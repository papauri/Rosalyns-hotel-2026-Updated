<?php

/**
 * Gym membership expiry-reminder engine.
 *
 * Shared by scripts/gym_membership_reminders.php (cron) and the "Send due
 * reminders now" button on admin/gym-members.php — one code path, testable
 * without HTTP. Settings (site_settings):
 *   gym_reminder_enabled  '1'|'0'   (default '1')
 *   gym_reminder_days     1..30     (default 3)
 *
 * Idempotency: gym_reminder_log has UNIQUE(member_id, expiry_date), so a
 * member is reminded exactly once per expiry — renewals (a new expiry_date)
 * become remindable again, and re-running the engine never double-sends.
 * The window match (expiring within N days, not exact-day) means a missed
 * cron day can't silently skip anyone; the log still guarantees single-send.
 *
 * Degrades gracefully until admin/migrations/2026_07_04_gym_reminder_log.sql
 * has been applied.
 */

if (!function_exists('gym_reminder_log_table_exists')) {
    function gym_reminder_log_table_exists(PDO $pdo): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gym_reminder_log'")->fetchColumn() > 0;
        } catch (Throwable $e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('gym_reminder_settings')) {
    /** @return array{enabled:bool,days:int} */
    function gym_reminder_settings(): array
    {
        $enabled = getSetting('gym_reminder_enabled', '1') === '1';
        $days = (int)getSetting('gym_reminder_days', '3');
        if ($days < 1 || $days > 30) {
            $days = 3;
        }
        return ['enabled' => $enabled, 'days' => $days];
    }
}

if (!function_exists('gym_run_expiry_reminders')) {
    /**
     * Find active members whose membership expires within the configured
     * window, email each a renewal reminder, and log the send.
     *
     * @return array{pending_migration:bool,disabled:bool,checked:int,sent:int,skipped:int,errors:array<string>}
     */
    function gym_run_expiry_reminders(PDO $pdo): array
    {
        $out = ['pending_migration' => false, 'disabled' => false, 'checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => []];

        if (function_exists('moduleEnabled') && !moduleEnabled('gym')) {
            $out['disabled'] = true;
            return $out;
        }
        $cfg = gym_reminder_settings();
        if (!$cfg['enabled']) {
            $out['disabled'] = true;
            return $out;
        }
        if (!gym_reminder_log_table_exists($pdo)) {
            $out['pending_migration'] = true;
            return $out;
        }
        if (!function_exists('sendGymRenewalReminderEmail')) {
            require_once __DIR__ . '/../../config/email.php';
        }

        try {
            $stmt = $pdo->prepare("
                SELECT m.id, m.member_number, m.full_name, m.email, m.membership_type,
                       m.expiry_date, m.monthly_fee,
                       DATEDIFF(m.expiry_date, CURDATE()) AS days_left
                FROM gym_members m
                WHERE m.status = 'active'
                  AND m.email IS NOT NULL AND m.email <> ''
                  AND m.expiry_date IS NOT NULL
                  AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                  AND NOT EXISTS (
                      SELECT 1 FROM gym_reminder_log l
                      WHERE l.member_id = m.id AND l.expiry_date = m.expiry_date
                  )
                ORDER BY m.expiry_date ASC
            ");
            $stmt->execute([$cfg['days']]);
            $due = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $out['errors'][] = 'Query failed: ' . $e->getMessage();
            return $out;
        }

        $out['checked'] = count($due);
        $claim = $pdo->prepare("INSERT IGNORE INTO gym_reminder_log (member_id, member_number, expiry_date, reminder_days, sent_to) VALUES (?,?,?,?,?)");
        $unclaim = $pdo->prepare("DELETE FROM gym_reminder_log WHERE member_id = ? AND expiry_date = ?");

        foreach ($due as $m) {
            if (!filter_var((string)$m['email'], FILTER_VALIDATE_EMAIL)) {
                $out['skipped']++;
                continue;
            }
            // Claim the log row FIRST (unique key) so concurrent runs can't
            // both email the same member; release the claim if sending fails.
            try {
                $claim->execute([(int)$m['id'], (string)$m['member_number'], (string)$m['expiry_date'], $cfg['days'], (string)$m['email']]);
                if ($claim->rowCount() === 0) {
                    $out['skipped']++; // already reminded for this expiry
                    continue;
                }
            } catch (Throwable $e) {
                $out['errors'][] = $m['member_number'] . ': log claim failed — ' . $e->getMessage();
                continue;
            }

            try {
                $res = sendGymRenewalReminderEmail($m, max(0, (int)$m['days_left']));
                if (!empty($res['success'])) {
                    $out['sent']++;
                } else {
                    $unclaim->execute([(int)$m['id'], (string)$m['expiry_date']]);
                    $out['errors'][] = $m['member_number'] . ': email failed — ' . (string)($res['message'] ?? 'unknown');
                }
            } catch (Throwable $e) {
                try { $unclaim->execute([(int)$m['id'], (string)$m['expiry_date']]); } catch (Throwable $e2) { /* keep first error */ }
                $out['errors'][] = $m['member_number'] . ': ' . $e->getMessage();
            }
        }

        return $out;
    }
}

if (!function_exists('gym_reminder_last_run')) {
    /**
     * Most recent reminder activity for the admin summary strip.
     *
     * @return array{last_sent_at:?string,sent_today:int,total:int}
     */
    function gym_reminder_last_run(PDO $pdo): array
    {
        if (!gym_reminder_log_table_exists($pdo)) {
            return ['last_sent_at' => null, 'sent_today' => 0, 'total' => 0];
        }
        try {
            return [
                'last_sent_at' => $pdo->query("SELECT MAX(sent_at) FROM gym_reminder_log")->fetchColumn() ?: null,
                'sent_today'   => (int)$pdo->query("SELECT COUNT(*) FROM gym_reminder_log WHERE sent_at >= CURDATE()")->fetchColumn(),
                'total'        => (int)$pdo->query("SELECT COUNT(*) FROM gym_reminder_log")->fetchColumn(),
            ];
        } catch (Throwable $e) {
            return ['last_sent_at' => null, 'sent_today' => 0, 'total' => 0];
        }
    }
}
