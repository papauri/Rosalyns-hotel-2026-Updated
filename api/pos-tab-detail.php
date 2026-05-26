<?php
/**
 * api/pos-tab-detail.php
 *
 * Returns full detail for a single stock order: order info, line items (with
 * KDS timestamps), KDS event log, and order audit trail.
 *
 * GET  ?order_id=<int>
 * Auth: valid admin session (any role with POS access).
 *       restaurant_staff may only view their own orders.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function ptderr(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/* Auth */
if (empty($_SESSION['admin_user'])) ptderr('Not authenticated', 401);
/** @var array $user */
$user = $_SESSION['admin_user'];

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) ptderr('Missing or invalid order_id');

try {
    /* Order */
    $stmt = $pdo->prepare(
        "SELECT o.*, u.full_name AS opened_by_name
         FROM stock_orders o
         LEFT JOIN admin_users u ON u.id = o.created_by
         WHERE o.id = ?"
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) ptderr('Order not found', 404);

    /* Scope: restaurant_staff can only see their own orders */
    if (($user['role'] ?? '') === 'restaurant_staff' && (int)$order['created_by'] !== (int)$user['id']) {
        ptderr('Forbidden', 403);
    }

    /* Line items */
    $itemsStmt = $pdo->prepare(
        "SELECT i.id, i.item_name, i.quantity, i.unit_price, i.line_total, i.notes,
                i.kds_status, i.menu_type, i.station, i.stock_deducted,
                i.started_at, i.ready_at, i.served_at, i.created_at
         FROM stock_order_items i
         WHERE i.order_id = ?
         ORDER BY i.id"
    );
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    /* KDS events */
    $kdsStmt = $pdo->prepare(
        "SELECT e.id, e.order_item_id, e.event, e.from_status, e.to_status,
                e.user_name, e.created_at,
                i.item_name
         FROM stock_kds_events e
         LEFT JOIN stock_order_items i ON i.id = e.order_item_id
         WHERE e.order_id = ?
         ORDER BY e.created_at ASC, e.id ASC"
    );
    $kdsStmt->execute([$orderId]);
    $kdsEvents = $kdsStmt->fetchAll(PDO::FETCH_ASSOC);

    /* Order audit */
    $auditStmt = $pdo->prepare(
        "SELECT id, event, actor_name, details, created_at
         FROM stock_order_audit
         WHERE order_id = ?
         ORDER BY created_at ASC, id ASC"
    );
    $auditStmt->execute([$orderId]);
    $auditEvents = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'      => true,
        'order'        => $order,
        'items'        => $items,
        'kds_events'   => $kdsEvents,
        'audit_events' => $auditEvents,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[api/pos-tab-detail] ' . $e->getMessage());
    rh_log_event('api/pos-tab-detail', 'error', $e->getMessage(), ['order_id' => $orderId]);
    ptderr('Server error', 500);
}
