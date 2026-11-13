<?php

/**
 * Staff activity (System Logs > Staff activity): who did what, per person and per day.
 *
 * Read-only. Reads the records that already say who did something (Booking History, payments, orders, kitchen/bar
 * screen moves, shifts, stock records, the admin activity log) and merges them into one timeline, plus a per-person,
 * per-day summary. Every query is a prepared statement with a half-open time range on the time column and a LIMIT;
 * the sources are merged in PHP (no SQL UNION of text columns, so no collation clashes).
 *
 * Never shown: old/new values, passwords, tokens, card data, request bodies, context_json. Sentences are built from
 * names, references, amounts and field NAMES only. The one exception: 'setting_change' rows (Hotel Settings, owner
 * 2026-11-14) read "Changed Tourism levy % from 1 to 1.5"; their values were cleaned and secrets masked when written
 * (includes/settings-change-log.php).
 *
 * The hook that writes the 'page_action' rows is admin/includes/activity-hook.php.
 */

if (!defined('RH_SA_PER_PAGE')) {
    define('RH_SA_PER_PAGE', 10);      // default rows per page (the shared pager's picker offers 10 / 25 / 50 / 100)
    define('RH_SA_MAX_DEPTH', 1000);   // newest rows a person can page through
    define('RH_SA_MAX_DAYS', 31);      // widest date range
    define('RH_SA_CSV_ROWS', 3000);
    define('RH_SA_COUNT_CAP', 10000);  // the timeline total is counted up to here ("10,000+" above it)
}

// The timeline and the day cards page with the shared System Logs pager (keys 'sa' and 'sad'). Loading it connects to nothing.
require_once __DIR__ . '/log-pager.php';
// Hotel Settings change rows ('setting_change'): their sentence. Loading it connects to nothing and logs nothing.
require_once __DIR__ . '/../../includes/settings-change-log.php';

if (!function_exists('rh_sa_parse_filters')) {

    /** Timeline types: key => label. */
    function rh_sa_types(): array
    {
        return [
            'signins' => 'Sign-ins',
            'bookings' => 'Bookings',
            'payments' => 'Payments',
            'till' => 'Till & orders',
            'kitchen' => 'Kitchen/bar screens',
            'stock' => 'Stock',
            'settings' => 'Settings & users',
            'setting_changes' => 'Hotel Settings changes',
            'other' => 'Other',
        ];
    }

    /** Summary figures: key => [label, plain helper]. */
    function rh_sa_counters(): array
    {
        return [
            'signins' => ['Sign-ins', 'Times this person signed in on that day.'],
            'bookings' => ['Bookings changed', 'Booking History entries by this person: booking created, edited, cancelled, checked in or out, no-show.'],
            'payments' => ['Payments recorded', 'Guest payments this person entered (not pending or failed), with the total. Refunds are counted separately.'],
            'refunds' => ['Refunds', 'Refund records this person entered, with the full amount refunded.'],
            'orders' => ['Orders', 'Till, bar and room-service orders this person started.'],
            'till' => ['Till actions', 'Orders voided, shifts opened and shifts closed by this person, plus named till events such as a refund, a discount or adding to a tab.'],
            'kitchen' => ['Kitchen/bar moves', 'Moves this person made on the kitchen and bar screens (started, ready, served, bumped).'],
            'stock' => ['Stock changes', 'Stock received, adjusted, wasted, counted or ordered by this person, plus stock audit events.'],
            'settings' => ['Settings & users', 'Named audit events: staff and permission changes, password resets, backups, licence checks.'],
            'saves' => ['Screen saves', 'Every successful save recorded from any screen (screen and action name only). It overlaps the figures above and also covers screens with no record of their own.'],
        ];
    }

    /**
     * Named admin_activity_log action prefixes per type (first match wins; the SQL type filter uses the same table).
     * Every entry keeps the shape '^(a|b)': the "Other" filter strips '^(' and ')' to build one exclusion list.
     */
    function rh_sa_action_prefixes(): array
    {
        return [
            'setting_changes' => '^(setting_change)',
            'signins' => '^(login|logout)',
            'payments' => '^(refund|payment|receipt|invoice|credit_note)',
            'till' => '^(pos|shift|till)',
            'kitchen' => '^(kds|bds|cds)',
            'stock' => '^(stock|barcode|supplier|wastage|purchase)',
            'settings' => '^(permissions|user|password|role|error_log|backup|licence|ai_|module|settings|cache)',
            'bookings' => '^(booking|checkin|check_in|checkout|room)',
        ];
    }

    function rh_sa_action_type(string $action): string
    {
        foreach (rh_sa_action_prefixes() as $type => $re) {
            if (preg_match('/' . $re . '/i', $action)) {
                return $type;
            }
        }
        return 'other';
    }

    function rh_sa_today(): string
    {
        return date('Y-m-d');
    }

    function rh_sa_valid_date($v): ?string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : null;
    }

    /**
     * Read the filters from GET. 'filtered' is true when the person used the Staff activity filters (marker sa=1),
     * which is also when the older panels on the page follow the same person / day.
     */
    function rh_sa_parse_filters(array $get, ?string $today = null): array
    {
        $today = $today ?: rh_sa_today();
        $types = rh_sa_types();
        $user = is_scalar($get['user'] ?? null) ? (int)$get['user'] : 0;
        $user = $user > 0 ? $user : 0;
        $type = is_string($get['type'] ?? null) ? $get['type'] : 'all';
        if ($type !== 'all' && !isset($types[$type])) {
            $type = 'all';
        }
        $range = is_string($get['range'] ?? null) ? $get['range'] : '';
        $from = rh_sa_valid_date($get['from'] ?? null);
        $to = rh_sa_valid_date($get['to'] ?? null);
        if ($range === 'today') {
            $from = $to = $today;
        } elseif ($range === 'yesterday') {
            $from = $to = date('Y-m-d', strtotime($today . ' -1 day'));
        } elseif ($range === '7d') {
            $to = $today;
            $from = date('Y-m-d', strtotime($today . ' -6 days'));
        } else {
            $range = '';
        }
        if ($from === null && $to === null) {
            $from = $to = $today;
            $range = $range ?: 'today';
        } elseif ($from === null) {
            $from = $to;
        } elseif ($to === null) {
            $to = $from;
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $clamped = false;
        if ((strtotime($to) - strtotime($from)) / 86400 > RH_SA_MAX_DAYS - 1) {
            $from = date('Y-m-d', strtotime($to . ' -' . (RH_SA_MAX_DAYS - 1) . ' days'));
            $clamped = true;
        }
        // The page is not a filter: the timeline pages with sa_page / sa_size (rh_sa_pager). An old ?p= is ignored.
        return [
            'user' => $user, 'type' => $type, 'from' => $from, 'to' => $to, 'range' => $range,
            'clamped' => $clamped, 'filtered' => isset($get['sa']),
        ];
    }

    /** [start, end-exclusive] datetime strings for the filter's days. */
    function rh_sa_bounds(array $f): array
    {
        return [$f['from'] . ' 00:00:00', date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'];
    }

    /** Query keys that belong to Staff activity itself (links set them afresh; the page numbers start again at 1). */
    function rh_sa_own_keys(): array
    {
        return ['sa', 'user', 'type', 'from', 'to', 'range', 'p', 'sa_page', 'sad_page', 'export', 'r'];
    }

    /**
     * The filter as GET parameters (for links), without a page number. $get (the page's query string) is kept for the
     * other sections (source, level, group, auto, their {key}_page / {key}_size) and the rows-per-page choices
     * sa_size / sad_size; Staff activity's own keys, its page numbers, an export and the auto-reload marker are not.
     */
    function rh_sa_query(array $f, array $over = [], array $get = []): string
    {
        $q = [];
        $own = rh_sa_own_keys();
        foreach ($get as $k => $v) {
            if (is_string($k) && is_string($v) && preg_match('/^[a-z][a-z0-9_]{0,30}$/', $k) && !in_array($k, $own, true)
                && !in_array($k, ['action', 'csrf_token'], true)) {
                $q[$k] = $v;
            }
        }
        $q = array_merge($q, ['sa' => 1, 'user' => $f['user'] ?: '', 'type' => $f['type'], 'from' => $f['from'], 'to' => $f['to']]);
        foreach ($over as $k => $v) {
            $q[$k] = $v;
        }
        foreach ($q as $k => $v) {
            if ($v === '' || $v === null) {
                unset($q[$k]);
            }
        }
        if (isset($q['range'])) {
            unset($q['from'], $q['to']);
        }
        return http_build_query($q);
    }

    /**
     * For the older panels on System Logs: an extra condition for the chosen person / days when the Staff activity
     * filters are in use; nothing otherwise (the page's default view is unchanged). $lead is 'WHERE' or 'AND'.
     * @return array{0:string,1:array}
     */
    function rh_sa_panel_clause(array $f, string $tsCol, ?string $uidCol, string $lead = 'AND'): array
    {
        if (empty($f['filtered'])) {
            return ['', []];
        }
        [$a, $b] = rh_sa_bounds($f);
        $sql = " {$tsCol} >= ? AND {$tsCol} < ?";
        $args = [$a, $b];
        if ($f['user'] > 0 && $uidCol !== null) {
            $sql .= " AND {$uidCol} = ?";
            $args[] = $f['user'];
        }
        return [' ' . $lead . $sql, $args];
    }

    /** Which timeline type a day-card figure opens (Screen saves overlap every type, so they open everything). */
    function rh_sa_counter_type(string $counter): string
    {
        $map = ['signins' => 'signins', 'bookings' => 'bookings', 'payments' => 'payments', 'refunds' => 'payments',
            'orders' => 'till', 'till' => 'till', 'kitchen' => 'kitchen', 'stock' => 'stock', 'settings' => 'settings', 'saves' => 'all'];
        return $map[$counter] ?? 'all';
    }

    // ------------------------------------------------------------------ paging (shared System Logs pager, key 'sa')

    /**
     * The timeline's pager from the query string (sa_page / sa_size) and the counted total (rh_sa_count: at most
     * RH_SA_COUNT_CAP + 1, or -1 when it could not be counted). Only the newest RH_SA_MAX_DEPTH lines can be paged
     * through, so the pages stop there while the summary keeps the real total ('beyond' = there are more). A page
     * past the end moves to the last page. Same array shape as rh_lp_parse(), for rh_lp_render().
     */
    function rh_sa_pager(array $get, int $total, int $depth = RH_SA_MAX_DEPTH): array
    {
        $p = rh_lp_parse($get, 'sa');
        $maxPage = max(1, (int)ceil($depth / $p['size']));
        $p['beyond'] = false;
        $p['max_page'] = $maxPage;
        if ($total < 0) {
            // Count failed: keep the asked page (never past the depth); rh_sa_pager_more lets the rows decide Next.
            $p['unknown'] = true;
            $p['total'] = 0;
            $p['page'] = min($p['page'], $maxPage);
            $p['pages'] = $p['page'];
        } else {
            $p['capped'] = $total > RH_SA_COUNT_CAP;
            $p['total'] = $p['capped'] ? RH_SA_COUNT_CAP : $total;
            $p['beyond'] = $total > $depth;
            $p['pages'] = max(1, (int)ceil(min($p['total'], $depth) / $p['size']));
            $p['page'] = min($p['page'], $p['pages']);
        }
        $p['offset'] = ($p['page'] - 1) * $p['size'];
        $p['more'] = $p['page'] < $p['pages'];
        return $p;
    }

    /** After the timeline loaded: with no count, Next shows while the timeline has more (never past the depth). */
    function rh_sa_pager_more(array $p, bool $hasMore): array
    {
        if (!empty($p['unknown'])) {
            $p['more'] = $hasMore && $p['page'] < $p['max_page'];
            $p['pages'] = $p['page'] + ($p['more'] ? 1 : 0);
        }
        return $p;
    }

    /** The plain sentence under the timeline's pager ('' when none is needed). */
    function rh_sa_pager_note(array $p): string
    {
        if (!empty($p['unknown'])) {
            return rh_lp_cap_note($p);
        }
        if (!empty($p['beyond'])) {
            return 'Only the newest ' . (int)RH_SA_MAX_DEPTH . ' lines can be paged through. Pick one person or a shorter range, or download the CSV.';
        }
        return '';
    }

    // ------------------------------------------------------------------ names, pages, wording

    /** @return array<int,array{name:string,username:string,role:string}> */
    function rh_sa_staff(PDO $pdo): array
    {
        $out = [];
        try {
            foreach ($pdo->query("SELECT id, username, full_name, role FROM admin_users ORDER BY full_name, username LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $name = trim((string)$r['full_name']) !== '' ? trim((string)$r['full_name']) : (string)$r['username'];
                $out[(int)$r['id']] = ['name' => $name, 'username' => (string)$r['username'], 'role' => (string)$r['role']];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** Screens outside the sidebar that staff save from: file => [title, type]. */
    function rh_sa_extra_pages(): array
    {
        return [
            'create-booking.php' => ['Create Booking', 'bookings'], 'edit-booking.php' => ['Edit Booking', 'bookings'],
            'booking-details.php' => ['Booking Details', 'bookings'], 'process-checkin.php' => ['Check-in', 'bookings'],
            'tentative-bookings.php' => ['Tentative Bookings', 'bookings'], 'room-dashboard.php' => ['Room Dashboard', 'bookings'],
            'send-quotation.php' => ['Quotation', 'bookings'], 'tentative-actions.php' => ['Tentative Bookings', 'bookings'],
            'payment-details.php' => ['Payment Details', 'payments'], 'payment-refund.php' => ['Refund', 'payments'],
            'credit-notes.php' => ['Credit Notes', 'payments'],
            'shift-close-report.php' => ['Shift Close', 'till'], 'pos-drift-report.php' => ['Till Drift Report', 'till'],
            'order-lifecycle.php' => ['Order Lifecycle', 'till'], 'menu-recipe.php' => ['Menu Recipe', 'till'],
            'stock-receipt.php' => ['Stock Receipt', 'stock'],
            'change-password.php' => ['Change Password', 'settings'], 'user-permissions.php' => ['Staff Permissions', 'settings'],
            'toggle-module.php' => ['Modules', 'settings'], 'apply-preset.php' => ['Business Setup', 'settings'],
            'licence.php' => ['Licence', 'settings'],
            'ai-action.php' => ['Assistant', 'other'], 'gym-packages.php' => ['Gym Packages', 'other'],
            'facebook-post.php' => ['Facebook', 'other'], 'review-responses.php' => ['Reviews', 'other'],
        ];
    }

    /**
     * file => ['title','type','nav'] for every screen we know. Built from the sidebar list (one source for page titles),
     * then the extras. Kitchen/bar screens are their own type.
     */
    function rh_sa_pages(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        $groupType = ['Front Desk' => 'bookings', 'Conference & Events' => 'other', 'Restaurant & Bar' => 'till', 'Stock' => 'stock',
            'Money' => 'payments', 'Gym' => 'other', 'Website' => 'other', 'Settings' => 'settings'];
        try {
            if (!function_exists('rh_admin_nav_groups') && is_file(__DIR__ . '/admin-nav-items.php')) {
                require_once __DIR__ . '/admin-nav-items.php';
            }
            if (function_exists('rh_admin_nav_groups')) {
                foreach (rh_admin_nav_groups() as $group => $items) {
                    if (!isset($groupType[$group])) {
                        continue;
                    }
                    foreach ($items as $it) {
                        $file = strtolower((string)$it[0]);
                        if (!preg_match('/^[a-z0-9_-]+\.php$/', $file)) {
                            continue;
                        }
                        $type = $groupType[$group];
                        if (in_array($file, ['kds.php', 'bds.php', 'cds.php', 'room-service-dashboard.php'], true)) {
                            $type = 'kitchen';
                        }
                        $map[$file] = ['title' => (string)$it[2], 'type' => $type, 'nav' => true];
                    }
                }
            }
        } catch (Throwable $e) {
        }
        foreach (rh_sa_extra_pages() as $file => [$title, $type]) {
            if (!isset($map[$file])) {
                $map[$file] = ['title' => $title, 'type' => $type, 'nav' => false];
            }
        }
        return $map;
    }

    function rh_sa_prettify(string $code): string
    {
        $s = trim(str_replace(['_', '-'], ' ', strtolower($code)));
        return $s;
    }

    function rh_sa_page_title(string $file): string
    {
        $pages = rh_sa_pages();
        if (isset($pages[$file])) {
            return $pages[$file]['title'];
        }
        return ucwords(rh_sa_prettify(preg_replace('/\.php$/', '', $file)));
    }

    function rh_sa_page_type(string $file): string
    {
        $pages = rh_sa_pages();
        return $pages[$file]['type'] ?? 'other';
    }

    /** Friendly sentences for common screen + action pairs. Anything else reads "Saved on <Screen>". */
    function rh_sa_action_sentences(): array
    {
        return [
            'booking_action' => [
                'add_charge' => 'Added a charge to a booking', 'add_menu_item' => 'Added a menu item to a booking',
                'cancel' => 'Cancelled a booking', 'checkin' => 'Checked a guest in', 'checkout' => 'Checked a guest out',
                'confirm' => 'Confirmed a booking', 'convert' => 'Converted a tentative booking', 'noshow' => 'Marked a booking as no-show',
                'generate_invoice' => 'Generated an invoice', 'regenerate_invoice' => 'Regenerated an invoice',
                'send_invoice' => 'Emailed an invoice', 'send_invoice_whatsapp' => 'Sent an invoice by WhatsApp',
                'tentative' => 'Made a booking tentative', 'void_charge' => 'Voided a booking charge',
            ],
            'action' => [
                'update_status' => 'Changed a booking status', 'checkout' => 'Checked a guest out', 'checkout_settle' => 'Checked a guest out and settled',
                'consolidation_record' => 'Recorded a consolidated payment', 'update_payment' => 'Updated a payment',
                'add_to_tab' => 'Added to a bar tab', 'close_shift' => 'Closed a till shift', 'pay_existing' => 'Took payment on an open order',
                'park' => 'Parked an order', 'refund' => 'Refunded an order', 'refund_order' => 'Refunded an order', 'set_float' => 'Set the opening float',
                'settle' => 'Settled an order', 'settle_refund' => 'Settled a refund', 'settle_order' => 'Settled an order',
                'settle_table_order' => 'Settled a table order', 'record_payment' => 'Recorded a payment on an account',
                'generate_receipt' => 'Generated a receipt', 'email_receipt' => 'Emailed a receipt', 'run_backup' => 'Ran a backup',
            ],
        ];
    }

    /** Split a stored "page=x.php; action=y; booking_action=z" into [page, [key=>value]]. Anything unexpected is dropped. */
    function rh_sa_parse_details(string $details): array
    {
        $page = '';
        $acts = [];
        foreach (explode(';', $details) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 'page' && preg_match('/^[a-z0-9_-]{1,80}\.php$/', $kv[1])) {
                $page = $kv[1];
            } elseif (in_array($kv[0], ['action', 'booking_action', 'charge_action', 'invoice_action', 'ajax'], true) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $kv[1])) {
                $acts[$kv[0]] = $kv[1];
            }
        }
        return [$page, $acts];
    }

    function rh_sa_page_action_text(string $details): string
    {
        [$page, $acts] = rh_sa_parse_details($details);
        if ($page === '') {
            return 'Saved on a screen';
        }
        $sentences = rh_sa_action_sentences();
        foreach ($acts as $key => $val) {
            if (isset($sentences[$key][$val])) {
                return $sentences[$key][$val] . ' (' . rh_sa_page_title($page) . ')';
            }
        }
        $text = 'Saved on ' . rh_sa_page_title($page);
        if ($acts) {
            $text .= ' (' . rh_sa_prettify((string)reset($acts)) . ')';
        }
        return $text;
    }

    function rh_sa_named_action_text(string $action, string $details): string
    {
        static $names = [
            'login_success' => 'Signed in', 'logout' => 'Signed out', 'login_failed' => 'Failed sign-in attempt', 'login_blocked' => 'Sign-in blocked',
            'permissions_changed' => 'Changed a staff member\'s permissions', 'permissions_reset' => 'Reset a staff member\'s permissions',
            'user_invited' => 'Invited a staff member', 'user_created' => 'Added a staff member', 'user_deleted' => 'Removed a staff member',
            'user_updated' => 'Updated a staff member', 'password_reset' => 'Reset a password', 'role_change' => 'Changed a staff role',
            'error_log_cleared' => 'Cleared an error log', 'backup_run' => 'Ran a backup', 'backup_deleted' => 'Deleted a backup',
            'licence_check' => 'Checked the licence', 'pos_refund' => 'Refunded a till order', 'pos_add_to_tab' => 'Added to a bar tab',
            'pos_discount' => 'Gave a till discount', 'pos_barcode_assigned' => 'Assigned a barcode to a product',
            'refund_created' => 'Created a refund', 'stock_count_deleted' => 'Deleted a stock count', 'stock_count_rejected' => 'Rejected a stock count',
            'supplier_deleted' => 'Deleted a supplier', 'barcode_registered' => 'Registered a barcode', 'barcode_receive_batch' => 'Received stock by barcode',
            'barcode_item_registered' => 'Registered a new item by barcode',
        ];
        $text = $names[$action] ?? ucfirst(rh_sa_prettify($action));
        if (!in_array($action, ['login_success', 'logout', 'login_failed', 'login_blocked'], true) && trim($details) !== '') {
            $d = trim(preg_replace('/\s+/', ' ', $details));
            if (preg_match('/pass(word)?\s*[:=]|token|secret|api[_ -]?key/i', $d)) {
                $d = '';
            }
            if ($d !== '') {
                $text .= ': ' . (function_exists('mb_substr') ? mb_substr($d, 0, 140) : substr($d, 0, 140));
            }
        }
        return $text;
    }

    function rh_sa_money(float $amount): string
    {
        static $cur = null;
        if ($cur === null) {
            $cur = 'MWK';
            try {
                if (function_exists('getSetting')) {
                    $c = trim((string)getSetting('currency_symbol', 'MWK'));
                    $cur = $c !== '' ? $c : 'MWK';
                }
            } catch (Throwable $e) {
            }
        }
        return $cur . ' ' . number_format($amount, 0);
    }

    /** Field names from Booking History's changed_fields (a JSON list). Names only, secret-looking names dropped. */
    function rh_sa_field_names($raw): array
    {
        $arr = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($arr)) {
            return [];
        }
        $names = [];
        foreach ($arr as $k => $v) {
            $n = is_string($v) ? $v : (is_string($k) ? $k : '');
            if ($n === '' || !preg_match('/^[A-Za-z0-9_ .-]{1,40}$/', $n) || preg_match('/pass|token|secret|card|cvv|pin\b/i', $n)) {
                continue;
            }
            $names[] = rh_sa_prettify($n);
        }
        return array_slice(array_values(array_unique($names)), 0, 6);
    }

    // ------------------------------------------------------------------ sources

    /**
     * The records that say who did what. 'counter' is the summary figure a row counts toward (null = shown in the
     * timeline only, or counted elsewhere). 'money' is a SQL amount expression summed for the figure.
     */
    function rh_sa_sources(): array
    {
        return [
            'booking' => ['table' => 'booking_audit_log', 'type' => 'bookings', 'counter' => 'bookings', 'id' => 'id', 'ts' => 'performed_at', 'uid' => 'performed_by', 'name' => 'performed_by_name',
                'cols' => 'booking_id, booking_reference, action, changed_fields'],
            // Same rows and amounts as payments.php / the end-of-day report (accounting lead 2026-11-07). The day is when the
            // person recorded it (created_at), since this is an activity view; payments made through the API carry
            // processed_by instead of recorded_by.
            'payment' => ['table' => 'payments', 'type' => 'payments', 'counter' => 'payments', 'id' => 'id', 'ts' => 'created_at', 'uid' => 'COALESCE(NULLIF(recorded_by,0), processed_by)', 'name' => null,
                'where' => "deleted_at IS NULL AND COALESCE(payment_type,'') <> 'refund' AND payment_status IN ('completed','paid','refunded','partially_refunded')",
                'money' => 'COALESCE(NULLIF(total_amount,0), payment_amount, 0)',
                'cols' => 'booking_id, booking_type, booking_reference, payment_method, COALESCE(NULLIF(total_amount,0), payment_amount, 0) AS amount'],
            'refund' => ['table' => 'payments', 'type' => 'payments', 'counter' => 'refunds', 'id' => 'id', 'ts' => 'created_at', 'uid' => 'COALESCE(NULLIF(recorded_by,0), processed_by)', 'name' => null,
                'where' => "deleted_at IS NULL AND payment_type = 'refund' AND refund_status IN ('completed','processing')",
                'money' => 'COALESCE(NULLIF(refund_amount,0), NULLIF(total_amount,0), payment_amount, 0)',
                'cols' => 'booking_id, booking_type, booking_reference, payment_method, COALESCE(NULLIF(refund_amount,0), NULLIF(total_amount,0), payment_amount, 0) AS amount'],
            'credit_note' => ['table' => 'credit_notes', 'type' => 'payments', 'counter' => null, 'id' => 'id', 'ts' => 'issued_at', 'uid' => 'issued_by', 'name' => null,
                'cols' => 'credit_note_number, original_amount AS amount'],
            'order' => ['table' => 'stock_orders', 'type' => 'till', 'counter' => 'orders', 'id' => 'id', 'ts' => 'created_at', 'uid' => 'created_by', 'name' => null,
                'cols' => 'reference, order_type, total_amount AS amount'],
            'order_paid' => ['table' => 'stock_orders', 'type' => 'till', 'counter' => null, 'id' => 'id', 'ts' => 'paid_at', 'uid' => 'paid_by', 'name' => null,
                'cols' => 'reference, payment_method, total_amount AS amount'],
            'order_void' => ['table' => 'stock_orders', 'type' => 'till', 'counter' => 'till', 'id' => 'id', 'ts' => 'voided_at', 'uid' => 'voided_by', 'name' => null,
                'cols' => 'reference, total_amount AS amount'],
            'order_audit' => ['table' => 'stock_order_audit', 'type' => 'till', 'counter' => null, 'id' => 'id', 'ts' => 'created_at', 'uid' => 'actor_id', 'name' => 'actor_name',
                'cols' => 'order_id, event'],
            'shift_open' => ['table' => 'stock_shift_opens', 'type' => 'till', 'counter' => 'till', 'id' => 'id', 'ts' => 'opened_at', 'uid' => 'user_id', 'name' => 'user_name',
                'cols' => 'float_amount AS amount'],
            'shift_close' => ['table' => 'stock_shift_closes', 'type' => 'till', 'counter' => 'till', 'id' => 'id', 'ts' => 'closed_at', 'uid' => 'user_id', 'name' => 'user_name',
                'cols' => '(COALESCE(expected_cash,0)+COALESCE(expected_mobile,0)+COALESCE(expected_card,0)) AS expected, (COALESCE(declared_cash,0)+COALESCE(declared_mobile,0)+COALESCE(declared_card,0)) AS declared'],
            'offline' => ['table' => 'offline_replay_log', 'type' => 'till', 'counter' => null, 'id' => 'id', 'ts' => 'replayed_at', 'uid' => 'user_id', 'name' => 'username',
                'cols' => 'endpoint, action, response_status'],
            'kds' => ['table' => 'stock_kds_events', 'type' => 'kitchen', 'counter' => 'kitchen', 'id' => 'id', 'ts' => 'created_at', 'uid' => 'user_id', 'name' => 'user_name',
                'cols' => 'order_id, event, to_status'],
            'stock_adj' => ['table' => 'stock_adjustments', 'type' => 'stock', 'counter' => 'stock', 'id' => 'id', 'ts' => 'created_at', 'uid' => 'adjusted_by', 'name' => null,
                'cols' => 'reason'],
            'stock_waste' => ['table' => 'stock_wastage', 'type' => 'stock', 'counter' => 'stock', 'id' => 'id', 'ts' => 'created_at', 'uid' => 'recorded_by', 'name' => null,
                'cols' => 'wastage_cost AS amount'],
            'stock_in' => ['table' => 'stock_in_log', 'type' => 'stock', 'counter' => 'stock', 'id' => 'id', 'ts' => 'created_at', 'uid' => 'created_by', 'name' => null,
                'cols' => 'cost_total AS amount, supplier_name'],
            'stock_count' => ['table' => 'stock_counts', 'type' => 'stock', 'counter' => 'stock', 'id' => 'id', 'ts' => 'created_at', 'uid' => 'counted_by', 'name' => null,
                'cols' => 'reference, status, is_opening'],
            'po' => ['table' => 'stock_purchase_orders', 'type' => 'stock', 'counter' => 'stock', 'id' => 'id', 'ts' => 'created_at', 'uid' => 'created_by', 'name' => null,
                'cols' => 'reference, total_cost AS amount'],
            'event' => ['table' => 'system_event_log', 'type' => 'other', 'counter' => null, 'id' => 'id', 'ts' => 'created_at', 'uid' => 'user_id', 'name' => 'username',
                'where' => "level IN ('warning','error','critical') AND user_id > 0", 'no_summary' => true,
                'cols' => 'source, level, LEFT(message, 160) AS message'],
        ];
    }

    function rh_sa_tables(PDO $pdo): array
    {
        static $cache = [];
        $key = spl_object_id($pdo);
        if (!isset($cache[$key])) {
            $cache[$key] = [];
            try {
                foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN) as $t) {
                    $cache[$key][strtolower((string)$t)] = true;
                }
            } catch (Throwable $e) {
            }
        }
        return $cache[$key];
    }

    /** Sources to read for this type filter, skipping tables this install does not have. */
    function rh_sa_active_sources(PDO $pdo, string $type): array
    {
        $tables = rh_sa_tables($pdo);
        $out = [];
        foreach (rh_sa_sources() as $key => $s) {
            if (!isset($tables[$s['table']])) {
                continue;
            }
            if ($type !== 'all' && $type !== $s['type']) {
                continue;
            }
            $out[$key] = $s;
        }
        return $out;
    }

    /** SQL + args for the admin_activity_log slice that belongs to a type ('' = no restriction). @return array{0:string,1:array} */
    function rh_sa_activity_type_sql(string $type): array
    {
        if ($type === 'all') {
            return ['', []];
        }
        $prefixes = rh_sa_action_prefixes();
        $pages = rh_sa_pages();
        if ($type === 'other') {
            $nonOther = [];
            foreach ($pages as $file => $p) {
                if ($p['type'] !== 'other') {
                    $nonOther[] = $file;
                }
            }
            $sql = "((action <> 'page_action' AND action NOT REGEXP ?) OR (action = 'page_action'";
            $args = ['^(' . implode('|', array_map(function ($re) { return rtrim(substr($re, 2), ')'); }, $prefixes)) . ')'];
            if ($nonOther) {
                $sql .= ' AND SUBSTRING_INDEX(SUBSTRING(details, 6), \';\', 1) NOT IN (' . implode(',', array_fill(0, count($nonOther), '?')) . ')';
                $args = array_merge($args, $nonOther);
            }
            return [$sql . '))', $args];
        }
        $sql = '(';
        $args = [];
        $parts = [];
        if (isset($prefixes[$type])) {
            $parts[] = "(action <> 'page_action' AND action REGEXP ?)";
            $args[] = $prefixes[$type];
        }
        $files = [];
        foreach ($pages as $file => $p) {
            if ($p['type'] === $type) {
                $files[] = $file;
            }
        }
        if ($files) {
            $parts[] = "(action = 'page_action' AND SUBSTRING_INDEX(SUBSTRING(details, 6), ';', 1) IN (" . implode(',', array_fill(0, count($files), '?')) . '))';
            $args = array_merge($args, $files);
        }
        if (!$parts) {
            return ['(1 = 0)', []];
        }
        return [$sql . implode(' OR ', $parts) . ')', $args];
    }

    // ------------------------------------------------------------------ timeline

    /**
     * The FROM + WHERE every timeline source is read with, shared by the timeline and its count so they can never
     * drift apart. Table and column names come only from rh_sa_sources(); every value is a placeholder.
     * @return array{0:string,1:array}
     */
    function rh_sa_source_from(array $s, array $f): array
    {
        [$a, $b] = rh_sa_bounds($f);
        $sql = " FROM {$s['table']} WHERE {$s['ts']} >= ? AND {$s['ts']} < ?";
        $args = [$a, $b];
        if ($f['user'] > 0) {
            $sql .= " AND {$s['uid']} = ?";
            $args[] = $f['user'];
        }
        if (!empty($s['where'])) {
            $sql .= ' AND ' . $s['where'];
        }
        return [$sql, $args];
    }

    /** The same for the admin activity log (sign-ins, named audit events, screen saves), with the type filter. */
    function rh_sa_activity_from(array $f): array
    {
        [$a, $b] = rh_sa_bounds($f);
        $sql = ' FROM admin_activity_log WHERE created_at >= ? AND created_at < ?';
        $args = [$a, $b];
        if ($f['user'] > 0) {
            $sql .= ' AND user_id = ?';
            $args[] = $f['user'];
        }
        [$tsql, $targs] = rh_sa_activity_type_sql($f['type']);
        if ($tsql !== '') {
            $sql .= ' AND ' . $tsql;
            $args = array_merge($args, $targs);
        }
        return [$sql, $args];
    }

    /**
     * Newest-first merged timeline. $offset/$limit are rows of the merged list; each source is asked for the newest
     * offset+limit+1 rows, then they are merged and sliced here. @return array{rows:array,has_more:bool}
     */
    function rh_sa_timeline(PDO $pdo, array $f, int $offset, int $limit): array
    {
        $offset = max(0, min($offset, RH_SA_MAX_DEPTH));
        $limit = max(1, min($limit, RH_SA_CSV_ROWS));
        $need = min($offset + $limit + 1, RH_SA_CSV_ROWS + 1); // UI pages: offset <= 900 + 100 rows, well inside
        $staff = rh_sa_staff($pdo);
        $all = [];

        foreach (rh_sa_active_sources($pdo, $f['type']) as $key => $s) {
            try {
                [$from, $args] = rh_sa_source_from($s, $f);
                $sql = "SELECT {$s['id']} AS _id, {$s['ts']} AS _ts, {$s['uid']} AS _uid, " . ($s['name'] ? $s['name'] : 'NULL') . " AS _name, {$s['cols']}"
                    . $from . " ORDER BY {$s['ts']} DESC, {$s['id']} DESC LIMIT " . (int)$need;
                $st = $pdo->prepare($sql);
                $st->execute($args);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $all[] = rh_sa_describe($key, $r, $staff);
                }
            } catch (Throwable $e) {
                // a source this install cannot read is skipped, the rest still show
            }
        }

        try {
            [$from, $args] = rh_sa_activity_from($f);
            $st = $pdo->prepare('SELECT id AS _id, created_at AS _ts, user_id AS _uid, username AS _name, action, details' . $from
                . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int)$need);
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $all[] = rh_sa_describe('activity', $r, $staff);
            }
        } catch (Throwable $e) {
        }

        usort($all, function ($x, $y) {
            return [$y['ts'], $y['order'], $y['id']] <=> [$x['ts'], $x['order'], $x['id']];
        });
        $slice = array_slice($all, $offset, $limit);
        return ['rows' => $slice, 'has_more' => count($all) > $offset + $limit];
    }

    /**
     * How many timeline lines the filter matches, counted up to $cap (returns at most $cap + 1). Each source is
     * counted with exactly the timeline's FROM + WHERE; a source whose query fails is skipped, as the timeline skips
     * it. -1 when nothing could be counted at all (the pager then says "count unavailable").
     */
    function rh_sa_count(PDO $pdo, array $f, int $cap = RH_SA_COUNT_CAP): int
    {
        $parts = [];
        foreach (rh_sa_active_sources($pdo, $f['type']) as $s) {
            $parts[] = rh_sa_source_from($s, $f);
        }
        $parts[] = rh_sa_activity_from($f);
        $total = 0;
        $counted = 0;
        foreach ($parts as [$from, $args]) {
            try {
                $st = $pdo->prepare('SELECT COUNT(*) FROM (SELECT 1 AS one' . $from . ' LIMIT ' . ((int)$cap + 1) . ') rh_sa_x');
                $st->execute($args);
                $total += (int)$st->fetchColumn();
                $counted++;
            } catch (Throwable $e) {
                // skipped, as in the timeline
            }
            if ($total > $cap) {
                return $cap + 1;
            }
        }
        return $counted > 0 ? $total : -1;
    }

    /**
     * Who a line belongs to. A line with no staff account behind it (the system, the guest website, an API client, a
     * failed sign-in) reads "System / unknown user", with any name the record carries in brackets. Day cards only
     * cover staff accounts, so these lines show in the list and the CSV but get no card.
     */
    function rh_sa_who(array $r, array $staff): string
    {
        $uid = (int)($r['_uid'] ?? 0);
        if ($uid > 0 && isset($staff[$uid])) {
            return $staff[$uid]['name'];
        }
        $n = trim((string)($r['_name'] ?? ''));
        if ($uid > 0) {
            return $n !== '' ? $n : 'User #' . $uid;
        }
        return 'System / unknown user' . ($n !== '' ? ' (' . (function_exists('mb_substr') ? mb_substr($n, 0, 60) : substr($n, 0, 60)) . ')' : '');
    }

    /** One source row to a timeline row: ts, who, type, what, where, link. */
    function rh_sa_describe(string $src, array $r, array $staff): array
    {
        $types = rh_sa_types();
        $uid = (int)($r['_uid'] ?? 0);
        $row = ['ts' => (string)$r['_ts'], 'id' => (int)$r['_id'], 'order' => $src, 'uid' => $uid, 'who' => rh_sa_who($r, $staff),
            'type' => 'other', 'what' => '', 'where' => '', 'link' => null, 'src' => $src, 'screen_save' => false];
        $money = function ($k = 'amount') use ($r) { return rh_sa_money((float)($r[$k] ?? 0)); };
        $ref = function ($k) use ($r) { $v = trim((string)($r[$k] ?? '')); return $v !== '' ? $v : null; };

        switch ($src) {
            case 'booking':
                $row['type'] = 'bookings';
                $bref = $ref('booking_reference') ?? ('#' . (int)$r['booking_id']);
                $action = (string)$r['action'];
                if ($action === 'created') {
                    $row['what'] = 'Created booking ' . $bref;
                } elseif ($action === 'no-show') {
                    $row['what'] = 'Marked booking ' . $bref . ' as no-show';
                } elseif ($action === 'payment_updated') {
                    $row['what'] = 'Updated the payment on booking ' . $bref;
                } else {
                    $fields = rh_sa_field_names($r['changed_fields'] ?? null);
                    $row['what'] = 'Changed booking ' . $bref . ($fields ? ': ' . implode(', ', $fields) : ' (' . rh_sa_prettify($action) . ')');
                }
                $row['where'] = 'Bookings';
                if ((int)$r['booking_id'] > 0) {
                    $row['link'] = ['page' => 'booking-details.php', 'query' => 'id=' . (int)$r['booking_id']];
                }
                break;
            case 'payment':
            case 'refund':
                $row['type'] = 'payments';
                $verb = $src === 'refund' ? 'Recorded refund ' : 'Recorded payment ';
                $row['what'] = $verb . $money() . ' on ' . ($ref('booking_reference') ?? 'an account') . ($ref('payment_method') ? ' (' . rh_sa_prettify((string)$r['payment_method']) . ')' : '');
                $row['where'] = 'Payments';
                if ((string)($r['booking_type'] ?? '') === 'room' && (int)$r['booking_id'] > 0) {
                    $row['link'] = ['page' => 'booking-details.php', 'query' => 'id=' . (int)$r['booking_id']];
                }
                break;
            case 'credit_note':
                $row['type'] = 'payments';
                $row['what'] = 'Issued credit note ' . ($ref('credit_note_number') ?? '') . ' (' . $money() . ')';
                $row['where'] = 'Credit notes';
                $row['link'] = ['page' => 'credit-notes.php', 'query' => ''];
                break;
            case 'order':
                $row['type'] = 'till';
                $row['what'] = 'Started ' . rh_sa_prettify((string)$r['order_type']) . ' order ' . ($ref('reference') ?? '') . ' (' . $money() . ')';
                $row['where'] = 'Till';
                break;
            case 'order_paid':
                $row['type'] = 'till';
                $row['what'] = 'Took payment on order ' . ($ref('reference') ?? '') . ' (' . $money() . ($ref('payment_method') ? ', ' . rh_sa_prettify((string)$r['payment_method']) : '') . ')';
                $row['where'] = 'Till';
                break;
            case 'order_void':
                $row['type'] = 'till';
                $row['what'] = 'Voided order ' . ($ref('reference') ?? '') . ' (' . $money() . ')';
                $row['where'] = 'Till';
                break;
            case 'order_audit':
                $row['type'] = 'till';
                $ev = preg_match('/^[A-Za-z0-9_ -]{1,40}$/', (string)$r['event']) ? rh_sa_prettify((string)$r['event']) : 'event';
                $row['what'] = (int)($r['order_id'] ?? 0) > 0 ? 'Order #' . (int)$r['order_id'] . ': ' . $ev : $ev; // shift/float/86 rows have no order
                $row['where'] = 'Till';
                break;
            case 'shift_open':
                $row['type'] = 'till';
                $row['what'] = 'Opened shift with a float of ' . $money();
                $row['where'] = 'Till';
                break;
            case 'shift_close':
                $row['type'] = 'till';
                $row['what'] = 'Closed shift: expected ' . $money('expected') . ', declared ' . $money('declared');
                $row['where'] = 'Till';
                break;
            case 'offline':
                $row['type'] = 'till';
                $act = preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)$r['action']) ? rh_sa_prettify((string)$r['action']) : 'action';
                $row['what'] = 'Offline ' . $act . ' synced' . ((int)$r['response_status'] >= 300 ? ' (refused, code ' . (int)$r['response_status'] . ')' : '');
                $row['where'] = 'Offline queue';
                break;
            case 'kds':
                $row['type'] = 'kitchen';
                $to = (string)($r['to_status'] ?? '');
                $row['what'] = 'Order #' . (int)$r['order_id'] . ' ' . (preg_match('/^[a-z_]{1,30}$/', $to) && $to !== '' ? 'moved to ' . rh_sa_prettify($to) : rh_sa_prettify((string)$r['event']));
                $row['where'] = 'Kitchen/bar screen';
                break;
            case 'stock_adj':
                $row['type'] = 'stock';
                $why = trim((string)($r['reason'] ?? ''));
                $row['what'] = 'Adjusted stock' . ($why !== '' && preg_match('/^[A-Za-z0-9 _.,-]{1,60}$/', $why) ? ' (' . $why . ')' : '');
                $row['where'] = 'Stock';
                break;
            case 'stock_waste':
                $row['type'] = 'stock';
                $row['what'] = 'Recorded wastage costing ' . $money();
                $row['where'] = 'Stock';
                break;
            case 'stock_in':
                $row['type'] = 'stock';
                $row['what'] = 'Received stock worth ' . $money() . ($ref('supplier_name') ? ' from ' . $r['supplier_name'] : '');
                $row['where'] = 'Stock';
                break;
            case 'stock_count':
                $row['type'] = 'stock';
                $row['what'] = ((int)($r['is_opening'] ?? 0) ? 'Opening stock count ' : 'Stock count ') . ($ref('reference') ?? '') . ' (' . rh_sa_prettify((string)$r['status']) . ')';
                $row['where'] = 'Stock';
                break;
            case 'po':
                $row['type'] = 'stock';
                $row['what'] = 'Created purchase order ' . ($ref('reference') ?? '') . ' (' . $money() . ')';
                $row['where'] = 'Purchasing';
                $row['link'] = ['page' => 'purchase-orders.php', 'query' => ''];
                break;
            case 'event':
                $row['type'] = 'other';
                $row['what'] = 'Problem while using ' . rh_sa_prettify((string)$r['source']) . ': ' . trim((string)$r['message']);
                $row['where'] = 'System';
                break;
            case 'activity':
                $action = (string)$r['action'];
                if ($action === 'page_action') {
                    [$page] = rh_sa_parse_details((string)$r['details']);
                    $row['type'] = $page !== '' ? rh_sa_page_type($page) : 'other';
                    $row['what'] = rh_sa_page_action_text((string)$r['details']);
                    $row['where'] = $page !== '' ? rh_sa_page_title($page) : 'A screen';
                    $row['screen_save'] = true;
                    $pages = rh_sa_pages();
                    if ($page !== '' && !empty($pages[$page]['nav'])) {
                        $row['link'] = ['page' => $page, 'query' => ''];
                    }
                } elseif ($action === 'setting_change') {
                    // One row per setting that really changed on Hotel Settings; values cleaned and secrets masked when written.
                    $row['type'] = 'setting_changes';
                    $row['what'] = rh_settings_log_sentence((string)($r['details'] ?? ''));
                    $row['where'] = 'Hotel Settings';
                } else {
                    $row['type'] = rh_sa_action_type($action);
                    $row['what'] = rh_sa_named_action_text($action, (string)($r['details'] ?? ''));
                    $row['where'] = $types[$row['type']] ?? 'Other';
                }
                break;
        }
        return $row;
    }

    // ------------------------------------------------------------------ summary

    /**
     * One row per person (staff accounts only) per day. The page pages these cards (key 'sad'); $maxRows only guards
     * memory, so paging, not the cap, limits what can be reached. @return array{rows:array,limited:bool}
     */
    function rh_sa_summary(PDO $pdo, array $f, int $maxRows = 2000): array
    {
        [$a, $b] = rh_sa_bounds($f);
        $staff = rh_sa_staff($pdo);
        $people = []; // "day|uid" => row
        $touch = function (string $day, int $uid, string $first, string $last) use (&$people, $staff) {
            $k = $day . '|' . $uid;
            if (!isset($people[$k])) {
                $people[$k] = ['day' => $day, 'uid' => $uid, 'who' => $uid > 0 && isset($staff[$uid]) ? $staff[$uid]['name'] : 'User #' . $uid,
                    'first_in' => null, 'first_seen' => $first, 'last_seen' => $last, 'last_out' => null,
                    'counts' => array_fill_keys(array_keys(rh_sa_counters()), 0), 'payments_total' => 0.0, 'refunds_total' => 0.0];
            }
            if ($first < $people[$k]['first_seen']) {
                $people[$k]['first_seen'] = $first;
            }
            if ($last > $people[$k]['last_seen']) {
                $people[$k]['last_seen'] = $last;
            }
            return $k;
        };

        $selects = [];
        $args = [];
        foreach (rh_sa_active_sources($pdo, 'all') as $key => $s) {
            if (!empty($s['no_summary'])) {
                continue;
            }
            $money = !empty($s['money']) ? $s['money'] : '0';
            $q = "SELECT '{$key}' AS src, {$s['uid']} AS uid, DATE({$s['ts']}) AS d, COUNT(*) AS n, MIN({$s['ts']}) AS f, MAX({$s['ts']}) AS l, SUM({$money}) AS m"
                . " FROM {$s['table']} WHERE {$s['ts']} >= ? AND {$s['ts']} < ? AND {$s['uid']} > 0";
            $qa = [$a, $b];
            if ($f['user'] > 0) {
                $q .= " AND {$s['uid']} = ?";
                $qa[] = $f['user'];
            }
            if (!empty($s['where'])) {
                $q .= ' AND ' . $s['where'];
            }
            $q .= " GROUP BY {$s['uid']}, DATE({$s['ts']})";
            $selects[$key] = [$q, $qa];
        }
        $rows = [];
        try {
            $st = $pdo->prepare(implode(' UNION ALL ', array_map(function ($x) { return '(' . $x[0] . ')'; }, $selects)));
            foreach ($selects as $x) {
                $args = array_merge($args, $x[1]);
            }
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $rows = [];
            foreach ($selects as $x) {
                try {
                    $st = $pdo->prepare($x[0]);
                    $st->execute($x[1]);
                    $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
                } catch (Throwable $e2) {
                }
            }
        }
        $sources = rh_sa_sources();
        foreach ($rows as $r) {
            $s = $sources[$r['src']] ?? null;
            if (!$s) {
                continue;
            }
            $k = $touch((string)$r['d'], (int)$r['uid'], (string)$r['f'], (string)$r['l']);
            if ($s['counter']) {
                $people[$k]['counts'][$s['counter']] += (int)$r['n'];
                if ($s['counter'] === 'payments') {
                    $people[$k]['payments_total'] += (float)$r['m'];
                } elseif ($s['counter'] === 'refunds') {
                    $people[$k]['refunds_total'] += (float)$r['m'];
                }
            }
        }

        try {
            $sql = "SELECT user_id AS uid, DATE(created_at) AS d, action, IF(action = 'page_action', 1, 0) AS ps, COUNT(*) AS n, MIN(created_at) AS f, MAX(created_at) AS l"
                . " FROM admin_activity_log WHERE created_at >= ? AND created_at < ? AND user_id > 0";
            $qa = [$a, $b];
            if ($f['user'] > 0) {
                $sql .= ' AND user_id = ?';
                $qa[] = $f['user'];
            }
            $sql .= ' GROUP BY user_id, DATE(created_at), action';
            $st = $pdo->prepare($sql);
            $st->execute($qa);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = $touch((string)$r['d'], (int)$r['uid'], (string)$r['f'], (string)$r['l']);
                $act = (string)$r['action'];
                if ($act === 'login_success') {
                    $people[$k]['counts']['signins'] += (int)$r['n'];
                    if ($people[$k]['first_in'] === null || $r['f'] < $people[$k]['first_in']) {
                        $people[$k]['first_in'] = (string)$r['f'];
                    }
                } elseif ($act === 'logout') {
                    if ($people[$k]['last_out'] === null || $r['l'] > $people[$k]['last_out']) {
                        $people[$k]['last_out'] = (string)$r['l'];
                    }
                } elseif ($act === 'page_action') {
                    $people[$k]['counts']['saves'] += (int)$r['n'];
                } elseif ($act === 'setting_change') {
                    // Not counted: the same save is already one "Screen saves" (its page_action row on Hotel Settings).
                } else {
                    $type = rh_sa_action_type($act);
                    if (in_array($type, ['settings', 'stock', 'till'], true) && $act !== 'refund_created') {
                        $people[$k]['counts'][$type] += (int)$r['n'];
                    }
                }
            }
        } catch (Throwable $e) {
        }

        $out = array_values($people);
        usort($out, function ($x, $y) {
            return [$y['day'], strtolower($x['who'])] <=> [$x['day'], strtolower($y['who'])];
        });
        $limited = count($out) > $maxRows;
        return ['rows' => array_slice($out, 0, $maxRows), 'limited' => $limited];
    }

    // ------------------------------------------------------------------ retention note, CSV

    /** How far back each record goes: [label => earliest datetime or null]. Nothing is deleted by this page. */
    function rh_sa_retention(PDO $pdo): array
    {
        $labels = ['booking' => 'Booking History', 'payment' => 'Payments', 'order' => 'Orders', 'kds' => 'Kitchen/bar screen moves',
            'shift_close' => 'Shift closes', 'stock_adj' => 'Stock adjustments', 'stock_in' => 'Stock received', 'offline' => 'Offline sync'];
        $src = rh_sa_sources();
        $tables = rh_sa_tables($pdo);
        $out = [];
        foreach ($labels as $key => $label) {
            $s = $src[$key];
            if (!isset($tables[$s['table']])) {
                continue;
            }
            try {
                $out[$label] = $pdo->query("SELECT MIN({$s['ts']}) FROM {$s['table']}")->fetchColumn() ?: null;
            } catch (Throwable $e) {
            }
        }
        if (isset($tables['admin_activity_log'])) {
            try {
                $out['Sign-ins and screen saves'] = $pdo->query("SELECT MIN(created_at) FROM admin_activity_log")->fetchColumn() ?: null;
            } catch (Throwable $e) {
            }
        }
        return $out;
    }

    /** Make a cell safe to open in a spreadsheet (a leading = + - @ or control character would run as a formula). */
    function rh_sa_csv_cell($v): string
    {
        $s = str_replace(["\r", "\n"], ' ', (string)$v);
        if ($s !== '' && strpos("=+-@\t", $s[0]) !== false) {
            $s = "'" . $s;
        }
        return $s;
    }

    /** Write the filtered timeline as CSV to an open stream. Returns the number of rows written. */
    function rh_sa_write_csv(PDO $pdo, array $f, $out): int
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Time', 'Person', 'Type', 'What', 'Where']);
        $t = rh_sa_timeline($pdo, $f, 0, RH_SA_CSV_ROWS);
        $types = rh_sa_types();
        foreach ($t['rows'] as $r) {
            fputcsv($out, [rh_sa_csv_cell($r['ts']), rh_sa_csv_cell($r['who']), rh_sa_csv_cell($types[$r['type']] ?? 'Other'), rh_sa_csv_cell($r['what']), rh_sa_csv_cell($r['where'])]);
        }
        return count($t['rows']);
    }
}
