<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * "Guest has left" (owner 2026-11-12). Usage (project root, TEST HOTEL ONLY): php scripts/tests/housekeeping_guest_left.php
 *   G1  marking a due-out room creates ONE high-priority checkout cleanup linked to the booking + room; the room stays
 *       'occupied'; a housekeeping audit row and a booking History step are written (also for a booking_rooms room).
 *   G2  a second tap does not duplicate.
 *   G3  a guest not due out yet, or a room that is not the booking's, is refused (no task).
 *   G4  completing the early task while the guest is still checked in leaves the room 'occupied'.
 *   G5  checkout (rh_checkout_with_gate -> updateBookingRoomsStatus, the path of booking-details / bookings / the
 *       settlement checkout) after a COMPLETED early clean: no new task, the room goes to inspection/available;
 *       the booking's other room (no early clean) still goes to cleaning with one task.
 *   G6  the same checkout during an IN-PROGRESS early clean: room to cleaning, no duplicate task.
 *   G7/G8  the same two cases through processGuestCheckout().
 *   G9/G10  checkout (both paths) while staff set the early clean to 'blocked': room to cleaning, still exactly one
 *       task; G9b/G10b an unrelated blocked task (no booking link) keeps today's behaviour (a new task is created).
 * Every run is inside scripts/tests/page_harness.php (outer transaction that never commits, rolled back). Fixtures are
 * throw-away rows found by unique tags (no explicit ids). The final invoice is pre-marked generated, so no PDF/email.
 * This file is also the in-process child for G5-G8 (run by the harness with cfg.post.scenario).
 */
$root = $root ?? dirname(__DIR__, 2);

/** Setup SQL for one fixture: room type, rooms R1 (primary, occupied), R2 (not the booking's), R3 (2nd room via
 *  booking_rooms, occupied), booking B checked in yesterday, due out $outSql; optional early task on R1. */
function hgl_fixture(string $tag, string $outSql = 'CURDATE()', ?string $earlyStatus = null, bool $linked = true): array
{
    $type = "(SELECT id FROM rooms WHERE slug = 'zz-gl-" . strtolower($tag) . "')";
    $r = function (string $n) use ($tag) { return "(SELECT id FROM individual_rooms WHERE room_number = 'ZG{$n}{$tag}')"; };
    $b = "(SELECT id FROM bookings WHERE booking_reference = 'ZGL{$tag}')";
    $sql = [
        "INSERT INTO rooms (name, description, short_description, price_per_night, max_guests, rooms_available, total_rooms, is_active, slug) VALUES ('ZZ GL TEST {$tag}', '', '', 100, 2, 3, 3, 1, 'zz-gl-" . strtolower($tag) . "')",
        "INSERT INTO individual_rooms (room_type_id, room_number, room_name, floor, status, housekeeping_status, is_active) VALUES ($type, 'ZG1{$tag}', 'zz test', 1, 'occupied', 'completed', 1)",
        "INSERT INTO individual_rooms (room_type_id, room_number, room_name, floor, status, housekeeping_status, is_active) VALUES ($type, 'ZG2{$tag}', 'zz test', 1, 'available', 'completed', 1)",
        "INSERT INTO individual_rooms (room_type_id, room_number, room_name, floor, status, housekeeping_status, is_active) VALUES ($type, 'ZG3{$tag}', 'zz test', 1, 'occupied', 'completed', 1)",
        "INSERT INTO bookings (booking_reference, room_id, individual_room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, final_invoice_generated, created_at)
         VALUES ('ZGL{$tag}', $type, {$r('1')}, 'ZZ GUEST LEFT TEST', '', '+265000000009', 'Malawi', 2, 2, 0, 50, DATE_SUB(CURDATE(), INTERVAL 1 DAY), $outSql,
            DATEDIFF($outSql, DATE_SUB(CURDATE(), INTERVAL 1 DAY)), 0, 0, 0, 0, 0, 0, 0, 'guest left test', 'checked-in', 'unpaid', 0, 'double', '" . bin2hex(random_bytes(16)) . "', 1, NOW())",
        "INSERT INTO booking_rooms (booking_id, individual_room_id, room_combination_id, is_primary, assigned_at, status_snapshot) VALUES ($b, {$r('1')}, NULL, 1, NOW(), 'assigned'), ($b, {$r('3')}, NULL, 0, NOW(), 'assigned')",
    ];
    if ($earlyStatus !== null) {
        $sql[] = "INSERT INTO housekeeping_assignments (individual_room_id, status, due_date, priority, assignment_type, notes, linked_booking_id, completed_at, created_at)
            VALUES ({$r('1')}, '{$earlyStatus}', CURDATE(), 'high', 'checkout_cleanup', 'zz gl test early clean', " . ($linked ? $b : 'NULL') . ", " . ($earlyStatus === 'completed' ? 'NOW()' : 'NULL') . ", NOW())";
    }
    return $sql;
}

// ───────────────────────── child mode (inside the harness) ─────────────────────────
if (isset($GLOBALS['__h_cfg'])) {
    $sc = (string)($_POST['scenario'] ?? '');
    $tag = (string)($_POST['tag'] ?? '');
    require_once $root . '/includes/room-management.php';
    $adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    $scKind = (string)substr($sc, (int)strpos($sc, '_') + 1);
    $earlyStatus = ['done' => 'completed', 'progress' => 'in_progress', 'blocked' => 'blocked', 'unrelated' => 'blocked'][$scKind] ?? 'in_progress';
    foreach (hgl_fixture($tag, 'CURDATE()', $earlyStatus, $scKind !== 'unrelated') as $q) {
        $pdo->exec($q);
    }
    $id = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $r1 = $id("SELECT id FROM individual_rooms WHERE room_number = 'ZG1{$tag}'");
    $r3 = $id("SELECT id FROM individual_rooms WHERE room_number = 'ZG3{$tag}'");
    $bid = $id("SELECT id FROM bookings WHERE booking_reference = 'ZGL{$tag}'");
    $state = function (int $roomId) use ($pdo): array {
        $s = $pdo->prepare("SELECT status, housekeeping_status FROM individual_rooms WHERE id = ?");
        $s->execute([$roomId]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $t = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status IN ('pending','in_progress')) AS open_tasks, SUM(status = 'blocked') AS blocked_tasks FROM housekeeping_assignments WHERE individual_room_id = ?");
        $t->execute([$roomId]);
        $c = $t->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['status' => $row['status'] ?? null, 'hk' => $row['housekeeping_status'] ?? null, 'tasks' => (int)($c['total'] ?? 0), 'open' => (int)($c['open_tasks'] ?? 0), 'blocked' => (int)($c['blocked_tasks'] ?? 0)];
    };
    $out = ['scenario' => $sc, 'expected_clean_status' => rh_room_clean_release_status(), 'maint_r1' => rh_room_has_active_maintenance($pdo, $r1)];
    if (strpos($sc, 'ubrs_') === 0) {
        $out['result'] = rh_checkout_with_gate($pdo, $bid, $adminId, true, 'zz test');
    } else {
        $out['result'] = processGuestCheckout($bid, $adminId, ['confirm_checkout_with_balance' => true]);
        unset($out['result']['data']['workflow']);
    }
    $bs = $pdo->prepare("SELECT status FROM bookings WHERE id = ?");
    $bs->execute([$bid]);
    $out['booking_status'] = $bs->fetchColumn();
    $out['r1'] = $state($r1);
    $out['r3'] = $state($r3);
    echo json_encode($out);
    return;
}

// ───────────────────────── runner ─────────────────────────
chdir($root);
// Owner rail: DB tests run at the test hotel only. The live hotel's repo must never run this (checked before any DB use).
if (stripos(str_replace('\\', '/', $root), 'Rosalyns-hotel-2026') !== false) {
    fwrite(STDERR, "Refusing to run in the live hotel's repo. Copy the changed files to the test hotel and run it there.\n");
    exit(2);
}
require_once $root . '/config/database.php';
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function hgl_run(string $page, array $cfg): array {
    global $harness;
    $cfg += ['ajax' => false];
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -800)]; } }
    return $res;
}
function hgl_clean(array $r, string $label): void {
    ok("$label: harness rolled back, nothing left behind", empty($r['leak']) && empty($r['marker_lost']) && !empty($r['rolled_back']), json_encode([$r['error'] ?? null, $r['raw'] ?? null]));
}
function hgl_tag(): string { return strtoupper(bin2hex(random_bytes(3))); }
function hgl_verify(string $tag): array {
    $r1 = "(SELECT id FROM individual_rooms WHERE room_number = 'ZG1{$tag}')";
    $r3 = "(SELECT id FROM individual_rooms WHERE room_number = 'ZG3{$tag}')";
    $b = "(SELECT id FROM bookings WHERE booking_reference = 'ZGL{$tag}')";
    return [
        "SELECT room_number, status FROM individual_rooms WHERE room_number IN ('ZG1{$tag}', 'ZG2{$tag}', 'ZG3{$tag}') ORDER BY room_number",
        "SELECT individual_room_id = $r1 AS on_r1, individual_room_id = $r3 AS on_r3, status, priority, assignment_type, linked_booking_id = $b AS linked, due_date = CURDATE() AS due_today, notes FROM housekeeping_assignments WHERE individual_room_id IN ($r1, $r3, (SELECT id FROM individual_rooms WHERE room_number = 'ZG2{$tag}')) ORDER BY id",
        "SELECT COUNT(*) AS n FROM booking_timeline_logs WHERE booking_id = $b AND action = 'Guest has left (housekeeping)'",
        "SELECT COUNT(*) AS n FROM housekeeping_audit_log WHERE action = 'created' AND assignment_id IN (SELECT id FROM housekeeping_assignments WHERE individual_room_id IN ($r1, $r3))",
    ];
}
function hgl_status(array $r, string $num): string {
    foreach (($r['verify'][0] ?? []) as $row) { if (($row['room_number'] ?? '') === $num) { return (string)$row['status']; } }
    return '?';
}

$hk = 'admin/housekeeping.php';
$rid = function (string $n, string $tag): string { return "SELECT id FROM individual_rooms WHERE room_number = 'ZG{$n}{$tag}'"; };
$bidSql = function (string $tag): string { return "SELECT id FROM bookings WHERE booking_reference = 'ZGL{$tag}'"; };

echo "== G1: mark a due-out room as left ==\n";
$t = hgl_tag();
$r = hgl_run($hk, ['post' => ['action' => 'guest_left', 'individual_room_id' => '{r1}', 'booking_id' => '{b}'], 'setup' => hgl_fixture($t),
    'vars' => ['r1' => $rid('1', $t), 'b' => $bidSql($t)], 'verify' => hgl_verify($t)]);
hgl_clean($r, 'G1');
$tasks = is_array($r['verify'][1] ?? null) ? $r['verify'][1] : [];
$task = $tasks[0] ?? [];
ok('G1: exactly one task is created', count($tasks) === 1, json_encode([$tasks, $r['output_flash'] ?? null, $r['raw'] ?? null]));
ok('G1: it is a high-priority checkout cleanup, pending, due today, linked to the booking', (int)($task['on_r1'] ?? 0) === 1 && ($task['assignment_type'] ?? '') === 'checkout_cleanup' && ($task['priority'] ?? '') === 'high' && ($task['status'] ?? '') === 'pending' && (int)($task['due_today'] ?? 0) === 1 && (int)($task['linked'] ?? 0) === 1, json_encode($task));
ok('G1: the note says the guest has left and who marked it', strpos((string)($task['notes'] ?? ''), 'Guest has left - cleaned before checkout (marked by ') === 0, (string)($task['notes'] ?? ''));
ok('G1: the room is still occupied (not for sale)', hgl_status($r, 'ZG1' . $t) === 'occupied', json_encode($r['verify'][0] ?? null));
ok('G1: a booking History step is written', (int)($r['verify'][2][0]['n'] ?? 0) === 1, json_encode($r['verify'][2] ?? null));
ok('G1: a housekeeping audit row is written', (int)($r['verify'][3][0]['n'] ?? 0) === 1, json_encode([$r['verify'][3] ?? null, $r['ddl_skipped'] ?? null]));

$t = hgl_tag();
$r = hgl_run($hk, ['post' => ['action' => 'guest_left', 'individual_room_id' => '{r3}', 'booking_id' => '{b}'], 'setup' => hgl_fixture($t),
    'vars' => ['r3' => $rid('3', $t), 'b' => $bidSql($t)], 'verify' => hgl_verify($t)]);
hgl_clean($r, 'G1b');
$tasks = is_array($r['verify'][1] ?? null) ? $r['verify'][1] : [];
ok('G1b: the booking\'s second room (booking_rooms) can be marked; it stays occupied', count($tasks) === 1 && (int)($tasks[0]['on_r3'] ?? 0) === 1 && hgl_status($r, 'ZG3' . $t) === 'occupied', json_encode([$tasks, $r['output_flash'] ?? null]));

echo "== G2: a second tap does nothing ==\n";
$t = hgl_tag();
$r = hgl_run($hk, ['post' => ['action' => 'guest_left', 'individual_room_id' => '{r1}', 'booking_id' => '{b}'], 'setup' => hgl_fixture($t, 'CURDATE()', 'pending'),
    'vars' => ['r1' => $rid('1', $t), 'b' => $bidSql($t)], 'verify' => hgl_verify($t)]);
hgl_clean($r, 'G2');
$tasks = is_array($r['verify'][1] ?? null) ? $r['verify'][1] : [];
ok('G2: still one task', count($tasks) === 1, json_encode($tasks));
ok('G2: the page says it is already marked', stripos(implode(' ', $r['output_flash'] ?? []), 'already marked as left') !== false, json_encode($r['output_flash'] ?? null));
ok('G2: no History step for the repeat tap', (int)($r['verify'][2][0]['n'] ?? -1) === 0);

echo "== G3: refusals ==\n";
$t = hgl_tag();
$r = hgl_run($hk, ['post' => ['action' => 'guest_left', 'individual_room_id' => '{r1}', 'booking_id' => '{b}'], 'setup' => hgl_fixture($t, 'DATE_ADD(CURDATE(), INTERVAL 1 DAY)'),
    'vars' => ['r1' => $rid('1', $t), 'b' => $bidSql($t)], 'verify' => hgl_verify($t)]);
hgl_clean($r, 'G3a');
ok('G3a: a guest not due out until tomorrow is refused (no task, room occupied)', ($r['verify'][1] ?? null) === [] && hgl_status($r, 'ZG1' . $t) === 'occupied' && stripos(implode(' ', $r['output_flash'] ?? []), 'not due out') !== false, json_encode([$r['verify'][1] ?? null, $r['output_flash'] ?? null]));
$t = hgl_tag();
$r = hgl_run($hk, ['post' => ['action' => 'guest_left', 'individual_room_id' => '{r2}', 'booking_id' => '{b}'], 'setup' => hgl_fixture($t),
    'vars' => ['r2' => $rid('2', $t), 'b' => $bidSql($t)], 'verify' => hgl_verify($t)]);
hgl_clean($r, 'G3b');
ok('G3b: a room that is not the booking\'s is refused (no task, room unchanged)', ($r['verify'][1] ?? null) === [] && hgl_status($r, 'ZG2' . $t) === 'available' && stripos(implode(' ', $r['output_flash'] ?? []), 'does not belong') !== false, json_encode([$r['verify'][1] ?? null, $r['output_flash'] ?? null]));

echo "== G4: finishing the early clean before checkout ==\n";
$t = hgl_tag();
$r = hgl_run($hk, ['post' => ['action' => 'mark_complete', 'id' => '{aid}'], 'setup' => hgl_fixture($t, 'CURDATE()', 'in_progress'),
    'vars' => ['aid' => "SELECT id FROM housekeeping_assignments WHERE individual_room_id = (" . $rid('1', $t) . ")"], 'verify' => hgl_verify($t)]);
hgl_clean($r, 'G4');
$tasks = is_array($r['verify'][1] ?? null) ? $r['verify'][1] : [];
ok('G4: the task is completed', ($tasks[0]['status'] ?? '') === 'completed', json_encode([$tasks, $r['output_flash'] ?? null]));
ok('G4: the room is STILL occupied (never released before checkout)', hgl_status($r, 'ZG1' . $t) === 'occupied', json_encode($r['verify'][0] ?? null));

$self = 'scripts/tests/housekeeping_guest_left.php';
foreach (['ubrs' => 'rh_checkout_with_gate / updateBookingRoomsStatus', 'pgc' => 'processGuestCheckout'] as $path => $label) {
    $n = $path === 'ubrs' ? ['G5', 'G6'] : ['G7', 'G8'];
    echo "== {$n[0]}: checkout after a COMPLETED early clean ($label) ==\n";
    $r = hgl_run($self, ['post' => ['scenario' => $path . '_done', 'tag' => hgl_tag()], 'fake_smtp' => 9]);
    hgl_clean($r, $n[0]);
    $j = $r['json'] ?? [];
    ok("{$n[0]}: checkout succeeds", !empty($j['result']['success']) && ($j['booking_status'] ?? '') === 'checked-out', json_encode([$j['result'] ?? null, $r['raw'] ?? null]));
    ok("{$n[0]}: no new task for the cleaned room", (int)($j['r1']['tasks'] ?? -1) === 1 && (int)($j['r1']['open'] ?? -1) === 0, json_encode($j['r1'] ?? null));
    ok("{$n[0]}: the cleaned room goes to " . ($j['expected_clean_status'] ?? '?') . ' (not cleaning)', !empty($j['expected_clean_status']) && ($j['r1']['status'] ?? '') === $j['expected_clean_status'] && empty($j['maint_r1']), json_encode($j['r1'] ?? null));
    ok("{$n[0]}: the booking's other room (no early clean) goes to cleaning with one task", ($j['r3']['status'] ?? '') === 'cleaning' && (int)($j['r3']['open'] ?? 0) === 1, json_encode($j['r3'] ?? null));

    echo "== {$n[1]}: checkout during an IN-PROGRESS early clean ($label) ==\n";
    $r = hgl_run($self, ['post' => ['scenario' => $path . '_progress', 'tag' => hgl_tag()], 'fake_smtp' => 9]);
    hgl_clean($r, $n[1]);
    $j = $r['json'] ?? [];
    ok("{$n[1]}: checkout succeeds", !empty($j['result']['success']) && ($j['booking_status'] ?? '') === 'checked-out', json_encode([$j['result'] ?? null, $r['raw'] ?? null]));
    ok("{$n[1]}: the room goes to cleaning with no duplicate task", ($j['r1']['status'] ?? '') === 'cleaning' && (int)($j['r1']['tasks'] ?? -1) === 1 && (int)($j['r1']['open'] ?? -1) === 1, json_encode($j['r1'] ?? null));

    $g = $path === 'ubrs' ? 'G9' : 'G10';
    echo "== {$g}: checkout while the early clean is BLOCKED ($label) ==\n";
    $r = hgl_run($self, ['post' => ['scenario' => $path . '_blocked', 'tag' => hgl_tag()], 'fake_smtp' => 9]);
    hgl_clean($r, $g);
    $j = $r['json'] ?? [];
    ok("{$g}: checkout succeeds", !empty($j['result']['success']) && ($j['booking_status'] ?? '') === 'checked-out', json_encode([$j['result'] ?? null, $r['raw'] ?? null]));
    ok("{$g}: the room goes to cleaning with still exactly one task (the blocked early clean)", ($j['r1']['status'] ?? '') === 'cleaning' && (int)($j['r1']['tasks'] ?? -1) === 1 && (int)($j['r1']['blocked'] ?? -1) === 1 && (int)($j['r1']['open'] ?? -1) === 0, json_encode($j['r1'] ?? null));
    ok("{$g}: the booking's other room still goes to cleaning with one task", ($j['r3']['status'] ?? '') === 'cleaning' && (int)($j['r3']['open'] ?? 0) === 1 && (int)($j['r3']['tasks'] ?? 0) === 1, json_encode($j['r3'] ?? null));

    echo "== {$g}b: an UNRELATED blocked task on the room keeps today's behaviour ($label) ==\n";
    $r = hgl_run($self, ['post' => ['scenario' => $path . '_unrelated', 'tag' => hgl_tag()], 'fake_smtp' => 9]);
    hgl_clean($r, "{$g}b");
    $j = $r['json'] ?? [];
    ok("{$g}b: checkout succeeds", !empty($j['result']['success']) && ($j['booking_status'] ?? '') === 'checked-out', json_encode([$j['result'] ?? null, $r['raw'] ?? null]));
    ok("{$g}b: a new turnover task is created next to the unrelated blocked one", ($j['r1']['status'] ?? '') === 'cleaning' && (int)($j['r1']['tasks'] ?? -1) === 2 && (int)($j['r1']['blocked'] ?? -1) === 1 && (int)($j['r1']['open'] ?? -1) === 1, json_encode($j['r1'] ?? null));
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
