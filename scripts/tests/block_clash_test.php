<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Blocks never land on a booked night (GRID_UNIFY_PLAN Phase 2): admin/blocked-dates.php grid_apply / preview_block /
 * block_date / block_multiple, the API block create / move (includes/block-save.php) and the Assistant's Block dates
 * card (preview + verify).
 * Usage (from the repo root, Liwonde test DB only): php scripts/tests/block_clash_test.php
 *
 * Part 1 runs the page inside page_harness.php (one outer transaction, never committed, rolled back). Part 2 runs
 * in-process on a connection that can never commit (clash_test_lib.php). Fixture rooms, types and bookings are
 * written inside those transactions on nights 700+ days ahead; nothing survives.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$h = __DIR__ . '/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function run(array $cfg): array {
    global $h;
    $out = shell_exec('php ' . escapeshellarg($h) . ' ' . escapeshellarg('admin/blocked-dates.php') . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $r['raw'] = (string)$out; return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -600), 'rolled_back' => false];
}
function msg(array $r): string { return strtolower((string)($r['json']['message'] ?? '')); }
function c(array $r, int $i = 0): int { return (int)($r['verify'][$i][0]['c'] ?? -1); }
function noise(array $r): bool { return (bool)preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)\b/', (string)$r['raw']); }

require_once $root . '/config/database.php';
require_once __DIR__ . '/clash_test_lib.php';

$hex = bin2hex(random_bytes(2));
$tag = 'ZZ blk ' . $hex;
$slug = 'zz-blk-' . $hex;
$num = 'ZB' . strtoupper($hex);
$D = static fn(int $k): string => date('Y-m-d', strtotime('+' . (700 + $k) . ' days'));
$fx = [
    "INSERT INTO rooms (name, slug, description, price_per_night, max_guests, total_rooms, rooms_available, display_order, is_active) VALUES ('$tag A', '$slug-a', 'ZZ clash test', 1, 2, 3, 3, 0, 1), ('$tag P', '$slug-p', 'ZZ clash test', 1, 4, 1, 1, 0, 1)",
    "INSERT INTO individual_rooms (room_type_id, room_number, room_name, status, is_active) SELECT t.id, CONCAT('$num', x.n), 'ZZ clash test', 'available', 1 FROM rooms t JOIN (SELECT '1' n UNION SELECT '2' UNION SELECT '3') x WHERE t.slug = '$slug-a'",
    "INSERT INTO individual_rooms (room_type_id, room_number, room_name, status, is_active) SELECT t.id, CONCAT('$num', x.n), 'ZZ clash test', 'available', 1 FROM rooms t JOIN (SELECT '5' n UNION SELECT '6') x WHERE t.slug = '$slug-p'",
    "INSERT INTO room_combinations (combined_name, combined_room_type_id, room_a_id, room_b_id, is_active) SELECT 'ZZ pair', t.id, a.id, b.id, 1 FROM rooms t, individual_rooms a, individual_rooms b WHERE t.slug = '$slug-p' AND a.room_number = '{$num}5' AND b.room_number = '{$num}6'",
];
$vars = ['r1' => "SELECT id FROM individual_rooms WHERE room_number = '{$num}1'", 'r2' => "SELECT id FROM individual_rooms WHERE room_number = '{$num}2'",
    'r3' => "SELECT id FROM individual_rooms WHERE room_number = '{$num}3'", 'p1' => "SELECT id FROM individual_rooms WHERE room_number = '{$num}5'",
    'T1' => "SELECT id FROM rooms WHERE slug = '$slug-a'"];
/** Booking seed SQL: $room = room number suffix ('1') or null (no room yet); $typeSfx 'a' | 'p'. */
$bkSql = static function (string $ref, string $typeSfx, ?string $room, string $ci, string $co) use ($slug, $num): string {
    $rid = $room === null ? 'NULL' : "(SELECT id FROM individual_rooms WHERE room_number = '$num$room')";
    return "INSERT INTO bookings (booking_reference, room_id, individual_room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, amount_paid, amount_due, status)"
        . " SELECT '$ref', t.id, $rid, 'ZZ Guest $ref', 'zz.clash@example.invalid', '000', '$ci', '$co', 1, 1, 1, 0, 1000, 1000, 0, 1000, 'confirmed' FROM rooms t WHERE t.slug = '$slug-$typeSfx'";
};
$brSql = static fn(string $ref, string $room): string => "INSERT INTO booking_rooms (booking_id, individual_room_id) SELECT b.id, r.id FROM bookings b, individual_rooms r WHERE b.booking_reference = '$ref' AND r.room_number = '$num$room'";
$cInd = "SELECT COUNT(*) AS c FROM individual_room_blocked_dates d JOIN individual_rooms r ON r.id = d.individual_room_id WHERE r.room_number LIKE '$num%'";
$cType = "SELECT COUNT(*) AS c FROM blocked_dates d JOIN rooms t ON t.id = d.room_id WHERE t.slug = '$slug-a'";
$cLog = "SELECT COUNT(*) AS c FROM admin_activity_log WHERE action = 'room_block' AND details LIKE '%$tag%'";
$grid = static function (array $items, array $setup, array $verify, string $op = 'block', array $extra = []) use ($fx, $vars, $tag): array {
    return run(['post' => ['action' => 'grid_apply', 'op' => $op, 'items' => json_encode($items), 'block_type' => $extra['block_type'] ?? 'maintenance', 'reason' => $extra['reason'] ?? $tag],
        'ajax' => true, 'setup' => array_merge($fx, $setup), 'vars' => $vars, 'verify' => $verify] + (isset($extra['as']) ? ['as_username' => $extra['as']] : []));
};
// Item JSON with a {var} id: the harness replaces {r1} inside POST strings, so the id is posted as a quoted placeholder.
$it = static fn(string $t, string $var, array $d): array => ['t' => $t, 'id' => '{' . $var . '}', 'd' => $d];
$ref1 = 'ZB' . $hex . 'A';

echo "== Part 1: the Blocked Dates page ==\n";
$bA = [$bkSql($ref1, 'a', '1', $D(2), $D(4))]; // Room 1 sleeps the nights D2 and D3
$five = [$D(1), $D(2), $D(3), $D(4), $D(5)];
$r = $grid([$it('room', 'r1', $five)], $bA, [$cInd, $cLog]);
check(($r['json']['success'] ?? false) === true && c($r) === 3 && c($r, 1) === 1 && strpos(msg($r), 'skipped 2 booked night') !== false
    && strpos(msg($r), strtolower($ref1)) !== false && strpos(msg($r), 'zz guest') !== false && !noise($r),
    '5 nights with a booking on 2: 3 nights blocked, the message says "Skipped 2 booked" and names the guest and reference, one log row', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));
check(!empty($r['rolled_back']) && empty($r['marker_lost']), 'page run rolled back');
$p = run(['post' => ['action' => 'preview_block', 'items' => json_encode([$it('room', 'r1', $five)])], 'ajax' => true, 'setup' => array_merge($fx, $bA), 'vars' => $vars, 'verify' => [$cInd]]);
check(($p['json']['skip_nights'] ?? -1) === 2 && ($p['json']['free_nights'] ?? -1) === 3 && ($p['json']['can_block'] ?? false) === true
    && strpos((string)($p['json']['note'] ?? ''), '2 booked night(s) will be skipped') !== false && c($p) === 0,
    'preview: 2 nights will be skipped, 3 blocked; the confirm note says so and nothing is saved', json_encode($p['json'] ?? $p['raw']));
$r = $grid([$it('room', 'r1', [$D(2), $D(3)])], $bA, [$cInd, $cLog]);
check(($r['json']['success'] ?? true) === false && c($r) === 0 && c($r, 1) === 0 && strpos(msg($r), 'nothing was blocked') !== false,
    'every selected night booked: nothing saved, no log row, success false', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));
$p = run(['post' => ['action' => 'preview_block', 'items' => json_encode([$it('room', 'r1', [$D(2), $D(3)])])], 'ajax' => true, 'setup' => array_merge($fx, $bA), 'vars' => $vars]);
check(($p['json']['can_block'] ?? true) === false && strpos((string)($p['json']['note'] ?? ''), 'Nothing to block') !== false, 'preview of an all-booked selection: can_block false (no confirm is shown)', json_encode($p['json'] ?? null));
$old = "INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) SELECT id, '" . $D(2) . "', 'event', 'OLD $tag' FROM individual_rooms WHERE room_number = '{$num}1'";
$r = $grid([$it('room', 'r1', [$D(1), $D(2), $D(3)])], array_merge($bA, [$old]),
    [$cInd, "SELECT CONCAT(d.block_type, '|', d.reason) AS c FROM individual_room_blocked_dates d JOIN individual_rooms r ON r.id = d.individual_room_id WHERE r.room_number = '{$num}1' AND d.block_date = '" . $D(2) . "'"]);
check(($r['json']['success'] ?? false) === true && c($r) === 2 && ($r['verify'][1][0]['c'] ?? '') === 'event|OLD ' . $tag,
    'an old block on a booked night is left exactly as it was (not updated)', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
$r = run(['post' => ['action' => 'block_date', 'block_scope' => 'individual', 'individual_room_id' => '{r1}', 'block_date' => $D(2), 'block_type' => 'manual', 'reason' => $tag],
    'ajax' => true, 'setup' => array_merge($fx, $bA), 'vars' => $vars, 'verify' => [$cInd]]);
check(($r['json']['success'] ?? true) === false && c($r) === 0 && strpos(msg($r), 'booked') !== false, 'the single-date form on a booked night saves nothing and says it is booked', json_encode($r['json'] ?? $r['raw']));
// Owner answer 2: a room-type block on a night with a booking of the type (no room yet) blocks only the free rooms.
$typed = [$bkSql('ZB' . $hex . 'T', 'a', null, $D(2), $D(3))];
$r = run(['post' => ['action' => 'block_multiple', 'block_scope' => 'type', 'room_id' => '{T1}', 'dates' => json_encode([$D(1), $D(2)]), 'block_type' => 'manual', 'reason' => $tag],
    'ajax' => true, 'setup' => array_merge($fx, $typed), 'vars' => $vars, 'verify' => [$cType, $cInd, "SELECT COUNT(*) AS c FROM blocked_dates d JOIN rooms t ON t.id = d.room_id WHERE t.slug = '$slug-a' AND d.block_date = '" . $D(2) . "'"]]);
check(($r['json']['success'] ?? false) === true && c($r, 0) === 1 && c($r, 1) === 2 && c($r, 2) === 0 && strpos(msg($r), 'one by one') !== false && strpos(msg($r), 'does not remove') !== false,
    'room-type block over a booking with no room: the free night gets the type block; the booked night gets 2 of 3 rooms blocked singly (one kept free), and the message says so', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));
$r = $grid([$it('room', 'r1', [$D(2)]), $it('room', 'r2', [$D(2)]), $it('room', 'r3', [$D(2)])], $typed, [$cInd]);
check(($r['json']['success'] ?? false) === true && c($r) === 2 && strpos(msg($r), 'no room yet') !== false,
    'one save of 3 rooms of a type with 1 booking with no room keeps exactly one room free (running budget) and names the booking', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
$pairB = [$bkSql('ZB' . $hex . 'P', 'p', null, $D(2), $D(3)), $brSql('ZB' . $hex . 'P', '6')];
$r = $grid([$it('room', 'p1', [$D(2)])], $pairB, [$cInd]);
check(($r['json']['success'] ?? true) === false && c($r) === 0, 'a joined room whose other room is booked is skipped for both rooms', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
$seed = "INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) SELECT id, '" . $D(2) . "', 'manual', '$tag' FROM individual_rooms WHERE room_number = '{$num}1'";
$r = $grid([$it('room', 'r1', [$D(2)])], array_merge($bA, [$seed]), [$cInd], 'unblock');
check(($r['json']['success'] ?? false) === true && c($r) === 0, 'unblock is never checked against bookings (a booked night can always be unblocked)', json_encode($r['json'] ?? null));
$u = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role <> 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($u) {
    $deny = ["DELETE FROM user_permissions WHERE user_id = " . (int)$u['id'] . " AND permission_key IN ('blocked_dates','block_rooms')",
        "INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (" . (int)$u['id'] . ", 'blocked_dates', 1), (" . (int)$u['id'] . ", 'block_rooms', 0)"];
    $r = $grid([$it('room', 'r1', [$D(1)])], $deny, [$cInd], 'block', ['as' => $u['username']]);
    check(($r['json']['success'] ?? true) === false && c($r) === 0 && strpos(msg($r), 'block rooms permission') !== false, 'without Block Rooms the save is refused', json_encode($r['json'] ?? null));
}

echo "== Part 2: API and Assistant (in-process, never committed) ==\n";
require_once $root . '/includes/block-save.php';
require_once $root . '/includes/ai/ai-actions.php';
$pdo = clash_test_pdo();
$GLOBALS['pdo'] = $pdo;
$pdo->beginTransaction();
try {
    $f = clash_test_fixtures($pdo, 'ZZ clash ' . $hex);
    $E = static fn(int $k): string => date('Y-m-d', strtotime('+' . (760 + $k) . ' days'));
    $bid = clash_test_booking($pdo, 'ZB' . $hex . 'X', $f['T1'], $f['r1'], $E(2), $E(4));
    $client = ['id' => 0, 'client_name' => 'ZZ test client'];
    $cnt = static fn(int $rid): int => (int)$pdo->query("SELECT COUNT(*) FROM individual_room_blocked_dates WHERE individual_room_id = $rid")->fetchColumn();
    $res = rh_block_api_create($pdo, ['block_scope' => 'individual', 'individual_room_id' => $f['r1'], 'dates' => [$E(1), $E(2), $E(3), $E(4), $E(5)], 'reason' => $tag], $client);
    check($res['code'] === 201 && $cnt($f['r1']) === 3 && count($res['body']['skipped'] ?? []) === 2 && ($res['body']['skipped'][0]['booking_reference'] ?? '') === 'ZB' . $hex . 'X'
        && ($res['body']['data']['blocked_count'] ?? 0) === 3 && ($res['body']['warnings'] ?? null) === [],
        'API POST: free nights blocked (201), booked nights listed in skipped[] with the booking reference, warnings kept (empty)', json_encode($res));
    $res = rh_block_api_create($pdo, ['block_scope' => 'individual', 'individual_room_id' => $f['r1'], 'dates' => [$E(2), $E(3)]], $client);
    check($res['code'] === 409 && $cnt($f['r1']) === 3 && count($res['body']['skipped'] ?? []) === 2, 'API POST: every night booked -> 409, nothing saved', json_encode($res));
    $cur = $pdo->query("SELECT * FROM individual_room_blocked_dates WHERE individual_room_id = {$f['r1']} AND block_date = '" . $E(1) . "'")->fetch(PDO::FETCH_ASSOC);
    $current = ['block_scope' => 'individual', 'individual_room_id' => $f['r1'], 'block_date' => $E(1), 'block_type' => $cur['block_type'], 'reason' => $cur['reason'], 'room_id' => null];
    $res = rh_block_api_move($pdo, $current, ['block_date' => $E(3)], $client);
    $still = (int)$pdo->query("SELECT COUNT(*) FROM individual_room_blocked_dates WHERE individual_room_id = {$f['r1']} AND block_date = '" . $E(1) . "'")->fetchColumn();
    check($res['code'] === 409 && $still === 1, 'API PUT onto a booked night -> 409, the old block is kept', json_encode($res));
    $res = rh_block_api_move($pdo, $current, ['block_date' => $E(6)], $client);
    check($res['code'] === 200 && (int)$pdo->query("SELECT COUNT(*) FROM individual_room_blocked_dates WHERE individual_room_id = {$f['r1']} AND block_date = '" . $E(6) . "'")->fetchColumn() === 1,
        'API PUT onto a free night moves the block', json_encode($res));

    // Assistant card: the preview lists only the nights the save may block, so a correct partial save verifies.
    $roomNo = (string)$pdo->query("SELECT room_number FROM individual_rooms WHERE id = {$f['r2']}")->fetchColumn();
    clash_test_booking($pdo, 'ZB' . $hex . 'Y', $f['T1'], $f['r2'], $E(11), $E(12));
    $user = $pdo->query("SELECT * FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $pv = rh_ai_pv_block_dates($pdo, $user, ['what' => 'room ' . $roomNo, 'from' => $E(10), 'to' => $E(12), 'type' => 'manual']);
    check(empty($pv['error']) && ($pv['block']['dates'] ?? null) === [$E(10), $E(12)] && !empty($pv['card']['warn']) && ($pv['facts']['booked_nights_skipped'] ?? 0) === 1,
        'Assistant preview: the booked night is left out of the card\'s nights and the card warns about it', json_encode([$pv['error'] ?? null, $pv['block'] ?? null, $pv['card']['warn'] ?? null]));
    $tg = ['scope' => 'room', 'id' => $f['r2'], 'room_ids' => [$f['r2']], 'type_id' => $f['T1'], 'label' => 'Room ' . $roomNo, 'dates' => [$E(10), $E(11), $E(12)]];
    $sv = rh_block_save($pdo, [$tg], 'manual', $tag, ['user_id' => (int)$user['id'], 'user_name' => 'test']);
    check($sv['ok'] && rh_ai_vf_block_dates($pdo, [], $pv) === true, 'Assistant verify passes on the correct partial save (booked night skipped)', json_encode([$sv['saved'], $sv['skipped']]));
    $pdo->exec("DELETE FROM individual_room_blocked_dates WHERE individual_room_id = {$f['r2']} AND block_date = '" . $E(12) . "'");
    check(rh_ai_vf_block_dates($pdo, [], $pv) === false, 'Assistant verify still fails when a night it promised is not blocked');
    $tn = (string)$pdo->query("SELECT name FROM rooms WHERE id = {$f['T1']}")->fetchColumn();
    clash_test_booking($pdo, 'ZB' . $hex . 'Z', $f['T1'], null, $E(21), $E(22));
    $pv = rh_ai_pv_block_dates($pdo, $user, ['what' => $tn, 'from' => $E(20), 'to' => $E(21), 'type' => 'manual']);
    check(empty($pv['error']) && ($pv['block']['dates'] ?? null) === [$E(20)] && count($pv['block']['singles'] ?? []) === 2,
        'Assistant preview of a room-type block: the night with a booking is promised as 2 rooms blocked one by one', json_encode([$pv['error'] ?? null, $pv['block'] ?? null]));
    $tt = ['scope' => 'type', 'id' => $f['T1'], 'room_ids' => [], 'type_id' => $f['T1'], 'label' => 'All ' . $tn . ' rooms', 'dates' => [$E(20), $E(21)]];
    $sv = rh_block_save($pdo, [$tt], 'manual', $tag, ['user_id' => (int)$user['id'], 'user_name' => 'test']);
    check($sv['ok'] && rh_ai_vf_block_dates($pdo, [], $pv) === true, 'Assistant verify passes for the type block + single-room blocks', json_encode([$sv['added'], $sv['singles']]));
} catch (Throwable $e) {
    bad('unexpected error', $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    $pdo->finalRollback();
}
check(clash_test_left_behind() === 0, 'nothing left behind');
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
