<?php
/**
 * Full end-to-end simulation: pick a real booking, run buildBookingEmailVariables(),
 * render the payment_invoice template, and report any unresolved {{placeholders}}.
 *
 * Run: php scripts/diag-full-render.php
 */
define('ROSALYNS_HOTEL_BOOTSTRAP', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../config/invoice.php';
require_once __DIR__ . '/../config/receipts.php';

global $pdo, $email_site_name, $email_site_url, $email_from_email;

echo '=== Pick a real booking with a payment ===' . PHP_EOL;
$stmt = $pdo->query("
    SELECT b.*, r.name AS room_name_col, p.vat_amount AS p_vat, p.total_amount AS p_total
    FROM bookings b
    LEFT JOIN rooms r ON r.id = b.room_id
    LEFT JOIN payments p ON p.booking_id = b.id
    WHERE b.booking_reference IS NOT NULL
    ORDER BY b.id DESC
    LIMIT 1
");
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
    echo 'No bookings found' . PHP_EOL;
    exit(1);
}
echo 'Booking: ' . $booking['booking_reference'] . PHP_EOL;
echo 'tourism_levy_percent: ' . ($booking['tourism_levy_percent'] ?? 'NULL') . PHP_EOL;
echo 'tourism_levy_amount : ' . ($booking['tourism_levy_amount'] ?? 'NULL') . PHP_EOL;
echo 'vat_amount          : ' . ($booking['vat_amount'] ?? 'NULL') . PHP_EOL;
echo 'total_amount        : ' . ($booking['total_amount'] ?? 'NULL') . PHP_EOL;
echo 'total_with_vat      : ' . ($booking['total_with_vat'] ?? 'NULL') . PHP_EOL;
echo PHP_EOL;

$room = ['id' => (int)$booking['room_id'], 'name' => (string)($booking['room_name_col'] ?? 'Room')];

// Simulate what sendInvoiceEmailToGuestWithCC() does
$checkOut = !empty($booking['check_out_date']) ? date('F j, Y', strtotime((string)$booking['check_out_date'])) : '';
$invoiceNumber = 'INV-TEST-001';
$templateVars = buildBookingEmailVariables($booking, $room, [
    'invoice_number' => $invoiceNumber,
    'check_out'      => $checkOut,
    'invoice_link'   => '',
]);

echo '=== Resolved vars (invoice) ===' . PHP_EOL;
foreach (['logo_html','site_name','vat_rate','vat_amount','vat_number','vat_number_html','levy_rate','levy_amount','subtotal_amount','total_amount','address','contact_phone','invoice_number','check_out','guest_name','booking_reference'] as $k) {
    $v = (string)($templateVars[$k] ?? '*** MISSING ***');
    if (strlen($v) > 100) {
        $v = substr($v, 0, 60) . '... [' . strlen($v) . ' chars]';
    }
    echo str_pad($k, 20) . ': ' . $v . PHP_EOL;
}
echo PHP_EOL;

echo '=== Render payment_invoice and check for leftover {{}} ===' . PHP_EOL;
$dbTemplate = renderBookingEmailTemplate('payment_invoice', $templateVars);
if (!$dbTemplate) {
    echo 'ERROR: renderBookingEmailTemplate returned null — check DB template exists and is_active=1' . PHP_EOL;
    exit(1);
}
$rendered = $dbTemplate['html_body'];
preg_match_all('/\{\{([a-z_0-9]+)\}\}/', $rendered, $m);
$leftover = array_unique($m[1]);
if ($leftover) {
    echo 'UNRESOLVED: ' . implode(', ', $leftover) . PHP_EOL;
} else {
    echo 'ALL placeholders resolved ✓' . PHP_EOL;
}

echo PHP_EOL . '=== Render payment_receipt ===' . PHP_EOL;
// Simulate receipt_placeholders with a real payment
$payRow = $pdo->query("SELECT * FROM payments WHERE booking_id = " . (int)$booking['id'] . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($payRow) {
    $context = [
        'guest_name' => $booking['guest_name'] ?? '',
        'guest_email' => $booking['guest_email'] ?? '',
        'guest_phone' => $booking['guest_phone'] ?? '',
        'description' => 'Room booking payment',
    ];
    $placeholders = receipt_placeholders($pdo, $payRow, $context);
    $tpl = getBookingEmailTemplateConfig('payment_receipt', []);
    $html = (string)($tpl['html_body'] ?? '');
    $rendered2 = str_replace(array_keys($placeholders), array_values($placeholders), $html);
    preg_match_all('/\{\{([a-z_0-9]+)\}\}/', $rendered2, $m2);
    $leftover2 = array_unique($m2[1]);
    if ($leftover2) {
        echo 'UNRESOLVED: ' . implode(', ', $leftover2) . PHP_EOL;
    } else {
        echo 'ALL receipt placeholders resolved ✓' . PHP_EOL;
    }
    echo 'vat_amount in payment: ' . ($payRow['vat_amount'] ?? 'NULL') . PHP_EOL;
    echo 'total_amount in payment: ' . ($payRow['total_amount'] ?? 'NULL') . PHP_EOL;
} else {
    echo 'No payment record for this booking' . PHP_EOL;
}
