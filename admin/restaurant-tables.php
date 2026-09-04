<?php

/**
 * Restaurant table settings for POS table locking.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once __DIR__ . '/../includes/finance-sequences.php';
require_once __DIR__ . '/../includes/restaurant-location-locks.php';
require_once __DIR__ . '/includes/restaurant-payment-sync.php';
require_once __DIR__ . '/includes/restaurant-order-serve.php';

/** @var PDO $pdo */
/** @var array $user */

if (!hasPermission((int)$user['id'], 'stock_management')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$canSettleTableOrders = hasPermission((int)$user['id'], 'restaurant_table_settle');
$currency_symbol = (string)getSetting('currency_symbol', 'MWK');
finance_ensure_sequence_tables($pdo);

$message = '';
$error = '';

function rt_calculate_restaurant_vat_parts(float $grossAmount): array
{
    $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0.0;
    if ($grossAmount <= 0 || $vatRate <= 0) {
        return [
            'net' => round($grossAmount, 2),
            'vat_rate' => 0.0,
            'vat' => 0.0,
            'gross' => round($grossAmount, 2),
        ];
    }

    $net = round($grossAmount / (1 + ($vatRate / 100)), 2);
    $vat = round($grossAmount - $net, 2);

    return [
        'net' => $net,
        'vat_rate' => $vatRate,
        'vat' => $vat,
        'gross' => round($grossAmount, 2),
    ];
}

function rt_map_payment_method(string $method): string
{
    return match ($method) {
        'cash' => 'cash',
        'mobile_money' => 'mobile_money',
        'card_manual', 'card_pos' => 'credit_card',
        default => 'other',
    };
}

function rt_sync_payment(PDO $pdo, array $order, int $recordedBy, string $paymentMethod): int
{
    $orderId = (int)($order['id'] ?? 0);
    $reference = (string)($order['reference'] ?? '');
    $vat = rt_calculate_restaurant_vat_parts((float)($order['total_amount'] ?? 0));
    $mappedMethod = rt_map_payment_method($paymentMethod);

    return rh_sync_restaurant_payment(
        $pdo,
        $orderId,
        $reference,
        !empty($order['customer_name']) ? (string)$order['customer_name'] : null,
        $vat,
        $recordedBy,
        $mappedMethod
    );
}

function rt_log_order_audit(PDO $pdo, int $orderId, ?int $actorId, ?string $actorName, string $event, array $details): void
{
    try {
        $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([
                $orderId,
                $actorId,
                $actorName,
                $event,
                json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (Throwable $e) {
        error_log('rt_log_order_audit: ' . $e->getMessage());
    }
}

function rt_apply_payment_to_order(PDO $pdo, array $user, array $order, string $paymentMethod, array $post): array
{
    $orderId = (int)($order['id'] ?? 0);
    $reference = (string)($order['reference'] ?? '');
    $totalAmount = (float)($order['total_amount'] ?? 0);
    $tendered = (float)($post['tendered_amount'] ?? 0);
    $mobileProvider = trim((string)($post['mobile_wallet_provider'] ?? ''));
    $mobileReference = trim((string)($post['mobile_wallet_reference'] ?? ''));
    $cardLast4Raw = preg_replace('/\D/', '', (string)($post['card_last4'] ?? ''));
    $cardLast4 = strlen($cardLast4Raw) >= 4 ? substr($cardLast4Raw, -4) : null;
    $cardAuthCode = trim((string)($post['card_auth_code'] ?? ''));

    $extras = ['tendered' => null, 'change' => null, 'mp' => null, 'mr' => null, 'l4' => null, 'auth' => null];
    if ($paymentMethod === 'cash') {
        if ($tendered < $totalAmount - BALANCE_TOLERANCE) {
            throw new RuntimeException('Tendered ' . number_format($tendered, 2) . ' < total ' . number_format($totalAmount, 2));
        }
        $extras['tendered'] = round($tendered, 2);
        $extras['change'] = round($tendered - $totalAmount, 2);
    } elseif ($paymentMethod === 'mobile_money') {
        if ($mobileProvider === '' || $mobileReference === '') {
            throw new RuntimeException('Mobile money requires provider + transaction reference.');
        }
        $extras['mp'] = mb_substr($mobileProvider, 0, 50);
        $extras['mr'] = mb_substr($mobileReference, 0, 100);
    } elseif ($paymentMethod === 'card_manual') {
        if (!$cardLast4 || $cardAuthCode === '') {
            throw new RuntimeException('Card requires last 4 digits + authorisation code.');
        }
        $extras['l4'] = $cardLast4;
        $extras['auth'] = mb_substr($cardAuthCode, 0, 50);
    }

    $pdo->prepare("UPDATE stock_orders SET status='paid', paid_at=NOW(), payment_method=?, tendered_amount=?, change_due=?, mobile_wallet_provider=?, mobile_wallet_reference=?, card_last4=?, card_auth_code=? WHERE id=?")
        ->execute([
            $paymentMethod,
            $extras['tendered'],
            $extras['change'],
            $extras['mp'],
            $extras['mr'],
            $extras['l4'],
            $extras['auth'],
            $orderId,
        ]);

    $extras['payment_id'] = rt_sync_payment($pdo, $order, (int)($user['id'] ?? 0), $paymentMethod);

    return $extras;
}

function rt_state_from_live_counts(array $counts, string $kitchenStatus, string $orderStatus): array
{
    $pending = (int)($counts['pending'] ?? 0);
    $preparing = (int)($counts['preparing'] ?? 0) + (int)($counts['in_progress'] ?? 0);
    $ready = (int)($counts['ready'] ?? 0);
    $collection = (int)($counts['collection'] ?? 0);
    $served = (int)($counts['served'] ?? 0);
    $orderStatusNorm = strtolower(trim($orderStatus));

    if ($preparing > 0) {
        return ['key' => 'preparing', 'label' => 'Preparing', 'icon' => 'fa-fire'];
    }
    if ($collection > 0) {
        return ['key' => 'collection', 'label' => 'Collecting', 'icon' => 'fa-hand-holding'];
    }
    if ($ready > 0 && $preparing === 0) {
        return ['key' => 'ready', 'label' => 'Ready', 'icon' => 'fa-bell'];
    }
    if ($pending > 0 && $preparing === 0 && $ready === 0 && $collection === 0) {
        return ['key' => 'pending', 'label' => 'Pending', 'icon' => 'fa-clock'];
    }
    if ($served > 0 && $pending === 0 && $preparing === 0 && $ready === 0 && $collection === 0) {
        if ($orderStatusNorm === 'placed') {
            return ['key' => 'awaiting-payment', 'label' => 'Awaiting Payment', 'icon' => 'fa-receipt'];
        }
        return ['key' => 'served', 'label' => 'Served', 'icon' => 'fa-check-double'];
    }

    $kitchen = strtolower(trim($kitchenStatus));
    if ($kitchen === 'in_progress' || $kitchen === 'preparing') {
        return ['key' => 'preparing', 'label' => 'Preparing', 'icon' => 'fa-fire'];
    }
    if ($kitchen === 'collection') {
        return ['key' => 'collection', 'label' => 'Collecting', 'icon' => 'fa-hand-holding'];
    }
    if ($kitchen === 'ready') {
        return ['key' => 'ready', 'label' => 'Ready', 'icon' => 'fa-bell'];
    }
    if ($kitchen === 'served' && $orderStatusNorm === 'placed') {
        return ['key' => 'awaiting-payment', 'label' => 'Awaiting Payment', 'icon' => 'fa-receipt'];
    }
    if ($kitchen === 'served') {
        return ['key' => 'served', 'label' => 'Served', 'icon' => 'fa-check-double'];
    }
    if ($kitchen === 'recalled') {
        return ['key' => 'recalled', 'label' => 'Recalled', 'icon' => 'fa-rotate-left'];
    }
    if ($kitchen === 'new' || $kitchen === 'none' || $kitchen === 'pending') {
        return ['key' => 'pending', 'label' => 'Pending', 'icon' => 'fa-clock'];
    }

    if (strtolower(trim($orderStatus)) === 'placed') {
        return ['key' => 'pending', 'label' => 'Pending', 'icon' => 'fa-clock'];
    }

    return ['key' => 'busy', 'label' => 'Active', 'icon' => 'fa-utensils'];
}

function rt_format_live_line_summary(array $counts): string
{
    $labels = [
        'pending' => 'pending',
        'preparing' => 'preparing',
        'in_progress' => 'in progress',
        'ready' => 'ready',
        'collection' => 'collecting',
        'served' => 'served',
    ];

    $parts = [];
    foreach (['pending', 'preparing', 'in_progress', 'ready', 'collection', 'served'] as $key) {
        $count = (int)($counts[$key] ?? 0);
        if ($count > 0) {
            $parts[] = $count . ' ' . $labels[$key];
        }
    }

    return $parts ? implode(' | ', $parts) : 'Order is active';
}

function rt_format_order_quantity(float $quantity): string
{
    if (abs($quantity - round($quantity)) < 0.00001) {
        return (string)(int)round($quantity);
    }

    return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
}

function rt_station_label(string $station): string
{
    return match (strtolower(trim($station))) {
        'kitchen' => 'Kitchen',
        'bar' => 'Bar',
        'coffee_bar' => 'Coffee Bar',
        default => ucwords(str_replace('_', ' ', trim($station))),
    };
}

function rt_station_sort_rank(string $station): int
{
    return match (strtolower(trim($station))) {
        'kitchen' => 1,
        'bar' => 2,
        'coffee_bar' => 3,
        default => 99,
    };
}

function rt_station_state_from_counts(array $counts): string
{
    $pending = (float)($counts['pending'] ?? 0);
    $preparing = (float)($counts['preparing'] ?? 0) + (float)($counts['in_progress'] ?? 0);
    $ready = (float)($counts['ready'] ?? 0);
    $collection = (float)($counts['collection'] ?? 0);
    $served = (float)($counts['served'] ?? 0);

    if ($preparing > 0) {
        return 'preparing';
    }
    if ($collection > 0) {
        return 'collection';
    }
    if ($ready > 0) {
        return 'ready';
    }
    if ($pending > 0) {
        return 'pending';
    }
    if ($served > 0) {
        return 'served';
    }

    return 'busy';
}

function rt_station_summary_text(array $counts): string
{
    $parts = [];
    $labels = [
        'pending' => 'pending',
        'preparing' => 'prep',
        'in_progress' => 'prep',
        'ready' => 'ready',
        'collection' => 'collecting',
        'served' => 'served',
    ];

    foreach (['pending', 'preparing', 'in_progress', 'ready', 'collection', 'served'] as $statusKey) {
        $count = (float)($counts[$statusKey] ?? 0);
        if ($count <= 0) {
            continue;
        }

        $parts[] = rt_format_order_quantity($count) . ' ' . $labels[$statusKey];
    }

    return $parts ? implode(' | ', $parts) : 'No station movement yet';
}

function rt_build_table_live_statuses(PDO $pdo): array
{
    $locks = rh_restaurant_active_location_locks($pdo);
    $tableLocks = $locks['tables'] ?? [];
    if (!$tableLocks) {
        return [];
    }

    $orderIds = [];
    foreach ($tableLocks as $lock) {
        if (!empty($lock['id'])) {
            $orderIds[] = (int)$lock['id'];
        }
    }
    $orderIds = array_values(array_unique(array_filter($orderIds)));

    $countsByOrder = [];
    if ($orderIds) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $pdo->prepare("SELECT order_id, kds_status, COUNT(*) AS line_count FROM stock_order_items WHERE order_id IN ($placeholders) GROUP BY order_id, kds_status");
        $stmt->execute($orderIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            $kdsStatus = (string)($row['kds_status'] ?? 'pending');
            $countsByOrder[$orderId][$kdsStatus] = (int)($row['line_count'] ?? 0);
        }
    }

    $statuses = [];
    foreach ($tableLocks as $tableNumber => $lock) {
        $orderId = (int)($lock['id'] ?? 0);
        $counts = $countsByOrder[$orderId] ?? [];
        $state = rt_state_from_live_counts($counts, (string)($lock['kitchen_status'] ?? ''), (string)($lock['status'] ?? ''));

        $openedAt = '';
        if (!empty($lock['created_at'])) {
            $ts = strtotime((string)$lock['created_at']);
            if ($ts !== false) {
                $openedAt = date('H:i', $ts);
            }
        }

        $statuses[(string)$tableNumber] = [
            'table_number' => (string)$tableNumber,
            'order_id' => $orderId,
            'reference' => (string)($lock['reference'] ?? ''),
            'state' => $state['key'],
            'label' => $state['label'],
            'icon' => $state['icon'],
            'summary' => rt_format_live_line_summary($counts),
            'opened_at' => $openedAt,
        ];
    }

    return $statuses;
}

function rt_render_live_status_html(?array $status, bool $isActive): string
{
    if (!$isActive) {
        return '<span class="rt-live-pill rt-live-pill--inactive"><i class="fas fa-ban"></i> Inactive</span>';
    }

    if (!$status) {
        return '<span class="rt-live-pill rt-live-pill--available"><i class="fas fa-circle-check"></i> Available</span>';
    }

    $stateKey = strtolower((string)($status['state'] ?? 'busy'));
    $stateKey = preg_replace('/[^a-z0-9-]/', '-', $stateKey) ?: 'busy';
    $icon = htmlspecialchars((string)($status['icon'] ?? 'fa-utensils'));
    $label = htmlspecialchars((string)($status['label'] ?? 'Active'));

    $metaParts = [];
    $reference = trim((string)($status['reference'] ?? ''));
    if ($reference !== '') {
        $metaParts[] = $reference;
    }
    $openedAt = trim((string)($status['opened_at'] ?? ''));
    if ($openedAt !== '') {
        $metaParts[] = 'Opened ' . $openedAt;
    }
    $metaText = htmlspecialchars($metaParts ? implode(' | ', $metaParts) : 'Active order');
    $summaryText = htmlspecialchars((string)($status['summary'] ?? 'Order is active'));

    return '<div class="rt-live-box">'
        . '<span class="rt-live-pill rt-live-pill--' . $stateKey . '"><i class="fas ' . $icon . '"></i> ' . $label . '</span>'
        . '<span class="rt-live-meta">' . $metaText . '</span>'
        . '<span class="rt-live-sub">' . $summaryText . '</span>'
        . '</div>';
}

function rt_build_table_active_orders(PDO $pdo): array
{
    $locks = rh_restaurant_active_location_locks($pdo);
    $tableLocks = $locks['tables'] ?? [];
    if (!$tableLocks) {
        return [];
    }

    $orderIds = [];
    $tableNumbersByOrderId = [];
    foreach ($tableLocks as $tableNumber => $lock) {
        $orderId = (int)($lock['id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }

        $orderIds[] = $orderId;
        $tableNumbersByOrderId[$orderId] = (string)$tableNumber;
    }

    $orderIds = array_values(array_unique($orderIds));
    if (!$orderIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $orderStmt = $pdo->prepare("SELECT o.id, o.reference, o.total_amount, o.created_at, o.customer_name, o.payment_method,
            u.full_name AS opened_by_name
        FROM stock_orders o
        LEFT JOIN admin_users u ON u.id = o.created_by
        WHERE o.id IN ($placeholders)");
    $orderStmt->execute($orderIds);

    $ordersById = [];
    foreach ($orderStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ordersById[(int)($row['id'] ?? 0)] = $row;
    }

    $itemStmt = $pdo->prepare("SELECT order_id, item_name, quantity, station, kds_status
        FROM stock_order_items
        WHERE order_id IN ($placeholders)
        ORDER BY order_id ASC, id ASC");
    $itemStmt->execute($orderIds);

    $itemsByOrder = [];
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $itemsByOrder[(int)($row['order_id'] ?? 0)][] = $row;
    }

    $activeOrders = [];
    foreach ($tableNumbersByOrderId as $orderId => $tableNumber) {
        if (!isset($ordersById[$orderId])) {
            continue;
        }

        $order = $ordersById[$orderId];
        $openedAt = '';
        if (!empty($order['created_at'])) {
            $ts = strtotime((string)$order['created_at']);
            if ($ts !== false) {
                $openedAt = date('H:i', $ts);
            }
        }

        $preview = [];
        $servedPreview = [];
        $quantityTotal = 0.0;
        $lineCount = 0;
        $servedLineCount = 0;
        $stationCounts = [];
        foreach ($itemsByOrder[$orderId] ?? [] as $item) {
            $lineCount++;
            $quantity = (float)($item['quantity'] ?? 0);
            $quantityTotal += $quantity;

            $statusKey = strtolower(trim((string)($item['kds_status'] ?? 'pending')));
            if (!in_array($statusKey, ['pending', 'preparing', 'in_progress', 'ready', 'collection', 'served'], true)) {
                $statusKey = 'pending';
            }

            $stationKey = trim((string)($item['station'] ?? ''));
            if ($stationKey !== '') {
                $stationCounts[$stationKey][$statusKey] = (float)($stationCounts[$stationKey][$statusKey] ?? 0) + $quantity;
            }

            $itemName = trim((string)($item['item_name'] ?? ''));
            if ($itemName === '') {
                $itemName = 'Menu item';
            }

            if (count($preview) < 3) {
                $preview[] = rt_format_order_quantity($quantity) . 'x ' . $itemName;
            }

            if ($statusKey === 'served') {
                $servedLineCount++;
                if (count($servedPreview) < 3) {
                    $servedPreview[] = rt_format_order_quantity($quantity) . 'x ' . $itemName;
                }
            }
        }

        $remainingLines = max(0, $lineCount - count($preview));
        $remainingServedLines = max(0, $servedLineCount - count($servedPreview));
        $customerName = trim((string)($order['customer_name'] ?? ''));
        $itemCountLabel = $lineCount > 0
            ? rt_format_order_quantity($quantityTotal) . ' item' . (abs($quantityTotal - 1.0) < 0.00001 ? '' : 's')
            : 'No items yet';
        $paymentMethod = trim((string)($order['payment_method'] ?? ''));

        $stationSummaries = [];
        $stationKeys = array_keys($stationCounts);
        usort($stationKeys, static function (string $left, string $right): int {
            $leftRank = rt_station_sort_rank($left);
            $rightRank = rt_station_sort_rank($right);
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcasecmp($left, $right);
        });
        foreach ($stationKeys as $stationKey) {
            $counts = $stationCounts[$stationKey] ?? [];
            $stationSummaries[] = [
                'label' => rt_station_label($stationKey),
                'state' => rt_station_state_from_counts($counts),
                'summary' => rt_station_summary_text($counts),
            ];
        }

        $activeOrders[$tableNumber] = [
            'order_id' => $orderId,
            'reference' => (string)($order['reference'] ?? ''),
            'customer_label' => $customerName !== '' ? $customerName : 'Walk-in / POS',
            'opened_at' => $openedAt,
            'opened_by_name' => (string)($order['opened_by_name'] ?? ''),
            'payment_method_label' => $paymentMethod !== '' ? ucwords(str_replace('_', ' ', $paymentMethod)) : '',
            'total_amount' => (float)($order['total_amount'] ?? 0),
            'item_count_label' => $itemCountLabel,
            'line_count' => $lineCount,
            'items_preview' => $preview,
            'remaining_lines' => $remainingLines,
            'served_items_preview' => $servedPreview,
            'remaining_served_lines' => $remainingServedLines,
            'station_summaries' => $stationSummaries,
        ];
    }

    return $activeOrders;
}

function rt_render_active_order_html(?array $order, bool $isActive, string $currencySymbol, bool $canSettleTableOrders): string
{
    if (!$isActive) {
        return '<span class="rt-order-empty"><i class="fas fa-ban"></i> Inactive</span>';
    }

    if (!$order || (int)($order['order_id'] ?? 0) <= 0) {
        return '<span class="rt-order-empty"><i class="fas fa-circle-check"></i> No active tab</span>';
    }

    $orderId = (int)$order['order_id'];
    $reference = htmlspecialchars((string)($order['reference'] ?? 'Order #' . $orderId));
    $totalAmount = htmlspecialchars($currencySymbol . ' ' . number_format((float)($order['total_amount'] ?? 0), 2));

    $metaParts = [];
    $customerLabel = trim((string)($order['customer_label'] ?? ''));
    if ($customerLabel !== '') {
        $metaParts[] = $customerLabel;
    }
    $itemCountLabel = trim((string)($order['item_count_label'] ?? ''));
    if ($itemCountLabel !== '') {
        $metaParts[] = $itemCountLabel;
    }
    $openedAt = trim((string)($order['opened_at'] ?? ''));
    if ($openedAt !== '') {
        $metaParts[] = 'Opened ' . $openedAt;
    }
    $openedByName = trim((string)($order['opened_by_name'] ?? ''));
    if ($openedByName !== '') {
        $metaParts[] = 'By ' . $openedByName;
    }
    $paymentMethodLabel = trim((string)($order['payment_method_label'] ?? ''));
    if ($paymentMethodLabel !== '') {
        $metaParts[] = $paymentMethodLabel;
    }

    $itemsHtml = '';
    foreach (($order['items_preview'] ?? []) as $previewItem) {
        $itemsHtml .= '<span class="rt-order-item">' . htmlspecialchars((string)$previewItem) . '</span>';
    }
    $remainingLines = (int)($order['remaining_lines'] ?? 0);
    if ($remainingLines > 0) {
        $itemsHtml .= '<span class="rt-order-item rt-order-item--more">+' . $remainingLines . ' more</span>';
    }
    if ($itemsHtml === '') {
        $itemsHtml = '<span class="rt-order-empty"><i class="fas fa-utensils"></i> No items posted yet</span>';
    }

    $servedItemsHtml = '';
    foreach (($order['served_items_preview'] ?? []) as $servedItem) {
        $servedItemsHtml .= '<span class="rt-order-item rt-order-item--served">' . htmlspecialchars((string)$servedItem) . '</span>';
    }
    $remainingServedLines = (int)($order['remaining_served_lines'] ?? 0);
    if ($remainingServedLines > 0) {
        $servedItemsHtml .= '<span class="rt-order-item rt-order-item--more">+' . $remainingServedLines . ' more served</span>';
    }
    if ($servedItemsHtml === '') {
        $servedItemsHtml = '<span class="rt-order-empty"><i class="fas fa-glass-water"></i> Nothing served yet</span>';
    }

    $stationsHtml = '';
    foreach (($order['station_summaries'] ?? []) as $stationSummary) {
        $stationState = strtolower((string)($stationSummary['state'] ?? 'busy'));
        $stationState = preg_replace('/[^a-z0-9-]/', '-', $stationState) ?: 'busy';
        $stationLabel = htmlspecialchars((string)($stationSummary['label'] ?? 'Station'));
        $stationSummaryText = htmlspecialchars((string)($stationSummary['summary'] ?? 'No station movement yet'));

        $stationsHtml .= '<span class="rt-order-station rt-order-station--' . $stationState . '"><strong>' . $stationLabel . '</strong><span>' . $stationSummaryText . '</span></span>';
    }
    if ($stationsHtml === '') {
        $stationsHtml = '<span class="rt-order-empty"><i class="fas fa-layer-group"></i> No station updates yet</span>';
    }

    $actionsHtml = '';
    if ($canSettleTableOrders) {
        $actionsHtml .= '<button type="button" class="rt-order-action rt-order-action--button" data-rt-settle-order data-order-id="' . $orderId . '" data-order-ref="' . htmlspecialchars((string)($order['reference'] ?? ''), ENT_QUOTES, 'UTF-8') . '" data-order-total="' . htmlspecialchars((string)($order['total_amount'] ?? 0), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-cash-register"></i> Take payment</button>';
    }
    $actionsHtml .= '<a href="order-lifecycle.php?id=' . $orderId . '" class="rt-order-action" target="_blank" rel="noopener"><i class="fas fa-receipt"></i> POS details</a>';

    return '<div class="rt-order-box">'
        . '<div class="rt-order-top"><strong class="rt-order-ref">' . $reference . '</strong><span class="rt-order-total">' . $totalAmount . '</span></div>'
        . '<span class="rt-order-meta">' . htmlspecialchars($metaParts ? implode(' | ', $metaParts) : 'Occupied table') . '</span>'
        . '<div class="rt-order-items">' . $itemsHtml . '</div>'
        . '<div class="rt-order-served"><span class="rt-order-served-label">Served</span><div class="rt-order-items">' . $servedItemsHtml . '</div></div>'
        . '<div class="rt-order-stations">' . $stationsHtml . '</div>'
        . '<div class="rt-order-actions">' . $actionsHtml . '</div>'
        . '</div>';
}

function rt_build_table_last_payments(PDO $pdo): array
{
    if (!rh_restaurant_tables_exist($pdo)) {
        return [];
    }

    $sql = "SELECT table_number, payment_id, payment_reference, payment_method, payment_status, order_id, order_reference, recorded_at
        FROM (
            SELECT
                so.table_number,
                p.id AS payment_id,
                p.payment_reference,
                p.payment_method,
                p.payment_status,
                so.id AS order_id,
                so.reference AS order_reference,
                COALESCE(p.updated_at, p.created_at, p.payment_date) AS recorded_at,
                ROW_NUMBER() OVER (
                    PARTITION BY so.table_number
                    ORDER BY COALESCE(p.updated_at, p.created_at, p.payment_date) DESC, p.id DESC
                ) AS row_num
            FROM payments p
            INNER JOIN stock_orders so ON p.booking_type = 'restaurant' AND p.booking_id = so.id
            WHERE p.deleted_at IS NULL
              AND COALESCE(p.payment_type, '') != 'refund'
              AND p.payment_status IN ('completed', 'paid')
              AND so.order_type = 'dine_in'
              AND so.table_number IS NOT NULL
              AND TRIM(so.table_number) <> ''
        ) ranked
        WHERE row_num = 1";

    $stmt = $pdo->query($sql);
    if (!$stmt) {
        return [];
    }

    $payments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tableNumber = (string)($row['table_number'] ?? '');
        if ($tableNumber === '') {
            continue;
        }

        $recordedAtLabel = '';
        if (!empty($row['recorded_at'])) {
            $ts = strtotime((string)$row['recorded_at']);
            if ($ts !== false) {
                $recordedAtLabel = date('d M Y H:i', $ts);
            }
        }

        $payments[$tableNumber] = [
            'payment_id' => (int)($row['payment_id'] ?? 0),
            'payment_reference' => (string)($row['payment_reference'] ?? ''),
            'payment_method' => (string)($row['payment_method'] ?? ''),
            'payment_method_label' => ucwords(str_replace('_', ' ', (string)($row['payment_method'] ?? ''))),
            'payment_status' => (string)($row['payment_status'] ?? ''),
            'payment_status_label' => ucwords(str_replace('_', ' ', (string)($row['payment_status'] ?? ''))),
            'order_id' => (int)($row['order_id'] ?? 0),
            'order_reference' => (string)($row['order_reference'] ?? ''),
            'recorded_at_label' => $recordedAtLabel,
        ];
    }

    return $payments;
}

function rt_render_last_payment_html(?array $payment): string
{
    if (!$payment || (int)($payment['payment_id'] ?? 0) <= 0) {
        return '<span class="rt-payment-empty"><i class="fas fa-receipt"></i> No settled payment yet</span>';
    }

    $paymentId = (int)$payment['payment_id'];
    $paymentReference = htmlspecialchars((string)($payment['payment_reference'] ?? 'Payment #' . $paymentId));
    $methodLabel = htmlspecialchars((string)($payment['payment_method_label'] ?? 'Recorded'));
    $statusLabel = htmlspecialchars((string)($payment['payment_status_label'] ?? 'Completed'));
    $orderReference = trim((string)($payment['order_reference'] ?? ''));
    $orderLabel = $orderReference !== '' ? 'Order ' . $orderReference : 'Restaurant order';
    $recordedAtLabel = trim((string)($payment['recorded_at_label'] ?? ''));
    $metaParts = [$orderLabel, $methodLabel];
    if ($recordedAtLabel !== '') {
        $metaParts[] = $recordedAtLabel;
    }
    $metaText = htmlspecialchars(implode(' | ', $metaParts));

    return '<div class="rt-payment-box">'
        . '<a href="payment-details.php?id=' . $paymentId . '" class="rt-payment-link"><i class="fas fa-receipt"></i> ' . $paymentReference . '</a>'
        . '<span class="rt-payment-meta">' . $metaText . '</span>'
        . '<span class="rt-payment-sub">Latest settled payment | ' . $statusLabel . '</span>'
        . '</div>';
}

function rt_upsert_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group)
        VALUES (?, ?, 'restaurant')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group), updated_at = NOW()");
    $stmt->execute([$key, $value]);
}

if (($_GET['ajax'] ?? '') === 'table_status') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode([
            'ok' => true,
            'statuses' => rh_restaurant_tables_exist($pdo) ? rt_build_table_live_statuses($pdo) : [],
            'active_orders' => rh_restaurant_tables_exist($pdo) ? rt_build_table_active_orders($pdo) : [],
            'last_payments' => rh_restaurant_tables_exist($pdo) ? rt_build_table_last_payments($pdo) : [],
            'server_time' => date('c'),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to fetch table status.']);
    }
    exit;
}

if (!rh_restaurant_tables_exist($pdo)) {
    $error = 'Restaurant table registry is not installed. Run migration 034 first.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'generate_range') {
                $start = (int)($_POST['range_start'] ?? 0);
                $end = (int)($_POST['range_end'] ?? 0);
                $capacityRaw = trim((string)($_POST['default_capacity'] ?? ''));
                $defaultCapacity = $capacityRaw === '' ? null : (int)$capacityRaw;
                $replaceRange = !empty($_POST['replace_range']);

                if ($start < 1 || $end < 1 || $end < $start || ($end - $start) > 300) {
                    throw new RuntimeException('Use a valid table range from 1 to 300 tables.');
                }
                if ($defaultCapacity !== null && ($defaultCapacity < 1 || $defaultCapacity > 999)) {
                    throw new RuntimeException('Default capacity must be blank or between 1 and 999.');
                }

                if ($replaceRange) {
                    $locks = rh_restaurant_active_location_locks($pdo);
                    $tableLocks = $locks['tables'] ?? [];
                    foreach ($tableLocks as $tableNumber => $lock) {
                        if (!preg_match('/^\d+$/', (string)$tableNumber)) {
                            continue;
                        }
                        $num = (int)$tableNumber;
                        if ($num < $start || $num > $end) {
                            $ref = (string)($lock['reference'] ?? 'active order');
                            throw new RuntimeException('Cannot replace range while Table ' . $tableNumber . ' has active order ' . $ref . '. Settle/cancel it first.');
                        }
                    }
                }

                $pdo->beginTransaction();
                $upsert = $pdo->prepare("INSERT INTO restaurant_tables (table_number, capacity, is_active, display_order)
                    VALUES (?, ?, 1, ?)
                    ON DUPLICATE KEY UPDATE
                        is_active = 1,
                        display_order = VALUES(display_order),
                        capacity = COALESCE(restaurant_tables.capacity, VALUES(capacity)),
                        updated_at = NOW()");
                for ($i = $start; $i <= $end; $i++) {
                    $upsert->execute([(string)$i, $defaultCapacity, $i]);
                }
                if ($replaceRange) {
                    $deactivate = $pdo->prepare("UPDATE restaurant_tables
                        SET is_active = 0, updated_at = NOW()
                        WHERE table_number REGEXP '^[0-9]+$'
                          AND (CAST(table_number AS UNSIGNED) < ? OR CAST(table_number AS UNSIGNED) > ?)");
                    $deactivate->execute([$start, $end]);
                }
                rt_upsert_setting($pdo, 'restaurant_table_range_start', (string)$start);
                rt_upsert_setting($pdo, 'restaurant_table_range_end', (string)$end);
                $pdo->commit();

                rh_log_event('admin/restaurant-tables', 'info', 'Restaurant table range updated', [
                    'user' => $user['username'] ?? '',
                    'range_start' => $start,
                    'range_end' => $end,
                    'default_capacity' => $defaultCapacity,
                    'replace_range' => $replaceRange,
                ]);
                $message = 'Restaurant table range saved.';
            } elseif ($action === 'save_tables') {
                $tableIds = $_POST['table_id'] ?? [];
                $capacities = $_POST['capacity'] ?? [];
                $notes = $_POST['notes'] ?? [];
                $activeRows = $_POST['is_active'] ?? [];
                if (!is_array($tableIds)) throw new RuntimeException('No table rows submitted.');

                $idToNumber = [];
                $tableIdsInt = array_values(array_filter(array_map('intval', $tableIds), static fn($id) => $id > 0));
                if (!empty($tableIdsInt)) {
                    $ph = implode(',', array_fill(0, count($tableIdsInt), '?'));
                    $lookup = $pdo->prepare("SELECT id, table_number FROM restaurant_tables WHERE id IN ($ph)");
                    $lookup->execute($tableIdsInt);
                    foreach ($lookup->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $idToNumber[(int)$row['id']] = (string)$row['table_number'];
                    }
                }
                $activeLocks = rh_restaurant_active_location_locks($pdo);
                $activeTableLocks = $activeLocks['tables'] ?? [];

                $pdo->beginTransaction();
                $update = $pdo->prepare("UPDATE restaurant_tables SET capacity = ?, notes = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                foreach ($tableIds as $idx => $idRaw) {
                    $id = (int)$idRaw;
                    if ($id <= 0) continue;
                    $capacityInput = trim((string)($capacities[$idx] ?? ''));
                    $capacity = $capacityInput === '' ? null : (int)$capacityInput;
                    if ($capacity !== null && ($capacity < 1 || $capacity > 999)) {
                        throw new RuntimeException('Capacity must be blank or between 1 and 999.');
                    }
                    $note = mb_substr(trim((string)($notes[$idx] ?? '')), 0, 255);
                    $active = isset($activeRows[$id]) ? 1 : 0;

                    if ($active === 0) {
                        $tableNumber = $idToNumber[$id] ?? null;
                        if ($tableNumber !== null && isset($activeTableLocks[$tableNumber])) {
                            $ref = (string)($activeTableLocks[$tableNumber]['reference'] ?? 'active order');
                            throw new RuntimeException('Cannot deactivate Table ' . $tableNumber . ' while order ' . $ref . ' is still active.');
                        }
                    }

                    $update->execute([$capacity, $note ?: null, $active, $id]);
                }
                $pdo->commit();

                rh_log_event('admin/restaurant-tables', 'info', 'Restaurant table capacities updated', [
                    'user' => $user['username'] ?? '',
                    'rows' => count($tableIds),
                ]);
                $message = 'Table capacities and active flags saved.';
            } elseif ($action === 'settle_table_order') {
                if (!$canSettleTableOrders) {
                    throw new RuntimeException('You do not have permission to settle tables from this page.');
                }

                $orderId = (int)($_POST['order_id'] ?? 0);
                $paymentMethod = (string)($_POST['payment_method'] ?? '');
                $allowedMethods = ['cash', 'mobile_money', 'card_manual', 'card_pos'];
                if ($orderId <= 0) {
                    throw new RuntimeException('Order not found.');
                }
                if (!in_array($paymentMethod, $allowedMethods, true)) {
                    throw new RuntimeException('Select a payment method.');
                }
                if ($paymentMethod === 'card_pos') {
                    throw new RuntimeException('Card POS terminal is not enabled yet — use Card.');
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT id, reference, total_amount, status, order_type, table_number, customer_name, customer_email, customer_phone FROM stock_orders WHERE id = ? FOR UPDATE");
                $stmt->execute([$orderId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) {
                    throw new RuntimeException('Order not found.');
                }
                if (($order['order_type'] ?? '') !== 'dine_in') {
                    throw new RuntimeException('Only dine-in table tabs can be settled here.');
                }
                if (($order['status'] ?? '') !== 'placed') {
                    throw new RuntimeException('This table tab has already been settled or closed.');
                }

                /* Drinks are handed over at the table, so auto-serve them (deducting stock) the
                 * same way the till does. Without this, settling here left un-bumped bar lines
                 * at stock_deducted=0 and kds_status='pending' — stock never came off the shelf
                 * and the ticket stayed on the bar display under an already-paid order. */
                rh_auto_serve_bar_items($pdo, $orderId, $user);

                $extras = rt_apply_payment_to_order($pdo, $user, $order, $paymentMethod, $_POST);
                rt_log_order_audit($pdo, (int)$order['id'], (int)$user['id'], (string)($user['full_name'] ?? ''), 'paid_from_tab', [
                    'method' => $paymentMethod,
                    'total' => (float)$order['total_amount'],
                    'tendered' => $extras['tendered'],
                    'change' => $extras['change'],
                    'table_number' => (string)($order['table_number'] ?? ''),
                    'till' => 'restaurant-tables.php',
                ]);
                $pdo->commit();

                if (function_exists('deleteCache')) {
                    deleteCache('stock_dashboard_metrics_v1');
                }
                rh_log_event('admin/restaurant-tables', 'info', 'Restaurant table settled from table registry', [
                    'user' => $user['username'] ?? '',
                    'order_id' => (int)$order['id'],
                    'reference' => (string)($order['reference'] ?? ''),
                    'table_number' => (string)($order['table_number'] ?? ''),
                    'payment_method' => $paymentMethod,
                ]);

                $changeMsg = ($paymentMethod === 'cash' && (float)($extras['change'] ?? 0) > 0)
                    ? ' Change: ' . $currency_symbol . ' ' . number_format((float)$extras['change'], 2) . '.'
                    : '';
                $tableLabel = trim((string)($order['table_number'] ?? ''));
                $message = ($tableLabel !== '' ? 'Table ' . $tableLabel . ' ' : 'Table tab ')
                    . 'settled — ' . (string)($order['reference'] ?? 'Order') . ' · '
                    . $currency_symbol . ' ' . number_format((float)$order['total_amount'], 2)
                    . ' · ' . str_replace('_', ' ', $paymentMethod) . '.' . $changeMsg;

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'ok'             => true,
                        'message'        => $message,
                        'order_id'       => (int)$order['id'],
                        'order_ref'      => (string)($order['reference'] ?? ''),
                        'table_number'   => $tableLabel,
                        'total'          => (float)$order['total_amount'],
                        'payment_method' => $paymentMethod,
                        'tendered'       => $extras['tendered'],
                        'change'         => $extras['change'],
                        'customer_name'  => (string)($order['customer_name'] ?? ''),
                        'customer_email' => (string)($order['customer_email'] ?? ''),
                        'customer_phone' => (string)($order['customer_phone'] ?? ''),
                    ]);
                    exit;
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $error]);
                exit;
            }
        }
    }
}

$csrf_token = generateCsrfToken();
$site_name = getSetting('site_name', 'Hotel');
$rangeStart = (int)getSetting('restaurant_table_range_start', '1');
$rangeEnd = (int)getSetting('restaurant_table_range_end', '20');
$tables = rh_restaurant_tables_exist($pdo)
    ? $pdo->query("SELECT id, table_number, capacity, notes, is_active, display_order, updated_at FROM restaurant_tables ORDER BY display_order ASC, CAST(table_number AS UNSIGNED) ASC, table_number ASC")->fetchAll(PDO::FETCH_ASSOC)
    : [];
$tableLiveStatuses = rh_restaurant_tables_exist($pdo) ? rt_build_table_live_statuses($pdo) : [];
$tableActiveOrders = rh_restaurant_tables_exist($pdo) ? rt_build_table_active_orders($pdo) : [];
$tableLastPayments = rh_restaurant_tables_exist($pdo) ? rt_build_table_last_payments($pdo) : [];
$activeCount = 0;
$totalCapacity = 0;
foreach ($tables as $table) {
    if ((int)$table['is_active'] === 1) $activeCount++;
    if ((int)$table['is_active'] === 1 && $table['capacity'] !== null) $totalCapacity += (int)$table['capacity'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant Tables - <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/restaurant-tables.css?v=<?php echo @filemtime(__DIR__ . '/css/restaurant-tables.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content restaurant-tables-page">
        <div class="restaurant-tables-head">
            <div>
                <h1><i class="fas fa-chair"></i> Restaurant Tables</h1>
                <p>Set the dine-in table range and optional sitting capacity used by POS duplicate-order protection.</p>
            </div>
            <a href="pos.php" target="_blank" rel="noopener" class="btn btn-secondary"><i class="fas fa-cash-register"></i> Open POS</a>
        </div>

        <?php if ($message !== ''): ?><?php showAlert($message, 'success'); ?><?php endif; ?>
        <?php if ($error !== ''): ?><?php showAlert($error, 'error'); ?><?php endif; ?>

        <div class="rt-grid">
            <section class="rt-card">
                <h2>Table Range</h2>
                <form method="post" action="restaurant-tables.php" data-admin-loader-form>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="generate_range">
                    <div class="rt-form-grid">
                        <div class="rt-field">
                            <label for="range_start">From</label>
                            <input type="number" min="1" max="999" id="range_start" name="range_start" value="<?php echo (int)$rangeStart; ?>" required>
                        </div>
                        <div class="rt-field">
                            <label for="range_end">To</label>
                            <input type="number" min="1" max="999" id="range_end" name="range_end" value="<?php echo (int)$rangeEnd; ?>" required>
                        </div>
                        <div class="rt-field">
                            <label for="default_capacity">Capacity</label>
                            <input type="number" min="1" max="999" id="default_capacity" name="default_capacity" placeholder="Optional">
                        </div>
                    </div>
                    <label class="rt-check"><input type="checkbox" name="replace_range" value="1"> Deactivate numeric tables outside this range</label>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-layer-group"></i> Generate / Update Range</button>
                </form>
            </section>

            <section class="rt-card">
                <h2>Current Table Summary</h2>
                <div class="rt-stats">
                    <div class="rt-stat"><strong><?php echo count($tables); ?></strong><span>Total Rows</span></div>
                    <div class="rt-stat"><strong><?php echo $activeCount; ?></strong><span>Active Tables</span></div>
                    <div class="rt-stat"><strong><?php echo $totalCapacity > 0 ? $totalCapacity : '&mdash;'; ?></strong><span>Total Capacity</span></div>
                </div>
                <p class="rt-muted" style="margin-top:12px;">Capacity is optional. Leave it blank if the table exists but seating changes often.</p>
            </section>
        </div>

        <form method="post" action="restaurant-tables.php" data-admin-loader-form>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="save_tables">
            <div class="rt-table-wrap">
                <table class="rt-table">
                    <thead>
                        <tr>
                            <th>Table</th>
                            <th>Live Status</th>
                            <th>Current Order</th>
                            <th>Last Payment</th>
                            <th>Capacity</th>
                            <th>Notes</th>
                            <th>Active</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tables)): ?>
                            <tr>
                                <td data-label="Tables" colspan="8">No table rows yet. Generate a range above.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tables as $idx => $table): ?>
                                <tr>
                                    <td data-label="Table">
                                        <strong>Table <?php echo htmlspecialchars($table['table_number']); ?></strong>
                                        <input type="hidden" name="table_id[]" value="<?php echo (int)$table['id']; ?>">
                                    </td>
                                    <td data-label="Live Status">
                                        <div class="rt-live-cell" data-live-status data-table-number="<?php echo htmlspecialchars((string)$table['table_number']); ?>" data-table-active="<?php echo (int)$table['is_active'] === 1 ? '1' : '0'; ?>">
                                            <?php echo rt_render_live_status_html($tableLiveStatuses[(string)$table['table_number']] ?? null, (int)$table['is_active'] === 1); ?>
                                        </div>
                                    </td>
                                    <td data-label="Current Order">
                                        <div class="rt-current-order-cell" data-active-order data-table-number="<?php echo htmlspecialchars((string)$table['table_number']); ?>" data-table-active="<?php echo (int)$table['is_active'] === 1 ? '1' : '0'; ?>">
                                            <?php echo rt_render_active_order_html($tableActiveOrders[(string)$table['table_number']] ?? null, (int)$table['is_active'] === 1, $currency_symbol, $canSettleTableOrders); ?>
                                        </div>
                                    </td>
                                    <td data-label="Last Payment">
                                        <div class="rt-last-payment-cell" data-last-payment data-table-number="<?php echo htmlspecialchars((string)$table['table_number']); ?>">
                                            <?php echo rt_render_last_payment_html($tableLastPayments[(string)$table['table_number']] ?? null); ?>
                                        </div>
                                    </td>
                                    <td data-label="Capacity"><input type="number" name="capacity[]" min="1" max="999" value="<?php echo $table['capacity'] !== null ? (int)$table['capacity'] : ''; ?>" placeholder="Optional"></td>
                                    <td data-label="Notes"><input type="text" name="notes[]" maxlength="255" value="<?php echo htmlspecialchars((string)($table['notes'] ?? '')); ?>" placeholder="Window, patio, VIP..."></td>
                                    <td data-label="Active">
                                        <label class="rt-active"><input type="checkbox" name="is_active[<?php echo (int)$table['id']; ?>]" value="1" <?php echo (int)$table['is_active'] === 1 ? 'checked' : ''; ?>> Active</label>
                                    </td>
                                    <td data-label="Updated"><span class="rt-muted"><?php echo htmlspecialchars(date('d M Y H:i', strtotime((string)$table['updated_at']))); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="actions-bar">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Table Details</button>
            </div>
        </form>
    </div>
    <div class="rt-modal-overlay" id="rtSettleModal" aria-hidden="true">
        <div class="rt-modal-card" role="dialog" aria-modal="true" aria-labelledby="rt-settle-title">
            <div class="rt-modal-head">
                <div>
                    <p class="rt-modal-kicker">Restaurant tables</p>
                    <h2 id="rt-settle-title">Take payment</h2>
                </div>
                <button type="button" class="rt-modal-close" data-rt-close-settle aria-label="Close payment modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="post" action="restaurant-tables.php" id="rtSettleForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="settle_table_order">
                <input type="hidden" name="order_id" id="rtSettleOrderId" value="">
                <input type="hidden" name="payment_method" id="rtSettleMethod" value="">
                <div class="rt-modal-body">
                    <div class="rt-settle-summary">
                        <span class="rt-settle-ref" id="rtSettleRef">Order</span>
                        <strong class="rt-settle-total" id="rtSettleTotal"><?php echo htmlspecialchars($currency_symbol); ?> 0.00</strong>
                    </div>
                    <div class="rt-pay-method-grid" role="group" aria-label="Select payment method">
                        <button type="button" class="rt-pay-method" data-rt-settle-method="cash"><i class="fas fa-money-bill-wave"></i> Cash</button>
                        <button type="button" class="rt-pay-method" data-rt-settle-method="mobile_money"><i class="fas fa-mobile-screen-button"></i> Mobile money</button>
                        <button type="button" class="rt-pay-method" data-rt-settle-method="card_manual"><i class="fas fa-credit-card"></i> Card</button>
                    </div>
                    <p class="rt-modal-error" id="rtSettleValidation" hidden></p>
                    <div class="rt-settle-section" id="rt-settle-cash">
                        <label for="rtSettleTendered">Tendered (<?php echo htmlspecialchars($currency_symbol); ?>)</label>
                        <input type="number" step="0.01" min="0" name="tendered_amount" id="rtSettleTendered" class="rt-modal-input">
                        <div class="rt-change-banner">Change: <strong id="rtSettleChange"><?php echo htmlspecialchars($currency_symbol); ?> 0.00</strong></div>
                    </div>
                    <div class="rt-settle-section" id="rt-settle-mobile_money">
                        <label for="rtSettleProvider">Provider</label>
                        <select name="mobile_wallet_provider" id="rtSettleProvider" class="rt-modal-select">
                            <option value="">Select...</option>
                            <option value="Airtel Money">Airtel Money</option>
                            <option value="TNM Mpamba">TNM Mpamba</option>
                            <option value="Mo626">Mo626</option>
                            <option value="Other">Other</option>
                        </select>
                        <label for="rtSettleMobileReference">Reference</label>
                        <input type="text" name="mobile_wallet_reference" id="rtSettleMobileReference" class="rt-modal-input" maxlength="100">
                    </div>
                    <div class="rt-settle-section" id="rt-settle-card_manual">
                        <label for="rtSettleCardLast4">Card last 4</label>
                        <input type="text" name="card_last4" id="rtSettleCardLast4" class="rt-modal-input" inputmode="numeric" pattern="\d{4}" maxlength="4">
                        <label for="rtSettleCardAuth">Auth code</label>
                        <input type="text" name="card_auth_code" id="rtSettleCardAuth" class="rt-modal-input" maxlength="50">
                    </div>
                </div>
                <div class="rt-modal-foot">
                    <button type="button" class="btn btn-secondary" data-rt-close-settle>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-hand-holding-dollar"></i> Take payment &amp; close table</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Post-settlement receipt modal -->
    <div class="rt-modal-overlay" id="rtReceiptModal" aria-hidden="true">
        <div class="rt-modal-card" role="dialog" aria-modal="true" aria-labelledby="rt-receipt-title" style="max-width:540px;">
            <div class="rt-modal-head" style="background:linear-gradient(135deg,#1d6a3e,#22c55e);color:#fff;border-radius:12px 12px 0 0;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:38px;height:38px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;"><i class="fas fa-check"></i></div>
                    <div>
                        <h2 id="rt-receipt-title" style="margin:0;font-size:16px;color:#fff;">Payment recorded</h2>
                        <div style="font-size:12px;opacity:.85;" id="rtReceiptSubtitle">Table settled</div>
                    </div>
                </div>
                <button type="button" onclick="rtCloseReceiptModal()" style="background:none;border:none;color:#fff;font-size:22px;line-height:1;cursor:pointer;opacity:.8;padding:4px;" aria-label="Close receipt modal">&times;</button>
            </div>
            <div class="rt-modal-body">
                <div id="rtReceiptSummary" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;"></div>
                <div style="font-size:13px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-paper-plane" style="color:#8B7355;"></i> Send receipt to guest
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#6c757d;display:block;margin-bottom:4px;">Email</label>
                        <input type="email" id="rtReceiptEmail" placeholder="guest@example.com" style="width:100%;box-sizing:border-box;min-height:36px;border:1px solid #d1d5db;border-radius:7px;padding:7px 10px;font-size:12px;margin-bottom:6px;">
                        <button type="button" id="rtReceiptEmailBtn" onclick="rtSendReceipt('email')" style="width:100%;padding:8px;background:#3b82f6;color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;"><i class="fas fa-envelope"></i> Send email</button>
                        <div id="rtReceiptEmailStatus" style="font-size:11px;margin-top:4px;min-height:14px;"></div>
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#6c757d;display:block;margin-bottom:4px;">WhatsApp</label>
                        <input type="tel" id="rtReceiptPhone" placeholder="+265 999 123 456" style="width:100%;box-sizing:border-box;min-height:36px;border:1px solid #d1d5db;border-radius:7px;padding:7px 10px;font-size:12px;margin-bottom:6px;">
                        <button type="button" id="rtReceiptWhatsAppBtn" onclick="rtSendReceipt('whatsapp')" style="width:100%;padding:8px;background:#1d6a3e;color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;"><i class="fab fa-whatsapp"></i> Send WhatsApp</button>
                        <div id="rtReceiptWhatsAppStatus" style="font-size:11px;margin-top:4px;min-height:14px;"></div>
                    </div>
                </div>
            </div>
            <div class="rt-modal-foot">
                <a id="rtReceiptPrintLink" href="#" target="_blank" rel="noopener" class="btn btn-secondary" style="text-decoration:none;"><i class="fas fa-print"></i> Print receipt</a>
                <button type="button" class="btn btn-primary" onclick="rtCloseReceiptModal()"><i class="fas fa-check"></i> Done</button>
            </div>
        </div>
    </div>

    <div id="admin-page-loader" class="admin-page-loader" role="status" aria-label="Loading">
        <div class="admin-page-loader-card">
            <div class="admin-page-loader-spinner"><span></span><span></span><span></span></div>
            <p class="admin-page-loader-title">Saving...</p>
        </div>
    </div>
    <script>
        document.querySelectorAll('[data-admin-loader-form]').forEach(form => {
            form.addEventListener('submit', () => {
                document.getElementById('admin-page-loader')?.classList.add('is-visible');
            });
        });

        const tableStatusEndpoint = 'restaurant-tables.php?ajax=table_status';
        const rtCurrencySymbol = <?php echo json_encode($currency_symbol); ?>;
        const rtCanSettleTableOrders = <?php echo $canSettleTableOrders ? 'true' : 'false'; ?>;
        const rtSettleModal = document.getElementById('rtSettleModal');
        const rtSettleForm = document.getElementById('rtSettleForm');
        const rtSettleOrderId = document.getElementById('rtSettleOrderId');
        const rtSettleMethod = document.getElementById('rtSettleMethod');
        const rtSettleRef = document.getElementById('rtSettleRef');
        const rtSettleTotal = document.getElementById('rtSettleTotal');
        const rtSettleTendered = document.getElementById('rtSettleTendered');
        const rtSettleChange = document.getElementById('rtSettleChange');
        const rtSettleValidation = document.getElementById('rtSettleValidation');

        function rtEscapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [char]));
        }

        function rtBuildLiveStatusHtml(status, isActive) {
            if (!isActive) {
                return '<span class="rt-live-pill rt-live-pill--inactive"><i class="fas fa-ban"></i> Inactive</span>';
            }
            if (!status) {
                return '<span class="rt-live-pill rt-live-pill--available"><i class="fas fa-circle-check"></i> Available</span>';
            }

            const rawState = String(status.state || 'busy').toLowerCase();
            const stateClass = rawState.replace(/[^a-z0-9-]/g, '-') || 'busy';
            const icon = rtEscapeHtml(status.icon || 'fa-utensils');
            const label = rtEscapeHtml(status.label || 'Active');

            const metaParts = [];
            if (status.reference) metaParts.push(String(status.reference));
            if (status.opened_at) metaParts.push('Opened ' + String(status.opened_at));
            const meta = rtEscapeHtml(metaParts.length ? metaParts.join(' | ') : 'Active order');
            const summary = rtEscapeHtml(status.summary || 'Order is active');

            return '<div class="rt-live-box">' +
                '<span class="rt-live-pill rt-live-pill--' + stateClass + '"><i class="fas ' + icon + '"></i> ' + label + '</span>' +
                '<span class="rt-live-meta">' + meta + '</span>' +
                '<span class="rt-live-sub">' + summary + '</span>' +
                '</div>';
        }

        function rtFormatMoney(value) {
            const amount = Number(value || 0);
            if (!Number.isFinite(amount)) {
                return '0.00';
            }

            return amount.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        }

        function rtSettleActionHtml(orderId, order) {
            if (!rtCanSettleTableOrders) {
                return '';
            }

            return '<button type="button" class="rt-order-action rt-order-action--button" data-rt-settle-order data-order-id="' + orderId + '" data-order-ref="' + rtEscapeHtml(order.reference || '') + '" data-order-total="' + Number(order.total_amount || 0) + '"><i class="fas fa-cash-register"></i> Take payment</button>';
        }

        function rtBuildServedItemsHtml(order) {
            const servedPreview = Array.isArray(order.served_items_preview) ? order.served_items_preview : [];
            let servedHtml = servedPreview.map((item) => '<span class="rt-order-item rt-order-item--served">' + rtEscapeHtml(item) + '</span>').join('');
            const remainingServedLines = Number(order.remaining_served_lines || 0);
            if (remainingServedLines > 0) {
                servedHtml += '<span class="rt-order-item rt-order-item--more">+' + remainingServedLines + ' more served</span>';
            }
            if (!servedHtml) {
                servedHtml = '<span class="rt-order-empty"><i class="fas fa-glass-water"></i> Nothing served yet</span>';
            }

            return '<div class="rt-order-served"><span class="rt-order-served-label">Served</span><div class="rt-order-items">' + servedHtml + '</div></div>';
        }

        function rtShowSettleValidation(message) {
            if (!rtSettleValidation) {
                return;
            }

            if (!message) {
                rtSettleValidation.hidden = true;
                rtSettleValidation.textContent = '';
                return;
            }

            rtSettleValidation.hidden = false;
            rtSettleValidation.textContent = message;
        }

        function rtResetSettleModal() {
            if (!rtSettleForm || !rtSettleMethod) {
                return;
            }

            rtSettleForm.reset();
            rtSettleMethod.value = '';
            document.querySelectorAll('[data-rt-settle-method]').forEach((button) => button.classList.remove('is-active'));
            document.querySelectorAll('.rt-settle-section').forEach((section) => section.classList.remove('is-active'));
            rtShowSettleValidation('');
            if (rtSettleChange) {
                rtSettleChange.textContent = rtCurrencySymbol + ' 0.00';
            }
        }

        function rtOpenSettleModal(orderId, orderRef, totalAmount) {
            if (!rtCanSettleTableOrders || !rtSettleModal || !rtSettleOrderId || !rtSettleRef || !rtSettleTotal) {
                return;
            }

            rtResetSettleModal();
            rtSettleOrderId.value = String(orderId || '');
            rtSettleRef.textContent = orderRef || ('Order #' + orderId);
            rtSettleTotal.textContent = rtCurrencySymbol + ' ' + rtFormatMoney(totalAmount);
            rtSettleModal.classList.add('active');
            rtSettleModal.setAttribute('aria-hidden', 'false');
        }

        function rtCloseSettleModal() {
            if (!rtSettleModal) {
                return;
            }

            rtSettleModal.classList.remove('active');
            rtSettleModal.setAttribute('aria-hidden', 'true');
            rtResetSettleModal();
        }

        function rtUpdateSettleChange() {
            if (!rtSettleChange || !rtSettleTendered || !rtSettleTotal) {
                return;
            }

            const totalText = String(rtSettleTotal.textContent || '').replace(rtCurrencySymbol, '').replace(/,/g, '').trim();
            const totalAmount = Number(totalText || 0);
            const tendered = Number(rtSettleTendered.value || 0);
            const change = Number.isFinite(totalAmount) && Number.isFinite(tendered) ? Math.max(0, tendered - totalAmount) : 0;
            rtSettleChange.textContent = rtCurrencySymbol + ' ' + rtFormatMoney(change);
        }

        function rtSelectSettleMethod(button) {
            if (!button || !rtSettleMethod) {
                return;
            }

            const method = button.dataset.rtSettleMethod || '';
            rtSettleMethod.value = method;
            document.querySelectorAll('[data-rt-settle-method]').forEach((candidate) => {
                candidate.classList.toggle('is-active', candidate === button);
            });
            document.querySelectorAll('.rt-settle-section').forEach((section) => {
                section.classList.toggle('is-active', section.id === 'rt-settle-' + method);
            });
            rtShowSettleValidation('');
            if (method === 'cash' && rtSettleTendered && rtSettleTotal) {
                const totalText = String(rtSettleTotal.textContent || '').replace(rtCurrencySymbol, '').replace(/,/g, '').trim();
                const total = Number(totalText || 0);
                if (total > 0 && (!rtSettleTendered.value || parseFloat(rtSettleTendered.value) < total)) {
                    rtSettleTendered.value = total.toFixed(2);
                }
                rtSettleTendered.focus();
            }
            rtUpdateSettleChange();
        }

        function rtBuildActiveOrderHtml(order, isActive) {
            if (!isActive) {
                return '<span class="rt-order-empty"><i class="fas fa-ban"></i> Inactive</span>';
            }
            if (!order || !Number(order.order_id || 0)) {
                return '<span class="rt-order-empty"><i class="fas fa-circle-check"></i> No active tab</span>';
            }

            const orderId = Number(order.order_id || 0);
            const reference = rtEscapeHtml(order.reference || ('Order #' + orderId));
            const metaParts = [];
            if (order.customer_label) metaParts.push(String(order.customer_label));
            if (order.item_count_label) metaParts.push(String(order.item_count_label));
            if (order.opened_at) metaParts.push('Opened ' + String(order.opened_at));
            if (order.opened_by_name) metaParts.push('By ' + String(order.opened_by_name));
            if (order.payment_method_label) metaParts.push(String(order.payment_method_label));

            const previewItems = Array.isArray(order.items_preview) ? order.items_preview : [];
            let itemsHtml = previewItems.map((item) => '<span class="rt-order-item">' + rtEscapeHtml(item) + '</span>').join('');
            const remainingLines = Number(order.remaining_lines || 0);
            if (remainingLines > 0) {
                itemsHtml += '<span class="rt-order-item rt-order-item--more">+' + remainingLines + ' more</span>';
            }
            if (!itemsHtml) {
                itemsHtml = '<span class="rt-order-empty"><i class="fas fa-utensils"></i> No items posted yet</span>';
            }

            const stationSummaries = Array.isArray(order.station_summaries) ? order.station_summaries : [];
            let stationsHtml = stationSummaries.map((station) => {
                const stateClass = String(station.state || 'busy').toLowerCase().replace(/[^a-z0-9-]/g, '-') || 'busy';
                const label = rtEscapeHtml(station.label || 'Station');
                const summary = rtEscapeHtml(station.summary || 'No station movement yet');
                return '<span class="rt-order-station rt-order-station--' + stateClass + '"><strong>' + label + '</strong><span>' + summary + '</span></span>';
            }).join('');
            if (!stationsHtml) {
                stationsHtml = '<span class="rt-order-empty"><i class="fas fa-layer-group"></i> No station updates yet</span>';
            }

            const settleActionHtml = rtSettleActionHtml(orderId, order);

            return '<div class="rt-order-box">' +
                '<div class="rt-order-top"><strong class="rt-order-ref">' + reference + '</strong><span class="rt-order-total">' + rtEscapeHtml(rtCurrencySymbol) + ' ' + rtFormatMoney(order.total_amount) + '</span></div>' +
                '<span class="rt-order-meta">' + rtEscapeHtml(metaParts.length ? metaParts.join(' | ') : 'Occupied table') + '</span>' +
                '<div class="rt-order-items">' + itemsHtml + '</div>' +
                rtBuildServedItemsHtml(order) +
                '<div class="rt-order-stations">' + stationsHtml + '</div>' +
                '<div class="rt-order-actions">' + settleActionHtml + '<a href="order-lifecycle.php?id=' + orderId + '" class="rt-order-action" target="_blank" rel="noopener"><i class="fas fa-receipt"></i> POS details</a></div>' +
                '</div>';
        }

        function rtBuildLastPaymentHtml(payment) {
            if (!payment || !Number(payment.payment_id || 0)) {
                return '<span class="rt-payment-empty"><i class="fas fa-receipt"></i> No settled payment yet</span>';
            }

            const paymentId = Number(payment.payment_id || 0);
            const paymentReference = rtEscapeHtml(payment.payment_reference || ('Payment #' + paymentId));
            const orderReference = String(payment.order_reference || '').trim();
            const methodLabel = rtEscapeHtml(payment.payment_method_label || 'Recorded');
            const recordedAtLabel = rtEscapeHtml(payment.recorded_at_label || '');
            const statusLabel = rtEscapeHtml(payment.payment_status_label || 'Completed');
            const metaParts = [orderReference ? 'Order ' + orderReference : 'Restaurant order', methodLabel];
            if (recordedAtLabel) metaParts.push(recordedAtLabel);

            return '<div class="rt-payment-box">' +
                '<a href="payment-details.php?id=' + paymentId + '" class="rt-payment-link"><i class="fas fa-receipt"></i> ' + paymentReference + '</a>' +
                '<span class="rt-payment-meta">' + rtEscapeHtml(metaParts.join(' | ')) + '</span>' +
                '<span class="rt-payment-sub">Latest settled payment | ' + statusLabel + '</span>' +
                '</div>';
        }

        if (rtSettleTendered) {
            rtSettleTendered.addEventListener('input', rtUpdateSettleChange);
        }

        document.addEventListener('click', (event) => {
            const openButton = event.target.closest('[data-rt-settle-order]');
            if (openButton) {
                event.preventDefault();
                rtOpenSettleModal(
                    Number(openButton.dataset.orderId || 0),
                    String(openButton.dataset.orderRef || ''),
                    Number(openButton.dataset.orderTotal || 0)
                );
                return;
            }

            const methodButton = event.target.closest('[data-rt-settle-method]');
            if (methodButton) {
                event.preventDefault();
                rtSelectSettleMethod(methodButton);
                return;
            }

            if (event.target.closest('[data-rt-close-settle]')) {
                event.preventDefault();
                rtCloseSettleModal();
                return;
            }

            if (event.target === rtSettleModal) {
                rtCloseSettleModal();
            }

            if (event.target === document.getElementById('rtReceiptModal')) {
                rtCloseReceiptModal();
            }
        });

        let _rtSettleOrderId = 0;

        function rtOpenReceiptModal(data) {
            _rtSettleOrderId = Number(data.order_id || 0);
            const sym = rtCurrencySymbol;
            const methodLabel = String(data.payment_method || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

            let summaryHtml = `<div><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Order</span><div style="font-weight:700;">${rtEscapeHtml(data.order_ref || '')}</div></div>`;
            summaryHtml += `<div><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Total</span><div style="font-size:18px;font-weight:700;color:#166534;">${sym} ${rtFormatMoney(data.total)}</div></div>`;
            summaryHtml += `<div><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Method</span><div style="font-weight:600;color:#374151;">${rtEscapeHtml(methodLabel)}</div></div>`;
            if (data.payment_method === 'cash' && Number(data.change) > 0) {
                summaryHtml += `<div><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Change</span><div style="font-size:16px;font-weight:700;color:#1d4ed8;">${sym} ${rtFormatMoney(data.change)}</div></div>`;
            }
            if (data.customer_name) {
                summaryHtml += `<div style="grid-column:1/-1"><span style="color:#6c757d;font-size:11px;font-weight:600;text-transform:uppercase;">Guest</span><div style="font-weight:600;">${rtEscapeHtml(data.customer_name)}</div></div>`;
            }
            document.getElementById('rtReceiptSummary').innerHTML = summaryHtml;

            const tableLabel = data.table_number ? 'Table ' + data.table_number + ' settled' : 'Table tab settled';
            document.getElementById('rtReceiptSubtitle').textContent = tableLabel;
            document.getElementById('rtReceiptEmail').value = data.customer_email || '';
            document.getElementById('rtReceiptPhone').value = data.customer_phone || '';
            document.getElementById('rtReceiptEmailStatus').textContent = '';
            document.getElementById('rtReceiptWhatsAppStatus').textContent = '';

            ['rtReceiptEmailBtn', 'rtReceiptWhatsAppBtn'].forEach(id => {
                const b = document.getElementById(id);
                if (b) { b.disabled = false; b.style.opacity = '1'; }
            });

            const printLink = document.getElementById('rtReceiptPrintLink');
            if (printLink && _rtSettleOrderId > 0) {
                printLink.href = 'stock-receipt.php?id=' + _rtSettleOrderId + '&print=1';
            }

            const modal = document.getElementById('rtReceiptModal');
            if (modal) { modal.classList.add('active'); modal.setAttribute('aria-hidden', 'false'); }
        }

        function rtCloseReceiptModal() {
            const modal = document.getElementById('rtReceiptModal');
            if (modal) { modal.classList.remove('active'); modal.setAttribute('aria-hidden', 'true'); }
        }

        async function rtSendReceipt(channel) {
            if (_rtSettleOrderId <= 0) return;
            const isEmail = channel === 'email';
            const recipient = (isEmail
                ? document.getElementById('rtReceiptEmail').value
                : document.getElementById('rtReceiptPhone').value
            ).trim();
            if (!recipient) {
                if (isEmail) {
                    document.getElementById('rtReceiptEmailStatus').innerHTML = '<span style="color:#dc2626;">Enter an email address.</span>';
                } else {
                    document.getElementById('rtReceiptWhatsAppStatus').innerHTML = '<span style="color:#dc2626;">Enter a phone number.</span>';
                }
                return;
            }

            const btnId = isEmail ? 'rtReceiptEmailBtn' : 'rtReceiptWhatsAppBtn';
            const statusId = isEmail ? 'rtReceiptEmailStatus' : 'rtReceiptWhatsAppStatus';
            const btn = document.getElementById(btnId);
            const statusEl = document.getElementById(statusId);
            btn.disabled = true;
            btn.style.opacity = '0.6';
            statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
            statusEl.style.color = '#6c757d';

            const fd = new FormData();
            fd.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
            fd.append('action', isEmail ? 'email_receipt' : 'whatsapp_receipt');
            fd.append('order_id', String(_rtSettleOrderId));
            fd.append('recipient', recipient);

            try {
                const r = await fetch('stock-receipt.php?id=' + _rtSettleOrderId, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                    credentials: 'same-origin',
                });
                const j = await r.json();
                if (j.ok) {
                    statusEl.innerHTML = '<i class="fas fa-check-circle" style="color:#16a34a;"></i> ' + rtEscapeHtml(j.message || 'Sent');
                    statusEl.style.color = '#16a34a';
                } else {
                    statusEl.innerHTML = '<i class="fas fa-times-circle" style="color:#dc2626;"></i> ' + rtEscapeHtml(j.error || 'Failed');
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

        if (rtSettleForm) {
            rtSettleForm.addEventListener('submit', async (event) => {
                event.preventDefault();

                const method = String(rtSettleMethod?.value || '').trim();
                if (!method) {
                    rtShowSettleValidation('Select a payment method before taking payment.');
                    return;
                }

                if (method === 'cash') {
                    const totalText = String(rtSettleTotal?.textContent || '').replace(rtCurrencySymbol, '').replace(/,/g, '').trim();
                    const totalAmount = Number(totalText || 0);
                    const tendered = Number(rtSettleTendered?.value || 0);
                    if (!Number.isFinite(tendered) || tendered < totalAmount) {
                        rtShowSettleValidation('Tendered cash must cover the full tab total.');
                        return;
                    }
                }

                rtShowSettleValidation('');
                document.getElementById('admin-page-loader')?.classList.add('is-visible');

                try {
                    const fd = new FormData(rtSettleForm);
                    const resp = await fetch('restaurant-tables.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: fd,
                        credentials: 'same-origin',
                    });
                    const data = await resp.json();
                    document.getElementById('admin-page-loader')?.classList.remove('is-visible');

                    if (data.ok) {
                        rtCloseSettleModal();
                        rtOpenReceiptModal(data);
                        // Refresh table statuses to reflect settled state
                        refreshTableLiveStatuses();
                    } else {
                        rtShowSettleValidation(data.error || 'Payment failed. Please try again.');
                    }
                } catch (err) {
                    document.getElementById('admin-page-loader')?.classList.remove('is-visible');
                    rtShowSettleValidation('Network error — please check your connection and try again.');
                }
            });
        }

        async function refreshTableLiveStatuses() {
            try {
                const response = await fetch(tableStatusEndpoint, {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                if (!response.ok) return;
                const payload = await response.json();
                if (!payload || payload.ok !== true || !payload.statuses) return;

                document.querySelectorAll('[data-live-status]').forEach((cell) => {
                    const tableNumber = cell.dataset.tableNumber || '';
                    const isActive = (cell.dataset.tableActive || '0') === '1';
                    const status = payload.statuses[tableNumber] || null;
                    cell.innerHTML = rtBuildLiveStatusHtml(status, isActive);
                });

                document.querySelectorAll('[data-active-order]').forEach((cell) => {
                    const tableNumber = cell.dataset.tableNumber || '';
                    const isActive = (cell.dataset.tableActive || '0') === '1';
                    const order = (payload.active_orders || {})[tableNumber] || null;
                    cell.innerHTML = rtBuildActiveOrderHtml(order, isActive);
                });

                document.querySelectorAll('[data-last-payment]').forEach((cell) => {
                    const tableNumber = cell.dataset.tableNumber || '';
                    const payment = (payload.last_payments || {})[tableNumber] || null;
                    cell.innerHTML = rtBuildLastPaymentHtml(payment);
                });
            } catch (error) {
                // Silent fail keeps page usable if polling briefly fails.
            }
        }

        refreshTableLiveStatuses();
        const liveStatusInterval = setInterval(() => {
            if (document.visibilityState === 'visible') {
                refreshTableLiveStatuses();
            }
        }, 12000);
        window.addEventListener('beforeunload', () => clearInterval(liveStatusInterval));
    </script>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

