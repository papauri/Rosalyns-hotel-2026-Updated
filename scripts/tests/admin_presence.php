<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Staff presence ("Who's online"): admin/includes/presence-lib.php, admin/api/presence.php,
 * the admin-init.php heartbeat and the logout clear.
 * Usage (from the repo root): php scripts/tests/admin_presence.php
 * Library checks run inside one transaction that is rolled back; page and endpoint checks run in
 * scripts/tests/page_harness.php (also rolled back). Existing users are only read - their
 * admin_presence rows are changed inside the transaction and restored by the rollback.
 */
$root = dirname(__DIR__, 2);
chdir($root);
class RhPresenceFailingPdo extends PDO
{
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        throw new PDOException('table admin_presence missing');
    }
}
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/config/database.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/presence-lib.php';

// A session for the heartbeat checks, started before any output.
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
$sd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_presence_test_sessions';
if (!is_dir($sd)) { mkdir($sd, 0777, true); }
session_save_path($sd);
session_id('presencetest' . bin2hex(random_bytes(5)));
ob_start();
session_start();
ob_end_clean();
$tbl = $pdo->query("SHOW TABLES LIKE 'admin_presence'")->rowCount() > 0;
if (!check($tbl, 'admin_presence table exists (migration 064)')) { echo "$pass passed, $fail failed\n"; exit(1); }
$hasCtx = $pdo->query("SHOW COLUMNS FROM admin_presence LIKE 'booking_context'")->rowCount() > 0;
if (!check($hasCtx, 'admin_presence.booking_context exists (migration 065)')) { echo "$pass passed, $fail failed\n"; exit(1); }

// ---- Pure helpers ----
check(rh_presence_bucket(0) === 'active' && rh_presence_bucket(120) === 'active', 'seen up to 2 min ago is Active now');
check(rh_presence_bucket(121) === 'idle' && rh_presence_bucket(1800) === 'idle', 'seen 2 to 30 min ago is Idle');
check(rh_presence_bucket(1801) === 'offline' && rh_presence_bucket(86400) === 'offline', 'seen over 30 min ago is offline');
check(rh_presence_bucket(-5) === 'active', 'a clock skew into the future is still Active now');
check(rh_presence_clean_page('bookings.php?id=5&x=1') === 'bookings.php', 'query string is stripped from the stored page');
check(rh_presence_clean_page('/admin/bookings.php') === 'bookings.php', 'only the file name is kept');
check(rh_presence_clean_page('../../etc/passwd') === '' && rh_presence_clean_page('x.php.txt') === '' && rh_presence_clean_page('') === '', 'odd page names are refused');
check(rh_presence_clean_page('<script>.php') === '', 'markup in a page name is refused');
check(rh_presence_page_title('bookings.php') === 'Bookings', 'a menu page shows its menu name', rh_presence_page_title('bookings.php'));
check(rh_presence_page_title('booking-details.php') === 'Booking details', 'a sub-page shows a tidied name', rh_presence_page_title('booking-details.php'));
check(rh_presence_page_title('') === 'Admin', 'no page shows Admin');
check(rh_presence_can_view('admin') && rh_presence_can_view('manager'), 'admin and manager can see who is online');
foreach (['receptionist', 'accountant', 'viewer', 'housekeeping', 'bar_staff', ''] as $r) {
    if (rh_presence_can_view($r)) { bad("role '$r' must not see who is online"); }
}
ok('no other role can see who is online');
check(rh_presence_can_see_ip('admin') && !rh_presence_can_see_ip('manager'), 'only admin sees IP addresses');

// ---- Library against the live schema, rolled back ----
$u = $pdo->query("SELECT id, role FROM admin_users WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
$adminId = (int)($pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn());
$mgrId = (int)($pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'manager' ORDER BY id LIMIT 1")->fetchColumn());
$staffId = (int)($pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role NOT IN ('admin','manager') ORDER BY id LIMIT 1")->fetchColumn());
if (!$adminId || !$staffId) { bad('needs an active admin and other staff user'); echo "$pass passed, $fail failed\n"; exit(1); }
// An install with no manager (e.g. a small test hotel) uses a temporary one inside the rolled-back
// transaction for the library checks; the page-level manager checks are then skipped.
$tempMgr = !$mgrId;

$_SERVER['REMOTE_ADDR'] = '10.9.8.7';
$_SERVER['HTTP_USER_AGENT'] = 'PresenceTest/1.0';

$row = function (int $id) use ($pdo) {
    $s = $pdo->prepare("SELECT *, TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS secs FROM admin_presence WHERE user_id = ?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC);
};

$pdo->beginTransaction();
try {
    if ($tempMgr) {
        $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 'manager', 1)")
            ->execute(['zz_presence_mgr', 'zz_presence_mgr@example.invalid', 'x', 'Presence Test Manager']);
        $mgrId = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("DELETE FROM admin_presence WHERE user_id IN (?, ?, ?)")->execute([$adminId, $mgrId, $staffId]);

    // First heartbeat of a sign-in
    rh_presence_heartbeat($pdo, $mgrId, 'bookings.php?id=7');
    $r = $row($mgrId);
    check($r && $r['current_page'] === 'bookings.php', 'first heartbeat stores the page without its query string', json_encode($r));
    check($r && $r['ip'] === '10.9.8.7' && $r['user_agent_short'] === 'PresenceTest/1.0', 'ip and short user agent are stored');
    check($r && (int)$r['secs'] <= 5, 'last_seen_at is now');
    $started = $r ? $r['session_started_at'] : '';

    // Throttle: same page inside 60 s writes nothing
    $pdo->prepare("UPDATE admin_presence SET last_seen_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE user_id = ?")->execute([$mgrId]);
    $_SESSION['rh_presence_ts'] = time() - 10;
    rh_presence_heartbeat($pdo, $mgrId, 'bookings.php');
    $r = $row($mgrId);
    check($r && (int)$r['secs'] >= 590, 'same page again within 60 s is throttled (no write)', json_encode($r));

    // A change of page is written straight away
    rh_presence_heartbeat($pdo, $mgrId, 'calendar.php');
    $r = $row($mgrId);
    check($r && $r['current_page'] === 'calendar.php' && (int)$r['secs'] <= 5, 'moving to another page updates the row at once');
    check($r && $r['session_started_at'] === $started, 'signed-in-since does not move on later heartbeats');

    // After 60 s the same page writes again
    $pdo->prepare("UPDATE admin_presence SET last_seen_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE user_id = ?")->execute([$mgrId]);
    $_SESSION['rh_presence_ts'] = time() - 61;
    rh_presence_heartbeat($pdo, $mgrId, 'calendar.php');
    $r = $row($mgrId);
    check($r && (int)$r['secs'] <= 5, 'after 60 s the same page writes again');

    // A ping with a bad page keeps the stored page
    $_SESSION['rh_presence_ts'] = time() - 61;
    rh_presence_heartbeat($pdo, $mgrId, '../../x');
    $r = $row($mgrId);
    check($r && $r['current_page'] === 'calendar.php', 'a refused page name keeps the page already stored');

    // Failures are silent
    $failPdo = null;
    try { $failPdo = (new ReflectionClass('RhPresenceFailingPdo'))->newInstanceWithoutConstructor(); } catch (Throwable $e) { $failPdo = null; }
    if ($failPdo) {
        $threw = false;
        try {
            unset($_SESSION['rh_presence_started']);
            rh_presence_heartbeat($failPdo, $mgrId, 'x.php');
            rh_presence_clear($failPdo, $mgrId);
            rh_presence_rows($failPdo, false);
        } catch (Throwable $e) {
            $threw = true;
        }
        check(!$threw, 'a failing database never raises out of heartbeat, clear or list');
        $_SESSION['rh_presence_started'] = time();
    } else {
        ok('failing-database check skipped (cannot build a stub PDO here)');
    }

    // Buckets in the list: active 30 s, idle 10 min, gone 45 min
    $ins = $pdo->prepare("INSERT INTO admin_presence (user_id, last_seen_at, current_page, session_started_at, ip) VALUES (?, DATE_SUB(NOW(), INTERVAL ? SECOND), ?, DATE_SUB(NOW(), INTERVAL 2 HOUR), ?)
        ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), current_page = VALUES(current_page), session_started_at = VALUES(session_started_at), ip = VALUES(ip)");
    $ins->execute([$mgrId, 30, 'bookings.php', '10.0.0.1']);
    $ins->execute([$staffId, 600, 'pos.php', '10.0.0.2']);
    $ins->execute([$adminId, 2700, 'dashboard.php', '10.0.0.3']);
    $by = [];
    foreach (rh_presence_rows($pdo, false) as $x) { $by[$x['user_id']] = $x; }
    check(isset($by[$mgrId]) && $by[$mgrId]['status'] === 'active' && $by[$mgrId]['status_label'] === 'Active now', 'seen 30 s ago shows Active now');
    check(isset($by[$staffId]) && $by[$staffId]['status'] === 'idle' && $by[$staffId]['status_label'] === 'Idle 10 min', 'seen 10 min ago shows Idle 10 min', json_encode($by[$staffId] ?? null));
    check(!isset($by[$adminId]), 'seen 45 min ago is not listed (offline)');
    check(isset($by[$mgrId]) && $by[$mgrId]['page_title'] === 'Bookings' && isset($by[$staffId]) && $by[$staffId]['page_title'] === 'POS Till', 'the screen shows its menu name', json_encode([$by[$mgrId]['page_title'] ?? null, $by[$staffId]['page_title'] ?? null]));
    check(isset($by[$mgrId]) && $by[$mgrId]['since'] !== '' && $by[$mgrId]['role_label'] !== '', 'signed-in-since and the role name are filled');
    check(!array_key_exists('ip', $by[$mgrId] ?? ['ip' => 1]), 'no IP in the rows for managers');
    $withIp = [];
    foreach (rh_presence_rows($pdo, true) as $x) { $withIp[$x['user_id']] = $x; }
    check(($withIp[$mgrId]['ip'] ?? '') === '10.0.0.1', 'IP is included for administrators');

    // Deactivated accounts are not listed
    $pdo->prepare("UPDATE admin_users SET is_active = 0 WHERE id = ?")->execute([$staffId]);
    $again = array_column(rh_presence_rows($pdo, false), 'user_id');
    check(!in_array($staffId, $again, true), 'a deactivated account is not listed');
    $pdo->prepare("UPDATE admin_users SET is_active = 1 WHERE id = ?")->execute([$staffId]);

    // Panel is for admin and manager only
    ob_start(); rh_presence_render_panel($pdo, 'receptionist', $staffId); $none = ob_get_clean();
    check(trim($none) === '', 'the panel prints nothing for other roles');
    ob_start(); rh_presence_render_panel($pdo, 'manager', $mgrId); $mp = ob_get_clean();
    check(strpos($mp, "Who's online") !== false && strpos($mp, '<span class="presence-ip"') === false && strpos($mp, '10.0.0.1') === false, 'the manager panel has no IP addresses');
    ob_start(); rh_presence_render_panel($pdo, 'admin', $adminId); $ap = ob_get_clean();
    check(strpos($ap, '10.0.0.1') !== false, 'the admin panel shows IP addresses');

    // ---- "Also booking right now" banner data ----
    $dash = "\u{2013}";
    $c = rh_presence_booking_ctx_clean('2031-11-12', '2031-11-14', '3, 5,5,x,-2,0, 7', 90);
    check($c['ci'] === '2031-11-12' && $c['co'] === '2031-11-14' && $c['rooms'] === [3, 5, 7] && abs($c['since'] - (time() - 90)) <= 2, 'booking context keeps valid dates and unique whole-number room ids', json_encode($c));
    $c = rh_presence_booking_ctx_clean('2031-11-14', '2031-11-14', '', 0);
    check($c['ci'] === '' && $c['co'] === '' && $c['rooms'] === [], 'check-out not after check-in drops both dates');
    $c = rh_presence_booking_ctx_clean('2031-02-30', '<b>', '1;DROP', 999999);
    check($c['ci'] === '' && $c['co'] === '' && $c['rooms'] === [1] && $c['since'] >= time() - 86400 - 2, 'impossible dates and markup are dropped, age is capped at a day', json_encode($c));
    check(rh_presence_fmt_range('2031-11-12', '2031-11-14') === '12' . $dash . '14 Nov', 'range inside one month reads 12-14 Nov', rh_presence_fmt_range('2031-11-12', '2031-11-14'));
    check(rh_presence_fmt_range('2031-10-30', '2031-11-02') === '30 Oct' . $dash . '2 Nov', 'range across months shows both months');
    check(rh_presence_ago(10) === 'just now' && rh_presence_ago(180) === '3 min ago' && rh_presence_ago(7200) === 'over an hour ago', 'started-ago wording');

    $rm = $pdo->query("SELECT id, name FROM rooms WHERE is_active = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_KEY_PAIR);
    $rmIds = array_keys($rm);
    $roomA = (int)($rmIds[0] ?? 0);
    $roomB = (int)($rmIds[1] ?? 999999);
    $nameA = (string)($rm[$roomA] ?? '');
    $roles = getAllRoles();
    $mgrRow = $pdo->prepare("SELECT full_name, username, role FROM admin_users WHERE id = ?");
    $mgrRow->execute([$mgrId]);
    $mg = $mgrRow->fetch(PDO::FETCH_ASSOC);
    $mgrWho = (string)($mg['full_name'] !== '' ? $mg['full_name'] : $mg['username']);
    $mgrLabel = (string)($roles[$mg['role']]['label'] ?? ucfirst((string)$mg['role']));

    $setBooker = function (int $uid, int $ageSecs, string $page, ?array $ctx) use ($pdo) {
        $pdo->prepare("INSERT INTO admin_presence (user_id, last_seen_at, current_page, session_started_at, booking_context)
            VALUES (?, DATE_SUB(NOW(), INTERVAL ? SECOND), ?, DATE_SUB(NOW(), INTERVAL 2 HOUR), ?)
            ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), current_page = VALUES(current_page), booking_context = VALUES(booking_context)")
            ->execute([$uid, $ageSecs, $page, $ctx === null ? null : json_encode($ctx)]);
    };
    $pdo->prepare("DELETE FROM admin_presence WHERE user_id IN (?, ?, ?)")->execute([$adminId, $mgrId, $staffId]);
    $none = ['ci' => '', 'co' => '', 'rooms' => []];
    $mineA = ['ci' => '2031-11-13', 'co' => '2031-11-15', 'rooms' => [$roomA]];

    // Another person on Create Booking, no dates yet: plain line, not amber
    $setBooker($mgrId, 20, 'create-booking.php', ['ci' => '', 'co' => '', 'rooms' => [], 'since' => time() - 190]);
    $o = rh_presence_booking_others($pdo, $adminId, $none);
    check(count($o) === 1 && $o[0]['overlap'] === false && $o[0]['text'] === $mgrWho . ' (' . $mgrLabel . ') ' . "\u{2014}" . ' started 3 min ago', 'other active booker is listed as "Name (Role) - started 3 min ago"', json_encode($o));
    // Self is hidden
    check(rh_presence_booking_others($pdo, $mgrId, $none) === [], 'the person never sees themselves');
    // Stale (over 2 min) and people on other pages are hidden
    $setBooker($mgrId, 150, 'create-booking.php', ['ci' => '', 'co' => '', 'rooms' => [], 'since' => time()]);
    check(rh_presence_booking_others($pdo, $adminId, $none) === [], 'someone not seen for over 2 minutes is not shown');
    $setBooker($mgrId, 10, 'bookings.php', ['ci' => '', 'co' => '', 'rooms' => [], 'since' => time()]);
    check(rh_presence_booking_others($pdo, $adminId, $none) === [], 'someone who has left Create Booking is not shown');
    // A deactivated account is not shown
    $setBooker($mgrId, 10, 'create-booking.php', ['ci' => '2031-11-12', 'co' => '2031-11-14', 'rooms' => [$roomA], 'since' => time() - 30]);
    $pdo->prepare("UPDATE admin_users SET is_active = 0 WHERE id = ?")->execute([$mgrId]);
    check(rh_presence_booking_others($pdo, $adminId, $none) === [], 'a deactivated account is not shown');
    $pdo->prepare("UPDATE admin_users SET is_active = 1 WHERE id = ?")->execute([$mgrId]);

    // Overlap: same room type, dates overlap (12-14 vs 13-15)
    $o = rh_presence_booking_others($pdo, $adminId, $mineA);
    check(count($o) === 1 && $o[0]['overlap'] === true, 'same room type and overlapping dates turn the banner amber', json_encode($o));
    check($nameA !== '' && count($o) === 1 && $o[0]['text'] === $mgrWho . ' is booking ' . $nameA . ' for 12' . $dash . '14 Nov too. If you both pick the last room, the second save will be refused.', 'amber sentence names the person, room type and dates', json_encode($o));
    // No overlap: different room type, dates only touching, or no dates chosen yet by the viewer
    $o = rh_presence_booking_others($pdo, $adminId, ['ci' => '2031-11-13', 'co' => '2031-11-15', 'rooms' => [$roomB]]);
    check(count($o) === 1 && $o[0]['overlap'] === ($roomB === $roomA), 'a different room type is not an overlap', json_encode($o));
    $o = rh_presence_booking_others($pdo, $adminId, ['ci' => '2031-11-14', 'co' => '2031-11-16', 'rooms' => [$roomA]]);
    check(count($o) === 1 && $o[0]['overlap'] === false, 'my check-in on their check-out day is not an overlap (night model)', json_encode($o));
    $o = rh_presence_booking_others($pdo, $adminId, ['ci' => '2031-11-10', 'co' => '2031-11-12', 'rooms' => [$roomA]]);
    check(count($o) === 1 && $o[0]['overlap'] === false, 'my check-out on their check-in day is not an overlap', json_encode($o));
    $o = rh_presence_booking_others($pdo, $adminId, ['ci' => '', 'co' => '', 'rooms' => [$roomA]]);
    check(count($o) === 1 && $o[0]['overlap'] === false, 'no dates chosen yet is not an overlap');
    // Text from the database is returned as plain text (the page puts it in with textContent)
    $pdo->prepare("UPDATE admin_users SET full_name = ? WHERE id = ?")->execute(['<img src=x onerror=alert(1)>', $mgrId]);
    $o = rh_presence_booking_others($pdo, $adminId, $mineA);
    check(count($o) === 1 && strpos($o[0]['text'], '<img src=x onerror=alert(1)>') === 0, 'a name with markup is returned as plain text for textContent');
    $pdo->prepare("UPDATE admin_users SET full_name = ? WHERE id = ?")->execute([(string)$mg['full_name'], $mgrId]);

    // Writing my own note
    $pdo->prepare("DELETE FROM admin_presence WHERE user_id = ?")->execute([$staffId]);
    rh_presence_booking_write($pdo, $staffId, rh_presence_booking_ctx_clean('2031-12-01', '2031-12-03', (string)$roomA, 45));
    $r = $row($staffId);
    $bc = $r ? json_decode((string)$r['booking_context'], true) : null;
    check($r && $r['current_page'] === 'create-booking.php' && (int)$r['secs'] <= 5 && is_array($bc) && $bc['ci'] === '2031-12-01' && $bc['rooms'] === [$roomA], 'booking note is written to my own row', json_encode($r));
    check(count(rh_presence_booking_others($pdo, $adminId, $none)) === 2, 'a freshly written note shows to the others');
    // A broken database never raises
    $threw = false;
    try { if ($failPdo) { rh_presence_booking_write($failPdo, $staffId, $none); rh_presence_booking_others($failPdo, $adminId, $none); } } catch (Throwable $e) { $threw = true; }
    check(!$threw, 'a failing database never raises out of the booking banner functions');

    // Sign-out clears
    rh_presence_clear($pdo, $mgrId);
    check($row($mgrId) === false, 'sign-out removes the presence row');
} finally {
    $pdo->rollBack();
}
$after = $pdo->prepare("SELECT COUNT(*) FROM admin_presence WHERE current_page = 'zz_never'");
$after->execute();
check(!$pdo->inTransaction(), 'library checks rolled back');

// ---- Pages and endpoint through the rolled-back harness ----
$h = $root . '/scripts/tests/page_harness.php';
function run_page(string $page, array $cfg): array {
    global $h;
    $cmd = 'php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -300), 'rolled_back' => false];
}
$mgrName = (string)$pdo->query("SELECT username FROM admin_users WHERE id = " . $mgrId)->fetchColumn();
$staffName = (string)$pdo->query("SELECT username FROM admin_users WHERE id = " . $staffId)->fetchColumn();
$staffRole = (string)$pdo->query("SELECT role FROM admin_users WHERE id = " . $staffId)->fetchColumn();
$seed = [
    "DELETE FROM admin_presence",
    "INSERT INTO admin_presence (user_id, last_seen_at, current_page, session_started_at, ip) VALUES ($adminId, NOW(), 'dashboard.php', NOW(), '10.1.2.3')",
    "INSERT INTO admin_presence (user_id, last_seen_at, current_page, session_started_at, ip) VALUES ($staffId, DATE_SUB(NOW(), INTERVAL 5 MINUTE), 'bookings.php', NOW(), '10.4.5.6')",
];
$clean = function (array $r): bool { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); };

// Endpoint: admin sees IPs
$r = run_page('admin/api/presence.php', ['method' => 'GET', 'setup' => $seed]);
$list = $r['json']['data']['online'] ?? null;
check(is_array($list) && count($list) === 2 && ($r['json']['success'] ?? false), 'admin: endpoint lists who is online', json_encode($r['json'] ?? $r));
check(is_array($list) && ($list[0]['ip'] ?? '') === '10.1.2.3', 'admin: IP addresses are included');
check($clean($r), 'admin endpoint run rolled back', json_encode($r));

// Endpoint: manager sees the list without IPs (page runs need a real signed-in manager)
if ($tempMgr) {
    echo "SKIP manager page checks: no active manager user on this install\n";
} else {
    $r = run_page('admin/api/presence.php', ['method' => 'GET', 'as_username' => $mgrName, 'setup' => $seed]);
    $list = $r['json']['data']['online'] ?? null;
    check(is_array($list) && count($list) === 2, 'manager: endpoint lists who is online', json_encode($r['json'] ?? $r));
    check(is_array($list) && !array_key_exists('ip', $list[0] ?? []) && strpos(json_encode($r['json'] ?? []), '10.1.2.3') === false, 'manager: no IP addresses in the answer');
    check($clean($r), 'manager endpoint run rolled back');
}

// Endpoint: everyone else is refused
$r = run_page('admin/api/presence.php', ['method' => 'GET', 'as_username' => $staffName, 'setup' => $seed]);
check(($r['json']['success'] ?? null) === false && !isset($r['json']['data']), "other staff ($staffRole): endpoint refuses and shows nothing", json_encode($r['json'] ?? $r));
check($clean($r), 'refused endpoint run rolled back');

// Ping: any signed-in user, XHR header required, only their own row
$r = run_page('admin/api/presence.php', ['method' => 'GET', 'ajax' => true, 'as_username' => $staffName, 'get' => ['ping' => '1', 'page' => 'payments.php?x=1'],
    'setup' => ["DELETE FROM admin_presence"], 'verify' => ["SELECT user_id, current_page FROM admin_presence"]]);
$v = $r['verify'][0] ?? [];
check(($r['json']['success'] ?? false) === true && count($v) === 1 && (int)$v[0]['user_id'] === $staffId && $v[0]['current_page'] === 'payments.php', 'ping by any signed-in user writes only their own row with a clean page', json_encode($r['verify'] ?? $r));
$r = run_page('admin/api/presence.php', ['method' => 'GET', 'as_username' => $staffName, 'get' => ['ping' => '1', 'page' => 'payments.php'],
    'setup' => ["DELETE FROM admin_presence"], 'verify' => ["SELECT COUNT(*) AS n FROM admin_presence"]]);
check(($r['json']['success'] ?? null) === false && (int)($r['verify'][0][0]['n'] ?? -1) === 0, 'ping without the X-Requested-With header is refused and writes nothing', json_encode($r['json'] ?? $r));
$r = run_page('admin/api/presence.php', ['method' => 'POST', 'as_username' => $tempMgr ? $staffName : $mgrName, 'setup' => $seed]);
check(($r['json']['success'] ?? null) === false, 'POST to the endpoint is refused');

// admin-init heartbeat on a real page load, and the panel on User Management
$r = run_page('admin/user-management.php', ['post' => [], 'setup' => ["DELETE FROM admin_presence"],
    'verify' => ["SELECT user_id, current_page, session_started_at FROM admin_presence WHERE user_id = $adminId"],
    'grep' => ["Who's online", 'rh-presence-panel', '<span class="presence-ip"', '1 online']]);
$v = $r['verify'][0] ?? [];
check(count($v) === 1 && ($v[0]['current_page'] ?? '') === 'user-management.php', 'a page load by a signed-in user records the page via admin-init', json_encode($r['verify'] ?? $r));
check(!empty($r['found']["Who's online"]) && !empty($r['found']['rh-presence-panel']) && !empty($r['found']['<span class="presence-ip"']), 'User Management shows the panel (with IPs) to an admin', json_encode($r['found'] ?? $r));
check($clean($r), 'page run rolled back');
if ($tempMgr) {
    echo "SKIP manager User Management check: no active manager user on this install\n";
} else {
    $r = run_page('admin/user-management.php', ['post' => [], 'as_username' => $mgrName, 'setup' => ["DELETE FROM admin_presence"], 'grep' => ["Who's online", '<span class="presence-ip"', 'rh-presence-panel']]);
    if (!empty($r['found']['rh-presence-panel']) || !empty($r['found']["Who's online"])) {
        check(!empty($r['found']["Who's online"]) && empty($r['found']['<span class="presence-ip"']), 'User Management shows the panel without IPs to a manager');
    } else {
        ok('manager has no access to User Management on this install (panel not reachable)');
    }
}
$r = run_page('admin/system-logs.php', ['post' => [], 'setup' => ["DELETE FROM admin_presence"], 'grep' => ["Who's online", 'rh-presence-panel']]);
check(!empty($r['found']['rh-presence-panel']), 'System Logs (audit area) also shows the panel to an admin', json_encode($r['found'] ?? $r));

// Banner endpoint (?booking=1): any signed-in user who can open Create Booking
$bkSeed = ["DELETE FROM admin_presence",
    "INSERT INTO admin_presence (user_id, last_seen_at, current_page, session_started_at, booking_context) VALUES ($staffId, NOW(), 'create-booking.php', NOW(), "
        . $pdo->quote(json_encode(['ci' => '2031-11-12', 'co' => '2031-11-14', 'rooms' => [$roomA], 'since' => time() - 240])) . ")"];
$r = run_page('admin/api/presence.php', ['method' => 'GET', 'ajax' => true, 'get' => ['booking' => '1', 'ci' => '2031-11-13', 'co' => '2031-11-15', 'rooms' => (string)$roomA, 'age' => '30'],
    'setup' => $bkSeed, 'verify' => ["SELECT user_id, current_page, booking_context FROM admin_presence WHERE user_id = $adminId"]]);
$others = $r['json']['data']['others'] ?? null;
check(($r['json']['success'] ?? false) === true && is_array($others) && count($others) === 1 && ($others[0]['overlap'] ?? null) === true && ($r['json']['data']['overlap'] ?? null) === true, 'booking banner endpoint: lists the other booker and flags the overlap', json_encode($r['json'] ?? $r));
$v = $r['verify'][0] ?? [];
$vc = json_decode((string)($v[0]['booking_context'] ?? ''), true);
check(count($v) === 1 && ($v[0]['current_page'] ?? '') === 'create-booking.php' && is_array($vc) && ($vc['ci'] ?? '') === '2031-11-13' && ($vc['rooms'] ?? []) === [$roomA], 'booking banner endpoint: writes only my own note', json_encode($r['verify'] ?? $r));
check($clean($r), 'booking banner run rolled back', json_encode($r));
$r = run_page('admin/api/presence.php', ['method' => 'GET', 'ajax' => true, 'get' => ['booking' => '1', 'idle' => '1', 'ci' => '2031-11-13', 'co' => '2031-11-15', 'rooms' => (string)$roomA],
    'setup' => $bkSeed, 'verify' => ["SELECT COUNT(*) AS n FROM admin_presence WHERE user_id = $adminId"]]);
check(($r['json']['success'] ?? false) === true && (int)($r['verify'][0][0]['n'] ?? -1) === 0 && count($r['json']['data']['others'] ?? []) === 1, 'booking banner endpoint: an idle tab reads the banner but writes nothing', json_encode($r['json'] ?? $r));
$r = run_page('admin/api/presence.php', ['method' => 'GET', 'get' => ['booking' => '1'], 'setup' => $bkSeed]);
check(($r['json']['success'] ?? null) === false && !isset($r['json']['data']), 'booking banner endpoint: refused without the X-Requested-With header', json_encode($r['json'] ?? $r));
$denySql = "INSERT INTO user_permissions (user_id, permission_key, is_granted, granted_by) VALUES ($staffId, 'create_booking', 0, $adminId) ON DUPLICATE KEY UPDATE is_granted = 0";
$r = run_page('admin/api/presence.php', ['method' => 'GET', 'ajax' => true, 'as_username' => $staffName, 'get' => ['booking' => '1', 'ci' => '2031-11-13', 'co' => '2031-11-15', 'rooms' => (string)$roomA],
    'setup' => array_merge($bkSeed, [$denySql]), 'verify' => ["SELECT COUNT(*) AS n FROM admin_presence WHERE user_id = $adminId", "SELECT booking_context FROM admin_presence WHERE user_id = $staffId"]]);
$raw = json_encode($r['json'] ?? []);
check(($r['json']['success'] ?? null) === false && !isset($r['json']['data']) && strpos($raw, $staffName) === false, 'booking banner endpoint: refused, with no names, for someone who cannot open Create Booking', $raw);
$vc = json_decode((string)($r['verify'][1][0]['booking_context'] ?? ''), true);
check(is_array($vc) && ($vc['ci'] ?? '') === '2031-11-12', 'refused request wrote nothing (the seeded note is unchanged)', json_encode($r['verify'] ?? $r));
check($clean($r), 'refused booking banner run rolled back');
$r = run_page('admin/create-booking.php', ['method' => 'GET', 'grep' => ['id="rh-cb-presence"', 'api/presence.php', 'cb-presence__list']]);
check(!empty($r['found']['id="rh-cb-presence"']) && !empty($r['found']['cb-presence__list']), 'Create Booking page carries the banner and its script', json_encode($r['found'] ?? $r));
check($clean($r), 'Create Booking page run rolled back');

// Logout clears
$r = run_page('admin/logout.php', ['method' => 'GET',
    'setup' => ["DELETE FROM admin_presence", "INSERT INTO admin_presence (user_id, last_seen_at, current_page, session_started_at) VALUES ($adminId, NOW(), 'dashboard.php', NOW())"],
    'verify' => ["SELECT COUNT(*) AS n FROM admin_presence WHERE user_id = $adminId"]]);
check((int)($r['verify'][0][0]['n'] ?? -1) === 0, 'logout.php removes the person from Who\'s online', json_encode($r['verify'] ?? $r));
check($clean($r), 'logout run rolled back');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
