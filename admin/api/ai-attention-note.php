<?php
/**
 * Dashboard "Needs attention" note (POST action=get_note + csrf_token) -> {success: true, note: "..."} ('' = show nothing).
 * includes/ai/ai-attention-note.php has the rules.
 *
 * Order of checks: signed in (api-init: 401) -> Assistant on (fail-closed kill switch: 404, as if the endpoint did not
 * exist) -> POST + action -> CSRF (403) -> ai_assistant_use (403) -> the rest of the note's gate (cloud on, key, model:
 * otherwise an empty note). The items are the copy the Dashboard kept in this person's session when it drew the panel;
 * nothing is taken from the request. Each item's gate is worked out again for this person (an item they can no longer
 * see is dropped). The session is closed before the model call, so the rest of the portal never waits on it.
 * action=get_note is a read (licence guard + staff activity: never recorded as work).
 */
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/api-init.php';
/** @var array $user */
/** @var PDO $pdo */
require_once __DIR__ . '/../../includes/ai/ai-attention-note.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rh_ai_an_api_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!rh_ai_enabled($pdo) || !rh_ai_chat_ready($pdo)) {
    rh_ai_an_api_out(404, ['success' => false, 'error' => 'Not found']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || (string)($_POST['action'] ?? '') !== 'get_note') {
    rh_ai_an_api_out(405, ['success' => false, 'error' => 'Use POST.']);
}
if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    rh_ai_an_api_out(403, ['success' => false, 'error' => 'Your session has changed. Reload the page.']);
}
$uid = (int)($user['id'] ?? 0);
if ($uid <= 0 || !hasPermission($uid, 'ai_assistant_use')) {
    rh_ai_an_api_out(403, ['success' => false, 'error' => 'You do not have access to the Assistant.']);
}
$stored = rh_ai_an_stored($uid, $_SESSION[RH_AI_AN_SESSION] ?? null);
session_write_close(); // reads only from here on; the model call must never hold this person's session

if ($stored === null || !rh_ai_an_gate($pdo, $uid)) {
    rh_ai_an_api_out(200, ['success' => true, 'note' => '']);
}
try {
    require_once __DIR__ . '/../includes/dashboard-attention.php';
    $gates = rh_dash_attention_gates(static function (string ...$keys) use ($uid): bool {
        foreach ($keys as $k) {
            if (hasPermission($uid, $k)) {
                return true;
            }
        }
        return false;
    }, static function (string $k): bool {
        return function_exists('rh_module_key_enabled') ? rh_module_key_enabled($k) : moduleEnabled($k);
    });
    $items = rh_ai_an_permitted($stored, $gates);
    rh_ai_an_engine();
    $r = rh_ai_an_note($pdo, $uid, $items);
} catch (Throwable $e) {
    error_log('admin/api/ai-attention-note.php: ' . $e->getMessage());
    rh_ai_an_api_out(200, ['success' => true, 'note' => '']);
}
rh_ai_an_api_out(200, ['success' => true, 'note' => (string)$r['note']]);
