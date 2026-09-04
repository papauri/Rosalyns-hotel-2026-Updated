<?php
require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */
/** @var PDO $pdo */
require_once 'includes/finance-schema.php';
require_once '../includes/station-hours.php';

if (!hasPermission((int)($user['id'] ?? 0), 'pos_accounting')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$site_name = getSetting('site_name') ?: 'Admin';
$currency_symbol = getSetting('currency_symbol') ?: 'MWK ';
$businessDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate) || !strtotime($businessDate)) {
    $businessDate = date('Y-m-d');
}
// Use the actual trading window for this business date, not calendar midnight — a sale made
// after midnight during an open trading window must land in the same day's accounting as its
// receipt number and shift close (see .claude/POS_KDS_ACCOUNTING_PLAN.md D4).
$dayWindow = rh_station_union_window_for_date($businessDate);
$dayStart = $dayWindow['start_sql'];
$dayEnd = $dayWindow['end_sql'];
$message = '';
$error = '';

function rh_pos_accounting_money(float $amount, string $currencySymbol): string
{
    return '<span class="finance-money"><span class="finance-money__currency">'
        . htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8')
        . '</span><span class="finance-money__amount">'
        . htmlspecialchars(number_format($amount, 2), ENT_QUOTES, 'UTF-8')
        . '</span></span>';
}

function rh_pos_accounting_money_text(float $amount, string $currencySymbol): string
{
    return trim($currencySymbol) . ' ' . number_format($amount, 2);
}

function rh_pos_accounting_event_label(string $event): string
{
    $event = strtolower(trim($event));
    $labels = [
        'parked_open_tab' => 'Open tab parked',
        'accountant_shift_closed' => 'Shift closed by accounting',
        'accountant_order_note' => 'Order note recorded',
        'payment_captured' => 'Payment captured',
        'voided' => 'Order voided',
        'recalled' => 'Order recalled',
        'shift_closed' => 'Shift closed',
    ];

    if (isset($labels[$event])) {
        return $labels[$event];
    }

    return ucfirst(str_replace('_', ' ', $event));
}

/**
 * @return array{summary:string,pairs:array<int,array{label:string,value:string}>}
 */
function rh_pos_accounting_log_details_view(?string $details, string $currencySymbol): array
{
    $raw = trim((string)$details);
    if ($raw === '') {
        return ['summary' => 'No extra context captured.', 'pairs' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['summary' => $raw, 'pairs' => []];
    }

    $pairs = [];
    $summaryBits = [];

    if (isset($decoded['lines'])) {
        $lineCount = (int)$decoded['lines'];
        $pairs[] = ['label' => 'Lines', 'value' => (string)$lineCount];
        $summaryBits[] = $lineCount . ' line' . ($lineCount === 1 ? '' : 's');
    }
    if (isset($decoded['total'])) {
        $totalText = rh_pos_accounting_money_text((float)$decoded['total'], $currencySymbol);
        $pairs[] = ['label' => 'Total', 'value' => $totalText];
        $summaryBits[] = $totalText;
    }
    if (isset($decoded['method']) && trim((string)$decoded['method']) !== '') {
        $method = ucfirst(str_replace('_', ' ', (string)$decoded['method']));
        $pairs[] = ['label' => 'Method', 'value' => $method];
        $summaryBits[] = $method;
    }
    if (isset($decoded['table']) && trim((string)$decoded['table']) !== '') {
        $tableLabel = trim((string)$decoded['table']);
        $pairs[] = ['label' => 'Table', 'value' => $tableLabel];
        $summaryBits[] = 'Table ' . $tableLabel;
    }
    if (isset($decoded['till']) && trim((string)$decoded['till']) !== '') {
        $till = trim((string)$decoded['till']) === 'pos.php' ? 'POS Till' : trim((string)$decoded['till']);
        $pairs[] = ['label' => 'Source', 'value' => $till];
    }
    if (isset($decoded['close_id'])) {
        $pairs[] = ['label' => 'Close ID', 'value' => (string)((int)$decoded['close_id'])];
    }

    if ($pairs === []) {
        return ['summary' => 'Structured audit context captured.', 'pairs' => []];
    }

    return [
        'summary' => implode(' · ', $summaryBits),
        'pairs' => $pairs,
    ];
}

function rh_pos_accounting_insert_shift_close(
    PDO $pdo,
    array $closeData,
    bool $overrideApplied,
    ?string $overrideReason,
    ?string $ipAddress
): int {
    $cols = finance_table_columns($pdo, 'stock_shift_closes');

    $orderedValues = [
        'user_id' => (int)$closeData['user_id'],
        'user_name' => (string)$closeData['user_name'],
        'shift_date' => (string)$closeData['shift_date'],
        'expected_cash' => (float)$closeData['expected_cash'],
        'declared_cash' => (float)$closeData['declared_cash'],
        'variance_cash' => (float)$closeData['variance_cash'],
        'expected_mobile' => (float)$closeData['expected_mobile'],
        'declared_mobile' => (float)$closeData['declared_mobile'],
        'variance_mobile' => (float)$closeData['variance_mobile'],
        'expected_card' => (float)$closeData['expected_card'],
        'declared_card' => (float)$closeData['declared_card'],
        'variance_card' => (float)$closeData['variance_card'],
        'orders_count' => (int)$closeData['orders_count'],
        'voids_count' => (int)$closeData['voids_count'],
        'voids_amount' => (float)$closeData['voids_amount'],
        'notes' => $closeData['notes'],
    ];

    if (isset($cols['total_revenue'])) {
        $orderedValues['total_revenue'] = (float)$closeData['total_revenue'];
    }
    if (isset($cols['settled_from_tabs_count'])) {
        $orderedValues['settled_from_tabs_count'] = (int)$closeData['settled_from_tabs_count'];
    }
    if (isset($cols['settled_from_tabs_amount'])) {
        $orderedValues['settled_from_tabs_amount'] = (float)$closeData['settled_from_tabs_amount'];
    }
    if (isset($cols['override_applied'])) {
        $orderedValues['override_applied'] = $overrideApplied ? 1 : 0;
    }
    if (isset($cols['override_reason'])) {
        $orderedValues['override_reason'] = $overrideReason;
    }
    if (isset($cols['ip_address'])) {
        $orderedValues['ip_address'] = $ipAddress;
    }

    $columnNames = array_keys($orderedValues);
    $placeholders = implode(', ', array_fill(0, count($columnNames), '?'));
    $insertSql = "INSERT INTO stock_shift_closes (" . implode(', ', $columnNames) . ", closed_at) VALUES (" . $placeholders . ", NOW())";
    $stmt = $pdo->prepare($insertSql);
    $stmt->execute(array_values($orderedValues));

    return (int)$pdo->lastInsertId();
}

function rh_pos_accounting_log(PDO $pdo, int $orderId, ?int $actorId, ?string $actorName, string $event, ?string $details = null): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$orderId, $actorId, $actorName, $event, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        error_log('POS accounting audit log failed: ' . $e->getMessage());
    }
}

function rh_pos_accounting_shift_totals(PDO $pdo, int $userId, string $dayStart, string $dayEnd): array
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount + COALESCE(tip_amount,0) ELSE 0 END), 0) AS cash,
            COALESCE(SUM(CASE WHEN payment_method = 'mobile_money' THEN total_amount + COALESCE(tip_amount,0) ELSE 0 END), 0) AS mobile,
            COALESCE(SUM(CASE WHEN payment_method IN ('card_manual','card_pos') THEN total_amount + COALESCE(tip_amount,0) ELSE 0 END), 0) AS card,
            COUNT(*) AS orders_count,
            COALESCE(SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END), 0) AS settled_from_tabs_count,
            COALESCE(SUM(CASE WHEN created_at < ? THEN total_amount + COALESCE(tip_amount,0) ELSE 0 END), 0) AS settled_from_tabs_amount
        FROM stock_orders
        WHERE created_by = ?
          AND status = 'paid'
          AND ((paid_at IS NOT NULL AND paid_at BETWEEN ? AND ?) OR (paid_at IS NULL AND created_at BETWEEN ? AND ?))
    ");
    $stmt->execute([$dayStart, $dayStart, $userId, $dayStart, $dayEnd, $dayStart, $dayEnd]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $voidStmt = $pdo->prepare("
        SELECT COUNT(*) AS voids_count, COALESCE(SUM(total_amount), 0) AS voids_amount
        FROM stock_orders
        WHERE created_by = ?
          AND status = 'voided'
          AND ((voided_at IS NOT NULL AND voided_at BETWEEN ? AND ?) OR (voided_at IS NULL AND created_at BETWEEN ? AND ?))
    ");
    $voidStmt->execute([$userId, $dayStart, $dayEnd, $dayStart, $dayEnd]);
    $voids = $voidStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'cash' => (float)($totals['cash'] ?? 0),
        'mobile' => (float)($totals['mobile'] ?? 0),
        'card' => (float)($totals['card'] ?? 0),
        'orders_count' => (int)($totals['orders_count'] ?? 0),
        'settled_from_tabs_count' => (int)($totals['settled_from_tabs_count'] ?? 0),
        'settled_from_tabs_amount' => (float)($totals['settled_from_tabs_amount'] ?? 0),
        'voids_count' => (int)($voids['voids_count'] ?? 0),
        'voids_amount' => (float)($voids['voids_amount'] ?? 0),
    ];
}

function rh_pos_accounting_existing_closes(PDO $pdo, string $businessDate): array
{
    $stmt = $pdo->prepare("SELECT user_id, COUNT(*) AS close_count FROM stock_shift_closes WHERE shift_date = ? GROUP BY user_id");
    $stmt->execute([$businessDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row['user_id']] = (int)$row['close_count'];
    }
    return $map;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Please refresh and try again.';
    } else {
        $postedDate = $_POST['business_date'] ?? $businessDate;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $postedDate) && strtotime($postedDate)) {
            $businessDate = $postedDate;
            $dayWindow = rh_station_union_window_for_date($businessDate);
            $dayStart = $dayWindow['start_sql'];
            $dayEnd = $dayWindow['end_sql'];
        }

        try {
            $action = (string)($_POST['action'] ?? '');
            $selectedUserIds = array_values(array_unique(array_filter(array_map('intval', $_POST['user_ids'] ?? []))));
            if ($selectedUserIds === []) {
                throw new RuntimeException('Choose at least one POS user to close.');
            }

            $existingCloses = rh_pos_accounting_existing_closes($pdo, $businessDate);
            $closedCount = 0;
            $skippedAlreadyClosed = 0;
            $skippedNoOrders = 0;
            $pdo->beginTransaction();

            foreach ($selectedUserIds as $targetUserId) {
                if (($existingCloses[$targetUserId] ?? 0) > 0) {
                    $skippedAlreadyClosed++;
                    continue;
                }

                $nameStmt = $pdo->prepare("SELECT COALESCE(NULLIF(full_name, ''), username) FROM admin_users WHERE id = ?");
                $nameStmt->execute([$targetUserId]);
                $targetName = (string)($nameStmt->fetchColumn() ?: ('User #' . $targetUserId));
                $totals = rh_pos_accounting_shift_totals($pdo, $targetUserId, $dayStart, $dayEnd);

                if ($totals['orders_count'] <= 0 && $action !== 'close_empty_shift') {
                    $skippedNoOrders++;
                    continue;
                }

                $declaredCash = round((float)($_POST['declared_cash'][$targetUserId] ?? $totals['cash']), 2);
                $declaredMobile = round((float)($_POST['declared_mobile'][$targetUserId] ?? $totals['mobile']), 2);
                $declaredCard = round((float)($_POST['declared_card'][$targetUserId] ?? $totals['card']), 2);
                if ($declaredCash < 0 || $declaredMobile < 0 || $declaredCard < 0) {
                    throw new RuntimeException('Declared amounts cannot be negative.');
                }

                $noteInput = trim((string)($_POST['notes'][$targetUserId] ?? ''));
                $bulkNote = trim((string)($_POST['bulk_note'] ?? ''));
                $note = trim('Accountant manual close by ' . ($user['username'] ?? 'admin') . ($bulkNote !== '' ? ' | ' . $bulkNote : '') . ($noteInput !== '' ? ' | ' . $noteInput : ''));
                $varianceCash = round($declaredCash - $totals['cash'], 2);
                $varianceMobile = round($declaredMobile - $totals['mobile'], 2);
                $varianceCard = round($declaredCard - $totals['card'], 2);
                $totalRevenue = $totals['cash'] + $totals['mobile'] + $totals['card'];

                $closeId = rh_pos_accounting_insert_shift_close(
                    $pdo,
                    [
                        'user_id' => $targetUserId,
                        'user_name' => $targetName,
                        'shift_date' => $businessDate,
                        'expected_cash' => $totals['cash'],
                        'declared_cash' => $declaredCash,
                        'variance_cash' => $varianceCash,
                        'expected_mobile' => $totals['mobile'],
                        'declared_mobile' => $declaredMobile,
                        'variance_mobile' => $varianceMobile,
                        'expected_card' => $totals['card'],
                        'declared_card' => $declaredCard,
                        'variance_card' => $varianceCard,
                        'total_revenue' => $totalRevenue,
                        'orders_count' => $totals['orders_count'],
                        'settled_from_tabs_count' => $totals['settled_from_tabs_count'],
                        'settled_from_tabs_amount' => $totals['settled_from_tabs_amount'],
                        'voids_count' => $totals['voids_count'],
                        'voids_amount' => $totals['voids_amount'],
                        'notes' => $note,
                    ],
                    true,
                    $note,
                    $_SERVER['REMOTE_ADDR'] ?? null
                );
                rh_pos_accounting_log($pdo, 0, (int)($user['id'] ?? 0), (string)($user['full_name'] ?? $user['username'] ?? 'Admin'), 'accountant_shift_closed', json_encode([
                    'close_id' => $closeId,
                    'target_user_id' => $targetUserId,
                    'shift_date' => $businessDate,
                    'expected' => $totals,
                    'declared_cash' => $declaredCash,
                    'declared_mobile' => $declaredMobile,
                    'declared_card' => $declaredCard,
                ]));
                $closedCount++;
            }

            // Save per-order accountant notes — upsert (clear old entry, insert new) inside same transaction
            $orderNotesPost = is_array($_POST['order_notes'] ?? null) ? $_POST['order_notes'] : [];
            if ($orderNotesPost) {
                $delNoteStmt = $pdo->prepare("DELETE FROM stock_order_audit WHERE order_id = ? AND event = 'accountant_order_note'");
                $insNoteStmt = $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, 'accountant_order_note', ?, ?)");
                $noteActorId   = (int)($user['id'] ?? 0);
                $noteActorName = (string)($user['full_name'] ?? $user['username'] ?? 'Admin');
                $noteIp        = $_SERVER['REMOTE_ADDR'] ?? null;
                foreach ($orderNotesPost as $rawOid => $rawNote) {
                    $oidInt = (int)$rawOid;
                    if ($oidInt <= 0) continue;
                    $delNoteStmt->execute([$oidInt]);
                    $noteText = trim((string)$rawNote);
                    if ($noteText !== '') {
                        $insNoteStmt->execute([$oidInt, $noteActorId, $noteActorName, $noteText, $noteIp]);
                    }
                }
            }

            $pdo->commit();
            if (function_exists('rh_log_event')) {
                rh_log_event('admin/pos-accounting', 'info', 'POS shifts manually closed from accounting', [
                    'closed_count' => $closedCount,
                    'skipped_already_closed' => $skippedAlreadyClosed,
                    'skipped_no_orders' => $skippedNoOrders,
                    'business_date' => $businessDate,
                    'user_id' => $user['id'] ?? null,
                ]);
            }
            $message = $closedCount . ' POS shift' . ($closedCount === 1 ? '' : 's') . ' closed.';
            if ($skippedAlreadyClosed > 0 || $skippedNoOrders > 0) {
                $message .= ' Skipped - already closed: ' . $skippedAlreadyClosed
                    . ', no paid orders: ' . $skippedNoOrders . '.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$existingCloses = rh_pos_accounting_existing_closes($pdo, $businessDate);
$posUsers = [];
$orders = [];
$orderItemsByOrder = [];
$logs = [];
$summary = ['orders' => 0, 'paid_total' => 0.0, 'cash' => 0.0, 'mobile' => 0.0, 'card' => 0.0, 'voids' => 0, 'voided_total' => 0.0];

try {
    $usersStmt = $pdo->prepare("
        SELECT
            au.id,
            COALESCE(NULLIF(au.full_name, ''), au.username) AS display_name,
            au.username,
            COUNT(so.id) AS order_count,
            COALESCE(SUM(CASE WHEN so.status = 'paid' THEN so.total_amount ELSE 0 END), 0) AS paid_total,
            /* so.payment_method only ever holds the LAST split leg's tender, so a mixed-tender
             * split must be read from stock_order_splits (each leg's own method) instead —
             * otherwise the whole tab is misreported under one tender. */
            COALESCE(SUM(CASE
                WHEN so.status = 'paid' AND COALESCE(so.split_count,1) <= 1 AND so.payment_method = 'cash' THEN so.total_amount + COALESCE(so.tip_amount,0)
                WHEN so.status = 'paid' AND COALESCE(so.split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method='cash' THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = so.id), 0)
                ELSE 0 END), 0) AS expected_cash,
            COALESCE(SUM(CASE
                WHEN so.status = 'paid' AND COALESCE(so.split_count,1) <= 1 AND so.payment_method = 'mobile_money' THEN so.total_amount + COALESCE(so.tip_amount,0)
                WHEN so.status = 'paid' AND COALESCE(so.split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method='mobile_money' THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = so.id), 0)
                ELSE 0 END), 0) AS expected_mobile,
            COALESCE(SUM(CASE
                WHEN so.status = 'paid' AND COALESCE(so.split_count,1) <= 1 AND so.payment_method IN ('card_manual','card_pos') THEN so.total_amount + COALESCE(so.tip_amount,0)
                WHEN so.status = 'paid' AND COALESCE(so.split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method IN ('card_manual','card_pos') THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = so.id), 0)
                ELSE 0 END), 0) AS expected_card,
            COALESCE(SUM(CASE WHEN so.status = 'voided' THEN so.total_amount ELSE 0 END), 0) AS voided_total,
            COALESCE(SUM(CASE WHEN so.status = 'voided' THEN 1 ELSE 0 END), 0) AS voided_count,
            COALESCE(SUM(CASE WHEN so.status = 'placed' AND so.order_type != 'room_service' THEN 1 ELSE 0 END), 0) AS open_tabs
        FROM admin_users au
        INNER JOIN stock_orders so ON so.created_by = au.id
        WHERE so.created_at BETWEEN ? AND ? OR so.paid_at BETWEEN ? AND ? OR so.voided_at BETWEEN ? AND ?
        GROUP BY au.id, au.full_name, au.username
        ORDER BY paid_total DESC, display_name ASC
    ");
    $usersStmt->execute([$dayStart, $dayEnd, $dayStart, $dayEnd, $dayStart, $dayEnd]);
    $posUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ordersStmt = $pdo->prepare("
        SELECT so.id, so.reference, so.order_type, so.status, so.payment_method, so.total_amount,
               so.table_number, so.customer_name, so.paid_at, so.voided_at, so.created_at, so.created_by,
               COALESCE(NULLIF(au.full_name, ''), au.username) AS user_name
        FROM stock_orders so
        LEFT JOIN admin_users au ON au.id = so.created_by
        WHERE so.created_at BETWEEN ? AND ? OR so.paid_at BETWEEN ? AND ? OR so.voided_at BETWEEN ? AND ?
        ORDER BY COALESCE(so.paid_at, so.created_at) ASC
        LIMIT 500
    ");
    $ordersStmt->execute([$dayStart, $dayEnd, $dayStart, $dayEnd, $dayStart, $dayEnd]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Index orders by cashier for the per-cashier sales drawer
    $ordersByUser = [];
    foreach ($orders as $o) {
        $ordersByUser[(int)$o['created_by']][] = $o;
    }

    // Pre-load any existing accountant notes for individual orders on this date
    $orderNotes = [];
    if ($orders) {
        $orderIds        = array_column($orders, 'id');
        $noteHolders     = implode(',', array_fill(0, count($orderIds), '?'));
        $existingNoteStmt = $pdo->prepare(
            "SELECT order_id, details FROM stock_order_audit
              WHERE order_id IN ($noteHolders) AND event = 'accountant_order_note'
              ORDER BY id DESC"
        );
        $existingNoteStmt->execute($orderIds);
        foreach ($existingNoteStmt->fetchAll(PDO::FETCH_ASSOC) as $en) {
            $enOid = (int)$en['order_id'];
            if (!array_key_exists($enOid, $orderNotes)) {
                $orderNotes[$enOid] = (string)$en['details'];
            }
        }
    }

    // Pre-load line items so each sale can expand into a simple order list.
    if ($orders) {
        $orderIds = array_map('intval', array_column($orders, 'id'));
        $itemHolders = implode(',', array_fill(0, count($orderIds), '?'));
        $orderItemsStmt = $pdo->prepare(
            "SELECT order_id, item_name, quantity, notes, station, menu_type
               FROM stock_order_items
              WHERE order_id IN ($itemHolders)
              ORDER BY order_id ASC, id ASC"
        );
        $orderItemsStmt->execute($orderIds);

        foreach ($orderItemsStmt->fetchAll(PDO::FETCH_ASSOC) as $oi) {
            $oid = (int)($oi['order_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            if (!isset($orderItemsByOrder[$oid])) {
                $orderItemsByOrder[$oid] = [];
            }
            $orderItemsByOrder[$oid][] = [
                'name' => (string)($oi['item_name'] ?? ''),
                'qty' => (float)($oi['quantity'] ?? 0),
                'note' => trim((string)($oi['notes'] ?? '')),
                'station' => trim((string)($oi['station'] ?? '')),
                'menu_type' => trim((string)($oi['menu_type'] ?? '')),
            ];
        }
    }

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS orders,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) AS paid_total,
            /* payment_method only ever holds the LAST split leg's tender — see expected_cash
             * above for the same fix applied per-cashier. */
            COALESCE(SUM(CASE
                WHEN status = 'paid' AND COALESCE(split_count,1) <= 1 AND payment_method = 'cash' THEN total_amount + COALESCE(tip_amount,0)
                WHEN status = 'paid' AND COALESCE(split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method='cash' THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = stock_orders.id), 0)
                ELSE 0 END), 0) AS cash,
            COALESCE(SUM(CASE
                WHEN status = 'paid' AND COALESCE(split_count,1) <= 1 AND payment_method = 'mobile_money' THEN total_amount + COALESCE(tip_amount,0)
                WHEN status = 'paid' AND COALESCE(split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method='mobile_money' THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = stock_orders.id), 0)
                ELSE 0 END), 0) AS mobile,
            COALESCE(SUM(CASE
                WHEN status = 'paid' AND COALESCE(split_count,1) <= 1 AND payment_method IN ('card_manual','card_pos') THEN total_amount + COALESCE(tip_amount,0)
                WHEN status = 'paid' AND COALESCE(split_count,1) > 1 THEN COALESCE((SELECT SUM(CASE WHEN s.payment_method IN ('card_manual','card_pos') THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END) FROM stock_order_splits s WHERE s.order_id = stock_orders.id), 0)
                ELSE 0 END), 0) AS card,
            COALESCE(SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END), 0) AS voids,
            COALESCE(SUM(CASE WHEN status = 'voided' THEN total_amount ELSE 0 END), 0) AS voided_total
        FROM stock_orders
        WHERE created_at BETWEEN ? AND ? OR paid_at BETWEEN ? AND ? OR voided_at BETWEEN ? AND ?
    ");
    $summaryStmt->execute([$dayStart, $dayEnd, $dayStart, $dayEnd, $dayStart, $dayEnd]);
    $summary = array_merge($summary, $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $logsStmt = $pdo->prepare("
        SELECT soa.event, soa.details, soa.actor_name, soa.created_at, so.reference
        FROM stock_order_audit soa
        LEFT JOIN stock_orders so ON so.id = soa.order_id
        WHERE soa.created_at BETWEEN ? AND ?
        ORDER BY soa.created_at DESC
        LIMIT 80
    ");
    $logsStmt->execute([$dayStart, $dayEnd]);
    $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $error = $error ?: 'Unable to load POS accounting data.';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Accounting | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content finance-page">
        <div class="acct-page-header">
            <div class="acct-page-header__copy">
                <h1 class="acct-page-header__title">POS Accounting</h1>
                <p class="acct-page-header__subtitle">Review POS sales, match declared counts, and manually close cashier shifts from accounting.</p>
            </div>
            <form method="GET" class="acct-filter-form">
                <label class="acct-filter-field">
                    <span>Business date</span>
                    <input type="date" name="date" value="<?php echo htmlspecialchars($businessDate); ?>">
                </label>
                <button type="submit" class="acct-btn acct-btn--primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="shift-close-report.php?date=<?php echo urlencode($businessDate); ?>" class="acct-btn acct-btn--ghost"><i class="fas fa-print"></i> Z-report</a>
                <a href="pos-drift-report.php" class="acct-btn acct-btn--ghost" title="Read-only report quantifying historical tip/VAT and split-tender accounting drift"><i class="fas fa-magnifying-glass-chart"></i> Drift report</a>
            </form>
        </div>

        <?php if ($message): ?><div class="pos-acct-alert pos-acct-alert--success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="pos-acct-alert pos-acct-alert--danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-label">Paid POS sales</div>
                <div class="acct-kpi__value"><?php echo rh_pos_accounting_money((float)$summary['paid_total'], $currency_symbol); ?></div>
                <div class="stat-sub"><?php echo (int)$summary['orders']; ?> total order rows</div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">Cash expected</div>
                <div class="acct-kpi__value"><?php echo rh_pos_accounting_money((float)$summary['cash'], $currency_symbol); ?></div>
                <div class="stat-sub">Count and match drawers</div>
            </div>
            <div class="stat-card info">
                <div class="stat-label">Mobile / card</div>
                <div class="acct-kpi__value"><?php echo rh_pos_accounting_money((float)$summary['mobile'] + (float)$summary['card'], $currency_symbol); ?></div>
                <div class="stat-sub">Mobile <?php echo rh_pos_accounting_money((float)$summary['mobile'], $currency_symbol); ?> · Card <?php echo rh_pos_accounting_money((float)$summary['card'], $currency_symbol); ?></div>
            </div>
            <div class="stat-card warning">
                <div class="stat-label">Voids</div>
                <div class="stat-value"><?php echo (int)$summary['voids']; ?></div>
                <div class="stat-sub"><?php echo rh_pos_accounting_money((float)$summary['voided_total'], $currency_symbol); ?> voided</div>
            </div>
        </div>

        <form method="POST" class="section-card pos-acct-close-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="business_date" value="<?php echo htmlspecialchars($businessDate); ?>">
            <input type="hidden" name="action" value="close_shift">
            <div class="pos-acct-section-head">
                <div>
                    <h3><i class="fas fa-scale-balanced"></i> Cashier close matching</h3>
                    <p>Tick one or more balanced users, adjust declared counts if needed, add notes, then close selected shifts in bulk.</p>
                </div>
                <div class="pos-acct-bulk-actions">
                    <input type="text" name="bulk_note" placeholder="Bulk note for selected closes">
                    <button type="submit" class="acct-btn acct-btn--primary"><i class="fas fa-lock"></i> Close selected</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="pos-acct-table mobile-enhanced" id="pos-acct-match-table">
                    <thead>
                        <tr>
                            <th style="width:48px;text-align:center;" title="Select / deselect all eligible cashiers">
                                <label class="pos-acct-check-wrap">
                                    <input type="checkbox" id="pos-acct-select-all">
                                </label>
                            </th>
                            <th>Cashier</th>
                            <th>Orders</th>
                            <th>Expected</th>
                            <th>Declared cash</th>
                            <th>Declared mobile</th>
                            <th>Declared card</th>
                            <th>Variance</th>
                            <th>Status</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$posUsers): ?>
                            <tr>
                                <td colspan="10" class="pos-acct-empty">No POS sales found for this date.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($posUsers as $posUser):
                                $uid = (int)$posUser['id'];
                                $expectedCash = (float)$posUser['expected_cash'];
                                $expectedMobile = (float)$posUser['expected_mobile'];
                                $expectedCard = (float)$posUser['expected_card'];
                                $isClosed = ($existingCloses[$uid] ?? 0) > 0;
                                $hasOpenTabs = (int)$posUser['open_tabs'] > 0;
                                $userOrders = $ordersByUser[$uid] ?? [];
                                $userOrderCount = count($userOrders);
                                $drawerId = 'drawer-' . $uid;
                                $rowId = 'row-' . $uid;
                            ?>
                                <!-- Collapsible header row (visible on mobile/tablet by default) -->
                                <tr class="pos-acct-header-row" id="<?php echo $rowId; ?>-header" data-uid="<?php echo $uid; ?>" aria-expanded="false">
                                    <td colspan="10" class="pos-acct-header-cell">
                                        <div class="pos-acct-header-wrap">
                                            <label class="pos-acct-check-wrap" title="Select this cashier for shift close">
                                                <input type="checkbox" class="pos-acct-main-check" name="user_ids[]" value="<?php echo $uid; ?>" <?php echo $isClosed ? 'disabled' : ''; ?>>
                                            </label>
                                            <button type="button" class="pos-acct-row-toggle" data-uid="<?php echo $uid; ?>" aria-expanded="false" aria-controls="<?php echo $rowId; ?>-details" title="Expand to view/edit details">
                                                <i class="fas fa-chevron-right pos-acct-row-toggle-chevron"></i>
                                            </button>
                                            <div class="pos-acct-header-info">
                                                <strong><?php echo htmlspecialchars((string)$posUser['display_name']); ?></strong>
                                                <div class="stat-sub">@<?php echo htmlspecialchars((string)$posUser['username']); ?></div>
                                            </div>
                                            <div class="pos-acct-header-status">
                                                <?php if ($isClosed): ?><span class="pos-acct-pill pos-acct-pill--closed">Closed</span><?php elseif ($hasOpenTabs): ?><span class="pos-acct-pill pos-acct-pill--open">Ready</span> <span class="pos-acct-pill pos-acct-pill--warn" title="<?php echo (int)$posUser['open_tabs']; ?> order(s) still in progress"><?php echo (int)$posUser['open_tabs']; ?> open</span><?php else: ?><span class="pos-acct-pill pos-acct-pill--open">Ready</span><?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Detailed fields row (hidden by default, expanded on click) -->
                                <tr class="pos-acct-details-row" id="<?php echo $rowId; ?>-details" data-pos-acct-row
                                    data-expected-cash="<?php echo htmlspecialchars((string)$expectedCash); ?>"
                                    data-expected-mobile="<?php echo htmlspecialchars((string)$expectedMobile); ?>"
                                    data-expected-card="<?php echo htmlspecialchars((string)$expectedCard); ?>" aria-hidden="true">
                                    <td colspan="10">
                                        <div class="pos-acct-details-wrap">
                                            <div class="pos-acct-detail-grid">
                                                <div class="pos-acct-detail-cell">
                                                    <label class="pos-acct-detail-label">Orders</label>
                                                    <div class="pos-acct-detail-value"><?php echo (int)$posUser['order_count']; ?><div class="stat-sub">Voids: <?php echo (int)$posUser['voided_count']; ?></div>
                                                    </div>
                                                </div>
                                                <div class="pos-acct-detail-cell">
                                                    <label class="pos-acct-detail-label">Expected</label>
                                                    <div class="pos-acct-detail-value"><?php echo rh_pos_accounting_money((float)$posUser['paid_total'], $currency_symbol); ?><div class="stat-sub">Cash <?php echo rh_pos_accounting_money($expectedCash, $currency_symbol); ?></div>
                                                    </div>
                                                </div>
                                                <div class="pos-acct-detail-cell">
                                                    <label class="pos-acct-detail-label">Declared cash</label>
                                                    <input type="number" step="0.01" min="0" class="pos-acct-input" name="declared_cash[<?php echo $uid; ?>]" value="<?php echo htmlspecialchars(number_format($expectedCash, 2, '.', '')); ?>" data-declared="cash" title="Enter actual cash counted in the drawer">
                                                </div>
                                                <div class="pos-acct-detail-cell">
                                                    <label class="pos-acct-detail-label">Declared mobile</label>
                                                    <input type="number" step="0.01" min="0" class="pos-acct-input" name="declared_mobile[<?php echo $uid; ?>]" value="<?php echo htmlspecialchars(number_format($expectedMobile, 2, '.', '')); ?>" data-declared="mobile" title="Enter actual mobile money total">
                                                </div>
                                                <div class="pos-acct-detail-cell">
                                                    <label class="pos-acct-detail-label">Declared card</label>
                                                    <input type="number" step="0.01" min="0" class="pos-acct-input" name="declared_card[<?php echo $uid; ?>]" value="<?php echo htmlspecialchars(number_format($expectedCard, 2, '.', '')); ?>" data-declared="card" title="Enter actual card payment total">
                                                </div>
                                                <div class="pos-acct-detail-cell">
                                                    <label class="pos-acct-detail-label">Variance</label>
                                                    <div class="pos-acct-detail-value"><span class="pos-acct-variance" title="Difference between expected and declared totals">0.00</span></div>
                                                </div>
                                                <div class="pos-acct-detail-cell">
                                                    <label class="pos-acct-detail-label">Notes</label>
                                                    <input type="text" class="pos-acct-input" name="notes[<?php echo $uid; ?>]" placeholder="Count note" title="Optional note for this shift close (e.g. reason for any variance)">
                                                </div>
                                            </div>
                                            <?php if ($userOrderCount > 0): ?>
                                                <button type="button" class="pos-acct-drawer-toggle"
                                                    data-drawer="<?php echo $drawerId; ?>"
                                                    data-total="<?php echo $userOrderCount; ?>"
                                                    aria-expanded="false"
                                                    aria-controls="<?php echo $drawerId; ?>"
                                                    title="Review individual sales before closing — click to expand">
                                                    <i class="fas fa-chevron-right pos-acct-drawer-chevron"></i>
                                                    <span class="pos-acct-drawer-label"><?php echo $userOrderCount; ?> sale<?php echo $userOrderCount !== 1 ? 's' : ''; ?></span>
                                                    <span class="pos-acct-drawer-verified" style="display:none"> · <span class="pos-acct-drawer-verified-count">0</span> verified</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php if ($userOrderCount > 0): ?>
                                    <tr class="pos-acct-drawer" id="<?php echo $drawerId; ?>" aria-hidden="true">
                                        <td colspan="10">
                                            <div class="pos-acct-drawer__inner">
                                                <div class="pos-acct-drawer__head">
                                                    <span><i class="fas fa-list-check"></i> Individual sales — <?php echo htmlspecialchars((string)$posUser['display_name']); ?></span>
                                                    <span class="pos-acct-drawer__all-verified" style="display:none"><i class="fas fa-circle-check"></i> All verified</span>
                                                    <button type="button" class="pos-acct-drawer-tick-all" data-drawer="<?php echo $drawerId; ?>" title="Mark all sales in this list as reviewed — does not close the shift">Tick all</button>
                                                </div>
                                                <table class="pos-acct-drawer__table">
                                                    <thead>
                                                        <tr>
                                                            <th style="width:32px" title="Verified by accountant">✓</th>
                                                            <th>Time</th>
                                                            <th>Reference</th>
                                                            <th>Type</th>
                                                            <th>Table / Guest</th>
                                                            <th>Status</th>
                                                            <th>Method</th>
                                                            <th>Details</th>
                                                            <th>Note</th>
                                                            <th>Amount</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($userOrders as $uo):
                                                            $uoTime = $uo['paid_at'] ?? $uo['voided_at'] ?? $uo['created_at'];
                                                            $uoLabel = htmlspecialchars(str_replace('_', ' ', (string)$uo['order_type']));
                                                            $uoTable = trim(($uo['table_number'] ?? '') . ($uo['customer_name'] ? ' / ' . $uo['customer_name'] : ''));
                                                            $uoStatus = (string)$uo['status'];
                                                            $uoStatusClass = $uoStatus === 'paid' ? 'pos-acct-pill--open' : ($uoStatus === 'voided' ? 'pos-acct-pill--warn' : 'pos-acct-pill--closed');
                                                            $uoMethod = htmlspecialchars(str_replace('_', ' ', (string)$uo['payment_method']));
                                                            $uoItems = $orderItemsByOrder[(int)$uo['id']] ?? [];
                                                        ?>
                                                            <tr>
                                                                <td data-label="✓"><input type="checkbox" class="pos-acct-order-tick" data-drawer="<?php echo $drawerId; ?>"></td>
                                                                <td class="pos-acct-drawer__time" data-label="Time"><?php echo htmlspecialchars((string)$uoTime); ?></td>
                                                                <td data-label="Reference"><code><?php echo htmlspecialchars((string)($uo['reference'] ?? ('#' . $uo['id']))); ?></code></td>
                                                                <td data-label="Type"><?php echo $uoLabel; ?></td>
                                                                <td class="pos-acct-drawer__guest" data-label="Table / Guest"><?php echo htmlspecialchars($uoTable !== '' ? $uoTable : '—'); ?></td>
                                                                <td data-label="Status"><span class="pos-acct-pill <?php echo $uoStatusClass; ?>"><?php echo htmlspecialchars($uoStatus); ?></span></td>
                                                                <td data-label="Method"><?php echo $uoMethod; ?></td>
                                                                <td data-label="Details">
                                                                    <?php if ($uoItems): ?>
                                                                        <details class="pos-acct-sale-details">
                                                                            <summary><i class="fas fa-list-ul" aria-hidden="true"></i> <?php echo count($uoItems); ?> item<?php echo count($uoItems) !== 1 ? 's' : ''; ?></summary>
                                                                            <ul class="pos-acct-sale-details__list">
                                                                                <?php foreach ($uoItems as $line):
                                                                                    $lineName = trim((string)($line['name'] ?? ''));
                                                                                    $lineQtyRaw = (float)($line['qty'] ?? 0);
                                                                                    $lineQty = rtrim(rtrim(number_format($lineQtyRaw, 2, '.', ''), '0'), '.');
                                                                                    $lineQty = $lineQty !== '' ? $lineQty : '0';
                                                                                    $lineNote = trim((string)($line['note'] ?? ''));
                                                                                    $lineStation = trim((string)($line['station'] ?? ''));
                                                                                    $lineType = trim((string)($line['menu_type'] ?? ''));
                                                                                    $metaParts = [];
                                                                                    if ($lineStation !== '') {
                                                                                        $metaParts[] = ucfirst(str_replace('_', ' ', $lineStation));
                                                                                    }
                                                                                    if ($lineType !== '') {
                                                                                        $metaParts[] = str_replace('_', ' ', $lineType);
                                                                                    }
                                                                                    $lineMeta = implode(' · ', $metaParts);
                                                                                ?>
                                                                                    <li>
                                                                                        <span class="pos-acct-sale-details__qty"><?php echo htmlspecialchars($lineQty); ?>x</span>
                                                                                        <span class="pos-acct-sale-details__name"><?php echo htmlspecialchars($lineName !== '' ? $lineName : 'Unnamed item'); ?></span>
                                                                                        <?php if ($lineMeta !== ''): ?>
                                                                                            <span class="pos-acct-sale-details__meta"><?php echo htmlspecialchars($lineMeta); ?></span>
                                                                                        <?php endif; ?>
                                                                                        <?php if ($lineNote !== ''): ?>
                                                                                            <div class="pos-acct-sale-details__note">Note: <?php echo htmlspecialchars($lineNote); ?></div>
                                                                                        <?php endif; ?>
                                                                                    </li>
                                                                                <?php endforeach; ?>
                                                                            </ul>
                                                                        </details>
                                                                    <?php else: ?>
                                                                        <span class="pos-acct-sale-details__empty">No items</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td data-label="Note"><input type="text" class="pos-acct-order-note" name="order_notes[<?php echo (int)$uo['id']; ?>]" placeholder="Accountant note…" value="<?php echo htmlspecialchars($orderNotes[(int)$uo['id']] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isClosed ? ' readonly' : ''; ?> title="Add a note to this individual sale"></td>
                                                                <td data-label="Amount"><?php echo rh_pos_accounting_money((float)$uo['total_amount'], $currency_symbol); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot>
                                                        <tr class="pos-acct-drawer__total-row">
                                                            <td colspan="9" style="text-align:right; font-weight:700;">Total paid</td>
                                                            <td><?php echo rh_pos_accounting_money((float)$posUser['paid_total'], $currency_symbol); ?></td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <div class="section-grid">
            <div class="section-card">
                <h3><i class="fas fa-receipt"></i> POS sales log</h3>
                <div class="table-responsive pos-acct-sales-wrap">
                    <table class="pos-acct-table pos-acct-table--sales mobile-enhanced">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Ref</th>
                                <th>User</th>
                                <th>Status</th>
                                <th>Method</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td data-label="Time"><?php echo htmlspecialchars((string)($order['paid_at'] ?: $order['created_at'])); ?></td>
                                    <td data-label="Ref"><?php echo htmlspecialchars((string)($order['reference'] ?? ('#' . $order['id']))); ?></td>
                                    <td data-label="User"><?php echo htmlspecialchars((string)($order['user_name'] ?: '—')); ?></td>
                                    <td data-label="Status"><?php echo htmlspecialchars((string)$order['status']); ?></td>
                                    <td data-label="Method"><?php echo htmlspecialchars(str_replace('_', ' ', (string)$order['payment_method'])); ?></td>
                                    <td data-label="Total"><?php echo rh_pos_accounting_money((float)$order['total_amount'], $currency_symbol); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$orders): ?><tr>
                                    <td colspan="6" class="pos-acct-empty">No POS orders found.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="section-card">
                <h3><i class="fas fa-clock-rotate-left"></i> POS audit logs</h3>
                <div class="pos-acct-log-list">
                    <?php foreach ($logs as $log):
                        $detailView = rh_pos_accounting_log_details_view($log['details'] ?? null, $currency_symbol);
                    ?>
                        <article class="pos-acct-log-item">
                            <strong class="pos-acct-log-item__title"><?php echo htmlspecialchars(rh_pos_accounting_event_label((string)$log['event'])); ?></strong>
                            <span class="pos-acct-log-item__meta"><?php echo htmlspecialchars((string)$log['created_at']); ?> · <?php echo htmlspecialchars((string)($log['actor_name'] ?: 'System')); ?> · <?php echo htmlspecialchars((string)($log['reference'] ?: 'POS')); ?></span>
                            <p class="pos-acct-log-item__summary"><?php echo htmlspecialchars($detailView['summary'] !== '' ? $detailView['summary'] : 'No extra context captured.'); ?></p>
                            <?php if (!empty($detailView['pairs'])): ?>
                                <div class="pos-acct-log-tags">
                                    <?php foreach ($detailView['pairs'] as $pair): ?>
                                        <span class="pos-acct-log-tag"><strong><?php echo htmlspecialchars($pair['label']); ?>:</strong> <?php echo htmlspecialchars($pair['value']); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$logs): ?><p class="pos-acct-empty">No POS audit logs found for this date.</p><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php require_once 'includes/admin-footer.php'; ?>
    <script>
        (function() {
            'use strict';

            // ── Variance live update ────────────────────────────────────────────
            function updateVariance(row) {
                var expectedCash = parseFloat(row.dataset.expectedCash || '0');
                var expectedMobile = parseFloat(row.dataset.expectedMobile || '0');
                var expectedCard = parseFloat(row.dataset.expectedCard || '0');
                var cash = parseFloat((row.querySelector('[data-declared="cash"]') || {}).value || '0');
                var mobile = parseFloat((row.querySelector('[data-declared="mobile"]') || {}).value || '0');
                var card = parseFloat((row.querySelector('[data-declared="card"]') || {}).value || '0');
                var variance = (cash - expectedCash) + (mobile - expectedMobile) + (card - expectedCard);
                var output = row.querySelector('.pos-acct-variance');
                if (!output) return;
                output.textContent = (variance > 0 ? '+' : '') + variance.toFixed(2);
                output.classList.toggle('is-balanced', Math.abs(variance) < 0.01);
                output.classList.toggle('is-off', Math.abs(variance) >= 0.01);
            }
            document.querySelectorAll('[data-pos-acct-row]').forEach(function(row) {
                row.querySelectorAll('input[type="number"]').forEach(function(input) {
                    input.addEventListener('input', function() {
                        updateVariance(row);
                    });
                });
                updateVariance(row);
            });

            // ── Collapsible cashier row toggle ──────────────────────────────────
            document.querySelectorAll('.pos-acct-row-toggle').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var uid = btn.dataset.uid;
                    var detailsRow = document.getElementById('row-' + uid + '-details');
                    if (!detailsRow) return;
                    var isOpen = detailsRow.classList.toggle('is-open');
                    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    detailsRow.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
                    var headerRow = document.getElementById('row-' + uid + '-header');
                    if (headerRow) {
                        headerRow.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    }
                });
            });

            // ── Sales drawer toggle ─────────────────────────────────────────────
            function updateVerifiedCount(drawerId) {
                var drawer = document.getElementById(drawerId);
                if (!drawer) return;
                var ticks = drawer.querySelectorAll('.pos-acct-order-tick');
                var checked = drawer.querySelectorAll('.pos-acct-order-tick:checked').length;
                var total = ticks.length;
                // Update counter on the toggle button
                var toggle = document.querySelector('[data-drawer="' + drawerId + '"].pos-acct-drawer-toggle');
                if (toggle) {
                    var verifiedSpan = toggle.querySelector('.pos-acct-drawer-verified');
                    var countSpan = toggle.querySelector('.pos-acct-drawer-verified-count');
                    if (verifiedSpan && countSpan) {
                        countSpan.textContent = checked;
                        verifiedSpan.style.display = checked > 0 ? '' : 'none';
                    }
                }
                // Show "All verified" banner inside drawer when all ticked
                var allBanner = drawer.querySelector('.pos-acct-drawer__all-verified');
                if (allBanner) {
                    allBanner.style.display = (checked === total && total > 0) ? '' : 'none';
                }
            }

            document.querySelectorAll('.pos-acct-drawer-toggle').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var drawerId = btn.dataset.drawer;
                    var drawer = document.getElementById(drawerId);
                    if (!drawer) return;
                    var isOpen = drawer.classList.toggle('is-open');
                    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    drawer.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
                    var chevron = btn.querySelector('.pos-acct-drawer-chevron');
                    if (chevron) chevron.classList.toggle('is-open', isOpen);
                    // Show per-row close button while drawer is open
                    var row = btn.closest('tr');
                    var closeBtn = row ? row.querySelector('.pos-acct-row-close-btn') : null;
                    if (closeBtn) closeBtn.style.display = isOpen ? '' : 'none';
                });
            });

            // Per-order review tick
            document.addEventListener('change', function(e) {
                if (!e.target || !e.target.classList.contains('pos-acct-order-tick')) return;
                var drawerId = e.target.dataset.drawer;
                if (drawerId) updateVerifiedCount(drawerId);
            });

            // Main cashier checkbox → auto-tick all individual sales + open drawer
            document.addEventListener('change', function(e) {
                if (!e.target || !e.target.classList.contains('pos-acct-main-check')) return;
                var check = e.target;
                var uid = check.value;
                var drawerId = 'drawer-' + uid;
                var drawer = document.getElementById(drawerId);
                if (!drawer) return;
                var ticks = Array.from(drawer.querySelectorAll('.pos-acct-order-tick'));
                if (!ticks.length) return;

                if (check.checked) {
                    // Open the drawer so the user can see what was marked
                    if (!drawer.classList.contains('is-open')) {
                        drawer.classList.add('is-open');
                        drawer.setAttribute('aria-hidden', 'false');
                        var toggleBtn = document.querySelector('[data-drawer="' + drawerId + '"].pos-acct-drawer-toggle');
                        if (toggleBtn) {
                            toggleBtn.setAttribute('aria-expanded', 'true');
                            var chevron = toggleBtn.querySelector('.pos-acct-drawer-chevron');
                            if (chevron) chevron.classList.add('is-open');
                            var toggleRow = toggleBtn.closest('tr');
                            var closeBtn = toggleRow ? toggleRow.querySelector('.pos-acct-row-close-btn') : null;
                            if (closeBtn) closeBtn.style.display = '';
                        }
                    }
                    // Tick all individual sales
                    ticks.forEach(function(t) {
                        t.checked = true;
                    });
                    updateVerifiedCount(drawerId);
                    var tickAllBtn = drawer.querySelector('.pos-acct-drawer-tick-all');
                    if (tickAllBtn) tickAllBtn.textContent = 'Untick all';
                    // Brief flash to confirm the auto-action
                    var head = drawer.querySelector('.pos-acct-drawer__head');
                    if (head && !head.querySelector('.pos-acct-auto-tick-flash')) {
                        var flash = document.createElement('span');
                        flash.className = 'pos-acct-auto-tick-flash';
                        flash.textContent = ticks.length + ' sale' + (ticks.length !== 1 ? 's' : '') + ' auto-marked ✔';
                        head.appendChild(flash);
                        setTimeout(function() {
                            if (flash.parentNode) flash.parentNode.removeChild(flash);
                        }, 3000);
                    }
                } else {
                    // Unchecked — clear individual sale ticks
                    ticks.forEach(function(t) {
                        t.checked = false;
                    });
                    updateVerifiedCount(drawerId);
                    var tickAllBtnOff = drawer.querySelector('.pos-acct-drawer-tick-all');
                    if (tickAllBtnOff) tickAllBtnOff.textContent = 'Tick all';
                }
            });

            // "Tick all" button inside each drawer
            document.querySelectorAll('.pos-acct-drawer-tick-all').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var drawerId = btn.dataset.drawer;
                    var drawer = document.getElementById(drawerId);
                    if (!drawer) return;
                    var ticks = drawer.querySelectorAll('.pos-acct-order-tick');
                    var allOn = Array.from(ticks).every(function(t) {
                        return t.checked;
                    });
                    ticks.forEach(function(t) {
                        t.checked = !allOn;
                    });
                    btn.textContent = allOn ? 'Tick all' : 'Untick all';
                    updateVerifiedCount(drawerId);
                });
            });

            // Row-level "Close shift" button — checks the cashier and submits
            document.querySelectorAll('.pos-acct-row-close-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var uid = btn.dataset.uid;
                    var mainCheck = table ? table.querySelector('.pos-acct-main-check[value="' + uid + '"]') : null;
                    if (mainCheck && !mainCheck.disabled) {
                        mainCheck.checked = true;
                        syncSelectAllState();
                    }
                    var form = btn.closest('form');
                    if (form) form.submit();
                });
            });

            // ── Select-all cashier checkbox ─────────────────────────────────────
            var selectAll = document.getElementById('pos-acct-select-all');
            var table = document.getElementById('pos-acct-match-table');
            var mainChecks = table ? Array.from(table.querySelectorAll('.pos-acct-main-check:not([disabled])')) : [];

            function syncSelectAllState() {
                if (!selectAll || !mainChecks.length) return;
                var checkedCount = mainChecks.filter(function(c) {
                    return c.checked;
                }).length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < mainChecks.length;
                selectAll.checked = checkedCount === mainChecks.length;
            }

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    mainChecks.forEach(function(c) {
                        c.checked = selectAll.checked;
                    });
                });
            }

            mainChecks.forEach(function(c) {
                c.addEventListener('change', syncSelectAllState);
            });

            syncSelectAllState();

        }());
    </script>
</body>

</html>

