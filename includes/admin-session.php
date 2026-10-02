<?php

/**
 * Admin / POS / KDS session lifetime (owner decision 2026-10-02: 8 hours idle = one shift).
 *
 * rh_admin_session_start() replaces session_start() in every entry point that serves a
 * signed-in admin (admin/admin-init.php, admin/api/api-init.php, the POS/KDS APIs). It
 * keeps PHP's garbage collector from deleting the session before the idle limit, then
 * signs the user out once they have been idle for RH_ADMIN_IDLE_SECONDS. The caller's
 * existing "not signed in" branch then redirects (pages) or answers 401 (APIs).
 *
 * Only real activity resets the clock: page loads and POST/PUT/DELETE requests. Background
 * GET polling (KDS tickets, POS notifications) does not, so a screen left open still times out.
 *
 * rh_admin_client_script() prints the matching browser side: the hotel UTC offset for
 * parsing database times, and a fetch() hook that sends the user to the login page when
 * an API answers 401.
 */

if (!defined('RH_ADMIN_IDLE_SECONDS')) {
    define('RH_ADMIN_IDLE_SECONDS', 8 * 3600);
}

if (!function_exists('rh_admin_session_start')) {

    /** True for a request a person made (navigation or a change), false for background polling. */
    function rh_admin_request_is_activity(): bool
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return true;
        }
        $mode = (string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
        if ($mode !== '') {
            return $mode === 'navigate';
        }
        if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return false;
        }
        return stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') !== false;
    }

    function rh_admin_session_start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @ini_set('session.gc_maxlifetime', (string)(RH_ADMIN_IDLE_SECONDS + 1800));
            session_start();
        }
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['admin_user_id'])) {
            return;
        }

        $now = time();
        $last = (int)($_SESSION['admin_last_activity'] ?? 0);
        if ($last > 0 && ($now - $last) > RH_ADMIN_IDLE_SECONDS) {
            error_log('[admin-session] user ' . (int)$_SESSION['admin_user_id'] . ' signed out after ' . round(($now - $last) / 3600, 1) . 'h idle');
            $_SESSION = [];
            @session_regenerate_id(true);
            $_SESSION['admin_logout_reason'] = 'idle';
            if (!headers_sent()) {
                header('X-Session-Expired: 1');
            }
            return;
        }
        if ($last === 0 || rh_admin_request_is_activity()) {
            $_SESSION['admin_last_activity'] = $now;
        }
    }

    /** Browser side: hotel UTC offset + redirect to login when the session has expired. */
    function rh_admin_client_script(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $offsetMin = (int)round((new DateTime('now'))->getOffset() / 60);
        $login = (defined('BASE_URL') ? rtrim((string)BASE_URL, '/') . '/admin/' : '') . 'login.php?reason=idle';
        ?>
<script>
(function () {
    var OFFSET_MIN = <?php echo $offsetMin; ?>;
    var sign = OFFSET_MIN < 0 ? '-' : '+', abs = Math.abs(OFFSET_MIN);
    // Database times are hotel-local ("YYYY-MM-DD HH:MM:SS"); append this to parse them.
    window.RH_TZ_OFFSET = sign + String(Math.floor(abs / 60)).padStart(2, '0') + ':' + String(abs % 60).padStart(2, '0');
    // "Now" as a hotel-local database string, for optimistic rows and queued forms.
    window.rhNowSql = function () {
        return new Date(Date.now() + OFFSET_MIN * 60000).toISOString().slice(0, 19).replace('T', ' ');
    };
    // Calendar date / datetime-local value of a Date in the device's own clock. Use these, not
    // toISOString(), which converts to UTC and lands on the previous day before 02:00 (UTC+2)
    // and for any local-midnight date (first of the month, expiry dates, ...).
    function pad2(n) { return String(n).padStart(2, '0'); }
    window.rhYmd = function (d) {
        d = d || new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    };
    window.rhYmdHm = function (d) {
        d = d || new Date();
        return window.rhYmd(d) + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    };
    if (window.fetch && !window.__rhSessionGuard) {
        window.__rhSessionGuard = true;
        var orig = window.fetch;
        var login = <?php echo json_encode($login); ?>;
        window.fetch = function () {
            return orig.apply(this, arguments).then(function (r) {
                var expired = r && (r.status === 401
                    || (r.headers && r.headers.get('X-Session-Expired') === '1')
                    || (r.redirected && /\/admin\/login\.php/.test(r.url || '')));
                if (expired) {
                    var u = new URL(r.url || '', location.href);
                    if (u.origin === location.origin) {
                        location.href = login;
                    }
                }
                return r;
            });
        };
    }
})();
</script>
        <?php
    }
}
