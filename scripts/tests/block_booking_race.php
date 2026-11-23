<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * A block and a booking for the same room and night at the same moment (GRID_UNIFY_PLAN Phase 2): exactly one wins,
 * never both. Usage (Liwonde test DB only): php scripts/tests/block_booking_race.php
 *
 * Two real PHP processes race with normal COMMITTING connections (a rolled-back harness cannot show a race): the
 * block through includes/block-save.php (block_booking_race_child.php) and the booking through the real
 * admin/create-booking.php save (concurrent_booking_child.php). Everything runs on a THROWAWAY room type with ONE room
 * (type "BLKRACE ...", room "BR-..."), far-future nights. Every row made here is deleted again by id / tag when the
 * test ends (leftovers of an aborted run are removed first). No schema changes.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$run = strtoupper(bin2hex(random_bytes(3)));
$tag = 'BLKRACE ' . $run;
$dependents = ['booking_audit_log', 'booking_notes', 'booking_rooms', 'booking_status_log', 'booking_timeline_logs', 'booking_charges',
    'booking_date_adjustments', 'booking_packages', 'tentative_booking_log', 'guest_communication_log', 'booking_financial_audit'];
$made = ['type' => 0, 'room' => 0];

function br_table_exists(PDO $pdo, string $t): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$t]);
    return (int)$st->fetchColumn() > 0;
}
/** Bookings of this test (guest names "CONCTEST BLKRACE ...") and every row linked to them. */
function br_cleanup_bookings(PDO $pdo): void {
    global $dependents;
    $ids = $pdo->query("SELECT id FROM bookings WHERE guest_name LIKE 'CONCTEST BLKRACE%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach ($dependents as $t) {
            if (br_table_exists($pdo, $t)) { $pdo->exec("DELETE FROM `$t` WHERE booking_id IN ($in)"); }
        }
        $pdo->exec("DELETE FROM bookings WHERE id IN ($in)");
    }
}
function br_cleanup(PDO $pdo): void {
    global $made;
    br_cleanup_bookings($pdo);
    $rooms = array_map('intval', $pdo->query("SELECT id FROM individual_rooms WHERE room_number LIKE 'BR-%' AND room_type_id IN (SELECT id FROM rooms WHERE name LIKE 'BLKRACE%')")->fetchAll(PDO::FETCH_COLUMN));
    if ($made['room']) { $rooms[] = (int)$made['room']; }
    if ($rooms) {
        $in = implode(',', array_unique($rooms));
        $pdo->exec("DELETE FROM individual_room_blocked_dates WHERE individual_room_id IN ($in)");
        $pdo->exec("DELETE FROM individual_rooms WHERE id IN ($in)");
    }
    $pdo->exec("DELETE FROM admin_activity_log WHERE action = 'room_block' AND details LIKE '%BLKRACE%'");
    if ($made['type']) { $pdo->exec("DELETE FROM rooms WHERE id = " . (int)$made['type']); }
    $pdo->exec("DELETE FROM rooms WHERE name LIKE 'BLKRACE%'");
    $made = ['type' => 0, 'room' => 0];
}
register_shutdown_function(function () use ($pdo) { try { br_cleanup($pdo); } catch (Throwable $e) { echo "CLEANUP ERROR: " . $e->getMessage() . "\n"; } });
br_cleanup($pdo);

$pdo->prepare("INSERT INTO rooms (name, slug, description, price_per_night, max_guests, rooms_available, total_rooms, is_active, display_order, children_allowed)
    VALUES (?, ?, 'block race test room type - removed automatically', 100, 2, 1, 1, 1, 9999, 1)")->execute([$tag . ' T', 'blkrace-' . strtolower($run)]);
$made['type'] = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO individual_rooms (room_type_id, room_number, room_name, status, is_active) VALUES (?, ?, 'BLKRACE room', 'available', 1)")->execute([$made['type'], 'BR-' . $run]);
$made['room'] = (int)$pdo->lastInsertId();

$race = static function (int $round, string $night, float $blockDelay = 0.0) use ($root, $run, $tag, $made): array {
    $start = microtime(true) + 2.5;
    $co = date('Y-m-d', strtotime($night . ' +1 day'));
    $booking = ['create_booking' => '1', 'guest_name' => 'CONCTEST BLKRACE ' . $run . ' ' . $round, 'guest_email' => 'conctest@example.invalid', 'guest_phone' => '+265000000002',
        'number_of_guests' => '2', 'child_guests' => '0', 'check_in_date' => $night, 'check_out_date' => $co, 'booking_status' => 'pending', 'auto_assign_room' => '1',
        'room_line_room_id' => [(string)$made['type']], 'room_line_qty' => ['1'], 'room_line_occupancy' => ['double'], 'room_line_override' => ['']];
    $cmds = [
        'block' => [$root . '/scripts/tests/block_booking_race_child.php', base64_encode(json_encode(['room_id' => $made['room'], 'night' => $night, 'start_at' => $start + $blockDelay, 'reason' => $tag]))],
        'booking' => [$root . '/scripts/tests/concurrent_booking_child.php', base64_encode(json_encode(['post' => $booking, 'start_at' => $start, 'tag' => 'B', 'run' => $run]))],
    ];
    $procs = [];
    foreach ($cmds as $k => [$script, $arg]) {
        $procs[$k] = [proc_open([PHP_BINARY, $script, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
    }
    $out = [];
    foreach ($procs as $k => [$p, $pipes]) {
        $text = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        $out[$k] = preg_match('/@@RESULT@@(.*)$/m', $text, $m) && is_array($j = json_decode($m[1], true)) ? $j + ['ran' => true] : ['ran' => false, 'raw' => substr($text, -400)];
    }
    return $out;
};

// Rounds 1-3 start together; rounds 4-5 let the booking start a moment earlier (the other order: the block must then
// wait for the booking's locks and skip the night).
$winners = [];
for ($round = 1; $round <= 5; $round++) {
    $night = date('Y-m-d', strtotime('+' . (900 + $round) . ' days'));
    $res = $race($round, $night, $round >= 4 ? ($round === 4 ? 0.15 : 0.6) : 0.0);
    $blocked = (int)$pdo->query("SELECT COUNT(*) FROM individual_room_blocked_dates WHERE individual_room_id = {$made['room']} AND block_date = '$night'")->fetchColumn();
    $booked = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE guest_name = " . $pdo->quote('CONCTEST BLKRACE ' . $run . ' ' . $round) . " AND status IN ('pending','tentative','confirmed','checked-in')")->fetchColumn();
    ok("round $round: both processes ran", $res['block']['ran'] && $res['booking']['ran'], json_encode($res));
    ok("round $round: never both a block and a booking on the night", !($blocked > 0 && $booked > 0), "blocked=$blocked booked=$booked " . json_encode($res));
    ok("round $round: exactly one of them won", ($blocked > 0) xor ($booked > 0), "blocked=$blocked booked=$booked " . json_encode($res));
    $winners[] = $blocked ? 'block' : ($booked ? 'booking' : 'none');
    echo '  winner: ' . end($winners) . ($booked && !$blocked ? ' (block said: ' . substr((string)($res['block']['message'] ?? ''), 0, 120) . ')' : '') . "\n";
    br_cleanup_bookings($pdo);
    $pdo->exec("DELETE FROM individual_room_blocked_dates WHERE individual_room_id = {$made['room']}");
}

ok('the booking-first order was exercised at least once (the block then skipped the night)', in_array('booking', $winners, true), json_encode($winners));
br_cleanup($pdo);
$left = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE guest_name LIKE 'CONCTEST BLKRACE%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE name LIKE 'BLKRACE%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE room_number LIKE 'BR-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'room_block' AND details LIKE '%BLKRACE%'")->fetchColumn();
ok('everything the test created has been removed', $left === 0, "left=$left");
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
