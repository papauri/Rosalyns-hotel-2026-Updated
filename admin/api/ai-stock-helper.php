<?php
/**
 * Stock name check endpoint (spec .claude/AI_STOCK_HELPER_SPEC.md D1-D3; engine includes/ai/ai-stock-kinds.php).
 *
 * POST + csrf_token + X-Requested-With. Actions:
 *   check         surface=fix, ids=1,2,3 (optional: the rows the page lists); at most ai_stock_max_chunks requests that send names
 *                 per person in 10 minutes (counted here in the session, not taken from the page)
 *                 -> {success, results: id => {state, line, chips, all}, summary, more, error}
 *                 One chunk of names per request: the page asks again while "more" > 0 (at most ai_stock_max_chunks times).
 *   price_of_one  surface=fix, id -> the person says this cost is the price of one (administrator / manager only).
 *
 * Order of checks: signed in (api-init: 401) -> helper on, hotel-wide (fail-closed: 404 as if missing) -> POST + action ->
 * CSRF (403) -> the person's gate for the surface (403). Every name, cost and price is read here from the database; the
 * browser sends ids only. Writes: ai_stock_kinds, usage and breaker rows only (never stock, costs or money).
 */
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/api-init.php';
/** @var array $user */
/** @var PDO $pdo */
require_once __DIR__ . '/../../includes/ai/ai-stock-kinds.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rh_ai_sk_api_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!rh_ai_sk_apply_on($pdo)) {
    rh_ai_sk_api_out(404, ['success' => false, 'error' => 'Not found']);
}
$action = (string)($_POST['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !in_array($action, ['check', 'price_of_one'], true)) {
    rh_ai_sk_api_out(405, ['success' => false, 'error' => 'Use POST.']);
}
if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    rh_ai_sk_api_out(403, ['success' => false, 'error' => 'Your session has changed. Reload the page.']);
}
$uid = (int)($user['id'] ?? 0);
$surface = (string)($_POST['surface'] ?? '');
if ($surface !== 'fix' || !rh_ai_sk_gate($pdo, $uid, $surface)) {
    rh_ai_sk_api_out(403, ['success' => false, 'error' => 'The name check is not available to you here.']);
}

require_once __DIR__ . '/../includes/stock-cost-fix-lib.php';
require_once __DIR__ . '/../../includes/import/case-signals.php';

try {
    $grid = rh_scf_grid_items($pdo);
} catch (Throwable $e) {
    error_log('ai-stock-helper grid: ' . $e->getMessage());
    rh_ai_sk_api_out(200, ['success' => false, 'error' => 'The stock items could not be loaded. Please reload the page.']);
}
$byId = [];
foreach ($grid as $it) {
    $byId[(int)$it['id']] = $it;
}
$toItem = static function (array $it): array {
    return ['id' => (int)$it['id'], 'name' => (string)$it['name'], 'category' => (string)$it['category'], 'unit' => (string)$it['unit'],
        'cost' => (float)$it['cost_per_unit'], 'pack_name' => $it['pack_name'], 'pack_size' => $it['pack_size']];
};

if ($action === 'price_of_one') {
    $id = (int)($_POST['id'] ?? 0);
    if (!isset($byId[$id])) {
        rh_ai_sk_api_out(200, ['success' => false, 'error' => 'That item is no longer in the list. Reload the page.']);
    }
    if (!rh_ai_sk_can_teach($user)) {
        rh_ai_sk_api_out(403, ['success' => false, 'error' => 'Only an administrator or manager can mark a price as the price of one.']);
    }
    $it = $toItem($byId[$id]);
    if (!rh_ai_sk_teach($pdo, $user, $it, 'one')) {
        rh_ai_sk_api_out(200, ['success' => false, 'error' => 'That could not be saved. Please try again.']);
    }
    logActivity($uid, 'ai_stock_name_check', 'Marked "' . mb_substr((string)$it['name'], 0, 80) . '" at ' . number_format((float)$it['cost'], 2) . ' as the price of one ' . (string)$it['unit']);
    $flags = import_cs_stock_flags([$byId[$id]], import_cs_load_evidence($pdo));
    $run = rh_ai_sk_run($pdo, $user, [$it], $flags, false);
    rh_ai_sk_api_out(200, ['success' => true, 'result' => $run['results'][$id] ?? null]);
}

// check: the server counts this person's requests that sent names in the last 10 minutes (the page's round number is not trusted)
[$skStamps, $skAllowed] = rh_ai_sk_rounds((array)($_SESSION['rh_sk_rounds'] ?? []), time(), (int)rh_ai_setting('ai_stock_max_chunks', '5'));
if (!$skAllowed) {
    rh_ai_sk_api_out(200, ['success' => false, 'error' => 'That is enough names for now. Wait a few minutes, then press Check names again for the rest.']);
}
$want = [];
foreach (explode(',', (string)($_POST['ids'] ?? '')) as $x) {
    $x = (int)trim($x);
    if ($x > 0 && isset($byId[$x])) {
        $want[$x] = true;
    }
    if (count($want) >= 2000) {
        break;
    }
}
$pick = $want ? array_intersect_key($byId, $want) : $byId;
$items = array_values(array_map($toItem, $pick));
$flags = [];
try {
    $flags = import_cs_stock_flags(array_values($pick), import_cs_load_evidence($pdo));
} catch (Throwable $e) {
    $flags = [];
}
session_write_close(); // the model calls never hold this person's session
@set_time_limit(2 * max(10, min(45, (int)rh_ai_setting('ai_stock_timeout_s', '45'))) + 10);
try {
    $run = rh_ai_sk_run($pdo, $user, $items, $flags, true);
} catch (Throwable $e) {
    error_log('ai-stock-helper check: ' . $e->getMessage());
    rh_ai_sk_api_out(200, ['success' => false, 'error' => 'The name check did not finish. The list works as before.']);
}
if ((int)$run['sent'] > 0) {
    // note this request against the cap (the session was closed for the model calls; it is opened again only for this)
    try {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $skStamps[] = time();
            $_SESSION['rh_sk_rounds'] = $skStamps;
            session_write_close();
        }
    } catch (Throwable $e) {
        error_log('ai-stock-helper rounds: ' . $e->getMessage());
    }
}
$out = [];
foreach ($run['results'] as $id => $r) {
    $out[(string)$id] = ['state' => $r['state'], 'line' => $r['line'], 'chips' => array_values($r['chips']), 'all' => (bool)$r['all']];
}
rh_ai_sk_api_out(200, ['success' => $run['ok'], 'error' => $run['error'], 'results' => (object)$out, 'summary' => $run['summary'], 'more' => (int)$run['more'], 'sent' => (int)$run['sent']]);
