<?php

/**
 * Housekeeping Management - Admin Panel
 * Enhanced with priority-based assignments, occupied rooms auto-fetch,
 * staff workload tracking, and checkout cleanup automation
 */
require_once 'admin-init.php';
require_once 'includes/admin-modal.php';
/** @var array{id: int, username: string, role: string} $user */
/** @var string $csrf_token */

if (!hasPermission($user['id'], 'housekeeping')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$message = '';
$error = '';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Extended status workflow with verification
$validHousekeepingStatuses = ['pending', 'in_progress', 'completed', 'verified', 'blocked'];
$validPriorities = ['high', 'medium', 'low'];
$validAssignmentTypes = ['checkout_cleanup', 'regular_cleaning', 'maintenance', 'deep_clean', 'turn_down'];
$validRecurringPatterns = ['daily', 'weekly', 'monthly'];

// Priority order for sorting (high first)
$priorityOrder = ['high' => 1, 'medium' => 2, 'low' => 3];

/**
 * Check if a room exists and is active
 */
function housekeepingRoomExists(PDO $pdo, int $roomId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM individual_rooms WHERE id = ? AND is_active = 1");
    $stmt->execute([$roomId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Check if a user exists and is active
 */
function housekeepingUserExists(PDO $pdo, ?int $userId): bool
{
    if (empty($userId)) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Check if a table exists in the database
 */
function housekeepingTableExists(PDO $pdo, string $table): bool
{
    /** @var array<string, bool> $cache */
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

/**
 * Check if a column exists in the housekeeping_assignments table
 * Caches results for performance
 */
function housekeepingColumnExists(PDO $pdo, string $column): bool
{
    /** @var array<string, bool> $cache */
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'housekeeping_assignments' AND COLUMN_NAME = ?");
    $stmt->execute([$column]);
    $cache[$column] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$column];
}

/**
 * Set room status and log the change
 */
function housekeepingSetRoomStatus(PDO $pdo, int $roomId, string $newStatus, ?string $reason, ?int $performedBy): void
{
    $statusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
    $statusStmt->execute([$roomId]);
    $oldStatus = (string)$statusStmt->fetchColumn();
    if ($oldStatus === '' || $oldStatus === $newStatus) {
        return;
    }

    $updateStmt = $pdo->prepare("UPDATE individual_rooms SET status = ? WHERE id = ?");
    $updateStmt->execute([$newStatus, $roomId]);

    if (housekeepingTableExists($pdo, 'room_maintenance_log')) {
        $logStmt = $pdo->prepare("INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by) VALUES (?, ?, ?, ?, ?)");
        $logStmt->execute([$roomId, $oldStatus, $newStatus, $reason, $performedBy]);
    }
}

/**
 * Validate due date - cannot be in the past
 */
function validateDueDate(string $dueDate): bool
{
    $today = date('Y-m-d');
    $dueTimestamp = strtotime($dueDate);
    $todayTimestamp = strtotime($today);

    if ($dueTimestamp === false) {
        return false;
    }

    // Due date must be today or in the future
    return $dueTimestamp >= $todayTimestamp;
}

/**
 * Get all occupied rooms that need housekeeping
 */
function getOccupiedRooms(PDO $pdo): array
{
    $sql = "
        SELECT DISTINCT
            ir.id,
            ir.room_number,
            ir.room_name,
            ir.status as room_status,
            ir.housekeeping_status,
            b.id as booking_id,
            b.guest_name,
            b.check_out_date,
            b.status as booking_status,
            CASE
                WHEN b.check_out_date = CURDATE() THEN 'checkout_today'
                WHEN b.check_out_date < CURDATE() THEN 'overdue_checkout'
                ELSE 'occupied'
            END as occupancy_type
        FROM individual_rooms ir
        INNER JOIN bookings b ON b.individual_room_id = ir.id
        WHERE b.status = 'checked-in'
          AND b.check_in_date <= CURDATE()
          AND b.check_out_date >= CURDATE()
          AND ir.is_active = 1
        ORDER BY
            CASE
                WHEN b.check_out_date = CURDATE() THEN 1
                ELSE 2
            END,
            ir.room_number ASC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get rooms that need checkout cleanup
 * Backward compatible: works with or without migration 004 columns
 */
function getCheckoutCleanupRooms(PDO $pdo): array
{
    $hasAssignmentType = housekeepingColumnExists($pdo, 'assignment_type');

    // A room needs at most ONE checkout cleanup regardless of how many past
    // bookings it has. We deliberately do NOT match on linked_booking_id here:
    // if the room already has any checkout cleanup (open OR already done), it is
    // excluded — this is what prevents the same room being listed/created twice
    // when it has more than one qualifying past booking.
    $notExistsConditions = [
        "ha.individual_room_id = ir.id",
        "ha.status IN ('pending', 'in_progress', 'completed', 'verified')"
    ];

    if ($hasAssignmentType) {
        $notExistsConditions[] = "ha.assignment_type = 'checkout_cleanup'";
    }

    $notExistsClause = implode(' AND ', $notExistsConditions);

    // Collapse to exactly one row per room by selecting only the most recent
    // qualifying booking for that room (latest checkout, then latest id). Without
    // this, a room with two past bookings produced two rows -> two cleanups.
    $sql = "
        SELECT
            ir.id,
            ir.room_number,
            ir.room_name,
            b.id as booking_id,
            b.guest_name,
            b.check_out_date
        FROM individual_rooms ir
        INNER JOIN bookings b ON b.individual_room_id = ir.id
        WHERE b.status IN ('checked-out', 'checked-in')
          AND b.check_out_date <= CURDATE()
          AND ir.is_active = 1
          AND b.id = (
              SELECT b2.id
              FROM bookings b2
              WHERE b2.individual_room_id = ir.id
                AND b2.status IN ('checked-out', 'checked-in')
                AND b2.check_out_date <= CURDATE()
              ORDER BY b2.check_out_date DESC, b2.id DESC
              LIMIT 1
          )
          AND NOT EXISTS (
              SELECT 1 FROM housekeeping_assignments ha
              WHERE {$notExistsClause}
          )
        ORDER BY b.check_out_date ASC, ir.room_number ASC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get staff workload (number of pending/in-progress assignments per staff)
 * Backward compatible: works with or without migration 004 columns
 */
function getStaffWorkload(PDO $pdo): array
{
    $hasPriority = housekeepingColumnExists($pdo, 'priority');

    // Build the high_priority_pending conditionally
    $highPriorityCase = $hasPriority
        ? "COUNT(CASE WHEN ha.status = 'pending' AND ha.priority = 'high' THEN 1 END) as high_priority_pending"
        : "0 as high_priority_pending";

    $sql = "
        SELECT
            u.id,
            u.username,
            COUNT(CASE WHEN ha.status IN ('pending', 'in_progress') THEN 1 END) as active_tasks,
            {$highPriorityCase},
            COUNT(CASE WHEN ha.status = 'completed' AND DATE(ha.completed_at) = CURDATE() THEN 1 END) as completed_today
        FROM admin_users u
        LEFT JOIN housekeeping_assignments ha ON ha.assigned_to = u.id
            AND (ha.status IN ('pending', 'in_progress') OR (ha.status = 'completed' AND DATE(ha.completed_at) = CURDATE()))
        WHERE u.is_active = 1
        GROUP BY u.id, u.username
        ORDER BY active_tasks DESC, u.username ASC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Auto-create checkout cleanup assignments
 * Backward compatible: works with or without migration 004 columns
 */
function autoCreateCheckoutCleanup(PDO $pdo, int $performedBy): int
{
    $hasAssignmentType = housekeepingColumnExists($pdo, 'assignment_type');
    $hasPriority = housekeepingColumnExists($pdo, 'priority');
    $hasAutoCreated = housekeepingColumnExists($pdo, 'auto_created');
    $hasLinkedBookingId = housekeepingColumnExists($pdo, 'linked_booking_id');

    // If we don't have the required columns for checkout cleanup, return early
    if (!$hasAssignmentType || !$hasLinkedBookingId) {
        return 0;
    }

    $checkoutRooms = getCheckoutCleanupRooms($pdo);
    $created = 0;

    foreach ($checkoutRooms as $room) {
        // Guard against duplicates PER ROOM (not per booking). A room must never
        // hold two open checkout cleanups — that previously left the room stuck
        // in 'cleaning' after completing one, because the phantom second one kept
        // it blocked. linked_booking_id is intentionally excluded from this check.
        $checkConditions = [
            "individual_room_id = ?",
            "status IN ('pending', 'in_progress')"
        ];
        $checkParams = [$room['id']];

        if ($hasAssignmentType) {
            $checkConditions[] = "assignment_type = 'checkout_cleanup'";
        }

        $checkSql = "SELECT id FROM housekeeping_assignments WHERE " . implode(' AND ', $checkConditions);
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute($checkParams);
        if ($checkStmt->fetch()) {
            continue;
        }

        // Build INSERT columns and values based on available columns
        $insertColumns = ['individual_room_id', 'status', 'due_date', 'assigned_to', 'created_by', 'notes'];
        $insertValues = ['?', '?', '?', '?', '?', '?'];
        $insertParams = [$room['id'], 'pending', date('Y-m-d'), null, $performedBy, 'Checkout cleanup required'];

        if ($hasAssignmentType) {
            $insertColumns[] = 'assignment_type';
            $insertValues[] = '?';
            $insertParams[] = 'checkout_cleanup';
        }
        if ($hasPriority) {
            $insertColumns[] = 'priority';
            $insertValues[] = '?';
            $insertParams[] = 'high';
        }
        if ($hasAutoCreated) {
            $insertColumns[] = 'auto_created';
            $insertValues[] = '?';
            $insertParams[] = 1;
        }
        if ($hasLinkedBookingId) {
            $insertColumns[] = 'linked_booking_id';
            $insertValues[] = '?';
            $insertParams[] = $room['booking_id'];
        }

        $insertSql = "INSERT INTO housekeeping_assignments (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")";
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute($insertParams);
        $newAssignmentId = (int)$pdo->lastInsertId();
        $created++;

        // Log audit trail for auto-created checkout cleanup
        $newData = [
            'individual_room_id' => $room['id'],
            'status' => 'pending',
            'due_date' => date('Y-m-d'),
            'assigned_to' => null,
            'created_by' => $performedBy,
            'notes' => 'Checkout cleanup required',
        ];
        if ($hasAssignmentType) $newData['assignment_type'] = 'checkout_cleanup';
        if ($hasPriority) $newData['priority'] = 'high';
        if ($hasAutoCreated) $newData['auto_created'] = 1;
        if ($hasLinkedBookingId) $newData['linked_booking_id'] = $room['booking_id'];

        logHousekeepingAction($newAssignmentId, 'created', null, $newData, $performedBy);

        // Update room status
        reconcileIndividualRoomHousekeeping($pdo, $room['id'], $performedBy);
    }

    return $created;
}

/**
 * Reconcile individual room housekeeping status
 * Backward compatible: works with or without migration 004 columns
 */
function reconcileIndividualRoomHousekeeping(PDO $pdo, int $roomId, ?int $performedBy = null): void
{
    $hasPriority = housekeepingColumnExists($pdo, 'priority');

    // Build SELECT columns based on available columns
    $selectColumns = ['status', 'notes'];
    if ($hasPriority) {
        $selectColumns[] = 'priority';
    }

    // Build ORDER BY clause based on available columns
    $orderByClauses = [
        "CASE status WHEN 'in_progress' THEN 1 WHEN 'pending' THEN 2 WHEN 'blocked' THEN 3 ELSE 99 END"
    ];

    if ($hasPriority) {
        array_unshift($orderByClauses, "CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END");
    }

    $orderByClauses[] = "due_date ASC";
    $orderByClauses[] = "id DESC";

    $sql = "
        SELECT " . implode(', ', $selectColumns) . "
        FROM housekeeping_assignments
                WHERE individual_room_id = ?
                    AND status IN ('pending','in_progress','blocked')
        ORDER BY " . implode(', ', $orderByClauses) . "
        LIMIT 1
    ";
    $openStmt = $pdo->prepare($sql);
    $openStmt->execute([$roomId]);
    $open = $openStmt->fetch(PDO::FETCH_ASSOC);

    if ($open) {
        $mapped = in_array($open['status'], ['pending', 'in_progress'], true) ? $open['status'] : 'pending';
        $notes = (string)($open['notes'] ?? '');
        if (($open['status'] ?? '') === 'blocked') {
            $notes = trim('Blocked assignment. ' . $notes);
        }

        $pdo->prepare("UPDATE individual_rooms SET housekeeping_status = ?, housekeeping_notes = ? WHERE id = ?")
            ->execute([$mapped, $notes !== '' ? $notes : null, $roomId]);

        $roomStatusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
        $roomStatusStmt->execute([$roomId]);
        $roomStatus = (string)$roomStatusStmt->fetchColumn();
        if ($roomStatus === 'available') {
            housekeepingSetRoomStatus($pdo, $roomId, 'cleaning', 'Housekeeping assignment active', $performedBy);
        }
        return;
    }

    $pdo->prepare("UPDATE individual_rooms SET housekeeping_status = 'completed', housekeeping_notes = NULL, last_cleaned_at = COALESCE(last_cleaned_at, NOW()) WHERE id = ?")
        ->execute([$roomId]);

    $roomStatusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
    $roomStatusStmt->execute([$roomId]);
    $roomStatus = (string)$roomStatusStmt->fetchColumn();
    if ($roomStatus === 'cleaning') {
        housekeepingSetRoomStatus($pdo, $roomId, 'available', 'Housekeeping assignment cleared', $performedBy);
    }
}

/**
 * Create recurring housekeeping assignments
 * Backward compatible: works with or without migration 004 columns
 */
function createRecurringAssignments(PDO $pdo, int $performedBy): int
{
    $hasIsRecurring = housekeepingColumnExists($pdo, 'is_recurring');
    $hasRecurringPattern = housekeepingColumnExists($pdo, 'recurring_pattern');
    $hasRecurringEndDate = housekeepingColumnExists($pdo, 'recurring_end_date');
    $hasAssignmentType = housekeepingColumnExists($pdo, 'assignment_type');
    $hasPriority = housekeepingColumnExists($pdo, 'priority');
    $hasEstimatedDuration = housekeepingColumnExists($pdo, 'estimated_duration');

    // If we don't have the required columns for recurring assignments, return early
    if (!$hasIsRecurring || !$hasRecurringPattern) {
        return 0;
    }

    $today = date('Y-m-d');
    $created = 0;

    // Build WHERE clause based on available columns
    $whereConditions = [
        "is_recurring = 1",
        "recurring_pattern IS NOT NULL"
    ];

    if ($hasRecurringEndDate) {
        $whereConditions[] = "(recurring_end_date IS NULL OR recurring_end_date >= ?)";
    }

    $whereConditions[] = "status IN ('completed', 'verified')";

    // Build SELECT columns based on available columns
    $selectColumns = ['*'];

    $sql = "SELECT " . implode(', ', $selectColumns) . " FROM housekeeping_assignments WHERE " . implode(' AND ', $whereConditions);
    $stmt = $pdo->prepare($sql);

    $params = [];
    if ($hasRecurringEndDate) {
        $params[] = $today;
    }

    $stmt->execute($params);
    $recurring = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recurring as $assignment) {
        $lastCreated = $assignment['created_at'];
        $shouldCreate = false;

        switch ($assignment['recurring_pattern']) {
            case 'daily':
                // Create if last assignment was before today
                $shouldCreate = (date('Y-m-d', strtotime($lastCreated)) < $today);
                break;
            case 'weekly':
                // Create if last assignment was more than 7 days ago
                $shouldCreate = (strtotime($lastCreated) < strtotime('-7 days'));
                break;
            case 'monthly':
                // Create if last assignment was more than 30 days ago
                $shouldCreate = (strtotime($lastCreated) < strtotime('-30 days'));
                break;
        }

        if ($shouldCreate) {
            // Build INSERT columns and values based on available columns
            $insertColumns = ['individual_room_id', 'status', 'due_date', 'assigned_to', 'created_by', 'notes'];
            $insertValues = ['?', '?', '?', '?', '?', '?'];
            $insertParams = [
                $assignment['individual_room_id'],
                'pending',
                $today,
                $assignment['assigned_to'],
                $performedBy,
                $assignment['notes']
            ];

            if ($hasAssignmentType) {
                $insertColumns[] = 'assignment_type';
                $insertValues[] = '?';
                $insertParams[] = $assignment['assignment_type'] ?? 'regular_cleaning';
            }
            if ($hasPriority) {
                $insertColumns[] = 'priority';
                $insertValues[] = '?';
                $insertParams[] = $assignment['priority'] ?? 'medium';
            }
            if ($hasIsRecurring) {
                $insertColumns[] = 'is_recurring';
                $insertValues[] = '?';
                $insertParams[] = 1;
            }
            if ($hasRecurringPattern) {
                $insertColumns[] = 'recurring_pattern';
                $insertValues[] = '?';
                $insertParams[] = $assignment['recurring_pattern'];
            }
            if ($hasRecurringEndDate) {
                $insertColumns[] = 'recurring_end_date';
                $insertValues[] = '?';
                $insertParams[] = $assignment['recurring_end_date'];
            }
            if ($hasEstimatedDuration) {
                $insertColumns[] = 'estimated_duration';
                $insertValues[] = '?';
                $insertParams[] = $assignment['estimated_duration'] ?? 30;
            }

            $insertSql = "INSERT INTO housekeeping_assignments (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")";
            $newStmt = $pdo->prepare($insertSql);
            $newStmt->execute($insertParams);
            $newAssignmentId = (int)$pdo->lastInsertId();
            $created++;

            // Log audit trail for recurring assignment creation
            $newData = [
                'individual_room_id' => $assignment['individual_room_id'],
                'status' => 'pending',
                'due_date' => $today,
                'assigned_to' => $assignment['assigned_to'],
                'created_by' => $performedBy,
                'notes' => $assignment['notes'],
            ];
            if ($hasAssignmentType) $newData['assignment_type'] = $assignment['assignment_type'] ?? 'regular_cleaning';
            if ($hasPriority) $newData['priority'] = $assignment['priority'] ?? 'medium';
            if ($hasIsRecurring) $newData['is_recurring'] = 1;
            if ($hasRecurringPattern) $newData['recurring_pattern'] = $assignment['recurring_pattern'];
            if ($hasRecurringEndDate) $newData['recurring_end_date'] = $assignment['recurring_end_date'];
            if ($hasEstimatedDuration) $newData['estimated_duration'] = $assignment['estimated_duration'] ?? 30;

            logHousekeepingAction($newAssignmentId, 'recurring_created', null, $newData, $performedBy);
        }
    }

    return $created;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'add_assignment') {
            $room_id = (int)($_POST['individual_room_id'] ?? 0);
            $due_date = $_POST['due_date'] ?? '';
            $status = $_POST['status'] ?? 'pending';
            $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $notes = trim($_POST['notes'] ?? '');
            $priority = $_POST['priority'] ?? 'medium';
            $assignment_type = $_POST['assignment_type'] ?? 'regular_cleaning';
            $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
            $recurring_pattern = $is_recurring ? ($_POST['recurring_pattern'] ?? null) : null;
            $recurring_end_date = $is_recurring ? ($_POST['recurring_end_date'] ?? null) : null;
            $estimated_duration = (int)($_POST['estimated_duration'] ?? 30);
            $scheduled_time = !empty($_POST['scheduled_time']) ? $_POST['scheduled_time'] : null;
            $started_at = !empty($_POST['started_at']) ? date('Y-m-d H:i:s', strtotime($_POST['started_at'])) : null;

            // Check which columns exist for backward compatibility
            $hasPriority = housekeepingColumnExists($pdo, 'priority');
            $hasAssignmentType = housekeepingColumnExists($pdo, 'assignment_type');
            $hasIsRecurring = housekeepingColumnExists($pdo, 'is_recurring');
            $hasRecurringPattern = housekeepingColumnExists($pdo, 'recurring_pattern');
            $hasRecurringEndDate = housekeepingColumnExists($pdo, 'recurring_end_date');
            $hasEstimatedDuration = housekeepingColumnExists($pdo, 'estimated_duration');
            $hasVerifiedAt = housekeepingColumnExists($pdo, 'verified_at');
            $hasScheduledTime = housekeepingColumnExists($pdo, 'scheduled_time');
            $hasStartedAt = housekeepingColumnExists($pdo, 'started_at');

            // Validation
            if (!$room_id) {
                $error = 'Room is required.';
            } elseif (!$due_date) {
                $error = 'Due date is required.';
            } elseif (!validateDueDate($due_date)) {
                $error = 'Due date cannot be in the past. Please select today or a future date.';
            } elseif (!in_array($status, $validHousekeepingStatuses, true)) {
                $error = 'Invalid housekeeping status.';
            } elseif ($hasPriority && !in_array($priority, $validPriorities, true)) {
                $error = 'Invalid priority level.';
            } elseif ($hasAssignmentType && !in_array($assignment_type, $validAssignmentTypes, true)) {
                $error = 'Invalid assignment type.';
            } elseif ($hasIsRecurring && $is_recurring && !in_array($recurring_pattern, $validRecurringPatterns, true)) {
                $error = 'Invalid recurring pattern.';
            } elseif (!housekeepingRoomExists($pdo, $room_id)) {
                $error = 'Selected room is invalid or inactive.';
            } elseif (!housekeepingUserExists($pdo, $assigned_to)) {
                $error = 'Assigned user is invalid.';
            } elseif (strtotime($due_date) === false) {
                $error = 'Invalid due date format.';
            } else {
                $pdo->beginTransaction();
                $completedAt = in_array($status, ['completed', 'verified'], true) ? date('Y-m-d H:i:s') : null;
                $verifiedAt = ($hasVerifiedAt && $status === 'verified') ? date('Y-m-d H:i:s') : null;

                // Build INSERT columns and values based on available columns
                $insertColumns = ['individual_room_id', 'status', 'due_date', 'assigned_to', 'created_by', 'notes', 'completed_at'];
                $insertValues = ['?', '?', '?', '?', '?', '?', '?'];
                $insertParams = [$room_id, $status, $due_date, $assigned_to, $user['id'] ?? null, $notes, $completedAt];

                if ($hasPriority) {
                    $insertColumns[] = 'priority';
                    $insertValues[] = '?';
                    $insertParams[] = $priority;
                }
                if ($hasAssignmentType) {
                    $insertColumns[] = 'assignment_type';
                    $insertValues[] = '?';
                    $insertParams[] = $assignment_type;
                }
                if ($hasIsRecurring) {
                    $insertColumns[] = 'is_recurring';
                    $insertValues[] = '?';
                    $insertParams[] = $is_recurring;
                }
                if ($hasRecurringPattern) {
                    $insertColumns[] = 'recurring_pattern';
                    $insertValues[] = '?';
                    $insertParams[] = $recurring_pattern;
                }
                if ($hasRecurringEndDate) {
                    $insertColumns[] = 'recurring_end_date';
                    $insertValues[] = '?';
                    $insertParams[] = $recurring_end_date;
                }
                if ($hasEstimatedDuration) {
                    $insertColumns[] = 'estimated_duration';
                    $insertValues[] = '?';
                    $insertParams[] = $estimated_duration;
                }
                if ($hasVerifiedAt) {
                    $insertColumns[] = 'verified_at';
                    $insertValues[] = '?';
                    $insertParams[] = $verifiedAt;
                }
                if ($hasScheduledTime) {
                    $insertColumns[] = 'scheduled_time';
                    $insertValues[] = '?';
                    $insertParams[] = $scheduled_time;
                }
                if ($hasStartedAt) {
                    $insertColumns[] = 'started_at';
                    $insertValues[] = '?';
                    $insertParams[] = $started_at;
                }

                $insertSql = "INSERT INTO housekeeping_assignments (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")";
                $stmt = $pdo->prepare($insertSql);
                $stmt->execute($insertParams);
                $newAssignmentId = (int)$pdo->lastInsertId();

                reconcileIndividualRoomHousekeeping($pdo, $room_id, $user['id'] ?? null);

                // Log audit trail
                $newData = [
                    'individual_room_id' => $room_id,
                    'status' => $status,
                    'due_date' => $due_date,
                    'assigned_to' => $assigned_to,
                    'notes' => $notes,
                    'completed_at' => $completedAt,
                ];
                if ($hasPriority) $newData['priority'] = $priority;
                if ($hasAssignmentType) $newData['assignment_type'] = $assignment_type;
                if ($hasIsRecurring) $newData['is_recurring'] = $is_recurring;
                if ($hasRecurringPattern) $newData['recurring_pattern'] = $recurring_pattern;
                if ($hasRecurringEndDate) $newData['recurring_end_date'] = $recurring_end_date;
                if ($hasEstimatedDuration) $newData['estimated_duration'] = $estimated_duration;
                if ($hasVerifiedAt) $newData['verified_at'] = $verifiedAt;

                logHousekeepingAction($newAssignmentId, 'created', null, $newData, $user['id'] ?? null, $user['username'] ?? null);

                $pdo->commit();
                $message = 'Assignment created successfully.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                }
            }
        } elseif ($action === 'update_assignment') {
            $id = (int)($_POST['id'] ?? 0);
            $room_id = (int)($_POST['individual_room_id'] ?? 0);
            $due_date = $_POST['due_date'] ?? '';
            $status = $_POST['status'] ?? 'pending';
            $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $notes = trim($_POST['notes'] ?? '');
            $priority = $_POST['priority'] ?? 'medium';
            $assignment_type = $_POST['assignment_type'] ?? 'regular_cleaning';
            $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
            $recurring_pattern = $is_recurring ? ($_POST['recurring_pattern'] ?? null) : null;
            $recurring_end_date = $is_recurring ? ($_POST['recurring_end_date'] ?? null) : null;
            $estimated_duration = (int)($_POST['estimated_duration'] ?? 30);
            $actual_duration = !empty($_POST['actual_duration']) ? (int)$_POST['actual_duration'] : null;
            $scheduled_time = !empty($_POST['scheduled_time']) ? $_POST['scheduled_time'] : null;
            $started_at_input = $_POST['started_at'] ?? '';
            $started_at = !empty($started_at_input) ? date('Y-m-d H:i:s', strtotime($started_at_input)) : null;

            // Check which columns exist for backward compatibility
            $hasPriority = housekeepingColumnExists($pdo, 'priority');
            $hasAssignmentType = housekeepingColumnExists($pdo, 'assignment_type');
            $hasIsRecurring = housekeepingColumnExists($pdo, 'is_recurring');
            $hasRecurringPattern = housekeepingColumnExists($pdo, 'recurring_pattern');
            $hasRecurringEndDate = housekeepingColumnExists($pdo, 'recurring_end_date');
            $hasEstimatedDuration = housekeepingColumnExists($pdo, 'estimated_duration');
            $hasActualDuration = housekeepingColumnExists($pdo, 'actual_duration');
            $hasVerifiedBy = housekeepingColumnExists($pdo, 'verified_by');
            $hasVerifiedAt = housekeepingColumnExists($pdo, 'verified_at');
            $hasScheduledTime = housekeepingColumnExists($pdo, 'scheduled_time');
            $hasStartedAt = housekeepingColumnExists($pdo, 'started_at');

            // Validation
            if (!$id || !$room_id || !$due_date) {
                $error = 'Room and due date are required.';
            } elseif (strtotime($due_date) === false) {
                $error = 'Invalid due date format.';
            } elseif (!in_array($status, $validHousekeepingStatuses, true)) {
                $error = 'Invalid housekeeping status.';
            } elseif ($hasPriority && !in_array($priority, $validPriorities, true)) {
                $error = 'Invalid priority level.';
            } elseif ($hasAssignmentType && !in_array($assignment_type, $validAssignmentTypes, true)) {
                $error = 'Invalid assignment type.';
            } elseif ($hasIsRecurring && $is_recurring && !in_array($recurring_pattern, $validRecurringPatterns, true)) {
                $error = 'Invalid recurring pattern.';
            } elseif (!housekeepingRoomExists($pdo, $room_id)) {
                $error = 'Selected room is invalid or inactive.';
            } elseif (!housekeepingUserExists($pdo, $assigned_to)) {
                $error = 'Assigned user is invalid.';
            } else {
                $pdo->beginTransaction();
                $existsStmt = $pdo->prepare("SELECT id, individual_room_id, status FROM housekeeping_assignments WHERE id = ?");
                $existsStmt->execute([$id]);
                $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    throw new RuntimeException('Assignment does not exist.');
                }
                if (($existing['status'] ?? '') === 'verified') {
                    throw new DomainException('Verified assignments are locked and cannot be edited.');
                }

                // Auto-set verified_by when status changes to verified
                $verifiedBy = null;
                $verifiedAt = null;
                if ($hasVerifiedBy && $hasVerifiedAt && $status === 'verified' && $existing['status'] !== 'verified') {
                    $verifiedBy = $user['id'] ?? null;
                    $verifiedAt = date('Y-m-d H:i:s');
                } elseif ($status !== 'verified') {
                    $verifiedAt = null;
                }

                $completedAt = in_array($status, ['completed', 'verified'], true) ? date('Y-m-d H:i:s') : null;
                if ($existing['status'] === 'completed' && $status !== 'completed' && $status !== 'verified') {
                    $completedAt = null;
                }

                // Build UPDATE SET clause based on available columns
                $setColumns = ['individual_room_id=?', 'status=?', 'due_date=?', 'assigned_to=?', 'notes=?', 'completed_at=?'];
                $updateParams = [$room_id, $status, $due_date, $assigned_to, $notes, $completedAt];

                if ($hasPriority) {
                    $setColumns[] = 'priority=?';
                    $updateParams[] = $priority;
                }
                if ($hasAssignmentType) {
                    $setColumns[] = 'assignment_type=?';
                    $updateParams[] = $assignment_type;
                }
                if ($hasIsRecurring) {
                    $setColumns[] = 'is_recurring=?';
                    $updateParams[] = $is_recurring;
                }
                if ($hasRecurringPattern) {
                    $setColumns[] = 'recurring_pattern=?';
                    $updateParams[] = $recurring_pattern;
                }
                if ($hasRecurringEndDate) {
                    $setColumns[] = 'recurring_end_date=?';
                    $updateParams[] = $recurring_end_date;
                }
                if ($hasEstimatedDuration) {
                    $setColumns[] = 'estimated_duration=?';
                    $updateParams[] = $estimated_duration;
                }
                if ($hasActualDuration) {
                    $setColumns[] = 'actual_duration=?';
                    $updateParams[] = $actual_duration;
                }
                if ($hasVerifiedBy) {
                    $setColumns[] = 'verified_by=?';
                    $updateParams[] = $verifiedBy;
                }
                if ($hasVerifiedAt) {
                    $setColumns[] = 'verified_at=?';
                    $updateParams[] = $verifiedAt;
                }
                if ($hasScheduledTime) {
                    $setColumns[] = 'scheduled_time=?';
                    $updateParams[] = $scheduled_time;
                }
                if ($hasStartedAt) {
                    $setColumns[] = 'started_at=?';
                    $updateParams[] = $started_at;
                }

                $updateParams[] = $id; // WHERE id=?

                $updateSql = "UPDATE housekeeping_assignments SET " . implode(', ', $setColumns) . " WHERE id=?";
                $stmt = $pdo->prepare($updateSql);
                $stmt->execute($updateParams);

                // Get updated data for audit log
                $updatedStmt = $pdo->prepare("SELECT * FROM housekeeping_assignments WHERE id = ?");
                $updatedStmt->execute([$id]);
                $newData = $updatedStmt->fetch(PDO::FETCH_ASSOC);

                // Determine action type
                $action = 'updated';
                if ($existing['status'] !== $status) {
                    $action = 'status_changed';
                }
                if (($existing['assigned_to'] ?? null) != $assigned_to) {
                    $action = $assigned_to ? 'assigned' : 'unassigned';
                }
                if ($hasPriority && ($existing['priority'] ?? null) !== $priority) {
                    $action = 'priority_changed';
                }

                logHousekeepingAction($id, $action, $existing, $newData, $user['id'] ?? null, $user['username'] ?? null);

                reconcileIndividualRoomHousekeeping($pdo, $room_id, $user['id'] ?? null);
                if ((int)$existing['individual_room_id'] !== $room_id) {
                    reconcileIndividualRoomHousekeeping($pdo, (int)$existing['individual_room_id'], $user['id'] ?? null);
                }
                $pdo->commit();
                $message = 'Assignment updated successfully.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                }
            }
        } elseif ($action === 'delete_assignment') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Invalid assignment selected.';
            } else {
                $pdo->beginTransaction();
                $rowStmt = $pdo->prepare("SELECT individual_room_id FROM housekeeping_assignments WHERE id = ?");
                $rowStmt->execute([$id]);
                $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException('Assignment not found.');
                }

                // Get assignment data before deletion for audit log
                $dataStmt = $pdo->prepare("SELECT * FROM housekeeping_assignments WHERE id = ?");
                $dataStmt->execute([$id]);
                $deletedData = $dataStmt->fetch(PDO::FETCH_ASSOC);

                $pdo->prepare("DELETE FROM housekeeping_assignments WHERE id = ?")->execute([$id]);

                reconcileIndividualRoomHousekeeping($pdo, (int)$row['individual_room_id'], $user['id'] ?? null);

                // Log audit trail
                logHousekeepingAction($id, 'deleted', $deletedData, null, $user['id'] ?? null, $user['username'] ?? null);

                $pdo->commit();
                $message = 'Assignment deleted successfully.';
            }
        } elseif ($action === 'auto_create_checkout') {
            $pdo->beginTransaction();
            $created = autoCreateCheckoutCleanup($pdo, $user['id'] ?? null);
            $pdo->commit();
            $message = "Auto-created {$created} checkout cleanup assignments.";
        } elseif ($action === 'bulk_assign_occupied') {
            $room_ids_raw = $_POST['room_ids'] ?? '[]';
            $room_ids = is_array($room_ids_raw) ? $room_ids_raw : (json_decode((string)$room_ids_raw, true) ?: []);
            $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $priority = $_POST['priority'] ?? 'medium';

            // Check which columns exist for backward compatibility
            $hasAssignmentType = housekeepingColumnExists($pdo, 'assignment_type');
            $hasPriority = housekeepingColumnExists($pdo, 'priority');

            if (empty($room_ids)) {
                $error = 'No rooms selected.';
            } else {
                $pdo->beginTransaction();
                $created = 0;
                $today = date('Y-m-d');

                foreach ($room_ids as $room_id) {
                    $room_id = (int)$room_id;
                    if (!housekeepingRoomExists($pdo, $room_id)) {
                        continue;
                    }

                    // Check if pending assignment already exists
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM housekeeping_assignments
                        WHERE individual_room_id = ? AND status IN ('pending', 'in_progress')
                    ");
                    $checkStmt->execute([$room_id]);
                    if ($checkStmt->fetch()) {
                        continue;
                    }

                    // Build INSERT columns and values based on available columns
                    $insertColumns = ['individual_room_id', 'status', 'due_date', 'assigned_to', 'created_by'];
                    $insertValues = ['?', '?', '?', '?', '?'];
                    $insertParams = [$room_id, 'pending', $today, $assigned_to, $user['id'] ?? null];

                    if ($hasAssignmentType) {
                        $insertColumns[] = 'assignment_type';
                        $insertValues[] = '?';
                        $insertParams[] = 'regular_cleaning';
                    }
                    if ($hasPriority) {
                        $insertColumns[] = 'priority';
                        $insertValues[] = '?';
                        $insertParams[] = $priority;
                    }

                    $insertSql = "INSERT INTO housekeeping_assignments (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")";
                    $stmt = $pdo->prepare($insertSql);
                    $stmt->execute($insertParams);
                    $newAssignmentId = (int)$pdo->lastInsertId();
                    $created++;

                    // Log audit trail for bulk created assignment
                    $newData = [
                        'individual_room_id' => $room_id,
                        'status' => 'pending',
                        'due_date' => $today,
                        'assigned_to' => $assigned_to,
                        'created_by' => $user['id'] ?? null,
                    ];
                    if ($hasAssignmentType) $newData['assignment_type'] = 'regular_cleaning';
                    if ($hasPriority) $newData['priority'] = $priority;

                    logHousekeepingAction($newAssignmentId, 'created', null, $newData, $user['id'] ?? null, $user['username'] ?? null);

                    reconcileIndividualRoomHousekeeping($pdo, $room_id, $user['id'] ?? null);
                }

                $pdo->commit();
                $message = "Bulk assigned {$created} rooms for housekeeping.";
            }
        } elseif ($action === 'verify_assignment') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Invalid assignment selected.';
            } else {
                $hasVerifiedBy = housekeepingColumnExists($pdo, 'verified_by');
                $hasVerifiedAt = housekeepingColumnExists($pdo, 'verified_at');

                // If we don't have the required columns for verification, show error
                if (!$hasVerifiedBy || !$hasVerifiedAt) {
                    $error = 'Verification feature requires database migration 004. Please contact administrator.';
                } else {
                    $pdo->beginTransaction();
                    // Get assignment data before verification for audit log
                    $dataStmt = $pdo->prepare("SELECT * FROM housekeeping_assignments WHERE id = ?");
                    $dataStmt->execute([$id]);
                    $beforeData = $dataStmt->fetch(PDO::FETCH_ASSOC);

                    $stmt = $pdo->prepare("
                        UPDATE housekeeping_assignments
                        SET status = 'verified', verified_by = ?, verified_at = NOW()
                        WHERE id = ? AND status = 'completed'
                    ");
                    $stmt->execute([$user['id'] ?? null, $id]);

                    if ($stmt->rowCount() > 0) {
                        // Get updated data for audit log
                        $dataStmt->execute([$id]);
                        $afterData = $dataStmt->fetch(PDO::FETCH_ASSOC);

                        // Log audit trail
                        logHousekeepingAction($id, 'verified', $beforeData, $afterData, $user['id'] ?? null, $user['username'] ?? null);

                        if (!empty($beforeData['individual_room_id'])) {
                            reconcileIndividualRoomHousekeeping($pdo, (int)$beforeData['individual_room_id'], $user['id'] ?? null);
                        }

                        $message = 'Assignment verified successfully.';
                    } else {
                        $error = 'Assignment not found or not in completed status.';
                    }

                    $pdo->commit();
                }
            }
        } elseif ($action === 'mark_started') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Invalid assignment selected.';
            } else {
                $pdo->beginTransaction();
                $dataStmt = $pdo->prepare("SELECT * FROM housekeeping_assignments WHERE id = ?");
                $dataStmt->execute([$id]);
                $beforeData = $dataStmt->fetch(PDO::FETCH_ASSOC);
                if (!$beforeData) throw new RuntimeException('Assignment not found.');
                $hasStartedAtCol = housekeepingColumnExists($pdo, 'started_at');
                if ($hasStartedAtCol) {
                    $pdo->prepare("UPDATE housekeeping_assignments SET started_at = COALESCE(started_at, NOW()), status = CASE WHEN status = 'pending' THEN 'in_progress' ELSE status END WHERE id = ?")
                        ->execute([$id]);
                } else {
                    $pdo->prepare("UPDATE housekeeping_assignments SET status = 'in_progress' WHERE id = ? AND status = 'pending'")
                        ->execute([$id]);
                }
                $dataStmt->execute([$id]);
                $afterData = $dataStmt->fetch(PDO::FETCH_ASSOC);
                logHousekeepingAction($id, 'status_changed', $beforeData, $afterData, $user['id'] ?? null, $user['username'] ?? null);
                reconcileIndividualRoomHousekeeping($pdo, (int)$beforeData['individual_room_id'], $user['id'] ?? null);
                $pdo->commit();
                $message = 'Assignment marked as started.';
            }
        } elseif ($action === 'mark_complete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Invalid assignment selected.';
            } else {
                $pdo->beginTransaction();
                $dataStmt = $pdo->prepare("SELECT * FROM housekeeping_assignments WHERE id = ?");
                $dataStmt->execute([$id]);
                $beforeData = $dataStmt->fetch(PDO::FETCH_ASSOC);
                if (!$beforeData) throw new RuntimeException('Assignment not found.');
                $hasStartedAtCol = housekeepingColumnExists($pdo, 'started_at');
                $setClause = "status = 'completed', completed_at = COALESCE(completed_at, NOW())";
                if ($hasStartedAtCol) {
                    $setClause .= ", started_at = COALESCE(started_at, NOW())";
                }
                $pdo->prepare("UPDATE housekeeping_assignments SET {$setClause} WHERE id = ?")
                    ->execute([$id]);
                $dataStmt->execute([$id]);
                $afterData = $dataStmt->fetch(PDO::FETCH_ASSOC);
                logHousekeepingAction($id, 'status_changed', $beforeData, $afterData, $user['id'] ?? null, $user['username'] ?? null);
                reconcileIndividualRoomHousekeeping($pdo, (int)$beforeData['individual_room_id'], $user['id'] ?? null);
                $pdo->commit();
                $message = 'Assignment marked as completed.';
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof DomainException ? $e->getMessage() : ('Database error: ' . $e->getMessage());
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    }
}

// Reconcile all rooms with open assignments
try {
    $roomRows = $pdo->query("SELECT DISTINCT individual_room_id FROM housekeeping_assignments")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($roomRows as $roomId) {
        reconcileIndividualRoomHousekeeping($pdo, (int)$roomId, $user['id'] ?? null);
    }
} catch (Throwable $syncError) {
    error_log('Housekeeping reconciliation warning: ' . $syncError->getMessage());
}

// Get data for the page
$roomsStmt = $pdo->query("SELECT id, room_number, room_name, status, housekeeping_status FROM individual_rooms WHERE is_active = 1 ORDER BY room_number ASC");
$rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

$usersStmt = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 ORDER BY username ASC");
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get occupied rooms for quick assignment
$occupiedRooms = getOccupiedRooms($pdo);

// Get rooms needing checkout cleanup
$checkoutCleanupRooms = getCheckoutCleanupRooms($pdo);

// Get staff workload
$staffWorkload = getStaffWorkload($pdo);

// Get all assignments with enhanced sorting
// Backward compatible: works with or without migration 004 columns
$hasPriority = housekeepingColumnExists($pdo, 'priority');
$hasVerifiedBy = housekeepingColumnExists($pdo, 'verified_by');

// Build ORDER BY clause based on available columns
$orderByClauses = [
    "CASE ha.status WHEN 'pending' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'completed' THEN 3 WHEN 'verified' THEN 4 WHEN 'blocked' THEN 5 ELSE 99 END"
];

if ($hasPriority) {
    $orderByClauses[] = "CASE ha.priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END";
}

$orderByClauses[] = "ha.due_date ASC";
$orderByClauses[] = "ha.created_at DESC";

$assignmentsStmt = $pdo->query(
    "
    SELECT ha.*, ir.room_number, ir.room_name, u.username as assigned_to_name, creator.username as created_by_name" .
        ($hasVerifiedBy ? ", verifier.username as verified_by_name" : "") . "
    FROM housekeeping_assignments ha
    LEFT JOIN individual_rooms ir ON ha.individual_room_id = ir.id
    LEFT JOIN admin_users u ON ha.assigned_to = u.id
    LEFT JOIN admin_users creator ON ha.created_by = creator.id" .
        ($hasVerifiedBy ? "
    LEFT JOIN admin_users verifier ON ha.verified_by = verifier.id" : "") . "
    ORDER BY " . implode(', ', $orderByClauses)
);
$assignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
// Backward compatible: works with or without migration 004 columns
$hasVerifiedAt = housekeepingColumnExists($pdo, 'verified_at');
$statsToday = date('Y-m-d');
$stats = [
    'today_total' => 0,
    'pending' => 0,
    'in_progress' => 0,
    'completed_today' => 0,
    'verified_today' => 0,
    'blocked' => 0,
    'high_priority' => 0,
];
foreach ($assignments as $a) {
    $status = (string)($a['status'] ?? '');
    if (isset($stats[$status])) {
        $stats[$status] = ($stats[$status] ?? 0) + 1;
    }

    if ($hasPriority && ($a['priority'] ?? '') === 'high' && in_array($status, ['pending', 'in_progress', 'completed', 'blocked'], true)) {
        $stats['high_priority']++;
    }

    $dueDateKey = substr((string)($a['due_date'] ?? ''), 0, 10);
    if ($dueDateKey === $statsToday) {
        $stats['today_total']++;
    }

    $completedDateKey = substr((string)($a['completed_at'] ?? ''), 0, 10);
    if ($completedDateKey === $statsToday) {
        $stats['completed_today']++;
    }

    $verifiedDateKey = $hasVerifiedAt ? substr((string)($a['verified_at'] ?? ''), 0, 10) : '';
    if ($status === 'verified' && $verifiedDateKey === $statsToday) {
        $stats['verified_today']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Housekeeping - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/housekeeping.css?v=<?php echo @filemtime(__DIR__ . '/css/housekeeping.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content">
        <div class="page-header">
            <h2><i class="fas fa-broom"></i> Housekeeping Management</h2>
            <div class="hk-page-header__actions">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="auto_create_checkout">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button class="btn btn-warning" type="button" data-help="Auto-Create Checkout Cleanup|Automatically generate cleaning assignments for every room that has a guest checking out and needs housekeeping." onclick="hkConfirm(this.closest('form'), 'Auto-create checkout cleanup assignments for all rooms that need it?', 'Auto-Create', 'btn-warning')">
                        <i class="fas fa-magic"></i> Auto-Create Checkout Cleanup
                    </button>
                </form>
                <button class="btn btn-primary" type="button" onclick="openModal()" data-help="Add Assignment|Manually create a new housekeeping task for a room and assign it to a staff member."><i class="fas fa-plus"></i> Add Assignment</button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success hk-alert hk-alert--success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger hk-alert hk-alert--error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Dashboard Statistics -->
        <div class="hk-dashboard" id="hkStatsDashboard">
            <button type="button" class="hk-stat-card hk-stat-card--interactive today_total" data-stat-key="today_total" data-stat-title="Today's Housekeeping Tasks" data-stat-description="All housekeeping assignments scheduled for today.">
                <span class="hk-stat-card__value"><?php echo (int)$stats['today_total']; ?></span>
                <span class="hk-stat-card__label"><i class="fas fa-calendar-day"></i> Today's Tasks</span>
                <span class="hk-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="hk-stat-card hk-stat-card--interactive pending" data-stat-key="pending" data-stat-title="Pending Tasks" data-stat-description="Tasks waiting to be started.">
                <span class="hk-stat-card__value"><?php echo (int)$stats['pending']; ?></span>
                <span class="hk-stat-card__label"><i class="fas fa-clock"></i> Pending Tasks</span>
                <span class="hk-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="hk-stat-card hk-stat-card--interactive in_progress" data-stat-key="in_progress" data-stat-title="In Progress" data-stat-description="Tasks currently being cleaned.">
                <span class="hk-stat-card__value"><?php echo (int)$stats['in_progress']; ?></span>
                <span class="hk-stat-card__label"><i class="fas fa-spinner"></i> In Progress</span>
                <span class="hk-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="hk-stat-card hk-stat-card--interactive completed" data-stat-key="completed_today" data-stat-title="Completed Today" data-stat-description="Housekeeping assignments completed today.">
                <span class="hk-stat-card__value"><?php echo (int)$stats['completed_today']; ?></span>
                <span class="hk-stat-card__label"><i class="fas fa-check"></i> Completed Today</span>
                <span class="hk-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="hk-stat-card hk-stat-card--interactive verified" data-stat-key="verified_today" data-stat-title="Verified Today" data-stat-description="Assignments verified by supervisors today.">
                <span class="hk-stat-card__value"><?php echo (int)$stats['verified_today']; ?></span>
                <span class="hk-stat-card__label"><i class="fas fa-check-double"></i> Verified Today</span>
                <span class="hk-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="hk-stat-card hk-stat-card--interactive high_priority" data-stat-key="high_priority" data-stat-title="High Priority" data-stat-description="Urgent assignments needing immediate attention.">
                <span class="hk-stat-card__value"><?php echo (int)$stats['high_priority']; ?></span>
                <span class="hk-stat-card__label"><i class="fas fa-exclamation-triangle"></i> High Priority</span>
                <span class="hk-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="hk-stat-card hk-stat-card--interactive blocked" data-stat-key="blocked" data-stat-title="Blocked" data-stat-description="Assignments currently blocked and needing unblock action.">
                <span class="hk-stat-card__value"><?php echo (int)$stats['blocked']; ?></span>
                <span class="hk-stat-card__label"><i class="fas fa-ban"></i> Blocked</span>
                <span class="hk-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
        </div>
        <script type="application/json" id="hkAssignmentData">
            <?php echo json_encode(array_map(static function (array $row): array {
                return [
                    'status' => (string)($row['status'] ?? ''),
                    'priority' => (string)($row['priority'] ?? ''),
                    'type' => (string)($row['assignment_type'] ?? ''),
                    'room' => trim((string)($row['room_number'] ?? '') . ' ' . (string)($row['room_name'] ?? '')),
                    'assignee' => (string)($row['assigned_to_name'] ?? 'Unassigned'),
                    'dueDate' => (string)($row['due_date'] ?? ''),
                    'notes' => (string)($row['notes'] ?? ''),
                    'completedAt' => (string)($row['completed_at'] ?? ''),
                    'verifiedAt' => (string)($row['verified_at'] ?? ''),
                ];
            }, $assignments), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]'; ?>
        </script>

        <!-- Occupied Rooms Section -->
        <?php if (!empty($occupiedRooms)): ?>
            <div class="hk-section">
                <h3><i class="fas fa-bed"></i> Occupied Rooms (<?php echo count($occupiedRooms); ?>)</h3>
                <form method="POST" id="bulkAssignForm">
                    <input type="hidden" name="action" value="bulk_assign_occupied">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="room_ids" id="selectedRoomIds">
                    <div class="bulk-actions">
                        <select name="assigned_to" id="bulkAssignTo" class="hk-inline-control">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="priority" class="hk-inline-control">
                            <option value="medium">Medium Priority</option>
                            <option value="high">High Priority</option>
                            <option value="low">Low Priority</option>
                        </select>
                        <button type="button" class="btn-quick" onclick="selectAllOccupied()">
                            <i class="fas fa-check-square"></i> Select All
                        </button>
                        <button type="submit" class="btn btn-primary hk-bulk-assign-btn" id="bulkAssignBtn" disabled>
                            <i class="fas fa-plus"></i> Assign Selected (<span id="selectedCount">0</span>)
                        </button>
                    </div>
                    <p class="hk-bulk-helper">
                        <i class="fas fa-hand-pointer"></i> Click a room card to select it, then choose a staff member and click Assign Selected.
                    </p>
                    <div class="occupied-rooms-list">
                        <?php foreach ($occupiedRooms as $room): ?>
                            <div class="occupied-room-card <?php echo $room['occupancy_type'] === 'checkout_today' ? 'checkout-today' : ''; ?>"
                                data-room-id="<?php echo $room['id']; ?>"
                                onclick="toggleRoomCard(this)"
                                role="button"
                                tabindex="0"
                                onkeydown="if(event.key==='Enter'||event.key===' ')toggleRoomCard(this)">
                                <div class="room-header">
                                    <span class="room-number"><?php echo htmlspecialchars($room['room_number'] . ' ' . ($room['room_name'] ?? '')); ?></span>
                                    <span class="occupancy-badge"><?php echo $room['occupancy_type'] === 'checkout_today' ? '<i class="fas fa-sign-out-alt"></i> Checkout Today' : '<i class="fas fa-bed"></i> Occupied'; ?></span>
                                </div>
                                <div class="guest-info"><i class="fas fa-user"></i> <?php echo htmlspecialchars($room['guest_name'] ?? 'Guest'); ?></div>
                                <div class="date-info"><i class="fas fa-calendar-alt"></i> Checkout: <strong><?php echo htmlspecialchars(date('M j, Y', strtotime($room['check_out_date']))); ?></strong></div>
                                <div class="card-select-indicator">
                                    <input type="checkbox" class="room-checkbox" value="<?php echo $room['id']; ?>" tabindex="-1" onclick="event.stopPropagation()">
                                    <span class="select-label"><i class="fas fa-circle-check"></i> Selected</span>
                                    <span class="unselect-label"><i class="far fa-circle"></i> Click to select</span>
                                </div>
                                <button type="button" class="btn-quick-assign" onclick="event.stopPropagation(); quickAssignRoom(<?php echo $room['id']; ?>)" title="Assign this room individually">
                                    <i class="fas fa-bolt"></i> Quick Assign
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Staff Workload Section -->
        <?php if (!empty($staffWorkload)): ?>
            <div class="hk-section">
                <h3><i class="fas fa-users"></i> Staff Workload</h3>
                <table class="staff-workload-table">
                    <thead>
                        <tr>
                            <th>Staff Member</th>
                            <th>Active Tasks</th>
                            <th>High Priority Pending</th>
                            <th>Completed Today</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staffWorkload as $staff): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($staff['username']); ?></td>
                                <td><span style="font-weight:700;"><?php echo $staff['active_tasks']; ?></span></td>
                                <td>
                                    <?php if ($staff['high_priority_pending'] > 0): ?>
                                        <span style="color:#dc2626; font-weight:700;"><?php echo $staff['high_priority_pending']; ?></span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td><span style="color:#1f7a45; font-weight:600;"><?php echo $staff['completed_today']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Assignments Table -->
        <div class="table-card">
            <div class="hk-table-toolbar">
                <h3 class="hk-table-toolbar__title"><i class="fas fa-list-check"></i> All Assignments (<?php echo count($assignments); ?>)</h3>
                <div class="hk-table-toolbar__filters">
                    <input type="text" id="tableSearch" class="hk-inline-control hk-inline-control--search" placeholder="Search room, staff…" oninput="filterTable()">
                    <select id="tableStatusFilter" class="hk-inline-control" onchange="filterTable()">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="verified">Verified</option>
                        <option value="blocked">Blocked</option>
                    </select>
                </div>
            </div>
            <table id="assignmentsTable">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Type</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Scheduled</th>
                        <th>Assigned To</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:24px;color:#9ca3af;">No housekeeping assignments yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($assignments as $row): ?>
                            <tr data-status="<?php echo htmlspecialchars((string)$row['status']); ?>" data-priority="<?php echo htmlspecialchars((string)($row['priority'] ?? '')); ?>" data-type="<?php echo htmlspecialchars((string)($row['assignment_type'] ?? '')); ?>" data-room="<?php echo htmlspecialchars(trim((string)($row['room_number'] ?? '') . ' ' . (string)($row['room_name'] ?? ''))); ?>" data-assignee="<?php echo htmlspecialchars((string)($row['assigned_to_name'] ?? 'Unassigned')); ?>" data-due-date="<?php echo htmlspecialchars((string)($row['due_date'] ?? '')); ?>">
                                <td><strong><?php echo htmlspecialchars($row['room_number'] . ' ' . ($row['room_name'] ?? '')); ?></strong></td>
                                <td><span class="type-badge <?php echo htmlspecialchars($row['assignment_type'] ?? ''); ?>"><?php echo ucfirst(str_replace('_', ' ', $row['assignment_type'] ?? '')); ?></span></td>
                                <td><span class="priority-badge <?php echo htmlspecialchars($row['priority'] ?? ''); ?>"><?php echo ucfirst($row['priority'] ?? ''); ?></span></td>
                                <td><span class="status-pill <?php echo htmlspecialchars($row['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?></span></td>
                                <td>
                                    <span><?php echo date('M j', strtotime($row['due_date'])); ?></span>
                                    <?php if (!empty($row['scheduled_time'])): ?>
                                        <br><small style="color:#3b5bdb; font-weight:600;"><i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($row['scheduled_time'])); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($row['started_at'])): ?>
                                        <br><small style="color:#0c8d6c;"><i class="fas fa-play-circle"></i> <?php echo date('H:i', strtotime($row['started_at'])); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($row['completed_at'])): ?>
                                        <br><small style="color:#1e7a34;"><i class="fas fa-check-circle"></i> <?php echo date('H:i', strtotime($row['completed_at'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['assigned_to_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars(mb_strimwidth($row['notes'] ?? '', 0, 30, '...')); ?></td>
                                <td>
                                    <div class="hk-row-actions">
                                        <?php if ($row['status'] === 'pending'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="mark_started">
                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <button class="btn btn-warning btn-sm" type="button" title="Mark as started" data-help="Start|Mark this cleaning assignment as in progress and record the start time." onclick="hkConfirm(this.closest('form'), 'Mark this assignment as started?', 'Start', 'btn-warning')"><i class="fas fa-play"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (in_array($row['status'], ['pending', 'in_progress'], true)): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="mark_complete">
                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <button class="btn btn-success btn-sm" type="button" title="Mark done" data-help="Mark Done|Mark this cleaning assignment as completed. The room is then ready for a supervisor to verify." onclick="hkConfirm(this.closest('form'), 'Mark this assignment as completed?', 'Mark Done', 'btn-success')"><i class="fas fa-check"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($row['status'] !== 'verified'): ?>
                                            <button class="btn btn-info btn-sm" type="button" onclick='editAssignment(<?php echo htmlspecialchars((string)json_encode($row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                                        <?php endif; ?>
                                        <button class="btn btn-secondary btn-sm" type="button" onclick='viewAuditLog(<?php echo $row['id']; ?>, "<?php echo htmlspecialchars($row['room_number'] . ' ' . ($row['room_name'] ?? '')); ?>")' title="View History"><i class="fas fa-history"></i></button>
                                        <?php if ($row['status'] === 'completed'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="verify_assignment">
                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <button class="btn btn-success btn-sm" type="button" title="Verify" data-help="Verify|Confirm the completed cleaning meets standard and close out this assignment. This cannot be undone." onclick="hkConfirm(this.closest('form'), 'Mark this assignment as verified? This cannot be undone.', 'Verify', 'btn-success')"><i class="fas fa-check-double"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_assignment">
                                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <button class="btn btn-danger btn-sm" type="button" onclick="hkConfirm(this.closest('form'), 'Delete this assignment? This cannot be undone.', 'Delete', 'btn-danger')"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php renderAdminModalStart('assignmentModal', 'Add Assignment', 'housekeeping-modal-content'); ?>
    <form method="POST" id="assignmentForm">
        <input type="hidden" name="action" id="formAction" value="add_assignment">
        <input type="hidden" name="id" id="assignmentId">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <div class="form-group">
            <label>Room *</label>
            <select name="individual_room_id" id="roomSelect" required>
                <option value="">Select room</option>
                <?php foreach ($rooms as $r): ?>
                    <option value="<?php echo $r['id']; ?>" data-status="<?php echo $r['status']; ?>" data-hk-status="<?php echo $r['housekeeping_status'] ?? ''; ?>">
                        <?php echo htmlspecialchars($r['room_number'] . ' ' . ($r['room_name'] ?? '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="color: #6b7280; font-size: 12px;">Room status will be shown when selected</small>
        </div>
        <div class="form-group">
            <label>Assignment Type *</label>
            <select name="assignment_type" id="assignmentType" required>
                <option value="regular_cleaning">Regular Cleaning</option>
                <option value="checkout_cleanup">Checkout Cleanup (High Priority)</option>
                <option value="deep_clean">Deep Clean</option>
                <option value="maintenance">Maintenance</option>
                <option value="turn_down">Turn Down Service</option>
            </select>
        </div>
        <div class="form-group">
            <label>Priority *</label>
            <select name="priority" id="priority" required>
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="low">Low</option>
            </select>
        </div>
        <div class="form-group">
            <label>Due Date *</label>
            <input type="date" name="due_date" id="due_date" required min="<?php echo date('Y-m-d'); ?>">
            <small style="color: #6b7280; font-size: 12px;">Due date cannot be in the past</small>
        </div>
        <div class="form-group">
            <label>Scheduled Time <small style="color:#9ca3af;">(optional)</small></label>
            <input type="time" name="scheduled_time" id="scheduled_time" step="300">
            <small style="color: #6b7280; font-size: 12px;">Specific time of day for this task (e.g. 09:00, 14:30)</small>
        </div>
        <div class="form-group">
            <label>Started At <small style="color:#9ca3af;">(auto-stamped or enter manually)</small></label>
            <input type="datetime-local" name="started_at" id="started_at">
            <small style="color: #6b7280; font-size: 12px;">When staff actually began — leave blank to stamp automatically</small>
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="status" id="status">
                <option value="pending">Pending</option>
                <option value="in_progress">In Progress</option>
                <option value="completed">Completed</option>
                <option value="verified">Verified</option>
                <option value="blocked">Blocked</option>
            </select>
        </div>
        <div class="form-group">
            <label>Assigned To</label>
            <select name="assigned_to" id="assigned_to">
                <option value="">Unassigned</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Estimated Duration (minutes)</label>
            <input type="number" name="estimated_duration" id="estimated_duration" value="30" min="5" step="5">
        </div>
        <div class="form-group">
            <label>Actual Duration (minutes)</label>
            <input type="number" name="actual_duration" id="actual_duration" min="1" step="1" placeholder="Fill when completed">
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" name="is_recurring" id="is_recurring" value="1">
                Recurring Task
            </label>
        </div>
        <div id="recurringOptions" style="display: none;">
            <div class="form-group">
                <label>Recurring Pattern</label>
                <select name="recurring_pattern" id="recurring_pattern">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                </select>
            </div>
            <div class="form-group">
                <label>Recurring End Date</label>
                <input type="date" name="recurring_end_date" id="recurring_end_date">
                <small style="color: #6b7280; font-size: 12px;">Leave empty for no end date</small>
            </div>
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" id="notes" rows="2"></textarea>
        </div>
        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <div id="assignmentFeedback" class="admin-modal-feedback" style="flex:1;"></div>
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
            <button type="submit" id="assignmentSaveBtn" class="btn btn-primary">Save</button>
        </div>
    </form>
    <?php renderAdminModalEnd(); ?>

    <!-- Confirm action modal (replaces window.confirm) -->
    <div class="modal-overlay" id="hkConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
        <div class="modal-content" style="max-width:420px;border-radius:12px;padding:0;overflow:hidden;">
            <div class="modal-header" style="padding:16px 20px;border-bottom:1px solid #e5e7eb;">
                <h3 style="margin:0;font-size:15px;" id="hkConfirmTitle">Confirm Action</h3>
            </div>
            <div class="modal-body" style="padding:16px 20px;">
                <p style="margin:0;font-size:14px;color:#374151;" id="hkConfirmMessage">Are you sure?</p>
            </div>
            <div class="modal-footer" style="padding:12px 20px;display:flex;gap:8px;justify-content:flex-end;border-top:1px solid #e5e7eb;">
                <button type="button" class="btn btn-secondary" onclick="hkCloseConfirm()">Cancel</button>
                <button type="button" class="btn btn-danger" id="hkConfirmBtn" onclick="hkDoConfirm()">Confirm</button>
            </div>
        </div>
    </div>

    <?php renderAdminModalStart('auditLogModal', 'Audit History', 'audit-log-modal-content'); ?>
    <div id="auditLogContent">
        <div style="text-align: center; padding: 20px;">
            <i class="fas fa-spinner fa-spin"></i> Loading...
        </div>
    </div>
    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top: 16px;">
        <button type="button" class="btn btn-secondary" onclick="closeAuditLogModal()">Close</button>
    </div>
    <?php renderAdminModalEnd(); ?>

    <?php renderAdminModalStart('statsQuickModal', 'Housekeeping Snapshot', 'housekeeping-modal-content hk-stats-modal'); ?>
    <div id="hkStatsModalMeta" class="hk-stats-modal__meta"></div>
    <div id="hkStatsModalList" class="hk-stats-modal__list"></div>
    <div class="hk-stats-modal__actions">
        <button type="button" class="btn btn-secondary" onclick="closeStatsQuickModal()">Close</button>
    </div>
    <?php renderAdminModalEnd(); ?>

    <?php renderAdminModalScript(); ?>

    <script>
        const hkTodayKey = <?php echo json_encode($statsToday, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function toDateKey(value) {
            return String(value || '').trim().slice(0, 10);
        }

        const hkStatConfig = {
            today_total: {
                title: "Today's Housekeeping Tasks",
                description: 'All housekeeping assignments scheduled for today.',
                match: function(item) {
                    return item.dueDateKey === hkTodayKey;
                }
            },
            pending: {
                title: 'Pending Tasks',
                description: 'Assignments waiting for a cleaner to start.',
                match: function(item) {
                    return item.status === 'pending';
                }
            },
            in_progress: {
                title: 'In Progress',
                description: 'Rooms currently being cleaned right now.',
                match: function(item) {
                    return item.status === 'in_progress';
                }
            },
            completed_today: {
                title: 'Completed Today',
                description: 'Housekeeping assignments completed today.',
                match: function(item) {
                    return item.completedDateKey === hkTodayKey;
                }
            },
            verified_today: {
                title: 'Verified Today',
                description: 'Assignments verified by supervisors today.',
                match: function(item) {
                    return item.status === 'verified' && item.verifiedDateKey === hkTodayKey;
                }
            },
            high_priority: {
                title: 'High Priority',
                description: 'Urgent housekeeping tasks that should be handled first.',
                match: function(item) {
                    return item.priority === 'high' && (item.status === 'pending' || item.status === 'in_progress' || item.status === 'completed' || item.status === 'blocked');
                }
            },
            blocked: {
                title: 'Blocked Tasks',
                description: 'Assignments blocked by an issue that needs intervention.',
                match: function(item) {
                    return item.status === 'blocked';
                }
            }
        };

        function getAssignmentRows() {
            const payload = document.getElementById('hkAssignmentData');
            if (!payload) return [];

            try {
                const parsed = JSON.parse(payload.textContent || '[]');
                if (!Array.isArray(parsed)) return [];
                return parsed.map(function(item) {
                    const dueDate = String(item.dueDate || '');
                    const completedAt = String(item.completedAt || '');
                    const verifiedAt = String(item.verifiedAt || '');
                    return {
                        status: String(item.status || '').toLowerCase(),
                        priority: String(item.priority || '').toLowerCase(),
                        type: String(item.type || '').toLowerCase(),
                        room: String(item.room || '-'),
                        assignee: String(item.assignee || 'Unassigned'),
                        dueDate: dueDate,
                        notes: String(item.notes || ''),
                        dueDateKey: toDateKey(dueDate),
                        completedDateKey: toDateKey(completedAt),
                        verifiedDateKey: toDateKey(verifiedAt)
                    };
                });
            } catch (error) {
                return [];
            }
        }

        function openStatsQuickModal(statKey) {
            const config = hkStatConfig[statKey];
            if (!config) return;

            const rows = getAssignmentRows().filter(function(item) {
                return config.match(item);
            });

            const titleEl = document.getElementById('statsQuickModal-title');
            const metaEl = document.getElementById('hkStatsModalMeta');
            const listEl = document.getElementById('hkStatsModalList');
            if (!titleEl || !metaEl || !listEl) return;

            titleEl.textContent = config.title + ' - Quick Insights';
            metaEl.innerHTML = '';
            listEl.innerHTML = '';

            const countEl = document.createElement('div');
            countEl.className = 'hk-stats-modal__count';
            countEl.textContent = String(rows.length);

            const descWrap = document.createElement('div');
            descWrap.className = 'hk-stats-modal__copy';
            const descTitle = document.createElement('div');
            descTitle.className = 'hk-stats-modal__copy-title';
            descTitle.textContent = config.title;
            const descText = document.createElement('p');
            descText.textContent = config.description;
            descWrap.appendChild(descTitle);
            descWrap.appendChild(descText);
            metaEl.appendChild(countEl);
            metaEl.appendChild(descWrap);

            if (rows.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'hk-stats-modal__empty';
                empty.innerHTML = '<i class="fas fa-check-circle"></i><span>No tasks in this bucket right now.</span>';
                listEl.appendChild(empty);
                openAdminModal('statsQuickModal');
                return;
            }

            rows.slice(0, 12).forEach(function(item) {
                const row = document.createElement('article');
                row.className = 'hk-stats-modal__item';

                const top = document.createElement('div');
                top.className = 'hk-stats-modal__item-top';

                const room = document.createElement('strong');
                room.textContent = item.room;
                top.appendChild(room);

                const status = document.createElement('span');
                status.className = 'status-pill ' + (item.status || 'pending');
                status.textContent = (item.status || 'pending').replace('_', ' ');
                top.appendChild(status);

                const meta = document.createElement('div');
                meta.className = 'hk-stats-modal__item-meta';
                meta.textContent = 'Assigned: ' + (item.assignee || 'Unassigned') + ' • Due: ' + (item.dueDate || 'N/A');

                const note = document.createElement('p');
                note.className = 'hk-stats-modal__item-note';
                note.textContent = (item.notes || '').trim() !== '' ? item.notes.trim() : 'No notes added.';

                row.appendChild(top);
                row.appendChild(meta);
                row.appendChild(note);
                listEl.appendChild(row);
            });

            if (rows.length > 12) {
                const more = document.createElement('div');
                more.className = 'hk-stats-modal__more';
                more.textContent = '+' + String(rows.length - 12) + ' more task(s). Use table filters for full list.';
                listEl.appendChild(more);
            }

            openAdminModal('statsQuickModal');
        }

        function closeStatsQuickModal() {
            closeAdminModal('statsQuickModal');
        }

        document.addEventListener('click', function(event) {
            const card = event.target.closest('.hk-stat-card--interactive');
            if (!card) return;
            const statKey = card.getAttribute('data-stat-key') || '';
            openStatsQuickModal(statKey);
        });

        document.addEventListener('keydown', function(event) {
            const card = event.target.closest('.hk-stat-card--interactive');
            if (!card) return;
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            const statKey = card.getAttribute('data-stat-key') || '';
            openStatsQuickModal(statKey);
        });

        // Set minimum date to today
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const dueDateInput = document.getElementById('due_date');
            if (dueDateInput) {
                dueDateInput.min = today;
            }

            // Auto-set high priority for checkout cleanup
            const assignmentTypeSelect = document.getElementById('assignmentType');
            const prioritySelect = document.getElementById('priority');
            if (assignmentTypeSelect && prioritySelect) {
                assignmentTypeSelect.addEventListener('change', function() {
                    if (this.value === 'checkout_cleanup') {
                        prioritySelect.value = 'high';
                    }
                });
            }

            // Toggle recurring options
            const isRecurringCheckbox = document.getElementById('is_recurring');
            const recurringOptions = document.getElementById('recurringOptions');
            if (isRecurringCheckbox && recurringOptions) {
                isRecurringCheckbox.addEventListener('change', function() {
                    recurringOptions.style.display = this.checked ? 'block' : 'none';
                });
            }

            // Show room status when selecting a room
            const roomSelect = document.getElementById('roomSelect');
            if (roomSelect) {
                roomSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const roomStatus = selectedOption.getAttribute('data-status');
                    const hkStatus = selectedOption.getAttribute('data-hk-status');
                    const small = this.parentElement.querySelector('small');
                    if (small && this.value) {
                        small.textContent = 'Room: ' + (roomStatus || 'unknown') + ' | Housekeeping: ' + (hkStatus || 'none');
                    }
                });
            }
        });

        function openModal() {
            document.getElementById('assignmentModal-title').textContent = 'Add Assignment';
            document.getElementById('formAction').value = 'add_assignment';
            document.getElementById('assignmentForm').reset();
            document.getElementById('assignmentId').value = '';
            document.getElementById('due_date').min = new Date().toISOString().split('T')[0];
            document.getElementById('recurringOptions').style.display = 'none';
            openAdminModal('assignmentModal');
        }

        function closeModal() {
            closeAdminModal('assignmentModal');
            var fb = document.getElementById('assignmentFeedback');
            if (fb) {
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
            }
        }

        function editAssignment(data) {
            document.getElementById('assignmentModal-title').textContent = 'Edit Assignment';
            document.getElementById('formAction').value = 'update_assignment';
            document.getElementById('assignmentId').value = data.id;
            document.getElementById('roomSelect').value = data.individual_room_id;
            document.getElementById('assignmentType').value = data.assignment_type || 'regular_cleaning';
            document.getElementById('priority').value = data.priority || 'medium';
            document.getElementById('due_date').value = data.due_date;
            document.getElementById('status').value = data.status;
            document.getElementById('assigned_to').value = data.assigned_to || '';
            document.getElementById('estimated_duration').value = data.estimated_duration || 30;
            document.getElementById('actual_duration').value = data.actual_duration || '';
            document.getElementById('notes').value = data.notes || '';

            const isRecurringCheckbox = document.getElementById('is_recurring');
            isRecurringCheckbox.checked = data.is_recurring == 1;
            document.getElementById('recurringOptions').style.display = data.is_recurring == 1 ? 'block' : 'none';
            document.getElementById('recurring_pattern').value = data.recurring_pattern || 'daily';
            document.getElementById('recurring_end_date').value = data.recurring_end_date || '';

            if (document.getElementById('scheduled_time')) {
                document.getElementById('scheduled_time').value = data.scheduled_time ? data.scheduled_time.substring(0, 5) : '';
            }
            if (document.getElementById('started_at')) {
                document.getElementById('started_at').value = data.started_at ? data.started_at.replace(' ', 'T').substring(0, 16) : '';
            }

            openAdminModal('assignmentModal');
        }

        function selectAllOccupied() {
            const checkboxes = document.querySelectorAll('.room-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => {
                cb.checked = !allChecked;
                const card = cb.closest('.occupied-room-card');
                if (card) card.classList.toggle('selected', !allChecked);
            });
            updateSelectedCount();
        }

        function toggleRoomCard(card) {
            const cb = card.querySelector('.room-checkbox');
            if (!cb) return;
            cb.checked = !cb.checked;
            card.classList.toggle('selected', cb.checked);
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const count = document.querySelectorAll('.room-checkbox:checked').length;
            const countEl = document.getElementById('selectedCount');
            const btn = document.getElementById('bulkAssignBtn');
            if (countEl) countEl.textContent = count;
            if (btn) btn.disabled = count === 0;
        }

        function quickAssignRoom(roomId) {
            document.getElementById('assignmentModal-title').textContent = 'Assign Room';
            document.getElementById('formAction').value = 'add_assignment';
            document.getElementById('assignmentForm').reset();
            document.getElementById('assignmentId').value = '';
            document.getElementById('due_date').value = new Date().toISOString().split('T')[0];
            document.getElementById('due_date').min = new Date().toISOString().split('T')[0];
            document.getElementById('roomSelect').value = roomId;
            document.getElementById('recurringOptions').style.display = 'none';
            openAdminModal('assignmentModal');
        }

        // Update selected room IDs when form is submitted
        const bulkAssignForm = document.getElementById('bulkAssignForm');
        if (bulkAssignForm) {
            bulkAssignForm.addEventListener('submit', function(event) {
                const selectedIds = Array.from(document.querySelectorAll('.room-checkbox:checked')).map(cb => cb.value);
                if (selectedIds.length === 0) {
                    event.preventDefault();
                    return;
                }
                document.getElementById('selectedRoomIds').value = JSON.stringify(selectedIds);
            });
        }

        bindAdminModal('assignmentModal');
        bindAdminModal('auditLogModal');
        bindAdminModal('statsQuickModal');

        function viewAuditLog(assignmentId, roomName) {
            document.getElementById('auditLogModal-title').textContent = 'Audit History - ' + roomName + ' (ID: ' + assignmentId + ')';
            document.getElementById('auditLogContent').innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            openAdminModal('auditLogModal');

            // Fetch audit log via AJAX
            fetch('api/get-housekeeping-audit.php?id=' + encodeURIComponent(assignmentId), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        document.getElementById('auditLogContent').innerHTML = '<div style="color: #dc2626; padding: 20px;">' + data.error + '</div>';
                        return;
                    }

                    if (data.logs.length === 0) {
                        document.getElementById('auditLogContent').innerHTML = '<div style="text-align: center; padding: 20px; color: #6b7280;">No audit history available.</div>';
                        return;
                    }

                    let html = '<div style="max-height: 400px; overflow-y: auto;">';
                    html += '<table style="width: 100%; border-collapse: collapse;">';
                    html += '<thead><tr style="background: #f9fafb; position: sticky; top: 0;">';
                    html += '<th style="padding: 10px; text-align: left; border-bottom: 2px solid #e5e7eb; font-size: 12px;">Action</th>';
                    html += '<th style="padding: 10px; text-align: left; border-bottom: 2px solid #e5e7eb; font-size: 12px;">Performed By</th>';
                    html += '<th style="padding: 10px; text-align: left; border-bottom: 2px solid #e5e7eb; font-size: 12px;">When</th>';
                    html += '<th style="padding: 10px; text-align: left; border-bottom: 2px solid #e5e7eb; font-size: 12px;">Changes</th>';
                    html += '</tr></thead><tbody>';

                    data.logs.forEach(log => {
                        const actionBadge = getActionBadge(log.action);
                        const formattedDate = new Date(log.performed_at).toLocaleString();
                        const changes = formatChanges(log);

                        html += '<tr style="border-bottom: 1px solid #e5e7eb;">';
                        html += '<td style="padding: 10px;">' + actionBadge + '</td>';
                        html += '<td style="padding: 10px; font-size: 13px;">' + (log.performed_by_name || 'System') + '</td>';
                        html += '<td style="padding: 10px; font-size: 12px; color: #6b7280;">' + formattedDate + '</td>';
                        html += '<td style="padding: 10px; font-size: 12px;">' + changes + '</td>';
                        html += '</tr>';
                    });

                    html += '</tbody></table></div>';
                    document.getElementById('auditLogContent').innerHTML = html;
                })
                .catch(error => {
                    console.error('Error fetching audit log:', error);
                    document.getElementById('auditLogContent').innerHTML = '<div style="color: #dc2626; padding: 20px;">Failed to load audit history.</div>';
                });
        }

        function getActionBadge(action) {
            const badges = {
                'created': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #d1fae5; color: #065f46;">Created</span>',
                'updated': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #dbeafe; color: #1e40af;">Updated</span>',
                'deleted': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #fee2e2; color: #991b1b;">Deleted</span>',
                'verified': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #e0e7ff; color: #3730a3;">Verified</span>',
                'status_changed': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #fef3c7; color: #92400e;">Status Changed</span>',
                'assigned': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #f3e8ff; color: #6b21a8;">Assigned</span>',
                'unassigned': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #f3f4f6; color: #374151;">Unassigned</span>',
                'priority_changed': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #ffedd5; color: #9a3412;">Priority Changed</span>',
                'recurring_created': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #ecfdf5; color: #064e3b;">Recurring Created</span>',
            };
            return badges[action] || '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #f3f4f6; color: #374151;">' + action + '</span>';
        }

        function formatChanges(log) {
            if (!log.changed_fields || log.changed_fields.length === 0) {
                return '<span style="color: #9ca3af;">No field changes</span>';
            }

            let changes = '<div style="display: flex; flex-wrap: wrap; gap: 4px;">';
            log.changed_fields.forEach(field => {
                changes += '<span style="display: inline; padding: 2px 6px; border-radius: 4px; background: #f3f4f6; color: #4b5563; font-size: 11px;">' + field + '</span>';
            });
            changes += '</div>';

            return changes;
        }

        function closeAuditLogModal() {
            closeAdminModal('auditLogModal');
        }

        // ── AJAX save for assignment modal ────────────────────────────────────
        document.getElementById('assignmentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var saveBtn = document.getElementById('assignmentSaveBtn');
            var fb = document.getElementById('assignmentFeedback');
            var origHtml = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
            fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new FormData(this)
                })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(res) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.className = 'admin-modal-feedback ' + (res.success ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error') + ' visible';
                    fb.innerHTML = '<i class="fas fa-' + (res.success ? 'check-circle' : 'exclamation-circle') + '"></i> ' + res.message;
                    if (res.success) refreshAssignmentsTable();
                })
                .catch(function() {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error — please try again.';
                });
        });

        function refreshAssignmentsTable() {
            fetch(window.location.href)
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var next = doc.getElementById('assignmentsTable');
                    var cur = document.getElementById('assignmentsTable');
                    var nextStats = doc.getElementById('hkStatsDashboard');
                    var curStats = document.getElementById('hkStatsDashboard');
                    var nextData = doc.getElementById('hkAssignmentData');
                    var curData = document.getElementById('hkAssignmentData');
                    if (next && cur) cur.innerHTML = next.innerHTML;
                    if (nextStats && curStats) curStats.innerHTML = nextStats.innerHTML;
                    if (nextData && curData) curData.textContent = nextData.textContent;
                }).catch(function() {});
        }

        // ── Table search + status filter ────────────────────────────────────
        function filterTable() {
            var query = (document.getElementById('tableSearch').value || '').toLowerCase();
            var status = (document.getElementById('tableStatusFilter').value || '').toLowerCase();
            var rows = document.querySelectorAll('#assignmentsTable tbody tr[data-status]');
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                var rowStat = (row.getAttribute('data-status') || '').toLowerCase();
                var matchTxt = !query || text.includes(query);
                var matchSt = !status || rowStat === status;
                row.style.display = matchTxt && matchSt ? '' : 'none';
            });
        }

        // ── Confirm modal helpers ───────────────────────────────────────────
        var _hkConfirmForm = null;

        function hkConfirm(form, message, btnLabel, btnClass) {
            _hkConfirmForm = form;
            document.getElementById('hkConfirmMessage').textContent = message || 'Are you sure?';
            var btn = document.getElementById('hkConfirmBtn');
            btn.textContent = btnLabel || 'Confirm';
            btn.className = 'btn ' + (btnClass || 'btn-danger');
            document.getElementById('hkConfirmModal').style.display = 'flex';
        }

        function hkCloseConfirm() {
            document.getElementById('hkConfirmModal').style.display = 'none';
            _hkConfirmForm = null;
        }

        function hkDoConfirm() {
            var formToSubmit = _hkConfirmForm;
            hkCloseConfirm();
            if (formToSubmit) formToSubmit.submit();
        }
        // Close on backdrop click
        document.getElementById('hkConfirmModal').addEventListener('click', function(e) {
            if (e.target === this) hkCloseConfirm();
        });
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

