<?php

/**
 * Hotel Room Management Service
 * Comprehensive room lifecycle management including:
 * - Room status transitions
 * - Housekeeping automation
 * - Check-in/Check-out workflows
 * - Room turnover tracking
 */

require_once __DIR__ . '/../config/database.php';

// Room status constants
define('ROOM_STATUS_AVAILABLE', 'available');
define('ROOM_STATUS_OCCUPIED', 'occupied');
define('ROOM_STATUS_CLEANING', 'cleaning');
define('ROOM_STATUS_INSPECTION', 'inspection');
define('ROOM_STATUS_MAINTENANCE', 'maintenance');
define('ROOM_STATUS_OUT_OF_ORDER', 'out_of_order');

// Housekeeping status constants
define('HK_STATUS_PENDING', 'pending');
define('HK_STATUS_IN_PROGRESS', 'in_progress');
define('HK_STATUS_COMPLETED', 'completed');
define('HK_STATUS_BLOCKED', 'blocked');

/**
 * Get valid room statuses with labels and colors
 */
function getRoomStatuses(): array
{
    return [
        ROOM_STATUS_AVAILABLE => [
            'label' => 'Available',
            'icon' => 'fa-check-circle',
            'color' => '#28a745',
            'description' => 'Room is clean and ready for guests'
        ],
        ROOM_STATUS_OCCUPIED => [
            'label' => 'Occupied',
            'icon' => 'fa-user',
            'color' => '#dc3545',
            'description' => 'Guest is currently checked in'
        ],
        ROOM_STATUS_CLEANING => [
            'label' => 'Cleaning',
            'icon' => 'fa-broom',
            'color' => '#ffc107',
            'description' => 'Room is being cleaned'
        ],
        ROOM_STATUS_INSPECTION => [
            'label' => 'Inspection',
            'icon' => 'fa-clipboard-check',
            'color' => '#17a2b8',
            'description' => 'Room awaiting inspection before release'
        ],
        ROOM_STATUS_MAINTENANCE => [
            'label' => 'Maintenance',
            'icon' => 'fa-tools',
            'color' => '#fd7e14',
            'description' => 'Room under maintenance'
        ],
        ROOM_STATUS_OUT_OF_ORDER => [
            'label' => 'Out of Order',
            'icon' => 'fa-ban',
            'color' => '#6c757d',
            'description' => 'Room not available for use'
        ]
    ];
}

/**
 * Validate room status transition
 *
 * @param string $currentStatus Current room status
 * @param string $newStatus Desired new status
 * @return array ['valid' => bool, 'reason' => string]
 */
function validateRoomStatusTransition(string $currentStatus, string $newStatus): array
{
    // Define valid transitions
    $validTransitions = [
        ROOM_STATUS_AVAILABLE => [
            ROOM_STATUS_OCCUPIED,      // Check-in
            ROOM_STATUS_MAINTENANCE,   // Schedule maintenance
            ROOM_STATUS_OUT_OF_ORDER   // Take out of order
        ],
        ROOM_STATUS_OCCUPIED => [
            ROOM_STATUS_CLEANING,      // Check-out (standard)
            ROOM_STATUS_MAINTENANCE,   // Emergency maintenance
            ROOM_STATUS_OUT_OF_ORDER   // Emergency
        ],
        ROOM_STATUS_CLEANING => [
            ROOM_STATUS_INSPECTION,    // Cleaning complete, needs inspection
            ROOM_STATUS_AVAILABLE,     // Cleaning complete, auto-release
            ROOM_STATUS_MAINTENANCE,   // Issue found during cleaning
            ROOM_STATUS_OUT_OF_ORDER   // Major issue found
        ],
        ROOM_STATUS_INSPECTION => [
            ROOM_STATUS_AVAILABLE,     // Inspection passed
            ROOM_STATUS_CLEANING,      // Inspection failed, re-clean
            ROOM_STATUS_MAINTENANCE,   // Issue found
            ROOM_STATUS_OUT_OF_ORDER   // Major issue
        ],
        ROOM_STATUS_MAINTENANCE => [
            ROOM_STATUS_CLEANING,      // Maintenance complete, needs cleaning
            ROOM_STATUS_AVAILABLE,     // Maintenance complete, ready
            ROOM_STATUS_OUT_OF_ORDER   // Cannot fix
        ],
        ROOM_STATUS_OUT_OF_ORDER => [
            ROOM_STATUS_MAINTENANCE,   // Start repairs
            ROOM_STATUS_CLEANING,      // Fixed, needs cleaning
            ROOM_STATUS_AVAILABLE      // Fixed and ready
        ]
    ];

    if ($currentStatus === $newStatus) {
        return ['valid' => false, 'reason' => 'Room is already in this status'];
    }

    if (!isset($validTransitions[$currentStatus])) {
        return ['valid' => false, 'reason' => 'Unknown current status: ' . $currentStatus];
    }

    if (!in_array($newStatus, $validTransitions[$currentStatus])) {
        return [
            'valid' => false,
            'reason' => "Cannot transition from {$currentStatus} to {$newStatus}"
        ];
    }

    return ['valid' => true, 'reason' => ''];
}

/**
 * Update room status with full workflow support
 *
 * @param int $roomId Individual room ID
 * @param string $newStatus New status
 * @param string $reason Reason for change
 * @param int|null $performedBy Admin user ID
 * @param array $options Additional options
 * @return array ['success' => bool, 'message' => string, 'data' => array]
 */
function updateRoomStatus(int $roomId, string $newStatus, string $reason = '', ?int $performedBy = null, array $options = []): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Get current room status.
        //
        // This previously did `LEFT JOIN room_inspections`, which deadlocked the
        // whole function: `room_inspections` is created lazily by
        // createRoomInspection() below, that is only reached via
        // handleStatusWorkflow() further down this same function, and this query
        // threw before ever getting there. The table therefore could never be
        // created, and EVERY updateRoomStatus() call returned
        // "Database error: ... room_inspections doesn't exist" — breaking check-in,
        // the room dashboard, mark-clean, inspections and guest checkout.
        // The joined column (`inspection_status`) was selected and never read
        // anywhere in the codebase, so dropping the join restores the function
        // with no behaviour change and no schema change.
        $stmt = $pdo->prepare("
            SELECT ir.*
            FROM individual_rooms ir
            WHERE ir.id = ?
        ");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Room not found'];
        }

        $currentStatus = $room['status'];

        // Validate transition (unless forced)
        if (empty($options['force'])) {
            $validation = validateRoomStatusTransition($currentStatus, $newStatus);
            if (!$validation['valid']) {
                $pdo->rollBack();
                return ['success' => false, 'message' => $validation['reason']];
            }
        }

        // Update room status
        $updateStmt = $pdo->prepare("UPDATE individual_rooms SET status = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$newStatus, $roomId]);

        // Log the status change
        $logStmt = $pdo->prepare("
            INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $logStmt->execute([$roomId, $currentStatus, $newStatus, $reason, $performedBy]);

        // Handle status-specific workflows
        $workflowResult = handleStatusWorkflow($pdo, $roomId, $currentStatus, $newStatus, $performedBy, $options);

        $pdo->commit();

        return [
            'success' => true,
            'message' => "Room status updated from {$currentStatus} to {$newStatus}",
            'data' => [
                'room_id' => $roomId,
                'old_status' => $currentStatus,
                'new_status' => $newStatus,
                'workflow' => $workflowResult
            ]
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Room status update error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Handle status-specific workflows
 */
function handleStatusWorkflow(PDO $pdo, int $roomId, string $oldStatus, string $newStatus, ?int $performedBy, array $options): array
{
    $result = ['actions' => []];

    switch ($newStatus) {
        case ROOM_STATUS_CLEANING:
            // Create housekeeping assignment
            $assignmentResult = createHousekeepingAssignment($roomId, $performedBy, $options);
            $result['actions'][] = 'housekeeping_created';
            $result['housekeeping'] = $assignmentResult;
            break;

        case ROOM_STATUS_INSPECTION:
            // Create inspection task
            $inspectionResult = createRoomInspection($roomId, $performedBy);
            $result['actions'][] = 'inspection_created';
            $result['inspection'] = $inspectionResult;
            break;

        case ROOM_STATUS_AVAILABLE:
            // Complete any pending housekeeping/inspection
            completeRoomTurnover($pdo, $roomId, $performedBy);
            $result['actions'][] = 'turnover_completed';

            // Update last cleaned timestamp
            $pdo->prepare("UPDATE individual_rooms SET last_cleaned_at = NOW() WHERE id = ?")->execute([$roomId]);
            break;

        case ROOM_STATUS_OCCUPIED:
            // Room occupied - no special workflow
            $result['actions'][] = 'occupied';
            break;

        case ROOM_STATUS_MAINTENANCE:
        case ROOM_STATUS_OUT_OF_ORDER:
            // Cancel any pending housekeeping
            $pdo->prepare("
                UPDATE housekeeping_assignments
                SET status = 'blocked', notes = CONCAT(COALESCE(notes, ''), ' - Room status changed to {$newStatus}')
                WHERE individual_room_id = ? AND status IN ('pending', 'in_progress')
            ")->execute([$roomId]);
            $result['actions'][] = 'housekeeping_blocked';
            break;
    }

    return $result;
}

/**
 * Create housekeeping assignment for a room
 */
function createHousekeepingAssignment(int $roomId, ?int $performedBy, array $options = []): array
{
    global $pdo;

    try {
        // Check for existing pending assignment
        $existingStmt = $pdo->prepare("
            SELECT id FROM housekeeping_assignments
            WHERE individual_room_id = ? AND status IN ('pending', 'in_progress')
        ");
        $existingStmt->execute([$roomId]);
        if ($existingStmt->fetch()) {
            return ['success' => true, 'message' => 'Housekeeping assignment already exists'];
        }

        // Determine priority
        $priority = $options['priority'] ?? 'normal';
        if (!empty($options['urgent'])) {
            $priority = 'urgent';
        }

        // Get room's housekeeping notes
        $notesStmt = $pdo->prepare("SELECT housekeeping_notes FROM individual_rooms WHERE id = ?");
        $notesStmt->execute([$roomId]);
        $roomNotes = $notesStmt->fetchColumn();

        $notes = 'Room turnover cleaning';
        if ($roomNotes) {
            $notes .= ' - ' . $roomNotes;
        }
        if (!empty($options['notes'])) {
            $notes .= ' - ' . $options['notes'];
        }

        // Create assignment
        $stmt = $pdo->prepare("
            INSERT INTO housekeeping_assignments
            (individual_room_id, status, due_date, priority, notes, created_by, created_at)
            VALUES (?, 'pending', CURDATE(), ?, ?, ?, NOW())
        ");
        $stmt->execute([$roomId, $priority, $notes, $performedBy]);

        // Update room's housekeeping status
        $pdo->prepare("UPDATE individual_rooms SET housekeeping_status = 'pending' WHERE id = ?")->execute([$roomId]);

        return [
            'success' => true,
            'message' => 'Housekeeping assignment created',
            'assignment_id' => $pdo->lastInsertId()
        ];
    } catch (PDOException $e) {
        error_log("Housekeeping assignment error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Create room inspection task
 */
function createRoomInspection(int $roomId, ?int $performedBy): array
{
    global $pdo;

    try {
        // Check if inspections table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'room_inspections'");
        if ($tableCheck->rowCount() === 0) {
            // Create inspections table
            $pdo->exec("
                CREATE TABLE room_inspections (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    individual_room_id INT NOT NULL,
                    status ENUM('pending', 'passed', 'failed') DEFAULT 'pending',
                    inspector_id INT,
                    checklist JSON,
                    notes TEXT,
                    inspected_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (individual_room_id) REFERENCES individual_rooms(id) ON DELETE CASCADE
                )
            ");
        }

        // Create inspection task
        $stmt = $pdo->prepare("
            INSERT INTO room_inspections (individual_room_id, status, created_at)
            VALUES (?, 'pending', NOW())
        ");
        $stmt->execute([$roomId]);

        return [
            'success' => true,
            'message' => 'Inspection task created',
            'inspection_id' => $pdo->lastInsertId()
        ];
    } catch (PDOException $e) {
        error_log("Inspection creation error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Complete room turnover (housekeeping + inspection done)
 */
function completeRoomTurnover(PDO $pdo, int $roomId, ?int $performedBy): void
{
    // Mark housekeeping as completed
    $pdo->prepare("
        UPDATE housekeeping_assignments
        SET status = 'completed', completed_at = NOW()
        WHERE individual_room_id = ? AND status IN ('pending', 'in_progress')
    ")->execute([$roomId]);

    // Mark inspection as passed
    $pdo->prepare("
        UPDATE room_inspections
        SET status = 'passed', inspected_at = NOW(), inspector_id = ?
        WHERE individual_room_id = ? AND status = 'pending'
    ")->execute([$performedBy, $roomId]);

    // Clear housekeeping status
    $pdo->prepare("UPDATE individual_rooms SET housekeeping_status = 'completed', housekeeping_notes = NULL WHERE id = ?")->execute([$roomId]);
}

/**
 * Process guest checkout with full room management
 *
 * @param int $bookingId Booking ID
 * @param int|null $performedBy Admin user ID
 * @param array $options Additional options
 * @return array Result array
 */
function processGuestCheckout(int $bookingId, ?int $performedBy = null, array $options = []): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Get booking details
        $stmt = $pdo->prepare("
            SELECT b.*, ir.id as individual_room_id, ir.status as room_status, ir.room_number,
                   b.room_id as room_type_id
            FROM bookings b
            LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
            WHERE b.id = ? AND b.status = 'checked-in'
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Booking not found or guest not checked in'];
        }

        // Recalculate financials first so the returned balance and the final
        // invoice reflect any folio charges added right up to checkout.
        if (function_exists('recalculateBookingFinancials')) {
            recalculateBookingFinancials($bookingId);
        }
        $balStmt = $pdo->prepare("SELECT amount_due FROM bookings WHERE id = ?");
        $balStmt->execute([$bookingId]);
        $outstandingBalance = round((float)($balStmt->fetchColumn() ?: 0), 2);

        // Update booking status
        $pdo->prepare("UPDATE bookings SET status = 'checked-out', checkout_completed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$bookingId]);

        // Restore room type availability (coarse per-type counter — one decrement
        // per booking at creation, so one restore here).
        $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ?")->execute([$booking['room_type_id']]);

        $workflowResults = [];
        $nextStatus = $options['room_status'] ?? ROOM_STATUS_CLEANING;

        // Collect EVERY individual room held by this booking: the primary
        // (bookings.individual_room_id) plus any additional rooms tracked in
        // booking_rooms (multi-room bookings). Previously only the primary was
        // released, so secondary rooms stayed 'occupied' forever after checkout.
        $roomsToRelease = [];
        if (!empty($booking['individual_room_id'])) {
            $roomsToRelease[(int)$booking['individual_room_id']] = true;
        }
        try {
            $brStmt = $pdo->prepare("SELECT individual_room_id FROM booking_rooms WHERE booking_id = ? AND released_at IS NULL AND individual_room_id IS NOT NULL");
            $brStmt->execute([$bookingId]);
            foreach ($brStmt->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                $roomsToRelease[(int)$rid] = true;
            }
        } catch (Throwable $e) {
            // booking_rooms may not exist on older schemas — primary handled above.
        }

        $primaryRoomId = (int)($booking['individual_room_id'] ?? 0);
        foreach (array_keys($roomsToRelease) as $roomId) {
            if ($roomId <= 0) {
                continue;
            }
            $roomResult = updateRoomStatus(
                $roomId,
                $nextStatus,
                "Guest checkout - Booking #{$booking['booking_reference']}",
                $performedBy,
                ['force' => true, 'notes' => $booking['booking_reference']]
            );
            $hkResult = null;
            if ($nextStatus === ROOM_STATUS_CLEANING) {
                $hkResult = createHousekeepingAssignment(
                    $roomId,
                    $performedBy,
                    [
                        'priority' => !empty($options['urgent_cleaning']) ? 'urgent' : 'normal',
                        'notes' => "Turnover after {$booking['guest_name']} checkout"
                    ]
                );
            }

            // Preserve the flat primary-room keys the caller reads for messaging;
            // record every room (incl. secondaries) under 'rooms' for completeness.
            if ($roomId === $primaryRoomId || !isset($workflowResults['room_update'])) {
                $workflowResults['room_update'] = $roomResult;
                if ($hkResult !== null) {
                    $workflowResults['housekeeping'] = $hkResult;
                }
            }
            $workflowResults['rooms'][$roomId] = ['room_update' => $roomResult, 'housekeeping' => $hkResult];

            $pdo->prepare("
                INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by, created_at)
                VALUES (?, 'occupied', ?, ?, ?, NOW())
            ")->execute([$roomId, $nextStatus, "Checkout: {$booking['booking_reference']}", $performedBy]);
        }

        // Release all room holds so availability and future checkouts stay clean.
        try {
            $pdo->prepare("UPDATE booking_rooms SET released_at = NOW() WHERE booking_id = ? AND released_at IS NULL")->execute([$bookingId]);
        } catch (Throwable $e) {
            // older schema without booking_rooms — safe to ignore
        }

        // Generate final invoice
        require_once __DIR__ . '/../config/invoice.php';
        $invoiceResult = generateAndSendFinalInvoice($bookingId, $performedBy);
        $workflowResults['invoice'] = $invoiceResult;

        $pdo->commit();

        return [
            'success' => true,
            'message' => $outstandingBalance > 0.01
                ? 'Guest checked out. Outstanding balance of ' . number_format($outstandingBalance, 2) . ' remains on the account.'
                : 'Guest checked out successfully',
            'data' => [
                'booking_id' => $bookingId,
                'booking_reference' => $booking['booking_reference'],
                'room_number' => $booking['room_number'] ?? null,
                'outstanding_balance' => $outstandingBalance,
                'rooms_released' => array_keys($roomsToRelease),
                'workflow' => $workflowResults
            ]
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Checkout process error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Mark room as clean and ready
 *
 * @param int $roomId Individual room ID
 * @param int|null $performedBy Housekeeping staff ID
 * @param array $options Additional options
 * @return array Result array
 */
function markRoomClean(int $roomId, ?int $performedBy = null, array $options = []): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Get current room status
        $stmt = $pdo->prepare("SELECT status, room_number FROM individual_rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Room not found'];
        }

        // Determine if inspection is required
        $requireInspection = !empty($options['require_inspection']) || getSetting('room_inspection_required', '0') === '1';

        $newStatus = $requireInspection ? ROOM_STATUS_INSPECTION : ROOM_STATUS_AVAILABLE;

        // Update housekeeping assignment
        $pdo->prepare("
            UPDATE housekeeping_assignments
            SET status = 'completed', completed_at = NOW()
            WHERE individual_room_id = ? AND status IN ('pending', 'in_progress')
        ")->execute([$roomId]);

        // Update room status
        $result = updateRoomStatus(
            $roomId,
            $newStatus,
            'Cleaning completed' . (!empty($options['notes']) ? ' - ' . $options['notes'] : ''),
            $performedBy,
            ['force' => true]
        );

        if (!$result['success']) {
            $pdo->rollBack();
            return $result;
        }

        // Update room's housekeeping status
        $pdo->prepare("
            UPDATE individual_rooms
            SET housekeeping_status = 'completed', last_cleaned_at = NOW()
            WHERE id = ?
        ")->execute([$roomId]);

        $pdo->commit();

        return [
            'success' => true,
            'message' => $requireInspection
                ? "Room marked for inspection - awaiting approval"
                : "Room marked as clean and available",
            'data' => [
                'room_id' => $roomId,
                'room_number' => $room['room_number'],
                'new_status' => $newStatus,
                'inspection_required' => $requireInspection
            ]
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Mark room clean error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Pass room inspection and make available
 *
 * @param int $roomId Individual room ID
 * @param int $inspectorId Inspector user ID
 * @param array $options Additional options
 * @return array Result array
 */
function passRoomInspection(int $roomId, int $inspectorId, array $options = []): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Update inspection record
        $pdo->prepare("
            UPDATE room_inspections
            SET status = 'passed', inspector_id = ?, inspected_at = NOW(), notes = ?
            WHERE individual_room_id = ? AND status = 'pending'
        ")->execute([$inspectorId, $options['notes'] ?? 'Inspection passed', $roomId]);

        // Update room status to available
        $result = updateRoomStatus(
            $roomId,
            ROOM_STATUS_AVAILABLE,
            'Inspection passed',
            $inspectorId,
            ['force' => true]
        );

        if (!$result['success']) {
            $pdo->rollBack();
            return $result;
        }

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Inspection passed - room is now available',
            'data' => ['room_id' => $roomId, 'new_status' => ROOM_STATUS_AVAILABLE]
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Pass inspection error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Fail room inspection - send back to cleaning
 *
 * @param int $roomId Individual room ID
 * @param int $inspectorId Inspector user ID
 * @param string $reason Reason for failure
 * @param array $options Additional options
 * @return array Result array
 */
function failRoomInspection(int $roomId, int $inspectorId, string $reason, array $options = []): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Update inspection record
        $pdo->prepare("
            UPDATE room_inspections
            SET status = 'failed', inspector_id = ?, inspected_at = NOW(), notes = ?
            WHERE individual_room_id = ? AND status = 'pending'
        ")->execute([$inspectorId, $reason, $roomId]);

        // Update room status back to cleaning
        $result = updateRoomStatus(
            $roomId,
            ROOM_STATUS_CLEANING,
            'Inspection failed: ' . $reason,
            $inspectorId,
            ['force' => true]
        );

        if (!$result['success']) {
            $pdo->rollBack();
            return $result;
        }

        // Create new housekeeping assignment
        $hkResult = createHousekeepingAssignment(
            $roomId,
            $inspectorId,
            ['priority' => 'urgent', 'notes' => 'Re-clean after failed inspection: ' . $reason]
        );

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Inspection failed - room sent back to cleaning',
            'data' => [
                'room_id' => $roomId,
                'new_status' => ROOM_STATUS_CLEANING,
                'housekeeping' => $hkResult
            ]
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Fail inspection error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Get rooms requiring housekeeping
 *
 * @param string $status Filter by status (cleaning, all)
 * @return array List of rooms
 */
function getRoomsRequiringHousekeeping(string $status = 'all'): array
{
    global $pdo;

    try {
        $sql = "
            SELECT
                ir.id, ir.room_number, ir.room_name, ir.status, ir.floor,
                ir.housekeeping_status, ir.housekeeping_notes,
                r.name as room_type,
                ha.id as assignment_id, ha.status as hk_status, ha.priority,
                ha.due_date, ha.notes as hk_notes,
                ha.assigned_to,
                au.username as assigned_to_name,
                b.booking_reference as last_booking_ref,
                b.guest_name as last_guest,
                b.check_out_date as last_checkout
            FROM individual_rooms ir
            LEFT JOIN rooms r ON ir.room_type_id = r.id
            LEFT JOIN housekeeping_assignments ha ON ir.id = ha.individual_room_id
                AND ha.status IN ('pending', 'in_progress')
            LEFT JOIN admin_users au ON ha.assigned_to = au.id
            LEFT JOIN bookings b ON ir.id = b.individual_room_id
                AND b.status = 'checked-out'
                AND b.checkout_completed_at = (
                    SELECT MAX(checkout_completed_at)
                    FROM bookings b2
                    WHERE b2.individual_room_id = ir.id
                )
            WHERE ir.is_active = 1
        ";

        if ($status === 'cleaning') {
            $sql .= " AND ir.status = 'cleaning'";
        } elseif ($status === 'pending') {
            $sql .= " AND ir.housekeeping_status = 'pending'";
        }

        $sql .= " ORDER BY
            CASE ha.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'normal' THEN 3
                ELSE 4
            END,
            ir.status = 'cleaning' DESC,
            ha.due_date ASC,
            ir.room_number ASC
        ";

        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Get housekeeping rooms error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get rooms requiring inspection
 *
 * @return array List of rooms
 */
function getRoomsRequiringInspection(): array
{
    global $pdo;

    try {
        $stmt = $pdo->query("
            SELECT
                ir.id, ir.room_number, ir.room_name, ir.status, ir.floor,
                r.name as room_type,
                ri.id as inspection_id, ri.created_at as inspection_created,
                ri.notes as inspection_notes,
                ha.completed_at as cleaning_completed,
                b.booking_reference as last_booking_ref,
                b.guest_name as last_guest
            FROM individual_rooms ir
            LEFT JOIN rooms r ON ir.room_type_id = r.id
            LEFT JOIN room_inspections ri ON ir.id = ri.individual_room_id AND ri.status = 'pending'
            LEFT JOIN housekeeping_assignments ha ON ir.id = ha.individual_room_id AND ha.status = 'completed'
            LEFT JOIN bookings b ON ir.id = b.individual_room_id
                AND b.status = 'checked-out'
                AND b.checkout_completed_at = (
                    SELECT MAX(checkout_completed_at)
                    FROM bookings b2
                    WHERE b2.individual_room_id = ir.id
                )
            WHERE ir.status = 'inspection' AND ir.is_active = 1
            ORDER BY ri.created_at ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Get inspection rooms error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get room dashboard summary
 *
 * @return array Room statistics
 */
function getRoomDashboardSummary(): array
{
    global $pdo;

    try {
        // Room status counts
        $statusStmt = $pdo->query("
            SELECT status, COUNT(*) as count
            FROM individual_rooms
            WHERE is_active = 1
            GROUP BY status
        ");
        $statusCounts = [];
        foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statusCounts[$row['status']] = (int)$row['count'];
        }

        // Housekeeping queue
        // Housekeeping queue (distinct active rooms needing cleaning attention)
        $hkStmt = $pdo->query("
                SELECT COUNT(DISTINCT q.room_id) AS count
                FROM (
                    SELECT ir.id AS room_id
                    FROM individual_rooms ir
                    WHERE ir.is_active = 1
                      AND ir.status = 'cleaning'

                    UNION ALL

                    SELECT ha.individual_room_id AS room_id
                    FROM housekeeping_assignments ha
                    INNER JOIN individual_rooms ir ON ir.id = ha.individual_room_id
                    WHERE ir.is_active = 1
                      AND ha.status IN ('pending', 'in_progress')
                ) q
            ");
        $cleaningCount = (int)$hkStmt->fetchColumn();

        // Pending inspections
        $inspStmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM individual_rooms
            WHERE status = 'inspection' AND is_active = 1
        ");
        $inspectionCount = (int)$inspStmt->fetchColumn();

        // Check-outs today
        $checkoutStmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM bookings
            WHERE status = 'checked-in' AND check_out_date = CURDATE()
        ");
        $checkoutsToday = (int)$checkoutStmt->fetchColumn();

        // Check-ins today (confirmed + pending, matching dashboard stat card)
        $checkinStmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM bookings
            WHERE status IN ('confirmed', 'pending') AND check_in_date = CURDATE()
        ");
        $checkinsToday = (int)$checkinStmt->fetchColumn();

        // Rooms available for check-in
        $availableStmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM individual_rooms
            WHERE status = 'available' AND is_active = 1
        ");
        $availableNow = (int)$availableStmt->fetchColumn();

        // No-show candidates: confirmed/pending bookings whose arrival date has
        // passed without a check-in. These silently hold room availability, so
        // staff need to review and mark them no-show (or check them in late).
        $noShowStmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM bookings
            WHERE status IN ('confirmed', 'pending')
              AND check_in_date < CURDATE()
        ");
        $noShowCandidates = (int)$noShowStmt->fetchColumn();

        return [
            'status_counts' => $statusCounts,
            'cleaning_queue' => $cleaningCount,
            'inspection_queue' => $inspectionCount,
            'checkouts_today' => $checkoutsToday,
            'checkins_today' => $checkinsToday,
            'available_now' => $availableNow,
            'no_show_candidates' => $noShowCandidates,
            'occupancy_rate' => calculateOccupancyRate($statusCounts)
        ];
    } catch (PDOException $e) {
        error_log("Get dashboard summary error: " . $e->getMessage());
        return [];
    }
}

/**
 * Calculate occupancy rate
 */
function calculateOccupancyRate(array $statusCounts): float
{
    $total = array_sum($statusCounts);
    if ($total === 0) return 0.0;

    $occupied = ($statusCounts['occupied'] ?? 0);
    return round(($occupied / $total) * 100, 1);
}

/**
 * Auto-release rooms that have been cleaning too long
 *
 * @param int $hours Threshold in hours
 * @return array Released room IDs
 */
function autoReleaseStaleCleaningRooms(int $hours = 4): array
{
    global $pdo;

    try {
        // Find rooms in cleaning status for too long
        $stmt = $pdo->query("
            SELECT id, room_number
            FROM individual_rooms
            WHERE status = 'cleaning'
            AND is_active = 1
            AND updated_at < DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
        ");
        $staleRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $released = [];
        foreach ($staleRooms as $room) {
            // Check if there's an active housekeeping assignment
            $hkStmt = $pdo->prepare("
                SELECT id FROM housekeeping_assignments
                WHERE individual_room_id = ? AND status IN ('pending', 'in_progress')
            ");
            $hkStmt->execute([$room['id']]);

            if (!$hkStmt->fetch()) {
                // No active assignment - auto-release
                $result = updateRoomStatus(
                    $room['id'],
                    ROOM_STATUS_AVAILABLE,
                    "Auto-released: no active cleaning for {$hours}+ hours",
                    null,
                    ['force' => true]
                );

                if ($result['success']) {
                    $released[] = $room['room_number'];
                }
            }
        }

        return $released;
    } catch (PDOException $e) {
        error_log("Auto-release error: " . $e->getMessage());
        return [];
    }
}
