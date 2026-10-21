<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/documents_flow.php. NOT run on its own: it is "the page" that
 * scripts/tests/page_harness.php executes (one outer transaction that can never commit, SMTP pointed at the
 * capture-only server). The scenario name and parameters arrive in $_POST. Output is one JSON object (or, for
 * the API scenarios that end in exit(), the runner reads the database through the harness verify queries).
 */
$sc = (string)($_POST['scenario'] ?? '');
$prm = json_decode((string)($_POST['params'] ?? '{}'), true) ?: [];

require_once $root . '/config/email.php';
require_once $root . '/config/receipts.php';
require_once $root . '/config/credit-notes.php';
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/admin/includes/account-record-payment.php';
require_once $root . '/admin/includes/restaurant-payment-sync.php';

$run = strtoupper(bin2hex(random_bytes(3)));
$out = ['scenario' => $sc];

function dx_id(): int { return random_int(2000000000, 2100000000); }
function dx_room_type(PDO $pdo): int { return (int)$pdo->query("SELECT id FROM rooms ORDER BY id LIMIT 1")->fetchColumn(); }
function dx_booking(PDO $pdo, string $run, string $email, string $status = 'confirmed'): array {
    $id = dx_id();
    $ref = 'DX' . $run . random_int(10, 99);
    $in = date('Y-m-d', strtotime('+400 day')); $outd = date('Y-m-d', strtotime('+401 day'));
    $pdo->prepare("INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,?,?,?,?,?,2,2,0,50,?,?,1,100,0,0,0,0,0,100,'documents test',?,'unpaid',0,'double',?,NOW())")
        ->execute([$id, $ref, dx_room_type($pdo), 'DOCS TEST', $email, '+265000000009', 'Malawi', $in, $outd, $status, bin2hex(random_bytes(16))]);
    return [$id, $ref];
}
function dx_payment(PDO $pdo, string $run, string $type, int $bookingId, string $ref, string $status, float $amount = 40.0, ?string $receipt = null): int {
    $id = dx_id();
    $pdo->prepare("INSERT INTO payments (id, payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, created_at)
        VALUES (?,?,?,?,?,CURDATE(),?,0,0,?, 'cash','partial_payment',?,?,?,NOW())")
        ->execute([$id, 'PDX' . $run . random_int(100, 999), $type, $bookingId, $ref, $amount, $amount, $status, $status === 'completed' ? 'completed' : 'pending', $receipt]);
    return $id;
}
function dx_account(PDO $pdo, string $run, string $type, string $email): array {
    $id = dx_id();
    $ref = strtoupper(substr($type, 0, 1)) . 'X' . $run . random_int(10, 99);
    if ($type === 'gym') {
        $pdo->prepare("INSERT INTO gym_inquiries (id, reference_number, name, email, phone, membership_type, status, total_amount, total_with_vat, amount_due, created_at)
            VALUES (?,?,?,?,?,?,'confirmed',100,100,100,NOW())")->execute([$id, $ref, 'DOCS GYM', $email, '+265000000009', 'Monthly']);
    } elseif ($type === 'event') {
        $pdo->prepare("INSERT INTO event_inquiries (id, reference_number, event_id, name, email, phone, status, total_amount, total_with_vat, amount_due, created_at)
            VALUES (?,?,0,?,?,?,'confirmed',100,100,100,NOW())")->execute([$id, $ref, 'DOCS EVENT', $email, '+265000000009']);
    } else {
        $pdo->prepare("INSERT INTO conference_inquiries (id, inquiry_reference, conference_room_id, company_name, contact_person, email, phone, event_date, start_time, end_time, number_of_attendees, status, total_amount, total_with_vat, amount_due, created_at)
            VALUES (?,?,0,?,?,?,?,?, '09:00:00','17:00:00',10,'confirmed',100,100,100,NOW())")->execute([$id, $ref, 'DOCS CO', 'DOCS PERSON', $email, '+265000000009', date('Y-m-d', strtotime('+400 day'))]);
    }
    return [$id, $ref];
}
function dx_user(PDO $pdo): array {
    $a = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return ['id' => (int)$a['id'], 'username' => $a['username'], 'full_name' => $a['full_name'], 'role' => 'admin'];
}
$user = dx_user($pdo);

switch ($sc) {
    case 'account': // gym | event | conference: Record payment helper (the shared modal's server side)
        $type = (string)$prm['type'];
        [$aid, $aref] = dx_account($pdo, $run, $type, (string)($prm['email'] ?? 'docs-test@example.invalid'));
        $res = rh_record_account_payment($pdo, $type, $aid, ['method' => 'cash', 'amount' => (string)($prm['amount'] ?? ''), 'transaction_reference' => '', 'notes' => '', 'confirm_overpay' => false, 'client_uuid' => null], $user['id'], (string)$user['full_name']);
        $sender = $type === 'gym' ? 'sendGymInvoiceEmail' : ($type === 'event' ? 'sendEventInvoiceEmail' : 'sendConferenceInvoiceEmail');
        if ($type === 'gym' || $type === 'event' || $type === 'conference') { require_once $root . '/config/invoice.php'; }
        $out['message'] = rh_account_after_payment_messages($pdo, $res, !empty($prm['want_invoice']), $sender, $aid, $user, 'MWK ');
        $out['balance_after'] = $res['balance_after'];
        $out['payment_id'] = $res['payment_id'];
        break;

    case 'dedupe': // a receipt is emailed once however many paths ask for it
        [$bid, $bref] = dx_booking($pdo, $run, 'docs-test@example.invalid');
        $pid = dx_payment($pdo, $run, 'room', $bid, $bref, 'completed', 40.0, 'RDX' . $run);
        $r1 = receipt_auto_send($pdo, $pid, $user);
        $r2 = receipt_auto_send($pdo, $pid, $user);
        $r3 = receipt_after_payment($pdo, $pid, $user);
        $out += ['first' => $r1['reason'] ?? '', 'second' => $r2['reason'] ?? '', 'third' => $r3['result']['reason'] ?? '', 'third_notice' => $r3['notice']];
        break;

    case 'pending_no_receipt': // pending money is not received money
        [$bid, $bref] = dx_booking($pdo, $run, 'docs-test@example.invalid');
        $pid = dx_payment($pdo, $run, 'room', $bid, $bref, 'pending', 40.0);
        $r = receipt_after_payment($pdo, $pid, $user);
        $out += ['reason' => $r['result']['reason'] ?? '', 'notice' => $r['notice']];
        break;

    case 'reason': // why a receipt can not go out, and what staff are told
        $why = (string)$prm['why'];
        $email = $why === 'no_email' ? '' : ($why === 'bad_email' ? 'not-an-email' : 'docs-test@example.invalid');
        if ($why === 'template_off') {
            $pdo->exec("UPDATE booking_email_templates SET is_active = 0 WHERE template_key = 'payment_receipt'");
        }
        if ($why === 'no_smtp') {
            $pdo->exec("UPDATE email_settings SET setting_value = '' WHERE setting_key = 'smtp_host'");
        }
        [$bid, $bref] = dx_booking($pdo, $run, $email);
        $pid = dx_payment($pdo, $run, 'room', $bid, $bref, 'completed', 40.0, 'RDX' . $run);
        $r = receipt_after_payment($pdo, $pid, $user);
        $out += ['reason' => $r['result']['reason'] ?? '', 'notice' => $r['notice'], 'sent' => $r['sent']];
        break;

    case 'restaurant': // till sale with / without a customer email
        $id = dx_id();
        $withMail = !empty($prm['email']);
        $pdo->prepare("INSERT INTO stock_orders (id, reference, client_uuid, order_type, customer_name, customer_email, status, total_amount, subtotal, created_by, created_at)
            VALUES (?,?,?,'dine_in','DOCS DINER',?, 'paid', 50, 50, ?, NOW())")
            ->execute([$id, 'DXR' . $run, bin2hex(random_bytes(16)), $withMail ? 'docs-test@example.invalid' : null, $user['id']]);
        $GLOBALS['rh_receipt_queue'] = [];
        $payId = rh_sync_restaurant_payment($pdo, $id, 'DXR' . $run, 'DOCS DINER', ['net' => 50, 'vat_rate' => 0, 'vat' => 0, 'gross' => 50], $user['id'], 'cash');
        $out['queued'] = isset($GLOBALS['rh_receipt_queue'][$payId]);
        // The queue is flushed at the end of a real request (after the till commits); do the same here.
        foreach (array_keys($GLOBALS['rh_receipt_queue']) as $qid) {
            $r = receipt_auto_send($pdo, (int)$qid, null);
            $out['flush_reason'] = $r['reason'] ?? '';
        }
        // One receipt number per sale: the payment's RCP number. The till slip shows it as "Receipt #" and the
        // order reference separately as "Order #"; the payment's PDF exists even for a walk-in (no email).
        $pr = $pdo->prepare('SELECT receipt_number, receipt_generated, receipt_path FROM payments WHERE id = ?');
        $pr->execute([$payId]);
        $prow = $pr->fetch(PDO::FETCH_ASSOC) ?: [];
        $od = receipt_load_restaurant_order($pdo, $id);
        $slip = $od ? receipt_build_restaurant_email_html($od['order'], $od['items'], $od['ctx']) : '';
        $pdfHtml = $od ? receipt_render_restaurant_pdf_html($od['order'], $od['items'], $od['ctx']) : '';
        $out['rcp'] = (string)($prow['receipt_number'] ?? '');
        $out['pdf_generated'] = !empty($prow['receipt_generated']) && !empty($prow['receipt_path']) && is_file($root . '/' . $prow['receipt_path']);
        $out['slip_has_rcp'] = $out['rcp'] !== '' && strpos($slip, $out['rcp']) !== false;
        $out['slip_has_order_label'] = strpos($slip, 'Order #') !== false && strpos($slip, 'DXR' . $run) !== false;
        $out['slip_receipt_not_order_ref'] = !preg_match('~Receipt #</strong></td><td[^>]*>DXR~', $slip);
        $out['pdf_has_rcp'] = $out['rcp'] !== '' && strpos($pdfHtml, $out['rcp']) !== false;
        // The rows roll back; remove the PDF the test wrote so no stale file is left under that number.
        if (!empty($prow['receipt_path'])) { @unlink($root . '/' . $prow['receipt_path']); }
        break;

    case 'restaurant_split': // split bill, two legs, order with / without a customer email: one receipt per leg
        $id = dx_id();
        $withMail = !empty($prm['email']);
        $pdo->prepare("INSERT INTO stock_orders (id, reference, client_uuid, order_type, customer_name, customer_email, status, total_amount, subtotal, created_by, created_at)
            VALUES (?,?,?,'dine_in','DOCS DINER',?, 'paid', 80, 80, ?, NOW())")
            ->execute([$id, 'DXS' . $run, bin2hex(random_bytes(16)), $withMail ? 'docs-test@example.invalid' : null, $user['id']]);
        $GLOBALS['rh_receipt_queue'] = [];
        $legIds = [];
        foreach ([1, 2] as $leg) {
            $legIds[] = rh_record_restaurant_split_leg_payment($pdo, $id, 'DXS' . $run, 'DOCS DINER', ['net' => 40, 'vat_rate' => 0, 'vat' => 0, 'gross' => 40], $user['id'], 'cash', $leg, $leg === 2);
        }
        $out['queued'] = count(array_filter($legIds, function ($p) { return isset($GLOBALS['rh_receipt_queue'][$p]); }));
        foreach (array_keys($GLOBALS['rh_receipt_queue']) as $qid) {
            receipt_auto_send($pdo, (int)$qid, null);
            receipt_auto_send($pdo, (int)$qid, null); // the once-only rule: a second ask sends nothing
        }
        // Every leg has its own RCP and its own PDF; the combined slip lists them and names the order.
        $pr = $pdo->prepare('SELECT receipt_number, receipt_generated, receipt_path FROM payments WHERE id = ?');
        $legPdfs = 0; $legNums = []; $legPaths = [];
        foreach ($legIds as $lid) {
            $pr->execute([$lid]);
            $prow = $pr->fetch(PDO::FETCH_ASSOC) ?: [];
            $legNums[] = (string)($prow['receipt_number'] ?? '');
            if (!empty($prow['receipt_generated']) && !empty($prow['receipt_path']) && is_file($root . '/' . $prow['receipt_path'])) { $legPdfs++; }
            if (!empty($prow['receipt_path'])) { $legPaths[] = $root . '/' . $prow['receipt_path']; }
        }
        $od = receipt_load_restaurant_order($pdo, $id);
        $slip = $od ? receipt_build_restaurant_email_html($od['order'], $od['items'], $od['ctx']) : '';
        $out['leg_pdfs'] = $legPdfs;
        foreach ($legPaths as $lp) { @unlink($lp); } // rows roll back; leave no stale PDFs
        $out['leg_numbers_distinct'] = count(array_filter($legNums)) === 2 && count(array_unique($legNums)) === 2;
        $out['slip_has_all_legs'] = count(array_filter($legNums, function ($n) use ($slip) { return $n !== '' && strpos($slip, $n) !== false; })) === 2;
        $out['slip_has_order_label'] = strpos($slip, 'Order #') !== false && strpos($slip, 'DXS' . $run) !== false;
        break;

    case 'statement_pdf': // paid-in-full statement vs balance-owed invoice: titles, wording and the shared invoice number
        require_once $root . '/config/invoice.php';
        [$bid, $bref] = dx_booking($pdo, $run, 'docs-test@example.invalid', 'checked-out');
        if (!empty($prm['paid'])) {
            dx_payment($pdo, $run, 'room', $bid, $bref, 'completed', 100.0, 'RDXS' . $run);
        }
        recalculateBookingFinancials($bid);
        $stmt = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.id = ?");
        $stmt->execute([$bid]);
        $bk = $stmt->fetch(PDO::FETCH_ASSOC);
        $html = buildInvoiceHTML($bk, 'INV-DXTEST-1', 'Test', 'a@b.c', '1', 'x', 'MWK', [], null, !empty($prm['paid']));
        $out["has_stmt_title"] = stripos($html, "Stay Statement") !== false || stripos($html, "STAY STATEMENT") !== false;
        $out['has_invoice_no'] = stripos($html, 'Invoice No.') !== false;
        $out['has_balance_due'] = stripos($html, 'Balance due') !== false;
        $out['has_stmt_no'] = stripos($html, 'Statement No.') !== false;
        $out['has_invoice_title'] = strpos($html, 'INVOICE') !== false; // the document title is printed in capitals
        $res = generateInvoicePDF($bid, 'INV-DXTEST-2', !empty($prm['paid']));
        $out['pdf_ok'] = is_array($res) && is_file($res['filepath']);
        if ($out['pdf_ok']) {
            $raw = (string)file_get_contents($res['filepath']);
            $out['pdf_file_is_invoice_no'] = basename($res['filepath']) === 'INV-DXTEST-2.pdf';
            // TCPDF writes the document title in the info dictionary as UTF-16BE (hex or raw) or plain text
            $needle16 = mb_convert_encoding('Stay Statement', 'UTF-16BE');
            $out['pdf_title_statement'] = strpos($raw, 'Stay Statement') !== false || strpos($raw, $needle16) !== false || strpos($raw, bin2hex($needle16)) !== false || stripos($raw, strtoupper(bin2hex($needle16))) !== false;
            @unlink($res['filepath']);
        }
        break;

    case 'api_create':
    case 'api_update':
        define('API_ACCESS_ALLOWED', true);
        $auth = new class { public function checkPermission($client, string $perm): bool { return true; } };
        $client = ['id' => 0, 'client_name' => 'documents test'];
        $GLOBALS['rh_api_client'] = $client;
        final class ApiResponse {
            public static function success($data = null, $message = 'Success', $code = 200) { exit; }
            public static function error(string $message, $code = 400, $details = null) { exit; }
            public static function validationError($errors) { exit; }
        }
        [$bid, $bref] = dx_booking($pdo, $run, 'docs-test@example.invalid');
        if ($sc === 'api_create') {
            $GLOBALS['rh_test_raw_input'] = json_encode(['booking_type' => 'room', 'booking_id' => $bid, 'payment_amount' => 30, 'payment_method' => 'cash', 'payment_status' => $prm['status'] ?? 'completed', 'payment_date' => date('Y-m-d')]);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/api/payments';
        } else {
            $pid = dx_payment($pdo, $run, 'room', $bid, $bref, 'pending', 30.0);
            $GLOBALS['rh_test_raw_input'] = json_encode(['payment_status' => 'completed']);
            $_SERVER['REQUEST_METHOD'] = 'PUT';
            $_SERVER['REQUEST_URI'] = '/api/payments/' . $pid;
        }
        $_SERVER['SCRIPT_NAME'] = '/api/payments.php';
        // The endpoint ends with exit(); the harness verify queries then read the result and roll everything back.
        require $root . '/api/payments.php';
        break;

    default:
        $out['error'] = 'unknown scenario';
}
if (!defined('API_ACCESS_ALLOWED')) {
    echo json_encode($out);
}
