<?php
/**
 * The Decisions panel's endpoint (.claude/AI_REVIEW_PANELS_PARKED.md, Phase 1).
 *
 * POST only, JSON {success, ...}. Checks in order: signed in (api-init: 401) -> master switch on (rh_ai_enabled(),
 * fail-closed: 404) -> security token (403) -> the panel's own gate for the area: ai_assistant_use + the area's
 * permission + its module (admin/includes/review-panel.php, 403) -> action.
 *   refresh  works the area out again (at most once in ai_review_refresh_minutes) and returns the panel's markup
 *   note     records "Noted" / "Not now" on one of today's cards in the proof log
 * Nothing here writes to bookings, money, stock, prices or settings: only the ai_review_* tables.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/api-init.php';
/** @var array $user */
/** @var PDO $pdo */
require_once __DIR__ . '/../../includes/ai/ai-core.php';
require_once __DIR__ . '/../includes/review-panel.php';

function rh_dec_api_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if (!rh_ai_enabled($pdo)) {
    rh_dec_api_out(404, ['success' => false, 'error' => 'Not found']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    rh_dec_api_out(405, ['success' => false, 'error' => 'Method not allowed']);
}
$in = $_POST;
if (!$in) {
    $j = json_decode((string)file_get_contents('php://input'), true);
    $in = is_array($j) ? $j : [];
}
if (!validateCsrfToken((string)($in['csrf_token'] ?? ''))) {
    rh_dec_api_out(403, ['success' => false, 'error' => 'Security token invalid. Refresh the page.']);
}
$uid = (int)($user['id'] ?? ($_SESSION['admin_user_id'] ?? 0));
$area = (string)($in['area'] ?? '');
if (!rh_review_gate($area, $pdo, $uid)) {
    rh_dec_api_out(403, ['success' => false, 'error' => 'Your account cannot open this.']);
}
require_once __DIR__ . '/../../includes/ai/ai-decisions.php';
if (!rh_dec_ready($pdo)) {
    rh_dec_api_out(404, ['success' => false, 'error' => 'Not found']);
}
$me = ['id' => $uid, 'role' => (string)($user['role'] ?? ''), 'username' => (string)($user['username'] ?? ''), 'full_name' => (string)($user['full_name'] ?? '')];

try {
    switch ((string)($in['action'] ?? '')) {
        case 'refresh':
            // The wording call may take a few seconds: never hold this person's session meanwhile (other pages and
            // in-app navigation would wait on it). The session is only read from here on.
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
            }
            @ignore_user_abort(true);
            $r = rh_dec_refresh($pdo, $area, true, $uid);
            $html = rh_review_panel($area);
            rh_dec_api_out(200, ['success' => true, 'status' => $r['status'], 'html' => $html]);
            break;
        case 'note':
            $what = (string)($in['what'] ?? '');
            if (!in_array($what, ['noted', 'dismissed'], true)) {
                rh_dec_api_out(400, ['success' => false, 'error' => 'Unknown choice.']);
            }
            $card = rh_dec_card_for($pdo, $me, $area, (string)($in['card_key'] ?? ''));
            if ($card === null) {
                rh_dec_api_out(404, ['success' => false, 'error' => 'That decision is no longer on today\'s list. Refresh the page.']);
            }
            $id = rh_dec_proof_log($pdo, $card, $what, $uid);
            rh_dec_api_out($id > 0 ? 200 : 500, $id > 0 ? ['success' => true, 'label' => $what === 'noted' ? 'Noted' : 'Not now'] : ['success' => false, 'error' => 'Could not be saved. Try again.']);
            break;
        default:
            rh_dec_api_out(400, ['success' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('Decisions endpoint: ' . $e->getMessage());
    rh_dec_api_out(500, ['success' => false, 'error' => 'Something went wrong. Try again.']);
}
