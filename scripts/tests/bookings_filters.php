<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Bookings list filter rules (admin/includes/bookings-list-filters.php). NO DATABASE.
 * Date overlap builder (checked against an in-PHP evaluation of the same rule over fixture stays), tab -> clauses map,
 * ?filter= / ?payment_status= resolution, overdue-arrival filter, Paid/Unpaid row flags (SQL NULL semantics).
 * Usage (repo root): php scripts/tests/bookings_filters.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

date_default_timezone_set('Africa/Blantyre');
require_once $root . '/admin/includes/bookings-list-filters.php';
check(!isset($GLOBALS['pdo']) && !function_exists('getSetting'), 'loads without the database');

// ---- dates
eq(rh_bk_valid_date('2026-11-12'), '2026-11-12', 'valid date kept');
eq(rh_bk_valid_date('2026-02-30'), '', 'impossible date dropped');
eq(rh_bk_valid_date("2026-11-12' OR 1=1"), '', 'junk dropped');
eq(rh_bk_date_range('2026-11-14', '2026-11-12'), ['2026-11-12', '2026-11-14'], 'From after To is swapped');
eq(rh_bk_date_scope('', ''), ['clauses' => [], 'params' => []], 'no dates -> no clauses');
eq(rh_bk_date_scope('2026-11-12', ''), ['clauses' => ['b.check_out_date >= ?'], 'params' => ['2026-11-12']], 'From only');
eq(rh_bk_date_scope('', '2026-11-12'), ['clauses' => ['b.check_in_date <= ?'], 'params' => ['2026-11-12']], 'To only');
eq(rh_bk_date_scope('2026-11-10', '2026-11-12'), ['clauses' => ['b.check_in_date <= ?', 'b.check_out_date >= ?'], 'params' => ['2026-11-12', '2026-11-10']], 'both: params follow clause order');

// Evaluate the built clauses against fixture stays (the same comparisons MySQL makes on Y-m-d strings).
function rh_t_match(array $scope, array $stay): bool
{
    foreach ($scope['clauses'] as $i => $c) {
        $v = $scope['params'][$i];
        if ($c === 'b.check_in_date <= ?' && !($stay['in'] <= $v)) return false;
        if ($c === 'b.check_out_date >= ?' && !($stay['out'] >= $v)) return false;
    }
    return true;
}
$stays = [
    'arrives 12th'        => ['in' => '2026-11-12', 'out' => '2026-11-14'],
    'in-house over 12th'  => ['in' => '2026-11-10', 'out' => '2026-11-15'],
    'departs 12th'        => ['in' => '2026-11-09', 'out' => '2026-11-12'],
    'left 11th'           => ['in' => '2026-11-08', 'out' => '2026-11-11'],
    'arrives 13th'        => ['in' => '2026-11-13', 'out' => '2026-11-14'],
];
$day = rh_bk_date_scope('2026-11-12', '2026-11-12');
$got = array_keys(array_filter($stays, function ($s) use ($day) { return rh_t_match($day, $s); }));
eq($got, ['arrives 12th', 'in-house over 12th', 'departs 12th'], 'From = To = a day: everyone staying that day (arrivals, in-house, departures)');
$range = rh_bk_date_scope('2026-11-13', '2026-11-20');
$got = array_keys(array_filter($stays, function ($s) use ($range) { return rh_t_match($range, $s); }));
eq($got, ['arrives 12th', 'in-house over 12th', 'arrives 13th'], 'range: stays overlapping it');
eq(rh_bk_date_scope_words('2026-11-12', '2026-11-12'), 'staying on 12 Nov 2026', 'notice words: one day');
eq(rh_bk_date_scope_words('2026-11-12', '2026-11-14'), 'staying between 12 Nov 2026 and 14 Nov 2026', 'notice words: range');
eq(rh_bk_date_scope_words('', ''), '', 'notice words: none');

// ---- overdue arrivals
eq(rh_bk_arrival_filter('overdue'), 'overdue', 'arrival=overdue recognised');
eq(rh_bk_arrival_filter('x'), '', 'other arrival values ignored');
eq(rh_bk_arrival_clause(''), null, 'no arrival filter -> no clause');
check(strpos((string)rh_bk_arrival_clause('overdue'), 'b.check_in_date < CURDATE()') !== false, 'overdue clause: arrival date passed');

// ---- overdue departures (Dashboard chip: checked in, check-out date passed)
eq(rh_bk_departure_filter('overdue'), 'overdue', 'departure=overdue recognised');
eq(rh_bk_departure_filter('today'), '', 'other departure values ignored');
eq(rh_bk_departure_clause(''), null, 'no departure filter -> no clause');
eq(rh_bk_departure_clause('overdue'), "b.status = 'checked-in' AND b.check_out_date < CURDATE()", 'overdue departure clause = the Dashboard count rule');
eq(rh_bk_notice_title('', 'overdue'), 'Overdue departures only', 'notice: overdue departures');
eq(rh_bk_notice_title('overdue', ''), 'Overdue arrivals only', 'notice: overdue arrivals');
eq(rh_bk_notice_title('', ''), 'Search filtered results', 'notice: plain search');

// ---- ?balance=owing (Dashboard "Departures with a balance": opened with ?filter=checkout_today)
if (!defined('BALANCE_TOLERANCE')) define('BALANCE_TOLERANCE', 0.01);
eq(rh_bk_balance_filter('owing'), 'owing', 'balance=owing recognised');
eq(rh_bk_balance_filter('outstanding'), '', 'other balance values ignored');
eq(rh_bk_balance_clause(''), null, 'no balance filter -> no clause');
$bal = rh_bk_balance_clause('owing');
eq($bal['clause'], 'b.amount_due > ?', 'balance clause binds the tolerance (no number in the SQL)');
eq($bal['params'], [rh_fd_balance_tolerance()], 'balance clause param = the dashboard tolerance');
check(strpos($bal['clause'], '?') !== false && count($bal['params']) === 1, 'one placeholder, one param');
// Same answer as the chip's per-row rule for a guest due out today (SQL: NULL amount_due is never > x).
foreach ([0.01, 0.011, 0, -5, 5000, null] as $amt) {
    $sql = $amt !== null && (float)$amt > $bal['params'][0];
    $chip = rh_fd_departure_owes(['departure_state' => 'due', 'amount_due' => $amt]);
    eq($sql, $chip, 'balance filter = chip rule for amount_due ' . var_export($amt, true));
}
// The tab + balance together = the chip's set: due out today (still in) AND owing.
$checkoutTab = rh_bk_tab_clauses('today-checkouts')['clauses'];
eq($checkoutTab, ['b.check_out_date = CURDATE()', "b.status = 'checked-in'"], "chip link tab = Today's Check-outs (due, not overdue or departed)");
eq(rh_bk_notice_title('', '', 'owing', 'checkout_today'), 'Departures with a balance', 'notice: chip view');
eq(rh_bk_notice_title('', '', 'owing', ''), 'Balance still to pay', 'notice: balance alone');
eq(rh_bk_notice_title('', 'overdue', 'owing', ''), 'Overdue departures only', 'notice: overdue wins its own title');
$bkSrc = (string)file_get_contents($root . '/admin/bookings.php');
check(substr_count($bkSrc, "'departure', 'balance', 'payment_status', 'page'].forEach(k => params.delete(k));") === 2, 'tabs clear ?balance= like the other filters');
check(strpos($bkSrc, "'balance' => \$filter_balance] as \$bk_keep_k") !== false && strpos($bkSrc, "['filter', 'payment_status', 'arrival', 'departure', 'balance'].forEach") !== false, 'search keeps ?balance= (server + client)');
check(strpos($bkSrc, "'balance'       => \$filter_balance,") !== false, 'pagination keeps ?balance=');
check(strpos($bkSrc, '$export_balance[\'params\']') !== false && strpos($bkSrc, '$bk_balance_clause[\'params\']') !== false, 'list, badges and export bind the balance param');

// ---- ?filter= / ?payment_status=
eq(rh_bk_filter_key('checkin_today'), 'checkin_today', 'filter key kept');
eq(rh_bk_filter_key('bogus'), '', 'unknown filter dropped');
eq(rh_bk_filter_key('', 'unpaid'), 'unpaid', 'Accounting link ?payment_status=unpaid opens Unpaid');
eq(rh_bk_filter_key('', 'partial'), '', 'other payment_status ignored');
eq(rh_bk_filter_key('paid', 'unpaid'), 'paid', '?filter= wins over payment_status');
foreach (rh_bk_tab_map() as $k => $tab) {
    check(rh_bk_tab_clauses($tab)['clauses'] !== [], "tab $tab has list clauses");
    foreach (rh_bk_tab_clauses($tab)['clauses'] as $c) {
        check(strpos($c, '?') === false, "tab $tab clause has no placeholder (badge SUM embeds it)");
    }
}
eq(rh_bk_tab_clauses('today-checkins')['clauses'], ['b.check_in_date = CURDATE()', "b.status IN ('confirmed','pending')"], "Today's check-ins = confirmed/pending arriving today");
eq(rh_bk_tab_clauses('today-checkouts')['clauses'], ['b.check_out_date = CURDATE()', "b.status = 'checked-in'"], "Today's check-outs = in-house leaving today");
eq(rh_bk_tab_clauses('checked-in')['clauses'], ["b.status = 'checked-in'"], '?filter=checked_in lists in-house only');
eq(rh_bk_tab_clauses('pending'), ['clauses' => [], 'order' => null], 'status tabs add nothing (filter_status does it)');
eq(rh_bk_tab_clauses('all'), ['clauses' => [], 'order' => null], 'All adds nothing');
eq('SUM(' . implode(' AND ', rh_bk_tab_clauses('today-checkins')['clauses']) . ')', "SUM(b.check_in_date = CURDATE() AND b.status IN ('confirmed','pending'))", 'badge SUM is built from the same pieces');

// ---- Paid / Unpaid flags (match SQL incl. NULL)
eq(rh_bk_payment_flags('paid', 'confirmed'), ['paid' => true, 'unpaid' => false], 'paid');
eq(rh_bk_payment_flags('completed', 'checked-out'), ['paid' => true, 'unpaid' => false], 'completed counts as paid');
eq(rh_bk_payment_flags('partial', 'checked-in'), ['paid' => false, 'unpaid' => true], 'partial is unpaid');
eq(rh_bk_payment_flags('unpaid', 'cancelled'), ['paid' => false, 'unpaid' => false], 'cancelled never unpaid');
eq(rh_bk_payment_flags('unpaid', 'expired'), ['paid' => false, 'unpaid' => false], 'expired never unpaid');
eq(rh_bk_payment_flags('refunded', 'confirmed'), ['paid' => false, 'unpaid' => false], 'refunded is neither');
eq(rh_bk_payment_flags('', 'pending'), ['paid' => false, 'unpaid' => true], "'' is unpaid (SQL '' NOT IN -> true)");
eq(rh_bk_payment_flags(null, 'pending'), ['paid' => false, 'unpaid' => false], 'NULL is neither (SQL NULL semantics)');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
