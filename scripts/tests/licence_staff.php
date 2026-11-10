<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Licence modules and staff changes from the licence panel (owner 2026-11-10). Pure: no database, no network, no email.
 * Usage (from the repo root): php scripts/tests/licence_staff.php
 *  1. which modules a licence allows (no list = nothing limited, finance always on, other switches never limited);
 *  2. the staff changes read from a verified answer (malformed ones dropped), which still need running (newer than the last
 *     one run, only from a fresh answer), when the staff report goes, where it goes, and the results kept until confirmed;
 *  3. the files: the gates use the licence for administrators too, the guard signs out deactivated accounts first, the
 *     staff code never reads a password or sends one, and module licensing stays off the guest website.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } }
function eq($want, $got, string $l): void { check($want === $got, $l, 'expected ' . var_export($want, true) . ' got ' . var_export($got, true)); }

require $root . '/includes/licence-staff.php';

// ---- 1. modules
eq(true, rh_licence_module_in(null, 'gym'), 'no licensed list: nothing is limited');
eq(false, rh_licence_module_in(['bookings', 'pos'], 'gym'), 'a module not listed is off');
eq(true, rh_licence_module_in(['bookings'], 'bookings'), 'a listed module is on');
eq(true, rh_licence_module_in([], 'finance'), 'finance is always on');
eq(true, rh_licence_module_in([], 'restaurant_page'), 'switches the licence does not control are never limited');
eq(false, rh_licence_module_in(['pos'], 'station_kds'), 'a station needs its own licence');
eq(false, rh_licence_module_in(['bookings'], 'ai_assistant'), 'the Assistant follows the licence too');
eq(true, rh_licence_module_in([], 'bookings'), 'an empty list limits nothing (a slip on the panel never switches a hotel off)');
eq(13, count(rh_licence_module_keys()), 'the 13 module keys the panel sells');
eq(true, rh_licence_module_allowed('gym'), 'licensing off in this test run: everything allowed');

// ---- 2. staff changes
$payload = ['issued_at' => 1000, 'staff_commands' => [
    ['id' => 5, 'kind' => 'role', 'username' => ' jbanda ', 'user_id' => 2, 'data' => ['role' => 'manager']],
    ['id' => 3, 'kind' => 'deactivate', 'username' => 'old', 'user_id' => 9, 'data' => []],
    ['id' => 4, 'kind' => 'drop_table', 'username' => 'x', 'data' => []],
    ['id' => '6', 'kind' => 'activate', 'username' => 'x'],
    ['id' => 7, 'kind' => 'activate', 'username' => ''],
    ['id' => 8, 'kind' => 'invite', 'username' => str_repeat('a', 101)],
    ['id' => 9, 'kind' => 'create', 'username' => 'newbie', 'data' => 'not an object'],
    'junk',
]];
$cmds = rh_licence_staff_commands($payload);
eq([3, 5, 9], array_column($cmds, 'id'), 'only well-formed changes, oldest first');
eq('jbanda', $cmds[1]['username'], 'username trimmed');
eq([], $cmds[2]['data'], 'data that is not an object becomes empty');
eq(0, $cmds[2]['user_id'], 'missing user id = 0');
eq([], rh_licence_staff_commands(null), 'no payload, no changes');
eq([], rh_licence_staff_commands(['staff_commands' => 'x']), 'not a list, no changes');

eq([5, 9], array_column(rh_licence_staff_todo($cmds, 3, 1000, 1500), 'id'), 'only changes newer than the last one run');
eq([], rh_licence_staff_todo($cmds, 0, 1000, 1000 + 601), 'an answer older than 10 minutes never replays changes');
eq([], rh_licence_staff_todo($cmds, 0, 0, 10), 'no issued time, nothing runs');
eq([], rh_licence_staff_todo($cmds, 9, 1000, 1000), 'all run already');

eq(false, rh_licence_staff_report_due(false, false, 0, 0, 'h', 'h', 1000, 1000 + 3600), 'nothing new: no report');
eq(true, rh_licence_staff_report_due(true, false, 0, 0, 'h', 'h', 1000, 1001), 'after changes: report');
eq(true, rh_licence_staff_report_due(false, true, 0, 0, 'h', 'h', 1000, 1001), 'results waiting: report');
eq(true, rh_licence_staff_report_due(false, false, 50, 40, 'h', 'h', 1000, 1001), 'the panel asked for a fresh list: report');
eq(false, rh_licence_staff_report_due(false, false, 50, 50, 'h', 'h', 1000, 1001), 'a request already answered: no report');
eq(true, rh_licence_staff_report_due(false, false, 0, 0, 'new', 'old', 1000, 1001), 'the list changed: report');
eq(true, rh_licence_staff_report_due(false, false, 0, 0, 'h', 'h', 1000, 1000 + 86400), 'once a day anyway');

eq('https://licence.example.com/api/hms-staff.php', rh_licence_staff_report_url('https://licence.example.com/api/licence.php'), 'report address next to the licence address');
eq('', rh_licence_staff_report_url('https://licence.example.com/api/licence.php?x=1'), 'odd licence address: no report');
eq('', rh_licence_staff_report_url(''), 'no licence address: no report');

$kept = rh_licence_staff_merge_results([['id' => 3, 'ok' => true, 'message' => '']], [['id' => 5, 'ok' => false, 'message' => 'last admin']]);
eq([3, 5], array_column($kept, 'id'), 'results kept until confirmed');
eq([5], array_column(rh_licence_staff_merge_results($kept, [], [3]), 'id'), 'confirmed results dropped');
$many = [];
for ($i = 1; $i <= 60; $i++) { $many[] = ['id' => $i, 'ok' => true, 'message' => '']; }
eq(50, count(rh_licence_staff_merge_results([], $many)), 'at most 50 kept');
eq(11, rh_licence_staff_merge_results([], $many)[0]['id'], 'the newest are kept');

// ---- 3. the files
$src = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
check(strpos($src('admin/admin-init.php'), "rh_module_licence_allows((string)\$_requiredModuleKey)") !== false, 'page gate: administrators follow the licence');
check(strpos($src('admin/includes/permissions.php'), '$isAdmin ? rh_module_licence_allows(') !== false, 'API gate: administrators follow the licence');
check(strpos($src('admin/includes/permissions.php'), "\$role === 'admin' ? rh_module_licence_allows(") !== false, 'second-surface gate: administrators follow the licence');
check(strpos($src('config/database.php'), '&& rh_module_licence_allows($module)') !== false, 'moduleEnabled() applies the licence ceiling');
check((bool)preg_match("/function rh_module_licence_allows.*?PHP_SAPI === 'cli' \\|\\| !isset\\(\\\$_SESSION\\).*?empty\\(\\\$_SESSION\\['admin_user_id'\\]\\)/s", $src('config/database.php')),
    'the licence ceiling applies only to signed-in staff (never the guest website or cron jobs)');
$guard = $src('includes/licence-guard.php');
check(strpos($guard, "rh_staff_session_guard(); // first, and whether or not licensing is on") !== false
    && strpos($guard, "rh_staff_session_guard();") < strpos($guard, "if (PHP_SAPI === 'cli' || !rh_licence_enabled())", strpos($guard, 'function rh_licence_guard_request')),
    'the guard signs out deactivated accounts before anything else, licensed or not');
$staffSrc = $src('includes/licence-staff.php');
check(strpos($staffSrc, 'password_hash, full_name') === false || strpos($staffSrc, 'rh_staff_invite_placeholder_hash()') !== false, 'new accounts get only an unusable placeholder password');
check(!preg_match('/SELECT[^;]*password_hash[^;]*FROM admin_users/i', $staffSrc), 'the staff report never reads a password hash');
check(strpos($staffSrc, "'last active administrator") !== false || strpos($staffSrc, 'This is the last active administrator.') !== false, 'the last active administrator is protected');
check(strpos($src('includes/licence.php'), 'rh_licence_staff_after_check(); // staff changes from the panel') !== false, 'staff changes run after the reply on the hourly check');
check(substr_count($src('includes/licence.php'), 'rh_licence_staff_after_check();') === 2, 'and after the panel\'s nudge');
check(substr_count($src('includes/licence.php'), "!empty(\$chk['ok'])") === 2, 'staff changes run only after a check-in that succeeded');
check(strpos($staffSrc, "\$GLOBALS['rh_staff_invite_base_url'] = \$base;") !== false && strpos($src('admin/includes/staff-invite-lib.php'), "\$GLOBALS['rh_staff_invite_base_url']") !== false,
    'provider invitations link to the site\'s own address, never the request Host header');
check(substr_count($staffSrc, 'rh_staff_invite_send(') === 1, 'every provider invitation goes through that one helper');
check(strpos($staffSrc, '$username = rh_clean_text(') !== false, 'new staff details are cleaned as on the Users page');
check(strpos($src('includes/licence.php'), "'staff_done_id'") !== false, 'the check-in tells the panel the last change run');

echo "licence_staff: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
