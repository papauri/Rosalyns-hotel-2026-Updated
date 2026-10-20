<?php

/**
 * Shared receivable-account payment sync.
 *
 * Single source of truth for recomputing a gym/event inquiry's paid & due
 * figures from the immutable payments ledger. Used by the inquiry admin pages
 * AND by payment-add.php so a payment collected from either place produces the
 * exact same balances (no drift).
 *
 * ACCOUNTING MODEL (verified against live data, VAT exclusive @ 17.5%):
 *   - payments.total_amount        = GROSS received (net + VAT) per payment
 *   - account.total_with_vat       = invoiced GROSS grand total, LOCKED at
 *                                    invoice time (may reflect a historical VAT
 *                                    rate — must NOT be recomputed from the
 *                                    current rate, or old invoices corrupt)
 *   - account.amount_paid          = SUM(gross completed non-refund payments)
 *   - account.amount_due           = max(0, total_with_vat - amount_paid)
 *   Invariant: total_with_vat == amount_paid + amount_due.
 *
 * We deliberately derive amount_due from the STORED total_with_vat, not from
 * total_amount * current_vat_rate, so an account invoiced at an older rate
 * keeps its original balance.
 */

if (!function_exists('rh_account_gross_total')) {
    /**
     * Resolve the authoritative invoiced GROSS grand total for a receivable
     * account row. Prefers the locked total_with_vat; only falls back to
     * computing from the net total when total_with_vat was never populated.
     *
     * @param array $row Must contain 'total_amount'; may contain 'total_with_vat'.
     */
    function rh_account_gross_total(array $row): float
    {
        $stored = (float)($row['total_with_vat'] ?? 0);
        if ($stored > 0.001) {
            return round($stored, 2);
        }
        $net = (float)($row['total_amount'] ?? 0);
        if (function_exists('vat_components')) {
            return round((float)(vat_components($net)['total'] ?? $net), 2);
        }
        return round($net, 2);
    }
}

if (!function_exists('rh_sum_account_paid')) {
    /**
     * Net GROSS cash held against an account from the immutable ledger:
     * non-refund payments (status completed/paid/refunded/partially_refunded —
     * a refund flips the ORIGINAL's status but the refund row carries the
     * deduction) minus refund rows with refund_status completed/processing.
     * This is the canonical "net paid" rule.
     * Matches recalculateBookingFinancials() so every account type treats
     * refunds identically. Floored at 0 (over-refunds never show negative paid).
     */
    function rh_sum_account_paid(PDO $pdo, string $bookingType, int $bookingId): float
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(
                CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund'
                     THEN total_amount
                     WHEN payment_type = 'refund' AND refund_status IN ('completed','processing')
                     THEN -total_amount
                     ELSE 0 END), 0) AS paid
             FROM payments
             WHERE booking_type = ? AND booking_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$bookingType, $bookingId]);
        return max(0.0, (float)($stmt->fetchColumn() ?: 0));
    }
}

if (!function_exists('rh_last_account_payment_date')) {
    function rh_last_account_payment_date(PDO $pdo, string $bookingType, int $bookingId): ?string
    {
        $stmt = $pdo->prepare(
            "SELECT MAX(payment_date) FROM payments
             WHERE booking_type = ? AND booking_id = ?
               AND payment_status IN ('completed','paid','refunded','partially_refunded')
               AND COALESCE(payment_type,'') <> 'refund'
               AND deleted_at IS NULL"
        );
        $stmt->execute([$bookingType, $bookingId]);
        return $stmt->fetchColumn() ?: null;
    }
}

if (!function_exists('rh_account_derive_payment_status')) {
    /**
     * payment_status label for a gym / event account, derived from the LEDGER (same rule as the
     * conference sync) so part-payments and refunds show truthfully: fully covered = 'full_paid',
     * some money held = 'deposit_paid' (the enum has no 'partial'), nothing held = 'pending', and
     * money that came in and was entirely refunded = 'refunded'. A cancelled account keeps its stored
     * label (its bill is void) unless every payment has been refunded.
     */
    function rh_account_derive_payment_status(PDO $pdo, string $bookingType, int $bookingId, array $row, float $gross, float $paid, float $due): string
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $stored = (string)($row['payment_status'] ?? '');
        if ($paid <= $tol) {
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM payments
                  WHERE booking_type = ? AND booking_id = ? AND deleted_at IS NULL AND COALESCE(payment_type,'') <> 'refund'
                    AND payment_status IN ('completed','paid','refunded','partially_refunded')"
            );
            $st->execute([$bookingType, $bookingId]);
            if ((int)$st->fetchColumn() > 0) {
                return 'refunded';
            }
        }
        if ((string)($row['status'] ?? '') === 'cancelled' && $stored !== '') {
            return $stored;
        }
        if ($gross > $tol && $due <= $tol) {
            return 'full_paid';
        }
        if ($paid > $tol) {
            return 'deposit_paid';
        }
        return 'pending';
    }
}

if (!function_exists('syncGymInquiryPaymentSnapshot')) {
    /**
     * Recompute a gym inquiry's amount_paid / amount_due / deposit_paid from the
     * payments ledger and persist them. Returns the resulting snapshot or null.
     */
    function syncGymInquiryPaymentSnapshot(PDO $pdo, int $inquiryId): ?array
    {
        $stmt = $pdo->prepare("SELECT id, status, payment_status, total_amount, total_with_vat, deposit_required, deposit_amount FROM gym_inquiries WHERE id = ? LIMIT 1");
        $stmt->execute([$inquiryId]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inquiry) {
            return null;
        }

        $grossTotal = rh_account_gross_total($inquiry);
        $amountPaid = rh_sum_account_paid($pdo, 'gym', $inquiryId);
        $amountDue  = rh_account_amount_due($inquiry, $grossTotal, $amountPaid);
        // deposit_required / deposit_paid columns are 0/1 FLAGS; the money rule compares the net paid
        // (refunds netted) against deposit_amount. Snapshot values are money; the stored flag is derived.
        $depositRequired = (!empty($inquiry['deposit_required']) && (float)($inquiry['deposit_amount'] ?? 0) > 0) ? round((float)$inquiry['deposit_amount'], 2) : 0.0;
        $depositPaid = min($amountPaid, $depositRequired);
        $depositFlag = ($depositRequired > 0 && $depositPaid + (defined('BALANCE_TOLERANCE') ? BALANCE_TOLERANCE : 0.01) >= $depositRequired) ? 1 : 0;
        $lastPaymentDate = rh_last_account_payment_date($pdo, 'gym', $inquiryId);
        $paymentStatus = rh_account_derive_payment_status($pdo, 'gym', $inquiryId, $inquiry, $grossTotal, $amountPaid, $amountDue);

        $upd = $pdo->prepare(
            "UPDATE gym_inquiries
                SET amount_paid = ?, amount_due = ?, deposit_paid = ?, last_payment_date = ?, payment_status = ?, updated_at = NOW()
              WHERE id = ?"
        );
        $upd->execute([$amountPaid, $amountDue, $depositFlag, $lastPaymentDate, $paymentStatus, $inquiryId]);

        return [
            'total_amount'     => (float)($inquiry['total_amount'] ?? 0),
            'grand_total'      => $grossTotal,
            'amount_paid'      => $amountPaid,
            'amount_due'       => $amountDue,
            'deposit_required' => $depositRequired,
            'deposit_paid'     => $depositPaid,
        ];
    }
}

if (!function_exists('syncEventInquiryPaymentSnapshot')) {
    /**
     * Recompute an event inquiry's amount_paid / amount_due / deposit_paid from
     * the payments ledger and persist them. Returns the resulting snapshot or null.
     */
    function syncEventInquiryPaymentSnapshot(PDO $pdo, int $inquiryId): ?array
    {
        $stmt = $pdo->prepare("SELECT id, status, payment_status, total_amount, total_with_vat, deposit_required, deposit_amount FROM event_inquiries WHERE id = ? LIMIT 1");
        $stmt->execute([$inquiryId]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inquiry) {
            return null;
        }

        $grossTotal = rh_account_gross_total($inquiry);
        $amountPaid = rh_sum_account_paid($pdo, 'event', $inquiryId);
        $amountDue  = rh_account_amount_due($inquiry, $grossTotal, $amountPaid);
        // deposit_required / deposit_paid columns are 0/1 FLAGS; the money rule compares the net paid
        // (refunds netted) against deposit_amount. Snapshot values are money; the stored flag is derived.
        $depositRequired = (!empty($inquiry['deposit_required']) && (float)($inquiry['deposit_amount'] ?? 0) > 0) ? round((float)$inquiry['deposit_amount'], 2) : 0.0;
        $depositPaid = min($amountPaid, $depositRequired);
        $depositFlag = ($depositRequired > 0 && $depositPaid + (defined('BALANCE_TOLERANCE') ? BALANCE_TOLERANCE : 0.01) >= $depositRequired) ? 1 : 0;
        $lastPaymentDate = rh_last_account_payment_date($pdo, 'event', $inquiryId);
        $paymentStatus = rh_account_derive_payment_status($pdo, 'event', $inquiryId, $inquiry, $grossTotal, $amountPaid, $amountDue);

        $upd = $pdo->prepare(
            "UPDATE event_inquiries
                SET amount_paid = ?, amount_due = ?, deposit_paid = ?, last_payment_date = ?, payment_status = ?, updated_at = NOW()
              WHERE id = ?"
        );
        $upd->execute([$amountPaid, $amountDue, $depositFlag, $lastPaymentDate, $paymentStatus, $inquiryId]);

        return [
            'total_amount'     => (float)($inquiry['total_amount'] ?? 0),
            'grand_total'      => $grossTotal,
            'amount_paid'      => $amountPaid,
            'amount_due'       => $amountDue,
            'deposit_required' => $depositRequired,
            'deposit_paid'     => $depositPaid,
        ];
    }
}

if (!function_exists('syncConferenceInquiryPaymentSnapshot')) {
    /**
     * Recompute a conference inquiry's amount_paid / amount_due / deposit_paid
     * from the payments ledger and persist them — the single source of truth for
     * conference balances, replacing three divergent copies that computed
     * amount_due against the NET total (understating VAT-exclusive balances) and
     * re-based total_with_vat to the current rate.
     *
     * Uses the same gross/locked model as rooms/gym/events: amount_due is measured
     * against the invoiced GROSS grand total (locked total_with_vat, only computed
     * from net when never populated), and refunds net out of amount_paid.
     */
    function syncConferenceInquiryPaymentSnapshot(PDO $pdo, int $inquiryId): ?array
    {
        $stmt = $pdo->prepare("SELECT id, status, payment_status, total_amount, total_with_vat, deposit_required, deposit_amount FROM conference_inquiries WHERE id = ? LIMIT 1");
        $stmt->execute([$inquiryId]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inquiry) {
            return null;
        }

        $grossTotal = rh_account_gross_total($inquiry);
        $amountPaid = rh_sum_account_paid($pdo, 'conference', $inquiryId);
        $amountDue  = rh_account_amount_due($inquiry, $grossTotal, $amountPaid);
        // deposit_required / deposit_paid columns are 0/1 FLAGS; the money rule compares the net paid
        // (refunds netted) against deposit_amount. Snapshot values are money; the stored flag is derived.
        $depositRequired = (!empty($inquiry['deposit_required']) && (float)($inquiry['deposit_amount'] ?? 0) > 0) ? round((float)$inquiry['deposit_amount'], 2) : 0.0;
        $depositPaid = min($amountPaid, $depositRequired);
        $depositFlag = ($depositRequired > 0 && $depositPaid + (defined('BALANCE_TOLERANCE') ? BALANCE_TOLERANCE : 0.01) >= $depositRequired) ? 1 : 0;
        $lastPaymentDate = rh_last_account_payment_date($pdo, 'conference', $inquiryId);

        // Derive the conference payment_status enum from the ledger.
        $tol = defined('BALANCE_TOLERANCE') ? BALANCE_TOLERANCE : 0.01;
        if ((string)($inquiry['status'] ?? '') === 'cancelled' && (string)($inquiry['payment_status'] ?? '') !== '') {
            // Cancelled: the bill is void, so the paid/due ratio no longer describes it — keep the stored label.
            $paymentStatus = (string)$inquiry['payment_status'];
        } elseif ($grossTotal > $tol && $amountDue <= $tol) {
            $paymentStatus = 'full_paid';
        } elseif ($amountPaid > $tol) {
            $paymentStatus = 'deposit_paid';
        } else {
            $paymentStatus = 'pending';
        }

        // Only (re)populate the VAT breakdown when it was never locked, so a
        // conference invoiced at a historical rate keeps its original figures.
        $storedGross = (float)($inquiry['total_with_vat'] ?? 0);
        if ($storedGross > 0.001) {
            $upd = $pdo->prepare(
                "UPDATE conference_inquiries
                    SET amount_paid = ?, amount_due = ?, deposit_paid = ?, last_payment_date = ?,
                        payment_status = ?, updated_at = NOW()
                  WHERE id = ?"
            );
            $upd->execute([$amountPaid, $amountDue, $depositFlag, $lastPaymentDate, $paymentStatus, $inquiryId]);
        } else {
            $vatParts = function_exists('vat_components')
                ? vat_components((float)($inquiry['total_amount'] ?? 0))
                : ['rate' => 0, 'vat' => 0, 'total' => (float)($inquiry['total_amount'] ?? 0)];
            $upd = $pdo->prepare(
                "UPDATE conference_inquiries
                    SET amount_paid = ?, amount_due = ?, vat_rate = ?, vat_amount = ?, total_with_vat = ?,
                        deposit_paid = ?, last_payment_date = ?, payment_status = ?, updated_at = NOW()
                  WHERE id = ?"
            );
            $upd->execute([
                $amountPaid, $amountDue, $vatParts['rate'], $vatParts['vat'], $vatParts['total'],
                $depositFlag, $lastPaymentDate, $paymentStatus, $inquiryId
            ]);
        }

        return [
            'total_amount'     => (float)($inquiry['total_amount'] ?? 0),
            'grand_total'      => $grossTotal,
            'amount_paid'      => $amountPaid,
            'amount_due'       => $amountDue,
            'deposit_required' => $depositRequired,
            'deposit_paid'     => $depositPaid,
            'payment_status'   => $paymentStatus,
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Shared payment/refund helpers (single implementation for every payment page)
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('rh_account_amount_due')) {
    /**
     * Amount still owed on a gym/event/conference account. A cancelled account's bill is
     * void (due = 0); whatever was paid stays as credit owed back to the customer.
     *
     * @param array $row Must contain 'status' (may be absent = not cancelled).
     */
    function rh_account_amount_due(array $row, float $grossTotal, float $amountPaid): float
    {
        if ((string)($row['status'] ?? '') === 'cancelled') {
            return 0.0;
        }
        return max(0.0, round($grossTotal - $amountPaid, 2));
    }
}

if (!function_exists('rh_account_credit_owed')) {
    /**
     * Credit owed back on an account = net paid - billed. Room bookings carry it in
     * bookings.credit_balance; gym/event/conference derive it (no schema field) with a
     * cancelled account billing 0.
     */
    function rh_account_credit_owed(PDO $pdo, string $bookingType, int $bookingId): float
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        if ($bookingType === 'room') {
            $st = $pdo->prepare("SELECT credit_balance FROM bookings WHERE id = ?");
            $st->execute([$bookingId]);
            $c = (float)($st->fetchColumn() ?: 0);
            return $c > $tol ? round($c, 2) : 0.0;
        }
        $tables = ['gym' => 'gym_inquiries', 'event' => 'event_inquiries', 'conference' => 'conference_inquiries'];
        if (!isset($tables[$bookingType])) {
            return 0.0;
        }
        $st = $pdo->prepare("SELECT status, total_amount, total_with_vat FROM {$tables[$bookingType]} WHERE id = ? LIMIT 1");
        $st->execute([$bookingId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0.0;
        }
        $billed = ((string)($row['status'] ?? '') === 'cancelled') ? 0.0 : rh_account_gross_total($row);
        $credit = round(rh_sum_account_paid($pdo, $bookingType, $bookingId) - $billed, 2);
        return $credit > $tol ? $credit : 0.0;
    }
}

if (!function_exists('rh_account_balance_due')) {
    /**
     * The live balance still owed on a room / conference / gym / event account, re-derived from the
     * ledger (rooms: recalculateBookingFinancials first). Call it INSIDE the transaction, after the
     * account row is locked, so two staff can never both collect the same balance. Never negative.
     */
    function rh_account_balance_due(PDO $pdo, string $bookingType, int $bookingId): float
    {
        if ($bookingType === 'room') {
            if (function_exists('recalculateBookingFinancials')) {
                recalculateBookingFinancials($bookingId);
            }
            $st = $pdo->prepare("SELECT amount_due FROM bookings WHERE id = ?");
            $st->execute([$bookingId]);
            return max(0.0, round((float)($st->fetchColumn() ?: 0), 2));
        }
        $tables = ['gym' => 'gym_inquiries', 'event' => 'event_inquiries', 'conference' => 'conference_inquiries'];
        if (!isset($tables[$bookingType])) {
            return 0.0;
        }
        $st = $pdo->prepare("SELECT status, total_amount, total_with_vat FROM {$tables[$bookingType]} WHERE id = ? LIMIT 1");
        $st->execute([$bookingId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0.0;
        }
        return rh_account_amount_due($row, rh_account_gross_total($row), rh_sum_account_paid($pdo, $bookingType, $bookingId));
    }
}

if (!function_exists('rh_payment_overpay_message')) {
    function rh_payment_overpay_message(): string
    {
        return "That is more than the balance due. Tick 'The guest paid more than owed' to record it \u{2014} the extra becomes a credit note.";
    }
}

if (!function_exists('rh_payment_limit_error')) {
    /**
     * The single rule for taking money against an account (owner, 2026-10-20): nothing owed = no
     * payment; more than the balance only with the explicit "guest paid more than owed" tick (the
     * extra then becomes a credit note). Returns the staff-readable refusal, or null when allowed.
     * Compare with BALANCE_TOLERANCE, never raw equality.
     */
    function rh_payment_limit_error(float $amount, float $balanceDue, bool $confirmOverpay): ?string
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        if ($balanceDue <= $tol) {
            return 'Nothing is owed on this booking. Use a credit note or refund instead.';
        }
        if ($amount > $balanceDue + $tol && !$confirmOverpay) {
            return rh_payment_overpay_message();
        }
        return null;
    }
}

if (!function_exists('rh_doc_state')) {
    /**
     * Which document is the RIGHT one to offer for an account (owner rule): money still owed = 'invoice'
     * (request for payment); money came in and nothing is owed = 'receipt'; nothing paid and nothing
     * billed yet = 'quote'. Void statuses never offer an invoice.
     */
    function rh_doc_state(float $paid, float $due, string $status = ''): string
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        if (in_array($status, ['cancelled', 'no-show', 'expired', 'declined'], true)) {
            return $paid > $tol ? 'receipt' : 'none';
        }
        if ($due > $tol) {
            return 'invoice';
        }
        return $paid > $tol ? 'receipt' : 'quote';
    }
}

if (!function_exists('rh_account_fully_paid')) {
    /**
     * True when money came in and nothing is owed on a room / conference / gym / event account (read-only:
     * no recalculation, no writes). Used to refuse "send invoice" on a settled account.
     */
    function rh_account_fully_paid(PDO $pdo, string $bookingType, int $bookingId): bool
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        if ($bookingType === 'room') {
            $st = $pdo->prepare("SELECT status, amount_paid, amount_due FROM bookings WHERE id = ?");
            $st->execute([$bookingId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r && !in_array((string)$r['status'], ['cancelled', 'no-show', 'expired'], true)
                && (float)$r['amount_paid'] > $tol && (float)$r['amount_due'] <= $tol;
        }
        $tables = ['gym' => 'gym_inquiries', 'event' => 'event_inquiries', 'conference' => 'conference_inquiries'];
        if (!isset($tables[$bookingType])) {
            return false;
        }
        $st = $pdo->prepare("SELECT status, total_amount, total_with_vat FROM {$tables[$bookingType]} WHERE id = ? LIMIT 1");
        $st->execute([$bookingId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (string)$r['status'] === 'cancelled') {
            return false;
        }
        $paid = rh_sum_account_paid($pdo, $bookingType, $bookingId);
        return $paid > $tol && rh_account_amount_due($r, rh_account_gross_total($r), $paid) <= $tol;
    }
}

if (!function_exists('rh_invoice_paid_refusal')) {
    /** The refusal staff see when they ask for an invoice on a settled account, or null when an invoice is fine. */
    function rh_invoice_paid_refusal(PDO $pdo, string $bookingType, int $bookingId): ?string
    {
        return rh_account_fully_paid($pdo, $bookingType, $bookingId) ? 'This booking is fully paid. Send the receipt instead.' : null;
    }
}

if (!function_exists('rh_sync_account_payments')) {
    /**
     * Resync an account's paid/due snapshot from the ledger, whatever its type.
     * Restaurant orders carry no balance row, so there is nothing to resync.
     */
    function rh_sync_account_payments(PDO $pdo, string $bookingType, int $bookingId): bool
    {
        if ($bookingId <= 0) {
            return false;
        }
        switch ($bookingType) {
            case 'room':
                return function_exists('recalculateBookingFinancials') ? (bool)recalculateBookingFinancials($bookingId) : false;
            case 'conference':
                return syncConferenceInquiryPaymentSnapshot($pdo, $bookingId) !== null;
            case 'gym':
                return syncGymInquiryPaymentSnapshot($pdo, $bookingId) !== null;
            case 'event':
                return syncEventInquiryPaymentSnapshot($pdo, $bookingId) !== null;
        }
        return true;
    }
}

if (!function_exists('rh_account_vat_split')) {
    /**
     * Split a GROSS payment into net + VAT pro rata to the account's own VAT / gross
     * ratio (levy and zero-rated lines carry no VAT, so they dilute the ratio exactly as
     * they did on the invoice). Falls back to the global rate extraction when the account
     * has no VAT data.
     *
     * @return array{rate:float,vat:float,net:float}
     */
    function rh_account_vat_split(PDO $pdo, string $bookingType, int $bookingId, float $gross, float $fallbackRate = 0.0): array
    {
        $gross = round($gross, 2);
        $ratio = null;
        try {
            if ($bookingType === 'room' && function_exists('getBookingFolioSummary')) {
                $s = getBookingFolioSummary($bookingId);
                $g = (float)($s['grand_total'] ?? 0);
                $v = (float)($s['total_vat'] ?? 0);
                if ($g > 0.001 && $v > 0.001) {
                    $ratio = min(0.99, $v / $g);
                }
            } else {
                $tables = ['gym' => 'gym_inquiries', 'event' => 'event_inquiries', 'conference' => 'conference_inquiries'];
                if (isset($tables[$bookingType])) {
                    $st = $pdo->prepare("SELECT total_amount, total_with_vat, vat_amount FROM {$tables[$bookingType]} WHERE id = ? LIMIT 1");
                    $st->execute([$bookingId]);
                    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        $g = rh_account_gross_total($row);
                        $v = (float)($row['vat_amount'] ?? 0);
                        if ($g > 0.001 && $v > 0.001) {
                            $ratio = min(0.99, $v / $g);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $ratio = null;
        }
        if ($ratio === null) {
            $rate = max(0.0, $fallbackRate);
            $vat  = $rate > 0 ? round($gross * ($rate / (100 + $rate)), 2) : 0.0;
        } else {
            $vat  = round($gross * $ratio, 2);
            $rate = round($ratio / (1 - $ratio) * 100, 2);
        }
        return ['rate' => $rate, 'vat' => $vat, 'net' => round($gross - $vat, 2)];
    }
}

if (!function_exists('rh_legacy_payment_status')) {
    /**
     * Map a payments.payment_status onto the legacy payments.status enum
     * (pending/completed/failed/refunded). Writing 'paid', 'partial' or 'cancelled' into
     * that column is rejected under STRICT_TRANS_TABLES ("Data truncated").
     */
    function rh_legacy_payment_status(string $paymentStatus): string
    {
        switch ($paymentStatus) {
            case 'completed':
            case 'paid':
            case 'partially_refunded':
                return 'completed';
            case 'refunded':
                return 'refunded';
            case 'failed':
            case 'cancelled':
                return 'failed';
            default:
                return 'pending';
        }
    }
}

if (!function_exists('rh_payment_status_supported')) {
    /**
     * True when payments.payment_status can store $value (the column is an ENUM; migration
     * 030 adds partially_refunded/failed). Lets callers degrade safely before it is applied
     * instead of silently storing an empty status.
     */
    function rh_payment_status_supported(PDO $pdo, string $value): bool
    {
        static $types = null;
        if ($types === null) {
            try {
                $st = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_status'");
                $types = (string)$st->fetchColumn();
            } catch (Throwable $e) {
                $types = '';
            }
        }
        return $types === '' || stripos($types, 'enum(') !== 0 || strpos($types, "'" . $value . "'") !== false;
    }
}

if (!function_exists('rh_log_payment_audit')) {
    /**
     * Write payment_audit_log rows: one per changed field in $changes (field => [old, new]); money
     * fields compare within BALANCE_TOLERANCE. With no $changes, one row for the action itself
     * ($note as new_value; unused when there are changes). Call inside the caller's transaction; a logging failure never blocks
     * the money action (it goes to the error log).
     */
    function rh_log_payment_audit(PDO $pdo, int $paymentId, ?string $paymentRef, string $action, array $changes, ?int $userId, string $userName, string $note = ''): int
    {
        $rows = [];
        foreach ($changes as $field => [$old, $new]) {
            $o = $old === null ? '' : (string)$old;
            $n = $new === null ? '' : (string)$new;
            if (is_numeric($o) && is_numeric($n) && abs((float)$o - (float)$n) <= BALANCE_TOLERANCE) {
                continue;
            }
            if ($o === $n) {
                continue;
            }
            $rows[] = [(string)$field, $old === null ? null : $o, $new === null ? null : $n];
        }
        if (!$changes) {
            $rows[] = [null, null, $note !== '' ? $note : null];
        }
        if (!$rows) {
            return 0;
        }
        try {
            $ins = $pdo->prepare("INSERT INTO payment_audit_log
                    (payment_id, payment_reference, action, field_name, old_value, new_value, performed_by, performed_by_name, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($rows as [$field, $old, $new]) {
                $ins->execute([
                    $paymentId, $paymentRef, mb_substr($action, 0, 50), $field, $old, $new,
                    $userId && $userId > 0 ? $userId : null,
                    mb_substr($userName, 0, 100) ?: null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
                ]);
            }
        } catch (Throwable $e) {
            error_log('rh_log_payment_audit failed for payment ' . $paymentId . ': ' . $e->getMessage());
            return 0;
        }
        return count($rows);
    }
}

if (!function_exists('rh_refresh_original_payment_status')) {
    /**
     * Recompute an original payment's status from its SETTLED refunds (completed or
     * processing): fully covered -> 'refunded'; partly -> 'partially_refunded'; none ->
     * back to 'completed'. Locks the original row; call inside the caller's transaction.
     * Restaurant originals only ever flip to 'refunded' (the POS reversal helper matches
     * originals on status 'completed', so a part-refunded one must stay 'completed').
     *
     * @return string|null the new payment_status, or null when the row is not a collectable original
     */
    function rh_refresh_original_payment_status(PDO $pdo, int $originalPaymentId): ?string
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $st = $pdo->prepare("SELECT id, booking_type, total_amount, payment_status FROM payments WHERE id = ? AND deleted_at IS NULL AND COALESCE(payment_type,'') <> 'refund' FOR UPDATE");
        $st->execute([$originalPaymentId]);
        $orig = $st->fetch(PDO::FETCH_ASSOC);
        if (!$orig || !in_array((string)$orig['payment_status'], ['completed', 'paid', 'refunded', 'partially_refunded'], true)) {
            return null;
        }
        $sum = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(refund_amount, total_amount)), 0) FROM payments
                               WHERE original_payment_id = ? AND payment_type = 'refund' AND deleted_at IS NULL
                                 AND refund_status IN ('completed','processing')");
        $sum->execute([$originalPaymentId]);
        $settled = round((float)$sum->fetchColumn(), 2);
        $total   = round((float)$orig['total_amount'], 2);
        $current = (string)$orig['payment_status'];

        if ($settled >= $total - $tol && $total > 0) {
            $new = 'refunded';
        } elseif ($settled > $tol && (string)$orig['booking_type'] !== 'restaurant') {
            $new = 'partially_refunded';
        } else {
            $new = in_array($current, ['completed', 'paid'], true) ? $current : 'completed';
        }
        if ($new === 'partially_refunded' && !rh_payment_status_supported($pdo, 'partially_refunded')) {
            $new = $current; // column not yet widened (migration 030): refund rows still carry the deduction
        }
        if ($new !== $current) {
            $pdo->prepare("UPDATE payments SET payment_status = ?, status = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$new, $new === 'refunded' ? 'refunded' : 'completed', $originalPaymentId]);
        }
        return $new;
    }
}

if (!function_exists('rh_sync_restaurant_order_refund_state')) {
    /**
     * Keep stock_orders.status in step with refunds: when the active refunds (pending,
     * processing, completed) cover every original payment of the order it becomes
     * 'refunded'; if a failed/removed refund uncovers it again, it returns to 'paid'.
     * Caller holds the transaction. Voided/cancelled orders are left alone.
     */
    function rh_sync_restaurant_order_refund_state(PDO $pdo, int $orderId, string $reason = ''): ?string
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $os = $pdo->prepare("SELECT status FROM stock_orders WHERE id = ? FOR UPDATE");
        $os->execute([$orderId]);
        $status = $os->fetchColumn();
        if ($status === false || !in_array((string)$status, ['paid', 'completed', 'refunded'], true)) {
            return $status === false ? null : (string)$status;
        }
        $orig = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM payments
                                WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type,'') <> 'refund'
                                  AND payment_status IN ('completed','paid','refunded','partially_refunded') AND deleted_at IS NULL");
        $orig->execute([$orderId]);
        $paid = (float)$orig->fetchColumn();
        $ref = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(refund_amount, total_amount)),0) FROM payments
                               WHERE booking_type = 'restaurant' AND booking_id = ? AND payment_type = 'refund' AND deleted_at IS NULL
                                 AND refund_status IN ('pending','processing','completed')");
        $ref->execute([$orderId]);
        $refunded = (float)$ref->fetchColumn();

        if ($paid > $tol && $refunded >= $paid - $tol) {
            if ((string)$status !== 'refunded') {
                $pdo->prepare("UPDATE stock_orders SET status = 'refunded', refunded_at = NOW(), refund_reason = ? WHERE id = ?")
                    ->execute([mb_substr($reason !== '' ? $reason : 'Refunded via payments', 0, 250), $orderId]);
            }
            return 'refunded';
        }
        if ((string)$status === 'refunded') {
            $pdo->prepare("UPDATE stock_orders SET status = 'paid', refunded_at = NULL WHERE id = ?")->execute([$orderId]);
            return 'paid';
        }
        return (string)$status;
    }
}

if (!function_exists('rh_apply_refund_side_effects')) {
    /**
     * After any refund row changes: refresh the original's status, resync the account and
     * (restaurant) the order state. Caller owns the transaction. Returns the original's
     * new payment_status (or null).
     */
    function rh_apply_refund_side_effects(PDO $pdo, string $bookingType, int $bookingId, ?int $originalPaymentId, string $reason = ''): ?string
    {
        $newStatus = null;
        if ($originalPaymentId) {
            $newStatus = rh_refresh_original_payment_status($pdo, $originalPaymentId);
        }
        if ($bookingType === 'restaurant') {
            rh_sync_restaurant_order_refund_state($pdo, $bookingId, $reason);
        } elseif (!rh_sync_account_payments($pdo, $bookingType, $bookingId)) {
            throw new RuntimeException('Could not resync the account balance after the refund change.');
        }
        return $newStatus;
    }
}

if (!function_exists('rh_settle_refund_row')) {
    /**
     * Move ONE refund row through its states: pending -> processing/completed/failed,
     * processing -> completed/failed. The single implementation behind the payment page's
     * "Settle refund" and the POS "Settle refund" (86 refunds), so both write identical rows.
     *
     * Lock order (matches payment-refund.php): stock_orders (restaurant) -> original payment -> refund row.
     * Store-credit ('credit_note') refunds are refused - they settle when the credit note is issued -
     * and a mobile-money refund never jumps pending -> completed: it must pass through 'processing'
     * (provider confirmation) first. On 'completed' the row is stamped refund_date_processed = NOW() and
     * recorded_by = the SETTLING user, so the payout lands on the shift of whoever handed the money
     * back (the person who raised it stays in refund_notes / the audit trail). The original payment's
     * status and the account / restaurant order state are resynced in the same transaction.
     *
     * Runs in the caller's transaction when there is one, otherwise in its own.
     *
     * @return array{id:int,reference:string,from:string,to:string,amount:float,booking_type:string,booking_id:int}
     * @throws RuntimeException with a user-facing message
     */
    function rh_settle_refund_row(PDO $pdo, int $refundId, string $targetStatus, int $settlerUserId, string $note = ''): array
    {
        if (!in_array($targetStatus, ['processing', 'completed', 'failed'], true)) {
            throw new RuntimeException('Choose a valid refund status.');
        }
        $ownTx = !$pdo->inTransaction();
        try {
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            $pre = $pdo->prepare("SELECT booking_type, booking_id, original_payment_id FROM payments WHERE id = ? AND payment_type = 'refund' AND deleted_at IS NULL");
            $pre->execute([$refundId]);
            $preRow = $pre->fetch(PDO::FETCH_ASSOC);
            if (!$preRow) {
                throw new RuntimeException('Refund record not found.');
            }
            if ((string)$preRow['booking_type'] === 'restaurant') {
                $pdo->prepare("SELECT id FROM stock_orders WHERE id = ? FOR UPDATE")->execute([(int)$preRow['booking_id']]);
            }
            if (!empty($preRow['original_payment_id'])) {
                $pdo->prepare("SELECT id FROM payments WHERE id = ? FOR UPDATE")->execute([(int)$preRow['original_payment_id']]);
            }
            $lock = $pdo->prepare("SELECT id, booking_type, booking_id, original_payment_id, payment_reference, payment_method, refund_status, COALESCE(refund_amount, total_amount) AS amt FROM payments WHERE id = ? AND payment_type = 'refund' AND deleted_at IS NULL FOR UPDATE");
            $lock->execute([$refundId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Refund record not found.');
            }
            if ((string)$row['payment_method'] === 'credit_note') {
                throw new RuntimeException('Store-credit refunds are settled when the credit note is issued. Void the credit note to reverse one.');
            }
            $from = (string)$row['refund_status'];
            $allowed = [
                'pending'    => ['processing', 'completed', 'failed'],
                'processing' => ['completed', 'failed'],
            ];
            if (!isset($allowed[$from]) || !in_array($targetStatus, $allowed[$from], true)) {
                throw new RuntimeException('A ' . $from . ' refund cannot be moved to ' . $targetStatus . '.');
            }
            if ((string)$row['payment_method'] === 'mobile_money' && $from === 'pending' && $targetStatus === 'completed') {
                throw new RuntimeException('A mobile money refund cannot go straight to completed - mark it processing first and complete it once the provider confirms.');
            }
            $actor = '';
            if ($settlerUserId > 0) {
                $an = $pdo->prepare("SELECT COALESCE(NULLIF(username, ''), CAST(id AS CHAR)) FROM admin_users WHERE id = ?");
                $an->execute([$settlerUserId]);
                $actor = (string)($an->fetchColumn() ?: $settlerUserId);
            }
            $line = ' [' . date('Y-m-d H:i') . ' ' . ($actor !== '' ? $actor : 'staff') . ': ' . $from . ' -> ' . $targetStatus . ($note !== '' ? ' - ' . mb_substr($note, 0, 250) : '') . ']';
            $newPaymentStatus = $targetStatus === 'completed' ? 'completed' : ($targetStatus === 'failed' ? 'cancelled' : 'pending');
            $newRowStatus = $targetStatus === 'completed' ? 'completed' : ($targetStatus === 'failed' ? 'failed' : 'pending');
            $pdo->prepare("
                UPDATE payments
                   SET refund_status = ?, payment_status = ?, status = ?,
                       refund_date_processed = CASE WHEN ? IN ('completed','failed') THEN NOW() ELSE refund_date_processed END,
                       recorded_by = CASE WHEN ? = 'completed' AND ? > 0 THEN ? ELSE recorded_by END,
                       refund_notes = CONCAT(COALESCE(refund_notes, ''), ?), updated_at = NOW()
                 WHERE id = ?
            ")->execute([$targetStatus, $newPaymentStatus, $newRowStatus, $targetStatus, $targetStatus, $settlerUserId, $settlerUserId, $line, $refundId]);
            rh_log_payment_audit($pdo, $refundId, (string)$row['payment_reference'], 'refund_' . $targetStatus,
                ['refund_status' => [$from, $targetStatus]], $settlerUserId, $actor !== '' ? $actor : 'staff');

            rh_apply_refund_side_effects(
                $pdo,
                (string)$row['booking_type'],
                (int)$row['booking_id'],
                !empty($row['original_payment_id']) ? (int)$row['original_payment_id'] : null,
                (strncmp((string)$row['payment_reference'], 'REF86-', 6) === 0 ? '86 refund settled' : 'Refund ' . $row['payment_reference'] . ' ' . $targetStatus)
            );
            if ($ownTx) {
                $pdo->commit();
            }
            return [
                'id' => (int)$row['id'],
                'reference' => (string)$row['payment_reference'],
                'from' => $from,
                'to' => $targetStatus,
                'amount' => (float)$row['amt'],
                'booking_type' => (string)$row['booking_type'],
                'booking_id' => (int)$row['booking_id'],
            ];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }
}

if (!function_exists('rh_cancel_auto_refund_account')) {
    /**
     * Refund-on-cancel for conference/event/gym accounts when the "nonroom_cancel_mode"
     * setting is 'refund_all'. Call INSIDE the cancel transaction AFTER the status is set
     * to 'cancelled' and the account resynced. Refunds the credit owed (net paid) back to
     * each original payment's own method, never more than still refundable per original,
     * as completed refund rows, then resyncs the account.
     *
     * @return array{refunded:float,refs:array}
     */
    function rh_cancel_auto_refund_account(PDO $pdo, string $bookingType, int $bookingId, ?int $adminUserId = null): array
    {
        $out = ['refunded' => 0.0, 'refs' => []];
        if (!function_exists('rh_refund_rule') || rh_refund_rule('nonroom_cancel_mode') !== 'refund_all') {
            return $out;
        }
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $credit = rh_account_credit_owed($pdo, $bookingType, $bookingId);
        if ($credit <= $tol) {
            return $out;
        }
        require_once __DIR__ . '/../../includes/finance-sequences.php';
        $allocs = rh_allocate_account_refunds($pdo, $bookingType, $bookingId, $credit, true);
        $ins = $pdo->prepare("
            INSERT INTO payments (
                payment_reference, booking_type, booking_id, booking_reference,
                payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, original_payment_id,
                refund_reason, refund_status, refund_amount, refund_notes,
                recorded_by, created_at
            ) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, 'refund', 'completed', ?,
                      'cancellation', 'completed', ?, ?, ?, NOW())
        ");
        foreach ($allocs as $alloc) {
            $orig = $alloc['payment'];
            $leg = (float)$alloc['amount'];
            $vr = (float)($orig['vat_rate'] ?? 0);
            $va = $vr > 0 ? round($leg * ($vr / (100 + $vr)), 2) : 0.0;
            $legRef = finance_next_refund_reference($pdo, date('Y-m-d'));
            $ins->execute([
                $legRef, $bookingType, $bookingId, (string)($orig['booking_reference'] ?? ''),
                round($leg - $va, 2), $vr, $va, $leg,
                $orig['payment_method'] ?: 'other',
                (int)$orig['id'],
                $leg, 'Cancellation (auto-refund per settings)', $adminUserId ?: null,
            ]);
            rh_refresh_original_payment_status($pdo, (int)$orig['id']);
            $out['refunded'] = round($out['refunded'] + $leg, 2);
            $out['refs'][] = $legRef;
        }
        if ($out['refunded'] > 0 && !rh_sync_account_payments($pdo, $bookingType, $bookingId)) {
            throw new RuntimeException('Could not resync the account balance after the cancellation refund.');
        }
        return $out;
    }
}

if (!function_exists('rh_allocate_account_refunds')) {
    /**
     * Allocate $amount across an account's original payments (newest first) up to what is
     * still refundable on each. Non-room twin of allocateBookingRefunds().
     *
     * @return array<int,array{payment:array,amount:float}>
     */
    function rh_allocate_account_refunds(PDO $pdo, string $bookingType, int $bookingId, float $amount, bool $lock = false): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        if ($amount <= $tol) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT p.id, p.payment_method, p.booking_type, p.booking_reference, p.vat_rate, p.total_amount,
                   COALESCE((
                       SELECT SUM(COALESCE(r.refund_amount, r.total_amount))
                       FROM payments r
                       WHERE r.original_payment_id = p.id AND r.payment_type = 'refund'
                         AND r.refund_status IN ('completed','processing','pending') AND r.deleted_at IS NULL
                   ), 0) AS already_refunded
            FROM payments p
            WHERE p.booking_type = ? AND p.booking_id = ?
              AND COALESCE(p.payment_type, '') <> 'refund'
              AND p.payment_status IN ('completed','paid','refunded','partially_refunded')
              AND p.deleted_at IS NULL
            ORDER BY p.payment_date DESC, p.id DESC
            " . ($lock ? "FOR UPDATE" : ""));
        $stmt->execute([$bookingType, $bookingId]);
        $out = [];
        $left = round($amount, 2);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if ($left <= $tol) {
                break;
            }
            $remaining = round((float)$p['total_amount'] - (float)$p['already_refunded'], 2);
            if ($remaining <= $tol) {
                continue;
            }
            $take = round(min($left, $remaining), 2);
            $out[] = ['payment' => $p, 'amount' => $take];
            $left = round($left - $take, 2);
        }
        return $out;
    }
}
