<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';

$recipient = 'johnpaulchirwa@mail.com';
$currency = getSetting('currency_symbol') ?: 'MWK';
$siteName = getSetting('site_name') ?: 'Rosalyns Beach Hotel';

$adminStmt = $pdo->query("SELECT id, COALESCE(NULLIF(full_name,''), username, 'System Cashier') AS cashier FROM admin_users WHERE role IN ('admin','manager','restaurant_staff') ORDER BY id ASC LIMIT 1");
$admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    fwrite(STDERR, "No admin/manager/restaurant_staff user found.\n");
    exit(1);
}

$itemStmt = $pdo->query("SELECT mi.id, mi.item_name, mi.price, COALESCE(mi.station, mc.default_station, 'kitchen') AS station, mc.slug AS menu_type
    FROM menu_items mi
    JOIN menu_categories mc ON mc.id = mi.category_id
    WHERE mi.is_available = 1
    ORDER BY mi.id ASC
    LIMIT 1");
$item = $itemStmt->fetch(PDO::FETCH_ASSOC);
if (!$item) {
    fwrite(STDERR, "No available menu item found for test order.\n");
    exit(1);
}

$qty = 2.0;
$lineTotal = round((float)$item['price'] * $qty, 2);
$reference = 'TEST-' . date('Ymd-His');
$customerName = 'John Paul Chirwa';
$customerEmail = $recipient;
$customerPhone = '+265999000111';

$pdo->beginTransaction();
try {
    $orderIns = $pdo->prepare("INSERT INTO stock_orders
        (reference, order_type, table_number, customer_name, customer_email, customer_phone, notes, status,
         payment_method, tendered_amount, change_due, paid_at, total_amount, subtotal, created_by, created_at, updated_at)
        VALUES (?, 'dine_in', '12', ?, ?, ?, ?, 'paid', 'cash', ?, ?, NOW(), ?, ?, ?, NOW(), NOW())");
    $orderIns->execute([
        $reference,
        $customerName,
        $customerEmail,
        $customerPhone,
        'Automated POS receipt test order',
        $lineTotal,
        0,
        $lineTotal,
        $lineTotal,
        (int)$admin['id'],
    ]);

    $orderId = (int)$pdo->lastInsertId();

    $itemIns = $pdo->prepare("INSERT INTO stock_order_items
        (order_id, menu_item_id, menu_type, item_name, unit_price, quantity, line_total, notes, kds_status, station, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'served', ?, NOW())");
    $itemIns->execute([
        $orderId,
        (int)$item['id'],
        (string)$item['menu_type'],
        (string)$item['item_name'],
        (float)$item['price'],
        $qty,
        $lineTotal,
        'Chef recommendation platter',
        (string)$item['station'],
    ]);

    $pdo->prepare("UPDATE stock_orders SET invoice_number = ?, invoice_generated_at = NOW(), receipt_sent_to = ?, receipt_sent_at = NOW(), receipt_send_count = receipt_send_count + 1 WHERE id = ?")
        ->execute(['RST-' . date('Ymd') . '-' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT), $recipient, $orderId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Failed to create test order: " . $e->getMessage() . "\n");
    exit(1);
}

$subject = $siteName . ' - Test Restaurant Receipt ' . $reference;
$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . '</title></head><body style="margin:0;padding:0;background:#f7f3ee;font-family:Arial,Helvetica,sans-serif;color:#1f1c18;">'
    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f7f3ee;padding:22px 10px;">'
    . '<tr><td align="center">'
    . '<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #ece3d9;border-radius:12px;overflow:hidden;">'
    . '<tr><td style="padding:18px 24px 16px;border-bottom:1px solid #ede7df;text-align:center;">'
    . '<h1 style="margin:0;color:#8B7355;font-size:24px;font-weight:600;">' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</h1>'
    . '<div style="margin-top:10px;font-size:12px;letter-spacing:0.12em;font-weight:700;color:#8B7355;">RESTAURANT RECEIPT</div>'
    . '<div style="margin-top:6px;font-size:12px;color:#5a534c;">Receipt ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . ' · ' . date('Y-m-d H:i') . '</div>'
    . '</td></tr>'
    . '<tr><td style="padding:16px 24px 10px;">'
    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:12px;color:#3f3933;">'
    . '<tr><td style="padding:4px 0;"><strong>Guest</strong></td><td align="right" style="padding:4px 0;">' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . '</td></tr>'
    . '<tr><td style="padding:4px 0;"><strong>Email</strong></td><td align="right" style="padding:4px 0;">' . htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8') . '</td></tr>'
    . '<tr><td style="padding:4px 0;"><strong>Cashier</strong></td><td align="right" style="padding:4px 0;">' . htmlspecialchars((string)$admin['cashier'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
    . '</table>'
    . '</td></tr>'
    . '<tr><td style="padding:8px 24px 0;">'
    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:13px;">'
    . '<tr style="background:#f5efe8;color:#3f3933;"><th align="left" style="padding:8px;">Item</th><th align="right" style="padding:8px;">Qty</th><th align="right" style="padding:8px;">Price</th><th align="right" style="padding:8px;">Line</th></tr>'
    . '<tr><td style="padding:10px 8px;border-bottom:1px solid #eee;">' . htmlspecialchars((string)$item['item_name'], ENT_QUOTES, 'UTF-8') . '<div style="margin-top:4px;font-size:11px;color:#8B7355;font-style:italic;">→ Chef recommendation platter</div></td><td align="right" style="padding:10px 8px;border-bottom:1px solid #eee;">' . number_format($qty, 2) . '</td><td align="right" style="padding:10px 8px;border-bottom:1px solid #eee;">' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ' ' . number_format((float)$item['price'], 2) . '</td><td align="right" style="padding:10px 8px;border-bottom:1px solid #eee;">' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ' ' . number_format($lineTotal, 2) . '</td></tr>'
    . '</table>'
    . '</td></tr>'
    . '<tr><td style="padding:14px 24px 0;">'
    . '<table role="presentation" align="right" cellspacing="0" cellpadding="0" style="font-size:13px;color:#3f3933;min-width:280px;">'
    . '<tr><td style="padding:4px 0;">Subtotal</td><td align="right" style="padding:4px 0;">' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ' ' . number_format($lineTotal, 2) . '</td></tr>'
    . '<tr><td style="padding:4px 0;">Payment</td><td align="right" style="padding:4px 0;">Cash</td></tr>'
    . '<tr><td style="padding:8px 0 0;border-top:1px solid #d9cec1;font-weight:700;">TOTAL</td><td align="right" style="padding:8px 0 0;border-top:1px solid #d9cec1;font-weight:700;font-size:15px;">' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ' ' . number_format($lineTotal, 2) . '</td></tr>'
    . '</table>'
    . '</td></tr>'
    . '<tr><td style="padding:18px 24px 22px;">'
    . '<div style="border-top:1px dashed #d9cec1;padding-top:10px;text-align:center;font-size:12px;color:#6a645d;line-height:1.5;">Thank you for dining with us. This is a test receipt generated from POS delivery workflow validation.</div>'
    . '</td></tr>'
    . '</table>'
    . '</td></tr>'
    . '</table>'
    . '</body></html>';

$result = sendEmail($recipient, $customerName, $subject, $html);
if (!empty($result['success'])) {
    $mode = !empty($result['preview']) ? 'preview-only (dev mode)' : 'live-send';
    echo "OK: Receipt email {$mode} completed. Order ID {$orderId}, Ref {$reference}.\n";
    exit(0);
}

$error = $result['message'] ?? 'Unknown sendEmail error';
fwrite(STDERR, "FAILED: {$error}\n");
exit(2);
