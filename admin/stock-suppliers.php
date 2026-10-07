<?php

/**
 * Stock Management — Suppliers
 *
 * Supplier master (CRUD) for procurement. Replaces the previous free-text
 * supplier_name approach with a proper master that batches, stock-in and
 * purchase orders link to by supplier_id. Self-heals the procurement schema
 * and backfills suppliers from historical free-text names on first load.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once 'includes/procurement-schema.php';
require_once __DIR__ . '/../includes/form-validation.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);

if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created.';
} else {
    ensureProcurementSchema($pdo);
    // One-time migration of historical free-text supplier names into the master.
    // It used to run on every page load, which re-created deleted or renamed
    // suppliers from the names still printed on old deliveries. Now it runs once;
    // later one-off ("Other") names stay free text unless added here deliberately.
    if ((string)getSetting('stock_supplier_backfill_done', '') !== '1') {
        try {
            rh_backfill_suppliers_from_batches($pdo);
            updateSetting('stock_supplier_backfill_done', '1');
        } catch (Throwable $e) { /* non-fatal; retried next load */ }
    }
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security token invalid.';
    } else {
        try {
            $action = $_POST['action'] ?? '';

            if ($action === 'save') {
                $id           = (int)($_POST['id'] ?? 0);
                $name         = rh_clean_text($_POST['name'] ?? '');
                $contactName  = rh_clean_text($_POST['contact_name'] ?? '');
                $email        = strtolower(rh_clean_text($_POST['email'] ?? ''));
                $phone        = rh_clean_text($_POST['phone'] ?? '');
                $address      = rh_clean_text($_POST['address'] ?? '');
                $leadRaw      = trim((string)($_POST['lead_time_days'] ?? '0'));
                $paymentTerms = rh_clean_text($_POST['payment_terms'] ?? '');
                $accountRef   = rh_clean_text($_POST['account_ref'] ?? '');
                $notes        = trim((string)($_POST['notes'] ?? ''));
                $isActive     = isset($_POST['is_active']) ? 1 : 0;

                if ($name === '') {
                    throw new RuntimeException('Supplier name is required.');
                }
                if (mb_strlen($name) > 255 || mb_strlen($contactName) > 255 || mb_strlen($email) > 255) {
                    throw new RuntimeException('Name, contact and email must be 255 characters or fewer.');
                }
                if (mb_strlen($phone) > 60 || mb_strlen($address) > 500 || mb_strlen($paymentTerms) > 100 || mb_strlen($accountRef) > 100) {
                    throw new RuntimeException('One of the fields is too long (phone 60, address 500, payment terms 100, account ref 100 characters).');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Enter a valid email address.');
                }
                if ($phone !== '' && !preg_match('/^[0-9+()\-.\s\/#*xX]{5,60}$/', $phone)) {
                    throw new RuntimeException('Enter a valid phone number (digits, spaces, + ( ) - only).');
                }
                if ($leadRaw !== '' && (!ctype_digit($leadRaw) || (int)$leadRaw > 365)) {
                    throw new RuntimeException('Lead time must be a whole number of days (0 to 365).');
                }
                $leadTime = (int)$leadRaw;
                if ($id < 0) {
                    throw new RuntimeException('Invalid supplier.');
                }

                // Natural key: supplier name, ignoring case and extra spaces (excluding self).
                // Check + write run under one lock so a double click cannot create two.
                rh_with_create_lock($pdo, 'stock_supplier', function () use ($pdo, $id, $name, $contactName, $email, $phone, $address, $leadTime, $paymentTerms, $accountRef, $notes, $isActive, $user, &$message) {
                $dup = rh_find_duplicate($pdo, 'stock_suppliers', 'name', $name, [], $id > 0 ? $id : null);
                if ($dup) {
                    throw new RuntimeException(rh_duplicate_message('supplier', (string)$dup['value']));
                }

                if ($id > 0) {
                    $pdo->prepare("
                        UPDATE stock_suppliers
                           SET name = ?, contact_name = ?, email = ?, phone = ?, address = ?,
                               lead_time_days = ?, payment_terms = ?, account_ref = ?, notes = ?, is_active = ?
                         WHERE id = ?
                    ")->execute([
                        $name, $contactName ?: null, $email ?: null, $phone ?: null, $address ?: null,
                        $leadTime, $paymentTerms ?: null, $accountRef ?: null, $notes ?: null, $isActive, $id
                    ]);
                    $message = 'Supplier updated.';
                } else {
                    $pdo->prepare("
                        INSERT INTO stock_suppliers
                            (name, contact_name, email, phone, address, lead_time_days, payment_terms, account_ref, notes, is_active, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $name, $contactName ?: null, $email ?: null, $phone ?: null, $address ?: null,
                        $leadTime, $paymentTerms ?: null, $accountRef ?: null, $notes ?: null, $isActive, $user['id']
                    ]);
                    $message = 'Supplier added.';
                }
                });
            } elseif ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                $pdo->prepare("UPDATE stock_suppliers SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
                $message = 'Supplier status updated.';
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $sup = $pdo->prepare("SELECT id, name FROM stock_suppliers WHERE id = ?");
                $sup->execute([$id]);
                $supRow = $sup->fetch(PDO::FETCH_ASSOC);
                if (!$supRow) {
                    throw new RuntimeException('Supplier not found — it may already have been deleted.');
                }

                // Purchase orders only reference the supplier by id (no name copy), so
                // deleting a supplier on a PO would turn those orders into "Unassigned".
                // Those suppliers must be deactivated instead.
                $usage = rh_supplier_usage($pdo, $id);
                if ($usage['purchase_orders'] > 0) {
                    throw new RuntimeException('"' . $supRow['name'] . '" can\'t be deleted because it is on ' . $usage['purchase_orders']
                        . ' purchase order' . ($usage['purchase_orders'] === 1 ? '' : 's')
                        . '. Deactivate it instead — it disappears from supplier lists but the orders keep their supplier.');
                }

                // Deliveries keep the supplier's name as text on each batch / stock-in
                // row, so stock history stays readable; they are just unlinked from the
                // master record. Preferred-supplier defaults become unassigned.
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE stock_ingredients SET preferred_supplier_id = NULL WHERE preferred_supplier_id = ?")->execute([$id]);
                if (rh_column_exists($pdo, 'stock_batches', 'supplier_id')) {
                    $pdo->prepare("UPDATE stock_batches SET supplier_name = COALESCE(NULLIF(TRIM(supplier_name), ''), ?), supplier_id = NULL WHERE supplier_id = ?")
                        ->execute([$supRow['name'], $id]);
                }
                if (rh_column_exists($pdo, 'stock_in_log', 'supplier_id')) {
                    $pdo->prepare("UPDATE stock_in_log SET supplier_name = COALESCE(NULLIF(TRIM(supplier_name), ''), ?), supplier_id = NULL WHERE supplier_id = ?")
                        ->execute([$supRow['name'], $id]);
                }
                $pdo->prepare("DELETE FROM stock_suppliers WHERE id = ?")->execute([$id]);
                $pdo->commit();

                if (function_exists('logActivity')) {
                    logActivity((int)$user['id'], 'supplier_deleted', 'Deleted supplier "' . $supRow['name'] . '" (#' . $id . ')'
                        . ($usage['deliveries'] > 0 ? '; ' . $usage['deliveries'] . ' past deliveries kept with the name as text' : '')
                        . ($usage['preferred_items'] > 0 ? '; unassigned from ' . $usage['preferred_items'] . ' item(s)' : ''));
                }
                $message = 'Supplier "' . $supRow['name'] . '" deleted.'
                    . ($usage['deliveries'] > 0 ? ' Its ' . $usage['deliveries'] . ' past deliveries stay in stock history under its name.' : '')
                    . ($usage['preferred_items'] > 0 ? ' ' . $usage['preferred_items'] . ' item(s) that used it as preferred supplier are now unassigned.' : '');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
    if ($message) $_SESSION['stock_msg'] = $message;
    if ($error)   $_SESSION['stock_err'] = $error;
    header('Location: stock-suppliers.php');
    exit;
}

if (!empty($_SESSION['stock_msg'])) { $message = $_SESSION['stock_msg']; unset($_SESSION['stock_msg']); }
if (!empty($_SESSION['stock_err'])) { $error   = $_SESSION['stock_err']; unset($_SESSION['stock_err']); }

$suppliers = [];
$stats = ['total' => 0, 'active' => 0];
if (!$error || strpos($error, 'not yet') === false) {
    try {
        $suppliers = $pdo->query("
            SELECT s.*,
                   (SELECT COUNT(*) FROM stock_ingredients i WHERE i.preferred_supplier_id = s.id AND i.is_archived = 0) AS preferred_count,
                   (SELECT COALESCE(SUM(sil.cost_total), 0) FROM stock_in_log sil WHERE sil.supplier_id = s.id) AS total_purchased,
                   (SELECT MAX(sil.created_at) FROM stock_in_log sil WHERE sil.supplier_id = s.id) AS last_received,
                   (SELECT COUNT(*) FROM stock_batches b WHERE b.supplier_id = s.id) AS batch_count,
                   (SELECT COUNT(*) FROM stock_in_log sil WHERE sil.supplier_id = s.id) AS delivery_count,
                   (SELECT COUNT(*) FROM stock_purchase_orders po WHERE po.supplier_id = s.id) AS po_count
            FROM stock_suppliers s
            ORDER BY s.is_active DESC, s.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $stats['total']  = count($suppliers);
        $stats['active'] = count(array_filter($suppliers, fn($s) => (int)$s['is_active'] === 1));
    } catch (Throwable $e) {
        $error = 'Failed to load suppliers: ' . $e->getMessage();
    }
}

$currency_symbol = getSetting('currency_symbol');
$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Suppliers — Stock Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        .sup-stats { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
        .sup-stat { background:#fff; border:1px solid #e6e0d6; border-radius:2px; padding:16px 20px; min-width:150px; box-shadow:0 2px 8px rgba(70,60,50,.06); }
        .sup-stat .num { font-size:1.8rem; font-weight:600; color:#3e3930; }
        .sup-stat .lbl { font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; color:#8a8172; }
        .sup-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e6e0d6; }
        .sup-table th, .sup-table td { padding:11px 14px; text-align:left; border-bottom:1px solid #efeae1; font-size:.9rem; }
        .sup-table th { background:#faf8f4; font-size:.74rem; text-transform:uppercase; letter-spacing:.05em; color:#8a8172; }
        .sup-table tr:hover td { background:#faf8f4; }
        .sup-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
        .sup-inactive td { opacity:.55; }
        .pill { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.72rem; font-weight:500; }
        .pill-on { background:#e3f0e4; color:#2e6b34; }
        .pill-off { background:#f0e3e3; color:#8a3a3a; }
        .sup-modal-bg { display:none; position:fixed; inset:0; background:rgba(40,34,28,.45); z-index:1000; align-items:flex-start; justify-content:center; padding:40px 16px; overflow-y:auto; }
        .sup-modal-bg.open { display:flex; }
        .sup-modal { width:100%; max-width:560px; }
        .sup-modal .body { padding:22px; display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .sup-modal .body .full { grid-column:1/3; }
        .sup-modal label { display:block; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; color:#8a8172; margin-bottom:5px; }
        .sup-modal input[type=text], .sup-modal input[type=email], .sup-modal input[type=number], .sup-modal textarea {
            width:100%; padding:9px 11px; font-family:inherit; font-size:.9rem; }
        .sup-modal textarea { min-height:64px; resize:vertical; }
        .btn-sup { padding:9px 18px; border:none; border-radius:2px; cursor:pointer; font-family:inherit; font-size:.88rem; letter-spacing:.03em; }
        .btn-sup-primary { background:#7E684B; color:#fff; }
        .sup-actions { display:flex; gap:8px; }
        .sup-link { color:#7E684B; cursor:pointer; text-decoration:none; font-size:.84rem; }
        .sup-icon-btn { background:none; border:none; padding:6px 7px; border-radius:4px; line-height:1; font-family:inherit; }
        .sup-icon-btn:hover, .sup-icon-btn:focus-visible { background:#f3ece4; }
        .sup-icon-btn--danger:hover, .sup-icon-btn--danger:focus-visible { color:#b4232f; background:#fbeae8; }
        .sup-icon-btn--locked { color:#b9ae9f; cursor:help; }
        .sup-help { font-size:.82rem; color:#8a8172; margin:-8px 0 16px; }
        @media (max-width:640px){ .sup-modal .body { grid-template-columns:1fr; } .sup-modal .body .full { grid-column:1; } }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <h2 class="page-title"><i class="fas fa-truck-field" style="color:#7E684B;"></i> Suppliers</h2>
            <button class="btn-sup btn-sup-primary" onclick="openSupplier()"><i class="fas fa-plus"></i> Add Supplier</button>
        </div>

        <?php if ($message): showAlert($message, 'success'); endif; ?>
        <?php if ($error):   showAlert($error,   'error');   endif; ?>

        <div class="sup-stats">
            <div class="sup-stat"><div class="num"><?php echo (int)$stats['total']; ?></div><div class="lbl">Suppliers</div></div>
            <div class="sup-stat"><div class="num"><?php echo (int)$stats['active']; ?></div><div class="lbl">Active</div></div>
        </div>

        <p class="sup-help"><i class="fas fa-circle-info"></i> <strong>Deactivate</strong> (<i class="fas fa-power-off"></i>) hides a supplier from pickers but keeps it on record — usually the right choice. <strong>Delete</strong> (<i class="fas fa-trash"></i>) removes it for good; past deliveries keep its name as text. Suppliers on purchase orders can only be deactivated (<i class="fas fa-lock"></i>).</p>

        <div style="overflow-x:auto;">
        <table class="sup-table">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Contact</th>
                    <th>Lead time</th>
                    <th>Terms</th>
                    <th class="num">Items</th>
                    <th class="num">Total purchased</th>
                    <th>Last delivery</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr><td colspan="9" style="text-align:center;padding:30px;color:#8a8172;">No suppliers yet. Add your first supplier to enable purchase orders.</td></tr>
                <?php else: foreach ($suppliers as $s): ?>
                    <tr class="<?php echo (int)$s['is_active'] ? '' : 'sup-inactive'; ?>">
                        <td>
                            <strong><?php echo htmlspecialchars($s['name']); ?></strong>
                            <?php if (!empty($s['email'])): ?><br><span style="color:#8a8172;font-size:.8rem;"><?php echo htmlspecialchars($s['email']); ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($s['contact_name'] ?? '—'); ?>
                            <?php if (!empty($s['phone'])): ?><br><span style="color:#8a8172;font-size:.8rem;"><?php echo htmlspecialchars($s['phone']); ?></span><?php endif; ?>
                        </td>
                        <td><?php echo (int)$s['lead_time_days']; ?> day<?php echo (int)$s['lead_time_days'] === 1 ? '' : 's'; ?></td>
                        <td><?php echo htmlspecialchars($s['payment_terms'] ?? '—'); ?></td>
                        <td class="num"><?php echo (int)$s['preferred_count']; ?></td>
                        <td class="num"><?php echo htmlspecialchars($currency_symbol) . number_format((float)$s['total_purchased'], 2); ?></td>
                        <td><?php echo !empty($s['last_received']) ? date('M j, Y', strtotime((string)$s['last_received'])) : '<span style="color:#8a8172;">Never</span>'; ?></td>
                        <td>
                            <?php if ((int)$s['is_active']): ?>
                                <span class="pill pill-on">Active</span>
                            <?php else: ?>
                                <span class="pill pill-off">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $usedDeliveries = max((int)$s['batch_count'], (int)$s['delivery_count']);
                            $usedPos = (int)$s['po_count'];
                            $deletable = $usedPos === 0;
                            $deleteMsg = 'Delete "' . $s['name'] . '" permanently? This cannot be undone.'
                                . ($usedDeliveries > 0 ? ' Its ' . $usedDeliveries . ' past deliver' . ($usedDeliveries === 1 ? 'y stays' : 'ies stay') . ' in stock history under its name, but it will no longer show a purchase total. To keep it on record, deactivate it instead.' : '')
                                . ((int)$s['preferred_count'] > 0 ? ' ' . (int)$s['preferred_count'] . ' item(s) using it as preferred supplier will become unassigned.' : '');
                            $isOn = (int)$s['is_active'] === 1;
                            ?>
                            <div class="sup-actions">
                                <button type="button" class="sup-link sup-icon-btn" title="Edit supplier" aria-label="Edit <?php echo htmlspecialchars($s['name']); ?>"
                                        onclick='openSupplier(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'><i class="fas fa-pen"></i></button>
                                <form method="POST" style="display:inline"
                                      data-admin-confirm="<?php echo $isOn ? 'Deactivate this supplier? It will be hidden from purchase orders and stock-in pickers. Its history stays.' : 'Reactivate this supplier so it can be picked again?'; ?>"
                                      data-admin-confirm-title="<?php echo $isOn ? 'Deactivate supplier' : 'Activate supplier'; ?>"
                                      data-admin-confirm-ok="<?php echo $isOn ? 'Deactivate' : 'Activate'; ?>"
                                      data-admin-confirm-icon="fa-power-off">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                    <button type="submit" class="sup-link sup-icon-btn" title="<?php echo $isOn ? 'Deactivate' : 'Activate'; ?>" aria-label="<?php echo ($isOn ? 'Deactivate ' : 'Activate ') . htmlspecialchars($s['name']); ?>">
                                        <i class="fas fa-power-off"></i>
                                    </button>
                                </form>
                                <?php if ($deletable): ?>
                                    <form method="POST" style="display:inline"
                                          data-admin-confirm="<?php echo htmlspecialchars($deleteMsg); ?>"
                                          data-admin-confirm-title="Delete supplier"
                                          data-admin-confirm-ok="Delete"
                                          data-admin-confirm-tone="danger"
                                          data-admin-confirm-icon="fa-trash">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                        <button type="submit" class="sup-link sup-icon-btn sup-icon-btn--danger" title="Delete supplier" aria-label="Delete <?php echo htmlspecialchars($s['name']); ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="sup-link sup-icon-btn sup-icon-btn--locked" tabindex="0"
                                          title="Can't delete: on <?php echo $usedPos; ?> purchase order<?php echo $usedPos === 1 ? '' : 's'; ?>. Deactivate it instead so those orders keep their supplier.">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Add/Edit modal -->
    <div class="sup-modal-bg" id="supModal">
        <div class="sup-modal">
            <div class="modal-header">
                <h3 id="supModalTitle">Add Supplier</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeSupplier()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="sup_id" value="0">
                <div class="body modal-body">
                    <div class="full">
                        <label>Supplier name *</label>
                        <input type="text" name="name" id="sup_name" required maxlength="255">
                    </div>
                    <div>
                        <label>Contact person</label>
                        <input type="text" name="contact_name" id="sup_contact" maxlength="255">
                    </div>
                    <div>
                        <label>Phone</label>
                        <input type="text" name="phone" id="sup_phone" maxlength="60">
                    </div>
                    <div>
                        <label>Email</label>
                        <input type="email" name="email" id="sup_email" maxlength="255">
                    </div>
                    <div>
                        <label>Lead time (days)</label>
                        <input type="number" name="lead_time_days" id="sup_lead" min="0" max="365" value="3">
                    </div>
                    <div>
                        <label>Payment terms</label>
                        <input type="text" name="payment_terms" id="sup_terms" maxlength="100" placeholder="e.g. Net 30">
                    </div>
                    <div>
                        <label>Account ref</label>
                        <input type="text" name="account_ref" id="sup_acct" maxlength="100">
                    </div>
                    <div class="full">
                        <label>Address</label>
                        <input type="text" name="address" id="sup_address" maxlength="500">
                    </div>
                    <div class="full">
                        <label>Notes</label>
                        <textarea name="notes" id="sup_notes" maxlength="2000"></textarea>
                    </div>
                    <div class="full">
                        <label style="display:inline-flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-size:.9rem;color:#3e3930;">
                            <input type="checkbox" name="is_active" id="sup_active" checked style="width:auto;"> Active
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-sup btn-sup-ghost" onclick="closeSupplier()">Cancel</button>
                    <button type="submit" class="btn-sup btn-sup-primary">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openSupplier(s) {
            var m = document.getElementById('supModal');
            document.getElementById('supModalTitle').textContent = s ? 'Edit Supplier' : 'Add Supplier';
            document.getElementById('sup_id').value      = s ? s.id : 0;
            document.getElementById('sup_name').value    = s ? (s.name || '') : '';
            document.getElementById('sup_contact').value = s ? (s.contact_name || '') : '';
            document.getElementById('sup_phone').value   = s ? (s.phone || '') : '';
            document.getElementById('sup_email').value   = s ? (s.email || '') : '';
            document.getElementById('sup_lead').value    = s ? (s.lead_time_days || 0) : 3;
            document.getElementById('sup_terms').value   = s ? (s.payment_terms || '') : '';
            document.getElementById('sup_acct').value    = s ? (s.account_ref || '') : '';
            document.getElementById('sup_address').value = s ? (s.address || '') : '';
            document.getElementById('sup_notes').value   = s ? (s.notes || '') : '';
            document.getElementById('sup_active').checked = s ? (s.is_active == 1) : true;
            m.classList.add('open');
        }
        function closeSupplier() { document.getElementById('supModal').classList.remove('open'); }
        document.getElementById('supModal').addEventListener('click', function (e) { if (e.target === this) closeSupplier(); });
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>
