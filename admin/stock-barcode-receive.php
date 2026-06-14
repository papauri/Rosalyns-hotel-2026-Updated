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

    // lookup_barcode — find ingredient mapped to a barcode
    if ($_GET['ajax'] === 'lookup_barcode') {
        $barcode = trim($_POST['barcode'] ?? '');
        if ($barcode === '') { echo json_encode(['found' => false]); exit; }
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
            echo json_encode(['found' => true, 'ingredient' => $row]);
        } else {
            echo json_encode(['found' => false, 'barcode' => $barcode]);
        }
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
            echo json_encode(['ok' => true, 'ingredient' => array_merge($ingredient, ['pack_size' => $packSize, 'pack_label' => $packLabel, 'barcode' => $barcode])]);
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
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0f1117;--surface:#1a1d27;--surface2:#22263a;--border:#2e3350;
  --primary:#4f8ef7;--success:#22c55e;--warn:#f59e0b;--danger:#ef4444;
  --text:#f1f5f9;--muted:#64748b;--radius:12px;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'Jost',sans-serif;font-size:15px;overscroll-behavior:none}
a{color:var(--primary);text-decoration:none}

/* ── Top bar ── */
.topbar{display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.topbar-back{width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:var(--surface2);color:var(--text);font-size:16px;border:none;cursor:pointer}
.topbar-title{flex:1;font-size:16px;font-weight:600}
.topbar-stats{font-size:12px;color:var(--muted);text-align:right;line-height:1.4}

/* ── Camera zone ── */
.camera-zone{position:relative;background:#000;width:100%;max-height:240px;overflow:hidden;display:flex;align-items:center;justify-content:center}
.camera-zone video{width:100%;max-height:240px;object-fit:cover;display:block}
.scan-overlay{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none}
.scan-frame{width:200px;height:100px;border:2px solid var(--primary);border-radius:8px;box-shadow:0 0 0 9999px rgba(0,0,0,.45)}
.scan-line{position:absolute;width:180px;height:2px;background:var(--primary);opacity:.8;animation:scanline 1.8s ease-in-out infinite}
@keyframes scanline{0%{top:calc(50% - 45px)}100%{top:calc(50% + 43px)}}
.scan-status{position:absolute;bottom:10px;background:rgba(0,0,0,.7);border-radius:20px;padding:4px 14px;font-size:12px;color:#fff}

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
.cam-btn.active{background:#1e3a5f;border-color:var(--primary);color:var(--primary)}
.scanner-toggle-btn{display:flex;align-items:center;gap:8px;padding:9px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .2s}
.scanner-toggle-btn.active{background:#1a2e1a;border-color:var(--success);color:var(--success)}

/* ── Scanner status strip ── */
#scannerStrip{display:none;align-items:center;justify-content:center;gap:8px;padding:8px 16px;background:#0d1f0d;border-bottom:1px solid #1a3a1a;font-size:12px;color:var(--success)}
#scannerStrip.off{display:flex;background:#1f1a0d;border-color:#3a2a00;color:var(--warn)}

/* ── Batch list ── */
.section-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px 6px;font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.batch-list{padding:0 16px 120px}
.batch-empty{text-align:center;padding:40px 20px;color:var(--muted)}
.batch-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.4}
.batch-item{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:10px;overflow:hidden}
.batch-item-head{display:flex;align-items:center;gap:10px;padding:12px 14px}
.batch-item-icon{width:36px;height:36px;background:var(--surface2);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:14px;flex-shrink:0}
.batch-item-name{flex:1;font-weight:600;font-size:14px;line-height:1.3}
.batch-item-sub{font-size:11px;color:var(--muted);margin-top:2px}
.batch-item-remove{width:32px;height:32px;background:none;border:none;color:var(--muted);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;border-radius:6px}
.batch-item-remove:hover{color:var(--danger);background:#2a1a1a}
.batch-item-body{padding:0 14px 12px;display:flex;gap:8px;flex-wrap:wrap}
.batch-field{display:flex;flex-direction:column;gap:4px;flex:1;min-width:90px}
.batch-field label{font-size:11px;color:var(--muted);font-weight:500}
.batch-field input{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 10px;color:var(--text);font-size:14px;font-family:inherit;outline:none;width:100%}
.batch-field input:focus{border-color:var(--primary)}
.batch-scan-count{display:inline-flex;align-items:center;gap:6px;background:var(--surface2);border-radius:20px;padding:3px 10px;font-size:12px;color:var(--muted);margin-top:4px}
.batch-scan-count button{background:none;border:none;color:var(--text);font-size:16px;cursor:pointer;width:24px;height:24px;display:flex;align-items:center;justify-content:center;border-radius:50%}
.batch-scan-count button:hover{background:var(--border)}
.batch-scan-count .qty-val{min-width:32px;text-align:center;font-weight:700;color:var(--text);font-size:15px}

/* ── Submit bar ── */
.submit-bar{position:fixed;bottom:0;left:0;right:0;padding:12px 16px;background:var(--surface);border-top:1px solid var(--border);z-index:100;display:flex;gap:10px;align-items:center}
.submit-btn{flex:1;padding:14px;background:var(--success);border:none;border-radius:var(--radius);color:#fff;font-size:16px;font-weight:700;cursor:pointer;font-family:inherit}
.submit-btn:disabled{opacity:.4;cursor:not-allowed}
.submit-count{font-size:13px;color:var(--muted);white-space:nowrap}

/* ── Flash ── */
.scan-flash{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--success);color:#fff;padding:14px 28px;border-radius:12px;font-size:15px;font-weight:700;z-index:9999;pointer-events:none;transition:opacity .3s;opacity:0}
.scan-flash.show{opacity:1}
.scan-flash.error{background:var(--danger)}

/* ── Modal overlay ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1000;display:flex;align-items:flex-end;justify-content:center}
.modal-sheet{background:var(--surface);border-radius:20px 20px 0 0;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;padding:20px 16px 32px}
.modal-handle{width:40px;height:4px;background:var(--border);border-radius:2px;margin:0 auto 16px}
.modal-title{font-size:16px;font-weight:700;margin-bottom:4px}
.modal-sub{font-size:13px;color:var(--muted);margin-bottom:16px}
.modal-field{margin-bottom:14px}
.modal-field label{display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
.modal-field input,.modal-field select{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:11px 14px;color:var(--text);font-size:15px;font-family:inherit;outline:none}
.modal-field input:focus,.modal-field select:focus{border-color:var(--primary)}
.ing-results{background:var(--surface2);border:1px solid var(--border);border-radius:8px;margin-top:4px;max-height:180px;overflow-y:auto;display:none}
.ing-result-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.ing-result-item:last-child{border-bottom:none}
.ing-result-item:hover{background:var(--border)}
.ing-result-item .ing-name{font-weight:600;font-size:14px}
.ing-result-item .ing-meta{font-size:12px;color:var(--muted)}
.modal-actions{display:flex;gap:10px;margin-top:20px}
.modal-btn{flex:1;padding:13px;border-radius:10px;border:none;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit}
.modal-btn-primary{background:var(--primary);color:#fff}
.modal-btn-secondary{background:var(--surface2);color:var(--text)}
.modal-btn:disabled{opacity:.4;cursor:not-allowed}
.modal-err{color:var(--danger);font-size:13px;margin-top:8px;display:none}
</style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
    <button class="topbar-back" onclick="history.back()"><i class="fas fa-arrow-left"></i></button>
    <div class="topbar-title"><i class="fas fa-barcode" style="color:var(--primary);margin-right:8px"></i>Receive Stock</div>
    <div class="topbar-stats"><?php echo $barcodeCount; ?> barcodes<br><?php echo $ingredientCount; ?> ingredients</div>
</div>

<!-- Camera zone (content injected by JS) -->
<div class="camera-zone" id="cameraZone" style="display:none"></div>
<video id="camVideo" autoplay playsinline muted style="display:none;position:absolute;pointer-events:none"></video>

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
        <div class="modal-title">Register Barcode</div>
        <div class="modal-sub" id="registerModalSub">Unknown barcode — link it to an ingredient once and it will be recognised forever.</div>

        <div class="modal-field">
            <label>Barcode</label>
            <input type="text" id="regBarcode" readonly style="opacity:.6">
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
        <div class="modal-err" id="registerErr"></div>
        <div class="modal-actions">
            <button class="modal-btn modal-btn-secondary" onclick="closeRegisterModal()">Cancel</button>
            <button class="modal-btn modal-btn-primary" id="registerSaveBtn" onclick="saveBarcode()">Save &amp; Add</button>
        </div>
    </div>
</div>

<script>
const CSRF = <?php echo json_encode($csrf_token); ?>;
const PAGE = 'stock-barcode-receive.php';
const LS_SCANNER_KEY = 'sbr_scanner_enabled';

// ── State ─────────────────────────────────────────────────────────────────
let batch = {};        // ingredient_id → { ingredient, quantity, cost_per_unit }
let camStream = null;
let camDetecting = false;
let pendingBarcode = null;
let scannerEnabled = localStorage.getItem(LS_SCANNER_KEY) === '1';

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
        strip.innerHTML = '<i class="fas fa-circle" style="font-size:8px"></i> Barcode scanner active — camera or keyboard wedge';
        cam.style.display = '';
        inp.style.display = '';
        sub.style.display = '';
    } else {
        btn.classList.remove('active');
        lbl.textContent = 'Scanner: OFF';
        strip.style.display = 'none';
        cam.style.display = 'none';
        inp.style.display = 'none';
        sub.style.display = 'none';
        document.getElementById('cameraZone').style.display = 'none';
    }
}

// ── Camera (BarcodeDetector API) ──────────────────────────────────────────
async function toggleCamera() {
    if (!scannerEnabled) return;
    const btn = document.getElementById('camToggle');

    // Turn off
    if (camStream) {
        stopCamera();
        btn.classList.remove('active');
        btn.innerHTML = '<i class="fas fa-camera"></i> Camera';
        document.getElementById('cameraZone').style.display = 'none';
        return;
    }

    // Pre-flight checks
    if (!('BarcodeDetector' in window)) {
        showCameraError(
            'Camera barcode scanning is not supported on this browser.',
            'Use Chrome on Android, or type / scan into the input field below.'
        );
        return;
    }
    if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
        showCameraError(
            'Camera requires a secure (HTTPS) connection.',
            'Use the input field below — a USB or Bluetooth scanner will work there.'
        );
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showCameraError(
            'Camera API not available.',
            'Use the input field below instead.'
        );
        return;
    }

    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    async function startStream(constraints) {
        camStream = await navigator.mediaDevices.getUserMedia(constraints);
        const video = document.getElementById('camVideo');
        video.srcObject = camStream;
        video.style.display = '';
        video.style.position = '';
        const zone = document.getElementById('cameraZone');
        zone.innerHTML = '';
        zone.appendChild(video);
        zone.appendChild(buildScanOverlay());
        zone.style.display = 'flex';
        btn.classList.add('active');
        btn.innerHTML = '<i class="fas fa-stop"></i> Stop';
        detectLoop();
    }

    try {
        // Prefer rear camera
        await startStream({ video: { facingMode: { ideal: 'environment' } } });
    } catch (e) {
        if (e.name === 'OverconstrainedError' || e.name === 'ConstraintNotSatisfiedError') {
            // Device only has front camera — retry without constraint
            try { await startStream({ video: true }); return; } catch (e2) { e = e2; }
        }
        btn.innerHTML = '<i class="fas fa-camera"></i> Camera';
        btn.classList.remove('active');
        if (e.name === 'NotAllowedError' || e.name === 'PermissionDeniedError') {
            showCameraError(
                'Camera permission denied.',
                'Tap the camera icon in your browser address bar to allow access, then tap Camera again.'
            );
        } else if (e.name === 'NotFoundError' || e.name === 'DevicesNotFoundError') {
            showCameraError(
                'No camera found on this device.',
                'Use the input field — a Bluetooth or USB scanner will work there.'
            );
        } else if (e.name === 'NotReadableError' || e.name === 'TrackStartError') {
            showCameraError(
                'Camera is in use by another app.',
                'Close the other app and try again.'
            );
        } else {
            showCameraError('Could not start camera.', e.message || 'Try the input field instead.');
        }
        document.getElementById('manualInput').focus();
    }
}

function buildScanOverlay() {
    const overlay = document.createElement('div');
    overlay.className = 'scan-overlay';
    overlay.innerHTML = '<div class="scan-frame"></div><div class="scan-line" id="scanLine"></div><div class="scan-status" id="scanStatus">Point camera at barcode</div>';
    return overlay;
}

function showCameraError(title, hint) {
    const zone = document.getElementById('cameraZone');
    zone.innerHTML =
        '<div style="text-align:center;padding:28px 20px;color:#fff;width:100%">' +
        '<i class="fas fa-camera-slash" style="font-size:30px;opacity:.4;display:block;margin-bottom:12px"></i>' +
        '<div style="font-size:13px;font-weight:600;margin-bottom:6px">' + title + '</div>' +
        '<div style="font-size:12px;opacity:.6;line-height:1.5">' + hint + '</div>' +
        '</div>';
    zone.style.display = 'flex';
    setTimeout(function () { zone.style.display = 'none'; }, 7000);
}

function stopCamera() {
    camDetecting = false;
    if (camStream) { camStream.getTracks().forEach(t => t.stop()); camStream = null; }
    // Move video back to its hidden holding spot
    const video = document.getElementById('camVideo');
    if (video) { video.srcObject = null; video.style.display = 'none'; video.style.position = 'absolute'; document.body.appendChild(video); }
}

async function detectLoop() {
    const detector = new BarcodeDetector({ formats: ['ean_13','ean_8','code_128','code_39','upc_a','upc_e','itf','qr_code'] });
    camDetecting = true;
    let lastCode = '', lastCodeAt = 0;
    while (camDetecting && camStream) {
        await new Promise(r => setTimeout(r, 400));
        try {
            const video = document.getElementById('camVideo');
            if (!video || !video.readyState || video.readyState < 2) continue;
            const codes = await detector.detect(video);
            if (!codes.length) continue;
            const code = codes[0].rawValue;
            const now = Date.now();
            if (code === lastCode && now - lastCodeAt < 3000) continue;
            lastCode = code; lastCodeAt = now;
            const statusEl = document.getElementById('scanStatus');
            if (statusEl) statusEl.textContent = 'Scanned: ' + code;
            await processBarcode(code);
        } catch(e) { /* frame not ready */ }
    }
}

// ── Keyboard-wedge (fast-type) listener ──────────────────────────────────
let _kwBuf = '', _kwLast = 0;
document.addEventListener('keydown', e => {
    if (!scannerEnabled) return;
    const tag = (document.activeElement || {}).tagName || '';
    if (['INPUT','TEXTAREA','SELECT'].includes(tag)) return;
    const now = Date.now();
    if (e.key === 'Enter') {
        if (_kwBuf.length >= 3 && now - _kwLast < 250) processBarcode(_kwBuf);
        _kwBuf = ''; return;
    }
    if (e.key.length === 1) {
        if (now - _kwLast > 500) _kwBuf = '';
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

// ── Core barcode processing ───────────────────────────────────────────────
async function processBarcode(barcode) {
    const fd = new FormData();
    fd.append('barcode', barcode);
    const res = await fetch(PAGE + '?ajax=lookup_barcode', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.found) {
        addToBatch(data.ingredient);
        flashMsg('Added: ' + data.ingredient.name);
    } else {
        openRegisterModal(barcode);
    }
}

// ── Batch management ──────────────────────────────────────────────────────
function addToBatch(ingredient) {
    const id = ingredient.ingredient_id;
    if (batch[id]) {
        batch[id].quantity += parseFloat(ingredient.pack_size) || 1;
    } else {
        batch[id] = {
            ingredient_id: id,
            name: ingredient.name,
            unit: ingredient.unit,
            pack_size: parseFloat(ingredient.pack_size) || 1,
            pack_label: ingredient.pack_label || ingredient.unit,
            quantity: parseFloat(ingredient.pack_size) || 1,
            cost_per_unit: parseFloat(ingredient.cost_per_unit) || 0,
        };
    }
    renderBatch();
}

function renderBatch() {
    const list = document.getElementById('batchList');
    const keys = Object.keys(batch);
    document.getElementById('emptyState').style.display = keys.length ? 'none' : 'block';
    document.getElementById('submitBtn').disabled = keys.length === 0;

    const totalQty = keys.reduce((s, k) => s + batch[k].quantity, 0);
    document.getElementById('batchTally').textContent = keys.length + ' item' + (keys.length !== 1 ? 's' : '');
    document.getElementById('submitCount').textContent = keys.length + ' line' + (keys.length !== 1 ? 's' : '') + ' · ' + totalQty.toFixed(2) + ' units total';

    // Remove existing cards (not the empty state)
    list.querySelectorAll('.batch-item').forEach(el => el.remove());

    keys.forEach(id => {
        const b = batch[id];
        const div = document.createElement('div');
        div.className = 'batch-item';
        div.id = 'batch-item-' + id;
        div.innerHTML = `
            <div class="batch-item-head">
                <div class="batch-item-icon"><i class="fas fa-box"></i></div>
                <div style="flex:1">
                    <div class="batch-item-name">${esc(b.name)}</div>
                    <div class="batch-item-sub">${esc(b.unit)} · pack: ${b.pack_size} ${esc(b.pack_label)}</div>
                </div>
                <button class="batch-item-remove" onclick="removeFromBatch(${id})" title="Remove"><i class="fas fa-times"></i></button>
            </div>
            <div class="batch-item-body">
                <div class="batch-field" style="flex:0 0 auto">
                    <label>Quantity (${esc(b.unit)})</label>
                    <div class="batch-scan-count">
                        <button onclick="adjustQty(${id}, -${b.pack_size})">−</button>
                        <span class="qty-val" id="qty-${id}">${b.quantity % 1 === 0 ? b.quantity : b.quantity.toFixed(3)}</span>
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
    if (el) el.textContent = batch[id].quantity % 1 === 0 ? batch[id].quantity : batch[id].quantity.toFixed(3);
    const keys = Object.keys(batch);
    const totalQty = keys.reduce((s, k) => s + batch[k].quantity, 0);
    document.getElementById('submitCount').textContent = keys.length + ' line' + (keys.length !== 1 ? 's' : '') + ' · ' + totalQty.toFixed(2) + ' units total';
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
let _selectedIng = null;

function openRegisterModal(barcode) {
    pendingBarcode = barcode;
    document.getElementById('regBarcode').value = barcode;
    document.getElementById('regIngSearch').value = '';
    document.getElementById('regIngId').value = '';
    document.getElementById('regPackSize').value = '1';
    document.getElementById('regPackLabel').value = '';
    document.getElementById('registerErr').style.display = 'none';
    document.getElementById('ingResults').style.display = 'none';
    document.getElementById('registerSaveBtn').disabled = false;
    _selectedIng = null;
    document.getElementById('registerModal').style.display = 'flex';
    setTimeout(() => document.getElementById('regIngSearch').focus(), 100);
}

function closeRegisterModal() {
    document.getElementById('registerModal').style.display = 'none';
    pendingBarcode = null;
}

function searchIngredients(q) {
    clearTimeout(_ingSearchTimer);
    const res = document.getElementById('ingResults');
    if (q.length < 1) { res.style.display = 'none'; return; }
    _ingSearchTimer = setTimeout(async () => {
        const r = await fetch(PAGE + '?ajax=search_ingredients&q=' + encodeURIComponent(q));
        const items = await r.json();
        if (!items.length) { res.innerHTML = '<div style="padding:12px 14px;color:var(--muted);font-size:13px;">No ingredients found</div>'; }
        else {
            res.innerHTML = items.map(i =>
                `<div class="ing-result-item" data-id="${i.id}" data-name="${esc(i.name)}" data-unit="${esc(i.unit)}" data-category="${esc(i.category)}">
                    <div><div class="ing-name">${esc(i.name)}</div><div class="ing-meta">${esc(i.category)}</div></div>
                    <div class="ing-meta">${esc(i.unit)}</div>
                </div>`
            ).join('');
            res.querySelectorAll('.ing-result-item').forEach(el => {
                el.addEventListener('click', () => selectIngredient(
                    el.dataset.id, el.dataset.name, el.dataset.unit, el.dataset.category
                ));
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
    if (!document.getElementById('regPackLabel').value) {
        document.getElementById('regPackLabel').value = unit;
    }
    document.getElementById('regPackSize').focus();
}

async function saveBarcode() {
    const barcode = document.getElementById('regBarcode').value;
    const ingId   = document.getElementById('regIngId').value;
    const packSize = parseFloat(document.getElementById('regPackSize').value) || 1;
    const packLabel = document.getElementById('regPackLabel').value.trim();
    const errEl   = document.getElementById('registerErr');
    const btn     = document.getElementById('registerSaveBtn');

    if (!ingId) { errEl.textContent = 'Please select an ingredient.'; errEl.style.display = 'block'; return; }
    btn.disabled = true; btn.textContent = 'Saving…';

    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('barcode', barcode);
    fd.append('ingredient_id', ingId);
    fd.append('pack_size', packSize);
    fd.append('pack_label', packLabel);

    try {
        const res = await fetch(PAGE + '?ajax=register_barcode', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            closeRegisterModal();
            addToBatch(data.ingredient);
            flashMsg('Registered & added: ' + data.ingredient.name);
        } else {
            errEl.textContent = data.error || 'Save failed.';
            errEl.style.display = 'block';
            btn.disabled = false; btn.textContent = 'Save & Add';
        }
    } catch(e) {
        errEl.textContent = 'Network error.'; errEl.style.display = 'block';
        btn.disabled = false; btn.textContent = 'Save & Add';
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
