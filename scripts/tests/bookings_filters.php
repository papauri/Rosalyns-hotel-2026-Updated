<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Bookings list filter rules (admin/includes/bookings-list-filters.php). NO DATABASE.
 * Date builder per date type (arrival/departure/staying/booked; checked against an in-PHP evaluation over fixture bookings), tab -> clauses map,
 * ?filter= / ?payment_status= resolution, overdue-arrival filter, Paid/Unpaid row flags (step 13: the badge's key).
 * Last block (Liwonde only, read-only): the tab / Payment-filter SQL equals the badge for every booking.
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
check(strpos($bkSrc, "'balance' => \$filter_balance, 'size' => (\$bk_page_size !== '10' ? \$bk_page_size : '')] as \$bk_keep_k") !== false && strpos($bkSrc, "['filter', 'payment_status', 'arrival', 'departure', 'balance', 'size'].forEach") !== false, 'search keeps ?balance= and ?size= (server + client)');
check(strpos($bkSrc, "'size'          => \$bk_page_size !== '10' ? \$bk_page_size : '',") !== false, 'page links keep ?size=');
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

// ---- Paid / Unpaid flags (step 13: from the badge's key, rh_booking_pay_state; replaces the stored-status flags)
eq(rh_bk_payment_flags('paid', 'confirmed'), ['paid' => true, 'unpaid' => false], 'badge paid -> Paid tab');
eq(rh_bk_payment_flags('paid', 'cancelled'), ['paid' => true, 'unpaid' => false], 'badge paid, cancelled -> still Paid tab (as before)');
eq(rh_bk_payment_flags('partial', 'checked-in'), ['paid' => false, 'unpaid' => true], 'part paid -> Unpaid tab');
eq(rh_bk_payment_flags('unpaid', 'pending'), ['paid' => false, 'unpaid' => true], 'unpaid -> Unpaid tab');
eq(rh_bk_payment_flags('unpaid', 'cancelled'), ['paid' => false, 'unpaid' => false], 'cancelled never unpaid');
eq(rh_bk_payment_flags('partial', 'no-show'), ['paid' => false, 'unpaid' => false], 'no-show never unpaid');
eq(rh_bk_payment_flags('unpaid', 'expired'), ['paid' => false, 'unpaid' => false], 'expired never unpaid');
eq(rh_bk_payment_flags('refunded', 'cancelled'), ['paid' => false, 'unpaid' => false], 'refunded is neither');

// ---- the SQL twin of the badge (rh_bk_pay_key_sql): same rule, tolerance inlined, used by tabs / filter / sort
$pk = rh_bk_pay_key_sql('b');
check(strpos($pk, sprintf('%.4F', rh_pay_state_tol())) !== false, 'pay key SQL uses BALANCE_TOLERANCE');
check(strpos($pk, 'b.amount_paid') !== false && strpos($pk, 'b.amount_due') !== false && strpos($pk, 'b.credit_balance') !== false, 'pay key SQL reads the money columns');
check(strpos($pk, '?') === false, 'pay key SQL has no placeholders (tab clauses carry no params)');
eq(rh_bk_tab_clauses('paid')['clauses'], [$pk . " = 'paid'"], 'Paid tab = badge paid');
check(in_array($pk . " IN ('partial', 'unpaid')", rh_bk_tab_clauses('unpaid')['clauses'], true), 'Unpaid tab = badge part paid or unpaid');
check(in_array("b.status NOT IN ('cancelled', 'no-show', 'expired')", rh_bk_tab_clauses('unpaid')['clauses'], true), 'Unpaid tab keeps cancelled / no-show / expired out');
eq(rh_bk_pay_clause('refunded')['clauses'], [$pk . " = 'refunded'"], 'pay refunded = badge refunded');

// ---- rows per page (?size=): whitelist, "All" only up to RH_BK_ALL_ROWS_MAX rows
eq(rh_bk_page_size(null), '10', 'absent size -> 10');
eq(rh_bk_page_size('25'), '25', 'size 25 kept');
eq(rh_bk_page_size(50), '50', 'int size kept');
eq(rh_bk_page_size(' ALL '), 'all', 'All is case/space tolerant');
eq(rh_bk_page_size('7'), '10', 'size not on the list -> 10');
eq(rh_bk_page_size('1000'), '10', 'huge size -> 10');
eq(rh_bk_page_size("25' OR 1=1"), '10', 'junk size -> 10');
eq(rh_bk_page_size(['50']), '10', 'array size -> 10');
eq(rh_bk_resolve_page_size('50', 3), ['size' => '50', 'per_page' => 50], '50 stays 50');
eq(rh_bk_resolve_page_size('all', 120), ['size' => 'all', 'per_page' => 120], 'All = every row');
eq(rh_bk_resolve_page_size('all', 500), ['size' => 'all', 'per_page' => 500], 'All at the limit');
eq(rh_bk_resolve_page_size('all', 501), ['size' => '100', 'per_page' => 100], 'All above the limit -> 100');
eq(rh_bk_resolve_page_size('all', 0), ['size' => 'all', 'per_page' => 1], 'All on an empty list -> at least 1');
eq(rh_bk_resolve_page_size('x', 40), ['size' => '10', 'per_page' => 10], 'bad size resolves to 10');
check(rh_bk_all_size_allowed(500) && !rh_bk_all_size_allowed(501), 'All offered up to 500 rows only');

// ---- left to pay under the payment badge
eq(rh_bk_left_to_pay('partial', 'confirmed', '12500', '5000'), 12500.0, 'partial shows amount due');
eq(rh_bk_left_to_pay('unpaid', 'confirmed', 12500, 0), 0.0, 'nothing paid yet -> not part-paid');
eq(rh_bk_left_to_pay('unpaid', 'checked-in', 7000, 3000), 7000.0, 'paid something + due -> shown even if status lags');
eq(rh_bk_left_to_pay('partial', 'confirmed', 0.004, 5000), 0.0, 'due within tolerance -> nothing');
eq(rh_bk_left_to_pay('partial', 'confirmed', 0, 5000), 0.0, 'stale partial with nothing due -> nothing');
eq(rh_bk_left_to_pay('paid', 'confirmed', 0, 20000), 0.0, 'paid -> nothing');
eq(rh_bk_left_to_pay('partial', 'cancelled', 8000, 2000), 8000.0, 'cancelled still owing its kept fee -> shown (owner 2026-11-14)');
eq(rh_bk_left_to_pay('unpaid', 'cancelled', 8000, 0), 8000.0, 'cancelled fee owed, nothing paid yet -> shown');
eq(rh_bk_left_to_pay('unpaid', 'cancelled', 0, 0), 0.0, 'cancelled, nothing kept (void + refund all) -> nothing');
eq(rh_bk_left_to_pay('paid', 'cancelled', 0, 8000), 0.0, 'cancelled, fee paid in full -> nothing');
eq(rh_bk_left_to_pay('refunded', 'cancelled', 0, 0), 0.0, 'cancelled, refunded -> nothing');
eq(rh_bk_left_to_pay('partial', 'cancelled', 0.004, 2000), 0.0, 'cancelled, due within tolerance -> nothing');
eq(rh_bk_left_to_pay('partial', 'expired', 8000, 2000), 0.0, 'expired -> nothing');
eq(rh_bk_left_to_pay('partial', 'no-show', 8000, 2000), 8000.0, 'no-show still owing -> shown (a no-show pays 100%)');
eq(rh_bk_left_to_pay('paid', 'no-show', 0, 2000), 0.0, 'no-show paid in full -> nothing');
eq(rh_bk_left_to_pay('refunded', 'confirmed', 8000, 2000), 0.0, 'refunded -> nothing');
eq(rh_bk_left_to_pay('partial', 'confirmed', '1234.567', '1'), 1234.57, 'rounded to 2 places');

// ---- More filters (step 8g): whitelists, fixed SQL pieces, bound numbers, notice words
eq(rh_bk_kind_filter('Group'), 'group', 'kind: case-insensitive');
eq(rh_bk_kind_filter('joined'), 'joined', 'kind: joined');
eq(rh_bk_kind_filter("group' OR 1=1"), '', 'kind: junk -> none');
eq(rh_bk_kind_filter(['group']), '', 'kind: array -> none');
eq(rh_bk_room_type_filter('7', [3, 7]), 7, 'room type: known id');
eq(rh_bk_room_type_filter('8', [3, 7]), 0, 'room type: unknown id -> none');
eq(rh_bk_room_type_filter('7 OR 1', null), 0, 'room type: junk -> none');
eq(rh_bk_room_type_filter('0', null), 0, 'room type: zero -> none');
eq(rh_bk_room_type_filter(['7'], null), 0, 'room type: array -> none');
eq(rh_bk_pay_filter('fee_owed'), 'fee_owed', 'pay: fee owed');
eq(rh_bk_pay_filter('owing'), '', 'pay: unknown -> none');
eq(rh_bk_more_scope('', 0, ''), ['clauses' => [], 'params' => []], 'more scope: nothing set -> nothing');
$ms = rh_bk_more_scope('group', 5, 'fee_owed');
eq(count($ms['clauses']), 4, 'more scope: group + room type + fee owed (2 pieces)');
eq($ms['params'], [5, rh_fd_balance_tolerance()], 'more scope: room id then tolerance bound, in clause order');
eq(substr_count(implode(' AND ', $ms['clauses']), '?'), count($ms['params']), 'more scope: one placeholder per param');
eq(rh_bk_tab_clauses('group-bookings')['clauses'], [rh_bk_group_sql()], 'Group tab = Booking kind Group rule');
eq(rh_bk_kind_clause('group'), rh_bk_group_sql(), 'kind group = group rule');
check(strpos(rh_bk_group_sql(), 'primary_booking_id') !== false, 'group rule uses primary_booking_id (create-booking group link)');
check(strpos(rh_bk_joined_sql(false), 'room_combinations') === false, 'joined rule without the combinations table: pair only');
check(strpos(rh_bk_kind_clause('single'), 'NOT (COALESCE(b.primary_booking_id') !== false, 'single = not group and not joined');
eq(rh_bk_pay_clause('paid')['clauses'], rh_bk_tab_clauses('paid')['clauses'], 'pay paid = Paid tab');
eq(rh_bk_pay_clause('unpaid')['clauses'], rh_bk_tab_clauses('unpaid')['clauses'], 'pay unpaid = Unpaid tab');
check(in_array(rh_bk_pay_key_sql('b') . " = 'partial'", rh_bk_pay_clause('part')['clauses'], true), 'pay part = Unpaid tab + badge part paid');
eq(rh_bk_tab_map()['group_bookings'] ?? '', 'group-bookings', '?filter=group_bookings opens the Group tab');
eq(rh_bk_filter_key('group_bookings'), 'group_bookings', 'group_bookings is a known filter key');
eq(rh_bk_more_scope_words('joined', 'Family <Suite>', 'part'), ', joined-room bookings only, room type Family <Suite>, part paid', 'notice words (caller escapes)');
eq(rh_bk_more_scope_words('', '', ''), '', 'notice words: none');
check(strpos(rh_bk_joined_sql(true), 'combined_room_type_id IS NOT NULL') !== false, 'joined rule: NULL type ids skipped (Single never turns NULL)');

// ---- Step 13 cross-check (LIWONDE only, READ-ONLY): every booking's tab / filter membership from the real SQL equals
// its badge (rh_booking_pay_state) - group rooms, company-billed rooms, cancelled / no-show / expired included.
// Path check FIRST: at Rosalyn's (live) the database is never even opened by this test.
if (stripos($root, 'Liwonde') !== false) { require $root . '/config/database.php'; }
if (stripos($root, 'Liwonde') === false || stripos((string)getSetting('site_name', ''), 'liwonde') === false) {
    echo "SKIP cross-check: runs at Liwonde (the test platform) only; Rosalyn's is live.\n";
} else {
    $xSql = static function (array $clauses): string { return '(' . implode(' AND ', $clauses) . ')'; };
    $xRows = $pdo->query("SELECT b.id, b.status, b.payment_status, b.amount_paid, b.amount_due, b.credit_balance,
            COALESCE(b.primary_booking_id, 0) AS grp, COALESCE(b.billing_account_id, 0) AS acct,
            " . $xSql(rh_bk_tab_clauses('paid')['clauses']) . " AS in_paid,
            " . $xSql(rh_bk_tab_clauses('unpaid')['clauses']) . " AS in_unpaid,
            " . $xSql(rh_bk_pay_clause('part')['clauses']) . " AS in_part,
            " . $xSql(rh_bk_pay_clause('refunded')['clauses']) . " AS in_refunded,
            " . rh_bk_pay_key_sql('b') . " AS sql_key
        FROM bookings b")->fetchAll(PDO::FETCH_ASSOC);
    $xMis = []; $xGrp = 0; $xAcct = 0; $xClosed = 0;
    foreach ($xRows as $r) {
        $k = rh_booking_pay_state($r)['key'];
        $f = rh_bk_payment_flags($k, (string)$r['status']);
        $closed = in_array(strtolower(trim((string)$r['status'])), ['cancelled', 'no-show', 'expired'], true);
        $want = [$k, $f['paid'], $f['unpaid'], $k === 'partial' && !$closed, $k === 'refunded'];
        $got = [(string)$r['sql_key'], (bool)$r['in_paid'], (bool)$r['in_unpaid'], (bool)$r['in_part'], (bool)$r['in_refunded']];
        if ($want !== $got) { $xMis[] = '#' . $r['id'] . ' ' . json_encode(['want' => $want, 'got' => $got]); }
        $xGrp += (int)$r['grp'] > 0 ? 1 : 0;
        $xAcct += (int)$r['acct'] > 0 ? 1 : 0;
        $xClosed += $closed ? 1 : 0;
    }
    echo 'cross-check: ' . count($xRows) . ' bookings, ' . $xGrp . ' group rooms, ' . $xAcct . ' company-billed, ' . $xClosed . ' closed; mismatches ' . count($xMis) . "\n";
    check(count($xRows) > 0, 'cross-check: Liwonde has bookings');
    check($xGrp > 0 && $xClosed > 0, 'cross-check covers group rooms and closed bookings');
    // Liwonde has no company-billed rooms today (billing_account_id unset): fixture rows run through the SAME SQL in a
    // derived table (no writes), company legs and tolerance edges included. A company leg is a bookings row with its own
    // figures; the rule never reads billing_account_id, like the badge.
    $fx = [
        // id, status, payment_status, paid, due, credit, billing_account_id
        [9001, 'confirmed', 'partial', 50000, 25000, 0, 7],          // company leg, part paid
        [9002, 'checked-out', 'paid', 75000, 0, 0, 7],               // company leg, paid in full
        [9003, 'confirmed', 'unpaid', 0, 75000, 0, 7],               // company leg, nothing paid (billed later)
        [9004, 'confirmed', 'paid', 75000, 0.004, 0, 0],             // due within tolerance -> paid
        [9005, 'confirmed', 'partial', 0.004, 1000, 0, 0],           // paid within tolerance -> unpaid
        [9006, 'confirmed', 'completed', 60000, 15000, 0, 0],        // stale stored 'completed' with money owed -> part paid
        [9007, 'cancelled', 'refunded', 0, 0, 0, 0],                 // refunded
        [9008, 'cancelled', 'unpaid', 0, 0, 0, 0],                   // void, nothing billed -> unpaid (not Paid in full)
        [9009, 'cancelled', 'paid', 20000, 0, 0, 0],                 // kept fee paid -> paid
        [9010, 'no-show', 'partial', 10000, 40000, 0, 0],            // no-show owing -> part paid, not in Unpaid tab
        [9011, 'confirmed', 'paid', 90000, 0, 15000, 0],             // overpaid (credit) -> paid
        [9012, 'expired', null, 0, 0, 0, 0],                         // NULL stored status, nothing billed -> unpaid
        [9013, 'confirmed', null, 0, 5000, 0, 0],                    // NULL stored status -> unpaid (old rule: neither)
    ];
    $fxSel = []; $fxPar = [];
    foreach ($fx as $r) {
        $fxSel[] = 'SELECT ? AS id, ? AS status, ? AS payment_status, CAST(? AS DECIMAL(12,3)) AS amount_paid, CAST(? AS DECIMAL(12,3)) AS amount_due, CAST(? AS DECIMAL(12,3)) AS credit_balance, ? AS billing_account_id';
        array_push($fxPar, ...$r);
    }
    $fxStmt = $pdo->prepare('SELECT b.*, ' . $xSql(rh_bk_tab_clauses('paid')['clauses']) . ' AS in_paid, '
        . $xSql(rh_bk_tab_clauses('unpaid')['clauses']) . ' AS in_unpaid, ' . $xSql(rh_bk_pay_clause('part')['clauses']) . ' AS in_part, '
        . $xSql(rh_bk_pay_clause('refunded')['clauses']) . ' AS in_refunded, ' . rh_bk_pay_key_sql('b') . ' AS sql_key FROM (' . implode(' UNION ALL ', $fxSel) . ') b');
    $fxStmt->execute($fxPar);
    $fxMis = []; $fxN = 0;
    foreach ($fxStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $fxN++;
        $k = rh_booking_pay_state($r)['key'];
        $f = rh_bk_payment_flags($k, (string)$r['status']);
        $closed = in_array((string)$r['status'], ['cancelled', 'no-show', 'expired'], true);
        $want = [$k, $f['paid'], $f['unpaid'], $k === 'partial' && !$closed, $k === 'refunded'];
        $got = [(string)$r['sql_key'], (bool)$r['in_paid'], (bool)$r['in_unpaid'], (bool)$r['in_part'], (bool)$r['in_refunded']];
        if ($want !== $got) { $fxMis[] = '#' . $r['id'] . ' ' . json_encode(['want' => $want, 'got' => $got]); }
    }
    echo 'fixture cross-check: ' . $fxN . ' rows (3 company legs); mismatches ' . count($fxMis) . "\n";
    check($fxN === count($fx) && count($fxMis) === 0, 'fixture cross-check (company legs, tolerance edges, NULL status): SQL = badge', implode('; ', $fxMis));
    $fxKeys = [];
    foreach ($fx as $r) { $fxKeys[$r[0]] = rh_booking_pay_state(['status' => $r[1], 'payment_status' => $r[2], 'amount_paid' => $r[3], 'amount_due' => $r[4], 'credit_balance' => $r[5]])['key']; }
    eq([$fxKeys[9001], $fxKeys[9004], $fxKeys[9005], $fxKeys[9006], $fxKeys[9007], $fxKeys[9008], $fxKeys[9010]], ['partial', 'paid', 'unpaid', 'partial', 'refunded', 'unpaid', 'partial'], 'fixture keys are the expected badge words');
    check(count($xMis) === 0, 'cross-check: tab / filter SQL = badge for every booking (0 mismatches)', implode('; ', array_slice($xMis, 0, 5)));
    // The tab badge counts use the same clauses (SUM over the tab rule): they equal the row-by-row badge counts.
    $xCnt = $pdo->query("SELECT SUM(" . implode(' AND ', rh_bk_tab_clauses('paid')['clauses']) . ") AS p, SUM(" . implode(' AND ', rh_bk_tab_clauses('unpaid')['clauses']) . ") AS u FROM bookings b")->fetch(PDO::FETCH_ASSOC);
    $xWantP = 0; $xWantU = 0;
    foreach ($xRows as $r) { $f = rh_bk_payment_flags(rh_booking_pay_state($r)['key'], (string)$r['status']); $xWantP += $f['paid'] ? 1 : 0; $xWantU += $f['unpaid'] ? 1 : 0; }
    check((int)$xCnt['p'] === $xWantP && (int)$xCnt['u'] === $xWantU, 'tab counts (Paid ' . $xWantP . ', Unpaid ' . $xWantU . ') = the badges', json_encode($xCnt));
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
