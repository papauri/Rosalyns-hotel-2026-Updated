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
                WHERE po.status IN ('draft','sent','partial') AND poi.ingredient_id IS NOT NULL
                GROUP BY poi.ingredient_id
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $onOrder[(int)$r['ingredient_id']] = (float)$r['qty'];
        } catch (Throwable $e) { $onOrder = []; }

        // Candidate ingredients: threshold = reorder_point, else min_quantity.
        $ingredients = $pdo->query("
            SELECT i.id, i.name, i.unit, i.category, i.current_quantity, i.min_quantity,
                   i.reorder_point, i.par_level, i.lead_time_days, i.cost_per_unit,
                   i.preferred_supplier_id, s.name AS supplier_name, s.lead_time_days AS supplier_lead
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

            $lineCost = round($suggest * (float)$i['cost_per_unit'], 2);
            $sid = (int)$i['preferred_supplier_id'];
            $key = $sid > 0 ? $sid : 0;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'supplier_id' => $sid,
                    'supplier'    => $sid > 0 ? (string)$i['supplier_name'] : 'Unassigned',
                    'lead'        => $sid > 0 ? (int)$i['supplier_lead'] : null,
                    'items'       => [],
                    'total'       => 0.0,
                ];
            }
            $groups[$key]['items'][] = [
                'id' => (int)$i['id'], 'name' => $i['name'], 'unit' => $i['unit'],
                'on_hand' => $onHand, 'reorder' => $reorderPoint, 'par' => $target,
                'on_order' => $ord, 'suggest' => round($suggest, 3), 'cost' => (float)$i['cost_per_unit'],
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
        .ro-summary { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:22px; }
        .ro-stat { background:#fff; border:1px solid #e6e0d6; border-radius:2px; padding:16px 20px; min-width:160px; box-shadow:0 2px 8px rgba(70,60,50,.06); }
        .ro-stat .num { font-size:1.7rem; font-weight:600; color:#3e3930; }
        .ro-stat .lbl { font-size:.76rem; text-transform:uppercase; letter-spacing:.05em; color:#8a8172; }
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
        .btn-ro { padding:8px 16px; border:none; border-radius:2px; cursor:pointer; font-family:inherit; font-size:.86rem; background:#8B7355; color:#fff; }
        .btn-ro[disabled] { opacity:.5; cursor:not-allowed; }
        .empty-ro { background:#fff; border:1px solid #e6e0d6; padding:40px; text-align:center; color:#8a8172; border-radius:2px; }
        @media (max-width:640px){ .ro-table thead { display:none; } }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-cart-flatbed" style="color:#8B7355;"></i> Reorder / Buying</h2>
        </div>

        <?php if ($error): showAlert($error, 'error'); endif; ?>

        <div class="ro-summary">
            <div class="ro-stat"><div class="num"><?php echo (int)$itemCount; ?></div><div class="lbl">Items to reorder</div></div>
            <div class="ro-stat"><div class="num"><?php echo count($groups); ?></div><div class="lbl">Supplier orders</div></div>
            <div class="ro-stat"><div class="num"><?php echo htmlspecialchars($currency_symbol) . number_format($grandTotal, 2); ?></div><div class="lbl">Est. purchase value</div></div>
        </div>

        <?php if (empty($groups)): ?>
            <div class="empty-ro">
                <i class="fas fa-check-circle" style="font-size:2rem;color:#5a8a5a;"></i>
                <p>Nothing to reorder — every tracked item is above its reorder point.<br>
                Set a <strong>reorder point</strong> and <strong>par level</strong> on ingredients to have them tracked here.</p>
            </div>
        <?php else: foreach ($groups as $g): ?>
            <div class="ro-group">
                <header>
                    <div>
                        <h3><?php echo htmlspecialchars($g['supplier']); ?></h3>
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
                        <?php foreach ($g['items'] as $it): ?>
                            <input type="hidden" name="ingredient_id[]" value="<?php echo (int)$it['id']; ?>">
                            <input type="hidden" name="order_qty[]" value="<?php echo htmlspecialchars((string)$it['suggest']); ?>">
                            <input type="hidden" name="unit_cost[]" value="<?php echo htmlspecialchars((string)$it['cost']); ?>">
                        <?php endforeach; ?>
                        <button type="submit" class="btn-ro" <?php echo $g['supplier_id'] === 0 ? 'disabled title="Assign a preferred supplier to these items first"' : ''; ?>>
                            <i class="fas fa-file-invoice"></i> Create draft PO
                        </button>
                    </form>
                </header>
                <table class="ro-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="num">On hand</th>
                            <th class="num">Reorder pt</th>
                            <th class="num">Par</th>
                            <th class="num">On order</th>
                            <th class="num">Suggested</th>
                            <th class="num">Est. cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($g['items'] as $it): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($it['name']); ?></strong> <span style="color:#8a8172;">(<?php echo htmlspecialchars($it['unit']); ?>)</span></td>
                                <td class="num low"><?php echo number_format($it['on_hand'], 3); ?></td>
                                <td class="num"><?php echo number_format($it['reorder'], 3); ?></td>
                                <td class="num"><?php echo number_format($it['par'], 3); ?></td>
                                <td class="num"><?php echo number_format($it['on_order'], 3); ?></td>
                                <td class="num"><strong><?php echo number_format($it['suggest'], 3); ?></strong></td>
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
