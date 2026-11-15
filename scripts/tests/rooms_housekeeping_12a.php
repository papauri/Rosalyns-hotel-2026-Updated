<?php
/**
 * Step 12a part 2: rooms + housekeeping fixes R1-R7 and the "obey the tick" maintenance rule.
 *
 *   php scripts/tests/rooms_housekeeping_12a.php        pure part: rule functions + source checks, no database
 *   php scripts/tests/rooms_housekeeping_12a.php --db   adds the database part (LIWONDE ONLY - refused anywhere else,
 *                                                      Rosalyn's is live)
 *
 * The database part builds its own made-up fixture (room types, rooms, a joined pair, bookings, a maintenance task)
 * with dates around today for in-house cases and far in the future for the joined case, COMMITS it (the functions
 * under test and the page harness use their own transactions), and deletes every row it made in a shutdown handler
 * (by id). Page checks (Individual Rooms, Housekeeping) run through the rolled-back page harness.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$root = dirname(__DIR__, 2);
$withDb = in_array('--db', $argv, true);

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "[PASS] $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "[FAIL] $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

// ------------------------------------------------------------------ pure part (no database)
require_once $root . '/includes/room-status-rules.php';

echo "--- R1/R4 vacated room target ---\n";
check(rh_vacated_room_target('occupied', false, false) === 'cleaning', 'Guest leaves an occupied room -> cleaning');
check(rh_vacated_room_target('occupied', true, false) === null, 'Another in-house guest still holds the room -> left as it is');
check(rh_vacated_room_target('out_of_order', false, false) === null, 'Out-of-order room keeps its status');
check(rh_vacated_room_target('maintenance', false, true) === null, 'Room already in maintenance keeps its status');
check(rh_vacated_room_target('occupied', false, true) === 'maintenance', 'Active blocking maintenance -> maintenance');
check(rh_vacated_room_target('out_of_order', false, true) === null, 'Out of order wins over a maintenance task');
check(rh_vacated_room_target('available', false, false) === 'cleaning', 'Stale available room still gets its departure clean');

echo "--- R2 check-in hard block ---\n";
foreach (['occupied', 'maintenance', 'out_of_order'] as $s) {
    check(rh_checkin_room_hard_block($s) !== null, "Check-in into '$s' is a hard block (no override)");
}
foreach (['cleaning', 'inspection', 'available'] as $s) {
    check(rh_checkin_room_hard_block($s) === null, "'$s' is not a hard block (override may cover a dirty room)");
}

echo "--- R5 status change vs bookings ---\n";
check(rh_room_status_contradiction('available', true) !== null, 'Available refused while a guest is in-house');
check(rh_room_status_contradiction('cleaning', true) !== null, 'Cleaning refused while a guest is in-house');
check(rh_room_status_contradiction('occupied', false) !== null, 'Occupied refused with nobody in-house');
check(rh_room_status_contradiction('occupied', true) === null, 'Occupied allowed with a guest in-house');
check(rh_room_status_contradiction('maintenance', true) === null, 'Maintenance allowed with a guest in-house (emergency)');
check(rh_room_status_contradiction('out_of_order', false) === null, 'Out of order allowed with nobody in-house');
check(rh_room_status_contradiction('available', false) === null, 'Available allowed with nobody in-house');
check(rh_room_status_contradiction('cleaning', false) === null, 'Cleaning allowed with nobody in-house');

echo "--- source checks (R1-R7, block_room, R8, R10) ---\n";
$db = (string)file_get_contents($root . '/config/database.php');
$rm = (string)file_get_contents($root . '/includes/room-management.php');
$hk = (string)file_get_contents($root . '/admin/housekeeping.php');
$mt = (string)file_get_contents($root . '/admin/room-maintenance.php');
$ir = (string)file_get_contents($root . '/admin/individual-rooms.php');
$eb = (string)file_get_contents($root . '/admin/edit-booking.php');
check(strpos($db, "require_once __DIR__ . '/../includes/room-status-rules.php'") !== false, 'database.php loads the rule file');
check(substr_count($db, 'rh_vacated_room_target(') >= 2 && strpos($rm, 'rh_vacated_room_target(') !== false, 'Checkout (both paths) and room moves use rh_vacated_room_target');
check(strpos($db, 'rh_checkin_room_hard_block(') !== false, 'Check-in gate uses the hard-block rule');
check(preg_match('/function rh_validate_booking_stay_change.*?rh_room_needed_for_joined\(/s', $db) === 1, 'Stay/room change validation checks rooms needed by joined bookings');
check(strpos($eb, 'rh_room_needed_for_joined(') !== false && strpos($eb, 'rh_release_room_after_move(') !== false, 'Edit booking: joined rule in the room list + shared move rule');
check(strpos($ir, 'ir_status_contradiction_message(') !== false && substr_count($ir, 'ir_status_change_followup(') >= 3, 'Individual Rooms: single + bulk status change checked and followed up');
check(preg_match('/function rh_room_has_active_maintenance.*?block_room = 1/s', $rm) === 1, 'rh_room_has_active_maintenance obeys the Block room tick');
check(strpos($rm, 'linked_booking_id, created_at)') !== false, 'Checkout task links to its booking (R8)');
check(preg_match('/function getCheckoutCleanupRooms.*?booking_rooms br.*?released_at/s', $hk) === 1, 'Create Checkout Cleanup reads booking_rooms incl. rows released at checkout (R7)');
check(strpos($hk, 'dataset.filterHidden') !== false && strpos($hk, 'rhPagerRefresh') !== false && strpos($hk, 'clearTableFilter') !== false, 'Housekeeping filter uses the shared pager + Clear');
check(strpos($mt, 'dataset.filterHidden') !== false && strpos($mt, 'clearTableFilter') !== false && strpos($mt, 'sched_page') === false, 'Maintenance filter uses the shared pager, no server page links');
check(strpos($hk, 'ul class="occupied-rooms-list ck-rows"') !== false && strpos($hk, "Overdue'") !== false, 'Occupied cards paged; overdue guest shows Overdue');
$head = substr($ir, 0, (int)strpos($ir, '</head>'));
check(strpos($head, 'window.fetch = function') === false && strpos($ir, '__irCsrfToken') !== false, 'Individual Rooms CSRF fetch helper sits in the page body (R10)');

if (!$withDb) {
    echo "\nPure part: $pass passed, $fail failed. (Database part: run with --db at Liwonde.)\n";
    exit($fail > 0 ? 1 : 0);
}

// ------------------------------------------------------------------ database part (LIWONDE ONLY)
if (stripos((string)realpath($root), 'Liwonde') === false) {
    echo "[FAIL] refused: --db runs only at the Liwonde test hotel (Rosalyn's is live)\n";
    exit(1);
}
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
require_once $root . '/includes/pricing.php';
require_once $root . '/includes/room-management.php';

function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

$run = strtoupper(bin2hex(random_bytes(3)));
$made = ['rooms' => [], 'individual_rooms' => [], 'room_combinations' => [], 'bookings' => [], 'schedules' => []];
$day = static fn(int $n): string => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' day'));
$adminId = (int)col($pdo, 'SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1');

function mk_type(PDO $pdo, string $name, int $total): int {
    global $made, $run;
    $pdo->prepare("INSERT INTO rooms (name, description, short_description, price_per_night, max_guests, rooms_available, total_rooms, is_active, slug) VALUES (?, '', '', 100000, 2, ?, ?, 1, ?)")
        ->execute([$name . ' ' . $run, $total, $total, strtolower(preg_replace('/\W+/', '-', $name)) . '-' . strtolower($run)]);
    return $made['rooms'][] = (int)$pdo->lastInsertId();
}
function mk_room(PDO $pdo, int $typeId, string $no, string $status = 'available'): int {
    global $made, $run;
    $pdo->prepare("INSERT INTO individual_rooms (room_type_id, room_number, room_name, floor, status) VALUES (?, ?, '12a test', 1, ?)")
        ->execute([$typeId, $no . $run, $status]);
    return $made['individual_rooms'][] = (int)$pdo->lastInsertId();
}
function mk_booking(PDO $pdo, int $typeId, string $in, string $out, string $status, array $roomIds = [], ?string $releasedAt = null): int {
    global $made, $run;
    static $seq = 0;
    $seq++;
    $nights = max(1, (int)round((strtotime($out) - strtotime($in)) / 86400));
    $tt = rh_stay_totals(100000.0 * $nights, 'price');
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, individual_room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            check_in_date, check_out_date, number_of_nights, total_amount, amount_paid, amount_due, vat_rate, vat_amount, total_with_vat, tourism_levy_amount, tourism_levy_percent,
            special_requests, status, payment_status, is_tentative, occupancy_type, created_at)
        VALUES (?, ?, ?, '12A TEST GUEST', '12a-test@example.invalid', '+265000000012', 'Malawi', 2, 2, 0, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, '12a test', ?, 'unpaid', 0, 'double', NOW())")
        ->execute(['RH12A' . $run . $seq, $typeId, $roomIds[0] ?? null, $in, $out, $nights, round($tt['net'] + $tt['levy'], 2), $tt['total_with_vat'],
            $tt['vat_rate'], $tt['vat'], $tt['total_with_vat'], $tt['levy'], $tt['levy_rate'], $status]);
    $id = $made['bookings'][] = (int)$pdo->lastInsertId();
    foreach ($roomIds as $i => $rid) {
        $pdo->prepare("INSERT INTO booking_rooms (booking_id, individual_room_id, room_combination_id, is_primary, assigned_at, status_snapshot, released_at) VALUES (?, ?, NULL, ?, NOW(), 'assigned', ?)")
            ->execute([$id, $rid, $i === 0 ? 1 : 0, $releasedAt]);
    }
    return $id;
}
function mk_maint(PDO $pdo, int $roomId, int $blockRoom): int {
    global $made, $adminId;
    $pdo->prepare("INSERT INTO room_maintenance_schedules (individual_room_id, title, status, priority, maintenance_type, block_room, start_date, end_date, due_date, created_by)
        VALUES (?, '12A TEST leak', 'in_progress', 'high', 'repair', ?, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 1 DAY), CURDATE(), ?)")
        ->execute([$roomId, $blockRoom, $adminId ?: null]);
    return $made['schedules'][] = (int)$pdo->lastInsertId();
}
function room_status(PDO $pdo, int $id): string { return (string)col($pdo, 'SELECT status FROM individual_rooms WHERE id = ?', [$id]); }
function open_tasks(PDO $pdo, int $id): int { return (int)col($pdo, "SELECT COUNT(*) FROM housekeeping_assignments WHERE individual_room_id = ? AND status IN ('pending','in_progress')", [$id]); }
function harness(string $page, array $post, array $verify): array {
    $cfg = base64_encode(json_encode(['post' => $post, 'ajax' => false, 'verify' => $verify]));
    $out = (string)shell_exec('php ' . escapeshellarg(__DIR__ . '/page_harness.php') . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { return $x; } }
    return ['error' => 'no result', 'raw' => substr($out, -600)];
}
function cleanup(PDO $pdo): void {
    global $made;
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    foreach ($made['schedules'] as $id) { $pdo->prepare('DELETE FROM room_maintenance_schedules WHERE id = ?')->execute([$id]); }
    $ids = $made['bookings'];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach (['booking_rooms', 'booking_timeline_logs', 'booking_audit_log', 'booking_notes'] as $t) {
            try { $pdo->prepare("DELETE FROM $t WHERE booking_id IN ($ph)")->execute($ids); } catch (Throwable $e) { /* table may not exist */ }
        }
        $pdo->prepare("DELETE FROM bookings WHERE id IN ($ph)")->execute($ids);
    }
    foreach ($made['room_combinations'] as $id) { $pdo->prepare('DELETE FROM room_combinations WHERE id = ?')->execute([$id]); }
    foreach ($made['individual_rooms'] as $id) {
        foreach (['room_maintenance_schedules', 'room_maintenance_log', 'housekeeping_assignments', 'room_inspections', 'individual_room_blocked_dates'] as $t) {
            try { $pdo->prepare("DELETE FROM $t WHERE individual_room_id = ?")->execute([$id]); } catch (Throwable $e) { /* optional table */ }
        }
        $pdo->prepare('DELETE FROM individual_rooms WHERE id = ?')->execute([$id]);
    }
    foreach ($made['rooms'] as $id) { $pdo->prepare('DELETE FROM rooms WHERE id = ?')->execute([$id]); }
}
register_shutdown_function(static function () use ($pdo) { cleanup($pdo); });

try {
    echo "\n--- fixture ---\n";
    $t = mk_type($pdo, '12A TEST TYPE', 8);
    $shared = mk_room($pdo, $t, 'S', 'occupied');   // held by two in-house bookings
    $ooo = mk_room($pdo, $t, 'O', 'out_of_order');   // out of order while the guest was in it
    $mBlock = mk_room($pdo, $t, 'MB', 'occupied');   // active maintenance task, Block room ticked
    $mOpen = mk_room($pdo, $t, 'MO', 'occupied');    // active maintenance task, Block room unticked
    $plain = mk_room($pdo, $t, 'P', 'occupied');
    mk_maint($pdo, $mBlock, 1);
    mk_maint($pdo, $mOpen, 0);
    $guest = mk_booking($pdo, $t, $day(-1), $day(1), 'checked-in', [$plain, $shared, $ooo, $mBlock, $mOpen]);
    $other = mk_booking($pdo, $t, $day(-1), $day(2), 'checked-in', [$shared]);
    ok('Fixture made (' . $run . ')');

    echo "--- block_room rule ---\n";
    check(rh_room_has_active_maintenance($pdo, $mBlock) === true, 'Active task with Block room ticked holds the room');
    check(rh_room_has_active_maintenance($pdo, $mOpen) === false, 'Active task with Block room unticked leaves the room on sale');

    echo "--- R1 checkout ---\n";
    updateBookingRoomsStatus($guest, 'cleaning', 'Checkout completed: 12a test', $adminId ?: null);
    check(room_status($pdo, $plain) === 'cleaning' && open_tasks($pdo, $plain) === 1, 'Plain room -> cleaning with one task');
    check(room_status($pdo, $shared) === 'occupied' && open_tasks($pdo, $shared) === 0, 'Room another in-house guest holds stays occupied, no task');
    check(room_status($pdo, $ooo) === 'out_of_order' && open_tasks($pdo, $ooo) === 0, 'Out-of-order room stays out of order, no task');
    check(room_status($pdo, $mBlock) === 'maintenance', 'Room with a blocking maintenance task -> maintenance');
    check(strpos((string)col($pdo, "SELECT reason FROM room_maintenance_log WHERE individual_room_id = ? AND status_to = 'maintenance' ORDER BY id DESC LIMIT 1", [$mBlock]), 'Maintenance assignment active') === 0,
        'Maintenance status is schedule-driven (releasable by the maintenance reconcile)');
    check(room_status($pdo, $mOpen) === 'cleaning', 'Room whose maintenance task does not block it -> cleaning');
    check((int)col($pdo, "SELECT COUNT(*) FROM room_maintenance_log WHERE individual_room_id IN (?, ?) AND status_to = 'cleaning'", [$plain, $mOpen]) === 2, 'Every status change is logged');
    updateBookingRoomsStatus($guest, 'cleaning', 'Checkout completed again: 12a test', $adminId ?: null);
    check(open_tasks($pdo, $plain) === 1, 'Running checkout twice never makes a second task');

    echo "--- R2 check-in gate ---\n";
    $arr = mk_booking($pdo, $t, $day(0), $day(2), 'confirmed', [$ooo]);
    $g = evaluateCheckInRoomReady($arr, $adminId ?: null, true);
    check(empty($g['allowed']) && empty($g['needs_confirm']) && empty($g['can_override']), 'Override never admits into an out-of-order room', json_encode($g));
    $arr2 = mk_booking($pdo, $t, $day(0), $day(2), 'confirmed', [$shared]);
    $g2 = evaluateCheckInRoomReady($arr2, $adminId ?: null, true);
    check(empty($g2['allowed']), 'Override never admits into a room another guest is in', json_encode($g2));
    $dirty = mk_room($pdo, $t, 'D', 'cleaning');
    $arr3 = mk_booking($pdo, $t, $day(0), $day(2), 'confirmed', [$dirty]);
    $g3 = evaluateCheckInRoomReady($arr3, $adminId ?: null, false);
    check(empty($g3['allowed']) && (!empty($g3['needs_confirm']) || stripos((string)$g3['message'], 'permission') !== false), 'A dirty room is still overridable (or asks for the permission)', json_encode($g3));

    echo "--- R4 in-house room move ---\n";
    $mv = mk_room($pdo, $t, 'MV', 'occupied');
    $mvOoo = mk_room($pdo, $t, 'MX', 'out_of_order');
    rh_release_room_after_move($mv, $guest, 'Guest moved rooms (12a test)', $adminId ?: null);
    rh_release_room_after_move($mv, $guest, 'Guest moved rooms (12a test)', $adminId ?: null);
    check(room_status($pdo, $mv) === 'cleaning' && open_tasks($pdo, $mv) === 1, 'Room left by a move -> cleaning, one task even when called twice');
    rh_release_room_after_move($mvOoo, $guest, 'Guest moved rooms (12a test)', $adminId ?: null);
    check(room_status($pdo, $mvOoo) === 'out_of_order' && open_tasks($pdo, $mvOoo) === 0, 'Out-of-order room left by a move keeps its status, no task');

    echo "--- R3 joined pair needed ---\n";
    $D = 700;
    $member = mk_type($pdo, '12A TEST MEMBER', 3);
    $j1 = mk_room($pdo, $member, 'J1'); $j2 = mk_room($pdo, $member, 'J2'); $j3 = mk_room($pdo, $member, 'J3');
    $joined = mk_type($pdo, '12A TEST JOINED', 1);
    $pdo->prepare("INSERT INTO room_combinations (combined_name, combined_room_type_id, room_a_id, room_b_id, price_override, max_guests_combined, is_active) VALUES (?, ?, ?, ?, NULL, 4, 1)")
        ->execute(['12A TEST PAIR ' . $run, $joined, $j1, $j2]);
    $made['room_combinations'][] = (int)$pdo->lastInsertId();
    mk_booking($pdo, $joined, $day($D), $day($D + 2), 'confirmed');            // waits for the only pair
    $x = mk_booking($pdo, $member, $day($D), $day($D + 2), 'confirmed', [$j3]);
    check(rh_room_needed_for_joined($j1, $day($D), $day($D + 2), $x) !== null, 'Precondition: the waiting joined booking needs pair room J1');
    $pre = checkIndividualRoomAvailability($j1, $day($D), $day($D + 2), $x, false);
    check(!empty($pre['available']), 'Precondition: J1 is otherwise free (so a refusal below comes from the joined rule)', json_encode($pre['error'] ?? ''));
    $gate = rh_validate_booking_stay_change($x, $day($D), $day($D + 2), null, $j1);
    check(empty($gate['ok']) && stripos((string)$gate['error'], 'joined') !== false, 'Moving a booking into a pair room a joined booking needs is refused', json_encode($gate));
    $gateOwn = rh_validate_booking_stay_change($x, $day($D), $day($D + 2), null, null);
    check(!empty($gateOwn['ok']), 'Keeping its own room is still fine', json_encode($gateOwn));

    echo "--- R5 Individual Rooms status change (rolled-back harness) ---\n";
    $h1 = harness('admin/individual-rooms.php', ['action' => 'update_status', 'id' => (string)$shared, 'new_status' => 'available', 'reason' => '12a test'],
        ["SELECT status FROM individual_rooms WHERE id = $shared"]);
    check(($h1['verify'][0][0]['status'] ?? '') === 'occupied' && stripos(implode(' ', $h1['output_flash'] ?? []), 'checked in') !== false, 'Available refused while a guest is checked in', json_encode($h1['output_flash'] ?? $h1));
    $free = mk_room($pdo, $t, 'F', 'available');
    $h2 = harness('admin/individual-rooms.php', ['action' => 'update_status', 'id' => (string)$free, 'new_status' => 'occupied', 'reason' => '12a test'],
        ["SELECT status FROM individual_rooms WHERE id = $free"]);
    check(($h2['verify'][0][0]['status'] ?? '') === 'available', 'Occupied refused with nobody checked in', json_encode($h2['output_flash'] ?? $h2));
    $h3 = harness('admin/individual-rooms.php', ['action' => 'update_status', 'id' => (string)$free, 'new_status' => 'cleaning', 'reason' => '12a test'],
        ["SELECT status FROM individual_rooms WHERE id = $free", "SELECT COUNT(*) AS n FROM housekeeping_assignments WHERE individual_room_id = $free AND status IN ('pending','in_progress')",
         "SELECT COUNT(*) AS n FROM room_maintenance_log WHERE individual_room_id = $free AND status_to = 'cleaning'"]);
    check(($h3['verify'][0][0]['status'] ?? '') === 'cleaning' && (int)($h3['verify'][1][0]['n'] ?? 0) === 1 && (int)($h3['verify'][2][0]['n'] ?? 0) === 1,
        'Cleaning sets the status, logs it and creates one housekeeping task', json_encode($h3['verify'] ?? $h3));

    echo "--- R7 Create Checkout Cleanup (rolled-back harness) ---\n";
    $c1 = mk_room($pdo, $t, 'C1', 'cleaning'); $c2 = mk_room($pdo, $t, 'C2', 'cleaning'); $cMoved = mk_room($pdo, $t, 'C3', 'available');
    $co = mk_booking($pdo, $t, $day(-3), $day(0), 'checked-out', [$c1, $c2], date('Y-m-d H:i:s', strtotime('-1 hour')));
    $pdo->prepare("UPDATE bookings SET checkout_completed_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?")->execute([$co]);
    $pdo->prepare("INSERT INTO booking_rooms (booking_id, individual_room_id, room_combination_id, is_primary, assigned_at, status_snapshot, released_at) VALUES (?, ?, NULL, 0, NOW(), 'assigned', DATE_SUB(NOW(), INTERVAL 2 DAY))")
        ->execute([$co, $cMoved]); // a room the guest moved out of two days ago
    $h4 = harness('admin/housekeeping.php', ['action' => 'auto_create_checkout'],
        ["SELECT individual_room_id AS r, COUNT(*) AS n FROM housekeeping_assignments WHERE linked_booking_id = $co AND assignment_type = 'checkout_cleanup' GROUP BY individual_room_id"]);
    $byRoom = [];
    foreach ((array)($h4['verify'][0] ?? []) as $row) { $byRoom[(int)$row['r']] = (int)$row['n']; }
    check(($byRoom[$c1] ?? 0) === 1 && ($byRoom[$c2] ?? 0) === 1, 'Both rooms of a two-room checkout get one cleanup each', json_encode($h4['verify'] ?? $h4));
    check(!isset($byRoom[$cMoved]), 'A room released earlier by a room move gets none', json_encode($byRoom));
} catch (Throwable $e) {
    bad('Unexpected error', $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
}

echo "\n$pass passed, $fail failed.\n";
exit($fail > 0 ? 1 : 0);
