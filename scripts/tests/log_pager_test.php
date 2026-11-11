<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * System Logs paging (owner 2026-11-12). NO DATABASE: only admin/includes/log-pager.php (pure helpers) is loaded,
 * plus source checks on admin/system-logs.php. Checks: page/size parsing (bad input), clamping, the count cap,
 * offsets, the page window, the "Showing x–y of N" wording, links (filters kept, anchor), the pager markup.
 * Usage (repo root): php scripts/tests/log_pager_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/admin/includes/log-pager.php';
require_once $root . '/admin/includes/login-bundles.php';
check(!isset($GLOBALS['pdo']) && !function_exists('getSetting'), 'the pager loads without the database or settings');

/* ── parsing ── */
$p = rh_lp_parse([], 'ev');
check($p['page'] === 1 && $p['size'] === 10 && $p['offset'] === 0 && $p['key'] === 'ev', 'default: page 1, 10 per page, offset 0');
foreach (['0', '-3', 'abc', '2x', '9999999', ' 2', ''] as $bad) {
    check(rh_lp_parse(['ev_page' => $bad], 'ev')['page'] === 1, 'bad page "' . $bad . '" -> 1');
}
check(rh_lp_parse(['ev_page' => ['2']], 'ev')['page'] === 1, 'an array page -> 1');
check(rh_lp_parse(['ev_page' => '3'], 'ev')['offset'] === 20, 'page 3 of 10 -> offset 20');
check(rh_lp_parse(['ev_page' => '3', 'ev_size' => '25'], 'ev')['offset'] === 50, 'page 3 of 25 -> offset 50');
foreach (['10', '25', '50', '100'] as $s) {
    check(rh_lp_parse(['ev_size' => $s], 'ev')['size'] === (int)$s, 'size ' . $s . ' allowed');
}
foreach (['5', '30', '1000', '25abc', '-10', 'x'] as $s) {
    check(rh_lp_parse(['ev_size' => $s], 'ev')['size'] === 10, 'size "' . $s . '" -> 10');
}
check(rh_lp_parse(['ev_size' => ['50']], 'ev')['size'] === 10, 'an array size -> 10');
check(rh_lp_parse(['ba_page' => '4'], 'ev')['page'] === 1, 'another list\'s page is not this list\'s');

/* ── clamp, cap, more ── */
$p = rh_lp_clamp(rh_lp_parse(['ev_page' => '999'], 'ev'), 1234);
check($p['page'] === 124 && $p['pages'] === 124 && $p['offset'] === 1230 && !$p['more'] && !$p['capped'], 'past the last page -> the last page');
$p = rh_lp_clamp(rh_lp_parse(['ev_page' => '2'], 'ev'), 0);
check($p['page'] === 1 && $p['pages'] === 1 && $p['total'] === 0, 'no rows -> page 1 of 1');
$p = rh_lp_clamp(rh_lp_parse(['ev_page' => '2'], 'ev'), 1234);
check($p['more'] && $p['offset'] === 10, 'page 2 of 124 has more');
$p = rh_lp_more($p, 11);
check($p['more'], 'below the cap, the count decides Next');
$p = rh_lp_clamp(rh_lp_parse(['ev_page' => '1500'], 'ev'), RH_LP_COUNT_CAP + 1);
check($p['capped'] && $p['total'] === RH_LP_COUNT_CAP && $p['page'] === 1500 && $p['offset'] === 14990, 'at the cap the page is not clamped (older history stays reachable)');
check(rh_lp_cap_for(rh_lp_parse(['ev_page' => '1500'], 'ev')) === 15000 && rh_lp_cap_for(rh_lp_parse([], 'ev')) === RH_LP_COUNT_CAP
    && rh_lp_cap_for(rh_lp_parse(['ev_page' => '999999', 'ev_size' => '100'], 'ev')) === RH_LP_MAX_ROWS, 'an older page is counted as deep as it asks, never past the maximum');
$deep = rh_lp_parse(['ev_page' => '5000'], 'ev');
$p = rh_lp_clamp($deep, 12345, rh_lp_cap_for($deep));
check(!$p['capped'] && $p['page'] === 1235 && $p['offset'] === 12340, 'a page past the real end (counted deep enough) -> the last page, no huge OFFSET');
$deep = rh_lp_parse(['ev_page' => '999999'], 'ev');
$p = rh_lp_clamp($deep, RH_LP_MAX_ROWS + 1, rh_lp_cap_for($deep));
check($p['capped'] && $p['page'] === 10000 && $p['offset'] === 99990, 'past the maximum depth -> the deepest page (OFFSET never above ' . RH_LP_MAX_ROWS . ')');
check(!rh_lp_more($p, 11)['more'], 'no Next past the maximum depth');
/* a failed count: the rows still show, Next while a full page came back */
$p = rh_lp_clamp(rh_lp_parse(['sc_page' => '3'], 'sc'), -1);
check($p['unknown'] && !$p['capped'] && $p['page'] === 3 && $p['offset'] === 20, 'count failed: the page is kept');
$pm = rh_lp_more($p, 11);
check($pm['more'] && $pm['pages'] === 4, 'count failed: a full page means Next');
check(!rh_lp_more($p, 7)['more'], 'count failed: a short page is the last');
check(rh_lp_summary($pm, 10) === "Showing 21\u{2013}30 (count unavailable)", 'count failed: summary says so', rh_lp_summary($pm, 10));
$uh = rh_lp_render('system-logs.php', [], rh_lp_more(rh_lp_clamp(rh_lp_parse([], 'sc'), -1), 3), 3, 'log-shift-closes');
check($uh !== '' && strpos($uh, 'count unavailable') !== false, 'count failed: the pager still shows (rows are not hidden)');
check(strpos(rh_lp_cap_note($p), 'could not be counted') !== false, 'count failed: a plain note');
$p = rh_lp_clamp(rh_lp_parse(['ev_page' => '1500'], 'ev'), RH_LP_COUNT_CAP + 1);
$p = rh_lp_more($p, 11);
check($p['more'] && $p['pages'] === 1501, 'at the cap, an extra row means Next');
$p = rh_lp_more(rh_lp_clamp(rh_lp_parse(['ev_page' => '1500'], 'ev'), RH_LP_COUNT_CAP + 1), 4);
check(!$p['more'], 'at the cap, a short page is the last one');
check(rh_lp_limit_sql(rh_lp_clamp(rh_lp_parse(['ev_page' => '3', 'ev_size' => '50'], 'ev'), 500)) === ' LIMIT 51 OFFSET 100', 'LIMIT size+1 OFFSET, integers only');

/* ── in-memory lists (grouped events, sign-in bundles) ── */
[$p, $rows] = rh_lp_slice(['lg_page' => '3'], 'lg', range(1, 25));
check($rows === [21, 22, 23, 24, 25] && $p['total'] === 25 && $p['pages'] === 3 && !$p['more'], 'slice: page 3 of 25 rows');
[$p, $rows] = rh_lp_slice(['lg_page' => '9'], 'lg', range(1, 25));
check($p['page'] === 3 && count($rows) === 5, 'slice: past the end -> last page');
[$p, $rows] = rh_lp_slice([], 'lg', []);
check($p['total'] === 0 && $rows === [], 'slice: nothing');
[$p, $rows] = rh_lp_slice(['lg_page' => '2', 'lg_size' => '25'], 'lg', range(1, 60));
check($rows === range(26, 50) && $p['page'] === 2 && $p['more'], 'slice: page 2 at 25 per page');

/* ── Recent Logins: bundling, then the page from lg_page (blocker 2026-11-12: lg_page was ignored) ── */
$ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0';
$logins = [];
$t = strtotime('2026-11-12 18:00:00');
for ($i = 0; $i < 30; $i++) { // 30 people, each two tries a minute apart, 2 hours between people
    $u = 'user' . $i;
    $at = $t - $i * 7200;
    $logins[] = ['user_id' => $i + 1, 'username' => $u, 'action' => 'login_success', 'details' => '', 'ip_address' => '10.0.0.' . $i, 'user_agent' => $ua, 'created_at' => date('Y-m-d H:i:s', $at)];
    $logins[] = ['user_id' => $i + 1, 'username' => $u, 'action' => 'login_failed', 'details' => '', 'ip_address' => '10.0.0.' . $i, 'user_agent' => $ua, 'created_at' => date('Y-m-d H:i:s', $at - 60)];
}
$bundles = rh_login_bundles($logins);
check(count($bundles) === 30 && $bundles[0]['attempt_count'] === 2 && $bundles[0]['result_label'] === 'Mixed' && $bundles[0]['browser_label'] === 'Chrome / Windows',
    'logins: two tries from one person and device = one bundle (Mixed, Chrome / Windows)');
check($bundles[0]['latest_at'] === '2026-11-12 18:00:00' && $bundles[0]['oldest_at'] === '2026-11-12 17:59:00', 'logins: a bundle keeps its newest and oldest time');
$far = [$logins[0], ['created_at' => date('Y-m-d H:i:s', $t - 3600)] + $logins[1]];
check(count(rh_login_bundles($far)) === 2, 'logins: the same person 60 minutes apart = two bundles (45-minute window)');
$sid = [['details' => 'sid:abcdef12'] + $logins[0], ['details' => 'sid:abcdef12', 'ip_address' => '10.9.9.9'] + $logins[1]];
check(count(rh_login_bundles($sid)) === 1, 'logins: the same session id joins even from a new address');
check(rh_login_bundles([['action' => 'login_blocked', 'created_at' => '2026-11-12 10:00:00']])[0]['result_label'] === 'Blocked', 'logins: blocked only -> Blocked');
[$lp1, $pg1] = rh_lp_slice([], 'lg', $bundles);
[$lp2, $pg2] = rh_lp_slice(['lg_page' => '2'], 'lg', $bundles);
[$lp3, $pg3] = rh_lp_slice(['lg_page' => '3'], 'lg', $bundles);
check($pg1[0]['username'] === 'user0' && $pg2[0]['username'] === 'user10' && $pg3[0]['username'] === 'user20' && $lp2['page'] === 2 && $lp3['page'] === 3,
    'logins: lg_page=2 and lg_page=3 show bundles 11-20 and 21-30 (not page 1 again)', json_encode([$pg2[0]['username'] ?? null, $pg3[0]['username'] ?? null]));
check(rh_lp_summary($lp2, count($pg2)) === "Showing 11\u{2013}20 of 30", 'logins: page 2 summary', rh_lp_summary($lp2, count($pg2)));
[$lpS, $pgS] = rh_lp_slice(['lg_page' => '2', 'lg_size' => '25'], 'lg', $bundles);
check(count($pgS) === 5 && $pgS[0]['username'] === 'user25', 'logins: 25 per page, page 2 = the last 5');

/* ── wording ── */
$p = rh_lp_clamp(rh_lp_parse(['ev_page' => '2'], 'ev'), 1234);
check(rh_lp_summary($p, 10) === "Showing 11\u{2013}20 of 1,234", 'summary: Showing 11–20 of 1,234', rh_lp_summary($p, 10));
check(rh_lp_summary(rh_lp_clamp(rh_lp_parse([], 'ev'), 0), 0) === 'Showing 0 of 0', 'summary: Showing 0 of 0');
$p = rh_lp_clamp(rh_lp_parse([], 'ev'), RH_LP_COUNT_CAP + 1);
check(rh_lp_summary($p, 10) === "Showing 1\u{2013}10 of 10,000+", 'summary: capped count says 10,000+', rh_lp_summary($p, 10));
check(rh_lp_cap_note($p) !== '' && rh_lp_cap_note(rh_lp_clamp(rh_lp_parse([], 'ev'), 5)) === '', 'cap note only when capped');
check(rh_lp_window(1, 1) === [1], 'window: one page');
check(rh_lp_window(5, 20) === [1, 'ellipsis', 3, 4, 5, 6, 7, 'ellipsis', 20], 'window: 1 … 3 4 5 6 7 … 20');
check(rh_lp_window(1, 20) === [1, 2, 3, 'ellipsis', 20], 'window: start');
check(rh_lp_window(20, 20) === [1, 'ellipsis', 18, 19, 20], 'window: end');
check(rh_lp_window(4, 6) === [1, 2, 3, 4, 5, 6], 'window: no ellipsis for a gap of none');
check(rh_lp_window(1500, 1501, false) === [1, 'ellipsis', 1498, 1499, 1500, 1501, 'ellipsis'], 'window: past the count cap ends in …');

/* ── links ── */
$get = ['source' => 'pos', 'level' => 'all', 'ev_page' => '2', 'ev_size' => '25', 'ba_page' => '3', 'export' => 'csv', 'r' => '1', 'bad key' => 'x', 'arr' => ['x']];
$p = rh_lp_clamp(rh_lp_parse($get, 'ev'), 1000);
$u = rh_lp_url('system-logs.php', $get, $p, 3, 'log-events');
parse_str((string)parse_url($u, PHP_URL_QUERY), $q);
check($q === ['source' => 'pos', 'level' => 'all', 'ev_size' => '25', 'ba_page' => '3', 'ev_page' => '3'],
    'link: other filters and other lists\' pages kept; export / reload marker / odd keys dropped', $u);
check(substr($u, -11) === '#log-events', 'link: returns to the list\'s anchor');
check(strpos(rh_lp_url('system-logs.php', $get, $p, 1, 'log-events'), 'ev_page') === false, 'link: page 1 has no page param');

/* ── markup ── */
$html = rh_lp_render('system-logs.php', $get, rh_lp_more($p, 26), 25, 'log-events', 'Event pages', 'Note <b>');
check(strpos($html, '<nav class="bookings-pagination admin-auto-pagination rh-log-pager"') === 0, 'markup: the shared pager classes');
check(strpos($html, '<span class="pg-current" aria-current="page">2</span>') !== false, 'markup: current page marked');
check(strpos($html, 'ev_page=3') !== false && strpos($html, 'Next &rsaquo;</a>') !== false, 'markup: Next links to page 3');
check(strpos($html, "Showing 26\u{2013}50 of 1,000") !== false, 'markup: summary');
check(strpos($html, '<select name="ev_size" aria-label="Rows per page" style="min-height:44px;">') !== false && strpos($html, '<option value="25" selected>') !== false, 'markup: rows picker with the current size');
check(strpos($html, 'action="system-logs.php#log-events"') !== false && strpos($html, 'name="ba_page" value="3"') !== false
    && strpos($html, 'name="ev_page"') === false && strpos($html, 'name="ev_size" value') === false, 'markup: picker keeps the other filters, starts at page 1');
check(strpos($html, 'Note &lt;b&gt;') !== false, 'markup: the note is escaped');
$first = rh_lp_render('system-logs.php', [], rh_lp_clamp(rh_lp_parse([], 'ev'), 5), 5, 'log-events');
check(strpos($first, '<a class="pg-btn disabled" aria-disabled="true">&lsaquo; Prev</a>') !== false && strpos($first, 'admin-auto-pagination--single') !== false, 'markup: one page -> disabled Prev without a link, single style');
check(rh_lp_render('system-logs.php', [], rh_lp_clamp(rh_lp_parse([], 'ev'), 0), 0, 'log-events') === '', 'markup: an empty list has no pager');
$evil = rh_lp_render('system-logs.php', ['source' => '"><script>x</script>'], rh_lp_clamp(rh_lp_parse([], 'ev'), 50), 10, 'log-events');
check(strpos($evil, '<script>') === false, 'markup: filter values are escaped');

/* ── System Logs uses it everywhere ── */
$src = (string)file_get_contents($root . '/admin/system-logs.php');
foreach (['ev' => 'log-events', 'ba' => 'log-booking-audit', 'em' => 'log-emails', 'lg' => 'log-logins', 'aa' => 'log-admin-activity', 'pos' => 'log-pos',
    'kds' => 'log-kds', 'sm' => 'log-station-messages', 'sc' => 'log-shift-closes', 'api' => 'log-api-calls', 'off' => 'log-offline-sync'] as $k => $anchor) {
    check(preg_match('/rh_lp_render\(\'system-logs\.php\', \$_GET, \$' . $k . 'P, [^;]*\'' . $anchor . '\'/', $src) === 1 && strpos($src, 'id="' . $anchor . '"') !== false,
        'system logs: ' . $k . ' list has a pager returning to #' . $anchor);
}
check(strpos($src, 'LIMIT {$limit}') === false && strpos($src, 'name="limit"') === false, 'system logs: no list is cut at the old Rows limit');
check(substr_count($src, '<div class="log-scroll">') === 0 && strpos($src, 'data-page-size-picker') === false, 'system logs: every table is marked so the client pager does not page it again');
check(strpos($src, 'posPageSize') === false, 'system logs: the POS client pager is gone (the server pages it)');
check(strpos($src, 'WHERE soa.order_id IN (') !== false, 'system logs: POS audit read for the orders on the page only');
check(strpos($src, "email_kind IN ('test', 'test_email')") !== false && strpos($src, "'em_size' => (string)\$emP['size']") !== false, 'system logs: email paging keeps the test-kind filter and the rows choice');
check(strpos($src, '<details class="log-file__more">') !== false && strpos($src, '<summary style="min-height:44px;') !== false, 'system logs: file tails show 20 lines, the rest behind a 44px Show more');
check(strpos($src, "rh_lp_slice(\$_GET, 'lg', rh_login_bundles(\$recentLogins))") !== false && strpos($src, "rh_lp_slice(\$_GET, 'ev', rh_log_group_events(") !== false,
    'system logs: grouped events and sign-in bundles page from their own _page param');
check(strpos($src, 'function rh_login_browser_label') === false, 'system logs: the sign-in helpers live in admin/includes/login-bundles.php only');
check(strpos($src, "ORDER BY replayed_at DESC, id DESC") !== false, 'system logs: offline sync has a stable order for paging');
check(strpos($src, "<?php if (\$scP['total'] > 0 || \$shiftCloses):") !== false, 'system logs: Shift Closes still show when the count failed');
check(preg_match('/action="system-logs\.php#log-events">\s*<\?php[^?]*foreach \(\$_GET as \$lpKeepKey/', $src) === 1
    && strpos($src, "(?:(?:ba|em|lg|aa|pos|kds|sm|sc|api|off)_(?:page|size)|ev_size)") !== false, 'system logs: the event filters keep ev_size and the other lists\' pages');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
