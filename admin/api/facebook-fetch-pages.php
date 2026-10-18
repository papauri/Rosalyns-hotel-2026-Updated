<?php

/**
 * AJAX: list the Facebook Pages the saved access token can see (read-only).
 *
 * User token  -> GET /me/accounts (every Page the user manages)
 * Page token  -> GET /me          (the token IS the Page)
 * POST only, CSRF protected. Nothing is posted or saved; the browser fills the Page ID
 * and name fields and the admin saves the form. Page tokens are never returned.
 */

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../../includes/facebook-functions.php';
/** @var array $user */
/** @var string $csrf_token */

sendSecurityHeaders();
header('Content-Type: application/json');

requireApiPermission('facebook_settings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.', 'code' => 405]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.', 'code' => 403]);
    exit;
}

$token = facebookStoredToken();
if ($token === '') {
    $unreadable = (string) getSetting('facebook_page_access_token', '') !== '';
    echo json_encode([
        'success' => false,
        'error'   => $unreadable
            ? 'The saved token cannot be read. Click Reconnect to save a fresh one.'
            : 'No access token is saved. Click Connect with Facebook first.',
        'code'    => 400,
    ]);
    exit;
}

// User token: all managed Pages.
$list = facebookListManagedPages($token);
if ($list['ok']) {
    $pages = array_map(static function (array $p): array {
        return ['id' => $p['id'], 'name' => $p['name'], 'category' => $p['category']];
    }, $list['pages']);
    echo json_encode(['success' => true, 'pages' => $pages]);
    exit;
}

// Page token: /me/accounts does not exist on a Page; /me is the Page itself.
$me = facebookGraphRequest('GET', '/me', ['fields' => 'id,name,category'], $token);
if ($me['ok'] && !empty($me['data']['id'])) {
    echo json_encode(['success' => true, 'pages' => [[
        'id'       => (string) $me['data']['id'],
        'name'     => (string) ($me['data']['name'] ?? ''),
        'category' => (string) ($me['data']['category'] ?? ''),
    ]]]);
    exit;
}

// Neither worked: show Facebook's own message for the last call (an expired token says so here).
$failed = $me;
$error  = facebookDescribeError($failed);
rh_log_event('facebook', 'warning', 'Failed to fetch Facebook pages', ['error' => $failed['message'], 'code' => $failed['code']]);
facebookRecordStatus(false, $error, facebookIsAuthFailure($failed));
echo json_encode(['success' => false, 'error' => $error, 'code' => $failed['http'] ?: 502]);
