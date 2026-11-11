<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Bookings list filter rules (admin/includes/bookings-list-filters.php). NO DATABASE.
 * Date builder per date type (arrival/departure/staying/booked; checked against an in-PHP evaluation over fixture bookings), tab -> clauses map,
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

// ---- dates: cleaning
eq(rh_bk_valid_date('2026-11-12'), '2026-11-12', 'valid date kept');
eq(rh_bk_valid_date('2026-02-30'), '', 'impossible date dropped');
eq(rh_bk_valid_date("2026-11-12' OR 1=1"), '', 'junk dropped');
eq(rh_bk_date_range('2026-11-14', '2026-11-12'), ['2026-11-12', '2026-11-14'], 'From after To is swapped');
eq(rh_bk_date_range('2026-13-40', '2026-11-12'), ['', '2026-11-12'], 'invalid From ignored, To kept');
eq(rh_bk_date_range('nonsense', ''), ['', ''], 'invalid only -> no dates');

// ---- date type (?date_type=, whitelisted, default Arrival)
eq(rh_bk_date_type(''), 'arrival', 'absent date_type -> arrival');
eq(rh_bk_date_type('Staying'), 'staying', 'case-insensitive');
eq(rh_bk_date_type("booked' OR 1=1"), 'arrival', 'unknown date_type -> arrival');
eq(array_keys(rh_bk_date_types()), ['arrival', 'departure', 'staying', 'booked'], 'four date types, Arrival first');
foreach (array_keys(rh_bk_date_types()) as $t) {
    eq(rh_bk_date_scope('', '', $t), ['clauses' => [], 'params' => []], "$t: no dates -> no clauses");
}
eq(rh_bk_date_scope('2026-11-12', '2026-11-14', 'bogus'), rh_bk_date_scope('2026-11-12', '2026-11-14', 'arrival'), 'unknown type builds the Arrival clause');
eq(rh_bk_date_scope('2026-11-12', '2026-11-14', 'arrival'), ['clauses' => ['b.check_in_date >= ?', 'b.check_in_date <= ?'], 'params' => ['2026-11-12', '2026-11-14']], 'arrival: check-in between, params in clause order');
eq(rh_bk_date_scope('2026-11-12', '', 'departure'), ['clauses' => ['b.check_out_date >= ?'], 'params' => ['2026-11-12']], 'departure: From only');
eq(rh_bk_date_scope('', '2026-11-14', 'staying'), ['clauses' => ['b.check_in_date <= ?'], 'params' => ['2026-11-14']], 'staying: To only');
eq(rh_bk_date_scope('2026-11-12', '2026-11-14', 'staying'), ['clauses' => ['b.check_in_date <= ?', 'b.check_out_date > ?'], 'params' => ['2026-11-14', '2026-11-12']], 'staying: check-in <= To, check-out > From');
eq(rh_bk_date_scope('2026-11-12', '2026-11-14', 'booked'), ['clauses' => ['b.created_at >= ?', 'b.created_at < ?'], 'params' => ['2026-11-12 00:00:00', '2026-11-15 00:00:00']], 'booked: From 00:00 to the day after To 00:00');
eq(rh_bk_date_scope('', '2026-12-31', 'booked')['params'], ['2027-01-01 00:00:00'], 'booked: To = 31 Dec rolls into the new year');
eq(rh_bk_date_scope('', '2026-02-28', 'booked')['params'], ['2026-03-01 00:00:00'], 'booked: To = 28 Feb (non-leap) -> 1 Mar');
eq(rh_bk_date_scope('', '2028-02-28', 'booked')['params'], ['2028-02-29 00:00:00'], 'booked: To = 28 Feb (leap year) -> 29 Feb');
foreach (array_keys(rh_bk_date_types()) as $t) {
    $sc = rh_bk_date_scope('2026-11-12', '2026-11-14', $t);
    check(count($sc['clauses']) === count($sc['params']) && count($sc['clauses']) === substr_count(implode(' ', $sc['clauses']), '?'), "$t: one placeholder per clause, one param each");
}

// Evaluate the built clauses against fixture bookings (the same comparisons MySQL makes on Y-m-d / Y-m-d H:i:s strings).
function rh_t_match(array $scope, array $b): bool
{
    $cols = ['b.check_in_date' => 'in', 'b.check_out_date' => 'out', 'b.created_at' => 'made'];
    foreach ($scope['clauses'] as $i => $c) {
        if (!preg_match('/^(b\.\w+) (>=|<=|>|<) \?$/', $c, $m) || !isset($cols[$m[1]])) {
            bad('unknown clause shape', $c);
            return false;
        }
        $x = $b[$cols[$m[1]]];
        $v = $scope['params'][$i];
        $okc = $m[2] === '>=' ? $x >= $v : ($m[2] === '<=' ? $x <= $v : ($m[2] === '>' ? $x > $v : $x < $v));
        if (!$okc) return false;
    }
    return true;
}
function rh_t_list(array $rows, string $from, string $to, string $type): array
{
    [$from, $to] = rh_bk_date_range($from, $to);
    $sc = rh_bk_date_scope($from, $to, rh_bk_date_type($type));
    return array_keys(array_filter($rows, function ($b) use ($sc) { return rh_t_match($sc, $b); }));
}
$rows = [
    'arrives 12th'       => ['in' => '2026-11-12', 'out' => '2026-11-14', 'made' => '2026-11-01 09:00:00'],
    'in-house over 12th' => ['in' => '2026-11-10', 'out' => '2026-11-15', 'made' => '2026-11-12 00:00:00'],
    'departs 12th'       => ['in' => '2026-11-09', 'out' => '2026-11-12', 'made' => '2026-11-12 23:59:59'],
    'left 11th'          => ['in' => '2026-11-08', 'out' => '2026-11-11', 'made' => '2026-11-11 23:59:59'],
    'arrives 13th'       => ['in' => '2026-11-13', 'out' => '2026-11-14', 'made' => '2026-11-13 00:00:00'],
];
// Same day (From = To = 12th)
eq(rh_t_list($rows, '2026-11-12', '2026-11-12', 'arrival'), ['arrives 12th'], 'arrival, one day: arriving that day only');
eq(rh_t_list($rows, '2026-11-12', '2026-11-12', 'departure'), ['departs 12th'], 'departure, one day: leaving that day only');
eq(rh_t_list($rows, '2026-11-12', '2026-11-12', 'staying'), ['arrives 12th', 'in-house over 12th'], 'staying, one day: sleeping there that night (not the guest leaving that morning)');
eq(rh_t_list($rows, '2026-11-12', '2026-11-12', 'booked'), ['in-house over 12th', 'departs 12th'], 'booked, one day: made 00:00:00 to 23:59:59 that day');
// Ranges include both end days
eq(rh_t_list($rows, '2026-11-12', '2026-11-13', 'arrival'), ['arrives 12th', 'arrives 13th'], 'arrival range includes both end days');
eq(rh_t_list($rows, '2026-11-11', '2026-11-12', 'departure'), ['departs 12th', 'left 11th'], 'departure range includes both end days');
eq(rh_t_list($rows, '2026-11-13', '2026-11-20', 'staying'), ['arrives 12th', 'in-house over 12th', 'arrives 13th'], 'staying range: a night inside it');
eq(rh_t_list($rows, '2026-11-11', '2026-11-12', 'booked'), ['in-house over 12th', 'departs 12th', 'left 11th'], 'booked range includes both end days');
// From > To swapped; invalid ignored; unknown type = arrival
eq(rh_t_list($rows, '2026-11-13', '2026-11-12', 'arrival'), ['arrives 12th', 'arrives 13th'], 'From after To: swapped, same result');
eq(rh_t_list($rows, 'junk', '2026-11-12', 'arrival'), ['arrives 12th', 'in-house over 12th', 'departs 12th', 'left 11th'], 'invalid From ignored: arriving on or before To');
eq(rh_t_list($rows, '2026-11-12', '2026-11-12', 'nope'), ['arrives 12th'], 'unknown date type behaves as Arrival');
// One end only
eq(rh_t_list($rows, '2026-11-12', '', 'departure'), ['arrives 12th', 'in-house over 12th', 'departs 12th', 'arrives 13th'], 'departure From only: leaving on or after');
eq(rh_t_list($rows, '', '2026-11-11', 'staying'), ['in-house over 12th', 'departs 12th', 'left 11th'], 'staying To only: arrived on or before');
eq(rh_t_list($rows, '2026-11-13', '', 'booked'), ['arrives 13th'], 'booked From only: made on or after');

// ---- notice words name the date type
eq(rh_bk_date_scope_words('2026-11-12', '2026-11-14', 'arrival'), "arriving 12\u{2013}14 Nov 2026", 'notice: Arriving 12-14 Nov');
eq(rh_bk_date_scope_words('2026-11-12', '2026-11-12', 'departure'), 'departing on 12 Nov 2026', 'notice: departing one day');
eq(rh_bk_date_scope_words('2026-11-12', '2026-11-12', 'staying'), 'staying the night of 12 Nov 2026', 'notice: staying one night');
eq(rh_bk_date_scope_words('2026-11-28', '2026-12-02', 'booked'), "booked 28 Nov \u{2013} 2 Dec 2026", 'notice: across months');
eq(rh_bk_date_scope_words('2026-12-30', '2027-01-02', 'staying'), "staying 30 Dec 2026 \u{2013} 2 Jan 2027", 'notice: across years');
eq(rh_bk_date_scope_words('2026-11-12', '', 'booked'), 'booked on or after 12 Nov 2026', 'notice: From only');
eq(rh_bk_date_scope_words('', '2026-11-14', 'x'), 'arriving on or before 14 Nov 2026', 'notice: To only, unknown type = arriving');
eq(rh_bk_date_scope_words('', '', 'staying'), '', 'notice: no dates -> nothing');

// ---- bookings.php wiring: one builder everywhere, date_type kept / cleared in the right places
$bkSrc = (string)file_get_contents($root . '/admin/bookings.php');
check(substr_count($bkSrc, 'rh_bk_date_scope($filter_date_from, $filter_date_to, $filter_date_type)') === 2, 'list/badges/count/insights scope and CSV export use the same builder + date type');
check(strpos($bkSrc, "\$filter_date_type = rh_bk_date_type(\$_GET['date_type'] ?? '');") !== false, 'date_type is whitelisted from the URL');
check(strpos($bkSrc, "'date_type'     => \$bk_has_dates ? \$filter_date_type : '',") !== false, 'pagination keeps date_type');
check(strpos($bkSrc, "\$insight_base_query['date_type'] = \$filter_date_type;") !== false, 'insight card links keep date_type');
check(strpos($bkSrc, 'rh_bk_date_scope_words($filter_date_from, $filter_date_to, $filter_date_type)') !== false, 'notice names the date type');
check(strpos($bkSrc, '<select name="date_type"') !== false, 'Dates select is a form field (search / Filter submit carry it)');
check(strpos($bkSrc, "'date_type'].forEach") === false && strpos($bkSrc, "'date_type', 'page']") === false, 'tabs never drop date_type (it stays with the dates); not double-sent by the keep list');
check(preg_match('/\$has_active_room_filters = [^;]*date_type/', $bkSrc) === 0, 'date_type alone is not an active filter (no notice/Clear without dates)');

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

// ---- header sort (2026-11-11): Total = the total shown, Room = room number else type name, natural order
eq(array_keys(rh_bk_sort_map()), ['ref', 'guest', 'room', 'check_in', 'check_out', 'nights', 'guests', 'total', 'status', 'payment', 'created'], 'sort keys unchanged (old sort links keep working)');
$bkTot = rh_bk_sort_map()['total'];
check(strpos($bkTot, 'b.total_with_vat') !== false && strpos($bkTot, 'b.vat_amount') !== false, 'Total sorts by total_with_vat (else total_amount + VAT), not the pre-VAT amount');
eq($bkTot, '(CASE WHEN COALESCE(b.total_with_vat, 0) > 0.0100 THEN b.total_with_vat ELSE COALESCE(b.total_amount, 0) + COALESCE(b.vat_amount, 0) END)', 'Total sort = rh_booking_full_total rule');
check(substr_count($bkTot, '(') === substr_count($bkTot, ')'), 'Total sort expression balanced');
eq(rh_bk_room_sort_sql(), "COALESCE(NULLIF(TRIM(ir.room_number), ''), r.name, '')", 'Room sort value = room number when assigned, else type name');
$bkAsc = rh_bk_room_sort_order('asc');
$bkDesc = rh_bk_room_sort_order('desc');
check(substr_count($bkAsc, ' ASC') + substr_count($bkAsc, ' DESC') === 4 && substr_count($bkDesc, ' ASC') + substr_count($bkDesc, ' DESC') === 4, 'Room sort: every part carries a direction');
eq('(ir.id IS NULL) ASC, ' . strtr(substr($bkAsc, strlen('(ir.id IS NULL) ASC, ')), [' ASC' => ' DESC', ' DESC' => ' ASC']), $bkDesc, 'Room sort: desc reverses the rooms, unassigned stay last');
check(strpos($bkDesc, '(ir.id IS NULL) ASC') === 0, 'Room sort desc: unassigned still last');
check(strpos($bkAsc, '(ir.id IS NULL) ASC') === 0 && strpos($bkAsc, 'CAST(') !== false, 'Room sort asc: assigned first, then numeric part (2 before 10)');
eq(rh_bk_room_sort_order("asc; DROP"), $bkDesc, 'Room sort: unknown dir -> desc (whitelisted)');
// Natural order, in PHP, of the same rule (assigned first; digit-led first; number; text)
$bkRows = [['n' => '10', 'a' => 1], ['n' => 'Deluxe', 'a' => 0], ['n' => '2', 'a' => 1], ['n' => 'EXEC-101', 'a' => 1], ['n' => 'Classic', 'a' => 0]];
usort($bkRows, static function ($x, $y) {
    $k = static fn($r) => [$r['a'] ? 0 : 1, ctype_digit(substr($r['n'], 0, 1)) ? 0 : 1, (int)$r['n'], $r['n']];
    return $k($x) <=> $k($y);
});
eq(array_column($bkRows, 'n'), ['2', '10', 'EXEC-101', 'Classic', 'Deluxe'], 'Room sort rule: 2, 10, EXEC-101, then unassigned type names A-Z');

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
