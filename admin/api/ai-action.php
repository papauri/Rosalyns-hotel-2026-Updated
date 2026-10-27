<?php
/**
 * Assistant action cards endpoint (.claude/AI_ASSISTANT_PLAN.md Phase 4).
 *
 * POST only, JSON {success, ...}. Checks in order: signed in (api-init: 401) -> Assistant on (fail-closed kill
 * switch: 404) -> security token -> ai_assistant_use + ai_assistant_actions (403) -> action.
 *   propose  one tap on a briefing point prepares a card (check-out, check-in, assign room) without a model call
 *   confirm  owner, still proposed, not expired, permissions still held, preview re-run (a changed price or
 *            availability gives a new card); single use under a row lock; returns the replay steps the browser
 *            posts to the EXISTING screen endpoint with the person's own session and security token
 *   report   the screen's JSON answer; the database decides executed / failed; activity log "via Assistant"
 *   cancel   drop a card that was not confirmed
 *   card     one card's current state
 * Nothing here writes to bookings, money, stock or settings: only ai_actions (and the outcome line in the chat).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/api-init.php';
/** @var array $user */
/** @var PDO $pdo */
require_once __DIR__ . '/../../includes/ai/ai-agents.php';
require_once __DIR__ . '/../../includes/ai/ai-actions.php';

function rh_ai_act_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if (!rh_ai_enabled($pdo) || !rh_ai_chat_ready($pdo)) {
    rh_ai_act_out(404, ['success' => false, 'error' => 'Not found']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    rh_ai_act_out(405, ['success' => false, 'error' => 'Method not allowed']);
}
$in = $_POST;
if (!$in) {
    $j = json_decode((string)file_get_contents('php://input'), true);
    $in = is_array($j) ? $j : [];
}
if (!validateCsrfToken((string)($in['csrf_token'] ?? ''))) {
    rh_ai_act_out(403, ['success' => false, 'error' => 'Security token invalid. Refresh the page.']);
}
$uid = (int)($user['id'] ?? 0);
if ($uid <= 0 || !hasPermission($uid, 'ai_assistant_use') || !hasPermission($uid, 'ai_assistant_actions')) {
    rh_ai_act_out(403, ['success' => false, 'error' => 'Your account cannot confirm Assistant actions.']);
}
if (rh_ai_idle_locked(true)) { // Phase 4c: idle lock (unlock is on api/ai-chat.php)
    rh_ai_act_out(423, ['success' => false, 'locked' => true, 'error' => 'The Assistant locked after a quiet spell. Type your password to carry on.']);
}
if (!rh_ai_actions_enabled()) {
    rh_ai_act_out(200, ['success' => false, 'error' => 'Assistant action cards are switched off.']);
}
$me = ['id' => $uid, 'role' => (string)($user['role'] ?? ''), 'username' => (string)($user['username'] ?? ''), 'full_name' => (string)($user['full_name'] ?? '')];
$cardId = (int)($in['card_id'] ?? 0);
$jsonArg = static function (string $k) use ($in): ?array {
    $v = $in[$k] ?? null;
    if (is_array($v)) {
        return $v;
    }
    $d = is_string($v) ? json_decode($v, true) : null;
    return is_array($d) ? $d : null;
};

try {
    switch ((string)($in['action'] ?? '')) {
        case 'propose':
            $key = (string)($in['key'] ?? '');
            if (!in_array($key, rh_ai_tap_actions(), true)) {
                rh_ai_act_out(400, ['success' => false, 'error' => 'That cannot be prepared from here.']);
            }
            $conv = (int)($in['conversation_id'] ?? 0);
            if (!rh_ai_conversation($pdo, $uid, $conv)) {
                $conv = rh_ai_conversation_create($pdo, $uid, (string)($in['page'] ?? ''));
            }
            $r = rh_ai_propose($pdo, $me, $conv, $key, ['booking_id' => (int)($in['booking_id'] ?? 0)], false);
            rh_ai_act_out(200, $r['ok'] ? ['success' => true, 'conversation_id' => $conv, 'card' => $r['card']] : ['success' => false, 'error' => $r['error']]);
            break;

        case 'confirm':
            $r = rh_ai_confirm($pdo, $me, $cardId, $jsonArg('fields') ?? [], (string)($in['password'] ?? '')); // risky cards check the password
            rh_ai_act_out(200, $r);
            break;

        case 'report':
            $r = rh_ai_report($pdo, $me, $cardId, $jsonArg('result'), ['guard_failed' => (string)($in['guard_failed'] ?? '') === '1']);
            rh_ai_act_out(200, $r);
            break;

        case 'cancel':
            rh_ai_act_out(200, rh_ai_cancel_card($pdo, $me, $cardId));
            break;

        case 'card':
            $row = rh_ai_card_row($pdo, $uid, $cardId);
            rh_ai_act_out(200, $row ? ['success' => true, 'card' => rh_ai_card_public($pdo, $cardId, $row)] : ['success' => false, 'error' => 'That card was not found.']);
            break;

        default:
            rh_ai_act_out(400, ['success' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('Assistant action endpoint: ' . $e->getMessage());
    rh_ai_act_out(200, ['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
