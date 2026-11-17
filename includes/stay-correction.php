<?php
/**
 * Correct the dates of a CHECKED-OUT room after the guest has left (group bookings plan A8, owner 2026-11-17).
 *
 * Staff record what really happened: a guest who arrived late, left early or stayed on. One place turns
 * (booking, new check-in, new check-out[, extra-night rate]) into dates and money, so the review (preview) and the
 * save are always the same figure:
 *   - checked-out rooms only (never cancelled, expired, no-show or deleted); permission correct_stay_after_checkout;
 *   - kept nights stay at the BOOKED nightly rate (rh_booked_stay_split, gross: VAT and levy inside), with the same
 *     rate-plan rule as a date change (rh_rate_plan_dropped); extra nights default to that same rate (D-R1b) and staff
 *     may set another rate a night, with a reason;
 *   - a room whose booking carries a cancellation policy snapshot cannot have nights CUT here yet (the unused-nights
 *     fee comes with the next update); moving the dates without changing the count, or adding nights, is allowed.
 *     A room without a snapshot gets no automatic unused-nights fee (owner answer 1, 2026-11-17);
 *   - a lower bill first clears what is owed; money that must go back is a refund to the original payment(s) or a
 *     credit note, chosen by staff (never left as credit_balance). A refund needs refund_payment;
 *   - a clash with another booking on the room for the added nights is a warning staff must tick, not a block;
 *   - the save re-quotes under the row lock and refuses when the figures differ from what staff reviewed;
 *   - History (timeline + audit), the date-adjustment log and System Logs get one entry each.
 * Receipts never change. Issued invoices keep their numbers and are rebuilt with the new figures after the save
 * (rh_stay_correction_refresh_invoices; never inside the transaction).
 */

if (!function_exists('rh_stay_correction_quote')) {

    /** "MWK 1,066,500.00" style money for staff text. */
    function rh_stay_correction_money(float $v, string $cur): string
    {
        return trim($cur) . ' ' . number_format($v, 2);
    }

    /** True when the booking row carries a saved cancellation policy snapshot (not NULL / empty). */
    function rh_stay_correction_has_snapshot(array $b): bool
    {
        return trim((string)($b['cancellation_policy_snapshot'] ?? '')) !== '';
    }

    /** The night ranges [from, to) in the new stay that were not in the old stay. */
    function rh_stay_correction_added_ranges(string $oldIn, string $oldOut, string $newIn, string $newOut): array
    {
        $r = [];
        if ($newIn < $oldIn) {
            $r[] = [$newIn, min($oldIn, $newOut)];
        }
        if ($newOut > $oldOut) {
            $r[] = [max($oldOut, $newIn), $newOut];
        }
        return array_values(array_filter($r, static function ($x) { return $x[0] < $x[1]; }));
    }

    /**
     * Other bookings on this booking's room(s) during the ADDED nights (warning only). Rooms = every room the booking
     * held (booking_rooms, released holds included) plus the primary pointer.
     *
     * @return array<int,array{id:int,ref:string,guest:string,check_in:string,check_out:string,status:string,room:string}>
     */
    function rh_stay_correction_clashes(PDO $pdo, array $b, string $newIn, string $newOut): array
    {
        $ranges = rh_stay_correction_added_ranges(substr((string)$b['check_in_date'], 0, 10), substr((string)$b['check_out_date'], 0, 10), $newIn, $newOut);
        if (!$ranges) {
            return [];
        }
        $rooms = function_exists('getBookingRoomIds') ? getBookingRoomIds((int)$b['id'], true) : [];
        if ((int)($b['individual_room_id'] ?? 0) > 0) {
            $rooms[] = (int)$b['individual_room_id'];
        }
        $rooms = array_values(array_unique(array_filter(array_map('intval', $rooms))));
        if (!$rooms) {
            return [];
        }
        $st = $pdo->prepare("
            SELECT DISTINCT b.id, b.booking_reference, b.guest_name, b.check_in_date, b.check_out_date, b.status, ir.room_number
            FROM bookings b
            LEFT JOIN booking_rooms br ON br.booking_id = b.id
            LEFT JOIN individual_rooms ir ON ir.id = ?
            WHERE b.id <> ? AND b.deleted_at IS NULL
              AND b.status NOT IN ('cancelled', 'no-show', 'expired')
              AND (b.individual_room_id = ? OR br.individual_room_id = ?)
              AND b.check_in_date < ? AND b.check_out_date > ?
        ");
        $out = [];
        foreach ($rooms as $rid) {
            foreach ($ranges as [$from, $to]) {
                $st->execute([$rid, (int)$b['id'], $rid, $rid, $to, $from]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $out[(int)$c['id']] = [
                        'id' => (int)$c['id'], 'ref' => (string)$c['booking_reference'], 'guest' => (string)$c['guest_name'],
                        'check_in' => substr((string)$c['check_in_date'], 0, 10), 'check_out' => substr((string)$c['check_out_date'], 0, 10),
                        'status' => (string)$c['status'], 'room' => (string)($c['room_number'] ?? ''),
                    ];
                }
            }
        }
        return array_values($out);
    }

    /** Ids of every room in $bookingId's group (root + members, any status, not deleted); [$bookingId] when single. */
    function rh_stay_correction_group_ids(PDO $pdo, int $bookingId): array
    {
        $st = $pdo->prepare('SELECT COALESCE(NULLIF(primary_booking_id, 0), id) FROM bookings WHERE id = ?');
        $st->execute([$bookingId]);
        $root = (int)$st->fetchColumn();
        if ($root <= 0) {
            return [$bookingId];
        }
        $st = $pdo->prepare('SELECT id FROM bookings WHERE (id = ? OR primary_booking_id = ?) AND deleted_at IS NULL');
        $st->execute([$root, $root]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array($bookingId, $ids, true)) {
            $ids[] = $bookingId;
        }
        return $ids;
    }

    /**
     * Invoice numbers issued for this room: its own payment rows, the booking's final invoice number and, in a group,
     * the group's ONE invoice number (rh_invoice_group_number - only one room's payment row holds it). Refund rows hold
     * credit-note numbers: never listed.
     */
    function rh_stay_correction_invoice_numbers(PDO $pdo, array $b): array
    {
        $nums = [];
        $st = $pdo->prepare("SELECT DISTINCT invoice_number FROM payments WHERE booking_type = 'room' AND booking_id = ? AND deleted_at IS NULL
            AND COALESCE(payment_type, '') <> 'refund'
            AND invoice_generated = 1 AND invoice_number IS NOT NULL AND invoice_number <> ''");
        $st->execute([(int)$b['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $nums[] = trim((string)$n);
        }
        $f = trim((string)($b['final_invoice_number'] ?? ''));
        if ($f !== '') {
            $nums[] = $f;
        }
        $ids = rh_stay_correction_group_ids($pdo, (int)$b['id']);
        if (count($ids) > 1) {
            if (!function_exists('rh_invoice_group_number')) {
                require_once __DIR__ . '/../config/invoice.php';
            }
            $root = !empty($b['primary_booking_id']) ? (int)$b['primary_booking_id'] : (int)$b['id'];
            $gn = rh_invoice_group_number($pdo, $ids, $root);
            if ($gn !== '') {
                $nums[] = $gn;
            }
        }
        return array_values(array_unique(array_filter($nums)));
    }

    /**
     * Quote a correction. Read-only.
     * $extraRate: the rate a night for ADDED nights (gross, as shown in rate x nights); null = the booked rate.
     *
     * @return array ok, error, check_in/check_out (old), new_check_in, new_check_out, old_nights, new_nights,
     *   kept_nights, extra_nights, per_night, extra_rate, rate_changed, old_room_total, new_room_total, room_delta,
     *   old_bill, new_bill, paid, old_due, new_due, money_back, rate_line_old, rate_line_new, plan_note, invoices,
     *   clashes, has_snapshot, totals, new_child_supplement, drop
     */
    function rh_stay_correction_quote(PDO $pdo, array $b, string $newIn, string $newOut, ?float $extraRate = null, ?string $today = null, string $cur = ''): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $today = $today ?? date('Y-m-d');
        if ($cur === '') {
            $cur = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
        }
        $out = ['ok' => false, 'error' => ''];
        if (!empty($b['deleted_at'])) {
            $out['error'] = 'This booking has been deleted. Restore it before making changes.';
            return $out;
        }
        if (($b['status'] ?? '') !== 'checked-out') {
            $out['error'] = 'Only a checked-out stay can be corrected here. Cancelled, expired and no-show bookings cannot.';
            return $out;
        }
        $inD = DateTime::createFromFormat('!Y-m-d', $newIn);
        $outD = DateTime::createFromFormat('!Y-m-d', $newOut);
        if (!$inD || !$outD || $inD->format('Y-m-d') !== $newIn || $outD->format('Y-m-d') !== $newOut) {
            $out['error'] = 'Enter both dates.';
            return $out;
        }
        if ($outD <= $inD) {
            $out['error'] = 'The check-out date must be after the check-in date.';
            return $out;
        }
        if ($newOut > $today) {
            $out['error'] = 'The guest has already checked out, so the stay cannot end after today.';
            return $out;
        }
        $oldIn = substr((string)$b['check_in_date'], 0, 10);
        $oldOut = substr((string)$b['check_out_date'], 0, 10);
        if ($newIn === $oldIn && $newOut === $oldOut) {
            $out['error'] = 'The dates are the same as now. Change the check-in or check-out date.';
            return $out;
        }
        $newNights = (int)$inD->diff($outD)->days;
        if ($newNights > 60) {
            $out['error'] = 'A corrected stay can be at most 60 nights.';
            return $out;
        }
        $split = rh_booked_stay_split($b);
        $oldNights = (int)$split['nights'];
        $hasSnap = rh_stay_correction_has_snapshot($b);
        if ($newNights < $oldNights && $hasSnap) {
            $out['error'] = 'This booking has a cancellation policy; unused nights on it can be corrected after the next update.';
            return $out;
        }
        if ($extraRate !== null && ($extraRate < 0 || !is_finite($extraRate))) {
            $out['error'] = 'The rate a night cannot be below zero.';
            return $out;
        }

        if (!function_exists('rh_rate_plan_dropped')) {
            require_once __DIR__ . '/pricing.php';
        }
        if (!function_exists('rh_booked_rate_line')) {
            require_once __DIR__ . '/booked-rate.php';
        }
        $drop = rh_rate_plan_dropped($pdo, $b, $newIn, $newOut, $newNights, (int)($b['room_id'] ?? 0));
        $addBack = max(0.0, (float)($drop['per_night'] ?? 0));
        $perNight = round((float)$split['per_night'] + $addBack, 2);
        $extraNights = max(0, $newNights - $oldNights);
        $keptNights = $newNights - $extraNights;
        $rate = $extraRate === null ? $perNight : round($extraRate, 2);
        $rateChanged = $extraNights > 0 && abs($rate - $perNight) > $tol;

        if (!$rateChanged) {
            $rp = rh_reprice_at_booked_rate($b, $newNights, $addBack);
            $tt = $rp['totals'];
            $newChild = (float)$rp['new_child_supplement'];
        } else {
            $newGross = round(((float)$split['per_night'] + $addBack) * $keptNights + $rate * $extraNights + (float)$split['package_gross'], 2);
            $tt = rh_stay_totals_with_package($newGross, (float)$split['package_gross'], rh_booking_has_levy($b));
            $oldChild = (float)($b['child_supplement_total'] ?? 0);
            $newChild = $oldChild > 0 ? round($oldChild * ($newNights / max(1, $oldNights)), 2) : 0.0;
        }
        $oldRoom = round((float)$split['total_with_vat'], 2);
        $newRoom = round((float)$tt['total_with_vat'], 2);

        // Bill / paid exactly as the booking page shows them (getBookingFolioSummary): the room part changes, extras stay.
        $fs = getBookingFolioSummary((int)$b['id']);
        if (isset($fs['error'])) {
            $out['error'] = 'Could not read the booking\'s bill. Nothing was changed.';
            return $out;
        }
        $oldBill = round((float)$fs['grand_total'], 2);
        $paid = round((float)$fs['amount_paid'], 2);
        $newBill = round($oldBill - $oldRoom + $newRoom, 2);
        $oldDue = round(max(0.0, $oldBill - $paid), 2);
        $newDue = round(max(0.0, $newBill - $paid), 2);
        // Money that goes back because of THIS change only (an older credit is not this change's).
        $moneyBack = round(max(0.0, $paid - $newBill) - max(0.0, $paid - $oldBill), 2);
        if ($moneyBack <= $tol) {
            $moneyBack = 0.0;
        }

        $rateWord = static function (int $n): string { return $n === 1 ? '1 night' : $n . ' nights'; };
        $oldLine = rh_booked_rate_line($b, null, ['currency' => $cur]);
        $newLine = rh_stay_correction_money($perNight, $cur) . ' x ' . $rateWord($keptNights);
        if ($extraNights > 0) {
            $newLine .= ' + ' . rh_stay_correction_money($rate, $cur) . ' x ' . $rateWord($extraNights) . ($rateChanged ? ' (rate changed)' : '');
        }
        if ((float)$split['package_gross'] > $tol) {
            $newLine .= ' + package ' . rh_stay_correction_money((float)$split['package_gross'], $cur);
        }
        $newLine .= ' = ' . rh_stay_correction_money($newRoom, $cur);

        return [
            'ok' => true, 'error' => '',
            'check_in' => $oldIn, 'check_out' => $oldOut, 'new_check_in' => $newIn, 'new_check_out' => $newOut,
            'old_nights' => $oldNights, 'new_nights' => $newNights, 'kept_nights' => $keptNights, 'extra_nights' => $extraNights,
            'per_night' => $perNight, 'extra_rate' => $rate, 'rate_changed' => $rateChanged,
            'old_room_total' => $oldRoom, 'new_room_total' => $newRoom, 'room_delta' => round($newRoom - $oldRoom, 2),
            'old_bill' => $oldBill, 'new_bill' => $newBill, 'paid' => $paid, 'old_due' => $oldDue, 'new_due' => $newDue,
            'money_back' => $moneyBack,
            'rate_line_old' => !empty($oldLine['ok']) ? (string)$oldLine['text'] : '',
            'rate_line_new' => $newLine,
            'plan_note' => !empty($drop['removed'])
                ? 'The ' . (string)$drop['plan_name'] . ' discount is removed: ' . (string)$drop['reason'] . '. '
                    . rh_stay_correction_money($addBack, $cur) . ' a night is added back to the rate.'
                : '',
            'invoices' => rh_stay_correction_invoice_numbers($pdo, $b),
            'clashes' => rh_stay_correction_clashes($pdo, $b, $newIn, $newOut),
            'has_snapshot' => $hasSnap,
            'totals' => $tt, 'new_child_supplement' => $newChild, 'drop' => $drop,
        ];
    }

    /** The quote without internal arrays, for the review dialog (JSON). */
    function rh_stay_correction_public(array $q): array
    {
        unset($q['totals'], $q['drop'], $q['new_child_supplement']);
        return $q;
    }

    /** One plain sentence for History / messages. */
    function rh_stay_correction_describe(array $q, string $cur): string
    {
        $d = static function (string $ymd): string { return date('j M Y', strtotime($ymd)); };
        $s = 'Stay corrected after check-out: ' . $d($q['check_in']) . ' to ' . $d($q['check_out']) . ' -> '
            . $d($q['new_check_in']) . ' to ' . $d($q['new_check_out']) . ' (' . (int)$q['old_nights'] . ' -> ' . (int)$q['new_nights']
            . ' night(s)). Room ' . rh_stay_correction_money((float)$q['old_room_total'], $cur) . ' -> ' . rh_stay_correction_money((float)$q['new_room_total'], $cur)
            . ' (' . $q['rate_line_new'] . '). Bill ' . rh_stay_correction_money((float)$q['old_bill'], $cur) . ' -> '
            . rh_stay_correction_money((float)$q['new_bill'], $cur) . ', paid ' . rh_stay_correction_money((float)$q['paid'], $cur)
            . ', owed ' . rh_stay_correction_money((float)$q['old_due'], $cur) . ' -> ' . rh_stay_correction_money((float)$q['new_due'], $cur) . '.';
        if ($q['plan_note'] !== '') {
            $s .= ' ' . $q['plan_note'];
        }
        return $s;
    }

    /**
     * Apply a reviewed correction. One transaction with the booking row locked; the figures are re-quoted under the
     * lock and must equal what staff reviewed (expected_new_bill / expected_money_back, BALANCE_TOLERANCE).
     *
     * $in: new_check_in, new_check_out, extra_rate (optional), rate_reason (required when the rate changed),
     *      reason (required, >= 3 chars), expected_new_bill, expected_money_back, clash_ack (bool, needed when the room
     *      had other bookings on the added nights), money_back_mode ('refund' | 'credit_note', needed when money goes
     *      back), money_back_typed (must equal it), can_correct (bool: correct_stay_after_checkout), can_refund (bool:
     *      refund_payment).
     * $user: id, full_name / username.
     * Throws Exception with a staff-readable message (nothing saved).
     *
     * @return array quote, describe, refund_total, refund_legs, credit_notes, after, adjustment_id
     */
    function rh_stay_correction_apply(PDO $pdo, int $bookingId, array $in, array $user): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $cur = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
        if (empty($in['can_correct'])) {
            throw new Exception('You do not have permission to correct a stay after check-out.');
        }
        $reason = trim((string)preg_replace('/\s+/u', ' ', (string)($in['reason'] ?? '')));
        if (mb_strlen($reason) < 3) {
            throw new Exception('Say why the stay is being corrected (the reason is required).');
        }
        $reason = mb_substr($reason, 0, 300);
        if (!isset($in['expected_new_bill']) || !is_numeric($in['expected_new_bill'])
            || !isset($in['expected_money_back']) || !is_numeric($in['expected_money_back'])) {
            throw new Exception('Review the correction before confirming (no figures were sent). Reload the page if this keeps happening.');
        }
        $newIn = trim((string)($in['new_check_in'] ?? ''));
        $newOut = trim((string)($in['new_check_out'] ?? ''));
        $extraRate = (isset($in['extra_rate']) && trim((string)$in['extra_rate']) !== '')
            ? (float)str_replace([',', ' '], '', (string)$in['extra_rate']) : null;
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
            $lock = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
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

            $q = rh_stay_correction_quote($pdo, $b, $newIn, $newOut, $extraRate, null, $cur);
            if (empty($q['ok'])) {
                throw new Exception($q['error']);
            }
            if (abs((float)$q['new_bill'] - (float)$in['expected_new_bill']) > $tol
                || abs((float)$q['money_back'] - (float)$in['expected_money_back']) > $tol) {
                throw new Exception('The figures changed since you reviewed them (the new bill is now ' . rh_stay_correction_money((float)$q['new_bill'], $cur)
                    . '). Nothing was saved - review the correction again.');
            }
            $rateReason = trim((string)preg_replace('/\s+/u', ' ', (string)($in['rate_reason'] ?? '')));
            if ($q['rate_changed'] && mb_strlen($rateReason) < 3) {
                throw new Exception('Say why the extra nights are at a different rate (the rate reason is required).');
            }
            $rateReason = mb_substr($rateReason, 0, 300);
            if ($q['clashes'] && empty($in['clash_ack'])) {
                throw new Exception('The room had another booking on the added night(s). Tick "I understand" to save anyway.');
            }

            $mode = '';
            if ($q['money_back'] > $tol) {
                $mode = (string)($in['money_back_mode'] ?? '');
                if (!in_array($mode, ['refund', 'credit_note'], true)) {
                    throw new Exception('Choose how the ' . rh_stay_correction_money((float)$q['money_back'], $cur) . ' goes back: refund or credit note.');
                }
                if ($mode === 'refund' && empty($in['can_refund'])) {
                    throw new Exception('You do not have permission to refund payments. Choose a credit note, or ask someone with the Issue & settle refunds permission.');
                }
                $typed = isset($in['money_back_typed']) ? (float)str_replace([',', ' '], '', (string)$in['money_back_typed']) : -1.0;
                if (abs($typed - (float)$q['money_back']) > $tol) {
                    throw new Exception('Type the amount going back exactly as shown (' . number_format((float)$q['money_back'], 2) . ') to confirm.');
                }
            }

            $before = [
                'check_in_date' => $q['check_in'], 'check_out_date' => $q['check_out'], 'number_of_nights' => (int)$q['old_nights'],
                'room_total' => (float)$q['old_room_total'], 'bill' => (float)$q['old_bill'], 'amount_paid' => (float)$q['paid'],
                'amount_due' => (float)$q['old_due'],
            ];

            rh_write_booking_stay_totals($pdo, $bookingId, $q['totals'], (int)$q['new_nights'], (string)$q['new_check_in'],
                (string)$q['new_check_out'], (float)$q['new_child_supplement']);
            if (!empty($q['drop']['removed']) && function_exists('rh_rate_plan_drop_clear')) {
                rh_rate_plan_drop_clear($pdo, $bookingId);
            }
            if (!recalculateBookingFinancials($bookingId)) {
                throw new Exception('Could not recalculate the booking balance - nothing was changed.');
            }

            $describe = rh_stay_correction_describe($q, $cur);
            if ($q['rate_changed']) {
                $describe .= ' Extra night rate changed from ' . rh_stay_correction_money((float)$q['per_night'], $cur) . ' to '
                    . rh_stay_correction_money((float)$q['extra_rate'], $cur) . ' a night. Rate reason: ' . $rateReason . '.';
            }
            $mb = rh_stay_correction_money_back($pdo, $b, (float)$q['money_back'], $mode, $describe . ' Reason: ' . $reason, $uid, $who);

            if (!recalculateBookingFinancials($bookingId)) {
                throw new Exception('Could not recalculate the booking balance - nothing was changed.');
            }
            $lock->execute([$bookingId]);
            $a = $lock->fetch(PDO::FETCH_ASSOC) ?: [];
            // Never leave paid money sitting on the room as credit (L1): the money-back legs must have absorbed it.
            if ((float)($a['credit_balance'] ?? 0) > $tol + max(0.0, (float)($b['credit_balance'] ?? 0))) {
                throw new Exception('The money going back did not balance. Nothing was changed.');
            }
            $fsA = getBookingFolioSummary($bookingId);
            $after = [
                'check_in_date' => (string)($a['check_in_date'] ?? $q['new_check_in']), 'check_out_date' => (string)($a['check_out_date'] ?? $q['new_check_out']),
                'number_of_nights' => (int)($a['number_of_nights'] ?? $q['new_nights']), 'room_total' => (float)$q['new_room_total'],
                'bill' => round((float)($fsA['grand_total'] ?? $q['new_bill']), 2), 'amount_paid' => round((float)($fsA['amount_paid'] ?? 0), 2),
                'amount_due' => round((float)($fsA['balance_due'] ?? 0), 2),
            ];
            if ($mb['refund_total'] > $tol) {
                $describe .= ' Refunded ' . rh_stay_correction_money($mb['refund_total'], $cur) . ' to the original payment(s).';
            }
            foreach ($mb['credit_notes'] as $cnOne) {
                $describe .= ' ' . rh_stay_correction_money($cnOne['amount'], $cur) . ' moved to credit note ' . $cnOne['number']
                    . ($cnOne['payer'] === 'company' ? ' (made out to ' . $cnOne['name'] . ', which paid it)' : '') . '.';
            }
            if ($q['clashes']) {
                $describe .= ' Saved although the room had another booking on the added night(s): '
                    . implode(', ', array_map(static function ($c) { return $c['ref']; }, $q['clashes'])) . '.';
            }
            if ($q['invoices']) {
                $describe .= ' Invoice ' . implode(', ', $q['invoices']) . ' keeps its number and shows the new figures.';
            }
            $describe .= ' Reason: ' . $reason;

            // Date-adjustment log (the same table as Adjust Stay Dates), so the booking's adjustment history shows it.
            $adjustmentId = 0;
            try {
                $pdo->prepare("INSERT INTO booking_date_adjustments (booking_id, booking_reference, old_check_in_date, new_check_in_date,
                        old_check_out_date, new_check_out_date, old_number_of_nights, new_number_of_nights, old_total_amount, new_total_amount,
                        amount_delta, adjustment_reason, adjusted_by, adjusted_by_name, ip_address, metadata)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$bookingId, $b['booking_reference'], $q['check_in'], $q['new_check_in'], $q['check_out'], $q['new_check_out'],
                        (int)$q['old_nights'], (int)$q['new_nights'], (float)$q['old_room_total'], (float)$q['new_room_total'], (float)$q['room_delta'],
                        $reason, $uid ?: null, $who !== '' ? $who : null, $_SERVER['REMOTE_ADDR'] ?? null,
                        json_encode(['after_checkout' => true, 'per_night' => $q['per_night'], 'extra_nights' => $q['extra_nights'],
                            'extra_rate' => $q['extra_rate'], 'rate_changed' => $q['rate_changed'], 'rate_reason' => $rateReason ?: null,
                            'money_back' => $q['money_back'], 'money_back_mode' => $mode ?: null, 'old_bill' => $q['old_bill'], 'new_bill' => $q['new_bill'],
                            'clashes' => array_column($q['clashes'], 'ref')])]);
                $adjustmentId = (int)$pdo->lastInsertId();
            } catch (PDOException $e) {
                error_log('rh_stay_correction_apply date log: ' . $e->getMessage()); // History below still records it
            }

            $meta = [
                'after_checkout' => true, 'adjustment_id' => $adjustmentId, 'old' => $before, 'new' => $after, 'per_night' => $q['per_night'],
                'extra_nights' => $q['extra_nights'], 'extra_rate' => $q['extra_rate'], 'rate_changed' => $q['rate_changed'], 'rate_reason' => $rateReason ?: null,
                'money_back' => $q['money_back'], 'money_back_mode' => $mode, 'refunded' => $mb['refund_total'],
                'credit_note' => implode(', ', array_column($mb['credit_notes'], 'number')) ?: null, 'reason' => $reason,
                'clashes' => array_column($q['clashes'], 'ref'), 'invoices' => $q['invoices'],
            ];
            if (function_exists('logBookingEvent')) {
                logBookingEvent($bookingId, (string)$b['booking_reference'], 'Stay corrected after check-out', 'date_adjustment', $describe,
                    json_encode(['check_in' => $q['check_in'], 'check_out' => $q['check_out'], 'nights' => $q['old_nights'], 'bill' => $q['old_bill'], 'owed' => $q['old_due']]),
                    json_encode(['check_in' => $q['new_check_in'], 'check_out' => $q['new_check_out'], 'nights' => $q['new_nights'], 'bill' => $after['bill'], 'owed' => $after['amount_due']]),
                    'admin', $uid ?: null, $who !== '' ? $who : null, $meta);
            }
            if (function_exists('logBookingAudit')) {
                logBookingAudit($bookingId, 'stay_corrected_after_checkout', $before, $after + ['reason' => $reason, 'money_back_mode' => $mode], $describe, (string)$b['booking_reference']);
            }
            if ($ownTx) {
                $pdo->commit();
                if (function_exists('rh_payment_audit_flush')) {
                    rh_payment_audit_flush($pdo);
                }
            }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e instanceof Exception ? $e : new Exception($e->getMessage());
        }

        if (function_exists('rh_log_event')) {
            rh_log_event('bookings', 'info', 'Stay corrected after check-out', [
                'booking_id' => $bookingId, 'ref' => $b['booking_reference'], 'old' => $q['check_in'] . ' to ' . $q['check_out'],
                'new' => $q['new_check_in'] . ' to ' . $q['new_check_out'], 'old_bill' => $q['old_bill'], 'new_bill' => $q['new_bill'],
                'money_back' => $q['money_back'], 'mode' => $mode, 'refunded' => $mb['refund_total'], 'by' => $user['username'] ?? null,
            ]);
        }
        if (!empty($q['drop']['removed']) && function_exists('rh_rate_plan_drop_log')) {
            rh_rate_plan_drop_log($bookingId, $b['booking_reference'] ?? null, $q['drop'], (float)rh_booked_stay_split($b)['per_night'],
                (float)$q['per_night'], (float)$q['old_room_total'], (float)$q['new_room_total'], $who);
        }
        return ['quote' => $q, 'describe' => $describe, 'refund_total' => $mb['refund_total'], 'refund_legs' => $mb['refund_legs'],
            'credit_notes' => $mb['credit_notes'], 'after' => $after, 'adjustment_id' => $adjustmentId];
    }

    /**
     * Give $amount back on a room booking: a refund to the original payment(s) in full, or a credit note (split by who
     * paid: company / guest). Same posting as includes/stay-nights.php (owner D4 2026-11-13). Inside the caller's
     * transaction; throws when the full amount cannot be placed. $mode '' or $amount <= tolerance does nothing.
     *
     * @return array{refund_total:float,refund_legs:array,credit_notes:array}
     */
    function rh_stay_correction_money_back(PDO $pdo, array $b, float $amount, string $mode, string $note, int $uid, string $who): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $cur = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
        $bookingId = (int)$b['id'];
        $res = ['refund_total' => 0.0, 'refund_legs' => [], 'credit_notes' => []];
        if ($mode === '' || $amount <= $tol) {
            return $res;
        }
        if ($mode === 'refund') {
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
            foreach (allocateBookingRefunds($pdo, $bookingId, $amount, true) as $alloc) {
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
                    $leg, mb_substr($note, 0, 1000), $uid ?: null,
                ]);
                rh_refresh_original_payment_status($pdo, (int)$orig['id']);
                if (function_exists('rh_payment_audit_defer')) {
                    rh_payment_audit_defer(['payment_id' => (int)$orig['id'], 'verify_ref' => $legRef, 'action' => 'refund_recorded',
                        'user_id' => $uid, 'who' => $who !== '' ? $who : 'System',
                        'note' => 'Refund ' . $legRef . ' of ' . number_format($leg, 2) . ' (stay corrected after check-out, completed). ' . $note]);
                }
                $res['refund_total'] = round($res['refund_total'] + $leg, 2);
                $res['refund_legs'][] = ['orig' => array_merge($orig, ['booking_type' => 'room', 'booking_id' => $bookingId, 'booking_reference' => $b['booking_reference']]),
                    'ref' => $legRef, 'amount' => $leg, 'reason' => $note, 'payer' => (string)($alloc['payer'] ?? 'guest')];
            }
            if ($res['refund_total'] + $tol < $amount) {
                throw new Exception('Could not refund the full ' . rh_stay_correction_money($amount, $cur) . ' against the payments on this booking. Nothing was changed.');
            }
            return $res;
        }
        if ($mode !== 'credit_note') {
            throw new Exception('Choose refund or credit note.');
        }
        $vatOn = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
        $groups = [];
        foreach (allocateBookingRefunds($pdo, $bookingId, $amount, true, 'any') as $al) {
            $groups[(string)($al['payer'] ?? 'guest') === 'company' ? 'company' : 'guest'][] = $al;
        }
        $cnBill = function_exists('rh_booking_billing') ? rh_booking_billing($pdo, $b) : ['on_account' => false];
        foreach (['company', 'guest'] as $payer) {
            if (empty($groups[$payer])) {
                continue;
            }
            $part = round(array_sum(array_column($groups[$payer], 'amount')), 2);
            $isCo = $payer === 'company' && !empty($cnBill['on_account']);
            $toName = $isCo ? (string)$cnBill['name'] : (trim((string)$b['guest_name']) !== '' ? (string)$b['guest_name'] : 'Guest');
            $toEmail = $isCo ? (string)$cnBill['billing_email'] : (string)($b['guest_email'] ?? '');
            $split = rh_account_vat_split($pdo, 'room', $bookingId, $part, $vatOn ? (float)getSetting('vat_rate') : 0.0);
            $cn = issueCreditNote($pdo, [
                'booking_type' => 'room', 'booking_id' => $bookingId, 'booking_reference' => (string)$b['booking_reference'],
                'guest_name' => $toName, 'guest_email' => $toEmail,
                'amount' => $part, 'vat_rate' => (float)$split['rate'], 'vat_amount' => (float)$split['vat'],
                'reason' => 'early_checkout',
                'reason_notes' => mb_substr(($isCo ? 'Company payment returned (guest: ' . (string)$b['guest_name']
                    . (($cnBill['reference'] ?? '') !== '' ? ', LPO ' . $cnBill['reference'] : '') . '). ' : '') . $note, 0, 1000),
                'issued_by' => $uid > 0 ? $uid : (int)$pdo->query('SELECT MIN(id) FROM admin_users')->fetchColumn(),
                'send_email' => false, 'generate_pdf' => false,
            ]);
            if (empty($cn['success'])) {
                throw new Exception('The credit note could not be created: ' . ($cn['error'] ?? 'unknown error') . '. Nothing was changed.');
            }
            $legs = recordCreditNoteRefundLegs($pdo, $bookingId, (string)$b['booking_reference'], $part,
                (int)$cn['credit_note_id'], (string)$cn['credit_note_number'], $uid, 'early_checkout', 'room', $groups[$payer]);
            if ($legs['total'] + $tol < $part) {
                throw new Exception('Could not move the full amount into the credit note. Nothing was changed.');
            }
            $res['credit_notes'][] = ['id' => (int)$cn['credit_note_id'], 'number' => (string)$cn['credit_note_number'], 'amount' => $part,
                'email' => trim($toEmail), 'payer' => $isCo ? 'company' : 'guest', 'name' => $toName];
        }
        if (round(array_sum(array_column($res['credit_notes'], 'amount')), 2) + $tol < $amount) {
            throw new Exception('Could not move the full amount into the credit note. Nothing was changed.');
        }
        return $res;
    }

    /**
     * After the save (outside the transaction): rebuild the room's issued invoice PDF(s) under their SAME numbers so
     * they show the corrected figures (invoice.php reuses a cached PDF otherwise), and the booking's / its group lead's
     * final invoice or statement. Mirrors admin/includes/payment-edit.php. Never emails. Returns notice text.
     */
    function rh_stay_correction_refresh_invoices(PDO $pdo, int $bookingId): string
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $notice = '';
        try {
            if (!function_exists('generateInvoicePDF')) {
                require_once __DIR__ . '/../config/invoice.php';
            }
            // Every cached room-invoice file of the WHOLE group (QA 2026-11-17): a group has ONE invoice number held on one
            // payment row only, while the other rooms' rows keep just invoice_path - and invoice.php serves that cached
            // file. One rebuild per file, under its own number (the stored number, else the file name, as invoice.php
            // reads it), from a room that points at it; every row on the old path is moved to the new one.
            $gIds = rh_stay_correction_group_ids($pdo, $bookingId);
            $ph = implode(',', array_fill(0, count($gIds), '?'));
            $st = $pdo->prepare("SELECT invoice_path, MAX(NULLIF(TRIM(invoice_number), '')) AS no,
                    MAX(booking_id = ?) AS has_self, MIN(booking_id) AS any_id
                FROM payments WHERE booking_type = 'room' AND booking_id IN ($ph) AND deleted_at IS NULL
                AND COALESCE(payment_type, '') <> 'refund'
                AND invoice_generated = 1 AND invoice_path IS NOT NULL AND invoice_path <> ''
                GROUP BY invoice_path");
            $st->execute(array_merge([$bookingId], $gIds));
            $done = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $oldPath = (string)$r['invoice_path'];
                $no = trim((string)($r['no'] ?? ''));
                if ($no === '') {
                    $no = (string)pathinfo($oldPath, PATHINFO_FILENAME);
                }
                if ($no === '' || isset($done[$no])) {
                    continue;
                }
                $done[$no] = true;
                $srcId = (int)$r['has_self'] === 1 ? $bookingId : (int)$r['any_id'];
                $g = generateInvoicePDF($srcId, $no);
                if ($g) {
                    $pdo->prepare("UPDATE payments SET invoice_path = ? WHERE booking_type = 'room' AND booking_id IN ($ph) AND invoice_path = ? AND invoice_generated = 1")
                        ->execute(array_merge([$g['relative_path']], $gIds, [$oldPath]));
                    $notice .= ' Invoice ' . $no . ' updated (same number).';
                }
            }
            $rs = $pdo->prepare("SELECT id, primary_booking_id FROM bookings WHERE id = ?");
            $rs->execute([$bookingId]);
            $row = $rs->fetch(PDO::FETCH_ASSOC) ?: [];
            $docIds = array_values(array_unique(array_filter([$bookingId, (int)($row['primary_booking_id'] ?? 0)])));
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
                $g = generateInvoicePDF($docId, $fNo, $groupDue <= $tol);
                if ($g) {
                    $pdo->prepare("UPDATE bookings SET final_invoice_path = ? WHERE id = ?")->execute([$g['relative_path'], $docId]);
                    $notice .= ' The ' . ($groupDue <= $tol ? 'statement' : 'final invoice') . ' ' . $fNo . ' was updated (not emailed).';
                }
            }
        } catch (Throwable $e) {
            error_log('rh_stay_correction_refresh_invoices: ' . $e->getMessage());
            $notice .= ' The invoice could not be rebuilt (' . $e->getMessage() . '). Use Regenerate on the Invoices page.';
        }
        return $notice;
    }
}
