<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Dashboard "Needs attention" (owner 2026-11-12: accurate at 100%, each person sees exactly what they may see, any
 * business type). Rules: admin/includes/dashboard-attention.php.
 *
 * Part 1 (always, NO DATABASE): every item's permission + module gate, link and wording, a role matrix and every
 * business preset, popup gates and link stripping, and source checks on admin/dashboard.php.
 * Part 2 (--db, LIWONDE ONLY, read-only SELECTs): each count the dashboard reads equals the count of the list its
 * link opens, using the target page's own filter helpers. Refuses to run anywhere but the Liwonde test platform.
 *
 * Usage (repo root): php scripts/tests/dashboard_attention.php [--db]
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/module-presets.php';
require_once $root . '/admin/includes/dashboard-attention.php';
check(!isset($GLOBALS['pdo']), 'part 1 loads without the database');

$ALL = array_keys(rh_dash_attention_defs());
eq(count($ALL), 18, '18 Needs-attention items');

/* ---- simulated user: permissions, module switches, page access (mirrors rhCanOpenPage / rhCanLinkTo) ---- */
function sim_mods(array $modules, array $frontEnd): callable
{
    return static function (string $k) use ($modules, $frontEnd): bool {
        if ($k === 'events') return !empty($frontEnd['events_page']);
        if ($k === 'restaurant_page') return !empty($frontEnd['restaurant_page']);
        if ($k === 'advance_booking' || $k === 'billing') return !empty($modules['bookings']) || !empty($modules['conference']) || !empty($modules['gym']);
        return !empty($modules[$k]);
    };
}
function sim_perm(array $keys): callable
{
    $set = array_fill_keys($keys, true);
    return static function (string ...$want) use ($set): bool {
        foreach ($want as $w) { if (isset($set[$w])) return true; }
        return false;
    };
}
function sim_can(array $keys, callable $mod, bool $isAdminRole): callable
{
    $perm = sim_perm($keys);
    return static function (string $page) use ($perm, $mod, $isAdminRole): bool {
        $p = getPermissionForPage($page);
        if ($p !== null && !$perm($p)) return false;
        $m = getModuleForPage($page);
        if ($m !== null && !$isAdminRole) {
            foreach ((array)$m as $mk) { if (!$mod((string)$mk)) return false; }
        }
        return in_array($page, rhStationSidePages(), true) || $perm('dashboard'); // admin portal = the Dashboard here
    };
}
function role_keys(string $role, array $plus = [], array $minus = []): array
{
    $d = getDefaultPermissionsForRole($role);
    $keys = [];
    foreach ((array)$d as $k => $v) {
        if (is_int($k)) { $keys[] = (string)$v; } elseif ($v) { $keys[] = (string)$k; }
    }
    return array_values(array_diff(array_unique(array_merge($keys, $plus)), $minus));
}
function all_keys(): array { return array_keys(getAllPermissions()); }
function run_items(array $keys, array $modules, array $frontEnd, bool $isAdminRole = false): array
{
    $mod = sim_mods($modules, $frontEnd);
    $gates = rh_dash_attention_gates(sim_perm($keys), $mod);
    $items = rh_dash_attention_items($gates, [], sim_can($keys, $mod, $isAdminRole), !empty($frontEnd['restaurant_page']));
    $out = [];
    foreach ($items as $it) { $out[$it['key']] = $it; }
    return $out;
}
$presets = getBusinessPresets();
$full = $presets['full_hotel'];

/* ---- 1. every item: hint, icon, link to a real page; its gate = the permission + modules of that page ---- */
$dataKeys = [ // the permission(s) that let a person see each list (any one is enough)
    'overdue_arrivals' => ['bookings'], 'overdue_departures' => ['bookings'], 'pending_bookings' => ['bookings'], 'departures_owing' => ['bookings'],
    'refunds_pending' => ['payments', 'refund_payment'], 'pending_conference' => ['conference'], 'unread_contact' => ['contact'],
    'pending_reviews' => ['reviews'], 'pending_events' => ['events_bookings'], 'pending_gym' => ['gym'], 'gym_expiring' => ['gym'],
    'rooms_out_of_service' => ['room_maintenance'], 'housekeeping_due' => ['housekeeping'],
    'room_service_open' => ['room_service_view', 'stock_orders'], 'room_service_reminders' => ['room_service_view'],
    'open_tabs' => ['stock_orders'], 'expired_batches' => ['stock_batches'], 'low_stock' => ['stock_management'],
];
eq(array_keys($dataKeys), $ALL, 'every item has its data permission listed in this test');
$allPerms = all_keys();
$fe = ['restaurant_page' => 1, 'events_page' => 1];
foreach (rh_dash_attention_defs(true) as $key => $def) {
    check(trim((string)$def['hint']) !== '', "$key: plain hint saying what it counts");
    check(!preg_match('/wand|magic|robot|sparkle|brain/i', (string)$def['icon']), "$key: no AI-looking icon");
    $page = rh_dash_link_page((string)$def['href']);
    check(getPermissionForPage($page) !== null, "$key: links a permission-checked admin page ($page)");
    foreach ((array)$dataKeys[$key] as $k) check(isset(getAllPermissions()[$k]), "$key: '$k' is a real permission key");
    // Nothing less: holding only one data permission (and the Dashboard) with every module on shows it.
    foreach ((array)$dataKeys[$key] as $k) {
        $extra = $key === 'refunds_pending' && $k === 'refund_payment' ? ['accounting'] : [];
        check(isset(run_items(array_merge(['dashboard', $k], $extra), $full['modules'], $fe)[$key]), "$key: shown to a user with only '$k'");
    }
    // Nothing more: every permission except its data permission(s) hides it.
    check(!isset(run_items(array_values(array_diff($allPerms, $dataKeys[$key])), $full['modules'], $fe)[$key]), "$key: hidden without " . implode('/', $dataKeys[$key]));
    // The page's own permission is one of the data permissions, and an alternative needs every module the page needs.
    check(in_array(getPermissionForPage($page), $dataKeys[$key], true), "$key: page permission " . getPermissionForPage($page) . ' is a data permission');
    $pageMods = array_values(array_filter((array)(getModuleForPage($page) ?? []), static fn($m) => $m !== 'station_room_service'));
    $covered = false;
    foreach ($def['when'] as $alt) { if (!array_diff($pageMods, $alt['modules'])) { $covered = true; } }
    check($covered, "$key: needs every module its page needs (" . implode(',', $pageMods) . ')');
    // Each module it needs, switched off, hides it even for an administrator.
    foreach (array_unique(array_merge(...array_map(static fn($a) => $a['modules'], $def['when']))) as $m) {
        $mods = $full['modules']; $f2 = $fe;
        if ($m === 'events') { $f2['events_page'] = 0; } else { $mods[$m] = 0; }
        $stillAlt = false; // room service open: switching stock off leaves the room-service panel route
        foreach ($def['when'] as $alt) { if (!in_array($m, $alt['modules'], true)) $stillAlt = true; }
        if (!$stillAlt) check(!isset(run_items($allPerms, $mods, $f2, true)[$key]), "$key: hidden with module '$m' off (administrator)");
    }
}

/* ---- 2. role matrix (Full Hotel, all modules on): exactly these items, and these links ---- */
$bk4 = ['overdue_arrivals', 'overdue_departures', 'pending_bookings', 'departures_owing'];
$matrix = [
    'administrator' => [role_keys('admin'), true, $ALL, $ALL],
    'manager' => [role_keys('manager'), false, $ALL, $ALL],
    'receptionist' => [role_keys('receptionist'), false,
        array_merge($bk4, ['pending_reviews', 'pending_gym', 'gym_expiring', 'rooms_out_of_service', 'housekeeping_due', 'room_service_open', 'room_service_reminders']),
        array_merge($bk4, ['pending_reviews', 'pending_gym', 'gym_expiring', 'rooms_out_of_service', 'housekeeping_due', 'room_service_reminders'])],
    'accountant' => [role_keys('accountant'), false, array_merge($bk4, ['refunds_pending']), array_merge($bk4, ['refunds_pending'])],
    'viewer' => [role_keys('viewer'), false, $bk4, $bk4],
    'till staff given the Dashboard' => [role_keys('restaurant_staff', ['dashboard']), false,
        ['room_service_open', 'room_service_reminders', 'open_tabs'], ['room_service_open', 'room_service_reminders', 'open_tabs']],
    'till only + Dashboard' => [['dashboard', 'pos_till'], false, [], []],
    'housekeeping' => [role_keys('housekeeping'), false, ['rooms_out_of_service', 'housekeeping_due'], ['rooms_out_of_service', 'housekeeping_due']],
    'conference staff' => [role_keys('conference_staff'), false, ['pending_conference'], ['pending_conference']],
    'gym staff' => [role_keys('gym_staff'), false, ['pending_gym', 'gym_expiring'], ['pending_gym', 'gym_expiring']],
    'conference rooms only' => [['dashboard', 'conference_rooms'], false, [], []],
    'gym check-in only' => [['dashboard', 'gym_checkin'], false, [], []],
    'refunds without Payments' => [['dashboard', 'accounting', 'refund_payment'], false, ['refunds_pending'], []],
    'stock count only' => [['dashboard', 'stock_count'], false, [], []],
];
foreach ($matrix as $who => [$keys, $isAdmin, $wantItems, $wantLinks]) {
    $got = run_items($keys, $full['modules'], $fe, $isAdmin);
    $order = array_flip($ALL);
    $sort = static function (array $a) use ($order): array { usort($a, static fn($x, $y) => $order[$x] <=> $order[$y]); return $a; };
    eq(array_keys($got), $sort($wantItems), "$who: exactly these items");
    eq($sort(array_keys(array_filter($got, static fn($i) => $i['href'] !== ''))), $sort($wantLinks), "$who: exactly these links");
    foreach ($got as $k => $it) {
        if ($it['href'] !== '') check(sim_can($keys, sim_mods($full['modules'], $fe), $isAdmin)(rh_dash_link_page($it['href'])), "$who: $k link opens a page they can open");
    }
}
check(!sim_can(role_keys('restaurant_staff'), sim_mods($full['modules'], $fe), false)('dashboard.php'), 'till staff without the Dashboard permission never see this panel');
$refOnly = run_items(['dashboard', 'accounting', 'refund_payment'], $full['modules'], $fe);
check(isset($refOnly['refunds_pending']) && array_key_exists('insight', $refOnly['refunds_pending']) && $refOnly['refunds_pending']['insight'] === null,'refunds without Payments: number only, no popup of refund rows');
check((run_items(role_keys('accountant'), $full['modules'], $fe)['refunds_pending']['insight'] ?? null) === 'refunds_pending', 'accountant: refunds popup kept');

/* ---- 3. every business type: only its modules' items, wording that fits ---- */
$roomKeys = array_merge($bk4, ['rooms_out_of_service', 'housekeeping_due', 'room_service_open', 'room_service_reminders']);
$wantByPreset = [
    'full_hotel' => $ALL,
    'hotel_no_restaurant' => array_values(array_diff($ALL, ['room_service_open', 'room_service_reminders', 'open_tabs', 'expired_batches', 'low_stock'])),
    'bar_restaurant' => ['refunds_pending', 'open_tabs', 'expired_batches', 'low_stock'],
    'conference_venue' => array_merge($bk4, ['refunds_pending', 'pending_conference', 'unread_contact', 'pending_reviews', 'pending_events', 'rooms_out_of_service']),
    'gym_fitness' => ['refunds_pending', 'unread_contact', 'pending_reviews', 'pending_events', 'pending_gym', 'gym_expiring'],
    'retail_shop' => ['refunds_pending', 'open_tabs', 'expired_batches', 'low_stock'],
    'supermarket' => ['refunds_pending', 'unread_contact', 'pending_reviews', 'open_tabs', 'expired_batches', 'low_stock'],
];
foreach ($presets as $pk => $p) {
    $got = run_items($allPerms, $p['modules'], $p['front_end'], true);
    eq(array_keys($got), $wantByPreset[$pk] ?? ['?'], "{$p['label']}: exactly its modules' items (administrator)");
    if (empty($p['modules']['bookings'])) check(!array_intersect(array_keys($got), $roomKeys), "{$p['label']}: no room or booking item, no false 'clear' room chip");
}
eq(run_items($allPerms, $presets['bar_restaurant']['modules'], $presets['bar_restaurant']['front_end'], true)['open_tabs']['label'], 'Open tabs awaiting payment', 'bar: open tabs wording');
eq(run_items($allPerms, $presets['retail_shop']['modules'], $presets['retail_shop']['front_end'], true)['open_tabs']['label'], 'Orders awaiting payment', 'shop: orders wording');
eq(run_items($allPerms, $presets['supermarket']['modules'], $presets['supermarket']['front_end'], true)['open_tabs']['hint'], 'Orders placed and not paid yet', 'supermarket: plain hint without "tabs"');
// A module the user's business has but the page module switched off for a non-admin: plain text, never a dead link.
$mods = $full['modules']; $mods['station_room_service'] = 0;
eq(run_items(role_keys('receptionist'), $mods, $fe)['room_service_reminders']['href'] ?? 'missing', '', 'room-service screen off: reminders item is plain text for staff');

/* ---- 4. popups: header cards only with a row to show; links the user cannot open are taken out ---- */
$g = static fn(array $keys, ?array $m = null) => rh_dash_attention_gates(sim_perm($keys), sim_mods($m ?? $full['modules'], $fe));
check(!rh_dash_guest_services_gate($g(['dashboard', 'gym_checkin']), false), 'guest services popup: none for gym check-in only (enquiry count hidden)');
check(rh_dash_guest_services_gate($g(['dashboard', 'contact']), false), 'guest services popup: contact-only user gets it');
check(!rh_dash_operations_gate($g(['dashboard', 'stock_count']), false), 'operations popup: none for stock count only (open tabs money hidden)');
check(rh_dash_operations_gate($g(['dashboard']), true), 'operations popup: owed row alone is enough');
check(!rh_dash_operations_gate($g(['dashboard', 'pos_till', 'accounting']), false), 'operations popup: no empty card for till + accounting');
$canAcct = sim_can(['dashboard', 'accounting'], sim_mods($full['modules'], $fe), false);
$stripped = rh_dash_insight_strip_links([
    'link' => ['href' => 'payments.php?refund_status=pending', 'label' => 'Open'],
    'link2' => ['href' => 'accounting-dashboard.php', 'label' => 'Acct'],
    'rows' => [
        ['reference' => ['href' => 'booking-details.php?id=4', 'label' => 'RH-1'], 'action' => ['href' => 'payment-details.php?id=9', 'label' => 'Payment']],
        ['reference' => ['type' => 'details', 'summary' => 'x'], 'action' => ['href' => 'accounting-dashboard.php#a', 'label' => 'Go']],
    ],
], $canAcct);
eq([$stripped['link'], $stripped['link2']['href']], [null, 'accounting-dashboard.php'], 'popup buttons: denied link removed, allowed kept');
eq([$stripped['rows'][0]['reference'], $stripped['rows'][0]['action']], ['RH-1', ''], 'popup rows: denied cell link -> plain label, denied action -> blank');
eq($stripped['rows'][1]['action']['href'], 'accounting-dashboard.php#a', 'popup rows: allowed action kept (anchor ok)');
eq($stripped['rows'][1]['reference'], ['type' => 'details', 'summary' => 'x'], 'popup rows: non-link cells untouched');

/* ---- 5. order and totals ---- */
[$open, $clear, $total] = rh_dash_attention_split([
    ['key' => 'a', 'count' => 2, 'tone' => 'amber'], ['key' => 'b', 'count' => 1, 'tone' => 'red'],
    ['key' => 'c', 'count' => 0, 'tone' => 'red'], ['key' => 'd', 'count' => 5, 'tone' => 'amber'],
]);
eq([array_column($open, 'key'), array_column($clear, 'key'), $total], [['b', 'd', 'a'], ['c'], 8], 'red first, then biggest; zero = clear; total of open');

/* ---- 6. admin/dashboard.php uses the one rule everywhere ---- */
$d = (string)file_get_contents($root . '/admin/dashboard.php');
check(strpos($d, '$add($attn') === false, 'dashboard: no inline item list left');
check(strpos($d, "\$ledeText .= \$attnTotal > 0 ? ' — ' . \$attnTotal . ' thing'") !== false && strpos($d, 'count($attnOpen) . \' thing') === false, 'dashboard: greeting "N things need you" uses the badge total (they always match)');
check(strpos($d, '$attn = rh_dash_attention_items($attnGate, [') !== false && strpos($d, '], $can, isRestaurantEnabled());') !== false, 'dashboard: items from the helper, links through $can');
foreach ($ALL as $k) check(strpos($d, "'$k'") !== false, "dashboard: passes a count for $k");
foreach ([
    "'pending_bookings'          => \$attnGate['pending_bookings'],", "'pending_conference'        => \$attnGate['pending_conference'],",
    "'open_tabs'                 => \$dash_open_tabs,", "'room_service_reminders_due'=> \$attnGate['room_service_reminders'],",
    "'room_service_pending'      => \$attnGate['room_service_open'],", "'guest_services_queue'      => \$dash_guest_queue_on,",
    "'operations_facilities'     => \$dash_ops_queue_on,",
] as $s) check(strpos($d, $s) !== false, 'dashboard popup gate: ' . trim(explode('=>', $s)[0]));
check(strpos($d, '$payload = rh_dash_insight_strip_links($payload, $dash_can);') !== false, 'dashboard: every popup strips links the user cannot open');
check(strpos($d, '<?php if ($dash_guest_queue_on):') !== false && strpos($d, '<?php if ($dash_ops_queue_on): ?>') !== false, 'dashboard: header popup buttons follow the same gates');
check(strpos($d, "if (\$attnGate['rooms_out_of_service']) {\n            \$guestSvc['maintenance_open']") !== false, 'dashboard: rooms out of service counted whenever its item shows (bookings module)');
check(strpos($d, "if (\$mod_roomsvc || \$attnGate['room_service_open']) {") !== false, 'dashboard: room-service open counted for Orders & Tabs users too');
check(strpos($d, '<?php if ($dash_open_tabs): // the Orders & Tabs list') !== false && strpos($d, '<?php if ($dash_open_tabs && $mod_bookings): ?>') !== false, 'dashboard: open tabs tile + money line follow the popup rule');
check(strpos($d, "if (\$attnGate['pending_gym']) {") !== false && strpos($d, "if (\$dash_open_tabs) { // the open tabs list") !== false, 'dashboard: popup rows follow item gates (gym enquiries, open tabs)');

/* ---- Part 2: counts == the lists their links open (Liwonde only) ---- */
if (in_array('--db', $argv, true)) {
    $isLiwonde = stripos(str_replace('\\', '/', (string)realpath($root)), '/Liwonde_Sun_Hotel_2026') !== false;
    if (!$isLiwonde) {
        bad('DB part refused', 'runs only in the Liwonde test platform repo (Rosalyn\'s is live)');
    } else {
        require $root . '/config/database.php';
        require_once $root . '/includes/room-management.php';
        require_once $root . '/includes/conference-pricing.php';
        require_once $root . '/admin/includes/bookings-list-filters.php';
        require_once $root . '/admin/includes/finance-filters.php';
        require_once $root . '/admin/includes/events-gym-filters.php';
        require_once $root . '/admin/includes/stock-orders-filters.php';
        require_once $root . '/admin/includes/stock-list-filters.php';
        $site = (string)getSetting('site_name', '');
        if (stripos($site, 'liwonde') === false) {
            bad('DB part refused', "site_name '$site' is not Liwonde");
        } else {
            $n = static function (string $sql, array $p = []) use ($pdo): int { $st = $pdo->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); };
            $src = static function (string $s) use ($d): bool { return strpos($d, $s) !== false; };
            $bk = static fn(string $where, array $p = []) => $n("SELECT COUNT(*) FROM bookings b WHERE b.deleted_at IS NULL AND $where", $p);
            // Overdue arrivals: Room summary count vs Bookings ?arrival=overdue.
            eq((int)(getRoomDashboardSummary()['no_show_candidates'] ?? -1), $bk(rh_bk_arrival_clause('overdue')), 'DB overdue arrivals = Bookings ?arrival=overdue');
            // Departures: the dashboard list rule vs Bookings ?departure=overdue and Today's Check-outs + ?balance=owing.
            check($src("WHEN b.check_out_date < ? THEN 'overdue'") && $src("OR (b.check_out_date < ? AND b.status = 'checked-in'))"), 'dashboard departure list rule unchanged');
            eq($n("SELECT COUNT(*) FROM bookings b WHERE b.deleted_at IS NULL AND b.check_out_date < CURDATE() AND b.status = 'checked-in'"), $bk(rh_bk_departure_clause('overdue')), 'DB overdue departures = Bookings ?departure=overdue');
            $bal = rh_bk_balance_clause('owing');
            eq($n("SELECT COUNT(*) FROM bookings b WHERE b.deleted_at IS NULL AND b.check_out_date = CURDATE() AND b.status = 'checked-in' AND b.amount_due > ?", [rh_fd_balance_tolerance()]),
               $bk(implode(' AND ', rh_bk_tab_clauses('today-checkouts')['clauses']) . ' AND ' . $bal['clause'], $bal['params']), 'DB departures with a balance = Today\'s Check-outs + ?balance=owing');
            // Simple counts: the dashboard's own SQL (checked present) vs the target page's list rule.
            $pairs = [
                'pending bookings' => ["SELECT COUNT(*) as count FROM bookings WHERE deleted_at IS NULL AND status = 'pending'", 'SELECT COUNT(*) FROM bookings b WHERE b.deleted_at IS NULL AND b.status = ?', ['pending']],
                'refunds to approve' => ["SELECT COUNT(*) FROM payments WHERE payment_type='refund' AND refund_status IN ('pending','processing') AND deleted_at IS NULL", null, null],
                'conference enquiries' => ["SELECT COUNT(*) as count FROM conference_inquiries WHERE status = 'pending'", null, null],
                'contact messages' => ["SELECT COUNT(*) FROM contact_inquiries WHERE status='new'", 'SELECT COUNT(*) FROM contact_inquiries WHERE 1=1 AND status = ?', ['new']],
                'reviews' => ["SELECT COUNT(*) FROM reviews WHERE status='pending'", 'SELECT COUNT(*) FROM reviews r LEFT JOIN rooms rm ON r.room_id = rm.id WHERE 1=1 AND r.status = ?', ['pending']],
                'gym enquiries' => ["SELECT COUNT(*) FROM gym_inquiries WHERE status='new'", 'SELECT COUNT(*) FROM gym_inquiries WHERE 1=1 AND status = ?', ['new']],
                'event bookings' => ["SELECT COUNT(*) FROM event_inquiries WHERE status='pending'", 'SELECT COUNT(*) FROM event_inquiries ei LEFT JOIN events e ON e.id = ei.event_id WHERE 1=1 AND ei.status = ?', ['pending']],
                'expired batches' => ["SELECT COUNT(*) FROM stock_batches WHERE status='active' AND quantity_remaining > 0 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()", 'SELECT COUNT(*) FROM stock_batches b WHERE ' . rh_stock_batch_expired_sql('b'), []],
            ];
            $pf = rh_fin_payments_where(['refund_status' => 'pending,processing'], 'transaction_id', []);
            $pairs['refunds to approve'][1] = 'SELECT COUNT(*) ' . rh_fin_payments_from() . ' ' . $pf['sql'];
            $pairs['refunds to approve'][2] = $pf['params'];
            $cf = rh_conf_list_filter(['status' => 'pending'], rh_conf_has_end_date($pdo));
            $pairs['conference enquiries'][1] = 'SELECT COUNT(*) FROM conference_inquiries ci LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id WHERE ' . implode(' AND ', $cf['clauses']);
            $pairs['conference enquiries'][2] = $cf['params'];
            foreach ($pairs as $label => [$dashSql, $listSql, $listParams]) {
                if (!check($src($dashSql), "dashboard still counts $label with: $dashSql")) continue;
                try { eq($n($dashSql), $n($listSql, $listParams), "DB $label = its list"); }
                catch (Throwable $e) { check(stripos($e->getMessage(), "doesn't exist") !== false, "DB $label", $e->getMessage()); }
            }
            // Orders & Tabs: open tabs (?status=placed) and room service (the chip's href), via the page's own filters.
            $so = static function (string $query) use ($n): int {
                parse_str((string)parse_url($query, PHP_URL_QUERY), $q);
                [$c, $p] = rh_so_list_where(rh_so_normalize_filters($q), '', '');
                return $n('SELECT COUNT(*) FROM stock_orders o' . ($c ? ' WHERE ' . implode(' AND ', $c) : ''), $p);
            };
            check($src("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) v FROM stock_orders WHERE status='placed'"), 'dashboard open tabs count unchanged');
            eq($n("SELECT COUNT(*) FROM stock_orders WHERE status='placed'"), $so('stock-orders.php?status=placed'), 'DB open tabs = Orders & Tabs ?status=placed');
            check($src("SELECT COUNT(*) FROM stock_orders WHERE order_type='room_service' AND \" . rh_fd_status_in_sql('status', rh_fd_room_service_open_statuses())"), 'dashboard room-service open count unchanged');
            eq($n("SELECT COUNT(*) FROM stock_orders WHERE order_type='room_service' AND " . rh_fd_status_in_sql('status', rh_fd_room_service_open_statuses())), $so(rh_fd_room_service_open_href()), 'DB room-service open = Orders & Tabs room-service link');
            // Housekeeping due: same SQL rule as housekeeping.php ?due=now (alias form).
            eq($n('SELECT COUNT(*) FROM housekeeping_assignments WHERE ' . rh_fd_housekeeping_due_sql()), $n('SELECT COUNT(*) FROM housekeeping_assignments ha LEFT JOIN individual_rooms ir ON ha.individual_room_id = ir.id WHERE ' . rh_fd_housekeeping_due_sql('ha')), 'DB housekeeping due = housekeeping.php ?due=now');
            // Gym expiring, rooms out of service, low stock: the page and the dashboard call the same helper.
            $gm = (string)file_get_contents($root . '/admin/gym-members.php');
            check(strpos($gm, 'rh_gym_member_filter_where()') !== false && $src("\$gmWhere['expiring']"), 'gym expiring: dashboard and Gym Members share rh_gym_member_filter_where');
            $rm = (string)file_get_contents($root . '/admin/room-maintenance.php');
            check(strpos($rm, 'rh_room_units_count(rh_fd_out_of_service_statuses())') !== false && $src('rh_room_units_count(rh_fd_out_of_service_statuses())'), 'rooms out of service: dashboard and the page notice share one count');
            $si = (string)file_get_contents($root . '/admin/stock-ingredients.php');
            check(strpos($si, "exactly the items the low-stock alert counts (rh_stock_alert_summary 'attention')") !== false && $src("\$stock['low_stock']        = \$stockAlerts['attention'];"), 'low stock: dashboard and ?filter=low share the alert rule');
            echo "DB part ran read-only on '$site'.\n";
        }
    }
} else {
    echo "DB part skipped (add --db, Liwonde only).\n";
}

echo "dashboard_attention: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
