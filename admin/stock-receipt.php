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

/* ---------- Receipt HTML: built in config/receipts.php so CLI/test scripts share it ---------- */
require_once __DIR__ . '/../config/receipts.php';

function buildReceiptHtml(array $order, array $items, array $ctx): string
{
    return receipt_build_restaurant_email_html($order, $items, $ctx);
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
                /* Paid orders are immutable: once any payment (or split leg) is recorded the total,
                 * discount and service charge are fixed. A change is a refund / credit note plus a
                 * new sale - never an edit of what the guest already paid. */
                /* Take the order row lock BEFORE judging whether it is still unpaid, and keep it until the
                 * totals are written. Checked outside the lock, a payment landing in between would be
                 * followed by a total rewrite on an order that had just been paid. */
                $pdo->beginTransaction();
                $lockStmt = $pdo->prepare("SELECT status, COALESCE(split_paid_count, 0) AS split_paid_count FROM stock_orders WHERE id = ? FOR UPDATE");
                $lockStmt->execute([$orderId]);
                $orderRow = array_merge($orderRow, $lockStmt->fetch(PDO::FETCH_ASSOC) ?: []);
                $paidLegs = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL");
                $paidLegs->execute([$orderId]);
                if ($orderRow['status'] !== 'placed' || (int)($orderRow['split_paid_count'] ?? 0) > 0 || (int)$paidLegs->fetchColumn() > 0) {
                    throw new RuntimeException('This order has been paid - its totals can no longer be changed. Use the refund flow and take a new sale instead.');
                }
                $discount = max(0, (float)($_POST['discount_amount'] ?? 0));
                $reason   = trim($_POST['discount_reason'] ?? '');
                $service  = max(0, (float)($_POST['service_charge'] ?? 0));
                $tax      = 0.0; // F&B prices are gross; VAT is extracted from them, never added on top
                $extraNotes = trim($_POST['extra_notes'] ?? '');

                // Recompute subtotal from line items so we can't be tricked by stale data
                $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(line_total), 0) FROM stock_order_items WHERE order_id = ? AND kds_status <> 'void'");
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

                $pdo->prepare("UPDATE stock_orders SET subtotal = ?, discount_amount = ?, discount_reason = ?, service_charge = ?, tax_amount = ?, total_amount = ?, notes = TRIM(BOTH '\n' FROM CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END, ?)), updated_at = NOW() WHERE id = ?")
                    ->execute([$subtotal, $discount, $reason ?: null, $service, $tax, $newTotal, $extraNotes ? '[Consolidation] ' . $extraNotes : '', $orderId]);

                // Sync payments table — recalculate VAT split from the new gross total
                $vatEnabled = rh_vat_enabled();
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
                        // PDF is built on the themed document kit (A4, real columns), not the email HTML
                        $pdfBytes = receipt_restaurant_pdf_bytes($orderRow, $items, [
                            'currency'   => $currency,
                            'footer'     => $footerLine,
                            'cashier'    => $cashier ?: '',
                            'split_legs' => $emailSplitLegs,
                        ]);
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

                // Receipt text (plain WhatsApp message).
                $waRef = (string)($orderRow['invoice_number'] ?: $orderRow['reference']);
                $waName = trim((string)($orderRow['customer_name'] ?? ''));
                $waBody = 'Hello' . ($waName !== '' ? ' ' . $waName : '') . ', thank you for visiting ' . $siteName . '. '
                    . 'Your receipt ' . $waRef . ': total ' . $currency . ' ' . number_format((float)($orderRow['total_amount'] ?? 0), 2) . '.';
                $waFooter = trim((string)getSetting('restaurant_receipt_footer', ''));
                if ($waFooter !== '') $waBody .= ' ' . $waFooter;

                require_once __DIR__ . '/../includes/whatsapp-functions.php';
                $waUrl = '';
                if (function_exists('isWhatsAppEnabled') && isWhatsAppEnabled()) {
                    // A WhatsApp provider is configured (Settings -> WhatsApp): send it from the hotel's account.
                    $waResult = sendWhatsAppMessage($phone, $waBody);
                    $waOk = !empty($waResult['success']);
                    $waStatus = $waOk ? 'sent' : 'failed';
                    $waError = $waOk ? null : (string)($waResult['message'] ?? 'WhatsApp send failed');
                } else {
                    // No provider: open WhatsApp on this device with the receipt typed in (click-to-chat),
                    // the same way hotel payment receipts work. Staff press Send in WhatsApp.
                    $waDigits = ltrim(preg_replace('/[^0-9]/', '', $phone), '0');
                    $waUrl = 'https://wa.me/' . $waDigits . '?text=' . rawurlencode($waBody);
                    $waOk = true;
                    $waStatus = 'preview';
                    $waError = 'Opened in WhatsApp on the staff device (click-to-chat); sent by staff from WhatsApp.';
                }

                $pdo->prepare("INSERT INTO stock_order_deliveries (order_id, channel, recipient, status, sent_by, error_message, sent_at) VALUES (?, 'whatsapp', ?, ?, ?, ?, NOW())")
                    ->execute([$orderId, $phone, $waStatus, $user['id'], $waError]);
                $pdo->prepare("UPDATE stock_orders SET customer_phone = COALESCE(customer_phone, ?), whatsapp_sent_to = ? WHERE id = ?")
                    ->execute([$phone, $phone, $orderId]);

                if (!$waOk) {
                    throw new RuntimeException('WhatsApp receipt could not be sent: ' . $waError);
                }
                $message = $waUrl !== ''
                    ? 'Opening WhatsApp with the receipt for ' . htmlspecialchars($phone) . ' — press Send in WhatsApp.'
                    : 'Receipt sent by WhatsApp to ' . htmlspecialchars($phone) . '.';
                $waOpenUrl = $waUrl;
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
        echo json_encode(['ok' => true, 'message' => $message ?? 'Done', 'url' => $waOpenUrl ?? '']);
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

/* ---------- PDF view: same bytes that are emailed ---------- */
if (!empty($_GET['pdf'])) {
    try {
        $pdfBytes = receipt_restaurant_pdf_bytes($order, $items, $ctx);
    } catch (Throwable $pdfEx) {
        error_log('stock-receipt: PDF view failed: ' . $pdfEx->getMessage());
        http_response_code(500);
        exit('PDF unavailable.');
    }
    $pdfName = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($order['invoice_number'] ?: $order['reference'])) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $pdfName . '"');
    header('Content-Length: ' . strlen($pdfBytes));
    echo $pdfBytes;
    exit;
}

/* ---------- Print-only mode ---------- */
if (!empty($_GET['print'])) {
    if (!empty($_GET['kot'])) {
        // Kitchen Order Ticket: modernized thermal layout (80mm), no prices.
        $ticketTimeRaw = (string)($order['fired_at'] ?: ($order['kitchen_printed_at'] ?: ($order['created_at'] ?: '')));
        $kotTime = $ticketTimeRaw !== '' ? rhToUserTime($ticketTimeRaw) : rhToUserTime(date('Y-m-d H:i:s'));
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
            <h2 class="page-title" style="flex:1;"><i class="fas fa-receipt" style="color:#7E684B;"></i> Receipt — <?php echo htmlspecialchars($order['reference']); ?></h2>
            <a href="stock-receipt.php?id=<?php echo (int)$orderId; ?>&pdf=1" target="_blank" class="btn-secondary"><i class="fas fa-file-pdf"></i> PDF</a>
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
                        <p style="font-size:11px;color:#155724;margin-top:8px;"><i class="fas fa-check"></i> Last sent <?php echo htmlspecialchars(rhToUserTime($order['receipt_sent_at'])); ?> to <?php echo htmlspecialchars($order['receipt_sent_to']); ?> (<?php echo (int)$order['receipt_send_count']; ?>x)</p>
                    <?php endif; ?>
                </div>

                <!-- WhatsApp -->
                <div class="panel">
                    <h3><i class="fab fa-whatsapp" style="color:#25D366;"></i> WhatsApp receipt</h3>
                    <?php if (!empty($waOpenUrl)): ?>
                        <a href="<?php echo htmlspecialchars($waOpenUrl, ENT_QUOTES); ?>" target="_blank" rel="noopener" class="btn-whatsapp" style="display:block;text-align:center;margin-bottom:10px;"><i class="fab fa-whatsapp"></i> Open WhatsApp to send</a>
                    <?php endif; ?>
                    <p style="font-size:11px;color:#6b7280;line-height:1.5;margin:0 0 8px;">
                        <?php if (function_exists('isWhatsAppEnabled') && isWhatsAppEnabled()): ?>
                            Sent automatically from the hotel's WhatsApp account.
                        <?php else: ?>
                            Opens WhatsApp on this device with the receipt typed in — press Send in WhatsApp. To send automatically instead, set up a provider in <a href="whatsapp-settings.php" style="color:#7E684B;">WhatsApp settings</a>.
                        <?php endif; ?>
                    </p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="whatsapp_receipt">
                        <input type="hidden" name="order_id" value="<?php echo (int)$orderId; ?>">
                        <label>Phone number (with country code)</label>
                        <input type="text" name="recipient" required value="<?php echo htmlspecialchars($order['customer_phone'] ?? ''); ?>" placeholder="+265 999 123 456">
                        <button type="submit" class="btn-whatsapp" style="margin-top:10px;width:100%;"><i class="fab fa-whatsapp"></i> Send via WhatsApp</button>
                    </form>
                </div>

                <!-- Manual consolidation -->
                <?php if ($canConsolidate && $order['status'] === 'placed' && (int)($order['split_paid_count'] ?? 0) === 0): ?>
                    <div class="panel">
                        <h3><i class="fas fa-balance-scale"></i> Manual consolidation</h3>
                        <p style="font-size:11px;color:#6c757d;margin:0 0 8px;">Apply a manager-approved discount or service charge before payment. Once paid, totals are final. Every change is audited.</p>
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
                                    <div style="color:#6c757d;font-size:11px;"><?php echo $d['sent_at'] ? htmlspecialchars(rhToUserTime($d['sent_at'])) : '—'; ?></div>
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

