<?php

/**
 * KDS / BDS / CDS station report: ONE fetch + aggregate + email render for the Station Report page
 * (admin/kds-report.php: screen, CSV, manual Email) and the timed scheduler job 'station_reports'
 * (includes/auto-email-jobs.php, owner 2026-11-12 "time the email on the KDS stations").
 *
 * Pure at include (defines functions only). rh_kds_report_aggregate(), rh_station_reports_key(),
 * rh_station_reports_recipients() and rh_station_reports_auto_plan() (given $windowFor) are pure:
 * scripts/tests/station_reports_job.php.
 */

if (!function_exists('rh_fmt_dur')) {
    function rh_fmt_dur(int $s): string
    {
        if ($s <= 0) return '—';
        $m = intdiv($s, 60);
        $r = $s % 60;
        return $m > 0 ? sprintf('%dm %02ds', $m, $r) : sprintf('%ds', $r);
    }
}

if (!function_exists('rh_fmt_dt')) {
    function rh_fmt_dt(?string $iso): string
    {
        if (!$iso) return '—';
        $t = strtotime($iso);
        return $t ? date('H:i:s', $t) : '—';
    }
}

if (!function_exists('rh_kds_report_fetch')) {

    /**
     * Served + voided items whose order was created in [$startSql, $endSql) (DB time). $station 'all' = every station.
     * @return list<array<string,mixed>>
     */
    function rh_kds_report_fetch(PDO $pdo, string $station, string $startSql, string $endSql): array
    {
        $wheres = ["o.created_at >= ? AND o.created_at < ?"];
        $params = [$startSql, $endSql];
        if ($station !== 'all') {
            $wheres[] = "oi.station = ?";
            $params[] = $station;
        }
        $whereSql = implode(' AND ', $wheres);

        $itemSql = "SELECT
                oi.id              AS item_id,
                oi.order_id,
                oi.item_name,
                oi.menu_type,
                oi.station,
                oi.quantity,
                oi.unit_price,
                oi.line_total,
                oi.kds_status,
                oi.started_at,
                oi.ready_at,
                oi.served_at,
                oi.notes           AS item_notes,
                o.reference,
                o.order_type,
                o.table_number,
                o.customer_name,
                o.kitchen_status,
                o.fired_at,
                o.created_at       AS order_created_at,
                o.status           AS order_status,
                au.full_name       AS bumped_by_name,
                au_open.full_name  AS opened_by_name
            FROM stock_order_items oi
            JOIN stock_orders o ON o.id = oi.order_id
            LEFT JOIN admin_users au ON au.id = oi.bumped_by
            LEFT JOIN admin_users au_open ON au_open.id = o.created_by
            WHERE {$whereSql}
            ORDER BY oi.served_at ASC, oi.id ASC";
        $st = $pdo->prepare($itemSql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Pure. Items Served / Qty / Revenue / prep times count served items only (pending, cooking or ready tickets stay
     * in the ticket list but are not "served"); voids = item void or order voided/cancelled; prep = fired -> served
     * when 0 <= seconds < 6h; topItems = top 20 by revenue.
     * @return array{totalItems:int, totalQty:float|int, totalRevenue:float, totalVoid:int, totalVoidValue:float,
     *               avgPrep:float|int, minPrep:int, maxPrep:int, byStation:array, topItems:array}
     */
    function rh_kds_report_aggregate(array $rows): array
    {
        $totalItems = 0;
        $totalQty = 0;
        $totalRevenue = 0.0;
        $totalVoid = 0;
        $totalVoidValue = 0.0;
        $prepTimes = []; // seconds from fired→served
        $byStation = []; // station => [items, qty, revenue]
        $byCategory = []; // item_name → counts (for top-items)
        foreach ($rows as $r) {
            $st = $r['station'] ?: 'unknown';
            if (!isset($byStation[$st])) $byStation[$st] = ['items' => 0, 'qty' => 0, 'revenue' => 0, 'voids' => 0, 'avg_seconds' => []];
            if ($r['kds_status'] === 'void' || $r['order_status'] === 'voided' || $r['order_status'] === 'cancelled') {
                $totalVoid++;
                $totalVoidValue += (float)$r['line_total'];
                $byStation[$st]['voids']++;
                continue;
            }
            // Items Served / Qty / Revenue / prep times count served items only; tickets still pending,
            // cooking or ready stay in the ticket list below but are not "served" yet.
            if ($r['kds_status'] !== 'served') {
                continue;
            }
            $byStation[$st]['items']++;
            $totalItems++;
            $totalQty += (float)$r['quantity'];
            $totalRevenue += (float)$r['line_total'];
            $byStation[$st]['qty'] += (float)$r['quantity'];
            $byStation[$st]['revenue'] += (float)$r['line_total'];
            if ($r['fired_at'] && $r['served_at']) {
                $secs = strtotime($r['served_at']) - strtotime($r['fired_at']);
                if ($secs >= 0 && $secs < 6 * 3600) {
                    $prepTimes[] = $secs;
                    $byStation[$st]['avg_seconds'][] = $secs;
                }
            }
            $key = $r['item_name'];
            if (!isset($byCategory[$key])) $byCategory[$key] = ['name' => $r['item_name'], 'station' => $r['station'], 'qty' => 0, 'revenue' => 0];
            $byCategory[$key]['qty'] += (float)$r['quantity'];
            $byCategory[$key]['revenue'] += (float)$r['line_total'];
        }
        $avgPrep = $prepTimes ? round(array_sum($prepTimes) / count($prepTimes)) : 0;
        $minPrep = $prepTimes ? min($prepTimes) : 0;
        $maxPrep = $prepTimes ? max($prepTimes) : 0;
        foreach ($byStation as $s => &$d) {
            $d['avg_seconds'] = $d['avg_seconds'] ? round(array_sum($d['avg_seconds']) / count($d['avg_seconds'])) : 0;
        }
        unset($d);
        uasort($byCategory, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        $topItems = array_slice($byCategory, 0, 20, true);

        return [
            'totalItems' => $totalItems, 'totalQty' => $totalQty, 'totalRevenue' => $totalRevenue,
            'totalVoid' => $totalVoid, 'totalVoidValue' => $totalVoidValue,
            'avgPrep' => $avgPrep, 'minPrep' => $minPrep, 'maxPrep' => $maxPrep,
            'byStation' => $byStation, 'topItems' => $topItems,
        ];
    }

    /**
     * The email body (admin/includes/kds-report-email.php) for one station/day. $vars: reqStation, reqDate,
     * STATION_OPTIONS, STATION_LABEL, the rh_kds_report_aggregate() keys, rows, currency_symbol, site_name.
     */
    function rh_kds_report_render_email(array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include __DIR__ . '/../admin/includes/kds-report-email.php';
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    /* ── timed station report emails (scheduler job 'station_reports') ── */

    /**
     * automated_email_log key: one row per station per trading day, so a re-run or two page loads at once never send
     * the same station's day twice. Test sends use a 'test:' stage so a test never blocks the real send.
     * @return array{job:string, type:string, id:int, stage:string}
     */
    function rh_station_reports_key(string $station, string $tradingDate, bool $test = false): array
    {
        return [
            'job' => 'station_reports',
            'type' => 'trading_day',
            'id' => (int)str_replace('-', '', $tradingDate),
            'stage' => ($test ? 'test:' : '') . $station . ':day:' . $tradingDate,
        ];
    }

    /**
     * Who gets the station report emails: setting automated_job_station_reports_to; empty = the End of Day report
     * recipients ($fallback, normally rh_eod_report_recipients). A set list is never mixed with the fallback.
     * @return list<array{email:string, name:string}>
     */
    function rh_station_reports_recipients(string $raw, callable $fallback): array
    {
        if (trim($raw) === '') {
            return (array)$fallback();
        }
        if (!function_exists('rh_daily_reports_parse_recipients')) {
            require_once __DIR__ . '/daily-reports-send.php';
        }
        return rh_daily_reports_parse_recipients($raw);
    }

    /**
     * What the timer does now for ONE station (pure with $windowFor): the station's own last ENDED trading day
     * (its opening -> its next opening, rh_pos_last_ended_trading_date). Test mode exactly like the daily reports:
     * only the test address, [TEST] subject, 'test:' stage; an unusable test address fails closed (skip).
     * A 'skip' here writes NO log row, so adding recipients later the same day still sends.
     * @param list<array{email:string, name?:string}> $recipients
     * @return array{action:string, station:string, date:string, key:array, recipients:array, subject_prefix:string, note:string}
     */
    function rh_station_reports_auto_plan(DateTimeImmutable $now, string $station, array $recipients, string $redirect = '', bool $testMode = false, ?callable $windowFor = null): array
    {
        require_once __DIR__ . '/../admin/includes/pos-shift-totals.php'; // pure helpers only at include
        if ($windowFor === null) {
            require_once __DIR__ . '/station-hours.php';
            $windowFor = static fn(string $d): array => rh_station_window_for_date($station, $d);
        }
        $day = rh_pos_last_ended_trading_date($now, $windowFor);
        $plan = [
            'action' => 'send', 'station' => $station, 'date' => $day, 'key' => rh_station_reports_key($station, $day, $testMode),
            'recipients' => $recipients, 'subject_prefix' => '', 'note' => '',
        ];
        if ($testMode) {
            $redirect = trim($redirect);
            if (!filter_var($redirect, FILTER_VALIDATE_EMAIL)) {
                return ['action' => 'skip', 'recipients' => [], 'note' => 'Test mode is on but the test address is not a valid email, so nothing was sent.'] + $plan;
            }
            $plan['recipients'] = [['email' => $redirect, 'name' => '']];
            $plan['subject_prefix'] = '[TEST] ';
        }
        if (!$plan['recipients']) {
            return ['action' => 'skip', 'note' => 'No one to send it to: add an address under "Station report emails go to" or End of Day report recipients, or set the admin email in Email Settings.'] + $plan;
        }
        return $plan;
    }
}
