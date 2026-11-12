<?php
/**
 * Add or take off nights on an in-house stay (owner 2026-11-13: "be able to simply put in an extra night or for refunds
 * take off nights and as a guard rail amount should be more guarded than just one click").
 *
 * ONE place turns (booking, +N / -N nights) into dates and money, so the review card (bookings.php stay_nights_quote)
 * and the save (stay_nights_apply) are always the same figure:
 *   - nights are added or taken off at the END of the stay (the check-out date moves), never before today;
 *   - everything is at the BOOKED nightly rate (rh_reprice_at_booked_rate()), with the same rate-plan rule as extend
 *     stay (rh_rate_plan_dropped(): a discount the new stay no longer qualifies for is removed);
 *   - added nights pass the shared stay-change gate (rh_validate_booking_stay_change(): room type, assigned and joined
 *     rooms, blocks, maintenance, locks);
 *   - taking nights off a stay that was paid for gives the unused money back the way front desk chooses each time
 *     (owner D4 2026-11-13): a refund to the original payment(s) in full, or a credit note. The amount is shown and
 *     must be typed back. Nothing paid / less paid than the new bill: the balance simply goes down.
 *   - the save re-quotes under the row lock and refuses when the figures differ from what staff reviewed.
 *
 * Only checked-in bookings. Pure functions here never write; rh_stay_nights_apply() is one transaction.
 */

if (!function_exists('rh_stay_nights_quote')) {

    /** Y-m-d $days after $ymd (negative = before). */
    function rh_stay_nights_shift(string $ymd, int $days): string
    {
        $d = new DateTime($ymd);
        $d->modify(($days >= 0 ? '+' : '') . $days . ' days');
        return $d->format('Y-m-d');
    }

    /**
     * Quote a change of $delta nights (positive = add, negative = take off) for a bookings row.
     * Read-only. $checkAvailability: run the stay-change gate for added nights inside a transaction that is rolled
     * back (only when no transaction is open; the save runs the real gate itself).
     *
     * @return array ok, error, delta, old_check_out, new_check_out, check_in, old_nights, new_nights, per_night,
     *               current_text, new_text, old_total, new_total, total_delta, folio, paid, old_due, new_due,
     *               money_back, plan_note, drop, reprice, available, availability_error
     */
    function rh_stay_nights_quote(PDO $pdo, array $b, int $delta, ?string $today = null, bool $checkAvailability = false, string $cur = ''): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $today = $today ?? date('Y-m-d');
        if ($cur === '') {
            $cur = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
        }
        $out = ['ok' => false, 'error' => '', 'delta' => $delta];
        if (($b['status'] ?? '') !== 'checked-in') {
            $out['error'] = 'Nights can only be added or taken off while the guest is checked in.';
            return $out;
        }
        if ($delta === 0) {
            $out['error'] = 'Choose how many nights to add or take off.';
            return $out;
        }
        if (abs($delta) > 60) {
            $out['error'] = 'Change at most 60 nights at a time.';
            return $out;
        }
        $in = substr((string)$b['check_in_date'], 0, 10);
        $oldOut = substr((string)$b['check_out_date'], 0, 10);
        $newOut = rh_stay_nights_shift($oldOut, $delta);
        if ($delta > 0 && $newOut < $today) {
            $short = (int)(new DateTime($newOut))->diff(new DateTime($today))->days;
            $out['error'] = 'The new check-out (' . date('j M Y', strtotime($newOut)) . ') would still be in the past. Add at least '
                . ($delta + $short) . ' night(s) to reach today.';
            return $out;
        }
        if ($delta < 0) {
            if ($newOut <= $today) {
                $out['error'] = 'Nights already stayed (or tonight, when the guest leaves today) cannot be taken off here. If the guest is leaving today, use Check out: it settles the unused nights.';
                return $out;
            }
            if ($newOut <= $in) {
                $out['error'] = 'The stay must keep at least one night.';
                return $out;
            }
        }
        $newNights = (int)(new DateTime($in))->diff(new DateTime($newOut))->days;
        $oldNights = max(1, (int)($b['number_of_nights'] ?? 1));

        if (!function_exists('rh_rate_plan_dropped')) {
            require_once __DIR__ . '/pricing.php';
        }
        if (!function_exists('rh_booked_rate_change_for_booking')) {
            require_once __DIR__ . '/booked-rate-change.php';
        }
        $drop = rh_rate_plan_dropped($pdo, $b, $in, $newOut, $newNights, (int)($b['room_id'] ?? 0));
        $rp = rh_reprice_at_booked_rate($b, $newNights, (float)($drop['per_night'] ?? 0));
        $nowLine = rh_booked_rate_line($b, null, ['currency' => $cur]);
        $newLine = rh_booked_rate_change_for_booking($b, $rp, $newNights, ['currency' => $cur]);

        $folio = round((float)($b['folio_charges_total'] ?? 0), 2);
        $paid = round((float)($b['amount_paid'] ?? 0), 2);
        $oldTotal = round((float)$rp['old_gross'], 2);
        $newTotal = round((float)$rp['new_gross'], 2);
        $oldBill = round($oldTotal + $folio, 2);
        $newBill = round($newTotal + $folio, 2);
        $oldDue = round(max(0.0, $oldBill - $paid), 2);
        $newDue = round(max(0.0, $newBill - $paid), 2);
        // Money that has to go back because of THIS change only (an older credit, if any, is not this change's).
        $moneyBack = round(max(0.0, $paid - $newBill) - max(0.0, $paid - $oldBill), 2);
        if ($moneyBack <= $tol) {
            $moneyBack = 0.0;
        }

        $out = [
            'ok' => true, 'error' => '', 'delta' => $delta,
            'check_in' => $in, 'old_check_out' => $oldOut, 'new_check_out' => $newOut,
            'old_nights' => $oldNights, 'new_nights' => $newNights,
            'per_night' => round((float)$rp['per_night'], 2),
            'current_text' => !empty($nowLine['ok']) ? (string)$nowLine['text'] : '',
            'new_text' => !empty($newLine['ok']) ? (string)$newLine['text'] : '',
            'old_total' => $oldTotal, 'new_total' => $newTotal, 'total_delta' => round($newTotal - $oldTotal, 2),
            'folio' => $folio, 'paid' => $paid, 'old_due' => $oldDue, 'new_due' => $newDue, 'money_back' => $moneyBack,
            'plan_note' => !empty($drop['removed'])
                ? 'The ' . (string)$drop['plan_name'] . ' discount will be removed: ' . (string)$drop['reason'] . '. '
                    . rh_stay_nights_money((float)$drop['per_night'], $cur) . ' a night is added back to the rate.'
                : '',
            'drop' => $drop, 'reprice' => $rp,
            'available' => null, 'availability_error' => '',
        ];

        if ($delta > 0 && $checkAvailability && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            try {
                $gate = rh_validate_booking_stay_change((int)$b['id'], $in, $newOut);
                $out['available'] = !empty($gate['ok']);
                $out['availability_error'] = (string)($gate['error'] ?? '');
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }
        return $out;
    }

    /** "K450,300.00" style money for staff text. */
    function rh_stay_nights_money(float $v, string $cur): string
    {
        return trim($cur) . ' ' . number_format($v, 2);
    }

    /** One plain sentence for History / messages. */
    function rh_stay_nights_describe(array $q, string $cur): string
    {
        $n = abs((int)$q['delta']);
        $verb = $q['delta'] > 0 ? 'Added ' . $n . ' night(s)' : 'Took off ' . $n . ' night(s)';
        $s = $verb . ': check-out ' . date('j M Y', strtotime($q['old_check_out'])) . ' -> ' . date('j M Y', strtotime($q['new_check_out']))
            . ', ' . (int)$q['old_nights'] . ' -> ' . (int)$q['new_nights'] . ' night(s). Room total '
            . rh_stay_nights_money((float)$q['old_total'], $cur) . ' -> ' . rh_stay_nights_money((float)$q['new_total'], $cur)
            . ($q['new_text'] !== '' ? ' (' . $q['new_text'] . ')' : '') . '. Balance ' . rh_stay_nights_money((float)$q['old_due'], $cur)
            . ' -> ' . rh_stay_nights_money((float)$q['new_due'], $cur) . '.';
        if ($q['plan_note'] !== '') {
            $s .= ' ' . $q['plan_note'];
        }
        return $s;
    }

    /**
     * Apply a reviewed change. One transaction with the booking row locked; the figures are re-quoted under the lock
     * and must equal what staff reviewed (expected_new_total / expected_total_delta, BALANCE_TOLERANCE).
     *
     * $in: delta (int), expected_new_total, expected_total_delta, reason (required, >= 3 chars),
     *      money_back_mode ('refund' | 'credit_note', needed when money goes back), money_back_typed (must equal it),
     *      can_refund (bool: the user holds refund_payment).
     * $user: id, full_name / username.
     * Throws Exception with a staff-readable message (nothing saved).
     *
     * @return array quote, describe, refund_total, refund_legs (for the notices after the commit), credit_note
     */
    function rh_stay_nights_apply(PDO $pdo, int $bookingId, array $in, array $user): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $cur = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
        $delta = (int)($in['delta'] ?? 0);
        $reason = trim((string)preg_replace('/\s+/u', ' ', (string)($in['reason'] ?? '')));
        if (mb_strlen($reason) < 3) {
            throw new Exception('Say why the nights are changing (the reason is required).');
        }
        $reason = mb_substr($reason, 0, 300);
        if (!isset($in['expected_new_total']) || !is_numeric($in['expected_new_total'])
            || !isset($in['expected_total_delta']) || !is_numeric($in['expected_total_delta'])) {
            throw new Exception('Review the change before confirming (no figures were sent). Reload the page if this keeps happening.');
        }
        $uid = (int)($user['id'] ?? 0);
        $who = (string)($user['full_name'] ?? ($user['username'] ?? ''));

        foreach (['/../admin/includes/finance-account-sync.php', '/finance-sequences.php', '/../config/credit-notes.php', '/booking-timeline.php', '/../admin/includes/audit-functions.php'] as $f) {
            if (is_file(__DIR__ . $f)) {
                require_once __DIR__ . $f;
            }
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $lock->execute([$bookingId]);
            $b = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$b) {
                throw new Exception('Booking not found.');
            }
            if (!recalculateBookingFinancials($bookingId)) {
                throw new Exception('Could not recalculate the booking balance - nothing was changed.');
            }
            $lock->execute([$bookingId]);
            $b = $lock->fetch(PDO::FETCH_ASSOC) ?: $b;

            $q = rh_stay_nights_quote($pdo, $b, $delta, null, false, $cur);
            if (empty($q['ok'])) {
                throw new Exception($q['error']);
            }
            if (abs((float)$q['new_total'] - (float)$in['expected_new_total']) > $tol
                || abs((float)$q['total_delta'] - (float)$in['expected_total_delta']) > $tol) {
                throw new Exception('The figures changed since you reviewed them (now ' . rh_stay_nights_money((float)$q['new_total'], $cur)
                    . '). Nothing was saved - review the change again.');
            }

            $mode = '';
            if ($q['money_back'] > $tol) {
                $mode = (string)($in['money_back_mode'] ?? '');
                if (!in_array($mode, ['refund', 'credit_note'], true)) {
                    throw new Exception('Choose how the ' . rh_stay_nights_money((float)$q['money_back'], $cur) . ' goes back to the guest: refund or credit note.');
                }
                if ($mode === 'refund' && empty($in['can_refund'])) {
                    throw new Exception('You do not have permission to refund payments. Choose a credit note, or ask someone with the Refund payments permission.');
                }
                $typed = isset($in['money_back_typed']) ? (float)str_replace([',', ' '], '', (string)$in['money_back_typed']) : -1.0;
                if (abs($typed - (float)$q['money_back']) > $tol) {
                    throw new Exception('Type the amount going back exactly as shown (' . number_format((float)$q['money_back'], 2) . ') to confirm.');
                }
            }

            if ($delta > 0) {
                $gate = rh_validate_booking_stay_change($bookingId, (string)$q['check_in'], (string)$q['new_check_out']);
                if (empty($gate['ok'])) {
                    throw new Exception('Cannot add the night(s): ' . (string)$gate['error']);
                }
            }

            $before = [
                'check_out_date' => $q['old_check_out'], 'number_of_nights' => (int)$q['old_nights'],
                'total_with_vat' => (float)$q['old_total'], 'amount_due' => round((float)($b['amount_due'] ?? 0), 2),
                'amount_paid' => round((float)($b['amount_paid'] ?? 0), 2),
            ];

            rh_write_booking_stay_totals($pdo, $bookingId, $q['reprice']['totals'], (int)$q['new_nights'], (string)$q['check_in'],
                (string)$q['new_check_out'], (float)$q['reprice']['new_child_supplement']);
            if (!empty($q['drop']['removed'])) {
                rh_rate_plan_drop_clear($pdo, $bookingId);
            }
            if (!recalculateBookingFinancials($bookingId)) {
                throw new Exception('Could not recalculate the booking balance - nothing was changed.');
            }

            $refundTotal = 0.0;
            $refundLegs = [];
            $creditNote = null;
            $describe = rh_stay_nights_describe($q, $cur);
            if ($mode === 'refund') {
                $refundReason = $describe . ' Reason: ' . $reason;
                $ins = $pdo->prepare("
                    INSERT INTO payments (
                        payment_reference, booking_type, booking_id, booking_reference,
                        payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                        payment_method, payment_type, payment_status, original_payment_id,
                        refund_reason, refund_status, refund_amount, refund_notes,
                        recorded_by, created_at
                    ) VALUES (?, 'room', ?, ?, ?, ?, ?, ?, ?, ?, 'refund', 'completed', ?, 'early_checkout', 'completed', ?, ?, ?, NOW())
                ");
                $todayYmd = date('Y-m-d');
                foreach (allocateBookingRefunds($pdo, $bookingId, (float)$q['money_back'], true) as $alloc) {
                    $orig = $alloc['payment'];
                    $leg = round((float)$alloc['amount'], 2);
                    if ($leg <= $tol) {
                        continue;
                    }
                    $sp = rh_refund_vat_split($orig, $leg);
                    $vr = rh_refund_leg_rate($pdo, $orig, (float)$sp['rate']);
                    $va = (float)$sp['vat'];
                    $legRef = finance_next_refund_reference($pdo, $todayYmd);
                    $ins->execute([
                        $legRef, $bookingId, $b['booking_reference'], $todayYmd,
                        round($leg - $va, 2), $vr, $va, $leg,
                        $orig['payment_method'] ?: 'other', (int)$orig['id'],
                        $leg, mb_substr($refundReason, 0, 1000), $uid ?: null,
                    ]);
                    rh_refresh_original_payment_status($pdo, (int)$orig['id']);
                    $refundTotal = round($refundTotal + $leg, 2);
                    $refundLegs[] = ['orig' => array_merge($orig, ['booking_type' => 'room', 'booking_id' => $bookingId, 'booking_reference' => $b['booking_reference']]),
                        'ref' => $legRef, 'amount' => $leg, 'reason' => $refundReason];
                }
                if ($refundTotal + $tol < (float)$q['money_back']) {
                    throw new Exception('Could not refund the full ' . rh_stay_nights_money((float)$q['money_back'], $cur) . ' against the payments on this booking. Nothing was changed.');
                }
            } elseif ($mode === 'credit_note') {
                $vatOn = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
                $split = rh_account_vat_split($pdo, 'room', $bookingId, (float)$q['money_back'], $vatOn ? (float)getSetting('vat_rate') : 0.0);
                $cn = issueCreditNote($pdo, [
                    'booking_type' => 'room', 'booking_id' => $bookingId, 'booking_reference' => (string)$b['booking_reference'],
                    'guest_name' => trim((string)$b['guest_name']) !== '' ? (string)$b['guest_name'] : 'Guest',
                    'guest_email' => (string)($b['guest_email'] ?? ''),
                    'amount' => (float)$q['money_back'], 'vat_rate' => (float)$split['rate'], 'vat_amount' => (float)$split['vat'],
                    'reason' => 'early_checkout',
                    'reason_notes' => mb_substr($describe . ' Reason: ' . $reason, 0, 1000),
                    'issued_by' => $uid > 0 ? $uid : (int)$pdo->query('SELECT MIN(id) FROM admin_users')->fetchColumn(),
                    'send_email' => false, 'generate_pdf' => false,
                ]);
                if (empty($cn['success'])) {
                    throw new Exception('The credit note could not be created: ' . ($cn['error'] ?? 'unknown error') . '. Nothing was changed.');
                }
                $legs = recordCreditNoteRefundLegs($pdo, $bookingId, (string)$b['booking_reference'], (float)$q['money_back'],
                    (int)$cn['credit_note_id'], (string)$cn['credit_note_number'], $uid, 'early_checkout', 'room');
                if ($legs['total'] + $tol < (float)$q['money_back']) {
                    throw new Exception('Could not move the full amount into the credit note. Nothing was changed.');
                }
                $creditNote = ['id' => (int)$cn['credit_note_id'], 'number' => (string)$cn['credit_note_number'], 'amount' => (float)$q['money_back'],
                    'email' => trim((string)($b['guest_email'] ?? ''))];
            }
            if (!recalculateBookingFinancials($bookingId)) {
                throw new Exception('Could not recalculate the booking balance - nothing was changed.');
            }
            $lock->execute([$bookingId]);
            $a = $lock->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($mode !== '' && (float)($a['credit_balance'] ?? 0) > $tol + max(0.0, (float)($b['credit_balance'] ?? 0))) {
                throw new Exception('The money going back did not balance. Nothing was changed.');
            }

            $after = [
                'check_out_date' => (string)($a['check_out_date'] ?? $q['new_check_out']), 'number_of_nights' => (int)($a['number_of_nights'] ?? $q['new_nights']),
                'total_with_vat' => round((float)($a['total_with_vat'] ?? $q['new_total']), 2), 'amount_due' => round((float)($a['amount_due'] ?? 0), 2),
                'amount_paid' => round((float)($a['amount_paid'] ?? 0), 2),
            ];
            if ($refundTotal > $tol) {
                $describe .= ' Refunded ' . rh_stay_nights_money($refundTotal, $cur) . ' to the original payment(s).';
            }
            if ($creditNote) {
                $describe .= ' ' . rh_stay_nights_money($creditNote['amount'], $cur) . ' moved to credit note ' . $creditNote['number'] . '.';
            }
            $describe .= ' Reason: ' . $reason;
            $meta = [
                'delta_nights' => $delta, 'old' => $before, 'new' => $after, 'per_night' => $q['per_night'], 'money_back' => $q['money_back'],
                'money_back_mode' => $mode, 'refunded' => $refundTotal, 'credit_note' => $creditNote['number'] ?? null, 'reason' => $reason,
                'rate_plan_removed' => !empty($q['drop']['removed']),
            ];
            if (function_exists('logBookingEvent')) {
                logBookingEvent($bookingId, (string)$b['booking_reference'], $delta > 0 ? 'Nights added' : 'Nights taken off', 'update', $describe,
                    $q['old_check_out'], $q['new_check_out'], 'admin', $uid ?: null, $who !== '' ? $who : null, $meta);
            }
            if (function_exists('logBookingAudit')) {
                logBookingAudit($bookingId, 'stay_nights_changed', $before, $after + ['reason' => $reason, 'money_back_mode' => $mode], $describe, (string)$b['booking_reference']);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e instanceof Exception ? $e : new Exception($e->getMessage());
        }

        if (function_exists('rh_log_event')) {
            rh_log_event('bookings', 'info', $delta > 0 ? 'Nights added to stay' : 'Nights taken off stay', [
                'booking_id' => $bookingId, 'ref' => $b['booking_reference'], 'delta' => $delta, 'old_check_out' => $q['old_check_out'],
                'new_check_out' => $q['new_check_out'], 'old_total' => $q['old_total'], 'new_total' => $q['new_total'],
                'money_back' => $q['money_back'], 'mode' => $mode, 'refunded' => $refundTotal, 'credit_note' => $creditNote['number'] ?? null,
                'by' => $user['username'] ?? null,
            ]);
        }
        if (!empty($q['drop']['removed']) && function_exists('rh_rate_plan_drop_log')) {
            rh_rate_plan_drop_log($bookingId, $b['booking_reference'] ?? null, $q['drop'], (float)rh_booked_stay_split($b)['per_night'],
                (float)$q['reprice']['per_night'], (float)$q['reprice']['old_gross'], (float)$q['reprice']['new_gross'], $who);
        }
        return ['quote' => $q, 'describe' => $describe, 'refund_total' => $refundTotal, 'refund_legs' => $refundLegs, 'credit_note' => $creditNote, 'after' => $after];
    }
}
