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
    // Backfill once — cheap no-op after the first run.
    try { rh_backfill_suppliers_from_batches($pdo); } catch (Throwable $e) { /* non-fatal */ }
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
                $name         = trim($_POST['name'] ?? '');
                $contactName  = trim($_POST['contact_name'] ?? '');
                $email        = trim($_POST['email'] ?? '');
                $phone        = trim($_POST['phone'] ?? '');
                $address      = trim($_POST['address'] ?? '');
                $leadTime     = max(0, (int)($_POST['lead_time_days'] ?? 0));
                $paymentTerms = trim($_POST['payment_terms'] ?? '');
                $accountRef   = trim($_POST['account_ref'] ?? '');
                $notes        = trim($_POST['notes'] ?? '');
                $isActive     = isset($_POST['is_active']) ? 1 : 0;

                if ($name === '') {
                    throw new RuntimeException('Supplier name is required.');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Enter a valid email address.');
                }

                // Enforce unique name (case-insensitive) excluding self.
                $dup = $pdo->prepare("SELECT id FROM stock_suppliers WHERE name = ? AND id <> ? LIMIT 1");
                $dup->execute([$name, $id]);
                if ($dup->fetchColumn()) {
                    throw new RuntimeException('A supplier with that name already exists.');
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
            } elseif ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                $pdo->prepare("UPDATE stock_suppliers SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
                $message = 'Supplier status updated.';
            }
        } catch (Throwable $e) {
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
                   (SELECT MAX(sil.created_at) FROM stock_in_log sil WHERE sil.supplier_id = s.id) AS last_received
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
        .sup-modal { background:#FFFDF9; border-radius:3px; width:100%; max-width:560px; box-shadow:0 12px 40px rgba(40,34,28,.25); }
        .sup-modal header { padding:18px 22px; border-bottom:1px solid #ece5da; font-family:'Cormorant Garamond',serif; font-size:1.35rem; color:#3e3930; }
        .sup-modal .body { padding:22px; display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .sup-modal .body .full { grid-column:1/3; }
        .sup-modal label { display:block; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; color:#8a8172; margin-bottom:5px; }
        .sup-modal input[type=text], .sup-modal input[type=email], .sup-modal input[type=number], .sup-modal textarea {
            width:100%; padding:9px 11px; border:1px solid #d3cbc0; border-radius:2px; font-family:inherit; font-size:.9rem; background:#fff; }
        .sup-modal textarea { min-height:64px; resize:vertical; }
        .sup-modal footer { padding:16px 22px; border-top:1px solid #ece5da; display:flex; justify-content:flex-end; gap:10px; }
        .btn-sup { padding:9px 18px; border:none; border-radius:2px; cursor:pointer; font-family:inherit; font-size:.88rem; letter-spacing:.03em; }
        .btn-sup-primary { background:#8B7355; color:#fff; }
        .btn-sup-ghost { background:transparent; color:#6a6255; border:1px solid #d3cbc0; }
        .sup-actions { display:flex; gap:8px; }
        .sup-link { color:#8B7355; cursor:pointer; text-decoration:none; font-size:.84rem; }
        @media (max-width:640px){ .sup-modal .body { grid-template-columns:1fr; } .sup-modal .body .full { grid-column:1; } .sup-table thead { display:none; } }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <h2 class="page-title"><i class="fas fa-truck-field" style="color:#8B7355;"></i> Suppliers</h2>
            <button class="btn-sup btn-sup-primary" onclick="openSupplier()"><i class="fas fa-plus"></i> Add Supplier</button>
        </div>

        <?php if ($message): showAlert($message, 'success'); endif; ?>
        <?php if ($error):   showAlert($error,   'error');   endif; ?>

        <div class="sup-stats">
            <div class="sup-stat"><div class="num"><?php echo (int)$stats['total']; ?></div><div class="lbl">Suppliers</div></div>
            <div class="sup-stat"><div class="num"><?php echo (int)$stats['active']; ?></div><div class="lbl">Active</div></div>
        </div>

        <table class="sup-table">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Contact</th>
                    <th>Lead time</th>
                    <th>Terms</th>
                    <th class="num">Items</th>
                    <th class="num">Total purchased</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:30px;color:#8a8172;">No suppliers yet. Add your first supplier to enable purchase orders.</td></tr>
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
                        <td>
                            <?php if ((int)$s['is_active']): ?>
                                <span class="pill pill-on">Active</span>
                            <?php else: ?>
                                <span class="pill pill-off">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="sup-actions">
                                <a class="sup-link" onclick='openSupplier(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'><i class="fas fa-pen"></i></a>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Toggle this supplier\'s status?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                    <button type="submit" class="sup-link" style="background:none;border:none;padding:0;">
                                        <i class="fas fa-power-off"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Add/Edit modal -->
    <div class="sup-modal-bg" id="supModal">
        <div class="sup-modal">
            <form method="POST">
                <header id="supModalTitle">Add Supplier</header>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="sup_id" value="0">
                <div class="body">
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
                <footer>
                    <button type="button" class="btn-sup btn-sup-ghost" onclick="closeSupplier()">Cancel</button>
                    <button type="submit" class="btn-sup btn-sup-primary">Save Supplier</button>
                </footer>
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
