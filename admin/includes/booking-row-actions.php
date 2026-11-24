<?php
/**
 * One booking's front-desk actions, shared by the single buttons on All Bookings (admin/bookings.php) and the bulk bar
 * (admin/includes/bookings-bulk.php, G5b). Moved here unchanged from the bookings.php handlers so both run the SAME
 * code and rules (owner 2026-11-18: "never a new shortcut"):
 *   bookings_convert_tentative_booking()  <- convert_tentative
 *   bookings_confirm_hold()               <- update_status pending / tentative -> confirmed
 *   bookings_assign_individual_room()     <- assign_individual_room
 *   bookings_assign_room_pair()           <- assign_room_combination
 *   bookings_checkin_gates() + bookings_checkin_commit() <- update_status checked-in (the "already stayed and left"
 *                                            path stays in bookings.php: the bulk bar never takes it)
 *   bookings_cancel_commit()              <- update_status cancelled (one room or the whole group + guest email, G5c)
 * Each joins a caller's open transaction instead of opening its own (the test hotel's tests run inside one).
 */

require_once __DIR__ . '/../../includes/booking-timeline.php';
require_once __DIR__ . '/../../includes/late-stay.php';
require_once __DIR__ . '/../../includes/system-logger.php';
require_once __DIR__ . '/../../includes/cancel-reason.php';
require_once __DIR__ . '/audit-functions.php';

if (!function_exists('bookings_convert_tentative_booking')) {

    /**
     * Tentative -> confirmed (the Confirm button of a tentative booking). Throws when the booking is not tentative or
     * was converted a moment ago. Sends the conversion email; a paid booking with no room gets one automatically.
     * @return array{message:string,email_ok:bool,email_error:string,auto_note:string} message = the single action's (HTML).
     */
    function bookings_convert_tentative_booking(PDO $pdo, int $booking_id, array $user): array
    {
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

        // Convert exactly once: the status condition in the UPDATE decides the winner, so a
        // double click / second tab can neither double-decrement stock nor send two emails.
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $update_stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', is_tentative = 0, tentative_expires_at = NULL, converted_to_confirmed_at = NOW(), converted_from_tentative = 1 WHERE id = ? AND status = 'tentative' AND is_tentative = 1");
            $update_stmt->execute([$booking_id]);
            if ($update_stmt->rowCount() !== 1) {
                throw new Exception('This booking was already converted or changed.');
            }

            // Decrement room availability — tentative bookings don't consume rooms_available,
            // but confirmed bookings do; apply the same decrement as pending→confirmed.
            $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")
                ->execute([$booking['room_id']]);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (Throwable $convEx) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack(); // never leave the next booking of a bulk run inside a broken transaction
            }
            throw $convEx;
        }

        $autoAssignMessage = '';
        $autoNote = '';
        if (in_array($booking['payment_status'] ?? '', ['paid', 'completed'], true) && empty($booking['individual_room_id'])) {
            $autoAssignResult = autoAssignConfirmedPaidBooking($booking_id);
            if ($autoAssignResult['success'] && !empty($autoAssignResult['assigned_room_number'])) {
                $autoAssignMessage = ' Room ' . htmlspecialchars($autoAssignResult['assigned_room_number']) . ' auto-assigned.';
                $autoNote = 'Room ' . $autoAssignResult['assigned_room_number'] . ' given automatically.';
            } elseif (!$autoAssignResult['success']) {
                $autoAssignMessage = ' (Room auto-assignment skipped: ' . htmlspecialchars($autoAssignResult['message']) . ')';
                $autoNote = 'No room given automatically (' . $autoAssignResult['message'] . ').';
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
        require_once __DIR__ . '/../../config/email.php';
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

        return ['message' => $message, 'email_ok' => !empty($email_result['success']), 'email_error' => (string)($email_result['message'] ?? ''), 'auto_note' => $autoNote];
    }

    /**
     * Pending / tentative -> confirmed through Status (update_status): one transaction that locks the booking row and
     * every room type sharing its rooms, re-checks availability, then changes the status and the room counter.
     * Then the booking confirmed email and, for a paid booking with no room, the automatic room. Throws on refusal.
     * $current: the booking row the caller already read (status, payment_status, room_id, individual_room_id,
     * booking_reference, check_in_date, check_out_date) or null to read it here.
     * @return array{message:string,email_ok:bool,email_error:string,auto_note:string}
     */
    function bookings_confirm_hold(PDO $pdo, int $booking_id, array $user, ?array $current_booking = null): array
    {
        if ($current_booking === null) {
            $check_stmt = $pdo->prepare("SELECT status, payment_status, room_id, individual_room_id, booking_reference, check_in_date, check_out_date FROM bookings WHERE id = ?");
            $check_stmt->execute([$booking_id]);
            $current_booking = $check_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$current_booking) {
            throw new Exception('Booking not found');
        }
        $current_status = (string)$current_booking['status'];
        $transitionValidation = validateBookingStatusTransition($current_status, 'confirmed');
        if (!$transitionValidation['allowed']) {
            throw new Exception($transitionValidation['reason']);
        }
        if (!in_array($current_status, ['pending', 'tentative'], true)) {
            throw new Exception('Only a pending or tentative booking can be confirmed (current: ' . $current_status . ').');
        }
        $room_id = $current_booking['room_id'];

        // Confirming a hold takes a room out of stock: one transaction that locks the booking row and
        // every room type sharing its rooms (read BEFORE the transaction, locked in ascending id),
        // re-checks availability, and only then changes the status and the room counter. Two people
        // confirming at once, or confirming while someone books, can never both claim the last room.
        $confirmLockIds = rh_related_room_type_ids($pdo, [(int)$room_id]);
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $lockBk = $pdo->prepare("SELECT status FROM bookings WHERE id = ? FOR UPDATE");
            $lockBk->execute([$booking_id]);
            $lockedStatus = $lockBk->fetchColumn();
            if (!in_array($lockedStatus, ['pending', 'tentative'], true)) {
                throw new Exception('This booking was changed by someone else a moment ago. Refresh the page and check its status.');
            }
            rh_lock_room_types($pdo, $confirmLockIds);
            $availabilityCheck = checkRoomAvailability($room_id, $current_booking['check_in_date'], $current_booking['check_out_date'], $booking_id);
            if (!$availabilityCheck['available']) {
                throw new Exception(getBookingActionErrorMessage('confirm', $availabilityCheck['error'] ?? 'No rooms available'));
            }
            $pdo->prepare("UPDATE bookings SET status = ?, is_tentative = 0, tentative_expires_at = NULL WHERE id = ?")->execute(['confirmed', $booking_id]);
            $update_room = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0");
            $update_room->execute([$room_id]);
            $confirmRoomUpdated = $update_room->rowCount() > 0;
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (Throwable $confirmEx) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw rh_is_lock_conflict($confirmEx) ? new Exception(rh_lock_conflict_message()) : $confirmEx;
        }
        $message = 'Booking status updated!';
        rh_log_event('bookings', 'info', 'Booking status changed', ['booking_id' => $booking_id, 'from' => $current_status, 'to' => 'confirmed', 'by' => $user['username'] ?? null]);
        logBookingAudit($booking_id, 'confirmed', ['status' => $current_status], ['status' => 'confirmed'], null, $current_booking['booking_reference'] ?? null);

        // (Availability re-check, status change and room counter were done in the locked transaction above.)
        if (!$confirmRoomUpdated) {
            // This shouldn't happen if availability checks are working, but handle it
            $message .= ' (Warning: Could not update room availability - room may be fully booked)';
        } else {
            $message .= ' Room availability updated.';
        }

        $emailOk = false;
        $emailError = '';
        $autoNote = '';
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
            require_once __DIR__ . '/../../config/email.php';

            // Send booking confirmed email
            $email_result = sendBookingConfirmedEmail($booking);
            $emailOk = !empty($email_result['success']);
            $emailError = (string)($email_result['message'] ?? '');

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
                    $autoNote = 'Room ' . $autoAssignResult['assigned_room_number'] . ' given automatically.';
                } else {
                    $message .= ' (Note: Auto-assign skipped - ' . htmlspecialchars($autoAssignResult['message']) . ')';
                    $autoNote = 'No room given automatically (' . $autoAssignResult['message'] . ').';
                }
            }
        }
        return ['message' => $message, 'email_ok' => $emailOk, 'email_error' => $emailError, 'auto_note' => $autoNote];
    }

    /**
     * Assign one physical room (the Assign room picker). A joined-room booking takes the whole pair the chosen room
     * belongs to. Same gates as before: confirmed / in-house only, room of the booking's type, children policy (override
     * needs a note), out-of-service / not-clean-for-a-move-in block, then the locked assign that re-checks the dates.
     * Never changes money.
     * @return array{success:bool,message:string}
     */
    function bookings_assign_individual_room(PDO $pdo, int $booking_id, int $individual_room_id, array $user, bool $allowChildPolicyOverride = false, string $childPolicyOverrideNote = ''): array
    {
        if ($booking_id <= 0 || $individual_room_id <= 0) {
            return ['success' => false, 'message' => 'Invalid booking or room selection'];
        }

        $bkStmt = $pdo->prepare("SELECT id, status, room_id, individual_room_id, child_guests, booking_reference, check_in_date FROM bookings WHERE id = ? AND deleted_at IS NULL");
        $bkStmt->execute([$booking_id]);
        $bookingToAssign = $bkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$bookingToAssign) {
            return ['success' => false, 'message' => 'Booking not found'];
        }

        // Use centralized validation for room assignment
        $validation = validateRoomAssignment($bookingToAssign);
        if (!$validation['allowed']) {
            return ['success' => false, 'message' => getBookingActionErrorMessage('assign_room', $validation['reason'])];
        }

        if (roomTypeHasActiveCombinations((int)$bookingToAssign['room_id'])) {
            // A joined-room booking takes the whole pair the chosen room belongs to
            // (e.g. "Assign" on one member room in Individual Rooms).
            $pairSt = $pdo->prepare("SELECT id FROM room_combinations WHERE is_active = 1 AND combined_room_type_id = ? AND (room_a_id = ? OR room_b_id = ?) ORDER BY id LIMIT 1");
            $pairSt->execute([(int)$bookingToAssign['room_id'], $individual_room_id, $individual_room_id]);
            $pairId = (int)$pairSt->fetchColumn();
            if ($pairId <= 0) {
                return ['success' => false, 'message' => 'This is a joined-room booking and that room is not part of one of its pairs. Assign it a pair from Bookings.'];
            }
            $comboResult = assignRoomCombinationToBooking($booking_id, $pairId, isset($user['id']) ? (int)$user['id'] : null);
            if (empty($comboResult['success'])) {
                return ['success' => false, 'message' => 'Those joined rooms could not be assigned: ' . ($comboResult['message'] ?: 'they are unavailable for the booking dates') . '.'];
            }
            logBookingAudit($booking_id, 'room_assigned', ['individual_room_id' => $bookingToAssign['individual_room_id'] ?? null],
                ['individual_room_id' => $comboResult['assigned_room_id'], 'room_combination_id' => $pairId],
                'Joined rooms ' . $comboResult['assigned_room_number'] . ' assigned manually by admin', $bookingToAssign['booking_reference'] ?? null);
            return ['success' => true, 'message' => (string)$comboResult['message']];
        }

        $policyInfo = getIndividualRoomEffectivePolicy((int)$bookingToAssign['room_id'], $individual_room_id);
        if (!$policyInfo) {
            return ['success' => false, 'message' => 'Selected room does not match this booking room type'];
        }

        if ((int)($bookingToAssign['child_guests'] ?? 0) > 0 && empty($policyInfo['policy']['children_allowed'])) {
            if (!$allowChildPolicyOverride || $childPolicyOverrideNote === '') {
                return ['success' => false, 'message' => 'This booking includes children. Assigning a room that does not accept children requires an override note.'];
            }
        }

        // Room-status gate with a clear, actionable reason (out of service; or not clean
        // when an in-house guest would move in now). A reservation may take a room that is
        // still being turned over: check-in is what waits for housekeeping.
        $hkBlock = getRoomHousekeepingAssignmentBlock($individual_room_id, (string)$bookingToAssign['status'] === 'checked-in');
        if ($hkBlock && !empty($hkBlock['blocked'])) {
            return ['success' => false, 'message' => (string)$hkBlock['message']];
        }

        $assigned = assignIndividualRoomToBooking($booking_id, $individual_room_id, $allowChildPolicyOverride, $childPolicyOverrideNote, $user['id'] ?? null);

        if (!$assigned) {
            $assignWhy = rh_room_assign_last_error();
            if (strpos($assignWhy, 'Not saved: ') === 0) {
                return ['success' => false, 'message' => $assignWhy]; // already names the room, night(s) and what is in the way
            }
            return ['success' => false, 'message' => 'Selected room could not be assigned' . ($assignWhy !== '' ? ': ' . $assignWhy : ' - it is unavailable for the booking dates (it may overlap another reservation, a block, a maintenance window or a housekeeping task).') . ' Check the room timeline and try again.'];
        }

        // Note: assignIndividualRoomToBooking() locks the booking + room, re-validates, and keeps the
        // room's status as is (it becomes 'occupied' at check-in).
        logBookingAudit(
            $booking_id,
            'room_assigned',
            ['individual_room_id' => $bookingToAssign['individual_room_id'] ?? null],
            ['individual_room_id' => $individual_room_id],
            $allowChildPolicyOverride ? 'Child policy override: ' . $childPolicyOverrideNote : 'Room assigned manually by admin',
            $bookingToAssign['booking_reference'] ?? null
        );

        return ['success' => true, 'message' => 'Room assigned successfully'];
    }

    /**
     * Joined-room booking: assign a whole pair (room_combinations row), the Assign room picker's pair choice.
     * @return array{success:bool,message:string}
     */
    function bookings_assign_room_pair(PDO $pdo, int $booking_id, int $combination_id, array $user): array
    {
        if ($booking_id <= 0 || $combination_id <= 0) {
            return ['success' => false, 'message' => 'Invalid booking or room pair selection'];
        }
        $bkStmt = $pdo->prepare("SELECT id, status, booking_reference, individual_room_id, room_combination_id FROM bookings WHERE id = ? AND deleted_at IS NULL");
        $bkStmt->execute([$booking_id]);
        $bookingToAssign = $bkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$bookingToAssign) {
            return ['success' => false, 'message' => 'Booking not found'];
        }
        $validation = validateRoomAssignment($bookingToAssign);
        if (!$validation['allowed']) {
            return ['success' => false, 'message' => getBookingActionErrorMessage('assign_room', $validation['reason'])];
        }
        $comboResult = assignRoomCombinationToBooking($booking_id, $combination_id, isset($user['id']) ? (int)$user['id'] : null);
        if (empty($comboResult['success'])) {
            return ['success' => false, 'message' => 'Those joined rooms could not be assigned: ' . ($comboResult['message'] ?: 'they are unavailable for the booking dates') . '.'];
        }
        logBookingAudit(
            $booking_id,
            'room_assigned',
            ['individual_room_id' => $bookingToAssign['individual_room_id'] ?? null, 'room_combination_id' => $bookingToAssign['room_combination_id'] ?? null],
            ['individual_room_id' => $comboResult['assigned_room_id'], 'room_combination_id' => $combination_id],
            'Joined rooms ' . $comboResult['assigned_room_number'] . ' assigned manually by admin',
            $bookingToAssign['booking_reference'] ?? null
        );
        return ['success' => true, 'message' => (string)$comboResult['message']];
    }

    /**
     * Check-in gates, in the single action's order: confirmed + payment + date (validateCheckIn), the late-arrival
     * choice ($in['late_arrival']), a free joined pair for a joined booking with no rooms (writes: only call it when
     * about to check in), then the clean-room gate ($in['confirm_checkin_room_not_ready'] = the override confirm).
     * Throws on the first three. The clean-room gate is returned for the caller to answer (room_gate.allowed false).
     * @return array{booking:array,validation:array,window:array,choice:array,room_gate:array}
     */
    function bookings_checkin_gates(PDO $pdo, int $booking_id, array $user, array $in): array
    {
        // Use centralized validation
        $check = $pdo->prepare("SELECT id, status, payment_status, individual_room_id, booking_reference, check_in_date, check_out_date" . rh_checkin_rule_select() . " FROM bookings WHERE id = ?");
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

        // Late arrival (owner 2026-11-12 "Dont assume, have options"): front desk says what happened -
        // arrived on the booked date, arriving today, or (stay over) already stayed and left. Re-checked here.
        $ciWindow = rh_late_stay_window($booking);
        $ciChoice = rh_late_checkin_choice($ciWindow, $in['late_arrival'] ?? null);
        if (!$ciChoice['ok']) {
            throw new Exception($ciChoice['error']);
        }

        // A joined-room booking with no rooms yet is given a free pair now (or the check-in is refused). Not for
        // "already stayed and left": nobody moves in.
        if ($ciChoice['choice'] !== 'ended') {
            $joinedPair = rh_checkin_ensure_joined_pair((int)$booking_id);
            if (!$joinedPair['ok']) {
                throw new Exception($joinedPair['message']);
            }
        }

        // The clean-room gate protects a guest moving in. "Already stayed and left" (owner 2026-11-13): nobody moves in,
        // so no gate (said in History); a room another in-house guest holds is never touched by that path.
        $roomGate = $ciChoice['choice'] === 'ended'
            ? ['allowed' => true, 'override' => false, 'needs_confirm' => false, 'message' => '', 'rooms' => []]
            : evaluateCheckInRoomReady($booking_id, (int)($user['id'] ?? 0), !empty($in['confirm_checkin_room_not_ready']));

        return ['booking' => $booking, 'validation' => $validation, 'window' => $ciWindow, 'choice' => $ciChoice, 'room_gate' => $roomGate];
    }

    /**
     * The check-in itself, after bookings_checkin_gates() passed with room_gate.allowed and a choice other than
     * 'ended': status checked-in + rooms occupied (one transaction), timeline, late-arrival note, History, and the
     * check-in email only when $in has 'email_guest' (no field = no email, as before).
     * @return array{message:string}
     */
    function bookings_checkin_commit(PDO $pdo, array $g, array $user, array $in): array
    {
        $booking = $g['booking'];
        $booking_id = (int)$booking['id'];
        $validation = $g['validation'];
        $ciWindow = $g['window'];
        $ciChoice = $g['choice'];
        $roomGate = $g['room_gate'];
        $checkin_note = trim((string)($in['checkin_note'] ?? ''));

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'checked-in' WHERE id = ?");
            $stmt->execute([$booking_id]);

            updateBookingRoomsStatus(
                $booking_id,
                'occupied',
                'Guest checked in: ' . ($booking['booking_reference'] ?? ('Booking #' . $booking_id)),
                $user['id'] ?? null
            );
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (Throwable $ciEx) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack(); // never leave the next booking of a bulk run inside a broken transaction
            }
            throw $ciEx;
        }

        // Timeline event (outside transaction — non-fatal if it fails)
        logBookingCheckIn($booking_id, $booking['booking_reference'] ?? '', 'admin', $user['id'] ?? null, $user['full_name'] ?? null);
        if ($roomGate['override']) {
            logCheckInRoomNotReadyOverride($booking_id, (string)($booking['booking_reference'] ?? ''), $roomGate['rooms'], $user['id'] ?? null, $user['full_name'] ?? null);
        }
        if (!empty($validation['via_arrangement'])) {
            logCheckInUnderArrangement($booking_id, (string)($booking['booking_reference'] ?? ''), (string)$validation['arrangement_note'], $user['id'] ?? null, $user['full_name'] ?? null);
        }

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
        $ciActualArrival = $ciChoice['choice'] !== '' ? rh_late_checkin_arrival($ciWindow, $ciChoice['choice']) : null;
        if ($ciChoice['choice'] !== '') {
            $checkInEventContext['arrival_choice'] = $ciChoice['choice'];
            $checkInEventContext['actual_arrival'] = $ciActualArrival;
        }
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
            // The chosen option and the actual arrival date go in the note (History) and in new_values
            // (actual_arrival is what the checkout settlement reads back for "nights actually stayed").
            $lateDescriptor = $ciChoice['choice'] !== ''
                ? rh_late_checkin_note($ciWindow, $ciChoice['choice'])
                : 'Late check-in (' . $lateDays . ' day(s) overdue)';
            $checkInAuditNote = $checkInAuditNote !== null
                ? ($lateDescriptor . ' - ' . $checkInAuditNote)
                : $lateDescriptor;
            if ($ciChoice['choice'] !== '') {
                logBookingEvent((int)$booking_id, (string)($booking['booking_reference'] ?? ''), 'Late check-in', 'check_in', $lateDescriptor,
                    (string)$booking['check_in_date'], (string)$ciActualArrival, 'admin', $user['id'] ?? null, $user['full_name'] ?? null,
                    ['arrival_choice' => $ciChoice['choice'], 'actual_arrival' => $ciActualArrival, 'late_days' => $lateDays]);
            }
        }
        $ciAuditNew = ['status' => 'checked-in'];
        if ($ciChoice['choice'] !== '') {
            $ciAuditNew['arrival_choice'] = $ciChoice['choice'];
            $ciAuditNew['actual_arrival'] = $ciActualArrival;
        }
        logBookingAudit(
            $booking_id,
            'checked-in',
            ['status' => 'confirmed'],
            $ciAuditNew,
            $checkInAuditNote,
            $booking['booking_reference'] ?? null
        );

        // "Email the guest a check-in confirmation" box (owner 2026-11-12). No field (old cached page, the
        // Assistant's check-in, the bulk bar) = no email. Never blocks the check-in.
        if (array_key_exists('email_guest', $in)) {
            require_once __DIR__ . '/../../config/email.php';
            $ciEmailWanted = rh_checkin_email_choice($in, false);
            $ciEmailSent = rh_checkin_guest_email($pdo, (int)$booking_id, $ciEmailWanted, isset($user['id']) ? (int)$user['id'] : null, $user['full_name'] ?? null);
            $message .= $ciEmailWanted ? ($ciEmailSent ? ' Check-in email sent.' : ' The check-in email could not be sent.') : ' No email sent (you chose not to).';
        }
        return ['message' => $message];
    }

    /**
     * Cancel one booking, or its whole group when $in['scope'] is 'group' and it has 2+ live rooms (all go or none):
     * the cancel branch + guest email loop of update_status -> cancelled, moved here unchanged and shared with the bulk
     * Cancel (G5c). Money stays inside cancelRoomBookingSettled() / rh_cancel_booking_group() (policy fee from the
     * locked snapshot, quoted figure re-checked under the row lock). The status transition is checked by the caller.
     * $in: the posted fields (cancellation_reason, scope, fee_quoted, fee_override, fee_override_pct, fee_override_reason,
     * email_guest). Throws "Cancellation failed - nothing was changed. ..." on a refusal.
     * @return array{message:string,cancelled:array,email:array} cancelled: [{id,previous_status,reference,summary}];
     *   email: [bookingId => 'sent' | 'not sent' | 'failed: ...'] for each emailed (previously confirmed) room.
     */
    function bookings_cancel_commit(PDO $pdo, int $booking_id, string $current_status, ?string $booking_reference, array $user, array $in): array
    {
        $new_status = 'cancelled';
        $cancelledNow = []; // bookings cancelled by this request: [id, previous_status]
        $done = [];
        // Staff must say why (owner 2026-11-24): checked before anything changes; a page loaded before this change
        // (still posting "Cancelled by admin") is refused too.
        $reasonCheck = rh_cancel_reason_check($in['cancellation_reason'] ?? '');
        if (!$reasonCheck['ok']) {
            throw new Exception($reasonCheck['error']);
        }
        $cancellation_reason = $reasonCheck['reason'];
        $reasonLine = rh_cancel_reason_history_line($cancellation_reason);
        $actorId = isset($user['id']) ? (int)$user['id'] : null;
        $actorName = $user['full_name'] ?? ($user['username'] ?? null);
        // Cancellation: status change + room/stock release + bill treatment +
        // refund all commit together (one transaction, booking row locked).
        // Group booking (owner 2026-10-20): the dialog asks each time. scope=group cancels every live
        // room of the group in ONE transaction (all go or none); scope=single (or none) is one room.
        $cancelGroupIds = ($in['scope'] ?? '') === 'group' ? rh_booking_group_ids($pdo, (int)$booking_id) : [];
        if (count($cancelGroupIds) > 1) {
            // Cancellation policy: the fee the dialog showed (none posted for bookings without a policy).
            $grpRes = rh_cancel_booking_group($pdo, $cancelGroupIds, (int)($user['id'] ?? 0), (string)$cancellation_reason, 'completed', rh_cancel_fee_choice_from_post($in, true));
            if (!$grpRes['success']) {
                throw new Exception('Cancellation failed - nothing was changed. ' . $grpRes['error']);
            }
            $message = $grpRes['message'];
            foreach ($grpRes['cancelled'] as $gc) {
                $cancelledNow[] = ['id' => $gc['id'], 'previous_status' => $gc['previous_status']];
                $done[] = ['id' => (int)$gc['id'], 'previous_status' => (string)$gc['previous_status'], 'reference' => (string)$gc['reference'], 'summary' => (string)$gc['summary']];
                rh_log_event('bookings', 'info', 'Booking status changed (group cancel)', ['booking_id' => $gc['id'], 'ref' => $gc['reference'], 'to' => $new_status, 'group' => $cancelGroupIds, 'reason' => $cancellation_reason, 'by' => $user['username'] ?? null]);
                // History: the status step with the reason (as Booking details writes it); the audit note's lines match it.
                logBookingStatusChange((int)$gc['id'], (string)$gc['reference'], (string)$gc['previous_status'], $new_status, 'admin', $actorId, $actorName, $cancellation_reason);
                logBookingAudit($gc['id'], $new_status, ['status' => $gc['previous_status']], ['status' => $new_status], 'Cancelled with its group (' . count($cancelGroupIds) . " rooms)\n" . $reasonLine, $gc['reference']);
            }
        } else {
            $cancelRes = cancelRoomBookingSettled($pdo, (int)$booking_id, (int)($user['id'] ?? 0), (string)$cancellation_reason, 'completed', rh_cancel_fee_choice_from_post($in, false));
            if (!$cancelRes['success']) {
                throw new Exception('Cancellation failed - nothing was changed. ' . $cancelRes['error']);
            }
            $cancelledNow[] = ['id' => (int)$booking_id, 'previous_status' => (string)$current_status];
            $done[] = ['id' => (int)$booking_id, 'previous_status' => (string)$current_status, 'reference' => (string)($cancelRes['booking_reference'] ?? ''), 'summary' => (string)$cancelRes['summary']];
            $message = 'Booking cancelled. ' . $cancelRes['summary'];
            rh_log_event('bookings', 'info', 'Booking status changed', ['booking_id' => $booking_id, 'from' => $current_status, 'to' => $new_status, 'mode' => $cancelRes['mode'], 'refund' => $cancelRes['refund_total'], 'reason' => $cancellation_reason, 'by' => $user['username'] ?? null]);
            logBookingStatusChange((int)$booking_id, (string)($booking_reference ?: ($cancelRes['booking_reference'] ?? '')), (string)$current_status, $new_status, 'admin', $actorId, $actorName, $cancellation_reason);
            logBookingAudit($booking_id, $new_status, ['status' => $current_status], ['status' => $new_status], $reasonLine, $booking_reference);
        }

        $emailOut = [];
        if (!empty($cancelledNow)) {
            // (Stock release, room release and refund were done atomically by cancelRoomBookingSettled above.)
            // Every booking cancelled by this request that was confirmed gets the guest email + the log rows.
            require_once __DIR__ . '/../../config/email.php';
            foreach ($cancelledNow as $cn) {
                if ($cn['previous_status'] !== 'confirmed') {
                    continue;
                }
                $booking_stmt = $pdo->prepare("
                    SELECT b.*, r.name as room_name
                    FROM bookings b
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE b.id = ?
                ");
                $booking_stmt->execute([$cn['id']]);
                $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$booking) {
                    continue;
                }
                // Owner 2026-11-12: staff may untick "Email the guest" to cancel quietly (no email, no WhatsApp).
                // A form without the field (page loaded before this change) keeps the old behaviour.
                $cancelEmailSkipped = !rh_cancel_email_choice($in, rh_cancel_email_default_ticked('list', (string)$cn['previous_status']));
                if ($cancelEmailSkipped) {
                    $email_result = ['success' => false, 'message' => rh_cancel_email_skipped_reason()];
                    rh_email_send_log_not_sent('booking_cancelled', (string)$booking['guest_email'], (string)$booking['guest_name'],
                        rh_email_ctx_account('room', $booking['id'], $booking['booking_reference']), 'Staff chose not to email the guest');
                    if (rh_cancel_email_default_ticked('list', (string)$cn['previous_status'])) {
                        sendBookingCancelledHotelAlert($booking); // unticked: nothing to the guest; the hotel's own WhatsApp alert still goes
                    }
                } else {
                    // Send cancellation email
                    $email_result = sendBookingCancelledEmail($booking, rh_cancel_guest_email_reason()); // the reason stays internal (owner 2026-11-25)
                }

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

                $refTag = count($cancelledNow) > 1 ? (' [' . $booking['booking_reference'] . ']') : '';
                if ($cancelEmailSkipped) {
                    $message .= ' No email sent' . $refTag . ' (you chose not to).';
                    $emailOut[(int)$cn['id']] = 'not sent';
                } elseif ($email_sent) {
                    $message .= ' Cancellation email sent' . $refTag . '.';
                    $emailOut[(int)$cn['id']] = 'sent';
                } else {
                    $message .= ' (Email failed' . $refTag . ': ' . $email_status . ')';
                    $emailOut[(int)$cn['id']] = 'failed: ' . $email_status;
                }
            }
        }
        return ['message' => $message, 'cancelled' => $done, 'email' => $emailOut];
    }
}
