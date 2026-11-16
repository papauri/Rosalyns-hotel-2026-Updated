<?php

/**
 * Stock > Fix crate prices (owner 2026-11-17).
 *
 * An earlier import stored the price of a whole crate / case as the cost of ONE bottle or can. Staff type how many units are in
 * one crate (or fill the numbers from the owner's CSV), Preview shows old -> new cost, value and the batches affected, and
 * Confirm divides the cost (and the cost of each ticked batch still holding stock, and open order lines still at the crate price) in one transaction.
 * The logic, rules and the history ASSUMPTION are in admin/includes/stock-cost-fix-lib.php.
 * Permission: stock_management (the same as editing stock items); module: stock.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once 'includes/procurement-schema.php';
require_once __DIR__ . '/../includes/form-validation.php';
require_once __DIR__ . '/includes/stock-cost-fix-lib.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);
$currency_symbol = (string)getSetting('currency_symbol');

if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created.';
} else {
    ensureProcurementSchema($pdo);
}

$scf_typed = [];          // id => ['n' => string, 'pack' => string]: what was typed, carried through every round trip
$scf_preview = null;      // rh_scf_preview() result
$scf_prefill = null;      // rh_scf_csv_prefill() result + file name
$scf_problems = [];       // refused items, named

$scf_items = [];
if (!$error) {
    try {
        $scf_items = rh_scf_grid_items($pdo);
    } catch (Throwable $e) {
        error_log('stock-cost-fix grid: ' . $e->getMessage());
        $error = 'The stock items could not be loaded. Please reload the page.';
    }
}
$scf_itemNames = [];
foreach ($scf_items as $it) {
    $scf_itemNames[(int)$it['id']] = (string)$it['name'];
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Please reload the page.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $withOld = $action === 'apply';
        [$lines, $lineErrors] = rh_scf_lines_from_json((string)($_POST[$withOld ? 'apply_json' : 'grid_json'] ?? ''), $withOld);
        if ($withOld && !$lineErrors) {
            // The ticked "Divide this batch" boxes (unticked ones are not posted).
            [$lines, $lineErrors] = rh_scf_attach_divide($lines, $_POST['divide'] ?? null);
        }
        foreach ($lines as $id => $l) {
            $scf_typed[$id] = ['n' => $l['n'], 'pack' => $l['pack']];
        }
        if ($lineErrors) {
            $scf_problems = $lineErrors;
        } elseif ($action === 'prefill') {
            $f = $_FILES['fill_file'] ?? null;
            if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $error = 'Choose the CSV file first, then press Fill from list.';
            } elseif ((int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                $error = 'The file could not be uploaded. Please try again.';
            } elseif ((int)$f['size'] > 2 * 1024 * 1024) {
                $error = 'The file is too large (2 MB at most).';
            } else {
                $data = (string)file_get_contents((string)$f['tmp_name']);
                $scf_prefill = rh_scf_csv_prefill($data, $scf_items);
                $scf_prefill['file'] = basename((string)($f['name'] ?? 'list.csv'));
                if (!$scf_prefill['ok']) {
                    $error = $scf_prefill['error'];
                    $scf_prefill = null;
                } else {
                    foreach ($scf_prefill['fill'] as $id => $n) {
                        $scf_typed[$id] = ['n' => (string)$n, 'pack' => $scf_typed[$id]['pack'] ?? ''];
                    }
                }
            }
        } elseif ($action === 'preview') {
            if (!$lines) {
                $error = 'Type the units in one crate/case for at least one item, then press Preview.';
            } else {
                $scf_preview = rh_scf_preview($pdo, $lines);
                $scf_problems = $scf_preview['errors'];
            }
        } elseif ($action === 'apply') {
            $res = rh_scf_apply($pdo, $lines, (int)$user['id'], (string)$user['username']);
            if ($res['ok']) {
                $cnt = count($res['rows']);
                $_SESSION['stock_msg'] = 'Corrected ' . $cnt . ' item' . ($cnt === 1 ? '' : 's') . '. Their stock value went from '
                    . $currency_symbol . ' ' . number_format($res['before'], 2) . ' to ' . $currency_symbol . ' ' . number_format($res['after'], 2)
                    . '. Each change is recorded in System Logs and Staff activity.';
                header('Location: stock-cost-fix.php');
                exit;
            }
            $scf_problems = $res['errors'];
            $error = 'Nothing was changed. Fix or remove the items named below, then preview again.';
        } else {
            $error = 'Unknown action.';
        }
    }
}

// Flags: the same shared judge as Stock Items' "May be a case price" note, worked out over every active item with a cost.
$scf_flags = [];
if ($scf_items) {
    try {
        require_once __DIR__ . '/../includes/import/case-signals.php';
        $scf_flags = import_cs_stock_flags($scf_items, import_cs_load_evidence($pdo));
    } catch (Throwable $e) {
        $scf_flags = [];
    }
}
usort($scf_items, function ($a, $b) use ($scf_flags) {
    $fa = isset($scf_flags[(int)$a['id']]) ? 0 : 1;
    $fb = isset($scf_flags[(int)$b['id']]) ? 0 : 1;
    return [$fa, mb_strtolower((string)$a['category']), mb_strtolower((string)$a['name'])]
        <=> [$fb, mb_strtolower((string)$b['category']), mb_strtolower((string)$b['name'])];
});
$scf_categories = [];
$scf_units = [];
foreach ($scf_items as $it) {
    $scf_categories[(string)$it['category']] = true;
    $scf_units[mb_strtolower(trim((string)$it['unit']))] = (string)$it['unit'];
}
ksort($scf_categories, SORT_FLAG_CASE | SORT_STRING);
ksort($scf_units, SORT_STRING);
$scf_isBottleCan = function (string $unit): bool {
    return in_array(mb_strtolower(trim($unit)), ['bottle', 'bottles', 'can', 'cans'], true);
};
$scf_fmtQty = function ($q): string {
    $s = rtrim(rtrim(number_format((float)$q, 3, '.', ','), '0'), '.');
    return $s === '-0' ? '0' : $s;
};
$scf_cur = htmlspecialchars($currency_symbol);

// Figure cards for the default filter (bottle + can); the page script keeps them in step with the filters.
$scf_listed = 0;
$scf_listedValue = 0.0;
$scf_listedFlagged = 0;
foreach ($scf_items as $it) {
    if ($scf_isBottleCan((string)$it['unit'])) {
        $scf_listed++;
        $scf_listedValue += (float)$it['current_quantity'] * (float)$it['cost_per_unit'];
        $scf_listedFlagged += isset($scf_flags[(int)$it['id']]) ? 1 : 0;
    }
}
$scf_typedCount = 0;
foreach ($scf_typed as $t) {
    $scf_typedCount += trim((string)$t['n']) !== '' ? 1 : 0;
}

$scf_applyJson = '';
if ($scf_preview && !$scf_preview['errors'] && $scf_preview['rows']) {
    $scf_applyJson = json_encode(rh_scf_apply_payload($scf_preview['rows']));
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Fix crate prices — Stock Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        .scf-card { background:var(--au-surface,#fff); border:1px solid var(--au-border,#e6e0d6); border-radius:var(--au-radius,4px); padding:16px 18px; margin-bottom:18px; box-shadow:var(--au-shadow-sm,0 2px 8px rgba(70,60,50,.06)); min-width:0; }
        .scf-card h3 { margin:0 0 10px; font-family:'Cormorant Garamond',serif; font-size:1.3rem; color:var(--au-text,#3e3930); }
        .scf-card p { margin:0 0 8px; color:var(--au-text,#3e3930); font-size:.9rem; line-height:1.5; }
        .scf-muted { color:var(--au-muted,#8a8172); font-size:.82rem; }
        .scf-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:12px; }
        .scf-toolbar label { display:flex; flex-direction:column; gap:4px; font-size:.78rem; color:var(--au-muted,#8a8172); min-width:0; flex:1 1 160px; }
        .scf-toolbar select, .scf-toolbar input[type="search"] { min-height:44px; padding:8px 10px; border:1px solid var(--au-border,#d3cbc0); border-radius:var(--au-radius-sm,4px); font-family:inherit; font-size:.9rem; background:#fff; width:100%; box-sizing:border-box; }
        .scf-upload { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .scf-upload input[type="file"] { max-width:100%; min-width:0; min-height:44px; font-size:.86rem; }
        .scf-btn { min-height:44px; padding:9px 16px; border:1px solid transparent; border-radius:var(--au-radius-sm,4px); cursor:pointer; font-family:inherit; font-size:.88rem; display:inline-flex; align-items:center; gap:6px; text-decoration:none; }
        .scf-btn--primary { background:var(--au-accent-deep,#7E684B); color:#fff; }
        .scf-btn--ghost { background:#fff; border-color:var(--au-border,#d3cbc0); color:var(--au-text,#3e3930); }
        .scf-btn[disabled] { opacity:.5; cursor:not-allowed; }
        .scf-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin:4px 0 12px; }
        .scf-table { width:100%; border-collapse:collapse; }
        .scf-table th, .scf-table td { padding:9px 10px; border-bottom:1px solid var(--au-border-soft,#efeae1); font-size:.88rem; text-align:left; vertical-align:top; }
        .scf-table th { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:var(--au-muted,#8a8172); }
        .scf-table .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .scf-table input[type="number"] { width:90px; min-height:44px; padding:6px 8px; border:1px solid var(--au-border,#d3cbc0); border-radius:var(--au-radius-sm,4px); font-family:inherit; font-size:.92rem; box-sizing:border-box; }
        .scf-table input[type="text"] { width:110px; min-height:44px; padding:6px 8px; border:1px solid var(--au-border,#d3cbc0); border-radius:var(--au-radius-sm,4px); font-family:inherit; font-size:.92rem; box-sizing:border-box; }
        .scf-table input[aria-invalid="true"] { border-color:var(--au-danger,#a25048); box-shadow:0 0 0 2px rgba(162,80,72,.18); }
        .scf-table tr.scf-row--typed td { background:#faf8f4; }
        .scf-table tr.scf-row--focus td { background:#f3ecdf; }
        .scf-flag { display:inline-block; margin-top:3px; }
        .scf-arrow { color:var(--au-muted,#8a8172); padding:0 3px; }
        .scf-down { color:#2e6b34; font-weight:600; }
        .scf-batches { margin:0; padding:0; list-style:none; font-size:.8rem; }
        .scf-batches li { margin:0 0 3px; overflow-wrap:anywhere; }
        .scf-check { color:#8a6a2f; font-weight:600; }
        .scf-batches li + li { margin-top:6px; padding-top:6px; border-top:1px dashed var(--au-border-soft,#efeae1); }
        .scf-tick { display:inline-flex; align-items:center; gap:8px; min-height:44px; padding:0 4px 0 0; cursor:pointer; font-weight:600; color:var(--au-text,#3e3930); }
        .scf-tick input[type="checkbox"] { width:20px; height:20px; margin:0; accent-color:var(--au-accent-deep,#7E684B); cursor:pointer; }
        .scf-held { margin:8px 0 0; padding:8px 10px; background:#f3ecdf; border-radius:var(--au-radius-sm,4px); font-size:.8rem; color:var(--au-text,#3e3930); }
        .scf-po-note { margin:0 0 14px; padding:10px 12px; border:1px solid var(--au-border,#e6e0d6); border-radius:var(--au-radius-sm,4px); font-size:.88rem; }
        .scf-list { margin:6px 0 0; padding-left:18px; font-size:.86rem; max-height:220px; overflow:auto; overflow-wrap:anywhere; }
        .scf-msg { display:none; margin:0 0 12px; padding:10px 12px; border-radius:var(--au-radius-sm,4px); background:#f0e3e3; color:#8a3a3a; font-size:.88rem; }
        .scf-msg.is-shown { display:block; }
        .scf-empty { text-align:center; padding:30px 12px; color:var(--au-muted,#8a8172); }
        details.scf-details summary { cursor:pointer; min-height:44px; display:flex; align-items:center; font-size:.88rem; }
        @media (max-width:640px) {
            .scf-card { padding:14px 12px; }
            .scf-actions .scf-btn { flex:1 1 auto; justify-content:center; }
            .scf-table input[type="number"], .scf-table input[type="text"] { width:100%; }
        }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content scf-page" id="scf-page">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-wine-bottle" style="color:var(--au-accent-deep,#7E684B);"></i> Fix crate prices</h2>
            <div><a class="scf-btn scf-btn--ghost" href="stock-ingredients.php"><i class="fas fa-arrow-left"></i> Stock items</a></div>
        </div>

        <?php if ($error): showAlert($error, 'error'); endif; ?>

        <div class="scf-card">
            <p><strong>When to use this:</strong> an item's cost is the price of a whole crate or case, but it is stored as the cost of one bottle or can. Type how many units are in one crate or case, press <strong>Preview</strong>, check the numbers, then <strong>Confirm</strong>. The cost per unit is divided by that number.</p>
            <p class="scf-muted"><strong>What changes:</strong> the item's cost per unit, its pack (for example crate of 24), the cost of each batch still holding stock that you tick (the cost future sales use), and the price of open purchase order lines still to receive at the crate price. <strong>What stays:</strong> the unit, quantities in stock, minimum, reorder and par levels, recipes, and past records (approved stock counts, adjustments, deliveries, received order lines and used-up batches keep their numbers). Each corrected item is recorded in System Logs and Staff activity. Items without a cost are not listed: there is nothing to divide.</p>
        </div>

        <?php if ($scf_problems): ?>
            <div class="scf-card" role="alert" style="border-color:#e3c4c0;">
                <h3>These items need a look</h3>
                <ul class="scf-list"><?php foreach ($scf_problems as $p): ?><li><?php echo htmlspecialchars($p); ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($scf_preview && $scf_preview['rows']): $scf_pv = $scf_preview; $scf_pvCount = count($scf_pv['rows']); ?>
            <div class="scf-card" id="scf-preview">
                <h3>Preview <span class="scf-muted">nothing is changed until you press Confirm</span></h3>
                <div class="ck-kpis ck-kpis--4" style="margin-bottom:14px;">
                    <div class="ck-kpi"><span class="ck-kpi__label">Items to correct <i class="help" tabindex="0" role="button" aria-label="About items to correct" data-tip="Items with a number in Units in one crate/case that passed the checks.">?</i></span><span class="ck-kpi__value"><?php echo (int)$scf_pvCount; ?></span></div>
                    <div class="ck-kpi"><span class="ck-kpi__label">Value before <i class="help" tabindex="0" role="button" aria-label="About value before" data-tip="In stock times the cost per unit now, added up for these items.">?</i></span><span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo $scf_cur; ?></span> <?php echo number_format($scf_pv['before'], 2); ?></span></div>
                    <div class="ck-kpi"><span class="ck-kpi__label">Value after <i class="help" tabindex="0" role="button" aria-label="About value after" data-tip="In stock times the new cost per unit (the average cost of the stock held after the change, 4 decimals), added up for these items. It follows the Divide this batch boxes.">?</i></span><span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo $scf_cur; ?></span> <span id="scf-pv-after" data-before="<?php echo htmlspecialchars(sprintf('%.2F', $scf_pv['before'])); ?>"><?php echo number_format($scf_pv['after'], 2); ?></span></span></div>
                    <div class="ck-kpi"><span class="ck-kpi__label">Change <i class="help" tabindex="0" role="button" aria-label="About change" data-tip="Value after minus value before: how much the stock value of these items goes down.">?</i></span><span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo $scf_cur; ?></span> <span id="scf-pv-change"><?php echo number_format($scf_pv['after'] - $scf_pv['before'], 2); ?></span></span></div>
                </div>
                <?php if ($scf_pv['pos']): ?>
                    <div class="scf-po-note">
                        <strong>Open purchase orders that change</strong> <i class="help" tabindex="0" role="button" aria-label="About open purchase orders that change" data-tip="Lines still to receive whose price per unit is the old crate price are divided by the same number, so the next delivery is booked at the right cost. The order total is the sum of its line totals. Received lines and received, closed or cancelled orders are not changed.">?</i>
                        <ul class="scf-list">
                            <?php foreach ($scf_pv['pos'] as $po): ?>
                                <li><?php echo htmlspecialchars($po['ref']); ?>: total <?php echo $scf_cur . ' ' . number_format($po['old_total'], 2); ?> → <?php echo number_format($po['new_total'], 2); ?><?php if ($po['status'] !== 'draft'): ?> <span class="scf-check">Already sent to the supplier: send them the corrected order.</span><?php endif; ?> <span class="scf-check">Check each line's quantity is in single units, not crates, before sending: only the price per unit is divided.</span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <form method="POST" action="stock-cost-fix.php" id="scf-confirm-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="apply">
                    <input type="hidden" name="apply_json" value="<?php echo htmlspecialchars($scf_applyJson, ENT_QUOTES); ?>">
                <div class="table-responsive">
                    <table class="scf-table" id="scf-preview-table">
                        <thead>
                            <tr>
                                <th>Item <i class="help" tabindex="0" role="button" aria-label="About item" data-tip="The stock item and its unit. The unit does not change.">?</i></th>
                                <th>Pack <i class="help" tabindex="0" role="button" aria-label="About pack" data-tip="The pack saved on the item: its name and how many units are in one.">?</i></th>
                                <th class="num">Cost per unit <i class="help" tabindex="0" role="button" aria-label="About cost per unit" data-tip="Cost per unit now, then the new cost: the average cost of the stock held after the change, batch by batch (left in each batch times its cost after, divided by all left), rounded to 4 decimals. With no stock in batches: the cost now divided by the units in one crate or case.">?</i></th>
                                <th class="num">Value in stock <i class="help" tabindex="0" role="button" aria-label="About value in stock" data-tip="In stock times the cost per unit, now and after.">?</i></th>
                                <th>Batches holding stock <i class="help" tabindex="0" role="button" aria-label="About batches holding stock" data-tip="Each batch of this item that still holds stock. A ticked batch has its cost divided by the same number; an unticked one keeps its cost. A batch whose cost is clearly below the item's cost starts unticked: it may already be a price for one unit. The item's new cost is the average of the stock held after the change. Used-up batches keep their numbers.">?</i></th>
                                <th>Open purchase orders <i class="help" tabindex="0" role="button" aria-label="About open purchase orders" data-tip="Lines of draft, sent or part-received orders still to receive, in the item's unit. A line priced at the old cost is divided; a line ordered by the crate, or already priced for one unit, is not changed.">?</i></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scf_pv['rows'] as $r): ?>
                                <tr data-scf-pv="1" data-qty="<?php echo htmlspecialchars(sprintf('%.6F', $r['qty'])); ?>" data-divn="<?php echo htmlspecialchars(sprintf('%.4F', $r['div_n'])); ?>">
                                    <td><strong><?php echo htmlspecialchars($r['name']); ?></strong><br><span class="scf-muted"><?php echo htmlspecialchars($r['unit']); ?> · <?php echo $scf_fmtQty($r['qty']); ?> in stock</span></td>
                                    <td><?php echo htmlspecialchars($r['pack']); ?> of <?php echo (int)$r['n']; ?></td>
                                    <td class="num"><?php echo $scf_cur . ' ' . rh_scf_fmt($r['old_cost']); ?><span class="scf-arrow">→</span><span class="scf-down scf-new-cost"><?php echo rh_scf_fmt($r['new_cost']); ?></span><br><span class="scf-muted"><?php echo htmlspecialchars($r['basis']); ?></span></td>
                                    <td class="num"><?php echo $scf_cur . ' ' . number_format($r['old_value'], 2); ?><span class="scf-arrow">→</span><span class="scf-down scf-new-value"><?php echo number_format($r['new_value'], 2); ?></span></td>
                                    <td>
                                        <?php if (!$r['batches']): ?>
                                            <span class="scf-muted">None hold stock</span>
                                        <?php else: ?>
                                            <ul class="scf-batches">
                                                <?php foreach ($r['batches'] as $b): $bLabel = $b['number'] !== '' ? $b['number'] : ('#' . $b['id']); ?>
                                                    <li>
                                                        <label class="scf-tick"><input type="checkbox" class="scf-divide" name="divide[]" value="<?php echo (int)$r['id'] . ':' . (int)$b['id']; ?>" data-rem="<?php echo htmlspecialchars(sprintf('%.6F', $b['remaining'])); ?>" data-old="<?php echo htmlspecialchars(sprintf('%.4F', $b['old'])); ?>" data-div="<?php echo htmlspecialchars(sprintf('%.4F', $b['divided'])); ?>"<?php echo $b['default'] ? ' checked' : ''; ?>> Divide this batch</label>
                                                        <span><?php echo htmlspecialchars($bLabel); ?>: <?php echo $scf_fmtQty($b['remaining']); ?> left, <?php echo rh_scf_fmt($b['old']); ?> → <?php echo rh_scf_fmt($b['divided']); ?> if divided</span>
                                                        <?php if ($b['reason'] !== ''): ?><br><span class="scf-check"><?php echo htmlspecialchars($b['reason']); ?></span><?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <p class="scf-held" data-scf-held="1"<?php echo $r['held_mixed'] ? '' : ' hidden'; ?>>Some batches keep their cost, so the new cost per <?php echo htmlspecialchars($r['unit']); ?> is the average over all the stock held, not the old cost divided by <?php echo (int)$r['n']; ?>.</p>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$r['po_lines']): ?>
                                            <span class="scf-muted">None open</span>
                                        <?php else: ?>
                                            <ul class="scf-batches">
                                                <?php foreach ($r['po_lines'] as $pl): ?>
                                                    <li class="<?php echo $pl['kind'] === 'divide' ? 'scf-down' : 'scf-muted'; ?>"><?php echo htmlspecialchars(rh_scf_po_line_text($pl, $r['unit'], $currency_symbol)); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="scf-actions" style="margin-top:14px;">
                    <span class="scf-muted"><?php echo $scf_pv['errors'] ? 'Fix the items named above, then preview again.' : 'If a number is wrong, change it in the list below and press Preview again.'; ?></span>
                    <button type="submit" class="scf-btn scf-btn--primary" id="scf-confirm" <?php echo $scf_applyJson === '' ? 'disabled' : ''; ?>><i class="fas fa-check"></i> Confirm and correct <?php echo (int)$scf_pvCount; ?> item<?php echo $scf_pvCount === 1 ? '' : 's'; ?></button>
                </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($scf_prefill): ?>
            <div class="scf-card" id="scf-prefill-report">
                <h3>Filled from <?php echo htmlspecialchars($scf_prefill['file']); ?></h3>
                <p><?php echo count($scf_prefill['fill']); ?> item<?php echo count($scf_prefill['fill']) === 1 ? '' : 's'; ?> filled in. Nothing is saved yet: check the numbers, then press Preview.</p>
                <?php foreach ([
                    'unmatched' => ['Not found (no active item with a cost has exactly this name)', 'Names are matched exactly, ignoring capitals and spaces at the ends. Type these by hand.'],
                    'ambiguous' => ['More than one item has this name', 'Not filled: type the number on the right item by hand.'],
                    'bad' => ['Not a whole number of 2 or more', 'Not filled.'],
                    'real' => ['Marked as a real per-unit price (Y)', 'Skipped: nothing to correct.'],
                ] as $k => [$title, $hint]): if (!$scf_prefill[$k]) { continue; } ?>
                    <details class="scf-details" <?php echo $k === 'unmatched' ? 'open' : ''; ?>>
                        <summary><?php echo htmlspecialchars($title); ?>: <?php echo count($scf_prefill[$k]); ?></summary>
                        <p class="scf-muted"><?php echo htmlspecialchars($hint); ?></p>
                        <ul class="scf-list"><?php foreach ($scf_prefill[$k] as $nm): ?><li><?php echo htmlspecialchars($nm); ?></li><?php endforeach; ?></ul>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$error || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <form method="POST" action="stock-cost-fix.php" enctype="multipart/form-data" id="scf-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="grid_json" id="scf-grid-json" value="">

            <div class="scf-card">
                <h3>Fill from list <span class="scf-muted">optional</span></h3>
                <p class="scf-muted">A CSV with the columns <strong>Item</strong> and <strong>Units in one crate/case (FILL IN)</strong>, and optionally <strong>Real per-unit price? (Y …)</strong>: rows marked Y are skipped. It only fills in the numbers below; nothing is saved until you Preview and Confirm.</p>
                <div class="scf-upload">
                    <input type="file" name="fill_file" id="scf-file" accept=".csv,text/csv" aria-label="CSV list of units in one crate or case">
                    <button type="submit" name="action" value="prefill" class="scf-btn scf-btn--ghost" id="scf-prefill-btn"><i class="fas fa-file-csv"></i> Fill from list</button>
                </div>
            </div>

            <div class="ck-kpis ck-kpis--4" style="margin-bottom:14px;">
                <div class="ck-kpi"><span class="ck-kpi__label">Items listed <i class="help" tabindex="0" role="button" aria-label="About items listed" data-tip="Active stock items with a cost that match the filters below.">?</i></span><span class="ck-kpi__value" id="scf-k-count"><?php echo (int)$scf_listed; ?></span></div>
                <div class="ck-kpi"><span class="ck-kpi__label">Value now <i class="help" tabindex="0" role="button" aria-label="About value now" data-tip="In stock times the cost per unit now, added up for the items listed.">?</i></span><span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo $scf_cur; ?></span> <span id="scf-k-value"><?php echo number_format($scf_listedValue, 2); ?></span></span></div>
                <div class="ck-kpi<?php echo $scf_listedFlagged ? ' ck-kpi--warn' : ''; ?>" id="scf-k-flag-card"><span class="ck-kpi__label">May be a case price <i class="help" tabindex="0" role="button" aria-label="About may be a case price" data-tip="Items listed that this hotel's own data flags as a possible crate or case price: the same check as the note on Stock Items. They are listed first.">?</i></span><span class="ck-kpi__value" id="scf-k-flag"><?php echo (int)$scf_listedFlagged; ?></span></div>
                <div class="ck-kpi"><span class="ck-kpi__label">Filled in <i class="help" tabindex="0" role="button" aria-label="About filled in" data-tip="Items with a number in Units in one crate/case, on every page and filter. Only these go to Preview.">?</i></span><span class="ck-kpi__value" id="scf-k-typed"><?php echo (int)$scf_typedCount; ?></span></div>
            </div>

            <div class="scf-card">
                <div class="scf-toolbar">
                    <label>Search <input type="search" id="scf-search" placeholder="Item name" autocomplete="off"></label>
                    <label>Category
                        <select id="scf-cat">
                            <option value="">All categories</option>
                            <?php foreach (array_keys($scf_categories) as $c): ?>
                                <option value="<?php echo htmlspecialchars((string)$c === '' ? '__none' : mb_strtolower((string)$c)); ?>"><?php echo htmlspecialchars($c !== '' ? (string)$c : '(no category)'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Unit
                        <select id="scf-unit">
                            <option value="__bc" selected>Bottle and can</option>
                            <option value="">All units</option>
                            <?php foreach ($scf_units as $uk => $ul): ?>
                                <option value="<?php echo htmlspecialchars($uk); ?>"><?php echo htmlspecialchars($ul); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="scf-msg" id="scf-js-msg" role="alert"></div>

                <?php if (!$scf_items): ?>
                    <div class="scf-empty"><i class="fas fa-check-circle" style="font-size:1.6rem;"></i><p>No active stock items with a cost.</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="scf-table" id="scf-table">
                            <thead>
                                <tr>
                                    <th>Item <i class="help" tabindex="0" role="button" aria-label="About item" data-tip="The stock item. May be a case price: this hotel's own data suggests the cost is for a whole case; its ? says what was compared.">?</i></th>
                                    <th>Category <i class="help" tabindex="0" role="button" aria-label="About category" data-tip="The item's category on Stock Items.">?</i></th>
                                    <th>Unit <i class="help" tabindex="0" role="button" aria-label="About unit" data-tip="The unit stock is counted in. It does not change.">?</i></th>
                                    <th class="num">In stock <i class="help" tabindex="0" role="button" aria-label="About in stock" data-tip="Quantity on hand now, in the unit.">?</i></th>
                                    <th class="num">Cost per unit now <i class="help" tabindex="0" role="button" aria-label="About cost per unit now" data-tip="The stored cost of one unit (the weighted average of deliveries).">?</i></th>
                                    <th class="num">Value now <i class="help" tabindex="0" role="button" aria-label="About value now" data-tip="In stock times the cost per unit now.">?</i></th>
                                    <th>Units in one crate/case <i class="help" tabindex="0" role="button" aria-label="About units in one crate or case" data-tip="Only when the cost is the price of a whole crate or case: how many bottles or cans are in it (a whole number, 2 or more). Leave blank to keep the cost as it is.">?</i></th>
                                    <th>Pack name <i class="help" tabindex="0" role="button" aria-label="About pack name" data-tip="Saved on the item with the number, for buying in packs. Blank means crate.">?</i></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($scf_items as $it):
                                    $id = (int)$it['id'];
                                    $unitKey = mb_strtolower(trim((string)$it['unit']));
                                    $shown = $scf_isBottleCan((string)$it['unit']);
                                    $qty = (float)$it['current_quantity'];
                                    $cost = (float)$it['cost_per_unit'];
                                    $tN = $scf_typed[$id]['n'] ?? '';
                                    $tPack = $scf_typed[$id]['pack'] ?? '';
                                    if ($tPack === '' && $tN === '' && trim((string)($it['pack_name'] ?? '')) !== '') {
                                        $tPack = (string)$it['pack_name'];
                                    }
                                    $flag = $scf_flags[$id] ?? null; ?>
                                    <tr data-scf-row="1" data-id="<?php echo $id; ?>" data-name="<?php echo htmlspecialchars(mb_strtolower((string)$it['name'])); ?>" data-cat="<?php echo htmlspecialchars((string)$it['category'] === '' ? '__none' : mb_strtolower((string)$it['category'])); ?>" data-unit="<?php echo htmlspecialchars($unitKey); ?>" data-value="<?php echo htmlspecialchars(sprintf('%.4F', $qty * $cost)); ?>" data-flag="<?php echo $flag ? '1' : '0'; ?>"<?php echo $shown ? '' : ' data-filter-hidden="1" hidden'; ?> class="<?php echo trim((string)$tN) !== '' ? 'scf-row--typed' : ''; ?>">
                                        <td><strong><?php echo htmlspecialchars((string)$it['name']); ?></strong>
                                            <?php if ($flag): ?><br><span class="scf-flag"><span class="badge badge-warning">May be a case price</span> <i class="help" tabindex="0" role="button" aria-label="Why it may be a case price" data-tip="<?php echo htmlspecialchars((string)$flag['reason']); ?>">?</i></span><?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars((string)$it['category']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$it['unit']); ?></td>
                                        <td class="num"><?php echo $scf_fmtQty($qty); ?></td>
                                        <td class="num"><?php echo $scf_cur . ' ' . rh_scf_fmt($cost); ?></td>
                                        <td class="num"><?php echo $scf_cur . ' ' . number_format($qty * $cost, 2); ?></td>
                                        <td><input type="number" class="scf-n" min="2" max="99999" step="1" inputmode="numeric" value="<?php echo htmlspecialchars((string)$tN); ?>" aria-label="Units in one crate or case for <?php echo htmlspecialchars((string)$it['name']); ?>"></td>
                                        <td><input type="text" class="scf-pack" maxlength="30" placeholder="crate" value="<?php echo htmlspecialchars((string)$tPack); ?>" aria-label="Pack name for <?php echo htmlspecialchars((string)$it['name']); ?>"></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="scf-actions" style="margin-top:12px;">
                    <span class="scf-muted">Numbers you type stay when you change page or filter.</span>
                    <button type="submit" name="action" value="preview" class="scf-btn scf-btn--primary" id="scf-preview-btn" <?php echo $scf_items ? '' : 'disabled'; ?>><i class="fas fa-eye"></i> Preview</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
    <script>
    (function () {
        'use strict';
        var form = document.getElementById('scf-form');
        var table = document.getElementById('scf-table');
        if (!form) return;
        var rows = table ? Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-scf-row]')) : [];
        var search = document.getElementById('scf-search');
        var cat = document.getElementById('scf-cat');
        var unit = document.getElementById('scf-unit');
        var msg = document.getElementById('scf-js-msg');
        var BC = ['bottle', 'bottles', 'can', 'cans'];

        function money(v) {
            return (Math.round(v * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function nOf(row) { return (row.querySelector('.scf-n') || {}).value || ''; }
        function nValid(s) {
            s = String(s).trim();
            if (!/^\d{1,5}$/.test(s)) return false;
            var v = parseInt(s, 10);
            return v >= 2 && v <= 99999;
        }
        function matches(row) {
            var q = search ? search.value.trim().toLowerCase() : '';
            var c = cat ? cat.value : '';
            var u = unit ? unit.value : '';
            if (q && row.getAttribute('data-name').indexOf(q) === -1) return false;
            if (c !== '' && row.getAttribute('data-cat') !== c) return false;
            if (u === '__bc') return BC.indexOf(row.getAttribute('data-unit')) !== -1;
            if (u !== '' && row.getAttribute('data-unit') !== u) return false;
            return true;
        }
        function cards() {
            var count = 0, value = 0, flag = 0, typed = 0;
            rows.forEach(function (row) {
                if (String(nOf(row)).trim() !== '') typed++;
                if (row.dataset.filterHidden === '1') return;
                count++;
                value += parseFloat(row.getAttribute('data-value')) || 0;
                if (row.getAttribute('data-flag') === '1') flag++;
            });
            var set = function (id, t) { var el = document.getElementById(id); if (el) el.textContent = t; };
            set('scf-k-count', String(count));
            set('scf-k-value', money(value));
            set('scf-k-flag', String(flag));
            set('scf-k-typed', String(typed));
            var fc = document.getElementById('scf-k-flag-card');
            if (fc) fc.classList.toggle('ck-kpi--warn', flag > 0);
        }
        function applyFilter() {
            rows.forEach(function (row) {
                if (matches(row)) { delete row.dataset.filterHidden; row.hidden = false; }
                else { row.dataset.filterHidden = '1'; row.hidden = true; }
            });
            if (table && typeof table.rhPagerRefresh === 'function') table.rhPagerRefresh(true);
            cards();
        }
        // Enter in Search only filters; it must not submit the form (the first submit button is "Fill from list").
        if (search) search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); applyFilter(); } });
        [search, cat, unit].forEach(function (el) {
            if (el) el.addEventListener(el === search ? 'input' : 'change', applyFilter);
        });
        if (table) {
            table.addEventListener('input', function (e) {
                if (!e.target.classList.contains('scf-n')) return;
                var row = e.target.closest('tr');
                if (row) row.classList.toggle('scf-row--typed', e.target.value.trim() !== '');
                e.target.removeAttribute('aria-invalid');
                cards();
            });
            // Enter in a box must not send the form with the first button (Fill from list).
            table.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.matches('input')) e.preventDefault();
            });
        }
        function say(text) {
            if (!msg) return;
            msg.textContent = text;
            msg.classList.toggle('is-shown', text !== '');
        }
        // Bring one row into view: clear the filters if they hide it, turn to its page, focus its box.
        function showRow(row, focusEl) {
            if (row.dataset.filterHidden === '1') {
                if (search) search.value = '';
                if (cat) cat.value = '';
                if (unit) unit.value = '';
                applyFilter();
            }
            if (table && typeof table.rhPagerShowRow === 'function') table.rhPagerShowRow(row);
            row.hidden = false;
            row.classList.add('scf-row--focus');
            window.setTimeout(function () { row.classList.remove('scf-row--focus'); }, 2500);
            if (focusEl) { try { focusEl.focus({ preventScroll: true }); } catch (err) { focusEl.focus(); } }
            row.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
        form.addEventListener('submit', function (e) {
            var btn = e.submitter || document.activeElement;
            var act = btn && btn.name === 'action' ? btn.value : 'preview';
            var out = [];
            var bad = null;
            rows.forEach(function (row) {
                var nEl = row.querySelector('.scf-n');
                var n = nEl ? nEl.value.trim() : '';
                if (n === '') return;
                if (!nValid(n)) {
                    nEl.setAttribute('aria-invalid', 'true');
                    if (!bad) bad = row;
                    return;
                }
                var p = row.querySelector('.scf-pack');
                out.push({ id: parseInt(row.getAttribute('data-id'), 10), n: n, pack: p ? p.value.trim() : '' });
            });
            if (bad && act === 'preview') {
                e.preventDefault();
                say('Units in one crate/case must be a whole number of 2 or more. The first box to fix is highlighted.');
                showRow(bad, bad.querySelector('.scf-n'));
                return;
            }
            if (act === 'prefill') {
                var file = document.getElementById('scf-file');
                if (!file || !file.files || !file.files.length) {
                    e.preventDefault();
                    say('Choose the CSV file first, then press Fill from list.');
                    return;
                }
            } else if (!out.length) {
                e.preventDefault();
                say('Type the units in one crate/case for at least one item, then press Preview.');
                return;
            }
            say('');
            document.getElementById('scf-grid-json').value = JSON.stringify(out);
        });
        var confirmForm = document.getElementById('scf-confirm-form');
        // "Divide this batch": the new cost is the average of the stock held after the change (left x cost after, over the
        // batches; old / N when no batch holds stock), the same rule the server applies on Confirm. Keeps cost, value and cards in step.
        function heldCost(tr) {
            var boxes = tr.querySelectorAll('input.scf-divide');
            var q = 0, v = 0, mixed = false;
            Array.prototype.forEach.call(boxes, function (b) {
                var rem = parseFloat(b.getAttribute('data-rem')) || 0;
                var c = parseFloat(b.getAttribute(b.checked ? 'data-div' : 'data-old')) || 0;
                if (!b.checked) mixed = true;
                q += rem; v += rem * c;
            });
            var cost = q > 0.0000005 ? Math.round(v / q * 10000) / 10000 : (parseFloat(tr.getAttribute('data-divn')) || 0);
            var value = Math.round((parseFloat(tr.getAttribute('data-qty')) || 0) * cost * 100) / 100;
            var c1 = tr.querySelector('.scf-new-cost');
            if (c1) c1.textContent = cost.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
            var v1 = tr.querySelector('.scf-new-value');
            if (v1) { v1.textContent = money(value); v1.setAttribute('data-v', String(value)); }
            var p = tr.querySelector('[data-scf-held]');
            if (p) p.hidden = !mixed;
        }
        function pvCards() {
            var after = 0;
            Array.prototype.forEach.call(document.querySelectorAll('#scf-preview-table .scf-new-value'), function (el) {
                after += parseFloat(el.getAttribute('data-v') || el.textContent.replace(/,/g, '')) || 0;
            });
            var a = document.getElementById('scf-pv-after');
            var ch = document.getElementById('scf-pv-change');
            if (a) a.textContent = money(after);
            if (a && ch) ch.textContent = money(after - (parseFloat(a.getAttribute('data-before')) || 0));
        }
        if (confirmForm) {
            confirmForm.addEventListener('change', function (e) {
                if (!e.target.classList || !e.target.classList.contains('scf-divide')) return;
                var tr = e.target.closest('tr');
                if (tr) { heldCost(tr); pvCards(); }
            });
        }
        if (confirmForm) {
            confirmForm.addEventListener('submit', function () {
                var b = document.getElementById('scf-confirm');
                window.setTimeout(function () { if (b) { b.disabled = true; b.textContent = 'Correcting…'; } }, 0);
            });
        }
        // ?item=ID (from the "May be a case price" note on Stock Items): open that item, waiting for the pager to be ready.
        var want = parseInt(new URLSearchParams(window.location.search).get('item') || '0', 10);
        if (want > 0) {
            var target = rows.filter(function (r) { return parseInt(r.getAttribute('data-id'), 10) === want; })[0];
            if (target) {
                var tries = 0;
                (function waitPager() {
                    if ((table && typeof table.rhPagerShowRow === 'function') || tries++ > 20) { showRow(target, target.querySelector('.scf-n')); return; }
                    window.setTimeout(waitPager, 100);
                })();
            }
        }
        var pv = document.getElementById('scf-preview');
        if (pv && !want) pv.scrollIntoView({ block: 'start' });
        cards();
    })();
    </script>
</body>
</html>
