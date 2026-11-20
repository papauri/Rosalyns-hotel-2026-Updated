<?php
/**
 * Room timeline for the operations pages: Housekeeping (admin/housekeeping.php), Maintenance
 * (admin/room-maintenance.php) and Room Service (admin/room-service-dashboard.php, the service block at the end).
 * ONE source for each page's first paint and its range endpoint (admin/api/housekeeping-timeline.php,
 * admin/api/maintenance-timeline.php, admin/api/room-service-timeline.php). Drawn by admin/js/room-timeline-ops.js.
 *
 * Read-only (SELECTs only). Never calls the Maintenance page's room-status sweep. The housekeeping and maintenance
 * payloads are deliberately slim: no money, no rates, no payment state (these pages' users may not have the calendar
 * or the booking screens). The room service payload carries each order's total and whether it is on the room folio,
 * the same figures the Room Service page already shows its users (formatted on the server, never recalculated).
 * Rules it carries:
 *  - one lane per active individual room (a joined pair's rooms each keep their own lane: tasks and jobs are per room);
 *  - a housekeeping task is a whole-day bar on its due date; an open task due before the range (or with no date) is
 *    carried onto today's column, flagged overdue, when the range shows today;
 *  - EVERY maintenance job that is not cancelled shows, block_room 0 included (the calendar keeps showing only the
 *    jobs that take the room off sale: rh_cal_fetch_maintenance). Done jobs show for 7 days after completion. An
 *    open job past its To date runs on to today (still open, flagged overdue);
 *  - job nights: From .. To minus one second, the same day span the calendar uses for a blocking job;
 *  - nights a guest holds a room (booking_rooms + the primary pointer, live bookings) tint the housekeeping grid.
 *
 * The caller must already have config/database.php loaded ($pdo). Never include admin-init.php from here.
 */

require_once __DIR__ . '/calendar-data.php';

if (!function_exists('rh_ops_dates')) {

    /** Range lengths the operations grids offer (same set as the calendar). */
    function rh_ops_allowed_days(): array
    {
        return [3, 7, 14, 30];
    }

    /** Pure: the range's dates. Returns ['dates' => [Y-m-d], 'info' => [{d, wd, day, mon, we, today}]]. */
    function rh_ops_dates(string $start, int $days, string $today): array
    {
        $startDt = new DateTime($start);
        $dates = [];
        $info = [];
        for ($i = 0; $i < $days; $i++) {
            $d = (clone $startDt)->modify('+' . $i . ' day');
            $ds = $d->format('Y-m-d');
            $dates[] = $ds;
            $info[] = ['d' => $ds, 'wd' => $d->format('D'), 'day' => $d->format('d'), 'mon' => $d->format('M'),
                'we' => in_array((int)$d->format('N'), [6, 7], true), 'today' => $ds === $today];
        }
        return ['dates' => $dates, 'info' => $info];
    }

    /** Pure: a stored date (date or datetime) as Y-m-d, '' when empty / zero. */
    function rh_ops_day($v): string
    {
        $s = substr(trim((string)$v), 0, 10);
        if ($s === '' || $s === '0000-00-00') {
            return '';
        }
        return rh_cal_valid_date($s) ?? '';
    }

    /** Pure: a stored time / datetime as H:i, '' when empty. */
    function rh_ops_time($v): string
    {
        $s = trim((string)$v);
        if ($s === '' || strpos($s, '0000-00-00') === 0) {
            return '';
        }
        if (preg_match('/(\d{2}):(\d{2})(?::\d{2})?$/', $s, $m)) {
            return $m[1] . ':' . $m[2];
        }
        return '';
    }

    /**
     * Pure: the page's deep-link parameters (admin/housekeeping.php, admin/room-maintenance.php), whitelisted.
     * Invalid values are dropped, never guessed. $mode: housekeeping | maintenance.
     *  id   = a task / job id (positive int; the page opens it only when it exists)
     *  room = housekeeping: a room NUMBER (the page's existing ?room= filter); maintenance: an individual room id
     *  date = Y-m-d, first day of the range;  view = timeline | list;  days = 3 / 7 / 14 / 30
     */
    function rh_ops_deeplink(array $get, string $mode): array
    {
        $out = ['id' => 0, 'room' => '', 'room_id' => 0, 'date' => null, 'view' => '', 'days' => 0];
        $id = filter_var($get['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if ($id !== false && $id !== null && is_scalar($get['id'])) {
            $out['id'] = (int)$id;
        }
        if (isset($get['room']) && is_scalar($get['room'])) {
            $room = trim((string)$get['room']);
            if ($mode === 'housekeeping') {
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{0,29}$/', $room)) {
                    $out['room'] = $room;
                }
            } else {
                $rid = filter_var($room, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
                if ($rid !== false) {
                    $out['room_id'] = (int)$rid;
                }
            }
        }
        $out['date'] = rh_cal_valid_date($get['date'] ?? '');
        $view = (isset($get['view']) && is_scalar($get['view'])) ? (string)$get['view'] : '';
        if (in_array($view, ['timeline', 'list'], true)) {
            $out['view'] = $view;
        }
        $days = (isset($get['days']) && is_scalar($get['days'])) ? filter_var($get['days'], FILTER_VALIDATE_INT) : false;
        if ($days !== false && in_array((int)$days, rh_ops_allowed_days(), true)) {
            $out['days'] = (int)$days;
        }
        return $out;
    }

    /** Pure: room id => [night Y-m-d => true] for the nights in $dates a live booking holds the room. */
    function rh_ops_stay_nights(array $bookings, array $heldRooms, array $dates): array
    {
        $out = [];
        if (!$dates) {
            return $out;
        }
        foreach ($bookings as $b) {
            $ci = (string)$b['check_in_date'];
            $eco = (string)($b['effective_check_out'] ?? $b['check_out_date']);
            foreach (array_keys(rh_cal_booking_room_ids($b, $heldRooms)) as $rid) {
                foreach ($dates as $d) {
                    if ($d >= $ci && $d < $eco) {
                        $out[(int)$rid][$d] = true;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Pure: lanes (one per room) and room-type groups, in the calendar's room order.
     * $hk = rh_cal_hk_rooms() result; $stay = rh_ops_stay_nights() result (housekeeping only, [] otherwise).
     */
    function rh_ops_lanes(array $rooms, array $hk, array $stay): array
    {
        $lanes = [];
        $groups = [];
        $oos = rh_fd_out_of_service_statuses();
        foreach ($rooms as $r) {
            $rid = (int)$r['id'];
            $typeId = (int)$r['room_type_id'];
            $h = $hk[$rid] ?? ['state' => 'clean', 'label' => rh_cal_hk_label('clean'), 'task' => null];
            $nights = array_keys($stay[$rid] ?? []);
            sort($nights);
            $lanes[] = [
                'id' => $rid,
                'type_id' => $typeId,
                'number' => (string)$r['room_number'],
                'name' => (string)($r['room_name'] ?? ''),
                'floor' => trim((string)($r['floor'] ?? '')),
                'status' => (string)($r['status'] ?? ''),
                'out' => in_array((string)($r['status'] ?? ''), $oos, true),
                'hk' => ['state' => (string)$h['state'], 'label' => (string)$h['label']],
                'unassigned' => $h['task'] !== null && empty($h['task']['assigned_to']),
                'stay' => $nights,
            ];
            if (!isset($groups[$typeId])) {
                $groups[$typeId] = ['id' => $typeId, 'name' => (string)($r['room_type_name'] ?? ''), 'lanes' => []];
            }
            $groups[$typeId]['lanes'][] = $rid;
        }
        return ['lanes' => $lanes, 'groups' => array_values($groups)];
    }

    /** Pure: the five housekeeping card counts, exactly as rh_cal_hk_tab() counts them. */
    function rh_ops_hk_counts(array $rooms, array $hk): array
    {
        $counts = ['dirty' => 0, 'cleaning' => 0, 'inspection' => 0, 'clean' => 0, 'blocked' => 0, 'unassigned' => 0, 'open' => 0];
        foreach ($rooms as $r) {
            $h = $hk[(int)$r['id']] ?? null;
            if (!$h) {
                continue;
            }
            $counts[$h['state']] = ($counts[$h['state']] ?? 0) + 1;
            $counts['open'] += (int)$h['open'];
            if ($h['task'] !== null && empty($h['task']['assigned_to'])) {
                $counts['unassigned']++;
            }
        }
        return $counts;
    }

    /** Pure: workflow status of a housekeeping row (completed + verified_at = verified), as the page reads it. */
    function rh_ops_hk_status(array $row): string
    {
        $status = (string)($row['status'] ?? '');
        $v = $row['verified_at'] ?? null;
        if ($status === 'completed' && $v !== null && $v !== '' && strpos((string)$v, '0000-00-00') !== 0) {
            return 'verified';
        }
        return $status;
    }

    /** Pure: plain words for a task / job type key (checkout_cleanup -> Checkout cleanup). */
    function rh_ops_words(string $key): string
    {
        $s = trim(str_replace('_', ' ', $key));
        return $s === '' ? '' : ucfirst($s);
    }

    /** Pure: the linked booking a drawer may show (guest name and stay dates only). */
    function rh_ops_booking_brief(?array $b): ?array
    {
        if (!$b) {
            return null;
        }
        return [
            'id' => (int)$b['id'],
            'ref' => (string)($b['booking_reference'] ?? ''),
            'guest' => (string)($b['guest_name'] ?? ''),
            'ci' => (string)($b['check_in_date'] ?? ''),
            'co' => (string)($b['check_out_date'] ?? ''),
            'status' => (string)($b['status'] ?? ''),
        ];
    }

    /**
     * Pure: housekeeping rows -> grid items. A task sits on its due date; an open task (pending / in progress /
     * blocked) due before $start or with no date is carried onto today's column when the range shows today.
     * Each item carries 'edit' = the keys editAssignment() on the page reads, so Edit opens the same modal.
     */
    function rh_ops_hk_items(array $rows, array $bookingsById, string $start, string $end, string $today): array
    {
        $todayIn = $today >= $start && $today <= $end;
        $items = [];
        foreach ($rows as $r) {
            $status = rh_ops_hk_status($r);
            $due = rh_ops_day($r['due_date'] ?? '');
            $open = in_array($status, ['pending', 'in_progress', 'blocked'], true);
            $day = $due;
            $carried = false;
            if ($due === '' || $due < $start) {
                if (!$open || !$todayIn) {
                    continue;
                }
                $day = $today;
                $carried = true;
            } elseif ($due > $end) {
                continue;
            }
            $bid = (int)($r['linked_booking_id'] ?? 0);
            $edit = [];
            foreach (['id', 'individual_room_id', 'assignment_type', 'priority', 'due_date', 'assigned_to', 'estimated_duration',
                'actual_duration', 'notes', 'is_recurring', 'recurring_pattern', 'recurring_end_date', 'scheduled_time', 'started_at'] as $k) {
                $edit[$k] = $r[$k] ?? null;
            }
            $edit['status'] = $status;
            $items[] = [
                'id' => (int)$r['id'],
                'room_id' => (int)$r['individual_room_id'],
                'room' => trim((string)($r['room_number'] ?? '') . ' ' . (string)($r['room_name'] ?? '')),
                'day' => $day,
                'due' => $due,
                'carried' => $carried,
                'overdue' => $open && $due !== '' && $due < $today,
                'status' => $status,
                'type' => (string)($r['assignment_type'] ?? ''),
                'type_label' => rh_ops_words((string)($r['assignment_type'] ?? '')) ?: 'Cleaning',
                'priority' => (string)($r['priority'] ?? ''),
                'assignee' => trim((string)($r['assigned_to_name'] ?? '')),
                'time' => rh_ops_time($r['scheduled_time'] ?? ''),
                'started' => rh_ops_time($r['started_at'] ?? ''),
                'completed' => rh_ops_time($r['completed_at'] ?? ''),
                'notes' => trim((string)($r['notes'] ?? '')),
                'booking' => $bid > 0 ? rh_ops_booking_brief($bookingsById[$bid] ?? null) : null,
                'edit' => $edit,
            ];
        }
        return $items;
    }

    /** Pure: workflow status of a maintenance row (planned / '' = pending, completed + verified_at = verified). */
    function rh_ops_maint_status(array $row): string
    {
        if (function_exists('rh_maint_status_from_db')) {
            return rh_maint_status_from_db($row['status'] ?? '', $row['verified_at'] ?? null);
        }
        $s = (string)($row['status'] ?? '');
        return ($s === '' || $s === 'planned') ? 'pending' : $s;
    }

    /** Pure: a job's nights [s, e]: From .. To minus one second (the calendar's span), never shorter than one night. */
    function rh_ops_job_span(array $row): array
    {
        $s = rh_ops_day($row['start_date'] ?? '');
        $endRaw = trim((string)($row['end_date'] ?? ''));
        $e = '';
        if ($endRaw !== '' && strpos($endRaw, '0000-00-00') !== 0) {
            $t = strtotime($endRaw);
            if ($t !== false) {
                $e = date('Y-m-d', $t - 1);
            }
        }
        if ($s === '') {
            $s = rh_ops_day($row['due_date'] ?? '');
        }
        if ($e === '' || $e < $s) {
            $e = $s;
        }
        return [$s, $e];
    }

    /**
     * Pure: which figure cards a job counts in (the Calendar's Maintenance cards, rh_cal_maint_tab): open = not done or
     * cancelled; progress = in progress; due = open and due (or starting, when no due date) by 6 days from today.
     * $status is the workflow status (rh_ops_maint_status). The list rows carry the same keys (data-ops-cards).
     */
    function rh_ops_maint_cards(array $row, string $status, string $today): array
    {
        $open = in_array($status, ['pending', 'planned', '', 'in_progress'], true); // raw DB 'planned' counts as pending
        $when = rh_ops_day($row['due_date'] ?? '') ?: rh_ops_day($row['start_date'] ?? '');
        $weekEnd = (new DateTime($today))->modify('+6 day')->format('Y-m-d');
        return [
            'open' => $open,
            'progress' => $status === 'in_progress',
            'due' => $open && $when !== '' && $when <= $weekEnd,
        ];
    }

    /**
     * Pure: maintenance rows -> grid items. Every job but cancelled ones, block_room 0 included; a done job (completed
     * / verified) only for 7 days after it was completed; an open job past its To date runs on to today.
     * $bookings + $heldRooms: live bookings over the jobs' dates, for "Affects bookings".
     * Each item carries 'edit' = the keys editSchedule() on the page reads.
     */
    function rh_ops_maint_items(array $rows, array $bookings, array $heldRooms, string $start, string $end, string $today): array
    {
        $doneFrom = (new DateTime($today))->modify('-7 day')->format('Y-m-d');
        $items = [];
        foreach ($rows as $r) {
            $status = rh_ops_maint_status($r);
            if ($status === 'cancelled') {
                continue;
            }
            [$s, $e] = rh_ops_job_span($r);
            if ($s === '') {
                continue;
            }
            $done = in_array($status, ['completed', 'verified'], true);
            if ($done) {
                $c = rh_ops_day($r['completed_at'] ?? '') ?: rh_ops_day($r['verified_at'] ?? '');
                if ($c === '' || $c < $doneFrom) {
                    continue;
                }
            }
            // No To date: an open job runs on to the range edge (drawn cut at the end), never "overdue".
            $endRaw = trim((string)($r['end_date'] ?? ''));
            $openEnd = !$done && ($endRaw === '' || strpos($endRaw, '0000-00-00') === 0);
            $overdue = !$done && !$openEnd && $e < $today;
            $drawE = $openEnd ? max($e, $end) : ($overdue ? $today : $e);
            if ($s > $end || $drawE < $start) {
                continue;
            }
            $rid = (int)$r['individual_room_id'];
            $affects = 0;
            $firstStay = '';
            foreach ($bookings as $b) {
                $held = rh_cal_booking_room_ids($b, $heldRooms);
                if (!isset($held[$rid])) {
                    continue;
                }
                $ci = (string)$b['check_in_date'];
                $eco = (string)($b['effective_check_out'] ?? $b['check_out_date']);
                if ($ci <= ($openEnd ? $drawE : $e) && $eco > $s) {
                    $affects++;
                    if ($firstStay === '' || $ci < $firstStay) {
                        $firstStay = $ci;
                    }
                }
            }
            $edit = [];
            foreach (['id', 'individual_room_id', 'title', 'description', 'maintenance_type', 'priority', 'due_date', 'assigned_to',
                'linked_booking_id', 'estimated_duration', 'actual_duration', 'block_room', 'start_date', 'end_date', 'is_recurring',
                'recurring_pattern', 'recurring_end_date', 'started_at'] as $k) {
                $edit[$k] = $r[$k] ?? null;
            }
            $edit['status'] = $status;
            $items[] = [
                'id' => (int)$r['id'],
                'room_id' => $rid,
                'room' => trim((string)($r['room_number'] ?? '') . ' ' . (string)($r['room_name'] ?? '')),
                'from' => $s,
                'to' => $e,
                'draw_to' => $drawE,
                'open_end' => $openEnd,
                'overdue' => $overdue,
                'status' => $status,
                'title' => (string)($r['title'] ?? ''),
                'type' => (string)($r['maintenance_type'] ?? ''),
                'type_label' => rh_ops_words((string)($r['maintenance_type'] ?? '')),
                'priority' => (string)($r['priority'] ?? ''),
                'blocks' => (int)($r['block_room'] ?? 0) === 1,
                'assignee' => trim((string)($r['assigned_to_name'] ?? '')),
                'reporter' => trim((string)($r['created_by_name'] ?? '')),
                'notes' => trim((string)($r['description'] ?? '')),
                'started' => rh_ops_time($r['started_at'] ?? ''),
                'completed' => rh_ops_day($r['completed_at'] ?? ''),
                'affects' => $affects,
                'first_stay' => $firstStay,
                'cards' => rh_ops_maint_cards($r, $status, $today),
                'edit' => $edit,
            ];
        }
        return $items;
    }

    /** Housekeeping tasks the grid may draw for [$start, $end] (+ open tasks due earlier or undated, for carrying). */
    function rh_ops_fetch_hk_rows(PDO $pdo, string $start, string $end): array
    {
        try {
            $st = $pdo->prepare("
                SELECT ha.*, ir.room_number, ir.room_name, u.username AS assigned_to_name
                FROM housekeeping_assignments ha
                LEFT JOIN individual_rooms ir ON ir.id = ha.individual_room_id
                LEFT JOIN admin_users u ON u.id = ha.assigned_to
                WHERE (ha.due_date BETWEEN ? AND ?)
                   OR (ha.status IN ('pending', 'in_progress', 'blocked') AND (ha.due_date IS NULL OR ha.due_date < ?))
                ORDER BY ha.due_date ASC, ha.id ASC
            ");
            $st->execute([$start, $end, $start]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Maintenance jobs that may touch [$start, $end]: every job starting on or before $end that is still open, or
     * whose nights reach $start, or that was completed in the last 7 days. block_room is NOT filtered.
     */
    function rh_ops_fetch_maint_rows(PDO $pdo, string $start, string $end): array
    {
        try {
            $st = $pdo->prepare("
                SELECT rms.*, ir.room_number, ir.room_name, u.username AS assigned_to_name, creator.username AS created_by_name
                FROM room_maintenance_schedules rms
                LEFT JOIN individual_rooms ir ON ir.id = rms.individual_room_id
                LEFT JOIN admin_users u ON u.id = rms.assigned_to
                LEFT JOIN admin_users creator ON creator.id = rms.created_by
                WHERE COALESCE(rms.status, '') <> 'cancelled'
                  AND DATE(rms.start_date) <= ?
                  AND (COALESCE(rms.status, '') NOT IN ('completed', 'verified')
                       OR DATE(DATE_SUB(rms.end_date, INTERVAL 1 SECOND)) >= ?
                       OR rms.completed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY))
                ORDER BY rms.start_date ASC, rms.id ASC
            ");
            $st->execute([$end, $start]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /** booking id => row (guest name, reference, dates, status) for the bookings tasks are linked to. */
    function rh_ops_fetch_bookings_by_id(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $pdo->prepare("SELECT id, booking_reference, guest_name, check_in_date, check_out_date, status
                                 FROM bookings WHERE deleted_at IS NULL AND id IN ($in)");
            $st->execute($ids);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $out[(int)$b['id']] = $b;
            }
            return $out;
        } catch (PDOException $e) {
            return [];
        }
    }

    /** The day a task / job is drawn from (for a deep link outside the default range), or null when it does not exist. */
    function rh_ops_item_date(PDO $pdo, string $mode, int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }
        if ($mode === 'service') {
            return rh_ops_rs_item_date($pdo, $id);
        }
        try {
            if ($mode === 'housekeeping') {
                $st = $pdo->prepare("SELECT * FROM housekeeping_assignments WHERE id = ?");
                $st->execute([$id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) {
                    return null;
                }
                // An open task from a past day (or with no date) is drawn on today: no need to move the range.
                $due = rh_ops_day($r['due_date'] ?? '');
                if (in_array(rh_ops_hk_status($r), ['pending', 'in_progress', 'blocked'], true) && ($due === '' || $due < date('Y-m-d'))) {
                    return null;
                }
                return $due ?: null;
            }
            $st = $pdo->prepare("SELECT start_date, due_date FROM room_maintenance_schedules WHERE id = ?");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return null;
            }
            return rh_ops_day($r['start_date'] ?? '') ?: (rh_ops_day($r['due_date'] ?? '') ?: null);
        } catch (PDOException $e) {
            return null;
        }
    }

    /** Pure: what the signed-in user may open from the grid (links only; actions are the page's own forms). */
    function rh_ops_can(int $uid): array
    {
        if ($uid <= 0 || !function_exists('rhCanLinkTo')) {
            return ['calendar' => false];
        }
        return ['calendar' => rhCanLinkTo($uid, 'calendar.php')];
    }

    /**
     * The whole grid for $days days from $start. $mode: housekeeping | maintenance | service (service: one day, $days
     * ignored, see rh_ops_rs_payload). $opts: user_id, today (tests).
     * Returns ['ok', 'mode', 'start', 'end', 'days', 'today', 'dates', 'groups', 'lanes', 'items', 'counts', 'can', 'error'].
     */
    function rh_ops_payload(PDO $pdo, string $mode, string $start, int $days, array $opts = []): array
    {
        if ($mode === 'service') {
            return rh_ops_rs_payload($pdo, $start, $opts); // one day of room service orders (block below)
        }
        $mode = $mode === 'maintenance' ? 'maintenance' : 'housekeeping';
        $days = max(3, min(31, $days));
        $today = rh_cal_valid_date($opts['today'] ?? '') ?? date('Y-m-d');
        $uid = (int)($opts['user_id'] ?? 0);
        $range = rh_ops_dates($start, $days, $today);
        $dates = $range['dates'];
        $end = $dates[count($dates) - 1];
        $error = null;

        try {
            $rooms = rh_cal_fetch_rooms($pdo);
        } catch (PDOException $e) {
            $rooms = [];
            $error = 'Some rooms could not be loaded.';
        }
        $roomsById = [];
        foreach ($rooms as $r) {
            $roomsById[(int)$r['id']] = $r;
        }
        $hk = rh_cal_hk_rooms($pdo, $roomsById, $today);

        if ($mode === 'housekeeping') {
            $bookings = [];
            $held = [];
            try {
                $bookings = rh_cal_fetch_bookings($pdo, $start, $end);
                $held = rh_cal_fetch_held_rooms($pdo, array_column($bookings, 'id'));
            } catch (PDOException $e) {
                $error = 'Guest stays could not be loaded.';
            }
            $built = rh_ops_lanes($rooms, $hk, rh_ops_stay_nights($bookings, $held, $dates));
            $rows = rh_ops_fetch_hk_rows($pdo, $start, $end);
            $linked = rh_ops_fetch_bookings_by_id($pdo, array_column($rows, 'linked_booking_id'));
            $items = rh_ops_hk_items($rows, $linked, $start, $end, $today);
            $counts = rh_ops_hk_counts($rooms, $hk);
        } else {
            $built = rh_ops_lanes($rooms, $hk, []);
            $rows = rh_ops_fetch_maint_rows($pdo, $start, $end);
            $from = $start;
            $to = max($end, $today);
            foreach ($rows as $r) {
                [$s, $e] = rh_ops_job_span($r);
                if ($s !== '' && $s < $from) {
                    $from = $s;
                }
                if ($e !== '' && $e > $to) {
                    $to = $e;
                }
            }
            $bookings = [];
            $held = [];
            if ($rows) {
                try {
                    $bookings = rh_cal_fetch_bookings($pdo, $from, $to);
                    $held = rh_cal_fetch_held_rooms($pdo, array_column($bookings, 'id'));
                } catch (PDOException $e) {
                    $error = 'Guest stays could not be loaded.';
                }
            }
            $items = rh_ops_maint_items($rows, $bookings, $held, $start, $end, $today);
            $counts = rh_cal_maint_tab($pdo, '', $today)['counts'];
        }

        return [
            'ok' => true,
            'mode' => $mode,
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'today' => $today,
            'dates' => $range['info'],
            'groups' => $built['groups'],
            'lanes' => $built['lanes'],
            'items' => $items,
            'counts' => $counts,
            'can' => rh_ops_can($uid),
            'error' => $error,
        ];
    }
}

/*
 * Room Service (admin/room-service-dashboard.php, CALENDAR_UI_DECISION 10.5): one day of room service orders by room
 * and hour. Read-only. Rules:
 *  - a bar = one order (stock_orders.order_type = 'room_service') from its placed time (created_at) to its delivered
 *    time (served_at), or to "now" while it is still open; ready time = the last line's ready_at;
 *  - state, from the order's kitchen_status exactly as the page's Active queue reads it: open = not served and not
 *    cancelled / voided (ready / collection -> Ready; in_progress / recalled -> Preparing; else New); served ->
 *    Delivered; cancelled / voided -> Cancelled (drawn only when the status filter asks for it);
 *  - an order still open from an earlier day is carried onto TODAY's grid (cut at midnight), so the grid agrees with
 *    the Active queue and the In progress card, which count open orders of any day;
 *  - late = still open more than RH_OPS_RS_LATE_SECONDS after it reached the station (fired_at, else created_at):
 *    the 10 minutes after which admin/kds.php elapsedClass() marks a ticket late. No new setting;
 *  - the room: the order's individual_room_id, else its room number / "Rm <number>" label, else a "No room" lane.
 */
if (!function_exists('rh_ops_rs_payload')) {

    if (!defined('RH_OPS_RS_LATE_SECONDS')) {
        define('RH_OPS_RS_LATE_SECONDS', 600); // same as admin/kds.php elapsedClass(): a ticket is late after 600 s
    }

    /** Pure: the endpoint's day. Missing start = today; anything not a real Y-m-d date string = null. */
    function rh_ops_rs_request_day(array $get, string $today): ?string
    {
        if (!array_key_exists('start', $get)) {
            return $today;
        }
        if (!is_string($get['start'])) {
            return null;
        }
        return rh_cal_valid_date($get['start']);
    }

    /** Pure: the order's state for the grid, cards and filters. */
    function rh_ops_rs_state(array $row): string
    {
        $status = (string)($row['status'] ?? '');
        if (in_array($status, ['cancelled', 'voided'], true)) {
            return 'cancelled';
        }
        $ks = (string)($row['kitchen_status'] ?? '');
        if ($ks === 'served') {
            return 'delivered';
        }
        if ($ks === 'ready' || $ks === 'collection') {
            return 'ready';
        }
        if ($ks === 'in_progress' || $ks === 'recalled') {
            return 'preparing';
        }
        return 'new';
    }

    /** Pure: a quantity without trailing zeros (2, 1.5). */
    function rh_ops_rs_qty($q): string
    {
        $s = rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');
        return ($s === '' || $s === '-0') ? '0' : $s;
    }

    /** Pure: a room label without its "Rm " / "Room " prefix. */
    function rh_ops_rs_room_number(string $label): string
    {
        return trim((string)preg_replace('/^\s*(?:rm|room)\b\.?\s*/i', '', $label));
    }

    /** Pure: the lane (individual room id) an order belongs to; 0 = the "No room" lane. */
    function rh_ops_rs_lane_for(array $row, array $byId, array $byNumber): int
    {
        $rid = (int)($row['individual_room_id'] ?? 0);
        if ($rid > 0 && isset($byId[$rid])) {
            return $rid;
        }
        foreach ([(string)($row['room_number'] ?? ''), (string)($row['table_number'] ?? '')] as $label) {
            $num = rh_ops_rs_room_number($label);
            if ($num !== '' && isset($byNumber[$num])) {
                return $byNumber[$num];
            }
        }
        return 0;
    }

    /**
     * Pure: the day's bars. $rows = order rows (rh_ops_rs_fetch_orders), $lines = order id => item lines, $lanes = the
     * payload lanes, $day = the day shown, $now = 'Y-m-d H:i:s' (server clock), $currency = symbol for the total.
     * Minutes count from the day's midnight: from / to are 0..1440; cut_s / cut_e say the order runs past them.
     */
    function rh_ops_rs_items(array $rows, array $lines, array $lanes, string $day, string $now, string $currency): array
    {
        $byId = [];
        $byNumber = [];
        foreach ($lanes as $l) {
            if ((int)$l['id'] <= 0) {
                continue;
            }
            $byId[(int)$l['id']] = true;
            if (!isset($byNumber[(string)$l['number']])) {
                $byNumber[(string)$l['number']] = (int)$l['id'];
            }
        }
        $dayStart = strtotime($day . ' 00:00:00');
        $nowTs = strtotime($now);
        $today = substr($now, 0, 10);
        $hm = static function ($v): string {
            $t = $v ? strtotime((string)$v) : false;
            return $t ? date('H:i', $t) : '';
        };
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $state = rh_ops_rs_state($r);
            $open = in_array($state, ['new', 'preparing', 'ready'], true);
            $startTs = strtotime((string)($r['created_at'] ?? ''));
            if ($startTs === false || empty($r['created_at'])) {
                continue;
            }
            $served = (string)($r['served_at'] ?? '');
            if ($state === 'delivered' && $served !== '') {
                $endTs = strtotime($served) ?: $startTs;
            } elseif ($open) {
                $endTs = $nowTs;
            } else {
                $endTs = $startTs; // cancelled, or delivered with no time: a short mark at its placed time
            }
            $from = (int)floor(($startTs - $dayStart) / 60);
            $to = (int)floor(($endTs - $dayStart) / 60);
            $carried = $open && $day === $today;
            if ($from >= 1440 || ($to < 0 && !$carried)) {
                continue; // not on this day
            }
            $cutS = $from < 0;
            $cutE = false;
            if ($open && $day < $today) {
                $to = 1440; // still open: it ran on past midnight
                $cutE = true;
            }
            if ($to > 1440) {
                $to = 1440;
                $cutE = true;
            }
            $from = max(0, $from);
            $to = max($from, $to);

            $units = 0.0;
            $readyAt = '';
            $list = [];
            foreach ($lines[$id] ?? [] as $ln) {
                $units += (float)$ln['quantity'];
                if (!empty($ln['ready_at']) && (string)$ln['ready_at'] > $readyAt) {
                    $readyAt = (string)$ln['ready_at'];
                }
                $list[] = ['name' => (string)$ln['item_name'], 'qty' => rh_ops_rs_qty($ln['quantity']),
                    'notes' => trim((string)($ln['notes'] ?? ''))];
            }
            $clockFrom = strtotime((string)(!empty($r['fired_at']) ? $r['fired_at'] : $r['created_at'])) ?: $startTs;
            $roomLabel = trim((string)($r['room_number'] ?? ''));
            if ($roomLabel === '') {
                $roomLabel = rh_ops_rs_room_number((string)($r['table_number'] ?? ''));
            }
            $cards = [
                'new' => $state === 'new',
                'preparing' => $state === 'preparing',
                'ready' => $state === 'ready',
                'delivered' => $state === 'delivered' && $served !== '' && substr($served, 0, 10) === $today,
            ];
            $out[] = [
                'id' => $id,
                'ref' => (string)($r['reference'] ?? ''),
                'room_id' => rh_ops_rs_lane_for($r, $byId, $byNumber),
                'room' => $roomLabel,
                'guest' => trim((string)($r['customer_name'] ?? '')),
                'state' => $state,
                'status' => $state,
                'open' => $open,
                'day' => $day,
                'from' => $from,
                'to' => $to,
                'cut_s' => $cutS,
                'cut_e' => $cutE,
                'running' => $carried,
                'placed' => $hm($r['created_at']),
                'placed_day' => rh_ops_day($r['created_at']),
                'ready' => ($readyAt !== '' && in_array($state, ['ready', 'delivered'], true)) ? $hm($readyAt) : '',
                'delivered' => $state === 'delivered' ? $hm($served) : '',
                'lines' => $list,
                'count' => rh_ops_rs_qty($units) . ((float)$units === 1.0 ? ' item' : ' items'),
                'notes' => trim((string)($r['notes'] ?? '')),
                'total' => trim($currency . ' ' . number_format((float)($r['total_amount'] ?? 0), 2)),
                'charged' => !empty($r['folio_posted_at']),
                'overdue' => $open && ($nowTs - $clockFrom) > RH_OPS_RS_LATE_SECONDS,
                'priority' => '',
                'assignee' => '',
                'cards' => $cards,
            ];
        }
        return $out;
    }

    /** The day's orders: placed that day, delivered that day, and (when the day is today) every order still open. */
    function rh_ops_rs_fetch_orders(PDO $pdo, string $day, string $today): array
    {
        $next = date('Y-m-d', strtotime($day . ' +1 day'));
        $carry = $day === $today
            ? " OR (o.kitchen_status <> 'served' AND o.status NOT IN ('cancelled', 'voided'))"
            : '';
        try {
            $st = $pdo->prepare("
                SELECT o.id, o.reference, o.individual_room_id, o.table_number, o.room_number, o.customer_name,
                       o.notes, o.status, o.kitchen_status, o.total_amount, o.folio_posted_at, o.created_at, o.fired_at,
                       o.served_at
                FROM stock_orders o
                WHERE o.order_type = 'room_service'
                  AND o.created_at < ?
                  AND (o.created_at >= ? OR o.served_at >= ?" . $carry . ")
                ORDER BY o.created_at ASC, o.id ASC
                LIMIT 500
            ");
            $st->execute([$next . ' 00:00:00', $day . ' 00:00:00', $day . ' 00:00:00']);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /** order id => its item lines (voided lines left out), in order. */
    function rh_ops_rs_fetch_lines(PDO $pdo, array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $pdo->prepare("SELECT order_id, item_name, quantity, notes, kds_status, ready_at
                                 FROM stock_order_items WHERE order_id IN (" . $in . ") AND kds_status <> 'void' ORDER BY id ASC");
            $st->execute($ids);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ln) {
                $out[(int)$ln['order_id']][] = $ln;
            }
            return $out;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * The four figure cards over the same set as the page's Active queue and In progress card (open = not served,
     * not cancelled / voided, any day): new + preparing + ready = In progress. delivered = served today.
     */
    function rh_ops_rs_counts(PDO $pdo, string $today): array
    {
        $counts = ['new' => 0, 'preparing' => 0, 'ready' => 0, 'delivered' => 0];
        try {
            $st = $pdo->query("SELECT status, kitchen_status FROM stock_orders
                               WHERE order_type = 'room_service' AND kitchen_status <> 'served'
                                 AND status NOT IN ('cancelled', 'voided')");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $counts[rh_ops_rs_state($r)]++;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM stock_orders
                                 WHERE order_type = 'room_service' AND kitchen_status = 'served'
                                   AND status NOT IN ('cancelled', 'voided') AND served_at >= ? AND served_at < ?");
            $st->execute([$today . ' 00:00:00', date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00']);
            $counts['delivered'] = (int)$st->fetchColumn();
        } catch (PDOException $e) {
            // cards show 0; the grid still loads
        }
        return $counts;
    }

    /** The day an order is drawn on for a deep link (?id=): null when it is not a room service order or still open. */
    function rh_ops_rs_item_date(PDO $pdo, int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $st = $pdo->prepare("SELECT created_at, status, kitchen_status FROM stock_orders WHERE id = ? AND order_type = 'room_service'");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null;
        }
        if (!$r) {
            return null;
        }
        if (in_array(rh_ops_rs_state($r), ['new', 'preparing', 'ready'], true)) {
            return null; // still open: it is on today's grid
        }
        return rh_ops_day($r['created_at'] ?? '') ?: null;
    }

    /**
     * One day of room service. $opts: user_id, today, now ('Y-m-d H:i:s', tests). Same keys as rh_ops_payload plus
     * 'now' (minutes since midnight when the day is today, else null) and 'late_minutes'. can.manage = the page's
     * room_service_manage permission (the Delivered button; the page's POST handler checks it again).
     */
    function rh_ops_rs_payload(PDO $pdo, string $day, array $opts = []): array
    {
        $now = (isset($opts['now']) && is_string($opts['now']) && strtotime($opts['now']) !== false)
            ? date('Y-m-d H:i:s', strtotime($opts['now'])) : date('Y-m-d H:i:s');
        $today = rh_cal_valid_date($opts['today'] ?? '') ?? substr($now, 0, 10);
        $uid = (int)($opts['user_id'] ?? 0);
        $day = rh_cal_valid_date($day) ?? $today;
        $error = null;

        try {
            $rooms = rh_cal_fetch_rooms($pdo);
        } catch (PDOException $e) {
            $rooms = [];
            $error = 'Some rooms could not be loaded.';
        }
        $built = rh_ops_lanes($rooms, [], []);
        $rows = rh_ops_rs_fetch_orders($pdo, $day, $today);
        $lines = rh_ops_rs_fetch_lines($pdo, array_column($rows, 'id'));
        $currency = function_exists('getSetting') ? (string)(getSetting('currency_symbol') ?: 'MWK') : 'MWK';
        $items = rh_ops_rs_items($rows, $lines, $built['lanes'], $day, $now, $currency);
        $lanes = $built['lanes'];
        $groups = $built['groups'];
        if (in_array(0, array_column($items, 'room_id'), true)) {
            $lanes[] = ['id' => 0, 'type_id' => 0, 'number' => 'No room', 'name' => '', 'floor' => '', 'status' => '',
                'out' => false, 'hk' => ['state' => 'clean', 'label' => ''], 'unassigned' => false, 'stay' => []];
            $groups[] = ['id' => 0, 'name' => 'Orders with no room linked', 'lanes' => [0]];
        }
        $can = rh_ops_can($uid);
        $can['manage'] = $uid > 0 && function_exists('hasPermission') && hasPermission($uid, 'room_service_manage');

        return [
            'ok' => true,
            'mode' => 'service',
            'start' => $day,
            'end' => $day,
            'days' => 1,
            'today' => $today,
            'dates' => rh_ops_dates($day, 1, $today)['info'],
            'groups' => $groups,
            'lanes' => $lanes,
            'items' => $items,
            'counts' => rh_ops_rs_counts($pdo, $today),
            'can' => $can,
            'now' => $day === $today ? (int)floor((strtotime($now) - strtotime($today . ' 00:00:00')) / 60) : null,
            'late_minutes' => (int)(RH_OPS_RS_LATE_SECONDS / 60),
            'error' => $error,
        ];
    }
}
