<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var PDO $pdo */
/** @var array $user */

header('Content-Type: application/json');

require_once '../config/email.php';
require_once '../includes/room-management.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token invalid. Refresh the page.']);
    exit;
}

$action = $_POST['action'];
$booking_id = (int)($_POST['booking_id'] ?? 0);
$admin_user_id = (int)($user['id'] ?? ($_SESSION['admin_user_id'] ?? 0));
$bookingScopedActions = ['checkin', 'cancel_checkin', 'checkout', 'cancel'];

if (in_array($action, $bookingScopedActions, true) && $booking_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit;
}

try {
    if ($booking_id > 0) {
        $delChk = $pdo->prepare("SELECT deleted_at FROM bookings WHERE id = ?");
        $delChk->execute([$booking_id]);
        if (!empty($delChk->fetchColumn())) {
            echo json_encode(['success' => false, 'message' => 'This booking has been deleted. Restore it before making changes.']);
            exit;
        }
    }
    if ($action === 'checkin') {
        $roomGate = evaluateCheckInRoomReady($booking_id, $admin_user_id ?: null, !empty($_POST['confirm_checkin_room_not_ready']));
        if (!$roomGate['allowed']) {
            echo json_encode(['success' => false, 'message' => $roomGate['message'], 'needs_confirm_room' => $roomGate['needs_confirm']]);
            exit;
        }
        // One rule everywhere (validateCheckIn): confirmed, at least a partial payment, on/after arrival date.
        $ciRowStmt = $pdo->prepare("SELECT status, payment_status, check_in_date FROM bookings WHERE id = ?");
        $ciRowStmt->execute([$booking_id]);
        $ciRow = $ciRowStmt->fetch(PDO::FETCH_ASSOC);
        if ($ciRow) {
            $ciRule = validateCheckIn($ciRow);
            if (!$ciRule['allowed']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Cannot check in: ' . $ciRule['reason']]);
                exit;
            }
        }
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'checked-in' WHERE id = ? AND status = 'confirmed'");
        $stmt->execute([$booking_id]);

        if ($stmt->rowCount() > 0) {
            // Get booking details for email notification
            $booking_stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name
                FROM bookings b
                INNER JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $booking_stmt->execute([$booking_id]);
            $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);

            // Update individual room status if assigned
            if ($booking && !empty($booking['individual_room_id'])) {
                $roomUpdate = $pdo->prepare("UPDATE individual_rooms SET status = 'occupied' WHERE id = ?");
                $roomUpdate->execute([$booking['individual_room_id']]);

                // Log the status change
                $logStmt = $pdo->prepare("
                    INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by)
                    VALUES (?, 'available', 'occupied', ?, ?)
                ");
                $logStmt->execute([$booking['individual_room_id'], 'Check-in: ' . $booking['booking_reference'], $admin_user_id ?: null]);
            }
            if ($booking) {
                require_once __DIR__ . '/../includes/booking-timeline.php';
                logBookingCheckIn($booking_id, (string)$booking['booking_reference'], 'admin', $admin_user_id ?: null, $user['full_name'] ?? null);
            }
            if ($booking && $roomGate['override']) {
                logCheckInRoomNotReadyOverride($booking_id, (string)$booking['booking_reference'], $roomGate['rooms'], $admin_user_id ?: null, $user['full_name'] ?? null);
            }

            // Send status update email
            if ($booking) {
                $email_result = sendSimpleStatusUpdateEmail($booking, 'checked-in');
                if (!$email_result['success']) {
                    error_log("Failed to send check-in email: " . $email_result['message']);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Guest checked in successfully']);
            exit;
        }

        // Give a helpful reason
        $check = $pdo->prepare("SELECT status, payment_status FROM bookings WHERE id = ?");
        $check->execute([$booking_id]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking not found']);
            exit;
        }

        if ($row['status'] !== 'confirmed') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Cannot check in: booking must be confirmed (current: {$row['status']})"]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cannot check in: booking not eligible']);
        exit;
    }

    if ($action === 'cancel_checkin') {
        // Undo a check-in (revert to confirmed)
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ? AND status = 'checked-in'");
        $stmt->execute([$booking_id]);

        if ($stmt->rowCount() > 0) {
            // Get booking details for email notification
            $booking_stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name
                FROM bookings b
                INNER JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $booking_stmt->execute([$booking_id]);
            $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);

            // Update individual room status back to available if it was assigned
            if ($booking && !empty($booking['individual_room_id'])) {
                $roomUpdate = $pdo->prepare("UPDATE individual_rooms SET status = 'available' WHERE id = ?");
                $roomUpdate->execute([$booking['individual_room_id']]);

                // Log the status change
                $logStmt = $pdo->prepare("
                    INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by)
                    VALUES (?, 'occupied', 'available', ?, ?)
                ");
                $logStmt->execute([$booking['individual_room_id'], 'Check-in cancelled: ' . $booking['booking_reference'], $admin_user_id ?: null]);
            }

            // Send status update email
            if ($booking) {
                $email_result = sendSimpleStatusUpdateEmail($booking, 'confirmed');
                if (!$email_result['success']) {
                    error_log("Failed to send status update email: " . $email_result['message']);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Check-in cancelled (reverted to confirmed)']);
            exit;
        }

        $check = $pdo->prepare("SELECT status FROM bookings WHERE id = ?");
        $check->execute([$booking_id]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking not found']);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Cannot cancel check-in: booking is not checked-in (current: {$row['status']})"]);
        exit;
    }

    if ($action === 'checkout') {
        // Use comprehensive room management checkout
        $options = [
            'room_status' => $_POST['room_status'] ?? ROOM_STATUS_CLEANING,
            'urgent_cleaning' => !empty($_POST['urgent_cleaning']),
            'confirm_checkout_with_balance' => !empty($_POST['confirm_checkout_with_balance'])
        ];

        $result = processGuestCheckout($booking_id, $admin_user_id ?: null, $options);

        if ($result['success']) {
            // Send status update email
            $booking_stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name
                FROM bookings b
                INNER JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $booking_stmt->execute([$booking_id]);
            $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);

            if ($booking) {
                $email_result = sendSimpleStatusUpdateEmail($booking, 'checked-out');
                if (!$email_result['success']) {
                    error_log("Failed to send check-out email: " . $email_result['message']);
                }
            }

            $message = $result['message'];
            $response = [
                'success' => true,
                'message' => $message,
                'data' => $result['data'] ?? []
            ];

            // Add workflow details
            if (!empty($result['data']['workflow'])) {
                $workflow = $result['data']['workflow'];

                // Room status info
                if (!empty($workflow['room_update']['success'])) {
                    $roomData = $workflow['room_update']['data'] ?? [];
                    $response['room_number'] = $result['data']['room_number'] ?? null;
                    $response['room_status'] = $roomData['new_status'] ?? 'cleaning';
                }

                // Housekeeping info
                if (!empty($workflow['housekeeping']['success'])) {
                    $response['housekeeping_created'] = true;
                    $response['message'] .= ' Housekeeping assignment created.';
                }

                // Invoice info
                if (!empty($workflow['invoice']['success'])) {
                    if ($workflow['invoice']['idempotent'] ?? false) {
                        $response['message'] .= ' Invoice was already generated.';
                    } else {
                        $response['message'] .= ' Final invoice generated.';
                    }

                    if (!($workflow['invoice']['email_sent'] ?? false)) {
                        $response['warnings'][] = 'Invoice email could not be sent.';
                    }
                } else {
                    $response['warnings'][] = 'Failed to generate invoice: ' . ($workflow['invoice']['message'] ?? 'Unknown error');
                }
            }

            echo json_encode($response);
            exit;
        }

        echo json_encode($result);
        exit;
    }

    if ($action === 'mark_room_clean') {
        // Mark room as clean after housekeeping
        $room_id = (int)($_POST['room_id'] ?? 0);

        if ($room_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid room ID']);
            exit;
        }

        $options = [
            'require_inspection' => !empty($_POST['require_inspection']),
            'notes' => $_POST['notes'] ?? ''
        ];

        $result = markRoomClean($room_id, $admin_user_id ?: null, $options);
        echo json_encode($result);
        exit;
    }

    if ($action === 'pass_inspection') {
        // Pass room inspection
        $room_id = (int)($_POST['room_id'] ?? 0);

        if ($room_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid room ID']);
            exit;
        }

        $options = ['notes' => $_POST['notes'] ?? 'Inspection passed'];
        $result = passRoomInspection($room_id, $admin_user_id, $options);
        echo json_encode($result);
        exit;
    }

    if ($action === 'fail_inspection') {
        // Fail room inspection and send back to cleaning
        $room_id = (int)($_POST['room_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($room_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid room ID']);
            exit;
        }

        if (empty($reason)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Reason for failure is required']);
            exit;
        }

        $result = failRoomInspection($room_id, $admin_user_id, $reason);
        echo json_encode($result);
        exit;
    }

    if ($action === 'update_room_status') {
        // Manual room status update
        $room_id = (int)($_POST['room_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';
        $reason = $_POST['reason'] ?? 'Manual status update';

        if ($room_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid room ID']);
            exit;
        }

        $validStatuses = [ROOM_STATUS_AVAILABLE, ROOM_STATUS_OCCUPIED, ROOM_STATUS_CLEANING,
                          ROOM_STATUS_INSPECTION, ROOM_STATUS_MAINTENANCE, ROOM_STATUS_OUT_OF_ORDER];

        if (!in_array($new_status, $validStatuses)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid room status']);
            exit;
        }

        $result = updateRoomStatus($room_id, $new_status, $reason, $admin_user_id ?: null);
        echo json_encode($result);
        exit;
    }

    if ($action === 'cancel') {
        // Cancel a booking - use centralized validation
        $check = $pdo->prepare("SELECT status, room_id, individual_room_id, booking_reference FROM bookings WHERE id = ?");
        $check->execute([$booking_id]);
        $booking = $check->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking not found']);
            exit;
        }

        // Use centralized validation for cancellation
        $validation = validateBookingCancellation($booking);
        if (!$validation['allowed']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => getBookingActionErrorMessage('cancel', $validation['reason'])]);
            exit;
        }

        // One transaction: status + room/stock release + bill treatment + refund
        // (booking row locked). Any failure rolls everything back.
        $cancelRes = cancelRoomBookingSettled($pdo, (int)$booking_id, (int)$admin_user_id, 'Cancelled via check-in workflow');
        if ($cancelRes['success']) {
            echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully. ' . $cancelRes['summary']]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cancellation failed - nothing was changed. ' . $cancelRes['error']]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

