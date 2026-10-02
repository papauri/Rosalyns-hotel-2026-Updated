<?php

/**
 * Render every emailed PDF document to bytes and send ONE test email per document type.
 *
 * Safe by design:
 *  - CLI only.
 *  - --to is REQUIRED and must be johnpaulchirwa@gmail.com unless --allow-any is passed.
 *  - Read-only: never allocates finance sequences, never writes records or receipt files.
 *    Uses the latest real rows (read-only) or --order / --payment ids.
 *  - Admin BCC is switched off so the only recipient is --to.
 *  - Subject is prefixed "[TEST]"; PDFs are also saved to a scratch folder that is printed.
 *
 * Usage:
 *   php scripts/send-test-documents.php --to=johnpaulchirwa@gmail.com
 *   php scripts/send-test-documents.php --to=johnpaulchirwa@gmail.com --only=restaurant_receipt,payment_receipt
 *   php scripts/send-test-documents.php --to=... --order=160 --payment=120 --outdir=/tmp/pdfs --no-send --watermark=SAMPLE
 *
 * Adding a document type: add one entry to $registry below. The builder receives
 * ($pdo, $args) and returns ['filename','subject','body_html','pdf' (bytes),'text' (optional)].
 * Pull sample rows READ-ONLY and call the same renderer production uses.
 */

declare(strict_types=1);

ini_set('memory_limit', '512M');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

const RH_TEST_DEFAULT_RECIPIENT = 'johnpaulchirwa@gmail.com';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../config/receipts.php';
require_once __DIR__ . '/../config/credit-notes.php';
require_once __DIR__ . '/../includes/quotation-pdf.php';

/* ---------- args ---------- */
$args = ['to' => '', 'only' => '', 'allow-any' => false, 'no-send' => false, 'outdir' => '', 'order' => 0, 'payment' => 0, 'watermark' => ''];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $key = $m[1];
        if (!array_key_exists($key, $args)) {
            fwrite(STDERR, "Unknown option --$key\n");
            exit(2);
        }
        $args[$key] = is_bool($args[$key]) ? true : (is_int($args[$key]) ? (int)($m[2] ?? 0) : (string)($m[2] ?? ''));
    }
}

$to = trim((string)$args['to']);
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "--to=<email> is required.\n");
    exit(2);
}
if (strcasecmp($to, RH_TEST_DEFAULT_RECIPIENT) !== 0 && !$args['allow-any']) {
    fwrite(STDERR, "Refusing to send to $to. Only " . RH_TEST_DEFAULT_RECIPIENT . " is allowed unless --allow-any is given.\n");
    exit(2);
}

// Only the requested recipient may ever receive a test email.
$email_bcc_admin = false;
$email_log_enabled = false; // test sends must not write email-log rows

$outDir = $args['outdir'] !== '' ? rtrim((string)$args['outdir'], '/\\') : sys_get_temp_dir() . '/rh-test-documents-' . date('Ymd-His');
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}

$pdoConn = $GLOBALS['pdo'];
$pdfOpts = $args['watermark'] !== '' ? ['watermark' => (string)$args['watermark']] : [];

/* ---------- invoice / quotation helpers (read-only; sample data carries a SAMPLE watermark) ---------- */
require_once __DIR__ . '/../config/invoice.php';

/** Simple branded email body for a test document. */
function rhdoc_inv_email(string $label, string $name, string $intro, array $rows): string
{
    $tok = hotel_brand_tokens();
    $inner = hotel_premium_email_body(htmlspecialchars($name), '<p style="margin:0 0 16px;">' . htmlspecialchars($intro) . '</p>')
        . hotel_premium_email_summary_rows(htmlspecialchars($label) . ' Summary', $rows);
    return strtr(hotel_premium_email_html($label, $inner, RH_TEST_DEFAULT_RECIPIENT), [
        '{{logo_html}}' => '',
        '{{site_name}}' => htmlspecialchars($tok['hotel']['name']),
        '{{address}}' => htmlspecialchars($tok['hotel']['address']),
        '{{contact_phone}}' => htmlspecialchars($tok['hotel']['phone']),
        '{{contact_email}}' => htmlspecialchars($tok['hotel']['email']),
    ]);
}

/** Sample room booking (invoice id 0 so nothing is read from or written to the DB). */
function rhdoc_inv_sample_room(bool $group, bool $paid): array
{
    $booking = [
        'id' => 0, 'booking_reference' => 'SAMPLE-1001', 'guest_name' => 'Sample Guest',
        'guest_email' => RH_TEST_DEFAULT_RECIPIENT, 'guest_phone' => '+265 999 000 000',
        'room_name' => 'Deluxe Lake View - Room 204', 'check_in_date' => date('Y-m-d', strtotime('+9 days')),
        'check_out_date' => date('Y-m-d', strtotime('+12 days')), 'number_of_nights' => 3,
        'adult_guests' => 2, 'child_guests' => 1, 'total_amount' => 480000, 'package_total' => 30000,
        'child_supplement_total' => 45000, 'tourism_levy_amount' => 12000, 'tourism_levy_percent' => 2.5,
        'rate_plan_discount' => 5000, 'rate_plan_label' => 'Early Bird', 'primary_booking_id' => null,
    ];
    $groupRows = [];
    if ($group) {
        $booking['room_name'] = 'Deluxe Lake View - Room 204';
        $groupRows = [
            ['id' => 0, 'booking_reference' => 'SAMPLE-1001', 'total_amount' => 300000, 'child_supplement_total' => 45000,
             'tourism_levy_amount' => 7500, 'tourism_levy_percent' => 2.5, 'vat_amount' => 0, 'total_with_vat' => 0,
             'occupancy_type' => 'double', 'number_of_nights' => 3, 'room_name' => 'Deluxe Lake View - Room 204'],
            ['id' => 0, 'booking_reference' => 'SAMPLE-1001B', 'total_amount' => 210000, 'child_supplement_total' => 0,
             'tourism_levy_amount' => 4500, 'tourism_levy_percent' => 2.5, 'vat_amount' => 0, 'total_with_vat' => 0,
             'occupancy_type' => 'single', 'number_of_nights' => 3, 'room_name' => 'Standard Garden - Room 105'],
        ];
    }
    $folio = [
        ['charge_type' => 'food', 'description' => 'Restaurant dinner', 'quantity' => 2, 'unit_price' => 18000, 'line_subtotal' => 30638.0, 'vat_amount' => 5362.0, 'line_total' => 36000, 'posted_at' => date('Y-m-d H:i:s')],
        ['charge_type' => 'drink', 'description' => 'Minibar', 'quantity' => 3, 'unit_price' => 4500, 'line_subtotal' => 11489.0, 'vat_amount' => 2011.0, 'line_total' => 13500, 'posted_at' => date('Y-m-d H:i:s')],
    ];
    $base = vat_components((float)($group ? 510000 : 480000))['total'];
    $grand = $base + 49500 + 12000;
    if ($paid) {
        $pay = [
            ['payment_date' => date('Y-m-d', strtotime('-20 days')), 'payment_method' => 'card', 'payment_type' => 'deposit', 'total_amount' => 300000, 'payment_reference' => 'PAY-SAMPLE-1'],
            ['payment_date' => date('Y-m-d'), 'payment_method' => 'cash', 'payment_type' => 'balance', 'total_amount' => $grand - 300000, 'payment_reference' => 'PAY-SAMPLE-2'],
        ];
        $summary = ['grand_total' => $grand, 'amount_paid' => $grand, 'balance_due' => 0.0];
    } else {
        $pay = [
            ['payment_date' => date('Y-m-d', strtotime('-20 days')), 'payment_method' => 'mobile_money', 'payment_type' => 'deposit', 'total_amount' => 200000, 'payment_reference' => 'PAY-SAMPLE-1'],
            ['payment_date' => date('Y-m-d', strtotime('-2 days')), 'payment_method' => 'cash', 'payment_type' => 'refund', 'total_amount' => 20000, 'payment_reference' => 'RFD-SAMPLE-1'],
        ];
        $summary = ['grand_total' => $grand, 'amount_paid' => 180000.0, 'balance_due' => $grand - 180000];
    }
    $packages = [['package_name' => 'Breakfast Package', 'quantity' => 2, 'price_amount' => 15000, 'total_cost' => 30000]];
    return [$booking, $groupRows, ['packages' => $packages, 'folio_charges' => $folio, 'payments' => $pay, 'summary' => $summary]];
}

/** Latest real room booking (read-only) or null. */
function rhdoc_inv_latest_booking(PDO $pdo): ?array
{
    $row = $pdo->query('SELECT b.*, r.name AS room_name FROM bookings b JOIN rooms r ON b.room_id = r.id ORDER BY b.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function rhdoc_inv_file(string $number): string
{
    return preg_replace('/[^A-Za-z0-9_-]+/', '-', $number) . '.pdf';
}

/** Sample / latest-real enquiry-style rows for conference, gym and event documents. */
function rhdoc_inv_sample_inquiry(string $kind, bool $paid): array
{
    $money = $paid
        ? ['total_amount' => 850000.0, 'vat_rate' => 17.5, 'vat_amount' => 126595.74, 'total_with_vat' => 850000.0, 'amount_paid' => 850000.0, 'amount_due' => 0.0, 'deposit_required' => 1, 'deposit_amount' => 300000.0, 'deposit_paid' => 300000.0]
        : ['total_amount' => 850000.0, 'vat_rate' => 17.5, 'vat_amount' => 126595.74, 'total_with_vat' => 850000.0, 'amount_paid' => 300000.0, 'amount_due' => 550000.0, 'deposit_required' => 1, 'deposit_amount' => 300000.0, 'deposit_paid' => 300000.0];
    if ($kind === 'conference') {
        return array_merge($money, [
            'id' => 0, 'inquiry_reference' => 'SAMPLE-CONF-001', 'company_name' => 'Sample Holdings Ltd', 'contact_person' => 'Sample Contact',
            'email' => RH_TEST_DEFAULT_RECIPIENT, 'phone' => '+265 999 000 000', 'room_name' => 'Lake Boardroom',
            'event_date' => date('Y-m-d', strtotime('+14 days')), 'start_time' => '08:30:00', 'end_time' => '16:30:00',
            'number_of_attendees' => 40, 'event_type' => 'Annual workshop', 'catering_required' => 1, 'av_equipment' => 'Projector, microphones',
        ]);
    }
    if ($kind === 'gym') {
        return array_merge($money, [
            'total_amount' => 120000.0, 'vat_amount' => 17872.34, 'total_with_vat' => 120000.0, 'amount_paid' => $paid ? 120000.0 : 50000.0, 'amount_due' => $paid ? 0.0 : 70000.0,
            'deposit_required' => 0, 'deposit_amount' => 0, 'deposit_paid' => 0,
            'id' => 0, 'reference_number' => 'SAMPLE-GYM-001', 'name' => 'Sample Member', 'email' => RH_TEST_DEFAULT_RECIPIENT,
            'phone' => '+265 999 000 000', 'membership_type' => 'Monthly - Full Access', 'preferred_date' => date('Y-m-d', strtotime('+3 days')),
            'notes' => 'Includes towel service and one induction session.',
        ]);
    }
    return array_merge($money, [
        'total_amount' => 60000.0, 'vat_amount' => 8936.17, 'total_with_vat' => 60000.0, 'amount_paid' => $paid ? 60000.0 : 20000.0, 'amount_due' => $paid ? 0.0 : 40000.0,
        'deposit_required' => 0, 'deposit_amount' => 0, 'deposit_paid' => 0,
        'id' => 0, 'reference_number' => 'SAMPLE-EVT-001', 'name' => 'Sample Attendee', 'email' => RH_TEST_DEFAULT_RECIPIENT,
        'phone' => '+265 999 000 000', 'event_title' => 'Sunset Jazz Night', 'event_date' => date('Y-m-d', strtotime('+10 days')),
        'guests' => 2, 'notes' => 'Table by the lake, welcome drink included.',
    ]);
}

function rhdoc_inv_hotel_args(): array
{
    $h = hotel_brand_tokens()['hotel'];
    return [$h['name'], $h['email'], $h['phone'], $h['address'], $h['currency']];
}

/* ---------- registry: one entry per document type ---------- */
$registry = [
    'restaurant_receipt' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        $orderId = (int)$a['order'];
        if ($orderId <= 0) {
            $orderId = (int)$pdo->query("SELECT so.id FROM stock_orders so WHERE so.status = 'paid' AND EXISTS (SELECT 1 FROM stock_order_items i WHERE i.order_id = so.id) ORDER BY so.id DESC LIMIT 1")->fetchColumn();
        }
        $data = $orderId > 0 ? receipt_load_restaurant_order($pdo, $orderId) : null;
        if (!$data) {
            // No real order in this database: render a clearly-marked sample (nothing is written).
            $order = ['id' => 0, 'reference' => 'SAMPLE-RST-1001', 'invoice_number' => 'SAMPLE-RST-1001', 'status' => 'paid',
                'order_type' => 'dine_in', 'table_number' => '4', 'room_number' => '', 'customer_name' => 'Sample Guest',
                'customer_email' => RH_TEST_DEFAULT_RECIPIENT, 'customer_phone' => '', 'payment_method' => 'cash', 'notes' => '',
                'paid_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'booking_reference' => '',
                'subtotal' => 38000, 'total_amount' => 38000, 'tax_amount' => 0, 'discount_amount' => 0, 'tip_amount' => 0, 'service_charge' => 0, 'tendered_amount' => 0, 'change_due' => 0];
            $items = [
                ['item_name' => 'Grilled Chambo', 'name' => 'Grilled Chambo', 'quantity' => 2, 'unit_price' => 12000, 'line_total' => 24000, 'total_price' => 24000, 'notes' => ''],
                ['item_name' => 'Fresh Juice', 'name' => 'Fresh Juice', 'quantity' => 2, 'unit_price' => 7000, 'line_total' => 14000, 'total_price' => 14000, 'notes' => ''],
            ];
            $data = ['order' => $order, 'items' => $items, 'ctx' => receipt_restaurant_context($pdo, $order)];
            $pdfOpts = ['watermark' => 'SAMPLE'];
        }
        $ref = (string)($data['order']['invoice_number'] ?: $data['order']['reference']);
        return [
            'filename'  => preg_replace('/[^A-Za-z0-9_-]+/', '-', $ref) . '.pdf',
            'subject'   => 'Receipt ' . $ref . ' - ' . hotel_brand_tokens()['hotel']['name'],
            'body_html' => receipt_build_restaurant_email_html($data['order'], $data['items'], $data['ctx']),
            'pdf'       => receipt_restaurant_pdf_bytes($data['order'], $data['items'], $data['ctx'], $pdfOpts),
        ];
    },
    'payment_receipt' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        $paymentId = (int)$a['payment'];
        if ($paymentId <= 0) {
            $paymentId = (int)$pdo->query("SELECT id FROM payments WHERE deleted_at IS NULL AND receipt_number IS NOT NULL AND receipt_number <> '' AND payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' ORDER BY id DESC LIMIT 1")->fetchColumn();
        }
        $payment = $paymentId > 0 ? receipt_get_payment($pdo, $paymentId) : null;
        if (!$payment) {
            // No real payment in this database: render a clearly-marked sample (nothing is written).
            $payment = ['id' => 0, 'receipt_number' => 'SAMPLE-RCP-1001', 'booking_type' => 'other', 'booking_id' => 0,
                'booking_reference' => 'SAMPLE-BK-1001', 'payment_reference' => 'SAMPLE-PAY-1001', 'payment_date' => date('Y-m-d'),
                'payment_method' => 'cash', 'payment_type' => 'full_payment', 'payment_status' => 'completed',
                'payment_amount' => 100000, 'vat_amount' => 0, 'total_amount' => 100000, 'vat_rate' => 0, 'tip_amount' => 0,
                'recorded_by_name' => 'Front Desk'];
            $pdfOpts = ['watermark' => 'SAMPLE'];
        }
        if (trim((string)($payment['receipt_number'] ?? '')) === '') {
            $payment['receipt_number'] = 'SAMPLE-' . $paymentId; // never allocate a real number in a test
        }
        $context = receipt_hydrate_context($pdo, $payment);
        $number = (string)$payment['receipt_number'];
        return [
            'filename'  => preg_replace('/[^A-Za-z0-9_-]+/', '-', $number) . '.pdf',
            'subject'   => 'Receipt ' . $number . ' - ' . hotel_brand_tokens()['hotel']['name'],
            'body_html' => receipt_build_pos_style_html($payment, $context, $pdo),
            'pdf'       => receipt_payment_pdf_bytes($payment, $context, $pdfOpts),
        ];
    },
    // ---- invoices & quotations (config/invoice.php) ----
    'invoice' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        [$name, $email, $phone, $addr, $cur] = rhdoc_inv_hotel_args();
        $real = rhdoc_inv_latest_booking($pdo);
        $group = [];
        $preload = null;
        if ($real) {
            $booking = $real;
            $opts = $pdfOpts;
        } else {
            [$booking, $group, $preload] = rhdoc_inv_sample_room(true, false);
            $opts = ['watermark' => 'SAMPLE'];
        }
        $number = 'SAMPLE-INV-1001';
        $html = buildInvoiceHTML($booking, $number, $name, $email, $phone, $addr, $cur, $group, $preload);
        return [
            'filename'  => rhdoc_inv_file($number),
            'subject'   => 'Invoice ' . $number . ' - ' . $name,
            'body_html' => rhdoc_inv_email('Invoice ' . $number, (string)$booking['guest_name'], 'Please find your invoice attached.', [['Invoice No.', $number], ['Booking', htmlspecialchars((string)$booking['booking_reference'])]]),
            'pdf'       => bookingRenderPdfFromHtml($html, 'Invoice ' . $number, $opts),
        ];
    },
    'final_invoice' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        // Same builder generateAndSendFinalInvoice() uses (via generateInvoicePDF), settled in full.
        [$name, $email, $phone, $addr, $cur] = rhdoc_inv_hotel_args();
        $real = rhdoc_inv_latest_booking($pdo);
        $preload = null;
        if ($real) {
            $booking = $real;
            $opts = $pdfOpts;
        } else {
            [$booking, , $preload] = rhdoc_inv_sample_room(false, true);
            $booking['child_guests'] = 0; $booking['child_supplement_total'] = 0; // keeps the sample to one page
            $opts = ['watermark' => 'SAMPLE'];
        }
        $number = 'SAMPLE-FINAL-1001';
        $html = buildInvoiceHTML($booking, $number, $name, $email, $phone, $addr, $cur, [], $preload);
        return [
            'filename'  => rhdoc_inv_file($number),
            'subject'   => 'Final invoice ' . $number . ' - ' . $name,
            'body_html' => rhdoc_inv_email('Final Invoice ' . $number, (string)$booking['guest_name'], 'Thank you for staying with us. Your final invoice is attached.', [['Invoice No.', $number], ['Booking', htmlspecialchars((string)$booking['booking_reference'])]]),
            'pdf'       => bookingRenderPdfFromHtml($html, 'Invoice ' . $number, $opts),
        ];
    },
    'conference_invoice' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        [$name, $email, $phone, $addr, $cur] = rhdoc_inv_hotel_args();
        $row = $pdo->query('SELECT ci.*, cr.name AS room_name FROM conference_inquiries ci LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id ORDER BY ci.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $opts = $row ? $pdfOpts : ['watermark' => 'SAMPLE'];
        $row = $row ?: rhdoc_inv_sample_inquiry('conference', false);
        $number = 'SAMPLE-CONF-INV-1001';
        return [
            'filename'  => rhdoc_inv_file($number),
            'subject'   => 'Conference invoice ' . $number . ' - ' . $name,
            'body_html' => rhdoc_inv_email('Conference Invoice ' . $number, (string)($row['contact_person'] ?? 'Client'), 'Please find your conference invoice attached.', [['Invoice No.', $number], ['Reference', htmlspecialchars((string)($row['inquiry_reference'] ?? ''))]]),
            'pdf'       => bookingRenderPdfFromHtml(buildConferenceInvoiceHTML($row, $number, $name, $email, $phone, $addr, $cur), 'Invoice ' . $number, $opts),
        ];
    },
    'gym_invoice' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        [$name, $email, $phone, $addr, $cur] = rhdoc_inv_hotel_args();
        $row = $pdo->query('SELECT * FROM gym_inquiries ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $opts = $row ? $pdfOpts : ['watermark' => 'SAMPLE'];
        $row = $row ?: rhdoc_inv_sample_inquiry('gym', true);
        $number = 'SAMPLE-GYM-INV-1001';
        return [
            'filename'  => rhdoc_inv_file($number),
            'subject'   => 'Gym membership invoice ' . $number . ' - ' . $name,
            'body_html' => rhdoc_inv_email('Gym Membership Invoice ' . $number, (string)($row['name'] ?? 'Member'), 'Your membership invoice is attached.', [['Invoice No.', $number], ['Reference', htmlspecialchars((string)($row['reference_number'] ?? ''))]]),
            'pdf'       => bookingRenderPdfFromHtml(buildGymInvoiceHTML($row, $number, $name, $email, $phone, $addr, $cur), 'Invoice ' . $number, $opts),
        ];
    },
    'event_invoice' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        [$name, $email, $phone, $addr, $cur] = rhdoc_inv_hotel_args();
        $row = $pdo->query('SELECT * FROM event_inquiries ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $opts = $row ? $pdfOpts : ['watermark' => 'SAMPLE'];
        $row = $row ?: rhdoc_inv_sample_inquiry('event', true);
        $number = 'SAMPLE-EVT-INV-1001';
        return [
            'filename'  => rhdoc_inv_file($number),
            'subject'   => 'Event booking invoice ' . $number . ' - ' . $name,
            'body_html' => rhdoc_inv_email('Event Booking Invoice ' . $number, (string)($row['name'] ?? 'Guest'), 'Your event booking invoice is attached.', [['Invoice No.', $number], ['Reference', htmlspecialchars((string)($row['reference_number'] ?? ''))]]),
            'pdf'       => bookingRenderPdfFromHtml(buildEventInvoiceHTML($row, $number, $name, $email, $phone, $addr, $cur), 'Invoice ' . $number, $opts),
        ];
    },
    'gym_quotation' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        $name = hotel_brand_tokens()['hotel']['name'];
        $row = $pdo->query('SELECT * FROM gym_inquiries ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $opts = $row ? $pdfOpts : ['watermark' => 'SAMPLE'];
        $row = $row ?: rhdoc_inv_sample_inquiry('gym', false);
        $pdf = generateGymQuotationPDF($row, array_merge(['valid_days' => 14, 'quote_reference' => 'SAMPLE-GQ-1001'], $opts));
        return [
            'filename'  => rhdoc_inv_file('SAMPLE-GQ-1001'),
            'subject'   => 'Gym membership quotation - ' . $name,
            'body_html' => rhdoc_inv_email('Gym Membership Quotation', (string)($row['name'] ?? 'Member'), 'Your membership quotation is attached.', [['Quotation', 'SAMPLE-GQ-1001']]),
            'pdf'       => $pdf,
        ];
    },
    'event_quotation' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        $name = hotel_brand_tokens()['hotel']['name'];
        $row = $pdo->query('SELECT * FROM event_inquiries ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $opts = $row ? $pdfOpts : ['watermark' => 'SAMPLE'];
        $row = $row ?: rhdoc_inv_sample_inquiry('event', false);
        $pdf = generateEventInquiryQuotationPDF($row, array_merge(['valid_days' => 14, 'quote_reference' => 'SAMPLE-EQ-1001'], $opts));
        return [
            'filename'  => rhdoc_inv_file('SAMPLE-EQ-1001'),
            'subject'   => 'Event quotation - ' . $name,
            'body_html' => rhdoc_inv_email('Event Booking Quotation', (string)($row['name'] ?? 'Guest'), 'Your event quotation is attached.', [['Quotation', 'SAMPLE-EQ-1001']]),
            'pdf'       => $pdf,
        ];
    },
    'credit_note' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        $cn = $pdo->query('SELECT * FROM credit_notes ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $opts = $cn ? $pdfOpts : ['watermark' => 'SAMPLE'];
        $apps = [];
        if ($cn) {
            $st = $pdo->prepare('SELECT cna.*, au.full_name AS applied_by_name FROM credit_note_applications cna LEFT JOIN admin_users au ON au.id = cna.applied_by WHERE cna.credit_note_id = ? ORDER BY cna.applied_at');
            $st->execute([(int)$cn['id']]);
            $apps = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $cn = ['credit_note_number' => 'SAMPLE-CN-0001', 'guest_name' => 'Amina Banda', 'guest_email' => 'amina@example.com', 'booking_reference' => 'SAMPLE-1001',
                'original_amount' => 150000, 'amount_used' => 60000, 'balance' => 90000, 'reason' => 'service_issue', 'status' => 'partially_applied',
                'reason_notes' => 'Air conditioning in the room was out of order for two nights; goodwill credit agreed with the duty manager.',
                'issued_at' => date('Y-m-d H:i:s', strtotime('-10 days')), 'expires_at' => date('Y-m-d', strtotime('+11 months'))];
            $apps = [['applied_at' => date('Y-m-d H:i:s', strtotime('-3 days')), 'applied_to_booking_reference' => 'SAMPLE-1042', 'applied_by_name' => 'Front Desk', 'amount_applied' => 60000]];
        }
        $name = hotel_brand_tokens()['hotel']['name'];
        $num = (string)$cn['credit_note_number'];
        return [
            'filename'  => rhdoc_inv_file($num),
            'subject'   => 'Credit note ' . $num . ' - ' . $name,
            'body_html' => rhdoc_inv_email('Credit Note ' . $num, (string)$cn['guest_name'], 'Your credit note is attached.', [['Credit note', $num]]),
            'pdf'       => rh_credit_note_pdf_bytes($cn, $apps, $opts),
        ];
    },
    'room_quotation' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        $name = hotel_brand_tokens()['hotel']['name'];
        $room = $pdo->query('SELECT * FROM rooms ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Deluxe Lake View', 'price_per_night' => 120000, 'bed_type' => 'King', 'max_guests' => 3];
        $booking = ['booking_reference' => 'SAMPLE-1001', 'guest_name' => 'Amina Banda', 'number_of_nights' => 3, 'adult_guests' => 2, 'child_guests' => 1,
            'total_amount' => vat_components(360000.0)['total'] + 45000, 'vat_amount' => vat_components(360000.0)['vat'], 'vat_rate' => (float)getSetting('vat_rate', 0),
            'child_supplement_total' => 45000, 'deposit_required' => 1, 'deposit_amount' => 150000,
            'check_in_date' => date('Y-m-d', strtotime('+30 days')), 'check_out_date' => date('Y-m-d', strtotime('+33 days')),
            'special_requests' => 'Ground floor room if possible; late arrival around 9pm.'];
        $pdf = generateQuotationPDF($booking, $room, ['valid_days' => 14, 'quotation_notes' => 'Breakfast is included for all guests.', 'watermark' => 'SAMPLE']);
        return [
            'filename'  => rhdoc_inv_file('QT-SAMPLE-1001'),
            'subject'   => 'Room quotation - ' . $name,
            'body_html' => rhdoc_inv_email('Room Quotation', 'Amina Banda', 'Your room quotation is attached.', [['Quotation', 'QT-SAMPLE-1001']]),
            'pdf'       => $pdf,
        ];
    },
    'conference_quotation' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        $name = hotel_brand_tokens()['hotel']['name'];
        $room = ['name' => 'Lakeside Conference Hall'];
        $enq = ['inquiry_reference' => 'SAMPLE-CONF-1001', 'company_name' => 'Chirwa & Partners', 'contact_person' => 'Thoko Chirwa', 'event_type' => 'Annual General Meeting',
            'number_of_attendees' => 60, 'event_date' => date('Y-m-d', strtotime('+45 days')), 'start_time' => '08:30:00', 'end_time' => '16:30:00',
            'total_amount' => 1800000, 'vat_rate' => (float)getSetting('vat_rate', 0), 'vat_amount' => 0, 'total_with_vat' => 0, 'deposit_required' => 900000,
            'notes' => 'Includes projector, two flip charts, tea breaks and a buffet lunch.'];
        $pdf = generateConferenceQuotationPDF($enq, $room, ['valid_days' => 14, 'quote_reference' => 'SAMPLE-CQ-1001', 'watermark' => 'SAMPLE']);
        return [
            'filename'  => rhdoc_inv_file('SAMPLE-CQ-1001'),
            'subject'   => 'Conference quotation - ' . $name,
            'body_html' => rhdoc_inv_email('Conference Quotation', 'Thoko Chirwa', 'Your conference quotation is attached.', [['Quotation', 'SAMPLE-CQ-1001']]),
            'pdf'       => $pdf,
        ];
    },
    'event_ticket_quotation' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        $name = hotel_brand_tokens()['hotel']['name'];
        $event = ['id' => 7, 'title' => 'Sunset Jazz Evening', 'ticket_price' => 25000, 'event_date' => date('Y-m-d', strtotime('+20 days')),
            'start_time' => '18:00:00', 'end_time' => '22:00:00', 'location' => 'Beach Terrace'];
        $pdf = generateEventQuotationPDF($event, ['name' => 'Kondwani Phiri', 'email' => 'kondwani@example.com', 'phone' => '+265 999 000 111'],
            ['attendee_count' => 12, 'valid_days' => 14, 'quote_reference' => 'SAMPLE-EQ-7', 'quotation_notes' => 'Table service and a welcome drink are included.', 'watermark' => 'SAMPLE']);
        return [
            'filename'  => rhdoc_inv_file('SAMPLE-EQ-7'),
            'subject'   => 'Event proposal quotation - ' . $name,
            'body_html' => rhdoc_inv_email('Event Quotation', 'Kondwani Phiri', 'Your event quotation is attached.', [['Quotation', 'SAMPLE-EQ-7']]),
            'pdf'       => $pdf,
        ];
    },
    'end_of_day' => function (PDO $pdo, array $a) use ($pdfOpts): array {
        require_once __DIR__ . '/../includes/booking-functions.php';
        require_once __DIR__ . '/../includes/eod-pdf-builder.php';
        $name = hotel_brand_tokens()['hotel']['name'];
        $cur = hotel_brand_tokens()['hotel']['currency'] . ' ';
        $d = [
            'date' => date('Y-m-d'), 'gross' => 2480000.0, 'net' => 2390000.0, 'adr' => 118000.0, 'revpar' => 82000.0, 'cash_total' => 640000.0, 'outstanding' => 310000.0,
            'rev' => ['room_gross' => 1650000, 'conf_gross' => 400000, 'fnb_gross' => 330000, 'gym_gross' => 60000, 'events_gross' => 40000, 'refunds' => 90000, 'total_vat' => 120000, 'txn_count' => 41, 'pending' => 80000],
            'ops' => ['arrivals_completed' => 6, 'expected_arrivals' => 8, 'departures_completed' => 5, 'expected_departures' => 5, 'stayovers' => 14, 'new_bookings' => 4, 'cancellations' => 1, 'no_shows' => 0],
            'methods' => [['method' => 'cash', 'total' => 640000, 'cnt' => 14], ['method' => 'card', 'total' => 1200000, 'cnt' => 17], ['method' => 'mobile_money', 'total' => 640000, 'cnt' => 10]],
            'pos' => ['orders' => 38, 'gross' => 330000, 'cogs' => 118000, 'voided_count' => 2, 'voided_value' => 14000],
            'pos_by_type' => [['order_type' => 'dine_in', 'cnt' => 22, 'gross' => 190000], ['order_type' => 'room_service', 'cnt' => 9, 'gross' => 95000], ['order_type' => 'takeaway', 'cnt' => 7, 'gross' => 45000]],
            'top_items' => [['item_name' => 'Grilled chambo', 'menu_type' => 'Food', 'qty' => 18, 'revenue' => 108000], ['item_name' => 'Kuche Kuche lager', 'menu_type' => 'Drink', 'qty' => 42, 'revenue' => 84000]],
            'void_reasons' => [['reason' => 'Guest changed order', 'cnt' => 2, 'value' => 14000]],
            'hk' => ['pending' => 3, 'in_progress' => 2, 'completed' => 11], 'reviewRow' => ['cnt' => 2, 'avg_rating' => 4.5],
            'rooms_total' => 24, 'rooms_occupied' => 20, 'rooms_oo' => 1, 'occupancy_pct' => 83.3,
            'tom' => ['arrivals' => 7, 'departures' => 6, 'rev_forecast' => 1900000],
            'score' => 82, 'score_label' => 'Healthy', 'net_change' => 120000, 'pos_change' => -15000, 'occ_change' => 4.2,
            'rooms_unsold' => 3, 'empty_room_opportunity' => 354000, 'payment_capture_rate' => 96.5,
            'room_type_perf' => [['room_type' => 'Deluxe Lake View', 'bookings' => 9, 'revenue' => 980000], ['room_type' => 'Standard Garden', 'bookings' => 11, 'revenue' => 670000]],
            'guest_intel' => ['new_guests' => 5, 'returning_guests' => 3, 'avg_lead_days' => 21], 'returning_rate' => 37.5,
            'closeout_alerts' => [['level' => 'warn', 'title' => 'Open tabs', 'detail' => '2 bar tabs still open'], ['level' => 'watch', 'title' => 'Cash variance', 'detail' => 'Till 1 short by 2,000']],
            'maintenance' => ['urgent' => 1, 'high' => 2, 'medium' => 3, 'low' => 1, 'total_open' => 7],
            'quotation_stats' => ['sent_today' => 3, 'accepted_today' => 1, 'total_active' => 9, 'pipeline_value' => 4200000.0],
        ];
        return [
            'filename'  => 'SAMPLE-end-of-day-' . date('Y-m-d') . '.pdf',
            'subject'   => 'End of day report SAMPLE - ' . $name,
            'body_html' => rhdoc_inv_email('End of Day Report', 'Management', 'The end-of-day report (sample data) is attached.', [['Date', date('Y-m-d')]]),
            'pdf'       => buildEodPdf($d, $name, $cur, 'Test run', ['watermark' => 'SAMPLE']),
        ];
    },
];

$only = array_values(array_filter(array_map('trim', explode(',', (string)$args['only']))));
$run = $only ? array_values(array_intersect(array_keys($registry), $only)) : array_keys($registry);
$unknown = array_diff($only, array_keys($registry));
if ($unknown) {
    fwrite(STDERR, 'Unknown document type(s): ' . implode(', ', $unknown) . '. Available: ' . implode(', ', array_keys($registry)) . "\n");
    exit(2);
}

echo "Output folder: $outDir\n";
$sent = 0;
$failed = 0;
foreach ($run as $type) {
    try {
        $doc = $registry[$type]($pdoConn, $args);
        $path = $outDir . '/' . $type . '__' . $doc['filename'];
        file_put_contents($path, $doc['pdf']);
        echo sprintf("[%s] rendered %s (%d bytes)\n", $type, $path, strlen($doc['pdf']));

        if ($args['no-send']) {
            continue;
        }
        $result = sendEmailWithAttachments(
            $to,
            null,
            '[TEST] ' . $doc['subject'],
            $doc['body_html'],
            [['content' => $doc['pdf'], 'name' => $doc['filename'], 'mime' => 'application/pdf']],
            (string)($doc['text'] ?? '')
        );
        if (!empty($result['success'])) {
            $sent++;
            echo "[$type] email sent to $to" . (!empty($result['preview']) ? ' (dev-mode preview only)' : '') . "\n";
        } else {
            $failed++;
            echo "[$type] SEND FAILED: " . ($result['message'] ?? 'unknown') . "\n";
        }
    } catch (Throwable $e) {
        $failed++;
        echo "[$type] ERROR: " . $e->getMessage() . "\n";
    }
}

echo "Done. sent=$sent failed=$failed\n";
exit($failed > 0 ? 1 : 0);
