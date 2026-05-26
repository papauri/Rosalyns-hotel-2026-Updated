<?php

/**
 * Restaurant Order Receipt / Invoice
 *
 * - GET ?id=N           → show printable receipt + email/WhatsApp actions
 * - GET ?id=N&print=1   → minimal print stylesheet auto-trigger
 * - POST action=email_receipt   → send via PHPMailer (uses existing config/email.php)
 * - POST action=whatsapp_receipt → provision-only (records intent + delivery row)
 */
require_once 'admin-init.php';
require_once '../config/email.php';
require_once '../includes/alert.php';

$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];

$currency  = getSetting('currency_symbol');
$siteName  = getSetting('site_name') ?: 'Hotel';
$hotelAddr = getSetting('hotel_address') ?: '';
$hotelPhone = getSetting('hotel_phone') ?: '';
$hotelEmail = getSetting('hotel_email') ?: '';
$invoicePrefix = getSetting('restaurant_invoice_prefix') ?: 'RST-';
$footerLine = getSetting('restaurant_receipt_footer') ?: 'Thank you for dining with us!';
$whatsappEnabled = (getSetting('restaurant_whatsapp_enabled') ?: '0') === '1';
$whatsappNumber = trim((string)getSetting('whatsapp_number', getSetting('whatsapp_hotel_number', '')));
$whatsappApiToken = trim((string)getSetting('whatsapp_api_token', getSetting('whatsapp_meta_access_token', '')));
$whatsappReady = $whatsappEnabled && $whatsappNumber !== '' && $whatsappApiToken !== '';
$svcPct  = (float)(getSetting('restaurant_service_charge_pct') ?: 0);
$taxPct  = (float)(getSetting('restaurant_tax_pct') ?: 0);

$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    exit('Order id required.');
}

$message = '';
$error = '';

/* ---------- Helper: ensure invoice_number is set on first view/print ---------- */
function ensureInvoiceNumber(PDO $pdo, array $order, string $prefix): string
{
    if (!empty($order['invoice_number'])) return $order['invoice_number'];
    $invNum = $prefix . date('Ymd') . '-' . str_pad((string)$order['id'], 5, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE stock_orders SET invoice_number = ?, invoice_generated_at = NOW() WHERE id = ? AND invoice_number IS NULL")
        ->execute([$invNum, (int)$order['id']]);
    return $invNum;
}

/* ---------- Build the HTML body of the receipt (used for view + email) ---------- */
function buildReceiptHtml(array $order, array $items, array $ctx): string
{
    $cur = $ctx['currency'];
    $site = htmlspecialchars($ctx['site']);
    $addr = htmlspecialchars($ctx['address']);
    $phone = htmlspecialchars($ctx['phone']);
    $email = htmlspecialchars($ctx['email']);
    $footer = htmlspecialchars($ctx['footer']);
    $invNum = htmlspecialchars($order['invoice_number'] ?? '');
    $ref    = htmlspecialchars($order['reference']);
    $date   = $order['paid_at'] ? date('Y-m-d H:i', strtotime($order['paid_at'])) : date('Y-m-d H:i', strtotime($order['created_at']));
    $cust   = htmlspecialchars($order['customer_name'] ?: 'Walk-in customer');
    $custEm = htmlspecialchars($order['customer_email'] ?: '');
    $custPh = htmlspecialchars($order['customer_phone'] ?: '');
    $isRoomService = ($order['order_type'] ?? '') === 'room_service';
    $orderType = htmlspecialchars(ucfirst(str_replace('_', ' ', $order['order_type'])));
    $rawTableNo = (string)($order['table_number'] ?: '');
    $roomNumber = trim((string)($order['room_number'] ?? ''));
    if ($isRoomService && $roomNumber === '' && $rawTableNo !== '') {
        $roomNumber = trim(preg_replace('/^Room\s+/i', '', $rawTableNo));
    }
    $tableNo = htmlspecialchars($rawTableNo);
    $roomNo = htmlspecialchars($roomNumber);
    $cashier = htmlspecialchars($ctx['cashier'] ?: '');
    $notes  = htmlspecialchars($order['notes'] ?: '');
    $method = htmlspecialchars(ucwords(str_replace('_', ' ', $order['payment_method'] ?: '—')));
    $statusLabel = htmlspecialchars(ucfirst($order['status']));
    $isVoid = in_array($order['status'], ['voided', 'cancelled'], true);

    $rows = '';
    foreach ($items as $it) {
        $noteRow = !empty($it['notes']) ? '<div style="font-size:11px;color:#8B7355;font-style:italic;">→ ' . htmlspecialchars($it['notes']) . '</div>' : '';
        $rows .= '<tr>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #eee;">' . htmlspecialchars($it['item_name']) . $noteRow . '</td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right;">' . number_format((float)$it['quantity'], 2) . '</td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right;">' . $cur . ' ' . number_format((float)$it['unit_price'], 2) . '</td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right;">' . $cur . ' ' . number_format((float)$it['line_total'], 2) . '</td>'
            . '</tr>';
    }

    $subtotal = (float)$order['subtotal'] ?: array_sum(array_map(fn($i) => (float)$i['line_total'], $items));
    $discount = (float)$order['discount_amount'];
    $service  = (float)$order['service_charge'];
    $tax      = (float)$order['tax_amount'];
    $total    = (float)$order['total_amount'];
    $tendered = $order['tendered_amount'] !== null ? (float)$order['tendered_amount'] : null;
    $change   = $order['change_due'] !== null ? (float)$order['change_due'] : null;

    $extras = '';
    if ($order['payment_method'] === 'mobile_money' && $order['mobile_wallet_reference']) {
        $extras .= '<div>Mobile: ' . htmlspecialchars($order['mobile_wallet_provider']) . ' · Ref ' . htmlspecialchars($order['mobile_wallet_reference']) . '</div>';
    } elseif ($order['payment_method'] === 'card_manual' && $order['card_last4']) {
        $extras .= '<div>Card: ···· ' . htmlspecialchars($order['card_last4']) . ' · Auth ' . htmlspecialchars($order['card_auth_code'] ?: '') . '</div>';
    }

    $voidBanner = '';
    if ($isVoid) {
        $voidBanner = '<div style="background:#fde7e9;border:2px solid #c82333;color:#721c24;padding:10px;text-align:center;font-weight:700;letter-spacing:2px;margin:0 0 12px;">VOID / NOT VALID</div>';
    }

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt ' . $ref . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f7f3ee;font-family:Arial,Helvetica,sans-serif;color:#1f1c18;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f7f3ee;padding:22px 10px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #ece3d9;border-radius:12px;overflow:hidden;">'
        . '<tr><td style="padding:18px 24px 16px;border-bottom:1px solid #ede7df;text-align:center;">'
        . $voidBanner
        . '<h1 style="margin:0;color:#8B7355;font-size:24px;font-weight:600;">' . $site . '</h1>'
        . ($addr ? '<div style="margin-top:6px;font-size:12px;color:#5a534c;">' . $addr . '</div>' : '')
        . ($phone ? '<div style="margin-top:2px;font-size:12px;color:#5a534c;">Tel: ' . $phone . ($email ? ' · Email: ' . $email : '') . '</div>' : '')
        . '<div style="margin-top:10px;font-size:12px;letter-spacing:0.12em;font-weight:700;color:#8B7355;">RESTAURANT RECEIPT</div>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 24px 10px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:12px;color:#3f3933;">'
        . '<tr><td style="padding:4px 0;"><strong>Receipt #</strong></td><td align="right" style="padding:4px 0;">' . $ref . '</td></tr>'
        . ($invNum ? '<tr><td style="padding:4px 0;"><strong>Invoice #</strong></td><td align="right" style="padding:4px 0;">' . $invNum . '</td></tr>' : '')
        . '<tr><td style="padding:4px 0;"><strong>Date</strong></td><td align="right" style="padding:4px 0;">' . htmlspecialchars($date) . '</td></tr>'
        . '<tr><td style="padding:4px 0;"><strong>Order type</strong></td><td align="right" style="padding:4px 0;">' . $orderType . (!$isRoomService && $tableNo ? ' · Table ' . $tableNo : '') . '</td></tr>'
        . ($isRoomService ? '<tr><td style="padding:4px 0;"><strong>Room</strong></td><td align="right" style="padding:4px 0;">' . ($roomNo ?: 'Not linked') . '</td></tr>' : '')
        . ($isRoomService && !empty($order['booking_reference']) ? '<tr><td style="padding:4px 0;"><strong>Booking</strong></td><td align="right" style="padding:4px 0;">' . htmlspecialchars((string)$order['booking_reference']) . '</td></tr>' : '')
        . '<tr><td style="padding:4px 0;"><strong>Customer</strong></td><td align="right" style="padding:4px 0;">' . $cust . '</td></tr>'
        . ($custEm ? '<tr><td style="padding:4px 0;"><strong>Email</strong></td><td align="right" style="padding:4px 0;">' . $custEm . '</td></tr>' : '')
        . ($custPh ? '<tr><td style="padding:4px 0;"><strong>Phone</strong></td><td align="right" style="padding:4px 0;">' . $custPh . '</td></tr>' : '')
        . ($cashier ? '<tr><td style="padding:4px 0;"><strong>Cashier</strong></td><td align="right" style="padding:4px 0;">' . $cashier . '</td></tr>' : '')
        . '<tr><td style="padding:4px 0;"><strong>Status</strong></td><td align="right" style="padding:4px 0;">' . $statusLabel . '</td></tr>'
        . '</table>'
        . '</td></tr>'
        . '<tr><td style="padding:8px 24px 0;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:13px;">'
        . '<thead><tr style="background:#f5efe8;"><th style="padding:8px;text-align:left;color:#3f3933;">Item</th><th style="padding:8px;text-align:right;color:#3f3933;">Qty</th><th style="padding:8px;text-align:right;color:#3f3933;">Price</th><th style="padding:8px;text-align:right;color:#3f3933;">Line</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody>'
        . '</table>'
        . '</td></tr>'
        . '<tr><td style="padding:14px 24px 0;">'
        . '<table role="presentation" align="right" cellspacing="0" cellpadding="0" style="font-size:13px;color:#3f3933;min-width:280px;">'
        . '<tr><td style="padding:4px 0;">Subtotal</td><td align="right" style="padding:4px 0;">' . $cur . ' ' . number_format($subtotal, 2) . '</td></tr>'
        . ($discount > 0 ? '<tr><td style="padding:4px 0;">Discount' . ($order['discount_reason'] ? ' (' . htmlspecialchars($order['discount_reason']) . ')' : '') . '</td><td align="right" style="padding:4px 0;color:#b3261e;">−' . $cur . ' ' . number_format($discount, 2) . '</td></tr>' : '')
        . ($service > 0 ? '<tr><td style="padding:4px 0;">Service charge</td><td align="right" style="padding:4px 0;">' . $cur . ' ' . number_format($service, 2) . '</td></tr>' : '')
        . ($tax > 0 ? '<tr><td style="padding:4px 0;">Tax</td><td align="right" style="padding:4px 0;">' . $cur . ' ' . number_format($tax, 2) . '</td></tr>' : '')
        . '<tr><td style="padding:8px 0 0;border-top:1px solid #d9cec1;font-weight:700;">TOTAL</td><td align="right" style="padding:8px 0 0;border-top:1px solid #d9cec1;font-weight:700;font-size:15px;">' . $cur . ' ' . number_format($total, 2) . '</td></tr>'
        . '<tr><td colspan="2" style="padding:8px 0 0;font-size:12px;color:#5a534c;">Paid via: ' . $method . '</td></tr>'
        . ($tendered !== null ? '<tr><td style="padding:4px 0;">Tendered</td><td align="right" style="padding:4px 0;">' . $cur . ' ' . number_format($tendered, 2) . '</td></tr>' : '')
        . ($change !== null && $change > 0 ? '<tr><td style="padding:4px 0;">Change</td><td align="right" style="padding:4px 0;">' . $cur . ' ' . number_format($change, 2) . '</td></tr>' : '')
        . ($extras ? '<tr><td colspan="2" style="padding:4px 0 0;font-size:11px;color:#5a534c;">' . $extras . '</td></tr>' : '')
        . '</table>'
        . '</td></tr>'
        . ($notes ? '<tr><td style="padding:14px 24px 0;"><div style="font-size:12px;color:#5a534c;background:#faf7f3;border:1px solid #ece3d9;border-radius:8px;padding:9px 10px;"><strong>Notes:</strong> ' . $notes . '</div></td></tr>' : '')
        . '<tr><td style="padding:18px 24px 22px;">'
        . '<div style="border-top:1px dashed #d9cec1;padding-top:10px;text-align:center;font-size:12px;color:#6a645d;line-height:1.5;">' . $footer . '</div>'
        . '</td></tr>'
        . '</table>'
        . '</td></tr>'
        . '</table>'
        . '</body></html>';
}

/* ---------- POST: email or whatsapp ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security token invalid.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            // Reload order
            $stmt = $pdo->prepare("SELECT * FROM stock_orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $orderRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$orderRow) throw new RuntimeException('Order not found.');

            if ($action === 'consolidate') {
                if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
                    throw new RuntimeException('Only admin/manager can adjust totals.');
                }
                if ($orderRow['status'] !== 'paid') {
                    throw new RuntimeException('Only paid orders can be re-consolidated.');
                }
                $discount = max(0, (float)($_POST['discount_amount'] ?? 0));
                $reason   = trim($_POST['discount_reason'] ?? '');
                $service  = max(0, (float)($_POST['service_charge'] ?? 0));
                $tax      = max(0, (float)($_POST['tax_amount'] ?? 0));
                $extraNotes = trim($_POST['extra_notes'] ?? '');

                // Recompute subtotal from line items so we can't be tricked by stale data
                $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(line_total), 0) FROM stock_order_items WHERE order_id = ?");
                $sumStmt->execute([$orderId]);
                $subtotal = (float)$sumStmt->fetchColumn();
                foreach (['discount' => $discount, 'service charge' => $service, 'tax' => $tax] as $label => $amount) {
                    if (!is_finite($amount) || $amount < 0 || $amount > 999999999.99) {
                        throw new RuntimeException('Invalid ' . $label . ' amount.');
                    }
                }
                if ($discount > $subtotal) throw new RuntimeException('Discount cannot exceed subtotal.');
                if ($discount > 0 && mb_strlen($reason) < 4) throw new RuntimeException('Provide a discount reason.');

                $newTotal = round($subtotal - $discount + $service + $tax, 2);

                $pdo->beginTransaction();
                $pdo->prepare("UPDATE stock_orders SET subtotal = ?, discount_amount = ?, discount_reason = ?, service_charge = ?, tax_amount = ?, total_amount = ?, notes = TRIM(BOTH '\n' FROM CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END, ?)), updated_at = NOW() WHERE id = ?")
                    ->execute([$subtotal, $discount, $reason ?: null, $service, $tax, $newTotal, $extraNotes ? '[Consolidation] ' . $extraNotes : '', $orderId]);

                // Sync payments table to new total
                $pdo->prepare("UPDATE payments SET payment_amount = ?, total_amount = ?, updated_at = NOW() WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL")
                    ->execute([$newTotal, $newTotal, $orderId]);

                // Audit
                if (function_exists('logOrderAudit')) {
                    // logOrderAudit lives in stock-orders.php; safer to inline:
                }
                $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, 'consolidated', ?, ?)")
                    ->execute([$orderId, $user['id'], $user['full_name'], json_encode(['subtotal' => $subtotal, 'discount' => $discount, 'service' => $service, 'tax' => $tax, 'total' => $newTotal, 'reason' => $reason]), $_SERVER['REMOTE_ADDR'] ?? null]);
                $pdo->commit();

                $message = 'Order totals consolidated. New total: ' . $currency . ' ' . number_format($newTotal, 2) . '.';
            } elseif ($action === 'email_receipt') {
                $to = trim($_POST['recipient'] ?? '');
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('A valid email address is required.');
                }
                // Build receipt
                $itemsStmt = $pdo->prepare("SELECT * FROM stock_order_items WHERE order_id = ? ORDER BY id");
                $itemsStmt->execute([$orderId]);
                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                $cashStmt = $pdo->prepare("SELECT full_name FROM admin_users WHERE id = ?");
                $cashStmt->execute([$orderRow['created_by']]);
                $cashier = $cashStmt->fetchColumn();
                ensureInvoiceNumber($pdo, $orderRow, $invoicePrefix);
                $orderRow = $pdo->prepare("SELECT * FROM stock_orders WHERE id = ?");
                $orderRow->execute([$orderId]);
                $orderRow = $orderRow->fetch(PDO::FETCH_ASSOC);

                $html = buildReceiptHtml($orderRow, $items, [
                    'currency' => $currency,
                    'site' => $siteName,
                    'address' => $hotelAddr,
                    'phone' => $hotelPhone,
                    'email' => $hotelEmail,
                    'footer' => $footerLine,
                    'cashier' => $cashier ?: '',
                ]);
                $subject = $siteName . ' — Receipt ' . ($orderRow['invoice_number'] ?: $orderRow['reference']);
                $toName = $orderRow['customer_name'] ?: 'Guest';

                // Insert delivery row first so we have a row to update
                $delIns = $pdo->prepare("INSERT INTO stock_order_deliveries (order_id, channel, recipient, status, sent_by) VALUES (?, 'email', ?, 'queued', ?)");
                $delIns->execute([$orderId, $to, $user['id']]);
                $deliveryId = (int)$pdo->lastInsertId();

                $result = sendEmail($to, $toName, $subject, $html);
                $ok = !empty($result['success']);
                $statusVal = $ok ? (($result['preview'] ?? false) ? 'preview' : 'sent') : 'failed';
                $errMsg = $ok ? null : ($result['message'] ?? 'unknown error');

                $pdo->prepare("UPDATE stock_order_deliveries SET status = ?, error_message = ?, sent_at = NOW() WHERE id = ?")
                    ->execute([$statusVal, $errMsg, $deliveryId]);

                if ($ok) {
                    $pdo->prepare("UPDATE stock_orders SET receipt_sent_at = NOW(), receipt_sent_to = ?, receipt_send_count = receipt_send_count + 1, customer_email = COALESCE(customer_email, ?) WHERE id = ?")
                        ->execute([$to, $to, $orderId]);
                    $message = $statusVal === 'preview'
                        ? 'Email preview generated (development mode). No live email sent.'
                        : 'Receipt emailed to ' . htmlspecialchars($to) . '.';
                } else {
                    throw new RuntimeException('Email failed: ' . $errMsg);
                }
            } elseif ($action === 'whatsapp_receipt') {
                $phone = preg_replace('/[^0-9+]/', '', $_POST['recipient'] ?? '');
                if ($phone === '') throw new RuntimeException('Phone number required.');

                $whatsappMissing = [];
                if (!$whatsappEnabled) $whatsappMissing[] = 'restaurant_whatsapp_enabled=0';
                if ($whatsappNumber === '') $whatsappMissing[] = 'whatsapp_number missing';
                if ($whatsappApiToken === '') $whatsappMissing[] = 'whatsapp_api_token missing';
                $whatsappNotReadyMessage = empty($whatsappMissing)
                    ? null
                    : 'WhatsApp not ready (' . implode(', ', $whatsappMissing) . '). Logged for future dispatch.';

                // Provision-only: log the intent, mark as queued. A future worker will pick it up.
                $pdo->prepare("INSERT INTO stock_order_deliveries (order_id, channel, recipient, status, sent_by, error_message) VALUES (?, 'whatsapp', ?, ?, ?, ?)")
                    ->execute([
                        $orderId,
                        $phone,
                        $whatsappReady ? 'queued' : 'preview',
                        $user['id'],
                        $whatsappNotReadyMessage,
                    ]);
                $pdo->prepare("UPDATE stock_orders SET customer_phone = COALESCE(customer_phone, ?), whatsapp_sent_to = ? WHERE id = ?")
                    ->execute([$phone, $phone, $orderId]);

                $message = $whatsappReady
                    ? 'Receipt queued for WhatsApp delivery to ' . htmlspecialchars($phone) . '.'
                    : 'WhatsApp delivery is provisioned but not fully configured yet. The intent has been logged — no billable send was triggered.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('DB Error [stock-receipt action]: ' . $e->getMessage());
            $error = 'Unable to complete receipt action. Please try again.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

/* ---------- Load order for view ---------- */
$stmt = $pdo->prepare("
    SELECT so.*, au.full_name AS cashier_name, b.booking_reference
    FROM stock_orders so
    LEFT JOIN admin_users au ON au.id = so.created_by
    LEFT JOIN bookings b ON b.id = so.booking_id
    WHERE so.id = ?
");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

ensureInvoiceNumber($pdo, $order, $invoicePrefix);
// reload to get the new invoice number
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

$itemsStmt = $pdo->prepare("SELECT * FROM stock_order_items WHERE order_id = ? ORDER BY id");
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$deliveriesStmt = $pdo->prepare("SELECT * FROM stock_order_deliveries WHERE order_id = ? ORDER BY sent_at DESC");
$deliveriesStmt->execute([$orderId]);
$deliveries = $deliveriesStmt->fetchAll(PDO::FETCH_ASSOC);

$ctx = [
    'currency' => $currency,
    'site' => $siteName,
    'address' => $hotelAddr,
    'phone' => $hotelPhone,
    'email' => $hotelEmail,
    'footer' => $footerLine,
    'cashier' => $order['cashier_name'] ?? '',
];
$receiptHtml = buildReceiptHtml($order, $items, $ctx);

/* ---------- Print-only mode ---------- */
if (!empty($_GET['print'])) {
    if (!empty($_GET['kot'])) {
        // Kitchen Order Ticket — minimal, big text, no prices, prints from kitchen printer.
        $kotTime = date('Y-m-d H:i');
        $isRoomService = ($order['order_type'] ?? '') === 'room_service';
        $roomNo = trim((string)($order['room_number'] ?? ''));
        if ($isRoomService && $roomNo === '' && !empty($order['table_number'])) {
            $roomNo = trim(preg_replace('/^Room\s+/i', '', (string)$order['table_number']));
        }
        $tbl = $isRoomService
            ? ('Room ' . htmlspecialchars($roomNo ?: 'unlinked'))
            : ($order['table_number'] ? 'Table ' . htmlspecialchars($order['table_number']) : strtoupper(str_replace('_', ' ', $order['order_type'] ?? 'walk_in')));
        $cust = $order['customer_name'] ? htmlspecialchars($order['customer_name']) : '';
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>KOT ' . htmlspecialchars($order['reference']) . '</title>';
        echo '<style>body{font-family:Arial,sans-serif;width:80mm;margin:0;padding:8px;font-size:14px;color:#000;}h1{font-size:20px;margin:0 0 4px;text-align:center;border-bottom:2px solid #000;padding-bottom:6px;}h2{font-size:16px;margin:6px 0;}.line{border-bottom:1px dashed #000;padding:6px 0;}.qty{font-size:22px;font-weight:700;}.nm{font-size:16px;font-weight:700;}.note{font-style:italic;font-size:13px;margin-top:2px;}.foot{margin-top:10px;border-top:2px solid #000;padding-top:6px;text-align:center;font-size:11px;}@media print{body{margin:0;padding:0;}}</style>';
        echo '</head><body>';
        echo '<h1>KITCHEN TICKET</h1>';
        echo '<div style="text-align:center;font-size:13px;">' . htmlspecialchars($order['reference']) . '</div>';
        echo '<h2>' . $tbl . ($cust ? ' · ' . $cust : '') . '</h2>';
        echo '<div style="font-size:12px;">Cashier: ' . htmlspecialchars($order['cashier_name'] ?? '') . ' · ' . $kotTime . '</div>';
        if (!empty($order['notes'])) echo '<div class="note" style="margin-top:6px;border:1px solid #000;padding:4px;"><strong>NOTE:</strong> ' . htmlspecialchars($order['notes']) . '</div>';
        echo '<div style="margin-top:8px;">';
        foreach ($items as $it) {
            $q = rtrim(rtrim(number_format((float)$it['quantity'], 2), '0'), '.');
            echo '<div class="line"><span class="qty">' . $q . '×</span> <span class="nm">' . htmlspecialchars($it['item_name']) . '</span>';
            if (!empty($it['notes'])) echo '<div class="note">→ ' . htmlspecialchars($it['notes']) . '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '<div class="foot">' . count($items) . ' items · ' . htmlspecialchars($order['order_type'] ?? '') . '</div>';
        echo '<script>window.onload=function(){window.print();};</script>';
        echo '</body></html>';
        exit;
    }
    echo $receiptHtml;
    echo '<script>window.onload=function(){window.print();};</script>';
    exit;
}

$csrf_token = generateCsrfToken();
$canConsolidate = in_array($user['role'] ?? '', ['admin', 'manager'], true);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt — <?php echo htmlspecialchars($order['reference']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-responsive-enhancements.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/stock-receipt.css">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header" style="display:flex;align-items:center;gap:14px;">
            <a href="stock-orders.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> Back to orders</a>
            <h2 class="page-title" style="flex:1;"><i class="fas fa-receipt" style="color:#8B7355;"></i> Receipt — <?php echo htmlspecialchars($order['reference']); ?></h2>
            <a href="stock-receipt.php?id=<?php echo (int)$orderId; ?>&print=1" target="_blank" class="btn-primary"><i class="fas fa-print"></i> Print</a>
        </div>

        <?php if ($message): showAlert($message, 'success');
        endif; ?>
        <?php if ($error):   showAlert(htmlspecialchars($error, ENT_QUOTES, 'UTF-8'),   'error');
        endif; ?>

        <div class="receipt-grid">
            <div class="receipt-frame">
                <iframe srcdoc="<?php echo htmlspecialchars($receiptHtml, ENT_QUOTES); ?>" style="width:100%;height:760px;border:none;"></iframe>
            </div>

            <div>
                <!-- Email -->
                <div class="panel">
                    <h3><i class="fas fa-envelope"></i> Email receipt to guest</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="email_receipt">
                        <input type="hidden" name="order_id" value="<?php echo (int)$orderId; ?>">
                        <label>Recipient email</label>
                        <input type="email" name="recipient" required value="<?php echo htmlspecialchars($order['customer_email'] ?? ''); ?>" placeholder="guest@example.com">
                        <button type="submit" class="btn-primary" style="margin-top:10px;width:100%;"><i class="fas fa-paper-plane"></i> Send receipt</button>
                    </form>
                    <?php if ($order['receipt_sent_at']): ?>
                        <p style="font-size:11px;color:#155724;margin-top:8px;"><i class="fas fa-check"></i> Last sent <?php echo date('Y-m-d H:i', strtotime($order['receipt_sent_at'])); ?> to <?php echo htmlspecialchars($order['receipt_sent_to']); ?> (<?php echo (int)$order['receipt_send_count']; ?>x)</p>
                    <?php endif; ?>
                </div>

                <!-- WhatsApp (provision) -->
                <div class="panel">
                    <h3><i class="fab fa-whatsapp" style="color:#25D366;"></i> WhatsApp receipt</h3>
                    <?php if (!$whatsappReady): ?>
                        <span class="badge-future"><i class="fas fa-info-circle"></i> Provision-only — no live WhatsApp messages will be sent or charged until setup is complete.</span>
                        <div style="margin-top:8px;font-size:11px;color:#6b7280;line-height:1.5;">
                            <div><strong>Readiness:</strong> <?php echo $whatsappEnabled ? 'Enabled' : 'Disabled'; ?></div>
                            <div><i class="fas <?php echo $whatsappNumber !== '' ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i> Number: <?php echo $whatsappNumber !== '' ? 'Configured' : 'Missing'; ?></div>
                            <div><i class="fas <?php echo $whatsappApiToken !== '' ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i> API token: <?php echo $whatsappApiToken !== '' ? 'Configured' : 'Missing'; ?></div>
                            <a href="whatsapp-settings.php" style="display:inline-block;margin-top:6px;color:#8B7355;text-decoration:none;"><i class="fas fa-sliders"></i> Open WhatsApp settings</a>
                        </div>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="whatsapp_receipt">
                        <input type="hidden" name="order_id" value="<?php echo (int)$orderId; ?>">
                        <label>Phone number (with country code)</label>
                        <input type="text" name="recipient" required value="<?php echo htmlspecialchars($order['customer_phone'] ?? ''); ?>" placeholder="+265 999 123 456">
                        <button type="submit" class="btn-whatsapp" style="margin-top:10px;width:100%;"><i class="fab fa-whatsapp"></i> <?php echo $whatsappReady ? 'Send via WhatsApp' : 'Queue for WhatsApp'; ?></button>
                    </form>
                </div>

                <!-- Manual consolidation -->
                <?php if ($canConsolidate && $order['status'] === 'paid'): ?>
                    <div class="panel">
                        <h3><i class="fas fa-balance-scale"></i> Manual consolidation</h3>
                        <p style="font-size:11px;color:#6c757d;margin:0 0 8px;">Apply a manager-approved discount, service charge or tax. Every change is audited.</p>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="consolidate">
                            <input type="hidden" name="order_id" value="<?php echo (int)$orderId; ?>">
                            <label>Discount amount (<?php echo $currency; ?>)</label>
                            <input type="number" step="0.01" min="0" name="discount_amount" value="<?php echo number_format((float)$order['discount_amount'], 2, '.', ''); ?>">
                            <label>Discount reason</label>
                            <input type="text" name="discount_reason" maxlength="255" value="<?php echo htmlspecialchars($order['discount_reason'] ?? ''); ?>" placeholder="e.g. Loyal guest courtesy">
                            <label>Service charge (<?php echo $currency; ?>)</label>
                            <input type="number" step="0.01" min="0" name="service_charge" value="<?php echo number_format((float)$order['service_charge'], 2, '.', ''); ?>">
                            <label>Tax amount (<?php echo $currency; ?>)</label>
                            <input type="number" step="0.01" min="0" name="tax_amount" value="<?php echo number_format((float)$order['tax_amount'], 2, '.', ''); ?>">
                            <label>Notes (appended to order)</label>
                            <textarea name="extra_notes" rows="2" placeholder="Optional context for this adjustment"></textarea>
                            <button type="submit" class="btn-primary" style="margin-top:10px;width:100%;" onclick="return confirm('Re-consolidate totals? Payment record will sync to the new total.');"><i class="fas fa-save"></i> Apply &amp; recompute</button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Delivery log -->
                <div class="panel">
                    <h3><i class="fas fa-history"></i> Delivery history</h3>
                    <?php if (empty($deliveries)): ?>
                        <p style="font-size:12px;color:#6c757d;margin:0;">No receipts dispatched yet.</p>
                    <?php else: ?>
                        <div class="delivery-list">
                            <?php foreach ($deliveries as $d): ?>
                                <div class="row">
                                    <div><strong><?php echo strtoupper(htmlspecialchars($d['channel'])); ?></strong> · <?php echo htmlspecialchars($d['recipient']); ?> <span class="pill <?php echo htmlspecialchars($d['status']); ?>"><?php echo htmlspecialchars($d['status']); ?></span></div>
                                    <div style="color:#6c757d;font-size:11px;"><?php echo $d['sent_at'] ? date('Y-m-d H:i', strtotime($d['sent_at'])) : '—'; ?></div>
                                    <?php if (!empty($d['error_message'])): ?>
                                        <div style="color:#856404;font-size:11px;margin-top:2px;"><?php echo htmlspecialchars($d['error_message']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
