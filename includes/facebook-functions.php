<?php

/**
 * Facebook Page Posting Functions
 * All Graph API calls are server-side PHP only — tokens are never exposed to the browser.
 *
 * Connection model (admin/facebook-settings.php):
 *   Connect with Facebook (OAuth)  ->  short-lived user token  ->  long-lived user token
 *   ->  /me/accounts  ->  Page access token (does not expire when derived from a long-lived
 *   user token). The Page token is stored encrypted in site_settings.facebook_page_access_token.
 *   A Page token pasted by hand is still accepted (Advanced section) but short-lived tokens
 *   from the Graph API Explorer die after about an hour, which is the usual reason posting stops.
 */

if (!defined('FACEBOOK_GRAPH_VERSION')) {
    define('FACEBOOK_GRAPH_VERSION', 'v24.0');
}
if (!defined('FACEBOOK_GRAPH_API')) {
    define('FACEBOOK_GRAPH_API', 'https://graph.facebook.com/' . FACEBOOK_GRAPH_VERSION);
}
if (!defined('FACEBOOK_OAUTH_SCOPES')) {
    define('FACEBOOK_OAUTH_SCOPES', 'pages_show_list,pages_read_engagement,pages_manage_posts');
}

/**
 * Check if Facebook posting is enabled and fully configured.
 */
function isFacebookPostingEnabled(): bool
{
    return getSetting('facebook_posting_enabled', '0') === '1'
        && (string) getSetting('facebook_page_id', '') !== ''
        && (string) getSetting('facebook_page_access_token', '') !== '';
}

// ── Secret storage (independent of the host name) ─────────────────────────────

/**
 * Encrypt a Facebook secret (app secret / page token). The key does not
 * depend on SERVER_NAME, so a token saved on one host alias (www / non-www / CLI) still decrypts
 * on another. Values are prefixed "fb1:".
 */
function facebookEncryptSecret(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $salt = $_ENV['APP_ENCRYPTION_SALT'] ?? getenv('APP_ENCRYPTION_SALT') ?: 'DEFAULT_SALT_CHANGE_IN_ENV';
    $key  = hash('sha256', $salt . '|facebook', true);
    $iv   = random_bytes(16);
    $enc  = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($enc === false) {
        error_log('facebookEncryptSecret: openssl_encrypt failed; secret not saved');
        return '';
    }
    return 'fb1:' . base64_encode($iv . $enc);
}

/**
 * Decrypt a value stored by facebookEncryptSecret(), or an older plaintext value.
 * Returns '' when it cannot be read (for example the host name changed under an old row).
 */
function facebookDecryptSecret(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    if (strncmp($stored, 'fb1:', 4) === 0) {
        $data = base64_decode(substr($stored, 4), true);
        if ($data === false || strlen($data) < 17) {
            return '';
        }
        $salt = $_ENV['APP_ENCRYPTION_SALT'] ?? getenv('APP_ENCRYPTION_SALT') ?: 'DEFAULT_SALT_CHANGE_IN_ENV';
        $key  = hash('sha256', $salt . '|facebook', true);
        $dec  = openssl_decrypt(substr($data, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($data, 0, 16));
        return $dec === false ? '' : $dec;
    }
    // Older rows may be plaintext (Facebook tokens start with "EA"; app secrets are 32 hex chars).
    if (preg_match('/^(EA[A-Za-z0-9_\-]{20,}|[0-9a-f]{32})$/', $stored)) {
        return $stored;
    }
    return '';
}

/** The saved Page access token, decrypted ('' if none or unreadable). */
function facebookStoredToken(): string
{
    return facebookDecryptSecret((string) getSetting('facebook_page_access_token', ''));
}

/** @return array{0:string,1:string} [app id, app secret] ('' when not saved) */
function facebookAppCredentials(): array
{
    return [
        trim((string) getSetting('facebook_app_id', '')),
        facebookDecryptSecret((string) getSetting('facebook_app_secret', '')),
    ];
}

/** Upsert site_settings rows and clear the settings cache. */
function facebookSaveSettings(array $pairs): void
{
    global $pdo;
    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    global $_SITE_SETTINGS;
    foreach ($pairs as $k => $v) {
        $stmt->execute([$k, (string) $v]);
        $_SITE_SETTINGS[$k] = (string) $v; // keep this request's in-memory settings cache in step
    }
    if (function_exists('clearSettingsCache')) {
        clearSettingsCache();
    }
}

// ── Graph API transport ───────────────────────────────────────────────────────

/** Path of a CA bundle for cURL, or '' to use the system default. */
function facebookCaBundle(): string
{
    $caBundle = (string) ini_get('curl.cainfo');
    if ($caBundle === '' || !file_exists($caBundle)) {
        $localCa = dirname(__DIR__) . '/config/cacert.pem';
        if (file_exists($localCa)) {
            $caBundle = $localCa;
        }
    }
    return $caBundle;
}

/**
 * One Graph API call. The access token travels in an Authorization header, never in the URL.
 *
 * @param  string      $method  GET or POST
 * @param  string      $path    e.g. "/me/accounts" or "/oauth/access_token"
 * @param  array       $params  Query (GET) or form body (POST)
 * @param  string|null $token   Bearer token (user, page or "app_id|app_secret")
 * @return array{ok:bool,http:int,data:array,message:string,code:int,subcode:int,curl:string}
 */
function facebookGraphRequest(string $method, string $path, array $params = [], ?string $token = null): array
{
    $method = strtoupper($method);
    $url    = FACEBOOK_GRAPH_API . '/' . ltrim($path, '/');
    $ch     = curl_init();
    $headers = ['Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_URL]        = $url;
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } else {
        $opts[CURLOPT_URL] = $url . ($params ? '?' . http_build_query($params) : '');
    }
    $ca = facebookCaBundle();
    if ($ca !== '') {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $http     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $decoded = is_string($response) ? json_decode($response, true) : null;
    $data    = is_array($decoded) ? $decoded : [];
    $err     = is_array($data['error'] ?? null) ? $data['error'] : [];
    $ok      = $curlErr === '' && $http >= 200 && $http < 300 && !$err;
    $message = (string) ($err['message'] ?? '');
    if ($curlErr !== '') {
        $message = 'Could not reach Facebook: ' . $curlErr;
    } elseif (!$ok && $message === '') {
        $message = 'Facebook returned HTTP ' . $http . ' with no error message.';
    }

    return [
        'ok'      => $ok,
        'http'    => $http,
        'data'    => $data,
        'message' => $message,
        'code'    => (int) ($err['code'] ?? 0),
        'subcode' => (int) ($err['error_subcode'] ?? 0),
        'curl'    => $curlErr,
    ];
}

/** True when a Graph error means the token itself is dead or lacks permission (not a blip). */
function facebookIsAuthFailure(array $r): bool
{
    if ($r['ok'] || $r['curl'] !== '') {
        return false;
    }
    return in_array($r['code'], [10, 102, 190], true)
        || ($r['code'] >= 200 && $r['code'] <= 299);
}

/**
 * Human-readable explanation of a failed Graph call: always Facebook's own message, then a hint.
 */
function facebookDescribeError(array $r): string
{
    $msg = trim((string) $r['message']);
    if ($msg === '') {
        $msg = 'Unknown Facebook error.';
    }
    if ($r['curl'] !== '') {
        return $msg;
    }
    $hint = '';
    if ($r['code'] === 190) {
        $hint = 'The saved access token has expired or was revoked. Open Facebook Settings and click Reconnect.';
    } elseif ($r['code'] === 10 || ($r['code'] >= 200 && $r['code'] <= 299)) {
        $hint = 'The token is missing a permission, or this Facebook user cannot publish to the Page. '
            . 'Reconnect and allow every permission Facebook asks for (pages_show_list, pages_read_engagement, pages_manage_posts); '
            . 'the Facebook user also needs full control of the Page.';
    } elseif ($r['code'] === 100) {
        $hint = 'Facebook rejected a parameter. Check the Page ID, or that the app may use this request.';
    } elseif (in_array($r['code'], [4, 17, 32, 341, 613], true)) {
        $hint = 'Facebook is rate-limiting this app. Wait a few minutes and try again.';
    } elseif ($r['code'] === 368) {
        $hint = 'Facebook has temporarily blocked posting from this Page.';
    } elseif (in_array($r['code'], [1, 2], true)) {
        $hint = 'Temporary Facebook problem. Try again shortly.';
    }
    $out = 'Facebook said: ' . $msg;
    if ($r['code'] > 0) {
        $out .= ' (code ' . $r['code'] . ($r['subcode'] ? '/' . $r['subcode'] : '') . ')';
    }
    return $hint !== '' ? $out . ' ' . $hint : $out;
}

// ── Connection status ─────────────────────────────────────────────────────────

/**
 * Remember whether the last real call to Facebook worked, so share buttons can disable
 * themselves with a hint. Only writes when something changed; blips (rate limit, network)
 * pass $authFailure = false and never mark the connection broken.
 */
function facebookRecordStatus(bool $ok, string $error = '', bool $authFailure = true): void
{
    if (!$ok && !$authFailure) {
        return;
    }
    $status = $ok ? 'ok' : 'error';
    $error  = $ok ? '' : mb_substr($error, 0, 500);
    if ((string) getSetting('facebook_connection_status', '') === $status
        && (string) getSetting('facebook_connection_error', '') === $error) {
        return;
    }
    try {
        facebookSaveSettings([
            'facebook_connection_status' => $status,
            'facebook_connection_error'  => $error,
            'facebook_last_checked_at'   => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        error_log('[facebook] could not record status: ' . $e->getMessage());
    }
}

/** Share buttons are usable: posting on, Page + token saved, last check did not fail. */
function isFacebookShareReady(): bool
{
    return isFacebookPostingEnabled()
        && (string) getSetting('facebook_connection_status', '') !== 'error';
}

/**
 * Is the share button for a module shown at all? (Posting switched on + module toggle on.)
 * A shown button may still be disabled: see facebookShareButtonAttrs().
 */
function facebookShareVisible(string $module): bool
{
    return getSetting('facebook_posting_enabled', '0') === '1'
        && getSetting('facebook_' . $module . '_enabled', '1') === '1';
}

/** Why share buttons are disabled ('' when they are usable). */
function facebookShareDisabledReason(): string
{
    if (isFacebookShareReady()) {
        return '';
    }
    if (isFacebookPostingEnabled()) {
        return 'Facebook connection needs attention. Open Facebook Settings and click Reconnect.';
    }
    return 'Facebook is not connected yet. Open Facebook Settings and click Connect with Facebook.';
}

/**
 * Attributes for a share button: title, plus disabled/aria-disabled when Facebook is not
 * connected. Echo it in place of a title="" attribute.
 */
function facebookShareButtonAttrs(string $title = 'Post to Facebook'): string
{
    $reason = facebookShareDisabledReason();
    if ($reason === '') {
        return ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
    }
    return ' title="' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '" disabled aria-disabled="true"';
}

// ── OAuth: Connect with Facebook ──────────────────────────────────────────────

/**
 * The exact redirect URI to whitelist in the Facebook app (Facebook Login > Settings >
 * Valid OAuth Redirect URIs). Built from BASE_URL (the host staff are browsing), https unless
 * running on localhost. No query string, so it matches exactly.
 */
function facebookRedirectUri(): string
{
    $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
    if ($base === '') {
        $base = rtrim((string) getSetting('site_url', ''), '/');
    }
    if (!preg_match('#^https?://#i', $base)) {
        $base = 'https://' . ltrim($base, '/');
    }
    $host = (string) parse_url($base, PHP_URL_HOST);
    if (!in_array($host, ['localhost', '127.0.0.1'], true)) {
        $base = preg_replace('#^http://#i', 'https://', $base);
    }
    return $base . '/admin/facebook-settings.php';
}

/** Start an OAuth attempt: returns the Facebook dialog URL and stores a one-time state in the session. */
function facebookBuildAuthUrl(string $appId): string
{
    $state = bin2hex(random_bytes(24));
    $_SESSION['fb_oauth'] = ['state' => $state, 'time' => time(), 'user' => (int) ($_SESSION['admin_user_id'] ?? 0)];
    $params = [
        'client_id'     => $appId,
        'redirect_uri'  => facebookRedirectUri(),
        'state'         => $state,
        'response_type' => 'code',
    ];
    $configId = trim((string) getSetting('facebook_login_config_id', ''));
    if ($configId !== '') {
        // "Facebook Login for Business" apps take a configuration instead of scopes.
        $params['config_id'] = $configId;
    } else {
        $params['scope'] = FACEBOOK_OAUTH_SCOPES;
    }
    return 'https://www.facebook.com/' . FACEBOOK_GRAPH_VERSION . '/dialog/oauth?' . http_build_query($params);
}

/** Validate and consume the OAuth state returned to the callback. */
function facebookConsumeOauthState(string $state): bool
{
    $saved = $_SESSION['fb_oauth'] ?? null;
    unset($_SESSION['fb_oauth']);
    if (!is_array($saved) || $state === '' || !hash_equals((string) ($saved['state'] ?? ''), $state)) {
        return false;
    }
    if (time() - (int) ($saved['time'] ?? 0) > 900) {
        return false;
    }
    return (int) ($saved['user'] ?? 0) === (int) ($_SESSION['admin_user_id'] ?? -1);
}

/**
 * Exchange the OAuth ?code for a long-lived user token.
 *
 * @return array{ok:bool,token:string,error:string,extended:bool}
 */
function facebookExchangeCodeForLongLivedToken(string $code): array
{
    [$appId, $secret] = facebookAppCredentials();
    if ($appId === '' || $secret === '') {
        return ['ok' => false, 'token' => '', 'error' => 'App ID or App Secret is not saved.', 'extended' => false];
    }
    $short = facebookGraphRequest('POST', '/oauth/access_token', [
        'client_id'     => $appId,
        'client_secret' => $secret,
        'redirect_uri'  => facebookRedirectUri(),
        'code'          => $code,
    ]);
    if (!$short['ok'] || empty($short['data']['access_token'])) {
        return ['ok' => false, 'token' => '', 'extended' => false, 'error' => facebookDescribeError($short)
            . ' If this mentions the redirect URI, whitelist exactly ' . facebookRedirectUri() . ' in the Facebook app.'];
    }
    $long = facebookGraphRequest('POST', '/oauth/access_token', [
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $appId,
        'client_secret'     => $secret,
        'fb_exchange_token' => (string) $short['data']['access_token'],
    ]);
    if (!$long['ok'] || empty($long['data']['access_token'])) {
        // The short-lived token still lists Pages, but Page tokens made from it also expire soon.
        return ['ok' => true, 'token' => (string) $short['data']['access_token'], 'extended' => false,
            'error' => 'Could not extend the token: ' . facebookDescribeError($long)];
    }
    return ['ok' => true, 'token' => (string) $long['data']['access_token'], 'extended' => true, 'error' => ''];
}

/**
 * Pages the user token can manage, each with its own Page access token.
 *
 * @return array{ok:bool,pages:array<int,array{id:string,name:string,category:string,access_token:string}>,error:string}
 */
function facebookListManagedPages(string $userToken): array
{
    $pages  = [];
    $params = ['fields' => 'id,name,category,access_token', 'limit' => 100];
    for ($i = 0; $i < 5; $i++) { // follow up to 5 result pages
        $r = facebookGraphRequest('GET', '/me/accounts', $params, $userToken);
        if (!$r['ok']) {
            return ['ok' => false, 'pages' => [], 'error' => facebookDescribeError($r)];
        }
        foreach ((array) ($r['data']['data'] ?? []) as $p) {
            if (!empty($p['id']) && !empty($p['access_token'])) {
                $pages[] = [
                    'id'           => (string) $p['id'],
                    'name'         => (string) ($p['name'] ?? ''),
                    'category'     => (string) ($p['category'] ?? ''),
                    'access_token' => (string) $p['access_token'],
                ];
            }
        }
        $after = $r['data']['paging']['cursors']['after'] ?? '';
        if ($after === '' || empty($r['data']['paging']['next'])) {
            break;
        }
        $params['after'] = $after;
    }
    return ['ok' => true, 'pages' => $pages, 'error' => ''];
}

/**
 * Ask Facebook about a token (GET /debug_token). Uses the app token when the app secret is
 * saved, otherwise the token inspects itself.
 *
 * @return array{ok:bool,valid:bool,type:string,expires_at:int,never_expires:bool,scopes:array,user_id:string,error:string}
 */
function facebookDebugToken(string $token): array
{
    [$appId, $secret] = facebookAppCredentials();
    $auth = ($appId !== '' && $secret !== '') ? $appId . '|' . $secret : $token;
    $r = facebookGraphRequest('GET', '/debug_token', ['input_token' => $token], $auth);
    $out = ['ok' => false, 'valid' => false, 'type' => '', 'expires_at' => 0, 'never_expires' => false,
            'scopes' => [], 'user_id' => '', 'error' => ''];
    if (!$r['ok']) {
        $out['error'] = facebookDescribeError($r);
        return $out;
    }
    $d   = (array) ($r['data']['data'] ?? []);
    $exp = (int) ($d['expires_at'] ?? 0);
    $out['ok']            = true;
    $out['valid']         = !empty($d['is_valid']);
    $out['type']          = (string) ($d['type'] ?? '');
    $out['expires_at']    = $exp;
    $out['never_expires'] = $exp === 0;
    $out['scopes']        = array_values(array_map('strval', (array) ($d['scopes'] ?? [])));
    $out['user_id']       = (string) ($d['user_id'] ?? '');
    if (!$out['valid']) {
        $out['error'] = (string) ($d['error']['message'] ?? 'Facebook reports this token is not valid.');
    }
    return $out;
}

/** Permissions we need that are not in the token's granted scopes. */
function facebookMissingScopes(array $granted): array
{
    if (!$granted) {
        return [];
    }
    return array_values(array_diff(explode(',', FACEBOOK_OAUTH_SCOPES), $granted));
}

/**
 * Store a Page connection (after OAuth or a validated manual token).
 * The Page token is stored encrypted; nothing is posted to Facebook.
 */
function facebookStoreConnection(string $pageId, string $pageName, string $pageToken, int $expiresAt, string $connectedBy = ''): void
{
    facebookSaveSettings([
        'facebook_page_id'           => $pageId,
        'facebook_page_name'         => $pageName,
        'facebook_page_access_token' => facebookEncryptSecret($pageToken),
        'facebook_token_expires_at'  => (string) $expiresAt, // 0 = does not expire
        'facebook_connected_at'      => date('Y-m-d H:i:s'),
        'facebook_connected_by'      => $connectedBy,
        'facebook_connection_status' => 'ok',
        'facebook_connection_error'  => '',
        'facebook_last_checked_at'   => date('Y-m-d H:i:s'),
    ]);
}

/** Forget the saved Page token and connection details (App ID / secret are kept). */
function facebookClearConnection(): void
{
    facebookSaveSettings([
        'facebook_page_access_token' => '',
        'facebook_token_expires_at'  => '',
        'facebook_connected_at'      => '',
        'facebook_connected_by'      => '',
        'facebook_connection_status' => '',
        'facebook_connection_error'  => '',
    ]);
}

/**
 * Read-only connection check: can the saved token see the Page, what does it allow, when does
 * it expire. GET calls only. Records the result so share buttons can disable themselves.
 *
 * @return array{connected:bool,page_name:string,page_id:string,page_link:string,token_type:string,never_expires:bool,expires_at:int,scopes:array,missing_scopes:array,error:string,warning:string}
 */
function facebookCheckConnection(): array
{
    $res = ['connected' => false, 'page_name' => '', 'page_id' => '', 'page_link' => '',
            'token_type' => '', 'never_expires' => false, 'expires_at' => 0,
            'scopes' => [], 'missing_scopes' => [], 'error' => '', 'warning' => ''];

    $pageId = (string) getSetting('facebook_page_id', '');
    $stored = (string) getSetting('facebook_page_access_token', '');
    $token  = facebookStoredToken();
    if ($stored === '') {
        $res['error'] = 'No access token is saved. Click Connect with Facebook.';
        return $res;
    }
    if ($token === '') {
        $res['error'] = 'The saved token cannot be read (the server encryption key changed). Click Reconnect to save a fresh one.';
        facebookRecordStatus(false, $res['error']);
        return $res;
    }
    if ($pageId === '') {
        $res['error'] = 'No Facebook Page ID is saved.';
        return $res;
    }

    $page = facebookGraphRequest('GET', '/' . rawurlencode($pageId), ['fields' => 'id,name,link'], $token);
    if (!$page['ok']) {
        $res['error'] = facebookDescribeError($page);
        facebookRecordStatus(false, $res['error'], facebookIsAuthFailure($page));
        return $res;
    }
    $res['page_id']   = (string) ($page['data']['id'] ?? $pageId);
    $res['page_name'] = (string) ($page['data']['name'] ?? '');
    $res['page_link'] = (string) ($page['data']['link'] ?? '');

    $dbg = facebookDebugToken($token);
    if ($dbg['ok']) {
        $res['token_type']     = $dbg['type'];
        $res['never_expires']  = $dbg['never_expires'];
        $res['expires_at']     = $dbg['expires_at'];
        $res['scopes']         = $dbg['scopes'];
        $res['missing_scopes'] = facebookMissingScopes($dbg['scopes']);
        if ($dbg['valid'] && (string) getSetting('facebook_token_expires_at', '') !== (string) $dbg['expires_at']) {
            try {
                facebookSaveSettings(['facebook_token_expires_at' => (string) $dbg['expires_at']]);
            } catch (Throwable $e) {
                error_log('[facebook] expiry not saved: ' . $e->getMessage());
            }
        }
    }

    // Posting needs pages_manage_posts; the other two only matter for reading Page details.
    if (in_array('pages_manage_posts', $res['missing_scopes'], true)) {
        $res['error'] = 'The token is missing pages_manage_posts. Posting will fail until you Reconnect and allow every permission.';
        facebookRecordStatus(false, $res['error']);
        return $res;
    }
    if ($res['missing_scopes']) {
        $res['warning'] = 'The token is missing: ' . implode(', ', $res['missing_scopes']) . '. Reconnect and allow every permission.';
    }

    $res['connected'] = true;
    facebookRecordStatus(true);
    return $res;
}

/**
 * Post a message + optional link or image to the Facebook Page feed.
 *
 * If $imageUrl is provided, uses the /photos endpoint (photo post with caption).
 * Otherwise uses the /feed endpoint (link post or plain text post).
 *
 * @param string      $message  Text / caption for the post.
 * @param string|null $link     Absolute public URL to attach (link preview). Ignored when $imageUrl is set.
 * @param string|null $imageUrl Absolute public URL of the image to post.
 * @return array{success:bool,message:string,post_id:string}
 */
function postFacebookFeed(string $message, ?string $link = null, ?string $imageUrl = null): array
{
    $pageId = (string) getSetting('facebook_page_id', '');
    $token  = facebookStoredToken();

    if ($pageId === '' || $token === '') {
        $unreadable = (string) getSetting('facebook_page_access_token', '') !== '';
        return [
            'success'  => false,
            'message'  => $unreadable
                ? 'The saved Facebook token cannot be read. Open Facebook Settings and click Reconnect.'
                : 'Facebook is not connected. Open Facebook Settings and click Connect with Facebook.',
            'post_id'  => '',
        ];
    }

    if ($imageUrl !== null && $imageUrl !== '') {
        $path   = '/' . rawurlencode($pageId) . '/photos';
        $params = ['url' => $imageUrl, 'caption' => $message];
    } else {
        $path   = '/' . rawurlencode($pageId) . '/feed';
        $params = ['message' => $message];
        if ($link !== null && $link !== '') {
            $params['link'] = $link;
        }
    }

    $r = facebookGraphRequest('POST', $path, $params, $token);

    if ($r['curl'] !== '') {
        rh_log_event('facebook', 'error', 'cURL error posting to Facebook', ['error' => $r['curl']]);
        return ['success' => false, 'message' => 'Network error: ' . $r['curl'], 'post_id' => ''];
    }

    $postId = (string) ($r['data']['post_id'] ?? $r['data']['id'] ?? '');
    if ($r['ok'] && $postId !== '') {
        rh_log_event('facebook', 'info', 'Facebook post published', [
            'post_id'   => $postId,
            'has_image' => ($imageUrl !== null && $imageUrl !== ''),
            'has_link'  => ($link !== null && $link !== ''),
        ]);
        facebookRecordStatus(true);
        return ['success' => true, 'message' => 'Posted successfully.', 'post_id' => $postId];
    }

    $described = facebookDescribeError($r);
    rh_log_event('facebook', 'warning', 'Facebook post failed', [
        'http_code' => $r['http'],
        'fb_error'  => $r['message'],
        'fb_code'   => $r['code'],
    ]);
    facebookRecordStatus(false, $described, facebookIsAuthFailure($r));
    return ['success' => false, 'message' => $described, 'post_id' => ''];
}

/**
 * Build post content array for a room.
 *
 * @param  array $room Row from the rooms table (must include: name, slug, short_description, description, price_per_night, image_url).
 * @return array{message:string,link:string,image_url:string}
 */
function buildRoomFacebookPost(array $room): array
{
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $currency = (string) getSetting('currency_symbol', 'MWK');
    $hashtags = trim((string) getSetting('facebook_default_hashtags', '#hotel #accommodation #luxury'));

    $slug     = (string) ($room['slug'] ?? '');
    $link     = $baseUrl . '/room.php?room=' . urlencode($slug);

    $priceLine = '';
    if (!empty($room['price_per_night'])) {
        $priceLine = "\nFrom " . $currency . ' ' . number_format((float) $room['price_per_night']) . '/night';
    }

    $blurb = trim((string) ($room['short_description'] ?? $room['description'] ?? ''));

    $message = trim(
        ($room['name'] ?? 'Room') . "\n"
            . ($blurb !== '' ? $blurb . "\n" : '')
            . $priceLine . "\n\n"
            . 'Book now: ' . $link . "\n\n"
            . $hashtags
    );

    $imageUrl = '';
    if (!empty($room['image_url'])) {
        $img      = (string) $room['image_url'];
        $imageUrl = preg_match('#^https?://#', $img) ? $img : $baseUrl . '/' . ltrim($img, '/');
    }

    return [
        'message'   => $message,
        'link'      => $link,
        'image_url' => $imageUrl,
    ];
}

/**
 * Build post content array for an event.
 *
 * @param  array $event Row from the events table (must include: id, title, description, event_date, start_time, image_path).
 * @return array{message:string,link:string,image_url:string}
 */
function buildEventFacebookPost(array $event): array
{
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $hashtags = trim((string) getSetting('facebook_default_hashtags', '#hotel #accommodation #luxury'));

    $datePart = '';
    if (!empty($event['event_date'])) {
        try {
            $dt = new DateTime((string) $event['event_date'], new DateTimeZone('Africa/Blantyre'));
            $datePart = "\nDate: " . $dt->format('l, F j, Y');
        } catch (Exception $e) {
            $datePart = '';
        }
    }
    if (!empty($event['start_time'])) {
        $datePart .= ' at ' . date('g:i A', strtotime((string) $event['start_time']));
    }

    $link = $baseUrl . '/events.php#event-' . ($event['id'] ?? '');

    $imageUrl = '';
    if (!empty($event['image_path'])) {
        $img      = (string) $event['image_path'];
        $imageUrl = preg_match('#^https?://#', $img) ? $img : $baseUrl . '/' . ltrim($img, '/');
    }

    $blurb = trim((string) ($event['description'] ?? ''));

    $message = trim(
        ($event['title'] ?? 'Event') . "\n"
            . ($blurb !== '' ? $blurb . "\n" : '')
            . $datePart . "\n\n"
            . 'Full details: ' . $link . "\n\n"
            . $hashtags
    );

    return [
        'message'   => $message,
        'link'      => $link,
        'image_url' => $imageUrl,
    ];
}

/**
 * Build post content array for a conference room.
 *
 * @param  array $room Row from conference_rooms (id, name, description, daily_rate, capacity, size_sqm, amenities, image_path).
 * @return array{message:string,link:string,image_url:string}
 */
function buildConferenceFacebookPost(array $room): array
{
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $currency = (string) getSetting('currency_symbol', 'MWK');
    $hashtags = trim((string) getSetting('facebook_default_hashtags', '#hotel #accommodation #luxury'));

    $link = $baseUrl . '/conference.php';

    $lines = [];
    $lines[] = '🏢 ' . ($room['name'] ?? 'Conference Room');

    $blurb = trim((string) ($room['description'] ?? ''));
    if ($blurb !== '') {
        $lines[] = $blurb;
    }

    $details = [];
    if (!empty($room['capacity'])) {
        $details[] = 'Capacity: ' . (int) $room['capacity'] . ' guests';
    }
    if (!empty($room['size_sqm'])) {
        $details[] = 'Size: ' . number_format((float) $room['size_sqm'], 0) . ' sqm';
    }
    if (!empty($room['daily_rate'])) {
        $details[] = 'Rate: ' . $currency . ' ' . number_format((float) $room['daily_rate']) . '/day';
    }
    if (!empty($details)) {
        $lines[] = implode(' · ', $details);
    }

    if (!empty($room['amenities'])) {
        $lines[] = 'Facilities: ' . $room['amenities'];
    }

    $lines[] = '';
    $lines[] = 'Book your next event: ' . $link;
    $lines[] = '';
    $lines[] = $hashtags;

    $message = trim(implode("\n", $lines));

    $imageUrl = '';
    if (!empty($room['image_path'])) {
        $img      = (string) $room['image_path'];
        $imageUrl = preg_match('#^https?://#', $img) ? $img : $baseUrl . '/' . ltrim($img, '/');
    }

    return [
        'message'   => $message,
        'link'      => $link,
        'image_url' => $imageUrl,
    ];
}

/**
 * Build post content array for a menu item (food or drink).
 *
 * @param  array  $item      Row from food_menu or drink_menu (id, item_name, description, price, category).
 * @param  string $menuType  'food' or 'drink'.
 * @return array{message:string,link:string,image_url:string}
 */
function buildMenuItemFacebookPost(array $item, string $menuType = 'food'): array
{
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $currency = (string) getSetting('currency_symbol', 'MWK');
    $hashtags = trim((string) getSetting('facebook_default_hashtags', '#hotel #accommodation #luxury'));

    $icon = $menuType === 'drink' ? '🍹' : '🍽️';
    $link = $baseUrl . '/restaurant.php';

    $lines = [];
    $lines[] = $icon . ' ' . ($item['item_name'] ?? 'Menu Item');

    $blurb = trim((string) ($item['description'] ?? ''));
    if ($blurb !== '') {
        $lines[] = $blurb;
    }

    if (!empty($item['price'])) {
        $lines[] = $currency . ' ' . number_format((float) $item['price']);
    }

    if (!empty($item['category'])) {
        $lines[] = 'Category: ' . $item['category'];
    }

    $lines[] = '';
    $lines[] = 'See our full menu: ' . $link;
    $lines[] = '';
    $lines[] = $hashtags;

    $message = trim(implode("\n", $lines));

    return [
        'message'   => $message,
        'link'      => $link,
        'image_url' => '',
    ];
}

/**
 * Build a Facebook post for a single gym package.
 *
 * @param array $package Row from gym_packages table.
 * @return array{message:string,link:string,image_url:string}
 */
function buildGymPackageFacebookPost(array $package): array
{
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $currency = (string) getSetting('currency_symbol', 'MWK');
    $hashtags = trim((string) getSetting('facebook_default_hashtags', '#hotel #accommodation #luxury'));

    $link   = $baseUrl . '/gym.php';
    $price  = (float) ($package['price'] ?? 0);
    $dur    = trim((string) ($package['duration_label'] ?? ''));
    $name   = trim((string) ($package['name'] ?? 'Wellness Package'));

    $lines   = [];
    $lines[] = "\ud83d\udcaa " . $name;

    if ($price > 0) {
        $priceStr = $currency . ' ' . number_format($price);
        if ($dur !== '') {
            $priceStr .= ' / ' . $dur;
        }
        $lines[] = $priceStr;
    }

    $includes = trim((string) ($package['includes_text'] ?? ''));
    if ($includes !== '') {
        $items = array_filter(array_map('trim', explode("\n", $includes)));
        if (!empty($items)) {
            $lines[] = '';
            $lines[] = 'Includes:';
            foreach (array_slice($items, 0, 5) as $b) {
                $lines[] = "\u2714 " . $b;
            }
        }
    }

    $lines[] = '';
    $lines[] = "\ud83c\udfe8 " . $link;
    $lines[] = '';
    $lines[] = $hashtags;

    return [
        'message'   => trim(implode("\n", $lines)),
        'link'      => $link,
        'image_url' => '',
    ];
}
