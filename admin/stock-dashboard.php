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
require_once 'includes/procurement-schema.php';
require_once 'includes/stock-budget-lib.php';
require_once dirname(__DIR__) . '/includes/stock-loss.php';

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
} else {
    ensureProcurementSchema($pdo);
}

// "Needs review" list is for managers and admins; each person dismisses items for themselves.
$canReview = in_array((string)($user['role'] ?? ''), ['admin', 'manager'], true);
if (!$error && $canReview && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_review') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Please try again.';
    } else {
        $keys = ($_POST['dismiss_all'] ?? '') === '1'
            ? array_map(static fn(array $i): string => $i['key'], rh_stock_review_items($pdo, (float)(getSetting('stock_variance_block_pct') ?: 10), (float)(getSetting('stock_variance_min_cost') ?: 5000), ''))
            : [(string)($_POST['key'] ?? '')];
        rh_stock_review_dismiss((int)$user['id'], $keys);
        header('Location: stock-dashboard.php#needs-review');
        exit;
    }
}

// Auto-run expiry sweep
if (!$error) runStockExpiryCheck();

$cacheKey = RH_STOCK_DASH_CACHE_KEY;
$metrics = function_exists('getCache') ? getCache($cacheKey) : null;
if (!$metrics && !$error) {
    try {
        $metrics = [];
        $metrics['ingredient_count'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE is_archived = 0")->fetchColumn();
        $metrics['total_inventory_value'] = rh_stock_valuation($pdo);
        $metrics['active_batches'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches WHERE status = 'active' AND quantity_remaining > 0")->fetchColumn();
        $metrics['expiring_3d'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches WHERE status = 'active' AND quantity_remaining > 0 AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetchColumn();
        $metrics['expiring_7d'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches WHERE status = 'active' AND quantity_remaining > 0 AND expiry_date IS NOT NULL AND expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 4 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

        $metrics['orders_today'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_orders WHERE status NOT IN ('voided','cancelled') AND DATE(created_at) = CURDATE()")->fetchColumn();
        $metrics['orders_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_orders WHERE status = 'placed' AND DATE(created_at) = CURDATE()")->fetchColumn();
        $metrics['revenue_today'] = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM stock_orders WHERE status = 'paid' AND DATE(created_at) = CURDATE()")->fetchColumn();
        $metrics['wastage_today'] = (float)$pdo->query("SELECT COALESCE(SUM(wastage_cost), 0) FROM stock_wastage WHERE recorded_date = CURDATE()")->fetchColumn();
        $metrics['wastage_30d'] = (float)$pdo->query("SELECT COALESCE(SUM(wastage_cost), 0) FROM stock_wastage WHERE recorded_date > DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
        $metrics['expired_batches'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches WHERE status = 'active' AND quantity_remaining > 0 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetchColumn();

        $metrics['pending_reconcile'] = (int)$pdo->query("
            SELECT COUNT(*) FROM booking_charges
            WHERE stock_tracked = 0 AND voided = 0
              AND charge_type IN ('food','drink')
              AND source_item_id IS NOT NULL
        ")->fetchColumn();

        $metrics['food_with_recipe'] = (int)$pdo->query("SELECT COUNT(*) FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mc.is_active = 1 AND mi.station = 'kitchen' AND EXISTS (SELECT 1 FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id WHERE sr.menu_item_id = mi.id AND sr.menu_type = mc.slug)")->fetchColumn();
        $metrics['food_total'] = (int)$pdo->query("SELECT COUNT(*) FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mc.is_active = 1 AND mi.station = 'kitchen'")->fetchColumn();
        $metrics['drink_with_recipe'] = (int)$pdo->query("SELECT COUNT(*) FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mc.is_active = 1 AND mi.station <> 'kitchen' AND EXISTS (SELECT 1 FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id WHERE sr.menu_item_id = mi.id AND sr.menu_type = mc.slug)")->fetchColumn();
    } catch (Throwable $e) {
        // menu_items may not exist yet; continue silently
    }
    try {
        $metrics['drink_total'] = (int)$pdo->query("SELECT COUNT(*) FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mc.is_active = 1 AND mi.station <> 'kitchen'")->fetchColumn();
    } catch (Throwable $e) { $metrics['drink_total'] = 0; }
    if (function_exists('setCache')) setCache($cacheKey, $metrics, 300);
}

// Stock alerts use the ONE shared rule (rh_stock_alert_summary) and are always live, never cached.
$alertSummary = ['never_stocked' => 0, 'out' => 0, 'low' => 0, 'negative' => 0, 'attention' => 0, 'out_items' => [], 'low_items' => [], 'negative_items' => [], 'never_items' => []];
if (!$error && $metrics) {
    $alertSummary = rh_stock_alert_summary($pdo, 10);
    $metrics['low_stock'] = $alertSummary['low'];
    $metrics['critical_stock'] = $alertSummary['out'];

    // Sales cards are live too. "Settled today" = paid orders by the day they were PAID (a tab opened
    // last night and settled today counts today, as on the Orders screen); "Open tabs" = every order
    // still open, whatever day it was started, so the number matches the list it links to.
    // Settled = 'paid' or 'completed'. Room-service orders charged to a guest folio are left out, as on
    // End of Day: the folio payment already counts them. An order refunded or voided later drops out of
    // this card (it shows what still stands as sold today); End of Day instead nets it on the reversal day.
    $metrics['orders_paid_today'] = null;
    try {
        $liveSales = $pdo->query("
            SELECT SUM(CASE WHEN settled = 1 THEN 1 ELSE 0 END) AS paid_n,
                   COALESCE(SUM(CASE WHEN settled = 1 THEN total_amount ELSE 0 END), 0) AS paid_amt,
                   SUM(CASE WHEN status = 'placed' THEN 1 ELSE 0 END) AS open_n,
                   SUM(CASE WHEN reversed = 1 THEN 1 ELSE 0 END) AS rev_n,
                   COALESCE(SUM(CASE WHEN reversed = 1 THEN total_amount ELSE 0 END), 0) AS rev_amt
            FROM (
                SELECT o.status, o.total_amount,
                       -- Paid orders refunded or voided today: shown under the card so the net figure is explained.
                       ((o.status = 'refunded' AND DATE(o.refunded_at) = CURDATE())
                        OR (o.status = 'voided' AND o.paid_at IS NOT NULL AND DATE(o.voided_at) = CURDATE())) AS reversed,
                       (o.status IN ('paid','completed') AND DATE(COALESCE(o.paid_at, o.created_at)) = CURDATE()
                        AND NOT (o.order_type = 'room_service' AND EXISTS (SELECT 1 FROM booking_charges bc WHERE bc.stock_order_id = o.id))) AS settled
                FROM stock_orders o
            ) x
        ")->fetch(PDO::FETCH_ASSOC);
        if ($liveSales) {
            $metrics['orders_paid_today'] = (int)$liveSales['paid_n'];
            $metrics['revenue_today']     = (float)$liveSales['paid_amt'];
            $metrics['orders_pending']    = (int)$liveSales['open_n'];
            $metrics['reversed_today_n']   = (int)$liveSales['rev_n'];
            $metrics['reversed_today_amt'] = (float)$liveSales['rev_amt'];
        }
    } catch (Throwable $e) {
        error_log('stock-dashboard sales cards: ' . $e->getMessage());
    }
}

// Live operational data (always fresh — not cached)
$topItemsToday = [];
$mostWastedIngredients = [];
if (!$error) {
    try {
        $topItemsToday = $pdo->query("
            SELECT oi.item_name, oi.menu_type, SUM(oi.quantity) AS qty_sold, SUM(oi.line_total) AS revenue,
                   COUNT(DISTINCT o.id) AS order_count
            FROM stock_order_items oi
            INNER JOIN stock_orders o ON o.id = oi.order_id
            WHERE o.status IN ('paid','completed') AND DATE(COALESCE(o.paid_at, o.created_at)) = CURDATE()
              AND NOT (o.order_type = 'room_service' AND EXISTS (SELECT 1 FROM booking_charges bc WHERE bc.stock_order_id = o.id))
            GROUP BY oi.item_name, oi.menu_type
            ORDER BY qty_sold DESC, revenue DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $topItemsToday = []; }

    try {
        $mostWastedIngredients = $pdo->query("
            SELECT i.name, i.unit, SUM(w.quantity) AS total_qty, SUM(w.wastage_cost) AS total_cost, COUNT(*) AS entries
            FROM stock_wastage w
            INNER JOIN stock_ingredients i ON i.id = w.ingredient_id
            WHERE w.recorded_date > DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY i.id, i.name, i.unit
            ORDER BY total_cost DESC
            LIMIT 6
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $mostWastedIngredients = []; }
}

// Live alert data (small queries, always fresh)
$criticalIng = [];
$expiringSoon = [];
$expiredBatches = [];
$lowStock = [];
$totalAlerts = 0;

if (!$error) {
    try {
        $criticalIng = $alertSummary['out_items'];

        $expiringSoon = $pdo->query("
            SELECT b.batch_number, i.name, b.expiry_date, b.quantity_remaining, b.cost_per_unit, DATEDIFF(b.expiry_date, CURDATE()) AS days_left
            FROM stock_batches b
            INNER JOIN stock_ingredients i ON i.id = b.ingredient_id
            WHERE b.status = 'active' AND b.quantity_remaining > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            ORDER BY b.expiry_date ASC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        $expiredBatches = $pdo->query("
            SELECT b.batch_number, i.name, b.expiry_date, b.quantity_remaining,
                   ABS(DATEDIFF(b.expiry_date, CURDATE())) AS days_expired
            FROM stock_batches b
            INNER JOIN stock_ingredients i ON i.id = b.ingredient_id
            WHERE b.status = 'active' AND b.quantity_remaining > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()
            ORDER BY b.expiry_date ASC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        $lowStock = array_map(static function (array $r): array {
            $r['min_quantity'] = $r['threshold']; // the list shows the effective reorder threshold
            return $r;
        }, $alertSummary['low_items']);

        // Real totals (the lists above are capped for display); never-stocked items add nothing.
        $totalAlerts = $alertSummary['out'] + $alertSummary['low']
            + (int)($metrics['expiring_3d'] ?? 0) + (int)($metrics['expired_batches'] ?? 0);
        if (($metrics['pending_reconcile'] ?? 0) > 0) $totalAlerts++;
    } catch (Throwable $e) {
        $totalAlerts = 0;
    }
}

// Stock budget tile + 80% / 100% notice (live, never cached; only for people who may see the budget)
$budgetTile = null;
$budgetCanManage = false;
if (!$error && hasPermission((int)$user['id'], 'stock_budget')) {
    $budgetCanManage = hasPermission((int)$user['id'], 'stock_budget_manage');
    try {
        if (rh_sbud_ensure_schema($pdo)) {
            $budgetTile = rh_sbud_dashboard($pdo);
        }
    } catch (Throwable $e) {
        error_log('stock-dashboard budget tile: ' . $e->getMessage());
    }
}

// Count reminder + "Needs review" (live, never cached)
$countReminder = null;
$reviewItems = [];
if (!$error) {
    $countReminder = rh_stock_count_reminder($pdo);
    if ($canReview) {
        $dismissed = rh_stock_review_dismissed((int)$user['id']);
        $reviewItems = array_values(array_filter(
            rh_stock_review_items($pdo, (float)(getSetting('stock_variance_block_pct') ?: 10), (float)(getSetting('stock_variance_min_cost') ?: 5000), (string)$currency_symbol),
            static fn(array $i): bool => !in_array($i['key'], $dismissed, true)
        ));
    }
}

// Health banner state
$healthState = 'ok';
if ($alertSummary['out'] > 0 || ($metrics['expiring_3d'] ?? 0) > 0 || ($metrics['expired_batches'] ?? 0) > 0) {
    $healthState = 'critical';
} elseif ($alertSummary['low'] > 0 || ($metrics['pending_reconcile'] ?? 0) > 0) {
    $healthState = 'warn';
} elseif ($alertSummary['never_stocked'] > 0) {
    $healthState = 'setup';
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
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-dashboard.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-dashboard.css'); ?>">
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-boxes" style="color:var(--color-primary,#766550);"></i> Stock Dashboard</h2>
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
                <?php elseif ($healthState === 'setup'): ?>
                    <i class="fas fa-clipboard-list"></i>
                <?php else: ?>
                    <i class="fas fa-exclamation-triangle"></i>
                <?php endif; ?>
            </div>
            <div class="sdash-health-banner__text">
                <?php if ($healthState === 'ok'): ?>
                    <strong>All good!</strong> Inventory is healthy — no urgent alerts right now.
                <?php elseif ($healthState === 'setup'): ?>
                    <strong>Setup —</strong> Opening stock not recorded for <?php echo (int)$alertSummary['never_stocked']; ?> item(s). <a href="<?php echo htmlspecialchars(rh_stock_opening_count_url()); ?>">Record a stock count</a> to finish setting up stock.
                <?php elseif ($healthState === 'critical'): ?>
                    <strong>Action needed —</strong>
                    <?php
                    $parts = [];
                    if ($alertSummary['out'] > 0) $parts[] = $alertSummary['out'] . ' item(s) out of stock';
                    if (($metrics['expiring_3d'] ?? 0) > 0) $parts[] = ($metrics['expiring_3d']) . ' batch(es) expiring within 3 days';
                    if (($metrics['expired_batches'] ?? 0) > 0) $parts[] = ($metrics['expired_batches']) . ' expired batch(es) still active';
                    echo implode(' &amp; ', $parts) . '.';
                    ?>
                <?php else: ?>
                    <strong>Heads up —</strong>
                    <?php
                    $parts = [];
                    if ($alertSummary['low'] > 0) $parts[] = $alertSummary['low'] . ' item(s) running low';
                    if (($metrics['pending_reconcile'] ?? 0) > 0) $parts[] = ($metrics['pending_reconcile']) . ' charge(s) need reconciling';
                    echo implode(' &amp; ', $parts) . '.';
                    ?>
                <?php endif; ?>
            </div>
            <button class="sdash-health-banner__refresh" onclick="location.reload()" title="Refresh dashboard">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>

        <?php echo rh_stock_count_reminder_html($countReminder, 'stock-count.php'); ?>

        <?php if (!empty($reviewItems)): ?>
        <!-- ═══ NEEDS REVIEW (last 7 days) ═══ -->
        <div class="sdash-section" id="needs-review">
            <div class="sdash-section__header">
                <h3 class="sdash-section__title">
                    Needs review
                    <span class="sdash-badge sdash-badge--red"><?php echo count($reviewItems); ?></span>
                </h3>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="dismiss_review">
                    <input type="hidden" name="dismiss_all" value="1">
                    <button type="submit" class="sdash-btn sdash-btn--sm sdash-btn--ghost">Dismiss all</button>
                </form>
            </div>
            <p style="color:#888;font-size:12px;margin:0 0 8px;">Big count shortages and manual stock changes from the last 7 days. Dismissing only hides an item for you.</p>
            <?php foreach (array_slice($reviewItems, 0, 20) as $ri): ?>
            <div class="sdash-alert-item">
                <div class="sdash-alert-item__info">
                    <span class="sdash-alert-item__name"><?php echo htmlspecialchars($ri['kind']); ?></span>
                    <span class="sdash-alert-item__detail"><?php echo htmlspecialchars($ri['text']); ?> &middot; <?php echo htmlspecialchars(($ri['who'] !== '' ? $ri['who'] : 'Unknown') . ', ' . date('Y-m-d H:i', strtotime($ri['at']))); ?></span>
                </div>
                <div class="sdash-alert-item__actions">
                    <a href="<?php echo htmlspecialchars($ri['link']); ?>" class="sdash-btn sdash-btn--sm sdash-btn--primary">Look</a>
                    <form method="POST" style="margin:0;display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="dismiss_review">
                        <input type="hidden" name="key" value="<?php echo htmlspecialchars($ri['key']); ?>">
                        <button type="submit" class="sdash-btn sdash-btn--sm sdash-btn--ghost">Dismiss</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (count($reviewItems) > 20): ?>
                <p style="color:#888;font-size:12px;margin:8px 0 0;">Showing the newest 20 of <?php echo count($reviewItems); ?>.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($budgetTile && $budgetTile['notice'] !== ''): ?>
        <div class="sdash-setup-note" style="margin:0 0 16px;padding:12px 16px;border:1px solid <?php echo $budgetTile['status'] === 'over' ? '#edc9c3' : '#ecd4a6'; ?>;background:<?php echo $budgetTile['status'] === 'over' ? '#fbeeec' : '#fdf6e7'; ?>;color:<?php echo $budgetTile['status'] === 'over' ? '#8a3a3a' : '#8a6a2f'; ?>;border-radius:8px;">
            <i class="fas fa-scale-balanced"></i> <strong>Stock budget &mdash;</strong> <?php echo htmlspecialchars($budgetTile['notice']); ?>
            <a href="stock-budget.php">See the budget</a>.
        </div>
        <?php endif; ?>

        <!-- ═══ TOP KPI ROW ═══ -->
        <div class="ck-kpis ck-kpis--4">
            <a class="ck-kpi ck-kpi--info" href="stock-orders.php?date=today&status=settled">
                <span class="ck-kpi__icon"><i class="fas fa-cash-register"></i></span>
                <span class="ck-kpi__label">Net Sales Today</span>
                <span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span> <?php echo number_format($metrics['revenue_today'], 0); ?></span>
                <span class="ck-kpi__sub">
                    <?php if (($metrics['orders_paid_today'] ?? null) === null): ?>
                        Could not load today's sales
                    <?php else: ?>
                    <?php echo number_format((int)$metrics['orders_paid_today']); ?> paid order<?php echo (int)$metrics['orders_paid_today'] !== 1 ? 's' : ''; ?>
                    <?php if ((int)$metrics['orders_paid_today'] > 0 && $metrics['revenue_today'] > 0): ?>
                        &middot; avg <?php echo htmlspecialchars($currency_symbol) . ' ' . number_format($metrics['revenue_today'] / $metrics['orders_paid_today'], 0); ?>
                    <?php endif; ?>
                    <?php if ((int)($metrics['reversed_today_n'] ?? 0) > 0): ?>
                        <br>Refunded / voided today: <?php echo number_format((int)$metrics['reversed_today_n']); ?> (<?php echo htmlspecialchars($currency_symbol) . ' ' . number_format((float)$metrics['reversed_today_amt'], 0); ?>)
                    <?php else: ?>
                        &middot; after refunds and voids
                    <?php endif; ?>
                    <?php endif; ?>
                </span>
            </a>

            <a class="ck-kpi <?php echo (int)($metrics['orders_pending'] ?? 0) > 0 ? 'ck-kpi--warn' : 'ck-kpi--good'; ?>" href="stock-orders.php?status=placed">
                <span class="ck-kpi__icon"><i class="fas fa-hourglass-half"></i></span>
                <span class="ck-kpi__label">Open Tabs</span>
                <span class="ck-kpi__value"><?php echo number_format((int)($metrics['orders_pending'] ?? 0)); ?></span>
                <span class="ck-kpi__sub"><?php echo (int)($metrics['orders_pending'] ?? 0) > 0 ? 'Awaiting payment / close-out' : 'No open tabs right now'; ?></span>
            </a>

            <a class="ck-kpi ck-kpi--good" href="stock-ingredients.php">
                <span class="ck-kpi__icon"><i class="fas fa-warehouse"></i></span>
                <span class="ck-kpi__label">Inventory Value</span>
                <span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span> <?php echo number_format($metrics['total_inventory_value'], 0); ?></span>
                <span class="ck-kpi__sub"><?php echo number_format((int)$metrics['ingredient_count']); ?> ingredients tracked</span>
            </a>

            <a class="ck-kpi ck-kpi--warn" href="stock-wastage.php">
                <span class="ck-kpi__icon"><i class="fas fa-trash-alt"></i></span>
                <span class="ck-kpi__label">Wastage</span>
                <span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span> <?php echo number_format($metrics['wastage_30d'], 0); ?></span>
                <span class="ck-kpi__sub">
                    30-day total
                    <?php if (($metrics['wastage_today'] ?? 0) > 0): ?>
                        &middot; today: <?php echo htmlspecialchars($currency_symbol) . ' ' . number_format($metrics['wastage_today'], 0); ?>
                    <?php endif; ?>
                </span>
            </a>
        </div>

        <?php if ($budgetTile || $budgetCanManage): ?>
        <style>
            .sdash-budget { display:block; text-decoration:none; color:inherit; background:#fff; border:1px solid #e6e0d6; border-radius:8px; padding:14px 18px; margin:16px 0; }
            .sdash-budget:hover { border-color:#7E684B; }
            .sdash-budget__head { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; font-size:.95rem; color:#3e3930; }
            .sdash-budget__head strong { font-variant-numeric:tabular-nums; }
            .sdash-budget__bar { height:10px; background:#eee9df; border-radius:5px; overflow:hidden; margin:10px 0 8px; }
            .sdash-budget__bar i { display:block; height:100%; background:#7E684B; }
            .sdash-budget--near .sdash-budget__bar i, .sdash-budget--ahead .sdash-budget__bar i { background:#c9a24a; }
            .sdash-budget--over .sdash-budget__bar i { background:#a25048; }
            .sdash-budget__foot { font-size:.82rem; color:#6a6255; }
        </style>
        <?php if ($budgetTile): $bt = $budgetTile; ?>
        <a class="sdash-budget sdash-budget--<?php echo htmlspecialchars($bt['status']); ?>" href="stock-budget.php">
            <div class="sdash-budget__head"><span><i class="fas fa-scale-balanced"></i> Budget this month &middot; <?php echo htmlspecialchars($bt['label']); ?></span><strong><?php echo number_format($bt['pct_used'], 0); ?>% used</strong></div>
            <div class="sdash-budget__bar"><i style="width:<?php echo (int)min(100, round($bt['pct_used'])); ?>%"></i></div>
            <div class="sdash-budget__foot">
                <?php echo $bt['left'] < -BALANCE_TOLERANCE ? 'Over by ' . htmlspecialchars($currency_symbol) . ' ' . number_format(abs($bt['left']), 0) : 'Left to spend ' . htmlspecialchars($currency_symbol) . ' ' . number_format($bt['left'], 0); ?>
                <?php if ($bt['worst']): ?> &middot; Closest to its limit: <?php echo htmlspecialchars($bt['worst']['label']); ?> (<?php echo number_format($bt['worst']['pct_used'], 0); ?>%)<?php endif; ?>
            </div>
        </a>
        <?php else: ?>
        <a class="sdash-budget" href="stock-budget.php">
            <div class="sdash-budget__head"><span><i class="fas fa-scale-balanced"></i> Budget this month</span><strong style="font-weight:400;color:#8a8172;">Not set &mdash; set one</strong></div>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($alertSummary['never_stocked'] > 0): ?>
        <div class="sdash-setup-note" style="margin:0 0 16px;padding:12px 16px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:8px;">
            <i class="fas fa-clipboard-list"></i>
            <strong>Opening stock not recorded (<?php echo (int)$alertSummary['never_stocked']; ?> items)</strong> &mdash;
            <a href="<?php echo htmlspecialchars(rh_stock_opening_count_url()); ?>">record a stock count</a>.
            Selling is not affected; these items are not counted as alerts until they have stock history.
        </div>
        <?php endif; ?>

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
                    <span>Out of Stock <em>(<?php echo (int)$alertSummary['out']; ?><?php echo $alertSummary['out'] > count($criticalIng) ? ', showing ' . count($criticalIng) : ''; ?>)</em></span>
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
                            <a href="purchase-orders.php" class="sdash-btn sdash-btn--sm sdash-btn--primary" title="Create a purchase order to restock">
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
                    <span>Expiring Soon <em>(<?php echo (int)($metrics['expiring_3d'] ?? count($expiringSoon)); ?><?php echo (int)($metrics['expiring_3d'] ?? 0) > count($expiringSoon) ? ', showing ' . count($expiringSoon) : ''; ?>)</em></span>
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

            <?php if (!empty($expiredBatches)): ?>
            <div class="sdash-alert-group sdash-alert-group--critical js-sdash-alert-group" data-open="true">
                <button type="button" class="sdash-alert-group__header" onclick="toggleAlertGroup(this)" aria-expanded="true">
                    <i class="fas fa-skull-crossbones"></i>
                    <span>Expired Batches Still Active <em>(<?php echo (int)($metrics['expired_batches'] ?? count($expiredBatches)); ?><?php echo (int)($metrics['expired_batches'] ?? 0) > count($expiredBatches) ? ', showing ' . count($expiredBatches) : ''; ?>)</em></span>
                    <i class="fas fa-chevron-down sdash-alert-group__chevron"></i>
                </button>
                <div class="sdash-alert-group__body">
                    <?php foreach ($expiredBatches as $b): ?>
                    <div class="sdash-alert-item">
                        <div class="sdash-alert-item__info">
                            <span class="sdash-alert-item__name"><?php echo htmlspecialchars($b['name']); ?></span>
                            <span class="sdash-alert-item__detail">
                                Batch <?php echo htmlspecialchars($b['batch_number']); ?> &mdash;
                                <strong style="color:#c82333">expired <?php echo (int)$b['days_expired']; ?> day<?php echo (int)$b['days_expired'] !== 1 ? 's' : ''; ?> ago</strong>
                                &middot; <?php echo number_format((float)$b['quantity_remaining'], 1); ?> remaining
                            </span>
                        </div>
                        <div class="sdash-alert-item__actions">
                            <a href="stock-batches.php?filter=expired" class="sdash-btn sdash-btn--sm sdash-btn--primary">
                                <i class="fas fa-trash"></i> Dispose
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
                    <span>Running Low <em>(<?php echo (int)$alertSummary['low']; ?><?php echo $alertSummary['low'] > count($lowStock) ? ', showing ' . count($lowStock) : ''; ?>)</em></span>
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
                            <a href="purchase-orders.php" class="sdash-btn sdash-btn--sm sdash-btn--ghost" title="Create a purchase order to restock">
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
        <?php endif; /* nothing to show when clear — the banner above already says so */ ?>

        <?php /* Inventory Health counts were removed: the banner and Needs Attention above already
                 show out-of-stock, low, expiring and expired items, each with an action. */ ?>

        <?php if (function_exists('isRestaurantEnabled') && isRestaurantEnabled()): ?>
        <!-- ═══ RECIPE COVERAGE ═══ (food-service only — recipes/food-cost don't apply to retail presets) -->
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
        <?php endif; ?>

        <!-- ═══ TOP ITEMS TODAY ═══ -->
        <div class="sdash-section">
            <div class="sdash-section__header">
                <h3 class="sdash-section__title"><i class="fas fa-fire"></i> Top Items Sold Today</h3>
                <a href="stock-orders.php?date=today&status=settled" class="sdash-section__action">All orders →</a>
            </div>
            <?php if (empty($topItemsToday)): ?>
                <p style="color:#888;font-size:13px;padding:10px 0;">No settled orders today yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="border-bottom:2px solid #e5ddd0;text-align:left;">
                            <th style="padding:6px 8px;color:#766550;font-weight:600;font-size:11px;text-transform:uppercase;">Item</th>
                            <th style="padding:6px 8px;color:#766550;font-weight:600;font-size:11px;text-transform:uppercase;text-align:center;">Type</th>
                            <th style="padding:6px 8px;color:#766550;font-weight:600;font-size:11px;text-transform:uppercase;text-align:right;">Qty Sold</th>
                            <th style="padding:6px 8px;color:#766550;font-weight:600;font-size:11px;text-transform:uppercase;text-align:right;">Revenue</th>
                            <th style="padding:6px 8px;color:#766550;font-weight:600;font-size:11px;text-transform:uppercase;text-align:right;">Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topItemsToday as $idx => $item): ?>
                        <tr style="border-bottom:1px solid #f0ece6;<?php echo $idx % 2 === 0 ? '' : 'background:#faf8f5;'; ?>">
                            <td style="padding:8px;font-weight:500;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td style="padding:8px;text-align:center;">
                                <?php
                                $type = strtolower((string)($item['menu_type'] ?? ''));
                                $typeIcon = $type === 'drink' ? '🍹' : ($type === 'food' ? '🍽️' : '📦');
                                echo $typeIcon . ' <small style="color:#888;">' . htmlspecialchars(ucfirst($type ?: 'item')) . '</small>';
                                ?>
                            </td>
                            <td style="padding:8px;text-align:right;font-weight:600;"><?php echo number_format((float)$item['qty_sold'], 1); ?></td>
                            <td style="padding:8px;text-align:right;color:#2e7d32;font-weight:600;"><?php echo $currency_symbol . ' ' . number_format((float)$item['revenue'], 0); ?></td>
                            <td style="padding:8px;text-align:right;color:#888;"><?php echo (int)$item['order_count']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══ WASTAGE BREAKDOWN (30 DAYS) ═══ -->
        <?php if (!empty($mostWastedIngredients)): ?>
        <div class="sdash-section">
            <div class="sdash-section__header">
                <h3 class="sdash-section__title"><i class="fas fa-trash-alt" style="color:#e65100;"></i> Most Wasted Ingredients (30 days)</h3>
                <a href="stock-wastage.php" class="sdash-section__action">All wastage →</a>
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="border-bottom:2px solid #e5ddd0;text-align:left;">
                            <th style="padding:6px 8px;color:#766550;font-weight:600;font-size:11px;text-transform:uppercase;">Ingredient</th>
                            <th style="padding:6px 8px;color:#766550;font-weight:600;font-size:11px;text-transform:uppercase;text-align:right;">Qty Lost</th>
                            <th style="padding:6px 8px;color:#766550;font-weight:600;font-size:11px;text-transform:uppercase;text-align:right;">Cost Lost</th>
                            <th style="padding:6px 8px;color:#766550;font-weight:600;font-size:11px;text-transform:uppercase;text-align:right;">Entries</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mostWastedIngredients as $idx => $w): ?>
                        <tr style="border-bottom:1px solid #f0ece6;<?php echo $idx % 2 === 0 ? '' : 'background:#faf8f5;'; ?>">
                            <td style="padding:8px;font-weight:500;"><?php echo htmlspecialchars($w['name']); ?></td>
                            <td style="padding:8px;text-align:right;"><?php echo number_format((float)$w['total_qty'], 2); ?> <?php echo htmlspecialchars($w['unit']); ?></td>
                            <td style="padding:8px;text-align:right;color:#c62828;font-weight:600;"><?php echo $currency_symbol . ' ' . number_format((float)$w['total_cost'], 0); ?></td>
                            <td style="padding:8px;text-align:right;color:#888;"><?php echo (int)$w['entries']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

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

