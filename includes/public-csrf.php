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
