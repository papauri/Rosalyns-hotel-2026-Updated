<?php

/**
 * AJAX: Fetch Facebook Pages managed by the stored access token.
 * Calls GET /me/accounts on the Graph API and returns page list.
 * POST only — CSRF protected.
 */

require_once __DIR__ . '/api-init.php';
/** @var array $user */
/** @var string $csrf_token */

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

$token = (string) getSetting('facebook_page_access_token', '');
if ($token === '') {
    echo json_encode(['success' => false, 'error' => 'No access token is saved. Paste and save a token first.', 'code' => 400]);
    exit;
}

// Resolve CA bundle (same pattern as facebook-functions.php)
$caBundle = (string) ini_get('curl.cainfo');
if ($caBundle === '' || !file_exists($caBundle)) {
    $localCa = dirname(__DIR__, 2) . '/config/cacert.pem';
    if (file_exists($localCa)) {
        $caBundle = $localCa;
    }
}

/**
 * Helper: run one cURL GET and return [httpCode, decoded, curlErr].
 *
 * @param string $url
 * @param string $caBundle
 * @return array{0:int,1:array<string,mixed>|null,2:string}
 */
function fbGet(string $url, string $caBundle): array
{
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($caBundle !== '') {
        $opts[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    $decoded = is_string($response) ? json_decode($response, true) : null;
    return [$httpCode, $decoded, $curlErr];
}

// ── Strategy 1: User Access Token → /me/accounts (returns all managed pages) ──
$accountsUrl = 'https://graph.facebook.com/v19.0/me/accounts?fields=id,name,category&access_token=' . urlencode($token);
[$httpCode, $decoded, $curlErr] = fbGet($accountsUrl, $caBundle);

if ($curlErr !== '') {
    rh_log_event('facebook', 'error', 'cURL error fetching pages', ['error' => $curlErr]);
    echo json_encode(['success' => false, 'error' => 'Network error: ' . $curlErr, 'code' => 502]);
    exit;
}

// Check if the token is a Page Access Token — /me/accounts returns error #100 for page tokens
$isPageTokenError = isset($decoded['error']['code']) && (int) $decoded['error']['code'] === 100
    && strpos((string) ($decoded['error']['message'] ?? ''), 'accounts') !== false;

if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['data'])) {
    // User token path — may manage multiple pages
    $pages = array_map(static function (array $p): array {
        return [
            'id'       => (string) $p['id'],
            'name'     => (string) $p['name'],
            'category' => (string) ($p['category'] ?? ''),
        ];
    }, (array) $decoded['data']);

    echo json_encode(['success' => true, 'pages' => $pages]);
    exit;
}

// ── Strategy 2: Page Access Token → /me?fields=id,name,category ──────────────
// A Page Access Token's /me IS the page itself — no accounts field.
if ($isPageTokenError) {
    $meUrl = 'https://graph.facebook.com/v19.0/me?fields=id,name,category&access_token=' . urlencode($token);
    [$meCode, $meDecoded, $meCurlErr] = fbGet($meUrl, $caBundle);

    if ($meCurlErr !== '') {
        rh_log_event('facebook', 'error', 'cURL error fetching page /me', ['error' => $meCurlErr]);
        echo json_encode(['success' => false, 'error' => 'Network error: ' . $meCurlErr, 'code' => 502]);
        exit;
    }

    if ($meCode >= 200 && $meCode < 300 && isset($meDecoded['id'])) {
        $pages = [[
            'id'       => (string) $meDecoded['id'],
            'name'     => (string) ($meDecoded['name'] ?? ''),
            'category' => (string) ($meDecoded['category'] ?? ''),
        ]];
        echo json_encode(['success' => true, 'pages' => $pages]);
        exit;
    }

    $fbError = (string) ($meDecoded['error']['message'] ?? ('HTTP ' . $meCode));
    rh_log_event('facebook', 'warning', 'Failed to fetch page via /me', ['error' => $fbError]);
    echo json_encode(['success' => false, 'error' => 'Facebook API error: ' . $fbError, 'code' => $meCode]);
    exit;
}

$fbError = (string) ($decoded['error']['message'] ?? ('HTTP ' . $httpCode));
rh_log_event('facebook', 'warning', 'Failed to fetch Facebook pages', ['error' => $fbError]);
echo json_encode(['success' => false, 'error' => 'Facebook API error: ' . $fbError, 'code' => $httpCode]);

