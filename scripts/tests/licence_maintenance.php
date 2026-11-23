<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Maintenance windows from the licence panel (owner 2026-11-23). Pure: no database, no network, no email.
 * Usage (from the repo root): php scripts/tests/licence_maintenance.php
 *  1. rh_maint_decide: before the notice, the notice, offline per scope, after the end, the clock edges, bad data, over 72 hours;
 *  2. what is stored from the panel's field, the staff notice wording, the guest website's words;
 *  3. the files: stored only from a verified answer, read fresh (never the settings cache), the guard and the guest gate use it,
 *     the panel's nudge and the key-authenticated API are never blocked, the hotel's own switch is never changed.
 */
$root = dirname(__DIR__, 2);
chdir($root);
date_default_timezone_set('Africa/Blantyre');
$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } }
function eq($want, $got, string $l): void { check($want === $got, $l, 'expected ' . var_export($want, true) . ' got ' . var_export($got, true)); }

require $root . '/includes/licence-maintenance.php';

$A = 1_800_000_000;            // notice from
$S = $A + 86400;               // starts
$E = $S + 7200;                // ends
$w = static function (array $over = []) use ($A, $S, $E): array {
    return array_merge(['id' => 7, 'scope' => 'both', 'announce_from' => $A, 'starts_at' => $S, 'ends_at' => $E, 'message' => 'Patch night.'], $over);
};

// ---- 1. the decision
foreach (['admin', 'site'] as $area) {
    eq('online', rh_maint_decide($A - 1, $w(), $area), "$area: before the notice");
    eq('announce', rh_maint_decide($A, $w(), $area), "$area: at the notice");
    eq('announce', rh_maint_decide($S - 1, $w(), $area), "$area: one second before the start");
    eq('offline', rh_maint_decide($S, $w(), $area), "$area: at the start");
    eq('offline', rh_maint_decide($E - 1, $w(), $area), "$area: one second before the end");
    eq('online', rh_maint_decide($E, $w(), $area), "$area: at the end (by this site's own clock)");
    eq('online', rh_maint_decide($E + 86400, $w(), $area), "$area: long after");
}
eq('offline', rh_maint_decide($S, $w(['scope' => 'admin']), 'admin'), 'admin window: staff system offline');
eq('online', rh_maint_decide($S, $w(['scope' => 'admin']), 'site'), 'admin window: guest website stays on');
eq('online', rh_maint_decide($S, $w(['scope' => 'site']), 'admin'), 'site window: staff system stays on');
eq('offline', rh_maint_decide($S, $w(['scope' => 'site']), 'site'), 'site window: guest website offline');
eq('online', rh_maint_decide($A, $w(['scope' => 'site']), 'admin'), 'site window: no staff-system notice from the decision (the banner says it)');
eq('online', rh_maint_decide($S, null, 'admin'), 'nothing held: online');
eq('online', rh_maint_decide($S, [], 'admin'), 'empty: online');
eq('online', rh_maint_decide($S, $w(), 'kitchen'), 'unknown area: online');
eq('online', rh_maint_decide($S, $w(['scope' => 'all']), 'admin'), 'unknown scope: online');
eq('online', rh_maint_decide($S, $w(['starts_at' => (string)$S]), 'admin'), 'a time as text: online');
eq('online', rh_maint_decide($S, $w(['id' => 0]), 'admin'), 'no id: online');
eq('online', rh_maint_decide($S, $w(['ends_at' => null]), 'admin'), 'no end: online');
eq('online', rh_maint_decide($S, $w(['ends_at' => $S]), 'admin'), 'end equal to start: online');
eq('online', rh_maint_decide($S, $w(['ends_at' => $S - 10]), 'admin'), 'end before start: online');
eq('online', rh_maint_decide($A, $w(['announce_from' => $S + 1]), 'admin'), 'notice after the start: online');
eq('offline', rh_maint_decide($S, $w(['ends_at' => $S + 259200]), 'admin'), 'exactly 72 hours: obeyed');
eq('online', rh_maint_decide($S, $w(['ends_at' => $S + 259201]), 'admin'), 'over 72 hours: ignored (fails open)');
eq('online', rh_maint_decide($S, $w(['announce_from' => $S - 2592001]), 'admin'), 'notice more than 30 days ahead: ignored');
eq('online', rh_maint_decide($S, $w(['message' => str_repeat('x', 4001)]), 'admin'), 'an oversized message: ignored');
eq('offline', rh_maint_decide($S, $w(['message' => null]), 'admin'), 'no message: still obeyed');
eq('online', rh_maint_decide(0, $w(), 'admin'), 'no clock: online');

// ---- 2. storing, wording
eq('', rh_maint_store_value(null), 'the panel says none: cleared');
eq('', rh_maint_store_value(['id' => 1]), 'an unusable block: cleared');
$stored = rh_maint_store_value($w(['extra' => 'dropped', 'message' => '  Patch night.  ']));
eq(['id' => 7, 'scope' => 'both', 'announce_from' => $A, 'starts_at' => $S, 'ends_at' => $E, 'message' => 'Patch night.'], json_decode($stored, true), 'stored: only the known fields, message trimmed');
eq('offline', rh_maint_decide($S, json_decode($stored, true), 'admin'), 'the stored copy decides the same');
eq(null, rh_maint_read(null), 'no database: nothing held');

$t = rh_maint_banner_text($w(), $A + 10);
check(strpos($t, 'Scheduled maintenance: ') === 0 && strpos($t, date('D j M Y, H:i', $S) . '–' . date('H:i', $E)) !== false && substr($t, -12) === 'Patch night.',
    'notice: date, from–to, then the message', $t);
check(strpos($t, 'the staff system and the guest website will be offline') !== false, 'notice says what goes offline', $t);
$long = rh_maint_banner_text($w(['ends_at' => $S + 86400]), $A + 10);
check(strpos($long, ' to ' . date('D j M Y, H:i', $S + 86400)) !== false, 'a window past midnight names both days', $long);
check(strpos(rh_maint_banner_text($w(['scope' => 'site']), $A + 10), 'the guest website will be offline') !== false, 'site-only window: staff are told too');
eq('', rh_maint_banner_text($w(), $A - 1), 'no notice before announce_from');
eq('', rh_maint_banner_text($w(), $E), 'no notice after the end');
check(strpos(rh_maint_banner_text($w(['scope' => 'site']), $S + 5), 'The guest website is offline for maintenance until') === 0, 'site window running: a short line for staff');
check(strpos(rh_maint_banner_text($w(), $S + 5), 'The system is offline for maintenance until') === 0, 'admin window running (if a page is somehow shown): a short line');
eq('', rh_maint_banner_text(['id' => 'x'], $S), 'bad data: no notice');
$gm = rh_maint_site_message(rh_maint_clean($w()));
check(strpos($gm, 'Patch night.') === 0 && strpos($gm, 'We expect to be back by ' . date('D j M Y, H:i', $E)) !== false, 'guest website: message and when it is back', $gm);
check(strpos(rh_maint_site_message(rh_maint_clean($w(['message' => '']))), 'temporarily unavailable') !== false, 'guest website: plain words when the panel sent none');

// the 503 a staff request gets (a child process, since it exits)
$child = static function (string $code) use ($root): string {
    $f = tempnam(sys_get_temp_dir(), 'rhm');
    file_put_contents($f, "<?php date_default_timezone_set('Africa/Blantyre'); require '" . addslashes($root) . "/includes/licence-maintenance.php'; " . $code);
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f));
    @unlink($f);
    return $out;
};
$blk = var_export($w(['message' => 'Patch <night> & more.']), true);
$json = json_decode($child("rh_maint_admin_respond($blk, $S + 60, false); echo 'NOT-EXITED';"), true);
check(is_array($json) && $json['success'] === false && $json['maintenance'] === true && $json['back_at'] === $E
    && strpos($json['error'], 'offline for scheduled maintenance until ' . date('D j M Y, H:i', $E)) !== false, 'JSON refusal: says so, when it is back, and stops there');
$page = $child("rh_maint_admin_respond($blk, $S + 60, true); echo 'NOT-EXITED';");
check(strpos($page, 'Scheduled maintenance</h1>') !== false && strpos($page, 'Patch &lt;night&gt; &amp; more.') !== false
    && strpos($page, 'Back online at ' . date('D j M Y, H:i', $E)) !== false && strpos($page, 'href="logout.php"') !== false && strpos($page, 'NOT-EXITED') === false,
    'the page: the message escaped, back online at, sign out, and nothing runs after it');
eq('', $child("rh_maint_admin_enforce('admin', 'dashboard.php'); echo '';"), 'command line: never refused');

// ---- 3. the files
$src = static function (string $p) use ($root): string { return (string)file_get_contents($root . '/' . $p); };
$lic = $src('includes/licence.php');
$guard = $src('includes/licence-guard.php');
$db = $src('config/database.php');
$m = $src('includes/licence-maintenance.php');
check(strpos($lic, "'backup_seen_at', 'maintenance'], true)") !== false, 'licence_maintenance is one of the writable licence settings (clears the settings cache)');
check((bool)preg_match("/function rh_licence_store_accepted.*?array_key_exists\('maintenance', \\\$payload\).*?rh_maint_store_value/s", $lic), 'stored only from a verified, accepted answer');
check(strpos($lic, "\$req['maintenance_seen']") !== false, 'the check-in tells the panel which window it holds');
check(strpos($m, "SELECT setting_value FROM site_settings WHERE setting_key = ?") !== false && strpos($m, 'getSetting(\'licence_maintenance') === false, 'read fresh from the database, never the settings file cache');
check((bool)preg_match("/rh_maint_decide\(time\(\), rh_maint_read\(.*?'admin'\) === 'offline'\) \{\s*rh_licence_maybe_check_after_response\(300\);\s*rh_maint_admin_enforce/s", $guard),
    'the guard: during the window a check-in runs at least every 5 minutes after the reply, then the request is refused');
check(strpos($lic, 'rh_licence_check_now(!$soon, $detached ? 6 : 3, $soon ? $everySeconds : 0)') !== false, 'and that sooner check is not skipped as "not due"');
check((bool)preg_match('/\$minGap > 0 && \$now - \(int\)\(\$stored\[\'last_check_at\'\] \?\? 0\) < \$minGap/', $lic), 'requests that all saw "due" at once make one call to the panel (gap re-checked under the lock)');
check(strpos($guard, 'rh_maint_banner_text(') !== false, 'the banner shows the notice');
check(strpos($m, "\$script === 'logout.php'") !== false, 'sign-out stays open');
check(strpos($m, "http_response_code(503)") !== false && strpos($m, 'Retry-After') !== false, 'a 503 with Retry-After (the till\'s offline queue keeps a sale on 5xx)');
check(strpos($src('admin/includes/offline-queue.js'), 'resp.status >= 500') !== false, 'the offline queue keeps an item on a server error');
check(strpos($db, 'rh_maint_site_offline_block()') !== false && strpos($db, "if (!rh_setting_enabled('site_maintenance_enabled', false)) {") !== false, 'guest gate: panel window ORed with the hotel\'s own switch');
check(!preg_match('/site_maintenance_enabled[\'"]\s*,\s*[\'"]?1/', $m . $lic . $guard), 'the hotel\'s own switch is never written');
check(strpos($src('api/licence-ping.php'), 'rh_licence_guard_request') === false && (bool)preg_match('#\(admin\|api\|api_key\|vendor\|scripts\)#', $db),
    'the panel\'s nudge is never blocked (api/ is outside the guest gate and has no staff guard)');
check(strpos($m, 'PHP_SAPI === \'cli\'') !== false, 'command-line jobs never blocked');
check(strpos($m, 'require') === false, 'licence-maintenance.php loads nothing (safe on every guest page)');

echo "licence_maintenance: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
