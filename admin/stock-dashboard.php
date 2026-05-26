<?php
/**
 * Stock Management — Dashboard
 *
 * Cached metrics + actionable alerts. Includes "Pending reconciliation"
 * count for booking_charges that bypassed stock tracking (R2).
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once dirname(__DIR__) . '/config/cache.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);
$currency_symbol = getSetting('currency_symbol');

if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created. Please run admin/migrations/015_stock_management.php first.';
}

// Auto-run expiry sweep
if (!$error) runStockExpiryCheck();

$cacheKey = 'stock_dashboard_metrics_v1';
$metrics = function_exists('getCache') ? getCache($cacheKey) : null;
if (!$metrics && !$error) {
    try {
        $metrics = [];
        $metrics['ingredient_count'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE is_archived = 0")->fetchColumn();
        $metrics['low_stock'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE is_archived = 0 AND min_quantity > 0 AND current_quantity <= min_quantity AND current_quantity > 0")->fetchColumn();
        $metrics['critical_stock'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE is_archived = 0 AND current_quantity <= 0")->fetchColumn();
        $metrics['total_inventory_value'] = (float)$pdo->query("SELECT COALESCE(SUM(GREATEST(0, current_quantity) * cost_per_unit), 0) FROM stock_ingredients WHERE is_archived = 0")->fetchColumn();
        $metrics['active_batches'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches WHERE status = 'active' AND quantity_remaining > 0")->fetchColumn();
        $metrics['expiring_3d'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches WHERE status = 'active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetchColumn();
        $metrics['expiring_7d'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches WHERE status = 'active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 4 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

        $metrics['orders_today'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
        $metrics['revenue_today'] = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM stock_orders WHERE status NOT IN ('cancelled','voided') AND DATE(created_at) = CURDATE()")->fetchColumn();
        $metrics['wastage_30d'] = (float)$pdo->query("SELECT COALESCE(SUM(wastage_cost), 0) FROM stock_wastage WHERE recorded_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

        // Pending reconciliation: charges that should have triggered stock but didn't
        $metrics['pending_reconcile'] = (int)$pdo->query("
            SELECT COUNT(*) FROM booking_charges
            WHERE stock_tracked = 0 AND voided = 0
              AND charge_type IN ('food','drink')
              AND source_item_id IS NOT NULL
        ")->fetchColumn();

        // Recipes coverage
        $metrics['food_with_recipe'] = (int)$pdo->query("SELECT COUNT(DISTINCT menu_item_id) FROM stock_recipes WHERE menu_type = 'food'")->fetchColumn();
        $metrics['food_total'] = (int)$pdo->query("SELECT COUNT(mi.id) FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mc.slug = 'food'")->fetchColumn();
        $metrics['drink_with_recipe'] = (int)$pdo->query("SELECT COUNT(DISTINCT menu_item_id) FROM stock_recipes WHERE menu_type = 'drink'")->fetchColumn();
    } catch (Throwable $e) {
        // menu_items may not exist yet; continue silently
    }
    try {
        $metrics['drink_total'] = (int)$pdo->query("SELECT COUNT(mi.id) FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mc.slug = 'drink'")->fetchColumn();
    } catch (Throwable $e) { $metrics['drink_total'] = 0; }
    if (function_exists('setCache')) setCache($cacheKey, $metrics, 300);
}

// Alerts (always live; small queries)
$alerts = [];
if (!$error) {
    try {
        $criticalIng = $pdo->query("
            SELECT name, current_quantity, unit FROM stock_ingredients
            WHERE is_archived = 0 AND current_quantity <= 0
            ORDER BY current_quantity ASC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($criticalIng as $i) {
            $alerts[] = [
                'priority' => 1,
                'icon' => 'fas fa-exclamation-circle',
                'color' => '#c82333',
                'msg' => "Out of stock: <strong>{$i['name']}</strong> ({$i['current_quantity']} {$i['unit']})",
                'link' => 'stock-ingredients.php'
            ];
        }
        $expiringSoon = $pdo->query("
            SELECT b.batch_number, i.name, b.expiry_date, b.quantity_remaining, b.cost_per_unit, DATEDIFF(b.expiry_date, CURDATE()) AS days_left
            FROM stock_batches b
            INNER JOIN stock_ingredients i ON i.id = b.ingredient_id
            WHERE b.status = 'active' AND b.expiry_date IS NOT NULL AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            ORDER BY b.expiry_date ASC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expiringSoon as $b) {
            $alerts[] = [
                'priority' => 2,
                'icon' => 'fas fa-clock',
                'color' => '#856404',
                'msg' => "Batch <strong>{$b['batch_number']}</strong> ({$b['name']}) expires in {$b['days_left']} day(s)",
                'link' => 'stock-batches.php?expiry=critical'
            ];
        }
        $lowStock = $pdo->query("
            SELECT name, current_quantity, min_quantity, unit FROM stock_ingredients
            WHERE is_archived = 0 AND min_quantity > 0 AND current_quantity > 0 AND current_quantity <= min_quantity
            ORDER BY (current_quantity / min_quantity) ASC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lowStock as $i) {
            $alerts[] = [
                'priority' => 3,
                'icon' => 'fas fa-arrow-down',
                'color' => '#0c5460',
                'msg' => "Low: <strong>{$i['name']}</strong> at {$i['current_quantity']} {$i['unit']} (min {$i['min_quantity']})",
                'link' => 'stock-ingredients.php'
            ];
        }
        if (($metrics['pending_reconcile'] ?? 0) > 0) {
            $alerts[] = [
                'priority' => 2,
                'icon' => 'fas fa-sync-alt',
                'color' => '#856404',
                'msg' => "<strong>{$metrics['pending_reconcile']}</strong> booking charge(s) need stock reconciliation (no recipe at time of charge or migration period).",
                'link' => 'stock-reports.php?tab=adjustments'
            ];
        }
    } catch (Throwable $e) {
        $alerts[] = ['priority'=>1,'icon'=>'fas fa-bug','color'=>'#c82333','msg'=>'Error loading alerts: ' . htmlspecialchars($e->getMessage()),'link'=>'#'];
    }
    usort($alerts, fn($a, $b) => $a['priority'] <=> $b['priority']);
}

$csrf_token = generateCsrfToken();

function recipe_coverage_pct(int $with, int $total): string {
    if ($total === 0) return '—';
    return number_format(($with / $total) * 100, 0) . '%';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-responsive-enhancements.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/stock-dashboard.css">
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-boxes" style="color:var(--color-primary,#8A775F);"></i> Stock Dashboard</h2>
            <a href="stock-orders.php" class="btn-add"><i class="fas fa-plus"></i> New Order</a>
        </div>

        <?php if ($error): showAlert($error, 'error'); endif; ?>

        <?php if ($metrics): ?>
        <div class="metric-grid">
            <div class="metric"><div class="label">Inventory value</div><div class="value"><?php echo $currency_symbol . ' ' . number_format($metrics['total_inventory_value'], 0); ?></div><div class="sub"><?php echo $metrics['ingredient_count']; ?> ingredient(s)</div></div>
            <div class="metric <?php echo $metrics['critical_stock']>0?'danger':''; ?>"><div class="label">Out of stock</div><div class="value"><?php echo $metrics['critical_stock']; ?></div></div>
            <div class="metric <?php echo $metrics['low_stock']>0?'warning':''; ?>"><div class="label">Low stock</div><div class="value"><?php echo $metrics['low_stock']; ?></div></div>
            <div class="metric <?php echo $metrics['expiring_3d']>0?'danger':''; ?>"><div class="label">Expiring ≤3d</div><div class="value"><?php echo $metrics['expiring_3d']; ?></div><div class="sub"><?php echo $metrics['expiring_7d']; ?> in 4–7d</div></div>
            <div class="metric"><div class="label">Today's orders</div><div class="value"><?php echo $metrics['orders_today']; ?></div><div class="sub"><?php echo $currency_symbol . ' ' . number_format($metrics['revenue_today'], 2); ?> revenue</div></div>
            <div class="metric warning"><div class="label">Wastage (30d)</div><div class="value"><?php echo $currency_symbol . ' ' . number_format($metrics['wastage_30d'], 0); ?></div></div>
            <div class="metric"><div class="label">Recipes coverage</div><div class="value"><?php echo recipe_coverage_pct($metrics['food_with_recipe']??0, $metrics['food_total']??0); ?> · <?php echo recipe_coverage_pct($metrics['drink_with_recipe']??0, $metrics['drink_total']??0); ?></div><div class="sub">Food · Drinks</div></div>
            <?php if (($metrics['pending_reconcile']??0) > 0): ?>
            <div class="metric warning"><div class="label">Pending reconcile</div><div class="value"><?php echo $metrics['pending_reconcile']; ?></div><div class="sub">Charges without stock impact</div></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <h3 style="margin-bottom:10px;">Alerts</h3>
        <div class="alerts">
            <?php if (empty($alerts)): ?>
                <div style="text-align:center; color:#155724; padding:20px;"><i class="fas fa-check-circle"></i> All systems nominal — no active alerts.</div>
            <?php else: foreach ($alerts as $a): ?>
                <div class="alert-row">
                    <i class="<?php echo $a['icon']; ?>" style="color:<?php echo $a['color']; ?>; margin-top:3px;"></i>
                    <a href="<?php echo htmlspecialchars($a['link']); ?>"><?php echo $a['msg']; ?></a>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <h3 style="margin-top:30px; margin-bottom:10px;">Quick links</h3>
        <div class="quick-links">
            <a class="quick-link" href="stock-ingredients.php"><i class="fas fa-carrot"></i><div class="nm">Ingredients</div></a>
            <a class="quick-link" href="stock-recipes.php"><i class="fas fa-book-open"></i><div class="nm">Recipes</div></a>
            <a class="quick-link" href="stock-batches.php"><i class="fas fa-layer-group"></i><div class="nm">Batches</div></a>
            <a class="quick-link" href="stock-orders.php"><i class="fas fa-receipt"></i><div class="nm">Orders</div></a>
            <a class="quick-link" href="stock-wastage.php"><i class="fas fa-trash-alt"></i><div class="nm">Wastage</div></a>
            <a class="quick-link" href="stock-reports.php"><i class="fas fa-chart-area"></i><div class="nm">Reports</div></a>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>
