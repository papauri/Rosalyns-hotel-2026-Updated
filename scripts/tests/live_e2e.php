<?php
/**
 * LIVE end-to-end test: booking -> deposit -> check-in -> POS/KDS/room-charge -> checkout -> timezone.
 * Usage (from project root): php scripts/tests/live_e2e.php
 *
 * Runs against the LIVE database by owner decision. It drives the shared app functions
 * (availability, pricing, payments sync, housekeeping, check-in/out, folio, invoice) and mirrors the
 * inline SQL of admin pages that cannot be included from CLI. Every row it creates is LEFT in the DB
 * (names/references carry the 'E2E LIVE' marker). No DELETE/TRUNCATE/DROP. Mail is forced to fail soft.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/booking-functions.php';
require_once __DIR__ . '/../../includes/booking-timeline.php';
require_once __DIR__ . '/../../includes/pricing.php';
require_once __DIR__ . '/../../includes/idempotency.php';
require_once __DIR__ . '/../../includes/finance-sequences.php';
require_once __DIR__ . '/../../includes/room-management.php';
require_once __DIR__ . '/../../includes/restaurant-location-locks.php';
require_once __DIR__ . '/../../config/email.php';
require_once __DIR__ . '/../../config/invoice.php';
require_once __DIR__ . '/../../admin/includes/finance-schema.php';
require_once __DIR__ . '/../../admin/includes/finance-account-sync.php';
require_once __DIR__ . '/../../admin/includes/restaurant-payment-sync.php';

/* Never deliver real mail: point SMTP at a closed local port so every send fails soft and fast. */
$smtp_host = '127.0.0.1'; $smtp_port = 1; $smtp_timeout = 2; $smtp_secure = ''; $smtp_debug = 0;
$smtp_username = 'e2e@example.invalid'; $smtp_password = 'x'; $development_mode = false;

$pass = 0; $fail = 0; $created = [];
function ok(string $l): void { global $pass; $pass++; echo "[PASS] $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "[FAIL] $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function near(float $a, float $b): bool { return abs($a - $b) <= BALANCE_TOLERANCE; }
function one(PDO $pdo, string $sql, array $p = []): ?array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

$runId = strtoupper(bin2hex(random_bytes(3)));
echo "\n=== E2E LIVE run $runId (" . date('Y-m-d H:i:s') . ") ===\n";

$admin = one($pdo, "SELECT id, full_name FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1");
if (!$admin) { bad('Admin user exists'); exit(1); }
$adminId = (int)$admin['id']; $adminName = (string)$admin['full_name'];
$today = date('Y-m-d'); $tomorrow = date('Y-m-d', strtotime('+1 day'));

/* ================= 1. BOOKING ================= */
echo "\n--- 1. Booking creation / availability / pricing ---\n";
$room = null; $irId = 0;
foreach ($pdo->query("SELECT * FROM rooms WHERE is_active = 1 AND price_per_night > 0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $av = checkRoomAvailability((int)$r['id'], $today, $tomorrow, null, 0, 1);
    if (!empty($av['available'])) {
        $free = one($pdo, "SELECT ir.id FROM individual_rooms ir WHERE ir.room_type_id = ? AND ir.is_active = 1 AND ir.status = 'available'
            AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.individual_room_id = ir.id AND b.status IN ('pending','confirmed','checked-in','tentative') AND b.check_in_date < ? AND b.check_out_date > ?)
            ORDER BY ir.id LIMIT 1", [$r['id'], $tomorrow, $today]);
        if ($free) { $room = $r; $irId = (int)$free['id']; break; }
    }
}
if (!check($room !== null, 'Found a real available room type + free physical room for tonight')) { echo "\n$pass passed, $fail failed\n"; exit(1); }
echo "  room type #{$room['id']} {$room['name']}, individual room #$irId\n";

$base = (float)$room['price_per_night'];
$occ = 'double';
$r_price = !empty($room['price_double_occupancy']) ? (float)$room['price_double_occupancy'] : $base;
$dyn = applyDynamicPricing($pdo, (int)$room['id'], $today, $tomorrow, 1, $r_price);
$tt = rh_stay_totals((float)$dyn['final_price'], 'price');
$rowTotal = round($tt['net'] + $tt['levy'], 2);
check(near($tt['net'] + $tt['vat'] + $tt['levy'], $tt['total_with_vat']), 'Pricing: net+VAT+levy = total_with_vat', json_encode($tt));
check(near($rowTotal + $tt['vat'], $tt['total_with_vat']), 'Pricing: total_amount(net+levy) + VAT = total_with_vat');
$mode = vat_mode();
if ($mode === 'exclusive') check(near($tt['total_with_vat'], (float)$dyn['final_price'] * (1 + ($tt['vat_rate'] + $tt['levy_rate']) / 100)), 'Pricing: exclusive mode adds VAT+levy on top');
elseif ($mode === 'inclusive') check(near($tt['total_with_vat'] - $tt['levy'], (float)$dyn['final_price']), 'Pricing: inclusive mode keeps price gross, levy on top');
else check(near($tt['vat'], 0.0), 'Pricing: VAT off => zero VAT');
echo "  vat_mode=$mode vat={$tt['vat_rate']}% levy={$tt['levy_rate']}% total={$tt['total_with_vat']}\n";

$ref = 'E2E-LIVE-' . $runId; // varchar(20)
$bookingId = 0;
try {
    $pdo->beginTransaction();
    $pdo->prepare("SELECT id FROM rooms WHERE id = ? FOR UPDATE")->execute([$room['id']]);
    $av = checkRoomAvailability((int)$room['id'], $today, $tomorrow, null, 0, 1);
    if (empty($av['available'])) throw new Exception('availability lost under lock: ' . ($av['error'] ?? ''));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,?,?,?,?,2,2,0,50,?,?,1,?,0,?,?,?,?,?,?,'pending','unpaid',0,?,?,NOW())")
        ->execute([$ref, $room['id'], 'E2E LIVE Test', 'e2e-live@example.invalid', '+265000000001', 'Malawi', $today, $tomorrow,
            $rowTotal, $tt['levy'], $tt['levy_rate'], $tt['vat_rate'], $tt['vat'], $tt['total_with_vat'], 'E2E LIVE automated test', $occ, bin2hex(random_bytes(16))]);
    $bookingId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO booking_notes (booking_id, note_text, created_by) VALUES (?, ?, ?)")->execute([$bookingId, 'E2E LIVE test booking', $adminId]);
    recalculateBookingFinancials($bookingId);
    $pdo->commit();
    $created['booking'] = "$bookingId ($ref)";
    ok("Booking created id=$bookingId ref=$ref");
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); bad('Booking create', $e->getMessage()); echo "\n$pass passed, $fail failed\n"; exit(1); }
logBookingCreated(['id' => $bookingId, 'booking_reference' => $ref, 'number_of_nights' => 1, 'total_amount' => $rowTotal, 'status' => 'pending', 'room_id' => $room['id'],
    'check_in_date' => $today, 'check_out_date' => $tomorrow, 'number_of_guests' => 2], 'admin', $adminId, $adminName);

$b = one($pdo, "SELECT * FROM bookings WHERE id = ?", [$bookingId]);
check(near((float)$b['total_with_vat'], $tt['total_with_vat']), 'Stored total_with_vat matches computed');
check(near((float)$b['amount_due'], (float)$b['total_with_vat']), 'amount_due = total_with_vat before any payment', "due={$b['amount_due']}");
check(near((float)$b['total_amount'] + (float)$b['vat_amount'], (float)$b['total_with_vat']), 'DB: total_amount + vat_amount = total_with_vat');
check(!empty(checkRoomAvailability((int)$room['id'], $today, $tomorrow, $bookingId, 0, 1)['available']), 'Availability still reported for this booking (self-excluded)');

/* ================= 2. CONFIRM / DEPOSIT / CHECK-IN ================= */
echo "\n--- 2. Confirm, deposit, housekeeping, check-in, folio ---\n";
$st = $pdo->prepare("UPDATE bookings SET status='confirmed', updated_at=NOW() WHERE id=? AND status='pending'"); $st->execute([$bookingId]);
check($st->rowCount() === 1, 'Booking confirmed');
$pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")->execute([$room['id']]);
logBookingStatusChange($bookingId, $ref, 'pending', 'confirmed', 'admin', $adminId, $adminName);

$asg = assignIndividualRoomToBooking($bookingId, $irId, false, null, $adminId);
$b = one($pdo, "SELECT * FROM bookings WHERE id = ?", [$bookingId]);
check((int)$b['individual_room_id'] === $irId, 'Individual room assigned', json_encode($asg));

/* Payment: mirrors admin/payment-add.php insert */
function e2e_pay(PDO $pdo, int $bookingId, string $ref, float $gross, string $method, int $adminId, string $note): string {
    $vatRate = rh_vat_enabled() ? (float)getSetting('vat_rate') : 0.0;
    $split = rh_account_vat_split($pdo, 'room', $bookingId, $gross, $vatRate);
    $payRef = 'PAYE2E' . strtoupper(substr(uniqid(), -6));
    $pdo->beginTransaction();
    $receipt = finance_next_receipt_number($pdo, date('Y-m-d'));
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_status, status, receipt_number, processed_by, notes) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?)")
        ->execute([$payRef, $bookingId, $ref, $split['net'], $split['rate'], $split['vat'], $gross, $method, rh_legacy_payment_status('completed'), $receipt, $adminId, $note]);
    if (!rh_sync_account_payments($pdo, 'room', $bookingId)) { $pdo->rollBack(); throw new Exception('sync failed'); }
    $pdo->commit();
    return $payRef;
}
$deposit = round($tt['total_with_vat'] * 0.3, 2);
try {
    $payRef = e2e_pay($pdo, $bookingId, $ref, $deposit, 'cash', $adminId, 'E2E LIVE deposit');
    logBookingPayment($bookingId, $ref, $deposit, 'partial', 'cash', 'completed', $adminId, $payRef);
    $created['deposit_payment'] = $payRef;
    $p = one($pdo, "SELECT * FROM payments WHERE payment_reference = ?", [$payRef]);
    check($p !== null && near((float)$p['payment_amount'] + (float)$p['vat_amount'], (float)$p['total_amount']), 'Deposit row: net + VAT = gross', json_encode($p));
    check(!empty($p['receipt_number']), 'Deposit receipt number issued', (string)($p['receipt_number'] ?? ''));
    $b = one($pdo, "SELECT * FROM bookings WHERE id = ?", [$bookingId]);
    check($b['payment_status'] === 'partial', "payment_status partial after deposit (got {$b['payment_status']})");
    check(near((float)$b['amount_paid'], $deposit) && near((float)$b['amount_due'], $tt['total_with_vat'] - $deposit), 'amount_paid/amount_due reflect deposit', "paid={$b['amount_paid']} due={$b['amount_due']}");
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); bad('Deposit payment', $e->getMessage()); }

/* Housekeeping: room must be clean first. Put it into cleaning, then mark clean through the app function. */
updateRoomStatus($irId, 'cleaning', 'E2E LIVE prep', $adminId, ['force' => true]);
$mc = markRoomClean($irId, $adminId, ['notes' => 'E2E LIVE']);
$ir = one($pdo, "SELECT status, housekeeping_status FROM individual_rooms WHERE id = ?", [$irId]);
check(!empty($mc['success']) && $ir['status'] === 'available', 'markRoomClean() leaves room available', json_encode($mc) . ' ' . json_encode($ir));
check(getBookingRoomsNotReady($bookingId) === [], 'Check-in room gate sees room as ready');

$b = one($pdo, "SELECT * FROM bookings WHERE id = ?", [$bookingId]);
$vc = validateCheckIn($b);
check($vc['allowed'], 'validateCheckIn allows confirmed + partially-paid booking on arrival day', $vc['reason']);
$gate = evaluateCheckInRoomReady($bookingId, $adminId, false);
check($gate['allowed'], 'evaluateCheckInRoomReady allowed', $gate['message']);
$pdo->beginTransaction();
$pdo->prepare("UPDATE bookings SET status='checked-in', updated_at=NOW() WHERE id=? AND status='confirmed'")->execute([$bookingId]);
updateBookingRoomsStatus($bookingId, 'occupied', 'Guest checked in: ' . $ref, $adminId);
$pdo->commit();
logBookingCheckIn($bookingId, $ref, 'admin', $adminId, $adminName);
$b = one($pdo, "SELECT status FROM bookings WHERE id=?", [$bookingId]);
$ir = one($pdo, "SELECT status FROM individual_rooms WHERE id=?", [$irId]);
check($b['status'] === 'checked-in' && $ir['status'] === 'occupied', 'Checked in; room occupied', "{$b['status']}/{$ir['status']}");

$fol = getBookingFolioSummary($bookingId);
check(empty($fol['error']), 'Folio summary loads', json_encode($fol));

/* ================= 3. POS / KDS / room charge ================= */
echo "\n--- 3. POS: dine-in order, KDS, payment, room charge ---\n";
$item = one($pdo, "SELECT mi.id, mi.item_name AS name, mi.price, COALESCE(mi.station, mc.default_station) AS station, mc.slug AS menu_type
    FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
    LEFT JOIN stock_recipes sr ON sr.menu_item_id = mi.id AND sr.menu_type = mc.slug
    WHERE mi.is_available = 1 AND mi.price > 0 ORDER BY (sr.id IS NULL) DESC, mi.id LIMIT 1");
if (!check($item !== null, 'Found an available priced menu item')) { echo "\n$pass passed, $fail failed\n"; exit(1); }
$station = in_array($item['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true) ? $item['station'] : 'kitchen';
$tbl = (string)col($pdo, "SELECT table_number FROM restaurant_tables WHERE is_active = 1 ORDER BY id LIMIT 1");

$qty = 2.0; $line = round((float)$item['price'] * $qty, 2);
$posOrderId = 0; $oref = '';
try {
    $pdo->beginTransaction();
    $loc = rh_restaurant_resolve_pos_location($pdo, 'dine_in', $tbl);
    $oref = generateStockOrderReference();
    $pdo->prepare("INSERT INTO stock_orders (reference, order_type, table_number, customer_name, notes, status, total_amount, subtotal, created_by, opened_as_tab)
        VALUES (?, 'dine_in', ?, 'E2E LIVE Test', 'E2E LIVE', 'placed', ?, ?, ?, 0)")->execute([$oref, $loc['table_number'], $line, $line, $adminId]);
    $posOrderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$posOrderId, $item['id'], $item['menu_type'], $item['name'], $qty, $item['price'], $line, $station]);
    $soi = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE stock_orders SET kitchen_status='new', fired_at=NOW() WHERE id=?")->execute([$posOrderId]);
    $pdo->prepare("INSERT INTO stock_kds_events (order_id, event, to_status, user_id, user_name, ip_address) VALUES (?, 'fired', 'new', ?, ?, ?)")->execute([$posOrderId, $adminId, $adminName, '127.0.0.1']);
    $pdo->commit();
    $created['pos_order'] = "$posOrderId ($oref)";
    ok("POS dine-in order #$posOrderId ($oref) opened on table $tbl and fired to KDS");
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); bad('POS order open', $e->getMessage()); $posOrderId = 0; }

if ($posOrderId) {
    $o = one($pdo, "SELECT kitchen_status, status FROM stock_orders WHERE id=?", [$posOrderId]);
    check($o['kitchen_status'] === 'new', 'Order visible to KDS as new');
    $pdo->prepare("UPDATE stock_order_items SET kds_status='preparing', started_at=NOW() WHERE id=?")->execute([$soi]);
    deductStockForMenuItem((int)$item['id'], (string)$item['menu_type'], $qty, 'pos_order', $soi, $adminId);
    $pdo->prepare("UPDATE stock_order_items SET kds_status='ready', ready_at=NOW(), stock_deducted=1 WHERE id=?")->execute([$soi]);
    $pdo->prepare("UPDATE stock_order_items SET kds_status='served', served_at=NOW() WHERE id=?")->execute([$soi]);
    $pdo->prepare("UPDATE stock_orders SET kitchen_status='served', served_at=NOW() WHERE id=?")->execute([$posOrderId]);
    $li = one($pdo, "SELECT kds_status, stock_deducted FROM stock_order_items WHERE id=?", [$soi]);
    check($li['kds_status'] === 'served' && (int)$li['stock_deducted'] === 1, 'KDS pending>preparing>ready>served advanced', json_encode($li));

    /* Payment (single cash), same maths as pos_calculateRestaurantVatParts + pos_syncPayment */
    $vr = rh_vat_enabled() ? (float)getSetting('vat_rate') : 0.0;
    if ($vr > 0) { $net = round($line / (1 + $vr / 100), 2); $vat = round($line - $net, 2); } else { $net = $line; $vat = 0.0; }
    $parts = ['net' => $net, 'vat_rate' => $vr, 'vat' => $vat, 'gross' => $line];
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE stock_orders SET status='paid', paid_at=NOW(), payment_method='cash', tendered_amount=?, change_due=0 WHERE id=?")->execute([$line, $posOrderId]);
    rh_stamp_order_paid_by($pdo, $posOrderId, $adminId);
    $payId = rh_sync_restaurant_payment($pdo, $posOrderId, $oref, 'E2E LIVE Test', $parts, $adminId, 'cash');
    $pdo->commit();
    $created['pos_payment_id'] = $payId;
    $pr = one($pdo, "SELECT * FROM payments WHERE id=?", [$payId]);
    check($pr && $pr['booking_type'] === 'restaurant' && (int)$pr['booking_id'] === $posOrderId && near((float)$pr['total_amount'], $line), 'payments row written for POS order', json_encode($pr));
    check($pr && near((float)$pr['payment_amount'] + (float)$pr['vat_amount'], (float)$pr['total_amount']), 'POS payment net + VAT = gross');
    check($pr && !empty($pr['receipt_number']), 'POS receipt number issued');
    check(one($pdo, "SELECT status FROM stock_orders WHERE id=?", [$posOrderId])['status'] === 'paid', 'POS order status=paid');

    /* The exact "payment_split" query from admin/includes/reports-extra-tabs.php */
    $from = date('Y-m-d 00:00:00'); $to = date('Y-m-d 23:59:59');
    $sp = $pdo->prepare("SELECT payment_method, COUNT(*) AS n, SUM(total_amount) AS total FROM payments WHERE booking_type='restaurant' AND deleted_at IS NULL
        AND COALESCE(payment_type,'') <> 'refund' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND created_at BETWEEN ? AND ?
        GROUP BY payment_method ORDER BY total DESC");
    $sp->execute([$from, $to]);
    $seen = false;
    foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($r['payment_method'] === $pr['payment_method'] && (float)$r['total'] >= $line - BALANCE_TOLERANCE) $seen = true; }
    check($seen, 'POS payment-split report query includes the new payment (' . ($pr['payment_method'] ?? '?') . ')');
}

/* Room-service charge to the checked-in guest's folio (same calls as pos_buildOrderFromPost) */
$bBefore = one($pdo, "SELECT total_with_vat, amount_due FROM bookings WHERE id=?", [$bookingId]);
$rsOrderId = 0;
try {
    $pdo->beginTransaction();
    $roomNo = (string)col($pdo, "SELECT room_number FROM individual_rooms WHERE id=?", [$irId]);
    $loc = rh_restaurant_resolve_pos_location($pdo, 'room_service', $roomNo);
    check((int)($loc['booking_id'] ?? 0) === $bookingId, 'Room-service resolver finds the checked-in E2E booking', json_encode($loc['label'] ?? null));
    $rsRef = generateStockOrderReference();
    $rsLine = round((float)$item['price'], 2);
    $pdo->prepare("INSERT INTO stock_orders (reference, order_type, booking_id, individual_room_id, table_number, room_number, customer_name, notes, status, total_amount, subtotal, created_by, opened_as_tab)
        VALUES (?, 'room_service', ?, ?, ?, ?, 'E2E LIVE Test', 'E2E LIVE', 'placed', ?, ?, ?, 0)")
        ->execute([$rsRef, $bookingId, $irId, $loc['table_number'], $loc['room_number'], $rsLine, $rsLine, $adminId]);
    $rsOrderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station) VALUES (?,?,?,?,1,?,?,?)")
        ->execute([$rsOrderId, $item['id'], $item['menu_type'], $item['name'], $item['price'], $rsLine, $station]);
    $rsSoi = (int)$pdo->lastInsertId();
    $charge = addBookingChargeFromMenu($bookingId, (string)$item['menu_type'], (int)$item['id'], 1.0, $adminId);
    if (empty($charge['success']) || empty($charge['charge_id'])) throw new RuntimeException('folio post failed: ' . json_encode($charge));
    $pdo->prepare("UPDATE booking_charges SET stock_order_id=? WHERE id=?")->execute([$rsOrderId, $charge['charge_id']]);
    $pdo->prepare("UPDATE stock_order_items SET stock_deducted=1 WHERE id=?")->execute([$rsSoi]);
    $pdo->prepare("UPDATE stock_orders SET folio_posted_at=NOW() WHERE id=?")->execute([$rsOrderId]);
    recalculateBookingFinancials($bookingId);
    $pdo->commit();
    $created['room_service_order'] = "$rsOrderId ($rsRef)";
    ok("Room-service order #$rsOrderId charged to folio (charge #{$charge['charge_id']})");
    $bAfter = one($pdo, "SELECT total_with_vat, amount_due FROM bookings WHERE id=?", [$bookingId]);
    check(near((float)$bAfter['amount_due'] - (float)$bBefore['amount_due'], $rsLine), 'Folio charge raised amount_due by the gross line', "before={$bBefore['amount_due']} after={$bAfter['amount_due']} line=$rsLine");
    $ch = one($pdo, "SELECT line_total, vat_amount, line_subtotal FROM booking_charges WHERE id=?", [$charge['charge_id']]);
    check(near((float)$ch['line_total'], $rsLine) && near((float)$ch['line_subtotal'] + (float)$ch['vat_amount'], (float)$ch['line_total']), 'Charge row: subtotal + VAT = line_total (F&B gross)', json_encode($ch));
    $fol = getBookingFolioSummary($bookingId);
    check(empty($fol['error']), 'Folio summary reflects charge', json_encode(array_keys($fol)));
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); bad('Room-service charge to folio', $e->getMessage()); }

/* ================= 4. SETTLE + CHECKOUT ================= */
echo "\n--- 4. Settle balance, checkout, invoice, timeline ---\n";
$bk = one($pdo, "SELECT amount_due FROM bookings WHERE id=?", [$bookingId]);
$due = round((float)$bk['amount_due'], 2);
check($due > BALANCE_TOLERANCE, "Balance outstanding before settlement ($due)");
$blocked = processGuestCheckout($bookingId, $adminId, []);
check(empty($blocked['success']) && one($pdo, "SELECT status FROM bookings WHERE id=?", [$bookingId])['status'] === 'checked-in', 'Checkout with balance is blocked/needs confirmation (status unchanged)', (string)($blocked['message'] ?? ''));
try {
    $payRef2 = e2e_pay($pdo, $bookingId, $ref, $due, 'cash', $adminId, 'E2E LIVE settlement');
    logBookingPayment($bookingId, $ref, $due, 'full', 'cash', 'completed', $adminId, $payRef2);
    $created['settlement_payment'] = $payRef2;
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); bad('Settlement payment', $e->getMessage()); }
$bk = one($pdo, "SELECT * FROM bookings WHERE id=?", [$bookingId]);
check(near((float)$bk['amount_due'], 0.0) && $bk['payment_status'] === 'paid', "Balance zero and payment_status=paid (got {$bk['payment_status']}, due {$bk['amount_due']})");
$sumPaid = (float)col($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM payments WHERE booking_type='room' AND booking_id=? AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'')<>'refund' AND deleted_at IS NULL", [$bookingId]);
check(near($sumPaid, (float)$bk['total_with_vat'] + (float)$bk['folio_charges_total']), 'Sum of room payments (gross) = stay total + folio charges', "paid=$sumPaid total={$bk['total_with_vat']} folio={$bk['folio_charges_total']}");

$co = processGuestCheckout($bookingId, $adminId, ['room_status' => ROOM_STATUS_CLEANING]);
check(!empty($co['success']), 'processGuestCheckout succeeded', (string)($co['message'] ?? ''));
$bk = one($pdo, "SELECT * FROM bookings WHERE id=?", [$bookingId]);
check($bk['status'] === 'checked-out' && !empty($bk['checkout_completed_at']), 'Booking checked-out with timestamp');
$ir = one($pdo, "SELECT status FROM individual_rooms WHERE id=?", [$irId]);
check($ir['status'] === 'cleaning', "Room released to cleaning (got {$ir['status']})");
check((int)$bk['final_invoice_generated'] === 1 && !empty($bk['final_invoice_number']), 'Final invoice generated', (string)($bk['final_invoice_number'] ?? ''));
$invFile = !empty($bk['final_invoice_path']) ? __DIR__ . '/../../' . ltrim((string)$bk['final_invoice_path'], '/\\') : '';
check($invFile !== '' && is_file($invFile) && filesize($invFile) > 500, 'Invoice PDF file exists on disk', (string)$bk['final_invoice_path']);
check((int)col($pdo, "SELECT COUNT(*) FROM payments WHERE booking_type='room' AND booking_id=? AND receipt_number IS NOT NULL AND receipt_number<>''", [$bookingId]) >= 2, 'Receipts numbered for deposit + settlement payments');
check((int)col($pdo, "SELECT COUNT(*) FROM housekeeping_assignments WHERE individual_room_id=? AND notes LIKE ?", [$irId, '%' . $ref . '%']) >= 1, 'Turnover housekeeping assignment created');

$tl = getBookingTimeline($bookingId);
$types = array_map(fn($r) => strtolower((string)$r['action'] . ' ' . (string)$r['action_type']), $tl);
$has = fn(string $needle) => count(array_filter($types, fn($t) => str_contains($t, $needle))) > 0;
check($has('created'), 'Timeline: booking created');
check($has('confirmed'), 'Timeline: confirmed');
check($has('payment'), 'Timeline: payment recorded');
check($has('check-in') || $has('checked in') || $has('check in'), 'Timeline: check-in', implode(' | ', $types));
check($has('check-out') || $has('checked out') || $has('check out'), 'Timeline: check-out', implode(' | ', $types));

/* ================= 4b. CANCEL + REFUND PATH ================= */
echo "
--- 4b. Cancel a paid booking: refund + payment_status=refunded ---
";
try {
    $ref2 = 'E2E-LIVE-' . $runId . 'C'; // varchar(20)
    $nextDay = date('Y-m-d', strtotime('+10 day')); $nextDay2 = date('Y-m-d', strtotime('+11 day'));
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,?,?,?,?,2,2,0,50,?,?,1,?,0,?,?,?,?,?,?,'confirmed','unpaid',0,?,?,NOW())")
        ->execute([$ref2, $room['id'], 'E2E LIVE Cancel', 'e2e-live@example.invalid', '+265000000001', 'Malawi', $nextDay, $nextDay2,
            $rowTotal, $tt['levy'], $tt['levy_rate'], $tt['vat_rate'], $tt['vat'], $tt['total_with_vat'], 'E2E LIVE automated cancel test', $occ, bin2hex(random_bytes(16))]);
    $cancelId = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($cancelId);
    $pdo->commit();
    $created['cancel_booking'] = "$cancelId ($ref2)";
    $cp = e2e_pay($pdo, $cancelId, $ref2, round($tt['total_with_vat'] * 0.5, 2), 'cash', $adminId, 'E2E LIVE cancel deposit');
    $cres = cancelRoomBookingSettled($pdo, $cancelId, $adminId, 'E2E LIVE cancel test');
    check(!empty($cres['success']) && $cres['refund_total'] > 0, 'cancelRoomBookingSettled succeeds and refunds the deposit', json_encode($cres));
    $cb = one($pdo, "SELECT status, payment_status FROM bookings WHERE id=?", [$cancelId]);
    check($cb['status'] === 'cancelled' && $cb['payment_status'] === 'refunded', "Cancelled booking reads payment_status=refunded (got {$cb['status']}/{$cb['payment_status']})");
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); bad('Cancel/refund path', $e->getMessage()); }

/* ================= 5. USER TIMEZONE ================= */
echo "\n--- 5. Per-user timezone helpers (simulated rh_tz cookie) ---\n";
$ts = (string)$bk['checkout_completed_at'];
unset($_COOKIE['rh_tz']);
check(rhToUserTime($ts, 'Y-m-d H:i') === date('Y-m-d H:i', strtotime($ts)), 'No cookie: hotel time unchanged');
$_COOKIE['rh_tz'] = 'America/New_York';
$expect = (new DateTime($ts, new DateTimeZone(RH_TIMEZONE)))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d H:i');
check(rhToUserTime($ts, 'Y-m-d H:i') === $expect, "Cookie America/New_York converts live checkout time ($ts -> $expect)");
check(RH_TIMEZONE === date_default_timezone_get(), 'Storage timezone untouched');
$_COOKIE['rh_tz'] = 'Not/AZone';
check(rhUserTimezone() === RH_TIMEZONE, 'Invalid cookie falls back to hotel timezone');
unset($_COOKIE['rh_tz']);

echo "\n=== Rows left in live DB (E2E LIVE) ===\n";
foreach ($created as $k => $v) echo "  $k: $v\n";
echo "  individual_room: #$irId, room_type: #{$room['id']}, admin actor: #$adminId\n";
echo "\n=== E2E LIVE result: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
