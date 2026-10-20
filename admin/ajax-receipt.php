<?php

/**
 * AJAX endpoint for sending receipts for hotel/conference payments.
 *
 * POST params:
 *   csrf_token  - required
 *   payment_id  - required (int)
 *   action      - 'email' | 'whatsapp'
 *   recipient   - email address (for email) or phone number (for whatsapp)
 *
 * Always returns JSON: {ok: bool, message: string} or {ok: false, error: string}
 */
require_once 'admin-init.php';
require_once '../config/receipts.php';
require_once '../includes/whatsapp-functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Security token invalid. Refresh and try again.']);
    exit;
}

$paymentId = (int)($_POST['payment_id'] ?? 0);
$action    = trim((string)($_POST['action'] ?? ''));
$recipient = trim((string)($_POST['recipient'] ?? ''));

if ($paymentId <= 0 && $action !== 'account_receipts') {
    echo json_encode(['ok' => false, 'error' => 'Payment ID required.']);
    exit;
}

try {
    receipt_ensure_schema($pdo);

    if ($action === 'account_receipts') {
        // Receipts of one account (room / conference / gym / event), newest first, for the "Send receipt" action.
        $acctType = (string)($_POST['booking_type'] ?? '');
        $acctId = (int)($_POST['booking_id'] ?? 0);
        if (!in_array($acctType, ['room', 'conference', 'gym', 'event'], true) || $acctId <= 0) {
            throw new RuntimeException('Account required.');
        }
        $st = $pdo->prepare("SELECT id, payment_reference, receipt_number, total_amount, payment_date, payment_method, receipt_emailed_at
            FROM payments WHERE booking_type = ? AND booking_id = ? AND deleted_at IS NULL
              AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund'
            ORDER BY payment_date DESC, id DESC");
        $st->execute([$acctType, $acctId]);
        echo json_encode(['ok' => true, 'payments' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } elseif ($action === 'email_guest') {
        // Re-send a receipt to the address on the account (a manual re-send is always allowed).
        $result = receipt_send_email($pdo, $paymentId, null, $user);
        echo json_encode(['ok' => true, 'message' => $result['message'] ?? 'Receipt emailed.']);
    } elseif ($action === 'email') {
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email address is required.');
        }
        $result = receipt_send_email($pdo, $paymentId, $recipient, $user);
        echo json_encode(['ok' => true, 'message' => $result['message'] ?? 'Receipt emailed.']);
    } elseif ($action === 'whatsapp') {
        $waResult = receipt_whatsapp_message($pdo, $paymentId);
        // Open the wa.me URL on the client side
        echo json_encode([
            'ok'      => true,
            'message' => 'Opening WhatsApp…',
            'url'     => $waResult['url'] ?? '',
        ]);
    } else {
        throw new RuntimeException('Unknown action.');
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
