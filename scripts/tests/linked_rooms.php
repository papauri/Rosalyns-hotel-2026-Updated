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

function mk_order_line(PDO $pdo, int $orderId, string $lineStatus, ?array $menu): int {
    $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status)
        VALUES (?, ?, ?, ?, 1, 50, 50, 'kitchen', ?)")->execute([$orderId, $menu['id'] ?? 0, $menu['menu_type'] ?? 'food', $menu['name'] ?? 'Test item', $lineStatus]);
    $lineId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE stock_orders SET total_amount = total_amount + 50, subtotal = subtotal + 50 WHERE id = ?")->execute([$orderId]);
    return $lineId;
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

    echo "\n--- F1 (owner 2026-10-20): a started order stays billable; cancel goes through ---\n";
    [$b2, $ref2] = mk_booking($pdo, $run, (int)$type['id'], (int)$ir['id'], 'confirmed', $future, $future2, 'started');
    $o2 = mk_order($pdo, $run, $b2, 'preparing', $menu);
    $o2b = mk_order_line($pdo, $o2, 'pending', $menu); // second line, never started
    $paid2 = 500.0;
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, recorded_by, created_at)
        VALUES (?, 'room', ?, ?, CURDATE(), ?, 0, 0, ?, 'cash', 'full_payment', 'completed', 'completed', ?, NOW())")
        ->execute(['PLR' . $run, $b2, $ref2, $paid2, $paid2, $adminId]);
    recalculateBookingFinancials($b2);
    $r = cancelRoomBookingSettled($pdo, $b2, $adminId, 'linked rooms test');
    check(!empty($r['success']), 'Cancel is NOT refused by a started order', (string)($r['error'] ?? ''));
    check(col($pdo, "SELECT status FROM bookings WHERE id = ?", [$b2]) === 'cancelled', 'Booking is cancelled');
    check(col($pdo, "SELECT status FROM stock_orders WHERE id = ?", [$o2]) === 'completed', 'Order is closed (completed), no tab left open');
    check(col($pdo, "SELECT folio_posted_at FROM stock_orders WHERE id = ?", [$o2]) !== null, 'Order is marked posted to the folio');
    check(col($pdo, "SELECT kds_status FROM stock_order_items WHERE order_id = ? ORDER BY id LIMIT 1", [$o2]) === 'preparing', 'Started line left alone for the kitchen');
    check(col($pdo, "SELECT kds_status FROM stock_order_items WHERE id = ?", [$o2b]) === 'void', 'Never-started line is cancelled');
    check((int)col($pdo, "SELECT COUNT(*) FROM booking_charges WHERE booking_id = ? AND stock_order_id = ? AND voided = 0", [$b2, $o2]) === 1, 'Exactly the started line is on the booking folio');
    $folio2 = round((float)col($pdo, "SELECT COALESCE(SUM(line_total), 0) FROM booking_charges WHERE booking_id = ? AND voided = 0", [$b2]), 2);
    check(abs($folio2 - 50.0) < 0.01, 'Folio carries the started line (50.00)', (string)$folio2);
    check(abs((float)col($pdo, "SELECT folio_charges_total FROM bookings WHERE id = ?", [$b2]) - $folio2) < 0.01, 'bookings.folio_charges_total matches the kept charge');
    check(abs((float)col($pdo, "SELECT total_amount FROM stock_orders WHERE id = ?", [$o2]) - 50.0) < 0.01, 'Order total shrinks to the kept line');
    $expectRefund = ($r['mode'] === 'void_keep_credit') ? 0.0 : max(0.0, round($paid2 - (float)$r['retained'] - $folio2, 2));
    check(abs((float)$r['refund_total'] - $expectRefund) < 0.01, 'Refund = paid - retained - kept room service', 'got ' . $r['refund_total'] . ' expected ' . $expectRefund . ' (mode ' . $r['mode'] . ')');
    check(abs((float)$r['refund_total'] + (float)$r['credit_balance'] - round($paid2 - (float)$r['retained'] - $folio2, 2)) < 0.01, 'Refund + credit owed back = paid - retained - kept room service');
    check((int)col($pdo, "SELECT COUNT(*) FROM booking_rooms WHERE booking_id = ? AND released_at IS NULL", [$b2]) === 0, 'Room hold released');
    check(col($pdo, "SELECT status FROM quotations WHERE booking_id = ?", [$b2]) === 'expired', 'Quotation expired');
    check(rh_booking_open_order_ref($pdo, $b2) === null, 'No open order is left on the booking');
    check(stripos((string)$r['summary'], 'stays charged') !== false, 'Summary tells staff the started food stays charged');

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
    check(!empty($ns['success']) && col($pdo, "SELECT status FROM bookings WHERE id = ?", [$b4]) === 'no-show', 'No-show with a started order goes through (owner 2026-10-20)', (string)($ns['error'] ?? ''));
    check(col($pdo, "SELECT status FROM stock_orders WHERE id = ?", [$o4]) === 'completed', 'No-show closes the started order (no tab left open)');
    check((int)col($pdo, "SELECT COUNT(*) FROM booking_charges WHERE booking_id = ? AND stock_order_id = ? AND voided = 0", [$b4, $o4]) === 1, 'No-show keeps the started line on the folio');
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
    $o6 = mk_order($pdo, $run, $b6, 'preparing', $menu);
    $d = rh_soft_delete_booking($pdo, $b6, $adminId, 'Test', 'linked rooms test delete');
    check(!empty($d['success']) && col($pdo, "SELECT deleted_at FROM bookings WHERE id = ?", [$b6]) !== null, 'Delete with a started order goes through (owner 2026-10-20)', (string)($d['message'] ?? ''));
    check(col($pdo, "SELECT status FROM stock_orders WHERE id = ?", [$o6]) === 'completed', 'Delete closes the started order');
    check((int)col($pdo, "SELECT COUNT(*) FROM booking_charges WHERE booking_id = ? AND stock_order_id = ? AND voided = 0", [$b6, $o6]) === 1, 'Delete keeps the started line on the folio');
    check(stripos((string)($d['message'] ?? ''), 'stays charged') !== false, 'Delete message tells staff the started food stays charged');

    echo "\n--- Part-paid order keeps the refusal (no automatic refunds) ---\n";
    [$b6b] = mk_booking($pdo, $run, (int)$type['id'], (int)$ir['id'], 'confirmed', $future, $future2, 'partpaid');
    $o6b = mk_order($pdo, $run, $b6b, 'preparing', $menu);
    $pdo->prepare("UPDATE stock_orders SET split_paid_count = 1 WHERE id = ?")->execute([$o6b]);
    $pp = cancelRoomBookingSettled($pdo, $b6b, $adminId, 'linked rooms test');
    check(empty($pp['success']) && stripos((string)$pp['error'], 'part-paid') !== false && col($pdo, "SELECT status FROM bookings WHERE id = ?", [$b6b]) === 'confirmed', 'Cancel with a part-paid order is still refused, nothing changed', (string)($pp['error'] ?? ''));

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

    echo "\n--- Group bookings: cancel / delete whole group or one room (owner 2026-10-20) ---\n";
    $grpRoomIds = $pdo->prepare("SELECT id FROM individual_rooms WHERE room_type_id = ? ORDER BY id LIMIT 3");
    $grpRoomIds->execute([$type['id']]);
    $grpRooms = array_map('intval', $grpRoomIds->fetchAll(PDO::FETCH_COLUMN));
    $mkGroup = function (string $tag, string $status = 'confirmed') use ($pdo, $run, $type, $grpRooms, $future, $future2): array {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            [$gid] = mk_booking($pdo, $run, (int)$type['id'], $grpRooms[$i % count($grpRooms)], $status, $future, $future2, $tag . $i);
            $ids[] = $gid;
        }
        // Lead has primary_booking_id NULL; each secondary points at the lead (admin/create-booking.php).
        $pdo->prepare("UPDATE bookings SET primary_booking_id = ? WHERE id IN (?, ?)")->execute([$ids[0], $ids[1], $ids[2]]);
        return $ids;
    };
    $holds = fn (int $id): int => (int)col($pdo, "SELECT COUNT(*) FROM booking_rooms WHERE booking_id = ? AND released_at IS NULL", [$id]);
    $stat = fn (int $id): string => (string)col($pdo, "SELECT status FROM bookings WHERE id = ?", [$id]);

    // Helper: any member finds the same live group, asked-about booking first.
    [$g1, $g2, $g3] = $mkGroup('gc');
    check(rh_booking_group_ids($pdo, $g2) === [$g2, $g1, $g3], 'Group lookup from a secondary: itself first, then lead and the other room', json_encode(rh_booking_group_ids($pdo, $g2)));
    check(rh_booking_group_ids($pdo, $g1) === [$g1, $g2, $g3], 'Group lookup from the lead returns all three');
    [$solo] = mk_booking($pdo, $run, (int)$type['id'], (int)$ir['id'], 'confirmed', $future, $future2, 'solo');
    check(rh_booking_group_ids($pdo, $solo) === [$solo], 'A booking that is not in a group returns just itself');

    // Whole group cancel: all 3 cancelled in one go, every hold released.
    $gr = rh_cancel_booking_group($pdo, rh_booking_group_ids($pdo, $g2), $adminId, 'linked rooms group test');
    check(!empty($gr['success']) && count($gr['cancelled']) === 3, 'Group cancel succeeds for 3 rooms', json_encode($gr));
    check($stat($g1) === 'cancelled' && $stat($g2) === 'cancelled' && $stat($g3) === 'cancelled', 'All 3 bookings are cancelled');
    check($holds($g1) + $holds($g2) + $holds($g3) === 0, 'All 3 room holds released');
    check(col($pdo, "SELECT COUNT(*) FROM bookings WHERE id IN ($g1,$g2,$g3) AND individual_room_id IS NOT NULL") == 0, 'All 3 primary room pointers cleared');
    check(strpos((string)$gr['message'], (string)col($pdo, "SELECT booking_reference FROM bookings WHERE id = ?", [$g1])) !== false
        && strpos((string)$gr['message'], (string)col($pdo, "SELECT booking_reference FROM bookings WHERE id = ?", [$g3])) !== false, 'The message names each booking');
    check(rh_booking_group_ids($pdo, $g2) === [], 'A cancelled group no longer offers a group (no live members)');

    // Single cancel of a secondary: the other two stay confirmed with their rooms.
    [$s1, $s2, $s3] = $mkGroup('gs');
    $r = cancelRoomBookingSettled($pdo, $s2, $adminId, 'linked rooms single test');
    check(!empty($r['success']) && $stat($s2) === 'cancelled', 'Single cancel cancels only that room');
    check($stat($s1) === 'confirmed' && $stat($s3) === 'confirmed' && $holds($s1) === 1 && $holds($s3) === 1, 'The other two rooms stay confirmed and keep their holds');
    check(rh_booking_group_ids($pdo, $s3) === [$s3, $s1], 'Group shrinks to the two live rooms', json_encode(rh_booking_group_ids($pdo, $s3)));

    // Cancelling the lead alone: the secondaries keep working as a group (the pointer still names the lead).
    [$l1, $l2, $l3] = $mkGroup('gl');
    $r = cancelRoomBookingSettled($pdo, $l1, $adminId, 'linked rooms lead test');
    check(!empty($r['success']) && $stat($l1) === 'cancelled' && $stat($l2) === 'confirmed' && $stat($l3) === 'confirmed', 'Cancelling the lead leaves both secondaries confirmed');
    check(rh_booking_group_ids($pdo, $l2) === [$l2, $l3], 'Secondaries still find each other after the lead is cancelled', json_encode(rh_booking_group_ids($pdo, $l2)));
    check((int)col($pdo, "SELECT COUNT(*) FROM bookings WHERE id IN (?, ?) AND primary_booking_id = ?", [$l2, $l3, $l1]) === 2, 'Secondaries still point at their lead (booking-details group display keeps working)');
    $sib = $pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE (b.id = :pid OR b.primary_booking_id = :pid2) AND b.id != :cur");
    $sib->execute([':pid' => $l1, ':pid2' => $l1, ':cur' => $l2]);
    check((int)$sib->fetchColumn() === 2, 'booking-details sibling query still lists the cancelled lead and the other room');

    // One member refuses (a part-paid placed order): the whole group is rolled back, nothing changes.
    [$p1, $p2, $p3] = $mkGroup('gp');
    $po = mk_order($pdo, $run, $p3, 'pending', $menu);
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount, payment_method, payment_status, created_at)
        VALUES (?, 'restaurant', ?, ?, CURDATE(), 20, 0, 0, 20, 'cash', 'completed', NOW())")->execute(['PLR' . $run . $po, $po, 'ORLR' . $run]);
    $gr = rh_cancel_booking_group($pdo, [$p1, $p2, $p3], $adminId, 'linked rooms group refusal');
    check(empty($gr['success']) && stripos((string)$gr['error'], 'part-paid') !== false, 'Group cancel is refused when one member has a part-paid order', (string)($gr['error'] ?? ''));
    check(strpos((string)$gr['error'], (string)col($pdo, "SELECT booking_reference FROM bookings WHERE id = ?", [$p3])) !== false, 'The refusal names the booking that blocked it');
    check($stat($p1) === 'confirmed' && $stat($p2) === 'confirmed' && $stat($p3) === 'confirmed', 'Nothing was cancelled (the members processed before the refusal were rolled back)');
    check($holds($p1) === 1 && $holds($p2) === 1 && $holds($p3) === 1, 'All three room holds are untouched');
    check(col($pdo, "SELECT status FROM quotations WHERE booking_id = ?", [$p1]) === 'sent', 'Quotation of the first member is untouched');
    check(col($pdo, "SELECT status FROM stock_orders WHERE id = ?", [$po]) === 'placed', 'The part-paid order is untouched');

    // Same for delete: a refusal on one member deletes none of them.
    $gd = rh_soft_delete_booking_group($pdo, [$p1, $p2, $p3], $adminId, 'Test', 'linked rooms group delete refusal');
    check(empty($gd['success']) && $stat($p1) === 'confirmed' && $stat($p2) === 'confirmed'
        && col($pdo, "SELECT deleted_at FROM bookings WHERE id = ?", [$p1]) === null && col($pdo, "SELECT deleted_at FROM bookings WHERE id = ?", [$p2]) === null,
        'Group delete refused on one member: none deleted', (string)($gd['message'] ?? ''));
    check($holds($p1) === 1 && $holds($p2) === 1, 'Group delete refusal leaves the room holds in place');

    // Whole group delete and delete of one room.
    [$d1, $d2, $d3] = $mkGroup('gd', 'pending');
    $gd = rh_soft_delete_booking_group($pdo, rh_booking_group_ids($pdo, $d3), $adminId, 'Test', 'linked rooms group delete');
    check(!empty($gd['success']) && count($gd['deleted']) === 3, 'Group delete succeeds for 3 rooms', json_encode($gd));
    check((int)col($pdo, "SELECT COUNT(*) FROM bookings WHERE id IN ($d1,$d2,$d3) AND deleted_at IS NOT NULL AND status = 'cancelled'") === 3, 'All 3 are marked deleted');
    check($holds($d1) + $holds($d2) + $holds($d3) === 0, 'All 3 room holds released by the group delete');
    [$e1, $e2, $e3] = $mkGroup('ge', 'pending');
    $d = rh_soft_delete_booking($pdo, $e2, $adminId, 'Test', 'linked rooms single delete');
    check(!empty($d['success']) && col($pdo, "SELECT deleted_at FROM bookings WHERE id = ?", [$e1]) === null && col($pdo, "SELECT deleted_at FROM bookings WHERE id = ?", [$e3]) === null
        && $holds($e1) === 1 && $holds($e3) === 1, 'Single delete leaves the other two rooms alone');
    check(rh_booking_group_ids($pdo, $e1) === [$e1, $e3], 'A deleted room drops out of the group');

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
