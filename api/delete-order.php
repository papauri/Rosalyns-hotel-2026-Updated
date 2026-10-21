<?php

/**
 * api/delete-order.php - permanently delete a restaurant / POS order.
 *
 *   POST: csrf_token, order_id, delete_reason (>= 8 characters)
 *   Auth: admin session whose user holds the `pos_delete_order` permission (re-checked here AND
 *         inside rh_delete_pos_order()). Any order, at any time - open, paid, voided, cancelled,
 *         in a closed shift. Used by Orders & Tabs (admin/stock-orders.php) and the POS
 *         "Today's orders" window (admin/pos.php).
 *
 * All the work (stock put back, payments / folio charges removed, child rows, audit record) is the
 * single helper rh_delete_pos_order() in admin/includes/pos-order-delete.php.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../admin/includes/permissions.php';
require_once __DIR__ . '/../admin/includes/pos-order-delete.php';
require_once __DIR__ . '/../includes/admin-session.php';
rh_admin_session_start(); // 8h idle sign-out

function dj_err(string $m, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $m]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    dj_err('POST only', 405);
}
if (empty($_SESSION['admin_user'])) {
    dj_err('Not authenticated', 401);
}
$csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!validateCsrfToken($csrfToken)) {
    dj_err('Invalid CSRF token', 403);
}

$userId = (int)($_SESSION['admin_user']['id'] ?? 0);
if ($userId <= 0 || !hasPermission($userId, 'pos_delete_order')) {
    dj_err('You do not have permission to delete orders.', 403);
}
rhApiModuleGate('pos.php'); // blocked when the till/POS module is switched off, as the page is

$orderId = (int)($_POST['order_id'] ?? 0);
$reason = trim((string)($_POST['delete_reason'] ?? ''));
if ($orderId <= 0) {
    dj_err('Missing order_id');
}
if (mb_strlen($reason) < 8) {
    dj_err('A reason of at least 8 characters is required to delete an order.');
}

try {
    $result = rh_delete_pos_order($pdo, $orderId, $userId, $reason);
    echo json_encode($result);
} catch (RuntimeException $e) {
    $code = (int)$e->getCode();
    dj_err($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
} catch (Throwable $e) {
    error_log('api/delete-order: ' . $e->getMessage());
    dj_err('Could not delete the order.', 500);
}
