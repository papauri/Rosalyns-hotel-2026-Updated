<?php

/**
 * Licence client - the PMS side of the owner's licence panel (msp-control-panel).
 *
 * Licensing is ON only when BOTH the code constant RH_LICENCE_PUBLIC_KEY (includes/licence-public-key.php) is
 * non-empty AND LICENCE_TENANT is set in the host's .env. Otherwise rh_licence_status() answers 'unconfigured' and
 * nothing anywhere changes (this is how a hotel that is not on a licence runs: no query, no banner, no restriction).
 *
 * Config (env only, never a setting): LICENCE_URL (the panel's api/licence.php), LICENCE_TENANT, LICENCE_KEY,
 * optional LICENCE_CONTACT (the name/phone shown to staff in the banner).
 * The one exception is the licence key: an administrator can save a replacement on the Licence page (setting
 * licence_key_override, encrypted). It wins over LICENCE_KEY and is saved only after the panel accepts it
 * (rh_licence_set_key); URL and tenant can never be changed from the portal.
 *
 * Storage (site_settings, read fresh from the database, never through the file cache):
 *   licence_token            JSON {payload, sig} - the last answer that VERIFIED (signature, tenant, domain, not older)
 *   licence_last_issued_at   highest issued_at ever accepted (anti-replay)
 *   licence_last_check_at    unix time of the last attempt (success or failure)
 *   licence_last_error       plain reason of the last failure, '' after a success (never the key, never the body)
 *
 * Status rules (rh_licence_evaluate):
 *   - no token has ever been stored  -> 'active' (a panel outage never locks a new install)
 *   - a token was stored but is now missing or fails re-verification (edited, wrong domain/tenant) -> 'grace'
 *   - otherwise lv_evaluate() from includes/licence-verify.php (server status, stricter date schedule, override)
 *
 * Enforcement lives in includes/licence-guard.php. Nothing here throws to the caller.
 */

require_once __DIR__ . '/licence-verify.php';
require_once __DIR__ . '/licence-public-key.php';
require_once __DIR__ . '/secret-salt.php';

if (!function_exists('rh_licence_env')) {

    /** An .env value (config/database.php exposes every KEY=value through $_ENV and getenv()). */
    function rh_licence_env(string $key): string
    {
        $v = $_ENV[$key] ?? getenv($key);
        return is_string($v) ? trim($v) : '';
    }

    function rh_licence_public_key(): string
    {
        return defined('RH_LICENCE_PUBLIC_KEY') ? trim((string)RH_LICENCE_PUBLIC_KEY) : '';
    }

    /** Pure form of the on/off rule (the tests pass their own key). */
    function rh_licence_is_enabled(string $publicKey, string $tenant): bool
    {
        return trim($publicKey) !== '' && trim($tenant) !== '';
    }

    function rh_licence_enabled(): bool
    {
        // Short-circuit on the constant first: with no key nothing else (env, database) is touched.
        $key = rh_licence_public_key();
        return $key !== '' && rh_licence_is_enabled($key, rh_licence_env('LICENCE_TENANT'));
    }

    /**
     * @param bool $withKey false skips reading the key (the per-request status never needs it, so no extra query)
     * @return array{url:string,tenant:string,key:string,contact:string}
     */
    function rh_licence_config(bool $withKey = true): array
    {
        return [
            'url'     => rh_licence_env('LICENCE_URL'),
            'tenant'  => rh_licence_env('LICENCE_TENANT'),
            'key'     => $withKey ? rh_licence_key_info()['key'] : '',
            'contact' => rh_licence_env('LICENCE_CONTACT'),
        ];
    }

    // ------------------------------------------------------------------ the licence key (portal override)

    /** Setting that holds a licence key saved from the Licence page (encrypted). LICENCE_URL and LICENCE_TENANT can never be set this way. */
    function rh_licence_key_setting(): string
    {
        return 'licence_key_override';
    }

    function rh_licence_key_crypto_key(?string $salt = null): string
    {
        return hash('sha256', 'licence-key|' . ($salt ?? rhSecretSalt()), true);
    }

    /** Stored as "lk1:" + base64(iv . ciphertext), same scheme as the WhatsApp token (APP_ENCRYPTION_SALT, old default readable). */
    function rh_licence_key_encrypt(string $plain): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', rh_licence_key_crypto_key(), OPENSSL_RAW_DATA, $iv);
        return 'lk1:' . base64_encode($iv . (string)$cipher);
    }

    /** @return string the plain key, or '' when it cannot be read */
    function rh_licence_key_decrypt(string $stored): string
    {
        if (strpos($stored, 'lk1:') !== 0) {
            return '';
        }
        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }
        return rhDecryptWithSalts(substr($raw, 16), substr($raw, 0, 16), 'rh_licence_key_crypto_key');
    }

    /** The saved override read fresh from the database (never cached). '' when none or unreadable. */
    function rh_licence_key_override(): string
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO)) {
            return '';
        }
        try {
            $st = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
            $st->execute([rh_licence_key_setting()]);
            $v = $st->fetchColumn();
            return is_string($v) && $v !== '' ? rh_licence_key_decrypt($v) : '';
        } catch (Throwable $e) {
            error_log('licence key read: ' . $e->getMessage());
            return '';
        }
    }

    /** Last four characters only, for the Licence page. Short keys show nothing. */
    function rh_licence_key_hint(string $key): string
    {
        return strlen($key) >= 8 ? '…' . substr($key, -4) : '';
    }

    /**
     * The key check-ins use: the one saved on the Licence page if present, else LICENCE_KEY from .env.
     * @return array{key:string,source:string,hint:string} source is saved|server|none
     */
    function rh_licence_key_info(): array
    {
        $saved = rh_licence_key_override();
        if ($saved !== '') {
            return ['key' => $saved, 'source' => 'saved', 'hint' => rh_licence_key_hint($saved)];
        }
        $env = rh_licence_env('LICENCE_KEY');
        return ['key' => $env, 'source' => $env !== '' ? 'server' : 'none', 'hint' => rh_licence_key_hint($env)];
    }

    /** Write or (with null) delete the saved key. Clears any cached copy. */
    function rh_licence_key_override_write(PDO $pdo, ?string $plain): bool
    {
        $key = rh_licence_key_setting();
        try {
            if ($plain === null) {
                $pdo->prepare('DELETE FROM site_settings WHERE setting_key = ?')->execute([$key]);
            } else {
                $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP')
                    ->execute([$key, rh_licence_key_encrypt($plain)]);
            }
            if (isset($GLOBALS['_SITE_SETTINGS'][$key])) {
                unset($GLOBALS['_SITE_SETTINGS'][$key]);
            }
            if (function_exists('deleteCache')) {
                deleteCache("setting_{$key}");
            }
            return true;
        } catch (Throwable $e) {
            error_log('licence key write: ' . $e->getMessage());
            return false;
        }
    }

    /** This site's domain: the host of the site_url setting, normalised exactly as the panel does. */
    function rh_licence_site_domain(): string
    {
        return lv_normalise_domain((string)(function_exists('getSetting') ? getSetting('site_url') : ''));
    }

    // ------------------------------------------------------------------ storage

    /** @return string[] the four setting keys, in the order token, issued, check, error */
    function rh_licence_keys(string $prefix = 'licence_'): array
    {
        return [$prefix . 'token', $prefix . 'last_issued_at', $prefix . 'last_check_at', $prefix . 'last_error'];
    }

    /** Fresh read (one query, no cache). @return array{token:string,last_issued_at:int,last_check_at:int,last_error:string} */
    function rh_licence_store_read(?PDO $pdo, string $prefix = 'licence_'): array
    {
        $out = ['token' => '', 'last_issued_at' => 0, 'last_check_at' => 0, 'last_error' => ''];
        if (!($pdo instanceof PDO)) {
            return $out;
        }
        try {
            $keys = rh_licence_keys($prefix);
            $st = $pdo->prepare('SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (?, ?, ?, ?)');
            $st->execute($keys);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $v = (string)$row['setting_value'];
                switch ($row['setting_key']) {
                    case $keys[0]: $out['token'] = $v; break;
                    case $keys[1]: $out['last_issued_at'] = max(0, (int)$v); break;
                    case $keys[2]: $out['last_check_at'] = max(0, (int)$v); break;
                    case $keys[3]: $out['last_error'] = $v; break;
                }
            }
        } catch (Throwable $e) {
            error_log('licence store read: ' . $e->getMessage());
        }
        return $out;
    }

    /** One setting write ($name is token|last_issued_at|last_check_at|last_error). Clears any cached copy. */
    function rh_licence_store_write(PDO $pdo, string $name, string $value, string $prefix = 'licence_'): bool
    {
        if (!in_array($name, ['token', 'last_issued_at', 'last_check_at', 'last_error'], true)) {
            return false;
        }
        $key = $prefix . $name;
        try {
            $st = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP');
            $st->execute([$key, $value]);
            if (isset($GLOBALS['_SITE_SETTINGS'][$key])) {
                unset($GLOBALS['_SITE_SETTINGS'][$key]);
            }
            if (function_exists('deleteCache')) {
                deleteCache("setting_{$key}");
            }
            return true;
        } catch (Throwable $e) {
            error_log('licence store write: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------ pure status

    /**
     * The status for a stored record. Pure: no database, no clock, no constants (the tests drive it).
     *
     * @param array $stored ['token' => string, 'last_issued_at' => int]
     * @return array{status:string,reason:string,message:string,source:string,issued_at:?int,payload:?array,business_type:?string,modules:?array}
     */
    function rh_licence_evaluate(array $stored, string $publicKey, string $tenant, string $domain, int $now): array
    {
        $token = (string)($stored['token'] ?? '');
        $last = max(0, (int)($stored['last_issued_at'] ?? 0));
        $base = ['source' => 'fallback', 'issued_at' => null, 'payload' => null, 'business_type' => null, 'modules' => null];

        if ($token === '') {
            if ($last > 0) {
                // A licence was received once and the record has since gone: deleting it must not unlock anything.
                return ['status' => 'grace', 'reason' => 'token_missing',
                    'message' => 'The licence record is missing, so the licence could not be verified. Press "Check now".'] + $base;
            }
            return ['status' => 'active', 'reason' => 'no_token',
                'message' => 'No licence answer has been received yet, so the system runs normally until the first check succeeds.'] + $base;
        }

        $r = lv_evaluate($token, $publicKey, $tenant, $domain, $last, $now, 'grace');
        if (($r['reason'] ?? '') !== 'ok') {
            return ['status' => 'grace', 'reason' => (string)$r['reason'],
                'message' => 'The licence could not be verified (' . rh_licence_reason_words((string)$r['reason']) . '). Press "Check now"; if it stays this way contact the system provider.'] + $base;
        }
        return [
            'status' => (string)$r['status'], 'reason' => 'ok', 'message' => '', 'source' => (string)$r['source'],
            'issued_at' => $r['issued_at'], 'payload' => $r['payload'],
            'business_type' => $r['business_type'], 'modules' => $r['modules'],
        ];
    }

    function rh_licence_reason_words(string $reason): string
    {
        $map = [
            'malformed' => 'the stored licence is damaged', 'bad_signature' => 'the stored licence was changed or is not genuine',
            'bad_public_key' => 'this build has no usable licence key', 'bad_payload' => 'the stored licence is incomplete',
            'tenant_mismatch' => 'it belongs to a different account', 'domain_mismatch' => 'it belongs to a different website address',
            'replayed' => 'it is older than one already received', 'error' => 'an internal error', 'token_missing' => 'the record is missing',
        ];
        return $map[$reason] ?? 'unknown reason';
    }

    /**
     * Verify an answer from the panel and say whether it may be stored. Pure.
     *
     * @return array{ok:bool,reason:string,payload:?array}
     */
    function rh_licence_accept(array $answer, string $publicKey, string $tenant, string $domain, int $lastIssuedAt, int $now): array
    {
        return lv_check_token($answer, $publicKey, $tenant, $domain, $lastIssuedAt, $now);
    }

    /** The dates a status screen needs, from a verified payload. @return array<string,string> (all Y-m-d) */
    function rh_licence_dates(array $payload): array
    {
        $paid = (string)($payload['paid_until'] ?? '');
        if (!lv_valid_date($paid)) {
            return [];
        }
        $graceEnd = lv_add_days($paid, (int)($payload['grace_days'] ?? 0));
        $roEnd = lv_add_days($graceEnd, (int)($payload['readonly_days'] ?? 0));
        return [
            'paid_until' => $paid,
            'grace_end' => $graceEnd,                        // last day of full use after the due date
            'read_only_from' => lv_add_days($graceEnd, 1),   // first day staff can only view / check out / take payments
            'read_only_end' => $roEnd,
            'suspended_from' => lv_add_days($roEnd, 1),      // first day the admin is locked
        ];
    }

    // ------------------------------------------------------------------ status for this request

    /**
     * The licence status for THIS request (cached for the request). Keys: status, reason, message, source, issued_at,
     * payload, business_type, modules, dates, configured, last_check_at, last_error, last_issued_at, contact.
     * 'unconfigured' means licensing is off. A failure of our own (no database, exception) answers 'active':
     * a fault here must never lock a hotel.
     */
    function rh_licence_status(bool $refresh = false): array
    {
        static $cache = null;
        if ($cache !== null && !$refresh) {
            return $cache;
        }
        if (!rh_licence_enabled()) {
            return $cache = ['status' => 'unconfigured', 'reason' => 'unconfigured', 'message' => '', 'source' => 'none', 'issued_at' => null,
                'payload' => null, 'business_type' => null, 'modules' => null, 'dates' => [], 'configured' => false,
                'last_check_at' => 0, 'last_error' => '', 'last_issued_at' => 0, 'contact' => ''];
        }
        $cfg = rh_licence_config(false);
        $res = ['status' => 'active', 'reason' => 'error', 'message' => '', 'source' => 'fallback', 'issued_at' => null,
            'payload' => null, 'business_type' => null, 'modules' => null];
        $stored = ['token' => '', 'last_issued_at' => 0, 'last_check_at' => 0, 'last_error' => ''];
        try {
            $pdo = $GLOBALS['pdo'] ?? null;
            if ($pdo instanceof PDO) {
                $stored = rh_licence_store_read($pdo);
                $res = rh_licence_evaluate($stored, rh_licence_public_key(), $cfg['tenant'], rh_licence_site_domain(), time());
            } else {
                $res['reason'] = 'no_db';
            }
        } catch (Throwable $e) {
            error_log('licence status: ' . $e->getMessage());
        }
        $res['dates'] = is_array($res['payload']) ? rh_licence_dates($res['payload']) : [];
        $res['configured'] = true;
        $res['last_check_at'] = $stored['last_check_at'];
        $res['last_error'] = $stored['last_error'];
        $res['last_issued_at'] = $stored['last_issued_at'];
        $res['contact'] = $cfg['contact'];
        return $cache = $res;
    }

    // ------------------------------------------------------------------ talking to the panel

    /**
     * POST JSON to the panel. https only (plain http only to this machine, for local testing); TLS verified; short timeout.
     *
     * @return array{code:int,body:string,error:string}
     */
    function rh_licence_http_post(string $url, string $json, int $timeout = 6): array
    {
        $out = ['code' => 0, 'body' => '', 'error' => ''];
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($host === '' || ($scheme !== 'https' && !($scheme === 'http' && $local))) {
            $out['error'] = 'LICENCE_URL must be an https address';
            return $out;
        }
        $timeout = max(1, min(15, $timeout));
        if (function_exists('curl_init')) {
            $buf = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min($timeout, 4),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_WRITEFUNCTION => static function ($c, $data) use (&$buf) {
                    $buf .= $data;
                    return strlen($buf) > 65536 ? 0 : strlen($data); // an answer is ~600 bytes: refuse anything big
                },
            ]);
            // Hosts (and Windows PHP) without curl.cainfo cannot verify any certificate: fall back to the bundled
            // CA list, as the Facebook helpers do. Verification stays ON either way.
            $ca = (string)ini_get('curl.cainfo');
            if ($ca === '' || !is_file($ca)) {
                $localCa = dirname(__DIR__) . '/config/cacert.pem';
                if (is_file($localCa)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $localCa);
                }
            }
            $ok = curl_exec($ch);
            $out['code'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($ok === false) {
                $out['error'] = 'connection failed (' . curl_errno($ch) . ')';
            }
            curl_close($ch);
            $out['body'] = $buf;
            return $out;
        }
        $ctx = stream_context_create([
            'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n", 'content' => $json,
                'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx, 0, 65537);
        if ($body === false) {
            $out['error'] = 'connection failed';
            return $out;
        }
        if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $out['code'] = (int)$m[1];
        }
        $out['body'] = strlen($body) > 65536 ? '' : $body;
        return $out;
    }

    /**
     * Ask the panel now, verify the answer, store it only if it verifies. Never throws.
     * Writes licence_last_check_at at the START of the attempt (so a failing panel is not hammered) and licence_last_error.
     *
     * @param bool $onlyIfDue re-check the schedule under the lock (used by the lazy daily check)
     * @return array{ok:bool,status:string,error:string,ran:bool}
     */
    function rh_licence_check_now(bool $onlyIfDue = false, int $timeout = 6): array
    {
        $res = ['ok' => false, 'status' => 'unconfigured', 'error' => '', 'ran' => false];
        $pdo = $GLOBALS['pdo'] ?? null;
        $locked = false;
        try {
            if (!rh_licence_enabled()) {
                $res['error'] = 'Licensing is not switched on for this site.';
                return $res;
            }
            if (!($pdo instanceof PDO)) {
                $res['error'] = 'No database connection.';
                return $res;
            }
            // One check at a time (several staff tabs, an outage): the panel limits answers per hour.
            try {
                $locked = (int)$pdo->query("SELECT GET_LOCK('rh_licence_check', 0)")->fetchColumn() === 1;
            } catch (Throwable $e) {
                $locked = false;
            }
            if (!$locked) {
                $res['error'] = 'Another check is already running.';
                $res['status'] = rh_licence_status()['status'];
                return $res;
            }
            $now = time();
            $stored = rh_licence_store_read($pdo);
            if ($onlyIfDue && !rh_licence_check_due($stored, $now)) {
                $res['status'] = rh_licence_status()['status'];
                return $res;
            }
            $res['ran'] = true;
            rh_licence_store_write($pdo, 'last_check_at', (string)$now);

            $cfg = rh_licence_config();
            $domain = rh_licence_site_domain();
            $fail = static function (string $why) use ($pdo, &$res): array {
                rh_licence_store_write($pdo, 'last_error', mb_substr($why, 0, 240));
                $res['error'] = $why;
                $res['status'] = rh_licence_status(true)['status'];
                return $res;
            };
            $a = rh_licence_ask_panel($cfg, $domain, $stored['last_issued_at'], $now, $timeout);
            if (!$a['ok']) {
                return $fail($a['error']);
            }
            if (!rh_licence_store_accepted($pdo, $a['answer'], $a['payload'], $stored['last_issued_at'])) {
                return $fail('The licence answer could not be saved.');
            }
            $res['ok'] = true;
            $res['status'] = rh_licence_status(true)['status'];
            return $res;
        } catch (Throwable $e) {
            error_log('licence check: ' . $e->getMessage());
            $res['error'] = 'The check failed unexpectedly.';
            return $res;
        } finally {
            if ($locked && $pdo instanceof PDO) {
                try { $pdo->query("SELECT RELEASE_LOCK('rh_licence_check')")->fetchColumn(); } catch (Throwable $e) { /* released at disconnect */ }
            }
        }
    }

    /**
     * One real check-in with the key in $cfg['key']: send it, verify the signed answer (signature, tenant, domain, anti-replay).
     * Stores nothing. Error texts never contain the key.
     *
     * @return array{ok:bool,error:string,answer:?array,payload:?array}
     */
    function rh_licence_ask_panel(array $cfg, string $domain, int $lastIssuedAt, int $now, int $timeout = 6): array
    {
        $out = ['ok' => false, 'error' => '', 'answer' => null, 'payload' => null];
        if ($cfg['url'] === '' || $cfg['key'] === '') {
            $out['error'] = $cfg['url'] === '' ? 'LICENCE_URL is not set in this site\'s .env.' : 'There is no licence key yet. Save one on the Licence page or set LICENCE_KEY in this site\'s .env.';
            return $out;
        }
        if ($domain === '') {
            $out['error'] = 'The site address (site_url setting) is empty, so the licence cannot be matched to this site.';
            return $out;
        }
        $body = json_encode(['tenant' => $cfg['tenant'], 'domain' => $domain, 'key' => $cfg['key'],
            'version' => 'pms-' . date('Ymd', (int)@filemtime(__FILE__))], JSON_UNESCAPED_SLASHES);
        $http = rh_licence_http_post($cfg['url'], (string)$body, $timeout);
        if ($http['error'] !== '') {
            $out['error'] = 'Could not reach the licence service: ' . $http['error'] . '.';
            return $out;
        }
        $answer = json_decode($http['body'], true);
        if ($http['code'] !== 200 || !is_array($answer)) {
            $code = (is_array($answer) && isset($answer['error']) && is_string($answer['error']) && preg_match('/^[a-z_]{1,30}$/', $answer['error']))
                ? ' (' . $answer['error'] . ')' : '';
            $out['error'] = 'The licence service answered HTTP ' . $http['code'] . $code . '.';
            return $out;
        }
        $v = rh_licence_accept($answer, rh_licence_public_key(), $cfg['tenant'], $domain, $lastIssuedAt, $now);
        if (!$v['ok']) {
            $out['error'] = 'The licence service answer was not accepted (' . rh_licence_reason_words((string)$v['reason']) . ').';
            return $out;
        }
        return ['ok' => true, 'error' => '', 'answer' => $answer, 'payload' => (array)$v['payload']];
    }

    /** Store a VERIFIED answer: token first, then the anti-replay number (a crash between them leaves a token that still verifies), then clear the error. */
    function rh_licence_store_accepted(PDO $pdo, array $answer, array $payload, int $lastIssuedAt): bool
    {
        $tokenJson = json_encode(['payload' => $answer['payload'], 'sig' => $answer['sig']], JSON_UNESCAPED_SLASHES);
        if (!rh_licence_store_write($pdo, 'token', (string)$tokenJson)) {
            return false;
        }
        rh_licence_store_write($pdo, 'last_issued_at', (string)max($lastIssuedAt, (int)$payload['issued_at']));
        rh_licence_store_write($pdo, 'last_error', '');
        return true;
    }

    /** Characters allowed in a licence key (also what the stored copy can round-trip). */
    function rh_licence_key_valid(string $key): bool
    {
        return strlen($key) >= 8 && strlen($key) <= 200 && preg_match('/^[\x21-\x7E]+$/', $key) === 1;
    }

    /**
     * Test a new licence key with a real check-in and save it ONLY if the panel accepts it (verified signed answer for THIS
     * tenant and domain). On any failure nothing is written: not the key, not the stored status, not the error record.
     * Needs licensing switched on; LICENCE_URL and LICENCE_TENANT are always the .env ones.
     *
     * @return array{ok:bool,error:string,status:string}
     */
    function rh_licence_set_key(string $newKey, int $timeout = 6): array
    {
        $res = ['ok' => false, 'error' => '', 'status' => ''];
        $pdo = $GLOBALS['pdo'] ?? null;
        $locked = false;
        try {
            if (!rh_licence_enabled()) {
                $res['error'] = 'Licensing is not switched on for this site.';
                return $res;
            }
            $newKey = trim($newKey);
            if (!rh_licence_key_valid($newKey)) {
                $res['error'] = 'That does not look like a licence key (8 to 200 letters, numbers or symbols, no spaces).';
                return $res;
            }
            if (!($pdo instanceof PDO)) {
                $res['error'] = 'No database connection.';
                return $res;
            }
            try {
                $locked = (int)$pdo->query("SELECT GET_LOCK('rh_licence_check', 0)")->fetchColumn() === 1;
            } catch (Throwable $e) {
                $locked = false;
            }
            if (!$locked) {
                $res['error'] = 'Another check is already running. Try again in a moment.';
                return $res;
            }
            $now = time();
            $stored = rh_licence_store_read($pdo);
            $cfg = rh_licence_config(false);
            $cfg['key'] = $newKey;
            $a = rh_licence_ask_panel($cfg, rh_licence_site_domain(), $stored['last_issued_at'], $now, $timeout);
            if (!$a['ok']) {
                $res['error'] = $a['error'];
                return $res;
            }
            if (!rh_licence_key_override_write($pdo, $newKey)) {
                $res['error'] = 'The key was accepted but could not be saved. The old key is still in use.';
                return $res;
            }
            rh_licence_store_accepted($pdo, $a['answer'], $a['payload'], $stored['last_issued_at']);
            rh_licence_store_write($pdo, 'last_check_at', (string)$now);
            $res['ok'] = true;
            $res['status'] = rh_licence_status(true)['status'];
            return $res;
        } catch (Throwable $e) {
            error_log('licence set key: ' . $e->getMessage());
            $res['error'] = 'The key could not be tested. Nothing was changed.';
            return $res;
        } finally {
            if ($locked && $pdo instanceof PDO) {
                try { $pdo->query("SELECT RELEASE_LOCK('rh_licence_check')")->fetchColumn(); } catch (Throwable $e) { /* released at disconnect */ }
            }
        }
    }

    /** Drop the key saved on the Licence page so check-ins use LICENCE_KEY from .env again. Changes no status. */
    function rh_licence_clear_key_override(): bool
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        return rh_licence_enabled() && $pdo instanceof PDO && rh_licence_key_override_write($pdo, null);
    }

    /** Minutes between automatic checks: LICENCE_CHECK_MINUTES in .env, default 60 (an hourly safety net; the panel also nudges the site straight after any change). 5 to 1440. */
    function rh_licence_check_interval_seconds(): int
    {
        $m = rh_licence_env('LICENCE_CHECK_MINUTES');
        $m = ctype_digit($m) ? (int)$m : 60;
        return max(5, min(1440, $m)) * 60;
    }

    /** Due every interval (default hourly), and again after 30 minutes when the last attempt failed. Pure apart from the interval setting. */
    function rh_licence_check_due(array $stored, int $now): bool
    {
        $last = (int)($stored['last_check_at'] ?? 0);
        if ($last <= 0 || $now - $last >= rh_licence_check_interval_seconds()) {
            return true;
        }
        return ((string)($stored['last_error'] ?? '')) !== '' && $now - $last >= 1800;
    }

    /**
     * Lazy daily check: from an admin page load, AFTER the response has been sent (the page never waits on the panel).
     * Registers one shutdown hook per request; does nothing when licensing is off.
     */
    function rh_licence_maybe_check_after_response(): void
    {
        static $registered = false;
        if ($registered || PHP_SAPI === 'cli' || !rh_licence_enabled()) {
            return;
        }
        try {
            $pdo = $GLOBALS['pdo'] ?? null;
            if (!rh_licence_check_due(rh_licence_store_read($pdo instanceof PDO ? $pdo : null), time())) {
                return;
            }
            $registered = true;
            register_shutdown_function(static function (): void {
                try {
                    @ignore_user_abort(true);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        @session_write_close();
                    }
                    $detached = false;
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request();
                        $detached = true;
                    } elseif (function_exists('litespeed_finish_request')) {
                        @litespeed_finish_request();
                        $detached = true;
                    } else {
                        while (ob_get_level() > 0) {
                            @ob_end_flush();
                        }
                        @flush();
                    }
                    // Not truly detached (some hosts): keep the wait short so the visitor is not held for long.
                    rh_licence_check_now(true, $detached ? 6 : 3);
                } catch (Throwable $e) {
                    error_log('licence lazy check: ' . $e->getMessage());
                }
            });
        } catch (Throwable $e) {
            error_log('licence lazy check setup: ' . $e->getMessage());
        }
    }

    /**
     * The panel's nudge (api/licence-ping.php): "something changed, check now". It carries no data and no authority —
     * the site still fetches and verifies the signed answer itself — so the worst a stranger can do is cause an extra
     * check, and that is limited to one a minute. The check runs after the reply is sent.
     * @return bool true when a check was scheduled
     */
    function rh_licence_nudge(int $now = 0): bool
    {
        static $registered = false;
        if ($registered || !rh_licence_enabled()) {
            return false;
        }
        try {
            $now = $now > 0 ? $now : time();
            $pdo = $GLOBALS['pdo'] ?? null;
            $stored = rh_licence_store_read($pdo instanceof PDO ? $pdo : null);
            if ($now - (int)($stored['last_check_at'] ?? 0) < 60) {
                return false;
            }
            $registered = true;
            register_shutdown_function(static function (): void {
                try {
                    @ignore_user_abort(true);
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request();
                    } elseif (function_exists('litespeed_finish_request')) {
                        @litespeed_finish_request();
                    } else {
                        while (ob_get_level() > 0) {
                            @ob_end_flush();
                        }
                        @flush();
                    }
                    rh_licence_check_now(false, 6);
                } catch (Throwable $e) {
                    error_log('licence nudge: ' . $e->getMessage());
                }
            });
            return true;
        } catch (Throwable $e) {
            error_log('licence nudge setup: ' . $e->getMessage());
            return false;
        }
    }
}
