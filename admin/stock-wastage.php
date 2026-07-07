<?php

/**
 * Stock Management — Wastage Log
 *
 * Bulk daily wastage entry + history with cost analytics.
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

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security token invalid.';
    } else {
        try {
            $date    = $_POST['recorded_date'] ?? date('Y-m-d');
            $ingIds  = $_POST['ingredient_id'] ?? [];
            $qtys    = $_POST['quantity'] ?? [];
            $reasons = $_POST['reason'] ?? [];

            $count = is_array($ingIds) ? count($ingIds) : 0;
            $saved = 0;

            $pdo->beginTransaction();
            $costSel = $pdo->prepare("SELECT cost_per_unit, current_quantity FROM stock_ingredients WHERE id = ? FOR UPDATE");
            $wIns = $pdo->prepare("
                INSERT INTO stock_wastage (ingredient_id, batch_id, quantity, cost_per_unit, wastage_cost, reason, recorded_date, recorded_by)
                VALUES (?, NULL, ?, ?, ?, ?, ?, ?)
            ");
            // Update only the reason on the stock_adjustments record — cost_at_time is already set
            // correctly by deductStockBatchFIFO using actual FIFO batch weighted average.
            $adjReasonUpd = $pdo->prepare('UPDATE stock_adjustments SET reason = ? WHERE id = ?');
            $wastageActualCostUpd = $pdo->prepare('UPDATE stock_wastage SET cost_per_unit = ?, wastage_cost = quantity * ? WHERE id = ?');

            for ($k = 0; $k < $count; $k++) {
                $iid = (int)($ingIds[$k] ?? 0);
                $q = (float)($qtys[$k] ?? 0);
                $rs = trim($reasons[$k] ?? 'Wastage');
                if ($iid <= 0 || $q <= 0) continue;

                $costSel->execute([$iid]);
                $row = $costSel->fetch(PDO::FETCH_ASSOC);
                if (!$row) continue;
                $cost = (float)$row['cost_per_unit'];
                $currentQty = (float)$row['current_quantity'];

                if ($q > $currentQty + 0.0001) {
                    throw new RuntimeException('Wastage quantity exceeds current stock for ingredient #' . $iid . '.');
                }

                $wIns->execute([$iid, $q, $cost, $q * $cost, $rs, $date, $user['id']]);
                $wastageId = (int)$pdo->lastInsertId();

                if (!function_exists('deductStockBatchFIFO')) {
                    throw new RuntimeException('Stock engine helper deductStockBatchFIFO is missing.');
                }
                if (function_exists('ensureStockBatchCoverageForDeduction')) {
                    ensureStockBatchCoverageForDeduction(
                        $iid,
                        $q,
                        $cost,
                        $user['id'],
                        'Auto batch sync before wastage entry ' . $wastageId
                    );
                }

                $adjId = (int)(deductStockBatchFIFO($iid, $q, 'wastage', $wastageId, $user['id']) ?? 0);
                if ($adjId <= 0) {
                    throw new RuntimeException('Failed to apply wastage deduction for ingredient #' . $iid . '.');
                }
                $adjReasonUpd->execute([mb_substr($rs, 0, 255), $adjId]);
                // Backfill wastage record with actual FIFO weighted cost (more accurate than ingredient average)
                $actualCostStmt = $pdo->prepare('SELECT cost_at_time FROM stock_adjustments WHERE id = ? LIMIT 1');
                $actualCostStmt->execute([$adjId]);
                $actualCost = (float)($actualCostStmt->fetchColumn() ?: $cost);
                $wastageActualCostUpd->execute([$actualCost, $actualCost, $wastageId]);
                $saved++;
            }
            $pdo->commit();

            if ($saved === 0) throw new RuntimeException('No valid wastage rows submitted.');
            $message = "{$saved} wastage entry(ies) recorded.";
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
    header('Location: stock-wastage.php');
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

$ingredients = [];
$entries = [];
$daysSinceLast = null;
$totalThisMonth = 0;
$totalLastMonth = 0;
$topWastedItems = [];

if (!$error || strpos($error, 'not yet') === false) {
    try {
        $ingredients = $pdo->query("SELECT id, name, unit, cost_per_unit FROM stock_ingredients WHERE is_archived = 0 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

        $entries = $pdo->query("
            SELECT w.*, i.name AS ingredient_name, i.unit, u.full_name AS recorded_by_name
            FROM stock_wastage w
            INNER JOIN stock_ingredients i ON i.id = w.ingredient_id
            LEFT JOIN admin_users u ON u.id = w.recorded_by
            ORDER BY w.recorded_date DESC, w.created_at DESC
            LIMIT 2000
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("SELECT MAX(recorded_date) FROM stock_wastage");
        $last = $stmt->fetchColumn();
        if ($last) {
            $daysSinceLast = (int)((new DateTime('today'))->diff(new DateTime($last))->format('%r%a'));
            $daysSinceLast = abs($daysSinceLast);
        }

        $totalThisMonth = (float)$pdo->query("SELECT COALESCE(SUM(wastage_cost), 0) FROM stock_wastage WHERE YEAR(recorded_date) = YEAR(CURDATE()) AND MONTH(recorded_date) = MONTH(CURDATE())")->fetchColumn();
        $totalLastMonth = (float)$pdo->query("SELECT COALESCE(SUM(wastage_cost), 0) FROM stock_wastage WHERE YEAR(recorded_date) = YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(recorded_date) = MONTH(CURDATE() - INTERVAL 1 MONTH)")->fetchColumn();

        $topWastedItems = $pdo->query("
            SELECT i.name, i.unit, SUM(w.quantity) AS total_qty, SUM(w.wastage_cost) AS total_cost
            FROM stock_wastage w
            INNER JOIN stock_ingredients i ON i.id = w.ingredient_id
            WHERE w.recorded_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY i.id, i.name, i.unit
            ORDER BY total_cost DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = 'Failed to load: ' . $e->getMessage();
    }
}

$wastageMonthDelta = $totalThisMonth - $totalLastMonth;
$wastageMonthDeltaPct = $totalLastMonth > 0
    ? round(($wastageMonthDelta / $totalLastMonth) * 100, 1)
    : null;

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Wastage Log — Stock Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-wastage.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-wastage.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-trash-alt" style="color:#8B7355;"></i> Wastage Log</h2>
        </div>

        <?php if ($message): showAlert($message, 'success');
        endif; ?>
        <?php if ($error):   showAlert($error,   'error');
        endif; ?>

        <?php if ($daysSinceLast !== null && $daysSinceLast > 3): ?>
            <div class="reminder-banner">
                <i class="fas fa-exclamation-triangle"></i> It has been <strong><?php echo $daysSinceLast; ?> days</strong> since the last wastage entry. Daily logging keeps inventory accurate.
            </div>
        <?php elseif ($daysSinceLast === null): ?>
            <div class="reminder-banner">
                <i class="fas fa-info-circle"></i> No wastage has been recorded yet. Use the form below to log spoilage, breakage and over-prep.
            </div>
        <?php endif; ?>

        <div class="summary-cards">
            <div class="summary-card warning summary-card--interactive js-stock-wastage-insight-trigger"
                role="button"
                tabindex="0"
                data-insight-key="this-month"
                data-insight-title="Current Month Wastage"
                aria-label="Open current month wastage insight">
                <div class="label">This month wastage</div>
                <div class="value"><?php echo $currency_symbol . ' ' . number_format($totalThisMonth, 2); ?></div>
                <div class="summary-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
            </div>

            <div class="summary-card summary-card--interactive js-stock-wastage-insight-trigger"
                role="button"
                tabindex="0"
                data-insight-key="last-month"
                data-insight-title="Previous Month Baseline"
                aria-label="Open previous month baseline insight">
                <div class="label">Last month</div>
                <div class="value"><?php echo $currency_symbol . ' ' . number_format($totalLastMonth, 2); ?></div>
                <div class="summary-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
            </div>

            <div class="summary-card summary-card--interactive js-stock-wastage-insight-trigger"
                role="button"
                tabindex="0"
                data-insight-key="recency"
                data-insight-title="Logging Recency Health"
                aria-label="Open wastage logging recency health">
                <div class="label">Days since last entry</div>
                <div class="value"><?php echo $daysSinceLast === null ? '—' : $daysSinceLast; ?></div>
                <div class="summary-card__hint"><i class="fas fa-table-list"></i> Open detail</div>
            </div>
        </div>

        <div class="modal-overlay" id="stockWastageInsightModal" style="align-items:flex-start; padding-top:60px;">
            <div class="stock-insight-modal-box">
                <div class="stock-insight-modal-head">
                    <h3 id="stockWastageInsightTitle" style="margin:0;font-size:18px;">Wastage Insight</h3>
                    <button type="button" class="stock-insight-close" onclick="closeStockWastageInsight()" aria-label="Close wastage insight">&times;</button>
                </div>
                <div id="stockWastageInsightBody"></div>
                <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                    <button type="button" onclick="closeStockWastageInsight()" style="padding:9px 16px; background:#e9ecef; border:none; border-radius:6px; cursor:pointer;">Close</button>
                </div>
            </div>
        </div>

        <template id="stock-wastage-insight-template-this-month">
            <p class="stock-insight-note">Current month wastage should be monitored against last month and top-loss ingredients to catch process drift quickly.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>This month wastage</th><td><?php echo $currency_symbol . ' ' . number_format($totalThisMonth, 2); ?></td></tr>
                    <tr><th>Last month reference</th><td><?php echo $currency_symbol . ' ' . number_format($totalLastMonth, 2); ?></td></tr>
                    <tr><th>Delta vs last month</th><td><?php echo ($wastageMonthDelta >= 0 ? '+' : '') . $currency_symbol . ' ' . number_format($wastageMonthDelta, 2); ?></td></tr>
                    <tr><th>Delta percent</th><td><?php echo $wastageMonthDeltaPct !== null ? (($wastageMonthDeltaPct >= 0 ? '+' : '') . $wastageMonthDeltaPct . '%') : 'No prior baseline'; ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a class="stock-insight-action" href="stock-wastage.php#wastageEntries">Review recent entries</a>
                <a class="stock-insight-action stock-insight-action--ghost" href="stock-wastage.php#wastage-entry-section">Record new wastage</a>
            </div>
        </template>

        <template id="stock-wastage-insight-template-last-month">
            <p class="stock-insight-note">Last month provides the baseline to evaluate whether this month is improving or deteriorating.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Last month wastage</th><td><?php echo $currency_symbol . ' ' . number_format($totalLastMonth, 2); ?></td></tr>
                    <tr><th>This month wastage</th><td><?php echo $currency_symbol . ' ' . number_format($totalThisMonth, 2); ?></td></tr>
                    <tr><th>Change amount</th><td><?php echo ($wastageMonthDelta >= 0 ? '+' : '') . $currency_symbol . ' ' . number_format($wastageMonthDelta, 2); ?></td></tr>
                    <tr><th>Change percent</th><td><?php echo $wastageMonthDeltaPct !== null ? (($wastageMonthDeltaPct >= 0 ? '+' : '') . $wastageMonthDeltaPct . '%') : 'No prior baseline'; ?></td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a class="stock-insight-action" href="stock-wastage.php#wastageEntries">Open history view</a>
            </div>
        </template>

        <template id="stock-wastage-insight-template-recency">
            <p class="stock-insight-note">Recency checks whether wastage logging is consistently maintained for reliable inventory and cost reporting.</p>
            <table class="stock-insight-table">
                <tbody>
                    <tr><th>Days since last wastage entry</th><td><?php echo $daysSinceLast === null ? 'No entries yet' : number_format((int)$daysSinceLast); ?></td></tr>
                    <tr><th>Current month wastage</th><td><?php echo $currency_symbol . ' ' . number_format($totalThisMonth, 2); ?></td></tr>
                    <tr><th>Recommended cadence</th><td>Log daily or at each disposal event</td></tr>
                </tbody>
            </table>
            <div class="stock-insight-actions">
                <a class="stock-insight-action" href="stock-wastage.php#wastage-entry-section">Record new wastage now</a>
            </div>
        </template>

        <?php if (!empty($topWastedItems)): ?>
            <div class="wastage-card">
                <h4 class="wastage-card__title">Top wasted items (last 30 days)</h4>
                <table class="wastage-mini-table">
                    <?php foreach ($topWastedItems as $t): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($t['name']); ?></td>
                            <td class="wastage-mini-table__muted"><?php echo number_format((float)$t['total_qty'], 3) . ' ' . htmlspecialchars($t['unit']); ?></td>
                            <td class="wastage-mini-table__cost"><?php echo $currency_symbol . ' ' . number_format((float)$t['total_cost'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

        <div class="wastage-card wastage-entry-card" id="wastage-entry-section">
            <h3 class="wastage-section-title">Record wastage</h3>
            <form method="POST" class="wastage-entry-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="wastage-date-field">
                    <label for="wastage-recorded-date">Date</label>
                    <input id="wastage-recorded-date" type="date" name="recorded_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div id="rows"></div>
                <div class="wastage-form-actions">
                    <button type="button" class="btn-secondary" onclick="addRow()"><i class="fas fa-plus"></i> Add row</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save wastage</button>
                </div>
            </form>
        </div>

        <h3 class="wastage-section-title wastage-section-title--entries" id="wastageEntries">Recent entries</h3>
        <div class="table-responsive">
            <table class="stock-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Ingredient</th>
                        <th style="text-align:right;">Quantity</th>
                        <th style="text-align:right;">Cost</th>
                        <th>Reason</th>
                        <th>Recorded by</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $entries_per_page    = 10;
                    $entries_page        = max(1, (int)($_GET['entries_page'] ?? 1));
                    $entries_total       = count($entries);
                    $entries_total_pages = $entries_total > 0 ? (int)ceil($entries_total / $entries_per_page) : 1;
                    $entries_display     = array_slice($entries, ($entries_page - 1) * $entries_per_page, $entries_per_page);
                    ?>
                    <?php foreach ($entries_display as $e): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($e['recorded_date']); ?></td>
                            <td><?php echo htmlspecialchars($e['ingredient_name']); ?></td>
                            <td style="text-align:right;"><?php echo number_format((float)$e['quantity'], 3) . ' ' . htmlspecialchars($e['unit']); ?></td>
                            <td style="text-align:right;font-weight:600;"><?php echo $currency_symbol . ' ' . number_format((float)$e['wastage_cost'], 2); ?></td>
                            <td><?php echo htmlspecialchars($e['reason']); ?></td>
                            <td style="font-size:12px; color:#6c757d;"><?php echo htmlspecialchars($e['recorded_by_name'] ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;padding:30px;color:#6c757d;">No wastage entries yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($entries_total_pages > 1): ?>
            <nav style="display:flex;align-items:center;justify-content:center;gap:6px;padding:16px 0;flex-wrap:wrap;">
                <?php for ($pg = 1; $pg <= $entries_total_pages; $pg++):
                    $pgHref = 'stock-wastage.php?' . http_build_query(['entries_page' => $pg]);
                    $pgActive = ($pg === $entries_page);
                ?>
                    <a href="<?php echo htmlspecialchars($pgHref, ENT_QUOTES, 'UTF-8'); ?>"
                        style="padding:6px 12px;border:1px solid <?php echo $pgActive ? 'var(--color-primary,#8A775F)' : '#dee2e6'; ?>;background:<?php echo $pgActive ? 'var(--color-primary,#8A775F)' : '#fff'; ?>;color:<?php echo $pgActive ? '#fff' : '#374151'; ?>;border-radius:4px;font-size:13px;text-decoration:none;"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <span style="padding:6px 8px;font-size:12px;color:#888;">
                    Showing <?php echo (($entries_page - 1) * $entries_per_page) + 1; ?>–<?php echo min($entries_page * $entries_per_page, $entries_total); ?> of <?php echo $entries_total; ?>
                </span>
            </nav>
        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
    <script>
        const ingredients = <?php echo json_encode(array_map(fn($i) => ['id' => (int)$i['id'], 'name' => $i['name'], 'unit' => $i['unit']], $ingredients)); ?>;
        const reasons = ['Spoiled', 'Breakage', 'Over-prepared', 'Customer return', 'Trim/peel waste', 'Burnt', 'Spilled', 'Other'];

        function addRow() {
            const rows = document.getElementById('rows');
            const r = document.createElement('div');
            r.className = 'form-row';
            r.innerHTML = `
                <select name="ingredient_id[]" required>
                    <option value="">Select ingredient...</option>
                    ${ingredients.map(i => `<option value="${i.id}" data-unit="${i.unit}">${i.name} (${i.unit})</option>`).join('')}
                </select>
                <input type="number" name="quantity[]" placeholder="Quantity" step="0.001" min="0" required>
                <select name="reason[]" required>
                    ${reasons.map(rs => `<option>${rs}</option>`).join('')}
                </select>
                <button type="button" class="form-row__remove" onclick="this.closest('.form-row').remove()" aria-label="Remove row"><i class="fas fa-times"></i></button>
            `;
            rows.appendChild(r);
        }

        function closeStockWastageInsight() {
            const modal = document.getElementById('stockWastageInsightModal');
            if (!modal) return;
            modal.classList.remove('active');
            if (!document.querySelector('.modal-overlay.active')) {
                document.body.classList.remove('modal-open');
            }
        }

        function openStockWastageInsight(triggerEl) {
            const key = triggerEl ? triggerEl.getAttribute('data-insight-key') : '';
            if (!key) return;

            const template = document.getElementById('stock-wastage-insight-template-' + key);
            const body = document.getElementById('stockWastageInsightBody');
            const title = document.getElementById('stockWastageInsightTitle');
            const modal = document.getElementById('stockWastageInsightModal');
            if (!template || !body || !title || !modal) return;

            title.textContent = triggerEl.getAttribute('data-insight-title') || 'Wastage Insight';
            body.innerHTML = template.innerHTML;
            modal.classList.add('active');
            document.body.classList.add('modal-open');
        }

        const wastageInsightModal = document.getElementById('stockWastageInsightModal');
        if (wastageInsightModal) {
            wastageInsightModal.addEventListener('click', function(e) {
                if (e.target === wastageInsightModal) {
                    closeStockWastageInsight();
                }
            });
        }

        if (!window.__stockWastageInsightHandlersBound) {
            document.addEventListener('click', function(e) {
                const trigger = e.target.closest('.js-stock-wastage-insight-trigger');
                if (!trigger) return;
                e.preventDefault();
                openStockWastageInsight(trigger);
            });

            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                const trigger = e.target && e.target.closest ? e.target.closest('.js-stock-wastage-insight-trigger') : null;
                if (!trigger) return;
                e.preventDefault();
                openStockWastageInsight(trigger);
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && document.getElementById('stockWastageInsightModal')?.classList.contains('active')) {
                    closeStockWastageInsight();
                }
            });

            window.__stockWastageInsightHandlersBound = true;
        }

        window.closeStockWastageInsight = closeStockWastageInsight;
        addRow();
    </script>
</body>

</html>

