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
$d = str_replace("\r\n", "\n", (string)file_get_contents($root . '/admin/dashboard.php')); // CRLF checkouts (Liwonde laptop) match too
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
// "Since you were last here" (first-login plan B2): the payments-received item is read only with dashboard_takings.
check(strpos($d, "rh_dash_since_counts(\$pdo, \$heroSince, ['bookings' => \$mod_bookings, 'payments' => \$show_takings,") !== false, 'since line: payments received follows dashboard_takings');
$sinceSrc = (string)file_get_contents($root . '/admin/includes/dashboard-since-data.php');
check(strpos($sinceSrc, "if (!empty(\$show['payments'])) {") !== false && substr_count($sinceSrc, 'rh_fin_payments_where(') === 1, 'since counts: payments read only behind the payments gate');
check(strpos($d, "<?php if (\$mod_finance && \$can('accounting-dashboard.php')): ?>") !== false, 'Accounting button unchanged');
// Refunds to approve (Needs attention, admin/includes/dashboard-attention.php since 2026-11-12): finance module, a finance
// page permission AND payments / refund_payment, as before; the popup rows only for users who can open Payments.
require_once $root . '/admin/includes/dashboard-attention.php';
$refDef = rh_dash_attention_defs()['refunds_pending'] ?? [];
check(($refDef['when'] ?? null) === [['modules' => ['finance'], 'perms' => [['accounting', 'payments', 'reports'], ['payments', 'refund_payment']]]], 'refunds to approve unchanged');
check(!empty($refDef['insight_needs_link']) && strpos($d, "'refunds_pending'        => (int)\$finance['refunds_pending'],") !== false, 'refunds count + popup only with the Payments link');
check(strpos($d, "'refunds_pending'           => \$attnGate['refunds_pending'] && \$dash_can_open_payments,") !== false, 'refunds insight has the same gate as its line');
check(substr_count($d, "\$mod_finance && \$mod_receivables") === 0, 'no owed figure left on the old finance gate');

// ---- 4. B4 (2026-11-24): donut centre = rooms free tonight; Money headline = the 7-day total, inside the takings gate
check(strpos($d, "<strong><?php echo (int)\$outlookTonightRow['free']; ?></strong><small>free tonight</small>") !== false, 'donut centre prints the free-tonight figure');
check((bool)preg_match("/<\?php if \(!empty\(\\\$roomOutlook\['ok'\]\) && is_array\(\\\$outlookTonightRow\)\): \?>.*?free tonight.*?<\?php else: \?>.*?%<\/strong><small>occupied now<\/small>/s", $d), 'donut falls back to occupancy % when the outlook is unreadable');
$mh = strpos($d, '<span class="ck-money__label" data-ck-trend-label>Taken this week</span>');
$mv = strpos($d, '<span class="ck-money__value" data-ck-trend-value><?php echo $money($trendTotal); ?></span>');
check($mh !== false && $mv !== false && $mv > $mh, 'Money headline: "Taken this week" + $trendTotal');
$gate = strrpos(substr($d, 0, (int)$mh), '<?php if ($show_takings): ?>');
$gateEnd = strpos($d, '<?php endif; ?>', (int)$gate);
check($gate !== false && $gateEnd !== false && $gateEnd > $mv, 'Money headline sits inside if ($show_takings)');
check(strpos($d, "' is-today is-active'") === false, 'no bar is active at load');
check(strpos($d, "bars.addEventListener('mouseleave'") !== false && strpos($d, "bars.addEventListener('focusout'") !== false, 'leaving the bars puts the 7-day total back');
check(strpos($d, "'Last 7 days · ' . \$moneyShort(\$trendTotal)") === false, 'sub line no longer repeats the headline');

echo "dashboard_figure_perms: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
