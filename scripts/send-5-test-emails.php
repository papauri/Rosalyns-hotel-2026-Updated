<?php

/**
 * Send 5 test emails with the new premium theme to johnpaulchirwa@gmail.com.
 *
 * Templates: booking_confirmed, tentative_booking_created, tentative_quotation,
 *            payment_invoice, payment_receipt
 *
 * Run: php scripts/send-5-test-emails.php
 */
define('ROSALYNS_HOTEL_BOOTSTRAP', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';

$to   = 'johnpaulchirwa@gmail.com';
$name = 'John Paul Chirwa';

// ── Sample substitution variables ──────────────────────────────────────────
$siteAddress = getSetting('address', 'Matuwi Village, Mangochi, Malawi');
$sitePhone   = getSetting('phone_main', '+265 888 226 665');
$siteEmail   = getSetting('email_main', 'info@rosalynsbeachhotel.com');
$siteName    = getSetting('site_name', 'Rosalyns Beach Hotel');

$logoPath = __DIR__ . '/../images/logo.png';
$logoSrc  = is_file($logoPath)
    ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath))
    : '';
$logoHtml = $logoSrc !== ''
    ? '<img src="' . $logoSrc . '" alt="' . htmlspecialchars($siteName, ENT_QUOTES) . '" style="max-height:60px;max-width:160px;">'
    : '<span style="font-family:Georgia,serif;font-size:20px;color:#6d6455;">' . htmlspecialchars($siteName, ENT_QUOTES) . '</span>';

$baseVars = [
    'site_name'     => $siteName,
    'address'       => $siteAddress,
    'contact_phone' => $sitePhone,
    'contact_email' => $siteEmail,
    'logo_html'     => $logoHtml,
    'guest_name'    => $name,
    'guest_email'   => $to,
    'phone_main'    => $sitePhone,
    'currency_symbol' => 'MWK',
    'check_in_time'   => '2:00 PM',
    'check_out_time'  => '11:00 AM',
    'payment_policy'  => 'A 50% deposit is required to confirm your booking. The balance is due on arrival.',
];

// ── Tax / VAT common vars (read from live DB settings) ─────────────────────
$vatNumber    = (string)getSetting('vat_number', '');
$vatRate      = (float)getSetting('vat_rate', 16.5);
$levyRate     = 1.0;   // standard tourism levy %
$subtotal     = 379310.00;
$levyAmt      = round($subtotal * $levyRate / 100, 2);
$vatAmt       = round($subtotal * $vatRate / 100, 2);
$totalWithTax = $subtotal + $levyAmt + $vatAmt;
$vatNumberHtml = $vatNumber !== ''
    ? '<p style="margin:8px 0 0;font-size:11px;color:#9b8f7e;text-align:center;">VAT Reg. No.: ' . htmlspecialchars($vatNumber, ENT_QUOTES, 'UTF-8') . '</p>'
    : '';
$taxVars = [
    'subtotal_amount' => 'MWK ' . number_format($subtotal, 2),
    'levy_rate'       => number_format($levyRate, 1),
    'levy_amount'     => 'MWK ' . number_format($levyAmt, 2),
    'vat_rate'        => number_format($vatRate, 1),
    'vat_amount'      => 'MWK ' . number_format($vatAmt, 2),
    'vat_number'      => $vatNumber,
    'vat_number_html' => $vatNumberHtml,
];

// ── Tests to send ──────────────────────────────────────────────────────────
$tests = [

    // 1. Confirmed Booking
    [
        'key'  => 'booking_confirmed',
        'vars' => $baseVars + [
            'booking_reference'       => 'LSH2026-CONF-001',
            'room_name'               => 'Deluxe Lake View Suite',
            'check_in_date_formatted' => 'June 3, 2026',
            'check_out_date_formatted' => 'June 6, 2026',
            'number_of_nights'        => '3',
            'number_of_guests'        => '2',
            'total_amount_formatted'  => '450,000',
        ],
    ],

    // 2. Tentative Booking Created
    [
        'key'  => 'tentative_booking_created',
        'vars' => $baseVars + [
            'booking_reference'           => 'LSH2026-TENT-001',
            'room_name'                   => 'Premium Garden Room',
            'check_in_date_formatted'     => 'July 10, 2026',
            'check_out_date_formatted'    => 'July 14, 2026',
            'number_of_nights'            => '4',
            'tentative_expires_at_formatted' => 'June 1, 2026 11:59 PM',
            'total_amount_formatted'      => '320,000',
        ],
    ],

    // 3. Room Quotation
    [
        'key'  => 'tentative_quotation',
        'vars' => $baseVars + [
            'quotation_reference' => 'QT-RBH-2026-TEST-001',
            'booking_reference'   => 'LSH2026-QUOT-001',
            'room_name'           => 'Honeymoon Chalet',
            'check_in_date'       => 'August 15, 2026',
            'check_out_date'      => 'August 20, 2026',
            'valid_until'         => 'June 10, 2026',
            'total_amount'        => 'MWK 680,000',
            'quotation_notes'     => 'This quotation includes breakfast for two guests daily. Kindly confirm within 14 days.',
        ],
    ],

    // 4. Room Invoice Email
    [
        'key'  => 'payment_invoice',
        'vars' => $baseVars + $taxVars + [
            'booking_reference' => 'LSH2026-INV-001',
            'invoice_number'    => 'INV-2026-TEST-001',
            'check_out'         => 'June 6, 2026',
            'total_amount'      => 'MWK ' . number_format($totalWithTax, 2),
        ],
    ],

    // 5. Payment Receipt
    [
        'key'  => 'payment_receipt',
        'vars' => $baseVars + $taxVars + [
            'receipt_number'    => 'RCP-2026-TEST-001',
            'payment_reference' => 'PAY-2026-TEST-001',
            'booking_type'      => 'Room Booking',
            'payment_date'      => 'May 27, 2026',
            'total_amount'      => 'MWK ' . number_format($totalWithTax / 2, 2),
            'payment_amount'    => 'MWK ' . number_format($totalWithTax / 2, 2),
        ],
    ],
];

// ── Send loop ──────────────────────────────────────────────────────────────
foreach ($tests as $i => $test) {
    $num = $i + 1;
    $key = $test['key'];

    // Load stored template from DB
    $tplRow  = getBookingEmailTemplateConfig($key, []);
    $subject = (string)($tplRow['subject'] ?? '(no subject)');
    $html    = (string)($tplRow['html_body'] ?? '');

    if ($html === '') {
        echo "  [{$num}] SKIP {$key} — no HTML in DB" . PHP_EOL;
        continue;
    }

    // Substitute sample vars
    $replace = bookingTemplateReplaceMap($test['vars']);
    $renderedHtml    = strtr($html, $replace);
    $renderedSubject = strtr($subject, $replace);

    // Send
    $result = sendEmail($to, $name, '[TEST ' . $num . '/5] ' . $renderedSubject, $renderedHtml);

    if (!empty($result['success'])) {
        echo "  [{$num}] ✓ SENT   {$key}" . PHP_EOL;
    } else {
        echo "  [{$num}] ✗ FAILED {$key}: " . ($result['message'] ?? 'unknown') . PHP_EOL;
    }

    // Small delay between sends
    usleep(500000);
}

echo PHP_EOL . 'Done. Check johnpaulchirwa@gmail.com for 5 emails.' . PHP_EOL;
