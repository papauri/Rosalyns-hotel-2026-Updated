<?php
/**
 * Public-page CSRF helpers.
 * Lightweight session-based CSRF token for public forms (booking, contact, gym, conference).
 * Each logical form type has its own session key so tokens do not cross-contaminate.
 */

if (!function_exists('pub_csrf_generate')) {
    /**
     * Returns the CSRF token for the given form key, generating it once per session.
     *
     * @param  string $form_key  Short identifier: 'booking', 'contact', 'gym', 'conference'
     * @return string            64-character hex token
     */
    function pub_csrf_generate(string $form_key): string
    {
        $skey = '_csrf_pub_' . $form_key;
        if (empty($_SESSION[$skey])) {
            $_SESSION[$skey] = bin2hex(random_bytes(32));
        }
        return $_SESSION[$skey];
    }

    /**
     * Validates and rotates the CSRF token for the given form key.
     * Rotates on success so each submission requires a fresh page load to resubmit.
     *
     * @param  string $token     Token from POST
     * @param  string $form_key  Must match the key used in pub_csrf_generate()
     * @return bool
     */
    function pub_csrf_validate(string $token, string $form_key): bool
    {
        $skey = '_csrf_pub_' . $form_key;
        $expected = $_SESSION[$skey] ?? '';
        if ($expected === '' || !hash_equals($expected, $token)) {
            return false;
        }
        // Rotate after valid use
        unset($_SESSION[$skey]);
        return true;
    }

    /**
     * IP-based rate limiter stored in session.
     * Allows max $limit requests per $window_seconds from the same session.
     *
     * @param  string $key     Unique limiter key, e.g. 'contact_form'
     * @param  int    $limit
     * @param  int    $window_seconds
     * @return bool            true = allowed, false = blocked
     */
    function pub_rate_limit(string $key, int $limit = 5, int $window_seconds = 600): bool
    {
        $skey = '_rl_' . $key;
        $now  = time();

        if (!isset($_SESSION[$skey]) || !is_array($_SESSION[$skey])) {
            $_SESSION[$skey] = [];
        }

        // Remove timestamps outside the window
        $_SESSION[$skey] = array_filter(
            $_SESSION[$skey],
            fn(int $ts) => ($now - $ts) < $window_seconds
        );

        if (count($_SESSION[$skey]) >= $limit) {
            return false;
        }

        $_SESSION[$skey][] = $now;
        return true;
    }
}

if (!function_exists('pub_client_ip')) {
    /** Client IP (REMOTE_ADDR only — forwarded headers are spoofable). */
    function pub_client_ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Per-IP rate limiter backed by the file cache (no schema). Complements the
     * session limiter, which a client defeats by dropping its cookie.
     * Falls back to a temp-dir file if the cache layer is off/unavailable.
     *
     * @return bool true = allowed, false = blocked
     */
    function pub_ip_rate_limit(string $action, int $limit = 10, int $window_seconds = 600): bool
    {
        $key = 'iprl_' . $action . '_' . substr(hash('sha256', pub_client_ip() . '|' . $action), 0, 24);
        $now = time();
        $hits = null;
        $useCache = function_exists('getCache') && function_exists('setCache');
        if ($useCache) {
            $hits = getCache($key, null, 'ratelimit');
        }
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $key . '.json';
        if (!is_array($hits)) {
            $raw = is_file($file) ? @file_get_contents($file) : false;
            $hits = $raw ? json_decode($raw, true) : [];
            if (!is_array($hits)) {
                $hits = [];
            }
        }
        $hits = array_values(array_filter($hits, fn($ts) => is_int($ts) && ($now - $ts) < $window_seconds));
        if (count($hits) >= $limit) {
            return false;
        }
        $hits[] = $now;
        $stored = $useCache ? setCache($key, $hits, $window_seconds, 'ratelimit') : false;
        // Always mirror to the temp file so a disabled cache cannot switch the limit off.
        @file_put_contents($file, json_encode($hits), LOCK_EX);
        return true;
    }

    /** Remember a just-created reference so ITS confirmation page may show full details. */
    function pub_confirm_remember(string $type, string $ref): void
    {
        if ($ref === '') {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $list = $_SESSION['_confirm_refs'] ?? [];
        $now = time();
        $list = array_values(array_filter(is_array($list) ? $list : [], fn($e) => is_array($e) && ($now - (int)($e['t'] ?? 0)) < 86400));
        $list[] = ['k' => $type, 'r' => $ref, 't' => $now];
        $_SESSION['_confirm_refs'] = array_slice($list, -10);
    }

    /** True if this session created $ref (of $type) within the last 24h. */
    function pub_confirm_allowed(string $type, string $ref): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $now = time();
        foreach ((array)($_SESSION['_confirm_refs'] ?? []) as $e) {
            if (is_array($e) && ($e['k'] ?? '') === $type && hash_equals((string)($e['r'] ?? ''), $ref)
                && ($now - (int)($e['t'] ?? 0)) < 86400) {
                return true;
            }
        }
        return false;
    }
}
