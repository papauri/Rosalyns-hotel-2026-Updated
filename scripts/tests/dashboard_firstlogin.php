<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Dashboard first-login information (plan .claude/DASHBOARD_FIRSTLOGIN_PLAN.md, Phase A: A2 last sign-in, A3 the
 * line in the hero, A4 the day status chip; B2 since line; C2-C4 handover notes, mark read, welcome card).
 * Part 1 (no database): admin/includes/dashboard-firstlogin.php wording.
 * Part 2 (--db, LIWONDE ONLY): the dashboard rendered through scripts/tests/page_harness.php (one transaction, always
 * rolled back) with seeded sign-in rows and End of Day sends.
 * Usage (repo root): php scripts/tests/dashboard_firstlogin.php [--db]
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
/** A real connection on which the three tables of migration 091 do not exist (how Rosalyn's runs until 091 is run there). */
class Zz091MissingTablePdo extends PDO
{
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        return parent::prepare(str_replace(['shift_handover_notes', 'shift_handover_reads', 'admin_user_preferences'], ['zz_no_such_shn', 'zz_no_such_shr', 'zz_no_such_aup'], (string)$query), $options);
    }
}

require_once $root . '/admin/includes/dashboard-firstlogin.php';
check(!isset($GLOBALS['pdo']) && !function_exists('getSetting'), 'the wording helpers load without the database or settings');

/* ---- Part 1: last sign-in text ---- */
$chromeWin = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
$android = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
$today = '2026-11-24';
$t = fn(?array $p) => rh_dash_last_signin_text($p, $today);
check($t(null) === 'First sign-in on this account.', 'no earlier sign-in (null) -> first sign-in', $t(null));
check($t([]) === 'First sign-in on this account.', 'no earlier sign-in ([]) -> first sign-in');
check($t(['at' => '', 'ua' => $chromeWin]) === 'First sign-in on this account.', 'empty time -> first sign-in, never a made-up date');
check($t(['at' => '2026-11-24 07:05:00', 'ua' => $chromeWin]) === 'Last signed in today at 07:05, Chrome on Windows.', 'today', $t(['at' => '2026-11-24 07:05:00', 'ua' => $chromeWin]));
check($t(['at' => '2026-11-23 18:40:00', 'ua' => $chromeWin]) === 'Last signed in yesterday at 18:40, Chrome on Windows.', 'yesterday', $t(['at' => '2026-11-23 18:40:00', 'ua' => $chromeWin]));
check($t(['at' => '2026-11-23 00:00:00', 'ua' => $chromeWin]) === 'Last signed in yesterday at 00:00, Chrome on Windows.', 'yesterday at midnight (start of day is inclusive)');
check($t(['at' => '2026-11-22 23:59:59', 'ua' => $android]) === 'Last signed in Sun 22 Nov at 23:59, Chrome on Android.', 'older date names the day; Android phone is not "Linux"', $t(['at' => '2026-11-22 23:59:59', 'ua' => $android]));
check($t(['at' => '2025-12-31 09:00:00', 'ua' => $chromeWin]) === 'Last signed in Wed 31 Dec 2025 at 09:00, Chrome on Windows.', 'another year adds the year', $t(['at' => '2025-12-31 09:00:00', 'ua' => $chromeWin]));
check(rh_dash_last_signin_text(['at' => '2026-12-31 23:30:00', 'ua' => $chromeWin], '2027-01-01') === 'Last signed in yesterday at 23:30, Chrome on Windows.', 'yesterday across a year end');
check($t(['at' => '2026-11-20 10:00:00', 'ua' => '']) === 'Last signed in Fri 20 Nov at 10:00, Unknown browser.', 'unknown user agent -> "Unknown browser", never blank', $t(['at' => '2026-11-20 10:00:00', 'ua' => '']));
check($t(['at' => '2026-11-20 10:00:00', 'ua' => 'curl/8.0 (Linux)']) === 'Last signed in Fri 20 Nov at 10:00, Unknown browser on Linux.', 'unknown browser on a known system');
check(rh_dash_device_words('<script>alert(1)</script>') === 'Unknown browser', 'a user agent is never echoed, only fixed words');

/* ---- Part 1: day status chip ---- */
$c = fn(array $s) => rh_dash_eod_chip($s, $today);
$sent = $c(['day' => '2026-11-23', 'sent_at' => '2026-11-24 07:12:41', 'test' => false]);
check($sent === ['state' => 'ok', 'text' => 'Yesterday closed · End of Day sent 07:12', 'href' => null], 'sent yesterday', json_encode($sent));
$late = $c(['day' => '2026-11-23', 'sent_at' => '2026-11-23 23:00:00', 'test' => false]);
check($late['text'] === 'Yesterday closed · End of Day sent 23:00', 'sent the same evening', $late['text']);
$test = $c(['day' => '2026-11-23', 'sent_at' => '2026-11-24 07:12:00', 'test' => true]);
check($test['text'] === 'Yesterday closed · End of Day sent 07:12 (test)' && $test['state'] === 'ok', 'a test-mode send counts, marked (test)', $test['text']);
$not = $c(['day' => '2026-11-23', 'sent_at' => null, 'test' => false]);
check($not === ['state' => 'warn', 'text' => "Yesterday's End of Day not run", 'href' => 'end-of-day-report.php?date=2026-11-23'], 'not sent -> warning linking to that day\'s report', json_encode($not));
$two = $c(['day' => '2026-11-22', 'sent_at' => '2026-11-23 06:30:00', 'test' => false]);
check($two['text'] === 'Sun 22 Nov closed · End of Day sent 06:30', 'day two back (before opening) is named, not "Yesterday"', $two['text']);
$twoNot = $c(['day' => '2026-11-22', 'sent_at' => null, 'test' => false]);
check($twoNot['text'] === "Sun 22 Nov's End of Day not run" && $twoNot['href'] === 'end-of-day-report.php?date=2026-11-22', 'day two back, not sent', json_encode($twoNot));
check(rh_dash_eod_chip(['day' => '2026-12-31', 'sent_at' => null], '2027-01-01')['text'] === "Yesterday's End of Day not run", 'yesterday across a year end');
check($c(['day' => 'garbage', 'sent_at' => null])['href'] === 'end-of-day-report.php', 'a bad day never builds a link with it');
check($c(['day' => '2026-11-23', 'sent_at' => '', 'test' => true])['state'] === 'warn', 'an empty send time is not a send');

/* ---- Part 1: "Since you were last here" line (B2) ---- */
$sinceT = '2026-11-23 18:40:00';
$moneyH = static fn($v): string => '<span class="ck-cur">MWK</span>' . number_format((float)$v, 2); // the dashboard's $money shape (HTML)
$canAll = static fn(string $p): bool => true;
$canNone = static fn(string $p): bool => false;
$canBk = static fn(string $p): bool => $p === 'bookings.php';
$tail4 = ' — 4 things need you.';
$full = ['booked' => 3, 'cancelled' => 1, 'moved' => 2, 'payments' => 4, 'received' => '420000.00'];
$L = rh_dash_since_line($full, $sinceT, $moneyH, $canAll, $tail4);
$enc = htmlspecialchars('since=' . urlencode($sinceT), ENT_QUOTES);
check($L === 'Since you were last here: '
    . '<a class="ck-hero__since-link" href="bookings.php?' . $enc . '&amp;since_kind=booked">3 new bookings</a> &middot; '
    . '<a class="ck-hero__since-link" href="bookings.php?' . $enc . '&amp;since_kind=cancelled">1 cancelled</a> &middot; '
    . '<a class="ck-hero__since-link" href="payments.php?status=received&amp;' . $enc . '"><span class="ck-cur">MWK</span>420,000.00 received</a> &middot; '
    . '<a class="ck-hero__since-link" href="bookings.php?' . $enc . '&amp;since_kind=moved">2 room moves</a> — 4 things need you.',
    'since line: approved order, links carry the same since, money HTML kept as is (never escaped twice)', $L);
check(strpos($L, '&lt;span') === false && substr_count($L, '<span class="ck-cur">') === 1, 'since line: $money output not passed through htmlspecialchars');
$one = rh_dash_since_line(['booked' => 1, 'cancelled' => 0, 'moved' => 1, 'payments' => 0, 'received' => '0'], $sinceT, $moneyH, $canAll, ' — nothing needs you right now.');
check(strpos($one, '>1 new booking</a>') !== false && strpos($one, '>1 room move</a>') !== false && strpos($one, 'cancelled') === false && strpos($one, 'received') === false
    && substr_count($one, '&middot;') === 1, 'since line: singulars; zero items left out', $one);
$zero = rh_dash_since_line(['booked' => 0, 'cancelled' => 0, 'moved' => 0, 'payments' => 0, 'received' => '0'], $sinceT, $moneyH, $canAll, $tail4);
check($zero === 'Nothing new since you were last here — 4 things need you.', 'since line: all zero -> "Nothing new since you were last here" + the tail', $zero);
$noPay = rh_dash_since_line(['booked' => 2, 'cancelled' => 0, 'moved' => 0, 'payments' => null, 'received' => null], $sinceT, $moneyH, $canAll, $tail4);
check(strpos($noPay, 'received') === false && strpos($noPay, 'payments.php') === false && strpos($noPay, 'MWK') === false && strpos($noPay, '2 new bookings') !== false, 'since line: no money item when the payments gate is off', $noPay);
$noBk = rh_dash_since_line(['booked' => null, 'cancelled' => null, 'moved' => null, 'payments' => 1, 'received' => '5000'], $sinceT, $moneyH, $canAll, $tail4);
check(strpos($noBk, 'bookings.php') === false && strpos($noBk, '5,000.00 received') !== false, 'since line: bookings items off without the bookings gate', $noBk);
check(rh_dash_since_line(['booked' => null, 'cancelled' => null, 'moved' => null, 'payments' => null, 'received' => null], $sinceT, $moneyH, $canAll, $tail4) === '', 'since line: nothing this user may see -> "" (today\'s lede stays)');
$noLink = rh_dash_since_line($full, $sinceT, $moneyH, $canNone, $tail4);
check(strpos($noLink, '<a ') === false && strpos($noLink, '3 new bookings · ') === false && strpos($noLink, '3 new bookings &middot; 1 cancelled') !== false, 'since line: no link when $can is false (plain text)', $noLink);
$someLink = rh_dash_since_line($full, $sinceT, $moneyH, $canBk, $tail4);
check(strpos($someLink, 'href="bookings.php?') !== false && strpos($someLink, 'payments.php') === false && strpos($someLink, '420,000.00 received') !== false, 'since line: Payments link only for users who can open it', $someLink);
$xss = rh_dash_since_line(['booked' => 1, 'cancelled' => 0, 'moved' => 0, 'payments' => 0, 'received' => '0'], '2026-11-23 18:40:00"><script>', $moneyH, $canAll, ' — <b>x</b>');
check(strpos($xss, '<script>') === false && strpos($xss, '<b>') === false && strpos($xss, '&lt;b&gt;') !== false, 'since line: the since value and the tail are escaped', $xss);

/* ---- Part 1: handover note text (C2) ---- */
$cb = 'rh_dash_handover_clean_body';
check($cb('') === null && $cb("  \n\t ") === null, 'note: empty or blank after trim -> refused');
check($cb(str_repeat('a', 500)) === str_repeat('a', 500), 'note: exactly 500 characters accepted (inclusive end)');
check($cb(str_repeat('a', 501)) === null, 'note: 501 characters refused');
check($cb('  ' . str_repeat('a', 500) . "  \n") === str_repeat('a', 500), 'note: the limit is counted after the trim');
check($cb(str_repeat('é', 500)) === str_repeat('é', 500) && $cb(str_repeat('é', 501)) === null, 'note: the limit counts characters, not bytes (500 x "é" = 1000 bytes)');
check($cb("Room 12\r\nlate check-out\rok") === "Room 12\nlate check-out\nok", 'note: line ends made "\n"');
check($cb("a\x00b\x07c\x1Bd\te") === "abcd\te", 'note: control characters removed, tab kept');
check($cb("bad \xC3\x28 utf8") === null, 'note: invalid UTF-8 refused');
check($cb('<script>alert(1)</script>') === '<script>alert(1)</script>', 'note: stored as typed (escaped on output, not on input)');
$fr = 'rh_dash_handover_from_text';
check($fr('Grace Banda', '2026-11-24 22:10:00', $today) === 'From Grace Banda, 22:10', 'note from: today -> time only', $fr('Grace Banda', '2026-11-24 22:10:00', $today));
check($fr('Grace Banda', '2026-11-23 22:10:00', $today, 1) === 'From Grace Banda, yesterday 22:10 · and 1 more', 'note from: yesterday + "and 1 more"', $fr('Grace Banda', '2026-11-23 22:10:00', $today, 1));
check($fr('Grace', '2026-11-22 23:59:00', $today, 3) === 'From Grace, Sun 22 Nov 23:59 · and 3 more', 'note from: older day named', $fr('Grace', '2026-11-22 23:59:00', $today, 3));
check($fr('  ', '', $today) === 'From a colleague', 'note from: no name and no time never print blanks');

/* ---- Part 1: welcome card pages + guide links (C4) ---- */
$wp = static fn(array $pages): array => array_column($pages, 'label');
$canRecep = static fn(string $p): bool => in_array($p, ['bookings.php', 'calendar.php', 'room-dashboard.php', 'housekeeping.php', 'payments.php'], true);
check($wp(rh_dash_welcome_pages('receptionist', $canRecep)) === ['Bookings', 'Calendar', 'Rooms'], 'welcome: receptionist with bookings + calendar -> Bookings, Calendar, Rooms', json_encode(rh_dash_welcome_pages('receptionist', $canRecep)));
$canNoCal = static fn(string $p): bool => $p !== 'calendar.php';
$noCal = rh_dash_welcome_pages('receptionist', $canNoCal);
check(!in_array('calendar.php', array_column($noCal, 'page'), true) && $wp($noCal) === ['Bookings', 'Rooms', 'Housekeeping'], 'welcome: no calendar access -> never Calendar', json_encode($noCal));
check(rh_dash_welcome_pages('chef', $canAll) === [], 'welcome: a station role -> no pages (card shows the role only)');
check(rh_dash_welcome_pages('receptionist', $canNone) === [], 'welcome: nothing openable -> empty list');
$maxed = true;
foreach (['admin', 'manager', 'receptionist', 'housekeeping', 'accountant', 'viewer', 'gym_staff', 'conference_staff', 'custom_role_x'] as $rr) {
    $maxed = $maxed && count(rh_dash_welcome_pages($rr, $canAll)) <= 3;
}
check($maxed && count(rh_dash_welcome_pages('admin', $canAll)) === 3, 'welcome: never more than 3 pages');
check($wp(rh_dash_welcome_pages('custom_role_x', $canBk)) === ['Bookings'], 'welcome: a custom role gets the general list, still filtered');
$gOff = rh_dash_guide_links([]);
check(array_column($gOff, 'label') === ['All guides', 'Admin reference'], 'guides: everything off -> the two general guides only', json_encode($gOff));
$gOn = rh_dash_guide_links(array_fill_keys(['bookings', 'pos', 'kds', 'bds', 'cds', 'room_service', 'housekeeping', 'stock', 'finance', 'reports'], true));
check(count($gOn) === 12 && end($gOn)['label'] === 'Reports & EOD' && $gOn[2]['href'] === '../docs/guides/07-reception-bookings.html', 'guides: everything on -> all 12 in menu order, plain-text labels');
$gSome = rh_dash_guide_links(['bookings' => true, 'reports' => false, 'stock' => true]);
check(array_column($gSome, 'label') === ['All guides', 'Admin reference', 'Reception', 'Stock'], 'guides: only the switched-on ones');

/* ---- Part 1: a missing table is recognised (migration 091 not run yet) ---- */
require_once $root . '/admin/includes/dashboard-since-data.php';
$missing = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'x.shift_handover_notes' doesn't exist");
$missing->errorInfo = ['42S02', 1146, "Table 'x.shift_handover_notes' doesn't exist"];
check(rh_dash_table_missing($missing), 'missing table (42S02 / 1146) recognised');
check(!rh_dash_table_missing(new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')) && !rh_dash_table_missing(new RuntimeException('x')), 'other faults are not "missing table" (they are still logged)');

/* ---- Part 2: the dashboard (Liwonde only, rolled back) ---- */
if (in_array('--db', $argv, true)) {
    $isLiwonde = stripos(str_replace('\\', '/', (string)realpath($root)), '/Liwonde_Sun_Hotel_2026') !== false;
    if (!$isLiwonde) {
        bad('DB part refused', 'runs only in the Liwonde test platform repo (Rosalyn\'s is live)');
    } else {
        require $root . '/config/database.php';
        $site = (string)getSetting('site_name', '');
        if (stripos($site, 'liwonde') === false) {
            bad('DB part refused', "site_name '$site' is not Liwonde");
        } else {
            $h = $root . '/scripts/tests/page_harness.php';
            $run = function (array $cfg) use ($h): array {
                $cmd = 'php ' . escapeshellarg($h) . ' ' . escapeshellarg('admin/dashboard.php') . ' ' . escapeshellarg(base64_encode(json_encode($cfg + ['method' => 'GET']))) . ' 2>&1';
                $out = shell_exec($cmd);
                if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
                if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
                return ['error' => 'no result', 'raw' => substr((string)$out, -400), 'rolled_back' => false];
            };
            $clean = fn(array $r): bool => !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']);
            $f = fn(array $r, string $needle): bool => !empty($r['found'][$needle]);

            // The harness signs in as the first active admin; the seeded rows are that user's.
            $adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
            $q = fn($v) => $pdo->quote((string)$v);
            $signin = fn(string $at, string $ua) => "INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, user_agent, created_at) VALUES ($adminId, 'zz_dfl', 'login_success', 'zz seed', '203.0.113.7', " . $q($ua) . ', ' . $q($at) . ')';
            $firefoxLinux = 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0';
            $older = $signin('2026-03-02 18:41:00', $chromeWin);
            $newer = $signin('2026-03-03 09:17:00', $firefoxLinux);
            $olderText = 'at 18:41, Chrome on Windows.';
            $newerText = 'at 09:17, Firefox on Linux.';
            $grepS = [$olderText, $newerText, 'Mon 2 Mar', 'First sign-in on this account.', 'Not you? <a href="change-password.php">Change your password</a>', 'class="ck-hero__signin"'];

            // A3: no anchor in the session (signed in before the deploy / dev login) -> the second-newest sign-in
            $r = $run(['setup' => [$older, $newer], 'grep' => $grepS]);
            check($clean($r), 'sign-in line: run rolled back', json_encode($r));
            check($f($r, $olderText) && $f($r, 'Mon 2 Mar') && !$f($r, $newerText), 'no anchor: shows the sign-in before the newest (older row\'s time, Chrome on Windows)', json_encode($r['found'] ?? $r));
            check($f($r, 'Not you? <a href="change-password.php">Change your password</a>'), 'the "Not you? Change your password" link is there');

            // A2: with the anchor, the newest sign-in BEFORE this session's row is shown
            $r = $run(['setup' => [$older, $newer], 'session' => ['admin_login_log_id' => 2147483647], 'grep' => $grepS]);
            check($clean($r) && $f($r, $newerText) && !$f($r, $olderText), 'anchor: shows the newest sign-in with a lower id than this session\'s row', json_encode($r['found'] ?? $r));

            // A3: nothing before this session's row -> first sign-in, link still there
            $r = $run(['setup' => [$older], 'session' => ['admin_login_log_id' => 1], 'grep' => $grepS]);
            check($clean($r) && $f($r, 'First sign-in on this account.') && !$f($r, $olderText) && $f($r, 'Not you? <a href="change-password.php">Change your password</a>'),
                'one row only, nothing before the anchor -> "First sign-in on this account." with the link', json_encode($r['found'] ?? $r));

            // B2: "Since you were last here" in the hero. The previous sign-in is 60 s ago (SQL time, so PHP and MySQL zones
            // never differ); seeded audit rows test the room-move rule (spec-check: JSON null old room = first assignment).
            $bkIds = $pdo->query("SELECT id FROM bookings WHERE deleted_at IS NULL ORDER BY id LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
            if (check(count($bkIds) === 4, 'four bookings to hang seeded audit rows on')) {
                $signinAgo = "INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, user_agent, created_at) VALUES ($adminId, 'zz_dfl', 'login_success', 'zz seed', '203.0.113.7', " . $q($chromeWin) . ', NOW() - INTERVAL 60 SECOND)';
                $signinNow = "INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, user_agent, created_at) VALUES ($adminId, 'zz_dfl', 'login_success', 'zz seed', '203.0.113.7', " . $q($chromeWin) . ', NOW())';
                $audit = fn(int $id, string $action, string $old, string $new) => "INSERT INTO booking_audit_log (booking_id, action, old_values, new_values, note, performed_at) VALUES ($id, " . $q($action) . ', ' . $q($old) . ', ' . $q($new) . ", 'zz seed', NOW())";
                [$b1, $b2, $b3, $b4] = array_map('intval', $bkIds);
                $nullOld = $audit($b1, 'room_assigned', '{"individual_room_id": null}', '{"individual_room_id": 15}');
                $moves = [
                    $nullOld,                                                                                   // first assignment: not a move
                    $audit($b2, 'room_assigned', '{"individual_room_id": "12"}', '{"individual_room_id": 15}'), // "12" -> 15: a move
                    $audit($b3, 'modified', '{"individual_room_id": "12"}', '{"individual_room_id": 12}'),      // "12" = 12: not a move
                    $audit($b3, 'modified', '{"individual_room_id": 0}', '{"individual_room_id": 7}'),          // old 0: not a move
                    $audit($b3, 'modified', '{"guest_name": "A"}', '{"guest_name": "B"}'),                      // no room in the edit
                    $audit($b4, 'upgraded', '{"room_id": 1}', '{"room_id": 2}'),                                // type upgrade: a move
                ];
                $grepB = ['class="ck-hero__lede ck-hero__since"', 'Since you were last here: ', 'Nothing new since you were last here', '2 room moves', 'room move', 'since_kind=moved', '&lt;span', 'class="ck-hero__lede"'];
                $r = $run(['setup' => array_merge([$signinAgo, $signinNow], $moves), 'grep' => $grepB]);
                check($clean($r) && $f($r, 'class="ck-hero__lede ck-hero__since"') && $f($r, '2 room moves') && $f($r, 'since_kind=moved') && !$f($r, '&lt;span'),
                    'since line: 2 room moves (reassignment "12" -> 15 and an upgrade; null old room, same room, old 0 and a non-room edit not counted), linked', json_encode($r['found'] ?? $r));
                $r = $run(['setup' => [$signinAgo, $signinNow, $nullOld], 'grep' => $grepB]);
                check($clean($r) && !$f($r, 'room move') && $f($r, 'class="ck-hero__lede ck-hero__since"'), 'since line: a first assignment (JSON null old room) alone is not a move', json_encode($r['found'] ?? $r));
                // No sign-in before this session's row -> today's lede as before, no since line.
                $r = $run(['setup' => [$signinAgo], 'session' => ['admin_login_log_id' => 1], 'grep' => $grepB]);
                check($clean($r) && $f($r, 'class="ck-hero__lede"') && !$f($r, 'ck-hero__since') && !$f($r, 'Since you were last here') && !$f($r, 'Nothing new since you were last here'),
                    'no earlier sign-in -> today\'s lede stays', json_encode($r['found'] ?? $r));
            }

            // A4: End of Day chip. D = the last ended trading day, computed the same way as the dashboard.
            require_once $root . '/admin/includes/pos-shift-totals.php';
            $D = rh_pos_last_ended_trading_date(new DateTimeImmutable('now'));
            $Dn = (int)str_replace('-', '', $D);
            $next = (new DateTimeImmutable($D))->modify('+1 day')->format('Y-m-d');
            // Clear whatever real sends this day already has (inside the rolled-back transaction only).
            $reset = [
                "DELETE FROM automated_email_log WHERE job = 'daily_reports' AND account_type = 'trading_day' AND account_id = $Dn",
                "DELETE FROM system_event_log WHERE source = 'eod-report' AND context_json LIKE " . $q('%"date":"' . $D . '"%'),
            ];
            $auto = fn(string $stage, string $status, string $at) => "INSERT INTO automated_email_log (job, account_type, account_id, stage, recipient, subject, status, sent_at, updated_at) VALUES ('daily_reports', 'trading_day', $Dn, " . $q($stage) . ", 'zz@example.invalid', 'zz seed', " . $q($status) . ', ' . $q($at) . ', ' . $q($at) . ')';
            $event = fn(bool $success, bool $testMode, string $at) => "INSERT INTO system_event_log (source, level, message, context_json, created_at) VALUES ('eod-report', 'info', 'EOD email send', "
                . $q(json_encode(['date' => $D, 'recipients' => ['zz@example.invalid'], 'cc' => [], 'test_mode' => $testMode, 'success' => $success], JSON_UNESCAPED_SLASHES)) . ', ' . $q($at) . ')';
            $grepE = ['End of Day sent 06:50', 'End of Day sent 07:12', 'End of Day sent 07:12 (test)', 'End of Day not run', 'end-of-day-report.php?date=' . $D, 'class="ck-daychip'];

            $r = $run(['setup' => array_merge($reset, [$auto('day:' . $D, 'sent', "$next 07:12:00"), $event(true, false, "$next 06:50:00")]), 'grep' => $grepE]);
            check($clean($r) && $f($r, 'End of Day sent 06:50') && !$f($r, 'End of Day sent 07:12') && !$f($r, 'End of Day not run'),
                "chip: timed send 07:12 + manual send 06:50 for $D -> the earlier (06:50)", json_encode($r['found'] ?? $r));

            $r = $run(['setup' => array_merge($reset, [$auto('day:' . $D, 'sent', "$next 07:12:00"), $event(true, false, "$next 08:30:00")]), 'grep' => $grepE]);
            check($clean($r) && $f($r, 'End of Day sent 07:12') && !$f($r, 'End of Day sent 07:12 (test)'), 'chip: timed send earlier than the manual one -> 07:12', json_encode($r['found'] ?? $r));

            $r = $run(['setup' => array_merge($reset, [$auto('day:' . $D, 'failed', "$next 07:12:00"), $event(false, false, "$next 06:50:00")]), 'grep' => $grepE]);
            check($clean($r) && $f($r, 'End of Day not run') && $f($r, 'end-of-day-report.php?date=' . $D) && !$f($r, 'End of Day sent'),
                'chip: a failed timed send and a failed manual send -> not run, linking to that day', json_encode($r['found'] ?? $r));

            $r = $run(['setup' => $reset, 'grep' => $grepE]);
            check($clean($r) && $f($r, 'End of Day not run'), 'chip: no send at all -> not run', json_encode($r['found'] ?? $r));

            $r = $run(['setup' => array_merge($reset, [$auto('test:day:' . $D, 'sent', "$next 07:12:00")]), 'grep' => $grepE]);
            check($clean($r) && $f($r, 'End of Day sent 07:12 (test)'), 'chip: a test-mode timed send counts, marked (test)', json_encode($r['found'] ?? $r));

            // A user who cannot open the End of Day report sees no chip.
            require_once $root . '/admin/includes/permissions.php';
            $noEod = null;
            foreach ($pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role <> 'admin' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $u) {
                if (rhCanOpenPage((int)$u['id'], 'dashboard.php') && !rhCanLinkTo((int)$u['id'], 'end-of-day-report.php')) { $noEod = $u['username']; break; }
            }
            if (check($noEod !== null, 'found an active user who has the dashboard but not the End of Day report')) {
                $r = $run(['as_username' => $noEod, 'setup' => array_merge($reset, [$auto('day:' . $D, 'sent', "$next 07:12:00")]), 'grep' => $grepE + [6 => 'class="ck-hero__lede"']]);
                check($clean($r) && $f($r, 'class="ck-hero__lede"') && !$f($r, 'class="ck-daychip') && !$f($r, 'End of Day sent') && !$f($r, 'End of Day not run'),
                    "chip: hidden for $noEod (no End of Day access) while the dashboard renders", json_encode($r['found'] ?? $r));
            }

            /* ---- C2-C4: handover notes + welcome card ---- */
            $runPage = function (string $page, array $cfg) use ($h): array {
                $cmd = 'php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
                $out = shell_exec($cmd);
                if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
                if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
                return ['error' => 'no result', 'raw' => substr((string)$out, -400), 'rolled_back' => false];
            };
            $bUser = null; // a staff member (not an administrator) who has the dashboard
            foreach ($pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role <> 'admin' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $u) {
                if (rhCanOpenPage((int)$u['id'], 'dashboard.php')) { $bUser = $u; break; }
            }
            if (check($bUser !== null, 'found an active non-admin user with the dashboard (user B)')) {
                $A = $adminId;
                $B = (int)$bUser['id'];
                $Bname = (string)$bUser['username'];
                $C = 2147483000; // user C: an id no account has (the readers never join admin_users)

                // Readers, straight on this connection inside one transaction that is always rolled back.
                $pdo->beginTransaction();
                try {
                    // Real notes already on Liwonde are marked read for A, B and C inside this transaction only.
                    foreach ([$A, $B, $C] as $who) {
                        $pdo->exec("INSERT IGNORE INTO shift_handover_reads (note_id, user_id, read_at) SELECT id, $who, NOW() FROM shift_handover_notes WHERE expires_at > NOW()");
                    }
                    $insNote = $pdo->prepare("INSERT INTO shift_handover_notes (body, created_by, created_by_name, created_at, expires_at) VALUES (?, ?, 'zz seed', NOW() - INTERVAL ? MINUTE, NOW() - INTERVAL ? MINUTE + INTERVAL 48 HOUR)");
                    $insRead = $pdo->prepare('INSERT INTO shift_handover_reads (note_id, user_id, read_at) VALUES (?, ?, NOW())');
                    $addNote = function (int $by, int $minsAgo, string $body) use ($pdo, $insNote, $insRead): int {
                        $insNote->execute([$body, $by, $minsAgo, $minsAgo]);
                        $id = (int)$pdo->lastInsertId();
                        $insRead->execute([$id, $by]); // the author's own read row, as the write endpoint does
                        return $id;
                    };
                    $U = fn(int $who) => rh_dash_handover_unread($pdo, $who);
                    $n1 = $addNote($A, 60, 'zz n1');
                    check($U($A) === ['note' => null, 'more' => 0], 'handover: the author (A) sees nothing unread', json_encode($U($A)));
                    check(($U($B)['note']['id'] ?? 0) === $n1 && $U($B)['more'] === 0 && $U($B)['note']['body'] === 'zz n1' && $U($B)['note']['from'] === 'zz seed', 'handover: B sees A\'s note', json_encode($U($B)));
                    $insRead->execute([$n1, $B]);
                    check($U($B) === ['note' => null, 'more' => 0], 'handover: B marked it read -> gone for B');
                    check(($U($C)['note']['id'] ?? 0) === $n1, 'handover: still unread for C');
                    $addNote($A, 49 * 60, 'zz expired'); // written 49 h ago: expired 1 h ago
                    $edge = $addNote($A, 48 * 60, 'zz expires now'); // expires_at = NOW(): the end is exclusive
                    check(($U($C)['note']['id'] ?? 0) === $n1 && $U($C)['more'] === 0, 'handover: an expired note (and one expiring this second) is not shown or counted', json_encode($U($C)) . " edge=$edge");
                    $n3 = $addNote($B, 10, 'zz n3');
                    check(($U($C)['note']['id'] ?? 0) === $n3 && $U($C)['more'] === 1, 'handover: newest first, "and 1 more" counts the other unread one', json_encode($U($C)));
                    check(($U($A)['note']['id'] ?? 0) === $n3 && $U($A)['more'] === 0 && $U($B)['note'] === null, 'handover: B\'s note is unread for A, not for its author B');
                    check(rh_dash_handover_unread($pdo, 0) === null, 'handover: no user -> null');
                    $pdo->exec("DELETE FROM admin_user_preferences WHERE user_id = $A AND preference_key = 'dashboard_welcome_dismissed'");
                    check(rh_dash_welcome_show($pdo, $A) === true, 'welcome: shown while no dismissed row');
                    $pdo->exec("INSERT INTO admin_user_preferences (user_id, preference_key, preference_value) VALUES ($A, 'dashboard_welcome_dismissed', '2026-11-24 10:00:00')");
                    check(rh_dash_welcome_show($pdo, $A) === false && rh_dash_welcome_show($pdo, 0) === false, 'welcome: hidden after "Got it"; no user -> hidden');
                } finally {
                    $pdo->rollBack();
                }
                check((int)$pdo->query("SELECT COUNT(*) FROM shift_handover_notes WHERE body LIKE 'zz %'")->fetchColumn() === 0, 'readers: nothing left behind (rolled back)');

                // Before migration 091 runs (Rosalyn's after the port): a missing table -> empty, silent, no log line.
                $mpdo = new Zz091MissingTablePdo('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $logFile = (string)tempnam(sys_get_temp_dir(), 'zz091');
                $oldLog = ini_set('error_log', $logFile);
                $mu = rh_dash_handover_unread($mpdo, $A);
                $mw = rh_dash_welcome_show($mpdo, $A);
                $mi = null;
                try { $mpdo->prepare('SELECT 1 FROM shift_handover_notes')->execute(); } catch (PDOException $e) { $mi = rh_dash_table_missing($e); }
                ini_set('error_log', (string)$oldLog);
                $logged = (string)@file_get_contents($logFile);
                @unlink($logFile);
                check($mi === true, 'missing table: the real MySQL error is recognised as "missing table"');
                check($mu === null && $mw === false && $logged === '', 'missing table: handover reader -> null (no button, no item), welcome -> hidden, nothing logged', json_encode([$mu, $mw, $logged]));
                $ds = str_replace("\r\n", "\n", (string)file_get_contents($root . '/admin/dashboard.php'));
                check(substr_count($ds, '<?php if ($handover !== null): ?>') === 2 && strpos($ds, '<?php if ($attn || $handoverNote):') !== false && strpos($ds, '<?php if ($welcomeCard): ?>') !== false,
                    'missing table: the button, the form, the item and the card all hang on the readers\' empty answers');
                $hs = (string)file_get_contents($root . '/admin/api/handover-notes.php');
                check(strpos($hs, 'if (rh_dash_table_missing($e)) {') !== false && strpos($hs, "'Handover notes are not set up on this system yet.'") !== false, 'missing table: the write endpoint answers with a plain refusal');

                // Write endpoint (harness, rolled back).
                $nCount = (int)$pdo->query('SELECT COUNT(*) FROM shift_handover_notes')->fetchColumn();
                $lastA = "(SELECT MAX(id) FROM shift_handover_notes WHERE created_by = $A)";
                $vW = [
                    "SELECT id, body, created_by, created_by_name, TIMESTAMPDIFF(MINUTE, created_at, expires_at) AS mins, ABS(TIMESTAMPDIFF(SECOND, NOW(), created_at)) AS skew FROM shift_handover_notes WHERE id = $lastA",
                    "SELECT user_id FROM shift_handover_reads WHERE note_id = $lastA ORDER BY user_id",
                    "SELECT details FROM admin_activity_log WHERE user_id = $A AND action = 'handover_note_written' AND details = CONCAT('Note #', $lastA)",
                    'SELECT COUNT(*) AS c FROM shift_handover_notes',
                ];
                $hn = 'admin/api/handover-notes.php';
                $r = $runPage($hn, ['post' => ['action' => 'write', 'body' => "  zz handover\r\nline 2 <b>x</b>  "], 'verify' => $vW]);
                $row = $r['verify'][0][0] ?? [];
                check($clean($r) && !empty($r['json']['success']) && (int)($r['json']['data']['note_id'] ?? 0) === (int)($row['id'] ?? -1)
                    && ($row['body'] ?? '') === "zz handover\nline 2 <b>x</b>" && (int)$row['created_by'] === $A && (int)$row['mins'] === 2880 && (int)$row['skew'] <= 5,
                    'write: note saved trimmed, by the caller, expires exactly 48 h after it was written', json_encode($r));
                check(array_map('intval', array_column($r['verify'][1] ?? [], 'user_id')) === [$A], 'write: the author\'s own read row only (author auto-read)', json_encode($r['verify'][1] ?? null));
                check(count($r['verify'][2] ?? []) === 1 && strpos(json_encode($r['verify'][2]), 'zz handover') === false, 'write: one activity row "Note #ID", never the text', json_encode($r['verify'][2] ?? null));
                check((int)($r['verify'][3][0]['c'] ?? -1) === $nCount + 1, 'write: exactly one note');
                $refused = function (array $post, string $label, string $errPart) use ($runPage, $hn, $clean, $nCount): void {
                    $r = $runPage($hn, ['post' => $post, 'verify' => ['SELECT COUNT(*) AS c FROM shift_handover_notes']]);
                    check($clean($r) && isset($r['json']['success']) && $r['json']['success'] === false && stripos((string)($r['json']['error'] ?? ''), $errPart) !== false
                        && (int)($r['verify'][0][0]['c'] ?? -1) === $nCount, $label, json_encode($r['json'] ?? $r));
                };
                $refused(['action' => 'write', 'body' => ''], 'write: 0 characters refused, nothing saved', '1 to 500');
                $refused(['action' => 'write', 'body' => "   \n  "], 'write: blank refused, nothing saved', '1 to 500');
                $refused(['action' => 'write', 'body' => str_repeat('a', 501)], 'write: 501 characters refused, nothing saved', '1 to 500');
                $refused(['action' => 'write', 'body' => 'zz no token', 'csrf_token' => ''], 'write: missing CSRF token refused, nothing saved', 'session');
                $refused(['action' => 'delete', 'body' => 'zz'], 'write: unknown action refused', 'Unknown');
                $r = $runPage($hn, ['post' => ['action' => 'write', 'body' => str_repeat('é', 500)], 'verify' => ['SELECT CHAR_LENGTH(body) AS n FROM shift_handover_notes WHERE id = ' . $lastA]]);
                check($clean($r) && !empty($r['json']['success']) && (int)($r['verify'][0][0]['n'] ?? 0) === 500, 'write: 500 characters (multi-byte) accepted whole', json_encode($r['json'] ?? $r));

                // Mark read (dashboard-seen.php) as B.
                $ds2 = 'admin/api/dashboard-seen.php';
                $seedNote = fn(string $body, string $created, string $expires) => "INSERT INTO shift_handover_notes (body, created_by, created_by_name, created_at, expires_at) VALUES (" . $q($body) . ", $A, 'zz Admin', $created, $expires)";
                $seedRead = fn(string $body, int $who) => "INSERT INTO shift_handover_reads (note_id, user_id, read_at) SELECT MAX(id), $who, NOW() FROM shift_handover_notes WHERE body = " . $q($body);
                $nidOf = fn(string $body) => '(SELECT MAX(id) FROM shift_handover_notes WHERE body = ' . $q($body) . ')';
                $live = $seedNote('zz seed note', 'NOW() - INTERVAL 5 MINUTE', 'NOW() + INTERVAL 47 HOUR');
                $vR = fn(string $body) => [
                    'SELECT user_id FROM shift_handover_reads WHERE note_id = ' . $nidOf($body) . ' ORDER BY user_id',
                    "SELECT details FROM admin_activity_log WHERE user_id = $B AND action = 'handover_note_read' AND details = CONCAT('Note #', " . $nidOf($body) . ')',
                    "SELECT COUNT(*) AS c FROM admin_activity_log WHERE action = 'page_action' AND details LIKE 'page=dashboard-seen.php%' AND created_at >= NOW() - INTERVAL 1 MINUTE",
                ];
                $r = $runPage($ds2, ['as_username' => $Bname, 'setup' => [$live, $seedRead('zz seed note', $A)], 'vars' => ['nid' => 'SELECT ' . $nidOf('zz seed note')],
                    'post' => ['action' => 'handover_read', 'note_id' => '{nid}', 'user_id' => (string)$C], 'verify' => $vR('zz seed note')]);
                check($clean($r) && !empty($r['json']['success']) && array_map('intval', array_column($r['verify'][0] ?? [], 'user_id')) === [$A, $B],
                    'read: B marks it read -> B\'s own row only (a user_id in the request is ignored, C gets nothing)', json_encode($r['verify'][0] ?? $r));
                check(count($r['verify'][1] ?? []) === 1 && (int)($r['verify'][2][0]['c'] ?? -1) === 0, 'read: one activity row "Note #ID" (no second row from the page hook)', json_encode($r['verify'] ?? null));
                check(array_key_exists('next', (array)($r['json']['data'] ?? [])), 'read: the answer carries the next unread note (or none)');
                $r = $runPage($ds2, ['as_username' => $Bname, 'setup' => [$live, $seedRead('zz seed note', $A), $seedRead('zz seed note', $B)], 'vars' => ['nid' => 'SELECT ' . $nidOf('zz seed note')],
                    'post' => ['action' => 'handover_read', 'note_id' => '{nid}'], 'verify' => $vR('zz seed note')]);
                check($clean($r) && !empty($r['json']['success']) && count($r['verify'][0] ?? []) === 2 && count($r['verify'][1] ?? []) === 0, 'read: marking it again is a no-op (no second row, not logged again)', json_encode($r['verify'] ?? $r));
                $r = $runPage($ds2, ['as_username' => $Bname, 'setup' => [$seedNote('zz old note', 'NOW() - INTERVAL 49 HOUR', 'NOW() - INTERVAL 1 HOUR')], 'vars' => ['nid' => 'SELECT ' . $nidOf('zz old note')],
                    'post' => ['action' => 'handover_read', 'note_id' => '{nid}'], 'verify' => $vR('zz old note')]);
                check($clean($r) && ($r['json']['success'] ?? null) === false && ($r['json']['error'] ?? '') === 'That note is no longer there.' && ($r['verify'][0] ?? null) === [], 'read: an expired note is refused, no row', json_encode($r['json'] ?? $r));
                $r = $runPage($ds2, ['as_username' => $Bname, 'post' => ['action' => 'handover_read', 'note_id' => '2147483000'], 'verify' => ["SELECT COUNT(*) AS c FROM shift_handover_reads WHERE note_id = 2147483000"]]);
                check($clean($r) && ($r['json']['success'] ?? null) === false && (int)($r['verify'][0][0]['c'] ?? -1) === 0, 'read: a note that does not exist is refused, no row', json_encode($r['json'] ?? $r));
                $r = $runPage($ds2, ['as_username' => $Bname, 'setup' => [$live], 'vars' => ['nid' => 'SELECT ' . $nidOf('zz seed note')],
                    'post' => ['action' => 'handover_read', 'note_id' => '{nid}', 'csrf_token' => ''], 'verify' => $vR('zz seed note')]);
                check($clean($r) && ($r['json']['success'] ?? null) === false && ($r['verify'][0] ?? null) === [], 'read: missing CSRF token refused, no row', json_encode($r['json'] ?? $r));
                $prefB = "SELECT preference_value FROM admin_user_preferences WHERE user_id = $B AND preference_key = 'dashboard_welcome_dismissed'";
                $r = $runPage($ds2, ['as_username' => $Bname, 'setup' => ["DELETE FROM admin_user_preferences WHERE user_id = $B AND preference_key = 'dashboard_welcome_dismissed'"],
                    'post' => ['action' => 'welcome_dismissed'], 'verify' => [$prefB, "SELECT COUNT(*) AS c FROM admin_activity_log WHERE user_id = $B AND created_at >= NOW() - INTERVAL 1 MINUTE AND (action LIKE '%welcome%' OR details LIKE '%dashboard-seen%')"]]);
                check($clean($r) && !empty($r['json']['success']) && count($r['verify'][0] ?? []) === 1 && (int)($r['verify'][1][0]['c'] ?? -1) === 0, '"Got it": B\'s own preference row saved, not logged', json_encode($r['verify'] ?? $r));
                $r = $runPage($ds2, ['as_username' => $Bname, 'setup' => ["DELETE FROM admin_user_preferences WHERE user_id = $B AND preference_key = 'dashboard_welcome_dismissed'"],
                    'post' => ['action' => 'welcome_dismissed', 'csrf_token' => ''], 'verify' => [$prefB]]);
                check($clean($r) && ($r['json']['success'] ?? null) === false && ($r['verify'][0] ?? null) === [], '"Got it": missing CSRF token refused', json_encode($r['json'] ?? $r));

                // The dashboard (C3/C4).
                $xssBody = "zz <script>alert(1)</script>\nsecond line";
                $noteAB = [$seedNote($xssBody, 'NOW() - INTERVAL 5 MINUTE', 'NOW() + INTERVAL 47 HOUR'), $seedRead($xssBody, $A)];
                $grepH = ['<li data-handover-item>', 'zz &lt;script&gt;alert(1)&lt;/script&gt;<br />', '<script>alert(1)', 'From zz Admin, ', 'data-handover-read="', 'id="ckHandoverOpen"', 'id="ckWelcome"', 'You are signed in as <strong>', '<i class="fas fa-chart-column"></i> Reports &amp; EOD</a>'];
                $dumpWith = (string)tempnam(sys_get_temp_dir(), 'zzdw');
                $dumpWithout = (string)tempnam(sys_get_temp_dir(), 'zzdo');
                $r = $run(['as_username' => $Bname, 'setup' => $noteAB, 'grep' => $grepH, 'dump_html' => $dumpWith]);
                check($clean($r) && $f($r, '<li data-handover-item>') && $f($r, 'zz &lt;script&gt;alert(1)&lt;/script&gt;<br />') && !$f($r, '<script>alert(1)') && $f($r, 'From zz Admin, ') && $f($r, 'data-handover-read="') && $f($r, 'id="ckHandoverOpen"'),
                    "dashboard: $Bname sees A's note in Needs attention, escaped, line breaks kept, Mark read button", json_encode($r['found'] ?? $r));
                $r = $run(['as_username' => $Bname, 'setup' => [], 'grep' => $grepH, 'dump_html' => $dumpWithout]);
                $badge = static function (string $file): string {
                    $html = (string)@file_get_contents($file);
                    @unlink($file);
                    preg_match('#Needs attention <span class="ck-badge">(\d+)</span>#', $html, $m1);
                    preg_match('#<p class="ck-hero__lede[^"]*">(.*?)</p>#s', $html, $m2);
                    return ($m1[1] ?? 'none') . '|' . trim($m2[1] ?? '');
                };
                $bw = $badge($dumpWith);
                $bo = $badge($dumpWithout);
                check($clean($r) && !$f($r, '<li data-handover-item>') && $bw === $bo && $bw !== '|', 'dashboard: the note is not counted (badge and hero sentence the same with and without it)', "$bw vs $bo");
                $r = $run(['setup' => $noteAB, 'grep' => $grepH]);
                check($clean($r) && !$f($r, '<li data-handover-item>') && $f($r, 'id="ckHandoverOpen"'), 'dashboard: the author (admin) does not see their own note; the button is there', json_encode($r['found'] ?? $r));
                $r = $run(['as_username' => $Bname, 'setup' => array_merge($noteAB, [$seedRead($xssBody, $B)]), 'grep' => $grepH]);
                check($clean($r) && !$f($r, '<li data-handover-item>'), 'dashboard: after Mark read the note is gone for B', json_encode($r['found'] ?? $r));
                $r = $run(['as_username' => $Bname, 'setup' => array_merge($noteAB, ["UPDATE shift_handover_notes SET expires_at = NOW() - INTERVAL 1 SECOND WHERE body = " . $q($xssBody)]), 'grep' => $grepH]);
                check($clean($r) && !$f($r, '<li data-handover-item>'), 'dashboard: an expired note is not shown', json_encode($r['found'] ?? $r));
                $noPrefB = "DELETE FROM admin_user_preferences WHERE user_id = $B AND preference_key = 'dashboard_welcome_dismissed'";
                $r = $run(['as_username' => $Bname, 'setup' => [$noPrefB], 'grep' => $grepH]);
                check($clean($r) && $f($r, 'id="ckWelcome"') && $f($r, 'You are signed in as <strong>'), "welcome card: shown to $Bname with no \"Got it\" yet", json_encode($r['found'] ?? $r));
                $r = $run(['as_username' => $Bname, 'setup' => [$noPrefB, "INSERT INTO admin_user_preferences (user_id, preference_key, preference_value) VALUES ($B, 'dashboard_welcome_dismissed', NOW())"], 'grep' => $grepH]);
                check($clean($r) && !$f($r, 'id="ckWelcome"'), 'welcome card: gone after "Got it" (the row dashboard-seen.php writes)', json_encode($r['found'] ?? $r));
                $r = $run(['setup' => [], 'grep' => $grepH]);
                check($clean($r) && $f($r, '<i class="fas fa-chart-column"></i> Reports &amp; EOD</a>'), 'guides menu: still rendered from the shared list (admin sees Reports & EOD, escaped once)', json_encode($r['found'] ?? $r));
            }
        }
    }
} else {
    echo "DB part skipped (add --db, Liwonde only).\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
