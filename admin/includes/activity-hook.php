<?php

/**
 * Staff activity hook (System Logs > Staff activity).
 *
 * One cheap, central record of "staff saved something": rh_sa_hook_register() is called at the very end of
 * admin/admin-init.php and admin/api/api-init.php. For a POST that finishes successfully it writes ONE
 * admin_activity_log row (action 'page_action') after the page has run.
 *
 * What is stored: the page file name and the action name(s) only ("page=bookings.php; action=update_status").
 * Never a request body, a value, a password, a token or an id. An action value is stored only when it looks like a
 * plain code word (letters, digits, - and _ up to 40 characters); anything else is stored as "other".
 *
 * What is skipped: every GET; pure reads that pages send as POST (the same read rule as the licence guard); background
 * presence / navigation / search / lookup endpoints; the Assistant chat; a request refused by a permission, module or
 * CSRF check; any response of 400 or above; a fatal error; a redirect whose address carries an error.
 * Known limit: a JSON endpoint that answers 200 with success:false looks like a success here and is still recorded.
 *
 * The friendly sentence ("Saved on Bookings") is built when the log is read (admin/includes/staff-activity-lib.php),
 * so the wording can improve without rewriting rows. Never throws, never creates tables, one INSERT.
 */

if (!function_exists('rh_sa_hook_register')) {

    /** Pages (file names) that are background traffic, not staff work. */
    function rh_sa_hook_skip_pages(): array
    {
        $skip = ['ai-chat.php', 'ai-daily.php', 'presence.php', 'nav-favorites.php', 'nav-sidebar-state.php', 'global-search.php',
            'search-bookings.php', 'guest-lookup.php', 'check-unique.php', 'user-check-unique.php', 'system-health.php',
            'get-housekeeping-audit.php', 'get-maintenance-audit.php', 'end-of-day-pdf.php', 'preset-affected-users.php',
            'decisions.php']; // decisions.php: the dashboard Decisions panel refreshes itself on every load (found by the idle soak)
        if (function_exists('rh_licence_rules')) {
            $r = rh_licence_rules();
            foreach ((array)($r['admin_api']['any'] ?? []) as $p) {
                $skip[] = (string)$p;
            }
        }
        return array_values(array_unique($skip));
    }

    /** A request's action code, or '' when absent; unusual values become 'other' so free text can never reach the log. */
    function rh_sa_hook_clean_action($v): string
    {
        if (!is_string($v) || $v === '') {
            return '';
        }
        return preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v) ? $v : 'other';
    }

    /**
     * Decide what to record for one request. Returns the details text, or null when nothing should be recorded.
     * Pure (no globals, no database): the test drives it directly.
     */
    function rh_sa_hook_describe(string $scriptName, string $method, array $get, array $post): ?string
    {
        if (strtoupper($method) !== 'POST') {
            return null;
        }
        $page = strtolower(basename(str_replace('\\', '/', $scriptName)));
        if (!preg_match('/^[a-z0-9_-]{1,80}\.php$/', $page)) {
            return null;
        }
        if (in_array($page, rh_sa_hook_skip_pages(), true)) {
            return null;
        }
        $keys = function_exists('rh_licence_action_keys') ? rh_licence_action_keys() : ['action', 'booking_action', 'charge_action', 'invoice_action', 'ajax'];
        $parts = [];
        $reads = 0;
        $present = 0;
        foreach ($keys as $k) {
            $raw = $post[$k] ?? ($get[$k] ?? null);
            if ($raw === null || $raw === '') {
                continue;
            }
            $present++;
            $v = rh_sa_hook_clean_action($raw);
            if ($v === '') {
                continue;
            }
            if (is_string($raw) && function_exists('rh_licence_is_read_action') && rh_licence_is_read_action($raw)) {
                $reads++;
            }
            $parts[] = $k . '=' . $v;
        }
        if ($present > 0 && $reads === $present) {
            return null; // every named action only reads
        }
        return 'page=' . $page . ($parts ? '; ' . implode('; ', $parts) : '');
    }

    /** Called by the permission / CSRF denial helpers so a refused request is never recorded as work. */
    function rh_sa_hook_mark_refused(): void
    {
        $GLOBALS['rh_sa_refused'] = true;
    }

    /** The shutdown step: re-check the outcome, then one INSERT. */
    function rh_sa_hook_finish(int $uid, string $username, string $details, string $at = ''): void
    {
        try {
            if (!empty($GLOBALS['rh_sa_refused'])) {
                return;
            }
            $code = http_response_code();
            if (is_int($code) && $code >= 400) {
                return;
            }
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            foreach (headers_list() as $h) {
                if (stripos($h, 'Location:') === 0 && preg_match('/[?&](error|err|no_access|access_denied)=/i', $h)) {
                    return;
                }
            }
            $pdo = $GLOBALS['pdo'] ?? null;
            if (!($pdo instanceof PDO)) {
                return;
            }
            $stmt = $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, 'page_action', ?, ?, ?)");
            $stmt->execute([$uid, substr($username, 0, 100), $details, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $at !== '' ? $at : date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            // never break or slow a request over a log row
        }
    }

    /** Call once at the end of the admin / admin-api init. Does nothing for anything but a POST by a signed-in person. */
    function rh_sa_hook_register(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            if (PHP_SAPI === 'cli' && !defined('RH_SA_HOOK_IN_CLI')) {
                return; // web requests only: the scripts and tests that include admin-init from the command line never write rows
            }
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                return;
            }
            $uid = (int)($_SESSION['admin_user_id'] ?? 0);
            if ($uid <= 0) {
                return;
            }
            $details = rh_sa_hook_describe((string)($_SERVER['SCRIPT_NAME'] ?? ''), 'POST', $_GET, $_POST);
            if ($details === null) {
                return;
            }
            $username = (string)($_SESSION['admin_username'] ?? '');
            // The time is taken now: the web scheduler's shutdown step runs first and can take minutes after the answer is sent.
            register_shutdown_function('rh_sa_hook_finish', $uid, $username, $details, date('Y-m-d H:i:s'));
        } catch (Throwable $e) {
        }
    }
}
