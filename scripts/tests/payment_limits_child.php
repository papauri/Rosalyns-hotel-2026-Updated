<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/** In-process scenarios for scripts/tests/payment_limits.php (run by page_harness.php, rolled back). */
$sc = (string)($_POST['scenario'] ?? '');
$prm = json_decode((string)($_POST['params'] ?? '{}'), true) ?: [];
require_once $root . '/config/email.php';
require_once $root . '/config/receipts.php';
require_once $root . '/config/credit-notes.php';
require_once $root . '/config/invoice.php';
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/admin/includes/account-record-payment.php';

function pl_id(): int { return random_int(2000000000, 2100000000); }
function pl_booking(PDO $pdo, string $status = 'confirmed'): array {
    $id = pl_id(); $ref = 'PLA' . random_int(10000, 99999);
    $in = date('Y-m-d', strtotime('+400 day')); $outd = date('Y-m-d', strtotime('+401 day'));
    $pdo->prepare("INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'LIMITS TEST','','+265000000009','Malawi',2,2,0,50,?,?,1,100,0,0,0,0,0,100,'limits test',?,'unpaid',0,'double',?,NOW())")
        ->execute([$id, $ref, $in, $outd, $status, bin2hex(random_bytes(16))]);
    return [$id, $ref];
}
function pl_pay(PDO $pdo, string $type, int $bid, string $ref, float $amt): void {
    $pdo->prepare("INSERT INTO payments (id, payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, created_at)
        VALUES (?,?,?,?,?,CURDATE(),?,0,0,?,'cash','partial_payment','completed','completed',?,NOW())")
        ->execute([pl_id(), 'PLP' . random_int(100000, 999999), $type, $bid, $ref, $amt, $amt, 'RPL' . random_int(100000, 999999)]);
}
function pl_account(PDO $pdo, string $type): array {
    $id = pl_id(); $ref = 'PLA' . random_int(10000, 99999);
    if ($type === 'gym') {
        $pdo->prepare("INSERT INTO gym_inquiries (id, reference_number, name, email, phone, membership_type, status, total_amount, total_with_vat, amount_due, created_at) VALUES (?,?,?,?,?,?,'confirmed',100,100,100,NOW())")->execute([$id, $ref, 'LIMITS GYM', '', '+265000000009', 'Monthly']);
    } elseif ($type === 'event') {
        $pdo->prepare("INSERT INTO event_inquiries (id, reference_number, event_id, name, email, phone, status, total_amount, total_with_vat, amount_due, created_at) VALUES (?,?,0,?,?,?,'confirmed',100,100,100,NOW())")->execute([$id, $ref, 'LIMITS EVENT', '', '+265000000009']);
    } else {
        $pdo->prepare("INSERT INTO conference_inquiries (id, inquiry_reference, conference_room_id, company_name, contact_person, email, phone, event_date, start_time, end_time, number_of_attendees, status, total_amount, total_with_vat, amount_due, created_at) VALUES (?,?,0,?,?,?,?,?, '09:00:00','17:00:00',10,'confirmed',100,100,100,NOW())")->execute([$id, $ref, 'LIMITS CO', 'LIMITS PERSON', '', '+265000000009', date('Y-m-d', strtotime('+400 day'))]);
    }
    return [$id, $ref];
}
$admin = $pdo->query("SELECT id, full_name FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$out = ['scenario' => $sc];

switch ($sc) {
    case 'account':
        $t = (string)$prm['type'];
        $rec = function (int $id, string $amt, bool $tick) use ($pdo, $t, $admin) {
            return rh_record_account_payment($pdo, $t, $id, ['method' => 'cash', 'amount' => $amt, 'transaction_reference' => '', 'notes' => '', 'confirm_overpay' => $tick, 'client_uuid' => null], (int)$admin['id'], (string)$admin['full_name']);
        };
        [$a] = pl_account($pdo, $t);
        $r = $rec($a, '100', false); $out['exact_ok'] = $r['balance_after'] < 0.01; $out['exact_cn'] = $r['credit_note_id'];
        try { $rec($a, '5', true); $out['nothing_owed'] = 'NOT REFUSED'; } catch (Exception $e) { $out['nothing_owed'] = $e->getMessage(); }
        [$b] = pl_account($pdo, $t);
        try { $rec($b, '130', false); $out['over_refused'] = ''; } catch (Exception $e) { $out['over_refused'] = $e->getMessage(); }
        $r = $rec($b, '130', true); $out['over_ok'] = $r['amount'] > 129; $out['over_cn'] = $r['credit_note_id'];
        // a settled account is not sent an invoice
        $fn = $t === 'gym' ? 'sendGymInvoiceEmail' : ($t === 'event' ? 'sendEventInvoiceEmail' : 'sendConferenceInvoiceEmail');
        $inv = $fn($a);
        $out['invoice_msg'] = (string)($inv['message'] ?? '');
        break;

    case 'room_invoice':
        [$paid, $pref] = pl_booking($pdo); pl_pay($pdo, 'room', $paid, $pref, 100.0); recalculateBookingFinancials($paid);
        [$owed] = pl_booking($pdo); recalculateBookingFinancials($owed);
        $inv = sendPaymentInvoiceEmail($paid);
        $out['paid_msg'] = (string)($inv['message'] ?? '');
        $out['owed_refusal'] = (string)(rh_invoice_paid_refusal($pdo, 'room', $owed) ?? '');
        $out['states'] = [rh_doc_state(0, 50, 'confirmed'), rh_doc_state(100, 0, 'confirmed'), rh_doc_state(0, 0, 'confirmed')];
        break;

    case 'api':
        define('API_ACCESS_ALLOWED', true);
        $auth = new class { public function checkPermission($client, string $perm): bool { return true; } };
        $client = ['id' => 0, 'client_name' => 'limits test'];
        $GLOBALS['rh_api_client'] = $client;
        final class ApiResponse {
            public static function success($data = null, $message = 'Success', $code = 200) { exit; }
            public static function error(string $message, $code = 400, $details = null) { echo json_encode(['api_error' => $message, 'api_code' => $code]); exit; }
            public static function validationError($errors) { echo json_encode(['api_error' => json_encode($errors), 'api_code' => 422]); exit; }
        }
        [$bid, $bref] = pl_booking($pdo);
        $mode = (string)$prm['mode'];
        if ($mode === 'nothing_owed') { pl_pay($pdo, 'room', $bid, $bref, 100.0); }
        $amt = in_array($mode, ['over', 'over_allowed'], true) ? 120 : ($mode === 'exact' ? 100 : 10);
        $body = ['booking_type' => 'room', 'booking_id' => $bid, 'payment_amount' => $amt, 'payment_method' => 'cash', 'payment_status' => 'completed', 'payment_date' => date('Y-m-d')];
        if ($mode === 'over_allowed') { $body['allow_overpayment'] = true; }
        $GLOBALS['rh_test_raw_input'] = json_encode($body);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/payments';
        $_SERVER['SCRIPT_NAME'] = '/api/payments.php';
        require $root . '/api/payments.php';
        break;
}
if (!defined('API_ACCESS_ALLOWED')) { echo json_encode($out); }
