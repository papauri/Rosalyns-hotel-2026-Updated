<?php
/**
 * End of Day "The day in brief" (POST action=get_brief + csrf_token + date) -> {success: true, text: "..."|null, points: [...]}.
 * includes/ai/ai-eod-narrative.php has the rules.
 *
 * Order of checks: signed in (api-init: 401) -> Assistant on (fail-closed kill switch: 404, as if the endpoint did not
 * exist) -> POST + action -> CSRF (403) -> reports + ai_assistant_use (403) -> the rest of the gate (cloud on, key, model:
 * otherwise text null). The figures are the copy the End of Day page kept in this person's session for that date; the
 * request names only the date and never carries a figure. The session is closed before the model call.
 * action=get_brief is a read (never recorded as work).
 */
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/api-init.php';
/** @var array $user */
/** @var PDO $pdo */
require_once __DIR__ . '/../../includes/ai/ai-eod-narrative.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rh_ai_eod_api_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!rh_ai_enabled($pdo) || !rh_ai_chat_ready($pdo)) {
    rh_ai_eod_api_out(404, ['success' => false, 'error' => 'Not found']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || (string)($_POST['action'] ?? '') !== 'get_brief') {
    rh_ai_eod_api_out(405, ['success' => false, 'error' => 'Use POST.']);
}
if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    rh_ai_eod_api_out(403, ['success' => false, 'error' => 'Your session has changed. Reload the page.']);
}
$uid = (int)($user['id'] ?? 0);
if ($uid <= 0 || !hasPermission($uid, 'reports') || !hasPermission($uid, 'ai_assistant_use')) {
    rh_ai_eod_api_out(403, ['success' => false, 'error' => 'You do not have access to this report.']);
}
$date = (string)($_POST['date'] ?? '');
$slot = $_SESSION[RH_AI_EOD_SESSION] ?? null;
session_write_close(); // reads only from here on; the model call must never hold this person's session

$r = rh_ai_eod_for_request($pdo, $uid, $date, $slot, rh_ai_eod_can_for($uid));
rh_ai_eod_api_out(200, ['success' => true, 'text' => $r['text'], 'points' => $r['points']]);
