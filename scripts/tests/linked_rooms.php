<?php
/**
 * Rooms linked-data test (.claude/plans/linked-data-cleanup.md, Rooms F1-F9).
 * Usage: php scripts/tests/linked_rooms.php
 *
 * Everything runs inside ONE transaction that is rolled back at the end - nothing is left in the live
 * database. If a rollback ever fails to remove the test booking the script says so and exits 1.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/booking-functions.php';
require_once __DIR__ . '/../../includes/booking-timeline.php';
require_once __DIR__ . '/../../includes/booking-soft-delete.php';
require_once __DIR__ . '/../../includes/room-management.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "[PASS] $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "[FAIL] $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function one(PDO $pdo, string $sql, array $p = []): ?array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

$run = strtoupper(bin2hex(random_bytes(3)));
$admin = one($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1");
$adminId = (int)($admin['id'] ?? 0);
$type = one($pdo, "SELECT r.id FROM rooms r WHERE EXISTS (SELECT 1 FROM individual_rooms ir WHERE ir.room_type_id = r.id) ORDER BY r.id LIMIT 1");
$ir = $type ? one($pdo, "SELECT id, room_number FROM individual_rooms WHERE room_type_id = ? ORDER BY id LIMIT 1", [$type['id']]) : null;
$menu = one($pdo, "SELECT id, name, menu_type, price FROM (SELECT id, item_name AS name, 'food' AS menu_type, price FROM menu_items) m ORDER BY id LIMIT 1");
if (!$adminId || !$type || !$ir) { bad('Fixture: an admin, a room type and a room exist'); echo "\n0 passed, 1 failed\n"; exit(1); }

$seq = 0;
function mk_booking(PDO $pdo, string $run, int $typeId, int $irId, string $status, string $in, string $out, string $tag): array {
    global $seq;
    $seq++;
    $ref = 'LR' . $run . $seq; // <= 20 chars
    $nights = max(1, (int)((strtotime($out) - strtotime($in)) / 86400));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,?,?,?,?,2,2,0,50,?,?,?,100,0,0,0,0,0,100,?,?, 'unpaid',0,'double',?,NOW())")
        ->execute([$ref, $typeId, 'LINKED TEST ' . $tag, 'linked-test@example.invalid', '+265000000009', 'Malawi', $in, $out, $nights, 'linked rooms test', $status, bin2hex(random_bytes(16))]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE bookings SET individual_room_id = ? WHERE id = ?")->execute([$irId, $id]);
    syncBookingRooms($id, [$irId]);
    $pdo->prepare("INSERT INTO quotations (booking_id, booking_type, quote_reference, booking_reference, guest_name, guest_email, room_name, total_amount, valid_days, valid_until, status, sent_at, sent_by)
        VALUES (?, 'room', ?, ?, 'LINKED TEST', 'linked-test@example.invalid', 'Test', 100, 7, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'sent', NOW(), 0)")
        ->execute([$id, 'QLR' . $run . $seq, $ref]);
    return [$id, $ref];
}
function mk_order(PDO $pdo, string $run, int $bookingId, string $lineStatus, ?array $menu): int {
    global $seq, $adminId;
    $seq++;
    $oref = 'ORLR' . $run . $seq;
    $pdo->prepare("INSERT INTO stock_orders (reference, order_type, customer_name, status, total_amount, subtotal, kitchen_status, created_by, booking_id, created_at)
        VALUES (?, 'room_service', 'LINKED TEST', 'placed', 50, 50, 'new', ?, ?, NOW())")->execute([$oref, $adminId, $bookingId]);
    $oid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status)
        VALUES (?, ?, ?, ?, 1, 50, 50, 'kitchen', ?)")->execute([$oid, $menu['id'] ?? 0, $menu['menu_type'] ?? 'food', $menu['name'] ?? 'Test item', $lineStatus]);
    return $oid;
}

$leakRef = '';
try {
    $pdo->beginTransaction();
    $future = date('Y-m-d', strtotime('+400 day')); $future2 = date('Y-m-d', strtotime('+401 day'));

    echo "\n--- F1/F2/F3: cancel closes the order, the room hold and the quotation ---\n";
    [$b1, $ref1] = mk_booking($pdo, $run, (int)$type['id'], (int)$ir['id'], 'confirmed', $future, $future2, 'cancel');
    $leakRef = $ref1;
    $o1 = mk_order($pdo, $run, $b1, 'pending', $menu);
    $r = cancelRoomBookingSettled($pdo, $b1, $adminId, 'linked rooms test');
    check(!empty($r['success']), 'Cancel succeeds', json_encode($r));
    check(col($pdo, "SELECT status FROM stock_orders WHERE id = ?", [$o1]) === 'cancelled', 'Unpaid, unstarted order is cancelled');
    check((int)col($pdo, "SELECT COUNT(*) FROM booking_rooms WHERE booking_id = ? AND released_at IS NULL", [$b1]) === 0, 'booking_rooms released');
    check(col($pdo, "SELECT individual_room_id FROM bookings WHERE id = ?", [$b1]) === null, 'Primary room pointer cleared');
    check(col($pdo, "SELECT status FROM quotations WHERE booking_id = ?", [$b1]) === 'expired', "Quotation 'sent' -> 'expired'");
    check((int)col($pdo, "SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_id = ? AND action = 'Room hold released' AND description LIKE ?", [$b1, '%' . $ir['room_number'] . '%']) === 1, 'Timeline names the released room');

    echo "\n--- F1: a started order refuses the cancel and nothing changes ---\n";
    [$b2] = mk_booking($pdo, $run, (int)$type['id'], (int)$ir['id'], 'confirmed', $future, $future2, 'started');
    $o2 = mk_order($pdo, $run, $b2, 'preparing', $menu);
    $r = cancelRoomBookingSettled($pdo, $b2, $adminId, 'linked rooms test');
    check(empty($r['success']) && stripos((string)$r['error'], 'kitchen already started') !== false, 'Cancel refused with a plain message', (string)($r['error'] ?? ''));
    check(col($pdo, "SELECT status FROM bookings WHERE id = ?", [$b2]) === 'confirmed', 'Booking still confirmed');
    check(col($pdo, "SELECT status FROM stock_orders WHERE id = ?", [$o2]) === 'placed', 'Order untouched');
    check((int)col($pdo, "SELECT COUNT(*) FROM booking_rooms WHERE booking_id = ? AND released_at IS NULL", [$b2]) === 1, 'Room hold untouched');
    check(col($pdo, "SELECT status FROM quotations WHERE booking_id = ?", [$b2]) === 'sent', 'Quotation untouched');
    check(rh_booking_open_order_ref($pdo, $b2) !== null, 'Open order is found (undo check-in is blocked on it)');

    echo "\n--- F5: one no-show function ---\n";
    $past = date('Y-m-d', strtotime('-5 day')); $past2 = date('Y-m-d', strtotime('-4 day'));
    [$b3] = mk_booking($pdo, $run, (int)$type['id'], (int)$ir['id'], 'confirmed', $past, $past2, 'noshow');
    $o3 = mk_order($pdo, $run, $b3, 'pending', $menu);
    $ns = rh_mark_booking_no_show($pdo, $b3, $adminId, 'Test', 'linked test');
    check(!empty($ns['success']), 'No-show succeeds', (string)($ns['error'] ?? ''));
    check(col($pdo, "SELECT status FROM bookings WHERE id = ?", [$b3]) === 'no-show', 'Status is no-show');
    check(col($pdo, "SELECT status FROM stock_orders WHERE id = ?", [$o3]) === 'cancelled', 'No-show cancels the unstarted order');
    check((int)col($pdo, "SELECT COUNT(*) FROM booking_rooms WHERE booking_id = ? AND released_at IS NULL", [$b3]) === 0, 'No-show releases the room');
    check(col($pdo, "SELECT status FROM quotations WHERE booking_id = ?", [$b3]) === 'expired', 'No-show expires the quotation');
    $again = rh_mark_booking_no_show($pdo, $b3, $adminId, 'Test', '');
    check(empty($again['success']), 'A second no-show is refused', (string)($again['error'] ?? ''));

    [$b4] = mk_booking($pdo, $run, (int)$type['id'], (int)$ir['id'], 'confirmed', $past, $past2, 'noshow2');
    $o4 = mk_order($pdo, $run, $b4, 'ready', $menu);
    $ns = rh_mark_booking_no_show($pdo, $b4, $adminId, 'Test', '');
    check(empty($ns['success']) && col($pdo, "SELECT status FROM bookings WHERE id = ?", [$b4]) === 'confirmed', 'No-show with a started order is refused, booking unchanged', (string)($ns['error'] ?? ''));
    $early = rh_mark_booking_no_show($pdo, (int)col($pdo, "SELECT id FROM bookings WHERE id = ?", [$b2]), $adminId, 'Test', '');
    check(empty($early['success']), 'No-show before the check-in date has passed is refused', (string)($early['error'] ?? ''));

    echo "\n--- F1: soft delete closes the order too ---\n";
    [$b5] = mk_booking($pdo, $run, (int)$type['id'], (int)$ir['id'], 'pending', $future, $future2, 'delete');
    $o5 = mk_order($pdo, $run, $b5, 'pending', $menu);
    $d = rh_soft_delete_booking($pdo, $b5, $adminId, 'Test', 'linked rooms test delete');
    check(!empty($d['success']), 'Delete succeeds', (string)($d['message'] ?? ''));
    check(col($pdo, "SELECT status FROM stock_orders WHERE id = ?", [$o5]) === 'cancelled', 'Delete cancels the unstarted order');
    check((int)col($pdo, "SELECT COUNT(*) FROM booking_rooms WHERE booking_id = ? AND released_at IS NULL", [$b5]) === 0, 'Delete releases the room');
    [$b6] = mk_booking($pdo, $run, (int)$type['id'], (int)$ir['id'], 'pending', $future, $future2, 'delete2');
    mk_order($pdo, $run, $b6, 'preparing', $menu);
    $d = rh_soft_delete_booking($pdo, $b6, $adminId, 'Test', 'linked rooms test delete');
    check(empty($d['success']) && col($pdo, "SELECT deleted_at FROM bookings WHERE id = ?", [$b6]) === null, 'Delete with a started order is refused, booking kept', (string)($d['message'] ?? ''));

    echo "\n--- F4: expire-now money guard ---\n";
    [$b7] = mk_booking($pdo, $run, (int)$type['id'], (int)$ir['id'], 'pending', $future, $future2, 'expire');
    check((int)col($pdo, "SELECT COUNT(*) FROM bookings b WHERE b.id = ? AND " . rh_booking_has_payment_sql('b'), [$b7]) === 0, 'Unpaid booking has no payment (expire allowed)');
    $pdo->prepare("UPDATE bookings SET deposit_paid = 1 WHERE id = ?")->execute([$b7]);
    check((int)col($pdo, "SELECT COUNT(*) FROM bookings b WHERE b.id = ? AND " . rh_booking_has_payment_sql('b'), [$b7]) === 1, 'Booking with money on it is detected (expire blocked)');

    echo "\n--- F8: checkout closes folio-charged room service + holds ---\n";
    $ci = date('Y-m-d', strtotime('-1 day')); $co = date('Y-m-d');
    [$b8] = mk_booking($pdo, $run, (int)$type['id'], (int)$ir['id'], 'checked-in', $ci, $co, 'checkout');
    $pdo->prepare("UPDATE bookings SET total_amount = 0, total_with_vat = 0, amount_due = 0, payment_status = 'paid' WHERE id = ?")->execute([$b8]);
    $o8 = mk_order($pdo, $run, $b8, 'served', $menu);
    $pdo->prepare("UPDATE stock_orders SET folio_posted_at = NOW() WHERE id = ?")->execute([$o8]);
    $co8 = rh_checkout_with_gate($pdo, $b8, $adminId, true, 'Test');
    if (check(!empty($co8['success']), 'Checkout goes through', (string)($co8['message'] ?? ''))) {
        check(col($pdo, "SELECT status FROM stock_orders WHERE id = ?", [$o8]) === 'completed', 'Folio-charged room-service order is closed at checkout');
        check((int)col($pdo, "SELECT COUNT(*) FROM booking_rooms WHERE booking_id = ? AND released_at IS NULL", [$b8]) === 0, 'Checkout releases booking_rooms');
    }

    echo "\n--- F6: individual room delete ---\n";
    $mkRoom = function (string $suffix) use ($pdo, $type): int {
        $pdo->prepare("INSERT INTO individual_rooms (room_type_id, room_number, room_name, floor, status) VALUES (?, ?, 'Linked test', 1, 'available')")
            ->execute([$type['id'], 'LT' . $suffix]);
        return (int)$pdo->lastInsertId();
    };
    $liveRoom = $mkRoom('A' . $run);
    [$b9] = mk_booking($pdo, $run, (int)$type['id'], $liveRoom, 'confirmed', $future, $future2, 'roomlive');
    $x = rh_delete_individual_room($pdo, $liveRoom, $adminId);
    check(!$x['success'] && stripos($x['error'], 'live booking') !== false, 'Delete blocked by a live booking', $x['error']);
    check((int)col($pdo, "SELECT COUNT(*) FROM individual_rooms WHERE id = ?", [$liveRoom]) === 1, 'Room still there');

    $histRoom = $mkRoom('B' . $run);
    [$b10] = mk_booking($pdo, $run, (int)$type['id'], $histRoom, 'checked-out', $past, $past2, 'roomhist');
    $x = rh_delete_individual_room($pdo, $histRoom, $adminId);
    check($x['success'] && $x['mode'] === 'deactivated', 'Room with history is deactivated, not deleted', json_encode($x));
    check((int)col($pdo, "SELECT is_active FROM individual_rooms WHERE id = ?", [$histRoom]) === 0, 'Room is inactive');

    $cleanRoom = $mkRoom('C' . $run);
    $pdo->prepare("INSERT INTO individual_room_amenities (individual_room_id, amenity_key, amenity_label, display_order) VALUES (?, 'wifi', 'WiFi', 0)")->execute([$cleanRoom]);
    $pdo->prepare("INSERT INTO individual_room_photos (individual_room_id, image_path, is_primary, display_order, is_active) VALUES (?, 'https://example.invalid/x.jpg', 1, 0, 1)")->execute([$cleanRoom]);
    $pdo->prepare("INSERT INTO housekeeping_assignments (individual_room_id, status, notes) VALUES (?, 'pending', 'linked test')")->execute([$cleanRoom]);
    $x = rh_delete_individual_room($pdo, $cleanRoom, $adminId);
    check($x['success'] && $x['mode'] === 'deleted', 'Room without history is deleted', json_encode($x));
    foreach (['individual_rooms' => 'id', 'individual_room_amenities' => 'individual_room_id', 'individual_room_photos' => 'individual_room_id', 'housekeeping_assignments' => 'individual_room_id'] as $t => $c) {
        check((int)col($pdo, "SELECT COUNT(*) FROM $t WHERE $c = ?", [$cleanRoom]) === 0, "$t rows gone with the room");
    }

    echo "\n--- F7: room type delete ---\n";
    $x = rh_delete_room_type($pdo, (int)$type['id']);
    check(!$x['success'] && stripos($x['error'], 'individual rooms') !== false, 'Type with individual rooms is blocked', $x['error']);
    $pdo->prepare("INSERT INTO rooms (name, description, short_description, price_per_night, max_guests, rooms_available, total_rooms, is_active, slug) VALUES (?, '', '', 1, 2, 0, 0, 0, ?)")
        ->execute(['LINKED TEST TYPE ' . $run, 'linked-test-type-' . strtolower($run)]);
    $tid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO blocked_dates (room_id, block_date) VALUES (?, ?)")->execute([$tid, $future]);
    $pdo->prepare("INSERT INTO gallery (room_id, title, category, image_url) VALUES (?, 'Linked test', 'rooms', 'https://example.invalid/g.jpg')")->execute([$tid]);
    $x = rh_delete_room_type($pdo, $tid);
    check($x['success'], 'Empty room type is deleted', json_encode($x));
    check((int)col($pdo, "SELECT COUNT(*) FROM gallery WHERE room_id = ?", [$tid]) === 0 && (int)col($pdo, "SELECT COUNT(*) FROM blocked_dates WHERE room_id = ?", [$tid]) === 0, 'Its gallery and blocked dates went with it');
} catch (Throwable $e) {
    bad('Test run crashed', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
if ($pdo->inTransaction()) { $pdo->rollBack(); }

// Leak guard: the rollback must have removed everything.
if ($leakRef !== '' && (int)col($pdo, "SELECT COUNT(*) FROM bookings WHERE booking_reference = ?", [$leakRef]) > 0) {
    bad('HARNESS LEAK - test booking survived the rollback', $leakRef);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
