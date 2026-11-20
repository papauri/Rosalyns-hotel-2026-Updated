<?php
/**
 * Room Service timeline day data (read-only JSON). GET start=Y-m-d (one day; missing = today).
 * Same payload as the Room Service page's first paint (admin/includes/ops-timeline-data.php rh_ops_payload, mode service).
 * Auth: admin session (api-init.php answers 401 without one) + the Room Service page permission and module (403).
 * The Delivered action stays the page's own POST (mark_delivered, CSRF, room_service_manage); this file never writes.
 */

require_once __DIR__ . '/api-init.php'; // 401 JSON when not signed in

requireApiPermission('room_service_view');        // 403 without the Room Service page permission
rhApiModuleGate('room-service-dashboard.php');    // 403 when the page's module (POS / room service station) is off

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false, 'error' => 'Use GET.']);
    exit;
}

require_once __DIR__ . '/../includes/ops-timeline-data.php';

$day = rh_ops_rs_request_day($_GET, date('Y-m-d'));
if ($day === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Pick a valid day.']);
    exit;
}

try {
    $payload = rh_ops_payload($pdo, 'service', $day, 1, [
        'user_id' => (int)($user['id'] ?? 0),
    ]);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('room-service-timeline: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The room service timeline could not be loaded. Try again.']);
}
