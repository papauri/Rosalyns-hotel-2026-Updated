<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Front-desk count rules (admin/includes/front-desk-counts.php) used by the Dashboard, Calendar and End of Day.
 * NO DATABASE. Usage (repo root): php scripts/tests/dashboard_counts.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

require_once $root . '/admin/includes/front-desk-counts.php';
check(!isset($GLOBALS['pdo']), 'loads without the database');
$today = '2026-11-11';

// Arrivals tab = still to arrive (the dashboard list also holds arrived guests).
$arrivals = [
    ['check_in_date' => $today, 'status' => 'confirmed'],
    ['check_in_date' => $today, 'status' => 'pending'],
    ['check_in_date' => $today, 'status' => 'checked-in'],
    ['check_in_date' => $today, 'status' => 'checked-out'],
];
eq(rh_fd_count_still_to_arrive($arrivals, $today), 2, 'confirmed + pending counted, arrived not');
eq(rh_fd_count_still_to_arrive([['check_in_date' => $today, 'status' => 'checked-in']], $today), 0, 'guest arrived -> Arrivals shows 0, not (1) all day');
eq(rh_fd_count_still_to_arrive([], $today), 0, 'no arrivals -> 0');
eq(rh_fd_count_still_to_arrive([['check_in_date' => '2026-11-10', 'status' => 'confirmed']], $today), 0, 'yesterday is not today');
eq(rh_fd_count_still_to_arrive([['check_in_date' => $today, 'status' => 'tentative']], $today), 0, 'tentative hold is not an arrival');

// Missed arrival (Calendar) = confirmed or pending, arrival date passed.
check(rh_fd_is_missed_arrival('confirmed', '2026-11-10', $today), 'confirmed past arrival flagged');
check(rh_fd_is_missed_arrival('pending', '2026-11-10', $today), 'pending past arrival flagged (as the Room Dashboard)');
check(!rh_fd_is_missed_arrival('pending', $today, $today), 'due today is not missed');
check(!rh_fd_is_missed_arrival('checked-in', '2026-11-10', $today), 'checked in is not missed');
check(!rh_fd_is_missed_arrival('tentative', '2026-11-10', $today), 'tentative not flagged');

// Departures with a balance = due out today, owing (matches Bookings "Today's Check-outs").
$deps = [
    ['departure_state' => 'due', 'amount_due' => 5000],
    ['departure_state' => 'due', 'amount_due' => 0.004],
    ['departure_state' => 'due', 'amount_due' => 0],
    ['departure_state' => 'overdue', 'amount_due' => 9000],
    ['departure_state' => 'departed', 'amount_due' => 100],
];
eq(rh_fd_departures_owing($deps), 1, 'only due-today owing counted (overdue has its own chip, departed gone, tolerance respected)');
eq(rh_fd_departures_owing([]), 0, 'no departures -> 0');

// End of Day tentative note.
eq(rh_fd_tentative_note(['tentative_arrivals' => 2]), 'Tentative holds (not counted): 2', 'tentative holds line');
eq(rh_fd_tentative_note(['tentative_arrivals' => 0]), '', 'no holds -> no line');
eq(rh_fd_tentative_note([]), '', 'missing key -> no line');

// Overdue departures chip count = rows the dashboard marks 'overdue' (checked in, check-out before today); the chip
// opens bookings.php?departure=overdue whose clause is the same rule (scripts/tests/bookings_filters.php).
$overdue = count(array_filter([
    ['status' => 'checked-in', 'check_out_date' => '2026-11-10'],
    ['status' => 'checked-in', 'check_out_date' => $today],
    ['status' => 'checked-out', 'check_out_date' => '2026-11-09'],
], function ($r) use ($today) { return $r['status'] === 'checked-in' && $r['check_out_date'] < $today; }));
eq($overdue, 1, 'overdue departure = still checked in after the check-out date (due today is not overdue)');

// Tomorrow preview uses the same tentative note.
eq(rh_fd_tentative_note(['arrivals' => 3, 'tentative_arrivals' => 1]), 'Tentative holds (not counted): 1', 'tomorrow tentative note');

// Per-row departure money flags (BALANCE_TOLERANCE, not a raw 0.01).
if (!defined('BALANCE_TOLERANCE')) define('BALANCE_TOLERANCE', 0.01);
check(!rh_fd_departure_owes(['departure_state' => 'due', 'amount_due' => 0.01]), 'exactly the tolerance is settled');
check(rh_fd_departure_owes(['departure_state' => 'due', 'amount_due' => 0.011]), 'just above the tolerance owes');
check(!rh_fd_departure_owes(['departure_state' => 'overdue', 'amount_due' => 9000]), 'overdue owing is not in the due-today badge');
check(rh_fd_departure_has_balance(['departure_state' => 'overdue', 'amount_due' => 9000]), 'overdue owing still has a balance (never "Settled")');
check(!rh_fd_departure_has_balance(['departure_state' => 'departed', 'amount_due' => 100]), 'departed row has no live balance');
check(!rh_fd_departure_has_balance(['departure_state' => 'due', 'amount_due' => -5]), 'credit is not a balance');

// Check-ins popup subtitle: counts from COUNT queries, cap noted only when rows were cut.
eq(rh_fd_checkins_subtitle(25, 15, 30), 'Everyone due in today, with their status: 25 still to arrive, 15 already arrived (showing first 30)', '40 check-ins, 30 shown -> real counts + cap note');
eq(rh_fd_checkins_subtitle(3, 2, 5), 'Everyone due in today, with their status: 3 still to arrive, 2 already arrived', 'all shown -> no cap note');
eq(rh_fd_checkins_subtitle(0, 0, 0), 'Everyone due in today, with their status: 0 still to arrive, 0 already arrived', 'empty day');

// Source checks on the dashboard (no database).
$dash = (string)file_get_contents($root . '/admin/dashboard.php');
check(!preg_match('/>\s*0\.01\b/', $dash), 'dashboard: no raw > 0.01 money comparison');
check(strpos($dash, '$departuresOwing = 0;') === false, 'dashboard: dead $departuresOwing = 0 removed');
check(strpos($dash, "rh_gym_member_filter_where()") !== false && strpos($dash, "\$gmWhere['active']") !== false, 'dashboard: gym active members use the Gym Members Active rule');
check(strpos($dash, "FROM gym_members WHERE status='active'\")") === false, 'dashboard: no raw status=active member count');
check(strpos($dash, 'rh_fd_checkins_subtitle(') !== false && strpos($dash, 'count($rows) - $ciStillToArrive') === false, 'dashboard: check-ins popup counts do not come from the capped rows');
check(strpos($dash, "count(\$roomServiceQueue)]") === false, 'dashboard: room-service tab badge is not the 10-row list length');
foreach (["'contact-inquiries.php?status=new'", "'events-inquiries.php?status=pending'", "'gym-inquiries.php?status=new'", 'gym-members.php?filter=active',
          "'bookings.php?filter=checkout_today&balance=owing', null", "'housekeeping.php?due=now'", "'room-maintenance.php?status=out_of_service'"] as $lnk) {
    check(strpos($dash, $lnk) !== false, "dashboard: chip opens $lnk");
}
check(strpos($dash, "gym_inquiries WHERE status='pending' OR status='new'") === false, 'dashboard: gym enquiry count = the New tab only');

// ---- Round 2026-11-11 (final): every chip / KPI counts what its link or popup lists.
// Arrived (KPI) = the popup's "already arrived": checked in, including same-day check-outs.
eq(rh_fd_arrived_statuses(), ['checked-in', 'checked-out'], 'arrived = checked in or already checked out');
$dayRows = [
    ['status' => 'confirmed'], ['status' => 'pending'], ['status' => 'checked-in'], ['status' => 'checked-out'], ['status' => 'tentative'],
];
$kpiArrived = count(array_filter($dayRows, function ($r) { return in_array($r['status'], rh_fd_arrived_statuses(), true); }));
$kpiStill = count(array_filter($dayRows, function ($r) { return in_array($r['status'], rh_fd_still_to_arrive_statuses(), true); }));
eq([$kpiArrived, $kpiStill], [2, 2], 'arrived-and-left guest is in the KPI arrived figure (2 of 4), tentative in neither');
check(!preg_match("/check_in_date = \\? AND status = 'checked-in'\\) AS arrived/", $dash), 'dashboard: KPI arrived no longer checked-in only');
check(strpos($dash, "rh_fd_status_in_sql('status', rh_fd_arrived_statuses())") !== false, 'dashboard: KPI arrived uses rh_fd_arrived_statuses');
check(strpos($dash, "check_in_date = ? AND status IN (") === false && strpos($dash, "b.status IN ('confirmed','pending')") === false, 'dashboard: check-in counts use rh_fd_still_to_arrive_statuses, no hard-coded list');

// SQL list builder: fixed literals only.
eq(rh_fd_status_in_sql('b.status', ['confirmed', 'pending']), "b.status IN ('confirmed','pending')", 'status IN builder');
$threw = false;
try { rh_fd_status_in_sql('status', ["x') OR 1=1 -- "]); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'status IN builder refuses anything but a plain status');
require_once $root . '/admin/includes/bookings-list-filters.php';
eq(rh_bk_tab_clauses('today-checkins')['clauses'][1], rh_fd_status_in_sql('b.status', rh_fd_still_to_arrive_statuses()), "Bookings Today's Check-ins statuses = rh_fd_still_to_arrive_statuses");

// Room-service open: count statuses = the statuses the Orders & Tabs link filters on.
eq(rh_fd_room_service_open_statuses(), ['placed', 'pending', 'confirmed'], 'room-service open statuses');
check(strpos($dash, "'placed','pending','confirmed'") === false && strpos($dash, "'placed', 'pending', 'confirmed')\")") === false, 'dashboard: no hard-coded room-service status list in counts');
check(substr_count($dash, 'rh_fd_room_service_open_href()') >= 4, 'dashboard: chip, popup, ops row and See all use the one link');
if (is_file($root . '/admin/includes/stock-orders-filters.php')) {
    require_once $root . '/admin/includes/stock-orders-filters.php';
    parse_str((string)parse_url(rh_fd_room_service_open_href(), PHP_URL_QUERY), $rsq);
    $rsf = rh_so_normalize_filters($rsq);
    eq($rsf['order_type'], 'room_service', 'room-service link: order_type kept by Orders & Tabs');
    $rsList = function_exists('rh_so_status_list') ? rh_so_status_list($rsf['status']) : [$rsf['status']];
    eq($rsList, rh_fd_room_service_open_statuses(), 'room-service link: Orders & Tabs keeps exactly the counted statuses');
    eq($rsf['date'], '', 'room-service link: no date window (the count has none)');
}

// Room-service reminders: one NOT EXISTS rule for both counts and the popup list.
$unserved = rh_fd_room_service_unserved_sql('b');
check(strpos($unserved, 'rso.booking_id = b.id OR (rso.booking_id IS NULL AND rso.individual_room_id = b.individual_room_id)') !== false, 'reminder rule: booking order, or room order without a booking');
check(strpos($unserved, 'DATE(COALESCE(rso.served_at, rso.updated_at, rso.created_at)) = CURDATE()') !== false, 'reminder rule: served today');
check(substr_count($dash, "rh_fd_room_service_unserved_sql('b')") >= 4, 'dashboard: reminder count, ops count, popup list and popup total share the rule');
check(strpos($dash, 'COALESCE(rs_booking.completed_today, rs_room.completed_today') === false, 'dashboard: popup no longer hides a room order behind an older booking order');
check(strpos($dash, "'Room-service reminders due', 'fa-bell', 'amber', 'stock-orders.php") === false, 'dashboard: reminders chip does not open an orders list');

// Housekeeping due + rooms out of service: one rule for the chip and the page filter.
eq(rh_fd_housekeeping_due_sql('ha'), "ha.status IN ('pending','in_progress') AND (ha.due_date IS NULL OR ha.due_date <= CURDATE())", 'housekeeping due rule');
check(substr_count($dash, 'rh_fd_housekeeping_due_sql()') === 2, 'dashboard: chip and ops row use the housekeeping due rule');
$hk = (string)file_get_contents($root . '/admin/housekeeping.php');
check(strpos($hk, "rh_fd_housekeeping_due_sql('ha')") !== false && strpos($hk, "'rh_due_now'") !== false, 'housekeeping: ?due=now filters on the same SQL rule');
check(strpos($hk, 'foreach ($hkTableRows as $row)') !== false && strpos($hk, '}, $assignments), JSON_HEX_TAG') !== false, 'housekeeping: table filtered, stat-card data unfiltered');
eq(rh_fd_out_of_service_statuses(), ['maintenance', 'out_of_order'], 'out of service statuses');
$rm = (string)file_get_contents($root . '/admin/room-maintenance.php');
check(strpos($rm, "=== 'out_of_service'") !== false && strpos($rm, 'rh_fd_out_of_service_statuses()') !== false, 'room maintenance: ?status=out_of_service uses the chip statuses');
check(strpos($dash, "rh_room_units_count(['maintenance', 'out_of_order'])") === false, 'dashboard: out-of-service count uses the shared statuses');

// admin-anchor.js filter keys land on the results.
$js = (string)file_get_contents($root . '/admin/js/admin-anchor.js');
if (check((bool)preg_match('~var FILTER_KEY = /(.+)/i;~', $js, $m), 'anchor: FILTER_KEY found')) {
    $re = '/' . $m[1] . '/i';
    foreach (['event_date', 'arrival', 'departure', 'status', 'filter', 'refund_status', 'balance', 'due'] as $k) check((bool)preg_match($re, $k), "anchor: $k is a filter key");
    foreach (['tab', 'id', 'flash', 'action'] as $k) check(!preg_match($re, $k), "anchor: $k is not a filter key");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
