<?php

/**
 * Stock Management — Purchase Orders (Procurement)
 *
 * Full PO lifecycle: draft -> sent -> partial/received -> closed (or cancelled).
 * Receiving posts stock into the existing batch/FIFO engine via
 * rh_receive_stock_line(), linking supplier_id + purchase_order_id so cost and
 * traceability stay consistent with manual stock-in.
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

if (!ensureStockTablesExist()) {
    $error = 'Stock tables not yet created.';
} else {
    ensureProcurementSchema($pdo);
}

/** Recompute and persist a PO's subtotal/total from its lines. */
function rh_po_recalc(PDO $pdo, int $poId): void
{
    $t = (float)$pdo->query("SELECT COALESCE(SUM(line_total),0) FROM stock_purchase_order_items WHERE purchase_order_id = " . (int)$poId)->fetchColumn();
    $pdo->prepare("UPDATE stock_purchase_orders SET subtotal = ?, total_cost = ? WHERE id = ?")->execute([$t, $t, $poId]);
}

$redirectTo = 'purchase-orders.php';

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security token invalid.';
    } else {
        try {
            $action = $_POST['action'] ?? '';

            if ($action === 'create' || $action === 'create_from_reorder') {
                $supplierId = (int)($_POST['supplier_id'] ?? 0);
                $orderDate  = trim($_POST['order_date'] ?? '') ?: date('Y-m-d');
                $expected   = trim($_POST['expected_date'] ?? '');
                $notes      = trim($_POST['notes'] ?? '');

                $pdo->beginTransaction();
                $ref = rh_next_po_reference($pdo);
                $pdo->prepare("
                    INSERT INTO stock_purchase_orders (reference, supplier_id, status, order_date, expected_date, notes, created_by)
                    VALUES (?, ?, 'draft', ?, ?, ?, ?)
                ")->execute([$ref, $supplierId ?: null, $orderDate, $expected ?: null, $notes ?: null, $user['id']]);
                $poId = (int)$pdo->lastInsertId();

                if ($action === 'create_from_reorder') {
                    $ids   = $_POST['ingredient_id'] ?? [];
                    $qtys  = $_POST['order_qty'] ?? [];
                    $costs = $_POST['unit_cost'] ?? [];
                    $lineIns = $pdo->prepare("
                        INSERT INTO stock_purchase_order_items
                            (purchase_order_id, ingredient_id, description, unit, ordered_qty, unit_cost, line_total)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ingMeta = $pdo->prepare("SELECT name, unit FROM stock_ingredients WHERE id = ?");
                    $n = is_array($ids) ? count($ids) : 0;
                    for ($k = 0; $k < $n; $k++) {
                        $iid = (int)($ids[$k] ?? 0);
                        $q   = (float)($qtys[$k] ?? 0);
                        $c   = max(0, (float)($costs[$k] ?? 0));
                        if ($iid <= 0 || $q <= 0) continue;
                        $ingMeta->execute([$iid]);
                        $m = $ingMeta->fetch(PDO::FETCH_ASSOC);
                        if (!$m) continue;
                        $lineIns->execute([$poId, $iid, $m['name'], $m['unit'], $q, $c, round($q * $c, 2)]);
                    }
                    rh_po_recalc($pdo, $poId);
                }
                $pdo->commit();
                $message = "Draft purchase order {$ref} created.";
                $redirectTo = 'purchase-orders.php?id=' . $poId;

            } elseif ($action === 'add_line') {
                $poId = (int)($_POST['po_id'] ?? 0);
                $iid  = (int)($_POST['ingredient_id'] ?? 0);
                $desc = trim($_POST['description'] ?? '');
                $unit = trim($_POST['unit'] ?? '');
                $q    = (float)($_POST['ordered_qty'] ?? 0);
                $c    = max(0, (float)($_POST['unit_cost'] ?? 0));
                if ($q <= 0) throw new RuntimeException('Quantity must be greater than zero.');
                if ($iid > 0) {
                    $m = $pdo->prepare("SELECT name, unit, cost_per_unit FROM stock_ingredients WHERE id = ?");
                    $m->execute([$iid]);
                    if ($row = $m->fetch(PDO::FETCH_ASSOC)) {
                        if ($desc === '') $desc = $row['name'];
                        if ($unit === '') $unit = $row['unit'];
                        if ($c <= 0) $c = (float)$row['cost_per_unit'];
                    }
                }
                if ($desc === '') throw new RuntimeException('Enter an item or description.');
                $pdo->prepare("
                    INSERT INTO stock_purchase_order_items
                        (purchase_order_id, ingredient_id, description, unit, ordered_qty, unit_cost, line_total)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$poId, $iid ?: null, $desc, $unit ?: null, $q, $c, round($q * $c, 2)]);
                rh_po_recalc($pdo, $poId);
                $message = 'Line added.';
                $redirectTo = 'purchase-orders.php?id=' . $poId;

            } elseif ($action === 'remove_line') {
                $lineId = (int)($_POST['line_id'] ?? 0);
                $poId   = (int)($_POST['po_id'] ?? 0);
                $pdo->prepare("DELETE FROM stock_purchase_order_items WHERE id = ? AND purchase_order_id = ?")->execute([$lineId, $poId]);
                rh_po_recalc($pdo, $poId);
                $message = 'Line removed.';
                $redirectTo = 'purchase-orders.php?id=' . $poId;

            } elseif ($action === 'update_line') {
                $lineId = (int)($_POST['line_id'] ?? 0);
                $poId   = (int)($_POST['po_id'] ?? 0);
                $q = (float)($_POST['ordered_qty'] ?? 0);
                $c = max(0, (float)($_POST['unit_cost'] ?? 0));
                if ($q <= 0) throw new RuntimeException('Quantity must be greater than zero.');
                $pdo->prepare("UPDATE stock_purchase_order_items SET ordered_qty = ?, unit_cost = ?, line_total = ? WHERE id = ? AND purchase_order_id = ?")
                    ->execute([$q, $c, round($q * $c, 2), $lineId, $poId]);
                rh_po_recalc($pdo, $poId);
                $message = 'Line updated.';
                $redirectTo = 'purchase-orders.php?id=' . $poId;

            } elseif ($action === 'send') {
                $poId = (int)($_POST['po_id'] ?? 0);
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM stock_purchase_order_items WHERE purchase_order_id = " . $poId)->fetchColumn();
                if ($cnt < 1) throw new RuntimeException('Add at least one line before sending.');
                $pdo->prepare("UPDATE stock_purchase_orders SET status = 'sent', sent_at = NOW() WHERE id = ? AND status = 'draft'")->execute([$poId]);
                $message = 'Purchase order marked as sent.';
                $redirectTo = 'purchase-orders.php?id=' . $poId;

            } elseif ($action === 'cancel') {
                $poId = (int)($_POST['po_id'] ?? 0);
                $pdo->prepare("UPDATE stock_purchase_orders SET status = 'cancelled', cancelled_at = NOW() WHERE id = ? AND status IN ('draft','sent')")->execute([$poId]);
                $message = 'Purchase order cancelled.';
                $redirectTo = 'purchase-orders.php?id=' . $poId;

            } elseif ($action === 'close') {
                $poId = (int)($_POST['po_id'] ?? 0);
                $pdo->prepare("UPDATE stock_purchase_orders SET status = 'closed', closed_at = NOW() WHERE id = ? AND status IN ('partial','received','sent')")->execute([$poId]);
                $message = 'Purchase order closed.';
                $redirectTo = 'purchase-orders.php?id=' . $poId;

            } elseif ($action === 'receive') {
                $poId = (int)($_POST['po_id'] ?? 0);
                $lineIds  = $_POST['line_id'] ?? [];
                $recvQtys = $_POST['receive_qty'] ?? [];
                $recvCosts = $_POST['receive_cost'] ?? [];
                $expiries = $_POST['expiry_date'] ?? [];

                $poStmt = $pdo->prepare("SELECT * FROM stock_purchase_orders WHERE id = ? LIMIT 1");
                $poStmt->execute([$poId]);
                $po = $poStmt->fetch(PDO::FETCH_ASSOC);
                if (!$po) throw new RuntimeException('Purchase order not found.');
                if (in_array($po['status'], ['cancelled', 'closed'], true)) {
                    throw new RuntimeException('This purchase order can no longer receive stock.');
                }

                $supplierName = null; $supplierContact = null;
                if (!empty($po['supplier_id'])) {
                    $s = $pdo->prepare("SELECT name, contact_name, phone FROM stock_suppliers WHERE id = ?");
                    $s->execute([(int)$po['supplier_id']]);
                    if ($sr = $s->fetch(PDO::FETCH_ASSOC)) {
                        $supplierName = $sr['name'];
                        $supplierContact = $sr['contact_name'] ?: $sr['phone'];
                    }
                }

                $pdo->beginTransaction();
                $lineSel = $pdo->prepare("SELECT * FROM stock_purchase_order_items WHERE id = ? AND purchase_order_id = ? FOR UPDATE");
                $received = 0;
                $n = is_array($lineIds) ? count($lineIds) : 0;
                for ($k = 0; $k < $n; $k++) {
                    $lid = (int)($lineIds[$k] ?? 0);
                    $rq  = (float)($recvQtys[$k] ?? 0);
                    if ($lid <= 0 || $rq <= 0) continue;
                    $lineSel->execute([$lid, $poId]);
                    $line = $lineSel->fetch(PDO::FETCH_ASSOC);
                    if (!$line) continue;

                    $outstanding = (float)$line['ordered_qty'] - (float)$line['received_qty'];
                    if ($rq > $outstanding + 0.0001) $rq = $outstanding;   // cap over-receipt
                    if ($rq <= 0) continue;

                    $cost = isset($recvCosts[$k]) && $recvCosts[$k] !== '' ? max(0, (float)$recvCosts[$k]) : (float)$line['unit_cost'];
                    $expiry = trim($expiries[$k] ?? '');

                    if (!empty($line['ingredient_id'])) {
                        rh_receive_stock_line(
                            $pdo,
                            (int)$line['ingredient_id'],
                            $rq,
                            $cost,
                            !empty($po['supplier_id']) ? (int)$po['supplier_id'] : null,
                            $supplierName,
                            $supplierContact,
                            $expiry !== '' ? $expiry : null,
                            7,
                            $poId,
                            $user['id'],
                            'PO ' . $po['reference']
                        );
                    }
                    $pdo->prepare("UPDATE stock_purchase_order_items SET received_qty = received_qty + ? WHERE id = ?")
                        ->execute([$rq, $lid]);
                    $received++;
                }

                if ($received === 0) throw new RuntimeException('Enter a quantity to receive on at least one line.');

                // Recompute PO status from line fulfilment.
                $agg = $pdo->prepare("SELECT SUM(ordered_qty) AS o, SUM(received_qty) AS r FROM stock_purchase_order_items WHERE purchase_order_id = ?");
                $agg->execute([$poId]);
                $a = $agg->fetch(PDO::FETCH_ASSOC);
                $ordered = (float)($a['o'] ?? 0);
                $recv    = (float)($a['r'] ?? 0);
                if ($recv + 0.0001 >= $ordered && $ordered > 0) {
                    $pdo->prepare("UPDATE stock_purchase_orders SET status = 'received', received_at = NOW() WHERE id = ?")->execute([$poId]);
                } else {
                    $pdo->prepare("UPDATE stock_purchase_orders SET status = 'partial', received_at = COALESCE(received_at, NOW()) WHERE id = ?")->execute([$poId]);
                }
                $pdo->commit();
                if (function_exists('deleteCache')) deleteCache('stock_dashboard_metrics_v2');
                $message = 'Stock received against purchase order.';
                $redirectTo = 'purchase-orders.php?id=' . $poId;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
    if ($message) $_SESSION['stock_msg'] = $message;
    if ($error)   $_SESSION['stock_err'] = $error;
    header('Location: ' . $redirectTo);
    exit;
}

if (!empty($_SESSION['stock_msg'])) { $message = $_SESSION['stock_msg']; unset($_SESSION['stock_msg']); }
if (!empty($_SESSION['stock_err'])) { $error   = $_SESSION['stock_err']; unset($_SESSION['stock_err']); }

$viewId = (int)($_GET['id'] ?? 0);
$statusColors = [
    'draft' => ['#8a8172', '#f0ece4'], 'sent' => ['#2f5f8a', '#e3ecf3'],
    'partial' => ['#8a6a2f', '#f3ecdf'], 'received' => ['#2e6b34', '#e3f0e4'],
    'closed' => ['#555', '#eaeaea'], 'cancelled' => ['#8a3a3a', '#f0e3e3'],
];

$suppliers = [];
$ingredients = [];
try {
    $suppliers = $pdo->query("SELECT id, name FROM stock_suppliers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $ingredients = $pdo->query("SELECT id, name, unit, cost_per_unit FROM stock_ingredients WHERE is_archived = 0 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* schema not ready */ }

$po = null; $lines = [];
if (!$error && $viewId > 0) {
    $st = $pdo->prepare("
        SELECT po.*, s.name AS supplier_name, s.email AS supplier_email, s.phone AS supplier_phone, u.full_name AS creator
        FROM stock_purchase_orders po
        LEFT JOIN stock_suppliers s ON s.id = po.supplier_id
        LEFT JOIN admin_users u ON u.id = po.created_by
        WHERE po.id = ? LIMIT 1
    ");
    $st->execute([$viewId]);
    $po = $st->fetch(PDO::FETCH_ASSOC);
    if ($po) {
        $ls = $pdo->prepare("SELECT * FROM stock_purchase_order_items WHERE purchase_order_id = ? ORDER BY id");
        $ls->execute([$viewId]);
        $lines = $ls->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $error = 'Purchase order not found.';
    }
}

$listOrders = [];
if (!$error && $viewId <= 0) {
    try {
        $listOrders = $pdo->query("
            SELECT po.*, s.name AS supplier_name,
                   (SELECT COUNT(*) FROM stock_purchase_order_items i WHERE i.purchase_order_id = po.id) AS line_count
            FROM stock_purchase_orders po
            LEFT JOIN stock_suppliers s ON s.id = po.supplier_id
            ORDER BY po.created_at DESC
            LIMIT 300
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $listOrders = []; }
}

$csrf_token = generateCsrfToken();
$isEditable = $po && $po['status'] === 'draft';
$canReceive = $po && in_array($po['status'], ['sent', 'partial'], true);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Purchase Orders — Stock Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        .po-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e6e0d6; }
        .po-table th, .po-table td { padding:10px 14px; border-bottom:1px solid #efeae1; font-size:.88rem; text-align:left; }
        .po-table th { background:#faf8f4; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#8a8172; }
        .po-table td.num, .po-table th.num { text-align:right; font-variant-numeric:tabular-nums; }
        .po-table tr:hover td { background:#faf8f4; }
        .badge { display:inline-block; padding:3px 11px; border-radius:20px; font-size:.72rem; font-weight:600; text-transform:capitalize; }
        .card { background:#fff; border:1px solid #e6e0d6; border-radius:2px; padding:18px 20px; margin-bottom:20px; box-shadow:0 2px 8px rgba(70,60,50,.06); }
        .card h3 { margin:0 0 14px; font-family:'Cormorant Garamond',serif; font-size:1.3rem; color:#3e3930; }
        .po-meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; }
        .po-meta .lbl { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#8a8172; }
        .po-meta .val { font-size:.95rem; color:#3e3930; }
        .row-form { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
        .row-form label { display:block; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; color:#8a8172; margin-bottom:4px; }
        .row-form input, .row-form select { padding:8px 10px; border:1px solid #d3cbc0; border-radius:2px; font-family:inherit; font-size:.88rem; background:#fff; }
        .btn { padding:9px 16px; border:none; border-radius:2px; cursor:pointer; font-family:inherit; font-size:.86rem; }
        .btn-primary { background:#8B7355; color:#fff; }
        .btn-ghost { background:transparent; border:1px solid #d3cbc0; color:#6a6255; }
        .btn-danger { background:#a25048; color:#fff; }
        .btn-sm { padding:5px 10px; font-size:.8rem; }
        .po-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:8px; }
        .mini { width:90px; }
        .mono { font-variant-numeric:tabular-nums; }
        .po-link { color:#8B7355; text-decoration:none; font-weight:600; }
        @media (max-width:640px){ .po-table thead { display:none; } }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <h2 class="page-title"><i class="fas fa-file-invoice" style="color:#8B7355;"></i> Purchase Orders</h2>
            <?php if ($viewId <= 0): ?>
                <button class="btn btn-primary" onclick="document.getElementById('newPoCard').scrollIntoView({behavior:'smooth'});"><i class="fas fa-plus"></i> New PO</button>
            <?php else: ?>
                <a href="purchase-orders.php" class="po-link"><i class="fas fa-arrow-left"></i> All purchase orders</a>
            <?php endif; ?>
        </div>

        <?php if ($message): showAlert($message, 'success'); endif; ?>
        <?php if ($error):   showAlert($error,   'error');   endif; ?>

        <?php if ($viewId > 0 && $po): ?>
            <?php [$fg, $bg] = $statusColors[$po['status']] ?? ['#555', '#eee']; ?>
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
                    <h3><?php echo htmlspecialchars($po['reference']); ?></h3>
                    <span class="badge" style="color:<?php echo $fg; ?>;background:<?php echo $bg; ?>;"><?php echo htmlspecialchars($po['status']); ?></span>
                </div>
                <div class="po-meta">
                    <div><div class="lbl">Supplier</div><div class="val"><?php echo htmlspecialchars($po['supplier_name'] ?? 'Unassigned'); ?></div></div>
                    <div><div class="lbl">Order date</div><div class="val"><?php echo htmlspecialchars($po['order_date']); ?></div></div>
                    <div><div class="lbl">Expected</div><div class="val"><?php echo htmlspecialchars($po['expected_date'] ?? '—'); ?></div></div>
                    <div><div class="lbl">Total</div><div class="val"><?php echo htmlspecialchars($currency_symbol) . number_format((float)$po['total_cost'], 2); ?></div></div>
                    <div><div class="lbl">Raised by</div><div class="val"><?php echo htmlspecialchars($po['creator'] ?? '—'); ?></div></div>
                </div>
                <?php if (!empty($po['notes'])): ?>
                    <p style="margin-top:12px;color:#6a6255;"><?php echo nl2br(htmlspecialchars($po['notes'])); ?></p>
                <?php endif; ?>
                <div class="po-actions">
                    <?php if ($po['status'] === 'draft'): ?>
                        <form method="POST" onsubmit="return confirm('Mark this PO as sent to the supplier?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="send">
                            <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                            <button class="btn btn-primary"><i class="fas fa-paper-plane"></i> Mark as sent</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($po['status'], ['draft','sent'], true)): ?>
                        <form method="POST" onsubmit="return confirm('Cancel this purchase order?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                            <button class="btn btn-danger"><i class="fas fa-ban"></i> Cancel</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($po['status'], ['partial','received','sent'], true)): ?>
                        <form method="POST" onsubmit="return confirm('Close this purchase order? Outstanding lines will not be received.');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="close">
                            <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                            <button class="btn btn-ghost"><i class="fas fa-box-archive"></i> Close</button>
                        </form>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost" onclick="window.print();"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>

            <!-- Lines / Receiving -->
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="receive">
                <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                <table class="po-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="num">Ordered</th>
                            <th class="num">Received</th>
                            <th class="num">Unit cost</th>
                            <th class="num">Line total</th>
                            <?php if ($canReceive): ?><th class="num">Receive now</th><th>Expiry</th><?php endif; ?>
                            <?php if ($isEditable): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lines)): ?>
                            <tr><td colspan="8" style="text-align:center;padding:24px;color:#8a8172;">No lines yet. Add items below.</td></tr>
                        <?php else: foreach ($lines as $ln):
                            $outstanding = (float)$ln['ordered_qty'] - (float)$ln['received_qty']; ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($ln['description']); ?></strong>
                                    <?php if (!empty($ln['unit'])): ?><span style="color:#8a8172;"> (<?php echo htmlspecialchars($ln['unit']); ?>)</span><?php endif; ?>
                                    <?php if (empty($ln['ingredient_id'])): ?><br><span style="color:#a06a3a;font-size:.76rem;">Non-stock line (no ingredient link)</span><?php endif; ?>
                                </td>
                                <td class="num"><?php echo number_format((float)$ln['ordered_qty'], 3); ?></td>
                                <td class="num"><?php echo number_format((float)$ln['received_qty'], 3); ?></td>
                                <td class="num"><?php echo number_format((float)$ln['unit_cost'], 4); ?></td>
                                <td class="num"><?php echo htmlspecialchars($currency_symbol) . number_format((float)$ln['line_total'], 2); ?></td>
                                <?php if ($canReceive): ?>
                                    <td class="num">
                                        <input type="hidden" name="line_id[]" value="<?php echo (int)$ln['id']; ?>">
                                        <input class="mini" type="number" name="receive_qty[]" step="0.001" min="0"
                                               max="<?php echo htmlspecialchars((string)max(0, $outstanding)); ?>"
                                               value="<?php echo $outstanding > 0 ? htmlspecialchars((string)round($outstanding, 3)) : '0'; ?>"
                                               <?php echo $outstanding <= 0 ? 'readonly title="Fully received"' : ''; ?>>
                                        <input type="hidden" name="receive_cost[]" value="<?php echo htmlspecialchars((string)$ln['unit_cost']); ?>">
                                    </td>
                                    <td><input type="date" name="expiry_date[]"></td>
                                <?php endif; ?>
                                <?php if ($isEditable): ?>
                                    <td>
                                        <button type="submit" form="rm<?php echo (int)$ln['id']; ?>" class="btn btn-danger btn-sm" title="Remove line"><i class="fas fa-trash"></i></button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <?php if ($canReceive): ?>
                    <div class="po-actions">
                        <button class="btn btn-primary" onclick="return confirm('Receive the entered quantities into stock?');"><i class="fas fa-truck-ramp-box"></i> Receive stock</button>
                    </div>
                <?php endif; ?>
            </form>

            <?php if ($isEditable): ?>
                <?php foreach ($lines as $ln): ?>
                    <form method="POST" id="rm<?php echo (int)$ln['id']; ?>" style="display:none;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="remove_line">
                        <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                        <input type="hidden" name="line_id" value="<?php echo (int)$ln['id']; ?>">
                    </form>
                <?php endforeach; ?>

                <div class="card">
                    <h3>Add line</h3>
                    <form method="POST" class="row-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="add_line">
                        <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                        <div>
                            <label>Ingredient</label>
                            <select name="ingredient_id" id="al_ing" onchange="alFill(this)">
                                <option value="0">— Custom / non-stock —</option>
                                <?php foreach ($ingredients as $ing): ?>
                                    <option value="<?php echo (int)$ing['id']; ?>"
                                            data-unit="<?php echo htmlspecialchars($ing['unit']); ?>"
                                            data-cost="<?php echo htmlspecialchars((string)$ing['cost_per_unit']); ?>">
                                        <?php echo htmlspecialchars($ing['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Description</label><input type="text" name="description" id="al_desc" maxlength="255" placeholder="Item name"></div>
                        <div><label>Unit</label><input type="text" name="unit" id="al_unit" maxlength="50" class="mini"></div>
                        <div><label>Qty</label><input type="number" name="ordered_qty" step="0.001" min="0.001" class="mini" required></div>
                        <div><label>Unit cost</label><input type="number" name="unit_cost" id="al_cost" step="0.0001" min="0" class="mini"></div>
                        <div><button class="btn btn-primary"><i class="fas fa-plus"></i> Add</button></div>
                    </form>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- LIST VIEW -->
            <table class="po-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Supplier</th>
                        <th>Status</th>
                        <th class="num">Lines</th>
                        <th class="num">Total</th>
                        <th>Order date</th>
                        <th>Expected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($listOrders)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:26px;color:#8a8172;">No purchase orders yet. Create one below or from the Reorder / Buying report.</td></tr>
                    <?php else: foreach ($listOrders as $o): [$fg, $bg] = $statusColors[$o['status']] ?? ['#555', '#eee']; ?>
                        <tr onclick="location.href='purchase-orders.php?id=<?php echo (int)$o['id']; ?>'" style="cursor:pointer;">
                            <td><a class="po-link" href="purchase-orders.php?id=<?php echo (int)$o['id']; ?>"><?php echo htmlspecialchars($o['reference']); ?></a></td>
                            <td><?php echo htmlspecialchars($o['supplier_name'] ?? 'Unassigned'); ?></td>
                            <td><span class="badge" style="color:<?php echo $fg; ?>;background:<?php echo $bg; ?>;"><?php echo htmlspecialchars($o['status']); ?></span></td>
                            <td class="num"><?php echo (int)$o['line_count']; ?></td>
                            <td class="num"><?php echo htmlspecialchars($currency_symbol) . number_format((float)$o['total_cost'], 2); ?></td>
                            <td><?php echo htmlspecialchars($o['order_date']); ?></td>
                            <td><?php echo htmlspecialchars($o['expected_date'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <div class="card" id="newPoCard" style="margin-top:22px;">
                <h3>New purchase order</h3>
                <form method="POST" class="row-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label>Supplier</label>
                        <select name="supplier_id">
                            <option value="0">— Unassigned —</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Order date</label><input type="date" name="order_date" value="<?php echo date('Y-m-d'); ?>"></div>
                    <div><label>Expected date</label><input type="date" name="expected_date"></div>
                    <div style="flex:1;min-width:180px;"><label>Notes</label><input type="text" name="notes" maxlength="500" style="width:100%;"></div>
                    <div><button class="btn btn-primary"><i class="fas fa-plus"></i> Create draft</button></div>
                </form>
                <?php if (empty($suppliers)): ?>
                    <p style="color:#a06a3a;margin-top:10px;"><i class="fas fa-circle-info"></i> Tip: add <a class="po-link" href="stock-suppliers.php">suppliers</a> first to enable reorder grouping and traceability.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function alFill(sel) {
            var o = sel.options[sel.selectedIndex];
            if (!o || !o.value || o.value === '0') return;
            document.getElementById('al_desc').value = o.textContent.trim();
            document.getElementById('al_unit').value = o.getAttribute('data-unit') || '';
            document.getElementById('al_cost').value = o.getAttribute('data-cost') || '';
        }
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>
