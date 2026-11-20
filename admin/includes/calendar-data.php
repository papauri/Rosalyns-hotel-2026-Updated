<?php
/**
 * Calendar data: ONE source for the Month view (admin/calendar.php?view=month), the Room Timeline (first paint in
 * admin/includes/calendar-timeline-view.php) and its range endpoint (admin/api/calendar-timeline.php).
 *
 * Read-only (SELECTs only). Range based: a first and a last night (inclusive). The rules it carries:
 *  - a joined-only pair is ONE lane on room A's row ($laneOfRoom maps every room to the lane it is drawn on);
 *  - every room a booking holds comes from booking_rooms (released_at IS NULL) plus the primary pointer;
 *  - a booking with no room yet sits on its room type's "Unassigned" lane (rt_<type>);
 *  - an in-house guest past check-out keeps the room to tomorrow (rh_effective_checkout_sql);
 *  - only live bookings: not deleted, status pending / tentative / confirmed / checked-in;
 *  - the night model: a stay holds check-in .. check-out minus one night.
 *
 * The caller must already have config/database.php loaded ($pdo). Never include admin-init.php from here.
 */

require_once __DIR__ . '/../../includes/booking-pay-state.php';
require_once __DIR__ . '/front-desk-counts.php';

if (!function_exists('rh_cal_live_statuses')) {

    /** Booking statuses drawn on the calendar (cancelled, expired, no-show and checked-out are not). */
    function rh_cal_live_statuses(): array
    {
        return ['pending', 'tentative', 'confirmed', 'checked-in'];
    }

    /** Active individual rooms with their room type (the Month view's room list, same order). */
    function rh_cal_fetch_rooms(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT ir.*, r.name as room_type_name, r.price_per_night, r.slug as room_type_slug
            FROM individual_rooms ir
            INNER JOIN rooms r ON ir.room_type_id = r.id
            WHERE ir.is_active = 1 AND r.is_active = 1
            ORDER BY r.name, ir.display_order ASC, ir.room_number ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Pure: rooms + joined-only pairs -> lanes. Room B of a pair is drawn on room A's lane ("101A + 101B").
     * Returns ['rooms' => lane rows, 'laneOfRoom' => room id => lane id, 'laneRoomIds' => lane id => room ids,
     * 'floors' => sorted distinct floors].
     */
    function rh_cal_build_lanes(array $individualRooms, array $pairs): array
    {
        $laneOfRoom = [];
        $laneRoomIds = [];
        $pairsByA = [];
        foreach ($pairs as $jp) {
            $pairsByA[$jp['room_a_id']] = $jp;
        }
        $rowsById = [];
        foreach ($individualRooms as $irRow) {
            $rowsById[(int)$irRow['id']] = $irRow;
        }
        $lanes = [];
        foreach ($individualRooms as $irRow) {
            $rid = (int)$irRow['id'];
            $laneOfRoom[$rid] = $laneOfRoom[$rid] ?? $rid;
            $laneRoomIds[$laneOfRoom[$rid]] = $laneRoomIds[$laneOfRoom[$rid]] ?? [$rid];
            if (isset($pairsByA[$rid]) && isset($rowsById[$pairsByA[$rid]['room_b_id']])) {
                $jp = $pairsByA[$rid];
                $bRow = $rowsById[$jp['room_b_id']];
                $laneOfRoom[$jp['room_b_id']] = $rid;
                $laneRoomIds[$rid] = [$rid, $jp['room_b_id']];
                $irRow['room_number'] = $jp['label'];
                $irRow['room_name'] = (string)$jp['combined_name'];
                $irRow['status'] = rh_joined_pair_status((string)$irRow['status'], (string)$bRow['status']);
                $floorsPair = array_values(array_unique(array_filter([trim((string)($irRow['floor'] ?? '')), trim((string)($bRow['floor'] ?? ''))], static fn($v) => $v !== '')));
                $irRow['floor'] = implode(' / ', $floorsPair);
                if ($jp['price_override'] !== null && $jp['price_override'] !== '') {
                    $irRow['price_per_night'] = $jp['price_override'];
                }
                $irRow['is_joined_lane'] = true;
            }
            $lanes[$rid] = $irRow;
        }
        // Drop room B of each joined pair (it is drawn on room A's lane).
        $rooms = array_values(array_filter($lanes, static function ($row) use ($laneOfRoom) {
            return $laneOfRoom[(int)$row['id']] === (int)$row['id'];
        }));

        $floors = [];
        foreach ($rooms as $ir) {
            $f = trim($ir['floor'] ?? '');
            if ($f !== '' && !in_array($f, $floors, true)) {
                $floors[] = $f;
            }
        }
        natsort($floors);

        return ['rooms' => $rooms, 'laneOfRoom' => $laneOfRoom, 'laneRoomIds' => $laneRoomIds, 'floors' => array_values($floors)];
    }

    /** Pure: getBlockedDates() rows grouped by date; a block on a joined room is ONE block on its lane. */
    function rh_cal_group_blocks(array $blockedDates, array $laneOfRoom, array $laneRoomIds): array
    {
        $byDate = [];
        $blockLaneSeen = [];
        foreach ($blockedDates as $blocked) {
            $dateKey = $blocked['block_date'];
            if (!isset($byDate[$dateKey])) {
                $byDate[$dateKey] = [];
            }
            if (($blocked['block_scope'] ?? 'type') === 'individual') {
                $blockedRid = (int)($blocked['individual_room_id'] ?? 0);
                $lane = $laneOfRoom[$blockedRid] ?? $blockedRid;
                $laneKeySeen = $lane . '|' . $dateKey;
                if ($lane !== $blockedRid || count($laneRoomIds[$lane] ?? []) > 1) {
                    if (isset($blockLaneSeen[$laneKeySeen])) {
                        continue;
                    }
                    $blockLaneSeen[$laneKeySeen] = true;
                    $blocked['individual_room_id'] = $lane;
                }
            }
            $byDate[$dateKey][] = $blocked;
        }
        return $byDate;
    }

    /** Live bookings with a night (or a departure) between $start and $end (inclusive), as the Month view reads them. */
    function rh_cal_fetch_bookings(PDO $pdo, string $start, string $end): array
    {
        $effCo = rh_effective_checkout_sql('b');
        $stmt = $pdo->prepare("
            SELECT b.*, {$effCo} AS effective_check_out, r.name as room_name, r.id as room_id, r.price_per_night,
                   ir.id as individual_room_id, ir.room_number as individual_room_number,
                   ir.room_name as individual_room_name, ir.floor as individual_room_floor,
                   ir.status as individual_room_status
            FROM bookings b
            INNER JOIN rooms r ON b.room_id = r.id
            LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
            WHERE b.deleted_at IS NULL AND b.status IN ('pending', 'tentative', 'confirmed', 'checked-in')
            AND (
                (b.check_in_date <= :end_date AND {$effCo} >= :start_date)
            )
            ORDER BY b.check_in_date ASC, r.name ASC, ir.room_number ASC
        ");
        $stmt->execute(['start_date' => $start, 'end_date' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** booking id => [room id => true] for every room each booking still holds (booking_rooms, not released). */
    function rh_cal_fetch_held_rooms(PDO $pdo, array $bookingIds): array
    {
        $held = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $bookingIds))));
        if (!$ids) {
            return $held;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $hr = $pdo->prepare("SELECT booking_id, individual_room_id FROM booking_rooms WHERE released_at IS NULL AND booking_id IN ($in)");
            $hr->execute($ids);
            foreach ($hr->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $held[(int)$row['booking_id']][(int)$row['individual_room_id']] = true;
            }
        } catch (PDOException $e) {
            $held = [];
        }
        return $held;
    }

    /**
     * Batched getBookingRoomLabel(): booking id => "101A + 101B" in one query (same order and wording). A booking
     * with no active booking_rooms row falls back to getBookingRoomLabel() (last released set / primary pointer).
     */
    function rh_cal_room_labels(PDO $pdo, array $bookingIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $bookingIds))));
        $labels = [];
        if (!$ids) {
            return $labels;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $pdo->prepare("
                SELECT br.booking_id, ir.room_number, ir.room_name
                FROM booking_rooms br
                JOIN individual_rooms ir ON ir.id = br.individual_room_id
                WHERE br.released_at IS NULL AND br.booking_id IN ($in)
                ORDER BY br.booking_id ASC, br.is_primary DESC, ir.room_number ASC, br.id ASC
            ");
            $st->execute($ids);
            $parts = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $label = trim((string)($row['room_number'] ?? ''));
                if ($label === '') {
                    $label = trim((string)($row['room_name'] ?? ''));
                }
                if ($label !== '') {
                    $parts[(int)$row['booking_id']][] = $label;
                }
            }
            foreach ($parts as $bid => $list) {
                $labels[$bid] = implode(' + ', array_values(array_unique($list)));
            }
        } catch (PDOException $e) {
            $labels = [];
        }
        foreach ($ids as $bid) {
            if (!isset($labels[$bid])) {
                $labels[$bid] = getBookingRoomLabel($bid);
            }
        }
        return $labels;
    }

    /** Pure: the room ids a booking holds (booking_rooms + the primary pointer). */
    function rh_cal_booking_room_ids(array $booking, array $heldRooms): array
    {
        $roomIdsHeld = $heldRooms[(int)$booking['id']] ?? [];
        if (!empty($booking['individual_room_id'])) {
            $roomIdsHeld[(int)$booking['individual_room_id']] = true;
        }
        return $roomIdsHeld;
    }

    /** Pure: the lane keys a booking is drawn on: ir_<lane> per held lane, else rt_<room type>. */
    function rh_cal_booking_lane_keys(array $booking, array $roomIdsHeld, array $laneOfRoom): array
    {
        return $roomIdsHeld
            ? array_values(array_unique(array_map(static fn ($rid) => 'ir_' . ($laneOfRoom[$rid] ?? $rid), array_keys($roomIdsHeld))))
            : ['rt_' . $booking['room_id']];
    }

    /**
     * Pure: date => lane key => bookings, one entry per night held (check-in .. effective check-out minus one).
     * $pairLabels: booking id => label, used for a booking holding more than one room.
     */
    function rh_cal_index_nights(array $bookings, array $heldRooms, array $laneOfRoom, array $pairLabels): array
    {
        $bookingsByDate = [];
        foreach ($bookings as $booking) {
            $checkIn = new DateTime($booking['check_in_date']);
            $checkOut = new DateTime($booking['effective_check_out'] ?? $booking['check_out_date']);
            $roomIdsHeld = rh_cal_booking_room_ids($booking, $heldRooms);
            $roomKeys = rh_cal_booking_lane_keys($booking, $roomIdsHeld, $laneOfRoom);
            $booking['pair_label'] = count($roomIdsHeld) > 1 ? (string)($pairLabels[(int)$booking['id']] ?? '') : '';

            $currentDate = clone $checkIn;
            while ($currentDate < $checkOut) {
                $dateKey = $currentDate->format('Y-m-d');
                foreach ($roomKeys as $roomKey) {
                    $bookingsByDate[$dateKey][$roomKey][] = $booking;
                }
                $currentDate->modify('+1 day');
            }
        }
        return $bookingsByDate;
    }

    /**
     * Everything the Month view draws, for nights $start .. $end (inclusive). Keys match the variables the Month
     * template has always used: rooms ($individualRooms), laneOfRoom, laneRoomIds, floors ($allFloors), roomTypes,
     * blockedDates, blockedDatesByDate, bookings, bookingsByDate, heldRooms, error (last error, or null).
     */
    function rh_calendar_load(PDO $pdo, string $start, string $end): array
    {
        $out = ['rooms' => [], 'laneOfRoom' => [], 'laneRoomIds' => [], 'floors' => [], 'roomTypes' => [],
            'blockedDates' => [], 'blockedDatesByDate' => [], 'bookings' => [], 'bookingsByDate' => [],
            'heldRooms' => [], 'allRooms' => [], 'error' => null];

        try {
            $out['allRooms'] = rh_cal_fetch_rooms($pdo);
        } catch (PDOException $e) {
            $out['error'] = "Error fetching individual rooms: " . $e->getMessage();
        }
        $out['rooms'] = $out['allRooms'];

        try {
            $lanes = rh_cal_build_lanes($out['allRooms'], rh_joined_only_pairs(true));
            $out['rooms'] = $lanes['rooms'];
            $out['laneOfRoom'] = $lanes['laneOfRoom'];
            $out['laneRoomIds'] = $lanes['laneRoomIds'];
            $out['floors'] = $lanes['floors'];
        } catch (Throwable $e) {
            error_log('calendar joined lanes: ' . $e->getMessage());
            $floors = [];
            foreach ($out['rooms'] as $ir) {
                $f = trim($ir['floor'] ?? '');
                if ($f !== '' && !in_array($f, $floors, true)) {
                    $floors[] = $f;
                }
            }
            natsort($floors);
            $out['floors'] = array_values($floors);
        }

        try {
            $out['roomTypes'] = $pdo->query("SELECT * FROM rooms WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $out['error'] = "Error fetching room types: " . $e->getMessage();
        }

        try {
            $out['blockedDates'] = getBlockedDates(null, $start, $end) ?: [];
            $out['blockedDatesByDate'] = rh_cal_group_blocks($out['blockedDates'], $out['laneOfRoom'], $out['laneRoomIds']);
        } catch (PDOException $e) {
            $out['error'] = "Error fetching blocked dates: " . $e->getMessage();
        }

        try {
            $out['bookings'] = rh_cal_fetch_bookings($pdo, $start, $end);
            $out['heldRooms'] = rh_cal_fetch_held_rooms($pdo, array_column($out['bookings'], 'id'));
            $multi = [];
            foreach ($out['bookings'] as $b) {
                if (count(rh_cal_booking_room_ids($b, $out['heldRooms'])) > 1) {
                    $multi[] = (int)$b['id'];
                }
            }
            $labels = rh_cal_room_labels($pdo, $multi);
            $out['bookingsByDate'] = rh_cal_index_nights($out['bookings'], $out['heldRooms'], $out['laneOfRoom'], $labels);
            $out['pairLabels'] = $labels;
        } catch (PDOException $e) {
            $out['error'] = "Error fetching bookings: " . $e->getMessage();
        }

        return $out;
    }
}

if (!function_exists('getTimelineAwareRoomStatus')) {
    /**
     * What a room is on a given night:
     *  - occupied  = a checked-in guest sleeps there (an overdue guest keeps it until checked out)
     *  - reserved  = a pending / tentative / confirmed booking holds it (incl. arrival due today and a
     *                missed arrival that has not been resolved yet - the guest is NOT in the room)
     *  - otherwise the night is free. The room's physical state (cleaning, inspection) only describes
     *    today; maintenance / out of order run from today until fixed. Past free nights are history.
     */
    function getTimelineAwareRoomStatus(array $room, string $date, array $bookingsByDate)
    {
        $today = date('Y-m-d');
        $nightBookings = $bookingsByDate[$date]['ir_' . $room['id']] ?? [];
        if ($nightBookings) {
            foreach ($nightBookings as $booking) {
                if ($booking['status'] === 'checked-in') {
                    return 'occupied';
                }
            }
            return 'reserved';
        }

        $physical = (string)($room['status'] ?? 'available');
        if ($date === $today) {
            return $physical === 'occupied' ? 'available' : $physical; // occupied with no stay = stale flag
        }
        if ($date > $today && in_array($physical, ['maintenance', 'out_of_order'], true)) {
            return $physical;
        }
        return 'available';
    }
}

/* ================================================================================================================
 * Room Timeline payload (first paint + admin/api/calendar-timeline.php). Read-only.
 * ============================================================================================================== */
if (!function_exists('rh_cal_timeline_payload')) {

    /** A valid Y-m-d or null (round-trip checked, so 2026-02-31 is refused). */
    function rh_cal_valid_date($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $s = trim((string)$value);
        $d = DateTime::createFromFormat('!Y-m-d', $s);
        return ($d && $d->format('Y-m-d') === $s) ? $s : null;
    }

    /** Pure: the date an in-house guest is due out: an overdue guest is due today. */
    function rh_cal_departure_date(array $b, string $today): string
    {
        $co = (string)$b['check_out_date'];
        return ($b['status'] === 'checked-in' && $co < $today) ? $today : $co;
    }

    /**
     * Pure: housekeeping state of one room. $open = its open tasks (pending / in_progress / blocked),
     * $latest = its newest task (any status) or null. Order: blocked > cleaning > dirty > inspection > clean.
     */
    function rh_cal_hk_state(string $roomStatus, array $open, ?array $latest, string $today): string
    {
        $hasBlocked = false;
        $hasInProgress = false;
        $hasDue = false;
        foreach ($open as $t) {
            $st = (string)($t['status'] ?? '');
            if ($st === 'blocked') {
                $hasBlocked = true;
            } elseif ($st === 'in_progress') {
                $hasInProgress = true;
            } elseif ($st === 'pending') {
                $due = (string)($t['due_date'] ?? '');
                if ($due === '' || $due === '0000-00-00' || substr($due, 0, 10) <= $today) {
                    $hasDue = true;
                }
            }
        }
        if ($hasBlocked) {
            return 'blocked';
        }
        if ($hasInProgress) {
            return 'cleaning';
        }
        if ($hasDue || $roomStatus === 'cleaning') {
            return 'dirty';
        }
        if ($roomStatus === 'inspection') {
            return 'inspection';
        }
        if ($latest && (string)($latest['status'] ?? '') === 'completed') {
            $v = (string)($latest['verified_at'] ?? '');
            if ($v === '' || strpos($v, '0000-00-00') === 0) {
                return 'inspection';
            }
        }
        return 'clean';
    }

    /** Plain words for a housekeeping state. */
    function rh_cal_hk_label(string $state): string
    {
        $map = [
            'clean' => 'Clean',
            'dirty' => 'Dirty: cleaning not started',
            'cleaning' => 'Cleaning in progress',
            'inspection' => 'Cleaned, waiting for inspection',
            'blocked' => 'Cleaning blocked',
        ];
        return $map[$state] ?? 'Clean';
    }

    /**
     * Housekeeping per individual room: room id => ['state', 'label', 'task' => current open task or null].
     * The current task is the one being cleaned, else the oldest due one, else a blocked one.
     */
    function rh_cal_hk_rooms(PDO $pdo, array $roomsById, string $today): array
    {
        $open = [];
        $latest = [];
        try {
            $rows = $pdo->query("
                SELECT ha.id, ha.individual_room_id, ha.status, ha.due_date, ha.verified_at, ha.assigned_to, ha.priority,
                       ha.assignment_type, ha.notes, ha.scheduled_time,
                       COALESCE(NULLIF(au.full_name, ''), au.username) AS assigned_name
                FROM housekeeping_assignments ha
                LEFT JOIN admin_users au ON au.id = ha.assigned_to
                WHERE ha.status IN ('pending', 'in_progress', 'blocked')
                   OR ha.id IN (SELECT MAX(x.id) FROM housekeeping_assignments x GROUP BY x.individual_room_id)
                ORDER BY ha.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $rows = [];
        }
        foreach ($rows as $r) {
            $rid = (int)$r['individual_room_id'];
            if (in_array($r['status'], ['pending', 'in_progress', 'blocked'], true)) {
                $open[$rid][] = $r;
            }
            if (!isset($latest[$rid]) || (int)$r['id'] > (int)$latest[$rid]['id']) {
                $latest[$rid] = $r;
            }
        }
        $out = [];
        foreach ($roomsById as $rid => $room) {
            $state = rh_cal_hk_state((string)($room['status'] ?? ''), $open[$rid] ?? [], $latest[$rid] ?? null, $today);
            $task = null;
            foreach (['in_progress', 'pending', 'blocked'] as $want) {
                foreach ($open[$rid] ?? [] as $t) {
                    if ($t['status'] === $want) {
                        $task = $t;
                        break 2;
                    }
                }
            }
            $out[$rid] = ['state' => $state, 'label' => rh_cal_hk_label($state), 'task' => $task, 'open' => count($open[$rid] ?? [])];
        }
        return $out;
    }

    /** Pure: worst housekeeping state of a joined pair's rooms. */
    function rh_cal_hk_worst(array $states): string
    {
        $rank = ['clean' => 1, 'inspection' => 2, 'cleaning' => 3, 'dirty' => 4, 'blocked' => 5];
        $worst = 'clean';
        foreach ($states as $s) {
            if (($rank[$s] ?? 0) > $rank[$worst]) {
                $worst = $s;
            }
        }
        return $worst;
    }

    /** Maintenance windows that take a room off sale between $start and $end (as the availability check reads them). */
    function rh_cal_fetch_maintenance(PDO $pdo, string $start, string $end): array
    {
        try {
            $st = $pdo->prepare("
                SELECT id, individual_room_id, title, status, priority,
                       DATE(start_date) AS s, DATE(DATE_SUB(end_date, INTERVAL 1 SECOND)) AS e
                FROM room_maintenance_schedules
                WHERE block_room = 1
                  AND COALESCE(status, '') NOT IN ('completed', 'cancelled', 'verified')
                  AND DATE(start_date) <= ?
                  AND DATE(DATE_SUB(end_date, INTERVAL 1 SECOND)) >= ?
            ");
            $st->execute([$end, $start]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Pure: lane id => [date => cell] for out of order / maintenance / blocked nights, then merged into spans.
     * Precedence on one night: out of order > maintenance > blocked.
     */
    function rh_cal_block_spans(array $lanes, array $laneOfRoom, array $blockedDates, array $maintenance, array $dates, string $today): array
    {
        $rank = ['ooo' => 3, 'maintenance' => 2, 'blocked' => 1];
        $cells = [];
        $put = static function (int $lane, string $d, array $cell) use (&$cells, $rank) {
            $cur = $cells[$lane][$d] ?? null;
            if ($cur === null || $rank[$cell['kind']] > $rank[$cur['kind']]) {
                $cells[$lane][$d] = $cell;
            }
        };
        $inRange = array_flip($dates);
        $lanesByType = [];
        foreach ($lanes as $l) {
            $lanesByType[(int)$l['room_type_id']][] = (int)$l['id'];
        }
        foreach ($blockedDates as $b) {
            $d = (string)$b['block_date'];
            if (!isset($inRange[$d])) {
                continue;
            }
            $reason = trim((string)($b['reason'] ?? ''));
            $cell = ['kind' => 'blocked', 'label' => 'Blocked', 'reason' => $reason, 'href' => 'blocked-dates.php'];
            if (($b['block_scope'] ?? 'type') === 'individual') {
                $rid = (int)($b['individual_room_id'] ?? 0);
                $put($laneOfRoom[$rid] ?? $rid, $d, $cell);
            } else {
                $targets = ($b['room_id'] === null || $b['room_id'] === '') ? array_map('intval', array_column($lanes, 'id')) : ($lanesByType[(int)$b['room_id']] ?? []);
                foreach ($targets as $laneId) {
                    $put($laneId, $d, $cell);
                }
            }
        }
        foreach ($maintenance as $m) {
            $rid = (int)$m['individual_room_id'];
            $lane = $laneOfRoom[$rid] ?? $rid;
            $cell = ['kind' => 'maintenance', 'label' => 'Maintenance', 'reason' => trim((string)($m['title'] ?? '')), 'href' => 'room-maintenance.php'];
            foreach ($dates as $d) {
                if ($d >= (string)$m['s'] && $d <= (string)$m['e']) {
                    $put($lane, $d, $cell);
                }
            }
        }
        // A room marked maintenance / out of order now stays out from today until someone fixes it.
        foreach ($lanes as $l) {
            $st = (string)($l['status'] ?? '');
            if (!in_array($st, ['maintenance', 'out_of_order'], true)) {
                continue;
            }
            $cell = $st === 'out_of_order'
                ? ['kind' => 'ooo', 'label' => 'Out of order', 'reason' => '', 'href' => 'room-maintenance.php']
                : ['kind' => 'maintenance', 'label' => 'Maintenance', 'reason' => '', 'href' => 'room-maintenance.php'];
            foreach ($dates as $d) {
                if ($d >= $today) {
                    $put((int)$l['id'], $d, $cell);
                }
            }
        }
        $spans = [];
        foreach ($cells as $laneId => $byDate) {
            $open = null;
            foreach ($dates as $d) {
                $c = $byDate[$d] ?? null;
                if ($open && $c && $c['kind'] === $open['kind'] && $c['reason'] === $open['reason']) {
                    $open['to'] = $d;
                    continue;
                }
                if ($open) {
                    $spans[] = $open;
                    $open = null;
                }
                if ($c) {
                    $open = ['lane' => (int)$laneId, 'kind' => $c['kind'], 'label' => $c['label'], 'reason' => $c['reason'],
                        'href' => $c['href'], 'from' => $d, 'to' => $d];
                }
            }
            if ($open) {
                $spans[] = $open;
            }
        }
        return $spans;
    }

    /**
     * Pure: per night figures. Occupancy = lanes with a stay that night (+ one per unassigned booking) / lanes
     * that can be sold (out of order excluded). Arrivals = check-in that day (pending, tentative, confirmed).
     * Departures = due out that day (an overdue in-house guest is due today).
     */
    function rh_cal_day_stats(array $bookings, array $heldRooms, array $lanes, array $laneOfRoom, array $dates, string $today): array
    {
        $assigned = [];
        $unassigned = [];
        foreach ($bookings as $b) {
            $held = rh_cal_booking_room_ids($b, $heldRooms);
            $co = (string)($b['effective_check_out'] ?? $b['check_out_date']);
            if ($held) {
                foreach (array_keys($held) as $rid) {
                    $assigned[] = ['room_id' => $rid, 'ci' => (string)$b['check_in_date'], 'co' => $co];
                }
            } else {
                $unassigned[] = ['booking_id' => (int)$b['id'], 'ci' => (string)$b['check_in_date'], 'co' => $co, 'units' => 1];
            }
        }
        $total = 0;
        foreach ($lanes as $l) {
            if ((string)($l['status'] ?? '') !== 'out_of_order') {
                $total++;
            }
        }
        $byNight = rh_fd_rooms_booked_by_night($laneOfRoom, $assigned, $unassigned, $dates, $total);
        $out = [];
        foreach ($dates as $d) {
            $arr = 0;
            $dep = 0;
            foreach ($bookings as $b) {
                if ((string)$b['check_in_date'] === $d && in_array($b['status'], ['pending', 'tentative', 'confirmed'], true)) {
                    $arr++;
                }
                if (rh_cal_departure_date($b, $today) === $d) {
                    $dep++;
                }
            }
            $booked = (int)($byNight[$d]['booked'] ?? 0);
            $out[$d] = [
                'booked' => $booked,
                'total' => $total,
                'pct' => $total > 0 ? (int)round($booked * 100 / $total) : null,
                'arr' => $arr,
                'dep' => $dep,
            ];
        }
        return $out;
    }

    /** booking id => ['size' => rooms in its group, 'ref' => the group's main booking ref] (batched). */
    function rh_cal_group_info(PDO $pdo, array $bookings): array
    {
        $rootOf = [];
        foreach ($bookings as $b) {
            $id = (int)$b['id'];
            $p = (int)($b['primary_booking_id'] ?? 0);
            $rootOf[$id] = ($p > 0 && $p !== $id) ? $p : $id;
        }
        $roots = array_values(array_unique($rootOf));
        $info = [];
        if (!$roots) {
            return $info;
        }
        $in = implode(',', array_fill(0, count($roots), '?'));
        try {
            $st = $pdo->prepare("
                SELECT p.id, p.booking_reference,
                       (SELECT COUNT(*) FROM bookings c
                         WHERE c.primary_booking_id = p.id AND c.id <> p.id AND c.deleted_at IS NULL
                           AND c.status NOT IN ('cancelled', 'expired')) AS children
                FROM bookings p WHERE p.id IN ($in)
            ");
            $st->execute($roots);
            $byRoot = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byRoot[(int)$r['id']] = ['size' => 1 + (int)$r['children'], 'ref' => (string)$r['booking_reference']];
            }
            foreach ($rootOf as $id => $root) {
                $info[$id] = $byRoot[$root] ?? ['size' => 1, 'ref' => ''];
            }
        } catch (PDOException $e) {
            $info = [];
        }
        return $info;
    }

    /** Pure: one booking as the timeline draws it (no HTML; the page escapes with textContent). */
    function rh_cal_booking_item(array $b, array $heldRooms, array $laneOfRoom, array $laneRoomIds, array $pairLabels, array $groupInfo, string $today, string $currency): array
    {
        $id = (int)$b['id'];
        $held = rh_cal_booking_room_ids($b, $heldRooms);
        $laneKeys = rh_cal_booking_lane_keys($b, $held, $laneOfRoom);
        $lanes = [];
        foreach ($laneKeys as $k) {
            if (strpos($k, 'ir_') === 0) {
                $lanes[] = (int)substr($k, 3);
            }
        }
        $joinedLane = false;
        foreach ($lanes as $laneId) {
            if (count($laneRoomIds[$laneId] ?? []) > 1) {
                $joinedLane = true;
            }
        }
        $ci = (string)$b['check_in_date'];
        $co = (string)$b['check_out_date'];
        $eco = (string)($b['effective_check_out'] ?? $co);
        $nights = (int)(new DateTime($ci))->diff(new DateTime($co))->days;
        $pay = rh_booking_pay_state($b, ['currency' => $currency]);
        $notes = trim((string)($b['special_requests'] ?? ''));
        $tnotes = trim((string)($b['tentative_notes'] ?? ''));
        $g = $groupInfo[$id] ?? ['size' => 1, 'ref' => ''];
        $label = count($held) > 1 ? (string)($pairLabels[$id] ?? '') : '';
        if ($label === '') {
            $label = trim((string)($b['individual_room_number'] ?? ''));
        }
        $noteText = $notes !== '' ? $notes : $tnotes;
        if (function_exists('mb_substr') && mb_strlen($noteText) > 280) {
            $noteText = mb_substr($noteText, 0, 280) . '...';
        }
        return [
            'id' => $id,
            'ref' => (string)$b['booking_reference'],
            'guest' => (string)$b['guest_name'],
            'room_guest' => trim((string)($b['room_guest_name'] ?? '')),
            'status' => (string)$b['status'],
            'lanes' => $lanes,
            'type_id' => (int)$b['room_id'],
            'type_name' => (string)($b['room_name'] ?? ''),
            'room_label' => $label,
            'ci' => $ci,
            'co' => $co,
            'eco' => $eco,
            'dep' => rh_cal_departure_date($b, $today),
            'nights' => $nights,
            'guests' => (int)($b['number_of_guests'] ?? 0),
            'adults' => (int)($b['adult_guests'] ?? 0),
            'children' => (int)($b['child_guests'] ?? 0),
            'rate_plan' => trim((string)($b['rate_plan_label'] ?? '')),
            'total' => round(rh_booking_full_total($b), 2),
            'paid' => round((float)($b['amount_paid'] ?? 0), 2),
            'due' => round((float)($b['amount_due'] ?? 0), 2),
            'pay' => ['key' => $pay['key'], 'label' => $pay['label'], 'short' => $pay['short'], 'class' => $pay['class']],
            'notes' => $noteText !== '',
            'note_text' => $noteText,
            'joined' => !empty($b['room_combination_id']) || $joinedLane,
            'group' => $g['size'] > 1 || count($lanes) > 1,
            'group_size' => max((int)$g['size'], count($lanes)),
            'group_ref' => $g['ref'],
            'missed' => rh_fd_is_missed_arrival((string)$b['status'], $ci, $today),
            'overdue' => $b['status'] === 'checked-in' && $co < $today,
        ];
    }

    /** What this user may open from the timeline (links only; each target page re-checks). */
    function rh_cal_timeline_can(int $uid): array
    {
        $keys = ['open_booking', 'checkin', 'checkout', 'assign', 'move', 'edit_dates', 'payment', 'create', 'rooms_page',
            'blocked_page', 'maint_page', 'hk_page'];
        if ($uid <= 0 || !function_exists('rhCanLinkTo')) {
            return array_fill_keys($keys, false);
        }
        $link = static fn (string $page): bool => rhCanLinkTo($uid, $page);
        $perm = static fn (string $key): bool => $key !== '' && hasPermission($uid, $key);
        $bookingsPage = $link('bookings.php');
        return [
            'open_booking' => $link('booking-details.php'),
            'checkin' => $bookingsPage && $perm((string)rhBookingActionPermission('checkin')),
            'checkout' => $bookingsPage && $perm((string)rhBookingActionPermission('checkout')),
            'assign' => $bookingsPage && $perm('edit_booking'),
            'move' => $bookingsPage && $perm('edit_booking'),
            'edit_dates' => $link('edit-booking.php') && $perm((string)rhBookingActionPermission('adjust_dates')),
            'payment' => $link('payment-add.php') && $perm((string)rhBookingActionPermission('payment')),
            'create' => $link('create-booking.php'),
            'rooms_page' => $link('individual-rooms.php'),
            'blocked_page' => $link('blocked-dates.php'),
            'maint_page' => $link('room-maintenance.php'),
            'hk_page' => $link('housekeeping.php'),
        ];
    }

    /**
     * The whole timeline for $days nights from $start. $opts: user_id, role, today (Y-m-d, for tests).
     * Returns ['ok', 'start', 'end', 'days', 'today', 'currency', 'dates', 'groups', 'lanes', 'bookings', 'blocks',
     * 'stats', 'agenda', 'can', 'hk_on', 'error'].
     */
    function rh_cal_timeline_payload(PDO $pdo, string $start, int $days, array $opts = []): array
    {
        $days = max(3, min(31, $days));
        $today = rh_cal_valid_date($opts['today'] ?? '') ?? date('Y-m-d');
        $uid = (int)($opts['user_id'] ?? 0);
        $role = (string)($opts['role'] ?? '');
        $startDt = new DateTime($start);
        $dates = [];
        $dateInfo = [];
        for ($i = 0; $i < $days; $i++) {
            $d = (clone $startDt)->modify('+' . $i . ' day');
            $ds = $d->format('Y-m-d');
            $dates[] = $ds;
            $dateInfo[] = ['d' => $ds, 'wd' => $d->format('D'), 'day' => $d->format('d'), 'mon' => $d->format('M'),
                'we' => in_array((int)$d->format('N'), [6, 7], true), 'today' => $ds === $today];
        }
        $end = $dates[count($dates) - 1];
        $currency = (string)getSetting('currency_symbol');

        $data = rh_calendar_load($pdo, $start, $end);
        $lanes = $data['rooms'];
        $laneOfRoom = $data['laneOfRoom'];
        $laneRoomIds = $data['laneRoomIds'];

        // Housekeeping state per individual room, then per lane (worst of a joined pair).
        $hkOn = ($role !== '' && function_exists('rhModuleAllowsAnyPage')) ? rhModuleAllowsAnyPage(['housekeeping.php'], $role) : true;
        $roomsById = [];
        foreach ($data['allRooms'] as $r) {
            $roomsById[(int)$r['id']] = $r;
        }
        $hk = rh_cal_hk_rooms($pdo, $roomsById, $today);

        // Today's bookings: lane flags for the Status filter, the type badges and the phone Agenda.
        $todayIn = $today >= $start && $today <= $end;
        $todayBookings = $todayIn
            ? array_values(array_filter($data['bookings'], static fn ($b) => (string)$b['check_in_date'] <= $today && (string)$b['effective_check_out'] >= $today))
            : rh_cal_fetch_bookings($pdo, $today, $today);
        $todayHeld = $todayIn ? $data['heldRooms'] : rh_cal_fetch_held_rooms($pdo, array_column($todayBookings, 'id'));

        $maintenance = rh_cal_fetch_maintenance($pdo, min($start, $today), max($end, $today));
        $blocks = rh_cal_block_spans($lanes, $laneOfRoom, $data['blockedDates'], $maintenance, $dates, $today);
        $outToday = [];
        foreach ($lanes as $l) {
            if (in_array((string)($l['status'] ?? ''), rh_fd_out_of_service_statuses(), true)) {
                $outToday[(int)$l['id']] = true;
            }
        }
        foreach ($maintenance as $m) {
            if ((string)$m['s'] <= $today && (string)$m['e'] >= $today) {
                $outToday[$laneOfRoom[(int)$m['individual_room_id']] ?? (int)$m['individual_room_id']] = true;
            }
        }
        $blockedToday = [];
        if ($todayIn) {
            foreach ($blocks as $s) {
                if ($s['kind'] === 'blocked' && $s['from'] <= $today && $s['to'] >= $today) {
                    $blockedToday[$s['lane']] = true;
                }
            }
        }

        $flags = [];
        foreach ($todayBookings as $b) {
            $held = rh_cal_booking_room_ids($b, $todayHeld);
            $laneIds = [];
            foreach (array_keys($held) as $rid) {
                $laneIds[$laneOfRoom[$rid] ?? $rid] = true;
            }
            foreach (array_keys($laneIds) as $laneId) {
                $f = &$flags[$laneId];
                if ((string)$b['check_in_date'] <= $today && $today < (string)$b['effective_check_out']) {
                    $f['night'] = true;
                }
                if ($b['status'] === 'checked-in') {
                    $f['occ'] = true;
                }
                if ((string)$b['check_in_date'] === $today && in_array($b['status'], ['pending', 'tentative', 'confirmed'], true)) {
                    $f['arr'] = true;
                }
                if (rh_cal_departure_date($b, $today) === $today) {
                    $f['dep'] = true;
                }
                unset($f);
            }
        }

        $laneOut = [];
        $groups = [];
        foreach ($lanes as $l) {
            $lid = (int)$l['id'];
            $states = [];
            foreach ($laneRoomIds[$lid] ?? [$lid] as $rid) {
                $states[] = $hk[$rid]['state'] ?? 'clean';
            }
            $hkState = rh_cal_hk_worst($states);
            $f = $flags[$lid] ?? [];
            $oos = !empty($outToday[$lid]);
            $free = empty($f['night']) && !$oos && empty($blockedToday[$lid]);
            $typeId = (int)$l['room_type_id'];
            $laneOut[] = [
                'id' => $lid,
                'type_id' => $typeId,
                'number' => (string)$l['room_number'],
                'name' => (string)($l['room_name'] ?? ''),
                'floor' => trim((string)($l['floor'] ?? '')),
                'status' => (string)($l['status'] ?? ''),
                'joined' => !empty($l['is_joined_lane']),
                'room_ids' => array_map('intval', $laneRoomIds[$lid] ?? [$lid]),
                'hk' => ['state' => $hkState, 'label' => rh_cal_hk_label($hkState)],
                'today' => ['free' => $free, 'occ' => !empty($f['occ']), 'arr' => !empty($f['arr']), 'dep' => !empty($f['dep']),
                    'dirty' => $hkState === 'dirty', 'oos' => $oos],
            ];
            if (!isset($groups[$typeId])) {
                $groups[$typeId] = ['id' => $typeId, 'name' => (string)$l['room_type_name'], 'lanes' => [], 'free_tonight' => 0, 'total' => 0];
            }
            $groups[$typeId]['lanes'][] = $lid;
            $groups[$typeId]['total']++;
            if ($free) {
                $groups[$typeId]['free_tonight']++;
            }
        }
        // Room types with no active room still get a group when a booking waits on them.
        $typeNames = [];
        foreach ($data['roomTypes'] as $rt) {
            $typeNames[(int)$rt['id']] = (string)$rt['name'];
        }

        $allBookings = $data['bookings'];
        $seen = array_flip(array_map('intval', array_column($allBookings, 'id')));
        foreach ($todayBookings as $b) {
            if (!isset($seen[(int)$b['id']])) {
                $allBookings[] = $b;
            }
        }
        $groupInfo = rh_cal_group_info($pdo, $allBookings);
        $pairLabels = $data['pairLabels'] ?? [];
        $missingLabel = [];
        foreach ($todayBookings as $b) {
            if (!isset($pairLabels[(int)$b['id']]) && count(rh_cal_booking_room_ids($b, $todayHeld)) > 1) {
                $missingLabel[] = (int)$b['id'];
            }
        }
        if ($missingLabel) {
            $pairLabels += rh_cal_room_labels($pdo, $missingLabel);
        }
        $heldAll = $data['heldRooms'] + $todayHeld;

        $items = [];
        foreach ($data['bookings'] as $b) {
            $item = rh_cal_booking_item($b, $heldAll, $laneOfRoom, $laneRoomIds, $pairLabels, $groupInfo, $today, $currency);
            if (!$item['lanes'] && !isset($groups[$item['type_id']])) {
                $groups[$item['type_id']] = ['id' => $item['type_id'], 'name' => $typeNames[$item['type_id']] ?? $item['type_name'], 'lanes' => [], 'free_tonight' => 0, 'total' => 0];
            }
            $items[] = $item;
        }

        // Phone Agenda: arriving today, in house, departing today.
        $agenda = ['arr' => [], 'inh' => [], 'dep' => []];
        foreach ($todayBookings as $b) {
            $item = rh_cal_booking_item($b, $heldAll, $laneOfRoom, $laneRoomIds, $pairLabels, $groupInfo, $today, $currency);
            if ((string)$b['check_in_date'] === $today && in_array($b['status'], ['pending', 'tentative', 'confirmed'], true)) {
                $agenda['arr'][] = $item;
            } elseif (rh_cal_departure_date($b, $today) === $today) {
                $agenda['dep'][] = $item;
            } elseif ($b['status'] === 'checked-in') {
                $agenda['inh'][] = $item;
            }
        }

        return [
            'ok' => true,
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'today' => $today,
            'currency' => $currency,
            'dates' => $dateInfo,
            'groups' => array_values($groups),
            'lanes' => $laneOut,
            'bookings' => $items,
            'blocks' => $blocks,
            'stats' => rh_cal_day_stats($data['bookings'], $data['heldRooms'], $lanes, $laneOfRoom, $dates, $today),
            'agenda' => $agenda,
            'can' => rh_cal_timeline_can($uid),
            'hk_on' => $hkOn,
            'error' => $data['error'] ? 'Some calendar data could not be loaded.' : null,
        ];
    }

    /**
     * Housekeeping tab rows: one per active individual room with its state and current task.
     * $filter: '' | dirty | cleaning | inspection | clean | unassigned. Returns ['rows', 'counts'].
     */
    function rh_cal_hk_tab(PDO $pdo, string $filter, string $today): array
    {
        $rooms = [];
        try {
            $rooms = rh_cal_fetch_rooms($pdo);
        } catch (PDOException $e) {
            $rooms = [];
        }
        $byId = [];
        foreach ($rooms as $r) {
            $byId[(int)$r['id']] = $r;
        }
        $hk = rh_cal_hk_rooms($pdo, $byId, $today);
        $counts = ['dirty' => 0, 'cleaning' => 0, 'inspection' => 0, 'clean' => 0, 'blocked' => 0, 'unassigned' => 0, 'open' => 0];
        $rows = [];
        foreach ($rooms as $r) {
            $rid = (int)$r['id'];
            $h = $hk[$rid];
            $counts[$h['state']] = ($counts[$h['state']] ?? 0) + 1;
            $counts['open'] += $h['open'];
            $unassigned = $h['task'] !== null && empty($h['task']['assigned_to']);
            if ($unassigned) {
                $counts['unassigned']++;
            }
            if ($filter === 'unassigned' ? !$unassigned : ($filter !== '' && $h['state'] !== $filter)) {
                continue;
            }
            $rows[] = ['room' => $r, 'hk' => $h];
        }
        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * Maintenance tab rows: open schedules (not completed / cancelled / verified).
     * $filter: '' | progress | due (due or starting within 7 days). Returns ['rows', 'counts'].
     */
    function rh_cal_maint_tab(PDO $pdo, string $filter, string $today): array
    {
        $rows = [];
        try {
            $rows = $pdo->query("
                SELECT rms.id, rms.individual_room_id, rms.title, rms.status, rms.priority, rms.start_date, rms.end_date,
                       rms.due_date, rms.block_room, ir.room_number, ir.room_name, ir.status AS room_status
                FROM room_maintenance_schedules rms
                LEFT JOIN individual_rooms ir ON ir.id = rms.individual_room_id
                WHERE COALESCE(rms.status, '') NOT IN ('completed', 'cancelled', 'verified')
                ORDER BY CASE rms.status WHEN 'in_progress' THEN 1 ELSE 2 END,
                         CASE rms.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END,
                         rms.start_date ASC, rms.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $rows = [];
        }
        $weekEnd = (new DateTime($today))->modify('+6 day')->format('Y-m-d');
        $counts = ['open' => count($rows), 'progress' => 0, 'due' => 0, 'out_now' => 0];
        $out = [];
        foreach ($rows as $r) {
            $prog = (string)$r['status'] === 'in_progress';
            $when = substr((string)($r['due_date'] ?: $r['start_date']), 0, 10);
            $due = $when !== '' && $when <= $weekEnd;
            if ($prog) {
                $counts['progress']++;
            }
            if ($due) {
                $counts['due']++;
            }
            if (($filter === 'progress' && !$prog) || ($filter === 'due' && !$due)) {
                continue;
            }
            $out[] = $r;
        }
        try {
            $counts['out_now'] = rh_room_units_count(rh_fd_out_of_service_statuses());
        } catch (Throwable $e) {
            $counts['out_now'] = 0;
        }
        return ['rows' => $out, 'counts' => $counts];
    }
}
