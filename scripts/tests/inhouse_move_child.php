<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/inhouse_move.php, run by page_harness.php (one outer transaction that
 * can never commit, rolled back). Everything is built from throw-away room types / rooms / bookings.
 *   f4 : in-house room-TYPE change: the old room goes to cleaning with an open task, only the new room is occupied.
 *   f5 : in-house move to another room of the SAME type: allowed, clean-room rule enforced, old room to housekeeping.
 *   f6 : the real public booking.php with a party that needs two rooms: the second room is linked to the first.
 */
$sc = (string)($_POST['scenario'] ?? '');
$out = ['scenario' => $sc];
require_once $root . '/includes/booking-room-change.php';
require_once $root . '/includes/booking-functions.php';
$adminRow = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$adminId = (int)$adminRow['id'];
$tag = strtoupper(bin2hex(random_bytes(3)));

$mkType = function (string $name, float $price) use ($pdo, $tag): int {
    $pdo->prepare("INSERT INTO rooms (name, description, short_description, price_per_night, max_guests, rooms_available, total_rooms, is_active, slug) VALUES (?, '', '', ?, 2, 3, 3, 1, ?)")
        ->execute(['ZZ TEST ' . $name . ' ' . $tag, $price, 'zz-test-' . strtolower($name) . '-' . strtolower($tag)]);
    return (int)$pdo->lastInsertId();
};
$mkRoom = function (int $typeId, string $number, string $status) use ($pdo): int {
    $pdo->prepare("INSERT INTO individual_rooms (room_type_id, room_number, room_name, floor, status, housekeeping_status, is_active) VALUES (?, ?, 'zz test', 1, ?, ?, 1)")
        ->execute([$typeId, $number, $status, $status === 'cleaning' ? 'pending' : 'completed']);
    return (int)$pdo->lastInsertId();
};
$mkBooking = function (int $typeId, int $roomId, string $status, string $in, string $out, string $ref) use ($pdo): int {
    $nights = max(1, (int)((strtotime($out) - strtotime($in)) / 86400));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,'ZZ INHOUSE TEST','',?,'Malawi',2,2,0,50,?,?,?,?,0,0,0,0,0,?,'inhouse move test',?, 'unpaid',0,'double',?,NOW())")
        ->execute([$ref, $typeId, '+265000000009', $in, $out, $nights, 100 * $nights, 100 * $nights, $status, bin2hex(random_bytes(16))]);
    $id = (int)$pdo->lastInsertId();
    if ($roomId > 0) {
        $pdo->prepare("UPDATE bookings SET individual_room_id = ? WHERE id = ?")->execute([$roomId, $id]);
        syncBookingRooms($id, [$roomId]);
    }
    return $id;
};
$roomState = function (int $roomId) use ($pdo): array {
    $s = $pdo->prepare("SELECT status, housekeeping_status FROM individual_rooms WHERE id = ?");
    $s->execute([$roomId]);
    $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    $t = $pdo->prepare("SELECT COUNT(*) FROM housekeeping_assignments WHERE individual_room_id = ? AND status IN ('pending','in_progress')");
    $t->execute([$roomId]);
    return ['status' => $r['status'] ?? null, 'hk' => $r['housekeeping_status'] ?? null, 'open_tasks' => (int)$t->fetchColumn()];
};
$held = function (int $bookingId) use ($pdo): array {
    $s = $pdo->prepare("SELECT individual_room_id FROM booking_rooms WHERE booking_id = ? AND released_at IS NULL ORDER BY individual_room_id");
    $s->execute([$bookingId]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
};
$bookingRow = function (int $bookingId) use ($pdo): array {
    $s = $pdo->prepare("SELECT individual_room_id, room_id, total_with_vat, status FROM bookings WHERE id = ?");
    $s->execute([$bookingId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
};
$yesterday = date('Y-m-d', strtotime('-1 day'));
$today = date('Y-m-d');
$plus2 = date('Y-m-d', strtotime('+2 day'));

if ($sc === 'f5') {
    $typeA = $mkType('A', 100);
    $a1 = $mkRoom($typeA, 'ZA1' . $tag, 'occupied');
    $a2 = $mkRoom($typeA, 'ZA2' . $tag, 'cleaning');   // dirty
    $a3 = $mkRoom($typeA, 'ZA3' . $tag, 'available');  // clean
    $bid = $mkBooking($typeA, $a1, 'checked-in', $yesterday, $plus2, 'ZI' . $tag . '5');
    // An earlier guest of the clean room, checked out inside this stay's past nights: history must not block the move.
    $mkBooking($typeA, $a3, 'checked-out', $yesterday, $today, 'ZI' . $tag . 'H');
    $before = $bookingRow($bid);

    $out['validate_in_house'] = validateRoomAssignment(['status' => 'checked-in'])['allowed'];
    $out['validate_confirmed'] = validateRoomAssignment(['status' => 'confirmed'])['allowed'];
    $out['validate_checked_out'] = validateRoomAssignment(['status' => 'checked-out'])['allowed'];
    $out['validate_cancelled'] = validateRoomAssignment(['status' => 'cancelled'])['allowed'];

    // Candidate list for the picker (clipped to tonight onward by admin/bookings.php; here the same call).
    $cand = rh_room_assignment_candidates($typeA, $today, $plus2, $bid, true);
    $byId = [];
    foreach ($cand['items'] as $it) { $byId[(int)$it['id']] = $it; }
    $out['cand_dirty_available'] = !empty($byId[$a2]['available']);
    $out['cand_clean_available'] = !empty($byId[$a3]['available']);

    // Dirty room: refused, nothing moved.
    $out['dirty_assigned'] = (bool)assignIndividualRoomToBooking($bid, $a2, false, null, $adminId);
    $out['dirty_error'] = rh_room_assign_last_error();
    $out['after_dirty'] = ['booking' => $bookingRow($bid), 'held' => $held($bid), 'a1' => $roomState($a1)];

    // Clean room: moved.
    $out['clean_assigned'] = (bool)assignIndividualRoomToBooking($bid, $a3, false, null, $adminId);
    $out['after_clean'] = ['booking' => $bookingRow($bid), 'held' => $held($bid), 'a1' => $roomState($a1), 'a3' => $roomState($a3)];
    $out['ids'] = ['a1' => $a1, 'a2' => $a2, 'a3' => $a3, 'before' => $before];

    // Departing today: nothing left to move.
    $typeD = $typeA;
    $d1 = $mkRoom($typeD, 'ZD1' . $tag, 'occupied');
    $d2 = $mkRoom($typeD, 'ZD2' . $tag, 'available');
    $bd = $mkBooking($typeD, $d1, 'checked-in', $yesterday, $today, 'ZI' . $tag . 'D');
    $out['departing_assigned'] = (bool)assignIndividualRoomToBooking($bd, $d2, false, null, $adminId);
    $out['departing_error'] = rh_room_assign_last_error();
    $out['departing_held'] = $held($bd) === [$d1];

    // The room the guest leaves is out of service: it keeps its status and gets no cleaning task.
    $e1 = $mkRoom($typeA, 'ZE1' . $tag, 'out_of_order');
    $e2 = $mkRoom($typeA, 'ZE2' . $tag, 'available');
    $be = $mkBooking($typeA, $e1, 'checked-in', $yesterday, $plus2, 'ZI' . $tag . 'E');
    $out['oos_assigned'] = (bool)assignIndividualRoomToBooking($be, $e2, false, null, $adminId);
    $out['oos_old'] = $roomState($e1);
    $out['oos_new'] = $roomState($e2);
}

if ($sc === 'f4') {
    $typeA = $mkType('A', 100);
    $typeB = $mkType('B', 150);
    $a1 = $mkRoom($typeA, 'ZA1' . $tag, 'occupied');
    $mkRoom($typeA, 'ZA2' . $tag, 'available');
    $b1 = $mkRoom($typeB, 'ZB1' . $tag, 'available');
    $b2 = $mkRoom($typeB, 'ZB2' . $tag, 'cleaning');
    $bid = $mkBooking($typeA, $a1, 'checked-in', $yesterday, $plus2, 'ZI' . $tag . '4');
    $res = rh_apply_room_type_change($pdo, $bid, $typeB, 'charge', 'upgrade_guest_request', '', ['id' => $adminId, 'full_name' => (string)$adminRow['full_name'], 'username' => (string)$adminRow['username']], true);
    $out['result'] = ['success' => !empty($res['success']), 'message' => (string)($res['message'] ?? '')];
    $out['booking'] = $bookingRow($bid);
    $out['held'] = $held($bid);
    $out['a1'] = $roomState($a1);
    $out['b1'] = $roomState($b1);
    $out['b2'] = $roomState($b2);
    $out['ids'] = ['a1' => $a1, 'b1' => $b1, 'b2' => $b2, 'type_b' => $typeB];
}

if ($sc === 'f6') {
    // The real public form handler. Two guests per room type, four guests -> two rooms. Nothing is emailed: the
    // harness points SMTP at a closed local port, and the whole run is rolled back.
    $typeA = (int)($_POST['room_type_id'] ?? 0);
    session_start();
    $_SESSION['_csrf_pub_booking'] = 'zz-test-token';
    $_SESSION['booking_attempts'] = [];
    $guests = (int)($_POST['guests'] ?? 4);
    $in = date('Y-m-d', strtotime('+75 day'));
    $outD = date('Y-m-d', strtotime('+77 day'));
    $_POST = [
        'csrf_token' => 'zz-test-token', 'room_id' => (string)$typeA, 'guest_name' => 'ZZ SPLIT TEST', 'guest_email' => 'zz-split-test@example.invalid',
        'guest_phone' => '+265999000111', 'guest_country' => 'Malawi', 'guest_address' => '', 'number_of_guests' => (string)$guests, 'child_guests' => '0',
        'check_in_date' => $in, 'check_out_date' => $outD, 'special_requests' => '', 'client_uuid' => bin2hex(random_bytes(16)),
    ];
    $_REQUEST = $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_USER_AGENT'] = 'zz-test';
    chdir($root);
    require $root . '/booking.php'; // exits after the redirect; the caller verifies by SQL
    return;
}

echo json_encode($out);
