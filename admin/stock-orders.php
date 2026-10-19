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
require_once __DIR__ . '/includes/pos-order-close.php';
require_once __DIR__ . '/includes/bulk-actions.php';
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
    $vatEnabled = rh_vat_enabled();
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

    $stmt = $pdo->prepare("\n        SELECT COALESCE(SUM((sri.quantity_per_portion / " . rh_stock_yield_sql('sri.yield_percent') . ") * i.cost_per_unit), 0)\n        FROM stock_recipes sr\n        INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id\n        INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id\n        WHERE sr.menu_item_id = ? AND sr.menu_type = ?\n    ");
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

function syncRestaurantOrderPayment(PDO $pdo, array $order, int $recordedBy, string $paymentMethod = 'cash', ?string $businessDate = null): void
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
        $mappedMethod,
        $businessDate
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
    $vatEnabled = rh_vat_enabled();
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
        rh_restore_room_service_charge_stock($pdo, $orderId, $charge, 'Room service order voided: ' . $reason, $voidedBy);
        $bookingIds[(int)$charge['booking_id']] = true;
    }

    foreach (array_keys($bookingIds) as $bookingId) {
        recalculateBookingFinancials((int)$bookingId);
    }

    return count($charges);
}

/** Sum of refund rows (pending, processing or completed) raised against a restaurant order. */
function restaurantOrderRefundTotal(PDO $pdo, int $orderId): float
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN COALESCE(refund_amount, 0) > 0 THEN refund_amount ELSE total_amount END), 0)
                           FROM payments
                          WHERE booking_type = 'restaurant' AND booking_id = ?
                            AND payment_type = 'refund' AND deleted_at IS NULL
                            AND COALESCE(refund_status, 'completed') IN ('pending', 'processing', 'completed')");
    $st->execute([$orderId]);
    return (float)$st->fetchColumn();
}

function getRestaurantOrderHealth(PDO $pdo, array $order): array
{
    $orderId = (int)$order['id'];
    $issues = [];

    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(line_total), 0) FROM stock_order_items WHERE order_id = ? AND kds_status <> 'void'");
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

    /* Net-paid rule: an 86'd line on a paid order lowers the order total and raises a refund row
     * (pending, processing or completed). The paid figure to compare with the order total is the
     * payments less those refunds - otherwise every 86'd paid order reads "unbalanced" and the
     * Reconcile button would rewrite the original sale row to match. */
    $refundedSum = round(restaurantOrderRefundTotal($pdo, $orderId), 2);
    $paymentSum = round($paymentSum - $refundedSum, 2);

    if ($order['status'] === 'paid' && ($activePaymentCount === 0 || abs($paymentSum - (float)$order['total_amount']) > BALANCE_TOLERANCE)) {
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

        $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(line_total), 0) FROM stock_order_items WHERE order_id = ? AND kds_status <> 'void'");
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
            /* Never rewrite the original sale row of an order that already has refund rows (an 86'd
             * line): the order total is intentionally lower than the sale, the difference lives in
             * the refund row, and re-syncing would take it off twice. */
            if (restaurantOrderRefundTotal($pdo, $orderId) > BALANCE_TOLERANCE) {
                $changes[] = 'payment ledger left as recorded (refund rows exist)';
            } else {
                // Reconcile corrects an existing sale; it must never re-date it. An existing ledger row
                // keeps its own date; a missing one is booked on the order's own paid date, not today.
                $paidDate = !empty($order['paid_at']) ? substr((string)$order['paid_at'], 0, 10) : null;
                syncRestaurantOrderPayment($pdo, $order, (int)$user['id'], (string)($order['payment_method'] ?: 'cash'), $paidDate);
                $changes[] = 'payment ledger synced';
            }
        }

        if (in_array($order['status'], ['cancelled'], true)) {
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

/* What this user may do from Orders & Tabs. Settling uses the till's own payment path
 * (pos.php pay_existing: deals, discounts, split bills, tips, the same ledger rows), so it
 * needs POS Take Payment & Settle plus access to the till. */
$soCanTakePayment = hasPermission((int)$user['id'], 'pos_take_payment');
$soCanSettleHere  = $soCanTakePayment && hasPermission((int)$user['id'], 'pos_till');
$soCanDiscount    = hasPermission((int)$user['id'], 'pos_discount');
$soCanForceServe  = hasPermission((int)$user['id'], 'pos_force_serve');
$soCanVoid        = in_array($user['role'] ?? '', ['admin', 'manager'], true);
/* Permanent delete of any order (api/delete-order.php re-checks this and the CSRF token). */
$soCanDelete      = hasPermission((int)$user['id'], 'pos_delete_order');
$soIsAjax         = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

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
                // Books a payment, so it needs the same gate as the till (Take Payment & Settle
                // AND till access), otherwise a payment-free till could still book cash here.
                if (!$soCanSettleHere) {
                    throw new RuntimeException('You do not have permission to take payments (POS Take Payment & Settle with till access).');
                }
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

                    if (function_exists('deleteCache')) rh_clear_stock_dashboard_cache();

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
                    rh_stamp_order_paid_by($pdo, $orderId, (int)$user['id']);

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

                    if (function_exists('deleteCache')) rh_clear_stock_dashboard_cache();

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
                $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
                if (mb_strlen($cancelReason) < 8) {
                    throw new RuntimeException('Cancel reason is required (at least 8 characters).');
                }

                $pdo->beginTransaction();
                $oh = $pdo->prepare("SELECT * FROM stock_orders WHERE id = ? FOR UPDATE");
                $oh->execute([$orderId]);
                $order = $oh->fetch(PDO::FETCH_ASSOC);
                if (!$order) throw new RuntimeException('Order not found.');
                /* Cancel = an open, completely unpaid order only. Paid / part-paid / refunded /
                 * already-reversed orders are refused; a paid order goes through Void, which writes
                 * the reversal record. */
                if ($order['status'] !== 'placed') throw new RuntimeException('Order is ' . str_replace('_', ' ', (string)$order['status']) . ' — only an open, unpaid order can be cancelled. Use Void for a paid order.');
                $cancelPaidChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL AND COALESCE(payment_status, 'completed') = 'completed'");
                $cancelPaidChk->execute([$orderId]);
                if ((int)($order['split_paid_count'] ?? 0) > 0 || (int)$cancelPaidChk->fetchColumn() > 0) throw new RuntimeException('Order has payment recorded against it — use Void (it writes the reversal).');

                // Enforce pre-prep cancellation policy: only cancel when all items are still pending
                $prepCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id=? AND kds_status IN ('preparing','in_progress','ready','collection','served')");
                $prepCheck->execute([$orderId]);
                if ((int)$prepCheck->fetchColumn() > 0) {
                    throw new RuntimeException('Cannot cancel: preparation has already started. Use Void (admin/manager) to reverse an in-progress order.');
                }
                if (in_array((string)($order['kitchen_status'] ?? ''), ['in_progress', 'ready', 'served'], true)) {
                    throw new RuntimeException('Cannot cancel: order is already in progress. Use Void instead.');
                }

                // Shared close: stock, folio charges, order + lines, ready pings, station notes, audit, KDS event.
                $closed = rh_close_pos_order($pdo, $orderId, (int)$user['id'], $cancelReason, 'cancel');
                $folioVoids = (int)$closed['folio_voided'];
                foreach ($closed['booking_ids'] as $closedBookingId) {
                    recalculateBookingFinancials((int)$closedBookingId);
                }
                $pdo->commit();

                foreach ($closed['notices'] as $notice) {
                    try {
                        rh_pos_notice_send($pdo, $notice);
                    } catch (Throwable $e) {
                        error_log('stock-orders cancel POS notify: ' . $e->getMessage());
                    }
                }
                if (function_exists('deleteCache')) rh_clear_stock_dashboard_cache();

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
                /* Only a live sale can be voided: 'placed' (open, possibly part-paid) or 'paid'. */
                if (!in_array($order['status'], ['placed', 'paid'], true)) throw new RuntimeException('Order is ' . str_replace('_', ' ', (string)$order['status']) . ' — only an open or paid order can be voided.');

                // Shared close: stock / wastage, folio charges, order + lines, payment reversal (contra rows), ready pings, STOP note, audit, KDS event.
                $closed = rh_close_pos_order($pdo, $orderId, (int)$user['id'], $voidReason, 'void');
                $folioVoids = (int)$closed['folio_voided'];
                foreach ($closed['booking_ids'] as $closedBookingId) {
                    recalculateBookingFinancials((int)$closedBookingId);
                }
                $pdo->commit();

                foreach ($closed['notices'] as $notice) {
                    try {
                        rh_pos_notice_send($pdo, $notice);
                    } catch (Throwable $e) {
                        error_log('stock-orders void POS notify: ' . $e->getMessage());
                    }
                }
                if (function_exists('deleteCache')) rh_clear_stock_dashboard_cache();

                $message = "Order {$order['reference']} voided. " . ($folioVoids > 0 ? "{$folioVoids} folio charge(s) voided and stock restored." : 'Stock restored.') . ' Reason logged.';
            } elseif ($action === 'reconcile_order') {
                if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
                    throw new RuntimeException('Only admins or managers can reconcile orders.');
                }
                $orderId = (int)($_POST['order_id'] ?? 0);
                if ($orderId <= 0) throw new RuntimeException('Invalid order.');
                $changes = reconcileRestaurantOrder($pdo, $orderId, $user);
                if (function_exists('deleteCache')) rh_clear_stock_dashboard_cache();
                $message = 'Order reconciled: ' . implode(', ', $changes) . '.';
            } elseif ($action === 'settle_order') {
                /* SETTLE — close a placed (open) order by collecting payment.
                 * The order already exists with items; we just need to record
                 * payment and flip status to 'paid'. */
                if (!$soCanSettleHere) {
                    throw new RuntimeException('You do not have permission to take payments (POS Take Payment & Settle with till access).');
                }
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
                if ((int)($order['split_paid_count'] ?? 0) > 0) throw new RuntimeException('This order is mid split-payment (' . (int)$order['split_paid_count'] . ' of ' . (int)($order['split_count'] ?? 0) . ' paid) — finish the remaining legs from the POS till; it cannot be settled in full again.');
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
                rh_stamp_order_paid_by($pdo, $orderId, (int)$user['id']);

                syncRestaurantOrderPayment($pdo, array_merge($order, ['status' => 'paid', 'payment_method' => $paymentMethod]), (int)$user['id'], $paymentMethod);
                logOrderAudit($pdo, $orderId, (int)$user['id'], (string)$user['full_name'], 'settled', json_encode([
                    'method'   => $paymentMethod,
                    'total'    => $totalAmount,
                    'tendered' => $paymentExtras['tendered_amount'],
                    'change'   => $paymentExtras['change_due'],
                ]));
                $pdo->commit();

                if (function_exists('deleteCache')) rh_clear_stock_dashboard_cache();

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

    // Orders & Tabs works in place: AJAX actions answer in JSON and the page refreshes its
    // list without navigating; plain form posts still redirect back.
    if ($soIsAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $error === '' || $error === null, 'message' => (string)$message, 'error' => (string)$error]);
        exit;
    }
    if ($message) $_SESSION['stock_msg'] = $message;
    if ($error)   $_SESSION['stock_err'] = $error;
    header('Location: stock-orders.php');
    exit;
}

/**
 * Restore stock for a cancelled / voided POS order. Delegates to the shared per-line restore
 * (rh_restore_pos_order_stock) used by the API void and cancel: matched on each line's original
 * deduction, skips lines an 86 or a KDS recall already handled, and records which kind of
 * deduction each restore reverses (source type + id, never id alone).
 */
function restoreFromPosOrder(PDO $pdo, int $orderId, ?int $doneBy): void
{
    rh_restore_pos_order_stock($pdo, $orderId, $doneBy, 'POS order cancelled / voided');
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

        // Today's shift summary (for drawer reconciliation visibility - anti-cheat).
        // Tender, revenue, tips and payouts come from rh_pos_shift_totals - the SAME maths as the
        // till's Z-report and the EOD: a sale counts in the window its paid_at falls in (so a tab
        // opened yesterday and settled today is today's cash), split bills are read per leg, tips
        // are cash in the drawer, and a sale refunded/voided later stays in its window with the
        // payout shown separately. Window is the trading window, not DATE(created_at)=CURDATE().
        require_once __DIR__ . '/includes/pos-shift-totals.php';
        $soShiftWindow = rh_station_union_business_window();
        $soShiftTotals = rh_pos_shift_totals($pdo, null, $soShiftWindow['start_sql'], $soShiftWindow['end_sql'], $soShiftWindow['start_sql']);
        $shiftStmt = $pdo->prepare("SELECT COUNT(*) AS orders_today FROM stock_orders WHERE created_at >= ? AND created_at < ?");
        $shiftStmt->execute([$soShiftWindow['start_sql'], $soShiftWindow['end_sql']]);
        $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $shift['revenue_today']    = (float)$soShiftTotals['sales_total'];
        $shift['cash_today']       = (float)$soShiftTotals['gross_cash'];
        $shift['mobile_today']     = (float)$soShiftTotals['gross_mobile'];
        $shift['card_today']       = (float)$soShiftTotals['gross_card'];
        $shift['refund_cash']      = (float)$soShiftTotals['refund_cash'];
        $shift['refund_mobile']    = (float)$soShiftTotals['refund_mobile'];
        $shift['refund_card']      = (float)$soShiftTotals['refund_card'];
        $shift['refund_amount']    = (float)$soShiftTotals['refund_amount'];
        $shift['tips_today']       = (float)$soShiftTotals['tips_total'];
        $shift['settled_today_n']  = (int)$soShiftTotals['orders_count'];
        $shift['voided_today']     = (float)$soShiftTotals['voids_amount'];
        $shift['void_count_today'] = (int)$soShiftTotals['voids_count'];
        $shift['earlier_tabs_n']   = (int)($soShiftTotals['settled_from_tabs_count'] ?? 0);
        $shift['earlier_tabs_amt'] = (float)($soShiftTotals['settled_from_tabs_amount'] ?? 0);

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
        $ordersSettledToday = (float)($shift['settled_today_n'] ?? 0);
        $operationsSnapshot['avg_ticket_today'] = $ordersSettledToday > 0 ? round(((float)($shift['revenue_today'] ?? 0)) / $ordersSettledToday, 2) : 0;

        // Revenue by cashier today: attributed to who TOOK the payment (paid_by, per split leg), in the
        // window each sale was paid - same basis as the Z-report - so the figure meant to catch drawer
        // discrepancies agrees with it. Open orders stay with whoever opened them.
        $soSaleWin   = rh_pos_sale_window_sql('o');
        $soSaleParam = [$soShiftWindow['start_sql'], $soShiftWindow['end_sql'], $soShiftWindow['start_sql'], $soShiftWindow['end_sql']];
        $soPayer     = rh_pos_payer_sql($pdo, 'o');
        $soLegPayer  = rh_pos_leg_payer_sql($pdo, 's', 'o');
        $cashierMap  = [];
        $cashierAdd  = function ($uid, $settled, $revenue, $open) use (&$cashierMap) {
            $uid = (int)$uid;
            if (!isset($cashierMap[$uid])) {
                $cashierMap[$uid] = ['uid' => $uid, 'orders_settled' => 0, 'revenue' => 0.0, 'orders_open' => 0];
            }
            $cashierMap[$uid]['orders_settled'] += (int)$settled;
            $cashierMap[$uid]['revenue']        += (float)$revenue;
            $cashierMap[$uid]['orders_open']    += (int)$open;
        };
        $cq = $pdo->prepare("SELECT $soPayer AS uid, COUNT(*) AS n, COALESCE(SUM(o.total_amount),0) AS rev
                             FROM stock_orders o WHERE COALESCE(o.split_count,1) <= 1 AND $soSaleWin GROUP BY $soPayer");
        $cq->execute($soSaleParam);
        foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) { $cashierAdd($r['uid'], $r['n'], $r['rev'], 0); }
        $cq = $pdo->prepare("SELECT $soLegPayer AS uid, COUNT(DISTINCT o.id) AS n, COALESCE(SUM(s.split_amount),0) AS rev
                             FROM stock_order_splits s INNER JOIN stock_orders o ON o.id = s.order_id
                             WHERE COALESCE(o.split_count,1) > 1 AND $soSaleWin GROUP BY $soLegPayer");
        $cq->execute($soSaleParam);
        foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) { $cashierAdd($r['uid'], $r['n'], $r['rev'], 0); }
        $cq = $pdo->prepare("SELECT o.created_by AS uid, COUNT(*) AS n FROM stock_orders o
                             WHERE o.status = 'placed' AND o.created_at >= ? AND o.created_at < ? GROUP BY o.created_by");
        $cq->execute([$soShiftWindow['start_sql'], $soShiftWindow['end_sql']]);
        foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) { $cashierAdd($r['uid'], 0, 0, $r['n']); }
        $cashierNames = [];
        if ($cashierMap) {
            $cph = implode(',', array_fill(0, count($cashierMap), '?'));
            $cn = $pdo->prepare("SELECT id, full_name FROM admin_users WHERE id IN ($cph)");
            $cn->execute(array_keys($cashierMap));
            foreach ($cn->fetchAll(PDO::FETCH_ASSOC) as $r) { $cashierNames[(int)$r['id']] = (string)$r['full_name']; }
        }
        $cashierStats = [];
        foreach ($cashierMap as $uid => $c) {
            $cashierStats[] = [
                'cashier'        => $cashierNames[$uid] ?? 'Unknown',
                'orders_settled' => $c['orders_settled'],
                'revenue'        => round($c['revenue'], 2),
                'orders_open'    => $c['orders_open'],
            ];
        }
        usort($cashierStats, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });

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

        // Payment mix: the same sale window as the shift totals (by paid_at, any later status),
        // split orders read per leg tender.
        $mixMap = [];
        $mixAdd = function ($method, $n, $amt) use (&$mixMap) {
            $k = ($method === null || $method === '') ? 'unassigned' : (string)$method;
            if (!isset($mixMap[$k])) {
                $mixMap[$k] = ['payment_method' => $k, 'orders_count' => 0, 'amount_total' => 0.0];
            }
            $mixMap[$k]['orders_count'] += (int)$n;
            $mixMap[$k]['amount_total'] += (float)$amt;
        };
        $mq = $pdo->prepare("SELECT o.payment_method AS m, COUNT(*) AS n, COALESCE(SUM(o.total_amount),0) AS amt
                             FROM stock_orders o WHERE COALESCE(o.split_count,1) <= 1 AND $soSaleWin GROUP BY o.payment_method");
        $mq->execute($soSaleParam);
        foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $r) { $mixAdd($r['m'], $r['n'], $r['amt']); }
        $mq = $pdo->prepare("SELECT s.payment_method AS m, COUNT(DISTINCT o.id) AS n, COALESCE(SUM(s.split_amount),0) AS amt
                             FROM stock_order_splits s INNER JOIN stock_orders o ON o.id = s.order_id
                             WHERE COALESCE(o.split_count,1) > 1 AND $soSaleWin GROUP BY s.payment_method");
        $mq->execute($soSaleParam);
        foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $r) { $mixAdd($r['m'], $r['n'], $r['amt']); }
        $paymentMixToday = array_values($mixMap);
        usort($paymentMixToday, function ($a, $b) { return $b['amount_total'] <=> $a['amount_total']; });

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
            <h2 class="page-title"><i class="fas fa-receipt" style="color:#7E684B;"></i> Restaurant Orders</h2>
        </div>
        <?php /* #soLive is refreshed in place after every action (no page reload / redirect). */ ?>
        <div id="soLive">

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
                <div class="shift-card__sub">By payment time</div>
                <?php if ((int)($shift['earlier_tabs_n'] ?? 0) > 0): ?>
                    <div class="shift-card__sub">Settled from earlier tabs: <?php echo (int)$shift['earlier_tabs_n']; ?> &middot; <?php echo $currency_symbol . ' ' . number_format((float)$shift['earlier_tabs_amt'], 2); ?></div>
                <?php endif; ?>
            </a>
            <a class="shift-card<?php echo ($filterMethod === 'cash') ? ' active' : ''; ?>" href="stock-orders.php?date=today&payment_method=cash" title="Today's cash taken (sales settled today, tips included). Refund/void payouts are shown in the detail view.">
                <div class="lbl">Cash</div>
                <div class="val"><?php echo $currency_symbol . ' ' . number_format((float)($shift['cash_today'] ?? 0), 2); ?></div>
                <div class="shift-card__sub">By payment time</div>
                <div class="shift-card__sub">Tips (included): <?php echo $currency_symbol . ' ' . number_format((float)($shift['tips_today'] ?? 0), 2); ?></div>
            </a>
            <a class="shift-card<?php echo ($filterMethod === 'mobile_money') ? ' active' : ''; ?>" href="stock-orders.php?date=today&payment_method=mobile_money" title="Today's mobile-money orders">
                <div class="lbl">Mobile Money</div>
                <div class="val"><?php echo $currency_symbol . ' ' . number_format((float)($shift['mobile_today'] ?? 0), 2); ?></div>
                <div class="shift-card__sub">By payment time</div>
            </a>
            <a class="shift-card<?php echo ($filterMethod === 'card_manual') ? ' active' : ''; ?>" href="stock-orders.php?date=today&payment_method=card_manual" title="Today's card orders">
                <div class="lbl">Card</div>
                <div class="val"><?php echo $currency_symbol . ' ' . number_format((float)($shift['card_today'] ?? 0), 2); ?></div>
                <div class="shift-card__sub">By payment time</div>
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
            <a class="ops-banner ops-banner--warn" href="stock-orders.php?health=review#rh-results" title="Open the review queue for orders needing attention">
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
                    style="background:#7E684B;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:13px;cursor:pointer;display:flex;align-items:center;gap:6px;"
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
                        <div class="lbl"><i class="fas fa-user-check" style="color:#7E684B;font-size:11px;"></i> Revenue by Cashier</div>
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
                        <i class="fas fa-arrow-right-arrow-left" style="color:#7E684B;"></i>
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
                        <strong class="ops-panel__header-title"><i class="fas fa-layer-group" style="color:#7E684B;"></i> Station Queue</strong>
                        <a class="ops-panel__header-link" href="stock-orders.php?status=placed#rh-results">View open orders</a>
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
                                                    style="margin-left:6px;color:#7E684B;font-size:11px;text-decoration:none;"
                                                    onclick="event.stopPropagation();"
                                                    onmouseover="this.style.color='#0d6efd'" onmouseout="this.style.color='#7E684B'">
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
                        <strong class="ops-panel__header-title"><i class="fas fa-wallet" style="color:#7E684B;"></i> Payment Mix Today</strong>
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
                            $mixHref = 'stock-orders.php?date=today&payment_method=' . rawurlencode($methodKey) . '#rh-results';
                            $isMixActive = $filterDate === 'today' && $filterMethod === $methodKey;
                        ?>
                            <a class="ops-panel__metric-link<?php echo $isMixActive ? ' ops-panel__metric-link--active' : ''; ?>"
                                href="<?php echo htmlspecialchars($mixHref, ENT_QUOTES, 'UTF-8'); ?>"
                                title="Filter orders paid with <?php echo htmlspecialchars($methodLabel); ?>">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                                    <span style="font-size:13px;font-weight:600;">
                                        <i class="fas <?php echo $methodIcon; ?>" style="color:#7E684B;width:16px;"></i>
                                        <?php echo htmlspecialchars($methodLabel); ?>
                                    </span>
                                    <span style="font-size:12px;color:#6c757d;">
                                        <?php echo (int)($mix['orders_count'] ?? 0); ?> orders &middot;
                                        <?php echo $currency_symbol . ' ' . number_format($mixAmt, 2); ?>
                                    </span>
                                </div>
                                <div style="height:10px;background:#e9ecef;border-radius:5px;overflow:hidden;" title="<?php echo number_format($share, 1); ?>% of today's revenue">
                                    <div style="width:<?php echo number_format($share, 1); ?>%;height:100%;background:#7E684B;border-radius:5px;transition:width .4s;"></div>
                                </div>
                                <div style="font-size:11px;color:#7E684B;font-weight:600;margin-top:2px;"><?php echo number_format($share, 1); ?>%</div>
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
                        <strong class="ops-panel__header-title"><i class="fas <?php echo isRestaurantEnabled() ? 'fa-utensils' : 'fa-cash-register'; ?>" style="color:#7E684B;"></i> Order Channels Today</strong>
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
                            $chHref = 'stock-orders.php?date=today&order_type=' . rawurlencode($chType) . '#rh-results';
                            $isChannelActive = $filterDate === 'today' && $filterOrderType === $chType;
                        ?>
                            <a class="ops-panel__metric-link<?php echo $isChannelActive ? ' ops-panel__metric-link--active' : ''; ?>"
                                href="<?php echo htmlspecialchars($chHref, ENT_QUOTES, 'UTF-8'); ?>"
                                title="Filter orders for <?php echo htmlspecialchars($chLabel); ?>">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                                    <span style="font-size:13px;font-weight:600;">
                                        <i class="fas <?php echo $chIcon; ?>" style="color:#7E684B;width:16px;"></i>
                                        <?php echo htmlspecialchars($chLabel); ?>
                                    </span>
                                    <span style="font-size:12px;color:#6c757d;">
                                        <?php echo (int)($ch['orders_count'] ?? 0); ?> orders &middot;
                                        <?php echo $currency_symbol . ' ' . number_format($chRev, 2); ?> &middot;
                                        <?php echo (int)($ch['active_count'] ?? 0); ?> active
                                    </span>
                                </div>
                                <div style="height:10px;background:#e9ecef;border-radius:5px;overflow:hidden;" title="<?php echo number_format($chPct, 1); ?>% of today's revenue">
                                    <div style="width:<?php echo number_format($chPct, 1); ?>%;height:100%;background:#8F6A35;border-radius:5px;transition:width .4s;"></div>
                                </div>
                                <div style="font-size:11px;color:#8F6A35;font-weight:600;margin-top:2px;"><?php echo number_format($chPct, 1); ?>%</div>
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
                        <strong class="ops-panel__header-title"><i class="fas fa-chart-bar" style="color:#7E684B;"></i> Hourly Activity Today</strong>
                        <span class="ops-panel__header-note">Click an hour bar to filter that hour</span>
                    </div>
                    <?php if (!empty($hourlyBreakdown)): ?>
                        <div style="display:flex;align-items:flex-end;gap:4px;height:100px;margin-top:14px;padding-bottom:22px;position:relative;">
                            <?php foreach ($hourlyBreakdown as $h):
                                $isPeak = $peakHour && (int)$h['hour'] === (int)$peakHour['hour'];
                                $barPct = $maxHourRev > 0 ? round(((float)$h['revenue'] / $maxHourRev) * 78, 0) : 0;
                                $hr     = (int)$h['hour'];
                                $hlbl   = $hr === 0 ? '12a' : ($hr < 12 ? $hr . 'a' : ($hr === 12 ? '12p' : ($hr - 12) . 'p'));
                                $hourHref = 'stock-orders.php?date=today&hour=' . $hr . '#rh-results';
                                $isHourActive = $filterDate === 'today' && $filterHour !== '' && (int)$filterHour === $hr;
                            ?>
                                <a class="ops-panel__chart-link<?php echo $isHourActive ? ' ops-panel__chart-link--active' : ''; ?>"
                                    href="<?php echo htmlspecialchars($hourHref, ENT_QUOTES, 'UTF-8'); ?>"
                                    style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100px;position:relative;"
                                    title="<?php echo $hlbl; ?>: <?php echo (int)$h['orders_count']; ?> orders · <?php echo $currency_symbol . ' ' . number_format((float)$h['revenue'], 2); ?>">
                                    <?php if ((int)$h['orders_count'] > 0): ?>
                                        <span style="font-size:9px;color:#7E684B;margin-bottom:2px;"><?php echo (int)$h['orders_count']; ?></span>
                                    <?php endif; ?>
                                    <div class="ops-panel__chart-bar" style="width:100%;background:<?php echo $isPeak ? '#8F6A35' : '#c8b89a'; ?>;height:<?php echo max($barPct, $h['revenue'] > 0 ? 4 : 0); ?>px;border-radius:3px 3px 0 0;min-height:<?php echo $h['revenue'] > 0 ? '4' : '0'; ?>px;transition:height .3s;"></div>
                                    <span style="font-size:9px;color:<?php echo $isPeak ? '#8F6A35' : '#999'; ?>;font-weight:<?php echo $isPeak ? '700' : '400'; ?>;position:absolute;bottom:0;white-space:nowrap;"><?php echo htmlspecialchars($hlbl); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <div style="font-size:11px;color:#6c757d;margin-top:4px;text-align:right;">
                            <span style="display:inline-block;width:10px;height:10px;background:#8F6A35;border-radius:2px;margin-right:4px;vertical-align:middle;"></span>Peak hour &nbsp;
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
                        <a class="ops-panel__header-link" href="stock-orders.php?date=today&status=voided#rh-results">Open voided orders</a>
                    </div>
                    <?php if ($totalVoidedToday > 0): ?>
                        <div style="margin-top:10px;font-size:13px;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #f5c6cb;">
                                <span><?php echo $totalVoidedToday; ?> void<?php echo $totalVoidedToday !== 1 ? 's' : ''; ?></span>
                                <strong style="color:#721c24;"><?php echo $currency_symbol . ' ' . number_format($totalVoidedAmt, 2); ?> lost</strong>
                            </div>
                            <?php foreach ($voidReasonsToday as $vr): ?>
                                <a class="ops-panel__reason-link" href="stock-orders.php?date=today&status=voided#rh-results" title="View today's voided orders">
                                    <span style="color:#555;flex:1;">
                                        <i class="fas fa-circle" style="font-size:6px;color:#dc3545;vertical-align:middle;margin-right:4px;"></i>
                                        <?php echo htmlspecialchars((string)($vr['reason'] ?? 'Unknown')); ?>
                                    </span>
                                    <span style="white-space:nowrap;font-weight:600;color:#721c24;"><?php echo (int)($vr['count'] ?? 0); ?> &times;</span>
                                </a>
                            <?php endforeach; ?>
                            <div style="margin-top:10px;">
                                <a href="stock-orders.php?date=today&status=voided#rh-results" style="font-size:12px;color:#7E684B;text-decoration:none;font-weight:600;">
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
                    <i class="fas fa-fire" style="color:#7E684B;"></i>
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
                                            <span style="background:#f3ece4;color:#7E684B;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:600;">
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
                            '<i class="fas fa-bed" style="color:#7E684B;"></i> Room ' + (item.table_number || '—') :
                            (item.table_number ? '<i class="fas fa-chair" style="color:#7E684B;"></i> ' + item.table_number : orderTypeLabel);
                        html += '<tr style="background:' + rowBg + ';border-bottom:1px solid #f0f0f0;">' +
                            '<td style="padding:7px 10px;font-size:12px;color:#6c757d;font-family:monospace;"><a href="' + orderHref + '" target="_blank" rel="noopener" style="color:#7E684B;text-decoration:none;font-weight:600;">' + (item.order_reference || '#' + item.order_id) + '</a></td>' +
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
                    rhOpenLifecycle(orderId);
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
                html += '<h4 style="margin:0 0 8px;font-size:14px;color:#374151;"><i class="fas fa-chart-line" style="color:#7E684B;"></i> Accounting impact</h4>';
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
                    html += '<h4 style="margin:0 0 8px;font-size:14px;color:#374151;"><i class="fas fa-timeline" style="color:#7E684B;"></i> Audit trail</h4>';
                    html += '<div style="border-left:3px solid #dee2e6;padding-left:14px;display:flex;flex-direction:column;gap:8px;">';
                    d.audit.forEach(function(entry) {
                        var evtColor = {
                            voided: '#c82333',
                            cancelled: '#c82333',
                            reconciled: '#155724',
                            paid: '#155724'
                        } [entry.event] || '#7E684B';
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
                html += '<h4 style="margin:0 0 8px;font-size:14px;color:#374151;"><i class="fas fa-wrench" style="color:#7E684B;"></i> How to reconcile</h4>';
                html += '<ol style="margin:0;padding-left:20px;font-size:13px;">';
                d.issues.forEach(function(issue) {
                    html += '<li style="padding:4px 0;">' + _reviewTip(issue) + '</li>';
                });
                html += '</ol></div>';

                // ── Reconcile action (admin/manager only) ─────────────────────
                if (formId && REVIEW_CAN_RECONCILE) {
                    html += '<div style="padding-top:14px;border-top:1px solid #e9ecef;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">';
                    html += '<button type="button" onclick="rhOpenLifecycle(' + orderId + ')" class="btn" style="background:#f8f9fa;color:#495057;border:1px solid #ced4da;padding:8px 16px;border-radius:6px;font-size:13px;cursor:pointer;font:inherit;">' +
                        '<i class="fas fa-timeline"></i> Full Timeline</a>';
                    html += '<button type="button" onclick="_doReconcile()" style="padding:8px 18px;background:#7E684B;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">' +
                        '<i class="fas fa-rotate"></i> Run Reconciliation &amp; Sync Accounting</button>';
                    html += '</div>';
                }

                if (typeof Modal !== 'undefined' && Modal.showMessage) {
                    Modal.showMessage({
                        title: '<i class="fas fa-magnifying-glass" style="color:#7E684B;"></i> Review &mdash; ' + d.reference,
                        message: html
                    });
                } else {
                    showFallbackOverlay('<i class="fas fa-magnifying-glass" style="color:#7E684B;"></i> Review &mdash; ' + d.reference, html, {
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

        <h3 style="margin-top:32px;" id="rh-results" data-rh-results>Recent Orders</h3>
        <?php /* Shared bulk bar (admin-bulk.js shows/hides it and counts the selection). The buttons submit this
              form only so the shared markup works; the page script below intercepts the submit and runs
              the same cancel / void / reconcile / delete / export flows as before. */ ?>
        <?php adminBulkBarStart('so_bulk', '#soOrdersTable', $csrf_token); ?>
            <?php adminBulkButton('cancel', 'Cancel', 'fa-circle-xmark', '', 'btn-danger'); ?>
            <?php if ($soCanVoid): ?>
                <?php adminBulkButton('void', 'Void', 'fa-ban', '', 'btn-danger'); ?>
            <?php endif; ?>
            <?php if ($canReconcile): ?>
                <?php adminBulkButton('reconcile', 'Reconcile', 'fa-scale-balanced', '', 'btn-warning'); ?>
            <?php endif; ?>
            <?php if ($soCanDelete): ?>
                <?php adminBulkButton('delete', 'Delete', 'fa-trash-can', '', 'btn-danger'); ?>
            <?php endif; ?>
            <?php adminBulkButton('export', 'Export', 'fa-file-csv', '', 'btn-secondary'); ?>
        <?php adminBulkBarEnd(); ?>
        <div class="table-responsive">
            <table class="order-list" id="soOrdersTable">
                <thead>
                    <tr>
                        <th class="admin-bulk-col"><?php echo adminBulkAllBox('Select all orders on this page'); ?></th>
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
                        <tr data-so-id="<?php echo (int)$o['id']; ?>"
                            data-so-ref="<?php echo htmlspecialchars((string)$o['reference'], ENT_QUOTES); ?>"
                            data-so-status="<?php echo htmlspecialchars((string)$o['status'], ENT_QUOTES); ?>"
                            data-so-type="<?php echo htmlspecialchars((string)$o['order_type'], ENT_QUOTES); ?>"
                            data-so-total="<?php echo htmlspecialchars(number_format((float)$o['total_amount'], 2, '.', ''), ENT_QUOTES); ?>"
                            data-so-method="<?php echo htmlspecialchars((string)($o['payment_method'] ?? ''), ENT_QUOTES); ?>"
                            data-so-customer="<?php echo htmlspecialchars((string)($o['customer_name'] ?? ''), ENT_QUOTES); ?>"
                            data-so-created="<?php echo htmlspecialchars((string)$o['created_at'], ENT_QUOTES); ?>"
                            data-so-review="<?php echo empty($health['is_balanced']) ? '1' : '0'; ?>">
                            <td class="admin-bulk-col"><?php echo adminBulkRowBox((int)$o['id'], 'Select order ' . (string)$o['reference']); ?></td>
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
                            <?php
                            /* Row actions: Timeline + Items always, ONE contextual primary (Settle, else Receipt, else Review);
                               Open, the other of Receipt/Review and the danger actions (Cancel, Void, Delete) sit in the shared More menu. */
                            $soIsPlaced    = ($o['status'] === 'placed');
                            $soShowSettle  = $soIsPlaced && ($o['order_type'] ?? '') !== 'room_service' && $soCanSettleHere;
                            $soShowReceipt = in_array($o['status'], ['paid', 'voided', 'cancelled'], true) || $o['order_type'] === 'room_service';
                            $soShowReview  = empty($health['is_balanced']);
                            $soPrimary     = $soShowSettle ? 'settle' : ($soShowReceipt ? 'receipt' : ($soShowReview ? 'review' : ''));
                            $soMoreReceipt = $soShowReceipt && $soPrimary !== 'receipt';
                            $soMoreReview  = $soShowReview && $soPrimary !== 'review';
                            $soCanVoidRow  = $soCanVoid && ($soIsPlaced || in_array($o['status'], ['paid', 'completed'], true));
                            $soMorePlain   = $soIsPlaced || $soMoreReceipt || $soMoreReview;
                            $soMoreDanger  = $soIsPlaced || $soCanVoidRow || $soCanDelete;
                            ?>
                            <td class="so-actions-cell">
                                <div class="so-actions">
                                    <button type="button" onclick="rhOpenLifecycle(<?php echo (int)$o['id']; ?>)" class="mini-action"><i class="fas fa-stream"></i> Timeline</button>
                                    <button type="button" class="mini-action"
                                        onclick="showOrderItems(<?php echo (int)$o['id']; ?>, <?php echo htmlspecialchars(json_encode($o['reference']), ENT_QUOTES, 'UTF-8'); ?>)"
                                        title="View line items for this order">
                                        <i class="fas fa-list-ul"></i> Items
                                    </button>
                                    <?php if ($soPrimary === 'settle'): ?>
                                        <button type="button" class="mini-action success"
                                            onclick="soOpenSettle(<?php echo (int)$o['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$o['reference']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode((float)$o['total_amount']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo (int)($o['split_count'] ?? 1); ?>, <?php echo (int)($o['split_paid_count'] ?? 0); ?>)"
                                            title="Take payment and close this order (deals, discount, split bill and tip as on the till)">
                                            <i class="fas fa-circle-check"></i> Settle<?php echo (int)($o['split_paid_count'] ?? 0) > 0 ? ' (' . (int)$o['split_paid_count'] . '/' . (int)$o['split_count'] . ' paid)' : ''; ?>
                                        </button>
                                    <?php elseif ($soPrimary === 'receipt'): ?>
                                        <a href="stock-receipt.php?id=<?php echo (int)$o['id']; ?>" class="mini-action primary"><i class="fas fa-receipt"></i> Receipt</a>
                                    <?php elseif ($soPrimary === 'review'): ?>
                                        <button class="mini-action warn" type="button"
                                            onclick="showOrderReview(<?php echo (int)$o['id']; ?>, <?php echo $canReconcile ? "'rf-" . (int)$o['id'] . "'" : 'null'; ?>)"
                                            title="View full payment review: timeline, accounting impact, and reconciliation tips">
                                            <i class="fas fa-magnifying-glass"></i> Review
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($soMorePlain || $soMoreDanger): ?>
                                        <div class="actions-more">
                                            <button type="button" class="mini-action actions-more-toggle" title="More actions" aria-label="More actions" aria-haspopup="true" onclick="toggleSoActionsMore(this, event)">
                                                <i class="fas fa-ellipsis-vertical"></i> More
                                            </button>
                                            <div class="actions-more-menu">
                                                <?php if ($soIsPlaced): ?>
                                                    <button type="button" onclick="closeSoActionMenus(); rhOpenLifecycle(<?php echo (int)$o['id']; ?>)" title="View live order status, items, and kitchen progress"><i class="fas fa-hourglass-half"></i> Open</button>
                                                <?php endif; ?>
                                                <?php if ($soMoreReceipt): ?>
                                                    <a href="stock-receipt.php?id=<?php echo (int)$o['id']; ?>"><i class="fas fa-receipt"></i> Receipt</a>
                                                <?php endif; ?>
                                                <?php if ($soMoreReview): ?>
                                                    <button type="button"
                                                        onclick="closeSoActionMenus(); showOrderReview(<?php echo (int)$o['id']; ?>, <?php echo $canReconcile ? "'rf-" . (int)$o['id'] . "'" : 'null'; ?>)"
                                                        title="View full payment review: timeline, accounting impact, and reconciliation tips">
                                                        <i class="fas fa-magnifying-glass"></i> Review
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($soMorePlain && $soMoreDanger): ?><hr class="menu-divider"><?php endif; ?>
                                                <?php if ($soIsPlaced): ?>
                                                    <button class="text-danger" type="button" onclick="closeSoActionMenus(); soRowAction('cancel', <?php echo (int)$o['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$o['reference']), ENT_QUOTES, 'UTF-8'); ?>)"><i class="fas fa-circle-xmark"></i> Cancel</button>
                                                <?php endif; ?>
                                                <?php if ($soCanVoidRow): ?>
                                                    <button class="text-danger" type="button" onclick="closeSoActionMenus(); soRowAction('void', <?php echo (int)$o['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$o['reference']), ENT_QUOTES, 'UTF-8'); ?>)"<?php if ($soIsPlaced): ?> title="Void an open order whose preparation has started (stock restored, payments reversed)"<?php endif; ?>><i class="fas fa-ban"></i> Void</button>
                                                <?php endif; ?>
                                                <?php if ($soCanDelete): ?>
                                                    <button class="text-danger" type="button" onclick="closeSoActionMenus(); soRowAction('delete', <?php echo (int)$o['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$o['reference']), ENT_QUOTES, 'UTF-8'); ?>)" title="Permanently delete this order (any status). Stock is put back, payments removed, a deletion record is kept."><i class="fas fa-trash-can"></i> Delete</button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($soShowReview && $canReconcile): ?>
                                    <form method="POST" id="rf-<?php echo (int)$o['id']; ?>" style="display:none;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="reconcile_order">
                                        <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="15" style="text-align:center;padding:30px;color:#6c757d;">No orders yet.</td>
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
                        style="padding:6px 12px;border:1px solid <?php echo $pgActive ? 'var(--color-primary,#766550)' : '#dee2e6'; ?>;background:<?php echo $pgActive ? 'var(--color-primary,#766550)' : '#fff'; ?>;color:<?php echo $pgActive ? '#fff' : '#374151'; ?>;border-radius:4px;font-size:13px;text-decoration:none;"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <span style="padding:6px 8px;font-size:12px;color:#888;">
                    Showing <?php echo (($orders_page - 1) * $orders_per_page) + 1; ?>–<?php echo min($orders_page * $orders_per_page, $orders_total); ?> of <?php echo $orders_total; ?>
                </span>
            </nav>
        <?php endif; ?>
        </div><?php /* /#soLive */ ?>

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
            <p class="stock-insight-note">Counted by payment time (not order created date): a tab opened earlier and paid today counts today. Supports drawer reconciliation and should align with cash settlement records.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Cash collected today</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['cash_today'] ?? 0), 2); ?></td></tr>
                    <tr><th>of which tips</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['tips_today'] ?? 0), 2); ?></td></tr>
                    <tr><th>Settled from earlier tabs</th><td><?php echo (int)($shift['earlier_tabs_n'] ?? 0); ?> &middot; <?php echo $currency_symbol . ' ' . number_format((float)($shift['earlier_tabs_amt'] ?? 0), 2); ?></td></tr>
                    <tr><th>Refund / void payouts</th><td>-<?php echo $currency_symbol . ' ' . number_format((float)($shift['refund_cash'] ?? 0), 2); ?></td></tr>
                    <tr><th>Expected after payouts</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['cash_today'] ?? 0) - (float)($shift['refund_cash'] ?? 0), 2); ?></td></tr>
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
                    <tr><th>Refund / void payouts</th><td>-<?php echo $currency_symbol . ' ' . number_format((float)($shift['refund_mobile'] ?? 0), 2); ?></td></tr>
                    <tr><th>Expected after payouts</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['mobile_today'] ?? 0) - (float)($shift['refund_mobile'] ?? 0), 2); ?></td></tr>
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
                    <tr><th>Refund / void payouts</th><td>-<?php echo $currency_symbol . ' ' . number_format((float)($shift['refund_card'] ?? 0), 2); ?></td></tr>
                    <tr><th>Expected after payouts</th><td><?php echo $currency_symbol . ' ' . number_format((float)($shift['card_today'] ?? 0) - (float)($shift['refund_card'] ?? 0), 2); ?></td></tr>
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

    <div id="soVoidModal" class="modal-overlay" data-modal role="dialog" aria-modal="true" aria-labelledby="soVoidTitle">
        <div class="modal-content" style="max-width:min(96vw,26rem); width:min(96vw,26rem);">
            <div class="modal-header">
                <h3 class="modal-title" id="soVoidTitle"><i class="fas fa-ban"></i> Void Order</h3>
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

    <!-- Settle Order Modal: the till's payment path (pos.php pay_existing) - deals, discount,
         split bill and tip are applied and recorded exactly as on the POS. -->
    <style>
        .so-settle-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 12px; }
        .so-settle-grid label, .so-settle-field label { font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px; }
        .so-settle-grid input, .so-settle-grid select, .so-settle-field input, .so-settle-field select { width: 100%; padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .so-settle-field { margin-bottom: 10px; }
        .so-settle-sum { border: 1px solid #e5e7eb; border-radius: 6px; margin: 10px 0; font-size: 14px; }
        .so-settle-sum div { display: flex; justify-content: space-between; padding: 6px 12px; }
        .so-settle-sum div + div { border-top: 1px solid #f1f1f1; }
        .so-settle-sum .is-due { font-weight: 700; background: #f0fdf4; }
        .so-settle-sum .is-deal { color: #166534; }
        @media (max-width: 560px) { .so-settle-grid { grid-template-columns: 1fr; } }
    </style>
    <div id="soSettleModal" class="modal-overlay" data-modal role="dialog" aria-modal="true" aria-labelledby="soSettleTitle">
        <div class="modal-content" style="max-width:min(96vw,34rem);width:min(96vw,34rem);">
            <div class="modal-header">
                <h3 class="modal-title" id="soSettleTitle"><i class="fas fa-circle-check"></i> Settle <span id="soSettleRef"></span></h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="soCloseSettle()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="so-settle-sum">
                    <div><span>Order total</span><span id="soSumTotal"></span></div>
                    <div class="is-deal" id="soSumDealRow" hidden><span id="soSumDealLabel">Deals</span><span id="soSumDeal"></span></div>
                    <div id="soSumDiscRow" hidden><span>Discount</span><span id="soSumDisc"></span></div>
                    <div id="soSumSplitRow" hidden><span id="soSumSplitLabel"></span><span id="soSumSplit"></span></div>
                    <div class="is-due"><span>To collect now</span><span id="soSumDue"></span></div>
                </div>
                <div class="so-settle-grid">
                    <div>
                        <label for="soSettleMethod">Payment method</label>
                        <select id="soSettleMethod" onchange="soUpdateSettleFields()">
                            <option value="">— Select —</option>
                            <option value="cash">Cash</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="card_manual">Card (manual / slip)</option>
                        </select>
                    </div>
                    <div>
                        <label for="soSettleSplit">Split bill</label>
                        <select id="soSettleSplit" onchange="soRecalcSettle()">
                            <?php for ($sp = 1; $sp <= 10; $sp++): ?>
                                <option value="<?php echo $sp; ?>"><?php echo $sp === 1 ? 'No split' : $sp . ' ways'; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="soSettleTip">Tip (optional)</label>
                        <input type="number" id="soSettleTip" min="0" step="0.01" placeholder="0.00" oninput="soRecalcSettle()">
                    </div>
                    <?php if ($soCanDiscount): ?>
                        <div>
                            <label for="soSettleDisc">Discount amount</label>
                            <input type="number" id="soSettleDisc" min="0" step="0.01" placeholder="0.00" oninput="soRecalcSettle()">
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($soCanDiscount): ?>
                    <div class="so-settle-field" id="soSettleDiscReasonWrap" hidden>
                        <label for="soSettleDiscReason">Discount reason</label>
                        <input type="text" id="soSettleDiscReason" maxlength="255" placeholder="e.g. Manager comp, loyalty">
                    </div>
                <?php endif; ?>
                <div id="soSettleCashFields" class="so-settle-field" hidden>
                    <label for="soSettleTendered">Amount tendered</label>
                    <input type="number" id="soSettleTendered" min="0" step="0.01" placeholder="0.00" oninput="soCalcChange()">
                    <div id="soSettleChangeDisplay" style="font-size:13px;margin-top:6px;font-weight:600;min-height:20px;"></div>
                </div>
                <div id="soSettleMobileFields" class="so-settle-grid" hidden>
                    <div><label for="soSettleMobileProvider">Provider</label><input type="text" id="soSettleMobileProvider" placeholder="e.g. Airtel Money, TNM Mpamba"></div>
                    <div><label for="soSettleMobileRef">Transaction reference</label><input type="text" id="soSettleMobileRef" placeholder="e.g. P234567890"></div>
                </div>
                <div id="soSettleCardFields" class="so-settle-grid" hidden>
                    <div><label for="soSettleCardLast4">Card last 4 digits</label><input type="text" id="soSettleCardLast4" maxlength="4" inputmode="numeric" placeholder="1234"></div>
                    <div><label for="soSettleCardAuth">Auth code (from slip)</label><input type="text" id="soSettleCardAuth" placeholder="e.g. 123456"></div>
                </div>
                <?php if ($soCanForceServe): ?>
                    <label class="so-settle-field" style="display:flex;gap:8px;align-items:flex-start;font-size:13px;">
                        <input type="checkbox" id="soSettleForce" style="width:auto;margin-top:2px;">
                        <span>Some kitchen items were never bumped: mark them served and settle anyway (recorded in the order log)</span>
                    </label>
                <?php endif; ?>
                <div id="soSettleErr" style="color:#c82333;font-size:13px;" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="soCloseSettle()">Cancel</button>
                <button type="button" class="btn btn-primary" id="soSettleBtn" onclick="soDoSettle()"><i class="fas fa-circle-check"></i> Take payment</button>
            </div>
        </div>
    </div>
    <?php /* Modals sit before the footer: admin-footer.php closes #rh-admin-page, and the admin SPA
       only swaps that container, so markup printed after it is lost on in-place navigation. */ ?>
    <?php require_once 'includes/admin-footer.php'; ?>
    <script>
        var SO_CSRF = <?php echo json_encode($csrf_token); ?>;
        var SO_CUR = (typeof REVIEW_CURRENCY !== 'undefined' && REVIEW_CURRENCY) ? REVIEW_CURRENCY : <?php echo json_encode((string)$currency_symbol); ?>;

        function soMoney(v) {
            return SO_CUR + ' ' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function soToast(msg, type) {
            if (window.Alert && typeof Alert.show === 'function') Alert.show(msg, type || 'success');
        }

        /* POST an action to this page (or another URL) as AJAX and return the JSON answer. */
        async function soPost(fields, url) {
            var fd = new FormData();
            fd.append('csrf_token', SO_CSRF);
            Object.keys(fields).forEach(function(k) { fd.append(k, fields[k]); });
            var r = await fetch(url || 'stock-orders.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
            var j = await r.json().catch(function() { return { ok: false, error: 'Unexpected response (' + r.status + ').' }; });
            return j;
        }

        /* Refresh the live region (cards, lists, table) in place - no reload, no redirect. */
        async function soRefresh() {
            try {
                var r = await fetch(window.location.href, { credentials: 'same-origin' });
                var html = await r.text();
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.getElementById('soLive');
                var live = document.getElementById('soLive');
                if (fresh && live) live.innerHTML = fresh.innerHTML;
            } catch (e) {
                window.location.reload();
            }
            soSyncBulk();
        }

        // ── Row "More" menu (shared placement from admin-components.js) ─────
        function closeSoActionMenus() {
            document.querySelectorAll('#soOrdersTable .actions-more.open').forEach(function (el) {
                el.classList.remove('open');
                var m = el.querySelector('.actions-more-menu');
                if (m) m.removeAttribute('style');
            });
        }

        function toggleSoActionsMore(btn, e) {
            if (e) e.stopPropagation();
            var wrap = btn.closest('.actions-more');
            var menu = wrap ? wrap.querySelector('.actions-more-menu') : null;
            if (!menu) return;
            var wasOpen = wrap.classList.contains('open');
            closeSoActionMenus();
            if (wasOpen) return;
            wrap.classList.add('open');
            if (window.rhPlaceActionMenu) window.rhPlaceActionMenu(btn, menu);
        }
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.actions-more')) closeSoActionMenus();
        });
        document.addEventListener('scroll', function (e) {
            if (!(e.target && e.target.closest && e.target.closest('.actions-more-menu'))) closeSoActionMenus();
        }, true);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSoActionMenus();
        });

        // ── Row actions (cancel / void / reconcile) ─────────────────────────
        var _soVoidIds = [];

        async function soRowAction(kind, id, ref) {
            if (kind === 'cancel') {
                var cReason = await soAskCancelReason(1, ref);
                if (cReason === null) return;
                var j = await soPost({ action: 'cancel_order', order_id: id, cancel_reason: cReason });
                soToast(j.ok ? j.message : j.error, j.ok ? 'success' : 'error');
                if (j.ok) soRefresh();
            } else if (kind === 'void') {
                promptVoid([id], ref);
            } else if (kind === 'delete') {
                soDeleteOrders([id], ref);
            }
        }

        /* Cancel needs a reason (>= 8 characters), like the API: one reason for the whole selection. */
        async function soAskCancelReason(count, ref) {
            var label = count > 1 ? count + ' orders' : ref;
            var details = [
                'Only an open, unpaid order whose preparation has not started can be cancelled. Use Void otherwise.',
                'Its stock is put back, its ready alerts and station notes are cleared, and the reason is saved on the order.'
            ];
            while (true) {
                var reason = await (window.AdminConfirm && AdminConfirm.prompt
                    ? AdminConfirm.prompt({ title: 'Cancel ' + label, message: 'Cancel ' + label + ' and restore its stock?', details: details, inputLabel: 'Reason (min 8 characters)', inputPlaceholder: 'Why is this order being cancelled?', confirmText: 'Cancel order' + (count > 1 ? 's' : ''), tone: 'danger', icon: 'fa-circle-xmark' })
                    : Promise.resolve(window.prompt('Reason for cancelling ' + label + ' (min 8 characters)')));
                if (reason === null) return null;
                reason = String(reason).trim();
                if (reason.length >= 8) return reason;
                soToast('Please give a reason of at least 8 characters.', 'warning');
            }
        }

        /* Delete = permanent. One reason (>= 8 characters) for the whole selection; the server
           (api/delete-order.php) re-checks the pos_delete_order permission and the CSRF token and
           does stock / money / audit in one transaction per order. */
        async function soAskDeleteReason(count, ref) {
            var label = count > 1 ? count + ' orders' : ref;
            var details = [
                'Stock the order still holds is put back; stock already written off as wastage stays wasted.',
                'Its payments, refunds and (room service) folio charges are removed, so shift totals, End of Day and reports change - including closed shifts.',
                'A permanent deletion record is kept. This cannot be undone.',
                'Cash already taken or handed back is not moved - settle the drawer separately.'
            ];
            while (true) {
                var reason = await (window.AdminConfirm && AdminConfirm.prompt
                    ? AdminConfirm.prompt({ title: 'Delete ' + label, message: 'Permanently delete ' + label + '?', details: details, inputLabel: 'Reason (min 8 characters)', inputPlaceholder: 'Why is this order being deleted?', confirmText: 'Delete order' + (count > 1 ? 's' : ''), tone: 'danger', icon: 'fa-trash-can' })
                    : Promise.resolve(window.prompt('Reason for deleting ' + label + ' (min 8 characters)')));
                if (reason === null) return null;
                reason = String(reason).trim();
                if (reason.length >= 8) return reason;
                soToast('Please give a reason of at least 8 characters.', 'warning');
            }
        }

        async function soDeleteOrders(ids, ref) {
            ids = (ids || []).filter(function(v) { return v; });
            if (!ids.length) return;
            var reason = await soAskDeleteReason(ids.length, ref);
            if (reason === null) return;
            var res = await soRunEach(ids, function(id) { return soPost({ order_id: id, delete_reason: reason }, '../api/delete-order.php'); });
            if (ids.length === 1 && res.done === 1) {
                soToast('Order ' + ref + ' deleted.', 'success');
                soRefresh();
            } else {
                soReportBulk('deleted', res);
            }
        }

        function promptVoid(ids, label) {
            _soVoidIds = Array.isArray(ids) ? ids : [];
            document.getElementById('soVoidReason').value = '';
            document.getElementById('soVoidErr').style.display = 'none';
            var t = document.getElementById('soVoidTitle');
            if (t) t.innerHTML = '<i class="fas fa-ban"></i> Void ' + (_soVoidIds.length > 1 ? _soVoidIds.length + ' orders' : String(label || 'order').replace(/[<>&"]/g, ''));
            var modal = document.getElementById('soVoidModal');
            if (modal) {
                modal.classList.add('active');
                document.body.classList.add('modal-open');
            }
        }

        function soCloseVoid() {
            var modal = document.getElementById('soVoidModal');
            if (modal) modal.classList.remove('active');
            if (!document.querySelector('.modal-overlay.active')) document.body.classList.remove('modal-open');
        }

        async function soDoVoid() {
            var reason = document.getElementById('soVoidReason').value.trim();
            var errEl = document.getElementById('soVoidErr');
            if (reason.length < 8) {
                errEl.textContent = 'Reason must be at least 8 characters.';
                errEl.style.display = '';
                return;
            }
            var ids = _soVoidIds.slice();
            if (!ids.length) return;
            soCloseVoid();
            var res = await soRunEach(ids, function(id) { return soPost({ action: 'void_order', order_id: id, void_reason: reason }); });
            soReportBulk('voided', res);
        }

        function _doReconcile() {
            if (!_reviewFormId) return;
            var form = document.getElementById(_reviewFormId);
            var idInput = form ? form.querySelector('[name=order_id]') : null;
            if (!idInput) return;
            soPost({ action: 'reconcile_order', order_id: idInput.value }).then(function(j) {
                soToast(j.ok ? j.message : j.error, j.ok ? 'success' : 'error');
                if (typeof closeOrderReview === 'function') closeOrderReview();
                document.querySelectorAll('.modal-overlay.active').forEach(function(m) { if (m.id !== 'soSettleModal') m.classList.remove('active'); });
                if (j.ok) soRefresh();
            });
        }

        // ── Bulk selection ───────────────────────────────────────────────
        function soSelectedRows() {
            return Array.from(document.querySelectorAll('#soOrdersTable input[data-bulk-row]:checked')).map(function(cb) { return cb.closest('tr'); }).filter(Boolean);
        }

        // The shared admin-bulk.js owns the bar (count, hidden state, select-all); this just asks it to re-read.
        function soSyncBulk() {
            if (window.AdminBulk && typeof AdminBulk.refresh === 'function') AdminBulk.refresh();
        }

        function soToggleAll(on) {
            document.querySelectorAll('#soOrdersTable input[data-bulk-row]').forEach(function(cb) { cb.checked = !!on; });
            soSyncBulk();
        }

        function soBulkClear() { soToggleAll(false); }

        // Run the page's own bulk flows instead of posting the shared bar form. Capture phase on the document,
        // registered before the shared handlers, so nothing else sees (or submits) this form.
        document.addEventListener('submit', function(event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-bulk-bar') || form.getAttribute('data-bulk-scope') !== '#soOrdersTable') return;
            event.preventDefault();
            event.stopImmediatePropagation();
            var op = event.submitter && event.submitter.value ? event.submitter.value : '';
            if (op) soBulk(op);
        }, true);

        async function soRunEach(ids, fn) {
            var done = 0, failed = [];
            for (var i = 0; i < ids.length; i++) {
                try {
                    var j = await fn(ids[i]);
                    if (j && j.ok) done++; else failed.push((j && j.error) || 'failed');
                } catch (e) {
                    failed.push('network error');
                }
            }
            return { done: done, failed: failed };
        }

        function soReportBulk(verb, res) {
            var msg = res.done + ' ' + verb + (res.failed.length ? ', ' + res.failed.length + ' skipped: ' + res.failed.slice(0, 3).join(' · ') : '.');
            soToast(msg, res.failed.length && !res.done ? 'error' : (res.failed.length ? 'warning' : 'success'));
            soRefresh();
        }

        async function soBulk(kind) {
            var rows = soSelectedRows();
            if (!rows.length) return;
            if (kind === 'export') {
                var head = ['Reference', 'Type', 'Customer', 'Status', 'Payment', 'Total', 'Created'];
                var lines = [head.join(',')].concat(rows.map(function(tr) {
                    return [tr.dataset.soRef, tr.dataset.soType, tr.dataset.soCustomer, tr.dataset.soStatus, tr.dataset.soMethod, tr.dataset.soTotal, tr.dataset.soCreated]
                        .map(function(v) { return '"' + String(v || '').replace(/"/g, '""') + '"'; }).join(',');
                }));
                var blob = new Blob([lines.join('\r\n')], { type: 'text/csv' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'orders-selected-' + new Date().toISOString().slice(0, 10) + '.csv';
                document.body.appendChild(a);
                a.click();
                setTimeout(function() { URL.revokeObjectURL(a.href); a.remove(); }, 500);
                return;
            }
            var pick = function(statuses) { return rows.filter(function(tr) { return statuses.indexOf(tr.dataset.soStatus) !== -1; }); };
            if (kind === 'cancel') {
                var c = pick(['placed']);
                if (!c.length) { soToast('None of the selected orders is open - only open, unpaid orders can be cancelled.', 'warning'); return; }
                var bReason = await soAskCancelReason(c.length, '');
                if (bReason === null) return;
                soReportBulk('cancelled', await soRunEach(c.map(function(tr) { return tr.dataset.soId; }), function(id) { return soPost({ action: 'cancel_order', order_id: id, cancel_reason: bReason }); }));
            } else if (kind === 'void') {
                var v = pick(['placed', 'paid']);
                if (!v.length) { soToast('Only open or paid orders can be voided.', 'warning'); return; }
                promptVoid(v.map(function(tr) { return tr.dataset.soId; }), '');
            } else if (kind === 'delete') {
                soDeleteOrders(rows.map(function(tr) { return tr.dataset.soId; }), rows.length === 1 ? rows[0].dataset.soRef : '');
            } else if (kind === 'reconcile') {
                var rc = rows.filter(function(tr) { return tr.dataset.soReview === '1'; });
                if (!rc.length) { soToast('None of the selected orders needs reconciling.', 'warning'); return; }
                soReportBulk('reconciled', await soRunEach(rc.map(function(tr) { return tr.dataset.soId; }), function(id) { return soPost({ action: 'reconcile_order', order_id: id }); }));
            }
        }

        // ── Settle (till payment path) ─────────────────────────────────────
        var _soSettle = null;

        async function soOpenSettle(id, ref, total, splitCount, splitPaid) {
            _soSettle = { id: id, ref: ref, total: parseFloat(total) || 0, splitCount: parseInt(splitCount, 10) || 1, splitPaid: parseInt(splitPaid, 10) || 0, deal: { amount: 0, ids: [], reason: '' } };
            document.getElementById('soSettleRef').textContent = ref;
            ['soSettleMethod', 'soSettleTendered', 'soSettleMobileProvider', 'soSettleMobileRef', 'soSettleCardLast4', 'soSettleCardAuth', 'soSettleTip', 'soSettleDisc', 'soSettleDiscReason'].forEach(function(fid) {
                var el = document.getElementById(fid);
                if (el) el.value = '';
            });
            var force = document.getElementById('soSettleForce');
            if (force) force.checked = false;
            var split = document.getElementById('soSettleSplit');
            split.value = String(Math.max(1, _soSettle.splitCount));
            split.disabled = _soSettle.splitPaid > 0; // a bill already being split keeps its ways
            var disc = document.getElementById('soSettleDisc');
            if (disc) disc.disabled = _soSettle.splitPaid > 0;
            document.getElementById('soSettleErr').hidden = true;
            soUpdateSettleFields();
            soRecalcSettle();
            var modal = document.getElementById('soSettleModal');
            modal.classList.add('active');
            document.body.classList.add('modal-open');
            // Deals the till would apply right now (only before the first split leg).
            if (_soSettle.splitPaid === 0) {
                try {
                    var r = await fetch('pos.php?ajax=tab_deals&order_id=' + encodeURIComponent(id), { credentials: 'same-origin' });
                    var d = await r.json();
                    if (_soSettle && _soSettle.id === id && d && d.ok && d.amount > 0) {
                        _soSettle.deal = { amount: parseFloat(d.amount) || 0, ids: d.ids || [], reason: d.reason || '' };
                        soRecalcSettle();
                    }
                } catch (e) { /* no deals */ }
            }
        }

        function soCloseSettle() {
            _soSettle = null;
            var modal = document.getElementById('soSettleModal');
            if (modal) modal.classList.remove('active');
            if (!document.querySelector('.modal-overlay.active')) document.body.classList.remove('modal-open');
        }

        function soUpdateSettleFields() {
            var method = document.getElementById('soSettleMethod').value;
            document.getElementById('soSettleCashFields').hidden = method !== 'cash';
            document.getElementById('soSettleMobileFields').hidden = method !== 'mobile_money';
            document.getElementById('soSettleCardFields').hidden = method !== 'card_manual';
            soCalcChange();
        }

        /* Amount for this payment: (total - deals - discount) split N ways, plus tip. The server
           recomputes everything; this is the cashier's guide and the cash-tendered check. */
        function soSettleAmounts() {
            if (!_soSettle) return { due: 0 };
            var s = _soSettle;
            var discEl = document.getElementById('soSettleDisc');
            var disc = discEl && !discEl.disabled ? Math.max(0, parseFloat(discEl.value) || 0) : 0;
            var net = s.splitPaid > 0 ? s.total : Math.max(0.01, s.total - s.deal.amount - disc);
            var ways = Math.max(1, parseInt(document.getElementById('soSettleSplit').value, 10) || 1);
            var leg = s.splitPaid + 1;
            var per = Math.floor((net / ways) * 100) / 100;
            var thisLeg = leg >= ways ? Math.round((net - per * (ways - 1)) * 100) / 100 : per;
            var tip = Math.max(0, parseFloat(document.getElementById('soSettleTip').value) || 0);
            return { disc: disc, net: net, ways: ways, leg: leg, thisLeg: thisLeg, tip: tip, due: Math.round((thisLeg + tip) * 100) / 100 };
        }

        function soRecalcSettle() {
            if (!_soSettle) return;
            var a = soSettleAmounts();
            document.getElementById('soSumTotal').textContent = soMoney(_soSettle.total);
            var dealRow = document.getElementById('soSumDealRow');
            dealRow.hidden = !(_soSettle.deal.amount > 0 && _soSettle.splitPaid === 0);
            document.getElementById('soSumDeal').textContent = '- ' + soMoney(_soSettle.deal.amount);
            document.getElementById('soSumDealLabel').textContent = 'Deals' + (_soSettle.deal.reason ? ' (' + _soSettle.deal.reason + ')' : '');
            var discRow = document.getElementById('soSumDiscRow');
            discRow.hidden = !(a.disc > 0);
            document.getElementById('soSumDisc').textContent = '- ' + soMoney(a.disc);
            var reasonWrap = document.getElementById('soSettleDiscReasonWrap');
            if (reasonWrap) reasonWrap.hidden = !(a.disc > 0);
            var splitRow = document.getElementById('soSumSplitRow');
            splitRow.hidden = a.ways <= 1;
            document.getElementById('soSumSplitLabel').textContent = 'Split ' + a.ways + ' ways - payment ' + a.leg + ' of ' + a.ways;
            document.getElementById('soSumSplit').textContent = soMoney(a.thisLeg);
            document.getElementById('soSumDue').textContent = soMoney(a.due);
            soCalcChange();
        }

        function soCalcChange() {
            var cd = document.getElementById('soSettleChangeDisplay');
            if (!cd || !_soSettle) return;
            var tendered = parseFloat(document.getElementById('soSettleTendered').value) || 0;
            if (tendered <= 0) { cd.textContent = ''; return; }
            var change = tendered - soSettleAmounts().due;
            cd.style.color = change < -0.004 ? '#c82333' : '#155724';
            cd.textContent = change < -0.004 ? 'Short by ' + soMoney(Math.abs(change)) : 'Change due: ' + soMoney(change);
        }

        async function soDoSettle() {
            if (!_soSettle) return;
            var errEl = document.getElementById('soSettleErr');
            var fail = function(m) { errEl.textContent = m; errEl.hidden = false; };
            errEl.hidden = true;
            var method = document.getElementById('soSettleMethod').value;
            if (!method) return fail('Select a payment method.');
            var a = soSettleAmounts();
            var f = {
                action: 'pay_existing',
                order_id: _soSettle.id,
                payment_method: method,
                split_count: a.ways,
                split_number: a.leg,
                tip_amount: a.tip.toFixed(2)
            };
            if (method === 'cash') {
                var tendered = parseFloat(document.getElementById('soSettleTendered').value) || 0;
                if (tendered + 0.004 < a.due) return fail('Tendered amount is less than the amount to collect (' + soMoney(a.due) + ').');
                f.tendered_amount = tendered.toFixed(2);
            } else if (method === 'mobile_money') {
                f.mobile_wallet_provider = document.getElementById('soSettleMobileProvider').value.trim();
                f.mobile_wallet_reference = document.getElementById('soSettleMobileRef').value.trim();
                if (!f.mobile_wallet_provider || !f.mobile_wallet_reference) return fail('Enter both the provider and the transaction reference.');
            } else if (method === 'card_manual') {
                f.card_last4 = document.getElementById('soSettleCardLast4').value.trim();
                f.card_auth_code = document.getElementById('soSettleCardAuth').value.trim();
                if (!/^\d{4}$/.test(f.card_last4) || !f.card_auth_code) return fail('Enter exactly 4 card digits and the auth code.');
            }
            if (_soSettle.splitPaid === 0 && a.leg === 1) {
                if (_soSettle.deal.amount > 0) {
                    f.deal_ids = _soSettle.deal.ids.join(',');
                    f.deal_discount_amount = _soSettle.deal.amount.toFixed(2);
                }
                if (a.disc > 0) {
                    var reasonEl = document.getElementById('soSettleDiscReason');
                    f.discount_amount = a.disc.toFixed(2);
                    f.discount_reason = reasonEl ? reasonEl.value.trim() : '';
                    if (!f.discount_reason) return fail('Give a reason for the discount.');
                }
            }
            var force = document.getElementById('soSettleForce');
            if (force && force.checked) f.force_serve_kitchen = '1';

            var btn = document.getElementById('soSettleBtn');
            btn.disabled = true;
            try {
                var j = await soPost(f, 'pos.php');
                if (!j.ok) return fail(j.error || 'Payment was not taken.');
                if (j.split_intermediate) {
                    _soSettle.splitPaid = parseInt(j.split_paid, 10) || (_soSettle.splitPaid + 1);
                    _soSettle.splitCount = parseInt(j.split_total, 10) || a.ways;
                    document.getElementById('soSettleSplit').disabled = true;
                    var d2 = document.getElementById('soSettleDisc');
                    if (d2) { d2.value = ''; d2.disabled = true; }
                    ['soSettleTendered', 'soSettleMobileRef', 'soSettleCardLast4', 'soSettleCardAuth', 'soSettleTip'].forEach(function(fid) { var el = document.getElementById(fid); if (el) el.value = ''; });
                    // The order total now carries the deals/discount applied on the first payment.
                    _soSettle.total = a.net;
                    _soSettle.deal = { amount: 0, ids: [], reason: '' };
                    soToast(j.message || 'Payment recorded - next person.', 'success');
                    soRefresh();
                    soRecalcSettle();
                } else {
                    soCloseSettle();
                    soToast(j.message || 'Order settled.', 'success');
                    soRefresh();
                }
            } catch (e) {
                fail('Network error - check the order before trying again.');
            } finally {
                btn.disabled = false;
            }
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
                                (item.notes ? '<div style="font-size:11px;color:#7E684B;font-weight:400;">' + item.notes + '</div>' : '') + '</td>' +
                                '<td style="padding:8px 6px;text-align:center;"><span style="background:#f3ece4;color:#7E684B;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:600;">' + (item.menu_type || '') + '</span></td>' +
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
                        Modal.showMessage({ title: '<i class="fas fa-list-ul" style="color:#7E684B;"></i> Items — ' + ref, message: html });
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

