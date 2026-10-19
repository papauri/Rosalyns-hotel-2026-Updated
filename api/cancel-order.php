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
require_once __DIR__ . '/../admin/includes/restaurant-payment-sync.php';
require_once __DIR__ . '/../admin/includes/pos-notices.php';
require_once __DIR__ . '/../admin/includes/pos-order-close.php';
require_once __DIR__ . '/../includes/admin-session.php';
rh_admin_session_start(); // 8h idle sign-out

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

    $ordStmt = $pdo->prepare("SELECT id, reference, status, order_type, created_by, kitchen_status, COALESCE(split_paid_count, 0) AS split_paid_count FROM stock_orders WHERE id = ? FOR UPDATE");
    $ordStmt->execute([$orderId]);
    $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        cjerr('Order not found', 404);
    }
    /* Cancel is for an accidental fire caught before any money moved: the order must be 'placed'
     * AND completely unpaid. A paid (or part-paid) order is money in the till and the ledger -
     * it has to go through Void (pos_void permission), which writes the reversal record. */
    if ((string)$order['status'] !== 'placed') {
        $pdo->rollBack();
        cjerr('Order ' . $order['reference'] . ' is ' . str_replace('_', ' ', (string)$order['status']) . ' - only an open, unpaid order can be cancelled. Use Void for a paid order.');
    }
    $paidChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL AND COALESCE(payment_status, 'completed') = 'completed'");
    $paidChk->execute([$orderId]);
    if ((int)$order['split_paid_count'] > 0 || (int)$paidChk->fetchColumn() > 0) {
        $pdo->rollBack();
        cjerr('Order ' . $order['reference'] . ' has payment recorded against it - it cannot be cancelled. A manager must void it (this writes the reversal).');
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

    // Shared close (stock restore, folio charges, order + lines, ready pings, audit, KDS event):
    // admin/includes/pos-order-close.php. Booking balances are re-derived here, inside the transaction.
    $closed = rh_close_pos_order($pdo, $orderId, $userId, $details, 'cancel');
    $folioVoided = (int)$closed['folio_voided'];
    foreach ($closed['booking_ids'] as $closedBookingId) {
        recalculateBookingFinancials((int)$closedBookingId);
    }

    $pdo->commit();

    /* Tell the cashier who opened the order that it was cancelled from another screen. The notice is
     * actionable on the till (Mark as read / Open order / ...): see admin/includes/pos-notices.php. */
    foreach ($closed['notices'] as $notice) {
        try {
            rh_pos_notice_send($pdo, $notice);
        } catch (Throwable $e) {
            error_log('cancel-order POS notify: ' . $e->getMessage());
            // Non-fatal - cancel is committed, notification is best-effort
        }
    }

    if (function_exists('deleteCache')) {
        try {
            rh_clear_stock_dashboard_cache();
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
