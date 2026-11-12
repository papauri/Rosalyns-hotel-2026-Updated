<?php
/**
 * Front-desk count rules shared by the Dashboard, the Calendar and the End of Day report/PDF/e-mail, so each number
 * counts exactly what its link opens. Pure (no database, no settings). Unit tested by scripts/tests/dashboard_counts.php.
 *
 * - Still to arrive (= Bookings "Today's Check-ins"): arrival date today, status confirmed or pending.
 * - Expected arrivals (End of Day, owner ASSUMPTION 2026-11-11): confirmed + pending + already checked in; tentative
 *   holds are NOT counted and are shown on their own line ("Tentative holds (not counted)") when there are any.
 * - Missed arrival (Calendar, Room Dashboard no-show count): confirmed or pending whose arrival date has passed.
 * - Arrived today (Dashboard Arrivals KPI + Today's Check-ins popup): arrival date today and checked in, INCLUDING
 *   guests who already checked out the same day (rh_fd_arrived_statuses).
 * - Departures with a balance (Dashboard chip -> Bookings "Today's Check-outs" + ?balance=owing): due out today,
 *   still in, owing. Overdue guests have their own "Overdue departures" chip. Balances use BALANCE_TOLERANCE
 *   (rh_fd_balance_tolerance), the same value the Bookings ?balance=owing filter binds.
 * - Room-service orders open (chip -> Orders & Tabs ?order_type=room_service&status=placed,pending,confirmed, no date
 *   window): rh_fd_room_service_open_statuses(), the count and the link built from the same list.
 * - Room-service reminders due: occupied rooms with no room-service order served today (rh_fd_room_service_unserved_sql).
 * - Housekeeping tasks due (chip -> housekeeping.php?due=now): pending / in progress, due today or earlier or undated.
 * - Rooms out of service (chip -> room-maintenance.php?status=out_of_service): maintenance or out of order.
 */

if (!function_exists('rh_fd_still_to_arrive_statuses')) {
    function rh_fd_still_to_arrive_statuses(): array
    {
        return ['confirmed', 'pending'];
    }

    /** Arrived today = checked in, including guests who already checked out the same day. */
    function rh_fd_arrived_statuses(): array
    {
        return ['checked-in', 'checked-out'];
    }

    /**
     * "col IN ('a','b')" from one of the fixed status lists above (never user input; each value is checked).
     * The Bookings tab badges embed their clauses the same way.
     */
    function rh_fd_status_in_sql(string $col, array $statuses): string
    {
        $vals = [];
        foreach ($statuses as $st) {
            if (!preg_match('/^[a-z_-]{1,30}$/', (string)$st)) {
                throw new InvalidArgumentException('Bad status literal');
            }
            $vals[] = "'" . $st . "'";
        }
        return $col . ' IN (' . ($vals ? implode(',', $vals) : "''") . ')';
    }

    /** The balance tolerance every "owing" check here and in the Bookings ?balance=owing filter uses. */
    function rh_fd_balance_tolerance(): float
    {
        return defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
    }

    /** Room-service orders the "open" chip counts; its Orders & Tabs link filters on exactly this list. */
    function rh_fd_room_service_open_statuses(): array
    {
        return ['placed', 'pending', 'confirmed'];
    }

    /** Orders & Tabs link that opens every room-service order the chip counts (all dates, like the count). */
    function rh_fd_room_service_open_href(): string
    {
        return 'stock-orders.php?order_type=room_service&status=' . implode(',', rh_fd_room_service_open_statuses());
    }

    /**
     * Room-service reminder: NOT EXISTS an order for this stay (booking, or the room when the order has no booking)
     * that was completed/paid or served today. $b = the bookings alias (rooms joined as individual_room_id).
     */
    function rh_fd_room_service_unserved_sql(string $b = 'b'): string
    {
        return "NOT EXISTS (SELECT 1 FROM stock_orders rso WHERE rso.order_type = 'room_service'"
            . " AND (rso.booking_id = $b.id OR (rso.booking_id IS NULL AND rso.individual_room_id = $b.individual_room_id))"
            . " AND (rso.status IN ('completed', 'paid') OR rso.kitchen_status = 'served')"
            . " AND DATE(COALESCE(rso.served_at, rso.updated_at, rso.created_at)) = CURDATE())";
    }

    /** Housekeeping tasks due: pending / in progress, due today or earlier, or with no due date. $a = alias prefix. */
    function rh_fd_housekeeping_due_sql(string $a = ''): string
    {
        $p = $a !== '' ? $a . '.' : '';
        return "{$p}status IN ('pending','in_progress') AND ({$p}due_date IS NULL OR {$p}due_date <= CURDATE())";
    }

    /** Room statuses the "Rooms out of service" chip counts. */
    function rh_fd_out_of_service_statuses(): array
    {
        return ['maintenance', 'out_of_order'];
    }

    function rh_fd_is_still_to_arrive(array $b, string $today): bool
    {
        return (string)($b['check_in_date'] ?? '') === $today
            && in_array(strtolower((string)($b['status'] ?? '')), rh_fd_still_to_arrive_statuses(), true);
    }

    /** How many rows are still to arrive today (rows: check_in_date, status). */
    function rh_fd_count_still_to_arrive(array $rows, string $today): int
    {
        $n = 0;
        foreach ($rows as $r) {
            if (rh_fd_is_still_to_arrive($r, $today)) $n++;
        }
        return $n;
    }

    /** A confirmed/pending booking whose arrival date has passed without a check-in. */
    function rh_fd_is_missed_arrival(string $status, string $checkInDate, string $today): bool
    {
        return in_array(strtolower($status), rh_fd_still_to_arrive_statuses(), true) && $checkInDate !== '' && $checkInDate < $today;
    }

    /**
     * Departures with a balance, matching Bookings "Today's Check-outs": departure_state 'due' (checked in, due out
     * today) and amount_due above the balance tolerance. Overdue and departed rows are not counted here.
     */
    function rh_fd_departures_owing(array $departureList): int
    {
        $n = 0;
        foreach ($departureList as $d) {
            if (rh_fd_departure_owes($d)) $n++;
        }
        return $n;
    }

    /** One departure row counted by "Departures with a balance": due out today and owing above the tolerance. */
    function rh_fd_departure_owes(array $d): bool
    {
        return (string)($d['departure_state'] ?? '') === 'due' && rh_fd_departure_has_balance($d);
    }

    /** Still in (due today or overdue) and owing above the tolerance: never shown as "Settled". */
    function rh_fd_departure_has_balance(array $d): bool
    {
        return (string)($d['departure_state'] ?? '') !== 'departed' && (float)($d['amount_due'] ?? 0) > rh_fd_balance_tolerance();
    }

    /**
     * Dashboard "Today's Check-ins" popup subtitle. $stillToArrive / $arrived come from COUNT queries (not the
     * capped row list); $shown is how many rows the popup lists.
     */
    function rh_fd_checkins_subtitle(int $stillToArrive, int $arrived, int $shown): string
    {
        $s = 'Everyone due in today, with their status: ' . $stillToArrive . ' still to arrive, ' . $arrived . ' already arrived';
        if ($shown < $stillToArrive + $arrived) {
            $s .= ' (showing first ' . $shown . ')';
        }
        return $s;
    }

    /** End of Day: '' or "Tentative holds (not counted): N". */
    function rh_fd_tentative_note(array $ops): string
    {
        $n = (int)($ops['tentative_arrivals'] ?? 0);
        return $n > 0 ? 'Tentative holds (not counted): ' . $n : '';
    }
}

if (!function_exists('rh_fd_rooms_booked_by_night')) {
    /**
     * Dashboard Rooms panel (owner fault 2026-11-12: "16 ready to sell" while tomorrow was full): how many room
     * units are booked on each night. A night is held when check_in <= night < check_out (the checkout day is free;
     * callers pass the effective checkout so an overdue in-house guest still holds tonight).
     *
     * $unitKeyByRoom: room id => unit key (a joined-only pair's two rooms share one key, so the pair counts once).
     * $assigned: rows ['room_id', 'ci', 'co'] for every room a live booking holds (primary pointer AND booking_rooms).
     * $unassigned: rows ['booking_id', 'ci', 'co', 'units'] for live bookings with no room yet; 'units' is how many
     *              counted units the booking will take (1, or 2 for a joined booking whose rooms are counted apart).
     * Returns night => ['booked' => int (capped at $totalUnits when > 0), 'unassigned' => bookings with no room yet].
     */
    function rh_fd_rooms_booked_by_night(array $unitKeyByRoom, array $assigned, array $unassigned, array $nights, int $totalUnits): array
    {
        $out = [];
        foreach ($nights as $night) {
            $night = (string)$night;
            $keys = [];
            foreach ($assigned as $a) {
                $rid = (int)($a['room_id'] ?? 0);
                if ($rid <= 0 || !((string)$a['ci'] <= $night && $night < (string)$a['co'])) {
                    continue;
                }
                $keys[(string)($unitKeyByRoom[$rid] ?? ('r' . $rid))] = true;
            }
            $noRoom = [];
            $noRoomUnits = 0;
            foreach ($unassigned as $u) {
                $bid = (int)($u['booking_id'] ?? 0);
                if (isset($noRoom[$bid]) || !((string)$u['ci'] <= $night && $night < (string)$u['co'])) {
                    continue;
                }
                $noRoom[$bid] = true;
                $noRoomUnits += max(1, (int)($u['units'] ?? 1));
            }
            $booked = count($keys) + $noRoomUnits;
            if ($totalUnits > 0) {
                $booked = min($booked, $totalUnits);
            }
            $out[$night] = ['booked' => $booked, 'unassigned' => count($noRoom)];
        }
        return $out;
    }

    /**
     * Per room: the guest in it now (checked-in, kept on check-out day and when overdue, latest arrival wins) and
     * its next booking (the earliest live booking that is not checked in and has not ended). Rows: ['room_id', 'booking_id', 'status', 'ci', 'co', ...any other fields];
     * 'co' is the effective checkout. Returns room id => ['current' => row|null, 'next' => row|null].
     */
    function rh_fd_room_booking_slots(array $rows, string $today): array
    {
        usort($rows, static function ($x, $y) {
            return [(string)$x['ci'], (int)$x['booking_id']] <=> [(string)$y['ci'], (int)$y['booking_id']];
        });
        $out = [];
        foreach ($rows as $r) {
            $rid = (int)($r['room_id'] ?? 0);
            $in = (string)$r['status'] === 'checked-in';
            if ($rid <= 0 || (!$in && (string)$r['co'] <= $today)) {
                continue;
            }
            $out[$rid] = $out[$rid] ?? ['current' => null, 'next' => null];
            if ($in) {
                $out[$rid]['current'] = $r; // rows run by arrival date: the latest checked-in stay is the one in the room
            } elseif ($out[$rid]['next'] === null) {
                $out[$rid]['next'] = $r;
            }
        }
        return $out;
    }

    /** 'paid' (nothing left within BALANCE_TOLERANCE), 'partial' (something paid, something left) or 'unpaid'. */
    function rh_fd_payment_state($amountDue, $amountPaid): string
    {
        $tol = rh_fd_balance_tolerance();
        if ((float)$amountDue <= $tol) {
            return 'paid';
        }
        return (float)$amountPaid > $tol ? 'partial' : 'unpaid';
    }

    /** Rooms panel / room insight line: "Tonight: 3 free of 17 · Tomorrow: 16 of 17 booked (2 with no room yet)". */
    function rh_fd_rooms_outlook_line(int $units, int $freeTonight, int $bookedTomorrow, int $noRoomTomorrow): string
    {
        $s = 'Tonight: ' . $freeTonight . ' free of ' . $units . ' · Tomorrow: ' . $bookedTomorrow . ' of ' . $units . ' booked';
        if ($noRoomTomorrow > 0) {
            $s .= ' (' . $noRoomTomorrow . ' with no room yet)';
        }
        return $s;
    }
}
