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

if ($paymentId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Payment ID required.']);
    exit;
}

try {
    receipt_ensure_schema($pdo);

    if ($action === 'email') {
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
