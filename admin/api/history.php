<?php
/**
 * History panel data (GET, read-only): admin/api/history.php?type=booking|payment&id=N
 * The step-by-step list of everything that happened to one booking or one payment (includes/booking-history.php).
 * Same view rights as the pages: 'bookings' for a booking, 'payments' for a payment, and the page's module switch.
 * Writes nothing.
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../../includes/booking-history.php';

/** @var PDO $pdo */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use GET.']);
    exit;
}

$type = (string)($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);
if (!in_array($type, ['booking', 'payment'], true) || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Choose a booking or a payment.']);
    exit;
}

if ($type === 'booking') {
    requireApiPermission('bookings');
    rhApiModuleGate('booking-details.php');
} else {
    requireApiPermission('payments');
    rhApiModuleGate('payment-details.php');
}

try {
    $data = rh_history_payload($pdo, $type, $id);
} catch (Throwable $e) {
    error_log('admin/api/history.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'The history could not be loaded. Please try again.']);
    exit;
}

if (empty($data['found'])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => $type === 'booking' ? 'Booking not found.' : 'Payment not found.']);
    exit;
}

$events = [];
foreach ($data['events'] as $e) {
    $events[] = ['at' => $e['at'], 'at_label' => $e['at_label'], 'who' => $e['who'], 'what' => $e['what'],
        'detail' => $e['detail'], 'kind' => $e['kind'], 'kind_label' => $e['kind_label']];
}
echo json_encode([
    'success' => true,
    'title' => $data['title'],
    'facts' => $data['facts'],
    'events' => $events,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
