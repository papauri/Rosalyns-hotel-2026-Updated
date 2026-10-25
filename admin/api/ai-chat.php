<?php
/**
 * Assistant chat endpoint (.claude/AI_ASSISTANT_PLAN.md Phase 2).
 *
 * POST only, JSON answers {success, ...}. Order of checks: signed in (api-init: 401) -> Assistant on
 * (fail-closed kill switch: 404 when off, as if the endpoint did not exist) -> security token ->
 * permission ai_assistant_use (403) -> rate limit (429) -> action. The session is closed before any
 * provider call so the rest of the portal never waits on the Assistant.
 *
 * Actions: send, list, load, rename, archive (own conversations only), prefs_get, prefs_set, suggestions,
 * briefing (the daily "full tea"; auto=1 only on the first open of the day when the person's preference is on)
 * and status (the plain line naming the step the Assistant is on, polled while it answers).
 * Nothing here writes to bookings, money, stock or settings; the provider key never appears in output.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/api-init.php';
/** @var array $user */
/** @var PDO $pdo */
require_once __DIR__ . '/../../includes/ai/ai-agents.php';

function rh_ai_api_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if (!rh_ai_enabled($pdo) || !rh_ai_chat_ready($pdo)) {
    rh_ai_api_out(404, ['success' => false, 'error' => 'Not found']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    rh_ai_api_out(405, ['success' => false, 'error' => 'Method not allowed']);
}

// Form fields, or a JSON body
$in = $_POST;
if (!$in) {
    $raw = (string)file_get_contents('php://input');
    $j = json_decode($raw, true);
    $in = is_array($j) ? $j : [];
}
if (!validateCsrfToken((string)($in['csrf_token'] ?? ''))) {
    rh_ai_api_out(403, ['success' => false, 'error' => 'Security token invalid. Refresh the page.']);
}
$uid = (int)($user['id'] ?? 0);
if ($uid <= 0 || !hasPermission($uid, 'ai_assistant_use')) {
    rh_ai_api_out(403, ['success' => false, 'error' => 'You do not have access to the Assistant.']);
}
$me = ['id' => $uid, 'role' => (string)($user['role'] ?? ''), 'username' => (string)($user['username'] ?? ''), 'full_name' => (string)($user['full_name'] ?? '')];
$action = (string)($in['action'] ?? '');

try {
    switch ($action) {
        case 'send':
            $problem = rh_ai_rate_problem($pdo, $uid);
            if ($problem !== '') {
                rh_ai_api_out(429, ['success' => false, 'error' => $problem]);
            }
            session_write_close(); // never hold the session lock while a provider thinks
            @set_time_limit(120);
            $r = rh_ai_turn($pdo, $me, [
                'conversation_id' => (int)($in['conversation_id'] ?? 0),
                'message' => (string)($in['message'] ?? ''),
                'page' => (string)($in['page'] ?? ''),
                'booking_id' => (int)($in['booking_id'] ?? 0),
            ]);
            if (!$r['ok']) {
                rh_ai_api_out(200, ['success' => false, 'error' => $r['error'], 'conversation_id' => $r['conversation_id']]);
            }
            rh_ai_api_out(200, [
                'success' => true, 'conversation_id' => $r['conversation_id'], 'reply' => $r['reply'], 'route' => $r['route'],
                'links' => $r['links'], 'chips' => $r['chips'], 'items' => $r['items'], 'notice' => $r['notice'],
                'usage' => ['tokens_in' => $r['tokens_in'], 'tokens_out' => $r['tokens_out'], 'cost_usd' => $r['cost_usd']],
            ]);
            break;

        case 'list':
            $st = $pdo->prepare("SELECT id, title, updated_at FROM ai_conversations WHERE user_id = ? AND archived = 0 ORDER BY updated_at DESC, id DESC LIMIT 30");
            $st->execute([$uid]);
            $list = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $title = rh_ai_vault_rehydrate($pdo, (int)$c['id'], (string)$c['title']);
                $list[] = ['id' => (int)$c['id'], 'title' => $title !== '' ? $title : 'New chat', 'updated_at' => (string)$c['updated_at']];
            }
            rh_ai_api_out(200, ['success' => true, 'conversations' => $list]);
            break;

        case 'load':
            $conv = (int)($in['conversation_id'] ?? 0);
            if (!rh_ai_conversation($pdo, $uid, $conv)) {
                rh_ai_api_out(404, ['success' => false, 'error' => 'That conversation was not found.']);
            }
            rh_ai_api_out(200, ['success' => true, 'conversation_id' => $conv, 'messages' => rh_ai_conversation_messages($pdo, $conv)]);
            break;

        case 'rename':
            $conv = (int)($in['conversation_id'] ?? 0);
            $title = trim((string)($in['title'] ?? ''));
            if (!rh_ai_conversation($pdo, $uid, $conv) || $title === '') {
                rh_ai_api_out(200, ['success' => false, 'error' => 'That conversation was not found.']);
            }
            $pdo->prepare("UPDATE ai_conversations SET title = ? WHERE id = ? AND user_id = ?")
                ->execute([rh_ai_title_from(rh_ai_scrub_outbound($pdo, $conv, mb_substr($title, 0, 80))), $conv, $uid]);
            rh_ai_api_out(200, ['success' => true]);
            break;

        case 'archive':
            $conv = (int)($in['conversation_id'] ?? 0);
            if (!rh_ai_conversation($pdo, $uid, $conv)) {
                rh_ai_api_out(200, ['success' => false, 'error' => 'That conversation was not found.']);
            }
            $pdo->prepare("UPDATE ai_conversations SET archived = 1 WHERE id = ? AND user_id = ?")->execute([$conv, $uid]);
            rh_ai_api_out(200, ['success' => true]);
            break;

        case 'prefs_get':
            rh_ai_api_out(200, ['success' => true, 'prefs' => rh_ai_prefs_get($pdo, $uid)]);
            break;

        case 'prefs_set':
            rh_ai_api_out(200, ['success' => true, 'prefs' => rh_ai_prefs_set($pdo, $uid, [
                'length' => (string)($in['length'] ?? ''), 'tone' => (string)($in['tone'] ?? ''),
                'language' => (string)($in['language'] ?? ''), 'nickname' => (string)($in['nickname'] ?? ''),
                'briefing' => isset($in['briefing']) ? (string)$in['briefing'] : null,
            ])]);
            break;

        case 'briefing':
            $auto = (string)($in['auto'] ?? '') === '1';
            if (!$auto) {
                $problem = rh_ai_rate_problem($pdo, $uid);
                if ($problem !== '') {
                    rh_ai_api_out(429, ['success' => false, 'error' => $problem]);
                }
            }
            session_write_close();
            @set_time_limit(120);
            $b = rh_ai_briefing($pdo, $me, $auto);
            if (!$b['ok']) {
                rh_ai_api_out(200, ['success' => false, 'error' => $b['error']]);
            }
            rh_ai_api_out(200, ['success' => true, 'shown' => $b['shown'], 'conversation_id' => $b['conversation_id'], 'reply' => $b['reply'], 'items' => $b['items'], 'chips' => $b['chips'], 'route' => 'briefing']);
            break;

        case 'status':
            session_write_close();
            rh_ai_api_out(200, ['success' => true, 'step' => rh_ai_step_get($pdo, $uid)]);
            break;

        case 'suggestions':
            rh_ai_api_out(200, ['success' => true, 'chips' => rh_ai_suggestions($me, (string)($in['page'] ?? ''))]);
            break;

        default:
            rh_ai_api_out(400, ['success' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('Assistant chat endpoint: ' . $e->getMessage());
    rh_ai_api_out(200, ['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
