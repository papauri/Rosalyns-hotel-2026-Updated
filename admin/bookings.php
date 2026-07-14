<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
require_once '../config/base-url.php';

/** @var array $user Admin user array injected by admin-init.php */
/** @var string $csrf_token */

// Load per-user granular permissions for action-button visibility
$_user_permissions = getUserPermissions($user['id']);
$_is_admin_user = in_array($user['role'] ?? '', ['admin', 'manager'], true);
$_perm_quick_modify = $_user_permissions['quick_modify_booking'] ?? false;
$_perm_edit_financials = $_user_permissions['edit_booking_financials'] ?? false;

require_once '../includes/modal.php';
require_once '../includes/alert.php';
require_once '../includes/booking-timeline.php';
require_once '../includes/finance-sequences.php';
$message = '';
$error = '';
$currency_symbol = (string)getSetting('currency_symbol', 'K');
finance_ensure_sequence_tables($pdo);

function isAjaxRequest(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Ensure the booking_status_log audit table exists. Extend-stay, admin
 * checkout-date change and room-upgrade all write an audit row here; on
 * installs where this table was never created the INSERT threw a fatal
 * "table doesn't exist" and aborted the whole action (after the booking row
 * had already been updated, leaving the guest silently extended but the UI
 * showing an error). Self-heal the schema, matching the ensure* pattern used
 * across the app. Idempotent and cheap (CREATE TABLE IF NOT EXISTS).
 */
function ensureBookingStatusLogTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS booking_status_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL,
                old_status VARCHAR(32) DEFAULT NULL,
                new_status VARCHAR(32) DEFAULT NULL,
                changed_by INT DEFAULT NULL,
                change_reason VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bsl_booking (booking_id),
                INDEX idx_bsl_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // Logging must never block the actual booking operation.
        error_log('ensureBookingStatusLogTable: ' . $e->getMessage());
    }
}

/**
 * Write a booking_status_log row without ever letting an audit failure break
 * the surrounding action (missing table, column drift, etc. are swallowed).
 */
function bookings_log_status_change(PDO $pdo, int $bookingId, ?string $oldStatus, ?string $newStatus, ?int $changedBy, string $reason): void
{
    try {
        ensureBookingStatusLogTable($pdo);
        $stmt = $pdo->prepare(
            "INSERT INTO booking_status_log (booking_id, old_status, new_status, changed_by, change_reason, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$bookingId, $oldStatus, $newStatus, $changedBy, mb_substr($reason, 0, 500)]);
    } catch (Throwable $e) {
        error_log('bookings_log_status_change (booking ' . $bookingId . '): ' . $e->getMessage());
    }
}

function getSignedDateDiffDays(DateTimeInterface $fromDate, DateTimeInterface $toDate): int
{
    $diff = $fromDate->diff($toDate);
    $days = (int)($diff->days ?? 0);
    return ((int)$diff->invert === 1) ? -$days : $days;
}

// Handle booking actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token invalid.']);
            exit;
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    try {
        $action = $_POST['action'] ?? '';

        // Ensure the audit table exists up front (before any transaction) so its
        // CREATE-TABLE DDL can't trigger an implicit commit mid-transaction, and
        // so the later bookings_log_status_change() calls never hit a missing table.
        ensureBookingStatusLogTable($pdo);

        if ($action === 'resend_email') {
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $email_type = $_POST['email_type'] ?? '';
            $cc_emails = $_POST['cc_emails'] ?? '';

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }

            // Get booking details
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception('Booking not found');
            }

            // Include email functions
            require_once '../config/email.php';

            // Parse CC emails
            $cc_array = [];
            if (!empty($cc_emails)) {
                $cc_array = array_filter(array_map('trim', explode(',', $cc_emails)));
                $cc_array = array_filter($cc_array, function ($email) {
                    return filter_var($email, FILTER_VALIDATE_EMAIL);
                });
            }

            // Send appropriate email based on type
            $email_result = ['success' => false, 'message' => 'Invalid email type'];

            switch ($email_type) {
                case 'booking_received':
                    $email_result = sendBookingReceivedEmail($booking);
                    break;
                case 'booking_confirmed':
                    $email_result = sendBookingConfirmedEmail($booking);
                    break;
                case 'tentative_confirmed':
                    $booking['tentative_expires_at'] = $booking['tentative_expires_at'] ?? date('Y-m-d H:i:s', strtotime('+48 hours'));
                    $email_result = sendTentativeBookingConfirmedEmail($booking);
                    break;
                case 'tentative_converted':
                    $email_result = sendTentativeBookingConvertedEmail($booking);
                    break;
                case 'booking_cancelled':
                    $cancellation_reason = 'Resent by admin';
                    $email_result = sendBookingCancelledEmail($booking, $cancellation_reason);
                    break;
                case 'booking_reminder':
                    // Reminder emails are only valid for active bookings. A no-show
                    // (or any closed status) is explicitly excluded here so it can
                    // never receive a late/overdue alert — no-show takes precedence
                    // over any time-based checkout logic. sendBookingReminderEmail()
                    // enforces the same rule again as defence-in-depth.
                    if (!in_array($booking['status'], ['confirmed', 'pending', 'checked-in'], true)) {
                        throw new Exception('Reminder emails are only for active bookings (no-show, cancelled and closed bookings are excluded).');
                    }
                    $email_result = sendBookingReminderEmail($booking);
                    break;
                case 'invoice':
                    require_once '../config/invoice.php';
                    $cc_recipients = array_values(array_filter($cc_array));
                    $inv_smtp = getEmailSetting('smtp_username', '');
                    if (!empty($inv_smtp) && !in_array($inv_smtp, $cc_recipients)) {
                        $cc_recipients[] = $inv_smtp;
                    }
                    $email_result = sendPaymentInvoiceEmailWithCC($booking_id, $cc_recipients);
                    if ($email_result['success']) {
                        logBookingAudit($booking_id, 'email_sent', null, null, 'Invoice email resent by admin (' . ($user['full_name'] ?? ($user['username'] ?? '')) . ')', $booking['booking_reference'] ?? null);
                    }
                    break;
                default:
                    throw new Exception('Invalid email type selected');
            }

            if ($email_result['success']) {
                $message = 'Email sent successfully to ' . htmlspecialchars($booking['guest_email']);
                if (!empty($cc_array)) {
                    $message .= ' (CC: ' . implode(', ', array_map(function ($email) {
                        return htmlspecialchars($email);
                    }, $cc_array)) . ')';
                }
                if (isAjaxRequest()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                }
            } else {
                throw new Exception('Failed to send email: ' . $email_result['message']);
            }
        } elseif ($action === 'make_tentative') {
            $booking_id = (int)($_POST['id'] ?? 0);

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }

            // Get booking details
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception('Booking not found');
            }

            // Use centralized validation for tentative transition
            $validation = validateTentativeTransition($booking);
            if (!$validation['allowed']) {
                throw new Exception(getBookingActionErrorMessage('make_tentative', $validation['reason']));
            }

            // Get tentative duration setting
            $tentative_hours = (int)getSetting('tentative_duration_hours', 48);
            $expires_at = date('Y-m-d H:i:s', strtotime("+$tentative_hours hours"));
            $note = $_POST['note'] ?? '';

            // Convert to tentative status
            $update_stmt = $pdo->prepare("
                UPDATE bookings
                SET status = 'tentative',
                    is_tentative = 1,
                    tentative_expires_at = ?
                WHERE id = ?
            ");
            $update_stmt->execute([$expires_at, $booking_id]);

            // Log the action
            $log_stmt = $pdo->prepare("
                INSERT INTO tentative_booking_log (
                    booking_id, action, new_expires_at, action_reason, performed_by, created_at
                ) VALUES (?, 'created', ?, ?, ?, NOW())
            ");
            $log_stmt->execute([
                $booking_id,
                $expires_at,
                $note,
                $user['id']
            ]);

            // Send tentative booking email
            require_once '../config/email.php';
            $booking['tentative_expires_at'] = $expires_at;
            $email_result = sendTentativeBookingConfirmedEmail($booking);

            if ($email_result['success']) {
                $message = 'Booking converted to tentative! Confirmation email sent to guest.';
            } else {
                $message = 'Booking made tentative! (Email failed: ' . $email_result['message'] . ')';
            }

            rh_log_event('bookings', 'info', 'Booking made tentative', ['booking_id' => $booking_id, 'ref' => $booking['booking_reference'] ?? null, 'expires' => $expires_at, 'by' => $user['username'] ?? null]);
            logBookingAudit($booking_id, 'tentative', ['status' => $booking['status'] ?? 'confirmed'], ['status' => 'tentative', 'tentative_expires_at' => $expires_at], 'Made tentative by admin', $booking['booking_reference'] ?? null);
        } elseif ($action === 'convert_tentative') {
            $booking_id = (int)($_POST['id'] ?? 0);

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }

            // Get booking details WITH room information
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name, r.slug as room_slug
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception('Booking not found');
            }

            if ($booking['status'] !== 'tentative' || $booking['is_tentative'] != 1) {
                throw new Exception('This is not a tentative booking');
            }

            // Convert to confirmed status and clear tentative fields
            $update_stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', is_tentative = 0, tentative_expires_at = NULL WHERE id = ?");
            $update_stmt->execute([$booking_id]);

            // Decrement room availability — tentative bookings don't consume rooms_available,
            // but confirmed bookings do; apply the same decrement as pending→confirmed.
            $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")
                ->execute([$booking['room_id']]);

            $autoAssignMessage = '';
            if (in_array($booking['payment_status'] ?? '', ['paid', 'completed'], true) && empty($booking['individual_room_id'])) {
                $autoAssignResult = autoAssignConfirmedPaidBooking($booking_id);
                if ($autoAssignResult['success'] && !empty($autoAssignResult['assigned_room_number'])) {
                    $autoAssignMessage = ' Room ' . htmlspecialchars($autoAssignResult['assigned_room_number']) . ' auto-assigned.';
                } elseif (!$autoAssignResult['success']) {
                    $autoAssignMessage = ' (Room auto-assignment skipped: ' . htmlspecialchars($autoAssignResult['message']) . ')';
                }
            }

            // Log the conversion
            $log_stmt = $pdo->prepare("
                INSERT INTO tentative_booking_log (
                    booking_id, action, action_reason, performed_by, created_at
                ) VALUES (?, 'converted', ?, ?, NOW())
            ");
            $log_stmt->execute([
                $booking_id,
                'Converted from tentative to confirmed by admin',
                $user['id']
            ]);

            // Send conversion email
            require_once '../config/email.php';
            $email_result = sendTentativeBookingConvertedEmail($booking);

            // Log email result for debugging
            error_log("Email sending result for booking {$booking_id}: " . json_encode($email_result));

            if ($email_result['success']) {
                if (isset($email_result['preview_url'])) {
                    $message = 'Tentative booking converted to confirmed!' . $autoAssignMessage . ' <a href="../' . htmlspecialchars($email_result['preview_url']) . '" target="_blank">View email preview</a> (Development Mode)';
                } else {
                    $message = 'Tentative booking converted to confirmed!' . $autoAssignMessage . ' Conversion email sent to ' . htmlspecialchars($booking['guest_email']);
                }
            } else {
                $message = 'Tentative booking converted!' . $autoAssignMessage . ' <strong>Email failed:</strong> ' . htmlspecialchars($email_result['message']);
                error_log("FAILED to send email for converted booking {$booking_id}: " . $email_result['message']);
            }

            rh_log_event('bookings', 'info', 'Tentative booking confirmed', ['booking_id' => $booking_id, 'ref' => $booking['booking_reference'] ?? null, 'by' => $user['username'] ?? null]);
            logBookingAudit($booking_id, 'confirmed', ['status' => 'tentative', 'is_tentative' => 1], ['status' => 'confirmed', 'is_tentative' => 0], 'Converted from tentative to confirmed by admin', $booking['booking_reference'] ?? null);
        } elseif ($action === 'convert_to_tentative') {
            $booking_id = (int)($_POST['id'] ?? 0);

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }

            // Get booking details
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name, r.slug as room_slug
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception('Booking not found');
            }

            // BLOCK: Confirmed bookings CANNOT be converted to tentative
            // This is a business rule to prevent accounting issues
            // Once a booking is confirmed or has any payment, it cannot revert to tentative
            $validation = validateTentativeTransition($booking);
            if (!$validation['allowed']) {
                throw new Exception(getBookingActionErrorMessage('make_tentative', $validation['reason']));
            }

            // If we reach here, the validation passed (shouldn't happen for confirmed bookings)
            throw new Exception('Operation not allowed: confirmed bookings cannot be converted to tentative');
        } elseif ($action === 'get_available_rooms') {
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }

            $room_type_id = (int)($_POST['room_type_id'] ?? 0);
            $check_in = trim($_POST['check_in'] ?? '');
            $check_out = trim($_POST['check_out'] ?? '');
            $exclude_booking_id = !empty($_POST['exclude_booking_id']) ? (int)$_POST['exclude_booking_id'] : null;

            if ($room_type_id <= 0 || !$check_in || !$check_out) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Missing required parameters',
                    'data' => []
                ]);
                exit;
            }

            $bookingChildGuests = 0;
            if ($exclude_booking_id) {
                $childStmt = $pdo->prepare("SELECT child_guests FROM bookings WHERE id = ?");
                $childStmt->execute([$exclude_booking_id]);
                $bookingChildGuests = (int)($childStmt->fetchColumn() ?: 0);
            }

            $availableRooms = getAvailableIndividualRooms($room_type_id, $check_in, $check_out, $exclude_booking_id);

            $normalized = array_map(function ($room) use ($bookingChildGuests) {
                $childrenAllowed = (int)($room['children_allowed'] ?? 1);
                return [
                    'id' => (int)$room['id'],
                    'room_number' => $room['room_number'] ?? '',
                    'room_name' => $room['room_name'] ?? '',
                    'room_type_name' => $room['room_type_name'] ?? null,
                    'floor' => $room['floor'] ?? null,
                    'children_allowed' => $childrenAllowed,
                    'requires_child_override' => $bookingChildGuests > 0 && $childrenAllowed === 0,
                    'available' => true
                ];
            }, $availableRooms);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Available rooms loaded',
                'data' => $normalized
            ]);
            exit;
        } elseif ($action === 'assign_individual_room') {
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }

            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $individual_room_id = (int)($_POST['individual_room_id'] ?? 0);
            $allowChildPolicyOverride = isset($_POST['allow_child_policy_override']) && $_POST['allow_child_policy_override'] === '1';
            $childPolicyOverrideNote = trim((string)($_POST['child_policy_override_note'] ?? ''));

            if ($booking_id <= 0 || $individual_room_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid booking or room selection']);
                exit;
            }

            $bkStmt = $pdo->prepare("SELECT id, status, room_id, individual_room_id, child_guests, booking_reference FROM bookings WHERE id = ?");
            $bkStmt->execute([$booking_id]);
            $bookingToAssign = $bkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$bookingToAssign) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found']);
                exit;
            }

            // Use centralized validation for room assignment
            $validation = validateRoomAssignment($bookingToAssign);
            if (!$validation['allowed']) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => getBookingActionErrorMessage('assign_room', $validation['reason'])]);
                exit;
            }

            $policyInfo = getIndividualRoomEffectivePolicy((int)$bookingToAssign['room_id'], $individual_room_id);
            if (!$policyInfo) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Selected room does not match this booking room type']);
                exit;
            }

            if ((int)($bookingToAssign['child_guests'] ?? 0) > 0 && empty($policyInfo['policy']['children_allowed'])) {
                if (!$allowChildPolicyOverride || $childPolicyOverrideNote === '') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'This booking includes children. Assigning a room that does not accept children requires an override note.']);
                    exit;
                }
            }

            // Housekeeping / room-status gate with a clear, actionable reason so
            // staff know WHY the room is blocked and HOW to free it (rooms with a
            // pending checkout cleanup must not be assignable until it is done).
            $hkBlock = getRoomHousekeepingAssignmentBlock($individual_room_id);
            if ($hkBlock && !empty($hkBlock['blocked'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $hkBlock['message']]);
                exit;
            }

            $assigned = assignIndividualRoomToBooking($booking_id, $individual_room_id, $allowChildPolicyOverride, $childPolicyOverrideNote, $user['id'] ?? null);

            if (!$assigned) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Selected room could not be assigned — it is unavailable for the booking dates (it may overlap another reservation, a maintenance block, or a housekeeping task). Check the room timeline and try again.']);
                exit;
            }

            // Note: assignIndividualRoomToBooking() already handles room status updates internally
            // for confirmed/checked-in bookings, so we don't need to duplicate it here
            logBookingAudit(
                $booking_id,
                'room_assigned',
                ['individual_room_id' => $bookingToAssign['individual_room_id'] ?? null],
                ['individual_room_id' => $individual_room_id],
                $allowChildPolicyOverride ? 'Child policy override: ' . $childPolicyOverrideNote : 'Room assigned manually by admin',
                $bookingToAssign['booking_reference'] ?? null
            );

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Room assigned successfully']);
            exit;
        } elseif ($action === 'update_status') {
            $booking_id = (int)($_POST['id'] ?? 0);
            $new_status = $_POST['status'] ?? '';
            $checkin_note = trim((string)($_POST['checkin_note'] ?? ''));

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }

            // Enforce business rules:
            // - Check-in only allowed when confirmed AND paid
            // - Cancel check-in (undo) allowed only when currently checked-in
            if ($new_status === 'checked-in') {
                // Use centralized validation
                $check = $pdo->prepare("SELECT id, status, payment_status, individual_room_id, booking_reference, check_in_date FROM bookings WHERE id = ?");
                $check->execute([$booking_id]);
                $booking = $check->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    throw new Exception('Booking not found');
                }

                // Use the validation helper function
                $validation = validateCheckIn($booking);
                if (!$validation['allowed']) {
                    throw new Exception(getBookingActionErrorMessage('check_in', $validation['reason']));
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'checked-in' WHERE id = ?");
                $stmt->execute([$booking_id]);

                updateBookingRoomsStatus(
                    $booking_id,
                    'occupied',
                    'Guest checked in: ' . ($booking['booking_reference'] ?? ('Booking #' . $booking_id)),
                    $user['id'] ?? null
                );
                $pdo->commit();

                // Timeline event (outside transaction — non-fatal if it fails)
                logBookingCheckIn($booking_id, $booking['booking_reference'] ?? '', 'admin', $user['id'] ?? null, $user['full_name'] ?? null);

                $scheduledCheckIn = new DateTime((string)($booking['check_in_date'] ?? 'today'));
                $scheduledCheckIn->setTime(0, 0, 0);
                $todayCheckIn = new DateTime('today');
                $isLateCheckIn = $scheduledCheckIn < $todayCheckIn;
                $lateDays = $isLateCheckIn ? (int)$scheduledCheckIn->diff($todayCheckIn)->days : 0;

                $message = 'Guest checked in!';
                $checkInEventContext = [
                    'booking_id' => $booking_id,
                    'ref' => $booking['booking_reference'] ?? null,
                    'scheduled_check_in' => $booking['check_in_date'] ?? null,
                    'is_late_checkin' => $isLateCheckIn,
                    'days_late' => $lateDays,
                    'by' => $user['username'] ?? null,
                ];
                if ($checkin_note !== '') {
                    $checkInEventContext['note'] = $checkin_note;
                }
                rh_log_event(
                    'bookings',
                    $isLateCheckIn ? 'warning' : 'info',
                    $isLateCheckIn ? 'Late guest check-in completed' : 'Guest checked in',
                    $checkInEventContext
                );

                $checkInAuditNote = $checkin_note !== '' ? ('Check-in note: ' . $checkin_note) : null;
                if ($isLateCheckIn && $lateDays > 0) {
                    $lateDescriptor = 'Late check-in (' . $lateDays . ' day(s) overdue)';
                    $checkInAuditNote = $checkInAuditNote !== null
                        ? ($lateDescriptor . ' - ' . $checkInAuditNote)
                        : $lateDescriptor;
                }
                logBookingAudit(
                    $booking_id,
                    'checked-in',
                    ['status' => 'confirmed'],
                    ['status' => 'checked-in'],
                    $checkInAuditNote,
                    $booking['booking_reference'] ?? null
                );
            } elseif ($new_status === 'cancel-checkin') {
                $check = $pdo->prepare("SELECT id, status, booking_reference FROM bookings WHERE id = ?");
                $check->execute([$booking_id]);
                $booking = $check->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    throw new Exception('Booking not found');
                }

                if ($booking['status'] !== 'checked-in') {
                    throw new Exception('Can only cancel check-in for a booking that is currently checked in (current status: ' . ($booking['status'] ?? 'unknown') . ')');
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
                $stmt->execute([$booking_id]);

                updateBookingRoomsStatus(
                    $booking_id,
                    'available',
                    'Check-in cancelled: ' . ($booking['booking_reference'] ?? ('Booking #' . $booking_id)),
                    $user['id'] ?? null
                );
                $pdo->commit();

                $message = 'Check-in cancelled (reverted to confirmed).';
                rh_log_event('bookings', 'info', 'Check-in cancelled', ['booking_id' => $booking_id, 'by' => $user['username'] ?? null]);
                logBookingAudit($booking_id, 'check-in cancelled', ['status' => 'checked-in'], ['status' => 'confirmed'], null, $booking['booking_reference'] ?? null);
            } else {
                $allowed = ['pending', 'confirmed', 'checked-out', 'cancelled'];
                if (!in_array($new_status, $allowed, true)) {
                    throw new Exception('Invalid status');
                }

                // Get current booking status and room_id before updating
                $check_stmt = $pdo->prepare("SELECT status, payment_status, room_id, individual_room_id, booking_reference, check_in_date, check_out_date FROM bookings WHERE id = ?");
                $check_stmt->execute([$booking_id]);
                $current_booking = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$current_booking) {
                    throw new Exception('Booking not found');
                }

                $current_status = $current_booking['status'];
                $room_id = $current_booking['room_id'];

                // Validate status transition using helper function
                $transitionValidation = validateBookingStatusTransition($current_status, $new_status);
                if (!$transitionValidation['allowed']) {
                    throw new Exception($transitionValidation['reason']);
                }

                // Update booking status; if leaving tentative, clear tentative fields so badge counts stay accurate
                $clear_tentative_flag = ($new_status !== 'tentative') ? ', is_tentative = 0, tentative_expires_at = NULL' : '';
                $stmt = $pdo->prepare("UPDATE bookings SET status = ?{$clear_tentative_flag} WHERE id = ?");
                $stmt->execute([$new_status, $booking_id]);
                $message = 'Booking status updated!';
                rh_log_event('bookings', 'info', 'Booking status changed', ['booking_id' => $booking_id, 'from' => $current_status, 'to' => $new_status, 'by' => $user['username'] ?? null]);
                logBookingAudit($booking_id, $new_status, ['status' => $current_status], ['status' => $new_status], null, $current_booking['booking_reference'] ?? null);

                // Handle room availability changes
                if (in_array($current_status, ['pending', 'tentative'], true) && $new_status === 'confirmed') {
                    // Serialise on the room-type row (same lock the creation flows take)
                    // BEFORE the availability re-check + rooms_available decrement, so two
                    // simultaneous confirmations can't both claim the last room.
                    $pdo->prepare("SELECT id FROM rooms WHERE id = ? FOR UPDATE")->execute([$room_id]);
                    // Check availability before confirming
                    $availabilityCheck = checkRoomAvailability($room_id, $current_booking['check_in_date'], $current_booking['check_out_date'], $booking_id);
                    if (!$availabilityCheck['available']) {
                        // Rollback status change
                        $stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?");
                        $stmt->execute([$current_status, $booking_id]);
                        $errorMsg = getBookingActionErrorMessage('confirm', $availabilityCheck['error'] ?? 'No rooms available');
                        throw new Exception($errorMsg);
                    }

                    // Booking confirmed: decrement rooms_available
                    $update_room = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0");
                    $update_room->execute([$room_id]);

                    if ($update_room->rowCount() === 0) {
                        // This shouldn't happen if availability checks are working, but handle it
                        $message .= ' (Warning: Could not update room availability - room may be fully booked)';
                    } else {
                        $message .= ' Room availability updated.';
                    }

                    // Send booking confirmed email
                    $booking_stmt = $pdo->prepare("
                        SELECT b.*, r.name as room_name
                        FROM bookings b
                        LEFT JOIN rooms r ON b.room_id = r.id
                        WHERE b.id = ?
                    ");
                    $booking_stmt->execute([$booking_id]);
                    $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($booking) {
                        // Include email functions
                        require_once '../config/email.php';

                        // Send booking confirmed email
                        $email_result = sendBookingConfirmedEmail($booking);

                        if ($email_result['success']) {
                            $message .= ' Confirmation email sent to guest.';
                        } else {
                            $message .= ' (Note: Confirmation email failed: ' . $email_result['message'] . ')';
                        }

                        // Auto-assign individual room only once the booking is confirmed and paid.
                        if (in_array($booking['payment_status'] ?? '', ['paid', 'completed'], true) && empty($booking['individual_room_id'])) {
                            $autoAssignResult = autoAssignConfirmedPaidBooking($booking_id);
                            if ($autoAssignResult['success'] && !empty($autoAssignResult['assigned_room_number'])) {
                                $message .= ' Room ' . htmlspecialchars($autoAssignResult['assigned_room_number']) . ' auto-assigned.';
                            } else {
                                $message .= ' (Note: Auto-assign skipped - ' . htmlspecialchars($autoAssignResult['message']) . ')';
                            }
                        }
                    }
                } elseif ($current_status === 'confirmed' && $new_status === 'cancelled') {
                    // Booking cancelled: increment rooms_available
                    $update_room = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms");
                    $update_room->execute([$room_id]);

                    if ($update_room->rowCount() > 0) {
                        $message .= ' Room availability restored.';
                    }

                    updateBookingRoomsStatus(
                        $booking_id,
                        'available',
                        'Booking cancelled: ' . ($current_booking['booking_reference'] ?? ('Booking #' . $booking_id)),
                        $user['id'] ?? null
                    );

                    // ── Refund accounting: if any completed payment exists, create a refund record ──
                    $canPay_stmt = $pdo->prepare("
                        SELECT SUM(total_amount) as total_paid
                        FROM payments
                        WHERE booking_type = 'room' AND booking_id = ?
                          AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund'
                          AND deleted_at IS NULL
                    ");
                    $canPay_stmt->execute([$booking_id]);
                    $canPay_row = $canPay_stmt->fetch(PDO::FETCH_ASSOC);
                    $cancel_paid_total = (float)($canPay_row['total_paid'] ?? 0);

                    if ($cancel_paid_total > 0) {
                        do {
                            $cancel_refund_ref = 'RFD-CAN-' . strtoupper(substr(uniqid(), -8));
                            $canRefChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
                            $canRefChk->execute([$cancel_refund_ref]);
                        } while ((int)$canRefChk->fetchColumn() > 0);

                        $vatEnabled_can = getSetting('vat_enabled') === '1';
                        $vatRate_can    = $vatEnabled_can ? (float)getSetting('vat_rate') : 0;
                        $vatAmt_can     = $vatRate_can > 0
                            ? round($cancel_paid_total * ($vatRate_can / (100 + $vatRate_can)), 2)
                            : 0;
                        $netAmt_can     = round($cancel_paid_total - $vatAmt_can, 2);

                        $cancel_reason_text = $_POST['cancellation_reason'] ?? 'Booking cancelled by admin';
                        $pdo->prepare("
                            INSERT INTO payments (
                                payment_reference, booking_type, booking_id, booking_reference,
                                payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                                payment_method, payment_type, payment_status,
                                refund_reason, refund_status, refund_amount,
                                recorded_by, created_at
                            ) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'refund', 'completed',
                                      'cancellation', 'completed', ?, ?, NOW())
                        ")->execute([
                            $cancel_refund_ref,
                            $booking_id,
                            $current_booking['booking_reference'],
                            $netAmt_can,
                            $vatRate_can,
                            $vatAmt_can,
                            $cancel_paid_total,
                            $cancel_paid_total,
                            (int)($user['id'] ?? 0),
                        ]);

                        rh_log_event('bookings', 'info', 'Refund recorded on booking cancellation', [
                            'booking_id' => $booking_id,
                            'ref'        => $current_booking['booking_reference'],
                            'amount'     => $cancel_paid_total,
                            'refund_ref' => $cancel_refund_ref,
                            'by'         => $user['username'] ?? null,
                        ]);
                        $message .= ' Refund of ' . $currency_symbol . ' ' . number_format($cancel_paid_total, 2)
                            . ' recorded (Ref: ' . $cancel_refund_ref . ').';
                    }

                    // Recalculate so amount_due is correct, then force payment_status to reflect cancellation
                    recalculateBookingFinancials((int)$booking_id);
                    if ($cancel_paid_total > 0) {
                        $pdo->prepare("UPDATE bookings SET payment_status = 'refunded', updated_at = NOW() WHERE id = ?")
                            ->execute([$booking_id]);
                    }

                    // Get booking details for email and logging
                    $booking_stmt = $pdo->prepare("
                        SELECT b.*, r.name as room_name
                        FROM bookings b
                        LEFT JOIN rooms r ON b.room_id = r.id
                        WHERE b.id = ?
                    ");
                    $booking_stmt->execute([$booking_id]);
                    $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($booking) {
                        // Send cancellation email
                        require_once '../config/email.php';
                        $cancellation_reason = $_POST['cancellation_reason'] ?? 'Cancelled by admin';
                        $email_result = sendBookingCancelledEmail($booking, $cancellation_reason);

                        // Log cancellation to database
                        $email_sent = $email_result['success'];
                        $email_status = $email_result['message'];
                        logCancellationToDatabase(
                            $booking['id'],
                            $booking['booking_reference'],
                            'room',
                            $booking['guest_email'],
                            $user['id'],
                            $cancellation_reason,
                            $email_sent,
                            $email_status
                        );

                        // Log cancellation to file
                        logCancellationToFile(
                            $booking['booking_reference'],
                            'room',
                            $booking['guest_email'],
                            $user['full_name'] ?? $user['username'],
                            $cancellation_reason,
                            $email_sent,
                            $email_status
                        );

                        if ($email_sent) {
                            $message .= ' Cancellation email sent.';
                        } else {
                            $message .= ' (Email failed: ' . $email_status . ')';
                        }
                    }
                }
            }

            if (isAjaxRequest()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message]);
                exit;
            }
        } elseif ($action === 'modify_booking') {
            // Comprehensive modify-booking handler used by the Modify modal.
            $actorId = (int)($user['id'] ?? 0);
            if ($actorId <= 0 || !hasPermission($actorId, 'edit_booking') || !hasPermission($actorId, 'quick_modify_booking')) {
                throw new Exception('You do not have permission to quick modify bookings.');
            }

            $canEditBookingFinancials = hasPermission($actorId, 'edit_booking_financials');
            $booking_id = (int)($_POST['id'] ?? 0);
            if ($booking_id <= 0) throw new Exception('Invalid booking id');

            $cur = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $cur->execute([$booking_id]);
            $current = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new Exception('Booking not found');

            // Only fields the modal allows. Whitelist + sanitize.
            $allowed = [
                'guest_name'        => 'string',
                'guest_email'       => 'string',
                'guest_phone'       => 'string',
                'guest_country'     => 'string',
                'check_in_date'     => 'date',
                'check_out_date'    => 'date',
                'number_of_guests'  => 'int',
                'adult_guests'      => 'int',
                'child_guests'      => 'int',
                'special_requests'  => 'string',
                'status'            => 'string',
                'payment_status'    => 'string',
                'individual_room_id' => 'int',
            ];
            if ($canEditBookingFinancials) {
                $allowed['total_amount'] = 'float';
            }
            $updates = [];
            $newValues = [];
            foreach ($allowed as $field => $type) {
                if (!array_key_exists($field, $_POST)) continue;
                $raw = $_POST[$field];
                if ($raw === '' && in_array($type, ['int', 'float'], true)) continue;
                switch ($type) {
                    case 'int':
                        $val = (int)$raw;
                        break;
                    case 'float':
                        $val = (float)$raw;
                        break;
                    case 'date':
                        $val = $raw ?: null;
                        if ($val && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                            throw new Exception("Invalid date for $field");
                        }
                        break;
                    default:
                        $val = trim((string)$raw);
                }
                $updates[$field] = $val;
                $newValues[$field] = $val;
            }

            if (!empty($updates['check_in_date']) && !empty($updates['check_out_date'])) {
                if (strtotime($updates['check_out_date']) <= strtotime($updates['check_in_date'])) {
                    throw new Exception('Check-out date must be after check-in date.');
                }
            }
            $allowedStatuses = ['pending', 'tentative', 'confirmed', 'checked-in', 'checked-out', 'cancelled', 'no-show'];
            if (isset($updates['status']) && !in_array($updates['status'], $allowedStatuses, true)) {
                throw new Exception('Invalid status value.');
            }
            $allowedPayment = ['unpaid', 'partial', 'paid', 'completed', 'refunded', 'partially_refunded', 'failed', 'pending'];
            if (isset($updates['payment_status']) && !in_array($updates['payment_status'], $allowedPayment, true)) {
                throw new Exception('Invalid payment status.');
            }

            if (!$canEditBookingFinancials && array_key_exists('total_amount', $_POST)) {
                throw new Exception('You do not have permission to change booking amounts.');
            }

            // Recompute number_of_nights when dates changed
            if (isset($updates['check_in_date']) || isset($updates['check_out_date'])) {
                $ci = $updates['check_in_date']  ?? $current['check_in_date'];
                $co = $updates['check_out_date'] ?? $current['check_out_date'];
                $nights = max(1, (int)round((strtotime($co) - strtotime($ci)) / 86400));
                $updates['number_of_nights'] = $nights;
                $newValues['number_of_nights'] = $nights;
            }

            // Remove amount_paid and amount_due from the direct update — they are always
            // recomputed from the payments ledger by recalculateBookingFinancials() below,
            // so never trust client-supplied values for these fields.
            unset($updates['amount_paid'], $updates['amount_due'], $newValues['amount_paid'], $newValues['amount_due']);

            if (empty($updates)) throw new Exception('No fields to update.');

            // Build & run UPDATE
            $sets = [];
            $vals = [];
            foreach ($updates as $f => $v) {
                $sets[] = "$f = ?";
                $vals[] = $v;
            }
            $sets[] = "updated_at = NOW()";
            $vals[] = $booking_id;
            $stmt = $pdo->prepare("UPDATE bookings SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($vals);

            // When total_amount was manually overridden, recompute vat_amount and total_with_vat
            // so recalculateBookingFinancials sees the correct gross base.
            if (isset($updates['total_amount'])) {
                $reVatRate  = (float)($current['vat_rate'] ?? 0);
                $reNewTotal = (float)$updates['total_amount'];
                $reVatAmt   = $reVatRate > 0 ? round($reNewTotal * ($reVatRate / 100), 2) : 0.0;
                $pdo->prepare("UPDATE bookings SET vat_amount = ?, total_with_vat = ? WHERE id = ?")
                    ->execute([$reVatAmt, round($reNewTotal + $reVatAmt, 2), $booking_id]);
            }

            // Recompute financials from the payments ledger to keep amount_paid / amount_due accurate
            recalculateBookingFinancials($booking_id);

            // Audit
            $oldSubset = array_intersect_key($current, $newValues);
            logBookingAudit($booking_id, 'modified', $oldSubset, $newValues, $_POST['note'] ?? null, $current['booking_reference'] ?? null);
            rh_log_event('bookings', 'info', 'Booking modified', ['booking_id' => $booking_id, 'fields' => array_keys($newValues), 'by' => $user['username'] ?? null]);

            $message = 'Booking updated successfully.';

            // Send guest modification email when meaningful fields changed
            $notifyFields = ['check_in_date', 'check_out_date', 'number_of_guests', 'adult_guests', 'child_guests',
                             'room_id', 'total_amount', 'status', 'special_requests', 'guest_email', 'guest_name', 'guest_phone'];
            $meaningfulChanges = array_intersect_key($newValues, array_flip($notifyFields));
            if (!empty($meaningfulChanges)) {
                try {
                    require_once '../config/email.php';
                    $modStmt = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
                    $modStmt->execute([$booking_id]);
                    $modBooking = $modStmt->fetch(PDO::FETCH_ASSOC);
                    if ($modBooking) {
                        $modEmailResult = sendBookingModifiedEmail($modBooking, $meaningfulChanges);
                        if ($modEmailResult['success']) {
                            $message .= ' Guest notified by email.';
                        } else {
                            error_log('Quick-modify guest email failed for booking ' . $booking_id . ': ' . $modEmailResult['message']);
                        }
                    }
                } catch (Throwable $modEmailEx) {
                    error_log('Quick-modify guest email exception for booking ' . $booking_id . ': ' . $modEmailEx->getMessage());
                }
            }

            if (isAjaxRequest()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message]);
                exit;
            }
        } elseif ($action === 'get_booking_for_modify') {
            $actorId = (int)($user['id'] ?? 0);
            if ($actorId <= 0 || !hasPermission($actorId, 'edit_booking') || !hasPermission($actorId, 'quick_modify_booking')) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'You do not have permission to quick modify bookings.']);
                exit;
            }

            $bid = (int)($_POST['booking_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
            $stmt->execute([$bid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            header('Content-Type: application/json');
            echo json_encode($row ? ['success' => true, 'data' => $row] : ['success' => false, 'message' => 'Not found']);
            exit;
        } elseif ($action === 'get_booking_audit_log') {
            $bid = (int)($_POST['booking_id'] ?? 0);
            $payload = [
                'booking' => null,
                'audit_logs' => function_exists('getBookingAuditLog') ? getBookingAuditLog($bid, 120) : [],
                'payments' => [],
                'tentative_log' => [],
            ];

            if ($bid > 0) {
                $booking_stmt = $pdo->prepare("\n                    SELECT b.id,
                           b.booking_reference,
                           b.guest_name,
                           b.guest_email,
                           b.guest_phone,
                           b.status,
                           b.payment_status,
                           b.check_in_date,
                           b.check_out_date,
                           b.number_of_nights,
                           b.total_amount,
                           b.amount_paid,
                           b.amount_due,
                           b.created_at,
                           b.updated_at,
                           r.name AS room_type_name,
                           ir.room_number AS room_number
                    FROM bookings b
                    LEFT JOIN rooms r ON b.room_id = r.id
                    LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                    WHERE b.id = ?");
                $booking_stmt->execute([$bid]);
                $payload['booking'] = $booking_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $payments_stmt = $pdo->prepare("\n                    SELECT p.id,
                           p.payment_reference,
                           p.payment_date,
                           p.payment_method,
                           p.payment_type,
                           p.payment_status,
                           p.payment_amount,
                           p.total_amount,
                           p.vat_amount,
                           p.refund_amount,
                           p.refund_reason,
                           p.notes,
                           p.recorded_by,
                           p.created_at,
                           COALESCE(au.full_name, au.username) AS recorded_by_name
                    FROM payments p
                    LEFT JOIN admin_users au ON au.id = p.recorded_by
                    WHERE p.booking_type = 'room'
                      AND p.booking_id = ?
                      AND p.deleted_at IS NULL
                    ORDER BY COALESCE(p.created_at, CONCAT(p.payment_date, ' 00:00:00')) DESC, p.id DESC
                    LIMIT 200");
                $payments_stmt->execute([$bid]);
                $payload['payments'] = $payments_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                if (function_exists('auditTableExists') && auditTableExists($pdo, 'tentative_booking_log')) {
                    $tentative_stmt = $pdo->prepare("\n                        SELECT tbl.id,
                               tbl.action,
                               tbl.action_reason,
                               tbl.previous_expires_at,
                               tbl.new_expires_at,
                               tbl.performed_by,
                               tbl.created_at,
                               COALESCE(au.full_name, au.username) AS performed_by_name
                        FROM tentative_booking_log tbl
                        LEFT JOIN admin_users au ON au.id = tbl.performed_by
                        WHERE tbl.booking_id = ?
                        ORDER BY tbl.created_at DESC, tbl.id DESC
                        LIMIT 120");
                    $tentative_stmt->execute([$bid]);
                    $payload['tentative_log'] = $tentative_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            }

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'data' => $payload]);
            exit;
        } elseif ($action === 'send_invoice_email') {
            $bid = (int)($_POST['booking_id'] ?? 0);
            if ($bid <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
                exit;
            }
            require_once '../config/invoice.php';
            $cc_recipients = [];
            $invoice_recipients = getEmailSetting('invoice_recipients', '');
            $smtp_username     = getEmailSetting('smtp_username', '');
            if (!empty($invoice_recipients)) {
                $cc_recipients = array_filter(array_map('trim', explode(',', $invoice_recipients)));
            }
            if (!empty($smtp_username) && !in_array($smtp_username, $cc_recipients)) {
                $cc_recipients[] = $smtp_username;
            }
            $result = sendPaymentInvoiceEmailWithCC($bid, $cc_recipients);
            if ($result['success']) {
                logBookingAudit($bid, 'email_sent', null, null, 'Invoice email sent by admin (' . ($user['full_name'] ?? ($user['username'] ?? '')) . ')');
            }
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        } elseif ($action === 'find_payment_for_refund') {
            $bid = (int)($_POST['booking_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, payment_reference, total_amount, payment_status, payment_type FROM payments
                WHERE booking_type = 'room' AND booking_id = ?
                  AND (deleted_at IS NULL)
                  AND payment_status IN ('completed','paid')
                  AND COALESCE(payment_type, '') <> 'refund'
                ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$bid]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            header('Content-Type: application/json');
            echo json_encode($p ? ['success' => true, 'payment' => $p] : ['success' => false, 'message' => 'No refundable payment found for this booking.']);
            exit;
        } elseif ($action === 'update_payment') {
            $payment_status = $_POST['payment_status'];
            $booking_id = $_POST['id'];

            // Get previous payment status and booking details
            $check = $pdo->prepare("SELECT status, payment_status, individual_room_id, total_amount, booking_reference FROM bookings WHERE id = ?");
            $check->execute([$booking_id]);
            $row = $check->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception('Booking not found');
            }

            $previous_status = $row['payment_status'] ?? 'unpaid';
            $total_amount = (float)$row['total_amount'];
            $booking_reference = $row['booking_reference'];

            // VAT per installation mode: exclusive adds on top, inclusive
            // extracts from the priced amount, off is zero.
            $vatParts = vat_components($total_amount);
            $vatRate = $vatParts['rate'];
            $vatAmount = $vatParts['vat'];
            $totalWithVat = $vatParts['total'];

            // Update payment status
            $stmt = $pdo->prepare("UPDATE bookings SET payment_status = ? WHERE id = ?");
            $stmt->execute([$payment_status, $booking_id]);
            logBookingAudit((int)$booking_id, 'payment_updated', ['payment_status' => $previous_status], ['payment_status' => $payment_status], 'Payment status updated by admin (' . ($user['full_name'] ?? ($user['username'] ?? '')) . ')', $booking_reference);
            $message = 'Payment status updated!';

            // If marking as paid, insert into payments table and update booking amounts
            if ($payment_status === 'paid' && $previous_status !== 'paid') {
                // Generate payment reference
                $payment_reference_base = 'PAY-' . date('Y') . '-' . str_pad($booking_id, 6, '0', STR_PAD_LEFT);
                $payment_reference = $payment_reference_base;
                $payment_reference_suffix = 1;
                $paymentReferenceCheck = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE payment_reference = ?');
                $paymentReferenceCheck->execute([$payment_reference]);
                while ((int)$paymentReferenceCheck->fetchColumn() > 0) {
                    $payment_reference_suffix++;
                    $payment_reference = $payment_reference_base . '-' . $payment_reference_suffix;
                    $paymentReferenceCheck->execute([$payment_reference]);
                }

                // Guard: skip insert if a completed payment already exists (prevents double-recording on retry)
                $dupChk = $pdo->prepare("
                    SELECT COUNT(*) FROM payments
                    WHERE booking_type = 'room' AND booking_id = ?
                      AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund'
                      AND deleted_at IS NULL
                ");
                $dupChk->execute([$booking_id]);
                if ((int)$dupChk->fetchColumn() === 0) {
                    $receipt_number = finance_next_receipt_number($pdo, date('Y-m-d'));
                    // Insert into payments table
                    $insert_payment = $pdo->prepare("
                        INSERT INTO payments (
                            payment_reference, booking_type, booking_id, booking_reference,
                            payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                            payment_method, payment_type, payment_status, invoice_generated,
                            receipt_number, status, recorded_by
                        ) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'full_payment', 'completed', 1, ?, 'completed', ?)
                    ");
                    $insert_payment->execute([
                        $payment_reference,
                        $booking_id,
                        $booking_reference,
                        $vatParts['net'], // payment_amount is always the ex-VAT figure
                        $vatRate,
                        $vatAmount,
                        $totalWithVat,
                        $receipt_number,
                        $user['id']
                    ]);
                }

                // Write VAT columns to booking so recalculate has the correct base
                $pdo->prepare("
                    UPDATE bookings
                    SET vat_rate = ?, vat_amount = ?, total_with_vat = ?, last_payment_date = CURDATE()
                    WHERE id = ?
                ")->execute([$vatRate, $vatAmount, $totalWithVat, $booking_id]);

                // Sync amount_paid / amount_due / payment_status from payments table
                recalculateBookingFinancials((int)$booking_id);

                $message .= ' Payment recorded in accounting system.';

                if (($row['status'] ?? '') === 'confirmed' && empty($row['individual_room_id'])) {
                    $autoAssignResult = autoAssignConfirmedPaidBooking((int)$booking_id);
                    if ($autoAssignResult['success'] && !empty($autoAssignResult['assigned_room_number'])) {
                        $message .= ' Room ' . htmlspecialchars($autoAssignResult['assigned_room_number']) . ' auto-assigned.';
                    } elseif (!$autoAssignResult['success']) {
                        $message .= ' (Room auto-assignment skipped: ' . htmlspecialchars($autoAssignResult['message']) . ')';
                    }
                }

                // Send invoice email
                require_once '../config/invoice.php';
                $invoice_result = sendPaymentInvoiceEmail($booking_id);

                if ($invoice_result['success']) {
                    logBookingAudit((int)$booking_id, 'email_sent', null, null, 'Invoice emailed automatically on payment recorded', $booking_reference);
                    $message .= ' Invoice sent successfully!';
                } else {
                    error_log("Invoice email failed: " . $invoice_result['message']);
                    $message .= ' (Invoice email failed - check logs)';
                }

                // Send receipt email with PDF for this payment
                $bk_pay_id_stmt = $pdo->prepare("SELECT id FROM payments WHERE booking_type = 'room' AND booking_id = ? AND payment_type != 'refund' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
                $bk_pay_id_stmt->execute([$booking_id]);
                $bk_pay_id = (int)($bk_pay_id_stmt->fetchColumn() ?: 0);
                if ($bk_pay_id > 0) {
                    require_once '../config/receipts.php';
                    $receipt_result = receipt_auto_send($pdo, $bk_pay_id, $user);
                    if ($receipt_result['success']) {
                        $message .= ' Receipt emailed.';
                    }
                }
            }
        } elseif ($action === 'checkout') {
            // Checkout a checked-in booking
            $booking_id = intval($_POST['id'] ?? 0);
            if ($booking_id > 0) {
                // Get booking info
                $check_stmt = $pdo->prepare("SELECT id, status, room_id, individual_room_id, booking_reference, check_out_date FROM bookings WHERE id = ?");
                $check_stmt->execute([$booking_id]);
                $bk = $check_stmt->fetch(PDO::FETCH_ASSOC);

                // Use validation helper
                $validation = $bk ? validateCheckOut($bk) : ['allowed' => false, 'reason' => 'Booking not found'];

                if ($validation['allowed']) {
                    // Update status
                    $upd = $pdo->prepare("UPDATE bookings SET status = 'checked-out', updated_at = NOW() WHERE id = ?");
                    $upd->execute([$booking_id]);

                    // Restore room availability
                    $restore = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms");
                    $restore->execute([$bk['room_id']]);

                    updateBookingRoomsStatus(
                        $booking_id,
                        'cleaning',
                        'Checkout completed: ' . ($bk['booking_reference'] ?? ('Booking #' . $booking_id)),
                        $user['id'] ?? null
                    );

                    $message = 'Booking ' . htmlspecialchars($bk['booking_reference']) . ' checked out successfully. Room availability restored.';
                    rh_log_event('bookings', 'info', 'Guest checked out', ['booking_id' => $booking_id, 'ref' => $bk['booking_reference'], 'by' => $user['username'] ?? null]);
                    logBookingAudit($booking_id, 'checked-out', ['status' => 'checked-in'], ['status' => 'checked-out'], null, $bk['booking_reference'] ?? null);
                } else {
                    $error = getBookingActionErrorMessage('check_out', $validation['reason']);
                }
            }
        } elseif ($action === 'checkout_assess') {
            // ── Assess whether a checkout requires financial settlement ──
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            $booking_id = (int)($_POST['id'] ?? 0);
            if ($booking_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing booking id']);
                exit;
            }

            $assess_stmt = $pdo->prepare("
                SELECT b.*,
                       r.name AS room_type_name,
                       r.price_per_night, r.price_single_occupancy,
                       r.price_double_occupancy, r.price_triple_occupancy,
                       ir.room_number AS individual_room_number
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                WHERE b.id = ? AND b.status = 'checked-in'
            ");
            $assess_stmt->execute([$booking_id]);
            $abk = $assess_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$abk) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found or not checked-in']);
                exit;
            }

            $today_a            = new DateTime('today');
            $checkIn_a          = new DateTime((string)$abk['check_in_date']);
            $scheduledCheckoutA = new DateTime((string)$abk['check_out_date']);
            $checkIn_a->setTime(0, 0, 0);
            $scheduledCheckoutA->setTime(0, 0, 0);
            $actualNights = max(1, (int)$today_a->diff($checkIn_a)->days);
            $schedNights  = max(1, (int)$scheduledCheckoutA->diff($checkIn_a)->days);
            // positive = late checkout days, negative = early checkout days
            $nightDiff    = getSignedDateDiffDays($scheduledCheckoutA, $today_a);

            $occupancy_a  = $abk['occupancy_type'] ?? 'single';
            if ($occupancy_a === 'double' && !empty($abk['price_double_occupancy'])) {
                $ppn_a = (float)$abk['price_double_occupancy'];
            } elseif ($occupancy_a === 'triple' && !empty($abk['price_triple_occupancy'])) {
                $ppn_a = (float)$abk['price_triple_occupancy'];
            } elseif (!empty($abk['price_single_occupancy'])) {
                $ppn_a = (float)$abk['price_single_occupancy'];
            } else {
                $ppn_a = (float)($abk['price_per_night'] ?? 0);
            }

            $guest_name_a  = trim($abk['guest_name'] ?? '');
            $room_label_a  = trim($abk['individual_room_number'] ?? $abk['room_type_name'] ?? 'N/A');
            $check_in_fmt  = date('d M Y', strtotime($abk['check_in_date']));
            $sched_out_fmt = $scheduledCheckoutA->format('d M Y');

            if ($nightDiff === 0) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success'    => true,
                    'settlement' => ['needed' => false, 'type' => 'none'],
                ]);
                exit;
            }

            $adjAmount    = abs($nightDiff) * $ppn_a;
            $amountPaid_a = (float)($abk['amount_paid'] ?? 0);
            $amountDue_a  = (float)($abk['amount_due'] ?? 0);

            $baseFields = [
                'actual_nights'   => $actualNights,
                'sched_nights'    => $schedNights,
                'price_per_night' => $ppn_a,
                'amount_paid'     => $amountPaid_a,
                'amount_due'      => $amountDue_a,
                'total_amount'    => (float)$abk['total_amount'],
                'sched_checkout'  => $sched_out_fmt,
                'actual_checkout' => $today_a->format('d M Y'),
                'check_in_date'   => $check_in_fmt,
                'guest_name'      => $guest_name_a,
                'room_label'      => $room_label_a,
                'booking_ref'     => $abk['booking_reference'],
            ];

            if ($nightDiff > 0) {
                // Overdue: guest owes extra nights
                header('Content-Type: application/json');
                echo json_encode([
                    'success'    => true,
                    'settlement' => array_merge($baseFields, [
                        'needed'        => true,
                        'type'          => 'charge',
                        'extra_nights'  => $nightDiff,
                        'charge_amount' => $adjAmount,
                    ]),
                ]);
            } else {
                // Early: guest may be owed a refund for unused nights
                $unusedNights = abs($nightDiff);
                $refundable   = min($adjAmount, $amountPaid_a);
                header('Content-Type: application/json');
                echo json_encode([
                    'success'    => true,
                    'settlement' => array_merge($baseFields, [
                        'needed'        => true,
                        'type'          => 'refund',
                        'unused_nights' => $unusedNights,
                        'refund_amount' => $adjAmount,
                        'refundable'    => $refundable,
                    ]),
                ]);
            }
            exit;
        } elseif ($action === 'checkout_settle') {
            // ── Settle finances then perform checkout ──
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            $booking_id        = (int)($_POST['id'] ?? 0);
            $settlement_action = $_POST['settlement_action'] ?? 'skip'; // 'charge' | 'refund' | 'skip'
            $payment_method    = $_POST['payment_method'] ?? 'cash';
            $settle_notes      = trim($_POST['notes'] ?? '');

            if ($booking_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing booking id']);
                exit;
            }

            $settle_stmt = $pdo->prepare("
                SELECT b.*,
                       r.price_per_night, r.price_single_occupancy,
                       r.price_double_occupancy, r.price_triple_occupancy,
                       r.name as room_name
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ? AND b.status = 'checked-in'
            ");
            $settle_stmt->execute([$booking_id]);
            $sbk = $settle_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sbk) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found or not checked-in']);
                exit;
            }

            $today_s            = new DateTime('today');
            $todayStr_s         = $today_s->format('Y-m-d');
            $checkIn_s          = new DateTime((string)$sbk['check_in_date']);
            $scheduledCheckoutS = new DateTime((string)$sbk['check_out_date']);
            $checkIn_s->setTime(0, 0, 0);
            $scheduledCheckoutS->setTime(0, 0, 0);
            $actualNights_s = max(1, (int)$today_s->diff($checkIn_s)->days);
            $schedNights_s  = max(1, (int)$scheduledCheckoutS->diff($checkIn_s)->days);
            // positive = late checkout days, negative = early checkout days
            $nightDiff_s    = getSignedDateDiffDays($scheduledCheckoutS, $today_s);

            $occupancy_s = $sbk['occupancy_type'] ?? 'single';
            if ($occupancy_s === 'double' && !empty($sbk['price_double_occupancy'])) {
                $ppn_s = (float)$sbk['price_double_occupancy'];
            } elseif ($occupancy_s === 'triple' && !empty($sbk['price_triple_occupancy'])) {
                $ppn_s = (float)$sbk['price_triple_occupancy'];
            } elseif (!empty($sbk['price_single_occupancy'])) {
                $ppn_s = (float)$sbk['price_single_occupancy'];
            } else {
                $ppn_s = (float)($sbk['price_per_night'] ?? 0);
            }

            $pdo->beginTransaction();
            try {
                $settlementPaymentReference = null;

                if ($settlement_action === 'charge' && $nightDiff_s > 0) {
                    // Add folio charge for extra nights.
                    // ppn_s is the NET room rate; addBookingCharge expects a GROSS price,
                    // so convert to gross before passing.
                    $lco_vatEnabled = getSetting('vat_enabled') === '1';
                    $lco_vatRate    = $lco_vatEnabled ? (float)getSetting('vat_rate') : 0;
                    $ppn_gross_s    = $lco_vatRate > 0 ? round($ppn_s * (1 + $lco_vatRate / 100), 4) : $ppn_s;
                    $extraNights_s  = $nightDiff_s;
                    $chargeAmount_s = round($extraNights_s * $ppn_gross_s, 2);
                    $chargeDesc    = "Late checkout: {$extraNights_s} extra night(s) at {$currency_symbol} "
                        . number_format($ppn_s, 2) . "/night"
                        . ($settle_notes ? '. ' . $settle_notes : '');
                    $chargeResult  = addBookingCharge(
                        $booking_id,
                        'late_checkout',
                        $chargeDesc,
                        (float)$extraNights_s,
                        $ppn_gross_s,
                        null,
                        (int)($user['id'] ?? 0)
                    );
                    if (!$chargeResult['success']) {
                        throw new Exception($chargeResult['message'] ?? 'Failed to add late-checkout charge');
                    }
                    // Extend booking checkout date to reflect actual stay
                    $pdo->prepare("
                        UPDATE bookings SET check_out_date = ?, number_of_nights = ?, updated_at = NOW() WHERE id = ?
                    ")->execute([$todayStr_s, $actualNights_s, $booking_id]);
                    // Recalculate so amount_due reflects the new folio charge
                    recalculateBookingFinancials($booking_id);

                    // Record immediate payment for extra-night settlement.
                    $paymentRefBase = 'PAY-EC-' . date('Y') . '-' . str_pad((string)$booking_id, 6, '0', STR_PAD_LEFT);
                    $settlementPaymentReference = $paymentRefBase;
                    $paymentRefSuffix = 1;
                    $paymentRefCheck = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE payment_reference = ?');
                    $paymentRefCheck->execute([$settlementPaymentReference]);
                    while ((int)$paymentRefCheck->fetchColumn() > 0) {
                        $paymentRefSuffix++;
                        $settlementPaymentReference = $paymentRefBase . '-' . $paymentRefSuffix;
                        $paymentRefCheck->execute([$settlementPaymentReference]);
                    }

                    $settlementReceipt = finance_next_receipt_number($pdo, $todayStr_s);
                    $settlementNotes = 'Overdue checkout settlement: ' . $extraNights_s . ' extra night(s)';
                    if ($settle_notes !== '') {
                        $settlementNotes .= '. ' . $settle_notes;
                    }

                    $pdo->prepare("\n                        INSERT INTO payments (\n                            payment_reference, booking_type, booking_id, booking_reference,\n                            payment_date, payment_amount, vat_rate, vat_amount, total_amount,\n                            payment_method, payment_type, payment_status, invoice_generated,\n                            receipt_number, status, notes, recorded_by\n                        ) VALUES (?, 'room', ?, ?, CURDATE(), ?, 0, 0, ?, ?, 'partial_payment', 'completed', 1, ?, 'completed', ?, ?)\n                    ")->execute([
                        $settlementPaymentReference,
                        $booking_id,
                        $sbk['booking_reference'],
                        $chargeAmount_s,
                        $chargeAmount_s,
                        $payment_method,
                        $settlementReceipt,
                        $settlementNotes,
                        (int)($user['id'] ?? 0),
                    ]);
                    $settlement_pay_id = (int)$pdo->lastInsertId();

                    // Re-sync booking financials after settlement payment insert.
                    recalculateBookingFinancials($booking_id);
                } elseif ($settlement_action === 'refund' && $nightDiff_s < 0) {
                    // Issue refund for unused nights
                    $unusedNights_s = abs($nightDiff_s);
                    $refundAmt      = min($unusedNights_s * $ppn_s, (float)($sbk['amount_paid'] ?? 0));

                    if ($refundAmt > 0) {
                        // Generate unique refund reference
                        do {
                            $refundRef_s = 'RFD-EC-' . strtoupper(substr(uniqid(), -8));
                            $refChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
                            $refChk->execute([$refundRef_s]);
                        } while ((int)$refChk->fetchColumn() > 0);

                        $refundReason = "Early checkout: {$unusedNights_s} unused night(s) at {$currency_symbol} "
                            . number_format($ppn_s, 2) . "/night"
                            . ($settle_notes ? '. ' . $settle_notes : '');

                        $vatEnabled_s = getSetting('vat_enabled') === '1';
                        $vatRate_s    = $vatEnabled_s ? (float)getSetting('vat_rate') : 0;
                        $vatAmt_s     = round($refundAmt * ($vatRate_s / (100 + $vatRate_s)), 2);
                        $netAmt_s     = $refundAmt - $vatAmt_s;

                        $pdo->prepare("
                            INSERT INTO payments (
                                payment_reference, booking_type, booking_id, booking_reference,
                                payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                                payment_method, payment_type, payment_status,
                                refund_reason, refund_status, refund_amount,
                                recorded_by, created_at
                            ) VALUES (
                                ?, 'room', ?, ?, ?,
                                ?, ?, ?, ?,
                                ?, 'refund', 'completed',
                                ?, 'completed', ?,
                                ?, NOW()
                            )
                        ")->execute([
                            $refundRef_s,
                            $booking_id,
                            $sbk['booking_reference'],
                            $todayStr_s,
                            $netAmt_s,
                            $vatRate_s,
                            $vatAmt_s,
                            $refundAmt,
                            $payment_method,
                            $refundReason,
                            $refundAmt,
                            (int)($user['id'] ?? 0),
                        ]);
                    }
                    // Shorten booking to reflect actual stay
                    $pdo->prepare("
                        UPDATE bookings SET check_out_date = ?, number_of_nights = ?, updated_at = NOW() WHERE id = ?
                    ")->execute([$todayStr_s, $actualNights_s, $booking_id]);
                    // Recalculate so amount_due reflects the refund
                    recalculateBookingFinancials($booking_id);
                }
                // 'skip' → no financial record; just proceed

                // ── Perform the actual checkout ──
                $pdo->prepare("UPDATE bookings SET status = 'checked-out', updated_at = NOW() WHERE id = ?")
                    ->execute([$booking_id]);

                $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")
                    ->execute([$sbk['room_id']]);

                updateBookingRoomsStatus(
                    $booking_id,
                    'cleaning',
                    'Checkout completed (' . $settlement_action . '): ' . ($sbk['booking_reference'] ?? ('Booking #' . $booking_id)),
                    (int)($user['id'] ?? 0)
                );

                rh_log_event('bookings', 'info', 'Guest checked out (settlement: ' . $settlement_action . ')', [
                    'booking_id'  => $booking_id,
                    'ref'         => $sbk['booking_reference'],
                    'settlement'  => $settlement_action,
                    'night_diff'  => $nightDiff_s,
                    'ppn'         => $ppn_s,
                    'by'          => $user['username'] ?? null,
                ]);
                logBookingAudit(
                    $booking_id,
                    'checked-out',
                    ['status' => 'checked-in'],
                    ['status' => 'checked-out', 'settlement' => $settlement_action],
                    null,
                    $sbk['booking_reference'] ?? null
                );

                $pdo->commit();

                // Send receipt email if an extra-night settlement payment was recorded
                if (!empty($settlement_pay_id)) {
                    try {
                        require_once '../config/receipts.php';
                        receipt_auto_send($pdo, $settlement_pay_id, $user);
                    } catch (Throwable $rcptEx) {
                        error_log('Receipt email failed for settlement payment ' . $settlement_pay_id . ': ' . $rcptEx->getMessage());
                    }
                }

                header('Content-Type: application/json');
                $checkoutMessage = 'Checkout completed successfully.';
                if (!empty($settlementPaymentReference)) {
                    $checkoutMessage .= ' Extra-night payment recorded (' . $settlementPaymentReference . ').';
                }
                echo json_encode(['success' => true, 'message' => $checkoutMessage]);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("checkout_settle error: " . $e->getMessage());
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Checkout failed: ' . $e->getMessage()]);
                exit;
            }
        } elseif ($action === 'consolidation_fetch') {
            // ── Return full financial snapshot for a booking ──
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            $booking_id = (int)($_POST['id'] ?? 0);
            if ($booking_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing booking id']);
                exit;
            }

            $cf_stmt = $pdo->prepare("
                SELECT b.*,
                       r.name AS room_type_name,
                       ir.room_number AS individual_room_number
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                WHERE b.id = ?
            ");
            $cf_stmt->execute([$booking_id]);
            $cf_bk = $cf_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cf_bk) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found']);
                exit;
            }

            // Active folio charges
            $charges_stmt = $pdo->prepare("
                SELECT description, charge_type, quantity, unit_price, line_total, posted_at
                FROM booking_charges
                WHERE booking_id = ? AND voided = 0
                ORDER BY posted_at ASC
            ");
            $charges_stmt->execute([$booking_id]);
            $cf_charges = $charges_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Completed payments (excluding refunds)
            $pays_stmt = $pdo->prepare("
                SELECT payment_reference, payment_date, total_amount, payment_method, payment_type, notes
                FROM payments
                WHERE booking_type = 'room' AND booking_id = ?
                  AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund'
                  AND deleted_at IS NULL
                ORDER BY payment_date ASC, id ASC
            ");
            $pays_stmt->execute([$booking_id]);
            $cf_pays = $pays_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Refunds
            $refunds_stmt = $pdo->prepare("
                SELECT payment_reference, payment_date, total_amount, payment_method, refund_reason
                FROM payments
                WHERE booking_type = 'room' AND booking_id = ?
                  AND payment_type = 'refund'
                  AND deleted_at IS NULL
                ORDER BY payment_date ASC, id ASC
            ");
            $refunds_stmt->execute([$booking_id]);
            $cf_refunds = $refunds_stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'booking' => [
                    'id'               => (int)$cf_bk['id'],
                    'reference'        => $cf_bk['booking_reference'],
                    'guest_name'       => $cf_bk['guest_name'],
                    'room_type'        => $cf_bk['room_type_name'] ?? '',
                    'room_number'      => $cf_bk['individual_room_number'] ?? '',
                    'check_in'         => date('d M Y', strtotime($cf_bk['check_in_date'])),
                    'check_out'        => date('d M Y', strtotime($cf_bk['check_out_date'])),
                    'nights'           => (int)$cf_bk['number_of_nights'],
                    'status'           => $cf_bk['status'],
                    'payment_status'   => $cf_bk['payment_status'],
                    'total_amount'     => (float)$cf_bk['total_amount'],
                    'total_with_vat'   => (float)($cf_bk['total_with_vat'] ?? $cf_bk['total_amount']),
                    'folio_charges'    => (float)($cf_bk['folio_charges_total'] ?? 0),
                    'amount_paid'      => (float)($cf_bk['amount_paid'] ?? 0),
                    'amount_due'       => (float)($cf_bk['amount_due'] ?? 0),
                ],
                'charges'  => $cf_charges,
                'payments' => $cf_pays,
                'refunds'  => $cf_refunds,
            ]);
            exit;
        } elseif ($action === 'consolidation_record') {
            // ── Record a manual consolidation payment ──
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            $booking_id     = (int)($_POST['id'] ?? 0);
            $pay_amount     = (float)($_POST['amount'] ?? 0);
            $pay_method     = $_POST['payment_method'] ?? 'cash';
            $pay_type       = $_POST['payment_type'] ?? 'partial_payment'; // partial_payment | full_payment | adjustment
            $pay_date       = $_POST['payment_date'] ?? date('Y-m-d');
            $notes          = trim($_POST['notes'] ?? '');
            $acct_notes     = trim($_POST['accountant_notes'] ?? '');
            $combined_notes = $notes . ($acct_notes ? ' | ACCT: ' . $acct_notes : '');

            if ($booking_id <= 0 || $pay_amount <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
                exit;
            }

            // Validate date
            $payDateObj = DateTime::createFromFormat('Y-m-d', $pay_date);
            if (!$payDateObj) {
                $pay_date = date('Y-m-d');
            }

            $cr_stmt = $pdo->prepare("SELECT booking_reference, guest_name, status FROM bookings WHERE id = ?");
            $cr_stmt->execute([$booking_id]);
            $cr_bk = $cr_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cr_bk) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found']);
                exit;
            }

            $vatEnabled_cr = getSetting('vat_enabled') === '1';
            $vatRate_cr    = $vatEnabled_cr ? (float)getSetting('vat_rate') : 0;
            $vatAmt_cr     = round($pay_amount * ($vatRate_cr / (100 + $vatRate_cr)), 2);
            $netAmt_cr     = $pay_amount - $vatAmt_cr;

            // Unique reference
            do {
                $payRef_cr = 'CON-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
                $refChk_cr = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
                $refChk_cr->execute([$payRef_cr]);
            } while ((int)$refChk_cr->fetchColumn() > 0);

            $pdo->beginTransaction();
            $receipt_number_cr = finance_next_receipt_number($pdo, $pay_date);

            $pdo->prepare("
                INSERT INTO payments (
                    payment_reference, booking_type, booking_id, booking_reference,
                    payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status,
                    receipt_number, notes, recorded_by, created_at
                ) VALUES (?, 'room', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, NOW())
            ")->execute([
                $payRef_cr,
                $booking_id,
                $cr_bk['booking_reference'],
                $pay_date,
                $netAmt_cr,
                $vatRate_cr,
                $vatAmt_cr,
                $pay_amount,
                $pay_method,
                $pay_type,
                $receipt_number_cr,
                $combined_notes ?: null,
                (int)($user['id'] ?? 0),
            ]);
            $consolidation_pay_id = (int)$pdo->lastInsertId();

            recalculateBookingFinancials($booking_id);
            $pdo->commit();

            // Send receipt email for this consolidation payment
            if ($consolidation_pay_id > 0) {
                try {
                    require_once '../config/receipts.php';
                    receipt_auto_send($pdo, $consolidation_pay_id, $user);
                } catch (Throwable $rcptEx) {
                    error_log('Receipt email failed for consolidation payment ' . $consolidation_pay_id . ': ' . $rcptEx->getMessage());
                }
            }

            // Fetch updated figures to return to JS
            $upd_stmt = $pdo->prepare("SELECT amount_paid, amount_due, payment_status FROM bookings WHERE id = ?");
            $upd_stmt->execute([$booking_id]);
            $upd = $upd_stmt->fetch(PDO::FETCH_ASSOC);

            rh_log_event('bookings', 'info', 'Manual consolidation payment recorded', [
                'booking_id' => $booking_id,
                'ref'        => $cr_bk['booking_reference'],
                'amount'     => $pay_amount,
                'method'     => $pay_method,
                'type'       => $pay_type,
                'by'         => $user['username'] ?? null,
            ]);
            logBookingAudit(
                $booking_id,
                'payment_consolidation',
                ['amount_paid' => null],
                ['amount_paid' => $pay_amount, 'payment_reference' => $payRef_cr],
                $combined_notes ?: 'Manual consolidation by ' . ($user['full_name'] ?? ($user['username'] ?? '')),
                $cr_bk['booking_reference']
            );

            header('Content-Type: application/json');
            echo json_encode([
                'success'          => true,
                'message'          => 'Payment of ' . $currency_symbol . ' ' . number_format($pay_amount, 2) . ' recorded successfully.',
                'payment_reference' => $payRef_cr,
                'new_amount_paid'  => (float)($upd['amount_paid'] ?? 0),
                'new_amount_due'   => (float)($upd['amount_due'] ?? 0),
                'new_payment_status' => $upd['payment_status'] ?? '',
            ]);
            exit;
        } elseif ($action === 'noshow') {
            // Mark a confirmed booking as no-show
            $booking_id = intval($_POST['id'] ?? 0);
            $noshow_note = trim((string)($_POST['checkin_note'] ?? ''));
            if ($booking_id > 0) {
                // Get full booking info needed for refund + email
                $check_stmt = $pdo->prepare("
                    SELECT b.*, r.name as room_name
                    FROM bookings b
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE b.id = ?
                ");
                $check_stmt->execute([$booking_id]);
                $bk = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if ($bk) {
                    $todayNoShow = new DateTime('today');
                    $bookingCheckIn = new DateTime((string)($bk['check_in_date'] ?? 'today'));
                    $bookingCheckIn->setTime(0, 0, 0);
                    $daysOverdue = $bookingCheckIn < $todayNoShow ? (int)$bookingCheckIn->diff($todayNoShow)->days : 0;

                    if ($bookingCheckIn >= $todayNoShow) {
                        $error = 'No-show can only be marked after the check-in date has passed.';
                    } else {
                        // Validate transition to no-show
                        $transitionValidation = validateBookingStatusTransition($bk['status'], 'no-show');
                        if (!$transitionValidation['allowed']) {
                            $error = getBookingActionErrorMessage('noshow', $transitionValidation['reason']);
                        } else {
                            // Update status to no-show
                            $upd = $pdo->prepare("UPDATE bookings SET status = 'no-show', updated_at = NOW() WHERE id = ?");
                            $upd->execute([$booking_id]);

                            // Restore room availability (was decremented at confirmation)
                            $restore = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms");
                            $restore->execute([$bk['room_id']]);

                            updateBookingRoomsStatus(
                                $booking_id,
                                'available',
                                'Marked no-show: ' . ($bk['booking_reference'] ?? ('Booking #' . $booking_id)),
                                $user['id'] ?? null
                            );

                            $message = 'Booking ' . htmlspecialchars($bk['booking_reference']) . ' marked as No-Show. Room availability restored.';
                            $noshowEventContext = [
                                'booking_id' => $booking_id,
                                'ref' => $bk['booking_reference'],
                                'scheduled_check_in' => $bk['check_in_date'] ?? null,
                                'days_overdue' => $daysOverdue,
                                'by' => $user['username'] ?? null,
                            ];
                            if ($noshow_note !== '') {
                                $noshowEventContext['note'] = $noshow_note;
                            }
                            rh_log_event('bookings', 'warning', 'Booking marked no-show', $noshowEventContext);

                            $noshowAuditNote = $noshow_note !== '' ? ('No-show note: ' . $noshow_note) : null;
                            if ($daysOverdue > 0) {
                                $noshowDescriptor = 'No-show marked ' . $daysOverdue . ' day(s) after check-in date';
                                $noshowAuditNote = $noshowAuditNote !== null
                                    ? ($noshowDescriptor . ' - ' . $noshowAuditNote)
                                    : $noshowDescriptor;
                            }
                            logBookingAudit($booking_id, 'no-show', ['status' => $bk['status']], ['status' => 'no-show'], $noshowAuditNote, $bk['booking_reference'] ?? null);

                            // Auto-refund — DB-only, fast
                            $ns_refund = ['created' => false, 'refund_ref' => '', 'refund_amount' => 0.0];
                            if ((float)($bk['amount_paid'] ?? 0) > 0) {
                                require_once __DIR__ . '/../includes/booking-functions.php';
                                $ns_refund = createNoShowRefund($bk, (int)($user['id'] ?? 0), $pdo);
                            }
                            if ($ns_refund['created']) {
                                $message .= ' Pending refund ' . $ns_refund['refund_ref']
                                    . ' (' . getSetting('currency_symbol', 'MWK') . ' ' . number_format((float)$ns_refund['refund_amount'], 2) . ') queued.';
                            }
                        }
                    }
                } else {
                    $error = 'Booking not found.';
                }
            }

            if (isAjaxRequest()) {
                header('Content-Type: application/json');
                if (!empty($error)) {
                    echo json_encode(['success' => false, 'message' => $error]);
                    exit;
                }
                echo json_encode(['success' => true, 'message' => $message ?? 'Booking marked as no-show.']);

                // Flush response to the browser NOW so the loader clears immediately.
                // Email sending (SMTP) can block for 30-300s on a slow/unreachable server;
                // by flushing first the UI is unblocked regardless of email outcome.
                if (ob_get_level()) { ob_end_flush(); }
                flush();
                if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

                // Send guest no-show email after the connection is closed
                if (!isset($error) && !empty($bk['guest_email'])) {
                    require_once __DIR__ . '/../config/email.php';
                    sendNoShowEmail(
                        $bk,
                        (float)($ns_refund['refund_amount'] ?? 0.0),
                        (string)($ns_refund['refund_ref'] ?? '')
                    );
                }
                exit;
            }
        } elseif ($action === 'get_booking_details') {
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }

            $booking_id = (int)($_POST['booking_id'] ?? 0);
            if ($booking_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Missing booking ID'
                ]);
                exit;
            }

            // Fetch booking details with room and individual room info
            $stmt = $pdo->prepare("
                SELECT b.*,
                    r.name as room_name,
                    COALESCE(p.payment_status, b.payment_status) as actual_payment_status,
                    p.payment_reference,
                    ir.room_number as individual_room_number,
                    ir.room_name as individual_room_name,
                    ir.floor as individual_room_floor,
                    ir.status as individual_room_status
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                LEFT JOIN payments p ON b.id = p.booking_id AND p.booking_type = 'room' AND p.status = 'completed'
                LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Booking not found'
                ]);
                exit;
            }

            // Format dates and amounts
            $booking['check_in_date_formatted'] = date('M j, Y', strtotime($booking['check_in_date']));
            $booking['check_out_date_formatted'] = date('M j, Y', strtotime($booking['check_out_date']));
            $booking['created_at_formatted'] = date('M j, Y H:i', strtotime($booking['created_at']));
            $booking['total_formatted'] = number_format($booking['total_amount'], 2);
            $booking['status_label'] = ucfirst($booking['status']);
            $booking['payment_status_label'] = ucfirst($booking['actual_payment_status'] ?? $booking['payment_status']);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => $booking
            ]);
            exit;
        } elseif ($action === 'get_all_room_types_for_upgrade') {
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }

            $current_room_id = (int)($_POST['current_room_id'] ?? 0);
            $check_in = trim($_POST['check_in'] ?? '');
            $check_out = trim($_POST['check_out'] ?? '');

            // Fetch all active room types that are more expensive than current
            $stmt = $pdo->prepare("
                SELECT r.*,
                       (SELECT COUNT(*) FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1 AND ir.status IN ('available', 'cleaning')) as available_count
                FROM rooms r
                WHERE r.is_active = 1
                AND r.id != ?
                AND r.price_per_night > (SELECT price_per_night FROM rooms WHERE id = ?)
                ORDER BY r.price_per_night ASC
            ");
            $stmt->execute([$current_room_id, $current_room_id]);
            $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Filter rooms that have availability for the dates
            $availableRooms = [];
            foreach ($rooms as $room) {
                // Check if room type has availability
                $hasAvailability = checkRoomAvailability($room['id'], $check_in, $check_out);
                if ($hasAvailability['available']) {
                    $availableRooms[] = [
                        'id' => (int)$room['id'],
                        'name' => $room['name'],
                        'price_per_night' => (float)$room['price_per_night'],
                        'available_count' => (int)($room['available_count'] ?? 0)
                    ];
                }
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => $availableRooms
            ]);
            exit;
        } elseif ($action === 'extend_stay') {
            // Extend the checkout date for a checked-in booking
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            $booking_id   = (int)($_POST['booking_id'] ?? 0);
            $new_checkout = trim($_POST['new_checkout'] ?? '');

            if ($booking_id <= 0 || !$new_checkout) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }

            // Validate date format
            $newCheckoutDt = DateTime::createFromFormat('Y-m-d', $new_checkout);
            if (!$newCheckoutDt) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid date format']);
                exit;
            }

            // Fetch booking
            $bk_stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'checked-in'");
            $bk_stmt->execute([$booking_id]);
            $bk = $bk_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$bk) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found or not checked-in']);
                exit;
            }

            $oldCheckout = $bk['check_out_date'];
            $checkIn     = $bk['check_in_date'];

            // New checkout must be after check-in
            $checkInDt = new DateTime($checkIn);
            if ($newCheckoutDt <= $checkInDt) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'New checkout date must be after check-in date']);
                exit;
            }

            // New checkout must be strictly after the current checkout date (actually extending)
            $oldCheckoutDt = new DateTime($oldCheckout);
            if ($newCheckoutDt <= $oldCheckoutDt) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'New checkout date must be after the current checkout date']);
                exit;
            }

            // New checkout must not be in the past
            $todayDt = new DateTime('today');
            if ($newCheckoutDt < $todayDt) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'New checkout date cannot be in the past']);
                exit;
            }

            // Recalculate nights and total
            $newNights = (int)$newCheckoutDt->diff($checkInDt)->days;
            if ($newNights < 1) $newNights = 1;

            // Get room price
            $room_stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
            $room_stmt->execute([$bk['room_id']]);
            $room = $room_stmt->fetch(PDO::FETCH_ASSOC);

            // Use occupancy-based price
            $occupancy = $bk['occupancy_type'] ?? 'single';
            if ($occupancy === 'double' && !empty($room['price_double_occupancy'])) {
                $pricePerNight = (float)$room['price_double_occupancy'];
            } elseif ($occupancy === 'triple' && !empty($room['price_triple_occupancy'])) {
                $pricePerNight = (float)$room['price_triple_occupancy'];
            } elseif (!empty($room['price_single_occupancy'])) {
                $pricePerNight = (float)$room['price_single_occupancy'];
            } else {
                $pricePerNight = (float)$room['price_per_night'];
            }

            $childGuests    = (int)($bk['child_guests'] ?? 0);
            $childMultiplier = (float)($bk['child_price_multiplier'] ?? 50);
            $baseAmount     = $pricePerNight * $newNights;
            $childSupplement = $childGuests > 0 ? ($pricePerNight * ($childMultiplier / 100) * $childGuests * $newNights) : 0;
            $newTotal       = $baseAmount + $childSupplement;
            // Recompute VAT and gross total to keep total_with_vat accurate
            $extVatRate      = (float)($bk['vat_rate'] ?? 0);
            $extVatAmount    = $extVatRate > 0 ? round($newTotal * ($extVatRate / 100), 2) : 0.0;
            $extTotalWithVat = round($newTotal + $extVatAmount, 2);

            // Atomicity: lock the room-type row, then run the conflict check + update in
            // one transaction so a concurrent booking or extend can't slip into the newly
            // extended nights between the check and the write (overbooking).
            $pdo->beginTransaction();
            $pdo->prepare("SELECT id FROM rooms WHERE id = ? FOR UPDATE")->execute([$bk['room_id']]);

            // Check for conflicts — fetch actual booking details so the error is actionable
            $blockingStatuses = getBookingStatusesThatBlockAvailability(false);
            $placeholders = implode(',', array_fill(0, count($blockingStatuses), '?'));
            $conflict_stmt = $pdo->prepare(
                "SELECT booking_reference, guest_name, check_in_date, check_out_date, status
                 FROM bookings
                 WHERE room_id = ? AND id != ? AND status IN ({$placeholders})
                 AND NOT (check_out_date <= ? OR check_in_date >= ?)
                 ORDER BY check_in_date ASC
                 LIMIT 3"
            );
            $conflict_stmt->execute(array_merge(
                [$bk['room_id'], $booking_id],
                $blockingStatuses,
                [$oldCheckout, $new_checkout]
            ));
            $conflictRows = $conflict_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($conflictRows)) {
                $pdo->rollBack();
                $details = array_map(function ($c) {
                    $in  = date('d M Y', strtotime($c['check_in_date']));
                    $out = date('d M Y', strtotime($c['check_out_date']));
                    return sprintf('%s — %s (%s to %s, %s)',
                        $c['booking_reference'], $c['guest_name'], $in, $out, ucfirst($c['status']));
                }, $conflictRows);
                $msg = 'Cannot extend: the following booking' . (count($conflictRows) > 1 ? 's conflict' : ' conflicts') . ' with the new dates: ' . implode('; ', $details);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $msg, 'conflicts' => $conflictRows]);
                exit;
            }

            // Update booking — include VAT columns so recalculate sees the new gross total
            $upd_stmt = $pdo->prepare("
                UPDATE bookings
                SET check_out_date = ?,
                    number_of_nights = ?,
                    total_amount = ?,
                    child_supplement_total = ?,
                    vat_amount = ?,
                    total_with_vat = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $upd_stmt->execute([$new_checkout, $newNights, $newTotal, $childSupplement, $extVatAmount, $extTotalWithVat, $booking_id]);

            // Recalculate amount_due so it reflects the new total correctly
            recalculateBookingFinancials($booking_id);
            $pdo->commit();

            // Log the extension (never fatal — must not undo the successful update)
            bookings_log_status_change(
                $pdo,
                $booking_id,
                'checked-in',
                'checked-in',
                $user['id'] ?? null,
                "Stay extended from {$oldCheckout} to {$new_checkout}. New total: K " . number_format($newTotal, 2)
            );

            header('Content-Type: application/json');
            echo json_encode([
                'success'      => true,
                'message'      => 'Stay extended successfully to ' . date('M j, Y', strtotime($new_checkout)),
                'new_checkout' => $new_checkout,
                'new_nights'   => $newNights,
                'new_total'    => number_format($newTotal, 2)
            ]);
            exit;
        } elseif ($action === 'admin_update_checkout_date') {
            // ── Admin-only: manually correct a checked-in booking's checkout date ──
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            if (!$_is_admin_user) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Administrator access required']);
                exit;
            }
            $booking_id    = (int)($_POST['booking_id'] ?? 0);
            $new_checkout  = trim($_POST['new_checkout'] ?? '');
            $change_reason = trim($_POST['reason'] ?? '');

            if ($booking_id <= 0 || !$new_checkout) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }
            $newCoDt = DateTime::createFromFormat('Y-m-d', $new_checkout);
            if (!$newCoDt) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid date format']);
                exit;
            }

            $acd_stmt = $pdo->prepare("SELECT b.*, r.name as room_type_name, r.price_per_night, r.price_single_occupancy, r.price_double_occupancy, r.price_triple_occupancy FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ? AND b.status = 'checked-in'");
            $acd_stmt->execute([$booking_id]);
            $acd_bk = $acd_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$acd_bk) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found or not checked-in']);
                exit;
            }

            $checkInDt_acd = new DateTime($acd_bk['check_in_date']);
            if ($newCoDt <= $checkInDt_acd) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'New checkout date must be after check-in date']);
                exit;
            }

            $newNights_acd = max(1, (int)$newCoDt->diff($checkInDt_acd)->days);

            $occ_acd = $acd_bk['occupancy_type'] ?? 'single';
            if ($occ_acd === 'double' && !empty($acd_bk['price_double_occupancy'])) {
                $ppn_acd = (float)$acd_bk['price_double_occupancy'];
            } elseif ($occ_acd === 'triple' && !empty($acd_bk['price_triple_occupancy'])) {
                $ppn_acd = (float)$acd_bk['price_triple_occupancy'];
            } elseif (!empty($acd_bk['price_single_occupancy'])) {
                $ppn_acd = (float)$acd_bk['price_single_occupancy'];
            } else {
                $ppn_acd = (float)($acd_bk['price_per_night'] ?? 0);
            }

            $childGuests_acd    = (int)($acd_bk['child_guests'] ?? 0);
            $childMult_acd      = (float)($acd_bk['child_price_multiplier'] ?? 50);
            $roomBase_acd       = $ppn_acd * $newNights_acd;
            $childSupp_acd      = $childGuests_acd > 0
                ? ($ppn_acd * ($childMult_acd / 100) * $childGuests_acd * $newNights_acd)
                : 0;

            // Tourism levy on (room + child) — matches api/bookings.php formula
            $levyPct_acd  = (float)($acd_bk['tourism_levy_percent'] ?? 0);
            $levyAmt_acd  = $levyPct_acd > 0
                ? round(($roomBase_acd + $childSupp_acd) * ($levyPct_acd / 100), 2)
                : 0;

            // total_amount = room + child + levy (no VAT), matching booking creation
            $newTotal_acd = $roomBase_acd + $childSupp_acd + $levyAmt_acd;

            // VAT applied on top of total_amount (includes levy), matching create-booking.php
            $vatRate_acd      = (float)($acd_bk['vat_rate'] ?? 0);
            $vatAmt_acd       = $vatRate_acd > 0 ? round($newTotal_acd * ($vatRate_acd / 100), 2) : 0;
            $totalWithVat_acd = round($newTotal_acd + $vatAmt_acd, 2);

            $oldCheckout_acd = $acd_bk['check_out_date'];

            $pdo->prepare("
                UPDATE bookings
                SET check_out_date = ?, number_of_nights = ?,
                    total_amount = ?, child_supplement_total = ?,
                    vat_amount = ?, total_with_vat = ?,
                    tourism_levy_amount = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$new_checkout, $newNights_acd, $newTotal_acd, $childSupp_acd, $vatAmt_acd, $totalWithVat_acd, $levyAmt_acd, $booking_id]);

            recalculateBookingFinancials($booking_id);

            $logReason = "Admin checkout date change: {$oldCheckout_acd} → {$new_checkout} ({$newNights_acd} nights)"
                . ($change_reason ? '. Reason: ' . $change_reason : '');
            bookings_log_status_change($pdo, $booking_id, 'checked-in', 'checked-in', $user['id'] ?? null, $logReason);

            rh_log_event('bookings', 'warning', 'Admin changed checkout date', [
                'booking_id'   => $booking_id,
                'ref'          => $acd_bk['booking_reference'],
                'old_checkout' => $oldCheckout_acd,
                'new_checkout' => $new_checkout,
                'new_nights'   => $newNights_acd,
                'new_total'    => $newTotal_acd,
                'reason'       => $change_reason,
                'by'           => $user['username'] ?? null,
            ]);

            header('Content-Type: application/json');
            echo json_encode([
                'success'      => true,
                'message'      => 'Checkout date updated to ' . date('M j, Y', strtotime($new_checkout)) . ". ({$newNights_acd} nights, total recalculated.)",
                'new_checkout' => $new_checkout,
                'new_nights'   => $newNights_acd,
                'new_total'    => number_format($totalWithVat_acd, 2),
            ]);
            exit;
        } elseif ($action === 'upgrade_room_type') {
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }

            $booking_id  = (int)($_POST['booking_id'] ?? 0);
            $new_room_id = (int)($_POST['new_room_id'] ?? 0);
            $send_email  = isset($_POST['send_email']) && $_POST['send_email'] === '1';

            if ($booking_id <= 0 || $new_room_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid booking or room selection']);
                exit;
            }

            // Get booking details
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as old_room_name, r.price_per_night as old_price_per_night
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found']);
                exit;
            }

            // Get new room details
            $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
            $stmt->execute([$new_room_id]);
            $new_room = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$new_room) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'New room type not found']);
                exit;
            }

            // Check if booking can be upgraded (only confirmed or pending bookings)
            if (!in_array($booking['status'], ['pending', 'confirmed'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Only pending or confirmed bookings can be upgraded']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                // Calculate new total based on new room price
                $nights = max(1, (int)$booking['number_of_nights']);
                $new_price_per_night = (float)$new_room['price_per_night'];
                $old_total = (float)$booking['total_amount'];
                $new_total = $new_price_per_night * $nights;

                // Calculate child supplement if applicable
                $child_supplement = 0.0;
                if (!empty($booking['child_guests']) && $booking['child_guests'] > 0) {
                    $child_multiplier = (float)($new_room['child_price_multiplier'] ?? 50);
                    $child_supplement = ($new_price_per_night * ($child_multiplier / 100) * $booking['child_guests'] * $nights);
                    $new_total += $child_supplement;
                }

                // Calculate price difference
                $price_difference = $new_total - $old_total;

                // Update booking
                $update_stmt = $pdo->prepare("
                    UPDATE bookings
                    SET room_id = ?,
                        total_amount = ?,
                        child_price_multiplier = ?,
                        child_supplement_total = ?
                    WHERE id = ?
                ");
                $update_stmt->execute([
                    $new_room_id,
                    $new_total,
                    $new_room['child_price_multiplier'] ?? 50,
                    $child_supplement,
                    $booking_id
                ]);

                // Handle individual room reassignment
                $room_reassigned = false;
                $assigned_room_number = '';
                if (!empty($booking['individual_room_id'])) {
                    // Check if current individual room is compatible with new room type
                    $ir_stmt = $pdo->prepare("SELECT room_type_id, room_number FROM individual_rooms WHERE id = ?");
                    $ir_stmt->execute([$booking['individual_room_id']]);
                    $current_ir = $ir_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($current_ir && (int)$current_ir['room_type_id'] !== $new_room_id) {
                        // Current individual room doesn't match new room type, try to auto-assign
                        $autoAssignResult = autoAssignIndividualRoom($booking_id);
                        if ($autoAssignResult['success']) {
                            $room_reassigned = true;
                            $assigned_room_number = $autoAssignResult['assigned_room_number'];
                        } else {
                            // No available room, clear individual_room_id
                            $clear_stmt = $pdo->prepare("UPDATE bookings SET individual_room_id = NULL WHERE id = ?");
                            $clear_stmt->execute([$booking_id]);

                            // Release old individual room
                            if ($booking['status'] === 'confirmed') {
                                updateBookingRoomsStatus(
                                    $booking_id,
                                    'available',
                                    'Room type upgraded, room released',
                                    $user['id'] ?? null
                                );
                            }
                        }
                    }
                }

                // Log the upgrade (non-fatal — the table is ensured up front so
                // this normally succeeds; a logging error must not roll back the
                // upgrade that already applied within this transaction).
                bookings_log_status_change(
                    $pdo,
                    $booking_id,
                    $booking['status'],
                    $booking['status'],
                    $user['id'] ?? null,
                    "Room type upgraded from {$booking['room_id']} ({$booking['old_room_name']}) to {$new_room_id} ({$new_room['name']}). Price difference: K " . number_format($price_difference, 2)
                );

                $pdo->commit();

                // Send upgrade email if requested
                $email_sent = false;
                $email_message = '';
                if ($send_email) {
                    require_once '../config/email.php';
                    $booking['old_room_name'] = $booking['old_room_name'];
                    $booking['new_room_name'] = $new_room['name'];
                    $booking['old_total'] = $old_total;
                    $booking['new_total'] = $new_total;
                    $booking['price_difference'] = $price_difference;
                    $email_result = sendBookingRoomUpgradeEmail($booking);
                    $email_sent = $email_result['success'];
                    $email_message = $email_result['message'];
                }

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Room type upgraded successfully' .
                        ($room_reassigned ? ". New room {$assigned_room_number} assigned." : '') .
                        ($email_sent ? ' Upgrade email sent.' : ''),
                    'data' => [
                        'new_total' => $new_total,
                        'price_difference' => $price_difference,
                        'room_reassigned' => $room_reassigned,
                        'assigned_room_number' => $assigned_room_number,
                        'email_sent' => $email_sent,
                        'email_message' => $email_message
                    ]
                ]);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Upgrade room type error: " . $e->getMessage());
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Error upgrading room type: ' . $e->getMessage()]);
                exit;
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            // Return 200 OK with success: false so frontend can handle the error message gracefully
            // without browser console errors or fetch rejection
            http_response_code(200);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        $error = 'Error: ' . $e->getMessage();
    }
}

// Shared filter inputs (used by on-screen table and CSV export)
$search_query = trim($_GET['search'] ?? '');
// Accept ?status= as an alias for ?filter_status= so dashboard insight cards and
// stat tiles that deep-link with ?status=checked-in land on the matching tab
// (server-filtered + tab activated + row flash), not just the unfiltered list.
$filter_status = $_GET['filter_status'] ?? ($_GET['status'] ?? '');
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$has_active_room_filters = $search_query !== '' || $filter_status !== '' || $filter_date_from !== '' || $filter_date_to !== '';

// Map ?filter= shortcuts (from dashboard badge links) to JS tab names
$_tab_map = [
    'checkin_today'  => 'today-checkins',
    'checkout_today' => 'today-checkouts',
    'checked_in'     => 'checked-in',
    'expiring_soon'  => 'expiring-soon',
    'paid'           => 'paid',
    'unpaid'         => 'unpaid',
    'today_bookings' => 'today-bookings',
    'week_bookings'  => 'week-bookings',
    'month_bookings' => 'month-bookings',
];
$active_tab_override = $_tab_map[trim($_GET['filter'] ?? '')] ?? '';
// Status-based tabs: detect from filter_status (tab names match filter_status values directly)
if ($active_tab_override === '' && $filter_status !== '') {
    $active_tab_override = $filter_status;
}

// Pagination params
$current_page = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 10;

// Pre-initialise stats in case the main query throws
$total_bookings = $pending = $tentative = $confirmed = $checked_in = 0;
$checked_out = $cancelled = $no_show = $paid = $unpaid = 0;
$today_checkins = $today_checkouts = $today_bookings = 0;
$week_bookings = $month_bookings = $expiring_soon = 0;
$list_count = 0;
$current_page_count = 0;
$total_pages = 1;
$offset      = 0;
$bookings_stats_insights = [];

// Handle CSV export (filter-aware)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $export_where_clauses = [];
        $export_params = [];

        if (!empty($search_query)) {
            $export_where_clauses[] = "(
                b.booking_reference LIKE ?
                OR b.guest_name LIKE ?
                OR b.guest_email LIKE ?
                OR b.guest_phone LIKE ?
                OR b.guest_country LIKE ?
                OR b.special_requests LIKE ?
                OR r.name LIKE ?
                OR COALESCE(ir.room_number, '') LIKE ?
                OR COALESCE(ir.room_name, '') LIKE ?
                OR EXISTS (
                    SELECT 1
                    FROM payments px
                    WHERE px.booking_type = 'room'
                      AND px.booking_id = b.id
                      AND px.deleted_at IS NULL
                      AND (
                        px.payment_reference LIKE ?
                        OR px.payment_method LIKE ?
                        OR px.payment_status LIKE ?
                      )
                )
            )";
            $search_param = "%{$search_query}%";
            $export_params = array_merge($export_params, array_fill(0, 12, $search_param));
        }

        if (!empty($filter_status)) {
            $export_where_clauses[] = "b.status = ?";
            $export_params[] = $filter_status;
        }

        if (!empty($filter_date_from)) {
            $export_where_clauses[] = "b.check_in_date >= ?";
            $export_params[] = $filter_date_from;
        }

        if (!empty($filter_date_to)) {
            $export_where_clauses[] = "b.check_out_date <= ?";
            $export_params[] = $filter_date_to;
        }

        $export_where_sql = !empty($export_where_clauses) ? 'WHERE ' . implode(' AND ', $export_where_clauses) : '';

        $export_stmt = $pdo->prepare("
                        SELECT b.booking_reference, b.guest_name, b.guest_email, b.guest_phone, b.guest_country,
                                     r.name as room_name, ir.room_number as individual_room_number, ir.room_name as individual_room_name,
                                     b.check_in_date, b.check_out_date, b.number_of_nights,
                                     b.number_of_guests, b.total_amount, b.status, b.payment_status, b.occupancy_type,
                                     b.special_requests, b.created_at,
                                     (
                                         SELECT px.payment_reference
                                         FROM payments px
                                         WHERE px.booking_type = 'room' AND px.booking_id = b.id AND px.deleted_at IS NULL
                                         ORDER BY px.created_at DESC
                                         LIMIT 1
                                     ) as payment_reference
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
                        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                        {$export_where_sql}
            ORDER BY b.created_at DESC
        ");
        $export_stmt->execute($export_params);
        $export_data = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="bookings-export-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Reference',
            'Guest Name',
            'Email',
            'Phone',
            'Country',
            'Room',
            'Room Number',
            'Room Unit Name',
            'Check-in',
            'Check-out',
            'Nights',
            'Guests',
            'Total',
            'Status',
            'Payment',
            'Payment Ref',
            'Occupancy',
            'Special Requests',
            'Created'
        ]);

        foreach ($export_data as $row) {
            fputcsv($output, [
                $row['booking_reference'],
                $row['guest_name'],
                $row['guest_email'],
                $row['guest_phone'],
                $row['guest_country'],
                $row['room_name'],
                $row['individual_room_number'],
                $row['individual_room_name'],
                $row['check_in_date'],
                $row['check_out_date'],
                $row['number_of_nights'],
                $row['number_of_guests'],
                $row['total_amount'],
                $row['status'],
                $row['payment_status'],
                $row['payment_reference'],
                $row['occupancy_type'],
                $row['special_requests'],
                $row['created_at']
            ]);
        }

        fclose($output);
        exit;
    } catch (PDOException $e) {
        $error = 'Export failed: ' . $e->getMessage();
    }
}

// ─── Auto-expire tentative bookings whose deadline has passed ────────────
$page_expired_count = 0;
try {
    $expired_list = getExpiredTentativeBookings();
    foreach ($expired_list as $eb) {
        if (markTentativeBookingExpired((int)$eb['id'])) {
            $page_expired_count++;
        }
    }
    if ($page_expired_count > 0) {
        rh_log_event(
            'bookings_page',
            'info',
            "Auto-expired {$page_expired_count} tentative booking(s) on page load",
            []
        );
    }
} catch (Throwable $e) {
    error_log("Tentative sweep on bookings page failed: " . $e->getMessage());
}

// Fetch all bookings with room details and payment status from payments table
try {
    $where_clauses = [];
    $params = [];

    if (!empty($search_query)) {
        $where_clauses[] = "(
            b.booking_reference LIKE ?
            OR b.guest_name LIKE ?
            OR b.guest_email LIKE ?
            OR b.guest_phone LIKE ?
            OR b.guest_country LIKE ?
            OR b.special_requests LIKE ?
            OR r.name LIKE ?
            OR COALESCE(ir.room_number, '') LIKE ?
            OR COALESCE(ir.room_name, '') LIKE ?
            OR EXISTS (
                SELECT 1
                FROM payments px
                WHERE px.booking_type = 'room'
                  AND px.booking_id = b.id
                  AND px.deleted_at IS NULL
                  AND (
                    px.payment_reference LIKE ?
                    OR px.payment_method LIKE ?
                    OR px.payment_status LIKE ?
                  )
            )
        )";
        $search_param = "%{$search_query}%";
        $params = array_merge($params, array_fill(0, 12, $search_param));
    }

    // Snapshot where clauses for insights (respects search/date filters but not status filter)
    // so insight cards show filtered data matching the current search/date scope.
    $insights_where_clauses = $where_clauses;
    $insights_params        = $params;

    // Stats badge counts must respect search and date filters so badge counts match displayed results,
    // but exclude status filter so all tabs show accurate counts for the current filters.
    $stats_where_clauses = $where_clauses;
    $stats_params        = $params;

    if (!empty($filter_status)) {
        $where_clauses[] = "b.status = ?";
        $params[] = $filter_status;
    }

    if (!empty($filter_date_from)) {
        $where_clauses[] = "b.check_in_date >= ?";
        $params[]        = $filter_date_from;
    }

    if (!empty($filter_date_to)) {
        $where_clauses[] = "b.check_out_date <= ?";
        $params[]        = $filter_date_to;
    }

    // Overdue arrivals (no-show candidates): booking whose arrival date has
    // passed while still confirmed/pending — i.e. never checked in. Surfaced
    // from the Room Dashboard no-show alert.
    if (($_GET['arrival'] ?? '') === 'overdue') {
        $where_clauses[] = "b.check_in_date < CURDATE() AND b.status IN ('confirmed','pending')";
    }

    $where_sql       = !empty($where_clauses)       ? 'WHERE ' . implode(' AND ', $where_clauses)       : '';
    $stats_where_sql = !empty($stats_where_clauses) ? 'WHERE ' . implode(' AND ', $stats_where_clauses) : '';

    // ── Stats: single query for all tab-badge counts (no payments join to avoid row duplication) ──
    // Uses $stats_where_sql which includes search/date filters but excludes filter_status so badges
    // show accurate counts for the current search/date scope regardless of which tab is active.
    $stats_stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                                                      AS total,
            SUM(b.status = 'pending')                                                                     AS pending,
            SUM(b.status = 'tentative')                                                                  AS tentative,
            SUM(b.status = 'confirmed')                                                                   AS confirmed,
            SUM(b.status = 'checked-in')                                                                  AS checked_in,
            SUM(b.status = 'checked-out')                                                                 AS checked_out,
            SUM(b.status = 'cancelled')                                                                   AS cancelled,
            SUM(b.status = 'no-show')                                                                     AS no_show,
            SUM(b.payment_status IN ('paid','completed'))                                                  AS paid,
            SUM(b.payment_status NOT IN ('paid','completed'))                                              AS unpaid,
            SUM(b.status = 'confirmed' AND b.check_in_date = CURDATE())                                   AS today_checkins,
            SUM(b.status = 'checked-in' AND b.check_out_date = CURDATE())                                 AS today_checkouts,
            SUM(DATE(b.created_at) = CURDATE())                                                           AS today_bookings,
            SUM(b.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))                                          AS week_bookings,
            SUM(DATE_FORMAT(b.created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m'))                           AS month_bookings,
            SUM(b.status = 'tentative' AND b.tentative_expires_at IS NOT NULL
                AND b.tentative_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR))           AS expiring_soon
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
        LEFT JOIN rooms rt ON ir.room_type_id = rt.id
        {$stats_where_sql}
    ");
    $stats_stmt->execute($stats_params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    $total_bookings  = (int)($stats['total']          ?? 0);
    $pending         = (int)($stats['pending']         ?? 0);
    $tentative       = (int)($stats['tentative']       ?? 0);
    $confirmed       = (int)($stats['confirmed']       ?? 0);
    $checked_in      = (int)($stats['checked_in']      ?? 0);
    $checked_out     = (int)($stats['checked_out']     ?? 0);
    $cancelled       = (int)($stats['cancelled']       ?? 0);
    $no_show         = (int)($stats['no_show']         ?? 0);
    $paid            = (int)($stats['paid']            ?? 0);
    $unpaid          = (int)($stats['unpaid']          ?? 0);
    $today_checkins  = (int)($stats['today_checkins']  ?? 0);
    $today_checkouts = (int)($stats['today_checkouts'] ?? 0);
    $today_bookings  = (int)($stats['today_bookings']  ?? 0);
    $week_bookings   = (int)($stats['week_bookings']   ?? 0);
    $month_bookings  = (int)($stats['month_bookings']  ?? 0);
    $expiring_soon   = (int)($stats['expiring_soon']   ?? 0);

    $bookings_stats_insight_limit = 8;

    $build_bookings_stats_link = static function (array $query_params): string {
        $query_string = http_build_query($query_params);
        return 'bookings.php' . ($query_string !== '' ? '?' . $query_string : '');
    };

    $map_bookings_stats_row = static function (array $row): array {
        $individual_room_name = trim((string)($row['individual_room_name'] ?? ''));
        $individual_room_number = trim((string)($row['individual_room_number'] ?? ''));
        $room_name = trim((string)($row['room_name'] ?? ''));

        if ($individual_room_name !== '' && $individual_room_number !== '') {
            $room_label = $individual_room_name . ' (' . $individual_room_number . ')';
        } elseif ($individual_room_name !== '') {
            $room_label = $individual_room_name;
        } elseif ($individual_room_number !== '') {
            $room_label = 'Room ' . $individual_room_number;
        } elseif ($room_name !== '') {
            $room_label = $room_name;
        } else {
            $room_label = 'Unassigned';
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'reference' => (string)($row['booking_reference'] ?? ''),
            'guest_name' => (string)($row['guest_name'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'room' => $room_label,
            'check_in' => (string)($row['check_in_date'] ?? ''),
            'check_out' => (string)($row['check_out_date'] ?? ''),
            'amount' => (float)($row['total_amount'] ?? 0),
            'payment_status' => (string)($row['payment_status'] ?? ''),
            'details_href' => 'booking-details.php?id=' . (int)($row['id'] ?? 0),
        ];
    };

    $fetch_bookings_stats_rows = static function (array $extra_clauses, array $extra_params, string $order_by) use ($pdo, $insights_where_clauses, $insights_params, $bookings_stats_insight_limit, $map_bookings_stats_row): array {
        $insight_where_clauses = array_merge($insights_where_clauses, $extra_clauses);
        $insight_where_sql = !empty($insight_where_clauses) ? 'WHERE ' . implode(' AND ', $insight_where_clauses) : '';
        $insight_params = array_merge($insights_params, $extra_params);

        $insight_stmt = $pdo->prepare("
            SELECT b.id,
                   b.booking_reference,
                   b.guest_name,
                   b.status,
                   b.check_in_date,
                   b.check_out_date,
                   b.total_amount,
                   b.payment_status,
                   r.name AS room_name,
                   ir.room_name AS individual_room_name,
                   ir.room_number AS individual_room_number
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
            {$insight_where_sql}
            ORDER BY {$order_by}
            LIMIT ?
        ");

        $insight_stmt->execute(array_merge($insight_params, [$bookings_stats_insight_limit]));
        $rows = $insight_stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($map_bookings_stats_row, $rows);
    };

    $insight_base_query = [];
    if ($search_query !== '') {
        $insight_base_query['search'] = $search_query;
    }
    if ($filter_date_from !== '') {
        $insight_base_query['date_from'] = $filter_date_from;
    }
    if ($filter_date_to !== '') {
        $insight_base_query['date_to'] = $filter_date_to;
    }

    $bookings_stats_rows = [
        'total_bookings' => $fetch_bookings_stats_rows([], [], 'b.created_at DESC'),
        'pending' => $fetch_bookings_stats_rows(["b.status = ?"], ['pending'], 'b.check_in_date ASC, b.created_at DESC'),
        'tentative' => $fetch_bookings_stats_rows(["b.status = ?"], ['tentative'], "COALESCE(b.tentative_expires_at, '9999-12-31 23:59:59') ASC, b.created_at DESC"),
        'confirmed' => $fetch_bookings_stats_rows(["b.status = ?"], ['confirmed'], 'b.check_in_date ASC, b.created_at DESC'),
        'checked_in' => $fetch_bookings_stats_rows(["b.status = ?"], ['checked-in'], 'b.check_out_date ASC, b.created_at DESC'),
    ];

    $bookings_stats_insights = [
        'total_bookings' => [
            'title' => 'Total Bookings',
            'subtitle' => 'Latest bookings in the current view scope.',
            'count' => $total_bookings,
            'summary' => 'Shows newest reservations so operations can triage active booking flow quickly.',
            'empty' => 'No bookings found in the current search/date scope.',
            'rows' => $bookings_stats_rows['total_bookings'],
            'link' => [
                'href' => $build_bookings_stats_link($insight_base_query),
                'label' => 'Open bookings list',
            ],
        ],
        'pending' => [
            'title' => 'Pending Bookings',
            'subtitle' => 'Bookings waiting for confirmation or follow-up.',
            'count' => $pending,
            'summary' => 'Prioritize these guests to avoid drop-offs and improve conversion.',
            'empty' => 'No pending bookings in the current search/date scope.',
            'rows' => $bookings_stats_rows['pending'],
            'link' => [
                'href' => $build_bookings_stats_link(array_merge($insight_base_query, ['filter_status' => 'pending'])),
                'label' => 'Open pending bookings',
            ],
        ],
        'tentative' => [
            'title' => 'Tentative Bookings',
            'subtitle' => 'Tentative reservations with expiry risk.',
            'count' => $tentative,
            'summary' => 'Review tentative bookings and secure payment before they expire.',
            'empty' => 'No tentative bookings in the current search/date scope.',
            'rows' => $bookings_stats_rows['tentative'],
            'link' => [
                'href' => $build_bookings_stats_link(array_merge($insight_base_query, ['filter_status' => 'tentative'])),
                'label' => 'Open tentative bookings',
            ],
        ],
        'confirmed' => [
            'title' => 'Confirmed Bookings',
            'subtitle' => 'Arrivals expected to check in soon.',
            'count' => $confirmed,
            'summary' => 'Use this queue for pre-arrival preparation and room readiness checks.',
            'empty' => 'No confirmed bookings in the current search/date scope.',
            'rows' => $bookings_stats_rows['confirmed'],
            'link' => [
                'href' => $build_bookings_stats_link(array_merge($insight_base_query, ['filter_status' => 'confirmed'])),
                'label' => 'Open confirmed bookings',
            ],
        ],
        'checked_in' => [
            'title' => 'Checked-In Guests',
            'subtitle' => 'Guests currently staying in-house.',
            'count' => $checked_in,
            'summary' => 'Track in-house guests and upcoming checkouts from one focused list.',
            'empty' => 'No checked-in guests in the current search/date scope.',
            'rows' => $bookings_stats_rows['checked_in'],
            'link' => [
                'href' => $build_bookings_stats_link(array_merge($insight_base_query, ['filter_status' => 'checked-in'])),
                'label' => 'Open checked-in guests',
            ],
        ],
    ];

    // Build list-specific WHERE and ORDER BY — server-side constraints for all tab shortcuts.
    // Stats query above stays unfiltered so badge counts remain accurate.
    $tab_extra_clauses = [];
    $list_order_by = 'b.created_at DESC';

    if ($active_tab_override === 'today-checkins') {
        $tab_extra_clauses[] = "b.check_in_date = CURDATE()";
        $tab_extra_clauses[] = "b.status = 'confirmed'";
        $list_order_by = 'b.check_in_date ASC, b.created_at DESC';
    } elseif ($active_tab_override === 'today-checkouts') {
        $tab_extra_clauses[] = "b.check_out_date = CURDATE()";
        $tab_extra_clauses[] = "b.status = 'checked-in'";
        $list_order_by = 'b.check_out_date ASC, b.created_at DESC';
    } elseif ($active_tab_override === 'expiring-soon') {
        $tab_extra_clauses[] = "b.status = 'tentative'";
        $tab_extra_clauses[] = "b.tentative_expires_at IS NOT NULL";
        $tab_extra_clauses[] = "b.tentative_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)";
        $list_order_by = 'b.tentative_expires_at ASC';
    } elseif ($active_tab_override === 'paid') {
        $tab_extra_clauses[] = "b.payment_status IN ('paid', 'completed')";
    } elseif ($active_tab_override === 'unpaid') {
        $tab_extra_clauses[] = "b.payment_status NOT IN ('paid', 'completed')";
    } elseif ($active_tab_override === 'today-bookings') {
        $tab_extra_clauses[] = "DATE(b.created_at) = CURDATE()";
    } elseif ($active_tab_override === 'week-bookings') {
        $tab_extra_clauses[] = "b.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($active_tab_override === 'month-bookings') {
        $tab_extra_clauses[] = "DATE_FORMAT(b.created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')";
    }
    // Status-based tabs (pending, tentative, confirmed, checked-in, checked-out, cancelled, no-show)
    // are already handled by $filter_status → $where_clauses — no extra clauses needed.

    $list_where_clauses = array_merge($where_clauses, $tab_extra_clauses);
    $list_where_sql = !empty($list_where_clauses) ? 'WHERE ' . implode(' AND ', $list_where_clauses) : '';

    // Re-count for pagination when the list is filtered by tab override, status filter, search, or date filters.
    // Use a separate variable so $total_bookings remains the global count for the "All" tab badge.
    $list_count = $total_bookings;
    if (!empty($tab_extra_clauses) || $filter_status !== '' || $search_query !== '' || $filter_date_from !== '' || $filter_date_to !== '') {
        $cnt_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
            {$list_where_sql}
        ");
        $cnt_stmt->execute($params);
        $list_count = (int)$cnt_stmt->fetchColumn();
    }

    // Clamp current page to valid range and compute offset
    $total_pages  = $list_count > 0 ? (int)ceil($list_count / $per_page) : 1;
    $current_page = min($current_page, $total_pages);
    $offset       = ($current_page - 1) * $per_page;

    $stmt = $pdo->prepare("
        SELECT b.*,
               r.name as room_name,
               COALESCE(
                   (SELECT px.payment_status FROM payments px
                    WHERE px.booking_type = 'room' AND px.booking_id = b.id AND px.deleted_at IS NULL
                    ORDER BY px.created_at DESC LIMIT 1),
                   b.payment_status
               ) as actual_payment_status,
               (SELECT px.payment_reference FROM payments px
                WHERE px.booking_type = 'room' AND px.booking_id = b.id AND px.deleted_at IS NULL
                ORDER BY px.created_at DESC LIMIT 1) as payment_reference,
               (SELECT px.payment_date FROM payments px
                WHERE px.booking_type = 'room' AND px.booking_id = b.id AND px.deleted_at IS NULL
                ORDER BY px.created_at DESC LIMIT 1) as last_payment_date,
               ir.room_number as individual_room_number,
               ir.room_name as individual_room_name,
               ir.floor as individual_room_floor,
               ir.status as individual_room_status,
               rt.name as room_type_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
        LEFT JOIN rooms rt ON ir.room_type_id = rt.id
        {$list_where_sql}
        ORDER BY {$list_order_by}
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $current_page_count = count($bookings);

    // Also fetch conference inquiries
    $conf_stmt = $pdo->query("
        SELECT * FROM conference_inquiries
        ORDER BY created_at DESC
    ");
    $conference_inquiries = $conf_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Error fetching bookings: ' . $e->getMessage();
    $bookings = [];
    $conference_inquiries = [];
    $bookings_stats_insights = [];
}

// Statistics are computed by the DB stats query inside the main try block above.

// ─── Missed check-ins: confirmed bookings whose check-in date has PASSED ───
$missed_checkins = [];
try {
    $missed_stmt = $pdo->query("
        SELECT b.*, r.name as room_name,
               COALESCE(
                   (SELECT px.payment_status
                    FROM payments px
                    WHERE px.booking_type = 'room' AND px.booking_id = b.id AND px.deleted_at IS NULL
                    ORDER BY px.created_at DESC
                    LIMIT 1),
                   b.payment_status
               ) AS actual_payment_status,
               ir.room_number as individual_room_number,
               ir.room_name as individual_room_name,
               DATEDIFF(CURDATE(), b.check_in_date) as days_overdue
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
        WHERE b.status = 'confirmed'
          AND b.check_in_date < CURDATE()
        ORDER BY b.check_in_date ASC
    ");
    $missed_checkins = $missed_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching missed check-ins: " . $e->getMessage());
}

// ─── Overdue checkouts: checked-in bookings whose checkout date has PASSED (or today and past checkout time) ───
$overdue_checkouts = [];
$is_past_checkout_time = false; // pre-initialised; set inside try block below
try {
    $checkout_time = getSetting('check_out_time', '11:00'); // Assuming standard 11:00 checkout
    $current_time = date('H:i');
    $is_past_checkout_time = ($current_time >= $checkout_time);

    $date_condition = $is_past_checkout_time
        ? "b.check_out_date <= CURDATE()"
        : "b.check_out_date < CURDATE()";

    // Only genuinely checked-in guests can be overdue on checkout. The
    // status = 'checked-in' gate inherently excludes no-show, cancelled and
    // confirmed-but-never-arrived bookings — those are surfaced as missed
    // check-ins above, never as late checkouts. No-show takes precedence over
    // any time-based checkout logic.
    $overdue_stmt = $pdo->query("
        SELECT b.*, r.name as room_name,
               ir.room_number as individual_room_number,
               ir.room_name as individual_room_name,
               DATEDIFF(CURDATE(), b.check_out_date) as days_overdue
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
        WHERE b.status = 'checked-in'
          AND {$date_condition}
        ORDER BY b.check_out_date ASC
    ");
    $overdue_checkouts = $overdue_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching overdue checkouts: " . $e->getMessage());
}


// $today_str is needed for table row rendering (overdue check-in flag)
$today     = new DateTime();
$today_str = $today->format('Y-m-d');
// $today_checkins, $today_checkouts, $today_bookings, $week_bookings,
// $month_bookings are already set by the DB stats query above.
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
    <title>All Bookings - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/bookings.css?v=20260528a">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>
    <script>
        // CSRF-aware fetch shim — re-installed on every SPA navigation.
        // window._rhCsrf is set by admin-header.php inside #rh-admin-page so it is
        // always the current session token, even after SPA navigation from a page
        // that does not have its own fetch interceptor (e.g. dashboard.php).
        (function() {
            if (!window.__rhBkOrigFetch) window.__rhBkOrigFetch = window.fetch;
            window.fetch = function(u, o) {
                if (o && o.body instanceof FormData && !o.body.has('csrf_token')) {
                    o.body.append('csrf_token', window._rhCsrf || '');
                }
                return window.__rhBkOrigFetch.apply(this, arguments);
            };
        })();
    </script>

    <div class="content">
        <div class="stats-grid">
            <div class="stat-card bookings-stats-insight-card js-bookings-stat-insight" data-stats-card="total_bookings" role="button" tabindex="0" aria-label="Open total bookings insight">
                <h3>Total Bookings</h3>
                <div class="number"><?php echo $total_bookings; ?></div>
                <span class="bookings-stats-insight-card__hint"><i class="fas fa-chart-line"></i> View insight</span>
            </div>
            <div class="stat-card pending bookings-stats-insight-card js-bookings-stat-insight" data-stats-card="pending" role="button" tabindex="0" aria-label="Open pending bookings insight">
                <h3>Pending</h3>
                <div class="number"><?php echo $pending; ?></div>
                <span class="bookings-stats-insight-card__hint"><i class="fas fa-list-check"></i> View insight</span>
            </div>
            <div class="stat-card tentative bookings-stats-insight-card js-bookings-stat-insight" data-stats-card="tentative" role="button" tabindex="0" aria-label="Open tentative bookings insight">
                <h3>Tentative</h3>
                <div class="number"><?php echo $tentative; ?></div>
                <span class="bookings-stats-insight-card__hint"><i class="fas fa-hourglass-half"></i> View insight</span>
            </div>
            <div class="stat-card confirmed bookings-stats-insight-card js-bookings-stat-insight" data-stats-card="confirmed" role="button" tabindex="0" aria-label="Open confirmed bookings insight">
                <h3>Confirmed</h3>
                <div class="number"><?php echo $confirmed; ?></div>
                <span class="bookings-stats-insight-card__hint"><i class="fas fa-circle-check"></i> View insight</span>
            </div>
            <div class="stat-card checked-in bookings-stats-insight-card js-bookings-stat-insight" data-stats-card="checked_in" role="button" tabindex="0" aria-label="Open checked in bookings insight">
                <h3>Checked In</h3>
                <div class="number"><?php echo $checked_in; ?></div>
                <span class="bookings-stats-insight-card__hint"><i class="fas fa-bed"></i> View insight</span>
            </div>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <!-- Missed Check-ins Alert Banner -->
        <?php if (!empty($missed_checkins)): ?>
            <div class="alert-banner bookings-alert-banner bookings-alert-banner--danger" role="status" aria-live="polite">
                <div class="bookings-alert-banner__icon" aria-hidden="true">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="bookings-alert-banner__copy">
                    <h3 class="bookings-alert-banner__title">Missed Check-ins!</h3>
                    <p class="bookings-alert-banner__text"><?php echo count($missed_checkins); ?> confirmed bookings have passed their check-in date without checking in.</p>
                    <p class="bookings-alert-banner__helper"><i class="fas fa-circle-info" aria-hidden="true"></i><span>Helper: Open details to review each booking and update arrival status immediately.</span></p>
                </div>
                <button class="btn btn-secondary bookings-alert-banner__action" type="button" onclick="openMissedCheckinsModal()">
                    <i class="fas fa-eye" aria-hidden="true"></i>
                    <span>View Details</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Overdue Checkouts Alert Banner -->
        <?php if (!empty($overdue_checkouts)): ?>
            <div class="alert-banner bookings-alert-banner bookings-alert-banner--warning" role="status" aria-live="polite">
                <div class="bookings-alert-banner__icon" aria-hidden="true">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="bookings-alert-banner__copy">
                    <h3 class="bookings-alert-banner__title">Overdue Checkouts!</h3>
                    <p class="bookings-alert-banner__text"><?php echo count($overdue_checkouts); ?> checked-in guests have passed their checkout date.</p>
                    <p class="bookings-alert-banner__helper"><i class="fas fa-circle-info" aria-hidden="true"></i><span>Helper: Open details to process checkout updates and release room availability.</span></p>
                </div>
                <button class="btn btn-secondary bookings-alert-banner__action" type="button" onclick="openOverdueCheckoutsModal()">
                    <i class="fas fa-eye" aria-hidden="true"></i>
                    <span>View Details</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Search & Tools Bar -->
        <div class="bookings-toolbar">
            <form method="GET" data-live-search-form="room-bookings" class="bookings-toolbar__form">
                <div class="bookings-toolbar__search">
                    <i class="fas fa-search bookings-toolbar__search-icon" aria-hidden="true"></i>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>"
                        data-live-search-input="room-bookings"
                        placeholder="Search ref, guest, phone, room no, payment ref..."
                        class="bookings-toolbar__input bookings-toolbar__input--search"
                        data-help="Search Bookings|Search by booking reference, guest name, phone number, room number, or payment reference. Results update automatically as you type.">
                </div>
                <select name="filter_status" class="bookings-toolbar__input bookings-toolbar__input--status">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="tentative" <?php echo $filter_status === 'tentative' ? 'selected' : ''; ?>>Tentative</option>
                    <option value="confirmed" <?php echo $filter_status === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="checked-in" <?php echo $filter_status === 'checked-in' ? 'selected' : ''; ?>>Checked In</option>
                    <option value="checked-out" <?php echo $filter_status === 'checked-out' ? 'selected' : ''; ?>>Checked Out</option>
                    <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="no-show" <?php echo $filter_status === 'no-show' ? 'selected' : ''; ?>>No-Show</option>
                </select>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>"
                    placeholder="From" title="Check-in from"
                    class="bookings-toolbar__input bookings-toolbar__input--date">
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>"
                    placeholder="To" title="Check-out to"
                    class="bookings-toolbar__input bookings-toolbar__input--date">
                <button type="submit" class="bookings-toolbar__button bookings-toolbar__button--filter btn btn-primary">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <?php if ($has_active_room_filters): ?>
                    <a href="bookings.php" class="bookings-toolbar__button bookings-toolbar__button--clear btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>
            <div class="bookings-toolbar__actions">
                <a href="<?php echo htmlspecialchars('bookings.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="bookings-toolbar__action bookings-toolbar__action--export">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <a href="tentative-bookings.php" class="bookings-toolbar__action bookings-toolbar__action--tentative">
                    <i class="fas fa-hourglass-half"></i> Tentative<?php if ($tentative > 0): ?> <span class="bookings-toolbar__badge"><?php echo $tentative; ?></span><?php endif; ?>
                </a>
                <a href="create-booking.php" class="bookings-toolbar__action bookings-toolbar__action--new" data-help="New Booking|Open the full reservation form to create a new room booking for a guest.">
                    <i class="fas fa-plus"></i> New Booking
                </a>
            </div>
        </div>

        <div id="booking-results" data-active-tab="<?php echo htmlspecialchars($active_tab_override ?: 'all', ENT_QUOTES); ?>" data-admin-pagination-scope data-flash-scope>
            <?php if ($has_active_room_filters): ?>
                <div style="background: #eef3ff; border: 1px solid #cfd8ff; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px; color: #1f2d6b;">
                        <i class="fas fa-filter"></i>
                        <strong>Search filtered results</strong>
                    </div>
                    <div style="color: #2f3a63; font-size: 13px;">
                        Showing <?php echo number_format($list_count); ?> matching room booking<?php echo $list_count === 1 ? '' : 's'; ?><?php if ($search_query !== ''): ?> for &ldquo;<?php echo htmlspecialchars($search_query); ?>&rdquo;<?php endif; ?>.
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <div class="tabs-container">
                <div class="tabs-header">
                    <button class="tab-button <?php echo $active_tab_override === '' ? 'active' : ''; ?>" data-tab="all" data-count="<?php echo $total_bookings; ?>">
                        <i class="fas fa-list"></i>
                        All
                        <span class="tab-count"><?php echo $total_bookings; ?></span>
                    </button>
                    <button class="tab-button" data-tab="pending" data-count="<?php echo $pending; ?>">
                        <i class="fas fa-clock"></i>
                        Pending
                        <span class="tab-count"><?php echo $pending; ?></span>
                    </button>
                    <button class="tab-button" data-tab="tentative" data-count="<?php echo $tentative; ?>">
                        <i class="fas fa-hourglass-half"></i>
                        Tentative
                        <span class="tab-count"><?php echo $tentative; ?></span>
                    </button>
                    <button class="tab-button" data-tab="expiring-soon" data-count="<?php echo $expiring_soon; ?>">
                        <i class="fas fa-exclamation-triangle"></i>
                        Expiring Soon
                        <span class="tab-count"><?php echo $expiring_soon; ?></span>
                    </button>
                    <button class="tab-button" data-tab="confirmed" data-count="<?php echo $confirmed; ?>">
                        <i class="fas fa-check-circle"></i>
                        Confirmed
                        <span class="tab-count"><?php echo $confirmed; ?></span>
                    </button>
                    <button class="tab-button" data-tab="today-checkins" data-count="<?php echo $today_checkins; ?>">
                        <i class="fas fa-calendar-day"></i>
                        Today's Check-ins
                        <span class="tab-count"><?php echo $today_checkins; ?></span>
                    </button>
                    <button class="tab-button" data-tab="today-checkouts" data-count="<?php echo $today_checkouts; ?>">
                        <i class="fas fa-calendar-times"></i>
                        Today's Check-outs
                        <span class="tab-count"><?php echo $today_checkouts; ?></span>
                    </button>
                    <button class="tab-button" data-tab="checked-in" data-count="<?php echo $checked_in; ?>">
                        <i class="fas fa-sign-in-alt"></i>
                        Checked In
                        <span class="tab-count"><?php echo $checked_in; ?></span>
                    </button>
                    <button class="tab-button" data-tab="checked-out" data-count="<?php echo $checked_out; ?>">
                        <i class="fas fa-sign-out-alt"></i>
                        Checked Out
                        <span class="tab-count"><?php echo $checked_out; ?></span>
                    </button>
                    <button class="tab-button" data-tab="cancelled" data-count="<?php echo $cancelled; ?>">
                        <i class="fas fa-times-circle"></i>
                        Cancelled
                        <span class="tab-count"><?php echo $cancelled; ?></span>
                    </button>
                    <button class="tab-button" data-tab="no-show" data-count="<?php echo $no_show; ?>">
                        <i class="fas fa-user-slash"></i>
                        No-Show
                        <span class="tab-count"><?php echo $no_show; ?></span>
                    </button>
                    <button class="tab-button" data-tab="paid" data-count="<?php echo $paid; ?>">
                        <i class="fas fa-dollar-sign"></i>
                        Paid
                        <span class="tab-count"><?php echo $paid; ?></span>
                    </button>
                    <button class="tab-button" data-tab="unpaid" data-count="<?php echo $unpaid; ?>">
                        <i class="fas fa-exclamation-circle"></i>
                        Unpaid
                        <span class="tab-count"><?php echo $unpaid; ?></span>
                    </button>
                    <button class="tab-button" data-tab="today-bookings" data-count="<?php echo $today_bookings; ?>">
                        <i class="fas fa-calendar-day"></i>
                        Today's Bookings
                        <span class="tab-count"><?php echo $today_bookings; ?></span>
                    </button>
                    <button class="tab-button" data-tab="week-bookings" data-count="<?php echo $week_bookings; ?>">
                        <i class="fas fa-calendar-week"></i>
                        This Week
                        <span class="tab-count"><?php echo $week_bookings; ?></span>
                    </button>
                    <button class="tab-button" data-tab="month-bookings" data-count="<?php echo $month_bookings; ?>">
                        <i class="fas fa-calendar-alt"></i>
                        This Month
                        <span class="tab-count"><?php echo $month_bookings; ?></span>
                    </button>
                </div>
            </div>

            <!-- Room Bookings -->
            <div class="bookings-section">
                <h3 class="section-title">
                    <i class="fas fa-bed"></i> Room Bookings
                    <span style="font-size: 14px; font-weight: normal; color: #666;">
                        <?php if ($total_pages > 1): ?>
                            (<?php echo $current_page_count; ?> of <?php echo number_format($list_count); ?> <?php echo $has_active_room_filters ? 'matching' : 'total'; ?> &mdash; page <?php echo $current_page; ?> of <?php echo $total_pages; ?>)
                        <?php else: ?>
                            (<?php echo number_format($list_count); ?> <?php echo $has_active_room_filters ? 'matching' : 'total'; ?>)
                        <?php endif; ?>
                    </span>
                </h3>

                <?php if (!empty($bookings)): ?>
                    <div class="table-responsive">
                        <table class="booking-table bookings-table tablet-table">
                            <thead>
                                <tr>
                                    <th>Ref</th>
                                    <th>Guest Name</th>
                                    <th>Room</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Nights</th>
                                    <th>Guests</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Payment</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $booking): ?>
                                    <?php
                                    $is_tentative = ($booking['status'] === 'tentative' || $booking['is_tentative'] == 1);
                                    $expires_soon = false;
                                    if ($is_tentative && $booking['tentative_expires_at']) {
                                        $expires_at = new DateTime($booking['tentative_expires_at']);
                                        $now = new DateTime();
                                        $hours_until_expiry = ($expires_at->getTimestamp() - $now->getTimestamp()) / 3600;
                                        $expires_soon = $hours_until_expiry <= 24 && $hours_until_expiry > 0;
                                    }

                                    $is_missed_checkin = ($booking['status'] === 'confirmed' && $booking['check_in_date'] < $today_str);

                                    $is_overdue_checkout = false;
                                    if ($booking['status'] === 'checked-in') {
                                        if ($booking['check_out_date'] < $today_str) {
                                            $is_overdue_checkout = true;
                                        } elseif ($booking['check_out_date'] === $today_str && $is_past_checkout_time) {
                                            $is_overdue_checkout = true;
                                        }
                                    }

                                    $row_style = '';
                                    if ($is_missed_checkin) {
                                        $row_style = 'style="background: rgba(220, 53, 69, 0.05); border-left: 4px solid #dc3545;"';
                                    } elseif ($is_overdue_checkout) {
                                        $row_style = 'style="background: rgba(255, 193, 7, 0.05); border-left: 4px solid #ffc107;"';
                                    } elseif ($is_tentative) {
                                        $row_style = 'style="background: linear-gradient(90deg, rgba(139, 115, 85, 0.05) 0%, white 10%);"';
                                    }
                                    ?>
                                    <tr <?php echo $row_style; ?>
                                        id="booking-<?php echo (int)$booking['id']; ?>"
                                        data-focus="booking-<?php echo (int)$booking['id']; ?>"
                                        data-status="<?php echo htmlspecialchars($booking['status'], ENT_QUOTES); ?>"
                                        data-payment-status="<?php echo htmlspecialchars($booking['actual_payment_status'] ?? $booking['payment_status'], ENT_QUOTES); ?>"
                                        data-check-in="<?php echo htmlspecialchars($booking['check_in_date'], ENT_QUOTES); ?>"
                                        data-check-out="<?php echo htmlspecialchars($booking['check_out_date'], ENT_QUOTES); ?>"
                                        data-created="<?php echo htmlspecialchars(date('Y-m-d', strtotime($booking['created_at'])), ENT_QUOTES); ?>"
                                        data-expiring-soon="<?php echo $expires_soon ? '1' : '0'; ?>"
                                        data-tentative="<?php echo $is_tentative ? '1' : '0'; ?>">
                                        <td data-label="Reference">
                                            <strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong>
                                            <?php if ($is_tentative): ?>
                                                <br><span class="tentative-indicator"><i class="fas fa-clock"></i> Tentative</span>
                                            <?php elseif ($is_missed_checkin): ?>
                                                <br><span style="color: #dc3545; font-size: 11px; font-weight: 600;"><i class="fas fa-exclamation-triangle"></i> Missed Check-in</span>
                                            <?php elseif ($is_overdue_checkout): ?>
                                                <br><span style="color: #856404; font-size: 11px; font-weight: 600;"><i class="fas fa-clock"></i> Overdue Checkout</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Guest">
                                            <?php echo htmlspecialchars($booking['guest_name']); ?>
                                            <br><small style="color: #666;"><?php echo htmlspecialchars($booking['guest_phone']); ?></small>
                                            <?php if (!empty($booking['guest_email'])): ?>
                                                <br><small class="guest-email"><?php echo htmlspecialchars($booking['guest_email']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Room">
                                            <?php echo htmlspecialchars($booking['room_name']); ?>
                                            <?php if (!empty($booking['rate_plan_label'])): ?>
                                                <br><small style="color:#8A5F2A; font-size:11px;"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($booking['rate_plan_label']); ?></small>
                                            <?php endif; ?>
                                            <?php $roomAssignmentLabel = getBookingRoomLabel((int)$booking['id'], (string)($booking['individual_room_name'] ?: $booking['individual_room_number'])); ?>
                                            <?php if ($roomAssignmentLabel !== ''): ?>
                                                <br><span class="room-chip"><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($roomAssignmentLabel); ?></span>
                                            <?php else: ?>
                                                <br><span class="room-chip room-chip--unassigned"><i class="fas fa-minus"></i> Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Check In"><?php echo date('M d, Y', strtotime($booking['check_in_date'])); ?></td>
                                        <td data-label="Check Out"><?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?></td>
                                        <td data-label="Nights"><?php echo $booking['number_of_nights']; ?></td>
                                        <td data-label="Guests"><?php echo $booking['number_of_guests']; ?></td>
                                        <td data-label="Total">
                                            <strong>K <?php echo number_format($booking['total_amount'], 2); ?></strong>
                                            <?php if ($is_tentative && $booking['tentative_expires_at']): ?>
                                                <?php if ($expires_soon): ?>
                                                    <br><span class="expires-soon"><i class="fas fa-exclamation-triangle"></i> Expires soon!</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Status">
                                            <span class="badge badge-<?php echo $booking['status']; ?>">
                                                <?php echo ucfirst($booking['status']); ?>
                                            </span>
                                            <?php if ($is_tentative && $booking['tentative_expires_at']): ?>
                                                <br><small style="color: #666; font-size: 10px;">
                                                    <?php
                                                    $expires = new DateTime($booking['tentative_expires_at']);
                                                    echo 'Expires: ' . $expires->format('M d, H:i');
                                                    ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Payment">
                                            <span class="badge badge-<?php echo $booking['actual_payment_status']; ?>">
                                                <?php
                                                $status = $booking['actual_payment_status'];
                                                // Map payment statuses to user-friendly labels
                                                $status_labels = [
                                                    'paid' => 'Paid',
                                                    'unpaid' => 'Unpaid',
                                                    'partial' => 'Partial',
                                                    'completed' => 'Paid',
                                                    'pending' => 'Pending',
                                                    'failed' => 'Failed',
                                                    'refunded' => 'Refunded',
                                                    'partially_refunded' => 'Partial Refund'
                                                ];
                                                echo $status_labels[$status] ?? ucfirst($status);
                                                ?>
                                            </span>
                                            <?php if ($booking['payment_reference']): ?>
                                                <br><small style="color: #666; font-size: 10px;">
                                                    <?php echo htmlspecialchars($booking['payment_reference']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Created">
                                            <small style="color: #666; font-size: 11px;">
                                                <i class="fas fa-clock"></i> <?php echo date('M j, H:i', strtotime($booking['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td class="actions-cell" data-label="Actions">
                                            <?php
                                            // Granular permission gates for action buttons
                                            $_perm_checkin  = $_user_permissions['checkin_guest']  ?? false;
                                            $_perm_checkout = $_user_permissions['checkout_guest'] ?? false;
                                            $_perm_cancel   = $_user_permissions['cancel_booking'] ?? false;
                                            $_perm_edit     = $_user_permissions['edit_booking']   ?? false;
                                            $_perm_pay      = $_user_permissions['payment_add']    ?? false;
                                            ?>
                                            <div class="actions-row">
                                                <button type="button" class="quick-action view" title="View booking summary" aria-label="View booking summary" data-booking-id="<?php echo (int)$booking['id']; ?>" data-booking-ref="<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>" data-guest-name="<?php echo htmlspecialchars((string)($booking['guest_name'] ?? ''), ENT_QUOTES); ?>" data-guest-email="<?php echo htmlspecialchars((string)($booking['guest_email'] ?? ''), ENT_QUOTES); ?>" data-guest-phone="<?php echo htmlspecialchars((string)($booking['guest_phone'] ?? ''), ENT_QUOTES); ?>" data-room-name="<?php echo htmlspecialchars((string)($booking['room_name'] ?? ''), ENT_QUOTES); ?>" data-individual-room-name="<?php echo htmlspecialchars((string)($booking['individual_room_name'] ?? ''), ENT_QUOTES); ?>" data-individual-room-number="<?php echo htmlspecialchars((string)($booking['individual_room_number'] ?? ''), ENT_QUOTES); ?>" data-check-in-date="<?php echo htmlspecialchars((string)($booking['check_in_date'] ?? ''), ENT_QUOTES); ?>" data-check-out-date="<?php echo htmlspecialchars((string)($booking['check_out_date'] ?? ''), ENT_QUOTES); ?>" data-number-of-nights="<?php echo htmlspecialchars((string)($booking['number_of_nights'] ?? ''), ENT_QUOTES); ?>" data-number-of-guests="<?php echo htmlspecialchars((string)($booking['number_of_guests'] ?? ''), ENT_QUOTES); ?>" data-total-display="<?php echo htmlspecialchars($currency_symbol . ' ' . number_format((float)($booking['total_amount'] ?? 0), 2), ENT_QUOTES); ?>" data-status-label="<?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string)($booking['status'] ?? ''))), ENT_QUOTES); ?>" data-payment-status-label="<?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string)($booking['actual_payment_status'] ?? ($booking['payment_status'] ?? '')))), ENT_QUOTES); ?>" data-created-at-label="<?php echo htmlspecialchars(!empty($booking['created_at']) ? date('M j, Y H:i', strtotime((string)$booking['created_at'])) : '', ENT_QUOTES); ?>" data-special-requests="<?php echo htmlspecialchars((string)($booking['special_requests'] ?? ''), ENT_QUOTES); ?>" onclick="openViewBookingModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', this, typeof event !== 'undefined' ? event : null)">
                                                    <i class="fas fa-circle-info"></i>
                                                    <span class="label">View details</span>
                                                </button>
                                                <?php if ($is_tentative): ?>
                                                    <button class="quick-action confirm" title="Convert to confirmed" aria-label="Convert to confirmed" onclick="convertTentativeBooking(<?php echo $booking['id']; ?>)">
                                                        <i class="fas fa-circle-check"></i>
                                                    </button>
                                                    <button class="quick-action cancel" title="Cancel booking" aria-label="Cancel booking" onclick="openCancelBookingModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['guest_name'], ENT_QUOTES); ?>')">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                <?php elseif ($booking['status'] === 'pending'): ?>
                                                    <?php
                                                    // Only show "Make Tentative" button if no payment exists
                                                    $can_make_tentative = !in_array($booking['payment_status'], ['paid', 'partial'], true);
                                                    ?>
                                                    <button class="quick-action confirm" title="Confirm booking" aria-label="Confirm booking" data-help="Confirm Booking|Move this pending booking to Confirmed status so it appears in the confirmed queue and can proceed to check-in." onclick="updateStatus(<?php echo $booking['id']; ?>, 'confirmed')">
                                                        <i class="fas fa-circle-check"></i>
                                                    </button>
                                                    <button class="quick-action cancel" title="Cancel booking" aria-label="Cancel booking" onclick="openCancelBookingModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['guest_name'], ENT_QUOTES); ?>')">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($booking['status'] === 'confirmed'): ?>
                                                    <?php
                                                    $payment_st = $booking['actual_payment_status'] ?? $booking['payment_status'];
                                                    $is_paid = in_array($payment_st, ['paid', 'partial', 'completed'], true);
                                                    $room_assigned = !empty($booking['individual_room_id']);
                                                    // Date-based validation: check-in only allowed on or after check-in date
                                                    $checkin_date_obj = new DateTime($booking['check_in_date']);
                                                    $checkin_date_obj->setTime(0, 0, 0);
                                                    $today_dt = new DateTime('today');
                                                    $checkin_date_reached = $checkin_date_obj <= $today_dt;
                                                    // Room assignment is advisory (not a hard block) — auto-assigned bookings have no individual_room_id
                                                    $can_checkin = $is_paid && $checkin_date_reached;
                                                    $checkin_error = '';
                                                    if (!$is_paid) {
                                                        $checkin_error = 'Cannot check in: booking must have at least partial payment.';
                                                    } elseif (!$checkin_date_reached) {
                                                        $checkin_error = 'Cannot check in: Check-in date has not been reached yet (' . htmlspecialchars($booking['check_in_date']) . ').';
                                                    }
                                                    // Parameters for modal
                                                    $guest_name = htmlspecialchars($booking['guest_name'], ENT_QUOTES);
                                                    $check_in_date = htmlspecialchars($booking['check_in_date'], ENT_QUOTES);
                                                    $payment_status = $booking['actual_payment_status'] ?? $booking['payment_status'];
                                                    $room_assigned_bool = $room_assigned ? 'true' : 'false';
                                                    $booking_status = $booking['status'];
                                                    ?>
                                                    <?php if ($is_missed_checkin): ?>
                                                        <?php if ($_perm_checkin): ?>
                                                            <button type="button" class="quick-action checkin--urgent <?php echo $can_checkin ? '' : 'disabled'; ?>" data-action="check-in" data-booking-id="<?php echo $booking['id']; ?>" data-booking-ref="<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>" data-guest-name="<?php echo $guest_name; ?>" data-check-in-date="<?php echo $check_in_date; ?>" data-payment-status="<?php echo $payment_status; ?>" data-room-assigned="<?php echo $room_assigned_bool; ?>" data-booking-status="<?php echo $booking_status; ?>" <?php if (!$can_checkin): ?> title="<?php echo htmlspecialchars($checkin_error); ?>" <?php else: ?> title="Late check-in" <?php endif; ?> aria-label="Late check-in">
                                                                <i class="fas fa-right-to-bracket"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($_perm_cancel): ?>
                                                            <button type="button" class="quick-action noshow--urgent" data-action="no-show" data-booking-id="<?php echo $booking['id']; ?>" data-booking-ref="<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>" data-guest-name="<?php echo $guest_name; ?>" data-check-in-date="<?php echo $check_in_date; ?>" data-payment-status="<?php echo $payment_status; ?>" data-room-assigned="<?php echo $room_assigned_bool; ?>" data-booking-status="<?php echo $booking_status; ?>" title="Mark no-show" aria-label="Mark no-show">
                                                                <i class="fas fa-user-slash"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <?php if ($_perm_checkin): ?>
                                                            <button type="button" class="quick-action checkin <?php echo $can_checkin ? '' : 'disabled'; ?>" data-action="check-in" data-booking-id="<?php echo $booking['id']; ?>" data-booking-ref="<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>" data-guest-name="<?php echo $guest_name; ?>" data-check-in-date="<?php echo $check_in_date; ?>" data-payment-status="<?php echo $payment_status; ?>" data-room-assigned="<?php echo $room_assigned_bool; ?>" data-booking-status="<?php echo $booking_status; ?>" data-help="Check In Guest|Check the guest into their room now. Requires at least partial payment and the check-in date to have arrived." <?php if (!$can_checkin): ?> title="<?php echo htmlspecialchars($checkin_error); ?>" <?php else: ?> title="Check in guest" <?php endif; ?> aria-label="Check in guest">
                                                                <i class="fas fa-right-to-bracket"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if ($_perm_cancel): ?>
                                                        <button class="quick-action cancel" title="Cancel booking" aria-label="Cancel booking" onclick="openCancelBookingModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['guest_name'], ENT_QUOTES); ?>')">
                                                            <i class="fas fa-ban"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if ($booking['status'] === 'checked-in'): ?>
                                                    <?php
                                                    // Date-based validation for check-out (allow early checkout)
                                                    $checkout_date_obj = new DateTime($booking['check_out_date']);
                                                    $checkout_date_obj->setTime(0, 0, 0);
                                                    $today_dt_checkout = new DateTime('today');
                                                    $tomorrow_dt_checkout = (clone $today_dt_checkout)->modify('+1 day');
                                                    // Allow checkout if check-out date is today, in the past, or max 1 day in the future (early checkout)
                                                    $checkout_allowed = $checkout_date_obj <= $tomorrow_dt_checkout;
                                                    ?>
                                                    <?php if ($is_overdue_checkout): ?>
                                                        <?php if ($_perm_checkout): ?>
                                                            <button class="quick-action checkout--urgent" <?php if (!$checkout_allowed): ?>disabled title="Check-out date is too far in the future" <?php else: ?>title="Checkout now" <?php endif; ?> aria-label="Checkout now" onclick="checkoutBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')">
                                                                <i class="fas fa-right-from-bracket"></i>
                                                            </button>
                                                            <button class="quick-action extend" title="Extend stay" aria-label="Extend stay" onclick="openExtendStayModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', '<?php echo $booking['check_out_date']; ?>', '<?php echo htmlspecialchars($booking['guest_name'], ENT_QUOTES); ?>')">
                                                                <i class="fas fa-calendar-plus"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <?php if ($_perm_checkout): ?>
                                                            <button class="quick-action checkout" <?php if (!$checkout_allowed): ?>disabled title="Check-out date is too far in the future" <?php else: ?>title="Checkout guest" <?php endif; ?> aria-label="Checkout guest" data-help="Checkout Guest|Check the guest out and close their stay. Available from the check-out date up to one day early." onclick="checkoutBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')">
                                                                <i class="fas fa-right-from-bracket"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <!-- Note: Undo check-in is available via the More (⋯) menu -->
                                                    <?php endif; ?>
                                                    <!-- Note: Cancel button is hidden for checked-in bookings -->
                                                <?php endif; ?>
                                                <?php
                                                // One-click "mark as paid" records the FULL total as settled, so it is
                                                // only valid when nothing has been paid yet. A partial booking still has
                                                // an outstanding balance — marking it paid here would silently discard
                                                // that balance, so those must go through Record Payment / Consolidation
                                                // in the More menu instead. Also hide for tentative and all final states.
                                                $can_mark_paid = in_array($booking['status'], ['pending', 'confirmed'], true)
                                                    && !in_array($booking['payment_status'], ['paid', 'partial', 'completed'], true);
                                                ?>
                                                <?php if ($can_mark_paid && $_perm_pay): ?>
                                                    <button class="quick-action paid" title="Record payment as paid" aria-label="Record payment as paid" data-help="Record Payment as Paid|Mark the full outstanding balance as paid in one click. Only available before any payment has been recorded — use Record Payment in the More menu for partial payments." onclick="updatePayment(<?php echo $booking['id']; ?>, 'paid')">
                                                        <i class="fas fa-money-bill-wave"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php
                                                // Modify (any non-final status)
                                                $can_modify = !in_array($booking['status'], ['checked-out', 'cancelled'], true);
                                                // Refund (paid bookings that ended without service: cancelled, no-show, or partial+refundable)
                                                $is_paid_for_refund = in_array($booking['payment_status'], ['paid', 'partial', 'completed'], true);
                                                $can_refund = $is_paid_for_refund && in_array($booking['status'], ['cancelled', 'no-show', 'checked-out'], true);
                                                ?>
                                                <?php if ($can_refund): ?>
                                                    <button class="quick-action refund" title="Refund payment" aria-label="Refund payment" onclick="openRefundForBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')">
                                                        <i class="fas fa-money-bill-transfer"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <div class="actions-more">
                                                    <button type="button" class="quick-action actions-more-toggle" title="More actions" aria-label="More actions" onclick="toggleActionsMore(this, typeof event !== 'undefined' ? event : null)">
                                                        <i class="fas fa-ellipsis-vertical"></i>
                                                        <span class="label">More</span>
                                                    </button>
                                                    <div class="actions-more-menu">
                                                        <a href="booking-details.php?id=<?php echo $booking['id']; ?>"><i class="fas fa-circle-info"></i> Full details</a>
                                                        <?php if ($_perm_edit): ?>
                                                            <a href="edit-booking.php?id=<?php echo $booking['id']; ?>"><i class="fas fa-pen-to-square"></i> Full edit page</a>
                                                        <?php endif; ?>
                                                        <?php if ($can_modify && $_perm_edit && $_perm_quick_modify): ?>
                                                            <button type="button" data-action="open-modify" data-booking-id="<?php echo $booking['id']; ?>" data-booking-ref="<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>"><i class="fas fa-sliders"></i> Quick modify</button>
                                                        <?php endif; ?>
                                                        <hr class="menu-divider">
                                                        <?php if ($booking['status'] === 'confirmed'): ?>
                                                            <?php if (!$booking['individual_room_id']): ?>
                                                                <button type="button" data-action="assign-room" data-booking-id="<?php echo $booking['id']; ?>" data-booking-ref="<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>" data-check-in="<?php echo htmlspecialchars($booking['check_in_date']); ?>" data-check-out="<?php echo htmlspecialchars($booking['check_out_date']); ?>" data-room-id="<?php echo $booking['room_id']; ?>"><i class="fas fa-key"></i> Assign room</button>
                                                            <?php else: ?>
                                                                <button type="button" data-action="assign-room" data-booking-id="<?php echo $booking['id']; ?>" data-booking-ref="<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>" data-check-in="<?php echo htmlspecialchars($booking['check_in_date']); ?>" data-check-out="<?php echo htmlspecialchars($booking['check_out_date']); ?>" data-room-id="<?php echo $booking['room_id']; ?>"><i class="fas fa-right-left"></i> Change room</button>
                                                            <?php endif; ?>
                                                            <?php if (!$is_missed_checkin): ?>
                                                                <button type="button" data-action="upgrade-room" data-booking-id="<?php echo $booking['id']; ?>" data-booking-ref="<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>" data-current-room-id="<?php echo $booking['room_id']; ?>" data-current-room-name="<?php echo htmlspecialchars($booking['room_name'], ENT_QUOTES); ?>" data-guest-name="<?php echo htmlspecialchars($booking['guest_name'], ENT_QUOTES); ?>" data-check-in="<?php echo htmlspecialchars($booking['check_in_date'], ENT_QUOTES); ?>" data-check-out="<?php echo htmlspecialchars($booking['check_out_date'], ENT_QUOTES); ?>" data-total-amount="<?php echo $booking['total_amount']; ?>" data-payment-status="<?php echo $booking['payment_status']; ?>"><i class="fas fa-arrow-up-right-dots"></i> Upgrade room</button>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                        <?php if ($booking['status'] === 'pending' && isset($can_make_tentative) && $can_make_tentative): ?>
                                                            <button type="button" data-action="make-tentative" data-booking-id="<?php echo $booking['id']; ?>" data-booking-ref="<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>" data-tentative-type="make_tentative"><i class="fas fa-hourglass-half"></i> Make tentative</button>
                                                        <?php endif; ?>
                                                        <?php if ($booking['status'] === 'checked-in' && !$is_overdue_checkout && $_perm_checkin): ?>
                                                            <button type="button" onclick="updateStatus(<?php echo $booking['id']; ?>, 'cancel-checkin')"><i class="fas fa-rotate-left"></i> Undo check-in</button>
                                                        <?php endif; ?>
                                                        <?php if ($booking['status'] === 'checked-in' && $is_overdue_checkout && $_is_admin_user): ?>
                                                            <button type="button" onclick="openAdminChangeDateModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', '<?php echo $booking['check_out_date']; ?>', '<?php echo htmlspecialchars($booking['guest_name'], ENT_QUOTES); ?>')"><i class="fas fa-calendar-pen"></i> Change checkout date</button>
                                                        <?php endif; ?>
                                                        <?php if ($_perm_pay && !in_array($booking['status'], ['cancelled', 'no-show'], true)): ?>
                                                            <button type="button" onclick="openConsolidationModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')"><i class="fas fa-scale-balanced"></i> Consolidation</button>
                                                        <?php endif; ?>
                                                        <hr class="menu-divider">
                                                        <button type="button" onclick="openResendEmailModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['status']); ?>', <?php echo ($is_missed_checkin || $is_overdue_checkout) ? 'true' : 'false'; ?>)"><i class="fas fa-envelope"></i> Resend email</button>
                                                        <button type="button" onclick="sendInvoiceEmail(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', this)"><i class="fas fa-file-invoice"></i> Send invoice</button>
                                                        <button type="button" onclick="openBookingListQuoteModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['guest_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['guest_email'] ?? '', ENT_QUOTES); ?>')"><i class="fas fa-file-invoice-dollar"></i> Send quotation</button>
                                                        <?php if (!empty($booking['guest_phone'])): ?>
                                                            <button type="button" onclick="openBookingListQuotationWhatsApp('<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['guest_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['guest_phone'], ENT_QUOTES); ?>')"><i class="fab fa-whatsapp"></i> Send via WhatsApp</button>
                                                        <?php endif; ?>
                                                        <hr class="menu-divider">
                                                        <button type="button" onclick="viewBookingAuditLog(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')"><i class="fas fa-clock-rotate-left"></i> Audit log</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <?php if ($has_active_room_filters): ?>
                            <p>No room bookings match your current search filters.</p>
                            <a href="bookings.php" style="display: inline-block; margin-top: 8px; padding: 8px 14px; border: 1px solid #ddd; border-radius: 8px; color: #444; text-decoration: none; font-size: 13px;">Clear filters</a>
                        <?php else: ?>
                            <p>No room bookings yet.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($total_pages > 1): ?>
                    <?php
                    $pg_params = array_filter([
                        'search'        => $search_query,
                        'filter_status' => $filter_status,
                        'date_from'     => $filter_date_from,
                        'date_to'       => $filter_date_to,
                    ], fn($v) => $v !== '');
                    $pg_base = 'bookings.php?' . (empty($pg_params) ? '' : http_build_query($pg_params) . '&');
                    ?>
                    <nav class="bookings-pagination" data-admin-pagination>
                        <?php if ($current_page > 1): ?>
                            <a href="<?php echo htmlspecialchars($pg_base . 'page=' . ($current_page - 1), ENT_QUOTES); ?>" class="pg-btn" data-page-nav data-no-spa="1">&lsaquo; Prev</a>
                        <?php endif; ?>

                        <?php
                        $pg_start = max(1, $current_page - 2);
                        $pg_end   = min($total_pages, $current_page + 2);
                        if ($pg_start > 1): ?>
                            <a href="<?php echo htmlspecialchars($pg_base . 'page=1', ENT_QUOTES); ?>" class="pg-btn" data-no-spa="1">1</a>
                            <?php if ($pg_start > 2): ?><span class="pg-ellipsis">&hellip;</span><?php endif; ?>
                        <?php endif; ?>

                        <?php for ($pg = $pg_start; $pg <= $pg_end; $pg++): ?>
                            <?php if ($pg === $current_page): ?>
                                <span class="pg-current"><?php echo $pg; ?></span>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($pg_base . 'page=' . $pg, ENT_QUOTES); ?>" class="pg-btn" data-page-nav data-no-spa="1"><?php echo $pg; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($pg_end < $total_pages): ?>
                            <?php if ($pg_end < $total_pages - 1): ?><span class="pg-ellipsis">&hellip;</span><?php endif; ?>
                            <a href="<?php echo htmlspecialchars($pg_base . 'page=' . $total_pages, ENT_QUOTES); ?>" class="pg-btn" data-page-nav data-no-spa="1"><?php echo $total_pages; ?></a>
                        <?php endif; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <a href="<?php echo htmlspecialchars($pg_base . 'page=' . ($current_page + 1), ENT_QUOTES); ?>" class="pg-btn" data-page-nav data-no-spa="1">Next &rsaquo;</a>
                        <?php endif; ?>

                        <span class="pg-summary">Showing <?php echo (($current_page - 1) * $per_page) + 1; ?>–<?php echo min($current_page * $per_page, $list_count); ?> of <?php echo $list_count; ?></span>
                    </nav>
                <?php endif; ?>
            </div>
        </div><!-- /booking-results -->

        <!-- Conference Inquiries -->
        <div class="bookings-section">
            <h3 class="section-title">
                <i class="fas fa-users"></i> Conference Inquiries
                <span style="font-size: 14px; font-weight: normal; color: #666;">
                    (<?php echo count($conference_inquiries); ?> total)
                </span>
            </h3>

            <?php if (!empty($conference_inquiries)): ?>
                <div class="table-responsive">
                    <table class="booking-table tablet-table">
                        <thead>
                            <tr>
                                <th style="width: 140px;">Date Received</th>
                                <th style="width: 220px;">Company/Name</th>
                                <th style="width: 220px;">Contact</th>
                                <th style="width: 180px;">Event Type</th>
                                <th style="width: 140px;">Expected Date</th>
                                <th style="width: 100px;">Attendees</th>
                                <th style="width: 140px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conference_inquiries as $inquiry): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($inquiry['created_at'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($inquiry['company_name']); ?></strong>
                                        <br><small><?php echo htmlspecialchars($inquiry['contact_person']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($inquiry['email']); ?>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars($inquiry['phone']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($inquiry['event_type']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($inquiry['expected_date'])); ?></td>
                                    <td><?php echo $inquiry['number_of_attendees']; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $inquiry['status']; ?>">
                                            <?php echo ucfirst($inquiry['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No conference inquiries yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Currency symbol, declared INSIDE #rh-admin-page so the admin SPA
        // re-runs it on every navigation to this page. The head-level copy only
        // executes on a full page load; when arriving here via an SPA link from
        // another page the head is never re-run, so without this in-content copy
        // fmt()/openCheckoutSettlementModal() below would hit
        // "_currencySymbol is not defined". Kept on window so the later
        // fmtMWK block (a separate SPA IIFE scope) resolves it too.
        window._currencySymbol = <?= json_encode($currency_symbol) ?>;
        var _currencySymbol = window._currencySymbol;

        // Ensure Alert is defined (fallback for early script execution)
        if (typeof Alert === 'undefined') {
            window.Alert = {
                show: function(message, type) {
                    // Fallback: silent no-op — Alert module not loaded yet
                }
            };
        }

        (function initRoomBookingsLiveSearch() {
            const searchForm = document.querySelector('[data-live-search-form="room-bookings"]');
            const searchInput = searchForm ? searchForm.querySelector('[data-live-search-input="room-bookings"]') : null;
            if (!searchForm || !searchInput) {
                return;
            }

            let searchDebounceTimer = null;
            searchInput.addEventListener('input', function() {
                window.clearTimeout(searchDebounceTimer);
                searchDebounceTimer = window.setTimeout(function() {
                    const query = searchInput.value.trim();
                    if (query.length === 0 || query.length >= 2) {
                        searchForm.requestSubmit();
                    }
                }, 450);
            });
        })();

        // ============================================
        // LOADING STATE MANAGEMENT FOR BUTTONS
        // ============================================

        /**
         * Set loading state on a button to prevent double-clicks
         * @param {HTMLElement} button - The button element
         * @param {boolean} isLoading - Whether to show loading state
         * @param {string} originalContent - Original button content (optional)
         */
        function setButtonLoading(button, isLoading, originalContent = null) {
            if (!button) return;

            if (isLoading) {
                // Store original content if not already stored
                if (!button.dataset.originalContent) {
                    button.dataset.originalContent = originalContent || button.innerHTML;
                }

                // Disable button and show loading spinner
                button.disabled = true;
                button.classList.add('btn-loading');
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                button.style.pointerEvents = 'none';
                button.style.opacity = '0.7';
            } else {
                // Restore original state
                button.disabled = false;
                button.classList.remove('btn-loading');
                button.innerHTML = button.dataset.originalContent || originalContent;
                button.style.pointerEvents = '';
                button.style.opacity = '';
                delete button.dataset.originalContent;
            }
        }

        /**
         * Set loading state on all quick-action buttons in a container
         * @param {HTMLElement} container - Container element
         * @param {boolean} isLoading - Loading state
         * @param {HTMLElement} excludeButton - Button to exclude (the one clicked)
         */
        function setAllButtonsLoading(container, isLoading, excludeButton = null) {
            const buttons = container.querySelectorAll('.quick-action, .btn');
            buttons.forEach(btn => {
                if (btn !== excludeButton) {
                    if (isLoading) {
                        btn.disabled = true;
                        btn.style.opacity = '0.5';
                        btn.style.pointerEvents = 'none';
                    } else {
                        btn.disabled = false;
                        btn.style.opacity = '';
                        btn.style.pointerEvents = '';
                    }
                }
            });
        }

        /**
         * Show global loading overlay
         */
        function showLoadingOverlay(message = 'Processing...') {
            let overlay = document.getElementById('globalLoadingOverlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'globalLoadingOverlay';
                overlay.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.5);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 99999;
                `;
                overlay.innerHTML = `
                    <div style="background: white; padding: 30px 40px; border-radius: 12px; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
                        <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: var(--gold, #8B7355); margin-bottom: 16px; display: block;"></i>
                        <div id="loadingMessage" style="font-size: 16px; color: var(--navy, #1A1A1A); font-weight: 500;">${message}</div>
                    </div>
                `;
                document.body.appendChild(overlay);
            } else {
                overlay.style.display = 'flex';
                document.getElementById('loadingMessage').textContent = message;
            }
        }

        /**
         * Hide global loading overlay
         */
        function hideLoadingOverlay() {
            const overlay = document.getElementById('globalLoadingOverlay');
            if (overlay) {
                overlay.style.display = 'none';
            }
        }

        function confirmAdminAction(options) {
            if (window.AdminConfirm && typeof window.AdminConfirm.request === 'function') {
                return window.AdminConfirm.request(options);
            }
            Alert.show('Confirmation controls are still loading. Please try again in a moment.', 'warning');
            return Promise.resolve(false);
        }

        function promptAdminAction(options) {
            if (window.AdminConfirm && typeof window.AdminConfirm.prompt === 'function') {
                return window.AdminConfirm.prompt(options);
            }
            Alert.show('Confirmation controls are still loading. Please try again in a moment.', 'warning');
            return Promise.resolve(null);
        }

        function showBookingActionMessage(message, type = 'info') {
            const text = String(message || '').trim();
            if (!text) {
                return;
            }
            if (window.Alert && typeof window.Alert.show === 'function') {
                Alert.show(text, type);
                return;
            }
            if (type === 'error') {
                console.error(text);
            } else {
                console.warn(text);
            }
        }

        const BOOKING_ACTION_MESSAGE_STORAGE_KEY = 'rh_booking_action_message';

        function queueBookingActionMessage(message, type = 'success') {
            const text = String(message || '').trim();
            if (!text) {
                return;
            }
            try {
                sessionStorage.setItem(BOOKING_ACTION_MESSAGE_STORAGE_KEY, JSON.stringify({
                    message: text,
                    type: type || 'success'
                }));
            } catch (e) {
                console.warn('Unable to persist booking action message', e);
            }
        }

        function restoreQueuedBookingActionMessage() {
            try {
                const raw = sessionStorage.getItem(BOOKING_ACTION_MESSAGE_STORAGE_KEY);
                if (!raw) {
                    return;
                }
                sessionStorage.removeItem(BOOKING_ACTION_MESSAGE_STORAGE_KEY);
                const payload = JSON.parse(raw);
                if (!payload || !payload.message) {
                    return;
                }
                showBookingActionMessage(payload.message, payload.type || 'success');
            } catch (e) {
                sessionStorage.removeItem(BOOKING_ACTION_MESSAGE_STORAGE_KEY);
            }
        }

        function reloadWithBookingActionMessage(responseData, fallbackMessage = 'Action completed successfully.') {
            const message = responseData && responseData.message ? responseData.message : fallbackMessage;
            queueBookingActionMessage(message, 'success');
            window.location.reload();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', restoreQueuedBookingActionMessage, {
                once: true
            });
        } else {
            restoreQueuedBookingActionMessage();
        }

        function postBookingAction(formData, errorMessage, timeoutMs) {
            if (formData instanceof FormData && !formData.has('csrf_token')) {
                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                const csrfToken = window._rhCsrf || (csrfMeta ? (csrfMeta.getAttribute('content') || '') : '');
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }
            }

            const controller = new AbortController();
            const tid = setTimeout(() => controller.abort(), timeoutMs || 30000);

            return fetch(window.location.href, {
                method: 'POST',
                body: formData,
                signal: controller.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(async response => {
                clearTimeout(tid);
                const contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    const data = await response.json();
                    if (!response.ok || data.success === false) {
                        throw new Error(data.message || data.error || errorMessage || 'Action failed.');
                    }
                    return data;
                }

                if (!response.ok) {
                    throw new Error(errorMessage || 'Action failed.');
                }

                return { success: true };
            }).catch(err => {
                clearTimeout(tid);
                if (err.name === 'AbortError') {
                    throw new Error('The request timed out. Please try again.');
                }
                throw err;
            });
        }

        function setModalActionLoading(form, isLoading, loadingText) {
            if (!form || !(form instanceof HTMLElement)) {
                return;
            }
            const modalContent = form.closest('.modal-content');
            if (!modalContent) {
                return;
            }

            let loader = modalContent.querySelector('.modal-inline-loader');
            if (!loader) {
                loader = document.createElement('div');
                loader.className = 'modal-inline-loader';
                loader.setAttribute('hidden', 'hidden');
                loader.innerHTML = '<span class="modal-inline-loader__spinner" aria-hidden="true"></span><span class="modal-inline-loader__text">Processing...</span>';
                modalContent.appendChild(loader);
            }

            const textNode = loader.querySelector('.modal-inline-loader__text');
            if (textNode && loadingText) {
                textNode.textContent = loadingText;
            }

            if (isLoading) {
                loader.removeAttribute('hidden');
                modalContent.classList.add('is-modal-busy');
            } else {
                loader.setAttribute('hidden', 'hidden');
                modalContent.classList.remove('is-modal-busy');
            }
        }

        // Tab switching functionality
        let currentTab = 'all';

        function switchTab(tabName) {
            currentTab = tabName;

            // Update active tab button
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.tab === tabName) {
                    btn.classList.add('active');
                }
            });

            // Filter table rows
            filterBookingsTable(tabName);

            // Update section title
            updateSectionTitle(tabName);
        }

        function filterBookingsTable(tabName) {
            const table = document.querySelector('.booking-table tbody');
            if (!table) return;

            const rows = table.querySelectorAll('tr');
            let visibleCount = 0;

            // Get today's date in LOCAL timezone (toISOString would give UTC which is wrong for UTC+2)
            const _now = new Date();
            const todayStr = `${_now.getFullYear()}-${String(_now.getMonth()+1).padStart(2,'0')}-${String(_now.getDate()).padStart(2,'0')}`;
            const today = new Date(_now.getFullYear(), _now.getMonth(), _now.getDate());

            // Calculate week start (7 days ago)
            const weekStart = new Date(today);
            weekStart.setDate(weekStart.getDate() - 7);

            // Calculate month start (first day of current month)
            const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);

            rows.forEach(row => {
                const status = (row.dataset.status || '').trim().toLowerCase();
                const payment = (row.dataset.paymentStatus || '').trim().toLowerCase();
                const checkInDateStr = row.dataset.checkIn || '';
                const checkOutDateStr = row.dataset.checkOut || '';
                const createdDateStr = row.dataset.created || '';
                const createdDate = createdDateStr ? new Date(createdDateStr + 'T00:00:00') : null;

                const isExpiringSoon = row.dataset.expiringSoon === '1';
                const isTentative = row.dataset.tentative === '1';
                const isTodayCheckIn = checkInDateStr === todayStr && status === 'confirmed';
                const isTodayCheckOut = checkOutDateStr === todayStr && status === 'checked-in';
                const isTodayBooking = createdDateStr === todayStr;
                const isWeekBooking = createdDate && createdDate >= weekStart;
                const isMonthBooking = createdDate && createdDate >= monthStart;

                let isVisible = false;

                switch (tabName) {
                    case 'all':
                        isVisible = true;
                        break;
                    case 'pending':
                        isVisible = status === 'pending';
                        break;
                    case 'tentative':
                        isVisible = status === 'tentative' || isTentative;
                        break;
                    case 'expiring-soon':
                        isVisible = isExpiringSoon;
                        break;
                    case 'confirmed':
                        isVisible = status === 'confirmed';
                        break;
                    case 'today-checkins':
                        isVisible = isTodayCheckIn;
                        break;
                    case 'today-checkouts':
                        isVisible = isTodayCheckOut;
                        break;
                    case 'checked-in':
                        isVisible = status === 'checked-in';
                        break;
                    case 'checked-out':
                        isVisible = status === 'checked-out';
                        break;
                    case 'cancelled':
                        isVisible = status === 'cancelled';
                        break;
                    case 'no-show':
                        isVisible = status === 'no-show';
                        break;
                    case 'paid':
                        isVisible = payment === 'paid' || payment === 'completed';
                        break;
                    case 'unpaid':
                        isVisible = payment !== 'paid' && payment !== 'completed';
                        break;
                    case 'today-bookings':
                        isVisible = isTodayBooking;
                        break;
                    case 'week-bookings':
                        isVisible = isWeekBooking;
                        break;
                    case 'month-bookings':
                        isVisible = isMonthBooking;
                        break;
                    default:
                        // Generic status-named tab (e.g. "expired") that has no
                        // bespoke rule above — match the row's status directly so
                        // deep links like ?status=expired still show their rows
                        // instead of hiding everything.
                        isVisible = status === tabName;
                        break;
                }

                if (isVisible) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Update count in section title
            const countSpan = document.querySelector('.section-title span');
            if (countSpan) {
                const totalRows = rows.length;
                if (visibleCount === totalRows) {
                    countSpan.textContent = `(${visibleCount} shown)`;
                } else {
                    countSpan.textContent = `(${visibleCount} of ${totalRows} shown)`;
                }
            }
        }

        function updateSectionTitle(tabName) {
            const titleElement = document.querySelector('.section-title');
            if (!titleElement) return;

            const tabTitles = {
                'all': 'All Room Bookings',
                'pending': 'Pending Bookings',
                'tentative': 'Tentative Bookings',
                'expiring-soon': 'Expiring Soon (Urgent)',
                'confirmed': 'Confirmed Bookings',
                'today-checkins': "Today's Check-ins",
                'today-checkouts': "Today's Check-outs",
                'checked-in': 'Checked In Guests',
                'checked-out': 'Checked Out Bookings',
                'cancelled': 'Cancelled Bookings',
                'no-show': 'No-Show Bookings',
                'paid': 'Paid Bookings',
                'unpaid': 'Unpaid Bookings',
                'today-bookings': "Today's Bookings",
                'week-bookings': "This Week's Bookings",
                'month-bookings': "This Month's Bookings"
            };

            const icon = titleElement.querySelector('i');
            const countSpan = titleElement.querySelector('span');

            let newTitle = tabTitles[tabName] || 'Room Bookings';
            let newIcon = 'fa-bed';

            if (tabName === 'pending') newIcon = 'fa-clock';
            if (tabName === 'tentative') newIcon = 'fa-hourglass-half';
            if (tabName === 'expiring-soon') newIcon = 'fa-exclamation-triangle';
            if (tabName === 'confirmed') newIcon = 'fa-check-circle';
            if (tabName === 'today-checkins') newIcon = 'fa-calendar-day';
            if (tabName === 'today-checkouts') newIcon = 'fa-calendar-times';
            if (tabName === 'checked-in') newIcon = 'fa-sign-in-alt';
            if (tabName === 'checked-out') newIcon = 'fa-sign-out-alt';
            if (tabName === 'cancelled') newIcon = 'fa-times-circle';
            if (tabName === 'no-show') newIcon = 'fa-user-slash';
            if (tabName === 'paid') newIcon = 'fa-dollar-sign';
            if (tabName === 'unpaid') newIcon = 'fa-exclamation-circle';
            if (tabName === 'today-bookings') newIcon = 'fa-calendar-day';
            if (tabName === 'week-bookings') newIcon = 'fa-calendar-week';
            if (tabName === 'month-bookings') newIcon = 'fa-calendar-alt';

            titleElement.innerHTML = `<i class="fas ${newIcon}"></i> ${newTitle} `;
            if (countSpan) {
                titleElement.appendChild(countSpan);
            }
        }

        // ── Tab navigation: each tab navigates to a URL so filtering is always server-side ──
        // JS visual filtering (filterBookingsTable) is still applied to the loaded rows
        // as a fast visual layer, but the source of truth is always the server-rendered rows.

        const TAB_FILTER_MAP = {
            'pending': {
                filter_status: 'pending'
            },
            'tentative': {
                filter_status: 'tentative'
            },
            'expiring-soon': {
                filter: 'expiring_soon'
            },
            'confirmed': {
                filter_status: 'confirmed'
            },
            'today-checkins': {
                filter: 'checkin_today'
            },
            'today-checkouts': {
                filter: 'checkout_today'
            },
            'checked-in': {
                filter_status: 'checked-in'
            },
            'checked-out': {
                filter_status: 'checked-out'
            },
            'cancelled': {
                filter_status: 'cancelled'
            },
            'no-show': {
                filter_status: 'no-show'
            },
            'paid': {
                filter: 'paid'
            },
            'unpaid': {
                filter: 'unpaid'
            },
            'today-bookings': {
                filter: 'today_bookings'
            },
            'week-bookings': {
                filter: 'week_bookings'
            },
            'month-bookings': {
                filter: 'month_bookings'
            },
        };

        function shortQuickActionLabel(button, helpText) {
            const classMap = {
                view: 'View details',
                email: 'Email',
                whatsapp: 'WhatsApp',
                assign: 'Assign',
                upgrade: 'Upgrade',
                modify: 'Modify',
                refund: 'Refund',
                paid: 'Paid',
                checkin: 'Check in',
                checkout: 'Checkout',
                cancel: 'Cancel',
                tentative: 'Tentative',
                noshow: 'No-show',
                consolidation: 'Consolidate',
                'actions-more-toggle': 'More'
            };

            for (const className in classMap) {
                if (button.classList.contains(className)) {
                    return classMap[className];
                }
            }

            const clean = String(helpText || '').replace(/\s+/g, ' ').trim();
            if (clean === '') {
                return '';
            }

            if (clean.length <= 14) {
                return clean;
            }

            const words = clean.split(' ');
            if (words.length > 1) {
                return words.slice(0, 2).join(' ');
            }

            return clean.slice(0, 14).trim();
        }

        function hydrateQuickActionButtons(scopeRoot) {
            const scope = scopeRoot || document;
            scope.querySelectorAll('.actions-row .quick-action').forEach(function(button) {
                const helpText = button.getAttribute('aria-label') || button.getAttribute('title') || button.textContent.trim();
                if (helpText) {
                    button.setAttribute('aria-label', helpText);
                    button.dataset.help = helpText;
                }

                const hasTextNode = Array.from(button.childNodes).some(function(node) {
                    return node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== '';
                });
                if (hasTextNode && !button.querySelector('.label')) {
                    return;
                }

                const labelText = shortQuickActionLabel(button, helpText);
                if (!labelText) {
                    return;
                }

                let labelSpan = button.querySelector('.label');
                if (!labelSpan) {
                    labelSpan = document.createElement('span');
                    labelSpan.className = 'label';
                    button.appendChild(labelSpan);
                }
                labelSpan.textContent = labelText;
            });
        }

        if (!window.__bookingsQuickActionHydratorBound) {
            window.__bookingsQuickActionHydratorBound = true;
            document.addEventListener('rh:content-updated', function() {
                if (!/\/admin\/bookings\.php$/i.test(window.location.pathname)) {
                    return;
                }
                const bookingResults = document.getElementById('booking-results');
                hydrateQuickActionButtons(bookingResults || document);
            });
        }

        // ── AJAX results loading — only #booking-results reloads on tab/page switch ──
        async function loadBookingResults(url) {
            const container = document.getElementById('booking-results');
            if (!container) {
                window.location.href = url;
                return;
            }

            // Create and show minimal loader within the table section
            let loader = container.querySelector('.minimal-loader');
            if (!loader) {
                loader = document.createElement('div');
                loader.className = 'minimal-loader';
                loader.style.cssText = `
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: rgba(255, 255, 255, 0.95);
                    padding: 20px 30px;
                    border-radius: 10px;
                    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
                    z-index: 100;
                    display: none;
                    align-items: center;
                    gap: 12px;
                    pointer-events: none;
                `;
                loader.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size: 20px; color: var(--gold, #8B7355);"></i><span style="font-size: 14px; color: #444; font-weight: 500;">Loading...</span>';
                container.style.position = 'relative';
                container.appendChild(loader);
            }
            loader.style.display = 'flex';
            container.style.opacity = '0.6';
            container.style.pointerEvents = 'none';
            container.style.transition = 'opacity 0.15s ease';

            try {
                const res = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Cache-Control': 'no-cache'
                    }
                });
                if (!res.ok) throw new Error('Network error');
                const html = await res.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');

                // Swap results container content
                const fresh = doc.getElementById('booking-results');
                if (fresh) container.innerHTML = fresh.innerHTML;
                hydrateQuickActionButtons(container);

                // Keep pagination/tab navigation focused on the booking table area.
                const topAnchor = container.querySelector('.bookings-section .table-responsive, .bookings-section table.booking-table, .bookings-section') || container;
                const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const targetTop = Math.max(0, (topAnchor.getBoundingClientRect().top + window.scrollY) - 88);
                window.scrollTo({
                    top: targetTop,
                    behavior: prefersReducedMotion ? 'auto' : 'smooth'
                });

                // Update tab badge counts from the freshly fetched page
                doc.querySelectorAll('.tab-button[data-tab]').forEach(btn => {
                    const local = document.querySelector('.tab-button[data-tab="' + btn.dataset.tab + '"]');
                    if (!local) return;
                    local.dataset.count = btn.dataset.count;
                    const span = local.querySelector('.tab-count');
                    if (span) span.textContent = btn.dataset.count;
                });

                // Update stat cards
                const freshNums = doc.querySelectorAll('.stat-card .number');
                document.querySelectorAll('.stat-card .number').forEach(function(n, i) {
                    if (freshNums[i]) n.textContent = freshNums[i].textContent;
                });

                // Push URL and re-apply visual tab state
                history.pushState({
                    url: url
                }, '', url);
                const activeTab = fresh ? (fresh.dataset.activeTab || 'all') : 'all';
                switchTab(activeTab);

            } catch (_) {
                window.location.href = url;
            } finally {
                if (loader) loader.style.display = 'none';
                container.style.opacity = '';
                container.style.pointerEvents = '';
            }
        }

        function navigateToTab(tabName) {
            const params = new URLSearchParams(window.location.search);
            params.delete('filter');
            params.delete('filter_status');
            params.delete('page');
            const tabFilter = TAB_FILTER_MAP[tabName];
            if (tabFilter) {
                for (const [k, v] of Object.entries(tabFilter)) {
                    params.set(k, v);
                }
            }
            loadBookingResults('bookings.php' + (params.toString() ? '?' + params.toString() : ''));
        }

        // Browser back/forward support
        window.addEventListener('popstate', function() {
            loadBookingResults(window.location.href);
        });

        // Initialize — re-runs on every SPA navigation to this page.
        // NOTE: runs immediately when re-executed by admin-spa.js (DOMContentLoaded already fired).
        (function initBookingsPage() {
            const activeTab = <?php echo json_encode($active_tab_override ?: 'all'); ?>;

            // Register tab handler for the global admin-components.js tab system.
            // It will be called whenever any .tab-button is clicked on this page.
            window.__pageTabHandler = function(tabName, tabBtn) {
                if (tabBtn && !tabBtn.closest('.tabs-header')) return;
                if (tabName === 'all') {
                    const params = new URLSearchParams(window.location.search);
                    params.delete('filter');
                    params.delete('filter_status');
                    params.delete('page');
                    loadBookingResults('bookings.php' + (params.toString() ? '?' + params.toString() : ''));
                } else if (tabName) {
                    navigateToTab(tabName);
                }
            };

            // Pagination: delegated listener for .pg-btn links inside #booking-results
            if (!window.__bookingsPaginationBound) {
                window.__bookingsPaginationBound = true;
                document.addEventListener('click', function(e) {
                    if (!/bookings\.php/i.test(window.location.pathname)) return;
                    const pgBtn = e.target.closest('.pg-btn');
                    if (pgBtn && pgBtn.href) {
                        e.preventDefault();
                        loadBookingResults(pgBtn.href);
                    }
                });
            }

            // Highlight correct tab on initial load
            document.querySelectorAll('.tab-button').forEach(function(button) {
                button.classList.toggle('active', button.dataset.tab === activeTab);
            });

            // Apply visual filter on loaded rows
            switchTab(activeTab);
            hydrateQuickActionButtons(document);
        })();

        async function makeTentative(id, button) {
            const confirmed = await confirmAdminAction({
                title: 'Make booking tentative',
                message: 'Convert this pending booking to a tentative reservation?',
                details: [
                    'The room will be held for 48 hours.',
                    'A confirmation email will be sent to the guest.'
                ],
                confirmText: 'Make Tentative',
                icon: 'fa-hourglass-half'
            });
            if (!confirmed) return;

            // Show loading state
            if (button) {
                setButtonLoading(button, true);
            } else {
                const activeBtn = document.activeElement;
                if (activeBtn && activeBtn.classList.contains('quick-action')) {
                    setButtonLoading(activeBtn, true);
                }
            }
            showLoadingOverlay('Converting to tentative...');

            const formData = new FormData();
            formData.append('action', 'make_tentative');
            formData.append('id', id);

            postBookingAction(formData, 'Error converting booking to tentative')
                .then(data => reloadWithBookingActionMessage(data, 'Booking moved to tentative successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (button) {
                        setButtonLoading(button, false);
                    }
                    Alert.show(error.message || 'Error converting booking to tentative', 'error');
                });
        }

        async function convertTentativeBooking(id) {
            const confirmed = await confirmAdminAction({
                title: 'Confirm reservation',
                message: 'Convert this tentative booking to a confirmed reservation?',
                details: ['A confirmation email will be sent to the guest.'],
                confirmText: 'Confirm Booking',
                icon: 'fa-circle-check',
                tone: 'success'
            });
            if (!confirmed) return;

            const activeBtn = document.activeElement;
            const isQuickAction = activeBtn && activeBtn.classList.contains('quick-action');

            if (isQuickAction) {
                setButtonLoading(activeBtn, true);
            }
            showLoadingOverlay('Converting booking to confirmed...');

            const formData = new FormData();
            formData.append('action', 'convert_tentative');
            formData.append('id', id);

            postBookingAction(formData, 'Error converting booking')
                .then(data => reloadWithBookingActionMessage(data, 'Tentative booking confirmed successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (isQuickAction) {
                        setButtonLoading(activeBtn, false);
                    }
                    Alert.show(error.message || 'Error converting booking', 'error');
                });
        }

        async function convertToTentative(id) {
            const confirmed = await confirmAdminAction({
                title: 'Move booking to tentative',
                message: 'Convert this confirmed booking to tentative?',
                details: [
                    'The booking will be placed on hold for 48 hours.',
                    'An email will be sent to the guest.'
                ],
                confirmText: 'Move to Tentative',
                icon: 'fa-clock'
            });
            if (!confirmed) return;

            const activeBtn = document.activeElement;
            const isQuickAction = activeBtn && activeBtn.classList.contains('quick-action');

            if (isQuickAction) {
                setButtonLoading(activeBtn, true);
            }
            showLoadingOverlay('Converting booking to tentative...');

            const formData = new FormData();
            formData.append('action', 'convert_to_tentative');
            formData.append('id', id);

            postBookingAction(formData, 'Error converting booking to tentative')
                .then(data => reloadWithBookingActionMessage(data, 'Booking converted to tentative successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (isQuickAction) {
                        setButtonLoading(activeBtn, false);
                    }
                    Alert.show(error.message || 'Error converting booking to tentative', 'error');
                });
        }

        function updateStatus(id, status) {
            // Find the button that was clicked (if any)
            const activeBtn = document.activeElement;
            const isQuickAction = activeBtn && activeBtn.classList.contains('quick-action');

            // Show loading state on button
            if (isQuickAction) {
                setButtonLoading(activeBtn, true);
            }

            // Show global loading overlay
            showLoadingOverlay('Updating booking status...');

            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('id', id);
            formData.append('status', status);

            postBookingAction(formData, 'Error updating status')
                .then(data => reloadWithBookingActionMessage(data, 'Booking status updated successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (isQuickAction) {
                        setButtonLoading(activeBtn, false);
                    }
                    Alert.show(error.message || 'Error updating status', 'error');
                });
        }

        function updatePayment(id, payment_status) {
            const activeBtn = document.activeElement;
            const isQuickAction = activeBtn && activeBtn.classList.contains('quick-action');

            if (isQuickAction) {
                setButtonLoading(activeBtn, true);
            }
            showLoadingOverlay('Updating payment status...');

            const formData = new FormData();
            formData.append('action', 'update_payment');
            formData.append('id', id);
            formData.append('payment_status', payment_status);

            postBookingAction(formData, 'Error updating payment')
                .then(data => reloadWithBookingActionMessage(data, 'Payment status updated successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (isQuickAction) {
                        setButtonLoading(activeBtn, false);
                    }
                    Alert.show(error.message || 'Error updating payment', 'error');
                });
        }

        async function cancelBooking(id, reference) {
            const reason = await promptAdminAction({
                title: 'Cancel booking',
                message: 'Cancel booking ' + reference + '?',
                details: ['The booking status will change to cancelled.', 'Room availability will be released where applicable.'],
                confirmText: 'Cancel Booking',
                inputLabel: 'Cancellation reason (optional)',
                inputPlaceholder: 'Example: Guest requested cancellation',
                icon: 'fa-ban',
                tone: 'danger'
            });
            if (reason === null) {
                return;
            }

            const activeBtn = document.activeElement;
            const isQuickAction = activeBtn && activeBtn.classList.contains('quick-action');

            if (isQuickAction) {
                setButtonLoading(activeBtn, true);
            }
            showLoadingOverlay('Cancelling booking...');

            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('id', id);
            formData.append('status', 'cancelled');
            formData.append('cancellation_reason', reason || 'Cancelled by admin');

            postBookingAction(formData, 'Error cancelling booking')
                .then(data => reloadWithBookingActionMessage(data, 'Booking cancelled successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (isQuickAction) {
                        setButtonLoading(activeBtn, false);
                    }
                    Alert.show(error.message || 'Error cancelling booking', 'error');
                });
        }

        async function checkoutBooking(id, reference) {
            // Close any open bookings-alert-modal (e.g. overdueCheckoutsModal) so the
            // settlement modal or confirm dialog is not hidden behind it (z-index: 4000).
            document.querySelectorAll('.bookings-alert-modal.active').forEach(function(m) {
                setBookingPageModalOpen(m, false);
            });

            // Step 1: assess whether a financial settlement is needed
            const assessData = new FormData();
            assessData.append('action', 'checkout_assess');
            assessData.append('id', id);

            let settlement = null;
            try {
                showLoadingOverlay('Checking settlement...');
                const assessResp = await postBookingAction(assessData, 'Failed to assess checkout');
                settlement = assessResp.settlement || {
                    needed: false,
                    type: 'none'
                };
            } catch (err) {
                hideLoadingOverlay();
                Alert.show(err.message || 'Could not assess checkout', 'error');
                return;
            }
            hideLoadingOverlay();

            if (!settlement.needed) {
                // Standard on-time checkout: simple confirm then execute
                const confirmed = await confirmAdminAction({
                    title: 'Confirm checkout',
                    message: 'Check out booking ' + reference + '?',
                    details: ['The booking will be marked as checked out.', 'Room status and audit history will be updated.'],
                    confirmText: 'Check Out',
                    icon: 'fa-right-from-bracket'
                });
                if (!confirmed) return;

                showLoadingOverlay('Checking out booking...');

                const formData = new FormData();
                formData.append('action', 'checkout');
                formData.append('id', id);
                postBookingAction(formData, 'Error checking out booking')
                    .then(data => reloadWithBookingActionMessage(data, 'Booking checked out successfully.'))
                    .catch(error => {
                        hideLoadingOverlay();
                        Alert.show(error.message || 'Error checking out booking', 'error');
                    });
            } else {
                // Open the settlement modal
                openCheckoutSettlementModal(id, reference, settlement);
            }
        }

        // ── Checkout Settlement Modal ─────────────────────────────────────────
        function fmt(n) {
            return _currencySymbol + ' ' + parseFloat(n).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function openCheckoutSettlementModal(bookingId, reference, s) {
            const modal = document.getElementById('checkoutSettlementModal');
            if (!modal) return;

            document.getElementById('cs_booking_id').value = bookingId;
            document.getElementById('cs_booking_ref').value = reference;
            document.getElementById('cs_settlement_type').value = s.type;

            const titleEl = document.getElementById('cs_title');
            const bodyEl = document.getElementById('cs_body');
            const chargeRow = document.getElementById('cs_charge_row');
            const refundRow = document.getElementById('cs_refund_row');
            const proceedBtn = document.getElementById('cs_proceed_btn');

            function csGuestInfo(s, ref) {
                const name = s.guest_name ? escHtml(s.guest_name) : escHtml(ref);
                const room = s.room_label ? ' &nbsp;|&nbsp; Room <strong>' + escHtml(s.room_label) + '</strong>' : '';
                return '<div style="background:#f7f3ee;border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:13px;line-height:1.7;">' +
                    '<strong>' + name + '</strong>' + room + '<br>' +
                    '<span style="color:#666;">Ref: ' + escHtml(s.booking_ref || ref) + '</span>&nbsp;&nbsp;' +
                    '<span style="color:#666;">Check-in: ' + escHtml(s.check_in_date || '') + '</span>' +
                    '</div>';
            }

            if (s.type === 'charge') {
                titleEl.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#ffc107;"></i> Overdue Checkout — Extra Charges';
                bodyEl.innerHTML =
                    csGuestInfo(s, reference) +
                    '<p style="margin:0 0 10px;">Guest stayed <strong>' + s.actual_nights + ' nights</strong> ' +
                    'but was only scheduled for <strong>' + s.sched_nights + ' night(s)</strong>. ' +
                    'Scheduled checkout was <strong>' + escHtml(s.sched_checkout) + '</strong>.</p>' +
                    '<table class="cs-table">' +
                    '<tr><td>Extra nights</td><td><strong>' + s.extra_nights + '</strong></td></tr>' +
                    '<tr><td>Rate per night</td><td>' + fmt(s.price_per_night) + '</td></tr>' +
                    '<tr><td>Amount paid so far</td><td>' + fmt(s.amount_paid) + '</td></tr>' +
                    '<tr class="cs-total"><td>Amount to charge</td><td><strong>' + fmt(s.charge_amount) + '</strong></td></tr>' +
                    '</table>';
                chargeRow.style.display = '';
                refundRow.style.display = 'none';
                proceedBtn.textContent = 'Charge & Checkout';
                proceedBtn.style.background = '#dc3545';
            } else {
                titleEl.innerHTML = '<i class="fas fa-rotate-left" style="color:#28a745;"></i> Early Checkout — Refund';
                bodyEl.innerHTML =
                    csGuestInfo(s, reference) +
                    '<p style="margin:0 0 10px;">Guest is checking out after <strong>' + s.actual_nights + ' night(s)</strong> ' +
                    'but was booked for <strong>' + s.sched_nights + ' nights</strong>. ' +
                    'Scheduled checkout was <strong>' + escHtml(s.sched_checkout) + '</strong>.</p>' +
                    '<table class="cs-table">' +
                    '<tr><td>Unused nights</td><td><strong>' + s.unused_nights + '</strong></td></tr>' +
                    '<tr><td>Rate per night</td><td>' + fmt(s.price_per_night) + '</td></tr>' +
                    '<tr><td>Amount paid</td><td>' + fmt(s.amount_paid) + '</td></tr>' +
                    '<tr><td>Full refund value</td><td>' + fmt(s.refund_amount) + '</td></tr>' +
                    '<tr class="cs-total"><td>Refundable (capped at paid)</td><td><strong>' + fmt(s.refundable) + '</strong></td></tr>' +
                    '</table>' +
                    (s.refundable <= 0 ? '<p style="color:#888;font-size:12px;">No payment on record — no refund will be issued.</p>' : '');
                chargeRow.style.display = 'none';
                refundRow.style.display = s.refundable > 0 ? '' : 'none';
                proceedBtn.textContent = s.refundable > 0 ? 'Refund & Checkout' : 'Checkout (no refund)';
                proceedBtn.style.background = '#28a745';
            }

            setBookingPageModalOpen(modal, true);
        }

        function closeCheckoutSettlementModal() {
            const modal = document.getElementById('checkoutSettlementModal');
            if (modal) setBookingPageModalOpen(modal, false);
        }

        // Exported for the overdue-checkout modal's inline onclick buttons —
        // reliable under SPA re-execution (see note by the modal handlers below).
        window.checkoutBooking = checkoutBooking;
        window.openCheckoutSettlementModal = openCheckoutSettlementModal;
        window.closeCheckoutSettlementModal = closeCheckoutSettlementModal;

        function escHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        const bookingsStatsInsights = <?php echo json_encode($bookings_stats_insights, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let bookingsStatsInsightLastTrigger = null;

        function formatBookingsInsightDate(value) {
            const rawValue = String(value || '').trim();
            if (rawValue === '') {
                return '—';
            }

            const parsed = new Date(rawValue + 'T00:00:00');
            if (Number.isNaN(parsed.getTime())) {
                return rawValue;
            }

            return parsed.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        }

        function formatBookingsInsightCurrency(value) {
            const numericValue = Number.parseFloat(value);
            if (!Number.isFinite(numericValue)) {
                return _currencySymbol + ' 0.00';
            }

            return _currencySymbol + ' ' + numericValue.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function formatBookingsInsightStatus(value) {
            return String(value || '')
                .replace(/[_-]/g, ' ')
                .replace(/\b\w/g, function(match) {
                    return match.toUpperCase();
                });
        }

        function renderBookingsStatsInsight(cardKey) {
            const payload = bookingsStatsInsights && Object.prototype.hasOwnProperty.call(bookingsStatsInsights, cardKey) ? bookingsStatsInsights[cardKey] : null;
            const titleEl = document.getElementById('bookingsStatsInsightTitle');
            const subtitleEl = document.getElementById('bookingsStatsInsightSubtitle');
            const summaryEl = document.getElementById('bookingsStatsInsightSummary');
            const rowsEl = document.getElementById('bookingsStatsInsightRows');
            const linkEl = document.getElementById('bookingsStatsInsightLink');

            if (!payload || !titleEl || !subtitleEl || !summaryEl || !rowsEl || !linkEl) {
                return false;
            }

            const countValue = Number.parseInt(payload.count, 10);
            const totalLabel = Number.isFinite(countValue) ? countValue.toLocaleString('en-US') : '0';
            const rows = Array.isArray(payload.rows) ? payload.rows : [];

            titleEl.innerHTML = '<i class="fas fa-chart-pie"></i> ' + escHtml(payload.title || 'Booking Insight');
            subtitleEl.textContent = payload.subtitle || 'Latest booking records.';
            summaryEl.innerHTML =
                '<div class="bookings-stats-insight-modal__kpi">' + escHtml(totalLabel) + '</div>' +
                '<p>' + escHtml(payload.summary || 'Latest records for this booking card.') + '</p>';

            if (!rows.length) {
                rowsEl.innerHTML = '<tr class="bookings-alert-modal__empty-row"><td colspan="6">' + escHtml(payload.empty || 'No matching records right now.') + '</td></tr>';
            } else {
                rowsEl.innerHTML = rows.map(function(row) {
                    const detailsHref = row.details_href ? String(row.details_href) : ('booking-details.php?id=' + encodeURIComponent(String(row.id || '0')));
                    const stayText = formatBookingsInsightDate(row.check_in) + ' - ' + formatBookingsInsightDate(row.check_out);
                    const paymentLabel = formatBookingsInsightStatus(row.payment_status || 'unknown');
                    const statusLabel = formatBookingsInsightStatus(row.status || 'unknown');

                    return '<tr>' +
                        '<td data-label="Reference"><strong>' + escHtml(row.reference || '—') + '</strong></td>' +
                        '<td data-label="Guest">' + escHtml(row.guest_name || '—') + '<br><small class="bookings-stats-insight-modal__muted">' + escHtml(statusLabel) + '</small></td>' +
                        '<td data-label="Room">' + escHtml(row.room || 'Unassigned') + '</td>' +
                        '<td data-label="Stay">' + escHtml(stayText) + '</td>' +
                        '<td data-label="Total">' + escHtml(formatBookingsInsightCurrency(row.amount)) + '<br><small class="bookings-stats-insight-modal__muted">' + escHtml(paymentLabel) + '</small></td>' +
                        '<td data-label="Actions"><a class="btn btn-sm btn-primary bookings-stats-insight-modal__row-action" href="' + escHtml(detailsHref) + '" data-no-spa="1" data-no-admin-loader="1">View</a></td>' +
                        '</tr>';
                }).join('');
            }

            const payloadLink = payload.link && typeof payload.link === 'object' ? payload.link : null;
            if (payloadLink && payloadLink.href) {
                linkEl.href = String(payloadLink.href);
                linkEl.textContent = payloadLink.label || 'Open full list';
                linkEl.style.display = '';
            } else {
                linkEl.style.display = 'none';
            }

            return true;
        }

        function openBookingsStatsInsightModal(cardKey, triggerEl) {
            const modal = document.getElementById('bookingsStatsInsightModal');
            if (!modal || !renderBookingsStatsInsight(cardKey)) {
                return;
            }

            bookingsStatsInsightLastTrigger = triggerEl instanceof HTMLElement ? triggerEl : null;
            setBookingPageModalOpen(modal, true);

            const closeBtn = modal.querySelector('.bookings-alert-modal__close');
            if (closeBtn instanceof HTMLElement) {
                requestAnimationFrame(function() {
                    closeBtn.focus();
                });
            }
        }

        function closeBookingsStatsInsightModal() {
            const modal = document.getElementById('bookingsStatsInsightModal');
            if (!modal) {
                return;
            }

            setBookingPageModalOpen(modal, false);

            if (bookingsStatsInsightLastTrigger instanceof HTMLElement && document.contains(bookingsStatsInsightLastTrigger)) {
                requestAnimationFrame(function() {
                    bookingsStatsInsightLastTrigger.focus();
                });
            }
        }

        async function handleCheckoutSettlementSubmit(e) {
            e.preventDefault();
            const form = e.target;
            if (!form || form.id !== 'checkoutSettlementForm') {
                return;
            }

            const bookingId = document.getElementById('cs_booking_id').value;
            const bookingRef = document.getElementById('cs_booking_ref').value;
            const settleType = document.getElementById('cs_settlement_type').value;
            const payMethod = document.getElementById('cs_payment_method').value;
            const refPayMethod = document.getElementById('cs_refund_payment_method').value;
            const skipToggle = document.getElementById('cs_skip_toggle').checked;
            const notes = document.getElementById('cs_notes').value;

            let settlementAction = skipToggle ? 'skip' : settleType;

            const formData = new FormData(form);
            formData.set('action', 'checkout_settle');
            formData.set('id', bookingId);
            formData.set('settlement_action', settlementAction);
            formData.set('payment_method', settleType === 'charge' ? payMethod : refPayMethod);
            formData.set('notes', notes);

            const submitBtn = form.querySelector('#cs_proceed_btn') || form.querySelector('button[type="submit"]');
            const confirmed = await confirmAdminAction({
                title: 'Confirm checkout',
                message: 'Complete checkout for booking ' + (bookingRef || ('#' + bookingId)) + '?',
                details: [
                    'Settlement option: ' + (settlementAction === 'skip' ? 'No financial adjustment' : settlementAction),
                    'This will mark the booking as checked out and update room status.'
                ],
                confirmText: 'Complete Checkout',
                icon: 'fa-right-from-bracket'
            });
            if (!confirmed) {
                return;
            }

            if (submitBtn) setButtonLoading(submitBtn, true);
            setModalActionLoading(form, true, 'Processing settlement and checkout...');

            try {
                const data = await postBookingAction(formData, 'Checkout failed');
                if (data.success) {
                    closeCheckoutSettlementModal();
                    reloadWithBookingActionMessage(data, 'Checkout completed successfully.');
                } else {
                    setModalActionLoading(form, false);
                    if (submitBtn) setButtonLoading(submitBtn, false);
                    Alert.show(data.message || 'Checkout failed.', 'error');
                }
            } catch (err) {
                setModalActionLoading(form, false);
                if (submitBtn) setButtonLoading(submitBtn, false);
                Alert.show(err.message || 'Checkout failed.', 'error');
            }
        }

        document.addEventListener('submit', function(e) {
            if (e.target && e.target.id === 'checkoutSettlementForm') {
                handleCheckoutSettlementSubmit(e);
            }
        });

        function markNoShow(id, reference, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus) {
            openCheckInModal(
                id,
                reference || '',
                guestName || '',
                checkInDate || '',
                paymentStatus || '',
                roomAssigned || false,
                bookingStatus || 'confirmed',
                'noshow'
            );
        }
    </script>

    <!-- Email Resend Modal -->
    <div id="resendEmailModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Resend Email</h3>
                <button class="close-modal" onclick="closeResendEmailModal()">&times;</button>
            </div>
            <form id="resendEmailForm" method="POST" action="">
                <input type="hidden" name="action" value="resend_email">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="booking_id" id="modal_booking_id" value="">

                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                        <input type="text" id="modal_booking_reference" class="form-control" readonly style="background: #f5f5f5;">
                    </div>

                    <div class="form-group">
                        <label for="email_type"><i class="fas fa-envelope"></i> Email Type:</label>
                        <select name="email_type" id="email_type" class="form-control" required>
                            <option value="">-- Select Email Type --</option>
                            <option value="booking_received">Booking Received (Initial confirmation)</option>
                            <option value="booking_confirmed">Booking Confirmed</option>
                            <option value="tentative_confirmed" id="opt_tentative_confirmed">Tentative Booking Confirmed</option>
                            <option value="tentative_converted" id="opt_tentative_converted">Tentative Converted to Confirmed</option>
                            <option value="booking_cancelled">Booking Cancelled</option>
                            <option value="invoice">Invoice</option>
                            <option value="booking_reminder" id="opt_booking_reminder" style="display:none;">&#9888; Check-in Reminder (Late / Overdue)</option>
                        </select>
                        <small style="color: #666;">Select the type of email to resend based on current booking status</small>
                    </div>

                    <div class="form-group">
                        <label for="cc_emails"><i class="fas fa-users"></i> CC Emails (Optional):</label>
                        <input type="text" name="cc_emails" id="cc_emails" class="form-control" placeholder="email1@example.com, email2@example.com">
                        <small style="color: #666;">Comma-separated email addresses to CC</small>
                    </div>

                    <!-- Inline success state (replaces toast on mobile) -->
                    <div id="resendEmailSuccess" style="display:none;text-align:center;padding:18px 12px;">
                        <div style="font-size:2.2rem;color:#28a745;margin-bottom:8px;"><i class="fas fa-circle-check"></i></div>
                        <div style="font-weight:600;color:#1a4731;font-size:1rem;" id="resendEmailSuccessMsg">Email sent!</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeResendEmailModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="resendEmailSubmitBtn"><i class="fas fa-paper-plane"></i> Send Email</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Room Assignment Modal -->
    <div id="quickRoomAssignModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3><i class="fas fa-door-open"></i> Assign Room</h3>
                <button class="close-modal" onclick="closeQuickRoomAssignModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                    <input type="text" id="quick_assign_booking_ref" class="form-control" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Dates:</label>
                    <input type="text" id="quick_assign_dates" class="form-control" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-door-open"></i> Select Individual Room:</label>
                    <div id="quick_assign_room_list" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px; padding: 10px;">
                        <div style="text-align: center; padding: 20px; color: #666;">
                            <i class="fas fa-spinner fa-spin"></i> Loading available rooms...
                        </div>
                    </div>
                </div>
                <input type="hidden" id="quick_assign_booking_id">
                <input type="hidden" id="quick_assign_room_id">
                <div id="quick_assign_override_panel" class="form-group" style="display:none; border: 1px solid #f0c36d; background: #fff8e1; border-radius: 8px; padding: 12px;">
                    <label style="display:flex; gap: 8px; align-items:flex-start; font-weight:600; color:#7a4f01;">
                        <input type="checkbox" id="quick_assign_children_override" style="margin-top: 3px;">
                        Override child room policy for this assignment
                    </label>
                    <textarea id="quick_assign_override_note" class="form-control" rows="3" placeholder="Required note explaining why this room is being assigned for a booking with children" style="margin-top: 10px;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeQuickRoomAssignModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitQuickRoomAssign()"><i class="fas fa-check"></i> Assign Room</button>
            </div>
        </div>
    </div>

    <!-- Make Tentative Modal -->
    <div id="makeTentativeModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-clock"></i> Make Tentative</h3>
                <button class="close-modal" onclick="closeMakeTentativeModal()">&times;</button>
            </div>
            <form id="makeTentativeForm" method="POST" action="">
                <input type="hidden" name="action" id="make_tentative_action" value="">
                <input type="hidden" name="id" id="make_tentative_booking_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                        <input type="text" id="make_tentative_ref" class="form-control" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label for="tentative_note"><i class="fas fa-sticky-note"></i> Optional Note:</label>
                        <textarea name="note" id="tentative_note" class="form-control" rows="3" placeholder="Add a note about why this booking is being made tentative..."></textarea>
                        <small style="color: #666;">This note will be recorded in the booking log.</small>
                    </div>
                    <div class="form-group" style="background: #fff8e1; padding: 12px; border-radius: 8px;">
                        <p style="margin: 0; color: #8B7355; font-size: 13px;">
                            <i class="fas fa-info-circle"></i>
                            This will convert the booking to a tentative reservation, holding the room for
                            <strong id="tentative_duration_display">48</strong> hours. A confirmation email will be sent to the guest.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMakeTentativeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Make Tentative</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Check In Modal -->
    <div id="checkInModal" class="modal-overlay bookings-checkin-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="modal-content bookings-checkin-modal__dialog">
            <div class="modal-header bookings-checkin-modal__header">
                <div class="bookings-checkin-modal__title-wrap">
                    <h3 id="checkin_modal_title"><i class="fas fa-sign-in-alt"></i> Check In Guest</h3>
                    <p id="checkin_modal_subtitle" class="bookings-checkin-modal__subtitle">Verify prerequisites and complete guest arrival.</p>
                </div>
                <button class="close-modal" type="button" onclick="closeCheckInModal()" aria-label="Close check-in modal">&times;</button>
            </div>
            <form id="checkInForm" method="POST" action="">
                <input type="hidden" name="action" id="checkin_action" value="update_status">
                <input type="hidden" name="status" id="checkin_status" value="checked-in">
                <input type="hidden" name="id" id="checkin_booking_id" value="">
                <input type="hidden" id="checkin_modal_mode" value="checkin">
                <div class="modal-body bookings-checkin-modal__body">
                    <div class="bookings-checkin-modal__mode-row">
                        <span id="checkin_mode_badge" class="bookings-checkin-modal__mode-badge">Standard Check-In</span>
                        <span id="checkin_mode_hint" class="bookings-checkin-modal__mode-hint">Proceed when all prerequisites are satisfied.</span>
                    </div>

                    <div id="checkin_context_note" class="bookings-checkin-modal__context-note" style="display: none;"></div>

                    <div class="bookings-checkin-modal__identity-grid">
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Booking Reference</label>
                            <input type="text" id="checkin_booking_ref" class="form-control bookings-checkin-modal__readonly" readonly>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Scheduled Check-in</label>
                            <input type="text" id="checkin_date" class="form-control bookings-checkin-modal__readonly" readonly>
                        </div>
                        <div class="form-group bookings-checkin-modal__identity-grid-full">
                            <label><i class="fas fa-user"></i> Guest Name</label>
                            <input type="text" id="checkin_guest_name" class="form-control bookings-checkin-modal__readonly" readonly>
                        </div>
                    </div>

                    <div class="prerequisites bookings-checkin-modal__prerequisites" id="checkin_prerequisites">
                        <h4><i class="fas fa-list-check"></i> Prerequisites</h4>
                        <ul>
                            <li id="prereq_payment"><i class="fas fa-times-circle"></i> Payment must be marked as PAID</li>
                            <li id="prereq_room"><i class="fas fa-times-circle"></i> A room must be assigned</li>
                            <li id="prereq_status"><i class="fas fa-times-circle"></i> Booking must be CONFIRMED</li>
                        </ul>
                    </div>

                    <div class="form-group" id="checkin_note_group">
                        <label for="checkin_note"><i class="fas fa-sticky-note"></i> Optional Note</label>
                        <textarea name="checkin_note" id="checkin_note" class="form-control" rows="2" placeholder="Add any check-in or no-show notes..."></textarea>
                    </div>

                    <p id="checkin_error_message" class="bookings-checkin-modal__error" style="display: none;"></p>
                </div>
                <div class="modal-footer bookings-checkin-modal__footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCheckInModal()">Cancel</button>
                    <button type="submit" id="checkin_submit_btn" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Check In</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cancel Booking Modal -->
    <div id="cancelBookingModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Cancel Booking</h3>
                <button class="close-modal" onclick="closeCancelBookingModal()">&times;</button>
            </div>
            <form id="cancelBookingForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="status" value="cancelled">
                <input type="hidden" name="id" id="cancel_booking_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                        <input type="text" id="cancel_booking_ref" class="form-control" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Guest Name:</label>
                        <input type="text" id="cancel_guest_name" class="form-control" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label for="cancellation_reason"><i class="fas fa-comment"></i> Cancellation Reason (Optional):</label>
                        <textarea name="cancellation_reason" id="cancellation_reason" class="form-control" rows="3" placeholder="Reason for cancellation..."></textarea>
                        <small style="color: #666;">This reason will be included in the cancellation email and logs.</small>
                    </div>
                    <div class="form-group" style="background: #f8f9fa; padding: 12px; border-radius: 8px;">
                        <p style="margin: 0; color: #666; font-size: 13px;">
                            <i class="fas fa-info-circle"></i>
                            Cancelling this booking will restore room availability and send a cancellation email to the guest.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCancelBookingModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-times"></i> Cancel Booking</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Missed Check-ins Modal -->
    <div id="missedCheckinsModal" class="modal-overlay bookings-alert-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="modal-content bookings-alert-modal__dialog bookings-alert-modal__dialog--wide">
            <div class="modal-header bookings-alert-modal__header bookings-alert-modal__header--danger">
                <div class="bookings-alert-modal__title-wrap">
                    <p class="bookings-alert-modal__eyebrow">Dashboard Insight</p>
                    <h3><i class="fas fa-exclamation-triangle"></i> Missed Check-ins</h3>
                    <p class="bookings-alert-modal__subtitle">Confirmed bookings that passed check-in date without arrival.</p>
                </div>
                <button class="close-modal bookings-alert-modal__close" type="button" onclick="closeMissedCheckinsModal()" aria-label="Close missed check-ins modal"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body bookings-alert-modal__body">
                <div class="bookings-alert-modal__notice bookings-alert-modal__notice--danger">
                    <i class="fas fa-triangle-exclamation"></i>
                    <p>Handle these overdue arrivals by completing a late check-in, marking no-show, or cancelling the booking with a reason.</p>
                </div>
                <div class="bookings-alert-modal__table-wrap">
                    <table class="booking-table bookings-alert-modal__table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Check-in Date</th>
                                <th>Overdue</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($missed_checkins)): foreach ($missed_checkins as $bk): ?>
                                    <tr>
                                        <td data-label="Reference"><strong><?php echo htmlspecialchars($bk['booking_reference']); ?></strong></td>
                                        <td data-label="Guest"><?php echo htmlspecialchars($bk['guest_name']); ?><br><small><?php echo htmlspecialchars($bk['guest_phone']); ?></small></td>
                                        <td data-label="Room"><?php echo htmlspecialchars($bk['room_name']); ?><br><small><?php echo $bk['individual_room_name'] ? htmlspecialchars($bk['individual_room_name'] . ' (' . $bk['individual_room_number'] . ')') : 'Unassigned'; ?></small></td>
                                        <td data-label="Check-in Date" class="bookings-alert-modal__overdue-cell"><?php echo date('M j, Y', strtotime($bk['check_in_date'])); ?></td>
                                        <td data-label="Overdue"><span class="badge bookings-alert-modal__badge bookings-alert-modal__badge--danger"><?php echo $bk['days_overdue']; ?> day(s)</span></td>
                                        <td data-label="Actions" class="bookings-alert-modal__actions">
                                            <button class="btn btn-sm btn-primary bookings-alert-modal__action-btn" type="button" onclick="openMissedCheckinWorkflow(<?php echo $bk['id']; ?>, '<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($bk['guest_name'], ENT_QUOTES); ?>', '<?php echo $bk['check_in_date']; ?>', '<?php echo htmlspecialchars((string)($bk['actual_payment_status'] ?? $bk['payment_status']), ENT_QUOTES); ?>', '<?php echo !empty($bk['individual_room_id']) ? 'true' : 'false'; ?>', '<?php echo $bk['status']; ?>', 'checkin')">Late Check-in</button>
                                            <button class="btn btn-sm btn-danger bookings-alert-modal__action-btn" type="button" onclick="openMissedCheckinWorkflow(<?php echo $bk['id']; ?>, '<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($bk['guest_name'], ENT_QUOTES); ?>', '<?php echo $bk['check_in_date']; ?>', '<?php echo htmlspecialchars((string)($bk['actual_payment_status'] ?? $bk['payment_status']), ENT_QUOTES); ?>', '<?php echo !empty($bk['individual_room_id']) ? 'true' : 'false'; ?>', '<?php echo $bk['status']; ?>', 'noshow')">Mark No-Show</button>
                                            <button class="btn btn-sm btn-secondary bookings-alert-modal__action-btn" type="button" onclick="openMissedCancelWorkflow(<?php echo $bk['id']; ?>, '<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($bk['guest_name'], ENT_QUOTES); ?>')">Cancel Booking</button>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr class="bookings-alert-modal__empty-row">
                                    <td colspan="6">No missed check-ins.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeMissedCheckinsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Overdue Checkouts Modal -->
    <div id="overdueCheckoutsModal" class="modal-overlay bookings-alert-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="modal-content bookings-alert-modal__dialog bookings-alert-modal__dialog--wide">
            <div class="modal-header bookings-alert-modal__header bookings-alert-modal__header--warning">
                <div class="bookings-alert-modal__title-wrap">
                    <p class="bookings-alert-modal__eyebrow">Dashboard Insight</p>
                    <h3><i class="fas fa-triangle-exclamation"></i> Overdue Checkouts</h3>
                    <p class="bookings-alert-modal__subtitle">Checked-in guests whose scheduled checkout date has already passed.</p>
                </div>
                <button class="close-modal bookings-alert-modal__close" type="button" onclick="closeOverdueCheckoutsModal()" aria-label="Close overdue checkouts modal"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body bookings-alert-modal__body">
                <div class="bookings-alert-modal__notice bookings-alert-modal__notice--warning">
                    <i class="fas fa-info-circle"></i>
                    <p>These guests have passed their scheduled checkout date. Process their checkout to free the room, or extend their stay to bill extra nights accurately.</p>
                </div>
                <div class="bookings-alert-modal__table-wrap">
                    <table class="booking-table bookings-alert-modal__table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Checkout Due</th>
                                <th>Overdue</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($overdue_checkouts)): foreach ($overdue_checkouts as $bk): ?>
                                    <tr>
                                        <td data-label="Reference"><strong><?php echo htmlspecialchars($bk['booking_reference']); ?></strong></td>
                                        <td data-label="Guest"><?php echo htmlspecialchars($bk['guest_name']); ?></td>
                                        <td data-label="Room"><?php echo htmlspecialchars($bk['room_name']); ?><br><small><?php echo $bk['individual_room_name'] ? htmlspecialchars($bk['individual_room_name'] . ' (' . $bk['individual_room_number'] . ')') : 'Unassigned'; ?></small></td>
                                        <td data-label="Checkout Due" class="bookings-alert-modal__warning-cell"><?php echo date('M j, Y', strtotime($bk['check_out_date'])); ?></td>
                                        <td data-label="Overdue"><span class="badge bookings-alert-modal__badge bookings-alert-modal__badge--warning"><?php echo $bk['days_overdue'] == 0 ? 'Today' : $bk['days_overdue'] . ' day(s)'; ?></span></td>
                                        <td data-label="Actions" class="bookings-alert-modal__actions">
                                            <button class="btn btn-sm btn-danger bookings-alert-modal__action-btn" onclick="checkoutBooking(<?php echo $bk['id']; ?>, '<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>')"><i class="fas fa-sign-out-alt"></i> Checkout Now</button>
                                            <button class="btn btn-sm btn-success bookings-alert-modal__action-btn" onclick="closeOverdueCheckoutsModal(); openExtendStayModal(<?php echo $bk['id']; ?>, '<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>', '<?php echo $bk['check_out_date']; ?>', '<?php echo htmlspecialchars($bk['guest_name'], ENT_QUOTES); ?>')"><i class="fas fa-calendar-plus"></i> Extend Stay</button>
                                            <?php if ($_is_admin_user): ?>
                                                <button class="btn btn-sm bookings-alert-modal__action-btn bookings-alert-modal__action-btn--admin-date" onclick="closeOverdueCheckoutsModal(); openAdminChangeDateModal(<?php echo $bk['id']; ?>, '<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>', '<?php echo $bk['check_out_date']; ?>', '<?php echo htmlspecialchars($bk['guest_name'], ENT_QUOTES); ?>')"><i class="fas fa-calendar-pen"></i> Change Date</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr class="bookings-alert-modal__empty-row">
                                    <td colspan="6"><i class="fas fa-circle-check"></i> No overdue checkouts - all guests are within their scheduled dates.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeOverdueCheckoutsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Booking Stats Insight Modal -->
    <div id="bookingsStatsInsightModal" class="modal-overlay bookings-alert-modal bookings-stats-insight-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="modal-content bookings-alert-modal__dialog bookings-alert-modal__dialog--wide">
            <div class="modal-header bookings-alert-modal__header">
                <div class="bookings-alert-modal__title-wrap">
                    <p class="bookings-alert-modal__eyebrow" id="bookingsStatsInsightEyebrow">Dashboard Insight</p>
                    <h3 id="bookingsStatsInsightTitle"><i class="fas fa-chart-pie"></i> Booking Insight</h3>
                    <p class="bookings-alert-modal__subtitle" id="bookingsStatsInsightSubtitle">Latest records for this card.</p>
                </div>
                <button class="close-modal bookings-alert-modal__close" type="button" onclick="closeBookingsStatsInsightModal()" aria-label="Close bookings stats insight modal"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body bookings-alert-modal__body">
                <div class="bookings-stats-insight-modal__summary" id="bookingsStatsInsightSummary">
                    <div class="bookings-stats-insight-modal__kpi">0</div>
                    <p>Select a card above to view its operational insight.</p>
                </div>
                <div class="bookings-alert-modal__table-wrap" id="bookingsStatsInsightTableWrap">
                    <table class="booking-table bookings-alert-modal__table bookings-stats-insight-modal__table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Stay</th>
                                <th>Total</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="bookingsStatsInsightRows">
                            <tr class="bookings-alert-modal__empty-row">
                                <td colspan="6">Insight details load when you open a card.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bookings-stats-insight-modal__footer">
                <a id="bookingsStatsInsightLink" class="btn btn-primary" href="bookings.php">Open full list</a>
                <button type="button" class="btn btn-secondary" onclick="closeBookingsStatsInsightModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Manual Consolidation Modal -->
    <div id="consolidationModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 680px;">
            <div class="modal-header">
                <h3><i class="fas fa-scale-balanced"></i> Manual Payment Consolidation</h3>
                <button class="close-modal" onclick="closeConsolidationModal()">&times;</button>
            </div>
            <div class="modal-body" id="con_body" style="padding:0;">
                <div style="padding:20px; text-align:center; color:#888;"><i class="fas fa-spinner fa-spin"></i> Loading financial summary...</div>
            </div>
        </div>
    </div>

    <!-- Extend Stay Modal -->
    <div id="extendStayModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header" style="background: var(--au-success, #10b981); color: #fff;">
                <h3><i class="fas fa-calendar-plus"></i> Extend Stay</h3>
                <button class="close-modal" onclick="closeExtendStayModal()" style="color: #fff;">&times;</button>
            </div>
            <form id="extendStayForm" method="POST" action="bookings.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="extend_stay">
                <input type="hidden" name="booking_id" id="extend_booking_id" value="">

                <div class="modal-body">
                    <div class="form-group">
                        <label>Booking Reference:</label>
                        <input type="text" id="extend_booking_ref" class="form-control" readonly style="background: var(--au-bg, #f4f6fa);">
                    </div>
                    <div class="form-group">
                        <label>Guest Name:</label>
                        <input type="text" id="extend_guest_name" class="form-control" readonly style="background: var(--au-bg, #f4f6fa);">
                    </div>
                    <div class="form-group">
                        <label>Current Check-out Date:</label>
                        <input type="text" id="extend_current_checkout" class="form-control" readonly style="background: var(--au-bg, #f4f6fa);">
                    </div>
                    <div class="form-group">
                        <label for="new_checkout" style="font-weight: 600; color: var(--au-success, #10b981);">New Check-out Date:</label>
                        <input type="date" id="new_checkout" name="new_checkout" class="form-control" required style="border-color: var(--au-success, #10b981);">
                        <small style="color: var(--au-muted, #6b7280); margin-top: 4px; display: block;">The booking total will be recalculated based on the new dates.</small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeExtendStayModal()">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Save Extension</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Admin Change Checkout Date Modal -->
    <div id="adminChangeDateModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header" style="background: var(--au-purple, #8b5cf6); color: #fff;">
                <h3><i class="fas fa-calendar-pen"></i> Admin: Change Checkout Date</h3>
                <button class="close-modal" onclick="closeAdminChangeDateModal()" style="color: #fff;">&times;</button>
            </div>
            <form id="adminChangeDateForm" method="POST" action="bookings.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="admin_update_checkout_date">
                <input type="hidden" name="booking_id" id="acd_booking_id" value="">
                <div class="modal-body">
                    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; color: #78350f;">
                        <i class="fas fa-triangle-exclamation" style="color: var(--au-warning, #f59e0b);"></i>
                        <strong>Admin override.</strong> This bypasses normal checkout restrictions and recalculates the booking total. Use only to correct the actual checkout date on record.
                    </div>
                    <div class="form-group">
                        <label>Booking:</label>
                        <input type="text" id="acd_booking_ref" class="form-control" readonly style="background: var(--au-bg, #f4f6fa);">
                    </div>
                    <div class="form-group">
                        <label>Guest:</label>
                        <input type="text" id="acd_guest_name" class="form-control" readonly style="background: var(--au-bg, #f4f6fa);">
                    </div>
                    <div class="form-group">
                        <label>Current checkout date:</label>
                        <input type="text" id="acd_current_checkout" class="form-control" readonly style="background: var(--au-bg, #f4f6fa);">
                    </div>
                    <div class="form-group">
                        <label for="acd_new_checkout" style="font-weight:600; color: var(--au-purple, #8b5cf6);">New checkout date: <span style="color: var(--au-danger, #ef4444);">*</span></label>
                        <input type="date" id="acd_new_checkout" name="new_checkout" class="form-control" required style="border-color: var(--au-purple, #8b5cf6);">
                        <small style="color: var(--au-muted, #6b7280); display:block; margin-top:4px;">Can be in the past (e.g. to correct an overdue record). Total &amp; amount due will be recalculated.</small>
                    </div>
                    <div class="form-group">
                        <label for="acd_reason">Reason (required):</label>
                        <input type="text" id="acd_reason" name="reason" class="form-control" placeholder="e.g. Guest departed early, missed checkout in system" maxlength="200" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAdminChangeDateModal()">Cancel</button>
                    <button type="submit" class="btn btn-sm" style="background: var(--au-purple, #8b5cf6); border-color: var(--au-purple, #8b5cf6); color: #fff;"><i class="fas fa-check"></i> Update Checkout Date</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Checkout Settlement Modal -->
    <div id="checkoutSettlementModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header">
                <h3 id="cs_title"><i class="fas fa-receipt"></i> Checkout Settlement</h3>
                <button class="close-modal" onclick="closeCheckoutSettlementModal()">&times;</button>
            </div>
            <form id="checkoutSettlementForm" method="POST" action="bookings.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="checkout_settle">
                <input type="hidden" id="cs_booking_id" name="id" value="">
                <input type="hidden" id="cs_booking_ref" value="">
                <input type="hidden" id="cs_settlement_type" value="">
                <input type="hidden" id="cs_settlement_action" name="settlement_action" value="skip">

                <div class="modal-body">
                    <!-- Dynamic summary injected by JS -->
                    <div id="cs_body" style="margin-bottom: 16px; font-size: 14px; color: #333;"></div>

                    <!-- Payment method for extra charge -->
                    <div id="cs_charge_row" style="display:none;">
                        <div class="form-group">
                            <label style="font-weight:600;">Payment method for extra charge <span style="color:#dc3545;">*</span></label>
                            <select id="cs_payment_method" name="cs_payment_method" class="form-control">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="debit_card">Debit Card</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <!-- Payment method for refund -->
                    <div id="cs_refund_row" style="display:none;">
                        <div class="form-group">
                            <label style="font-weight:600;">Refund method <span style="color:#28a745;">*</span></label>
                            <select id="cs_refund_payment_method" name="cs_refund_payment_method" class="form-control">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="debit_card">Debit Card</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Notes (optional)</label>
                        <input type="text" id="cs_notes" name="cs_notes" class="form-control" placeholder="e.g. guest agreed, cash collected" maxlength="200">
                    </div>

                    <div class="form-group" style="background:#f8f8f8; border-radius:6px; padding:10px 12px; margin-top:8px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;color:#555;">
                            <input type="checkbox" id="cs_skip_toggle" name="cs_skip_toggle" style="width:16px;height:16px;">
                            Skip settlement — checkout without recording a charge or refund
                        </label>
                        <small style="color:#888;display:block;margin-top:4px;">Use only if the adjustment has been handled outside the system.</small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCheckoutSettlementModal()">Cancel</button>
                    <button type="button" id="cs_proceed_btn" class="btn btn-primary" style="background:#dc3545;border-color:#dc3545;" onclick="const f=document.getElementById('checkoutSettlementForm'); if(f){ f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})); } return false;">Proceed & Checkout</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .cs-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 13px;
        }

        .cs-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #eee;
        }

        .cs-table tr.cs-total td {
            font-weight: 700;
            background: #f7f3ee;
            border-top: 2px solid #c0a882;
        }

        .modal-content {
            position: relative;
        }

        .modal-inline-loader {
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.76);
            backdrop-filter: blur(1.5px);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.58rem;
            border-radius: inherit;
            z-index: 40;
            color: #1f2937;
            font-size: 0.86rem;
            font-weight: 600;
        }

        .modal-inline-loader__spinner {
            width: 1rem;
            height: 1rem;
            border: 2px solid rgba(31, 41, 55, 0.18);
            border-top-color: #1f2937;
            border-radius: 999px;
            animation: modal-inline-spin 0.72s linear infinite;
        }

        .modal-content.is-modal-busy {
            pointer-events: none;
        }

        @keyframes modal-inline-spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }
    </style>

    <!-- View Booking Details Modal -->
    <div id="viewBookingModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header bookings-view-modal__header">
                <div class="bookings-view-modal__title-block">
                    <h3><i class="fas fa-eye" aria-hidden="true"></i> Booking Details</h3>
                    <div class="bookings-view-modal__meta" aria-label="Booking quick summary">
                        <span class="bookings-view-modal__pill">
                            <span class="bookings-view-modal__pill-label">Ref</span>
                            <span id="view_booking_header_ref">—</span>
                        </span>
                        <span class="bookings-view-modal__pill">
                            <span class="bookings-view-modal__pill-label">Status</span>
                            <span id="view_booking_header_status">—</span>
                        </span>
                        <span class="bookings-view-modal__pill">
                            <span class="bookings-view-modal__pill-label">Payment</span>
                            <span id="view_booking_header_payment">—</span>
                        </span>
                    </div>
                </div>
                <button type="button" class="close-modal" onclick="closeViewBookingModal()" aria-label="Close booking details modal"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body">
                <div class="details-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">
                    <div class="detail-item">
                        <label>Booking Reference:</label>
                        <div id="view_booking_ref" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Guest Name:</label>
                        <div id="view_guest_name" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Email:</label>
                        <div id="view_guest_email" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Phone:</label>
                        <div id="view_guest_phone" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Room:</label>
                        <div id="view_room_name" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Individual Room:</label>
                        <div id="view_individual_room" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Check-in:</label>
                        <div id="view_check_in" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Check-out:</label>
                        <div id="view_check_out" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Nights:</label>
                        <div id="view_nights" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Guests:</label>
                        <div id="view_guests" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Total Amount:</label>
                        <div id="view_total" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Status:</label>
                        <div id="view_status" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Payment Status:</label>
                        <div id="view_payment" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Created At:</label>
                        <div id="view_created" class="detail-value"></div>
                    </div>
                </div>
                <div class="detail-item" style="grid-column: span 2;">
                    <label>Special Requests:</label>
                    <div id="view_special_requests" class="detail-value" style="background: #f5f5f5; padding: 8px; border-radius: 6px;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewBookingModal()">Close</button>
                <a id="view_full_details_link" href="#" class="btn btn-primary" target="_blank"><i class="fas fa-external-link-alt"></i> Full Details</a>
            </div>
        </div>
    </div>

    <!-- Upgrade Room Type Modal -->
    <div id="upgradeRoomModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-arrow-up"></i> Upgrade Room Type</h3>
                <button class="close-modal" onclick="closeUpgradeRoomModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                    <input type="text" id="upgrade_booking_ref" class="form-control" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Guest Name:</label>
                    <input type="text" id="upgrade_guest_name" class="form-control" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-bed"></i> Current Room Type:</label>
                    <input type="text" id="upgrade_current_room" class="form-control" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Dates:</label>
                    <input type="text" id="upgrade_dates" class="form-control" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-dollar-sign"></i> Current Total:</label>
                    <input type="text" id="upgrade_current_total" class="form-control" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label for="upgrade_new_room"><i class="fas fa-arrow-up"></i> Select New Room Type:</label>
                    <select id="upgrade_new_room" class="form-control" required>
                        <option value="">-- Select Room Type --</option>
                    </select>
                    <small style="color: #666;">Choose a higher-tier room type to upgrade the booking</small>
                </div>
                <div id="upgrade_price_preview" style="background: #e7f3ff; padding: 12px; border-radius: 6px; margin: 16px 0; display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-weight: bold; color: #666;">New Total:</span>
                        <span id="upgrade_new_total" style="color: #333; font-weight: bold;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: bold; color: #666;">Price Difference:</span>
                        <span id="upgrade_price_diff" style="color: #666;">-</span>
                    </div>
                </div>
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="upgrade_send_email" value="1" checked>
                        <span><i class="fas fa-envelope"></i> Send upgrade confirmation email to guest</span>
                    </label>
                </div>
                <div class="form-group" style="background: #fff8e1; padding: 12px; border-radius: 8px;">
                    <p style="margin: 0; color: #8B7355; font-size: 13px;">
                        <i class="fas fa-info-circle"></i>
                        Upgrading will recalculate the booking total based on the new room type price.
                        If the price increases, the guest will need to pay the difference upon check-in.
                    </p>
                </div>
                <input type="hidden" id="upgrade_booking_id">
                <input type="hidden" id="upgrade_current_room_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeUpgradeRoomModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitUpgradeRoom()"><i class="fas fa-arrow-up"></i> Upgrade Room</button>
            </div>
        </div>
    </div>

    <script>
        // Ensure setBookingPageModalOpen is available early (full definition also lives in
        // the third <script> block below; this copy guarantees it is defined before any
        // onclick handler in this block fires, even if the later block fails to parse).
        if (typeof setBookingPageModalOpen !== 'function') {
            window.setBookingPageModalOpen = function setBookingPageModalOpen(modal, isOpen) {
                if (!modal) return;
                modal.style.transition = 'none';
                modal.style.display = isOpen ? 'flex' : 'none';
                modal.style.opacity = isOpen ? '1' : '0';
                modal.style.visibility = isOpen ? 'visible' : 'hidden';
                modal.classList.toggle('active', isOpen);
                modal.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
                if (isOpen) {
                    document.body.classList.add('modal-open');
                } else if (!document.querySelector('.modal-overlay.active, .modal.active')) {
                    document.body.classList.remove('modal-open');
                }
            };
        }

        let viewBookingRequestController = null;
        let viewBookingActiveTrigger = null;

        function setViewBookingTriggerLoading(button, isLoading) {
            if (!(button instanceof HTMLElement)) {
                return;
            }

            if (isLoading) {
                if (!button.dataset.originalContent) {
                    button.dataset.originalContent = button.innerHTML;
                }
                if (!button.dataset.originalAriaLabel) {
                    button.dataset.originalAriaLabel = button.getAttribute('aria-label') || '';
                }

                button.disabled = true;
                button.classList.add('btn-loading');
                button.setAttribute('aria-busy', 'true');
                button.setAttribute('aria-label', 'Loading booking details');
                button.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i>';
                return;
            }

            button.disabled = false;
            button.classList.remove('btn-loading');
            button.removeAttribute('aria-busy');
            if (button.dataset.originalAriaLabel) {
                button.setAttribute('aria-label', button.dataset.originalAriaLabel);
            }
            if (button.dataset.originalContent) {
                button.innerHTML = button.dataset.originalContent;
            }
            delete button.dataset.originalAriaLabel;
            delete button.dataset.originalContent;
        }

        function buildViewBookingFallback(triggerButton, bookingId, bookingReference) {
            const dataset = (triggerButton instanceof HTMLElement && triggerButton.dataset) ? triggerButton.dataset : {};

            const fallbackRef = String(bookingReference || dataset.bookingRef || bookingId || '').trim();
            const fallbackRoomName = String(dataset.roomName || '').trim();
            const fallbackIndividualRoomName = String(dataset.individualRoomName || '').trim();
            const fallbackIndividualRoomNumber = String(dataset.individualRoomNumber || '').trim();
            const fallbackIndividualRoom = fallbackIndividualRoomName ?
                (fallbackIndividualRoomNumber ? (fallbackIndividualRoomName + ' (#' + fallbackIndividualRoomNumber + ')') : fallbackIndividualRoomName) :
                (fallbackIndividualRoomNumber || 'Not assigned');

            return {
                booking_reference: fallbackRef,
                guest_name: String(dataset.guestName || '').trim(),
                guest_email: String(dataset.guestEmail || '').trim(),
                guest_phone: String(dataset.guestPhone || '').trim(),
                room_name: fallbackRoomName,
                individual_room_name: fallbackIndividualRoomName,
                individual_room_number: fallbackIndividualRoomNumber,
                check_in_date_formatted: String(dataset.checkInDate || '').trim(),
                check_out_date_formatted: String(dataset.checkOutDate || '').trim(),
                number_of_nights: String(dataset.numberOfNights || '').trim(),
                number_of_guests: String(dataset.numberOfGuests || '').trim(),
                total_formatted: String(dataset.totalDisplay || '').trim(),
                status_label: String(dataset.statusLabel || '').trim(),
                payment_status_label: String(dataset.paymentStatusLabel || '').trim(),
                created_at_formatted: String(dataset.createdAtLabel || '').trim(),
                special_requests: String(dataset.specialRequests || '').trim(),
                __fallbackIndividualRoomDisplay: fallbackIndividualRoom
            };
        }

        function populateViewBookingModalFields(booking) {
            const setText = function(id, value, emptyValue = '—') {
                const element = document.getElementById(id);
                if (!element) return;
                const normalized = String(value ?? '').trim();
                element.textContent = normalized !== '' ? normalized : emptyValue;
            };

            const individualRoom = String(booking.__fallbackIndividualRoomDisplay || '').trim() ||
                (booking.individual_room_name ?
                    (booking.individual_room_number ? (booking.individual_room_name + ' (#' + booking.individual_room_number + ')') : booking.individual_room_name) :
                    (booking.individual_room_number || 'Not assigned'));

            setText('view_booking_ref', booking.booking_reference, '—');
            setText('view_guest_name', booking.guest_name, '—');
            setText('view_guest_email', booking.guest_email, '—');
            setText('view_guest_phone', booking.guest_phone, '—');
            setText('view_room_name', booking.room_name, '—');
            setText('view_individual_room', individualRoom, 'Not assigned');
            setText('view_check_in', booking.check_in_date_formatted || booking.check_in_date, '—');
            setText('view_check_out', booking.check_out_date_formatted || booking.check_out_date, '—');
            setText('view_nights', booking.number_of_nights, '—');
            setText('view_guests', booking.number_of_guests, '—');
            setText('view_total', booking.total_formatted || booking.total_amount, '—');
            setText('view_status', booking.status_label || booking.status, '—');
            setText('view_payment', booking.payment_status_label || booking.payment_status, '—');
            setText('view_created', booking.created_at_formatted || booking.created_at, '—');
            setText('view_special_requests', booking.special_requests, 'None');

            setText('view_booking_header_ref', booking.booking_reference, '—');
            setText('view_booking_header_status', booking.status_label || booking.status, '—');
            setText('view_booking_header_payment', booking.payment_status_label || booking.payment_status, '—');
        }

        function openViewBookingModal(bookingId, bookingReference, triggerButton = null, clickEvent = null) {
            if (clickEvent) {
                clickEvent.preventDefault();
                clickEvent.stopPropagation();
            }

            let normalizedBookingId = Number.parseInt(String(bookingId || ''), 10);
            if (!Number.isFinite(normalizedBookingId) || normalizedBookingId <= 0) {
                const triggerBookingId = triggerButton instanceof HTMLElement ? Number.parseInt(String(triggerButton.dataset.bookingId || ''), 10) : NaN;
                if (Number.isFinite(triggerBookingId) && triggerBookingId > 0) {
                    normalizedBookingId = triggerBookingId;
                }
            }

            if (!Number.isFinite(normalizedBookingId) || normalizedBookingId <= 0) {
                showAlert('Unable to open booking details because the booking ID is invalid.', 'error');
                return;
            }

            if (viewBookingRequestController && typeof viewBookingRequestController.abort === 'function') {
                viewBookingRequestController.abort();
            }

            if (viewBookingActiveTrigger && viewBookingActiveTrigger !== triggerButton) {
                setViewBookingTriggerLoading(viewBookingActiveTrigger, false);
            }

            viewBookingActiveTrigger = triggerButton instanceof HTMLElement ? triggerButton : null;
            if (viewBookingActiveTrigger) {
                setViewBookingTriggerLoading(viewBookingActiveTrigger, true);
            }

            const modal = document.getElementById('viewBookingModal');
            setBookingPageModalOpen(modal, true);
            // Set full details link
            const fullDetailsLink = document.getElementById('view_full_details_link');
            fullDetailsLink.href = `booking-details.php?id=${normalizedBookingId}`;

            // Immediately hydrate with row-level fallback values so the modal is
            // still useful even if the network request is slow or fails.
            const fallbackBooking = buildViewBookingFallback(viewBookingActiveTrigger, normalizedBookingId, bookingReference);
            populateViewBookingModalFields(fallbackBooking);

            // Fetch booking details via AJAX
            const formData = new FormData();
            formData.append('action', 'get_booking_details');
            formData.append('booking_id', String(normalizedBookingId));
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = window._rhCsrf || (csrfMeta ? (csrfMeta.getAttribute('content') || '') : '');
            if (csrfToken && !formData.has('csrf_token')) {
                formData.append('csrf_token', csrfToken);
            }

            viewBookingRequestController = typeof AbortController !== 'undefined' ? new AbortController() : null;
            const requestSignal = viewBookingRequestController ? viewBookingRequestController.signal : undefined;

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    signal: requestSignal,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(async response => {
                    const payloadText = await response.text();
                    let data;
                    try {
                        data = JSON.parse(payloadText);
                    } catch (parseError) {
                        throw new Error('Received an invalid booking details response.');
                    }

                    if (!response.ok || !data.success || !data.data) {
                        throw new Error(data.message || 'Failed to load booking details.');
                    }

                    return data.data;
                })
                .then(booking => {
                    populateViewBookingModalFields(booking);
                })
                .catch((error) => {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                    const message = (error && error.message) ? error.message : 'Error loading booking details. Please try again.';
                    Alert.show(message, 'error');
                })
                .finally(() => {
                    if (viewBookingRequestController && viewBookingRequestController.signal === requestSignal) {
                        viewBookingRequestController = null;
                    }
                    if (viewBookingActiveTrigger) {
                        setViewBookingTriggerLoading(viewBookingActiveTrigger, false);
                        viewBookingActiveTrigger = null;
                    }
                });
        }

        function closeViewBookingModal() {
            const modal = document.getElementById('viewBookingModal');
            if (viewBookingRequestController && typeof viewBookingRequestController.abort === 'function') {
                viewBookingRequestController.abort();
                viewBookingRequestController = null;
            }
            if (viewBookingActiveTrigger) {
                setViewBookingTriggerLoading(viewBookingActiveTrigger, false);
                viewBookingActiveTrigger = null;
            }
            setBookingPageModalOpen(modal, false);
        }

        // Close modal when clicking outside
        document.getElementById('viewBookingModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeViewBookingModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Escape') {
                return;
            }
            const modal = document.getElementById('viewBookingModal');
            if (!modal || !modal.classList.contains('active')) {
                return;
            }
            closeViewBookingModal();
        });

        function openResendEmailModal(bookingId, bookingReference, bookingStatus, isLateOrOverdue = false) {
            const modal = document.getElementById('resendEmailModal');
            setBookingPageModalOpen(modal, true);
            document.getElementById('modal_booking_id').value = bookingId;
            document.getElementById('modal_booking_reference').value = bookingReference;

            // Reset success state
            document.getElementById('resendEmailSuccess').style.display = 'none';
            document.getElementById('resendEmailSubmitBtn').style.display = '';
            const prevErr = document.getElementById('resendEmailError');
            if (prevErr) prevErr.remove();

            // Show/hide reminder option — only for late check-in or overdue bookings
            const reminderOpt = document.getElementById('opt_booking_reminder');
            if (reminderOpt) {
                reminderOpt.style.display = isLateOrOverdue ? '' : 'none';
            }

            // Tentative email types only make sense for tentative bookings — hiding
            // them elsewhere stops a confirmed booking from offering a "Tentative"
            // email type in the dropdown.
            const isTentative = bookingStatus === 'tentative';
            ['opt_tentative_confirmed', 'opt_tentative_converted'].forEach(function (id) {
                const opt = document.getElementById(id);
                if (opt) opt.style.display = isTentative ? '' : 'none';
            });

            // Set default email type based on booking status
            const emailTypeSelect = document.getElementById('email_type');
            emailTypeSelect.value = '';

            switch (bookingStatus) {
                case 'pending':
                    emailTypeSelect.value = 'booking_received';
                    break;
                case 'tentative':
                    emailTypeSelect.value = 'tentative_confirmed';
                    break;
                case 'confirmed':
                    emailTypeSelect.value = isLateOrOverdue ? 'booking_reminder' : 'booking_confirmed';
                    break;
                case 'cancelled':
                    emailTypeSelect.value = 'booking_cancelled';
                    break;
                case 'checked-in':
                    if (isLateOrOverdue) emailTypeSelect.value = 'booking_reminder';
                    break;
            }
        }

        function closeResendEmailModal() {
            const modal = document.getElementById('resendEmailModal');
            setBookingPageModalOpen(modal, false);
            const form = document.getElementById('resendEmailForm');
            form.reset();
            // Restore form body if it was hidden by success state
            const body = form.querySelector('.modal-body');
            if (body) body.style.display = '';
            const successEl = document.getElementById('resendEmailSuccess');
            if (successEl) successEl.style.display = 'none';
            const submitBtn = document.getElementById('resendEmailSubmitBtn');
            if (submitBtn) { submitBtn.style.display = ''; submitBtn.disabled = false; }
            const errEl = document.getElementById('resendEmailError');
            if (errEl) errEl.remove();
        }

        // AJAX submission for resend email form
        document.addEventListener('DOMContentLoaded', function() {
            const resendForm = document.getElementById('resendEmailForm');
            if (!resendForm) return;
            resendForm.addEventListener('submit', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const submitBtn = resendForm.querySelector('[type="submit"]');
                const originalLabel = submitBtn ? submitBtn.innerHTML : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
                }

                // Remove any previous inline error
                const prevErr = document.getElementById('resendEmailError');
                if (prevErr) prevErr.remove();

                const formData = new FormData(resendForm);
                fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(res => {
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        return res.json();
                    })
                    .then(data => {
                        if (data.success) {
                            // Show inline success (visible on mobile without needing to scroll)
                            const successEl = document.getElementById('resendEmailSuccess');
                            const successMsg = document.getElementById('resendEmailSuccessMsg');
                            if (successEl) {
                                if (successMsg) successMsg.textContent = data.message || 'Email sent successfully!';
                                resendForm.querySelector('.modal-body').style.display = 'none';
                                successEl.style.display = 'block';
                                if (submitBtn) submitBtn.style.display = 'none';
                            }
                            // Auto-close after 1.6s then show toast
                            setTimeout(() => {
                                closeResendEmailModal();
                                showBookingActionMessage(data.message || 'Email sent successfully.', 'success');
                            }, 1600);
                        } else {
                            const errDiv = document.createElement('div');
                            errDiv.id = 'resendEmailError';
                            errDiv.style.cssText = 'color:#dc3545;font-size:13px;margin:8px 0 0;padding:8px 12px;background:#fff5f5;border:1px solid #f5c6cb;border-radius:6px;';
                            errDiv.textContent = data.message || 'Failed to send email.';
                            resendForm.querySelector('.modal-footer').before(errDiv);
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = originalLabel;
                            }
                        }
                    })
                    .catch(() => {
                        const errDiv = document.createElement('div');
                        errDiv.id = 'resendEmailError';
                        errDiv.style.cssText = 'color:#dc3545;font-size:13px;margin:8px 0 0;padding:8px 12px;background:#fff5f5;border:1px solid #f5c6cb;border-radius:6px;';
                        errDiv.textContent = 'Network error — please try again.';
                        resendForm.querySelector('.modal-footer').before(errDiv);
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalLabel;
                        }
                    });
            });
        });

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('resendEmailModal');
            if (event.target === modal) {
                closeResendEmailModal();
            }
            const modal2 = document.getElementById('makeTentativeModal');
            if (event.target === modal2) {
                closeMakeTentativeModal();
            }
            const modal3 = document.getElementById('checkInModal');
            if (event.target === modal3) {
                closeCheckInModal();
            }
            const modal4 = document.getElementById('cancelBookingModal');
            if (event.target === modal4) {
                closeCancelBookingModal();
            }
            const modal5 = document.getElementById('missedCheckinsModal');
            if (event.target === modal5) {
                closeMissedCheckinsModal();
            }
            const modal6 = document.getElementById('overdueCheckoutsModal');
            if (event.target === modal6) {
                closeOverdueCheckoutsModal();
            }
            const modal7 = document.getElementById('bookingsStatsInsightModal');
            if (event.target === modal7) {
                closeBookingsStatsInsightModal();
            }
        });

        // Quick Room Assignment Modal Functions
        let selectedRoomId = null;

        function openQuickRoomAssignModal(bookingId, bookingReference, checkIn, checkOut, roomId) {
            const modal = document.getElementById('quickRoomAssignModal');
            if (modal) {
                setBookingPageModalOpen(modal, true);
            }
            document.getElementById('quick_assign_booking_id').value = bookingId;
            document.getElementById('quick_assign_booking_ref').value = bookingReference;
            document.getElementById('quick_assign_room_id').value = roomId;

            const checkInDate = new Date(checkIn);
            const checkOutDate = new Date(checkOut);
            document.getElementById('quick_assign_dates').value =
                checkInDate.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                }) +
                ' - ' +
                checkOutDate.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });

            // Load available rooms
            loadAvailableRooms(roomId, checkIn, checkOut, bookingId);
        }

        function closeQuickRoomAssignModal() {
            const modal = document.getElementById('quickRoomAssignModal');
            if (modal) {
                setBookingPageModalOpen(modal, false);
            }
            document.getElementById('quick_assign_room_list').innerHTML = '';
            document.getElementById('quick_assign_override_panel').style.display = 'none';
            document.getElementById('quick_assign_children_override').checked = false;
            document.getElementById('quick_assign_override_note').value = '';
            selectedRoomId = null;
        }

        function loadAvailableRooms(roomId, checkIn, checkOut, bookingId) {
            const roomList = document.getElementById('quick_assign_room_list');
            roomList.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;"><i class="fas fa-spinner fa-spin"></i> Loading available rooms...</div>';

            const formData = new FormData();
            formData.append('action', 'get_available_rooms');
            formData.append('room_type_id', roomId);
            formData.append('check_in', checkIn);
            formData.append('check_out', checkOut);
            formData.append('exclude_booking_id', bookingId);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data && data.data.length > 0) {
                        roomList.innerHTML = '';
                        data.data.forEach(room => {
                            const roomCard = document.createElement('div');
                            const requiresChildOverride = room.requires_child_override === true;
                            roomCard.className = 'room-assign-card';
                            roomCard.dataset.available = room.available ? 'true' : 'false';
                            roomCard.dataset.requiresChildOverride = requiresChildOverride ? 'true' : 'false';
                            roomCard.style.cssText = `
                                display: flex;
                                justify-content: space-between;
                                align-items: center;
                                padding: 12px;
                                margin-bottom: 8px;
                                border: 2px solid ${requiresChildOverride ? '#f0ad4e' : (room.available ? '#28a745' : '#dc3545')};
                                border-radius: 8px;
                                cursor: ${room.available ? 'pointer' : 'not-allowed'};
                                background: ${requiresChildOverride ? '#fff8e1' : (room.available ? '#fff' : '#f8f8f8')};
                                transition: all 0.2s;
                            `;

                            const roomName = room.room_name ?
                                (room.room_number ? `${room.room_name} <small style="font-weight:400;opacity:0.8">#${room.room_number}</small>` : room.room_name) :
                                (room.room_type_name ? `${room.room_type_name} ${room.room_number}` : `Room ${room.room_number}`);

                            roomCard.innerHTML = `
                                <div>
                                    <div style="font-weight: 600; color: var(--navy);">
                                        <i class="fas fa-door-open" style="color: var(--gold);"></i>
                                        ${roomName}
                                    </div>
                                    ${room.floor ? `<small style="color: #666;"><i class="fas fa-layer-group"></i> Floor: ${room.floor}</small>` : ''}
                                    ${requiresChildOverride ? `<small style="display:block; color:#7a4f01; margin-top:4px;"><i class="fas fa-triangle-exclamation"></i> Child policy override note required</small>` : ''}
                                </div>
                                <div>
                                    ${requiresChildOverride
                                        ? `<span class="badge" style="background: #fff3cd; color: #7a4f01; padding: 4px 12px; border-radius: 12px; font-size: 11px;">Needs Note</span>`
                                        : (room.available
                                        ? `<span class="badge" style="background: #d4edda; color: #155724; padding: 4px 12px; border-radius: 12px; font-size: 11px;">Available</span>`
                                        : `<span class="badge" style="background: #f8d7da; color: #721c24; padding: 4px 12px; border-radius: 12px; font-size: 11px;">Unavailable</span>`)
                                    }
                                </div>
                            `;

                            if (room.available) {
                                roomCard.onclick = () => selectRoomForAssignment(room.id, roomCard);
                            }

                            roomList.appendChild(roomCard);
                        });
                    } else {
                        roomList.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> No available rooms found for these dates.</div>';
                    }
                })
                .catch(() => {
                    roomList.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fas fa-exclamation-circle"></i> Error loading available rooms.</div>';
                });
        }

        function selectRoomForAssignment(roomId, cardElement) {
            selectedRoomId = roomId;

            // Remove previous selection
            document.querySelectorAll('.room-assign-card').forEach(card => {
                delete card.dataset.selected;
                const needsOverride = card.dataset.requiresChildOverride === 'true';
                card.style.background = needsOverride ? '#fff8e1' : '#fff';
                card.style.borderColor = needsOverride ? '#f0ad4e' : (card.dataset.available === 'true' ? '#28a745' : '#dc3545');
            });

            // Highlight selected card
            cardElement.dataset.selected = 'true';
            cardElement.style.background = '#fff8e1';
            cardElement.style.borderColor = 'var(--gold)';

            const requiresOverride = cardElement.dataset.requiresChildOverride === 'true';
            document.getElementById('quick_assign_override_panel').style.display = requiresOverride ? 'block' : 'none';
            if (!requiresOverride) {
                document.getElementById('quick_assign_children_override').checked = false;
                document.getElementById('quick_assign_override_note').value = '';
            }
        }

        async function submitQuickRoomAssign() {
            if (!selectedRoomId) {
                Alert.show('Please select a room to assign.', 'error');
                return;
            }

            const bookingId = document.getElementById('quick_assign_booking_id').value;
            const bookingRef = document.getElementById('quick_assign_booking_ref').value;
            const dates = document.getElementById('quick_assign_dates').value;
            const selectedCard = document.querySelector('.room-assign-card[data-selected="true"]');
            const roomName = selectedCard ? selectedCard.textContent.replace(/\s+/g, ' ').trim() : 'Selected room';
            const requiresOverride = selectedCard && selectedCard.dataset.requiresChildOverride === 'true';
            const overrideChecked = document.getElementById('quick_assign_children_override').checked;
            const overrideNote = document.getElementById('quick_assign_override_note').value.trim();

            if (requiresOverride && (!overrideChecked || overrideNote === '')) {
                Alert.show('A child policy override note is required for this room.', 'error');
                return;
            }

            const confirmed = await confirmAdminAction({
                title: 'Confirm room assignment',
                message: 'Assign this room to booking ' + (bookingRef || bookingId) + '?',
                details: ['Room: ' + roomName, 'Stay dates: ' + dates],
                confirmText: 'Assign Room',
                icon: 'fa-door-open',
                tone: 'success'
            });
            if (!confirmed) return;

            const submitBtn = document.querySelector('#quickRoomAssignModal button[onclick="submitQuickRoomAssign()"]');
            if (submitBtn) setButtonLoading(submitBtn, true);
            showLoadingOverlay('Assigning room...');

            const formData = new FormData();
            formData.append('action', 'assign_individual_room');
            formData.append('booking_id', bookingId);
            formData.append('individual_room_id', selectedRoomId);
            if (requiresOverride) {
                formData.append('allow_child_policy_override', '1');
                formData.append('child_policy_override_note', overrideNote);
            }

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const successMessage = data.message || 'Room assigned successfully!';
                        showBookingActionMessage(successMessage, 'success');
                        queueBookingActionMessage(successMessage, 'success');
                        closeQuickRoomAssignModal();
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        hideLoadingOverlay();
                        if (submitBtn) setButtonLoading(submitBtn, false);
                        Alert.show(data.message || 'Failed to assign room.', 'error');
                    }
                })
                .catch(() => {
                    hideLoadingOverlay();
                    if (submitBtn) setButtonLoading(submitBtn, false);
                    Alert.show('Error assigning room.', 'error');
                });
        }

        // Make Tentative Modal Functions
        function openMakeTentativeModal(bookingId, bookingReference, actionType) {
            const modal = document.getElementById('makeTentativeModal');
            setBookingPageModalOpen(modal, true);
            document.getElementById('make_tentative_booking_id').value = bookingId;
            document.getElementById('make_tentative_ref').value = bookingReference;
            document.getElementById('make_tentative_action').value = actionType;
            // Set modal title based on action
            const title = document.querySelector('#makeTentativeModal h3');
            if (actionType === 'make_tentative') {
                title.innerHTML = '<i class="fas fa-clock"></i> Make Tentative';
                document.querySelector('#makeTentativeForm button[type="submit"]').innerHTML = '<i class="fas fa-check"></i> Make Tentative';
            } else if (actionType === 'convert_to_tentative') {
                title.innerHTML = '<i class="fas fa-clock"></i> Convert to Tentative';
                document.querySelector('#makeTentativeForm button[type="submit"]').innerHTML = '<i class="fas fa-check"></i> Convert to Tentative';
            }
            // Clear note
            document.getElementById('tentative_note').value = '';
        }

        function closeMakeTentativeModal() {
            const modal = document.getElementById('makeTentativeModal');
            setBookingPageModalOpen(modal, false);
            document.getElementById('makeTentativeForm').reset();
        }

        // Check In Modal Functions
        function openCheckInModal(bookingId, bookingReference, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus, mode = 'checkin') {
            const safeMode = mode === 'noshow' ? 'noshow' : 'checkin';
            const modal = document.getElementById('checkInModal');
            const titleEl = document.getElementById('checkin_modal_title');
            const subtitleEl = document.getElementById('checkin_modal_subtitle');
            const modeBadgeEl = document.getElementById('checkin_mode_badge');
            const modeHintEl = document.getElementById('checkin_mode_hint');
            const contextEl = document.getElementById('checkin_context_note');
            const prerequisitesEl = document.getElementById('checkin_prerequisites');
            const noteGroupEl = document.getElementById('checkin_note_group');
            const errorEl = document.getElementById('checkin_error_message');
            const actionInput = document.getElementById('checkin_action');
            const statusInput = document.getElementById('checkin_status');
            const modeInput = document.getElementById('checkin_modal_mode');
            const submitBtn = document.getElementById('checkin_submit_btn');

            setBookingPageModalOpen(modal, true);

            document.getElementById('checkin_booking_id').value = bookingId;
            document.getElementById('checkin_booking_ref').value = bookingReference;
            document.getElementById('checkin_guest_name').value = guestName;
            document.getElementById('checkin_date').value = checkInDate;

            const paymentOk = paymentStatus === 'paid' || paymentStatus === 'completed' || paymentStatus === 'partial';
            const roomOk = roomAssigned === true || roomAssigned === '1' || roomAssigned === 'true';
            const statusOk = bookingStatus === 'confirmed';

            const checkInDateObj = new Date(String(checkInDate) + 'T00:00:00');
            const todayObj = new Date();
            todayObj.setHours(0, 0, 0, 0);
            const dateReached = !Number.isNaN(checkInDateObj.getTime()) && checkInDateObj <= todayObj;
            const isLateCheckIn = !Number.isNaN(checkInDateObj.getTime()) && checkInDateObj < todayObj;
            const overdueDays = isLateCheckIn ? Math.floor((todayObj - checkInDateObj) / 86400000) : 0;

            document.getElementById('prereq_payment').innerHTML = '<i class="fas ' + (paymentOk ? 'fa-check-circle' : 'fa-times-circle') + '"></i> ' +
                (paymentOk ? ('Payment: ' + paymentStatus) : 'Payment required (at least partial)');
            document.getElementById('prereq_room').innerHTML = '<i class="fas ' + (roomOk ? 'fa-check-circle' : 'fa-exclamation-circle') + '" style="color:' + (roomOk ? '' : '#f0a500') + '"></i> ' +
                (roomOk ? 'Room assigned' : 'No specific room assigned (auto-assign)');
            document.getElementById('prereq_status').innerHTML = '<i class="fas ' + (statusOk ? 'fa-check-circle' : 'fa-times-circle') + '"></i> ' +
                (statusOk ? 'Booking is CONFIRMED' : 'Booking must be CONFIRMED');

            modeInput.value = safeMode;
            contextEl.style.display = 'block';
            errorEl.style.display = 'none';
            noteGroupEl.style.display = 'block';

            if (safeMode === 'noshow') {
                actionInput.value = 'noshow';
                statusInput.value = '';
                titleEl.innerHTML = '<i class="fas fa-user-slash"></i> Mark Booking as No-Show';
                subtitleEl.textContent = 'Use this when a confirmed guest did not arrive after check-in date.';
                modeBadgeEl.textContent = 'No-Show Workflow';
                modeBadgeEl.classList.add('is-danger');
                modeHintEl.textContent = 'This restores room availability and logs a no-show audit event.';
                contextEl.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ' +
                    (isLateCheckIn ? ('Check-in is overdue by ' + overdueDays + ' day(s).') : 'No-show requires the check-in date to have passed.');
                prerequisitesEl.style.display = 'none';

                const canNoShow = statusOk && isLateCheckIn;
                submitBtn.disabled = !canNoShow;
                submitBtn.classList.remove('btn-primary');
                submitBtn.classList.add('btn-danger');
                submitBtn.innerHTML = '<i class="fas fa-user-slash"></i> Mark No-Show';

                if (!canNoShow) {
                    const issues = [];
                    if (!statusOk) issues.push('booking is not confirmed');
                    if (!isLateCheckIn) issues.push('check-in date has not passed');
                    errorEl.textContent = 'Cannot mark no-show because ' + issues.join(' and ') + '.';
                    errorEl.style.display = 'block';
                }
                return;
            }

            actionInput.value = 'update_status';
            statusInput.value = 'checked-in';
            titleEl.innerHTML = '<i class="fas fa-sign-in-alt"></i> ' + (isLateCheckIn ? 'Late Check-In' : 'Check In Guest');
            subtitleEl.textContent = isLateCheckIn ?
                'Guest is arriving after the scheduled check-in date.' :
                'Verify prerequisites and complete guest arrival.';
            modeBadgeEl.textContent = isLateCheckIn ? 'Late Check-In' : 'Standard Check-In';
            modeBadgeEl.classList.remove('is-danger');
            modeHintEl.textContent = 'Check-in updates booking and occupancy immediately.';
            contextEl.innerHTML = isLateCheckIn ?
                ('<i class="fas fa-clock"></i> Check-in is overdue by ' + overdueDays + ' day(s).') :
                '<i class="fas fa-calendar-check"></i> On-time check-in workflow.';
            prerequisitesEl.style.display = 'block';

            const canCheckIn = paymentOk && statusOk && dateReached; // roomOk is advisory only
            submitBtn.disabled = !canCheckIn;
            submitBtn.classList.remove('btn-danger');
            submitBtn.classList.add('btn-primary');
            submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> ' + (isLateCheckIn ? 'Confirm Late Check-In' : 'Check In');

            if (!canCheckIn) {
                const issues = [];
                if (!paymentOk) issues.push('no payment recorded');
                if (!statusOk) issues.push('booking not confirmed');
                if (!dateReached) issues.push('check-in date not reached');
                errorEl.textContent = 'Cannot check in because ' + issues.join(', ') + '.';
                errorEl.style.display = 'block';
            } else if (!roomOk) {
                errorEl.textContent = 'Note: No specific room assigned — booking will check in without an individual room number.';
                errorEl.style.display = 'block';
                errorEl.style.color = '#856404';
            }
        }

        function closeCheckInModal() {
            const modal = document.getElementById('checkInModal');
            setBookingPageModalOpen(modal, false);
            document.getElementById('checkInForm').reset();
        }

        // Cancel Booking Modal Functions
        function openCancelBookingModal(bookingId, bookingReference, guestName) {
            const modal = document.getElementById('cancelBookingModal');
            setBookingPageModalOpen(modal, true);
            document.getElementById('cancel_booking_id').value = bookingId;
            document.getElementById('cancel_booking_ref').value = bookingReference;
            document.getElementById('cancel_guest_name').value = guestName;
            document.getElementById('cancellation_reason').value = '';
        }

        function closeCancelBookingModal() {
            const modal = document.getElementById('cancelBookingModal');
            setBookingPageModalOpen(modal, false);
            document.getElementById('cancelBookingForm').reset();
        }

        // Form submission for new modals
        document.getElementById('makeTentativeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            // Add note if provided
            const note = document.getElementById('tentative_note').value;
            if (note) {
                formData.append('note', note);
            }

            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) setButtonLoading(submitBtn, true);
            showLoadingOverlay('Making booking tentative...');

            postBookingAction(formData, 'Error making booking tentative')
                .then(data => reloadWithBookingActionMessage(data, 'Booking moved to tentative successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (submitBtn) setButtonLoading(submitBtn, false);
                    Alert.show(error.message || 'Error making booking tentative', 'error');
                });
        });

        document.getElementById('checkInForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const note = document.getElementById('checkin_note').value;
            const mode = document.getElementById('checkin_modal_mode').value;
            if (note) {
                formData.append('checkin_note', note);
            }

            const submitBtn = this.querySelector('button[type="submit"]');
            const form = this;
            if (submitBtn) setButtonLoading(submitBtn, true);
            setModalActionLoading(form, true, mode === 'noshow' ? 'Marking as No-Show...' : 'Checking in guest...');

            postBookingAction(formData, mode === 'noshow' ? 'Error marking booking as no-show' : 'Error checking in guest')
                .then(data => {
                    setModalActionLoading(form, false);
                    reloadWithBookingActionMessage(data, mode === 'noshow' ? 'Booking marked as no-show successfully.' : 'Guest checked in successfully.');
                })
                .catch(error => {
                    setModalActionLoading(form, false);
                    if (submitBtn) setButtonLoading(submitBtn, false);
                    Alert.show(error.message || (mode === 'noshow' ? 'Error marking booking as no-show' : 'Error checking in guest'), 'error');
                });
        });

        document.getElementById('cancelBookingForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) setButtonLoading(submitBtn, true);
            setModalActionLoading(this, true, 'Cancelling booking...');

            postBookingAction(formData, 'Error cancelling booking')
                .then(data => reloadWithBookingActionMessage(data, 'Booking cancelled successfully.'))
                .catch(error => {
                    setModalActionLoading(this, false);
                    if (submitBtn) setButtonLoading(submitBtn, false);
                    Alert.show(error.message || 'Error cancelling booking', 'error');
                });
        });

        // Admin Banner Modals Functions
        function openMissedCheckinsModal() {
            const modal = document.getElementById('missedCheckinsModal');
            setBookingPageModalOpen(modal, true);
        }

        function closeMissedCheckinsModal() {
            const modal = document.getElementById('missedCheckinsModal');
            setBookingPageModalOpen(modal, false);
        }

        function toggleMissedCheckins() {
            const modal = document.getElementById('missedCheckinsModal');
            setBookingPageModalOpen(modal, !modal.classList.contains('active'));
        }

        function openMissedCheckinWorkflow(bookingId, bookingReference, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus, mode = 'checkin') {
            closeMissedCheckinsModal();
            openCheckInModal(bookingId, bookingReference, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus, mode);
        }

        function openMissedCancelWorkflow(bookingId, bookingReference, guestName) {
            closeMissedCheckinsModal();
            openCancelBookingModal(bookingId, bookingReference, guestName);
        }

        function openOverdueCheckoutsModal() {
            const modal = document.getElementById('overdueCheckoutsModal');
            setBookingPageModalOpen(modal, true);
        }

        function closeOverdueCheckoutsModal() {
            const modal = document.getElementById('overdueCheckoutsModal');
            setBookingPageModalOpen(modal, false);
        }

        function toggleOverdueCheckouts() {
            const modal = document.getElementById('overdueCheckoutsModal');
            setBookingPageModalOpen(modal, !modal.classList.contains('active'));
        }

        document.addEventListener('click', function(event) {
            const trigger = event.target.closest('.js-bookings-stat-insight');
            if (!trigger) {
                return;
            }
            if (event.defaultPrevented) {
                return;
            }
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            openBookingsStatsInsightModal(String(trigger.dataset.statsCard || ''), trigger);
        });

        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            const keyboardTarget = event.target instanceof Element ? event.target.closest('.js-bookings-stat-insight') : null;
            if (!keyboardTarget) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            openBookingsStatsInsightModal(String(keyboardTarget.dataset.statsCard || ''), keyboardTarget);
        });

        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Escape') {
                return;
            }

            const statsModal = document.getElementById('bookingsStatsInsightModal');
            if (statsModal && statsModal.classList.contains('active')) {
                closeBookingsStatsInsightModal();
                return;
            }

            const missedModal = document.getElementById('missedCheckinsModal');
            if (missedModal && missedModal.classList.contains('active')) {
                closeMissedCheckinsModal();
                return;
            }

            const overdueModal = document.getElementById('overdueCheckoutsModal');
            if (overdueModal && overdueModal.classList.contains('active')) {
                closeOverdueCheckoutsModal();
            }
        });

        function openExtendStayModal(bookingId, bookingRef, currentCheckout, guestName) {
            const modal = document.getElementById('extendStayModal');
            setBookingPageModalOpen(modal, true);

            document.getElementById('extend_booking_id').value = bookingId;
            document.getElementById('extend_booking_ref').value = bookingRef;
            document.getElementById('extend_guest_name').value = guestName;
            document.getElementById('extend_current_checkout').value = currentCheckout;

            // Use UTC-safe arithmetic to avoid timezone off-by-one errors:
            // new Date('YYYY-MM-DD') is parsed as UTC midnight, so local date methods
            // (getDate/setDate) can shift the result in UTC-offset timezones.
            const parts = currentCheckout.split('-');
            const checkoutPlusOneUtc = new Date(Date.UTC(
                parseInt(parts[0], 10),
                parseInt(parts[1], 10) - 1,
                parseInt(parts[2], 10) + 1
            ));
            const checkoutPlusOne = checkoutPlusOneUtc.toISOString().split('T')[0];

            // For overdue bookings the checkout date is in the past, so the min must
            // be at least today to prevent extending to a date that is still overdue.
            // Use local date methods for "today" to avoid UTC midnight cross-over issues.
            const now = new Date();
            const todayLocal = now.getFullYear() + '-' +
                String(now.getMonth() + 1).padStart(2, '0') + '-' +
                String(now.getDate()).padStart(2, '0');

            const minDateStr = checkoutPlusOne < todayLocal ? todayLocal : checkoutPlusOne;

            // Default value: for an OVERDUE booking (checkout already passed) the
            // minimum floor is today, but defaulting to *today* is not really an
            // "extension" — a guest you're extending is staying at least one more
            // night. So default to tomorrow when overdue, while still allowing the
            // clerk to pick today via the picker. Non-overdue bookings default to
            // the day after the current checkout (their first real extra night).
            let defaultStr = minDateStr;
            if (minDateStr === todayLocal) {
                const tomorrowUtc = new Date(Date.UTC(
                    now.getFullYear(), now.getMonth(), now.getDate() + 1
                ));
                defaultStr = tomorrowUtc.toISOString().split('T')[0];
            }

            const newCheckoutInput = document.getElementById('new_checkout');
            newCheckoutInput.min = minDateStr;
            newCheckoutInput.value = defaultStr;
        }

        function closeExtendStayModal() {
            const modal = document.getElementById('extendStayModal');
            setBookingPageModalOpen(modal, false);
        }

        // ── Admin Change Checkout Date Modal ──────────────────────────────────
        function openAdminChangeDateModal(bookingId, bookingRef, currentCheckout, guestName) {
            const modal = document.getElementById('adminChangeDateModal');
            if (!modal) return;
            document.getElementById('acd_booking_id').value = bookingId;
            document.getElementById('acd_booking_ref').value = bookingRef;
            document.getElementById('acd_guest_name').value = guestName;
            document.getElementById('acd_current_checkout').value = currentCheckout;
            document.getElementById('acd_new_checkout').value = currentCheckout;
            document.getElementById('acd_new_checkout').max = '';
            document.getElementById('acd_reason').value = '';
            setBookingPageModalOpen(modal, true);
        }

        function closeAdminChangeDateModal() {
            const modal = document.getElementById('adminChangeDateModal');
            if (modal) setBookingPageModalOpen(modal, false);
        }

        // Explicit window exports: this page loads via the admin SPA, which
        // re-executes inline scripts inside an IIFE. Inline onclick attributes
        // (the overdue-checkout modal's Checkout Now / Extend Stay / Change Date
        // buttons) resolve against window, so bind these handlers there directly
        // rather than relying on the SPA's auto-export heuristic.
        window.openOverdueCheckoutsModal = openOverdueCheckoutsModal;
        window.closeOverdueCheckoutsModal = closeOverdueCheckoutsModal;
        window.openExtendStayModal = openExtendStayModal;
        window.closeExtendStayModal = closeExtendStayModal;
        window.openAdminChangeDateModal = openAdminChangeDateModal;
        window.closeAdminChangeDateModal = closeAdminChangeDateModal;

        document.getElementById('adminChangeDateForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const bookingRef = document.getElementById('acd_booking_ref')?.value || '';
            const currentCheckout = document.getElementById('acd_current_checkout')?.value || '';
            const newCheckout = document.getElementById('acd_new_checkout')?.value || '';
            const submitBtn = this.querySelector('button[type="submit"]');
            const form = this;
            confirmAdminAction({
                    title: 'Confirm checkout date change',
                    message: 'Update checkout date for booking ' + (bookingRef || 'this booking') + '?',
                    details: [
                        'Current: ' + (currentCheckout || '—'),
                        'New: ' + (newCheckout || '—')
                    ],
                    confirmText: 'Save Change',
                    icon: 'fa-calendar-pen',
                    tone: 'warning'
                })
                .then(confirmed => {
                    if (!confirmed) {
                        return;
                    }

                    if (submitBtn) setButtonLoading(submitBtn, true);
                    setModalActionLoading(form, true, 'Updating checkout date...');

                    return postBookingAction(formData, 'Failed to update checkout date.')
                        .then(data => {
                            closeAdminChangeDateModal();
                            reloadWithBookingActionMessage(data, 'Checkout date updated successfully.');
                        })
                        .catch(error => {
                            setModalActionLoading(form, false);
                            if (submitBtn) setButtonLoading(submitBtn, false);
                            Alert.show(error.message || 'An error occurred. Please try again.', 'error');
                        });
                });
        });

        document.getElementById('extendStayForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const bookingRef = document.getElementById('extend_booking_ref')?.value || '';
            const oldCheckout = document.getElementById('extend_current_checkout')?.value || '';
            const newCheckout = document.getElementById('new_checkout')?.value || '';
            const submitBtn = this.querySelector('button[type="submit"]');
            const form = this;

            confirmAdminAction({
                    title: 'Confirm stay extension',
                    message: 'Extend stay for booking ' + (bookingRef || 'this booking') + '?',
                    details: [
                        'Current checkout: ' + (oldCheckout || '—'),
                        'New checkout: ' + (newCheckout || '—')
                    ],
                    confirmText: 'Extend Stay',
                    icon: 'fa-calendar-plus',
                    tone: 'success'
                })
                .then(confirmed => {
                    if (!confirmed) return;

                    if (submitBtn) setButtonLoading(submitBtn, true);
                    setModalActionLoading(form, true, 'Extending stay...');

                    return postBookingAction(formData, 'Failed to extend stay.')
                        .then(data => {
                            setModalActionLoading(form, false);
                            closeExtendStayModal();
                            reloadWithBookingActionMessage(data, 'Stay extended successfully.');
                        })
                        .catch(error => {
                            // Force-clear loading state — do this first before anything else
                            // so the overlay never gets stuck even if Alert.show throws
                            try { setModalActionLoading(form, false); } catch(_) {}
                            try {
                                const mc = form ? form.closest('.modal-content') : null;
                                if (mc) {
                                    mc.classList.remove('is-modal-busy');
                                    const ldr = mc.querySelector('.modal-inline-loader');
                                    if (ldr) ldr.setAttribute('hidden', 'hidden');
                                }
                            } catch(_) {}
                            if (submitBtn) {
                                try { setButtonLoading(submitBtn, false); } catch(_) {}
                            }
                            Alert.show(error.message || 'An error occurred while extending the stay.', 'error');
                        });
                })
                .catch(() => {
                    try { setModalActionLoading(form, false); } catch(_) {}
                    if (submitBtn) {
                        try { setButtonLoading(submitBtn, false); } catch(_) {}
                    }
                });
        });

        // ── Manual Consolidation Modal ────────────────────────────────────────
        function fmtMWK(n) {
            return _currencySymbol + ' ' + parseFloat(n || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function badgePay(status) {
            const map = {
                paid: '#28a745',
                partial: '#fd7e14',
                unpaid: '#dc3545'
            };
            return '<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;background:' + (map[status] || '#6c757d') + ';color:#fff;">' + (status || 'unknown').toUpperCase() + '</span>';
        }

        async function openConsolidationModal(bookingId, reference) {
            const modal = document.getElementById('consolidationModal');
            if (!modal) return;
            document.getElementById('con_body').innerHTML =
                '<div style="padding:20px;text-align:center;color:#888;"><i class="fas fa-spinner fa-spin"></i> Loading financial summary...</div>';
            setBookingPageModalOpen(modal, true);

            const fd = new FormData();
            fd.append('action', 'consolidation_fetch');
            fd.append('id', bookingId);

            try {
                const data = await postBookingAction(fd, 'Failed to load financial data');
                const b = data.booking;
                const charges = data.charges || [];
                const payments = data.payments || [];
                const refunds = data.refunds || [];

                const roomLabel = [b.room_type, b.room_name || (b.room_number ? '#' + b.room_number : '')].filter(Boolean).join(' \u2014 ');

                // Charges table
                let chargesHtml = '';
                if (charges.length) {
                    chargesHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;"><thead><tr style="background:#f7f3ee;"><th style="padding:5px 8px;text-align:left;font-weight:600;">Description</th><th style="padding:5px 8px;text-align:right;font-weight:600;">Qty</th><th style="padding:5px 8px;text-align:right;font-weight:600;">Unit</th><th style="padding:5px 8px;text-align:right;font-weight:600;">Total</th></tr></thead><tbody>';
                    charges.forEach(c => {
                        chargesHtml += '<tr style="border-bottom:1px solid #eee;"><td style="padding:5px 8px;">' + escHtml(c.description) + '</td><td style="padding:5px 8px;text-align:right;">' + parseFloat(c.quantity) + '</td><td style="padding:5px 8px;text-align:right;">' + fmtMWK(c.unit_price) + '</td><td style="padding:5px 8px;text-align:right;font-weight:600;">' + fmtMWK(c.line_total) + '</td></tr>';
                    });
                    chargesHtml += '</tbody></table>';
                } else {
                    chargesHtml = '<p style="color:#999;font-size:12px;margin:6px 0 0;">No additional folio charges.</p>';
                }

                // Payments table
                let paysHtml = '';
                if (payments.length) {
                    paysHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;"><thead><tr style="background:#f7f3ee;"><th style="padding:5px 8px;text-align:left;font-weight:600;">Reference</th><th style="padding:5px 8px;text-align:left;font-weight:600;">Date</th><th style="padding:5px 8px;text-align:left;font-weight:600;">Method</th><th style="padding:5px 8px;text-align:right;font-weight:600;">Amount</th></tr></thead><tbody>';
                    payments.forEach(p => {
                        paysHtml += '<tr style="border-bottom:1px solid #eee;"><td style="padding:5px 8px;">' + escHtml(p.payment_reference) + (p.notes ? '<br><small style="color:#888;">' + escHtml(p.notes) + '</small>' : '') + '</td><td style="padding:5px 8px;">' + escHtml(p.payment_date) + '</td><td style="padding:5px 8px;">' + escHtml(p.payment_method.replace(/_/g, ' ')) + '</td><td style="padding:5px 8px;text-align:right;font-weight:600;color:#28a745;">' + fmtMWK(p.total_amount) + '</td></tr>';
                    });
                    paysHtml += '</tbody></table>';
                    refunds.forEach(r => {
                        paysHtml += '<p style="font-size:11px;color:#dc3545;margin:4px 0 0;"><i class="fas fa-rotate-left"></i> Refund ' + escHtml(r.payment_reference) + ' \u2014 ' + fmtMWK(r.total_amount) + ' (' + escHtml(r.payment_date) + ')' + (r.refund_reason ? ': ' + escHtml(r.refund_reason) : '') + '</p>';
                    });
                } else {
                    paysHtml = '<p style="color:#999;font-size:12px;margin:6px 0 0;">No payments recorded yet.</p>';
                }

                // Summary bar
                const summaryBar =
                    '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;">' +
                    '<div style="background:#f7f3ee;border-radius:6px;padding:10px 12px;text-align:center;"><div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.06em;">Total Charged</div><div style="font-size:18px;font-weight:700;color:#2a2723;margin-top:2px;">' + fmtMWK(b.total_with_vat + b.folio_charges) + '</div></div>' +
                    '<div style="background:#e8f5e9;border-radius:6px;padding:10px 12px;text-align:center;"><div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.06em;">Amount Paid</div><div style="font-size:18px;font-weight:700;color:#28a745;margin-top:2px;">' + fmtMWK(b.amount_paid) + '</div></div>' +
                    '<div style="background:' + (b.amount_due > 0.01 ? '#fff3f3' : '#e8f5e9') + ';border-radius:6px;padding:10px 12px;text-align:center;"><div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.06em;">Balance Due</div><div style="font-size:18px;font-weight:700;color:' + (b.amount_due > 0.01 ? '#dc3545' : '#28a745') + ';margin-top:2px;">' + fmtMWK(b.amount_due) + '</div></div>' +
                    '</div>';

                // Record Payment form
                const paymentForm =
                    '<form id="conPaymentForm">' +
                    '<input type="hidden" name="action" value="consolidation_record">' +
                    '<input type="hidden" name="id" value="' + b.id + '">' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
                    '<div class="form-group" style="margin:0;"><label style="font-weight:600;">Amount (' + _currencySymbol + ') <span style="color:#dc3545;">*</span></label><input type="number" name="amount" id="con_amount" class="form-control" min="0.01" step="0.01" placeholder="e.g. 25000.00" required style="border-color:#B18247;"></div>' +
                    '<div class="form-group" style="margin:0;"><label style="font-weight:600;">Payment method <span style="color:#dc3545;">*</span></label><select name="payment_method" class="form-control"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="credit_card">Credit Card</option><option value="debit_card">Debit Card</option><option value="mobile_money">Mobile Money</option><option value="cheque">Cheque</option><option value="other">Other</option></select></div>' +
                    '</div>' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
                    '<div class="form-group" style="margin:0;"><label style="font-weight:600;">Payment type</label><select name="payment_type" class="form-control"><option value="partial_payment">Partial payment</option><option value="full_payment">Full / final payment</option><option value="adjustment">Adjustment</option></select></div>' +
                    '<div class="form-group" style="margin:0;"><label style="font-weight:600;">Payment date</label><input type="date" name="payment_date" class="form-control" value="' + new Date().toISOString().split('T')[0] + '"></div>' +
                    '</div>' +
                    '<div class="form-group" style="margin-bottom:10px;"><label>General notes</label><input type="text" name="notes" class="form-control" placeholder="e.g. Cash collected at front desk" maxlength="200"></div>' +
                    '<div class="form-group" style="margin-bottom:14px;"><label style="font-weight:600;"><i class="fas fa-calculator" style="color:#B18247;"></i> Accountant notes</label><input type="text" name="accountant_notes" class="form-control" placeholder="e.g. Reconciled against invoice RFD-20260514, balance cleared" maxlength="300" style="border-color:#B18247;"><small style="color:#888;display:block;margin-top:4px;">Stored as &ldquo;ACCT: &hellip;&rdquo; prefix in payment notes &mdash; visible on invoices and ledger reports.</small></div>' +
                    '<div style="display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #eee;padding-top:14px;">' +
                    '<button type="button" class="btn btn-secondary" onclick="closeConsolidationModal()">Close</button>' +
                    '<button type="submit" id="con_submit_btn" class="btn btn-primary" style="background:#B18247;border-color:#B18247;"><i class="fas fa-scale-balanced"></i> Record Payment</button>' +
                    '</div></form>';

                document.getElementById('con_body').innerHTML =
                    '<div style="padding:16px 20px 0;">' +
                    '<div style="background:#f7f3ee;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:13px;line-height:1.7;">' +
                    '<strong>' + escHtml(b.guest_name) + '</strong>' +
                    (roomLabel ? ' &nbsp;|&nbsp; <strong>' + escHtml(roomLabel) + '</strong>' : '') +
                    '<br><span style="color:#666;">Ref: ' + escHtml(b.reference) + '</span>' +
                    ' &nbsp; <span style="color:#666;">' + escHtml(b.check_in) + ' \u2013 ' + escHtml(b.check_out) + ' (' + b.nights + ' nights)</span>' +
                    ' &nbsp; ' + badgePay(b.payment_status) +
                    '</div>' +
                    summaryBar +
                    '<details style="margin-bottom:12px;" open><summary style="font-size:12px;font-weight:600;color:#555;cursor:pointer;padding:4px 0;">Folio Charges</summary>' + chargesHtml + '</details>' +
                    '<details style="margin-bottom:16px;" open><summary style="font-size:12px;font-weight:600;color:#555;cursor:pointer;padding:4px 0;">Payment History</summary>' + paysHtml + '</details>' +
                    '<div style="border-top:2px solid #B18247;padding-top:14px;"><h4 style="margin:0 0 12px;font-size:14px;color:#2a2723;"><i class="fas fa-plus-circle"></i> Record New Payment</h4>' + paymentForm + '</div></div>';

                document.getElementById('conPaymentForm').addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const fd2 = new FormData(this);
                    const btn = document.getElementById('con_submit_btn');
                    if (btn) setButtonLoading(btn, true);
                    showLoadingOverlay('Recording payment...');
                    try {
                        const result = await postBookingAction(fd2, 'Failed to record payment');
                        hideLoadingOverlay();
                        if (btn) setButtonLoading(btn, false);
                        closeConsolidationModal();
                        Alert.show(result.message + ' Ref: ' + result.payment_reference, 'success');
                        setTimeout(() => window.location.reload(), 2000);
                    } catch (err) {
                        hideLoadingOverlay();
                        if (btn) setButtonLoading(btn, false);
                        Alert.show(err.message || 'Failed to record payment.', 'error');
                    }
                });

            } catch (err) {
                document.getElementById('con_body').innerHTML =
                    '<div style="padding:20px;color:#dc3545;"><i class="fas fa-circle-exclamation"></i> ' + escHtml(err.message || 'Failed to load data.') + '</div>';
            }
        }

        function closeConsolidationModal() {
            const modal = document.getElementById('consolidationModal');
            if (modal) setBookingPageModalOpen(modal, false);
        }

        // Upgrade Room Type Modal Functions
        let availableRoomsForUpgrade = [];

        function openUpgradeRoomModal(bookingId, bookingRef, currentRoomId, currentRoomName, guestName, checkIn, checkOut, totalAmount, paymentStatus) {
            const modal = document.getElementById('upgradeRoomModal');
            setBookingPageModalOpen(modal, true);
            document.getElementById('upgrade_booking_id').value = bookingId;
            document.getElementById('upgrade_booking_ref').value = bookingRef;
            document.getElementById('upgrade_guest_name').value = guestName;
            document.getElementById('upgrade_current_room').value = currentRoomName;
            document.getElementById('upgrade_current_room_id').value = currentRoomId;
            document.getElementById('upgrade_current_total').value = 'K ' + parseFloat(totalAmount).toLocaleString();

            const checkInDate = new Date(checkIn);
            const checkOutDate = new Date(checkOut);
            document.getElementById('upgrade_dates').value =
                checkInDate.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                }) +
                ' - ' +
                checkOutDate.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });

            // Load available room types for upgrade
            loadRoomTypesForUpgrade(currentRoomId, checkIn, checkOut);
        }

        function closeUpgradeRoomModal() {
            const modal = document.getElementById('upgradeRoomModal');
            setBookingPageModalOpen(modal, false);
            document.getElementById('upgrade_new_room').innerHTML = '<option value="">-- Select Room Type --</option>';
            document.getElementById('upgrade_price_preview').style.display = 'none';
            availableRoomsForUpgrade = [];
        }

        function loadRoomTypesForUpgrade(currentRoomId, checkIn, checkOut) {
            const roomSelect = document.getElementById('upgrade_new_room');
            roomSelect.innerHTML = '<option value="">Loading room types...</option>';

            // Fetch all active room types
            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        'action': 'get_all_room_types_for_upgrade',
                        'current_room_id': currentRoomId,
                        'check_in': checkIn,
                        'check_out': checkOut
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data && data.data.length > 0) {
                        availableRoomsForUpgrade = data.data;
                        roomSelect.innerHTML = '<option value="">-- Select Room Type --</option>';
                        data.data.forEach(room => {
                            const option = document.createElement('option');
                            option.value = room.id;
                            option.textContent = room.name + ' (K ' + parseFloat(room.price_per_night).toLocaleString() + '/night)';
                            option.dataset.price = room.price_per_night;
                            option.dataset.name = room.name;
                            roomSelect.appendChild(option);
                        });
                    } else {
                        roomSelect.innerHTML = '<option value="">No upgrade options available</option>';
                    }
                })
                .catch(() => {
                    roomSelect.innerHTML = '<option value="">Error loading room types</option>';
                });

            // Add change event listener for price preview
            roomSelect.onchange = function() {
                updateUpgradePricePreview();
            };
        }

        function updateUpgradePricePreview() {
            const roomSelect = document.getElementById('upgrade_new_room');
            const selectedOption = roomSelect.options[roomSelect.selectedIndex];
            const previewDiv = document.getElementById('upgrade_price_preview');

            if (!selectedOption || !selectedOption.value) {
                previewDiv.style.display = 'none';
                return;
            }

            const currentTotal = parseFloat(document.getElementById('upgrade_current_total').value.replace(/[^0-9.-]+/g, ''));
            const newPricePerNight = parseFloat(selectedOption.dataset.price);

            // Calculate nights from dates
            const datesText = document.getElementById('upgrade_dates').value;
            const dateParts = datesText.split(' - ');
            if (dateParts.length === 2) {
                const checkIn = new Date(dateParts[0]);
                const checkOut = new Date(dateParts[1]);
                const nights = Math.ceil((checkOut - checkIn) / (1000 * 60 * 60 * 24));
                const newTotal = newPricePerNight * nights;
                const priceDiff = newTotal - currentTotal;

                document.getElementById('upgrade_new_total').textContent = 'K ' + newTotal.toLocaleString();
                const diffText = priceDiff >= 0 ? '+K ' + priceDiff.toLocaleString() : '-K ' + Math.abs(priceDiff).toLocaleString();
                document.getElementById('upgrade_price_diff').textContent = diffText;
                document.getElementById('upgrade_price_diff').style.color = priceDiff >= 0 ? '#dc3545' : '#28a745';
                previewDiv.style.display = 'block';
            }
        }

        function submitUpgradeRoom() {
            const bookingId = document.getElementById('upgrade_booking_id').value;
            const newRoomId = document.getElementById('upgrade_new_room').value;
            const sendEmail = document.getElementById('upgrade_send_email').checked ? '1' : '0';

            if (!newRoomId) {
                Alert.show('Please select a new room type.', 'error');
                return;
            }

            const submitBtn = document.querySelector('#upgradeRoomModal button[onclick="submitUpgradeRoom()"]');
            if (submitBtn) setButtonLoading(submitBtn, true);
            showLoadingOverlay('Upgrading room type...');

            const formData = new FormData();
            formData.append('action', 'upgrade_room_type');
            formData.append('booking_id', bookingId);
            formData.append('new_room_id', newRoomId);
            formData.append('send_email', sendEmail);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const successMessage = data.message || 'Room type upgraded successfully!';
                        showBookingActionMessage(successMessage, 'success');
                        queueBookingActionMessage(successMessage, 'success');
                        closeUpgradeRoomModal();
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        hideLoadingOverlay();
                        if (submitBtn) setButtonLoading(submitBtn, false);
                        Alert.show(data.message || 'Failed to upgrade room type.', 'error');
                    }
                })
                .catch(() => {
                    hideLoadingOverlay();
                    if (submitBtn) setButtonLoading(submitBtn, false);
                    Alert.show('Error upgrading room type.', 'error');
                });
        }

        // Close modal when clicking outside
        document.getElementById('upgradeRoomModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeUpgradeRoomModal();
            }
        });

        // Delegated event listeners for action buttons
        function bindBookingsActionDelegates() {
            if (window.__bookingsActionDelegatesBound) return;
            window.__bookingsActionDelegatesBound = true;

            // Close modal when clicking outside for quickRoomAssignModal
            const quickRoomAssignModal = document.getElementById('quickRoomAssignModal');
            if (quickRoomAssignModal && !quickRoomAssignModal.dataset.overlayCloseBound) {
                quickRoomAssignModal.dataset.overlayCloseBound = '1';
                quickRoomAssignModal.addEventListener('click', function(event) {
                    if (event.target === this) {
                        closeQuickRoomAssignModal();
                    }
                });
            }

            document.addEventListener('click', function(event) {
                const button = event.target.closest('[data-action]');
                if (!button) return;

                try {
                    const action = button.dataset.action;
                    const bookingId = button.dataset.bookingId;
                    const bookingRef = button.dataset.bookingRef || '';

                    if (!bookingId) {
                        Alert.show('This booking action is missing its booking ID.', 'error');
                        return;
                    }

                    if (action === 'assign-room') {
                        // Prevent default behavior and stop propagation
                        event.preventDefault();
                        event.stopPropagation();

                        const checkIn = button.dataset.checkIn;
                        const checkOut = button.dataset.checkOut;
                        const roomId = button.dataset.roomId;

                        // bookingRef is optional for assign-room action
                        const effectiveBookingRef = bookingRef || button.dataset.bookingRef || 'N/A';

                        if (!checkIn || !checkOut || !roomId) {
                            Alert.show('Room assignment data is incomplete. Refresh and try again.', 'error');
                            return;
                        }

                        openQuickRoomAssignModal(bookingId, effectiveBookingRef, checkIn, checkOut, roomId);
                    } else if (action === 'make-tentative') {
                        const tentativeType = button.dataset.tentativeType; // 'make_tentative' or 'convert_to_tentative'
                        if (typeof openMakeTentativeModal === 'function') {
                            openMakeTentativeModal(bookingId, bookingRef, tentativeType);
                        }
                    } else if (action === 'check-in') {
                        event.preventDefault();
                        event.stopPropagation();
                        const guestName = button.dataset.guestName;
                        const checkInDate = button.dataset.checkInDate;
                        const paymentStatus = button.dataset.paymentStatus;
                        const roomAssigned = button.dataset.roomAssigned === 'true' || button.dataset.roomAssigned === '1';
                        const bookingStatus = button.dataset.bookingStatus;
                        if (typeof openCheckInModal === 'function') {
                            openCheckInModal(bookingId, bookingRef, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus);
                        }
                    } else if (action === 'no-show') {
                        event.preventDefault();
                        event.stopPropagation();
                        const guestName = button.dataset.guestName;
                        const checkInDate = button.dataset.checkInDate;
                        const paymentStatus = button.dataset.paymentStatus;
                        const roomAssigned = button.dataset.roomAssigned === 'true' || button.dataset.roomAssigned === '1';
                        const bookingStatus = button.dataset.bookingStatus;
                        if (typeof openCheckInModal === 'function') {
                            openCheckInModal(bookingId, bookingRef, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus, 'noshow');
                        }
                    } else if (action === 'upgrade-room') {
                        const currentRoomId = button.dataset.currentRoomId;
                        const currentRoomName = button.dataset.currentRoomName;
                        const guestName = button.dataset.guestName;
                        const checkIn = button.dataset.checkIn;
                        const checkOut = button.dataset.checkOut;
                        const totalAmount = button.dataset.totalAmount;
                        const paymentStatus = button.dataset.paymentStatus;

                        if (typeof openUpgradeRoomModal === 'function') {
                            openUpgradeRoomModal(bookingId, bookingRef, currentRoomId, currentRoomName, guestName, checkIn, checkOut, totalAmount, paymentStatus);
                        }
                    }
                } catch (error) {
                    if (typeof Alert !== 'undefined' && Alert.show) {
                        Alert.show('An error occurred while opening the modal. Please try again.', 'error');
                    }
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindBookingsActionDelegates);
        } else {
            bindBookingsActionDelegates();
        }
    </script>
    <script src="js/admin-components.js"></script>

    <!-- ===== Modify Booking Modal ===== -->
    <div id="modifyBookingModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-pen-to-square"></i> Modify Booking</h3>
                <button type="button" class="close-modal" onclick="closeModifyBookingModal()">&times;</button>
            </div>
            <form id="modifyBookingForm" method="POST" action="">
                <input type="hidden" name="action" value="modify_booking">
                <input type="hidden" name="id" id="mb_booking_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Booking Reference</label>
                        <input type="text" id="mb_ref" class="form-control" readonly style="background:#f5f5f5;">
                    </div>

                    <div class="form-section-title">Guest</div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Full name</label>
                            <input type="text" name="guest_name" id="mb_guest_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="guest_email" id="mb_guest_email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="guest_phone" id="mb_guest_phone" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="guest_country" id="mb_guest_country" class="form-control">
                        </div>
                    </div>

                    <div class="form-section-title">Stay</div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Check-in date</label>
                            <input type="date" name="check_in_date" id="mb_check_in" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Check-out date</label>
                            <input type="date" name="check_out_date" id="mb_check_out" class="form-control" required>
                        </div>
                    </div>
                    <div class="grid-3">
                        <div class="form-group">
                            <label>Adults</label>
                            <input type="number" min="1" name="adult_guests" id="mb_adults" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Children</label>
                            <input type="number" min="0" name="child_guests" id="mb_children" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Total guests</label>
                            <input type="number" min="1" name="number_of_guests" id="mb_guests" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-section-title">Status &amp; payment</div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="mb_status" class="form-control">
                                <option value="pending">Pending</option>
                                <option value="tentative">Tentative</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="checked-in">Checked in</option>
                                <option value="checked-out">Checked out</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="no-show">No-show</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Payment status</label>
                            <select name="payment_status" id="mb_payment_status" class="form-control">
                                <option value="unpaid">Unpaid</option>
                                <option value="partial">Partial</option>
                                <option value="paid">Paid</option>
                                <option value="completed">Completed</option>
                                <option value="refunded">Refunded</option>
                                <option value="partially_refunded">Partially refunded</option>
                                <option value="failed">Failed</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        <div class="form-group <?php echo $_perm_edit_financials ? '' : 'is-financial-locked'; ?>">
                            <label>Total amount</label>
                            <input type="number" step="0.01" name="total_amount" id="mb_total" class="form-control" <?php echo $_perm_edit_financials ? '' : 'readonly disabled aria-disabled="true"'; ?>>
                            <?php if (!$_perm_edit_financials): ?>
                                <small class="field-lock-hint">Only admin users or users with the Edit Booking Financials permission can change booking amounts.</small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group is-financial-locked">
                            <label>Amount paid</label>
                            <input type="number" step="0.01" name="amount_paid" id="mb_paid" class="form-control" readonly disabled aria-disabled="true">
                            <small class="field-lock-hint">Amount paid is calculated from payment records and cannot be edited here.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Special requests / internal note</label>
                        <textarea name="special_requests" id="mb_special" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Audit note (why)</label>
                        <input type="text" name="note" id="mb_note" class="form-control" placeholder="e.g. Guest requested earlier check-in">
                    </div>

                    <div style="background:#fff8e1;padding:10px 12px;border-radius:8px;color:#8B7355;font-size:13px;">
                        <i class="fas fa-info-circle"></i>
                        Changes are recorded in the booking audit log. Use <a href="#" id="mb_full_edit_link">the full edit page</a> for advanced fields (room type, occupancy pricing).
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModifyBookingModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== Booking Audit Log Modal ===== -->
    <div id="bookingAuditModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" data-close-on-overlay="false">
        <div class="modal-content booking-audit-modal__dialog">
            <div class="modal-header booking-audit-modal__header">
                <h3 class="booking-audit-modal__title"><i class="fas fa-clock-rotate-left"></i> Booking Audit Timeline <small id="bal_ref" class="booking-audit-modal__ref"></small></h3>
                <button type="button" class="close-modal" onclick="closeBookingAuditModal()">&times;</button>
            </div>
            <div class="modal-body booking-audit-modal__content-wrap">
                <div id="bal_body" class="booking-audit-modal__body">
                    <div class="booking-audit-modal__loading"><i class="fas fa-spinner fa-spin"></i> Loading timeline...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeBookingAuditModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // ===== Modify Booking modal logic =====
        function setBookingPageModalOpen(modal, isOpen) {
            if (!modal) return;
            modal.style.transition = 'none';
            modal.style.display = isOpen ? 'flex' : 'none';
            modal.style.opacity = isOpen ? '1' : '0';
            modal.style.visibility = isOpen ? 'visible' : 'hidden';
            modal.classList.toggle('active', isOpen);
            modal.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            if (isOpen) {
                document.body.classList.add('modal-open');
            } else if (!document.querySelector('.modal-overlay.active, .modal.active')) {
                document.body.classList.remove('modal-open');
            }
        }

        function openModifyBookingModal(bookingId, bookingRef) {
            const modal = document.getElementById('modifyBookingModal');
            setBookingPageModalOpen(modal, true);
            document.getElementById('mb_booking_id').value = bookingId;
            document.getElementById('mb_ref').value = bookingRef || '';
            document.getElementById('mb_full_edit_link').href = 'edit-booking.php?id=' + encodeURIComponent(bookingId);

            const fd = new FormData();
            fd.append('action', 'get_booking_for_modify');
            fd.append('booking_id', bookingId);
            fetch(window.location.href, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(j => {
                    if (!j.success) {
                        Alert.show(j.message || 'Failed to load booking', 'error');
                        return;
                    }
                    const b = j.data;
                    const set = (id, v) => {
                        const el = document.getElementById(id);
                        if (el) el.value = (v ?? '');
                    };
                    set('mb_guest_name', b.guest_name);
                    set('mb_guest_email', b.guest_email);
                    set('mb_guest_phone', b.guest_phone);
                    set('mb_guest_country', b.guest_country);
                    set('mb_check_in', b.check_in_date);
                    set('mb_check_out', b.check_out_date);
                    set('mb_adults', b.adult_guests);
                    set('mb_children', b.child_guests);
                    set('mb_guests', b.number_of_guests);
                    set('mb_status', b.status);
                    set('mb_payment_status', b.payment_status);
                    set('mb_total', b.total_amount);
                    set('mb_paid', b.amount_paid);
                    set('mb_special', b.special_requests);
                    set('mb_note', '');
                })
                .catch(() => Alert.show('Error loading booking', 'error'));
        }

        function closeModifyBookingModal() {
            const m = document.getElementById('modifyBookingModal');
            setBookingPageModalOpen(m, false);
        }
        document.getElementById('modifyBookingModal').addEventListener('click', function(e) {
            if (e.target === this) closeModifyBookingModal();
        });
        document.getElementById('modifyBookingForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            const getFieldValue = (fieldName, fieldId, fallback = '-') => {
                const posted = fd.get(fieldName);
                if (posted !== null && posted !== '') {
                    return posted;
                }
                const el = document.getElementById(fieldId);
                if (el && el.value !== '') {
                    return el.value;
                }
                return fallback;
            };
            const ref = document.getElementById('mb_ref').value || ('Booking #' + (fd.get('id') || ''));
            const confirmed = await confirmAdminAction({
                title: 'Confirm booking changes',
                message: 'Review the main booking values before saving ' + ref + '.',
                details: [
                    'Stay: ' + (fd.get('check_in_date') || '-') + ' to ' + (fd.get('check_out_date') || '-'),
                    'Guests: ' + (fd.get('number_of_guests') || '-') + ' total, ' + (fd.get('adult_guests') || '-') + ' adult(s), ' + (fd.get('child_guests') || '0') + ' child guest(s)',
                    'Status: ' + (fd.get('status') || '-'),
                    'Payment status: ' + (fd.get('payment_status') || '-'),
                    'Total amount: ' + getFieldValue('total_amount', 'mb_total', '0'),
                    'Amount paid: ' + getFieldValue('amount_paid', 'mb_paid', '0')
                ],
                confirmText: 'Save Changes',
                icon: 'fa-pen-to-square'
            });
            if (!confirmed) return;
            const btn = this.querySelector('button[type=submit]');
            if (btn) setButtonLoading(btn, true);
            showLoadingOverlay('Saving changes…');
            fetch(window.location.href, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(j => {
                    hideLoadingOverlay();
                    if (j.success) {
                        Alert.show(j.message || 'Saved', 'success');
                        setTimeout(() => window.location.reload(), 800);
                    } else {
                        if (btn) setButtonLoading(btn, false);
                        Alert.show(j.message || 'Failed to save', 'error');
                    }
                })
                .catch(() => {
                    hideLoadingOverlay();
                    if (btn) setButtonLoading(btn, false);
                    Alert.show('Error saving changes', 'error');
                });
        });

        // ===== Refund flow =====
        function openRefundForBooking(bookingId, bookingRef) {
            const fd = new FormData();
            fd.append('action', 'find_payment_for_refund');
            fd.append('booking_id', bookingId);
            showLoadingOverlay('Looking up payment…');
            fetch(window.location.href, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(j => {
                    hideLoadingOverlay();
                    if (j.success && j.payment && j.payment.id) {
                        return confirmAdminAction({
                            title: 'Open refund form',
                            message: 'Open the refund form for this booking payment?',
                            details: [
                                'Booking: ' + bookingRef,
                                'Payment: ' + (j.payment.payment_reference || 'Payment #' + j.payment.id)
                            ],
                            confirmText: 'Open Refund',
                            icon: 'fa-rotate-left',
                            tone: 'warning'
                        }).then(confirmed => {
                            if (confirmed) {
                                window.location.href = 'payment-refund.php?id=' + encodeURIComponent(j.payment.id);
                            }
                        });
                    } else {
                        Alert.show(j.message || 'No refundable payment found.', 'error');
                    }
                })
                .catch(() => {
                    hideLoadingOverlay();
                    Alert.show('Error looking up payment', 'error');
                });
        }

        // ===== Audit log viewer =====
        const AUDIT_FIELD_LABELS = {
            check_in_date: 'Check-in date',
            check_out_date: 'Check-out date',
            status: 'Status',
            payment_status: 'Payment status',
            total_amount: 'Total amount',
            amount_paid: 'Amount paid',
            amount_due: 'Amount due',
            number_of_nights: 'Nights',
            number_of_guests: 'Guests',
            adult_guests: 'Adults',
            child_guests: 'Children',
            guest_name: 'Guest name',
            guest_email: 'Email',
            guest_phone: 'Phone',
            guest_country: 'Country',
            special_requests: 'Special requests',
            admin_note: 'Admin note',
            room_id: 'Room type',
            individual_room_id: 'Room number'
        };
        const AUDIT_ACTION_LABELS = {
            modified: 'Modified',
            cancelled: 'Cancelled',
            'checked-in': 'Checked In',
            'checked-out': 'Checked Out',
            'no-show': 'No Show',
            extended: 'Extended Stay',
            upgraded: 'Room Upgraded',
            payment_updated: 'Payment Updated',
            payment_consolidation: 'Payment Recorded',
            tentative: 'Made Tentative',
            confirmed: 'Confirmed',
            pending: 'Set to Pending',
            'check-in cancelled': 'Check-in Cancelled'
        };
        const AUDIT_CURRENCY = <?php echo json_encode((string)($currency_symbol ?? 'MWK')); ?>;

        function formatAuditDate(value) {
            if (!value) return '\u2014';
            const d = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(d.getTime())) return escapeHtml(String(value));
            return escapeHtml(d.toLocaleString('en-GB', {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }));
        }

        function formatAuditValue(field, value) {
            if (value === null || value === undefined || value === '') return '\u2014';
            if (/(amount|total|refund|vat)/i.test(field)) {
                const n = Number(value);
                if (!Number.isNaN(n)) return escapeHtml(AUDIT_CURRENCY + ' ' + n.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }));
            }
            if (/(date|_at)$/i.test(field)) {
                return formatAuditDate(String(value));
            }
            if (typeof value === 'boolean') return value ? 'Yes' : 'No';
            return escapeHtml(String(value));
        }

        function prettifyKey(key) {
            return AUDIT_FIELD_LABELS[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }

        function computeExtensionText(oldVals, newVals) {
            const oldCheckout = oldVals && oldVals.check_out_date ? String(oldVals.check_out_date) : '';
            const newCheckout = newVals && newVals.check_out_date ? String(newVals.check_out_date) : '';
            if (!oldCheckout || !newCheckout) return '';
            const oldDate = new Date(oldCheckout + 'T00:00:00');
            const newDate = new Date(newCheckout + 'T00:00:00');
            if (Number.isNaN(oldDate.getTime()) || Number.isNaN(newDate.getTime())) return '';
            const diffDays = Math.round((newDate.getTime() - oldDate.getTime()) / 86400000);
            if (diffDays > 0) return 'Extended by ' + diffDays + ' night' + (diffDays === 1 ? '' : 's');
            if (diffDays < 0) return 'Reduced by ' + Math.abs(diffDays) + ' night' + (Math.abs(diffDays) === 1 ? '' : 's');
            return '';
        }

        function renderAuditDiff(row) {
            const changed = Array.isArray(row.changed_fields) ? row.changed_fields : [];
            if (!changed.length) return '';
            const oldVals = row.old_values || {};
            const newVals = row.new_values || {};
            let html = '<div class="bal-item__section"><h5>Field Changes</h5><table class="alog-diff"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
            changed.forEach(field => {
                html += '<tr>' +
                    '<td><strong>' + escapeHtml(prettifyKey(field)) + '</strong></td>' +
                    '<td class="f-old">' + formatAuditValue(field, oldVals[field]) + '</td>' +
                    '<td class="f-new">' + formatAuditValue(field, newVals[field]) + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>';
            return html;
        }

        function renderAuditEvent(row) {
            const changed = Array.isArray(row.changed_fields) ? row.changed_fields : [];
            const oldVals = row.old_values || {};
            const newVals = row.new_values || {};
            const extensionHint = computeExtensionText(oldVals, newVals);
            const isExtension = row.action === 'extended' || changed.includes('check_out_date') || changed.includes('number_of_nights');
            const title = isExtension && extensionHint ? 'Stay Extension' : (AUDIT_ACTION_LABELS[row.action] || prettifyKey(String(row.action || 'update')));
            const who = row.performed_by_name || ('User #' + (row.performed_by || '?'));
            const note = row.note ? '<div class="bal-note"><strong>Note:</strong> ' + escapeHtml(row.note) + '</div>' : '';
            const changeCount = changed.length ? '<span class="bal-chip">' + changed.length + ' field' + (changed.length === 1 ? '' : 's') + ' changed</span>' : '';
            const extensionChip = extensionHint ? '<span class="bal-chip bal-chip--accent">' + escapeHtml(extensionHint) + '</span>' : '';
            const ip = row.ip_address ? '<span class="bal-meta-pill">IP ' + escapeHtml(row.ip_address) + '</span>' : '';

            return '<article class="bal-item">' +
                '<div class="bal-item__head">' +
                '<h4>' + escapeHtml(title) + '</h4>' +
                '<div class="bal-item__meta">' +
                '<span>' + escapeHtml(who) + '</span>' +
                '<span>\u2022</span>' +
                '<span>' + formatAuditDate(row.performed_at) + '</span>' +
                ip +
                '</div>' +
                '</div>' +
                '<div class="bal-item__chips">' + changeCount + extensionChip + '</div>' +
                renderAuditDiff(row) +
                note +
                '</article>';
        }

        function renderPaymentEvent(row) {
            const amountVal = Number(row.total_amount ?? row.payment_amount ?? row.refund_amount ?? 0);
            const amountText = AUDIT_CURRENCY + ' ' + amountVal.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            const who = row.recorded_by_name || (row.recorded_by ? ('User #' + row.recorded_by) : 'System');
            const type = String(row.payment_type || 'payment').replace(/_/g, ' ');
            const status = row.payment_status ? String(row.payment_status) : 'completed';
            const ref = row.payment_reference ? '<span class="bal-meta-pill">Ref ' + escapeHtml(row.payment_reference) + '</span>' : '';
            const method = row.payment_method ? '<span class="bal-meta-pill">' + escapeHtml(String(row.payment_method).replace(/_/g, ' ')) + '</span>' : '';
            const statusChipClass = /refund/i.test(type) ? ' bal-chip--warn' : ' bal-chip--ok';
            const notes = row.notes ? '<div class="bal-note"><strong>Notes:</strong> ' + escapeHtml(row.notes) + '</div>' : '';
            const reason = row.refund_reason ? '<div class="bal-note"><strong>Refund reason:</strong> ' + escapeHtml(row.refund_reason) + '</div>' : '';

            return '<article class="bal-item bal-item--payment">' +
                '<div class="bal-item__head">' +
                '<h4>' + (/refund/i.test(type) ? 'Refund Recorded' : 'Payment Recorded') + '</h4>' +
                '<div class="bal-item__meta">' +
                '<span>' + escapeHtml(who) + '</span>' +
                '<span>\u2022</span>' +
                '<span>' + formatAuditDate(row.created_at || row.payment_date) + '</span>' +
                '</div>' +
                '</div>' +
                '<div class="bal-item__chips">' +
                '<span class="bal-chip' + statusChipClass + '">' + escapeHtml(type) + '</span>' +
                '<span class="bal-chip">' + escapeHtml(status) + '</span>' +
                '<span class="bal-chip bal-chip--amount">' + escapeHtml(amountText) + '</span>' +
                method +
                ref +
                '</div>' +
                reason +
                notes +
                '</article>';
        }

        function renderTentativeEvent(row) {
            const action = String(row.action || 'tentative').replace(/_/g, ' ');
            const who = row.performed_by_name || (row.performed_by ? ('User #' + row.performed_by) : 'System');
            const reason = row.action_reason ? '<div class="bal-note"><strong>Reason:</strong> ' + escapeHtml(row.action_reason) + '</div>' : '';
            const prevExp = row.previous_expires_at ? '<span class="bal-meta-pill">Prev expiry: ' + formatAuditDate(row.previous_expires_at) + '</span>' : '';
            const newExp = row.new_expires_at ? '<span class="bal-meta-pill">New expiry: ' + formatAuditDate(row.new_expires_at) + '</span>' : '';

            return '<article class="bal-item bal-item--tentative">' +
                '<div class="bal-item__head">' +
                '<h4>Tentative Timeline</h4>' +
                '<div class="bal-item__meta">' +
                '<span>' + escapeHtml(who) + '</span>' +
                '<span>\u2022</span>' +
                '<span>' + formatAuditDate(row.created_at) + '</span>' +
                '</div>' +
                '</div>' +
                '<div class="bal-item__chips">' +
                '<span class="bal-chip bal-chip--accent">' + escapeHtml(action) + '</span>' +
                prevExp +
                newExp +
                '</div>' +
                reason +
                '</article>';
        }

        function renderAuditSummary(snapshot, counts) {
            if (!snapshot) return '';
            const room = [snapshot.room_type_name || '', snapshot.room_number ? ('Room ' + snapshot.room_number) : ''].filter(Boolean).join(' \u2022 ');
            const chips = [
                '<span class="bal-chip">Status: ' + escapeHtml(snapshot.status || '\u2014') + '</span>',
                '<span class="bal-chip">Payment: ' + escapeHtml(snapshot.payment_status || '\u2014') + '</span>',
                '<span class="bal-chip">Stay: ' + formatAuditDate(snapshot.check_in_date) + ' -> ' + formatAuditDate(snapshot.check_out_date) + '</span>',
                '<span class="bal-chip">Nights: ' + escapeHtml(String(snapshot.number_of_nights ?? '\u2014')) + '</span>',
                '<span class="bal-chip bal-chip--amount">Total: ' + formatAuditValue('total_amount', snapshot.total_amount) + '</span>',
                '<span class="bal-chip bal-chip--ok">Paid: ' + formatAuditValue('amount_paid', snapshot.amount_paid) + '</span>',
                '<span class="bal-chip bal-chip--warn">Due: ' + formatAuditValue('amount_due', snapshot.amount_due) + '</span>',
                '<span class="bal-chip">Events: ' + counts.total + '</span>'
            ];

            return '<section class="bal-summary">' +
                '<div class="bal-summary__top">' +
                '<h4>' + escapeHtml(snapshot.guest_name || 'Guest') + '</h4>' +
                '<div class="bal-summary__sub">' +
                (snapshot.guest_email ? '<span>' + escapeHtml(snapshot.guest_email) + '</span>' : '') +
                (snapshot.guest_phone ? '<span>' + escapeHtml(snapshot.guest_phone) + '</span>' : '') +
                (room ? '<span>' + escapeHtml(room) + '</span>' : '') +
                '</div>' +
                '</div>' +
                '<div class="bal-summary__chips">' + chips.join('') + '</div>' +
                '</section>';
        }

        function viewBookingAuditLog(bookingId, ref) {
            _closeAllActionMenus(null);
            document.getElementById('bal_ref').textContent = ref ? ('\u00b7 ' + ref) : '';
            const body = document.getElementById('bal_body');
            body.innerHTML = '<div class="booking-audit-modal__loading"><i class="fas fa-spinner fa-spin"></i> Loading timeline...</div>';
            const modal = document.getElementById('bookingAuditModal');
            setBookingPageModalOpen(modal, true);
            const fd = new FormData();
            fd.append('action', 'get_booking_audit_log');
            fd.append('booking_id', bookingId);
            fetch(window.location.href, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(j => {
                    if (!j.success) {
                        body.innerHTML = '<div class="bal-state bal-state--error">Failed to load booking timeline.</div>';
                        return;
                    }
                    const payload = Array.isArray(j.data) ? {
                        booking: null,
                        audit_logs: j.data,
                        payments: [],
                        tentative_log: []
                    } : (j.data || {});
                    const auditLogs = Array.isArray(payload.audit_logs) ? payload.audit_logs : [];
                    const paymentLogs = Array.isArray(payload.payments) ? payload.payments : [];
                    const tentativeLogs = Array.isArray(payload.tentative_log) ? payload.tentative_log : [];

                    const timeline = [];
                    auditLogs.forEach(row => timeline.push({
                        type: 'audit',
                        ts: row.performed_at || '',
                        html: renderAuditEvent(row)
                    }));
                    paymentLogs.forEach(row => timeline.push({
                        type: 'payment',
                        ts: row.created_at || row.payment_date || '',
                        html: renderPaymentEvent(row)
                    }));
                    tentativeLogs.forEach(row => timeline.push({
                        type: 'tentative',
                        ts: row.created_at || '',
                        html: renderTentativeEvent(row)
                    }));

                    timeline.sort((a, b) => {
                        const ta = new Date(String(a.ts).replace(' ', 'T')).getTime() || 0;
                        const tb = new Date(String(b.ts).replace(' ', 'T')).getTime() || 0;
                        return tb - ta;
                    });

                    if (!timeline.length) {
                        body.innerHTML = '<div class="bal-state">No audit entries yet.</div>';
                        return;
                    }

                    const counts = {
                        total: timeline.length,
                        audits: auditLogs.length,
                        payments: paymentLogs.length,
                        tentative: tentativeLogs.length
                    };

                    body.innerHTML = '<div class="bal-shell">' +
                        renderAuditSummary(payload.booking, counts) +
                        '<div class="bal-overview">' +
                        '<span class="bal-chip">Audit events: ' + counts.audits + '</span>' +
                        '<span class="bal-chip bal-chip--ok">Payment events: ' + counts.payments + '</span>' +
                        '<span class="bal-chip bal-chip--accent">Tentative events: ' + counts.tentative + '</span>' +
                        '</div>' +
                        '<div class="bal-timeline">' + timeline.map(item => item.html).join('') + '</div>' +
                        '</div>';
                })
                .catch(() => {
                    body.innerHTML = '<div class="bal-state bal-state--error">Error loading booking timeline.</div>';
                });
        }

        function closeBookingAuditModal() {
            const m = document.getElementById('bookingAuditModal');
            setBookingPageModalOpen(m, false);
        }
        document.getElementById('bookingAuditModal').addEventListener('click', function(e) {
            if (e.target === this && this.dataset.closeOnOverlay !== 'false') closeBookingAuditModal();
        });

        function escapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        // ===== Send invoice email from dropdown =====
        function sendInvoiceEmail(bookingId, ref, triggerEl, clickEvent) {
            if (clickEvent) clickEvent.stopPropagation();
            if (!bookingId) return;
            confirmAdminAction({
                title: 'Send invoice to guest',
                message: 'Generate if needed and email the invoice for ' + escapeHtml(ref) + ' to the guest?',
                confirmText: 'Send Invoice',
                icon: 'fa-file-invoice'
            }).then(confirmed => {
                if (!confirmed) return;
                showLoadingOverlay('Sending invoice…');
                const fd = new FormData();
                fd.append('action', 'send_invoice_email');
                fd.append('booking_id', bookingId);
                fetch(window.location.href, {
                        method: 'POST',
                        body: fd,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(async r => {
                        const contentType = (r.headers.get('content-type') || '').toLowerCase();
                        if (!contentType.includes('application/json')) {
                            throw new Error('We could not confirm the invoice send result. Please sign in again and retry.');
                        }
                        const data = await r.json();
                        if (!r.ok || !data.success) {
                            throw new Error(data.message || 'We could not send the invoice right now.');
                        }
                        return data;
                    })
                    .then(j => {
                        hideLoadingOverlay();
                        showBookingActionMessage(j.message || 'Invoice email sent to the guest successfully.', 'success');
                    })
                    .catch((error) => {
                        hideLoadingOverlay();
                        showBookingActionMessage((error && error.message) ? error.message : 'Network error. Please try again.', 'error');
                    });
            });
        }

        // ===== Overflow menu toggling =====
        function _closeAllActionMenus(except) {
            document.querySelectorAll('.actions-more.open').forEach(el => {
                if (el === except) return;
                el.classList.remove('open');
                const m = el.querySelector('.actions-more-menu');
                if (m) m.removeAttribute('style');
            });
        }

        function _getActionMenuViewport() {
            const vv = window.visualViewport;
            if (vv) {
                return {
                    left: 0,
                    top: 0,
                    right: vv.width,
                    bottom: vv.height,
                    width: vv.width,
                    height: vv.height
                };
            }
            return {
                left: 0,
                top: 0,
                right: window.innerWidth,
                bottom: window.innerHeight,
                width: window.innerWidth,
                height: window.innerHeight
            };
        }

        function toggleActionsMore(btn, evt) {
            if (evt && typeof evt.preventDefault === 'function') {
                evt.preventDefault();
            }
            if (evt && typeof evt.stopPropagation === 'function') {
                evt.stopPropagation();
            }
            const wrap = btn.closest('.actions-more');
            if (!wrap) return;
            const menu = wrap.querySelector('.actions-more-menu');
            if (!menu) return;
            const isOpen = wrap.classList.contains('open');
            _closeAllActionMenus(null);
            if (isOpen) return; // was open → now closed, done

            // Position using fixed coords so overflow:auto containers don't clip it.
            const rect = btn.getBoundingClientRect();
            const viewportPad = 8;
            const menuGap = 6;
            const viewport = _getActionMenuViewport();
            const maxMenuW = Math.max(0, Math.floor(viewport.width - (viewportPad * 2)));
            // On mobile cap the menu height so it never fills the whole screen and stays scrollable.
            const maxMenuH = viewport.width <= 640 ?
                Math.max(180, Math.floor(Math.min(viewport.height * 0.58, viewport.height - (viewportPad * 2)))) :
                Math.max(180, Math.floor(viewport.height - (viewportPad * 2)));

            menu.style.cssText = 'display:block;position:fixed;visibility:hidden;left:0;top:0;z-index:12050;width:auto;min-width:0;max-width:' + Math.round(maxMenuW) + 'px;';
            const measuredRect = menu.getBoundingClientRect();
            const fixedOffsetX = measuredRect.left;
            const fixedOffsetY = measuredRect.top;
            const menuW = Math.min(Math.ceil(measuredRect.width) || 250, maxMenuW);
            const menuH = Math.min(Math.ceil(measuredRect.height) || 0, maxMenuH);

            let top = rect.bottom + menuGap;
            let left = rect.right - menuW;
            const minLeft = viewport.left + viewportPad;
            const maxLeft = Math.max(minLeft, viewport.right - viewportPad - menuW);

            // On narrow mobile screens, keep overflow menus closer to center so
            // they do not feel pinned against the left edge.
            if (viewport.width <= 640) {
                const centeredLeft = viewport.left + ((viewport.width - menuW) / 2);
                left = centeredLeft;
            }

            left = Math.min(Math.max(left, minLeft), maxLeft);

            const spaceBelow = (viewport.bottom - viewportPad) - (rect.bottom + menuGap);
            const spaceAbove = (rect.top - menuGap) - (viewport.top + viewportPad);
            if (viewport.width <= 640 && spaceAbove > spaceBelow) {
                top = rect.top - menuH - menuGap;
            } else if (top + menuH > viewport.bottom - viewportPad) {
                top = rect.top - menuH - menuGap; // flip above button
            }
            const minTop = viewport.top + viewportPad;
            const maxTop = Math.max(minTop, viewport.bottom - viewportPad - menuH);
            top = Math.min(Math.max(top, minTop), maxTop);

            const applyMenuPosition = function(posLeft, posTop) {
                menu.style.cssText = 'display:block;position:fixed;z-index:12050;top:' + Math.round(posTop) + 'px;left:' + Math.round(posLeft) + 'px;right:auto;width:' + Math.round(menuW) + 'px;max-width:' + Math.round(maxMenuW) + 'px;max-height:' + Math.round(maxMenuH) + 'px;overflow-y:auto;overflow-x:hidden;';
            };

            const desiredRect = {
                left: minLeft,
                right: viewport.right - viewportPad,
                top: minTop,
                bottom: viewport.bottom - viewportPad
            };

            const nudgeIntoViewport = function(rect) {
                let deltaX = 0;
                let deltaY = 0;

                if (rect.left < desiredRect.left) {
                    deltaX = desiredRect.left - rect.left;
                } else if (rect.right > desiredRect.right) {
                    deltaX = desiredRect.right - rect.right;
                }

                if (rect.top < desiredRect.top) {
                    deltaY = desiredRect.top - rect.top;
                } else if (rect.bottom > desiredRect.bottom) {
                    deltaY = desiredRect.bottom - rect.bottom;
                }

                return {
                    x: deltaX,
                    y: deltaY
                };
            };

            let posLeft = left - fixedOffsetX;
            let posTop = top - fixedOffsetY;

            // Some transformed admin layouts can produce negative fixed offsets,
            // which leaves the menu clipped on the left edge. Keep style coords
            // within a safe visible minimum.
            posLeft = Math.max(posLeft, viewportPad);
            posTop = Math.max(posTop, viewportPad);

            applyMenuPosition(posLeft, posTop);

            let positionedRect = menu.getBoundingClientRect();
            let nudge = nudgeIntoViewport(positionedRect);
            if (Math.abs(nudge.x) > 0.5 || Math.abs(nudge.y) > 0.5) {
                posLeft += nudge.x;
                posTop += nudge.y;
                applyMenuPosition(posLeft, posTop);
                positionedRect = menu.getBoundingClientRect();
            }

            const outsideViewport =
                positionedRect.left < (viewport.left + viewportPad - 1) ||
                positionedRect.right > (viewport.right - viewportPad + 1) ||
                positionedRect.top < (viewport.top + viewportPad - 1) ||
                positionedRect.bottom > (viewport.bottom - viewportPad + 1);

            if (outsideViewport) {
                // Some mobile layouts can report a non-zero probe offset but still position
                // fixed elements in the viewport coordinate space; fall back to raw viewport coords,
                // then nudge one more time based on the measured viewport rect.
                posLeft = left;
                posTop = top;
                applyMenuPosition(posLeft, posTop);
                positionedRect = menu.getBoundingClientRect();
                nudge = nudgeIntoViewport(positionedRect);
                if (Math.abs(nudge.x) > 0.5 || Math.abs(nudge.y) > 0.5) {
                    applyMenuPosition(posLeft + nudge.x, posTop + nudge.y);
                }
            }

            wrap.classList.add('open');
        }
        document.addEventListener('click', function(event) {
            const target = event.target;
            if (target && typeof target.closest === 'function' && target.closest('.actions-more')) {
                return;
            }
            _closeAllActionMenus(null);
        });
        // Also close on scroll so the menu doesn't drift away from its button
        document.addEventListener('scroll', function(event) {
            const target = event.target;
            if (target && typeof target.closest === 'function' && (target.closest('.actions-more-menu') || target.closest('.actions-more'))) {
                return;
            }
            _closeAllActionMenus(null);
        }, true);

        window.addEventListener('resize', function() {
            _closeAllActionMenus(null);
        });

        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', function() {
                _closeAllActionMenus(null);
            });
            window.visualViewport.addEventListener('scroll', function() {
                _closeAllActionMenus(null);
            });
        }

        // Bind data-action="open-modify"
        document.addEventListener('click', function(event) {
            const btn = event.target.closest('[data-action="open-modify"]');
            if (!btn) return;
            event.preventDefault();
            event.stopPropagation();
            openModifyBookingModal(btn.dataset.bookingId, btn.dataset.bookingRef);
        });

        // ── Booking-list quotation modal ──────────────────────────────────────────
        var _blqBookingId = 0;

        function openBookingListQuoteModal(bookingId, ref, guestName, guestEmail) {
            _blqBookingId = bookingId;
            var sumEl = document.getElementById('bl-quotation-summary');
            sumEl.innerHTML = '<strong>' + _blqEsc(guestName) + '</strong>' +
                ' &mdash; <span style="color:#555;">' + _blqEsc(guestEmail) + '</span>' +
                '<br><small style="color:#999;">Ref: ' + _blqEsc(ref) + '</small>';
            document.getElementById('bl-quotation-valid-days').value = '7';
            document.getElementById('bl-quotation-notes').value = '';
            var fb = document.getElementById('bl-quotation-feedback');
            fb.style.display = 'none';
            fb.innerHTML = '';
            var btn = document.getElementById('bl-quotation-send-btn');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Quotation';
            document.getElementById('bl-quotation-modal').style.display = 'flex';
        }

        function closeBookingListQuoteModal() {
            document.getElementById('bl-quotation-modal').style.display = 'none';
            _blqBookingId = 0;
        }

        function _blqEsc(str) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(String(str)));
            return d.innerHTML;
        }

        function sendBookingListQuotation() {
            if (!_blqBookingId) {
                return;
            }
            var btn = document.getElementById('bl-quotation-send-btn');
            var fb = document.getElementById('bl-quotation-feedback');
            var validDays = parseInt(document.getElementById('bl-quotation-valid-days').value, 10);
            var notes = document.getElementById('bl-quotation-notes').value.trim();
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            var csrf = window._rhCsrf || (csrfMeta ? csrfMeta.getAttribute('content') : '');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending\u2026';
            fb.style.display = 'none';
            fetch('api/send-quotation.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        csrf: csrf,
                        booking_id: _blqBookingId,
                        valid_days: validDays,
                        quotation_notes: notes
                    })
                })
                .then(async function(res) {
                    var contentType = (res.headers.get('content-type') || '').toLowerCase();
                    if (!contentType.includes('application/json')) {
                        throw new Error('We could not confirm the quotation send result. Please sign in again and retry.');
                    }
                    var data = await res.json();
                    if (!res.ok || !data.success) {
                        throw new Error(data.error || data.message || 'We could not send the quotation right now.');
                    }
                    return data;
                })
                .then(function(data) {
                    var successMessage = data.message || 'Quotation email sent successfully.';
                    fb.style.cssText = 'display:block;background:#D4EDDA;color:#155724;padding:12px 14px;border-radius:4px;font-size:14px;';
                    fb.innerHTML = '<i class="fas fa-check-circle"></i> ' + _blqEsc(successMessage);
                    showBookingActionMessage(successMessage, 'success');
                    btn.innerHTML = '<i class="fas fa-check"></i> Sent';
                    setTimeout(function() {
                        closeBookingListQuoteModal();
                    }, 1800);
                })
                .catch(function(err) {
                    var friendlyMessage = (err && err.message) ? err.message : 'Network error. Please try again.';
                    fb.style.cssText = 'display:block;background:#F8D7DA;color:#721C24;padding:12px 14px;border-radius:4px;font-size:14px;';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + _blqEsc(friendlyMessage);
                    showBookingActionMessage(friendlyMessage, 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Retry';
                });
        }

        function openBookingListQuotationWhatsApp(ref, guestName, guestPhone) {
            var phone = String(guestPhone || '').replace(/[^0-9]/g, '');
            if (!phone) {
                return;
            }

            var message = [
                'Hello ' + String(guestName || '').trim() + ',',
                'your quotation for booking reference ' + String(ref || '').trim() + ' is ready.',
                'Please let us know if you would like any changes or if you are ready to confirm.'
            ].join('\n\n');

            var waUrl = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(message);
            var waWindow = window.open(waUrl, '_blank', 'noopener,noreferrer');
            if (waWindow) {
                showBookingActionMessage('Opening WhatsApp with the quotation draft message.', 'success');
            } else {
                showBookingActionMessage('WhatsApp window was blocked by your browser. Please allow pop-ups and try again.', 'warning');
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            var _blModal = document.getElementById('bl-quotation-modal');
            if (_blModal) {
                _blModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeBookingListQuoteModal();
                    }
                });
            }
        });
    </script>

    <!-- Quotation Modal (Bookings List) -->
    <div id="bl-quotation-modal" class="admin-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="bl-quotation-modal-title">
        <div class="admin-modal admin-modal--md">
            <div class="admin-modal__header">
                <h3 id="bl-quotation-modal-title" class="admin-modal__title">
                    <i class="fas fa-file-invoice-dollar"></i> Send Quotation
                </h3>
                <button type="button" class="admin-modal__close" onclick="closeBookingListQuoteModal()" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal__body">
                <div id="bl-quotation-summary" style="background:#FAF6F0;border-radius:6px;padding:14px 16px;margin-bottom:18px;font-size:14px;"></div>
                <div class="form-group">
                    <label for="bl-quotation-valid-days" style="font-size:14px;font-weight:500;color:#333;">Quotation valid for</label>
                    <select id="bl-quotation-valid-days" class="form-control" style="width:100%;padding:9px 12px;border:1px solid #DDD;border-radius:4px;font-size:14px;margin-top:6px;">
                        <option value="1">1 day</option>
                        <option value="2">2 days</option>
                        <option value="3">3 days</option>
                        <option value="5">5 days</option>
                        <option value="7" selected>7 days</option>
                        <option value="14">14 days</option>
                        <option value="21">21 days</option>
                        <option value="30">30 days</option>
                    </select>
                </div>
                <div class="form-group" style="margin-top:14px;">
                    <label for="bl-quotation-notes" style="font-size:14px;font-weight:500;color:#333;">Note to guest <span style="color:#999;font-weight:400;">(optional)</span></label>
                    <textarea id="bl-quotation-notes" class="form-control" rows="3" placeholder="e.g. Rates include complimentary breakfast." style="width:100%;padding:9px 12px;border:1px solid #DDD;border-radius:4px;font-size:14px;margin-top:6px;resize:vertical;box-sizing:border-box;"></textarea>
                </div>
                <div id="bl-quotation-feedback" style="display:none;margin-top:14px;"></div>
            </div>
            <div class="admin-modal__footer">
                <button type="button" class="btn btn-secondary" onclick="closeBookingListQuoteModal()">Cancel</button>
                <button type="button" id="bl-quotation-send-btn" class="btn" style="background:#2F4F78;color:#fff;border-color:#2F4F78;" onclick="sendBookingListQuotation()">
                    <i class="fas fa-paper-plane"></i> Send Quotation
                </button>
            </div>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>

