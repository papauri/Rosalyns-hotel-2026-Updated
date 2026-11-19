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
require_once __DIR__ . '/../includes/form-validation.php';
require_once __DIR__ . '/includes/stock-list-filters.php';

// Dashboard filter (?filter=low): exactly the items the low-stock alert counts (rh_stock_alert_summary 'attention').
$ingFilter = rh_stock_ingredient_filter_key($_GET['filter'] ?? '');

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

// Change main unit (Step U3, migration 090): needs stock_change_unit + stock_management.
require_once __DIR__ . '/includes/stock-unit-change-lib.php';
$canChangeUnit = !$error && rh_suc_allowed((int)$user['id']) && rh_stock_unit_changes_ready($pdo);

// Preview (JSON, nothing saved): what changes place by place, stock value before = after.
if (!$error && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['unit_change_preview'])) {
    $out = ['ok' => false, 'errors' => ['Changing the main unit is not available.'], 'fingerprint' => '', 'lines' => [], 'history' => []];
    if ($canChangeUnit) {
        $pv = rh_suc_preview($pdo, (int)$_GET['unit_change_preview'], (int)$user['id']);
        $pl = $pv['plan'] ?? null;
        $out = [
            'ok' => !empty($pv['ok']),
            'errors' => array_values((array)($pv['errors'] ?? [])),
            'fingerprint' => !empty($pv['ok']) ? (string)$pv['fingerprint'] : '',
            'name' => $pl ? (string)$pl['name'] : '',
            'old_unit' => $pl ? (string)$pl['old_unit'] : '',
            'new_unit' => $pl ? (string)$pl['new_unit'] : '',
            'measure' => $pl ? ('1 ' . $pl['big'] . ' = ' . rh_suc_fmt($pl['n']) . ' ' . rh_stock_measure_plural($pl['small'], $pl['n'])) : '',
            'value_before' => $pl ? $currency_symbol . ' ' . number_format((float)$pl['value_before'], 2) : '',
            'value_after' => $pl ? $currency_symbol . ' ' . number_format((float)$pl['value_after'], 2) : '',
            'lines' => array_values((array)($pv['lines'] ?? [])),
            'history' => [],
        ];
        foreach ((array)($pv['history'] ?? []) as $h) {
            $out['history'][] = [
                'when' => date('j M Y H:i', strtotime((string)$h['changed_at'])),
                'who' => (string)($h['who'] ?? '') !== '' ? (string)$h['who'] : 'Unknown user',
                'change' => $h['old_unit'] . ' -> ' . $h['new_unit'],
                'value' => $currency_symbol . ' ' . number_format((float)$h['value_before'], 2) . ' -> ' . number_format((float)$h['value_after'], 2),
            ];
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- POST handling ----
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security token invalid. Please reload the page.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'unit_change_apply') {
                // Confirm: one transaction, re-checks that the preview is still current (fingerprint).
                if (!$canChangeUnit) {
                    throw new RuntimeException('You do not have permission to change main units.');
                }
                $res = rh_suc_apply($pdo, (int)($_POST['id'] ?? 0), (string)($_POST['fingerprint'] ?? ''), (int)$user['id']);
                if (empty($res['ok'])) {
                    throw new RuntimeException(implode(' ', (array)$res['errors']));
                }
                $rp = $res['plan'];
                $message = $rp['name'] . ': main unit changed from ' . $rp['old_unit'] . ' to ' . $rp['new_unit']
                    . '. Stock, batches, recipes and open orders were converted; stock value stays '
                    . $currency_symbol . ' ' . number_format((float)$rp['value_after'], 2) . '.';
            } elseif ($action === 'add' || $action === 'update') {
                $name = rh_clean_text($_POST['name'] ?? '');
                $category = rh_clean_text($_POST['category'] ?? 'General');
                $unit = rh_clean_text($_POST['unit'] ?? 'g');
                $rawNums = [
                    'min_quantity' => trim((string)($_POST['min_quantity'] ?? '0')),
                    'reorder_point' => trim((string)($_POST['reorder_point'] ?? '0')),
                    'par_level' => trim((string)($_POST['par_level'] ?? '0')),
                ];
                foreach ($rawNums as $rawNum) {
                    if ($rawNum !== '' && (!is_numeric($rawNum) || (float)$rawNum < 0 || (float)$rawNum > 99999999)) {
                        throw new RuntimeException('Minimum, reorder point and par level must be numbers of 0 or more.');
                    }
                }
                $rawYield = trim((string)($_POST['yield_percent'] ?? '100'));
                if ($rawYield !== '' && (!is_numeric($rawYield) || (float)$rawYield <= 0 || (float)$rawYield > 100)) {
                    throw new RuntimeException('Yield percent must be above 0 and at most 100.');
                }
                $rawLead = trim((string)($_POST['lead_time_days'] ?? '0'));
                if ($rawLead !== '' && (!ctype_digit($rawLead) || (int)$rawLead > 365)) {
                    throw new RuntimeException('Lead time must be a whole number of days (0 to 365).');
                }
                $minQty = max(0, (float)$rawNums['min_quantity']);
                $yield = $rawYield === '' ? 100.0 : max(0.1, min(100, (float)$rawYield));
                $notes = trim((string)($_POST['notes'] ?? ''));
                $reorderPoint = max(0, (float)$rawNums['reorder_point']);
                $parLevel     = max(0, (float)$rawNums['par_level']);
                $leadTime     = max(0, (int)$rawLead);
                $prefSupplier = (int)($_POST['preferred_supplier_id'] ?? 0) ?: null;
                // Optional purchase pack (e.g. crate of 24). Stock stays in the unit above; both fields or neither.
                $packName = rh_clean_text($_POST['pack_name'] ?? '');
                $rawPack = str_replace(',', '', trim((string)($_POST['pack_size'] ?? '')));
                $packSize = null;
                if ($rawPack !== '' && $rawPack !== '0') {
                    if (!is_numeric($rawPack) || (float)$rawPack <= 0 || (float)$rawPack > 99999) {
                        throw new RuntimeException('Units per pack must be a number above 0.');
                    }
                    $packSize = round((float)$rawPack, 3);
                    if ($packName === '') {
                        $packName = 'pack';
                    }
                } else {
                    $packName = '';
                }
                if (mb_strlen($packName) > 30) {
                    throw new RuntimeException('Pack name is too long (30 characters maximum).');
                }
                // Optional pieces in one container unit (6 per packet; a dozen is implied 12): Menu Management uses it so staff can type whole pieces.
                $rawPieces = str_replace(',', '', trim((string)($_POST['pieces_per_unit'] ?? '')));
                $piecesPerUnit = null;
                if ($rawPieces !== '' && $rawPieces !== '0') {
                    if (!is_numeric($rawPieces) || (float)$rawPieces <= 0 || (float)$rawPieces > 99999) {
                        throw new RuntimeException('Pieces in one ' . $unit . ' must be a number above 0.');
                    }
                    if (rh_stock_unit_is_container($unit) && !rh_stock_unit_is_dozen($unit)) {
                        $piecesPerUnit = round((float)$rawPieces, 2);
                    }
                }
                $editId = (int)($_POST['id'] ?? 0);

                if ($name === '' || $unit === '') {
                    throw new RuntimeException('Name and unit are required.');
                }
                if (mb_strlen($name) > 100 || mb_strlen($category) > 50 || mb_strlen($unit) > 20) {
                    throw new RuntimeException('Name (100), category (50) or unit (20 characters) is too long.');
                }
                if ($action === 'update' && $editId <= 0) {
                    throw new RuntimeException('Invalid ingredient.');
                }
                /* Optional count measure (Step U1): "bottle" and "shot", N shots in a bottle, which one is the stock unit.
                 * Only a form that showed the fields (measure_present) can change it, so other posts never wipe a measure. */
                $measureOn = rh_stock_measure_ready($pdo) && !empty($_POST['measure_present']);
                $measureNew = null;
                if ($measureOn) {
                    [$measureNew, $measureErr] = rh_stock_measure_from_input($_POST);
                    if ($measureErr !== null) {
                        throw new RuntimeException($measureErr);
                    }
                }
                /* Optional bottle size in ml (Step U2): only a form that showed the field (volume_present) can change it. A stock
                 * unit that is itself a volume (litre, ml) or a weight never keeps one. */
                $volOn = rh_stock_drink_ready($pdo) && !empty($_POST['volume_present']);
                $volNew = null;
                if ($volOn) {
                    [$volNew, $volErr] = rh_drink_volume_from_input($_POST['volume_ml'] ?? '');
                    if ($volErr !== null) {
                        throw new RuntimeException($volErr);
                    }
                    if (rh_drink_unit_ml($unit) !== null || rh_drink_unit_is_weight($unit) || rh_stock_unit_is_container($unit)) { // litre stock, weights, crates / packs: no bottle size
                        $volNew = null;
                    }
                }
                if ($prefSupplier !== null) {
                    $supChk = $pdo->prepare("SELECT COUNT(*) FROM stock_suppliers WHERE id = ?");
                    $supChk->execute([$prefSupplier]);
                    if ((int)$supChk->fetchColumn() === 0) {
                        throw new RuntimeException('The selected supplier no longer exists.');
                    }
                }

                rh_with_create_lock($pdo, 'stock_ingredient', function () use ($pdo, $action, $name, $category, $unit, $minQty, $yield, $notes, $reorderPoint, $parLevel, $leadTime, $prefSupplier, $packName, $packSize, $piecesPerUnit, $editId, $measureOn, $measureNew, $volOn, $volNew, $user, &$message) {
                  $pdo->beginTransaction(); // the item and its count measure (plus the open count lines it re-works) change together
                  try {
                    // Archived ingredients still block the name (restoring one would clash).
                    $dup = rh_find_duplicate($pdo, 'stock_ingredients', 'name', $name, [], $action === 'update' ? $editId : null);
                    if ($dup) {
                        throw new RuntimeException(rh_duplicate_message('ingredient', (string)$dup['value']));
                    }
                    if ($action === 'add') {
                        $stmt = $pdo->prepare("
                            INSERT INTO stock_ingredients (name, category, unit, min_quantity, yield_percent, notes, reorder_point, par_level, lead_time_days, preferred_supplier_id, pack_name, pack_size, pieces_per_unit)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$name, $category ?: 'General', $unit, $minQty, $yield, $notes, $reorderPoint, $parLevel, $leadTime, $prefSupplier, $packName !== '' ? $packName : null, $packSize, $piecesPerUnit]);
                        $newIngId = (int)$pdo->lastInsertId();
                        $message = "Ingredient \"{$name}\" added.";
                        if ($measureOn && $measureNew !== null) {
                            rh_stock_measure_save($pdo, $newIngId, $measureNew, (int)$user['id'], 'Stock Items (new item)');
                        }
                        if ($volOn && $volNew !== null) {
                            rh_drink_volume_save($pdo, $newIngId, $volNew, (int)$user['id'], 'Stock Items (new item)');
                        }
                    } else {
                        /* A unit change does not convert anything: quantity on hand, batches, cost per
                         * unit, recipe quantities, par/reorder levels and pack sizes would all stay as
                         * numbers in the OLD unit. Only an ingredient with no stock footprint may change it. */
                        $oldRow = $pdo->prepare("SELECT unit, current_quantity FROM stock_ingredients WHERE id = ? FOR UPDATE");
                        $oldRow->execute([$editId]);
                        $old = $oldRow->fetch(PDO::FETCH_ASSOC);
                        if (!$old) {
                            throw new RuntimeException('Invalid ingredient.');
                        }
                        if (mb_strtolower(trim((string)$old['unit'])) !== mb_strtolower(trim($unit))) {
                            $inUse = abs((float)$old['current_quantity']) > 0.00005;
                            foreach (['stock_recipe_ingredients', 'stock_batches', 'stock_adjustments'] as $usedTable) {
                                if ($inUse) {
                                    break;
                                }
                                $usedChk = $pdo->prepare("SELECT COUNT(*) FROM `{$usedTable}` WHERE ingredient_id = ?");
                                $usedChk->execute([$editId]);
                                $inUse = ((int)$usedChk->fetchColumn() > 0);
                            }
                            if ($inUse) {
                                throw new RuntimeException('Unit cannot be changed once the ingredient has stock, batches or recipes - create a new ingredient and archive this one.');
                            }
                        }
                        $stmt = $pdo->prepare("
                            UPDATE stock_ingredients
                            SET name = ?, category = ?, unit = ?, min_quantity = ?, yield_percent = ?, notes = ?,
                                reorder_point = ?, par_level = ?, lead_time_days = ?, preferred_supplier_id = ?, pack_name = ?, pack_size = ?, pieces_per_unit = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$name, $category ?: 'General', $unit, $minQty, $yield, $notes, $reorderPoint, $parLevel, $leadTime, $prefSupplier, $packName !== '' ? $packName : null, $packSize, $piecesPerUnit, $editId]);
                        $message = "Ingredient updated.";
                        if ($measureOn) {
                            $mRes = rh_stock_measure_save($pdo, $editId, $measureNew, (int)$user['id']);
                            if ($mRes['changed']) {
                                $message .= ' Count measure ' . ($measureNew === null ? 'removed.' : 'saved: ' . rh_stock_measure_describe($measureNew) . '.');
                                if ($mRes['lines'] > 0) {
                                    $message .= ' ' . $mRes['lines'] . ' counted line' . ($mRes['lines'] === 1 ? '' : 's') . ' on ' . $mRes['counts'] . ' open count' . ($mRes['counts'] === 1 ? ' was' : 's were') . ' re-worked with it.';
                                }
                            }
                        }
                        if ($volOn && rh_drink_volume_save($pdo, $editId, $volNew, (int)$user['id'])) {
                            $message .= ' Bottle size ' . ($volNew === null ? 'cleared.' : 'saved: ' . rh_stock_qty_fmt($volNew, 0) . ' ml.') . ' Stock and saved recipe amounts are unchanged.';
                        }
                    }
                    $pdo->commit();
                  } catch (Throwable $txE) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $txE;
                  }
                });
            } elseif ($action === 'save_drink_measures') {
                // The hotel's one list of drink measures (Step U2). Sizes only: stock, cost and saved recipes do not change.
                if (!rh_stock_drink_ready($pdo)) {
                    throw new RuntimeException('Drink measures are not set up on this system yet.');
                }
                $dmNames = $_POST['measure_name'] ?? [];
                $dmMls = $_POST['measure_ml'] ?? [];
                if (!is_array($dmNames) || !is_array($dmMls) || count($dmNames) > 100) {
                    throw new RuntimeException('The measures list was not sent correctly. Reload the page and try again.');
                }
                $dmRows = [];
                foreach (array_values($dmNames) as $dmK => $dmName) {
                    $dmRows[] = ['name' => rh_clean_text((string)$dmName), 'ml' => (string)(array_values($dmMls)[$dmK] ?? '')];
                }
                [$dmList, $dmErr] = rh_drink_measures_clean($dmRows);
                if ($dmErr !== null) {
                    throw new RuntimeException($dmErr);
                }
                $message = rh_drink_measures_save($pdo, $dmList, (int)$user['id'])
                    ? 'Drink measures saved. Stock, cost and recipe amounts already saved are unchanged.'
                    : 'No change to the drink measures.';
            } elseif ($action === 'archive') {
                $id = (int)($_POST['id'] ?? 0);
                /* Owner 2026-10-19: an archived ingredient drops out of the stock valuation, so stock
                 * on hand must be counted or written off first (no value vanishing silently). */
                $curQ = $pdo->prepare("SELECT current_quantity, unit FROM stock_ingredients WHERE id = ?");
                $curQ->execute([$id]);
                $cur = $curQ->fetch(PDO::FETCH_ASSOC);
                if (!$cur) {
                    throw new RuntimeException('Ingredient not found.');
                }
                if (abs((float)$cur['current_quantity']) > 0.00005) {
                    throw new RuntimeException('Cannot archive: ' . rtrim(rtrim(number_format((float)$cur['current_quantity'], 3, '.', ''), '0'), '.')
                        . ' ' . $cur['unit'] . ' is still in stock. Count it in a stock count or record it as wastage first, so its value is written off properly.');
                }
                // An archived item must not move stock: refuse while a recipe still uses it (each sale would deduct it).
                $useQ = $pdo->prepare("SELECT DISTINCT COALESCE(mi.item_name, CONCAT('menu item #', sr.menu_item_id)) AS dish
                                       FROM stock_recipe_ingredients sri
                                       INNER JOIN stock_recipes sr ON sr.id = sri.recipe_id
                                       LEFT JOIN menu_items mi ON mi.id = sr.menu_item_id
                                       WHERE sri.ingredient_id = ? ORDER BY dish LIMIT 4");
                $useQ->execute([$id]);
                $dishes = $useQ->fetchAll(PDO::FETCH_COLUMN);
                if ($dishes) {
                    $more = count($dishes) > 3 ? ' and others' : '';
                    throw new RuntimeException('Cannot archive: this ingredient is still in the recipe for ' . implode(', ', array_slice($dishes, 0, 3)) . $more
                        . '. Remove it from those recipes (or swap in another ingredient) on the Recipes page first.');
                }
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
                /* The FKs CASCADE, so deleting would erase this ingredient's whole usage, wastage and
                 * stock-in ledger (past COGS, shrinkage and supplier spend would change retroactively)
                 * and make any stock on hand vanish with no write-off. Only a never-used ingredient
                 * may be hard-deleted; everything else is archived. */
                $curQ = $pdo->prepare("SELECT current_quantity FROM stock_ingredients WHERE id = ? FOR UPDATE");
                $curQ->execute([$id]);
                $curVal = $curQ->fetchColumn();
                if ($curVal === false) {
                    throw new RuntimeException('Ingredient not found.');
                }
                $hasHistory = abs((float)$curVal) > 0.00005;
                foreach (['stock_adjustments', 'stock_batches', 'stock_in_log', 'stock_wastage', 'stock_purchase_order_items', 'stock_count_lines', 'stock_unit_changes'] as $histTable) {
                    if ($hasHistory) {
                        break;
                    }
                    try {
                        $histChk = $pdo->prepare("SELECT COUNT(*) FROM `{$histTable}` WHERE ingredient_id = ?");
                        $histChk->execute([$id]);
                        $hasHistory = ((int)$histChk->fetchColumn() > 0);
                    } catch (Throwable $e) {
                        // table not installed on this schema: no history there
                    }
                }
                if ($hasHistory) {
                    throw new RuntimeException('Cannot delete: this ingredient has stock on hand or movement history (batches, adjustments, wastage, stock-in, counts or purchase orders). Archive it instead.');
                }
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

                if ($qty <= 0 || $qty > 99999999) throw new RuntimeException('Quantity must be greater than zero.');
                if (!is_numeric(trim((string)($_POST['quantity'] ?? ''))) || (isset($_POST['cost_per_unit']) && trim((string)$_POST['cost_per_unit']) !== '' && !is_numeric(trim((string)$_POST['cost_per_unit'])))) {
                    throw new RuntimeException('Quantity and cost must be valid numbers.');
                }
                if ($expiry !== '') {
                    $expDt = DateTime::createFromFormat('Y-m-d', $expiry);
                    if (!$expDt || $expDt->format('Y-m-d') !== $expiry) {
                        throw new RuntimeException('Expiry date is not a valid date.');
                    }
                }
                if ($alertDays > 3650) throw new RuntimeException('Expiry alert days is too large.');
                if ($supplier !== '') { $supplier = mb_substr(rh_clean_text($supplier), 0, 255); }
                $supplierContact = mb_substr(rh_clean_text($supplierContact), 0, 255);

                // Double-submit guard: the identical receipt from this session within 20 seconds is a double click.
                $stockInFp = md5(json_encode([$id, $qty, $cost, $supplierId, $supplier, $expiry, $batchNotes]));
                if (($_SESSION['stock_in_last_fp'] ?? '') === $stockInFp && (time() - (int)($_SESSION['stock_in_last_at'] ?? 0)) < 20) {
                    throw new RuntimeException('This stock was just received - duplicate submission ignored.');
                }

                $pdo->beginTransaction();
                // Lock ingredient row
                $sel = $pdo->prepare("SELECT current_quantity, cost_per_unit, category FROM stock_ingredients WHERE id = ? FOR UPDATE");
                $sel->execute([$id]);
                $ing = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$ing) throw new RuntimeException('Ingredient not found.');

                $oldQty = (float)$ing['current_quantity'];
                $oldAvg = (float)$ing['cost_per_unit'];
                // Blank / 0 cost falls back to the current average; refuse when there is none.
                $cost = rh_stock_receipt_cost($cost, $oldAvg);
                $newAvg = calculateWeightedAvgCost($oldQty, $oldAvg, $qty, $cost);
                // Settle any unallocated deficit so batch sum stays equal to current_quantity.
                $settle = rh_stock_receipt_settlement($pdo, $id, $oldQty, $qty);
                $settledTxt = rtrim(rtrim(number_format($settle['settled'], 6, '.', ''), '0'), '.');
                if ($settle['settled'] > 0) {
                    // Late cost: sales made at zero cost for this deficit get costed at this receipt's unit cost.
                    rh_stock_backcost_settled_deficit($pdo, $id, (float)$settle['deficit'], (float)$settle['settled'], $cost, (int)($user['id'] ?? 0) ?: null, 'manual receipt');
                    $batchNotes = trim(($batchNotes !== '' ? $batchNotes . ' | ' : '') . 'Settled ' . $settledTxt . ' unallocated deficit');
                }

                // Insert batch
                $bIns = $pdo->prepare("
                    INSERT INTO stock_batches
                        (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit,
                         supplier_id, supplier_name, supplier_contact, received_date, expiry_date, expiry_alert_days, status, notes, created_by)
                    VALUES (?, '', ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)
                ");
                $bIns->execute([
                    $id,
                    $qty,
                    $settle['remaining'],
                    $cost,
                    $supplierId ?: null,
                    $supplier ?: null,
                    $supplierContact ?: null,
                    $expiry !== '' ? $expiry : null,
                    $alertDays,
                    $settle['remaining'] < 0.0001 ? 'depleted' : 'active',
                    $batchNotes ?: null,
                    $user['id']
                ]);
                $batchId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE stock_batches SET batch_number = ? WHERE id = ?")
                    ->execute(['B' . str_pad((string)$batchId, 6, '0', STR_PAD_LEFT), $batchId]);

                // Stock-in log
                // Also records the item's category now and today's date (the stock budget's grouping and month; migration 069).
                $logCols = ['ingredient_id', 'batch_id', 'quantity', 'cost_per_unit', 'cost_total', 'supplier_id', 'supplier_name', 'supplier_contact',
                            'avg_cost_before', 'avg_cost_after', 'notes', 'created_by'];
                $logVals = [$id, $batchId, $qty, $cost, $qty * $cost, $supplierId ?: null, $supplier ?: null, $supplierContact ?: null,
                            $oldAvg, $newAvg, $batchNotes ?: null, $user['id']];
                if (rh_stock_in_log_dated_cols($pdo)) {
                    array_push($logCols, 'category', 'received_date');
                    array_push($logVals, trim((string)($ing['category'] ?? '')), date('Y-m-d'));
                }
                $pdo->prepare("INSERT INTO stock_in_log (" . implode(', ', $logCols) . ") VALUES (" . implode(', ', array_fill(0, count($logCols), '?')) . ")")
                    ->execute($logVals);

                // Update ingredient (qty + new weighted avg)
                $upd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ?, cost_per_unit = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$qty, $newAvg, $id]);

                // Stock adjustment row
                $adj = $pdo->prepare("
                    INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by)
                    VALUES (?, ?, ?, 'stock_in', ?, ?, ?)
                ");
                $adj->execute([$id, $qty, $settle['settled'] > 0 ? 'Stock received (batch; ' . $settledTxt . ' settled unallocated deficit)' : 'Stock received (batch)', $batchId, $cost, $user['id']]);

                $pdo->commit();
                $_SESSION['stock_in_last_fp'] = $stockInFp;
                $_SESSION['stock_in_last_at'] = time();
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
                    // Settle any unallocated deficit (sales beyond batches) first so batch sum == current_quantity.
                    $settle = rh_stock_receipt_settlement($pdo, $id, $currentQty, $qty);
                    if ($settle['settled'] > 0 && $costAtTime > 0) {
                        // Late cost: sales made at zero cost for this deficit get costed at the ingredient's cost (no cost = left at 0).
                        rh_stock_backcost_settled_deficit($pdo, $id, (float)$settle['deficit'], (float)$settle['settled'], $costAtTime, (int)($user['id'] ?? 0) ?: null, 'manual adjustment');
                    }
                    $batchIns->execute([
                        $id,
                        $tmpBatchNumber,
                        $qty,
                        $settle['remaining'],
                        max(0, $costAtTime),
                        mb_substr('Manual adjustment add: ' . $reason . ($settle['settled'] > 0 ? ' (settled ' . rtrim(rtrim(number_format($settle['settled'], 6, '.', ''), '0'), '.') . ' unallocated deficit)' : ''), 0, 500),
                        $user['id']
                    ]);

                    $batchId = (int)$pdo->lastInsertId();
                    if ($settle['remaining'] < 0.0001) {
                        $pdo->prepare("UPDATE stock_batches SET status = 'depleted' WHERE id = ?")->execute([$batchId]);
                    }
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

                    // Keep the FIFO batch cost deductStockBatchFIFO recorded; only label the row.
                    $pdo->prepare('UPDATE stock_adjustments SET reason = ? WHERE id = ?')
                        ->execute([$reasonShort, $adjId]);
                }

                $pdo->commit();

                $message = 'Manual adjustment recorded.';
            } elseif ($action === 'update_batch') {
                // Edit expiry / alert / notes / status on an existing batch.
                $batchId = (int)($_POST['batch_id'] ?? 0);
                $expiry  = trim($_POST['expiry_date'] ?? '');
                $alert   = max(0, (int)($_POST['expiry_alert_days'] ?? 7));
                $bnotes  = trim($_POST['notes'] ?? '');
                if ($batchId <= 0) throw new RuntimeException('Batch not found.');
                if ($expiry !== '') {
                    $expDt = DateTime::createFromFormat('Y-m-d', $expiry);
                    if (!$expDt || $expDt->format('Y-m-d') !== $expiry) {
                        throw new RuntimeException('Expiry date is not a valid date.');
                    }
                }
                if ($alert > 3650) throw new RuntimeException('Expiry alert days is too large.');
                $pdo->beginTransaction();
                $bSel = $pdo->prepare("SELECT id, status, expiry_date FROM stock_batches WHERE id = ? FOR UPDATE");
                $bSel->execute([$batchId]);
                $bRow = $bSel->fetch(PDO::FETCH_ASSOC);
                if (!$bRow) throw new RuntimeException('Batch not found.');
                $storedExpiry = substr((string)($bRow['expiry_date'] ?? ''), 0, 10);
                if ($expiry !== $storedExpiry) {
                    // Changing the expiry can trigger an automatic expiry write-off: same gate as Discard Batch.
                    if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
                        throw new RuntimeException('Changing a batch expiry date is restricted to managers and admins.');
                    }
                    if ($expiry !== '' && ($bRow['status'] ?? '') === 'active' && $expiry < date('Y-m-d')) {
                        throw new RuntimeException('Expiry date cannot be set in the past. To write this stock off, use Discard Batch instead.');
                    }
                }
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
                $pdo->commit();
                $message = 'Batch updated.';
            } elseif ($action === 'discard_batch') {
                // Mark a batch as wasted/expired/recalled and remove its remaining qty from stock.
                // Same gate as manual adjust: admin/manager only, reason of at least 8 characters.
                if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
                    throw new RuntimeException('Discarding a batch is restricted to managers and admins.');
                }
                $batchId = (int)($_POST['batch_id'] ?? 0);
                $reason  = mb_substr(trim($_POST['reason'] ?? ''), 0, 200);
                if (mb_strlen($reason) < 8) throw new RuntimeException('A reason of at least 8 characters is required to discard a batch.');
                $newStatus = $_POST['status'] ?? 'wasted';
                $allowed = ['expired', 'recalled', 'wasted'];
                if (!in_array($newStatus, $allowed, true)) $newStatus = 'wasted';

                $pdo->beginTransaction();
                // Lock order (every stock write path): the ingredient row before its batch.
                $lockIng = $pdo->prepare("SELECT ingredient_id FROM stock_batches WHERE id = ?");
                $lockIng->execute([$batchId]);
                $pdo->prepare("SELECT id FROM stock_ingredients WHERE id = ? FOR UPDATE")->execute([(int)$lockIng->fetchColumn()]);
                $sel = $pdo->prepare("SELECT ingredient_id, batch_number, quantity_remaining, cost_per_unit, status FROM stock_batches WHERE id = ? FOR UPDATE");
                $sel->execute([$batchId]);
                $b = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$b) throw new RuntimeException('Batch not found.');
                $remaining = (float)$b['quantity_remaining'];
                // Same guard as the Batches page: an empty or closed batch has nothing to discard (closing a used-up
                // batch would make a later void / return from it be written off instead of put back).
                if ($remaining <= 0.0001 || ($b['status'] ?? '') !== 'active') throw new RuntimeException('Batch is already empty or closed.');
                $ingredId = (int)$b['ingredient_id'];
                $cost = (float)$b['cost_per_unit'];

                if ($remaining > 0.0001) {
                    // Subtract exactly what the batch held (no silent clamp) so ledger == totals.
                    $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$remaining, $ingredId]);
                    // Zero out the batch
                    $pdo->prepare("UPDATE stock_batches SET quantity_remaining = 0, status = ?, notes = CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END, ?), updated_at = NOW() WHERE id = ?")
                        ->execute([$newStatus, '[' . date('Y-m-d') . '] ' . $newStatus . ': ' . $reason, $batchId]);
                    // Record adjustment under the appropriate enum so Theft Radar isn't polluted.
                    $adjType = $newStatus === 'expired' ? 'expiry' : ($newStatus === 'recalled' ? 'recall' : 'wastage');
                    // Mirror into stock_wastage (expired / wasted / recalled with a 'Recall:' prefix) so wastage
                    // reports include the write-off; 'wastage' adjustments key source_id to the wastage id.
                    $bn = (string)($b['batch_number'] ?? ('#' . $batchId));
                    $wReason = $newStatus === 'recalled' ? "Recall: {$reason} (batch {$bn})"
                        : ($newStatus === 'expired' ? "Expired batch {$bn}: {$reason}" : "{$reason} (batch {$bn})");
                    $wastageId = rh_insert_batch_wastage_row($pdo, $ingredId, $batchId, $remaining, $cost, $wReason, (int)$user['id']);
                    $pdo->prepare("
                        INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$ingredId, -$remaining, mb_substr("Batch {$newStatus}: {$reason}", 0, 255), $adjType, $adjType === 'wastage' ? $wastageId : $batchId, $cost, $user['id']]);
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
        if (function_exists('deleteCache')) rh_clear_stock_dashboard_cache();
    }
    if ($error)   $_SESSION['stock_err'] = $error;
    // A refused Add / Edit comes back with what was typed, so the form reopens instead of the
    // person starting again (the reason is shown inside the form).
    if ($error && in_array((string)($action ?? ''), ['add', 'update'], true)) {
        $keep = [];
        foreach (['id', 'name', 'category', 'unit', 'min_quantity', 'yield_percent', 'notes', 'reorder_point', 'par_level', 'preferred_supplier_id', 'lead_time_days', 'pack_name', 'pack_size', 'pieces_per_unit', 'measure_big_name', 'measure_small_name', 'measure_per_big', 'measure_stock_side', 'volume_ml'] as $keepField) {
            $keep[$keepField] = (string)($_POST[$keepField] ?? '');
        }
        $_SESSION['stock_ingredient_form_keep'] = ['action' => (string)$action, 'error' => $error, 'post' => $keep];
    }
    header('Location: stock-ingredients.php' . ($ingFilter !== '' ? '?filter=' . $ingFilter : '')); // back to the same list
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
$stockFormReopen = null; // set when the last Add / Edit was refused
$measureReady = rh_stock_measure_ready($pdo); // migration 088: count measures (bottle / shot)
$measureSizes = $measureReady ? rh_stock_measure_sizes() : ["shot_ml" => 0, "glass_ml" => 0];
$drinkReady = rh_stock_drink_ready($pdo); // migration 089: bottle size + the hotel's drink measures list
$drinkList = $drinkReady ? rh_drink_measures_list() : [];
try {
    $supplierOptions = $pdo->query("SELECT id, name FROM stock_suppliers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $supplierOptions = []; }
if (!$error || strpos($error, 'Stock tables not yet') === false) {
    try {
        $histSql = rh_stock_history_sql($pdo, 'i'); // shared "has stock history" rule
        $attentionSql = rh_stock_attention_sql(rh_stock_threshold_sql('i'), $histSql, 'i'); // the low-stock alert rule
        $stmt = $pdo->query("
            SELECT i.*,
                   (CASE WHEN {$histSql} THEN 0 ELSE 1 END) AS never_stocked,
                   (CASE WHEN {$attentionSql} THEN 1 ELSE 0 END) AS needs_attention,
                   (SELECT MIN(b.expiry_date) FROM stock_batches b WHERE b.ingredient_id = i.id AND b.status = 'active' AND b.expiry_date IS NOT NULL AND b.quantity_remaining > 0) AS next_expiry
            FROM stock_ingredients i
            ORDER BY i.is_archived ASC, i.category ASC, i.name ASC
        ");
        $ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Read-only flags for the screen, mirroring the server rules: the Unit is locked once an
        // ingredient has stock, batches, adjustments or recipes; Delete is only offered when it
        // has no stock and no history at all (everything else is archived).
        $ingUnitUsed = [];
        $ingHistoryUsed = [];
        foreach (['stock_recipe_ingredients', 'stock_batches', 'stock_adjustments', 'stock_in_log', 'stock_wastage', 'stock_purchase_order_items', 'stock_count_lines', 'stock_unit_changes'] as $usedTable) {
            try {
                foreach ($pdo->query("SELECT DISTINCT ingredient_id FROM `{$usedTable}`")->fetchAll(PDO::FETCH_COLUMN) as $usedId) {
                    $ingHistoryUsed[(int)$usedId] = true;
                    if (in_array($usedTable, ['stock_recipe_ingredients', 'stock_batches', 'stock_adjustments'], true)) {
                        $ingUnitUsed[(int)$usedId] = true;
                    }
                }
            } catch (Throwable $e) {
                // table not installed on this schema: no history there
            }
        }
        foreach ($ingredients as &$ingFlagRow) {
            $hasStockQty = abs((float)$ingFlagRow['current_quantity']) > 0.00005;
            $ingFlagRow['unit_locked'] = $hasStockQty || isset($ingUnitUsed[(int)$ingFlagRow['id']]);
            $ingFlagRow['has_history'] = $hasStockQty || isset($ingHistoryUsed[(int)$ingFlagRow['id']]);
        }
        unset($ingFlagRow);
        // Read-only "May be a case price" note under Avg cost / unit: the same judge the Import review uses (includes/import/case-signals.php),
        // worked out before the low-stock filter so similar items are compared with every item. Changes nothing.
        $caseCostFlags = [];
        try {
            require_once __DIR__ . '/../includes/import/case-signals.php';
            $caseCostFlags = import_cs_stock_flags($ingredients, import_cs_load_evidence($pdo));
        } catch (Throwable $e) {
            $caseCostFlags = [];
        }
        // Rebuild the refused Add / Edit form: the stored row (for the unit lock) with what was typed on top.
        if (!empty($_SESSION['stock_ingredient_form_keep']) && is_array($_SESSION['stock_ingredient_form_keep'])) {
            $keepForm = $_SESSION['stock_ingredient_form_keep'];
            unset($_SESSION['stock_ingredient_form_keep']);
            $keepData = (array)($keepForm['post'] ?? []);
            if (($keepForm['action'] ?? '') === 'update') {
                foreach ($ingredients as $ingKeepRow) {
                    if ((int)$ingKeepRow['id'] === (int)($keepData['id'] ?? 0)) {
                        $keepData['unit_locked'] = !empty($ingKeepRow['unit_locked']);
                        $keepData['cost_per_unit'] = (string)$ingKeepRow['cost_per_unit']; // for the cost-per-measure line
                        $keepData['unit'] = $keepData['unit_locked'] ? (string)$ingKeepRow['unit'] : $keepData['unit'];
                        break;
                    }
                }
            } else {
                unset($keepData['id']);
            }
            $stockFormReopen = ['data' => $keepData, 'error' => (string)($keepForm['error'] ?? '')];
        }
        if ($ingFilter === 'low') {
            // Applied after the flags and the refused-form rebuild above, which need every row.
            $ingredients = array_values(array_filter($ingredients, static fn(array $r): bool => (int)$r['needs_attention'] === 1));
        }
        foreach ($ingredients as $i) {
            if (!in_array($i['category'], $categories, true)) $categories[] = $i['category'];
        }
        sort($categories);

        // Export to Excel (CSV — opens directly in Excel). Mirrors the CSV export
        // convention used on stock-reports.php. Must run before any HTML output.
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $fname = ($ingFilter === 'low' ? 'stock-low-items-' : 'stock-management-') . date('Ymd-His') . '.csv';
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
    $min = (float)$i['reorder_point'] > 0 ? (float)$i['reorder_point'] : (float)$i['min_quantity']; // shared threshold rule
    if ((int)$i['is_archived'] === 1) return '<span class="badge badge-archived">Archived</span>';
    if ($cur <= 0 && !empty($i['never_stocked'])) return '<span class="badge badge-setup" title="Opening stock has not been recorded yet">Not stocked</span>';
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
            <h2 class="page-title"><i class="fas <?php echo $stockIsFood ? 'fa-carrot' : 'fa-boxes-stacked'; ?>" style="color:var(--color-primary,#766550);"></i> <?php echo $stockNounPl; ?></h2>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php if (!empty($ingredients)): ?>
                    <a class="btn-secondary" href="?<?php echo $ingFilter !== '' ? 'filter=' . $ingFilter . '&amp;' : ''; ?>export=csv" style="display:inline-flex; align-items:center; gap:6px; text-decoration:none;" title="<?php echo $ingFilter === 'low' ? 'Download low-stock ' . strtolower($stockNounPl) . ' only (this filtered list)' : 'Download all ' . strtolower($stockNounPl); ?> as an Excel-compatible spreadsheet"><i class="fas fa-file-excel"></i> <?php echo $ingFilter === 'low' ? 'Export low-stock items' : 'Export to Excel'; ?></a>
                <?php endif; ?>
                <?php if ($drinkReady): ?>
                    <button type="button" class="btn-secondary" onclick="openModal('drinkMeasuresModal')" title="The hotel's list of drink measures (shot, glass of wine...) and their size in ml, used to type recipes and counts in measures"><i class="fas fa-wine-glass"></i> Drink measures</button>
                <?php endif; ?>
                <button class="btn-add" onclick="openIngredientModal()"><i class="fas fa-plus"></i> Add <?php echo $stockNoun; ?></button>
            </div>
        </div>

        <?php if ($message): showAlert(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), 'success'); // the toast shows HTML; item names are staff-typed
        endif; ?>
        <?php if ($error):   showAlert(htmlspecialchars($error, ENT_QUOTES, 'UTF-8'),   'error');
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

        <?php if ($ingFilter === 'low'): $lowListCount = count($ingredients); ?>
            <div class="stock-filter-notice" style="background:#eef3ff;border:1px solid #cfd8ff;border-radius:10px;padding:12px 14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:8px;color:#1f2d6b;">
                    <i class="fas fa-filter"></i>
                    <strong>Filtered list</strong>
                </div>
                <div style="color:#2f3a63;font-size:13px;flex:1 1 320px;">
                    Showing <?php echo number_format($lowListCount); ?> <?php echo $lowListCount === 1 ? $stockNounLow : strtolower($stockNounPl); ?> that need restocking: out of stock, or at or below the low-stock line (reorder point, else minimum). The same rule and count as the dashboard's low-stock alert; archived items and items whose opening stock was never recorded are left out.
                    The search, category and status boxes narrow this list further.
                    <a href="stock-ingredients.php" style="margin-left:8px;color:#1f2d6b;font-weight:600;">Clear</a>
                </div>
            </div>
        <?php endif; ?>

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
                <option value="unstocked">Not stocked (opening stock not recorded)</option>
                <option value="expiring">Expiring soon</option>
                <option value="archived">Archived</option>
            </select>
            <span id="filter-count" data-total="<?php echo count($ingredients); ?>" style="color:#6c757d;font-size:13px;"><?php echo count($ingredients); ?> ingredient(s)</span>
        </div>

        <div class="table-responsive" data-rh-results>
            <table class="stock-table" id="stock-table">
                <thead>
                    <tr>
                        <th>Name <i class="help" data-tip="The ingredient's display name. Used everywhere — recipes, stock-in logs, wastage reports.">?</i></th>
                        <th class="col-optional-lg">Category <i class="help" data-tip="Free-text grouping (e.g. Dairy, Meat, Pantry). Used to keep the recipe-builder dropdown tidy.">?</i></th>
                        <th class="col-optional-md">Unit <i class="help" data-tip="Base unit of measure for this ingredient. All stock-in receipts, recipe quantities, and adjustments use this same unit. Pick once and stick to it.">?</i></th>
                        <th style="text-align:right;">Current <i class="help" data-tip="How much you have on hand right now (sum of all active batches). Updated automatically when you receive stock or when a recipe is sold/wasted.">?</i></th>
                        <th class="col-optional-lg" style="text-align:right;">Min <i class="help" data-tip="The low-stock line: the reorder point if you set one, otherwise the minimum quantity. At or below it the row turns yellow (Low) and shows up in reorder reports.">?</i></th>
                        <th class="col-optional-lg" style="text-align:right;">Yield % <i class="help" data-tip="The part of what you buy that ends up on the plate after peeling, trimming, bones or cooking. Example: 10 kg of potatoes gives 8 kg once peeled = 80 %. A plate with 250 g of chips then takes 313 g of raw potato off stock (250 ÷ 0.80). 100 % = nothing thrown away (oil, sugar, bottled drinks).">?</i></th>
                        <th style="text-align:right;">Avg cost / unit <i class="help" data-tip="Weighted-average cost across all batches received. Recalculated automatically every time you stock-in at a different price. This drives recipe food-cost %. A &quot;May be a case price&quot; note appears when this hotel's own data suggests the cost is for a whole case: the menu selling price of a linked recipe, its delivery costs, its pack size, or most items of the same category and unit costing far less. The note changes nothing; its ? says what it compared.">?</i></th>
                        <th class="col-optional-lg">Next expiry <i class="help" data-tip="The earliest expiry date among all active batches of this ingredient. Click Batches to see/edit each batch individually. Green = plenty of time, yellow = within your alert window, red = ≤ 3 days or already expired.">?</i></th>
                        <th>Status <i class="help" data-tip="OK · Low (≤ min) · Critical (zero or below) · Archived (hidden from recipes).">?</i></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ingredients as $i):
                        $cur = (float)$i['current_quantity'];
                        $min = (float)$i['reorder_point'] > 0 ? (float)$i['reorder_point'] : (float)$i['min_quantity']; // shared threshold rule
                        $neverStocked = ($cur <= 0 && !empty($i['never_stocked']));
                        // Days until next expiry (null if no batch with expiry)
                        $expDays = null;
                        if (!empty($i['next_expiry'])) {
                            $expDays = (int)(new DateTime('today'))->diff(new DateTime($i['next_expiry']))->format('%r%a');
                        }
                        $isExpiringSoon = ($expDays !== null && $expDays >= 0 && $expDays <= 7);
                        $isExpired      = ($expDays !== null && $expDays < 0);
                        $rowStatus = ((int)$i['is_archived'] === 1) ? 'archived'
                            : ($neverStocked ? 'unstocked'
                            : ($cur <= 0     ? 'critical'
                            : ($isExpired    ? 'critical'
                            : ($min > 0 && $cur <= $min ? 'low'
                            : ($isExpiringSoon ? 'expiring' : 'ok')))));
                        $qtyClass = $neverStocked ? '' : ($cur <= 0 ? 'qty-critical' : ($min > 0 && $cur <= $min ? 'qty-low' : ''));

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
                            <td class="col-optional-lg"><?php echo htmlspecialchars($i['category']); ?></td>
                            <td class="col-optional-md"><?php echo htmlspecialchars($i['unit']); ?></td>
                            <td class="qty-num <?php echo $qtyClass; ?>" style="text-align:right;"><?php echo number_format($cur, 3); ?></td>
                            <td class="qty-num col-optional-lg" style="text-align:right;"><?php echo number_format($min, 3); ?></td>
                            <td class="qty-num col-optional-lg" style="text-align:right;"><?php echo number_format((float)$i['yield_percent'], 1); ?></td>
                            <td class="qty-num" style="text-align:right;"><?php echo $currency_symbol . ' ' . number_format((float)$i['cost_per_unit'], 2); ?>
                                <?php if (isset($caseCostFlags[(int)$i['id']])): ?>
                                    <div class="cost-case-note"><span class="badge badge-warning">May be a case price</span> <i class="help" data-tip="<?php echo htmlspecialchars($caseCostFlags[(int)$i['id']]['reason'] . ' Nothing has been changed. If it is a case price, correct it on Stock › Fix crate prices: type how many units are in one crate or case and the cost is divided by that number.'); ?>">?</i> <a class="cost-case-fix" style="display:inline-flex;align-items:center;min-height:44px;" href="stock-cost-fix.php?item=<?php echo (int)$i['id']; ?>">Fix crate prices</a></div>
                                <?php endif; ?>
                            </td>
                            <td class="col-optional-lg">
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
                                        <button class="btn-adjust" onclick='openAdjustModal(<?php echo (int)$i['id']; ?>, <?php echo json_encode($i['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo json_encode($i['unit'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'>
                                            <i class="fas fa-balance-scale"></i> Adjust
                                        </button>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="unarchive">
                                            <input type="hidden" name="id" value="<?php echo (int)$i['id']; ?>">
                                            <button><i class="fas fa-undo"></i> Restore</button>
                                        </form>
                                        <button onclick='openIngredientModal(<?php echo json_encode($i, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'>
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                    <?php endif; ?>
                                    <?php /* Stock In + Adjust (or Restore + Edit) stay on the row; the rest is in the shared More menu, Delete last. */ ?>
                                    <?php $canDeleteIng = $i['is_archived'] && empty($i['has_history']); ?>
                                    <?php if (!$i['is_archived'] || $canDeleteIng): ?>
                                    <div class="actions-more">
                                        <button type="button" class="actions-more-toggle" title="More actions" aria-label="More actions" aria-haspopup="true" onclick="toggleIngredientMore(this, event)">
                                            <i class="fas fa-ellipsis-vertical"></i> More
                                        </button>
                                        <div class="actions-more-menu">
                                            <?php if (!$i['is_archived']): ?>
                                                <button type="button" onclick='closeIngredientMenus(); openBatchesModal(<?php echo (int)$i['id']; ?>, <?php echo json_encode($i['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo json_encode($i['unit'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)' title="View &amp; manage batches / expiry dates">
                                                    <i class="fas fa-boxes-stacked"></i> Batches<?php if ($hasBatches): ?> (<?php echo count($batchesByIngredient[(int)$i['id']]); ?>)<?php endif; ?>
                                                </button>
                                                <button type="button" onclick='closeIngredientMenus(); openIngredientModal(<?php echo json_encode($i, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'>
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <form method="POST" onsubmit="return confirm('Archive this ingredient?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="action" value="archive">
                                                    <input type="hidden" name="id" value="<?php echo (int)$i['id']; ?>">
                                                    <button class="btn-danger"><i class="fas fa-archive"></i> Archive</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" onsubmit="return confirm('Permanently delete? This cannot be undone.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int)$i['id']; ?>">
                                                    <button class="btn-danger"><i class="fas fa-trash"></i> Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($i['is_archived'] && !empty($i['has_history'])): ?>
                                        <span class="row-note" title="Has stock or movement history, so it can only be archived.">Kept for history</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ingredients) && $ingFilter === 'low'): ?>
                        <tr>
                            <td colspan="10" style="text-align:center; padding:30px; color:#6c757d;">Nothing needs restocking right now. <a href="stock-ingredients.php">Show all <?php echo strtolower($stockNounPl); ?></a></td>
                        </tr>
                    <?php elseif (empty($ingredients)): ?>
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
            <div class="modal-header">
                <h3 id="ingredientModalTitle">Add <?php echo $stockNoun; ?></h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('ingredientModal')">&times;</button>
            </div>
            <form method="POST" id="ingredientForm" data-busy-text="Saving...">
                <div class="modal-body">
                <div class="rh-form-error" id="ing_form_error" role="alert" hidden></div>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" id="ing_action" value="add">
                <input type="hidden" name="id" id="ing_id" value="">
                <fieldset class="ing-section">
                    <legend>Basic details</legend>
                    <div class="ing-grid">
                        <div class="ing-field">
                            <label for="ing_name">Name * <i class="help" data-tip="The name shown in dropdowns and reports. Be specific (e.g. 'Chicken breast (boneless)' not just 'Chicken').">?</i></label>
                            <input type="text" name="name" id="ing_name" required maxlength="200">
                        </div>
                        <div class="ing-field">
                            <label for="ing_category">Category <i class="help" data-tip="<?php echo $stockIsFood ? 'Used to group ingredients in the recipe-builder dropdown. Pick consistent names like Dairy, Meat, Pantry, Vegetables, Beverages.' : 'Used to group stock items in lists and reports. Pick consistent names like Groceries, Beverages, Household.'; ?>">?</i></label>
                            <input type="text" name="category" id="ing_category" placeholder="<?php echo $stockIsFood ? 'e.g. Dairy, Meat, Produce' : 'e.g. Groceries, Beverages, Household'; ?>" maxlength="100">
                        </div>
                        <div class="ing-field ing-span">
                            <label for="ing_notes">Notes <i class="help" data-tip="Internal notes — e.g. preferred supplier, preparation tips. Shown under the name in the table.">?</i></label>
                            <input type="text" name="notes" id="ing_notes" maxlength="500">
                        </div>
                    </div>
                </fieldset>
                <fieldset class="ing-section">
                    <legend>Units and packs</legend>
                    <div class="ing-grid">
                        <div class="ing-field">
                            <label for="ing_unit">Unit * <i class="help" data-tip="<?php echo $stockIsFood ? 'The base unit for ALL stock movements of this ingredient — receipts, recipe quantities, wastage. Choose carefully; changing it later breaks existing recipes.' : 'The base unit for ALL stock movements of this item — receipts, sales deductions, wastage. Retail products normally use Pieces (pcs).'; ?>">?</i></label>
                            <select name="unit" id="ing_unit" required>
                                <option value="g">Grams (g)</option>
                                <option value="kg">Kilograms (kg)</option>
                                <option value="ml">Millilitres (ml)</option>
                                <option value="L">Litres (L)</option>
                                <option value="pcs">Pieces (pcs)</option>
                                <option value="packet">Packets</option>
                                <option value="box">Boxes</option>
                                <option value="dozen">Dozens (12)</option>
                                <option value="slice">Slices</option>
                                <option value="tsp">Teaspoons (tsp)</option>
                                <option value="tbsp">Tablespoons (tbsp)</option>
                            </select>
                            <?php /* Locked units are not submitted by a disabled select, so a hidden mirror carries the value. */ ?>
                            <input type="hidden" name="unit" id="ing_unit_keep" value="" disabled>
                        </div>
                        <div class="ing-field">
                            <label for="ing_yield">Default yield % <i class="help" data-tip="The part of the raw item that ends up on the plate after peeling, trimming, bones or cooking. To find it, weigh the item before prep and again after: after ÷ before × 100. Put the same figure in Yield % on the recipe lines that use it.">?</i></label>
                            <input type="number" name="yield_percent" id="ing_yield" step="0.1" min="0.1" max="100" value="100">
                        </div>
                        <div class="ing-span">
                            <p class="field-note" id="ing_unit_note" hidden><i class="fas fa-lock"></i> Create a new ingredient to change unit. This one has stock, batches, adjustments or recipes in its current unit.</p>
                            <div class="yield-explainer">
                                <strong>What yield means:</strong> how much of what you buy actually ends up on the plate, after peeling, trimming, bones or cooking.<br><strong>Example, chips:</strong> you peel a 10 kg sack of potatoes and are left with 8 kg. That is <strong>80 %</strong> yield (8 ÷ 10). A plate of chips has 250 g of potato, so each plate takes <strong>313 g of raw potato</strong> off stock (250 ÷ 0.80) and is costed on 313 g. The sack makes <strong>32 plates, not 40</strong>.<br><strong>Find your own:</strong> weigh before prep, weigh after, then after ÷ before. In the recipe, type what goes on the plate; the system adds the loss. Use <strong>100 %</strong> when nothing is thrown away (oil, sugar, bottled and canned drinks). Rice and pasta get heavier when cooked: type the dry amount and keep 100 %.
                            </div>
                        </div>
                        <div class="ing-field">
                            <label for="ing_pack_name">Bought in packs? Pack name <i class="help" data-tip="Optional. If you buy this item by the crate, case, tray or box, enter the name here and how many single units are inside. Stock, counts and recipes stay in the unit above; purchase orders and buying suggestions can then use whole packs.">?</i></label>
                            <input type="text" name="pack_name" id="ing_pack_name" maxlength="30" placeholder="e.g. crate, case, tray, box">
                        </div>
                        <div class="ing-field">
                            <label for="ing_pack_size">Units in one pack <i class="help" data-tip="How many of the stock unit are in one pack, e.g. 24 for a crate of 24 bottles. Leave blank if you do not buy this item in packs.">?</i></label>
                            <input type="number" name="pack_size" id="ing_pack_size" step="0.001" min="0" placeholder="e.g. 24">
                        </div>
                        <div class="ing-field" id="ing_pieces_row" hidden>
                            <label id="ing_pieces_lbl">Pieces in one packet <i class="help" data-tip="Optional. How many single pieces are inside one of this unit, e.g. 6 sausages in a packet. Menu Management then lets staff type whole pieces (1 sausage) and works out the part of the packet. A dozen is always 12.">?</i></label>
                            <input type="number" name="pieces_per_unit" id="ing_pieces" step="any" min="0.01" placeholder="e.g. 6">
                        </div>
                    </div>
                </fieldset>
                <fieldset class="ing-section">
                    <legend>Stock levels and supplier</legend>
                    <div class="ing-grid ing-grid-3">
                        <div class="ing-field">
                            <label for="ing_min">Min quantity (low-stock threshold) <i class="help" data-tip="When current stock drops to or below this number, the <?php echo $stockNounLow; ?> turns yellow on the dashboard and appears in reorder reports. Leave at 0 if you don't want low-stock alerts.">?</i></label>
                            <input type="number" name="min_quantity" id="ing_min" step="0.001" min="0" value="0">
                        </div>
                        <div class="ing-field">
                            <label for="ing_reorder">Reorder point <i class="help" data-tip="When stock hits this level, the item appears in the Reorder / Buying report. Usually set to cover your supplier's lead time. Falls back to Min quantity if left at 0.">?</i></label>
                            <input type="number" name="reorder_point" id="ing_reorder" step="0.001" min="0" value="0">
                        </div>
                        <div class="ing-field">
                            <label for="ing_par">Par level (order up to) <i class="help" data-tip="Target stock level to top up to when reordering. Suggested order = Par − on hand − on order.">?</i></label>
                            <input type="number" name="par_level" id="ing_par" step="0.001" min="0" value="0">
                        </div>
                    </div>
                    <div class="ing-grid">
                        <div class="ing-field">
                            <label for="ing_supplier">Preferred supplier <i class="help" data-tip="Default supplier used to group this item on the Reorder report and pre-fill purchase orders.">?</i></label>
                            <select name="preferred_supplier_id" id="ing_supplier">
                                <option value="0">— None —</option>
                                <?php foreach ($supplierOptions as $so): ?>
                                    <option value="<?php echo (int)$so['id']; ?>"><?php echo htmlspecialchars($so['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ing-field">
                            <label for="ing_lead">Supplier lead time (days) <i class="help" data-tip="Typical days from placing an order to delivery. Used to prioritise urgent reorders.">?</i></label>
                            <input type="number" name="lead_time_days" id="ing_lead" min="0" max="365" value="0">
                        </div>
                    </div>
                </fieldset>
                <?php if ($drinkReady): ?>
                <fieldset class="ing-section ing-measure" id="ing_drink">
                    <legend>Bottle size and drink measures (optional) <i class="help" data-tip="For drinks. With the size of one bottle, recipes can be typed as 2 shots or 50 ml and are worked out into the stock unit. Stock, cost and sales stay in the unit above. Sizes of a shot, glass and so on come from the Drink measures list.">?</i></legend>
                    <input type="hidden" name="volume_present" value="1">
                    <div class="ing-grid" id="ing_vol_row">
                        <div>
                            <label for="ing_vol" id="ing_vol_lbl">Size of one bottle in ml <i class="help" data-tip="How many ml one of the stock unit holds, e.g. 1000 for a 1 litre bottle or 750 for a 75 cl bottle. Leave empty when the name already says it (Gin 750ml).">?</i></label>
                            <input type="number" name="volume_ml" id="ing_vol" step="any" min="0" max="100000" placeholder="e.g. 750">
                        </div>
                    </div>
                    <p class="field-note" id="ing_drink_line"></p>
                    <div class="ing-drink-pick" id="ing_drink_pick_row" hidden>
                        <label for="ing_drink_pick">Show it in <i class="help" data-tip="Pick a measure from the hotel's list to see how many are in one bottle and what one costs. Nothing is saved from here.">?</i></label>
                        <select id="ing_drink_pick" aria-label="Measure to show"></select>
                        <?php if ($measureReady): ?>
                            <button type="button" class="btn-secondary" id="ing_drink_use" onclick="ingDrinkUse()" title="Fills the count measure below (smaller measure and how many in one bigger). Check it, then press Save.">Use this for counting</button>
                        <?php endif; ?>
                    </div>
                </fieldset>
                <?php endif; ?>
                <?php if ($measureReady): ?>
                <fieldset class="ing-section ing-measure" id="ing_measure">
                    <legend>Count in a smaller or bigger measure (optional) <i class="help" data-tip="For bar stock counted by the shot or glass. Stock, prices and sales stay in the unit above; a stock count can then be typed in either measure or both, e.g. 2 bottles + 7 shots, and is worked out into the stock unit. Clear all four fields to remove it.">?</i></legend>
                    <input type="hidden" name="measure_present" value="1">
                    <div class="ing-grid">
                        <div>
                            <label for="ing_m_big">Bigger measure <i class="help" data-tip="The whole container, e.g. bottle, jug, box.">?</i></label>
                            <input type="text" name="measure_big_name" id="ing_m_big" maxlength="30" placeholder="e.g. bottle" oninput="ingMeasureSync()">
                        </div>
                        <div>
                            <label for="ing_m_small">Smaller measure <i class="help" data-tip="What is poured or served from it, e.g. shot, tot, glass.">?</i></label>
                            <input type="text" name="measure_small_name" id="ing_m_small" maxlength="30" placeholder="e.g. shot" oninput="ingMeasureSync()">
                        </div>
                        <div>
                            <label for="ing_m_n" id="ing_m_n_lbl">How many smaller in one bigger <i class="help" data-tip="How many of the smaller measure make one of the bigger, e.g. 30 shots of 25 ml in a 750 ml bottle. 1 to <?php echo (int)RH_STOCK_MEASURE_MAX_N; ?>.">?</i></label>
                            <input type="number" name="measure_per_big" id="ing_m_n" step="any" min="1" max="<?php echo (int)RH_STOCK_MEASURE_MAX_N; ?>" placeholder="e.g. 30" oninput="ingMeasureSync()">
                        </div>
                        <div>
                            <label for="ing_m_side">Which one is the stock unit <i class="help" data-tip="The measure that IS the unit above. Normally the bigger one (stock kept in bottles, counted in shots).">?</i></label>
                            <select name="measure_stock_side" id="ing_m_side" onchange="this.dataset.touched = '1'; ingMeasureSync()">
                                <option value="big">The bigger measure</option>
                                <option value="small">The smaller measure</option>
                            </select>
                        </div>
                    </div>
                    <p class="field-note" id="ing_m_hint" hidden></p>
                    <div class="ing-measure-suggest" id="ing_m_suggest" hidden>
                        <p class="field-note" id="ing_m_suggest_txt"></p>
                        <button type="button" class="btn-secondary" id="ing_m_use_shot" onclick="ingMeasureUse('shot')">Use shots</button>
                        <button type="button" class="btn-secondary" id="ing_m_use_glass" onclick="ingMeasureUse('glass')">Use glasses</button>
                    </div>
                    <button type="button" class="btn-secondary" onclick="ingMeasureClear()">Clear measure</button>
                    <?php if ($canChangeUnit): ?>
                    <div class="ing-unit-change" id="ing_uc" hidden>
                        <p class="field-note" id="ing_uc_txt"></p>
                        <button type="button" class="btn-secondary" id="ing_uc_btn" onclick="ucOpen()">Change main unit</button>
                    </div>
                    <?php endif; ?>
                </fieldset>
                <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('ingredientModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Stock-In Modal -->
    <div class="modal-overlay" id="stockInModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Stock In: <span id="si_name"></span></h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('stockInModal')">&times;</button>
            </div>
            <form method="POST" id="stockInForm" onsubmit="return confirmStockIn(this);">
                <div class="modal-body">
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('stockInModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Receive Stock</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Adjust Modal -->
    <div class="modal-overlay" id="adjustModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Manual Adjustment: <span id="adj_name"></span></h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('adjustModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('adjustModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Batches Modal -->
    <div class="modal-overlay" id="batchesModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-boxes-stacked"></i>
                    Batches: <span id="bm_name"></span>
                    <span style="font-weight:400; font-size:13px;">(unit: <span id="bm_unit"></span>)</span>
                </h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('batchesModal')">&times;</button>
            </div>
            <div class="modal-body">
            <p class="si-batch-note">
                Each batch tracks its own <strong>expiry date</strong>, <strong>supplier</strong>, and <strong>quantity remaining</strong>.
                Edit a batch to fix a wrong expiry date or notes. Use <strong>Discard</strong> to write off expired/recalled stock — that automatically subtracts the remaining quantity from total stock.
            </p>

            <div id="bm_table_wrap"></div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('batchesModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Single Batch Modal -->
    <div class="modal-overlay" id="editBatchModal">
        <div class="modal-content" style="max-width:520px;">
            <div class="modal-header">
                <h3>Edit Batch <span id="eb_batchno"></span></h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('editBatchModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('editBatchModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Save Batch</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Discard Batch Modal -->
    <div class="modal-overlay" id="discardBatchModal">
        <div class="modal-content" style="max-width:480px;">
            <div class="modal-header">
                <h3><i class="fas fa-trash-can"></i> Discard Batch <span id="db_batchno"></span></h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('discardBatchModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return confirm('This will subtract the remaining quantity from your total stock and close the batch. Continue?');">
                <div class="modal-body">
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('discardBatchModal')">Cancel</button>
                    <button type="submit" class="btn-danger">Discard Batch</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($drinkReady): ?>
    <!-- Drink measures (Step U2): the hotel's one list of measures and their size in ml -->
    <div class="modal-overlay" id="drinkMeasuresModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Drink measures</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('drinkMeasuresModal')">&times;</button>
            </div>
            <form method="POST" id="drinkMeasuresForm" data-busy-text="Saving...">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="save_drink_measures">
                    <p class="field-note">One list for the whole hotel. In Menu Management and Recipes a drink can then be typed as 2 shots or 1 glass of wine, and it is worked out into the stock unit when saved. Changing a size here does not change stock, cost or recipe amounts already saved. ml can always be used, so it is not in the list.</p>
                    <div class="dm-list" role="group" aria-label="Drink measures">
                        <div class="dm-row dm-head">
                            <span>Measure <i class="help" data-tip="The name staff pick, e.g. shot, tot, double, glass of wine, cocktail pour. Up to 30 characters.">?</i></span>
                            <span>Size in ml <i class="help" data-tip="How many ml one of this measure holds, e.g. 25 or 30 for a shot, 175 for a glass of wine. Above 0, at most <?php echo number_format(RH_DRINK_MEASURE_MAX_ML); ?>.">?</i></span>
                            <span></span>
                        </div>
                        <div id="dm_rows">
                            <?php foreach ($drinkList as $dm): ?>
                                <div class="dm-row">
                                    <input type="text" name="measure_name[]" maxlength="30" value="<?php echo htmlspecialchars($dm['name']); ?>" aria-label="Measure name">
                                    <input type="number" name="measure_ml[]" step="any" min="0.01" max="<?php echo (int)RH_DRINK_MEASURE_MAX_ML; ?>" value="<?php echo htmlspecialchars(rh_stock_qty_fmt((float)$dm['ml'], 0, '')); ?>" aria-label="Size in ml">
                                    <button type="button" class="btn-secondary dm-remove" onclick="dmRemoveRow(this)" aria-label="Remove this measure">Remove</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="button" class="btn-secondary dm-add" onclick="dmAddRow()"><i class="fas fa-plus"></i> Add a measure</button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('drinkMeasuresModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Save measures</button>
                </div>
            </form>
        </div>
    </div>
    <script src="js/stock-drink-measures.js?v=<?php echo @filemtime(__DIR__ . '/js/stock-drink-measures.js'); ?>"></script>
    <?php endif; ?>

    <?php if ($canChangeUnit): ?>
    <!-- Change main unit (Step U3): preview first, nothing is saved until Confirm. -->
    <div class="modal-overlay" id="unitChangeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Change main unit: <span id="uc_name"></span></h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('unitChangeModal')">&times;</button>
            </div>
            <form method="POST" id="unitChangeForm" onsubmit="if (document.getElementById('uc_fp').value === '') return false; setTimeout(function () { document.getElementById('uc_confirm').disabled = true; }, 0); return true;">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="unit_change_apply">
                    <input type="hidden" name="id" id="uc_id" value="">
                    <input type="hidden" name="fingerprint" id="uc_fp" value="">
                    <p class="field-note" id="uc_loading">Working out the preview...</p>
                    <div id="uc_body" hidden>
                        <p class="uc-summary" id="uc_summary"></p>
                        <p class="field-note">Stock, batches, recipe amounts, minimum / reorder / par levels, open purchase orders and counts that are not approved yet are converted. Approved counts and past movements stay as they were recorded. Nothing changes until you press Confirm.</p>
                        <div class="uc-value"><span id="uc_value"></span> <i class="help" data-tip="Stock value = quantity on hand x cost of one, worked out over the open batches. It must be the same before and after; if it would differ by more than 0.01 the change is refused.">?</i></div>
                        <div class="uc-lines" id="uc_lines" role="table" aria-label="What changes">
                            <div class="uc-line uc-head" role="row"><span role="columnheader">Place <i class="help" data-tip="Where this item's amount is kept: stock on hand, each batch, recipes, open purchase orders and counts not approved yet.">?</i></span><span role="columnheader">Now <i class="help" data-tip="The amount as it is stored today, in the current main unit.">?</i></span><span role="columnheader">After the change <i class="help" data-tip="The same amount written in the new main unit. Prices per unit change the other way, so values stay the same.">?</i></span></div>
                        </div>
                    </div>
                    <div class="uc-errors" id="uc_errors" role="alert" hidden></div>
                    <div class="uc-history" id="uc_history_wrap" hidden>
                        <h4>Earlier main-unit changes</h4>
                        <ul id="uc_history"></ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('unitChangeModal')">Cancel</button>
                    <button type="submit" class="btn-primary" id="uc_confirm" disabled>Confirm</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <?php require_once 'includes/admin-footer.php'; ?>
    <script src="js/admin-form-guard.js?v=<?php echo @filemtime(__DIR__ . '/js/admin-form-guard.js'); ?>"></script>
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

        // More-actions menu (shared placement from admin-components.js)
        function closeIngredientMenus() {
            document.querySelectorAll('.row-actions .actions-more.open').forEach(function (el) {
                el.classList.remove('open');
                var m = el.querySelector('.actions-more-menu');
                if (m) m.removeAttribute('style');
            });
        }

        function toggleIngredientMore(btn, e) {
            if (e) e.stopPropagation();
            var wrap = btn.closest('.actions-more');
            var menu = wrap ? wrap.querySelector('.actions-more-menu') : null;
            if (!menu) return;
            var wasOpen = wrap.classList.contains('open');
            closeIngredientMenus();
            if (wasOpen) return;
            wrap.classList.add('open');
            if (window.rhPlaceActionMenu) window.rhPlaceActionMenu(btn, menu);
        }
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.actions-more')) closeIngredientMenus();
        });
        document.addEventListener('scroll', function (e) {
            if (!(e.target && e.target.closest && e.target.closest('.actions-more-menu'))) closeIngredientMenus();
        }, true);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeIngredientMenus();
        });

        function openIngredientModal(data) {
            document.getElementById('ingredientForm').reset();
            if (window.RHFormGuard) RHFormGuard.clearErrors(document.getElementById('ingredientForm'));
            if (data) {
                // data.id is missing when a refused Add is being reopened with what was typed.
                const isEdit = !!data.id;
                document.getElementById('ingredientModalTitle').textContent = (isEdit ? 'Edit ' : 'Add ') + '<?php echo $stockNoun; ?>';
                document.getElementById('ing_action').value = isEdit ? 'update' : 'add';
                document.getElementById('ing_id').value = data.id || '';
                document.getElementById('ing_name').value = data.name || '';
                document.getElementById('ing_category').value = data.category || '';
                (function () {
                    // A unit typed elsewhere (e.g. bottle, box) that is not in the list is added so it is not lost.
                    const us = document.getElementById('ing_unit');
                    const want = data.unit || 'g';
                    if (!Array.prototype.some.call(us.options, function (o) { return o.value === want; })) {
                        const o = document.createElement('option'); o.value = want; o.textContent = want; us.appendChild(o);
                    }
                    us.value = want;
                })();
                setIngredientUnitLock(!!data.unit_locked, data.unit || 'g');
                document.getElementById('ing_min').value = data.min_quantity || 0;
                document.getElementById('ing_yield').value = data.yield_percent || 100;
                document.getElementById('ing_notes').value = data.notes || '';
                document.getElementById('ing_reorder').value = data.reorder_point || 0;
                document.getElementById('ing_par').value = data.par_level || 0;
                document.getElementById('ing_lead').value = data.lead_time_days || 0;
                document.getElementById('ing_supplier').value = data.preferred_supplier_id || 0;
                document.getElementById('ing_pack_name').value = data.pack_name || '';
                document.getElementById('ing_pack_size').value = data.pack_size ? parseFloat(data.pack_size) : '';
                document.getElementById('ing_pieces').value = data.pieces_per_unit ? parseFloat(data.pieces_per_unit) : '';
                if (document.getElementById('ing_measure')) {
                    document.getElementById('ing_m_big').value = data.measure_big_name || '';
                    document.getElementById('ing_m_small').value = data.measure_small_name || '';
                    document.getElementById('ing_m_n').value = data.measure_per_big ? parseFloat(data.measure_per_big) : '';
                    document.getElementById('ing_m_side').value = data.measure_stock_side === 'small' ? 'small' : 'big';
                    document.getElementById('ing_m_side').dataset.touched = data.measure_stock_side ? '1' : '';
                }
            } else {
                document.getElementById('ingredientModalTitle').textContent = 'Add <?php echo $stockNoun; ?>';
                document.getElementById('ing_action').value = 'add';
                document.getElementById('ing_id').value = '';
                setIngredientUnitLock(false, 'g');
            }
            ingredientPiecesSync();
            if (document.getElementById('ing_measure')) {
                if (!data) document.getElementById('ing_m_side').dataset.touched = '';
                ingMeasureSync();
            }
            if (document.getElementById('ing_drink')) {
                document.getElementById('ing_vol').value = data && parseFloat(data.volume_ml) > 0 ? parseFloat(data.volume_ml) : '';
                ingDrinkCost = data ? (parseFloat(data.cost_per_unit) || 0) : 0;
                ingDrinkSync();
            }
            ucSync(data && data.id ? data : null);
            openModal('ingredientModal');
        }

        /* Change main unit (Step U3): offered on a saved item with a saved bottle / shot measure. The preview and the
         * change are done by the server; this only shows them. */
        let ucItemId = 0;
        function ucSync(data) {
            const box = document.getElementById('ing_uc');
            if (!box) return;
            const n = data ? parseFloat(data.measure_per_big) : 0;
            const big = data ? String(data.measure_big_name || '') : '';
            const small = data ? String(data.measure_small_name || '') : '';
            if (!data || !(n > 1) || big === '' || small === '') {
                ucItemId = 0;
                box.hidden = true;
                return;
            }
            ucItemId = parseInt(data.id, 10) || 0;
            const onSmall = data.measure_stock_side === 'small';
            document.getElementById('ing_uc_txt').textContent = 'Stock is kept in ' + (onSmall ? small : big) + '. To keep it in '
                + (onSmall ? big : small) + ' instead and convert stock, batches, recipes and open orders, use Change main unit. '
                + 'You will see a preview first. Edits above that are not saved yet are dropped.';
            box.hidden = !(ucItemId > 0);
        }

        function ucOpen() {
            if (!(ucItemId > 0)) return;
            const id = ucItemId;
            closeModal('ingredientModal');
            document.getElementById('uc_id').value = id;
            document.getElementById('uc_fp').value = '';
            document.getElementById('uc_name').textContent = '';
            document.getElementById('uc_loading').hidden = false;
            document.getElementById('uc_body').hidden = true;
            document.getElementById('uc_errors').hidden = true;
            document.getElementById('uc_history_wrap').hidden = true;
            document.getElementById('uc_confirm').disabled = true;
            openModal('unitChangeModal');
            fetch('stock-ingredients.php?unit_change_preview=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(ucShow)
                .catch(function () { ucShow({ ok: false, errors: ['The preview could not be loaded. Close this and try again.'], lines: [], history: [] }); });
        }

        function ucShow(d) {
            if (String(document.getElementById('uc_id').value) === '') return;
            document.getElementById('uc_loading').hidden = true;
            document.getElementById('uc_name').textContent = d.name || '';
            const lines = document.getElementById('uc_lines');
            lines.querySelectorAll('.uc-line:not(.uc-head)').forEach(function (el) { el.remove(); });
            if (d.old_unit) {
                document.getElementById('uc_summary').textContent = 'Main unit: ' + d.old_unit + ' -> ' + d.new_unit + ' (' + d.measure + ').';
                document.getElementById('uc_value').textContent = 'Stock value before: ' + d.value_before + '. After: ' + d.value_after + '.';
                (d.lines || []).forEach(function (l) {
                    const row = document.createElement('div');
                    row.className = 'uc-line';
                    row.setAttribute('role', 'row');
                    [['uc-place', l.place, ''], ['uc-old', l.old, 'Now: '], ['uc-new', l.new, 'After: ']].forEach(function (c) {
                        const s = document.createElement('span');
                        s.className = c[0];
                        s.setAttribute('role', 'cell');
                        s.setAttribute('data-label', c[2]);
                        s.textContent = c[1];
                        row.appendChild(s);
                    });
                    lines.appendChild(row);
                });
                document.getElementById('uc_body').hidden = false;
            } else {
                document.getElementById('uc_body').hidden = true;
            }
            const errs = document.getElementById('uc_errors');
            errs.textContent = '';
            if (!d.ok) {
                const p = document.createElement('p');
                p.textContent = 'This change cannot be made yet:';
                const ul = document.createElement('ul');
                (d.errors && d.errors.length ? d.errors : ['Unknown reason.']).forEach(function (t) {
                    const li = document.createElement('li'); li.textContent = t; ul.appendChild(li);
                });
                errs.appendChild(p);
                errs.appendChild(ul);
                errs.hidden = false;
            }
            const hl = document.getElementById('uc_history');
            hl.textContent = '';
            (d.history || []).forEach(function (h) {
                const li = document.createElement('li');
                li.textContent = h.when + ', ' + h.who + ': ' + h.change + ' (stock value ' + h.value + ')';
                hl.appendChild(li);
            });
            document.getElementById('uc_history_wrap').hidden = !(d.history && d.history.length);
            document.getElementById('uc_fp').value = d.ok ? (d.fingerprint || '') : '';
            document.getElementById('uc_confirm').disabled = !(d.ok && d.fingerprint);
        }

        /* Count measure (Step U1). The suggestion reads a size in the name (750ml, 75cl, 1L, 1.5 L) and the shot/glass
         * sizes from Settings; it only fills the fields when the person presses Use, and nothing is saved until Save. */
        const ING_MEASURE_ML = <?php echo json_encode(['shot' => $measureSizes['shot_ml'], 'glass' => $measureSizes['glass_ml']]); ?>;
        function ingMeasureNameMl(name) {
            const m = /(\d+(?:[.,]\d+)?)\s*(ml|cl|l|ltr|litres?|liters?)(?![a-z])/i.exec(name || '');
            if (!m) return null;
            const v = parseFloat(m[1].replace(',', '.'));
            const u = m[2].toLowerCase();
            const ml = u === 'ml' ? v : (u === 'cl' ? v * 10 : v * 1000);
            return ml > 0 && ml <= 100000 ? ml : null;
        }
        function ingMeasureRound(n) { return Math.round(n * 10000) / 10000; }
        function ingMeasurePlural(w) { return w ? (/(s|x|z|ch|sh)$/i.test(w) ? w + 'es' : w + 's') : w; }
        function ingMeasureSync() {
            const box = document.getElementById('ing_measure');
            if (!box) return;
            const big = document.getElementById('ing_m_big').value.trim();
            const small = document.getElementById('ing_m_small').value.trim();
            const n = parseFloat(document.getElementById('ing_m_n').value);
            const side = document.getElementById('ing_m_side');
            const unit = (document.getElementById('ing_unit').value || '').trim().toLowerCase();
            // Which measure is the stock unit follows the unit name until the person picks one.
            if (side.dataset.touched !== '1') {
                const sl = small.toLowerCase();
                side.value = (sl && unit && (sl === unit || sl + 's' === unit || unit + 's' === sl)) ? 'small' : 'big';
            }
            side.options[0].textContent = big ? 'The bigger measure (' + big + ')' : 'The bigger measure';
            side.options[1].textContent = small ? 'The smaller measure (' + small + ')' : 'The smaller measure';
            document.getElementById('ing_m_n_lbl').firstChild.textContent = (small && big ? 'How many ' + ingMeasurePlural(small) + ' in one ' + big : 'How many smaller in one bigger') + ' ';
            const hint = document.getElementById('ing_m_hint');
            if (big && small && n >= 1) {
                hint.textContent = side.value === 'big'
                    ? 'Example: a count of 2 ' + ingMeasurePlural(big) + ' + 7 ' + ingMeasurePlural(small) + ' is saved as ' + ingMeasureRound(2 + 7 / n) + ' ' + (unit || big) + ' in stock.'
                    : 'Example: a count of 2 ' + ingMeasurePlural(big) + ' + 7 ' + ingMeasurePlural(small) + ' is saved as ' + ingMeasureRound(2 * n + 7) + ' ' + (unit || small) + ' in stock.';
                hint.hidden = false;
            } else {
                hint.hidden = true;
            }
            const ml = ingMeasureNameMl(document.getElementById('ing_name').value);
            const parts = [];
            ['shot', 'glass'].forEach(function (k) {
                const btn = document.getElementById('ing_m_use_' + k);
                const per = ING_MEASURE_ML[k];
                const ok = !!(ml && per > 0);
                btn.hidden = !ok;
                if (ok) {
                    const v = ingMeasureRound(ml / per);
                    btn.textContent = 'Use ' + v + ' ' + (k === 'shot' ? 'shots' : 'glasses');
                    btn.dataset.n = String(v);
                    parts.push(v + ' ' + (k === 'shot' ? 'shots' : 'glasses') + ' of ' + per + ' ml');
                }
            });
            document.getElementById('ing_m_suggest').hidden = parts.length === 0;
            document.getElementById('ing_m_suggest_txt').textContent = parts.length ? 'The name says ' + ml + ' ml: about ' + parts.join(' or ') + ' (sizes from Settings). Nothing is filled in until you press Use.' : '';
        }
        function ingMeasureUse(kind) {
            const btn = document.getElementById('ing_m_use_' + kind);
            document.getElementById('ing_m_n').value = btn.dataset.n || '';
            if (!document.getElementById('ing_m_small').value.trim()) document.getElementById('ing_m_small').value = kind;
            if (!document.getElementById('ing_m_big').value.trim()) document.getElementById('ing_m_big').value = 'bottle';
            ingMeasureSync();
        }
        function ingMeasureClear() {
            ['ing_m_big', 'ing_m_small', 'ing_m_n'].forEach(function (id) { document.getElementById(id).value = ''; });
            document.getElementById('ing_m_side').dataset.touched = '';
            ingMeasureSync();
        }
        if (document.getElementById('ing_measure')) {
            document.getElementById('ing_name').addEventListener('input', ingMeasureSync);
            document.getElementById('ing_unit').addEventListener('change', ingMeasureSync);
        }

        /* Bottle size and drink measures (Step U2). Same maths as the server (admin/js/stock-drink-measures.js).
         * One plain line: "1 L bottle · 1 shot = 30 ml → 1 bottle = 33.3 shots · cost per shot MWK 900.00". */
        const ING_DRINK_LIST = <?php echo json_encode($drinkList); ?>;
        const ING_DRINK_CUR = <?php echo json_encode((string)$currency_symbol); ?>;
        let ingDrinkCost = 0;
        function ingDrinkVal(id) {
            const el = document.getElementById(id);
            return el ? el.value : '';
        }
        function ingDrinkItem() {
            return {
                name: ingDrinkVal('ing_name'),
                unit: ingDrinkVal('ing_unit'),
                volume_ml: ingDrinkVal('ing_vol'),
                measure_big_name: ingDrinkVal('ing_m_big').trim(),
                measure_small_name: ingDrinkVal('ing_m_small').trim(),
                measure_per_big: ingDrinkVal('ing_m_n'),
                measure_stock_side: document.getElementById('ing_measure') ? ingDrinkVal('ing_m_side') : ''
            };
        }
        /* The word for one stock unit in the line: the unit itself (bottle, can...), "bottle" for pieces. */
        function ingDrinkWord(unit) {
            return unit && !/^(pcs|pieces?|each|ea|units?)$/i.test(unit) ? unit : 'bottle';
        }
        function ingDrinkSize(ml) {
            return ml >= 1000 ? RHDrink.fmt(ml / 1000, 3) + ' L' : RHDrink.fmt(ml, 2) + ' ml';
        }
        function ingDrinkSync() {
            const box = document.getElementById('ing_drink');
            if (!box || !window.RHDrink) return;
            const ing = ingDrinkItem();
            const unit = (ing.unit || '').trim();
            const weight = RHDrink.isWeight(unit) || RHDrink.isContainer(unit) || /^(slices?|tsp|tbsp)$/i.test(unit); // crates / packs hold many bottles: no bottle size
            box.hidden = weight; // kg / g stock, spoons, slices: no drink measures
            const volUnit = RHDrink.unitMl(unit) !== null;
            document.getElementById('ing_vol_row').hidden = volUnit;
            const lbl = document.getElementById('ing_vol_lbl');
            const smallSide = ing.measure_stock_side === 'small' && ing.measure_big_name;
            const bigWord = smallSide ? ing.measure_big_name : ingDrinkWord(unit);
            lbl.firstChild.textContent = 'Size of one ' + bigWord + ' in ml ';
            const line = document.getElementById('ing_drink_line');
            const pickRow = document.getElementById('ing_drink_pick_row');
            const pick = document.getElementById('ing_drink_pick');
            const size = weight ? null : RHDrink.itemMl(Object.assign({}, ing, { unit: unit }));
            if (!size) {
                line.textContent = weight ? '' : 'Type the size of one ' + bigWord + ' in ml (or put it in the name, like Gin 750ml) to use shots, glasses or ml in recipes.';
                pickRow.hidden = true;
                return;
            }
            const whole = size.source === 'unit' ? size.perUnit : size.container; // ml in one whole bottle (or one litre)
            const wholeWord = size.source === 'unit' ? unit : bigWord;
            const src = size.source === 'name' ? ' (from the name)' : '';
            if (!ING_DRINK_LIST.length) {
                line.textContent = '1 ' + wholeWord + ' = ' + ingDrinkSize(whole) + src + '. Add shots, glasses and so on under Drink measures to see how many are in one.';
                pickRow.hidden = true;
                return;
            }
            const keep = pick.value;
            if (pick.options.length !== ING_DRINK_LIST.length) {
                pick.innerHTML = '';
                ING_DRINK_LIST.forEach(function (m) {
                    const o = document.createElement('option');
                    o.value = m.name;
                    o.textContent = m.name + ' (' + RHDrink.fmt(m.ml, 2) + ' ml)';
                    pick.appendChild(o);
                });
            }
            const shot = ING_DRINK_LIST.find(function (m) { return m.name.toLowerCase() === 'shot'; });
            pick.value = keep && ING_DRINK_LIST.some(function (m) { return m.name === keep; }) ? keep : (shot ? shot.name : ING_DRINK_LIST[0].name);
            const m = ING_DRINK_LIST.find(function (x) { return x.name === pick.value; });
            const n = whole / m.ml;
            let t;
            if (size.source === 'unit' && size.perUnit < 100) {
                // Stock kept in ml or cl: say what one measure is in that unit.
                t = 'Stock in ' + unit + ' · 1 ' + m.name + ' = ' + RHDrink.fmt(m.ml / size.perUnit, 2) + ' ' + unit;
            } else {
                t = (size.source === 'unit' ? '1 ' + unit : ingDrinkSize(whole) + ' ' + wholeWord) + src + ' · 1 ' + m.name + ' = ' + RHDrink.fmt(m.ml, 2) + ' ml → 1 ' + wholeWord + ' = ' + RHDrink.fmt(n, 1) + ' ' + RHDrink.plural(m.name, n);
            }
            if (ingDrinkCost > 0) t += ' · cost per ' + m.name + ' ' + ING_DRINK_CUR + ' ' + (ingDrinkCost * m.ml / size.perUnit).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            line.textContent = t;
            pickRow.hidden = false;
            const use = document.getElementById('ing_drink_use');
            if (use) use.dataset.n = String(Math.round(n * 10000) / 10000);
        }
        /* Fills the count measure (Step U1) with the picked measure: suggestion only, saved with Save. */
        function ingDrinkUse() {
            const pick = document.getElementById('ing_drink_pick');
            const use = document.getElementById('ing_drink_use');
            if (!pick || !use || !document.getElementById('ing_measure') || !(parseFloat(use.dataset.n) > 0)) return;
            const unit = (document.getElementById('ing_unit').value || '').trim();
            document.getElementById('ing_m_small').value = pick.value;
            document.getElementById('ing_m_n').value = use.dataset.n;
            if (!document.getElementById('ing_m_big').value.trim()) document.getElementById('ing_m_big').value = ingDrinkWord(unit);
            ingMeasureSync();
            ingDrinkSync();
            document.getElementById('ing_m_n').focus();
        }
        if (document.getElementById('ing_drink')) {
            document.getElementById('ingredientForm').addEventListener('input', ingDrinkSync);
            document.getElementById('ingredientForm').addEventListener('change', ingDrinkSync);
        }
        /* Drink measures list: add / remove rows (blank rows are ignored on Save). */
        function dmAddRow() {
            const body = document.getElementById('dm_rows');
            if (!body) return;
            const row = document.createElement('div');
            row.className = 'dm-row';
            row.innerHTML = '<input type="text" name="measure_name[]" maxlength="30" placeholder="e.g. tot" aria-label="Measure name">' +
                '<input type="number" name="measure_ml[]" step="any" min="0.01" max="<?php echo (int)RH_DRINK_MEASURE_MAX_ML; ?>" placeholder="e.g. 30" aria-label="Size in ml">' +
                '<button type="button" class="btn-secondary dm-remove" onclick="dmRemoveRow(this)" aria-label="Remove this measure">Remove</button>';
            body.appendChild(row);
            row.querySelector('input').focus();
        }
        function dmRemoveRow(btn) {
            const row = btn.closest('.dm-row');
            if (row) row.remove();
        }

        /* Pieces in one unit: only for container units (packet, box, tray...); a dozen is always 12. */
        function ingredientPiecesSync() {
            const ING_CONTAINER_RE = /^(packets?|packs?|packages?|boxe?s?|tins?|trays?|crates?|cartons?|bags?|sachets?|buckets?|containers?|bunch(es)?|bundles?|cases?|punnets?|dozens?|dz|doz)$/i;
            const unit = (document.getElementById('ing_unit').value || '').trim();
            const row = document.getElementById('ing_pieces_row');
            const show = ING_CONTAINER_RE.test(unit) && !/^(dozens?|dz|doz)$/i.test(unit);
            row.hidden = !show;
            if (!show) document.getElementById('ing_pieces').value = '';
            document.getElementById('ing_pieces_lbl').firstChild.textContent = 'Pieces in one ' + (unit || 'unit') + ' ';
        }
        document.getElementById('ing_unit').addEventListener('change', function () { ingredientPiecesSync(); });

        // Lock the Unit select (with a visible reason) when the ingredient already has stock,
        // batches, adjustments or recipes: the server refuses a unit change then.
        function setIngredientUnitLock(locked, unit) {
            const sel = document.getElementById('ing_unit');
            const keep = document.getElementById('ing_unit_keep');
            const note = document.getElementById('ing_unit_note');
            sel.disabled = locked;
            keep.disabled = !locked;
            keep.value = locked ? unit : '';
            note.hidden = !locked;
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
            let shown = 0;
            const rows = document.querySelectorAll('.ing-row');
            rows.forEach(row => {
                const name = row.dataset.name || '';
                const c = row.dataset.category || '';
                const s = row.dataset.status || '';
                let show = true;
                if (q && name.indexOf(q) === -1) show = false;
                if (cat && c !== cat) show = false;
                if (st && s !== st) show = false;
                // data-filter-hidden tells the table pager (admin-section-pagination.js) to leave this
                // row out of its pages and counts; display covers a table too short to have a pager.
                row.dataset.filterHidden = show ? '' : '1';
                row.style.display = show ? '' : 'none';
                if (show) shown++;
            });
            const table = document.getElementById('stock-table');
            if (table && typeof table.rhPagerRefresh === 'function') {
                table.rhPagerRefresh(true);
            } else if (table) {
                rows.forEach(row => { row.hidden = false; });
            }
            const counter = document.getElementById('filter-count');
            if (counter) {
                const total = parseInt(counter.dataset.total || '0', 10);
                counter.textContent = shown === total ? total + ' ingredient(s)' : shown + ' of ' + total + ' ingredient(s)';
            }
        }

        // Add / Edit form: a duplicate name is flagged while typing, Save shows a busy state and
        // ignores a second click, and a refused save reopens the form with what was typed.
        (function () {
            const form = document.getElementById('ingredientForm');
            if (!form || !window.RHFormGuard) return;
            RHFormGuard.watch(document.getElementById('ing_name'), function () {
                return { form: 'stock_ingredient', excludeId: document.getElementById('ing_id').value };
            });
            RHFormGuard.guardSubmit(form, { busyText: 'Saving...' });
        })();
        <?php if ($stockFormReopen): ?>
        (function () {
            const r = <?php echo json_encode($stockFormReopen, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            openIngredientModal(r.data);
            if (window.RHFormGuard) RHFormGuard.showError(document.getElementById('ingredientForm'), r.error);
        })();
        <?php endif; ?>
    </script>
</body>

</html>

