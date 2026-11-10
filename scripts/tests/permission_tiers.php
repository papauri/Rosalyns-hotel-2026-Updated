<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Permission tiers (owner 2026-11-11). Pure: no database, no network, no email. Loads only admin/includes/permissions.php
 * (function definitions, nothing runs on load) and calls the pure rule rh_permission_resolve() plus the tier lists.
 * Usage (from the repo root): php scripts/tests/permission_tiers.php
 *  1. the tier lists: Tier S inside Tier A, view_only in neither, the new keys exist with no page, managers lose edit_templates;
 *  2. the rule: super admin all; plain admin Tier S off while a super admin exists, on in the fallback (count 0), on when a
 *     super admin switched it on; a super admin's deny wins; non-admins never get Tier A, even with a saved "granted" row;
 *  3. the files: hasPermission/getUserPermissions both call the rule; the risky screens check the new keys server-side.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } }
function eq($want, $got, string $l): void { check($want === $got, $l, 'expected ' . var_export($want, true) . ' got ' . var_export($got, true)); }

require $root . '/admin/includes/permissions.php';

$S = rh_super_only_permissions();
$A = rh_admin_only_permissions();
$all = getAllPermissions();
$mgr = getDefaultPermissionsForRole('manager');
$rcp = getDefaultPermissionsForRole('receptionist');

// ---- 1. lists
foreach ($S as $k) {
    check(in_array($k, $A, true), "Tier S key $k is also administrators-only");
}
foreach ($A as $k) {
    check(isset($all[$k]), "tier key $k exists in getAllPermissions");
}
check(!in_array('view_only', $S, true) && !in_array('view_only', $A, true), 'view_only is in neither tier');
foreach (['email_server_settings', 'backup_restore', 'site_switches'] as $k) {
    check(isset($all[$k]) && $all[$k]['page'] === '' && $all[$k]['category'] === 'Settings', "new key $k: Settings, not a page");
    check(!preg_match("/'[a-z0-9_-]+\.php'\s*=>\s*'$k'/", file_get_contents($root . '/admin/includes/permissions.php')), "new key $k owns no page");
}
check(in_array('email_server_settings', $S, true) && in_array('backup_restore', $S, true), 'email server and restore are super admin only');
check(in_array('site_switches', $A, true) && !in_array('site_switches', $S, true), 'site switches: administrators only, not super only');
check(!in_array('edit_templates', $mgr, true), 'managers no longer get edit_templates by default');
check(in_array('booking_settings', $mgr, true) && in_array('backup_management', $mgr, true), 'managers keep Hotel Settings and Backups');
foreach (getAllRoles() as $rk => $rd) {
    if ($rk === 'admin') {
        continue;
    }
    check(array_intersect(getDefaultPermissionsForRole($rk), $A) === [], "role $rk has no administrators-only key by default");
}

// ---- 2. the rule
$r = 'rh_permission_resolve';
foreach (array_merge($A, ['bookings', 'accounting']) as $k) {
    check($r('admin', true, $k, null, [], [], [], 1), "super admin has $k");
}
eq(false, $r('admin', true, 'view_only', true, [], [], [], 1), 'super admin is never view only');
foreach ($S as $k) {
    eq(false, $r('admin', false, $k, null, [], [], [], 1), "plain admin: $k off while a super admin exists");
    eq(true, $r('admin', false, $k, null, [], [], [], 0), "plain admin: $k on when no super admin exists (fallback)");
    eq(true, $r('admin', false, $k, null, [], [], [$k => true], 2), "plain admin: $k on when a super admin switched it on");
    eq(false, $r('admin', false, $k, null, [], [$k => true], [], 0), "plain admin: a super admin's deny on $k holds in the fallback too");
}
foreach (array_diff($A, $S) as $k) {
    eq(true, $r('admin', false, $k, null, [], [], [], 1), "plain admin keeps administrators-only key $k");
}
eq(false, $r('admin', false, 'finance_settings', null, [], ['finance_settings' => true], [], 1), 'deny wins for a plain admin');
eq(false, $r('admin', false, 'bookings', null, [], ['bookings' => true], ['bookings' => true], 1), 'deny wins over a grant');
eq(true, $r('admin', false, 'view_only', true, [], [], [], 1), 'plain admin view only when ticked');
eq(false, $r('admin', false, 'view_only', null, [], [], [], 1), 'plain admin not view only by default');
eq(false, $r('manager', false, 'edit_templates', true, $mgr, [], [], 1), 'manager with a saved edit_templates=1 row: refused');
eq(false, $r('manager', false, 'edit_templates', null, $mgr, [], [], 1), 'manager by default: no edit_templates');
eq(false, $r('receptionist', false, 'finance_settings', true, $rcp, [], [], 0), 'receptionist with a saved finance_settings=1 row: refused');
eq(false, $r('manager', false, 'user_management', true, $mgr, [], [], 0), 'manager with a saved user_management=1 row: refused');
eq(false, $r('manager', false, 'api_keys', true, $mgr, [], [], 0), 'manager never gets Tier S, even in the fallback');
eq(false, $r('manager', false, 'site_switches', true, $mgr, [], [], 0), 'manager never gets site switches');
eq(false, $r('manager', false, 'module_settings', true, $mgr, [], ['module_settings' => true], 0), 'super grants never apply below administrator');
eq(true, $r('manager', false, 'booking_settings', null, $mgr, [], [], 1), 'manager keeps Hotel Settings by default');
eq(false, $r('manager', false, 'booking_settings', false, $mgr, [], [], 1), 'a saved off row still removes a default');
eq(true, $r('receptionist', false, 'refund_payment', true, $rcp, [], [], 1), 'a saved on row still adds an ordinary key');
eq(true, $r('manager', false, 'view_only', true, $mgr, [], [], 1), 'view only ticked on a manager');
eq(false, $r('manager', false, 'view_only', null, $mgr, [], [], 1), 'view only off by default');

// Tier S off is never a "limit" (it would make every plain administrator look limited and lock role changes).
eq(['bookings' => true], rh_admin_real_limits(['denies' => ['api_keys' => true, 'bookings' => true, 'backup_restore' => true]]), 'real limits leave Tier S keys out');
eq([], rh_admin_real_limits(['denies' => []]), 'no denies: no limits');

// ---- 3. files
$p = file_get_contents($root . '/admin/includes/permissions.php');
check(strpos($p, "if (!\$granted && in_array((string)\$key, \$superOnly, true)) {\n                continue;") !== false, 'rh_set_admin_permissions stores Tier S off as no row, never a deny');
check(strpos($p, "if (in_array((string)\$key, \$adminOnly, true)) {\n                \$permissions[\$key] = \$granted = false;") !== false, 'setUserPermissions stores administrators-only keys as off for non-admins');
check(strpos($p, "if (\$target_role === 'admin' && rh_super_admins_in_charge()) {\n                return false;") !== false, 'canManageUser: plain admins cannot manage administrators while a super admin exists');
check(strpos($p, "return rh_super_admins_in_charge() || rh_admin_has_limits(\$user_id);") !== false, 'plain admins get link stripping once a super admin exists');
check(substr_count($p, 'rh_permission_resolve(') >= 6, 'hasPermission, getUserPermissions and the admin change log use the one rule');
$bs = file_get_contents($root . '/admin/booking-settings.php');
foreach (['toggle_booking_system', 'disable_booking', 'booking_disabled_action', 'toggle_site_maintenance', 'save_site_maintenance_message', 'save_booking_reference_prefix'] as $post) {
    check((bool)preg_match("/isset\(\\\$_POST\['$post'\]\)\) \{\s*\n\s*if \(!\\\$canSiteSwitches\)/", $bs), "booking-settings: $post refuses without site_switches");
}
check((bool)preg_match("/isset\(\\\$_POST\['service_channel_settings'\]\)\) \{\s*if \(!\\\$canSiteSwitches\)/", $bs), 'booking-settings: service channels refuse without site_switches');
check(strpos($bs, "validateCsrfToken((string)(\$_GET['csrf_token'] ?? '')) && \$canSiteSwitches") !== false, 'booking-settings: the ?enable link needs site_switches too');
check(strpos($bs, "\$required_fields = \$canEmailServer ?") !== false, 'booking-settings: without email_server_settings the SMTP fields are not checked or saved');
check(strpos($bs, "hasPermission((int)(\$user['id'] ?? 0), 'site_switches')") !== false, 'booking-settings: site_switches read from the database');
check(strpos($bs, "hasPermission((int)(\$user['id'] ?? 0), 'email_server_settings')") !== false, 'booking-settings: email server needs email_server_settings');
$bm = file_get_contents($root . '/admin/backup-management.php');
check(strpos($bm, "\$action === 'restore' && \$canRestore") !== false, 'backups: restore needs backup_restore');
$ak = file_get_contents($root . '/admin/api-keys.php');
check(strpos($ak, "hasPermission((int)(\$_SESSION['admin_user_id'] ?? 0), 'api_keys')") !== false, 'API keys page checks the permission, not the session role');
$um = file_get_contents($root . '/admin/user-management.php');
check(substr_count($um, '!$rh_editor_can_assign_admin') >= 3, 'Users page: making someone an administrator needs a super admin (add, edit, bulk)');

echo "permission_tiers: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
