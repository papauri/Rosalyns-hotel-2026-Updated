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

    /** @return array{url:string,tenant:string,key:string,contact:string} */
    function rh_licence_config(): array
    {
        return [
            'url'     => rh_licence_env('LICENCE_URL'),
            'tenant'  => rh_licence_env('LICENCE_TENANT'),
            'key'     => rh_licence_env('LICENCE_KEY'),
            'contact' => rh_licence_env('LICENCE_CONTACT'),
        ];
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
        $cfg = rh_licence_config();
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
            if ($cfg['url'] === '' || $cfg['key'] === '') {
                return $fail('LICENCE_URL or LICENCE_KEY is not set in this site\'s .env.');
            }
            if ($domain === '') {
                return $fail('The site address (site_url setting) is empty, so the licence cannot be matched to this site.');
            }
            $body = json_encode(['tenant' => $cfg['tenant'], 'domain' => $domain, 'key' => $cfg['key'],
                'version' => 'pms-' . date('Ymd', (int)@filemtime(__FILE__))], JSON_UNESCAPED_SLASHES);
            $http = rh_licence_http_post($cfg['url'], (string)$body, $timeout);
            if ($http['error'] !== '') {
                return $fail('Could not reach the licence service: ' . $http['error'] . '.');
            }
            $answer = json_decode($http['body'], true);
            if ($http['code'] !== 200 || !is_array($answer)) {
                $code = (is_array($answer) && isset($answer['error']) && is_string($answer['error']) && preg_match('/^[a-z_]{1,30}$/', $answer['error']))
                    ? ' (' . $answer['error'] . ')' : '';
                return $fail('The licence service answered HTTP ' . $http['code'] . $code . '.');
            }
            $v = rh_licence_accept($answer, rh_licence_public_key(), $cfg['tenant'], $domain, $stored['last_issued_at'], $now);
            if (!$v['ok']) {
                return $fail('The licence service answer was not accepted (' . rh_licence_reason_words((string)$v['reason']) . ').');
            }
            // Token first, then the anti-replay number: a crash between them leaves a token that still verifies.
            $tokenJson = json_encode(['payload' => $answer['payload'], 'sig' => $answer['sig']], JSON_UNESCAPED_SLASHES);
            if (!rh_licence_store_write($pdo, 'token', (string)$tokenJson)) {
                return $fail('The licence answer could not be saved.');
            }
            rh_licence_store_write($pdo, 'last_issued_at', (string)max($stored['last_issued_at'], (int)$v['payload']['issued_at']));
            rh_licence_store_write($pdo, 'last_error', '');
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

    /** Daily, and again after 30 minutes when the last attempt failed. Pure. */
    function rh_licence_check_due(array $stored, int $now): bool
    {
        $last = (int)($stored['last_check_at'] ?? 0);
        if ($last <= 0 || $now - $last >= 86400) {
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
}
