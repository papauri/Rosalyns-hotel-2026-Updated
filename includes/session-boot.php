<?php

/**
 * One place that starts every PHP session on the site (admin, POS/KDS APIs and guest pages).
 *
 * Why: sessions used the host's shared save path and the host's garbage-collection lifetime.
 * On cPanel / LiteSpeed hosts .user.ini is not read, PHP's default lifetime is 24 minutes or
 * less, and the host's session cleaner (or any guest page that triggers PHP's own GC) deletes
 * every session file idle for longer than that — staff were signed out after ~15 minutes even
 * though the app allows 8 hours idle (includes/admin-session.php).
 *
 * rh_session_start() keeps this site's sessions in its own private folder (storage/sessions,
 * web access denied) with an 8h30 lifetime, so neither the host cleaner nor another app's GC
 * can remove them early. Every entry point must use it — a page starting a session on the
 * default path would not find the visitor's session and, with strict mode on, would replace
 * their cookie (signing staff out).
 */

if (!function_exists('rh_session_start')) {

    /** Private session folder, created on first use; null when it cannot be made writable. */
    function rh_session_save_dir(): ?string
    {
        $dir = dirname(__DIR__) . '/storage/sessions';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
        if (!is_writable($dir)) {
            return null;
        }
        $guard = dirname($dir) . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "# Private storage - never served.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        if (!is_file(dirname($dir) . '/index.html')) {
            @file_put_contents(dirname($dir) . '/index.html', '');
        }
        return $dir;
    }

    function rh_session_start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        // 8h staff idle limit + 30 min margin (must outlive RH_ADMIN_IDLE_SECONDS).
        @ini_set('session.gc_maxlifetime', '30600');
        // Test harnesses that run pages in-process keep their own session folder.
        $dir = defined('RH_SESSION_KEEP_SAVE_PATH') ? null : rh_session_save_dir();
        if ($dir !== null) {
            @ini_set('session.save_path', $dir);
            // Hosts often switch PHP's own GC off and rely on their cleaner, which never looks
            // in this folder; run GC here now and then so old files do not pile up.
            @ini_set('session.gc_probability', '1');
            @ini_set('session.gc_divisor', '500');
        }
        session_start();
    }
}
