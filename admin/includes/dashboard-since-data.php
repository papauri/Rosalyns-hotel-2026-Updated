<?php
/**
 * Dashboard first-login readers (plan .claude/DASHBOARD_FIRSTLOGIN_PLAN.md, tasks A2-A4, B2, C2-C4). Read-only, prepared
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

if (!function_exists('rh_dash_pay_scope_types')) {
    /**
     * The booking types the Payments page lists by default (admin/payments.php $scopeActive with no type, no scope and
     * no refund filter): the enabled types when some are switched off, else [] (every type). $enabled =
     * rh_enabled_booking_types().
     */
    function rh_dash_pay_scope_types(array $enabled): array
    {
        $all = ['room', 'conference', 'restaurant', 'gym', 'event'];
        return (!empty($enabled) && count($enabled) < count($all)) ? $enabled : [];
    }
}

if (!function_exists('rh_dash_since_counts')) {
    /**
     * "Since you were last here" counts (plan B2), each the row count of the list its link opens, built with that
     * list's own rule (no second copy of the SQL):
     *   booked / cancelled / moved  Bookings ?since=&since_kind= (rh_bk_since_clause, not deleted)    needs $show['bookings']
     *   payments / received          Payments ?status=received&since= (rh_fin_payments_where, the page's  needs $show['payments']
     *                                default type scope $show['scope_types']): row count and SUM(total_amount), exact
     * A gated-off item is null. $since must be a real 'Y-m-d H:i:s'. null = could not read (show today's lede instead).
     */
    function rh_dash_since_counts(PDO $pdo, string $since, array $show): ?array
    {
        require_once __DIR__ . '/bookings-list-filters.php';
        require_once __DIR__ . '/finance-filters.php';
        if (rh_bk_since_filter($since) === '') {
            return null;
        }
        $out = ['booked' => null, 'cancelled' => null, 'moved' => null, 'payments' => null, 'received' => null];
        try {
            if (!empty($show['bookings'])) {
                foreach (['booked', 'cancelled', 'moved'] as $kind) {
                    $c = rh_bk_since_clause($since, $kind);
                    $st = $pdo->prepare('SELECT COUNT(*) FROM bookings b WHERE b.deleted_at IS NULL AND ' . $c['clause']);
                    $st->execute($c['params']);
                    $out[$kind] = (int)$st->fetchColumn();
                }
            }
            if (!empty($show['payments'])) {
                $w = rh_fin_payments_where([
                    'status' => 'received',
                    'since' => $since,
                    'scope_types' => (array)($show['scope_types'] ?? []),
                ], 'transaction_id', []);
                $st = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(p.total_amount), 0) AS amt ' . rh_fin_payments_from() . ' ' . $w['sql']);
                $st->execute($w['params']);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'amt' => '0'];
                $out['payments'] = (int)$row['n'];
                $out['received'] = (string)$row['amt'];
            }
            return $out;
        } catch (Throwable $e) {
            error_log('rh_dash_since_counts: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('rh_dash_table_missing')) {
    /**
     * True when $e is "table does not exist" (SQLSTATE 42S02 / MySQL 1146): migration 091 not run on this database yet.
     * That is expected (migrations run by hand), so the callers stay silent instead of logging on every page load.
     */
    function rh_dash_table_missing(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $info = $e->errorInfo;
        return (string)$e->getCode() === '42S02'
            || (is_array($info) && ((string)($info[0] ?? '') === '42S02' || (int)($info[1] ?? 0) === 1146));
    }
}

if (!function_exists('rh_dash_handover_unread')) {
    /**
     * Handover notes for the dashboard (plan C2/C3): the newest note $uid has not marked read that has not expired
     * (expires_at > NOW(), database time on both sides), plus how many other such notes there are. A note's author has a
     * read row from the moment it is written, so authors never see their own note.
     * -> ['note' => ['id', 'body', 'from', 'at'] | null, 'more' => int]; null = not available here (tables missing, or
     * a fault): the dashboard then shows no handover button and no item, exactly as before.
     */
    function rh_dash_handover_unread(PDO $pdo, int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        $unread = "FROM shift_handover_notes n
            LEFT JOIN shift_handover_reads r ON r.note_id = n.id AND r.user_id = ?
            WHERE n.expires_at > NOW() AND r.note_id IS NULL";
        try {
            $c = $pdo->prepare('SELECT COUNT(*) ' . $unread);
            $c->execute([$uid]);
            $count = (int)$c->fetchColumn();
            if ($count === 0) {
                return ['note' => null, 'more' => 0];
            }
            $st = $pdo->prepare('SELECT n.id, n.body, n.created_by_name, n.created_at ' . $unread . ' ORDER BY n.created_at DESC, n.id DESC LIMIT 1');
            $st->execute([$uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['note' => null, 'more' => 0];
            }
            return [
                'note' => ['id' => (int)$row['id'], 'body' => (string)$row['body'], 'from' => (string)$row['created_by_name'], 'at' => (string)$row['created_at']],
                'more' => max(0, $count - 1),
            ];
        } catch (Throwable $e) {
            if (!rh_dash_table_missing($e)) {
                error_log('rh_dash_handover_unread: ' . $e->getMessage());
            }
            return null;
        }
    }
}

if (!function_exists('rh_dash_welcome_show')) {
    /**
     * Show the first-login welcome card (plan C4)? True while $uid has no 'dashboard_welcome_dismissed' preference.
     * False on any fault or a missing admin_user_preferences table (the dashboard then renders as before).
     */
    function rh_dash_welcome_show(PDO $pdo, int $uid): bool
    {
        if ($uid <= 0) {
            return false;
        }
        try {
            $st = $pdo->prepare("SELECT 1 FROM admin_user_preferences WHERE user_id = ? AND preference_key = 'dashboard_welcome_dismissed' LIMIT 1");
            $st->execute([$uid]);
            return $st->fetchColumn() === false;
        } catch (Throwable $e) {
            if (!rh_dash_table_missing($e)) {
                error_log('rh_dash_welcome_show: ' . $e->getMessage());
            }
            return false;
        }
    }
}
