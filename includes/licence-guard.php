<?php

/**
 * Licence enforcement for STAFF requests (the guest website is never gated, CLI scripts are never gated).
 *
 * ONE central guard, rh_licence_guard_request(), called right after the database is loaded by every entry point that
 * serves a signed-in staff member: admin/admin-init.php, admin/api/api-init.php, the admin/api files that start their
 * own session, and the api/ station endpoints (void/cancel/delete order, KDS, POS notifications, tab detail, exports).
 * scripts/tests/licence_client.php fails if a file that starts an admin session does not call it.
 *
 * Statuses (includes/licence.php):
 *   unconfigured / active : nothing happens.
 *   grace                 : a calm banner on admin pages (rh_licence_render_banner), nothing blocked.
 *   read_only             : GET pages work; a request that changes data is refused unless it is on the allowlist below.
 *   suspended             : admin pages show a locked page; admin/api and station APIs answer 423; sign-in/out, the
 *                           Licence page, document downloads, CSV exports and backups stay open.
 *
 * The decision is a pure function (rh_licence_decide) so it can be tested without a web server.
 *
 * READ-ONLY ALLOWLIST (a change request passes only if it is one of these):
 *   - sign-in / sign-out / password reset (they never reach the guard) and "change my password"
 *   - checking a guest OUT (Bookings: checkout, checkout_settle, update_status to checked-out; Booking details: checkout)
 *   - closing an open till shift (pos.php / pos-accounting.php close_shift): cashing up money already taken
 *   - recording / settling money on EXISTING records: payment-add.php, receipts (ajax-receipt.php, receipts.php generate/email),
 *     Bookings update_payment + consolidation_record, till pay_existing, Orders & Tabs settle_order, table settle_table_order,
 *     Booking details "update payment", record_payment on conference / event / gym accounts
 *   - exports and downloads (the real CSV exports listed in rh_licence_export_rules, document-download.php, reports-export, end-of-day PDF)
 *   - a database backup (backup-management.php run_backup); restore and delete-backups are NOT allowed
 *   - harmless bookkeeping that is sent as POST: presence heartbeat, menu favourites / sidebar state, notification acks, searches
 *   - every read that the pages send as POST (an action named get_*, find_*, search*, lookup*, list_*, fetch*, poll, preview, view_*, load* or
 *     one of the listed read names)
 * Everything else that is not a plain GET is refused. New sales, new bookings, edits, kitchen bumps, refunds, opening floats, settings,
 * imports and the Assistant's confirmed actions are therefore refused in read_only.
 */

require_once __DIR__ . '/licence.php';

if (!function_exists('rh_licence_guard_request')) {

    /** @return array{0:string,1:string} [area, script basename]; area is admin | admin_api | station | other */
    function rh_licence_request_target(?string $scriptName = null): array
    {
        $p = str_replace('\\', '/', (string)($scriptName ?? ($_SERVER['SCRIPT_NAME'] ?? '')));
        if (preg_match('#/admin/api/([^/]+)$#', $p, $m)) {
            return ['admin_api', strtolower($m[1])];
        }
        if (preg_match('#/admin/([^/]+)$#', $p, $m)) {
            return ['admin', strtolower($m[1])];
        }
        if (preg_match('#/api/([^/]+)$#', $p, $m)) {
            return ['station', strtolower($m[1])];
        }
        return ['other', strtolower(basename($p))];
    }

    /** Parameters that name what a request does. All present ones are checked, so a second one cannot smuggle a write. */
    function rh_licence_action_keys(): array
    {
        return ['action', 'booking_action', 'charge_action', 'invoice_action', 'ajax'];
    }

    /** True for an action that only reads (pages send many reads as POST). */
    function rh_licence_is_read_action(string $a): bool
    {
        if (preg_match('/^(get|find|search|lookup|list|fetch|poll|preview|view|load)(_|$)/', $a)) {
            return true;
        }
        static $names = [
            'feed', 'recipe_card', 'booking_group_info', 'room_change_options', 'checkout_assess', 'consolidation_fetch',
            'prefs_get', 'status', 'briefing', 'suggestions', 'alerts', 'check_ready', 'order_items', 'shift_stats', 'tabs',
            'tab_deals', 'orders_today', 'stations', 'resto_orders', 'table_status', 'card_insight', 'guest_credit_lookup',
            'manager_auth', 'ack', 'ack_message', 'ack_pos_message', 'ack_pos_messages', 'mark_notes_seen', 'summary',
        ];
        return in_array($a, $names, true);
    }

    /** The allowlist: per area, scripts open for any request ('any') and per-script action rules ('actions'). */
    function rh_licence_rules(): array
    {
        return [
            'admin' => [
                'any' => ['change-password.php', 'licence.php', 'payment-add.php', 'ajax-receipt.php', 'document-download.php'],
                'actions' => [
                    'bookings.php' => [
                        ['action' => ['checkout', 'checkout_settle', 'consolidation_record', 'update_payment']],
                        ['action' => ['update_status'], 'status' => ['checked-out']],
                    ],
                    'booking-details.php' => [['booking_action' => ['checkout']], ['present' => 'update_payment']],
                    'pos.php' => [['action' => ['pay_existing', 'close_shift']]],
                    // cashing up an open shift (Z-report) is settling money already taken, not a new sale
                    'pos-accounting.php' => [['action' => ['close_shift']]],
                    'stock-orders.php' => [['action' => ['settle_order']]],
                    'restaurant-tables.php' => [['action' => ['settle_table_order']]],
                    'receipts.php' => [['action' => ['generate_receipt', 'email_receipt']]],
                    // money on existing conference / event / gym accounts (the shared account-record-payment include)
                    'conference-management.php' => [['action' => ['record_payment']]],
                    'events-inquiries.php' => [['action' => ['record_payment']]],
                    'gym-inquiries.php' => [['action' => ['record_payment']]],
                    'backup-management.php' => [['action' => ['run_backup']]],
                ],
            ],
            'admin_api' => [
                'any' => ['presence.php', 'nav-favorites.php', 'nav-sidebar-state.php', 'global-search.php', 'search-bookings.php',
                    'guest-lookup.php', 'check-unique.php', 'user-check-unique.php', 'system-health.php',
                    'get-housekeeping-audit.php', 'get-maintenance-audit.php', 'end-of-day-pdf.php'],
                'actions' => [],
            ],
            'station' => [
                'any' => ['reports-export.php', 'pos-tab-detail.php', 'pos-notifications.php'],
                'actions' => [],
            ],
        ];
    }

    /** Does one allowlist rule match this request? Keys: a param => allowed values, or 'present' => a POST field that must exist. */
    function rh_licence_rule_matches(array $rule, array $get, array $post): bool
    {
        foreach ($rule as $key => $want) {
            if ($key === 'present') {
                if (!isset($post[(string)$want])) {
                    return false;
                }
                continue;
            }
            $have = $post[$key] ?? ($get[$key] ?? null);
            if (!is_string($have) || !in_array($have, (array)$want, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The real CSV exports / downloads, per page (script => parameter => the one accepted value, or true for "any id").
     * Only these pass while the system is locked: a page that does not handle the parameter would just render, so a generic
     * "?export=anything" rule would be a way round the lock.
     */
    function rh_licence_export_rules(): array
    {
        return [
            'bookings.php' => ['export' => 'csv'], 'payments.php' => ['export' => 'csv'], 'receipts.php' => ['export' => 'csv'],
            'end-of-day-report.php' => ['export' => 'csv'], 'stock-reports.php' => ['export' => 'csv'],
            'stock-ingredients.php' => ['export' => 'csv'], 'kds-report.php' => ['format' => 'csv'],
            'quotations.php' => ['download' => true],
        ];
    }

    /** Is this GET one of the real exports of this page? */
    function rh_licence_is_export_get(string $script, array $get): bool
    {
        $rule = rh_licence_export_rules()[$script] ?? null;
        if ($rule === null) {
            return false;
        }
        foreach ($rule as $param => $want) {
            $have = $get[$param] ?? null;
            if (!is_string($have) || $have === '' || ($want !== true && $have !== $want)) {
                return false;
            }
        }
        return true;
    }

    /**
     * May this request pass while the status is read_only? Pure.
     * $get / $post are the request arrays ($_GET / $_POST).
     */
    function rh_licence_write_allowed(string $area, string $script, string $method, array $get, array $post): bool
    {
        $method = strtoupper($method);
        $rules = rh_licence_rules()[$area] ?? null;
        if ($rules === null) {
            return true;
        }
        if (in_array($script, $rules['any'], true)) {
            return true;
        }
        $isRead = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        if ($isRead) {
            if ($area === 'admin') {
                // Pages are viewed freely. The one known change sent as GET: the till's "86 / item on-off" toggle.
                return !($script === 'pos.php' && ($get['ajax'] ?? '') === 'toggle_item');
            }
            // JSON endpoints: a GET with a changing action (kds-action ?action=bump_ticket) is a change.
        }

        $present = [];
        foreach (rh_licence_action_keys() as $k) {
            foreach ([$post, $get] as $src) {
                if (isset($src[$k]) && $src[$k] !== '') {
                    $present[] = [$k, $src[$k]];
                }
            }
        }
        $actionRules = $rules['actions'][$script] ?? [];
        foreach ($present as [$k, $v]) {
            if (!is_string($v)) {
                return false;
            }
            if (rh_licence_is_read_action($v)) {
                continue;
            }
            $ok = false;
            foreach ($actionRules as $rule) {
                if (isset($rule[$k]) && in_array($v, (array)$rule[$k], true) && rh_licence_rule_matches($rule, $get, $post)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        if ($present) {
            return true;
        }
        if ($isRead) {
            return true; // a plain GET with no action
        }
        foreach ($actionRules as $rule) {
            if (isset($rule['present']) && rh_licence_rule_matches($rule, $get, $post)) {
                return true; // e.g. Booking details "update payment" is a bare field, not an action
            }
        }
        return false;
    }

    /** May this request pass while the status is suspended? Pure. Sign-in/out never reach the guard. */
    function rh_licence_suspended_allowed(string $area, string $script, string $method, array $get, array $post): bool
    {
        $method = strtoupper($method);
        $isRead = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        if ($area === 'admin') {
            if (in_array($script, ['licence.php', 'document-download.php'], true)) {
                return true;
            }
            if ($script === 'backup-management.php') {
                return rh_licence_write_allowed($area, $script, $method, $get, $post); // view, download, run a backup; no restore or delete
            }
            return $isRead && rh_licence_is_export_get($script, $get);
        }
        return in_array($script, ['end-of-day-pdf.php', 'reports-export.php'], true) && $area !== 'other';
    }

    /**
     * The decision. Pure.
     *
     * @return array{allow:bool,status:string}
     */
    function rh_licence_decide(string $status, string $area, string $script, string $method, array $get = [], array $post = []): array
    {
        if ($area === 'other' || in_array($status, ['unconfigured', 'active', 'grace'], true)) {
            return ['allow' => true, 'status' => $status];
        }
        $allow = $status === 'read_only'
            ? rh_licence_write_allowed($area, $script, $method, $get, $post)
            : rh_licence_suspended_allowed($area, $script, $method, $get, $post);
        return ['allow' => $allow, 'status' => $status];
    }

    // ------------------------------------------------------------------ words

    function rh_licence_contact_words(array $st): string
    {
        $c = trim((string)($st['contact'] ?? ''));
        return $c !== '' ? 'Please contact ' . $c . '.' : 'Please contact your system provider.';
    }

    function rh_licence_date_words(string $ymd): string
    {
        $t = strtotime($ymd . ' 12:00:00');
        return $t ? date('j F Y', $t) : $ymd;
    }

    /** Whole days from today (hotel time) to a Y-m-d date; never negative. */
    function rh_licence_days_until(string $ymd): int
    {
        try {
            $a = new DateTimeImmutable(date('Y-m-d'));
            $b = new DateTimeImmutable($ymd);
            return max(0, (int)$a->diff($b)->format('%r%a'));
        } catch (Throwable $e) {
            return 0;
        }
    }

    function rh_licence_refusal_message(array $st): string
    {
        $who = rh_licence_contact_words($st);
        if (($st['status'] ?? '') === 'suspended') {
            return 'Your system is paused because the subscription payment is overdue. Your data is safe. ' . rtrim($who, '.') . ' to switch it back on.';
        }
        return 'Not saved: the system is in view-only mode because the subscription payment is overdue. You can still look things up, check guests out and take payments. ' . $who;
    }

    /** Plain banner text for grace / read_only, or '' when none is needed. */
    function rh_licence_banner_text(array $st): string
    {
        $status = (string)($st['status'] ?? '');
        $who = rh_licence_contact_words($st);
        $d = (array)($st['dates'] ?? []);
        $dated = ($st['source'] ?? '') === 'server' || ($st['source'] ?? '') === 'schedule';
        if ($status === 'grace') {
            if (($st['reason'] ?? 'ok') !== 'ok') {
                return (string)($st['message'] ?? '');
            }
            if ($dated && !empty($d['read_only_from'])) {
                $n = rh_licence_days_until($d['read_only_from']);
                return 'Payment overdue — the system will become read-only on ' . rh_licence_date_words($d['read_only_from'])
                    . ' (' . $n . ' day' . ($n === 1 ? '' : 's') . ' left). ' . $who;
            }
            return 'Payment overdue — the system will become read-only soon. ' . $who;
        }
        if ($status === 'read_only') {
            $tail = ($dated && !empty($d['suspended_from'])) ? ' It will lock completely on ' . rh_licence_date_words($d['suspended_from']) . '.' : '';
            return 'Payment overdue — the system is read-only. You can view records, check guests out, record payments and export data.' . $tail . ' ' . $who;
        }
        return '';
    }

    /** The banner, printed inside #rh-admin-page by admin/includes/admin-header.php. Prints nothing unless needed. */
    function rh_licence_render_banner(): void
    {
        if (!rh_licence_enabled()) {
            return;
        }
        $st = rh_licence_status();
        $text = rh_licence_banner_text($st);
        if ($text === '') {
            return;
        }
        $cls = $st['status'] === 'read_only' ? 'alert-error' : 'alert-warning';
        echo '<div class="content" style="padding-bottom:0"><div class="alert ' . $cls . '" role="status" id="rhLicenceBanner">'
            . '<i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . ' <a href="licence.php">Licence details</a></span></div></div>';
    }

    // ------------------------------------------------------------------ refusing

    /**
     * A browser page load or form post (answer with a page / redirect) rather than a script's own fetch (answer with JSON).
     * A real navigation always wins. A POST carrying client_queued_at is the offline queue replaying a saved sale (that
     * stamp is added only when the sale was queued offline; client_uuid is NOT a marker, the till adds it to every form):
     * it needs a status code, never a redirect, or the queue would take the redirect as success and drop the sale.
     */
    function rh_licence_wants_html(): bool
    {
        $mode = (string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
        if ($mode === 'navigate') {
            return true;
        }
        if (isset($_POST['client_queued_at'])) {
            return false;
        }
        if (($_SERVER['HTTP_X_RH_ACTIVITY'] ?? '') === '1') {
            return true; // the admin single-page shell: page loads and form posts
        }
        if ($mode !== '') {
            return false;
        }
        if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return false;
        }
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        return !(stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false);
    }
    function rh_licence_send_json_refusal(array $st): void
    {
        $locked = ($st['status'] ?? '') === 'suspended';
        $msg = rh_licence_refusal_message($st);
        if (!headers_sent()) {
            http_response_code($locked ? 423 : 403);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-RH-Licence: ' . ($locked ? 'suspended' : 'read_only'));
        }
        echo json_encode(['success' => false, 'ok' => false, 'error' => $msg, 'message' => $msg, 'licence' => $st['status'] ?? '']);
        exit;
    }

    /** The locked page (suspended). Self-contained: it must work with no menu, no session data beyond the role. */
    function rh_licence_render_locked_page(array $st): void
    {
        if (!headers_sent()) {
            http_response_code(423);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-RH-Licence: suspended');
        }
        $h = static function ($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $d = (array)($st['dates'] ?? []);
        $isAdmin = ($_SESSION['admin_role'] ?? '') === 'admin';
        $paid = !empty($d['paid_until']) ? rh_licence_date_words($d['paid_until']) : '';
        $site = function_exists('getSetting') ? (string)getSetting('site_name') : '';
        $title = $site !== '' ? $site : 'Your system';
        $btn = 'min-height:44px;display:inline-flex;align-items:center;padding:0 18px;border-radius:8px;text-decoration:none;font-weight:600;';
        $prefix = ['admin' => '', 'admin_api' => '../'][rh_licence_request_target()[0]] ?? '../admin/'; // links work from admin/, admin/api/ and api/
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . $h($title) . ' is paused</title></head>'
            . '<body style="margin:0;font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f6f2ea;color:#3e3930;">'
            . '<main style="max-width:520px;margin:10vh auto;padding:0 16px;">'
            . '<div style="background:#fff;border-radius:14px;padding:30px 26px;box-shadow:0 8px 28px rgba(0,0,0,.10);text-align:center;">'
            . '<h1 style="font-size:1.4rem;margin:0 0 12px;color:#2b2722;">' . $h($title) . ' is paused</h1>'
            . '<p style="line-height:1.6;margin:0 0 12px;">The subscription payment is overdue, so the staff system is paused for now.</p>'
            . '<p style="line-height:1.6;margin:0 0 12px;"><strong>Your data is safe</strong> and your website is still open for guests.</p>'
            . '<p style="line-height:1.6;margin:0 0 20px;">' . $h(rtrim(rh_licence_contact_words($st), '.')) . ' to switch it back on. It comes back on by itself once the payment is recorded.</p>'
            . '<p style="margin:0;display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">';
        if ($isAdmin) {
            echo '<a href="' . $prefix . 'licence.php" style="' . $btn . 'background:#7E684B;color:#fff;">Licence details</a>'
                . '<a href="' . $prefix . 'backup-management.php" style="' . $btn . 'border:1px solid #7E684B;color:#7E684B;">Download a backup</a>';
        }
        echo '<a href="' . $prefix . 'logout.php" style="' . $btn . 'border:1px solid #cfc6b8;color:#3e3930;font-weight:400;">Sign out</a>'
            . '</p></div></main></body></html>';
        exit;
    }

    /** Refuse a request. Never returns. */
    function rh_licence_refuse(array $st, string $area, string $method): void
    {
        $locked = ($st['status'] ?? '') === 'suspended';
        $navigate = ((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '')) === 'navigate';
        if ($locked && $navigate) {
            rh_licence_render_locked_page($st); // a person opened it in the browser: a friendly page, never raw JSON
        }
        if ($area !== 'admin' || !rh_licence_wants_html()) {
            rh_licence_send_json_refusal($st);
        }
        if ($locked) {
            rh_licence_render_locked_page($st);
        }
        // read_only, a staff form post from the browser: say so on the page they came from and go back (nothing saved).
        $_SESSION['error_message'] = rh_licence_refusal_message($st);
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
            header('X-RH-Licence: read_only');
            header('Location: ' . $back, true, 303);
        }
        exit;
    }

    // ------------------------------------------------------------------ the one call

    /**
     * Call once per staff request, AFTER config/database.php is loaded (so $pdo exists) and after the sign-in check.
     * Does nothing when licensing is off, on the command line, or when nobody is signed in. A fault inside the licence code
     * never blocks anyone (it fails open); only a clear read_only / suspended answer refuses.
     */
    function rh_licence_guard_request(): void
    {
        if (PHP_SAPI === 'cli' || !rh_licence_enabled()) {
            return;
        }
        try {
            if (empty($_SESSION['admin_user_id'])) {
                return;
            }
            [$area, $script] = rh_licence_request_target();
            if ($area === 'other') {
                return;
            }
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            // First, so even a locked site checks again and can recover once the hotel has paid.
            if ($area === 'admin' && $method === 'GET' && function_exists('rh_admin_request_is_activity') && rh_admin_request_is_activity()) {
                rh_licence_maybe_check_after_response();
            }
            $st = rh_licence_status();
            if (!in_array($st['status'], ['read_only', 'suspended'], true)) {
                return;
            }
            $d = rh_licence_decide($st['status'], $area, $script, $method, $_GET, $_POST);
            if ($d['allow']) {
                return;
            }
        } catch (Throwable $e) {
            error_log('licence guard: ' . $e->getMessage());
            return;
        }
        rh_licence_refuse($st, $area, $method);
    }
}
