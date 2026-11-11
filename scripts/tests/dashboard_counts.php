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

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
