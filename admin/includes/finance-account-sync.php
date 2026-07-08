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
     * completed non-refund payments minus completed/processing refunds.
     * Matches recalculateBookingFinancials() so every account type treats
     * refunds identically. Floored at 0 (over-refunds never show negative paid).
     */
    function rh_sum_account_paid(PDO $pdo, string $bookingType, int $bookingId): float
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(
                CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund'
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
               AND payment_status IN ('completed','paid')
               AND COALESCE(payment_type,'') <> 'refund'
               AND deleted_at IS NULL"
        );
        $stmt->execute([$bookingType, $bookingId]);
        return $stmt->fetchColumn() ?: null;
    }
}

if (!function_exists('syncGymInquiryPaymentSnapshot')) {
    /**
     * Recompute a gym inquiry's amount_paid / amount_due / deposit_paid from the
     * payments ledger and persist them. Returns the resulting snapshot or null.
     */
    function syncGymInquiryPaymentSnapshot(PDO $pdo, int $inquiryId): ?array
    {
        $stmt = $pdo->prepare("SELECT id, total_amount, total_with_vat, deposit_required FROM gym_inquiries WHERE id = ? LIMIT 1");
        $stmt->execute([$inquiryId]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inquiry) {
            return null;
        }

        $grossTotal = rh_account_gross_total($inquiry);
        $amountPaid = rh_sum_account_paid($pdo, 'gym', $inquiryId);
        $amountDue  = max(0.0, round($grossTotal - $amountPaid, 2));
        $depositRequired = (float)($inquiry['deposit_required'] ?? 0);
        $depositPaid = min($amountPaid, $depositRequired);
        $lastPaymentDate = rh_last_account_payment_date($pdo, 'gym', $inquiryId);

        $upd = $pdo->prepare(
            "UPDATE gym_inquiries
                SET amount_paid = ?, amount_due = ?, deposit_paid = ?, last_payment_date = ?, updated_at = NOW()
              WHERE id = ?"
        );
        $upd->execute([$amountPaid, $amountDue, $depositPaid, $lastPaymentDate, $inquiryId]);

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
        $stmt = $pdo->prepare("SELECT id, total_amount, total_with_vat, deposit_required FROM event_inquiries WHERE id = ? LIMIT 1");
        $stmt->execute([$inquiryId]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inquiry) {
            return null;
        }

        $grossTotal = rh_account_gross_total($inquiry);
        $amountPaid = rh_sum_account_paid($pdo, 'event', $inquiryId);
        $amountDue  = max(0.0, round($grossTotal - $amountPaid, 2));
        $depositRequired = (float)($inquiry['deposit_required'] ?? 0);
        $depositPaid = min($amountPaid, $depositRequired);
        $lastPaymentDate = rh_last_account_payment_date($pdo, 'event', $inquiryId);

        $upd = $pdo->prepare(
            "UPDATE event_inquiries
                SET amount_paid = ?, amount_due = ?, deposit_paid = ?, last_payment_date = ?, updated_at = NOW()
              WHERE id = ?"
        );
        $upd->execute([$amountPaid, $amountDue, $depositPaid, $lastPaymentDate, $inquiryId]);

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
        $stmt = $pdo->prepare("SELECT id, total_amount, total_with_vat, deposit_required FROM conference_inquiries WHERE id = ? LIMIT 1");
        $stmt->execute([$inquiryId]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inquiry) {
            return null;
        }

        $grossTotal = rh_account_gross_total($inquiry);
        $amountPaid = rh_sum_account_paid($pdo, 'conference', $inquiryId);
        $amountDue  = max(0.0, round($grossTotal - $amountPaid, 2));
        $depositRequired = (float)($inquiry['deposit_required'] ?? 0);
        $depositPaid = min($amountPaid, $depositRequired);
        $lastPaymentDate = rh_last_account_payment_date($pdo, 'conference', $inquiryId);

        // Derive the conference payment_status enum from the ledger.
        $tol = defined('BALANCE_TOLERANCE') ? BALANCE_TOLERANCE : 0.01;
        if ($grossTotal > $tol && $amountDue <= $tol) {
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
            $upd->execute([$amountPaid, $amountDue, $depositPaid, $lastPaymentDate, $paymentStatus, $inquiryId]);
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
                $depositPaid, $lastPaymentDate, $paymentStatus, $inquiryId
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
