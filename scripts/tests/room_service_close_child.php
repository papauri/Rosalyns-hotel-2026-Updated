<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/linked_rooms.php ("room service closes when served"), run by
 * page_harness.php (one outer transaction that can never commit, rolled back). Builds a checked-in
 * booking plus a folio-charged room-service order, then runs the REAL endpoint:
 *   serve   : api/kds-action.php serve_item on the last open line
 *   bump    : api/kds-action.php bump_ticket
 *   recall  : api/kds-action.php recall_ticket on an order that was just closed
 *   deliver : admin/room-service-dashboard.php mark_delivered on a served-but-open order
 * The caller verifies by order reference (cfg verify SQL), so nothing needs to survive the run.
 */
$sc = (string)($_POST['scenario'] ?? '');
$ref = (string)($_POST['ref'] ?? '');
$room = 'ZZ' . substr($ref, -6);
$admin = $pdo->query("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$bid = random_int(2000000000, 2100000000);
$pdo->prepare("INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
        child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
        vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
    VALUES (?,?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'RS CLOSE TEST','','+265000000009','Malawi',2,2,0,50,CURDATE(),DATE_ADD(CURDATE(), INTERVAL 1 DAY),1,100,0,0,0,0,0,100,'rs close test','checked-in','unpaid',0,'double',?,NOW())")
    ->execute([$bid, 'RB' . substr($ref, -8), bin2hex(random_bytes(16))]);

$orderStatus = $sc === 'recall' ? 'completed' : 'placed';
$kitchen = ($sc === 'deliver') ? 'served' : ($sc === 'recall' ? 'served' : 'ready');
$pdo->prepare("INSERT INTO stock_orders (reference, order_type, table_number, room_number, customer_name, status, total_amount, subtotal, kitchen_status, fired_at, served_at,
        created_by, booking_id, folio_posted_at, created_at)
    VALUES (?, 'room_service', ?, ?, 'RS CLOSE TEST', ?, 100, 100, ?, NOW(), " . ($kitchen === 'served' ? 'NOW()' : 'NULL') . ", ?, ?, NOW(), NOW())")
    ->execute([$ref, $room, $room, $orderStatus, $kitchen, (int)$admin['id'], $bid]);
$oid = (int)$pdo->lastInsertId();
$line = function (string $station, string $status) use ($pdo, $oid): int {
    $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status, stock_deducted)
        VALUES (?, 0, 'food', 'RS close test item', 1, 50, 50, ?, ?, " . ($status === 'served' ? '0' : '1') . ")")->execute([$oid, $station, $status]);
    return (int)$pdo->lastInsertId();
};
$first = $line('kitchen', $sc === 'bump' ? 'ready' : 'served');
$last = $line('bar', ($sc === 'deliver' || $sc === 'recall') ? 'served' : 'ready');

if ($sc === 'deliver') {
    $_POST = ['action' => 'mark_delivered', 'order_id' => (string)$oid, 'csrf_token' => $_SESSION['csrf_token'] ?? ''];
    $_REQUEST = $_POST;
    chdir($root . '/admin');
    require $root . '/admin/room-service-dashboard.php';
    return;
}

// KDS endpoint: it reads $_SESSION['admin_user'] - write it into the harness session file first.
session_start();
$_SESSION['admin_user'] = ['id' => (int)$admin['id'], 'username' => $admin['username'], 'full_name' => $admin['full_name'], 'role' => $admin['role']];
$csrf = $_SESSION['csrf_token'];
session_write_close();
$_POST = ['csrf_token' => $csrf] + match ($sc) {
    'serve'  => ['action' => 'serve_item', 'item_id' => (string)$last, 'station' => 'bar'],
    'bump'   => ['action' => 'bump_ticket', 'order_id' => (string)$oid],
    'recall' => ['action' => 'recall_ticket', 'order_id' => (string)$oid],
    default  => ['action' => 'none'],
};
$_REQUEST = $_POST;
$_SERVER['REQUEST_METHOD'] = 'POST';
chdir($root . '/api');
require $root . '/api/kds-action.php';
