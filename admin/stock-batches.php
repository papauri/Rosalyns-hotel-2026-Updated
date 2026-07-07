<?php

/**
 * Stock Management — Batch Tracker
 *
 * Lists batches with expiry tiers, supplier info, and recall/wastage actions.
 * Auto-runs runStockExpiryCheck() on page load.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';

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
if (!$error) {
    $expiredCount = runStockExpiryCheck();
    if ($expiredCount > 0) {
        $message = "{$expiredCount} batch(es) automatically marked as expired.";
    }
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security token invalid.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'mark_wasted') {
                $batchId = (int)($_POST['batch_id'] ?? 0);
                $reason = trim($_POST['reason'] ?? 'Wastage');

                $pdo->beginTransaction();
                $sel = $pdo->prepare("SELECT b.*, i.cost_per_unit AS ing_cost FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE b.id = ? FOR UPDATE");
                $sel->execute([$batchId]);
                $batch = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$batch) throw new RuntimeException('Batch not found.');
                $remaining = (float)$batch['quantity_remaining'];
                if ($remaining <= 0) throw new RuntimeException('Batch already empty.');

                // Wastage row
                $wIns = $pdo->prepare("
                    INSERT INTO stock_wastage (ingredient_id, batch_id, quantity, cost_per_unit, wastage_cost, reason, recorded_date, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?)
                ");
                $cost = (float)$batch['cost_per_unit'];
                $wIns->execute([$batch['ingredient_id'], $batchId, $remaining, $cost, $remaining * $cost, $reason, $user['id']]);

                // Adjustment + zero out batch
                $adj = $pdo->prepare("
                    INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by)
                    VALUES (?, ?, ?, 'wastage', ?, ?, ?)
                ");
                $adj->execute([$batch['ingredient_id'], -$remaining, $reason, $batchId, $cost, $user['id']]);

                $upd = $pdo->prepare("UPDATE stock_batches SET quantity_remaining = 0, status = 'wasted', updated_at = NOW() WHERE id = ?");
                $upd->execute([$batchId]);

                $ingUpd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - ?, updated_at = NOW() WHERE id = ?");
                $ingUpd->execute([$remaining, $batch['ingredient_id']]);

                $pdo->commit();
                $message = 'Batch marked as wasted.';
            } elseif ($action === 'recall') {
                $batchId = (int)($_POST['batch_id'] ?? 0);
                $reason = trim($_POST['reason'] ?? 'Supplier recall');

                $pdo->beginTransaction();
                $sel = $pdo->prepare("SELECT * FROM stock_batches WHERE id = ? FOR UPDATE");
                $sel->execute([$batchId]);
                $batch = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$batch) throw new RuntimeException('Batch not found.');
                $remaining = (float)$batch['quantity_remaining'];

                if ($remaining > 0) {
                    $cost = (float)$batch['cost_per_unit'];
                    $adj = $pdo->prepare("
                        INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by)
                        VALUES (?, ?, ?, 'recall', ?, ?, ?)
                    ");
                    $adj->execute([$batch['ingredient_id'], -$remaining, $reason, $batchId, $cost, $user['id']]);

                    $ingUpd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - ?, updated_at = NOW() WHERE id = ?");
                    $ingUpd->execute([$remaining, $batch['ingredient_id']]);
                }
                $upd = $pdo->prepare("UPDATE stock_batches SET quantity_remaining = 0, status = 'recalled', notes = CONCAT(IFNULL(notes,''), '\nRECALL: ', ?), updated_at = NOW() WHERE id = ?");
                $upd->execute([$reason, $batchId]);
                $pdo->commit();
                $message = 'Batch recalled and removed from stock.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
    if ($message) {
        $_SESSION['stock_msg'] = $message;
        if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v2');
    }
    if ($error)   $_SESSION['stock_err'] = $error;
    header('Location: stock-batches.php' . (!empty($_GET['ingredient_id']) ? '?ingredient_id=' . (int)$_GET['ingredient_id'] : ''));
    exit;
}

if (!empty($_SESSION['stock_msg'])) {
    $message = $_SESSION['stock_msg'];
    unset($_SESSION['stock_msg']);
}
if (!empty($_SESSION['stock_err'])) {
    $error   = $_SESSION['stock_err'];
    unset($_SESSION['stock_err']);
}

$ingredientFilter = isset($_GET['ingredient_id']) ? (int)$_GET['ingredient_id'] : 0;
$statusFilter = $_GET['status'] ?? 'active';
$expiryFilter = $_GET['expiry'] ?? '';

$batches = [];
$ingredients = [];
if (!$error || strpos($error, 'not yet') === false) {
    try {
        $ingredients = $pdo->query("SELECT id, name, unit FROM stock_ingredients WHERE is_archived = 0 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

        $sql = "
            SELECT b.*, i.name AS ingredient_name, i.unit, i.id AS ing_id,
                   u.full_name AS created_by_name,
                   DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry
            FROM stock_batches b
            INNER JOIN stock_ingredients i ON i.id = b.ingredient_id
            LEFT JOIN admin_users u ON u.id = b.created_by
            WHERE 1=1
        ";
        $params = [];
        if ($ingredientFilter > 0) {
            $sql .= " AND b.ingredient_id = ?";
            $params[] = $ingredientFilter;
        }
        if ($statusFilter && $statusFilter !== 'all') {
            $sql .= " AND b.status = ?";
            $params[] = $statusFilter;
        }
        if ($expiryFilter === 'critical') {
            $sql .= " AND b.expiry_date IS NOT NULL AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
        } elseif ($expiryFilter === 'soon') {
            $sql .= " AND b.expiry_date IS NOT NULL AND b.expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 4 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($expiryFilter === 'upcoming') {
            $sql .= " AND b.expiry_date IS NOT NULL AND b.expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 8 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
        }
        $sql .= " ORDER BY (b.expiry_date IS NULL) ASC, b.expiry_date ASC, b.received_date DESC, b.id DESC LIMIT 2000";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = 'Failed to load batches: ' . $e->getMessage();
    }
}

$csrf_token = generateCsrfToken();

function expiry_tier(?string $expiry, ?int $days): array
{
    if (!$expiry || $days === null) return ['none', 'No expiry', '#6c757d'];
    if ($days < 0) return ['expired', 'Expired ' . abs($days) . 'd ago', '#721c24'];
    if ($days <= 3) return ['critical', $days . 'd left', '#c82333'];
    if ($days <= 7) return ['soon', $days . 'd left', '#856404'];
    if ($days <= 30) return ['upcoming', $days . 'd left', '#0c5460'];
    return ['ok', $days . 'd left', '#155724'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Batch Tracker — Stock Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-batches.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-batches.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-layer-group" style="color:var(--color-primary,#8A775F);"></i> Batch Tracker</h2>
            <a href="stock-ingredients.php" class="btn-add"><i class="fas fa-truck-loading"></i> Receive Stock</a>
        </div>

        <?php if ($message): showAlert($message, 'success');
        endif; ?>
        <?php if ($error):   showAlert($error,   'error');
        endif; ?>

        <div style="background:#fff8ef;border:1px solid #efd8b7;border-radius:12px;padding:14px 16px;margin-bottom:18px;color:#5b4a1f;">
            <strong>Batch actions:</strong>
            <span style="margin-left:6px;">Mark Wasted removes damaged or spoiled stock from inventory.</span>
            <span style="margin-left:6px;">Recall removes the batch from active use when a supplier issue or quality problem is found.</span>
        </div>

        <?php
        // Quick metrics
        $cntCritical = $cntSoon = $cntUpcoming = $cntActive = 0;
        $valueAtRisk = 0.0;
        foreach ($batches as $b) {
            if ($b['status'] === 'active') {
                $cntActive++;
                $d = $b['days_to_expiry'];
                if ($d !== null && $d >= 0 && $d <= 3) {
                    $cntCritical++;
                    $valueAtRisk += (float)$b['quantity_remaining'] * (float)$b['cost_per_unit'];
                } elseif ($d !== null && $d > 3 && $d <= 7) {
                    $cntSoon++;
                } elseif ($d !== null && $d > 7 && $d <= 30) {
                    $cntUpcoming++;
                }
            }
        }
        ?>
        <div class="summary-cards">
            <div class="summary-card critical summary-card--interactive js-stock-batches-insight-trigger"
                role="button"
                tabindex="0"
                data-insight-key="critical"
                data-insight-title="Critical Expiry (3 Days or Less)"
                aria-label="Open critical expiry insight">
                <div class="label">Expiring ≤ 3 days</div>
                <div class="value"><?php echo $cntCritical; ?></div>
                <div style="font-size:12px;color:#6c757d;margin-top:4px;">Value at risk: <?php echo $currency_symbol . ' ' . number_format($valueAtRisk, 2); ?></div>
                <div class="summary-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
            </div>

            <div class="summary-card soon summary-card--interactive js-stock-batches-insight-trigger"
                role="button"
                tabindex="0"
                data-insight-key="soon"
                data-insight-title="Expiry Watchlist (4-7 Days)"
                aria-label="Open expiry watchlist insight">
                <div class="label">Expiring 4–7 days</div>
                <div class="value"><?php echo $cntSoon; ?></div>
                <div class="summary-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
            </div>

            <div class="summary-card upcoming summary-card--interactive js-stock-batches-insight-trigger"
                role="button"
                tabindex="0"
                data-insight-key="upcoming"
                data-insight-title="Upcoming Expiry Horizon (8-30 Days)"
                aria-label="Open upcoming expiry horizon insight">
                <div class="label">Expiring 8–30 days</div>
                <div class="value"><?php echo $cntUpcoming; ?></div>
                <div class="summary-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
            </div>

            <div class="summary-card summary-card--interactive js-stock-batches-insight-trigger"
                role="button"
                tabindex="0"
                data-insight-key="active"
                data-insight-title="Active Batch Capacity"
                aria-label="Open active batch capacity insight">
                <div class="label">Total active batches</div>
                <div class="value"><?php echo $cntActive; ?></div>
                <div class="summary-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
            </div>
        </div>

        <div class="modal-overlay" id="stockBatchesInsightModal" style="align-items:flex-start; padding-top:60px;">
            <div class="stock-insight-modal-box">
                <div class="stock-insight-modal-head">
                    <h3 id="stockBatchesInsightTitle" style="margin:0;font-size:18px;">Batch Insight</h3>
                    <button type="button" class="stock-insight-close" onclick="closeM('stockBatchesInsightModal')" aria-label="Close stock batch insight">&times;</button>
                </div>
                <div id="stockBatchesInsightBody"></div>
                <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                    <button type="button" onclick="closeM('stockBatchesInsightModal')" style="padding:9px 16px; background:#e9ecef; border:none; border-radius:6px; cursor:pointer;">Close</button>
                </div>
            </div>
        </div>

        <template id="stock-batches-insight-template-critical">
            <p class="stock-insight-note">Critical batches are at immediate risk of expiry and should be used first, discounted, or removed based on quality.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Expiring in 3 days or less</th><td><?php echo number_format($cntCritical); ?></td></tr>
                    <tr><th>Estimated value at risk</th><td><?php echo $currency_symbol . ' ' . number_format($valueAtRisk, 2); ?></td></tr>
                    <tr><th>Total active batches</th><td><?php echo number_format($cntActive); ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a class="stock-insight-action" href="stock-batches.php?expiry=critical">Open critical batches</a>
                <a class="stock-insight-action stock-insight-action--ghost" href="stock-batches.php?status=active">Open all active batches</a>
            </div>
        </template>

        <template id="stock-batches-insight-template-soon">
            <p class="stock-insight-note">Soon-to-expire batches should be planned into production before they enter the critical window.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Expiring in 4-7 days</th><td><?php echo number_format($cntSoon); ?></td></tr>
                    <tr><th>Critical (<=3 days)</th><td><?php echo number_format($cntCritical); ?></td></tr>
                    <tr><th>Active batches total</th><td><?php echo number_format($cntActive); ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a class="stock-insight-action" href="stock-batches.php?expiry=soon">Open 4-7 day batches</a>
            </div>
        </template>

        <template id="stock-batches-insight-template-upcoming">
            <p class="stock-insight-note">Upcoming batches (8-30 days) help forecast prep sequencing and purchasing cadence.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Expiring in 8-30 days</th><td><?php echo number_format($cntUpcoming); ?></td></tr>
                    <tr><th>Expiring in 4-7 days</th><td><?php echo number_format($cntSoon); ?></td></tr>
                    <tr><th>Expiring in <=3 days</th><td><?php echo number_format($cntCritical); ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a class="stock-insight-action" href="stock-batches.php?expiry=upcoming">Open 8-30 day batches</a>
            </div>
        </template>

        <template id="stock-batches-insight-template-active">
            <p class="stock-insight-note">Active batch capacity represents the inventory currently available for kitchen deductions and sales fulfillment.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Active batches</th><td><?php echo number_format($cntActive); ?></td></tr>
                    <tr><th>Critical expiry count</th><td><?php echo number_format($cntCritical); ?></td></tr>
                    <tr><th>Soon expiry count</th><td><?php echo number_format($cntSoon); ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a class="stock-insight-action" href="stock-batches.php?status=active">Open active batches</a>
                <a class="stock-insight-action stock-insight-action--ghost" href="stock-ingredients.php">Open ingredient stock</a>
            </div>
        </template>

        <form method="GET" class="stock-toolbar">
            <select name="ingredient_id" onchange="this.form.submit()">
                <option value="0">All ingredients</option>
                <?php foreach ($ingredients as $ing): ?>
                    <option value="<?php echo (int)$ing['id']; ?>" <?php echo $ingredientFilter === (int)$ing['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ing['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" onchange="this.form.submit()">
                <?php foreach (['active' => 'Active', 'depleted' => 'Depleted', 'expired' => 'Expired', 'wasted' => 'Wasted', 'recalled' => 'Recalled', 'all' => 'All'] as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $statusFilter === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="expiry" onchange="this.form.submit()">
                <option value="">Any expiry</option>
                <option value="critical" <?php echo $expiryFilter === 'critical' ? 'selected' : ''; ?>>≤ 3 days</option>
                <option value="soon" <?php echo $expiryFilter === 'soon' ? 'selected' : ''; ?>>4 – 7 days</option>
                <option value="upcoming" <?php echo $expiryFilter === 'upcoming' ? 'selected' : ''; ?>>8 – 30 days</option>
            </select>
            <span style="color:#6c757d;font-size:13px;"><?php echo count($batches); ?> batch(es)</span>
        </form>

        <div class="table-responsive">
            <table class="stock-table">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Ingredient</th>
                        <th style="text-align:right;">Received</th>
                        <th style="text-align:right;">Remaining</th>
                        <th style="text-align:right;">Cost / unit</th>
                        <th>Supplier</th>
                        <th>Received on</th>
                        <th>Expiry</th>
                        <th>Status</th>
                        <th>Actions <i class="help" data-tip="Mark Wasted removes stock for spoilage or damage. Recall removes stock because the supplier or product is not safe to keep active.">?</i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $batches_per_page    = 10;
                    $batches_page        = max(1, (int)($_GET['batches_page'] ?? 1));
                    $batches_total       = count($batches);
                    $batches_total_pages = $batches_total > 0 ? (int)ceil($batches_total / $batches_per_page) : 1;
                    $batches_display     = array_slice($batches, ($batches_page - 1) * $batches_per_page, $batches_per_page);
                    ?>
                    <?php foreach ($batches_display as $b):
                        $tier = expiry_tier($b['expiry_date'], $b['days_to_expiry'] !== null ? (int)$b['days_to_expiry'] : null);
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($b['batch_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($b['ingredient_name']); ?></td>
                            <td style="text-align:right;"><?php echo number_format((float)$b['quantity_received'], 3) . ' ' . htmlspecialchars($b['unit']); ?></td>
                            <td style="text-align:right;font-weight:600;"><?php echo number_format((float)$b['quantity_remaining'], 3) . ' ' . htmlspecialchars($b['unit']); ?></td>
                            <td style="text-align:right;"><?php echo $currency_symbol . ' ' . number_format((float)$b['cost_per_unit'], 4); ?></td>
                            <td>
                                <?php echo htmlspecialchars($b['supplier_name'] ?: '—'); ?>
                                <?php if (!empty($b['supplier_contact'])): ?>
                                    <div style="font-size:11px;color:#6c757d;"><?php echo htmlspecialchars($b['supplier_contact']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($b['received_date']); ?></td>
                            <td>
                                <?php if ($b['expiry_date']): ?>
                                    <?php echo htmlspecialchars($b['expiry_date']); ?>
                                    <span class="pill tier-<?php echo $tier[0]; ?>" style="margin-left:6px;"><?php echo htmlspecialchars($tier[1]); ?></span>
                                <?php else: ?>
                                    <span style="color:#6c757d;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="status-<?php echo htmlspecialchars($b['status']); ?>"><?php echo htmlspecialchars(ucfirst($b['status'])); ?></span></td>
                            <td>
                                <?php if ($b['status'] === 'active' && (float)$b['quantity_remaining'] > 0): ?>
                                    <div class="row-actions">
                                        <button type="button" class="btn-danger" onclick='openWasteModal(<?php echo (int)$b['id']; ?>, <?php echo json_encode($b['batch_number']); ?>, <?php echo (float)$b['quantity_remaining']; ?>, <?php echo json_encode($b['unit']); ?>)' title="Remove stock because it is spoiled, damaged or unusable.">
                                            <i class="fas fa-trash-alt"></i> Mark Wasted
                                        </button>
                                        <button type="button" onclick='openRecallModal(<?php echo (int)$b['id']; ?>, <?php echo json_encode($b['batch_number']); ?>)' title="Recall this batch from active stock due to supplier or safety issues.">
                                            <i class="fas fa-undo"></i> Recall
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#6c757d;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($batches)): ?>
                        <tr>
                            <td colspan="10" style="text-align:center;padding:30px;color:#6c757d;">No batches match these filters.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($batches_total_pages > 1): ?>
            <nav style="display:flex;align-items:center;justify-content:center;gap:6px;padding:16px 0;flex-wrap:wrap;">
                <?php for ($pg = 1; $pg <= $batches_total_pages; $pg++):
                    $pgParams = ['ingredient_id' => $ingredientFilter ?: '', 'status' => $statusFilter, 'expiry' => $expiryFilter, 'batches_page' => $pg];
                    $pgHref = 'stock-batches.php?' . http_build_query(array_filter($pgParams, fn($v) => $v !== ''));
                    $pgActive = ($pg === $batches_page);
                ?>
                    <a href="<?php echo htmlspecialchars($pgHref, ENT_QUOTES, 'UTF-8'); ?>"
                        style="padding:6px 12px;border:1px solid <?php echo $pgActive ? 'var(--color-primary,#8A775F)' : '#dee2e6'; ?>;background:<?php echo $pgActive ? 'var(--color-primary,#8A775F)' : '#fff'; ?>;color:<?php echo $pgActive ? '#fff' : '#374151'; ?>;border-radius:4px;font-size:13px;text-decoration:none;"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <span style="padding:6px 8px;font-size:12px;color:#888;">
                    Showing <?php echo (($batches_page - 1) * $batches_per_page) + 1; ?>–<?php echo min($batches_page * $batches_per_page, $batches_total); ?> of <?php echo $batches_total; ?>
                </span>
            </nav>
        <?php endif; ?>
    </div>

    <div class="modal-overlay" id="wasteModal" style="align-items:flex-start; padding-top:60px;">
        <div style="background:#fff; padding:24px; border-radius:12px; width:95%; max-width:480px;">
            <h3 style="margin:0 0 12px;">Mark Batch as Wasted</h3>
            <p style="color:#6c757d; font-size:13px;">Batch <strong id="wm_batch"></strong> — <span id="wm_qty"></span> will be removed from stock and logged as wastage.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="mark_wasted">
                <input type="hidden" name="batch_id" id="wm_id">
                <label style="font-size:12px; font-weight:600;">Reason</label>
                <input type="text" name="reason" required style="width:100%; padding:9px 12px; border:1px solid #d6d8db; border-radius:6px; margin-bottom:14px;" placeholder="e.g. Spoiled, contamination, expired">
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="closeM('wasteModal')" style="padding:9px 16px; background:#e9ecef; border:none; border-radius:6px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="padding:9px 16px; background:#c82333; color:#fff; border:none; border-radius:6px; cursor:pointer;">Confirm Wastage</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="recallModal" style="align-items:flex-start; padding-top:60px;">
        <div style="background:#fff; padding:24px; border-radius:12px; width:95%; max-width:480px;">
            <h3 style="margin:0 0 12px;">Recall Batch</h3>
            <p style="color:#6c757d; font-size:13px;">Batch <strong id="rm_batch"></strong> will be removed from active stock and logged as recalled. Use this when the supplier asks for it back or the batch is not safe to keep active.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="recall">
                <input type="hidden" name="batch_id" id="rm_id">
                <label style="font-size:12px; font-weight:600;">Recall reason</label>
                <input type="text" name="reason" required style="width:100%; padding:9px 12px; border:1px solid #d6d8db; border-radius:6px; margin-bottom:14px;" placeholder="e.g. Supplier recall notice">
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="closeM('recallModal')" style="padding:9px 16px; background:#e9ecef; border:none; border-radius:6px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="padding:9px 16px; background:#8B7355; color:#fff; border:none; border-radius:6px; cursor:pointer;">Confirm Recall</button>
                </div>
            </form>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
    <script>
        window.openM = function(id) {
            document.getElementById(id).classList.add('active');
        };
        window.closeM = function(id) {
            document.getElementById(id).classList.remove('active');
        };
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => {
                if (e.target === m) m.classList.remove('active');
            });
        });
        window.openWasteModal = function(id, batch, qty, unit) {
            document.getElementById('wm_id').value = id;
            document.getElementById('wm_batch').textContent = batch;
            document.getElementById('wm_qty').textContent = qty.toFixed(3) + ' ' + unit;
            openM('wasteModal');
        };
        window.openRecallModal = function(id, batch) {
            document.getElementById('rm_id').value = id;
            document.getElementById('rm_batch').textContent = batch;
            openM('recallModal');
        };

        window.openStockBatchesInsight = function(triggerEl) {
            const key = triggerEl ? triggerEl.getAttribute('data-insight-key') : '';
            if (!key) return;

            const template = document.getElementById('stock-batches-insight-template-' + key);
            const body = document.getElementById('stockBatchesInsightBody');
            const title = document.getElementById('stockBatchesInsightTitle');
            if (!template || !body || !title) return;

            title.textContent = triggerEl.getAttribute('data-insight-title') || 'Batch Insight';
            body.innerHTML = template.innerHTML;
            openM('stockBatchesInsightModal');
        };

        if (!window.__stockBatchesInsightHandlersBound) {
            document.addEventListener('click', function(e) {
                const trigger = e.target.closest('.js-stock-batches-insight-trigger');
                if (!trigger) return;
                e.preventDefault();
                openStockBatchesInsight(trigger);
            });

            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                const trigger = e.target && e.target.closest ? e.target.closest('.js-stock-batches-insight-trigger') : null;
                if (!trigger) return;
                e.preventDefault();
                openStockBatchesInsight(trigger);
            });

            window.__stockBatchesInsightHandlersBound = true;
        }
    </script>
</body>

</html>

