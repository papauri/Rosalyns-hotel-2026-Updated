<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Conference + gym list filter rules (admin/includes/events-gym-filters.php). NO DATABASE.
 * Conference ?status= whitelist, ?event_date= validation and multi-day range rule (checked against an
 * in-PHP evaluation over fixture enquiries), Gym Members tab clauses, "in the gym now" = today's open visits.
 * Usage (repo root): php scripts/tests/events_gym_filters.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

date_default_timezone_set('Africa/Blantyre');
require_once $root . '/admin/includes/events-gym-filters.php';
check(!isset($GLOBALS['pdo']) && !function_exists('getSetting'), 'loads without the database');

// ---- conference status whitelist
eq(array_keys(rh_conf_list_statuses()), ['pending', 'confirmed', 'completed', 'cancelled'], 'all four enquiry statuses');
eq(rh_conf_list_filter(['status' => 'pending'], true)['clauses'], ['ci.status = ?'], 'pending -> bound status clause');
eq(rh_conf_list_filter(['status' => 'pending'], true)['params'], ['pending'], 'pending param (same rule as dashboard status = pending)');
eq(rh_conf_list_filter(['status' => ' Pending '], true)['status'], 'pending', 'case/space tolerant');
eq(rh_conf_list_filter(['status' => "pending' OR 1=1"], true), ['status' => '', 'event_date' => '', 'open_only' => false, 'clauses' => [], 'params' => []], 'injection dropped -> no filter');
eq(rh_conf_list_filter(['status' => 'new'], true)['status'], '', 'unknown status dropped');
eq(rh_conf_list_filter(['status' => ['pending']], true)['status'], '', 'array status dropped');
eq(rh_conf_list_filter([], true), ['status' => '', 'event_date' => '', 'open_only' => false, 'clauses' => [], 'params' => []], 'no params -> full list');

// ---- dates
eq(rh_conf_list_valid_date('2026-11-12'), '2026-11-12', 'valid date kept');
eq(rh_conf_list_valid_date('2026-02-30'), '', 'impossible date dropped');
eq(rh_conf_list_valid_date('2026-11-12 OR 1=1'), '', 'junk dropped');
eq(rh_conf_list_valid_date(null), '', 'missing date dropped');

// ---- date range rule (multi-day enquiries match every day of their range)
$f = rh_conf_list_filter(['event_date' => '2026-11-11'], true);
eq($f['clauses'], ['ci.status IN (?,?)', '(ci.event_date <= ? AND COALESCE(ci.end_date, ci.event_date) >= ?)'], 'day view, no status: pending+confirmed + dashboard range clause');
eq($f['open_only'], true, 'day view, no status: open_only flag (notice says pending or confirmed)');
eq($f['params'], ['pending', 'confirmed', '2026-11-11', '2026-11-11'], 'params follow clause order (statuses, then range)');
function rh_t_conf_match(array $flt, array $row): bool
{
    $p = $flt['params'];
    $i = 0;
    foreach ($flt['clauses'] as $c) {
        if ($c === 'ci.status IN (?,?)') { $a = $p[$i++]; $b2 = $p[$i++]; if ($row['status'] !== $a && $row['status'] !== $b2) return false; continue; }
        if ($c === 'ci.status = ?') { if ($row['status'] !== $p[$i++]) return false; continue; }
        if ($c === 'ci.event_date = ?') { if ($row['start'] !== $p[$i++]) return false; continue; }
        if ($c === '(ci.event_date <= ? AND COALESCE(ci.end_date, ci.event_date) >= ?)') {
            $d1 = $p[$i++]; $d2 = $p[$i++];
            $end = $row['end'] ?? $row['start'];
            if (!($row['start'] <= $d1 && $end >= $d2)) return false;
            continue;
        }
        return false;
    }
    return true;
}
$multi = ['status' => 'confirmed', 'start' => '2026-11-10', 'end' => '2026-11-12'];
foreach (['2026-11-09' => false, '2026-11-10' => true, '2026-11-11' => true, '2026-11-12' => true, '2026-11-13' => false] as $d => $want) {
    eq(rh_t_conf_match(rh_conf_list_filter(['event_date' => $d], true), $multi), $want, "10th-12th event on $d");
}
eq(rh_t_conf_match(rh_conf_list_filter(['event_date' => '2026-11-10'], true), ['status' => 'pending', 'start' => '2026-11-10', 'end' => null]), true, 'single-day (NULL end) matches its day');
$g = rh_conf_list_filter(['event_date' => '2026-11-10'], false);
eq($g['clauses'], ['ci.status IN (?,?)', 'ci.event_date = ?'], 'no end_date column: exact day');
eq($g['params'], ['pending', 'confirmed', '2026-11-10'], 'exact day params');
// Dashboard Today's conferences counts pending + confirmed only: a day link without a status must list the same rows.
foreach (['pending' => true, 'confirmed' => true, 'cancelled' => false, 'completed' => false] as $st => $want) {
    eq(rh_t_conf_match(rh_conf_list_filter(['event_date' => '2026-11-11'], true), ['status' => $st] + $multi), $want, "day view without status: $st " . ($want ? 'listed' : 'excluded'));
}
eq(rh_conf_list_filter(['event_date' => '2026-11-11', 'status' => 'cancelled'], true)['open_only'], false, 'explicit status overrides the day default');

// ---- both: params follow clause order
$b = rh_conf_list_filter(['status' => 'confirmed', 'event_date' => '2026-11-11'], true);
eq($b['clauses'][0], 'ci.status = ?', 'status clause first');
eq($b['params'], ['confirmed', '2026-11-11', '2026-11-11'], 'params follow clause order');
eq(rh_t_conf_match($b, $multi), true, 'confirmed on day 2 matches');
eq(rh_t_conf_match($b, ['status' => 'cancelled'] + $multi), false, 'cancelled excluded by status');
check(strpos(implode(' ', $b['clauses']), '2026') === false, 'no values interpolated into SQL');

// ---- gym members tabs
$w = rh_gym_member_filter_where();
eq(array_keys($w), ['all', 'active', 'expiring', 'expired'], 'tab keys = badge keys');
eq($w['all'], '1=1', 'All = every member');
check(strpos($w['active'], 'expiry_date >= CURDATE()') !== false, 'Active excludes lapsed members (end date passed)');
check(strpos($w['expired'], 'expiry_date < CURDATE()') !== false, 'Expired includes lapsed members');
check(strpos($w['expiring'], "BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)") !== false, 'Expiring = today..+30 inclusive (same as dashboard)');

// ---- in the gym now
eq(rh_gym_in_now_sql(), 'checked_out_at IS NULL AND checked_in_at >= CURDATE()', 'in-now = Reports rule (gym-reports.php)');
eq(rh_gym_in_now_sql('ga.'), 'ga.checked_out_at IS NULL AND ga.checked_in_at >= CURDATE()', 'aliased');
eq(rh_gym_in_now_sql('x; DROP'), 'checked_out_at IS NULL AND checked_in_at >= CURDATE()', 'bad alias ignored');
$reports = (string)@file_get_contents($root . '/admin/gym-reports.php');
check(strpos($reports, 'WHERE ' . rh_gym_in_now_sql() . '")') !== false, 'gym-reports.php uses the identical in-now clause');
$lib = (string)@file_get_contents($root . '/admin/includes/gym-checkin-lib.php');
check(strpos($lib, 'WHERE ' . rh_gym_in_now_sql('ga.')) !== false, 'check-in "Currently in the gym" list uses the same rule');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
