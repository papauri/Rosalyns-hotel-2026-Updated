<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Check-in who and when (owner 2026-11-27: "on the booking page and details it shows whether someone has checked in and
 * a log of time and who did it and also add the checked in filter"). NO DATABASE.
 *   - admin/includes/booking-status-info.php: who in words, the merged check-in log (timeline + audit rows), the list's
 *     line under the Status pill, the Booking details "Check-in" block words.
 *   - admin/includes/bookings-list-filters.php: the "Checked in on" Dates choice (SQL builder, notice words).
 * The live proof that the "Checked in on" SQL picks the right bookings is a read-only query at Liwonde (not here).
 * Usage (repo root): php scripts/tests/checkin_who_when_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

date_default_timezone_set('Africa/Blantyre');
require_once $root . '/admin/includes/booking-status-info.php';
require_once $root . '/admin/includes/bookings-list-filters.php';
check(!isset($GLOBALS['pdo']) && !function_exists('getSetting'), 'loads without the database');

// Fixture rows, shaped like the two log tables.
function tl(string $type, ?string $nv, string $at, string $name = 'Ann Banda', string $by = 'admin', string $staff = ''): array
{
    return ['action_type' => $type, 'new_value' => $nv, 'performed_by_type' => $by, 'performed_by_name' => $name, 'created_at' => $at, 'staff_name' => $staff];
}
function au(string $action, string $at, string $name = 'Ann Banda', string $staff = ''): array
{
    return ['action' => $action, 'performed_by_name' => $name, 'performed_at' => $at, 'staff_name' => $staff];
}
function step(string $kind, string $when, string $who): array
{
    return ['kind' => $kind, 'when' => $when, 'who' => $who];
}

// ---- who, in words
eq(rh_checkin_who('guest', 'Jo Guest'), 'guest', 'who: a guest step says "guest"');
eq(rh_checkin_who('system', 'Night audit'), 'system', 'who: an automatic step says "system"');
eq(rh_checkin_who('api', 'Channel key'), 'API', 'who: an API step says "API"');
eq(rh_checkin_who('admin', '  Ann Banda '), 'Ann Banda', 'who: staff name as logged, trimmed');
eq(rh_checkin_who('ADMIN', 'Ann Banda'), 'Ann Banda', 'who: type in any case');
eq(rh_checkin_who('admin', '', 'Ben Phiri'), 'Ben Phiri', 'who: no logged name -> the admin_users name');
eq(rh_checkin_who('admin', ' ', ''), '', 'who: nothing recorded -> empty');

// ---- the merged log
// One check-in, logged in both tables a second apart: one step.
eq(rh_checkin_log_merge([tl('check_in', 'checked-in', '2026-11-12 14:06:00')], [au('checked-in', '2026-11-12 14:06:01')]),
    [step('in', '2026-11-12 14:06:00', 'Ann Banda')], 'merge: timeline + audit of the same check-in = one step (timeline time)');
// Audit row up to 120 s away is the same step; 121 s is not.
eq(count(rh_checkin_log_merge([tl('check_in', 'checked-in', '2026-11-12 14:06:00')], [au('checked-in', '2026-11-12 14:08:00')])), 1, 'merge: audit 120 s later is the same step');
eq(count(rh_checkin_log_merge([tl('check_in', 'checked-in', '2026-11-12 14:06:00')], [au('checked-in', '2026-11-12 14:08:01')])), 2, 'merge: audit 121 s later is its own step');
// Check in, cancel, check in again within two minutes: three steps, each audit row paired with the nearest timeline row.
eq(rh_checkin_log_merge(
    [tl('check_in', 'checked-in', '2026-11-12 10:00:00'), tl('check_in', 'checked-in', '2026-11-12 10:01:30')],
    [au('checked-in', '2026-11-12 10:00:00'), au('check-in cancelled', '2026-11-12 10:01:00', 'Ben Phiri'), au('checked-in', '2026-11-12 10:01:30')]
), [step('in', '2026-11-12 10:00:00', 'Ann Banda'), step('undo', '2026-11-12 10:01:00', 'Ben Phiri'), step('in', '2026-11-12 10:01:30', 'Ann Banda')],
    'merge: check-in, cancelled, check-in again = three steps in order');
// Two rows of one table never merge with each other.
eq(count(rh_checkin_log_merge([tl('check_in', 'checked-in', '2026-11-12 10:00:00'), tl('check_in', 'checked-in', '2026-11-12 10:00:30')], [])), 2, 'merge: two timeline check-ins 30 s apart stay two steps');
eq(count(rh_checkin_log_merge([], [au('checked-in', '2026-11-12 10:00:00'), au('checked-in', '2026-11-12 10:00:30')])), 2, 'merge: two audit check-ins 30 s apart stay two steps');
// A completed stay recorded late logs check-in and check-out in the same second: check-in first; its note row is not a step.
eq(rh_checkin_log_merge([
    tl('check_out', 'checked-out', '2026-11-12 09:00:00'),
    tl('check_in', 'checked-out', '2026-11-12 09:00:00'),
    tl('check_in', 'checked-in', '2026-11-12 09:00:00'),
], []), [step('in', '2026-11-12 09:00:00', 'Ann Banda'), step('out', '2026-11-12 09:00:00', 'Ann Banda')], 'merge: same second -> check-in before check-out; the "completed stay recorded" note is not a step');
// Notes on a check-in are not steps.
eq(rh_checkin_log_merge([tl('check_in', null, '2026-11-12 09:00:00'), tl('check_in', 'Late check-in', '2026-11-12 09:00:05')], []), [], 'merge: "not marked clean" / "Late check-in" notes are not steps');
// Check-out logged only in the audit log.
eq(rh_checkin_log_merge([tl('check_in', 'checked-in', '2026-11-10 12:00:00')], [au('checked-out', '2026-11-12 10:00:00', 'Ben Phiri')]),
    [step('in', '2026-11-10 12:00:00', 'Ann Banda'), step('out', '2026-11-12 10:00:00', 'Ben Phiri')], 'merge: an audit-only check-out is a step');
// Any timeline check_out row is a check-out (e.g. the checkout settlement row).
eq(rh_checkin_log_merge([tl('check_out', null, '2026-11-12 10:00:00', 'Night audit', 'system')], []), [step('out', '2026-11-12 10:00:00', 'system')], 'merge: a system check-out says "system"');
// bookings.checkout_completed_at only when no log row has the check-out.
eq(rh_checkin_log_merge([tl('check_in', 'checked-in', '2026-11-10 12:00:00')], [], '2026-11-13 11:00:00', 'Cara Mwale'),
    [step('in', '2026-11-10 12:00:00', 'Ann Banda'), step('out', '2026-11-13 11:00:00', 'Cara Mwale')], 'merge: checkout time from the booking when no log row has it');
eq(rh_checkin_log_merge([tl('check_in', 'checked-in', '2026-11-10 12:00:00'), tl('check_out', 'checked-out', '2026-11-13 10:59:58')], [], '2026-11-13 11:00:00', 'Cara Mwale'),
    [step('in', '2026-11-10 12:00:00', 'Ann Banda'), step('out', '2026-11-13 10:59:58', 'Ann Banda')], 'merge: no second check-out from the booking when the log has one');
eq(rh_checkin_log_merge([], [], '0000-00-00 00:00:00'), [], 'merge: a zero checkout date is ignored');
// A missing name is filled from the paired audit row, or the admin_users name.
eq(rh_checkin_log_merge([tl('check_in', 'checked-in', '2026-11-12 14:06:00', '')], [au('checked-in', '2026-11-12 14:06:01', 'Dan Tembo')])[0]['who'], 'Dan Tembo', 'merge: empty timeline name filled from the audit row');
eq(rh_checkin_log_merge([tl('check_in', 'checked-in', '2026-11-12 14:06:00', '', 'admin', 'Dan Tembo')], [])[0]['who'], 'Dan Tembo', 'merge: empty timeline name -> the admin_users name');
eq(rh_checkin_log_merge([tl('check_in', 'checked-in', '2026-11-12 14:06:00', 'Ann Banda')], [au('checked-in', '2026-11-12 14:06:01', 'Dan Tembo')])[0]['who'], 'Ann Banda', 'merge: a logged timeline name is kept');
// Bad or empty dates are skipped; input order does not matter.
eq(rh_checkin_log_merge([tl('check_in', 'checked-in', '0000-00-00 00:00:00')], [au('checked-in', '')]), [], 'merge: rows without a real time are skipped');
eq(rh_checkin_log_merge([tl('check_out', 'checked-out', '2026-11-14 10:00:00'), tl('check_in', 'checked-in', '2026-11-12 10:00:00')], [])[0]['kind'], 'in', 'merge: oldest first whatever the row order');

// ---- the latest check-in
$log = rh_checkin_log_merge(
    [tl('check_in', 'checked-in', '2026-11-12 10:00:00'), tl('check_in', 'checked-in', '2026-11-12 10:01:30', 'Eve Zulu'), tl('check_out', 'checked-out', '2026-11-14 09:00:00')],
    [au('check-in cancelled', '2026-11-12 10:01:00')]
);
eq(rh_checkin_last_in($log), step('in', '2026-11-12 10:01:30', 'Eve Zulu'), 'last in: the latest check-in, even after the check-out');
eq(rh_checkin_last_in([]), null, 'last in: none for an empty log');
eq(rh_checkin_last_in([step('out', '2026-11-14 09:00:00', 'Ann Banda')]), null, 'last in: none when only a check-out is logged');

// ---- the list's line under the Status pill
$ev = ['ci_when' => '2026-11-12 14:06:00', 'ci_who' => 'Ann Banda'];
eq(rh_bk_checkin_line($ev, 'checked-in', '2026-11-27'), 'Checked in 12 Nov 14:06 by Ann Banda', 'line: checked in this year');
eq(rh_bk_checkin_line($ev, 'checked-out', '2026-11-27'), 'Checked in 12 Nov 14:06 by Ann Banda', 'line: a checked-out row shows its check-in too');
eq(rh_bk_checkin_line(['ci_when' => '2025-12-30 09:05:00', 'ci_who' => 'Ann Banda'], 'checked-out', '2026-11-27'), 'Checked in 30 Dec 2025 09:05 by Ann Banda', 'line: another year shows the year');
eq(rh_bk_checkin_line(['ci_when' => '2026-11-12 14:06:00', 'ci_who' => ''], 'checked-in', '2026-11-27'), 'Checked in 12 Nov 14:06 (name not recorded)', 'line: no name recorded');
eq(rh_bk_checkin_line(['ci_when' => '2026-11-12 14:06:00', 'ci_who' => 'guest'], 'checked-in', '2026-11-27'), 'Checked in 12 Nov 14:06 by guest', 'line: by guest');
eq(rh_bk_checkin_line(null, 'checked-in', '2026-11-27'), 'Checked in: time not recorded', 'line: no log row');
eq(rh_bk_checkin_line(['when' => '2026-11-14 09:00:00'], 'checked-out', '2026-11-27'), 'Checked in: time not recorded', 'line: a check-out event alone is not a check-in time');
eq(rh_bk_checkin_line(['ci_when' => '0000-00-00 00:00:00'], 'checked-in', '2026-11-27'), 'Checked in: time not recorded', 'line: zero date');
foreach (['confirmed', 'pending', 'tentative', 'cancelled', 'no-show', 'expired', ''] as $st) {
    eq(rh_bk_checkin_line($ev, $st, '2026-11-27'), '', "line: nothing for status '$st'");
}

// ---- Booking details block in words
eq(rh_checkin_state('checked-in'), ['label' => 'Checked in', 'class' => 'ok'], 'state: checked in');
eq(rh_checkin_state('checked-out'), ['label' => 'Checked out', 'class' => 'muted'], 'state: checked out');
eq(rh_checkin_state('confirmed'), ['label' => 'Not checked in', 'class' => 'muted'], 'state: confirmed = not checked in');
eq(rh_checkin_state(''), ['label' => 'Not checked in', 'class' => 'muted'], 'state: unknown = not checked in');
eq(rh_checkin_step_words(step('in', '2026-11-12 14:06:00', 'Ann Banda')), ['what' => 'Checked in', 'when' => 'Thu 12 Nov 2026, 14:06', 'who' => 'by Ann Banda'], 'words: check-in step');
eq(rh_checkin_step_words(step('undo', '2026-11-12 14:09:00', '')), ['what' => 'Check-in cancelled', 'when' => 'Thu 12 Nov 2026, 14:09', 'who' => 'name not recorded'], 'words: cancelled, no name');
eq(rh_checkin_step_words(step('out', '2026-11-14 10:00:00', 'system')), ['what' => 'Checked out', 'when' => 'Sat 14 Nov 2026, 10:00', 'who' => 'by system'], 'words: check-out by system');

// ---- "Checked in on" (Dates select)
eq(rh_bk_date_type('checked_in'), 'checked_in', 'date type: checked_in whitelisted');
eq(rh_bk_date_type('Checked_In'), 'checked_in', 'date type: any case');
eq(rh_bk_date_types()['checked_in'] ?? null, 'Checked in on', 'date type label: "Checked in on"');
$ciSql = "b.status IN ('checked-in', 'checked-out') AND (SELECT MAX(tci.created_at) FROM booking_timeline_logs tci"
    . " WHERE tci.booking_id = b.id AND tci.action_type = 'check_in' AND tci.new_value = 'checked-in')";
eq(rh_bk_date_scope('2026-11-12', '2026-11-14', 'checked_in'), ['clauses' => ['(' . $ciSql . ' >= ?)', '(' . $ciSql . ' < ?)'], 'params' => ['2026-11-12 00:00:00', '2026-11-15 00:00:00']],
    'checked in on: latest check-in from From 00:00 to the day after To 00:00, checked in now or checked out since');
eq(rh_bk_date_scope('2026-11-12', '', 'checked_in'), ['clauses' => ['(' . $ciSql . ' >= ?)'], 'params' => ['2026-11-12 00:00:00']], 'checked in on: From only');
eq(rh_bk_date_scope('', '2026-11-14', 'checked_in'), ['clauses' => ['(' . $ciSql . ' < ?)'], 'params' => ['2026-11-15 00:00:00']], 'checked in on: To only (status test still there)');
eq(rh_bk_date_scope('', '2026-12-31', 'checked_in')['params'], ['2027-01-01 00:00:00'], 'checked in on: To = 31 Dec rolls into the new year');
eq(rh_bk_date_scope('', '', 'checked_in'), ['clauses' => [], 'params' => []], 'checked in on: no dates -> no clauses');
$sc = rh_bk_date_scope('2026-11-12', '2026-11-14', 'checked_in');
check(count($sc['clauses']) === count($sc['params']) && substr_count(implode(' ', $sc['clauses']), '?') === 2, 'checked in on: one placeholder per clause, one param each');
check(strpos(implode(' ', $sc['clauses']), '2026') === false, 'checked in on: dates are bound, never in the SQL');
check(substr_count(implode(' ', $sc['clauses']), '(') === substr_count(implode(' ', $sc['clauses']), ')'), 'checked in on: brackets balance (each clause stands alone after AND)');
// Through the list's own cleaner and scope (the same builder as the list, badges, count and CSV export).
$scope = rh_bk_scope(rh_bk_filters_from_get(['date_type' => 'checked_in', 'date_from' => '2026-11-14', 'date_to' => '2026-11-12']));
check(in_array('(' . $ciSql . ' >= ?)', $scope['clauses'], true) && in_array('(' . $ciSql . ' < ?)', $scope['clauses'], true)
    && in_array('2026-11-12 00:00:00', $scope['params'], true) && in_array('2026-11-15 00:00:00', $scope['params'], true), 'checked in on: list scope carries it (From after To swapped)');
check(count($scope['params']) === substr_count(implode(' ', $scope['clauses']), '?'), 'checked in on: list scope placeholders = params');
eq(rh_bk_keep_params(rh_bk_filters_from_get(['date_from' => '2026-11-12', 'date_type' => 'checked_in']))['date_type'] ?? '', 'checked_in', 'checked in on: page links keep it');
// Notice / chip words.
eq(rh_bk_date_scope_words('2026-11-12', '2026-11-12', 'checked_in'), 'checked in on 12 Nov 2026', 'words: one day');
eq(rh_bk_date_scope_words('2026-11-12', '2026-11-14', 'checked_in'), "checked in 12\u{2013}14 Nov 2026", 'words: range');
eq(rh_bk_date_scope_words('2026-11-12', '', 'checked_in'), 'checked in on or after 12 Nov 2026', 'words: From only');
eq(rh_bk_date_scope_words('', '2026-11-14', 'checked_in'), 'checked in on or before 14 Nov 2026', 'words: To only');

// ---- screens wired (source checks)
$bk = (string)file_get_contents($root . '/admin/bookings.php');
check(strpos($bk, "['checked-in', 'Checked in', 'fa-sign-in-alt', \$checked_in, 'Guests checked in now (in house).']") !== false, 'tab: "Checked in", tooltip "Guests checked in now (in house)."');
check(strpos($bk, "'checked-in': 'Checked in',") !== false, 'tab title (JS) matches');
check(substr_count($bk, 'rh_bk_checkin_line(') === 1 && substr_count($bk, 'class="bk-checkin-line"') === 1 && substr_count($bk, 'class="bk-checkin-line bk-checkin-line--twin"') === 1, 'list: one line under the Status pill + its twin under the total');
check(preg_match('/<small class="bk-checkin-line(?: bk-checkin-line--twin)?"><\?php echo htmlspecialchars\(\$bk_ci_line\); \?><\/small>/', $bk) === 1
    && preg_match_all('/htmlspecialchars\(\$bk_ci_line\)/', $bk) === 2, 'list: the line is escaped both times');
$bd = (string)file_get_contents($root . '/admin/booking-details.php');
check(strpos($bd, '$bd_checkin_log = rh_checkin_log_load($pdo, $booking);') !== false && strpos($bd, 'id="check-in"') !== false, 'details: the Check-in block is loaded and shown');
check(preg_match('/id="bdCheckinTitle">Check-in\s*<i class="help" data-tip="[^"]+">\?<\/i>/', $bd) === 1, 'details: the block has its "?" helper');

echo "\n" . ($fail === 0 ? "ALL PASS ($pass)" : "FAILED $fail of " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
