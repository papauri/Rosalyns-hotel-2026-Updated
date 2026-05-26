<?php
/**
 * Booking Timeline Functions
 * Tracks all booking activities from creation to completion
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Log a booking timeline event
 *
 * @param int $booking_id Booking ID
 * @param string $booking_reference Booking reference
 * @param string $action Action description (e.g., 'Booking created', 'Status changed to confirmed')
 * @param string $action_type Action type enum value
 * @param string|null $description Detailed description
 * @param string|null $old_value Previous value (for status changes)
 * @param string|null $new_value New value (for status changes)
 * @param string $performed_by_type Who performed the action
 * @param int|null $performed_by_id User ID (if admin)
 * @param string|null $performed_by_name User name
 * @param array|null $metadata Additional metadata
 * @return bool Success status
 */
function logBookingEvent(
    int $booking_id,
    string $booking_reference,
    string $action,
    string $action_type,
    ?string $description = null,
    ?string $old_value = null,
    ?string $new_value = null,
    string $performed_by_type = 'system',
    ?int $performed_by_id = null,
    ?string $performed_by_name = null,
    ?array $metadata = null
): bool {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO booking_timeline_logs (
                booking_id, booking_reference, action, action_type, description,
                old_value, new_value, performed_by_type, performed_by_id,
                performed_by_name, ip_address, user_agent, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt->execute([
            $booking_id,
            $booking_reference,
            $action,
            $action_type,
            $description,
            $old_value,
            $new_value,
            $performed_by_type,
            $performed_by_id,
            $performed_by_name,
            $ip_address,
            $user_agent,
            $metadata ? json_encode($metadata) : null
        ]);

        return true;
    } catch (PDOException $e) {
        error_log("Failed to log booking event: " . $e->getMessage());
        return false;
    }
}

/**
 * Log booking creation
 */
function logBookingCreated(array $booking, string $performed_by_type = 'guest', ?int $performed_by_id = null, ?string $performed_by_name = null): bool {
    return logBookingEvent(
        $booking['id'],
        $booking['booking_reference'],
        'Booking created',
        'create',
        "New booking created for {$booking['number_of_nights']} night(s) - Total: {$booking['total_amount']}",
        null,
        $booking['status'],
        $performed_by_type,
        $performed_by_id,
        $performed_by_name,
        [
            'room_id' => $booking['room_id'] ?? null,
            'check_in' => $booking['check_in_date'] ?? null,
            'check_out' => $booking['check_out_date'] ?? null,
            'guests' => $booking['number_of_guests'] ?? null,
            'total' => $booking['total_amount'] ?? null,
            'is_tentative' => $booking['is_tentative'] ?? false
        ]
    );
}

/**
 * Log booking status change
 */
function logBookingStatusChange(int $booking_id, string $booking_reference, string $old_status, string $new_status, string $performed_by_type = 'admin', ?int $performed_by_id = null, ?string $performed_by_name = null, ?string $reason = null): bool {
    $description = "Status changed from '{$old_status}' to '{$new_status}'";
    if ($reason) {
        $description .= " - Reason: {$reason}";
    }

    return logBookingEvent(
        $booking_id,
        $booking_reference,
        "Status changed: {$old_status} → {$new_status}",
        'status_change',
        $description,
        $old_status,
        $new_status,
        $performed_by_type,
        $performed_by_id,
        $performed_by_name,
        ['reason' => $reason]
    );
}

/**
 * Log booking cancellation with payment reconciliation
 */
function logBookingCancellation(
    array $booking,
    string $cancelled_by_type = 'admin',
    ?int $cancelled_by_id = null,
    ?string $cancelled_by_name = null,
    ?string $reason = null,
    float $amount_paid = 0.00,
    float $refund_amount = 0.00,
    string $refund_status = 'not_required'
): bool {
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Log to cancellation_log table
        $stmt = $pdo->prepare("
            INSERT INTO cancellation_log (
                booking_id, booking_reference, booking_type, guest_email, guest_name,
                cancelled_by_type, cancelled_by_id, cancelled_by_name, cancellation_reason,
                total_amount, amount_paid, refund_amount, refund_status,
                ip_address, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $booking['id'],
            $booking['booking_reference'],
            'room',
            $booking['guest_email'],
            $booking['guest_name'],
            $cancelled_by_type,
            $cancelled_by_id,
            $cancelled_by_name,
            $reason,
            $booking['total_amount'] ?? 0,
            $amount_paid,
            $refund_amount,
            $refund_status,
            $_SERVER['REMOTE_ADDR'] ?? null,
            json_encode([
                'room_name' => $booking['room_name'] ?? null,
                'check_in' => $booking['check_in_date'] ?? null,
                'check_out' => $booking['check_out_date'] ?? null
            ])
        ]);

        // Log to timeline
        logBookingStatusChange(
            $booking['id'],
            $booking['booking_reference'],
            $booking['status'],
            'cancelled',
            $cancelled_by_type,
            $cancelled_by_id,
            $cancelled_by_name,
            $reason
        );

        $pdo->commit();
        return true;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Failed to log booking cancellation: " . $e->getMessage());
        return false;
    }
}

/**
 * Log email sent
 */
function logBookingEmail(int $booking_id, string $booking_reference, string $email_type, string $recipient, bool $success, ?string $error = null): bool {
    return logBookingEvent(
        $booking_id,
        $booking_reference,
        "Email sent: {$email_type}",
        'email',
        $success ? "Email '{$email_type}' sent to {$recipient}" : "Failed to send '{$email_type}' to {$recipient}: {$error}",
        null,
        $success ? 'sent' : 'failed',
        'system',
        null,
        null,
        [
            'email_type' => $email_type,
            'recipient' => $recipient,
            'success' => $success,
            'error' => $error
        ]
    );
}

/**
 * Log check-in
 */
function logBookingCheckIn(int $booking_id, string $booking_reference, string $performed_by_type = 'admin', ?int $performed_by_id = null, ?string $performed_by_name = null): bool {
    return logBookingEvent(
        $booking_id,
        $booking_reference,
        'Guest checked in',
        'check_in',
        'Guest has been checked in to the room',
        'confirmed',
        'checked-in',
        $performed_by_type,
        $performed_by_id,
        $performed_by_name
    );
}

/**
 * Log check-out
 */
function logBookingCheckOut(int $booking_id, string $booking_reference, string $performed_by_type = 'admin', ?int $performed_by_id = null, ?string $performed_by_name = null): bool {
    return logBookingEvent(
        $booking_id,
        $booking_reference,
        'Guest checked out',
        'check_out',
        'Guest has checked out from the room',
        'checked-in',
        'checked-out',
        $performed_by_type,
        $performed_by_id,
        $performed_by_name
    );
}

/**
 * Log tentative booking conversion
 */
function logTentativeConversion(int $booking_id, string $booking_reference, string $performed_by_type = 'admin', ?int $performed_by_id = null, ?string $performed_by_name = null): bool {
    return logBookingEvent(
        $booking_id,
        $booking_reference,
        'Tentative booking converted to confirmed',
        'conversion',
        'Tentative booking has been converted to a confirmed booking',
        'tentative',
        'pending',
        $performed_by_type,
        $performed_by_id,
        $performed_by_name
    );
}

/**
 * Log tentative booking expiry
 */
function logTentativeExpiry(int $booking_id, string $booking_reference): bool {
    return logBookingEvent(
        $booking_id,
        $booking_reference,
        'Tentative booking expired',
        'expiry',
        'Tentative booking has expired and been automatically cancelled',
        'tentative',
        'expired',
        'system'
    );
}

/**
 * Log payment
 */
function logBookingPayment(int $booking_id, string $booking_reference, float $amount, string $payment_type, string $payment_method, string $status = 'completed', ?int $processed_by = null, ?string $reference = null): bool {
    global $pdo;

    try {
        // Insert into payments table
        $stmt = $pdo->prepare("
            INSERT INTO booking_payments (
                booking_id, booking_reference, payment_type, amount, payment_method,
                payment_reference, payment_status, processed_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $booking_id,
            $booking_reference,
            $payment_type,
            $amount,
            $payment_method,
            $reference,
            $status,
            $processed_by
        ]);

        // Log to timeline
        logBookingEvent(
            $booking_id,
            $booking_reference,
            "Payment recorded: {$payment_type}",
            'payment',
            ucfirst($payment_type) . " payment of " . number_format($amount, 2) . " via {$payment_method} - Status: {$status}",
            null,
            $status,
            $processed_by ? 'admin' : 'system',
            $processed_by
        );

        return true;

    } catch (PDOException $e) {
        error_log("Failed to log booking payment: " . $e->getMessage());
        return false;
    }
}

/**
 * Log admin note
 */
function logBookingNote(int $booking_id, string $booking_reference, string $note, int $admin_id, string $admin_name): bool {
    return logBookingEvent(
        $booking_id,
        $booking_reference,
        'Note added',
        'note',
        $note,
        null,
        null,
        'admin',
        $admin_id,
        $admin_name
    );
}

/**
 * Write a "created" entry directly into booking_audit_log.
 * Called from the public booking form so that the admin Audit Log modal
 * is not empty for brand-new online bookings.
 * Safe no-op if the table cannot be created or the insert fails.
 */
function logBookingCreatedAudit(int $bookingId, string $bookingReference, string $performedByType = 'guest', ?string $performedByName = null): void {
    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS booking_audit_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id INT UNSIGNED NOT NULL,
            booking_reference VARCHAR(64) DEFAULT NULL,
            action VARCHAR(64) NOT NULL,
            old_values JSON NULL,
            new_values JSON NULL,
            changed_fields JSON NULL,
            note TEXT NULL,
            performed_by INT UNSIGNED DEFAULT NULL,
            performed_by_name VARCHAR(255) DEFAULT NULL,
            performed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_bal_booking (booking_id),
            KEY idx_bal_action (action),
            KEY idx_bal_performed_at (performed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for booking actions'");

        $stmt = $pdo->prepare("INSERT INTO booking_audit_log
            (booking_id, booking_reference, action, note, performed_by_name, ip_address, user_agent)
            VALUES (?, ?, 'created', ?, ?, ?, ?)");
        $stmt->execute([
            $bookingId,
            $bookingReference,
            'Booking made online' . ($performedByName ? ' by ' . $performedByName : ''),
            $performedByName,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('logBookingCreatedAudit failed: ' . $e->getMessage());
    }
}

/**
 * Get booking timeline
 */
function getBookingTimeline(int $booking_id): array {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM booking_timeline_logs
            WHERE booking_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$booking_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to get booking timeline: " . $e->getMessage());
        return [];
    }
}

/**
 * Get cancellation logs
 */
function getCancellationLogs(int $limit = 50): array {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT cl.*, r.name as room_name
            FROM cancellation_log cl
            LEFT JOIN bookings b ON cl.booking_id = b.id
            LEFT JOIN rooms r ON b.room_id = r.id
            ORDER BY cl.cancellation_date DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to get cancellation logs: " . $e->getMessage());
        return [];
    }
}

/**
 * Get pending refunds
 */
function getPendingRefunds(): array {
    global $pdo;

    try {
        $stmt = $pdo->query("
            SELECT cl.*, b.room_id, r.name as room_name
            FROM cancellation_log cl
            JOIN bookings b ON cl.booking_id = b.id
            JOIN rooms r ON b.room_id = r.id
            WHERE cl.refund_status IN ('pending', 'processing')
            ORDER BY cl.cancellation_date ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to get pending refunds: " . $e->getMessage());
        return [];
    }
}

/**
 * Format timeline action type for display
 *
 * @param string $action_type The action type
 * @return array Array with 'label', 'icon', and 'color' keys
 */
function formatActionType(string $action_type): array {
    $types = [
        'create' => ['label' => 'Created', 'icon' => 'fa-plus-circle', 'color' => '#28a745'],
        'update' => ['label' => 'Updated', 'icon' => 'fa-edit', 'color' => '#17a2b8'],
        'status_change' => ['label' => 'Status Change', 'icon' => 'fa-exchange-alt', 'color' => '#8B7355'],
        'payment' => ['label' => 'Payment', 'icon' => 'fa-credit-card', 'color' => '#28a745'],
        'cancellation' => ['label' => 'Cancellation', 'icon' => 'fa-times-circle', 'color' => '#dc3545'],
        'email' => ['label' => 'Email', 'icon' => 'fa-envelope', 'color' => '#6c757d'],
        'check_in' => ['label' => 'Check-in', 'icon' => 'fa-sign-in-alt', 'color' => '#28a745'],
        'check_out' => ['label' => 'Check-out', 'icon' => 'fa-sign-out-alt', 'color' => '#6c757d'],
        'conversion' => ['label' => 'Conversion', 'icon' => 'fa-check-double', 'color' => '#28a745'],
        'reminder' => ['label' => 'Reminder', 'icon' => 'fa-bell', 'color' => '#ffc107'],
        'expiry' => ['label' => 'Expired', 'icon' => 'fa-hourglass-end', 'color' => '#dc3545'],
        'note' => ['label' => 'Note', 'icon' => 'fa-sticky-note', 'color' => '#17a2b8'],
        'date_adjustment' => ['label' => 'Date Adjustment', 'icon' => 'fa-calendar-alt', 'color' => '#f5576c']
    ];

    return $types[$action_type] ?? ['label' => ucfirst($action_type), 'icon' => 'fa-circle', 'color' => '#6c757d'];
}

/**
 * Get booking date adjustments with formatted display data
 *
 * @param int $bookingId Booking ID
 * @return array List of formatted adjustments
 */
function getBookingDateAdjustmentsFormatted(int $bookingId): array {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT
                id,
                old_check_in_date,
                new_check_in_date,
                old_check_out_date,
                new_check_out_date,
                old_number_of_nights,
                new_number_of_nights,
                old_total_amount,
                new_total_amount,
                amount_delta,
                adjustment_reason,
                adjusted_by_name,
                adjustment_timestamp,
                metadata
            FROM booking_date_adjustments
            WHERE booking_id = ?
            ORDER BY adjustment_timestamp DESC
        ");
        $stmt->execute([$bookingId]);
        $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format each adjustment for display
        foreach ($adjustments as &$adj) {
            $adj['nights_delta'] = $adj['new_number_of_nights'] - $adj['old_number_of_nights'];
            $adj['is_refund'] = $adj['amount_delta'] < 0;

            // Parse metadata for additional details
            $metadata = json_decode($adj['metadata'], true) ?: [];
            $adj['child_supplement_delta'] = $metadata['child_supplement_delta'] ?? 0;
            $adj['credit_balance'] = $metadata['credit_balance'] ?? null;

            // Build delta display text
            $deltaParts = [];
            if ($adj['amount_delta'] >= 0) {
                $deltaParts[] = '+$' . number_format($adj['amount_delta'], 2) . ' additional charge';
            } else {
                $deltaParts[] = '-$' . number_format(abs($adj['amount_delta']), 2) . ' refund/credit';
            }

            // Add credit balance note if applicable
            if ($adj['credit_balance'] > 0) {
                $deltaParts[] = '(Credit balance: $' . number_format($adj['credit_balance'], 2) . ')';
            }

            $adj['delta_display'] = implode(' ', $deltaParts);

            // Format old and new dates for display
            $adj['old_check_in_formatted'] = date('M j, Y', strtotime($adj['old_check_in_date']));
            $adj['new_check_in_formatted'] = date('M j, Y', strtotime($adj['new_check_in_date']));
            $adj['old_check_out_formatted'] = date('M j, Y', strtotime($adj['old_check_out_date']));
            $adj['new_check_out_formatted'] = date('M j, Y', strtotime($adj['new_check_out_date']));
        }

        return $adjustments;
    } catch (PDOException $e) {
        error_log("getBookingDateAdjustmentsFormatted error: " . $e->getMessage());
        return [];
    }
}
