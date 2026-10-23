<?php

/**
 * Stock Management — Reorder / Buying
 *
 * Shows every ingredient at or below its reorder point (falls back to
 * min_quantity), computes a suggested order quantity (par level minus what's
 * on hand minus what's already on order), and groups the shortfall by
 * preferred supplier. Each supplier group can be turned into a draft purchase
 * order in one click (posts to purchase-orders.php).
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once 'includes/procurement-schema.php';
require_once 'includes/stock-budget-lib.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);
$currency_symbol = getSetting('currency_symbol');

if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created.';
} else {
    ensureProcurementSchema($pdo);
}

$groups = [];   // supplier_id => ['supplier'=>name, 'lead'=>days, 'items'=>[], 'total'=>x]
$grandTotal = 0.0;
$itemCount = 0;

if (!$error) {
    try {
        // On-order quantities from open purchase orders (draft/sent/partial).
        $onOrder = [];
        try {
            $rows = $pdo->query("
                SELECT poi.ingredient_id, SUM(GREATEST(0, poi.ordered_qty - poi.received_qty)) AS qty
                FROM stock_purchase_order_items poi
                INNER JOIN stock_purchase_orders po ON po.id = poi.purchase_order_id
                WHERE po.status IN ('sent','partial') AND poi.ingredient_id IS NOT NULL
                GROUP BY poi.ingredient_id
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $onOrder[(int)$r['ingredient_id']] = (float)$r['qty'];
        } catch (Throwable $e) { $onOrder = []; }

        // Candidate ingredients: threshold = reorder_point, else min_quantity (same rule as rh_stock_threshold_sql()).
        // never_stocked = no stock history yet (opening stock not recorded): still buyable, but tagged, not an alert.
        $histSql = rh_stock_history_sql($pdo, 'i');
        $ingredients = $pdo->query("
            SELECT (CASE WHEN {$histSql} THEN 0 ELSE 1 END) AS never_stocked, i.id, i.name, i.unit, i.category, i.current_quantity, i.min_quantity,
                   i.reorder_point, i.par_level, i.lead_time_days, i.cost_per_unit, i.pack_name, i.pack_size,
                   i.preferred_supplier_id, s.id AS supplier_exists, s.name AS supplier_name,
                   s.lead_time_days AS supplier_lead, s.is_active AS supplier_active
            FROM stock_ingredients i
            LEFT JOIN stock_suppliers s ON s.id = i.preferred_supplier_id
            WHERE i.is_archived = 0
            ORDER BY i.name
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ingredients as $i) {
            $reorderPoint = (float)$i['reorder_point'] > 0 ? (float)$i['reorder_point'] : (float)$i['min_quantity'];
            if ($reorderPoint <= 0) continue;                 // no threshold set → not tracked
            $onHand = (float)$i['current_quantity'];
            if ($onHand > $reorderPoint + 0.0001) continue;   // still above reorder point

            $ord = $onOrder[(int)$i['id']] ?? 0.0;
            // Target: par level, else 2× reorder point as a sensible default top-up.
            $target = (float)$i['par_level'] > 0 ? (float)$i['par_level'] : ($reorderPoint * 2);
            $suggest = $target - $onHand - $ord;
            if ($suggest <= 0.0001) continue;                 // incoming PO already covers it

            // Items bought in packs: suggest whole packs (rounded up), so the order matches what the supplier delivers.
            $packSize = $i['pack_size'] !== null && (float)$i['pack_size'] > 0 ? (float)$i['pack_size'] : null;
            $pk = rh_pack_suggest($suggest, $packSize);
            $suggestUnits = $pk['units'];
            $lineCost = round($suggestUnits * (float)$i['cost_per_unit'], 2);
            // A preferred supplier that no longer exists counts as unassigned.
            $sid = !empty($i['supplier_exists']) ? (int)$i['preferred_supplier_id'] : 0;
            $key = $sid > 0 ? $sid : 0;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'supplier_id' => $sid,
                    'supplier'    => $sid > 0 ? (string)$i['supplier_name'] : 'Unassigned',
                    'lead'        => $sid > 0 ? (int)$i['supplier_lead'] : null,
                    'inactive'    => $sid > 0 && (int)$i['supplier_active'] !== 1,
                    'items'       => [],
                    'total'       => 0.0,
                ];
            }
            $groups[$key]['items'][] = [
                'id' => (int)$i['id'], 'name' => $i['name'], 'unit' => $i['unit'],
                'never' => ((int)$i['never_stocked'] === 1 && $onHand <= 0.0001), 'on_hand' => $onHand, 'reorder' => $reorderPoint, 'par' => $target,
                'on_order' => $ord, 'suggest' => round($suggestUnits, 3), 'cost' => (float)$i['cost_per_unit'],
                'packs' => $pk['packs'], 'pack_name' => $pk['packs'] !== null ? (trim((string)$i['pack_name']) !== '' ? trim((string)$i['pack_name']) : 'pack') : null, 'pack_size' => $packSize,
                'line_cost' => $lineCost,
            ];
            $groups[$key]['total'] += $lineCost;
            $grandTotal += $lineCost;
            $itemCount++;
        }

        // Unassigned group last, otherwise by supplier name.
        uasort($groups, function ($a, $b) {
            if ($a['supplier_id'] === 0) return 1;
            if ($b['supplier_id'] === 0) return -1;
            return strcasecmp($a['supplier'], $b['supplier']);
        });
    } catch (Throwable $e) {
        $error = 'Failed to build reorder report: ' . $e->getMessage();
    }
}

// Left to spend this month (whole-stock cap, or all category budgets added up) for people who may see the budget.
$budgetLeft = null;
if (!$error && hasPermission((int)$user['id'], 'stock_budget')) {
    try {
        if (rh_sbud_ensure_schema($pdo)) {
            $budgetLeft = rh_sbud_dashboard($pdo);
        }
    } catch (Throwable $e) {
        error_log('stock-reorder budget hint: ' . $e->getMessage());
    }
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Reorder / Buying — Stock Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        .ro-summary { margin-bottom:22px; }
        .ro-group { background:#fff; border:1px solid #e6e0d6; border-radius:2px; margin-bottom:22px; box-shadow:0 2px 8px rgba(70,60,50,.06); overflow:hidden; }
        .ro-group > header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; padding:14px 18px; background:#faf8f4; border-bottom:1px solid #efeae1; }
        .ro-group h3 { margin:0; font-family:'Cormorant Garamond',serif; font-size:1.3rem; color:#3e3930; }
        .ro-group .sub { font-size:.8rem; color:#8a8172; }
        .ro-table { width:100%; border-collapse:collapse; }
        .ro-table th, .ro-table td { padding:10px 14px; border-bottom:1px solid #efeae1; font-size:.88rem; text-align:left; }
        .ro-table th { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#8a8172; }
        .ro-table td.num, .ro-table th.num { text-align:right; font-variant-numeric:tabular-nums; }
        .ro-table tfoot td { font-weight:600; background:#faf8f4; }
        .low { color:#8a3a3a; font-weight:600; }
        .btn-ro { padding:8px 16px; border:none; border-radius:2px; cursor:pointer; font-family:inherit; font-size:.86rem; background:#7E684B; color:#fff; }
        .btn-ro[disabled] { opacity:.5; cursor:not-allowed; }
        .empty-ro { background:#fff; border:1px solid #e6e0d6; padding:40px; text-align:center; color:#8a8172; border-radius:2px; }
        @media (max-width:640px){ .ro-table thead { display:none; } }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-cart-flatbed" style="color:#7E684B;"></i> Reorder / Buying</h2>
        </div>

        <?php if ($error): showAlert($error, 'error'); endif; ?>

        <?php
        $neverListed = 0;
        foreach ($groups as $g) { foreach ($g['items'] as $it0) { if (!empty($it0['never'])) $neverListed++; } }
        if ($neverListed > 0): ?>
        <p style="margin:0 0 14px;color:#1e3a8a;"><i class="fas fa-clipboard-list"></i> Opening stock not recorded (<?php echo (int)$neverListed; ?> items below) &mdash; <a href="<?php echo htmlspecialchars(rh_stock_opening_count_url()); ?>">record a stock count</a> instead of buying what you may already have.</p>
        <?php endif; ?>

        <?php if (!$error): /* a failed build must not show 0 items as fact */ ?>
        <div class="ck-kpis ck-kpis--3 ro-summary">
            <div class="ck-kpi ck-kpi--warn"><span class="ck-kpi__label">Items to reorder <i class="help" tabindex="0" role="button" aria-label="About items to reorder" data-tip="Ingredients at or below their reorder point where the stock on hand plus orders already on the way still falls short of the target.">?</i></span><span class="ck-kpi__value"><?php echo (int)$itemCount; ?></span></div>
            <div class="ck-kpi"><span class="ck-kpi__label">Supplier orders <i class="help" tabindex="0" role="button" aria-label="About supplier orders" data-tip="How many separate purchase orders this list would make: one per preferred supplier, plus one for items with no supplier.">?</i></span><span class="ck-kpi__value"><?php echo count($groups); ?></span></div>
            <div class="ck-kpi"><span class="ck-kpi__label">Est. purchase value <i class="help" tabindex="0" role="button" aria-label="About estimated purchase value" data-tip="Suggested quantities times each item&#x27;s current cost per unit, added up across all suppliers. The real invoice may differ.">?</i></span><span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span> <?php echo number_format($grandTotal, 2); ?></span>
                <?php if ($budgetLeft): $afterBuy = $budgetLeft['left'] - $grandTotal; ?>
                    <span class="ck-kpi__sub">Left to spend this month: <a href="stock-budget.php"><?php echo htmlspecialchars($currency_symbol) . ' ' . number_format($budgetLeft['left'], 0); ?></a><?php echo $grandTotal > 0 && $afterBuy < -BALANCE_TOLERANCE ? ' &middot; <strong style="color:#8a3a3a;">this would go over by ' . htmlspecialchars($currency_symbol) . ' ' . number_format(abs($afterBuy), 0) . '</strong>' : ''; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>

        <?php if ($error): ?>
        <?php elseif (empty($groups)): ?>
            <div class="empty-ro">
                <i class="fas fa-check-circle" style="font-size:2rem;color:#5a8a5a;"></i>
                <p>Nothing to reorder — every tracked item is above its reorder point.<br>
                Set a <strong>reorder point</strong> and <strong>par level</strong> on ingredients to have them tracked here.</p>
            </div>
        <?php else: foreach ($groups as $g): ?>
            <div class="ro-group">
                <header>
                    <div>
                        <h3><?php echo htmlspecialchars($g['supplier']); ?><?php if (!empty($g['inactive'])): ?> <span style="font-size:.72rem;font-weight:500;color:#8a3a3a;background:#f0e3e3;padding:2px 8px;border-radius:20px;vertical-align:middle;">Inactive</span><?php endif; ?></h3>
                        <span class="sub">
                            <?php echo count($g['items']); ?> item<?php echo count($g['items']) === 1 ? '' : 's'; ?>
                            · Est. <?php echo htmlspecialchars($currency_symbol) . number_format($g['total'], 2); ?>
                            <?php if ($g['lead'] !== null && $g['lead'] > 0): ?> · Lead time <?php echo (int)$g['lead']; ?>d<?php endif; ?>
                        </span>
                    </div>
                    <form method="POST" action="purchase-orders.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="create_from_reorder">
                        <input type="hidden" name="supplier_id" value="<?php echo (int)$g['supplier_id']; ?>">
                        <?php
                        /* The order lines travel in a few packed fields (50 items each) instead of 4 hidden fields per item:
                         * 250 items would pass PHP's max_input_vars and be cut off. A pack item posts whole packs and the
                         * price of one pack; the order converts it to stock units. */
                        $roRows = [];
                        foreach ($g['items'] as $it) {
                            $roRows[] = [
                                'id' => (int)$it['id'],
                                'in' => $it['packs'] !== null ? 'pack' : 'unit',
                                'q' => (string)($it['packs'] !== null ? $it['packs'] : $it['suggest']),
                                'c' => (string)($it['packs'] !== null ? round($it['cost'] * $it['pack_size'], 4) : $it['cost']),
                            ];
                        }
                        foreach (array_chunk($roRows, 50) as $roChunk): ?>
                            <input type="hidden" name="lines_json[]" value="<?php echo htmlspecialchars(json_encode($roChunk), ENT_QUOTES); ?>">
                        <?php endforeach; ?>
                        <button type="submit" class="btn-ro" <?php echo $g['supplier_id'] === 0 ? 'disabled title="Assign a preferred supplier to these items first"' : (!empty($g['inactive']) ? 'disabled title="This supplier is deactivated. Reactivate it on the Suppliers page or pick another preferred supplier for these items."' : ''); ?>>
                            <i class="fas fa-file-invoice"></i> Create draft PO
                        </button>
                    </form>
                </header>
                <table class="ro-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="num">On hand <i class="help" tabindex="0" role="button" aria-label="About on hand" data-tip="Stock in the store right now, in the item&#x27;s own unit.">?</i></th>
                            <th class="num">Reorder pt <i class="help" tabindex="0" role="button" aria-label="About reorder point" data-tip="The low-stock line for this item: its reorder point if set, otherwise its minimum quantity. At or below it, the item is listed here.">?</i></th>
                            <th class="num">Par <i class="help" tabindex="0" role="button" aria-label="About par level" data-tip="The target to top the item back up to: its par level if set, otherwise twice the reorder point.">?</i></th>
                            <th class="num">On order <i class="help" tabindex="0" role="button" aria-label="About on order" data-tip="Quantity still to arrive on purchase orders that are sent or part-received. Draft orders are not counted.">?</i></th>
                            <th class="num">Suggested <i class="help" tabindex="0" role="button" aria-label="About suggested quantity" data-tip="Par minus on hand minus on order. For items bought in packs it is rounded up to whole packs.">?</i></th>
                            <th class="num">Est. cost <i class="help" tabindex="0" role="button" aria-label="About estimated cost" data-tip="Suggested quantity times the item&#x27;s current cost per unit.">?</i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($g['items'] as $it): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($it['name']); ?></strong> <span style="color:#8a8172;">(<?php echo htmlspecialchars($it['unit']); ?>)</span><?php if (!empty($it['never'])): ?> <span style="color:#1e3a8a;font-size:.75rem;" title="Opening stock has not been recorded for this item">not yet stocked</span><?php endif; ?></td>
                                <td class="num low"><?php echo number_format($it['on_hand'], 3); ?></td>
                                <td class="num"><?php echo number_format($it['reorder'], 3); ?></td>
                                <td class="num"><?php echo number_format($it['par'], 3); ?></td>
                                <td class="num"><?php echo number_format($it['on_order'], 3); ?></td>
                                <td class="num"><?php if ($it['packs'] !== null): ?><strong>Buy <?php echo (int)$it['packs']; ?> <?php echo htmlspecialchars(rh_pack_label($it['pack_name'], (float)$it['packs'])); ?></strong><br><span style="color:#8a8172;font-size:.78rem;">(<?php echo htmlspecialchars(rh_pack_num($it['suggest']) . ' ' . rh_unit_label($it['unit'], $it['suggest'])); ?>)</span><?php else: ?><strong><?php echo number_format($it['suggest'], 3); ?></strong><?php endif; ?></td>
                                <td class="num"><?php echo htmlspecialchars($currency_symbol) . number_format($it['line_cost'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" class="num">Group total</td>
                            <td class="num"><?php echo htmlspecialchars($currency_symbol) . number_format($g['total'], 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>
