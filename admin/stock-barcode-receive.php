<?php
/**
 * Stock Barcode Receive
 * Mobile-first delivery receiving via barcode scanner (camera or USB wedge).
 * Scan each product → build a batch list → submit to update stock.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';

/** @var PDO $pdo */
$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];

if (!ensureStockTablesExist()) {
    http_response_code(500); exit('Stock tables missing.');
}
if (!hasPermission($user['id'], 'stock_management')) {
    header('Location: dashboard.php?error=access_denied'); exit;
}

$csrf_token    = generateCsrfToken();
$currency      = getSetting('currency_symbol', 'MWK');
$siteName      = getSetting('site_name', 'Hotel');

// ── AJAX handlers ────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    // lookup_barcode — find ingredient OR menu item mapped to a barcode
    if ($_GET['ajax'] === 'lookup_barcode') {
        $barcode = trim($_POST['barcode'] ?? '');
        if ($barcode === '') { echo json_encode(['found' => false]); exit; }
        // 1. Check stock ingredient barcodes
        $stmt = $pdo->prepare("
            SELECT sib.id AS mapping_id, sib.barcode, sib.pack_size, sib.pack_label,
                   si.id AS ingredient_id, si.name, si.unit, si.current_quantity, si.cost_per_unit
            FROM stock_ingredient_barcodes sib
            JOIN stock_ingredients si ON si.id = sib.ingredient_id
            WHERE sib.barcode = ?
        ");
        $stmt->execute([$barcode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo json_encode(['found' => true, 'type' => 'ingredient', 'ingredient' => $row]);
            exit;
        }
        // 2. Check POS menu items
        $stmt2 = $pdo->prepare("
            SELECT mi.id, mi.item_name AS name, mi.price, mi.barcode,
                   mc.name AS category_name, mc.slug AS menu_type
            FROM menu_items mi
            JOIN menu_categories mc ON mc.id = mi.category_id
            WHERE mi.barcode = ?
        ");
        $stmt2->execute([$barcode]);
        $menuItem = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($menuItem) {
            echo json_encode(['found' => true, 'type' => 'pos_item', 'item' => $menuItem]);
            exit;
        }
        echo json_encode(['found' => false, 'barcode' => $barcode]);
        exit;
    }

    // register_barcode — link a new barcode to an ingredient
    if ($_GET['ajax'] === 'register_barcode') {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            http_response_code(403); echo json_encode(['error' => 'Invalid token.']); exit;
        }
        $barcode      = trim($_POST['barcode'] ?? '');
        $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
        $packSize     = max(0.0001, (float)($_POST['pack_size'] ?? 1));
        $packLabel    = mb_substr(trim($_POST['pack_label'] ?? ''), 0, 50) ?: null;
        if (!$barcode || !$ingredientId) {
            http_response_code(400); echo json_encode(['error' => 'Barcode and ingredient required.']); exit;
        }
        try {
            // Check ingredient exists
            $ing = $pdo->prepare("SELECT id, name, unit, current_quantity, cost_per_unit FROM stock_ingredients WHERE id = ? AND is_archived = 0");
            $ing->execute([$ingredientId]);
            $ingredient = $ing->fetch(PDO::FETCH_ASSOC);
            if (!$ingredient) { http_response_code(404); echo json_encode(['error' => 'Ingredient not found.']); exit; }

            // Check barcode not already taken
            $dup = $pdo->prepare("SELECT ingredient_id FROM stock_ingredient_barcodes WHERE barcode = ?");
            $dup->execute([$barcode]);
            if ($dup->fetch()) {
                http_response_code(409); echo json_encode(['error' => 'This barcode is already registered to another ingredient.']); exit;
            }

            $pdo->prepare("INSERT INTO stock_ingredient_barcodes (barcode, ingredient_id, pack_size, pack_label, created_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$barcode, $ingredientId, $packSize, $packLabel, $user['id']]);

            logActivity($user['id'], 'barcode_registered', "Registered barcode {$barcode} → {$ingredient['name']} (pack: {$packSize} {$ingredient['unit']})");
            echo json_encode(['ok' => true, 'ingredient' => array_merge($ingredient, [
                'ingredient_id' => $ingredient['id'],
                'pack_size'     => $packSize,
                'pack_label'    => $packLabel,
                'barcode'       => $barcode,
            ])]);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // receive_batch — submit scanned delivery, create batches, update stock
    if ($_GET['ajax'] === 'receive_batch') {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            http_response_code(403); echo json_encode(['error' => 'Invalid token.']); exit;
        }
        $items        = json_decode($_POST['items'] ?? '[]', true);
        $supplier     = mb_substr(trim($_POST['supplier'] ?? ''), 0, 255) ?: null;
        $receivedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['received_date'] ?? '') ? $_POST['received_date'] : date('Y-m-d');
        if (!$items || !is_array($items) || count($items) === 0) {
            http_response_code(400); echo json_encode(['error' => 'No items to receive.']); exit;
        }
        try {
            $pdo->beginTransaction();
            $created = 0;
            foreach ($items as $item) {
                $ingId   = (int)($item['ingredient_id'] ?? 0);
                $qty     = (float)($item['quantity'] ?? 0);
                $cost    = (float)($item['cost_per_unit'] ?? 0);
                if (!$ingId || $qty <= 0) continue;

                // Lock + get current ingredient
                $sel = $pdo->prepare("SELECT current_quantity, cost_per_unit FROM stock_ingredients WHERE id = ? FOR UPDATE");
                $sel->execute([$ingId]);
                $ing = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$ing) continue;

                $oldQty = (float)$ing['current_quantity'];
                $oldAvg = (float)$ing['cost_per_unit'];
                $newAvg = $cost > 0 ? calculateWeightedAvgCost($oldQty, $oldAvg, $qty, $cost) : $oldAvg;

                // Create batch
                $bIns = $pdo->prepare("
                    INSERT INTO stock_batches
                        (ingredient_id, batch_number, quantity_received, quantity_remaining,
                         cost_per_unit, supplier_name, received_date, status, notes, created_by)
                    VALUES (?, '', ?, ?, ?, ?, ?, 'active', ?, ?)
                ");
                $note = 'Received via barcode scanner' . ($supplier ? " — {$supplier}" : '');
                $bIns->execute([$ingId, $qty, $qty, $cost ?: $oldAvg, $supplier, $receivedDate, $note, $user['id']]);
                $batchId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE stock_batches SET batch_number = ? WHERE id = ?")
                    ->execute(['B' . str_pad((string)$batchId, 6, '0', STR_PAD_LEFT), $batchId]);

                // Stock-in log
                $pdo->prepare("
                    INSERT INTO stock_in_log
                        (ingredient_id, batch_id, quantity, cost_per_unit, cost_total, supplier_name,
                         avg_cost_before, avg_cost_after, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$ingId, $batchId, $qty, $cost ?: $oldAvg, $qty * ($cost ?: $oldAvg),
                    $supplier, $oldAvg, $newAvg, $note, $user['id']]);

                // Update ingredient qty + avg cost
                $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ?, cost_per_unit = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$qty, $newAvg, $ingId]);

                // Adjustment row
                $pdo->prepare("
                    INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by)
                    VALUES (?, ?, 'Stock received via barcode scan', 'stock_in', ?, ?, ?)
                ")->execute([$ingId, $qty, $batchId, $cost ?: $oldAvg, $user['id']]);

                $created++;
            }
            $pdo->commit();
            logActivity($user['id'], 'barcode_receive_batch', "Received {$created} line(s) via barcode scanner" . ($supplier ? " from {$supplier}" : ''));
            echo json_encode(['ok' => true, 'batches_created' => $created]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // search_ingredients — autocomplete for the register modal
    if ($_GET['ajax'] === 'search_ingredients') {
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $rows = $pdo->prepare("SELECT id, name, unit, category FROM stock_ingredients WHERE is_archived = 0 AND name LIKE ? ORDER BY name LIMIT 30");
        $rows->execute([$q]);
        echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // search_categories — menu categories for item registration
    if ($_GET['ajax'] === 'search_categories') {
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $rows = $pdo->prepare("SELECT id, name, slug FROM menu_categories WHERE is_active = 1 AND name LIKE ? ORDER BY sort_order, name LIMIT 20");
        $rows->execute([$q]);
        echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // register_item — create a menu_item with this barcode (POS retail item)
    if ($_GET['ajax'] === 'register_item') {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            http_response_code(403); echo json_encode(['error' => 'Invalid token.']); exit;
        }
        $barcode    = trim($_POST['barcode'] ?? '');
        $name       = mb_substr(trim($_POST['name'] ?? ''), 0, 200);
        $price      = round(max(0, (float)($_POST['price'] ?? 0)), 2);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        if (!$barcode || !$name) {
            http_response_code(400); echo json_encode(['error' => 'Barcode and name required.']); exit;
        }
        try {
            // Default to "Retail Items" category if none chosen
            if (!$categoryId) {
                $catRow = $pdo->query("SELECT id FROM menu_categories WHERE slug = 'retail' AND is_active = 1 LIMIT 1")->fetch();
                $categoryId = $catRow ? (int)$catRow['id'] : 0;
            }
            if (!$categoryId) {
                http_response_code(400); echo json_encode(['error' => 'Please select a category.']); exit;
            }
            // Check barcode not already on a menu item
            $dup = $pdo->prepare("SELECT id FROM menu_items WHERE barcode = ?");
            $dup->execute([$barcode]);
            if ($dup->fetch()) {
                http_response_code(409); echo json_encode(['error' => 'This barcode is already registered to a POS item.']); exit;
            }
            // Also check ingredient barcodes
            $dup2 = $pdo->prepare("SELECT id FROM stock_ingredient_barcodes WHERE barcode = ?");
            $dup2->execute([$barcode]);
            if ($dup2->fetch()) {
                http_response_code(409); echo json_encode(['error' => 'This barcode is already registered as a stock ingredient.']); exit;
            }
            $maxOrderStmt = $pdo->prepare("SELECT COALESCE(MAX(display_order),0) FROM menu_items WHERE category_id = ?");
            $maxOrderStmt->execute([$categoryId]);
            $maxOrder = (int)$maxOrderStmt->fetchColumn();
            $pdo->prepare("
                INSERT INTO menu_items (item_name, price, category_id, barcode, show_pos, show_room_service, is_available, display_order)
                VALUES (?, ?, ?, ?, 1, 0, 1, ?)
            ")->execute([$name, $price, $categoryId, $barcode, $maxOrder + 10]);
            $newId = (int)$pdo->lastInsertId();
            // Get category name for response
            $cat = $pdo->prepare("SELECT name, slug FROM menu_categories WHERE id = ?");
            $cat->execute([$categoryId]);
            $catRow = $cat->fetch(PDO::FETCH_ASSOC);
            logActivity($user['id'], 'barcode_item_registered', "Registered barcode {$barcode} → POS item: {$name} @ {$currency}{$price}");
            echo json_encode(['ok' => true, 'item' => [
                'id'            => $newId,
                'name'          => $name,
                'price'         => $price,
                'barcode'       => $barcode,
                'category_name' => $catRow['name'] ?? '',
                'menu_type'     => $catRow['slug'] ?? '',
            ]]);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400); echo json_encode(['error' => 'Unknown action.']); exit;
}

// Load ingredients count for display
$ingredientCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE is_archived = 0")->fetchColumn();
$barcodeCount    = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredient_barcodes")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Receive Stock — <?php echo htmlspecialchars($siteName); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- BarcodeDetector polyfill for desktop Chrome / Firefox / Safari (self-hosted) -->
<script type="module">
import { BarcodeDetectorPolyfill } from './js/barcode-detector-polyfill.js';
if (!('BarcodeDetector' in window)) { window.BarcodeDetector = BarcodeDetectorPolyfill; }
</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f4f2ef;--surface:#fffdfb;--surface2:#ede9e3;--border:#d7dde6;
  --primary:#8A775F;--success:#3f8f5a;--warn:#9a7c53;--danger:#956a5b;
  --text:#1f2a37;--muted:#5f6b7c;--radius:12px;
  --navy:#111827;--gold:#B18247;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'Jost',sans-serif;font-size:15px;overscroll-behavior:none}
a{color:var(--primary);text-decoration:none}

/* ── Top bar ── */
.topbar{display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--navy);border-bottom:3px solid var(--gold);position:sticky;top:0;z-index:100}
.topbar-back{width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:rgba(255,255,255,.1);color:#fff;font-size:16px;border:none;cursor:pointer}
.topbar-back:hover{background:rgba(255,255,255,.18)}
.topbar-title{flex:1;font-size:16px;font-weight:600;color:#fff}
.topbar-stats{font-size:12px;color:rgba(255,255,255,.6);text-align:right;line-height:1.4}

/* ── Camera zone ── */
.camera-zone{position:relative;background:#000;width:100%;max-height:240px;overflow:hidden;display:flex;align-items:center;justify-content:center}
.camera-zone video{width:100%;max-height:240px;object-fit:cover;display:block}
.scan-overlay{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none}
.scan-frame{width:200px;height:100px;border:2px solid var(--gold);border-radius:8px;box-shadow:0 0 0 9999px rgba(0,0,0,.45)}
.scan-line{position:absolute;width:180px;height:2px;background:var(--gold);opacity:.8;animation:scanline 1.8s ease-in-out infinite}
@keyframes scanline{0%{top:calc(50% - 45px)}100%{top:calc(50% + 43px)}}
.scan-status{position:absolute;bottom:10px;background:rgba(0,0,0,.7);border-radius:20px;padding:4px 14px;font-size:12px;color:#fff}
.cam-error-msg{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;text-align:center;background:rgba(0,0,0,.88)}
.cam-error-msg i{font-size:28px;opacity:.5;margin-bottom:12px;color:#fff}
.cam-error-msg .cam-err-title{font-size:13px;font-weight:700;color:#fff;margin-bottom:6px}
.cam-error-msg .cam-err-hint{font-size:12px;color:rgba(255,255,255,.65);line-height:1.6}
.cam-error-msg .cam-err-retry{margin-top:14px;padding:8px 20px;background:var(--gold);border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}

/* ── Manual / fallback input ── */
.manual-row{display:flex;gap:8px;padding:12px 16px;background:var(--surface);border-bottom:1px solid var(--border)}
.manual-row input{flex:1;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 14px;color:var(--text);font-size:14px;font-family:inherit;outline:none}
.manual-row input:focus{border-color:var(--primary)}
.manual-row button{padding:10px 16px;background:var(--primary);border:none;border-radius:8px;color:#fff;font-weight:600;cursor:pointer;font-size:14px;white-space:nowrap}

/* ── Delivery meta ── */
.meta-strip{padding:12px 16px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;gap:10px;flex-wrap:wrap}
.meta-strip input{flex:1;min-width:120px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);font-size:13px;font-family:inherit;outline:none}
.meta-strip input:focus{border-color:var(--primary)}

/* ── Toggle buttons ── */
.cam-btn{display:flex;align-items:center;gap:8px;padding:9px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-weight:500;cursor:pointer;white-space:nowrap}
.cam-btn.active{background:#e8f5ee;border-color:var(--success);color:var(--success)}
.scanner-toggle-btn{display:flex;align-items:center;gap:8px;padding:9px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .2s}
.scanner-toggle-btn.active{background:#f0ede8;border-color:var(--primary);color:var(--primary)}

/* ── Scanner status strip ── */
#scannerStrip{display:none;align-items:center;justify-content:center;gap:8px;padding:8px 16px;background:#e8f5ee;border-bottom:1px solid #b5dcc4;font-size:12px;color:var(--success)}
#scannerStrip.off{display:flex;background:#f5ede8;border-color:#d7c0b0;color:var(--warn)}

/* ── Batch list ── */
.section-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px 6px;font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.batch-list{padding:0 16px 120px}
.batch-empty{text-align:center;padding:40px 20px;color:var(--muted)}
.batch-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.3;color:var(--primary)}
.batch-item{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.batch-item-head{display:flex;align-items:center;gap:10px;padding:12px 14px}
.batch-item-icon{width:36px;height:36px;background:var(--surface2);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:14px;flex-shrink:0}
.batch-item-name{flex:1;font-weight:600;font-size:14px;line-height:1.3}
.batch-item-sub{font-size:11px;color:var(--muted);margin-top:2px}
.batch-item-remove{width:32px;height:32px;background:none;border:none;color:var(--muted);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;border-radius:6px}
.batch-item-remove:hover{color:var(--danger);background:#f5e8e8}
.batch-item-body{padding:0 14px 12px;display:flex;gap:8px;flex-wrap:wrap}
.batch-field{display:flex;flex-direction:column;gap:4px;flex:1;min-width:90px}
.batch-field label{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.batch-field input{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 10px;color:var(--text);font-size:14px;font-family:inherit;outline:none;width:100%}
.batch-field input:focus{border-color:var(--primary)}
.batch-scan-count{display:inline-flex;align-items:center;gap:6px;background:var(--surface2);border-radius:20px;padding:3px 10px;font-size:12px;color:var(--muted);margin-top:4px;border:1px solid var(--border)}
.batch-scan-count button{background:none;border:none;color:var(--text);font-size:16px;cursor:pointer;width:24px;height:24px;display:flex;align-items:center;justify-content:center;border-radius:50%}
.batch-scan-count button:hover{background:var(--border)}
.batch-scan-count .qty-val{min-width:32px;text-align:center;font-weight:700;color:var(--text);font-size:15px}

/* ── Submit bar ── */
.submit-bar{position:fixed;bottom:0;left:0;right:0;padding:12px 16px;background:var(--surface);border-top:1px solid var(--border);z-index:100;display:flex;gap:10px;align-items:center;box-shadow:0 -2px 12px rgba(0,0,0,.06)}
.submit-btn{flex:1;padding:14px;background:var(--success);border:none;border-radius:var(--radius);color:#fff;font-size:16px;font-weight:700;cursor:pointer;font-family:inherit}
.submit-btn:disabled{opacity:.4;cursor:not-allowed}
.submit-count{font-size:13px;color:var(--muted);white-space:nowrap}

/* ── Flash ── */
.scan-flash{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--success);color:#fff;padding:14px 28px;border-radius:12px;font-size:15px;font-weight:700;z-index:9999;pointer-events:none;transition:opacity .3s;opacity:0}
.scan-flash.show{opacity:1}
.scan-flash.error{background:var(--danger)}

/* ── Modal overlay ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;display:flex;align-items:flex-end;justify-content:center}
.modal-sheet{background:var(--surface);border-radius:20px 20px 0 0;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;padding:20px 16px 32px}
.modal-handle{width:40px;height:4px;background:var(--border);border-radius:2px;margin:0 auto 16px}
.modal-title{font-size:16px;font-weight:700;margin-bottom:4px;color:var(--text)}
.modal-sub{font-size:13px;color:var(--muted);margin-bottom:16px}
.modal-field{margin-bottom:14px}
.modal-field label{display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
.modal-field input,.modal-field select{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:11px 14px;color:var(--text);font-size:15px;font-family:inherit;outline:none}
.modal-field input:focus,.modal-field select:focus{border-color:var(--primary)}
.ing-results{background:var(--surface);border:1px solid var(--border);border-radius:8px;margin-top:4px;max-height:180px;overflow-y:auto;display:none;box-shadow:0 4px 12px rgba(0,0,0,.08)}
.ing-result-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.ing-result-item:last-child{border-bottom:none}
.ing-result-item:hover{background:var(--surface2)}
.ing-result-item .ing-name{font-weight:600;font-size:14px}
.ing-result-item .ing-meta{font-size:12px;color:var(--muted)}
.modal-actions{display:flex;gap:10px;margin-top:20px}
.modal-btn{flex:1;padding:13px;border-radius:10px;border:none;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit}
.modal-btn-primary{background:var(--primary);color:#fff}
.modal-btn-secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border)}
.modal-btn:disabled{opacity:.4;cursor:not-allowed}
.modal-err{color:var(--danger);font-size:13px;margin-top:8px;display:none}

/* ── Type picker cards ── */
.reg-type-card{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;padding:18px 12px;background:var(--surface2);border:2px solid var(--border);border-radius:12px;cursor:pointer;text-align:center;font-family:inherit;transition:border-color .15s,background .15s}
.reg-type-card:hover{border-color:var(--primary);background:#f5f2ed}
.reg-type-card strong{font-size:14px;font-weight:700;color:var(--text)}
.reg-type-card span{font-size:11px;color:var(--muted);line-height:1.4;margin-top:2px}
.reg-type-card.selected{border-color:var(--primary);background:#f0ece6}

/* ── Torch button ── */
.torch-btn{display:none;align-items:center;gap:6px;padding:9px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:13px;font-weight:500;cursor:pointer;white-space:nowrap}
.torch-btn.active{background:#fef9e7;border-color:#d4a017;color:#a07800}
.torch-btn.visible{display:flex}

/* ── Batch item pulse on re-scan ── */
@keyframes pulse-scan{0%{box-shadow:0 0 0 0 rgba(138,119,95,.5)}70%{box-shadow:0 0 0 8px rgba(138,119,95,0)}100%{box-shadow:0 0 0 0 rgba(138,119,95,0)}}
.batch-item.pulse{animation:pulse-scan .4s ease-out}

/* ── Scan count badge ── */
.scan-badge{display:inline-flex;align-items:center;gap:3px;background:var(--primary);color:#fff;font-size:10px;font-weight:700;border-radius:10px;padding:2px 7px;margin-left:6px;vertical-align:middle}
</style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
    <button class="topbar-back" onclick="history.back()"><i class="fas fa-arrow-left"></i></button>
    <div class="topbar-title"><i class="fas fa-barcode" style="color:var(--primary);margin-right:8px"></i>Receive Stock</div>
    <div class="topbar-stats"><?php echo $barcodeCount; ?> barcodes<br><?php echo $ingredientCount; ?> ingredients</div>
</div>

<!-- Camera zone -->
<div class="camera-zone" id="cameraZone" style="display:none">
    <video id="camVideo" autoplay playsinline muted></video>
    <div class="scan-overlay" id="scanOverlay" style="display:none">
        <div class="scan-frame"></div>
        <div class="scan-line"></div>
        <div class="scan-status" id="scanStatus">Point camera at barcode</div>
    </div>
    <div class="cam-error-msg" id="camErrorMsg" style="display:none"></div>
</div>

<!-- Scanner status strip -->
<div id="scannerStrip"></div>

<!-- Controls row -->
<div class="manual-row">
    <button class="scanner-toggle-btn" id="scannerToggleBtn" onclick="toggleScanner()" title="Enable / disable barcode scanner">
        <i class="fas fa-barcode"></i> <span id="scannerToggleLbl">Scanner: OFF</span>
    </button>
    <button class="cam-btn" id="camToggle" onclick="toggleCamera()" style="display:none">
        <i class="fas fa-camera"></i> Camera
    </button>
    <button class="torch-btn" id="torchBtn" onclick="toggleTorch()" title="Toggle flashlight">
        <i class="fas fa-bolt"></i>
    </button>
    <input type="text" id="manualInput" placeholder="Type or scan barcode here…"
        autocomplete="off" autocorrect="off" spellcheck="false" inputmode="text" style="display:none">
    <button id="manualSubmitBtn" onclick="handleManualInput()" style="display:none"><i class="fas fa-search"></i></button>
</div>

<!-- Delivery meta -->
<div class="meta-strip">
    <input type="text" id="supplierInput" placeholder="Supplier name (optional)">
    <input type="date" id="receivedDate" value="<?php echo date('Y-m-d'); ?>">
</div>

<!-- Batch list -->
<div class="section-head">
    <span>Scanned Items</span>
    <span id="batchTally" style="color:var(--text)">0 items</span>
</div>
<div class="batch-list" id="batchList">
    <div class="batch-empty" id="emptyState">
        <i class="fas fa-barcode"></i>
        Scan a product barcode to start building the delivery.
    </div>
</div>

<!-- Submit bar -->
<div class="submit-bar">
    <div class="submit-count" id="submitCount">Nothing scanned yet</div>
    <button class="submit-btn" id="submitBtn" onclick="submitBatch()" disabled>
        <i class="fas fa-check"></i> Receive into Stock
    </button>
</div>

<!-- Flash message -->
<div class="scan-flash" id="scanFlash"></div>

<!-- Register barcode modal -->
<div class="modal-overlay" id="registerModal" style="display:none">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <div class="modal-title">Unknown Barcode</div>
        <div class="modal-sub" id="registerModalSub" style="font-family:monospace;font-size:12px;background:var(--surface2);padding:6px 10px;border-radius:6px;color:var(--muted)"></div>

        <!-- Step 1: type picker -->
        <div id="regTypePicker" style="margin-top:16px">
            <p style="font-size:13px;color:var(--muted);margin-bottom:12px">What is this barcode for?</p>
            <div style="display:flex;gap:10px">
                <button class="reg-type-card" id="regTypeIngBtn" onclick="selectRegType('ingredient')">
                    <i class="fas fa-boxes" style="font-size:22px;color:var(--primary);margin-bottom:8px"></i>
                    <strong>Ingredient</strong>
                    <span>Used in recipes &amp; stock management</span>
                </button>
                <button class="reg-type-card" id="regTypeItemBtn" onclick="selectRegType('item')">
                    <i class="fas fa-tag" style="font-size:22px;color:var(--success);margin-bottom:8px"></i>
                    <strong>Item for Sale</strong>
                    <span>Scanned at POS for payment (drinks, snacks…)</span>
                </button>
            </div>
        </div>

        <!-- Step 2a: Ingredient form -->
        <div id="regIngForm" style="display:none;margin-top:16px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
                <button onclick="selectRegType(null)" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;padding:0"><i class="fas fa-arrow-left"></i> Back</button>
                <span style="font-size:13px;font-weight:600;color:var(--text)">Link to Ingredient</span>
            </div>
            <div class="modal-field">
                <label>Ingredient</label>
                <input type="text" id="regIngSearch" placeholder="Search ingredients…" autocomplete="off" oninput="searchIngredients(this.value)">
                <div class="ing-results" id="ingResults"></div>
                <input type="hidden" id="regIngId">
            </div>
            <div class="modal-field" style="display:flex;gap:10px">
                <div style="flex:1">
                    <label>Pack Size</label>
                    <input type="number" id="regPackSize" value="1" min="0.001" step="any" placeholder="e.g. 24">
                </div>
                <div style="flex:1">
                    <label>Pack Label</label>
                    <input type="text" id="regPackLabel" placeholder="e.g. can, bottle, case">
                </div>
            </div>
            <div class="modal-err" id="registerIngErr"></div>
            <div class="modal-actions">
                <button class="modal-btn modal-btn-secondary" onclick="closeRegisterModal()">Cancel</button>
                <button class="modal-btn modal-btn-primary" id="registerIngSaveBtn" onclick="saveBarcode()">Save &amp; Add to Batch</button>
            </div>
        </div>

        <!-- Step 2b: POS Item form -->
        <div id="regItemForm" style="display:none;margin-top:16px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
                <button onclick="selectRegType(null)" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;padding:0"><i class="fas fa-arrow-left"></i> Back</button>
                <span style="font-size:13px;font-weight:600;color:var(--text)">Register POS Item</span>
            </div>
            <div class="modal-field">
                <label>Item Name</label>
                <input type="text" id="regItemName" placeholder="e.g. Coca-Cola 330ml" autocomplete="off">
            </div>
            <div class="modal-field" style="display:flex;gap:10px">
                <div style="flex:1">
                    <label>Sale Price (<?php echo htmlspecialchars($currency); ?>)</label>
                    <input type="number" id="regItemPrice" min="0" step="0.01" placeholder="0.00">
                </div>
                <div style="flex:1">
                    <label>Category</label>
                    <input type="text" id="regCatSearch" placeholder="Search or leave blank…" autocomplete="off" oninput="searchCategories(this.value)">
                    <div class="ing-results" id="catResults"></div>
                    <input type="hidden" id="regCatId">
                </div>
            </div>
            <p style="font-size:11px;color:var(--muted);margin-top:-6px">Leave category blank to use "Retail Items" automatically.</p>
            <div class="modal-err" id="registerItemErr"></div>
            <div class="modal-actions">
                <button class="modal-btn modal-btn-secondary" onclick="closeRegisterModal()">Cancel</button>
                <button class="modal-btn modal-btn-primary" id="registerItemSaveBtn" onclick="saveItem()">Register on POS</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = <?php echo json_encode($csrf_token); ?>;
const PAGE = 'stock-barcode-receive.php';
const LS_SCANNER_KEY = 'sbr_scanner_enabled';
const LS_CAM_KEY     = 'sbr_cam_active';

// ── State ─────────────────────────────────────────────────────────────────
let batch = {};
let camStream = null;
let camDetecting = false;
let pendingBarcode = null;
let scannerEnabled = localStorage.getItem(LS_SCANNER_KEY) === '1';
let _modalOpen = false;

// ── Barcode cache (eliminates duplicate server round-trips) ───────────────
const _barcodeCache = new Map(); // barcode → { data, ts }
const CACHE_TTL = 20 * 60 * 1000; // 20 min
function cacheGet(bc) {
    const e = _barcodeCache.get(bc);
    if (!e) return null;
    if (Date.now() - e.ts > CACHE_TTL) { _barcodeCache.delete(bc); return null; }
    return e.data;
}
function cacheSet(bc, data) { _barcodeCache.set(bc, { data, ts: Date.now() }); }
function cacheInvalidate(bc) { _barcodeCache.delete(bc); }

// ── Audio / haptic feedback ───────────────────────────────────────────────
let _audioCtx = null;
function _getAudioCtx() {
    if (!_audioCtx) _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    return _audioCtx;
}
function _beep(freq = 1200, ms = 80, type = 'square') {
    try {
        const ctx  = _getAudioCtx();
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.frequency.value = freq; osc.type = type;
        gain.gain.setValueAtTime(0.25, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + ms / 1000);
        osc.start(); osc.stop(ctx.currentTime + ms / 1000);
    } catch(e) {}
}
function _vib(pattern) { try { navigator.vibrate && navigator.vibrate(pattern); } catch(e) {} }
function fbSuccess()  { _vib(40);          _beep(1400, 60);               }
function fbError()    { _vib([40,20,40]);  _beep(360, 180, 'sawtooth');   }
function fbUnknown()  { _vib([30,20,30]);  _beep(700, 120, 'triangle');   }

// ── Scanner toggle (persisted per device) ────────────────────────────────
function toggleScanner() {
    scannerEnabled = !scannerEnabled;
    localStorage.setItem(LS_SCANNER_KEY, scannerEnabled ? '1' : '0');
    if (!scannerEnabled && camStream) stopCamera();
    updateScannerUI();
    flashMsg(scannerEnabled ? 'Barcode scanner enabled' : 'Barcode scanner disabled', !scannerEnabled);
}

function updateScannerUI() {
    const btn   = document.getElementById('scannerToggleBtn');
    const lbl   = document.getElementById('scannerToggleLbl');
    const strip = document.getElementById('scannerStrip');
    const cam   = document.getElementById('camToggle');
    const inp   = document.getElementById('manualInput');
    const sub   = document.getElementById('manualSubmitBtn');

    if (scannerEnabled) {
        btn.classList.add('active');
        lbl.textContent = 'Scanner: ON';
        strip.style.display = 'flex';
        strip.className = '';
        strip.innerHTML = '<i class="fas fa-circle" style="font-size:8px"></i> Barcode scanner active — camera or USB wedge';
        cam.style.display = '';
        inp.style.display = '';
        sub.style.display = '';
        // Auto-resume camera if it was active last session
        if (localStorage.getItem(LS_CAM_KEY) === '1' && !camStream) {
            setTimeout(toggleCamera, 400);
        }
    } else {
        btn.classList.remove('active');
        lbl.textContent = 'Scanner: OFF';
        strip.style.display = 'none';
        cam.style.display = 'none';
        cam.classList.remove('active');
        cam.innerHTML = '<i class="fas fa-camera"></i> Camera';
        document.getElementById('torchBtn').classList.remove('visible','active');
        inp.style.display = 'none';
        sub.style.display = 'none';
        document.getElementById('cameraZone').style.display = 'none';
        hideCameraError();
    }
}

// ── Camera helpers ────────────────────────────────────────────────────────
function isPWA() {
    return window.matchMedia('(display-mode: standalone)').matches
        || window.matchMedia('(display-mode: fullscreen)').matches
        || window.navigator.standalone === true;
}
function isIOS() { return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream; }

function showCameraError(title, hint, showRetry) {
    const msg = document.getElementById('camErrorMsg');
    const overlay = document.getElementById('scanOverlay');
    if (overlay) overlay.style.display = 'none';
    msg.innerHTML =
        '<i class="fas fa-camera-slash"></i>' +
        '<div class="cam-err-title">' + title + '</div>' +
        '<div class="cam-err-hint">' + hint + '</div>' +
        (showRetry ? '<button class="cam-err-retry" onclick="retryCamera()">Try Again</button>' : '');
    msg.style.display = 'flex';
    msg.style.flexDirection = 'column';
    msg.style.alignItems = 'center';
    document.getElementById('cameraZone').style.display = 'flex';
}

function hideCameraError() {
    const msg = document.getElementById('camErrorMsg');
    if (msg) msg.style.display = 'none';
    const overlay = document.getElementById('scanOverlay');
    if (overlay) overlay.style.display = '';
}

function permissionDeniedMsg() {
    if (isIOS() && isPWA()) {
        return 'Go to iOS Settings → scroll to Safari → Camera → Allow, then tap Try Again.';
    }
    if (isIOS()) {
        return 'Tap AA in the address bar → Website Settings → Camera → Allow, then tap Try Again.';
    }
    if (isPWA()) {
        return 'Go to Android Settings → Apps → find this app → Permissions → Camera → Allow, then tap Try Again.';
    }
    return 'Tap the lock / info icon in the address bar → Permissions → Camera → Allow, then tap Try Again below.';
}

function stopCamera() {
    camDetecting = false;
    if (camStream) {
        camStream.getTracks().forEach(t => t.stop());
        camStream = null;
    }
    const video = document.getElementById('camVideo');
    if (video) video.srcObject = null;
}

// ── Camera toggle ─────────────────────────────────────────────────────────
async function toggleCamera() {
    if (!scannerEnabled) return;
    const btn  = document.getElementById('camToggle');
    const zone = document.getElementById('cameraZone');
    const tBtn = document.getElementById('torchBtn');

    if (camStream) {
        stopCamera();
        localStorage.setItem(LS_CAM_KEY, '0');
        btn.classList.remove('active');
        btn.innerHTML = '<i class="fas fa-camera"></i> Camera';
        tBtn.classList.remove('visible','active');
        zone.style.display = 'none';
        hideCameraError();
        return;
    }

    // Wait up to 4 s for polyfill module to inject BarcodeDetector
    for (let i = 0; i < 8 && !('BarcodeDetector' in window); i++) {
        await new Promise(r => setTimeout(r, 500));
    }
    if (!('BarcodeDetector' in window)) {
        showCameraError('Barcode engine unavailable',
            'Could not load the barcode engine. Refresh the page or use the text input / USB scanner instead.', true);
        return;
    }
    if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
        showCameraError('HTTPS required', 'Camera access only works on secure (https://) connections.', false);
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showCameraError('Camera API unavailable', 'Use the text input or a Bluetooth scanner instead.', false);
        return;
    }

    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        // Lower resolution = faster barcode decode + less CPU
        const constraints = { video: { facingMode: { ideal: 'environment' }, width: { ideal: 640 }, height: { ideal: 480 } } };
        try {
            camStream = await navigator.mediaDevices.getUserMedia(constraints);
        } catch (e) {
            if (e.name === 'OverconstrainedError' || e.name === 'ConstraintNotSatisfiedError') {
                camStream = await navigator.mediaDevices.getUserMedia({ video: true });
            } else { throw e; }
        }

        const video = document.getElementById('camVideo');
        video.srcObject = camStream;
        hideCameraError();
        zone.style.display = 'flex';
        btn.classList.add('active');
        btn.innerHTML = '<i class="fas fa-stop"></i> Stop';
        localStorage.setItem(LS_CAM_KEY, '1');

        // Show torch button if device supports it
        const track = camStream.getVideoTracks()[0];
        if (track && track.getCapabilities && track.getCapabilities().torch) {
            tBtn.classList.add('visible');
        }

        detectLoop();
    } catch (e) {
        btn.innerHTML = '<i class="fas fa-camera"></i> Camera';
        btn.classList.remove('active');
        localStorage.setItem(LS_CAM_KEY, '0');
        if (e.name === 'NotAllowedError' || e.name === 'PermissionDeniedError') {
            showCameraError('Camera permission denied', permissionDeniedMsg(), true);
        } else if (e.name === 'NotFoundError' || e.name === 'DevicesNotFoundError') {
            showCameraError('No camera found', 'No camera detected on this device. Use the text input instead.', false);
        } else if (e.name === 'NotReadableError' || e.name === 'TrackStartError') {
            showCameraError('Camera busy', 'Camera is in use by another app. Close it and tap Try Again.', true);
        } else {
            showCameraError('Could not start camera', e.message || 'Try the text input instead.', true);
        }
        document.getElementById('manualInput').focus();
    }
}

function retryCamera() {
    hideCameraError();
    document.getElementById('cameraZone').style.display = 'none';
    toggleCamera();
}

// Torch toggle — uses MediaStreamTrack constraints
async function toggleTorch() {
    if (!camStream) return;
    const track = camStream.getVideoTracks()[0];
    if (!track) return;
    const caps = track.getCapabilities ? track.getCapabilities() : {};
    if (!caps.torch) { flashMsg('Torch not supported on this device', true); return; }
    const newTorch = !track.getSettings().torch;
    try {
        await track.applyConstraints({ advanced: [{ torch: newTorch }] });
        document.getElementById('torchBtn').classList.toggle('active', newTorch);
    } catch(e) { flashMsg('Could not toggle torch', true); }
}

// ── Detect loop — fire-and-forget (camera never pauses for server lookup) ──
async function detectLoop() {
    const detector = new BarcodeDetector({
        formats: ['ean_13','ean_8','code_128','code_39','code_93','upc_a','upc_e','qr_code','data_matrix']
    });
    const video = document.getElementById('camVideo');
    camDetecting = true;
    let lastCode = '', lastCodeAt = 0;
    while (camDetecting && camStream) {
        await new Promise(r => setTimeout(r, 200)); // 200 ms — fast enough, non-blocking
        try {
            if (!video.readyState || video.readyState < 2) continue;
            const codes = await detector.detect(video);
            if (!codes.length) continue;
            const code = codes[0].rawValue;
            const now = Date.now();
            if (code === lastCode && now - lastCodeAt < 2000) continue; // 2 s same-code cooldown
            lastCode = code; lastCodeAt = now;
            const statusEl = document.getElementById('scanStatus');
            if (statusEl) statusEl.textContent = '✓ ' + code;
            processBarcode(code); // ← no await; loop continues immediately
        } catch(e) { /* frame not ready or detector busy — continue */ }
    }
}

// ── Keyboard-wedge (USB/Bluetooth scanner) listener ──────────────────────
// Scanners type chars <30 ms apart then send Enter. 300 ms window catches
// even slower units; 600 ms reset drops keys typed manually.
let _kwBuf = '', _kwLast = 0;
document.addEventListener('keydown', e => {
    if (!scannerEnabled) return;
    if (e.ctrlKey || e.altKey || e.metaKey) return;
    const tag = (document.activeElement || {}).tagName || '';
    if (['INPUT','TEXTAREA','SELECT'].includes(tag)) return;
    const now = Date.now();
    if (e.key === 'Enter') {
        if (_kwBuf.length >= 3 && now - _kwLast < 300) processBarcode(_kwBuf);
        _kwBuf = ''; return;
    }
    if (e.key.length === 1) {
        if (now - _kwLast > 600) _kwBuf = '';
        _kwBuf += e.key; _kwLast = now;
    }
});

document.getElementById('manualInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); handleManualInput(); }
});

updateScannerUI();

function handleManualInput() {
    const inp = document.getElementById('manualInput');
    const v = inp.value.trim();
    if (v) { processBarcode(v); inp.value = ''; }
}

// ── Core barcode processing — non-blocking with cache ─────────────────────
// Only one server request in flight at a time; extras queue behind it.
// Cache hits are synchronous — zero network cost.
let _lookupInFlight = false;
let _lookupQueued   = null;

function processBarcode(barcode) {
    if (_modalOpen) return; // don't scan while register modal is open

    const cached = cacheGet(barcode);
    if (cached) { _handleResult(barcode, cached); return; }

    if (_lookupInFlight) { _lookupQueued = barcode; return; } // queue latest, drop older
    _lookupInFlight = true;

    _fetchLookup(barcode).then(data => {
        if (!data) return;
        cacheSet(barcode, data);
        _handleResult(barcode, data);
    }).finally(() => {
        _lookupInFlight = false;
        if (_lookupQueued) { const q = _lookupQueued; _lookupQueued = null; processBarcode(q); }
    });
}

async function _fetchLookup(barcode) {
    try {
        const fd = new FormData(); fd.append('barcode', barcode);
        const res = await fetch(PAGE + '?ajax=lookup_barcode', { method: 'POST', body: fd });
        return await res.json();
    } catch(e) { flashMsg('Network error looking up barcode', true); return null; }
}

function _handleResult(barcode, data) {
    if (data.found && data.type === 'ingredient') {
        fbSuccess();
        addToBatch(data.ingredient);
        flashMsg('+ ' + data.ingredient.name);
    } else if (data.found && data.type === 'pos_item') {
        fbSuccess();
        flashMsg('POS: ' + data.item.name + ' · <?php echo htmlspecialchars($currency); ?>' + formatPrice(data.item.price));
    } else {
        fbUnknown();
        openRegisterModal(barcode);
    }
}

function formatPrice(n) { return (typeof n === 'number' ? n : parseFloat(n) || 0).toFixed(2); }

// ── Batch management ──────────────────────────────────────────────────────
function addToBatch(ingredient) {
    const id = String(ingredient.ingredient_id);
    if (batch[id]) {
        // Already in batch — surgical DOM update, no full re-render
        batch[id].quantity    = Math.round((batch[id].quantity + (parseFloat(ingredient.pack_size) || 1)) * 10000) / 10000;
        batch[id].scanCount   = (batch[id].scanCount || 1) + 1;
        const qEl = document.getElementById('qty-' + id);
        if (qEl) {
            const q = batch[id].quantity;
            qEl.textContent = q % 1 === 0 ? q : q.toFixed(3);
            // Update scan badge
            const badge = document.getElementById('sbadge-' + id);
            if (badge) badge.textContent = batch[id].scanCount + '×';
            // Pulse animation
            const card = document.getElementById('batch-item-' + id);
            if (card) { card.classList.remove('pulse'); void card.offsetWidth; card.classList.add('pulse'); }
        } else { renderBatch(); }
        _updateTotals();
    } else {
        batch[id] = {
            ingredient_id: ingredient.ingredient_id,
            name:          ingredient.name,
            unit:          ingredient.unit,
            pack_size:     parseFloat(ingredient.pack_size)    || 1,
            pack_label:    ingredient.pack_label || ingredient.unit,
            quantity:      parseFloat(ingredient.pack_size)    || 1,
            cost_per_unit: parseFloat(ingredient.cost_per_unit)|| 0,
            scanCount:     1,
        };
        renderBatch();
    }
}

function _updateTotals() {
    const keys = Object.keys(batch);
    const totalQty = keys.reduce((s,k) => s + batch[k].quantity, 0);
    document.getElementById('batchTally').textContent    = keys.length + ' item' + (keys.length !== 1 ? 's' : '');
    document.getElementById('submitCount').textContent   = keys.length + ' line' + (keys.length !== 1 ? 's' : '') + ' · ' + totalQty.toFixed(2) + ' units total';
    document.getElementById('submitBtn').disabled        = keys.length === 0;
    document.getElementById('emptyState').style.display = keys.length ? 'none' : 'block';
}

function renderBatch() {
    const list = document.getElementById('batchList');
    list.querySelectorAll('.batch-item').forEach(el => el.remove());
    const keys = Object.keys(batch);
    _updateTotals();
    keys.forEach(id => {
        const b   = batch[id];
        const qty = b.quantity % 1 === 0 ? b.quantity : b.quantity.toFixed(3);
        const div = document.createElement('div');
        div.className = 'batch-item';
        div.id = 'batch-item-' + id;
        div.innerHTML = `
            <div class="batch-item-head">
                <div class="batch-item-icon"><i class="fas fa-box"></i></div>
                <div style="flex:1">
                    <div class="batch-item-name">${esc(b.name)}<span class="scan-badge" id="sbadge-${id}">${b.scanCount}×</span></div>
                    <div class="batch-item-sub">${esc(b.unit)} · pack: ${b.pack_size} ${esc(b.pack_label)}</div>
                </div>
                <button class="batch-item-remove" onclick="removeFromBatch(${id})" title="Remove"><i class="fas fa-times"></i></button>
            </div>
            <div class="batch-item-body">
                <div class="batch-field" style="flex:0 0 auto">
                    <label>Qty (${esc(b.unit)})</label>
                    <div class="batch-scan-count">
                        <button onclick="adjustQty(${id}, -${b.pack_size})">−</button>
                        <span class="qty-val" id="qty-${id}">${qty}</span>
                        <button onclick="adjustQty(${id}, ${b.pack_size})">+</button>
                    </div>
                </div>
                <div class="batch-field">
                    <label>Cost / ${esc(b.unit)}</label>
                    <input type="number" min="0" step="0.01" value="${b.cost_per_unit || ''}"
                        placeholder="0.00" oninput="updateCost(${id}, this.value)">
                </div>
            </div>`;
        list.appendChild(div);
    });
}

function removeFromBatch(id) { delete batch[id]; renderBatch(); }

function adjustQty(id, delta) {
    batch[id].quantity = Math.max(0.001, Math.round((batch[id].quantity + delta) * 10000) / 10000);
    const el = document.getElementById('qty-' + id);
    if (el) { const q = batch[id].quantity; el.textContent = q % 1 === 0 ? q : q.toFixed(3); }
    _updateTotals();
}

function updateCost(id, val) { batch[id].cost_per_unit = parseFloat(val) || 0; }

// ── Submit batch ──────────────────────────────────────────────────────────
async function submitBatch() {
    const keys = Object.keys(batch);
    if (!keys.length) return;
    const btn = document.getElementById('submitBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const items = keys.map(id => ({
        ingredient_id: batch[id].ingredient_id,
        quantity: batch[id].quantity,
        cost_per_unit: batch[id].cost_per_unit,
    }));

    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('items', JSON.stringify(items));
    fd.append('supplier', document.getElementById('supplierInput').value.trim());
    fd.append('received_date', document.getElementById('receivedDate').value);

    try {
        const res = await fetch(PAGE + '?ajax=receive_batch', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            stopCamera();
            batch = {};
            renderBatch();
            flashMsg('✓ ' + data.batches_created + ' batch' + (data.batches_created !== 1 ? 'es' : '') + ' received into stock!');
            btn.innerHTML = '<i class="fas fa-check"></i> Receive into Stock';
        } else {
            flashMsg(data.error || 'Submit failed', true);
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Receive into Stock';
        }
    } catch(e) {
        flashMsg('Network error — please try again', true);
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Receive into Stock';
    }
}

// ── Register modal ────────────────────────────────────────────────────────
let _ingSearchTimer = null;
let _catSearchTimer = null;
let _selectedIng = null;
let _regType = null;  // 'ingredient' | 'item'

function openRegisterModal(barcode) {
    pendingBarcode = barcode;
    // Reset to type picker
    selectRegType(null);
    document.getElementById('registerModalSub').textContent = barcode;
    // Clear ingredient form
    document.getElementById('regIngSearch').value = '';
    document.getElementById('regIngId').value = '';
    document.getElementById('regPackSize').value = '1';
    document.getElementById('regPackLabel').value = '';
    document.getElementById('registerIngErr').style.display = 'none';
    document.getElementById('ingResults').style.display = 'none';
    document.getElementById('registerIngSaveBtn').disabled = false;
    // Clear item form
    document.getElementById('regItemName').value = '';
    document.getElementById('regItemPrice').value = '';
    document.getElementById('regCatSearch').value = '';
    document.getElementById('regCatId').value = '';
    document.getElementById('registerItemErr').style.display = 'none';
    document.getElementById('catResults').style.display = 'none';
    document.getElementById('registerItemSaveBtn').disabled = false;
    _selectedIng = null;
    document.getElementById('registerModal').style.display = 'flex';
}

function selectRegType(type) {
    _regType = type;
    document.getElementById('regTypePicker').style.display = type ? 'none' : 'block';
    document.getElementById('regIngForm').style.display  = (type === 'ingredient') ? 'block' : 'none';
    document.getElementById('regItemForm').style.display = (type === 'item') ? 'block' : 'none';
    if (type === 'ingredient') setTimeout(() => document.getElementById('regIngSearch').focus(), 80);
    if (type === 'item')       setTimeout(() => document.getElementById('regItemName').focus(), 80);
}

function closeRegisterModal() {
    document.getElementById('registerModal').style.display = 'none';
    pendingBarcode = null;
    _regType = null;
}

// Ingredient search
function searchIngredients(q) {
    clearTimeout(_ingSearchTimer);
    const res = document.getElementById('ingResults');
    if (q.length < 1) { res.style.display = 'none'; return; }
    _ingSearchTimer = setTimeout(async () => {
        const r = await fetch(PAGE + '?ajax=search_ingredients&q=' + encodeURIComponent(q));
        const items = await r.json();
        if (!items.length) {
            res.innerHTML = '<div style="padding:12px 14px;color:var(--muted);font-size:13px;">No ingredients found</div>';
        } else {
            res.innerHTML = items.map(i =>
                `<div class="ing-result-item" data-id="${i.id}" data-name="${esc(i.name)}" data-unit="${esc(i.unit)}" data-category="${esc(i.category || '')}">
                    <div><div class="ing-name">${esc(i.name)}</div><div class="ing-meta">${esc(i.category || '')}</div></div>
                    <div class="ing-meta">${esc(i.unit)}</div>
                </div>`
            ).join('');
            res.querySelectorAll('.ing-result-item').forEach(el => {
                el.addEventListener('click', () => selectIngredient(el.dataset.id, el.dataset.name, el.dataset.unit, el.dataset.category));
            });
        }
        res.style.display = 'block';
    }, 250);
}

function selectIngredient(id, name, unit, category) {
    _selectedIng = { id, name, unit };
    document.getElementById('regIngId').value = id;
    document.getElementById('regIngSearch').value = name;
    document.getElementById('ingResults').style.display = 'none';
    if (!document.getElementById('regPackLabel').value) document.getElementById('regPackLabel').value = unit;
    document.getElementById('regPackSize').focus();
}

// Category search
function searchCategories(q) {
    clearTimeout(_catSearchTimer);
    const res = document.getElementById('catResults');
    if (q.length < 1) { res.style.display = 'none'; return; }
    _catSearchTimer = setTimeout(async () => {
        const r = await fetch(PAGE + '?ajax=search_categories&q=' + encodeURIComponent(q));
        const items = await r.json();
        if (!items.length) {
            res.innerHTML = '<div style="padding:12px 14px;color:var(--muted);font-size:13px;">No categories found</div>';
        } else {
            res.innerHTML = items.map(i =>
                `<div class="ing-result-item" data-id="${i.id}" data-name="${esc(i.name)}">
                    <div class="ing-name">${esc(i.name)}</div>
                </div>`
            ).join('');
            res.querySelectorAll('.ing-result-item').forEach(el => {
                el.addEventListener('click', () => {
                    document.getElementById('regCatId').value = el.dataset.id;
                    document.getElementById('regCatSearch').value = el.dataset.name;
                    res.style.display = 'none';
                });
            });
        }
        res.style.display = 'block';
    }, 250);
}

// Save ingredient barcode
async function saveBarcode() {
    const barcode   = pendingBarcode;
    const ingId     = document.getElementById('regIngId').value;
    const packSize  = parseFloat(document.getElementById('regPackSize').value) || 1;
    const packLabel = document.getElementById('regPackLabel').value.trim();
    const errEl     = document.getElementById('registerIngErr');
    const btn       = document.getElementById('registerIngSaveBtn');

    if (!ingId) { errEl.textContent = 'Please select an ingredient.'; errEl.style.display = 'block'; return; }
    btn.disabled = true; btn.textContent = 'Saving…';

    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('barcode', barcode);
    fd.append('ingredient_id', ingId);
    fd.append('pack_size', packSize);
    fd.append('pack_label', packLabel);

    try {
        const res  = await fetch(PAGE + '?ajax=register_barcode', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            closeRegisterModal();
            addToBatch(data.ingredient);
            flashMsg('Registered & added: ' + data.ingredient.name);
        } else {
            errEl.textContent = data.error || 'Save failed.';
            errEl.style.display = 'block';
            btn.disabled = false; btn.textContent = 'Save & Add to Batch';
        }
    } catch(e) {
        errEl.textContent = 'Network error.'; errEl.style.display = 'block';
        btn.disabled = false; btn.textContent = 'Save & Add to Batch';
    }
}

// Save POS item
async function saveItem() {
    const barcode  = pendingBarcode;
    const name     = document.getElementById('regItemName').value.trim();
    const price    = document.getElementById('regItemPrice').value.trim();
    const catId    = document.getElementById('regCatId').value;
    const errEl    = document.getElementById('registerItemErr');
    const btn      = document.getElementById('registerItemSaveBtn');

    if (!name) { errEl.textContent = 'Please enter an item name.'; errEl.style.display = 'block'; return; }
    if (price === '' || isNaN(parseFloat(price))) { errEl.textContent = 'Please enter a sale price.'; errEl.style.display = 'block'; return; }
    btn.disabled = true; btn.textContent = 'Saving…';

    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('barcode', barcode);
    fd.append('name', name);
    fd.append('price', price);
    fd.append('category_id', catId || '');

    try {
        const res  = await fetch(PAGE + '?ajax=register_item', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            closeRegisterModal();
            flashMsg('✓ ' + data.item.name + ' registered on POS at ' + formatPrice(data.item.price));
        } else {
            errEl.textContent = data.error || 'Save failed.';
            errEl.style.display = 'block';
            btn.disabled = false; btn.textContent = 'Register on POS';
        }
    } catch(e) {
        errEl.textContent = 'Network error.'; errEl.style.display = 'block';
        btn.disabled = false; btn.textContent = 'Register on POS';
    }
}

// ── Flash message ─────────────────────────────────────────────────────────
let _flashTimer;
function flashMsg(msg, isError = false) {
    const el = document.getElementById('scanFlash');
    el.textContent = msg;
    el.className = 'scan-flash' + (isError ? ' error' : '');
    el.classList.add('show');
    clearTimeout(_flashTimer);
    _flashTimer = setTimeout(() => el.classList.remove('show'), 2200);
}

function esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// Close modal when tapping overlay
document.getElementById('registerModal').addEventListener('click', function(e) {
    if (e.target === this) closeRegisterModal();
});
</script>
</body>
</html>
