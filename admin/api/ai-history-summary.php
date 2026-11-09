<?php
/**
 * The History panel's "Summary" (GET, read-only): admin/api/ai-history-summary.php?type=booking|payment&id=N
 * -> {success: true, sentences: [...]} (three to five plain sentences, includes/ai/ai-history.php).
 *
 * Shown only while the Assistant is available to this person. Order of checks: signed in (api-init: 401) -> Assistant on
 * (fail-closed kill switch: 404 when off, as if the endpoint did not exist) -> GET -> a booking or payment id ->
 * ai_assistant_use (403) -> the History panel's own view rights ('bookings' or 'payments', and the page's module switch).
 * The panel stays exactly as it was on any answer but success. Local only: no model call, no tokens, writes nothing.
 * Nothing to cache: the sentences are copied from the steps, which have to be read first anyway.
 */
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/api-init.php';
/** @var array $user */
/** @var PDO $pdo */
require_once __DIR__ . '/../../includes/ai/ai-core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rh_ai_hist_api_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!rh_ai_enabled($pdo) || !rh_ai_chat_ready($pdo)) {
    rh_ai_hist_api_out(404, ['success' => false, 'error' => 'Not found']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    rh_ai_hist_api_out(405, ['success' => false, 'error' => 'Use GET.']);
}
$type = (string)($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);
if (!in_array($type, ['booking', 'payment'], true) || $id <= 0) {
    rh_ai_hist_api_out(400, ['success' => false, 'error' => 'Choose a booking or a payment.']);
}
$uid = (int)($user['id'] ?? 0);
if ($uid <= 0 || !hasPermission($uid, 'ai_assistant_use')) {
    rh_ai_hist_api_out(403, ['success' => false, 'error' => 'You do not have access to the Assistant.']);
}
// The same view rights as the History panel itself (admin/api/history.php)
if ($type === 'booking') {
    requireApiPermission('bookings');
    rhApiModuleGate('booking-details.php');
} else {
    requireApiPermission('payments');
    rhApiModuleGate('payment-details.php');
}
session_write_close(); // reads only from here on

try {
    require_once __DIR__ . '/../../includes/booking-history.php';
    require_once __DIR__ . '/../../includes/ai/ai-history.php';
    $events = $type === 'booking' ? rh_booking_history($pdo, $id) : rh_payment_history($pdo, $id);
    $sentences = rh_ai_history_summary($events, true);
} catch (Throwable $e) {
    error_log('admin/api/ai-history-summary.php: ' . $e->getMessage());
    rh_ai_hist_api_out(500, ['success' => false, 'error' => 'No summary just now.']);
}
rh_ai_hist_api_out(200, ['success' => true, 'sentences' => $sentences]);
