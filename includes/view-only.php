<?php

/**
 * View only (owner 2026-11-10). A person who is "view only" can open and read every page they are allowed to see, but
 * cannot add, change or delete anything. Buttons that would change data are shown greyed out (rh_view_only_render), and
 * the server refuses every change anyway (rh_view_only_guard, called from rh_licence_guard_request): the greying is only
 * a courtesy, the refusal is the protection.
 *
 * Who is view only:
 *   - EVERY staff member, administrators included, while the business is on a free trial that its provider made view only
 *     (verified licence answer: trial === true and trial_view_only === true; set per business on the licence panel);
 *   - anyone with the "view_only" permission ticked for them (Users > Permissions, or from the licence panel). No role has
 *     it by default.
 *
 * What still works: reading (pages, and requests whose action only reads), signing out, changing one's own password, the
 * Licence page, search and look-ups, and harmless bookkeeping (online presence, menu favourites, sidebar state).
 * Everything else that is not a plain read is refused, including check-out, payments and backups (stricter than the
 * licence's read-only step).
 *
 * Exports (owner 2026-11-10): during a view-only FREE TRIAL the data exports and downloads are refused too: the CSV
 * exports (rh_licence_export_rules, plus any ?export= / ?download= on a staff page: system logs, backups, quotations),
 * reports-export and the end-of-day PDF (rh_view_only_is_export). Opening one invoice or receipt (document-download.php)
 * stays open: that is looking at a document. A person who is view only by permission may still export.
 *
 * Fails open: any fault here lets the request through (it must never lock a hotel by accident).
 */

require_once __DIR__ . '/licence.php';

if (!function_exists('rh_view_only_guard')) {

    /** The allowlist while view only: same shape as rh_licence_rules(), with no write actions at all. */
    function rh_view_only_rules(): array
    {
        return [
            'admin' => ['any' => ['change-password.php', 'licence.php'], 'actions' => []],
            'admin_api' => [
                'any' => ['presence.php', 'nav-favorites.php', 'nav-sidebar-state.php', 'global-search.php', 'search-bookings.php',
                    'guest-lookup.php', 'check-unique.php', 'user-check-unique.php', 'system-health.php',
                    'get-housekeeping-audit.php', 'get-maintenance-audit.php', 'end-of-day-pdf.php'],
                'actions' => [],
            ],
            'station' => ['any' => ['reports-export.php', 'pos-tab-detail.php', 'pos-notifications.php'], 'actions' => []],
        ];
    }

    /** Is this request a data export or download (refused during a view-only free trial)? Pure. */
    function rh_view_only_is_export(string $area, string $script, array $get): bool
    {
        if ($area === 'admin_api') {
            return $script === 'end-of-day-pdf.php';
        }
        if ($area === 'station') {
            return $script === 'reports-export.php';
        }
        if ($area !== 'admin') {
            return false;
        }
        if (rh_licence_is_export_get($script, $get)) {
            return true;
        }
        foreach (['export', 'download'] as $k) {
            if (isset($get[$k]) && $get[$k] !== '') {
                return true; // system-logs ?export=csv, backup-management ?download=, and any later export on a staff page
            }
        }
        return false;
    }

    /**
     * May this request pass for a view-only person? Pure. $trial: everyone is view only because of a view-only free
     * trial (then exports are refused as well).
     */
    function rh_view_only_allowed(string $area, string $script, string $method, array $get, array $post, bool $trial = false): bool
    {
        if ($area === 'other') {
            return true;
        }
        if ($trial && rh_view_only_is_export($area, $script, $get)) {
            return false;
        }
        return rh_licence_write_allowed($area, $script, $method, $get, $post, rh_view_only_rules());
    }

    /** Pure: does a verified licence status make everyone view only (a view-only free trial)? */
    function rh_view_only_trial_on(array $st): bool
    {
        $p = $st['payload'] ?? null;
        return ($st['reason'] ?? '') === 'ok' && is_array($p) && ($p['trial_view_only'] ?? null) === true && rh_licence_trial($p)['trial'];
    }

    /** Why the signed-in person is view only: 'trial', 'user', or '' (not view only). Cached for the request. Fails open. */
    function rh_view_only_reason(): string
    {
        static $why = null;
        if ($why !== null) {
            return $why;
        }
        $why = '';
        try {
            if (PHP_SAPI === 'cli' || !isset($_SESSION) || !is_array($_SESSION) || empty($_SESSION['admin_user_id'])) {
                return $why;
            }
            if (rh_licence_enabled() && rh_view_only_trial_on(rh_licence_status())) {
                return $why = 'trial';
            }
            $pdo = $GLOBALS['pdo'] ?? null;
            if ($pdo instanceof PDO) {
                $st = $pdo->prepare("SELECT is_granted FROM user_permissions WHERE user_id = ? AND permission_key = 'view_only'");
                $st->execute([(int)$_SESSION['admin_user_id']]);
                if ((int)$st->fetchColumn() === 1) {
                    $why = 'user';
                }
            }
        } catch (Throwable $e) {
            $why = '';
        }
        return $why;
    }

    function rh_view_only_message(string $why): string
    {
        if ($why === 'trial') {
            $c = '';
            try {
                $c = trim((string)(rh_licence_status()['contact'] ?? ''));
            } catch (Throwable $e) {
                $c = '';
            }
            return 'Not saved: this system is view only during the free trial. You can look at everything, but nothing can be changed. '
                . 'Contact ' . ($c !== '' ? $c : 'your system provider') . ' to start using it fully.';
        }
        return 'Not saved: your account is view only. You can look at everything, but you cannot change anything. Ask your administrator if you need to.';
    }

    /** Refuse a change for a view-only person. Never returns. */
    function rh_view_only_refuse(string $why, string $area): void
    {
        $msg = rh_view_only_message($why);
        if ($area !== 'admin' || !rh_licence_wants_html()) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                header('X-RH-View-Only: 1');
            }
            echo json_encode(['success' => false, 'ok' => false, 'error' => $msg, 'message' => $msg, 'view_only' => true]);
            exit;
        }
        // A form posted from the browser: say so on the page it came from (nothing saved).
        $_SESSION['error_message'] = $msg;
        $back = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref !== '') {
            $p = parse_url($ref);
            $host = (string)($p['host'] ?? '');
            $mine = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
            $refPath = (string)($p['path'] ?? '');
            $sameDir = dirname($refPath) === dirname(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
            if ($host !== '' && strtolower($host) === $mine && $sameDir && substr($refPath, -4) === '.php') {
                $back = './' . basename($refPath) . (isset($p['query']) ? '?' . $p['query'] : '');
            }
        }
        if (!headers_sent()) {
            header('X-RH-View-Only: 1');
            header('Location: ' . $back, true, 303);
        }
        exit;
    }

    /** Called first thing for every staff request (rh_licence_guard_request), whether or not licensing is on. */
    function rh_view_only_guard(): void
    {
        try {
            $why = rh_view_only_reason();
            if ($why === '') {
                return;
            }
            [$area, $script] = rh_licence_request_target();
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if (rh_view_only_allowed($area, $script, $method, $_GET, $_POST, $why === 'trial')) {
                return;
            }
        } catch (Throwable $e) {
            error_log('view only guard: ' . $e->getMessage());
            return;
        }
        rh_view_only_refuse($why, $area);
    }

    /**
     * The banner and the greying, printed inside #rh-admin-page by admin/includes/admin-header.php. Prints nothing unless
     * the signed-in person is view only. The script greys out (and stops clicks on):
     *   - the submit buttons of forms that post;
     *   - buttons, and links styled as buttons (any class with btn / button / action / cta / chip), whose words, title or
     *     icon say they change something (Add, New, Save, Delete, Record, Check in..., a trash / plus / pen icon);
     *   - links to the pages that create or edit (create-booking.php, edit-booking.php, any create- / add- / new- / edit- page);
     *   - during a view-only free trial, exports and downloads too (Export, Download, CSV, ?export=, reports-export...).
     * Only the site's own header and menu, and close buttons, are skipped: page headers often hold the action buttons.
     * Menus, search, filters and print stay usable. It keeps up with content loaded later and stops when a page without
     * this banner is shown (view only switched off).
     */
    function rh_view_only_render(): void
    {
        $why = rh_view_only_reason();
        if ($why === '') {
            return;
        }
        $text = $why === 'trial'
            ? 'View only: this system is in view-only mode for the free trial. You can open and look at everything; buttons that would change something are greyed out.'
            : 'View only: your account can open and look at everything; buttons that would change something are greyed out.';
        $msg = rh_view_only_message($why);
        echo '<div class="content" style="padding-bottom:0"><div class="alert alert-info" role="status" id="rhViewOnlyBanner" data-rh-view-only="' . ($why === 'trial' ? 'trial' : 'user') . '">'
            . '<i class="fas fa-eye" aria-hidden="true"></i><span>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span></div></div>';
        ?>
<style>.rh-vo-off{opacity:.45!important;cursor:not-allowed!important;filter:grayscale(1);}#rhVoToast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:100000;max-width:92vw;padding:10px 16px;border-radius:8px;background:#1c2430;color:#fff;font-size:14px;box-shadow:0 6px 20px rgba(0,0,0,.25)}</style>
<script>
(function () {
    if (window.__rhViewOnly) { window.__rhViewOnly.scan(); return; }
    var MSG = <?php echo json_encode($msg); ?>;
    var VERB = /^(\+\s*)?(add|new|create|save|update|edit|delete|remove|record|post|submit|confirm|approve|reject|check[\s-]?in|check[\s-]?out|cancel booking|void|refund|send|email|import|upload|assign|move|transfer|close shift|open shift|bump|restore|issue|pay|settle|book|block|unblock|duplicate|archive|accept|decline|merge|split|adjust|receive|write off)\b/i;
    var EXPORT = /^(export|download|csv|excel)\b/i;
    var WRITE_HREF = /(^|[\/=])(create|add|new|edit)[-_][a-z0-9_-]*\.php/i;
    var EXPORT_HREF = /[?&](export|download)=|[?&]format=csv|reports-export\.php|end-of-day-pdf\.php/i;
    var BTN_CLASS = /(^|[\s_-])(btn|button|action|actions|cta|chip)([\s_-]|$)/i;
    var WRITE_ICON = '.fa-trash, .fa-trash-alt, .fa-trash-can, .fa-plus, .fa-plus-circle, .fa-circle-plus, .fa-pen, .fa-pencil, .fa-pencil-alt, .fa-edit, .fa-pen-to-square, .fa-save, .fa-floppy-disk';
    var EXPORT_ICON = '.fa-download, .fa-file-csv, .fa-file-excel, .fa-file-export';
    var SKIP = '.admin-header, .admin-nav, .sidebar, #rh-sidebar, .admin-sidebar, .modal-header, [data-dismiss], [data-bs-dismiss], .close, .btn-close, [data-vo-allow], [data-filter], #rhViewOnlyBanner';
    var READ_ACTION = /^(get|find|search|lookup|list|fetch|poll|preview|view|load)(_|$)/;
    function banner() { return document.getElementById('rhViewOnlyBanner'); }
    function active() { return !!banner(); }
    function trial() { var b = banner(); return !!b && b.getAttribute('data-rh-view-only') === 'trial'; }
    function label(el) {
        var t = (el.textContent || el.value || '').replace(/\s+/g, ' ').trim();
        if (t === '') { t = el.getAttribute('aria-label') || el.getAttribute('title') || (el.getAttribute('data-help') || '').split('|')[0] || ''; }
        return t.replace(/\s+/g, ' ').trim();
    }
    function looksLikeButton(el) {
        if (el.tagName !== 'A') { return true; }
        var h = el.getAttribute('href') || '';
        return el.getAttribute('role') === 'button' || BTN_CLASS.test(el.className || '') || h === '' || h.charAt(0) === '#' || /^javascript:/i.test(h);
    }
    function changes(el, isTrial) {
        if (el.tagName === 'A') {
            var h = el.getAttribute('href') || '';
            if (WRITE_HREF.test(h) || (isTrial && EXPORT_HREF.test(h))) { return true; }
        }
        if (!looksLikeButton(el)) { return false; }
        var t = label(el);
        var f = el.form;
        if (f && (el.type === 'submit' || el.type === 'image') && (f.getAttribute('method') || 'get').toLowerCase() !== 'post') {
            // a filter / search form (GET) only reads; its "Update" or "Apply" stays usable. Its export does not, in a trial.
            return isTrial && (EXPORT.test(t) || /^(export|download|format)$/i.test(el.name || ''));
        }
        if (VERB.test(t) || (isTrial && EXPORT.test(t))) { return true; }
        if ((el.textContent || '').trim() === '') {
            if (el.querySelector(WRITE_ICON) || (isTrial && el.querySelector(EXPORT_ICON))) { return true; }
        }
        return false;
    }
    function writeForm(f) {
        if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') { return false; }
        if (/(logout|change-password|licence)\.php/i.test(f.getAttribute('action') || '')) { return false; }
        var a = f.querySelector('[name=action]');
        return !(a && READ_ACTION.test(a.value || ''));
    }
    function off(el) {
        if (el.classList.contains('rh-vo-off')) { return; }
        el.classList.add('rh-vo-off');
        el.setAttribute('aria-disabled', 'true');
        el.setAttribute('data-rh-vo', el.disabled ? 'was' : '1');
        if (!el.title) { el.title = 'View only'; }
        if (el.tagName !== 'A' && 'disabled' in el) { el.disabled = true; }
    }
    function release() {
        document.querySelectorAll('.rh-vo-off').forEach(function (el) {
            el.classList.remove('rh-vo-off');
            el.removeAttribute('aria-disabled');
            if (el.getAttribute('data-rh-vo') === '1' && 'disabled' in el) { el.disabled = false; }
            if (el.title === 'View only') { el.removeAttribute('title'); }
            el.removeAttribute('data-rh-vo');
        });
    }
    function scan() {
        if (!active()) { release(); return; }
        document.querySelectorAll('form').forEach(function (f) {
            if (writeForm(f)) { f.querySelectorAll('button:not([type=button]):not([type=reset]), input[type=submit], input[type=image]').forEach(off); }
        });
        var isTrial = trial();
        document.querySelectorAll('button, a, input[type=button], input[type=submit], [role=button]').forEach(function (el) {
            if (el.closest(SKIP)) { return; }
            if (changes(el, isTrial)) { off(el); }
        });
    }
    function toast() {
        var t = document.getElementById('rhVoToast');
        if (!t) { t = document.createElement('div'); t.id = 'rhVoToast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
        t.textContent = MSG;
        clearTimeout(t._h);
        t._h = setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 4000);
    }
    document.addEventListener('click', function (e) {
        if (!active()) { return; }
        var el = e.target && e.target.closest ? e.target.closest('.rh-vo-off') : null;
        if (el) { e.preventDefault(); e.stopImmediatePropagation(); toast(); }
    }, true);
    document.addEventListener('submit', function (e) {
        if (!active()) { return; }
        var f = e.target;
        if (f && f.tagName === 'FORM' && writeForm(f)) { e.preventDefault(); e.stopImmediatePropagation(); toast(); }
    }, true);
    var queued = false;
    new MutationObserver(function () {
        if (queued) { return; }
        queued = true;
        setTimeout(function () { queued = false; scan(); }, 150);
    }).observe(document.documentElement, {childList: true, subtree: true});
    window.__rhViewOnly = {scan: scan};
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', scan); } else { scan(); }
})();
</script>
        <?php
    }
}
