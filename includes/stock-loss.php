<?php

/**
 * Stock loss-prevention helpers (shared by admin/stock-count.php and admin/stock-dashboard.php).
 *
 *  1. rh_stock_alert_level()          - the one rule behind the red / orange count lines.
 *  2. rh_stock_manual_additions()     - manual stock ADDITIONS (a manual add can hide an earlier loss).
 *  3. rh_stock_count_reminder()       - "no stock count approved for N days" notice (no emails, no cron).
 *  4. rh_stock_review_items()         - "Needs review" list: big count shortages, manual adjustments,
 *                                       batch discards / write-offs / recalls (last 7 days), read from the
 *                                       existing records, so nothing new is stored except who dismissed what.
 *
 * Read-only against the stock tables; the only write is the per-user dismissed list in site_settings.
 */

if (!function_exists('rh_stock_alert_level')) {

    /**
     * ONE rule for a counted line. Only shortages worth at least $minCost are ever flagged.
     *   'block' (red)    = shortage % is at or above the "needs approval above" level.
     *   'alert' (orange) = shortage % is at or above the "alert at variance" level.
     *   'none'           = anything else (surplus, small %, or worth less than $minCost).
     * Red always counts as over the alert level too, even if an owner sets the alert above the approval level.
     */
    function rh_stock_alert_level(float $variance, float $varCost, float $base, float $alertPct, float $blockPct, float $minCost): string
    {
        if ($variance >= 0 || abs($varCost) + 0.00005 < $minCost) {
            return 'none';
        }
        $pct = $base > 0 ? abs($variance) / $base * 100 : 100.0;
        if ($pct + 0.00005 >= $blockPct) {
            return 'block';
        }
        return ($pct + 0.00005 >= $alertPct) ? 'alert' : 'none';
    }

    /** Whole days since $lastApproved (null = never). Calendar-free: 86400 s per day, rounded down. */
    function rh_stock_days_since(?string $lastApproved, DateTimeInterface $now): ?int
    {
        if ($lastApproved === null || trim($lastApproved) === '') {
            return null;
        }
        $ts = strtotime($lastApproved);
        if ($ts === false) {
            return null;
        }
        return max(0, (int)floor(($now->getTimestamp() - $ts) / 86400));
    }

    /**
     * Reminder state from the setting + the newest approved count. @return ?array{days:?int,text:string}
     * null = nothing to show (reminder off, or a count was approved recently enough).
     */
    function rh_stock_count_reminder_for(int $reminderDays, ?string $lastApproved, DateTimeInterface $now): ?array
    {
        if ($reminderDays <= 0) {
            return null;
        }
        $days = rh_stock_days_since($lastApproved, $now);
        if ($days === null) {
            return ['days' => null, 'text' => 'No stock count has been approved yet'];
        }
        if ($days < $reminderDays) {
            return null;
        }
        return ['days' => $days, 'text' => 'No stock count approved for ' . $days . ' day' . ($days === 1 ? '' : 's')];
    }

    /** Reads the setting "stock_count_reminder_days" (default 7) and the newest approved count. */
    function rh_stock_count_reminder(PDO $pdo): ?array
    {
        $raw = getSetting('stock_count_reminder_days', null);
        $reminderDays = ($raw === null || $raw === false || trim((string)$raw) === '') ? 7 : (int)$raw;
        if ($reminderDays <= 0) {
            return null;
        }
        try {
            $last = $pdo->query("SELECT MAX(COALESCE(approved_at, updated_at)) FROM stock_counts WHERE status = 'approved'")->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
        return rh_stock_count_reminder_for($reminderDays, $last ? (string)$last : null, new DateTimeImmutable('now'));
    }

    /** Plain notice markup (the same amber box the count and budget notices use). */
    function rh_stock_count_reminder_html(?array $reminder, string $startUrl = 'stock-count.php'): string
    {
        if (!$reminder) {
            return '';
        }
        return '<div class="sdash-setup-note" style="margin:0 0 16px;padding:12px 16px;border:1px solid #ecd4a6;background:#fdf6e7;color:#8a6a2f;border-radius:8px;">'
            . '<strong>' . htmlspecialchars($reminder['text']) . ' &mdash;</strong> <a href="' . htmlspecialchars($startUrl) . '">start one</a>.</div>';
    }

    /**
     * Manual stock ADDITIONS in the last $days days, biggest cost first. Read-only.
     * @return array<int,array<string,mixed>> who, when, qty, cost (qty x cost at the time), reason, ingredient.
     */
    function rh_stock_manual_additions(PDO $pdo, int $days = 30, int $limit = 20): array
    {
        $days = max(1, $days);
        $limit = max(1, $limit);
        $st = $pdo->prepare("
            SELECT sa.id, sa.created_at, sa.quantity_change AS qty, sa.cost_at_time, sa.reason,
                   (sa.quantity_change * sa.cost_at_time) AS total_cost,
                   i.name AS ingredient_name, i.unit, u.full_name AS who
            FROM stock_adjustments sa
            INNER JOIN stock_ingredients i ON i.id = sa.ingredient_id
            LEFT JOIN admin_users u ON u.id = sa.adjusted_by
            WHERE sa.source_type = 'manual' AND sa.quantity_change > 0
              AND sa.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            ORDER BY total_cost DESC, sa.created_at DESC
            LIMIT {$limit}
        ");
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * "Needs review" items from the last $days days, newest first. Keys are stable ("adj:12", "cnt:3")
     * so a person can dismiss one. Nothing is stored for the items themselves.
     * @return array<int,array{key:string,kind:string,at:string,who:string,text:string,link:string}>
     */
    function rh_stock_review_items(PDO $pdo, float $blockPct, float $minCost, string $currency = '', int $days = 7): array
    {
        $days = max(1, $days);
        $items = [];
        $fmt = static fn(float $n): string => rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');

        // Manual adjustments (adds and subtractions) and batch discards / write-offs / recalls / expiries.
        try {
            $st = $pdo->query("
                SELECT sa.id, sa.created_at, sa.quantity_change, sa.cost_at_time, sa.reason, sa.source_type,
                       i.name AS ingredient_name, i.unit, u.full_name AS who
                FROM stock_adjustments sa
                INNER JOIN stock_ingredients i ON i.id = sa.ingredient_id
                LEFT JOIN admin_users u ON u.id = sa.adjusted_by
                WHERE sa.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                  AND (sa.source_type IN ('manual', 'expiry', 'recall')
                       OR (sa.source_type = 'wastage' AND EXISTS (SELECT 1 FROM stock_wastage w WHERE w.id = sa.source_id AND w.batch_id IS NOT NULL)))
                ORDER BY sa.created_at DESC, sa.id DESC
                LIMIT 100
            ");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $q = (float)$r['quantity_change'];
                $cost = abs($q) * (float)$r['cost_at_time'];
                if ($r['source_type'] === 'manual') {
                    $kind = 'Manual adjustment';
                    $what = ($q >= 0 ? 'added ' : 'took off ') . $fmt(abs($q));
                } elseif ($r['source_type'] === 'recall') {
                    $kind = 'Batch recalled';
                    $what = 'removed ' . $fmt(abs($q));
                } elseif ($r['source_type'] === 'expiry') {
                    $kind = 'Batch expired';
                    $what = 'removed ' . $fmt(abs($q));
                } else {
                    $kind = 'Batch written off';
                    $what = 'removed ' . $fmt(abs($q));
                }
                $items[] = [
                    'key'  => 'adj:' . (int)$r['id'],
                    'kind' => $kind,
                    'at'   => (string)$r['created_at'],
                    'who'  => (string)($r['who'] ?? ''),
                    'text' => ucfirst($what) . ' ' . $r['unit'] . ' of ' . $r['ingredient_name'] . ' (' . $currency . ' ' . number_format($cost, 2) . ') - ' . trim((string)$r['reason']),
                    'link' => 'stock-reports.php?tab=adjustments',
                ];
            }
        } catch (Throwable $e) {
            error_log('rh_stock_review_items adjustments: ' . $e->getMessage());
        }

        // Counts approved in the window with at least one shortage at or above the approval level.
        try {
            $st = $pdo->prepare("
                SELECT sc.id, sc.reference, COALESCE(sc.approved_at, sc.updated_at) AS at, au.full_name AS who,
                       COUNT(*) AS n, SUM(ABS(scl.variance_cost)) AS cost
                FROM stock_counts sc
                INNER JOIN stock_count_lines scl ON scl.count_id = sc.id
                LEFT JOIN admin_users au ON au.id = sc.approved_by
                WHERE sc.status = 'approved'
                  AND COALESCE(sc.approved_at, sc.updated_at) >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                  AND scl.variance < 0
                  AND ABS(scl.variance_cost) >= ?
                  AND ((scl.actual_quantity - scl.variance) <= 0
                       OR ABS(scl.variance) / (scl.actual_quantity - scl.variance) * 100 >= ?)
                GROUP BY sc.id, sc.reference, at, au.full_name
                ORDER BY at DESC
                LIMIT 50
            ");
            $st->execute([$minCost, $blockPct]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ref = (string)preg_replace('/^SC-/i', '', (string)$r['reference']);
                $items[] = [
                    'key'  => 'cnt:' . (int)$r['id'],
                    'kind' => 'Big shortage',
                    'at'   => (string)$r['at'],
                    'who'  => (string)($r['who'] ?? ''),
                    'text' => 'Count ' . $ref . ' was approved with ' . (int)$r['n'] . ' shortage line' . ((int)$r['n'] === 1 ? '' : 's') . ' over the approval level (' . $currency . ' ' . number_format((float)$r['cost'], 2) . ' lost)',
                    'link' => 'stock-count.php?id=' . (int)$r['id'],
                ];
            }
        } catch (Throwable $e) {
            error_log('rh_stock_review_items counts: ' . $e->getMessage());
        }

        usort($items, static fn(array $a, array $b): int => strcmp($b['at'], $a['at']));
        return $items;
    }

    function rh_stock_review_dismissed_key(int $userId): string
    {
        return 'stock_review_dismissed_' . $userId;
    }

    /** @return string[] keys this person has dismissed. */
    function rh_stock_review_dismissed(int $userId): array
    {
        $raw = getSetting(rh_stock_review_dismissed_key($userId), '');
        $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** Add keys to this person's dismissed list (newest 300 kept). */
    function rh_stock_review_dismiss(int $userId, array $keys): void
    {
        $clean = [];
        foreach ($keys as $k) {
            if (is_string($k) && preg_match('/^(adj|cnt):\d+$/', $k)) {
                $clean[] = $k;
            }
        }
        if (!$clean) {
            return;
        }
        $all = array_values(array_unique(array_merge(rh_stock_review_dismissed($userId), $clean)));
        updateSetting(rh_stock_review_dismissed_key($userId), json_encode(array_slice($all, -300)));
    }
}
