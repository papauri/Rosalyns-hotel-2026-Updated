<?php

/**
 * Stock Management — Restaurant Orders
 *
 * Operational console for live restaurant orders, settlement health,
 * and stock-impact governance.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once __DIR__ . '/../includes/finance-sequences.php';
require_once __DIR__ . '/../includes/restaurant-location-locks.php';
require_once __DIR__ . '/includes/restaurant-payment-sync.php';
require_once __DIR__ . '/../includes/station-hours.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);
$currency_symbol = getSetting('currency_symbol');
finance_ensure_sequence_tables($pdo);

if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created. Please run admin/migrations/015_stock_management.php first.';
}

function calculateRestaurantVatParts(float $grossAmount): array
{
    $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0.0;
    if ($grossAmount <= 0 || $vatRate <= 0) {
        return ['net' => round($grossAmount, 2), 'vat_rate' => 0.0, 'vat' => 0.0, 'gross' => round($grossAmount, 2)];
    }

    $net = round($grossAmount / (1 + ($vatRate / 100)), 2);
    $vat = round($grossAmount - $net, 2);
    return ['net' => $net, 'vat_rate' => $vatRate, 'vat' => $vat, 'gross' => round($grossAmount, 2)];
}

function calculateMenuItemRecipeCost(PDO $pdo, int $menuItemId, string $menuType, float $quantity): float
{
    if ($quantity <= 0) return 0.0;

    $stmt = $pdo->prepare("\n        SELECT COALESCE(SUM((sri.quantity_per_portion / (GREATEST(sri.yield_percent, 0.1) / 100)) * i.cost_per_unit), 0)\n        FROM stock_recipes sr\n        INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id\n        INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id\n        WHERE sr.menu_item_id = ? AND sr.menu_type = ?\n    ");
    $stmt->execute([$menuItemId, $menuType]);
    return round(((float)$stmt->fetchColumn()) * $quantity, 4);
}

/**
 * Map our internal POS payment_method values onto the payments.payment_method enum.
 * Internal: cash | mobile_money | card_manual | card_pos | other
 * Payments enum: cash | bank_transfer | mobile_money | credit_card | debit_card | cheque | other
 */
function mapPosMethodToPaymentEnum(string $method): string
{
    return match ($method) {
        'cash'         => 'cash',
        'mobile_money' => 'mobile_money',
        'card_manual'  => 'credit_card',
        'card_pos'     => 'credit_card',
        default        => 'other',
    };
}

function logOrderAudit(PDO $pdo, int $orderId, ?int $actorId, ?string $actorName, string $event, ?string $details = null): void
{
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ins = $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$orderId, $actorId, $actorName, $event, $details, $ip]);
    } catch (Throwable $e) {
        error_log('logOrderAudit failed: ' . $e->getMessage());
    }
}

function syncRestaurantOrderPayment(PDO $pdo, array $order, int $recordedBy, string $paymentMethod = 'cash'): void
{
    $orderId = (int)$order['id'];
    $reference = (string)$order['reference'];
    $grossAmount = (float)$order['total_amount'];
    $vat = calculateRestaurantVatParts($grossAmount);
    $mappedMethod = mapPosMethodToPaymentEnum($paymentMethod);

    rh_sync_restaurant_payment(
        $pdo,
        $orderId,
        $reference,
        !empty($order['customer_name']) ? (string)$order['customer_name'] : null,
        $vat,
        $recordedBy,
        $mappedMethod
    );
}

function restaurantColumnExists(PDO $pdo, string $table, string $column): bool
{
    /** @var array<string,bool> $cache */
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $column]);
        $cache[$key] = (int)$stmt->fetchColumn() > 0;
    }
    return (bool)$cache[$key];
}

function restaurantRoomServiceLinkingAvailable(PDO $pdo): bool
{
    return restaurantColumnExists($pdo, 'stock_orders', 'booking_id')
        && restaurantColumnExists($pdo, 'stock_orders', 'room_number')
        && restaurantColumnExists($pdo, 'stock_orders', 'folio_posted_at')
        && restaurantColumnExists($pdo, 'booking_charges', 'stock_order_id');
}

function getCheckedInRoomServiceBookings(PDO $pdo): array
{
    $stmt = $pdo->query("\n        SELECT b.id, b.booking_reference, b.guest_name, b.guest_email, b.guest_phone,\n               b.individual_room_id, ir.room_number, r.name AS room_type_name\n        FROM bookings b\n        LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id\n        LEFT JOIN rooms r ON r.id = b.room_id\n        WHERE b.status = 'checked-in'\n        ORDER BY ir.room_number IS NULL, ir.room_number, b.guest_name\n    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loadCheckedInRoomServiceBooking(PDO $pdo, int $bookingId): array
{
    $stmt = $pdo->prepare("\n        SELECT b.id, b.booking_reference, b.guest_name, b.guest_email, b.guest_phone,\n               b.individual_room_id, ir.room_number, r.name AS room_type_name\n        FROM bookings b\n        LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id\n        LEFT JOIN rooms r ON r.id = b.room_id\n        WHERE b.id = ? AND b.status = 'checked-in'\n    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        throw new RuntimeException('Pick a checked-in room/booking for room service.');
    }
    return $booking;
}

function addRoomServiceFolioChargeForOrder(PDO $pdo, int $bookingId, int $orderId, string $menuType, int $menuItemId, string $itemName, float $quantity, float $unitPrice, ?int $addedBy): array
{
    $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0.0;
    // unitPrice is the VAT-inclusive (gross) menu price — extract net and VAT.
    $lineTotal    = round($quantity * $unitPrice, 2);
    if ($vatRate > 0) {
        $lineSubtotal = round($lineTotal / (1 + ($vatRate / 100)), 2);
        $vatAmount    = round($lineTotal - $lineSubtotal, 2);
    } else {
        $lineSubtotal = $lineTotal;
        $vatAmount    = 0.0;
    }
    $chargeType = $menuType === 'food' ? 'food' : 'drink';

    $columns = [
        'booking_id',
        'charge_type',
        'source_item_id',
        'description',
        'quantity',
        'unit_price',
        'line_subtotal',
        'vat_rate',
        'vat_amount',
        'line_total',
        'posted_at',
        'added_by'
    ];
    $values = [$bookingId, $chargeType, $menuItemId, 'Room service: ' . $itemName, $quantity, $unitPrice, $lineSubtotal, $vatRate, $vatAmount, $lineTotal, date('Y-m-d H:i:s'), $addedBy];

    if (restaurantColumnExists($pdo, 'booking_charges', 'stock_order_id')) {
        array_splice($columns, 1, 0, ['stock_order_id']);
        array_splice($values, 1, 0, [$orderId]);
    }

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO booking_charges (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    $chargeId = (int)$pdo->lastInsertId();

    // Stock deduction deferred — happens when KDS marks item 'ready' (prepared).
    // Folio charge is posted immediately for guest billing; stock impact follows at prep time.
    return ['charge_id' => $chargeId, 'line_total' => $lineTotal, 'stock_tracked' => false];
}

function voidRoomServiceFolioChargesForOrder(PDO $pdo, int $orderId, string $reason, int $voidedBy): int
{
    if (!restaurantColumnExists($pdo, 'booking_charges', 'stock_order_id')) {
        return 0;
    }

    $stmt = $pdo->prepare("\n        SELECT id, booking_id, charge_type, source_item_id, quantity, stock_tracked\n        FROM booking_charges\n        WHERE stock_order_id = ? AND voided = 0\n        FOR UPDATE\n    ");
    $stmt->execute([$orderId]);
    $charges = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$charges) return 0;

    $bookingIds = [];
    $upd = $pdo->prepare("UPDATE booking_charges SET voided = 1, voided_at = NOW(), void_reason = ?, voided_by = ?, updated_at = NOW() WHERE id = ?");
    foreach ($charges as $charge) {
        $chargeId = (int)$charge['id'];
        $upd->execute([mb_substr($reason, 0, 255), $voidedBy, $chargeId]);
        if (
            !empty($charge['stock_tracked'])
            && in_array($charge['charge_type'], ['food', 'drink'], true)
            && !empty($charge['source_item_id'])
        ) {
            restoreStockForMenuItem((int)$charge['source_item_id'], (string)$charge['charge_type'], (float)$charge['quantity'], 'Room service order voided: ' . $reason, $voidedBy, $chargeId);
        }
        $bookingIds[(int)$charge['booking_id']] = true;
    }

    foreach (array_keys($bookingIds) as $bookingId) {
        recalculateBookingFinancials((int)$bookingId);
    }

    return count($charges);
}

function getRestaurantOrderHealth(PDO $pdo, array $order): array
{
    $orderId = (int)$order['id'];
    $issues = [];

    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(line_total), 0) FROM stock_order_items WHERE order_id = ?');
    $sumStmt->execute([$orderId]);
    $lineSum = round((float)$sumStmt->fetchColumn(), 2);
    $expectedTotal = round($lineSum - (float)$order['discount_amount'] + (float)$order['service_charge'] + (float)$order['tax_amount'], 2);
    if (!in_array($order['status'], ['voided', 'cancelled'], true) && abs($expectedTotal - (float)$order['total_amount']) > 0.01) {
        $issues[] = 'Order total does not match item subtotal/consolidation fields';
    }

    $paymentSum = 0.0;
    $activePaymentCount = 0;
    $payStmt = $pdo->prepare("\n        SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS total\n        FROM payments\n        WHERE booking_type = 'restaurant'\n          AND booking_id = ?\n          AND COALESCE(payment_type, '') != 'refund'\n          AND deleted_at IS NULL\n          AND payment_status IN ('completed','paid','partial')\n          AND status = 'completed'\n    ");
    $payStmt->execute([$orderId]);
    $pay = $payStmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'total' => 0];
    $activePaymentCount = (int)$pay['c'];
    $paymentSum = round((float)$pay['total'], 2);

    if ($order['status'] === 'paid' && ($activePaymentCount === 0 || abs($paymentSum - (float)$order['total_amount']) > 0.01)) {
        $issues[] = 'Paid order is not balanced against the accounting payment row';
    }
    if (in_array($order['status'], ['voided', 'cancelled'], true) && $activePaymentCount > 0) {
        $issues[] = 'Reversed order still has an active completed payment';
    }

    $activeFolioCharges = 0;
    if (($order['order_type'] ?? '') === 'room_service') {
        if (empty($order['booking_id'])) {
            $issues[] = 'Room-service order is not linked to a checked-in booking';
        }
        if (restaurantColumnExists($pdo, 'booking_charges', 'stock_order_id')) {
            $folioStmt = $pdo->prepare('SELECT COUNT(*) FROM booking_charges WHERE stock_order_id = ? AND voided = 0');
            $folioStmt->execute([$orderId]);
            $activeFolioCharges = (int)$folioStmt->fetchColumn();
            if (!in_array($order['status'], ['voided', 'cancelled'], true) && !empty($order['booking_id']) && $activeFolioCharges === 0) {
                $issues[] = 'Room-service order has no active folio charges';
            }
        }
    }

    return [
        'issues' => $issues,
        'is_balanced' => empty($issues),
        'line_sum' => $lineSum,
        'expected_total' => $expectedTotal,
        'payment_sum' => $paymentSum,
        'active_payment_count' => $activePaymentCount,
        'active_folio_charges' => $activeFolioCharges,
    ];
}

function reconcileRestaurantOrder(PDO $pdo, int $orderId, array $user): array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM stock_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new RuntimeException('Order not found.');

        $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(line_total), 0) FROM stock_order_items WHERE order_id = ?');
        $sumStmt->execute([$orderId]);
        $subtotal = round((float)$sumStmt->fetchColumn(), 2);
        $newTotal = round($subtotal - (float)$order['discount_amount'] + (float)$order['service_charge'] + (float)$order['tax_amount'], 2);
        $changes = [];

        if (!in_array($order['status'], ['voided', 'cancelled'], true) && (abs((float)$order['subtotal'] - $subtotal) > 0.01 || abs((float)$order['total_amount'] - $newTotal) > 0.01)) {
            $pdo->prepare('UPDATE stock_orders SET subtotal = ?, total_amount = ?, updated_at = NOW() WHERE id = ?')->execute([$subtotal, $newTotal, $orderId]);
            $order['subtotal'] = $subtotal;
            $order['total_amount'] = $newTotal;
            $changes[] = 'order totals recomputed';
        }

        if (($order['order_type'] ?? '') === 'room_service' && !empty($order['booking_id'])) {
            if (restaurantColumnExists($pdo, 'booking_charges', 'stock_order_id')) {
                $folioStmt = $pdo->prepare('SELECT COUNT(*) FROM booking_charges WHERE stock_order_id = ? AND voided = 0');
                $folioStmt->execute([$orderId]);
                if ((int)$folioStmt->fetchColumn() > 0) {
                    $pdo->prepare('UPDATE stock_orders SET folio_posted_at = COALESCE(folio_posted_at, NOW()), updated_at = NOW() WHERE id = ?')->execute([$orderId]);
                    recalculateBookingFinancials((int)$order['booking_id']);
                    $changes[] = 'booking folio recalculated';
                }
            }
        } elseif ($order['status'] === 'paid') {
            syncRestaurantOrderPayment($pdo, $order, (int)$user['id'], (string)($order['payment_method'] ?: 'cash'));
            $changes[] = 'payment ledger synced';
        }

        if (in_array($order['status'], ['voided', 'cancelled'], true)) {
            $pdo->prepare("\n                UPDATE payments\n                SET payment_status = 'cancelled', status = 'failed', updated_at = NOW()\n                WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL\n            ")->execute([$orderId]);
            $changes[] = 'reversed payment rows cancelled';
        }

        logOrderAudit($pdo, $orderId, (int)$user['id'], (string)$user['full_name'], 'reconciled', json_encode(['changes' => $changes]));
        $pdo->commit();
        return $changes ?: ['already balanced'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// AJAX: order items viewer (used by the Items modal on this page)
if (!$error && !empty($_GET['ajax']) && $_GET['ajax'] === 'order_items') {
    $oid = (int)($_GET['id'] ?? 0);
    header('Content-Type: application/json; charset=utf-8');
    if ($oid > 0) {
        $stmt = $pdo->prepare("SELECT item_name, menu_type, quantity, unit_price, line_total, kds_status, station, notes FROM stock_order_items WHERE order_id = ? ORDER BY id");
        $stmt->execute([$oid]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        echo '[]';
    }
    exit;
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security token invalid.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'place_order') {
                /* ============================================================
                 * Combined "Place + Pay" — payment is collected at order time.
                 * This closes the cash-skim cheat: there is no later state where
                 * the order is "placed" but unpaid. Stock deducts atomically.
                 * Card POS terminal integration is a provision (disabled) for now.
                 * ============================================================ */
                $allowedOrderTypes = ['walk_in', 'dine_in', 'takeaway', 'room_service', 'other'];
                $orderType = $_POST['order_type'] ?? 'walk_in';
                if (!in_array($orderType, $allowedOrderTypes, true)) {
                    $orderType = 'walk_in';
                }
                $tableNumber  = trim($_POST['table_number'] ?? '');
                $customerName = trim($_POST['customer_name'] ?? '');
                $customerEmail = trim($_POST['customer_email'] ?? '');
                $customerPhone = trim($_POST['customer_phone'] ?? '');
                $notes        = trim($_POST['notes'] ?? '');
                $bookingId    = 0;
                $individualRoomId = null;
                $roomNumber = null;
                $roomServiceBooking = null;
                $paymentMethod = $_POST['payment_method'] ?? '';

                if ($orderType === 'room_service') {
                    if (!restaurantRoomServiceLinkingAvailable($pdo)) {
                        throw new RuntimeException('Room-service folio sync needs migration 025. Run admin/migrations/025_restaurant_order_room_service_sync.php.');
                    }
                    $bookingId = (int)($_POST['booking_id'] ?? 0);
                    $roomServiceBooking = loadCheckedInRoomServiceBooking($pdo, $bookingId);
                    $individualRoomId = !empty($roomServiceBooking['individual_room_id']) ? (int)$roomServiceBooking['individual_room_id'] : null;
                    $roomNumber = trim((string)($roomServiceBooking['room_number'] ?? '')) ?: null;
                    $tableNumber = $roomNumber ?: ('Booking ' . $roomServiceBooking['booking_reference']);
                    $customerName = (string)$roomServiceBooking['guest_name'];
                    $customerEmail = (string)($roomServiceBooking['guest_email'] ?? '');
                    $customerPhone = (string)($roomServiceBooking['guest_phone'] ?? '');
                    $paymentMethod = '';
                    $notes = trim($notes . ($notes !== '' ? "\n" : '') . 'Charged to booking ' . $roomServiceBooking['booking_reference']);
                } else {
                    $allowedMethods = ['cash', 'mobile_money', 'card_manual', 'card_pos', 'other'];
                    if (!in_array($paymentMethod, $allowedMethods, true)) {
                        throw new RuntimeException('Select a valid payment method.');
                    }
                    if ($paymentMethod === 'card_pos') {
                        // Provision-only: dedicated card-POS integration not enabled yet.
                        throw new RuntimeException('Card POS terminal is not enabled yet — use Card (manual) for now.');
                    }
                    if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('Customer email looks invalid.');
                    }
                }
                $itemIds   = $_POST['item_id'] ?? [];
                $itemTypes = $_POST['item_type'] ?? [];
                $itemQtys  = $_POST['item_qty'] ?? [];

                // Method-specific input
                $tendered             = (float)($_POST['tendered_amount'] ?? 0);
                $mobileProvider       = trim($_POST['mobile_wallet_provider'] ?? '');
                $mobileReference      = trim($_POST['mobile_wallet_reference'] ?? '');
                $cardLast4Raw         = preg_replace('/\D/', '', (string)($_POST['card_last4'] ?? ''));
                $cardLast4            = strlen($cardLast4Raw) >= 4 ? substr($cardLast4Raw, -4) : null;
                $cardAuthCode         = trim($_POST['card_auth_code'] ?? '');

                $count = is_array($itemIds) ? count($itemIds) : 0;
                if ($count === 0) throw new RuntimeException('Add at least one item to the order.');

                $pdo->beginTransaction();

                // Enforce the same table/room lock and active-location validation used by POS.
                if ($orderType === 'dine_in') {
                    $resolved = rh_restaurant_resolve_pos_location($pdo, 'dine_in', $tableNumber);
                    $tableNumber = (string)($resolved['table_number'] ?? $tableNumber);
                } elseif ($orderType === 'room_service') {
                    if (!$roomNumber) {
                        throw new RuntimeException('Selected room-service booking has no active room number.');
                    }
                    $resolved = rh_restaurant_resolve_pos_location($pdo, 'room_service', (string)$roomNumber);
                    if (!empty($resolved['booking_id']) && (int)$resolved['booking_id'] !== $bookingId) {
                        throw new RuntimeException('Room occupancy changed. Refresh and select the active checked-in booking.');
                    }
                    $tableNumber = (string)($resolved['table_number'] ?: $roomNumber);
                    if (!empty($resolved['individual_room_id'])) {
                        $individualRoomId = (int)$resolved['individual_room_id'];
                    }
                }

                $reference = generateStockOrderReference();
                $orderColumns = ['reference', 'order_type', 'table_number', 'customer_name', 'customer_email', 'customer_phone', 'notes', 'status', 'total_amount', 'created_by', 'payment_method'];
                $orderValues = [$reference, $orderType, $tableNumber ?: null, $customerName ?: null, $customerEmail ?: null, $customerPhone ?: null, $notes ?: null, 'placed', 0, $user['id'], $paymentMethod ?: null];
                if (restaurantRoomServiceLinkingAvailable($pdo)) {
                    $orderColumns[] = 'booking_id';
                    $orderColumns[] = 'individual_room_id';
                    $orderColumns[] = 'room_number';
                    $orderColumns[] = 'folio_posted_at';
                    $orderValues[] = $orderType === 'room_service' ? $bookingId : null;
                    $orderValues[] = $orderType === 'room_service' ? $individualRoomId : null;
                    $orderValues[] = $orderType === 'room_service' ? $roomNumber : null;
                    $orderValues[] = null;
                }
                $orderIns = $pdo->prepare('INSERT INTO stock_orders (' . implode(',', $orderColumns) . ') VALUES (' . implode(',', array_fill(0, count($orderColumns), '?')) . ')');
                $orderIns->execute($orderValues);
                $orderId = (int)$pdo->lastInsertId();

                $itemIns = $pdo->prepare("
                    INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $totalAmount = 0;
                $totalCost = 0;
                $folioChargeCount = 0;

                for ($k = 0; $k < $count; $k++) {
                    $itemId = (int)($itemIds[$k] ?? 0);
                    $qty = (float)($itemQtys[$k] ?? 0);
                    if ($itemId <= 0 || $qty <= 0) continue;
                    if ($qty > 1000) throw new RuntimeException('Quantity per line capped at 1000. Found ' . $qty);

                    // SERVER-SIDE PRICE LOOKUP — never trust client price.
                    $sel = $pdo->prepare("
                        SELECT mi.id, mi.item_name AS name, mi.price,
                               COALESCE(mi.station, mc.default_station) AS station,
                               mc.slug AS menu_type
                        FROM menu_items mi
                        JOIN menu_categories mc ON mc.id = mi.category_id
                        WHERE mi.id = ? AND mi.is_available = 1
                    ");
                    $sel->execute([$itemId]);
                    $row = $sel->fetch(PDO::FETCH_ASSOC);
                    if (!$row) throw new RuntimeException('Menu item not found or unavailable.');

                    $menuType = $row['menu_type'];
                    $line = round((float)$row['price'] * $qty, 2);
                    $lineCost = calculateMenuItemRecipeCost($pdo, $itemId, $menuType, $qty);
                    $station = in_array($row['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true)
                        ? $row['station']
                        : 'kitchen';
                    $itemIns->execute([$orderId, $itemId, $menuType, $row['name'], $qty, (float)$row['price'], $line, $station]);
                    $totalAmount += $line;
                    $totalCost += $lineCost;

                    if ($orderType === 'room_service') {
                        addRoomServiceFolioChargeForOrder($pdo, $bookingId, $orderId, $menuType, $itemId, (string)$row['name'], $qty, (float)$row['price'], $user['id']);
                        $folioChargeCount++;
                    }
                    // Stock deduction deferred — happens when KDS marks item 'ready' (prepared)
                }

                if ($totalAmount <= 0) throw new RuntimeException('Order total must be greater than zero.');

                if ($orderType === 'room_service') {
                    if ($folioChargeCount <= 0) {
                        throw new RuntimeException('Room-service order did not post any folio charges.');
                    }

                    $pdo->prepare("\n                        UPDATE stock_orders SET\n                            total_amount = ?,\n                            subtotal = ?,\n                            total_cost = ?,\n                            folio_posted_at = NOW(),\n                            kitchen_status = 'new',\n                            fired_at = COALESCE(fired_at, NOW()),\n                            kitchen_printed_at = COALESCE(kitchen_printed_at, NOW())\n                        WHERE id = ?\n                    ")->execute([$totalAmount, $totalAmount, $totalCost, $orderId]);

                    try {
                        $pdo->prepare("INSERT INTO stock_kds_events (order_id, event, to_status, user_id, user_name, ip_address) VALUES (?, 'fired', 'new', ?, ?, ?)")
                            ->execute([$orderId, $user['id'], $user['full_name'], $_SERVER['REMOTE_ADDR'] ?? null]);
                    } catch (Throwable $e) {
                        error_log('room service kds log: ' . $e->getMessage());
                    }

                    recalculateBookingFinancials($bookingId);
                    logOrderAudit($pdo, $orderId, $user['id'], $user['full_name'], 'room_service_charged', json_encode([
                        'booking_id' => $bookingId,
                        'booking_reference' => $roomServiceBooking['booking_reference'] ?? '',
                        'room' => $roomNumber,
                        'total' => $totalAmount,
                        'lines' => $count,
                        'folio_charges' => $folioChargeCount,
                    ]));

                    $pdo->commit();

                    if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v3');

                    $roomLabel = $roomNumber ? 'Room ' . $roomNumber : 'booking ' . ($roomServiceBooking['booking_reference'] ?? '');
                    $message = "Room-service order {$reference} posted to {$roomLabel} ({$currency_symbol} " . number_format($totalAmount, 2) . "). It will appear on the guest folio for checkout accounting.";
                } else {

                    // Method-specific validation now that we have the total
                    $paymentExtras = [
                        'tendered_amount' => null,
                        'change_due' => null,
                        'mobile_wallet_provider' => null,
                        'mobile_wallet_reference' => null,
                        'card_last4' => null,
                        'card_auth_code' => null,
                    ];
                    if ($paymentMethod === 'cash') {
                        if ($tendered + 0.001 < $totalAmount) {
                            throw new RuntimeException('Tendered amount is less than the total. Got ' . number_format($tendered, 2) . ', need ' . number_format($totalAmount, 2) . '.');
                        }
                        $paymentExtras['tendered_amount'] = round($tendered, 2);
                        $paymentExtras['change_due'] = round($tendered - $totalAmount, 2);
                    } elseif ($paymentMethod === 'mobile_money') {
                        if ($mobileProvider === '' || $mobileReference === '') {
                            throw new RuntimeException('Mobile money requires both provider (Airtel/TNM/etc) and transaction reference.');
                        }
                        $paymentExtras['mobile_wallet_provider'] = mb_substr($mobileProvider, 0, 50);
                        $paymentExtras['mobile_wallet_reference'] = mb_substr($mobileReference, 0, 100);
                    } elseif ($paymentMethod === 'card_manual') {
                        if (!$cardLast4 || $cardAuthCode === '') {
                            throw new RuntimeException('Card payment requires the last 4 digits AND the authorisation code from the slip.');
                        }
                        $paymentExtras['card_last4'] = $cardLast4;
                        $paymentExtras['card_auth_code'] = mb_substr($cardAuthCode, 0, 50);
                    }

                    // Persist totals + payment fields + flip status to 'paid' atomically
                    $upd = $pdo->prepare("
                    UPDATE stock_orders SET
                        total_amount = ?,
                        subtotal = ?,
                        total_cost = ?,
                        status = 'paid',
                        paid_at = NOW(),
                        tendered_amount = ?,
                        change_due = ?,
                        mobile_wallet_provider = ?,
                        mobile_wallet_reference = ?,
                        card_last4 = ?,
                        card_auth_code = ?
                    WHERE id = ?
                ");
                    $upd->execute([
                        $totalAmount,
                        $totalAmount,
                        $totalCost,
                        $paymentExtras['tendered_amount'],
                        $paymentExtras['change_due'],
                        $paymentExtras['mobile_wallet_provider'],
                        $paymentExtras['mobile_wallet_reference'],
                        $paymentExtras['card_last4'],
                        $paymentExtras['card_auth_code'],
                        $orderId,
                    ]);

                    // Sync to accounting ledger
                    $orderRow = ['id' => $orderId, 'reference' => $reference, 'total_amount' => $totalAmount, 'customer_name' => $customerName, 'status' => 'paid'];
                    syncRestaurantOrderPayment($pdo, $orderRow, $user['id'], $paymentMethod);

                    logOrderAudit(
                        $pdo,
                        $orderId,
                        $user['id'],
                        $user['full_name'],
                        'placed_paid',
                        json_encode([
                            'method' => $paymentMethod,
                            'total' => $totalAmount,
                            'lines' => $count,
                            'tendered' => $paymentExtras['tendered_amount'],
                            'change' => $paymentExtras['change_due'],
                        ])
                    );

                    $pdo->commit();

                    if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v3');

                    $changeMsg = '';
                    if ($paymentMethod === 'cash' && $paymentExtras['change_due'] > 0) {
                        $changeMsg = ' Change due: ' . $currency_symbol . ' ' . number_format($paymentExtras['change_due'], 2) . '.';
                    }
                    $message = "Order {$reference} paid ({$currency_symbol} " . number_format($totalAmount, 2) . " via " . str_replace('_', ' ', $paymentMethod) . ")." . $changeMsg;
                }
            } elseif ($action === 'cancel_order') {
                /* Cancel — only allowed on UNPAID orders (none should normally exist
                 * with the combined-pay flow, but we keep this for legacy/edge cases). */
                $orderId = (int)($_POST['order_id'] ?? 0);

                $pdo->beginTransaction();
                $oh = $pdo->prepare("SELECT * FROM stock_orders WHERE id = ? FOR UPDATE");
                $oh->execute([$orderId]);
                $order = $oh->fetch(PDO::FETCH_ASSOC);
                if (!$order) throw new RuntimeException('Order not found.');
                if (in_array($order['status'], ['cancelled', 'voided'], true)) throw new RuntimeException('Order already reversed.');
                if ($order['status'] === 'paid') throw new RuntimeException('Order is paid — use Void with a reason instead.');

                // Enforce pre-prep cancellation policy: only cancel when all items are still pending
                $prepCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id=? AND kds_status IN ('preparing','in_progress','ready','collection','served')");
                $prepCheck->execute([$orderId]);
                if ((int)$prepCheck->fetchColumn() > 0) {
                    throw new RuntimeException('Cannot cancel: preparation has already started. Use Void (admin/manager) to reverse an in-progress order.');
                }
                if (in_array((string)($order['kitchen_status'] ?? ''), ['in_progress', 'ready', 'served'], true)) {
                    throw new RuntimeException('Cannot cancel: order is already in progress. Use Void instead.');
                }

                $folioVoids = 0;
                if (($order['order_type'] ?? '') === 'room_service') {
                    $folioVoids = voidRoomServiceFolioChargesForOrder($pdo, $orderId, 'Room-service order cancelled', (int)$user['id']);
                    restoreFromPosOrder($pdo, $orderId, $user['id']);
                } else {
                    restoreFromPosOrder($pdo, $orderId, $user['id']);
                }

                $pdo->prepare("UPDATE stock_orders SET status = 'cancelled', updated_at = NOW(), kitchen_status='served', served_at=COALESCE(served_at, NOW()) WHERE id = ?")->execute([$orderId]);
                $pdo->prepare("UPDATE stock_order_items SET kds_status='void', served_at=COALESCE(served_at, NOW()), bumped_by=? WHERE order_id=? AND kds_status NOT IN ('served','void')")
                    ->execute([$user['id'], $orderId]);
                $pdo->prepare("\n                    UPDATE payments\n                    SET payment_status = 'cancelled', status = 'failed', updated_at = NOW()\n                    WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL\n                ")->execute([$orderId]);
                logOrderAudit($pdo, $orderId, $user['id'], $user['full_name'], 'cancelled', null);
                $pdo->commit();

                if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v2');

                $message = "Order {$order['reference']} cancelled" . ($folioVoids > 0 ? " and {$folioVoids} folio charge(s) voided." : ' and stock restored.');
            } elseif ($action === 'void_order') {
                /* VOID a paid order — admin/manager only, mandatory reason, full audit. */
                if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
                    throw new RuntimeException('Only admins or managers can void a paid order.');
                }
                $orderId = (int)($_POST['order_id'] ?? 0);
                $voidReason = trim($_POST['void_reason'] ?? '');
                if (mb_strlen($voidReason) < 8) {
                    throw new RuntimeException('Void reason is required (at least 8 characters).');
                }

                $pdo->beginTransaction();
                $oh = $pdo->prepare("SELECT * FROM stock_orders WHERE id = ? FOR UPDATE");
                $oh->execute([$orderId]);
                $order = $oh->fetch(PDO::FETCH_ASSOC);
                if (!$order) throw new RuntimeException('Order not found.');
                if (in_array($order['status'], ['cancelled', 'voided'], true)) throw new RuntimeException('Order already reversed.');

                $folioVoids = 0;
                if (($order['order_type'] ?? '') === 'room_service') {
                    $folioVoids = voidRoomServiceFolioChargesForOrder($pdo, $orderId, $voidReason, (int)$user['id']);
                    restoreFromPosOrder($pdo, $orderId, $user['id']);
                } else {
                    restoreFromPosOrder($pdo, $orderId, $user['id']);
                }

                $pdo->prepare("UPDATE stock_orders SET status = 'voided', voided_by = ?, voided_at = NOW(), void_reason = ?, updated_at = NOW(), kitchen_status='served', served_at=COALESCE(served_at, NOW()) WHERE id = ?")
                    ->execute([$user['id'], mb_substr($voidReason, 0, 500), $orderId]);
                // Clear all station boards: mark every non-served line as voided so KDS/BDS/CDS drop it
                $pdo->prepare("UPDATE stock_order_items SET kds_status='void', served_at=COALESCE(served_at, NOW()), bumped_by=? WHERE order_id=? AND kds_status NOT IN ('served','void')")
                    ->execute([$user['id'], $orderId]);
                // KDS audit trail entry for the void
                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $pdo->prepare("INSERT INTO stock_kds_events (order_id, event, from_status, to_status, user_id, user_name, ip_address) VALUES (?, 'voided', 'in_progress', 'void', ?, ?, ?)")
                        ->execute([$orderId, $user['id'], $user['full_name'], $ip]);
                } catch (Throwable $e) {
                    error_log('void kds_log: ' . $e->getMessage());
                }
                $pdo->prepare("\n                    UPDATE payments\n                    SET payment_status = 'cancelled', status = 'failed', notes = CONCAT(COALESCE(notes,''), '\\nVOID: ', ?), updated_at = NOW()\n                    WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL\n                ")->execute([$voidReason, $orderId]);
                logOrderAudit($pdo, $orderId, $user['id'], $user['full_name'], 'voided', $voidReason);
                $pdo->commit();

                if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v2');

                $message = "Order {$order['reference']} voided. " . ($folioVoids > 0 ? "{$folioVoids} folio charge(s) voided and stock restored." : 'Stock restored.') . ' Reason logged.';
            } elseif ($action === 'reconcile_order') {
                if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
                    throw new RuntimeException('Only admins or managers can reconcile orders.');
                }
                $orderId = (int)($_POST['order_id'] ?? 0);
                if ($orderId <= 0) throw new RuntimeException('Invalid order.');
                $changes = reconcileRestaurantOrder($pdo, $orderId, $user);
                if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v3');
                $message = 'Order reconciled: ' . implode(', ', $changes) . '.';
            } elseif ($action === 'settle_order') {
                /* SETTLE — close a placed (open) order by collecting payment.
                 * The order already exists with items; we just need to record
                 * payment and flip status to 'paid'. */
                $orderId = (int)($_POST['order_id'] ?? 0);
                $paymentMethod = $_POST['payment_method'] ?? '';
                $allowedMethods = ['cash', 'mobile_money', 'card_manual'];
                if (!in_array($paymentMethod, $allowedMethods, true)) {
                    throw new RuntimeException('Select a valid payment method.');
                }

                $pdo->beginTransaction();
                $oh = $pdo->prepare("SELECT * FROM stock_orders WHERE id = ? FOR UPDATE");
                $oh->execute([$orderId]);
                $order = $oh->fetch(PDO::FETCH_ASSOC);
                if (!$order) throw new RuntimeException('Order not found.');
                if ($order['status'] !== 'placed') throw new RuntimeException('Order is not open — cannot settle a ' . $order['status'] . ' order.');
                if (($order['order_type'] ?? '') === 'room_service') throw new RuntimeException('Room-service orders are charged to the booking folio — use the booking module to settle them.');

                $totalAmount = (float)$order['total_amount'];
                if ($totalAmount <= 0) throw new RuntimeException('Order total is zero — cannot settle.');

                $tendered        = (float)($_POST['tendered_amount'] ?? 0);
                $mobileProvider  = mb_substr(trim($_POST['mobile_wallet_provider'] ?? ''), 0, 50);
                $mobileReference = mb_substr(trim($_POST['mobile_wallet_reference'] ?? ''), 0, 100);
                $cardLast4Raw    = preg_replace('/\D/', '', (string)($_POST['card_last4'] ?? ''));
                $cardLast4       = strlen($cardLast4Raw) >= 4 ? substr($cardLast4Raw, -4) : null;
                $cardAuthCode    = mb_substr(trim($_POST['card_auth_code'] ?? ''), 0, 50);

                $paymentExtras = ['tendered_amount' => null, 'change_due' => null, 'mobile_wallet_provider' => null, 'mobile_wallet_reference' => null, 'card_last4' => null, 'card_auth_code' => null];
                if ($paymentMethod === 'cash') {
                    if ($tendered + 0.001 < $totalAmount) {
                        throw new RuntimeException('Tendered amount (' . number_format($tendered, 2) . ') is less than order total (' . number_format($totalAmount, 2) . ').');
                    }
                    $paymentExtras['tendered_amount'] = round($tendered, 2);
                    $paymentExtras['change_due']      = round($tendered - $totalAmount, 2);
                } elseif ($paymentMethod === 'mobile_money') {
                    if ($mobileProvider === '' || $mobileReference === '') {
                        throw new RuntimeException('Mobile money requires both provider and transaction reference.');
                    }
                    $paymentExtras['mobile_wallet_provider']  = $mobileProvider;
                    $paymentExtras['mobile_wallet_reference'] = $mobileReference;
                } elseif ($paymentMethod === 'card_manual') {
                    if (!$cardLast4 || $cardAuthCode === '') {
                        throw new RuntimeException('Card payment requires the last 4 digits AND the authorisation code from the slip.');
                    }
                    $paymentExtras['card_last4']     = $cardLast4;
                    $paymentExtras['card_auth_code'] = $cardAuthCode;
                }

                $pdo->prepare("
                    UPDATE stock_orders SET
                        status = 'paid', paid_at = NOW(), payment_method = ?,
                        tendered_amount = ?, change_due = ?,
                        mobile_wallet_provider = ?, mobile_wallet_reference = ?,
                        card_last4 = ?, card_auth_code = ?, updated_at = NOW()
                    WHERE id = ? AND status = 'placed'
                ")->execute([
                    $paymentMethod,
                    $paymentExtras['tendered_amount'], $paymentExtras['change_due'],
                    $paymentExtras['mobile_wallet_provider'], $paymentExtras['mobile_wallet_reference'],
                    $paymentExtras['card_last4'], $paymentExtras['card_auth_code'],
                    $orderId,
                ]);

                syncRestaurantOrderPayment($pdo, array_merge($order, ['status' => 'paid', 'payment_method' => $paymentMethod]), (int)$user['id'], $paymentMethod);
                logOrderAudit($pdo, $orderId, (int)$user['id'], (string)$user['full_name'], 'settled', json_encode([
                    'method'   => $paymentMethod,
                    'total'    => $totalAmount,
                    'tendered' => $paymentExtras['tendered_amount'],
                    'change'   => $paymentExtras['change_due'],
                ]));
                $pdo->commit();

                if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v3');

                $changeMsg = '';
                if ($paymentMethod === 'cash' && ($paymentExtras['change_due'] ?? 0) > 0) {
                    $changeMsg = ' Change: ' . $currency_symbol . ' ' . number_format((float)$paymentExtras['change_due'], 2) . '.';
                }
                $message = "Order {$order['reference']} settled — {$currency_symbol} " . number_format($totalAmount, 2) . ' via ' . str_replace('_', ' ', $paymentMethod) . '.' . $changeMsg;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }

    if ($message) $_SESSION['stock_msg'] = $message;
    if ($error)   $_SESSION['stock_err'] = $error;
    header('Location: stock-orders.php');
    exit;
}

/**
 * Restore stock for a POS order — equivalent to restoreStockForMenuItem but
 * matching the 'pos_order' source_type the deduction wrote.
 * Inline here to avoid a second public helper for now.
 */
function restoreFromPosOrder(PDO $pdo, int $orderId, ?int $doneBy): void
{
    if ($orderId <= 0) return;
    $byBatch = [];
    $byIngredient = [];
    $seenAdj = [];

    // ── New format (current): adjustments stored with source_id = stock_order_items.id ──
    // KDS ready_item uses item ID as source_id to avoid false idempotency-skip when two
    // items share an ingredient. Only look at items where stock was actually deducted.
    $itemSel = $pdo->prepare("SELECT id FROM stock_order_items WHERE order_id = ? AND stock_deducted = 1");
    $itemSel->execute([$orderId]);
    $itemIds = $itemSel->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($itemIds)) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $sel = $pdo->prepare("
            SELECT sa.id AS adjustment_id, sa.ingredient_id, sa.quantity_change, sbd.batch_id, sbd.quantity_deducted
            FROM stock_adjustments sa
            LEFT JOIN stock_batch_deductions sbd ON sbd.adjustment_id = sa.id
            WHERE sa.source_type = 'pos_order' AND sa.source_id IN ({$placeholders})
        ");
        $sel->execute($itemIds);
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $adjId = (int)$h['adjustment_id'];
            $ingId = (int)$h['ingredient_id'];
            if (!isset($seenAdj[$adjId])) {
                $seenAdj[$adjId] = true;
                $byIngredient[$ingId] = ($byIngredient[$ingId] ?? 0) + abs((float)$h['quantity_change']);
            }
            if (!empty($h['batch_id'])) {
                $bid = (int)$h['batch_id'];
                $byBatch[$bid] = ($byBatch[$bid] ?? 0) + (float)$h['quantity_deducted'];
            }
        }
    }

    // ── Legacy fallback: adjustments stored with source_id = order_id ──
    // Used by early POS versions before item-level source_id was introduced.
    if (empty($byIngredient)) {
        $sel = $pdo->prepare("
            SELECT sa.id AS adjustment_id, sa.ingredient_id, sa.quantity_change, sbd.batch_id, sbd.quantity_deducted
            FROM stock_adjustments sa
            LEFT JOIN stock_batch_deductions sbd ON sbd.adjustment_id = sa.id
            WHERE sa.source_type = 'pos_order' AND sa.source_id = ?
        ");
        $sel->execute([$orderId]);
        $seenAdj = [];
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $adjId = (int)$h['adjustment_id'];
            $ingId = (int)$h['ingredient_id'];
            if (!isset($seenAdj[$adjId])) {
                $seenAdj[$adjId] = true;
                $byIngredient[$ingId] = ($byIngredient[$ingId] ?? 0) + abs((float)$h['quantity_change']);
            }
            if (!empty($h['batch_id'])) {
                $bid = (int)$h['batch_id'];
                $byBatch[$bid] = ($byBatch[$bid] ?? 0) + (float)$h['quantity_deducted'];
            }
        }
    }

    if (!empty($byBatch)) {
        $bUpd = $pdo->prepare("
            UPDATE stock_batches
            SET quantity_remaining = quantity_remaining + ?,
                status = CASE WHEN status = 'depleted' THEN 'active' ELSE status END,
                updated_at = NOW()
            WHERE id = ?
        ");
        foreach ($byBatch as $bid => $q) $bUpd->execute([$q, $bid]);
    }

    $adjStmt = $pdo->prepare("
        INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by)
        VALUES (?, ?, 'POS order cancelled', 'void_restore', ?, ?, ?)
    ");
    $costSel = $pdo->prepare("SELECT cost_per_unit FROM stock_ingredients WHERE id = ?");
    $ingUpd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ?, updated_at = NOW() WHERE id = ?");

    foreach ($byIngredient as $ingId => $q) {
        $costSel->execute([$ingId]);
        $cost = (float)($costSel->fetchColumn() ?: 0);
        $adjStmt->execute([$ingId, $q, $orderId, $cost, $doneBy]);
        $ingUpd->execute([$q, $ingId]);
    }
}

if (!empty($_SESSION['stock_msg'])) {
    $message = $_SESSION['stock_msg'];
    unset($_SESSION['stock_msg']);
}
if (!empty($_SESSION['stock_err'])) {
    $error   = $_SESSION['stock_err'];
    unset($_SESSION['stock_err']);
}

$orders = [];
$shift = [];
$orderHealth = [];
$healthSummary   = ['unbalanced' => 0, 'room_service_unlinked' => 0];
$operationsSnapshot = [
    'open_orders' => 0,
    'awaiting_prep' => 0,
    'in_progress' => 0,
    'ready_to_serve' => 0,
    'awaiting_payment' => 0,
    'settled_today' => 0,
    'cancelled_today' => 0,
    'voided_today' => 0,
    'active_tables' => 0,
    'active_rooms' => 0,
    'avg_ticket_today' => 0,
];
$stationQueue = [];
$stationItems  = [];
$cashierStats  = [];
$reviewData    = [];
$canReconcile  = false;
$paymentMixToday = [];
$topSellingItemsToday = [];
$hourlyBreakdown = [];
$orderTypeSplit = [];
$yesterday = ['orders_yesterday' => 0, 'revenue_yesterday' => 0, 'settled_revenue_yesterday' => 0];
$voidReasonsToday = [];
$filterDate           = '';
$filterMethod         = '';
$filterOrderType      = '';
$filterHour           = '';
$filterStatus         = '';
$filterHealth         = '';
$filterKitchenStatus  = '';
$hasActiveFilter      = false; // set inside try block when filters are parsed
if (!$error || strpos($error, 'not yet') === false) {
    try {
        // ---- Filter parsing (drives the clickable shift-cards) ----
        $filterDate           = $_GET['date'] ?? '';
        $filterMethod         = $_GET['payment_method'] ?? '';
        $filterOrderType      = $_GET['order_type'] ?? '';
        $filterHour           = $_GET['hour'] ?? '';
        $filterStatus         = $_GET['status'] ?? '';
        $filterHealth         = $_GET['health'] ?? '';
        $filterKitchenStatus  = $_GET['kitchen_status'] ?? '';
        $hasActiveFilter      = false; // default; set to true below when a filter is active
        $allowedMethods       = ['cash', 'mobile_money', 'card_manual', 'card_pos', 'unassigned'];
        $allowedStatus        = ['placed', 'paid', 'completed', 'cancelled', 'voided', 'parked'];
        // Single DB values
        $allowedKitchenStatus = ['new', 'pending', 'in_progress', 'preparing', 'ready', 'collection', 'served', 'recalled'];
        // Pipeline-stage group aliases (map to IN clauses — same groupings the ops snapshot uses)
        $kitchenStatusGroups  = [
            'awaiting_prep' => ['new', 'pending'],
            'in_progress_all' => ['in_progress', 'preparing'],
            'ready_all'       => ['ready', 'collection'],
        ];
        $whereClauses   = [];
        $whereParams    = [];
        if ($filterDate === 'today') {
            $whereClauses[] = 'DATE(o.created_at) = CURDATE()';
        }
        if ($filterMethod === 'unassigned') {
            $whereClauses[] = "(o.payment_method IS NULL OR TRIM(o.payment_method) = '')";
        } elseif (in_array($filterMethod, $allowedMethods, true)) {
            $whereClauses[] = 'o.payment_method = ?';
            $whereParams[]  = $filterMethod;
        }
        if ($filterOrderType !== '' && preg_match('/^[a-z_]+$/', (string)$filterOrderType) === 1) {
            $whereClauses[] = 'o.order_type = ?';
            $whereParams[]  = $filterOrderType;
        } else {
            $filterOrderType = '';
        }
        if ($filterHour !== '' && ctype_digit((string)$filterHour) && (int)$filterHour >= 0 && (int)$filterHour <= 23) {
            $whereClauses[] = 'HOUR(o.created_at) = ?';
            $whereParams[]  = (int)$filterHour;
        } else {
            $filterHour = '';
        }
        if (in_array($filterStatus, $allowedStatus, true)) {
            $whereClauses[] = 'o.status = ?';
            $whereParams[]  = $filterStatus;
        }
        if (isset($kitchenStatusGroups[$filterKitchenStatus])) {
            $placeholders   = implode(',', array_fill(0, count($kitchenStatusGroups[$filterKitchenStatus]), '?'));
            $whereClauses[] = 'o.kitchen_status IN (' . $placeholders . ')';
            foreach ($kitchenStatusGroups[$filterKitchenStatus] as $ks) {
                $whereParams[] = $ks;
            }
        } elseif (in_array($filterKitchenStatus, $allowedKitchenStatus, true)) {
            $whereClauses[] = 'o.kitchen_status = ?';
            $whereParams[]  = $filterKitchenStatus;
        }
        $whereSql = $whereClauses ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';
        $activeFilters = compact('filterDate', 'filterMethod', 'filterOrderType', 'filterHour', 'filterStatus', 'filterHealth', 'filterKitchenStatus');
        $hasActiveFilter = $filterDate || $filterMethod || $filterOrderType || $filterHour || $filterStatus || $filterHealth || $filterKitchenStatus;

        $orderStmt = $pdo->prepare("
            SELECT o.*, u.full_name AS created_by_name, vu.full_name AS voided_by_name,
                   b.booking_reference, b.guest_name AS booking_guest_name,
                   ir.room_number AS booking_room_number,
                   (SELECT COUNT(*) FROM stock_order_items WHERE order_id = o.id) AS line_count
            FROM stock_orders o
            LEFT JOIN admin_users u ON u.id = o.created_by
            LEFT JOIN admin_users vu ON vu.id = o.voided_by
            LEFT JOIN bookings b ON b.id = o.booking_id
            LEFT JOIN individual_rooms ir ON ir.id = o.individual_room_id
            $whereSql
            ORDER BY o.created_at DESC
            LIMIT 2000
        ");
        $orderStmt->execute($whereParams);
        $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orders as $o) {
            $health = getRestaurantOrderHealth($pdo, $o);
            $orderHealth[(int)$o['id']] = $health;
            if (!$health['is_balanced']) {
                $healthSummary['unbalanced']++;
            }
            if (($o['order_type'] ?? '') === 'room_service' && empty($o['booking_id'])) {
                $healthSummary['room_service_unlinked']++;
            }
        }

        // Optional health filter (post-query because health is computed in PHP).
        if ($filterHealth === 'review') {
            $orders = array_values(array_filter($orders, function ($o) use ($orderHealth) {
                $h = $orderHealth[(int)$o['id']] ?? null;
                return $h && empty($h['is_balanced']);
            }));
        }

        // ── Review detail data for unbalanced orders ────────────────
        $canReconcile  = in_array($user['role'] ?? '', ['admin', 'manager'], true);
        $unbalancedIds = array_keys(array_filter($orderHealth, static fn(array $h): bool => empty($h['is_balanced'])));
        if (!empty($unbalancedIds)) {
            $auditPlaceholders = implode(',', array_fill(0, count($unbalancedIds), '?'));
            $auditQry = $pdo->prepare(
                "SELECT order_id, actor_name, event, details, created_at
                 FROM stock_order_audit
                 WHERE order_id IN ($auditPlaceholders)
                 ORDER BY created_at ASC"
            );
            $auditQry->execute($unbalancedIds);
            $auditByOrder = [];
            foreach ($auditQry->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                $auditByOrder[(int)$ar['order_id']][] = $ar;
            }
            foreach ($orders as $rdO) {
                $oid = (int)$rdO['id'];
                $h   = $orderHealth[$oid] ?? ['is_balanced' => true, 'issues' => []];
                if (!empty($h['is_balanced'])) continue;
                $reviewData[$oid] = [
                    'id'                   => $oid,
                    'reference'            => $rdO['reference'],
                    'status'               => $rdO['status'],
                    'order_type'           => $rdO['order_type'],
                    'total_amount'         => (float)$rdO['total_amount'],
                    'payment_method'       => $rdO['payment_method'] ?? '',
                    'created_at'           => $rdO['created_at'],
                    'created_by_name'      => $rdO['created_by_name'] ?? '',
                    'issues'               => $h['issues'],
                    'line_sum'             => $h['line_sum'],
                    'expected_total'       => $h['expected_total'],
                    'payment_sum'          => $h['payment_sum'],
                    'active_payment_count' => $h['active_payment_count'],
                    'active_folio_charges' => $h['active_folio_charges'],
                    'audit'                => $auditByOrder[$oid] ?? [],
                ];
            }
        }

        // Today's shift summary (for drawer reconciliation visibility — anti-cheat).
        // Tender split reads per-leg from stock_order_splits for split orders: the order row's
        // payment_method only ever holds the LAST leg's tender, so a mixed-tender split used to
        // report its whole value under one tender — in the very figure meant to catch drawer
        // discrepancies. Window is the trading window, not DATE(created_at)=CURDATE(), so
        // after-midnight trading counts against the session it belongs to.
        $soShiftWindow = rh_station_union_business_window();
        $shiftStmt = $pdo->prepare("
            SELECT
                COUNT(*) AS orders_today,
                COALESCE(SUM(CASE WHEN status='paid' THEN total_amount ELSE 0 END), 0) AS revenue_today,
                COALESCE(SUM(CASE
                    WHEN status='paid' AND COALESCE(split_count,1) <= 1 AND payment_method='cash' THEN total_amount
                    WHEN status='paid' AND COALESCE(split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method='cash' THEN s.split_amount ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = stock_orders.id), 0)
                    ELSE 0 END), 0) AS cash_today,
                COALESCE(SUM(CASE
                    WHEN status='paid' AND COALESCE(split_count,1) <= 1 AND payment_method='mobile_money' THEN total_amount
                    WHEN status='paid' AND COALESCE(split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method='mobile_money' THEN s.split_amount ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = stock_orders.id), 0)
                    ELSE 0 END), 0) AS mobile_today,
                COALESCE(SUM(CASE
                    WHEN status='paid' AND COALESCE(split_count,1) <= 1 AND payment_method IN ('card_manual','card_pos') THEN total_amount
                    WHEN status='paid' AND COALESCE(split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method IN ('card_manual','card_pos') THEN s.split_amount ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = stock_orders.id), 0)
                    ELSE 0 END), 0) AS card_today,
                COALESCE(SUM(CASE WHEN status='voided' THEN total_amount ELSE 0 END), 0) AS voided_today,
                SUM(CASE WHEN status='voided' THEN 1 ELSE 0 END) AS void_count_today
            FROM stock_orders
            WHERE created_at >= ? AND created_at < ?
        ");
        $shiftStmt->execute([$soShiftWindow['start_sql'], $soShiftWindow['end_sql']]);
        $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $opsStmt = $pdo->query("
            SELECT
                SUM(CASE WHEN status = 'placed' THEN 1 ELSE 0 END) AS open_orders,
                SUM(CASE WHEN status = 'placed' AND order_type = 'room_service' THEN 1 ELSE 0 END) AS open_room_service,
                SUM(CASE WHEN status = 'placed' AND order_type <> 'room_service' THEN 1 ELSE 0 END) AS open_walk_in,
                SUM(CASE WHEN status = 'placed' AND kitchen_status IN ('new', 'pending') THEN 1 ELSE 0 END) AS awaiting_prep,
                SUM(CASE WHEN status = 'placed' AND kitchen_status IN ('in_progress', 'preparing') THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN status = 'placed' AND kitchen_status IN ('ready', 'collection') THEN 1 ELSE 0 END) AS ready_to_serve,
                SUM(CASE WHEN status = 'placed' AND kitchen_status = 'served' THEN 1 ELSE 0 END) AS awaiting_payment,
                SUM(CASE WHEN status IN ('paid', 'completed') AND DATE(COALESCE(paid_at, updated_at, created_at)) = CURDATE() THEN 1 ELSE 0 END) AS settled_today,
                SUM(CASE WHEN status = 'cancelled' AND DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END) AS cancelled_today,
                SUM(CASE WHEN status = 'voided' AND DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END) AS voided_today,
                COUNT(DISTINCT CASE WHEN status = 'placed' AND order_type <> 'room_service' AND table_number IS NOT NULL AND TRIM(table_number) <> '' THEN table_number END) AS active_tables,
                COUNT(DISTINCT CASE WHEN status = 'placed' AND order_type = 'room_service' AND table_number IS NOT NULL AND TRIM(table_number) <> '' THEN table_number END) AS active_rooms
            FROM stock_orders
        ");
        $operationsSnapshot = array_merge($operationsSnapshot, ($opsStmt->fetch(PDO::FETCH_ASSOC) ?: []));
        $ordersToday = (float)($shift['orders_today'] ?? 0);
        $operationsSnapshot['avg_ticket_today'] = $ordersToday > 0 ? round(((float)($shift['revenue_today'] ?? 0)) / $ordersToday, 2) : 0;

        // Revenue by cashier today (settled orders)
        $cashierStats = $pdo->query("
            SELECT
                COALESCE(u.full_name, 'Unknown') AS cashier,
                COUNT(CASE WHEN o.status IN ('paid','completed') THEN 1 END) AS orders_settled,
                COALESCE(SUM(CASE WHEN o.status IN ('paid','completed') THEN o.total_amount ELSE 0 END), 0) AS revenue,
                COUNT(CASE WHEN o.status = 'placed' THEN 1 END) AS orders_open
            FROM stock_orders o
            LEFT JOIN admin_users u ON u.id = o.created_by
            WHERE DATE(o.created_at) = CURDATE()
            GROUP BY o.created_by, u.full_name
            ORDER BY revenue DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stationQueueStmt = $pdo->query("
            SELECT
                soi.station,
                SUM(CASE WHEN soi.kds_status = 'pending' THEN 1 ELSE 0 END) AS pending_lines,
                SUM(CASE WHEN soi.kds_status IN ('preparing', 'in_progress') THEN 1 ELSE 0 END) AS in_progress_lines,
                SUM(CASE WHEN soi.kds_status IN ('ready', 'collection') THEN 1 ELSE 0 END) AS ready_lines,
                SUM(CASE WHEN soi.kds_status = 'served' THEN 1 ELSE 0 END) AS served_lines,
                SUM(CASE WHEN soi.kds_status = 'void' THEN 1 ELSE 0 END) AS void_lines,
                COUNT(*) AS total_lines
            FROM stock_order_items soi
            INNER JOIN stock_orders o ON o.id = soi.order_id
            WHERE o.status = 'placed'
              AND soi.station IS NOT NULL AND soi.station != ''
            GROUP BY soi.station
            ORDER BY FIELD(soi.station, 'kitchen', 'bar', 'coffee_bar'), soi.station
        ");
        $stationQueue = $stationQueueStmt->fetchAll(PDO::FETCH_ASSOC);

        // Detailed items per station — used by station-detail modals
        $stationItemsStmt = $pdo->query("
            SELECT
                soi.id AS item_id,
                soi.station,
                soi.item_name,
                soi.quantity,
                soi.kds_status,
                soi.notes,
                soi.menu_type,
                o.id AS order_id,
                o.reference AS order_reference,
                o.order_type,
                o.table_number,
                o.created_at AS order_created_at
            FROM stock_order_items soi
            INNER JOIN stock_orders o ON o.id = soi.order_id
            WHERE o.status = 'placed'
              AND soi.station IS NOT NULL AND soi.station != ''
              AND soi.kds_status NOT IN ('served', 'void', 'cancelled')
            ORDER BY soi.station,
                     FIELD(soi.kds_status, 'pending', 'in_progress', 'preparing', 'ready', 'collection'),
                     o.created_at ASC
        ");
        $stationItems = $stationItemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $paymentMixStmt = $pdo->query("
            SELECT
                COALESCE(NULLIF(payment_method, ''), 'unassigned') AS payment_method,
                COUNT(*) AS orders_count,
                COALESCE(SUM(total_amount), 0) AS amount_total
            FROM stock_orders
            WHERE DATE(created_at) = CURDATE()
              AND status IN ('paid', 'completed')
            GROUP BY COALESCE(NULLIF(payment_method, ''), 'unassigned')
            ORDER BY amount_total DESC
        ");
        $paymentMixToday = $paymentMixStmt->fetchAll(PDO::FETCH_ASSOC);

        $topItemsStmt = $pdo->query("
            SELECT
                soi.item_name,
                soi.menu_type,
                SUM(soi.quantity) AS quantity_total,
                SUM(soi.line_total) AS revenue_total,
                SUM(CASE WHEN soi.kds_status IN ('served', 'ready', 'collection') THEN soi.quantity ELSE 0 END) AS progressed_qty
            FROM stock_order_items soi
            INNER JOIN stock_orders o ON o.id = soi.order_id
            WHERE DATE(o.created_at) = CURDATE()
              AND o.status NOT IN ('cancelled', 'voided')
            GROUP BY soi.item_name, soi.menu_type
            ORDER BY quantity_total DESC, revenue_total DESC
            LIMIT 10
        ");
        $topSellingItemsToday = $topItemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Hourly activity breakdown today (orders + settled revenue per hour)
        $hourlyStmt = $pdo->query("
            SELECT
                HOUR(created_at) AS hour,
                COUNT(*) AS orders_count,
                COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END), 0) AS revenue
            FROM stock_orders
            WHERE DATE(created_at) = CURDATE()
            GROUP BY HOUR(created_at)
            ORDER BY hour
        ");
        $hourlyBreakdown = $hourlyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Order channel / type split today
        $channelStmt = $pdo->query("
            SELECT
                COALESCE(NULLIF(TRIM(order_type), ''), 'restaurant') AS order_type,
                COUNT(*) AS orders_count,
                COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END), 0) AS revenue,
                SUM(CASE WHEN status NOT IN ('cancelled','voided') THEN 1 ELSE 0 END) AS active_count
            FROM stock_orders
            WHERE DATE(created_at) = CURDATE()
            GROUP BY COALESCE(NULLIF(TRIM(order_type), ''), 'restaurant')
            ORDER BY revenue DESC
        ");
        $orderTypeSplit = $channelStmt->fetchAll(PDO::FETCH_ASSOC);

        // Yesterday totals for delta comparison
        $ydayStmt = $pdo->query("
            SELECT
                COUNT(*) AS orders_yesterday,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) AS revenue_yesterday,
                COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END), 0) AS settled_revenue_yesterday
            FROM stock_orders
            WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ");
        $yesterday = $ydayStmt->fetch(PDO::FETCH_ASSOC)
            ?: ['orders_yesterday' => 0, 'revenue_yesterday' => 0, 'settled_revenue_yesterday' => 0];

        // Void reasons today (for manager review)
        $voidStmt = $pdo->query("
            SELECT
                COALESCE(NULLIF(TRIM(o.void_reason), ''), 'No reason given') AS reason,
                COUNT(*) AS count,
                COALESCE(SUM(o.total_amount), 0) AS amount_total
            FROM stock_orders o
            WHERE o.status = 'voided'
              AND DATE(o.updated_at) = CURDATE()
            GROUP BY COALESCE(NULLIF(TRIM(o.void_reason), ''), 'No reason given')
            ORDER BY count DESC
            LIMIT 5
        ");
        $voidReasonsToday = $voidStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = 'Failed to load: ' . $e->getMessage();
    }
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Restaurant Orders — Stock Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-orders.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-orders.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-receipt" style="color:#8B7355;"></i> Restaurant Orders</h2>
        </div>

        <?php if ($message): showAlert($message, 'success');
        endif; ?>
        <?php if ($error):   showAlert($error,   'error');
        endif; ?>

        <!-- Today's shift bar (anti-cheat: visible totals = manager can reconcile drawer).
             Each card is a clickable filter that scopes the orders list below. -->
        <div class="shift-bar">
            <a class="shift-card<?php echo ($filterDate === 'today' && !$filterMethod && !$filterOrderType && $filterHour === '' && !$filterStatus && $filterHealth !== 'review' && !$filterKitchenStatus) ? ' active' : ''; ?>" href="stock-orders.php?date=today" title="Show today's orders">
                <div class="lbl">Orders Today</div>
                <div class="val"><?php echo (int)($shift['orders_today'] ?? 0); ?></div>
            </a>
            <a class="shift-card" href="accounting-dashboard.php" title="Open accounting dashboard">
                <div class="lbl">Revenue Today</div>
                <div class="val"><?php echo $currency_symbol . ' ' . number_format((float)($shift['revenue_today'] ?? 0), 2); ?></div>
            </a>
            <a class="shift-card<?php echo ($filterMethod === 'cash') ? ' active' : ''; ?>" href="stock-orders.php?date=today&payment_method=cash" title="Today's cash orders">
                <div class="lbl">Cash</div>
                <div class="val"><?php echo $currency_symbol . ' ' . number_format((float)($shift['cash_today'] ?? 0), 2); ?></div>
            </a>
            <a class="shift-card<?php echo ($filterMethod === 'mobile_money') ? ' active' : ''; ?>" href="stock-orders.php?date=today&payment_method=mobile_money" title="Today's mobile-money orders">
                <div class="lbl">Mobile Money</div>
                <div class="val"><?php echo $currency_symbol . ' ' . number_format((float)($shift['mobile_today'] ?? 0), 2); ?></div>
            </a>
            <a class="shift-card<?php echo ($filterMethod === 'card_manual') ? ' active' : ''; ?>" href="stock-orders.php?date=today&payment_method=card_manual" title="Today's card orders">
                <div class="lbl">Card</div>
                <div class="val"><?php echo $currency_symbol . ' ' . number_format((float)($shift['card_today'] ?? 0), 2); ?></div>
            </a>
            <a class="shift-card<?php echo ((int)($shift['void_count_today'] ?? 0) > 0) ? ' warn' : ''; ?><?php echo ($filterStatus === 'voided') ? ' active' : ''; ?>" href="stock-orders.php?date=today&status=voided" title="Today's voided orders">
                <div class="lbl">Voids</div>
                <div class="val"><?php echo (int)($shift['void_count_today'] ?? 0); ?> · <?php echo $currency_symbol . ' ' . number_format((float)($shift['voided_today'] ?? 0), 2); ?></div>
            </a>
            <a class="shift-card<?php echo ((int)$healthSummary['unbalanced'] > 0) ? ' warn' : ''; ?><?php echo ($filterHealth === 'review') ? ' active' : ''; ?>" href="stock-orders.php?health=review" title="Orders that need manager review">
                <div class="lbl">Needs Review</div>
                <div class="val"><?php echo (int)$healthSummary['unbalanced']; ?></div>
            </a>
            <a href="stock-count.php" class="shift-card" style="background:#fff7e6; border-color:#ffc107;" title="Run end-of-shift stock count">
                <div class="lbl"><i class="fas fa-clipboard-check"></i> End-of-Shift</div>
                <div class="val" style="font-size:14px;">Run Count &rarr;</div>
            </a>
        </div>

        <?php if ($hasActiveFilter): ?>
            <div class="filter-bar">
                <i class="fas fa-filter"></i>
                <span>Filters:</span>
                <?php if ($filterDate === 'today'): ?><span><strong>Today</strong></span><?php endif; ?>
                <?php if ($filterMethod): ?>
                    <?php $filterMethodLabel = $filterMethod === 'unassigned' ? 'Unassigned' : ucwords(str_replace('_', ' ', $filterMethod)); ?>
                    <span>Method: <strong><?php echo htmlspecialchars($filterMethodLabel); ?></strong></span>
                <?php endif; ?>
                <?php if ($filterOrderType): ?>
                    <?php
                    $orderTypeLabels = [
                        'walk_in' => 'Walk-In',
                        'dine_in' => 'Dine-In',
                        'room_service' => 'Room Service',
                    ];
                    ?>
                    <span>Channel: <strong><?php echo htmlspecialchars($orderTypeLabels[$filterOrderType] ?? ucwords(str_replace('_', ' ', $filterOrderType))); ?></strong></span>
                <?php endif; ?>
                <?php if ($filterHour !== ''): ?>
                    <?php
                    $filterHourStart = (int)$filterHour;
                    $filterHourEnd = ($filterHourStart + 1) % 24;
                    $formatHourLabel = static function (int $hour): string {
                        return $hour === 0 ? '12am' : ($hour < 12 ? $hour . 'am' : ($hour === 12 ? '12pm' : ($hour - 12) . 'pm'));
                    };
                    ?>
                    <span>Hour: <strong><?php echo htmlspecialchars($formatHourLabel($filterHourStart) . ' - ' . $formatHourLabel($filterHourEnd)); ?></strong></span>
                <?php endif; ?>
                <?php if ($filterStatus): ?><span>Status: <strong><?php echo htmlspecialchars(ucfirst($filterStatus)); ?></strong></span><?php endif; ?>
                <?php if ($filterKitchenStatus): ?>
                    <?php
                    $ksLabels = ['awaiting_prep' => 'Awaiting Prep', 'in_progress_all' => 'In Progress', 'ready_all' => 'Ready to Serve'];
                    $ksDisplay = $ksLabels[$filterKitchenStatus] ?? ucwords(str_replace('_', ' ', $filterKitchenStatus));
                    ?>
                    <span>Kitchen: <strong><?php echo htmlspecialchars($ksDisplay); ?></strong></span>
                <?php endif; ?>
                <?php if ($filterHealth === 'review'): ?><span><strong>Needs review</strong></span><?php endif; ?>
                <span style="margin-left:auto;"><a class="clear" href="stock-orders.php"><i class="fas fa-times"></i> Clear filters</a></span>
            </div>
        <?php endif; ?>

        <?php if ((int)$healthSummary['unbalanced'] > 0): ?>
            <a class="ops-banner ops-banner--warn" href="stock-orders.php?health=review#ops-insights" title="Open the review queue for orders needing attention">
                <div class="ops-banner__content">
                    <strong><i class="fas fa-triangle-exclamation"></i> Manager review needed:</strong>
                    <?php echo (int)$healthSummary['unbalanced']; ?> order(s) have accounting, folio, or room-service balance checks to review.
                    <?php if ((int)$healthSummary['room_service_unlinked'] > 0): ?>
                        <div style="font-size:12px;color:#721c24;margin-top:4px;">Room service without a booking link: <?php echo (int)$healthSummary['room_service_unlinked']; ?> legacy order(s). New room-service orders now require a checked-in room.</div>
                    <?php endif; ?>
                </div>
                <span class="ops-banner__action">Open review queue <i class="fas fa-arrow-right"></i></span>
            </a>
        <?php else: ?>
            <div class="ops-banner ops-banner--ok">
                <div class="ops-banner__content">
                    <strong><i class="fas fa-circle-check" style="color:#155724;"></i> Order health:</strong> recent restaurant orders are balanced against items, payments, and room-service folios.
                </div>
                <a class="ops-banner__action" href="stock-orders.php?date=today#ops-insights">View today&apos;s flow <i class="fas fa-arrow-right"></i></a>
            </div>
        <?php endif; ?>

        <section aria-label="Stock orders operational insights" id="ops-insights">

            <?php
            // Pre-compute deltas and helpers before rendering
            $revToday  = (float)($shift['revenue_today'] ?? 0);
            $revYday   = (float)($yesterday['revenue_yesterday'] ?? 0);
            $revDelta  = $revYday > 0 ? round((($revToday - $revYday) / $revYday) * 100, 1) : null;

            $ordToday  = (int)($shift['orders_today'] ?? 0);
            $ordYday   = (int)($yesterday['orders_yesterday'] ?? 0);
            $ordDelta  = $ordYday > 0 ? round((($ordToday - $ordYday) / $ordYday) * 100, 1) : null;

            $totalChannelRevenue = array_sum(array_column($orderTypeSplit, 'revenue')) ?: 1;
            $totalPaymentRevenue = (float)($shift['revenue_today'] ?? 0) ?: 1;

            $maxHourRev = !empty($hourlyBreakdown) ? max(array_column($hourlyBreakdown, 'revenue')) : 1;
            $peakHour   = null;
            if (!empty($hourlyBreakdown)) {
                usort($hourlyBreakdown, fn(array $a, array $b): int => (int)$b['revenue'] - (int)$a['revenue']);
                $peakHour = $hourlyBreakdown[0] ?? null;
                usort($hourlyBreakdown, fn(array $a, array $b): int => (int)$a['hour'] - (int)$b['hour']);
            }

            $totalVoidedToday = array_sum(array_column($voidReasonsToday, 'count'));
            $totalVoidedAmt   = array_sum(array_column($voidReasonsToday, 'amount_total'));

            function insightsDelta(?float $delta): string
            {
                if ($delta === null) return '<span style="font-size:11px;color:#aaa;">No prior data</span>';
                $color = $delta >= 0 ? '#155724' : '#721c24';
                $arrow = $delta >= 0 ? '▲' : '▼';
                return '<span style="font-size:11px;color:' . $color . ';font-weight:600;">'
                    . $arrow . ' ' . abs($delta) . '% vs yesterday</span>';
            }
            ?>

            <!-- ── Insights header ─────────────────────────────────── -->
            <div style="display:flex;align-items:center;gap:12px;margin:8px 0 16px;flex-wrap:wrap;">
                <h3 style="margin:0;flex:1;">Operational Insights</h3>
                <span id="insight-refresh-timer" style="display:none;"></span>
                <button onclick="window.location.href=window.location.href.split('#')[0]+'#ops-insights'"
                    style="background:#8B7355;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:13px;cursor:pointer;display:flex;align-items:center;gap:6px;"
                    title="Refresh page and return to insights">
                    <i class="fas fa-rotate-right"></i> Refresh
                </button>
            </div>

            <!-- ── KPI Row with yesterday deltas ──────────────────── -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin-bottom:16px;">

                <div class="shift-card shift-card--interactive js-stock-orders-insight-trigger"
                    style="flex-direction:column;align-items:flex-start;"
                    role="button"
                    tabindex="0"
                    data-insight-key="revenue-today"
                    data-insight-title="Revenue Today Breakdown"
                    aria-label="Open revenue today breakdown">
                    <div class="lbl">Revenue Today</div>
                    <div class="val" style="font-size:22px;"><?php echo $currency_symbol . ' ' . number_format($revToday, 2); ?></div>
                    <?php echo insightsDelta($revDelta); ?>
                    <div style="font-size:11px;color:#6c757d;margin-top:3px;">Yesterday: <?php echo $currency_symbol . ' ' . number_format($revYday, 2); ?></div>
                    <div class="shift-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>

                <div class="shift-card shift-card--interactive js-stock-orders-insight-trigger"
                    style="flex-direction:column;align-items:flex-start;"
                    role="button"
                    tabindex="0"
                    data-insight-key="orders-today"
                    data-insight-title="Orders Today Throughput"
                    aria-label="Open orders today throughput">
                    <div class="lbl">Orders Today</div>
                    <div class="val" style="font-size:22px;"><?php echo $ordToday; ?></div>
                    <?php echo insightsDelta($ordDelta); ?>
                    <div style="font-size:11px;color:#6c757d;margin-top:3px;">Yesterday: <?php echo $ordYday; ?></div>
                    <div class="shift-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>

                <div class="shift-card shift-card--interactive js-stock-orders-insight-trigger"
                    style="flex-direction:column;align-items:flex-start;"
                    role="button"
                    tabindex="0"
                    data-insight-key="avg-ticket"
                    data-insight-title="Average Ticket Quality"
                    aria-label="Open average ticket quality">
                    <div class="lbl">Avg Ticket</div>
                    <div class="val" style="font-size:22px;"><?php echo $currency_symbol . ' ' . number_format((float)($operationsSnapshot['avg_ticket_today'] ?? 0), 2); ?></div>
                    <div style="font-size:11px;color:#6c757d;margin-top:3px;">Across <?php echo (int)($operationsSnapshot['settled_today'] ?? 0); ?> settled today</div>
                    <div class="shift-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>

                <div class="shift-card<?php echo ((int)($operationsSnapshot['awaiting_payment'] ?? 0) > 0) ? ' warn' : ''; ?>"
                    style="flex-direction:column;align-items:flex-start;cursor:pointer;"
                    onclick="window.location.href='stock-orders.php?status=placed'"
                    role="link" tabindex="0"
                    title="View all open orders">
                    <div class="lbl">Live Open Orders</div>
                    <div class="val" style="font-size:22px;"><?php echo (int)($operationsSnapshot['open_orders'] ?? 0); ?></div>
                    <div style="font-size:11px;color:#6c757d;margin-top:3px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <a href="stock-orders.php?status=placed&order_type=walk_in"
                            style="color:inherit;text-decoration:none;white-space:nowrap;"
                            title="View walk-in / dine-in orders"
                            onclick="event.stopPropagation();">
                            <i class="fas <?php echo isRestaurantEnabled() ? 'fa-utensils' : 'fa-receipt'; ?>" style="font-size:9px;"></i>
                            Walk-in: <strong><?php echo (int)($operationsSnapshot['open_walk_in'] ?? 0); ?></strong>
                        </a>
                        <span style="color:#ced4da;">&middot;</span>
                        <a href="stock-orders.php?status=placed&order_type=room_service"
                            style="color:inherit;text-decoration:none;white-space:nowrap;"
                            title="View room service orders"
                            onclick="event.stopPropagation();">
                            <i class="fas fa-bed" style="font-size:9px;"></i>
                            Room svc: <strong><?php echo (int)($operationsSnapshot['open_room_service'] ?? 0); ?></strong>
                        </a>
                    </div>
                    <div style="font-size:10px;color:#adb5bd;margin-top:2px;">
                        <?php echo (int)($operationsSnapshot['active_tables'] ?? 0); ?> tables &middot;
                        <?php echo (int)($operationsSnapshot['active_rooms'] ?? 0); ?> rooms occupied
                    </div>
                </div>

                <div class="shift-card shift-card--interactive js-stock-orders-insight-trigger"
                    style="flex-direction:column;align-items:flex-start;"
                    role="button"
                    tabindex="0"
                    data-insight-key="cash-collected"
                    data-insight-title="Cash Collection Summary"
                    aria-label="Open cash collection summary">
                    <div class="lbl">Cash Collected</div>
                    <div class="val" style="font-size:22px;"><?php echo $currency_symbol . ' ' . number_format((float)($shift['cash_today'] ?? 0), 2); ?></div>
                    <div style="font-size:11px;color:#6c757d;margin-top:3px;">Today's cash drawer total</div>
                    <div class="shift-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>

                <div class="shift-card shift-card--interactive js-stock-orders-insight-trigger"
                    style="flex-direction:column;align-items:flex-start;"
                    role="button"
                    tabindex="0"
                    data-insight-key="mobile-money"
                    data-insight-title="Mobile Money Collection"
                    aria-label="Open mobile money collection">
                    <div class="lbl">Mobile Money</div>
                    <div class="val" style="font-size:22px;"><?php echo $currency_symbol . ' ' . number_format((float)($shift['mobile_today'] ?? 0), 2); ?></div>
                    <div style="font-size:11px;color:#6c757d;margin-top:3px;">Today's mobile transactions</div>
                    <div class="shift-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>

                <div class="shift-card shift-card--interactive js-stock-orders-insight-trigger"
                    style="flex-direction:column;align-items:flex-start;"
                    role="button"
                    tabindex="0"
                    data-insight-key="card-payments"
                    data-insight-title="Card Payments Summary"
                    aria-label="Open card payments summary">
                    <div class="lbl">Card Payments</div>
                    <div class="val" style="font-size:22px;"><?php echo $currency_symbol . ' ' . number_format((float)($shift['card_today'] ?? 0), 2); ?></div>
                    <div style="font-size:11px;color:#6c757d;margin-top:3px;">Card manual + POS</div>
                    <div class="shift-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>

                <div class="shift-card shift-card--interactive js-stock-orders-insight-trigger<?php echo $totalVoidedToday > 0 ? ' warn' : ''; ?>"
                    style="flex-direction:column;align-items:flex-start;"
                    role="button"
                    tabindex="0"
                    data-insight-key="voids-today"
                    data-insight-title="Voids Monitoring"
                    aria-label="Open voids monitoring">
                    <div class="lbl">Voids Today</div>
                    <div class="val" style="font-size:22px;"><?php echo $totalVoidedToday; ?></div>
                    <div style="font-size:11px;color:#6c757d;margin-top:3px;">
                        <?php echo $totalVoidedToday > 0
                            ? $currency_symbol . ' ' . number_format($totalVoidedAmt, 2) . ' lost'
                            : 'No voids — clean shift'; ?>
                    </div>
                    <div class="shift-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>

                <?php if ($peakHour): ?>
                    <div class="shift-card shift-card--interactive js-stock-orders-insight-trigger"
                        style="flex-direction:column;align-items:flex-start;background:#fff7e6;border-color:#ffc107;"
                        role="button"
                        tabindex="0"
                        data-insight-key="peak-hour"
                        data-insight-title="Peak Hour Snapshot"
                        aria-label="Open peak hour snapshot">
                        <div class="lbl">Peak Hour</div>
                        <?php
                        $ph = (int)$peakHour['hour'];
                        $phLabel = $ph === 0 ? '12am' : ($ph < 12 ? $ph . 'am' : ($ph === 12 ? '12pm' : ($ph - 12) . 'pm'));
                        ?>
                        <div class="val" style="font-size:22px;"><?php echo htmlspecialchars($phLabel); ?></div>
                        <div style="font-size:11px;color:#6c757d;margin-top:3px;">
                            <?php echo (int)$peakHour['orders_count']; ?> orders &middot;
                            <?php echo $currency_symbol . ' ' . number_format((float)$peakHour['revenue'], 2); ?>
                        </div>
                        <div class="shift-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($cashierStats)): ?>
                    <div class="shift-card shift-card--interactive js-stock-orders-insight-trigger"
                        style="flex-direction:column;align-items:flex-start;min-width:180px;"
                        role="button"
                        tabindex="0"
                        data-insight-key="cashier-performance"
                        data-insight-title="Cashier Performance Snapshot"
                        aria-label="Open cashier performance snapshot">
                        <div class="lbl"><i class="fas fa-user-check" style="color:#8B7355;font-size:11px;"></i> Revenue by Cashier</div>
                        <div style="margin-top:6px;width:100%;display:flex;flex-direction:column;gap:5px;">
                            <?php foreach ($cashierStats as $cs):
                                if ((float)($cs['revenue'] ?? 0) == 0 && (int)($cs['orders_open'] ?? 0) == 0) continue;
                            ?>
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                    <span style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:110px;" title="<?php echo htmlspecialchars($cs['cashier']); ?>">
                                        <?php echo htmlspecialchars($cs['cashier']); ?>
                                    </span>
                                    <span style="font-size:11px;white-space:nowrap;color:#495057;">
                                        <?php echo $currency_symbol . ' ' . number_format((float)($cs['revenue'] ?? 0), 0); ?>
                                        <?php if ((int)($cs['orders_open'] ?? 0) > 0): ?>
                                            <span style="color:#dc3545;font-size:10px;" title="Open orders"> · <?php echo (int)$cs['orders_open']; ?> open</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="shift-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
                    </div>
                <?php endif; ?>

            </div>

            <!-- ── Order Pipeline ─────────────────────────────────── -->
            <div class="ops-panel ops-panel--pipeline" style="margin:0 0 14px;padding:16px 20px;">
                <div class="ops-panel__header">
                    <strong class="ops-panel__header-title">
                        <i class="fas fa-arrow-right-arrow-left" style="color:#8B7355;"></i>
                        Live Order Pipeline
                    </strong>
                    <span class="ops-panel__header-note">Click any stage to filter the orders list</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:6px;align-items:center;">
                    <?php
                    $pipeline = [
                        ['label' => 'Awaiting Prep',     'val' => (int)($operationsSnapshot['awaiting_prep'] ?? 0),    'icon' => 'fa-hourglass-start', 'color' => '#6c757d', 'link' => 'stock-orders.php?status=placed&kitchen_status=awaiting_prep',  'stage_key' => 'awaiting_prep'],
                        ['label' => 'In Progress',       'val' => (int)($operationsSnapshot['in_progress'] ?? 0),      'icon' => 'fa-fire',            'color' => '#fd7e14', 'link' => 'stock-orders.php?status=placed&kitchen_status=in_progress_all', 'stage_key' => 'in_progress_all'],
                        ['label' => 'Ready to Serve',    'val' => (int)($operationsSnapshot['ready_to_serve'] ?? 0),   'icon' => 'fa-bell',            'color' => '#0d6efd', 'link' => 'stock-orders.php?status=placed&kitchen_status=ready_all',        'stage_key' => 'ready_all'],
                        ['label' => 'Awaiting Payment',  'val' => (int)($operationsSnapshot['awaiting_payment'] ?? 0), 'icon' => 'fa-credit-card',     'color' => '#dc3545', 'link' => 'stock-orders.php?status=placed&kitchen_status=served',            'stage_key' => 'served'],
                        ['label' => 'Settled Today',     'val' => (int)($operationsSnapshot['settled_today'] ?? 0),    'icon' => 'fa-circle-check',    'color' => '#198754', 'link' => 'stock-orders.php?date=today&status=paid',                        'stage_key' => 'settled'],
                    ];
                    foreach ($pipeline as $idx => $stage):
                        $warn = ($stage['label'] === 'Awaiting Payment' && $stage['val'] > 0);
                        $isActiveStage = (
                            ($stage['stage_key'] === 'awaiting_prep'   && $filterKitchenStatus === 'awaiting_prep') ||
                            ($stage['stage_key'] === 'in_progress_all' && $filterKitchenStatus === 'in_progress_all') ||
                            ($stage['stage_key'] === 'ready_all'       && $filterKitchenStatus === 'ready_all') ||
                            ($stage['stage_key'] === 'served'          && $filterKitchenStatus === 'served') ||
                            ($stage['stage_key'] === 'settled'         && $filterDate === 'today' && $filterStatus === 'paid')
                        );
                        $bgColor     = $isActiveStage ? '#e8f4fd' : ($warn ? '#fff3cd' : '#f8f9fa');
                        $borderColor = $isActiveStage ? '#0d6efd' : ($warn ? '#ffc107' : '#dee2e6');
                        $borderWidth = $isActiveStage ? '2px' : '1px';
                    ?>
                        <a href="<?php echo htmlspecialchars($stage['link'], ENT_QUOTES, 'UTF-8'); ?>"
                            title="View <?php echo htmlspecialchars($stage['label']); ?> orders"
                            style="text-align:center;padding:10px 8px;background:<?php echo $bgColor; ?>;border-radius:8px;border:<?php echo $borderWidth; ?> solid <?php echo $borderColor; ?>;text-decoration:none;display:block;transition:transform .15s,box-shadow .15s;"
                            onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,.12)'"
                            onmouseout="this.style.transform='';this.style.boxShadow=''">
                            <i class="fas <?php echo $stage['icon']; ?>" style="color:<?php echo $stage['color']; ?>;font-size:18px;"></i>
                            <div style="font-size:24px;font-weight:700;color:<?php echo $stage['color']; ?>;line-height:1.2;margin-top:4px;">
                                <?php echo $stage['val']; ?>
                            </div>
                            <div style="font-size:11px;color:#6c757d;line-height:1.3;"><?php echo htmlspecialchars($stage['label']); ?></div>
                            <?php if ($isActiveStage): ?><div style="font-size:9px;margin-top:3px;color:#0d6efd;font-weight:700;">● Active Filter</div><?php endif; ?>
                        </a>
                        <?php if ($idx < count($pipeline) - 1): ?>
                            <div style="text-align:center;font-size:20px;color:#ced4da;">&rarr;</div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── Three-column row: Station Queue | Payment Mix | Channel Split ── -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin:0 0 14px;">

                <!-- Station Queue with load bars -->
                <div class="ops-panel ops-panel--station-queue" style="margin:0;">
                    <div class="ops-panel__header">
                        <strong class="ops-panel__header-title"><i class="fas fa-layer-group" style="color:#8B7355;"></i> Station Queue</strong>
                        <a class="ops-panel__header-link" href="stock-orders.php?status=placed#ops-insights">View open orders</a>
                    </div>
                    <div class="table-responsive" style="margin-top:10px;">
                        <table class="order-list" style="margin-top:0;">
                            <thead>
                                <tr>
                                    <th>Station</th>
                                    <th style="text-align:center;">Pending</th>
                                    <th style="text-align:center;">In Prog</th>
                                    <th style="text-align:center;">Ready</th>
                                    <th style="text-align:center;">Served</th>
                                    <th>Load</th>
                                    <th style="text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $maxStationLoad = 1;
                                foreach ($stationQueue as $sq) {
                                    $active = (int)($sq['pending_lines'] ?? 0) + (int)($sq['in_progress_lines'] ?? 0) + (int)($sq['ready_lines'] ?? 0);
                                    if ($active > $maxStationLoad) $maxStationLoad = $active;
                                }
                                foreach ($stationQueue as $sq):
                                    $stationName  = (string)($sq['station'] ?? 'unknown');
                                    $stationLabel = match ($stationName) {
                                        'kitchen'    => 'Kitchen',
                                        'bar'        => 'Bar',
                                        'coffee_bar' => 'Coffee Bar',
                                        default      => ucwords(str_replace('_', ' ', $stationName)),
                                    };
                                    $stationScreen = match ($stationName) {
                                        'kitchen'    => 'kds.php',
                                        'bar'        => 'bds.php',
                                        'coffee_bar' => 'cds.php',
                                        default      => null,
                                    };
                                    $activeLines = (int)($sq['pending_lines'] ?? 0) + (int)($sq['in_progress_lines'] ?? 0) + (int)($sq['ready_lines'] ?? 0);
                                    $stationTotalLines = (int)($sq['total_lines'] ?? 0);
                                    $stationVoidLines  = (int)($sq['void_lines'] ?? 0);
                                    $loadPct = $maxStationLoad > 0 ? round(($activeLines / $maxStationLoad) * 100) : 0;
                                    $loadColor = $loadPct >= 80 ? '#dc3545' : ($loadPct >= 50 ? '#fd7e14' : '#198754');
                                    $stationNameJson = json_encode($stationName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                    $stationLabelJson = json_encode($stationLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                ?>
                                    <tr class="ops-panel__table-row ops-panel__table-row--interactive"
                                        tabindex="0"
                                        role="button"
                                        title="Open <?php echo htmlspecialchars($stationLabel); ?> queue details"
                                        onclick='showStationItems(<?php echo $stationNameJson; ?>, <?php echo $stationLabelJson; ?>)'
                                        onkeydown='if(event.key === "Enter" || event.key === " "){event.preventDefault();showStationItems(<?php echo $stationNameJson; ?>, <?php echo $stationLabelJson; ?>);}'>
                                        <td data-label="Station">
                                            <strong><?php echo htmlspecialchars($stationLabel); ?></strong>
                                            <?php if ($stationScreen): ?>
                                                <a href="<?php echo htmlspecialchars($stationScreen, ENT_QUOTES, 'UTF-8'); ?>" target="_blank"
                                                    title="Open <?php echo htmlspecialchars($stationLabel); ?> Display"
                                                    style="margin-left:6px;color:#8B7355;font-size:11px;text-decoration:none;"
                                                    onclick="event.stopPropagation();"
                                                    onmouseover="this.style.color='#0d6efd'" onmouseout="this.style.color='#8B7355'">
                                                    <i class="fas fa-tv"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Pending" style="text-align:center;"><?php echo (int)($sq['pending_lines'] ?? 0); ?></td>
                                        <td data-label="In Progress" style="text-align:center;"><?php echo (int)($sq['in_progress_lines'] ?? 0); ?></td>
                                        <td data-label="Ready" style="text-align:center;color:#0d6efd;font-weight:<?php echo (int)($sq['ready_lines'] ?? 0) > 0 ? '700' : '400'; ?>;">
                                            <?php echo (int)($sq['ready_lines'] ?? 0); ?>
                                        </td>
                                        <td data-label="Served" style="text-align:center;color:#198754;"><?php echo (int)($sq['served_lines'] ?? 0); ?></td>
                                        <td data-label="Load" style="min-width:60px;">
                                            <div style="height:8px;background:#e9ecef;border-radius:4px;overflow:hidden;">
                                                <div style="width:<?php echo $loadPct; ?>%;height:100%;background:<?php echo $loadColor; ?>;border-radius:4px;transition:width .4s;"></div>
                                            </div>
                                            <span style="font-size:10px;color:<?php echo $loadColor; ?>;">
                                                <?php echo $activeLines; ?> active / <?php echo $stationTotalLines; ?> total<?php if ($stationVoidLines > 0): ?> &middot; <?php echo $stationVoidLines; ?> void<?php endif; ?>
                                            </span>
                                        </td>
                                        <td data-label="Actions" style="text-align:center;">
                                            <button type="button"
                                                class="mini-action"
                                                onclick='event.stopPropagation();showStationItems(<?php echo $stationNameJson; ?>, <?php echo $stationLabelJson; ?>)'
                                                title="View active items for this station"
                                                aria-label="View active items for <?php echo htmlspecialchars($stationLabel); ?>">
                                                <i class="fas fa-list-ul"></i> Items
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($stationQueue)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align:center;color:#6c757d;padding:16px;">No active station queues.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Payment Mix with visual bars -->
                <div class="ops-panel ops-panel--payment-mix" style="margin:0;">
                    <div class="ops-panel__header">
                        <strong class="ops-panel__header-title"><i class="fas fa-wallet" style="color:#8B7355;"></i> Payment Mix Today</strong>
                        <span class="ops-panel__header-note">Click a payment method to filter the orders list</span>
                    </div>
                    <div style="margin-top:12px;display:flex;flex-direction:column;gap:10px;">
                        <?php foreach ($paymentMixToday as $mix):
                            $methodKey   = (string)($mix['payment_method'] ?? 'unassigned');
                            $methodLabel = match ($methodKey) {
                                'cash'        => 'Cash',
                                'mobile_money' => 'Mobile Money',
                                'card_manual' => 'Card (Manual)',
                                'card_pos'    => 'Card POS',
                                'unassigned'  => 'Unassigned',
                                default       => ucwords(str_replace('_', ' ', $methodKey)),
                            };
                            $methodIcon = match ($methodKey) {
                                'cash'         => 'fa-money-bill',
                                'mobile_money' => 'fa-mobile-screen',
                                'card_manual'  => 'fa-credit-card',
                                'card_pos'     => 'fa-credit-card',
                                default        => 'fa-circle-question',
                            };
                            $mixAmt = (float)($mix['amount_total'] ?? 0);
                            $share  = $totalPaymentRevenue > 0 ? ($mixAmt / $totalPaymentRevenue) * 100 : 0;
                            $mixHref = 'stock-orders.php?date=today&payment_method=' . rawurlencode($methodKey) . '#ops-insights';
                            $isMixActive = $filterDate === 'today' && $filterMethod === $methodKey;
                        ?>
                            <a class="ops-panel__metric-link<?php echo $isMixActive ? ' ops-panel__metric-link--active' : ''; ?>"
                                href="<?php echo htmlspecialchars($mixHref, ENT_QUOTES, 'UTF-8'); ?>"
                                title="Filter orders paid with <?php echo htmlspecialchars($methodLabel); ?>">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                                    <span style="font-size:13px;font-weight:600;">
                                        <i class="fas <?php echo $methodIcon; ?>" style="color:#8B7355;width:16px;"></i>
                                        <?php echo htmlspecialchars($methodLabel); ?>
                                    </span>
                                    <span style="font-size:12px;color:#6c757d;">
                                        <?php echo (int)($mix['orders_count'] ?? 0); ?> orders &middot;
                                        <?php echo $currency_symbol . ' ' . number_format($mixAmt, 2); ?>
                                    </span>
                                </div>
                                <div style="height:10px;background:#e9ecef;border-radius:5px;overflow:hidden;" title="<?php echo number_format($share, 1); ?>% of today's revenue">
                                    <div style="width:<?php echo number_format($share, 1); ?>%;height:100%;background:#8B7355;border-radius:5px;transition:width .4s;"></div>
                                </div>
                                <div style="font-size:11px;color:#8B7355;font-weight:600;margin-top:2px;"><?php echo number_format($share, 1); ?>%</div>
                            </a>
                        <?php endforeach; ?>
                        <?php if (empty($paymentMixToday)): ?>
                            <p style="color:#6c757d;font-size:13px;text-align:center;padding:16px 0;">No settled payments today yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Order Channel / Type Split -->
                <div class="ops-panel ops-panel--channel-split" style="margin:0;">
                    <div class="ops-panel__header">
                        <strong class="ops-panel__header-title"><i class="fas <?php echo isRestaurantEnabled() ? 'fa-utensils' : 'fa-cash-register'; ?>" style="color:#8B7355;"></i> Order Channels Today</strong>
                        <span class="ops-panel__header-note">Click a channel to filter the orders list</span>
                    </div>
                    <div style="margin-top:12px;display:flex;flex-direction:column;gap:10px;">
                        <?php foreach ($orderTypeSplit as $ch):
                            $chType  = (string)($ch['order_type'] ?? 'restaurant');
                            $chLabel = match ($chType) {
                                'walk_in'     => 'Walk-In',
                                'dine_in'     => 'Dine-In',
                                'room_service' => 'Room Service',
                                'bar_tab'     => 'Bar Tab',
                                'takeaway'    => 'Takeaway',
                                'delivery'    => 'Delivery',
                                default       => ucwords(str_replace('_', ' ', $chType)),
                            };
                            $chIcon = match ($chType) {
                                'walk_in'      => isRestaurantEnabled() ? 'fa-chair' : 'fa-receipt',
                                'dine_in'      => 'fa-utensils',
                                'room_service' => 'fa-bell-concierge',
                                'bar_tab'      => 'fa-martini-glass',
                                'takeaway'     => 'fa-bag-shopping',
                                default        => isRestaurantEnabled() ? 'fa-utensils' : 'fa-receipt',
                            };
                            $chRev  = (float)($ch['revenue'] ?? 0);
                            $chPct  = $totalChannelRevenue > 0 ? ($chRev / $totalChannelRevenue) * 100 : 0;
                            $chHref = 'stock-orders.php?date=today&order_type=' . rawurlencode($chType) . '#ops-insights';
                            $isChannelActive = $filterDate === 'today' && $filterOrderType === $chType;
                        ?>
                            <a class="ops-panel__metric-link<?php echo $isChannelActive ? ' ops-panel__metric-link--active' : ''; ?>"
                                href="<?php echo htmlspecialchars($chHref, ENT_QUOTES, 'UTF-8'); ?>"
                                title="Filter orders for <?php echo htmlspecialchars($chLabel); ?>">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                                    <span style="font-size:13px;font-weight:600;">
                                        <i class="fas <?php echo $chIcon; ?>" style="color:#8B7355;width:16px;"></i>
                                        <?php echo htmlspecialchars($chLabel); ?>
                                    </span>
                                    <span style="font-size:12px;color:#6c757d;">
                                        <?php echo (int)($ch['orders_count'] ?? 0); ?> orders &middot;
                                        <?php echo $currency_symbol . ' ' . number_format($chRev, 2); ?> &middot;
                                        <?php echo (int)($ch['active_count'] ?? 0); ?> active
                                    </span>
                                </div>
                                <div style="height:10px;background:#e9ecef;border-radius:5px;overflow:hidden;" title="<?php echo number_format($chPct, 1); ?>% of today's revenue">
                                    <div style="width:<?php echo number_format($chPct, 1); ?>%;height:100%;background:#B18247;border-radius:5px;transition:width .4s;"></div>
                                </div>
                                <div style="font-size:11px;color:#B18247;font-weight:600;margin-top:2px;"><?php echo number_format($chPct, 1); ?>%</div>
                            </a>
                        <?php endforeach; ?>
                        <?php if (empty($orderTypeSplit)): ?>
                            <p style="color:#6c757d;font-size:13px;text-align:center;padding:16px 0;">No orders recorded today yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- ── Hourly Chart + Void Analysis ─────────────────── -->
            <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin:0 0 14px;">

                <!-- Hourly Activity Bar Chart -->
                <div class="ops-panel ops-panel--hourly-activity" style="margin:0;">
                    <div class="ops-panel__header">
                        <strong class="ops-panel__header-title"><i class="fas fa-chart-bar" style="color:#8B7355;"></i> Hourly Activity Today</strong>
                        <span class="ops-panel__header-note">Click an hour bar to filter that hour</span>
                    </div>
                    <?php if (!empty($hourlyBreakdown)): ?>
                        <div style="display:flex;align-items:flex-end;gap:4px;height:100px;margin-top:14px;padding-bottom:22px;position:relative;">
                            <?php foreach ($hourlyBreakdown as $h):
                                $isPeak = $peakHour && (int)$h['hour'] === (int)$peakHour['hour'];
                                $barPct = $maxHourRev > 0 ? round(((float)$h['revenue'] / $maxHourRev) * 78, 0) : 0;
                                $hr     = (int)$h['hour'];
                                $hlbl   = $hr === 0 ? '12a' : ($hr < 12 ? $hr . 'a' : ($hr === 12 ? '12p' : ($hr - 12) . 'p'));
                                $hourHref = 'stock-orders.php?date=today&hour=' . $hr . '#ops-insights';
                                $isHourActive = $filterDate === 'today' && $filterHour !== '' && (int)$filterHour === $hr;
                            ?>
                                <a class="ops-panel__chart-link<?php echo $isHourActive ? ' ops-panel__chart-link--active' : ''; ?>"
                                    href="<?php echo htmlspecialchars($hourHref, ENT_QUOTES, 'UTF-8'); ?>"
                                    style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100px;position:relative;"
                                    title="<?php echo $hlbl; ?>: <?php echo (int)$h['orders_count']; ?> orders · <?php echo $currency_symbol . ' ' . number_format((float)$h['revenue'], 2); ?>">
                                    <?php if ((int)$h['orders_count'] > 0): ?>
                                        <span style="font-size:9px;color:#8B7355;margin-bottom:2px;"><?php echo (int)$h['orders_count']; ?></span>
                                    <?php endif; ?>
                                    <div class="ops-panel__chart-bar" style="width:100%;background:<?php echo $isPeak ? '#B18247' : '#c8b89a'; ?>;height:<?php echo max($barPct, $h['revenue'] > 0 ? 4 : 0); ?>px;border-radius:3px 3px 0 0;min-height:<?php echo $h['revenue'] > 0 ? '4' : '0'; ?>px;transition:height .3s;"></div>
                                    <span style="font-size:9px;color:<?php echo $isPeak ? '#B18247' : '#999'; ?>;font-weight:<?php echo $isPeak ? '700' : '400'; ?>;position:absolute;bottom:0;white-space:nowrap;"><?php echo htmlspecialchars($hlbl); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <div style="font-size:11px;color:#6c757d;margin-top:4px;text-align:right;">
                            <span style="display:inline-block;width:10px;height:10px;background:#B18247;border-radius:2px;margin-right:4px;vertical-align:middle;"></span>Peak hour &nbsp;
                            <span style="display:inline-block;width:10px;height:10px;background:#c8b89a;border-radius:2px;margin-right:4px;vertical-align:middle;"></span>Other hours
                            &nbsp;(bar height = revenue, number = orders)
                        </div>
                    <?php else: ?>
                        <p style="color:#6c757d;font-size:13px;text-align:center;padding:24px 0;">No hourly data yet today.</p>
                    <?php endif; ?>
                </div>

                <!-- Void Analysis -->
                <div class="ops-panel ops-panel--void-analysis<?php echo $totalVoidedToday > 0 ? ' ops-panel--warn' : ''; ?>" style="margin:0;">
                    <div class="ops-panel__header">
                        <strong class="ops-panel__header-title">
                            <i class="fas fa-ban" style="color:<?php echo $totalVoidedToday > 0 ? '#721c24' : '#155724'; ?>;"></i>
                            Void Analysis Today
                        </strong>
                        <a class="ops-panel__header-link" href="stock-orders.php?date=today&status=voided#ops-insights">Open voided orders</a>
                    </div>
                    <?php if ($totalVoidedToday > 0): ?>
                        <div style="margin-top:10px;font-size:13px;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #f5c6cb;">
                                <span><?php echo $totalVoidedToday; ?> void<?php echo $totalVoidedToday !== 1 ? 's' : ''; ?></span>
                                <strong style="color:#721c24;"><?php echo $currency_symbol . ' ' . number_format($totalVoidedAmt, 2); ?> lost</strong>
                            </div>
                            <?php foreach ($voidReasonsToday as $vr): ?>
                                <a class="ops-panel__reason-link" href="stock-orders.php?date=today&status=voided#ops-insights" title="View today's voided orders">
                                    <span style="color:#555;flex:1;">
                                        <i class="fas fa-circle" style="font-size:6px;color:#dc3545;vertical-align:middle;margin-right:4px;"></i>
                                        <?php echo htmlspecialchars((string)($vr['reason'] ?? 'Unknown')); ?>
                                    </span>
                                    <span style="white-space:nowrap;font-weight:600;color:#721c24;"><?php echo (int)($vr['count'] ?? 0); ?> &times;</span>
                                </a>
                            <?php endforeach; ?>
                            <div style="margin-top:10px;">
                                <a href="stock-orders.php?date=today&status=voided#ops-insights" style="font-size:12px;color:#8B7355;text-decoration:none;font-weight:600;">
                                    <i class="fas fa-magnifying-glass"></i> View all voided orders &rarr;
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="margin-top:14px;text-align:center;color:#155724;padding:10px 0;">
                            <i class="fas fa-circle-check" style="font-size:28px;margin-bottom:6px;display:block;"></i>
                            <strong>No voids today</strong>
                            <p style="font-size:12px;color:#6c757d;margin-top:4px;">Clean shift — keep it up!</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ── Top Moving Items ──────────────────────────────── -->
            <details open style="margin:0 0 16px;">
                <summary style="cursor:pointer;font-weight:600;padding:10px 14px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;list-style:none;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-fire" style="color:#8B7355;"></i>
                    Top Moving Items Today
                    <span style="font-size:12px;color:#6c757d;font-weight:400;margin-left:4px;">(<?php echo count($topSellingItemsToday); ?> items with movement)</span>
                    <i class="fas fa-chevron-down" style="margin-left:auto;font-size:12px;color:#aaa;"></i>
                </summary>
                <div class="ops-panel ops-panel--top-items" style="margin:0;border-top:none;border-radius:0 0 6px 6px;">
                    <div class="table-responsive" style="margin-top:8px;">
                        <table class="order-list" style="margin-top:0;">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Item</th>
                                    <th>Type</th>
                                    <th style="text-align:center;">Qty Ordered</th>
                                    <th style="text-align:center;">Progressed</th>
                                    <th style="text-align:right;">Revenue</th>
                                    <th style="text-align:center;">Fulfil %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topSellingItemsToday as $rank => $item):
                                    $qtyTotal     = (float)($item['quantity_total'] ?? 0);
                                    $progressedQty = (float)($item['progressed_qty'] ?? 0);
                                    $progressPct  = $qtyTotal > 0 ? ($progressedQty / $qtyTotal) * 100 : 0;
                                    $progColor    = $progressPct >= 80 ? '#198754' : ($progressPct >= 40 ? '#fd7e14' : '#dc3545');
                                ?>
                                    <tr>
                                        <td data-label="#" style="color:#aaa;font-size:12px;"><?php echo $rank + 1; ?></td>
                                        <td data-label="Item"><strong><?php echo htmlspecialchars((string)($item['item_name'] ?? '')); ?></strong></td>
                                        <td data-label="Type">
                                            <span style="background:#f3ece4;color:#8B7355;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:600;">
                                                <?php echo htmlspecialchars(ucfirst((string)($item['menu_type'] ?? ''))); ?>
                                            </span>
                                        </td>
                                        <td data-label="Qty Ordered" style="text-align:center;font-weight:600;"><?php echo number_format($qtyTotal, 0); ?></td>
                                        <td data-label="Progressed" style="text-align:center;"><?php echo number_format($progressedQty, 0); ?> / <?php echo number_format($qtyTotal, 0); ?></td>
                                        <td data-label="Revenue" style="text-align:right;font-weight:600;"><?php echo $currency_symbol . ' ' . number_format((float)($item['revenue_total'] ?? 0), 2); ?></td>
                                        <td data-label="Fulfil %" style="text-align:center;">
                                            <div style="display:flex;align-items:center;gap:6px;justify-content:center;">
                                                <div style="width:50px;height:7px;background:#e9ecef;border-radius:4px;overflow:hidden;">
                                                    <div style="width:<?php echo min(100, round($progressPct)); ?>%;height:100%;background:<?php echo $progColor; ?>;border-radius:4px;"></div>
                                                </div>
                                                <span style="font-size:11px;color:<?php echo $progColor; ?>;font-weight:600;"><?php echo number_format($progressPct, 0); ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($topSellingItemsToday)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align:center;color:#6c757d;padding:20px;">No menu movement recorded today yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>

        </section>

        <script>
            var STATION_ITEMS = <?php echo json_encode($stationItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            var ORDER_REVIEW_DATA = <?php echo json_encode($reviewData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            var REVIEW_CAN_RECONCILE = <?php echo (!empty($canReconcile)) ? 'true' : 'false'; ?>;
            var REVIEW_CURRENCY = <?php echo json_encode($currency_symbol ?? 'MWK'); ?>;

            function showFallbackOverlay(title, html, options) {
                var opts = options || {};
                var overlay = document.createElement('div');
                var alignItems = opts.alignTop ? 'flex-start' : 'center';
                var maxWidth = opts.maxWidth || '800px';
                var padding = opts.alignTop ? '30px 16px' : '20px';

                overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:' + alignItems + ';justify-content:center;padding:' + padding + ';overflow-y:auto;';
                overlay.setAttribute('data-fallback-overlay', '1');
                overlay.innerHTML = '<div style="background:#fff;border-radius:8px;max-width:' + maxWidth + ';width:100%;max-height:90vh;overflow:auto;padding:24px;position:relative;">' +
                    '<button type="button" data-fallback-overlay-close style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:20px;cursor:pointer;color:#6c757d;">&times;</button>' +
                    '<h3 style="margin:0 0 16px;font-size:16px;">' + title + '</h3>' +
                    html + '</div>';

                function closeOverlay() {
                    document.removeEventListener('keydown', onKeydown);
                    if (overlay.parentNode) {
                        overlay.parentNode.removeChild(overlay);
                    }
                }

                function onKeydown(event) {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        closeOverlay();
                    }
                }

                overlay.addEventListener('click', function(event) {
                    if (event.target === overlay || event.target.closest('[data-fallback-overlay-close]')) {
                        closeOverlay();
                    }
                });

                document.addEventListener('keydown', onKeydown);
                document.body.appendChild(overlay);
                return overlay;
            }

            function showStationItems(stationKey, stationLabel) {
                var items = STATION_ITEMS.filter(function(i) {
                    return i.station === stationKey;
                });
                var kdsScreen = {
                    kitchen: 'kds.php',
                    bar: 'bds.php',
                    coffee_bar: 'cds.php'
                } [stationKey] || null;

                var statusColors = {
                    pending: '#6c757d',
                    in_progress: '#fd7e14',
                    preparing: '#fd7e14',
                    ready: '#0d6efd',
                    collection: '#0d6efd'
                };
                var html = '';

                if (kdsScreen) {
                    html += '<p style="margin:0 0 12px;"><a href="' + kdsScreen + '" target="_blank" style="color:#0d6efd;font-weight:600;"><i class="fas fa-tv"></i> Open ' + stationLabel + ' Display Screen</a></p>';
                }

                if (items.length === 0) {
                    html += '<p style="color:#6c757d;text-align:center;padding:20px 0;"><i class="fas fa-check-circle" style="color:#198754;"></i> No active items in queue for this station.</p>';
                } else {
                    html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">';
                    html += '<thead><tr style="background:#f8f9fa;">' +
                        '<th style="padding:8px 10px;text-align:left;border-bottom:1px solid #dee2e6;">Order</th>' +
                        '<th style="padding:8px 10px;text-align:left;border-bottom:1px solid #dee2e6;">Item</th>' +
                        '<th style="padding:8px 6px;text-align:center;border-bottom:1px solid #dee2e6;">Qty</th>' +
                        '<th style="padding:8px 10px;text-align:left;border-bottom:1px solid #dee2e6;">Status</th>' +
                        '<th style="padding:8px 10px;text-align:left;border-bottom:1px solid #dee2e6;">Table / Type</th>' +
                        '<th style="padding:8px 10px;text-align:left;border-bottom:1px solid #dee2e6;">Notes</th>' +
                        '</tr></thead><tbody>';
                    items.forEach(function(item, idx) {
                        var rowBg = idx % 2 === 0 ? '#fff' : '#f8f9fa';
                        var statusStr = (item.kds_status || 'pending').replace(/_/g, ' ');
                        var statusColor = statusColors[item.kds_status] || '#6c757d';
                        var orderTypeLabel = (item.order_type || 'walk_in').replace(/_/g, ' ');
                        orderTypeLabel = orderTypeLabel.charAt(0).toUpperCase() + orderTypeLabel.slice(1);
                        var orderHref = 'order-lifecycle.php?id=' + item.order_id;
                        var tableInfo = item.order_type === 'room_service' ?
                            '<i class="fas fa-bed" style="color:#8B7355;"></i> Room ' + (item.table_number || '—') :
                            (item.table_number ? '<i class="fas fa-chair" style="color:#8B7355;"></i> ' + item.table_number : orderTypeLabel);
                        html += '<tr style="background:' + rowBg + ';border-bottom:1px solid #f0f0f0;">' +
                            '<td style="padding:7px 10px;font-size:12px;color:#6c757d;font-family:monospace;"><a href="' + orderHref + '" target="_blank" rel="noopener" style="color:#8B7355;text-decoration:none;font-weight:600;">' + (item.order_reference || '#' + item.order_id) + '</a></td>' +
                            '<td style="padding:7px 10px;font-weight:600;">' + (item.item_name || '—') + '</td>' +
                            '<td style="padding:7px 6px;text-align:center;">' + (item.quantity || 1) + '</td>' +
                            '<td style="padding:7px 10px;"><span style="color:' + statusColor + ';font-weight:600;text-transform:capitalize;">' + statusStr + '</span></td>' +
                            '<td style="padding:7px 10px;font-size:12px;">' + tableInfo + '</td>' +
                            '<td style="padding:7px 10px;font-size:12px;color:#6c757d;">' + (item.notes ? item.notes : '<em>—</em>') + '</td>' +
                            '</tr>';
                    });
                    html += '</tbody></table></div>';
                    html += '<p style="margin:10px 0 0;font-size:12px;color:#999;">' + items.length + ' active item(s) in queue</p>';
                }

                if (typeof Modal !== 'undefined' && Modal.showMessage) {
                    Modal.showMessage({
                        title: stationLabel + ' — Active Queue',
                        message: html
                    });
                } else {
                    showFallbackOverlay(stationLabel + ' — Active Queue', html, {
                        maxWidth: '800px'
                    });
                }
            }

            // ── Payment Review Modal ─────────────────────────────────────────────
            function _fmtNum(n) {
                return (REVIEW_CURRENCY + ' ' + Number(n).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }));
            }

            function _reviewTip(issue) {
                if (issue.indexOf('does not match item') !== -1) {
                    return 'Run <strong>Reconcile</strong> to recompute the order total from its line items. ' +
                        'Also check whether any items were manually price-edited after payment was recorded.';
                }
                if (issue.indexOf('not balanced against the accounting') !== -1) {
                    return 'Run <strong>Reconcile</strong> to create or update the payment ledger entry so it matches the order total. ' +
                        'Then verify the Accounting Dashboard shows the correct revenue figure.';
                }
                if (issue.indexOf('Reversed order still has an active') !== -1) {
                    return 'This voided/cancelled order still has a live payment in the ledger. ' +
                        'Run <strong>Reconcile</strong> to cancel that payment row. ' +
                        'Check the Accounting Dashboard afterwards to confirm no revenue is counted for this order.';
                }
                if (issue.indexOf('not linked to a checked-in booking') !== -1) {
                    return 'Assign this room-service order to the correct booking via order-lifecycle.php. ' +
                        'If the guest has already checked out, either void the order or manually add the charge to the booking in the Bookings module.';
                }
                if (issue.indexOf('no active folio charges') !== -1) {
                    return 'The room-service charge was not posted to the booking folio. ' +
                        'Run <strong>Reconcile</strong> to re-sync the folio entry. ' +
                        'If reconcile cannot fix it, manually add the charge in the Bookings module &rarr; Folio Charges.';
                }
                return 'Run <strong>Reconcile</strong> to automatically fix this where safe. ' +
                    'Then verify the result in the Accounting Dashboard.';
            }

            var _reviewFormId = null;

            function showOrderReview(orderId, formId) {
                _reviewFormId = formId || null;
                var d = ORDER_REVIEW_DATA[orderId];
                if (!d) {
                    window.open('order-lifecycle.php?id=' + orderId, '_blank', 'noopener');
                    return;
                }

                var statusColors = {
                    paid: '#155724',
                    placed: '#0d6efd',
                    voided: '#6c757d',
                    cancelled: '#721c24',
                    completed: '#155724'
                };
                var statusColor = statusColors[d.status] || '#495057';
                var methodLabel = d.payment_method ? d.payment_method.replace(/_/g, ' ').replace(/\b\w/g, function(c) {
                    return c.toUpperCase();
                }) : '—';

                var html = '';

                // ── Header summary ────────────────────────────────────────────
                html += '<div style="background:#f8f9fa;border-radius:6px;padding:12px 16px;margin-bottom:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;">';
                html += '<div><div style="font-size:11px;color:#6c757d;text-transform:uppercase;letter-spacing:.06em;">Status</div>' +
                    '<div style="font-weight:700;color:' + statusColor + ';text-transform:capitalize;">' + d.status + '</div></div>';
                html += '<div><div style="font-size:11px;color:#6c757d;text-transform:uppercase;letter-spacing:.06em;">Order Total</div>' +
                    '<div style="font-weight:700;">' + _fmtNum(d.total_amount) + '</div></div>';
                html += '<div><div style="font-size:11px;color:#6c757d;text-transform:uppercase;letter-spacing:.06em;">Items Subtotal</div>' +
                    '<div style="font-weight:700;color:' + (Math.abs(d.line_sum - d.total_amount) > 0.01 ? '#c82333' : '#155724') + ';">' + _fmtNum(d.line_sum) + '</div></div>';
                html += '<div><div style="font-size:11px;color:#6c757d;text-transform:uppercase;letter-spacing:.06em;">Ledger Payment</div>' +
                    '<div style="font-weight:700;color:' + (Math.abs(d.payment_sum - d.total_amount) > 0.01 ? '#c82333' : '#155724') + ';">' +
                    (d.active_payment_count > 0 ? _fmtNum(d.payment_sum) : '<em style="color:#999;">None recorded</em>') + '</div></div>';
                html += '<div><div style="font-size:11px;color:#6c757d;text-transform:uppercase;letter-spacing:.06em;">Payment Method</div>' +
                    '<div style="font-weight:700;">' + methodLabel + '</div></div>';
                html += '<div><div style="font-size:11px;color:#6c757d;text-transform:uppercase;letter-spacing:.06em;">Created by</div>' +
                    '<div style="font-weight:700;">' + (d.created_by_name || '—') + '</div></div>';
                html += '</div>';

                // ── What's wrong ──────────────────────────────────────────────
                html += '<div style="margin-bottom:16px;">';
                html += '<h4 style="margin:0 0 8px;font-size:14px;color:#c82333;"><i class="fas fa-triangle-exclamation"></i> What needs attention (' + d.issues.length + ' issue' + (d.issues.length !== 1 ? 's' : '') + ')</h4>';
                html += '<ul style="margin:0;padding-left:20px;">';
                d.issues.forEach(function(issue) {
                    html += '<li style="padding:4px 0;font-size:13px;">' + issue + '</li>';
                });
                html += '</ul></div>';

                // ── Accounting impact ─────────────────────────────────────────
                html += '<div style="margin-bottom:16px;">';
                html += '<h4 style="margin:0 0 8px;font-size:14px;color:#374151;"><i class="fas fa-chart-line" style="color:#8B7355;"></i> Accounting impact</h4>';
                html += '<div style="font-size:13px;background:#fff7e6;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;">';
                if (Math.abs(d.expected_total - d.total_amount) > 0.01) {
                    var diff = d.expected_total - d.total_amount;
                    html += '<p style="margin:4px 0;">Order header records <strong>' + _fmtNum(d.total_amount) + '</strong> but line items ' +
                        (diff > 0 ? 'add up to more' : 'add up to less') + ' at <strong>' + _fmtNum(d.expected_total) + '</strong> ' +
                        '(<strong style="color:#c82333;">' + (diff > 0 ? '+' : '') + _fmtNum(diff) + ' discrepancy</strong>). ' +
                        'Accounting reports use the order header total, so revenue may be ' + (diff > 0 ? 'understated' : 'overstated') + ' by this amount.</p>';
                }
                if (d.active_payment_count === 0 && d.status === 'paid') {
                    html += '<p style="margin:4px 0;">No payment row found in the ledger. This order will not appear in cash/card/mobile revenue totals in the Accounting Dashboard until a payment record is created. Run <strong>Reconcile</strong> to generate one.</p>';
                } else if (d.active_payment_count > 0 && Math.abs(d.payment_sum - d.total_amount) > 0.01) {
                    var ledgerDiff = d.payment_sum - d.total_amount;
                    html += '<p style="margin:4px 0;">Ledger shows <strong>' + _fmtNum(d.payment_sum) + '</strong> vs order total of <strong>' + _fmtNum(d.total_amount) + '</strong> ' +
                        '(<strong style="color:#c82333;">' + (ledgerDiff > 0 ? '+' : '') + _fmtNum(ledgerDiff) + '</strong>). ' +
                        'This gap will cause a mismatch in the Accounting Dashboard. Run <strong>Reconcile</strong> to sync the ledger.</p>';
                }
                if (d.active_folio_charges === 0 && d.order_type === 'room_service') {
                    html += '<p style="margin:4px 0;">No folio charge posted for this room-service order. The guest\'s bill in the Bookings module will not include this charge.</p>';
                }
                html += '</div></div>';

                // ── Audit trail ───────────────────────────────────────────────
                if (d.audit && d.audit.length > 0) {
                    html += '<div style="margin-bottom:16px;">';
                    html += '<h4 style="margin:0 0 8px;font-size:14px;color:#374151;"><i class="fas fa-timeline" style="color:#8B7355;"></i> Audit trail</h4>';
                    html += '<div style="border-left:3px solid #dee2e6;padding-left:14px;display:flex;flex-direction:column;gap:8px;">';
                    d.audit.forEach(function(entry) {
                        var evtColor = {
                            voided: '#c82333',
                            cancelled: '#c82333',
                            reconciled: '#155724',
                            paid: '#155724'
                        } [entry.event] || '#8B7355';
                        html += '<div style="font-size:12px;">' +
                            '<span style="display:inline-block;width:8px;height:8px;background:' + evtColor + ';border-radius:50%;margin-right:6px;"></span>' +
                            '<strong style="text-transform:capitalize;">' + (entry.event || '—') + '</strong>' +
                            ' by <em>' + (entry.actor_name || 'System') + '</em>' +
                            ' <span style="color:#adb5bd;">&mdash; ' + (entry.created_at || '') + '</span>' +
                            (entry.details ? '<div style="margin-top:3px;padding-left:14px;color:#6c757d;">' + entry.details + '</div>' : '') +
                            '</div>';
                    });
                    html += '</div></div>';
                }

                // ── Reconciliation tips ───────────────────────────────────────
                html += '<div style="margin-bottom:16px;">';
                html += '<h4 style="margin:0 0 8px;font-size:14px;color:#374151;"><i class="fas fa-wrench" style="color:#8B7355;"></i> How to reconcile</h4>';
                html += '<ol style="margin:0;padding-left:20px;font-size:13px;">';
                d.issues.forEach(function(issue) {
                    html += '<li style="padding:4px 0;">' + _reviewTip(issue) + '</li>';
                });
                html += '</ol></div>';

                // ── Reconcile action (admin/manager only) ─────────────────────
                if (formId && REVIEW_CAN_RECONCILE) {
                    html += '<div style="padding-top:14px;border-top:1px solid #e9ecef;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">';
                    html += '<a href="order-lifecycle.php?id=' + orderId + '" target="_blank" rel="noopener" class="btn" style="background:#f8f9fa;color:#495057;border:1px solid #ced4da;text-decoration:none;padding:8px 16px;border-radius:6px;font-size:13px;">' +
                        '<i class="fas fa-timeline"></i> Full Timeline</a>';
                    html += '<button type="button" onclick="_doReconcile()" style="padding:8px 18px;background:#8B7355;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">' +
                        '<i class="fas fa-rotate"></i> Run Reconciliation &amp; Sync Accounting</button>';
                    html += '</div>';
                }

                if (typeof Modal !== 'undefined' && Modal.showMessage) {
                    Modal.showMessage({
                        title: '<i class="fas fa-magnifying-glass" style="color:#8B7355;"></i> Review &mdash; ' + d.reference,
                        message: html
                    });
                } else {
                    showFallbackOverlay('<i class="fas fa-magnifying-glass" style="color:#8B7355;"></i> Review &mdash; ' + d.reference, html, {
                        maxWidth: '680px',
                        alignTop: true
                    });
                }
            }

            function _doReconcile() {
                if (!_reviewFormId) return;
                var form = document.getElementById(_reviewFormId);
                if (form) form.submit();
            }
        </script>

        <h3 style="margin-top:32px;">Recent Orders</h3>
        <div class="table-responsive">
            <table class="order-list">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Type</th>
                        <th>Customer</th>
                        <th>Table</th>
                        <th>Table Status</th>
                        <th>Room / Folio</th>
                        <th>Items</th>
                        <th style="text-align:right;">Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Health</th>
                        <th>Cashier</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $orders_per_page    = 10;
                    $orders_page        = max(1, (int)($_GET['orders_page'] ?? 1));
                    $orders_total       = count($orders);
                    $orders_total_pages = $orders_total > 0 ? (int)ceil($orders_total / $orders_per_page) : 1;
                    $orders_display     = array_slice($orders, ($orders_page - 1) * $orders_per_page, $orders_per_page);
                    ?>
                    <?php foreach ($orders_display as $o):
                        $health = $orderHealth[(int)$o['id']] ?? ['is_balanced' => true, 'issues' => []];
                        $methodLabel = $o['payment_method']
                            ? ucwords(str_replace('_', ' ', $o['payment_method']))
                            : '—';
                        $voidTitle = '';
                        if ($o['status'] === 'voided') {
                            $voidTitle = 'Voided by ' . ($o['voided_by_name'] ?? '?')
                                . ' at ' . ($o['voided_at'] ? date('Y-m-d H:i', strtotime($o['voided_at'])) : '?')
                                . ': ' . ($o['void_reason'] ?? '');
                        }

                        $kitchenRaw = strtolower(trim((string)($o['kitchen_status'] ?? 'none')));
                        $kitchenLabel = match ($kitchenRaw) {
                            'new' => 'Pending',
                            'in_progress' => 'Preparing',
                            'ready' => 'Ready',
                            'served' => 'Served',
                            'recalled' => 'Recalled',
                            default => ucfirst(str_replace('_', ' ', $kitchenRaw ?: 'none')),
                        };

                        $tableRaw = trim((string)($o['table_number'] ?? ''));
                        $roomRaw = trim((string)($o['room_number'] ?: $o['booking_room_number'] ?: preg_replace('/^Room\s+/i', '', (string)$o['table_number'])));
                        if (($o['order_type'] ?? '') === 'room_service') {
                            $tableLabel = $roomRaw !== '' ? 'Room ' . $roomRaw : ($tableRaw !== '' ? $tableRaw : '—');
                        } else {
                            $tableClean = trim((string)preg_replace('/^Room\s+/i', '', $tableRaw));
                            $tableLabel = $tableClean !== '' ? 'Table ' . $tableClean : '—';
                        }

                        $tableStatusClass = 'ts-na';
                        $tableStatusLabel = 'N/A';
                        $tableStatusMeta = 'No table linked';
                        if (($o['order_type'] ?? '') === 'room_service') {
                            $tableStatusClass = 'ts-room-service';
                            $tableStatusLabel = 'Room Service';
                            $tableStatusMeta = $kitchenLabel;
                        } elseif ($tableLabel !== '—') {
                            if (in_array((string)$o['status'], ['paid', 'completed', 'cancelled', 'voided'], true)) {
                                $tableStatusClass = 'ts-released';
                                $tableStatusLabel = 'Released';
                                $tableStatusMeta = ucfirst((string)$o['status']);
                            } elseif ((string)$o['status'] === 'placed' && $kitchenRaw === 'served') {
                                $tableStatusClass = 'ts-awaiting-payment';
                                $tableStatusLabel = 'Awaiting Payment';
                                $tableStatusMeta = 'Order served';
                            } elseif ((string)$o['status'] === 'placed') {
                                $tableStatusClass = 'ts-occupied';
                                $tableStatusLabel = 'Occupied';
                                $tableStatusMeta = $kitchenLabel;
                            } else {
                                $tableStatusClass = 'ts-na';
                                $tableStatusLabel = ucfirst((string)$o['status']);
                                $tableStatusMeta = $kitchenLabel;
                            }
                        }
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($o['reference']); ?></strong></td>
                            <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $o['order_type']))); ?></td>
                            <td><?php echo htmlspecialchars($o['customer_name'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($tableLabel); ?></td>
                            <td>
                                <span class="table-status-pill <?php echo htmlspecialchars($tableStatusClass); ?>"><?php echo htmlspecialchars($tableStatusLabel); ?></span>
                                <div style="font-size:11px; color:#6c757d;"><?php echo htmlspecialchars($tableStatusMeta); ?></div>
                            </td>
                            <td style="font-size:12px;">
                                <?php if ($o['order_type'] === 'room_service'): ?>
                                    <?php $roomText = $o['room_number'] ?: $o['booking_room_number'] ?: preg_replace('/^Room\s+/i', '', (string)$o['table_number']); ?>
                                    <strong><?php echo $roomText ? 'Room ' . htmlspecialchars($roomText) : 'No room link'; ?></strong>
                                    <?php if (!empty($o['booking_reference'])): ?>
                                        <div style="font-size:11px;color:#6c757d;">Folio <?php echo htmlspecialchars($o['booking_reference']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($o['folio_posted_at'])): ?>
                                        <div style="font-size:10px;color:#155724;">Posted <?php echo date('Y-m-d H:i', strtotime($o['folio_posted_at'])); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#6c757d;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)$o['line_count']; ?></td>
                            <td style="text-align:right;font-weight:600;"><?php echo $currency_symbol . ' ' . number_format((float)$o['total_amount'], 2); ?></td>
                            <td style="font-size:12px;">
                                <?php echo htmlspecialchars($methodLabel); ?>
                                <?php if ($o['payment_method'] === 'cash' && $o['change_due'] !== null && (float)$o['change_due'] > 0): ?>
                                    <div style="font-size:10px;color:#6c757d;">Change <?php echo $currency_symbol . ' ' . number_format((float)$o['change_due'], 2); ?></div>
                                <?php endif; ?>
                                <?php if ($o['payment_method'] === 'mobile_money' && $o['mobile_wallet_reference']): ?>
                                    <div style="font-size:10px;color:#6c757d;"><?php echo htmlspecialchars($o['mobile_wallet_provider']); ?>: <?php echo htmlspecialchars($o['mobile_wallet_reference']); ?></div>
                                <?php endif; ?>
                                <?php if ($o['payment_method'] === 'card_manual' && $o['card_last4']): ?>
                                    <div style="font-size:10px;color:#6c757d;">···· <?php echo htmlspecialchars($o['card_last4']); ?> · <?php echo htmlspecialchars($o['card_auth_code']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="pill st-<?php echo htmlspecialchars($o['status']); ?>" <?php if ($voidTitle): ?> title="<?php echo htmlspecialchars($voidTitle); ?>" <?php endif; ?>><?php echo htmlspecialchars(ucfirst($o['status'])); ?></span>
                            </td>
                            <td style="font-size:12px;">
                                <?php if (!empty($health['is_balanced'])): ?>
                                    <span class="health-pill ok">Balanced</span>
                                <?php else: ?>
                                    <span class="health-pill bad">Review</span>
                                    <ul class="health-list" style="margin:5px 0 0;padding-left:16px;">
                                        <?php foreach (array_slice($health['issues'], 0, 3) as $issue): ?>
                                            <li><?php echo htmlspecialchars($issue); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;"><?php echo htmlspecialchars($o['created_by_name'] ?: '—'); ?></td>
                            <td style="font-size:12px;"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($o['created_at']))); ?></td>
                            <td>
                                <a href="order-lifecycle.php?id=<?php echo (int)$o['id']; ?>" target="_blank" rel="noopener" class="mini-action"><i class="fas fa-stream"></i> Timeline</a>
                                <button type="button" class="mini-action"
                                    onclick="showOrderItems(<?php echo (int)$o['id']; ?>, <?php echo json_encode($o['reference']); ?>)"
                                    title="View line items for this order">
                                    <i class="fas fa-list-ul"></i> Items
                                </button>
                                <?php if (in_array($o['status'], ['paid', 'voided', 'cancelled'], true) || $o['order_type'] === 'room_service'): ?>
                                    <a href="stock-receipt.php?id=<?php echo (int)$o['id']; ?>" class="mini-action primary"><i class="fas fa-receipt"></i> Receipt</a>
                                <?php endif; ?>
                                <?php if (empty($health['is_balanced'])): ?>
                                    <button class="mini-action warn" type="button"
                                        onclick="showOrderReview(<?php echo (int)$o['id']; ?>, <?php echo $canReconcile ? "'rf-" . (int)$o['id'] . "'" : 'null'; ?>)"
                                        title="View full payment review: timeline, accounting impact, and reconciliation tips">
                                        <i class="fas fa-magnifying-glass"></i> Review
                                    </button>
                                    <?php if ($canReconcile): ?>
                                        <form method="POST" id="rf-<?php echo (int)$o['id']; ?>" style="display:none;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="reconcile_order">
                                            <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($o['status'] === 'placed'): ?>
                                    <a href="order-lifecycle.php?id=<?php echo (int)$o['id']; ?>" target="_blank" rel="noopener" class="mini-action primary" title="View live order status, items, and kitchen progress"><i class="fas fa-hourglass-half"></i> Open</a>
                                    <?php if (($o['order_type'] ?? '') !== 'room_service'): ?>
                                        <button type="button" class="mini-action success"
                                            onclick="promptSettle(document.getElementById('sf-<?php echo (int)$o['id']; ?>'), <?php echo json_encode($o['reference']); ?>, <?php echo json_encode((float)$o['total_amount']); ?>)"
                                            title="Settle this order and collect payment now">
                                            <i class="fas fa-circle-check"></i> Settle
                                        </button>
                                        <form method="POST" id="sf-<?php echo (int)$o['id']; ?>" style="display:none;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="settle_order">
                                            <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                                            <input type="hidden" name="payment_method" value="">
                                            <input type="hidden" name="tendered_amount" value="">
                                            <input type="hidden" name="mobile_wallet_provider" value="">
                                            <input type="hidden" name="mobile_wallet_reference" value="">
                                            <input type="hidden" name="card_last4" value="">
                                            <input type="hidden" name="card_auth_code" value="">
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel unpaid order and restore stock?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="cancel_order">
                                        <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                                        <button class="mini-action danger" type="submit">Cancel</button>
                                    </form>
                                <?php elseif (in_array($o['status'], ['paid', 'completed'], true) && in_array($user['role'] ?? '', ['admin', 'manager'], true)): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="void_order">
                                        <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                                        <input type="hidden" name="void_reason" value="">
                                        <button class="mini-action danger" type="button" onclick="promptVoid(this.closest('form'))"><i class="fas fa-ban"></i> Void</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="14" style="text-align:center;padding:30px;color:#6c757d;">No orders yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($orders_total_pages > 1): ?>
            <nav style="display:flex;align-items:center;justify-content:center;gap:6px;padding:16px 0;flex-wrap:wrap;">
                <?php
                $pgBase = array_filter([
                    'date'           => $filterDate,
                    'payment_method' => $filterMethod,
                    'order_type'     => $filterOrderType,
                    'hour'           => $filterHour,
                    'status'         => $filterStatus,
                    'health'         => $filterHealth,
                    'kitchen_status' => $filterKitchenStatus,
                ], fn($v) => $v !== '');
                for ($pg = 1; $pg <= $orders_total_pages; $pg++):
                    $pgHref   = 'stock-orders.php?' . http_build_query(array_merge($pgBase, ['orders_page' => $pg]));
                    $pgActive = ($pg === $orders_page);
                ?>
                    <a href="<?php echo htmlspecialchars($pgHref, ENT_QUOTES, 'UTF-8'); ?>"
                        style="padding:6px 12px;border:1px solid <?php echo $pgActive ? 'var(--color-primary,#8A775F)' : '#dee2e6'; ?>;background:<?php echo $pgActive ? 'var(--color-primary,#8A775F)' : '#fff'; ?>;color:<?php echo $pgActive ? '#fff' : '#374151'; ?>;border-radius:4px;font-size:13px;text-decoration:none;"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <span style="padding:6px 8px;font-size:12px;color:#888;">
                    Showing <?php echo (($orders_page - 1) * $orders_per_page) + 1; ?>–<?php echo min($orders_page * $orders_per_page, $orders_total); ?> of <?php echo $orders_total; ?>
                </span>
            </nav>
        <?php endif; ?>

        <div class="modal-overlay" id="stockOrdersInsightModal-overlay" data-modal-overlay aria-hidden="true"></div>
        <div class="modal-overlay modal-lg" id="stockOrdersInsightModal" role="dialog" aria-modal="true" aria-labelledby="stockOrdersInsightTitle" data-modal data-close-on-escape="true" data-close-on-overlay="true">
            <div class="modal-container">
                <div class="modal-header">
                    <h3 class="modal-title" id="stockOrdersInsightTitle">Operational Insight</h3>
                    <button type="button" class="modal-close" data-modal-close aria-label="Close stock orders insight"><i class="fas fa-times"></i></button>
                </div>
                <div class="modal-body" id="stockOrdersInsightBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-modal-close>Close</button>
                </div>
            </div>
        </div>

        <template id="stock-orders-insight-template-revenue-today">
            <p class="stock-insight-note">Revenue today compares gross settled revenue against yesterday to show shift momentum.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Revenue today</th><td><?php echo $currency_symbol . ' ' . number_format($revToday, 2); ?></td></tr>
                    <tr><th>Revenue yesterday</th><td><?php echo $currency_symbol . ' ' . number_format($revYday, 2); ?></td></tr>
                    <tr><th>Delta vs yesterday</th><td><?php echo $revDelta === null ? 'No prior data' : (($revDelta >= 0 ? '+' : '-') . number_format(abs($revDelta), 1) . '%'); ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a href="stock-orders.php?date=today" class="btn btn-primary">Open today's orders</a>
                <a href="accounting-dashboard.php" class="btn btn-secondary">Open accounting dashboard</a>
            </div>
        </template>

        <template id="stock-orders-insight-template-orders-today">
            <p class="stock-insight-note">Orders throughput compares completed volume against yesterday to flag operational acceleration or slowdown.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Orders today</th><td><?php echo number_format($ordToday); ?></td></tr>
                    <tr><th>Orders yesterday</th><td><?php echo number_format($ordYday); ?></td></tr>
                    <tr><th>Delta vs yesterday</th><td><?php echo $ordDelta === null ? 'No prior data' : (($ordDelta >= 0 ? '+' : '-') . number_format(abs($ordDelta), 1) . '%'); ?></td></tr>
                    <tr><th>Revenue today</th><td><?php echo $currency_symbol . ' ' . number_format($revToday, 2); ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a href="stock-orders.php?date=today" class="btn btn-primary">Open today's queue</a>
            </div>
        </template>

        <template id="stock-orders-insight-template-avg-ticket">
            <p class="stock-insight-note">Average ticket tracks spend-per-order efficiency based on settled transactions.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Average ticket</th><td><?php echo $currency_symbol . ' ' . number_format((float)($operationsSnapshot['avg_ticket_today'] ?? 0), 2); ?></td></tr>
                    <tr><th>Settled orders today</th><td><?php echo number_format((int)($operationsSnapshot['settled_today'] ?? 0)); ?></td></tr>
                    <tr><th>Revenue today</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['revenue_today'] ?? 0), 2); ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a href="stock-orders.php?date=today&status=paid" class="btn btn-primary">Open settled orders</a>
            </div>
        </template>

        <template id="stock-orders-insight-template-cash-collected">
            <p class="stock-insight-note">Cash collection supports drawer reconciliation and should align with cash settlement records.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Cash collected today</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['cash_today'] ?? 0), 2); ?></td></tr>
                    <tr><th>Total revenue today</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['revenue_today'] ?? 0), 2); ?></td></tr>
                    <tr><th>Cash share of revenue</th><td><?php echo (float)($shift['revenue_today'] ?? 0) > 0 ? number_format(((float)($shift['cash_today'] ?? 0) / (float)$shift['revenue_today']) * 100, 1) . '%' : '0.0%'; ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a href="stock-orders.php?date=today&payment_method=cash" class="btn btn-primary">Open cash orders</a>
            </div>
        </template>

        <template id="stock-orders-insight-template-mobile-money">
            <p class="stock-insight-note">Mobile money totals should match provider references and cashier closure sheets.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Mobile money today</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['mobile_today'] ?? 0), 2); ?></td></tr>
                    <tr><th>Total revenue today</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['revenue_today'] ?? 0), 2); ?></td></tr>
                    <tr><th>Mobile share of revenue</th><td><?php echo (float)($shift['revenue_today'] ?? 0) > 0 ? number_format(((float)($shift['mobile_today'] ?? 0) / (float)$shift['revenue_today']) * 100, 1) . '%' : '0.0%'; ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a href="stock-orders.php?date=today&payment_method=mobile_money" class="btn btn-primary">Open mobile money orders</a>
            </div>
        </template>

        <template id="stock-orders-insight-template-card-payments">
            <p class="stock-insight-note">Card totals include manual and POS card settlements and should be reviewed with slips or gateway reports.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Card payments today</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['card_today'] ?? 0), 2); ?></td></tr>
                    <tr><th>Total revenue today</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['revenue_today'] ?? 0), 2); ?></td></tr>
                    <tr><th>Card share of revenue</th><td><?php echo (float)($shift['revenue_today'] ?? 0) > 0 ? number_format(((float)($shift['card_today'] ?? 0) / (float)$shift['revenue_today']) * 100, 1) . '%' : '0.0%'; ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a href="stock-orders.php?date=today&payment_method=card_manual" class="btn btn-primary">Open manual card orders</a>
                <a href="stock-orders.php?date=today&payment_method=card_pos" class="btn btn-secondary">Open POS card orders</a>
            </div>
        </template>

        <template id="stock-orders-insight-template-voids-today">
            <p class="stock-insight-note">Void activity highlights leakage risk and exceptions that need manager review and reason quality checks.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Voids today</th><td><?php echo number_format($totalVoidedToday); ?></td></tr>
                    <tr><th>Voided amount</th><td><?php echo $currency_symbol . ' ' . number_format($totalVoidedAmt, 2); ?></td></tr>
                    <tr><th>Needs-review orders</th><td><?php echo number_format((int)($healthSummary['unbalanced'] ?? 0)); ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a href="stock-orders.php?date=today&status=voided" class="btn btn-primary">Open voided orders</a>
                <a href="stock-orders.php?health=review" class="btn btn-secondary">Open review queue</a>
            </div>
        </template>

        <?php if ($peakHour): ?>
            <template id="stock-orders-insight-template-peak-hour">
                <p class="stock-insight-note">Peak hour pinpoints the busiest period for staffing and prep balancing.</p>
                <table class="stock-insight-table">
                    <tbody>
                        <tr>
                            <th>Peak hour label</th>
                            <td>
                                <?php
                                $peakHourNum = (int)$peakHour['hour'];
                                $peakHourLabel = $peakHourNum === 0 ? '12am' : ($peakHourNum < 12 ? $peakHourNum . 'am' : ($peakHourNum === 12 ? '12pm' : ($peakHourNum - 12) . 'pm'));
                                echo htmlspecialchars($peakHourLabel);
                                ?>
                            </td>
                        </tr>
                        <tr><th>Orders in peak hour</th><td><?php echo number_format((int)$peakHour['orders_count']); ?></td></tr>
                        <tr><th>Revenue in peak hour</th><td><?php echo $currency_symbol . ' ' . number_format((float)$peakHour['revenue'], 2); ?></td></tr>
                    </tbody>
                </table>
                <div class="stock-insight-actions">
                    <a href="stock-orders.php?date=today&hour=<?php echo (int)$peakHour['hour']; ?>" class="btn btn-primary">Open peak-hour orders</a>
                </div>
            </template>
        <?php endif; ?>

        <template id="stock-orders-insight-template-cashier-performance">
            <p class="stock-insight-note">Cashier performance view helps monitor ownership of open orders and revenue concentration.</p>
            <table class="stock-insight-table">
                <thead>
                    <tr>
                        <th>Cashier</th>
                        <th>Revenue</th>
                        <th>Open Orders</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cashierStats)): ?>
                        <tr>
                            <td colspan="3">No cashier data for today.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cashierStats as $cs): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($cs['cashier'] ?? 'Unknown')); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format((float)($cs['revenue'] ?? 0), 2); ?></td>
                                <td><?php echo number_format((int)($cs['orders_open'] ?? 0)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a href="stock-orders.php?date=today" class="btn btn-primary">Open today's orders</a>
            </div>
        </template>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
    <div id="soVoidModal" class="modal-overlay" data-modal role="dialog" aria-modal="true" aria-labelledby="soVoidTitle">
        <div class="modal-content" style="max-width:min(96vw,26rem); width:min(96vw,26rem);">
            <div class="modal-header">
                <h3 class="modal-title" id="soVoidTitle" style="color:#c82333;"><i class="fas fa-ban"></i> Void Order</h3>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="soCloseVoid()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin:0 0 12px;font-size:14px;color:#374151;">Provide a reason for voiding this paid order. This is logged with your name.</p>
                <textarea id="soVoidReason" rows="3" placeholder="Reason (min 8 characters)…" style="width:100%;padding:8px;border:1px solid #ced4da;border-radius:4px;font-size:14px;resize:vertical;box-sizing:border-box;"></textarea>
                <span id="soVoidErr" style="color:#c82333;font-size:13px;display:none;"></span>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="soCloseVoid()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="soDoVoid()"><i class="fas fa-ban"></i> Void Order</button>
            </div>
        </div>
    </div>

    <!-- Settle Order Modal -->
    <div id="soSettleModal" class="modal-overlay" data-modal role="dialog" aria-modal="true" aria-labelledby="soSettleTitle">
        <div class="modal-content" style="max-width:min(96vw,30rem);width:min(96vw,30rem);">
            <div class="modal-header">
                <h3 class="modal-title" id="soSettleTitle" style="color:#155724;"><i class="fas fa-circle-check"></i> Settle Order</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="soCloseSettle()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="soSettleInfo" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:14px;"></div>
                <div style="margin-bottom:12px;">
                    <label for="soSettleMethod" style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Payment Method</label>
                    <select id="soSettleMethod" style="width:100%;padding:8px 10px;border:1px solid #ced4da;border-radius:4px;font-size:14px;" onchange="soUpdateSettleFields()">
                        <option value="">— Select —</option>
                        <option value="cash">Cash</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="card_manual">Card (Manual / Slip)</option>
                    </select>
                </div>
                <div id="soSettleCashFields" style="display:none;margin-bottom:12px;">
                    <label for="soSettleTendered" style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Amount Tendered</label>
                    <input type="number" id="soSettleTendered" min="0" step="0.01" placeholder="0.00"
                        style="width:100%;padding:8px 10px;border:1px solid #ced4da;border-radius:4px;font-size:14px;box-sizing:border-box;"
                        oninput="soCalcChange()">
                    <div id="soSettleChangeDisplay" style="font-size:13px;margin-top:6px;font-weight:600;min-height:20px;"></div>
                </div>
                <div id="soSettleMobileFields" style="display:none;margin-bottom:12px;">
                    <label for="soSettleMobileProvider" style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Provider</label>
                    <input type="text" id="soSettleMobileProvider" placeholder="e.g. Airtel Money, TNM Mpamba"
                        style="width:100%;padding:8px 10px;border:1px solid #ced4da;border-radius:4px;font-size:14px;box-sizing:border-box;margin-bottom:8px;">
                    <label for="soSettleMobileRef" style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Transaction Reference</label>
                    <input type="text" id="soSettleMobileRef" placeholder="e.g. P234567890"
                        style="width:100%;padding:8px 10px;border:1px solid #ced4da;border-radius:4px;font-size:14px;box-sizing:border-box;">
                </div>
                <div id="soSettleCardFields" style="display:none;margin-bottom:12px;">
                    <label for="soSettleCardLast4" style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Card Last 4 Digits</label>
                    <input type="text" id="soSettleCardLast4" maxlength="4" placeholder="1234"
                        style="width:100%;padding:8px 10px;border:1px solid #ced4da;border-radius:4px;font-size:14px;box-sizing:border-box;margin-bottom:8px;">
                    <label for="soSettleCardAuth" style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Auth Code (from slip)</label>
                    <input type="text" id="soSettleCardAuth" placeholder="e.g. 123456"
                        style="width:100%;padding:8px 10px;border:1px solid #ced4da;border-radius:4px;font-size:14px;box-sizing:border-box;">
                </div>
                <span id="soSettleErr" style="color:#c82333;font-size:13px;display:none;"></span>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="soCloseSettle()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="soDoSettle()"><i class="fas fa-circle-check"></i> Settle &amp; Close Order</button>
            </div>
        </div>
    </div>
    <script>
        var _soVoidForm = null;

        function promptVoid(form) {
            _soVoidForm = form;
            document.getElementById('soVoidReason').value = '';
            document.getElementById('soVoidErr').style.display = 'none';
            var modal = document.getElementById('soVoidModal');
            if (modal) {
                modal.classList.add('active');
                document.body.classList.add('modal-open');
            }
        }

        function soCloseVoid(resetState = true) {
            var modal = document.getElementById('soVoidModal');
            if (modal) {
                modal.classList.remove('active');
            }
            if (!document.querySelector('.modal-overlay.active')) {
                document.body.classList.remove('modal-open');
            }
            if (resetState) {
                _soVoidForm = null;
            }
        }

        function soDoVoid() {
            const reason = document.getElementById('soVoidReason').value.trim();
            const errEl = document.getElementById('soVoidErr');
            const form = _soVoidForm;
            if (reason.length < 8) {
                errEl.textContent = 'Reason must be at least 8 characters.';
                errEl.style.display = '';
                return;
            }
            if (!form) {
                return;
            }
            form.querySelector('[name=void_reason]').value = reason;
            soCloseVoid(false);
            _soVoidForm = null;
            form.submit();
        }

        // ── Settle Order Modal ─────────────────────────────────────────────
        var _soSettleForm = null;
        var _soSettleTotal = 0;

        function promptSettle(form, ref, total) {
            _soSettleForm = form;
            _soSettleTotal = parseFloat(total) || 0;
            document.getElementById('soSettleMethod').value = '';
            document.getElementById('soSettleTendered').value = '';
            document.getElementById('soSettleMobileProvider').value = '';
            document.getElementById('soSettleMobileRef').value = '';
            document.getElementById('soSettleCardLast4').value = '';
            document.getElementById('soSettleCardAuth').value = '';
            document.getElementById('soSettleChangeDisplay').textContent = '';
            document.getElementById('soSettleErr').style.display = 'none';
            document.getElementById('soSettleInfo').innerHTML =
                '<strong><i class="fas fa-receipt"></i> ' + ref + '</strong> &mdash; Total: <strong>' +
                (REVIEW_CURRENCY || 'MWK') + ' ' + Number(total).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</strong>';
            soUpdateSettleFields();
            var modal = document.getElementById('soSettleModal');
            if (modal) {
                modal.classList.add('active');
                document.body.classList.add('modal-open');
            }
        }

        function soCloseSettle(resetState) {
            if (resetState !== false) _soSettleForm = null;
            var modal = document.getElementById('soSettleModal');
            if (modal) modal.classList.remove('active');
            if (!document.querySelector('.modal-overlay.active')) {
                document.body.classList.remove('modal-open');
            }
        }

        function soUpdateSettleFields() {
            var method = document.getElementById('soSettleMethod').value;
            document.getElementById('soSettleCashFields').style.display   = method === 'cash'         ? '' : 'none';
            document.getElementById('soSettleMobileFields').style.display = method === 'mobile_money' ? '' : 'none';
            document.getElementById('soSettleCardFields').style.display   = method === 'card_manual'  ? '' : 'none';
            document.getElementById('soSettleChangeDisplay').textContent = '';
        }

        function soCalcChange() {
            var cd = document.getElementById('soSettleChangeDisplay');
            var tendered = parseFloat(document.getElementById('soSettleTendered').value) || 0;
            if (tendered <= 0) { cd.textContent = ''; return; }
            var change = tendered - _soSettleTotal;
            if (change < -0.001) {
                cd.style.color = '#c82333';
                cd.textContent = 'Short by ' + (REVIEW_CURRENCY || '') + ' ' + Math.abs(change).toFixed(2);
            } else {
                cd.style.color = '#155724';
                cd.textContent = 'Change due: ' + (REVIEW_CURRENCY || '') + ' ' + change.toFixed(2);
            }
        }

        function soDoSettle() {
            var errEl = document.getElementById('soSettleErr');
            errEl.style.display = 'none';
            var method = document.getElementById('soSettleMethod').value;
            if (!method) {
                errEl.textContent = 'Select a payment method.';
                errEl.style.display = '';
                return;
            }
            var form = _soSettleForm;
            if (!form) return;

            form.querySelector('[name=payment_method]').value = method;

            if (method === 'cash') {
                var tendered = parseFloat(document.getElementById('soSettleTendered').value) || 0;
                if (tendered + 0.001 < _soSettleTotal) {
                    errEl.textContent = 'Tendered amount is less than the order total.';
                    errEl.style.display = '';
                    return;
                }
                form.querySelector('[name=tendered_amount]').value = tendered.toFixed(2);
            } else if (method === 'mobile_money') {
                var provider = document.getElementById('soSettleMobileProvider').value.trim();
                var ref = document.getElementById('soSettleMobileRef').value.trim();
                if (!provider || !ref) {
                    errEl.textContent = 'Enter both provider and transaction reference.';
                    errEl.style.display = '';
                    return;
                }
                form.querySelector('[name=mobile_wallet_provider]').value = provider;
                form.querySelector('[name=mobile_wallet_reference]').value = ref;
            } else if (method === 'card_manual') {
                var last4 = document.getElementById('soSettleCardLast4').value.trim();
                var auth = document.getElementById('soSettleCardAuth').value.trim();
                if (last4.length !== 4 || !/^\d{4}$/.test(last4) || !auth) {
                    errEl.textContent = 'Enter exactly 4 card digits and the auth code.';
                    errEl.style.display = '';
                    return;
                }
                form.querySelector('[name=card_last4]').value = last4;
                form.querySelector('[name=card_auth_code]').value = auth;
            }

            soCloseSettle(false);
            _soSettleForm = null;
            form.submit();
        }

        // ── Order Items Viewer ─────────────────────────────────────────────
        function showOrderItems(orderId, ref) {
            var url = 'stock-orders.php?ajax=order_items&id=' + orderId;
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(items) {
                    var html = '';
                    if (!items || items.length === 0) {
                        html = '<p style="text-align:center;color:#6c757d;padding:24px 0;"><i class="fas fa-inbox" style="font-size:28px;display:block;margin-bottom:8px;"></i>No items found for this order.</p>';
                    } else {
                        var statusColors = { pending: '#6c757d', in_progress: '#fd7e14', preparing: '#fd7e14', ready: '#0d6efd', collection: '#0d6efd', served: '#198754', void: '#dc3545' };
                        var stationLabels = { kitchen: 'Kitchen', bar: 'Bar', coffee_bar: 'Coffee Bar' };
                        html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">';
                        html += '<thead><tr style="background:#f8f9fa;">' +
                            '<th style="padding:8px 10px;text-align:left;border-bottom:2px solid #dee2e6;">Item</th>' +
                            '<th style="padding:8px 6px;text-align:center;border-bottom:2px solid #dee2e6;">Type</th>' +
                            '<th style="padding:8px 6px;text-align:center;border-bottom:2px solid #dee2e6;">Qty</th>' +
                            '<th style="padding:8px 10px;text-align:right;border-bottom:2px solid #dee2e6;">Unit</th>' +
                            '<th style="padding:8px 10px;text-align:right;border-bottom:2px solid #dee2e6;">Line Total</th>' +
                            '<th style="padding:8px 8px;text-align:center;border-bottom:2px solid #dee2e6;">Station</th>' +
                            '<th style="padding:8px 8px;text-align:center;border-bottom:2px solid #dee2e6;">Status</th>' +
                            '</tr></thead><tbody>';
                        var lineTotal = 0;
                        items.forEach(function(item, idx) {
                            var rowBg = idx % 2 === 0 ? '#fff' : '#f9fafb';
                            var statusColor = statusColors[item.kds_status] || '#6c757d';
                            var stationLabel = stationLabels[item.station] || (item.station || '—');
                            var lt = parseFloat(item.line_total) || 0;
                            lineTotal += lt;
                            html += '<tr style="background:' + rowBg + ';border-bottom:1px solid #f0f0f0;">' +
                                '<td style="padding:8px 10px;font-weight:600;">' + (item.item_name || '—') +
                                (item.notes ? '<div style="font-size:11px;color:#8B7355;font-weight:400;">' + item.notes + '</div>' : '') + '</td>' +
                                '<td style="padding:8px 6px;text-align:center;"><span style="background:#f3ece4;color:#8B7355;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:600;">' + (item.menu_type || '') + '</span></td>' +
                                '<td style="padding:8px 6px;text-align:center;font-weight:700;">' + (item.quantity || '') + '</td>' +
                                '<td style="padding:8px 10px;text-align:right;">' + (REVIEW_CURRENCY || '') + ' ' + Number(item.unit_price || 0).toFixed(2) + '</td>' +
                                '<td style="padding:8px 10px;text-align:right;font-weight:600;">' + (REVIEW_CURRENCY || '') + ' ' + lt.toFixed(2) + '</td>' +
                                '<td style="padding:8px 8px;text-align:center;font-size:12px;">' + stationLabel + '</td>' +
                                '<td style="padding:8px 8px;text-align:center;"><span style="color:' + statusColor + ';font-weight:600;text-transform:capitalize;">' + (item.kds_status || '—').replace(/_/g, ' ') + '</span></td>' +
                                '</tr>';
                        });
                        html += '</tbody><tfoot><tr style="background:#f8f9fa;font-weight:700;border-top:2px solid #dee2e6;">' +
                            '<td colspan="4" style="padding:8px 10px;text-align:right;">Order Total</td>' +
                            '<td style="padding:8px 10px;text-align:right;">' + (REVIEW_CURRENCY || '') + ' ' + lineTotal.toFixed(2) + '</td>' +
                            '<td colspan="2"></td></tr></tfoot></table></div>';
                        html += '<p style="margin:10px 0 0;font-size:12px;color:#6c757d;">' + items.length + ' line item' + (items.length !== 1 ? 's' : '') + '</p>';
                    }
                    if (typeof Modal !== 'undefined' && Modal.showMessage) {
                        Modal.showMessage({ title: '<i class="fas fa-list-ul" style="color:#8B7355;"></i> Items — ' + ref, message: html });
                    } else {
                        showFallbackOverlay('Items — ' + ref, html, { maxWidth: '780px' });
                    }
                })
                .catch(function() {
                    showFallbackOverlay('Error', '<p style="color:#c82333;padding:16px 0;">Could not load order items. Please try again.</p>', {});
                });
        }

        function openStockOrdersInsight(triggerEl) {
            const key = triggerEl ? triggerEl.getAttribute('data-insight-key') : '';
            if (!key) return;

            const template = document.getElementById('stock-orders-insight-template-' + key);
            const body = document.getElementById('stockOrdersInsightBody');
            const title = document.getElementById('stockOrdersInsightTitle');
            const modal = document.getElementById('stockOrdersInsightModal');
            const overlay = document.getElementById('stockOrdersInsightModal-overlay');
            if (!template || !body || !title || !modal) return;

            title.textContent = triggerEl.getAttribute('data-insight-title') || 'Operational Insight';
            body.innerHTML = template.innerHTML;

            if (window.Modal && typeof window.Modal.open === 'function') {
                window.Modal.open('stockOrdersInsightModal');
                return;
            }

            modal.classList.add('active');
            if (overlay) overlay.classList.add('active');
            document.body.classList.add('modal-open');
        }

        if (!window.__stockOrdersInsightHandlersBound) {
            document.addEventListener('click', function(e) {
                const trigger = e.target.closest('.js-stock-orders-insight-trigger');
                if (!trigger) return;

                const nestedLink = e.target.closest('a');
                if (nestedLink && trigger.contains(nestedLink)) {
                    return;
                }

                e.preventDefault();
                openStockOrdersInsight(trigger);
            });

            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                const trigger = e.target && e.target.closest ? e.target.closest('.js-stock-orders-insight-trigger') : null;
                if (!trigger) return;
                e.preventDefault();
                openStockOrdersInsight(trigger);
            });

            window.__stockOrdersInsightHandlersBound = true;
        }
    </script>
    <?php require __DIR__ . '/includes/offline-banner.php'; ?>
</body>

</html>

