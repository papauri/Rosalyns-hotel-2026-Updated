<?php

/**
 * POS Till — Modern Touchscreen Restaurant Till
 *
 * Designed for tablets / dedicated POS terminals. Full-screen layout,
 * big touch targets, category tabs, search, payment modal, receipt
 * print/email handoff, real-time cart total.
 *
 * Reuses ALL the anti-cheat machinery in admin/stock-orders.php:
 *   - server-side price lookup
 *   - atomic place-and-pay
 *   - audit log + activity log
 *   - method-specific validation (cash tendered ≥ total, mobile ref, card last4+auth)
 *   - card_pos disabled (provision)
 *
 * Permission: pos_till (granted to admin, manager, restaurant_staff).
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once __DIR__ . '/../includes/finance-sequences.php';
require_once __DIR__ . '/../includes/station-hours.php';
require_once __DIR__ . '/../includes/restaurant-location-locks.php';
require_once __DIR__ . '/includes/restaurant-payment-sync.php';
require_once __DIR__ . '/includes/restaurant-order-serve.php';

$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];
$currency_symbol = getSetting('currency_symbol');
$siteName = getSetting('site_name') ?: 'Hotel';
$restaurantWindow = rh_station_union_business_window();
$kitchenWindow    = rh_station_business_window('kitchen');
$barWindow        = rh_station_business_window('bar');

if (!ensureStockTablesExist()) {
    http_response_code(500);
    exit('Stock tables missing. Run migration 015 first.');
}
finance_ensure_sequence_tables($pdo);

/* ---------------- Inline copies of helpers (kept lean) ---------------- */
function pos_calculateRestaurantVatParts(float $grossAmount): array
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
function pos_mapMethod(string $m): string
{
    return match ($m) {
        'cash'         => 'cash',
        'mobile_money' => 'mobile_money',
        'card_manual'  => 'credit_card',
        'card_pos'     => 'credit_card',
        default        => 'other',
    };
}
function pos_calculateMenuItemRecipeCost(PDO $pdo, int $menuItemId, string $menuType, float $quantity): float
{
    if ($quantity <= 0) return 0.0;
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM((sri.quantity_per_portion / (GREATEST(sri.yield_percent, 0.1) / 100)) * i.cost_per_unit), 0)
        FROM stock_recipes sr
        INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
        INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id
        WHERE sr.menu_item_id = ? AND sr.menu_type = ?
    ");
    $stmt->execute([$menuItemId, $menuType]);
    return round(((float)$stmt->fetchColumn()) * $quantity, 4);
}
function pos_logAudit(PDO $pdo, int $orderId, ?int $actorId, ?string $actorName, string $event, ?string $details = null): void
{
    try {
        $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$orderId, $actorId, $actorName, $event, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        error_log('pos_logAudit: ' . $e->getMessage());
    }
}
function pos_syncPayment(PDO $pdo, array $order, int $recordedBy, string $paymentMethod): void
{
    $orderId = (int)$order['id'];
    $reference = (string)$order['reference'];
    $vat = pos_calculateRestaurantVatParts((float)$order['total_amount']);
    $mappedMethod = pos_mapMethod($paymentMethod);

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

/* ---------------- POST: place / pay / park / close shift ---------------- */
$message = '';
$error = '';
$lastOrderId = 0;
$lastOrderRef = '';
$justParked = false;
$lastOrderCustomerEmail = '';
$lastOrderCustomerPhone = '';
$justClosedShift = null;

function pos_redirectWithFlash(array $flash): void
{
    $_SESSION['pos_flash'] = $flash;
    header('Location: pos.php?pos_saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['pos_flash']) && is_array($_SESSION['pos_flash'])) {
    $posFlash = $_SESSION['pos_flash'];
    unset($_SESSION['pos_flash']);
    $message = (string)($posFlash['message'] ?? '');
    $lastOrderId = (int)($posFlash['last_order_id'] ?? 0);
    $lastOrderRef = (string)($posFlash['last_order_ref'] ?? '');
    $justParked = !empty($posFlash['just_parked']);
    $justClosedShift = is_array($posFlash['just_closed_shift'] ?? null) ? $posFlash['just_closed_shift'] : null;
}

if ($lastOrderId > 0 && !$justParked) {
    try {
        $lastOrderContactStmt = $pdo->prepare("SELECT customer_email, customer_phone FROM stock_orders WHERE id = ? LIMIT 1");
        $lastOrderContactStmt->execute([$lastOrderId]);
        $lastOrderContact = $lastOrderContactStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $lastOrderCustomerEmail = (string)($lastOrderContact['customer_email'] ?? '');
        $lastOrderCustomerPhone = (string)($lastOrderContact['customer_phone'] ?? '');
    } catch (Throwable $ignored) {
        $lastOrderCustomerEmail = '';
        $lastOrderCustomerPhone = '';
    }
}

/**
 * Create a placed (unpaid) order from POSTed cart. Returns [orderId, reference, total].
 */
function pos_buildOrderFromPost(PDO $pdo, array $user, string $orderType, ?string $tableNumber, ?string $customerName, ?string $customerEmail, ?string $customerPhone, ?string $orderNote, bool $openedAsTab): array
{
    $itemIds   = $_POST['item_id']   ?? [];
    $itemTypes = $_POST['item_type'] ?? [];
    $itemQtys  = $_POST['item_qty']  ?? [];
    $itemNotes = $_POST['item_note'] ?? [];
    $count = is_array($itemIds) ? count($itemIds) : 0;
    if ($count === 0) throw new RuntimeException('Cart is empty — tap items to add.');

    $reference = generateStockOrderReference();
    $clientUuid = isset($_POST['client_uuid']) ? mb_substr(trim((string)$_POST['client_uuid']), 0, 64) : '';
    if ($clientUuid !== '') {
        // Use FOR UPDATE so the row-level/gap lock held by the caller's transaction
        // serializes concurrent requests with the same client_uuid.
        // DB-level enforcement requires migration 038_pos_client_uuid_unique.php.
        $existing = $pdo->prepare("SELECT id, reference, total_amount FROM stock_orders WHERE client_uuid=? LIMIT 1 FOR UPDATE");
        $existing->execute([$clientUuid]);
        if ($prior = $existing->fetch(PDO::FETCH_ASSOC)) {
            return [(int)$prior['id'], (string)$prior['reference'], (float)$prior['total_amount'], 0];
        }
    }

    $location = rh_restaurant_resolve_pos_location($pdo, $orderType, $tableNumber);
    if ($orderType === 'room_service' && !empty($location['booking']) && is_array($location['booking'])) {
        $booking = $location['booking'];
        $customerName = $customerName !== '' ? $customerName : (string)($booking['guest_name'] ?? '');
        $customerEmail = $customerEmail !== '' ? $customerEmail : (string)($booking['guest_email'] ?? '');
        $customerPhone = $customerPhone !== '' ? $customerPhone : (string)($booking['guest_phone'] ?? '');
    }

    $pdo->prepare("INSERT INTO stock_orders (reference, client_uuid, order_type, booking_id, individual_room_id, table_number, room_number, customer_name, customer_email, customer_phone, notes, status, total_amount, created_by, opened_as_tab) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'placed', 0, ?, ?)")
        ->execute([
            $reference,
            $clientUuid ?: null,
            $orderType,
            $location['booking_id'],
            $location['individual_room_id'],
            $location['table_number'],
            $location['room_number'],
            $customerName ?: null,
            $customerEmail ?: null,
            $customerPhone ?: null,
            $orderNote ?: null,
            $user['id'],
            $openedAsTab ? 1 : 0
        ]);
    $orderId = (int)$pdo->lastInsertId();

    // Insert cart lines via the shared helper (server-side price lookup, station
    // routing, recipe cost). Stock deduction stays deferred to the KDS for
    // non-room-service; room-service folio posting happens just below.
    [$totalAmount, $totalCost, $count, $folioItems] = pos_appendCartItemsToOrder($pdo, $orderId, $orderType);
    if ($totalAmount <= 0) throw new RuntimeException('Order total must be greater than zero.');

    $pdo->prepare("UPDATE stock_orders SET total_amount=?, subtotal=?, total_cost=? WHERE id=?")
        ->execute([$totalAmount, $totalAmount, $totalCost, $orderId]);

    // ── Room-service: post all items to the booking folio immediately ──────
    // addBookingChargeFromMenu deducts stock; setting stock_deducted=1 on the
    // stock_order_items row prevents KDS ready_item from double-deducting.
    if ($orderType === 'room_service' && !empty($location['booking_id'])) {
        $rs_booking_id = (int)$location['booking_id'];
        foreach ($folioItems as $fi) {
            $charge = addBookingChargeFromMenu($rs_booking_id, $fi['type'], $fi['item_id'], $fi['qty'], (int)$user['id']);
            if (!empty($charge['success']) && !empty($charge['charge_id'])) {
                $pdo->prepare("UPDATE booking_charges SET stock_order_id = ? WHERE id = ?")
                    ->execute([$orderId, (int)$charge['charge_id']]);
                $pdo->prepare("UPDATE stock_order_items SET stock_deducted = 1 WHERE id = ?")
                    ->execute([$fi['soi_id']]);
            }
        }
        $pdo->prepare("UPDATE stock_orders SET folio_posted_at = NOW() WHERE id = ?")->execute([$orderId]);
        recalculateBookingFinancials($rs_booking_id);
    }

    // Stamp offline timestamps + log a replay event if this came from the offline queue
    if (function_exists('rh_stamp_order_offline')) {
        rh_stamp_order_offline($pdo, $orderId);
    }
    if (function_exists('rh_log_offline_replay')) {
        rh_log_offline_replay($pdo, 'pos.php?action=' . ($_POST['action'] ?? 'place_order'), [
            'action' => $_POST['action'] ?? 'place_order',
            'entity_type' => 'stock_order',
            'entity_id' => $orderId,
            'entity_reference' => $reference,
            'response_status' => 200,
            'response_summary' => "Order created · " . number_format($totalAmount, 2) . " · {$count} item(s)",
            'details' => ['order_type' => $orderType, 'location' => $location['label'], 'items' => $count],
        ]);
    }

    return [$orderId, $reference, $totalAmount, $count];
}

/**
 * Fire an order to all relevant station displays (Kitchen / Bar / Coffee Bar).
 * Sets order kitchen_status='new' and fired_at=NOW() if not already, defaults all
 * items to kds_status='pending'. Each station's display filters items by station.
 * Idempotent: re-firing only stamps missing timestamps.
 */
function pos_fireKitchen(PDO $pdo, int $orderId, int $userId, string $userName): void
{
    // Are there ANY items that still need prep?
    $st = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id=? AND kds_status='pending'");
    $st->execute([$orderId]);
    $pending = (int)$st->fetchColumn();
    if ($pending <= 0) {
        // Nothing to fire — mark served so the order is closed-out.
        $pdo->prepare("UPDATE stock_orders SET kitchen_status='served' WHERE id=? AND kitchen_status='none'")->execute([$orderId]);
        return;
    }
    $pdo->prepare("UPDATE stock_orders SET kitchen_status='new', fired_at=COALESCE(fired_at,NOW()), kitchen_printed_at=COALESCE(kitchen_printed_at,NOW()) WHERE id=? AND kitchen_status IN ('none','new')")->execute([$orderId]);
    // Audit per station so each board can see who fired what
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $pdo->prepare("INSERT INTO stock_kds_events (order_id, event, to_status, user_id, user_name, ip_address) VALUES (?, 'fired', 'new', ?, ?, ?)")
        ->execute([$orderId, $userId, $userName, $ip]);
}

/**
 * Insert POSTed cart items into an existing order row. Reuses the same server-side
 * price lookup / station routing / recipe-cost logic as the place flow so behaviour
 * is identical whether opening a fresh order or appending to an open tab.
 *
 * Does NOT update the order totals — the caller decides whether to set or accumulate.
 * Returns [addedAmount, addedCost, addedCount, folioItems].
 */
function pos_appendCartItemsToOrder(PDO $pdo, int $orderId, string $orderType): array
{
    $itemIds   = $_POST['item_id']   ?? [];
    $itemTypes = $_POST['item_type'] ?? [];
    $itemQtys  = $_POST['item_qty']  ?? [];
    $itemNotes = $_POST['item_note'] ?? [];
    $count = is_array($itemIds) ? count($itemIds) : 0;
    if ($count === 0) throw new RuntimeException('Cart is empty — tap items to add.');

    $itemIns = $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, notes, station) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $addedAmount = 0.0;
    $addedCost   = 0.0;
    $added       = 0;
    $folioItems  = [];
    for ($k = 0; $k < $count; $k++) {
        $itemId = (int)($itemIds[$k] ?? 0);
        $qty = (float)($itemQtys[$k] ?? 0);
        $lineNote = isset($itemNotes[$k]) ? mb_substr(trim((string)$itemNotes[$k]), 0, 250) : '';
        if ($itemId <= 0 || $qty <= 0) continue;
        if ($qty > 1000) throw new RuntimeException('Quantity cap: 1000.');
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
        $lineCost = pos_calculateMenuItemRecipeCost($pdo, $itemId, $menuType, $qty);
        $station = in_array($row['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true)
            ? $row['station']
            : 'kitchen';
        $itemIns->execute([$orderId, $itemId, $menuType, $row['name'], $qty, (float)$row['price'], $line, $lineNote ?: null, $station]);
        $soiId = (int)$pdo->lastInsertId();
        $addedAmount += $line;
        $addedCost += $lineCost;
        $added++;
        if ($orderType === 'room_service') {
            $folioItems[] = ['item_id' => $itemId, 'type' => $menuType, 'qty' => $qty, 'soi_id' => $soiId];
        }
    }
    if ($added === 0) throw new RuntimeException('No valid items to add.');
    return [round($addedAmount, 2), round($addedCost, 4), $added, $folioItems];
}

/**
 * Auto-serve bar / coffee-bar items on a tab at settlement time. Thin wrapper kept so the
 * existing call sites read unchanged; the logic now lives in admin/includes/restaurant-order-serve.php
 * so admin/restaurant-tables.php settles drinks identically instead of skipping this entirely.
 */
function pos_autoServeBarItems(PDO $pdo, int $orderId, array $user): int
{
    return rh_auto_serve_bar_items($pdo, $orderId, $user);
}

/**
 * Manager-authorised force-serve for stranded kitchen (food) items — the last-resort
 * unblock for a tab whose food was never bumped through the KDS (e.g. fired before a
 * business window boundary, or simply missed). Never called automatically: the caller
 * must already hold pos_force_serve or have obtained a manager auth token for it.
 * Deducts stock for any undeducted line exactly as a normal KDS bump would, then marks
 * the lines served and logs who authorised it. Returns the number of lines force-served.
 */
function pos_forceServeKitchenItems(PDO $pdo, int $orderId, array $actor, ?int $authorisedById, ?string $authorisedByName): int
{
    $sel = $pdo->prepare("SELECT id, menu_item_id, menu_type, quantity, stock_deducted FROM stock_order_items WHERE order_id = ? AND station = 'kitchen' AND kds_status NOT IN ('served','void')");
    $sel->execute([$orderId]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;

    /* Same pre-check the KDS bump does. Without it, force-serving a tab whose ingredients are
     * exhausted drove stock negative, and the resulting "went negative" warning called
     * rh_log_event() — which used to run CREATE TABLE IF NOT EXISTS and implicitly COMMIT this
     * transaction mid-settlement, so the payment persisted while the caller reported failure.
     * An override for un-bumped tickets must not double as an override for absent stock. */
    $undeducted = array_values(array_filter($rows, static fn(array $r): bool => (int)$r['stock_deducted'] === 0));
    if ($undeducted) {
        $shortages = rh_stock_shortages_for_items($pdo, $undeducted);
        if ($shortages) {
            throw new RuntimeException(rh_stock_shortage_message(
                $shortages,
                'Receive stock or adjust the recipe before force-serving this tab.'
            ));
        }
    }

    foreach ($rows as $r) {
        if ((int)$r['stock_deducted'] === 0) {
            $ok = deductStockForMenuItem((int)$r['menu_item_id'], (string)$r['menu_type'], (float)$r['quantity'], 'pos_order', (int)$r['id'], (int)$actor['id']);
            if ($ok) {
                $pdo->prepare("UPDATE stock_order_items SET stock_deducted = 1 WHERE id = ?")->execute([(int)$r['id']]);
            } else {
                error_log("pos_forceServeKitchenItems: stock deduction failed for item #{$r['id']} on order #{$orderId}");
            }
        }
    }

    $pdo->prepare("UPDATE stock_order_items SET kds_status='served', started_at=COALESCE(started_at,NOW()), ready_at=COALESCE(ready_at,NOW()), served_at=NOW(), bumped_by=? WHERE order_id = ? AND station = 'kitchen' AND kds_status NOT IN ('served','void')")
        ->execute([(int)$actor['id'], $orderId]);

    $remain = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id = ? AND kds_status NOT IN ('served','void')");
    $remain->execute([$orderId]);
    if ((int)$remain->fetchColumn() === 0) {
        $pdo->prepare("UPDATE stock_orders SET kitchen_status='served', served_at=COALESCE(served_at,NOW()) WHERE id = ?")->execute([$orderId]);
    }

    $auditDetails = ['count' => count($rows), 'reason' => 'manager force-serve at till settlement'];
    if ($authorisedById) {
        $auditDetails['authorised_by_id'] = $authorisedById;
        $auditDetails['authorised_by_name'] = $authorisedByName;
    }
    pos_logAudit($pdo, $orderId, $actor['id'], $actor['full_name'], 'kitchen_items_force_served', json_encode($auditDetails));
    return count($rows);
}

/**
 * Apply payment to an existing placed order. Validates method-specific extras.
 */
function pos_applyPaymentToOrder(PDO $pdo, array $user, int $orderId, string $reference, float $totalAmount, string $paymentMethod, array $post, int $splitCount = 1, int $splitNumber = 1): array
{
    $tipAmount       = max(0.0, round((float)($post['tip_amount'] ?? 0), 2));
    $tendered        = (float)($post['tendered_amount'] ?? 0);
    $mobileProvider  = trim($post['mobile_wallet_provider'] ?? '');
    $mobileReference = trim($post['mobile_wallet_reference'] ?? '');
    $cardLast4Raw    = preg_replace('/\D/', '', (string)($post['card_last4'] ?? ''));
    $cardLast4       = strlen($cardLast4Raw) >= 4 ? substr($cardLast4Raw, -4) : null;
    $cardAuthCode    = trim($post['card_auth_code'] ?? '');

    // Each split person pays their equal share of the menu total plus their own tip
    $splitAmount = $splitCount > 1 ? round($totalAmount / $splitCount, 2) : $totalAmount;
    $amountDue   = round($splitAmount + $tipAmount, 2);

    $extras = [
        'tendered' => null, 'change' => null, 'mp' => null, 'mr' => null, 'l4' => null, 'auth' => null,
        'tip' => $tipAmount, 'split_amount' => $splitAmount, 'amount_due' => $amountDue,
    ];

    if ($paymentMethod === 'cash') {
        if ($tendered < $amountDue - BALANCE_TOLERANCE) throw new RuntimeException('Tendered ' . number_format($tendered, 2) . ' < amount due ' . number_format($amountDue, 2));
        $extras['tendered'] = round($tendered, 2);
        $extras['change']   = round($tendered - $amountDue, 2);
    } elseif ($paymentMethod === 'mobile_money') {
        if ($mobileProvider === '' || $mobileReference === '') throw new RuntimeException('Mobile money requires provider + transaction reference.');
        $extras['mp'] = mb_substr($mobileProvider, 0, 50);
        $extras['mr'] = mb_substr($mobileReference, 0, 100);
    } elseif ($paymentMethod === 'card_manual') {
        if (!$cardLast4 || $cardAuthCode === '') throw new RuntimeException('Card requires last 4 digits + authorisation code.');
        $extras['l4']   = $cardLast4;
        $extras['auth'] = mb_substr($cardAuthCode, 0, 50);
    }

    if ($splitCount > 1) {
        // Record this split leg
        $pdo->prepare("INSERT INTO stock_order_splits (order_id, split_number, split_amount, tip_amount, payment_method, tendered_amount, change_due, mobile_wallet_provider, mobile_wallet_reference, card_last4, card_auth_code, paid_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$orderId, $splitNumber, $splitAmount, $tipAmount, $paymentMethod, $extras['tendered'], $extras['change'], $extras['mp'], $extras['mr'], $extras['l4'], $extras['auth'], $user['id']]);

        $pdo->prepare("UPDATE stock_orders SET split_paid_count = split_paid_count + 1, tip_amount = tip_amount + ? WHERE id = ?")
            ->execute([$tipAmount, $orderId]);

        if ($splitNumber >= $splitCount) {
            // Last split: close the order; use last leg's method for the ledger
            $pdo->prepare("UPDATE stock_orders SET status='paid', paid_at=NOW(), payment_method=? WHERE id=?")
                ->execute([$paymentMethod, $orderId]);
            // Tips are cash movement, not revenue: the ledger books totalAmount only.
            // pos-accounting.php reconciles tips separately via stock_order_splits/tip_amount.
            $cnStmt = $pdo->prepare("SELECT customer_name FROM stock_orders WHERE id = ?");
            $cnStmt->execute([$orderId]);
            pos_syncPayment($pdo, ['id' => $orderId, 'reference' => $reference, 'total_amount' => $totalAmount, 'customer_name' => (string)($cnStmt->fetchColumn() ?: ''), 'status' => 'paid'], $user['id'], $paymentMethod);
        }
    } else {
        // Single payment — store tip on the order row along with payment details
        $pdo->prepare("UPDATE stock_orders SET status='paid', paid_at=NOW(), payment_method=?, tendered_amount=?, change_due=?, mobile_wallet_provider=?, mobile_wallet_reference=?, card_last4=?, card_auth_code=?, tip_amount=? WHERE id=?")
            ->execute([$paymentMethod, $extras['tendered'], $extras['change'], $extras['mp'], $extras['mr'], $extras['l4'], $extras['auth'], $tipAmount, $orderId]);
        // Tips are cash movement, not revenue: the ledger books totalAmount only.
        $cnStmt = $pdo->prepare("SELECT customer_name FROM stock_orders WHERE id = ?");
        $cnStmt->execute([$orderId]);
        pos_syncPayment($pdo, ['id' => $orderId, 'reference' => $reference, 'total_amount' => $totalAmount, 'customer_name' => (string)($cnStmt->fetchColumn() ?: ''), 'status' => 'paid'], $user['id'], $paymentMethod);
    }

    return $extras;
}

/* Detect optional columns once so the POS degrades gracefully if migration 045
 * (stock_orders.covers) has not yet been applied on this database. */
$posHasCoversCol = false;
try {
    $coversChk = $pdo->query("SHOW COLUMNS FROM stock_orders LIKE 'covers'");
    $posHasCoversCol = $coversChk && $coversChk->fetch(PDO::FETCH_ASSOC) !== false;
} catch (Throwable $coversChkEx) {
    $posHasCoversCol = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? 'pay';
    if (!validateCsrfToken($token)) {
        $error = 'Security token invalid — refresh the page.';
    } else {
        try {
            $allowedMethods = ['cash', 'mobile_money', 'card_manual', 'card_pos'];
            $allowedTypes = ['walk_in', 'dine_in', 'takeaway', 'room_service'];
            $orderType = in_array($_POST['order_type'] ?? '', $allowedTypes, true) ? $_POST['order_type'] : 'walk_in';
            $tableNumber  = trim($_POST['table_number'] ?? '');
            $customerName = trim($_POST['customer_name'] ?? '');
            $customerEmail = trim($_POST['customer_email'] ?? '');
            $customerPhone = trim($_POST['customer_phone'] ?? '');
            if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Customer email looks invalid.');
            }
            $orderNote = trim($_POST['notes'] ?? '');

            if ($action === 'park') {
                /* === Fire order to stations, pay later (open tab) === */
                $pdo->beginTransaction();
                [$orderId, $reference, $totalAmount, $count] = pos_buildOrderFromPost($pdo, $user, $orderType, $tableNumber, $customerName, $customerEmail, $customerPhone, $orderNote, true);
                $coversPark = max(0, min(99, (int)($_POST['covers'] ?? 0)));
                if ($posHasCoversCol && $coversPark > 0) {
                    $pdo->prepare("UPDATE stock_orders SET covers=? WHERE id=?")->execute([$coversPark, $orderId]);
                }
                $pdo->prepare("UPDATE stock_orders SET kitchen_printed_at=NOW() WHERE id=?")->execute([$orderId]);
                pos_fireKitchen($pdo, $orderId, $user['id'], $user['full_name']);
                pos_logAudit($pdo, $orderId, $user['id'], $user['full_name'], 'parked_open_tab', json_encode(['lines' => $count, 'total' => $totalAmount, 'table' => $tableNumber, 'till' => 'pos.php']));
                $pdo->commit();
                if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v2');
                $lastOrderId = $orderId;
                $lastOrderRef = $reference;
                $justParked = true;
                // Build human-readable station list from actual items
                $stnStmt = $pdo->prepare("SELECT DISTINCT station FROM stock_order_items WHERE order_id=? ORDER BY station");
                $stnStmt->execute([$orderId]);
                $stnNames = array_map(fn($s) => match ($s) {
                    'kitchen' => 'Kitchen',
                    'bar' => 'Bar',
                    'coffee_bar' => 'Coffee Bar',
                    default => ucfirst($s)
                }, array_column($stnStmt->fetchAll(PDO::FETCH_ASSOC), 'station'));
                $stationLabel = implode(' & ', $stnNames) ?: 'Station';
                $message = "Fired to {$stationLabel}: {$reference} — {$currency_symbol} " . number_format($totalAmount, 2) . " · open tab.";
                // AJAX path: return JSON so the POS can show inline confirmation without a page reload
                $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
                if ($isXhr) {
                    // Fetch line items for the confirmation modal
                    $lineStmt = $pdo->prepare("SELECT item_name, quantity FROM stock_order_items WHERE order_id = ? ORDER BY id");
                    $lineStmt->execute([$orderId]);
                    $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'ok' => true,
                        'order_id' => $orderId,
                        'reference' => $reference,
                        'total' => $totalAmount,
                        'station_label' => $stationLabel,
                        'message' => $message,
                        'lines' => $lines,
                    ]);
                    exit;
                }
                pos_redirectWithFlash([
                    'message' => $message,
                    'last_order_id' => $lastOrderId,
                    'last_order_ref' => $lastOrderRef,
                    'just_parked' => true,
                ]);
            } elseif ($action === 'add_to_tab') {
                /* === Append items (another round) to an existing open tab === */
                $tabOrderId = (int)($_POST['tab_order_id'] ?? 0);
                if ($tabOrderId <= 0) throw new RuntimeException('No tab selected to add to.');

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT id, reference, status, order_type, booking_id,
                                              created_by, total_amount, total_cost,
                                              COALESCE(subtotal, total_amount) AS subtotal,
                                              COALESCE(split_count, 1) AS split_count,
                                              COALESCE(split_paid_count, 0) AS split_paid_count
                                       FROM stock_orders WHERE id = ? FOR UPDATE");
                $stmt->execute([$tabOrderId]);
                $tab = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$tab) throw new RuntimeException('Tab not found.');
                if ($tab['status'] !== 'placed') {
                    throw new RuntimeException('That tab is no longer open (' . str_replace('_', ' ', (string)$tab['status']) . '). Refreshing tabs.');
                }
                if ((int)$tab['split_paid_count'] > 0) {
                    throw new RuntimeException('This tab is mid split-payment — finish settling it before adding more items.');
                }
                if (($user['role'] ?? '') === 'restaurant_staff' && (int)$tab['created_by'] !== (int)$user['id']) {
                    throw new RuntimeException('You can only add to tabs you opened.');
                }

                $tabType = (string)$tab['order_type'];
                [$addedAmount, $addedCost, $addedCount, $folioItems] = pos_appendCartItemsToOrder($pdo, $tabOrderId, $tabType);

                // Accumulate the order financials. total_amount already reflects any
                // earlier discount; we add the new gross to both subtotal and total.
                $newSubtotal = round((float)$tab['subtotal'] + $addedAmount, 2);
                $newTotal    = round((float)$tab['total_amount'] + $addedAmount, 2);
                $newCost     = round((float)$tab['total_cost'] + $addedCost, 4);
                $pdo->prepare("UPDATE stock_orders SET total_amount=?, subtotal=?, total_cost=? WHERE id=?")
                    ->execute([$newTotal, $newSubtotal, $newCost, $tabOrderId]);

                // Room-service tab: post the new lines to the guest folio immediately.
                if ($tabType === 'room_service' && !empty($tab['booking_id'])) {
                    $rs_booking_id = (int)$tab['booking_id'];
                    foreach ($folioItems as $fi) {
                        $charge = addBookingChargeFromMenu($rs_booking_id, $fi['type'], $fi['item_id'], $fi['qty'], (int)$user['id']);
                        if (!empty($charge['success']) && !empty($charge['charge_id'])) {
                            $pdo->prepare("UPDATE booking_charges SET stock_order_id = ? WHERE id = ?")
                                ->execute([$tabOrderId, (int)$charge['charge_id']]);
                            $pdo->prepare("UPDATE stock_order_items SET stock_deducted = 1 WHERE id = ?")
                                ->execute([$fi['soi_id']]);
                        }
                    }
                    $pdo->prepare("UPDATE stock_orders SET folio_posted_at = NOW() WHERE id = ?")->execute([$tabOrderId]);
                    recalculateBookingFinancials($rs_booking_id);
                }

                // Re-activate the kitchen status so the newly added pending lines show as
                // fresh work on the station boards, then fire them.
                $pdo->prepare("UPDATE stock_orders SET kitchen_status='new', kitchen_printed_at=NOW() WHERE id=?")->execute([$tabOrderId]);
                pos_fireKitchen($pdo, $tabOrderId, $user['id'], $user['full_name']);

                pos_logAudit($pdo, $tabOrderId, $user['id'], $user['full_name'], 'items_added_to_tab', json_encode([
                    'added_lines'  => $addedCount,
                    'added_amount' => $addedAmount,
                    'new_total'    => $newTotal,
                    'till'         => 'pos.php',
                ]));
                logActivity($user['id'], 'pos_add_to_tab', 'Added ' . $addedCount . ' item(s) to tab ' . $tab['reference'] . ' (' . $currency_symbol . ' ' . number_format($addedAmount, 2) . '); new total ' . $currency_symbol . ' ' . number_format($newTotal, 2));
                $pdo->commit();
                if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v2');

                // Build station label from the whole order so the user sees where it went
                $stnStmt = $pdo->prepare("SELECT DISTINCT station FROM stock_order_items WHERE order_id=? ORDER BY station");
                $stnStmt->execute([$tabOrderId]);
                $stnNames = array_map(fn($s) => match ($s) {
                    'kitchen' => 'Kitchen',
                    'bar' => 'Bar',
                    'coffee_bar' => 'Coffee Bar',
                    default => ucfirst($s)
                }, array_column($stnStmt->fetchAll(PDO::FETCH_ASSOC), 'station'));
                $stationLabel = implode(' & ', $stnNames) ?: 'Station';
                $message = "Added {$addedCount} item(s) to {$tab['reference']} — new total {$currency_symbol} " . number_format($newTotal, 2) . '.';

                $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
                if ($isXhr) {
                    $lineStmt = $pdo->prepare("SELECT item_name, quantity FROM stock_order_items WHERE order_id = ? ORDER BY id");
                    $lineStmt->execute([$tabOrderId]);
                    $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'ok'            => true,
                        'order_id'      => $tabOrderId,
                        'reference'     => $tab['reference'],
                        'added_count'   => $addedCount,
                        'new_total'     => $newTotal,
                        'station_label' => $stationLabel,
                        'message'       => $message,
                        'lines'         => $lines,
                    ]);
                    exit;
                }
                pos_redirectWithFlash([
                    'message' => $message,
                    'last_order_id' => $tabOrderId,
                    'last_order_ref' => $tab['reference'],
                    'just_parked' => true,
                ]);
            } elseif ($action === 'pay_existing') {
                /* === Recall a parked order and take payment (supports split bills + tips) === */
                $orderId       = (int)($_POST['order_id'] ?? 0);
                $paymentMethod = $_POST['payment_method'] ?? '';
                $tipAmount     = max(0.0, round((float)($_POST['tip_amount'] ?? 0), 2));
                $splitCount    = max(1, min(10, (int)($_POST['split_count'] ?? 1)));
                $splitNumber   = max(1, min($splitCount, (int)($_POST['split_number'] ?? 1)));

                if (!in_array($paymentMethod, $allowedMethods, true)) throw new RuntimeException('Select a payment method.');
                if ($paymentMethod === 'card_pos') throw new RuntimeException('Card POS terminal is not enabled yet — use Card (manual).');

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT id, reference, total_amount, status, order_type, created_by, COALESCE(split_count,1) AS split_count, COALESCE(split_paid_count,0) AS split_paid_count FROM stock_orders WHERE id=? FOR UPDATE");
                $stmt->execute([$orderId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new RuntimeException('Order not found.');
                if ($row['status'] !== 'placed') {
                    $settledStmt = $pdo->prepare("SELECT COALESCE(NULLIF(u.full_name, ''), u.username, '') AS recorded_by_name
                                                    FROM payments p
                                                    LEFT JOIN admin_users u ON u.id = p.recorded_by
                                                   WHERE p.booking_type = 'restaurant'
                                                     AND p.booking_id = ?
                                                     AND COALESCE(p.payment_type, '') != 'refund'
                                                     AND p.deleted_at IS NULL
                                                ORDER BY p.id DESC
                                                   LIMIT 1");
                    $settledStmt->execute([$orderId]);
                    $settledByName = trim((string)$settledStmt->fetchColumn());
                    $statusLabel = str_replace('_', ' ', (string)$row['status']);
                    $byLine = $settledByName !== '' ? ' by ' . $settledByName : '';
                    throw new RuntimeException("This tab is already {$statusLabel}{$byLine}. Refreshing open tabs so it cannot be charged twice.");
                }
                if (($row['order_type'] ?? '') === 'room_service') {
                    throw new RuntimeException('Room-service orders are settled via the guest folio at check-out — they cannot be paid directly at the till.');
                }
                if (($user['role'] ?? '') === 'restaurant_staff' && (int)$row['created_by'] !== (int)$user['id']) {
                    throw new RuntimeException('You can only settle tabs you opened.');
                }

                // Bar/coffee drinks are handed over immediately — auto-serve them (with stock
                // deduction) so settlement is never blocked by an un-bumped BDS ticket.
                pos_autoServeBarItems($pdo, $orderId, $user);
                // Only block on kitchen (food) items still in progress
                $pendingItemsStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id = ? AND station = 'kitchen' AND kds_status NOT IN ('served', 'void')");
                $pendingItemsStmt->execute([$orderId]);
                $pendingItems = (int)$pendingItemsStmt->fetchColumn();
                if ($pendingItems > 0) {
                    // Stranded food (fired before a business window boundary, or simply never
                    // bumped) can never reach 'served' on its own. A manager can force-serve it
                    // — explicit, attributed, audited — rather than the tab being stuck forever.
                    $forceServeOk = false;
                    $forceActorId = null;
                    $forceActorName = null;
                    if (!empty($_POST['force_serve_kitchen'])) {
                        if (hasPermission($user['id'], 'pos_force_serve')) {
                            $forceServeOk = true;
                        } else {
                            $forceServeToken = trim($_POST['mgr_auth_token'] ?? '');
                            $mgrAuth = $_SESSION['pos_mgr_auth'] ?? null;
                            if ($mgrAuth
                                && $mgrAuth['token'] === $forceServeToken
                                && $mgrAuth['expires'] >= time()
                                && in_array('pos_force_serve', $mgrAuth['permissions'], true)
                            ) {
                                $forceServeOk = true;
                                $forceActorId = $mgrAuth['manager_id'];
                                $forceActorName = $mgrAuth['manager_name'];
                                unset($_SESSION['pos_mgr_auth']);
                            }
                        }
                    }
                    if (!$forceServeOk) {
                        throw new RuntimeException('This tab still has ' . $pendingItems . ' food item' . ($pendingItems === 1 ? '' : 's') . ' not yet served — complete kitchen service, or ask a manager to force-serve it before settling.');
                    }
                    pos_forceServeKitchenItems($pdo, $orderId, $user, $forceActorId, $forceActorName);
                }

                // Apply discounts on first payment leg only (before any split payments)
                $firstLeg = ($splitNumber === 1 && (int)$row['split_paid_count'] === 0 && (float)($row['discount_amount'] ?? 0) == 0);

                if ($firstLeg) {
                    // Deal discounts — auto-applied, no pos_discount permission required
                    $dealDiscountRaw = max(0.0, round((float)($_POST['deal_discount_amount'] ?? 0), 2));
                    $dealIdsStr      = trim($_POST['deal_ids'] ?? '');
                    $dealValidation  = ($dealDiscountRaw > 0) ? pos_validate_deal_discount($pdo, $dealIdsStr, (float)$row['total_amount']) : ['amount' => 0.0, 'reason' => ''];
                    $dealDiscount    = min($dealDiscountRaw, round((float)$row['total_amount'] * 0.90, 2));
                    if ($dealDiscount > 0) {
                        $dealReason = !empty($dealValidation['reason']) ? $dealValidation['reason'] : 'Deal discount';
                        $row['total_amount'] = max(0.01, round((float)$row['total_amount'] - $dealDiscount, 2));
                        $pdo->prepare("UPDATE stock_orders SET total_amount=?, discount_amount=?, discount_reason=? WHERE id=?")
                            ->execute([$row['total_amount'], $dealDiscount, $dealReason, $orderId]);
                        pos_logAudit($pdo, $orderId, $user['id'], $user['full_name'], 'deal_discount_applied', json_encode(['amount' => $dealDiscount, 'deals' => $dealReason]));
                    }

                    // Manual staff discount — requires pos_discount permission
                    $discountAmount = max(0.0, round((float)($_POST['discount_amount'] ?? 0), 2));
                    $discountReason = mb_substr(trim($_POST['discount_reason'] ?? ''), 0, 255);
                    if ($discountAmount > 0) {
                        if (!hasPermission($user['id'], 'pos_discount')) throw new RuntimeException('You do not have permission to apply discounts.');
                        $discountedTotal = max(0.01, round((float)$row['total_amount'] - $discountAmount, 2));
                        $combinedDiscount = round($dealDiscount + $discountAmount, 2);
                        $combinedReason   = trim((isset($dealReason) && $dealReason ? $dealReason . ' + ' : '') . $discountReason);
                        $pdo->prepare("UPDATE stock_orders SET total_amount=?, discount_amount=?, discount_reason=? WHERE id=?")
                            ->execute([$discountedTotal, $combinedDiscount, $combinedReason ?: null, $orderId]);
                        $row['total_amount'] = $discountedTotal;
                        pos_logAudit($pdo, $orderId, $user['id'], $user['full_name'], 'discount_applied', json_encode(['amount' => $discountAmount, 'reason' => $discountReason]));
                        logActivity($user['id'], 'pos_discount', 'Discount ' . $currency_symbol . ' ' . number_format($discountAmount, 2) . ' on tab ' . $row['reference'] . ($discountReason ? ' — ' . $discountReason : ''));
                    }
                }

                // Validate split sequence
                if ($splitCount > 1) {
                    $dbPaid = (int)$row['split_paid_count'];
                    $dbCount = (int)$row['split_count'];
                    if ($splitNumber === 1 && $dbPaid === 0) {
                        // First split: record the agreed total on the order
                        $pdo->prepare("UPDATE stock_orders SET split_count = ? WHERE id = ?")->execute([$splitCount, $orderId]);
                    } elseif ($splitNumber !== $dbPaid + 1) {
                        throw new RuntimeException('Split sequence error — expected leg ' . ($dbPaid + 1) . ', received ' . $splitNumber . '. Refresh the page.');
                    } elseif ($dbCount !== $splitCount) {
                        throw new RuntimeException('Split count changed mid-session (' . $dbCount . ' vs ' . $splitCount . '). Refresh and restart.');
                    }
                }

                $extras = pos_applyPaymentToOrder($pdo, $user, $orderId, $row['reference'], (float)$row['total_amount'], $paymentMethod, $_POST, $splitCount, $splitNumber);
                $isLastSplit = ($splitCount <= 1 || $splitNumber >= $splitCount);
                $methodLabel = str_replace('_', ' ', $paymentMethod);
                $amtLabel    = $currency_symbol . ' ' . number_format($extras['amount_due'], 2);
                $tipLabel    = $tipAmount > 0 ? ' (incl. tip ' . $currency_symbol . ' ' . number_format($tipAmount, 2) . ')' : '';
                $auditEvent  = $splitCount > 1 ? 'split_paid' : 'paid_from_tab';
                pos_logAudit($pdo, $orderId, $user['id'], $user['full_name'], $auditEvent, json_encode(['method' => $paymentMethod, 'total' => $row['total_amount'], 'split_count' => $splitCount, 'split_number' => $splitNumber, 'split_amount' => $extras['split_amount'], 'tip' => $tipAmount, 'tendered' => $extras['tendered'], 'change' => $extras['change'], 'till' => 'pos.php']));
                $pdo->commit();
                if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v2');

                $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
                if ($isXhr) {
                    if (!$isLastSplit) {
                        // Intermediate split — keep modal open for next person
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'ok'                => true,
                            'split_intermediate' => true,
                            'split_paid'        => $splitNumber,
                            'split_total'       => $splitCount,
                            'splits_remaining'  => $splitCount - $splitNumber,
                            'split_amount'      => $extras['split_amount'],
                            'tip_amount'        => $tipAmount,
                            'change'            => $extras['change'],
                            'payment_method'    => $paymentMethod,
                            'message'           => "Split {$splitNumber}/{$splitCount} — {$methodLabel} {$amtLabel}{$tipLabel}. Next person ready.",
                        ]);
                        exit;
                    }
                    // Final split or single payment — show full receipt
                    $lastOrderId  = $orderId;
                    $lastOrderRef = $row['reference'];
                    $contactStmt  = $pdo->prepare("SELECT customer_name, customer_email, customer_phone FROM stock_orders WHERE id = ?");
                    $contactStmt->execute([$orderId]);
                    $contact = $contactStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $itemsStmt2 = $pdo->prepare("SELECT item_name, quantity, unit_price FROM stock_order_items WHERE order_id = ? ORDER BY id");
                    $itemsStmt2->execute([$orderId]);
                    $receiptItems = $itemsStmt2->fetchAll(PDO::FETCH_ASSOC);
                    $changeMsg = ($paymentMethod === 'cash' && ($extras['change'] ?? 0) > 0) ? ' Change: ' . $currency_symbol . ' ' . number_format($extras['change'], 2) . '.' : '';
                    $message = "Paid {$row['reference']} — {$currency_symbol} " . number_format((float)$row['total_amount'], 2) . " · {$methodLabel}." . $changeMsg;
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'ok'             => true,
                        'order_id'       => $orderId,
                        'reference'      => $row['reference'],
                        'total'          => (float)$row['total_amount'],
                        'tip_amount'     => $tipAmount,
                        'split_count'    => $splitCount,
                        'payment_method' => $paymentMethod,
                        'tendered'       => $extras['tendered'],
                        'change'         => $extras['change'],
                        'customer_name'  => (string)($contact['customer_name'] ?? ''),
                        'customer_email' => (string)($contact['customer_email'] ?? ''),
                        'customer_phone' => (string)($contact['customer_phone'] ?? ''),
                        'items'          => $receiptItems,
                        'message'        => $message,
                    ]);
                    exit;
                }

                $lastOrderId  = $orderId;
                $lastOrderRef = $row['reference'];
                $changeMsg = ($paymentMethod === 'cash' && ($extras['change'] ?? 0) > 0) ? ' Change: ' . $currency_symbol . ' ' . number_format($extras['change'], 2) . '.' : '';
                $message = $isLastSplit
                    ? "Paid {$row['reference']} — {$currency_symbol} " . number_format((float)$row['total_amount'], 2) . " · {$methodLabel}." . $changeMsg
                    : "Split {$splitNumber}/{$splitCount} recorded for {$row['reference']}.";
                pos_redirectWithFlash([
                    'message' => $message,
                    'last_order_id' => $lastOrderId,
                    'last_order_ref' => $lastOrderRef,
                    'just_parked' => false,
                ]);
            } elseif ($action === 'close_shift') {
                /* === Z-report: cashier declares cash, system records variance === */
                /* HARD BLOCK: a cashier (and the admin closing on their behalf) MUST settle or
                 * cancel every open tab they opened THIS BUSINESS WINDOW before closing. This
                 * stops the "leave a tab unpaid, close the shift, pocket the cash later"
                 * loophole, without letting one stranded tab from an earlier shift block every
                 * close from then on (that tab stays visible in the tray and is still reported).
                 * room_service orders are excluded: they are settled via the guest folio at
                 * check-out, never at the till, so they must never block a cashier's close. */
                $openTabsCheck = $pdo->prepare("SELECT reference FROM stock_orders WHERE created_by = ? AND status = 'placed' AND order_type != 'room_service' AND created_at >= ? ORDER BY created_at ASC LIMIT 20");
                $openTabsCheck->execute([$user['id'], $restaurantWindow['start_sql']]);
                $openTabRefs = $openTabsCheck->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($openTabRefs)) {
                    $refsLabel = implode(', ', $openTabRefs);
                    throw new RuntimeException('Cannot close shift: ' . count($openTabRefs) . ' open tab(s) from this shift still need to be settled or cancelled — ' . $refsLabel . '. Open the Tabs tray, take payment, or cancel them first.');
                }
                $declCash   = round((float)($_POST['declared_cash']   ?? 0), 2);
                $declMobile = round((float)($_POST['declared_mobile'] ?? 0), 2);
                $declCard   = round((float)($_POST['declared_card']   ?? 0), 2);
                $shiftNote  = trim($_POST['shift_note'] ?? '');
                if ($declCash < 0 || $declMobile < 0 || $declCard < 0) throw new RuntimeException('Declared amounts cannot be negative.');

                // Expected totals (this cashier, paid in the active restaurant window).
                // Uses paid_at so tabs created earlier but settled now are included.
                $windowStart = $restaurantWindow['start_sql'];
                $windowEnd = $restaurantWindow['end_sql'];
                // Split-order legs book to the tender each leg actually took
                // (stock_order_splits.payment_method); stock_orders.payment_method only ever
                // holds the LAST leg's method, so reading it for a mixed-tender split reported
                // the whole tab under one tender and made the drawer impossible to balance.
                // Non-split orders still read straight off stock_orders.
                $expNonSplitStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(CASE WHEN payment_method='cash' THEN total_amount + COALESCE(tip_amount,0) ELSE 0 END),0) AS cash,
                           COALESCE(SUM(CASE WHEN payment_method='mobile_money' THEN total_amount + COALESCE(tip_amount,0) ELSE 0 END),0) AS mobile,
                           COALESCE(SUM(CASE WHEN payment_method IN ('card_manual','card_pos') THEN total_amount + COALESCE(tip_amount,0) ELSE 0 END),0) AS card
                    FROM stock_orders
                    WHERE created_by = ?
                      AND status = 'paid'
                      AND COALESCE(split_count,1) <= 1
                      AND (
                              (paid_at IS NOT NULL AND paid_at >= ? AND paid_at < ?)
                          OR  (paid_at IS NULL AND created_at >= ? AND created_at < ?)
                      )
                ");
                $expNonSplitStmt->execute([$user['id'], $windowStart, $windowEnd, $windowStart, $windowEnd]);
                $expNonSplit = $expNonSplitStmt->fetch(PDO::FETCH_ASSOC) ?: ['cash' => 0, 'mobile' => 0, 'card' => 0];

                $expSplitStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(CASE WHEN s.payment_method='cash' THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END),0) AS cash,
                           COALESCE(SUM(CASE WHEN s.payment_method='mobile_money' THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END),0) AS mobile,
                           COALESCE(SUM(CASE WHEN s.payment_method IN ('card_manual','card_pos') THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END),0) AS card
                    FROM stock_order_splits s
                    INNER JOIN stock_orders o ON o.id = s.order_id
                    WHERE o.created_by = ?
                      AND o.status = 'paid'
                      AND COALESCE(o.split_count,1) > 1
                      AND (
                              (o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at < ?)
                          OR  (o.paid_at IS NULL AND o.created_at >= ? AND o.created_at < ?)
                      )
                ");
                $expSplitStmt->execute([$user['id'], $windowStart, $windowEnd, $windowStart, $windowEnd]);
                $expSplit = $expSplitStmt->fetch(PDO::FETCH_ASSOC) ?: ['cash' => 0, 'mobile' => 0, 'card' => 0];

                // Order-level metrics (tips, counts, stale-tab tracking) don't depend on which
                // tender a split leg used, so these still read straight off the order row.
                $expOrdersStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(COALESCE(tip_amount,0)),0) AS tips_total,
                           COUNT(*) AS orders_count,
                           COALESCE(SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END),0) AS settled_from_tabs_count,
                           COALESCE(SUM(CASE WHEN created_at < ? THEN total_amount + COALESCE(tip_amount,0) ELSE 0 END),0) AS settled_from_tabs_amount
                    FROM stock_orders
                    WHERE created_by = ?
                      AND status = 'paid'
                      AND (
                              (paid_at IS NOT NULL AND paid_at >= ? AND paid_at < ?)
                          OR  (paid_at IS NULL AND created_at >= ? AND created_at < ?)
                      )
                ");
                $expOrdersStmt->execute([$windowStart, $windowStart, $user['id'], $windowStart, $windowEnd, $windowStart, $windowEnd]);
                $expOrders = $expOrdersStmt->fetch(PDO::FETCH_ASSOC) ?: ['tips_total' => 0, 'orders_count' => 0, 'settled_from_tabs_count' => 0, 'settled_from_tabs_amount' => 0];

                $E = [
                    'cash'   => round((float)$expNonSplit['cash']   + (float)$expSplit['cash'], 2),
                    'mobile' => round((float)$expNonSplit['mobile'] + (float)$expSplit['mobile'], 2),
                    'card'   => round((float)$expNonSplit['card']   + (float)$expSplit['card'], 2),
                    'tips_total' => (float)$expOrders['tips_total'],
                    'orders_count' => (int)$expOrders['orders_count'],
                    'settled_from_tabs_count' => (int)$expOrders['settled_from_tabs_count'],
                    'settled_from_tabs_amount' => (float)$expOrders['settled_from_tabs_amount'],
                ];

                // Voids reporting follows voided_at (fallback to created_at for legacy rows).
                $voidsStmt = $pdo->prepare("
                    SELECT COUNT(*) AS voids_count,
                           COALESCE(SUM(total_amount),0) AS voids_amount
                    FROM stock_orders
                    WHERE created_by = ?
                      AND status = 'voided'
                      AND (
                            (voided_at IS NOT NULL AND voided_at >= ? AND voided_at < ?)
                         OR (voided_at IS NULL AND created_at >= ? AND created_at < ?)
                      )
                ");
                $voidsStmt->execute([$user['id'], $windowStart, $windowEnd, $windowStart, $windowEnd]);
                $V = $voidsStmt->fetch(PDO::FETCH_ASSOC) ?: ['voids_count' => 0, 'voids_amount' => 0];
                $E['voids_count'] = (int)($V['voids_count'] ?? 0);
                $E['voids_amount'] = (float)($V['voids_amount'] ?? 0);
                $vCash   = round($declCash   - (float)$E['cash'], 2);
                $vMobile = round($declMobile - (float)$E['mobile'], 2);
                $vCard   = round($declCard   - (float)$E['card'], 2);
                // Balance enforcement: cashier must balance to within MWK 1.00 unless an admin/manager overrides.
                $isPrivileged = in_array($user['role'] ?? '', ['admin', 'manager'], true);
                $overrideRequested = !empty($_POST['admin_override']);
                $overrideReason = trim($_POST['override_reason'] ?? '');
                $threshold = 1.00; // tolerance for rounding
                $maxVar = max(abs($vCash), abs($vMobile), abs($vCard));
                if ($maxVar > $threshold) {
                    if (!$isPrivileged) {
                        throw new RuntimeException('Shift does not balance (variance ' . number_format($maxVar, 2) . '). Recount the drawer or ask an admin/manager to close on your behalf with an override.');
                    }
                    if (!$overrideRequested) {
                        throw new RuntimeException('Variance of ' . number_format($maxVar, 2) . ' exceeds tolerance. Tick the override box to record this close with a reason.');
                    }
                    if (mb_strlen($overrideReason) < 5) {
                        throw new RuntimeException('Override reason is required (minimum 5 characters) and will be saved for audit.');
                    }
                    $shiftNote = trim('[OVERRIDE by ' . $user['username'] . '] ' . $overrideReason . ($shiftNote !== '' ? ' | ' . $shiftNote : ''));
                }

                $pdo->prepare("INSERT INTO stock_shift_closes (user_id, user_name, shift_date, closed_at, expected_cash, declared_cash, variance_cash, expected_mobile, declared_mobile, variance_mobile, expected_card, declared_card, variance_card, orders_count, voids_count, voids_amount, notes, ip_address) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$user['id'], $user['full_name'], $restaurantWindow['business_date'], (float)$E['cash'], $declCash, $vCash, (float)$E['mobile'], $declMobile, $vMobile, (float)$E['card'], $declCard, $vCard, (int)$E['orders_count'], (int)$E['voids_count'], (float)$E['voids_amount'], $shiftNote ?: null, $_SERVER['REMOTE_ADDR'] ?? null]);
                $closeId = (int)$pdo->lastInsertId();
                pos_logAudit($pdo, 0, $user['id'], $user['full_name'], 'shift_closed', json_encode(['close_id' => $closeId, 'window_start' => $windowStart, 'window_end' => $windowEnd, 'expected_cash' => $E['cash'], 'declared_cash' => $declCash, 'variance_cash' => $vCash, 'expected_mobile' => $E['mobile'], 'declared_mobile' => $declMobile, 'variance_mobile' => $vMobile, 'expected_card' => $E['card'], 'declared_card' => $declCard, 'variance_card' => $vCard, 'tips_total' => $E['tips_total'] ?? 0, 'orders' => $E['orders_count'], 'voids' => $E['voids_count'], 'settled_from_tabs_count' => $E['settled_from_tabs_count'], 'settled_from_tabs_amount' => $E['settled_from_tabs_amount'], 'override' => $overrideRequested && $maxVar > $threshold, 'override_reason' => $overrideRequested ? $overrideReason : null]));

                $justClosedShift = [
                    'expected_cash' => (float)$E['cash'],
                    'declared_cash' => $declCash,
                    'variance_cash' => $vCash,
                    'expected_mobile' => (float)$E['mobile'],
                    'declared_mobile' => $declMobile,
                    'variance_mobile' => $vMobile,
                    'expected_card' => (float)$E['card'],
                    'declared_card' => $declCard,
                    'variance_card' => $vCard,
                    'orders_count' => (int)$E['orders_count'],
                    'voids_count' => (int)$E['voids_count'],
                    'tips_total' => (float)($E['tips_total'] ?? 0),
                    'settled_from_tabs_count' => (int)$E['settled_from_tabs_count'],
                    'settled_from_tabs_amount' => (float)$E['settled_from_tabs_amount'],
                ];
                $message = 'Shift closed. Paid orders: ' . (int)$E['orders_count'] . ' (settled earlier tabs: ' . (int)$E['settled_from_tabs_count'] . '). Variance — cash: ' . number_format($vCash, 2) . ', mobile: ' . number_format($vMobile, 2) . ', card: ' . number_format($vCard, 2) . '.';
                pos_redirectWithFlash([
                    'message' => $message,
                    'just_closed_shift' => $justClosedShift,
                ]);
            } elseif ($action === 'set_float') {
                /* === Opening float declaration === */
                if (!hasPermission($user['id'], 'pos_float')) throw new RuntimeException('You do not have permission to declare an opening float.');
                $floatAmount = max(0.0, round((float)($_POST['float_amount'] ?? 0), 2));
                $floatNote   = mb_substr(trim($_POST['float_note'] ?? ''), 0, 255);
                $pdo->prepare("INSERT INTO stock_shift_opens (user_id, user_name, shift_date, float_amount, notes, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$user['id'], $user['full_name'], $restaurantWindow['business_date'], $floatAmount, $floatNote ?: null, $_SERVER['REMOTE_ADDR'] ?? null]);
                pos_logAudit($pdo, 0, $user['id'], $user['full_name'], 'float_set', json_encode(['amount' => $floatAmount, 'note' => $floatNote]));
                logActivity($user['id'], 'pos_float_set', 'Opening float declared: ' . $currency_symbol . ' ' . number_format($floatAmount, 2) . ($floatNote ? ' — ' . $floatNote : ''));
                $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
                if ($isXhr) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'float_amount' => $floatAmount, 'message' => 'Float set: ' . $currency_symbol . ' ' . number_format($floatAmount, 2)]);
                    exit;
                }
                pos_redirectWithFlash(['message' => 'Opening float recorded: ' . $currency_symbol . ' ' . number_format($floatAmount, 2)]);
            } elseif ($action === 'refund_order') {
                /* === Refund a paid order — requires pos_refund permission or manager auth === */
                $mgrAuthToken  = trim($_POST['mgr_auth_token'] ?? '');
                $mgrActorId    = null;
                $mgrActorName  = null;
                if (!hasPermission($user['id'], 'pos_refund')) {
                    // Check manager auth token granted in-session
                    $mgrAuth = $_SESSION['pos_mgr_auth'] ?? null;
                    if (!$mgrAuth
                        || $mgrAuth['token'] !== $mgrAuthToken
                        || $mgrAuth['expires'] < time()
                        || !in_array('pos_refund', $mgrAuth['permissions'], true)
                    ) {
                        throw new RuntimeException('Refund requires manager authorisation. Please use the manager auth overlay.');
                    }
                    $mgrActorId   = $mgrAuth['manager_id'];
                    $mgrActorName = $mgrAuth['manager_name'];
                    // Consume token (one-use)
                    unset($_SESSION['pos_mgr_auth']);
                }
                $refundOrderId = (int)($_POST['order_id'] ?? 0);
                $refundReason  = mb_substr(trim($_POST['refund_reason'] ?? ''), 0, 255);
                if ($refundOrderId <= 0) throw new RuntimeException('Invalid order ID.');
                if (mb_strlen($refundReason) < 5) throw new RuntimeException('Refund reason required (minimum 5 characters).');

                $pdo->beginTransaction();
                $refStmt = $pdo->prepare("SELECT id, reference, status, total_amount, tip_amount, payment_method, created_by FROM stock_orders WHERE id = ? FOR UPDATE");
                $refStmt->execute([$refundOrderId]);
                $refRow = $refStmt->fetch(PDO::FETCH_ASSOC);
                if (!$refRow) throw new RuntimeException('Order not found.');
                if ($refRow['status'] !== 'paid') throw new RuntimeException('Only paid orders can be refunded. Current status: ' . $refRow['status'] . '.');

                $refundTotal = (float)$refRow['total_amount'] + (float)($refRow['tip_amount'] ?? 0);
                $pdo->prepare("UPDATE stock_orders SET status='refunded', refunded_at=NOW(), refund_reason=? WHERE id=?")
                    ->execute([$refundReason, $refundOrderId]);
                // Create refund record for ledger reversal (canonical columns so refund reports pick it up).
                // Deliberately NOT wrapped in a try/catch: if this insert fails, the whole
                // refund must roll back (see outer catch) rather than leave the order marked
                // 'refunded' with cash out of the drawer and no ledger entry to show for it.
                {
                    // POS menu prices are gross — extract VAT from within (same as the sale sync)
                    $refVat = pos_calculateRestaurantVatParts((float)$refRow['total_amount']);

                    // The ledger reverses exactly what the sale recorded, and the sale
                    // (pos_syncPayment -> rh_sync_restaurant_payment) passes total_amount
                    // ONLY — the tip never enters `payments`. This previously wrote
                    // payment_amount = net + tip and total_amount = total + tip, so
                    // refunding a tipped order reversed more than was ever booked and left
                    // revenue negative by the tip. Tips are not revenue; they are cash
                    // movement, and admin/pos-accounting.php already counts them separately
                    // for till reconciliation via total_amount + tip_amount.
                    // $refundTotal below still includes the tip: that is what is physically
                    // handed back and what the audit trail and staff message should show.
                    $origPayStmt = $pdo->prepare("SELECT id FROM payments WHERE booking_type='restaurant' AND COALESCE(payment_type,'') != 'refund' AND deleted_at IS NULL AND (payment_reference = ? OR booking_id = ?) ORDER BY id DESC LIMIT 1");
                    $origPayStmt->execute(['POS-' . $refRow['reference'], $refundOrderId]);
                    $origPaymentId = (int)$origPayStmt->fetchColumn() ?: null;
                    $pdo->prepare("INSERT INTO payments (
                            payment_reference, booking_type, booking_id, booking_reference,
                            payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                            payment_method, payment_type, payment_status, status,
                            original_payment_id, refund_reason, refund_status, refund_amount,
                            notes, recorded_by, created_at
                        ) VALUES (?, 'restaurant', ?, ?, ?, ?, ?, ?, ?, ?, 'refund', 'completed', 'completed', ?, ?, 'completed', ?, ?, ?, NOW())")
                        ->execute([
                            'REF-POS-' . $refRow['reference'],
                            $refundOrderId,
                            $refRow['reference'],
                            rh_station_union_business_window()['business_date'] ?? date('Y-m-d'),
                            $refVat['net'],
                            $refVat['vat_rate'],
                            $refVat['vat'],
                            $refVat['gross'],
                            pos_mapMethod($refRow['payment_method'] ?? 'cash'),
                            $origPaymentId,
                            /* payments.refund_reason is an ENUM
                             * ('early_checkout','late_checkout_charge','cancellation',
                             * 'service_issue','overpayment','other') — NOT free text. Binding the
                             * cashier's typed reason here made MySQL reject the row with
                             * "Data truncated for column 'refund_reason'", and because the insert
                             * used to sit in a swallow-and-log try/catch, every POS refund since
                             * this code was written silently failed to reach the ledger. The
                             * operator's wording is preserved in `notes` (TEXT) just below. */
                            'other',
                            $refVat['gross'],
                            'Refund: ' . $refundReason,
                            $user['id'],
                        ]);
                }
                $auditDetails = ['reason' => $refundReason, 'total' => $refundTotal, 'original_method' => $refRow['payment_method']];
                if ($mgrActorId) {
                    $auditDetails['authorised_by_id']   = $mgrActorId;
                    $auditDetails['authorised_by_name'] = $mgrActorName;
                }
                pos_logAudit($pdo, $refundOrderId, $user['id'], $user['full_name'], 'refunded', json_encode($auditDetails));
                $activityDetail = 'Refund on order ' . $refRow['reference'] . ' (' . $currency_symbol . ' ' . number_format($refundTotal, 2) . '): ' . $refundReason;
                if ($mgrActorName) $activityDetail .= ' [Authorised by: ' . $mgrActorName . ']';
                logActivity($user['id'], 'pos_refund', $activityDetail);
                $pdo->commit();
                if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v2');
                $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
                if ($isXhr) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'reference' => $refRow['reference'], 'message' => 'Refund processed for ' . $refRow['reference']]);
                    exit;
                }
                pos_redirectWithFlash(['message' => 'Refund processed for ' . $refRow['reference'] . ' — ' . $currency_symbol . ' ' . number_format($refundTotal, 2)]);
            } else {
                /* === Default: place + pay (single transaction) === */
                $paymentMethod = $_POST['payment_method'] ?? '';
                if (!in_array($paymentMethod, $allowedMethods, true)) throw new RuntimeException('Select a payment method.');
                if ($paymentMethod === 'card_pos') throw new RuntimeException('Card POS terminal is not enabled yet — use Card (manual).');
                // Room-service orders must always be charged to the guest folio — settled at checkout.
                // Direct cash/card/mobile payment at the till is not permitted for room service.
                if ($orderType === 'room_service') {
                    throw new RuntimeException('Room-service orders are charged to the guest room folio — use Park (Fire to Kitchen) to send the order to the kitchen.');
                }

                $pdo->beginTransaction();
                [$orderId, $reference, $totalAmount, $count] = pos_buildOrderFromPost($pdo, $user, $orderType, $tableNumber, $customerName, $customerEmail, $customerPhone, $orderNote, false);
                $coversPay = max(0, min(99, (int)($_POST['covers'] ?? 0)));
                if ($posHasCoversCol && $coversPay > 0) {
                    $pdo->prepare("UPDATE stock_orders SET covers=? WHERE id=?")->execute([$coversPay, $orderId]);
                }
                // Deal discounts — auto-applied, no pos_discount permission required
                $dealDiscountRaw  = max(0.0, round((float)($_POST['deal_discount_amount'] ?? 0), 2));
                $dealIdsStr       = trim($_POST['deal_ids'] ?? '');
                $dealValidation   = ($dealDiscountRaw > 0) ? pos_validate_deal_discount($pdo, $dealIdsStr, $totalAmount) : ['amount' => 0.0, 'reason' => ''];
                // Cap deal discount at 90% of order total — sanity guard
                $dealDiscount = min($dealDiscountRaw, round($totalAmount * 0.90, 2));
                if ($dealDiscount > 0) {
                    $dealReason = !empty($dealValidation['reason']) ? $dealValidation['reason'] : 'Deal discount';
                    $totalAmount = max(0.01, round($totalAmount - $dealDiscount, 2));
                    $pdo->prepare("UPDATE stock_orders SET total_amount=?, discount_amount=?, discount_reason=? WHERE id=?")
                        ->execute([$totalAmount, $dealDiscount, $dealReason, $orderId]);
                    pos_logAudit($pdo, $orderId, $user['id'], $user['full_name'], 'deal_discount_applied', json_encode(['amount' => $dealDiscount, 'deals' => $dealReason]));
                }

                // Manual staff discount — requires pos_discount permission
                $discountAmount = max(0.0, round((float)($_POST['discount_amount'] ?? 0), 2));
                $discountReason = mb_substr(trim($_POST['discount_reason'] ?? ''), 0, 255);
                if ($discountAmount > 0 && $discountAmount < $totalAmount) {
                    if (!hasPermission($user['id'], 'pos_discount')) throw new RuntimeException('You do not have permission to apply discounts.');
                    $totalAmount = max(0.01, round($totalAmount - $discountAmount, 2));
                    // If a deal already set discount_amount, add to it
                    $existingDiscount = (float)($dealDiscount);
                    $combinedDiscount = round($existingDiscount + $discountAmount, 2);
                    $combinedReason   = trim((isset($dealReason) && $dealReason ? $dealReason . ' + ' : '') . $discountReason);
                    $pdo->prepare("UPDATE stock_orders SET total_amount=?, discount_amount=?, discount_reason=? WHERE id=?")
                        ->execute([$totalAmount, $combinedDiscount, $combinedReason ?: null, $orderId]);
                    pos_logAudit($pdo, $orderId, $user['id'], $user['full_name'], 'discount_applied', json_encode(['amount' => $discountAmount, 'reason' => $discountReason]));
                    logActivity($user['id'], 'pos_discount', 'Discount ' . $currency_symbol . ' ' . number_format($discountAmount, 2) . ' on order ' . $reference . ($discountReason ? ' — ' . $discountReason : ''));
                }
                $extras = pos_applyPaymentToOrder($pdo, $user, $orderId, $reference, $totalAmount, $paymentMethod, $_POST);
                // Bar/coffee-bar drinks are handed to the customer at payment — auto-serve
                // them now so stock is deducted immediately (mirrors the pay_existing tab flow).
                pos_autoServeBarItems($pdo, $orderId, $user);
                // Fire remaining (food) items to the kitchen. pos_autoServeBarItems already
                // marked bar items served, so fireKitchen only picks up kitchen-station items.
                if (in_array($orderType, ['dine_in', 'takeaway', 'room_service', 'walk_in'], true)) {
                    pos_fireKitchen($pdo, $orderId, $user['id'], $user['full_name']);
                }
                pos_logAudit($pdo, $orderId, $user['id'], $user['full_name'], 'placed_paid', json_encode([
                    'method' => $paymentMethod,
                    'total' => $totalAmount,
                    'lines' => $count,
                    'tendered' => $extras['tendered'],
                    'change' => $extras['change'],
                    'till' => 'pos.php'
                ]));
                $pdo->commit();
                if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v2');
                $lastOrderId = $orderId;
                $lastOrderRef = $reference;
                $changeMsg = ($paymentMethod === 'cash' && $extras['change'] > 0) ? ' Change: ' . $currency_symbol . ' ' . number_format($extras['change'], 2) . '.' : '';
                $message = "Paid {$reference} — {$currency_symbol} " . number_format($totalAmount, 2) . " · " . str_replace('_', ' ', $paymentMethod) . "." . $changeMsg;
                pos_redirectWithFlash([
                    'message' => $message,
                    'last_order_id' => $lastOrderId,
                    'last_order_ref' => $lastOrderRef,
                    'just_parked' => false,
                ]);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
            // Return JSON error for XHR requests so the JS can show inline messages
            $isXhrErr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isXhrErr && in_array(($_POST['action'] ?? ''), ['park', 'pay_existing', 'add_to_tab', 'refund_order'], true)) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $error]);
                exit;
            }
        }
    }
}

/* ---------------- Load menu, categories, snapshot, recent ----------------
 * Items flagged for EITHER POS or Room Service are loaded from the unified
 * menu_items table. Each item carries `show_pos` / `show_rs` flags so the
 * JS can filter per active mode. Categories are dynamic from menu_categories.
 * Managers/admins also see unavailable items (is_available=0) with an 86 badge
 * so they can toggle availability from the till without leaving the POS. */
$isManagerOrAdmin = in_array($user['role'] ?? '', ['admin', 'manager'], true);
$posCanRefund   = hasPermission($user['id'], 'pos_refund');
$posCanDiscount = hasPermission($user['id'], 'pos_discount');
$posCanToggle86 = hasPermission($user['id'], 'pos_86');
$posCanFloat    = hasPermission($user['id'], 'pos_float');
$posCanForceServe = hasPermission($user['id'], 'pos_force_serve');
$menuAvailFilter = $posCanToggle86 ? '' : 'AND mi.is_available = 1';
// Catalog scoping per business preset: food-service installations sell from
// food_service categories (Food, Drinks); everyone else (supermarket, gym,
// retail) sells from retail categories. A hotel never sees Groceries; a
// supermarket never sees the restaurant menu. COALESCE keeps working if the
// column predates a migration on some environment.
$posCatalogContext = (function_exists('isRestaurantEnabled') && isRestaurantEnabled()) ? 'food_service' : 'retail';
$allMenuItems = $pdo->query("
    SELECT mi.id, mi.item_name AS name, mi.price,
           COALESCE(mi.category, 'Other') AS sub_category,
           mi.show_pos, mi.show_room_service, mi.is_available,
           mi.barcode,
           mc.name AS cat_name, mc.slug AS menu_type, mc.sort_order AS cat_sort
    FROM menu_items mi
    JOIN menu_categories mc ON mc.id = mi.category_id
    WHERE mc.is_active = 1
      AND COALESCE(mc.business_context, 'food_service') = " . $pdo->quote($posCatalogContext) . "
      AND (mi.show_pos = 1 OR mi.show_room_service = 1)
      $menuAvailFilter
    ORDER BY mc.sort_order ASC, mi.display_order ASC, mi.item_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$menuList = [];
$categories = ['__ALL__' => ['label' => 'All', 'count' => 0]];
$posVisibleCount = 0;
foreach ($allMenuItems as $item) {
    $cat = $item['cat_name'] . ' · ' . ($item['sub_category'] ?: 'Other');
    $isPos = (int)$item['show_pos'];
    $isRs  = (int)$item['show_room_service'];
    $isAvail = (int)$item['is_available'];
    if ($isPos && $isAvail) {
        $categories[$cat] = ['label' => $cat, 'count' => ($categories[$cat]['count'] ?? 0) + 1];
        $posVisibleCount++;
    } elseif ($isPos && !$isAvail && $posCanToggle86) {
        // Count 86'd items under their category so managers still see the category
        $categories[$cat] = ['label' => $cat, 'count' => ($categories[$cat]['count'] ?? 0)];
    }
    $menuList[] = [
        'id'          => (int)$item['id'],
        'type'        => $item['menu_type'],
        'name'        => $item['name'],
        'price'       => (float)$item['price'],
        'category'    => $cat,
        'show_pos'    => $isPos,
        'show_rs'     => $isRs,
        'is_available'=> $isAvail,
        'barcode'     => $item['barcode'] ?? null,
    ];
}
$categories['__ALL__']['count'] = $posVisibleCount;

$stockSnapshot = [];
$snap = $pdo->query("
    SELECT sr.menu_item_id, sr.menu_type,
           MIN(FLOOR(GREATEST(0, i.current_quantity) / (sri.quantity_per_portion / (GREATEST(sri.yield_percent, 0.1)/100)))) AS max_portions
    FROM stock_recipes sr
    INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
    INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id
    WHERE sri.quantity_per_portion > 0
    GROUP BY sr.menu_item_id, sr.menu_type
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($snap as $s) $stockSnapshot[$s['menu_type'] . ':' . $s['menu_item_id']] = (int)$s['max_portions'];

/* Active POS deals — loaded once at page time, evaluated in JS per-cart-change. */
$posDealsRaw = [];
try {
    $posDealsRaw = $pdo->query("
        SELECT id, name, description, deal_type, days_of_week,
               start_time, end_time, valid_from, valid_to,
               applies_to, item_types, item_ids,
               discount_percent, discount_fixed,
               multi_buy_qty, multi_buy_pay,
               spend_threshold, combo_requires,
               max_uses_per_order, exclusive
        FROM pos_deals
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($posDealsRaw as &$d) {
        $d['days_of_week']    = $d['days_of_week']    ? json_decode($d['days_of_week'],    true) : null;
        $d['item_types']      = $d['item_types']      ? json_decode($d['item_types'],      true) : null;
        $d['item_ids']        = $d['item_ids']        ? json_decode($d['item_ids'],        true) : null;
        $d['combo_requires']  = $d['combo_requires']  ? json_decode($d['combo_requires'],  true) : null;
        $d['discount_percent']    = (float)$d['discount_percent'];
        $d['discount_fixed']      = (float)$d['discount_fixed'];
        $d['spend_threshold']     = $d['spend_threshold'] !== null ? (float)$d['spend_threshold'] : null;
        $d['multi_buy_qty']       = $d['multi_buy_qty'] !== null ? (int)$d['multi_buy_qty'] : null;
        $d['multi_buy_pay']       = $d['multi_buy_pay'] !== null ? (int)$d['multi_buy_pay'] : null;
        $d['max_uses_per_order']  = $d['max_uses_per_order'] !== null ? (int)$d['max_uses_per_order'] : null;
        $d['exclusive']           = (bool)$d['exclusive'];
    }
    unset($d);
} catch (Exception $e) {
    $posDealsRaw = [];
    $posDealsError = $e->getMessage();
}

/**
 * Server-side deal validation: verify submitted deal IDs are genuinely active
 * right now and return the total capped deal discount to apply.
 * Deal discounts do NOT require pos_discount permission — they are automatic.
 */
function pos_validate_deal_discount(PDO $pdo, string $dealIdsStr, float $totalAmount): array
{
    $result = ['amount' => 0.0, 'reason' => ''];
    if (empty(trim($dealIdsStr))) return $result;

    $ids = array_values(array_filter(array_map('intval', explode(',', $dealIdsStr))));
    if (empty($ids)) return $result;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $nowDate = date('Y-m-d');
    $nowTime = date('H:i:s');
    $nowDow  = (int)date('N'); // 1=Mon … 7=Sun

    $stmt = $pdo->prepare("
        SELECT id, name, days_of_week, start_time, end_time, valid_from, valid_to
        FROM pos_deals
        WHERE id IN ($placeholders) AND is_active = 1
          AND (valid_from IS NULL OR valid_from <= ?)
          AND (valid_to   IS NULL OR valid_to   >= ?)
    ");
    $stmt->execute([...$ids, $nowDate, $nowDate]);
    $deals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $validNames = [];
    foreach ($deals as $d) {
        // Check day-of-week window
        if ($d['days_of_week']) {
            $days = json_decode($d['days_of_week'], true) ?: [];
            if (!in_array($nowDow, $days, true)) continue;
        }
        // Check time window
        if ($d['start_time'] && $d['end_time']) {
            if ($nowTime < $d['start_time'] || $nowTime > $d['end_time']) continue;
        }
        $validNames[] = $d['name'];
    }

    if (empty($validNames)) return $result;

    $result['reason'] = implode(', ', $validNames);
    return $result;
}

/* My-shift summary (per-cashier, based on payment time in this restaurant window). */
function pos_fetch_shift_summary(PDO $pdo, array $restaurantWindow, int $userId): array
{
    $myShift = $pdo->prepare("
        SELECT COUNT(*) AS orders_today,
            COALESCE(SUM(total_amount),0) AS revenue_today,
            /* Split orders book per-leg from stock_order_splits — the order row's
             * payment_method only holds the LAST leg's tender, which would show a
             * mixed cash+card split entirely under card in the till's live header. */
            COALESCE(SUM(CASE
                WHEN COALESCE(split_count,1) <= 1 AND payment_method='cash' THEN total_amount
                WHEN COALESCE(split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method='cash' THEN s.split_amount ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = stock_orders.id),0)
                ELSE 0 END),0) AS cash_today,
            COALESCE(SUM(CASE
                WHEN COALESCE(split_count,1) <= 1 AND payment_method='mobile_money' THEN total_amount
                WHEN COALESCE(split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method='mobile_money' THEN s.split_amount ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = stock_orders.id),0)
                ELSE 0 END),0) AS mobile_today,
            COALESCE(SUM(CASE
                WHEN COALESCE(split_count,1) <= 1 AND payment_method IN ('card_manual','card_pos') THEN total_amount
                WHEN COALESCE(split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method IN ('card_manual','card_pos') THEN s.split_amount ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = stock_orders.id),0)
                ELSE 0 END),0) AS card_today,
            COALESCE(SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END),0) AS settled_from_tabs_count,
            COALESCE(SUM(CASE WHEN created_at < ? THEN total_amount ELSE 0 END),0) AS settled_from_tabs_amount
        FROM stock_orders
        WHERE created_by = ?
          AND status = 'paid'
          AND (
                (paid_at IS NOT NULL AND paid_at >= ? AND paid_at < ?)
             OR (paid_at IS NULL AND created_at >= ? AND created_at < ?)
          )
    ");
    $myShift->execute([
        $restaurantWindow['start_sql'],
        $restaurantWindow['start_sql'],
        $userId,
        $restaurantWindow['start_sql'],
        $restaurantWindow['end_sql'],
        $restaurantWindow['start_sql'],
        $restaurantWindow['end_sql']
    ]);
    $row = $myShift->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'orders_today' => (int)($row['orders_today'] ?? 0),
        'revenue_today' => (float)($row['revenue_today'] ?? 0),
        'cash_today' => (float)($row['cash_today'] ?? 0),
        'mobile_today' => (float)($row['mobile_today'] ?? 0),
        'card_today' => (float)($row['card_today'] ?? 0),
        'settled_from_tabs_count' => (int)($row['settled_from_tabs_count'] ?? 0),
        'settled_from_tabs_amount' => (float)($row['settled_from_tabs_amount'] ?? 0),
    ];
}

$shift = pos_fetch_shift_summary($pdo, $restaurantWindow, (int)$user['id']);

if (isset($_GET['ajax']) && $_GET['ajax'] === 'shift_stats') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode([
            'success' => true,
            'shift' => pos_fetch_shift_summary($pdo, $restaurantWindow, (int)$user['id']),
            'ts' => date('c'),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to load shift stats.']);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_item') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hasPermission($user['id'], 'pos_86')) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to toggle item availability.']);
        exit;
    }
    $toggleItemId = (int)($_GET['item_id'] ?? 0);
    if (!$toggleItemId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid item ID.']);
        exit;
    }
    try {
        $currStmt = $pdo->prepare("SELECT id, item_name, is_available FROM menu_items WHERE id = ?");
        $currStmt->execute([$toggleItemId]);
        $currItem = $currStmt->fetch(PDO::FETCH_ASSOC);
        if (!$currItem) {
            http_response_code(404);
            echo json_encode(['error' => 'Item not found.']);
            exit;
        }
        $newAvail = $currItem['is_available'] ? 0 : 1;
        $pdo->prepare("UPDATE menu_items SET is_available = ? WHERE id = ?")->execute([$newAvail, $toggleItemId]);
        $action86 = $newAvail ? 'item_enabled' : 'item_86d';
        pos_logAudit($pdo, 0, $user['id'], $user['full_name'], $action86, json_encode(['item_id' => $toggleItemId, 'item_name' => $currItem['item_name']]));
        logActivity($user['id'], 'pos_' . $action86, ($newAvail ? 'Enabled' : '86\'d') . ' menu item: ' . $currItem['item_name']);
        echo json_encode(['ok' => true, 'item_id' => $toggleItemId, 'is_available' => $newAvail, 'item_name' => $currItem['item_name']]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'assign_barcode') {
    /* === Assign or clear a barcode on a menu item ===
     * Accepts POST: item_id, barcode (empty string = clear)
     * Requires pos_86 permission (manager-level). */
    header('Content-Type: application/json; charset=utf-8');
    if (!hasPermission($user['id'], 'pos_86')) {
        http_response_code(403);
        echo json_encode(['error' => 'Manager permission required to assign barcodes.']);
        exit;
    }
    $bcItemId = (int)($_POST['item_id'] ?? 0);
    $bcValue  = trim((string)($_POST['barcode'] ?? ''));
    if (!$bcItemId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid item ID.']);
        exit;
    }
    if (mb_strlen($bcValue) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Barcode too long (max 100 chars).']);
        exit;
    }
    try {
        $bcCheck = $pdo->prepare("SELECT id, item_name FROM menu_items WHERE id = ?");
        $bcCheck->execute([$bcItemId]);
        $bcItem = $bcCheck->fetch(PDO::FETCH_ASSOC);
        if (!$bcItem) {
            http_response_code(404);
            echo json_encode(['error' => 'Item not found.']);
            exit;
        }
        if ($bcValue !== '') {
            // check uniqueness
            $dupCheck = $pdo->prepare("SELECT id FROM menu_items WHERE barcode = ? AND id != ?");
            $dupCheck->execute([$bcValue, $bcItemId]);
            if ($dupCheck->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'That barcode is already assigned to another item.']);
                exit;
            }
        }
        $pdo->prepare("UPDATE menu_items SET barcode = ? WHERE id = ?")
            ->execute([$bcValue !== '' ? $bcValue : null, $bcItemId]);
        pos_logAudit($pdo, 0, $user['id'], $user['full_name'], 'barcode_assigned',
            json_encode(['item_id' => $bcItemId, 'item_name' => $bcItem['item_name'], 'barcode' => $bcValue]));
        logActivity($user['id'], 'pos_barcode_assigned',
            ($bcValue !== '' ? "Assigned barcode '{$bcValue}'" : 'Cleared barcode') . ' on: ' . $bcItem['item_name']);
        echo json_encode(['ok' => true, 'item_id' => $bcItemId, 'barcode' => $bcValue !== '' ? $bcValue : null]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'manager_auth') {
    /* === Manager in-session authorisation for privileged POS actions ===
     * Accepts: username, password, required_permission
     * Returns: { ok, token } or { error }
     * Token is stored in $_SESSION['pos_mgr_auth'] and consumed on first use. */
    header('Content-Type: application/json; charset=utf-8');
    $mgrUsername  = trim($_POST['username'] ?? '');
    $mgrPassword  = $_POST['password'] ?? '';
    $requiredPerm = trim($_POST['required_permission'] ?? '');
    if (!$mgrUsername || !$mgrPassword || !$requiredPerm) {
        http_response_code(400);
        echo json_encode(['error' => 'Username, password and required permission are required.']);
        exit;
    }
    // Validate the permission key exists
    $allPerms = getAllPermissions();
    if (!isset($allPerms[$requiredPerm])) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown permission key.']);
        exit;
    }
    try {
        $mgrStmt = $pdo->prepare("SELECT id, full_name, username, password_hash, role, is_active FROM admin_users WHERE username = ? LIMIT 1");
        $mgrStmt->execute([$mgrUsername]);
        $mgrUser = $mgrStmt->fetch(PDO::FETCH_ASSOC);
        if (!$mgrUser || !$mgrUser['is_active'] || !password_verify($mgrPassword, $mgrUser['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid manager credentials.']);
            exit;
        }
        if ($mgrUser['id'] === $user['id']) {
            http_response_code(400);
            echo json_encode(['error' => 'You cannot authorise your own actions — a different manager must approve.']);
            exit;
        }
        if (!hasPermission((int)$mgrUser['id'], $requiredPerm)) {
            http_response_code(403);
            echo json_encode(['error' => $mgrUser['full_name'] . ' does not have the ' . ($allPerms[$requiredPerm]['label'] ?? $requiredPerm) . ' permission.']);
            exit;
        }
        // Issue session token (one-use, 5 min expiry)
        $token = bin2hex(random_bytes(24));
        $_SESSION['pos_mgr_auth'] = [
            'token'        => $token,
            'manager_id'   => (int)$mgrUser['id'],
            'manager_name' => $mgrUser['full_name'] ?: $mgrUser['username'],
            'permissions'  => [$requiredPerm],
            'expires'      => time() + 300,
        ];
        logActivity((int)$mgrUser['id'], 'pos_mgr_auth_granted', 'Authorised ' . ($user['full_name'] ?: $user['username']) . ' to perform: ' . $requiredPerm . ' (in-session override, till)');
        echo json_encode(['ok' => true, 'token' => $token, 'manager_name' => $mgrUser['full_name'] ?: $mgrUser['username']]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Auth error: ' . $e->getMessage()]);
    }
    exit;
}

$myRecent = $pdo->prepare("SELECT id, reference, total_amount, payment_method, change_due, status, discount_amount, created_at FROM stock_orders WHERE created_by = ? AND created_at >= ? AND created_at < ? ORDER BY created_at DESC LIMIT 10");
$myRecent->execute([$user['id'], $restaurantWindow['start_sql'], $restaurantWindow['end_sql']]);
$recent = $myRecent->fetchAll(PDO::FETCH_ASSOC);

$restaurantTables = rh_restaurant_active_tables($pdo);
$checkedInRooms = rh_restaurant_checked_in_rooms($pdo);
$activeLocationLocks = rh_restaurant_active_location_locks($pdo);

/* Open tabs (placed but not yet paid). No time bound — a stale previous-shift
 * tab must stay visible and cannot be left behind, however old. Excludes
 * room_service (settled via the guest folio, never a till obligation).
 * Admins/managers see all tabs; restaurant_staff only see their own. */
$tabsCoversSelect = $posHasCoversCol ? 'COALESCE(o.covers, 0) AS covers,' : '0 AS covers,';
$tabsSql = "SELECT o.id, o.reference, o.total_amount, o.table_number, o.customer_name, o.created_at, o.created_by,
                   {$tabsCoversSelect}
                   COALESCE(o.split_count, 1) AS split_count, COALESCE(o.split_paid_count, 0) AS split_paid_count,
                   u.full_name AS opened_by,
                   (SELECT COUNT(*) FROM stock_order_items WHERE order_id = o.id) AS line_count,
                   (SELECT COUNT(*) FROM stock_order_items WHERE order_id = o.id AND kds_status = 'pending')                   AS pending_count,
                   (SELECT COUNT(*) FROM stock_order_items WHERE order_id = o.id AND kds_status IN ('preparing','in_progress')) AS preparing_count,
                   (SELECT COUNT(*) FROM stock_order_items WHERE order_id = o.id AND kds_status = 'ready')                     AS ready_count,
                   (SELECT COUNT(*) FROM stock_order_items WHERE order_id = o.id AND kds_status = 'collection')                AS collection_count,
                   (SELECT COUNT(*) FROM stock_order_items WHERE order_id = o.id AND kds_status = 'served')                    AS served_count
            FROM stock_orders o
            LEFT JOIN admin_users u ON u.id = o.created_by
            WHERE o.status = 'placed' AND o.order_type != 'room_service' ";
$tabsArgs = [];
if (($user['role'] ?? '') === 'restaurant_staff') {
    $tabsSql .= " AND o.created_by = ? ";
    $tabsArgs[] = $user['id'];
}
$tabsSql .= " ORDER BY o.created_at DESC LIMIT 50";
try {
    $tabsStmt = $pdo->prepare($tabsSql);
    $tabsStmt->execute($tabsArgs);
    $openTabs = $tabsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $tabsEx) {
    // Migration 043 not yet run — split_count / split_paid_count columns absent; fall back to literals
    $tabsFallbackSql = str_replace(
        'COALESCE(o.split_count, 1) AS split_count, COALESCE(o.split_paid_count, 0) AS split_paid_count,',
        '1 AS split_count, 0 AS split_paid_count,',
        $tabsSql
    );
    $tabsFallback = $pdo->prepare($tabsFallbackSql);
    $tabsFallback->execute($tabsArgs);
    $openTabs = $tabsFallback->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'tabs') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'tabs' => $openTabs,
        'count' => count($openTabs),
        'window_start' => $restaurantWindow['start_sql'],
        'now' => date('c'),
    ]);
    exit;
}

/* ============================================================
 * Admin/manager live "All Stations" JSON poll endpoint.
 * GET ?ajax=stations — returns counts + ticket details across
 * Kitchen / Bar / Coffee Bar plus open tabs and today's revenue.
 * Read-only, no writes, lightweight (≤50 rows).
 * ============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stations') {
    header('Content-Type: application/json; charset=utf-8');
    if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    try {
        $stations = ['kitchen', 'bar', 'coffee_bar'];
        $counts = [];
        $countStmt = $pdo->prepare("
            SELECT oi.station,
                   SUM(CASE WHEN oi.kds_status='pending' THEN 1 ELSE 0 END) AS pending,
                   SUM(CASE WHEN oi.kds_status='preparing' THEN 1 ELSE 0 END) AS in_progress,
                   SUM(CASE WHEN oi.kds_status='ready' THEN 1 ELSE 0 END) AS ready,
                   SUM(CASE WHEN oi.kds_status NOT IN ('served','void') THEN 1 ELSE 0 END) AS open_total
            FROM stock_order_items oi
            INNER JOIN stock_orders o ON o.id = oi.order_id
            WHERE oi.station = ?
              AND o.fired_at IS NOT NULL
                            AND o.fired_at >= ?
                            AND o.fired_at < ?
              AND o.status NOT IN ('voided','cancelled')
        ");
        foreach ($stations as $st) {
            $countStmt->execute([$st, $restaurantWindow['start_sql'], $restaurantWindow['end_sql']]);
            $r = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $counts[$st] = [
                'pending'     => (int)($r['pending']     ?? 0),
                'in_progress' => (int)($r['in_progress'] ?? 0),
                'ready'       => (int)($r['ready']       ?? 0),
                'open_total'  => (int)($r['open_total']  ?? 0),
            ];
        }

        // Live tickets per station (max 30 each, oldest first to surface bottlenecks).
        $itemsStmt = $pdo->prepare("
            SELECT oi.id, oi.order_id, oi.item_name, oi.quantity, oi.notes, oi.kds_status,
                   oi.station, o.reference, o.table_number, o.fired_at, o.order_type,
                   o.customer_name, ir.room_number AS booking_room_number
            FROM stock_order_items oi
            INNER JOIN stock_orders o ON o.id = oi.order_id
            LEFT JOIN individual_rooms ir ON ir.id = o.individual_room_id
            WHERE oi.station = ?
              AND oi.kds_status NOT IN ('served','void')
              AND o.fired_at IS NOT NULL
              AND o.fired_at >= ?
              AND o.fired_at < ?
              AND o.status NOT IN ('voided','cancelled')
            ORDER BY o.fired_at ASC
            LIMIT 30
        ");
        $tickets = [];
        foreach ($stations as $st) {
            $itemsStmt->execute([$st, $restaurantWindow['start_sql'], $restaurantWindow['end_sql']]);
            $tickets[$st] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Open-tabs count system-wide and current business-window totals. This endpoint is
        // admin/manager-only (checked above), so there is no separate "visible to me" subset —
        // open_tabs_visible mirrors open_tabs_all and exists only because the JS badge reads
        // whichever one is present.
        $openAll = (int)$pdo->query("SELECT COUNT(*) FROM stock_orders WHERE status='placed' AND order_type != 'room_service'")->fetchColumn();
        $todayTotalsStmt = $pdo->prepare("
            SELECT COUNT(*) AS orders_count,
                   COALESCE(SUM(CASE WHEN status='paid' THEN total_amount ELSE 0 END),0) AS revenue
            FROM stock_orders WHERE created_at >= ? AND created_at < ?
        ");
        $todayTotalsStmt->execute([$restaurantWindow['start_sql'], $restaurantWindow['end_sql']]);
        $todayTotals = $todayTotalsStmt->fetch(PDO::FETCH_ASSOC) ?: ['orders_count' => 0, 'revenue' => 0];

        echo json_encode([
            'ts'             => date('c'),
            'counts'         => $counts,
            'tickets'        => $tickets,
            'open_tabs_all'  => $openAll,
            'open_tabs_visible' => $openAll,
            'orders_today'   => (int)$todayTotals['orders_count'],
            'revenue_today'  => (float)$todayTotals['revenue'],
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* ============================================================
 * Admin/manager: today's restaurant orders AJAX endpoint.
 * GET ?ajax=resto_orders — order list + summary for today.
 * ============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'resto_orders') {
    header('Content-Type: application/json; charset=utf-8');
    if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    try {
        $restoStmt = $pdo->prepare("
            SELECT o.id, o.reference, o.order_type, o.status, o.total_amount,
                   o.created_at, o.fired_at, o.table_number, o.customer_name,
                   ir.room_number, COUNT(oi.id) AS item_count
            FROM stock_orders o
            LEFT JOIN stock_order_items oi ON oi.order_id = o.id
            LEFT JOIN individual_rooms ir ON ir.id = o.individual_room_id
            WHERE o.created_at >= ?
              AND o.created_at < ?
              AND o.status NOT IN ('voided','cancelled')
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT 150
        ");
        $restoStmt->execute([$restaurantWindow['start_sql'], $restaurantWindow['end_sql']]);
        $restoOrders = $restoStmt->fetchAll(PDO::FETCH_ASSOC);
        $restoSumStmt = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status='placed' THEN 1 ELSE 0 END) AS open_tabs,
                   SUM(CASE WHEN status='paid'   THEN 1 ELSE 0 END) AS paid,
                   COALESCE(SUM(total_amount), 0) AS revenue
            FROM stock_orders
            WHERE created_at >= ?
              AND created_at < ?
              AND status NOT IN ('voided','cancelled')
        ");
        $restoSumStmt->execute([$restaurantWindow['start_sql'], $restaurantWindow['end_sql']]);
        $restoSum = $restoSumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['orders' => $restoOrders, 'summary' => $restoSum]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* Deep-link: ?settle=ID from stock-orders.php "Take Payment" buttons.
   Admin/manager (or the cashier who opened the tab) lands here with the settle modal pre-opened. */
$settleAuto = null;
if (!empty($_GET['settle']) && ctype_digit((string)$_GET['settle'])) {
    $settleStmt = $pdo->prepare("SELECT id, reference, total_amount, created_by, status FROM stock_orders WHERE id = ? LIMIT 1");
    $settleStmt->execute([(int)$_GET['settle']]);
    $settleRow = $settleStmt->fetch(PDO::FETCH_ASSOC);
    if ($settleRow && $settleRow['status'] === 'placed') {
        $isPrivileged = in_array($user['role'] ?? '', ['admin', 'manager'], true);
        if ($isPrivileged || (int)$settleRow['created_by'] === (int)$user['id']) {
            $settleAuto = [
                'id'    => (int)$settleRow['id'],
                'total' => (float)$settleRow['total_amount'],
                'ref'   => (string)$settleRow['reference'],
            ];
        }
    }
}

$csrf_token = generateCsrfToken();
$isFullScreen = ($user['role'] ?? '') === 'restaurant_staff';

/* Initial admin/manager "All Stations" snapshot rendered server-side so the
 * panel works even before the JS poller fires. Same query shape as ?ajax=stations. */
$adminStationsInit = ['counts' => ['kitchen' => ['open_total' => 0, 'pending' => 0, 'in_progress' => 0, 'ready' => 0], 'bar' => ['open_total' => 0, 'pending' => 0, 'in_progress' => 0, 'ready' => 0], 'coffee_bar' => ['open_total' => 0, 'pending' => 0, 'in_progress' => 0, 'ready' => 0]], 'open_tabs_all' => 0];
if (in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    try {
        $cs = $pdo->prepare("
            SELECT oi.station,
                   SUM(CASE WHEN oi.kds_status='pending' THEN 1 ELSE 0 END) AS pending,
                   SUM(CASE WHEN oi.kds_status='preparing' THEN 1 ELSE 0 END) AS in_progress,
                   SUM(CASE WHEN oi.kds_status='ready' THEN 1 ELSE 0 END) AS ready,
                   SUM(CASE WHEN oi.kds_status NOT IN ('served','void') THEN 1 ELSE 0 END) AS open_total
            FROM stock_order_items oi
            INNER JOIN stock_orders o ON o.id = oi.order_id
            WHERE oi.station = ? AND o.fired_at IS NOT NULL
                            AND o.fired_at >= ?
                            AND o.fired_at < ?
              AND o.status NOT IN ('voided','cancelled')
        ");
        foreach (['kitchen', 'bar', 'coffee_bar'] as $st) {
            $cs->execute([$st, $restaurantWindow['start_sql'], $restaurantWindow['end_sql']]);
            $r = $cs->fetch(PDO::FETCH_ASSOC) ?: [];
            $adminStationsInit['counts'][$st] = [
                'pending'     => (int)($r['pending']     ?? 0),
                'in_progress' => (int)($r['in_progress'] ?? 0),
                'ready'       => (int)($r['ready']       ?? 0),
                'open_total'  => (int)($r['open_total']  ?? 0),
            ];
        }
        $adminStationsInit['open_tabs_all'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_orders WHERE status='placed' AND order_type != 'room_service'")->fetchColumn();
    } catch (Throwable $e) {
        // Silent — JS poller will retry.
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>POS Till — <?php echo htmlspecialchars($siteName); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#8B7355">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RH POS">
    <style>
        html,
        body {
            background: #f7f0e8;
        }

        .pos-action-loader {
            position: fixed;
            inset: 0;
            z-index: 100000;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 15, 20, 0.38);
        }

        .pos-action-loader.show {
            display: flex;
        }

        .pos-action-loader__card {
            min-width: min(300px, calc(100vw - 40px));
            padding: 16px 18px;
            border-radius: 14px;
            background: rgba(253, 250, 245, 0.97);
            border: 1px solid rgba(138, 119, 95, 0.22);
            box-shadow: 0 16px 42px rgba(0, 0, 0, 0.28);
            text-align: center;
        }

        .pos-action-loader__spinner {
            width: 34px;
            height: 34px;
            margin: 0 auto 10px;
            border-radius: 50%;
            border: 3px solid rgba(138, 119, 95, 0.22);
            border-top-color: #8a775f;
            animation: pos-loader-boot-spin .7s linear infinite;
        }

        @keyframes pos-loader-boot-spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ── POS Camera Barcode Scanner ─────────────────────────────────── */
        #posCamScanOverlay {
            position: fixed; inset: 0; z-index: 99500;
            background: #000; flex-direction: column;
        }
        .pos-cam-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 16px; background: rgba(0,0,0,0.85); color: #fff;
            flex-shrink: 0;
        }
        .pos-cam-title { font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .pos-cam-close {
            background: rgba(255,255,255,0.12); border: none; color: #fff;
            width: 38px; height: 38px; border-radius: 50%; font-size: 16px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; transition: background .15s;
        }
        .pos-cam-close:active { background: rgba(255,255,255,0.25); }
        /* Canvas-based camera view: video frames are drawn to a <canvas> each rAF tick.
           Canvas has no OS-level hardware overlay, so position:absolute siblings render
           above it correctly on all Android Chrome versions. The <video> element stays in
           the DOM (hidden) so BarcodeDetector.detect(video) still works. */
        .pos-cam-view {
            flex: 1; min-height: 0;
            position: relative;
            background: #000;
            overflow: hidden;
        }
        #posCamVideo {
            /* Hidden — only exists for BarcodeDetector.detect() */
            position: absolute; width: 1px; height: 1px;
            opacity: 0; pointer-events: none; top: 0; left: 0;
        }
        #posCamCanvas {
            display: block; width: 100%; height: 100%;
        }
        .pos-cam-guide {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: min(72vw, 280px); height: min(72vw, 280px);
            pointer-events: none;
        }
        .pos-cam-corner {
            position: absolute; width: 30px; height: 30px;
            border-color: #4ade80; border-style: solid;
        }
        .pos-cam-corner.tl { top: 0; left: 0; border-width: 3px 0 0 3px; border-radius: 4px 0 0 0; }
        .pos-cam-corner.tr { top: 0; right: 0; border-width: 3px 3px 0 0; border-radius: 0 4px 0 0; }
        .pos-cam-corner.bl { bottom: 0; left: 0; border-width: 0 0 3px 3px; border-radius: 0 0 0 4px; }
        .pos-cam-corner.br { bottom: 0; right: 0; border-width: 0 3px 3px 0; border-radius: 0 0 4px 0; }
        .pos-cam-scan-line {
            position: absolute; left: 10px; right: 10px; height: 2px;
            background: rgba(74,222,128,0.75);
            box-shadow: 0 0 8px rgba(74,222,128,0.5);
            animation: pos-cam-line 2s ease-in-out infinite;
        }
        @keyframes pos-cam-line { 0%,100% { top: 12px; } 50% { top: calc(100% - 12px); } }
        /* ── Deal savings lines (cart + pay modal) ── */
        .cart-deals-block { padding: 6px 10px 2px; }
        .cart-deal-line { display: flex; align-items: center; gap: 7px; font-size: 12px; color: #065f46; background: #ecfdf5; border-radius: 7px; padding: 5px 10px; margin-bottom: 4px; }
        .cart-deal-line i { color: #10b981; flex-shrink: 0; }
        .cart-deal-line span:nth-child(3) { color: #6b7280; font-size: 11px; flex: 1; }
        .cdl-saving { margin-left: auto; font-weight: 700; color: #059669; white-space: nowrap; }
        .cart-deal-pending { display: flex; align-items: center; gap: 7px; font-size: 12px; color: #92400e; background: #fffbeb; border-radius: 7px; padding: 5px 10px; margin-bottom: 4px; border: 1px dashed #fcd34d; }
        .cart-deal-pending i { color: #d97706; flex-shrink: 0; }
        .fi-deal-pending { background: rgba(251,191,36,0.15) !important; color: #92400e !important; border-color: rgba(251,191,36,0.4) !important; }
        .pay-deal-line { display: flex; align-items: center; gap: 8px; padding: 3px 0; }
        .pay-deal-line span:first-child { flex: 1; }
        .pdl-saving { margin-left: auto; white-space: nowrap; }

        .pos-cam-footer {
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 16px; background: rgba(0,0,0,0.88); color: #fff;
            font-size: 12px; gap: 12px; flex-shrink: 0;
            margin-top: auto;
        }
        #posCamStatus { opacity: 0.8; flex: 1; font-size: 13px; }
        .pos-cam-keep-lbl {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; color: rgba(255,255,255,0.65); cursor: pointer; white-space: nowrap;
        }
        .pos-cam-keep-lbl input { cursor: pointer; accent-color: #4ade80; width: 15px; height: 15px; }
        .pos-cam-torch {
            background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);
            color: #fff; width: 38px; height: 38px; border-radius: 50%; font-size: 16px;
            cursor: pointer; display: none; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .pos-cam-torch.visible { display: flex; }
        .pos-cam-torch.active { background: #f59e0b; border-color: #f59e0b; color: #000; }
        /* Success flash on camera view */
        .pos-cam-view.found-flash { animation: pos-cam-flash .35s ease; }
        @keyframes pos-cam-flash { 0%,100% { filter: none; } 50% { filter: brightness(1.8); } }
        /* Mini cart panel inside scanner */
        .pos-cam-cart {
            background: rgba(10,12,18,0.96); border-top: 2px solid rgba(74,222,128,0.3);
            flex-shrink: 0; max-height: 0; overflow: hidden;
            transition: max-height .3s ease;
        }
        .pos-cam-cart.open { max-height: 260px; }
        .pos-cam-cart-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 9px 14px; cursor: pointer; user-select: none;
            color: rgba(255,255,255,0.9); font-size: 12px; font-weight: 600;
            border-bottom: 1px solid rgba(255,255,255,0.07);
        }
        .pos-cam-cart-head .cc-title { display: flex; align-items: center; gap: 7px; }
        .pos-cam-cart-head .cc-title i { color: #4ade80; font-size: 13px; }
        .pos-cam-cart-head .cc-total { color: #4ade80; font-size: 13px; font-weight: 800; }
        .pos-cam-cart-head .cc-chevron { font-size: 10px; color: rgba(255,255,255,0.4); transition: transform .25s; }
        .pos-cam-cart:not(.open) .cc-chevron { transform: rotate(180deg); }
        .pos-cam-cart-body { padding: 4px 0; max-height: 130px; overflow-y: auto; }
        .pos-cam-cart-row {
            display: flex; align-items: center;
            padding: 5px 10px 5px 14px; font-size: 12px; color: rgba(255,255,255,0.82);
            gap: 6px; border-bottom: 1px solid rgba(255,255,255,0.04);
        }
        .pos-cam-cart-row:last-child { border-bottom: none; }
        .pos-cam-cart-row .cc-name { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pos-cam-cart-row .cc-price { color: rgba(255,255,255,0.5); flex-shrink: 0; font-size: 11px; min-width: 60px; text-align: right; }
        /* Qty +/- controls */
        .cc-row-qty { display: flex; align-items: center; gap: 3px; flex-shrink: 0; }
        .cc-qty-btn { width: 20px; height: 20px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.07); color: #fff; font-size: 13px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; }
        .cc-qty-btn:active { background: rgba(255,255,255,0.2); }
        .cc-qty-val { min-width: 20px; text-align: center; font-weight: 700; color: #4ade80; font-size: 12px; }
        .cc-rm { background: none; border: none; color: rgba(255,255,255,0.25); font-size: 12px; cursor: pointer; padding: 2px 4px; line-height: 1; flex-shrink: 0; }
        .cc-rm:active { color: #f87171; }
        .pos-cam-cart-empty { padding: 10px 14px; font-size: 12px; color: rgba(255,255,255,0.35); text-align:center; }
        .cc-deal-line { display: flex; align-items: center; gap: 7px; font-size: 11px; color: #4ade80; padding: 5px 14px; background: rgba(74,222,128,0.07); border-top: 1px solid rgba(74,222,128,0.12); }
        .cc-deal-line i { flex-shrink: 0; }
        .cc-deal-saving { margin-left: auto; font-weight: 700; }
        /* Action bar */
        .pos-cam-cart-actions {
            display: flex; gap: 7px; padding: 8px 12px 10px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }
        .cc-act {
            flex: 1; padding: 8px 6px; border-radius: 9px;
            border: 1px solid rgba(255,255,255,0.14); background: rgba(255,255,255,0.07);
            color: rgba(255,255,255,0.85); font-size: 12px; font-weight: 600;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px;
        }
        .cc-act:active { background: rgba(255,255,255,0.16); }
        .cc-act.is-pay { background: #16a34a; border-color: #15803d; color: #fff; flex: 2; font-size: 13px; }
        .cc-act.is-pay:active { background: #15803d; }
        /* Feed item X button */
        .fi-rm { background: none; border: none; color: rgba(255,255,255,0.28); font-size: 13px; cursor: pointer; padding: 4px 2px; line-height: 1; flex-shrink: 0; }
        .fi-rm:active { color: #f87171; }
        .pos-cam-feed-item.is-removed { opacity: 0.3; text-decoration: line-through; }
        /* Barcode wedge status footer — lives as a natural .till-wrap grid row */
        #barcodeScanStrip {
            display: none; /* JS switches to flex */
            align-items: center; gap: 10px;
            background: #0d1117; color: #fff;
            font-size: 13px; font-weight: 600;
            padding: 10px 16px calc(10px + env(safe-area-inset-bottom));
            border-top: 1px solid rgba(74,222,128,0.22);
            pointer-events: none; flex-shrink: 0; width: 100%;
        }
        #barcodeScanStrip .fas { color: #4ade80; }
        #barcodeScanLast { margin-left: auto; opacity: 0.65; font-weight: 400; font-size: 12px; max-width: 48%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        /* Scanned items live feed (Facebook Live comment style) */
        .pos-cam-feed {
            position: absolute; bottom: 0; left: 0; right: 0;
            display: flex; flex-direction: column; justify-content: flex-end;
            gap: 6px; padding: 10px 12px 14px;
            pointer-events: none; /* pass touches through to canvas */
            overflow: hidden;
            max-height: 80%;
            z-index: 5;
        }
        .pos-cam-feed-item {
            display: flex; align-items: center; gap: 10px;
            background: rgba(6,10,16,0.88);
            border: 1.5px solid rgba(74,222,128,0.5);
            border-radius: 12px;
            padding: 9px 12px; color: #fff;
            animation: pos-feed-in .22s cubic-bezier(.22,1,.36,1);
            flex-shrink: 0; width: 100%; box-sizing: border-box;
            box-shadow: 0 4px 14px rgba(0,0,0,0.6);
            pointer-events: auto; /* re-enable on cards so X button works */
        }
        @keyframes pos-feed-in { from { opacity:0; transform: translateY(10px) scale(.95); } to { opacity:1; transform: none; } }
        @keyframes pos-feed-bump { 0%,100% { transform:none; } 30% { transform: scale(1.04); box-shadow: 0 0 0 2px #4ade80; } 60% { transform: scale(.98); } }
        .pos-cam-feed-item.feed-bump { animation: pos-feed-bump .35s cubic-bezier(.22,1,.36,1); }
        .pos-cam-feed-item .fi-icon { color: #4ade80; font-size: 18px; flex-shrink: 0; }
        .fi-body { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
        .fi-body .fi-name { font-weight: 700; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fi-body .fi-qty-label { font-size: 11px; color: rgba(255,255,255,0.5); }
        .fi-body .fi-cart-total { font-size: 11px; color: #4ade80; font-weight: 600; }
        .fi-line-total { flex-shrink: 0; color: #4ade80; font-weight: 800; font-size: 16px; white-space: nowrap; padding-left: 4px; }
        .fi-deals { display: flex; flex-direction: column; gap: 3px; margin-top: 4px; }
        .fi-deal-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 10px; font-weight: 700;
                         background: rgba(74,222,128,0.15); color: #4ade80; border-radius: 5px; padding: 3px 7px;
                         border: 1px solid rgba(74,222,128,0.3); line-height: 1.3; }
        .fi-deal-badge i { font-size: 9px; flex-shrink: 0; }
        .fi-deal-badge strong { color: #86efac; }
        .pos-cam-feed-item.is-unknown { border-color: rgba(248,113,113,0.55); }
        .fi-icon--warn { color: #f87171 !important; }
        /* Scan button in mobile bar */
        .pos-mobile-action.is-scan { color: #4ade80; }
        .pos-mobile-action.is-scan.is-active { background: rgba(74,222,128,0.15); }
    </style>
    <link rel="manifest" href="manifest.php">
    <link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="css/pos-overrides.css?v=<?php echo @filemtime(__DIR__ . '/css/pos-overrides.css'); ?>">
    <script src="js/station-sounds.js"></script>
    <script>
    /* Load BarcodeDetector polyfill for Firefox / Safari / older browsers.
       Dynamic import lets us catch failures so Chrome Android (native) keeps working. */
    (async function () {
        if (!('BarcodeDetector' in window)) {
            try {
                const m = await import('https://unpkg.com/@undecaf/barcode-detector-polyfill@0.9.23/dist/main.js');
                window.BarcodeDetector = m.BarcodeDetectorPolyfill;
            } catch (e) {
                console.warn('[POS] BarcodeDetector polyfill failed to load. Native support only.');
            }
        }
        window._posBarcodeDetectorReady = true;
    }());
    </script>
</head>

<body class="pos-screen<?php echo in_array($user['role'] ?? '', ['admin', 'manager'], true) ? ' pos-admin' : ''; ?>">
    <div class="pos-action-loader" id="posActionLoader" role="status" aria-live="polite" aria-label="Loading">
        <div class="pos-action-loader__card">
            <div class="pos-action-loader__brand">
                <i class="fas fa-hotel pos-action-loader__icon" aria-hidden="true"></i>
                <span class="pos-action-loader__hotel"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="pos-action-loader__divider"></div>
            <div class="pos-action-loader__spinner" aria-hidden="true"></div>
            <p class="pos-action-loader__title" id="posActionLoaderTitle">Loading…</p>
            <p class="pos-action-loader__text" id="posActionLoaderText">Please wait.</p>
        </div>
    </div>
    <script>
        (function() {
            const key = 'rh_pos_nav_loader';
            try {
                const raw = sessionStorage.getItem(key);
                if (!raw) return;
                const state = JSON.parse(raw);
                if (!state || typeof state !== 'object' || Number(state.expiresAt || 0) <= Date.now()) {
                    sessionStorage.removeItem(key);
                    return;
                }
                window.__rhPosNavLoaderState = {
                    title: typeof state.title === 'string' ? state.title : '',
                    text: typeof state.text === 'string' ? state.text : '',
                    subtle: state.subtle !== false
                };
                sessionStorage.removeItem(key);

                const loader = document.getElementById('posActionLoader');
                if (!loader) return;
                const titleEl = document.getElementById('posActionLoaderTitle');
                const textEl = document.getElementById('posActionLoaderText');
                if (titleEl && window.__rhPosNavLoaderState.title) {
                    titleEl.textContent = window.__rhPosNavLoaderState.title;
                }
                if (textEl && window.__rhPosNavLoaderState.text) {
                    textEl.textContent = window.__rhPosNavLoaderState.text;
                }
                if (window.__rhPosNavLoaderState.subtle) {
                    loader.classList.add('pos-action-loader--subtle');
                }
                loader.classList.add('show');
            } catch (_) {
                try {
                    sessionStorage.removeItem(key);
                } catch (__unused) {
                    // Ignore storage cleanup errors.
                }
            }
        })();
    </script>
    <div class="till-wrap">
        <div class="till-bar">

            <!-- ROW 1: Brand | Cashier | Actions | Sign-out -->
            <div class="tb-row1">

                <!-- Brand + Cashier identity block -->
                <div class="brand">
                    <span class="brand-name"><?php echo htmlspecialchars($siteName); ?></span>
                    <span class="brand-label">Point of Sale</span>
                    <span class="brand-cashier">
                        <i class="fas fa-user-circle"></i>
                        <span class="brand-cashier-name"><?php echo htmlspecialchars($user['full_name']); ?></span>
                        <span class="brand-cashier-sep">·</span>
                        <?php echo htmlspecialchars(ucfirst($user['role'] ?? 'Cashier')); ?>
                    </span>
                </div>

                <!-- Mobile menu button -->
                <button type="button" class="pos-mobile-menu-btn" id="posMobileMenuBtn" onclick="openPosMobileMenu()" aria-label="Open POS menu" aria-controls="posMobileMenu" aria-expanded="false" title="Open menu">
                    <i class="fas fa-bars"></i>
                    <span>Menu</span>
                    <span class="mobile-menu-badge" id="mobileMenuBadge"></span>
                </button>

                <!-- Action buttons -->
                <div class="tb-actions">
                    <!-- Till ops -->
                    <button class="recent-toggle" onclick="toggleRecent()" data-help="Recent orders|Last 10 orders you rang up."><i class="fas fa-receipt"></i> Recent</button>
                    <button class="recent-toggle" onclick="openTabsTray()" data-help="Open tabs|Unpaid kitchen orders."><i class="fas fa-utensils"></i> Tabs <span id="tabBadge" <?php echo empty($openTabs) ? ' style="display:none;"' : ''; ?>><?php echo count($openTabs); ?></span></button>
                    <button class="recent-toggle" onclick="openStationNoteModal()" data-help="Station note|Quick note to Kitchen/Bar/Coffee."><i class="fas fa-paper-plane"></i> Note</button>
                    <button class="recent-toggle" onclick="openCloseShift()" data-help="Close shift (Z-report)|End-of-shift cash count."><i class="fas fa-cash-register"></i> Close Shift</button>

                    <?php if ($isManagerOrAdmin): ?>
                        <div class="tb-sep"></div>
                        <?php /* The three per-station links (Kitchen/Bar/Coffee) collapsed into this
                                  one control: the Stations tray it opens already shows each station's
                                  live counts AND carries direct links to those boards, so four
                                  toolbar buttons were showing what one plus a tray already covers.
                                  The badge sums all stations so nothing is lost at a glance. */ ?>
                        <button class="recent-toggle" onclick="openStationsTray()" data-help="Stations|Live Kitchen, Bar and Coffee boards with ticket counts, and links to open each screen."><i class="fas fa-layer-group"></i> Stations<span id="stationsBadge" style="<?php $tot = ($adminStationsInit['counts']['kitchen']['open_total'] ?? 0) + ($adminStationsInit['counts']['bar']['open_total'] ?? 0) + ($adminStationsInit['counts']['coffee_bar']['open_total'] ?? 0);
                                                                                                                                                                echo $tot > 0 ? '' : 'display:none;'; ?>"><?php echo $tot; ?></span></button>
                        <?php /* Hidden badge targets kept so the live stations poller can keep
                                  updating per-station counts without null checks. */ ?>
                        <span id="kitchenBadge" hidden></span><span id="barBadge" hidden></span><span id="coffeeBadge" hidden></span>
                    <?php endif; ?>
                    <?php if ($posCanToggle86): ?>
                        <button class="recent-toggle" id="eightySixModeBtn" onclick="toggle86Mode()" data-help="86 Mode|Toggle item availability. When active, click any item to mark it as 86'd (unavailable) or to re-enable it. All sessions reload the menu."><i class="fas fa-ban"></i> 86</button>
                    <?php endif; ?>
                    <button type="button" class="rh-help-toggle recent-toggle" data-inline="1" id="rhHelpToggle" aria-label="Toggle help tooltips" data-help="Help mode|Turn tooltip hints on or off for POS actions."><span class="dot"></span><i class="fas fa-question-circle"></i> <span id="rhHelpLabel">Help</span></button>

                    <div class="tb-sep"></div>
                    <?php /* Everything below is used once a shift or less. Kept one tap away rather
                              than occupying the bar staff scan all service. */ ?>
                    <div class="tb-more">
                        <button type="button" class="recent-toggle tb-more__btn" id="posMoreBtn" onclick="togglePosMoreMenu(event)" aria-expanded="false" aria-haspopup="true"><i class="fas fa-ellipsis"></i> More</button>
                        <div class="tb-more__menu" id="posMoreMenu" hidden>
                            <?php if ($posCanFloat): ?>
                            <button type="button" onclick="closePosMoreMenu(); openFloatModal();"><i class="fas fa-coins"></i> Opening float</button>
                            <?php endif; ?>
                            <?php if ($isManagerOrAdmin && moduleEnabled('stock')): ?>
                            <a href="stock-orders.php"><i class="fas fa-list"></i> All orders</a>
                            <?php endif; ?>
                            <button type="button" onclick="closePosMoreMenu(); RHSounds.openSettings();"><i class="fas fa-sliders"></i> Sound settings</button>
                            <a href="../docs/guides/01-pos-till.html" target="_blank" rel="noopener"><i class="fas fa-book-open"></i> POS guide</a>
                            <?php if (!$isFullScreen): ?><a href="dashboard.php"><i class="fas fa-arrow-left"></i> Admin dashboard</a><?php endif; ?>
                        </div>
                    </div>
                    <a class="logout" href="logout.php"><i class="fas fa-sign-out-alt"></i> Sign out</a>
                </div>

            </div>

            <!-- ROW 2: Shift stats (scrollable) -->
            <div class="tb-row2">
                <span class="stat"><strong id="tbStatOrders"><?php echo (int)($shift['orders_today'] ?? 0); ?></strong><span class="stat-label">Orders</span></span>
                <span class="stat"><strong id="tbStatRevenue"><?php echo $currency_symbol . ' ' . number_format((float)($shift['revenue_today'] ?? 0), 0); ?></strong><span class="stat-label">Revenue</span></span>
                <span class="stat"><strong id="tbStatCash"><?php echo $currency_symbol . ' ' . number_format((float)($shift['cash_today'] ?? 0), 0); ?></strong><span class="stat-label">Cash</span></span>
                <span class="stat"><strong id="tbStatMobile"><?php echo $currency_symbol . ' ' . number_format((float)($shift['mobile_today'] ?? 0), 0); ?></strong><span class="stat-label">Mobile</span></span>
                <span class="stat"><strong id="tbStatCard"><?php echo $currency_symbol . ' ' . number_format((float)($shift['card_today'] ?? 0), 0); ?></strong><span class="stat-label">Card</span></span>
            </div>

        </div>

        <div class="till-grid">
            <?php /* Room Service only exists for a hotel — it charges to a guest
                     room folio. Non-hotel presets (bar, gym, retail, supermarket)
                     have no rooms, so the whole Restaurant/Room-Service toggle is
                     dropped and the till stays in single-menu mode. */ ?>
            <?php $posRoomServiceOn = moduleEnabled('bookings') && moduleEnabled('station_room_service'); ?>
            <?php if ($posRoomServiceOn): ?>
            <!-- Menu mode toggle: flicks the visible menu between Restaurant (POS) and Room Service.
             Selecting Room Service also auto-sets the order type so the location field expects a room. -->
            <div class="menu-mode" role="tablist" aria-label="Menu mode" data-active-mode="restaurant">
                <button type="button" class="menu-mode-btn is-active" data-mode="restaurant" role="tab" aria-selected="true" onclick="setMenuMode('restaurant')">
                    <i class="fas fa-utensils"></i> <span>Restaurant</span>
                </button>
                <button type="button" class="menu-mode-btn" data-mode="room_service" role="tab" aria-selected="false" onclick="setMenuMode('room_service')">
                    <i class="fas fa-bed"></i> <span>Room Service</span>
                </button>
            </div>
            <?php endif; ?>

            <!-- Categories -->
            <div class="cats-wrap" id="catsWrap">
                <button type="button" class="cat-dropdown-trigger" id="catDropdownTrigger" onclick="toggleCatDropdown()" aria-haspopup="listbox" aria-expanded="false">
                    <i class="fas fa-th-large"></i>
                    <span id="catDropdownLabel">All Items</span>
                    <i class="fas fa-chevron-down cat-dropdown-chevron"></i>
                </button>
                <div class="cats" id="cats" role="listbox" aria-label="Menu categories">
                    <?php foreach ($categories as $key => $cat): ?>
                        <button class="cat-btn<?php echo $key === '__ALL__' ? ' active' : ''; ?>" data-cat="<?php echo htmlspecialchars($key, ENT_QUOTES); ?>" onclick="selectCat(this)" role="option" aria-selected="<?php echo $key === '__ALL__' ? 'true' : 'false'; ?>">
                            <?php echo htmlspecialchars($cat['label']); ?>
                            <span class="count"><?php echo (int)$cat['count']; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Menu -->
            <div class="menu">
                <div class="toolbar">
                    <input type="text" id="search" placeholder="Search menu…" oninput="renderMenu()">
                    <button onclick="document.getElementById('search').value='';renderMenu()"><i class="fas fa-times"></i></button>
                    <?php if ($posCanToggle86): ?>
                    <button id="barcodeToggleBtn" onclick="posToggleBarcodeScanner()" title="Toggle barcode scanner" style="display:none;" class="barcode-toggle-btn">
                        <i class="fas fa-barcode"></i>
                    </button>
                    <?php endif; ?>
                    <button onclick="posCamScanOpen()" title="Camera barcode scanner" class="barcode-toggle-btn" style="color:#22c55e;" aria-label="Open camera scanner">
                        <i class="fas fa-camera"></i>
                    </button>
                </div>
                <div class="grid" id="grid"></div>
            </div>

            <!-- Cart -->
            <div class="cart" id="mainCart">
                <div class="cart-head">
                    <h3><i class="fas fa-shopping-cart"></i> Order</h3>
                    <div style="display:flex; gap:10px;">
                        <button class="clear" onclick="clearCart()"><i class="fas fa-trash"></i> Clear</button>
                        <button class="cart-close-btn" onclick="toggleCartDrawer()" data-help="Close order panel|Hide the current order panel so the menu has more room. Tap the floating cart button to bring it back."><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="cart-lines" id="cart-lines">
                    <p style="color:#6c757d; text-align:center; padding:30px 0; font-size:13px;">Tap items to add to the order.</p>
                </div>
                <div class="cart-foot">
                    <!-- Service context: order type + location + customer name. These inputs live OUTSIDE the
                     pay form (form="payForm" attribute) so they submit with both Fire-Order and Pay flows. -->
                    <div class="service-ctx">
                        <div class="ctx-chips" role="group" aria-label="Service type">
                            <button type="button" class="ctx-chip is-active" data-type="walk_in" onclick="setServiceType('walk_in')"><i class="fas fa-walking"></i><span>Walk-in</span></button>
                            <?php if (isRestaurantEnabled()): ?>
                            <button type="button" class="ctx-chip" data-type="dine_in" onclick="setServiceType('dine_in')"><i class="fas fa-utensils"></i><span>Dine-in</span></button>
                            <button type="button" class="ctx-chip" data-type="takeaway" onclick="setServiceType('takeaway')"><i class="fas fa-shopping-bag"></i><span>Takeaway</span></button>
                            <?php endif; ?>
                            <?php if (moduleEnabled('bookings') && moduleEnabled('station_room_service')): ?>
                            <button type="button" class="ctx-chip" data-type="room_service" onclick="setServiceType('room_service')"><i class="fas fa-bed"></i><span>Room</span></button>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" id="ctxOrderType" name="order_type" form="payForm" value="walk_in">
                        <div class="ctx-fields">
                            <input type="hidden" id="ctxLocation" name="table_number" form="payForm" value="">
                            <select id="ctxTableSelect" onchange="syncServiceLocation()" style="display:none;">
                                <option value="">Select table...</option>
                                <?php foreach ($restaurantTables as $table): ?>
                                    <?php
                                    $tableNumber = (string)$table['table_number'];
                                    $tableLock = $activeLocationLocks['tables'][$tableNumber] ?? null;
                                    $tableMeta = $table['capacity'] !== null ? ' · seats ' . (int)$table['capacity'] : '';
                                    ?>
                                    <option value="<?php echo htmlspecialchars($tableNumber); ?>" data-capacity="<?php echo $table['capacity'] !== null ? (int)$table['capacity'] : ''; ?>" <?php echo $tableLock ? 'disabled' : ''; ?>>
                                        Table <?php echo htmlspecialchars($tableNumber . $tableMeta); ?><?php echo $tableLock ? ' · busy ' . htmlspecialchars((string)$tableLock['reference']) : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select id="ctxRoomSelect" onchange="syncServiceLocation()" style="display:none;">
                                <option value="">Select checked-in room...</option>
                                <?php foreach ($checkedInRooms as $room): ?>
                                    <?php
                                    $roomNumber = (string)$room['room_number'];
                                    $roomLock = $activeLocationLocks['rooms'][$roomNumber] ?? null;
                                    $guest = trim((string)($room['guest_name'] ?? ''));
                                    ?>
                                    <option value="<?php echo htmlspecialchars($roomNumber); ?>" data-booking="<?php echo (int)$room['booking_id']; ?>" <?php echo $roomLock ? 'disabled' : ''; ?>>
                                        Room <?php echo htmlspecialchars($roomNumber); ?><?php echo $guest !== '' ? ' · ' . htmlspecialchars($guest) : ''; ?><?php echo $roomLock ? ' · busy ' . htmlspecialchars((string)$roomLock['reference']) : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="ctx-location-hint" id="ctxLocationHint"></div>
                            <input type="text" id="ctxCustomer" name="customer_name" form="payForm" placeholder="Guest name (optional)" autocomplete="off">
                            <div class="ctx-covers-row" style="display:flex; align-items:center; gap:8px; margin-top:8px;">
                                <label for="ctxCovers" style="font-size:12px; color:#6c757d; white-space:nowrap;"><i class="fas fa-users" style="margin-right:4px;"></i>Covers</label>
                                <input type="number" id="ctxCovers" name="covers" form="payForm" min="0" max="99" step="1" placeholder="0" autocomplete="off" style="width:70px; padding:6px 8px; border:1px solid #d6d8db; border-radius:6px; font-size:13px;">
                                <span style="font-size:11px; color:#9ca3af;">guests on this tab (optional)</span>
                            </div>
                        </div>
                    </div>
                    <!-- Active-tab banner: shown when adding a round to an existing open tab -->
                    <div id="activeTabBanner" style="display:none; align-items:center; gap:10px; background:#fff7ed; border:1px solid #fdba74; border-radius:8px; padding:9px 12px; margin-bottom:10px;">
                        <i class="fas fa-layer-group" style="color:#ea580c;"></i>
                        <div style="flex:1; min-width:0; font-size:12.5px; color:#9a3412; line-height:1.3;">
                            Adding to <strong id="activeTabBannerRef">TAB</strong>
                            <span style="display:block; font-size:11px; color:#c2630f;">Next Fire appends to this tab · current total <span id="activeTabBannerTotal"></span></span>
                        </div>
                        <button type="button" onclick="clearActiveTab(true)" title="Stop adding to this tab" style="flex-shrink:0; background:transparent; border:none; color:#c2410c; font-size:16px; cursor:pointer; padding:4px;"><i class="fas fa-times-circle"></i></button>
                    </div>
                    <div id="cart-deal-lines"></div>
                    <div class="total-row"><span>Total</span><span id="total"><?php echo $currency_symbol; ?> 0.00</span></div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                        <button class="park-btn" id="parkBtn" onclick="parkOrder()" disabled data-help="Fire order|Sends the order to the relevant station (Kitchen, Bar, or both) and opens it as a TAB (no payment yet). Stock is deducted immediately. Pay later from the Tabs button.
Use for dine-in: staff can prepare while the customer is still seated."><span id="parkBtnLabel"><i class="fas fa-fire"></i> Fire Order</span></button>
                        <button class="pay-btn" id="payBtn" onclick="openPayModal()" disabled data-help="Pay now|Take payment AND place the order in one step. Use for walk-in / takeaway / quick-service. The kitchen still receives the ticket automatically."><i class="fas fa-credit-card ico"></i> Pay</button>
                    </div>
                    <div style="font-size:11px; color:#6c757d; margin-top:8px; text-align:center;" id="parkHint">Fire Order = open tab (pay later)</div>
                    <button type="button" id="repeatRoundBtn" onclick="repeatLastRound()" style="display:none; width:100%; margin-top:8px; padding:9px; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px; font-weight:600; color:#334155; cursor:pointer;" data-help="Repeat last round|Re-loads the items from the last order you fired or paid so you can quickly send the same round again."><i class="fas fa-rotate-right"></i> Repeat last round (<span id="repeatRoundCount">0</span>)</button>
                </div>
            </div>

            <button class="mobile-cart-toggle" onclick="toggleCartDrawer()">
                <i class="fas fa-shopping-cart"></i>
                <span class="badge" id="cartBadge" style="display:none;">0</span>
            </button>
        </div>
        <!-- Barcode wedge scanner status bar — sits as a natural footer row inside .till-wrap grid -->
        <div id="barcodeScanStrip" style="display:none;">
            <i class="fas fa-barcode"></i>
            <span>Barcode scanner active — scan an item to add it to the cart</span>
            <span id="barcodeScanLast"></span>
        </div>
    </div>

    <!-- Recent dropdown -->
    <div class="recent-list" id="recentList">
        <div class="recent-list__header">
            <span>Recent Orders</span>
            <button type="button" onclick="toggleRecent()" aria-label="Close recent orders">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <?php if (empty($recent)): ?>
            <div style="padding:18px; color:#6c757d; text-align:center;">No orders yet today.</div>
        <?php else: ?>
            <?php foreach ($recent as $r):
                $rDiscount = (float)($r['discount_amount'] ?? 0);
                $rStatus = $r['status'] ?? '';
                $rStatusColor = match($rStatus) {
                    'paid'      => '#155724',
                    'refunded'  => '#6f42c1',
                    'cancelled', 'voided' => '#c82333',
                    default     => '#6c757d',
                };
            ?>
                <div class="r" style="display:flex; align-items:center; gap:8px; justify-content:space-between; cursor:default;">
                    <a href="stock-receipt.php?id=<?php echo (int)$r['id']; ?>" target="_blank" style="flex:1; text-decoration:none; color:inherit;">
                        <div class="ref"><?php echo htmlspecialchars($r['reference']); ?> · <?php echo $currency_symbol . ' ' . number_format((float)$r['total_amount'], 2); ?><?php if ($rDiscount > 0): ?> <span style="font-size:10px;color:#856404;background:#fffbeb;padding:1px 5px;border-radius:4px;">-<?php echo number_format($rDiscount,2); ?></span><?php endif; ?></div>
                        <div style="color:#6c757d; font-size:11px;"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $r['payment_method'] ?? '—'))); ?> · <?php echo htmlspecialchars(date('H:i', strtotime($r['created_at']))); ?> · <span style="color:<?php echo $rStatusColor; ?>; font-weight:600;"><?php echo htmlspecialchars($rStatus); ?></span></div>
                    </a>
                    <?php if ($posCanRefund && $rStatus === 'paid'): ?>
                        <button type="button" onclick="openRefundModal(<?php echo (int)$r['id']; ?>, <?php echo json_encode((string)$r['reference']); ?>, <?php echo (float)$r['total_amount']; ?>)" style="flex-shrink:0; padding:5px 9px; background:#6f42c1; color:#fff; border:none; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap;" title="Process refund"><i class="fas fa-rotate-left"></i> Refund</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Pay modal -->
    <div class="overlay modal-overlay" data-modal id="payOverlay">
        <div class="modal modal-content">
            <div class="modal-head modal-header">
                <h3><i class="fas fa-credit-card"></i> Take payment</h3><button class="close modal-close" onclick="closePayModal()">&times;</button>
            </div>
            <form method="POST" id="payForm" data-offline-queue="1">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div id="payHiddenItems"></div>
                <div class="modal-body">
                    <div style="font-size:32px; font-weight:700; text-align:center; margin-bottom:14px;">
                        <span id="payTotal"><?php echo $currency_symbol; ?> 0.00</span>
                    </div>

                    <div id="payDealLines" style="display:none; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:9px 14px; margin-bottom:12px; font-size:13px; color:#065f46;"></div>
                    <div id="payKitchenWarning" style="display:none; align-items:flex-start; gap:10px; background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:13px; color:#856404; line-height:1.4;">
                        <i class="fas fa-exclamation-triangle" style="flex-shrink:0; margin-top:1px;"></i>
                        <span id="payKitchenWarningText"></span>
                    </div>
                    <div id="paySvcSummary" style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:8px 12px; margin-bottom:12px; font-size:12.5px; color:#475569; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-info-circle" style="color:#8B7355;"></i>
                        <span id="paySvcSummaryText">Walk-in</span>
                        <button type="button" onclick="closePayModal()" style="margin-left:auto; background:transparent; border:none; color:#8B7355; font-size:12px; cursor:pointer; text-decoration:underline;">Change</button>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                        <div><label>Email (for receipt)</label><input type="email" name="customer_email"></div>
                        <div><label>Phone (for WhatsApp)</label><input type="text" name="customer_phone"></div>
                    </div>
                    <label>Notes</label>
                    <input type="text" name="notes" placeholder="Allergies, special requests…">

                    <!-- Discount section -->
                    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;margin-bottom:10px;<?php echo $posCanDiscount ? '' : 'display:none;'; ?>">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                            <span style="font-size:13px;font-weight:600;color:#374151;white-space:nowrap;"><i class="fas fa-tag" style="color:#d97706;margin-right:4px;"></i>Discount</span>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;" id="payDiscountPresets">
                                <button type="button" class="discount-preset-btn active" data-pct="0" onclick="setDiscountPct(this,0)">None</button>
                                <button type="button" class="discount-preset-btn" data-pct="5" onclick="setDiscountPct(this,5)">5%</button>
                                <button type="button" class="discount-preset-btn" data-pct="10" onclick="setDiscountPct(this,10)">10%</button>
                                <button type="button" class="discount-preset-btn" data-pct="15" onclick="setDiscountPct(this,15)">15%</button>
                                <button type="button" class="discount-preset-btn" data-pct="25" onclick="setDiscountPct(this,25)">25%</button>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div>
                                <label style="font-size:11px;font-weight:600;color:#6c757d;margin-bottom:3px;display:block;">Amount (<?php echo $currency_symbol; ?>)</label>
                                <input type="number" step="0.01" min="0" id="payDiscountAmt" placeholder="0.00" oninput="onDiscountAmtInput()" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;">
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:600;color:#6c757d;margin-bottom:3px;display:block;">Reason</label>
                                <select id="payDiscountReason" onchange="syncPayDiscountToForm()" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;">
                                    <option value="">Select…</option>
                                    <option>Staff discount</option>
                                    <option>Happy hour</option>
                                    <option>Manager override</option>
                                    <option>Complimentary</option>
                                    <option>Loyalty discount</option>
                                    <option>Event promo</option>
                                </select>
                            </div>
                        </div>
                        <input type="hidden" name="discount_amount" id="payDiscountAmtHidden" value="0">
                        <input type="hidden" name="discount_reason" id="payDiscountReasonHidden" value="">
                        <input type="hidden" name="deal_discount_amount" id="payDealDiscountAmtHidden" value="0">
                        <input type="hidden" name="deal_ids" id="payDealIdsHidden" value="">
                    </div>

                    <label>Payment method</label>
                    <div class="pay-method-grid">
                        <button type="button" data-method="cash" onclick="setMethod(this)"><i class="fas fa-money-bill-wave"></i> Cash</button>
                        <button type="button" data-method="mobile_money" onclick="setMethod(this)"><i class="fas fa-mobile-alt"></i> Mobile Money</button>
                        <button type="button" data-method="card_manual" onclick="setMethod(this)"><i class="fas fa-credit-card"></i> Card (manual)</button>
                        <button type="button" class="disabled" data-method="card_pos" onclick="showCardPosUnavailable()"><i class="fas fa-microchip"></i> Card POS<br><small>(soon)</small></button>
                    </div>
                    <input type="hidden" name="payment_method" id="payment_method" value="">

                    <div id="ext-cash" style="display:none;">
                        <label>Tendered (<?php echo $currency_symbol; ?>)</label>
                        <input type="number" step="0.01" min="0" name="tendered_amount" id="tendered" oninput="updChange()">
                        <div class="change-banner">Change: <span id="changeOut"><?php echo $currency_symbol; ?> 0.00</span></div>
                        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:6px; margin-top:8px;">
                            <button type="button" onclick="quickTend(500)" style="padding:10px;border:1px solid #d6d8db;background:#fff;border-radius:6px;cursor:pointer;">+500</button>
                            <button type="button" onclick="quickTend(1000)" style="padding:10px;border:1px solid #d6d8db;background:#fff;border-radius:6px;cursor:pointer;">+1k</button>
                            <button type="button" onclick="quickTend(5000)" style="padding:10px;border:1px solid #d6d8db;background:#fff;border-radius:6px;cursor:pointer;">+5k</button>
                            <button type="button" onclick="quickTendExact()" style="padding:10px;border:1px solid #28a745;background:#e9f5ee;color:#155724;border-radius:6px;cursor:pointer;font-weight:600;">Exact</button>
                        </div>
                    </div>

                    <div id="ext-mobile_money" style="display:none;">
                        <label>Provider</label>
                        <select name="mobile_wallet_provider">
                            <option value="">Select…</option>
                            <option>Airtel Money</option>
                            <option>TNM Mpamba</option>
                            <option>Mo626</option>
                            <option>Other</option>
                        </select>
                        <label>Transaction reference</label>
                        <input type="text" name="mobile_wallet_reference" placeholder="MP25.0123.A4567">
                    </div>

                    <div id="ext-card_manual" style="display:none;">
                        <label>Card last 4 digits</label>
                        <input type="text" name="card_last4" maxlength="4" pattern="\d{4}" placeholder="1234">
                        <label>Authorisation code (from slip)</label>
                        <input type="text" name="card_auth_code" maxlength="50">
                    </div>
                </div>
                <div class="modal-foot modal-footer">
                    <button type="button" class="btn-cancel" onclick="closePayModal()">Cancel</button>
                    <button type="submit" class="btn-confirm" id="confirmBtn" disabled>Confirm payment</button>
                </div>
            </form>
        </div>
    </div>

    <div class="overlay modal-overlay" data-modal id="posMobileQuickViewOverlay">
        <div class="modal modal-content pos-mobile-quick-view-modal">
            <div class="modal-head modal-header pos-mobile-quick-view-modal__head">
                <div class="pos-mobile-quick-view-modal__heading">
                    <h3 id="posMobileQuickViewTitle"><i class="fas fa-layer-group"></i> Quick View</h3>
                    <p class="pos-mobile-quick-view-modal__subtitle" id="posMobileQuickViewSubtitle" hidden></p>
                </div>
                <button class="close modal-close" type="button" onclick="closePosMobileQuickView()" aria-label="Close quick view">&times;</button>
            </div>
            <div class="modal-body" id="posMobileQuickViewBody"></div>
        </div>
    </div>

    <!-- Station-closed fire confirmation overlay -->
    <div class="overlay modal-overlay" data-modal id="kitchenClosedOverlay">
        <div class="modal modal-content" style="max-width:400px; text-align:center; padding:0;">
            <div style="padding:28px 24px 20px;">
                <div style="font-size:44px; margin-bottom:10px; line-height:1;" id="stationClosedEmoji">⚠️</div>
                <h3 style="margin:0 0 8px; font-size:18px; font-weight:700; color:#c82333;" id="stationClosedTitle">Station Closed</h3>
                <p style="font-size:13px; color:#888; margin:0 0 0;" id="stationClosedMsg">This station is currently closed. The ticket will sit unseen until it reopens. Proceed?</p>
            </div>
            <div style="display:flex; gap:10px; padding:0 24px 24px;">
                <button onclick="cancelKitchenFire()" style="flex:1; padding:14px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; font-family:inherit;">Cancel</button>
                <button onclick="confirmKitchenFire()" style="flex:1; padding:14px; background:#c82333; color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit;"><i class="fas fa-fire"></i> Fire Anyway</button>
            </div>
        </div>
    </div>

    <!-- Success modal -->
    <?php if ($lastOrderRef): ?>
        <div class="overlay modal-overlay show" data-modal id="successOverlay" data-dismissible="1">
            <div class="success-modal modal-content" style="position:relative;">
                <button type="button" class="close modal-close" aria-label="Close" onclick="closeSuccess()" data-help="Close|Dismiss this dialog and start a new order."
                    style="position:absolute; top:10px; right:14px; background:transparent; border:none; font-size:26px; line-height:1; cursor:pointer; color:#6c757d;">&times;</button>
                <div class="icon"><i class="fas fa-<?php echo $justParked ? 'fire' : 'check-circle'; ?>"></i></div>
                <h2><?php echo $justParked ? 'Order Fired' : 'Payment received'; ?></h2>
                <div class="ref"><?php echo htmlspecialchars($lastOrderRef); ?></div>
                <div style="font-size:14px; color:#155724;"><?php echo htmlspecialchars($message); ?></div>
                <div class="actions">
                    <a class="a-print" href="stock-receipt.php?id=<?php echo (int)$lastOrderId; ?>&print=1&kot=<?php echo $justParked ? '1' : '0'; ?>" target="_blank" data-help="<?php echo $justParked ? 'Print KOT|Kitchen Order Ticket — the chef-side slip listing what to cook. Opens in a new tab so you can keep the till open.' : 'Print receipt|Customer-facing slip with totals, VAT and payment method. Opens in a new tab.'; ?>"><i class="fas fa-print"></i> <?php echo $justParked ? 'Print KOT' : 'Print'; ?></a>
                    <?php if (!$justParked): ?>
                        <button class="a-receipt" onclick="openPosPageModal('stock-receipt.php?id=<?php echo (int)$lastOrderId; ?>','Send Receipt','fas fa-envelope')" data-help="Email / WhatsApp|Send the receipt to the customer's email or WhatsApp number."><i class="fas fa-envelope"></i> Email / WhatsApp</button>
                    <?php else: ?>
                        <button class="a-receipt" onclick="closeSuccess(); openTabsTray();" data-help="View open tabs|Jump to the list of unpaid tickets. From there you can settle this tab when the customer is ready."><i class="fas fa-list"></i> View Tabs</button>
                    <?php endif; ?>
                    <?php if (in_array($user['role'] ?? '', ['admin', 'manager'], true)): ?>
                        <button class="a-receipt a-lifecycle" onclick="openPosPageModal('order-lifecycle.php?id=<?php echo (int)$lastOrderId; ?>','Timeline','fas fa-stream')" data-help="Order lifecycle|See every event for this order — placement, kitchen actions, stock movements, payment — with timestamps and the user who did each."><i class="fas fa-stream"></i> Lifecycle</button>
                    <?php endif; ?>
                    <button class="a-new" onclick="closeSuccess()" data-help="New order|Close this dialog and start ringing up the next order."><i class="fas fa-plus-circle"></i> New order</button>
                </div>
                <?php if (!$justParked): ?>
                    <div style="margin-top:14px;padding:12px 12px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fbfaf7;text-align:left;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;">
                            <strong style="font-size:13px;color:#3f3a33;"><i class="fas fa-paper-plane" style="color:#8B7355;margin-right:6px;"></i>Send receipt now</strong>
                            <a href="whatsapp-settings.php" target="_blank" rel="noopener" style="font-size:11px;color:#8B7355;text-decoration:none;"><i class="fas fa-sliders"></i> WhatsApp setup</a>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <form method="POST" action="stock-receipt.php?id=<?php echo (int)$lastOrderId; ?>" target="_blank" style="display:grid;gap:6px;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="email_receipt">
                                <input type="hidden" name="order_id" value="<?php echo (int)$lastOrderId; ?>">
                                <input type="email" name="recipient" required value="<?php echo htmlspecialchars((string)($lastOrderCustomerEmail ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="guest@example.com" style="min-height:36px;border:1px solid #d9d4cc;border-radius:8px;padding:7px 10px;font-size:12px;font-family:inherit;">
                                <button type="submit" class="a-receipt" style="justify-content:center;"><i class="fas fa-envelope"></i> Send Email</button>
                            </form>
                            <form method="POST" action="stock-receipt.php?id=<?php echo (int)$lastOrderId; ?>" target="_blank" style="display:grid;gap:6px;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="whatsapp_receipt">
                                <input type="hidden" name="order_id" value="<?php echo (int)$lastOrderId; ?>">
                                <input type="text" name="recipient" required value="<?php echo htmlspecialchars((string)($lastOrderCustomerPhone ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="+265 999 123 456" style="min-height:36px;border:1px solid #d9d4cc;border-radius:8px;padding:7px 10px;font-size:12px;font-family:inherit;">
                                <button type="submit" class="a-receipt" style="justify-content:center;background:#1d6a3e;"><i class="fab fa-whatsapp"></i> Send WhatsApp</button>
                            </form>
                        </div>
                        <p style="margin:7px 0 0;font-size:11px;color:#6c757d;line-height:1.5;">If WhatsApp provider is not fully configured yet, the intent is logged with no billable send; complete number + API token in WhatsApp Settings to go live.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Shift-close result modal -->
    <?php if ($justClosedShift !== null): ?>
        <div class="overlay modal-overlay show" data-modal id="shiftResultOverlay">
            <div class="success-modal modal-content" style="text-align:left; width:560px;">
                <h2 style="text-align:center; color:#1f1f24;"><i class="fas fa-cash-register"></i> Shift closed</h2>
                <div style="text-align:center; font-size:13px; color:#6c757d; margin-bottom:18px;"><?php echo htmlspecialchars($user['full_name']); ?> · <?php echo date('Y-m-d H:i'); ?></div>
                <table style="width:100%; border-collapse:collapse; font-size:14px;">
                    <thead>
                        <tr style="background:#f7f7f7;">
                            <th style="text-align:left; padding:8px;">Tender</th>
                            <th style="text-align:right; padding:8px;">Expected</th>
                            <th style="text-align:right; padding:8px;">Declared</th>
                            <th style="text-align:right; padding:8px;">Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (['cash' => 'Cash', 'mobile' => 'Mobile', 'card' => 'Card'] as $k => $lbl): $v = $justClosedShift["variance_$k"];
                            $vc = $v == 0 ? '#155724' : ($v < 0 ? '#c82333' : '#856404'); ?>
                            <tr>
                                <td style="padding:8px; border-top:1px solid #eee;"><?php echo $lbl; ?></td>
                                <td style="padding:8px; border-top:1px solid #eee; text-align:right;"><?php echo number_format($justClosedShift["expected_$k"], 2); ?></td>
                                <td style="padding:8px; border-top:1px solid #eee; text-align:right;"><?php echo number_format($justClosedShift["declared_$k"], 2); ?></td>
                                <td style="padding:8px; border-top:1px solid #eee; text-align:right; color:<?php echo $vc; ?>; font-weight:700;"><?php echo ($v > 0 ? '+' : '') . number_format($v, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="font-size:12px; color:#6c757d; margin-top:14px;">Orders: <?php echo $justClosedShift['orders_count']; ?> · Voids: <?php echo $justClosedShift['voids_count']; ?><?php if (($justClosedShift['tips_total'] ?? 0) > 0): ?> · Tips collected: <strong><?php echo $currency_symbol . ' ' . number_format((float)$justClosedShift['tips_total'], 2); ?></strong><?php endif; ?> · Settled earlier tabs: <?php echo (int)($justClosedShift['settled_from_tabs_count'] ?? 0); ?> (<?php echo $currency_symbol . ' ' . number_format((float)($justClosedShift['settled_from_tabs_amount'] ?? 0), 2); ?>). Recorded in <code>stock_shift_closes</code> for management review.</p>
                <div class="actions">
                    <button class="a-print" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print Z-report</button>
                    <a class="a-new" href="pos.php"><i class="fas fa-arrow-right"></i> Continue</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Open tabs tray -->
    <div class="overlay modal-overlay" data-modal id="tabsOverlay">
        <div class="modal modal-content">
            <div class="modal-head modal-header" style="flex-wrap:wrap; gap:8px;">
                <h3 id="openTabsTitle" style="flex:1; min-width:0;"><i class="fas fa-utensils"></i> Open tabs (<?php echo count($openTabs); ?>)</h3>
                <div style="display:flex; align-items:center; gap:6px;">
                    <div style="position:relative;">
                        <i class="fas fa-search" style="position:absolute; left:9px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:12px; pointer-events:none;"></i>
                        <input type="text" id="tabsSearchInput" placeholder="Search tabs…" oninput="filterOpenTabCards(this.value)" autocomplete="off"
                            style="padding:7px 10px 7px 28px; border:1px solid #d1d5db; border-radius:7px; font-size:12px; width:160px; outline:none;">
                    </div>
                    <button class="close modal-close" onclick="closeTabsTray()">&times;</button>
                </div>
            </div>
            <div class="modal-body" id="tabsTrayBody" style="max-height:72vh; overflow-y:auto;">
                <?php if (empty($openTabs)): ?>
                    <div class="tabs-tray-tools is-collapsed" id="tabsTrayTools">
                        <div class="tabs-tray-tools__headline">
                            <div class="tabs-tray-tools__metrics">
                                <div class="tabs-tray-metric"><span class="tabs-tray-metric__label">Open</span><strong>0</strong></div>
                                <div class="tabs-tray-metric"><span class="tabs-tray-metric__label">Outstanding</span><strong><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8'); ?> 0.00</strong></div>
                                <div class="tabs-tray-metric"><span class="tabs-tray-metric__label">Stale</span><strong>0</strong></div>
                            </div>
                            <div class="tabs-tray-tools__status">
                                <span class="tabs-tray-updated" id="tabsTrayUpdated">Live</span>
                                <button type="button" class="tabs-tray-toggle" id="tabsTrayToggleBtn" onclick="toggleTabsTrayTools()" aria-expanded="false"><i class="fas fa-sliders"></i> Bulk tools</button>
                            </div>
                        </div>
                        <div class="tabs-tray-tools__bulk-panel" id="tabsTrayBulkPanel" hidden>
                            <div class="tabs-tray-tools__bulk-row">
                                <label class="tabs-bulk-check"><input type="checkbox" id="tabsBulkAll" onchange="toggleTabsBulkAll(this.checked)" disabled> <span>Select all</span></label>
                                <button type="button" class="tabs-bulk-btn" onclick="tabsSelectStale()" disabled><i class="fas fa-triangle-exclamation"></i> Select stale</button>
                                <button type="button" class="tabs-bulk-btn" onclick="tabsClearSelection()" disabled><i class="fas fa-broom"></i> Clear</button>
                                <span class="tabs-bulk-info" id="tabsBulkSelectionInfo">No tabs selected</span>
                            </div>
                            <div class="tabs-tray-tools__bulk-actions">
                                <button type="button" class="tabs-bulk-btn tabs-bulk-btn--cancel" id="tabsBulkCancelBtn" onclick="bulkCancelTabs()" disabled><i class="fas fa-circle-xmark"></i> Bulk cancel</button>
                                <?php if (in_array($user['role'] ?? '', ['admin', 'manager'], true)): ?>
                                    <button type="button" class="tabs-bulk-btn tabs-bulk-btn--void" id="tabsBulkVoidBtn" onclick="bulkVoidTabs()" disabled><i class="fas fa-ban"></i> Bulk void</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <p style="text-align:center; color:#6c757d; padding:30px 0;">No open tabs.</p>
                <?php else: ?>
                    <?php
                    $openTabsTotalAmount = 0.0;
                    $staleTabsCount = 0;
                    foreach ($openTabs as $open_tab_metric) {
                        $openTabsTotalAmount += (float)($open_tab_metric['total_amount'] ?? 0);
                        if (!empty($restaurantWindow['start_sql']) && (($open_tab_metric['created_at'] ?? '') < $restaurantWindow['start_sql'])) {
                            $staleTabsCount++;
                        }
                    }
                    ?>
                    <div class="tabs-tray-tools is-collapsed" id="tabsTrayTools">
                        <div class="tabs-tray-tools__headline">
                            <div class="tabs-tray-tools__metrics">
                                <div class="tabs-tray-metric"><span class="tabs-tray-metric__label">Open</span><strong><?php echo count($openTabs); ?></strong></div>
                                <div class="tabs-tray-metric"><span class="tabs-tray-metric__label">Outstanding</span><strong><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8') . ' ' . number_format($openTabsTotalAmount, 2); ?></strong></div>
                                <div class="tabs-tray-metric"><span class="tabs-tray-metric__label">Stale</span><strong><?php echo (int)$staleTabsCount; ?></strong></div>
                            </div>
                            <div class="tabs-tray-tools__status">
                                <span class="tabs-tray-updated" id="tabsTrayUpdated">Live</span>
                                <button type="button" class="tabs-tray-toggle" id="tabsTrayToggleBtn" onclick="toggleTabsTrayTools()" aria-expanded="false"><i class="fas fa-sliders"></i> Bulk tools</button>
                            </div>
                        </div>
                        <div class="tabs-tray-tools__bulk-panel" id="tabsTrayBulkPanel" hidden>
                            <div class="tabs-tray-tools__bulk-row">
                                <label class="tabs-bulk-check"><input type="checkbox" id="tabsBulkAll" onchange="toggleTabsBulkAll(this.checked)"> <span>Select all</span></label>
                                <button type="button" class="tabs-bulk-btn" onclick="tabsSelectStale()"><i class="fas fa-triangle-exclamation"></i> Select stale</button>
                                <button type="button" class="tabs-bulk-btn" onclick="tabsClearSelection()"><i class="fas fa-broom"></i> Clear</button>
                                <span class="tabs-bulk-info" id="tabsBulkSelectionInfo">No tabs selected</span>
                            </div>
                            <div class="tabs-tray-tools__bulk-actions">
                                <button type="button" class="tabs-bulk-btn tabs-bulk-btn--cancel" id="tabsBulkCancelBtn" onclick="bulkCancelTabs()" disabled><i class="fas fa-circle-xmark"></i> Bulk cancel</button>
                                <?php if (in_array($user['role'] ?? '', ['admin', 'manager'], true)): ?>
                                    <button type="button" class="tabs-bulk-btn tabs-bulk-btn--void" id="tabsBulkVoidBtn" onclick="bulkVoidTabs()" disabled><i class="fas fa-ban"></i> Bulk void</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="tab-cards-list" id="tabsCardsList">
                        <?php foreach ($openTabs as $t):
                            $created   = strtotime($t['created_at']);
                            $ageSec    = max(0, time() - $created);
                            $ageM = floor($ageSec / 60);
                            $ageS = $ageSec % 60;
                            $ageStr = $ageM > 0 ? ($ageM . 'm ' . str_pad((string)$ageS, 2, '0', STR_PAD_LEFT) . 's') : ($ageS . 's');
                            $ageColor  = $ageSec >= 1800 ? '#c82333' : ($ageSec >= 900 ? '#d4a843' : '#28a745');
                            $isStale   = $t['created_at'] < ($restaurantWindow['start_sql'] ?? '');
                            $pendingCount    = (int)($t['pending_count']    ?? 0);
                            $preparingCount  = (int)($t['preparing_count']  ?? 0);
                            $readyCount      = (int)($t['ready_count']      ?? 0);
                            $collectionCount = (int)($t['collection_count'] ?? 0);
                            $servedCount     = (int)($t['served_count']     ?? 0);
                            $totalItems      = (int)($t['line_count']       ?? 0);
                            $canCancelBeforePrep = ($pendingCount > 0)
                                && ($preparingCount === 0) && ($readyCount === 0)
                                && ($collectionCount === 0) && ($servedCount === 0);
                            // Bar items auto-serve on settlement; only kitchen items block
                            $canSettle = $totalItems > 0;
                            $openedByOther = ((int)($t['created_by'] ?? 0) !== (int)$user['id']);
                        ?>
                            <article class="tab-card<?php echo $isStale ? ' stale' : ''; ?>" data-order-id="<?php echo (int)$t['id']; ?>" data-is-stale="<?php echo $isStale ? '1' : '0'; ?>">
                                <div class="tc-row1">
                                    <label class="tc-select-wrap" aria-label="Select <?php echo htmlspecialchars((string)($t['reference'] ?? 'TAB'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="checkbox" class="tc-select-input" data-order-id="<?php echo (int)$t['id']; ?>" data-ref="<?php echo htmlspecialchars((string)($t['reference'] ?? 'TAB'), ENT_QUOTES, 'UTF-8'); ?>" data-total="<?php echo (float)($t['total_amount'] ?? 0); ?>" data-can-cancel="<?php echo $canCancelBeforePrep ? '1' : '0'; ?>" data-is-stale="<?php echo $isStale ? '1' : '0'; ?>" onchange="tabsSelectionChanged()">
                                        <span class="tc-select-indicator"><i class="fas fa-check"></i></span>
                                    </label>
                                    <div class="tc-ref"><?php echo htmlspecialchars($t['reference']); ?></div>
                                    <div class="tc-age tab-age" data-created="<?php echo (int)$created; ?>" style="color:<?php echo $ageColor; ?>;">
                                        <i class="fas fa-stopwatch"></i> <?php echo $ageStr; ?>
                                    </div>
                                </div>
                                <div class="tc-meta">
                                    <?php if (!empty($t['table_number'])): ?><span class="tc-meta-pill"><i class="fas fa-table-cells-large"></i> Table <?php echo htmlspecialchars($t['table_number']); ?></span><?php endif; ?>
                                    <?php if (!empty($t['customer_name'])): ?><span class="tc-meta-pill"><i class="fas fa-user"></i> <?php echo htmlspecialchars($t['customer_name']); ?></span><?php endif; ?>
                                    <?php if ((int)($t['covers'] ?? 0) > 0): ?><span class="tc-meta-pill"><i class="fas fa-users"></i> <?php echo (int)$t['covers']; ?> cover<?php echo (int)$t['covers'] === 1 ? '' : 's'; ?></span><?php endif; ?>
                                    <span class="tc-meta-pill"><i class="fas fa-list"></i> <?php echo $totalItems; ?> item<?php echo $totalItems === 1 ? '' : 's'; ?></span>
                                    <span class="tc-meta-pill"><i class="fas fa-clock"></i> Opened <?php echo htmlspecialchars(date('H:i', $created)); ?></span>
                                    <?php if ($openedByOther): ?>
                                        <span class="tc-meta-pill"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars((string)($t['opened_by'] ?? 'staff')); ?></span>
                                    <?php else: ?>
                                        <span class="tc-meta-pill"><i class="fas fa-user-check"></i> You</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($totalItems > 0): ?>
                                    <div class="tc-flow">
                                        <?php if ($pendingCount > 0):    ?><span class="tc-flow-chip pending"><i class="fas fa-clock"></i> <?php echo $pendingCount; ?> pending</span><?php endif; ?>
                                        <?php if ($preparingCount > 0):  ?><span class="tc-flow-chip preparing"><i class="fas fa-fire-burner"></i> <?php echo $preparingCount; ?> prep</span><?php endif; ?>
                                        <?php if ($readyCount > 0):      ?><span class="tc-flow-chip ready"><i class="fas fa-bell"></i> <?php echo $readyCount; ?> ready</span><?php endif; ?>
                                        <?php if ($collectionCount > 0): ?><span class="tc-flow-chip collection"><i class="fas fa-hand-holding"></i> <?php echo $collectionCount; ?> collecting</span><?php endif; ?>
                                        <?php if ($servedCount > 0):     ?><span class="tc-flow-chip served"><i class="fas fa-check"></i> <?php echo $servedCount; ?> served</span><?php endif; ?>
                                        <?php if ($totalItems > 0 && $servedCount === $totalItems): ?><span class="tc-flow-chip served-all"><i class="fas fa-circle-check"></i> All served</span><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="tc-summary-row">
                                    <div>
                                        <span class="tc-total-label">Total</span>
                                        <div class="tc-total"><?php echo $currency_symbol . ' ' . number_format((float)$t['total_amount'], 2); ?></div>
                                    </div>
                                    <?php if ($isStale): ?><div class="tc-stale-warn" data-help="From an earlier shift|Settle it as normal — if kitchen items were never bumped, the Pay button will offer a manager force-serve override."><i class="fas fa-triangle-exclamation"></i> Previous shift</div><?php endif; ?>
                                </div>
                                <div class="tc-actions">
                                    <?php if ((int)($t['split_paid_count'] ?? 0) === 0): ?>
                                    <button type="button" onclick="startAddToTab(<?php echo (int)$t['id']; ?>, <?php echo json_encode((string)$t['reference']); ?>, <?php echo (float)$t['total_amount']; ?>)"
                                        class="tc-btn tc-btn-add"
                                        data-help="Add items|Add another round to this tab. Returns you to the menu; the next Fire adds to this tab.">
                                        <i class="fas fa-plus"></i> Add items
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" onclick="openPayForTab(<?php echo (int)$t['id']; ?>, <?php echo (float)$t['total_amount']; ?>, <?php echo json_encode((string)$t['reference']); ?>, <?php echo $canSettle ? 'true' : 'false'; ?>, <?php echo (int)($t['split_count'] ?? 1); ?>, <?php echo (int)($t['split_paid_count'] ?? 0); ?>)"
                                        class="tc-btn tc-btn-settle"
                                        data-help="Settle tab|Close this tab — take payment and mark the order as paid.">
                                        <i class="fas fa-credit-card"></i> Settle
                                    </button>
                                    <button type="button" class="tc-btn tc-btn-more" onclick="toggleTabCardActions(this)" aria-expanded="false">
                                        <i class="fas fa-ellipsis"></i> More
                                    </button>
                                </div>
                                <?php /* Occasional and destructive actions live behind "More": a tab card showed
                                          seven buttons at once, which is a lot to scan mid-service when only
                                          "Add items" and "Settle" are used routinely. Keeping Cancel and Void
                                          one tap further back is a safety gain too. */ ?>
                                <div class="tc-actions tc-actions--secondary" hidden>
                                    <button type="button" onclick="openTabDetail(<?php echo (int)$t['id']; ?>)"
                                        class="tc-btn tc-btn-detail">
                                        <i class="fas fa-receipt"></i> Details
                                    </button>
                                    <button type="button"
                                        onclick="openPosPageModal('stock-receipt.php?id=<?php echo (int)$t['id']; ?>&print=1&kot=1','Print KOT','fas fa-print')"
                                        class="tc-btn tc-btn-kot">
                                        <i class="fas fa-print"></i> KOT
                                    </button>
                                    <?php if ($canCancelBeforePrep): ?>
                                        <button type="button"
                                            onclick="cancelOpenOrder(<?php echo (int)$t['id']; ?>, <?php echo json_encode((string)$t['reference']); ?>)"
                                            class="tc-btn tc-btn-cancel">
                                            <i class="fas fa-circle-xmark"></i> Cancel
                                        </button>
                                    <?php endif; ?>
                                    <?php if (in_array($user['role'] ?? '', ['admin', 'manager'], true)): ?>
                                        <button type="button"
                                            onclick="openPosPageModal('order-lifecycle.php?id=<?php echo (int)$t['id']; ?>','Timeline','fas fa-stream')"
                                            class="tc-btn tc-btn-log">
                                            <i class="fas fa-stream"></i> Lifecycle
                                        </button>
                                        <button type="button"
                                            onclick="adminVoidTab(<?php echo (int)$t['id']; ?>, <?php echo json_encode((string)$t['reference']); ?>)"
                                            class="tc-btn tc-btn-void">
                                            <i class="fas fa-ban"></i> Void
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tab Detail Overlay -->
    <div class="overlay modal-overlay" data-modal id="tabDetailOverlay">
        <div class="modal modal-content" style="width:880px; max-width:96vw;">
            <div class="modal-head modal-header">
                <h3 id="tdiTitle"><i class="fas fa-receipt"></i> Tab details</h3>
                <button class="close modal-close" onclick="closeTabDetailOverlay()">&times;</button>
            </div>
            <div class="modal-body" id="tdiBody" style="max-height:78vh; overflow-y:auto;">
                <div style="text-align:center; padding:40px 0; color:#9ca3af;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
            </div>
        </div>
    </div>

    <!-- POS Reason Modal (replaces browser prompt/alert for cancel + void) -->
    <div class="overlay modal-overlay" data-modal id="posReasonOverlay">
        <div class="modal modal-content">
            <div class="modal-head modal-header">
                <h3 id="prmTitle"><i class="fas fa-comment-alt"></i> Reason required</h3>
                <button class="close modal-close" onclick="posReasonCancel()">&times;</button>
            </div>
            <div class="prm-body">
                <p class="prm-prompt" id="prmPrompt"></p>
                <div class="prm-warn" id="prmWarn" style="display:none;"></div>
                <label class="prm-label" for="prmReason">Reason <span style="color:#c82333;">*</span></label>
                <textarea class="prm-textarea" id="prmReason" rows="3" placeholder="Enter reason (min 8 characters)…"></textarea>
                <div class="prm-hint" id="prmHint">0 / 8 characters minimum</div>
                <div id="prmNotesWrap" style="display:none;">
                    <label class="prm-label" for="prmNotes" style="margin-top:8px;">Additional notes (optional)</label>
                    <input type="text" class="prm-notes" id="prmNotes" placeholder="Optional follow-up notes…">
                </div>
                <div class="prm-error" id="prmError"></div>
            </div>
            <div class="modal-foot modal-footer">
                <button type="button" class="tc-btn" style="background:#f0f2f4; color:#495057; flex:1; padding:14px;" onclick="posReasonCancel()">Cancel</button>
                <button type="button" id="prmConfirm" class="tc-btn" style="flex:2; padding:14px; border-radius:8px; font-size:15px; font-weight:700; background:#c82333; color:#fff; cursor:pointer;" onclick="posReasonConfirm()"><i class="fas fa-check"></i> <span id="prmConfirmLabel">Confirm</span></button>
            </div>
        </div>
    </div>

    <!-- Close shift modal -->
    <div class="overlay modal-overlay" data-modal id="closeShiftOverlay">
        <div class="modal modal-content">
            <div class="modal-head modal-header">
                <h3><i class="fas fa-cash-register"></i> Close shift (Z-report)</h3><button class="close modal-close" onclick="closeShiftModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="close_shift">
                <div class="modal-body">
                    <p style="font-size:13px; color:#6c757d; margin-top:0;">Count what's actually in the drawer / records. The shift must balance — variance &gt; <?php echo $currency_symbol; ?> 1.00 will be blocked unless overridden by an admin/manager.</p>
                    <div style="background:#f7f7f7; border-radius:8px; padding:12px; font-size:13px; margin-bottom:14px;">
                        <div>Expected cash: <strong id="expCash" data-amount="<?php echo (float)($shift['cash_today'] ?? 0); ?>"><?php echo $currency_symbol . ' ' . number_format((float)($shift['cash_today'] ?? 0), 2); ?></strong></div>
                        <div>Expected mobile: <strong id="expMobile" data-amount="<?php echo (float)($shift['mobile_today'] ?? 0); ?>"><?php echo $currency_symbol . ' ' . number_format((float)($shift['mobile_today'] ?? 0), 2); ?></strong></div>
                        <div>Expected card: <strong id="expCard" data-amount="<?php echo (float)($shift['card_today'] ?? 0); ?>"><?php echo $currency_symbol . ' ' . number_format((float)($shift['card_today'] ?? 0), 2); ?></strong></div>
                        <div>Paid orders this shift: <strong id="closeShiftOrdersCount"><?php echo (int)($shift['orders_today'] ?? 0); ?></strong></div>
                        <div>Settled earlier tabs: <strong id="closeShiftSettledCount"><?php echo (int)($shift['settled_from_tabs_count'] ?? 0); ?></strong> · <span id="closeShiftSettledAmount"><?php echo $currency_symbol . ' ' . number_format((float)($shift['settled_from_tabs_amount'] ?? 0), 2); ?></span></div>
                    </div>
                    <label>Cash counted (<?php echo $currency_symbol; ?>)</label>
                    <input type="number" step="0.01" min="0" name="declared_cash" id="declCash" required oninput="updShiftVariance()">
                    <label>Mobile money totals (<?php echo $currency_symbol; ?>)</label>
                    <input type="number" step="0.01" min="0" name="declared_mobile" id="declMobile" value="<?php echo number_format((float)($shift['mobile_today'] ?? 0), 2, '.', ''); ?>" oninput="updShiftVariance()">
                    <label>Card totals (<?php echo $currency_symbol; ?>)</label>
                    <input type="number" step="0.01" min="0" name="declared_card" id="declCard" value="<?php echo number_format((float)($shift['card_today'] ?? 0), 2, '.', ''); ?>" oninput="updShiftVariance()">

                    <div id="shiftVarianceBox" style="margin-top:10px; padding:10px 12px; border-radius:8px; font-size:13px; display:none;"></div>

                    <?php if (in_array($user['role'] ?? '', ['admin', 'manager'], true)): ?>
                        <div id="shiftOverrideBox" style="display:none; margin-top:10px; padding:10px 12px; background:#fff3cd; border:1px solid #d4a843; border-radius:8px;">
                            <label style="display:flex; align-items:center; gap:8px; font-weight:600; color:#856404; margin-bottom:6px;">
                                <input type="checkbox" name="admin_override" value="1" id="adminOverride" onchange="document.getElementById('overrideReason').required = this.checked;">
                                <i class="fas fa-user-shield"></i> Admin override (close despite variance)
                            </label>
                            <label style="font-size:12px;">Reason (audit-logged)</label>
                            <input type="text" name="override_reason" id="overrideReason" placeholder="e.g. drawer short — counted by JS &amp; LM, signed off" minlength="5">
                        </div>
                    <?php endif; ?>

                    <label>Note (optional)</label>
                    <input type="text" name="shift_note" placeholder="Drawer started with X, etc.">
                </div>
                <div class="modal-foot modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeShiftModal()">Cancel</button>
                    <button type="submit" class="btn-confirm" id="closeShiftBtn">Close shift</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Opening Float modal -->
    <div class="overlay modal-overlay" data-modal id="floatOverlay">
        <div class="modal modal-content" style="max-width:400px;">
            <div class="modal-head modal-header">
                <h3><i class="fas fa-coins"></i> Set opening float</h3><button class="close modal-close" onclick="closeFloatModal()">&times;</button>
            </div>
            <form id="floatForm" onsubmit="submitFloat(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="set_float">
                <div class="modal-body">
                    <p style="font-size:13px;color:#6c757d;margin-top:0;">Record the cash amount placed in the drawer at the start of your shift. This is logged for end-of-shift reconciliation.</p>
                    <label>Float amount (<?php echo $currency_symbol; ?>)</label>
                    <input type="number" step="0.01" min="0" name="float_amount" id="floatAmount" required placeholder="e.g. 5000.00" style="font-size:18px; font-weight:600;">
                    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:6px; margin:8px 0;">
                        <button type="button" onclick="document.getElementById('floatAmount').value='2000'" style="padding:10px;border:1px solid #d6d8db;background:#fff;border-radius:6px;cursor:pointer;font-size:12px;">2,000</button>
                        <button type="button" onclick="document.getElementById('floatAmount').value='5000'" style="padding:10px;border:1px solid #d6d8db;background:#fff;border-radius:6px;cursor:pointer;font-size:12px;">5,000</button>
                        <button type="button" onclick="document.getElementById('floatAmount').value='10000'" style="padding:10px;border:1px solid #d6d8db;background:#fff;border-radius:6px;cursor:pointer;font-size:12px;">10,000</button>
                        <button type="button" onclick="document.getElementById('floatAmount').value='20000'" style="padding:10px;border:1px solid #d6d8db;background:#fff;border-radius:6px;cursor:pointer;font-size:12px;">20,000</button>
                    </div>
                    <label>Note <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
                    <input type="text" name="float_note" placeholder="Float handed over by manager, etc.">
                </div>
                <div class="modal-foot modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeFloatModal()">Cancel</button>
                    <button type="submit" class="btn-confirm"><i class="fas fa-coins"></i> Record float</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Refund confirm modal (manager only) -->
    <div class="overlay modal-overlay" data-modal id="refundOverlay">
        <div class="modal modal-content" style="max-width:420px;">
            <div class="modal-head modal-header" style="background:#6f42c1;color:#fff;border-radius:12px 12px 0 0;">
                <h3 style="color:#fff;margin:0;"><i class="fas fa-rotate-left"></i> Process refund</h3>
                <button class="close modal-close" onclick="closeRefundModal()" style="color:#fff;">&times;</button>
            </div>
            <form id="refundForm" onsubmit="submitRefund(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="refund_order">
                <input type="hidden" name="order_id" id="refundOrderId" value="">
                <div class="modal-body">
                    <div style="background:#f3e8ff;border:1px solid #c4b5fd;border-radius:8px;padding:12px;margin-bottom:14px;font-size:14px;">
                        <div id="refundOrderRef" style="font-weight:700;color:#6f42c1;margin-bottom:4px;"></div>
                        <div id="refundOrderAmt" style="color:#374151;"></div>
                    </div>
                    <p style="font-size:13px;color:#6c757d;margin-top:0;margin-bottom:12px;">This will mark the order as <strong>refunded</strong> and create a negative payment record. Stock is not automatically restored — reverse any stock adjustments manually if needed.</p>
                    <label>Reason for refund <span style="color:#c82333;">*</span></label>
                    <textarea name="refund_reason" id="refundReason" rows="3" placeholder="Guest complaint, overcharge, duplicate order… (min 5 characters)" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:13px;font-family:inherit;" required minlength="5"></textarea>
                </div>
                <div class="modal-foot modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeRefundModal()">Cancel</button>
                    <button type="submit" class="btn-confirm" style="background:#6f42c1;"><i class="fas fa-rotate-left"></i> Confirm refund</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Manager In-Session Auth Overlay ──────────────────────────────── -->
    <div class="overlay modal-overlay" data-modal id="mgrAuthOverlay" style="z-index:10001;">
        <div class="modal modal-content" style="max-width:380px;">
            <div class="modal-head modal-header" style="background:#1e293b;color:#fff;border-radius:12px 12px 0 0;">
                <h3 style="color:#fff;margin:0;"><i class="fas fa-shield-alt"></i> Manager Authorisation</h3>
                <button class="close modal-close" onclick="closeMgrAuthOverlay()" style="color:#fff;">&times;</button>
            </div>
            <form id="mgrAuthForm" onsubmit="submitMgrAuth(event)" autocomplete="off">
                <div class="modal-body">
                    <p style="font-size:13px;color:#6c757d;margin-top:0;">This action requires a manager or authorised user to approve. Please enter your credentials to proceed.</p>
                    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:13px;color:#166534;">
                        <i class="fas fa-key" style="margin-right:6px;"></i>
                        Permission required: <strong id="mgrAuthPermLabel"></strong>
                    </div>
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px;">Manager username</label>
                    <input type="text" id="mgrAuthUsername" autocomplete="off" placeholder="Username" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:9px 12px;font-size:14px;margin-bottom:10px;">
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px;">Password</label>
                    <input type="password" id="mgrAuthPassword" autocomplete="new-password" placeholder="Password" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:9px 12px;font-size:14px;margin-bottom:6px;">
                    <div id="mgrAuthError" style="color:#c82333;font-size:12px;min-height:18px;margin-bottom:4px;"></div>
                </div>
                <div class="modal-foot modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeMgrAuthOverlay()">Cancel</button>
                    <button type="submit" class="btn-confirm" style="background:#1e293b;"><i class="fas fa-check-circle"></i> Authorise</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pay-existing-tab modal (small wrapper that points payForm at action=pay_existing) -->
    <div class="overlay modal-overlay" data-modal id="payTabOverlay">
        <div class="modal modal-content" style="max-width:480px;">
            <div class="modal-head modal-header" style="flex-direction:column;align-items:flex-start;gap:2px;">
                <div style="display:flex;align-items:center;justify-content:space-between;width:100%;">
                    <h3 style="margin:0;"><i class="fas fa-credit-card"></i> Settle tab</h3>
                    <button class="close modal-close" onclick="closePayTabOverlay()">&times;</button>
                </div>
                <div id="payTabSplitStep" style="display:none;font-size:12px;color:#8B7355;font-weight:600;padding-left:2px;"></div>
            </div>
            <form method="POST" id="payTabForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="pay_existing">
                <input type="hidden" name="order_id" id="payTabOrderId" value="">
                <input type="hidden" name="split_count" id="payTabSplitCount" value="1">
                <input type="hidden" name="split_number" id="payTabSplitNumber" value="1">
                <input type="hidden" name="tip_amount" id="payTabTipHidden" value="0">
                <input type="hidden" name="force_serve_kitchen" id="payTabForceServeKitchen" value="0">
                <input type="hidden" name="mgr_auth_token" id="payTabMgrAuthToken" value="">
                <div class="modal-body" style="padding-bottom:10px;">
                    <!-- Reference + order total -->
                    <div style="font-size:13px;color:#6c757d;text-align:center;" id="payTabRef">—</div>
                    <div style="font-size:13px;text-align:center;color:#6c757d;margin:2px 0 2px;" id="payTabOrderTotalRow" style="display:none;">
                        Order total: <span id="payTabTotal" style="font-weight:600;"><?php echo $currency_symbol; ?> 0.00</span>
                    </div>
                    <!-- Amount due (= share + tip, what this person pays) -->
                    <div style="font-size:30px;font-weight:700;text-align:center;margin:6px 0 14px;color:#1f2937;" id="payTabAmountDueDisplay"><?php echo $currency_symbol; ?> 0.00</div>

                    <!-- Running split ledger (appends a row per person paid) -->
                    <div id="payTabSplitLedger" style="display:none;margin-bottom:10px;"></div>

                    <!-- Split bill selector -->
                    <div style="background:#f8f9fa;border-radius:8px;padding:10px 12px;margin-bottom:10px;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span style="font-size:13px;font-weight:600;color:#374151;white-space:nowrap;"><i class="fas fa-users" style="color:#8B7355;margin-right:4px;"></i>Split bill</span>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;" id="payTabSplitWays">
                                <button type="button" class="split-way-btn active" data-ways="1" onclick="ptSetSplitWays(1)">Off</button>
                                <button type="button" class="split-way-btn" data-ways="2" onclick="ptSetSplitWays(2)">2</button>
                                <button type="button" class="split-way-btn" data-ways="3" onclick="ptSetSplitWays(3)">3</button>
                                <button type="button" class="split-way-btn" data-ways="4" onclick="ptSetSplitWays(4)">4</button>
                                <button type="button" class="split-way-btn" data-ways="5" onclick="ptSetSplitWays(5)">5</button>
                                <button type="button" class="split-way-btn" data-ways="6" onclick="ptSetSplitWays(6)">6</button>
                            </div>
                        </div>
                        <div id="payTabShareRow" style="display:none;margin-top:8px;font-size:12px;color:#374151;">
                            Each person: <strong id="payTabShareAmt"></strong>
                        </div>
                    </div>

                    <!-- Tip section -->
                    <div style="background:#f8f9fa;border-radius:8px;padding:10px 12px;margin-bottom:10px;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                            <span style="font-size:13px;font-weight:600;color:#374151;white-space:nowrap;"><i class="fas fa-hand-holding-heart" style="color:#059669;margin-right:4px;"></i>Tip</span>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;" id="payTabTipPresets">
                                <button type="button" class="tip-preset-btn active" data-pct="0" onclick="ptSetTipPct(0)">None</button>
                                <button type="button" class="tip-preset-btn" data-pct="5" onclick="ptSetTipPct(5)">5%</button>
                                <button type="button" class="tip-preset-btn" data-pct="10" onclick="ptSetTipPct(10)">10%</button>
                                <button type="button" class="tip-preset-btn" data-pct="15" onclick="ptSetTipPct(15)">15%</button>
                            </div>
                        </div>
                        <input type="number" step="0.01" min="0" id="payTabTipInput" placeholder="Custom tip amount" oninput="ptOnTipInput()" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;">
                    </div>

                    <!-- Discount section (first leg only) -->
                    <div id="payTabDiscountSection" style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;margin-bottom:10px;<?php echo $posCanDiscount ? '' : 'display:none;'; ?>">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                            <span style="font-size:13px;font-weight:600;color:#374151;white-space:nowrap;"><i class="fas fa-tag" style="color:#d97706;margin-right:4px;"></i>Discount</span>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;" id="payTabDiscountPresets">
                                <button type="button" class="discount-preset-btn active" data-pct="0" onclick="ptSetDiscountPct(this,0)">None</button>
                                <button type="button" class="discount-preset-btn" data-pct="5" onclick="ptSetDiscountPct(this,5)">5%</button>
                                <button type="button" class="discount-preset-btn" data-pct="10" onclick="ptSetDiscountPct(this,10)">10%</button>
                                <button type="button" class="discount-preset-btn" data-pct="15" onclick="ptSetDiscountPct(this,15)">15%</button>
                                <button type="button" class="discount-preset-btn" data-pct="25" onclick="ptSetDiscountPct(this,25)">25%</button>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div>
                                <label style="font-size:11px;font-weight:600;color:#6c757d;margin-bottom:3px;display:block;">Amount (<?php echo $currency_symbol; ?>)</label>
                                <input type="number" step="0.01" min="0" id="payTabDiscountAmt" placeholder="0.00" oninput="ptOnDiscountAmtInput()" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;">
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:600;color:#6c757d;margin-bottom:3px;display:block;">Reason</label>
                                <select id="payTabDiscountReason" onchange="syncTabDiscountToForm()" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;">
                                    <option value="">Select…</option>
                                    <option>Staff discount</option>
                                    <option>Happy hour</option>
                                    <option>Manager override</option>
                                    <option>Complimentary</option>
                                    <option>Loyalty discount</option>
                                    <option>Event promo</option>
                                </select>
                            </div>
                        </div>
                        <input type="hidden" name="discount_amount" id="payTabDiscountAmtHidden" value="0">
                        <input type="hidden" name="discount_reason" id="payTabDiscountReasonHidden" value="">
                        <input type="hidden" name="deal_discount_amount" id="payTabDealDiscountAmtHidden" value="0">
                        <input type="hidden" name="deal_ids" id="payTabDealIdsHidden" value="">
                    </div>

                    <label>Payment method</label>
                    <div class="pay-method-grid">
                        <button type="button" data-method="cash" onclick="setMethodTab(this)"><i class="fas fa-money-bill-wave"></i> Cash</button>
                        <button type="button" data-method="mobile_money" onclick="setMethodTab(this)"><i class="fas fa-mobile-alt"></i> Mobile</button>
                        <button type="button" data-method="card_manual" onclick="setMethodTab(this)"><i class="fas fa-credit-card"></i> Card</button>
                        <button type="button" class="disabled" onclick="showCardPosUnavailable()"><i class="fas fa-microchip"></i> Card POS<br><small>(soon)</small></button>
                    </div>
                    <input type="hidden" name="payment_method" id="payTabMethod" value="">
                    <div id="ext-tab-cash" style="display:none;">
                        <label id="payTabTenderedLabel">Tendered (<?php echo $currency_symbol; ?>)</label>
                        <input type="number" step="0.01" min="0" name="tendered_amount" id="payTabTendered" oninput="updTabChange()">
                        <div class="change-banner">Change: <span id="payTabChange"><?php echo $currency_symbol; ?> 0.00</span></div>
                        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:6px; margin-top:8px;">
                            <button type="button" onclick="quickTendTab(500)" style="padding:10px;border:1px solid #d6d8db;background:#fff;border-radius:6px;cursor:pointer;font-size:12px;">+500</button>
                            <button type="button" onclick="quickTendTab(1000)" style="padding:10px;border:1px solid #d6d8db;background:#fff;border-radius:6px;cursor:pointer;font-size:12px;">+1k</button>
                            <button type="button" onclick="quickTendTab(5000)" style="padding:10px;border:1px solid #d6d8db;background:#fff;border-radius:6px;cursor:pointer;font-size:12px;">+5k</button>
                            <button type="button" onclick="quickTendTabExact()" style="padding:10px;border:1px solid #28a745;background:#e9f5ee;color:#155724;border-radius:6px;cursor:pointer;font-weight:600;font-size:12px;">Exact</button>
                        </div>
                    </div>
                    <div id="ext-tab-mobile_money" style="display:none;">
                        <label>Provider</label>
                        <select name="mobile_wallet_provider">
                            <option value="">Select…</option>
                            <option>Airtel Money</option>
                            <option>TNM Mpamba</option>
                            <option>Mo626</option>
                            <option>Other</option>
                        </select>
                        <label>Reference</label><input type="text" name="mobile_wallet_reference">
                    </div>
                    <div id="ext-tab-card_manual" style="display:none;">
                        <label>Card last 4</label><input type="text" name="card_last4" maxlength="4" pattern="\d{4}">
                        <label>Auth code</label><input type="text" name="card_auth_code" maxlength="50">
                    </div>
                </div>
                <div class="modal-foot modal-footer">
                    <button type="button" class="btn-cancel" onclick="closePayTabOverlay()">Cancel</button>
                    <button type="submit" id="payTabSubmitBtn" class="btn-confirm">Take payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Post-payment receipt modal -->
    <div class="overlay modal-overlay" id="receiptModal" style="z-index:100002;">
        <div class="modal modal-content" style="width:520px;max-width:96vw;">
            <div class="modal-head modal-header" style="background:linear-gradient(135deg,#1d6a3e,#22c55e);color:#fff;border-radius:12px 12px 0 0;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:40px;height:40px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;"><i class="fas fa-check"></i></div>
                    <div>
                        <h3 style="margin:0;color:#fff;font-size:16px;" id="rmTitle">Payment received</h3>
                        <div style="font-size:12px;opacity:.85;" id="rmSubtitle">Tab settled</div>
                    </div>
                </div>
                <button class="close modal-close" onclick="closeReceiptModal()" style="color:#fff;opacity:.8;">&times;</button>
            </div>
            <div class="modal-body" style="padding:20px;">
                <!-- Payment summary strip -->
                <div id="rmSummary" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">
                </div>
                <!-- Send receipt section -->
                <div style="font-size:13px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-paper-plane" style="color:#8B7355;"></i> Send receipt to guest
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#6c757d;display:block;margin-bottom:4px;">Email</label>
                        <input type="email" id="rmEmail" placeholder="guest@example.com" style="width:100%;box-sizing:border-box;min-height:36px;border:1px solid #d1d5db;border-radius:7px;padding:7px 10px;font-size:12px;margin-bottom:6px;">
                        <button type="button" id="rmEmailBtn" onclick="sendPosReceipt('email')" style="width:100%;padding:8px;background:#3b82f6;color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;"><i class="fas fa-envelope"></i> Send email</button>
                        <div id="rmEmailStatus" style="font-size:11px;margin-top:4px;min-height:14px;"></div>
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#6c757d;display:block;margin-bottom:4px;">WhatsApp</label>
                        <input type="tel" id="rmPhone" placeholder="+265 999 123 456" style="width:100%;box-sizing:border-box;min-height:36px;border:1px solid #d1d5db;border-radius:7px;padding:7px 10px;font-size:12px;margin-bottom:6px;">
                        <button type="button" id="rmWhatsAppBtn" onclick="sendPosReceipt('whatsapp')" style="width:100%;padding:8px;background:#1d6a3e;color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;"><i class="fab fa-whatsapp"></i> Send WhatsApp</button>
                        <div id="rmWhatsAppStatus" style="font-size:11px;margin-top:4px;min-height:14px;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-foot modal-footer" style="gap:8px;">
                <a id="rmPrintLink" href="#" target="_blank" rel="noopener" class="btn-cancel"><i class="fas fa-print"></i> Print receipt</a>
                <button type="button" class="btn-confirm" onclick="closeReceiptModal()"><i class="fas fa-check"></i> Done</button>
            </div>
        </div>
    </div>

    <!-- Generic in-app iframe modal: KOT preview, timeline, receipt page, etc. -->
    <div class="overlay modal-overlay" id="posPageModal" style="z-index:100003;padding:8px;">
        <div class="modal modal-content" style="width:860px;max-width:98vw;height:90vh;display:flex;flex-direction:column;overflow:hidden;">
            <div class="modal-head modal-header" style="flex-shrink:0;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                <h3 id="posPageModalTitle" style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px;"><i id="posPageModalIcon" class="fas fa-file-alt"></i> <span id="posPageModalTitleText">Loading…</span></h3>
                <div style="display:flex;align-items:center;gap:8px;">
                    <button type="button" id="posPageModalPrintBtn" onclick="document.getElementById('posPageModalFrame').contentWindow.print()" style="background:#f3f4f6;border:none;border-radius:6px;padding:7px 12px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;color:#374151;"><i class="fas fa-print"></i> Print</button>
                    <button class="close modal-close" onclick="closePosPageModal()">&times;</button>
                </div>
            </div>
            <iframe id="posPageModalFrame" src="about:blank" style="flex:1;border:none;background:#f9fafb;" title="Page viewer"></iframe>
        </div>
    </div>

    <?php if (in_array($user['role'] ?? '', ['admin', 'manager'], true)): ?>
        <!-- Admin/manager: Live "All Stations" panel — Kitchen + Bar + Coffee Bar in one view, polled every 10s -->
        <div class="overlay modal-overlay" data-modal id="stationsOverlay">
            <div class="modal modal-content" style="width:980px; max-width:96vw; display:flex; flex-direction:column; max-height:94vh; overflow:hidden;">
                <div class="modal-head modal-header" style="flex-shrink:0;">
                    <h3><i class="fas fa-layer-group"></i> All Stations — Live</h3>
                    <span style="margin-left:14px; font-size:12px; color:#6c757d;">Auto-refresh every 10s · Last update: <span id="stationsTs">—</span></span>
                    <button class="close modal-close" onclick="document.getElementById('stationsOverlay').classList.remove('show');">&times;</button>
                </div>
                <div class="modal-body" style="flex:1; overflow-y:auto; padding:18px 20px;">
                    <div id="stationsTotals" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; font-size:12px;">
                        <span class="stat" style="background:#f8f9fb; padding:6px 12px; border-radius:18px;">Open tabs system-wide: <strong id="stOpenTabs">0</strong></span>
                        <span class="stat" style="background:#f8f9fb; padding:6px 12px; border-radius:18px;">Orders today: <strong id="stOrdersToday">0</strong></span>
                        <span class="stat" style="background:#f8f9fb; padding:6px 12px; border-radius:18px;">Revenue today: <strong id="stRevenueToday"><?php echo $currency_symbol; ?> 0.00</strong></span>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:14px;">
                        <?php foreach (['kitchen' => ['Kitchen', 'fa-utensils', '#d4a843'], 'bar' => ['Bar', 'fa-wine-glass', '#6f42c1'], 'coffee_bar' => ['Coffee Bar', 'fa-mug-hot', '#8B5A2B']] as $stKey => $meta): ?>
                            <div style="background:#fff; border:1px solid #eaecef; border-radius:10px; padding:12px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                    <h4 style="margin:0; color:<?php echo $meta[2]; ?>; font-size:14px;"><i class="fas <?php echo $meta[1]; ?>"></i> <?php echo $meta[0]; ?></h4>
                                    <span style="font-size:11px; color:#6c757d;">Open: <strong id="stOpen-<?php echo $stKey; ?>">0</strong> · Pending: <strong id="stPending-<?php echo $stKey; ?>">0</strong> · Ready: <strong id="stReady-<?php echo $stKey; ?>">0</strong></span>
                                </div>
                                <div id="stTickets-<?php echo $stKey; ?>" style="font-size:12px; color:#6c757d;">Loading…</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="padding:12px 20px; border-top:1px solid #eaecef; display:flex; gap:8px; flex-wrap:wrap; font-size:12px; flex-shrink:0; background:#fafafa; border-radius:0 0 14px 14px;">
                    <?php if (moduleEnabled('station_kds')): ?>
                    <a href="kds.php" target="_blank" style="padding:8px 12px; background:#d4a843; color:#1f1f24; text-decoration:none; border-radius:6px; font-weight:600;"><i class="fas fa-external-link-alt"></i> Kitchen</a>
                    <?php endif; ?>
                    <?php if (moduleEnabled('station_bds')): ?>
                    <a href="bds.php" target="_blank" style="padding:8px 12px; background:#6f42c1; color:#fff; text-decoration:none; border-radius:6px; font-weight:600;"><i class="fas fa-external-link-alt"></i> Bar</a>
                    <?php endif; ?>
                    <?php if (moduleEnabled('station_cds')): ?>
                    <a href="cds.php" target="_blank" style="padding:8px 12px; background:#8B5A2B; color:#fff; text-decoration:none; border-radius:6px; font-weight:600;"><i class="fas fa-external-link-alt"></i> Coffee</a>
                    <?php endif; ?>
                    <button type="button" onclick="openRestoOrdersModal()" style="padding:8px 14px; background:#1a5276; color:#fff; border:none; border-radius:6px; font-weight:600; font-size:12px; cursor:pointer;"><i class="fas fa-receipt"></i> Today's restaurant orders</button>
                    <?php if (moduleEnabled('stock')): ?>
                    <a href="stock-orders.php" style="padding:8px 12px; background:#3a3a40; color:#fff; text-decoration:none; border-radius:6px; font-weight:600;"><i class="fas fa-list"></i> Full orders list</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Today's restaurant orders modal -->
        <div class="overlay modal-overlay" data-modal id="restoOrdersOverlay">
            <div class="modal modal-content" style="width:960px; max-width:96vw; display:flex; flex-direction:column; max-height:94vh; overflow:hidden;">
                <div class="modal-head modal-header" style="flex-shrink:0; flex-wrap:wrap; gap:8px;">
                    <div>
                        <h3><i class="fas fa-receipt"></i> Today's Restaurant Orders</h3>
                        <div style="font-size:12px; color:#6c757d; margin-top:3px;" id="restoOrdersSummary">—</div>
                    </div>
                    <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                        <button onclick="loadRestoOrders('all')" id="rfAll" style="padding:5px 12px; font-size:11px; border:1px solid #d6d8db; border-radius:14px; cursor:pointer; font-weight:600; background:#edf2f7;">All</button>
                        <button onclick="loadRestoOrders('pending')" id="rfPending" style="padding:5px 12px; font-size:11px; border:1px solid #d6d8db; border-radius:14px; cursor:pointer; font-weight:600; background:#fff;">Pending</button>
                        <button onclick="loadRestoOrders('paid')" id="rfPaid" style="padding:5px 12px; font-size:11px; border:1px solid #d6d8db; border-radius:14px; cursor:pointer; font-weight:600; background:#fff;">Paid</button>
                        <button class="close modal-close" onclick="document.getElementById('restoOrdersOverlay').classList.remove('show');" style="margin-left:6px;">&times;</button>
                    </div>
                </div>
                <div id="restoOrdersTable" style="flex:1; overflow-y:auto; padding:18px 20px;">
                    <p style="color:#6c757d; text-align:center; padding:20px 0;">Loading…</p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Station note modal -->
    <div class="overlay modal-overlay" data-modal id="stationNoteOverlay">
        <div class="modal modal-content" style="width:480px;max-width:96vw;">
            <div class="modal-head modal-header">
                <h3><i class="fas fa-paper-plane"></i> Station note</h3><button class="close modal-close" onclick="closeStationNoteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <label>Send to</label>
                <select id="stationNoteTarget">
                    <option value="kitchen">Kitchen</option>
                    <option value="bar">Bar</option>
                    <option value="coffee_bar">Coffee Bar</option>
                </select>
                <label style="margin-top:10px;">Priority</label>
                <div style="display:flex;gap:8px;margin-bottom:6px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:7px 14px;border:1px solid #ddd;border-radius:14px;font-size:13px;user-select:none;">
                        <input type="radio" name="stationNotePriority" id="snPriorityNormal" value="normal" checked> Normal
                    </label>
                    <label id="snPriorityUrgentLabel" style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:7px 14px;border:2px solid #c82333;border-radius:14px;font-size:13px;color:#c82333;font-weight:600;user-select:none;">
                        <input type="radio" name="stationNotePriority" id="snPriorityUrgent" value="urgent"> <i class="fas fa-exclamation-triangle"></i> Urgent
                    </label>
                </div>
                <label>Link to order <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
                <select id="stationNoteOrderId" style="margin-bottom:8px;">
                    <option value="">— No specific order —</option>
                    <?php if ($lastOrderId && $lastOrderRef): ?>
                        <option value="<?php echo (int)$lastOrderId; ?>" data-ref="<?php echo htmlspecialchars($lastOrderRef); ?>">Last order: <?php echo htmlspecialchars($lastOrderRef); ?></option>
                    <?php endif; ?>
                    <?php foreach ($openTabs as $ot): ?>
                        <option value="<?php echo (int)$ot['id']; ?>" data-ref="<?php echo htmlspecialchars($ot['reference']); ?>">
                            <?php
                            $label = $ot['reference'];
                            if (!empty($ot['table_number'])) $label .= ' · Table ' . $ot['table_number'];
                            elseif (!empty($ot['customer_name'])) $label .= ' · ' . $ot['customer_name'];
                            echo htmlspecialchars($label);
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="stationNoteHistoryWrap" style="margin:2px 0 10px;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc;">
                    <div style="padding:8px 10px;border-bottom:1px solid #e5e7eb;">
                        <strong style="font-size:12px;color:#4b5563;display:flex;align-items:center;gap:6px;"><i class="fas fa-message"></i> Order station thread</strong>
                        <div id="stationNoteHistoryMeta" style="margin-top:4px;font-size:11px;color:#6b7280;">Select an order to view station messages received and sent.</div>
                    </div>
                    <div id="stationNoteHistoryList" style="max-height:170px;overflow-y:auto;padding:8px 10px;">
                        <div style="font-size:12px;color:#9ca3af;">No order selected.</div>
                    </div>
                </div>
                <label>Message</label>
                <input type="text" id="stationNoteText" maxlength="255" placeholder="Table 5 needs extra napkins">
                <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:10px;">
                    <?php foreach (['Hold last order', 'Extra napkins', 'Guest allergy', 'Rush pickup', 'Call waiter', 'Table ready'] as $t): ?>
                        <button type="button" onclick="addStationNoteChip('<?php echo htmlspecialchars($t, ENT_QUOTES); ?>')" style="padding:7px 10px; background:#f0f0f0; border:1px solid #ddd; border-radius:14px; cursor:pointer; font-size:12px;"><?php echo htmlspecialchars($t); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-foot modal-footer">
                <button type="button" class="btn-cancel" onclick="closeStationNoteModal()">Cancel</button>
                <button type="button" class="btn-confirm" id="stationNoteSendBtn" onclick="sendStationNote()"><i class="fas fa-paper-plane"></i> Send note</button>
            </div>
        </div>
    </div>

    <!-- Line note modal (per-item modifier) -->
    <div class="overlay modal-overlay" data-modal id="noteOverlay">
        <div class="modal modal-content" style="width:420px;max-width:96vw;">
            <div class="modal-head modal-header">
                <h3><i class="fas fa-comment-dots"></i> Item note</h3><button class="close modal-close" onclick="document.getElementById('noteOverlay').classList.remove('show');">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin:0 0 8px; font-size:13px; color:#6c757d;" id="noteItemName"></p>
                <textarea id="noteText" rows="3" placeholder="No onion · extra cheese · well done · allergy:peanuts"></textarea>
                <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                    <?php foreach (['No onion', 'Extra cheese', 'Well done', 'Medium', 'No ice', 'Spicy', 'Allergy'] as $t): ?>
                        <button type="button" onclick="addNoteChip('<?php echo $t; ?>')" style="padding:6px 10px; background:#f0f0f0; border:1px solid #ddd; border-radius:14px; cursor:pointer; font-size:12px;"><?php echo $t; ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-foot modal-footer">
                <button type="button" class="btn-cancel" onclick="document.getElementById('noteOverlay').classList.remove('show');">Cancel</button>
                <button type="button" class="btn-confirm" onclick="saveNote()">Save note</button>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="toast err" data-pos-server-toast>
            <span class="toast__message"><?php echo htmlspecialchars($error); ?></span>
            <button type="button" class="toast__close" aria-label="Close notification" onclick="this.closest('.toast').remove()"><i class="fas fa-xmark" aria-hidden="true"></i></button>
        </div>
    <?php endif; ?>

    <script>
        /* ── RHPoll: persistent polling helper ──────────────────────────────
   Keep polling active even when this tab is not focused so notifications
   continue to arrive without switching back to POS/KDS tabs. */
        const RHPoll = (() => {
            const timers = new Map(); // fn -> interval id
            return {
                every(fn, ms) {
                    if (timers.has(fn)) return;
                    const id = setInterval(() => {
                        try {
                            fn();
                        } catch (e) {
                            /* keep scheduler alive */
                        }
                    }, ms);
                    timers.set(fn, id);
                }
            };
        })();
        const menuList = <?php echo json_encode($menuList); ?>;
        const stockSnapshot = <?php echo json_encode($stockSnapshot); ?>;
        const posDeals = <?php echo json_encode(array_values($posDealsRaw), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        <?php if (!empty($posDealsError)): ?>
        console.error('[POS Deals] DB error loading deals:', <?php echo json_encode($posDealsError); ?>);
        <?php endif; ?>
        console.log('[POS Deals] Loaded', posDeals.length, 'deal(s):', posDeals);
        const currencySymbol = <?php echo json_encode($currency_symbol); ?>;
        const posKitchenOpen = <?php echo $kitchenWindow['is_open_now'] ? 'true' : 'false'; ?>;
        const posKitchenHours = <?php echo json_encode($kitchenWindow['opens_at'] . ' – ' . $kitchenWindow['closes_at']); ?>;
        const posBarOpen = <?php echo $barWindow['is_open_now'] ? 'true' : 'false'; ?>;
        const posBarHours = <?php echo json_encode($barWindow['opens_at'] . ' – ' . $barWindow['closes_at']); ?>;
        const posCsrfToken = <?php echo json_encode($csrf_token); ?>;
        const posLastOrderId    = <?php echo (int)$lastOrderId; ?>;
        const posLastOrderEmail = <?php echo json_encode($lastOrderCustomerEmail); ?>;
        const posLastOrderPhone = <?php echo json_encode($lastOrderCustomerPhone); ?>;
        const posJustParked     = <?php echo $justParked ? 'true' : 'false'; ?>;
        const posUserId = <?php echo (int)$user['id']; ?>;
        const posCurrentUserName = <?php echo json_encode($user['full_name'] ?: $user['username']); ?>;
        const posCanManageTabs = <?php echo $isManagerOrAdmin ? 'true' : 'false'; ?>;
        const posCanRefund     = <?php echo $posCanRefund ? 'true' : 'false'; ?>;
        const posCanDiscount   = <?php echo $posCanDiscount ? 'true' : 'false'; ?>;
        const posCanToggle86   = <?php echo $posCanToggle86 ? 'true' : 'false'; ?>;
        const posCanForceServe = <?php echo $posCanForceServe ? 'true' : 'false'; ?>;
        const posCanFloat      = <?php echo $posCanFloat ? 'true' : 'false'; ?>;
        const posCanAssignBarcode = <?php echo $posCanToggle86 ? 'true' : 'false'; ?>;
        const posVatEnabled = <?php echo in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true) ? 'true' : 'false'; ?>;
        const posVatRate = <?php echo json_encode((float)getSetting('vat_rate')); ?>;
        const posServerErrorMessage = <?php echo json_encode($error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const posRestaurantTables = <?php echo json_encode($restaurantTables, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const posCheckedInRooms = <?php echo json_encode($checkedInRooms, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const POS_LIVE_POLL_MS = 1000;
        const POS_INBOX_POLL_MS = 700;
        const POS_NOTIFICATION_DURATION_MS = 120000;
        const POS_API_BASE = '../api/';

        function posApiUrl(path) {
            return POS_API_BASE + String(path || '').replace(/^\/+/, '');
        }

        function posNewClientUuidValue() {
            return (window.crypto && typeof window.crypto.randomUUID === 'function') ?
                window.crypto.randomUUID() :
                'pos-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
        }

        function posEnsureClientUuid(form) {
            if (!form || form.querySelector('[name="client_uuid"]')) return;
            const field = document.createElement('input');
            field.type = 'hidden';
            field.name = 'client_uuid';
            field.value = posNewClientUuidValue();
            form.appendChild(field);
        }

        /* Force a fresh client_uuid. The AJAX park flow stays on the page, so without
           this a second "Fire" would reuse the prior UUID and the server's idempotency
           guard would return the first order instead of opening a new tab. */
        function posRefreshClientUuid(form) {
            if (!form) return;
            const existing = form.querySelector('[name="client_uuid"]');
            if (existing) existing.parentNode.removeChild(existing);
            posEnsureClientUuid(form);
        }

        document.addEventListener('submit', e => {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if ((form.method || '').toLowerCase() === 'post') posEnsureClientUuid(form);
        }, true);

        let cart = [];
        let activeCat = '__ALL__';
        /* Menu mode: 'restaurant' (default) shows items flagged show_pos. 'room_service' shows items
           flagged show_room_service and auto-pins the order context to Room service. */
        let menuMode = 'restaurant';

        function menuItemVisibleInMode(m) {
            return menuMode === 'room_service' ? !!m.show_rs : !!m.show_pos;
        }

        function rebuildCategories() {
            const wrap = document.getElementById('cats');
            if (!wrap) return;
            const counts = {
                __ALL__: 0
            };
            const order = ['__ALL__'];
            menuList.forEach(m => {
                if (!menuItemVisibleInMode(m)) return;
                counts.__ALL__++;
                if (!(m.category in counts)) {
                    counts[m.category] = 0;
                    order.push(m.category);
                }
                counts[m.category]++;
            });
            if (!(activeCat in counts)) activeCat = '__ALL__';
            wrap.innerHTML = order.map(key => {
                const label = key === '__ALL__' ? 'All' : key;
                const active = key === activeCat ? ' active' : '';
                const sel = key === activeCat ? 'true' : 'false';
                return `<button class="cat-btn${active}" data-cat="${escHtml(key)}" onclick="selectCat(this)" role="option" aria-selected="${sel}">${escHtml(label)} <span class="count">${counts[key]}</span></button>`;
            }).join('');
            /* Sync the dropdown label (used on narrow screens) */
            const lbl = document.getElementById('catDropdownLabel');
            if (lbl) lbl.textContent = activeCat === '__ALL__' ? 'All Items' : activeCat;
        }

        function setMenuMode(mode) {
            if (mode !== 'restaurant' && mode !== 'room_service') return;
            const menuModeSwitch = document.querySelector('.menu-mode');
            if (menuModeSwitch) menuModeSwitch.setAttribute('data-active-mode', mode);
            if (mode === menuMode) return;
            menuMode = mode;
            document.querySelectorAll('.menu-mode-btn').forEach(b => {
                const isActive = b.dataset.mode === mode;
                b.classList.toggle('is-active', isActive);
                b.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            /* Lock / unlock service-type chips based on menu mode.
               In Room Service mode only the Room chip is selectable — the others are
               greyed-out and pointer-events disabled so the cashier cannot accidentally
               mix a restaurant order type with the RS menu. */
            document.querySelectorAll('.ctx-chip').forEach(c => {
                const isRoomChip = c.dataset.type === 'room_service';
                if (mode === 'room_service') {
                    if (!isRoomChip) {
                        c.classList.add('is-locked');
                        c.setAttribute('disabled', '');
                        c.setAttribute('aria-disabled', 'true');
                    }
                } else {
                    c.classList.remove('is-locked');
                    c.removeAttribute('disabled');
                    c.removeAttribute('aria-disabled');
                }
            });
            /* Tie the order-type chip to the selected menu mode so RS orders fire to room_service. */
            const ot = document.getElementById('ctxOrderType');
            if (mode === 'room_service') {
                if (typeof setServiceType === 'function') setServiceType('room_service');
            } else if (ot && ot.value === 'room_service') {
                if (typeof setServiceType === 'function') setServiceType('walk_in');
            }
            rebuildCategories();
            renderMenu();
        }

        const POS_READY_SEEN_KEY = 'rh_pos_ready_seen_v2_u' + posUserId;
        const POS_INBOX_SEEN_KEY = 'rh_pos_inbox_seen_v2_u' + posUserId;

        function posLoadSeenSet(key) {
            try {
                const parsed = JSON.parse(localStorage.getItem(key) || '[]');
                return new Set(Array.isArray(parsed) ? parsed.map(String) : []);
            } catch (e) {
                return new Set();
            }
        }

        function posSaveSeenSet(key, set) {
            try {
                localStorage.setItem(key, JSON.stringify(Array.from(set).slice(-500)));
            } catch (e) {
                /* storage may be unavailable in private mode */
            }
        }

        function posRememberSeen(set, key, value) {
            set.add(String(value));
            posSaveSeenSet(key, set);
        }
        const _seenReadyNotifications = posLoadSeenSet(POS_READY_SEEN_KEY);

        /* ── Ready-order notification system ─────────────────────────── */
        let _notifGranted = (typeof Notification !== 'undefined' && Notification.permission === 'granted');

        RHSounds.init();

        function posRequestNotifPermission() {
            if (typeof Notification === 'undefined') return;
            if (Notification.permission === 'default') {
                Notification.requestPermission().then(p => {
                    _notifGranted = (p === 'granted');
                });
            }
        }

        function posShowNotification(title, body, vibrate) {
            if (vibrate && navigator.vibrate && (!window.RHSounds || typeof RHSounds.isInteractionUnlocked !== 'function' || RHSounds.isInteractionUnlocked())) {
                navigator.vibrate([300, 100, 300, 100, 600]);
            }
            RHNotif.show({
                title,
                body,
                type: vibrate ? 'urgent' : 'success',
                source: 'Station',
                sound: true,
                duration: POS_NOTIFICATION_DURATION_MS,
            });
        }

        function posCompactItemsSummary(summary, maxItems = 4) {
            const parts = String(summary || '')
                .split(',')
                .map(part => part.trim())
                .filter(Boolean);
            if (!parts.length) return '';
            if (parts.length <= maxItems) return parts.join(', ');
            return parts.slice(0, maxItems).join(', ') + ' +' + (parts.length - maxItems) + ' more';
        }

        function posReadyLocationLabel(notification) {
            const raw = String(notification.table_label || '').trim();
            if (!raw) return '';
            if (/^table\s+/i.test(raw)) return raw;
            if (/^\d+$/.test(raw)) return 'Table ' + raw;
            return raw;
        }

        function posReadyNotificationBody(notification) {
            const message = String(notification.message || '').trim();
            const tableLabel = posReadyLocationLabel(notification);
            const itemSummary = posCompactItemsSummary(notification.items_summary || '', 4);
            const itemCount = parseInt(notification.item_count || 0, 10) || 0;
            const lines = [];
            if (message) lines.push(message);
            if (tableLabel) lines.push('Table/Location: ' + tableLabel);
            if (!itemSummary) return lines.join('\n');
            const countLabel = itemCount > 0 ? itemCount + ' item' + (itemCount === 1 ? '' : 's') : 'Items';
            lines.push(countLabel + ': ' + itemSummary);
            return lines.join('\n');
        }

        let _notifInFlight = false;
        async function pollReadyNotifications() {
            if (_notifInFlight) return;
            _notifInFlight = true;
            try {
                const payload = new URLSearchParams();
                payload.set('csrf_token', posCsrfToken);
                const r = await fetch(posApiUrl('pos-notifications.php?action=poll'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                        'X-CSRF-Token': posCsrfToken,
                    },
                    body: payload.toString(),
                    credentials: 'same-origin'
                });
                if (!r.ok) return;
                const j = await r.json().catch(() => null);
                if (j && j.ok && j.notifications && j.notifications.length) {
                    j.notifications.forEach(n => {
                        const readyKey = [n.order_id, n.station, n.reference || n.id].join(':');
                        if (_seenReadyNotifications.has(readyKey)) return;
                        posRememberSeen(_seenReadyNotifications, POS_READY_SEEN_KEY, readyKey);
                        const stnLabel = {
                            kitchen: 'Kitchen',
                            bar: 'Bar',
                            coffee_bar: 'Coffee Bar'
                        };
                        const src = stnLabel[n.station] || 'Station';
                        RHNotif.show({
                            title: n.vibrate ? '🔔 Your order is ready!' : '✅ Order Ready',
                            body: posReadyNotificationBody(n),
                            type: n.vibrate ? 'urgent' : 'success',
                            source: src,
                            duration: POS_NOTIFICATION_DURATION_MS,
                            sound: false,
                        });
                        if (n.vibrate) RHSounds.play('urgent');
                        else RHSounds.play('normal');
                        pollMyOrders(true);
                        refreshOpenTabs(false);
                    });
                }
            } catch (e) {
                /* swallow — network blip */
            } finally {
                _notifInFlight = false;
            }
        }

        // Request permission on first interaction
        document.addEventListener('click', function onFirstClick() {
            posRequestNotifPermission();
            document.removeEventListener('click', onFirstClick);
        }, {
            once: true
        });

        // Kept active in background tabs so runner notifications arrive quickly.
        RHPoll.every(pollReadyNotifications, POS_LIVE_POLL_MS);
        setTimeout(pollReadyNotifications, 250);
        /* ── End notification system ──────────────────────────────────── */

        /* ── Station inbox (replies + ack feed) ───────────────────────── */
        let _inboxVisible = false;
        let _inboxLastMsgs = [];
        const _seenReplies = posLoadSeenSet(POS_INBOX_SEEN_KEY);
        const _inboxReplyInFlight = new Set();
        const POS_INBOX_STATION_LABELS = {
            kitchen: 'Kitchen',
            bar: 'Bar',
            coffee_bar: 'Coffee Bar'
        };

        function posInboxReplyKey(message) {
            return 'reply:' + message.id;
        }

        function posInboxDirectKey(message) {
            return 'direct:' + message.id;
        }

        function posInboxStationLabel(station) {
            return POS_INBOX_STATION_LABELS[station] || station || 'Station';
        }

        function isPosInboxDirectMessage(message) {
            return !!message && message.source === 'station' && parseInt(message.to_user_id, 10) > 0;
        }

        function isPosInboxDirectPending(message) {
            return isPosInboxDirectMessage(message) && parseInt(message.pos_acknowledged || 0, 10) !== 1;
        }

        function isCollectionDirectMessage(message) {
            if (!isPosInboxDirectMessage(message)) return false;
            const text = String(message?.message || '').toLowerCase();
            return text.includes('ready for collection') || text.includes('for collection');
        }

        function normalizePosInboxMessages(messages) {
            return (messages || []).filter(m => !isPosInboxDirectMessage(m) || isPosInboxDirectPending(m) || !!m.reply_message);
        }

        function posInboxFindMessage(messageId) {
            const msgId = parseInt(messageId, 10) || 0;
            if (msgId <= 0) return null;
            return (_inboxLastMsgs || []).find(m => (parseInt(m.id, 10) || 0) === msgId) || null;
        }

        function posInboxApplyLocalReply(messageId, replyText) {
            const msgId = parseInt(messageId, 10) || 0;
            const reply = String(replyText || '').trim();
            if (msgId <= 0 || !reply || !Array.isArray(_inboxLastMsgs)) return;
            const ts = new Date().toISOString().slice(0, 19).replace('T', ' ');
            _inboxLastMsgs = _inboxLastMsgs.map(m => {
                if ((parseInt(m.id, 10) || 0) !== msgId) return m;
                return {
                    ...m,
                    reply_message: reply,
                    replied_at: ts,
                    replied_by_name: posCurrentUserName || m.replied_by_name || 'You',
                };
            });
            if (_inboxVisible) renderPosInbox(_inboxLastMsgs);
            updatePosInboxBadgesFromMessages(_inboxLastMsgs);
            _syncPosMobileBadges();
        }

        function posInboxPendingCount(messages) {
            return (messages || []).filter(isPosInboxDirectPending).length;
        }

        function posInboxOrderContext(message) {
            const parts = [];
            const type = String(message.order_type || '').trim();
            if (type) {
                const label = type.replace(/_/g, ' ');
                parts.push(label.charAt(0).toUpperCase() + label.slice(1));
            }
            if (message.table_number) parts.push('Table ' + message.table_number);
            if (message.room_number) parts.push('Room ' + message.room_number);
            if (message.customer_name) parts.push(message.customer_name);
            return parts.join(' · ');
        }

        function posInboxOrderThreadKey(message) {
            const orderId = parseInt(message?.order_id, 10) || 0;
            if (orderId > 0) return 'order:' + orderId;
            const orderRef = String(message?.order_ref || '').trim().toLowerCase();
            if (orderRef) return 'ref:' + orderRef;
            return 'msg:' + (parseInt(message?.id, 10) || 0);
        }

        function posInboxMessageTimeValue(message) {
            if (!message || !message.created_at) return 0;
            const parsed = Date.parse(String(message.created_at).replace(' ', 'T'));
            return Number.isFinite(parsed) ? parsed : 0;
        }

        function groupPosInboxDirectThreads(messages) {
            const map = new Map();
            (messages || []).forEach(message => {
                if (!isPosInboxDirectMessage(message)) return;
                const key = posInboxOrderThreadKey(message);
                if (!map.has(key)) map.set(key, []);
                map.get(key).push(message);
            });
            const threads = Array.from(map.values()).map(items => {
                return items.sort((a, b) => posInboxMessageTimeValue(a) - posInboxMessageTimeValue(b));
            });
            return threads.sort((a, b) => {
                const aLast = a[a.length - 1] || null;
                const bLast = b[b.length - 1] || null;
                return posInboxMessageTimeValue(bLast) - posInboxMessageTimeValue(aLast);
            });
        }

        function markInboxMessagesSeen(messages) {
            let changed = false;
            messages.forEach(message => {
                if (message.reply_message) {
                    _seenReplies.add(posInboxReplyKey(message));
                    changed = true;
                }
                if (message.source === 'station' && message.to_user_id) {
                    _seenReplies.add(posInboxDirectKey(message));
                    changed = true;
                }
            });
            if (changed) posSaveSeenSet(POS_INBOX_SEEN_KEY, _seenReplies);
        }

        function updatePosInboxBadgesFromMessages(messages) {
            _inboxBadgeUpdate(posInboxPendingCount(messages));
        }

        const POS_QUICK_REPLIES = ['On it!', 'Coming right up', '5 minutes', 'Almost ready', 'Noted, thanks', 'One moment please'];

        function posInboxReplyComposerHtml(message, _unused = false) {
            const msgId = parseInt(message.id, 10) || 0;
            if (msgId <= 0) return '';
            const stationLabel = posInboxStationLabel(message.station);
            const inputId = 'posInboxReplyInput-' + msgId;
            const draft = _posReplyDrafts[String(msgId)] || '';
            const quickChips = POS_QUICK_REPLIES.map(r =>
                `<button type="button" onclick="quickSendPosReply(${msgId},'${r.replace(/'/g,"\\'")}',this)" ` +
                `style="background:#f5f0ea;border:1px solid #c9b99a;border-radius:14px;padding:4px 10px;font-size:11px;font-weight:600;color:#6b4f2a;cursor:pointer;white-space:nowrap;transition:background .12s;"` +
                ` onmouseover="this.style.background='#e8ddd0'" onmouseout="this.style.background='#f5f0ea'">${escHtml(r)}</button>`
            ).join('');
            return `<div style="margin-top:8px;display:grid;gap:6px;">` +
                `<div style="display:flex;flex-wrap:wrap;gap:5px;">${quickChips}</div>` +
                `<div style="display:flex;gap:6px;align-items:stretch;">` +
                `<input type="text" id="${inputId}" maxlength="255" placeholder="Or type a custom reply…" ` +
                `value="${escHtml(draft)}" ` +
                `oninput="_posReplyDrafts['${msgId}'] = this.value;" ` +
                `onkeydown="if(event.key==='Enter'){event.preventDefault();sendPosInboxReply(${msgId});}" ` +
                `style="flex:1;min-width:0;min-height:38px;border:1px solid #d1d5db;border-radius:7px;padding:7px 10px;font-size:12px;color:#111;">` +
                `<button type="button" data-pos-reply="${msgId}" onclick="sendPosInboxReply(${msgId}, this)" style="min-height:38px;padding:6px 12px;border:1px solid #8B7355;background:#8B7355;color:#fff;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;">` +
                `<i class="fas fa-paper-plane" style="margin-right:5px;"></i>Send</button>` +
                `</div>` +
                `<button type="button" data-pos-ack="${msgId}" onclick="ackPosInboxMessage(${msgId}, this)" ` +
                `style="min-height:34px;padding:6px 12px;background:transparent;border:1px solid #d1d5db;border-radius:7px;font-size:12px;color:#6c757d;cursor:pointer;font-weight:600;display:flex;align-items:center;gap:6px;transition:background .12s,color .12s;" ` +
                `onmouseover="this.style.background='#fee2e2';this.style.color='#b91c1c';this.style.borderColor='#fca5a5'" ` +
                `onmouseout="this.style.background='transparent';this.style.color='#6c757d';this.style.borderColor='#d1d5db'">` +
                `<i class="fas fa-times-circle"></i> Dismiss</button>` +
                `</div>`;
        }

        async function quickSendPosReply(msgId, text, triggerEl = null) {
            const input = document.getElementById('posInboxReplyInput-' + msgId);
            if (input) input.value = text;
            _posReplyDrafts[String(msgId)] = text;
            return sendPosInboxReply(msgId, triggerEl);
        }

        function dismissPosInboxThread(messageIds) {
            const ids = new Set((messageIds || []).map(id => parseInt(id, 10) || 0).filter(Boolean));
            if (!ids.size) return;
            _inboxLastMsgs = (_inboxLastMsgs || []).filter(m => !ids.has(parseInt(m.id, 10) || 0));
            if (_inboxVisible) renderPosInbox(_inboxLastMsgs);
            updatePosInboxBadgesFromMessages(_inboxLastMsgs);
            _syncPosMobileBadges();
        }

        function posInboxMarkReadBtn(messageIds, label = 'Mark as read') {
            // IDs are integers — JSON.stringify produces e.g. [123,456], no HTML-unsafe chars
            const idsJson = JSON.stringify((messageIds || []).map(id => parseInt(id, 10) || 0).filter(Boolean));
            return `<button type="button" onclick="dismissPosInboxThread(${idsJson})" ` +
                `style="margin-top:8px;display:inline-flex;align-items:center;gap:6px;padding:6px 14px;` +
                `background:#f3f4f6;border:1px solid #d1d5db;border-radius:7px;font-size:12px;font-weight:600;` +
                `color:#6c757d;cursor:pointer;transition:background .12s,color .12s;" ` +
                `onmouseover="this.style.background='#e5e7eb';this.style.color='#374151'" ` +
                `onmouseout="this.style.background='#f3f4f6';this.style.color='#6c757d'">` +
                `<i class="fas fa-check"></i> ${escHtml(label)}</button>`;
        }

        function togglePosInbox(forceState = null) {
            const opening = typeof forceState === 'boolean' ? forceState : !_inboxVisible;
            _inboxVisible = opening;
            const widget = document.getElementById('posInboxWidget');
            const panel = document.getElementById('posInboxPanel');
            if (widget) widget.classList.toggle('is-mobile-open', _inboxVisible && window.innerWidth <= 640);
            if (panel) panel.style.display = _inboxVisible ? 'block' : 'none';
            if (typeof window.__posClampFloatingWidgets === 'function') {
                setTimeout(window.__posClampFloatingWidgets, 0);
            }
            if (_inboxVisible) {
                renderPosInbox(_inboxLastMsgs);
                markInboxMessagesSeen(_inboxLastMsgs);
                updatePosInboxBadgesFromMessages(_inboxLastMsgs);
            }
            _syncPosMobileBadges();
        }

        function _inboxBadgeUpdate(count) {
            const badge = document.getElementById('posInboxBadge');
            const widget = document.getElementById('posInboxWidget');
            if (!badge || !widget) return;
            const showWidget = window.innerWidth > 640 || (_inboxLastMsgs && _inboxLastMsgs.length > 0) || count > 0;
            widget.style.display = showWidget ? 'flex' : 'none';
            if (showWidget && typeof window.__posClampFloatingWidgets === 'function') {
                setTimeout(window.__posClampFloatingWidgets, 0);
            }
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.style.display = 'flex';
            } else {
                badge.textContent = '';
                badge.style.display = 'none';
            }
            _syncPosMobileBadges();
        }

        function showPosInboxAttention(urgent = false) {
            const widget = document.getElementById('posInboxWidget');
            const button = document.getElementById('posInboxBtn');
            if (widget) widget.style.display = 'flex';
            if (typeof window.__posClampFloatingWidgets === 'function') {
                setTimeout(window.__posClampFloatingWidgets, 0);
            }
            if (!button) return;
            button.style.background = urgent ? '#7f1d1d' : '#1d4a2e';
            button.style.boxShadow = urgent ?
                '0 0 0 4px rgba(220,38,38,.24), 0 8px 24px rgba(220,38,38,.5)' :
                '0 0 0 4px rgba(34,197,94,.22), 0 8px 24px rgba(34,197,94,.35)';
            clearTimeout(window._posInboxAttentionTimer);
            window._posInboxAttentionTimer = setTimeout(() => {
                button.style.background = '#1d4a2e';
                button.style.boxShadow = '0 4px 14px rgba(0,0,0,.35)';
            }, urgent ? 12000 : 8000);
        }

        const _posReplyDrafts = {};

        function renderPosInbox(messages) {
            const list = document.getElementById('posInboxList');
            if (!list) return;
            // Preserve any in-progress typed replies before rebuilding
            list.querySelectorAll('[id^="posInboxReplyInput-"]').forEach(el => {
                const id = el.id.replace('posInboxReplyInput-', '');
                if (id && el.value) _posReplyDrafts[id] = el.value;
            });
            if (!messages.length) {
                list.innerHTML = '<p style="text-align:center;color:#888;padding:20px;font-size:13px;">No active station notes right now.</p>';
                return;
            }

            const directThreads = groupPosInboxDirectThreads(messages);
            const directMessageIds = new Set();
            directThreads.forEach(thread => thread.forEach(msg => directMessageIds.add(parseInt(msg.id, 10) || 0)));

            const directHtml = directThreads.map(thread => {
                const lead = thread[thread.length - 1] || null;
                if (!lead) return '';
                const leadId = parseInt(lead.id, 10) || 0;
                const isUrgent = thread.some(m => m.priority === 'urgent');
                const pendingMessages = thread.filter(isPosInboxDirectPending);
                const latestPending = pendingMessages[pendingMessages.length - 1] || null;
                const pendingCount = pendingMessages.length;

                const orderRef = lead.order_ref ?
                    escHtml(lead.order_ref) :
                    (lead.order_id ? 'Order #' + escHtml(String(lead.order_id)) : 'Order not linked');
                const orderContext = posInboxOrderContext(lead);
                const dishSummary = lead.order_items_summary ?
                    `<div style="margin-top:4px;font-size:12px;color:#5b3f1d;"><i class="fas fa-utensils" style="margin-right:4px;"></i>${escHtml(lead.order_items_summary)}</div>` :
                    (lead.order_id ? '<div style="margin-top:4px;font-size:11px;color:#7c5a2b;"><i class="fas fa-utensils"></i> Dish details unavailable.</div>' : '');

                const threadMessagesHtml = thread.map(m => {
                    const stationName = posInboxStationLabel(m.station);
                    const t = m.created_at ? stationNoteFmtTime(m.created_at) : '';
                    const pending = isPosInboxDirectPending(m);
                    const replyLine = m.reply_message ?
                        `<div style="margin-top:6px;padding:6px 8px;background:#f0fdf4;border-left:3px solid #22c55e;border-radius:0 6px 6px 0;font-size:12px;color:#166534;"><i class="fas fa-reply"></i> <strong>You:</strong> ${escHtml(m.reply_message)}${m.replied_at ? ` <span style="color:#6b7280;">${escHtml(stationNoteFmtTime(m.replied_at))}</span>` : ''}</div>` : '';
                    const actionLine = !pending ?
                        `<div style="margin-top:5px;font-size:11px;color:#166534;"><i class="fas fa-check-circle"></i> Actioned${m.pos_acknowledged_at ? ` · ${escHtml(stationNoteFmtTime(m.pos_acknowledged_at))}` : ''}</div>` :
                        '<div style="margin-top:5px;font-size:11px;color:#92400e;"><i class="fas fa-hourglass-half"></i> Waiting for FOH action</div>';

                    return `<div style="margin-top:8px;padding:8px 9px;background:#fffdfa;border:1px solid #f3e7cd;border-radius:8px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
                            <span style="font-size:11px;font-weight:700;color:${m.priority === 'urgent' ? '#c82333' : '#92400e'};text-transform:uppercase;letter-spacing:.04em;"><i class="fas fa-user-chef"></i> ${escHtml(stationName)}${m.priority === 'urgent' ? ' · URGENT' : ''}</span>
                            <span style="font-size:11px;color:#9ca3af;">${escHtml(t)}</span>
                        </div>
                        <div style="margin-top:4px;font-size:13px;color:#111;font-weight:500;"><i class="fas fa-comment-dots" style="margin-right:4px;color:${m.priority === 'urgent' ? '#c82333' : '#92400e'};"></i>${escHtml(m.message || '')}</div>
                        ${replyLine}
                        ${actionLine}
                    </div>`;
                }).join('');

                const replyComposer = latestPending ? posInboxReplyComposerHtml(latestPending, true) : '';

                return `<div style="padding:10px 14px;border-bottom:1px solid #f3f4f6;background:#fffbeb;border-left:4px solid ${isUrgent ? '#c82333' : '#f59e0b'};">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;gap:8px;">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:${isUrgent ? '#c82333' : '#92400e'};"><i class="fas fa-layer-group"></i> Order Thread${isUrgent ? ' · URGENT' : ''}</span>
                        <span style="font-size:11px;color:#9ca3af;">${thread.length} msg${thread.length === 1 ? '' : 's'}</span>
                    </div>
                    <div style="margin-bottom:3px;"><span style="display:inline-block;background:#fef3c7;border:1px solid #fde68a;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700;color:#92400e;"><i class="fas fa-receipt" style="margin-right:3px;"></i>${orderRef}</span>${pendingCount > 0 ? `<span style="display:inline-block;margin-left:6px;background:#fff7ed;border:1px solid #fed7aa;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700;color:#9a3412;"><i class="fas fa-bell"></i> ${pendingCount} pending</span>` : ''}</div>
                    ${orderContext ? `<div style="margin-top:3px;font-size:11px;color:#7c5a2b;"><i class="fas fa-location-dot" style="margin-right:4px;"></i>${escHtml(orderContext)}</div>` : ''}
                    ${dishSummary}
                    ${threadMessagesHtml}
                    ${replyComposer}
                    ${!latestPending && leadId > 0 ? posInboxMarkReadBtn(thread.map(m => m.id), 'Remove from list') : ''}
                </div>`;
            }).join('');

            const otherHtml = messages.filter(m => !directMessageIds.has(parseInt(m.id, 10) || 0)).map(m => {
                const stn = posInboxStationLabel(m.station);
                const msgId = parseInt(m.id, 10) || 0;
                const time = m.created_at ?
                    new Date(m.created_at.replace(' ', 'T')).toLocaleTimeString([], {
                        hour: '2-digit',
                        minute: '2-digit'
                    }) :
                    '';
                const isUrgent = m.priority === 'urgent';
                /* Direct note from station → THIS POS user (source='station' + to_user_id set).
                   These are not replies — they are fresh notes initiated by the station. */
                const isStationDirect = isPosInboxDirectMessage(m);
                if (isStationDirect) {
                    return '';
                }
                let statusHtml = '';
                if (m.reply_message) {
                    const rt = m.replied_at ?
                        new Date(m.replied_at.replace(' ', 'T')).toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit'
                        }) :
                        '';
                    statusHtml = `<div style="margin-top:5px;padding:5px 8px;background:#f0fdf4;border-left:3px solid #22c55e;border-radius:0 5px 5px 0;font-size:12px;color:#166534;">
                <i class="fas fa-reply"></i> <strong>${escHtml(m.replied_by_name || stn)}</strong>: ${escHtml(m.reply_message)} <span style="color:#9ca3af;">${rt}</span></div>`;
                } else if (parseInt(m.is_acknowledged) === 1) {
                    const at = m.acknowledged_at ?
                        new Date(m.acknowledged_at.replace(' ', 'T')).toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit'
                        }) :
                        '';
                    statusHtml = `<div style="margin-top:4px;font-size:11px;color:#22c55e;"><i class="fas fa-check-double"></i> Acknowledged ${at}</div>`;
                } else if (m.seen_at) {
                    statusHtml = `<div style="margin-top:4px;font-size:11px;color:#6c757d;"><i class="fas fa-eye"></i> Seen by station — awaiting action</div>`;
                } else {
                    statusHtml = `<div style="margin-top:4px;font-size:11px;color:#f59e0b;"><i class="fas fa-hourglass-half"></i> Not yet seen by station</div>`;
                }
                const isPending = !m.reply_message && parseInt(m.is_acknowledged || 0, 10) !== 1;
                const replyComposer = isPending ? posInboxReplyComposerHtml(m, false) : posInboxMarkReadBtn([m.id]);
                return `<div style="padding:10px 14px;border-bottom:1px solid #f3f4f6;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;">
                <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:${isUrgent ? '#c82333' : '#6c757d'};">${escHtml(stn)}${isUrgent ? ' · <i class="fas fa-exclamation-triangle"></i> URGENT' : ''}</span>
                <span style="font-size:11px;color:#9ca3af;">${time}</span>
            </div>
            ${m.order_ref ? `<div style="margin-bottom:3px;"><span style="display:inline-block;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700;color:#374151;"><i class="fas fa-receipt" style="margin-right:3px;"></i>${escHtml(m.order_ref)}</span></div>` : ''}
            <div style="font-size:13px;color:#111;">${escHtml(m.message || '')}</div>
            ${statusHtml}
            ${replyComposer}
        </div>`;
            }).join('');

            list.innerHTML = directHtml + otherHtml;
            // Restore any in-progress drafts that survived the rebuild
            Object.entries(_posReplyDrafts).forEach(([id, val]) => {
                const el = list.querySelector('#posInboxReplyInput-' + id);
                if (el && !el.value) el.value = val;
            });
        }

        async function sendPosInboxReply(messageId, triggerButton = null, markActioned = false) {
            const msgId = parseInt(messageId, 10) || 0;
            if (msgId <= 0 || _inboxReplyInFlight.has(msgId)) return false;

            const message = posInboxFindMessage(msgId);
            if (!message || !message.station) {
                posToastReady('Message context expired. Refreshing inbox.', true);
                setTimeout(pollStationReplies, 150);
                return false;
            }

            const input = document.getElementById('posInboxReplyInput-' + msgId);
            const reply = (input?.value || '').trim().slice(0, 255);
            if (!reply) {
                posToastReady('Type a reply first.', true);
                input?.focus();
                return false;
            }

            const replyBtn = triggerButton || document.querySelector('[data-pos-reply="' + msgId + '"]');
            const replyAckBtn = document.querySelector('[data-pos-reply-ack="' + msgId + '"]');
            const actionBtn = document.querySelector('[data-pos-ack="' + msgId + '"]');

            _inboxReplyInFlight.add(msgId);
            [replyBtn, replyAckBtn, actionBtn, input].forEach(el => {
                if (!el) return;
                el.disabled = true;
                el.setAttribute('aria-busy', 'true');
            });

            try {
                const fd = new FormData();
                fd.append('csrf_token', posCsrfToken);
                if (isPosInboxDirectMessage(message)) {
                    // Reply directly onto the original station→POS thread row so KDS
                    // can render the response as part of the same conversation.
                    fd.append('action', 'station_reply');
                    fd.append('station', String(message.station));
                    fd.append('message_id', String(msgId));
                    fd.append('reply', reply);
                } else {
                    fd.append('action', 'send_message');
                    fd.append('station', String(message.station));
                    fd.append('message', reply);
                    fd.append('priority', message.priority === 'urgent' ? 'urgent' : 'normal');
                    const orderId = parseInt(message.order_id || 0, 10) || 0;
                    if (orderId > 0) fd.append('order_id', String(orderId));
                }

                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 12000);
                const r = await fetch('../api/kds-action.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    signal: controller.signal
                });
                clearTimeout(timeoutId);
                const j = await r.json();
                if (!j.ok) {
                    posToastReady(j.error || 'Reply failed.', true);
                    return false;
                }

                posInboxApplyLocalReply(msgId, reply);

                if (input) input.value = '';
                delete _posReplyDrafts[String(msgId)];
                const stationName = posInboxStationLabel(message.station);

                if (markActioned && isPosInboxDirectPending(message)) {
                    const acked = await ackPosInboxMessage(msgId, null, {
                        suppressToast: true,
                        skipRepoll: true
                    });
                    if (!acked) posToastReady('Reply sent, but action mark failed.', true);
                    else posToastReady('Reply sent and action recorded for ' + stationName + '.', false);
                } else {
                    posToastReady('Reply sent to ' + stationName + '.', false);
                }

                setTimeout(() => {
                    pollStationReplies().catch(() => {
                        // Background refresh failure should not block the cashier after a successful reply.
                    });
                }, 80);
                return true;
            } catch (e) {
                if (e && e.name === 'AbortError') posToastReady('Reply timed out. Please retry.', true);
                else posToastReady('Network error sending reply.', true);
                return false;
            } finally {
                _inboxReplyInFlight.delete(msgId);
                [replyBtn, replyAckBtn, actionBtn, input].forEach(el => {
                    if (!el) return;
                    el.disabled = false;
                    el.removeAttribute('aria-busy');
                });
            }
        }

        async function ackPosInboxMessage(messageId, triggerButton = null, options = {}) {
            const suppressToast = !!(options && options.suppressToast);
            const skipRepoll = !!(options && options.skipRepoll);
            const msgId = parseInt(messageId, 10) || 0;
            if (msgId <= 0) return false;

            // Optimistic: mark acknowledged locally right away
            const prevMsgs = (_inboxLastMsgs || []).slice();
            const ts = new Date().toISOString().slice(0, 19).replace('T', ' ');
            _inboxLastMsgs = prevMsgs.map(m => {
                if ((parseInt(m.id, 10) || 0) !== msgId) return m;
                return { ...m, pos_acknowledged: 1, pos_acknowledged_at: ts, pos_acknowledged_by: posUserId };
            });
            if (_inboxVisible) renderPosInbox(_inboxLastMsgs);
            updatePosInboxBadgesFromMessages(_inboxLastMsgs);
            _syncPosMobileBadges();

            const fd = new FormData();
            fd.append('csrf_token', posCsrfToken);
            fd.append('action', 'ack_pos_message');
            fd.append('station', 'kitchen');
            fd.append('message_id', String(msgId));
            try {
                const r = await fetch('../api/kds-action.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) {
                    // Rollback
                    _inboxLastMsgs = prevMsgs;
                    if (_inboxVisible) renderPosInbox(_inboxLastMsgs);
                    updatePosInboxBadgesFromMessages(_inboxLastMsgs);
                    _syncPosMobileBadges();
                    if (!suppressToast) posToastReady(j.error || 'Could not save action — restored.', true);
                    return false;
                }
                if (!suppressToast) posToastReady('FOH action recorded.', false);
                if (!skipRepoll) setTimeout(pollStationReplies, 300);
                return true;
            } catch (e) {
                _inboxLastMsgs = prevMsgs;
                if (_inboxVisible) renderPosInbox(_inboxLastMsgs);
                updatePosInboxBadgesFromMessages(_inboxLastMsgs);
                _syncPosMobileBadges();
                if (!suppressToast) posToastReady('Network error — action not saved.', true);
                return false;
            }
        }

        let _inboxPollInFlight = false;
        let _inboxPollQueued = false;
        async function pollStationReplies() {
            if (_inboxPollInFlight) {
                _inboxPollQueued = true;
                return;
            }
            _inboxPollInFlight = true;
            try {
                const fd = new FormData();
                fd.append('csrf_token', posCsrfToken);
                fd.append('action', 'get_pos_inbox');
                fd.append('station', 'kitchen'); /* station param required by API but ignored for this action */
                const r = await fetch('../api/kds-action.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) return;
                const msgs = normalizePosInboxMessages(j.messages || []);
                _inboxLastMsgs = msgs;
                const widget = document.getElementById('posInboxWidget');
                if (widget) {
                    widget.style.display = msgs.length > 0 ? 'flex' : 'none';
                }
                /* Detect new replies we haven't notified about yet */
                const newReplies = msgs.filter(m => m.reply_message && !_seenReplies.has(posInboxReplyKey(m)));
                if (newReplies.length > 0 && !_inboxVisible) {
                    showPosInboxAttention(false);
                    newReplies.forEach(m => {
                        posRememberSeen(_seenReplies, POS_INBOX_SEEN_KEY, posInboxReplyKey(m));
                        const stn = posInboxStationLabel(m.station);
                        RHNotif.show({
                            title: stn + ' replied to your note',
                            body: '\u201c' + m.reply_message + '\u201d',
                            type: 'success',
                            source: stn,
                            duration: POS_NOTIFICATION_DURATION_MS,
                        });
                    });
                    pollMyOrders(true);
                }
                /* Detect new direct station→POS notes (initiated by the station, not replies). */
                const newDirect = msgs.filter(m => isPosInboxDirectPending(m) && !_seenReplies.has(posInboxDirectKey(m)));
                if (newDirect.length > 0) {
                    const hasUrgentDirect = newDirect.some(m => m.priority === 'urgent' || isCollectionDirectMessage(m));
                    showPosInboxAttention(hasUrgentDirect);
                    newDirect.forEach(m => {
                        posRememberSeen(_seenReplies, POS_INBOX_SEEN_KEY, posInboxDirectKey(m));
                        const stn = posInboxStationLabel(m.station);
                        const isCollectionAlert = isCollectionDirectMessage(m);
                        const isUrgent = m.priority === 'urgent' || isCollectionAlert;
                        const detail = [posCompactItemsSummary(m.order_items_summary || '', 4), m.message].filter(Boolean).join(' · ');
                        RHNotif.show({
                            title: isCollectionAlert ? '\u{1F514} READY FOR COLLECTION: ' + stn + (m.order_ref ? ' \u00b7 ' + m.order_ref : '') : (isUrgent ? '\u26a0 URGENT: ' + stn + (m.order_ref ? ' \u00b7 ' + m.order_ref : '') : stn + ' note' + (m.order_ref ? ' \u00b7 ' + m.order_ref : '')),
                            body: detail || m.message || '',
                            type: isUrgent ? 'urgent' : 'info',
                            source: stn,
                            duration: POS_NOTIFICATION_DURATION_MS,
                            sound: false,
                        });
                        RHSounds.play(isUrgent ? 'urgent' : 'normal');
                    });
                    pollMyOrders(true);
                    if (!_inboxVisible) {
                        _inboxVisible = true;
                        const panel = document.getElementById('posInboxPanel');
                        if (panel) panel.style.display = 'block';
                        renderPosInbox(msgs);
                    }
                }
                updatePosInboxBadgesFromMessages(msgs);
                if (_inboxVisible) {
                    renderPosInbox(msgs);
                    markInboxMessagesSeen(msgs);
                }
                const stationNoteOverlay = document.getElementById('stationNoteOverlay');
                const stationNoteOrderId = document.getElementById('stationNoteOrderId')?.value || '';
                if (stationNoteOverlay?.classList.contains('show') && stationNoteOrderId) {
                    loadStationNoteOrderHistory(stationNoteOrderId, {
                        silent: true
                    });
                }
                _syncPosMobileBadges();
            } catch (e) {
                /* swallow */
            } finally {
                _inboxPollInFlight = false;
                if (_inboxPollQueued) {
                    _inboxPollQueued = false;
                    setTimeout(pollStationReplies, 60);
                }
            }
        }

        RHPoll.every(pollStationReplies, POS_INBOX_POLL_MS);
        setTimeout(pollStationReplies, 400); /* initial check shortly after load */
        /* ── End station inbox ────────────────────────────────────────── */

        /* ── My Orders live tracker ──────────────────────────────────────
            Polls ../api/kds-action.php?action=get_my_orders every few seconds and renders the
            current cashier's orders with kitchen + payment status, table/customer
            info, age, and total. Click an order to open its receipt. */
        let _myOrdersVisible = false;
        let _myOrdersPollInFlight = false;
        let _myOrdersLast = [];

        function clampMyOrdersWidgetIntoView() {
            if (typeof window.__posClampFloatingWidgets !== 'function') return;
            window.__posClampFloatingWidgets();
            setTimeout(() => {
                if (typeof window.__posClampFloatingWidgets === 'function') {
                    window.__posClampFloatingWidgets();
                }
            }, 60);
        }

        function toggleMyOrders(forceState = null) {
            const opening = typeof forceState === 'boolean' ? forceState : !_myOrdersVisible;
            _myOrdersVisible = opening;
            const widget = document.getElementById('myOrdersWidget');
            const panel = document.getElementById('myOrdersPanel');
            if (widget) widget.classList.toggle('is-mobile-open', _myOrdersVisible && window.innerWidth <= 640);
            if (panel) panel.style.display = _myOrdersVisible ? 'block' : 'none';
            if (_myOrdersVisible) {
                pollMyOrders();
                clampMyOrdersWidgetIntoView();
            }
        }

        async function openMyOrdersCurrentDetail() {
            showPosActionLoader('Loading orders…', 'Fetching your orders for today.');
            try {
                await pollMyOrders(true);
            } finally {
                hidePosActionLoader();
            }
            toggleMyOrders(true);
            // List is now visible — user taps a row to open its detail
        }

        function myOrderStatusPill(o) {
            const map = {
                placed: {
                    lbl: 'Placed',
                    bg: '#fef3c7',
                    fg: '#92400e',
                    icon: 'fa-receipt'
                },
                preparing: {
                    lbl: 'Preparing',
                    bg: '#dbeafe',
                    fg: '#1e40af',
                    icon: 'fa-fire'
                },
                ready: {
                    lbl: 'Ready',
                    bg: '#bbf7d0',
                    fg: '#166534',
                    icon: 'fa-bell'
                },
                served: {
                    lbl: 'Served',
                    bg: '#e5e7eb',
                    fg: '#374151',
                    icon: 'fa-check-double'
                },
                paid: {
                    lbl: 'Paid',
                    bg: '#d1fae5',
                    fg: '#065f46',
                    icon: 'fa-check-circle'
                },
                voided: {
                    lbl: 'Voided',
                    bg: '#fee2e2',
                    fg: '#991b1b',
                    icon: 'fa-ban'
                },
                cancelled: {
                    lbl: 'Cancelled',
                    bg: '#f3f4f6',
                    fg: '#6b7280',
                    icon: 'fa-circle-xmark'
                },
                empty: {
                    lbl: 'Empty',
                    bg: '#f3f4f6',
                    fg: '#6b7280',
                    icon: 'fa-circle-question'
                }
            };
            const c = map[o.kitchen_status] || map.placed;
            return `<span style="display:inline-flex;align-items:center;gap:4px;background:${c.bg};color:${c.fg};font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:.04em;"><i class="fas ${c.icon}"></i> ${c.lbl}</span>`;
        }

        function myOrderPaymentPill(o) {
            if (o.status === 'voided' || o.status === 'cancelled') {
                const label = o.status === 'voided' ? 'Voided' : 'Cancelled';
                return `<span style="display:inline-flex;align-items:center;gap:4px;background:#fee2e2;color:#991b1b;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:10px;"><i class="fas fa-ban"></i> ${label}</span>`;
            }
            if (o.status === 'paid') {
                const m = (o.payment_method || '').replace(/_/g, ' ');
                return `<span style="display:inline-flex;align-items:center;gap:4px;background:#d1fae5;color:#065f46;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:10px;"><i class="fas fa-check-circle"></i> Paid${m ? ' · ' + escHtml(m) : ''}</span>`;
            }
            /* 'refunded' was missing, so a refunded order fell through to the opened_as_tab
               branch below and was labelled "Open tab" — telling staff that money still needed
               collecting on an order that had already been paid AND handed back. */
            if (o.status === 'refunded') {
                return `<span style="display:inline-flex;align-items:center;gap:4px;background:#ede9fe;color:#5b21b6;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:10px;"><i class="fas fa-rotate-left"></i> Refunded</span>`;
            }
            if (o.opened_as_tab == 1 || o.opened_as_tab === '1') {
                return `<span style="display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:10px;"><i class="fas fa-clock"></i> Open tab</span>`;
            }
            return `<span style="display:inline-flex;align-items:center;gap:4px;background:#fee2e2;color:#991b1b;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:10px;"><i class="fas fa-circle-exclamation"></i> Unpaid</span>`;
        }

        function fmtAgeFromIso(iso) {
            if (!iso) return '';
            const t = Date.parse(iso.replace(' ', 'T'));
            if (isNaN(t)) return '';
            const sec = Math.max(0, Math.floor((Date.now() - t) / 1000));
            if (sec < 60) return sec + 's ago';
            const m = Math.floor(sec / 60);
            if (m < 60) return m + 'm ago';
            const h = Math.floor(m / 60);
            return h + 'h ' + (m % 60) + 'm ago';
        }

        function myOrderKdsBreakdown(o) {
            const buckets = [
                ['pending', 'Pending'],
                ['preparing', 'Preparing'],
                ['ready', 'Ready'],
                ['collection', 'Collection'],
                ['served', 'Served']
            ];
            const parts = buckets.map(([key, label]) => {
                const value = parseInt(o['items_' + key] || 0, 10) || 0;
                return value > 0 ? (label + ': ' + value) : '';
            }).filter(Boolean);
            if (!parts.length) {
                const kitchen = String(o.kitchen_status || o.status || 'placed').replace(/_/g, ' ');
                return 'KDS: ' + kitchen;
            }
            return 'KDS: ' + parts.join(' · ');
        }

        function renderMyOrders(orders) {
            const list = document.getElementById('myOrdersList');
            const chip = document.getElementById('myOrdersTotalChip');
            const badge = document.getElementById('myOrdersBadge');
            if (!list) return;
            const todayCount = orders.length;
            if (chip) chip.textContent = todayCount + (todayCount === 1 ? ' order' : ' orders');
            if (badge) {
                if (todayCount > 0) {
                    badge.textContent = todayCount > 99 ? '99+' : String(todayCount);
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
            }
            if (!orders.length) {
                list.innerHTML = '<p style="text-align:center;color:#9ca3af;padding:30px 18px;font-size:13px;">No orders fired yet today. Tap items in the menu to start a new order.</p>';
                if (_myOrdersVisible) clampMyOrdersWidgetIntoView();
                return;
            }
            list.innerHTML = orders.map(o => {
                const total = fmtMoney(o.total_amount || 0);
                const items = parseInt(o.item_total || 0, 10);
                const where = o.table_number ? 'Table ' + escHtml(o.table_number) : (o.order_type || 'walk_in').replace(/_/g, ' ');
                const customer = o.customer_name ? ' · ' + escHtml(o.customer_name) : '';
                const itemSummary = posCompactItemsSummary(o.items_summary || '', 4);
                const stationSummary = (o.stations || []).map(st => {
                    const done = !!st.done;
                    return `<span style="display:inline-flex;align-items:center;gap:4px;background:${done ? '#ecfdf5' : '#fff7ed'};color:${done ? '#047857' : '#9a3412'};border:1px solid ${done ? '#a7f3d0' : '#fed7aa'};border-radius:9px;padding:1px 7px;font-size:10.5px;font-weight:700;"><i class="fas ${done ? 'fa-check' : 'fa-hourglass-half'}"></i>${escHtml(st.label || st.station || 'Station')}${done ? ' done' : ' · ' + parseInt(st.pending || 0, 10) + ' pending'}</span>`;
                }).join('');
                const progress = parseInt(o.progress_percent || 0, 10);
                const isLive = o.kitchen_status === 'placed' || o.kitchen_status === 'preparing';
                const ringClr = o.kitchen_status === 'ready' ? '#16a34a' :
                    o.kitchen_status === 'preparing' ? '#2563eb' :
                    o.kitchen_status === 'served' ? '#9ca3af' : '#f59e0b';
                const kdsSummary = escHtml(myOrderKdsBreakdown(o));
                return `<a href="#" onclick="openTabDetail(${o.id}); return false;" style="display:block;padding:11px 14px;border-bottom:1px solid #f3f4f6;text-decoration:none;color:inherit;cursor:pointer;${isLive ? 'background:#fffdf7;' : ''}">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;gap:8px;">
                <div style="display:flex;align-items:center;gap:8px;min-width:0;">
                    <strong style="font-size:13px;color:#1f1f24;white-space:nowrap;">${escHtml(o.reference)}</strong>
                    <span style="font-size:11.5px;color:#6c757d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(where)}${customer}</span>
                </div>
                <strong style="font-size:13px;color:#1f1f24;white-space:nowrap;">${currencySymbol} ${total}</strong>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                ${myOrderStatusPill(o)}
                ${myOrderPaymentPill(o)}
                <span style="font-size:10.5px;color:#9ca3af;margin-left:auto;"><i class="fas fa-clock"></i> ${escHtml(fmtAgeFromIso(o.created_at))} · ${items} item${items===1?'':'s'}</span>
            </div>
            <div style="font-size:10.5px;color:#5f6368;line-height:1.35;margin:-1px 0 6px;"><i class="fas fa-sitemap"></i> ${kdsSummary}</div>
            ${itemSummary ? `<div style="font-size:10.5px;color:#6b7280;line-height:1.35;margin:-1px 0 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><i class="fas fa-list-check"></i> ${escHtml(itemSummary)}</div>` : ''}
            ${stationSummary ? `<div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin:-1px 0 6px;">${stationSummary}</div>` : ''}
            ${isLive || o.kitchen_status === 'ready' ? `<div style="height:5px;background:#f3f4f6;border-radius:3px;overflow:hidden;"><div style="height:100%;width:${progress}%;background:${ringClr};transition:width .4s ease;"></div></div>` : ''}
                <div style="display:flex;justify-content:flex-end;gap:6px;flex-wrap:wrap;margin-top:8px;">
                    <span style="display:inline-flex;align-items:center;gap:4px;background:#f8fafc;color:#334155;border:1px solid #e2e8f0;border-radius:999px;padding:2px 8px;font-size:10px;font-weight:700;"><i class="fas fa-receipt"></i> Details</span>
                    ${(String(o.opened_as_tab) === '1' && String(o.status) === 'placed') ? '<span style="display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:999px;padding:2px 8px;font-size:10px;font-weight:700;"><i class="fas fa-credit-card"></i> Settle later</span>' : ''}
                </div>
        </a>`;
            }).join('');
            _syncPosMobileBadges();
            if (_myOrdersVisible) clampMyOrdersWidgetIntoView();
        }

        async function pollMyOrders(force = false) {
            if (_myOrdersPollInFlight) return;
            _myOrdersPollInFlight = true;
            try {
                const payload = new URLSearchParams();
                payload.set('csrf_token', posCsrfToken);
                const r = await fetch(posApiUrl('kds-action.php?action=get_my_orders'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                        'X-CSRF-Token': posCsrfToken,
                    },
                    body: payload.toString(),
                    credentials: 'same-origin'
                });
                if (!r.ok) return;
                const j = await r.json().catch(() => null);
                if (!j || !j.ok) return;
                _myOrdersLast = Array.isArray(j.orders) ? j.orders : [];
                renderMyOrders(_myOrdersLast);
            } catch (e) {
                /* ignore — silent background poll */
            } finally {
                _myOrdersPollInFlight = false;
            }
        }

        RHPoll.every(pollMyOrders, POS_LIVE_POLL_MS);
        setTimeout(pollMyOrders, 250);
        /* ── End my orders ────────────────────────────────────────────── */

        let _stationNoteHistoryReqToken = 0;

        function stationNoteResolveOrderLabel(orderId) {
            const select = document.getElementById('stationNoteOrderId');
            if (!select) return '';
            const needle = String(orderId || '');
            const option = Array.from(select.options).find(opt => String(opt.value) === needle);
            if (!option) return '';
            return (option.dataset.ref || option.textContent || '').trim();
        }

        function stationNoteFmtTime(iso) {
            if (!iso) return '';
            const ts = new Date(String(iso).replace(' ', 'T'));
            if (Number.isNaN(ts.getTime())) return '';
            return ts.toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function renderStationNoteOrderHistory(orderId, messages) {
            const metaEl = document.getElementById('stationNoteHistoryMeta');
            const listEl = document.getElementById('stationNoteHistoryList');
            if (!metaEl || !listEl) return;

            const normalizedOrderId = parseInt(orderId || 0, 10) || 0;
            if (!normalizedOrderId) {
                metaEl.textContent = 'Select an order to view station messages received and sent.';
                listEl.innerHTML = '<div style="font-size:12px;color:#9ca3af;">No order selected.</div>';
                return;
            }

            const orderLabel = stationNoteResolveOrderLabel(normalizedOrderId) || ('Order #' + normalizedOrderId);
            const rows = [];
            let receivedCount = 0;
            let pendingActionCount = 0;

            (messages || []).forEach(message => {
                const station = posInboxStationLabel(message.station);
                const isUrgent = message.priority === 'urgent';
                const isDirectReceived = message.source === 'station' && parseInt(message.to_user_id || 0, 10) > 0;

                if (message.source !== 'station') {
                    rows.push({
                        direction: 'sent',
                        station,
                        text: String(message.message || ''),
                        timestamp: message.created_at,
                        urgent: isUrgent,
                        status: 'Outbound note',
                    });
                }

                if (message.reply_message) {
                    receivedCount += 1;
                    rows.push({
                        direction: 'received',
                        station,
                        text: String(message.reply_message || ''),
                        timestamp: message.replied_at || message.created_at,
                        urgent: isUrgent,
                        status: 'Station reply',
                    });
                }

                if (isDirectReceived) {
                    receivedCount += 1;
                    const pendingAction = parseInt(message.pos_acknowledged || 0, 10) !== 1;
                    if (pendingAction) pendingActionCount += 1;
                    const actionStamp = message.pos_acknowledged_at ? (' · ' + stationNoteFmtTime(message.pos_acknowledged_at)) : '';
                    rows.push({
                        direction: 'received',
                        station,
                        text: String(message.message || ''),
                        timestamp: message.created_at,
                        urgent: isUrgent,
                        status: pendingAction ? 'Pending action' : ('Actioned' + actionStamp),
                    });
                }
            });

            const toMs = value => {
                if (!value) return 0;
                const dt = new Date(String(value).replace(' ', 'T'));
                return Number.isNaN(dt.getTime()) ? 0 : dt.getTime();
            };
            rows.sort((a, b) => toMs(b.timestamp) - toMs(a.timestamp));

            metaEl.textContent = orderLabel + ' · Received ' + receivedCount + (receivedCount === 1 ? ' message' : ' messages') + ' from stations' + (pendingActionCount > 0 ? (' · ' + pendingActionCount + ' pending action') : '');

            if (!rows.length) {
                listEl.innerHTML = '<div style="font-size:12px;color:#9ca3af;">No station traffic yet for this order.</div>';
                return;
            }

            listEl.innerHTML = rows.map(row => {
                const isReceived = row.direction === 'received';
                const bg = isReceived ? '#fffbeb' : '#f5f3ff';
                const border = isReceived ? '#f59e0b' : '#8B7355';
                const icon = isReceived ? 'fa-inbox' : 'fa-paper-plane';
                const dirLabel = isReceived ? 'Received' : 'Sent';
                const urgentTag = row.urgent ? '<span style="margin-left:6px;background:#c82333;color:#fff;border-radius:9px;padding:1px 6px;font-size:10px;font-weight:700;">URGENT</span>' : '';
                const time = stationNoteFmtTime(row.timestamp);
                return `<div style="padding:7px 8px;margin-bottom:6px;border-left:3px solid ${border};border-radius:6px;background:${bg};">` +
                    `<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">` +
                    `<span style="font-size:11px;font-weight:700;color:#4b5563;"><i class="fas ${icon}" style="margin-right:4px;"></i>${dirLabel} · ${escHtml(row.station)}${urgentTag}</span>` +
                    `<span style="font-size:10px;color:#9ca3af;">${escHtml(time)}</span>` +
                    `</div>` +
                    `<div style="margin-top:3px;font-size:12px;color:#111;line-height:1.35;">${escHtml(row.text)}</div>` +
                    `<div style="margin-top:2px;font-size:10.5px;color:#6b7280;">${escHtml(row.status)}</div>` +
                    `</div>`;
            }).join('');
        }

        async function loadStationNoteOrderHistory(orderId = '', options = {}) {
            const silent = !!(options && options.silent);
            const normalizedOrderId = parseInt(orderId || 0, 10) || 0;
            const listEl = document.getElementById('stationNoteHistoryList');
            const metaEl = document.getElementById('stationNoteHistoryMeta');

            if (!normalizedOrderId) {
                renderStationNoteOrderHistory('', []);
                return;
            }

            const reqToken = ++_stationNoteHistoryReqToken;
            if (!silent && listEl) {
                listEl.innerHTML = '<div style="font-size:12px;color:#9ca3af;"><i class="fas fa-spinner fa-spin"></i> Loading order thread…</div>';
            }
            try {
                const fd = new FormData();
                fd.append('csrf_token', posCsrfToken);
                fd.append('action', 'get_order_station_messages');
                fd.append('order_id', String(normalizedOrderId));
                fd.append('limit', '60');
                const r = await fetch('../api/kds-action.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (reqToken !== _stationNoteHistoryReqToken) return;
                if (!j.ok) {
                    if (!silent && metaEl && listEl) {
                        metaEl.textContent = 'Could not load station thread.';
                        listEl.innerHTML = '<div style="font-size:12px;color:#c82333;">' + escHtml(j.error || 'Request failed') + '</div>';
                    }
                    return;
                }
                renderStationNoteOrderHistory(normalizedOrderId, j.messages || []);
            } catch (e) {
                if (reqToken !== _stationNoteHistoryReqToken) return;
                if (!silent && metaEl && listEl) {
                    metaEl.textContent = 'Could not load station thread.';
                    listEl.innerHTML = '<div style="font-size:12px;color:#c82333;">Network error while loading order messages.</div>';
                }
            }
        }

        function openStationNoteModal(target = 'kitchen', presetOrderId = '') {
            const targetEl = document.getElementById('stationNoteTarget');
            const textEl = document.getElementById('stationNoteText');
            const orderEl = document.getElementById('stationNoteOrderId');
            if (targetEl) targetEl.value = target;
            if (textEl) textEl.value = '';
            if (orderEl && presetOrderId) orderEl.value = String(presetOrderId);
            loadStationNoteOrderHistory(orderEl?.value || '');
            document.getElementById('stationNoteOverlay').classList.add('show');
            setTimeout(() => textEl?.focus(), 60);
        }

        function closeStationNoteModal() {
            document.getElementById('stationNoteOverlay').classList.remove('show');
        }

        function addStationNoteChip(text) {
            const input = document.getElementById('stationNoteText');
            if (!input) return;
            input.value = (input.value ? input.value + ' · ' : '') + text;
            input.focus();
        }
        async function sendStationNote() {
            const target = document.getElementById('stationNoteTarget')?.value || 'kitchen';
            const input = document.getElementById('stationNoteText');
            const btn = document.getElementById('stationNoteSendBtn');
            const orderSel = document.getElementById('stationNoteOrderId');
            const message = (input?.value || '').trim().slice(0, 255);
            const priority = document.querySelector('input[name="stationNotePriority"]:checked')?.value || 'normal';
            const orderId = orderSel?.value || '';
            if (!message) {
                posToastReady('Type a station note first.', true);
                return;
            }
            if (btn) btn.disabled = true;
            try {
                const fd = new FormData();
                fd.append('csrf_token', posCsrfToken);
                fd.append('action', 'send_message');
                fd.append('station', target);
                fd.append('message', message);
                fd.append('priority', priority);
                if (orderId) fd.append('order_id', orderId);
                const r = await fetch('../api/kds-action.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (!j.ok) {
                    posToastReady(j.error || 'Station note failed.', true);
                    return;
                }
                closeStationNoteModal();
                const label = {
                    kitchen: 'Kitchen',
                    bar: 'Bar',
                    coffee_bar: 'Coffee Bar'
                } [target] || 'Station';
                posToastReady(label + ' note sent' + (priority === 'urgent' ? ' [URGENT]' : '') + '.', priority === 'urgent');
                if (orderId) loadStationNoteOrderHistory(orderId, {
                    silent: true
                });
                document.getElementById('posInboxWidget').style.display = 'flex';
                setTimeout(pollStationReplies, 800); /* quick re-poll so the message appears in inbox */
            } catch (e) {
                posToastReady('Network error sending station note.', true);
            } finally {
                if (btn) btn.disabled = false;
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('stationNoteOrderId')?.addEventListener('change', e => {
                loadStationNoteOrderHistory(e.target?.value || '');
            });
            document.getElementById('stationNoteText')?.addEventListener('keydown', e => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    sendStationNote();
                }
            });
        });

        function maxPortions(id, type) {
            const k = type + ':' + id;
            return Object.prototype.hasOwnProperty.call(stockSnapshot, k) ? stockSnapshot[k] : null;
        }

        function escHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        function fmtMoney(n) {
            return Number(n || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function fmtMoneyNoDecimals(n) {
            return Number(n || 0).toLocaleString('en-US', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }

        function applyShiftStats(shift) {
            if (!shift || typeof shift !== 'object') return;

            const orders = parseInt(shift.orders_today, 10) || 0;
            const revenue = Number(shift.revenue_today || 0);
            const cash = Number(shift.cash_today || 0);
            const mobile = Number(shift.mobile_today || 0);
            const card = Number(shift.card_today || 0);
            const settledCount = parseInt(shift.settled_from_tabs_count, 10) || 0;
            const settledAmount = Number(shift.settled_from_tabs_amount || 0);

            const setText = (id, text) => {
                const el = document.getElementById(id);
                if (el) el.textContent = text;
            };

            setText('tbStatOrders', String(orders));
            setText('tbStatRevenue', currencySymbol + ' ' + fmtMoneyNoDecimals(revenue));
            setText('tbStatCash', currencySymbol + ' ' + fmtMoneyNoDecimals(cash));
            setText('tbStatMobile', currencySymbol + ' ' + fmtMoneyNoDecimals(mobile));
            setText('tbStatCard', currencySymbol + ' ' + fmtMoneyNoDecimals(card));

            const setExpected = (id, amount) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.dataset.amount = amount.toFixed(2);
                el.textContent = currencySymbol + ' ' + fmtMoney(amount);
            };

            setExpected('expCash', cash);
            setExpected('expMobile', mobile);
            setExpected('expCard', card);

            setText('closeShiftOrdersCount', String(orders));
            setText('closeShiftSettledCount', String(settledCount));
            setText('closeShiftSettledAmount', currencySymbol + ' ' + fmtMoney(settledAmount));
        }

        let _shiftStatsPollInFlight = false;
        async function refreshShiftStats(force = false) {
            if (_shiftStatsPollInFlight) return;
            if (document.hidden && !force) return;
            _shiftStatsPollInFlight = true;
            try {
                const response = await fetch('pos.php?ajax=shift_stats', {
                    credentials: 'same-origin'
                });
                if (!response.ok) return;
                const data = await response.json();
                if (!data || data.success !== true || !data.shift) return;
                applyShiftStats(data.shift);
            } catch (e) {
                /* network blip — next poll will retry */
            } finally {
                _shiftStatsPollInFlight = false;
            }
        }

        /* Global click-lock — prevents accidental double-taps on payment, fire-order
           and other hard-to-undo buttons. 1200ms cooldown per element + spinner overlay.
           Opt out with data-no-lock or anchors that lead elsewhere (links inside
           widgets are already lockable; we exclude tab/category buttons). */
        const _posClickLocks = new WeakMap();
        const POS_NAV_LOADER_KEY = 'rh_pos_nav_loader';
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('button, a, [role="button"], [data-lock-click]');
            if (!btn) return;
            if (btn.dataset.noLock !== undefined) return;
            if (btn.classList.contains('cat-btn') || btn.classList.contains('menu-btn')) return; /* high-frequency tap targets */
            if (btn.disabled || btn.getAttribute('aria-disabled') === 'true') return;
            const now = Date.now();
            const last = _posClickLocks.get(btn) || 0;
            if (now - last < 1200) {
                e.stopImmediatePropagation();
                e.preventDefault();
                return;
            }
            _posClickLocks.set(btn, now);
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');
            setTimeout(() => {
                btn.classList.remove('is-loading');
                btn.removeAttribute('aria-busy');
            }, 1200);
        }, true);

        function primePosNavigationLoader(title, text, options = {}) {
            const ttlMs = Math.max(2500, Number(options.ttlMs) || 9000);
            const payload = {
                title: String(title || 'Loading...').slice(0, 96),
                text: String(text || 'Please wait.').slice(0, 180),
                subtle: options.subtle !== false,
                expiresAt: Date.now() + ttlMs
            };
            try {
                sessionStorage.setItem(POS_NAV_LOADER_KEY, JSON.stringify(payload));
            } catch (_) {
                // Ignore storage failures.
            }
        }

        function showPosActionLoader(title, text, options = {}) {
            const loader = document.getElementById('posActionLoader');
            if (!loader) return;
            document.getElementById('posActionLoaderTitle').textContent = title || 'Loading…';
            document.getElementById('posActionLoaderText').textContent = text || 'Please wait.';
            loader.classList.toggle('pos-action-loader--subtle', !!options.subtle);
            loader.classList.add('show');
            // Safety net — auto-dismiss after 12s to prevent stuck overlay
            clearTimeout(window._posLoaderSafetyTimer);
            window._posLoaderSafetyTimer = setTimeout(() => hidePosActionLoader(), 12000);
        }

        function hidePosActionLoader() {
            clearTimeout(window._posLoaderSafetyTimer);
            const loader = document.getElementById('posActionLoader');
            if (!loader) return;
            loader.classList.remove('show');
            loader.classList.remove('pos-action-loader--subtle');
        }

        if (window.__rhPosNavLoaderState) {
            const navLoaderState = window.__rhPosNavLoaderState;
            showPosActionLoader(navLoaderState.title || 'Refreshing till...', navLoaderState.text || 'Loading the latest order details.', {
                subtle: navLoaderState.subtle !== false
            });
            window.__rhPosNavLoaderState = null;
            setTimeout(() => hidePosActionLoader(), 900);
        }

        function initPosCatsResizer() {
            const STORAGE_KEY = 'rh_pos_cats_width_v1';
            const MOBILE_QUERY = '(max-width: 1024px)';
            const grid = document.querySelector('.till-grid');
            const catsWrap = document.getElementById('catsWrap');
            if (!grid || !catsWrap || catsWrap.dataset.resizeReady === '1') return;
            catsWrap.dataset.resizeReady = '1';

            const clampWidth = (width) => {
                const minWidth = 182;
                const cart = document.getElementById('mainCart');
                const gridWidth = Math.max(0, Math.floor(grid.getBoundingClientRect().width));
                const cartWidth = (cart && !grid.classList.contains('order-menu-closed')) ? Math.floor(cart.getBoundingClientRect().width || 0) : 0;
                const reserveForMenu = 330;
                const maxWidth = Math.max(minWidth + 8, Math.min(460, gridWidth - cartWidth - reserveForMenu));
                return Math.max(minWidth, Math.min(maxWidth, Math.round(width)));
            };

            const applyWidth = (width, persist) => {
                if (window.matchMedia(MOBILE_QUERY).matches) return;
                const safeWidth = clampWidth(width);
                grid.style.setProperty('--pos-cats-width-live', safeWidth + 'px');
                if (persist) {
                    try {
                        localStorage.setItem(STORAGE_KEY, String(safeWidth));
                    } catch (_) {
                        // no-op
                    }
                }
            };

            const clearWidth = () => {
                grid.style.removeProperty('--pos-cats-width-live');
            };

            const handle = document.createElement('button');
            handle.type = 'button';
            handle.id = 'catsResizeHandle';
            handle.setAttribute('aria-label', 'Resize categories panel');
            handle.title = 'Drag to resize categories';
            handle.dataset.noLock = '1';
            handle.style.position = 'absolute';
            handle.style.top = '50%';
            handle.style.right = '-9px';
            handle.style.transform = 'translateY(-50%)';
            handle.style.width = '18px';
            handle.style.height = '60px';
            handle.style.borderRadius = '999px';
            handle.style.border = '1px solid rgba(255,255,255,.16)';
            handle.style.background = 'linear-gradient(180deg, rgba(255,255,255,.18), rgba(255,255,255,.06))';
            handle.style.boxShadow = '0 6px 16px rgba(0,0,0,.25)';
            handle.style.cursor = 'col-resize';
            handle.style.zIndex = '4';
            handle.style.touchAction = 'none';
            handle.style.display = 'none';
            handle.innerHTML = '<i class="fas fa-grip-lines-vertical" aria-hidden="true" style="font-size:11px; color:rgba(255,255,255,.82);"></i>';

            catsWrap.style.position = catsWrap.style.position || 'relative';
            catsWrap.appendChild(handle);

            const syncVisibility = () => {
                const isMobile = window.matchMedia(MOBILE_QUERY).matches;
                handle.style.display = isMobile ? 'none' : 'inline-flex';
                handle.style.alignItems = 'center';
                handle.style.justifyContent = 'center';
                if (isMobile) {
                    clearWidth();
                    return;
                }

                let saved = null;
                try {
                    saved = parseInt(localStorage.getItem(STORAGE_KEY) || '', 10);
                } catch (_) {
                    saved = null;
                }

                if (Number.isFinite(saved) && saved > 0) {
                    applyWidth(saved, false);
                }
            };

            const stopDrag = () => {
                document.body.style.userSelect = '';
                document.body.style.cursor = '';
                document.removeEventListener('pointermove', onMove, true);
                document.removeEventListener('pointerup', stopDrag, true);
                document.removeEventListener('pointercancel', stopDrag, true);
            };

            const onMove = (event) => {
                applyWidth(event.clientX - grid.getBoundingClientRect().left, true);
            };

            handle.addEventListener('pointerdown', (event) => {
                if (event.pointerType === 'mouse' && event.button !== 0) return;
                if (window.matchMedia(MOBILE_QUERY).matches) return;
                event.preventDefault();
                document.body.style.userSelect = 'none';
                document.body.style.cursor = 'col-resize';
                document.addEventListener('pointermove', onMove, true);
                document.addEventListener('pointerup', stopDrag, true);
                document.addEventListener('pointercancel', stopDrag, true);
            });

            handle.addEventListener('dblclick', () => {
                clearWidth();
                try {
                    localStorage.removeItem(STORAGE_KEY);
                } catch (_) {
                    // no-op
                }
            });

            window.addEventListener('resize', syncVisibility);
            window.syncPosCatsResize = syncVisibility;
            syncVisibility();
        }

        function showPosScopedLoader(scopeEl, message) {
            if (!scopeEl) return function() {
                return;
            };

            const computedPosition = window.getComputedStyle(scopeEl).position;
            const originalInlinePosition = scopeEl.style.position;
            if (computedPosition === 'static') {
                scopeEl.style.position = 'relative';
            }

            const overlay = document.createElement('div');
            overlay.setAttribute('role', 'status');
            overlay.setAttribute('aria-live', 'polite');
            overlay.setAttribute('aria-label', message || 'Refreshing');
            overlay.style.position = 'absolute';
            overlay.style.inset = '0';
            overlay.style.zIndex = '20';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.padding = '10px';
            overlay.style.background = 'linear-gradient(135deg, rgba(247,243,238,0.90), rgba(243,236,228,0.84))';
            overlay.style.backdropFilter = 'blur(1px)';
            overlay.style.pointerEvents = 'auto';
            overlay.style.borderRadius = 'inherit';

            const card = document.createElement('div');
            card.style.minWidth = 'min(320px, 92%)';
            card.style.maxWidth = '420px';
            card.style.borderRadius = '12px';
            card.style.border = '1px solid rgba(139,115,85,0.35)';
            card.style.background = 'rgba(255,255,255,0.92)';
            card.style.boxShadow = '0 10px 28px rgba(0,0,0,0.12)';
            card.style.padding = '12px 14px';

            const row = document.createElement('div');
            row.style.display = 'flex';
            row.style.alignItems = 'center';
            row.style.gap = '10px';

            const spinner = document.createElement('span');
            spinner.style.width = '17px';
            spinner.style.height = '17px';
            spinner.style.borderRadius = '999px';
            spinner.style.border = '2px solid rgba(139,115,85,0.30)';
            spinner.style.borderTopColor = 'rgba(111,91,65,0.95)';
            spinner.style.flex = '0 0 auto';

            const textWrap = document.createElement('div');
            textWrap.style.display = 'grid';
            textWrap.style.gap = '2px';

            const title = document.createElement('strong');
            title.textContent = 'Updating tabs';
            title.style.fontSize = '12px';
            title.style.letterSpacing = '0.08em';
            title.style.textTransform = 'uppercase';
            title.style.color = '#6f5b41';

            const text = document.createElement('span');
            text.textContent = message || 'Refreshing open tabs...';
            text.style.fontSize = '14px';
            text.style.color = '#2f2a24';

            const track = document.createElement('div');
            track.style.marginTop = '8px';
            track.style.height = '3px';
            track.style.borderRadius = '999px';
            track.style.overflow = 'hidden';
            track.style.background = 'rgba(139,115,85,0.16)';

            const bar = document.createElement('span');
            bar.style.display = 'block';
            bar.style.width = '42%';
            bar.style.height = '100%';
            bar.style.borderRadius = 'inherit';
            bar.style.background = 'linear-gradient(90deg, rgba(139,115,85,0.86), rgba(111,91,65,0.68))';

            track.appendChild(bar);
            textWrap.appendChild(title);
            textWrap.appendChild(text);
            row.appendChild(spinner);
            row.appendChild(textWrap);
            card.appendChild(row);
            card.appendChild(track);
            overlay.appendChild(card);
            scopeEl.appendChild(overlay);

            const spinAnim = spinner.animate([{
                    transform: 'rotate(0deg)'
                },
                {
                    transform: 'rotate(360deg)'
                }
            ], {
                duration: 900,
                iterations: Infinity,
                easing: 'linear'
            });

            const barAnim = bar.animate([{
                    transform: 'translateX(-22%)',
                    opacity: 0.72
                },
                {
                    transform: 'translateX(95%)',
                    opacity: 1
                },
                {
                    transform: 'translateX(-22%)',
                    opacity: 0.72
                }
            ], {
                duration: 1300,
                iterations: Infinity,
                easing: 'ease-in-out'
            });

            return function() {
                spinAnim.cancel();
                barAnim.cancel();
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                if (computedPosition === 'static') {
                    if (originalInlinePosition) {
                        scopeEl.style.position = originalInlinePosition;
                    } else {
                        scopeEl.style.removeProperty('position');
                    }
                }
            };
        }

        function selectCat(btn) {
            activeCat = btn.dataset.cat;
            document.querySelectorAll('.cat-btn').forEach(b => {
                b.classList.toggle('active', b === btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });
            // Update dropdown trigger label on mobile
            const label = document.getElementById('catDropdownLabel');
            if (label) label.textContent = btn.querySelector('.count') ? btn.firstChild.textContent.trim() : btn.textContent.trim();
            closeCatDropdown();
            renderMenu();
        }

        function toggleCatDropdown() {
            const wrap = document.getElementById('catsWrap');
            const trigger = document.getElementById('catDropdownTrigger');
            const isOpen = wrap.classList.contains('open');
            if (isOpen) {
                closeCatDropdown();
                return;
            }
            // Pin the fixed-position dropdown exactly below the trigger button
            const cats = document.getElementById('cats');
            if (cats && trigger) {
                const rect = trigger.getBoundingClientRect();
                cats.style.top = rect.bottom + 'px';
            }
            wrap.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');
            // Backdrop to close on outside tap
            let bd = document.getElementById('catsBackdrop');
            if (!bd) {
                bd = document.createElement('div');
                bd.id = 'catsBackdrop';
                bd.className = 'cats-backdrop';
                bd.addEventListener('click', closeCatDropdown);
                document.body.appendChild(bd);
            }
            bd.classList.add('show');
        }

        function closeCatDropdown() {
            const wrap = document.getElementById('catsWrap');
            const trigger = document.getElementById('catDropdownTrigger');
            if (wrap) wrap.classList.remove('open');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
            const bd = document.getElementById('catsBackdrop');
            if (bd) bd.classList.remove('show');
        }

        let lastMenuTapKey = '';
        let lastMenuTapAt = 0;

        function bindMenuItemTap(tile, menuItem) {
            let startX = 0;
            let startY = 0;
            let startAt = 0;
            let moved = false;
            tile.tabIndex = 0;
            tile.setAttribute('role', 'button');
            tile.setAttribute('aria-label', 'Add ' + menuItem.name);

            tile.addEventListener('pointerdown', event => {
                if (event.pointerType === 'mouse' && event.button !== 0) return;
                startX = event.clientX;
                startY = event.clientY;
                startAt = Date.now();
                moved = false;
                tile.classList.add('is-pressing');
                if (tile.setPointerCapture) tile.setPointerCapture(event.pointerId);
            });
            tile.addEventListener('pointermove', event => {
                if (Math.abs(event.clientX - startX) > 12 || Math.abs(event.clientY - startY) > 12) {
                    moved = true;
                    tile.classList.remove('is-pressing');
                }
            });
            tile.addEventListener('pointercancel', () => tile.classList.remove('is-pressing'));
            tile.addEventListener('pointerleave', () => tile.classList.remove('is-pressing'));
            tile.addEventListener('pointerup', event => {
                tile.classList.remove('is-pressing');
                const dragDistance = Math.max(Math.abs(event.clientX - startX), Math.abs(event.clientY - startY));
                const held = Date.now() - startAt;
                if (moved || dragDistance > 12) return;
                if (held > 700) {
                    // Long-press → assign barcode (managers only)
                    if (posCanAssignBarcode) posShowBarcodeAssignModal(menuItem);
                    return;
                }
                const tapKey = menuItem.type + ':' + menuItem.id;
                const now = Date.now();
                if (tapKey === lastMenuTapKey && now - lastMenuTapAt < 260) return;
                lastMenuTapKey = tapKey;
                lastMenuTapAt = now;
                addToCart(menuItem);
            });
            tile.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    addToCart(menuItem);
                }
            });
        }

        function renderMenu() {
            const q = document.getElementById('search').value.toLowerCase();
            const grid = document.getElementById('grid');
            grid.innerHTML = '';
            menuList.forEach(m => {
                if (!menuItemVisibleInMode(m)) return;
                if (q && m.name.toLowerCase().indexOf(q) === -1) return;
                if (activeCat !== '__ALL__' && m.category !== activeCat) return;

                const isAvailable = m.is_available !== 0; // undefined or 1 = available
                const is86d = !isAvailable && posCanToggle86;
                // Users without 86 permission cannot see unavailable items
                if (!isAvailable && !posCanToggle86) return;

                const max = maxPortions(m.id, m.type);
                const cartQty = cart.filter(c => c.id === m.id && c.type === m.type).reduce((s, c) => s + c.qty, 0);
                const remain = max === null ? null : (max - cartQty);
                const oos = (max !== null && remain <= 0);
                let stockStr, low = '';
                if (!isAvailable) {
                    stockStr = '86\'d';
                    low = 'low';
                } else if (max === null) {
                    stockStr = 'Untracked';
                } else if (remain <= 0) {
                    stockStr = 'Out of stock';
                    low = 'low';
                } else if (remain <= 5) {
                    stockStr = remain + ' left';
                    low = 'low';
                } else {
                    stockStr = remain + ' avail';
                }

                const div = document.createElement('div');
                const eightySixBadge = is86d ? `<span style="position:absolute;top:4px;right:4px;background:#c82333;color:#fff;font-size:9px;font-weight:800;padding:2px 5px;border-radius:4px;letter-spacing:.05em;">86</span>` : '';
                const barcodeBadge = (m.barcode && posCanAssignBarcode) ? `<span title="Barcode: ${escHtml(m.barcode)}" style="position:absolute;bottom:4px;right:4px;color:#4ade80;font-size:10px;"><i class="fas fa-barcode"></i></span>` : '';
                div.className = 'item' + ((oos && isAvailable) ? ' oos' : '') + (is86d ? ' oos' : '');
                div.style.position = 'relative';
                if (is86d) div.style.opacity = '0.6';

                if (eightySixMode && posCanToggle86) {
                    // 86 mode: show toggle button over item
                    div.innerHTML = `
                <div>
                    <div class="badge">${escHtml(m.type)}</div>
                    <div class="nm">${escHtml(m.name)}</div>
                </div>
                <div>
                    <div class="pr">${currencySymbol} ${fmtMoney(m.price)}</div>
                    <div class="st low">${isAvailable ? 'Tap to 86' : 'Tap to enable'}</div>
                </div>
                ${eightySixBadge}${barcodeBadge}`;
                    div.style.cursor = 'pointer';
                    div.style.border = is86d ? '2px solid #22c55e' : '2px dashed #c82333';
                    div.addEventListener('click', () => doToggleItem(m.id));
                } else {
                    div.innerHTML = `
                <div>
                    <div class="badge">${escHtml(m.type)}</div>
                    <div class="nm">${escHtml(m.name)}</div>
                </div>
                <div>
                    <div class="pr">${currencySymbol} ${fmtMoney(m.price)}</div>
                    <div class="st ${low}">${stockStr}</div>
                </div>
                ${eightySixBadge}${barcodeBadge}`;
                    if (!oos && isAvailable) bindMenuItemTap(div, m);
                }
                grid.appendChild(div);
            });
        }

        function addToCart(m) {
            const ex = cart.find(c => c.id === m.id && c.type === m.type);
            if (ex) ex.qty++;
            else cart.push({
                ...m,
                qty: 1
            });
            renderCart();
            renderMenu();
        }

        /* ─── Barcode Scanner Engine ─────────────────────────────────────────
         * USB/Bluetooth barcode scanners appear as HID keyboards: they type the
         * barcode string very quickly (< 30 ms between chars) then send Enter.
         * We detect that pattern and match against menuList[].barcode.
         *
         * Enabled state is stored per-device in localStorage so each POS
         * terminal can be configured independently without a settings page.
         * Managers can assign barcodes via long-press on any menu tile.
         * ──────────────────────────────────────────────────────────────────── */
        const LS_BARCODE_KEY = 'pos_barcode_scanner_enabled';
        let barcodeScannerEnabled = localStorage.getItem(LS_BARCODE_KEY) === '1';
        let _bcBuffer = '';
        let _bcLastChar = 0;
        const BC_SPEED_MS  = 50;  // chars faster than this = scanner (not human typing)
        const BC_MIN_LEN   = 3;   // ignore strings shorter than this

        function posInitBarcodeUI() {
            const btn  = document.getElementById('barcodeToggleBtn');
            const strip = document.getElementById('barcodeScanStrip');
            const pmLbl = document.getElementById('pmBarcodeLbl');
            if (btn)  btn.style.display = '';
            if (barcodeScannerEnabled) {
                if (btn)   { btn.classList.add('active'); btn.title = 'Barcode scanner ON — click to disable'; }
                if (strip) { strip.style.display = 'flex'; }
                if (pmLbl) pmLbl.textContent = 'Scanner: ON';
            } else {
                if (btn)   { btn.classList.remove('active'); btn.title = 'Barcode scanner OFF — click to enable'; }
                if (strip) { strip.style.display = 'none'; }
                if (pmLbl) pmLbl.textContent = 'Scanner: OFF';
            }
        }

        function posToggleBarcodeScanner() {
            barcodeScannerEnabled = !barcodeScannerEnabled;
            localStorage.setItem(LS_BARCODE_KEY, barcodeScannerEnabled ? '1' : '0');
            posInitBarcodeUI();
            posToast(barcodeScannerEnabled ? 'Barcode scanner enabled' : 'Barcode scanner disabled', 'ok', 2200);
        }

        function posHandleBarcodeInput(code) {
            if (!code || code.length < BC_MIN_LEN) return;
            const match = menuList.find(m => m.barcode && m.barcode === code);
            const lastEl = document.getElementById('barcodeScanLast');
            if (match) {
                if (!match.is_available) {
                    posToast('86\'d: ' + match.name, 'err', 2500);
                    if (lastEl) lastEl.textContent = '✗ 86\'d';
                } else if (!menuItemVisibleInMode(match)) {
                    posToast('Not available in this mode: ' + match.name, 'err', 2500);
                    if (lastEl) lastEl.textContent = '✗ mode';
                } else {
                    // Stock-level check using the live snapshot
                    const stockKey = match.type + ':' + match.id;
                    const inStock = Object.prototype.hasOwnProperty.call(stockSnapshot, stockKey)
                        ? stockSnapshot[stockKey] : null;
                    const inCart = (cart.find(c => c.id === match.id && c.type === match.type) || {}).qty || 0;
                    if (inStock !== null && inStock - inCart <= 0) {
                        posToast('Out of stock: ' + match.name, 'err', 2500);
                        if (lastEl) lastEl.textContent = '✗ no stock';
                        return;
                    }
                    addToCart(match);
                    posToast('+ ' + match.name, 'ok', 1400);
                    if (lastEl) lastEl.textContent = '✓ ' + match.name;
                }
            } else {
                posToast('Barcode not registered: ' + code, 'err', 2800);
                if (lastEl) lastEl.textContent = '? ' + code;
            }
        }

        /* HID/USB barcode scanner listener — active whenever scanner mode is on OR the
           camera overlay is open. External wedge scanners emit keystrokes very fast
           (< 50 ms between chars) then send Enter; human typing is much slower. */
        document.addEventListener('keydown', function(e) {
            const camOverlayOpen = (document.getElementById('posCamScanOverlay') || {}).style &&
                                   document.getElementById('posCamScanOverlay').style.display === 'flex';
            if (!barcodeScannerEnabled && !camOverlayOpen) return;
            // Ignore if focus is inside a text input (unless it's the barcode assign input)
            const ae = document.activeElement || {};
            if (['INPUT','TEXTAREA','SELECT'].includes(ae.tagName || '') && ae.id !== 'bcAssignInput') return;
            const now = Date.now();
            if (e.key === 'Enter') {
                if (_bcBuffer.length >= BC_MIN_LEN && (now - _bcLastChar) < BC_SPEED_MS * 5) {
                    posHandleBarcodeInput(_bcBuffer);
                    // If camera overlay is open, also trigger the camera feed card
                    if (camOverlayOpen && typeof window._posCamOnExternalCode === 'function') {
                        window._posCamOnExternalCode(_bcBuffer);
                    }
                }
                _bcBuffer = '';
                return;
            }
            if (e.key.length === 1) {
                if (now - _bcLastChar > 500) _bcBuffer = '';
                _bcBuffer += e.key;
                _bcLastChar = now;
            }
        });

        /* Barcode assign modal (long-press a menu tile) */
        function posShowBarcodeAssignModal(menuItem) {
            const existing = menuItem.barcode || '';
            const modal = document.createElement('div');
            modal.className = 'pos-modal-overlay';
            modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:10000;display:flex;align-items:center;justify-content:center;';
            modal.innerHTML = `
                <div style="background:#1e1e2e;border-radius:12px;padding:28px 24px;min-width:320px;max-width:90vw;box-shadow:0 8px 40px rgba(0,0,0,.5);">
                    <h3 style="margin:0 0 6px;color:#f1f5f9;font-size:16px;font-weight:700;">
                        <i class="fas fa-barcode" style="color:#4ade80;margin-right:8px;"></i>Assign Barcode
                    </h3>
                    <p style="margin:0 0 16px;color:#94a3b8;font-size:13px;">${escHtml(menuItem.name)}</p>
                    <label style="color:#cbd5e1;font-size:13px;display:block;margin-bottom:6px;">Barcode / SKU</label>
                    <input id="bcAssignInput" type="text" value="${escHtml(existing)}"
                        placeholder="Scan or type barcode — leave empty to clear"
                        style="width:100%;box-sizing:border-box;padding:10px 12px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:#f1f5f9;font-size:15px;outline:none;"
                        autocomplete="off" autocorrect="off" spellcheck="false">
                    <div style="display:flex;gap:10px;margin-top:18px;">
                        <button id="bcAssignSave" style="flex:1;padding:11px;border-radius:8px;border:none;background:#22c55e;color:#fff;font-size:14px;font-weight:700;cursor:pointer;">
                            <i class="fas fa-save"></i> Save
                        </button>
                        <button id="bcAssignCancel" style="flex:1;padding:11px;border-radius:8px;border:none;background:#334155;color:#f1f5f9;font-size:14px;cursor:pointer;">
                            Cancel
                        </button>
                    </div>
                    <div id="bcAssignErr" style="color:#f87171;font-size:12px;margin-top:10px;display:none;"></div>
                </div>`;
            document.body.appendChild(modal);
            const inp = modal.querySelector('#bcAssignInput');
            inp.focus();
            inp.select();
            modal.querySelector('#bcAssignCancel').onclick = () => modal.remove();
            modal.querySelector('#bcAssignSave').onclick = async () => {
                const newCode = inp.value.trim();
                const btn = modal.querySelector('#bcAssignSave');
                const errEl = modal.querySelector('#bcAssignErr');
                btn.disabled = true;
                btn.textContent = 'Saving…';
                errEl.style.display = 'none';
                try {
                    const fd = new FormData();
                    fd.append('csrf_token', posCsrfToken);
                    fd.append('item_id', menuItem.id);
                    fd.append('barcode', newCode);
                    const res = await fetch('pos.php?ajax=assign_barcode', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.ok) {
                        // Update the in-memory menuList so the scanner picks it up immediately
                        const idx = menuList.findIndex(m => m.id === menuItem.id && m.type === menuItem.type);
                        if (idx !== -1) menuList[idx].barcode = data.barcode;
                        menuItem.barcode = data.barcode;
                        modal.remove();
                        posToast(newCode ? 'Barcode assigned' : 'Barcode cleared', 'ok', 2200);
                    } else {
                        errEl.textContent = data.error || 'Save failed.';
                        errEl.style.display = 'block';
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-save"></i> Save';
                    }
                } catch(err) {
                    errEl.textContent = 'Network error. Please try again.';
                    errEl.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save"></i> Save';
                }
            };
        }

        // Boot barcode UI after page load
        document.addEventListener('DOMContentLoaded', posInitBarcodeUI);

        function setQty(idx, q) {
            q = Math.max(0, Math.min(1000, parseFloat(q) || 0));
            if (q === 0) cart.splice(idx, 1);
            else cart[idx].qty = q;
            renderCart();
            renderMenu();
        }

        function bump(idx, d) {
            setQty(idx, (cart[idx]?.qty || 0) + d);
        }

        function rm(idx) {
            cart.splice(idx, 1);
            renderCart();
            renderMenu();
        }
        async function clearCart() {
            if (cart.length) {
                const confirmed = await posConfirm('Clear cart', 'Remove all items from the current sale?', 'Clear cart');
                if (!confirmed) return;
            }
            cart = [];
            renderCart();
            renderMenu();
        }

        function cartTotal() {
            return cart.reduce((s, l) => s + l.price * l.qty, 0);
        }

        /** Returns which station types are in the current cart */
        function cartStations() {
            const hasFood = cart.some(l => l.type === 'food');
            const hasDrink = cart.some(l => l.type === 'drink');
            return {
                hasFood,
                hasDrink
            };
        }

        /* ═══════════════════════════════════════════════════════════════════════
           DEALS ENGINE  v2
           Evaluates posDeals against the current cart every time the cart changes.
           Supports: happy_hour, percent_off, fixed_off, multi_buy, spend_save, combo
           Handles: exclusive deals, max_uses_per_order, deal stacking cap
        ═══════════════════════════════════════════════════════════════════════ */
        let _dealSavings = 0;
        let _dealLines   = [];  // [{id, name, saving, detail}]

        function _dealNowValid(deal) {
            const now  = new Date();
            const dow  = now.getDay() === 0 ? 7 : now.getDay(); // 1=Mon … 7=Sun (ISO)
            const hhmm = now.getHours().toString().padStart(2,'0') + ':' + now.getMinutes().toString().padStart(2,'0');
            // YYYY-MM-DD in LOCAL time (not UTC) to avoid midnight boundary bugs
            const ymd  = now.getFullYear() + '-' +
                         String(now.getMonth()+1).padStart(2,'0') + '-' +
                         String(now.getDate()).padStart(2,'0');

            if (deal.valid_from && ymd < deal.valid_from) return false;
            if (deal.valid_to   && ymd > deal.valid_to)   return false;
            if (Array.isArray(deal.days_of_week) && deal.days_of_week.length) {
                if (!deal.days_of_week.some(d => parseInt(d,10) === dow)) return false;
            }
            if (deal.start_time && deal.end_time) {
                const st = deal.start_time.slice(0,5);
                const et = deal.end_time.slice(0,5);
                if (hhmm < st || hhmm > et) return false;
            }
            return true;
        }

        function _dealItemQualifies(deal, cartLine) {
            if (deal.applies_to === 'all') return true;
            if (deal.applies_to === 'item_types' && Array.isArray(deal.item_types)) {
                return deal.item_types.includes(String(cartLine.type));
            }
            if (deal.applies_to === 'items' && Array.isArray(deal.item_ids)) {
                return deal.item_ids.map(Number).includes(Number(cartLine.id));
            }
            return false;
        }

        function _expandUnits(lines) {
            // Expand cart lines into individual units [{price}], integers only for qty
            const units = [];
            for (const l of lines) {
                const intQty = Math.floor(Number(l.qty) || 0);
                for (let i = 0; i < intQty; i++) units.push(l.price);
            }
            return units;
        }

        function applyDeals() {
            if (!posDeals || !posDeals.length || !cart.length) {
                _dealSavings = 0; _dealLines = []; return;
            }
            const lines   = [];
            let totalSave = 0;
            const gross   = cartTotal();
            console.log('[applyDeals] cart:', JSON.stringify(cart.map(l=>({id:l.id,qty:l.qty}))), 'deals:', posDeals.length);
            let hasExclusive = false;

            // First pass: find any exclusive deal that fires
            for (const deal of posDeals) {
                if (!deal.exclusive) continue;
                if (!_dealNowValid(deal)) continue;
                const qualifying = cart.filter(l => _dealItemQualifies(deal, l));
                if (!qualifying.length && deal.deal_type !== 'spend_save' && deal.deal_type !== 'combo') continue;
                if (deal.deal_type === 'spend_save' && deal.spend_threshold && gross < deal.spend_threshold) continue;
                hasExclusive = true;
                break;
            }

            for (const deal of posDeals) {
                if (!_dealNowValid(deal)) continue;
                // Skip non-exclusive deals if an exclusive deal is active
                if (hasExclusive && !deal.exclusive) continue;
                // If there are multiple exclusive deals, only run them (they may stack with each other)

                const qualifying = cart.filter(l => _dealItemQualifies(deal, l));
                let saving = 0;
                let detail = '';

                if (deal.deal_type === 'happy_hour' || deal.deal_type === 'percent_off') {
                    if (!qualifying.length) continue;
                    const pct  = deal.discount_percent / 100;
                    const base = qualifying.reduce((s, l) => s + l.price * l.qty, 0);
                    saving = Math.round(base * pct * 100) / 100;
                    const scopeLabel = (deal.applies_to === 'all')
                        ? 'all items'
                        : qualifying.map(l => l.name).join(', ');
                    detail = `${deal.discount_percent}% off ${scopeLabel}`;
                    if (deal.deal_type === 'happy_hour') detail = '⏰ ' + detail;

                } else if (deal.deal_type === 'fixed_off') {
                    if (!qualifying.length && deal.applies_to !== 'all') continue;
                    saving = deal.discount_fixed;
                    detail = `${currencySymbol} ${fmtMoney(saving)} off`;
                    // Cap: cannot exceed qualifying subtotal
                    if (qualifying.length) {
                        const qSub = qualifying.reduce((s, l) => s + l.price * l.qty, 0);
                        saving = Math.min(saving, qSub);
                    }

                } else if (deal.deal_type === 'multi_buy') {
                    if (!qualifying.length) continue;
                    const units = _expandUnits(qualifying).sort((a, b) => a - b); // cheapest first
                    const groupSize  = deal.multi_buy_qty || 2;
                    const payFor     = deal.multi_buy_pay || 1;
                    const freePerGrp = groupSize - payFor;
                    const groups     = Math.floor(units.length / groupSize);
                    console.log('[multi_buy]', deal.name, 'qualifying:', qualifying.length, 'units:', units.length, 'groupSize:', groupSize, 'payFor:', payFor, 'groups:', groups, 'freePerGrp:', freePerGrp);
                    if (groups < 1) continue;
                    // Respect max_uses_per_order cap
                    const maxFreeGroups = deal.max_uses_per_order ? Math.min(groups, deal.max_uses_per_order) : groups;
                    const totalFree  = maxFreeGroups * freePerGrp;
                    saving = Math.round(units.slice(0, totalFree).reduce((s, p) => s + p, 0) * 100) / 100;
                    console.log('[multi_buy]', deal.name, 'totalFree:', totalFree, 'saving:', saving);
                    detail = `Buy ${groupSize}, pay for ${payFor} — ${totalFree} item${totalFree !== 1 ? 's' : ''} free`;

                } else if (deal.deal_type === 'spend_save') {
                    const threshold = deal.spend_threshold || 0;
                    if (gross < threshold) continue;
                    if (deal.discount_percent > 0) {
                        const base = qualifying.length
                            ? qualifying.reduce((s, l) => s + l.price * l.qty, 0)
                            : gross;
                        saving = Math.round(base * (deal.discount_percent / 100) * 100) / 100;
                        detail = `Spend ${currencySymbol} ${fmtMoney(threshold)}+ · ${deal.discount_percent}% off`;
                    } else if (deal.discount_fixed > 0) {
                        saving = deal.discount_fixed;
                        detail = `Spend ${currencySymbol} ${fmtMoney(threshold)}+ · ${currencySymbol} ${fmtMoney(saving)} off`;
                    }

                } else if (deal.deal_type === 'combo') {
                    // Requires items from each specified group to be present
                    const groups = Array.isArray(deal.combo_requires) ? deal.combo_requires : [];
                    if (!groups.length) continue;
                    let allGroupsMet = true;
                    let comboItems   = [];
                    for (const grp of groups) {
                        const types    = Array.isArray(grp.item_types) ? grp.item_types : [];
                        const minQty   = parseInt(grp.min_qty || 1, 10);
                        const matching = cart.filter(l => types.includes(String(l.type)));
                        const grpQty   = matching.reduce((s, l) => s + Math.floor(Number(l.qty) || 0), 0);
                        if (grpQty < minQty) { allGroupsMet = false; break; }
                        comboItems.push(...matching);
                    }
                    if (!allGroupsMet) continue;
                    // Dedupe comboItems
                    comboItems = [...new Map(comboItems.map(l => [l.id, l])).values()];
                    const base = comboItems.reduce((s, l) => s + l.price * l.qty, 0);
                    saving = Math.round(base * (deal.discount_percent / 100) * 100) / 100;
                    detail = `Combo: ${groups.map(g => g.item_types.join('+')).join(' & ')} · ${deal.discount_percent}% off`;
                }

                if (saving <= 0) continue;
                totalSave += saving;
                lines.push({ id: String(deal.id), name: deal.name, saving, detail });
            }

            // Global cap: total deal savings ≤ 90% of cart total (sanity guard)
            const maxSavings = Math.round(gross * 0.90 * 100) / 100;
            _dealSavings = Math.min(totalSave, maxSavings);
            _dealLines   = lines;
        }

        const CLOSED_BADGE = '<span style="background:#c82333;color:#fff;font-size:9px;font-weight:700;padding:2px 5px;border-radius:4px;letter-spacing:.04em;vertical-align:middle;margin-left:4px;">CLOSED</span>';

        /** Dynamically updates park button label/icon/CLOSED badge + hint text */
        function updateFireButton() {
            const btn = document.getElementById('parkBtn');
            const label = document.getElementById('parkBtnLabel');
            const hint = document.getElementById('parkHint');
            if (!btn || !label) return;
            const {
                hasFood,
                hasDrink
            } = cartStations();
            let icon, text, stationOpen;
            if (hasFood && hasDrink) {
                icon = 'fa-fire';
                text = 'Fire to Stations';
                stationOpen = posKitchenOpen && posBarOpen;
            } else if (hasDrink) {
                icon = 'fa-cocktail';
                text = 'Send to Bar';
                stationOpen = posBarOpen;
            } else if (hasFood) {
                icon = 'fa-utensils';
                text = 'Send to Kitchen';
                stationOpen = posKitchenOpen;
            } else {
                icon = 'fa-fire';
                text = 'Fire Order';
                stationOpen = true;
            }
            // Override label when appending to an existing tab
            if (typeof _activeTab !== 'undefined' && _activeTab) {
                label.innerHTML = `<i class="fas fa-plus"></i> Add to ${escHtml(_activeTab.ref)}${stationOpen ? '' : CLOSED_BADGE}`;
                if (hint) hint.textContent = 'Appends this round to ' + _activeTab.ref;
                return;
            }
            label.innerHTML = `<i class="fas ${icon}"></i> ${text}${stationOpen ? '' : CLOSED_BADGE}`;
            if (hint) hint.textContent = text + ' = open tab (pay later)';
        }

        function toggleCartDrawer() {
            const cart = document.getElementById('mainCart');
            const grid = document.querySelector('.till-grid');
            const backdrop = document.getElementById('cartBackdrop');
            if (!cart || !grid) return;
            if (window.matchMedia('(max-width: 1024px)').matches) {
                const opening = !cart.classList.contains('open');
                cart.classList.toggle('open');
                document.body.classList.toggle('pos-cart-open', opening);
                if (backdrop) backdrop.classList.toggle('show', opening);
                return;
            }
            grid.classList.toggle('order-menu-closed');
            if (typeof window.syncPosCatsResize === 'function') {
                window.syncPosCatsResize();
            }
        }

        /* ===== Mobile POS menu ===== */
        function openPosMobileMenu() {
            const menu = document.getElementById('posMobileMenu');
            const backdrop = document.getElementById('posMobileBackdrop');
            const menuBtn = document.getElementById('posMobileMenuBtn');
            if (!menu || !backdrop) return;
            menu.classList.add('is-open');
            backdrop.classList.add('is-open');
            if (menuBtn) menuBtn.setAttribute('aria-expanded', 'true');
            document.addEventListener('keydown', _posMobileMenuEsc);
        }

        function closePosMobileMenu() {
            const menu = document.getElementById('posMobileMenu');
            const backdrop = document.getElementById('posMobileBackdrop');
            const menuBtn = document.getElementById('posMobileMenuBtn');
            if (!menu || !backdrop) return;
            menu.classList.remove('is-open');
            backdrop.classList.remove('is-open');
            if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
            document.removeEventListener('keydown', _posMobileMenuEsc);
        }

        function bindPosMobileMenuButton() {
            const menuBtn = document.getElementById('posMobileMenuBtn');
            if (!menuBtn || menuBtn.dataset.bound === '1') return;
            menuBtn.dataset.bound = '1';
            menuBtn.addEventListener('click', function(event) {
                event.preventDefault();
                if (!document.getElementById('posMobileMenu')?.classList.contains('is-open')) openPosMobileMenu();
            });
        }

        function _posMobileMenuEsc(e) {
            if (e.key === 'Escape') closePosMobileMenu();
        }

        function isPosPhoneViewport() {
            return window.innerWidth <= 640;
        }

        function isPosCompactViewport() {
            return window.matchMedia('(max-width: 1024px)').matches;
        }

        function posMobileQuickViewEmpty(iconClass, title, text) {
            return '<div class="pos-mobile-empty-state">' +
                '<div class="pos-mobile-empty-state__icon"><i class="fas ' + escHtml(iconClass || 'fa-circle-info') + '"></i></div>' +
                '<h4 class="pos-mobile-empty-state__title">' + escHtml(title || 'Nothing to show') + '</h4>' +
                '<p class="pos-mobile-empty-state__text">' + escHtml(text || 'There is nothing to show right now.') + '</p>' +
                '</div>';
        }

        function openPosMobileQuickView(title, html, options = {}) {
            const overlay = document.getElementById('posMobileQuickViewOverlay');
            const titleEl = document.getElementById('posMobileQuickViewTitle');
            const subtitleEl = document.getElementById('posMobileQuickViewSubtitle');
            const bodyEl = document.getElementById('posMobileQuickViewBody');
            if (!overlay || !titleEl || !bodyEl) return;
            titleEl.innerHTML = title || '<i class="fas fa-layer-group"></i> Quick View';
            const subtitleText = typeof options.subtitle === 'string' ? options.subtitle.trim() : '';
            if (subtitleEl) {
                subtitleEl.textContent = subtitleText;
                subtitleEl.hidden = subtitleText === '';
            }
            bodyEl.innerHTML = html || posMobileQuickViewEmpty('fa-circle-info', 'Nothing to show', 'There is nothing to see right now.');
            bodyEl.scrollTop = 0;
            overlay.classList.add('show');
        }

        function closePosMobileQuickView() {
            document.getElementById('posMobileQuickViewOverlay')?.classList.remove('show');
            const subtitleEl = document.getElementById('posMobileQuickViewSubtitle');
            if (subtitleEl) {
                subtitleEl.textContent = '';
                subtitleEl.hidden = true;
            }
        }

        function openPosMobileRecentView() {
            if (!isPosPhoneViewport()) {
                toggleRecent();
                return;
            }
            const recentList = document.getElementById('recentList');
            const rows = Array.from(recentList?.querySelectorAll('a.r') || []);
            if (!rows.length) {
                openPosMobileQuickView(
                    '<i class="fas fa-receipt"></i> Recent Orders',
                    posMobileQuickViewEmpty('fa-receipt', 'No recent orders', 'As soon as an order is fired or paid, it will appear here.'), {
                        subtitle: 'Latest activity'
                    }
                );
                return;
            }
            const cards = rows.map((row, index) => {
                const ref = row.querySelector('.ref')?.textContent?.trim() || ('Order ' + (index + 1));
                const detail = Array.from(row.querySelectorAll('div')).find(el => !el.classList.contains('ref'))?.textContent?.trim() || 'Open to view details';
                const href = row.getAttribute('href') || '#';
                return '<a class="pos-mobile-quick-view-item pos-mobile-quick-view-item--recent" href="' + escHtml(href) + '" target="_blank" rel="noopener">' +
                    '<div class="pos-mobile-quick-view-item__top">' +
                    '<strong class="pos-mobile-quick-view-item__title">' + escHtml(ref) + '</strong>' +
                    '<span class="pos-mobile-quick-view-item__badge">Recent</span>' +
                    '</div>' +
                    '<p class="pos-mobile-quick-view-item__meta">' + escHtml(detail) + '</p>' +
                    '<span class="pos-mobile-quick-view-item__cta">Open receipt <i class="fas fa-arrow-right"></i></span>' +
                    '</a>';
            }).join('');
            openPosMobileQuickView(
                '<i class="fas fa-receipt"></i> Recent Orders',
                '<div class="pos-mobile-quick-view-list">' + cards + '</div>', {
                    subtitle: rows.length + (rows.length === 1 ? ' order' : ' orders')
                }
            );
        }

        async function openPosMobileInboxView() {
            if (!isPosPhoneViewport()) {
                togglePosInbox();
                return;
            }
            showPosActionLoader('Loading inbox…', 'Checking station replies.');
            try {
                await pollStationReplies();
            } finally {
                hidePosActionLoader();
            }
            const messages = Array.isArray(_inboxLastMsgs) ? _inboxLastMsgs : [];
            if (!messages.length) {
                openPosMobileQuickView(
                    '<i class="fas fa-inbox"></i> Station Inbox',
                    posMobileQuickViewEmpty('fa-inbox', 'Inbox is clear', 'No station notes or replies right now.'), {
                        subtitle: 'Live station communication'
                    }
                );
                return;
            }
            const messageHtml = messages.map((message) => {
                const station = posInboxStationLabel(message.station);
                const urgent = message.priority === 'urgent';
                const messageText = escHtml(message.message || 'No message body');
                const contextText = escHtml(posInboxOrderContext(message) || (message.order_ref ? String(message.order_ref) : 'General note'));
                const timeLabel = message.created_at ?
                    new Date(String(message.created_at).replace(' ', 'T')).toLocaleTimeString([], {
                        hour: '2-digit',
                        minute: '2-digit'
                    }) :
                    '';
                return '<article class="pos-mobile-quick-view-item pos-mobile-quick-view-item--inbox' + (urgent ? ' pos-mobile-quick-view-item--alert' : '') + '">' +
                    '<div class="pos-mobile-quick-view-item__top">' +
                    '<strong class="pos-mobile-quick-view-item__title">' + escHtml(station) + '</strong>' +
                    '<span class="pos-mobile-quick-view-item__badge">' + (urgent ? 'Urgent' : 'Inbox') + '</span>' +
                    '</div>' +
                    '<p class="pos-mobile-quick-view-item__summary">' + messageText + '</p>' +
                    '<p class="pos-mobile-quick-view-item__meta">' + contextText + '</p>' +
                    '<div class="pos-mobile-quick-view-item__footer">' + (timeLabel ? '<span><i class="fas fa-clock"></i> ' + escHtml(timeLabel) + '</span>' : '<span><i class="fas fa-clock"></i> Just now</span>') + '</div>' +
                    '</article>';
            }).join('');
            openPosMobileQuickView(
                '<i class="fas fa-inbox"></i> Station Inbox',
                '<div class="pos-mobile-quick-view-list">' + messageHtml + '</div>', {
                    subtitle: messages.length + (messages.length === 1 ? ' message' : ' messages')
                }
            );
        }

        async function openPosMobileOrdersView() {
            await openMyOrdersCurrentDetail();
        }

        function runPosMobileMenuAction(actionName) {
            const action = typeof actionName === 'function' ? actionName : window[actionName];
            const shouldDelay = isPosCompactViewport() &&
                !!document.getElementById('posMobileMenu')?.classList.contains('is-open');
            closePosMobileMenu();
            if (typeof action !== 'function') return;
            if (shouldDelay) {
                window.setTimeout(() => action(), 320);
                return;
            }
            action();
        }

        function _syncPosMobileMenuViewport() {
            // Wide desktop uses inline top actions; close any open sheet to avoid stale UI state.
            if (window.innerWidth > 1700) closePosMobileMenu();
            if (window.innerWidth > 640) {
                document.getElementById('recentList')?.classList.remove('recent-list--mobile');
                document.getElementById('posInboxWidget')?.classList.remove('is-mobile-open');
                document.getElementById('myOrdersWidget')?.classList.remove('is-mobile-open');
            }
        }
        bindPosMobileMenuButton();
        initPosCatsResizer();
        window.addEventListener('resize', _syncPosMobileMenuViewport);
        _syncPosMobileMenuViewport();

        function toggleMobileHelp() {
            const help = document.getElementById('rhHelpToggle');
            if (help) help.click();
        }

        /* Keep mobile quick actions and menu badges in sync with desktop badges. */
        function _syncPosMobileBadges() {
            const pairs = [
                ['tabBadge', ['mobileTabBadge']],
                ['posInboxBadge', ['mobileInboxBadge']],
                ['myOrdersBadge', ['mobileMyOrdersBadge']],
                ['kitchenBadge', ['menuKitchenBadge']],
                ['barBadge', ['menuBarBadge']],
                ['coffeeBadge', ['menuCoffeeBadge']],
            ];
            let totalCount = 0;
            pairs.forEach(([srcId, dstIds]) => {
                const src = document.getElementById(srcId);
                if (!src) return;
                const visible = src.style.display !== 'none' && src.textContent.trim() !== '';
                const count = parseInt(src.textContent, 10) || 0;
                dstIds.forEach(dstId => {
                    const dst = document.getElementById(dstId);
                    if (!dst) return;
                    dst.textContent = src.textContent;
                    dst.style.display = visible ? 'inline-flex' : 'none';
                });
                totalCount += count;
            });
            const menuBadge = document.getElementById('mobileMenuBadge');
            if (menuBadge) {
                menuBadge.textContent = totalCount > 0 ? (totalCount > 99 ? '99+' : totalCount) : '';
                menuBadge.style.display = totalCount > 0 ? 'inline-flex' : 'none';
            }
        }
        /* Observe DOM changes on known badges to keep mobile menu in sync. */
        (function() {
            const ids = ['tabBadge', 'posInboxBadge', 'kitchenBadge', 'barBadge', 'coffeeBadge', 'stationsBadge'];
            const obs = new MutationObserver(_syncPosMobileBadges);
            ids.forEach(id => {
                const el = document.getElementById(id);
                if (el) obs.observe(el, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['style']
                });
            });
            _syncPosMobileBadges();
        })();
        document.addEventListener('DOMContentLoaded', _syncPosMobileBadges);


        function renderCart() {
            const c = document.getElementById('cart-lines');

            // Update mobile badge
            const badge = document.getElementById('cartBadge');
            const mobileCartBadge = document.getElementById('mobileCartBadge');
            const totQty = cart.reduce((s, l) => s + l.qty, 0);
            if (totQty > 0) {
                if (badge) {
                    badge.style.display = 'inline-block';
                    badge.textContent = totQty;
                }
                if (mobileCartBadge) {
                    mobileCartBadge.style.display = 'inline-flex';
                    mobileCartBadge.textContent = totQty;
                }
            } else {
                if (badge) {
                    badge.style.display = 'none';
                    badge.textContent = '0';
                }
                if (mobileCartBadge) {
                    mobileCartBadge.style.display = 'none';
                    mobileCartBadge.textContent = '0';
                }
            }

            if (!cart.length) {
                c.innerHTML = '<p style="color:#6c757d; text-align:center; padding:30px 0; font-size:13px;">Tap items to add to the order.</p>';
            } else {
                c.innerHTML = cart.map((l, i) => `
            <div class="cline">
                <div>
                    <div class="nm">${escHtml(l.name)}</div>
                    <div class="ln-meta">${currencySymbol} ${fmtMoney(l.price)} · ${escHtml(l.type)}</div>
                    ${l.note ? `<div class="ln-note" style="font-size:11px; color:#8B7355; margin-top:3px; font-style:italic;"><i class="fas fa-comment-dots"></i> ${escHtml(l.note)}</div>` : ''}
                    <button type="button" onclick="openNote(${i})" style="background:none; border:none; color:${l.note ? '#8B7355' : '#a0a0a0'}; font-size:11px; padding:2px 0; cursor:pointer; margin-top:2px;"><i class="fas fa-comment-dots"></i> ${l.note ? 'Edit note' : 'Add note'}</button>
                </div>
                <div class="qty">
                    <button type="button" onclick="bump(${i},-1)">−</button>
                    <input type="number" min="0" max="1000" step="0.5" value="${l.qty}" onchange="setQty(${i}, this.value)">
                    <button type="button" onclick="bump(${i},1)">+</button>
                </div>
                <button type="button" class="rm" onclick="rm(${i})"><i class="fas fa-times"></i></button>
            </div>`).join('');
            }
            applyDeals();
            console.log('[renderCart] _dealLines:', _dealLines, '_dealSavings:', _dealSavings);

            const t = cartTotal();
            const eff = effectiveCartTotal();

            // Deal savings strip in cart
            let dealsHtml = '';
            if (_dealLines.length) {
                dealsHtml = _dealLines.map(dl =>
                    `<div class="cart-deal-line"><i class="fas fa-tags"></i> <strong>${escHtml(dl.name)}</strong> <span>${escHtml(dl.detail)}</span><span class="cdl-saving">−${currencySymbol} ${fmtMoney(dl.saving)}</span></div>`
                ).join('');
                dealsHtml = `<div class="cart-deals-block">${dealsHtml}</div>`;
            }

            // Deal progress hints — show near-miss deals so staff know to upsell
            if (cart.length && posDeals && posDeals.length) {
                const pendingHints = [];
                const gross = cartTotal();
                posDeals.forEach(deal => {
                    if (!_dealNowValid(deal)) return;
                    // Already fired
                    if (_dealLines.find(d => d.id === String(deal.id))) return;

                    if (deal.deal_type === 'multi_buy') {
                        const qualifying = cart.filter(l => _dealItemQualifies(deal, l));
                        if (!qualifying.length) return;
                        const totalQty = qualifying.reduce((s, l) => s + Math.floor(Number(l.qty) || 0), 0);
                        const groupSize = deal.multi_buy_qty || 2;
                        const needed = groupSize - (totalQty % groupSize);
                        if (needed > 0 && needed < groupSize) {
                            const itemNames = [...new Set(qualifying.map(l => l.name))].join(', ');
                            pendingHints.push(`<div class="cart-deal-pending"><i class="fas fa-hourglass-half"></i> <strong>${escHtml(deal.name)}</strong> — add <strong>${needed}</strong> more ${escHtml(itemNames)} to unlock</div>`);
                        }

                    } else if (deal.deal_type === 'spend_save') {
                        const threshold = deal.spend_threshold || 0;
                        if (threshold <= 0 || gross >= threshold) return;
                        const stillNeeded = threshold - gross;
                        // Only show hint if within 50% of threshold (avoid noise)
                        if (stillNeeded > threshold * 0.5) return;
                        pendingHints.push(`<div class="cart-deal-pending"><i class="fas fa-hourglass-half"></i> <strong>${escHtml(deal.name)}</strong> — spend <strong>${currencySymbol} ${fmtMoney(stillNeeded)}</strong> more to unlock</div>`);

                    } else if (deal.deal_type === 'combo') {
                        const groups = Array.isArray(deal.combo_requires) ? deal.combo_requires : [];
                        if (!groups.length) return;
                        const missingGroups = [];
                        let anyGroupMet = false;
                        for (const grp of groups) {
                            const types = Array.isArray(grp.item_types) ? grp.item_types : [];
                            const minQty = parseInt(grp.min_qty || 1, 10);
                            const matching = cart.filter(l => types.includes(String(l.type)));
                            const grpQty = matching.reduce((s, l) => s + Math.floor(Number(l.qty) || 0), 0);
                            if (grpQty >= minQty) { anyGroupMet = true; }
                            else { missingGroups.push(`${minQty}× ${types.join('/')}`); }
                        }
                        // Only hint if at least one group is already satisfied (near-miss)
                        if (!anyGroupMet || !missingGroups.length) return;
                        pendingHints.push(`<div class="cart-deal-pending"><i class="fas fa-hourglass-half"></i> <strong>${escHtml(deal.name)}</strong> — also add ${missingGroups.map(m => escHtml(m)).join(' + ')} to unlock</div>`);
                    }
                });
                if (pendingHints.length) {
                    dealsHtml += `<div class="cart-deals-block">${pendingHints.join('')}</div>`;
                }
            }

            // Inject deal lines below cart items
            const dealsEl = document.getElementById('cart-deal-lines');
            if (dealsEl) dealsEl.innerHTML = dealsHtml;

            const displayTotal = _dealSavings > 0 ? eff : t;
            document.getElementById('total').textContent = currencySymbol + ' ' + fmtMoney(displayTotal);
            document.getElementById('payBtn').disabled = !cart.length;
            const parkBtn = document.getElementById('parkBtn');
            if (parkBtn) parkBtn.disabled = !cart.length;
            updateFireButton();
            if (typeof updateRepeatButton === 'function') updateRepeatButton();
            if (typeof updateActiveTabBanner === 'function') updateActiveTabBanner();
        }

        let activeNoteIdx = -1;

        function openNote(i) {
            activeNoteIdx = i;
            document.getElementById('noteItemName').textContent = cart[i].name;
            document.getElementById('noteText').value = cart[i].note || '';
            document.getElementById('noteOverlay').classList.add('show');
        }

        function addNoteChip(t) {
            const ta = document.getElementById('noteText');
            ta.value = (ta.value ? ta.value + ', ' : '') + t;
            ta.focus();
        }

        function saveNote() {
            if (activeNoteIdx < 0) return;
            cart[activeNoteIdx].note = document.getElementById('noteText').value.trim().slice(0, 250);
            document.getElementById('noteOverlay').classList.remove('show');
            activeNoteIdx = -1;
            renderCart();
        }

        function injectCartHidden(targetId) {
            document.getElementById(targetId).innerHTML = cart.map(l => `
        <input type="hidden" name="item_id[]" value="${l.id}">
        <input type="hidden" name="item_type[]" value="${l.type}">
        <input type="hidden" name="item_qty[]" value="${l.qty}">
        <input type="hidden" name="item_note[]" value="${(l.note || '').replace(/"/g,'&quot;')}">
    `).join('');
        }

        function openPayModal() {
            if (!cart.length) return;
            if (!validateServiceContext()) return;
            // Reset manual discount (deal savings already computed in renderCart)
            _payDiscount = 0;
            const dAmtEl = document.getElementById('payDiscountAmt');
            if (dAmtEl) dAmtEl.value = '';
            document.querySelectorAll('#payDiscountPresets .discount-preset-btn').forEach(b => b.classList.toggle('active', b.dataset.pct === '0'));

            // Show deal savings block in pay modal
            const dealBlock = document.getElementById('payDealLines');
            if (dealBlock) {
                if (_dealLines.length) {
                    dealBlock.innerHTML = _dealLines.map(dl =>
                        `<div class="pay-deal-line"><i class="fas fa-tags" style="color:#10b981"></i> <span>${escHtml(dl.name)}</span><span class="pdl-saving" style="color:#10b981;font-weight:600;">−${currencySymbol} ${fmtMoney(dl.saving)}</span></div>`
                    ).join('');
                    dealBlock.style.display = 'block';
                } else {
                    dealBlock.innerHTML = '';
                    dealBlock.style.display = 'none';
                }
            }

            syncPayDiscountToForm();
            const t = effectiveCartTotal();
            document.getElementById('payTotal').textContent = currencySymbol + ' ' + fmtMoney(t);
            injectCartHidden('payHiddenItems');
            /* Render the service-context summary inside the modal so the cashier sees what they're paying for */
            updatePaySvcSummary();
            const kw = document.getElementById('payKitchenWarning');
            if (kw) {
                const {
                    hasFood,
                    hasDrink
                } = cartStations();
                const warnParts = [];
                if (hasFood && !posKitchenOpen) warnParts.push(`Kitchen is closed (${posKitchenHours})`);
                if (hasDrink && !posBarOpen) warnParts.push(`Bar is closed (${posBarHours})`);
                if (warnParts.length) {
                    const wtxt = document.getElementById('payKitchenWarningText');
                    if (wtxt) wtxt.innerHTML = '<strong>' + warnParts.join(' · ') + '</strong>. Tickets will sit unseen until they reopen.';
                    kw.style.display = 'flex';
                } else {
                    kw.style.display = 'none';
                }
            }
            document.getElementById('payOverlay').classList.add('show');
            updConfirm();
        }

        function closePayModal() {
            document.getElementById('payOverlay').classList.remove('show');
        }

        let _kitchenFireCallback = null;

        function showKitchenClosedWarning(onConfirm) {
            _kitchenFireCallback = onConfirm;
            document.getElementById('kitchenClosedOverlay').classList.add('show');
        }

        function cancelKitchenFire() {
            _kitchenFireCallback = null;
            document.getElementById('kitchenClosedOverlay').classList.remove('show');
        }

        function confirmKitchenFire() {
            document.getElementById('kitchenClosedOverlay').classList.remove('show');
            if (typeof _kitchenFireCallback === 'function') {
                const cb = _kitchenFireCallback;
                _kitchenFireCallback = null;
                cb();
            }
        }

        /* === Service context (order type, table/room, customer) ===========================
           Chips toggle the hidden order_type input. Dine-in & Room service require a
           table / room number — validateServiceContext() blocks Fire / Pay until filled. */
        const SVC_LABEL = {
            walk_in: 'Walk-in',
            dine_in: 'Dine-in',
            takeaway: 'Takeaway',
            room_service: 'Room service'
        };

        function setServiceType(t) {
            const ot = document.getElementById('ctxOrderType');
            if (!ot) return;
            /* Block selection of locked chips — e.g. walk-in/dine-in/takeaway while in
               Room Service menu mode. The Room chip is always allowed. */
            if (t !== 'room_service' && typeof menuMode !== 'undefined' && menuMode === 'room_service') return;
            ot.value = t;
            document.querySelectorAll('.ctx-chip').forEach(c => c.classList.toggle('is-active', c.dataset.type === t));
            /* Keep the menu-mode toggle in sync with the chosen service context so the visible
               menu always matches what the order will fire as. Guard against infinite recursion
               via the early-return inside setMenuMode() when the mode is unchanged. */
            if (typeof menuMode !== 'undefined') {
                if (t === 'room_service' && menuMode !== 'room_service') setMenuMode('room_service');
                else if (t !== 'room_service' && menuMode === 'room_service') setMenuMode('restaurant');
            }
            const loc = document.getElementById('ctxLocation');
            const tableSelect = document.getElementById('ctxTableSelect');
            const roomSelect = document.getElementById('ctxRoomSelect');
            const hint = document.getElementById('ctxLocationHint');
            if (!loc || !tableSelect || !roomSelect) return;
            tableSelect.style.display = 'none';
            roomSelect.style.display = 'none';
            tableSelect.required = false;
            roomSelect.required = false;
            tableSelect.classList.remove('is-required');
            roomSelect.classList.remove('is-required');
            loc.value = '';
            if (hint) {
                hint.style.display = 'none';
                hint.textContent = '';
                hint.classList.remove('is-warn');
            }
            if (t === 'dine_in') {
                tableSelect.style.display = '';
                tableSelect.required = true;
                tableSelect.classList.add('is-required');
                if (hint) {
                    hint.style.display = 'block';
                    if (!posRestaurantTables.length) {
                        hint.textContent = 'No active restaurant tables are configured. Ask an admin to set Restaurant Tables first.';
                        hint.classList.add('is-warn');
                    } else {
                        hint.textContent = 'Select a free table from the admin-managed table range.';
                    }
                }
            } else if (t === 'room_service') {
                roomSelect.style.display = '';
                roomSelect.required = true;
                roomSelect.classList.add('is-required');
                if (hint) {
                    hint.style.display = 'block';
                    if (!posCheckedInRooms.length) {
                        hint.textContent = 'No checked-in rooms are available for room-service orders.';
                        hint.classList.add('is-warn');
                    } else {
                        hint.textContent = 'Only checked-in rooms are listed. Busy rooms are disabled until their active order is served, settled, cancelled, or completed.';
                    }
                }
            } else {
                loc.value = '';
            }
            syncServiceLocation();
        }

        function syncServiceLocation() {
            const t = document.getElementById('ctxOrderType')?.value || 'walk_in';
            const hidden = document.getElementById('ctxLocation');
            const tableSelect = document.getElementById('ctxTableSelect');
            const roomSelect = document.getElementById('ctxRoomSelect');
            const hint = document.getElementById('ctxLocationHint');
            if (!hidden) return;
            if (t === 'dine_in') {
                hidden.value = tableSelect?.value || '';
                const opt = tableSelect?.selectedOptions?.[0];
                if (hint && opt && opt.value) {
                    const cap = opt.dataset.capacity || '';
                    hint.textContent = cap ? ('Table ' + opt.value + ' seats ' + cap + '.') : ('Table ' + opt.value + ' selected.');
                    hint.classList.remove('is-warn');
                    hint.style.display = 'block';
                }
            } else if (t === 'room_service') {
                hidden.value = roomSelect?.value || '';
                const opt = roomSelect?.selectedOptions?.[0];
                if (hint && opt && opt.value) {
                    hint.textContent = 'Room ' + opt.value + ' selected.';
                    hint.classList.remove('is-warn');
                    hint.style.display = 'block';
                }
            } else {
                hidden.value = '';
            }
        }

        function validateServiceContext() {
            const t = document.getElementById('ctxOrderType')?.value || 'walk_in';
            const loc = (document.getElementById('ctxLocation')?.value || '').trim();
            if ((t === 'dine_in' || t === 'room_service') && !loc) {
                const label = t === 'dine_in' ? 'a table number' : 'a room number';
                posToastReady('Enter ' + label + ' before sending the order.', true);
                const el = document.getElementById(t === 'dine_in' ? 'ctxTableSelect' : 'ctxRoomSelect');
                if (el) {
                    el.style.display = '';
                    el.focus();
                }
                return false;
            }
            return true;
        }

        function updatePaySvcSummary() {
            const txt = document.getElementById('paySvcSummaryText');
            if (!txt) return;
            const t = document.getElementById('ctxOrderType')?.value || 'walk_in';
            const loc = (document.getElementById('ctxLocation')?.value || '').trim();
            const customer = (document.getElementById('ctxCustomer')?.value || '').trim();
            const parts = [SVC_LABEL[t] || 'Walk-in'];
            if (loc) parts.push((t === 'room_service' ? 'Room ' : 'Table ') + loc);
            if (customer) parts.push(customer);
            txt.textContent = parts.join(' · ');
        }

        /* ===== Active tab (add-to-tab) state ===========================
           When set, the Fire button appends the cart to an existing open tab
           instead of opening a new one. Pay-now is disabled in this mode. */
        let _activeTab = null; // { id, ref, total }

        function startAddToTab(id, ref, total) {
            setActiveTab(id, ref, total);
            // Return to the menu so the user can build the next round
            if (typeof closeTabsTray === 'function') closeTabsTray();
            const tabsOv = document.getElementById('tabsOverlay');
            if (tabsOv) tabsOv.classList.remove('show');
            // Ensure the cart drawer is visible on mobile
            const mainCart = document.getElementById('mainCart');
            if (mainCart && window.matchMedia('(max-width: 1024px)').matches && !mainCart.classList.contains('open')) {
                toggleCartDrawer();
            }
            posToastReady('Adding to ' + ref + ' — tap items then Fire to append.', false);
        }

        function setActiveTab(id, ref, total) {
            _activeTab = { id: parseInt(id, 10) || 0, ref: String(ref || ''), total: parseFloat(total) || 0 };
            updateActiveTabBanner();
            updateFireButton();
            renderCart();
        }

        function clearActiveTab(announce = false) {
            const had = _activeTab;
            _activeTab = null;
            updateActiveTabBanner();
            updateFireButton();
            renderCart();
            if (announce && had) posToastReady('Stopped adding to ' + had.ref + '. New orders open a fresh tab.', false);
        }

        function updateActiveTabBanner() {
            const banner = document.getElementById('activeTabBanner');
            if (!banner) return;
            if (_activeTab) {
                const refEl = document.getElementById('activeTabBannerRef');
                const totEl = document.getElementById('activeTabBannerTotal');
                if (refEl) refEl.textContent = _activeTab.ref;
                if (totEl) totEl.textContent = currencySymbol + ' ' + fmtMoney(_activeTab.total);
                banner.style.display = 'flex';
            } else {
                banner.style.display = 'none';
            }
            // Pay-now is meaningless when appending to a tab — disable it
            const payBtn = document.getElementById('payBtn');
            if (payBtn) {
                if (_activeTab) {
                    payBtn.disabled = true;
                    payBtn.title = 'Settle from the Tabs tray when adding to an existing tab';
                } else {
                    payBtn.disabled = !cart.length;
                    payBtn.title = '';
                }
            }
        }

        /* ===== Repeat last round ======================================= */
        let _lastRoundItems = []; // [{id,type,qty,note,name,price}]

        function rememberLastRound() {
            if (!cart.length) return;
            _lastRoundItems = cart.map(l => ({ id: l.id, type: l.type, qty: l.qty, note: l.note || '', name: l.name, price: l.price }));
            updateRepeatButton();
        }

        function updateRepeatButton() {
            const btn = document.getElementById('repeatRoundBtn');
            if (!btn) return;
            if (_lastRoundItems.length && !cart.length) {
                const cnt = document.getElementById('repeatRoundCount');
                if (cnt) cnt.textContent = String(_lastRoundItems.reduce((s, l) => s + (parseFloat(l.qty) || 0), 0));
                btn.style.display = 'block';
            } else {
                btn.style.display = 'none';
            }
        }

        function repeatLastRound() {
            if (!_lastRoundItems.length) {
                posToastReady('No previous round to repeat yet.', true);
                return;
            }
            // Re-load the items into the cart so the user can review before firing
            cart = _lastRoundItems.map(l => ({ id: l.id, type: l.type, qty: l.qty, note: l.note || '', name: l.name, price: l.price }));
            renderCart();
            posToastReady('Last round loaded — review then Fire / Add.', false);
        }

        function parkOrder() {
            if (!cart.length) return;
            // When appending to an existing tab the location/customer come from the tab itself.
            if (!_activeTab && !validateServiceContext()) return;
            const {
                hasFood,
                hasDrink
            } = cartStations();
            const kitchenBlocked = hasFood && !posKitchenOpen;
            const barBlocked = hasDrink && !posBarOpen;
            if (kitchenBlocked || barBlocked) {
                // Build a station-specific warning message
                const parts = [];
                if (kitchenBlocked) parts.push(`Kitchen (${posKitchenHours})`);
                if (barBlocked) parts.push(`Bar (${posBarHours})`);
                const closed = parts.join(' and ');
                const emoji = hasFood && hasDrink ? '⚠️' : (kitchenBlocked ? '🍳' : '🍷');
                const el = document.getElementById('stationClosedEmoji');
                if (el) el.textContent = emoji;
                const ti = document.getElementById('stationClosedTitle');
                if (ti) ti.textContent = closed + ' Closed';
                const ms = document.getElementById('stationClosedMsg');
                if (ms) ms.textContent = `${closed} ${parts.length > 1 ? 'are' : 'is'} currently closed. The ticket will sit unseen on the station display until it reopens. Proceed?`;
                showKitchenClosedWarning(() => doParkSubmit());
                return;
            }
            doParkSubmit();
        }

        async function doParkSubmit() {
            if (window._posParkSubmitInFlight) return;
            window._posParkSubmitInFlight = true;
            document.getElementById('payment_method').value = '';
            injectCartHidden('payHiddenItems');
            const f = document.getElementById('payForm');
            if (!f) {
                window._posParkSubmitInFlight = false;
                return;
            }
            const addingToTab = !!_activeTab;
            let actionInput = f.querySelector('input[name="action"]');
            if (!actionInput) {
                actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                f.appendChild(actionInput);
            }
            // Manage the tab_order_id hidden field
            let tabIdInput = f.querySelector('input[name="tab_order_id"]');
            if (addingToTab) {
                if (!tabIdInput) {
                    tabIdInput = document.createElement('input');
                    tabIdInput.type = 'hidden';
                    tabIdInput.name = 'tab_order_id';
                    f.appendChild(tabIdInput);
                }
                tabIdInput.value = String(_activeTab.id);
                actionInput.value = 'add_to_tab';
            } else {
                if (tabIdInput) tabIdInput.value = '';
                actionInput.value = 'park';
            }
            // New tab → fresh idempotency key each time (AJAX stays on page). Add-to-tab
            // doesn't use client_uuid server-side, so a fresh value is harmless there too.
            posRefreshClientUuid(f);
            // Remember this round for the "Repeat last round" button before the cart is cleared
            rememberLastRound();
            showPosActionLoader(addingToTab ? 'Adding to tab...' : 'Firing order...', addingToTab ? 'Appending your items to the open tab.' : 'Sending your ticket to the station display.', { subtle: true });
            try {
                const fd = new FormData(f);
                const r = await fetch('pos.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const j = await r.json();
                hidePosActionLoader();
                if (!j.ok) {
                    posToastReady(j.error || (addingToTab ? 'Failed to add to tab — please retry.' : 'Failed to fire order — please retry.'), true);
                    return;
                }
                if (addingToTab) {
                    // Keep the tab active so the user can keep adding rounds; update running total
                    if (_activeTab && typeof j.new_total !== 'undefined') {
                        _activeTab.total = parseFloat(j.new_total) || _activeTab.total;
                        updateActiveTabBanner();
                    }
                    cart = [];
                    renderCart();
                    updateRepeatButton();
                    posToastReady(j.message || ('Added to ' + (j.reference || 'tab') + '.'), false);
                    refreshOpenTabs(false);
                } else {
                    showPosParkSuccess(j);
                }
            } catch (e) {
                hidePosActionLoader();
                posToastReady('Network error — could not reach the server. Please retry.', true);
            } finally {
                window._posParkSubmitInFlight = false;
            }
        }

        function showPosParkSuccess(j) {
            // Clear the cart first
            cart = [];
            renderCart();
            updateRepeatButton();
            // Reset covers for the next (new) order
            const coversEl = document.getElementById('ctxCovers');
            if (coversEl) coversEl.value = '';
            const ref = escHtml(j.reference || '');
            const refJson = JSON.stringify(String(j.reference || ''));
            const stn = escHtml(j.station_label || 'Station');
            const total = parseFloat(j.total || 0);
            const orderId = parseInt(j.order_id, 10) || 0;
            const linesHtml = (j.lines || []).map(l =>
                `<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f3f4f6;font-size:13px;">` +
                `<span>${escHtml(l.item_name)}</span><span style="font-weight:600;">${escHtml(String(l.quantity))}×</span></div>`
            ).join('');
            // Inject inline success overlay
            const existing = document.getElementById('posParkSuccessOverlay');
            if (existing) existing.remove();
            const html = `<div class="overlay modal-overlay show" id="posParkSuccessOverlay" data-modal data-dismissible="1">
                <div class="success-modal modal-content" style="position:relative;max-width:440px;width:100%;">
                    <button type="button" class="close modal-close" aria-label="Close" onclick="document.getElementById('posParkSuccessOverlay').remove()"
                        style="position:absolute;top:10px;right:14px;background:transparent;border:none;font-size:26px;line-height:1;cursor:pointer;color:#6c757d;">&times;</button>
                    <div class="icon" style="color:#e85d04;"><i class="fas fa-fire"></i></div>
                    <h2>Order Fired!</h2>
                    <div class="ref">${ref}</div>
                    <div style="font-size:13px;color:#155724;margin-bottom:10px;">Sent to ${stn} · ${currencySymbol} ${fmtMoney(total)} · open tab</div>
                    ${linesHtml ? `<div style="max-height:180px;overflow-y:auto;margin:10px 0;border:1px solid #f3f4f6;border-radius:8px;padding:6px 10px;">${linesHtml}</div>` : ''}
                    <div class="actions">
                        <a class="a-print" href="stock-receipt.php?id=${encodeURIComponent(j.order_id)}&print=1&kot=1" target="_blank"><i class="fas fa-print"></i> Print KOT</a>
                        <button class="a-receipt" onclick="document.getElementById('posParkSuccessOverlay').remove(); startAddToTab(${orderId}, ${refJson}, ${total});"><i class="fas fa-plus"></i> Add more</button>
                        <button class="a-receipt" onclick="document.getElementById('posParkSuccessOverlay').remove(); openTabsTray();"><i class="fas fa-list"></i> View Tabs</button>
                        <button class="a-new" onclick="document.getElementById('posParkSuccessOverlay').remove();"><i class="fas fa-plus-circle"></i> New order</button>
                    </div>
                </div>
            </div>`;
            document.body.insertAdjacentHTML('beforeend', html);
            if (typeof posModalBridgeApply === 'function') {
                const el = document.getElementById('posParkSuccessOverlay');
                if (el) posModalBridgeApply(el);
            }
        }


        /* Toolbar overflow menu (Float, All orders, Sounds, Guide, Admin). */
        function closePosMoreMenu() {
            const menu = document.getElementById('posMoreMenu');
            const btn = document.getElementById('posMoreBtn');
            if (!menu) return;
            menu.setAttribute('hidden', '');
            if (btn) {
                btn.setAttribute('aria-expanded', 'false');
                btn.classList.remove('is-open');
            }
        }

        function togglePosMoreMenu(e) {
            if (e) e.stopPropagation();
            const menu = document.getElementById('posMoreMenu');
            const btn = document.getElementById('posMoreBtn');
            if (!menu) return;
            const opening = menu.hasAttribute('hidden');
            if (opening) {
                menu.removeAttribute('hidden');
                positionPosMoreMenu();
                btn.setAttribute('aria-expanded', 'true');
                btn.classList.add('is-open');
            } else {
                closePosMoreMenu();
            }
        }

        /* The menu is position:fixed (see pos-overrides.css) so the toolbar's overflow can't
           clip it, which means its coordinates have to be set from the button each time. */
        function positionPosMoreMenu() {
            const menu = document.getElementById('posMoreMenu');
            const btn = document.getElementById('posMoreBtn');
            if (!menu || !btn || menu.hasAttribute('hidden')) return;
            const b = btn.getBoundingClientRect();
            const m = menu.getBoundingClientRect();
            const pad = 8;
            let left = b.right - m.width;
            left = Math.max(pad, Math.min(left, window.innerWidth - m.width - pad));
            let top = b.bottom + 6;
            if (top + m.height > window.innerHeight - pad) {
                top = Math.max(pad, b.top - m.height - 6); // flip above if it would overflow
            }
            menu.style.left = Math.round(left) + 'px';
            menu.style.top = Math.round(top) + 'px';
        }

        window.addEventListener('resize', positionPosMoreMenu);
        window.addEventListener('scroll', positionPosMoreMenu, true);

        document.addEventListener('click', function(e) {
            const menu = document.getElementById('posMoreMenu');
            if (!menu || menu.hasAttribute('hidden')) return;
            if (!e.target.closest('.tb-more')) closePosMoreMenu();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closePosMoreMenu();
        });

        /* Show/hide a tab card's secondary actions (Details, KOT, Cancel, Lifecycle, Void).
           Scoped to the card that was tapped so other open cards stay as they were. */
        function toggleTabCardActions(btn) {
            const card = btn.closest('.tab-card');
            if (!card) return;
            const panel = card.querySelector('.tc-actions--secondary');
            if (!panel) return;
            const open = panel.hasAttribute('hidden');
            if (open) panel.removeAttribute('hidden');
            else panel.setAttribute('hidden', '');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.classList.toggle('is-open', open);
        }

        function openTabsTray() {
            const overlay = document.getElementById('tabsOverlay');
            if (!overlay) return;
            overlay.classList.add('show');
            try {
                _tabsToolsExpanded = localStorage.getItem(POS_TABS_TOOLS_STATE_KEY) === '1';
            } catch (_) {
                _tabsToolsExpanded = false;
            }
            startTabsAutoRefresh();
            tabsSelectionChanged();
            applyTabsTrayToolsState();
            updateTabsTrayUpdatedLabel();
            refreshOpenTabs(true, {
                scopedLoader: true,
                loaderMessage: 'Fetching open tabs...'
            });
        }

        let _tabsRefreshInFlight = false;
        let _selectedOpenTabIds = new Set();
        let _tabsAutoRefreshTimer = null;
        let _tabsToolsExpanded = false;
        const POS_TABS_TOOLS_STATE_KEY = 'rh_pos_tabs_tools_expanded_v1';

        function tabCreatedSeconds(createdAt) {
            const ts = Date.parse(String(createdAt || '').replace(' ', 'T'));
            return Number.isNaN(ts) ? 0 : Math.floor(ts / 1000);
        }

        function tabAgeLabel(createdAt) {
            const created = tabCreatedSeconds(createdAt);
            if (!created) return '';
            return fmtAgeSec(Math.max(0, Math.floor(Date.now() / 1000) - created));
        }

        function tabAgeColor(createdAt) {
            const created = tabCreatedSeconds(createdAt);
            const sec = created ? Math.max(0, Math.floor(Date.now() / 1000) - created) : 0;
            if (sec >= 1800) return '#c82333';
            if (sec >= 900) return '#d4a843';
            return '#28a745';
        }

        function updateTabsTrayUpdatedLabel() {
            const updated = document.getElementById('tabsTrayUpdated');
            if (!updated) return;
            const stamp = new Date().toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            updated.textContent = 'Live · ' + stamp;
        }

        function applyTabsTrayToolsState() {
            const tools = document.getElementById('tabsTrayTools');
            const panel = document.getElementById('tabsTrayBulkPanel');
            const toggle = document.getElementById('tabsTrayToggleBtn');
            if (!tools || !panel || !toggle) return;

            tools.classList.toggle('is-collapsed', !_tabsToolsExpanded);
            panel.hidden = !_tabsToolsExpanded;
            toggle.setAttribute('aria-expanded', _tabsToolsExpanded ? 'true' : 'false');
            toggle.innerHTML = _tabsToolsExpanded ? '<i class="fas fa-chevron-up"></i> Hide bulk' : '<i class="fas fa-sliders"></i> Bulk tools';
        }

        function toggleTabsTrayTools(forceExpanded = null) {
            if (typeof forceExpanded === 'boolean') {
                _tabsToolsExpanded = forceExpanded;
            } else {
                _tabsToolsExpanded = !_tabsToolsExpanded;
            }
            try {
                localStorage.setItem(POS_TABS_TOOLS_STATE_KEY, _tabsToolsExpanded ? '1' : '0');
            } catch (_) {
                // localStorage unavailable in private modes — ignore.
            }
            applyTabsTrayToolsState();
        }

        function startTabsAutoRefresh() {
            if (_tabsAutoRefreshTimer) return;
            _tabsAutoRefreshTimer = window.setInterval(() => {
                const overlay = document.getElementById('tabsOverlay');
                if (!overlay || !overlay.classList.contains('show')) return;
                refreshOpenTabs(false);
            }, 10000);
        }

        function stopTabsAutoRefresh() {
            if (!_tabsAutoRefreshTimer) return;
            window.clearInterval(_tabsAutoRefreshTimer);
            _tabsAutoRefreshTimer = null;
        }

        function renderTabsTrayTools(openCount, outstandingAmount, staleCount) {
            const openTabs = Math.max(0, parseInt(openCount, 10) || 0);
            const staleTabs = Math.max(0, parseInt(staleCount, 10) || 0);
            const outstanding = parseFloat(outstandingAmount || 0) || 0;
            const hasTabs = openTabs > 0;
            const disabledAttr = hasTabs ? '' : ' disabled';
            return `<div class="tabs-tray-tools${_tabsToolsExpanded ? '' : ' is-collapsed'}" id="tabsTrayTools">
                <div class="tabs-tray-tools__headline">
                    <div class="tabs-tray-tools__metrics">
                        <div class="tabs-tray-metric"><span class="tabs-tray-metric__label">Open</span><strong>${openTabs}</strong></div>
                        <div class="tabs-tray-metric"><span class="tabs-tray-metric__label">Outstanding</span><strong>${currencySymbol} ${fmtMoney(outstanding)}</strong></div>
                        <div class="tabs-tray-metric"><span class="tabs-tray-metric__label">Stale</span><strong>${staleTabs}</strong></div>
                    </div>
                    <div class="tabs-tray-tools__status">
                        <span class="tabs-tray-updated" id="tabsTrayUpdated">Live</span>
                        <button type="button" class="tabs-tray-toggle" id="tabsTrayToggleBtn" onclick="toggleTabsTrayTools()" aria-expanded="${_tabsToolsExpanded ? 'true' : 'false'}">${_tabsToolsExpanded ? '<i class="fas fa-chevron-up"></i> Hide bulk' : '<i class="fas fa-sliders"></i> Bulk tools'}</button>
                    </div>
                </div>
                <div class="tabs-tray-tools__bulk-panel" id="tabsTrayBulkPanel"${_tabsToolsExpanded ? '' : ' hidden'}>
                    <div class="tabs-tray-tools__bulk-row">
                        <label class="tabs-bulk-check"><input type="checkbox" id="tabsBulkAll" onchange="toggleTabsBulkAll(this.checked)"${disabledAttr}> <span>Select all</span></label>
                        <button type="button" class="tabs-bulk-btn" onclick="tabsSelectStale()"${disabledAttr}><i class="fas fa-triangle-exclamation"></i> Select stale</button>
                        <button type="button" class="tabs-bulk-btn" onclick="tabsClearSelection()"${disabledAttr}><i class="fas fa-broom"></i> Clear</button>
                        <span class="tabs-bulk-info" id="tabsBulkSelectionInfo">No tabs selected</span>
                    </div>
                    <div class="tabs-tray-tools__bulk-actions">
                        <button type="button" class="tabs-bulk-btn tabs-bulk-btn--cancel" id="tabsBulkCancelBtn" onclick="bulkCancelTabs()" disabled><i class="fas fa-circle-xmark"></i> Bulk cancel</button>
                        ${posCanManageTabs ? '<button type="button" class="tabs-bulk-btn tabs-bulk-btn--void" id="tabsBulkVoidBtn" onclick="bulkVoidTabs()" disabled><i class="fas fa-ban"></i> Bulk void</button>' : ''}
                    </div>
                </div>
            </div>`;
        }

        function getOpenTabSelectionInputs() {
            return Array.from(document.querySelectorAll('#tabsCardsList .tc-select-input'));
        }

        function getSelectedOpenTabs(filterFn = null) {
            const selected = getOpenTabSelectionInputs().filter(input => input.checked).map(input => ({
                orderId: parseInt(input.dataset.orderId || '0', 10) || 0,
                ref: String(input.dataset.ref || 'TAB'),
                total: parseFloat(input.dataset.total || '0') || 0,
                canCancel: input.dataset.canCancel === '1',
                isStale: input.dataset.isStale === '1',
            })).filter(tab => tab.orderId > 0);
            return typeof filterFn === 'function' ? selected.filter(filterFn) : selected;
        }

        function tabsSelectionChanged() {
            const inputs = getOpenTabSelectionInputs();
            const selectedTabs = getSelectedOpenTabs();
            const selectedIds = selectedTabs.map(tab => tab.orderId);
            _selectedOpenTabIds = new Set(selectedIds);

            const totalTabs = inputs.length;
            const selectedCount = selectedTabs.length;
            const cancellableSelected = selectedTabs.filter(tab => tab.canCancel).length;
            const staleSelected = selectedTabs.filter(tab => tab.isStale).length;

            const selectAll = document.getElementById('tabsBulkAll');
            if (selectAll) {
                selectAll.checked = totalTabs > 0 && selectedCount === totalTabs;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < totalTabs;
            }

            const summary = document.getElementById('tabsBulkSelectionInfo');
            if (summary) {
                if (selectedCount === 0) {
                    summary.textContent = 'No tabs selected';
                } else {
                    summary.textContent = selectedCount + ' selected' + (staleSelected > 0 ? (' · ' + staleSelected + ' stale') : '');
                }
            }

            const cancelBtn = document.getElementById('tabsBulkCancelBtn');
            if (cancelBtn) cancelBtn.disabled = cancellableSelected === 0;

            const voidBtn = document.getElementById('tabsBulkVoidBtn');
            if (voidBtn) voidBtn.disabled = selectedCount === 0;
        }

        function toggleTabsBulkAll(checked) {
            getOpenTabSelectionInputs().forEach(input => {
                input.checked = !!checked;
            });
            tabsSelectionChanged();
        }

        function tabsSelectStale() {
            const inputs = getOpenTabSelectionInputs();
            if (!inputs.length) return;
            inputs.forEach(input => {
                input.checked = input.dataset.isStale === '1';
            });
            tabsSelectionChanged();
        }

        function tabsClearSelection() {
            getOpenTabSelectionInputs().forEach(input => {
                input.checked = false;
            });
            _selectedOpenTabIds.clear();
            tabsSelectionChanged();
        }

        async function bulkCancelTabs() {
            const selected = getSelectedOpenTabs(tab => tab.canCancel);
            if (!selected.length) {
                posToast('Select tabs that are still pending to bulk-cancel.', 'err');
                return;
            }
            const reason = await posAskReason({
                title: 'Bulk cancel tabs',
                prompt: `Cancel <strong>${selected.length}</strong> selected tab${selected.length === 1 ? '' : 's'}? This works only while all items are still pending (not yet cooking).`,
                warn: 'Bulk cancel does not affect tabs already in preparation. Those are skipped automatically.',
                confirmLabel: 'Cancel selected',
                confirmColor: '#7c2d12',
                hasNotes: false,
            });
            if (!reason) return;

            showPosActionLoader('Bulk cancelling tabs…', 'Applying your action to selected tabs.');
            let okCount = 0;
            let failCount = 0;
            const failedRefs = [];

            for (const tab of selected) {
                const fd = new FormData();
                fd.append('csrf_token', '<?php echo $csrf_token; ?>');
                fd.append('order_id', String(tab.orderId));
                fd.append('cancel_reason', reason);
                try {
                    const resp = await fetch(posApiUrl('cancel-order.php'), {
                        method: 'POST',
                        body: fd,
                        credentials: 'include'
                    });
                    const j = await resp.json();
                    if (j.ok) {
                        okCount++;
                    } else {
                        failCount++;
                        failedRefs.push(tab.ref);
                    }
                } catch (error) {
                    failCount++;
                    failedRefs.push(tab.ref);
                }
            }

            hidePosActionLoader();
            if (okCount > 0 && failCount === 0) {
                posToast(okCount + ' tab' + (okCount === 1 ? '' : 's') + ' cancelled.', 'ok');
            } else if (okCount > 0) {
                posToast(okCount + ' cancelled, ' + failCount + ' failed.', 'err');
            } else {
                posToast('Bulk cancel failed for selected tabs.', 'err');
            }
            if (failedRefs.length) {
                posToast('Failed: ' + failedRefs.slice(0, 3).join(', ') + (failedRefs.length > 3 ? '…' : ''), 'err');
            }

            _selectedOpenTabIds.clear();
            await refreshOpenTabs(true);
            refreshShiftStats(true);
        }

        async function bulkVoidTabs() {
            if (!posCanManageTabs) {
                posToast('Only admin/manager can void tabs in bulk.', 'err');
                return;
            }
            const selected = getSelectedOpenTabs();
            if (!selected.length) {
                posToast('Select at least one tab to void.', 'err');
                return;
            }
            const reason = await posAskReason({
                title: 'Bulk void tabs',
                prompt: `Void <strong>${selected.length}</strong> selected tab${selected.length === 1 ? '' : 's'}? Stock will be restored for ready items and station tickets removed.`,
                warn: 'Bulk void is permanent, admin/manager only, and fully audit-logged.',
                confirmLabel: 'Void selected',
                confirmColor: '#c82333',
                hasNotes: true,
            });
            if (!reason) return;

            const notes = document.getElementById('prmNotes')?.value?.trim() || '';
            showPosActionLoader('Bulk voiding tabs…', 'Applying admin action to selected tabs.');
            let okCount = 0;
            let failCount = 0;
            const failedRefs = [];

            for (const tab of selected) {
                const fd = new FormData();
                fd.append('csrf_token', '<?php echo $csrf_token; ?>');
                fd.append('order_id', String(tab.orderId));
                fd.append('void_reason', reason);
                fd.append('void_notes', notes);
                try {
                    const resp = await fetch(posApiUrl('void-order.php'), {
                        method: 'POST',
                        body: fd,
                        credentials: 'include'
                    });
                    const j = await resp.json();
                    if (j.ok) {
                        okCount++;
                    } else {
                        failCount++;
                        failedRefs.push(tab.ref);
                    }
                } catch (error) {
                    failCount++;
                    failedRefs.push(tab.ref);
                }
            }

            hidePosActionLoader();
            if (okCount > 0 && failCount === 0) {
                posToast(okCount + ' tab' + (okCount === 1 ? '' : 's') + ' voided.', 'ok');
            } else if (okCount > 0) {
                posToast(okCount + ' voided, ' + failCount + ' failed.', 'err');
            } else {
                posToast('Bulk void failed for selected tabs.', 'err');
            }
            if (failedRefs.length) {
                posToast('Failed: ' + failedRefs.slice(0, 3).join(', ') + (failedRefs.length > 3 ? '…' : ''), 'err');
            }

            _selectedOpenTabIds.clear();
            await refreshOpenTabs(true);
            refreshShiftStats(true);
        }

        function renderOpenTabs(tabs, windowStart) {
            const title = document.getElementById('openTabsTitle');
            const body = document.getElementById('tabsTrayBody');
            if (title) title.innerHTML = '<i class="fas fa-utensils"></i> Open tabs (' + tabs.length + ')';
            if (!body) return;
            const orderedTabs = [...tabs].sort((a, b) => tabCreatedSeconds(a.created_at) - tabCreatedSeconds(b.created_at));
            const staleCount = orderedTabs.filter(t => windowStart && String(t.created_at || '') < String(windowStart || '')).length;
            const outstandingTotal = orderedTabs.reduce((sum, t) => sum + (parseFloat(t.total_amount || 0) || 0), 0);

            if (!orderedTabs.length) {
                _selectedOpenTabIds.clear();
                body.innerHTML = renderTabsTrayTools(0, 0, 0) + '<p style="text-align:center; color:#6c757d; padding:30px 0;">No open tabs.</p>';
                tabsSelectionChanged();
                applyTabsTrayToolsState();
                updateTabsTrayUpdatedLabel();
                return;
            }
            body.innerHTML = renderTabsTrayTools(orderedTabs.length, outstandingTotal, staleCount) + `<div class="tab-cards-list" id="tabsCardsList">` + orderedTabs.map(t => {
                const orderId = parseInt(t.id, 10) || 0;
                const createdSec = tabCreatedSeconds(t.created_at);
                const totalItems = parseInt(t.line_count || 0, 10) || 0;
                const pendingCount = parseInt(t.pending_count || 0, 10) || 0;
                const preparingCount = parseInt(t.preparing_count || 0, 10) || 0;
                const readyCount = parseInt(t.ready_count || 0, 10) || 0;
                const collectionCount = parseInt(t.collection_count || 0, 10) || 0;
                const servedCount = parseInt(t.served_count || 0, 10) || 0;
                const canCancelBeforePrep = pendingCount > 0 && preparingCount === 0 && readyCount === 0 && collectionCount === 0 && servedCount === 0;
                const canSettle = totalItems > 0; // bar items auto-served on settle; server checks kitchen items
                const isStale = windowStart && String(t.created_at || '') < String(windowStart || '');
                const byOther = parseInt(t.created_by || 0, 10) !== posUserId;
                const openedAt = createdSec ? new Date(createdSec * 1000).toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit'
                }) : '—';
                const ref = escHtml(t.reference || 'TAB');
                const actionRef = escHtml(JSON.stringify(String(t.reference || 'TAB')));
                const refAttr = escHtml(String(t.reference || 'TAB'));
                const flow = [
                    pendingCount > 0 ? `<span class="tc-flow-chip pending"><i class="fas fa-clock"></i> ${pendingCount} pending</span>` : '',
                    preparingCount > 0 ? `<span class="tc-flow-chip preparing"><i class="fas fa-fire-burner"></i> ${preparingCount} prep</span>` : '',
                    readyCount > 0 ? `<span class="tc-flow-chip ready"><i class="fas fa-bell"></i> ${readyCount} ready</span>` : '',
                    collectionCount > 0 ? `<span class="tc-flow-chip collection"><i class="fas fa-hand-holding"></i> ${collectionCount} collecting</span>` : '',
                    servedCount > 0 ? `<span class="tc-flow-chip served"><i class="fas fa-check"></i> ${servedCount} served</span>` : '',
                    totalItems > 0 && servedCount === totalItems ? '<span class="tc-flow-chip served-all"><i class="fas fa-circle-check"></i> All served</span>' : ''
                ].join('');
                const coversN = parseInt(t.covers || 0, 10) || 0;
                const metaPills = [
                    t.table_number ? `<span class="tc-meta-pill"><i class="fas fa-table-cells-large"></i> Table ${escHtml(t.table_number)}</span>` : '',
                    t.customer_name ? `<span class="tc-meta-pill"><i class="fas fa-user"></i> ${escHtml(t.customer_name)}</span>` : '',
                    coversN > 0 ? `<span class="tc-meta-pill"><i class="fas fa-users"></i> ${coversN} cover${coversN === 1 ? '' : 's'}</span>` : '',
                    `<span class="tc-meta-pill"><i class="fas fa-list"></i> ${totalItems} item${totalItems === 1 ? '' : 's'}</span>`,
                    `<span class="tc-meta-pill"><i class="fas fa-clock"></i> Opened ${escHtml(openedAt)}</span>`,
                    byOther ? `<span class="tc-meta-pill"><i class="fas fa-user-tie"></i> ${escHtml(t.opened_by || 'staff')}</span>` : `<span class="tc-meta-pill"><i class="fas fa-user-check"></i> You</span>`
                ].filter(Boolean).join('');
                const managerTools = posCanManageTabs ? `
                    <button type="button" onclick="openPosPageModal('order-lifecycle.php?id=${orderId}','Timeline','fas fa-stream')" class="tc-btn tc-btn-log"><i class="fas fa-stream"></i> Lifecycle</button>
                    <button type="button" onclick="adminVoidTab(${orderId}, ${actionRef})" class="tc-btn tc-btn-void"><i class="fas fa-ban"></i> Void</button>` : '';
                return `<article class="tab-card${isStale ? ' stale' : ''}" data-order-id="${orderId}" data-is-stale="${isStale ? '1' : '0'}">
                    <div class="tc-row1">
                        <label class="tc-select-wrap" aria-label="Select ${ref}">
                            <input type="checkbox" class="tc-select-input" data-order-id="${orderId}" data-ref="${refAttr}" data-total="${parseFloat(t.total_amount || 0) || 0}" data-can-cancel="${canCancelBeforePrep ? '1' : '0'}" data-is-stale="${isStale ? '1' : '0'}" onchange="tabsSelectionChanged()"${_selectedOpenTabIds.has(orderId) ? ' checked' : ''}>
                            <span class="tc-select-indicator"><i class="fas fa-check"></i></span>
                        </label>
                        <div class="tc-ref">${ref}</div>
                        <div class="tc-age tab-age" data-created="${createdSec}" style="color:${tabAgeColor(t.created_at)};"><i class="fas fa-stopwatch"></i> ${escHtml(tabAgeLabel(t.created_at))}</div>
                    </div>
                    <div class="tc-meta">${metaPills}</div>
                    ${totalItems > 0 ? `<div class="tc-flow">${flow}</div>` : ''}
                    <div class="tc-summary-row">
                        <div>
                            <span class="tc-total-label">Total</span>
                            <div class="tc-total">${currencySymbol} ${fmtMoney(t.total_amount)}</div>
                        </div>
                        ${(parseInt(t.split_count||1) > 1 && parseInt(t.split_paid_count||0) > 0)
                            ? `<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:5px;padding:3px 8px;font-size:11px;font-weight:600;color:#92400e;"><i class="fas fa-users" style="margin-right:3px;"></i>Split ${parseInt(t.split_paid_count||0)}/${parseInt(t.split_count||1)} paid</div>`
                            : (parseInt(t.split_count||1) > 1 ? `<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:5px;padding:3px 8px;font-size:11px;font-weight:600;color:#0369a1;"><i class="fas fa-users" style="margin-right:3px;"></i>Split ×${parseInt(t.split_count||1)}</div>` : '')}
                        ${isStale ? '<div class="tc-stale-warn" data-help="From an earlier shift|Settle it as normal — if kitchen items were never bumped, the Pay button will offer a manager force-serve override."><i class="fas fa-triangle-exclamation"></i> Previous shift</div>' : ''}
                    </div>
                    <div class="tc-actions">
                        ${parseInt(t.split_paid_count||0) === 0 ? `<button type="button" onclick="startAddToTab(${orderId}, ${actionRef}, ${parseFloat(t.total_amount || 0) || 0})" class="tc-btn tc-btn-add" data-help="Add items|Add another round to this tab. Returns you to the menu; the next Fire adds to this tab."><i class="fas fa-plus"></i> Add items</button>` : ''}
                        <button type="button" onclick="openPayForTab(${orderId}, ${parseFloat(t.total_amount || 0) || 0}, ${actionRef}, ${canSettle ? 'true' : 'false'}, ${parseInt(t.split_count||1)||1}, ${parseInt(t.split_paid_count||0)||0})" class="tc-btn tc-btn-settle" data-help="Settle tab|Close this tab — take payment and mark the order as paid."><i class="fas fa-credit-card"></i> Settle</button>
                        <button type="button" class="tc-btn tc-btn-more" onclick="toggleTabCardActions(this)" aria-expanded="false"><i class="fas fa-ellipsis"></i> More</button>
                    </div>
                    <div class="tc-actions tc-actions--secondary" hidden>
                        <button type="button" onclick="openTabDetail(${orderId})" class="tc-btn tc-btn-detail"><i class="fas fa-receipt"></i> Details</button>
                        <button type="button" onclick="openPosPageModal('stock-receipt.php?id=${orderId}&print=1&kot=1','Print KOT','fas fa-print')" class="tc-btn tc-btn-kot"><i class="fas fa-print"></i> KOT</button>
                        ${canCancelBeforePrep ? `<button type="button" onclick="cancelOpenOrder(${orderId}, ${actionRef})" class="tc-btn tc-btn-cancel"><i class="fas fa-circle-xmark"></i> Cancel</button>` : ''}
                        ${managerTools}
                    </div>
                </article>`;
            }).join('') + '</div>';
            tabsSelectionChanged();
            applyTabsTrayToolsState();
            updateTabsTrayUpdatedLabel();
            // Re-apply any active search filter after the DOM is rebuilt
            if (_tabsSearchQuery) filterOpenTabCards(_tabsSearchQuery);
        }

        async function refreshOpenTabs(force = false, options = {}) {
            if (_tabsRefreshInFlight) return;
            _tabsRefreshInFlight = true;
            const useScopedLoader = !!options.scopedLoader;
            const releaseScopedLoader = useScopedLoader ?
                showPosScopedLoader(document.getElementById('tabsTrayBody'), options.loaderMessage || 'Refreshing tabs...') :
                function() {
                    return;
                };
            try {
                const response = await fetch('pos.php?ajax=tabs', {
                    credentials: 'same-origin'
                });
                const data = await response.json();
                const tabs = Array.isArray(data.tabs) ? data.tabs : [];
                renderOpenTabs(tabs, data.window_start || '');
                const setBadge = (id, n) => {
                    const el = document.getElementById(id);
                    if (!el) return;
                    el.textContent = n > 99 ? '99+' : String(n);
                    el.style.display = n > 0 ? '' : 'none';
                };
                setBadge('tabBadge', tabs.length);
                _syncPosMobileBadges();
                updateTabsTrayUpdatedLabel();
                if (force) tickTabAges();
            } catch (e) {
                if (force) posToastReady('Could not refresh open tabs.', true);
            } finally {
                releaseScopedLoader();
                _tabsRefreshInFlight = false;
            }
        }

        /* Live tab-age timers (updates every 15s) */
        function fmtAgeSec(sec) {
            const m = Math.floor(sec / 60),
                s = sec % 60;
            return m > 0 ? (m + 'm ' + String(s).padStart(2, '0') + 's') : (s + 's');
        }

        function tickTabAges() {
            const now = Math.floor(Date.now() / 1000);
            document.querySelectorAll('.tab-age').forEach(el => {
                const c = parseInt(el.dataset.created || '0', 10);
                if (!c) return;
                const sec = Math.max(0, now - c);
                el.innerHTML = '<i class="fas fa-stopwatch"></i> ' + fmtAgeSec(sec);
                el.style.color = sec >= 1800 ? '#c82333' : (sec >= 900 ? '#d4a843' : '#28a745');
            });
        }
        RHPoll.every(tickTabAges, 15000);
        document.addEventListener('DOMContentLoaded', tickTabAges);

        function closeTabsTray() {
            const overlay = document.getElementById('tabsOverlay');
            if (overlay) overlay.classList.remove('show');
            stopTabsAutoRefresh();
            _selectedOpenTabIds.clear();
            // Clear search so next open starts fresh
            const si = document.getElementById('tabsSearchInput');
            if (si) { si.value = ''; _tabsSearchQuery = ''; }
        }

        let _tabsSearchQuery = '';
        function filterOpenTabCards(query) {
            _tabsSearchQuery = (query || '').trim().toLowerCase();
            const cards = document.querySelectorAll('#tabsCardsList .tab-card');
            let visible = 0;
            cards.forEach(card => {
                if (!_tabsSearchQuery) { card.style.display = ''; visible++; return; }
                const text = (card.textContent || '').toLowerCase();
                const match = text.includes(_tabsSearchQuery);
                card.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            // Show empty state if nothing matches
            let noMatch = document.getElementById('tabsSearchNoMatch');
            if (!noMatch) {
                noMatch = document.createElement('p');
                noMatch.id = 'tabsSearchNoMatch';
                noMatch.style.cssText = 'text-align:center;color:#9ca3af;padding:20px 0;font-size:13px;';
                noMatch.textContent = 'No tabs match your search.';
                const list = document.getElementById('tabsCardsList');
                if (list) list.after(noMatch);
            }
            noMatch.style.display = (_tabsSearchQuery && visible === 0) ? '' : 'none';
        }

        /* POS in-app toast notification (used by cancel/void and station note flows) */
        function closePosToast(closeButton) {
            const toast = closeButton?.closest?.('.toast');
            if (toast) toast.remove();
        }

        function posToast(msg, type, duration) {
            const timeoutMs = duration || POS_NOTIFICATION_DURATION_MS;
            const t = document.createElement('div');
            t.className = 'toast ' + (type === 'err' ? 'err' : 'ok');
            const text = document.createElement('span');
            text.className = 'toast__message';
            text.textContent = msg;
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'toast__close';
            close.setAttribute('aria-label', 'Close notification');
            close.innerHTML = '<i class="fas fa-xmark" aria-hidden="true"></i>';
            close.addEventListener('click', event => {
                event.preventDefault();
                event.stopPropagation();
                closePosToast(close);
            });
            t.append(text, close);
            document.body.appendChild(t);
            setTimeout(() => t.remove(), timeoutMs);
        }

        document.addEventListener('click', event => {
            const closeButton = event.target.closest?.('.toast__close');
            if (!closeButton) return;
            event.preventDefault();
            event.stopPropagation();
            closePosToast(closeButton);
        });
        /* Alias for older calls in pos.php (station notes etc.) */
        function posToastReady(msg, isErr) {
            posToast(msg, isErr ? 'err' : 'ok');
        }

        function posFriendlyIssue(rawMessage) {
            const raw = String(rawMessage || 'Action failed');
            const lower = raw.toLowerCase();
            if (lower.includes('already') || lower.includes('charged twice') || lower.includes('settled') || lower.includes('paid')) {
                return {
                    title: 'Tab already settled',
                    message: raw + ' The open tabs tray will refresh so the cashier does not take a duplicate payment.'
                };
            }
            if (lower.includes('open tab') || lower.includes('nothing') || lower.includes('pending')) {
                return {
                    title: 'Nothing pending here',
                    message: raw + ' Refresh the tabs tray and check whether the order has already moved to the station board or been settled.'
                };
            }
            if (lower.includes('ingredient') || lower.includes('stock') || lower.includes('86')) {
                return {
                    title: 'Stock needs attention',
                    message: raw + ' Ask a manager to receive stock, adjust the recipe, or 86 the item before continuing.'
                };
            }
            if (lower.includes('security') || lower.includes('csrf') || lower.includes('token')) {
                return {
                    title: 'Session check needed',
                    message: 'Refresh POS and try again so the security token is renewed.'
                };
            }
            return {
                title: 'POS action needs attention',
                message: raw
            };
        }

        function posAlert(title, message) {
            let overlay = document.getElementById('posAlertOverlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'posAlertOverlay';
                overlay.className = 'overlay modal-overlay';
                overlay.setAttribute('data-modal', '');
                overlay.innerHTML = `
                <div class="modal modal-content" role="dialog" aria-modal="true" aria-labelledby="posAlertTitle">
                    <div class="modal-head modal-header">
                        <h3 id="posAlertTitle"></h3>
                        <button type="button" class="close modal-close" id="posAlertClose" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p id="posAlertMessage" class="prm-prompt"></p>
                    </div>
                    <div class="modal-foot modal-footer">
                        <button type="button" class="btn-confirm" id="posAlertOk">Close</button>
                    </div>
                </div>`;
                document.body.appendChild(overlay);
            }
            const titleNode = document.getElementById('posAlertTitle');
            const messageNode = document.getElementById('posAlertMessage');
            const closeButton = document.getElementById('posAlertClose');
            const okButton = document.getElementById('posAlertOk');
            const close = () => overlay.classList.remove('show');
            titleNode.textContent = title;
            messageNode.textContent = message;
            closeButton.onclick = close;
            okButton.onclick = close;
            overlay.onclick = event => {
                if (event.target === overlay) close();
            };
            overlay.classList.add('show');
            okButton.focus();
        }

        function posShowFriendlyError(rawMessage) {
            const issue = posFriendlyIssue(rawMessage);
            posAlert(issue.title, issue.message);
            posToast(issue.title, 'err');
            refreshOpenTabs(false);
        }

        if (posServerErrorMessage) {
            setTimeout(() => posShowFriendlyError(posServerErrorMessage), 250);
        }

        document.querySelectorAll('[data-pos-server-toast]').forEach(toast => {
            setTimeout(() => toast.remove(), POS_NOTIFICATION_DURATION_MS);
        });

        let activePosConfirm = null;

        function posConfirm(title, message, confirmLabel) {
            if (activePosConfirm) activePosConfirm(false);

            return new Promise(resolve => {
                let overlay = document.getElementById('posConfirmOverlay');
                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.className = 'overlay modal-overlay';
                    overlay.setAttribute('data-modal', '');
                    overlay.id = 'posConfirmOverlay';
                    overlay.innerHTML = `
                <div class="modal modal-content" role="dialog" aria-modal="true" aria-labelledby="posConfirmTitle">
                    <div class="modal-head modal-header">
                        <h3 id="posConfirmTitle"></h3>
                        <button type="button" class="close modal-close" id="posConfirmClose" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p id="posConfirmMessage" class="prm-prompt"></p>
                    </div>
                    <div class="modal-foot modal-footer">
                        <button type="button" class="btn-cancel" id="posConfirmCancel">Keep cart</button>
                        <button type="button" class="tc-btn tc-btn-cancel" id="posConfirmAccept"></button>
                    </div>
                </div>`;
                    document.body.appendChild(overlay);
                }

                const titleNode = document.getElementById('posConfirmTitle');
                const messageNode = document.getElementById('posConfirmMessage');
                const closeButton = document.getElementById('posConfirmClose');
                const cancelButton = document.getElementById('posConfirmCancel');
                const acceptButton = document.getElementById('posConfirmAccept');

                titleNode.textContent = title;
                messageNode.textContent = message;
                acceptButton.textContent = confirmLabel || 'Confirm';

                const finish = confirmed => {
                    overlay.classList.remove('show');
                    closeButton.removeEventListener('click', closeHandler);
                    cancelButton.removeEventListener('click', closeHandler);
                    acceptButton.removeEventListener('click', acceptHandler);
                    overlay.removeEventListener('click', backdropHandler);
                    document.removeEventListener('keydown', keyHandler);
                    activePosConfirm = null;
                    resolve(confirmed);
                };
                const closeHandler = () => finish(false);
                const acceptHandler = () => finish(true);
                const backdropHandler = event => {
                    if (event.target === overlay) finish(false);
                };
                const keyHandler = event => {
                    if (!overlay.classList.contains('show')) return;
                    if (event.key === 'Escape') finish(false);
                    if (event.key === 'Enter') finish(true);
                };

                activePosConfirm = finish;
                closeButton.addEventListener('click', closeHandler);
                cancelButton.addEventListener('click', closeHandler);
                acceptButton.addEventListener('click', acceptHandler);
                overlay.addEventListener('click', backdropHandler);
                document.addEventListener('keydown', keyHandler);
                overlay.classList.add('show');
                cancelButton.focus();
            });
        }

        function showCardPosUnavailable() {
            const message = 'Card POS terminal is not enabled yet. Use Card (manual) for now.';
            if (typeof Modal !== 'undefined' && typeof Modal.showMessage === 'function') {
                Modal.showMessage({
                    title: 'Card POS unavailable',
                    message: '<p>' + message + '</p>',
                    size: 'sm'
                });
                return;
            }
            posToast(message, 'err');
        }

        // Cancel-before-prep: available to any cashier while ALL items are still pending.
        async function cancelOpenOrder(orderId, ref) {
            const reason = await posAskReason({
                title: 'Cancel order',
                prompt: `Cancel <strong>${ref}</strong>? This only works while all items are still pending (not yet cooking). No stock has been deducted yet.`,
                warn: 'Once the kitchen or bar starts any item, Cancel is blocked. Use Void instead.',
                confirmLabel: 'Cancel order',
                confirmColor: '#7c2d12',
                hasNotes: false,
            });
            if (!reason) return;
            const fd = new FormData();
            fd.append('csrf_token', '<?php echo $csrf_token; ?>');
            fd.append('order_id', orderId);
            fd.append('cancel_reason', reason);
            try {
                const resp = await fetch(posApiUrl('cancel-order.php'), {
                    method: 'POST',
                    body: fd,
                    credentials: 'include'
                });
                const j = await resp.json();
                if (!j.ok) {
                    posToast(j.error || 'Cancel failed', 'err');
                    return;
                }
                posToast(j.message || 'Order cancelled.', 'ok');
                _selectedOpenTabIds.delete(parseInt(orderId, 10) || 0);
                await refreshOpenTabs(true);
                refreshShiftStats(true);
            } catch (e) {
                posToast('Network error: ' + e.message, 'err');
            }
        }

        // Admin/manager-only: void an open or paid order from the tabs tray.
        async function adminVoidTab(orderId, ref) {
            const reason = await posAskReason({
                title: 'Void order',
                prompt: `Void <strong>${ref}</strong>? Stock will be restored for any items already marked Ready. The order will be removed from all station boards and any payment cancelled.`,
                warn: 'Void is permanent and admin/manager only. This action is fully audit-logged.',
                confirmLabel: 'Void order',
                confirmColor: '#c82333',
                hasNotes: true,
            });
            if (!reason) return;
            const notes = document.getElementById('prmNotes')?.value?.trim() || '';
            const fd = new FormData();
            fd.append('csrf_token', '<?php echo $csrf_token; ?>');
            fd.append('order_id', orderId);
            fd.append('void_reason', reason);
            fd.append('void_notes', notes);
            try {
                const resp = await fetch(posApiUrl('void-order.php'), {
                    method: 'POST',
                    body: fd,
                    credentials: 'include'
                });
                const j = await resp.json();
                if (!j.ok) {
                    posToast(j.error || 'Void failed', 'err');
                    return;
                }
                posToast(j.message || 'Order voided.', 'ok');
                _selectedOpenTabIds.delete(parseInt(orderId, 10) || 0);
                await refreshOpenTabs(true);
                refreshShiftStats(true);
            } catch (e) {
                posToast('Network error: ' + e.message, 'err');
            }
        }

        /* ─── POS Reason Modal ─────────── */
        let _prmResolve = null;

        function posAskReason({
            title = 'Reason required',
            prompt = '',
            warn = '',
            confirmLabel = 'Confirm',
            confirmColor = '#c82333',
            hasNotes = false
        } = {}) {
            return new Promise(resolve => {
                _prmResolve = resolve;
                document.getElementById('prmTitle').innerHTML = '<i class="fas fa-comment-alt"></i> ' + title;
                document.getElementById('prmPrompt').innerHTML = prompt;
                const warnEl = document.getElementById('prmWarn');
                if (warn) {
                    warnEl.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ' + warn;
                    warnEl.style.display = '';
                } else {
                    warnEl.style.display = 'none';
                }
                const notesWrap = document.getElementById('prmNotesWrap');
                notesWrap.style.display = hasNotes ? '' : 'none';
                if (hasNotes && document.getElementById('prmNotes')) document.getElementById('prmNotes').value = '';
                const confirmBtn = document.getElementById('prmConfirm');
                confirmBtn.style.background = confirmColor;
                document.getElementById('prmConfirmLabel').textContent = confirmLabel;
                const ta = document.getElementById('prmReason');
                ta.value = '';
                document.getElementById('prmHint').textContent = '0 / 8 characters minimum';
                document.getElementById('prmError').textContent = '';
                ta.oninput = () => {
                    const l = ta.value.trim().length;
                    document.getElementById('prmHint').textContent = l + ' / 8 characters minimum';
                    document.getElementById('prmHint').style.color = l >= 8 ? '#155724' : '#9ca3af';
                };
                document.getElementById('posReasonOverlay').classList.add('show');
                setTimeout(() => ta.focus(), 80);
            });
        }

        function posReasonConfirm() {
            const reason = document.getElementById('prmReason').value.trim();
            const errEl = document.getElementById('prmError');
            if (reason.length < 8) {
                errEl.textContent = 'Please enter at least 8 characters.';
                return;
            }
            errEl.textContent = '';
            document.getElementById('posReasonOverlay').classList.remove('show');
            if (_prmResolve) {
                _prmResolve(reason);
                _prmResolve = null;
            }
        }

        function posReasonCancel() {
            document.getElementById('posReasonOverlay').classList.remove('show');
            if (_prmResolve) {
                _prmResolve(null);
                _prmResolve = null;
            }
        }
        // Allow Enter to submit the modal (Shift+Enter for newlines)
        document.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey && document.getElementById('posReasonOverlay')?.classList.contains('show')) {
                e.preventDefault();
                posReasonConfirm();
            }
            if (e.key === 'Escape' && document.getElementById('posReasonOverlay')?.classList.contains('show')) {
                posReasonCancel();
            }
        });

        /* ─── Tab Detail Modal ────────────────────────────────────────────────── */
        const KDS_LABEL = {
            pending: 'Pending',
            preparing: 'Preparing',
            ready: 'Ready',
            collection: 'Collecting',
            served: 'Served',
            void: 'Void'
        };
        const KDS_ICON = {
            pending: 'fas fa-clock',
            preparing: 'fas fa-fire-burner',
            ready: 'fas fa-bell',
            collection: 'fas fa-hand-holding',
            served: 'fas fa-check-circle',
            void: 'fas fa-ban'
        };
        const AUDIT_LABEL = {
            parked_open_tab: 'Tab opened',
            placed_paid: 'Paid immediately',
            paid_from_tab: 'Tab settled',
            voided: 'Order voided',
            cancelled: 'Order cancelled',
            item_cancelled: 'Item cancelled',
        };
        const KDS_EVENT_LABEL = {
            fired: 'Ticket fired to station',
            started: 'Started preparing',
            ready: 'Marked ready — stock deducted',
            collected: 'Collected by runner',
            served: 'Served',
            recalled: 'Recalled',
            bumped: 'Ticket bumped',
            voided: 'Voided on station',
        };

        async function openTabDetail(orderId) {
            // Close the My Orders panel — it sits at z-index 99990 (above the modal) and intercepts clicks
            toggleMyOrders(false);

            const overlay = document.getElementById('tabDetailOverlay');
            const body = document.getElementById('tdiBody');
            const title = document.getElementById('tdiTitle');
            // Raise above floating widgets (z 99990) so its backdrop and content receive pointer events
            overlay.style.zIndex = '100001';
            overlay.classList.add('show');
            body.innerHTML = '<div style="text-align:center;padding:40px 0;color:#9ca3af;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
            try {
                const resp = await fetch(posApiUrl('pos-tab-detail.php?order_id=' + encodeURIComponent(String(orderId))), {
                    credentials: 'include',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const contentType = String(resp.headers.get('content-type') || '').toLowerCase();
                let data = null;

                if (contentType.includes('application/json')) {
                    data = await resp.json().catch(() => null);
                } else {
                    const raw = await resp.text();
                    const trimmed = String(raw || '').trim();
                    if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
                        try {
                            data = JSON.parse(trimmed);
                        } catch (e) {
                            data = null;
                        }
                    }
                }

                if (!data) {
                    body.innerHTML = '<p style="color:#c82333;padding:20px;">Order details are temporarily unavailable. Please refresh POS and try again.</p>';
                    return;
                }

                if (!resp.ok || !data.success) {
                    body.innerHTML = '<p style="color:#c82333;padding:20px;">' + escH(data.error || ('Failed to load (HTTP ' + resp.status + ')')) + '</p>';
                    return;
                }
                title.innerHTML = '<i class="fas fa-receipt"></i> ' + escH(data.order.reference);
                body.innerHTML = renderTabDetail(data);
            } catch (e) {
                body.innerHTML = '<p style="color:#c82333;padding:20px;">Network error: ' + escH(e.message) + '</p>';
            }
        }

        function escH(s) {
            const d = document.createElement('div');
            d.textContent = String(s || '');
            return d.innerHTML;
        }

        function fmtTs(ts) {
            if (!ts) return '—';
            const d = new Date(ts.replace(' ', 'T'));
            return d.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'short'
            }) + ' ' + d.toLocaleTimeString('en-GB', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }

        function fmtTime(ts) {
            if (!ts) return '—';
            const d = new Date(ts.replace(' ', 'T'));
            return d.toLocaleTimeString('en-GB', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }

        function renderTabDetail(data) {
            const o = data.order;
            const items = data.items || [];
            const kdsEvts = data.kds_events || [];
            const auditEvts = data.audit_events || [];

            /* Status counts */
            const counts = {
                pending: 0,
                preparing: 0,
                ready: 0,
                collection: 0,
                served: 0
            };
            items.forEach(i => {
                if (counts[i.kds_status] !== undefined) counts[i.kds_status]++;
            });
            const total = items.length;
            const grossTotal = parseFloat(o.total_amount || 0) || 0;
            const vatRate = posVatEnabled ? parseFloat(posVatRate || 0) : 0;
            const netTotal = vatRate > 0 ? Math.max(0, grossTotal / (1 + (vatRate / 100))) : grossTotal;
            const vatAmount = Math.max(0, grossTotal - netTotal);
            const amountDue = o.status === 'paid' || o.status === 'voided' || o.status === 'cancelled' ? 0 : grossTotal;
            const canSettle = o.status !== 'paid' && o.status !== 'voided' && o.status !== 'cancelled' && counts.pending === 0 && counts.preparing === 0 && counts.ready === 0 && counts.collection === 0 && total > 0;
            const canCancelBeforePrep = counts.pending > 0 && counts.preparing === 0 && counts.ready === 0 && counts.collection === 0 && counts.served === 0;
            const settlementBlocked = !canSettle && o.status !== 'paid' && o.status !== 'voided' && o.status !== 'cancelled';

            /* ── Summary ── */
            const statusBadge = o.status === 'paid' ? `<span class="tdi-status paid"><i class="fas fa-check-circle"></i> Paid</span>` :
                o.status === 'voided' ? `<span class="tdi-status voided"><i class="fas fa-ban"></i> Voided</span>` :
                `<span class="tdi-status placed"><i class="fas fa-clock"></i> Open tab</span>`;
            let metaRows = '';
            if (o.table_number) metaRows += `<span>Table <strong>${escH(o.table_number)}</strong></span>  &nbsp;·&nbsp; `;
            if (o.customer_name) metaRows += `<span>${escH(o.customer_name)}</span>  &nbsp;·&nbsp; `;
            metaRows += `Opened ${fmtTs(o.created_at)}`;
            if (o.opened_by_name) metaRows += `  &nbsp;·&nbsp; by <strong>${escH(o.opened_by_name)}</strong>`;
            if (o.order_type) metaRows += `<br>Type: <strong>${escH(o.order_type)}</strong>`;
            if (o.notes) metaRows += `<br>Notes: <em>${escH(o.notes)}</em>`;

            let html = `
    <div class="tdi-summary">
        <div>
            <div class="tdi-ref">${escH(o.reference)}</div>
            <div class="tdi-sub">${metaRows}</div>
            ${statusBadge}
        </div>
        <div class="tdi-total-block">
            <div class="tdi-total"><?php echo $currency_symbol; ?> ${fmtMoney(o.total_amount)}</div>
            <div style="font-size:12px;color:#6c757d;margin-top:4px;">${total} item${total !== 1 ? 's' : ''}</div>
        </div>
    </div>`;

            html += `
    <div class="tdi-accounting">
        <div class="tdi-accounting__metric">
            <span>Gross total</span>
            <strong><?php echo $currency_symbol; ?> ${fmtMoney(grossTotal)}</strong>
        </div>
        <div class="tdi-accounting__metric">
            <span>Net total</span>
            <strong><?php echo $currency_symbol; ?> ${fmtMoney(netTotal)}</strong>
        </div>
        <div class="tdi-accounting__metric">
            <span>VAT${vatRate > 0 ? ` · ${fmtMoney(vatRate)}%` : ''}</span>
            <strong><?php echo $currency_symbol; ?> ${fmtMoney(vatAmount)}</strong>
        </div>
        <div class="tdi-accounting__metric">
            <span>Balance due</span>
            <strong><?php echo $currency_symbol; ?> ${fmtMoney(amountDue)}</strong>
        </div>
    </div>`;

            if (settlementBlocked) {
                html += `<div class="tdi-settlement-note"><i class="fas fa-triangle-exclamation"></i> This tab cannot be settled yet. Wait until every item is served, then take payment and close the tab.</div>`;
            }

            const detailActions = [];
            if (canSettle) {
                detailActions.push(`<button type="button" class="tc-btn tc-btn-settle tdi-action" onclick="settleTabFromDetail(${parseInt(o.id, 10) || 0}, ${grossTotal}, ${JSON.stringify(String(o.reference || 'TAB'))})"><i class="fas fa-credit-card"></i> Settle tab</button>`);
            }
            detailActions.push(`<button type="button" class="tc-btn tc-btn-kot tdi-action" onclick="openPosPageModal('stock-receipt.php?id=${parseInt(o.id, 10) || 0}&print=1&kot=1','Print KOT','fas fa-print')"><i class="fas fa-print"></i> Print KOT</button>`);
            detailActions.push(`<button type="button" class="tc-btn tc-btn-log tdi-action" onclick="openPosPageModal('order-lifecycle.php?id=${parseInt(o.id, 10) || 0}','Timeline','fas fa-stream')"><i class="fas fa-stream"></i> Timeline</button>`);
            if (canCancelBeforePrep) {
                detailActions.push(`<button type="button" class="tc-btn tc-btn-cancel tdi-action" onclick="cancelOpenOrder(${parseInt(o.id, 10) || 0}, ${JSON.stringify(String(o.reference || 'TAB'))})"><i class="fas fa-circle-xmark"></i> Cancel</button>`);
            }
            if (posCanManageTabs) {
                detailActions.push(`<button type="button" class="tc-btn tc-btn-void tdi-action" onclick="adminVoidTab(${parseInt(o.id, 10) || 0}, ${JSON.stringify(String(o.reference || 'TAB'))})"><i class="fas fa-ban"></i> Void</button>`);
            }

            html += `<div class="tdi-actions">${detailActions.join('')}</div>`;
            html += '<div class="tdi-columns"><div class="tdi-column tdi-column--main">';

            /* ── Kitchen Flow Stepper ── */
            /* ── Order Lifecycle Track ── */
            const lcMilestones = [{
                    key: 'placed',
                    icon: 'fas fa-clipboard-check',
                    label: 'Placed'
                },
                {
                    key: 'fired',
                    icon: 'fas fa-fire-burner',
                    label: 'Fired'
                },
                {
                    key: 'ready',
                    icon: 'fas fa-bell',
                    label: 'Ready'
                },
                {
                    key: 'served',
                    icon: 'fas fa-concierge-bell',
                    label: 'Served'
                },
                {
                    key: 'paid',
                    icon: 'fas fa-circle-check',
                    label: 'Paid'
                },
            ];
            const lcFiredEvt = kdsEvts.find(e => e.event === 'fired');
            const readyTimes = items.map(i => i.ready_at).filter(Boolean);
            const servedTimes = items.map(i => i.served_at).filter(Boolean);
            const paidAudit = auditEvts.find(e => e.event === 'paid_from_tab' || e.event === 'payment_recorded');
            const lcTs = {
                placed: o.created_at || null,
                fired: lcFiredEvt ? lcFiredEvt.created_at : null,
                ready: readyTimes.length ? readyTimes.reduce((a, b) => (a > b ? a : b)) : null,
                served: servedTimes.length ? servedTimes.reduce((a, b) => (a > b ? a : b)) : null,
                paid: paidAudit ? paidAudit.created_at : (o.status === 'paid' ? o.updated_at : null),
            };
            const lcVoided = o.status === 'voided' || o.status === 'cancelled';
            const lcKeys = ['placed', 'fired', 'ready', 'served', 'paid'];
            let lcCurrent = 'placed';
            lcKeys.forEach(k => {
                if (lcTs[k]) lcCurrent = k;
            });

            let lcHtml = '<div class="tdi-section-hd">Order lifecycle</div><div class="lc-track">';
            lcMilestones.forEach((s, idx) => {
                const ts = lcTs[s.key];
                const isDone = !!ts;
                const cls = lcVoided ? 'lc-step lc-voided' :
                    isDone ? 'lc-step lc-done' :
                    s.key === lcCurrent ? 'lc-step lc-active' :
                    'lc-step';
                lcHtml += `<div class="${cls}">
            <div class="lc-dot"><i class="${s.icon}"></i></div>
            <div class="lc-label">${s.label}</div>
            <div class="lc-time">${ts ? fmtTime(ts) : '—'}</div>
        </div>`;
                if (idx < lcMilestones.length - 1) {
                    lcHtml += `<div class="lc-connector${isDone ? ' lc-done' : ''}"></div>`;
                }
            });
            lcHtml += '</div>';
            html += lcHtml;

            /* ── Item Status Stepper ── */
            const steps = [{
                    key: 'pending',
                    icon: 'fas fa-clock',
                    label: 'Pending'
                },
                {
                    key: 'preparing',
                    icon: 'fas fa-fire-burner',
                    label: 'Preparing'
                },
                {
                    key: 'ready',
                    icon: 'fas fa-bell',
                    label: 'Ready'
                },
                {
                    key: 'collection',
                    icon: 'fas fa-hand-holding',
                    label: 'Collecting'
                },
                {
                    key: 'served',
                    icon: 'fas fa-check-circle',
                    label: 'Served'
                },
            ];
            let stepHtml = '<div class="tdi-section-hd" style="margin-top:18px;">Item status</div><div class="kds-stepper">';
            steps.forEach(s => {
                const cnt = counts[s.key] || 0;
                const allDone = s.key === 'served' && cnt === total && total > 0;
                const hasAny = cnt > 0;
                const cls = allDone ? 'kds-step all-done' : (hasAny ? 'kds-step has-items' : 'kds-step');
                stepHtml += `<div class="${cls}">
            <div class="step-dot"><i class="${s.icon}"></i></div>
            <span class="step-lbl">${s.label}</span>
            <span class="step-cnt">${cnt > 0 ? cnt : '—'}</span>
        </div>`;
            });
            stepHtml += '</div>';
            html += stepHtml;

            /* ── Items Table ── */
            html += '<div class="tdi-section-hd">Line items</div>';
            if (items.length === 0) {
                html += '<p style="color:#9ca3af;font-size:13px;">No items found.</p>';
            } else {
                html += `<table class="tdi-tbl">
            <thead><tr>
                <th>Item</th><th>Qty</th><th>Total</th><th>Status</th><th>Station</th><th>Started</th><th>Ready</th><th>Served</th>
            </tr></thead><tbody>`;
                items.forEach(i => {
                    const badge = `<span class="kbadge ${i.kds_status}"><i class="${KDS_ICON[i.kds_status] || 'fas fa-circle'}"></i> ${KDS_LABEL[i.kds_status] || i.kds_status}</span>`;
                    const deduct = i.stock_deducted == 1 ? ' <span style="font-size:10px;color:#166534;font-weight:700;" title="Stock has been deducted">✓ stk</span>' : '';
                    const notes = i.notes ? `<br><span style="font-size:11px;color:#9ca3af;font-style:italic;">${escH(i.notes)}</span>` : '';
                    html += `<tr>
                <td><strong>${escH(i.item_name)}</strong>${notes}${deduct}</td>
                <td>${parseFloat(i.quantity)}</td>
                <td style="white-space:nowrap;"><?php echo $currency_symbol; ?> ${fmtMoney(i.line_total)}</td>
                <td>${badge}</td>
                <td style="font-size:12px;color:#6c757d;">${escH(i.station || '—')}</td>
                <td class="ts">${fmtTime(i.started_at)}</td>
                <td class="ts">${fmtTime(i.ready_at)}</td>
                <td class="ts">${fmtTime(i.served_at)}</td>
            </tr>`;
                });
                html += '</tbody></table>';
            }

            html += '</div><div class="tdi-column tdi-column--side">';

            /* ── Combined Audit + KDS Timeline ── */
            const allEvents = [];
            auditEvts.forEach(e => allEvents.push({
                ts: e.created_at,
                type: 'audit',
                event: e.event,
                actor: e.actor_name,
                detail: e.details
            }));
            kdsEvts.forEach(e => allEvents.push({
                ts: e.created_at,
                type: 'kds',
                event: e.event,
                actor: e.user_name,
                detail: e.item_name ? `Item: ${e.item_name}${e.from_status ? ' · ' + e.from_status + ' → ' + (e.to_status || '?') : ''}` : ''
            }));
            allEvents.sort((a, b) => a.ts < b.ts ? -1 : a.ts > b.ts ? 1 : 0);

            html += '<div class="tdi-section-hd" style="margin-top:20px;">Audit &amp; event log</div>';
            if (allEvents.length === 0) {
                html += '<p style="color:#9ca3af;font-size:13px;">No events recorded yet.</p>';
            } else {
                html += '<div class="tdi-timeline">';
                allEvents.forEach(ev => {
                    const label = ev.type === 'kds' ? (KDS_EVENT_LABEL[ev.event] || ev.event) : (AUDIT_LABEL[ev.event] || ev.event.replace(/_/g, ' '));
                    let extra = '';
                    if (ev.type === 'audit' && ev.detail) {
                        try {
                            const d = JSON.parse(ev.detail);
                            extra = Object.entries(d).filter(([k]) => !['till'].includes(k)).map(([k, v]) => `${k}: ${v}`).join(' · ');
                        } catch {
                            extra = ev.detail;
                        }
                    } else if (ev.detail) {
                        extra = ev.detail;
                    }
                    html += `<div class="tl-item tl-${ev.type}">
                <span class="tl-time">${fmtTs(ev.ts)}${ev.actor ? ' · ' + escH(ev.actor) : ''}</span>
                <div class="tl-title">${escH(label)}</div>
                ${extra ? `<div class="tl-extra">${escH(extra)}</div>` : ''}
            </div>`;
                });
                html += '</div>';
            }

            html += '</div></div>';

            return html;
        }

        function openCloseShift() {
            document.getElementById('closeShiftOverlay').classList.add('show');
            refreshShiftStats(true).finally(() => {
                updShiftVariance();
            });
        }

        function closeShiftModal() {
            document.getElementById('closeShiftOverlay').classList.remove('show');
        }

        /* Live variance preview for shift close. Blocks/warns when out-of-tolerance. */
        function updShiftVariance() {
            const expCash = parseFloat(document.getElementById('expCash')?.dataset.amount || '0');
            const expMobile = parseFloat(document.getElementById('expMobile')?.dataset.amount || '0');
            const expCard = parseFloat(document.getElementById('expCard')?.dataset.amount || '0');
            const decCash = parseFloat(document.getElementById('declCash')?.value || '0') || 0;
            const decMobile = parseFloat(document.getElementById('declMobile')?.value || '0') || 0;
            const decCard = parseFloat(document.getElementById('declCard')?.value || '0') || 0;
            const vC = +(decCash - expCash).toFixed(2);
            const vM = +(decMobile - expMobile).toFixed(2);
            const vK = +(decCard - expCard).toFixed(2);
            const max = Math.max(Math.abs(vC), Math.abs(vM), Math.abs(vK));
            const box = document.getElementById('shiftVarianceBox');
            const ovBox = document.getElementById('shiftOverrideBox');
            const btn = document.getElementById('closeShiftBtn');
            if (!box || !btn) return;
            const sym = (typeof currencySymbol !== 'undefined') ? currencySymbol : '';
            const fmt = v => (v >= 0 ? '+' : '-') + sym + ' ' + fmtMoney(Math.abs(v));
            box.style.display = 'block';
            box.innerHTML = `<strong>Variance preview:</strong> Cash ${fmt(vC)} · Mobile ${fmt(vM)} · Card ${fmt(vK)}`;
            const tolerance = 1.00;
            if (max <= tolerance) {
                box.style.background = '#d4edda';
                box.style.color = '#155724';
                box.style.border = '1px solid #28a745';
                if (ovBox) ovBox.style.display = 'none';
                btn.disabled = false;
                btn.textContent = 'Close shift';
            } else {
                box.style.background = '#f8d7da';
                box.style.color = '#721c24';
                box.style.border = '1px solid #c82333';
                box.innerHTML += `<br><small>Out of tolerance (max variance ${sym} ${fmtMoney(max)} &gt; ${sym} ${fmtMoney(tolerance)}).</small>`;
                if (ovBox) {
                    // Privileged user — show override box, allow submit only if checked
                    ovBox.style.display = 'block';
                    const ov = document.getElementById('adminOverride');
                    btn.disabled = !(ov && ov.checked);
                    btn.textContent = (ov && ov.checked) ? 'Close shift (override)' : 'Override required';
                } else {
                    btn.disabled = true;
                    btn.textContent = 'Recount required';
                }
            }
        }
        document.addEventListener('change', e => {
            if (e.target && e.target.id === 'adminOverride') updShiftVariance();
        });

        function closeSuccess() {
            const o = document.getElementById('successOverlay');
            if (o) o.remove();
            // Also strip ?-style query params if present so a refresh doesn't re-show the dialog
            if (window.history.replaceState && window.location.search) {
                const url = window.location.pathname;
                try {
                    window.history.replaceState({}, '', url);
                } catch (e) {}
            }
        }

        function posModalBridgeSyncBodyState() {
            const hasOpenOverlay = !!document.querySelector('.overlay.show, .modal-overlay.active');
            document.body.classList.toggle('modal-open', hasOpenOverlay);
        }

        function posModalBridgeApply(overlay) {
            if (!overlay || !overlay.classList || !overlay.classList.contains('overlay')) {
                return;
            }

            overlay.classList.add('modal-overlay');
            overlay.setAttribute('data-modal', '');

            Array.from(overlay.children).forEach(child => {
                if (!child.classList) {
                    return;
                }
                if (child.classList.contains('modal') || child.classList.contains('success-modal')) {
                    child.classList.add('modal-content');
                }
            });

            overlay.querySelectorAll('.modal-head').forEach(header => header.classList.add('modal-header'));
            overlay.querySelectorAll('.modal-foot').forEach(footer => footer.classList.add('modal-footer'));
            overlay.querySelectorAll('.close').forEach(closeBtn => closeBtn.classList.add('modal-close'));

            overlay.classList.toggle('active', overlay.classList.contains('show'));

            if (overlay.dataset.modalBridgeBound === '1') {
                return;
            }

            const classObserver = new MutationObserver(() => {
                overlay.classList.toggle('active', overlay.classList.contains('show'));
                posModalBridgeSyncBodyState();
            });
            classObserver.observe(overlay, {
                attributes: true,
                attributeFilter: ['class']
            });

            overlay.dataset.modalBridgeBound = '1';
        }

        function initPosSharedModalBridge() {
            document.querySelectorAll('.overlay').forEach(posModalBridgeApply);
            posModalBridgeSyncBodyState();

            const domObserver = new MutationObserver(mutations => {
                mutations.forEach(mutation => {
                    mutation.addedNodes.forEach(node => {
                        if (!node || node.nodeType !== 1) {
                            return;
                        }

                        if (node.classList && node.classList.contains('overlay')) {
                            posModalBridgeApply(node);
                        }

                        if (typeof node.querySelectorAll === 'function') {
                            node.querySelectorAll('.overlay').forEach(posModalBridgeApply);
                        }
                    });
                });
                posModalBridgeSyncBodyState();
            });

            domObserver.observe(document.body, {
                childList: true,
                subtree: true
            });
        }

        initPosSharedModalBridge();

        function closeTabDetailOverlay() {
            const ov = document.getElementById('tabDetailOverlay');
            if (!ov) return;
            ov.classList.remove('show');
            ov.style.zIndex = ''; // reset elevated z-index
        }

        // ----- Global modal-close behaviour -----
        // 1. ESC closes the topmost open .overlay.show
        // Global keyboard shortcuts
        document.addEventListener('keydown', e => {
            const tag = (e.target.tagName || '').toLowerCase();
            const isTyping = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
            if (isTyping) return;
            // '/' — focus menu search (no modal open) or tabs search (tabs tray open)
            if (e.key === '/') {
                const tabsOpen = document.getElementById('tabsOverlay')?.classList.contains('show');
                if (tabsOpen) {
                    e.preventDefault();
                    document.getElementById('tabsSearchInput')?.focus();
                    return;
                }
                const anyModal = document.querySelector('.overlay.show');
                if (!anyModal) {
                    e.preventDefault();
                    const s = document.getElementById('search');
                    if (s) { s.focus(); s.select(); }
                }
            }
        });

        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            const open = Array.from(document.querySelectorAll('.overlay.show'));
            if (!open.length) return;
            const top = open[open.length - 1];
            if (top.id === 'successOverlay') {
                closeSuccess();
                return;
            }
            if (top.id === 'tabsOverlay') {
                closeTabsTray();
                return;
            }
            if (top.id === 'tabDetailOverlay') {
                closeTabDetailOverlay();
                return;
            }
            if (top.id === 'payTabOverlay') {
                closePayTabOverlay();
                return;
            }
            if (top.id === 'receiptModal') {
                closeReceiptModal();
                return;
            }
            if (top.id === 'posPageModal') {
                closePosPageModal();
                return;
            }
            top.classList.remove('show');
        });
        // 2. Click on the dimmed backdrop (not on the modal body) closes the overlay
        document.querySelectorAll('.overlay').forEach(ov => {
            ov.addEventListener('mousedown', e => {
                if (e.target !== ov) return; // ignore clicks inside the modal
                if (ov.id === 'successOverlay') {
                    closeSuccess();
                    return;
                }
                if (ov.id === 'tabsOverlay') {
                    closeTabsTray();
                    return;
                }
                if (ov.id === 'tabDetailOverlay') {
                    closeTabDetailOverlay();
                    return;
                }
                if (ov.id === 'payTabOverlay') {
                    closePayTabOverlay();
                    return;
                }
                if (ov.id === 'receiptModal') {
                    closeReceiptModal();
                    return;
                }
                if (ov.id === 'posPageModal') {
                    closePosPageModal();
                    return;
                }
                ov.classList.remove('show');
            });
        });

        function closePayTabOverlay() {
            const ov = document.getElementById('payTabOverlay');
            if (!ov) return;
            ov.classList.remove('show');
            ov.style.zIndex = '';
        }

        // Split + tip state for the current settle session
        const _pt = {
            orderId: 0, total: 0, ref: '',
            ways: 1,        // how many ways to split (1 = off)
            current: 1,     // which split leg we're currently collecting (1-based)
            paid: [],       // { person, method, methodLabel, amount, tip, change } per completed leg
            discount: 0,    // discount applied before splitting (first leg only)
        };

        function ptUpdateDisplay() {
            const baseTotal = Math.max(0, _pt.total - (_pt.discount || 0));
            const share = _pt.ways > 1 ? baseTotal / _pt.ways : baseTotal;
            const tip = Math.max(0, parseFloat(document.getElementById('payTabTipInput').value) || 0);
            const due = share + tip;

            document.getElementById('payTabTipHidden').value = tip.toFixed(2);
            document.getElementById('payTabAmountDueDisplay').textContent = currencySymbol + ' ' + fmtMoney(due);

            // Store due in a data attr for updTabChange()
            document.getElementById('payTabAmountDueDisplay').dataset.due = due;

            // Share display
            const shareRow = document.getElementById('payTabShareRow');
            if (_pt.ways > 1) {
                shareRow.style.display = '';
                document.getElementById('payTabShareAmt').textContent = currencySymbol + ' ' + fmtMoney(share) + ' × ' + _pt.ways;
            } else {
                shareRow.style.display = 'none';
            }

            // Step indicator
            const stepEl = document.getElementById('payTabSplitStep');
            if (_pt.ways > 1) {
                stepEl.textContent = 'Person ' + _pt.current + ' of ' + _pt.ways + ' — paying ' + currencySymbol + ' ' + fmtMoney(due);
                stepEl.style.display = '';
            } else {
                stepEl.style.display = 'none';
            }

            // Submit btn label
            const btn = document.getElementById('payTabSubmitBtn');
            if (btn) btn.textContent = _pt.ways > 1 ? 'Pay split ' + _pt.current + ' of ' + _pt.ways : 'Take payment';

            // Tendered label
            const tl = document.getElementById('payTabTenderedLabel');
            if (tl) tl.textContent = 'Tendered — due: ' + currencySymbol + ' ' + fmtMoney(due);

            // Refresh change calculation
            updTabChange();
            // Rebuild the running ledger
            ptBuildLedger();
        }

        function ptBuildLedger() {
            const ledger = document.getElementById('payTabSplitLedger');
            if (!ledger) return;
            if (_pt.ways <= 1) { ledger.style.display = 'none'; return; }

            const sym = currencySymbol;
            const baseTotal = Math.max(0, _pt.total - (_pt.discount || 0));
            const share = Math.round(baseTotal / _pt.ways * 100) / 100;
            const mLabel = { cash: 'Cash', mobile_money: 'Mobile', card_manual: 'Card' };

            // Lock / unlock split-way buttons
            document.querySelectorAll('#payTabSplitWays .split-way-btn').forEach(b => {
                const lock = _pt.paid.length > 0;
                b.disabled = lock;
                b.style.opacity = lock ? '0.45' : '';
                b.title = lock ? 'Cannot change while split is in progress' : '';
            });

            // Progress dots
            let html = '<div style="display:flex;align-items:center;gap:4px;margin-bottom:8px;">';
            for (let i = 0; i < _pt.ways; i++) {
                if (i < _pt.paid.length)        html += '<span style="color:#22c55e;font-size:13px;">●</span>';
                else if (i === _pt.paid.length) html += '<span style="color:#92400e;font-size:13px;">●</span>';
                else                             html += '<span style="color:#d1d5db;font-size:13px;">○</span>';
            }
            const remaining = _pt.ways - _pt.paid.length;
            html += `<span style="font-size:11px;color:#6c757d;margin-left:6px;">${_pt.paid.length} paid &middot; ${remaining} left</span></div>`;

            // Person rows
            html += '<div style="border-radius:6px;overflow:hidden;border:1px solid #e5e7eb;">';
            for (let i = 0; i < _pt.ways; i++) {
                const pNum = i + 1;
                const border = i < _pt.ways - 1 ? 'border-bottom:1px solid #e5e7eb;' : '';
                if (i < _pt.paid.length) {
                    const p = _pt.paid[i];
                    const ml = p.methodLabel || (mLabel[p.method] || p.method);
                    const tipNote = p.tip > 0 ? `<span style="color:#059669;font-size:10px;margin-left:4px;">+tip ${sym} ${fmtMoney(p.tip)}</span>` : '';
                    const chgNote = p.change > 0 ? `<span style="color:#1d4ed8;font-size:10px;margin-left:4px;">chg ${sym} ${fmtMoney(p.change)}</span>` : '';
                    html += `<div style="display:flex;align-items:center;gap:6px;padding:8px 10px;background:#f0fdf4;${border}">
                        <span style="color:#22c55e;flex-shrink:0;">✓</span>
                        <span style="font-size:12px;font-weight:700;color:#166534;min-width:64px;flex-shrink:0;">Person ${pNum}</span>
                        <span style="font-size:11px;color:#374151;">${escHtml(ml)}</span>
                        <span style="margin-left:auto;font-size:12px;font-weight:700;color:#166534;white-space:nowrap;">${sym} ${fmtMoney(p.amount)}</span>
                        ${tipNote}${chgNote}
                    </div>`;
                } else if (i === _pt.paid.length) {
                    html += `<div style="display:flex;align-items:center;gap:6px;padding:8px 10px;background:#fffbeb;${border}">
                        <span style="color:#92400e;flex-shrink:0;">→</span>
                        <span style="font-size:12px;font-weight:700;color:#92400e;min-width:64px;flex-shrink:0;">Person ${pNum}</span>
                        <span style="font-size:11px;color:#92400e;font-style:italic;">paying now</span>
                        <span style="margin-left:auto;font-size:12px;color:#92400e;white-space:nowrap;">${sym} ${fmtMoney(share)} + tip</span>
                    </div>`;
                } else {
                    html += `<div style="display:flex;align-items:center;gap:6px;padding:8px 10px;background:#fff;${border}">
                        <span style="color:#d1d5db;flex-shrink:0;">○</span>
                        <span style="font-size:12px;color:#9ca3af;min-width:64px;flex-shrink:0;">Person ${pNum}</span>
                        <span style="font-size:11px;color:#d1d5db;">pending</span>
                        <span style="margin-left:auto;font-size:12px;color:#d1d5db;white-space:nowrap;">${sym} ${fmtMoney(share)}</span>
                    </div>`;
                }
            }
            html += '</div>';

            ledger.innerHTML = html;
            ledger.style.display = '';
        }

        function ptSetSplitWays(n) {
            _pt.ways = n;
            _pt.current = 1;
            _pt.paid = [];
            document.getElementById('payTabSplitCount').value = n;
            document.getElementById('payTabSplitNumber').value = 1;
            document.querySelectorAll('#payTabSplitWays .split-way-btn').forEach(b => b.classList.toggle('active', parseInt(b.dataset.ways) === n));
            // Order total row: show when split active
            const otr = document.getElementById('payTabOrderTotalRow');
            if (otr) otr.style.display = n > 1 ? '' : 'none';
            ptUpdateDisplay();
        }

        function ptSetTipPct(pct) {
            const baseTotal = Math.max(0, _pt.total - (_pt.discount || 0));
            const share = _pt.ways > 1 ? baseTotal / _pt.ways : baseTotal;
            const input = document.getElementById('payTabTipInput');
            input.value = pct > 0 ? (share * pct / 100).toFixed(2) : '';
            document.querySelectorAll('#payTabTipPresets .tip-preset-btn').forEach(b => b.classList.toggle('active', parseInt(b.dataset.pct) === pct));
            ptUpdateDisplay();
        }

        function ptOnTipInput() {
            document.querySelectorAll('#payTabTipPresets .tip-preset-btn').forEach(b => b.classList.remove('active'));
            ptUpdateDisplay();
        }

        function ptResetForm() {
            document.getElementById('payTabMethod').value = '';
            document.getElementById('payTabTipInput').value = '';
            document.getElementById('payTabTipHidden').value = '0';
            document.querySelectorAll('#payTabTipPresets .tip-preset-btn').forEach(b => b.classList.toggle('active', b.dataset.pct === '0'));
            // Discount can only be changed on first leg — hide section on subsequent legs (and if no permission)
            const discSection = document.getElementById('payTabDiscountSection');
            if (discSection) discSection.style.display = (!posCanDiscount || _pt.current > 1 || _pt.paid.length > 0) ? 'none' : '';
            document.querySelectorAll('#payTabOverlay .pay-method-grid button').forEach(b => b.classList.remove('active'));
            ['cash', 'mobile_money', 'card_manual'].forEach(k => {
                const e = document.getElementById('ext-tab-' + k);
                if (e) e.style.display = 'none';
            });
            const tEl = document.getElementById('payTabTendered');
            if (tEl) tEl.value = '';
            const mrEl = document.querySelector('#ext-tab-mobile_money input[name="mobile_wallet_reference"]');
            if (mrEl) mrEl.value = '';
            const mpEl = document.querySelector('#ext-tab-mobile_money select');
            if (mpEl) mpEl.value = '';
            const l4El = document.querySelector('#ext-tab-card_manual input[name="card_last4"]');
            if (l4El) l4El.value = '';
            const authEl = document.querySelector('#ext-tab-card_manual input[name="card_auth_code"]');
            if (authEl) authEl.value = '';
        }

        function openPayForTab(orderId, total, ref, canSettle = true, existingSplitCount = 1, existingSplitPaid = 0) {
            if (!canSettle) {
                posToastReady('Wait until all items are served before settling the tab.', true);
                return;
            }
            closeTabsTray();

            // Initialise split state
            _pt.orderId = orderId;
            _pt.total = total;
            _pt.ref = ref;
            _pt.paid = [];
            _pt.discount = 0;
            // Reset discount UI
            const dAmt = document.getElementById('payTabDiscountAmt');
            const dHidden = document.getElementById('payTabDiscountAmtHidden');
            const dReason = document.getElementById('payTabDiscountReason');
            const dReasonH = document.getElementById('payTabDiscountReasonHidden');
            if (dAmt) dAmt.value = '';
            if (dHidden) dHidden.value = '0';
            if (dReason) dReason.value = '';
            if (dReasonH) dReasonH.value = '';
            document.querySelectorAll('#payTabDiscountPresets .discount-preset-btn').forEach(b => b.classList.toggle('active', b.dataset.pct === '0'));
            const discSection = document.getElementById('payTabDiscountSection');
            if (discSection) discSection.style.display = posCanDiscount ? '' : 'none';

            document.getElementById('payTabOrderId').value = orderId;
            document.getElementById('payTabForceServeKitchen').value = '0';
            document.getElementById('payTabMgrAuthToken').value = '';
            document.getElementById('payTabRef').textContent = ref;
            document.getElementById('payTabTotal').textContent = currencySymbol + ' ' + fmtMoney(total);
            document.getElementById('payTabTotal').dataset.total = total;
            const otr = document.getElementById('payTabOrderTotalRow');

            if (existingSplitCount > 1 && existingSplitPaid > 0) {
                // Tab is already mid-split (e.g. browser was refreshed). Pre-populate.
                _pt.ways = existingSplitCount;
                _pt.current = existingSplitPaid + 1;
                document.getElementById('payTabSplitCount').value = existingSplitCount;
                document.getElementById('payTabSplitNumber').value = existingSplitPaid + 1;
                document.querySelectorAll('#payTabSplitWays .split-way-btn').forEach(b => b.classList.toggle('active', parseInt(b.dataset.ways) === existingSplitCount));
                if (otr) otr.style.display = '';
                // Populate ledger with placeholder rows for already-paid legs
                const share = Math.round(total / existingSplitCount * 100) / 100;
                for (let i = 0; i < existingSplitPaid; i++) {
                    _pt.paid.push({ person: i + 1, method: '', methodLabel: 'Previously paid', amount: share, tip: 0, change: 0 });
                }
            } else {
                _pt.ways = 1;
                _pt.current = 1;
                document.getElementById('payTabSplitCount').value = 1;
                document.getElementById('payTabSplitNumber').value = 1;
                document.querySelectorAll('#payTabSplitWays .split-way-btn').forEach(b => b.classList.toggle('active', b.dataset.ways === '1'));
                if (otr) otr.style.display = 'none';
            }

            ptResetForm();
            ptUpdateDisplay();

            const ov = document.getElementById('payTabOverlay');
            ov.style.zIndex = '100001';
            ov.classList.add('show');
        }

        // AJAX payment submission + receipt modal
        let _receiptOrderId = 0;

        document.getElementById('payTabForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const method = document.getElementById('payTabMethod').value;
            if (!method) {
                posToastReady('Pick a payment method first.', true);
                return;
            }
            const btn = document.getElementById('payTabSubmitBtn');
            const origTxt = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';

            const fd = new FormData(this);
            try {
                const r = await fetch('pos.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                    credentials: 'same-origin',
                });
                const j = await r.json();
                if (!j.ok) {
                    // Stranded food (fired before this business window, or never bumped)
                    // blocks settlement. Offer the manager force-serve override right here
                    // instead of leaving the cashier with a dead end.
                    const foodBlocked = typeof j.error === 'string' && j.error.indexOf('not yet served') !== -1;
                    if (foodBlocked) {
                        btn.disabled = false;
                        btn.innerHTML = origTxt;
                        const form = this;
                        const retry = (mgrToken) => {
                            document.getElementById('payTabForceServeKitchen').value = '1';
                            document.getElementById('payTabMgrAuthToken').value = mgrToken || '';
                            form.requestSubmit();
                        };
                        if (posCanForceServe) {
                            retry(null);
                        } else {
                            openMgrAuthOverlay('pos_force_serve', retry);
                        }
                        return;
                    }
                    posToastReady(j.error || 'Payment failed.', true);
                    btn.disabled = false;
                    btn.innerHTML = origTxt;
                    return;
                }

                if (j.split_intermediate) {
                    // Push this leg into the running ledger
                    const mLabel = { cash: 'Cash', mobile_money: 'Mobile', card_manual: 'Card' };
                    _pt.paid.push({
                        person:      j.split_paid,
                        method:      j.payment_method,
                        methodLabel: mLabel[j.payment_method] || j.payment_method,
                        amount:      (parseFloat(j.split_amount) || 0) + (parseFloat(j.tip_amount) || 0),
                        tip:         parseFloat(j.tip_amount) || 0,
                        change:      parseFloat(j.change) || 0,
                    });

                    // Advance to next person
                    _pt.current = j.split_paid + 1;
                    document.getElementById('payTabSplitNumber').value = _pt.current;

                    // Clear payment inputs, keep split config, rebuild ledger
                    ptResetForm();
                    ptUpdateDisplay(); // sets correct btn label + calls ptBuildLedger()

                    btn.disabled = false;
                    // Do NOT restore origTxt — ptUpdateDisplay() already set the correct label
                    setTimeout(() => { refreshShiftStats(); }, 300);
                    return;
                }

                // Final payment (single or last split) — push last leg then show receipt
                if (_pt.ways > 1) {
                    const mLabelF = { cash: 'Cash', mobile_money: 'Mobile', card_manual: 'Card' };
                    const lastShare = Math.round(parseFloat(j.total || 0) / _pt.ways * 100) / 100;
                    _pt.paid.push({
                        person:      _pt.current,
                        method:      j.payment_method,
                        methodLabel: mLabelF[j.payment_method] || j.payment_method,
                        amount:      lastShare + (parseFloat(j.tip_amount) || 0),
                        tip:         parseFloat(j.tip_amount) || 0,
                        change:      parseFloat(j.change) || 0,
                    });
                    j._splitLegs = _pt.paid.slice(); // pass to receipt modal
                }
                // If we just settled the tab we were adding to, stop the add-to-tab mode
                if (_activeTab && parseInt(j.order_id, 10) === _activeTab.id) clearActiveTab(false);
                closePayTabOverlay();
                showReceiptModal(j);
                setTimeout(() => { refreshShiftStats(); refreshOpenTabs(false); }, 400);
            } catch (err) {
                posToastReady('Network error — please retry.', true);
                btn.disabled = false;
                btn.innerHTML = origTxt;
            }
        });

        function showReceiptModal(data) {
            _receiptOrderId = parseInt(data.order_id, 10) || 0;
            const sym = currencySymbol;
            const splitCount = parseInt(data.split_count, 10) || 1;
            const splitLegs = Array.isArray(data._splitLegs) ? data._splitLegs : [];
            const mLabel = { cash: 'Cash', mobile_money: 'Mobile Money', card_manual: 'Card (manual)' };

            // For split orders, sum all tips from all legs; for single, use data.tip_amount
            const totalTips = splitLegs.length > 1
                ? splitLegs.reduce((s, p) => s + (p.tip || 0), 0)
                : (parseFloat(data.tip_amount) || 0);
            const grandTotal = (parseFloat(data.total) || 0) + totalTips;

            // Method label: for split, show "Split ×N" instead of a single method
            const displayMethod = splitCount > 1 ? `Split ×${splitCount}` : (mLabel[data.payment_method] || data.payment_method);

            const titleSuffix = splitCount > 1 ? ' — split ×' + splitCount : '';
            document.getElementById('rmTitle').textContent = 'Payment received — ' + (data.reference || '') + titleSuffix;
            document.getElementById('rmSubtitle').textContent = displayMethod + ' · ' + sym + ' ' + fmtMoney(grandTotal);

            let summaryHtml = `
                <div><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Order total</span><div style="font-size:20px;font-weight:700;color:#166534;">${sym} ${fmtMoney(data.total)}</div></div>
                <div><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Method</span><div style="font-weight:600;color:#374151;">${escHtml(displayMethod)}</div></div>`;

            if (totalTips > 0) {
                summaryHtml += `<div><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Tips</span><div style="font-weight:600;color:#059669;">${sym} ${fmtMoney(totalTips)}</div></div>
                <div><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Grand total</span><div style="font-size:18px;font-weight:700;color:#166534;">${sym} ${fmtMoney(grandTotal)}</div></div>`;
            }

            // Split breakdown table — one row per leg
            if (splitCount > 1 && splitLegs.length > 0) {
                summaryHtml += `<div style="grid-column:1/-1;margin-top:4px;">
                    <span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Split breakdown</span>
                    <div style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;margin-top:4px;">`;
                splitLegs.forEach((p, idx) => {
                    const border = idx < splitLegs.length - 1 ? 'border-bottom:1px solid #f3f4f6;' : '';
                    const tipNote = p.tip > 0 ? ` <span style="color:#059669;font-size:10px;">+tip ${sym} ${fmtMoney(p.tip)}</span>` : '';
                    const chgNote = p.change > 0 ? ` <span style="color:#1d4ed8;font-size:10px;">chg ${sym} ${fmtMoney(p.change)}</span>` : '';
                    summaryHtml += `<div style="display:flex;align-items:center;padding:7px 10px;font-size:12px;${border}">
                        <span style="color:#22c55e;margin-right:6px;">✓</span>
                        <span style="color:#374151;font-weight:600;min-width:62px;">Person ${p.person}</span>
                        <span style="color:#6c757d;">${escHtml(p.methodLabel || p.method || '—')}</span>
                        <span style="margin-left:auto;font-weight:700;color:#166534;white-space:nowrap;">${sym} ${fmtMoney(p.amount)}</span>
                        ${tipNote}${chgNote}
                    </div>`;
                });
                summaryHtml += '</div></div>';
            } else if (splitCount > 1) {
                // Fallback: no legs data, show simple split summary
                summaryHtml += `<div style="grid-column:1/-1"><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Split</span><div style="font-weight:600;color:#374151;">${splitCount} ways · ${sym} ${fmtMoney(data.total / splitCount)} each</div></div>`;
            }

            // Cash change for single/last-leg cash payment
            if (splitCount <= 1 && data.payment_method === 'cash' && data.change > 0) {
                summaryHtml += `<div><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Tendered</span><div style="font-weight:600;">${sym} ${fmtMoney(data.tendered)}</div></div>
                <div><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Change</span><div style="font-size:16px;font-weight:700;color:#1d4ed8;">${sym} ${fmtMoney(data.change)}</div></div>`;
            }
            if (data.customer_name) {
                summaryHtml += `<div style="grid-column:1/-1"><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Guest</span><div style="font-weight:600;">${escHtml(data.customer_name)}</div></div>`;
            }
            document.getElementById('rmSummary').innerHTML = summaryHtml;

            // Pre-fill contact fields
            const emailEl = document.getElementById('rmEmail');
            const phoneEl = document.getElementById('rmPhone');
            emailEl.value = data.customer_email || '';
            phoneEl.value = data.customer_phone || '';
            document.getElementById('rmEmailStatus').textContent = '';
            document.getElementById('rmWhatsAppStatus').textContent = '';

            // Reset send buttons
            ['rmEmailBtn', 'rmWhatsAppBtn'].forEach(id => {
                const b = document.getElementById(id);
                if (b) { b.disabled = false; b.style.opacity = '1'; }
            });

            // Print link
            const pl = document.getElementById('rmPrintLink');
            if (pl) pl.href = 'stock-receipt.php?id=' + _receiptOrderId + '&print=1';

            document.getElementById('receiptModal').classList.add('show');

            // Auto-print: open receipt page which triggers window.print() on load.
            // Works with any printer set as default — thermal (ESC/POS), inkjet, PDF, network.
            // Toggle stored in localStorage so each terminal remembers its own preference.
            if (localStorage.getItem('pos_auto_print_receipt') === '1' && _receiptOrderId) {
                setTimeout(function () {
                    window.open('stock-receipt.php?id=' + _receiptOrderId + '&print=1', '_blank', 'noopener');
                }, 350);
            }
        }

        function closeReceiptModal() {
            document.getElementById('receiptModal').classList.remove('show');
        }

        function openPosPageModal(url, title, iconClass) {
            const modal = document.getElementById('posPageModal');
            const frame = document.getElementById('posPageModalFrame');
            const titleEl = document.getElementById('posPageModalTitleText');
            const iconEl = document.getElementById('posPageModalIcon');
            if (!modal || !frame) return;
            titleEl.textContent = title || 'Loading…';
            iconEl.className = iconClass || 'fas fa-file-alt';
            frame.src = url;
            // Close any lower-level overlays so this one is clearly on top
            closeTabDetailOverlay();
            modal.classList.add('show');
        }

        function closePosPageModal() {
            const modal = document.getElementById('posPageModal');
            const frame = document.getElementById('posPageModalFrame');
            if (!modal) return;
            modal.classList.remove('show');
            if (frame) frame.src = 'about:blank';
        }

        function settleTabFromDetail(orderId, total, ref) {
            // Close the detail overlay first, then open the pay modal in the next tick
            // so the DOM transition completes before opening the next overlay.
            closeTabDetailOverlay();
            requestAnimationFrame(() => {
                openPayForTab(orderId, total, ref, true);
            });
        }

        async function sendPosReceipt(channel) {
            if (_receiptOrderId <= 0) return;
            const isEmail = channel === 'email';
            const recipient = (isEmail
                ? document.getElementById('rmEmail').value
                : document.getElementById('rmPhone').value
            ).trim();
            if (!recipient) {
                posToastReady(isEmail ? 'Enter an email address.' : 'Enter a phone number.', true);
                return;
            }

            const btnId = isEmail ? 'rmEmailBtn' : 'rmWhatsAppBtn';
            const statusId = isEmail ? 'rmEmailStatus' : 'rmWhatsAppStatus';
            const btn = document.getElementById(btnId);
            const statusEl = document.getElementById(statusId);
            btn.disabled = true;
            btn.style.opacity = '0.6';
            statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
            statusEl.style.color = '#6c757d';

            const fd = new FormData();
            fd.append('csrf_token', posCsrfToken);
            fd.append('action', isEmail ? 'email_receipt' : 'whatsapp_receipt');
            fd.append('order_id', String(_receiptOrderId));
            fd.append('recipient', recipient);

            try {
                const r = await fetch('stock-receipt.php?id=' + _receiptOrderId, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                    credentials: 'same-origin',
                });
                const j = await r.json();
                if (j.ok) {
                    statusEl.innerHTML = '<i class="fas fa-check-circle" style="color:#16a34a;"></i> ' + escHtml(j.message || 'Sent');
                    statusEl.style.color = '#16a34a';
                } else {
                    statusEl.innerHTML = '<i class="fas fa-times-circle" style="color:#dc2626;"></i> ' + escHtml(j.error || 'Failed');
                    statusEl.style.color = '#dc2626';
                    btn.disabled = false;
                    btn.style.opacity = '1';
                }
            } catch (err) {
                statusEl.innerHTML = '<i class="fas fa-times-circle" style="color:#dc2626;"></i> Network error';
                statusEl.style.color = '#dc2626';
                btn.disabled = false;
                btn.style.opacity = '1';
            }
        }

        function setMethodTab(btn) {
            if (btn.classList.contains('disabled')) return;
            const m = btn.dataset.method;
            document.getElementById('payTabMethod').value = m;
            document.querySelectorAll('#payTabOverlay .pay-method-grid button').forEach(b => b.classList.toggle('active', b === btn));
            ['cash', 'mobile_money', 'card_manual'].forEach(k => {
                const e = document.getElementById('ext-tab-' + k);
                if (e) e.style.display = (k === m) ? 'block' : 'none';
            });
            if (m === 'cash') {
                const tEl = document.getElementById('payTabTendered');
                const dueEl = document.getElementById('payTabAmountDueDisplay');
                const due = dueEl ? (parseFloat(dueEl.dataset.due) || 0) : (parseFloat(document.getElementById('payTabTotal').dataset.total) || 0);
                if (tEl && due > 0 && (!tEl.value || parseFloat(tEl.value) < due)) {
                    tEl.value = due.toFixed(2);
                    updTabChange();
                }
                if (tEl) tEl.focus();
            }
        }

        function updTabChange() {
            const t = parseFloat(document.getElementById('payTabTendered').value) || 0;
            const dueEl = document.getElementById('payTabAmountDueDisplay');
            const due = dueEl ? (parseFloat(dueEl.dataset.due) || 0) : (parseFloat(document.getElementById('payTabTotal').dataset.total) || 0);
            const ch = Math.max(0, t - due);
            document.getElementById('payTabChange').textContent = currencySymbol + ' ' + fmtMoney(ch);
        }

        function setMethod(btn) {
            const m = btn.dataset.method;
            if (btn.classList.contains('disabled')) return;
            document.getElementById('payment_method').value = m;
            document.querySelectorAll('.pay-method-grid button').forEach(b => b.classList.toggle('active', b === btn));
            ['cash', 'mobile_money', 'card_manual'].forEach(k => {
                const el = document.getElementById('ext-' + k);
                if (el) el.style.display = (k === m) ? 'block' : 'none';
            });
            // Auto-fill tendered with discounted total when cashier picks cash
            if (m === 'cash') {
                const tEl = document.getElementById('tendered');
                const total = effectiveCartTotal();
                if (tEl && total > 0 && (!tEl.value || parseFloat(tEl.value) < total)) {
                    tEl.value = total.toFixed(2);
                    updChange();
                }
                if (tEl) tEl.focus();
            }
            updConfirm();
        }

        function updChange() {
            const t = parseFloat(document.getElementById('tendered').value) || 0;
            const ch = Math.max(0, t - effectiveCartTotal());
            document.getElementById('changeOut').textContent = currencySymbol + ' ' + fmtMoney(ch);
            updConfirm();
        }

        function quickTend(n) {
            const cur = parseFloat(document.getElementById('tendered').value) || 0;
            document.getElementById('tendered').value = (cur + n).toFixed(2);
            updChange();
        }

        function quickTendExact() {
            document.getElementById('tendered').value = effectiveCartTotal().toFixed(2);
            updChange();
        }

        function quickTendTab(n) {
            const el = document.getElementById('payTabTendered');
            el.value = ((parseFloat(el.value) || 0) + n).toFixed(2);
            updTabChange();
        }

        function quickTendTabExact() {
            const dueEl = document.getElementById('payTabAmountDueDisplay');
            const due = dueEl ? (parseFloat(dueEl.dataset.due) || 0) : (parseFloat(document.getElementById('payTabTotal').dataset.total) || 0);
            document.getElementById('payTabTendered').value = due.toFixed(2);
            updTabChange();
        }

        function updConfirm() {
            const m = document.getElementById('payment_method').value;
            let ok = !!m && cart.length > 0;
            if (m === 'cash') {
                const t = parseFloat(document.getElementById('tendered').value) || 0;
                ok = ok && (t + 0.001 >= effectiveCartTotal());
            }
            document.getElementById('confirmBtn').disabled = !ok;
        }

        function toggleRecent(forceState = null) {
            const recentList = document.getElementById('recentList');
            if (!recentList) return;
            const opening = typeof forceState === 'boolean' ? forceState : !recentList.classList.contains('show');
            recentList.classList.toggle('show', opening);
            recentList.classList.toggle('recent-list--mobile', opening && window.innerWidth <= 640);
        }

        document.getElementById('payForm').addEventListener('submit', e => {
            const m = document.getElementById('payment_method').value;
            if (!m) {
                e.preventDefault();
                posToastReady('Pick a payment method.', true);
                hidePosActionLoader();
                return;
            }
            showPosActionLoader('Taking payment...', 'Saving the order and sending station tickets.');
        });

        /* ── Discount state (new-order pay modal) ─────────────────────────── */
        let _payDiscount = 0;

        function effectiveCartTotal() {
            return Math.max(0, cartTotal() - _payDiscount - _dealSavings);
        }

        function setDiscountPct(btn, pct) {
            const raw = cartTotal();
            _payDiscount = pct > 0 ? Math.round(raw * pct / 100 * 100) / 100 : 0;
            const dEl = document.getElementById('payDiscountAmt');
            if (dEl) dEl.value = _payDiscount > 0 ? _payDiscount.toFixed(2) : '';
            document.querySelectorAll('#payDiscountPresets .discount-preset-btn').forEach(b => b.classList.toggle('active', b === btn));
            syncPayDiscountToForm();
            document.getElementById('payTotal').textContent = currencySymbol + ' ' + fmtMoney(effectiveCartTotal());
            updChange();
            updConfirm();
        }

        function onDiscountAmtInput() {
            _payDiscount = Math.max(0, parseFloat(document.getElementById('payDiscountAmt').value) || 0);
            document.querySelectorAll('#payDiscountPresets .discount-preset-btn').forEach(b => b.classList.remove('active'));
            const noneBtn = document.querySelector('#payDiscountPresets .discount-preset-btn[data-pct="0"]');
            if (_payDiscount === 0 && noneBtn) noneBtn.classList.add('active');
            syncPayDiscountToForm();
            document.getElementById('payTotal').textContent = currencySymbol + ' ' + fmtMoney(effectiveCartTotal());
            updChange();
            updConfirm();
        }

        function syncPayDiscountToForm() {
            // Manual discount (requires pos_discount permission on server)
            document.getElementById('payDiscountAmtHidden').value = Math.max(0, _payDiscount).toFixed(2);
            const reason = document.getElementById('payDiscountReason');
            document.getElementById('payDiscountReasonHidden').value = reason ? reason.value.trim() : '';

            // Auto deal discount (no permission required — validated server-side by deal ID)
            document.getElementById('payDealDiscountAmtHidden').value = _dealSavings.toFixed(2);
            document.getElementById('payDealIdsHidden').value = _dealLines.map(dl => dl.id).filter(Boolean).join(',');
        }

        /* ── Discount for settle-tab modal ──────────────────────────────── */
        function ptSetDiscountPct(btn, pct) {
            if (_pt.paid.length > 0) { posToastReady('Cannot change discount once payments have started.', true); return; }
            const base = _pt.total;
            _pt.discount = pct > 0 ? Math.round(base * pct / 100 * 100) / 100 : 0;
            const dEl = document.getElementById('payTabDiscountAmt');
            if (dEl) dEl.value = _pt.discount > 0 ? _pt.discount.toFixed(2) : '';
            document.querySelectorAll('#payTabDiscountPresets .discount-preset-btn').forEach(b => b.classList.toggle('active', b === btn));
            syncTabDiscountToForm();
            ptUpdateDisplay();
        }

        function ptOnDiscountAmtInput() {
            if (_pt.paid.length > 0) return;
            _pt.discount = Math.max(0, parseFloat(document.getElementById('payTabDiscountAmt').value) || 0);
            document.querySelectorAll('#payTabDiscountPresets .discount-preset-btn').forEach(b => b.classList.remove('active'));
            const noneBtn = document.querySelector('#payTabDiscountPresets .discount-preset-btn[data-pct="0"]');
            if (_pt.discount === 0 && noneBtn) noneBtn.classList.add('active');
            syncTabDiscountToForm();
            ptUpdateDisplay();
        }

        function syncTabDiscountToForm() {
            // Manual discount only — deal discount on tabs is passed separately
            document.getElementById('payTabDiscountAmtHidden').value = (_pt.discount || 0).toFixed(2);
            const reason = document.getElementById('payTabDiscountReason');
            document.getElementById('payTabDiscountReasonHidden').value = reason ? reason.value.trim() : '';
            // Tab settlement: no deal discounts (deals applied at order creation)
            document.getElementById('payTabDealDiscountAmtHidden').value = '0';
            document.getElementById('payTabDealIdsHidden').value = '';
        }

        /* ── 86 Mode (manager item availability toggle) ──────────────────── */
        let eightySixMode = false;

        function toggle86Mode() {
            if (!posCanToggle86) {
                openMgrAuthOverlay('pos_86', () => { if (!eightySixMode) toggle86Mode(); });
                return;
            }
            eightySixMode = !eightySixMode;
            const btn = document.getElementById('eightySixModeBtn');
            if (btn) btn.classList.toggle('mode-active', eightySixMode);
            renderMenu();
            posToastReady(eightySixMode ? '86 Mode ON — tap an item to toggle availability' : '86 Mode OFF', false);
        }

        async function doToggleItem(itemId) {
            try {
                const r = await fetch('pos.php?ajax=toggle_item&item_id=' + encodeURIComponent(itemId), { credentials: 'same-origin' });
                const j = await r.json();
                if (j.ok) {
                    const item = menuList.find(m => m.id === itemId);
                    if (item) item.is_available = j.is_available;
                    renderMenu();
                    posToastReady((j.is_available ? '✓ Re-enabled: ' : '86\'d: ') + (j.item_name || ''), !j.is_available);
                } else {
                    posToastReady(j.error || 'Toggle failed.', true);
                }
            } catch (e) {
                posToastReady('Network error toggling item.', true);
            }
        }

        /* ── Manager In-Session Auth Overlay ────────────────────────────── */
        let _mgrAuthCallback = null; // fn to call after manager authorises
        let _mgrAuthPermission = '';
        let _mgrAuthToken = '';     // returned server token, passed in privileged form

        function openMgrAuthOverlay(requiredPermission, onAuthorised) {
            _mgrAuthPermission = requiredPermission;
            _mgrAuthCallback   = onAuthorised;
            _mgrAuthToken      = '';
            document.getElementById('mgrAuthUsername').value = '';
            document.getElementById('mgrAuthPassword').value = '';
            document.getElementById('mgrAuthError').textContent = '';
            const permLabels = {
                pos_refund: 'Process Refunds',
                pos_86: 'Quick-86 Items',
                pos_float: 'Opening Float',
                pos_discount: 'Apply Discounts',
                pos_force_serve: 'Force-Serve Stranded Food',
            };
            document.getElementById('mgrAuthPermLabel').textContent = permLabels[requiredPermission] || requiredPermission;
            document.getElementById('mgrAuthOverlay').classList.add('show');
            setTimeout(() => { const u = document.getElementById('mgrAuthUsername'); if (u) u.focus(); }, 80);
        }

        function closeMgrAuthOverlay() {
            document.getElementById('mgrAuthOverlay').classList.remove('show');
            _mgrAuthCallback  = null;
            _mgrAuthToken     = '';
        }

        async function submitMgrAuth(e) {
            e.preventDefault();
            const form = e.target;
            const btn  = form.querySelector('button[type="submit"]');
            const orig = btn.innerHTML;
            const errEl = document.getElementById('mgrAuthError');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying…';
            errEl.textContent = '';
            try {
                const fd = new FormData();
                fd.append('username', document.getElementById('mgrAuthUsername').value.trim());
                fd.append('password', document.getElementById('mgrAuthPassword').value);
                fd.append('required_permission', _mgrAuthPermission);
                const r = await fetch('pos.php?ajax=manager_auth', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                    credentials: 'same-origin',
                });
                const j = await r.json();
                if (j.ok) {
                    _mgrAuthToken = j.token;
                    closeMgrAuthOverlay();
                    posToastReady('Authorised by ' + escHtml(j.manager_name), false);
                    if (typeof _mgrAuthCallback === 'function') _mgrAuthCallback(_mgrAuthToken);
                } else {
                    errEl.textContent = j.error || 'Authorisation failed.';
                }
            } catch (err) {
                errEl.textContent = 'Network error.';
            }
            btn.disabled = false;
            btn.innerHTML = orig;
        }

        /* ── Opening Float modal ─────────────────────────────────────────── */
        function openFloatModal() {
            if (!posCanFloat) {
                openMgrAuthOverlay('pos_float', () => openFloatModal());
                return;
            }
            document.getElementById('floatOverlay').classList.add('show');
            const fa = document.getElementById('floatAmount');
            if (fa) { fa.value = ''; fa.focus(); }
        }

        function closeFloatModal() {
            document.getElementById('floatOverlay').classList.remove('show');
        }

        async function submitFloat(e) {
            e.preventDefault();
            const form = e.target;
            const btn = form.querySelector('button[type="submit"]');
            const origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
            try {
                const r = await fetch('pos.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });
                const j = await r.json();
                if (j.ok) {
                    closeFloatModal();
                    posToastReady(j.message || 'Float recorded.', false);
                    form.reset();
                } else {
                    posToastReady(j.error || 'Error saving float.', true);
                }
            } catch (err) {
                posToastReady('Network error.', true);
            }
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }

        /* ── Refund modal ────────────────────────────────────────────────── */
        function openRefundModal(orderId, ref, total) {
            if (!posCanRefund) {
                // Cashier needs manager auth — after approval, re-open the refund modal
                openMgrAuthOverlay('pos_refund', (token) => {
                    _pendingRefundToken = token;
                    openRefundModal(orderId, ref, total);
                });
                return;
            }
            document.getElementById('refundOrderId').value = orderId;
            document.getElementById('refundOrderRef').textContent = ref;
            document.getElementById('refundOrderAmt').textContent = currencySymbol + ' ' + fmtMoney(total);
            document.getElementById('refundReason').value = '';
            // Attach the manager auth token (if obtained) to the form
            let tokenField = document.getElementById('refundMgrToken');
            if (!tokenField) {
                tokenField = document.createElement('input');
                tokenField.type = 'hidden';
                tokenField.id   = 'refundMgrToken';
                tokenField.name = 'mgr_auth_token';
                document.getElementById('refundForm').appendChild(tokenField);
            }
            tokenField.value = _pendingRefundToken || '';
            _pendingRefundToken = '';
            document.getElementById('refundOverlay').classList.add('show');
            setTimeout(() => { const r = document.getElementById('refundReason'); if (r) r.focus(); }, 80);
        }
        let _pendingRefundToken = '';

        function closeRefundModal() {
            document.getElementById('refundOverlay').classList.remove('show');
        }

        async function submitRefund(e) {
            e.preventDefault();
            const form = e.target;
            const btn = form.querySelector('button[type="submit"]');
            const origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
            try {
                const r = await fetch('pos.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });
                const j = await r.json();
                if (j.ok) {
                    closeRefundModal();
                    posToastReady(j.message || 'Refund processed.', false);
                    setTimeout(() => location.reload(), 1800);
                } else {
                    posToastReady(j.error || 'Refund failed.', true);
                }
            } catch (err) {
                posToastReady('Network error.', true);
            }
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }

        // Initial render
        renderMenu();
        renderCart();
        setTimeout(() => refreshShiftStats(true), 700);
        RHPoll.every(refreshShiftStats, 6000);

        <?php if ($settleAuto): ?>
            // Deep-link from stock-orders.php "Take Payment" — auto-open the settle modal for this tab.
            openPayForTab(<?php echo (int)$settleAuto['id']; ?>, <?php echo number_format($settleAuto['total'], 2, '.', ''); ?>, <?php echo json_encode($settleAuto['ref']); ?>);
        <?php endif; ?>

        <?php if (in_array($user['role'] ?? '', ['admin', 'manager'], true)): ?>
            /* ============================================================
             * Admin/manager: live "All Stations" poller.
             * Polls ?ajax=stations every few seconds; updates badges + tray content
             * if open (shows full ticket lists). Skips when tab is hidden
             * to spare the DB.
             * ============================================================ */
            function openStationsTray() {
                document.getElementById('stationsOverlay').classList.add('show');
                refreshStations(true);
            }

            function fmtAge(firedAt) {
                if (!firedAt) return '';
                const sec = Math.max(0, Math.floor((Date.now() - new Date(firedAt.replace(' ', 'T')).getTime()) / 1000));
                const m = Math.floor(sec / 60);
                const s = sec % 60;
                return m > 0 ? (m + 'm ' + String(s).padStart(2, '0') + 's') : (s + 's');
            }

            function ageColor(firedAt) {
                if (!firedAt) return '#6c757d';
                const sec = Math.max(0, Math.floor((Date.now() - new Date(firedAt.replace(' ', 'T')).getTime()) / 1000));
                if (sec >= 900) return '#c82333';
                if (sec >= 600) return '#d4a843';
                return '#155724';
            }

            function renderStationTickets(stKey, tickets) {
                const box = document.getElementById('stTickets-' + stKey);
                if (!box) return;
                if (!tickets || !tickets.length) {
                    box.innerHTML = '<em style="color:#6c757d;">No open tickets.</em>';
                    return;
                }
                // Group by order_id so each ticket is one card.
                const groups = {};
                tickets.forEach(t => {
                    (groups[t.order_id] = groups[t.order_id] || {
                        order: t,
                        items: []
                    }).items.push(t);
                });
                let html = '';
                Object.values(groups).forEach(g => {
                    const o = g.order;
                    const room = o.booking_room_number ? 'Room ' + o.booking_room_number :
                        (o.table_number ? 'Table ' + o.table_number : (o.customer_name || '—'));
                    html += '<div style="border:1px solid #eaecef; border-radius:6px; padding:8px; margin-bottom:6px; background:#fbfbfd;">' +
                        '<div style="display:flex; justify-content:space-between; font-weight:600; font-size:12px;">' +
                        '<span>' + escAttr(o.reference) + ' · ' + escAttr(room) + '</span>' +
                        '<span style="color:' + ageColor(o.fired_at) + ';">' + fmtAge(o.fired_at) + '</span>' +
                        '</div>';
                    g.items.forEach(it => {
                        const statusColor = it.kds_status === 'pending' ? '#856404' :
                            (it.kds_status === 'preparing' ? '#004085' : '#155724');
                        html += '<div style="font-size:11px; color:#495057; margin-top:3px;">' +
                            '<span style="color:' + statusColor + '; font-weight:600;">[' + escAttr(it.kds_status) + ']</span> ' +
                            escAttr(it.quantity) + '× ' + escAttr(it.item_name) +
                            (it.notes ? ' <em style="color:#6c757d;">(' + escAttr(it.notes) + ')</em>' : '') +
                            '</div>';
                    });
                    html += '</div>';
                });
                box.innerHTML = html;
            }

            function escAttr(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [c]));
            }

            let _stationsInflight = false;
            async function refreshStations(forceFull) {
                if (_stationsInflight) return;
                if (document.hidden && !forceFull) return;
                _stationsInflight = true;
                try {
                    const r = await fetch('pos.php?ajax=stations', {
                        credentials: 'same-origin'
                    });
                    if (!r.ok) return;
                    const d = await r.json();
                    if (d.error) return;
                    // Update top-bar badges.
                    const setBadge = (id, n) => {
                        const el = document.getElementById(id);
                        if (!el) return;
                        el.textContent = n;
                        el.style.display = n > 0 ? '' : 'none';
                    };
                    setBadge('kitchenBadge', d.counts.kitchen.open_total);
                    setBadge('barBadge', d.counts.bar.open_total);
                    setBadge('coffeeBadge', d.counts.coffee_bar.open_total);
                    setBadge('stationsBadge', d.counts.kitchen.open_total + d.counts.bar.open_total + d.counts.coffee_bar.open_total);
                    setBadge('tabBadge', parseInt(d.open_tabs_visible ?? d.open_tabs_all ?? 0, 10) || 0);
                    _syncPosMobileBadges();

                    // If the tray is open, also refresh the inside.
                    const tray = document.getElementById('stationsOverlay');
                    if (tray && tray.classList.contains('show')) {
                        document.getElementById('stationsTs').textContent = new Date().toLocaleTimeString();
                        document.getElementById('stOpenTabs').textContent = d.open_tabs_all;
                        document.getElementById('stOrdersToday').textContent = d.orders_today;
                        document.getElementById('stRevenueToday').textContent = currencySymbol + ' ' + fmtMoney(d.revenue_today);
                        ['kitchen', 'bar', 'coffee_bar'].forEach(stKey => {
                            const c = d.counts[stKey];
                            document.getElementById('stOpen-' + stKey).textContent = c.open_total;
                            document.getElementById('stPending-' + stKey).textContent = c.pending;
                            document.getElementById('stReady-' + stKey).textContent = c.ready;
                            renderStationTickets(stKey, d.tickets[stKey]);
                        });
                    }
                } catch (e) {
                    /* network blip — next poll will retry */
                } finally {
                    _stationsInflight = false;
                }
            }
            // Poll quickly; first poll after load so initial page render is unaffected.
            // Kept active in background tabs so station counters stay live.
            setTimeout(refreshStations, 1200);
            RHPoll.every(refreshStations, 4000);

            /* ============================================================
             * Restaurant orders today modal
             * ============================================================ */
            let _restoOrdersData = null;
            let _restoFilter = 'all';

            function openRestoOrdersModal() {
                document.getElementById('restoOrdersOverlay').classList.add('show');
                _restoOrdersData = null; // force fresh fetch
                showPosActionLoader('Loading orders…', 'Fetching today\'s restaurant orders.');
                loadRestoOrders('all');
            }
            async function loadRestoOrders(filter) {
                if (filter !== undefined) _restoFilter = filter;
                ['rfAll', 'rfPending', 'rfPaid'].forEach(id => {
                    const b = document.getElementById(id);
                    if (b) b.style.background = '#fff';
                });
                const activeId = {
                    all: 'rfAll',
                    pending: 'rfPending',
                    paid: 'rfPaid'
                } [_restoFilter];
                if (activeId) {
                    const b = document.getElementById(activeId);
                    if (b) b.style.background = '#edf2f7';
                }
                const tableEl = document.getElementById('restoOrdersTable');
                const summEl = document.getElementById('restoOrdersSummary');
                if (!_restoOrdersData) {
                    tableEl.innerHTML = '<p style="color:#6c757d;text-align:center;padding:30px 0;"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';
                }
                try {
                    const resp = await fetch('?ajax=resto_orders', {
                        credentials: 'include'
                    });
                    const d = await resp.json();
                    if (!d.orders) {
                        tableEl.innerHTML = '<p style="color:#c82333; padding:20px;">Error loading orders.</p>';
                        return;
                    }
                    _restoOrdersData = d.orders;
                    const s = d.summary || {};
                    summEl.innerHTML = 'Total: <strong>' + (s.total || 0) + '</strong>' +
                        ' &nbsp;·&nbsp; Pending: <strong>' + (s.open_tabs || 0) + '</strong>' +
                        ' &nbsp;·&nbsp; Paid: <strong>' + (s.paid || 0) + '</strong>' +
                        ' &nbsp;·&nbsp; Revenue: <strong>' + currencySymbol + ' ' + fmtMoney(s.revenue || 0) + '</strong>';
                    renderRestoOrders();
                } catch (e) {
                    tableEl.innerHTML = '<p style="color:#c82333; padding:20px;">Failed: ' + escHtml(e.message) + '</p>';
                } finally {
                    hidePosActionLoader();
                }
            }

            function renderRestoOrders() {
                const tableEl = document.getElementById('restoOrdersTable');
                if (!_restoOrdersData) return;
                let orders = _restoOrdersData;
                if (_restoFilter === 'pending') orders = orders.filter(o => o.status === 'placed');
                else if (_restoFilter === 'paid') orders = orders.filter(o => o.status === 'paid');
                if (!orders.length) {
                    tableEl.innerHTML = '<p style="color:#6c757d; text-align:center; padding:30px 0;">No orders match this filter.</p>';
                    return;
                }
                const statusBadge = s => {
                    const c = {
                        placed: '#856404',
                        paid: '#155724'
                    } [s] || '#495057';
                    const label = s === 'placed' ? 'pending' : s;
                    return '<span style="color:' + c + ';font-weight:600;text-transform:capitalize;">' + escHtml(label) + '</span>';
                };
                let html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">' +
                    '<thead><tr style="border-bottom:2px solid #eaecef; font-size:11px; color:#6c757d; text-transform:uppercase; letter-spacing:.4px;">' +
                    '<th style="text-align:left;padding:7px 8px;">Ref</th>' +
                    '<th style="text-align:left;padding:7px 8px;">Type</th>' +
                    '<th style="text-align:left;padding:7px 8px;">Location</th>' +
                    '<th style="text-align:center;padding:7px 8px;">Items</th>' +
                    '<th style="text-align:left;padding:7px 8px;">Status</th>' +
                    '<th style="text-align:right;padding:7px 8px;">Total</th>' +
                    '<th style="text-align:center;padding:7px 8px;">Time</th>' +
                    '<th style="padding:7px 8px;"></th>' +
                    '</tr></thead><tbody>';
                orders.forEach((o, i) => {
                    const loc = o.room_number ? 'Room ' + o.room_number :
                        (o.table_number ? 'Table ' + o.table_number :
                            (o.customer_name || '—'));
                    const time = (o.created_at || '').substring(11, 16);
                    const bg = i % 2 === 0 ? '#fff' : '#f8f9fb';
                    html += '<tr style="border-bottom:1px solid #f0f0f0;background:' + bg + ';">' +
                        '<td style="padding:7px 8px;font-weight:600;">' + escHtml(o.reference) + '</td>' +
                        '<td style="padding:7px 8px;color:#6c757d;">' + escHtml((o.order_type || '—').replace(/_/g, ' ')) + '</td>' +
                        '<td style="padding:7px 8px;">' + escHtml(loc) + '</td>' +
                        '<td style="padding:7px 8px;text-align:center;">' + (o.item_count || 0) + '</td>' +
                        '<td style="padding:7px 8px;">' + statusBadge(o.status) + '</td>' +
                        '<td style="padding:7px 8px;text-align:right;">' + currencySymbol + ' ' + fmtMoney(o.total_amount) + '</td>' +
                        '<td style="padding:7px 8px;text-align:center;color:#6c757d;">' + escHtml(time) + '</td>' +
                        '<td style="padding:7px 8px;white-space:nowrap;">' +
                        '<a href="order-lifecycle.php?id=' + o.id + '" target="_blank" style="font-size:11px;color:#8B7355;text-decoration:none;" title="Lifecycle log">Log</a>' +
                        '</td></tr>';
                });
                html += '</tbody></table>';
                tableEl.innerHTML = html;
            }
        <?php endif; ?>

            /* ── Draggable floating widgets ──────────────────────────────────── */
            (function() {
                /* handleEl: the element that initiates drag; el: the element that moves */
                function makeWidgetDraggable(el, storageKey, handleEl) {
                    try {
                        var saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
                        if (saved && typeof saved.left === 'number' && typeof saved.top === 'number') {
                            applyAbsPos(el, saved.left, saved.top);
                            constrainWidgetToViewport(el);
                        }
                    } catch (_) {}

                    var grip = handleEl || el;
                    var ds = null;

                    grip.addEventListener('pointerdown', function(e) {
                        if (e.button !== 0 && e.pointerType === 'mouse') return;
                        var r = el.getBoundingClientRect();
                        ds = {
                            sx: e.clientX,
                            sy: e.clientY,
                            ol: r.left,
                            ot: r.top,
                            moved: false
                        };
                        grip.setPointerCapture(e.pointerId);
                        e.stopPropagation();
                    });

                    grip.addEventListener('pointermove', function(e) {
                        if (!ds) return;
                        var dx = e.clientX - ds.sx,
                            dy = e.clientY - ds.sy;
                        if (!ds.moved && Math.abs(dx) + Math.abs(dy) < 6) return;
                        ds.moved = true;
                        grip.style.cursor = 'grabbing';
                        var maxL = window.innerWidth - el.offsetWidth - 4;
                        var maxT = window.innerHeight - el.offsetHeight - 4;
                        applyAbsPos(el, Math.max(4, Math.min(maxL, ds.ol + dx)), Math.max(4, Math.min(maxT, ds.ot + dy)));
                    });

                    grip.addEventListener('pointerup', function() {
                        if (!ds) return;
                        var wasMoved = ds.moved;
                        ds = null;
                        grip.style.cursor = 'grab';
                        if (wasMoved) {
                            var r = el.getBoundingClientRect();
                            try {
                                localStorage.setItem(storageKey, JSON.stringify({
                                    left: r.left,
                                    top: r.top
                                }));
                            } catch (_) {}
                        }
                        constrainWidgetToViewport(el, storageKey);
                    });

                    grip.addEventListener('pointercancel', function() {
                        ds = null;
                        grip.style.cursor = 'grab';
                    });
                }

                function applyAbsPos(el, left, top) {
                    el.style.left = left + 'px';
                    el.style.top = top + 'px';
                    el.style.right = 'auto';
                    el.style.bottom = 'auto';
                }

                function constrainWidgetToViewport(el, storageKey) {
                    if (!el) return;
                    if (window.getComputedStyle(el).display === 'none') return;

                    /* Only touch widgets the user has actually dragged. This used to run on every
                     * load and resize regardless, and because applyAbsPos() strips the CSS
                     * bottom/right anchoring in favour of hard left/top pixels — then SAVED them —
                     * a widget that had never been moved got permanently pinned to wherever it
                     * happened to be measured. That is how the inbox and My Orders pills ended up
                     * parked in the middle of the order panel with no way back: the corner
                     * defaults could never apply again. Undragged widgets now keep their CSS
                     * anchoring and stay in their corners. */
                    var hasUserPosition = false;
                    try {
                        hasUserPosition = !!(storageKey && localStorage.getItem(storageKey));
                    } catch (_) {}
                    if (!hasUserPosition) return;

                    var pad = 8;
                    var r = el.getBoundingClientRect();
                    var maxL = Math.max(pad, window.innerWidth - r.width - pad);
                    var maxT = Math.max(pad, window.innerHeight - r.height - pad);
                    var nextL = Math.max(pad, Math.min(maxL, r.left));
                    var nextT = Math.max(pad, Math.min(maxT, r.top));
                    if (nextL === r.left && nextT === r.top) return;
                    applyAbsPos(el, nextL, nextT);
                    try {
                        localStorage.setItem(storageKey, JSON.stringify({
                            left: nextL,
                            top: nextT
                        }));
                    } catch (_) {}
                }

                function syncFloatingWidgetsToViewport() {
                    constrainWidgetToViewport(document.getElementById('posInboxWidget'), 'rh_pos_inbox_pos');
                    constrainWidgetToViewport(document.getElementById('myOrdersWidget'), 'rh_pos_orders_pos');
                }

                window.__posClampFloatingWidgets = syncFloatingWidgetsToViewport;

                document.addEventListener('DOMContentLoaded', function() {
                    var inbox = document.getElementById('posInboxWidget');
                    var orders = document.getElementById('myOrdersWidget');
                    if (inbox) makeWidgetDraggable(inbox, 'rh_pos_inbox_pos', document.getElementById('posInboxDragHandle'));
                    if (orders) makeWidgetDraggable(orders, 'rh_pos_orders_pos', document.getElementById('myOrdersDragHandle'));
                    window.addEventListener('resize', syncFloatingWidgetsToViewport);
                    setTimeout(syncFloatingWidgetsToViewport, 80);
                });
            }());

        // Auto-send receipt after redirect payment when contact info was captured
        if (posLastOrderId > 0 && !posJustParked && (posLastOrderEmail || posLastOrderPhone)) {
            document.addEventListener('DOMContentLoaded', function() {
                _receiptOrderId = posLastOrderId;
                setTimeout(function() {
                    if (posLastOrderEmail) {
                        var emailEl = document.getElementById('rmEmail');
                        if (emailEl) emailEl.value = posLastOrderEmail;
                        sendPosReceipt('email');
                    }
                    if (posLastOrderPhone) {
                        var phoneEl = document.getElementById('rmPhone');
                        if (phoneEl) phoneEl.value = posLastOrderPhone;
                        sendPosReceipt('whatsapp');
                    }
                }, 600);
            });
        }
    </script>
    <?php $rh_help_hide_fab = true;
    $rh_help_disable_fallback = true;
    require __DIR__ . '/includes/help-tooltips.php'; ?>
    <?php require __DIR__ . '/includes/offline-banner.php'; ?>

    <!-- Station inbox widget -->
    <div id="posInboxWidget" style="display:none;position:fixed;bottom:90px;right:22px;z-index:99990;flex-direction:column;align-items:flex-end;gap:8px;">
        <!-- Inbox slide-up panel -->
        <div id="posInboxPanel" style="display:none;width:320px;max-height:420px;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.22);border:1px solid #e5e7eb;overflow:hidden;">
            <div style="padding:11px 14px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
                <strong style="font-size:13px;display:flex;align-items:center;gap:6px;"><i class="fas fa-inbox" style="color:#1d6a3e;"></i> Station Replies</strong>
                <button onclick="togglePosInbox()" style="background:none;border:none;cursor:pointer;font-size:17px;color:#9ca3af;line-height:1;">&times;</button>
            </div>
            <div id="posInboxList" style="max-height:340px;overflow-y:auto;">
                <p style="text-align:center;color:#9ca3af;padding:20px;font-size:13px;">Loading…</p>
            </div>
        </div>
        <!-- FAB row: inbox button + drag handle on the right -->
        <div style="display:flex;align-items:center;gap:6px;">
            <button id="posInboxBtn" onclick="togglePosInbox()" title="Station message inbox" style="width:52px;height:52px;border-radius:50%;background:#1d4a2e;border:none;color:#86efac;font-size:20px;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;position:relative;touch-action:manipulation;flex-shrink:0;">
                <i class="fas fa-inbox"></i>
                <span id="posInboxBadge" style="display:none;position:absolute;top:-3px;right:-3px;background:#c82333;color:#fff;font-size:10px;font-weight:800;padding:2px 5px;border-radius:10px;min-width:18px;text-align:center;line-height:1.4;"></span>
            </button>
            <div id="posInboxDragHandle" class="pos-drag-grip" title="Drag to reposition" style="width:20px;height:52px;display:flex;align-items:center;justify-content:center;cursor:grab;color:rgba(134,239,172,0.5);font-size:13px;touch-action:none;user-select:none;-webkit-user-select:none;border-radius:10px;background:rgba(29,74,46,0.55);border:1px solid rgba(134,239,172,0.15);transition:background 0.15s,color 0.15s;">
                <i class="fas fa-grip-vertical"></i>
            </div>
        </div>
    </div>

    <!-- My orders live tracker — floating bottom-left -->
    <div id="myOrdersWidget" style="position:fixed;bottom:22px;left:22px;z-index:99990;display:flex;flex-direction:column;align-items:flex-start;gap:8px;">
        <div id="myOrdersPanel" style="display:none;width:380px;max-width:calc(100vw - 44px);max-height:520px;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.22);border:1px solid #e5e7eb;overflow:hidden;">
            <div style="padding:11px 14px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#fdf8f3,#f5efe5);">
                <strong style="font-size:13px;display:flex;align-items:center;gap:6px;color:#5a4a36;"><i class="fas fa-list-check" style="color:#8B7355;"></i> My Orders Today</strong>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span id="myOrdersTotalChip" style="font-size:11px;color:#6c757d;background:#fff;padding:2px 8px;border-radius:9px;border:1px solid #e5e7eb;">0</span>
                    <button onclick="toggleMyOrders()" style="background:none;border:none;cursor:pointer;font-size:17px;color:#9ca3af;line-height:1;">&times;</button>
                </div>
            </div>
            <div id="myOrdersList" style="max-height:460px;overflow-y:auto;">
                <p style="text-align:center;color:#9ca3af;padding:24px 18px;font-size:13px;">Loading…</p>
            </div>
        </div>
        <!-- FAB row: my-orders button + drag handle on the right -->
        <div style="display:flex;align-items:center;gap:6px;">
            <button id="myOrdersBtn" onclick="openMyOrdersCurrentDetail()" title="My orders — live status" style="height:52px;padding:0 18px;border-radius:26px;background:linear-gradient(135deg,#8B7355,#6f5b41);border:none;color:#fff;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.35);display:flex;align-items:center;gap:9px;position:relative;touch-action:manipulation;">
                <i class="fas fa-list-check"></i>
                <span>My Orders</span>
                <span id="myOrdersBadge" style="display:none;background:#fff;color:#8B7355;font-size:11px;font-weight:800;padding:2px 7px;border-radius:10px;min-width:20px;text-align:center;line-height:1.4;">0</span>
            </button>
            <div id="myOrdersDragHandle" class="pos-drag-grip" title="Drag to reposition" style="width:20px;height:52px;display:flex;align-items:center;justify-content:center;cursor:grab;color:rgba(255,255,255,0.45);font-size:13px;touch-action:none;user-select:none;-webkit-user-select:none;border-radius:10px;background:rgba(139,115,85,0.5);border:1px solid rgba(255,255,255,0.12);transition:background 0.15s,color 0.15s;">
                <i class="fas fa-grip-vertical"></i>
            </div>
        </div>
    </div>

    <!-- Cart backdrop (mobile) -->
    <div class="cart-backdrop" id="cartBackdrop" onclick="toggleCartDrawer()"></div>

    <!-- Mobile POS bottom-sheet menu -->
    <div class="pos-mobile-backdrop" id="posMobileBackdrop" onclick="closePosMobileMenu()"></div>
    <div class="pos-mobile-sheet" id="posMobileMenu" role="dialog" aria-modal="true" aria-label="POS mobile menu">
        <div class="pm-handle"></div>
        <div class="pm-head">
            <div class="pm-title">
                <strong><?php echo htmlspecialchars($siteName); ?></strong>
                <span>Point of Sale Menu</span>
            </div>
            <button type="button" class="pm-close" onclick="closePosMobileMenu()" aria-label="Close POS menu"><i class="fas fa-times"></i></button>
        </div>
        <div class="pm-user">
            <div class="pm-user-avatar"><i class="fas fa-user-circle"></i></div>
            <div>
                <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                <span><?php echo htmlspecialchars(ucfirst($user['role'] ?? 'Cashier')); ?></span>
            </div>
        </div>
        <div class="pm-scroll">
            <section>
                <h3 class="pm-group-title">Till Actions</h3>
                <div class="pos-mobile-actions" aria-label="POS mobile actions">
                    <button type="button" class="pos-mobile-action is-scan" id="posCamScanBtn" onclick="runPosMobileMenuAction('posCamScanOpen')" title="Camera barcode scanner"><i class="fas fa-camera"></i><span>Scan</span></button>
                    <button type="button" class="pos-mobile-action" onclick="runPosMobileMenuAction('openPosMobileRecentView')"><i class="fas fa-receipt"></i><span>Recent</span></button>
                    <button type="button" class="pos-mobile-action" onclick="runPosMobileMenuAction('openTabsTray')"><i class="fas fa-utensils"></i><span>Tabs</span><span class="mobile-action-badge" id="mobileTabBadge" <?php echo empty($openTabs) ? ' style="display:none;"' : ''; ?>><?php echo count($openTabs); ?></span></button>
                    <button type="button" class="pos-mobile-action" onclick="runPosMobileMenuAction('openStationNoteModal')"><i class="fas fa-paper-plane"></i><span>Note</span></button>
                    <button type="button" class="pos-mobile-action" onclick="runPosMobileMenuAction('openPosMobileInboxView')"><i class="fas fa-inbox"></i><span>Inbox</span><span class="mobile-action-badge" id="mobileInboxBadge" style="display:none;"></span></button>
                    <button type="button" class="pos-mobile-action is-primary" onclick="runPosMobileMenuAction('toggleCartDrawer')"><i class="fas fa-shopping-cart"></i><span>Cart</span><span class="mobile-action-badge" id="mobileCartBadge" style="display:none;"></span></button>
                    <button type="button" class="pos-mobile-action" onclick="runPosMobileMenuAction('openPosMobileOrdersView')"><i class="fas fa-list-check"></i><span>My Orders</span><span class="mobile-action-badge" id="mobileMyOrdersBadge" style="display:none;"></span></button>

                    <button type="button" class="pos-mobile-action" onclick="runPosMobileMenuAction('openCloseShift')"><i class="fas fa-cash-register"></i><span>Close Shift</span></button>
                </div>
            </section>
            <?php if (in_array($user['role'] ?? '', ['admin', 'manager'], true)): ?>
                <section>
                    <h3 class="pm-group-title">Stations</h3>
                    <div class="pm-grid">
                        <?php if (moduleEnabled('station_kds')): ?>
                        <a class="pm-action" href="kds.php" target="_blank" rel="noopener"><i class="fas fa-utensils"></i><span>Kitchen</span><span class="pm-badge" id="menuKitchenBadge" style="<?php echo ($adminStationsInit['counts']['kitchen']['open_total'] ?? 0) > 0 ? '' : 'display:none;'; ?>"><?php echo (int)($adminStationsInit['counts']['kitchen']['open_total'] ?? 0); ?></span></a>
                        <?php endif; ?>
                        <?php if (moduleEnabled('station_bds')): ?>
                        <a class="pm-action" href="bds.php" target="_blank" rel="noopener"><i class="fas fa-wine-glass"></i><span>Bar</span><span class="pm-badge" id="menuBarBadge" style="<?php echo ($adminStationsInit['counts']['bar']['open_total'] ?? 0) > 0 ? '' : 'display:none;'; ?>"><?php echo (int)($adminStationsInit['counts']['bar']['open_total'] ?? 0); ?></span></a>
                        <?php endif; ?>
                        <?php if (moduleEnabled('station_cds')): ?>
                        <a class="pm-action" href="cds.php" target="_blank" rel="noopener"><i class="fas fa-mug-hot"></i><span>Coffee</span><span class="pm-badge" id="menuCoffeeBadge" style="<?php echo ($adminStationsInit['counts']['coffee_bar']['open_total'] ?? 0) > 0 ? '' : 'display:none;'; ?>"><?php echo (int)($adminStationsInit['counts']['coffee_bar']['open_total'] ?? 0); ?></span></a>
                        <?php endif; ?>
                        <button type="button" class="pm-action" onclick="runPosMobileMenuAction('openStationsTray')"><i class="fas fa-layer-group"></i><span>All Stations</span></button>
                        <?php if (moduleEnabled('stock')): ?>
                        <a class="pm-action" href="stock-orders.php"><i class="fas fa-list"></i><span>All Orders</span></a>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
            <section>
                <h3 class="pm-group-title">Tools</h3>
                <div class="pm-grid">
                    <?php if ($posCanToggle86): ?>
                    <button type="button" class="pm-action" id="pmBarcodeToggle" onclick="runPosMobileMenuAction('posToggleBarcodeScanner')"><i class="fas fa-barcode"></i><span id="pmBarcodeLbl">Barcode Scanner</span></button>
                    <?php endif; ?>
                    <button type="button" class="pm-action" onclick="runPosMobileMenuAction(function () { RHSounds.openSettings(); })"><i class="fas fa-sliders"></i><span>Sound Settings</span></button>
                    <button type="button" class="pm-action" onclick="runPosMobileMenuAction('toggleMobileHelp')"><i class="fas fa-question-circle"></i><span>Help Tooltips</span></button>
                    <a class="pm-action" href="../docs/guides/01-pos-till.html" target="_blank" rel="noopener"><i class="fas fa-book-open"></i><span>POS Guide</span></a>
                    <?php if (!$isFullScreen): ?>
                        <a class="pm-action" href="dashboard.php"><i class="fas fa-arrow-left"></i><span>Admin</span></a>
                    <?php endif; ?>
                    <a class="pm-action is-danger" href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Sign Out</span></a>
                </div>
            </section>
        </div>
    </div>
    <script src="js/pwa-install.js" defer></script>

    <!-- POS Camera Barcode Scanner Overlay -->
    <div id="posCamScanOverlay" role="dialog" aria-modal="true" aria-label="Camera barcode scanner" style="display:none;">
        <div class="pos-cam-header">
            <span class="pos-cam-title"><i class="fas fa-barcode" style="color:#4ade80;"></i> Scan Item</span>
            <div style="display:flex;align-items:center;gap:8px;">
                <button class="pos-cam-torch" id="posCamTorch" onclick="posCamToggleTorch()" aria-label="Toggle torch" title="Flashlight">
                    <i class="fas fa-bolt"></i>
                </button>
                <button class="pos-cam-close" onclick="posCamScanClose()" aria-label="Close scanner">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="pos-cam-view" id="posCamView">
            <video id="posCamVideo" autoplay playsinline muted></video>
            <canvas id="posCamCanvas"></canvas>
            <div class="pos-cam-guide">
                <div class="pos-cam-corner tl"></div>
                <div class="pos-cam-corner tr"></div>
                <div class="pos-cam-corner bl"></div>
                <div class="pos-cam-corner br"></div>
                <div class="pos-cam-scan-line"></div>
            </div>
            <!-- Feed lives here — CSS grid stacking puts it above the video on mobile -->
            <div class="pos-cam-feed" id="posCamFeed"></div>
        </div>
        <!-- Cart panel — slides up after first scan -->
        <div class="pos-cam-cart" id="posCamCart">
            <div class="pos-cam-cart-head" onclick="posCamCartToggle()">
                <span class="cc-title"><i class="fas fa-shopping-cart"></i> Cart</span>
                <span id="posCamCartTotal" class="cc-total"></span>
                <i class="fas fa-chevron-up cc-chevron"></i>
            </div>
            <div class="pos-cam-cart-body" id="posCamCartBody"></div>
            <div class="pos-cam-cart-actions" id="posCamCartActions" style="display:none;">
                <?php if ($posCanDiscount): ?>
                <button class="cc-act" onclick="posCamDiscountAction()" title="Discount"><i class="fas fa-percent"></i></button>
                <?php endif; ?>
                <button class="cc-act" onclick="posCamClearAction()" title="Clear cart"><i class="fas fa-trash-alt"></i></button>
                <button class="cc-act is-pay" onclick="posCamPayAction()"><i class="fas fa-credit-card"></i> Pay</button>
            </div>
        </div>
        <div class="pos-cam-footer">
            <span id="posCamStatus">Point camera at a barcode</span>
            <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                <label class="pos-cam-keep-lbl" title="Keep scanner open after each scan to add multiple items">
                    <input type="checkbox" id="posCamKeepOpen" checked> Keep open
                </label>
                <label class="pos-cam-keep-lbl" title="Auto-print receipt after every payment on this terminal">
                    <input type="checkbox" id="posCamAutoPrint" onchange="localStorage.setItem('pos_auto_print_receipt', this.checked ? '1' : '0')"> Auto-print
                </label>
            </div>
        </div>
    </div>

    <script>
    /* ── POS Camera Barcode Scanner ─────────────────────────────────────────── */
    (function () {
        'use strict';
        var _stream = null, _detector = null, _loopId = null, _canvas = null, _ctx = null;
        var _cooldown = false, _torchOn = false;
        var _ac = null; // AudioContext — created once on scanner open (requires user gesture)
        var _dispCanvas = null, _dispCtx = null, _drawId = null;

        function _startDrawLoop(video) {
            _dispCanvas = document.getElementById('posCamCanvas');
            if (!_dispCanvas) return;
            _dispCtx = _dispCanvas.getContext('2d');
            function draw() {
                _drawId = requestAnimationFrame(draw);
                if (!video || video.readyState < 2) return;
                var cw = _dispCanvas.offsetWidth, ch = _dispCanvas.offsetHeight;
                if (_dispCanvas.width !== cw || _dispCanvas.height !== ch) {
                    _dispCanvas.width = cw; _dispCanvas.height = ch;
                }
                if (!cw || !ch || !video.videoWidth || !video.videoHeight) return;
                // Draw with cover behaviour
                var scale = Math.max(cw / video.videoWidth, ch / video.videoHeight);
                var dw = video.videoWidth * scale, dh = video.videoHeight * scale;
                _dispCtx.drawImage(video, (cw - dw) / 2, (ch - dh) / 2, dw, dh);
            }
            draw();
        }
        function _stopDrawLoop() {
            if (_drawId) { cancelAnimationFrame(_drawId); _drawId = null; }
            if (_dispCtx && _dispCanvas) { _dispCtx.clearRect(0, 0, _dispCanvas.width, _dispCanvas.height); }
            _dispCanvas = null; _dispCtx = null;
        }
        var COOLDOWN_MS = 2200;

        async function _waitDetector() {
            // Already available natively (Chrome Android) — return immediately
            if (typeof BarcodeDetector !== 'undefined') return true;
            // Wait for the async polyfill loader to finish (max 4 s)
            for (var i = 0; i < 20; i++) {
                await new Promise(function (r) { setTimeout(r, 200); });
                if (typeof BarcodeDetector !== 'undefined') return true;
                if (window._posBarcodeDetectorReady) break; // loader done, still nothing
            }
            return typeof BarcodeDetector !== 'undefined';
        }

        // Back-button: push a history entry so Android back closes scanner instead of leaving the page
        window.addEventListener('popstate', function (e) {
            if (document.getElementById('posCamScanOverlay').style.display === 'flex') {
                posCamScanClose(true); // true = triggered by browser back, don't call history.back() again
            }
        });

        window.posCamScanOpen = async function () {
            var overlay = document.getElementById('posCamScanOverlay');
            if (!overlay) return;
            // Create AudioContext now — this IS a user gesture so it will be allowed
            if (!_ac) {
                try { _ac = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) {}
            }
            // Push dummy history state so browser back closes scanner, not the page
            history.pushState({ posCamScanner: true }, '');
            overlay.style.display = 'flex';
            // Clear previous feed
            var feed = document.getElementById('posCamFeed');
            if (feed) feed.innerHTML = '';
            // Restore auto-print checkbox state
            var apCb = document.getElementById('posCamAutoPrint');
            if (apCb) apCb.checked = localStorage.getItem('pos_auto_print_receipt') === '1';
            _setStatus('Starting camera…');

            var ok = await _waitDetector();
            if (!ok) {
                _setStatus('Camera scanning not supported on this browser. Use a Bluetooth scanner instead.');
                return;
            }

            try {
                _detector = new BarcodeDetector({
                    formats: ['ean_13','ean_8','code_128','code_39','upc_a','upc_e','qr_code','data_matrix','itf']
                });
            } catch (e) { _setStatus('Scanner init failed: ' + e.message); return; }

            try {
                _stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 640 }, height: { ideal: 480 } }
                });
            } catch (e) {
                _setStatus('Camera denied. Please allow camera access and try again.');
                return;
            }

            var video = document.getElementById('posCamVideo');
            video.srcObject = _stream;
            await video.play().catch(function () {});
            _startDrawLoop(video);
            var track = _stream.getVideoTracks()[0];
            if (track && track.getCapabilities && track.getCapabilities().torch) {
                var torchBtn = document.getElementById('posCamTorch');
                if (torchBtn) torchBtn.classList.add('visible');
            }

            _canvas = document.createElement('canvas');
            _ctx = _canvas.getContext('2d', { willReadFrequently: true });
            _cooldown = false; _torchOn = false;
            _loopId = setInterval(_scanFrame, 200);
            _setStatus('Point camera at a barcode');
            document.getElementById('posCamScanBtn') && document.getElementById('posCamScanBtn').classList.add('is-active');
        };

        window.posCamScanClose = function (fromPopstate) {
            clearInterval(_loopId); _loopId = null;
            _stopDrawLoop();
            _cooldown = false; _torchOn = false;
            if (_stream) { _stream.getTracks().forEach(function (t) { t.stop(); }); _stream = null; }
            if (_ac) { try { _ac.close(); } catch(e) {} _ac = null; }
            var video = document.getElementById('posCamVideo');
            if (video) video.srcObject = null;
            var torch = document.getElementById('posCamTorch');
            if (torch) { torch.classList.remove('active', 'visible'); }
            var cartEl = document.getElementById('posCamCart');
            if (cartEl) cartEl.classList.remove('open');
            var overlay = document.getElementById('posCamScanOverlay');
            if (overlay) overlay.style.display = 'none';
            document.getElementById('posCamScanBtn') && document.getElementById('posCamScanBtn').classList.remove('is-active');
            // If closed via X button (not by browser back), pop the history state we pushed
            if (!fromPopstate && history.state && history.state.posCamScanner) {
                history.back();
            }
        };

        // Expose so HID/USB scanners can trigger feed cards when overlay is open
        window._posCamOnExternalCode = function (code) {
            _refreshCart();
            _addFeedItem(code);
        };

        window.posCamToggleTorch = async function () {
            if (!_stream) return;
            var track = _stream.getVideoTracks()[0];
            if (!track) return;
            _torchOn = !_torchOn;
            try {
                await track.applyConstraints({ advanced: [{ torch: _torchOn }] });
                var btn = document.getElementById('posCamTorch');
                if (btn) btn.classList.toggle('active', _torchOn);
            } catch (e) { _torchOn = !_torchOn; }
        };

        async function _scanFrame() {
            if (_cooldown || !_stream) return;
            var video = document.getElementById('posCamVideo');
            if (!video || video.readyState < 2 || video.paused) return;
            try {
                var barcodes = await _detector.detect(video);
                if (barcodes.length > 0) _onCode(barcodes[0].rawValue);
            } catch (e) { /* ignore decode errors */ }
        }

        function _onCode(code) {
            _cooldown = true;
            setTimeout(function () { _cooldown = false; }, COOLDOWN_MS);

            // Haptic
            navigator.vibrate && navigator.vibrate(50);
            // Beep — uses pre-created AudioContext (avoids mobile autoplay block)
            try {
                if (_ac) {
                    if (_ac.state === 'suspended') _ac.resume();
                    var osc = _ac.createOscillator(), g = _ac.createGain();
                    osc.connect(g); g.connect(_ac.destination);
                    osc.type = 'square'; osc.frequency.value = 1450;
                    g.gain.setValueAtTime(0.18, _ac.currentTime);
                    g.gain.exponentialRampToValueAtTime(0.001, _ac.currentTime + 0.1);
                    osc.start(_ac.currentTime); osc.stop(_ac.currentTime + 0.11);
                }
            } catch (e) {}

            // Flash viewfinder
            var view = document.getElementById('posCamView');
            if (view) { view.classList.add('found-flash'); setTimeout(function () { view.classList.remove('found-flash'); }, 360); }

            _setStatus('Scanned: ' + code);
            if (typeof posHandleBarcodeInput === 'function') posHandleBarcodeInput(code);

            // Show feed card + refresh mini cart immediately (addToCart is synchronous)
            _refreshCart();
            _addFeedItem(code);

            var keepOpen = document.getElementById('posCamKeepOpen');
            if (!keepOpen || !keepOpen.checked) {
                // Give user 3 seconds to see the scan feedback before closing
                setTimeout(function () { _setStatus('Closing…'); }, 2600);
                setTimeout(posCamScanClose, 3000);
            } else {
                setTimeout(function () { _setStatus('Ready — scan next item'); }, 1200);
            }
        }

        function _refreshCart() {
            var cartEl  = document.getElementById('posCamCart');
            var bodyEl  = document.getElementById('posCamCartBody');
            var totalEl = document.getElementById('posCamCartTotal');
            var actEl   = document.getElementById('posCamCartActions');
            if (!cartEl || !bodyEl) return;
            var sym = (typeof currencySymbol !== 'undefined') ? currencySymbol : 'MWK';
            if (typeof cart === 'undefined' || !cart.length) {
                bodyEl.innerHTML = '<div class="pos-cam-cart-empty">Cart empty — scan an item</div>';
                if (totalEl) totalEl.textContent = '';
                if (actEl) actEl.style.display = 'none';
                cartEl.classList.remove('open');
                return;
            }
            var html = '', grandTotal = 0;
            for (var i = 0; i < cart.length; i++) {
                var item = cart[i];
                var lineTotal = (parseFloat(item.price) || 0) * (parseFloat(item.qty) || 1);
                grandTotal += lineTotal;
                html += '<div class="pos-cam-cart-row">'
                    + '<span class="cc-name">' + _esc(item.name || '') + '</span>'
                    + '<div class="cc-row-qty">'
                    +   '<button class="cc-qty-btn" onclick="posCamBump(' + i + ',-1)">−</button>'
                    +   '<span class="cc-qty-val">' + (item.qty || 1) + '</span>'
                    +   '<button class="cc-qty-btn" onclick="posCamBump(' + i + ',1)">+</button>'
                    + '</div>'
                    + '<span class="cc-price">' + sym + ' ' + _fmt(lineTotal) + '</span>'
                    + '<button class="cc-rm" onclick="posCamRm(' + i + ')" title="Remove"><i class="fas fa-times"></i></button>'
                    + '</div>';
            }
            // Add deal savings lines
            applyDeals();
            if (_dealLines && _dealLines.length) {
                for (var d = 0; d < _dealLines.length; d++) {
                    html += '<div class="cc-deal-line"><i class="fas fa-tags"></i> '
                        + _esc(_dealLines[d].name)
                        + '<span class="cc-deal-saving">−' + sym + ' ' + _fmt(_dealLines[d].saving) + '</span></div>';
                }
                grandTotal = Math.max(0, grandTotal - _dealSavings);
            }

            bodyEl.innerHTML = html;
            if (totalEl) totalEl.textContent = sym + ' ' + _fmt(grandTotal);
            if (actEl) actEl.style.display = 'flex';
            cartEl.classList.add('open');
        }

        window.posCamCartToggle = function () {
            var cartEl = document.getElementById('posCamCart');
            if (cartEl) cartEl.classList.toggle('open');
        };

        window.posCamBump = function (idx, d) {
            if (d > 0 && typeof cart !== 'undefined' && cart[idx]) {
                var item = cart[idx];
                var stockKey = item.type + ':' + item.id;
                var inStock = Object.prototype.hasOwnProperty.call(stockSnapshot, stockKey) ? stockSnapshot[stockKey] : null;
                if (inStock !== null && item.qty >= inStock) {
                    posToast('Out of stock: ' + item.name, 'err', 2000);
                    return;
                }
            }
            bump(idx, d);
            _refreshCart();
        };
        window.posCamRm   = function (idx)    { rm(idx);       _refreshCart(); };

        window.posCamPayAction = function () {
            posCamScanClose();
            setTimeout(function () { openPayModal(); }, 120);
        };
        window.posCamClearAction = function () {
            if (typeof cart !== 'undefined') { cart.splice(0); renderCart(); renderMenu(); _refreshCart(); }
        };
        window.posCamDiscountAction = function () {
            posCamScanClose();
            setTimeout(function () { openPayModal(); }, 120);
        };

        window.posCamFeedRm = function (btn) {
            var el = btn.closest('.pos-cam-feed-item');
            if (!el || el.classList.contains('is-removed')) return;
            var code = el.dataset.barcode;
            if (code && typeof menuList !== 'undefined') {
                var matched = menuList.find(function (m) { return m.barcode && m.barcode === code; });
                if (matched && typeof cart !== 'undefined') {
                    var idx = cart.findIndex(function (c) { return c.id === matched.id && c.type === matched.type; });
                    if (idx !== -1) { rm(idx); _refreshCart(); }
                }
            }
            el.classList.add('is-removed');
        };

        function _buildFeedInner(recognised, name, code, qty, unitPrice, lineTotal, netCartTotal, sym, itemDeals, pendingDeals) {
            if (!recognised) {
                return '<i class="fas fa-exclamation-circle fi-icon fi-icon--warn"></i>'
                    + '<div class="fi-body">'
                    +   '<span class="fi-name">' + _esc(code) + '</span>'
                    +   '<span class="fi-qty-label" style="color:#f87171;">Not registered — long-press a menu item to link</span>'
                    + '</div>';
            }
            var dealHtml = '';
            if (itemDeals && itemDeals.length) {
                dealHtml = '<div class="fi-deals">'
                    + itemDeals.map(function(dl) {
                        return '<span class="fi-deal-badge"><i class="fas fa-tags"></i> '
                            + _esc(dl.name) + ' &mdash; ' + _esc(dl.detail)
                            + ' <strong>−' + sym + ' ' + _fmt(dl.saving) + '</strong></span>';
                    }).join('')
                    + '</div>';
            }
            if (pendingDeals && pendingDeals.length) {
                dealHtml += '<div class="fi-deals">'
                    + pendingDeals.map(function(pd) {
                        return '<span class="fi-deal-badge fi-deal-pending"><i class="fas fa-hourglass-half"></i> '
                            + _esc(pd.hint) + '</span>';
                    }).join('')
                    + '</div>';
            }
            return '<i class="fas fa-check-circle fi-icon"></i>'
                + '<div class="fi-body">'
                +   '<span class="fi-name">' + _esc(name) + '</span>'
                +   '<span class="fi-qty-label">' + qty + ' × ' + sym + ' ' + _fmt(unitPrice) + '</span>'
                +   (netCartTotal !== null ? '<span class="fi-cart-total">Cart: ' + sym + ' ' + _fmt(netCartTotal) + '</span>' : '')
                +   dealHtml
                + '</div>'
                + (lineTotal !== null ? '<span class="fi-line-total">' + sym + ' ' + _fmt(lineTotal) + '</span>' : '')
                + '<button class="fi-rm" onclick="posCamFeedRm(this)" title="Remove from cart"><i class="fas fa-times"></i></button>';
        }

        function _addFeedItem(code) {
            var feed = document.getElementById('posCamFeed');
            if (!feed) return;

            var matched = (typeof menuList !== 'undefined')
                ? menuList.find(function (m) { return m.barcode && m.barcode === code; })
                : null;
            var cartItem = matched && (typeof cart !== 'undefined')
                ? cart.find(function (c) { return c.id === matched.id; })
                : null;

            var name      = matched ? matched.name : code;
            var unitPrice = matched ? (parseFloat(matched.price) || 0) : null;
            var qty       = cartItem ? (parseFloat(cartItem.qty) || 1) : 1;
            var lineTotal = unitPrice !== null ? unitPrice * qty : null;
            var sym       = (typeof currencySymbol !== 'undefined') ? currencySymbol : 'MWK';
            var recognised = matched !== null;

            // Evaluate deals so the card reflects the current savings
            if (typeof applyDeals === 'function') applyDeals();

            var grossTotal = null;
            if (typeof cart !== 'undefined' && cart.length) {
                grossTotal = cart.reduce(function (sum, ci) {
                    return sum + (parseFloat(ci.price) || 0) * (parseFloat(ci.qty) || 1);
                }, 0);
            }
            var netCartTotal = grossTotal !== null
                ? Math.max(0, grossTotal - (typeof _dealSavings !== 'undefined' ? _dealSavings : 0))
                : null;

            // Find deals that are currently active AND apply to this specific item
            var itemDeals = [];
            var pendingDeals = [];
            if (recognised && typeof posDeals !== 'undefined') {
                var fakeCartLine = { id: matched.id, type: matched.type || matched.menu_type, price: parseFloat(matched.price) || 0, qty: qty };
                var cartGross = grossTotal || 0;
                posDeals.forEach(function(deal) {
                    if (typeof _dealNowValid === 'function' && !_dealNowValid(deal)) return;
                    var dl = (typeof _dealLines !== 'undefined') ? _dealLines.find(function(d) { return d.id === String(deal.id); }) : null;

                    if (deal.deal_type === 'multi_buy') {
                        if (typeof _dealItemQualifies !== 'function' || !_dealItemQualifies(deal, fakeCartLine)) return;
                        if (dl) { itemDeals.push(dl); return; }
                        var groupSize = deal.multi_buy_qty || 2;
                        var totalQtyInCart = 0;
                        if (typeof cart !== 'undefined') {
                            cart.forEach(function(ci) {
                                if (_dealItemQualifies(deal, ci)) totalQtyInCart += Math.floor(Number(ci.qty) || 0);
                            });
                        }
                        var needed = groupSize - (totalQtyInCart % groupSize);
                        if (needed > 0 && needed < groupSize) {
                            pendingDeals.push({ hint: 'Add ' + needed + ' more to unlock ' + deal.name });
                        }

                    } else if (deal.deal_type === 'spend_save') {
                        if (dl) { itemDeals.push(dl); return; }
                        var threshold = deal.spend_threshold || 0;
                        if (threshold <= 0 || cartGross >= threshold) return;
                        var stillNeeded = threshold - cartGross;
                        if (stillNeeded > threshold * 0.5) return; // only near-miss
                        pendingDeals.push({ hint: 'Spend ' + sym + ' ' + _fmt(stillNeeded) + ' more to unlock ' + deal.name });

                    } else if (deal.deal_type === 'combo') {
                        if (dl) { itemDeals.push(dl); return; }
                        var groups = Array.isArray(deal.combo_requires) ? deal.combo_requires : [];
                        if (!groups.length) return;
                        var missingGroups = [];
                        var anyMet = false;
                        for (var gi = 0; gi < groups.length; gi++) {
                            var grp = groups[gi];
                            var types = Array.isArray(grp.item_types) ? grp.item_types : [];
                            var minQty = parseInt(grp.min_qty || 1, 10);
                            var grpQty = 0;
                            if (typeof cart !== 'undefined') {
                                cart.forEach(function(ci) { if (types.indexOf(String(ci.type)) >= 0) grpQty += Math.floor(Number(ci.qty) || 0); });
                            }
                            if (grpQty >= minQty) { anyMet = true; }
                            else { missingGroups.push(minQty + '× ' + types.join('/')); }
                        }
                        if (anyMet && missingGroups.length) {
                            pendingDeals.push({ hint: 'Also add ' + missingGroups.join(' + ') + ' to unlock ' + deal.name });
                        }

                    } else {
                        // percent_off, fixed_off, happy_hour — show if fired
                        if (dl && typeof _dealItemQualifies === 'function' && _dealItemQualifies(deal, fakeCartLine)) {
                            itemDeals.push(dl);
                        }
                    }
                });
            }

            // If a card for this barcode already exists, update it in-place (bump animation)
            var existing = feed.querySelector('[data-barcode="' + CSS.escape(code) + '"]');
            if (existing && !existing.classList.contains('is-removed')) {
                existing.innerHTML = _buildFeedInner(recognised, name, code, qty, unitPrice, lineTotal, netCartTotal, sym, itemDeals, pendingDeals);
                // Re-play the pop-in animation so the user sees the update
                existing.classList.remove('feed-bump');
                void existing.offsetWidth; // reflow
                existing.classList.add('feed-bump');
                // Move to bottom so the latest scan is always visible
                feed.appendChild(existing);
                feed.scrollTop = feed.scrollHeight;
                return;
            }

            // New barcode — create a fresh card
            var el = document.createElement('div');
            el.className = 'pos-cam-feed-item' + (recognised ? '' : ' is-unknown');
            el.dataset.barcode = code;
            el.innerHTML = _buildFeedInner(recognised, name, code, qty, unitPrice, lineTotal, netCartTotal, sym, itemDeals, pendingDeals);
            feed.appendChild(el);

            // Cap at 20 unique cards
            while (feed.children.length > 20) { feed.removeChild(feed.firstChild); }
            feed.scrollTop = feed.scrollHeight;
        }

        function _esc(str) {
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }
        function _fmt(n) {
            return Number(n).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        }

        function _setStatus(msg) {
            var el = document.getElementById('posCamStatus');
            if (el) el.textContent = msg;
        }
    })();
    </script>
</body>

</html>

