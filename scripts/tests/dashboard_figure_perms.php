<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Dashboard money figures (owner 2026-11-11): one permission per money card, defaults administrator + accountant only.
 * Pure: no database, no network, no email. Loads only admin/includes/permissions.php (function definitions) and reads
 * admin/dashboard.php as text. Usage (from the repo root): php scripts/tests/dashboard_figure_perms.php
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } }

require $root . '/admin/includes/permissions.php';

$keys = ['dashboard_takings', 'dashboard_owed', 'dashboard_pos_sales'];
$all  = getAllPermissions();
$cats = getNavCategories();
$r    = 'rh_permission_resolve';

// ---- 1. definitions
foreach ($keys as $k) {
    check(isset($all[$k]), "$k is defined");
    check(($all[$k]['label'] ?? '') !== '' && stripos((string)($all[$k]['description'] ?? ''), 'money standing') !== false, "$k has a label and a plain helper");
    check(($all[$k]['category'] ?? '') === 'Dashboard figures', "$k sits in Dashboard figures");
    check(isset($cats[$all[$k]['category'] ?? '']), "$k category is shown on Staff & Access");
    check(($all[$k]['page'] ?? null) === '', "$k owns no page");
    check(isset(getPermissionGroups()[$all[$k]['group'] ?? '']), "$k group exists");
    check(!in_array($k, rh_admin_only_permissions(), true), "$k is not administrators-only (accountants need it)");
}

// ---- 2. role defaults through the one rule (no saved row)
foreach ($keys as $k) {
    check($r('accountant', false, $k, null, getDefaultPermissionsForRole('accountant'), [], [], 0), "accountant has $k by default");
    check($r('admin', false, $k, null, getDefaultPermissionsForRole('admin'), [], [], 1), "plain admin has $k");
    check($r('admin', true, $k, null, [], [], [], 1), "super admin has $k");
    foreach (['manager', 'viewer', 'receptionist', 'restaurant_staff', 'room_service', 'housekeeping', 'chef', 'bar_staff', 'coffee_staff', 'gym_staff', 'conference_staff', 'unknown_role'] as $role) {
        check(!$r($role, false, $k, null, getDefaultPermissionsForRole($role), [], [], 0), "$role does not have $k by default");
    }
    // Granular: a saved row wins either way.
    check($r('manager', false, $k, true, getDefaultPermissionsForRole('manager'), [], [], 0), "a manager given $k sees it");
    check(!$r('accountant', false, $k, false, getDefaultPermissionsForRole('accountant'), [], [], 0), "an accountant with $k switched off does not see it");
}

// ---- 3. the Dashboard follows the keys
$d = file_get_contents($root . '/admin/dashboard.php');
check(strpos($d, "\$pos_sales       = \$mod_pos && \$dash_perm('dashboard_pos_sales');") !== false, 'till sales follow dashboard_pos_sales');
check(strpos($d, "\$show_takings    = moduleEnabled('finance') && \$dash_perm('dashboard_takings');") !== false, 'takings follow dashboard_takings');
check(strpos($d, "\$show_owed       = moduleEnabled('finance') && \$dash_perm('dashboard_owed');") !== false, 'owed follows dashboard_owed');
check(strpos($d, "\$pos_rev_needed  = \$raw_pos && (\$pos_sales || \$show_takings);") !== false, 'takings total still includes the till');
check(strpos($d, "'outstanding_balances'      => \$show_owed && \$mod_receivables,") !== false, 'owed insight follows dashboard_owed');
check(strpos($d, "'total_revenue_today'       => \$show_takings,") !== false, 'takings insight follows dashboard_takings');
check(strpos($d, "'restaurant_revenue_today'  => \$pos_sales,") !== false, 'till insight follows dashboard_pos_sales');
check(strpos($d, "if (\$show_owed && \$mod_receivables) { // owner 2026-11-11") !== false, 'operations overview owed row follows dashboard_owed');
check(strpos($d, "<?php if (\$show_takings): ?>\n                <a class=\"ck-kpi js-dashboard-insight\" data-insight-card=\"total_revenue_today\"") !== false, 'Takings today card follows dashboard_takings');
check((bool)preg_match('/<\?php if \(\$show_owed\): \?>\s*<\?php if \(\$mod_receivables\): \?>\s*<a class="ck-kpi[^"]*"[^>]*data-insight-card="outstanding_balances"/', $d), 'Owed to us card follows dashboard_owed');
check(strpos($d, "\$hasMoney = \$show_takings || \$pos_sales || \$moneyRefundLine;") !== false, 'Money panel: takings, till or refunds queue');
check(strpos($d, "if (\$show_takings) { // the 7-day chart") !== false, '7-day chart read only with dashboard_takings');
check(strpos($d, "<?php if (\$mod_finance && \$can('accounting-dashboard.php')): ?>") !== false, 'Accounting button unchanged');
check(strpos($d, "\$add(\$attn, \$mod_finance && \$dash_perm('payments', 'refund_payment'), (int)\$finance['refunds_pending']") !== false, 'refunds to approve unchanged');
check(strpos($d, "'refunds_pending'           => \$mod_finance && \$dash_perm('payments', 'refund_payment'),") !== false, 'refunds insight has the same gate as its line');
check(substr_count($d, "\$mod_finance && \$mod_receivables") === 0, 'no owed figure left on the old finance gate');

echo "dashboard_figure_perms: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
