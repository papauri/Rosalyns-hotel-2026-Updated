<?php
/**
 * Dashboard first-login readers (plan .claude/DASHBOARD_FIRSTLOGIN_PLAN.md, tasks A2-A4). Read-only, prepared
 * statements; any fault returns null so the dashboard hides the line instead of showing a wrong one. The wording
 * is in admin/includes/dashboard-firstlogin.php (pure). Tested by scripts/tests/dashboard_firstlogin.php --db.
 */

if (!function_exists('rh_dash_prev_signin')) {
    /**
     * The sign-in before this one: the newest login_success row of $uid with id < $anchorId ($anchorId =
     * $_SESSION['admin_login_log_id'], set by admin/login.php). Anchor 0 (signed in before the anchor existed, or the
     * dev login) = the second-newest row. -> ['at' => created_at, 'ua' => user_agent]; [] = no earlier sign-in;
     * null = could not read (hide the line).
     */
    function rh_dash_prev_signin(PDO $pdo, int $uid, int $anchorId): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        try {
            if ($anchorId > 0) {
                $st = $pdo->prepare("SELECT created_at, user_agent FROM admin_activity_log
                    WHERE user_id = ? AND action = 'login_success' AND id < ? ORDER BY id DESC LIMIT 1");
                $st->execute([$uid, $anchorId]);
            } else {
                $st = $pdo->prepare("SELECT created_at, user_agent FROM admin_activity_log
                    WHERE user_id = ? AND action = 'login_success' ORDER BY id DESC LIMIT 1 OFFSET 1");
                $st->execute([$uid]);
            }
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return [];
            }
            return ['at' => (string)$row['created_at'], 'ua' => (string)($row['user_agent'] ?? '')];
        } catch (Throwable $e) {
            error_log('rh_dash_prev_signin: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('rh_dash_eod_status')) {
    /**
     * The last trading day that has ended by $now (rh_pos_last_ended_trading_date, EOD_SPEC §1.2) and the earliest
     * successful End of Day send for it: (a) the timed report claim row in automated_email_log (daily_reports /
     * trading_day / YYYYMMDD / sent, updated_at), (b) a manual send logged in system_event_log (eod-report /
     * "EOD email send", context date = the day, success true, created_at). A test-mode send counts, flagged 'test'.
     * -> ['day' => 'Y-m-d', 'sent_at' => ?'Y-m-d H:i:s', 'test' => bool]; null = could not read (hide the chip).
     */
    function rh_dash_eod_status(PDO $pdo, DateTimeImmutable $now): ?array
    {
        try {
            require_once __DIR__ . '/pos-shift-totals.php'; // pure helpers only at include
            $day = rh_pos_last_ended_trading_date($now);
            $sends = []; // [sent_at, test]

            $a = $pdo->prepare("SELECT updated_at, stage FROM automated_email_log
                WHERE job = 'daily_reports' AND account_type = 'trading_day' AND account_id = ? AND status = 'sent'");
            $a->execute([(int)str_replace('-', '', $day)]);
            foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $stage = (string)$r['stage'];
                if ($stage === 'day:' . $day || $stage === 'test:day:' . $day) {
                    $sends[] = [(string)$r['updated_at'], strpos($stage, 'test:') === 0];
                }
            }

            $b = $pdo->prepare("SELECT created_at, context_json FROM system_event_log
                WHERE source = 'eod-report' AND message = 'EOD email send' AND created_at >= ? AND context_json LIKE ?");
            $b->execute([$day . ' 00:00:00', '%"date":"' . $day . '"%']);
            foreach ($b->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ctx = json_decode((string)$r['context_json'], true);
                if (is_array($ctx) && ($ctx['date'] ?? null) === $day && ($ctx['success'] ?? false) === true) {
                    $sends[] = [(string)$r['created_at'], !empty($ctx['test_mode'])];
                }
            }

            $first = null;
            foreach ($sends as $s) {
                if ($first === null || strcmp($s[0], $first[0]) < 0) {
                    $first = $s;
                }
            }
            return ['day' => $day, 'sent_at' => $first[0] ?? null, 'test' => (bool)($first[1] ?? false)];
        } catch (Throwable $e) {
            error_log('rh_dash_eod_status: ' . $e->getMessage());
            return null;
        }
    }
}
