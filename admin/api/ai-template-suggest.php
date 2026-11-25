<?php
/**
 * Email Templates "Suggest wording" (POST action=get_suggestions + csrf_token + X-Requested-With, template, subject,
 * html_body, tone) -> {success: true, versions: [{subject, html, preview_text, missing_tags, tone}], message}.
 * tone is whitelisted (rh_ai_ts_tone(): mixed, friendly, concise, firm, formal, apologetic; anything else = mixed).
 * includes/ai/ai-template-suggest.php has the rules.
 *
 * Order of checks: signed in (api-init: 401) -> the Assistant is connected (kill switch, chat tables, cloud on, key,
 * Fast or Primary model; otherwise 404, as if the endpoint did not exist) -> POST + action (405) -> CSRF +
 * X-Requested-With (403) -> ai_assistant_use + edit_templates (403) -> a known email template -> the per-person limit.
 * Nothing is saved: the page only fills its own fields with a version the person picks. The session is closed before
 * the model call. action=get_suggestions is a read (licence guard + staff activity: never recorded as work).
 */
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/api-init.php';
/** @var array $user */
/** @var PDO $pdo */
require_once __DIR__ . '/../../includes/ai/ai-template-suggest.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rh_ai_ts_api_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$rhTsNone = 'No suggestions this time. Your template is unchanged.';

if (!rh_ai_ts_available($pdo)) {
    rh_ai_ts_api_out(404, ['success' => false, 'error' => 'Not found']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || (string)($_POST['action'] ?? '') !== 'get_suggestions') {
    rh_ai_ts_api_out(405, ['success' => false, 'error' => 'Use POST.']);
}
if (!validateCsrfToken((string)($_POST['csrf_token'] ?? '')) || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
    rh_ai_ts_api_out(403, ['success' => false, 'error' => 'Your session has changed. Reload the page.']);
}
$uid = (int)($user['id'] ?? 0);
if (!rh_ai_ts_person_ok($uid, rh_ai_ts_can_for($uid))) {
    rh_ai_ts_api_out(403, ['success' => false, 'error' => 'You do not have access to this.']);
}

$key = (string)($_POST['template'] ?? '');
$subject = (string)($_POST['subject'] ?? '');
$html = (string)($_POST['html_body'] ?? '');
$tone = rh_ai_ts_tone($_POST['tone'] ?? ''); // whitelisted: unknown or missing = mixed; the raw text never reaches the prompt
if (!rh_ai_ts_template_ok($key)) {
    rh_ai_ts_api_out(200, ['success' => true, 'versions' => [], 'message' => 'Suggestions are not available for this template.']);
}
if (trim($html) === '') {
    rh_ai_ts_api_out(200, ['success' => true, 'versions' => [], 'message' => 'There is no wording yet. Press Load Default first, then ask for suggestions.']);
}

$now = time();
$ck = rh_ai_ts_cache_key($uid, $key, $subject, $html, $tone);
$hit = rh_ai_ts_cache_get((array)($_SESSION[RH_AI_TS_SESSION]['cache'] ?? []), $ck, $now);
if ($hit !== null) {
    session_write_close();
    rh_ai_ts_api_out(200, ['success' => true, 'versions' => $hit, 'message' => $hit ? '' : $rhTsNone]);
}
// The limit is counted on the server, before the call (two tabs cannot both slip past it)
$rate = rh_ai_ts_rate((array)($_SESSION[RH_AI_TS_SESSION]['stamps'] ?? []), $now, rh_ai_ts_limit());
if (!$rate['ok']) {
    session_write_close();
    $mins = (int)max(1, ceil($rate['wait'] / 60));
    rh_ai_ts_api_out(200, ['success' => true, 'versions' => [], 'message' => 'That is enough suggestions for now. Try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '. Your template is unchanged.']);
}
$_SESSION[RH_AI_TS_SESSION]['stamps'] = $rate['stamps'];
session_write_close(); // the model call never holds this person's session

@set_time_limit(60);
$hotel = (string)getSetting('site_name', 'Hotel');
$r = rh_ai_ts_suggest($pdo, $uid, $key, $subject, $html, $hotel, $tone);

if ($r['versions']) {
    try {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[RH_AI_TS_SESSION]['cache'] = rh_ai_ts_cache_put((array)($_SESSION[RH_AI_TS_SESSION]['cache'] ?? []), $ck, $r['versions'], time());
            session_write_close();
        }
    } catch (Throwable $e) {
        error_log('ai-template-suggest cache: ' . $e->getMessage());
    }
}
$msg = '';
if (!$r['versions']) {
    $msg = $r['reason'] === 'budget'
        ? 'The Assistant has reached its usage limit for now. Your template is unchanged.'
        : $rhTsNone;
}
rh_ai_ts_api_out(200, ['success' => true, 'versions' => $r['versions'], 'message' => $msg]);
