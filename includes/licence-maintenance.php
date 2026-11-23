<?php

/**
 * Maintenance windows from the licence panel (owner 2026-11-23; panel app/maintenance.php).
 *
 * The panel's SIGNED licence answer may carry "maintenance": null or {id, scope, announce_from, starts_at, ends_at, message}
 * (unix seconds; scope admin | site | both). includes/licence.php keeps it in site_settings.licence_maintenance (JSON, ''
 * when none) on every accepted answer that has the field, and tells the panel which window it holds (maintenance_seen).
 *
 *   - from announce_from: staff see a notice on every admin page (rh_licence_render_banner);
 *   - from starts_at to ends_at: an admin/both window refuses every staff page, admin API and station screen with a 503
 *     (rh_licence_guard_request; nobody is let through, administrators included); a site/both window shows the guest
 *     website's maintenance page (rh_enforce_frontend_maintenance_mode in config/database.php, ORed with the hotel's own
 *     site_maintenance_enabled switch, which is never changed);
 *   - at ends_at everything is back by this site's own clock, even if the panel is never reached again.
 *
 * Fails open: any missing, damaged or out-of-range data means "online". A window longer than 72 hours is ignored.
 * Never blocked: command-line jobs, the panel's nudge (api/licence-ping.php) and the check-ins it starts, the key-authenticated
 * api/ endpoints, sign-in and sign-out. This file is pure apart from rh_maint_read() and the two responders, and loads nothing.
 */

if (!function_exists('rh_maint_decide')) {

    /** The longest window obeyed (72 hours, the panel's own limit). */
    function rh_maint_max_window(): int
    {
        return 259200;
    }

    /**
     * A block from the panel, checked. Pure. Null when anything is missing, of the wrong type or out of range.
     * @return array{id:int,scope:string,announce_from:int,starts_at:int,ends_at:int,message:string}|null
     */
    function rh_maint_clean($b): ?array
    {
        if (!is_array($b)) {
            return null;
        }
        foreach (['id', 'announce_from', 'starts_at', 'ends_at'] as $k) {
            if (!isset($b[$k]) || !is_int($b[$k]) || $b[$k] <= 0) {
                return null;
            }
        }
        $scope = $b['scope'] ?? null;
        if (!in_array($scope, ['admin', 'site', 'both'], true)) {
            return null;
        }
        if ($b['announce_from'] > $b['starts_at'] || $b['starts_at'] >= $b['ends_at']
            || $b['ends_at'] - $b['starts_at'] > rh_maint_max_window() || $b['starts_at'] - $b['announce_from'] > 2592000) {
            return null;
        }
        $msg = isset($b['message']) && is_string($b['message']) ? trim($b['message']) : '';
        if (strlen($msg) > 4000) {
            return null;
        }
        return ['id' => $b['id'], 'scope' => $scope, 'announce_from' => $b['announce_from'], 'starts_at' => $b['starts_at'],
            'ends_at' => $b['ends_at'], 'message' => $msg];
    }

    /**
     * What $area ('admin' = staff system, 'site' = guest website) should do now. Pure.
     * @return string online | announce | offline
     */
    function rh_maint_decide(int $now, ?array $block, string $area): string
    {
        $b = rh_maint_clean($block);
        if ($b === null || $now <= 0 || !in_array($area, ['admin', 'site'], true)) {
            return 'online';
        }
        if ($b['scope'] !== 'both' && $b['scope'] !== $area) {
            return 'online';
        }
        if ($now >= $b['ends_at']) {
            return 'online';
        }
        if ($now >= $b['starts_at']) {
            return 'offline';
        }
        return $now >= $b['announce_from'] ? 'announce' : 'online';
    }

    /** The value kept in site_settings.licence_maintenance for the panel's field: JSON, or '' (none / not usable). Pure. */
    function rh_maint_store_value($fromPanel): string
    {
        $b = rh_maint_clean($fromPanel);
        return $b === null ? '' : (string)json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The window this site holds, read fresh from the database (never the settings file cache). Null when none or on any fault. */
    function rh_maint_read(?PDO $pdo, bool $refresh = false): ?array
    {
        static $cache = [];
        if (!($pdo instanceof PDO)) {
            return null;
        }
        $k = spl_object_id($pdo);
        if (!$refresh && array_key_exists($k, $cache)) {
            return $cache[$k];
        }
        try {
            $st = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
            $st->execute(['licence_maintenance']);
            $v = $st->fetchColumn();
            $b = is_string($v) && $v !== '' ? rh_maint_clean(json_decode($v, true)) : null;
        } catch (Throwable $e) {
            $b = null;
        }
        return $cache[$k] = $b;
    }

    /** "Tue 25 Nov 2026, 22:00" in this site's time zone. */
    function rh_maint_when(int $ts): string
    {
        return date('D j M Y, H:i', $ts);
    }

    /** "Tue 25 Nov 2026, 22:00–23:30" or "Tue 25 Nov 2026, 22:00 to Wed 26 Nov 2026, 02:00". */
    function rh_maint_window_words(array $b): string
    {
        $from = rh_maint_when($b['starts_at']);
        return date('Y-m-d', $b['starts_at']) === date('Y-m-d', $b['ends_at'])
            ? $from . '–' . date('H:i', $b['ends_at'])
            : $from . ' to ' . rh_maint_when($b['ends_at']);
    }

    /** Which part goes offline, in plain words. */
    function rh_maint_scope_words(string $scope): string
    {
        return ['admin' => 'the staff system (admin, front desk, POS and kitchen screens)', 'site' => 'the guest website',
            'both' => 'the staff system and the guest website'][$scope] ?? 'the system';
    }

    /** The staff notice for this window now, or '' when none is due. */
    function rh_maint_banner_text(?array $block, int $now): string
    {
        $b = rh_maint_clean($block);
        if ($b === null) {
            return '';
        }
        $admin = rh_maint_decide($now, $b, 'admin');
        $site = rh_maint_decide($now, $b, 'site');
        $msg = $b['message'] !== '' ? ' ' . $b['message'] : '';
        if ($admin === 'announce' || $site === 'announce') {
            return 'Scheduled maintenance: ' . rh_maint_window_words($b) . '. During this time ' . rh_maint_scope_words($b['scope'])
                . ' will be offline.' . $msg;
        }
        if ($admin === 'offline') {
            return 'The system is offline for maintenance until ' . rh_maint_when($b['ends_at']) . '.';
        }
        if ($site === 'offline') {
            return 'The guest website is offline for maintenance until ' . rh_maint_when($b['ends_at']) . '.' . $msg;
        }
        return '';
    }

    /** The window that takes the guest website offline now, or null. For config/database.php; reads the global $pdo. */
    function rh_maint_site_offline_block(): ?array
    {
        $b = rh_maint_read($GLOBALS['pdo'] ?? null);
        return rh_maint_decide(time(), $b, 'site') === 'offline' ? $b : null;
    }

    /** The guest website's words for a window: its message and when it is back. */
    function rh_maint_site_message(array $b): string
    {
        $msg = $b['message'] !== '' ? $b['message'] : 'Our website is temporarily unavailable while we complete scheduled maintenance.';
        return $msg . "\n\nWe expect to be back by " . rh_maint_when($b['ends_at']) . '.';
    }

    /**
     * Staff requests during an admin/both window: a 503 page for a person opening a page, a 503 JSON refusal for the
     * pages' own calls, admin APIs and station screens. Returns when the staff system is not offline (or for sign-out).
     * $area / $script come from rh_licence_request_target().
     */
    function rh_maint_admin_enforce(string $area, string $script): void
    {
        if (PHP_SAPI === 'cli' || !in_array($area, ['admin', 'admin_api', 'station'], true) || $script === 'logout.php') {
            return;
        }
        $now = time();
        $b = rh_maint_read($GLOBALS['pdo'] ?? null);
        if ($b === null || rh_maint_decide($now, $b, 'admin') !== 'offline') {
            return;
        }
        rh_maint_admin_respond($b, $now, $area === 'admin' && (!function_exists('rh_licence_wants_html') || rh_licence_wants_html()));
    }

    /** The 503 itself (a page when $html, else JSON). Never returns. Split out so the tests can see what is sent. */
    function rh_maint_admin_respond(array $b, int $now, bool $html): void
    {
        $msg = $b['message'] !== '' ? $b['message'] : 'The system is offline for scheduled maintenance.';
        $back = rh_maint_when($b['ends_at']);
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: ' . max(60, $b['ends_at'] - $now));
            header('Cache-Control: no-store');
            header('X-RH-Maintenance: offline');
            header('Content-Type: ' . ($html ? 'text/html' : 'application/json') . '; charset=utf-8');
        }
        if (!$html) {
            $text = 'The system is offline for scheduled maintenance until ' . $back . '. ' . $msg;
            echo json_encode(['success' => false, 'ok' => false, 'error' => $text, 'message' => $text, 'maintenance' => true, 'back_at' => $b['ends_at']]);
            exit;
        }
        $h = static function ($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $site = function_exists('getSetting') ? (string)getSetting('site_name') : '';
        $title = $site !== '' ? $site : 'The system';
        // Reloads every 2 minutes so a staff screen comes back by itself when the window ends or is ended early.
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<meta http-equiv="refresh" content="' . (int)min(120, max(30, $b['ends_at'] - $now + 5)) . '">'
            . '<title>' . $h($title) . ': scheduled maintenance</title></head>'
            . '<body style="margin:0;font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f6f2ea;color:#3e3930;">'
            . '<main style="max-width:520px;margin:10vh auto;padding:0 16px;">'
            . '<div style="background:#fff;border-radius:14px;padding:30px 26px;border:1px solid #e5ddd4;text-align:center;">'
            . '<h1 style="font-size:1.4rem;margin:0 0 12px;color:#2b2722;">Scheduled maintenance</h1>'
            . '<p style="line-height:1.6;margin:0 0 12px;">' . nl2br($h($msg)) . '</p>'
            . '<p style="line-height:1.6;margin:0 0 20px;"><strong>Back online at ' . $h($back) . '.</strong> Your data is safe.</p>'
            . '<p style="margin:0;"><a href="logout.php" style="min-height:44px;display:inline-flex;align-items:center;padding:0 18px;border-radius:8px;text-decoration:none;border:1px solid #cfc6b8;color:#3e3930;">Sign out</a></p>'
            . '</div></main></body></html>';
        exit;
    }
}
