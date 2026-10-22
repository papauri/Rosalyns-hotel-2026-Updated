<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-house room moves and split bookings. Usage (project root): php scripts/tests/inhouse_move.php
 *   F4  a room-TYPE change for a checked-in guest sends the old room to housekeeping (status cleaning + an open
 *       cleaning task) and marks only the NEW room occupied (the old room used to be set back to occupied).
 *   F5  a checked-in guest can be moved to another room of the SAME type; a room that is not clean is refused;
 *       the old room goes to housekeeping; nothing else (money, type) changes.
 *   F7  completing a housekeeping task stamps the room's last_cleaned_at again (an old stamp is replaced).
 *   F6  a public booking that needs two rooms is saved as one group (the second room points at the first), so the
 *       cancel dialog offers the whole group.
 * Every scenario runs in scripts/tests/page_harness.php (outer transaction that never commits, rolled back).
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function im_run(array $post, array $opts = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => $post, 'ajax' => false, 'verify' => $opts['verify'] ?? [], 'fake_smtp' => $opts['fake_smtp'] ?? 0]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/inhouse_move_child.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -600), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -600)]; } }
    return $res;
}
function im_clean(array $r, string $label): void {
    ok("$label: harness rolled back, nothing left behind", empty($r['leak']) && empty($r['marker_lost']) && !empty($r['rolled_back']), json_encode([$r['error'] ?? null, $r['raw'] ?? null]));
}

echo "== F5: in-house move to another room of the same type ==\n";
$r = im_run(['scenario' => 'f5']);
$j = $r['json'] ?? [];
im_clean($r, 'F5');
ok('F5 rules: a checked-in booking may be assigned a room', ($j['validate_in_house'] ?? null) === true, json_encode($j ?: $r));
ok('F5 rules: confirmed still allowed; checked-out and cancelled still refused', ($j['validate_confirmed'] ?? null) === true && ($j['validate_checked_out'] ?? null) === false && ($j['validate_cancelled'] ?? null) === false);
ok('F5 picker: the dirty room is listed as not available, the clean one is available', ($j['cand_dirty_available'] ?? null) === false && ($j['cand_clean_available'] ?? null) === true);
$ids = $j['ids'] ?? [];
$d = $j['after_dirty'] ?? [];
ok('F5 clean-room rule: a room that is not clean is refused', ($j['dirty_assigned'] ?? null) === false && stripos((string)($j['dirty_error'] ?? ''), 'clean') !== false, (string)($j['dirty_error'] ?? ''));
ok('F5 clean-room rule: the guest stays in the old room (pointer, hold and status unchanged)',
    (int)($d['booking']['individual_room_id'] ?? 0) === (int)($ids['a1'] ?? -1) && ($d['held'] ?? null) === [(int)($ids['a1'] ?? -1)] && ($d['a1']['status'] ?? '') === 'occupied');
$c = $j['after_clean'] ?? [];
ok('F5: the move to a clean room of the same type succeeds', ($j['clean_assigned'] ?? null) === true);
ok('F5: the booking now holds only the new room', (int)($c['booking']['individual_room_id'] ?? 0) === (int)($ids['a3'] ?? -1) && ($c['held'] ?? null) === [(int)($ids['a3'] ?? -1)], json_encode($c['held'] ?? null));
ok('F5: the new room is occupied', ($c['a3']['status'] ?? '') === 'occupied');
ok('F5: the old room goes to housekeeping (cleaning + an open cleaning task)', ($c['a1']['status'] ?? '') === 'cleaning' && (int)($c['a1']['open_tasks'] ?? 0) === 1, json_encode($c['a1'] ?? null));
ok('F5: money and room type are untouched', (float)($c['booking']['total_with_vat'] ?? -1) === (float)($ids['before']['total_with_vat'] ?? -2) && (int)($c['booking']['room_id'] ?? 0) === (int)($ids['before']['room_id'] ?? -1));
ok('F5: a guest who departs today has nothing left to move (refused, stays put)', ($j['departing_assigned'] ?? null) === false && ($j['departing_held'] ?? null) === true, (string)($j['departing_error'] ?? ''));

ok('F5: an out-of-service room the guest leaves keeps its status and gets no cleaning task', ($j['oos_assigned'] ?? null) === true && ($j['oos_old']['status'] ?? '') === 'out_of_order' && (int)($j['oos_old']['open_tasks'] ?? -1) === 0 && ($j['oos_new']['status'] ?? '') === 'occupied', json_encode([$j['oos_old'] ?? null, $j['oos_new'] ?? null]));

echo "== F4: in-house room-type change ==\n";
$r = im_run(['scenario' => 'f4']);
$j = $r['json'] ?? [];
im_clean($r, 'F4');
ok('F4: the upgrade goes through', !empty($j['result']['success']), json_encode($j['result'] ?? $r));
$ids = $j['ids'] ?? [];
ok('F4: the booking is on the new type and holds one room of it', (int)($j['booking']['room_id'] ?? 0) === (int)($ids['type_b'] ?? -1) && count($j['held'] ?? []) === 1 && in_array((int)($j['held'][0] ?? 0), [(int)($ids['b1'] ?? -1)], true), json_encode([$j['booking'] ?? null, $j['held'] ?? null]));
ok('F4: the OLD room is released to housekeeping (status cleaning, not occupied) with an open cleaning task', ($j['a1']['status'] ?? '') === 'cleaning' && (int)($j['a1']['open_tasks'] ?? 0) === 1 && ($j['a1']['hk'] ?? '') === 'pending', json_encode($j['a1'] ?? null));
ok('F4: only the NEW room is occupied', ($j['b1']['status'] ?? '') === 'occupied');
ok('F4: the dirty room of the new type was not used and is untouched', ($j['b2']['status'] ?? '') === 'cleaning');

echo "== F6: public booking for two rooms is saved as a group ==\n";
$pick = $pdo->query("SELECT r.id, r.max_guests FROM rooms r WHERE r.is_active = 1 AND r.max_guests BETWEEN 1 AND 6
    AND (SELECT COUNT(*) FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1) >= 3
    AND NOT EXISTS (SELECT 1 FROM room_combinations rc WHERE rc.combined_room_type_id = r.id AND rc.is_active = 1)
    ORDER BY r.max_guests ASC, r.id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$pick) {
    ok('F6 fixture: a room type with 3+ rooms and no joined pairs exists', false, 'none found');
} else {
    $in = date('Y-m-d', strtotime('+75 day'));
    $r = im_run(['scenario' => 'f6', 'room_type_id' => (string)$pick['id'], 'guests' => (string)((int)$pick['max_guests'] + 1)], [
        'fake_smtp' => 9, // closed local port: the confirmation emails fail harmlessly, nothing is sent
        'verify' => ["SELECT id, booking_reference, primary_booking_id, status FROM bookings WHERE guest_email = 'zz-split-test@example.invalid' AND check_in_date = '$in' ORDER BY id"],
    ]);
    im_clean($r, 'F6');
    $rows = $r['verify'][0] ?? [];
    if (!is_array($rows) || count($rows) < 2) {
        ok('F6: the party was split across two bookings', false, json_encode([$rows, $r['output_flash'] ?? null, $r['raw'] ?? null]));
    } else {
        $lead = $rows[0];
        ok('F6: the party was split across two bookings', count($rows) === 2, 'rows ' . count($rows));
        ok('F6: the first booking is the lead (no primary pointer)', $lead['primary_booking_id'] === null);
        $allLinked = true;
        foreach (array_slice($rows, 1) as $sec) { $allLinked = $allLinked && (int)$sec['primary_booking_id'] === (int)$lead['id']; }
        ok('F6: every other room points at the lead (the cancel dialog offers the whole group)', $allLinked, json_encode($rows));
    }
}

echo "== F7: last_cleaned_at moves on every clean ==\n";
$rid = random_int(2000000000, 2100000000);
$aid = $rid + 1;
$cfg = base64_encode(json_encode(['post' => ['action' => 'mark_complete', 'id' => (string)$aid], 'ajax' => false,
    'setup' => [
        "INSERT INTO individual_rooms (id, room_type_id, room_number, room_name, floor, status, housekeeping_status, last_cleaned_at, is_active) VALUES ($rid, (SELECT id FROM rooms ORDER BY id LIMIT 1), 'ZF7$rid', 'zz test', 1, 'cleaning', 'pending', '2020-01-01 08:00:00', 1)",
        "INSERT INTO housekeeping_assignments (id, individual_room_id, status, due_date, assignment_type, notes, created_at) VALUES ($aid, $rid, 'pending', CURDATE(), 'checkout_cleanup', 'zz test', NOW())",
    ],
    'verify' => ["SELECT status, housekeeping_status, (last_cleaned_at >= CURDATE()) AS cleaned_today FROM individual_rooms WHERE id = $rid"]]));
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' admin/housekeeping.php ' . escapeshellarg($cfg) . ' 2>&1');
$r = ['error' => 'no result', 'raw' => substr((string)$out, -400)];
if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $r = $x; } }
im_clean($r, 'F7');
$row = $r['verify'][0][0] ?? [];
ok('F7: finishing a cleaning task completes the room and stamps last_cleaned_at (an old stamp is replaced)', ($row['housekeeping_status'] ?? '') === 'completed' && (int)($row['cleaned_today'] ?? 0) === 1, json_encode([$row, $r['output_flash'] ?? null, $r['raw'] ?? null]));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
