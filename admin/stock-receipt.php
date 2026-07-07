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
    $cashier    = htmlspecialchars($ctx['cashier'] ?: '');
    $splitLegs  = $ctx['split_legs'] ?? [];
    $notes  = htmlspecialchars($order['notes'] ?: '');
    $method = htmlspecialchars(ucwords(str_replace('_', ' ', $order['payment_method'] ?: '—')));
    $statusLabel = htmlspecialchars(ucfirst($order['status']));
    $isVoid = in_array($order['status'], ['voided', 'cancelled'], true);

    $rows = '';
    foreach ($items as $it) {
        $noteRow = !empty($it['notes']) ? '<div style="font-size:11px;color:#8B7355;font-style:italic;">→ ' . htmlspecialchars($it['notes']) . '</div>' : '';
        $rows .= '<tr>'
            . '<td style="padding:6px 8px;border:1px solid #e0d8ce;">' . htmlspecialchars($it['item_name']) . $noteRow . '</td>'
            . '<td style="padding:6px 8px;border:1px solid #e0d8ce;text-align:right;white-space:nowrap;">' . number_format((float)$it['quantity'], 2) . '</td>'
            . '<td style="padding:6px 8px;border:1px solid #e0d8ce;text-align:right;white-space:nowrap;">' . $cur . ' ' . number_format((float)$it['unit_price'], 2) . '</td>'
            . '<td style="padding:6px 8px;border:1px solid #e0d8ce;text-align:right;white-space:nowrap;">' . $cur . ' ' . number_format((float)$it['line_total'], 2) . '</td>'
            . '</tr>';
    }

    $subtotal   = (float)$order['subtotal'] ?: array_sum(array_map(fn($i) => (float)$i['line_total'], $items));
    $discount   = (float)$order['discount_amount'];
    $service    = (float)$order['service_charge'];
    $tax        = (float)$order['tax_amount'];
    $total      = (float)$order['total_amount'];
    $tip        = (float)($order['tip_amount'] ?? 0);
    $splitCount = max(1, (int)($order['split_count'] ?? 1));
    $grandTotal = $total + $tip;
    $tendered   = $order['tendered_amount'] !== null ? (float)$order['tendered_amount'] : null;
    $change     = $order['change_due'] !== null ? (float)$order['change_due'] : null;

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

    // Logo via public HTTPS URL so hotel_embed_logo_cid() can reference it (prevents orphaned PNG attachment)
    $logoUrl  = function_exists('hotel_email_logo_url') ? hotel_email_logo_url() : '';
    $logoHtml = $logoUrl !== ''
        ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $site . '" style="max-height:60px;width:auto;display:block;margin:0 auto 10px;">'
        : '';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt ' . $ref . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f7f3ee;font-family:Arial,Helvetica,sans-serif;color:#1f1c18;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f7f3ee;padding:22px 10px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #ece3d9;border-radius:12px;overflow:hidden;">'
        . '<tr><td style="padding:18px 24px 16px;border-bottom:1px solid #ede7df;text-align:center;">'
        . $voidBanner
        . $logoHtml
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
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:13px;border:1px solid #d9cec1;">'
        . '<thead><tr style="background:#8B7355;"><th style="padding:8px 8px 8px 8px;text-align:left;color:#ffffff;border-right:1px solid #9A8775;border-bottom:2px solid #6d5a44;">Item</th><th style="padding:8px;text-align:right;color:#ffffff;border-right:1px solid #9A8775;border-bottom:2px solid #6d5a44;white-space:nowrap;">Qty</th><th style="padding:8px;text-align:right;color:#ffffff;border-right:1px solid #9A8775;border-bottom:2px solid #6d5a44;white-space:nowrap;">Unit Price</th><th style="padding:8px;text-align:right;color:#ffffff;border-bottom:2px solid #6d5a44;white-space:nowrap;">Line Total</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody>'
        . '</table>'
        . '</td></tr>'
        . '<tr><td style="padding:14px 24px 0;">'
        . '<table role="presentation" align="right" cellspacing="0" cellpadding="0" style="font-size:13px;color:#3f3933;min-width:300px;border-collapse:collapse;border:1px solid #d9cec1;">'
        . '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;">Subtotal</td><td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;white-space:nowrap;">' . $cur . ' ' . number_format($subtotal, 2) . '</td></tr>'
        . ($discount > 0 ? '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;">Discount' . ($order['discount_reason'] ? ' (' . htmlspecialchars($order['discount_reason']) . ')' : '') . '</td><td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;color:#b3261e;white-space:nowrap;">−' . $cur . ' ' . number_format($discount, 2) . '</td></tr>' : '')
        . ($service > 0 ? '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;">Service charge</td><td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;white-space:nowrap;">' . $cur . ' ' . number_format($service, 2) . '</td></tr>' : '')
        . ($tax > 0 ? '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;">Tax</td><td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;white-space:nowrap;">' . $cur . ' ' . number_format($tax, 2) . '</td></tr>' : '')
        . ($tip > 0 ? '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;color:#059669;font-weight:600;">Tip</td><td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;color:#059669;font-weight:600;white-space:nowrap;">+ ' . $cur . ' ' . number_format($tip, 2) . '</td></tr>' : '')
        . '<tr style="background:#3f3933;"><td style="padding:8px 10px;font-weight:700;color:#ffffff;border-right:1px solid #5a534c;">' . ($tip > 0 ? 'GRAND TOTAL' : 'TOTAL') . '</td><td align="right" style="padding:8px 10px;font-weight:700;font-size:15px;color:#D5B37C;white-space:nowrap;">' . $cur . ' ' . number_format($grandTotal, 2) . '</td></tr>'
        . (function_exists('vat_document_note') && vat_document_note() !== '' ? '<tr><td colspan="2" style="padding:6px 10px;font-size:11px;color:#7a6f63;text-align:center;">' . htmlspecialchars(vat_document_note(), ENT_QUOTES, 'UTF-8') . '</td></tr>' : '')
        . ($splitCount > 1 ? '<tr><td colspan="2" style="padding:5px 10px;font-size:12px;color:#5a534c;background:#faf7f3;border-top:1px solid #e8e0d5;"><i class="fas fa-users"></i> Split ' . $splitCount . ' ways — ' . $cur . ' ' . number_format($total / $splitCount, 2) . ' each</td></tr>' : '')
        . '<tr><td colspan="2" style="padding:6px 10px;font-size:12px;color:#5a534c;border-top:1px solid #d9cec1;">Paid via: ' . $method . '</td></tr>'
        . ($tendered !== null ? '<tr><td style="padding:4px 10px;border-right:1px solid #d9cec1;">Tendered</td><td align="right" style="padding:4px 10px;white-space:nowrap;">' . $cur . ' ' . number_format($tendered, 2) . '</td></tr>' : '')
        . ($change !== null && $change > 0 ? '<tr><td style="padding:4px 10px;border-top:1px solid #e8e0d5;border-right:1px solid #d9cec1;">Change</td><td align="right" style="padding:4px 10px;border-top:1px solid #e8e0d5;white-space:nowrap;">' . $cur . ' ' . number_format($change, 2) . '</td></tr>' : '')
        . ($extras ? '<tr><td colspan="2" style="padding:4px 10px;font-size:11px;color:#5a534c;">' . $extras . '</td></tr>' : '')
        . '</table>'
        . '</td></tr>'
        . (!empty($splitLegs) ? (function() use ($splitLegs, $cur): string {
            $rows = '';
            $methodNames = ['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'card_manual' => 'Card (manual)', 'card_pos' => 'Card POS', 'other' => 'Other'];
            foreach ($splitLegs as $leg) {
                $legMethod = htmlspecialchars($methodNames[$leg['payment_method']] ?? ucwords(str_replace('_', ' ', $leg['payment_method'])));
                $legAmt = (float)$leg['split_amount'] + (float)$leg['tip_amount'];
                $tipNote = (float)$leg['tip_amount'] > 0 ? ' <span style="color:#059669;">(+tip ' . $cur . ' ' . number_format((float)$leg['tip_amount'], 2) . ')</span>' : '';
                $changeNote = ($leg['change_due'] !== null && (float)$leg['change_due'] > 0) ? ' · Chg ' . $cur . ' ' . number_format((float)$leg['change_due'], 2) : '';
                $rows .= '<tr><td style="padding:5px 8px;border-bottom:1px solid #ede7df;">#' . (int)$leg['split_number'] . '</td>'
                    . '<td style="padding:5px 8px;border-bottom:1px solid #ede7df;">' . $legMethod . '</td>'
                    . '<td align="right" style="padding:5px 8px;border-bottom:1px solid #ede7df;white-space:nowrap;">' . $cur . ' ' . number_format($legAmt, 2) . $tipNote . $changeNote . '</td></tr>';
            }
            return '<tr><td style="padding:10px 24px 0;">'
                . '<div style="font-size:12px;color:#374151;font-weight:700;margin-bottom:4px;">Split payment breakdown</div>'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:12px;color:#3f3933;border-collapse:collapse;border:1px solid #d9cec1;">'
                . '<thead><tr style="background:#f5f0ea;"><th style="padding:5px 8px;text-align:left;border-bottom:1px solid #d9cec1;">Leg</th><th style="padding:5px 8px;text-align:left;border-bottom:1px solid #d9cec1;">Method</th><th style="padding:5px 8px;text-align:right;border-bottom:1px solid #d9cec1;">Amount</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table>'
                . '</td></tr>';
        })() : '')
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

                // Sync payments table — recalculate VAT split from the new gross total
                $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
                $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0.0;
                $newNet = ($newTotal > 0 && $vatRate > 0) ? round($newTotal / (1 + ($vatRate / 100)), 2) : round($newTotal, 2);
                $newVat = round($newTotal - $newNet, 2);
                $pdo->prepare("UPDATE payments SET payment_amount = ?, vat_rate = ?, vat_amount = ?, total_amount = ?, updated_at = NOW() WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL")
                    ->execute([$newNet, $vatEnabled ? $vatRate : 0.0, $newVat, $newTotal, $orderId]);

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

                // Fetch split legs for split orders
                $emailSplitLegs = [];
                if ((int)($orderRow['split_count'] ?? 1) > 1) {
                    try {
                        $esl = $pdo->prepare("SELECT * FROM stock_order_splits WHERE order_id = ? ORDER BY split_number");
                        $esl->execute([$orderId]);
                        $emailSplitLegs = $esl->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Throwable $eslEx) { /* pre-migration guard */ }
                }
                $html = buildReceiptHtml($orderRow, $items, [
                    'currency'   => $currency,
                    'site'       => $siteName,
                    'address'    => $hotelAddr,
                    'phone'      => $hotelPhone,
                    'email'      => $hotelEmail,
                    'footer'     => $footerLine,
                    'cashier'    => $cashier ?: '',
                    'split_legs' => $emailSplitLegs,
                ]);
                $subject = $siteName . ' — Receipt ' . ($orderRow['invoice_number'] ?: $orderRow['reference']);
                $toName = $orderRow['customer_name'] ?: 'Guest';

                // Insert delivery row first so we have a row to update
                $delIns = $pdo->prepare("INSERT INTO stock_order_deliveries (order_id, channel, recipient, status, sent_by) VALUES (?, 'email', ?, 'queued', ?)");
                $delIns->execute([$orderId, $to, $user['id']]);
                $deliveryId = (int)$pdo->lastInsertId();

                // Attach a PDF copy of the receipt if TCPDF is available
                $pdfAttachments = [];
                if (function_exists('bookingRenderPdfFromHtml')) {
                    try {
                        $pdfBytes = bookingRenderPdfFromHtml($html, $subject);
                        if ($pdfBytes !== '') {
                            $pdfName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $orderRow['invoice_number'] ?: $orderRow['reference']) . '.pdf';
                            $pdfAttachments = [['content' => $pdfBytes, 'name' => $pdfName, 'mime' => 'application/pdf']];
                        }
                    } catch (Throwable $pdfEx) {
                        error_log('stock-receipt: PDF generation failed: ' . $pdfEx->getMessage());
                    }
                }

                $result = !empty($pdfAttachments)
                    ? sendEmailWithAttachments($to, $toName, $subject, $html, $pdfAttachments)
                    : sendEmail($to, $toName, $subject, $html);
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

// XHR path: return JSON so POS receipt modal can handle responses inline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8');
    if (!empty($error)) {
        echo json_encode(['ok' => false, 'error' => $error]);
    } else {
        echo json_encode(['ok' => true, 'message' => $message ?? 'Done']);
    }
    exit;
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

// Fetch split legs for split orders (used in receipt display)
$splitLegs = [];
if ((int)($order['split_count'] ?? 1) > 1) {
    try {
        $splStmt = $pdo->prepare("SELECT * FROM stock_order_splits WHERE order_id = ? ORDER BY split_number");
        $splStmt->execute([$orderId]);
        $splitLegs = $splStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* table may not exist pre-migration */ }
}

$deliveriesStmt = $pdo->prepare("SELECT * FROM stock_order_deliveries WHERE order_id = ? ORDER BY sent_at DESC");
$deliveriesStmt->execute([$orderId]);
$deliveries = $deliveriesStmt->fetchAll(PDO::FETCH_ASSOC);

$ctx = [
    'currency'   => $currency,
    'site'       => $siteName,
    'address'    => $hotelAddr,
    'phone'      => $hotelPhone,
    'email'      => $hotelEmail,
    'footer'     => $footerLine,
    'cashier'    => $order['cashier_name'] ?? '',
    'split_legs' => $splitLegs,
];
$receiptHtml = buildReceiptHtml($order, $items, $ctx);

/* ---------- Print-only mode ---------- */
if (!empty($_GET['print'])) {
    if (!empty($_GET['kot'])) {
        // Kitchen Order Ticket: modernized thermal layout (80mm), no prices.
        $ticketTimeRaw = (string)($order['fired_at'] ?: ($order['kitchen_printed_at'] ?: ($order['created_at'] ?: '')));
        $kotTime = $ticketTimeRaw !== '' ? date('Y-m-d H:i', strtotime($ticketTimeRaw)) : date('Y-m-d H:i');
        $isRoomService = ($order['order_type'] ?? '') === 'room_service';
        $serviceLabel = strtoupper(str_replace('_', ' ', (string)($order['order_type'] ?? 'walk_in')));
        $roomNo = trim((string)($order['room_number'] ?? ''));
        if ($isRoomService && $roomNo === '' && !empty($order['table_number'])) {
            $roomNo = trim(preg_replace('/^Room\s+/i', '', (string)$order['table_number']));
        }
        $locationLabel = $isRoomService
            ? 'ROOM ' . strtoupper($roomNo !== '' ? $roomNo : 'UNLINKED')
            : ($order['table_number'] ? 'TABLE ' . strtoupper((string)$order['table_number']) : $serviceLabel);
        $cust = trim((string)($order['customer_name'] ?? ''));
        $cashierName = trim((string)($order['cashier_name'] ?? ''));
        $itemCount = count($items);
        $totalQty = 0.0;
        foreach ($items as $it) {
            $totalQty += (float)($it['quantity'] ?? 0);
        }
        $totalQtyText = rtrim(rtrim(number_format($totalQty, 2), '0'), '.');

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>KOT ' . htmlspecialchars($order['reference']) . '</title>';
        echo '<style>'
            . '*{box-sizing:border-box;}'
            . 'body{font-family:Arial,Helvetica,sans-serif;width:80mm;max-width:80mm;margin:0 auto;padding:4mm 3mm;color:#111;background:#fff;}'
            . '.kot-card{border:1.2px solid #111;padding:2.8mm 2.6mm;}'
            . '.kot-top{text-align:center;border-bottom:1px solid #111;padding-bottom:2mm;margin-bottom:2mm;}'
            . '.kot-site{font-size:12px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;line-height:1.2;}'
            . '.kot-title{font-size:11px;font-weight:700;letter-spacing:.17em;text-transform:uppercase;margin-top:1mm;}'
            . '.kot-ref{font-size:16px;font-weight:700;letter-spacing:.06em;line-height:1.1;margin-top:1.4mm;}'
            . '.kot-meta{width:100%;border-collapse:collapse;font-size:11px;line-height:1.25;}'
            . '.kot-meta td{padding:1.3mm 0;border-bottom:1px dashed #999;vertical-align:top;}'
            . '.kot-meta td.lbl{width:34%;font-size:9px;font-weight:700;letter-spacing:.11em;text-transform:uppercase;color:#333;}'
            . '.kot-note{margin-top:2mm;border:1px solid #111;padding:1.8mm;font-size:10.5px;line-height:1.35;}'
            . '.kot-lines{margin-top:2mm;border-top:1.2px solid #111;}'
            . '.kot-line{display:flex;gap:2mm;padding:2.1mm 0;border-bottom:1px dashed #999;}'
            . '.kot-qty{min-width:16mm;max-width:16mm;border:1px solid #111;text-align:center;font-weight:700;font-size:16px;line-height:1;padding:1.6mm 1mm;}'
            . '.kot-body{flex:1;min-width:0;}'
            . '.kot-name{font-size:12.8px;font-weight:700;line-height:1.18;text-transform:uppercase;word-break:break-word;}'
            . '.kot-item-note{font-size:10px;font-style:italic;line-height:1.28;margin-top:1mm;word-break:break-word;}'
            . '.kot-station{font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:#444;margin-top:1mm;}'
            . '.kot-empty{padding:3.2mm 0;text-align:center;font-size:11px;color:#444;font-style:italic;}'
            . '.kot-foot{margin-top:2.4mm;padding-top:2mm;border-top:1.2px solid #111;text-align:center;}'
            . '.kot-foot-main{font-size:10.8px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;}'
            . '.kot-foot-sub{font-size:9.5px;color:#333;margin-top:1mm;}'
            . '.kot-cut{margin-top:2.1mm;border-top:1px dashed #777;padding-top:1.4mm;text-align:center;font-size:8.6px;letter-spacing:.14em;text-transform:uppercase;color:#444;}'
            . '@media print{body{margin:0 auto;padding:0;} .kot-card{border-width:1px;}}'
            . '</style>';
        echo '</head><body>';
        echo '<div class="kot-card">';
        echo '<div class="kot-top">';
        echo '<div class="kot-site">' . htmlspecialchars($siteName) . '</div>';
        echo '<div class="kot-title">Kitchen Order Ticket</div>';
        echo '<div class="kot-ref">' . htmlspecialchars($order['reference']) . '</div>';
        echo '</div>';

        echo '<table class="kot-meta">';
        echo '<tr><td class="lbl">Time</td><td>' . htmlspecialchars($kotTime) . '</td></tr>';
        echo '<tr><td class="lbl">Service</td><td>' . htmlspecialchars($serviceLabel) . '</td></tr>';
        echo '<tr><td class="lbl">Location</td><td>' . htmlspecialchars($locationLabel) . '</td></tr>';
        if ($cashierName !== '') {
            echo '<tr><td class="lbl">Cashier</td><td>' . htmlspecialchars($cashierName) . '</td></tr>';
        }
        if ($cust !== '') {
            echo '<tr><td class="lbl">Guest</td><td>' . htmlspecialchars($cust) . '</td></tr>';
        }
        echo '</table>';

        if (!empty($order['notes'])) {
            echo '<div class="kot-note"><strong>Order note:</strong> ' . htmlspecialchars((string)$order['notes']) . '</div>';
        }

        echo '<div class="kot-lines">';
        if ($itemCount === 0) {
            echo '<div class="kot-empty">No line items found.</div>';
        } else {
            foreach ($items as $it) {
                $q = rtrim(rtrim(number_format((float)$it['quantity'], 2), '0'), '.');
                echo '<div class="kot-line">';
                echo '<div class="kot-qty">' . htmlspecialchars($q) . 'x</div>';
                echo '<div class="kot-body">';
                echo '<div class="kot-name">' . htmlspecialchars((string)$it['item_name']) . '</div>';
                if (!empty($it['notes'])) {
                    echo '<div class="kot-item-note">Note: ' . htmlspecialchars((string)$it['notes']) . '</div>';
                }
                if (!empty($it['station'])) {
                    echo '<div class="kot-station">Station: ' . htmlspecialchars(strtoupper((string)$it['station'])) . '</div>';
                }
                echo '</div>';
                echo '</div>';
            }
        }
        echo '</div>';

        echo '<div class="kot-foot">';
        echo '<div class="kot-foot-main">' . $itemCount . ' item(s) - ' . htmlspecialchars($totalQtyText !== '' ? $totalQtyText : '0') . ' qty total</div>';
        echo '<div class="kot-foot-sub">Prep in sequence and mark when complete.</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="kot-cut">Kitchen copy</div>';
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
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-receipt.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-receipt.css'); ?>">
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

