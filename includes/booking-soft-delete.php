<?php
/**
 * Recoverable booking deletion (bookings.deleted_at / deleted_by / deleted_reason, migration 060).
 *
 * Real-hotel rules:
 *  - A deleted booking is kept (payments, invoices and the audit trail still point at a real row)
 *    and is listed only under the Deleted filter, with who deleted it, exactly when, and why.
 *  - An in-house (checked-in) guest cannot be deleted: check out or undo the check-in first.
 *  - A booking with money on it cannot be deleted: refund/void its payments and void its folio
 *    charges first, so the books never carry revenue against a booking nobody can see.
 *  - A deleted booking never holds a room: pending / tentative / confirmed are moved to
 *    'cancelled' (bill voided, room + stock released). The original status is kept in the audit
 *    trail and is put back on restore, after re-checking the room type is still free.
 *
 * Permission: delete_booking (administrators always; assignable to other users).
 */

require_once __DIR__ . '/../config/database.php';
if (!function_exists('logBookingEvent')) {
    require_once __DIR__ . '/booking-timeline.php';
}

/** Statuses that hold a room and are therefore released (set to cancelled) on delete. */
function rh_soft_delete_releasing_statuses(): array
{
    return ['pending', 'tentative', 'confirmed'];
}

/**
 * Why this booking cannot be deleted, or null when it can. $b is a full bookings row
 * (financials should be current: call recalculateBookingFinancials() first when it matters).
 */
function rh_booking_delete_blocker(array $b): ?string
{
    if (!empty($b['deleted_at'])) {
        return 'This booking is already deleted.';
    }
    if (($b['status'] ?? '') === 'checked-in') {
        return 'The guest is checked in. Check them out (or undo the check-in) before deleting the booking.';
    }
    $cur = (string)getSetting('currency_symbol', 'MWK');
    if ((float)($b['amount_paid'] ?? 0) > BALANCE_TOLERANCE) {
        return 'This booking has ' . $cur . ' ' . number_format((float)$b['amount_paid'], 2)
            . ' paid against it. Refund or void the payments first, so the accounts stay correct.';
    }
    if ((float)($b['folio_charges_total'] ?? 0) > BALANCE_TOLERANCE) {
        return 'This booking has folio charges posted. Void them first, so the accounts stay correct.';
    }
    return null;
}

/** Status the booking held before it was deleted (from the audit trail), or null. */
function rh_booking_status_before_delete(PDO $pdo, int $bookingId): ?string
{
    try {
        $st = $pdo->prepare("SELECT old_values FROM booking_audit_log WHERE booking_id = ? AND action = 'deleted' ORDER BY id DESC LIMIT 1");
        $st->execute([$bookingId]);
        $old = json_decode((string)$st->fetchColumn(), true);
        $s = is_array($old) ? (string)($old['status'] ?? '') : '';
        return $s !== '' ? $s : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Soft-delete a booking. Reason is required. One transaction, booking row locked.
 *
 * @return array{success:bool,message:string}
 */
function rh_soft_delete_booking(PDO $pdo, int $bookingId, int $userId, string $userName, string $reason): array
{
    $reason = trim($reason);
    if ($bookingId <= 0) {
        return ['success' => false, 'message' => 'Invalid booking.'];
    }
    if (mb_strlen($reason) < 5) {
        return ['success' => false, 'message' => 'Give a reason for deleting this booking (at least 5 characters).'];
    }
    $reason = mb_substr($reason, 0, 500);

    $ownTx = !$pdo->inTransaction();
    try {
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        $lock = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
        $lock->execute([$bookingId]);
        $b = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            throw new RuntimeException('Booking not found.');
        }
        // Money is judged on the live payments/charges, not on a possibly stale cached column.
        recalculateBookingFinancials($bookingId);
        $lock->execute([$bookingId]);
        $b = $lock->fetch(PDO::FETCH_ASSOC);

        $blocker = rh_booking_delete_blocker($b);
        if ($blocker !== null) {
            throw new RuntimeException($blocker);
        }

        $prevStatus = (string)$b['status'];
        $ref = (string)$b['booking_reference'];
        $releases = in_array($prevStatus, rh_soft_delete_releasing_statuses(), true);
        $newStatus = $releases ? 'cancelled' : $prevStatus;

        if ($releases) {
            // Same room/stock release as a cancellation, but no fee and no guest email.
            $pdo->prepare("UPDATE bookings SET status = 'cancelled', is_tentative = 0, tentative_expires_at = NULL,
                    cancellation_retained_amount = NULL WHERE id = ?")->execute([$bookingId]);
            if ($prevStatus === 'confirmed') {
                $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")
                    ->execute([$b['room_id']]);
            }
            updateBookingRoomsStatus($bookingId, 'available', 'Booking deleted: ' . $ref, $userId ?: null);
        }
        $pdo->prepare("UPDATE bookings SET deleted_at = NOW(), deleted_by = ?, deleted_reason = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$userId ?: null, $reason, $bookingId]);
        recalculateBookingFinancials($bookingId);

        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }

    // Audit trail (after commit; a logging failure must not undo the delete).
    $old = ['status' => $prevStatus, 'individual_room_id' => $b['individual_room_id'], 'deleted_at' => null];
    $new = ['status' => $newStatus, 'deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => $userId, 'deleted_reason' => $reason];
    if (function_exists('logBookingAudit')) {
        logBookingAudit($bookingId, 'deleted', $old, $new, 'Deleted by ' . $userName . ': ' . $reason, $ref);
    }
    logBookingEvent($bookingId, $ref, 'Booking deleted', 'status_change',
        'Deleted by ' . $userName . '. Reason: ' . $reason . ($releases ? ' (was ' . $prevStatus . '; room released)' : ''),
        $prevStatus, $newStatus, 'admin', $userId ?: null, $userName, ['deleted' => true, 'previous_status' => $prevStatus]);
    if (function_exists('rh_log_event')) {
        rh_log_event('bookings', 'warning', 'Booking deleted', ['booking_id' => $bookingId, 'ref' => $ref, 'previous_status' => $prevStatus, 'reason' => $reason, 'by' => $userName]);
    }

    return ['success' => true, 'message' => 'Booking ' . $ref . ' deleted. It is listed under the Deleted filter and can be restored.'];
}

/**
 * Restore a deleted booking to the status it had before deletion. When that status holds a
 * room, the room type must still be free for the stay; otherwise the booking stays deleted.
 *
 * @return array{success:bool,message:string}
 */
function rh_restore_booking(PDO $pdo, int $bookingId, int $userId, string $userName, string $note = ''): array
{
    $note = mb_substr(trim($note), 0, 500);
    $ownTx = !$pdo->inTransaction();
    $assignMsg = '';
    try {
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        $lock = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
        $lock->execute([$bookingId]);
        $b = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            throw new RuntimeException('Booking not found.');
        }
        if (empty($b['deleted_at'])) {
            throw new RuntimeException('This booking is not deleted.');
        }
        $ref = (string)$b['booking_reference'];
        $restoreStatus = rh_booking_status_before_delete($pdo, $bookingId);
        if ($restoreStatus === null) {
            throw new RuntimeException('The status this booking had before it was deleted is not in the audit log, so it cannot be restored automatically. Ask an administrator to check the booking.');
        }
        $reholds = in_array($restoreStatus, rh_soft_delete_releasing_statuses(), true) && (string)$b['status'] === 'cancelled';

        if ($reholds) {
            $pdo->prepare("SELECT id FROM rooms WHERE id = ? FOR UPDATE")->execute([$b['room_id']]);
            $av = checkRoomAvailability((int)$b['room_id'], (string)$b['check_in_date'], (string)$b['check_out_date'], $bookingId, (int)($b['child_guests'] ?? 0));
            if (empty($av['available'])) {
                throw new RuntimeException('Cannot restore as ' . $restoreStatus . ': the room type is no longer free for '
                    . $b['check_in_date'] . ' to ' . $b['check_out_date'] . ' (' . ($av['error'] ?? 'sold out') . ').'
                    . ' Rebook the guest instead, or free a room first.');
            }
            if ($restoreStatus === 'tentative') {
                // A restored hold gets a fresh hold period, otherwise it would never lapse.
                $holdHours = max(1, (int)getSetting('tentative_duration_hours', 48));
                $pdo->prepare("UPDATE bookings SET status = 'tentative', is_tentative = 1, tentative_expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = ?")
                    ->execute([$holdHours, $bookingId]);
            } else {
                $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?")->execute([$restoreStatus, $bookingId]);
            }
            if ($restoreStatus === 'confirmed') {
                $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")
                    ->execute([$b['room_id']]);
            }
        }
        $pdo->prepare("UPDATE bookings SET deleted_at = NULL, deleted_by = NULL, deleted_reason = NULL, updated_at = NOW() WHERE id = ?")
            ->execute([$bookingId]);
        recalculateBookingFinancials($bookingId);
        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }

    if ($reholds && $restoreStatus === 'confirmed' && function_exists('autoAssignIndividualRoom')) {
        try {
            $as = autoAssignIndividualRoom($bookingId);
            $assignMsg = !empty($as['success']) && !empty($as['assigned_room_number'])
                ? ' Room ' . $as['assigned_room_number'] . ' assigned.'
                : ' Assign a room before arrival.';
        } catch (Throwable $e) {
            $assignMsg = ' Assign a room before arrival.';
        }
    }

    $old = ['status' => (string)$b['status'], 'deleted_at' => $b['deleted_at'], 'deleted_by' => $b['deleted_by'], 'deleted_reason' => $b['deleted_reason']];
    $new = ['status' => $reholds ? $restoreStatus : (string)$b['status'], 'deleted_at' => null];
    $desc = 'Restored by ' . $userName . ($note !== '' ? '. Note: ' . $note : '') . '. Deleted ' . $b['deleted_at'] . ' (reason: ' . $b['deleted_reason'] . ').';
    if (function_exists('logBookingAudit')) {
        logBookingAudit($bookingId, 'restored', $old, $new, $desc, $ref);
    }
    logBookingEvent($bookingId, $ref, 'Booking restored', 'status_change', $desc, (string)$b['status'], $new['status'],
        'admin', $userId ?: null, $userName, ['restored' => true]);
    if (function_exists('rh_log_event')) {
        rh_log_event('bookings', 'info', 'Booking restored', ['booking_id' => $bookingId, 'ref' => $ref, 'status' => $new['status'], 'by' => $userName]);
    }

    return ['success' => true, 'message' => 'Booking ' . $ref . ' restored as ' . $new['status'] . '.' . $assignMsg];
}
