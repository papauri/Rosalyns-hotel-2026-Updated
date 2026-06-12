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

$cacheKey = 'stock_dashboard_metrics_v2';
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

        $metrics['pending_reconcile'] = (int)$pdo->query("
            SELECT COUNT(*) FROM booking_charges
            WHERE stock_tracked = 0 AND voided = 0
              AND charge_type IN ('food','drink')
              AND source_item_id IS NOT NULL
        ")->fetchColumn();

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

// Live alert data (small queries, always fresh)
$criticalIng = [];
$expiringSoon = [];
$lowStock = [];
$totalAlerts = 0;

if (!$error) {
    try {
        $criticalIng = $pdo->query("
            SELECT name, current_quantity, unit FROM stock_ingredients
            WHERE is_archived = 0 AND current_quantity <= 0
            ORDER BY current_quantity ASC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        $expiringSoon = $pdo->query("
            SELECT b.batch_number, i.name, b.expiry_date, b.quantity_remaining, b.cost_per_unit, DATEDIFF(b.expiry_date, CURDATE()) AS days_left
            FROM stock_batches b
            INNER JOIN stock_ingredients i ON i.id = b.ingredient_id
            WHERE b.status = 'active' AND b.expiry_date IS NOT NULL AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            ORDER BY b.expiry_date ASC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        $lowStock = $pdo->query("
            SELECT name, current_quantity, min_quantity, unit FROM stock_ingredients
            WHERE is_archived = 0 AND min_quantity > 0 AND current_quantity > 0 AND current_quantity <= min_quantity
            ORDER BY (current_quantity / min_quantity) ASC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        $totalAlerts = count($criticalIng) + count($expiringSoon) + count($lowStock);
        if (($metrics['pending_reconcile'] ?? 0) > 0) $totalAlerts++;
    } catch (Throwable $e) {
        $totalAlerts = 0;
    }
}

// Health banner state
$healthState = 'ok';
if (count($criticalIng) > 0 || ($metrics['expiring_3d'] ?? 0) > 0) {
    $healthState = 'critical';
} elseif (count($lowStock) > 0 || ($metrics['pending_reconcile'] ?? 0) > 0) {
    $healthState = 'warn';
}

function recipe_coverage_pct(int $with, int $total): string {
    if ($total === 0) return '—';
    return number_format(($with / $total) * 100, 0) . '%';
}
function recipe_coverage_num(int $with, int $total): float {
    if ($total === 0) return 0;
    return round(($with / $total) * 100);
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css">
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

        <!-- ═══ HEALTH BANNER ═══ -->
        <div class="sdash-health-banner sdash-health-banner--<?php echo $healthState; ?>">
            <div class="sdash-health-banner__icon">
                <?php if ($healthState === 'ok'): ?>
                    <i class="fas fa-check-circle"></i>
                <?php elseif ($healthState === 'critical'): ?>
                    <i class="fas fa-exclamation-circle"></i>
                <?php else: ?>
                    <i class="fas fa-exclamation-triangle"></i>
                <?php endif; ?>
            </div>
            <div class="sdash-health-banner__text">
                <?php if ($healthState === 'ok'): ?>
                    <strong>All good!</strong> Inventory is healthy — no urgent alerts right now.
                <?php elseif ($healthState === 'critical'): ?>
                    <strong>Action needed —</strong>
                    <?php
                    $parts = [];
                    if (count($criticalIng) > 0) $parts[] = count($criticalIng) . ' item(s) out of stock';
                    if (($metrics['expiring_3d'] ?? 0) > 0) $parts[] = ($metrics['expiring_3d']) . ' batch(es) expiring within 3 days';
                    echo implode(' &amp; ', $parts) . '.';
                    ?>
                <?php else: ?>
                    <strong>Heads up —</strong>
                    <?php
                    $parts = [];
                    if (count($lowStock) > 0) $parts[] = count($lowStock) . ' item(s) running low';
                    if (($metrics['pending_reconcile'] ?? 0) > 0) $parts[] = ($metrics['pending_reconcile']) . ' charge(s) need reconciling';
                    echo implode(' &amp; ', $parts) . '.';
                    ?>
                <?php endif; ?>
            </div>
            <button class="sdash-health-banner__refresh" onclick="location.reload()" title="Refresh dashboard">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>

        <!-- ═══ TOP KPI ROW ═══ -->
        <div class="sdash-kpi-row">
            <a class="sdash-kpi-card" href="stock-orders.php?date=today">
                <div class="sdash-kpi-card__icon sdash-kpi-card__icon--blue">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <div class="sdash-kpi-card__body">
                    <div class="sdash-kpi-card__label">Today's Orders</div>
                    <div class="sdash-kpi-card__value"><?php echo number_format((int)$metrics['orders_today']); ?></div>
                    <div class="sdash-kpi-card__sub">
                        <?php echo $currency_symbol . ' ' . number_format($metrics['revenue_today'], 0); ?> revenue
                        <?php if ((int)$metrics['orders_today'] > 0): ?>
                            &middot; avg <?php echo $currency_symbol . ' ' . number_format($metrics['revenue_today'] / $metrics['orders_today'], 0); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="sdash-kpi-card__arrow"><i class="fas fa-chevron-right"></i></span>
            </a>

            <a class="sdash-kpi-card" href="stock-ingredients.php">
                <div class="sdash-kpi-card__icon sdash-kpi-card__icon--green">
                    <i class="fas fa-warehouse"></i>
                </div>
                <div class="sdash-kpi-card__body">
                    <div class="sdash-kpi-card__label">Inventory Value</div>
                    <div class="sdash-kpi-card__value"><?php echo $currency_symbol . ' ' . number_format($metrics['total_inventory_value'], 0); ?></div>
                    <div class="sdash-kpi-card__sub"><?php echo number_format((int)$metrics['ingredient_count']); ?> ingredients tracked</div>
                </div>
                <span class="sdash-kpi-card__arrow"><i class="fas fa-chevron-right"></i></span>
            </a>

            <a class="sdash-kpi-card sdash-kpi-card--wastage" href="stock-wastage.php">
                <div class="sdash-kpi-card__icon sdash-kpi-card__icon--orange">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <div class="sdash-kpi-card__body">
                    <div class="sdash-kpi-card__label">Wastage (30 days)</div>
                    <div class="sdash-kpi-card__value"><?php echo $currency_symbol . ' ' . number_format($metrics['wastage_30d'], 0); ?></div>
                    <div class="sdash-kpi-card__sub">Recorded losses this month</div>
                </div>
                <span class="sdash-kpi-card__arrow"><i class="fas fa-chevron-right"></i></span>
            </a>
        </div>

        <!-- ═══ NEEDS ATTENTION ═══ -->
        <?php if ($totalAlerts > 0): ?>
        <div class="sdash-section">
            <div class="sdash-section__header">
                <h3 class="sdash-section__title">
                    <i class="fas fa-bell"></i> Needs Attention
                    <span class="sdash-badge sdash-badge--red"><?php echo $totalAlerts; ?></span>
                </h3>
                <span class="sdash-section__hint">Tap a group to expand</span>
            </div>

            <?php if (!empty($criticalIng)): ?>
            <div class="sdash-alert-group sdash-alert-group--critical js-sdash-alert-group" data-open="true">
                <button type="button" class="sdash-alert-group__header" onclick="toggleAlertGroup(this)" aria-expanded="true">
                    <i class="fas fa-times-circle"></i>
                    <span>Out of Stock <em>(<?php echo count($criticalIng); ?>)</em></span>
                    <i class="fas fa-chevron-down sdash-alert-group__chevron"></i>
                </button>
                <div class="sdash-alert-group__body">
                    <?php foreach ($criticalIng as $i): ?>
                    <div class="sdash-alert-item">
                        <div class="sdash-alert-item__info">
                            <span class="sdash-alert-item__name"><?php echo htmlspecialchars($i['name']); ?></span>
                            <span class="sdash-alert-item__detail"><?php echo htmlspecialchars((string)$i['current_quantity']); ?> <?php echo htmlspecialchars($i['unit']); ?> available</span>
                        </div>
                        <div class="sdash-alert-item__actions">
                            <a href="stock-orders.php" class="sdash-btn sdash-btn--sm sdash-btn--primary">
                                <i class="fas fa-cart-plus"></i> Order
                            </a>
                            <a href="stock-ingredients.php" class="sdash-btn sdash-btn--sm sdash-btn--ghost">View</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($expiringSoon)): ?>
            <div class="sdash-alert-group sdash-alert-group--warn js-sdash-alert-group" data-open="true">
                <button type="button" class="sdash-alert-group__header" onclick="toggleAlertGroup(this)" aria-expanded="true">
                    <i class="fas fa-clock"></i>
                    <span>Expiring Soon <em>(<?php echo count($expiringSoon); ?>)</em></span>
                    <i class="fas fa-chevron-down sdash-alert-group__chevron"></i>
                </button>
                <div class="sdash-alert-group__body">
                    <?php foreach ($expiringSoon as $b): ?>
                    <div class="sdash-alert-item">
                        <div class="sdash-alert-item__info">
                            <span class="sdash-alert-item__name"><?php echo htmlspecialchars($b['name']); ?></span>
                            <span class="sdash-alert-item__detail">
                                Batch <?php echo htmlspecialchars($b['batch_number']); ?> &mdash;
                                <?php
                                $dl = (int)$b['days_left'];
                                if ($dl === 0) echo '<strong style="color:#c82333">expires today</strong>';
                                elseif ($dl === 1) echo '<strong style="color:#c82333">expires tomorrow</strong>';
                                else echo 'expires in ' . $dl . ' days';
                                ?>
                                &middot; <?php echo number_format((float)$b['quantity_remaining'], 1); ?> remaining
                            </span>
                        </div>
                        <div class="sdash-alert-item__actions">
                            <a href="stock-batches.php?expiry=critical" class="sdash-btn sdash-btn--sm sdash-btn--warn">
                                <i class="fas fa-eye"></i> Review
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($lowStock)): ?>
            <div class="sdash-alert-group sdash-alert-group--info js-sdash-alert-group" data-open="false">
                <button type="button" class="sdash-alert-group__header" onclick="toggleAlertGroup(this)" aria-expanded="false">
                    <i class="fas fa-arrow-down"></i>
                    <span>Running Low <em>(<?php echo count($lowStock); ?>)</em></span>
                    <i class="fas fa-chevron-down sdash-alert-group__chevron"></i>
                </button>
                <div class="sdash-alert-group__body" style="display:none">
                    <?php foreach ($lowStock as $i): ?>
                    <?php
                        $pct = $i['min_quantity'] > 0 ? min(100, round(($i['current_quantity'] / $i['min_quantity']) * 100)) : 0;
                    ?>
                    <div class="sdash-alert-item">
                        <div class="sdash-alert-item__info sdash-alert-item__info--bar">
                            <span class="sdash-alert-item__name"><?php echo htmlspecialchars($i['name']); ?></span>
                            <span class="sdash-alert-item__detail">
                                <?php echo number_format((float)$i['current_quantity'], 1); ?> <?php echo htmlspecialchars($i['unit']); ?>
                                &nbsp;/&nbsp; min <?php echo number_format((float)$i['min_quantity'], 1); ?> <?php echo htmlspecialchars($i['unit']); ?>
                            </span>
                            <div class="sdash-mini-bar">
                                <div class="sdash-mini-bar__fill sdash-mini-bar__fill--warn" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                        </div>
                        <div class="sdash-alert-item__actions">
                            <a href="stock-orders.php" class="sdash-btn sdash-btn--sm sdash-btn--ghost">
                                <i class="fas fa-cart-plus"></i> Order
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (($metrics['pending_reconcile'] ?? 0) > 0): ?>
            <div class="sdash-alert-group sdash-alert-group--warn js-sdash-alert-group" data-open="false">
                <button type="button" class="sdash-alert-group__header" onclick="toggleAlertGroup(this)" aria-expanded="false">
                    <i class="fas fa-sync-alt"></i>
                    <span>Pending Reconciliation <em>(<?php echo $metrics['pending_reconcile']; ?>)</em></span>
                    <i class="fas fa-chevron-down sdash-alert-group__chevron"></i>
                </button>
                <div class="sdash-alert-group__body" style="display:none">
                    <div class="sdash-alert-item">
                        <div class="sdash-alert-item__info">
                            <span class="sdash-alert-item__name"><?php echo number_format((int)$metrics['pending_reconcile']); ?> booking charge(s) need stock reconciliation</span>
                            <span class="sdash-alert-item__detail">These charges occurred without a recipe in place or during the migration period.</span>
                        </div>
                        <div class="sdash-alert-item__actions">
                            <a href="stock-reports.php?tab=adjustments" class="sdash-btn sdash-btn--sm sdash-btn--warn">Process</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="sdash-all-clear">
            <i class="fas fa-check-circle"></i>
            <span>No active alerts — inventory is in great shape!</span>
        </div>
        <?php endif; ?>

        <!-- ═══ INVENTORY HEALTH ═══ -->
        <div class="sdash-section">
            <div class="sdash-section__header">
                <h3 class="sdash-section__title"><i class="fas fa-heartbeat"></i> Inventory Health</h3>
            </div>
            <div class="sdash-health-grid">
                <div class="sdash-health-stat">
                    <div class="sdash-health-stat__value"><?php echo number_format((int)$metrics['ingredient_count']); ?></div>
                    <div class="sdash-health-stat__label">Ingredients tracked</div>
                </div>
                <div class="sdash-health-stat <?php echo ($metrics['critical_stock'] ?? 0) > 0 ? 'sdash-health-stat--danger' : ''; ?>">
                    <div class="sdash-health-stat__value"><?php echo number_format((int)$metrics['critical_stock']); ?></div>
                    <div class="sdash-health-stat__label">Out of stock</div>
                </div>
                <div class="sdash-health-stat <?php echo ($metrics['low_stock'] ?? 0) > 0 ? 'sdash-health-stat--warn' : ''; ?>">
                    <div class="sdash-health-stat__value"><?php echo number_format((int)$metrics['low_stock']); ?></div>
                    <div class="sdash-health-stat__label">Running low</div>
                </div>
                <div class="sdash-health-stat">
                    <div class="sdash-health-stat__value"><?php echo number_format((int)$metrics['active_batches']); ?></div>
                    <div class="sdash-health-stat__label">Active batches</div>
                </div>
                <div class="sdash-health-stat <?php echo ($metrics['expiring_3d'] ?? 0) > 0 ? 'sdash-health-stat--danger' : ''; ?>">
                    <div class="sdash-health-stat__value"><?php echo number_format((int)$metrics['expiring_3d']); ?></div>
                    <div class="sdash-health-stat__label">Expiring ≤3 days</div>
                </div>
                <div class="sdash-health-stat <?php echo ($metrics['expiring_7d'] ?? 0) > 0 ? 'sdash-health-stat--warn' : ''; ?>">
                    <div class="sdash-health-stat__value"><?php echo number_format((int)$metrics['expiring_7d']); ?></div>
                    <div class="sdash-health-stat__label">Expiring 4–7 days</div>
                </div>
            </div>
        </div>

        <!-- ═══ RECIPE COVERAGE ═══ -->
        <div class="sdash-section">
            <div class="sdash-section__header">
                <h3 class="sdash-section__title"><i class="fas fa-book-open"></i> Recipe Coverage</h3>
                <a href="stock-recipes.php" class="sdash-section__action">Manage recipes</a>
            </div>
            <div class="sdash-coverage-list">
                <?php
                $foodPct = recipe_coverage_num((int)($metrics['food_with_recipe'] ?? 0), (int)($metrics['food_total'] ?? 0));
                $drinkPct = recipe_coverage_num((int)($metrics['drink_with_recipe'] ?? 0), (int)($metrics['drink_total'] ?? 0));
                ?>
                <div class="sdash-coverage-row">
                    <div class="sdash-coverage-row__label">
                        <i class="fas fa-utensils"></i> Food menu
                        <span class="sdash-coverage-row__count"><?php echo (int)($metrics['food_with_recipe'] ?? 0); ?> / <?php echo (int)($metrics['food_total'] ?? 0); ?> items</span>
                    </div>
                    <div class="sdash-coverage-row__bar-wrap">
                        <div class="sdash-coverage-bar">
                            <div class="sdash-coverage-bar__fill <?php echo $foodPct >= 80 ? 'sdash-coverage-bar__fill--good' : ($foodPct >= 50 ? 'sdash-coverage-bar__fill--mid' : 'sdash-coverage-bar__fill--low'); ?>"
                                 style="width:<?php echo $foodPct; ?>%"></div>
                        </div>
                        <span class="sdash-coverage-row__pct"><?php echo $foodPct; ?>%</span>
                    </div>
                </div>
                <div class="sdash-coverage-row">
                    <div class="sdash-coverage-row__label">
                        <i class="fas fa-cocktail"></i> Drinks menu
                        <span class="sdash-coverage-row__count"><?php echo (int)($metrics['drink_with_recipe'] ?? 0); ?> / <?php echo (int)($metrics['drink_total'] ?? 0); ?> items</span>
                    </div>
                    <div class="sdash-coverage-row__bar-wrap">
                        <div class="sdash-coverage-bar">
                            <div class="sdash-coverage-bar__fill <?php echo $drinkPct >= 80 ? 'sdash-coverage-bar__fill--good' : ($drinkPct >= 50 ? 'sdash-coverage-bar__fill--mid' : 'sdash-coverage-bar__fill--low'); ?>"
                                 style="width:<?php echo $drinkPct; ?>%"></div>
                        </div>
                        <span class="sdash-coverage-row__pct"><?php echo $drinkPct; ?>%</span>
                    </div>
                </div>
            </div>
            <p class="sdash-coverage-note">Higher coverage = more automatic stock deductions and accurate cost reporting when orders are placed.</p>
        </div>

        <!-- ═══ QUICK NAVIGATION ═══ -->
        <div class="sdash-section sdash-section--last">
            <div class="sdash-section__header">
                <h3 class="sdash-section__title"><i class="fas fa-compass"></i> Navigate</h3>
            </div>
            <div class="sdash-quicknav">
                <a class="sdash-qn-item" href="stock-ingredients.php">
                    <i class="fas fa-carrot"></i>
                    <span>Ingredients</span>
                    <?php $ingBadge = (int)$metrics['critical_stock'] + (int)$metrics['low_stock']; if ($ingBadge > 0): ?>
                    <em class="sdash-qn-badge sdash-qn-badge--red"><?php echo $ingBadge; ?></em>
                    <?php endif; ?>
                </a>
                <a class="sdash-qn-item" href="stock-recipes.php">
                    <i class="fas fa-book-open"></i>
                    <span>Recipes</span>
                </a>
                <a class="sdash-qn-item" href="stock-batches.php">
                    <i class="fas fa-layer-group"></i>
                    <span>Batches</span>
                    <?php $batchBadge = (int)$metrics['expiring_3d'] + (int)$metrics['expiring_7d']; if ($batchBadge > 0): ?>
                    <em class="sdash-qn-badge sdash-qn-badge--warn"><?php echo $batchBadge; ?></em>
                    <?php endif; ?>
                </a>
                <a class="sdash-qn-item" href="stock-orders.php">
                    <i class="fas fa-receipt"></i>
                    <span>Orders</span>
                    <?php if ((int)$metrics['orders_today'] > 0): ?>
                    <em class="sdash-qn-badge sdash-qn-badge--blue"><?php echo $metrics['orders_today']; ?> today</em>
                    <?php endif; ?>
                </a>
                <a class="sdash-qn-item" href="stock-wastage.php">
                    <i class="fas fa-trash-alt"></i>
                    <span>Wastage</span>
                </a>
                <a class="sdash-qn-item" href="stock-reports.php">
                    <i class="fas fa-chart-area"></i>
                    <span>Reports</span>
                </a>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>

    <script>
    function toggleAlertGroup(btn) {
        var group = btn.closest('.sdash-alert-group');
        var body = group.querySelector('.sdash-alert-group__body');
        var chevron = group.querySelector('.sdash-alert-group__chevron');
        var isOpen = btn.getAttribute('aria-expanded') === 'true';

        if (isOpen) {
            body.style.display = 'none';
            btn.setAttribute('aria-expanded', 'false');
            group.dataset.open = 'false';
        } else {
            body.style.display = '';
            btn.setAttribute('aria-expanded', 'true');
            group.dataset.open = 'true';
        }

        if (chevron) {
            chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
        }
    }

    // Init chevron for already-open groups
    document.querySelectorAll('.sdash-alert-group[data-open="true"] .sdash-alert-group__chevron').forEach(function(c) {
        c.style.transform = 'rotate(180deg)';
    });
    </script>
</body>
</html>

