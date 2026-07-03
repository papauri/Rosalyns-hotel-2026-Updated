<?php
declare(strict_types=1);

/**
 * Gym check-in scanner endpoint.
 *
 * POST actions:
 *   action=scan     code=GM-XXXXXX  method=barcode|manual → toggle check-in/out
 *   action=checkout attendance_id=N                       → manual check-out
 *   action=snapshot                                       → in-gym + today lists
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../includes/gym-checkin-lib.php';
/** @var array $user */
/** @var PDO $pdo */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token invalid. Refresh the page.']);
    exit;
}

requireApiPermission('gym_checkin');

if (!function_exists('moduleEnabled') || !moduleEnabled('gym')) {
    echo json_encode(['success' => false, 'error' => 'The gym module is disabled for this installation.']);
    exit;
}

$action  = (string)($_POST['action'] ?? 'scan');
$adminId = (int)($user['id'] ?? 0);

if ($action === 'scan') {
    $code   = (string)($_POST['code'] ?? '');
    $method = (string)($_POST['method'] ?? 'barcode');
    $result = gym_checkin_process($pdo, $code, $adminId, $method);
    $result['success']  = !in_array($result['outcome'], ['error', 'pending_migration'], true);
    $result['snapshot'] = gym_checkin_snapshot($pdo);
    echo json_encode($result);
    exit;
}

if ($action === 'checkout') {
    $result = gym_checkin_force_checkout($pdo, (int)($_POST['attendance_id'] ?? 0), $adminId);
    $result['success']  = $result['outcome'] === 'checked_out';
    $result['snapshot'] = gym_checkin_snapshot($pdo);
    echo json_encode($result);
    exit;
}

if ($action === 'snapshot') {
    echo json_encode(['success' => true, 'snapshot' => gym_checkin_snapshot($pdo)]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action.']);
exit;
