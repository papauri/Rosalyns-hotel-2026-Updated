<?php

/**
 * Room Service Dashboard
 *
 *  Centralised view + control panel for in-room food & beverage:
 *   - KPI cards (today's RS orders / revenue / avg delivery / items served)
 *   - Active queue (placed → fired → ready → delivered) — pulls from stock_orders
 *     where order_type='room_service'
 *   - Recent room-service charges from booking_charges (folio sync)
 *   - "Place Room Service Order" wizard:
 *        Pick a checked-in booking → pick menu items (filtered show_room_service=1)
 *        → for each line:
 *            (a) addBookingChargeFromMenu()  — adds folio charge + deducts stock
 *            (b) creates a stock_orders + stock_order_items entry (no double deduction)
 *            (c) fires to KDS / BDS / CDS by stamping fired_at + kitchen_status='new'
 *
 *  Permissions:
 *     room_service_view   — read
 *     room_service_manage — write (create orders, mark delivered, etc.)
 */
require_once 'admin-init.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/../includes/restaurant-location-locks.php';

/** @var PDO $pdo */

if (!hasPermission((int)$_SESSION['admin_user_id'], 'room_service_view')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];
/** @var array $user */
$canManage       = hasPermission((int)$user['id'], 'room_service_manage');
$currency_symbol = getSetting('currency_symbol') ?: 'MWK';
$site_name       = getSetting('site_name') ?: "Hotel";

/** Log an RS action to stock_order_audit (mirrors pos_logAudit in pos.php) */
function rs_logAudit(PDO $pdo, int $orderId, ?int $actorId, ?string $actorName, string $event, ?string $details = null): void
{
    try {
        $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$orderId, $actorId, $actorName, $event, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        error_log('rs_logAudit: ' . $e->getMessage());
    }
}

/** Recipe cost for a menu item (mirrors pos_calculateMenuItemRecipeCost in pos.php) */
function rs_calcCost(PDO $pdo, int $menuItemId, string $menuType, float $quantity): float
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

if (!ensureStockTablesExist()) {
    header('Location: dashboard.php?error=stock_tables_missing');
    exit;
}

$flash = ['msg' => '', 'err' => ''];

/* =====================================================================
 * GET: check_ready — returns room_service orders with at least one
 *      station fully ready (all items at that station in ready/collection
 *      status). Used by the RS dashboard notification poller.
 *      Auth is handled by admin-init.php. No CSRF needed (read-only GET).
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'check_ready') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmtRdy = $pdo->query("
            SELECT o.id            AS order_id,
                   o.reference,
                   o.table_number  AS table_label,
                   oi.station,
                   COUNT(oi.id)                                                              AS total_at_station,
                   SUM(CASE WHEN oi.kds_status IN ('ready','collection') THEN 1 ELSE 0 END) AS ready_at_station
            FROM   stock_orders      o
            JOIN   stock_order_items oi ON oi.order_id = o.id
            WHERE  o.order_type       = 'room_service'
              AND  o.status          NOT IN ('cancelled')
              AND  o.kitchen_status  NOT IN ('served','cancelled')
              AND  oi.kds_status     NOT IN ('served','void')
            GROUP  BY o.id, oi.station
            HAVING ready_at_station = total_at_station
               AND total_at_station  > 0
        ");
        $stnMap = ['kitchen' => 'Kitchen', 'bar' => 'Bar', 'coffee_bar' => 'Coffee Bar'];
        $ready  = [];
        foreach ($stmtRdy->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lbl    = $stnMap[$r['station']] ?? ucfirst((string)$r['station']);
            $room   = $r['table_label'] ?: $r['reference'];
            $ready[] = [
                'order_id'     => (int)$r['order_id'],
                'reference'    => $r['reference'],
                'table_label'  => $room,
                'station'      => $r['station'],
                'station_label' => $lbl,
                'key'          => $r['order_id'] . ':' . $r['station'],
                'message'      => $lbl . ': order ' . $r['reference'] . ' (' . htmlspecialchars($room, ENT_QUOTES, 'UTF-8') . ') is ready for collection.',
            ];
        }
        echo json_encode(['ok' => true, 'ready' => $ready]);
    } catch (Throwable $e) {
        error_log('RS check_ready: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Query failed']);
    }
    exit;
}

/* =====================================================================
 * Action handlers
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        $flash['err'] = 'You do not have permission to perform this action.';
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash['err'] = 'Security token invalid. Refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'place_rs_order') {
                $bookingId = (int)($_POST['booking_id'] ?? 0);
                $itemIds   = $_POST['item_id']   ?? [];
                $itemTypes = $_POST['item_type'] ?? [];
                $itemQtys  = $_POST['item_qty']  ?? [];
                $notes     = trim((string)($_POST['notes'] ?? ''));
                if ($bookingId <= 0) throw new RuntimeException('Pick a checked-in booking.');
                $count = is_array($itemIds) ? count($itemIds) : 0;
                if ($count === 0) throw new RuntimeException('Add at least one item.');

                // Verify booking is checked-in
                $bk = $pdo->prepare("SELECT b.id, b.booking_reference, b.guest_name, b.guest_email, b.guest_phone, b.status, b.individual_room_id, ir.room_number, r.name AS room_type_name
                                     FROM bookings b
                                     LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                                     LEFT JOIN rooms r ON r.id = b.room_id
                                     WHERE b.id = ?");
                $bk->execute([$bookingId]);
                $booking = $bk->fetch(PDO::FETCH_ASSOC);
                if (!$booking) throw new RuntimeException('Booking not found.');
                if ($booking['status'] !== 'checked-in') throw new RuntimeException('Only checked-in bookings can have room-service orders.');

                $tableLabel = $booking['room_number']
                    ? 'Rm ' . $booking['room_number']
                    : ('Booking #' . $booking['booking_reference']);

                $pdo->beginTransaction();
                // Guard: rh_restaurant_resolve_pos_location throws if room_number is empty.
                // If a booking is checked-in but not yet assigned to a physical room, fall
                // back to using the booking data directly so the order is still allowed.
                $roomNumForResolver = (string)($booking['room_number'] ?? '');
                if ($roomNumForResolver !== '') {
                    $resolvedRoom = rh_restaurant_resolve_pos_location($pdo, 'room_service', $roomNumForResolver);
                } else {
                    $resolvedRoom = [
                        'booking_id'        => $bookingId,
                        'individual_room_id' => !empty($booking['individual_room_id']) ? (int)$booking['individual_room_id'] : null,
                        'table_number'      => $tableLabel,
                        'room_number'       => null,
                        'label'             => $tableLabel,
                        'booking'           => $booking,
                    ];
                }
                $tableLabel = $resolvedRoom['label'] ?: $tableLabel;

                // Build stock_orders row first (so items can be linked)
                $reference = generateStockOrderReference();
                $pdo->prepare("INSERT INTO stock_orders (reference, order_type, booking_id, individual_room_id, table_number, room_number, customer_name, customer_email, customer_phone, notes, status, total_amount, created_by) VALUES (?, 'room_service', ?, ?, ?, ?, ?, ?, ?, ?, 'placed', 0, ?)")
                    ->execute([
                        $reference,
                        $resolvedRoom['booking_id'] ?: $bookingId,
                        $resolvedRoom['individual_room_id'] ?: (!empty($booking['individual_room_id']) ? (int)$booking['individual_room_id'] : null),
                        $resolvedRoom['table_number'] ?: $tableLabel,
                        $resolvedRoom['room_number'] ?: ($booking['room_number'] ?: null),
                        $booking['guest_name'],
                        $booking['guest_email'] ?? null,
                        $booking['guest_phone'] ?? null,
                        $notes ?: null,
                        $user['id']
                    ]);
                $orderId = (int)$pdo->lastInsertId();

                $itemIns = $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $totalAmount = 0.0;
                $totalCost   = 0.0;

                for ($k = 0; $k < $count; $k++) {
                    $itemId = (int)($itemIds[$k] ?? 0);
                    $type   = trim((string)($itemTypes[$k] ?? 'food'));
                    $qty    = (float)($itemQtys[$k] ?? 0);
                    if ($itemId <= 0 || $qty <= 0) continue;
                    if ($qty > 100) throw new RuntimeException('Quantity cap: 100 per line.');

                    // SERVER-SIDE lookup from unified menu_items table
                    $row = null;
                    $sel = $pdo->prepare("
                        SELECT mi.id, mi.item_name, mi.price,
                               COALESCE(mi.station, mc.default_station) AS station,
                               mc.slug AS menu_type
                        FROM menu_items mi
                        JOIN menu_categories mc ON mc.id = mi.category_id
                        WHERE mi.id = ? AND mi.is_available = 1 AND mi.show_room_service = 1
                    ");
                    $sel->execute([$itemId]);
                    $row = $sel->fetch(PDO::FETCH_ASSOC);
                    if (!$row) throw new RuntimeException('Menu item not found or not available.');

                    $line = round((float)$row['price'] * $qty, 2);
                    $menuType = $row['menu_type'];
                    $station = in_array($row['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true)
                        ? $row['station']
                        : 'kitchen';

                    // Add to KDS pipeline (stock deduction done by addBookingChargeFromMenu below)
                    $itemIns->execute([$orderId, $itemId, $menuType, $row['item_name'], $qty, (float)$row['price'], $line, $station]);
                    $itemRowId = (int)$pdo->lastInsertId();
                    $totalAmount += $line;
                    $totalCost   += rs_calcCost($pdo, $itemId, $menuType, $qty);

                    // Add to booking folio (deducts stock automatically)
                    $charge = addBookingChargeFromMenu($bookingId, $menuType, $itemId, $qty, (int)$user['id']);
                    if (empty($charge['success'])) {
                        throw new RuntimeException("Failed to add charge for {$row['item_name']}: " . ($charge['message'] ?? 'unknown error'));
                    }
                    if (!empty($charge['charge_id'])) {
                        $pdo->prepare("UPDATE booking_charges SET stock_order_id = ? WHERE id = ?")
                            ->execute([$orderId, (int)$charge['charge_id']]);
                        // Mark KDS item as stock-deducted so the ready_item handler does not deduct again
                        $pdo->prepare("UPDATE stock_order_items SET stock_deducted = 1 WHERE id = ?")
                            ->execute([$itemRowId]);
                    }
                }

                if ($totalAmount <= 0) throw new RuntimeException('Order total must be greater than zero.');
                $pdo->prepare("UPDATE stock_orders SET total_amount=?, subtotal=?, total_cost=?, folio_posted_at=NOW() WHERE id=?")
                    ->execute([$totalAmount, $totalAmount, $totalCost, $orderId]);

                // Fire to stations: stamp fired_at + kitchen_status='new', plus event row
                $pdo->prepare("UPDATE stock_orders SET kitchen_status='new', fired_at=COALESCE(fired_at,NOW()), kitchen_printed_at=COALESCE(kitchen_printed_at,NOW()) WHERE id=?")
                    ->execute([$orderId]);
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $pdo->prepare("INSERT INTO stock_kds_events (order_id, event, to_status, user_id, user_name, ip_address) VALUES (?, 'fired', 'new', ?, ?, ?)")
                    ->execute([$orderId, $user['id'], $user['full_name'], $ip]);

                if (function_exists('recalculateBookingFinancials')) {
                    recalculateBookingFinancials($bookingId);
                }

                $pdo->commit();

                rs_logAudit($pdo, $orderId, (int)$user['id'], $user['full_name'], 'rs_order_placed', json_encode([
                    'reference'  => $reference,
                    'booking_id' => $bookingId,
                    'room'       => $tableLabel,
                    'items'      => $count,
                    'total'      => $totalAmount,
                    'total_cost' => $totalCost,
                    'source'     => 'room-service-dashboard.php',
                ]));
                rh_log_event('room_service', 'info', "RS order {$reference} placed for {$tableLabel}", [
                    'order_id'   => $orderId,
                    'booking_id' => $bookingId,
                    'items'      => $count,
                    'total'      => $totalAmount,
                    'actor'      => $user['full_name'],
                ]);

                $flash['msg'] = 'Room service order ' . $reference . ' placed for ' . $tableLabel . ' — ' . $count . ' item(s), '
                    . $currency_symbol . ' ' . number_format($totalAmount, 2) . '. Charged to folio.';
            } elseif ($action === 'mark_delivered') {
                $orderId = (int)($_POST['order_id'] ?? 0);
                if ($orderId <= 0) throw new RuntimeException('Invalid order.');
                $pdo->beginTransaction();

                $orderStmt = $pdo->prepare("SELECT id, order_type, status, kitchen_status FROM stock_orders WHERE id = ? FOR UPDATE");
                $orderStmt->execute([$orderId]);
                $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
                if (!$order || ($order['order_type'] ?? '') !== 'room_service') {
                    throw new RuntimeException('Room service order not found.');
                }

                $currentKitchenStatus = (string)($order['kitchen_status'] ?? '');
                if (($order['status'] ?? '') === 'cancelled' || in_array($currentKitchenStatus, ['served', 'cancelled'], true)) {
                    throw new RuntimeException('Order is already finalized and cannot be marked delivered.');
                }

                $deliverableKitchenStatuses = ['placed', 'new', 'in_progress', 'recalled', 'ready', 'collection'];
                if (!in_array($currentKitchenStatus, $deliverableKitchenStatuses, true)) {
                    throw new RuntimeException('Order is not in a deliverable kitchen state.');
                }

                $pdo->prepare("UPDATE stock_order_items
                               SET kds_status='served',
                                   started_at=COALESCE(started_at,NOW()),
                                   ready_at=COALESCE(ready_at,NOW()),
                                   served_at=COALESCE(served_at,NOW()),
                                   bumped_by=COALESCE(bumped_by,?)
                               WHERE order_id=? AND kds_status<>'void'")
                    ->execute([(int)$user['id'], $orderId]);

                $orderUpdateStmt = $pdo->prepare("UPDATE stock_orders
                               SET kitchen_status='served',
                                   served_at=COALESCE(served_at,NOW()),
                                   status='completed'
                               WHERE id=?
                                 AND order_type='room_service'
                                 AND status<>'cancelled'
                                 AND kitchen_status NOT IN ('served', 'cancelled')");
                $orderUpdateStmt->execute([$orderId]);
                if ($orderUpdateStmt->rowCount() < 1) {
                    throw new RuntimeException('Unable to mark order as delivered from its current state.');
                }

                // Deduct stock for any items that bypassed the KDS ready_item path
                // (e.g. orders created via stock-orders.php where stock is deferred to KDS).
                $undeductedStmt = $pdo->prepare(
                    "SELECT menu_item_id, menu_type, quantity FROM stock_order_items
                     WHERE order_id = ? AND stock_deducted = 0 AND kds_status != 'void'"
                );
                $undeductedStmt->execute([$orderId]);
                $undeductedItems = $undeductedStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($undeductedItems as $ud) {
                    deductStockForMenuItem(
                        (int)$ud['menu_item_id'],
                        (string)$ud['menu_type'],
                        (float)$ud['quantity'],
                        'pos_order',
                        $orderId,
                        (int)$user['id']
                    );
                }
                if (!empty($undeductedItems)) {
                    $pdo->prepare("UPDATE stock_order_items SET stock_deducted = 1
                                   WHERE order_id = ? AND stock_deducted = 0 AND kds_status != 'void'")
                        ->execute([$orderId]);
                }

                $pdo->prepare("INSERT INTO stock_kds_events (order_id, event, to_status, user_id, user_name, ip_address) VALUES (?, 'delivered', 'served', ?, ?, ?)")
                    ->execute([$orderId, $user['id'], $user['full_name'], $_SERVER['REMOTE_ADDR'] ?? null]);
                $pdo->commit();
                rs_logAudit($pdo, $orderId, (int)$user['id'], $user['full_name'], 'rs_order_delivered', json_encode([
                    'source' => 'room-service-dashboard.php',
                ]));
                rh_log_event('room_service', 'info', "RS order #{$orderId} marked delivered", [
                    'order_id' => $orderId,
                    'actor'    => $user['full_name'],
                ]);
                $flash['msg'] = 'Order #' . $orderId . ' marked as delivered.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash['err'] = $e->getMessage();
        }
    }
}

/* =====================================================================
 * Read queries
 * ===================================================================== */
$today = date('Y-m-d');

$kpiSql = "SELECT
        COUNT(*) AS orders_today,
        COALESCE(SUM(total_amount),0) AS revenue_today,
        COALESCE(AVG(CASE WHEN fired_at IS NOT NULL AND served_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, fired_at, served_at) END),0) AS avg_seconds,
        SUM(CASE WHEN kitchen_status NOT IN ('served') AND status<>'cancelled' THEN 1 ELSE 0 END) AS in_progress
    FROM stock_orders
    WHERE order_type='room_service' AND DATE(created_at)=?";
$st = $pdo->prepare($kpiSql);
$st->execute([$today]);
$kpis = $st->fetch(PDO::FETCH_ASSOC) ?: ['orders_today' => 0, 'revenue_today' => 0, 'avg_seconds' => 0, 'in_progress' => 0];

$st = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items oi JOIN stock_orders o ON o.id=oi.order_id WHERE o.order_type='room_service' AND DATE(oi.served_at)=? AND oi.kds_status='served'");
$st->execute([$today]);
$itemsServedToday = (int)$st->fetchColumn();

$activeSql = "SELECT o.id, o.reference, o.table_number, o.customer_name, o.notes,
                     o.kitchen_status, o.created_at, o.fired_at, o.total_amount,
                     COUNT(oi.id) AS item_count,
                     SUM(CASE WHEN oi.kds_status='served' THEN 1 ELSE 0 END) AS items_served,
                     SUM(CASE WHEN oi.kds_status='ready'  THEN 1 ELSE 0 END) AS items_ready,
                     GROUP_CONCAT(DISTINCT CASE WHEN oi.kds_status NOT IN ('served','void') THEN oi.station END ORDER BY oi.station SEPARATOR ',') AS pending_stations
              FROM stock_orders o
              LEFT JOIN stock_order_items oi ON oi.order_id=o.id
              WHERE o.order_type='room_service'
                AND o.kitchen_status<>'served'
                AND o.status<>'cancelled'
              GROUP BY o.id
              ORDER BY o.created_at DESC
              LIMIT 50";
$activeOrders = $pdo->query($activeSql)->fetchAll(PDO::FETCH_ASSOC);

$chargesSql = "SELECT bc.id, bc.booking_id, bc.charge_type, bc.description, bc.quantity, bc.unit_price,
                      bc.line_total AS total_amount,
                      0 AS stock_tracked, bc.created_at, b.booking_reference, b.guest_name,
                      ir.room_number
               FROM booking_charges bc
               JOIN bookings b ON b.id=bc.booking_id
               LEFT JOIN individual_rooms ir ON ir.id=b.individual_room_id
               WHERE DATE(bc.created_at)=? AND bc.voided=0
                 AND bc.charge_type IN ('food','drink','room_service')
               ORDER BY bc.created_at DESC
               LIMIT 100";
$st = $pdo->prepare($chargesSql);
$st->execute([$today]);
$rsCharges = $st->fetchAll(PDO::FETCH_ASSOC);

$bkSt = $pdo->query("SELECT b.id, b.booking_reference, b.guest_name, b.status, ir.room_number, r.name AS room_type_name
                     FROM bookings b
                     LEFT JOIN individual_rooms ir ON ir.id=b.individual_room_id
                     LEFT JOIN rooms r ON r.id=b.room_id
                     WHERE b.status='checked-in'
                     ORDER BY ir.room_number, b.guest_name");
$checkedIn = $bkSt->fetchAll(PDO::FETCH_ASSOC);

$rsMenuItems = $pdo->query("
    SELECT mi.id, mi.item_name, mi.price,
           COALESCE(mi.category, 'Other') AS category,
           COALESCE(mi.station, mc.default_station) AS station,
           mc.slug AS menu_type, mc.name AS cat_name
    FROM menu_items mi
    JOIN menu_categories mc ON mc.id = mi.category_id
    WHERE mi.is_available = 1 AND mi.show_room_service = 1 AND mc.is_active = 1
    ORDER BY mc.sort_order ASC, mi.category ASC, mi.item_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Group by category slug for the JS menu payload (backward-compatible key structure)
$rsMenu = [];
foreach ($rsMenuItems as $item) {
    $slug = $item['menu_type'];
    if (!isset($rsMenu[$slug])) $rsMenu[$slug] = [];
    $rsMenu[$slug][] = $item;
}

function rs_fmt_dur(int $s): string
{
    if ($s <= 0) return '—';
    $m = intdiv($s, 60);
    $r = $s % 60;
    return $m > 0 ? sprintf('%dm %02ds', $m, $r) : sprintf('%ds', $r);
}

/** Render station pills for pending stations (e.g. "kitchen,bar") */
function rs_station_pills(string $pendingStations): string
{
    if ($pendingStations === '') return '<span style="color:#6c757d;font-size:11px;">—</span>';
    $map = [
        'kitchen'    => ['label' => 'Kitchen',  'color' => '#7c4a00', 'bg' => '#fff3e0'],
        'bar'        => ['label' => 'Bar',       'color' => '#1565c0', 'bg' => '#e3f2fd'],
        'coffee_bar' => ['label' => 'Coffee',    'color' => '#4a1942', 'bg' => '#f3e5f5'],
    ];
    $out = '';
    foreach (explode(',', $pendingStations) as $stn) {
        $stn = trim($stn);
        if ($stn === '') continue;
        $cfg = $map[$stn] ?? ['label' => ucfirst($stn), 'color' => '#555', 'bg' => '#eee'];
        $out .= sprintf(
            '<span style="display:inline-block;padding:2px 7px;border-radius:10px;background:%s;color:%s;font-size:10px;font-weight:700;margin:1px 2px;white-space:nowrap;">%s</span>',
            $cfg['bg'],
            $cfg['color'],
            htmlspecialchars($cfg['label'])
        );
    }
    return $out ?: '<span style="color:#6c757d;font-size:11px;">—</span>';
}

/** Human-friendly timestamp: "just now" / "5m ago" / "2:34 PM" / "May 6, 2:34 PM" */
function rs_fmt_time(?string $dt): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    if ($ts === false) return htmlspecialchars($dt);
    $diff = time() - $ts;
    if ($diff < 60)   return 'just now';
    if ($diff < 3600) return intdiv($diff, 60) . 'm ago';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('g:i A', $ts);
    return date('M j, g:i A', $ts);
}

$csrf_token = generateCsrfToken();
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Service Dashboard — <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/room-service-dashboard.css?v=<?php echo @filemtime(__DIR__ . '/css/room-service-dashboard.css'); ?>">
    <script src="js/station-sounds.js"></script>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content">
        <div class="page-header">
            <div>
                <h2 class="page-title"><i class="fas fa-bell-concierge" style="color:#0c8d6c;"></i> Room Service Dashboard</h2>
                <p style="color:#6c757d; margin:4px 0 0;">In-room dining, charged to folio &amp; routed to the right kitchen station automatically.</p>
            </div>
            <a href="menu-management.php?tab=food&amp;jump=room-service"
                class="btn-rs-edit-menu"
                title="Jump to Room Service items in Menu Management">
                <i class="fas fa-concierge-bell"></i> Edit Room Service Menu
            </a>
        </div>

        <?php if ($flash['msg']): ?><div class="alert success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($flash['msg']); ?></div><?php endif; ?>
        <?php if ($flash['err']): ?><div class="alert error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($flash['err']); ?></div><?php endif; ?>

        <!-- Station-ready notifications (populated by JS polling) -->
        <div id="rs-notif-strip" style="display:none; background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:none; align-items:flex-start; gap:12px;">
            <i class="fas fa-bell" style="color:#0c8d6c; font-size:18px; margin-top:2px; flex-shrink:0;"></i>
            <div id="rs-notif-list" style="flex:1; font-size:13px;"></div>
            <button type="button" onclick="rsDismissNotifs()" style="background:none; border:none; cursor:pointer; color:#6c757d; padding:0; flex-shrink:0;" title="Dismiss"><i class="fas fa-times"></i></button>
        </div>

        <div class="kpi-grid">
            <div class="kpi">
                <div class="lbl">Today's RS orders</div>
                <div class="val"><?php echo (int)$kpis['orders_today']; ?></div>
            </div>
            <div class="kpi green">
                <div class="lbl">Revenue today</div>
                <div class="val"><?php echo $currency_symbol . ' ' . number_format((float)$kpis['revenue_today'], 2); ?></div>
            </div>
            <div class="kpi">
                <div class="lbl">Items served</div>
                <div class="val"><?php echo $itemsServedToday; ?></div>
            </div>
            <div class="kpi">
                <div class="lbl">Avg delivery</div>
                <div class="val"><?php echo rs_fmt_dur((int)$kpis['avg_seconds']); ?></div>
            </div>
            <div class="kpi amber">
                <div class="lbl">In progress</div>
                <div class="val"><?php echo (int)$kpis['in_progress']; ?></div>
            </div>
        </div>

        <?php if ($canManage): ?>
            <div class="panel">
                <h3>
                    <i class="fas fa-plus-circle" style="color:#0c8d6c;"></i> Place a Room Service Order
                    <span class="menu-source-badge" title="Items below are flagged 'Show in Room Service' in Menu Management.">
                        <i class="fas fa-bed"></i> Room Service menu
                    </span>
                </h3>
                <form method="POST" id="rsOrderForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="place_rs_order">
                    <div class="grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:18px;">
                        <div>
                            <label style="font-size:12px; font-weight:600; color:#6c757d; text-transform:uppercase;">Checked-in booking</label>
                            <select name="booking_id" id="rsBooking" required style="width:100%; padding:10px; border:1px solid #d8dde3; border-radius:6px; margin:6px 0 14px;">
                                <option value="">— Select a guest / room —</option>
                                <?php foreach ($checkedIn as $b): ?>
                                    <option value="<?php echo (int)$b['id']; ?>">
                                        <?php echo htmlspecialchars(($b['room_number'] ? 'Rm ' . $b['room_number'] . ' · ' : '') . $b['guest_name'] . ' · ' . $b['booking_reference']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <?php
                            $rs_count = 0;
                            foreach ($rsMenu as $slug => $items) {
                                $rs_count += count(array_filter($items, static fn($i) => strtolower($i['category'] ?? '') === 'room service'));
                            }
                            $firstSlug = array_key_first($rsMenu) ?? 'food';
                            ?>
                            <div class="menu-tabs">
                                <?php $isFirst = true;
                                foreach ($rsMenu as $slug => $items): ?>
                                    <button type="button" <?php echo $isFirst ? 'class="active" ' : ''; ?>data-tab="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>" onclick="rsSwitchTab('<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>')">
                                        <?php echo htmlspecialchars(ucfirst($slug)); ?> (<?php echo count($items); ?>)
                                    </button>
                                <?php $isFirst = false;
                                endforeach; ?>
                                <button type="button" data-tab="room_service" onclick="rsSwitchTab('room_service')" style="color:#0c8d6c; border-left:1px solid #dee2e6; margin-left:auto;"><i class="fas fa-concierge-bell" style="margin-right:5px;"></i>Room Service (<?php echo $rs_count; ?>)</button>
                            </div>
                            <div class="menu-grid" id="rsMenuGrid"></div>
                        </div>

                        <div>
                            <label style="font-size:12px; font-weight:600; color:#6c757d; text-transform:uppercase;">Order</label>
                            <div class="rs-cart" id="rsCart">
                                <div class="empty"><i class="fas fa-utensils" style="font-size:24px; color:#d1d5db;"></i>
                                    <p style="margin:6px 0 0; font-size:13px;">Tap items on the left to add to the order.</p>
                                </div>
                            </div>
                            <div class="total-line"><span class="lbl">Total</span><span class="val" id="rsCartTotal"><?php echo $currency_symbol; ?> 0.00</span></div>

                            <label style="font-size:12px; font-weight:600; color:#6c757d; text-transform:uppercase;">Delivery notes (optional)</label>
                            <textarea name="notes" rows="2" placeholder="E.g. allergies, cutlery, knock first..." style="width:100%; padding:8px; border:1px solid #d8dde3; border-radius:6px; margin:6px 0 14px; font-size:13px;"></textarea>

                            <button type="submit" class="btn-fire" id="rsFireBtn" disabled><i class="fas fa-paper-plane"></i> Send to kitchen &amp; charge to folio</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h3><i class="fas fa-stopwatch" style="color:#856404;"></i> Active queue (<?php echo count($activeOrders); ?>)</h3>
            <?php if (!$activeOrders): ?>
                <div class="empty"><i class="fas fa-coffee" style="font-size:32px; color:#d1d5db;"></i>
                    <p>No room service orders in progress.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="rs-table">
                        <thead>
                            <tr>
                                <th>Ref</th>
                                <th>Room / Guest</th>
                                <th>Items</th>
                                <th>Stations</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Placed</th>
                                <th>Fired</th>
                                <th>Wait</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeOrders as $o):
                                $waitSec = $o['fired_at'] ? max(0, time() - strtotime($o['fired_at'])) : (time() - strtotime($o['created_at']));
                                $fireDelaySec = $o['fired_at'] ? max(0, strtotime($o['fired_at']) - strtotime($o['created_at'])) : 0;
                            ?>
                                <tr>
                                    <td><a href="order-lifecycle.php?id=<?php echo (int)$o['id']; ?>" target="_blank" style="color:#8B7355; font-weight:600;"><?php echo htmlspecialchars($o['reference']); ?></a></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($o['table_number'] ?: '—'); ?></strong>
                                        <?php if ($o['customer_name']): ?><br><small style="color:#6c757d;"><?php echo htmlspecialchars($o['customer_name']); ?></small><?php endif; ?>
                                    </td>
                                    <td><?php echo (int)$o['item_count']; ?> <small style="color:#6c757d;">· R:<?php echo (int)$o['items_ready']; ?> S:<?php echo (int)$o['items_served']; ?></small></td>
                                    <td><?php echo rs_station_pills((string)($o['pending_stations'] ?? '')); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format((float)$o['total_amount'], 2); ?></td>
                                    <td><span class="ks-pill <?php echo htmlspecialchars($o['kitchen_status']); ?>"><?php echo htmlspecialchars($o['kitchen_status']); ?></span></td>
                                    <td><small title="<?php echo htmlspecialchars($o['created_at']); ?>"><?php echo rs_fmt_time($o['created_at']); ?></small></td>
                                    <td><small title="<?php echo $o['fired_at'] ? htmlspecialchars($o['fired_at']) : 'Not yet fired'; ?>">
                                            <?php if ($o['fired_at']): ?>
                                                <?php echo date('g:i A', strtotime($o['fired_at'])); ?>
                                                <?php if ($fireDelaySec > 0): ?>
                                                    <span style="color:<?php echo $fireDelaySec > 300 ? '#dc3545' : '#856404'; ?>; font-size:10px;"> (+<?php echo rs_fmt_dur($fireDelaySec); ?>)</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:#6c757d;">Not fired</span>
                                            <?php endif; ?>
                                        </small></td>
                                    <td style="font-variant-numeric:tabular-nums;"><?php echo rs_fmt_dur($waitSec); ?></td>
                                    <td>
                                        <?php if ($canManage): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Mark order <?php echo htmlspecialchars($o['reference']); ?> as delivered?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="mark_delivered">
                                                <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                                                <button type="submit" style="padding:5px 10px; background:#0c8d6c; color:white; border:none; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer;"><i class="fas fa-check"></i> Delivered</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h3><i class="fas fa-receipt" style="color:#5e35b1;"></i> Today's folio charges (<?php echo count($rsCharges); ?>)</h3>
            <?php if (!$rsCharges): ?>
                <div class="empty">
                    <p>No food/drink charges posted to bookings today.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="rs-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Booking</th>
                                <th>Room</th>
                                <th>Guest</th>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Total</th>
                                <th>Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rsCharges as $c): ?>
                                <tr>
                                    <td><small><?php echo date('g:i A', strtotime($c['created_at'])); ?></small></td>
                                    <td><a href="booking-details.php?id=<?php echo (int)$c['booking_id']; ?>" target="_blank" style="color:#8B7355;"><?php echo htmlspecialchars($c['booking_reference']); ?></a></td>
                                    <td><?php echo $c['room_number'] ? 'Rm ' . htmlspecialchars($c['room_number']) : '—'; ?></td>
                                    <td><?php echo htmlspecialchars($c['guest_name']); ?></td>
                                    <td><?php echo htmlspecialchars($c['description']); ?></td>
                                    <td><?php echo rtrim(rtrim(number_format((float)$c['quantity'], 2), '0'), '.'); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format((float)$c['total_amount'], 2); ?></td>
                                    <td>
                                        <?php if ($c['stock_tracked']): ?>
                                            <span style="color:#0c8d6c;" title="Ingredients deducted from stock"><i class="fas fa-check-circle"></i></span>
                                        <?php else: ?>
                                            <span style="color:#856404;" title="No recipe linked / stock not deducted"><i class="fas fa-exclamation-circle"></i></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const rsMenu = <?php echo json_encode($rsMenu, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const currency = <?php echo json_encode($currency_symbol); ?>;
        let rsCart = [];
        <?php /** @var string $firstSlug */ ?>
        let rsTab = <?php echo json_encode($firstSlug); ?>;

        function rsEsc(s) {
            return String(s || '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        function rsFmt(n) {
            return currency + ' ' + Number(n || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function rsGetItems() {
            if (rsTab === 'room_service') {
                const fRS = (rsMenu['food'] || []).filter(it => it.category === 'Room Service').map(it => ({
                    ...it,
                    _type: 'food'
                }));
                const dRS = (rsMenu['drink'] || []).filter(it => it.category === 'Room Service').map(it => ({
                    ...it,
                    _type: 'drink'
                }));
                return [...fRS, ...dRS];
            }
            return (rsMenu[rsTab] || []).map(it => ({
                ...it,
                _type: rsTab
            }));
        }

        function rsRenderMenu() {
            const grid = document.getElementById('rsMenuGrid');
            if (!grid) return;
            const items = rsGetItems();
            if (!items.length) {
                grid.innerHTML = '<div class="empty" style="grid-column:1/-1;">No items enabled for room service in this tab.</div>';
                return;
            }
            grid.innerHTML = items.map(it => {
                const stnLabel = ({
                    kitchen: 'Kit',
                    bar: 'Bar',
                    coffee_bar: 'Cof'
                })[it.station] || '';
                return '<button type="button" class="menu-tile" data-item-id="' + it.id + '" data-item-type="' + rsEsc(it._type) + '" data-item-name="' + rsEsc(String(it.item_name)) + '" data-item-price="' + Number(it.price) + '">' +
                    '<div class="nm">' + rsEsc(it.item_name) + '</div>' +
                    '<div style="display:flex; justify-content:space-between; align-items:center;">' +
                    '<span class="pr">' + rsFmt(it.price) + '</span>' +
                    '<span class="stn-pill ' + rsEsc(it.station || '') + '">' + stnLabel + '</span>' +
                    '</div></button>';
            }).join('');
        }
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.menu-tile');
            if (!btn) return;
            rsAddItem(Number(btn.dataset.itemId), btn.dataset.itemType, btn.dataset.itemName, Number(btn.dataset.itemPrice));
        });

        function rsSwitchTab(tab) {
            rsTab = tab;
            document.querySelectorAll('.menu-tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
            rsRenderMenu();
        }

        function rsAddItem(id, type, name, price) {
            const existing = rsCart.find(c => c.id === id && c.type === type);
            if (existing) existing.qty += 1;
            else rsCart.push({
                id,
                type,
                name,
                price,
                qty: 1
            });
            rsRenderCart();
        }

        function rsRemove(idx) {
            rsCart.splice(idx, 1);
            rsRenderCart();
        }

        function rsUpdateQty(idx, val) {
            const q = Math.max(0.1, Math.min(100, parseFloat(val) || 1));
            rsCart[idx].qty = q;
            rsRenderCart();
        }

        function rsCartStations() {
            let hasFood = false,
                hasDrink = false;
            rsCart.forEach(c => {
                if (c.type === 'food') hasFood = true;
                else hasDrink = true;
            });
            return {
                hasFood,
                hasDrink
            };
        }

        function rsUpdateFireBtn() {
            const btn = document.getElementById('rsFireBtn');
            if (!btn) return;
            const {
                hasFood,
                hasDrink
            } = rsCartStations();
            let label = 'Send to Kitchen & charge to folio';
            let icon = 'fa-paper-plane';
            if (hasFood && hasDrink) {
                label = 'Fire to Stations & charge to folio';
                icon = 'fa-fire';
            } else if (hasDrink) {
                label = 'Send to Bar & charge to folio';
                icon = 'fa-glass-martini-alt';
            }
            btn.innerHTML = '<i class="fas ' + icon + '"></i> ' + label;
        }

        function rsRenderCart() {
            const cart = document.getElementById('rsCart');
            const totalEl = document.getElementById('rsCartTotal');
            const fireBtn = document.getElementById('rsFireBtn');
            if (!cart) return;
            if (!rsCart.length) {
                cart.innerHTML = '<div class="empty"><i class="fas fa-utensils" style="font-size:24px; color:#d1d5db;"></i><p style="margin:6px 0 0; font-size:13px;">Tap items on the left to add to the order.</p></div>';
                totalEl.textContent = currency + ' 0.00';
                if (fireBtn) {
                    fireBtn.disabled = true;
                    fireBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send to Kitchen & charge to folio';
                }
                return;
            }
            let total = 0;
            cart.innerHTML = rsCart.map((c, i) => {
                const ln = c.price * c.qty;
                total += ln;
                return '<div class="rs-cart-row">' +
                    '<div class="name">' + rsEsc(c.name) + ' <small style="color:#6c757d;">(' + c.type + ')</small>' +
                    '<input type="hidden" name="item_id[]"   value="' + c.id + '">' +
                    '<input type="hidden" name="item_type[]" value="' + c.type + '">' +
                    '</div>' +
                    '<div class="qty"><input type="number" min="1" max="99" step="1" name="item_qty[]" value="' + c.qty + '" onchange="rsUpdateQty(' + i + ', this.value)"></div>' +
                    '<div class="ln">' + rsFmt(ln) + '</div>' +
                    '<button type="button" onclick="rsRemove(' + i + ')" style="background:none; border:none; color:#dc3545; cursor:pointer;"><i class="fas fa-times"></i></button>' +
                    '</div>';
            }).join('');
            totalEl.textContent = rsFmt(total);
            if (fireBtn) fireBtn.disabled = false;
            rsUpdateFireBtn();
        }
        // Works for both direct load (DOMContentLoaded not yet fired) and SPA
        // navigation (DOMContentLoaded already fired, readyState is 'complete').
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', rsRenderMenu);
        } else {
            rsRenderMenu();
        }

        /* ── RHPoll helper ─────────────────────────────────────────────────── */
        const RHPoll = (() => {
            const timers = new Map();
            return {
                every(fn, ms) {
                    if (timers.has(fn)) return;
                    const id = setInterval(() => {
                        try {
                            fn();
                        } catch (e) {}
                    }, ms);
                    timers.set(fn, id);
                }
            };
        })();

        /* ── Station-ready notification polling ────────────────────────────── */
        const RS_USER_ID = <?php echo json_encode((int)$user['id']); ?>;
        const RS_SEEN_KEY = 'rh_rs_ready_seen_v1_u' + RS_USER_ID;

        const _rsSeenNotifs = new Set(
            (JSON.parse(localStorage.getItem(RS_SEEN_KEY) || '[]') || []).map(String)
        );

        function _rsSaveSeen() {
            localStorage.setItem(RS_SEEN_KEY, JSON.stringify(Array.from(_rsSeenNotifs).slice(-300)));
        }

        let _rsNotifFlight = false;
        async function rsPollReadyNotifications() {
            if (_rsNotifFlight) return;
            _rsNotifFlight = true;
            try {
                const r = await fetch('room-service-dashboard.php?action=check_ready', {
                    credentials: 'same-origin'
                });
                if (!r.ok) return;
                const j = await r.json();
                if (!j.ok || !j.ready || !j.ready.length) return;

                const strip = document.getElementById('rs-notif-strip');
                const list = document.getElementById('rs-notif-list');
                let hasNew = false;

                j.ready.forEach(n => {
                    const key = n.key || (n.order_id + ':' + n.station);
                    if (_rsSeenNotifs.has(key)) return;
                    _rsSeenNotifs.add(key);
                    _rsSaveSeen();
                    hasNew = true;

                    const stnLabel = n.station_label || (n.station || '').replace('_', ' ');
                    if (list) {
                        const el = document.createElement('div');
                        el.style.cssText = 'padding:3px 0; color:#0c8d6c;';
                        el.innerHTML = '<i class="fas fa-check-circle"></i> ' + n.message;
                        list.appendChild(el);
                    }
                    if (typeof RHNotif !== 'undefined') {
                        RHNotif.show({
                            title: stnLabel + ' ready',
                            body: n.message || '',
                            type: 'success',
                            source: 'Room Service',
                            duration: 8000
                        });
                    }
                    if (typeof RHSounds !== 'undefined') {
                        RHSounds.play('normal');
                    }
                });

                if (hasNew && strip) strip.style.display = 'flex';
            } catch (e) {
                /* swallow network blips */ } finally {
                _rsNotifFlight = false;
            }
        }

        function rsDismissNotifs() {
            const strip = document.getElementById('rs-notif-strip');
            const list = document.getElementById('rs-notif-list');
            if (strip) strip.style.display = 'none';
            if (list) list.innerHTML = '';
        }

        // Init sounds on first user interaction (required by browser autoplay policy)
        document.addEventListener('click', function _rsInitSounds() {
            if (typeof RHSounds !== 'undefined') RHSounds.init();
            document.removeEventListener('click', _rsInitSounds);
        }, {
            once: true
        });

        // Poll every 12 seconds — same cadence as POS
        RHPoll.every(rsPollReadyNotifications, 12000);
    </script>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

