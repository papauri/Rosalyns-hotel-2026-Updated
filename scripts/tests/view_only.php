<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * View only (owner 2026-11-10, includes/view-only.php). Pure: no database, no network.
 * Usage (from the repo root): php scripts/tests/view_only.php
 *  1. what a view-only person may still send (reads, sign-out, own password, search, presence) and what is refused
 *     (every change, including check-out, payments and backups that the licence's read-only step allows);
 *  2. when a free trial makes everyone view only (verified answer, trial on, trial_view_only true);
 *  3. the "view_only" permission exists and no role has it by default;
 *  4. the files: the guard runs it for every staff request, the header prints the banner and the greying.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } }

require $root . '/includes/licence-guard.php';
require $root . '/admin/includes/permissions.php';

$A = static fn(string $area, string $script, string $method, array $get = [], array $post = []): bool => rh_view_only_allowed($area, $script, $method, $get, $post);

// ---- 1. requests
check($A('admin', 'bookings.php', 'GET'), 'pages open (GET)');
check($A('admin', 'bookings.php', 'GET', ['export' => 'csv']), 'exports open');
check(!$A('admin', 'bookings.php', 'POST', [], ['action' => 'checkout']), 'check-out refused (read-only allows it, view only does not)');
check(rh_licence_write_allowed('admin', 'bookings.php', 'POST', [], ['action' => 'checkout']), 'the read-only step itself is unchanged (still allows check-out)');
check(!$A('admin', 'payment-add.php', 'POST', [], ['amount' => '10']), 'recording a payment refused');
check(!$A('admin', 'create-booking.php', 'POST', [], ['guest_name' => 'x']), 'a new booking refused');
check(!$A('admin', 'backup-management.php', 'POST', [], ['action' => 'run_backup']), 'running a backup refused');
check(!$A('admin', 'pos.php', 'GET', ['ajax' => 'toggle_item']), 'the till 86 toggle (a change sent as GET) refused');
check($A('admin', 'bookings.php', 'POST', [], ['action' => 'get_booking']), 'a read sent as POST passes');
check($A('admin', 'change-password.php', 'POST', [], ['new_password' => 'x']), 'changing one\'s own password passes');
check($A('admin_api', 'presence.php', 'POST'), 'online presence passes');
check($A('admin_api', 'global-search.php', 'POST'), 'search passes');
check(!$A('admin_api', 'ai-action.php', 'POST', [], ['action' => 'confirm']), 'the Assistant\'s confirmed actions refused');
check(!$A('admin_api', 'user-permissions.php', 'POST', [], ['action' => 'save']), 'changing permissions refused');
check(!$A('station', 'kds-action.php', 'GET', ['action' => 'bump_ticket']), 'a kitchen bump (sent as GET) refused');
check(!$A('station', 'void-order.php', 'POST', [], ['order_id' => '5']), 'voiding an order refused');
check($A('station', 'pos-notifications.php', 'POST'), 'till notifications pass');
check($A('other', 'index.php', 'POST'), 'the guest website is never touched');

// ---- 2. view-only free trial
$st = static fn(array $p, string $reason = 'ok'): array => ['reason' => $reason, 'payload' => $p];
$trial = ['trial' => true, 'trial_until' => '2026-12-01', 'trial_view_only' => true];
check(rh_view_only_trial_on($st($trial)), 'a verified view-only trial makes everyone view only');
check(!rh_view_only_trial_on($st(['trial_view_only' => false] + $trial)), 'trial with view only switched off: normal');
check(!rh_view_only_trial_on($st(array_diff_key($trial, ['trial_view_only' => 1]))), 'an older panel that does not send it: normal');
check(!rh_view_only_trial_on($st(['trial' => false] + $trial)), 'not a trial: normal');
check(!rh_view_only_trial_on($st(['trial_view_only' => 'yes'] + $trial)), 'only a real true counts');
check(!rh_view_only_trial_on($st($trial, 'bad_signature')), 'an answer that does not verify never restricts anyone');
check(rh_view_only_reason() === '', 'command line: never view only');

// ---- 3. the permission
$all = getAllPermissions();
check(isset($all['view_only']) && ($all['view_only']['category'] ?? '') === 'Core', 'permission "view_only" exists (Core)');
check(($all['view_only']['page'] ?? 'x') === '', 'it is not a page (never opens or maps a page)');
$roles = [];
foreach (array_keys(getAllRoles()) as $r) {
    if ($r !== 'admin' && in_array('view_only', getDefaultPermissionsForRole($r), true)) { $roles[] = $r; }
}
check($roles === [], 'no role has view only by default', implode(', ', $roles));
check(array_key_last($all) === 'view_only', 'listed last (never the first page a person is sent to)');

// ---- 4. the files
$guard = (string)file_get_contents($root . '/includes/licence-guard.php');
$fn = substr($guard, (int)strpos($guard, 'function rh_licence_guard_request'));
check(strpos($fn, 'rh_view_only_guard();') !== false && strpos($fn, 'rh_view_only_guard();') < strpos($fn, '!rh_licence_enabled()'),
    'the guard applies view only for every staff request, licensed or not');
check(strpos((string)file_get_contents($root . '/admin/includes/admin-header.php'), 'rh_view_only_render();') !== false, 'the admin header prints the banner and greying');
$vo = (string)file_get_contents($root . '/includes/view-only.php');
check(strpos($vo, "permission_key = 'view_only'") !== false && strpos($vo, 'is_granted') !== false, 'a person is view only only when the permission is explicitly ticked');

echo "view_only: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
