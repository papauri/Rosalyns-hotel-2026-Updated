<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/../includes/finance-sequences.php';

use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists('receipt_table_columns')) {
    function receipt_table_columns(PDO $pdo, string $table): array
    {
        static $cache = [];
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('Unsafe table name.');
        }
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];
        foreach ($rows as $row) {
            $field = (string)($row['Field'] ?? '');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
                $columns[$field] = true;
            }
        }
        $cache[$table] = $columns;
        return $columns;
    }
}

if (!function_exists('receipt_ensure_schema')) {
    function receipt_ensure_schema(PDO $pdo): void
    {
        $columns = receipt_table_columns($pdo, 'payments');
        $ddl = [];
        if (!isset($columns['receipt_path'])) {
            $ddl[] = 'ADD COLUMN receipt_path VARCHAR(255) NULL AFTER receipt_number';
        }
        if (!isset($columns['receipt_generated'])) {
            $ddl[] = 'ADD COLUMN receipt_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER receipt_path';
        }
        if (!isset($columns['receipt_generated_at'])) {
            $ddl[] = 'ADD COLUMN receipt_generated_at DATETIME NULL AFTER receipt_generated';
        }
        if (!isset($columns['receipt_emailed_at'])) {
            $ddl[] = 'ADD COLUMN receipt_emailed_at DATETIME NULL AFTER receipt_generated_at';
        }
        if (!isset($columns['receipt_email_count'])) {
            $ddl[] = 'ADD COLUMN receipt_email_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER receipt_emailed_at';
        }
        if ($ddl !== []) {
            $pdo->exec('ALTER TABLE payments ' . implode(', ', $ddl));
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id INT UNSIGNED NOT NULL,
            receipt_number VARCHAR(80) DEFAULT NULL,
            event_type VARCHAR(40) NOT NULL,
            recipient VARCHAR(255) DEFAULT NULL,
            channel VARCHAR(40) DEFAULT NULL,
            event_note TEXT NULL,
            performed_by INT UNSIGNED DEFAULT NULL,
            performed_by_name VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_receipt_events_payment (payment_id),
            KEY idx_receipt_events_receipt (receipt_number),
            KEY idx_receipt_events_type (event_type),
            KEY idx_receipt_events_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        receipt_ensure_template_settings($pdo);
    }
}

if (!function_exists('receipt_ensure_template_settings')) {
    function receipt_ensure_template_settings(PDO $pdo): void
    {
        $defaults = [
            'receipt_email_subject' => 'Receipt {{receipt_number}} - {{site_name}}',
            'receipt_email_template' => '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="font-family:Arial,sans-serif;background:#F7F3EE;margin:0;padding:20px;"><div style="max-width:640px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;"><div style="background:#231F1C;color:#B18247;text-align:center;padding:28px 34px;"><h1 style="margin:0;font-family:Georgia,serif;font-size:28px;">{{site_name}}</h1><p style="color:#F3ECE4;letter-spacing:.08em;text-transform:uppercase;margin:8px 0 0;font-size:12px;">Payment Receipt</p></div><div style="padding:28px 34px;color:#2A2723;"><p>Dear {{guest_name}},</p><p>Thank you for your payment. Your receipt is attached for your records.</p><table style="width:100%;border-collapse:collapse;background:#F7F3EE;border-radius:8px;overflow:hidden;"><tr><td style="padding:9px 12px;color:#5E554D;">Receipt No.</td><td style="padding:9px 12px;text-align:right;font-weight:700;">{{receipt_number}}</td></tr><tr><td style="padding:9px 12px;color:#5E554D;">Reference</td><td style="padding:9px 12px;text-align:right;">{{payment_reference}}</td></tr><tr><td style="padding:9px 12px;color:#5E554D;">Date</td><td style="padding:9px 12px;text-align:right;">{{payment_date}}</td></tr><tr><td style="padding:9px 12px;color:#5E554D;">Amount</td><td style="padding:9px 12px;text-align:right;font-weight:700;">{{total_amount}}</td></tr></table><p style="margin-top:22px;color:#5E554D;">Questions? Contact us at {{contact_email}}.</p></div></div></body></html>',
            'receipt_whatsapp_template' => 'Hello {{guest_name}}, your payment receipt {{receipt_number}} for {{total_amount}} at {{site_name}} is ready. Reference: {{payment_reference}}. Thank you.',
        ];

        $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'finance') ON DUPLICATE KEY UPDATE setting_key = setting_key");
        foreach ($defaults as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    }
}

if (!function_exists('receipt_format_money')) {
    function receipt_format_money(float $amount, string $currencySymbol): string
    {
        return trim($currencySymbol) . ' ' . number_format($amount, 2);
    }
}

if (!function_exists('receipt_get_payment')) {
    function receipt_get_payment(PDO $pdo, int $paymentId): ?array
    {
        $stmt = $pdo->prepare("SELECT p.*, COALESCE(au.full_name, au.username, p.processed_by) AS recorded_by_name
            FROM payments p
            LEFT JOIN admin_users au ON au.id = p.recorded_by
            WHERE p.id = ? AND p.deleted_at IS NULL
            LIMIT 1");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        return $payment ?: null;
    }
}

if (!function_exists('receipt_hydrate_context')) {
    function receipt_hydrate_context(PDO $pdo, array $payment): array
    {
        $guestName = 'Guest';
        $guestEmail = '';
        $guestPhone = '';
        $description = ucfirst(str_replace('_', ' ', (string)($payment['booking_type'] ?? 'payment')));

        if (($payment['booking_type'] ?? '') === 'room' && !empty($payment['booking_id'])) {
            $stmt = $pdo->prepare("SELECT b.guest_name, b.guest_email, b.guest_phone, b.booking_reference, r.name AS room_name
                FROM bookings b
                LEFT JOIN rooms r ON r.id = b.room_id
                WHERE b.id = ?");
            $stmt->execute([(int)$payment['booking_id']]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $guestName = (string)($booking['guest_name'] ?? $guestName);
            $guestEmail = (string)($booking['guest_email'] ?? '');
            $guestPhone = (string)($booking['guest_phone'] ?? '');
            $description = trim('Room booking ' . (string)($booking['booking_reference'] ?? $payment['booking_reference'] ?? '') . ' ' . (string)($booking['room_name'] ?? ''));
        } elseif (($payment['booking_type'] ?? '') === 'conference' && !empty($payment['booking_id'])) {
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(company_name, organization_name, contact_name, contact_person) AS guest_name,
                       COALESCE(contact_email, email, '') AS guest_email,
                       COALESCE(contact_phone, phone, '') AS guest_phone,
                       COALESCE(enquiry_reference, inquiry_reference, id) AS ref
                    FROM conference_inquiries WHERE id = ?");
                $stmt->execute([(int)$payment['booking_id']]);
                $booking = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $guestName = (string)($booking['guest_name'] ?? $guestName);
                $guestEmail = (string)($booking['guest_email'] ?? '');
                $guestPhone = (string)($booking['guest_phone'] ?? '');
                $description = 'Conference booking ' . (string)($booking['ref'] ?? $payment['booking_reference'] ?? '');
            } catch (Throwable $e) {
                $description = 'Conference payment ' . (string)($payment['booking_reference'] ?? '');
            }
        } elseif (($payment['booking_type'] ?? '') === 'restaurant' && !empty($payment['booking_id'])) {
            try {
                $stmt = $pdo->prepare("SELECT reference, customer_name, customer_email, customer_phone, order_type FROM stock_orders WHERE id = ?");
                $stmt->execute([(int)$payment['booking_id']]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $guestName = (string)($order['customer_name'] ?? $guestName);
                $guestEmail = (string)($order['customer_email'] ?? '');
                $guestPhone = (string)($order['customer_phone'] ?? '');
                $description = 'Restaurant order ' . (string)($order['reference'] ?? $payment['booking_reference'] ?? '');
            } catch (Throwable $e) {
                $description = 'Restaurant payment ' . (string)($payment['booking_reference'] ?? '');
            }
        }

        if ($guestName === '') {
            $guestName = 'Guest';
        }

        return [
            'guest_name' => $guestName,
            'guest_email' => $guestEmail,
            'guest_phone' => $guestPhone,
            'description' => trim($description),
        ];
    }
}

if (!function_exists('receipt_placeholders')) {
    function receipt_placeholders(PDO $pdo, array $payment, array $context): array
    {
        $currency = getSetting('currency_symbol', 'MWK');
        $siteName = getSetting('site_name', 'Hotel');
        $contactEmail = getEmailSetting('email_from_email', '') ?: getEmailSetting('smtp_username', '');
        $receiptNumber = (string)($payment['receipt_number'] ?? '');

        $vatEnabled  = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
        // Rate stored on this payment wins — a receipt re-sent after a rate
        // change must keep its original rate label.
        $vatRateNum  = (float)($payment['vat_rate'] ?? 0) > 0
            ? (float)$payment['vat_rate']
            : ($vatEnabled ? (float)getSetting('vat_rate') : 0.0);
        $vatNumStr   = (string)getSetting('vat_number', '');
        $vatNumHtml  = $vatNumStr !== ''
            ? '<p style="margin:8px 0 0;font-size:11px;color:#9b8f7e;text-align:center;">VAT Reg. No.: ' . htmlspecialchars($vatNumStr, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';
        // Use public HTTPS URL — email clients (Gmail/Outlook) block data: URIs
        $logoSrc  = function_exists('hotel_email_logo_url') ? hotel_email_logo_url() : '';
        $logoHtml = $logoSrc !== ''
            ? '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '" style="max-width:110px;height:auto;display:block;margin:0 auto;">'
            : '';

        return [
            '{{site_name}}' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
            '{{guest_name}}' => htmlspecialchars((string)$context['guest_name'], ENT_QUOTES, 'UTF-8'),
            '{{guest_email}}' => htmlspecialchars((string)$context['guest_email'], ENT_QUOTES, 'UTF-8'),
            '{{guest_phone}}' => htmlspecialchars((string)$context['guest_phone'], ENT_QUOTES, 'UTF-8'),
            '{{receipt_number}}' => htmlspecialchars($receiptNumber, ENT_QUOTES, 'UTF-8'),
            '{{booking_type}}' => htmlspecialchars(ucwords(str_replace('_', ' ', (string)($payment['booking_type'] ?? ''))), ENT_QUOTES, 'UTF-8'),
            '{{payment_reference}}' => htmlspecialchars((string)($payment['payment_reference'] ?? ''), ENT_QUOTES, 'UTF-8'),
            '{{booking_reference}}' => htmlspecialchars((string)($payment['booking_reference'] ?? ''), ENT_QUOTES, 'UTF-8'),
            '{{payment_date}}' => !empty($payment['payment_date']) ? date('d M Y', strtotime((string)$payment['payment_date'])) : '',
            '{{payment_method}}' => htmlspecialchars(ucwords(str_replace('_', ' ', (string)($payment['payment_method'] ?? ''))), ENT_QUOTES, 'UTF-8'),
            '{{payment_type}}' => htmlspecialchars(ucwords(str_replace('_', ' ', (string)($payment['payment_type'] ?? ''))), ENT_QUOTES, 'UTF-8'),
            '{{payment_status}}' => htmlspecialchars(ucwords(str_replace('_', ' ', (string)($payment['payment_status'] ?? ''))), ENT_QUOTES, 'UTF-8'),
            '{{payment_amount}}' => htmlspecialchars(receipt_format_money((float)($payment['payment_amount'] ?? 0), $currency), ENT_QUOTES, 'UTF-8'),
            '{{vat_amount}}' => htmlspecialchars(vat_document_value(receipt_format_money((float)($payment['vat_amount'] ?? 0), $currency)), ENT_QUOTES, 'UTF-8'),
            '{{total_amount}}' => htmlspecialchars(receipt_format_money((float)($payment['total_amount'] ?? 0), $currency), ENT_QUOTES, 'UTF-8'),
            '{{description}}' => htmlspecialchars((string)$context['description'], ENT_QUOTES, 'UTF-8'),
            '{{contact_email}}' => htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'),
            '{{contact_phone}}' => htmlspecialchars((string)getSetting('phone_main', ''), ENT_QUOTES, 'UTF-8'),
            '{{address}}' => htmlspecialchars((string)getSetting('hotel_address', getSetting('address', '')), ENT_QUOTES, 'UTF-8'),
            '{{hotel_address}}' => htmlspecialchars((string)getSetting('hotel_address', getSetting('address', '')), ENT_QUOTES, 'UTF-8'),
            '{{vat_number}}'      => htmlspecialchars($vatNumStr, ENT_QUOTES, 'UTF-8'),
            '{{vat_rate}}'        => $vatRateNum > 0.0 ? number_format($vatRateNum, 1) : '0',
            '{{vat_number_html}}' => $vatNumHtml,
            '{{logo_html}}'       => $logoHtml,
        ];
    }
}

if (!function_exists('receipt_log_event')) {
    function receipt_log_event(PDO $pdo, int $paymentId, ?string $receiptNumber, string $type, ?string $recipient, ?string $channel, ?string $note, ?array $user = null): void
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO receipt_events (payment_id, receipt_number, event_type, recipient, channel, event_note, performed_by, performed_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $paymentId,
                $receiptNumber,
                $type,
                $recipient,
                $channel,
                $note,
                isset($user['id']) ? (int)$user['id'] : null,
                $user['full_name'] ?? ($user['username'] ?? null),
            ]);
        } catch (Throwable $e) {
            error_log('receipt_log_event failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('receipt_build_pos_style_html_raw')) {
    /**
     * Build a clean POS-receipt-style HTML document for a payment record.
     * Visual design mirrors buildReceiptHtml() in stock-receipt.php.
     * Used for both the PDF attachment and injecting into the email body.
     */
    function receipt_build_pos_style_html_raw(array $payment, array $context, PDO $pdo): string
    {
        $currency    = getSetting('currency_symbol', 'MWK');
        $siteName    = getSetting('site_name', 'Hotel');
        $address     = trim((string)getSetting('hotel_address', getSetting('address', '')));
        $phone       = trim((string)getSetting('hotel_phone', getSetting('phone_main', '')));
        $email       = trim((string)(getEmailSetting('email_from_email', '') ?: getEmailSetting('smtp_username', '')));
        $footer      = trim((string)getSetting('receipt_footer', getSetting('payment_terms', 'Thank you for your payment.')));
        $vatEnabled  = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
        $vatRate     = $vatEnabled ? (float)getSetting('vat_rate') : 0.0;
        $vatNumber   = trim((string)getSetting('vat_number', ''));

        $receiptNumber = htmlspecialchars((string)($payment['receipt_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $payRef        = htmlspecialchars((string)($payment['payment_reference'] ?? ''), ENT_QUOTES, 'UTF-8');
        $bookingRef    = htmlspecialchars((string)($payment['booking_reference'] ?? ''), ENT_QUOTES, 'UTF-8');
        $date          = !empty($payment['payment_date']) ? date('d M Y', strtotime((string)$payment['payment_date'])) : date('d M Y');
        $guestName     = htmlspecialchars((string)($context['guest_name'] ?? 'Guest'), ENT_QUOTES, 'UTF-8');
        $guestEmail    = htmlspecialchars((string)($context['guest_email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $guestPhone    = htmlspecialchars((string)($context['guest_phone'] ?? ''), ENT_QUOTES, 'UTF-8');
        $method        = htmlspecialchars(ucwords(str_replace('_', ' ', (string)($payment['payment_method'] ?? ''))), ENT_QUOTES, 'UTF-8');
        $recordedBy    = htmlspecialchars((string)($payment['recorded_by_name'] ?? $payment['processed_by'] ?? ''), ENT_QUOTES, 'UTF-8');
        $description   = htmlspecialchars((string)($context['description'] ?? ''), ENT_QUOTES, 'UTF-8');
        $netAmount     = (float)($payment['payment_amount'] ?? 0);
        $vatAmount     = (float)($payment['vat_amount'] ?? 0);
        $totalAmount   = (float)($payment['total_amount'] ?? 0);
        // Label the VAT line with the rate stored on THIS payment, not the
        // current setting — old receipts must not re-label after a rate change.
        if ((float)($payment['vat_rate'] ?? 0) > 0) {
            $vatRate = (float)$payment['vat_rate'];
        }
        $tipAmount     = (float)($payment['tip_amount'] ?? 0);
        $isRefund      = (string)($payment['payment_type'] ?? '') === 'refund';

        // Logo: use public HTTPS URL only — CID/data-URI embedding causes PNG attachment artefact
        $logoUrl  = function_exists('hotel_email_logo_url') ? hotel_email_logo_url() : '';
        $logoHtml = $logoUrl !== ''
            ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '" style="max-height:64px;width:auto;display:block;margin:0 auto 10px;">'
            : '';

        $voidBanner = $isRefund
            ? '<div style="background:#fde7e9;border:2px solid #c82333;color:#721c24;padding:10px;text-align:center;font-weight:700;letter-spacing:2px;margin:0 0 12px;">REFUND</div>'
            : '';

        // Detail rows
        $details = '';
        $details .= '<tr><td style="padding:4px 0;"><strong>Receipt #</strong></td><td align="right" style="padding:4px 0;">' . $receiptNumber . '</td></tr>';
        $details .= '<tr><td style="padding:4px 0;"><strong>Date</strong></td><td align="right" style="padding:4px 0;">' . $date . '</td></tr>';
        if ($bookingRef !== '' && $bookingRef !== $payRef) {
            $details .= '<tr><td style="padding:4px 0;"><strong>Booking ref</strong></td><td align="right" style="padding:4px 0;">' . $bookingRef . '</td></tr>';
        }
        $details .= '<tr><td style="padding:4px 0;"><strong>Payment ref</strong></td><td align="right" style="padding:4px 0;">' . $payRef . '</td></tr>';
        $details .= '<tr><td style="padding:4px 0;"><strong>Guest</strong></td><td align="right" style="padding:4px 0;">' . $guestName . '</td></tr>';
        if ($guestEmail !== '') {
            $details .= '<tr><td style="padding:4px 0;"><strong>Email</strong></td><td align="right" style="padding:4px 0;">' . $guestEmail . '</td></tr>';
        }
        if ($guestPhone !== '') {
            $details .= '<tr><td style="padding:4px 0;"><strong>Phone</strong></td><td align="right" style="padding:4px 0;">' . $guestPhone . '</td></tr>';
        }
        if ($description !== '') {
            $details .= '<tr><td style="padding:4px 0;"><strong>For</strong></td><td align="right" style="padding:4px 0;">' . $description . '</td></tr>';
        }
        if ($recordedBy !== '') {
            $details .= '<tr><td style="padding:4px 0;"><strong>Recorded by</strong></td><td align="right" style="padding:4px 0;">' . $recordedBy . '</td></tr>';
        }

        // Totals
        $grandTotal  = $totalAmount + $tipAmount;
        $totalsHtml  = '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;">Sub-total (net)</td>';
        $totalsHtml .= '<td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;white-space:nowrap;">' . $currency . ' ' . number_format($netAmount, 2) . '</td></tr>';
        if ($vatAmount > 0) {
            $vatLabel = 'VAT' . ($vatRate > 0 ? ' (' . number_format($vatRate, 1) . '%)' : '');
            $totalsHtml .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;">' . htmlspecialchars($vatLabel, ENT_QUOTES, 'UTF-8') . '</td>';
            $totalsHtml .= '<td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;white-space:nowrap;">' . $currency . ' ' . number_format($vatAmount, 2) . '</td></tr>';
        }
        if ($tipAmount > 0) {
            $totalsHtml .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;color:#059669;font-weight:600;">Tip</td>';
            $totalsHtml .= '<td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;color:#059669;font-weight:600;white-space:nowrap;">+ ' . $currency . ' ' . number_format($tipAmount, 2) . '</td></tr>';
        }
        $totalsHtml .= '<tr style="background:#3f3933;"><td style="padding:8px 10px;font-weight:700;color:#ffffff;border-right:1px solid #5a534c;">' . ($isRefund ? 'REFUNDED' : ($tipAmount > 0 ? 'GRAND TOTAL' : 'TOTAL RECEIVED')) . '</td>';
        $totalsHtml .= '<td align="right" style="padding:8px 10px;font-weight:700;font-size:15px;color:#D5B37C;white-space:nowrap;">' . $currency . ' ' . number_format($grandTotal, 2) . '</td></tr>';
        $totalsHtml .= '<tr><td colspan="2" style="padding:6px 10px;font-size:12px;color:#5a534c;border-top:1px solid #d9cec1;">Paid via: ' . $method . '</td></tr>';
        if ($vatNumber !== '') {
            $totalsHtml .= '<tr><td colspan="2" style="padding:4px 10px;font-size:11px;color:#7C6E5B;">VAT Reg. No.: ' . htmlspecialchars($vatNumber, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        $footerHtml = htmlspecialchars($footer, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt ' . $receiptNumber . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f7f3ee;font-family:Arial,Helvetica,sans-serif;color:#1f1c18;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f7f3ee;padding:22px 10px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #ece3d9;border-radius:12px;overflow:hidden;">'
            . '<tr><td style="padding:18px 24px 16px;border-bottom:1px solid #ede7df;text-align:center;">'
            . $voidBanner
            . $logoHtml
            . '<h1 style="margin:0;color:#8B7355;font-size:22px;font-weight:600;">' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</h1>'
            . ($address ? '<div style="margin-top:6px;font-size:12px;color:#5a534c;">' . htmlspecialchars($address, ENT_QUOTES, 'UTF-8') . '</div>' : '')
            . ($phone ? '<div style="margin-top:2px;font-size:12px;color:#5a534c;">Tel: ' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . ($email ? ' · ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '') . '</div>' : '')
            . '<div style="margin-top:10px;font-size:11px;letter-spacing:0.12em;font-weight:700;color:#8B7355;">PAYMENT RECEIPT</div>'
            . '</td></tr>'
            . '<tr><td style="padding:16px 24px 10px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:12px;color:#3f3933;">'
            . $details
            . '</table>'
            . '</td></tr>'
            . '<tr><td style="padding:8px 24px 0;">'
            . '<table role="presentation" align="right" cellspacing="0" cellpadding="0" style="font-size:13px;color:#3f3933;min-width:280px;border-collapse:collapse;border:1px solid #d9cec1;">'
            . $totalsHtml
            . '</table>'
            . '</td></tr>'
            . '<tr><td style="padding:18px 24px 22px;">'
            . '<div style="border-top:1px dashed #d9cec1;padding-top:10px;text-align:center;font-size:12px;color:#6a645d;line-height:1.5;">' . $footerHtml . '</div>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>'
            . '</body></html>';
    }
}


/* ═══════════════════════════════════════════════════════════════════════
 * Restaurant order receipt (CLI-safe: no admin session needed)
 * Email/print body + PDF attachment built on the shared document theme.
 * ═══════════════════════════════════════════════════════════════════════ */

if (!function_exists('receipt_h')) {
    /** Null-safe htmlspecialchars (strict_types friendly). Extra args are accepted and ignored. */
    function receipt_h($value, $flags = null, $encoding = null): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('receipt_apply_theme')) {
    /**
     * Re-colour a legacy receipt HTML body with the shared brand tokens so the email body
     * and the PDF share one palette. Structure is untouched.
     */
    function receipt_apply_theme(string $html): string
    {
        $c = hotel_brand_tokens()['colors'];
        return strtr($html, [
            'background:#f7f3ee'  => 'background:' . $c['page'],
            'background:#ffffff;border:1px solid #ece3d9;border-radius:12px;overflow:hidden;' => 'background:' . $c['card'] . ';border:1px solid ' . $c['rule'] . ';',
            'background:#3f3933'  => 'background:' . $c['accent'],
            'background:#8B7355'  => 'background:' . $c['accent'],
            'background:#faf7f3'  => 'background:' . $c['panel'],
            'background:#f5f0ea'  => 'background:' . $c['panel'],
            'background:#fde7e9'  => 'background:' . $c['danger_bg'],
            '#D5B37C'             => '#ffffff',
            '#8B7355'             => $c['accent'],
            '#3f3933'             => $c['text'],
            '#1f1c18'             => $c['text'],
            '#374151'             => $c['text'],
            '#5a534c'             => $c['muted_dark'],
            '#6a645d'             => $c['muted_dark'],
            '#7a6f63'             => $c['muted_dark'],
            '#7C6E5B'             => $c['muted_dark'],
            '#9b8f7e'             => $c['muted'],
            '#d9cec1'             => $c['rule'],
            '#e8e0d5'             => $c['rule'],
            '#ede7df'             => $c['rule'],
            '#ece3d9'             => $c['rule'],
            '#e0d8ce'             => $c['rule'],
            '#9A8775'             => $c['rule'],
            '#6d5a44'             => $c['muted_dark'],
            '#c82333'             => $c['danger'],
            '#721c24'             => $c['danger'],
            '#b3261e'             => $c['danger'],
            '#059669'             => $c['success'],
        ]);
    }
}

if (!function_exists('receipt_build_restaurant_email_html_raw')) {
    function receipt_build_restaurant_email_html_raw(array $order, array $items, array $ctx): string
    {
        $cur = $ctx['currency'];
        $site = receipt_h($ctx['site']);
        $addr = receipt_h($ctx['address']);
        $phone = receipt_h($ctx['phone']);
        $email = receipt_h($ctx['email']);
        $footer = receipt_h($ctx['footer']);
        $invNum = receipt_h($order['invoice_number'] ?? '');
        $ref    = receipt_h($order['reference']);
        $date   = $order['paid_at'] ? date('Y-m-d H:i', strtotime($order['paid_at'])) : date('Y-m-d H:i', strtotime($order['created_at']));
        $cust   = receipt_h($order['customer_name'] ?: 'Walk-in customer');
        $custEm = receipt_h($order['customer_email'] ?: '');
        $custPh = receipt_h($order['customer_phone'] ?: '');
        $isRoomService = ($order['order_type'] ?? '') === 'room_service';
        $orderType = receipt_h(ucfirst(str_replace('_', ' ', $order['order_type'])));
        $rawTableNo = (string)($order['table_number'] ?: '');
        $roomNumber = trim((string)($order['room_number'] ?? ''));
        if ($isRoomService && $roomNumber === '' && $rawTableNo !== '') {
            $roomNumber = trim(preg_replace('/^Room\s+/i', '', $rawTableNo));
        }
        $tableNo = receipt_h($rawTableNo);
        $roomNo = receipt_h($roomNumber);
        $cashier    = receipt_h($ctx['cashier'] ?: '');
        $splitLegs  = $ctx['split_legs'] ?? [];
        $notes  = receipt_h($order['notes'] ?: '');
        $method = receipt_h(ucwords(str_replace('_', ' ', $order['payment_method'] ?: '—')));
        $statusLabel = receipt_h(ucfirst($order['status']));
        $isVoid = in_array($order['status'], ['voided', 'cancelled'], true);
    
        $rows = '';
        foreach ($items as $it) {
            $noteRow = !empty($it['notes']) ? '<div style="font-size:11px;color:#8B7355;font-style:italic;">→ ' . receipt_h($it['notes']) . '</div>' : '';
            $rows .= '<tr>'
                . '<td style="padding:6px 8px;border:1px solid #e0d8ce;">' . receipt_h($it['item_name']) . $noteRow . '</td>'
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
            $extras .= '<div>Mobile: ' . receipt_h($order['mobile_wallet_provider']) . ' · Ref ' . receipt_h($order['mobile_wallet_reference']) . '</div>';
        } elseif ($order['payment_method'] === 'card_manual' && $order['card_last4']) {
            $extras .= '<div>Card: ···· ' . receipt_h($order['card_last4']) . ' · Auth ' . receipt_h($order['card_auth_code'] ?: '') . '</div>';
        }
    
        $voidBanner = '';
        if ($isVoid) {
            $voidBanner = '<div style="background:#fde7e9;border:2px solid #c82333;color:#721c24;padding:10px;text-align:center;font-weight:700;letter-spacing:2px;margin:0 0 12px;">VOID / NOT VALID</div>';
        }
    
        // Logo via public HTTPS URL so hotel_embed_logo_cid() can reference it (prevents orphaned PNG attachment)
        $logoUrl  = function_exists('hotel_email_logo_url') ? hotel_email_logo_url() : '';
        $logoHtml = $logoUrl !== ''
            ? '<img src="' . receipt_h($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $site . '" style="max-height:60px;width:auto;display:block;margin:0 auto 10px;">'
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
            . '<tr><td style="padding:4px 0;"><strong>Date</strong></td><td align="right" style="padding:4px 0;">' . receipt_h($date) . '</td></tr>'
            . '<tr><td style="padding:4px 0;"><strong>Order type</strong></td><td align="right" style="padding:4px 0;">' . $orderType . (!$isRoomService && $tableNo ? ' · Table ' . $tableNo : '') . '</td></tr>'
            . ($isRoomService ? '<tr><td style="padding:4px 0;"><strong>Room</strong></td><td align="right" style="padding:4px 0;">' . ($roomNo ?: 'Not linked') . '</td></tr>' : '')
            . ($isRoomService && !empty($order['booking_reference']) ? '<tr><td style="padding:4px 0;"><strong>Booking</strong></td><td align="right" style="padding:4px 0;">' . receipt_h((string)$order['booking_reference']) . '</td></tr>' : '')
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
            . ($discount > 0 ? '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;">Discount' . ($order['discount_reason'] ? ' (' . receipt_h($order['discount_reason']) . ')' : '') . '</td><td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;color:#b3261e;white-space:nowrap;">−' . $cur . ' ' . number_format($discount, 2) . '</td></tr>' : '')
            . ($service > 0 ? '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;">Service charge</td><td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;white-space:nowrap;">' . $cur . ' ' . number_format($service, 2) . '</td></tr>' : '')
            . ($tax > 0 ? '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;">Tax</td><td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;white-space:nowrap;">' . $cur . ' ' . number_format($tax, 2) . '</td></tr>' : '')
            . ($tip > 0 ? '<tr><td style="padding:6px 10px;border-bottom:1px solid #e8e0d5;border-right:1px solid #d9cec1;color:#059669;font-weight:600;">Tip</td><td align="right" style="padding:6px 10px;border-bottom:1px solid #e8e0d5;color:#059669;font-weight:600;white-space:nowrap;">+ ' . $cur . ' ' . number_format($tip, 2) . '</td></tr>' : '')
            . '<tr style="background:#3f3933;"><td style="padding:8px 10px;font-weight:700;color:#ffffff;border-right:1px solid #5a534c;">' . ($tip > 0 ? 'GRAND TOTAL' : 'TOTAL') . '</td><td align="right" style="padding:8px 10px;font-weight:700;font-size:15px;color:#D5B37C;white-space:nowrap;">' . $cur . ' ' . number_format($grandTotal, 2) . '</td></tr>'
            . (function_exists('vat_document_note') && vat_document_note() !== '' ? '<tr><td colspan="2" style="padding:6px 10px;font-size:11px;color:#7a6f63;text-align:center;">' . receipt_h(vat_document_note(), ENT_QUOTES, 'UTF-8') . '</td></tr>' : '')
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
                    $legMethod = receipt_h($methodNames[$leg['payment_method']] ?? ucwords(str_replace('_', ' ', $leg['payment_method'])));
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
}

if (!function_exists('receipt_build_restaurant_email_html')) {
    /** Restaurant receipt HTML used for the email body, the admin preview and printing. */
    function receipt_build_restaurant_email_html(array $order, array $items, array $ctx): string
    {
        return receipt_apply_theme(receipt_build_restaurant_email_html_raw($order, $items, $ctx));
    }
}

if (!function_exists('receipt_restaurant_context')) {
    /** Display context (hotel details, footer line, cashier) for a restaurant receipt. */
    function receipt_restaurant_context(PDO $pdo, array $order): array
    {
        $tok = hotel_brand_tokens();
        $cashier = (string)($order['cashier_name'] ?? '');
        if ($cashier === '' && !empty($order['created_by'])) {
            $st = $pdo->prepare('SELECT full_name FROM admin_users WHERE id = ?');
            $st->execute([(int)$order['created_by']]);
            $cashier = (string)($st->fetchColumn() ?: '');
        }
        $splitLegs = [];
        if ((int)($order['split_count'] ?? 1) > 1) {
            try {
                $st = $pdo->prepare('SELECT * FROM stock_order_splits WHERE order_id = ? ORDER BY split_number');
                $st->execute([(int)$order['id']]);
                $splitLegs = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* table may not exist pre-migration */ }
        }
        return [
            'currency'   => (string)getSetting('currency_symbol', 'MWK'),
            'site'       => $tok['hotel']['name'],
            'address'    => (string)(getSetting('hotel_address') ?: ''),
            'phone'      => (string)(getSetting('hotel_phone') ?: getSetting('phone_main', '')),
            'email'      => $tok['hotel']['email'],
            'footer'     => (string)(getSetting('restaurant_receipt_footer') ?: 'Thank you for dining with us!'),
            'cashier'    => $cashier,
            'split_legs' => $splitLegs,
        ];
    }
}

if (!function_exists('receipt_load_restaurant_order')) {
    /** @return array{order:array,items:array,ctx:array}|null  Read-only. */
    function receipt_load_restaurant_order(PDO $pdo, int $orderId): ?array
    {
        $st = $pdo->prepare('SELECT so.*, au.full_name AS cashier_name, b.booking_reference
            FROM stock_orders so
            LEFT JOIN admin_users au ON au.id = so.created_by
            LEFT JOIN bookings b ON b.id = so.booking_id
            WHERE so.id = ?');
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM stock_order_items WHERE order_id = ? ORDER BY id');
        $st->execute([$orderId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        return ['order' => $order, 'items' => $items, 'ctx' => receipt_restaurant_context($pdo, $order)];
    }
}

if (!function_exists('receipt_qty_text')) {
    function receipt_qty_text($qty): string
    {
        $t = rtrim(rtrim(number_format((float)$qty, 2), '0'), '.');
        return $t === '' ? '0' : $t;
    }
}

if (!function_exists('receipt_render_restaurant_pdf_html')) {
    /** Themed, TCPDF-safe HTML for a restaurant receipt PDF. */
    function receipt_render_restaurant_pdf_html(array $order, array $items, array $ctx): string
    {
        $t = hotel_brand_tokens()['colors'];
        $cur = (string)$ctx['currency'];
        $money = static function ($v) use ($cur): string {
            return rh_pdf_money((float)$v, $cur);
        };

        $isRoomService = ($order['order_type'] ?? '') === 'room_service';
        $isVoid = in_array((string)($order['status'] ?? ''), ['voided', 'cancelled'], true);
        $rawTable = (string)($order['table_number'] ?? '');
        $roomNumber = trim((string)($order['room_number'] ?? ''));
        if ($isRoomService && $roomNumber === '' && $rawTable !== '') {
            $roomNumber = trim((string)preg_replace('/^Room\s+/i', '', $rawTable));
        }
        $dateRaw = !empty($order['paid_at']) ? $order['paid_at'] : ($order['created_at'] ?? 'now');
        $orderType = ucfirst(str_replace('_', ' ', (string)($order['order_type'] ?? '')));
        if (!$isRoomService && $rawTable !== '') {
            $orderType .= ' - Table ' . $rawTable;
        }
        $methodRaw = (string)($order['payment_method'] ?? '');
        $method = $methodRaw !== '' ? ucwords(str_replace('_', ' ', $methodRaw)) : '';
        $customer = (string)($order['customer_name'] ?? '');

        $meta = [
            ['Receipt No.', (string)($order['reference'] ?? '')],
            ['Invoice No.', (string)($order['invoice_number'] ?? '')],
            ['Date', date('d M Y, H:i', strtotime((string)$dateRaw))],
            ['Order type', $orderType],
            ['Room', $isRoomService ? ($roomNumber !== '' ? $roomNumber : 'Not linked') : ''],
            ['Booking', $isRoomService ? (string)($order['booking_reference'] ?? '') : ''],
            ['Customer', $customer !== '' ? $customer : 'Walk-in customer'],
            ['Email', (string)($order['customer_email'] ?? '')],
            ['Phone', (string)($order['customer_phone'] ?? '')],
            ['Cashier', (string)($ctx['cashier'] ?? '')],
            ['Status', ucfirst((string)($order['status'] ?? ''))],
        ];

        $cols = [
            ['label' => 'Item', 'width' => 52, 'align' => 'left'],
            ['label' => 'Qty', 'width' => 10, 'align' => 'right'],
            ['label' => 'Unit', 'width' => 18, 'align' => 'right'],
            ['label' => 'Total', 'width' => 20, 'align' => 'right'],
        ];
        $rows = [];
        foreach ($items as $it) {
            $name = rh_pdf_e($it['item_name'] ?? '');
            if (!empty($it['notes'])) {
                $name .= '<br/><span style="color:' . $t['muted_dark'] . ';font-size:7.5pt;">Note: ' . rh_pdf_e($it['notes']) . '</span>';
            }
            $rows[] = [
                $name,
                rh_pdf_e(receipt_qty_text($it['quantity'] ?? 0)),
                rh_pdf_e($money($it['unit_price'] ?? 0)),
                rh_pdf_e($money($it['line_total'] ?? 0)),
            ];
        }

        $subtotal = (float)($order['subtotal'] ?? 0);
        if ($subtotal == 0.0) {
            $subtotal = array_sum(array_map(static function ($i) {
                return (float)$i['line_total'];
            }, $items));
        }
        $discount = (float)($order['discount_amount'] ?? 0);
        $service  = (float)($order['service_charge'] ?? 0);
        $tax      = (float)($order['tax_amount'] ?? 0);
        $total    = (float)($order['total_amount'] ?? 0);
        $tip      = (float)($order['tip_amount'] ?? 0);
        $splitCount = max(1, (int)($order['split_count'] ?? 1));
        $grand    = $total + $tip;

        $totals = [['label' => 'Subtotal', 'value' => $money($subtotal)]];
        if ($discount > 0) {
            $reason = trim((string)($order['discount_reason'] ?? ''));
            $totals[] = ['label' => 'Discount' . ($reason !== '' ? ' (' . $reason . ')' : ''), 'value' => '-' . $money($discount), 'type' => 'discount'];
        }
        if ($service > 0) {
            $totals[] = ['label' => 'Service charge', 'value' => $money($service)];
        }
        if ($tax > 0) {
            $totals[] = ['label' => 'Tax', 'value' => $money($tax)];
        }
        if ($tip > 0) {
            $totals[] = ['label' => 'Tip', 'value' => '+' . $money($tip), 'type' => 'success'];
        }
        $totals[] = ['label' => $tip > 0 ? 'Grand total' : 'Total', 'value' => $money($grand), 'type' => 'total'];

        // F&B prices are gross: VAT is contained in the total, never added on top.
        if (function_exists('vat_mode') && vat_mode() !== 'off' && $total > 0) {
            $rate = (float)getSetting('vat_rate', 0);
            $rateLabel = rtrim(rtrim(number_format($rate, 2), '0'), '.');
            // Only a stored tax figure is ever printed (as the "Tax" row above); never
            // derive a VAT amount from today's rate, which may differ from the sale's.
            if ($tax <= 0) {
                $totals[] = ['label' => 'All prices include VAT at ' . $rateLabel . '%', 'value' => '', 'type' => 'note'];
            }
        }
        if ($splitCount > 1) {
            $totals[] = ['label' => 'Split ' . $splitCount . ' ways: ' . $money($total / $splitCount) . ' each', 'value' => '', 'type' => 'note'];
        }
        if ($method !== '') {
            $totals[] = ['label' => 'Paid via', 'value' => $method, 'type' => 'strong'];
        }
        if (isset($order['tendered_amount']) && $order['tendered_amount'] !== null) {
            $totals[] = ['label' => 'Tendered', 'value' => $money($order['tendered_amount'])];
        }
        if (isset($order['change_due']) && $order['change_due'] !== null && (float)$order['change_due'] > 0) {
            $totals[] = ['label' => 'Change', 'value' => $money($order['change_due'])];
        }
        if ($methodRaw === 'mobile_money' && !empty($order['mobile_wallet_reference'])) {
            $totals[] = ['label' => 'Mobile: ' . trim((string)($order['mobile_wallet_provider'] ?? '')) . ' ref ' . $order['mobile_wallet_reference'], 'value' => '', 'type' => 'note'];
        } elseif ($methodRaw === 'card_manual' && !empty($order['card_last4'])) {
            $totals[] = ['label' => 'Card **** ' . $order['card_last4'] . (!empty($order['card_auth_code']) ? ' auth ' . $order['card_auth_code'] : ''), 'value' => '', 'type' => 'note'];
        }

        $body = rh_pdf_items_table($cols, $rows) . rh_pdf_spacer(3) . rh_pdf_totals_table($totals);

        if (!empty($ctx['split_legs'])) {
            $names = ['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'card_manual' => 'Card (manual)', 'card_pos' => 'Card POS', 'other' => 'Other'];
            $legRows = [];
            foreach ($ctx['split_legs'] as $leg) {
                $m = $names[$leg['payment_method'] ?? ''] ?? ucwords(str_replace('_', ' ', (string)($leg['payment_method'] ?? '')));
                $amt = (float)($leg['split_amount'] ?? 0) + (float)($leg['tip_amount'] ?? 0);
                $extra = '';
                if ((float)($leg['tip_amount'] ?? 0) > 0) {
                    $extra .= ' (incl. tip ' . $money($leg['tip_amount']) . ')';
                }
                if (isset($leg['change_due']) && (float)$leg['change_due'] > 0) {
                    $extra .= ' change ' . $money($leg['change_due']);
                }
                $legRows[] = ['#' . (int)($leg['split_number'] ?? 0), rh_pdf_e($m), rh_pdf_e($money($amt) . $extra)];
            }
            $body .= rh_pdf_spacer(4) . rh_pdf_section_title('Split payment breakdown') . rh_pdf_spacer(1)
                . rh_pdf_items_table([
                    ['label' => 'Leg', 'width' => 14, 'align' => 'left'],
                    ['label' => 'Method', 'width' => 36, 'align' => 'left'],
                    ['label' => 'Amount', 'width' => 50, 'align' => 'right'],
                ], $legRows);
        }
        if (!empty($order['notes'])) {
            $body .= rh_pdf_spacer(4) . rh_pdf_note('Notes: ' . $order['notes']);
        }
        $terms = hotel_brand_tokens()['terms_text'];
        if ($terms !== '') {
            $body .= rh_pdf_spacer(3) . rh_pdf_note($terms);
        }
        $body .= rh_pdf_spacer(6) . '<table width="100%" cellpadding="4" cellspacing="0" border="0"><tr><td align="center" style="font-family:times;font-size:11pt;color:' . $t['muted_dark'] . ';">'
            . rh_pdf_e($ctx['footer'] ?? '') . '</td></tr></table>';

        return rh_pdf_document_shell('Restaurant Receipt', $meta, $body, [
            'banner' => $isVoid ? ['text' => 'Void / not valid', 'tone' => 'danger'] : null,
        ]);
    }
}

if (!function_exists('receipt_build_restaurant_pdf_html')) {
    /** Load an order read-only and return its receipt PDF HTML. */
    function receipt_build_restaurant_pdf_html(PDO $pdo, int $orderId): string
    {
        $data = receipt_load_restaurant_order($pdo, $orderId);
        if (!$data) {
            throw new RuntimeException('Order not found.');
        }
        return receipt_render_restaurant_pdf_html($data['order'], $data['items'], $data['ctx']);
    }
}

if (!function_exists('receipt_restaurant_pdf_bytes')) {
    function receipt_restaurant_pdf_bytes(array $order, array $items, array $ctx, array $opts = []): string
    {
        $ref = (string)($order['invoice_number'] ?? '');
        if ($ref === '') {
            $ref = (string)($order['reference'] ?? '');
        }
        return bookingRenderPdfFromHtml(receipt_render_restaurant_pdf_html($order, $items, $ctx), 'Receipt ' . $ref, $opts);
    }
}

if (!function_exists('receipt_render_payment_pdf_html')) {
    /** Themed, TCPDF-safe HTML for a payment receipt PDF (honours vat_mode()). */
    function receipt_render_payment_pdf_html(array $payment, array $context): string
    {
        $t = hotel_brand_tokens()['colors'];
        $cur = (string)getSetting('currency_symbol', 'MWK');
        $money = static function ($v) use ($cur): string {
            return rh_pdf_money((float)$v, $cur);
        };
        $isRefund = (string)($payment['payment_type'] ?? '') === 'refund';

        $net = (float)($payment['payment_amount'] ?? 0);
        $vat = (float)($payment['vat_amount'] ?? 0);
        $gross = (float)($payment['total_amount'] ?? 0);
        if ($gross <= 0) {
            $gross = $net + $vat;
        }
        $tip = (float)($payment['tip_amount'] ?? 0);
        $rate = (float)($payment['vat_rate'] ?? 0);
        $mode = function_exists('vat_mode') ? vat_mode() : 'off';
        $showVatAmount = function_exists('vat_shows_amount') ? vat_shows_amount() : ($vat > 0);

        $bookingRef = (string)($payment['booking_reference'] ?? '');
        $payRef = (string)($payment['payment_reference'] ?? '');
        $method = ucwords(str_replace('_', ' ', (string)($payment['payment_method'] ?? '')));
        $date = !empty($payment['payment_date']) ? date('d M Y', strtotime((string)$payment['payment_date'])) : date('d M Y');
        $meta = [
            ['Receipt No.', (string)($payment['receipt_number'] ?? '')],
            ['Date', $date],
            ['Booking ref', $bookingRef !== $payRef ? $bookingRef : ''],
            ['Payment ref', $payRef],
            ['Received from', (string)($context['guest_name'] ?? 'Guest')],
            ['Email', (string)($context['guest_email'] ?? '')],
            ['Phone', (string)($context['guest_phone'] ?? '')],
            ['Recorded by', (string)($payment['recorded_by_name'] ?? $payment['processed_by'] ?? '')],
            ['Status', ucwords(str_replace('_', ' ', (string)($payment['payment_status'] ?? '')))],
        ];

        // Exclusive: the line is net and VAT is added below. Inclusive/off: the line is the amount paid.
        $lineAmount = ($mode === 'exclusive' && $showVatAmount) ? $net : $gross;
        $desc = rh_pdf_e((string)($context['description'] ?? 'Payment'));
        $typeLabel = ucwords(str_replace('_', ' ', (string)($payment['payment_type'] ?? '')));
        if ($typeLabel !== '') {
            $desc .= '<br/><span style="color:' . $t['muted_dark'] . ';font-size:7.5pt;">' . rh_pdf_e($typeLabel) . '</span>';
        }
        $items = rh_pdf_items_table([
            ['label' => 'Item', 'width' => 52, 'align' => 'left'],
            ['label' => 'Qty', 'width' => 10, 'align' => 'right'],
            ['label' => 'Unit', 'width' => 18, 'align' => 'right'],
            ['label' => 'Total', 'width' => 20, 'align' => 'right'],
        ], [[$desc, '1', rh_pdf_e($money($lineAmount)), rh_pdf_e($money($lineAmount))]]);

        $totals = [];
        if ($mode === 'exclusive' && $showVatAmount) {
            $totals[] = ['label' => 'Subtotal (net)', 'value' => $money($net)];
        }
        foreach (rh_pdf_vat_rows($vat, $rate, $cur) as $row) {
            $totals[] = $row;
        }
        if ($tip > 0) {
            $totals[] = ['label' => 'Tip', 'value' => '+' . $money($tip), 'type' => 'success'];
        }
        $totals[] = [
            'label' => $isRefund ? 'Refunded' : ($tip > 0 ? 'Grand total' : 'Total received'),
            'value' => $money($gross + $tip),
            'type' => 'total',
        ];
        if ($method !== '') {
            $totals[] = ['label' => 'Paid via', 'value' => $method, 'type' => 'strong'];
        }

        $body = $items . rh_pdf_spacer(3) . rh_pdf_totals_table($totals);
        $terms = hotel_brand_tokens()['terms_text'];
        if ($terms !== '') {
            $body .= rh_pdf_spacer(4) . rh_pdf_note($terms);
        }
        $footer = trim((string)getSetting('receipt_footer', ''));
        if ($footer === '') {
            $footer = 'Thank you for your payment.';
        }
        $body .= rh_pdf_spacer(6) . '<table width="100%" cellpadding="4" cellspacing="0" border="0"><tr><td align="center" style="font-family:times;font-size:11pt;color:' . $t['muted_dark'] . ';">'
            . rh_pdf_e($footer) . '</td></tr></table>';

        return rh_pdf_document_shell($isRefund ? 'Refund Receipt' : 'Payment Receipt', $meta, $body, [
            'banner' => $isRefund ? ['text' => 'Refund', 'tone' => 'danger'] : null,
        ]);
    }
}

if (!function_exists('receipt_payment_pdf_bytes')) {
    function receipt_payment_pdf_bytes(array $payment, array $context, array $opts = []): string
    {
        return bookingRenderPdfFromHtml(
            receipt_render_payment_pdf_html($payment, $context),
            'Receipt ' . (string)($payment['receipt_number'] ?? ''),
            $opts
        );
    }
}

if (!function_exists('receipt_build_pos_style_html')) {
    /** Payment receipt email body (POS-style layout, shared brand palette). */
    function receipt_build_pos_style_html(array $payment, array $context, PDO $pdo): string
    {
        return receipt_apply_theme(receipt_build_pos_style_html_raw($payment, $context, $pdo));
    }
}

if (!function_exists('receipt_generate_pdf')) {
    function receipt_generate_pdf(PDO $pdo, int $paymentId, ?array $user = null): array
    {
        receipt_ensure_schema($pdo);
        $payment = receipt_get_payment($pdo, $paymentId);
        if (!$payment) {
            throw new RuntimeException('Payment not found.');
        }
        if (!in_array((string)$payment['payment_status'], ['completed', 'paid', 'refunded', 'partially_refunded'], true)) {
            throw new RuntimeException('Only completed, paid, or refunded payments can have receipts.');
        }

        $receiptNumber = trim((string)($payment['receipt_number'] ?? ''));
        if ($receiptNumber === '' && (string)($payment['payment_type'] ?? '') !== 'refund') {
            // Allocate under a row lock and write only if still empty: two concurrent
            // "send receipt" clicks must end with ONE number on the payment, not two.
            $ownReceiptTx = !$pdo->inTransaction();
            try {
                if ($ownReceiptTx) {
                    $pdo->beginTransaction();
                }
                $rcLock = $pdo->prepare('SELECT receipt_number FROM payments WHERE id = ? FOR UPDATE');
                $rcLock->execute([$paymentId]);
                $lockedNumber = trim((string)($rcLock->fetchColumn() ?: ''));
                if ($lockedNumber === '') {
                    $candidate = finance_next_receipt_number($pdo, (string)($payment['payment_date'] ?? date('Y-m-d')));
                    $rcUpd = $pdo->prepare("UPDATE payments SET receipt_number = ? WHERE id = ? AND (receipt_number IS NULL OR receipt_number = '')");
                    $rcUpd->execute([$candidate, $paymentId]);
                    if ($rcUpd->rowCount() > 0) {
                        $lockedNumber = $candidate;
                    } else {
                        $rcLock->execute([$paymentId]);
                        $lockedNumber = trim((string)($rcLock->fetchColumn() ?: ''));
                    }
                }
                if ($ownReceiptTx) {
                    $pdo->commit();
                }
            } catch (Throwable $rcEx) {
                if ($ownReceiptTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $rcEx;
            }
            $receiptNumber = $lockedNumber;
            $payment['receipt_number'] = $receiptNumber;
        } elseif ($receiptNumber === '') {
            $receiptNumber = 'RFD-' . (string)($payment['payment_reference'] ?? $paymentId);
            $payment['receipt_number'] = $receiptNumber;
        }

        $context = receipt_hydrate_context($pdo, $payment);
        $dir = dirname(__DIR__) . '/invoices/receipts';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $safeNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', $receiptNumber) ?: ('receipt-' . $paymentId);
        $filename = $safeNumber . '.pdf';
        $path = $dir . '/' . $filename;
        $relativePath = 'invoices/receipts/' . $filename;

        // Themed PDF on the shared document kit (see config/document-theme.php)
        $pdfBytes = receipt_payment_pdf_bytes($payment, $context);

        if ($pdfBytes !== '') {
            file_put_contents($path, $pdfBytes);
        }

        $pdo->prepare('UPDATE payments SET receipt_path = ?, receipt_generated = 1, receipt_generated_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute([$relativePath, $paymentId]);
        receipt_log_event($pdo, $paymentId, $receiptNumber, 'generated', null, 'pdf', 'Receipt PDF generated', $user);
        rh_log_event('receipts', 'info', 'Receipt generated', ['payment_id' => $paymentId, 'receipt_number' => $receiptNumber]);

        return ['success' => true, 'receipt_number' => $receiptNumber, 'path' => $path, 'relative_path' => $relativePath, 'bytes' => $pdfBytes];
    }
}

if (!function_exists('receipt_send_email')) {
    function receipt_send_email(PDO $pdo, int $paymentId, ?string $recipient = null, ?array $user = null): array
    {
        receipt_ensure_schema($pdo);
        $payment = receipt_get_payment($pdo, $paymentId);
        if (!$payment) {
            throw new RuntimeException('Payment not found.');
        }
        $pdf = receipt_generate_pdf($pdo, $paymentId, $user);
        $payment = receipt_get_payment($pdo, $paymentId) ?: $payment;
        $context = receipt_hydrate_context($pdo, $payment);
        $to = trim((string)($recipient ?: $context['guest_email']));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid recipient email is required.');
        }

        $placeholders = receipt_placeholders($pdo, $payment, $context);
        $receiptTemplate = function_exists('getBookingEmailTemplateConfig')
            ? getBookingEmailTemplateConfig('payment_receipt', [])
            : [];
        if (!empty($receiptTemplate['subject']) && !empty($receiptTemplate['html_body']) && (int)($receiptTemplate['is_active'] ?? 1) === 1) {
            $subject = str_replace(array_keys($placeholders), array_values($placeholders), (string)$receiptTemplate['subject']);
            $body = str_replace(array_keys($placeholders), array_values($placeholders), (string)$receiptTemplate['html_body']);
            $textBody = !empty($receiptTemplate['text_body'])
                ? str_replace(array_keys($placeholders), array_values($placeholders), (string)$receiptTemplate['text_body'])
                : '';
        } else {
            $subject = str_replace(array_keys($placeholders), array_values($placeholders), getSetting('receipt_email_subject', 'Receipt {{receipt_number}}'));
            $body = str_replace(array_keys($placeholders), array_values($placeholders), getSetting('receipt_email_template', 'Your receipt is attached.'));
            $textBody = '';
        }

        if (getEmailSetting('smtp_host', '') === '') {
            throw new RuntimeException('SMTP host is not configured.');
        }

        // Shared sender (verified TLS, retries, dev-mode preview) instead of a private PHPMailer.
        $ccList = array_filter(array_map('trim', explode(',', (string)getEmailSetting('invoice_recipients', ''))));
        // Payment receipt email body: POS-style layout in the shared brand palette
        $emailBody = receipt_build_pos_style_html($payment, $context, $pdo);
        $attachments = [];
        if ((string)($pdf['bytes'] ?? '') !== '') {
            $attachments[] = ['content' => $pdf['bytes'], 'name' => $pdf['receipt_number'] . '.pdf', 'mime' => 'application/pdf'];
        } elseif (!empty($pdf['path']) && is_file($pdf['path'])) {
            $attachments[] = ['path' => $pdf['path'], 'name' => $pdf['receipt_number'] . '.pdf', 'mime' => 'application/pdf'];
        }
        $sendResult = sendEmailWithAttachments(
            $to,
            (string)$context['guest_name'],
            html_entity_decode($subject, ENT_QUOTES, 'UTF-8'),
            $emailBody,
            $attachments,
            $textBody !== '' ? html_entity_decode($textBody, ENT_QUOTES, 'UTF-8') : '',
            ['cc' => array_values($ccList)]
        );
        if (empty($sendResult['success'])) {
            throw new RuntimeException((string)($sendResult['message'] ?? 'Receipt email failed.'));
        }
        if (!empty($sendResult['preview'])) {
            return ['success' => true, 'message' => 'Email preview generated (development mode). No live email sent.'];
        }

        $pdo->prepare('UPDATE payments SET receipt_emailed_at = NOW(), receipt_email_count = receipt_email_count + 1, updated_at = NOW() WHERE id = ?')
            ->execute([$paymentId]);
        receipt_log_event($pdo, $paymentId, (string)$pdf['receipt_number'], 'emailed', $to, 'email', 'Receipt emailed', $user);
        rh_log_event('receipts', 'info', 'Receipt emailed', ['payment_id' => $paymentId, 'receipt_number' => $pdf['receipt_number'], 'recipient' => $to]);

        return ['success' => true, 'message' => 'Receipt emailed to ' . $to];
    }
}

/**
 * Automatically send a receipt email for a payment, safely.
 * - Only sends if payment is completed/paid and no receipt has been emailed yet.
 * - Never throws; always returns a result array so callers can log the outcome.
 */
if (!function_exists('receipt_auto_send')) {
    function receipt_auto_send(PDO $pdo, int $paymentId, ?array $user = null): array
    {
        try {
            receipt_ensure_schema($pdo);
            $payment = receipt_get_payment($pdo, $paymentId);
            if (!$payment) {
                return ['success' => false, 'message' => 'Payment not found'];
            }
            if (!in_array((string)($payment['payment_status'] ?? ''), ['completed', 'paid'], true)) {
                return ['success' => false, 'message' => 'Payment not in completed/paid status'];
            }
            if (!empty($payment['receipt_emailed_at'])) {
                return ['success' => false, 'message' => 'Receipt already emailed at ' . $payment['receipt_emailed_at']];
            }
            return receipt_send_email($pdo, $paymentId, null, $user);
        } catch (Throwable $e) {
            error_log('receipt_auto_send failed for payment ' . $paymentId . ': ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('receipt_whatsapp_message')) {
    function receipt_whatsapp_message(PDO $pdo, int $paymentId): array
    {
        receipt_ensure_schema($pdo);
        $payment = receipt_get_payment($pdo, $paymentId);
        if (!$payment) {
            throw new RuntimeException('Payment not found.');
        }
        receipt_generate_pdf($pdo, $paymentId, null);
        $payment = receipt_get_payment($pdo, $paymentId) ?: $payment;
        $context = receipt_hydrate_context($pdo, $payment);
        $placeholders = receipt_placeholders($pdo, $payment, $context);
        $template = getSetting('receipt_whatsapp_template', 'Receipt {{receipt_number}} for {{total_amount}} is ready.');
        $message = html_entity_decode(str_replace(array_keys($placeholders), array_values($placeholders), $template), ENT_QUOTES, 'UTF-8');
        $phone = preg_replace('/[^0-9]+/', '', (string)$context['guest_phone']);
        $url = $phone !== '' ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($message) : 'https://wa.me/?text=' . rawurlencode($message);
        return ['success' => true, 'message' => $message, 'url' => $url, 'phone' => $phone];
    }
}
