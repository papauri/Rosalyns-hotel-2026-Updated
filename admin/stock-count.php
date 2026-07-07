<?php

/**
 * Stock Count & Variance Reconciliation
 *
 * Anti-theft enforcement: every disappearance of physical stock MUST tie to a
 * recorded sale, wastage, expiry, or an explicit variance from a manager-approved
 * stock count. This page is the count + reconciliation workflow.
 *
 * Workflow:
 *   1. Any stock-write user starts a count (full / category / spot scope).
 *   2. They enter the actual physical quantity per ingredient.
 *   3. On submit, the system computes variance vs the running system quantity.
 *      Surpluses are always allowed (good news). Shortages above thresholds
 *      require a reason code AND admin/manager approval.
 *   4. On approval, stock_adjustments rows of source_type='variance' are
 *      written and the LIVE quantity is adjusted by the saved variance delta
 *      (so post-count movements are preserved).
 *      Approver name and time are recorded forever.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';

$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];
$canApprove = in_array($user['role'] ?? '', ['admin', 'manager'], true);

$message = '';
$error = '';
$currency = getSetting('currency_symbol');
$alertPct = (float)(getSetting('stock_variance_alert_pct') ?: 2);
$blockPct = (float)(getSetting('stock_variance_block_pct') ?: 10);
$minCost  = (float)(getSetting('stock_variance_min_cost') ?: 5000);

if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created. Please run admin/migrations/015_stock_management.php first.';
}

/* Helper: generate a count reference */
function generateCountReference(): string
{
    return date('Ymd') . '-' . substr(strtoupper(bin2hex(random_bytes(2))), 0, 4);
}

function displayCountReference(?string $reference): string
{
    $ref = trim((string)$reference);
    if ($ref === '') {
        return '—';
    }
    // Keep legacy records readable but remove the old SC- prefix in UI.
    return (string)preg_replace('/^SC-/i', '', $ref);
}

/* ============== POST handlers ============== */
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security token invalid.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'start_count') {
                $scope = $_POST['scope'] ?? 'spot';
                if (!in_array($scope, ['full', 'category', 'spot'], true)) $scope = 'spot';
                $scopeValue = trim($_POST['scope_value'] ?? '');
                $shift = trim($_POST['shift'] ?? '');
                $notes = trim($_POST['notes'] ?? '');

                $ref = generateCountReference();
                $ins = $pdo->prepare("INSERT INTO stock_counts (reference, count_date, shift, scope, scope_value, status, counted_by, notes) VALUES (?, CURDATE(), ?, ?, ?, 'draft', ?, ?)");
                $ins->execute([$ref, $shift ?: null, $scope, $scopeValue ?: null, $user['id'], $notes ?: null]);
                $countId = (int)$pdo->lastInsertId();

                // Snapshot current quantities + cost for the chosen scope
                $sql = "SELECT id, current_quantity, cost_per_unit FROM stock_ingredients WHERE is_archived = 0";
                $params = [];
                if ($scope === 'category' && $scopeValue !== '') {
                    $sql .= " AND category = ?";
                    $params[] = $scopeValue;
                } elseif ($scope === 'spot') {
                    $ids = array_filter(array_map('intval', explode(',', $scopeValue)));
                    if (empty($ids)) throw new RuntimeException('Spot count requires at least one ingredient ID.');
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $sql .= " AND id IN ($ph)";
                    $params = array_merge($params, $ids);
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $lineIns = $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost) VALUES (?, ?, ?, 0, 0, ?, 0)");
                foreach ($rows as $r) {
                    $lineIns->execute([$countId, (int)$r['id'], (float)$r['current_quantity'], (float)$r['cost_per_unit']]);
                }

                $_SESSION['stock_msg'] = "Count {$ref} started. Enter your physical readings.";
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'save_lines') {
                $countId = (int)($_POST['count_id'] ?? 0);
                $hdr = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ? AND status = 'draft'");
                $hdr->execute([$countId]);
                $count = $hdr->fetch(PDO::FETCH_ASSOC);
                if (!$count) throw new RuntimeException('Count not found or already submitted.');
                if ((int)$count['counted_by'] !== (int)$user['id'] && !$canApprove) {
                    throw new RuntimeException('Only the original counter or a manager can edit this count.');
                }

                $lines = $_POST['line'] ?? [];
                $upd = $pdo->prepare("UPDATE stock_count_lines SET actual_quantity = ?, variance = ?, variance_cost = ?, reason_code = ?, reason_notes = ? WHERE id = ? AND count_id = ?");
                foreach ($lines as $lineId => $payload) {
                    $lineId = (int)$lineId;
                    $actual = (float)($payload['actual'] ?? 0);
                    $sys    = (float)($payload['system'] ?? 0);
                    $cost   = (float)($payload['cost'] ?? 0);
                    $reason = (string)($payload['reason'] ?? '');
                    $notes  = trim((string)($payload['notes'] ?? ''));
                    if ($actual < 0) {
                        throw new RuntimeException('Actual quantity cannot be negative.');
                    }
                    if (!in_array($reason, ['', 'spillage', 'expired', 'staff_meal', 'sampling', 'prep_waste', 'theft_suspected', 'correction', 'other'], true)) {
                        $reason = '';
                    }
                    $variance = round($actual - $sys, 4);
                    $varCost  = round($variance * $cost, 4);
                    $upd->execute([$actual, $variance, $varCost, $reason, $notes ?: null, $lineId, $countId]);
                }

                $_SESSION['stock_msg'] = 'Count readings saved.';
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'submit_count') {
                $countId = (int)($_POST['count_id'] ?? 0);
                $hdr = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ? AND status = 'draft' FOR UPDATE");
                $pdo->beginTransaction();
                $hdr->execute([$countId]);
                $count = $hdr->fetch(PDO::FETCH_ASSOC);
                if (!$count) throw new RuntimeException('Count not found.');

                // Validate every shortage line has a reason code
                $missing = $pdo->prepare("SELECT COUNT(*) FROM stock_count_lines WHERE count_id = ? AND variance < 0 AND (reason_code = '' OR reason_code IS NULL)");
                $missing->execute([$countId]);
                if ((int)$missing->fetchColumn() > 0) {
                    throw new RuntimeException('Every shortage line must have a reason. Save your readings, pick a reason for each negative variance, then submit.');
                }

                // Compute totals
                $tots = $pdo->prepare("SELECT COALESCE(SUM(variance_cost),0) AS total, COALESCE(SUM(CASE WHEN variance_cost<0 THEN variance_cost ELSE 0 END),0) AS shortage, COALESCE(SUM(CASE WHEN variance_cost>0 THEN variance_cost ELSE 0 END),0) AS surplus FROM stock_count_lines WHERE count_id = ?");
                $tots->execute([$countId]);
                $t = $tots->fetch(PDO::FETCH_ASSOC);

                $pdo->prepare("UPDATE stock_counts SET status='submitted', total_variance_cost=?, shortage_cost=?, surplus_cost=?, updated_at=NOW() WHERE id=?")
                    ->execute([(float)$t['total'], (float)$t['shortage'], (float)$t['surplus'], $countId]);
                $pdo->commit();

                $_SESSION['stock_msg'] = 'Count submitted for approval. Shortage cost: ' . $currency . ' ' . number_format(abs((float)$t['shortage']), 2) . '.';
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'approve_count') {
                if (!$canApprove) throw new RuntimeException('Only admin/manager can approve counts.');
                $countId = (int)($_POST['count_id'] ?? 0);

                $pdo->beginTransaction();
                $hdr = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ? FOR UPDATE");
                $hdr->execute([$countId]);
                $count = $hdr->fetch(PDO::FETCH_ASSOC);
                if (!$count) throw new RuntimeException('Count not found.');
                if ($count['status'] !== 'submitted') throw new RuntimeException('Only submitted counts can be approved.');

                // Apply variance deltas for each line while keeping batch ledger in sync.
                $lineSel = $pdo->prepare("SELECT * FROM stock_count_lines WHERE count_id = ?");
                $lineSel->execute([$countId]);
                $lines = $lineSel->fetchAll(PDO::FETCH_ASSOC);

                if (!function_exists('deductStockBatchFIFO') || !function_exists('ensureStockBatchCoverageForDeduction')) {
                    throw new RuntimeException('Stock engine helpers missing.');
                }

                $liveSel = $pdo->prepare("SELECT current_quantity, cost_per_unit FROM stock_ingredients WHERE id = ? FOR UPDATE");
                $ingAdd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ?, updated_at = NOW() WHERE id = ?");
                $batchIns = $pdo->prepare("\n                    INSERT INTO stock_batches (\n                        ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit,\n                        supplier_name, supplier_contact, received_date, expiry_date, expiry_alert_days, status, notes, created_by\n                    ) VALUES (?, ?, ?, ?, ?, NULL, NULL, CURDATE(), NULL, 7, 'active', ?, ?)\n                ");
                $batchNumUpd = $pdo->prepare('UPDATE stock_batches SET batch_number = ? WHERE id = ?');
                $adjInsPositive = $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by) VALUES (?, ?, ?, 'variance', ?, ?, ?)");
                $adjReasonUpd = $pdo->prepare('UPDATE stock_adjustments SET reason = ?, cost_at_time = ? WHERE id = ?');
                $lineUpd = $pdo->prepare("UPDATE stock_count_lines SET adjustment_id = ? WHERE id = ?");

                foreach ($lines as $ln) {
                    $variance = round((float)$ln['variance'], 4);
                    if (abs($variance) < 0.0001) continue;

                    $ingredientId = (int)$ln['ingredient_id'];
                    $lineId = (int)$ln['id'];
                    $reason = 'Stock count ' . $count['reference'] . ' (' . ($ln['reason_code'] ?: 'no_code') . ')';
                    $reasonShort = mb_substr($reason, 0, 255);

                    $liveSel->execute([$ingredientId]);
                    $live = $liveSel->fetch(PDO::FETCH_ASSOC);
                    if (!$live) {
                        throw new RuntimeException('Ingredient not found during count approval.');
                    }

                    $liveQty = (float)$live['current_quantity'];
                    $costAtTime = (float)$ln['cost_per_unit'];
                    if ($costAtTime <= 0) {
                        $costAtTime = (float)$live['cost_per_unit'];
                    }

                    if ($variance > 0) {
                        // Count surplus becomes a real active batch so FIFO stays correct.
                        $tmpBatchNumber = 'TMP-C-' . date('YmdHis') . '-' . strtoupper(substr(str_replace('.', '', uniqid('', true)), -8));
                        $batchIns->execute([
                            $ingredientId,
                            $tmpBatchNumber,
                            $variance,
                            $variance,
                            max(0, $costAtTime),
                            mb_substr('Stock count surplus ' . $count['reference'], 0, 500),
                            $user['id'],
                        ]);
                        $batchId = (int)$pdo->lastInsertId();
                        $batchNumber = 'C' . date('Ymd') . '-' . str_pad((string)$batchId, 6, '0', STR_PAD_LEFT);
                        $batchNumUpd->execute([$batchNumber, $batchId]);

                        $ingAdd->execute([$variance, $ingredientId]);
                        $adjInsPositive->execute([$ingredientId, $variance, $reasonShort, $countId, $costAtTime, $user['id']]);
                        $adjId = (int)$pdo->lastInsertId();
                    } else {
                        $deductQty = abs($variance);
                        if ($deductQty > $liveQty + 0.0001) {
                            throw new RuntimeException('Variance exceeds current live stock for ingredient #' . $ingredientId . '. Reopen and recount.');
                        }

                        ensureStockBatchCoverageForDeduction(
                            $ingredientId,
                            $deductQty,
                            $costAtTime,
                            $user['id'],
                            'Auto batch sync before count shortage ' . $count['reference']
                        );

                        $adjId = (int)(deductStockBatchFIFO($ingredientId, $deductQty, 'variance', $countId, $user['id']) ?? 0);
                        if ($adjId <= 0) {
                            throw new RuntimeException('Failed to apply shortage variance for ingredient #' . $ingredientId . '.');
                        }
                        $adjReasonUpd->execute([$reasonShort, $costAtTime, $adjId]);
                    }

                    $lineUpd->execute([$adjId, $lineId]);
                }

                $pdo->prepare("UPDATE stock_counts SET status='approved', approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE id = ?")
                    ->execute([$user['id'], $countId]);
                $pdo->commit();

                if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v2');
                $_SESSION['stock_msg'] = "Count " . displayCountReference((string)$count['reference']) . " approved. Variances applied to stock.";
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'reject_count') {
                if (!$canApprove) throw new RuntimeException('Only admin/manager can reject counts.');
                $countId = (int)($_POST['count_id'] ?? 0);
                $reason = trim($_POST['rejection_reason'] ?? '');
                if (mb_strlen($reason) < 8) throw new RuntimeException('Provide a rejection reason (min 8 characters).');
                $pdo->prepare("UPDATE stock_counts SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=? AND status='submitted'")
                    ->execute([mb_substr($reason, 0, 500), $countId]);
                $_SESSION['stock_msg'] = 'Count rejected and sent back for re-counting.';
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }

            if ($action === 'reopen_count') {
                $countId = (int)($_POST['count_id'] ?? 0);
                $pdo->prepare("UPDATE stock_counts SET status='draft', updated_at=NOW() WHERE id=? AND status='rejected'")
                    ->execute([$countId]);
                $_SESSION['stock_msg'] = 'Count reopened — fix the readings and resubmit.';
                header('Location: stock-count.php?id=' . $countId);
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

if (!empty($_SESSION['stock_msg'])) {
    $message = $_SESSION['stock_msg'];
    unset($_SESSION['stock_msg']);
}
if (!empty($_SESSION['stock_err'])) {
    $error   = $_SESSION['stock_err'];
    unset($_SESSION['stock_err']);
}

/* ============== Page state ============== */
$activeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$activeCount = null;
$activeLines = [];

if ($activeId) {
    $stmt = $pdo->prepare("
        SELECT sc.*, cu.full_name AS counted_by_name, au.full_name AS approved_by_name
        FROM stock_counts sc
        LEFT JOIN admin_users cu ON cu.id = sc.counted_by
        LEFT JOIN admin_users au ON au.id = sc.approved_by
        WHERE sc.id = ?
    ");
    $stmt->execute([$activeId]);
    $activeCount = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($activeCount) {
        $lstmt = $pdo->prepare("
            SELECT scl.*, i.name AS ingredient_name, i.unit, i.category
            FROM stock_count_lines scl
            INNER JOIN stock_ingredients i ON i.id = scl.ingredient_id
            WHERE scl.count_id = ?
            ORDER BY i.category, i.name
        ");
        $lstmt->execute([$activeId]);
        $activeLines = $lstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$counts = $pdo->query("
    SELECT sc.*, cu.full_name AS counted_by_name, au.full_name AS approved_by_name,
           (SELECT COUNT(*) FROM stock_count_lines WHERE count_id = sc.id) AS line_count
    FROM stock_counts sc
    LEFT JOIN admin_users cu ON cu.id = sc.counted_by
    LEFT JOIN admin_users au ON au.id = sc.approved_by
    ORDER BY sc.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT DISTINCT category FROM stock_ingredients WHERE is_archived = 0 ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$ingredientList = $pdo->query("SELECT id, name, category, unit FROM stock_ingredients WHERE is_archived = 0 ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);

/* ============== Theft / unaccounted summary (last 30 days) ==============
 * For each ingredient, classify total ABS movement by source. Anything that
 * drains stock and is NOT traced to a source_type IS the theft signal — but
 * by design every drain has a source_type, so we instead surface ingredients
 * whose sum of negative 'manual' or 'variance' adjustments is large compared
 * to sales — that is the audit lens. */
$theftRadar = $pdo->query("
    SELECT i.id, i.name, i.unit, i.cost_per_unit,
           COALESCE(SUM(CASE WHEN sa.source_type IN ('pos_order','room_service') THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS sold,
           COALESCE(SUM(CASE WHEN sa.source_type = 'wastage' THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS wasted,
           COALESCE(SUM(CASE WHEN sa.source_type = 'variance' AND sa.quantity_change < 0 THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS shortage,
           COALESCE(SUM(CASE WHEN sa.source_type = 'manual' AND sa.quantity_change < 0 THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS manual_out
    FROM stock_ingredients i
    LEFT JOIN stock_adjustments sa ON sa.ingredient_id = i.id AND sa.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    WHERE i.is_archived = 0
    GROUP BY i.id, i.name, i.unit, i.cost_per_unit
    HAVING shortage > 0 OR manual_out > 0
    ORDER BY (shortage * i.cost_per_unit + manual_out * i.cost_per_unit) DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Stock Count — Variance & Anti-Theft</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-count.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-count.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content stock-count-page">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-clipboard-check" style="color:#8B7355;"></i> Stock Count &amp; Anti-Theft</h2>
            <p style="color:#6c757d; margin-top:4px;">Every gram of physical stock must tie to a sale, wastage entry, or an approved variance. Surprise counts catch shrinkage before it grows.</p>
        </div>

        <?php if ($message): showAlert($message, 'success');
        endif; ?>
        <?php if ($error):   showAlert($error,   'error');
        endif; ?>

        <?php if ($activeCount): ?>
            <!-- ============= COUNT DETAIL ============= -->
            <?php
            $totalLines = count($activeLines);
            $pendingShort = 0;
            $pendingSurplus = 0;
            foreach ($activeLines as $ln) {
                $vc = (float)$ln['variance_cost'];
                if ($vc < 0) $pendingShort += $vc;
                else $pendingSurplus += $vc;
            }
            $editable = $activeCount['status'] === 'draft';
            $isSubmitted = $activeCount['status'] === 'submitted';
            ?>
            <div class="count-header">
                <a href="stock-count.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> Back to all counts</a>
                <span class="count-header__meta">
                    Count <strong><?php echo htmlspecialchars(displayCountReference((string)$activeCount['reference'])); ?></strong>
                    <span class="pill st-<?php echo $activeCount['status']; ?>"><?php echo $activeCount['status']; ?></span>
                    ·
                    Counted by <strong><?php echo htmlspecialchars($activeCount['counted_by_name'] ?: '—'); ?></strong>
                    on <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($activeCount['created_at']))); ?>
                </span>
            </div>

            <div class="summary-grid">
                <div class="sum-card">
                    <div class="lbl">Lines</div>
                    <div class="val"><?php echo $totalLines; ?></div>
                </div>
                <div class="sum-card<?php echo $pendingShort < 0 ? ' bad' : ''; ?>">
                    <div class="lbl">Shortage Cost</div>
                    <div class="val"><?php echo $currency . ' ' . number_format(abs($pendingShort), 2); ?></div>
                </div>
                <div class="sum-card<?php echo $pendingSurplus > 0 ? ' good' : ''; ?>">
                    <div class="lbl">Surplus Cost</div>
                    <div class="val"><?php echo $currency . ' ' . number_format($pendingSurplus, 2); ?></div>
                </div>
                <div class="sum-card">
                    <div class="lbl">Net</div>
                    <div class="val"><?php echo $currency . ' ' . number_format($pendingSurplus + $pendingShort, 2); ?></div>
                </div>
            </div>

            <?php if ($activeCount['status'] === 'rejected'): ?>
                <div style="background:#f8d7da; border:1px solid #f1b0b7; padding:10px 14px; border-radius:8px; margin-bottom:14px;">
                    <strong>Rejected:</strong> <?php echo htmlspecialchars($activeCount['rejection_reason'] ?? ''); ?>
                    <form method="POST" style="display:inline; margin-left:14px;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="reopen_count">
                        <input type="hidden" name="count_id" value="<?php echo (int)$activeCount['id']; ?>">
                        <button class="btn-secondary">Reopen for re-count</button>
                    </form>
                </div>
            <?php endif; ?>

            <form method="POST" id="countForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="count_id" value="<?php echo (int)$activeCount['id']; ?>">

                <div style="display:grid;grid-template-columns:minmax(12rem,1.6fr) repeat(2,minmax(8.8rem,1fr)) auto auto;gap:0.5rem;align-items:end;margin-bottom:0.7rem;">
                    <label style="display:flex;flex-direction:column;gap:0.28rem;">
                        <span style="font-size:11px;color:#6c757d;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Search ingredient</span>
                        <input type="text" id="scFilterSearch" placeholder="Name, category, unit..." style="min-height:38px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:0.28rem;">
                        <span style="font-size:11px;color:#6c757d;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Variance</span>
                        <select id="scFilterVariance" style="min-height:38px;">
                            <option value="all">All rows</option>
                            <option value="shortage">Shortage only</option>
                            <option value="surplus">Surplus only</option>
                            <option value="balanced">Balanced only</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:0.28rem;">
                        <span style="font-size:11px;color:#6c757d;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Reason</span>
                        <select id="scFilterReason" style="min-height:38px;">
                            <option value="all">All reasons</option>
                            <option value="none">No reason</option>
                            <?php foreach (['spillage', 'expired', 'staff_meal', 'sampling', 'prep_waste', 'theft_suspected', 'correction', 'other'] as $rc): ?>
                                <option value="<?php echo htmlspecialchars($rc); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $rc))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="button" class="btn-secondary" id="scFilterReset" style="min-height:38px;"><i class="fas fa-rotate-left"></i> Reset</button>
                    <span id="scFilterCount" style="font-size:12px;color:#6c757d;justify-self:end;white-space:nowrap;"></span>
                </div>

                <div class="card" style="overflow:auto; max-height:65vh; padding:0;">
                    <table class="lines-table no-card-mobile">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th style="text-align:right;">System Qty</th>
                                <th style="text-align:right;">Actual Qty</th>
                                <th style="text-align:right;">Variance</th>
                                <th style="text-align:right;">Cost Impact</th>
                                <th>Reason</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeLines as $ln):
                                $sys = (float)$ln['system_quantity'];
                                $act = (float)$ln['actual_quantity'];
                                $var = (float)$ln['variance'];
                                $varCost = (float)$ln['variance_cost'];
                                $absVarPct = $sys > 0 ? abs($var) / $sys * 100 : 0;
                                $rowClass = '';
                                if ($var < 0 && abs($varCost) >= $minCost) {
                                    $rowClass = $absVarPct >= $blockPct ? 'row-block' : 'row-flag';
                                }
                                $varClass = abs($var) < 0.0001 ? 'var-zero' : ($var > 0 ? 'var-pos' : 'var-neg');
                            ?>
                                <?php
                                $reasonCode = trim((string)($ln['reason_code'] ?? ''));
                                $varianceBand = abs($var) < 0.0001 ? 'balanced' : ($var > 0 ? 'surplus' : 'shortage');
                                $searchBlob = mb_strtolower(trim((string)$ln['ingredient_name'] . ' ' . (string)$ln['category'] . ' ' . (string)$ln['unit']));
                                ?>
                                <tr class="<?php echo $rowClass; ?>"
                                    data-filter-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-filter-variance="<?php echo htmlspecialchars($varianceBand, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-filter-reason="<?php echo htmlspecialchars($reasonCode !== '' ? $reasonCode : 'none', ENT_QUOTES, 'UTF-8'); ?>">
                                    <td data-label="Ingredient">
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($ln['ingredient_name']); ?></div>
                                        <div style="font-size:10px; color:#6c757d;"><?php echo htmlspecialchars($ln['category']); ?> · <?php echo htmlspecialchars($ln['unit']); ?> · <?php echo $currency; ?> <?php echo number_format((float)$ln['cost_per_unit'], 2); ?>/<?php echo htmlspecialchars($ln['unit']); ?></div>
                                    </td>
                                    <td data-label="System Qty" style="text-align:right;"><?php echo number_format($sys, 4); ?></td>
                                    <td data-label="Actual Qty" style="text-align:right;">
                                        <?php if ($editable): ?>
                                            <input class="qty" type="number" step="0.0001" min="0"
                                                name="line[<?php echo (int)$ln['id']; ?>][actual]"
                                                value="<?php echo number_format($act, 4, '.', ''); ?>"
                                                data-system="<?php echo number_format($sys, 4, '.', ''); ?>"
                                                data-cost="<?php echo number_format((float)$ln['cost_per_unit'], 4, '.', ''); ?>"
                                                oninput="recalcRow(this)">
                                            <input type="hidden" name="line[<?php echo (int)$ln['id']; ?>][system]" value="<?php echo number_format($sys, 4, '.', ''); ?>">
                                            <input type="hidden" name="line[<?php echo (int)$ln['id']; ?>][cost]" value="<?php echo number_format((float)$ln['cost_per_unit'], 4, '.', ''); ?>">
                                        <?php else: ?>
                                            <?php echo number_format($act, 4); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Variance" style="text-align:right;" class="var <?php echo $varClass; ?>"><?php echo number_format($var, 4); ?></td>
                                    <td data-label="Cost Impact" style="text-align:right;" class="varCost <?php echo $varClass; ?>"><?php echo $currency . ' ' . number_format($varCost, 2); ?></td>
                                    <td data-label="Reason">
                                        <?php if ($editable): ?>
                                            <select class="reason" name="line[<?php echo (int)$ln['id']; ?>][reason]">
                                                <option value="" <?php echo $ln['reason_code'] === '' ? ' selected' : ''; ?>>—</option>
                                                <?php foreach (['spillage', 'expired', 'staff_meal', 'sampling', 'prep_waste', 'theft_suspected', 'correction', 'other'] as $rc): ?>
                                                    <option value="<?php echo $rc; ?>" <?php echo $ln['reason_code'] === $rc ? ' selected' : ''; ?>><?php echo ucfirst(str_replace('_', ' ', $rc)); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($ln['reason_code'] ?: '—'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Notes">
                                        <?php if ($editable): ?>
                                            <input class="notes" type="text" maxlength="500" name="line[<?php echo (int)$ln['id']; ?>][notes]" value="<?php echo htmlspecialchars($ln['reason_notes'] ?? ''); ?>">
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($ln['reason_notes'] ?? ''); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                    <?php if ($editable): ?>
                        <button type="submit" name="action" value="save_lines" class="btn-secondary"><i class="fas fa-save"></i> Save readings</button>
                        <button type="button" class="btn-primary" onclick="scConfirm(this.form,'submit_count','Submit count for approval? Once submitted you cannot edit it.')"><i class="fas fa-paper-plane"></i> Submit for approval</button>
                    <?php elseif ($isSubmitted && $canApprove): ?>
                        <button type="button" class="btn-success" onclick="scConfirm(this.form,'approve_count','Approve count and post variances to stock? This cannot be undone.')"><i class="fas fa-check"></i> Approve &amp; post variances</button>
                        <button type="button" class="btn-danger" onclick="rejectPrompt(this.form)"><i class="fas fa-times"></i> Reject &amp; send back</button>
                        <input type="hidden" name="rejection_reason" id="rejection_reason" value="">
                        <span id="reject_err" style="color:#c82333;font-size:13px;display:none;"></span>
                    <?php elseif ($activeCount['status'] === 'approved'): ?>
                        <span style="color:#155724;"><i class="fas fa-check-circle"></i> Approved by <?php echo htmlspecialchars($activeCount['approved_by_name'] ?: '?'); ?> on <?php echo $activeCount['approved_at'] ? date('Y-m-d H:i', strtotime($activeCount['approved_at'])) : '?'; ?>. Variances posted to stock.</span>
                    <?php endif; ?>
                </div>
            </form>

            <script>
                const currency = <?php echo json_encode($currency); ?>;

                function applyStockLineFilters() {
                    const qEl = document.getElementById('scFilterSearch');
                    const varianceEl = document.getElementById('scFilterVariance');
                    const reasonEl = document.getElementById('scFilterReason');
                    const countEl = document.getElementById('scFilterCount');
                    const rows = Array.from(document.querySelectorAll('#countForm .lines-table tbody tr'));
                    if (!qEl || !varianceEl || !reasonEl || !countEl || !rows.length) return;

                    const q = (qEl.value || '').trim().toLowerCase();
                    const varianceFilter = varianceEl.value || 'all';
                    const reasonFilter = reasonEl.value || 'all';
                    let visible = 0;

                    rows.forEach((row) => {
                        const hay = (row.dataset.filterSearch || '').toLowerCase();
                        const rowVariance = row.dataset.filterVariance || 'balanced';
                        const rowReason = row.dataset.filterReason || 'none';

                        const matchSearch = q === '' || hay.includes(q);
                        const matchVariance = varianceFilter === 'all' || rowVariance === varianceFilter;
                        const matchReason = reasonFilter === 'all' || rowReason === reasonFilter;
                        const show = matchSearch && matchVariance && matchReason;
                        row.style.display = show ? '' : 'none';
                        if (show) visible += 1;
                    });

                    countEl.textContent = `${visible} of ${rows.length} rows`;
                }

                function syncRowFilterStateFromInputs(input) {
                    const tr = input.closest('tr');
                    if (!tr) return;
                    const sys = parseFloat(input.dataset.system || '0') || 0;
                    const act = parseFloat(input.value || '0') || 0;
                    const variance = act - sys;
                    tr.dataset.filterVariance = Math.abs(variance) < 0.0001 ? 'balanced' : (variance > 0 ? 'surplus' : 'shortage');
                }

                function recalcRow(input) {
                    const tr = input.closest('tr');
                    const sys = parseFloat(input.dataset.system) || 0;
                    const cost = parseFloat(input.dataset.cost) || 0;
                    const act = parseFloat(input.value) || 0;
                    const variance = act - sys;
                    const varCost = variance * cost;
                    const cells = tr.querySelectorAll('.var, .varCost');
                    cells[0].textContent = variance.toFixed(4);
                    cells[1].textContent = currency + ' ' + Number(varCost || 0).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    const cls = Math.abs(variance) < 0.0001 ? 'var-zero' : (variance > 0 ? 'var-pos' : 'var-neg');
                    cells[0].className = 'var ' + cls;
                    cells[1].className = 'varCost ' + cls;
                    tr.dataset.filterVariance = Math.abs(variance) < 0.0001 ? 'balanced' : (variance > 0 ? 'surplus' : 'shortage');
                    applyStockLineFilters();
                }

                document.addEventListener('DOMContentLoaded', function() {
                    const searchEl = document.getElementById('scFilterSearch');
                    const varianceEl = document.getElementById('scFilterVariance');
                    const reasonEl = document.getElementById('scFilterReason');
                    const resetEl = document.getElementById('scFilterReset');

                    if (searchEl) searchEl.addEventListener('input', applyStockLineFilters);
                    if (varianceEl) varianceEl.addEventListener('change', applyStockLineFilters);
                    if (reasonEl) reasonEl.addEventListener('change', applyStockLineFilters);

                    document.querySelectorAll('#countForm .lines-table .reason').forEach((sel) => {
                        sel.addEventListener('change', function() {
                            const tr = this.closest('tr');
                            if (tr) {
                                tr.dataset.filterReason = this.value && this.value !== '' ? this.value : 'none';
                            }
                            applyStockLineFilters();
                        });
                    });

                    document.querySelectorAll('#countForm .lines-table .qty').forEach((input) => {
                        syncRowFilterStateFromInputs(input);
                    });

                    if (resetEl) {
                        resetEl.addEventListener('click', function() {
                            if (searchEl) searchEl.value = '';
                            if (varianceEl) varianceEl.value = 'all';
                            if (reasonEl) reasonEl.value = 'all';
                            applyStockLineFilters();
                        });
                    }

                    applyStockLineFilters();
                });

                var _scConfirmForm = null,
                    _scConfirmAction = null;

                function scConfirm(form, actionValue, message) {
                    _scConfirmForm = form;
                    _scConfirmAction = actionValue;
                    document.getElementById('scConfirmMessage').textContent = message;
                    var modal = document.getElementById('scConfirmModal');
                    if (modal) {
                        modal.classList.add('active');
                        document.body.classList.add('modal-open');
                    }
                }

                function scCloseConfirm(resetState = true) {
                    var modal = document.getElementById('scConfirmModal');
                    if (modal) {
                        modal.classList.remove('active');
                    }
                    if (!document.querySelector('.modal-overlay.active')) {
                        document.body.classList.remove('modal-open');
                    }
                    if (resetState) {
                        _scConfirmForm = null;
                        _scConfirmAction = null;
                    }
                }

                function scDoConfirm() {
                    var form = _scConfirmForm;
                    var actionValue = _scConfirmAction;
                    scCloseConfirm(false);
                    _scConfirmForm = null;
                    _scConfirmAction = null;
                    if (!form || !actionValue) return;
                    const a = document.createElement('input');
                    a.type = 'hidden';
                    a.name = 'action';
                    a.value = actionValue;
                    form.appendChild(a);
                    form.submit();
                }

                function rejectPrompt(form) {
                    const r = prompt('Reject count — reason (min 8 characters):');
                    if (!r || r.trim().length < 8) {
                        const el = document.getElementById('reject_err');
                        if (el) {
                            el.textContent = 'Rejection reason required (min 8 characters).';
                            el.style.display = '';
                        }
                        return;
                    }
                    document.getElementById('rejection_reason').value = r.trim();
                    const a = document.createElement('input');
                    a.type = 'hidden';
                    a.name = 'action';
                    a.value = 'reject_count';
                    form.appendChild(a);
                    form.submit();
                }
            </script>

        <?php else: ?>
            <!-- ============= LIST + START ============= -->
            <div class="grid-2">
                <div>
                    <div class="card">
                        <h3><i class="fas fa-plus-circle"></i> Start a Count</h3>
                        <p style="font-size:12px; color:#6c757d; margin-top:0;">Surprise counts catch shrinkage. Start narrow if you suspect a specific category.</p>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="start_count">
                            <div class="form-field">
                                <label>Scope</label>
                                <select name="scope" id="scope" onchange="onScopeChange()">
                                    <option value="full">Full count (every ingredient)</option>
                                    <option value="category" selected>By category</option>
                                    <option value="spot">Spot check (specific items)</option>
                                </select>
                            </div>
                            <div class="form-field" id="scope-category">
                                <label>Category</label>
                                <select name="scope_value" id="scope_value_cat">
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field" id="scope-spot" style="display:none;">
                                <label>Pick ingredients (Ctrl/Cmd-click for multi)</label>
                                <select id="scope_spot_select" multiple size="8" style="width:100%;">
                                    <?php foreach ($ingredientList as $ing): ?>
                                        <option value="<?php echo (int)$ing['id']; ?>"><?php echo htmlspecialchars($ing['category'] . ' · ' . $ing['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="scope_value" id="scope_value_spot">
                            </div>
                            <div class="form-field">
                                <label>Shift label (optional)</label>
                                <input type="text" name="shift" placeholder="e.g. Morning, Closing, Surprise">
                            </div>
                            <div class="form-field">
                                <label>Notes</label>
                                <textarea name="notes" rows="2" placeholder="Why this count?"></textarea>
                            </div>
                            <button type="submit" class="btn-primary"><i class="fas fa-play"></i> Start count</button>
                        </form>
                    </div>

                    <div class="card" style="margin-top:14px;">
                        <h3 style="color:#c82333;"><i class="fas fa-shield-alt"></i> Theft Radar (last 30d)</h3>
                        <p style="font-size:12px; color:#6c757d; margin-top:0;">Top ingredients losing stock to <strong>shortage variances</strong> or <strong>manual adjustments</strong> — i.e. drains <em>not</em> attributable to a sale or wastage.</p>
                        <?php if (empty($theftRadar)): ?>
                            <div style="padding:14px; background:#e9f5ee; border-radius:6px; color:#155724;"><i class="fas fa-check-circle"></i> No unaccounted shortages detected. All stock movement traces to sales or recorded events.</div>
                        <?php else: ?>
                            <table class="count-list no-card-mobile">
                                <thead>
                                    <tr>
                                        <th>Ingredient</th>
                                        <th style="text-align:right;">Shortage</th>
                                        <th style="text-align:right;">Manual out</th>
                                        <th style="text-align:right;">Cost lost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($theftRadar as $r):
                                        $cost = ((float)$r['shortage'] + (float)$r['manual_out']) * (float)$r['cost_per_unit'];
                                    ?>
                                        <tr>
                                            <td data-label="Ingredient"><?php echo htmlspecialchars($r['name']); ?> <span style="color:#6c757d;font-size:11px;"><?php echo htmlspecialchars($r['unit']); ?></span></td>
                                            <td data-label="Shortage" style="text-align:right; color:#c82333;"><?php echo number_format((float)$r['shortage'], 4); ?></td>
                                            <td data-label="Manual out" style="text-align:right; color:#c82333;"><?php echo number_format((float)$r['manual_out'], 4); ?></td>
                                            <td data-label="Cost lost" style="text-align:right; font-weight:600; color:#c82333;"><?php echo $currency . ' ' . number_format($cost, 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="card">
                        <h3><i class="fas fa-list"></i> Recent Counts</h3>
                        <table class="count-list no-card-mobile">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Date</th>
                                    <th>Scope</th>
                                    <th>Lines</th>
                                    <th style="text-align:right;">Shortage</th>
                                    <th style="text-align:right;">Surplus</th>
                                    <th>Counted by</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($counts as $c): ?>
                                    <tr style="cursor:pointer;" onclick="window.location='stock-count.php?id=<?php echo (int)$c['id']; ?>';">
                                        <td data-label="Reference"><strong><?php echo htmlspecialchars(displayCountReference((string)$c['reference'])); ?></strong></td>
                                        <td data-label="Date"><?php echo htmlspecialchars($c['count_date']); ?></td>
                                        <td data-label="Scope">
                                            <?php echo htmlspecialchars($c['scope']); ?>
                                            <?php if ($c['scope_value']): ?><div style="font-size:10px; color:#6c757d;"><?php echo htmlspecialchars(mb_strimwidth($c['scope_value'], 0, 30, '…')); ?></div><?php endif; ?>
                                        </td>
                                        <td data-label="Lines"><?php echo (int)$c['line_count']; ?></td>
                                        <td data-label="Shortage" style="text-align:right; color:#c82333;"><?php echo $currency . ' ' . number_format(abs((float)$c['shortage_cost']), 2); ?></td>
                                        <td data-label="Surplus" style="text-align:right; color:#155724;"><?php echo $currency . ' ' . number_format((float)$c['surplus_cost'], 2); ?></td>
                                        <td data-label="Counted by" style="font-size:12px;"><?php echo htmlspecialchars($c['counted_by_name'] ?: '—'); ?></td>
                                        <td data-label="Status"><span class="pill st-<?php echo htmlspecialchars($c['status']); ?>"><?php echo htmlspecialchars($c['status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($counts)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align:center; padding:30px; color:#6c757d;">No counts yet — start your first one →</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <script>
                function onScopeChange() {
                    const v = document.getElementById('scope').value;
                    document.getElementById('scope-category').style.display = (v === 'category') ? 'block' : 'none';
                    document.getElementById('scope-spot').style.display = (v === 'spot') ? 'block' : 'none';
                    document.getElementById('scope_value_cat').name = (v === 'category') ? 'scope_value' : '';
                    if (v === 'full') {
                        // ensure neither spot nor category sends scope_value
                        document.getElementById('scope_value_cat').name = '';
                    }
                }
                document.getElementById('scope_spot_select').addEventListener('change', e => {
                    const ids = [...e.target.selectedOptions].map(o => o.value).join(',');
                    document.getElementById('scope_value_spot').value = ids;
                    document.getElementById('scope_value_spot').name = 'scope_value';
                });
                onScopeChange();
            </script>
        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
    <div id="scConfirmModal" class="modal-overlay" data-modal role="dialog" aria-modal="true" aria-labelledby="scConfirmTitle">
        <div class="modal-content" style="max-width:min(96vw,25rem); width:min(96vw,25rem);">
            <div class="modal-header">
                <h3 class="modal-title" id="scConfirmTitle">Confirm Action</h3>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="scCloseConfirm()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin:0;font-size:14px;color:#374151;" id="scConfirmMessage">Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="scCloseConfirm()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="scDoConfirm()">Confirm</button>
            </div>
        </div>
    </div>
</body>

</html>

