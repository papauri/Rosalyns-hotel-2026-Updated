<?php
/**
 * Maintenance room timeline range data (read-only JSON). GET start=Y-m-d&days=3..31.
 * Same payload as the Maintenance page's first paint (admin/includes/ops-timeline-data.php rh_ops_payload).
 * Read-only: never runs the page's room-status sweep.
 * Auth: admin session (api-init.php answers 401 without one) + the Maintenance page permission and module (403).
 */

require_once __DIR__ . '/api-init.php'; // 401 JSON when not signed in

requireApiPermission('room_maintenance');      // 403 without the Maintenance permission
rhApiModuleGate('room-maintenance.php');      // 403 when the page's module is switched off

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false, 'error' => 'Use GET.']);
    exit;
}

require_once __DIR__ . '/../includes/ops-timeline-data.php';

$start = rh_cal_valid_date($_GET['start'] ?? '');
if ($start === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Pick a valid start date.']);
    exit;
}
$days = filter_var($_GET['days'] ?? 14, FILTER_VALIDATE_INT, ['options' => ['min_range' => 3, 'max_range' => 31]]);
if ($days === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Days must be between 3 and 31.']);
    exit;
}

try {
    $payload = rh_ops_payload($pdo, 'maintenance', $start, (int)$days, [
        'user_id' => (int)($user['id'] ?? 0),
    ]);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('maintenance-timeline: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The maintenance timeline could not be loaded. Try again.']);
}
