<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Dashboard first-login information (plan .claude/DASHBOARD_FIRSTLOGIN_PLAN.md, Phase A: A2 last sign-in, A3 the
 * line in the hero, A4 the day status chip).
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
        }
    }
} else {
    echo "DB part skipped (add --db, Liwonde only).\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
