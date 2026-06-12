<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api-init.php';

/** @var \PDO   $pdo */
/** @var array $user */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

requireApiPermission('edit_booking');

$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

if (!validateCsrfToken($body['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$action     = trim((string)($body['action'] ?? ''));
$booking_id = isset($body['booking_id']) ? (int)$body['booking_id'] : 0;

if ($booking_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid booking_id.']);
    exit;
}

if (!in_array($action, ['send_reminder', 'extend', 'expire_now', 'get_history'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT b.*, r.name AS room_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Booking not found.']);
        exit;
    }

    switch ($action) {

        // ── Send reminder email ───────────────────────────────────────────
        case 'send_reminder':
            if ($booking['status'] !== 'tentative' || !$booking['is_tentative']) {
                echo json_encode(['success' => false, 'error' => 'This is not an active tentative booking.']);
                exit;
            }

            require_once __DIR__ . '/../../config/email.php';
            $result = sendTentativeBookingReminderEmail($booking);

            if (!$result['success']) {
                echo json_encode(['success' => false, 'error' => 'Email failed: ' . ($result['message'] ?? 'Unknown error')]);
                exit;
            }

            $pdo->prepare("UPDATE bookings SET reminder_sent = 1, reminder_sent_at = NOW() WHERE id = ?")
                ->execute([$booking_id]);

            $pdo->prepare("
                INSERT INTO tentative_booking_log (booking_id, action, performed_by, action_reason)
                VALUES (?, 'reminder_sent', ?, ?)
            ")->execute([
                $booking_id,
                (int)$user['id'],
                'Manual reminder sent by ' . $user['full_name'],
            ]);

            echo json_encode([
                'success'    => true,
                'message'    => 'Reminder email sent to ' . htmlspecialchars($booking['guest_email'], ENT_QUOTES, 'UTF-8'),
                'sent_at'    => date('M j, H:i'),
            ]);
            break;

        // ── Extend tentative expiry ───────────────────────────────────────
        case 'extend':
            if ($booking['status'] !== 'tentative' || !$booking['is_tentative']) {
                echo json_encode(['success' => false, 'error' => 'This is not an active tentative booking.']);
                exit;
            }

            $hours = isset($body['hours']) ? max(1, min(168, (int)$body['hours'])) : 24;

            // Base the extension on current expiry (or NOW if already past)
            $base_ts        = max(time(), (int)strtotime((string)$booking['tentative_expires_at']));
            $new_expires_at = date('Y-m-d H:i:s', $base_ts + ($hours * 3600));

            $pdo->prepare("
                UPDATE bookings
                SET tentative_expires_at = ?,
                    reminder_sent        = 0,
                    reminder_sent_at     = NULL
                WHERE id = ?
            ")->execute([$new_expires_at, $booking_id]);

            $pdo->prepare("
                INSERT INTO tentative_booking_log
                    (booking_id, action, previous_expires_at, new_expires_at, performed_by, action_reason)
                VALUES (?, 'extended', ?, ?, ?, ?)
            ")->execute([
                $booking_id,
                $booking['tentative_expires_at'],
                $new_expires_at,
                (int)$user['id'],
                "Extended by {$hours}h by " . $user['full_name'],
            ]);

            echo json_encode([
                'success'              => true,
                'message'              => "Extended by {$hours}h — new expiry: " . date('M j, Y H:i', strtotime($new_expires_at)),
                'new_expires_at'       => $new_expires_at,
                'new_expires_formatted' => date('M j, Y · H:i', strtotime($new_expires_at)),
            ]);
            break;

        // ── Expire now (manual cleanup) ───────────────────────────────────
        case 'expire_now':
            if (!in_array($booking['status'], ['tentative', 'pending'], true)) {
                echo json_encode(['success' => false, 'error' => 'Booking cannot be expired from its current status.']);
                exit;
            }

            $pdo->beginTransaction();

            $pdo->prepare("
                UPDATE bookings
                SET status = 'expired', is_tentative = 0, expired_at = NOW()
                WHERE id = ?
            ")->execute([$booking_id]);

            $pdo->prepare("
                INSERT INTO tentative_booking_log (booking_id, action, performed_by, action_reason)
                VALUES (?, 'expired', ?, ?)
            ")->execute([
                $booking_id,
                (int)$user['id'],
                'Manually expired by ' . $user['full_name'],
            ]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Booking expired — room hold released.',
            ]);
            break;

        // ── Booking history log ───────────────────────────────────────────
        case 'get_history':
            $stmt = $pdo->prepare("
                SELECT action, action_reason, created_at
                FROM   tentative_booking_log
                WHERE  booking_id = ?
                ORDER  BY created_at ASC
            ");
            $stmt->execute([$booking_id]);
            $log = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'log' => $log]);
            break;
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('tentative-actions API PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('tentative-actions API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

