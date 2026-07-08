<?php

/**
 * Stock Management — Ingredients
 *
 * CRUD + stock-in (creates batch + recalculates weighted avg cost) + quick-adjust.
 * Ingredient deletion: blocked if used in any recipe; archive instead.
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
$message = '';
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);
$currency_symbol = getSetting('currency_symbol');

// Ensure migration has been run
if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created. Please run admin/migrations/015_stock_management.php first.';
} else {
    ensureProcurementSchema($pdo);
}

// ---- POST handling ----
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security token invalid. Please reload the page.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add' || $action === 'update') {
                $name = trim($_POST['name'] ?? '');
                $category = trim($_POST['category'] ?? 'General');
                $unit = trim($_POST['unit'] ?? 'g');
                $minQty = max(0, (float)($_POST['min_quantity'] ?? 0));
                $yield = max(0.1, min(100, (float)($_POST['yield_percent'] ?? 100)));
                $notes = trim($_POST['notes'] ?? '');
                $reorderPoint = max(0, (float)($_POST['reorder_point'] ?? 0));
                $parLevel     = max(0, (float)($_POST['par_level'] ?? 0));
                $leadTime     = max(0, (int)($_POST['lead_time_days'] ?? 0));
                $prefSupplier = (int)($_POST['preferred_supplier_id'] ?? 0) ?: null;

                if ($name === '' || $unit === '') {
                    throw new RuntimeException('Name and unit are required.');
                }

                if ($action === 'add') {
                    $stmt = $pdo->prepare("
                        INSERT INTO stock_ingredients (name, category, unit, min_quantity, yield_percent, notes, reorder_point, par_level, lead_time_days, preferred_supplier_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $category ?: 'General', $unit, $minQty, $yield, $notes, $reorderPoint, $parLevel, $leadTime, $prefSupplier]);
                    $message = "Ingredient \"{$name}\" added.";
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare("
                        UPDATE stock_ingredients
                        SET name = ?, category = ?, unit = ?, min_quantity = ?, yield_percent = ?, notes = ?,
                            reorder_point = ?, par_level = ?, lead_time_days = ?, preferred_supplier_id = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $category ?: 'General', $unit, $minQty, $yield, $notes, $reorderPoint, $parLevel, $leadTime, $prefSupplier, $id]);
                    $message = "Ingredient updated.";
                }
            } elseif ($action === 'archive') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("UPDATE stock_ingredients SET is_archived = 1, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                $message = 'Ingredient archived.';
            } elseif ($action === 'unarchive') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("UPDATE stock_ingredients SET is_archived = 0, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                $message = 'Ingredient restored.';
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                // Block hard delete if in recipes
                $check = $pdo->prepare("SELECT COUNT(*) FROM stock_recipe_ingredients WHERE ingredient_id = ?");
                $check->execute([$id]);
                if ((int)$check->fetchColumn() > 0) {
                    throw new RuntimeException('Cannot delete: ingredient is used in one or more recipes. Archive it instead.');
                }
                // Cascading FKs will clean up batches/adjustments/wastage tied to this ingredient.
                $del = $pdo->prepare("DELETE FROM stock_ingredients WHERE id = ?");
                $del->execute([$id]);
                $message = 'Ingredient deleted.';
            } elseif ($action === 'stock_in') {
                $id = (int)($_POST['id'] ?? 0);
                $qty = (float)($_POST['quantity'] ?? 0);
                $cost = max(0, (float)($_POST['cost_per_unit'] ?? 0));
                $supplierId = (int)($_POST['supplier_id'] ?? 0);
                $supplier = trim($_POST['supplier_name'] ?? '');
                $supplierContact = trim($_POST['supplier_contact'] ?? '');
                // If a master supplier is chosen, use its details for the legacy
                // free-text columns so batch/log rows stay human-readable.
                if ($supplierId > 0) {
                    $supRow = $pdo->prepare("SELECT name, contact_name, phone FROM stock_suppliers WHERE id = ? LIMIT 1");
                    $supRow->execute([$supplierId]);
                    if ($sup = $supRow->fetch(PDO::FETCH_ASSOC)) {
                        $supplier = (string)$sup['name'];
                        if ($supplierContact === '') {
                            $supplierContact = (string)($sup['contact_name'] ?: $sup['phone'] ?: '');
                        }
                    } else {
                        $supplierId = 0;
                    }
                }
                $expiry = trim($_POST['expiry_date'] ?? '');
                $alertDays = max(0, (int)($_POST['expiry_alert_days'] ?? 7));
                $batchNotes = trim($_POST['notes'] ?? '');

                if ($qty <= 0) throw new RuntimeException('Quantity must be greater than zero.');

                $pdo->beginTransaction();
                // Lock ingredient row
                $sel = $pdo->prepare("SELECT current_quantity, cost_per_unit FROM stock_ingredients WHERE id = ? FOR UPDATE");
                $sel->execute([$id]);
                $ing = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$ing) throw new RuntimeException('Ingredient not found.');

                $oldQty = (float)$ing['current_quantity'];
                $oldAvg = (float)$ing['cost_per_unit'];
                $newAvg = calculateWeightedAvgCost($oldQty, $oldAvg, $qty, $cost);

                // Insert batch
                $bIns = $pdo->prepare("
                    INSERT INTO stock_batches
                        (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit,
                         supplier_id, supplier_name, supplier_contact, received_date, expiry_date, expiry_alert_days, status, notes, created_by)
                    VALUES (?, '', ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, 'active', ?, ?)
                ");
                $bIns->execute([
                    $id,
                    $qty,
                    $qty,
                    $cost,
                    $supplierId ?: null,
                    $supplier ?: null,
                    $supplierContact ?: null,
                    $expiry !== '' ? $expiry : null,
                    $alertDays,
                    $batchNotes ?: null,
                    $user['id']
                ]);
                $batchId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE stock_batches SET batch_number = ? WHERE id = ?")
                    ->execute(['B' . str_pad((string)$batchId, 6, '0', STR_PAD_LEFT), $batchId]);

                // Stock-in log
                $logIns = $pdo->prepare("
                    INSERT INTO stock_in_log
                        (ingredient_id, batch_id, quantity, cost_per_unit, cost_total, supplier_id, supplier_name, supplier_contact,
                         avg_cost_before, avg_cost_after, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $logIns->execute([
                    $id,
                    $batchId,
                    $qty,
                    $cost,
                    $qty * $cost,
                    $supplierId ?: null,
                    $supplier ?: null,
                    $supplierContact ?: null,
                    $oldAvg,
                    $newAvg,
                    $batchNotes ?: null,
                    $user['id']
                ]);

                // Update ingredient (qty + new weighted avg)
                $upd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ?, cost_per_unit = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$qty, $newAvg, $id]);

                // Stock adjustment row
                $adj = $pdo->prepare("
                    INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by)
                    VALUES (?, ?, ?, 'stock_in', ?, ?, ?)
                ");
                $adj->execute([$id, $qty, 'Stock received (batch)', $batchId, $cost, $user['id']]);

                $pdo->commit();
                $message = sprintf('Stock received: %s units. New avg cost: %s %s/unit.', number_format($qty, 3), $currency_symbol, number_format($newAvg, 2));
            } elseif ($action === 'adjust') {
                // Manual quantity adjustment — restricted to admin/manager only.
                // Anti-theft: any non-sale, non-wastage drain MUST go through Stock Count
                // with a reason and approval. This action is a back-stop for emergencies.
                if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
                    throw new RuntimeException('Manual adjustments are restricted. Use Stock Count for variance reconciliation.');
                }
                $id = (int)($_POST['id'] ?? 0);
                $direction = ($_POST['direction'] ?? 'add') === 'subtract' ? -1 : 1;
                $qty = abs((float)($_POST['quantity'] ?? 0));
                $reason = trim($_POST['reason'] ?? '');
                if ($qty <= 0) throw new RuntimeException('Quantity must be greater than zero.');
                if (mb_strlen($reason) < 8) throw new RuntimeException('A reason of at least 8 characters is required for manual adjustments.');

                $pdo->beginTransaction();
                $ingSel = $pdo->prepare('SELECT current_quantity, cost_per_unit FROM stock_ingredients WHERE id = ? FOR UPDATE');
                $ingSel->execute([$id]);
                $ingRow = $ingSel->fetch(PDO::FETCH_ASSOC);
                if (!$ingRow) {
                    throw new RuntimeException('Ingredient not found.');
                }

                $currentQty = (float)$ingRow['current_quantity'];
                $costAtTime = (float)$ingRow['cost_per_unit'];
                $reasonShort = mb_substr($reason, 0, 255);

                if ($direction > 0) {
                    // Manual add creates a real active batch so FIFO stays consistent.
                    $tmpBatchNumber = 'TMP-M-' . date('YmdHis') . '-' . strtoupper(substr(str_replace('.', '', uniqid('', true)), -8));
                    $batchIns = $pdo->prepare("\n                        INSERT INTO stock_batches (\n                            ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit,\n                            supplier_name, supplier_contact, received_date, expiry_date, expiry_alert_days, status, notes, created_by\n                        ) VALUES (?, ?, ?, ?, ?, NULL, NULL, CURDATE(), NULL, 7, 'active', ?, ?)\n                    ");
                    $batchIns->execute([
                        $id,
                        $tmpBatchNumber,
                        $qty,
                        $qty,
                        max(0, $costAtTime),
                        mb_substr('Manual adjustment add: ' . $reason, 0, 500),
                        $user['id']
                    ]);

                    $batchId = (int)$pdo->lastInsertId();
                    $batchNumber = 'M' . date('Ymd') . '-' . str_pad((string)$batchId, 6, '0', STR_PAD_LEFT);
                    $pdo->prepare('UPDATE stock_batches SET batch_number = ? WHERE id = ?')->execute([$batchNumber, $batchId]);

                    $pdo->prepare('UPDATE stock_ingredients SET current_quantity = current_quantity + ?, updated_at = NOW() WHERE id = ?')
                        ->execute([$qty, $id]);

                    $pdo->prepare("\n                        INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by)\n                        VALUES (?, ?, ?, 'manual', ?, ?, ?)\n                    ")->execute([$id, $qty, $reasonShort, $batchId, $costAtTime, $user['id']]);
                } else {
                    if ($qty > $currentQty + 0.0001) {
                        throw new RuntimeException('Cannot subtract more than current stock. Use Stock Count to reconcile physical differences.');
                    }
                    if (!function_exists('deductStockBatchFIFO')) {
                        throw new RuntimeException('Stock engine helper deductStockBatchFIFO is missing.');
                    }

                    if (function_exists('ensureStockBatchCoverageForDeduction')) {
                        ensureStockBatchCoverageForDeduction(
                            $id,
                            $qty,
                            $costAtTime,
                            $user['id'],
                            'Auto batch sync before manual subtraction'
                        );
                    }

                    $adjId = (int)(deductStockBatchFIFO($id, $qty, 'manual', null, $user['id']) ?? 0);
                    if ($adjId <= 0) {
                        throw new RuntimeException('Manual subtraction failed for this ingredient.');
                    }

                    $pdo->prepare('UPDATE stock_adjustments SET reason = ?, cost_at_time = ? WHERE id = ?')
                        ->execute([$reasonShort, $costAtTime, $adjId]);
                }

                $pdo->commit();

                $message = 'Manual adjustment recorded.';
            } elseif ($action === 'update_batch') {
                // Edit expiry / alert / notes / status on an existing batch.
                $batchId = (int)($_POST['batch_id'] ?? 0);
                $expiry  = trim($_POST['expiry_date'] ?? '');
                $alert   = max(0, (int)($_POST['expiry_alert_days'] ?? 7));
                $bnotes  = trim($_POST['notes'] ?? '');
                $upd = $pdo->prepare("
                    UPDATE stock_batches
                    SET expiry_date = ?, expiry_alert_days = ?, notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $upd->execute([
                    $expiry !== '' ? $expiry : null,
                    $alert,
                    $bnotes ?: null,
                    $batchId
                ]);
                $message = 'Batch updated.';
            } elseif ($action === 'discard_batch') {
                // Mark a batch as wasted/expired/recalled and remove its remaining qty from stock.
                $batchId = (int)($_POST['batch_id'] ?? 0);
                $reason  = trim($_POST['reason'] ?? 'Discarded');
                $newStatus = $_POST['status'] ?? 'wasted';
                $allowed = ['expired', 'recalled', 'wasted'];
                if (!in_array($newStatus, $allowed, true)) $newStatus = 'wasted';

                $pdo->beginTransaction();
                $sel = $pdo->prepare("SELECT ingredient_id, quantity_remaining, cost_per_unit FROM stock_batches WHERE id = ? FOR UPDATE");
                $sel->execute([$batchId]);
                $b = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$b) throw new RuntimeException('Batch not found.');
                $remaining = (float)$b['quantity_remaining'];
                $ingredId = (int)$b['ingredient_id'];
                $cost = (float)$b['cost_per_unit'];

                if ($remaining > 0) {
                    // Subtract from ingredient stock
                    $pdo->prepare("UPDATE stock_ingredients SET current_quantity = GREATEST(0, current_quantity - ?), updated_at = NOW() WHERE id = ?")
                        ->execute([$remaining, $ingredId]);
                    // Zero out the batch
                    $pdo->prepare("UPDATE stock_batches SET quantity_remaining = 0, status = ?, notes = CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END, ?), updated_at = NOW() WHERE id = ?")
                        ->execute([$newStatus, '[' . date('Y-m-d') . '] ' . $newStatus . ': ' . $reason, $batchId]);
                    // Record adjustment under the appropriate enum so Theft Radar isn't polluted.
                    $adjType = $newStatus === 'expired' ? 'expiry' : ($newStatus === 'recalled' ? 'recall' : 'wastage');
                    $pdo->prepare("
                        INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$ingredId, -$remaining, "Batch {$newStatus}: {$reason}", $adjType, $batchId, $cost, $user['id']]);
                } else {
                    $pdo->prepare("UPDATE stock_batches SET status = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$newStatus, $batchId]);
                }
                $pdo->commit();
                $message = ucfirst($newStatus) . " — batch closed and stock adjusted.";
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }

    // POST/redirect/GET to prevent resubmission
    if ($message) {
        $_SESSION['stock_msg'] = $message;
        // Invalidate stock dashboard cache
        if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v3');
    }
    if ($error)   $_SESSION['stock_err'] = $error;
    header('Location: stock-ingredients.php');
    exit;
}

// Pull session flashes
if (!empty($_SESSION['stock_msg'])) {
    $message = $_SESSION['stock_msg'];
    unset($_SESSION['stock_msg']);
}
if (!empty($_SESSION['stock_err'])) {
    $error   = $_SESSION['stock_err'];
    unset($_SESSION['stock_err']);
}

// ---- Fetch data ----
$ingredients = [];
$categories = [];
$batchesByIngredient = [];
$supplierOptions = [];
try {
    $supplierOptions = $pdo->query("SELECT id, name FROM stock_suppliers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $supplierOptions = []; }
if (!$error || strpos($error, 'Stock tables not yet') === false) {
    try {
        $stmt = $pdo->query("
            SELECT i.*,
                   (SELECT MIN(b.expiry_date) FROM stock_batches b WHERE b.ingredient_id = i.id AND b.status = 'active' AND b.expiry_date IS NOT NULL AND b.quantity_remaining > 0) AS next_expiry
            FROM stock_ingredients i
            ORDER BY i.is_archived ASC, i.category ASC, i.name ASC
        ");
        $ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ingredients as $i) {
            if (!in_array($i['category'], $categories, true)) $categories[] = $i['category'];
        }
        sort($categories);

        // Export to Excel (CSV — opens directly in Excel). Mirrors the CSV export
        // convention used on stock-reports.php. Must run before any HTML output.
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $fname = 'stock-management-' . date('Ymd-His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel renders accented characters and the currency symbol correctly
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Name', 'Category', 'Unit', 'Current Qty', 'Min Qty', 'Reorder Point', 'Par Level', 'Lead Time (days)', 'Cost/Unit', 'Stock Value', 'Next Expiry', 'Status']);
            foreach ($ingredients as $row) {
                $qty  = (float)($row['current_quantity'] ?? 0);
                $cost = (float)($row['cost_per_unit'] ?? 0);
                fputcsv($out, [
                    $row['name'] ?? '',
                    $row['category'] ?? '',
                    $row['unit'] ?? '',
                    $qty,
                    (float)($row['min_quantity'] ?? 0),
                    (float)($row['reorder_point'] ?? 0),
                    (float)($row['par_level'] ?? 0),
                    (int)($row['lead_time_days'] ?? 0),
                    number_format($cost, 2, '.', ''),
                    number_format($qty * $cost, 2, '.', ''),
                    $row['next_expiry'] ?? '',
                    !empty($row['is_archived']) ? 'Archived' : 'Active',
                ]);
            }
            fclose($out);
            exit;
        }

        // Pull batches (last 60d + every batch with stock left or expiring) for the manage-batches modal
        $bstmt = $pdo->query("
            SELECT id, ingredient_id, batch_number, quantity_received, quantity_remaining,
                   cost_per_unit, supplier_name, supplier_contact,
                   received_date, expiry_date, expiry_alert_days, status, notes
            FROM stock_batches
            WHERE status = 'active'
               OR quantity_remaining > 0
               OR received_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
               OR (expiry_date IS NOT NULL AND expiry_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
            ORDER BY ingredient_id ASC,
                     CASE WHEN status = 'active' THEN 0 ELSE 1 END,
                     COALESCE(expiry_date, '9999-12-31') ASC,
                     id DESC
        ");
        foreach ($bstmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $batchesByIngredient[(int)$b['ingredient_id']][] = $b;
        }
    } catch (Throwable $e) {
        $error = 'Failed to load ingredients: ' . $e->getMessage();
    }
}

$csrf_token = generateCsrfToken();

function stock_status_badge(array $i, ?int $expDays = null): string
{
    $cur = (float)$i['current_quantity'];
    $min = (float)$i['min_quantity'];
    if ((int)$i['is_archived'] === 1) return '<span class="badge badge-archived">Archived</span>';
    if ($cur <= 0)                     return '<span class="badge badge-critical">Critical</span>';
    if ($expDays !== null && $expDays < 0)  return '<span class="badge badge-critical">Expired</span>';
    if ($min > 0 && $cur <= $min)      return '<span class="badge badge-low">Low</span>';
    if ($expDays !== null && $expDays <= 7) return '<span class="badge badge-low">Expiring</span>';
    return '<span class="badge badge-ok">OK</span>';
}

// Preset-aware vocabulary: a kitchen stocks "Ingredients" (raw materials that
// recipes consume); a shop/supermarket stocks "Stock Items" (the products
// themselves, auto-linked 1:1 from Product Management). Same table, same
// ledger — only the noun changes.
$stockIsFood  = function_exists('isRestaurantEnabled') && isRestaurantEnabled();
$stockNoun    = $stockIsFood ? 'Ingredient' : 'Stock Item';
$stockNounPl  = $stockIsFood ? 'Ingredients' : 'Stock Items';
$stockNounLow = $stockIsFood ? 'ingredient' : 'stock item';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?php echo $stockNounPl; ?> — Stock Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-ingredients.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-ingredients.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content stock-ingredients-page">
        <div class="page-header">
            <h2 class="page-title"><i class="fas <?php echo $stockIsFood ? 'fa-carrot' : 'fa-boxes-stacked'; ?>" style="color:var(--color-primary,#8A775F);"></i> <?php echo $stockNounPl; ?></h2>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php if (!empty($ingredients)): ?>
                    <a class="btn-secondary" href="?export=csv" style="display:inline-flex; align-items:center; gap:6px; text-decoration:none;" title="Download all <?php echo strtolower($stockNounPl); ?> as an Excel-compatible spreadsheet"><i class="fas fa-file-excel"></i> Export to Excel</a>
                <?php endif; ?>
                <button class="btn-add" onclick="openIngredientModal()"><i class="fas fa-plus"></i> Add <?php echo $stockNoun; ?></button>
            </div>
        </div>

        <?php if ($message): showAlert($message, 'success');
        endif; ?>
        <?php if ($error):   showAlert($error,   'error');
        endif; ?>

        <div class="info-banner">
            <strong>How this page works:</strong>
            Each <em>ingredient</em> holds a running total of stock and a weighted-average cost. Stock arrives in <em>batches</em> (each with its own expiry date) — click <strong>Batches</strong> on any row to view, edit expiry, or write off a specific batch. The earliest batch expiry is shown in the <em>Next expiry</em> column.
            <div class="legend">
                <span><i class="dot dot-ok"></i> OK — above min stock & not near expiry</span>
                <span><i class="dot dot-warn"></i> Low / Expiring soon — reorder or use up</span>
                <span><i class="dot dot-crit"></i> Critical / Expired — out of stock or past date</span>
            </div>
        </div>

        <div class="stock-toolbar">
            <input type="text" id="filter-search" placeholder="Search <?php echo $stockNounLow; ?>..." oninput="filterTable()">
            <select id="filter-category" onchange="filterTable()">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-status" onchange="filterTable()">
                <option value="">All statuses</option>
                <option value="ok">OK</option>
                <option value="low">Low</option>
                <option value="critical">Critical</option>
                <option value="expiring">Expiring soon</option>
                <option value="archived">Archived</option>
            </select>
            <span style="color:#6c757d;font-size:13px;"><?php echo count($ingredients); ?> ingredient(s)</span>
        </div>

        <div class="table-responsive">
            <table class="stock-table" id="stock-table">
                <thead>
                    <tr>
                        <th>Name <i class="help" data-tip="The ingredient's display name. Used everywhere — recipes, stock-in logs, wastage reports.">?</i></th>
                        <th>Category <i class="help" data-tip="Free-text grouping (e.g. Dairy, Meat, Pantry). Used to keep the recipe-builder dropdown tidy.">?</i></th>
                        <th>Unit <i class="help" data-tip="Base unit of measure for this ingredient. All stock-in receipts, recipe quantities, and adjustments use this same unit. Pick once and stick to it.">?</i></th>
                        <th style="text-align:right;">Current <i class="help" data-tip="How much you have on hand right now (sum of all active batches). Updated automatically when you receive stock or when a recipe is sold/wasted.">?</i></th>
                        <th style="text-align:right;">Min <i class="help" data-tip="Low-stock threshold. When current quantity drops to or below this number, the row turns yellow (Low) and shows up in reorder reports.">?</i></th>
                        <th style="text-align:right;">Yield % <i class="help" data-tip="Usable yield after prep losses. Example: 1 kg raw chicken at 75 % yield gives 750 g cooked. The recipe builder uses this to figure out how much RAW stock to deduct per portion. Set to 100 % for items with no trim/cook loss (rice, water, oil).">?</i></th>
                        <th style="text-align:right;">Avg cost / unit <i class="help" data-tip="Weighted-average cost across all batches received. Recalculated automatically every time you stock-in at a different price. This drives recipe food-cost %.">?</i></th>
                        <th>Next expiry <i class="help" data-tip="The earliest expiry date among all active batches of this ingredient. Click Batches to see/edit each batch individually. Green = plenty of time, yellow = within your alert window, red = ≤ 3 days or already expired.">?</i></th>
                        <th>Status <i class="help" data-tip="OK · Low (≤ min) · Critical (zero or below) · Archived (hidden from recipes).">?</i></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ingredients as $i):
                        $cur = (float)$i['current_quantity'];
                        $min = (float)$i['min_quantity'];
                        // Days until next expiry (null if no batch with expiry)
                        $expDays = null;
                        if (!empty($i['next_expiry'])) {
                            $expDays = (int)(new DateTime('today'))->diff(new DateTime($i['next_expiry']))->format('%r%a');
                        }
                        $isExpiringSoon = ($expDays !== null && $expDays >= 0 && $expDays <= 7);
                        $isExpired      = ($expDays !== null && $expDays < 0);
                        $rowStatus = ((int)$i['is_archived'] === 1) ? 'archived'
                            : ($cur <= 0     ? 'critical'
                            : ($isExpired    ? 'critical'
                            : ($min > 0 && $cur <= $min ? 'low'
                            : ($isExpiringSoon ? 'expiring' : 'ok'))));
                        $qtyClass = $cur <= 0 ? 'qty-critical' : ($min > 0 && $cur <= $min ? 'qty-low' : '');

                        // Expiry pill class
                        $expCls = 'exp-none';
                        $expLabel = '<i class="exp-d">—</i>';
                        if ($expDays !== null) {
                            if ($expDays < 0) {
                                $expCls = 'exp-crit';
                                $expLabel = '<i class="fas fa-exclamation-triangle"></i> <span class="exp-d">Expired</span>';
                            } elseif ($expDays <= 3) {
                                $expCls = 'exp-crit';
                                $expLabel = '<i class="fas fa-exclamation-triangle"></i> <span class="exp-d">' . $expDays . 'd left</span>';
                            } elseif ($expDays <= 14) {
                                $expCls = 'exp-warn';
                                $expLabel = '<i class="fas fa-clock"></i> <span class="exp-d">' . $expDays . 'd left</span>';
                            } else {
                                $expCls = 'exp-ok';
                                $expLabel = '<span class="exp-d">' . $expDays . 'd left</span>';
                            }
                        }
                        $hasBatches = !empty($batchesByIngredient[(int)$i['id']]);
                    ?>
                        <tr class="ing-row <?php echo $i['is_archived'] ? 'archived' : ''; ?>"
                            data-name="<?php echo htmlspecialchars(strtolower($i['name'])); ?>"
                            data-category="<?php echo htmlspecialchars($i['category']); ?>"
                            data-status="<?php echo $rowStatus; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($i['name']); ?></strong>
                                <?php if (!empty($i['notes'])): ?>
                                    <div style="color:#6c757d; font-size:11px; margin-top:2px;"><?php echo htmlspecialchars($i['notes']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($i['category']); ?></td>
                            <td><?php echo htmlspecialchars($i['unit']); ?></td>
                            <td class="qty-num <?php echo $qtyClass; ?>" style="text-align:right;"><?php echo number_format($cur, 3); ?></td>
                            <td class="qty-num" style="text-align:right;"><?php echo number_format($min, 3); ?></td>
                            <td class="qty-num" style="text-align:right;"><?php echo number_format((float)$i['yield_percent'], 1); ?></td>
                            <td class="qty-num" style="text-align:right;"><?php echo $currency_symbol . ' ' . number_format((float)$i['cost_per_unit'], 2); ?></td>
                            <td>
                                <?php if (!empty($i['next_expiry'])): ?>
                                    <div class="exp-pill <?php echo $expCls; ?>">
                                        <?php echo $expLabel; ?>
                                    </div>
                                    <div style="font-size:10px;color:#6c757d;margin-top:2px;">
                                        <?php echo htmlspecialchars(date('d M Y', strtotime($i['next_expiry']))); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="exp-pill exp-none">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo stock_status_badge($i, $expDays); ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <?php if (!$i['is_archived']): ?>
                                        <button class="btn-stockin" onclick='openStockInModal(<?php echo (int)$i['id']; ?>, <?php echo json_encode($i['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo json_encode($i['unit'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo (float)$i['cost_per_unit']; ?>, <?php echo (float)$i['current_quantity']; ?>)'>
                                            <i class="fas fa-truck-loading"></i> Stock In
                                        </button>
                                        <button onclick='openBatchesModal(<?php echo (int)$i['id']; ?>, <?php echo json_encode($i['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo json_encode($i['unit'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)' title="View &amp; manage batches / expiry dates" style="<?php echo $hasBatches ? '' : 'opacity:0.6;'; ?>">
                                            <i class="fas fa-boxes-stacked"></i> Batches
                                            <?php if ($hasBatches): ?>
                                                <span style="background:#8B7355;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;"><?php echo count($batchesByIngredient[(int)$i['id']]); ?></span>
                                            <?php endif; ?>
                                        </button>
                                        <button class="btn-adjust" onclick='openAdjustModal(<?php echo (int)$i['id']; ?>, <?php echo json_encode($i['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo json_encode($i['unit'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'>
                                            <i class="fas fa-balance-scale"></i> Adjust
                                        </button>
                                    <?php endif; ?>
                                    <button onclick='openIngredientModal(<?php echo json_encode($i, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php if (!$i['is_archived']): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this ingredient?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="id" value="<?php echo (int)$i['id']; ?>">
                                            <button class="btn-danger"><i class="fas fa-archive"></i> Archive</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="unarchive">
                                            <input type="hidden" name="id" value="<?php echo (int)$i['id']; ?>">
                                            <button><i class="fas fa-undo"></i> Restore</button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete? This cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int)$i['id']; ?>">
                                            <button class="btn-danger"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ingredients)): ?>
                        <tr>
                            <td colspan="10" style="text-align:center; padding:30px; color:#6c757d;">No <?php echo strtolower($stockNounPl); ?> yet. Click <strong>Add <?php echo $stockNoun; ?></strong> to get started.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit Ingredient Modal -->
    <div class="modal-overlay" id="ingredientModal">
        <div class="modal-content">
            <h3 id="ingredientModalTitle">Add <?php echo $stockNoun; ?></h3>
            <form method="POST" id="ingredientForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" id="ing_action" value="add">
                <input type="hidden" name="id" id="ing_id" value="">
                <div class="form-row">
                    <div>
                        <label>Name * <i class="help" data-tip="The name shown in dropdowns and reports. Be specific (e.g. 'Chicken breast (boneless)' not just 'Chicken').">?</i></label>
                        <input type="text" name="name" id="ing_name" required maxlength="200">
                    </div>
                    <div>
                        <label>Category <i class="help" data-tip="<?php echo $stockIsFood ? 'Used to group ingredients in the recipe-builder dropdown. Pick consistent names like Dairy, Meat, Pantry, Vegetables, Beverages.' : 'Used to group stock items in lists and reports. Pick consistent names like Groceries, Beverages, Household.'; ?>">?</i></label>
                        <input type="text" name="category" id="ing_category" placeholder="<?php echo $stockIsFood ? 'e.g. Dairy, Meat, Produce' : 'e.g. Groceries, Beverages, Household'; ?>" maxlength="100">
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label>Unit * <i class="help" data-tip="<?php echo $stockIsFood ? 'The base unit for ALL stock movements of this ingredient — receipts, recipe quantities, wastage. Choose carefully; changing it later breaks existing recipes.' : 'The base unit for ALL stock movements of this item — receipts, sales deductions, wastage. Retail products normally use Pieces (pcs).'; ?>">?</i></label>
                        <select name="unit" id="ing_unit" required>
                            <option value="g">Grams (g)</option>
                            <option value="kg">Kilograms (kg)</option>
                            <option value="ml">Millilitres (ml)</option>
                            <option value="L">Litres (L)</option>
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="slice">Slices</option>
                            <option value="tsp">Teaspoons (tsp)</option>
                            <option value="tbsp">Tablespoons (tbsp)</option>
                        </select>
                    </div>
                    <div>
                        <label>Min quantity (low-stock threshold) <i class="help" data-tip="When current stock drops to or below this number, the <?php echo $stockNounLow; ?> turns yellow on the dashboard and appears in reorder reports. Leave at 0 if you don't want low-stock alerts.">?</i></label>
                        <input type="number" name="min_quantity" id="ing_min" step="0.001" min="0" value="0">
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label>Default yield % <i class="help" data-tip="How much of the raw weight ends up usable on the plate after trim/cook losses. The recipe builder uses this to compute how much RAW stock to deduct per portion.">?</i></label>
                        <input type="number" name="yield_percent" id="ing_yield" step="0.1" min="0.1" max="100" value="100">
                        <div class="yield-explainer">
                            <strong>What yield means:</strong> if you buy 1 kg of raw chicken at <strong>75 %</strong> yield, you'll plate only <strong>0.75 kg</strong>. To serve a 150 g portion the system will deduct <strong>200 g raw</strong> (150 ÷ 0.75). Use <strong>100 %</strong> for items with no loss (rice, oil, spices, bottled drinks).
                        </div>
                    </div>
                    <div>
                        <label>Notes <i class="help" data-tip="Internal notes — e.g. preferred supplier, preparation tips. Shown under the name in the table.">?</i></label>
                        <input type="text" name="notes" id="ing_notes" maxlength="500">
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label>Reorder point <i class="help" data-tip="When stock hits this level, the item appears in the Reorder / Buying report. Usually set to cover your supplier's lead time. Falls back to Min quantity if left at 0.">?</i></label>
                        <input type="number" name="reorder_point" id="ing_reorder" step="0.001" min="0" value="0">
                    </div>
                    <div>
                        <label>Par level (order up to) <i class="help" data-tip="Target stock level to top up to when reordering. Suggested order = Par − on hand − on order.">?</i></label>
                        <input type="number" name="par_level" id="ing_par" step="0.001" min="0" value="0">
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label>Preferred supplier <i class="help" data-tip="Default supplier used to group this item on the Reorder report and pre-fill purchase orders.">?</i></label>
                        <select name="preferred_supplier_id" id="ing_supplier">
                            <option value="0">— None —</option>
                            <?php foreach ($supplierOptions as $so): ?>
                                <option value="<?php echo (int)$so['id']; ?>"><?php echo htmlspecialchars($so['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Supplier lead time (days) <i class="help" data-tip="Typical days from placing an order to delivery. Used to prioritise urgent reorders.">?</i></label>
                        <input type="number" name="lead_time_days" id="ing_lead" min="0" max="365" value="0">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('ingredientModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Stock-In Modal -->
    <div class="modal-overlay" id="stockInModal">
        <div class="modal-content">
            <h3>Stock In: <span id="si_name"></span></h3>
            <form method="POST" id="stockInForm" onsubmit="return confirmStockIn(this);">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="stock_in">
                <input type="hidden" name="id" id="si_id" value="">
                <input type="hidden" id="si_current_qty" value="0">
                <div class="form-row">
                    <div>
                        <label>Quantity received (<span id="si_unit"></span>) * <i class="help" data-tip="Exact quantity that arrived in this delivery, in the ingredient's base unit. This adds to current stock.">?</i></label>
                        <input type="number" name="quantity" id="si_qty" step="0.001" min="0.001" required>
                    </div>
                    <div>
                        <label>Cost per unit (<?php echo htmlspecialchars($currency_symbol); ?>) <i class="help" data-tip="Price you paid per unit for THIS batch. Combined with current stock, it updates the weighted-average cost used by recipes.">?</i></label>
                        <input type="number" name="cost_per_unit" id="si_cost" step="0.0001" min="0" value="0">
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label>Supplier <i class="help" data-tip="Pick a supplier from your master list for traceability and reorder history. Choose 'Other' to type a one-off name.">?</i></label>
                        <select name="supplier_id" id="si_supplier" onchange="toggleSupplierOther(this)">
                            <option value="0">— Select supplier —</option>
                            <?php foreach ($supplierOptions as $so): ?>
                                <option value="<?php echo (int)$so['id']; ?>"><?php echo htmlspecialchars($so['name']); ?></option>
                            <?php endforeach; ?>
                            <option value="0" data-other="1">— Other / one-off —</option>
                        </select>
                        <input type="text" name="supplier_name" id="si_supplier_other" maxlength="200" placeholder="Supplier name" style="display:none;margin-top:6px;">
                    </div>
                    <div>
                        <label>Supplier contact <i class="help" data-tip="Phone or email — handy if you need to reorder or report a quality issue.">?</i></label>
                        <input type="text" name="supplier_contact" maxlength="200" placeholder="Phone / email">
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label>Expiry date <i class="help" data-tip="Best-before / use-by date printed on this batch. The system tracks it per batch and warns you BEFORE the expiry-alert window. Leave blank for non-perishables (rice, oil, spices).">?</i></label>
                        <input type="date" name="expiry_date" id="si_expiry">
                    </div>
                    <div>
                        <label>Alert me ___ days before expiry <i class="help" data-tip="How many days in advance to start showing a yellow warning. Default 7. Set to 14 for slow-moving items, 3 for highly perishable.">?</i></label>
                        <input type="number" name="expiry_alert_days" min="0" value="7">
                    </div>
                </div>
                <div>
                    <label>Notes</label>
                    <input type="text" name="notes" maxlength="500">
                </div>
                <div id="si_warning" style="display:none; margin-top:12px;" class="stock-warn"></div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('stockInModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Receive Stock</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Adjust Modal -->
    <div class="modal-overlay" id="adjustModal">
        <div class="modal-content">
            <h3>Manual Adjustment: <span id="adj_name"></span></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="id" id="adj_id" value="">
                <div class="form-row">
                    <div>
                        <label>Direction *</label>
                        <select name="direction" required>
                            <option value="add">Add stock (+)</option>
                            <option value="subtract">Subtract stock (−)</option>
                        </select>
                    </div>
                    <div>
                        <label>Quantity (<span id="adj_unit"></span>) *</label>
                        <input type="number" name="quantity" step="0.001" min="0.001" required>
                    </div>
                </div>
                <div>
                    <label>Reason *</label>
                    <input type="text" name="reason" required maxlength="250" placeholder="e.g. Stock count correction, found extra, breakage">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('adjustModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Batches Modal -->
    <div class="modal-overlay" id="batchesModal">
        <div class="modal-content">
            <h3 style="display:flex;align-items:center;gap:10px;">
                <i class="fas fa-boxes-stacked" style="color:#8B7355;"></i>
                Batches: <span id="bm_name"></span>
                <span style="font-weight:400; font-size:13px; color:#6c757d;">(unit: <span id="bm_unit"></span>)</span>
            </h3>
            <p style="font-size:13px; color:#5b4a1f; background:#fffbf2; border-left:3px solid #f0ad4e; padding:8px 12px; border-radius:4px; margin:0 0 12px;">
                Each batch tracks its own <strong>expiry date</strong>, <strong>supplier</strong>, and <strong>quantity remaining</strong>.
                Edit a batch to fix a wrong expiry date or notes. Use <strong>Discard</strong> to write off expired/recalled stock — that automatically subtracts the remaining quantity from total stock.
            </p>

            <div id="bm_table_wrap"></div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('batchesModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Single Batch Modal -->
    <div class="modal-overlay" id="editBatchModal">
        <div class="modal-content" style="max-width:520px;">
            <h3>Edit Batch <span id="eb_batchno" style="color:#8B7355;"></span></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="update_batch">
                <input type="hidden" name="batch_id" id="eb_id">
                <div class="form-row">
                    <div>
                        <label>Expiry date <i class="help" data-tip="Correct the printed best-before / use-by date. Leave empty if this batch never expires.">?</i></label>
                        <input type="date" name="expiry_date" id="eb_expiry">
                    </div>
                    <div>
                        <label>Alert ___ days before <i class="help" data-tip="How early to start warning before this specific batch expires.">?</i></label>
                        <input type="number" name="expiry_alert_days" id="eb_alert" min="0" value="7">
                    </div>
                </div>
                <div style="font-size:12px; color:#6c757d; margin-top:2px;">
                    Status changes that remove stock are handled by <strong>Discard Batch</strong>.
                </div>
                <div>
                    <label>Notes</label>
                    <input type="text" name="notes" id="eb_notes" maxlength="500">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('editBatchModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Save Batch</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Discard Batch Modal -->
    <div class="modal-overlay" id="discardBatchModal">
        <div class="modal-content" style="max-width:480px;">
            <h3 style="color:#c82333;"><i class="fas fa-trash-can"></i> Discard Batch <span id="db_batchno"></span></h3>
            <form method="POST" onsubmit="return confirm('This will subtract the remaining quantity from your total stock and close the batch. Continue?');">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="discard_batch">
                <input type="hidden" name="batch_id" id="db_id">
                <p style="background:#fff3cd; padding:10px 12px; border-radius:5px; font-size:13px; color:#856404;">
                    Remaining quantity: <strong id="db_remaining"></strong>. Discarding writes this off and removes it from total stock.
                </p>
                <div>
                    <label>Reason</label>
                    <select name="status" id="db_status">
                        <option value="wasted">Wasted (spoilage / damage)</option>
                        <option value="expired">Expired (past use-by date)</option>
                        <option value="recalled">Recalled (supplier issue)</option>
                    </select>
                </div>
                <div>
                    <label>Details</label>
                    <input type="text" name="reason" maxlength="250" placeholder="e.g. Found mouldy, dented can, supplier recall #123" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('discardBatchModal')">Cancel</button>
                    <button type="submit" class="btn-primary" style="background:#c82333;">Discard Batch</button>
                </div>
            </form>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
    <script>
        const BATCHES = <?php echo json_encode($batchesByIngredient, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const CURRENCY = <?php echo json_encode($currency_symbol); ?>;

        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => {
                if (e.target === m) m.classList.remove('active');
            });
        });

        function openIngredientModal(data) {
            document.getElementById('ingredientForm').reset();
            if (data) {
                document.getElementById('ingredientModalTitle').textContent = 'Edit <?php echo $stockNoun; ?>';
                document.getElementById('ing_action').value = 'update';
                document.getElementById('ing_id').value = data.id;
                document.getElementById('ing_name').value = data.name || '';
                document.getElementById('ing_category').value = data.category || '';
                document.getElementById('ing_unit').value = data.unit || 'g';
                document.getElementById('ing_min').value = data.min_quantity || 0;
                document.getElementById('ing_yield').value = data.yield_percent || 100;
                document.getElementById('ing_notes').value = data.notes || '';
                document.getElementById('ing_reorder').value = data.reorder_point || 0;
                document.getElementById('ing_par').value = data.par_level || 0;
                document.getElementById('ing_lead').value = data.lead_time_days || 0;
                document.getElementById('ing_supplier').value = data.preferred_supplier_id || 0;
            } else {
                document.getElementById('ingredientModalTitle').textContent = 'Add <?php echo $stockNoun; ?>';
                document.getElementById('ing_action').value = 'add';
                document.getElementById('ing_id').value = '';
            }
            openModal('ingredientModal');
        }

        function toggleSupplierOther(sel) {
            var opt = sel.options[sel.selectedIndex];
            var other = document.getElementById('si_supplier_other');
            var isOther = opt && opt.getAttribute('data-other') === '1';
            other.style.display = isOther ? 'block' : 'none';
            if (!isOther) { other.value = ''; }
        }

        function openStockInModal(id, name, unit, lastCost, currentQty) {
            document.getElementById('stockInForm').reset();
            document.getElementById('si_id').value = id;
            document.getElementById('si_name').textContent = name;
            document.getElementById('si_unit').textContent = unit;
            document.getElementById('si_qty').step = '0.001';
            document.getElementById('si_cost').value = lastCost || 0;
            document.getElementById('si_current_qty').value = currentQty || 0;
            document.getElementById('si_warning').style.display = 'none';
            openModal('stockInModal');
        }

        function confirmStockIn(form) {
            const qty = parseFloat(form.quantity.value);
            const cur = parseFloat(document.getElementById('si_current_qty').value) || 0;
            const expiry = form.expiry_date.value;
            const warnEl = document.getElementById('si_warning');
            let warns = [];
            if (cur > 0 && qty > cur * 5) warns.push('Quantity is more than 5× current stock — please confirm this is not a typo.');
            if (expiry) {
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const exp = new Date(expiry + 'T00:00:00');
                if (exp < today) warns.push('Expiry date is in the past — this batch is already expired.');
            }
            if (warns.length) {
                warnEl.textContent = '⚠ ' + warns.join(' ');
                warnEl.style.display = 'block';
                return confirm(warns.join('\n\n') + '\n\nContinue anyway?');
            }
            return true;
        }

        function openAdjustModal(id, name, unit) {
            document.getElementById('adj_id').value = id;
            document.getElementById('adj_name').textContent = name;
            document.getElementById('adj_unit').textContent = unit;
            openModal('adjustModal');
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function expiryClass(dateStr) {
            if (!dateStr) return {
                cls: '',
                label: '—'
            };
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const exp = new Date(dateStr + 'T00:00:00');
            const days = Math.floor((exp - today) / 86400000);
            if (days < 0) return {
                cls: 'expired',
                label: `<span style="color:#721c24;font-weight:600;"><i class="fas fa-exclamation-triangle"></i> Expired ${Math.abs(days)}d ago</span>`
            };
            if (days <= 3) return {
                cls: 'expired',
                label: `<span style="color:#721c24;font-weight:600;">${days}d left</span>`
            };
            if (days <= 14) return {
                cls: 'warn',
                label: `<span style="color:#856404;">${days}d left</span>`
            };
            return {
                cls: '',
                label: `<span style="color:#155724;">${days}d</span>`
            };
        }

        function openBatchesModal(ingredientId, name, unit) {
            document.getElementById('bm_name').textContent = name;
            document.getElementById('bm_unit').textContent = unit;
            const list = (BATCHES[ingredientId] || []);
            const wrap = document.getElementById('bm_table_wrap');
            if (!list.length) {
                wrap.innerHTML = '<div class="batch-empty">No batches yet. Use <strong>Stock In</strong> to record your first delivery.</div>';
            } else {
                let rows = '';
                list.forEach(b => {
                    const exp = expiryClass(b.expiry_date);
                    const cls = ['batch-row', exp.cls, b.status].join(' ');
                    rows += `<tr class="${cls}">
                        <td><strong>${escapeHtml(b.batch_number)}</strong>
                            <div style="font-size:10px;color:#6c757d;">received ${escapeHtml(b.received_date || '')}</div>
                        </td>
                        <td>${parseFloat(b.quantity_remaining).toFixed(3)} <span style="color:#6c757d;">/ ${parseFloat(b.quantity_received).toFixed(3)}</span></td>
                        <td>${CURRENCY} ${Number(b.cost_per_unit||0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                        <td>${b.expiry_date ? escapeHtml(b.expiry_date) : '<span style="color:#adb5bd;">—</span>'}<div style="font-size:11px;">${exp.label}</div></td>
                        <td>${escapeHtml(b.supplier_name || '—')}</td>
                        <td><span class="batch-status-badge bs-${escapeHtml(b.status)}">${escapeHtml(b.status)}</span></td>
                        <td style="white-space:nowrap;">
                            <button type="button" onclick='editBatch(${JSON.stringify(b)})' style="border:1px solid #d6d8db; background:#fff; padding:4px 8px; border-radius:4px; cursor:pointer; font-size:11px;"><i class="fas fa-pen"></i> Edit</button>
                            ${parseFloat(b.quantity_remaining) > 0 ? `<button type="button" onclick='discardBatch(${JSON.stringify(b)})' style="border:1px solid #f1b0b7; background:#fff; color:#c82333; padding:4px 8px; border-radius:4px; cursor:pointer; font-size:11px; margin-left:4px;"><i class="fas fa-trash-can"></i> Discard</button>` : ''}
                        </td>
                    </tr>`;
                });
                wrap.innerHTML = `
                    <div style="max-height:60vh; overflow-y:auto;">
                    <table class="batch-table">
                        <thead><tr>
                            <th>Batch <i class="help" data-tip="Auto-generated batch ID + the date this delivery arrived.">?</i></th>
                            <th>Remaining / Received <i class="help" data-tip="Quantity left in this batch out of what was originally received. Drops as recipes are sold.">?</i></th>
                            <th>Cost <i class="help" data-tip="Price you paid per unit on this specific batch.">?</i></th>
                            <th>Expiry <i class="help" data-tip="Best-before / use-by date for this batch. Click Edit to fix.">?</i></th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th></th>
                        </tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                    </div>`;
            }
            openModal('batchesModal');
        }

        function editBatch(b) {
            document.getElementById('eb_id').value = b.id;
            document.getElementById('eb_batchno').textContent = b.batch_number;
            document.getElementById('eb_expiry').value = b.expiry_date || '';
            document.getElementById('eb_alert').value = b.expiry_alert_days || 7;
            document.getElementById('eb_notes').value = b.notes || '';
            openModal('editBatchModal');
        }

        function discardBatch(b) {
            document.getElementById('db_id').value = b.id;
            document.getElementById('db_batchno').textContent = b.batch_number;
            document.getElementById('db_remaining').textContent = parseFloat(b.quantity_remaining).toFixed(3);
            // pre-pick a sensible reason
            const exp = expiryClass(b.expiry_date);
            document.getElementById('db_status').value = exp.cls === 'expired' ? 'expired' : 'wasted';
            openModal('discardBatchModal');
        }

        function filterTable() {
            const q = document.getElementById('filter-search').value.toLowerCase();
            const cat = document.getElementById('filter-category').value;
            const st = document.getElementById('filter-status').value;
            document.querySelectorAll('.ing-row').forEach(row => {
                const name = row.dataset.name || '';
                const c = row.dataset.category || '';
                const s = row.dataset.status || '';
                let show = true;
                if (q && name.indexOf(q) === -1) show = false;
                if (cat && c !== cat) show = false;
                if (st && s !== st) show = false;
                row.style.display = show ? '' : 'none';
            });
        }
    </script>
</body>

</html>

