<?php
/**
 * Child process for scripts/tests/api_payment_delete.php - runs ONE DELETE /api/payments/{id} case
 * through the real endpoint code (api/payments.php) and prints one JSON line with the outcome.
 * Usage: php scripts/tests/api_payment_delete_child.php <case>
 *
 * The endpoint ends with exit(), so the result and the clean-up run in a shutdown function.
 * Refusal cases run inside a transaction that is rolled back (the endpoint refuses before it opens
 * its own). The one success case cannot (the endpoint opens its own transaction), so its fixture is
 * committed and every row it made is deleted again by id at shutdown.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/finance-sequences.php';
finance_ensure_sequence_tables($pdo);

// Stand-ins for what api/index.php provides: the router flag, an authenticated client, the response class.
define('API_ACCESS_ALLOWED', true);
$auth = new class {
    public function checkPermission($client, string $perm): bool { return true; }
};
$client = ['id' => 0, 'client_name' => 'linked test'];
$GLOBALS['rh_api_client'] = $client;
final class ApiResponse {
    public static function success($data = null, $message = 'Success', $code = 200) { $GLOBALS['rh_test_resp'] = [(int)$code, (string)$message]; exit; }
    public static function error(string $message, $code = 400, $details = null) { $GLOBALS['rh_test_resp'] = [(int)$code, $message]; exit; }
    public static function validationError($errors) { $GLOBALS['rh_test_resp'] = [422, json_encode($errors)]; exit; }
}

$case = (string)($argv[1] ?? '');
$run = strtoupper(bin2hex(random_bytes(3)));
$fx = ['booking' => 0, 'payment' => 0, 'committed' => false];

function child_fixture(PDO $pdo, string $run, array $pay, array &$fx): array {
    $type = (int)$pdo->query("SELECT id FROM rooms ORDER BY id LIMIT 1")->fetchColumn();
    $ref = 'AD' . $run;
    $in = date('Y-m-d', strtotime('+420 day')); $out = date('Y-m-d', strtotime('+421 day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,'API DELETE TEST','linked-test@example.invalid','+265000000009','Malawi',2,2,0,50,?,?,1,100,0,0,0,0,0,100,'api delete test','pending','unpaid',0,'double',?,NOW())")
        ->execute([$ref, $type, $in, $out, bin2hex(random_bytes(16))]);
    $bid = (int)$pdo->lastInsertId();
    $fx['booking'] = $bid; // recorded before the payment insert so a failure there cannot leak the booking
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, created_at)
        VALUES (?, ?, ?, ?, CURDATE(), 50, 0, 0, 50, ?, 'deposit', ?, ?, ?, NOW())")
        ->execute(['PAD' . $run, $pay['type'] ?? 'room', $bid, $ref, $pay['method'] ?? 'mobile_money', $pay['status'], $pay['status'] === 'completed' ? 'completed' : 'pending', $pay['receipt'] ?? null]);
    return [$bid, (int)$pdo->lastInsertId()];
}

register_shutdown_function(function () use ($pdo, &$fx) {
    $resp = $GLOBALS['rh_test_resp'] ?? [0, 'no response'];
    $state = ['deleted' => false, 'audit' => 0, 'timeline' => 0];
    try {
        if ($fx['payment']) {
            $s = $pdo->prepare("SELECT deleted_at FROM payments WHERE id = ?"); $s->execute([$fx['payment']]);
            $state['deleted'] = $s->fetchColumn() !== null;
            $s = $pdo->prepare("SELECT COUNT(*) FROM payment_audit_log WHERE payment_id = ? AND action = 'deleted'"); $s->execute([$fx['payment']]);
            $state['audit'] = (int)$s->fetchColumn();
            $s = $pdo->prepare("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_id = ? AND action = 'Pending payment deleted'"); $s->execute([$fx['booking']]);
            $state['timeline'] = (int)$s->fetchColumn();
        }
    } catch (Throwable $e) {
        $state['error'] = $e->getMessage();
    }
    $leak = false;
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        } elseif ($fx['committed']) {
            // Remove exactly what this case made (booking children cascade with the booking).
            $pdo->prepare("DELETE FROM payment_audit_log WHERE payment_id = ?")->execute([$fx['payment']]);
            $pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$fx['payment']]);
            $pdo->prepare("DELETE FROM booking_timeline_logs WHERE booking_id = ?")->execute([$fx['booking']]);
            $pdo->prepare("DELETE FROM bookings WHERE id = ?")->execute([$fx['booking']]);
        }
        $s = $pdo->prepare("SELECT (SELECT COUNT(*) FROM bookings WHERE id = ?) + (SELECT COUNT(*) FROM payments WHERE id = ?)");
        $s->execute([$fx['booking'], $fx['payment']]);
        $leak = (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) {
        $leak = true;
    }
    echo json_encode(['code' => $resp[0], 'message' => $resp[1], 'state' => $state, 'leak' => $leak]), "\n";
});

$cases = [
    'completed'  => ['status' => 'completed', 'method' => 'cash'],
    'receipted'  => ['status' => 'pending', 'receipt' => 'RCPT-TEST-1'],
    'creditnote' => ['status' => 'completed', 'method' => 'credit_note'],
    'restaurant' => ['status' => 'pending', 'type' => 'restaurant'],
    'draft'      => ['status' => 'pending'],
];
if (!isset($cases[$case])) { $GLOBALS['rh_test_resp'] = [0, 'unknown case']; exit(1); }

if ($case === 'draft') {
    [$fx['booking'], $fx['payment']] = child_fixture($pdo, $run, $cases[$case], $fx);
    $fx['committed'] = true;
} else {
    $pdo->beginTransaction();
    [$fx['booking'], $fx['payment']] = child_fixture($pdo, $run, $cases[$case], $fx);
}

$_SERVER['REQUEST_METHOD'] = 'DELETE';
$_SERVER['REQUEST_URI'] = '/api/payments/' . $fx['payment'] . '?reason=' . rawurlencode('linked test');
$_GET['reason'] = 'linked test';
require __DIR__ . '/../../api/payments.php';
