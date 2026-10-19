<?php

/**
 * Stock Management - Budget (open-to-buy)
 *
 * One month at a time: what is budgeted per ingredient category, what has been received (spent), what is
 * already ordered (committed) and what is left to spend, with pace and a month-end forecast.
 * All the maths lives in includes/stock-budget-lib.php; this page only shows it and saves the budget form.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once 'includes/admin-modal.php';
require_once 'includes/stock-budget-lib.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);
$currency_symbol = (string)getSetting('currency_symbol');
$canManage = hasPermission((int)$user['id'], 'stock_budget_manage');

if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created.';
} elseif (!rh_sbud_ensure_schema($pdo)) {
    $error = 'The budget tables could not be prepared. Please try again or contact support.';
}

$now = rh_sbud_now();
$ym = rh_sbud_month($_REQUEST['month'] ?? '', $now);
// Keep the picker inside a sensible window (3 years back, 12 months ahead).
if ($ym < rh_sbud_shift_month($now->format('Y-m'), -36) || $ym > rh_sbud_shift_month($now->format('Y-m'), 12)) {
    $ym = $now->format('Y-m');
}

// Detail drawer data (JSON for the click-a-row panel).
if (!$error && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['detail'])) {
    $d = rh_sbud_detail($pdo, $ym, (string)$_GET['detail'], $now);
    $out = ['receipts' => [], 'pos' => []];
    foreach ($d['receipts'] as $r) {
        $out['receipts'][] = [
            'date' => date('j M', strtotime($r['created_at'])), 'name' => $r['name'], 'qty' => rtrim(rtrim(number_format((float)$r['quantity'], 3, '.', ''), '0'), '.') . ' ' . $r['unit'],
            'value' => $currency_symbol . ' ' . number_format((float)$r['cost_total'], 0),
            'po' => $r['po_reference'] ?: '', 'po_id' => (int)$r['purchase_order_id'],
        ];
    }
    foreach ($d['pos'] as $p) {
        $out['pos'][] = ['id' => (int)$p['id'], 'ref' => $p['reference'], 'status' => $p['status'], 'supplier' => $p['supplier'],
            'value' => $currency_symbol . ' ' . number_format((float)$p['outstanding'], 0)];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

$redirectTo = 'stock-budget.php?month=' . urlencode($ym);
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid.';
    } elseif (($_POST['action'] ?? '') === 'save_budgets') {
        if (!$canManage) {
            $error = 'You do not have permission to set stock budgets.';
        } else {
            $rows = [];
            $cats = $_POST['b_category'] ?? [];
            $modes = $_POST['b_mode'] ?? [];
            $vals = $_POST['b_value'] ?? [];
            $ctls = $_POST['b_control'] ?? [];
            for ($k = 0, $n = is_array($cats) ? count($cats) : 0; $k < $n; $k++) {
                $mode = ($modes[$k] ?? 'fixed') === 'target_pct' ? 'target_pct' : 'fixed';
                $rows[] = [
                    'category' => (string)$cats[$k], 'mode' => $mode,
                    'amount' => $mode === 'fixed' ? ($vals[$k] ?? '0') : 0,
                    'target_pct' => $mode === 'target_pct' ? ($vals[$k] ?? '0') : 0,
                    'control' => ($ctls[$k] ?? 'warn') === 'approval' ? 'approval' : 'warn',
                ];
            }
            $source = (string)($_POST['b_source'] ?? 'manual');
            $res = rh_sbud_save($pdo, $ym, $rows, (int)$user['id'], $source, $now);
            if ($res['ok']) {
                $message = $res['changed'] > 0 ? 'Budgets saved.' : 'No changes to save.';
            } else {
                $error = $res['error'];
            }
        }
    }
    if ($message) $_SESSION['stock_msg'] = $message;
    if ($error)   $_SESSION['stock_err'] = $error;
    header('Location: ' . $redirectTo);
    exit;
}
if (!empty($_SESSION['stock_msg'])) { $message = $_SESSION['stock_msg']; unset($_SESSION['stock_msg']); }
if (!empty($_SESSION['stock_err'])) { $error   = $_SESSION['stock_err']; unset($_SESSION['stock_err']); }

$c = null; $history = []; $auditRows = []; $categories = []; $copyProposal = []; $suggestProposal = [];
if (!$error || $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $c = rh_sbud_compute($pdo, $ym, ['now' => $now]);
        $history = rh_sbud_history($pdo, $ym, 6);
        $auditRows = rh_sbud_audit_recent($pdo, $ym, 12);
        $categories = rh_sbud_categories($pdo);
        if ($canManage && $c['info']['state'] !== 'past') {
            $copyProposal = rh_sbud_copy_proposal($pdo, $ym);
            $suggestProposal = rh_sbud_suggest_proposal($pdo, $ym);
        }
    } catch (Throwable $e) {
        error_log('stock-budget: ' . $e->getMessage());
        $error = $error ?: 'The budget could not be worked out just now. Please try again.';
    }
}

$csrf_token = generateCsrfToken();
$cur = $currency_symbol;
$money = static function (float $v) use ($cur): string { return htmlspecialchars($cur) . ' ' . number_format($v, 0); };
$pillText = ['ok' => 'On track', 'ahead' => 'Ahead of pace', 'near' => 'Nearly used', 'over' => 'Over budget', 'none' => 'No spend yet'];
$prevYm = rh_sbud_shift_month($ym, -1);
$nextYm = rh_sbud_shift_month($ym, 1);
$thisYm = $now->format('Y-m');
$canNext = $nextYm <= rh_sbud_shift_month($thisYm, 12);
$canPrev = $prevYm >= rh_sbud_shift_month($thisYm, -36);
$isPast = $c && $c['info']['state'] === 'past';

/** Render one progress bar (received + already ordered, with the "month gone" tick). */
$bar = static function (float $budget, float $spent, float $comm, float $monthPct, bool $showPace) {
    $scale = max($budget, $spent + $comm, 0.01);
    $sp = min(100, $spent / $scale * 100);
    $cm = min(100 - $sp, $comm / $scale * 100);
    $limit = $budget > 0 && ($spent + $comm) > $budget + BALANCE_TOLERANCE ? $budget / $scale * 100 : null;
    $pace = $showPace && $budget > 0 ? min(100, $monthPct / 100 * $budget / $scale * 100) : null;
    $h = '<span class="sbud-bar" aria-hidden="true"><i class="sbud-bar__spent" style="width:' . round($sp, 2) . '%"></i><i class="sbud-bar__comm" style="width:' . round($cm, 2) . '%"></i>';
    if ($pace !== null) $h .= '<b class="sbud-bar__pace" style="left:' . round($pace, 2) . '%" title="Share of the month gone"></b>';
    if ($limit !== null) $h .= '<b class="sbud-bar__limit" style="left:' . round($limit, 2) . '%" title="Budget limit"></b>';
    return $h . '</span>';
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Stock Budget</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        .sbud-nav { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .sbud-month { display:inline-flex; align-items:center; gap:2px; background:#fff; border:1px solid #e6e0d6; border-radius:4px; }
        .sbud-month a, .sbud-month span { padding:8px 12px; color:#3e3930; text-decoration:none; font-size:.95rem; }
        .sbud-month span { font-weight:600; min-width:120px; text-align:center; }
        .sbud-month a:hover { background:#faf8f4; }
        .sbud-month a[aria-disabled="true"] { opacity:.35; pointer-events:none; }
        .sbud-btn { padding:9px 16px; border:1px solid transparent; border-radius:4px; cursor:pointer; font-family:inherit; font-size:.86rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
        .sbud-btn--primary { background:#7E684B; color:#fff; }
        .sbud-btn--ghost { background:#fff; border-color:#d3cbc0; color:#6a6255; }
        .sbud-btn--sm { padding:6px 10px; font-size:.8rem; }
        .sbud-card { background:#fff; border:1px solid #e6e0d6; border-radius:4px; padding:18px 20px; margin-bottom:20px; box-shadow:0 2px 8px rgba(70,60,50,.06); }
        .sbud-card h3 { margin:0 0 12px; font-family:'Cormorant Garamond',serif; font-size:1.3rem; color:#3e3930; }
        .sbud-card h3 small { font-family:inherit; font-size:.78rem; font-weight:400; color:#8a8172; margin-left:6px; }
        .sbud-pace { margin-top:6px; }
        .sbud-pace__text { display:flex; flex-wrap:wrap; gap:6px 16px; font-size:.86rem; color:#6a6255; margin-top:8px; }
        .sbud-verdict { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:12px; font-size:.95rem; color:#3e3930; }
        .sbud-pill { display:inline-block; padding:3px 11px; border-radius:20px; font-size:.72rem; font-weight:600; white-space:nowrap; }
        .sbud-pill--ok { color:#2e6b34; background:#e3f0e4; }
        .sbud-pill--ahead, .sbud-pill--near { color:#8a6a2f; background:#f3ecdf; }
        .sbud-pill--over { color:#8a3a3a; background:#f0e3e3; }
        .sbud-pill--none { color:#6a6255; background:#eee9df; }
        .sbud-bar { position:relative; display:flex; height:12px; background:#eee9df; border-radius:6px; overflow:visible; width:100%; }
        .sbud-bar i { display:block; height:100%; }
        .sbud-bar__spent { background:#7E684B; border-radius:6px 0 0 6px; }
        .sbud-bar__comm { background:#c9b38f; }
        .sbud-bar__pace, .sbud-bar__limit { position:absolute; top:-3px; bottom:-3px; width:2px; background:#3e3930; opacity:.55; }
        .sbud-bar__limit { background:#a25048; opacity:.9; }
        .sbud-legend { display:flex; flex-wrap:wrap; gap:4px 16px; font-size:.76rem; color:#8a8172; margin-top:8px; }
        .sbud-legend i { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:5px; vertical-align:-1px; }
        .sbud-rows { display:flex; flex-direction:column; gap:10px; }
        .sbud-row { display:block; width:100%; text-align:left; background:#fff; border:1px solid #e6e0d6; border-radius:4px; padding:14px 16px; cursor:pointer; font-family:inherit; color:inherit; }
        .sbud-row:hover, .sbud-row:focus-visible { border-color:#7E684B; background:#faf8f4; outline:none; }
        .sbud-row__top { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:10px; }
        .sbud-row__name { font-weight:600; color:#3e3930; font-size:1rem; }
        .sbud-row__name small { display:block; font-weight:400; color:#8a8172; font-size:.76rem; margin-top:2px; }
        .sbud-figs { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:8px; margin-top:12px; }
        .sbud-figs span { display:block; font-variant-numeric:tabular-nums; font-size:.92rem; color:#3e3930; min-width:0; overflow-wrap:anywhere; }
        .sbud-figs em { display:block; font-style:normal; font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; color:#8a8172; margin-bottom:1px; }
        .sbud-figs .neg { color:#8a3a3a; font-weight:600; }
        .sbud-table { width:100%; border-collapse:collapse; }
        .sbud-table th, .sbud-table td { padding:9px 10px; border-bottom:1px solid #efeae1; font-size:.88rem; text-align:left; }
        .sbud-table th { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#8a8172; }
        .sbud-table .num { text-align:right; font-variant-numeric:tabular-nums; }
        .sbud-table .neg { color:#8a3a3a; font-weight:600; }
        .sbud-empty { text-align:center; padding:34px 16px; color:#8a8172; }
        .sbud-empty i { font-size:2rem; color:#c9b38f; }
        .sbud-help { font-size:.88rem; color:#6a6255; line-height:1.55; }
        .sbud-help p { margin:0 0 8px; }
        .sbud-card summary { cursor:pointer; font-weight:600; color:#3e3930; }
        .sbud-audit { list-style:none; margin:10px 0 0; padding:0; font-size:.84rem; color:#6a6255; }
        .sbud-audit li { padding:6px 0; border-bottom:1px solid #efeae1; overflow-wrap:anywhere; }
        /* Drawer */
        .sbud-drawer { position:fixed; inset:0; z-index:9990; display:none; }
        .sbud-drawer.open { display:block; }
        .sbud-drawer__back { position:absolute; inset:0; background:rgba(30,25,20,.45); }
        .sbud-drawer__panel { position:absolute; top:0; right:0; bottom:0; width:min(460px,100vw); background:#fff; box-shadow:-8px 0 30px rgba(0,0,0,.2); display:flex; flex-direction:column; }
        .sbud-drawer__head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid #e6e0d6; background:#faf8f4; }
        .sbud-drawer__head h3 { margin:0; font-family:'Cormorant Garamond',serif; font-size:1.3rem; color:#3e3930; }
        .sbud-drawer__body { padding:14px 18px; overflow-y:auto; flex:1; }
        .sbud-drawer__body h4 { margin:14px 0 6px; font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; color:#8a8172; }
        .sbud-drawer__body h4:first-child { margin-top:0; }
        .sbud-d-item { display:flex; justify-content:space-between; gap:10px; padding:8px 0; border-bottom:1px solid #efeae1; font-size:.88rem; }
        .sbud-d-item small { display:block; color:#8a8172; }
        .sbud-d-item a { color:#7E684B; font-weight:600; text-decoration:none; }
        .sbud-d-item .v { font-variant-numeric:tabular-nums; white-space:nowrap; }
        .sbud-x { background:none; border:none; font-size:1.5rem; line-height:1; cursor:pointer; color:#6a6255; padding:4px 8px; }
        /* Edit form */
        .sbud-erow { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(0,1.3fr) minmax(0,1fr) minmax(0,1.2fr) auto; gap:8px; align-items:end; margin-bottom:10px; padding-bottom:10px; border-bottom:1px solid #efeae1; }
        .sbud-erow label { display:block; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:#8a8172; margin-bottom:3px; }
        .sbud-erow select, .sbud-erow input { width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #d3cbc0; border-radius:3px; font-family:inherit; font-size:.88rem; background:#fff; }
        .sbud-tools { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
        .sbud-note { font-size:.82rem; color:#6a6255; margin:0 0 12px; }
        .sbud-filled { font-size:.82rem; color:#8a6a2f; background:#f3ecdf; padding:8px 10px; border-radius:3px; margin-bottom:12px; display:none; }
        @media (max-width:640px) {
            .sbud-figs { grid-template-columns:repeat(2, minmax(0,1fr)); }
            .sbud-erow { grid-template-columns:1fr 1fr; }
            .sbud-erow > div:first-child { grid-column:1 / -1; }
            .sbud-card { padding:14px; }
            .sbud-month span { min-width:100px; }
            .sbud-table th, .sbud-table td { padding:8px 5px; font-size:.78rem; }
            .sbud-table th { font-size:.62rem; letter-spacing:0; }
        }
        /* Touch screens: every control is at least 44x44 (staff use this on tablets and phones). */
        @media (max-width:1024px), (pointer:coarse) {
            .sbud-btn, .sbud-btn--sm { min-height:44px; }
            .sbud-x, .sbud-month a { min-width:44px; min-height:44px; display:inline-flex; align-items:center; justify-content:center; }
            .sbud-btn--sm:has(> i:only-child) { min-width:44px; justify-content:center; }
        }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <h2 class="page-title"><i class="fas fa-scale-balanced" style="color:#7E684B;"></i> Stock Budget</h2>
            <div class="sbud-nav">
                <div class="sbud-month" role="group" aria-label="Pick a month">
                    <a href="stock-budget.php?month=<?php echo urlencode($prevYm); ?>" aria-label="Previous month"<?php echo $canPrev ? '' : ' aria-disabled="true"'; ?>><i class="fas fa-chevron-left"></i></a>
                    <span><?php echo htmlspecialchars($c['info']['label'] ?? $ym); ?></span>
                    <a href="stock-budget.php?month=<?php echo urlencode($nextYm); ?>" aria-label="Next month"<?php echo $canNext ? '' : ' aria-disabled="true"'; ?>><i class="fas fa-chevron-right"></i></a>
                </div>
                <?php if ($ym !== $thisYm): ?><a class="sbud-btn sbud-btn--ghost" href="stock-budget.php">This month</a><?php endif; ?>
                <?php if ($canManage && $c && !$isPast): ?>
                    <button type="button" class="sbud-btn sbud-btn--primary" id="sbudEditBtn"><i class="fas fa-pen"></i> Edit budgets</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): showAlert($message, 'success'); endif; ?>
        <?php if ($error):   showAlert($error,   'error');   endif; ?>

        <?php if ($c): $s = $c['summary']; $hasBudget = $c['has_budget']; ?>

            <!-- Summary -->
            <div class="ck-kpis ck-kpis--4">
                <div class="ck-kpi"><span class="ck-kpi__label">Budget</span><span class="ck-kpi__value ck-kpi__value--money"><?php echo $money($s['budget']); ?></span>
                    <span class="ck-kpi__sub"><?php echo $s['basis'] === 'whole' ? 'Whole-stock limit' : ($hasBudget ? 'All category budgets added up' : 'Not set'); ?></span></div>
                <div class="ck-kpi"><span class="ck-kpi__label">Received</span><span class="ck-kpi__value ck-kpi__value--money"><?php echo $money($hasBudget ? $s['spent'] : $c['total']['spent']); ?></span>
                    <span class="ck-kpi__sub">Stock booked in this month</span></div>
                <div class="ck-kpi"><span class="ck-kpi__label">Already ordered</span><span class="ck-kpi__value ck-kpi__value--money"><?php echo $money($hasBudget ? $s['committed'] : $c['total']['committed']); ?></span>
                    <span class="ck-kpi__sub">Sent to suppliers, not yet received</span></div>
                <div class="ck-kpi <?php echo !$hasBudget ? '' : ($s['left'] < -BALANCE_TOLERANCE ? 'ck-kpi--alert' : ($s['status'] === 'near' ? 'ck-kpi--warn' : 'ck-kpi--good')); ?>"><span class="ck-kpi__label">Left to spend</span>
                    <span class="ck-kpi__value ck-kpi__value--money"><?php echo $hasBudget ? ($s['left'] < -BALANCE_TOLERANCE ? '-' : '') . $money(abs($s['left'])) : '—'; ?></span>
                    <span class="ck-kpi__sub"><?php echo $hasBudget ? 'Budget minus received minus ordered' : 'Set a budget to see this'; ?></span></div>
            </div>

            <div class="sbud-card" style="margin-top:16px;">
                <?php if ($hasBudget): ?>
                    <div class="sbud-pace">
                        <?php echo $bar($s['budget'], $s['spent'], $s['committed'], $c['info']['month_pct'], $c['info']['state'] === 'current'); ?>
                        <div class="sbud-legend">
                            <span><i style="background:#7E684B"></i>Received</span>
                            <span><i style="background:#c9b38f"></i>Already ordered</span>
                            <?php if ($c['info']['state'] === 'current'): ?><span><i style="background:#3e3930;opacity:.55;width:2px;height:12px"></i>Share of the month gone</span><?php endif; ?>
                        </div>
                        <div class="sbud-pace__text">
                            <span><strong><?php echo number_format($s['pct_used'], 0); ?>%</strong> of the budget is used</span>
                            <?php if ($c['info']['state'] === 'current'): ?><span><strong><?php echo number_format($c['info']['month_pct'], 0); ?>%</strong> of the month has gone</span><?php endif; ?>
                            <?php if ($s['forecast'] !== null && $c['info']['state'] === 'current'): ?><span>Month-end forecast: <strong><?php echo $money($s['forecast']); ?></strong></span><?php endif; ?>
                            <?php if ($c['sales']): ?><span>F&amp;B sales so far: <strong><?php echo $money($c['sales']['so_far']); ?></strong> &middot; expected for the month: <strong><?php echo $money($c['sales']['expected']); ?></strong></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="sbud-verdict">
                        <span class="sbud-pill sbud-pill--<?php echo htmlspecialchars($s['status']); ?>"><?php echo htmlspecialchars($pillText[$s['status']] ?? ''); ?></span>
                        <span><?php echo htmlspecialchars(rh_sbud_verdict($s, $c['info'], $cur)); ?></span>
                    </div>
                <?php else: ?>
                    <div class="sbud-empty">
                        <i class="fas fa-scale-balanced"></i>
                        <p>No stock budget is set for <?php echo htmlspecialchars($c['info']['label']); ?>.<br>
                            This month so far: <?php echo $money($c['total']['spent']); ?> received<?php echo $c['info']['state'] === 'current' ? ', ' . $money($c['total']['committed']) . ' already ordered' : ''; ?>.</p>
                        <?php if ($canManage && !$isPast): ?>
                            <button type="button" class="sbud-btn sbud-btn--primary" id="sbudEditBtn2"><i class="fas fa-pen"></i> Set up budgets</button>
                        <?php elseif (!$canManage && !$isPast): ?>
                            <p style="font-size:.84rem;">Ask a manager to set the budgets.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Per-category lines -->
            <?php if ($hasBudget || $c['other']['spent'] > BALANCE_TOLERANCE || $c['other']['committed'] > BALANCE_TOLERANCE): ?>
            <div class="sbud-card">
                <h3>By category <small>Tap a line to see the receipts and orders behind it</small></h3>
                <div class="sbud-rows">
                    <?php foreach ($c['lines'] as $l): ?>
                        <button type="button" class="sbud-row" data-cat="<?php echo htmlspecialchars($l['category']); ?>" data-label="<?php echo htmlspecialchars($l['label']); ?>">
                            <span class="sbud-row__top">
                                <span class="sbud-row__name"><?php echo htmlspecialchars($l['label']); ?>
                                    <small>
                                        <?php echo $l['mode'] === 'target_pct' && $c['sales']
                                            ? htmlspecialchars(rh_sbud_sales_basis_text($l['target_pct'], $c['sales'], $cur))
                                            : 'Fixed amount'; ?>
                                        &middot; <?php echo $l['control'] === 'approval' ? 'Going over needs approval' : 'Going over shows a warning'; ?>
                                    </small>
                                </span>
                                <span class="sbud-pill sbud-pill--<?php echo htmlspecialchars($l['status']); ?>"><?php echo htmlspecialchars($pillText[$l['status']] ?? ''); ?></span>
                            </span>
                            <?php echo $bar($l['budget'], $l['spent'], $l['committed'], $c['info']['month_pct'], $c['info']['state'] === 'current'); ?>
                            <span class="sbud-figs">
                                <span><em>Budget</em><?php echo $money($l['budget']); ?></span>
                                <span><em>Received</em><?php echo $money($l['spent']); ?></span>
                                <span><em>Already ordered</em><?php echo $money($l['committed']); ?></span>
                                <span><em>Left to spend</em><span class="<?php echo $l['left'] < -BALANCE_TOLERANCE ? 'neg' : ''; ?>"><?php echo ($l['left'] < -BALANCE_TOLERANCE ? '-' : '') . $money(abs($l['left'])); ?></span></span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                    <?php if ($c['other']['spent'] > BALANCE_TOLERANCE || $c['other']['committed'] > BALANCE_TOLERANCE): ?>
                        <button type="button" class="sbud-row" data-cat="__other__" data-label="Other (no budget line)">
                            <span class="sbud-row__top">
                                <span class="sbud-row__name">Other (no budget line)
                                    <small>Uncategorised items and categories without a budget. They only count against a whole-stock limit.</small></span>
                            </span>
                            <span class="sbud-figs">
                                <span><em>Received</em><?php echo $money($c['other']['spent']); ?></span>
                                <span><em>Already ordered</em><?php echo $money($c['other']['committed']); ?></span>
                            </span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Past months -->
            <div class="sbud-card">
                <h3>Past months <small>Budget against what was received, in <?php echo htmlspecialchars($cur); ?></small></h3>
                <div style="overflow-x:auto;"><table class="sbud-table no-card-mobile no-auto-sort">
                    <thead><tr><th>Month</th><th class="num">Budget</th><th class="num">Received</th><th class="num">Difference</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $h): ?>
                        <tr>
                            <td><a href="stock-budget.php?month=<?php echo urlencode($h['ym']); ?>" style="color:#7E684B;text-decoration:none;font-weight:600;"><?php echo htmlspecialchars(date('M Y', strtotime($h['ym'] . '-01'))); ?></a></td>
                            <td class="num"><?php echo $h['has_budget'] ? number_format($h['budget'], 0) : '—'; ?></td>
                            <td class="num"><?php echo number_format($h['actual'], 0); ?></td>
                            <td class="num <?php echo ($h['variance'] !== null && $h['variance'] < -BALANCE_TOLERANCE) ? 'neg' : ''; ?>">
                                <?php echo $h['variance'] === null ? '—' : number_format(abs($h['variance']), 0) . ($h['variance'] < -BALANCE_TOLERANCE ? ' over' : ' left'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>

            <div class="sbud-card">
                <details>
                    <summary>How this works</summary>
                    <div class="sbud-help" style="margin-top:10px;">
                        <p><strong>Received</strong> is the value of stock booked in during the month (goods-in, at the cost recorded when it was received). <strong>Already ordered</strong> is what is still to arrive on purchase orders you have sent. <strong>Left to spend</strong> is the budget minus both, so two people cannot both spend the same last amount.</p>
                        <p>Amounts are what the stock costs you, not selling prices. Costs are kept exactly as they are entered on receiving and on purchase orders, so if you enter them with VAT, budget with VAT.</p>
                        <p>A budget can be a fixed amount, or a % of the food and drink sales we expect for the month (the same daily figure as the End of Day report, which includes VAT). Because sales include VAT, set the % on the same basis as your costs: if stock costs are entered without VAT, a 30% target here is really a lower share of your sales, so raise the % by about the VAT rate. Expected sales are this month's sales so far spread over the whole month, from day 4; in the first 3 days, or before any sale, last month's sales are used. A past month uses what was actually sold.</p>
                        <p>When a purchase order would take a category over its budget, the order page shows it before you send. A line set to <em>warn</em> asks you to confirm; a line set to <em>needs approval</em> can only be sent by someone allowed to approve, who must give a reason. Orders already sent are only counted in the current month.</p>
                    </div>
                </details>
            </div>

            <?php if ($auditRows): ?>
            <div class="sbud-card">
                <details>
                    <summary>Recent changes for <?php echo htmlspecialchars($c['info']['label']); ?></summary>
                    <ul class="sbud-audit">
                        <?php foreach ($auditRows as $a): ?>
                            <li>
                                <strong><?php echo htmlspecialchars(date('j M H:i', strtotime($a['created_at']))); ?></strong>
                                &middot; <?php echo htmlspecialchars($a['full_name'] ?: 'Someone'); ?>
                                &middot;
                                <?php
                                $cat = $a['category'] === RH_SBUD_ALL ? 'Whole stock' : (string)$a['category'];
                                if ($a['action'] === 'po_override') {
                                    echo 'approved ' . htmlspecialchars((string)$a['po_reference']) . ' over budget for ' . htmlspecialchars($cat) . ' (' . htmlspecialchars((string)$a['reason']) . ')';
                                } elseif ($a['action'] === 'removed') {
                                    echo 'removed ' . htmlspecialchars($cat) . ' (was ' . htmlspecialchars((string)$a['old_value']) . ')';
                                } elseif ($a['action'] === 'set') {
                                    echo 'set ' . htmlspecialchars($cat) . ': ' . htmlspecialchars((string)$a['new_value']);
                                } else {
                                    echo 'changed ' . htmlspecialchars($cat) . ': ' . htmlspecialchars((string)$a['old_value']) . ' to ' . htmlspecialchars((string)$a['new_value']);
                                }
                                if (!empty($a['source']) && in_array($a['source'], ['copy', 'suggest'], true)) {
                                    echo ' <em>(' . ($a['source'] === 'copy' ? 'copied from last month' : 'suggested from the last 3 months') . ')</em>';
                                }
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </div>
            <?php endif; ?>

            <!-- Drawer -->
            <div class="sbud-drawer" id="sbudDrawer" aria-hidden="true">
                <div class="sbud-drawer__back" id="sbudDrawerBack"></div>
                <div class="sbud-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="sbudDrawerTitle">
                    <div class="sbud-drawer__head"><h3 id="sbudDrawerTitle">Details</h3><button type="button" class="sbud-x" id="sbudDrawerClose" aria-label="Close">&times;</button></div>
                    <div class="sbud-drawer__body" id="sbudDrawerBody"></div>
                </div>
            </div>

            <!-- Edit budgets (manage permission) -->
            <?php if ($canManage && !$isPast): ?>
                <?php renderAdminModalStart('sbudModal', 'Budgets for ' . $c['info']['label'], '', 'sbudForm', 'stock-budget.php?month=' . urlencode($ym)); ?>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="save_budgets">
                    <input type="hidden" name="b_source" id="sbudSource" value="manual">
                    <p class="sbud-note">One line per category. Give a fixed amount, or a % of the food and drink sales expected this month. Choose what happens when an order would go over.</p>
                    <div class="sbud-tools">
                        <button type="button" class="sbud-btn sbud-btn--ghost sbud-btn--sm" id="sbudCopy"<?php echo $copyProposal ? '' : ' disabled title="Last month has no budget to copy"'; ?>><i class="fas fa-copy"></i> Copy last month</button>
                        <button type="button" class="sbud-btn sbud-btn--ghost sbud-btn--sm" id="sbudSuggest"<?php echo $suggestProposal ? '' : ' disabled title="No stock received in the last 3 months"'; ?>><i class="fas fa-wand-magic-sparkles"></i> Suggest from last 3 months</button>
                    </div>
                    <div class="sbud-filled" id="sbudFilled"></div>
                    <div id="sbudRows"></div>
                    <button type="button" class="sbud-btn sbud-btn--ghost sbud-btn--sm" id="sbudAdd"><i class="fas fa-plus"></i> Add a line</button>
                <?php renderAdminModalFooterStart(); ?>
                    <button type="button" class="sbud-btn sbud-btn--ghost" onclick="closeAdminModal('sbudModal')">Cancel</button>
                    <button type="submit" class="sbud-btn sbud-btn--primary">Save budgets</button>
                <?php renderAdminModalEnd(); ?>
                <?php renderAdminModalScript(); ?>
                <script type="application/json" id="sbudData"><?php echo json_encode([
                    'categories' => $categories,
                    'current' => array_map(static function ($l) { return ['category' => $l['category'], 'mode' => $l['mode'], 'value' => $l['mode'] === 'target_pct' ? $l['target_pct'] : $l['amount'], 'control' => $l['control']]; }, $c['lines']),
                    'copy' => array_map(static function ($p) { return ['category' => $p['category'], 'mode' => $p['mode'], 'value' => $p['mode'] === 'target_pct' ? $p['target_pct'] : $p['amount'], 'control' => $p['control']]; }, $copyProposal),
                    'suggest' => array_map(static function ($p) { return ['category' => $p['category'], 'mode' => $p['mode'], 'value' => $p['amount'], 'control' => $p['control']]; }, $suggestProposal),
                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>

    <script>
    (function () {
        var month = <?php echo json_encode($ym); ?>;
        var cur = <?php echo json_encode($currency_symbol); ?>;
        var drawer = document.getElementById('sbudDrawer');
        if (!drawer) return;
        var body = document.getElementById('sbudDrawerBody');
        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
        function closeDrawer() { drawer.classList.remove('open'); drawer.setAttribute('aria-hidden', 'true'); }
        function item(left, sub, right, href) {
            var d = el('div', 'sbud-d-item'), l = el('div');
            if (href) { var a = el('a', null, left); a.href = href; l.appendChild(a); } else { l.appendChild(el('span', null, left)); }
            if (sub) l.appendChild(el('small', null, sub));
            d.appendChild(l); d.appendChild(el('span', 'v', right));
            return d;
        }
        function openDrawer(cat, label) {
            document.getElementById('sbudDrawerTitle').textContent = label;
            body.textContent = 'Loading...';
            drawer.classList.add('open'); drawer.setAttribute('aria-hidden', 'false');
            fetch('stock-budget.php?month=' + encodeURIComponent(month) + '&detail=' + encodeURIComponent(cat), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) throw new Error('bad'); return r.json(); })
                .then(function (d) {
                    body.textContent = '';
                    body.appendChild(el('h4', null, 'Already ordered (open purchase orders)'));
                    if (!d.pos.length) body.appendChild(el('p', 'sbud-note', 'Nothing is waiting to arrive.'));
                    d.pos.forEach(function (p) { body.appendChild(item(p.ref, p.supplier + ' - ' + p.status, p.value, 'purchase-orders.php?id=' + p.id)); });
                    body.appendChild(el('h4', null, 'Received this month'));
                    if (!d.receipts.length) body.appendChild(el('p', 'sbud-note', 'No stock received yet.'));
                    d.receipts.forEach(function (r) { body.appendChild(item(r.name, r.date + ' - ' + r.qty + (r.po ? ' - ' + r.po : ''), r.value, r.po_id ? 'purchase-orders.php?id=' + r.po_id : '')); });
                })
                .catch(function () { body.textContent = 'These details could not be loaded. Please try again.'; });
        }
        document.querySelectorAll('.sbud-row').forEach(function (b) {
            b.addEventListener('click', function () { openDrawer(b.getAttribute('data-cat'), b.getAttribute('data-label')); });
        });
        document.getElementById('sbudDrawerBack').addEventListener('click', closeDrawer);
        document.getElementById('sbudDrawerClose').addEventListener('click', closeDrawer);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });
    })();

    (function () {
        var dataEl = document.getElementById('sbudData');
        var rowsEl = document.getElementById('sbudRows');
        if (!dataEl || !rowsEl) return;
        var data = JSON.parse(dataEl.textContent);
        var source = document.getElementById('sbudSource');
        var filled = document.getElementById('sbudFilled');
        function opt(value, text, sel) { var o = document.createElement('option'); o.value = value; o.textContent = text; if (sel) o.selected = true; return o; }
        function field(label, node) { var d = document.createElement('div'); var l = document.createElement('label'); l.textContent = label; d.appendChild(l); d.appendChild(node); return d; }
        function addRow(r) {
            r = r || { category: '', mode: 'fixed', value: '', control: 'warn' };
            var row = document.createElement('div'); row.className = 'sbud-erow';
            var cat = document.createElement('select'); cat.name = 'b_category[]'; cat.required = true;
            cat.appendChild(opt('', 'Choose...', !r.category));
            cat.appendChild(opt('*', 'Whole stock (all categories)', r.category === '*'));
            var seen = {};
            data.categories.concat(r.category && r.category !== '*' ? [r.category] : []).forEach(function (c) { if (seen[c]) return; seen[c] = 1; cat.appendChild(opt(c, c, c === r.category)); });
            var mode = document.createElement('select'); mode.name = 'b_mode[]';
            mode.appendChild(opt('fixed', 'Fixed amount', r.mode === 'fixed'));
            mode.appendChild(opt('target_pct', '% of F&B sales', r.mode === 'target_pct'));
            var val = document.createElement('input'); val.type = 'number'; val.name = 'b_value[]'; val.step = '0.01'; val.min = '0'; val.required = true; val.inputMode = 'decimal'; val.value = r.value === '' ? '' : r.value;
            function sync() { val.placeholder = mode.value === 'target_pct' ? 'e.g. 30' : 'Amount'; if (mode.value === 'target_pct') val.max = '100'; else val.removeAttribute('max'); }
            mode.addEventListener('change', sync); sync();
            var ctl = document.createElement('select'); ctl.name = 'b_control[]';
            ctl.appendChild(opt('warn', 'Warn if over', r.control !== 'approval'));
            ctl.appendChild(opt('approval', 'Needs approval', r.control === 'approval'));
            var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'sbud-btn sbud-btn--ghost sbud-btn--sm'; rm.setAttribute('aria-label', 'Remove this line'); rm.innerHTML = '<i class="fas fa-trash"></i>';
            rm.addEventListener('click', function () { row.remove(); });
            row.appendChild(field('Category', cat)); row.appendChild(field('Budget by', mode));
            row.appendChild(field(r.mode === 'target_pct' ? '% of sales' : 'Value', val)); row.appendChild(field('When over', ctl));
            var rmWrap = document.createElement('div'); rmWrap.appendChild(rm); row.appendChild(rmWrap);
            mode.addEventListener('change', function () { row.children[2].firstChild.textContent = mode.value === 'target_pct' ? '% of sales' : 'Amount'; });
            rowsEl.appendChild(row);
        }
        function fill(list, src, msg) {
            rowsEl.textContent = '';
            list.forEach(addRow);
            source.value = src;
            filled.style.display = src === 'manual' ? 'none' : 'block';
            filled.textContent = msg;
        }
        function open() { fill(data.current.length ? data.current : [], 'manual', ''); if (!data.current.length) addRow(); openAdminModal('sbudModal'); bindAdminModal('sbudModal'); }
        ['sbudEditBtn', 'sbudEditBtn2'].forEach(function (id) { var b = document.getElementById(id); if (b) b.addEventListener('click', open); });
        document.getElementById('sbudAdd').addEventListener('click', function () { addRow(); });
        document.getElementById('sbudCopy').addEventListener('click', function () { fill(data.copy, 'copy', 'Filled in from last month. Check the lines, then press Save budgets. Nothing is saved until you do.'); });
        document.getElementById('sbudSuggest').addEventListener('click', function () { fill(data.suggest, 'suggest', 'Suggested from what was received in the last 3 months (average per month). Change anything you like, then press Save budgets.'); });
        // Any hand edit after a copy/suggest makes it a manual save again only if lines were changed - keep the label honest.
        rowsEl.addEventListener('input', function () { if (source.value !== 'manual') { source.value = 'manual'; filled.style.display = 'none'; } });
    })();
    </script>
</body>
</html>
