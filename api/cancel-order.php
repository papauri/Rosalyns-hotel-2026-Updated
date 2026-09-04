<?php

/**
 * api/cancel-order.php
 *
 * Fully cancel a restaurant order only when preparation has NOT started.
 *
 * Rules:
 * - Order must not already be cancelled/voided
 * - No item may be in preparing/in_progress/ready/collection/served
 * - Intended for accidental fires caught before kitchen/bar starts work
 *
 * Effects:
 * - Restores deducted stock via the original POS stock-adjustment trail
 * - Marks order status = cancelled
 * - Marks open KDS items as void so they disappear from station boards
 * - Cancels linked payment rows (if any)
 * - Writes audit + KDS lifecycle events
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../admin/includes/permissions.php';
require_once __DIR__ . '/../admin/includes/offline-log.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function cjerr(string $m, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $m]);
    exit;
}

function cjok(array $extra = []): void
{
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

function c_restore_from_pos_order(PDO $pdo, int $orderId, ?int $doneBy): void
{
    $byBatch = [];
    $byIngredient = [];

    $sel = $pdo->prepare("SELECT sa.id AS adjustment_id, sa.ingredient_id, sa.quantity_change, sbd.batch_id, sbd.quantity_deducted FROM stock_adjustments sa LEFT JOIN stock_batch_deductions sbd ON sbd.adjustment_id = sa.id WHERE sa.source_type = 'pos_order' AND sa.source_id = ?");
    $sel->execute([$orderId]);

    $seenAdjustments = [];
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adjustmentId = (int)$row['adjustment_id'];
        if (!isset($seenAdjustments[$adjustmentId])) {
            $seenAdjustments[$adjustmentId] = true;
            $ingredientId = (int)$row['ingredient_id'];
            $byIngredient[$ingredientId] = ($byIngredient[$ingredientId] ?? 0) + abs((float)$row['quantity_change']);
        }

        if (!empty($row['batch_id'])) {
            $batchId = (int)$row['batch_id'];
            $byBatch[$batchId] = ($byBatch[$batchId] ?? 0) + (float)$row['quantity_deducted'];
        }
    }

    if ($byBatch) {
        $batchUpd = $pdo->prepare("UPDATE stock_batches SET quantity_remaining = quantity_remaining + ?, status = CASE WHEN status='depleted' THEN 'active' ELSE status END, updated_at = NOW() WHERE id = ?");
        foreach ($byBatch as $batchId => $qty) {
            $batchUpd->execute([$qty, $batchId]);
        }
    }

    $costSel = $pdo->prepare("SELECT cost_per_unit FROM stock_ingredients WHERE id = ?");
    $adjIns = $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by) VALUES (?, ?, 'POS order cancelled before prep', 'void_restore', ?, ?, ?)");
    $ingUpd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ?, updated_at = NOW() WHERE id = ?");

    foreach ($byIngredient as $ingredientId => $qty) {
        $costSel->execute([$ingredientId]);
        $cpu = (float)($costSel->fetchColumn() ?: 0);
        $adjIns->execute([$ingredientId, $qty, $orderId, $cpu, $doneBy]);
        $ingUpd->execute([$qty, $ingredientId]);
    }
}

function c_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function c_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!c_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function c_ensure_station_messages_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS station_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        station VARCHAR(30) NOT NULL,
        message VARCHAR(255) NOT NULL,
        sent_by INT UNSIGNED NULL,
        sent_by_name VARCHAR(120) NOT NULL DEFAULT '',
        source VARCHAR(20) NOT NULL DEFAULT 'pos',
        is_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
        acknowledged_by INT UNSIGNED NULL,
        acknowledged_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_station_messages_station_created (station, created_at),
        INDEX idx_station_messages_ack (is_acknowledged)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    c_ensure_column($pdo, 'station_messages', 'priority', "ENUM('normal','urgent') NOT NULL DEFAULT 'normal'");
    c_ensure_column($pdo, 'station_messages', 'seen_at', 'DATETIME NULL');
    c_ensure_column($pdo, 'station_messages', 'reply_message', 'VARCHAR(255) NULL');
    c_ensure_column($pdo, 'station_messages', 'replied_at', 'DATETIME NULL');
    c_ensure_column($pdo, 'station_messages', 'replied_by_name', 'VARCHAR(120) NULL');
    c_ensure_column($pdo, 'station_messages', 'order_id', 'INT UNSIGNED NULL');
    c_ensure_column($pdo, 'station_messages', 'order_ref', 'VARCHAR(40) NULL');
    c_ensure_column($pdo, 'station_messages', 'to_user_id', 'INT UNSIGNED NULL');
    c_ensure_column($pdo, 'station_messages', 'pos_acknowledged', 'TINYINT(1) NOT NULL DEFAULT 0');
    c_ensure_column($pdo, 'station_messages', 'pos_acknowledged_at', 'DATETIME NULL');
    c_ensure_column($pdo, 'station_messages', 'pos_acknowledged_by', 'INT UNSIGNED NULL');
}

function c_void_room_service_folio_charges(PDO $pdo, int $orderId, string $reason, int $voidedBy): int
{
    if (!c_column_exists($pdo, 'booking_charges', 'stock_order_id')) {
        error_log("booking_charges.stock_order_id missing; room-service folio charges not voided for stock order {$orderId}");
        // Graceful fallback: match void-order.php behaviour — don't block cancellation
        return 0;
    }

    $stmt = $pdo->prepare("SELECT id, booking_id, charge_type, source_item_id, quantity, stock_tracked FROM booking_charges WHERE stock_order_id = ? AND voided = 0 FOR UPDATE");
    $stmt->execute([$orderId]);
    $charges = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$charges) {
        return 0;
    }

    // If stock was already restored via the POS adjustment trail (source_type='pos_order'),
    // don't restore again from the folio charge path.
    $posAdjStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'pos_order' AND source_id = ?");
    $posAdjStmt->execute([$orderId]);
    $stockAlreadyRestoredViaPosPath = (int)$posAdjStmt->fetchColumn() > 0;

    $update = $pdo->prepare("UPDATE booking_charges SET voided = 1, voided_at = NOW(), void_reason = ?, voided_by = ?, updated_at = NOW() WHERE id = ?");
    $bookingIds = [];
    foreach ($charges as $charge) {
        $chargeId = (int)$charge['id'];
        $update->execute([mb_substr($reason, 0, 255), $voidedBy, $chargeId]);
        // Restore stock if it was deducted via the room_service path and not yet restored
        if (!$stockAlreadyRestoredViaPosPath
            && !empty($charge['stock_tracked'])
            && in_array((string)$charge['charge_type'], ['food', 'drink'], true)
            && !empty($charge['source_item_id'])
        ) {
            try {
                restoreStockForMenuItem((int)$charge['source_item_id'], (string)$charge['charge_type'], (float)$charge['quantity'], 'Room service order cancelled: ' . $reason, $voidedBy, $chargeId);
            } catch (Throwable $se) {
                error_log("c_void_room_service_folio_charges stock restore failed for charge {$chargeId}: " . $se->getMessage());
            }
        }
        $bookingIds[(int)$charge['booking_id']] = true;
    }

    foreach (array_keys($bookingIds) as $bookingId) {
        recalculateBookingFinancials((int)$bookingId);
    }

    return count($charges);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cjerr('POST only', 405);
}
if (empty($_SESSION['admin_user'])) {
    cjerr('Not authenticated', 401);
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    cjerr('Invalid CSRF token', 403);
}

$user = $_SESSION['admin_user'];
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');
$isPrivileged = in_array($userRole, ['admin', 'manager'], true);
$canOperate = $isPrivileged
    || hasPermission($userId, 'pos_till')
    || hasPermission($userId, 'kds_view')
    || hasPermission($userId, 'bds_view')
    || hasPermission($userId, 'cds_view')
    || hasPermission($userId, 'stock_orders');

if (!$canOperate) {
    cjerr('Forbidden', 403);
}

$orderId = (int)($_POST['order_id'] ?? 0);
$reason = trim((string)($_POST['cancel_reason'] ?? ''));
$notes = trim((string)($_POST['cancel_notes'] ?? ''));
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

if ($orderId <= 0) {
    cjerr('Missing order_id');
}
if (mb_strlen($reason) < 8) {
    cjerr('Cancel reason is required (at least 8 characters)');
}

$details = $reason . ($notes !== '' ? "\nNotes: " . $notes : '');

try {
    $pdo->beginTransaction();

    $ordStmt = $pdo->prepare("SELECT id, reference, status, order_type, created_by, kitchen_status FROM stock_orders WHERE id = ? FOR UPDATE");
    $ordStmt->execute([$orderId]);
    $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        cjerr('Order not found', 404);
    }
    if (in_array((string)$order['status'], ['cancelled', 'voided'], true)) {
        $pdo->rollBack();
        cjerr('Order already reversed');
    }

    // Non-privileged users can cancel only their own order or one tied to their station permissions.
    if (!$isPrivileged && (int)$order['created_by'] !== $userId) {
        $allowedStations = [];
        if (hasPermission($userId, 'kds_view')) {
            $allowedStations[] = 'kitchen';
        }
        if (hasPermission($userId, 'bds_view')) {
            $allowedStations[] = 'bar';
        }
        if (hasPermission($userId, 'cds_view')) {
            $allowedStations[] = 'coffee_bar';
        }

        if (!$allowedStations) {
            $pdo->rollBack();
            cjerr('You can only cancel your own orders', 403);
        }

        $pl = implode(',', array_fill(0, count($allowedStations), '?'));
        $vis = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id = ? AND station IN ($pl)");
        $vis->execute(array_merge([$orderId], $allowedStations));
        if ((int)$vis->fetchColumn() === 0) {
            $pdo->rollBack();
            cjerr('Order not visible to your station', 403);
        }
    }

    $itemStmt = $pdo->prepare("SELECT id, kds_status FROM stock_order_items WHERE order_id = ? FOR UPDATE");
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        $pdo->rollBack();
        cjerr('Order has no items', 400);
    }

    $progressedStatuses = ['preparing', 'in_progress', 'ready', 'collection', 'served'];
    foreach ($items as $item) {
        if (in_array((string)$item['kds_status'], $progressedStatuses, true)) {
            $pdo->rollBack();
            cjerr('Cannot cancel: preparation has already started');
        }
    }
    if (in_array((string)$order['kitchen_status'], ['in_progress', 'ready', 'served'], true)) {
        $pdo->rollBack();
        cjerr('Cannot cancel: order is already in progress');
    }

    c_restore_from_pos_order($pdo, $orderId, $userId);
    $folioVoided = 0;
    if (($order['order_type'] ?? '') === 'room_service') {
        $folioVoided = c_void_room_service_folio_charges($pdo, $orderId, 'Room-service order cancelled before prep: ' . $details, $userId);
    }

    $pdo->prepare("UPDATE stock_orders SET status='cancelled', voided_by=?, voided_at=NOW(), void_reason=?, updated_at=NOW(), kitchen_status='served', served_at=COALESCE(served_at, NOW()) WHERE id=?")
        ->execute([$userId, mb_substr($details, 0, 500), $orderId]);

    $pdo->prepare("UPDATE stock_order_items SET kds_status='void', served_at=COALESCE(served_at, NOW()), bumped_by=? WHERE order_id=? AND kds_status NOT IN ('served','void')")
        ->execute([$userId, $orderId]);

    $pdo->prepare("UPDATE payments SET payment_status='cancelled', status='failed', notes=CONCAT(COALESCE(notes,''), '\nCANCELLED-BEFORE-PREP: ', ?), updated_at=NOW() WHERE booking_type='restaurant' AND booking_id=? AND payment_type<>'refund' AND deleted_at IS NULL")
        ->execute([$details, $orderId]);

    /* Retract any outstanding collection ping / unacknowledged station note — a cancelled
     * order must stop asking the floor to collect it (see the same cleanup in void-order.php). */
    try {
        $pdo->prepare("DELETE FROM pos_ready_notifications WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE station_messages SET pos_acknowledged = 1, pos_acknowledged_at = NOW(), pos_acknowledged_by = ? WHERE order_id = ? AND source = 'station' AND COALESCE(pos_acknowledged, 0) = 0")
            ->execute([$userId, $orderId]);
    } catch (Throwable $e) {
        error_log('cancel-order notification cleanup: ' . $e->getMessage());
    }

    $actorName = (string)($user['full_name'] ?? $user['username'] ?? 'user');
    $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, 'cancelled_before_prep', ?, ?)")
        ->execute([$orderId, $userId, $actorName, $details, $ip]);

    try {
        $pdo->prepare("INSERT INTO stock_kds_events (order_id, event, from_status, to_status, user_id, user_name, ip_address) VALUES (?, 'cancelled', ?, 'void', ?, ?, ?)")
            ->execute([$orderId, (string)$order['kitchen_status'], $userId, $actorName, $ip]);
    } catch (Throwable $e) {
        // Keep cancellation resilient on older event schemas.
    }

    $pdo->commit();

    // Notify POS cashier that their order was cancelled from a station
    if ((int)($order['created_by'] ?? 0) > 0 && (int)$order['created_by'] !== $userId) {
        try {
            c_ensure_station_messages_table($pdo);
            $cancellerName = (string)($user['full_name'] ?? $user['username'] ?? 'station');
            $roleLabel = match ($userRole) {
                'chef'         => 'Kitchen',
                'bar_staff'    => 'Bar',
                'coffee_staff' => 'Coffee Bar',
                default        => ucfirst($userRole),
            };
            $notifMsg = 'Order ' . (string)$order['reference'] . ' was CANCELLED by ' . $roleLabel . ' (' . $cancellerName . '): ' . $reason;
            $pdo->prepare("INSERT INTO station_messages (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref, to_user_id, is_acknowledged, acknowledged_at) VALUES ('kitchen', ?, ?, ?, 'station', 'urgent', ?, ?, ?, 0, NULL)")
                ->execute([mb_substr($notifMsg, 0, 255), $userId, $cancellerName, $orderId, (string)$order['reference'], (int)$order['created_by']]);
        } catch (Throwable $e) {
            error_log('cancel-order POS notify: ' . $e->getMessage());
            // Non-fatal — cancel is committed, notification is best-effort
        }
    }

    if (function_exists('deleteCache')) {
        try {
            deleteCache('stock_dashboard_metrics_v1');
        } catch (Throwable $e) {
        }
    }

    if (function_exists('rh_log_event')) {
        rh_log_event('api/cancel-order', 'warning', 'Order cancelled before prep', [
            'order_id' => $orderId,
            'reference' => (string)$order['reference'],
            'user' => (string)($user['username'] ?? ''),
            'role' => $userRole,
        ]);
    }

    if (function_exists('rh_log_offline_replay')) {
        rh_log_offline_replay($pdo, '/api/cancel-order.php', [
            'action' => 'cancel_order_before_prep',
            'entity_type' => 'stock_order',
            'entity_id' => $orderId,
            'entity_reference' => $order['reference'] ?? null,
            'response_status' => 200,
            'response_summary' => 'Order cancelled before prep + stock/folio restored',
            'details' => ['reason' => $reason],
        ]);
    }

    cjok([
        'order_id' => $orderId,
        'reference' => $order['reference'],
        'message' => 'Order ' . $order['reference'] . ' cancelled before prep. Stock restored.' . ($folioVoided > 0 ? " {$folioVoided} room folio charge(s) voided." : ''),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cjerr($e->getMessage(), 500);
}
