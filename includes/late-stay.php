<?php
/**
 * Late check-in / late (or early) check-out: front desk says what happened, nothing is assumed
 * (owner 2026-11-12: "Dont assume, have options").
 *
 * ONE place turns (booking, the day the guest left, the basis) into nights and money, so the checkout
 * settlement's quote (bookings.php checkout_assess) and its charge / refund (checkout_settle) are always
 * the same figure. Everything is at the BOOKED nightly rate (rh_reprice_at_booked_rate()).
 *
 *  - basis 'booked': the stay runs from the booked check-in to the leave date. Leaving on the booked
 *    check-out date = no change at all (no reprice, no money). Later = extra nights after the booked
 *    check-out; earlier = unused nights (refund / keep path).
 *  - basis 'actual' (owner decision 2026-11-12, only when the check-in was recorded as "Guest is arriving
 *    today" on a day after the booked check-in): charge only the nights actually stayed, actual arrival ->
 *    leave date. The stay is repriced to those nights; any unused booked amount goes the early-checkout
 *    refund way, shown in full.
 *
 * Pure functions here never touch the database except rh_late_stay_actual_arrival() (read only) and
 * rh_record_completed_stay() (the "already stayed and left" check-in, one transaction).
 */

if (!function_exists('rh_late_stay_ymd')) {

    /** A Y-m-d string for a date value, or null when it is not a real date. */
    function rh_late_stay_ymd($value): ?string
    {
        $s = substr(trim((string)$value), 0, 10);
        $d = DateTime::createFromFormat('!Y-m-d', $s);
        return ($d && $d->format('Y-m-d') === $s) ? $s : null;
    }

    /** Whole days from $from to $to (negative when $to is earlier). Both Y-m-d. */
    function rh_late_stay_days(string $from, string $to): int
    {
        $a = new DateTime($from);
        $b = new DateTime($to);
        $diff = $a->diff($b);
        return $diff->invert ? -(int)$diff->days : (int)$diff->days;
    }

    /** "12 Nov 2026" for a Y-m-d date ('' when empty). */
    function rh_late_stay_fmt(?string $ymd): string
    {
        $ymd = rh_late_stay_ymd((string)$ymd);
        return $ymd === null ? '' : date('d M Y', strtotime($ymd));
    }

    /**
     * How late an arrival is, and whether the booked stay is already over.
     * late_days = days the booked check-in is behind today (0 when on time / future);
     * stay_ended = the booked check-out date is today or earlier (every booked night has passed).
     *
     * @return array{late_days:int,stay_ended:bool,check_in:?string,check_out:?string,today:string}
     */
    function rh_late_stay_window(array $b, ?string $today = null): array
    {
        $today = rh_late_stay_ymd((string)$today) ?? date('Y-m-d');
        $in = rh_late_stay_ymd($b['check_in_date'] ?? '');
        $out = rh_late_stay_ymd($b['check_out_date'] ?? '');
        $late = $in !== null ? max(0, rh_late_stay_days($in, $today)) : 0;
        return [
            'late_days' => $late,
            'stay_ended' => $out !== null && $out <= $today,
            'check_in' => $in,
            'check_out' => $out,
            'today' => $today,
        ];
    }

    /**
     * The late check-in choices front desk may pick for this booking (nothing preselected):
     * 'booked_date' = guest arrived on the booked date, recorded late; 'today' = guest is arriving today;
     * 'ended' = guest already stayed and has left (only when the booked stay is over).
     */
    function rh_late_checkin_choices(array $window): array
    {
        if ((int)$window['late_days'] <= 0) {
            return [];
        }
        $c = ['booked_date', 'today'];
        if (!empty($window['stay_ended'])) {
            $c[] = 'ended';
        }
        return $c;
    }

    /**
     * Server check of the posted late check-in choice. Not late = no choice needed ('' returned).
     * Late = one of rh_late_checkin_choices() is required; anything else is refused.
     *
     * @return array{ok:bool,choice:string,error:string}
     */
    function rh_late_checkin_choice(array $window, $posted): array
    {
        $allowed = rh_late_checkin_choices($window);
        if (!$allowed) {
            return ['ok' => true, 'choice' => '', 'error' => ''];
        }
        $choice = is_string($posted) ? trim($posted) : '';
        if (!in_array($choice, $allowed, true)) {
            return ['ok' => false, 'choice' => '', 'error' => 'This arrival is ' . (int)$window['late_days']
                . ' day(s) late. Choose what happened (arrived on the booked date, arriving today'
                . (in_array('ended', $allowed, true) ? ', or already stayed and left' : '') . ') before checking in.'];
        }
        return ['ok' => true, 'choice' => $choice, 'error' => ''];
    }

    /** The arrival date a late check-in choice records (booked check-in or today). */
    function rh_late_checkin_arrival(array $window, string $choice): ?string
    {
        if ($choice === 'today') {
            return $window['today'];
        }
        return $window['check_in'];
    }

    /** History / audit wording for a late check-in choice. */
    function rh_late_checkin_note(array $window, string $choice): string
    {
        $booked = rh_late_stay_fmt($window['check_in']);
        $today = rh_late_stay_fmt($window['today']);
        $late = (int)$window['late_days'];
        switch ($choice) {
            case 'booked_date':
                return "Late check-in: guest arrived on the booked date {$booked}, recorded on {$today} ({$late} day(s) late). Booked dates and total unchanged.";
            case 'today':
                return "Late check-in: guest is arriving today {$today} (booked arrival {$booked}, {$late} day(s) late). Booked dates and total unchanged.";
            case 'ended':
                return "Recorded a completed stay: the guest already stayed {$booked} to " . rh_late_stay_fmt($window['check_out'])
                    . " and has left (recorded on {$today}). Checked in and out together at the booked dates, no extra nights charged, no check-in email.";
        }
        return $late > 0 ? "Late check-in ({$late} day(s) overdue)" : '';
    }

    /**
     * Price the stay for the day the guest left. Pure: same input, same figures, no writes.
     *
     * @param string      $leaveDate Y-m-d, between the stay start and $today
     * @param string      $basis     'booked' | 'actual'
     * @param string|null $arrival   recorded actual arrival (Y-m-d) - needed for basis 'actual'
     * @return array ok, error, basis, leave_date, stay_in, booked_in, booked_out, booked_nights, nights,
     *               extra_nights, unused_nights, direction ('none'|'charge'|'refund'), amount (always >= 0),
     *               refundable, per_night, old_gross, new_gross, reprice (null = no change written),
     *               new_check_in, new_check_out
     */
    function rh_late_stay_quote(array $b, string $leaveDate, string $basis = 'booked', ?string $arrival = null, ?string $today = null): array
    {
        $fail = function (string $msg) { return ['ok' => false, 'error' => $msg]; };
        $today = rh_late_stay_ymd((string)$today) ?? date('Y-m-d');
        $bookedIn = rh_late_stay_ymd($b['check_in_date'] ?? '');
        $bookedOut = rh_late_stay_ymd($b['check_out_date'] ?? '');
        $leave = rh_late_stay_ymd($leaveDate);
        if ($bookedIn === null || $bookedOut === null) {
            return $fail('The booking has no valid stay dates.');
        }
        if ($leave === null) {
            return $fail('Choose the date the guest left.');
        }
        if (!in_array($basis, ['booked', 'actual'], true)) {
            return $fail('Unknown way of counting the nights.');
        }
        $stayIn = $bookedIn;
        if ($basis === 'actual') {
            $arrival = rh_late_stay_ymd((string)$arrival);
            if ($arrival === null || $arrival <= $bookedIn) {
                return $fail('"Charge only the nights actually stayed" needs an arrival recorded after the booked check-in.');
            }
            $stayIn = $arrival;
        }
        if ($leave > $today) {
            return $fail('The leave date cannot be after today (' . rh_late_stay_fmt($today) . ').');
        }
        if ($leave < $stayIn) {
            return $fail('The leave date cannot be before the guest\'s arrival (' . rh_late_stay_fmt($stayIn) . ').');
        }

        $bookedNights = max(1, (int)($b['number_of_nights'] ?? 0) ?: rh_late_stay_days($bookedIn, $bookedOut));
        $nights = max(1, rh_late_stay_days($stayIn, $leave)); // a same-day departure still bills one night
        $newOut = (new DateTime($stayIn))->modify('+' . $nights . ' day')->format('Y-m-d');
        $split = function_exists('rh_booked_stay_split') ? rh_booked_stay_split($b) : null;
        $oldGross = $split ? round((float)$split['total_with_vat'], 2) : round((float)($b['total_with_vat'] ?? 0), 2);
        $perNight = $split ? (float)$split['per_night'] : 0.0;

        $q = [
            'ok' => true, 'error' => '', 'basis' => $basis, 'leave_date' => $leave, 'today' => $today,
            'stay_in' => $stayIn, 'booked_in' => $bookedIn, 'booked_out' => $bookedOut,
            'booked_nights' => $bookedNights, 'nights' => $nights,
            'extra_nights' => 0, 'unused_nights' => 0,
            'direction' => 'none', 'amount' => 0.0, 'refundable' => 0.0,
            'per_night' => round($perNight, 2), 'old_gross' => $oldGross, 'new_gross' => $oldGross,
            'reprice' => null, 'new_check_in' => $bookedIn, 'new_check_out' => $bookedOut,
        ];

        // Left on the booked check-out date under the booked count: nothing changes, no money (never repriced:
        // the reprice clamps and rewrites totals).
        if ($basis === 'booked' && $leave === $bookedOut) {
            return $q;
        }
        if ($basis === 'booked' && $nights === $bookedNights) {
            return $q; // same-day departure on a one-night booking: still the booked night
        }

        $rp = rh_reprice_at_booked_rate($b, $nights);
        $delta = round((float)$rp['new_gross'] - (float)$rp['old_gross'], 2);
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $q['reprice'] = $rp;
        $q['per_night'] = round((float)$rp['per_night'], 2);
        $q['old_gross'] = round((float)$rp['old_gross'], 2);
        $q['new_gross'] = round((float)$rp['new_gross'], 2);
        $q['new_check_in'] = $stayIn;
        $q['new_check_out'] = $newOut;
        $q['extra_nights'] = max(0, $nights - $bookedNights);
        $q['unused_nights'] = max(0, $bookedNights - $nights);
        if ($basis === 'booked') {
            // Owner (A): booked nights stand; extra nights run from the booked check-out to the leave date.
            $q['extra_nights'] = max(0, rh_late_stay_days($bookedOut, $leave));
            $q['unused_nights'] = max(0, rh_late_stay_days($leave, $bookedOut));
        }
        if ($delta > $tol) {
            $q['direction'] = 'charge';
            $q['amount'] = $delta;
        } elseif ($delta < -$tol) {
            $q['direction'] = 'refund';
            $q['amount'] = round(-$delta, 2);
            $paid = (float)($b['amount_paid'] ?? 0);
            $folio = (float)($b['folio_charges_total'] ?? 0);
            $q['refundable'] = max(0.0, min($q['amount'], round($paid - ((float)$rp['new_gross'] + $folio), 2)));
        }
        return $q;
    }

    /**
     * Every leave date front desk may pick, with its quote for each basis: the booked check-out date
     * (when it has passed), today, or any other date from the stay start to today. Read only.
     *
     * @return array{min:string,max:string,dates:array<string,array>,has_actual:bool,arrival:?string}
     */
    function rh_late_stay_options(array $b, ?string $arrival, ?string $today = null): array
    {
        $today = rh_late_stay_ymd((string)$today) ?? date('Y-m-d');
        $bookedIn = rh_late_stay_ymd($b['check_in_date'] ?? '') ?? $today;
        $arrival = rh_late_stay_ymd((string)$arrival);
        $hasActual = $arrival !== null && $arrival > $bookedIn && $arrival <= $today;
        $min = $bookedIn;
        $floor = (new DateTime($today))->modify('-400 day')->format('Y-m-d'); // keeps the answer small
        if ($min < $floor) {
            $min = $floor;
        }
        $dates = [];
        for ($d = new DateTime($min); $d->format('Y-m-d') <= $today; $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $row = ['booked' => rh_late_stay_quote($b, $ymd, 'booked', null, $today)];
            if ($hasActual && $ymd >= $arrival) {
                $row['actual'] = rh_late_stay_quote($b, $ymd, 'actual', $arrival, $today);
            }
            $dates[$ymd] = $row;
        }
        return ['min' => $min, 'max' => $today, 'dates' => $dates, 'has_actual' => $hasActual, 'arrival' => $hasActual ? $arrival : null];
    }

    /** One plain History line for a checkout settlement choice (leave date, nights, amount). */
    function rh_late_stay_describe(array $q, string $currency): string
    {
        $m = function (float $v) use ($currency) { return $currency . ' ' . number_format($v, 2); };
        $leave = rh_late_stay_fmt($q['leave_date'] ?? '');
        $bookedOut = rh_late_stay_fmt($q['booked_out'] ?? '');
        $stay = rh_late_stay_fmt($q['new_check_in'] ?? '') . ' to ' . rh_late_stay_fmt($q['new_check_out'] ?? '')
            . ', ' . (int)($q['nights'] ?? 0) . ' night(s)';
        $basis = ($q['basis'] ?? 'booked') === 'actual'
            ? 'Charged only the nights actually stayed (arrived ' . rh_late_stay_fmt($q['stay_in'] ?? '') . '). '
            : '';
        $rate = ' at the booked rate ' . $m((float)($q['per_night'] ?? 0)) . ' a night';
        switch ($q['direction'] ?? 'none') {
            case 'charge':
                return $basis . "Guest left on {$leave} (booked check-out {$bookedOut}): " . (int)$q['extra_nights']
                    . ' night(s) more than booked' . $rate . ', ' . $m((float)$q['amount']) . " added to the bill. Stay now {$stay}.";
            case 'refund':
                return $basis . "Guest left on {$leave} (booked check-out {$bookedOut}): " . (int)$q['unused_nights']
                    . ' unused night(s)' . $rate . ', bill reduced by ' . $m((float)$q['amount'])
                    . ' (refundable ' . $m((float)$q['refundable']) . "). Stay now {$stay}.";
        }
        if (($q['basis'] ?? 'booked') === 'actual') {
            return $basis . "Guest left on {$leave}: same number of nights as booked, no money change. Stay now {$stay}.";
        }
        return "Guest left on the booked date {$leave}: no extra nights charged. Booked stay unchanged ("
            . rh_late_stay_fmt($q['booked_in'] ?? '') . ' to ' . $bookedOut . ', ' . (int)($q['booked_nights'] ?? 0) . ' night(s)).';
    }

    /**
     * The actual arrival date recorded by a late check-in ("Guest is arriving today"), or null when the
     * guest arrived on the booked date / no choice was recorded. Read from the latest check-in audit row.
     */
    function rh_late_stay_actual_arrival(PDO $pdo, int $bookingId, ?string $bookedIn): ?string
    {
        try {
            $st = $pdo->prepare("SELECT new_values FROM booking_audit_log WHERE booking_id = ? AND action = 'checked-in' ORDER BY id DESC LIMIT 1");
            $st->execute([$bookingId]);
            $nv = json_decode((string)$st->fetchColumn(), true);
        } catch (Throwable $e) {
            return null;
        }
        $a = is_array($nv) ? rh_late_stay_ymd($nv['actual_arrival'] ?? '') : null;
        $in = rh_late_stay_ymd((string)$bookedIn);
        return ($a !== null && $in !== null && $a > $in) ? $a : null;
    }

    /**
     * Housekeeping for a stay recorded after it ended ("already stayed and left", owner 2026-11-13): trust the room
     * status. Each room the booking held (booking_rooms - both rooms of a joined pair - plus the primary pointer):
     *  - held by another checked-in booking: never touched (that guest's room);
     *  - still 'occupied': checked out like any checkout - a "Guest has left" clean already done is reused
     *    (rh_checkout_early_clean_target / rh_checkout_apply_early_clean), otherwise 'cleaning' + one open clean task
     *    (rh_ensure_checkout_cleaning_task, never a duplicate);
     *  - any other status (available, cleaning, maintenance ...): kept exactly as it is, no task added, so the next
     *    arrival is never held up by an old stay.
     * Runs inside the caller's transaction. Returns room numbers per outcome for History.
     *
     * @return array{cleaning:string[],early:string[],kept:string[],in_use:string[]}
     */
    function rh_completed_stay_room_turnover(PDO $pdo, int $bookingId, array $roomIds, ?int $userId, string $bookingRef): array
    {
        $out = ['cleaning' => [], 'early' => [], 'kept' => [], 'in_use' => []];
        if (!function_exists('rh_checkout_early_clean_target')) {
            require_once __DIR__ . '/room-management.php';
        }
        $roomSt = $pdo->prepare("SELECT room_number, status FROM individual_rooms WHERE id = ?");
        $otherSt = $pdo->prepare("
            SELECT COUNT(*) FROM bookings b
            WHERE b.id <> ? AND b.status = 'checked-in'
              AND (b.individual_room_id = ? OR EXISTS (
                    SELECT 1 FROM booking_rooms br
                    WHERE br.booking_id = b.id AND br.individual_room_id = ? AND br.released_at IS NULL))
        ");
        $setSt = $pdo->prepare("UPDATE individual_rooms SET status = 'cleaning' WHERE id = ? AND status = 'occupied'");
        $logSt = $pdo->prepare("INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by) VALUES (?, 'occupied', 'cleaning', ?, ?)");
        foreach (array_unique(array_map('intval', $roomIds)) as $roomId) {
            if ($roomId <= 0) {
                continue;
            }
            $roomSt->execute([$roomId]);
            $room = $roomSt->fetch(PDO::FETCH_ASSOC);
            if (!$room) {
                continue;
            }
            $num = (string)$room['room_number'];
            $otherSt->execute([$bookingId, $roomId, $roomId]);
            if ((int)$otherSt->fetchColumn() > 0) {
                $out['in_use'][] = $num;
                continue;
            }
            if ((string)$room['status'] !== 'occupied') {
                $out['kept'][] = $num . ' (' . str_replace('_', ' ', (string)$room['status']) . ')';
                continue;
            }
            $early = rh_checkout_early_clean_target($pdo, $roomId, $bookingId);
            if ($early !== null) {
                $applied = rh_checkout_apply_early_clean($roomId, $early, $bookingRef, $userId);
                if (!empty($applied['success'])) {
                    $out['early'][] = $num;
                    continue;
                }
            }
            $setSt->execute([$roomId]);
            if ($setSt->rowCount() > 0) {
                $logSt->execute([$roomId, 'Completed stay recorded (guest already left): ' . $bookingRef, $userId]);
            }
            rh_ensure_checkout_cleaning_task($roomId, $bookingId, $userId);
            $out['cleaning'][] = $num;
        }
        return $out;
    }

    /** One History sentence for rh_completed_stay_room_turnover()'s result ('' when the booking had no room). */
    function rh_completed_stay_room_note(array $t): string
    {
        $parts = [];
        $list = function (array $nums) { return 'Room ' . implode(', ', $nums); };
        if (!empty($t['cleaning'])) {
            $parts[] = $list($t['cleaning']) . ' was still marked occupied: sent to cleaning, as at any checkout.';
        }
        if (!empty($t['early'])) {
            $parts[] = $list($t['early']) . ': the departure clean was already done, not cleaned twice.';
        }
        if (!empty($t['kept'])) {
            $parts[] = $list($t['kept']) . ': room status kept as it is, no cleaning task added.';
        }
        if (!empty($t['in_use'])) {
            $parts[] = $list($t['in_use']) . ': another guest is in the room now, room left as it is.';
        }
        return implode(' ', $parts);
    }

    /**
     * "Guest already stayed and has left": check in and check out together at the booked dates, no extra
     * charge, in ONE transaction (a balance block undoes the check-in too). Same steps as the gated checkout
     * (rh_checkout_with_gate): balance gate, checkout_completed_at, room-type stock back, room-service orders
     * on the folio closed, the booking stops holding its rooms. Rooms follow the checkout housekeeping rule
     * through rh_completed_stay_room_turnover() (a room another guest is using now is never touched).
     *
     * @return array{success:bool,message:string,needs_confirm:bool,override:bool,balance:float,rooms:array}
     */
    function rh_record_completed_stay(PDO $pdo, int $bookingId, ?int $userId, bool $confirmBalance, ?string $today = null): array
    {
        $out = ['success' => false, 'message' => '', 'needs_confirm' => false, 'override' => false, 'balance' => 0.0, 'rooms' => []];
        $ownTx = !$pdo->inTransaction();
        try {
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            $st = $pdo->prepare("SELECT id, status, room_id, individual_room_id, booking_reference, check_in_date, check_out_date FROM bookings WHERE id = ? FOR UPDATE");
            $st->execute([$bookingId]);
            $bk = $st->fetch(PDO::FETCH_ASSOC);
            if (!$bk) {
                throw new RuntimeException('Booking not found.');
            }
            if ($bk['status'] !== 'confirmed') {
                throw new RuntimeException('Only a confirmed booking can be recorded as a completed stay (current: ' . $bk['status'] . ').');
            }
            $w = rh_late_stay_window($bk, $today);
            if (!$w['stay_ended'] || $w['late_days'] <= 0) {
                throw new RuntimeException('The booked stay has not ended yet, so it cannot be recorded as already completed.');
            }
            $pdo->prepare("UPDATE bookings SET status = 'checked-in', updated_at = NOW() WHERE id = ?")->execute([$bookingId]);
            $gate = evaluateCheckoutBalance($pdo, $bookingId, $userId, $confirmBalance);
            $out['balance'] = (float)$gate['balance'];
            if (!$gate['allowed']) {
                if ($ownTx) {
                    $pdo->rollBack();
                } else {
                    // Inside the caller's transaction: undo the check-in ourselves (the caller may commit).
                    $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ? AND status = 'checked-in'")->execute([$bookingId]);
                }
                $out['message'] = $gate['message'];
                $out['needs_confirm'] = !empty($gate['needs_confirm']);
                return $out;
            }
            $pdo->prepare("UPDATE bookings SET status = 'checked-out', checkout_completed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$bookingId]);
            $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")->execute([$bk['room_id']]);
            if (!function_exists('closeFolioChargedRoomServiceOrders')) {
                require_once __DIR__ . '/room-management.php';
            }
            closeFolioChargedRoomServiceOrders($pdo, $bookingId);
            // Every room the booking holds (read before the holds are released), then the checkout housekeeping rule.
            $roomIds = getBookingRoomIds($bookingId);
            if ((int)($bk['individual_room_id'] ?? 0) > 0) {
                $roomIds[] = (int)$bk['individual_room_id'];
            }
            rh_release_booking_room_holds($pdo, $bookingId);
            $out['rooms'] = rh_completed_stay_room_turnover($pdo, $bookingId, $roomIds, $userId, (string)$bk['booking_reference']);
            if ($ownTx) {
                $pdo->commit();
            }
            $out['success'] = true;
            $out['override'] = !empty($gate['override']);
            return $out;
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['message'] = $e->getMessage();
            return $out;
        }
    }
}
