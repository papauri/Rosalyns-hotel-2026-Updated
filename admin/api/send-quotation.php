<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../../config/email.php';
require_once __DIR__ . '/../../includes/quotation-pdf.php';

/** @var PDO   $pdo */
/** @var array $user */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

requireApiPermission('create_booking');

// Parse JSON body
$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

// CSRF
if (!validateCsrfToken($body['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$booking_id = isset($body['booking_id']) ? (int)$body['booking_id'] : 0;
$valid_days = isset($body['valid_days']) ? max(1, (int)$body['valid_days']) : 7;
$notes      = trim((string)($body['quotation_notes'] ?? ''));

if ($booking_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'booking_id is required.']);
    exit;
}

try {
    // Fetch booking (any status — quotations can be sent for any booking)
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Booking not found.']);
        exit;
    }

    // Send email
    $result = sendTentativeQuotationEmail($booking, [
        'valid_days'       => $valid_days,
        'quotation_notes'  => $notes,
    ]);

    if (!$result['success']) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $result['message'] ?? 'Failed to send email.']);
        exit;
    }

    // Stamp last_quotation_sent_at
    $upd = $pdo->prepare("UPDATE bookings SET last_quotation_sent_at = NOW() WHERE id = ?");
    $upd->execute([$booking_id]);

    // ── Generate PDF and save to disk ────────────────────────────────────────
    $quoteRef       = 'QT-' . strtoupper((string)($booking['booking_reference'] ?? $booking_id));
    $quotationsDir  = dirname(__DIR__, 2) . '/quotations';
    if (!is_dir($quotationsDir)) {
        mkdir($quotationsDir, 0755, true);
    }
    $pdfFilename    = $quoteRef . '-' . date('Ymd') . '.pdf';
    $pdfAbsPath     = $quotationsDir . '/' . $pdfFilename;
    $pdfRelPath     = 'quotations/' . $pdfFilename;

    $savedPdfPath   = null;
    try {
        $roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ? LIMIT 1");
        $roomStmt->execute([$booking['room_id'] ?? 0]);
        $room     = $roomStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $pdfBinary = generateQuotationPDF($booking, $room, [
            'valid_days'      => $valid_days,
            'quotation_notes' => $notes,
        ]);
        if (file_put_contents($pdfAbsPath, $pdfBinary) !== false) {
            $savedPdfPath = $pdfRelPath;
        }
    } catch (Throwable $pdfEx) {
        error_log('send-quotation PDF save error: ' . $pdfEx->getMessage());
        // Non-fatal — email was already sent
    }

    // ── Insert record into quotations table ──────────────────────────────────
    $roomNameForLog = '';
    if (!empty($room['name'])) {
        $roomNameForLog = $room['name'];
    } elseif (!empty($booking['room_name'])) {
        $roomNameForLog = $booking['room_name'];
    }
    $insStmt = $pdo->prepare("
        INSERT INTO quotations
            (booking_id, booking_type, quote_reference, booking_reference, guest_name,
             guest_email, room_name, total_amount, valid_days, valid_until,
             quotation_notes, pdf_path, status, sent_at, sent_by)
        VALUES (?, 'room', ?, ?, ?, ?, ?, ?, ?,
                DATE_ADD(CURDATE(), INTERVAL ? DAY),
                ?, ?, 'sent', NOW(), ?)
    ");
    $insStmt->execute([
        $booking_id,
        $quoteRef,
        $booking['booking_reference'] ?? '',
        $booking['guest_name']        ?? '',
        $booking['guest_email']       ?? '',
        $roomNameForLog,
        (float)($booking['total_amount'] ?? 0),
        $valid_days,
        $valid_days,
        $notes !== '' ? $notes : null,
        $savedPdfPath,
        (int)($user['id'] ?? 0),
    ]);

    // Log to tentative_booking_log
    $log = $pdo->prepare(
        "INSERT INTO tentative_booking_log (booking_id, action, performed_by, action_reason)
         VALUES (?, 'quotation_sent', ?, ?)"
    );
    $log->execute([
        $booking_id,
        (int)($user['id'] ?? 0),
        $notes !== '' ? 'Quotation sent with note: ' . $notes : 'Quotation sent',
    ]);

    rh_log_event('send-quotation', 'info', 'Quotation emailed for booking #' . $booking_id, [
        'booking_reference' => $booking['booking_reference'],
        'guest_email'       => $booking['guest_email'],
        'valid_days'        => $valid_days,
        'by'                => $user['username'] ?? '',
    ]);

    echo json_encode([
        'success'   => true,
        'message'   => 'Quotation sent to ' . $booking['guest_email'],
        'pdf_saved' => $savedPdfPath !== null,
    ]);
} catch (PDOException $e) {
    $errMsg = $e->getMessage();
    error_log('send-quotation PDO error: ' . $errMsg);
    if (function_exists('rh_log_event')) {
        rh_log_event('send-quotation', 'error', 'PDO error: ' . $errMsg, [
            'booking_id' => $booking_id ?? 0,
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    $errMsg = $e->getMessage();
    error_log('send-quotation error: ' . $errMsg . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (function_exists('rh_log_event')) {
        rh_log_event('send-quotation', 'error', 'Exception: ' . $errMsg, [
            'booking_id' => $booking_id ?? 0,
            'file'       => $e->getFile() . ':' . $e->getLine(),
            'trace'      => substr($e->getTraceAsString(), 0, 800),
        ]);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to send quotation right now. Please try again.']);
}

