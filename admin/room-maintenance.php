<?php

/**
 * Room Maintenance Management - Admin Panel
 * Enhanced with priority-based assignments, rooms needing maintenance auto-fetch,
 * staff workload tracking, and verification workflow
 * Mirrors housekeeping.php implementation patterns
 */
require_once 'admin-init.php';
require_once 'includes/admin-modal.php';
/** @var array{id: int, username: string, role: string} $user */
/** @var string $csrf_token */

if (!hasPermission($user['id'], 'room_maintenance')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$message = '';
$error = '';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Extended status workflow with verification
$validMaintenanceStatuses = ['pending', 'in_progress', 'completed', 'verified', 'cancelled'];
$validPriorities = ['high', 'medium', 'low', 'urgent'];
$validMaintenanceTypes = ['repair', 'replacement', 'inspection', 'upgrade', 'emergency'];
$validRecurringPatterns = ['daily', 'weekly', 'monthly'];

// Priority order for sorting (urgent first)
$priorityOrder = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

/**
 * Check if a room exists and is active
 */
function maintenanceRoomExists(PDO $pdo, int $roomId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM individual_rooms WHERE id = ? AND is_active = 1");
    $stmt->execute([$roomId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Check if a user exists and is active
 */
function maintenanceUserExists(PDO $pdo, ?int $userId): bool
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
function maintenanceTableExists(PDO $pdo, string $table): bool
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
 * Check if a column exists in the room_maintenance_schedules table
 * Caches results for performance
 */
function maintenanceColumnExists(PDO $pdo, string $column): bool
{
    /** @var array<string, bool> $cache */
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_maintenance_schedules' AND COLUMN_NAME = ?");
    $stmt->execute([$column]);
    $cache[$column] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$column];
}

/**
 * Set room status and log the change
 */
function maintenanceSetRoomStatus(PDO $pdo, int $roomId, string $newStatus, ?string $reason, ?int $performedBy): void
{
    $statusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
    $statusStmt->execute([$roomId]);
    $oldStatus = (string)$statusStmt->fetchColumn();
    if ($oldStatus === '' || $oldStatus === $newStatus) {
        return;
    }

    $updateStmt = $pdo->prepare("UPDATE individual_rooms SET status = ? WHERE id = ?");
    $updateStmt->execute([$newStatus, $roomId]);

    if (maintenanceTableExists($pdo, 'room_maintenance_log')) {
        $logStmt = $pdo->prepare("INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by) VALUES (?, ?, ?, ?, ?)");
        $logStmt->execute([$roomId, $oldStatus, $newStatus, $reason, $performedBy]);
    }
}

/**
 * Validate due date - cannot be in the past
 */
function validateMaintenanceDueDate(string $dueDate): bool
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
 * Get rooms that need maintenance (based on reported issues or status)
 */
function getRoomsNeedingMaintenance(PDO $pdo): array
{
    $sql = "
        SELECT DISTINCT
            ir.id,
            ir.room_number,
            ir.room_name,
            ir.status as room_status,
            b.id as booking_id,
            b.guest_name,
            b.check_out_date,
            b.status as booking_status,
            CASE
                WHEN ir.status = 'out_of_order' THEN 'out_of_order'
                WHEN b.status = 'checked-in' THEN 'occupied'
                ELSE 'available'
            END as room_condition
        FROM individual_rooms ir
        LEFT JOIN bookings b ON b.individual_room_id = ir.id
            AND b.status IN ('checked-in', 'checked-out')
            AND b.check_out_date >= CURDATE()
        WHERE ir.is_active = 1
          AND (
              ir.status = 'out_of_order'
              OR ir.status = 'maintenance'
              OR b.status = 'checked-in'
          )
        ORDER BY
            CASE ir.status WHEN 'out_of_order' THEN 1 WHEN 'maintenance' THEN 2 ELSE 3 END,
            ir.room_number ASC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get rooms that need maintenance but have no pending/in-progress tasks
 */
function getMaintenanceNeededRooms(PDO $pdo): array
{
    $hasDueDate = maintenanceColumnExists($pdo, 'due_date');
    $hasMaintenanceType = maintenanceColumnExists($pdo, 'maintenance_type');

    // Build the NOT EXISTS clause conditionally based on available columns
    $notExistsConditions = [
        "rms.individual_room_id = ir.id",
        "rms.status IN ('pending', 'in_progress')"
    ];

    $notExistsClause = implode(' AND ', $notExistsConditions);

    $sql = "
        SELECT DISTINCT
            ir.id,
            ir.room_number,
            ir.room_name,
            ir.status as room_status,
            b.id as booking_id,
            b.guest_name
        FROM individual_rooms ir
        LEFT JOIN bookings b ON b.individual_room_id = ir.id
            AND b.status = 'checked-in'
        WHERE ir.is_active = 1
          AND (
              ir.status IN ('out_of_order', 'maintenance')
              OR b.status = 'checked-in'
          )
          AND NOT EXISTS (
              SELECT 1 FROM room_maintenance_schedules rms
              WHERE {$notExistsClause}
          )
        ORDER BY ir.room_number ASC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get staff workload (number of pending/in-progress assignments per staff)
 * Backward compatible: works with or without migration 005 columns
 */
function getMaintenanceStaffWorkload(PDO $pdo): array
{
    $hasPriority = maintenanceColumnExists($pdo, 'priority');

    // Build the high_priority_pending conditionally
    $highPriorityCase = $hasPriority
        ? "COUNT(CASE WHEN rms.status = 'pending' AND rms.priority IN ('urgent', 'high') THEN 1 END) as high_priority_pending"
        : "0 as high_priority_pending";

    $sql = "
        SELECT
            u.id,
            u.username,
            COUNT(CASE WHEN rms.status IN ('pending', 'in_progress') THEN 1 END) as active_tasks,
            {$highPriorityCase},
            COUNT(CASE WHEN rms.status = 'completed' AND DATE(rms.completed_at) = CURDATE() THEN 1 END) as completed_today
        FROM admin_users u
        LEFT JOIN room_maintenance_schedules rms ON rms.assigned_to = u.id
            AND (rms.status IN ('pending', 'in_progress') OR (rms.status = 'completed' AND DATE(rms.completed_at) = CURDATE()))
        WHERE u.is_active = 1
        GROUP BY u.id, u.username
        ORDER BY active_tasks DESC, u.username ASC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Auto-create maintenance tasks for rooms that need them
 * Backward compatible: works with or without migration 005 columns
 */
function autoCreateMaintenanceTasks(PDO $pdo, int $performedBy): int
{
    $hasDueDate = maintenanceColumnExists($pdo, 'due_date');
    $hasMaintenanceType = maintenanceColumnExists($pdo, 'maintenance_type');
    $hasPriority = maintenanceColumnExists($pdo, 'priority');
    $hasAutoCreated = maintenanceColumnExists($pdo, 'auto_created');
    $hasLinkedBookingId = maintenanceColumnExists($pdo, 'linked_booking_id');

    $maintenanceRooms = getMaintenanceNeededRooms($pdo);
    $created = 0;

    foreach ($maintenanceRooms as $room) {
        // Check if assignment already exists
        $checkStmt = $pdo->prepare("
            SELECT id FROM room_maintenance_schedules
            WHERE individual_room_id = ? AND status IN ('pending', 'in_progress')
        ");
        $checkStmt->execute([$room['id']]);
        if ($checkStmt->fetch()) {
            continue;
        }

        // Determine maintenance type based on room status
        $maintenanceType = 'inspection';
        $priority = 'medium';
        if ($room['room_status'] === 'out_of_order') {
            $maintenanceType = 'repair';
            $priority = 'high';
        } elseif ($room['room_status'] === 'maintenance') {
            $maintenanceType = 'repair';
            $priority = 'urgent';
        }

        // Build INSERT columns and values based on available columns
        $insertColumns = ['individual_room_id', 'title', 'status', 'start_date', 'end_date', 'assigned_to', 'created_by'];
        $insertValues = ['?', '?', '?', '?', '?', '?', '?'];
        $insertParams = [
            $room['id'],
            'Auto-generated maintenance task',
            'pending',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime('+1 day')),
            null,
            $performedBy
        ];

        if ($hasDueDate) {
            $insertColumns[] = 'due_date';
            $insertValues[] = '?';
            $insertParams[] = date('Y-m-d');
        }
        if ($hasMaintenanceType) {
            $insertColumns[] = 'maintenance_type';
            $insertValues[] = '?';
            $insertParams[] = $maintenanceType;
        }
        if ($hasPriority) {
            $insertColumns[] = 'priority';
            $insertValues[] = '?';
            $insertParams[] = $priority;
        }
        if ($hasAutoCreated) {
            $insertColumns[] = 'auto_created';
            $insertValues[] = '?';
            $insertParams[] = 1;
        }
        if ($hasLinkedBookingId && !empty($room['booking_id'])) {
            $insertColumns[] = 'linked_booking_id';
            $insertValues[] = '?';
            $insertParams[] = $room['booking_id'];
        }

        $insertSql = "INSERT INTO room_maintenance_schedules (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")";
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute($insertParams);
        $newMaintenanceId = (int)$pdo->lastInsertId();
        $created++;

        // Log audit trail for auto-created maintenance task
        $newData = [
            'individual_room_id' => $room['id'],
            'title' => 'Auto-generated maintenance task',
            'status' => 'pending',
            'start_date' => date('Y-m-d H:i:s'),
            'end_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'assigned_to' => null,
            'created_by' => $performedBy,
        ];
        if ($hasDueDate) $newData['due_date'] = date('Y-m-d');
        if ($hasMaintenanceType) $newData['maintenance_type'] = $maintenanceType;
        if ($hasPriority) $newData['priority'] = $priority;
        if ($hasAutoCreated) $newData['auto_created'] = 1;
        if ($hasLinkedBookingId && !empty($room['booking_id'])) $newData['linked_booking_id'] = $room['booking_id'];

        logMaintenanceAction($newMaintenanceId, 'created', null, $newData, $performedBy);
    }

    return $created;
}

/**
 * Reconcile individual room maintenance status
 * Backward compatible: works with or without migration 005 columns
 */
function reconcileMaintenanceRoomStatus(PDO $pdo, int $roomId, ?int $performedBy = null): void
{
    $hasPriority = maintenanceColumnExists($pdo, 'priority');
    $hasDueDate = maintenanceColumnExists($pdo, 'due_date');

    // Build SELECT columns based on available columns
    $selectColumns = ['status', 'title'];
    if ($hasPriority) {
        $selectColumns[] = 'priority';
    }
    if ($hasDueDate) {
        $selectColumns[] = 'due_date';
    }

    // Build ORDER BY clause based on available columns
    $orderByClauses = [];

    if ($hasPriority) {
        $orderByClauses[] = "CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END";
    }

    if ($hasDueDate) {
        $orderByClauses[] = "due_date ASC";
    }

    $orderByClauses[] = "created_at DESC";

    $sql = "
        SELECT " . implode(', ', $selectColumns) . "
        FROM room_maintenance_schedules
        WHERE individual_room_id = ?
                    AND status IN ('pending','in_progress','completed','planned')
        ORDER BY " . implode(', ', $orderByClauses) . "
        LIMIT 1
    ";
    $openStmt = $pdo->prepare($sql);
    $openStmt->execute([$roomId]);
    $open = $openStmt->fetch(PDO::FETCH_ASSOC);

    if ($open) {
        $roomStatusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
        $roomStatusStmt->execute([$roomId]);
        $roomStatus = (string)$roomStatusStmt->fetchColumn();

        // Only set to maintenance if not occupied
        if (!in_array($roomStatus, ['occupied', 'out_of_order'], true)) {
            maintenanceSetRoomStatus($pdo, $roomId, 'maintenance', 'Maintenance assignment active', $performedBy);
        }
        return;
    }

    // No active maintenance - check if room should be available
    $roomStatusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
    $roomStatusStmt->execute([$roomId]);
    $roomStatus = (string)$roomStatusStmt->fetchColumn();

    if ($roomStatus === 'maintenance') {
        maintenanceSetRoomStatus($pdo, $roomId, 'available', 'Maintenance assignment cleared', $performedBy);
    }
}

/**
 * Create recurring maintenance assignments
 * Backward compatible: works with or without migration 005 columns
 */
function createRecurringMaintenance(PDO $pdo, int $performedBy): int
{
    $hasIsRecurring = maintenanceColumnExists($pdo, 'is_recurring');
    $hasRecurringPattern = maintenanceColumnExists($pdo, 'recurring_pattern');
    $hasRecurringEndDate = maintenanceColumnExists($pdo, 'recurring_end_date');
    $hasMaintenanceType = maintenanceColumnExists($pdo, 'maintenance_type');
    $hasPriority = maintenanceColumnExists($pdo, 'priority');
    $hasEstimatedDuration = maintenanceColumnExists($pdo, 'estimated_duration');
    $hasDueDate = maintenanceColumnExists($pdo, 'due_date');

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

    $sql = "SELECT * FROM room_maintenance_schedules WHERE " . implode(' AND ', $whereConditions);
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
                $shouldCreate = (date('Y-m-d', strtotime($lastCreated)) < $today);
                break;
            case 'weekly':
                $shouldCreate = (strtotime($lastCreated) < strtotime('-7 days'));
                break;
            case 'monthly':
                $shouldCreate = (strtotime($lastCreated) < strtotime('-30 days'));
                break;
        }

        if ($shouldCreate) {
            // Calculate new dates
            $startDate = date('Y-m-d H:i:s');
            $endDate = date('Y-m-d H:i:s', strtotime('+1 day'));
            $dueDate = $today;

            // Build INSERT columns and values based on available columns
            $insertColumns = ['individual_room_id', 'title', 'description', 'status', 'start_date', 'end_date', 'assigned_to', 'created_by'];
            $insertValues = ['?', '?', '?', '?', '?', '?', '?', '?'];
            $insertParams = [
                $assignment['individual_room_id'],
                $assignment['title'],
                $assignment['description'],
                'pending',
                $startDate,
                $endDate,
                $assignment['assigned_to'],
                $performedBy
            ];

            if ($hasDueDate) {
                $insertColumns[] = 'due_date';
                $insertValues[] = '?';
                $insertParams[] = $dueDate;
            }
            if ($hasMaintenanceType) {
                $insertColumns[] = 'maintenance_type';
                $insertValues[] = '?';
                $insertParams[] = $assignment['maintenance_type'] ?? 'inspection';
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
                $insertParams[] = $assignment['estimated_duration'] ?? 60;
            }

            $insertSql = "INSERT INTO room_maintenance_schedules (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")";
            $newStmt = $pdo->prepare($insertSql);
            $newStmt->execute($insertParams);
            $newMaintenanceId = (int)$pdo->lastInsertId();
            $created++;

            // Log audit trail for recurring maintenance creation
            $newData = [
                'individual_room_id' => $assignment['individual_room_id'],
                'title' => $assignment['title'],
                'description' => $assignment['description'],
                'status' => 'pending',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'assigned_to' => $assignment['assigned_to'],
                'created_by' => $performedBy,
            ];
            if ($hasDueDate) $newData['due_date'] = $dueDate;
            if ($hasMaintenanceType) $newData['maintenance_type'] = $assignment['maintenance_type'] ?? 'inspection';
            if ($hasPriority) $newData['priority'] = $assignment['priority'] ?? 'medium';
            if ($hasIsRecurring) $newData['is_recurring'] = 1;
            if ($hasRecurringPattern) $newData['recurring_pattern'] = $assignment['recurring_pattern'];
            if ($hasRecurringEndDate) $newData['recurring_end_date'] = $assignment['recurring_end_date'];
            if ($hasEstimatedDuration) $newData['estimated_duration'] = $assignment['estimated_duration'] ?? 60;

            logMaintenanceAction($newMaintenanceId, 'recurring_created', null, $newData, $performedBy);
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

        if ($action === 'add_schedule') {
            $room_id = (int)($_POST['individual_room_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $due_date = $_POST['due_date'] ?? '';
            $status = $_POST['status'] ?? 'pending';
            $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $notes = trim($_POST['notes'] ?? '');
            $priority = $_POST['priority'] ?? 'medium';
            $maintenance_type = $_POST['maintenance_type'] ?? 'repair';
            $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
            $recurring_pattern = $is_recurring ? ($_POST['recurring_pattern'] ?? null) : null;
            $recurring_end_date = $is_recurring ? ($_POST['recurring_end_date'] ?? null) : null;
            $estimated_duration = (int)($_POST['estimated_duration'] ?? 60);
            $start_date = $_POST['start_date'] ?? date('Y-m-d H:i:s');
            $end_date = $_POST['end_date'] ?? date('Y-m-d H:i:s', strtotime('+1 day'));
            $block_room = isset($_POST['block_room']) ? 1 : 0;
            $linked_booking_id = !empty($_POST['linked_booking_id']) ? (int)$_POST['linked_booking_id'] : null;

            // Check which columns exist for backward compatibility
            $hasDueDate = maintenanceColumnExists($pdo, 'due_date');
            $hasMaintenanceType = maintenanceColumnExists($pdo, 'maintenance_type');
            $hasPriority = maintenanceColumnExists($pdo, 'priority');
            $hasIsRecurring = maintenanceColumnExists($pdo, 'is_recurring');
            $hasRecurringPattern = maintenanceColumnExists($pdo, 'recurring_pattern');
            $hasRecurringEndDate = maintenanceColumnExists($pdo, 'recurring_end_date');
            $hasEstimatedDuration = maintenanceColumnExists($pdo, 'estimated_duration');
            $hasActualDuration = maintenanceColumnExists($pdo, 'actual_duration');
            $hasVerifiedAt = maintenanceColumnExists($pdo, 'verified_at');
            $hasVerifiedBy = maintenanceColumnExists($pdo, 'verified_by');
            $hasCompletedAt = maintenanceColumnExists($pdo, 'completed_at');
            $hasLinkedBookingId = maintenanceColumnExists($pdo, 'linked_booking_id');
            $hasAutoCreated = maintenanceColumnExists($pdo, 'auto_created');
            $hasStartedAt = maintenanceColumnExists($pdo, 'started_at');
            $started_at_input = $_POST['started_at'] ?? '';
            $started_at = !empty($started_at_input) ? date('Y-m-d H:i:s', strtotime($started_at_input)) : null;

            // Validation
            if (!$room_id) {
                $error = 'Room is required.';
            } elseif (!$title) {
                $error = 'Title is required.';
            } elseif ($hasDueDate && !$due_date) {
                $error = 'Due date is required.';
            } elseif ($hasDueDate && !validateMaintenanceDueDate($due_date)) {
                $error = 'Due date cannot be in the past. Please select today or a future date.';
            } elseif (!in_array($status, $validMaintenanceStatuses, true)) {
                $error = 'Invalid maintenance status.';
            } elseif ($hasPriority && !in_array($priority, $validPriorities, true)) {
                $error = 'Invalid priority level.';
            } elseif ($hasMaintenanceType && !in_array($maintenance_type, $validMaintenanceTypes, true)) {
                $error = 'Invalid maintenance type.';
            } elseif ($hasIsRecurring && $is_recurring && !in_array($recurring_pattern, $validRecurringPatterns, true)) {
                $error = 'Invalid recurring pattern.';
            } elseif (!maintenanceRoomExists($pdo, $room_id)) {
                $error = 'Selected room is invalid or inactive.';
            } elseif (!maintenanceUserExists($pdo, $assigned_to)) {
                $error = 'Assigned user is invalid.';
            } elseif (strtotime($start_date) === false || strtotime($end_date) === false) {
                $error = 'Invalid date format.';
            } elseif (strtotime($end_date) <= strtotime($start_date)) {
                $error = 'End date must be after start date.';
            } else {
                $pdo->beginTransaction();

                $completedAt = in_array($status, ['completed', 'verified'], true) ? date('Y-m-d H:i:s') : null;
                $verifiedAt = ($hasVerifiedAt && $status === 'verified') ? date('Y-m-d H:i:s') : null;
                $verifiedBy = ($hasVerifiedBy && $status === 'verified') ? ($user['id'] ?? null) : null;

                // Build INSERT columns and values based on available columns
                $insertColumns = ['individual_room_id', 'title', 'description', 'status', 'start_date', 'end_date', 'assigned_to', 'created_by'];
                $insertValues = ['?', '?', '?', '?', '?', '?', '?', '?'];
                $insertParams = [$room_id, $title, $description, $status, $start_date, $end_date, $assigned_to, $user['id'] ?? null];

                if ($hasDueDate) {
                    $insertColumns[] = 'due_date';
                    $insertValues[] = '?';
                    $insertParams[] = $due_date;
                }
                if ($hasPriority) {
                    $insertColumns[] = 'priority';
                    $insertValues[] = '?';
                    $insertParams[] = $priority;
                }
                if ($hasMaintenanceType) {
                    $insertColumns[] = 'maintenance_type';
                    $insertValues[] = '?';
                    $insertParams[] = $maintenance_type;
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
                if ($hasCompletedAt) {
                    $insertColumns[] = 'completed_at';
                    $insertValues[] = '?';
                    $insertParams[] = $completedAt;
                }
                if ($hasVerifiedBy) {
                    $insertColumns[] = 'verified_by';
                    $insertValues[] = '?';
                    $insertParams[] = $verifiedBy;
                }
                if ($hasVerifiedAt) {
                    $insertColumns[] = 'verified_at';
                    $insertValues[] = '?';
                    $insertParams[] = $verifiedAt;
                }
                if ($hasLinkedBookingId) {
                    $insertColumns[] = 'linked_booking_id';
                    $insertValues[] = '?';
                    $insertParams[] = $linked_booking_id;
                }
                if ($hasAutoCreated) {
                    $insertColumns[] = 'auto_created';
                    $insertValues[] = '?';
                    $insertParams[] = 0;
                }
                if (maintenanceColumnExists($pdo, 'block_room')) {
                    $insertColumns[] = 'block_room';
                    $insertValues[] = '?';
                    $insertParams[] = $block_room;
                }
                if ($hasStartedAt) {
                    $insertColumns[] = 'started_at';
                    $insertValues[] = '?';
                    $insertParams[] = $started_at;
                }

                $insertSql = "INSERT INTO room_maintenance_schedules (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")";
                $stmt = $pdo->prepare($insertSql);
                $stmt->execute($insertParams);
                $newMaintenanceId = (int)$pdo->lastInsertId();

                reconcileMaintenanceRoomStatus($pdo, $room_id, $user['id'] ?? null);

                // Log audit trail
                $newData = [
                    'individual_room_id' => $room_id,
                    'title' => $title,
                    'description' => $description,
                    'status' => $status,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'assigned_to' => $assigned_to,
                    'created_by' => $user['id'] ?? null,
                ];
                if ($hasDueDate) $newData['due_date'] = $due_date;
                if ($hasPriority) $newData['priority'] = $priority;
                if ($hasMaintenanceType) $newData['maintenance_type'] = $maintenance_type;
                if ($hasIsRecurring) $newData['is_recurring'] = $is_recurring;
                if ($hasRecurringPattern) $newData['recurring_pattern'] = $recurring_pattern;
                if ($hasRecurringEndDate) $newData['recurring_end_date'] = $recurring_end_date;
                if ($hasEstimatedDuration) $newData['estimated_duration'] = $estimated_duration;
                if ($hasCompletedAt) $newData['completed_at'] = $completedAt;
                if ($hasVerifiedBy) $newData['verified_by'] = $verifiedBy;
                if ($hasVerifiedAt) $newData['verified_at'] = $verifiedAt;
                if ($hasLinkedBookingId) $newData['linked_booking_id'] = $linked_booking_id;
                if ($hasAutoCreated) $newData['auto_created'] = 0;
                if (maintenanceColumnExists($pdo, 'block_room')) $newData['block_room'] = $block_room;

                logMaintenanceAction($newMaintenanceId, 'created', null, $newData, $user['id'] ?? null, $user['username'] ?? null);

                $pdo->commit();
                $message = 'Maintenance schedule created successfully.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                }
            }
        } elseif ($action === 'update_schedule') {
            $id = (int)($_POST['id'] ?? 0);
            $room_id = (int)($_POST['individual_room_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $due_date = $_POST['due_date'] ?? '';
            $status = $_POST['status'] ?? 'pending';
            $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $notes = trim($_POST['notes'] ?? '');
            $priority = $_POST['priority'] ?? 'medium';
            $maintenance_type = $_POST['maintenance_type'] ?? 'repair';
            $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
            $recurring_pattern = $is_recurring ? ($_POST['recurring_pattern'] ?? null) : null;
            $recurring_end_date = $is_recurring ? ($_POST['recurring_end_date'] ?? null) : null;
            $estimated_duration = (int)($_POST['estimated_duration'] ?? 60);
            $actual_duration = !empty($_POST['actual_duration']) ? (int)$_POST['actual_duration'] : null;
            $start_date = $_POST['start_date'] ?? date('Y-m-d H:i:s');
            $end_date = $_POST['end_date'] ?? date('Y-m-d H:i:s', strtotime('+1 day'));
            $block_room = isset($_POST['block_room']) ? 1 : 0;
            $linked_booking_id = !empty($_POST['linked_booking_id']) ? (int)$_POST['linked_booking_id'] : null;

            // Check which columns exist for backward compatibility
            $hasDueDate = maintenanceColumnExists($pdo, 'due_date');
            $hasMaintenanceType = maintenanceColumnExists($pdo, 'maintenance_type');
            $hasPriority = maintenanceColumnExists($pdo, 'priority');
            $hasIsRecurring = maintenanceColumnExists($pdo, 'is_recurring');
            $hasRecurringPattern = maintenanceColumnExists($pdo, 'recurring_pattern');
            $hasRecurringEndDate = maintenanceColumnExists($pdo, 'recurring_end_date');
            $hasEstimatedDuration = maintenanceColumnExists($pdo, 'estimated_duration');
            $hasActualDuration = maintenanceColumnExists($pdo, 'actual_duration');
            $hasVerifiedAt = maintenanceColumnExists($pdo, 'verified_at');
            $hasVerifiedBy = maintenanceColumnExists($pdo, 'verified_by');
            $hasCompletedAt = maintenanceColumnExists($pdo, 'completed_at');
            $hasLinkedBookingId = maintenanceColumnExists($pdo, 'linked_booking_id');
            $hasStartedAt = maintenanceColumnExists($pdo, 'started_at');
            $started_at_input = $_POST['started_at'] ?? '';
            $started_at = !empty($started_at_input) ? date('Y-m-d H:i:s', strtotime($started_at_input)) : null;

            // Validation
            if (!$id || !$room_id || !$title) {
                $error = 'Room and title are required.';
            } elseif ($hasDueDate && !$due_date) {
                $error = 'Due date is required.';
            } elseif ($hasDueDate && !validateMaintenanceDueDate($due_date)) {
                $error = 'Due date cannot be in the past. Please select today or a future date.';
            } elseif (!in_array($status, $validMaintenanceStatuses, true)) {
                $error = 'Invalid maintenance status.';
            } elseif ($hasPriority && !in_array($priority, $validPriorities, true)) {
                $error = 'Invalid priority level.';
            } elseif ($hasMaintenanceType && !in_array($maintenance_type, $validMaintenanceTypes, true)) {
                $error = 'Invalid maintenance type.';
            } elseif ($hasIsRecurring && $is_recurring && !in_array($recurring_pattern, $validRecurringPatterns, true)) {
                $error = 'Invalid recurring pattern.';
            } elseif (!maintenanceRoomExists($pdo, $room_id)) {
                $error = 'Selected room is invalid or inactive.';
            } elseif (!maintenanceUserExists($pdo, $assigned_to)) {
                $error = 'Assigned user is invalid.';
            } elseif (strtotime($start_date) === false || strtotime($end_date) === false) {
                $error = 'Invalid date format.';
            } elseif (strtotime($end_date) <= strtotime($start_date)) {
                $error = 'End date must be after start date.';
            } else {
                $pdo->beginTransaction();
                $existsStmt = $pdo->prepare("SELECT id, individual_room_id, status, verified_by FROM room_maintenance_schedules WHERE id = ?");
                $existsStmt->execute([$id]);
                $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    throw new RuntimeException('Maintenance schedule does not exist.');
                }
                if (($existing['status'] ?? '') === 'verified') {
                    throw new DomainException('Verified maintenance schedules are locked and cannot be edited.');
                }

                // Auto-set verified_by when status changes to verified
                $verifiedBy = $existing['verified_by'] ?? null;
                $verifiedAt = null;
                if ($hasVerifiedBy && $hasVerifiedAt && $status === 'verified' && ($existing['status'] ?? '') !== 'verified') {
                    $verifiedBy = $user['id'] ?? null;
                    $verifiedAt = date('Y-m-d H:i:s');
                } elseif ($status !== 'verified' && $hasVerifiedAt) {
                    $verifiedAt = null;
                    if ($hasVerifiedBy) {
                        $verifiedBy = null;
                    }
                }

                $completedAt = in_array($status, ['completed', 'verified'], true) ? date('Y-m-d H:i:s') : null;
                if (($existing['status'] ?? '') === 'completed' && $status !== 'completed' && $status !== 'verified') {
                    $completedAt = null;
                }

                // Build UPDATE SET clause based on available columns
                $setColumns = ['individual_room_id=?', 'title=?', 'description=?', 'status=?', 'start_date=?', 'end_date=?', 'assigned_to=?'];
                $updateParams = [$room_id, $title, $description, $status, $start_date, $end_date, $assigned_to];

                if ($hasDueDate) {
                    $setColumns[] = 'due_date=?';
                    $updateParams[] = $due_date;
                }
                if ($hasPriority) {
                    $setColumns[] = 'priority=?';
                    $updateParams[] = $priority;
                }
                if ($hasMaintenanceType) {
                    $setColumns[] = 'maintenance_type=?';
                    $updateParams[] = $maintenance_type;
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
                if ($hasCompletedAt) {
                    $setColumns[] = 'completed_at=?';
                    $updateParams[] = $completedAt;
                }
                if ($hasVerifiedBy) {
                    $setColumns[] = 'verified_by=?';
                    $updateParams[] = $verifiedBy;
                }
                if ($hasVerifiedAt) {
                    $setColumns[] = 'verified_at=?';
                    $updateParams[] = $verifiedAt;
                }
                if ($hasLinkedBookingId) {
                    $setColumns[] = 'linked_booking_id=?';
                    $updateParams[] = $linked_booking_id;
                }
                if (maintenanceColumnExists($pdo, 'block_room')) {
                    $setColumns[] = 'block_room=?';
                    $updateParams[] = $block_room;
                }
                if ($hasStartedAt) {
                    $setColumns[] = 'started_at=?';
                    $updateParams[] = $started_at;
                }

                $updateParams[] = $id; // WHERE id=?

                $updateSql = "UPDATE room_maintenance_schedules SET " . implode(', ', $setColumns) . " WHERE id=?";
                $stmt = $pdo->prepare($updateSql);
                $stmt->execute($updateParams);

                // Get updated data for audit log
                $updatedStmt = $pdo->prepare("SELECT * FROM room_maintenance_schedules WHERE id = ?");
                $updatedStmt->execute([$id]);
                $newData = $updatedStmt->fetch(PDO::FETCH_ASSOC);

                // Determine action type
                $action = 'updated';
                if (($existing['status'] ?? '') !== $status) {
                    $action = 'status_changed';
                }
                if (($existing['assigned_to'] ?? null) != $assigned_to) {
                    $action = $assigned_to ? 'assigned' : 'unassigned';
                }
                if ($hasPriority && ($existing['priority'] ?? null) !== $priority) {
                    $action = 'priority_changed';
                }
                if ($hasMaintenanceType && ($existing['maintenance_type'] ?? null) !== $maintenance_type) {
                    $action = 'type_changed';
                }

                logMaintenanceAction($id, $action, $existing, $newData, $user['id'] ?? null, $user['username'] ?? null);

                reconcileMaintenanceRoomStatus($pdo, $room_id, $user['id'] ?? null);
                if ((int)($existing['individual_room_id'] ?? 0) !== $room_id) {
                    reconcileMaintenanceRoomStatus($pdo, (int)($existing['individual_room_id'] ?? 0), $user['id'] ?? null);
                }
                $pdo->commit();
                $message = 'Maintenance schedule updated successfully.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                }
            }
        } elseif ($action === 'delete_schedule') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Invalid schedule selected.';
            } else {
                $pdo->beginTransaction();
                $rowStmt = $pdo->prepare("SELECT individual_room_id FROM room_maintenance_schedules WHERE id = ?");
                $rowStmt->execute([$id]);
                $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException('Schedule not found.');
                }

                // Get schedule data before deletion for audit log
                $dataStmt = $pdo->prepare("SELECT * FROM room_maintenance_schedules WHERE id = ?");
                $dataStmt->execute([$id]);
                $deletedData = $dataStmt->fetch(PDO::FETCH_ASSOC);

                $pdo->prepare("DELETE FROM room_maintenance_schedules WHERE id = ?")->execute([$id]);

                reconcileMaintenanceRoomStatus($pdo, (int)($row['individual_room_id'] ?? 0), $user['id'] ?? null);

                // Log audit trail
                logMaintenanceAction($id, 'deleted', $deletedData, null, $user['id'] ?? null, $user['username'] ?? null);

                $pdo->commit();
                $message = 'Maintenance schedule deleted successfully.';
            }
        } elseif ($action === 'auto_create_tasks') {
            $pdo->beginTransaction();
            $created = autoCreateMaintenanceTasks($pdo, $user['id'] ?? null);
            $pdo->commit();
            $message = "Auto-created {$created} maintenance tasks.";
        } elseif ($action === 'bulk_assign_rooms') {
            $room_ids_raw = $_POST['room_ids'] ?? '[]';
            $room_ids = is_array($room_ids_raw) ? $room_ids_raw : (json_decode((string)$room_ids_raw, true) ?: []);
            $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $priority = $_POST['priority'] ?? 'medium';
            $maintenance_type = $_POST['maintenance_type'] ?? 'inspection';

            // Check which columns exist for backward compatibility
            $hasDueDate = maintenanceColumnExists($pdo, 'due_date');
            $hasMaintenanceType = maintenanceColumnExists($pdo, 'maintenance_type');
            $hasPriority = maintenanceColumnExists($pdo, 'priority');
            $hasAutoCreated = maintenanceColumnExists($pdo, 'auto_created');

            if (empty($room_ids)) {
                $error = 'No rooms selected.';
            } else {
                $pdo->beginTransaction();
                $created = 0;
                $today = date('Y-m-d');

                foreach ($room_ids as $room_id) {
                    $room_id = (int)$room_id;
                    if (!maintenanceRoomExists($pdo, $room_id)) {
                        continue;
                    }

                    // Check if pending assignment already exists
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM room_maintenance_schedules
                        WHERE individual_room_id = ? AND status IN ('pending', 'in_progress')
                    ");
                    $checkStmt->execute([$room_id]);
                    if ($checkStmt->fetch()) {
                        continue;
                    }

                    // Build INSERT columns and values based on available columns
                    $insertColumns = ['individual_room_id', 'title', 'status', 'start_date', 'end_date', 'assigned_to', 'created_by'];
                    $insertValues = ['?', '?', '?', '?', '?', '?', '?'];
                    $insertParams = [
                        $room_id,
                        'Bulk assigned maintenance task',
                        'pending',
                        date('Y-m-d H:i:s'),
                        date('Y-m-d H:i:s', strtotime('+1 day')),
                        $assigned_to,
                        $user['id'] ?? null
                    ];

                    if ($hasDueDate) {
                        $insertColumns[] = 'due_date';
                        $insertValues[] = '?';
                        $insertParams[] = $today;
                    }
                    if ($hasMaintenanceType) {
                        $insertColumns[] = 'maintenance_type';
                        $insertValues[] = '?';
                        $insertParams[] = $maintenance_type;
                    }
                    if ($hasPriority) {
                        $insertColumns[] = 'priority';
                        $insertValues[] = '?';
                        $insertParams[] = $priority;
                    }
                    if ($hasAutoCreated) {
                        $insertColumns[] = 'auto_created';
                        $insertValues[] = '?';
                        $insertParams[] = 1;
                    }

                    $insertSql = "INSERT INTO room_maintenance_schedules (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")";
                    $stmt = $pdo->prepare($insertSql);
                    $stmt->execute($insertParams);
                    $newMaintenanceId = (int)$pdo->lastInsertId();
                    $created++;

                    // Log audit trail for bulk created maintenance task
                    $newData = [
                        'individual_room_id' => $room_id,
                        'title' => 'Bulk assigned maintenance task',
                        'status' => 'pending',
                        'start_date' => date('Y-m-d H:i:s'),
                        'end_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
                        'assigned_to' => $assigned_to,
                        'created_by' => $user['id'] ?? null,
                    ];
                    if ($hasDueDate) $newData['due_date'] = $today;
                    if ($hasMaintenanceType) $newData['maintenance_type'] = $maintenance_type;
                    if ($hasPriority) $newData['priority'] = $priority;
                    if ($hasAutoCreated) $newData['auto_created'] = 1;

                    logMaintenanceAction($newMaintenanceId, 'created', null, $newData, $user['id'] ?? null, $user['username'] ?? null);

                    reconcileMaintenanceRoomStatus($pdo, $room_id, $user['id'] ?? null);
                }

                $pdo->commit();
                $message = "Bulk assigned {$created} rooms for maintenance.";
            }
        } elseif ($action === 'verify_schedule') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Invalid schedule selected.';
            } else {
                $hasVerifiedBy = maintenanceColumnExists($pdo, 'verified_by');
                $hasVerifiedAt = maintenanceColumnExists($pdo, 'verified_at');

                // If we don't have the required columns for verification, show error
                if (!$hasVerifiedBy || !$hasVerifiedAt) {
                    $error = 'Verification feature requires database migration 005. Please contact administrator.';
                } else {
                    $pdo->beginTransaction();
                    // Get schedule data before verification for audit log
                    $dataStmt = $pdo->prepare("SELECT * FROM room_maintenance_schedules WHERE id = ?");
                    $dataStmt->execute([$id]);
                    $beforeData = $dataStmt->fetch(PDO::FETCH_ASSOC);

                    $stmt = $pdo->prepare("
                        UPDATE room_maintenance_schedules
                        SET status = 'verified', verified_by = ?, verified_at = NOW()
                        WHERE id = ? AND status = 'completed'
                    ");
                    $stmt->execute([$user['id'] ?? null, $id]);

                    if ($stmt->rowCount() > 0) {
                        // Get updated data for audit log
                        $dataStmt->execute([$id]);
                        $afterData = $dataStmt->fetch(PDO::FETCH_ASSOC);

                        // Log audit trail
                        logMaintenanceAction($id, 'verified', $beforeData, $afterData, $user['id'] ?? null, $user['username'] ?? null);

                        if (!empty($beforeData['individual_room_id'])) {
                            reconcileMaintenanceRoomStatus($pdo, (int)$beforeData['individual_room_id'], $user['id'] ?? null);
                        }

                        $message = 'Maintenance verified successfully.';
                    } else {
                        $error = 'Schedule not found or not in completed status.';
                    }

                    $pdo->commit();
                }
            }
        } elseif ($action === 'mark_started') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Invalid schedule selected.';
            } else {
                $pdo->beginTransaction();
                $dataStmt = $pdo->prepare("SELECT * FROM room_maintenance_schedules WHERE id = ?");
                $dataStmt->execute([$id]);
                $beforeData = $dataStmt->fetch(PDO::FETCH_ASSOC);
                if (!$beforeData) throw new RuntimeException('Schedule not found.');
                $hasStartedAtCol = maintenanceColumnExists($pdo, 'started_at');
                if ($hasStartedAtCol) {
                    $pdo->prepare("UPDATE room_maintenance_schedules SET started_at = COALESCE(started_at, NOW()), status = CASE WHEN status = 'pending' THEN 'in_progress' ELSE status END WHERE id = ?")
                        ->execute([$id]);
                } else {
                    $pdo->prepare("UPDATE room_maintenance_schedules SET status = 'in_progress' WHERE id = ? AND status = 'pending'")
                        ->execute([$id]);
                }
                $dataStmt->execute([$id]);
                $afterData = $dataStmt->fetch(PDO::FETCH_ASSOC);
                logMaintenanceAction($id, 'status_changed', $beforeData, $afterData, $user['id'] ?? null, $user['username'] ?? null);
                reconcileMaintenanceRoomStatus($pdo, (int)$beforeData['individual_room_id'], $user['id'] ?? null);
                $pdo->commit();
                $message = 'Schedule marked as started.';
            }
        } elseif ($action === 'mark_complete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Invalid schedule selected.';
            } else {
                $pdo->beginTransaction();
                $dataStmt = $pdo->prepare("SELECT * FROM room_maintenance_schedules WHERE id = ?");
                $dataStmt->execute([$id]);
                $beforeData = $dataStmt->fetch(PDO::FETCH_ASSOC);
                if (!$beforeData) throw new RuntimeException('Schedule not found.');
                $hasStartedAtCol = maintenanceColumnExists($pdo, 'started_at');
                $setClause = "status = 'completed', completed_at = COALESCE(completed_at, NOW())";
                if ($hasStartedAtCol) {
                    $setClause .= ", started_at = COALESCE(started_at, NOW())";
                }
                $pdo->prepare("UPDATE room_maintenance_schedules SET {$setClause} WHERE id = ?")
                    ->execute([$id]);
                $dataStmt->execute([$id]);
                $afterData = $dataStmt->fetch(PDO::FETCH_ASSOC);
                logMaintenanceAction($id, 'status_changed', $beforeData, $afterData, $user['id'] ?? null, $user['username'] ?? null);
                reconcileMaintenanceRoomStatus($pdo, (int)$beforeData['individual_room_id'], $user['id'] ?? null);
                $pdo->commit();
                $message = 'Schedule marked as completed.';
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
    // Return AJAX error for validation failures (set $error but no exit above)
    if ($isAjax && $error) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $error]);
        exit;
    }
}

// Reconcile all rooms with open assignments
try {
    $roomRows = $pdo->query("SELECT DISTINCT individual_room_id FROM room_maintenance_schedules")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($roomRows as $roomId) {
        reconcileMaintenanceRoomStatus($pdo, (int)$roomId, $user['id'] ?? null);
    }
} catch (Throwable $syncError) {
    error_log('Maintenance reconciliation warning: ' . $syncError->getMessage());
}

// Get data for the page
$roomsStmt = $pdo->query("SELECT id, room_number, room_name, status FROM individual_rooms WHERE is_active = 1 ORDER BY room_number ASC");
$rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

$usersStmt = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 ORDER BY username ASC");
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get bookings for linking
$bookingsStmt = $pdo->query("
    SELECT b.id, b.booking_reference, b.guest_name, ir.room_number, b.check_in_date, b.check_out_date, b.status
    FROM bookings b
    LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
    WHERE b.status IN ('pending', 'confirmed', 'checked-in')
    ORDER BY b.check_in_date DESC
    LIMIT 100
");
$bookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get rooms needing maintenance
$roomsNeedingMaintenance = getRoomsNeedingMaintenance($pdo);

// Get staff workload
$staffWorkload = getMaintenanceStaffWorkload($pdo);

// Auto-trigger on every page load: create scheduled maintenance tasks silently
try {
    autoCreateMaintenanceTasks($pdo, (int)($user['id'] ?? 0));
} catch (Throwable $autoErr) {
    // Non-fatal — silently skip if tables/columns not ready
}

// Get all schedules with enhanced sorting
// Backward compatible: works with or without migration 005 columns
$hasPriority = maintenanceColumnExists($pdo, 'priority');
$hasVerifiedBy = maintenanceColumnExists($pdo, 'verified_by');
$hasMaintenanceType = maintenanceColumnExists($pdo, 'maintenance_type');
$hasDueDate = maintenanceColumnExists($pdo, 'due_date');

// Build ORDER BY clause based on available columns
$orderByClauses = [
    "CASE rms.status WHEN 'pending' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'completed' THEN 3 WHEN 'verified' THEN 4 WHEN 'cancelled' THEN 5 ELSE 99 END"
];

if ($hasPriority) {
    $orderByClauses[] = "CASE rms.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END";
}

if ($hasDueDate) {
    $orderByClauses[] = "rms.due_date ASC";
} else {
    $orderByClauses[] = "rms.start_date ASC";
}

$orderByClauses[] = "rms.created_at DESC";

$scheduleStmt = $pdo->query(
    "
    SELECT rms.*, ir.room_number, ir.room_name, u.username as assigned_to_name, creator.username as created_by_name" .
        ($hasVerifiedBy ? ", verifier.username as verified_by_name" : "") . "
    FROM room_maintenance_schedules rms
    LEFT JOIN individual_rooms ir ON rms.individual_room_id = ir.id
    LEFT JOIN admin_users u ON rms.assigned_to = u.id
    LEFT JOIN admin_users creator ON rms.created_by = creator.id" .
        ($hasVerifiedBy ? "
    LEFT JOIN admin_users verifier ON rms.verified_by = verifier.id" : "") . "
    ORDER BY " . implode(', ', $orderByClauses)
);
$schedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
// Backward compatible: works with or without migration 005 columns
$hasVerifiedAtStats = maintenanceColumnExists($pdo, 'verified_at');
$statsToday = date('Y-m-d');
$stats = [
    'today_total' => 0,
    'pending' => 0,
    'in_progress' => 0,
    'high_priority' => 0,
    'completed_today' => 0,
    'verified_today' => 0,
    'emergency_type' => 0,
];
foreach ($schedules as $s) {
    $status = (string)($s['status'] ?? '');
    if (isset($stats[$status])) {
        $stats[$status] = ($stats[$status] ?? 0) + 1;
    }

    if ($hasPriority && in_array((string)($s['priority'] ?? ''), ['high', 'urgent'], true) && in_array($status, ['pending', 'in_progress', 'completed'], true)) {
        $stats['high_priority']++;
    }

    $scheduledDateKey = $hasDueDate ? substr((string)($s['due_date'] ?? ''), 0, 10) : '';
    if ($scheduledDateKey === '') {
        $scheduledDateKey = substr((string)($s['start_date'] ?? ''), 0, 10);
    }
    if ($scheduledDateKey === $statsToday) {
        $stats['today_total']++;
    }

    $completedDateKey = substr((string)($s['completed_at'] ?? ''), 0, 10);
    if ($completedDateKey === $statsToday) {
        $stats['completed_today']++;
    }

    $verifiedDateKey = $hasVerifiedAtStats ? substr((string)($s['verified_at'] ?? ''), 0, 10) : '';
    if ($status === 'verified' && $verifiedDateKey === $statsToday) {
        $stats['verified_today']++;
    }

    if ($hasMaintenanceType && ($s['maintenance_type'] ?? null) === 'emergency') {
        $stats['emergency_type']++;
    }
}

// Maintenance logs
$maintenanceLogs = [];
try {
    if (maintenanceTableExists($pdo, 'room_maintenance_log')) {
        $logStmt = $pdo->prepare("
            SELECT rml.*, ir.room_number, ir.room_name, au.username AS performed_by_name
            FROM room_maintenance_log rml
            LEFT JOIN individual_rooms ir ON rml.individual_room_id = ir.id
            LEFT JOIN admin_users au ON rml.performed_by = au.id
            ORDER BY rml.created_at DESC, rml.id DESC
            LIMIT 50
        ");
        $logStmt->execute();
        $maintenanceLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('Unable to load maintenance logs: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Maintenance - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/room-maintenance.css?v=<?php echo @filemtime(__DIR__ . '/css/room-maintenance.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content">
        <div class="page-header">
            <h2><i class="fas fa-tools"></i> Room Maintenance Management</h2>
            <div class="rm-page-header__actions">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="auto_create_tasks">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button class="btn btn-warning" type="button" onclick="rmConfirm(this.closest('form'), 'Auto-create maintenance tasks for all rooms that need them?', 'Auto-Create', 'btn-warning')">
                        <i class="fas fa-magic"></i> Auto-Create Tasks
                    </button>
                </form>
                <button class="btn btn-primary" type="button" onclick="openModal()"><i class="fas fa-plus"></i> Add Maintenance</button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success rm-alert rm-alert--success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger rm-alert rm-alert--error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Dashboard Statistics -->
        <div class="rm-dashboard" id="rmStatsDashboard">
            <button type="button" class="rm-stat-card rm-stat-card--interactive today_total" data-stat-key="today_total" data-stat-title="Today's Maintenance Tasks" data-stat-description="All maintenance schedules set for today.">
                <span class="rm-stat-card__value"><?php echo (int)$stats['today_total']; ?></span>
                <span class="rm-stat-card__label"><i class="fas fa-calendar-day"></i> Today's Tasks</span>
                <span class="rm-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="rm-stat-card rm-stat-card--interactive pending" data-stat-key="pending" data-stat-title="Pending Tasks" data-stat-description="Maintenance tasks waiting for a technician.">
                <span class="rm-stat-card__value"><?php echo (int)$stats['pending']; ?></span>
                <span class="rm-stat-card__label"><i class="fas fa-clock"></i> Pending Tasks</span>
                <span class="rm-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="rm-stat-card rm-stat-card--interactive in_progress" data-stat-key="in_progress" data-stat-title="In Progress" data-stat-description="Repairs currently in active work.">
                <span class="rm-stat-card__value"><?php echo (int)$stats['in_progress']; ?></span>
                <span class="rm-stat-card__label"><i class="fas fa-spinner"></i> In Progress</span>
                <span class="rm-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="rm-stat-card rm-stat-card--interactive completed" data-stat-key="completed_today" data-stat-title="Completed Today" data-stat-description="Maintenance jobs completed today.">
                <span class="rm-stat-card__value"><?php echo (int)$stats['completed_today']; ?></span>
                <span class="rm-stat-card__label"><i class="fas fa-check"></i> Completed Today</span>
                <span class="rm-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="rm-stat-card rm-stat-card--interactive verified" data-stat-key="verified_today" data-stat-title="Verified Today" data-stat-description="Maintenance jobs verified today.">
                <span class="rm-stat-card__value"><?php echo (int)$stats['verified_today']; ?></span>
                <span class="rm-stat-card__label"><i class="fas fa-check-double"></i> Verified Today</span>
                <span class="rm-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="rm-stat-card rm-stat-card--interactive high_priority" data-stat-key="high_priority" data-stat-title="High/Urgent Priority" data-stat-description="Critical tasks that should be handled first.">
                <span class="rm-stat-card__value"><?php echo (int)$stats['high_priority']; ?></span>
                <span class="rm-stat-card__label"><i class="fas fa-exclamation-triangle"></i> High/Urgent Priority</span>
                <span class="rm-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
            <button type="button" class="rm-stat-card rm-stat-card--interactive emergency_type" data-stat-key="emergency_type" data-stat-title="Emergency" data-stat-description="Emergency maintenance items across rooms.">
                <span class="rm-stat-card__value"><?php echo (int)$stats['emergency_type']; ?></span>
                <span class="rm-stat-card__label"><i class="fas fa-bolt"></i> Emergency</span>
                <span class="rm-stat-card__hint"><i class="fas fa-circle-info"></i> Hover for insight • Click for details</span>
            </button>
        </div>
        <script type="application/json" id="rmScheduleData">
            <?php echo json_encode(array_map(static function (array $row): array {
                $scheduledDate = (string)($row['due_date'] ?? '');
                if ($scheduledDate === '') {
                    $scheduledDate = (string)($row['start_date'] ?? '');
                }
                return [
                    'status' => (string)($row['status'] ?? ''),
                    'priority' => (string)($row['priority'] ?? ''),
                    'type' => (string)($row['maintenance_type'] ?? ''),
                    'room' => trim((string)($row['room_number'] ?? '') . ' ' . (string)($row['room_name'] ?? '')),
                    'assignee' => (string)($row['assigned_to_name'] ?? 'Unassigned'),
                    'dueDate' => $scheduledDate,
                    'scheduleDate' => $scheduledDate,
                    'title' => (string)($row['title'] ?? ''),
                    'completedAt' => (string)($row['completed_at'] ?? ''),
                    'verifiedAt' => (string)($row['verified_at'] ?? ''),
                ];
            }, $schedules), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]'; ?>
        </script>

        <!-- Rooms Needing Maintenance Section -->
        <?php if (!empty($roomsNeedingMaintenance)): ?>
            <div class="rm-section">
                <h3><i class="fas fa-exclamation-circle"></i> Rooms Needing Maintenance (<?php echo count($roomsNeedingMaintenance); ?>)</h3>
                <form method="POST" id="bulkAssignForm">
                    <input type="hidden" name="action" value="bulk_assign_rooms">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="room_ids" id="selectedRoomIds">
                    <div class="bulk-actions">
                        <select name="assigned_to" id="bulkAssignTo" class="rm-inline-control">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="maintenance_type" class="rm-inline-control">
                            <option value="inspection">Inspection</option>
                            <option value="repair">Repair</option>
                            <option value="replacement">Replacement</option>
                            <option value="upgrade">Upgrade</option>
                            <option value="emergency">Emergency</option>
                        </select>
                        <select name="priority" class="rm-inline-control">
                            <option value="medium">Medium Priority</option>
                            <option value="high">High Priority</option>
                            <option value="urgent">Urgent Priority</option>
                            <option value="low">Low Priority</option>
                        </select>
                        <button type="button" class="btn-quick" onclick="selectAllRooms()">
                            <i class="fas fa-check-square"></i> Select All
                        </button>
                        <button type="submit" class="btn btn-primary rm-bulk-assign-btn" id="bulkAssignBtn" disabled>
                            <i class="fas fa-plus"></i> Assign Selected (<span id="selectedCount">0</span>)
                        </button>
                    </div>
                    <p class="rm-bulk-helper">
                        <i class="fas fa-hand-pointer"></i> Tap a room card to select it, then assign type/priority in one action.
                    </p>
                    <div class="rooms-needing-list">
                        <?php foreach ($roomsNeedingMaintenance as $room): ?>
                            <div class="room-needs-card <?php echo $room['room_status'] === 'out_of_order' ? 'out-of-order' : ($room['room_status'] === 'maintenance' ? 'maintenance' : ''); ?>" data-room-id="<?php echo $room['id']; ?>" onclick="toggleRoomCard(this)" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' ')toggleRoomCard(this)">
                                <div class="room-header">
                                    <span class="room-number"><?php echo htmlspecialchars($room['room_number'] . ' ' . ($room['room_name'] ?? '')); ?></span>
                                    <span class="room-condition"><?php echo ucfirst(str_replace('_', ' ', $room['room_status'] ?? 'available')); ?></span>
                                </div>
                                <?php if (!empty($room['guest_name'])): ?>
                                    <div class="guest-info"><i class="fas fa-user"></i> <?php echo htmlspecialchars($room['guest_name']); ?></div>
                                <?php endif; ?>
                                <div class="rm-room-select-indicator">
                                    <input type="checkbox" class="room-checkbox" value="<?php echo $room['id']; ?>" tabindex="-1" onclick="event.stopPropagation()">
                                    <span class="select-label"><i class="fas fa-circle-check"></i> Selected</span>
                                    <span class="unselect-label"><i class="far fa-circle"></i> Click to select</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Staff Workload Section -->
        <?php if (!empty($staffWorkload)): ?>
            <div class="rm-section">
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
                                <td><?php echo $staff['active_tasks']; ?></td>
                                <td><?php echo $staff['high_priority_pending']; ?></td>
                                <td><?php echo $staff['completed_today']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Maintenance Schedules Table -->
        <?php
        $sched_per_page    = 10;
        $sched_page        = max(1, (int)($_GET['sched_page'] ?? 1));
        $sched_total       = count($schedules);
        $sched_total_pages = $sched_total > 0 ? (int)ceil($sched_total / $sched_per_page) : 1;
        $sched_display     = array_slice($schedules, ($sched_page - 1) * $sched_per_page, $sched_per_page);
        ?>
        <div class="table-card" id="maintenanceSchedulesContainer">
            <div class="rm-table-toolbar">
                <h3 class="rm-table-toolbar__title"><i class="fas fa-list-check"></i> All Maintenance Tasks (<?php echo count($schedules); ?>)</h3>
                <div class="rm-table-toolbar__filters">
                    <input type="text" id="tableSearch" class="rm-inline-control rm-inline-control--search" placeholder="Search room, title, staff..." oninput="filterTable()">
                    <select id="tableStatusFilter" class="rm-inline-control" onchange="filterTable()">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="verified">Verified</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Scheduled</th>
                        <th>Assigned To</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:24px;">No maintenance schedules.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sched_display as $row): ?>
                            <tr data-status="<?php echo htmlspecialchars((string)$row['status']); ?>" data-priority="<?php echo htmlspecialchars((string)($row['priority'] ?? '')); ?>" data-type="<?php echo htmlspecialchars((string)($row['maintenance_type'] ?? '')); ?>" data-room="<?php echo htmlspecialchars(trim((string)($row['room_number'] ?? '') . ' ' . (string)($row['room_name'] ?? ''))); ?>" data-assignee="<?php echo htmlspecialchars((string)($row['assigned_to_name'] ?? 'Unassigned')); ?>" data-due-date="<?php echo htmlspecialchars((string)($row['due_date'] ?? '')); ?>">
                                <td><?php echo htmlspecialchars($row['room_number'] . ' ' . ($row['room_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($row['title']); ?></td>
                                <td>
                                    <?php if ($hasMaintenanceType): ?>
                                        <span class="type-badge <?php echo $row['maintenance_type']; ?>"><?php echo ucfirst($row['maintenance_type']); ?></span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($hasPriority): ?>
                                        <span class="priority-badge <?php echo $row['priority']; ?>"><?php echo ucfirst($row['priority']); ?></span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><span class="status-pill <?php echo $row['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?></span></td>
                                <td>
                                    <span><?php echo $hasDueDate && !empty($row['due_date']) ? date('M j', strtotime($row['due_date'])) : date('M j', strtotime($row['start_date'])); ?></span>
                                    <?php if (!empty($row['started_at'])): ?>
                                        <br><small style="color:#0c8d6c;"><i class="fas fa-play-circle"></i> <?php echo date('H:i', strtotime($row['started_at'])); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($row['completed_at'])): ?>
                                        <br><small style="color:#1e7a34;"><i class="fas fa-check-circle"></i> <?php echo date('H:i', strtotime($row['completed_at'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['assigned_to_name'] ?? '-'); ?></td>
                                <td>
                                    <div class="rm-row-actions">
                                        <?php if ($row['status'] === 'pending'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="mark_started">
                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <button class="btn btn-warning btn-sm" type="button" title="Mark as started" onclick="rmConfirm(this.closest('form'), 'Mark this maintenance as started?', 'Start', 'btn-warning')"><i class="fas fa-play"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (in_array($row['status'], ['pending', 'in_progress'], true)): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="mark_complete">
                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <button class="btn btn-success btn-sm" type="button" title="Mark done" onclick="rmConfirm(this.closest('form'), 'Mark this maintenance as completed?', 'Mark Done', 'btn-success')"><i class="fas fa-check"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($row['status'] !== 'verified'): ?>
                                            <button class="btn btn-info btn-sm" type="button" onclick='editSchedule(<?php echo htmlspecialchars((string)json_encode($row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                                        <?php endif; ?>
                                        <button class="btn btn-secondary btn-sm" type="button" onclick='viewAuditLog(<?php echo $row['id']; ?>, "<?php echo htmlspecialchars($row['title']); ?>")' title="View History"><i class="fas fa-history"></i></button>
                                        <?php if ($row['status'] === 'completed'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="verify_schedule">
                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <button class="btn btn-success btn-sm" type="button" title="Verify" onclick="rmConfirm(this.closest('form'), 'Mark this maintenance as verified? This cannot be undone.', 'Verify', 'btn-success')"><i class="fas fa-check-double"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_schedule">
                                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <button class="btn btn-danger btn-sm" type="button" onclick="rmConfirm(this.closest('form'), 'Delete this schedule? This cannot be undone.', 'Delete', 'btn-danger')"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($sched_total_pages > 1): ?>
            <nav style="display:flex;align-items:center;justify-content:center;gap:6px;padding:16px 0;flex-wrap:wrap;">
                <?php for ($pg = 1; $pg <= $sched_total_pages; $pg++):
                    $pgHref = 'room-maintenance.php?' . http_build_query(['sched_page' => $pg]);
                    $pgActive = ($pg === $sched_page);
                ?>
                    <a href="<?php echo htmlspecialchars($pgHref, ENT_QUOTES, 'UTF-8'); ?>"
                        style="padding:6px 12px;border:1px solid <?php echo $pgActive ? 'var(--color-primary,#8A775F)' : '#dee2e6'; ?>;background:<?php echo $pgActive ? 'var(--color-primary,#8A775F)' : '#fff'; ?>;color:<?php echo $pgActive ? '#fff' : '#374151'; ?>;border-radius:4px;font-size:13px;text-decoration:none;"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <span style="padding:6px 8px;font-size:12px;color:#888;">
                    Showing <?php echo (($sched_page - 1) * $sched_per_page) + 1; ?>–<?php echo min($sched_page * $sched_per_page, $sched_total); ?> of <?php echo $sched_total; ?>
                </span>
            </nav>
        <?php endif; ?>

        <!-- Maintenance Log -->
        <?php if (!empty($maintenanceLogs)): ?>
            <div class="table-card" style="margin-top:16px;">
                <div style="padding:12px 16px;border-bottom:1px solid #eef2f7;font-weight:700;color:#1f2d3d;">
                    <i class="fas fa-history"></i> Maintenance Log
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Reason</th>
                            <th>By</th>
                            <th>At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($maintenanceLogs as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(($log['room_number'] ?? '-') . ' ' . ($log['room_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($log['status_from'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($log['status_to'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($log['reason'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($log['performed_by_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($log['created_at'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php renderAdminModalStart('scheduleModal', 'Add Maintenance', 'maintenance-modal-content'); ?>
    <form method="POST" id="scheduleForm">
        <input type="hidden" name="action" id="formAction" value="add_schedule">
        <input type="hidden" name="id" id="scheduleId">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <div class="form-group">
            <label>Room *</label>
            <select name="individual_room_id" id="roomSelect" required>
                <option value="">Select room</option>
                <?php foreach ($rooms as $r): ?>
                    <option value="<?php echo $r['id']; ?>" data-status="<?php echo $r['status']; ?>">
                        <?php echo htmlspecialchars($r['room_number'] . ' ' . ($r['room_name'] ?? '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="color: #6b7280; font-size: 12px;">Room status will be shown when selected</small>
        </div>
        <div class="form-group">
            <label>Title *</label>
            <input type="text" name="title" id="title" required>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" id="description" rows="2"></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Maintenance Type *</label>
                <select name="maintenance_type" id="maintenance_type" required>
                    <option value="repair">Repair</option>
                    <option value="replacement">Replacement</option>
                    <option value="inspection">Inspection</option>
                    <option value="upgrade">Upgrade</option>
                    <option value="emergency">Emergency</option>
                </select>
            </div>
            <div class="form-group">
                <label>Priority *</label>
                <select name="priority" id="priority" required>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                    <option value="low">Low</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Due Date *</label>
                <input type="date" name="due_date" id="due_date" required min="<?php echo date('Y-m-d'); ?>">
                <small style="color: #6b7280; font-size: 12px;">Due date cannot be in the past</small>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" id="status">
                    <option value="pending">Pending</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="verified">Verified</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Start Date</label>
                <input type="datetime-local" name="start_date" id="start_date">
            </div>
            <div class="form-group">
                <label>End Date</label>
                <input type="datetime-local" name="end_date" id="end_date">
            </div>
        </div>
        <div class="form-group">
            <label>Started At <small style="color:#9ca3af;">(auto-stamped or enter manually)</small></label>
            <input type="datetime-local" name="started_at" id="started_at">
            <small style="color: #6b7280; font-size: 12px;">When staff actually began work — leave blank to stamp automatically</small>
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
            <label>Linked Booking</label>
            <select name="linked_booking_id" id="linked_booking_id">
                <option value="">None</option>
                <?php foreach ($bookings as $b): ?>
                    <option value="<?php echo $b['id']; ?>">
                        <?php echo htmlspecialchars($b['booking_reference'] . ' - ' . $b['guest_name'] . ' (' . $b['room_number'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Estimated Duration (minutes)</label>
            <input type="number" name="estimated_duration" id="estimated_duration" value="60" min="5" step="5">
        </div>
        <div class="form-group">
            <label>Actual Duration (minutes)</label>
            <input type="number" name="actual_duration" id="actual_duration" min="1" step="1" placeholder="Fill when completed">
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="block_room" id="block_room" value="1" checked> Block room during maintenance</label>
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
        <div style="display:flex; gap:10px; justify-content:flex-end; align-items:center;">
            <div id="scheduleFeedback" class="admin-modal-feedback" style="flex:1;"></div>
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
            <button type="submit" id="scheduleSaveBtn" class="btn btn-primary">Save</button>
        </div>
    </form>
    <?php renderAdminModalEnd(); ?>

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

    <?php renderAdminModalStart('statsQuickModal', 'Maintenance Snapshot', 'maintenance-modal-content rm-stats-modal'); ?>
    <div id="rmStatsModalMeta" class="rm-stats-modal__meta"></div>
    <div id="rmStatsModalList" class="rm-stats-modal__list"></div>
    <div class="rm-stats-modal__actions">
        <button type="button" class="btn btn-secondary" onclick="closeStatsQuickModal()">Close</button>
    </div>
    <?php renderAdminModalEnd(); ?>

    <!-- Confirm action modal (replaces window.confirm) -->
    <div class="modal-overlay" id="rmConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
        <div class="modal-content" style="max-width:420px;border-radius:12px;padding:0;overflow:hidden;">
            <div class="modal-header" style="padding:16px 20px;border-bottom:1px solid #e5e7eb;">
                <h3 style="margin:0;font-size:15px;" id="rmConfirmTitle">Confirm Action</h3>
            </div>
            <div class="modal-body" style="padding:16px 20px;">
                <p style="margin:0;font-size:14px;color:#374151;" id="rmConfirmMessage">Are you sure?</p>
            </div>
            <div class="modal-footer" style="padding:12px 20px;display:flex;gap:8px;justify-content:flex-end;border-top:1px solid #e5e7eb;">
                <button type="button" class="btn btn-secondary" onclick="rmCloseConfirm()">Cancel</button>
                <button type="button" class="btn btn-danger" id="rmConfirmBtn" onclick="rmDoConfirm()">Confirm</button>
            </div>
        </div>
    </div>

    <?php renderAdminModalScript(); ?>

    <script>
        const rmTodayKey = <?php echo json_encode($statsToday, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function rmToDateKey(value) {
            return String(value || '').trim().slice(0, 10);
        }

        const rmStatConfig = {
            today_total: {
                title: "Today's Maintenance Tasks",
                description: 'All maintenance schedules set for today.',
                match: function(item) {
                    return item.scheduleDateKey === rmTodayKey;
                }
            },
            pending: {
                title: 'Pending Tasks',
                description: 'Maintenance tasks waiting to be started.',
                match: function(item) {
                    return item.status === 'pending';
                }
            },
            in_progress: {
                title: 'In Progress',
                description: 'Repairs currently in active maintenance.',
                match: function(item) {
                    return item.status === 'in_progress';
                }
            },
            completed_today: {
                title: 'Completed Today',
                description: 'Maintenance jobs completed today.',
                match: function(item) {
                    return item.completedDateKey === rmTodayKey;
                }
            },
            verified_today: {
                title: 'Verified Today',
                description: 'Maintenance jobs verified today.',
                match: function(item) {
                    return item.status === 'verified' && item.verifiedDateKey === rmTodayKey;
                }
            },
            high_priority: {
                title: 'High/Urgent Priority',
                description: 'Critical tasks that must be resolved first.',
                match: function(item) {
                    return (item.priority === 'high' || item.priority === 'urgent') && (item.status === 'pending' || item.status === 'in_progress' || item.status === 'completed');
                }
            },
            emergency_type: {
                title: 'Emergency Type',
                description: 'Emergency maintenance jobs across rooms.',
                match: function(item) {
                    return item.type === 'emergency';
                }
            }
        };

        function getScheduleRows() {
            const payload = document.getElementById('rmScheduleData');
            if (!payload) return [];

            try {
                const parsed = JSON.parse(payload.textContent || '[]');
                if (!Array.isArray(parsed)) return [];
                return parsed.map(function(item) {
                    const dueDate = String(item.dueDate || '');
                    const scheduleDate = String(item.scheduleDate || dueDate);
                    const completedAt = String(item.completedAt || '');
                    const verifiedAt = String(item.verifiedAt || '');
                    return {
                        status: String(item.status || '').toLowerCase(),
                        priority: String(item.priority || '').toLowerCase(),
                        type: String(item.type || '').toLowerCase(),
                        room: String(item.room || '-'),
                        assignee: String(item.assignee || 'Unassigned'),
                        dueDate: dueDate,
                        title: String(item.title || ''),
                        scheduleDateKey: rmToDateKey(scheduleDate),
                        completedDateKey: rmToDateKey(completedAt),
                        verifiedDateKey: rmToDateKey(verifiedAt)
                    };
                });
            } catch (error) {
                return [];
            }
        }

        function openStatsQuickModal(statKey) {
            const config = rmStatConfig[statKey];
            if (!config) return;

            const rows = getScheduleRows().filter(function(item) {
                return config.match(item);
            });

            const titleEl = document.getElementById('statsQuickModal-title');
            const metaEl = document.getElementById('rmStatsModalMeta');
            const listEl = document.getElementById('rmStatsModalList');
            if (!titleEl || !metaEl || !listEl) return;

            titleEl.textContent = config.title + ' - Quick Insights';
            metaEl.innerHTML = '';
            listEl.innerHTML = '';

            const countEl = document.createElement('div');
            countEl.className = 'rm-stats-modal__count';
            countEl.textContent = String(rows.length);

            const descWrap = document.createElement('div');
            descWrap.className = 'rm-stats-modal__copy';
            const descTitle = document.createElement('div');
            descTitle.className = 'rm-stats-modal__copy-title';
            descTitle.textContent = config.title;
            const descText = document.createElement('p');
            descText.textContent = config.description;
            descWrap.appendChild(descTitle);
            descWrap.appendChild(descText);
            metaEl.appendChild(countEl);
            metaEl.appendChild(descWrap);

            if (rows.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'rm-stats-modal__empty';
                empty.innerHTML = '<i class="fas fa-check-circle"></i><span>No tasks in this bucket right now.</span>';
                listEl.appendChild(empty);
                openAdminModal('statsQuickModal');
                return;
            }

            rows.slice(0, 12).forEach(function(item) {
                const row = document.createElement('article');
                row.className = 'rm-stats-modal__item';

                const top = document.createElement('div');
                top.className = 'rm-stats-modal__item-top';

                const room = document.createElement('strong');
                room.textContent = item.room;
                top.appendChild(room);

                const status = document.createElement('span');
                status.className = 'status-pill ' + (item.status || 'pending');
                status.textContent = (item.status || 'pending').replace('_', ' ');
                top.appendChild(status);

                const meta = document.createElement('div');
                meta.className = 'rm-stats-modal__item-meta';
                meta.textContent = 'Assigned: ' + (item.assignee || 'Unassigned') + ' • Due: ' + (item.dueDate || 'N/A');

                const title = document.createElement('p');
                title.className = 'rm-stats-modal__item-note';
                title.textContent = (item.title || '').trim() !== '' ? item.title.trim() : 'Untitled maintenance task.';

                row.appendChild(top);
                row.appendChild(meta);
                row.appendChild(title);
                listEl.appendChild(row);
            });

            if (rows.length > 12) {
                const more = document.createElement('div');
                more.className = 'rm-stats-modal__more';
                more.textContent = '+' + String(rows.length - 12) + ' more task(s). Use table filters for full list.';
                listEl.appendChild(more);
            }

            openAdminModal('statsQuickModal');
        }

        function closeStatsQuickModal() {
            closeAdminModal('statsQuickModal');
        }

        document.addEventListener('click', function(event) {
            const card = event.target.closest('.rm-stat-card--interactive');
            if (!card) return;
            const statKey = card.getAttribute('data-stat-key') || '';
            openStatsQuickModal(statKey);
        });

        document.addEventListener('keydown', function(event) {
            const card = event.target.closest('.rm-stat-card--interactive');
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

            // Auto-set urgent priority for emergency type
            const maintenanceTypeSelect = document.getElementById('maintenance_type');
            const prioritySelect = document.getElementById('priority');
            if (maintenanceTypeSelect && prioritySelect) {
                maintenanceTypeSelect.addEventListener('change', function() {
                    if (this.value === 'emergency') {
                        prioritySelect.value = 'urgent';
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
                    const small = this.parentElement.querySelector('small');
                    if (small && this.value) {
                        small.textContent = 'Room status: ' + (roomStatus || 'unknown');
                    }
                });
            }

            // Set default dates
            const now = new Date();
            const tomorrow = new Date(now);
            tomorrow.setDate(tomorrow.getDate() + 1);

            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            if (startDateInput) {
                startDateInput.value = now.toISOString().slice(0, 16);
            }
            if (endDateInput) {
                endDateInput.value = tomorrow.toISOString().slice(0, 16);
            }
        });

        function openModal() {
            document.getElementById('scheduleModal-title').textContent = 'Add Maintenance';
            document.getElementById('formAction').value = 'add_schedule';
            document.getElementById('scheduleForm').reset();
            document.getElementById('scheduleId').value = '';
            document.getElementById('due_date').min = new Date().toISOString().split('T')[0];
            document.getElementById('recurringOptions').style.display = 'none';

            // Set default dates
            const now = new Date();
            const tomorrow = new Date(now);
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('start_date').value = now.toISOString().slice(0, 16);
            document.getElementById('end_date').value = tomorrow.toISOString().slice(0, 16);

            openAdminModal('scheduleModal');
        }

        function closeModal() {
            closeAdminModal('scheduleModal');
            var fb = document.getElementById('scheduleFeedback');
            if (fb) {
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
            }
        }

        function editSchedule(data) {
            document.getElementById('scheduleModal-title').textContent = 'Edit Maintenance';
            document.getElementById('formAction').value = 'update_schedule';
            document.getElementById('scheduleId').value = data.id;
            document.getElementById('roomSelect').value = data.individual_room_id;
            document.getElementById('title').value = data.title;
            document.getElementById('description').value = data.description || '';
            document.getElementById('maintenance_type').value = data.maintenance_type || 'repair';
            document.getElementById('priority').value = data.priority || 'medium';
            document.getElementById('due_date').value = data.due_date || '';
            document.getElementById('status').value = data.status;
            document.getElementById('assigned_to').value = data.assigned_to || '';
            document.getElementById('linked_booking_id').value = data.linked_booking_id || '';
            document.getElementById('estimated_duration').value = data.estimated_duration || 60;
            document.getElementById('actual_duration').value = data.actual_duration || '';
            document.getElementById('block_room').checked = data.block_room == 1;

            // Set dates
            if (data.start_date) {
                document.getElementById('start_date').value = toDatetimeLocal(data.start_date);
            }
            if (data.end_date) {
                document.getElementById('end_date').value = toDatetimeLocal(data.end_date);
            }

            const isRecurringCheckbox = document.getElementById('is_recurring');
            isRecurringCheckbox.checked = data.is_recurring == 1;
            document.getElementById('recurringOptions').style.display = data.is_recurring == 1 ? 'block' : 'none';
            document.getElementById('recurring_pattern').value = data.recurring_pattern || 'daily';
            document.getElementById('recurring_end_date').value = data.recurring_end_date || '';

            if (document.getElementById('started_at')) {
                document.getElementById('started_at').value = data.started_at ? data.started_at.replace(' ', 'T').substring(0, 16) : '';
            }

            openAdminModal('scheduleModal');
        }

        function selectAllRooms() {
            const checkboxes = document.querySelectorAll('.room-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => {
                cb.checked = !allChecked;
                const card = cb.closest('.room-needs-card');
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

        function toDatetimeLocal(value) {
            if (!value) return '';
            return String(value).replace(' ', 'T').slice(0, 16);
        }

        bindAdminModal('scheduleModal');
        bindAdminModal('auditLogModal');
        bindAdminModal('statsQuickModal');

        function viewAuditLog(maintenanceId, title) {
            document.getElementById('auditLogModal-title').textContent = 'Audit History - ' + title + ' (ID: ' + maintenanceId + ')';
            document.getElementById('auditLogContent').innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            openAdminModal('auditLogModal');

            // Fetch audit log via AJAX
            fetch('api/get-maintenance-audit.php?id=' + encodeURIComponent(maintenanceId), {
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
                        const actionBadge = getMaintenanceActionBadge(log.action);
                        const formattedDate = new Date(log.performed_at).toLocaleString();
                        const changes = formatMaintenanceChanges(log);

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

        function getMaintenanceActionBadge(action) {
            const badges = {
                'created': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #d1fae5; color: #065f46;">Created</span>',
                'updated': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #dbeafe; color: #1e40af;">Updated</span>',
                'deleted': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #fee2e2; color: #991b1b;">Deleted</span>',
                'verified': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #e0e7ff; color: #3730a3;">Verified</span>',
                'status_changed': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #fef3c7; color: #92400e;">Status Changed</span>',
                'assigned': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #f3e8ff; color: #6b21a8;">Assigned</span>',
                'unassigned': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #f3f4f6; color: #374151;">Unassigned</span>',
                'priority_changed': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #ffedd5; color: #9a3412;">Priority Changed</span>',
                'type_changed': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #fce7f3; color: #9d174d;">Type Changed</span>',
                'recurring_created': '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #ecfdf5; color: #064e3b;">Recurring Created</span>',
            };
            return badges[action] || '<span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #f3f4f6; color: #374151;">' + action + '</span>';
        }

        function formatMaintenanceChanges(log) {
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

        document.getElementById('scheduleForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var saveBtn = document.getElementById('scheduleSaveBtn');
            var fb = document.getElementById('scheduleFeedback');
            var origHtml = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving\u2026';
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
                    if (res.success) refreshMaintenanceTable();
                })
                .catch(function() {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error \u2014 please try again.';
                });
        });

        function refreshMaintenanceTable() {
            fetch(window.location.href)
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var next = doc.getElementById('maintenanceSchedulesContainer');
                    var cur = document.getElementById('maintenanceSchedulesContainer');
                    var nextStats = doc.getElementById('rmStatsDashboard');
                    var curStats = document.getElementById('rmStatsDashboard');
                    var nextData = doc.getElementById('rmScheduleData');
                    var curData = document.getElementById('rmScheduleData');
                    if (next && cur) cur.outerHTML = next.outerHTML;
                    if (nextStats && curStats) curStats.innerHTML = nextStats.innerHTML;
                    if (nextData && curData) curData.textContent = nextData.textContent;
                }).catch(function() {});
        }

        function filterTable() {
            var query = (document.getElementById('tableSearch').value || '').toLowerCase();
            var status = (document.getElementById('tableStatusFilter').value || '').toLowerCase();
            var rows = document.querySelectorAll('#maintenanceSchedulesContainer tbody tr[data-status]');
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                var rowStat = (row.getAttribute('data-status') || '').toLowerCase();
                var matchTxt = !query || text.includes(query);
                var matchSt = !status || rowStat === status;
                row.style.display = matchTxt && matchSt ? '' : 'none';
            });
        }

        // ── Confirm modal helpers ───────────────────────────────────────────
        var _rmConfirmForm = null;

        function rmConfirm(form, message, btnLabel, btnClass) {
            _rmConfirmForm = form;
            document.getElementById('rmConfirmMessage').textContent = message || 'Are you sure?';
            var btn = document.getElementById('rmConfirmBtn');
            btn.textContent = btnLabel || 'Confirm';
            btn.className = 'btn ' + (btnClass || 'btn-danger');
            document.getElementById('rmConfirmModal').style.display = 'flex';
        }

        function rmCloseConfirm() {
            document.getElementById('rmConfirmModal').style.display = 'none';
            _rmConfirmForm = null;
        }

        function rmDoConfirm() {
            var formToSubmit = _rmConfirmForm;
            rmCloseConfirm();
            if (formToSubmit) formToSubmit.submit();
        }
        document.getElementById('rmConfirmModal').addEventListener('click', function(e) {
            if (e.target === this) rmCloseConfirm();
        });
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

