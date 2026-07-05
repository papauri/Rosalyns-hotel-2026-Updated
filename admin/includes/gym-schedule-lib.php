<?php

/**
 * Gym Calendar & Slot Booking — shared library.
 *
 * Powers both the public schedule page (gym-schedule.php) and the admin day
 * view (admin/gym-schedule.php). No admin-only dependencies, so it is safe to
 * include from the front end. All functions fail soft when the tables are
 * missing so a fresh install never fatals before the schema self-heals.
 *
 * Concepts:
 *   - Opening hours live per weekday in gym_hours (0=Sun .. 6=Sat).
 *   - A "slot" is a fixed-length window (default 60 min) between open/close.
 *   - Each slot has a floor capacity; reservations count party_size against it
 *     to prevent overcrowding.
 */

if (!function_exists('gymScheduleEnsureTables')) {
    function gymScheduleEnsureTables(PDO $pdo): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS gym_hours (
                day_of_week TINYINT NOT NULL COMMENT '0=Sunday .. 6=Saturday',
                is_open TINYINT(1) NOT NULL DEFAULT 1,
                open_time TIME NOT NULL DEFAULT '06:00:00',
                close_time TIME NOT NULL DEFAULT '21:00:00',
                PRIMARY KEY (day_of_week)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Gym opening hours per weekday'");
            $pdo->exec("CREATE TABLE IF NOT EXISTS gym_slot_reservations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference VARCHAR(20) NOT NULL,
                slot_date DATE NOT NULL,
                slot_time TIME NOT NULL,
                member_id INT UNSIGNED NULL,
                member_number VARCHAR(32) NULL,
                full_name VARCHAR(255) NOT NULL,
                phone VARCHAR(50) NULL,
                email VARCHAR(255) NULL,
                party_size INT NOT NULL DEFAULT 1,
                status ENUM('booked','cancelled','attended','no_show') NOT NULL DEFAULT 'booked',
                source VARCHAR(20) NOT NULL DEFAULT 'public',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ref (reference),
                KEY idx_slot (slot_date, slot_time, status),
                KEY idx_member (member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Gym floor time-slot reservations'");
            // Seed weekday hours once if empty.
            $count = (int)$pdo->query("SELECT COUNT(*) FROM gym_hours")->fetchColumn();
            if ($count === 0) {
                $ins = $pdo->prepare("INSERT INTO gym_hours (day_of_week,is_open,open_time,close_time) VALUES (?,1,'06:00:00','21:00:00')");
                for ($d = 0; $d < 7; $d++) { $ins->execute([$d]); }
            }
            $ok = true;
        } catch (Throwable $e) {
            error_log('gymScheduleEnsureTables failed: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('gymScheduleSettings')) {
    /**
     * @return array{enabled:bool, capacity:int, duration:int, advance_days:int}
     */
    function gymScheduleSettings(): array
    {
        $enabled = in_array(getSetting('gym_slot_booking_enabled', '0'), ['1', 1, true, 'true', 'on'], true);
        return [
            'enabled'      => $enabled,
            'capacity'     => max(1, (int)getSetting('gym_slot_capacity', 20)),
            'duration'     => max(15, (int)getSetting('gym_slot_duration_minutes', 60)),
            'advance_days' => max(0, min(60, (int)getSetting('gym_slot_advance_days', 7))),
        ];
    }
}

if (!function_exists('gymScheduleHours')) {
    /**
     * All seven weekday rows keyed by day_of_week (0=Sun..6=Sat).
     */
    function gymScheduleHours(PDO $pdo): array
    {
        if (!gymScheduleEnsureTables($pdo)) return [];
        $rows = [];
        try {
            foreach ($pdo->query("SELECT day_of_week, is_open, open_time, close_time FROM gym_hours")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['day_of_week']] = [
                    'is_open'    => (int)$r['is_open'] === 1,
                    'open_time'  => substr((string)$r['open_time'], 0, 5),
                    'close_time' => substr((string)$r['close_time'], 0, 5),
                ];
            }
        } catch (Throwable $e) { /* fail soft */ }
        // Fill any gaps with a sensible default so callers always get 7 days.
        for ($d = 0; $d < 7; $d++) {
            if (!isset($rows[$d])) {
                $rows[$d] = ['is_open' => true, 'open_time' => '06:00', 'close_time' => '21:00'];
            }
        }
        ksort($rows);
        return $rows;
    }
}

if (!function_exists('gymScheduleDayClasses')) {
    /**
     * Active gym classes that fall on a given date's weekday (best-effort:
     * matches gym_classes.day_label against the weekday name, e.g. "Monday").
     * Returned keyed by HH:00 hour when a start time can be parsed.
     */
    function gymScheduleDayClasses(PDO $pdo, string $date): array
    {
        $out = [];
        try {
            $weekday = date('l', strtotime($date)); // e.g. "Monday"
            $stmt = $pdo->query("SELECT title, time_label, day_label, level_label FROM gym_classes WHERE is_active = 1");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $dayLabel = (string)($c['day_label'] ?? '');
                // Match "Monday", "Mon", "Mon & Wed", "Daily", etc.
                $matches = $dayLabel === '' || stripos($dayLabel, $weekday) !== false
                    || stripos($dayLabel, substr($weekday, 0, 3)) !== false
                    || stripos($dayLabel, 'daily') !== false || stripos($dayLabel, 'everyday') !== false;
                if (!$matches) continue;
                $hourKey = null;
                if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', (string)($c['time_label'] ?? ''), $m)) {
                    $h = (int)$m[1];
                    $ap = strtolower($m[3] ?? '');
                    if ($ap === 'pm' && $h < 12) $h += 12;
                    if ($ap === 'am' && $h === 12) $h = 0;
                    $hourKey = sprintf('%02d:00', $h);
                }
                $entry = ['title' => (string)$c['title'], 'time_label' => (string)($c['time_label'] ?? ''), 'level' => (string)($c['level_label'] ?? '')];
                if ($hourKey !== null) { $out[$hourKey][] = $entry; }
                else { $out['_unscheduled'][] = $entry; }
            }
        } catch (Throwable $e) { /* classes optional */ }
        return $out;
    }
}

if (!function_exists('gymScheduleGenerateSlots')) {
    /**
     * Build the slot grid for a date: one entry per slot window between the
     * weekday's open and close time.
     *
     * @return array<int,array{time:string,label:string,capacity:int,booked:int,remaining:int,is_full:bool,is_past:bool,classes:array}>
     */
    function gymScheduleGenerateSlots(PDO $pdo, string $date): array
    {
        if (!gymScheduleEnsureTables($pdo)) return [];
        $cfg = gymScheduleSettings();
        $hours = gymScheduleHours($pdo);
        $dow = (int)date('w', strtotime($date)); // 0=Sun..6=Sat
        $day = $hours[$dow] ?? ['is_open' => false, 'open_time' => '06:00', 'close_time' => '21:00'];
        if (empty($day['is_open'])) return [];

        $startTs = strtotime($date . ' ' . $day['open_time']);
        $endTs   = strtotime($date . ' ' . $day['close_time']);
        if ($startTs === false || $endTs === false || $endTs <= $startTs) return [];

        $step = $cfg['duration'] * 60;
        // Booked counts for the whole day in one query.
        $counts = [];
        try {
            $cs = $pdo->prepare("SELECT slot_time, COALESCE(SUM(party_size),0) AS booked
                FROM gym_slot_reservations
                WHERE slot_date = ? AND status IN ('booked','attended')
                GROUP BY slot_time");
            $cs->execute([$date]);
            foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $counts[substr((string)$r['slot_time'], 0, 5)] = (int)$r['booked'];
            }
        } catch (Throwable $e) { /* fail soft */ }

        $classes = gymScheduleDayClasses($pdo, $date);
        $now = time();
        $slots = [];
        for ($ts = $startTs; $ts + $step <= $endTs + 1; $ts += $step) {
            $time = date('H:i', $ts);
            $booked = $counts[$time] ?? 0;
            $remaining = max(0, $cfg['capacity'] - $booked);
            $slots[] = [
                'time'      => $time,
                'label'     => date('g:i A', $ts) . ' – ' . date('g:i A', $ts + $step),
                'capacity'  => $cfg['capacity'],
                'booked'    => $booked,
                'remaining' => $remaining,
                'is_full'   => $remaining <= 0,
                'is_past'   => ($ts + $step) <= $now,
                'classes'   => $classes[$time] ?? [],
            ];
        }
        return $slots;
    }
}

if (!function_exists('gymScheduleReference')) {
    function gymScheduleReference(PDO $pdo): string
    {
        do {
            $ref = 'GS-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $chk = $pdo->prepare("SELECT COUNT(*) FROM gym_slot_reservations WHERE reference = ?");
            $chk->execute([$ref]);
        } while ((int)$chk->fetchColumn() > 0);
        return $ref;
    }
}

if (!function_exists('gymScheduleCreateReservation')) {
    /**
     * Validate + create a slot reservation with capacity enforcement.
     *
     * @param array $data keys: slot_date, slot_time, full_name, phone, email,
     *                    party_size, member_id, member_number, source
     * @return array{ok:bool, message:string, reference?:string}
     */
    function gymScheduleCreateReservation(PDO $pdo, array $data): array
    {
        if (!gymScheduleEnsureTables($pdo)) {
            return ['ok' => false, 'message' => 'Slot booking is not available right now.'];
        }
        $cfg = gymScheduleSettings();
        if (!$cfg['enabled']) {
            return ['ok' => false, 'message' => 'Slot booking is currently turned off.'];
        }

        $date = trim((string)($data['slot_date'] ?? ''));
        $time = trim((string)($data['slot_time'] ?? ''));
        $name = trim((string)($data['full_name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $party = max(1, (int)($data['party_size'] ?? 1));
        $source = (string)($data['source'] ?? 'public');

        if ($name === '' || mb_strlen($name) > 255) {
            return ['ok' => false, 'message' => 'Please enter your name.'];
        }
        if ($phone === '' && $email === '') {
            return ['ok' => false, 'message' => 'Please provide a phone number or email so we can reach you.'];
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Enter a valid email or leave it blank.'];
        }
        if ($party < 1 || $party > 10) {
            return ['ok' => false, 'message' => 'Party size must be between 1 and 10.'];
        }
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) {
            return ['ok' => false, 'message' => 'Choose a valid date.'];
        }
        // Within the bookable window (today .. today+advance_days).
        $today = new DateTime('today');
        $maxDate = (clone $today)->modify('+' . $cfg['advance_days'] . ' days');
        $dateOnly = DateTime::createFromFormat('Y-m-d', $date); $dateOnly->setTime(0, 0);
        if ($dateOnly < $today || $dateOnly > $maxDate) {
            return ['ok' => false, 'message' => 'That date is outside the bookable window.'];
        }

        // Must be a real slot for that day (validates against opening hours).
        $slots = gymScheduleGenerateSlots($pdo, $date);
        $slot = null;
        foreach ($slots as $s) { if ($s['time'] === substr($time, 0, 5)) { $slot = $s; break; } }
        if ($slot === null) {
            return ['ok' => false, 'message' => 'That time slot is not available on the selected day.'];
        }
        if ($slot['is_past']) {
            return ['ok' => false, 'message' => 'That time slot has already passed.'];
        }
        if ($party > $slot['remaining']) {
            return ['ok' => false, 'message' => $slot['remaining'] > 0
                ? ('Only ' . $slot['remaining'] . ' space' . ($slot['remaining'] === 1 ? '' : 's') . ' left in that slot.')
                : 'That slot is fully booked. Please choose another time.'];
        }

        // Optional member link by member number.
        $memberId = null;
        $memberNumber = trim((string)($data['member_number'] ?? ''));
        if ($memberNumber !== '') {
            try {
                $ms = $pdo->prepare("SELECT id FROM gym_members WHERE member_number = ? LIMIT 1");
                $ms->execute([$memberNumber]);
                $mid = $ms->fetchColumn();
                if ($mid !== false) { $memberId = (int)$mid; }
            } catch (Throwable $e) { /* members optional */ }
        }
        if (isset($data['member_id']) && (int)$data['member_id'] > 0) {
            $memberId = (int)$data['member_id'];
        }

        // Prevent obvious duplicates: same name+slot already booked.
        try {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM gym_slot_reservations
                WHERE slot_date = ? AND slot_time = ? AND status IN ('booked','attended')
                  AND (LOWER(full_name) = LOWER(?) OR (member_number IS NOT NULL AND member_number = ?))");
            $dup->execute([$date, $slot['time'] . ':00', $name, $memberNumber !== '' ? $memberNumber : '__none__']);
            if ((int)$dup->fetchColumn() > 0) {
                return ['ok' => false, 'message' => 'You already have a reservation for that slot.'];
            }
        } catch (Throwable $e) { /* best-effort */ }

        try {
            $ref = gymScheduleReference($pdo);
            $ins = $pdo->prepare("INSERT INTO gym_slot_reservations
                (reference, slot_date, slot_time, member_id, member_number, full_name, phone, email, party_size, status, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked', ?)");
            $ins->execute([
                $ref, $date, $slot['time'] . ':00', $memberId, $memberNumber ?: null,
                $name, $phone ?: null, $email ?: null, $party, $source,
            ]);
            return ['ok' => true, 'message' => 'Reserved.', 'reference' => $ref];
        } catch (Throwable $e) {
            error_log('gymScheduleCreateReservation failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save your reservation. Please try again.'];
        }
    }
}

if (!function_exists('gymScheduleCancelReservation')) {
    /**
     * Cancel by reference (public) or by id (admin). $byReference true => match reference.
     */
    function gymScheduleCancelReservation(PDO $pdo, string $key, bool $byReference = true): array
    {
        if (!gymScheduleEnsureTables($pdo)) return ['ok' => false, 'message' => 'Unavailable.'];
        try {
            $col = $byReference ? 'reference' : 'id';
            $stmt = $pdo->prepare("UPDATE gym_slot_reservations SET status = 'cancelled' WHERE $col = ? AND status = 'booked'");
            $stmt->execute([$key]);
            if ($stmt->rowCount() > 0) {
                return ['ok' => true, 'message' => 'Reservation cancelled.'];
            }
            return ['ok' => false, 'message' => 'Reservation not found or already cancelled.'];
        } catch (Throwable $e) {
            error_log('gymScheduleCancelReservation failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not cancel. Please try again.'];
        }
    }
}

if (!function_exists('gymScheduleDayReservations')) {
    /**
     * All reservations for a date grouped by HH:MM slot time (admin day view).
     */
    function gymScheduleDayReservations(PDO $pdo, string $date): array
    {
        if (!gymScheduleEnsureTables($pdo)) return [];
        $out = [];
        try {
            $stmt = $pdo->prepare("SELECT id, reference, slot_time, member_number, full_name, phone, email, party_size, status, source, created_at
                FROM gym_slot_reservations
                WHERE slot_date = ? AND status <> 'cancelled'
                ORDER BY slot_time ASC, created_at ASC");
            $stmt->execute([$date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[substr((string)$r['slot_time'], 0, 5)][] = $r;
            }
        } catch (Throwable $e) { /* fail soft */ }
        return $out;
    }
}
