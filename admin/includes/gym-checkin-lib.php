<?php

/**
 * Gym check-in / check-out decision logic.
 *
 * Shared by admin/gym-checkin.php (scanner page) and admin/api/gym-checkin.php
 * (AJAX endpoint), and structured as plain functions so the scan paths are
 * unit-testable without HTTP. The barcode payload IS the member_number
 * (GM-XXXXXX) — no separate barcode column exists.
 *
 * Backed by gym_attendance (migration
 * admin/migrations/2026_07_04_gym_attendance.sql). Every function degrades
 * gracefully until that table exists.
 */

if (!function_exists('gym_attendance_table_exists')) {
    function gym_attendance_table_exists(PDO $pdo): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gym_attendance'")->fetchColumn() > 0;
        } catch (Throwable $e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('gym_checkin_process')) {
    /**
     * Process a scanned/typed member code and toggle their attendance state.
     *
     * Outcomes ('outcome' key):
     *  - 'pending_migration'  gym_attendance table missing
     *  - 'not_found'          no member with that number
     *  - 'blocked'            member exists but is not active / expired
     *  - 'checked_in'         new visit opened
     *  - 'checked_out'        open visit closed (duration included)
     *  - 'error'              database failure
     *
     * @return array{outcome:string, message:string, member?:array, expiring_soon?:bool, duration_minutes?:int, attendance_id?:int}
     */
    /**
     * End-of-day sweep: close any visit still open from a PREVIOUS day.
     * Members who forget to scan out get auto-checked-out at the gym's
     * closing time (setting gym_close_time, default 22:00) on the day they
     * checked in — so yesterday's forgotten scan never flips today's
     * check-in into a check-out, "in gym now" stays truthful, and visit
     * durations aren't inflated across midnight. Runs lazily before every
     * scan/snapshot and from the daily cron. Idempotent and cheap (indexed
     * on checked_in_at; matches only open rows).
     *
     * @return int rows auto-closed
     */
    function gym_auto_checkout_stale(PDO $pdo, ?int $adminId = null): int
    {
        if (!gym_attendance_table_exists($pdo)) {
            return 0;
        }
        try {
            $closeTime = '22:00:00';
            if (function_exists('getSetting')) {
                $t = trim((string)getSetting('gym_close_time', '22:00'));
                if (preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $t)) {
                    $closeTime = $t . ':00';
                }
            }
            // Close at the LATER of (check-in time, closing time) on the
            // check-in day — a late-night check-in after closing still gets a
            // non-negative duration.
            $stmt = $pdo->prepare("
                UPDATE gym_attendance
                SET checked_out_at = GREATEST(checked_in_at, CONCAT(DATE(checked_in_at), ' ', ?)),
                    checked_out_by = ?,
                    notes = TRIM(CONCAT(COALESCE(notes, ''), ' [auto check-out: end of day]'))
                WHERE checked_out_at IS NULL
                  AND checked_in_at < CURDATE()
            ");
            $stmt->execute([$closeTime, $adminId]);
            return (int)$stmt->rowCount();
        } catch (Throwable $e) {
            error_log('gym_auto_checkout_stale: ' . $e->getMessage());
            return 0;
        }
    }

    function gym_checkin_process(PDO $pdo, string $code, int $adminId, string $method = 'barcode'): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['outcome' => 'not_found', 'message' => 'Empty code scanned.'];
        }
        if (!in_array($method, ['barcode', 'manual'], true)) {
            $method = 'barcode';
        }
        if (!gym_attendance_table_exists($pdo)) {
            return ['outcome' => 'pending_migration', 'message' => 'Attendance table missing — run the gym_attendance migration first.'];
        }
        // Seamless day boundary: never let yesterday's forgotten scan-out
        // turn today's arrival into a "checked out".
        gym_auto_checkout_stale($pdo, $adminId);

        try {
            $stmt = $pdo->prepare("SELECT id, member_number, full_name, email, membership_type, start_date, expiry_date, status FROM gym_members WHERE member_number = ? LIMIT 1");
            $stmt->execute([$code]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                return ['outcome' => 'not_found', 'message' => 'No member found for "' . $code . '". Check the number or enrol the member first.'];
            }

            $memberPublic = [
                'id'              => (int)$member['id'],
                'member_number'   => (string)$member['member_number'],
                'full_name'       => (string)$member['full_name'],
                'membership_type' => (string)($member['membership_type'] ?? ''),
                'expiry_date'     => $member['expiry_date'] ?: null,
                'status'          => (string)$member['status'],
            ];

            $today   = date('Y-m-d');
            $expired = $member['expiry_date'] !== null && $member['expiry_date'] !== '' && $member['expiry_date'] < $today;

            if ($member['status'] !== 'active' || $expired) {
                $why = $expired
                    ? 'membership expired ' . date('M j, Y', strtotime((string)$member['expiry_date']))
                    : 'membership is ' . $member['status'];
                return [
                    'outcome' => 'blocked',
                    'message' => $member['full_name'] . ' cannot check in — ' . $why . '.',
                    'member'  => $memberPublic,
                ];
            }

            // Open visit? → check out.
            $open = $pdo->prepare("SELECT id, checked_in_at FROM gym_attendance WHERE member_id = ? AND checked_out_at IS NULL ORDER BY checked_in_at DESC LIMIT 1");
            $open->execute([(int)$member['id']]);
            $visit = $open->fetch(PDO::FETCH_ASSOC);

            if ($visit) {
                $pdo->prepare("UPDATE gym_attendance SET checked_out_at = NOW(), checked_out_by = ? WHERE id = ?")
                    ->execute([$adminId ?: null, (int)$visit['id']]);
                $mins = max(0, (int)floor((time() - strtotime((string)$visit['checked_in_at'])) / 60));
                $dur  = $mins >= 60 ? floor($mins / 60) . 'h ' . ($mins % 60) . 'm' : $mins . 'm';
                return [
                    'outcome'          => 'checked_out',
                    'message'          => 'Goodbye ' . $member['full_name'] . ' — visit duration ' . $dur . '.',
                    'member'           => $memberPublic,
                    'duration_minutes' => $mins,
                    'attendance_id'    => (int)$visit['id'],
                ];
            }

            // No open visit → check in. Pass-sharing guard first: one person
            // realistically visits at most gym_max_daily_visits times a day
            // (default 2 — a morning session plus a re-entry). A further scan
            // is the classic sign of a card being passed around outside, so
            // it's refused with a clear warning; staff can still record a
            // legitimate exception manually from the members page.
            $maxDaily = 2;
            if (function_exists('getSetting')) {
                $md = (int)getSetting('gym_max_daily_visits', 2);
                if ($md >= 1 && $md <= 10) { $maxDaily = $md; }
            }
            $todayCount = $pdo->prepare("SELECT COUNT(*) FROM gym_attendance WHERE member_id = ? AND checked_in_at >= CURDATE()");
            $todayCount->execute([(int)$member['id']]);
            $visitsToday = (int)$todayCount->fetchColumn();
            if ($visitsToday >= $maxDaily) {
                if (function_exists('logActivity')) {
                    try { logActivity($adminId ?: 0, 'gym_checkin_blocked', 'Possible pass sharing: ' . $member['member_number'] . ' (' . $member['full_name'] . ') attempted visit #' . ($visitsToday + 1) . ' today'); } catch (Throwable $e) { /* fine */ }
                }
                return [
                    'outcome' => 'blocked',
                    'message' => $member['full_name'] . ' has already visited ' . $visitsToday . '× today — possible pass sharing. Verify the person matches the membership before letting them in.',
                    'member'  => $memberPublic,
                ];
            }

            $pdo->prepare("INSERT INTO gym_attendance (member_id, member_number, checked_in_at, checked_in_by, method) VALUES (?, ?, NOW(), ?, ?)")
                ->execute([(int)$member['id'], (string)$member['member_number'], $adminId ?: null, $method]);
            $attendanceId = (int)$pdo->lastInsertId();

            $expiringSoon = $member['expiry_date'] !== null && $member['expiry_date'] !== ''
                && $member['expiry_date'] <= date('Y-m-d', strtotime('+7 days'));

            $msg = 'Welcome ' . $member['full_name'];
            $msg .= $member['expiry_date'] ? ' — membership expires ' . date('M j, Y', strtotime((string)$member['expiry_date'])) . '.' : '.';

            return [
                'outcome'       => 'checked_in',
                'message'       => $msg,
                'member'        => $memberPublic,
                'expiring_soon' => $expiringSoon,
                'attendance_id' => $attendanceId,
            ];
        } catch (Throwable $e) {
            error_log('gym_checkin_process: ' . $e->getMessage());
            return ['outcome' => 'error', 'message' => 'Database error while processing the scan.'];
        }
    }
}

if (!function_exists('gym_checkin_snapshot')) {
    /**
     * Live lists for the scanner page: who is in the gym now + today's log.
     */
    function gym_checkin_snapshot(PDO $pdo): array
    {
        $out = ['in_gym' => [], 'today' => [], 'in_gym_count' => 0, 'visits_today' => 0];
        if (!gym_attendance_table_exists($pdo)) {
            return $out;
        }
        gym_auto_checkout_stale($pdo);
        try {
            $out['in_gym'] = $pdo->query("
                SELECT ga.id, ga.member_number, ga.checked_in_at, gm.full_name,
                       TIMESTAMPDIFF(MINUTE, ga.checked_in_at, NOW()) AS minutes_in
                FROM gym_attendance ga
                LEFT JOIN gym_members gm ON gm.id = ga.member_id
                WHERE ga.checked_out_at IS NULL
                ORDER BY ga.checked_in_at ASC
                LIMIT 100
            ")->fetchAll(PDO::FETCH_ASSOC);
            $out['today'] = $pdo->query("
                SELECT ga.id, ga.member_number, ga.checked_in_at, ga.checked_out_at, ga.method, gm.full_name,
                       CASE WHEN ga.checked_out_at IS NULL THEN NULL
                            ELSE TIMESTAMPDIFF(MINUTE, ga.checked_in_at, ga.checked_out_at) END AS minutes
                FROM gym_attendance ga
                LEFT JOIN gym_members gm ON gm.id = ga.member_id
                WHERE ga.checked_in_at >= CURDATE()
                ORDER BY ga.checked_in_at DESC
                LIMIT 100
            ")->fetchAll(PDO::FETCH_ASSOC);
            $out['in_gym_count'] = count($out['in_gym']);
            $out['visits_today'] = count($out['today']);
        } catch (Throwable $e) {
            error_log('gym_checkin_snapshot: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('gym_checkin_force_checkout')) {
    /** Manual check-out from the "currently in the gym" list. */
    function gym_checkin_force_checkout(PDO $pdo, int $attendanceId, int $adminId): array
    {
        if (!gym_attendance_table_exists($pdo)) {
            return ['outcome' => 'pending_migration', 'message' => 'Attendance table missing.'];
        }
        try {
            $stmt = $pdo->prepare("UPDATE gym_attendance SET checked_out_at = NOW(), checked_out_by = ? WHERE id = ? AND checked_out_at IS NULL");
            $stmt->execute([$adminId ?: null, $attendanceId]);
            if ($stmt->rowCount() === 0) {
                return ['outcome' => 'error', 'message' => 'Visit not found or already checked out.'];
            }
            return ['outcome' => 'checked_out', 'message' => 'Checked out.'];
        } catch (Throwable $e) {
            error_log('gym_checkin_force_checkout: ' . $e->getMessage());
            return ['outcome' => 'error', 'message' => 'Database error.'];
        }
    }
}
