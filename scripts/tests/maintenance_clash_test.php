<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * A maintenance job that closes the room never lands on a booked night (GRID_UNIFY_PLAN Phase 2, owner answers 1 + 3):
 * admin/room-maintenance.php add / update refuse with the bookings + free dates; generated jobs (bulk assign, auto,
 * recurring) are created with block_room = 0 on booked nights.
 * Usage (from the repo root, Liwonde test DB only): php scripts/tests/maintenance_clash_test.php
 *
 * Page runs are inside page_harness.php (never committed, rolled back); the recurring generator runs in-process on a
 * connection that can never commit (clash_test_lib.php). Fixtures are written inside those transactions.
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
    $out = shell_exec('php ' . escapeshellarg($h) . ' ' . escapeshellarg('admin/room-maintenance.php') . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $r['raw'] = (string)$out; return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -600), 'rolled_back' => false];
}
function msg(array $r): string { return (string)($r['json']['message'] ?? ''); }
function v(array $r, int $i = 0) { return $r['verify'][$i][0]['c'] ?? null; }

require_once $root . '/config/database.php';
require_once __DIR__ . '/clash_test_lib.php';

$hex = bin2hex(random_bytes(2));
$slug = 'zz-mnt-' . $hex;
$num = 'ZM' . strtoupper($hex);
$title = 'ZZ job ' . $hex;
$D = static fn(int $k, string $t = '10:00:00'): string => date('Y-m-d', strtotime('+' . (800 + $k) . ' days')) . ' ' . $t;
$day = static fn(int $k): string => date('Y-m-d', strtotime('+' . (800 + $k) . ' days'));
$ref = 'ZM' . $hex . 'A';
$fx = [
    "INSERT INTO rooms (name, slug, description, price_per_night, max_guests, total_rooms, rooms_available, display_order, is_active) VALUES ('ZZ mnt $hex', '$slug', 'ZZ clash test', 1, 2, 2, 2, 0, 1)",
    "INSERT INTO individual_rooms (room_type_id, room_number, room_name, status, is_active) SELECT t.id, CONCAT('$num', x.n), 'ZZ clash test', 'available', 1 FROM rooms t JOIN (SELECT '1' n UNION SELECT '2') x WHERE t.slug = '$slug'",
    // Room 1 sleeps a guest on the nights D10, D11, D12 (check-in D10, check-out D13).
    "INSERT INTO bookings (booking_reference, room_id, individual_room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, amount_paid, amount_due, status)"
        . " SELECT '$ref', t.id, (SELECT id FROM individual_rooms WHERE room_number = '{$num}1'), 'ZZ Guest $ref', 'zz.clash@example.invalid', '000', '" . $day(10) . "', '" . $day(13) . "', 3, 1, 1, 0, 1000, 1000, 0, 1000, 'confirmed' FROM rooms t WHERE t.slug = '$slug'",
];
$vars = ['r1' => "SELECT id FROM individual_rooms WHERE room_number = '{$num}1'", 'r2' => "SELECT id FROM individual_rooms WHERE room_number = '{$num}2'",
    'job' => "SELECT id FROM room_maintenance_schedules WHERE title = '$title'"];
$cJobs = "SELECT COUNT(*) AS c FROM room_maintenance_schedules s JOIN individual_rooms r ON r.id = s.individual_room_id WHERE r.room_number LIKE '$num%'";
$add = static function (array $post, array $setup = [], array $verify = []) use ($fx, $vars, $title, $day): array {
    $post += ['action' => 'add_schedule', 'individual_room_id' => '{r1}', 'title' => $title . ' new', 'description' => '', 'due_date' => $day(1), 'status' => 'pending',
        'priority' => 'medium', 'maintenance_type' => 'repair', 'estimated_duration' => '60', 'block_room' => '1'];
    if ($post['block_room'] === null) {
        unset($post['block_room']); // unticked: the form posts nothing
    }
    return run(['post' => $post, 'ajax' => true, 'setup' => array_merge($fx, $setup), 'vars' => $vars, 'verify' => $verify]);
};
$seedJob = static fn(string $room, string $s, string $e, string $status = 'planned', int $block = 1): string =>
    "INSERT INTO room_maintenance_schedules (individual_room_id, title, status, start_date, end_date, block_room, due_date) SELECT id, '$title', '$status', '$s', '$e', $block, '" . date('Y-m-d', strtotime('+801 days')) . "' FROM individual_rooms WHERE room_number = '$num$room'";
$upd = static function (array $post, array $setup, array $verify = []) use ($fx, $vars, $title, $day): array {
    $post += ['action' => 'update_schedule', 'id' => '{job}', 'individual_room_id' => '{r1}', 'title' => $title, 'description' => '', 'due_date' => $day(1), 'status' => 'pending',
        'priority' => 'medium', 'maintenance_type' => 'repair', 'estimated_duration' => '60', 'block_room' => '1'];
    if ($post['block_room'] === null) {
        unset($post['block_room']);
    }
    return run(['post' => $post, 'ajax' => true, 'setup' => array_merge($fx, $setup), 'vars' => $vars, 'verify' => $verify]);
};

echo "== Add ==\n";
$r = $add(['start_date' => $D(9), 'end_date' => $D(11)], [], [$cJobs]);
check(($r['json']['success'] ?? true) === false && (int)v($r) === 0 && strpos(msg($r), 'Not saved') === 0 && strpos(msg($r), $ref) !== false && strpos(msg($r), 'Free: ') !== false,
    'Block room over a booked night: refused, nothing saved, the message names the booking and the free dates', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));
check(!empty($r['rolled_back']) && empty($r['marker_lost']), 'page run rolled back');
$r = $add(['start_date' => $D(9), 'end_date' => $D(11), 'block_room' => null], [], [$cJobs, "SELECT block_room AS c FROM room_maintenance_schedules WHERE title = '$title new'"]);
check(($r['json']['success'] ?? false) === true && (int)v($r) === 1 && (string)v($r, 1) === '0', 'the same job without Block room is saved (it does not close the room)', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));
$r = $add(['start_date' => $D(8), 'end_date' => $D(10, '00:00:00')], [], [$cJobs]);
check(($r['json']['success'] ?? false) === true && (int)v($r) === 1, 'a job ending at 00:00 on the guest\'s check-in day is saved (its last night is the night before)', json_encode($r['json'] ?? $r['raw']));
$r = $add(['start_date' => $D(13, '14:00:00'), 'end_date' => $D(14)], [], [$cJobs]);
check(($r['json']['success'] ?? false) === true && (int)v($r) === 1, 'a job starting 14:00 on the guest\'s check-out day is saved (that night is free)', json_encode($r['json'] ?? $r['raw']));
$r = $add(['start_date' => $D(9), 'end_date' => $D(11), 'status' => 'completed'], [], [$cJobs]);
check(($r['json']['success'] ?? false) === true && (int)v($r) === 1, 'a completed job is not checked (it no longer closes the room)', json_encode($r['json'] ?? $r['raw']));

echo "== Update ==\n";
$cEnd = "SELECT end_date AS c FROM room_maintenance_schedules WHERE title = '$title'";
$r = $upd(['start_date' => $D(5), 'end_date' => $D(11)], [$seedJob('1', $D(5), $D(7))], [$cEnd]);
check(($r['json']['success'] ?? true) === false && (string)v($r) === $D(7) && strpos(msg($r), $ref) !== false, 'extending a job over a booking is refused and the row is unchanged', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));
$u = $pdo->query("SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$r = $upd(['start_date' => $D(10), 'end_date' => $D(12), 'assigned_to' => (string)$u], [$seedJob('1', $D(10), $D(12))], ["SELECT assigned_to AS c FROM room_maintenance_schedules WHERE title = '$title'"]);
check(($r['json']['success'] ?? false) === true && (int)v($r) === (int)$u, 'editing the assignee of an old job that already overlaps a booking still saves (only newly covered nights are checked)', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));
$r = $upd(['start_date' => $D(10), 'end_date' => $D(12), 'status' => 'completed'], [$seedJob('1', $D(10), $D(12))], ["SELECT status AS c FROM room_maintenance_schedules WHERE title = '$title'"]);
check(($r['json']['success'] ?? false) === true && (string)v($r) === 'completed', 'marking that job completed saves', json_encode($r['json'] ?? $r['raw']));
$r = $upd(['start_date' => $D(10), 'end_date' => $D(11)], [$seedJob('2', $D(10), $D(11))], ["SELECT r.room_number AS c FROM room_maintenance_schedules s JOIN individual_rooms r ON r.id = s.individual_room_id WHERE s.title = '$title'"]);
check(($r['json']['success'] ?? true) === false && (string)v($r) === $num . '2', 'moving a job to a booked room is checked on every night and refused', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));
$r = $upd(['start_date' => $D(10), 'end_date' => $D(11), 'status' => 'pending'], [$seedJob('1', $D(10), $D(11), 'completed')], ["SELECT status AS c FROM room_maintenance_schedules WHERE title = '$title'"]);
check(($r['json']['success'] ?? true) === false && (string)v($r) === 'completed', 'reopening a completed job over a booking is checked on every night and refused', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));

echo "== Generated jobs (owner answer 3) ==\n";
$tonight = "INSERT INTO bookings (booking_reference, room_id, individual_room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, amount_paid, amount_due, status)"
    . " SELECT 'ZM{$hex}T', t.id, (SELECT id FROM individual_rooms WHERE room_number = '{$num}1'), 'ZZ Guest T', 'zz.clash@example.invalid', '000', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 2, 1, 1, 0, 1000, 1000, 0, 1000, 'confirmed' FROM rooms t WHERE t.slug = '$slug'";
$cBlk = static fn(string $room): string => "SELECT GROUP_CONCAT(s.block_room) AS c FROM room_maintenance_schedules s JOIN individual_rooms r ON r.id = s.individual_room_id WHERE r.room_number = '$num$room'";
$r = run(['post' => ['action' => 'bulk_assign_rooms', 'room_ids' => json_encode(['{r1}', '{r2}'])], 'setup' => array_merge($fx, [$tonight]), 'vars' => $vars, 'verify' => [$cBlk('1'), $cBlk('2')],
    'session_keys' => []]);
// room_ids is a JSON string: the harness {var} replacement runs on it too.
check((string)v($r, 0) === '0' && (string)v($r, 1) === '1', 'bulk assign: the booked room gets a job that does not close it (block_room 0); the free room\'s job closes it', json_encode([$r['verify'] ?? null, substr($r['raw'] ?? '', -300)]));
$r = run(['post' => ['action' => 'auto_create_tasks'], 'setup' => array_merge($fx, [$tonight, "UPDATE individual_rooms SET status = 'out_of_order' WHERE room_number IN ('{$num}1', '{$num}2')"]), 'vars' => $vars,
    'verify' => [$cBlk('1'), $cBlk('2')]]);
check((string)v($r, 0) === '0' && (string)v($r, 1) === '1', 'auto-create: the booked room\'s job does not close it; the free room\'s does', json_encode($r['verify'] ?? null));

echo "== Recurring (in-process, never committed) ==\n";
require_once $root . '/includes/maintenance-recurring.php';
$pdo = clash_test_pdo();
$GLOBALS['pdo'] = $pdo;
$pdo->beginTransaction();
try {
    $f = clash_test_fixtures($pdo, 'ZZ clash ' . $hex);
    clash_test_booking($pdo, 'ZM' . $hex . 'R', $f['T1'], $f['r1'], date('Y-m-d'), date('Y-m-d', strtotime('+2 days')));
    $prev = date('Y-m-d', strtotime('-7 days'));
    $series = $pdo->prepare("INSERT INTO room_maintenance_schedules (individual_room_id, title, status, start_date, end_date, block_room, due_date, is_recurring, recurring_pattern, completed_at)
        VALUES (?, ?, 'completed', ?, ?, ?, ?, 1, 'weekly', NOW())");
    $series->execute([$f['r1'], 'ZZ weekly ' . $hex, $prev . ' 00:00:00', date('Y-m-d', strtotime('-6 days')) . ' 00:00:00', 1, $prev]);
    $series->execute([$f['r2'], 'ZZ weekly ' . $hex, $prev . ' 00:00:00', date('Y-m-d', strtotime('-6 days')) . ' 00:00:00', 1, $prev]);
    $series->execute([$f['r3'], 'ZZ weekly ' . $hex, $prev . ' 00:00:00', date('Y-m-d', strtotime('-6 days')) . ' 00:00:00', 0, $prev]);
    $notes = [];
    rh_create_recurring_maintenance($pdo, null, $notes);
    $blk = static function (int $rid) use ($pdo, $hex): ?string {
        $v = $pdo->query("SELECT block_room FROM room_maintenance_schedules WHERE individual_room_id = $rid AND title = 'ZZ weekly $hex' AND status = 'planned'")->fetchColumn();
        return $v === false ? null : (string)$v;
    };
    check($blk($f['r1']) === '0' && $notes && strpos(implode(' ', $notes), 'does not close the room') !== false, 'recurring: the next occurrence on a booked night is created with block_room 0 and says so', json_encode([$blk($f['r1']), $notes]));
    check($blk($f['r2']) === '1', 'recurring: a free night copies the series\' Block room tick (1)');
    check($blk($f['r3']) === '0', 'recurring: a series without Block room stays without it (0)');
} catch (Throwable $e) {
    bad('unexpected error', $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    $pdo->finalRollback();
}
check(clash_test_left_behind() === 0, 'nothing left behind');
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
