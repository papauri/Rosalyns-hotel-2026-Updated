<?php

/**
 * Edit a payment already taken on a room booking (owner 2026-11-12: "Be able to edit a payment as admin and super
 * admin. Add a special permission ... make changes without breaking anything and logically adjusting accordingly").
 *
 * Rules:
 *   - permission 'payment_edit' (on for administrators, grantable to anyone), checked here as well as by the page;
 *   - room payments that were collected (completed / paid / partially_refunded / refunded), never a refund row,
 *     never a till (restaurant) payment (paid till orders are fixed), never on a cancelled / no-show / expired booking;
 *   - amount > 0; never below what was already refunded or moved to a credit note from this payment; never above
 *     what the booking owed before this payment (rh_payment_limit_error, no overpay through an edit);
 *   - one transaction with the booking row locked: the payment row (a new amount is split for VAT exactly like a
 *     new payment, rh_account_vat_split), payment_type full / partial derived from the result, the original's
 *     refund status, recalculateBookingFinancials() for that booking only, and the payment audit rows (old -> new
 *     for every changed field plus the reason, shown in the booking's and the payment's History);
 *   - after the commit (never undoing the saved edit): the receipt keeps its number and is regenerated marked
 *     "Corrected receipt"; it is emailed only when staff tick the box (unticked = an email_send_log 'not_sent' row);
 *     the payment's own invoice and the booking's final invoice / statement are regenerated with the same numbers.
 */

require_once __DIR__ . '/finance-account-sync.php';
require_once __DIR__ . '/finance-schema.php';
// The document builders (config/invoice.php: TCPDF, config/receipts.php) load only when a document is made
// (rh_payment_edit_docs_load), so viewing Payment details never loads TCPDF. invoice.php declares its TCPDF flag `global`,
// so loading it from inside a function works.
if (!function_exists('rh_payment_edit_docs_load')) {
    function rh_payment_edit_docs_load(): void
    {
        require_once __DIR__ . '/../../config/invoice.php';
        require_once __DIR__ . '/../../config/receipts.php';
    }
}

if (!function_exists('rh_payment_edit_methods')) {
    /** Methods staff may pick (the payments.payment_method enum without the credit-note leg). */
    function rh_payment_edit_methods(): array
    {
        return [
            'cash'          => 'Cash',
            'mobile_money'  => 'Mobile Money',
            'bank_transfer' => 'Bank Transfer',
            'credit_card'   => 'Credit Card',
            'debit_card'    => 'Debit Card',
            'cheque'        => 'Cheque',
            'other'         => 'Other',
        ];
    }
}

if (!function_exists('rh_payment_edit_refusal')) {
    /** Why this payments row cannot be edited, or null when it can. Pure (no database). */
    function rh_payment_edit_refusal(array $p): ?string
    {
        if (!empty($p['deleted_at'])) {
            return 'This payment was removed.';
        }
        if ((string)($p['payment_type'] ?? '') === 'refund') {
            return 'A refund is moved on with "Settle refund", not edited.';
        }
        if ((string)($p['booking_type'] ?? '') === 'restaurant') {
            return 'Till (restaurant) payments are fixed once the order is paid. Use a refund on the till instead.';
        }
        if ((string)($p['booking_type'] ?? '') !== 'room') {
            return 'Only room booking payments can be edited for now. Correct this one with a refund or a credit note.';
        }
        if (!empty($p['_company_leg'])) {
            // Part of a company payment split across bookings (migration 084): editing one line would break the split.
            return 'This is part of a company payment split across bookings. Refund it and record the company payment again on Company accounts.';
        }
        if ((string)($p['payment_method'] ?? '') === 'credit_note' || !empty($p['credit_note_id'])) {
            return 'This payment came from a credit note. Change or void the credit note on Credit Notes instead.';
        }
        if (!in_array((string)($p['payment_status'] ?? ''), ['completed', 'paid', 'partially_refunded', 'refunded'], true)) {
            return 'Only a payment that was received can be edited here. A pending payment is changed with Edit on the payment form.';
        }
        return null;
    }
}

if (!function_exists('rh_payment_edit_refunded_from')) {
    /** Money already sent back or moved to a credit note FROM this payment (pending, processing and completed refund legs). */
    function rh_payment_edit_refunded_from(PDO $pdo, int $paymentId): float
    {
        $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(refund_amount, total_amount)), 0) FROM payments
            WHERE original_payment_id = ? AND payment_type = 'refund' AND deleted_at IS NULL
              AND refund_status IN ('pending', 'processing', 'completed')");
        $st->execute([$paymentId]);
        return round((float)$st->fetchColumn(), 2);
    }
}

if (!function_exists('rh_payment_edit_owed_without')) {
    /**
     * What the booking owed before this payment was taken: bill - (net paid - this payment), from the live ledger
     * (recalculateBookingFinancials first). Negative = the booking was already overpaid without it.
     */
    function rh_payment_edit_owed_without(PDO $pdo, int $bookingId, float $paymentTotal): float
    {
        if (!recalculateBookingFinancials($bookingId)) {
            throw new Exception('Could not work out the booking balance. Nothing was changed.');
        }
        $st = $pdo->prepare("SELECT amount_due, credit_balance FROM bookings WHERE id = ?");
        $st->execute([$bookingId]);
        $b = $st->fetch(PDO::FETCH_ASSOC) ?: ['amount_due' => 0, 'credit_balance' => 0];
        return round((float)$b['amount_due'] - (float)$b['credit_balance'] + $paymentTotal, 2);
    }
}

if (!function_exists('rh_payment_edit_type')) {
    /**
     * payment_type after the edit: full when it covers what the booking owed before it, part otherwise (same rule as
     * a new payment). A deposit / adjustment keeps its type. Pure.
     */
    function rh_payment_edit_type(?string $current, float $amount, float $owedWithout): string
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $current = (string)$current;
        if (in_array($current, ['deposit', 'adjustment'], true)) {
            return $current;
        }
        return $amount + $tol >= $owedWithout ? 'full_payment' : 'partial_payment';
    }
}

if (!function_exists('rh_payment_edit_apply')) {
    /**
     * Save the edit. $in: amount (gross received), payment_method, payment_date (Y-m-d), transaction_reference, notes,
     * reason (required). $user: id, full_name / username. Throws Exception with a staff-readable message (nothing saved).
     *
     * @return array{payment_id:int,payment_reference:string,booking_id:int,booking_reference:string,old:array,new:array,
     *               changes:array,reason:string,owed_without:float,due_before:float,due_after:float,
     *               booking_status_before:string,booking_status_after:string,receipt_number:string}
     */
    function rh_payment_edit_apply(PDO $pdo, int $paymentId, array $in, array $user): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $uid = (int)($user['id'] ?? 0);
        $who = (string)($user['full_name'] ?? '') !== '' ? (string)$user['full_name'] : (string)($user['username'] ?? '');
        $cur = (string)getSetting('currency_symbol', 'MWK');
        $money = static function (float $v) use ($cur): string {
            return $cur . ' ' . number_format($v, 2);
        };

        if ($uid <= 0 || !function_exists('hasPermission') || !hasPermission($uid, 'payment_edit')) {
            throw new Exception('You do not have permission to edit payments (Edit payments).');
        }

        $reason = trim(preg_replace('/\s+/u', ' ', (string)($in['reason'] ?? '')));
        if (mb_strlen($reason) < 3) {
            throw new Exception('Say why the payment is being changed (the reason is required).');
        }
        $reason = mb_substr($reason, 0, 500);

        $amountRaw = trim(str_replace([',', ' '], '', (string)($in['amount'] ?? '')));
        if ($amountRaw === '' || !is_numeric($amountRaw)) {
            throw new Exception('Enter the amount the guest actually paid.');
        }
        $amount = round((float)$amountRaw, 2);
        if ($amount <= $tol) {
            throw new Exception('The amount must be more than zero. A payment cannot be edited away; if no money was received at all, ask the owner how to correct it.');
        }
        if ($amount > 99999999) {
            throw new Exception('That amount is too large.');
        }

        $dateRaw = trim((string)($in['payment_date'] ?? ''));
        $dateObj = DateTime::createFromFormat('!Y-m-d', $dateRaw);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $dateRaw || $dateRaw > date('Y-m-d')) {
            throw new Exception('Enter a valid payment date (not in the future).');
        }
        $txn = mb_substr(trim((string)($in['transaction_reference'] ?? '')), 0, 100);
        $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 1000);
        $method = (string)($in['payment_method'] ?? '');
        $txnCol = finance_payment_transaction_column($pdo);

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $find = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL");
            $find->execute([$paymentId]);
            $first = $find->fetch(PDO::FETCH_ASSOC);
            if (!$first) {
                throw new Exception('Payment not found.');
            }
            if (!function_exists('rh_billing_payment_is_company_leg')) {
                require_once __DIR__ . '/../../includes/billing-accounts.php';
            }
            $first['_company_leg'] = rh_billing_payment_is_company_leg($pdo, (int)$first['id']);
            if (($refusal = rh_payment_edit_refusal($first)) !== null) {
                throw new Exception($refusal);
            }
            $bookingId = (int)$first['booking_id'];
            // Lock order: the booking, then the payment (the same order as recording a payment), so two edits or an
            // edit and a new payment on the same booking queue up instead of both reading the old balance.
            $bk = $pdo->prepare("SELECT id, booking_reference, status, payment_status FROM bookings WHERE id = ? FOR UPDATE");
            $bk->execute([$bookingId]);
            $booking = $bk->fetch(PDO::FETCH_ASSOC);
            if (!$booking) {
                throw new Exception('The booking for this payment was not found.');
            }
            if (in_array((string)$booking['status'], ['cancelled', 'no-show', 'expired'], true)) {
                throw new Exception('This booking is ' . $booking['status'] . '. Its money was settled when it closed; correct it with a refund or a credit note instead.');
            }
            $ps = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $ps->execute([$paymentId]);
            $old = $ps->fetch(PDO::FETCH_ASSOC);
            if (!$old || (int)$old['booking_id'] !== $bookingId) {
                throw new Exception('This payment changed while you were editing. Reload the page and try again.');
            }
            if (($refusal = rh_payment_edit_refusal($old)) !== null) {
                throw new Exception($refusal);
            }

            $oldMethod = (string)($old['payment_method'] ?? '');
            if (!isset(rh_payment_edit_methods()[$method]) && $method !== $oldMethod) {
                throw new Exception('Choose how the guest paid.');
            }

            $refundedFrom = rh_payment_edit_refunded_from($pdo, $paymentId);
            if ($amount + $tol < $refundedFrom) {
                throw new Exception($money($refundedFrom) . ' of this payment was already refunded or moved to a credit note, so the amount cannot go below that.');
            }

            $oldTotal = round((float)$old['total_amount'], 2);
            $dueBefore = null;
            $statusBefore = (string)($booking['payment_status'] ?? '');
            $owedWithout = rh_payment_edit_owed_without($pdo, $bookingId, $oldTotal);
            $dueSt = $pdo->prepare("SELECT amount_due, payment_status FROM bookings WHERE id = ?");
            $dueSt->execute([$bookingId]);
            $dueRow = $dueSt->fetch(PDO::FETCH_ASSOC) ?: [];
            $dueBefore = round((float)($dueRow['amount_due'] ?? 0), 2);
            $statusBefore = (string)($dueRow['payment_status'] ?? $statusBefore);

            // Raising the amount is taking more money for the booking: the same limit as recording a payment, without
            // the overpay tick (an edit never creates a credit note; the extra is recorded as its own payment).
            if ($amount > $oldTotal + $tol) {
                if (rh_payment_limit_error($amount, $owedWithout, false) !== null) {
                    throw new Exception($owedWithout <= $tol
                        ? 'Nothing else was owed on this booking, so this payment cannot be raised. If the guest really paid more, record the extra as a new payment.'
                        : 'That is more than the booking owed before this payment (' . $money($owedWithout) . '). Enter at most that amount; if the guest really paid more, record the extra as a new payment.');
                }
            }

            // VAT: the same amount keeps its stored split; a new amount is split like a new payment.
            if (abs($amount - $oldTotal) <= $tol) {
                $amount = $oldTotal;
                $net = round((float)$old['payment_amount'], 2);
                $vat = round((float)$old['vat_amount'], 2);
                $rate = (float)$old['vat_rate'];
            } else {
                $fallbackRate = rh_vat_enabled() ? (float)getSetting('vat_rate') : 0.0;
                $split = rh_account_vat_split($pdo, 'room', $bookingId, $amount, $fallbackRate);
                $net = (float)$split['net'];
                $vat = (float)$split['vat'];
                $rate = (float)$split['rate'];
            }
            $type = rh_payment_edit_type($old['payment_type'] ?? null, $amount, $owedWithout);
            $oldDate = substr((string)$old['payment_date'], 0, 10);
            $oldTxn = (string)($old[$txnCol] ?? '');
            $oldNotes = (string)($old['notes'] ?? '');

            $changes = [
                'amount'                => [$oldTotal, $amount],
                'vat_amount'            => [round((float)$old['vat_amount'], 2), $vat],
                'payment_type'          => [$old['payment_type'] ?? null, $type],
                'payment_method'        => [$oldMethod, $method],
                'payment_date'          => [$oldDate, $dateRaw],
                'transaction_reference' => [$oldTxn !== '' ? $oldTxn : null, $txn !== '' ? $txn : null],
                'notes'                 => [$oldNotes !== '' ? $oldNotes : null, $notes !== '' ? $notes : null],
            ];
            $changed = [];
            foreach ($changes as $f => [$o, $n]) {
                $os = $o === null ? '' : (string)$o;
                $ns = $n === null ? '' : (string)$n;
                if (is_numeric($os) && is_numeric($ns) ? abs((float)$os - (float)$ns) > $tol : $os !== $ns) {
                    $changed[$f] = [$o, $n];
                }
            }
            if (!$changed) {
                throw new Exception('Nothing was changed.');
            }

            // processed_by / recorded_by stay: they say who took the money, not who corrected the record.
            $pdo->prepare("UPDATE payments SET payment_amount = ?, vat_rate = ?, vat_amount = ?, total_amount = ?, payment_method = ?,
                    payment_date = ?, {$txnCol} = ?, notes = ?, payment_type = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$net, $rate, $vat, $amount, $method, $dateRaw, $txn !== '' ? $txn : null, $notes !== '' ? $notes : null, $type, $paymentId]);

            // An original with refunds: its refunded / partly refunded status follows the new amount.
            if ($refundedFrom > $tol) {
                rh_refresh_original_payment_status($pdo, $paymentId);
            }
            if (!recalculateBookingFinancials($bookingId)) {
                throw new Exception('Could not recalculate the booking balance. Nothing was changed.');
            }
            $dueSt->execute([$bookingId]);
            $after = $dueSt->fetch(PDO::FETCH_ASSOC) ?: [];

            rh_log_payment_audit($pdo, $paymentId, (string)$old['payment_reference'], 'edited', $changed, $uid, $who, 'Reason: ' . $reason);

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw ($e instanceof Exception) ? $e : new Exception($e->getMessage(), 0, $e);
        }

        return [
            'payment_id' => $paymentId,
            'payment_reference' => (string)$old['payment_reference'],
            'booking_id' => $bookingId,
            'booking_reference' => (string)$booking['booking_reference'],
            'old' => $old,
            'new' => ['total_amount' => $amount, 'payment_amount' => $net, 'vat_amount' => $vat, 'vat_rate' => $rate,
                      'payment_type' => $type, 'payment_method' => $method, 'payment_date' => $dateRaw],
            'changes' => $changed,
            'reason' => $reason,
            'owed_without' => $owedWithout,
            'due_before' => (float)$dueBefore,
            'due_after' => round((float)($after['amount_due'] ?? 0), 2),
            'booking_status_before' => $statusBefore,
            'booking_status_after' => (string)($after['payment_status'] ?? ''),
            'receipt_number' => trim((string)($old['receipt_number'] ?? '')),
        ];
    }
}

if (!function_exists('rh_payment_edit_change_text')) {
    /** "amount MWK 1,599,465.60 -> MWK 800,000.00, type Full payment -> Partial payment, method Cash -> Mobile money". Pure. */
    function rh_payment_edit_change_text(array $changed, string $cur): string
    {
        $labels = ['amount' => 'amount', 'vat_amount' => 'VAT', 'payment_type' => 'type', 'payment_method' => 'method',
                   'payment_date' => 'date', 'transaction_reference' => 'reference', 'notes' => 'notes'];
        $words = static function ($v): string {
            $v = trim(str_replace(['_', '-'], ' ', (string)$v));
            return $v === '' ? '(blank)' : ucfirst(strtolower($v));
        };
        $parts = [];
        foreach ($changed as $f => [$o, $n]) {
            if (in_array($f, ['amount', 'vat_amount'], true)) {
                $parts[] = $labels[$f] . ' ' . $cur . ' ' . number_format((float)$o, 2) . ' → ' . $cur . ' ' . number_format((float)$n, 2);
            } elseif (in_array($f, ['payment_type', 'payment_method'], true)) {
                $parts[] = $labels[$f] . ' ' . $words($o) . ' → ' . $words($n);
            } else {
                $parts[] = ($labels[$f] ?? $f) . ' ' . ((string)$o === '' ? '(blank)' : (string)$o) . ' → ' . ((string)$n === '' ? '(blank)' : (string)$n);
            }
        }
        return implode(', ', $parts);
    }
}

if (!function_exists('rh_payment_edit_after')) {
    /**
     * Everything after the commit: event log, receipt (same number, marked Corrected; emailed only when ticked), the
     * payment's invoice and the booking's final invoice / statement regenerated with the same numbers. Never throws;
     * returns the extra sentences for the staff message.
     */
    function rh_payment_edit_after(PDO $pdo, array $res, bool $emailGuest, array $user): string
    {
        rh_payment_edit_docs_load();
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $cur = (string)getSetting('currency_symbol', 'MWK');
        $who = (string)($user['full_name'] ?? '') !== '' ? (string)$user['full_name'] : (string)($user['username'] ?? '');
        $pid = (int)$res['payment_id'];
        $bid = (int)$res['booking_id'];
        $notice = '';
        $today = date('Y-m-d');
        $oldDate = substr((string)($res['old']['payment_date'] ?? ''), 0, 10);
        $pastDay = ($oldDate !== '' && $oldDate < $today) || (string)$res['new']['payment_date'] < $today;

        try {
            rh_log_event('payment-edit', 'info', 'Payment edited', [
                'payment_id' => $pid, 'payment_reference' => $res['payment_reference'], 'booking_id' => $bid,
                'booking_reference' => $res['booking_reference'], 'changes' => $res['changes'], 'reason' => $res['reason'],
                'due_before' => $res['due_before'], 'due_after' => $res['due_after'], 'earlier_day' => $pastDay,
                'email_guest' => $emailGuest, 'by' => $who,
            ]);
        } catch (Throwable $e) {
            error_log('payment-edit log: ' . $e->getMessage());
        }

        // Guest and account, for the email decision and the email log.
        $guest = ['guest_name' => '', 'guest_email' => '', 'primary_booking_id' => null, 'final_invoice_generated' => 0, 'final_invoice_number' => ''];
        try {
            $gs = $pdo->prepare("SELECT guest_name, guest_email, primary_booking_id, final_invoice_generated, final_invoice_number FROM bookings WHERE id = ?");
            $gs->execute([$bid]);
            $guest = $gs->fetch(PDO::FETCH_ASSOC) ?: $guest;
        } catch (Throwable $e) {
            error_log('payment-edit guest: ' . $e->getMessage());
        }
        $to = trim((string)($guest['guest_email'] ?? ''));
        $acct = function_exists('rh_email_ctx_account') ? rh_email_ctx_account('room', $bid, $res['booking_reference']) : [];

        // RECEIPT: same number, marked "Corrected receipt" from now on (the receipt_events row is the marker).
        try {
            receipt_ensure_schema($pdo); // before any write below: its DDL must never meet an open transaction
            $number = (string)$res['receipt_number'];
            if ($number !== '') {
                receipt_log_event($pdo, $pid, $number, 'corrected', null, null,
                    mb_substr('Payment edited: ' . rh_payment_edit_change_text($res['changes'], $cur) . '. Reason: ' . $res['reason'], 0, 2000), $user);
            }
            if ($emailGuest) {
                if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    if (function_exists('rh_email_send_log_not_sent')) {
                        rh_email_send_log_not_sent('receipt', $to, (string)$guest['guest_name'], $acct, 'No valid guest email address on file');
                    }
                    if ($number !== '') {
                        receipt_generate_pdf($pdo, $pid, $user);
                    }
                    $notice .= ' The corrected receipt was not emailed: the guest has no valid email address.';
                } else {
                    $sent = receipt_send_email($pdo, $pid, null, $user); // regenerates the PDF, then sends it
                    $notice .= !empty($sent['preview'])
                        ? ' Corrected receipt generated (development mode: preview only, no live email sent).'
                        : ' Corrected receipt emailed to ' . $to . '.';
                }
            } else {
                if (function_exists('rh_email_send_log_not_sent')) {
                    rh_email_send_log_not_sent('receipt', $to, (string)$guest['guest_name'], $acct, 'Staff chose not to email the guest a corrected receipt');
                }
                if ($number !== '') {
                    receipt_generate_pdf($pdo, $pid, $user);
                    $notice .= ' Receipt ' . $number . ' now shows the corrected amount (not emailed to the guest).';
                }
            }
        } catch (Throwable $e) {
            error_log('payment-edit receipt: ' . $e->getMessage());
            $notice .= ' The corrected receipt could not be ' . ($emailGuest ? 'emailed' : 'regenerated') . ' (' . $e->getMessage() . '). Use Send Receipt on the payment.';
        }

        // INVOICES: the payment's own invoice and the booking's final invoice / statement keep their numbers and now
        // show the corrected paid figure (no email).
        try {
            $inv = $pdo->prepare("SELECT invoice_generated, invoice_number, invoice_path FROM payments WHERE id = ?");
            $inv->execute([$pid]);
            $ir = $inv->fetch(PDO::FETCH_ASSOC) ?: [];
            $invNo = trim((string)($ir['invoice_number'] ?? ''));
            if ((int)($ir['invoice_generated'] ?? 0) === 1 && $invNo !== '' && trim((string)($ir['invoice_path'] ?? '')) !== '') {
                $g = generateInvoicePDF($bid, $invNo);
                if ($g) {
                    $pdo->prepare("UPDATE payments SET invoice_path = ? WHERE id = ?")->execute([$g['relative_path'], $pid]);
                    $notice .= ' Invoice ' . $invNo . ' updated.';
                }
            }
            $docIds = array_values(array_unique(array_filter([$bid, (int)($guest['primary_booking_id'] ?? 0)])));
            foreach ($docIds as $docId) {
                $fs = $pdo->prepare("SELECT id, primary_booking_id, final_invoice_generated, final_invoice_number FROM bookings WHERE id = ?");
                $fs->execute([$docId]);
                $fr = $fs->fetch(PDO::FETCH_ASSOC);
                $fNo = trim((string)($fr['final_invoice_number'] ?? ''));
                if (!$fr || (int)$fr['final_invoice_generated'] !== 1 || $fNo === '') {
                    continue;
                }
                $lead = !empty($fr['primary_booking_id']) ? (int)$fr['primary_booking_id'] : (int)$fr['id'];
                $gd = $pdo->prepare("SELECT COALESCE(SUM(amount_due), 0) FROM bookings WHERE (id = ? OR primary_booking_id = ?) AND deleted_at IS NULL");
                $gd->execute([$lead, $lead]);
                $groupDue = round((float)$gd->fetchColumn(), 2);
                $g = generateInvoicePDF($docId, $fNo, $groupDue <= $tol); // owing = final invoice, settled = paid statement
                if ($g) {
                    $pdo->prepare("UPDATE bookings SET final_invoice_path = ? WHERE id = ?")->execute([$g['relative_path'], $docId]);
                    $notice .= ' The booking\'s ' . ($groupDue <= $tol ? 'statement' : 'final invoice') . ' ' . $fNo . ' was updated (not emailed).';
                }
            }
        } catch (Throwable $e) {
            error_log('payment-edit invoices: ' . $e->getMessage());
            $notice .= ' The invoice could not be regenerated (' . $e->getMessage() . '). Use Regenerate on the Invoices page.';
        }

        if ($pastDay) {
            $day = $oldDate !== '' && $oldDate < $today ? $oldDate : (string)$res['new']['payment_date'];
            $notice .= ' This payment is dated ' . date('j M Y', strtotime($day)) . ': the End of Day report for that day now shows the corrected figures, so a copy printed or emailed earlier is out of date. The change is in the payment\'s History.';
        }
        return $notice;
    }
}
