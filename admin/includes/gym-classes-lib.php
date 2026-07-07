<?php

/**
 * Gym classes + enrolment logic.
 *
 * gym_classes already exists as a marketing schedule (title, day_label like
 * "Tuesday & Thursday", time_label, level_label) shown on the public gym page
 * and the admin schedule day-view. This adds the operational half: enrolling
 * real gym_members into a class, listing the roster, and reminding enrolees.
 *
 * The enrolment table is created lazily (CREATE TABLE IF NOT EXISTS) the same
 * way gymScheduleEnsureTables() works — no separate migration file to run.
 */

if (!function_exists('gymClassesEnsureTables')) {
    function gymClassesEnsureTables(PDO $pdo): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            // gym_classes may pre-exist as marketing content; create it if not so
            // a fresh install still works, matching the columns already in use.
            $pdo->exec("CREATE TABLE IF NOT EXISTS gym_classes (
                id INT NOT NULL AUTO_INCREMENT,
                title VARCHAR(150) NOT NULL,
                description TEXT NULL,
                day_label VARCHAR(120) NOT NULL,
                time_label VARCHAR(50) NOT NULL,
                level_label VARCHAR(80) NULL DEFAULT 'All Levels',
                display_order INT NULL DEFAULT 0,
                is_active TINYINT(1) NULL DEFAULT 1,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Gym class schedule'");

            $pdo->exec("CREATE TABLE IF NOT EXISTS gym_class_enrollments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                class_id INT NOT NULL,
                member_id INT NOT NULL,
                status ENUM('enrolled','cancelled') NOT NULL DEFAULT 'enrolled',
                enrolled_by INT NULL,
                enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_reminded_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_class_member (class_id, member_id),
                KEY idx_class (class_id),
                KEY idx_member (member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Members enrolled in a gym class'");
            $ok = true;
        } catch (Throwable $e) {
            error_log('gymClassesEnsureTables failed: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('gymClassesList')) {
    /**
     * All classes with their active enrolment counts, ready for the admin list.
     *
     * @return array<int,array<string,mixed>>
     */
    function gymClassesList(PDO $pdo): array
    {
        gymClassesEnsureTables($pdo);
        try {
            $rows = $pdo->query("
                SELECT c.id, c.title, c.description, c.day_label, c.time_label, c.level_label,
                       c.display_order, c.is_active,
                       COALESCE(e.cnt, 0) AS enrolled_count
                FROM gym_classes c
                LEFT JOIN (
                    SELECT class_id, COUNT(*) AS cnt
                    FROM gym_class_enrollments
                    WHERE status = 'enrolled'
                    GROUP BY class_id
                ) e ON e.class_id = c.id
                ORDER BY c.is_active DESC, c.display_order ASC, c.title ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (Throwable $e) {
            error_log('gymClassesList: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('gymClassGet')) {
    function gymClassGet(PDO $pdo, int $classId): ?array
    {
        gymClassesEnsureTables($pdo);
        try {
            $stmt = $pdo->prepare("SELECT id, title, description, day_label, time_label, level_label, display_order, is_active FROM gym_classes WHERE id = ? LIMIT 1");
            $stmt->execute([$classId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('gymClassGet: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('gymClassSave')) {
    /**
     * Create or update a class. @return array{success:bool, message:string, id?:int}
     */
    function gymClassSave(PDO $pdo, array $data, int $classId = 0): array
    {
        gymClassesEnsureTables($pdo);
        $title = trim((string)($data['title'] ?? ''));
        $day   = trim((string)($data['day_label'] ?? ''));
        $time  = trim((string)($data['time_label'] ?? ''));
        $level = trim((string)($data['level_label'] ?? '')) ?: 'All Levels';
        $desc  = trim((string)($data['description'] ?? ''));
        $order = (int)($data['display_order'] ?? 0);
        $active = !empty($data['is_active']) ? 1 : 0;

        if ($title === '' || mb_strlen($title) > 150) {
            return ['success' => false, 'message' => 'Class title is required (max 150 characters).'];
        }
        if ($day === '' || mb_strlen($day) > 120) {
            return ['success' => false, 'message' => 'Days are required (e.g. "Tuesday & Thursday").'];
        }
        if ($time === '' || mb_strlen($time) > 50) {
            return ['success' => false, 'message' => 'A time is required (e.g. "7:00 AM").'];
        }
        try {
            if ($classId > 0) {
                $stmt = $pdo->prepare("UPDATE gym_classes SET title=?, description=?, day_label=?, time_label=?, level_label=?, display_order=?, is_active=? WHERE id=?");
                $stmt->execute([$title, $desc ?: null, $day, $time, $level, $order, $active, $classId]);
                return ['success' => true, 'message' => 'Class updated.', 'id' => $classId];
            }
            $stmt = $pdo->prepare("INSERT INTO gym_classes (title, description, day_label, time_label, level_label, display_order, is_active) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$title, $desc ?: null, $day, $time, $level, $order, $active]);
            return ['success' => true, 'message' => 'Class created.', 'id' => (int)$pdo->lastInsertId()];
        } catch (Throwable $e) {
            error_log('gymClassSave: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error while saving the class.'];
        }
    }
}

if (!function_exists('gymClassDelete')) {
    function gymClassDelete(PDO $pdo, int $classId): array
    {
        gymClassesEnsureTables($pdo);
        try {
            $pdo->prepare("DELETE FROM gym_class_enrollments WHERE class_id = ?")->execute([$classId]);
            $pdo->prepare("DELETE FROM gym_classes WHERE id = ?")->execute([$classId]);
            return ['success' => true, 'message' => 'Class deleted.'];
        } catch (Throwable $e) {
            error_log('gymClassDelete: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error while deleting the class.'];
        }
    }
}

if (!function_exists('gymClassRoster')) {
    /**
     * Enrolled members for a class (active memberships first).
     *
     * @return array<int,array<string,mixed>>
     */
    function gymClassRoster(PDO $pdo, int $classId): array
    {
        gymClassesEnsureTables($pdo);
        try {
            $stmt = $pdo->prepare("
                SELECT e.id AS enrollment_id, e.enrolled_at, e.last_reminded_at,
                       m.id AS member_id, m.member_number, m.full_name, m.email,
                       m.membership_type, m.status AS member_status, m.expiry_date
                FROM gym_class_enrollments e
                JOIN gym_members m ON m.id = e.member_id
                WHERE e.class_id = ? AND e.status = 'enrolled'
                ORDER BY (m.status = 'active') DESC, m.full_name ASC
            ");
            $stmt->execute([$classId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('gymClassRoster: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('gymClassEnrolMember')) {
    /** Enrol an active member into a class (idempotent — re-enrols a cancelled row). */
    function gymClassEnrolMember(PDO $pdo, int $classId, int $memberId, int $adminId): array
    {
        gymClassesEnsureTables($pdo);
        if ($classId <= 0 || $memberId <= 0) {
            return ['success' => false, 'message' => 'Pick a class and a member.'];
        }
        if (!gymClassGet($pdo, $classId)) {
            return ['success' => false, 'message' => 'That class no longer exists.'];
        }
        try {
            $chk = $pdo->prepare("SELECT id, full_name FROM gym_members WHERE id = ? LIMIT 1");
            $chk->execute([$memberId]);
            $member = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                return ['success' => false, 'message' => 'Member not found.'];
            }
            // Upsert: revive a previously cancelled enrolment or insert fresh.
            $stmt = $pdo->prepare("
                INSERT INTO gym_class_enrollments (class_id, member_id, status, enrolled_by)
                VALUES (?, ?, 'enrolled', ?)
                ON DUPLICATE KEY UPDATE status = 'enrolled', enrolled_by = VALUES(enrolled_by), enrolled_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$classId, $memberId, $adminId ?: null]);
            return ['success' => true, 'message' => $member['full_name'] . ' enrolled.'];
        } catch (Throwable $e) {
            error_log('gymClassEnrolMember: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error while enrolling the member.'];
        }
    }
}

if (!function_exists('gymClassRemoveMember')) {
    function gymClassRemoveMember(PDO $pdo, int $enrollmentId): array
    {
        gymClassesEnsureTables($pdo);
        try {
            $stmt = $pdo->prepare("DELETE FROM gym_class_enrollments WHERE id = ?");
            $stmt->execute([$enrollmentId]);
            return ['success' => $stmt->rowCount() > 0, 'message' => $stmt->rowCount() > 0 ? 'Member removed from class.' : 'Enrolment not found.'];
        } catch (Throwable $e) {
            error_log('gymClassRemoveMember: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error while removing the member.'];
        }
    }
}

if (!function_exists('gymClassSendReminders')) {
    /**
     * Email every enrolled member (with a valid email) about the class.
     * Best-effort: one failure never blocks the rest. Requires sendGymClassReminderEmail().
     *
     * @return array{success:bool, message:string, sent:int, skipped:int, failed:int}
     */
    function gymClassSendReminders(PDO $pdo, int $classId, int $adminId): array
    {
        $class = gymClassGet($pdo, $classId);
        if (!$class) {
            return ['success' => false, 'message' => 'Class not found.', 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        }
        if (!function_exists('sendGymClassReminderEmail')) {
            require_once __DIR__ . '/../../config/email.php';
        }
        $roster = gymClassRoster($pdo, $classId);
        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($roster as $r) {
            $email = trim((string)($r['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }
            try {
                $res = sendGymClassReminderEmail([
                    'member_number' => $r['member_number'],
                    'full_name'     => $r['full_name'],
                    'email'         => $email,
                ], $class);
                if (!empty($res['success'])) {
                    $sent++;
                    try {
                        $pdo->prepare("UPDATE gym_class_enrollments SET last_reminded_at = NOW() WHERE id = ?")->execute([(int)$r['enrollment_id']]);
                    } catch (Throwable $e) { /* non-fatal */ }
                } else {
                    $failed++;
                }
            } catch (Throwable $e) {
                error_log('gymClassSendReminders: ' . $e->getMessage());
                $failed++;
            }
        }
        if (function_exists('logActivity')) {
            try { logActivity($adminId ?: 0, 'gym_class_reminder', 'Sent reminders for "' . $class['title'] . '": ' . $sent . ' sent, ' . $skipped . ' skipped, ' . $failed . ' failed'); } catch (Throwable $e) { /* fine */ }
        }
        $parts = [];
        if ($sent) { $parts[] = $sent . ' reminder' . ($sent === 1 ? '' : 's') . ' sent'; }
        if ($skipped) { $parts[] = $skipped . ' skipped (no email)'; }
        if ($failed) { $parts[] = $failed . ' failed'; }
        $msg = $parts ? implode(' · ', $parts) . '.' : 'No enrolled members to remind.';
        return ['success' => $sent > 0 || ($skipped === 0 && $failed === 0), 'message' => $msg, 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed];
    }
}
