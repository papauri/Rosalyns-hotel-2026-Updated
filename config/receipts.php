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

if (!function_exists('receipt_build_pos_style_html')) {
    /**
     * Build a clean POS-receipt-style HTML document for a payment record.
     * Visual design mirrors buildReceiptHtml() in stock-receipt.php.
     * Used for both the PDF attachment and injecting into the email body.
     */
    function receipt_build_pos_style_html(array $payment, array $context, PDO $pdo): string
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

if (!function_exists('receipt_generate_pdf')) {
    function receipt_generate_pdf(PDO $pdo, int $paymentId, ?array $user = null): array
    {
        receipt_ensure_schema($pdo);
        $payment = receipt_get_payment($pdo, $paymentId);
        if (!$payment) {
            throw new RuntimeException('Payment not found.');
        }
        if (!in_array((string)$payment['payment_status'], ['completed', 'paid', 'refunded'], true)) {
            throw new RuntimeException('Only completed, paid, or refunded payments can have receipts.');
        }

        $receiptNumber = trim((string)($payment['receipt_number'] ?? ''));
        if ($receiptNumber === '' && (string)($payment['payment_type'] ?? '') !== 'refund') {
            $receiptNumber = finance_next_receipt_number($pdo, (string)($payment['payment_date'] ?? date('Y-m-d')));
            $pdo->prepare('UPDATE payments SET receipt_number = ? WHERE id = ?')->execute([$receiptNumber, $paymentId]);
            $payment['receipt_number'] = $receiptNumber;
        } elseif ($receiptNumber === '') {
            $receiptNumber = 'RFD-' . (string)($payment['payment_reference'] ?? $paymentId);
            $payment['receipt_number'] = $receiptNumber;
        }

        $context = receipt_hydrate_context($pdo, $payment);
        $currency = getSetting('currency_symbol', 'MWK');
        $siteName = getSetting('site_name', 'Hotel');
        $address = getSetting('hotel_address', getSetting('address', ''));
        $phone = getSetting('hotel_phone', getSetting('phone_main', ''));

        $dir = dirname(__DIR__) . '/invoices/receipts';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $safeNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', $receiptNumber) ?: ('receipt-' . $paymentId);
        $filename = $safeNumber . '.pdf';
        $path = $dir . '/' . $filename;
        $relativePath = 'invoices/receipts/' . $filename;

        // Build POS-style receipt HTML (same visual design as stock-receipt.php)
        $receiptHtml = receipt_build_pos_style_html($payment, $context, $pdo);
        $pdfBytes    = '';

        if (function_exists('bookingRenderPdfFromHtml')) {
            $pdfBytes = bookingRenderPdfFromHtml($receiptHtml, 'Receipt ' . $receiptNumber);
        } else {
            $tcpdfVendorPath = dirname(__DIR__) . '/vendor/tecnickcom/tcpdf/tcpdf.php';
            $tcpdfLegacyPath = dirname(__DIR__) . '/TCPDF/tcpdf.php';
            if (!class_exists('TCPDF')) {
                if (is_file($tcpdfVendorPath)) {
                    require_once $tcpdfVendorPath;
                } elseif (is_file($tcpdfLegacyPath)) {
                    require_once $tcpdfLegacyPath;
                }
            }
            if (!class_exists('TCPDF')) {
                throw new RuntimeException(
                    'Receipt PDF generation is unavailable because TCPDF was not found. '
                        . 'Install TCPDF with "composer require tecnickcom/tcpdf" or place tcpdf.php in /TCPDF.'
                );
            }

            if (!class_exists('JapandiReceiptTCPDF')) {
                class JapandiReceiptTCPDF extends TCPDF {
                    public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false): void
                    {
                        parent::AddPage($orientation, $format, $keepmargins, $tocpage);
                        $this->SetFillColor(247, 243, 238);
                        $this->Rect(0, 0, $this->getPageWidth(), $this->getPageHeight(), 'F');
                    }
                }
            }
            $siteName = getSetting('site_name', 'Hotel');
            $tcpdfInst = new JapandiReceiptTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $tcpdfInst->SetCreator($siteName);
            $tcpdfInst->SetAuthor($siteName);
            $tcpdfInst->SetTitle('Receipt ' . $receiptNumber);
            $tcpdfInst->SetMargins(14, 14, 14);
            $tcpdfInst->SetAutoPageBreak(true, 16);
            $tcpdfInst->AddPage();
            $tcpdfHtml = str_replace(
                ['background:#f7f3ee', 'background:#f7f3ee;', 'background:#3f3933', 'background:#3f3933;'],
                ['background:#ffffff', 'background:#ffffff;', 'background:#333333', 'background:#333333;'],
                $receiptHtml
            );
            $tcpdfInst->writeHTML($tcpdfHtml, true, false, true, false, '');
            $pdfBytes = $tcpdfInst->Output('', 'S');
        }

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

        $fromEmail = getEmailSetting('email_from_email', '') ?: getEmailSetting('smtp_username', '');
        $fromName = getEmailSetting('email_from_name', '') ?: getSetting('site_name', 'Hotel');
        $smtpHost = getEmailSetting('smtp_host', '');
        $smtpPort = (int)getEmailSetting('smtp_port', 587);
        $smtpUser = getEmailSetting('smtp_username', '');
        $smtpPass = getEmailSetting('smtp_password', '');
        $smtpSecure = getEmailSetting('smtp_secure', 'tls');

        if ($smtpHost === '') {
            throw new RuntimeException('SMTP host is not configured.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->Port = $smtpPort;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = $smtpSecure;
        $mail->CharSet = 'UTF-8';
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to, (string)$context['guest_name']);
        $invoiceRecipients = getEmailSetting('invoice_recipients', '');
        foreach (array_filter(array_map('trim', explode(',', $invoiceRecipients))) as $cc) {
            if (filter_var($cc, FILTER_VALIDATE_EMAIL) && $cc !== $to) {
                $mail->addCC($cc);
            }
        }
        // Use POS-style receipt HTML as the email body — logo via public HTTPS URL, no CID embedding
        $emailBody = receipt_build_pos_style_html($payment, $context, $pdo);

        $mail->isHTML(true);
        $mail->Subject = html_entity_decode($subject, ENT_QUOTES, 'UTF-8');
        $mail->Body = $emailBody;
        $mail->AltBody = $textBody !== ''
            ? html_entity_decode($textBody, ENT_QUOTES, 'UTF-8')
            : strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $emailBody));
        if (($pdf['bytes'] ?? '') !== '') {
            $mail->addStringAttachment($pdf['bytes'], $pdf['receipt_number'] . '.pdf', PHPMailer::ENCODING_BASE64, 'application/pdf');
        } elseif (!empty($pdf['path']) && is_file($pdf['path'])) {
            $mail->addAttachment($pdf['path'], $pdf['receipt_number'] . '.pdf', PHPMailer::ENCODING_BASE64, 'application/pdf');
        }
        $mail->send();

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
