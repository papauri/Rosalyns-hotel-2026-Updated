<?php

/**
 * Diagnostic: check logo, VAT settings and placeholder coverage in DB templates.
 * Run: php scripts/diag-email-vars.php
 */
define('ROSALYNS_HOTEL_BOOTSTRAP', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';

echo '=== Logo ===' . PHP_EOL;
$src = function_exists('hotel_invoice_logo_src') ? hotel_invoice_logo_src() : 'FUNCTION_MISSING';
echo 'Length      : ' . strlen($src) . PHP_EOL;
echo 'data: URI?  : ' . (str_starts_with($src, 'data:') ? 'YES (base64 embedded)' : 'NO') . PHP_EOL;
echo 'http URL?   : ' . (str_starts_with($src, 'http') ? 'YES' : 'NO') . PHP_EOL;
echo 'Preview     : ' . substr($src, 0, 80) . PHP_EOL;
echo PHP_EOL;

echo '=== DB Settings ===' . PHP_EOL;
echo 'vat_enabled     : ' . var_export(getSetting('vat_enabled'), true) . PHP_EOL;
echo 'vat_rate        : ' . var_export(getSetting('vat_rate'), true) . PHP_EOL;
echo 'vat_number      : ' . var_export(getSetting('vat_number'), true) . PHP_EOL;
echo 'currency_symbol : ' . var_export(getSetting('currency_symbol'), true) . PHP_EOL;
echo 'site_url        : ' . var_export(getSetting('site_url'), true) . PHP_EOL;
echo 'hotel_address   : ' . var_export(getSetting('hotel_address', getSetting('address', '')), true) . PHP_EOL;
echo 'phone_main      : ' . var_export(getSetting('phone_main', ''), true) . PHP_EOL;
echo PHP_EOL;

// Simulate buildBookingEmailVariables with a dummy booking
echo '=== buildBookingEmailVariables vars ===' . PHP_EOL;
$dummyBooking = [
    'id'                  => 0,
    'room_id'             => 0,
    'booking_reference'   => 'TEST-001',
    'guest_name'          => 'Test Guest',
    'guest_email'         => 'test@example.com',
    'guest_phone'         => '',
    'check_in_date'       => '2026-06-01',
    'check_out_date'      => '2026-06-04',
    'number_of_nights'    => 3,
    'number_of_guests'    => 2,
    'total_amount'        => 450000.00,
    'vat_amount'          => null,
    'tourism_levy_amount' => null,
    'tourism_levy_percent' => null,
];
$dummyRoom = ['id' => 0, 'name' => 'Deluxe Suite'];
$vars = buildBookingEmailVariables($dummyBooking, $dummyRoom);
foreach (['logo_html', 'vat_number', 'vat_number_html', 'vat_rate', 'vat_amount', 'levy_rate', 'levy_amount', 'subtotal_amount', 'total_amount', 'address', 'contact_phone'] as $k) {
    $val = $vars[$k] ?? '*** MISSING ***';
    if (strlen((string)$val) > 120) {
        $val = substr((string)$val, 0, 80) . '... [' . strlen((string)$val) . ' chars]';
    }
    echo str_pad($k, 20) . ': ' . $val . PHP_EOL;
}
echo PHP_EOL;

echo '=== Template placeholder audit ===' . PHP_EOL;
$keys = ['payment_invoice', 'conference_invoice', 'payment_receipt', 'booking_confirmed', 'tentative_booking_created'];
foreach ($keys as $key) {
    $tpl  = getBookingEmailTemplateConfig($key, []);
    $html = (string)($tpl['html_body'] ?? '');
    preg_match_all('/\{\{([a-z_0-9]+)\}\}/', $html, $m);
    $unique = array_unique($m[1]);
    sort($unique);
    echo $key . ':' . PHP_EOL . '  ' . implode(', ', $unique) . PHP_EOL;
}
