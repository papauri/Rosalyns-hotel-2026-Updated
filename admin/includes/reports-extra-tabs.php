<?php
/**
 * reports-extra-tabs.php
 *
 * Adds four comprehensive reporting tabs to admin/reports.php:
 *   - fnb       : POS / restaurant + bar + coffee bar sales, tabs, voids
 *   - stock     : ingredient usage, wastage, batch/yield, stock value
 *   - staff     : per-user activity (orders fired, KDS bumps, voids, payments)
 *   - voids     : voided orders + cancellations + refunds, with reasons & actors
 *
 * Required outer scope: $pdo, $start_date, $end_date, $active_tab, $currency_symbol
 *
 * All queries are date-bounded by the active filter and gracefully degrade if
 * a table/column is missing (older deployments).
 */
if (!isset($pdo)) { return; }
$start_date  = $start_date  ?? date('Y-m-d');
$end_date    = $end_date    ?? date('Y-m-d');
$active_tab  = $active_tab  ?? 'fnb';
$rp_currency = $currency_symbol ?? '$';
$rp_from = $start_date . ' 00:00:00';
$rp_to   = $end_date . ' 23:59:59';

function rp_safe(callable $fn, $fallback = []) {
    try { return $fn(); } catch (Throwable $e) { error_log('[reports-extra] ' . $e->getMessage()); return $fallback; }
}

/* ---------------- F&B / POS ---------------- */
$fnb = [
    'station_sales' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT
                COALESCE(oi.station, 'kitchen') AS station,
                COUNT(DISTINCT o.id) AS orders,
                SUM(oi.quantity) AS items_qty,
                SUM(oi.line_total) AS revenue
            FROM stock_orders o
            JOIN stock_order_items oi ON oi.order_id = o.id
            WHERE o.created_at BETWEEN ? AND ?
              AND o.status NOT IN ('voided','cancelled')
            GROUP BY oi.station
            ORDER BY revenue DESC");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
    'top_items' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT oi.item_name, oi.menu_type, COALESCE(oi.station,'kitchen') AS station,
                SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue, COUNT(DISTINCT o.id) AS orders
            FROM stock_orders o
            JOIN stock_order_items oi ON oi.order_id = o.id
            WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('voided','cancelled')
            GROUP BY oi.item_name, oi.menu_type, oi.station
            ORDER BY revenue DESC LIMIT 25");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
    'order_types' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT order_type, COUNT(*) AS n, SUM(total_amount) AS revenue, AVG(total_amount) AS avg_check
            FROM stock_orders
            WHERE created_at BETWEEN ? AND ? AND status NOT IN ('voided','cancelled')
            GROUP BY order_type ORDER BY revenue DESC");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
    'payment_split' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT payment_method, COUNT(*) AS n, SUM(amount) AS total
            FROM stock_payments
            WHERE created_at BETWEEN ? AND ? AND status='completed'
            GROUP BY payment_method ORDER BY total DESC");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
    'kitchen_time' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT
                AVG(TIMESTAMPDIFF(SECOND, fired_at, served_at)) AS avg_seconds,
                MIN(TIMESTAMPDIFF(SECOND, fired_at, served_at)) AS min_seconds,
                MAX(TIMESTAMPDIFF(SECOND, fired_at, served_at)) AS max_seconds,
                COUNT(*) AS tickets
            FROM stock_orders
            WHERE fired_at BETWEEN ? AND ? AND served_at IS NOT NULL");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }, []),
    'totals' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT
                COUNT(*) AS orders,
                SUM(CASE WHEN status NOT IN ('voided','cancelled') THEN total_amount ELSE 0 END) AS net_revenue,
                SUM(CASE WHEN status='voided' THEN total_amount ELSE 0 END) AS voided_value,
                AVG(CASE WHEN status NOT IN ('voided','cancelled') THEN total_amount END) AS avg_check
            FROM stock_orders WHERE created_at BETWEEN ? AND ?");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }, []),
];

/* ---------------- Stock / Inventory ---------------- */
$stock = [
    'low_stock' => rp_safe(function() use ($pdo) {
        $st = $pdo->query("SELECT name, current_stock, min_stock_level, unit FROM stock_ingredients
            WHERE is_active=1 AND current_stock <= min_stock_level
            ORDER BY (current_stock / NULLIF(min_stock_level,0)) ASC LIMIT 30");
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
    'stock_value' => rp_safe(function() use ($pdo) {
        $st = $pdo->query("SELECT
                SUM(current_stock * COALESCE(unit_cost, 0)) AS total_value,
                COUNT(*) AS active_items,
                SUM(CASE WHEN current_stock <= min_stock_level THEN 1 ELSE 0 END) AS low_count,
                SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END) AS oos_count
            FROM stock_ingredients WHERE is_active=1");
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }, []),
    'wastage' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        // stock_adjustments may carry waste/spoilage entries with reason='wastage' or adjustment_type='waste'
        $st = $pdo->prepare("SELECT i.name AS ingredient, ABS(SUM(sa.quantity_change)) AS qty,
                ABS(SUM(sa.quantity_change * COALESCE(i.unit_cost, 0))) AS value
            FROM stock_adjustments sa
            JOIN stock_ingredients i ON i.id = sa.ingredient_id
            WHERE sa.created_at BETWEEN ? AND ?
              AND (sa.adjustment_type='waste' OR sa.reason LIKE '%wast%' OR sa.reason LIKE '%spoil%')
            GROUP BY i.id ORDER BY value DESC LIMIT 20");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
    'top_used' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT i.name, ABS(SUM(sa.quantity_change)) AS qty,
                ABS(SUM(sa.quantity_change * COALESCE(i.unit_cost, 0))) AS cost
            FROM stock_adjustments sa
            JOIN stock_ingredients i ON i.id = sa.ingredient_id
            WHERE sa.created_at BETWEEN ? AND ? AND sa.quantity_change < 0
              AND sa.adjustment_type IN ('pos_order','sale','consumption','recipe')
            GROUP BY i.id ORDER BY cost DESC LIMIT 20");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
];

/* ---------------- Staff Activity ---------------- */
$staff = [
    'pos_actions' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT u.id, u.username, u.full_name, u.role,
                COUNT(o.id) AS orders_created,
                SUM(o.total_amount) AS revenue_handled,
                SUM(CASE WHEN o.status='voided' THEN 1 ELSE 0 END) AS voids_caused
            FROM admin_users u
            LEFT JOIN stock_orders o ON o.created_by = u.id AND o.created_at BETWEEN ? AND ?
            WHERE u.is_active=1
            GROUP BY u.id HAVING orders_created > 0
            ORDER BY revenue_handled DESC");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
    'kds_actions' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT user_name, event, COUNT(*) AS n
            FROM stock_kds_events
            WHERE created_at BETWEEN ? AND ?
            GROUP BY user_name, event
            ORDER BY user_name, n DESC");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
    'voids_by_user' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT u.username, u.full_name, COUNT(*) AS voids,
                SUM(o.total_amount) AS voided_value
            FROM stock_orders o
            JOIN admin_users u ON u.id = o.voided_by
            WHERE o.voided_at BETWEEN ? AND ?
            GROUP BY u.id ORDER BY voids DESC");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
    'logins' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        // Best-effort: works if admin_audit_log or admin_users.last_login_at exists
        $st = $pdo->prepare("SELECT u.username, u.full_name, u.role, u.last_login_at
            FROM admin_users u
            WHERE u.is_active=1 AND u.last_login_at BETWEEN ? AND ?
            ORDER BY u.last_login_at DESC LIMIT 50");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
];

/* ---------------- Voids / Refunds ---------------- */
$voids = [
    'list' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT o.id, o.reference, o.total_amount, o.voided_at, o.void_reason,
                u.username AS voided_by_name, u2.username AS created_by_name, o.order_type
            FROM stock_orders o
            LEFT JOIN admin_users u  ON u.id  = o.voided_by
            LEFT JOIN admin_users u2 ON u2.id = o.created_by
            WHERE o.status='voided' AND o.voided_at BETWEEN ? AND ?
            ORDER BY o.voided_at DESC LIMIT 200");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
    'totals' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT
                COUNT(*) AS n,
                SUM(total_amount) AS value,
                COUNT(DISTINCT voided_by) AS distinct_actors
            FROM stock_orders WHERE status='voided' AND voided_at BETWEEN ? AND ?");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }, []),
    'refunds' => rp_safe(function() use ($pdo, $rp_from, $rp_to) {
        $st = $pdo->prepare("SELECT id, payment_reference, amount, refund_reason, refunded_at, refunded_by
            FROM stock_payments
            WHERE status='refunded' AND refunded_at BETWEEN ? AND ?
            ORDER BY refunded_at DESC LIMIT 100");
        $st->execute([$rp_from, $rp_to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }),
];
?>

<!-- F&B / POS Tab -->
<div class="tab-content <?php echo $active_tab === 'fnb' ? 'active' : ''; ?>" id="tab-fnb">
    <div class="rx-kpis">
        <div class="rx-kpi"><div class="lbl"><?php echo htmlspecialchars(rh_pos_short_label()); ?> Net Revenue</div><div class="val"><?php echo $rp_currency.' '.number_format((float)($fnb['totals']['net_revenue'] ?? 0), 2); ?></div></div>
        <div class="rx-kpi"><div class="lbl">Orders</div><div class="val"><?php echo number_format((int)($fnb['totals']['orders'] ?? 0)); ?></div></div>
        <div class="rx-kpi"><div class="lbl">Avg Check</div><div class="val"><?php echo $rp_currency.' '.number_format((float)($fnb['totals']['avg_check'] ?? 0), 2); ?></div></div>
        <div class="rx-kpi"><div class="lbl">Voided Value</div><div class="val" style="color:#c82333;"><?php echo $rp_currency.' '.number_format((float)($fnb['totals']['voided_value'] ?? 0), 2); ?></div></div>
        <div class="rx-kpi"><div class="lbl">Avg Kitchen Time</div><div class="val"><?php $sec = (int)($fnb['kitchen_time']['avg_seconds'] ?? 0); echo $sec ? floor($sec/60).'m '.($sec%60).'s' : '—'; ?></div><div class="sub"><?php echo number_format((int)($fnb['kitchen_time']['tickets'] ?? 0)); ?> tickets</div></div>
    </div>

    <div class="rx-grid-2">
        <div class="rx-section">
            <h2><i class="fas fa-store"></i> Sales by Station</h2>
            <?php if (!$fnb['station_sales']): ?><div class="rx-empty">No station sales</div><?php else: ?>
            <table class="rx-table"><thead><tr><th>Station</th><th class="num">Orders</th><th class="num">Items</th><th class="num">Revenue</th></tr></thead><tbody>
            <?php foreach ($fnb['station_sales'] as $s): ?>
                <tr>
                    <td><span class="rx-station-pill <?php echo htmlspecialchars($s['station']); ?>"><?php echo htmlspecialchars(str_replace('_',' ', $s['station'])); ?></span></td>
                    <td class="num"><?php echo number_format((int)$s['orders']); ?></td>
                    <td class="num"><?php echo number_format((float)$s['items_qty'], 1); ?></td>
                    <td class="num"><?php echo $rp_currency.' '.number_format((float)$s['revenue'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </div>

        <div class="rx-section">
            <h2><i class="fas fa-credit-card"></i> Payment Methods</h2>
            <?php if (!$fnb['payment_split']): ?><div class="rx-empty">No payments recorded</div><?php else: ?>
            <table class="rx-table"><thead><tr><th>Method</th><th class="num">#</th><th class="num">Total</th></tr></thead><tbody>
            <?php foreach ($fnb['payment_split'] as $p): ?>
                <tr><td><?php echo htmlspecialchars(str_replace('_',' ', $p['payment_method'])); ?></td><td class="num"><?php echo number_format((int)$p['n']); ?></td><td class="num"><?php echo $rp_currency.' '.number_format((float)$p['total'], 2); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </div>
    </div>

    <div class="rx-section">
        <h2><i class="fas fa-list-ol"></i> Top Items</h2>
        <?php if (!$fnb['top_items']): ?><div class="rx-empty">No items sold</div><?php else: ?>
        <table class="rx-table"><thead><tr><th>#</th><th>Item</th><th>Type</th><th>Station</th><th class="num">Qty</th><th class="num">Orders</th><th class="num">Revenue</th></tr></thead><tbody>
        <?php foreach ($fnb['top_items'] as $i => $it): ?>
            <tr>
                <td><?php echo $i+1; ?></td>
                <td><?php echo htmlspecialchars($it['item_name']); ?></td>
                <td><?php echo htmlspecialchars($it['menu_type']); ?></td>
                <td><span class="rx-station-pill <?php echo htmlspecialchars($it['station']); ?>"><?php echo htmlspecialchars(str_replace('_',' ',$it['station'])); ?></span></td>
                <td class="num"><?php echo number_format((float)$it['qty'], 1); ?></td>
                <td class="num"><?php echo number_format((int)$it['orders']); ?></td>
                <td class="num"><?php echo $rp_currency.' '.number_format((float)$it['revenue'], 2); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </div>

    <div class="rx-section">
        <h2><i class="fas fa-utensils"></i> Order Types</h2>
        <?php if (!$fnb['order_types']): ?><div class="rx-empty">No data</div><?php else: ?>
        <table class="rx-table"><thead><tr><th>Type</th><th class="num">Orders</th><th class="num">Revenue</th><th class="num">Avg Check</th></tr></thead><tbody>
        <?php foreach ($fnb['order_types'] as $ot): ?>
            <tr><td><?php echo htmlspecialchars(str_replace('_',' ', $ot['order_type'])); ?></td><td class="num"><?php echo number_format((int)$ot['n']); ?></td><td class="num"><?php echo $rp_currency.' '.number_format((float)$ot['revenue'], 2); ?></td><td class="num"><?php echo $rp_currency.' '.number_format((float)$ot['avg_check'], 2); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </div>
</div>

<!-- Stock Tab -->
<div class="tab-content <?php echo $active_tab === 'stock' ? 'active' : ''; ?>" id="tab-stock">
    <div class="rx-kpis">
        <div class="rx-kpi"><div class="lbl">Stock Value (Now)</div><div class="val"><?php echo $rp_currency.' '.number_format((float)($stock['stock_value']['total_value'] ?? 0), 2); ?></div></div>
        <div class="rx-kpi"><div class="lbl">Active SKUs</div><div class="val"><?php echo number_format((int)($stock['stock_value']['active_items'] ?? 0)); ?></div></div>
        <div class="rx-kpi"><div class="lbl">Low Stock</div><div class="val" style="color:#d4a843;"><?php echo number_format((int)($stock['stock_value']['low_count'] ?? 0)); ?></div></div>
        <div class="rx-kpi"><div class="lbl">Out of Stock</div><div class="val" style="color:#c82333;"><?php echo number_format((int)($stock['stock_value']['oos_count'] ?? 0)); ?></div></div>
    </div>

    <div class="rx-grid-2">
        <div class="rx-section">
            <h2><i class="fas fa-arrow-down"></i> Low Stock Alerts</h2>
            <?php if (!$stock['low_stock']): ?><div class="rx-empty">All ingredients above reorder point</div><?php else: ?>
            <table class="rx-table"><thead><tr><th>Ingredient</th><th class="num">Current</th><th class="num">Min</th><th>Unit</th></tr></thead><tbody>
            <?php foreach ($stock['low_stock'] as $l): ?>
                <tr><td><?php echo htmlspecialchars($l['name']); ?></td><td class="num" style="color:<?php echo $l['current_stock']==0 ? '#c82333' : '#d4a843'; ?>;"><?php echo number_format((float)$l['current_stock'], 2); ?></td><td class="num"><?php echo number_format((float)$l['min_stock_level'], 2); ?></td><td><?php echo htmlspecialchars($l['unit']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </div>

        <div class="rx-section">
            <h2><i class="fas fa-trash"></i> Wastage</h2>
            <?php if (!$stock['wastage']): ?><div class="rx-empty">No wastage logged</div><?php else: ?>
            <table class="rx-table"><thead><tr><th>Ingredient</th><th class="num">Qty</th><th class="num">Value</th></tr></thead><tbody>
            <?php foreach ($stock['wastage'] as $w): ?>
                <tr><td><?php echo htmlspecialchars($w['ingredient']); ?></td><td class="num"><?php echo number_format((float)$w['qty'], 2); ?></td><td class="num" style="color:#c82333;"><?php echo $rp_currency.' '.number_format((float)$w['value'], 2); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </div>
    </div>

    <div class="rx-section">
        <h2><i class="fas fa-chart-pie"></i> Top Consumed Ingredients (by cost)</h2>
        <?php if (!$stock['top_used']): ?><div class="rx-empty">No consumption data</div><?php else: ?>
        <table class="rx-table"><thead><tr><th>#</th><th>Ingredient</th><th class="num">Qty Used</th><th class="num">Cost</th></tr></thead><tbody>
        <?php foreach ($stock['top_used'] as $i => $u): ?>
            <tr><td><?php echo $i+1; ?></td><td><?php echo htmlspecialchars($u['name']); ?></td><td class="num"><?php echo number_format((float)$u['qty'], 2); ?></td><td class="num"><?php echo $rp_currency.' '.number_format((float)$u['cost'], 2); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </div>
</div>

<!-- Staff Activity Tab -->
<div class="tab-content <?php echo $active_tab === 'staff' ? 'active' : ''; ?>" id="tab-staff">
    <div class="rx-section">
        <h2><i class="fas fa-cash-register"></i> POS Activity by User</h2>
        <?php if (!$staff['pos_actions']): ?><div class="rx-empty">No POS activity</div><?php else: ?>
        <table class="rx-table"><thead><tr><th>User</th><th>Role</th><th class="num">Orders</th><th class="num">Revenue Handled</th><th class="num">Voids Caused</th></tr></thead><tbody>
        <?php foreach ($staff['pos_actions'] as $u): ?>
            <tr><td><strong><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?></strong> <span style="color:#6c757d; font-size:11px;">(<?php echo htmlspecialchars($u['username']); ?>)</span></td><td><?php echo htmlspecialchars($u['role']); ?></td><td class="num"><?php echo number_format((int)$u['orders_created']); ?></td><td class="num"><?php echo $rp_currency.' '.number_format((float)$u['revenue_handled'], 2); ?></td><td class="num" style="color:<?php echo $u['voids_caused']>0?'#c82333':'#28a745'; ?>;"><?php echo number_format((int)$u['voids_caused']); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </div>

    <div class="rx-grid-2">
        <div class="rx-section">
            <h2><i class="fas fa-fire"></i> KDS / BDS / CDS Actions</h2>
            <?php if (!$staff['kds_actions']): ?><div class="rx-empty">No station activity</div><?php else: ?>
            <table class="rx-table"><thead><tr><th>User</th><th>Action</th><th class="num">Count</th></tr></thead><tbody>
            <?php foreach ($staff['kds_actions'] as $k): ?>
                <tr><td><?php echo htmlspecialchars($k['user_name'] ?: '—'); ?></td><td><?php echo htmlspecialchars($k['event']); ?></td><td class="num"><?php echo number_format((int)$k['n']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </div>

        <div class="rx-section">
            <h2><i class="fas fa-sign-in-alt"></i> Recent Logins</h2>
            <?php if (!$staff['logins']): ?><div class="rx-empty">No login records in range</div><?php else: ?>
            <table class="rx-table"><thead><tr><th>User</th><th>Role</th><th>Last Login</th></tr></thead><tbody>
            <?php foreach ($staff['logins'] as $l): ?>
                <tr><td><?php echo htmlspecialchars($l['full_name'] ?: $l['username']); ?></td><td><?php echo htmlspecialchars($l['role']); ?></td><td><?php echo htmlspecialchars($l['last_login_at'] ?: '—'); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Voids / Refunds Tab -->
<div class="tab-content <?php echo $active_tab === 'voids' ? 'active' : ''; ?>" id="tab-voids">
    <div class="rx-kpis">
        <div class="rx-kpi"><div class="lbl">Voided Orders</div><div class="val" style="color:#c82333;"><?php echo number_format((int)($voids['totals']['n'] ?? 0)); ?></div></div>
        <div class="rx-kpi"><div class="lbl">Voided Value</div><div class="val" style="color:#c82333;"><?php echo $rp_currency.' '.number_format((float)($voids['totals']['value'] ?? 0), 2); ?></div></div>
        <div class="rx-kpi"><div class="lbl">Distinct Voiders</div><div class="val"><?php echo number_format((int)($voids['totals']['distinct_actors'] ?? 0)); ?></div></div>
        <div class="rx-kpi"><div class="lbl">Refunds</div><div class="val"><?php echo number_format(count($voids['refunds'])); ?></div></div>
    </div>

    <div class="rx-section">
        <h2><i class="fas fa-ban"></i> Voided Orders</h2>
        <?php if (!$voids['list']): ?><div class="rx-empty">No voids in this period — clean sweep.</div><?php else: ?>
        <table class="rx-table"><thead><tr><th>When</th><th>Reference</th><th>Type</th><th>Created by</th><th>Voided by</th><th class="num">Value</th><th>Reason</th><th></th></tr></thead><tbody>
        <?php foreach ($voids['list'] as $v): ?>
            <tr><td><?php echo htmlspecialchars($v['voided_at']); ?></td><td><strong><?php echo htmlspecialchars($v['reference']); ?></strong></td><td><?php echo htmlspecialchars($v['order_type']); ?></td><td><?php echo htmlspecialchars($v['created_by_name'] ?: '—'); ?></td><td><?php echo htmlspecialchars($v['voided_by_name'] ?: '—'); ?></td><td class="num"><?php echo $rp_currency.' '.number_format((float)$v['total_amount'], 2); ?></td><td style="max-width:300px; font-size:12px;"><?php echo htmlspecialchars($v['void_reason']); ?></td><td><a href="order-lifecycle.php?id=<?php echo (int)$v['id']; ?>" target="_blank" rel="noopener" style="color:#8B7355; font-size:12px;"><i class="fas fa-stream"></i></a></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </div>

    <?php if ($staff['voids_by_user']): ?>
    <div class="rx-section">
        <h2><i class="fas fa-user-shield"></i> Voids by User</h2>
        <table class="rx-table"><thead><tr><th>User</th><th class="num">Voids</th><th class="num">Voided Value</th></tr></thead><tbody>
        <?php foreach ($staff['voids_by_user'] as $vu): ?>
            <tr><td><?php echo htmlspecialchars($vu['full_name'] ?: $vu['username']); ?></td><td class="num"><?php echo number_format((int)$vu['voids']); ?></td><td class="num"><?php echo $rp_currency.' '.number_format((float)$vu['voided_value'], 2); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <?php endif; ?>

    <?php if ($voids['refunds']): ?>
    <div class="rx-section">
        <h2><i class="fas fa-undo"></i> Refunds</h2>
        <table class="rx-table"><thead><tr><th>When</th><th>Reference</th><th class="num">Amount</th><th>Reason</th></tr></thead><tbody>
        <?php foreach ($voids['refunds'] as $r): ?>
            <tr><td><?php echo htmlspecialchars($r['refunded_at']); ?></td><td><?php echo htmlspecialchars($r['payment_reference']); ?></td><td class="num"><?php echo $rp_currency.' '.number_format((float)$r['amount'], 2); ?></td><td><?php echo htmlspecialchars($r['refund_reason']); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <?php endif; ?>
</div>

