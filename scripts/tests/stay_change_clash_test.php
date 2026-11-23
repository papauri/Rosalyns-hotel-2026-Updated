<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Stay date changes lock like booking creation and refuse a clash with a clear message (GRID_UNIFY_PLAN Phase 2b,
 * owner 2026-11-23). Usage (from the repo root, Liwonde test DB only): php scripts/tests/stay_change_clash_test.php
 *
 * Part 1 runs in ONE transaction on a connection that can never commit (clash_test_lib.php) and is rolled back:
 *   Change dates (processBookingDateAdjustment) over a room block, another booking, a room-closing maintenance job and
 *   a room-type block is refused, nothing saved, the message names room + night(s) + what is in the way; onto free
 *   nights it saves the same total as the reprice path (calculateDateAdjustmentAmount); Add nights (stay-nights.php,
 *   the timeline end-grip) quote + apply over a block refused; room assign over a block refused; a booking that
 *   changed room between the lock plan and the locks is refused.
 * Part 2 races a stay extension (child process, real save, COMMITTING connection) against a block on the same
 *   night (block_booking_race_child.php): exactly one wins, never both. Throwaway type "STAYRACE ...", room "BR-S...",
 *   bookings "CONCTEST STAYRACE ..."; everything is deleted again by id / tag (leftovers of an aborted run first).
 */
$root = dirname(__DIR__, 2);
chdir($root);
require $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/includes/stay-change-lock.php';
require_once $root . '/includes/stay-nights.php';
require_once __DIR__ . '/clash_test_lib.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

$mainPdo = $pdo;
$adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn(); // booking_date_adjustments.adjusted_by FK
// ---------------------------------------------------------------- Part 1 (rolled back)
$pdo = clash_test_pdo();
$GLOBALS['pdo'] = $pdo;
$pdo->beginTransaction();
try {
    $fx = clash_test_fixtures($pdo, 'ZZ stayclash ' . bin2hex(random_bytes(2)));
    [$T1, $T2] = [$fx['T1'], $fx['T2']];
    [$r1, $r2, $r3, $r4] = [$fx['r1'], $fx['r2'], $fx['r3'], $fx['r4']];
    $num = static fn(int $rid): string => (string)$pdo->query("SELECT room_number FROM individual_rooms WHERE id = $rid")->fetchColumn();
    $D = static fn(int $k): string => date('Y-m-d', strtotime('+' . (700 + $k) . ' days'));
    $bk = static fn(string $ref, int $type, ?int $room, string $ci, string $co, string $status = 'confirmed'): int
        => clash_test_booking($pdo, $ref, $type, $room, $ci, $co, $status);
    $row = static function (int $id) use ($pdo): array {
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $adjust = static fn(int $id, string $ci, string $co): array => processBookingDateAdjustment($id, $ci, $co, 'stay clash test', $adminId, 'test');
    $nights = static fn(string $a, string $b): string => rh_block_nights_text(rh_stay_seg_nights($a, $b));

    echo "== Change dates over a room block ==\n";
    $x1 = $bk('ZZS-1', $T1, $r1, $D(10), $D(12));
    $pdo->prepare("INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) VALUES (?, ?, 'maintenance', 'roof leak')")->execute([$r1, $D(12)]);
    $pdo->prepare("INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) VALUES (?, ?, 'maintenance', 'roof leak')")->execute([$r1, $D(13)]);
    $res = $adjust($x1, $D(10), $D(14));
    $want = 'Not saved: Room ' . $num($r1) . ' is blocked on ' . $nights($D(12), $D(14)) . ' (Maintenance: roof leak). Choose other dates or another room.';
    check(empty($res['success']) && (string)$res['message'] === $want, 'extend over a blocked night is refused with the room, nights and block reason', json_encode($res));
    check(substr((string)$row($x1)['check_out_date'], 0, 10) === $D(12), 'nothing saved: the check-out date is unchanged');

    echo "== Change dates over another booking ==\n";
    $x2 = $bk('ZZS-2', $T1, $r2, $D(20), $D(22));
    $bk('ZZS-Y', $T1, $r2, $D(22), $D(24));
    $res = $adjust($x2, $D(20), $D(23));
    $want = 'Not saved: Room ' . $num($r2) . ' is booked on ' . $nights($D(22), $D(23)) . ' by ZZ Guest ZZS-Y (ZZS-Y). Choose other dates or another room.';
    check(empty($res['success']) && (string)$res['message'] === $want, 'extend over another booking is refused naming guest + reference', json_encode($res));
    check(substr((string)$row($x2)['check_out_date'], 0, 10) === $D(22), 'nothing saved (other booking)');

    echo "== Change dates over a room-closing maintenance job (block_room = 1) ==\n";
    $x3 = $bk('ZZS-3', $T1, $r3, $D(30), $D(31));
    $pdo->prepare("INSERT INTO room_maintenance_schedules (individual_room_id, title, status, start_date, end_date, block_room, due_date) VALUES (?, 'ZZ boiler', 'planned', ?, ?, 1, ?)")
        ->execute([$r3, $D(31) . ' 08:00:00', $D(32) . ' 12:00:00', $D(32)]);
    $res = $adjust($x3, $D(30), $D(33));
    $want = 'Not saved: Room ' . $num($r3) . ' is blocked on ' . $nights($D(31), $D(33)) . ' (Maintenance: ZZ boiler). Choose other dates or another room.';
    check(empty($res['success']) && (string)$res['message'] === $want, 'extend over block_room=1 maintenance is refused naming the job', json_encode($res));
    $pdo->exec("UPDATE room_maintenance_schedules SET block_room = 0 WHERE title = 'ZZ boiler'");
    $before = $row($x3);
    $calc = calculateDateAdjustmentAmount($before, $D(30), $D(33));
    $res = $adjust($x3, $D(30), $D(33));
    check(!empty($res['success']), 'the same extension saves once the job no longer closes the room (block_room = 0)', json_encode($res));

    echo "== Change dates over a room-type block ==\n";
    $x4 = $bk('ZZS-4', $T2, $r4, $D(40), $D(41));
    $pdo->prepare("INSERT INTO blocked_dates (room_id, block_date, block_type, reason) VALUES (?, ?, 'event', 'ZZ wedding')")->execute([$T2, $D(41)]);
    $res = $adjust($x4, $D(40), $D(42));
    check(empty($res['success']) && strpos((string)$res['message'], 'Not saved: All ') === 0 && strpos((string)$res['message'], 'rooms are blocked on ' . $nights($D(41), $D(42)) . ' (Event: ZZ wedding)') !== false,
        'extend over a room-type block is refused naming the block', json_encode($res));

    echo "== Change dates onto free nights: saved, same total as the reprice path ==\n";
    $x5 = $bk('ZZS-5', $T1, $r1, $D(50), $D(52));
    $pdo->exec("UPDATE bookings SET total_amount = 200000, total_with_vat = 200000, amount_due = 200000 WHERE id = $x5");
    $before = $row($x5);
    $calc = calculateDateAdjustmentAmount($before, $D(50), $D(54));
    $res = $adjust($x5, $D(50), $D(54));
    $after = $row($x5);
    check(!empty($res['success']) && substr((string)$after['check_out_date'], 0, 10) === $D(54) && (int)$after['number_of_nights'] === 4, 'extend onto free nights saves the new dates', json_encode($res));
    check(abs((float)$after['total_with_vat'] - (float)$calc['new_total_amount']) <= BALANCE_TOLERANCE && abs((float)$calc['new_total_amount'] - 400000) <= BALANCE_TOLERANCE,
        'the saved total equals the reprice path (2 -> 4 nights at the booked rate: 400,000.00)', $after['total_with_vat'] . ' vs ' . $calc['new_total_amount']);
    $res = $adjust($x5, $D(50), $D(53));
    check(!empty($res['success']) || stripos((string)$res['message'], 'Not saved') === false, 'shortening is never refused as a clash', json_encode($res));

    echo "== Add nights (in-house; the timeline end-grip) over a block ==\n";
    $today = date('Y-m-d');
    $x6 = $bk('ZZS-6', $T1, $r2, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day')), 'checked-in');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $pdo->prepare("INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) VALUES (?, ?, 'manual', 'ZZ painting')")->execute([$r2, $tomorrow]);
    // The quote's availability (rh_stay_nights_quote) is the gate's 'detail' (it skips the check inside a caller's
    // transaction, as here, so the gate is called the way the quote calls it).
    $q = rh_stay_nights_quote($pdo, $row($x6), 1, null, false, 'MWK');
    $g = rh_validate_booking_stay_change($x6, (string)$row($x6)['check_in_date'], date('Y-m-d', strtotime('+2 days')));
    $q['available'] = !empty($g['ok']);
    $q['availability_error'] = (string)$g['detail'];
    check(!empty($q['ok']) && $q['available'] === false && strpos((string)$q['availability_error'], 'Room ' . $num($r2) . ' is blocked on ' . $nights($tomorrow, date('Y-m-d', strtotime('+2 days'))) . ' (Blocked: ZZ painting)') === 0,
        'the quote says which room, night and block is in the way (no "Not saved" lead in a preview)', json_encode([$q['available'] ?? null, $q['availability_error'] ?? null]));
    $err = '';
    try {
        rh_stay_nights_apply($pdo, $x6, ['delta' => 1, 'expected_new_total' => $q['new_total'], 'expected_total_delta' => $q['total_delta'], 'reason' => 'stay clash test'], ['id' => $adminId, 'full_name' => 'test']);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    check(strpos($err, 'Not saved: Room ' . $num($r2) . ' is blocked on') === 0, 'Add nights over a block is refused with the clear text', $err);
    check(substr((string)$row($x6)['check_out_date'], 0, 10) === $tomorrow, 'nothing saved (add nights)');

    echo "== Room assign / move over a block ==\n";
    $x7 = $bk('ZZS-7', $T1, null, $D(12), $D(13));
    $okAssign = assignIndividualRoomToBooking($x7, $r1);
    check($okAssign === false && strpos(rh_room_assign_last_error(), 'Not saved: Room ' . $num($r1) . ' is blocked on ' . $nights($D(12), $D(13))) === 0,
        'assigning a room blocked on the stay is refused with the clear text', rh_room_assign_last_error());
    check($row($x7)['individual_room_id'] === null, 'nothing saved (assign)');

    echo "== The booking changed room between the lock plan and the locks ==\n";
    $x8 = $bk('ZZS-8', $T1, $r3, $D(60), $D(61));
    $plan = rh_stay_lock_plan($pdo, $x8);
    $pdo->exec("UPDATE bookings SET individual_room_id = $r1 WHERE id = $x8");
    $err = '';
    $pdo->beginTransaction();
    try {
        rh_stay_take_locks($pdo, $plan);
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    }
    $pdo->rollBack();
    check($err === rh_stay_lock_changed_message(), 'locks that no longer cover the booking refuse the save', $err);
    check(rh_stay_plan_covers(rh_stay_lock_plan($pdo, $x8, [], [$r2]), [$T1], [$r1, $r2]) && !rh_stay_plan_covers($plan, [], [$r1]),
        'the plan covers the booking\'s room and a room it moves to');
} catch (Throwable $e) {
    bad('part 1 crashed', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $pdo->finalRollback();
}
check(clash_test_left_behind() === 0, 'part 1 left nothing behind');

// ---------------------------------------------------------------- Part 2 (race, committing, throwaway rows)
$pdo = $mainPdo;
$GLOBALS['pdo'] = $pdo;
$run = strtoupper(bin2hex(random_bytes(3)));
$tag = 'STAYRACE ' . $run;
$dependents = ['booking_audit_log', 'booking_notes', 'booking_rooms', 'booking_status_log', 'booking_timeline_logs', 'booking_charges',
    'booking_date_adjustments', 'booking_packages', 'tentative_booking_log', 'guest_communication_log', 'booking_financial_audit'];
function sr_table_exists(PDO $pdo, string $t): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$t]);
    return (int)$st->fetchColumn() > 0;
}
function sr_cleanup_bookings(PDO $pdo): void {
    global $dependents;
    $ids = $pdo->query("SELECT id FROM bookings WHERE guest_name LIKE 'CONCTEST STAYRACE%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach ($dependents as $t) {
            if (sr_table_exists($pdo, $t)) { $pdo->exec("DELETE FROM `$t` WHERE booking_id IN ($in)"); }
        }
        $pdo->exec("DELETE FROM bookings WHERE id IN ($in)");
    }
}
function sr_cleanup(PDO $pdo): void {
    sr_cleanup_bookings($pdo);
    $rooms = array_map('intval', $pdo->query("SELECT id FROM individual_rooms WHERE room_number LIKE 'BR-S%' AND room_type_id IN (SELECT id FROM rooms WHERE name LIKE 'STAYRACE%')")->fetchAll(PDO::FETCH_COLUMN));
    if ($rooms) {
        $in = implode(',', $rooms);
        $pdo->exec("DELETE FROM individual_room_blocked_dates WHERE individual_room_id IN ($in)");
        $pdo->exec("DELETE FROM individual_rooms WHERE id IN ($in)");
    }
    $pdo->exec("DELETE FROM admin_activity_log WHERE action = 'room_block' AND details LIKE '%STAYRACE%'");
    $pdo->exec("DELETE FROM rooms WHERE name LIKE 'STAYRACE%'");
}
register_shutdown_function(function () use ($pdo) { try { sr_cleanup($pdo); } catch (Throwable $e) { echo "CLEANUP ERROR: " . $e->getMessage() . "\n"; } });
sr_cleanup($pdo);

$pdo->prepare("INSERT INTO rooms (name, slug, description, price_per_night, max_guests, rooms_available, total_rooms, is_active, display_order, children_allowed)
    VALUES (?, ?, 'stay race test room type - removed automatically', 100, 2, 1, 1, 1, 9999, 1)")->execute([$tag . ' T', 'stayrace-' . strtolower($run)]);
$raceType = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO individual_rooms (room_type_id, room_number, room_name, status, is_active) VALUES (?, ?, 'STAYRACE room', 'available', 1)")->execute([$raceType, 'BR-S' . $run]);
$raceRoom = (int)$pdo->lastInsertId();

$race = static function (int $round, string $mode, float $blockDelay, float $extDelay) use ($root, $pdo, $run, $tag, $raceType, $raceRoom): array {
    if ($mode === 'nights') {
        [$ci, $co] = [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day'))];
        $status = 'checked-in';
    } else {
        $ci = date('Y-m-d', strtotime('+' . (950 + 3 * $round) . ' days'));
        $co = date('Y-m-d', strtotime($ci . ' +1 day'));
        $status = 'confirmed';
    }
    $night = $co; // the night the extension adds
    $newCo = date('Y-m-d', strtotime($co . ' +1 day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, individual_room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, amount_paid, amount_due, status)
        VALUES (?, ?, ?, ?, 'conctest@example.invalid', '000', ?, ?, ?, 1, 1, 0, 1000, 1000, 0, 1000, ?)")
        ->execute(['SR' . $run . $round, $raceType, $raceRoom, 'CONCTEST STAYRACE ' . $run . ' ' . $round, $ci, $co, (int)round((strtotime($co) - strtotime($ci)) / 86400), $status]);
    $bid = (int)$pdo->lastInsertId();
    $start = microtime(true) + 2.5;
    $cmds = [
        'block' => [$root . '/scripts/tests/block_booking_race_child.php', base64_encode(json_encode(['room_id' => $raceRoom, 'night' => $night, 'start_at' => $start + $blockDelay, 'reason' => $tag]))],
        'stay' => [$root . '/scripts/tests/stay_change_race_child.php', base64_encode(json_encode(['booking_id' => $bid, 'mode' => $mode, 'new_check_out' => $newCo, 'start_at' => $start + $extDelay]))],
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
    $blocked = (int)$pdo->query("SELECT COUNT(*) FROM individual_room_blocked_dates WHERE individual_room_id = $raceRoom AND block_date = '$night'")->fetchColumn();
    $extended = substr((string)$pdo->query("SELECT check_out_date FROM bookings WHERE id = $bid")->fetchColumn(), 0, 10) === $newCo ? 1 : 0;
    return [$out, $blocked, $extended];
};

$winners = [];
// [mode, block delay, extension delay]: together, the extension first, the block first; both save paths.
$rounds = [1 => ['nights', 0.0, 0.0], 2 => ['dates', 0.0, 0.0], 3 => ['nights', 0.6, 0.0], 4 => ['dates', 0.15, 0.0], 5 => ['nights', 0.0, 0.6], 6 => ['dates', 0.0, 0.15]];
foreach ($rounds as $round => [$mode, $bd, $ed]) {
    [$res, $blocked, $extended] = $race($round, $mode, $bd, $ed);
    check($res['block']['ran'] && $res['stay']['ran'], "round $round ($mode): both processes ran", json_encode($res));
    check(!($blocked && $extended), "round $round ($mode): never both a block and the extension on the night", "blocked=$blocked extended=$extended " . json_encode($res));
    check(($blocked > 0) xor ($extended > 0), "round $round ($mode): exactly one of them won", "blocked=$blocked extended=$extended " . json_encode($res));
    $winners[] = $blocked ? 'block' : ($extended ? 'stay' : 'none');
    echo '  winner: ' . end($winners) . ($blocked && !$extended ? ' (stay said: ' . substr((string)($res['stay']['message'] ?? ''), 0, 160) . ')' : '') . "\n";
    if ($blocked && !$extended && $res['stay']['ran']) {
        check(strpos((string)$res['stay']['message'], 'Not saved: Room BR-S' . $run . ' is blocked on') === 0 || (string)$res['stay']['message'] === rh_lock_conflict_message(),
            "round $round ($mode): the losing extension says which room and night is blocked", (string)$res['stay']['message']);
    }
    sr_cleanup_bookings($pdo);
    $pdo->exec("DELETE FROM individual_room_blocked_dates WHERE individual_room_id = $raceRoom");
}
check(in_array('stay', $winners, true) && in_array('block', $winners, true), 'both orders were exercised (each side won at least once)', json_encode($winners));
sr_cleanup($pdo);
$left = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE guest_name LIKE 'CONCTEST STAYRACE%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE name LIKE 'STAYRACE%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE room_number LIKE 'BR-S%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'room_block' AND details LIKE '%STAYRACE%'")->fetchColumn();
check($left === 0, 'everything the race created has been removed', "left=$left");

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
