<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Housekeeping, Maintenance + Room Service room timeline (admin/includes/ops-timeline-data.php, admin/js/room-timeline-ops.js).
 * Usage (repo root, TEST HOTEL): php scripts/tests/ops_timeline_test.php
 * Read-only except (e), which inserts two maintenance rows inside a transaction and ROLLS BACK. Cases:
 *  (a) deep-link parameters: valid values kept, invalid ones ignored (id, room, date, view, days)
 *  (b) housekeeping items (pure): due-day bars, open past tasks carried onto today, closed ones not, overdue flag
 *  (c) maintenance items (pure): block_room 0 included, cancelled / old done jobs out, overdue runs to today,
 *      no To date runs to the range edge, figure-card keys, "affects bookings" via booking_rooms
 *  (d) payload shape for both modes on live data; card counts equal the Calendar tabs' counts
 *  (e) the Maintenance grid shows a block_room = 0 job, the Calendar still does not (transaction, rolled back)
 *  (f) the endpoints: 401 without a session, permission + module gate before any data
 *  (g) links: calendar maintenance blocks / status cells / housekeeping icon point at the new deep links
 *  (h) the engine seam and the pages: modes registered, same POST actions and confirm texts, list hook, no-JS fallback
 *  (i) Room Service: page wiring, endpoint gate, day validation, pure order bars, deep-link id refusal, live payload shape
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/admin/includes/ops-timeline-data.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$today = date('Y-m-d');
$day = static fn (int $n): string => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));

// ---------------------------------------------------------------------------------------------- (a)
echo "(a) Deep links\n";
$dl = rh_ops_deeplink(['id' => '42', 'room' => '101', 'date' => '2026-12-01', 'view' => 'list', 'days' => '14'], 'housekeeping');
ok('valid HK link kept', $dl['id'] === 42 && $dl['room'] === '101' && $dl['room_id'] === 0 && $dl['date'] === '2026-12-01' && $dl['view'] === 'list' && $dl['days'] === 14, json_encode($dl));
$dl = rh_ops_deeplink(['id' => '0', 'room' => '<script>', 'date' => '2026-02-30', 'view' => 'grid', 'days' => '5'], 'housekeeping');
ok('invalid HK link ignored (id 0, bad room, 30 Feb, unknown view, 5 days)', $dl['id'] === 0 && $dl['room'] === '' && $dl['date'] === null && $dl['view'] === '' && $dl['days'] === 0, json_encode($dl));
$dl = rh_ops_deeplink(['id' => ['1'], 'room' => ['x'], 'date' => ['2026-01-01'], 'view' => ['list'], 'days' => ['7']], 'housekeeping');
ok('array parameters ignored', $dl['id'] === 0 && $dl['room'] === '' && $dl['date'] === null && $dl['view'] === '' && $dl['days'] === 0, json_encode($dl));
$dl = rh_ops_deeplink(['id' => '-3', 'room' => '12', 'view' => 'timeline', 'days' => '30'], 'maintenance');
ok('maintenance room = room id; negative id ignored', $dl['id'] === 0 && $dl['room_id'] === 12 && $dl['room'] === '' && $dl['view'] === 'timeline' && $dl['days'] === 30, json_encode($dl));
$dl = rh_ops_deeplink(['room' => '12abc', 'id' => '1.5'], 'maintenance');
ok('maintenance: non-numeric room and decimal id ignored', $dl['room_id'] === 0 && $dl['id'] === 0, json_encode($dl));

// ---------------------------------------------------------------------------------------------- (b)
echo "\n(b) Housekeeping items\n";
$hkRow = static fn (array $o): array => $o + ['id' => 1, 'individual_room_id' => 7, 'room_number' => '101', 'room_name' => 'Garden',
    'status' => 'pending', 'due_date' => $today, 'assignment_type' => 'checkout_cleanup', 'priority' => 'high', 'assigned_to' => null,
    'assigned_to_name' => null, 'notes' => 'Towels', 'linked_booking_id' => null, 'scheduled_time' => null, 'started_at' => null,
    'completed_at' => null, 'verified_at' => null];
$rows = [
    $hkRow(['id' => 1, 'due_date' => $day(2)]),
    $hkRow(['id' => 2, 'due_date' => $day(-3)]),                                     // open, past: carried to today
    $hkRow(['id' => 3, 'due_date' => $day(-3), 'status' => 'completed']),            // closed, past: not drawn
    $hkRow(['id' => 4, 'due_date' => null, 'status' => 'in_progress']),              // open, undated: carried
    $hkRow(['id' => 5, 'due_date' => $day(1), 'status' => 'completed', 'verified_at' => $today . ' 10:00:00']),
    $hkRow(['id' => 6, 'due_date' => $day(40)]),                                     // after the range
];
$items = rh_ops_hk_items($rows, [], $today, $day(6), $today);
$by = array_column($items, null, 'id');
ok('in-range task drawn on its due day', isset($by[1]) && $by[1]['day'] === $day(2) && !$by[1]['carried'] && !$by[1]['overdue']);
ok('open past task carried onto today, flagged overdue', isset($by[2]) && $by[2]['day'] === $today && $by[2]['carried'] && $by[2]['overdue']);
ok('closed past task not drawn', !isset($by[3]));
ok('undated open task carried, not overdue', isset($by[4]) && $by[4]['day'] === $today && $by[4]['carried'] && !$by[4]['overdue']);
ok('completed + verified_at reads as verified', isset($by[5]) && $by[5]['status'] === 'verified');
ok('task after the range not drawn', !isset($by[6]));
$future = rh_ops_hk_items($rows, [], $day(10), $day(16), $today);
ok('no carrying when today is not in the range', !in_array(2, array_column($future, 'id'), true) && !in_array(4, array_column($future, 'id'), true));
$editKeys = ['id', 'individual_room_id', 'assignment_type', 'priority', 'due_date', 'status', 'assigned_to', 'estimated_duration',
    'actual_duration', 'notes', 'is_recurring', 'recurring_pattern', 'recurring_end_date', 'scheduled_time', 'started_at'];
ok('item carries every key editAssignment() reads', isset($by[1]) && !array_diff($editKeys, array_keys($by[1]['edit'])));
ok('item has no money fields', isset($by[1]) && !preg_match('/amount|price|paid|balance|rate/i', implode(' ', array_keys($by[1]))));

// ---------------------------------------------------------------------------------------------- (c)
echo "\n(c) Maintenance items\n";
$mRow = static fn (array $o): array => $o + ['id' => 1, 'individual_room_id' => 7, 'room_number' => '101', 'room_name' => '', 'title' => 'Leak',
    'description' => '', 'status' => 'planned', 'priority' => 'medium', 'maintenance_type' => 'repair', 'block_room' => 1,
    'start_date' => $day(1) . ' 00:00:00', 'end_date' => $day(3) . ' 00:00:00', 'due_date' => null, 'assigned_to' => null,
    'assigned_to_name' => null, 'created_by_name' => 'admin', 'completed_at' => null, 'verified_at' => null, 'started_at' => null];
$mRows = [
    $mRow(['id' => 1, 'block_room' => 0]),
    $mRow(['id' => 2]),
    $mRow(['id' => 3, 'status' => 'cancelled']),
    $mRow(['id' => 4, 'status' => 'completed', 'completed_at' => $day(-10) . ' 09:00:00']),
    $mRow(['id' => 5, 'status' => 'completed', 'completed_at' => $day(-2) . ' 09:00:00']),
    $mRow(['id' => 6, 'start_date' => $day(-6) . ' 00:00:00', 'end_date' => $day(-2) . ' 00:00:00', 'status' => 'in_progress']),
    $mRow(['id' => 7, 'start_date' => $day(2) . ' 00:00:00', 'end_date' => '0000-00-00 00:00:00']),
    $mRow(['id' => 8, 'start_date' => $day(20) . ' 00:00:00', 'end_date' => $day(22) . ' 00:00:00', 'due_date' => $day(20)]),
];
$bookings = [['id' => 900, 'individual_room_id' => null, 'room_id' => 1, 'check_in_date' => $day(2), 'check_out_date' => $day(5), 'effective_check_out' => $day(5)]];
$held = [900 => [7 => true]];
$mi = rh_ops_maint_items($mRows, $bookings, $held, $today, $day(13), $today);
$mb = array_column($mi, null, 'id');
ok('block_room 0 job included, marked as not blocking', isset($mb[1]) && $mb[1]['blocks'] === false);
ok('block_room 1 job included, blocking', isset($mb[2]) && $mb[2]['blocks'] === true);
ok('job nights: From .. To minus one second', isset($mb[2]) && $mb[2]['from'] === $day(1) && $mb[2]['to'] === $day(2));
ok('cancelled job left out', !isset($mb[3]));
ok('job done 10 days ago left out, done 2 days ago shown', !isset($mb[4]) && isset($mb[5]) && $mb[5]['status'] === 'completed');
ok('open job past its To date runs on to today, overdue', isset($mb[6]) && $mb[6]['draw_to'] === $today && $mb[6]['overdue'] && !$mb[6]['open_end']);
ok('job with no To date runs to the range edge, not overdue', isset($mb[7]) && $mb[7]['open_end'] && !$mb[7]['overdue'] && $mb[7]['draw_to'] === $day(13));
ok('job after the range left out', !isset($mb[8]));
ok('figure-card keys: planned job is open and due this week', isset($mb[2]) && $mb[2]['cards'] === ['open' => true, 'progress' => false, 'due' => true]);
ok('figure-card keys: in-progress overdue job', isset($mb[6]) && $mb[6]['cards'] === ['open' => true, 'progress' => true, 'due' => true]);
ok('figure-card keys: done job counts in none', isset($mb[5]) && !in_array(true, $mb[5]['cards'], true));
$pageRow = ['status' => 'planned', 'verified_at' => null, 'due_date' => null, 'start_date' => $day(3) . ' 00:00:00']; // as the list SELECT rms.* gives it
ok('list row: raw "planned" status is open and due (page call and raw value)', rh_ops_maint_cards($pageRow, rh_ops_maint_status($pageRow), $today) === ['open' => true, 'progress' => false, 'due' => true]
    && rh_ops_maint_cards($pageRow, 'planned', $today)['open'] === true);
ok('list row: page normalises status before the card keys', strpos((string)file_get_contents($root . '/admin/room-maintenance.php'), 'rh_ops_maint_cards($row, rh_ops_maint_status($row), $opsToday)') !== false);
ok('due card: job due in 20 days is not due this week',rh_ops_maint_cards($mRows[7], 'pending', $today)['due'] === false);
ok('affects bookings: a stay holding the room via booking_rooms is counted', isset($mb[2]) && $mb[2]['affects'] === 1 && $mb[2]['first_stay'] === $day(2));
ok('affects bookings: no overlap, no count', isset($mb[6]) && $mb[6]['affects'] === 0);
$mEdit = ['id', 'individual_room_id', 'title', 'description', 'maintenance_type', 'priority', 'due_date', 'assigned_to', 'block_room',
    'start_date', 'end_date', 'status', 'started_at'];
ok('item carries the keys editSchedule() reads', isset($mb[1]) && !array_diff($mEdit, array_keys($mb[1]['edit'])));

// Lanes (pure).
$lanes = rh_ops_lanes(
    [['id' => 7, 'room_type_id' => 3, 'room_number' => '101', 'room_name' => 'Garden', 'floor' => '1', 'status' => 'maintenance', 'room_type_name' => 'Deluxe'],
     ['id' => 8, 'room_type_id' => 3, 'room_number' => '102', 'room_name' => '', 'floor' => '1', 'status' => 'available', 'room_type_name' => 'Deluxe']],
    [7 => ['state' => 'dirty', 'label' => 'Dirty', 'task' => ['assigned_to' => null], 'open' => 1]],
    rh_ops_stay_nights($bookings, $held, [$day(1), $day(2), $day(3)])
);
ok('one lane per room, grouped by type', count($lanes['lanes']) === 2 && count($lanes['groups']) === 1 && $lanes['groups'][0]['lanes'] === [7, 8]);
ok('lane flags: out of service, hk state, unassigned task, stay nights', $lanes['lanes'][0]['out'] === true && $lanes['lanes'][0]['hk']['state'] === 'dirty'
    && $lanes['lanes'][0]['unassigned'] === true && $lanes['lanes'][0]['stay'] === [$day(2), $day(3)] && $lanes['lanes'][1]['stay'] === []);

// ---------------------------------------------------------------------------------------------- (d)
echo "\n(d) Payload on live data\n";
$keys = ['ok', 'mode', 'start', 'end', 'days', 'today', 'dates', 'groups', 'lanes', 'items', 'counts', 'can', 'error'];
$hkP = rh_ops_payload($pdo, 'housekeeping', $today, 7);
ok('HK payload has every key', !array_diff($keys, array_keys($hkP)) && $hkP['ok'] === true && $hkP['mode'] === 'housekeeping');
ok('HK payload: 7 dates from start, today flagged', count($hkP['dates']) === 7 && $hkP['dates'][0]['d'] === $today && $hkP['dates'][0]['today'] === true && $hkP['end'] === $day(6));
$laneIds = array_column($hkP['lanes'], 'id');
$groupLanes = array_merge(...array_map(static fn ($g) => $g['lanes'], $hkP['groups'] ?: [['lanes' => []]]));
ok('HK payload: every lane is in exactly one group', count($laneIds) === count($groupLanes) && !array_diff($laneIds, $groupLanes));
$badItem = array_filter($hkP['items'], static fn ($i) => $i['day'] < $today || $i['day'] > $day(6));
ok('HK payload: every task sits inside the range (' . count($hkP['items']) . ' tasks)', !$badItem);
$calHk = rh_cal_hk_tab($pdo, '', $today)['counts'];
ok('HK cards equal the Calendar Housekeeping tab counts', $hkP['counts'] == $calHk, json_encode([$hkP['counts'], $calHk]));
$mtP = rh_ops_payload($pdo, 'maintenance', $today, 14);
ok('maintenance payload has every key', !array_diff($keys, array_keys($mtP)) && $mtP['ok'] === true && $mtP['mode'] === 'maintenance' && count($mtP['dates']) === 14);
$calMt = rh_cal_maint_tab($pdo, '', $today)['counts'];
ok('maintenance cards equal the Calendar Maintenance tab counts', $mtP['counts'] == $calMt, json_encode([$mtP['counts'], $calMt]));
ok('payload days clamped to 3..31', count(rh_ops_payload($pdo, 'housekeeping', $today, 99)['dates']) === 31 && count(rh_ops_payload($pdo, 'housekeeping', $today, 1)['dates']) === 3);
ok('nobody signed in: no calendar link', rh_ops_can(0) === ['calendar' => false]);
$json = json_encode($hkP);
ok('payload has no money fields', !preg_match('/"(amount|total_amount|price|paid|balance|rate)[a-z_]*"\s*:/i', (string)$json));

// ---------------------------------------------------------------------------------------------- (e)
echo "\n(e) Non-blocking job on the Maintenance grid, not on the Calendar (rolled back)\n";
$roomId = (int)$pdo->query("SELECT ir.id FROM individual_rooms ir INNER JOIN rooms r ON r.id = ir.room_type_id WHERE ir.is_active = 1 AND r.is_active = 1 ORDER BY ir.id LIMIT 1")->fetchColumn();
$engine = (string)$pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_maintenance_schedules'")->fetchColumn();
if ($roomId > 0 && strcasecmp($engine, 'InnoDB') === 0) {
    $fs = $day(400); // far ahead, nothing else there
    $fe = date('Y-m-d', strtotime($fs . ' +2 days'));
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO room_maintenance_schedules (individual_room_id, title, status, priority, block_room, start_date, end_date)
            VALUES (?, ?, 'planned', 'low', ?, ?, ?)");
        $ins->execute([$roomId, 'ops test no block', 0, $fs . ' 00:00:00', $fe . ' 00:00:00']);
        $id0 = (int)$pdo->lastInsertId();
        $ins->execute([$roomId, 'ops test blocks', 1, $fs . ' 00:00:00', $fe . ' 00:00:00']);
        $id1 = (int)$pdo->lastInsertId();
        $endR = date('Y-m-d', strtotime($fs . ' +6 days'));
        $grid = array_column(rh_ops_maint_items(rh_ops_fetch_maint_rows($pdo, $fs, $endR), [], [], $fs, $endR, $today), null, 'id');
        ok('Maintenance grid shows the block_room 0 job', isset($grid[$id0]) && $grid[$id0]['blocks'] === false);
        ok('Maintenance grid shows the block_room 1 job', isset($grid[$id1]) && $grid[$id1]['blocks'] === true);
        $cal = array_map('intval', array_column(rh_cal_fetch_maintenance($pdo, $fs, $endR), 'id'));
        ok('Calendar still leaves the block_room 0 job out', !in_array($id0, $cal, true));
        ok('Calendar still draws the block_room 1 job', in_array($id1, $cal, true));
        $p = rh_ops_payload($pdo, 'maintenance', $fs, 7);
        ok('maintenance payload for that range carries both', in_array($id0, array_column($p['items'], 'id'), true) && in_array($id1, array_column($p['items'], 'id'), true));
    } finally {
        $pdo->rollBack();
    }
    $left = (int)$pdo->query("SELECT COUNT(*) FROM room_maintenance_schedules WHERE title IN ('ops test no block', 'ops test blocks')")->fetchColumn();
    ok('rolled back: no test rows left', $left === 0);
} else {
    ok('(e) needs an active room and an InnoDB table', false, "room $roomId engine $engine");
}

// ---------------------------------------------------------------------------------------------- (f)
echo "\n(f) Endpoints\n";
foreach (['housekeeping-timeline.php' => ['housekeeping', 'housekeeping.php'], 'maintenance-timeline.php' => ['room_maintenance', 'room-maintenance.php']] as $file => [$perm, $page]) {
    $apiFile = $root . '/admin/api/' . $file;
    $runner = '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["PHP_SELF"]="/admin/api/' . $file . '"; $_GET=["start"=>"' . $today . '","days"=>"7"]; require ' . var_export($apiFile, true) . ';';
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $runner], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = '';
    if (is_resource($proc)) {
        $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    ok("$file: no session answers \"Not authenticated\" and no data", strpos($out, 'Not authenticated') !== false && strpos($out, '"lanes"') === false, substr($out, 0, 200));
    $src = (string)file_get_contents($apiFile);
    $pPerm = strpos($src, "requireApiPermission('$perm')");
    $pMod = strpos($src, "rhApiModuleGate('$page')");
    $pData = strpos($src, 'rh_ops_payload(');
    ok("$file: permission '$perm' and module gate run before any data is read", $pPerm !== false && $pMod !== false && $pData !== false && $pPerm < $pData && $pMod < $pData);
    ok("$file: GET only, no-store", strpos($src, "!== 'GET'") !== false && strpos($src, 'Cache-Control: no-store') !== false);
}
require_once $root . '/admin/includes/permissions.php';
$noUser = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 100000 FROM admin_users")->fetchColumn();
ok('a user id that does not exist has neither page permission', hasPermission($noUser, 'housekeeping') === false && hasPermission($noUser, 'room_maintenance') === false);

// ---------------------------------------------------------------------------------------------- (g)
echo "\n(g) Links\n";
$dates = [$today, $day(1), $day(2)];
$spans = rh_cal_block_spans(
    [['id' => 7, 'room_type_id' => 3, 'status' => 'available'], ['id' => 8, 'room_type_id' => 3, 'status' => 'maintenance']],
    [7 => 7, 8 => 8], [], [['id' => 55, 'individual_room_id' => 7, 'title' => 'Leak', 's' => $today, 'e' => $day(1)]], $dates, $today
);
$byLane = array_column($spans, null, 'lane');
ok('calendar maintenance block links the job on the Maintenance timeline', ($byLane[7]['href'] ?? '') === 'room-maintenance.php?id=55&view=timeline', json_encode($byLane[7] ?? null));
ok('calendar status-only maintenance cell links the room on the Maintenance timeline', ($byLane[8]['href'] ?? '') === 'room-maintenance.php?room=8&view=timeline', json_encode($byLane[8] ?? null));
ok('housekeeping icon link: room number on the Housekeeping timeline', rh_cal_hk_href('101') === 'housekeeping.php?room=101&view=timeline' && rh_cal_hk_href('A 2') === 'housekeeping.php?room=A%202&view=timeline');
ok('housekeeping icon link: a room number the page would refuse falls back to tasks due', rh_cal_hk_href('<b>') === 'housekeeping.php?due=now' && rh_cal_hk_href('') === 'housekeeping.php?due=now');
$calSrc = (string)file_get_contents($root . '/admin/includes/calendar-data.php');
ok('calendar lanes carry hk_href', strpos($calSrc, "'hk_href' => rh_cal_hk_href(") !== false);
$js = (string)file_get_contents($root . '/admin/js/room-timeline-ops.js');
ok('drawer "Open on calendar" link format', strpos($js, "'calendar.php?view=timeline&start=' + encodeURIComponent(day) + '&filter_room_id=' + encodeURIComponent(roomId)") !== false);

// ---------------------------------------------------------------------------------------------- (h)
echo "\n(h) Engine seam and pages\n";
$core = (string)file_get_contents($root . '/admin/js/room-timeline.js');
ok('room-timeline.js hands non-booking modes to opsEngine, booking path unchanged', (bool)preg_match("/var mode = \\(typeof cfg\\.mode === 'string' && cfg\\.mode\\) \\? cfg\\.mode : 'booking';/", $core)
    && strpos($core, 'window.RoomTimeline.opsEngine(root, cfg, adapter)') !== false && strpos($core, 'modes: MODES') !== false);
ok('room-timeline-ops.js registers the engine and both modes', strpos($js, 'RT.opsEngine = engine;') !== false && strpos($js, 'RT.modes.housekeeping = {') !== false && strpos($js, 'RT.modes.maintenance = {') !== false);
ok('room-timeline-ops.js: names reach the page through textContent only', strpos($js, 'innerHTML') === false && strpos($js, 'insertAdjacentHTML') === false);
ok('room-timeline-ops.js: reduced motion honoured on smooth scrolls', substr_count($js, "behavior: reduce() ? 'auto' : 'smooth'") >= 2);
$pages = [
    'housekeeping.php' => ['hkConfirm', 'editAssignment', '#hkAssignmentsCard', [
        ['mark_started', 'Mark this assignment as started?'], ['mark_complete', 'Mark this assignment as completed?'],
        ['verify_assignment', 'Mark this assignment as verified? This cannot be undone.'], ['delete_assignment', 'Delete this assignment? This cannot be undone.']]],
    'room-maintenance.php' => ['rmConfirm', 'editSchedule', '#maintenanceSchedulesContainer', [
        ['mark_started', 'Mark this maintenance as started?'], ['mark_complete', 'Mark this maintenance as completed? The room stays in Maintenance until the task is verified.'],
        ['verify_schedule', 'Mark this maintenance as verified? The room will be released from Maintenance and this cannot be undone.'], ['delete_schedule', 'Delete this schedule? This cannot be undone.']]],
];
foreach ($pages as $page => [$confirm, $edit, $list, $acts]) {
    $src = (string)file_get_contents($root . '/admin/' . $page);
    $allSame = true;
    foreach ($acts as [$action, $msg]) {
        // the page's own handler + the same confirm text in the page and in the drawer
        if (strpos($src, "'" . $action . "'") === false || strpos($src, $msg) === false || strpos($js, "['" . $action . "', '" . $msg . "'") === false) {
            $allSame = false;
            echo "    missing: $page $action\n";
        }
    }
    ok("$page: drawer posts the page's own actions with the same confirm texts", $allSame);
    ok("$page: section wired (view include, confirm, edit, list card, scripts)", strpos($src, "require __DIR__ . '/includes/ops-timeline-view.php'") !== false
        && strpos($src, "'confirm' => '$confirm'") !== false && strpos($src, "'edit' => '$edit'") !== false && strpos($src, "'list' => '$list'") !== false
        && strpos($src, 'js/room-timeline-ops.js') !== false && strpos($src, "window.RoomTimeline.init(root)") !== false);
    ok("$page: list rows carry data-ops-id / data-ops-room and filterTable asks opsRowMatch", strpos($src, 'data-ops-id="') !== false && strpos($src, 'data-ops-room="') !== false
        && strpos($src, 'window.RoomTimeline.opsRowMatch(table, row)') !== false);
    ok("$page: a save without reload refreshes the timeline", strpos($src, 'window.RoomTimeline.reload()') !== false);
    ok("$page: figure cards have a ? help each", substr_count($src, 'data-ops-card="') >= 1 && strpos($src, '<i class="help" data-tip="<?php echo htmlspecialchars($opsHelp); ?>">?</i>') !== false);
    $cStart = (int)strpos($src, 'data-ops-card="');
    $cEnd = strpos($src, '<?php else: ?>', $cStart); // the old cards after it are only the fallback
    ok("$page: no 'Hover for insight' wording on the new cards", $cEnd !== false && strpos(substr($src, $cStart, $cEnd - $cStart), 'insight') === false);
}
$view = (string)file_get_contents($root . '/admin/includes/ops-timeline-view.php');
ok('timeline section is hidden until the script runs (no-JS keeps the list + modals)', (bool)preg_match('/<section class="tl tl-ops" id="opsTimeline" data-tl-root hidden/', $view));
ok('timeline JSON escaped for HTML', strpos($view, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT') !== false);
ok('view toggle Timeline | List in the toolbar', strpos($view, 'data-ops-view="timeline"') !== false && strpos($view, 'data-ops-view="list"') !== false);

// ---------------------------------------------------------------------------------------------- (i)
echo "\n(i) Room Service timeline\n";
$rsPage = (string)file_get_contents($root . '/admin/room-service-dashboard.php');
ok('room service page wired (view include, list card, table, scripts, init)', strpos($rsPage, "require __DIR__ . '/includes/ops-timeline-view.php'") !== false
    && strpos($rsPage, "'list' => '#rsActivePanel'") !== false && strpos($rsPage, "'table' => '#rsActiveTable'") !== false
    && strpos($rsPage, 'js/room-timeline-ops.js') !== false && strpos($rsPage, 'window.RoomTimeline.init(root)') !== false);
ok('room service list rows carry data-ops-id / data-ops-cards and filterTable asks opsRowMatch', strpos($rsPage, 'data-ops-id="') !== false
    && strpos($rsPage, 'data-ops-cards="') !== false && strpos($rsPage, 'window.RoomTimeline.opsRowMatch(table, row)') !== false);
ok('room service: drawer Delivered posts mark_delivered (order_id) with the page\'s own confirm text', strpos($rsPage, "\$action === 'mark_delivered'") !== false
    && strpos($rsPage, "confirm('Mark order <?php echo htmlspecialchars(\$o['reference']); ?> as delivered?')") !== false
    && strpos($js, "['mark_delivered', 'Mark order ' + it.ref + ' as delivered?', 'Delivered', 'btn-success', 'order_id']") !== false);
ok('room service: the grid rides the page\'s one poll timer (no new timer in the ops engine)', strpos($rsPage, 'window.RoomTimeline.opsPoll()') !== false
    && substr_count($rsPage, 'setInterval(') <= 1 && strpos($js, 'setInterval(') === false);
ok('room service: one day per step, rooms without orders hidden until "Show all rooms"', strpos($js, 'RT.modes.service = {') !== false
    && strpos($js, 'fixedDays: 1') !== false && strpos($js, '!st.allRooms') !== false && strpos($view, 'data-ops-allrooms') !== false);
ok('room service: every Active queue column and figure card has a ? help', substr_count($rsPage, '<th data-help="') >= 10
    && substr_count($rsPage, 'data-ops-card="') >= 1 && strpos($rsPage, '<i class="help" data-tip="<?php echo htmlspecialchars($opsHelp); ?>">?</i>') !== false);

ok('room service: the view sends the grid to its own endpoint, with the page CSRF token', strpos($view, '$opsIsRs ? \'api/room-service-timeline.php\'') !== false
    && strpos($view, '\'csrf\' => (string)($csrf_token ?? \'\')') !== false && strpos($rsPage, '$csrf_token = generateCsrfToken();') !== false
    && strpos($rsPage, '$csrf_token = generateCsrfToken();') < strpos($rsPage, "require __DIR__ . '/includes/ops-timeline-view.php'"));

// Endpoint: 401 without a session, permission + module gate before data, GET only.
$rsApi = $root . '/admin/api/room-service-timeline.php';
$runner = '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["PHP_SELF"]="/admin/api/room-service-timeline.php"; $_GET=["start"=>"' . $today . '"]; require ' . var_export($rsApi, true) . ';';
$proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $runner], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
$out = '';
if (is_resource($proc)) {
    $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
}
ok('room-service-timeline.php: no session answers "Not authenticated" and no data', strpos($out, 'Not authenticated') !== false && strpos($out, '"lanes"') === false, substr($out, 0, 200));
$src = (string)file_get_contents($rsApi);
$pPerm = strpos($src, "requireApiPermission('room_service_view')");
$pMod = strpos($src, "rhApiModuleGate('room-service-dashboard.php')");
$pData = strpos($src, 'rh_ops_payload(');
ok('room-service-timeline.php: room_service_view + module gate run before any data is read', $pPerm !== false && $pMod !== false && $pData !== false && $pPerm < $pData && $pMod < $pData);
ok('room-service-timeline.php: GET only, no-store, never writes', strpos($src, "!== 'GET'") !== false && strpos($src, 'Cache-Control: no-store') !== false
    && !preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $src));
ok('a user id that does not exist has no Room Service permission (403 path)', hasPermission($noUser, 'room_service_view') === false && hasPermission($noUser, 'room_service_manage') === false);

// Day parameter.
ok('day: missing start = today', rh_ops_rs_request_day([], $today) === $today);
ok('day: a real date is kept', rh_ops_rs_request_day(['start' => '2026-03-04'], $today) === '2026-03-04');
ok('day: 31 Feb, junk and arrays refused', rh_ops_rs_request_day(['start' => '2026-02-31'], $today) === null
    && rh_ops_rs_request_day(['start' => 'yesterday'], $today) === null && rh_ops_rs_request_day(['start' => ['2026-03-04']], $today) === null
    && rh_ops_rs_request_day(['start' => ''], $today) === null);
$dl = rh_ops_deeplink(['id' => '9', 'room' => '7', 'date' => '2026-03-04', 'view' => 'list'], 'service');
ok('service deep link: room = room id, date, view and id kept', $dl['id'] === 9 && $dl['room_id'] === 7 && $dl['date'] === '2026-03-04' && $dl['view'] === 'list', json_encode($dl));

// Items (pure): rows = rooms with an order; label matching; carried open order; overdue; cards.
$rsLanes = [['id' => 7, 'number' => '101'], ['id' => 8, 'number' => '102'], ['id' => 9, 'number' => '103'], ['id' => 10, 'number' => '104']];
$rsRow = static fn (array $o): array => $o + ['reference' => 'RS-' . $o['id'], 'individual_room_id' => null, 'table_number' => '', 'room_number' => '',
    'customer_name' => 'Guest', 'notes' => '', 'status' => 'pending', 'kitchen_status' => 'pending', 'total_amount' => '10.00',
    'folio_posted_at' => null, 'fired_at' => null, 'served_at' => null];
$yday = $day(-1);
$rsRows = [
    $rsRow(['id' => 1, 'individual_room_id' => 7, 'created_at' => "$today 10:00:00", 'kitchen_status' => 'served', 'served_at' => "$today 10:40:00"]),
    $rsRow(['id' => 2, 'table_number' => 'Rm 102', 'created_at' => "$today 11:55:00"]),
    $rsRow(['id' => 3, 'room_number' => 'Room 999', 'created_at' => "$yday 23:00:00", 'fired_at' => "$yday 23:01:00", 'kitchen_status' => 'in_progress']),
    $rsRow(['id' => 4, 'individual_room_id' => 7, 'created_at' => "$today 09:00:00", 'status' => 'cancelled']),
    $rsRow(['id' => 5, 'individual_room_id' => 9, 'created_at' => "$yday 08:00:00", 'kitchen_status' => 'served', 'served_at' => "$yday 08:30:00"]),
    $rsRow(['id' => 6, 'individual_room_id' => 9, 'created_at' => "$today 11:50:00", 'fired_at' => "$today 11:51:00", 'kitchen_status' => 'ready']),
];
$rsLines = [1 => [['item_name' => 'Tea', 'quantity' => '1.000', 'notes' => '', 'ready_at' => "$today 10:20:00"],
                  ['item_name' => 'Toast', 'quantity' => '1', 'notes' => ' no butter ', 'ready_at' => "$today 10:25:00"]]];
$items = array_column(rh_ops_rs_items($rsRows, $rsLines, $rsLanes, $today, "$today 12:00:00", 'MWK'), null, 'id');
ok('items: yesterday\'s delivered order is not on today', !isset($items[5]) && count($items) === 5, implode(',', array_keys($items)));
$laneIds = array_values(array_unique(array_column($items, 'room_id')));
sort($laneIds);
ok('rows = rooms with an order: lanes 0 (no room), 7, 8, 9; room 104 has none', $laneIds === [0, 7, 8, 9], json_encode($laneIds));
ok('a "Rm 102" table label lands on room 102', ($items[2]['room_id'] ?? -1) === 8 && ($items[2]['room'] ?? '') === '102');
ok('delivered: bar from placed to delivered, card counts it', ($items[1]['from'] ?? 0) === 600 && ($items[1]['to'] ?? 0) === 640 && $items[1]['state'] === 'delivered'
    && $items[1]['delivered'] === '10:40' && $items[1]['ready'] === '10:25' && $items[1]['cards']['delivered'] === true && $items[1]['open'] === false);
ok('delivered: lines, item count and notes', $items[1]['count'] === '2 items' && $items[1]['lines'][1]['notes'] === 'no butter' && $items[1]['lines'][0]['qty'] === '1');
ok('open order runs to now, not late at 5 min', $items[2]['state'] === 'new' && $items[2]['from'] === 715 && $items[2]['to'] === 720 && $items[2]['open'] === true
    && $items[2]['overdue'] === false && $items[2]['cards']['new'] === true);
ok('open order from yesterday carried onto today, cut at midnight, late', $items[3]['state'] === 'preparing' && $items[3]['from'] === 0 && $items[3]['cut_s'] === true
    && $items[3]['to'] === 720 && $items[3]['running'] === true && $items[3]['overdue'] === true && $items[3]['room_id'] === 0 && $items[3]['room'] === 'Room 999');
ok('cancelled order: a mark at its placed time, not open, no card', $items[4]['state'] === 'cancelled' && $items[4]['from'] === 540 && $items[4]['to'] === 540
    && $items[4]['open'] === false && !in_array(true, $items[4]['cards'], true));
ok('ready order 9 min after firing is not late', $items[6]['state'] === 'ready' && $items[6]['overdue'] === false && $items[6]['cards']['ready'] === true);
$past = array_column(rh_ops_rs_items($rsRows, $rsLines, $rsLanes, $yday, "$today 12:00:00", 'MWK'), null, 'id');
ok('past day: its delivered order shows; an order still open runs to midnight (cut)', isset($past[5]) && $past[5]['from'] === 480 && $past[5]['to'] === 510
    && isset($past[3]) && $past[3]['from'] === 1380 && $past[3]['to'] === 1440 && $past[3]['cut_e'] === true && $past[3]['running'] === false && !isset($past[1]));
ok('states: served / ready / collection / recalled / voided', rh_ops_rs_state(['kitchen_status' => 'served']) === 'delivered'
    && rh_ops_rs_state(['kitchen_status' => 'collection']) === 'ready' && rh_ops_rs_state(['kitchen_status' => 'recalled']) === 'preparing'
    && rh_ops_rs_state(['kitchen_status' => 'pending']) === 'new' && rh_ops_rs_state(['status' => 'voided', 'kitchen_status' => 'served']) === 'cancelled');

// Deep-link id: an id the page may not open gives no day (and so opens nothing on the grid).
$notRs = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM stock_orders WHERE order_type <> 'room_service'")->fetchColumn();
$noOrder = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 100000 FROM stock_orders")->fetchColumn();
ok('deep link id: 0, a missing order and a non room service order give no day', rh_ops_item_date($pdo, 'service', 0) === null
    && rh_ops_item_date($pdo, 'service', $noOrder) === null && ($notRs === 0 || rh_ops_item_date($pdo, 'service', $notRs) === null));
$rsOpen = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM stock_orders WHERE order_type = 'room_service' AND kitchen_status <> 'served' AND status NOT IN ('cancelled','voided')")->fetchColumn();
ok('deep link id: a still-open order opens on today (no own day)', $rsOpen === 0 || rh_ops_item_date($pdo, 'service', $rsOpen) === null);

// Live payload (read-only).
$p = rh_ops_payload($pdo, 'service', $today, 1, ['user_id' => $noUser]);
$keys = ['ok', 'mode', 'start', 'end', 'days', 'today', 'dates', 'groups', 'lanes', 'items', 'counts', 'can', 'now', 'late_minutes', 'error'];
ok('payload: JSON shape', array_keys($p) === $keys && $p['mode'] === 'service' && $p['days'] === 1 && $p['start'] === $today && count($p['dates']) === 1
    && is_int($p['now']) && $p['late_minutes'] === 10 && json_encode($p) !== false, json_encode(array_keys($p)));
ok('payload: no permission = no Delivered button', ($p['can']['manage'] ?? true) === false);
$inProg = (int)$pdo->query("SELECT COUNT(*) FROM stock_orders WHERE order_type='room_service' AND kitchen_status<>'served' AND status NOT IN ('cancelled','voided')")->fetchColumn();
ok('cards: new + preparing + ready = the page\'s In progress figure', $p['counts']['new'] + $p['counts']['preparing'] + $p['counts']['ready'] === $inProg, json_encode($p['counts']) . " vs $inProg");
$laneSet = array_flip(array_column($p['lanes'], 'id'));
$miss = array_filter($p['items'], static fn ($it) => !isset($laneSet[$it['room_id']]));
ok('payload: every order sits on a lane (the "No room" lane when unlinked)', !$miss, json_encode(array_column($miss, 'id')));
$pp = rh_ops_payload($pdo, 'service', $yday, 1, ['user_id' => $noUser]);
ok('payload: a past day has no now line', $pp['start'] === $yday && $pp['now'] === null);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
