<?php

/**
 * Housekeeping Management - Admin Panel
 * Enhanced with priority-based assignments, occupied rooms auto-fetch,
 * staff workload tracking, and checkout cleanup automation
 */
require_once 'admin-init.php';
require_once 'includes/admin-modal.php';
require_once __DIR__ . '/../includes/form-validation.php';
require_once __DIR__ . '/includes/bulk-actions.php';
require_once __DIR__ . '/includes/front-desk-counts.php'; // rh_fd_housekeeping_due_sql: the Dashboard "tasks due" rule
require_once __DIR__ . '/../includes/ops-extend.php'; // Repeat until... (GRID_UNIFY_PLAN Phase 3)
/** @var array{id: int, username: string, role: string} $user */
/** @var string $csrf_token */

if (!hasPermission($user['id'], 'housekeeping')) {
    rhDenyAndRedirectHome((int)$_SESSION['admin_user_id'], (string)($_SESSION['admin_role'] ?? ''), basename($_SERVER['PHP_SELF']));
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
    // "Guest has left" state per card: the latest checkout cleanup linked to this booking + room (made before checkout).
    $earlySelect = "NULL AS early_task_status,";
    if (housekeepingColumnExists($pdo, 'assignment_type') && housekeepingColumnExists($pdo, 'linked_booking_id')) {
        $earlySelect = "(SELECT ha.status FROM housekeeping_assignments ha
                WHERE ha.individual_room_id = ir.id AND ha.linked_booking_id = b.id AND ha.assignment_type = 'checkout_cleanup'
                ORDER BY ha.id DESC LIMIT 1) AS early_task_status,";
    }
    $sql = "
        SELECT DISTINCT
            {$earlySelect}
            ir.id,
            ir.room_number,
            ir.room_name,
            ir.status as room_status,
            ir.housekeeping_status,
            b.id as booking_id,
            b.guest_name,
            b.room_guest_name,
            b.check_out_date,
            b.status as booking_status,
            CASE
                WHEN b.check_out_date = CURDATE() THEN 'checkout_today'
                WHEN b.check_out_date < CURDATE() THEN 'overdue_checkout'
                ELSE 'occupied'
            END as occupancy_type
        FROM individual_rooms ir
        INNER JOIN bookings b ON (b.individual_room_id = ir.id OR EXISTS (
            SELECT 1 FROM booking_rooms br WHERE br.booking_id = b.id AND br.individual_room_id = ir.id AND br.released_at IS NULL))
        WHERE b.status = 'checked-in'
          AND b.deleted_at IS NULL
          AND b.check_in_date <= CURDATE()
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
 * housekeeping_assignments.status is enum(pending, in_progress, completed, blocked) - it has no
 * 'verified' value, and strict SQL rejects one. 'Verified' is stored as completed + verified_at set
 * and translated back when read (same approach as the maintenance schedules).
 */
function rhHkStatusToDb(string $status): string
{
    return $status === 'verified' ? 'completed' : $status;
}

function rhHkStatusFromRow(array $row): string
{
    $status = (string)($row['status'] ?? '');
    $marker = $row['verified_at'] ?? null;
    if ($status === 'completed' && $marker !== null && $marker !== '' && strpos((string)$marker, '0000-00-00') !== 0) {
        return 'verified';
    }
    return $status;
}

/**
 * Get rooms that need checkout cleanup
 * Backward compatible: works with or without migration 004 columns
 */
function getCheckoutCleanupRooms(PDO $pdo): array
{
    $hasAssignmentType = housekeepingColumnExists($pdo, 'assignment_type');

    // Checked per booking: a booking is listed only if no checkout cleanup is linked to it
    // (linked_booking_id), so a room with an old cleanup is listed again after its next
    // checkout. Still at most ONE row per room (latest checked-out booking, below).
    $notExistsConditions = [
        "ha.individual_room_id = ir.id",
        "ha.status IN ('pending', 'in_progress', 'completed', 'verified')"
    ];

    if ($hasAssignmentType) {
        $notExistsConditions[] = "ha.assignment_type = 'checkout_cleanup'";
    }
    if (housekeepingColumnExists($pdo, 'linked_booking_id')) {
        $notExistsConditions[] = "ha.linked_booking_id = b.id";
    }

    $notExistsClause = implode(' AND ', $notExistsConditions);

    // Step 12a (R7): a booking holds every room in booking_rooms (a second room, both rooms of a joined pair), not
    // only its primary pointer. Checkout stamps released_at on those rows, so a released row counts when it was
    // released at checkout (within 10 minutes of checkout_completed_at); rows released earlier by a room move
    // are not this checkout's rooms (the move queued their own clean).
    $holds = static function (string $b): string {
        $rel = "(br.released_at IS NULL OR br.released_at >= DATE_SUB(COALESCE({$b}.checkout_completed_at, {$b}.check_out_date), INTERVAL 10 MINUTE))";
        return "({$b}.individual_room_id = ir.id"
            . (bookingAvailabilityTableExists('booking_rooms')
                ? " OR EXISTS (SELECT 1 FROM booking_rooms br WHERE br.booking_id = {$b}.id AND br.individual_room_id = ir.id AND {$rel})"
                : '')
            . ')';
    };
    $holdsB = $holds('b');
    $holdsB2 = $holds('b2');

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
        INNER JOIN bookings b ON {$holdsB}
        WHERE b.status = 'checked-out'
          AND b.deleted_at IS NULL
          AND b.check_out_date <= CURDATE()
          AND ir.is_active = 1
          AND b.id = (
              SELECT b2.id
              FROM bookings b2
              WHERE {$holdsB2}
                AND b2.status = 'checked-out'
                AND b2.deleted_at IS NULL
                AND b2.check_out_date <= CURDATE()
              ORDER BY b2.check_out_date DESC, b2.id DESC
              LIMIT 1
          )
          AND NOT EXISTS (
              SELECT 1 FROM housekeeping_assignments ha
              WHERE {$notExistsClause}
          )
          -- the normal checkout flow already queues a (non-linked) turnover task: skip rooms with
          -- any open task, or one completed after this checkout
          AND NOT EXISTS (
              SELECT 1 FROM housekeeping_assignments hx
              WHERE hx.individual_room_id = ir.id
                AND (hx.status IN ('pending', 'in_progress')
                     OR (hx.status IN ('completed', 'verified')
                         AND hx.completed_at >= COALESCE(b.checkout_completed_at, b.check_out_date)))
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

    // Stamp the clean time each time the room moves to 'completed' (the assignment above ended); a repeat reconcile of an already-clean room keeps it. last_cleaned_at is set first: MySQL reads later columns after earlier SET items.
    $pdo->prepare("UPDATE individual_rooms SET last_cleaned_at = IF(last_cleaned_at IS NULL OR COALESCE(housekeeping_status, '') <> 'completed', NOW(), last_cleaned_at), housekeeping_status = 'completed', housekeeping_notes = NULL WHERE id = ?")
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
    // Only the newest task of a recurring chain spawns the next one. Without this every completed
    // ancestor re-spawned a task each period, so the number of open tasks grew without bound.
    $whereConditions[] = "NOT EXISTS (SELECT 1 FROM housekeeping_assignments nx
        WHERE nx.individual_room_id = housekeeping_assignments.individual_room_id
          AND nx.assignment_type <=> housekeeping_assignments.assignment_type AND nx.is_recurring = 1
          AND nx.id > housekeeping_assignments.id)";

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

/**
 * An "open" task (pending / in progress / blocked) already exists for the same room + assignment type + due date.
 * Returns the existing assignment id or 0. $excludeId skips the row being edited.
 */
function hkFindOpenDuplicateAssignment(PDO $pdo, int $roomId, string $type, string $dueDate, ?int $excludeId = null): int
{
    $sql = "SELECT id FROM housekeeping_assignments
            WHERE individual_room_id = ? AND due_date = ? AND status IN ('pending','in_progress','blocked')";
    $params = [$roomId, $dueDate];
    if (housekeepingColumnExists($pdo, 'assignment_type')) {
        $sql .= " AND assignment_type = ?";
        $params[] = $type;
    }
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= " AND id <> ?";
        $params[] = $excludeId;
    }
    $sql .= " ORDER BY id DESC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)($st->fetchColumn() ?: 0);
}

/** Shared field checks for add/update; returns an error string or ''. */
function hkValidateAssignmentFields(string $dueDate, int $estimated, ?string $scheduledTime, bool $isRecurring, ?string $recurEnd, string $notes): string
{
    $d = DateTime::createFromFormat('Y-m-d', $dueDate);
    if (!$d || $d->format('Y-m-d') !== $dueDate) {
        return 'Invalid due date format.';
    }
    if ($estimated < 1 || $estimated > 1440) {
        return 'Estimated duration must be between 1 and 1440 minutes.';
    }
    if ($scheduledTime !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $scheduledTime)) {
        return 'Scheduled time must be a valid time (HH:MM).';
    }
    if ($isRecurring && $recurEnd !== null && $recurEnd !== '') {
        $e = DateTime::createFromFormat('Y-m-d', $recurEnd);
        if (!$e || $e->format('Y-m-d') !== $recurEnd) {
            return 'Recurring end date is not a valid date.';
        }
        if ($recurEnd < $dueDate) {
            return 'Recurring end date cannot be before the due date.';
        }
    }
    if (mb_strlen($notes) > 2000) {
        return 'Notes are too long (2000 characters maximum).';
    }
    return '';
}

/**
 * Single-row workflow steps shared by the one-by-one buttons and the bulk bar, so both enforce the
 * same rules. The caller owns the transaction. Each returns true when it changed the row, false when
 * the row is not in a state the step applies to.
 */
function hkLoadAssignment(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM housekeeping_assignments WHERE id = ? FOR UPDATE");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function hkStartAssignment(PDO $pdo, int $id, array $beforeData, ?int $userId, ?string $username): void
{
    if (housekeepingColumnExists($pdo, 'started_at')) {
        $pdo->prepare("UPDATE housekeeping_assignments SET started_at = COALESCE(started_at, NOW()), status = CASE WHEN status = 'pending' THEN 'in_progress' ELSE status END WHERE id = ?")
            ->execute([$id]);
    } else {
        $pdo->prepare("UPDATE housekeeping_assignments SET status = 'in_progress' WHERE id = ? AND status = 'pending'")
            ->execute([$id]);
    }
    $afterData = hkLoadAssignment($pdo, $id);
    logHousekeepingAction($id, 'status_changed', $beforeData, $afterData, $userId, $username);
    reconcileIndividualRoomHousekeeping($pdo, (int)$beforeData['individual_room_id'], $userId);
}

function hkCompleteAssignment(PDO $pdo, int $id, array $beforeData, ?int $userId, ?string $username): void
{
    $setClause = "status = 'completed', completed_at = COALESCE(completed_at, NOW())";
    if (housekeepingColumnExists($pdo, 'started_at')) {
        $setClause .= ", started_at = COALESCE(started_at, NOW())";
    }
    $pdo->prepare("UPDATE housekeeping_assignments SET {$setClause} WHERE id = ?")->execute([$id]);
    $afterData = hkLoadAssignment($pdo, $id);
    logHousekeepingAction($id, 'status_changed', $beforeData, $afterData, $userId, $username);
    reconcileIndividualRoomHousekeeping($pdo, (int)$beforeData['individual_room_id'], $userId);
}

/** Verify a completed, not-yet-verified task. False when it is not in that state. */
function hkVerifyAssignment(PDO $pdo, int $id, ?int $userId, ?string $username): bool
{
    $beforeData = hkLoadAssignment($pdo, $id);
    $stmt = $pdo->prepare("UPDATE housekeeping_assignments SET verified_by = ?, verified_at = NOW() WHERE id = ? AND status = 'completed' AND verified_at IS NULL");
    $stmt->execute([$userId, $id]);
    if ($stmt->rowCount() <= 0) {
        return false;
    }
    $afterData = hkLoadAssignment($pdo, $id);
    logHousekeepingAction($id, 'verified', $beforeData, $afterData, $userId, $username);
    if (!empty($beforeData['individual_room_id'])) {
        reconcileIndividualRoomHousekeeping($pdo, (int)$beforeData['individual_room_id'], $userId);
    }
    return true;
}

function hkDeleteAssignment(PDO $pdo, array $deletedData, ?int $userId, ?string $username): void
{
    $id = (int)$deletedData['id'];
    rh_log_task_snapshot_before_delete($pdo, 'housekeeping', $id, $userId, $username);
    $pdo->prepare("DELETE FROM housekeeping_assignments WHERE id = ?")->execute([$id]);
    reconcileIndividualRoomHousekeeping($pdo, (int)$deletedData['individual_room_id'], $userId);
    logHousekeepingAction($id, 'deleted', $deletedData, null, $userId, $username);
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
            } elseif (($fieldErr = hkValidateAssignmentFields((string)$due_date, $estimated_duration, $scheduled_time, (bool)$is_recurring, $recurring_end_date, $notes)) !== '') {
                $error = $fieldErr;
            } elseif (strtotime($due_date) === false) {
                $error = 'Invalid due date format.';
            } else {
                // One open task per room + type + date: check and insert under one lock so a double click cannot add two.
                $hkLockName = substr('rh_form_hk_assignment_' . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64);
                if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($hkLockName) . ', 10)')->fetchColumn() !== 1) {
                    $hkLockName = '';
                    throw new DomainException('Someone else is saving a housekeeping task right now. Please try again in a moment.');
                }
                if (in_array($status, ['pending', 'in_progress', 'blocked'], true)) {
                    $dupHk = hkFindOpenDuplicateAssignment($pdo, $room_id, $assignment_type, $due_date);
                    if ($dupHk > 0) {
                        throw new DomainException('This room already has an open ' . str_replace('_', ' ', $assignment_type) . ' task for ' . $due_date . ' (task #' . $dupHk . '). Edit that one instead.');
                    }
                }
                $pdo->beginTransaction();
                $completedAt = in_array($status, ['completed', 'verified'], true) ? date('Y-m-d H:i:s') : null;
                $verifiedAt = ($hasVerifiedAt && $status === 'verified') ? date('Y-m-d H:i:s') : null;

                // Build INSERT columns and values based on available columns
                $insertColumns = ['individual_room_id', 'status', 'due_date', 'assigned_to', 'created_by', 'notes', 'completed_at'];
                $insertValues = ['?', '?', '?', '?', '?', '?', '?'];
                $insertParams = [$room_id, rhHkStatusToDb($status), $due_date, $assigned_to, $user['id'] ?? null, $notes, $completedAt];

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
                try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($hkLockName) . ')'); } catch (Throwable $e2) {}
                $hkLockName = '';
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
            } elseif (($fieldErr = hkValidateAssignmentFields((string)$due_date, $estimated_duration, $scheduled_time, (bool)$is_recurring, $recurring_end_date, $notes)) !== '') {
                $error = $fieldErr;
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
                $existsStmt = $pdo->prepare("SELECT id, individual_room_id, status" . ($hasVerifiedAt ? ", verified_at" : "") . " FROM housekeeping_assignments WHERE id = ? FOR UPDATE");
                $existsStmt->execute([$id]);
                $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    throw new RuntimeException('Assignment does not exist.');
                }
                $existingLogical = rhHkStatusFromRow($existing);
                if ($existingLogical === 'verified') {
                    throw new DomainException('Verified assignments are locked and cannot be edited.');
                }

                // Moving a task onto a room + type + date that already has an open task would create a duplicate.
                if (in_array($status, ['pending', 'in_progress', 'blocked'], true)) {
                    $dupHk = hkFindOpenDuplicateAssignment($pdo, $room_id, $assignment_type, $due_date, $id);
                    if ($dupHk > 0) {
                        throw new DomainException('This room already has an open ' . str_replace('_', ' ', $assignment_type) . ' task for ' . $due_date . ' (task #' . $dupHk . '). Edit that one instead.');
                    }
                }

                // Auto-set verified_by when status changes to verified
                $verifiedBy = null;
                $verifiedAt = null;
                if ($hasVerifiedBy && $hasVerifiedAt && $status === 'verified' && $existingLogical !== 'verified') {
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
                $updateParams = [$room_id, rhHkStatusToDb($status), $due_date, $assigned_to, $notes, $completedAt];

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
                $deletedData = hkLoadAssignment($pdo, $id);
                if (!$deletedData) {
                    throw new RuntimeException('Assignment not found.');
                }
                hkDeleteAssignment($pdo, $deletedData, $user['id'] ?? null, $user['username'] ?? null);
                $pdo->commit();
                $message = 'Assignment deleted successfully.';
            }
        } elseif ($action === 'auto_create_checkout') {
            $pdo->beginTransaction();
            $created = autoCreateCheckoutCleanup($pdo, $user['id'] ?? null);
            $pdo->commit();
            $message = "Created {$created} checkout cleanup assignments.";
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
            } elseif ($hasPriority && !in_array($priority, $validPriorities, true)) {
                $error = 'Invalid priority.';
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
                    if (hkVerifyAssignment($pdo, $id, $user['id'] ?? null, $user['username'] ?? null)) {
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
                $beforeData = hkLoadAssignment($pdo, $id);
                if (!$beforeData) throw new RuntimeException('Assignment not found.');
                hkStartAssignment($pdo, $id, $beforeData, $user['id'] ?? null, $user['username'] ?? null);
                $pdo->commit();
                $message = 'Assignment marked as started.';
            }
        } elseif ($action === 'mark_complete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Invalid assignment selected.';
            } else {
                $pdo->beginTransaction();
                $beforeData = hkLoadAssignment($pdo, $id);
                if (!$beforeData) throw new RuntimeException('Assignment not found.');
                hkCompleteAssignment($pdo, $id, $beforeData, $user['id'] ?? null, $user['username'] ?? null);
                $pdo->commit();
                $message = 'Assignment marked as completed.';
            }
        } elseif ($action === 'guest_left') {
            // "Guest has left" (owner 2026-11-12): a room due out today (or overdue) whose guest has physically gone gets
            // its departure clean now, before the desk checks the guest out. The room stays 'occupied' (not for sale);
            // checkout later counts the finished clean (rh_checkout_early_clean_target). Undo = delete the task.
            $glRoomId = (int)($_POST['individual_room_id'] ?? 0);
            $glBookingId = (int)($_POST['booking_id'] ?? 0);
            if ($glRoomId <= 0 || $glBookingId <= 0) {
                $error = 'Choose a room to mark as left.';
            } elseif (!housekeepingColumnExists($pdo, 'assignment_type') || !housekeepingColumnExists($pdo, 'linked_booking_id')) {
                $error = 'This needs database migration 004. Please contact the administrator.';
            } else {
                $glUserId = $user['id'] ?? null;
                $glUserName = (string)($user['username'] ?? '');
                $pdo->beginTransaction();
                // Booking + room row locked: a double tap waits here and then finds the task made by the first.
                $glStmt = $pdo->prepare("
                    SELECT b.id, b.booking_reference, b.status, b.deleted_at, b.check_out_date,
                           (b.check_out_date <= CURDATE()) AS due_out, ir.room_number, ir.room_name
                    FROM bookings b
                    INNER JOIN individual_rooms ir ON ir.id = ? AND ir.is_active = 1
                    WHERE b.id = ?
                      AND (b.individual_room_id = ir.id OR EXISTS (
                          SELECT 1 FROM booking_rooms br WHERE br.booking_id = b.id AND br.individual_room_id = ir.id AND br.released_at IS NULL))
                    FOR UPDATE
                ");
                $glStmt->execute([$glRoomId, $glBookingId]);
                $gl = $glStmt->fetch(PDO::FETCH_ASSOC);
                if (!$gl) {
                    throw new DomainException('That room does not belong to this booking. Nothing was changed.');
                }
                $glRoomLabel = trim((string)$gl['room_number'] . ' ' . (string)($gl['room_name'] ?? ''));
                if ((string)$gl['status'] !== 'checked-in' || !empty($gl['deleted_at'])) {
                    throw new DomainException('Room ' . $glRoomLabel . ': only a checked-in guest can be marked as left. Nothing was changed.');
                }
                if ((int)$gl['due_out'] !== 1) {
                    throw new DomainException('Room ' . $glRoomLabel . ': the guest is not due out until ' . date('M j, Y', strtotime((string)$gl['check_out_date'])) . '. An early departure goes through the front desk. Nothing was changed.');
                }
                $glExisting = $pdo->prepare("SELECT id FROM housekeeping_assignments
                    WHERE individual_room_id = ? AND linked_booking_id = ? AND assignment_type = 'checkout_cleanup' ORDER BY id DESC LIMIT 1");
                $glExisting->execute([$glRoomId, $glBookingId]);
                $glExistingId = (int)($glExisting->fetchColumn() ?: 0);
                if ($glExistingId > 0) {
                    $pdo->rollBack();
                    $message = 'Room ' . $glRoomLabel . ' is already marked as left (task #' . $glExistingId . '). Nothing was changed.';
                } else {
                    $glToday = date('Y-m-d');
                    $glDup = hkFindOpenDuplicateAssignment($pdo, $glRoomId, 'checkout_cleanup', $glToday);
                    if ($glDup > 0) {
                        throw new DomainException('Room ' . $glRoomLabel . ' already has an open checkout cleanup task for today (task #' . $glDup . '). Use that one. Nothing was changed.');
                    }
                    $glNotes = 'Guest has left - cleaned before checkout (marked by ' . ($glUserName !== '' ? $glUserName : 'staff') . ')';
                    $glData = [
                        'individual_room_id' => $glRoomId,
                        'status' => 'pending',
                        'due_date' => $glToday,
                        'assigned_to' => null,
                        'created_by' => $glUserId,
                        'notes' => $glNotes,
                        'assignment_type' => 'checkout_cleanup',
                        'linked_booking_id' => $glBookingId,
                    ];
                    if (housekeepingColumnExists($pdo, 'priority')) {
                        $glData['priority'] = 'high';
                    }
                    $glCols = array_keys($glData);
                    $pdo->prepare("INSERT INTO housekeeping_assignments (" . implode(', ', $glCols) . ") VALUES (" . implode(', ', array_fill(0, count($glCols), '?')) . ")")
                        ->execute(array_values($glData));
                    $glTaskId = (int)$pdo->lastInsertId();
                    logHousekeepingAction($glTaskId, 'created', null, $glData, $glUserId, $glUserName !== '' ? $glUserName : null);
                    // Housekeeping flag only; reconcile never changes an 'occupied' room's status.
                    reconcileIndividualRoomHousekeeping($pdo, $glRoomId, $glUserId);
                    require_once __DIR__ . '/../includes/booking-timeline.php';
                    logBookingEvent(
                        $glBookingId,
                        (string)$gl['booking_reference'],
                        'Guest has left (housekeeping)',
                        'note',
                        'Room ' . $glRoomLabel . ' marked as left by ' . ($glUserName !== '' ? $glUserName : 'staff') . '; the departure clean (task #' . $glTaskId . ') started before checkout. The room stays occupied and cannot be sold until the desk checks the guest out.',
                        null,
                        null,
                        'admin',
                        $glUserId !== null ? (int)$glUserId : null,
                        $glUserName !== '' ? $glUserName : null,
                        ['individual_room_id' => $glRoomId, 'housekeeping_assignment_id' => $glTaskId]
                    );
                    $pdo->commit();
                    $message = 'Room ' . $glRoomLabel . ': guest has left. A high-priority departure clean is on the list. The room stays occupied (not for sale) until the desk checks the guest out.';
                }
            }
        } elseif ($action === 'bulk_assignments' && (string)($_POST['bulk_op'] ?? '') === 'extend') {
            // Extend... = repeat every day / week until a date (GRID_UNIFY_PLAN Phase 3): includes/ops-extend.php, one
            // transaction under the add form's lock, no duplicate for the same room + type + day. preview=1 saves nothing.
            $extRes = rh_hk_extend($pdo, adminBulkIds(), ['every' => (string)($_POST['every'] ?? 'daily'), 'until' => (string)($_POST['until'] ?? '')],
                ['id' => isset($user['id']) ? (int)$user['id'] : null, 'name' => $user['username'] ?? null], [
                    'preview' => !empty($_POST['preview']),
                    'reconcile' => static function (int $rid) use ($pdo, $user): void { reconcileIndividualRoomHousekeeping($pdo, $rid, $user['id'] ?? null); },
                ]);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => $extRes['ok'], 'message' => $extRes['message'], 'preview' => !empty($_POST['preview']), 'added' => $extRes['added'],
                    'dupes' => $extRes['dupes']]);
                exit;
            }
            if ($extRes['ok']) {
                $message = $extRes['message'];
            } else {
                $error = $extRes['message'];
            }
        } elseif ($action === 'bulk_assignments') {
            // Bulk bar over the assignments table: every row goes through the same helpers/rules as the
            // single-row buttons, in one transaction; rows a rule rejects are skipped and reported.
            $bulkOp = (string)($_POST['bulk_op'] ?? '');
            $bulkIds = adminBulkIds();
            $bulkAssignTo = null;
            $bulkPriority = '';
            $bulkError = '';
            if (!in_array($bulkOp, ['start', 'complete', 'verify', 'assign', 'priority', 'delete'], true)) {
                $bulkError = 'Choose a bulk action.';
            } elseif (!$bulkIds) {
                $bulkError = 'Select at least one assignment.';
            } elseif ($bulkOp === 'assign') {
                $rawAssignTo = (string)($_POST['assigned_to'] ?? '');
                if ($rawAssignTo === '' || !ctype_digit($rawAssignTo)) {
                    $bulkError = 'Choose who to assign the selected tasks to (or Unassigned).';
                } else {
                    $bulkAssignTo = (int)$rawAssignTo > 0 ? (int)$rawAssignTo : null;
                    if (!housekeepingUserExists($pdo, $bulkAssignTo)) {
                        $bulkError = 'Assigned user is invalid.';
                    }
                }
            } elseif ($bulkOp === 'priority') {
                $bulkPriority = (string)($_POST['priority'] ?? '');
                if (!housekeepingColumnExists($pdo, 'priority')) {
                    $bulkError = 'Priority is not available on this installation.';
                } elseif (!in_array($bulkPriority, $validPriorities, true)) {
                    $bulkError = 'Invalid priority level.';
                }
            } elseif ($bulkOp === 'verify' && (!housekeepingColumnExists($pdo, 'verified_by') || !housekeepingColumnExists($pdo, 'verified_at'))) {
                $bulkError = 'Verification feature requires database migration 004. Please contact administrator.';
            }

            if ($bulkError !== '') {
                $error = $bulkError;
            } else {
                $bulkDone = 0;
                $bulkSkipped = [];
                $actorId = $user['id'] ?? null;
                $actorName = $user['username'] ?? null;
                $pdo->beginTransaction();
                foreach ($bulkIds as $bulkId) {
                    $bulkRow = hkLoadAssignment($pdo, $bulkId);
                    if (!$bulkRow) {
                        adminBulkSkip($bulkSkipped, 'not found');
                        continue;
                    }
                    $logical = rhHkStatusFromRow($bulkRow);
                    if ($bulkOp === 'start') {
                        if ($logical !== 'pending') {
                            adminBulkSkip($bulkSkipped, 'not pending');
                            continue;
                        }
                        hkStartAssignment($pdo, $bulkId, $bulkRow, $actorId, $actorName);
                    } elseif ($bulkOp === 'complete') {
                        if (!in_array($logical, ['pending', 'in_progress'], true)) {
                            adminBulkSkip($bulkSkipped, 'only pending or in-progress tasks can be marked done');
                            continue;
                        }
                        hkCompleteAssignment($pdo, $bulkId, $bulkRow, $actorId, $actorName);
                    } elseif ($bulkOp === 'verify') {
                        if ($logical !== 'completed' || !hkVerifyAssignment($pdo, $bulkId, $actorId, $actorName)) {
                            adminBulkSkip($bulkSkipped, 'not completed or already verified');
                            continue;
                        }
                    } elseif ($bulkOp === 'delete') {
                        if ($logical === 'verified') {
                            adminBulkSkip($bulkSkipped, 'verified tasks are kept as records');
                            continue;
                        }
                        hkDeleteAssignment($pdo, $bulkRow, $actorId, $actorName);
                    } else { // assign / priority: same lock as the edit form - verified tasks cannot change
                        if ($logical === 'verified') {
                            adminBulkSkip($bulkSkipped, 'verified tasks are locked');
                            continue;
                        }
                        if ($bulkOp === 'assign') {
                            if (($bulkRow['assigned_to'] !== null ? (int)$bulkRow['assigned_to'] : null) === $bulkAssignTo) {
                                adminBulkSkip($bulkSkipped, 'already set');
                                continue;
                            }
                            $pdo->prepare("UPDATE housekeeping_assignments SET assigned_to = ? WHERE id = ?")->execute([$bulkAssignTo, $bulkId]);
                            $bulkAudit = $bulkAssignTo ? 'assigned' : 'unassigned';
                        } else {
                            if ((string)($bulkRow['priority'] ?? '') === $bulkPriority) {
                                adminBulkSkip($bulkSkipped, 'already set');
                                continue;
                            }
                            $pdo->prepare("UPDATE housekeeping_assignments SET priority = ? WHERE id = ?")->execute([$bulkPriority, $bulkId]);
                            $bulkAudit = 'priority_changed';
                        }
                        logHousekeepingAction($bulkId, $bulkAudit, $bulkRow, hkLoadAssignment($pdo, $bulkId), $actorId, $actorName);
                        reconcileIndividualRoomHousekeeping($pdo, (int)$bulkRow['individual_room_id'], $actorId);
                    }
                    $bulkDone++;
                }
                $pdo->commit();
                $bulkVerbs = ['start' => 'started', 'complete' => 'marked done', 'verify' => 'verified', 'assign' => 'assigned', 'priority' => 'priority updated', 'delete' => 'deleted'];
                [$message, $error] = adminBulkOutcome($bulkVerbs[$bulkOp], $bulkDone, $bulkSkipped);
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($hkLockName)) {
            try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($hkLockName) . ')'); } catch (Throwable $e2) {}
            $hkLockName = '';
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
// A leftover 'pending' / 'in progress' flag with no open task and a room not in 'cleaning' is stale: show it as completed.
$roomsStmt = $pdo->query("SELECT ir.id, ir.room_number, ir.room_name, ir.status,
    CASE WHEN ir.housekeeping_status IN ('pending', 'in_progress') AND ir.status <> 'cleaning' AND NOT " . rh_room_open_task_sql('ir') . " THEN 'completed' ELSE ir.housekeeping_status END AS housekeeping_status
    FROM individual_rooms ir WHERE ir.is_active = 1 ORDER BY ir.room_number ASC");
$rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

$usersStmt = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 ORDER BY username ASC");
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get occupied rooms for quick assignment
$occupiedRooms = getOccupiedRooms($pdo);

// Get rooms needing checkout cleanup
$checkoutCleanupRooms = getCheckoutCleanupRooms($pdo);

// Get staff workload
$staffWorkload = getStaffWorkload($pdo);
// Only staff with housekeeping work today — listing every account (bar, kitchen,
// coffee…) at zero buried the few rows that matter.
$staffWorkload = array_values(array_filter($staffWorkload, static function ($row) {
    return (int)($row['active_tasks'] ?? 0) > 0
        || (int)($row['high_priority_pending'] ?? 0) > 0
        || (int)($row['completed_today'] ?? 0) > 0;
}));

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
    SELECT ha.*, (" . rh_fd_housekeeping_due_sql('ha') . ") AS rh_due_now, ir.room_number, ir.room_name, u.username as assigned_to_name, creator.username as created_by_name" .
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
foreach ($assignments as &$hkRow) {
    $hkRow['status'] = rhHkStatusFromRow($hkRow);
}
unset($hkRow);

// Dashboard deep links (owner 2026-11-11: a link opens what its chip counts). Whitelisted; filter the table only, the
// stat cards keep counting every assignment.
//  ?due=now      "Housekeeping tasks due": pending / in progress, due today or earlier or undated (rh_due_now, same SQL).
//  ?room=<no.>   room-service reminder row: that room's assignments (exact room number).
$hkFilterDue = (is_scalar($_GET['due'] ?? null) && (string)$_GET['due'] === 'now') ? 'now' : '';
$hkFilterRoom = is_scalar($_GET['room'] ?? null) ? trim((string)$_GET['room']) : '';
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{0,29}$/', $hkFilterRoom)) {
    $hkFilterRoom = '';
}
$hkTableRows = array_values(array_filter($assignments, static function (array $r) use ($hkFilterDue, $hkFilterRoom): bool {
    if ($hkFilterDue === 'now' && (int)($r['rh_due_now'] ?? 0) !== 1) {
        return false;
    }
    if ($hkFilterRoom !== '' && trim((string)($r['room_number'] ?? '')) !== $hkFilterRoom) {
        return false;
    }
    return true;
}));
$hkTableFiltered = $hkFilterDue !== '' || $hkFilterRoom !== '';

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

    if ($hasPriority && ($a['priority'] ?? '') === 'high' && in_array($status, ['pending', 'in_progress', 'blocked'], true)) {
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

// Room timeline (CALENDAR_UI_DECISION 10): first paint for 31 days so every range button works without a fetch.
// Deep links: ?id= (task) &room= (room number) &date= &view=timeline|list &days=3|7|14|30; invalid values are ignored.
// Read-only; on any failure the page keeps its list and old cards (the timeline section is simply not printed).
require_once __DIR__ . '/includes/ops-timeline-data.php';
$opsMode = 'housekeeping';
$opsPayload = null;
$opsInitial = rh_ops_deeplink($_GET, 'housekeeping');
$opsInitial['status'] = $hkFilterDue === 'now' ? 'due' : '';
try {
    $opsToday = date('Y-m-d');
    $opsStart = $opsInitial['date'] ?? $opsToday;
    if ($opsInitial['date'] === null && $opsInitial['id'] > 0) {
        $opsItemDay = rh_ops_item_date($pdo, 'housekeeping', $opsInitial['id']);
        if ($opsItemDay !== null && ($opsItemDay < $opsToday || $opsItemDay > date('Y-m-d', strtotime('+30 days')))) {
            $opsStart = $opsItemDay;
        }
    }
    $opsPayload = rh_ops_payload($pdo, 'housekeeping', $opsStart, 31, ['user_id' => (int)($user['id'] ?? 0)]);
} catch (Throwable $e) {
    error_log('housekeeping timeline: ' . $e->getMessage());
    $opsPayload = null;
}
$opsPage = ['list' => '#hkAssignmentsCard', 'table' => '#assignmentsTable', 'confirm' => 'hkConfirm', 'edit' => 'editAssignment',
    'bulk' => '#hkAssignmentsCard form[data-bulk-bar]', 'new' => 'hkNewTaskFor'];
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
    <link rel="stylesheet" href="css/ops-pages.css?v=<?php echo @filemtime(__DIR__ . '/css/ops-pages.css'); ?>">
    <link rel="stylesheet" href="css/housekeeping.css?v=<?php echo @filemtime(__DIR__ . '/css/housekeeping.css'); ?>">
    <link rel="stylesheet" href="css/admin-cockpit.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-cockpit.css'); ?>">
    <link rel="stylesheet" href="css/room-timeline.css?v=<?php echo @filemtime(__DIR__ . '/css/room-timeline.css'); ?>">
    <link rel="stylesheet" href="css/room-timeline-ops.css?v=<?php echo @filemtime(__DIR__ . '/css/room-timeline-ops.css'); ?>">
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
                    <button class="btn btn-secondary" type="button" data-help="Create Checkout Cleanup|Generate cleaning assignments for every room that has a guest checking out and needs housekeeping." onclick="hkConfirm(this.closest('form'), 'Create checkout cleanup assignments for all rooms that need it?', 'Create', 'btn-primary')">
                        <i class="fas fa-broom"></i> Create Checkout Cleanup
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
        <?php if ($opsPayload): ?>
        <?php
        /* Same five cards and counts as the Calendar's Housekeeping tab (rh_cal_hk_tab). A click filters the timeline
           and the list to those rooms and scrolls to them (room-timeline-ops.js); click again to show all. */
        $opsCards = [
            'dirty' => ['Dirty', 'bad', 'Rooms with a cleaning task due and not started, or marked as cleaning. Active rooms, now.'],
            'cleaning' => ['Cleaning', 'warn', 'Rooms with a cleaning task in progress right now.'],
            'inspection' => ['Inspection', 'info', 'Rooms whose last cleaning task is done but not yet checked (verified).'],
            'clean' => ['Clean', 'good', 'Rooms with no cleaning due, in progress or waiting for inspection.'],
            'unassigned' => ['Unassigned tasks', 'plain', 'Rooms whose current open cleaning task has nobody assigned.'],
        ];
        ?>
        <?php /* Shared page strip: one row of compact chips (.ops-page-strip > .ops-chip). room-timeline-ops.js fills .ck-kpi__value and sets is-active / aria-pressed. */ ?>
        <div class="ops-page-strip" id="hkStatsDashboard" role="group" aria-label="Housekeeping room counts. Click one to show only those rooms.">
            <?php foreach ($opsCards as $opsKey => [$opsLabel, $opsTone, $opsHelp]): ?>
                <button type="button" class="ops-chip ops-chip--<?php echo $opsTone; ?>" data-ops-card="<?php echo $opsKey; ?>" data-ops-count="<?php echo $opsKey; ?>" data-ops-sub="Show these rooms" aria-pressed="false" title="Show these rooms. Click again to show all.">
                    <span class="ck-kpi__value ops-chip__value"><?php echo (int)($opsPayload['counts'][$opsKey] ?? 0); ?></span>
                    <span class="ops-chip__label"><?php echo htmlspecialchars($opsLabel); ?> <i class="help" data-tip="<?php echo htmlspecialchars($opsHelp); ?>">?</i></span>
                </button>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="ck-kpis" id="hkStatsDashboard">
            <?php /* Cards open their detail on click; Verified Today lives in the task list. */ ?>
            <button type="button" class="ck-kpi" data-stat-key="today_total" data-stat-title="Today's Housekeeping Tasks" data-stat-description="All housekeeping assignments scheduled for today.">
                <span class="ck-kpi__icon"><i class="fas fa-calendar-day"></i></span>
                <span class="ck-kpi__label">Today's Tasks</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['today_total']; ?></span>
            </button>
            <button type="button" class="ck-kpi ck-kpi--info" data-stat-key="pending" data-stat-title="Pending Tasks" data-stat-description="Tasks waiting to be started.">
                <span class="ck-kpi__icon"><i class="fas fa-clock"></i></span>
                <span class="ck-kpi__label">Pending Tasks</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['pending']; ?></span>
            </button>
            <button type="button" class="ck-kpi ck-kpi--warn" data-stat-key="in_progress" data-stat-title="In Progress" data-stat-description="Tasks currently being cleaned.">
                <span class="ck-kpi__icon"><i class="fas fa-spinner"></i></span>
                <span class="ck-kpi__label">In Progress</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['in_progress']; ?></span>
            </button>
            <button type="button" class="ck-kpi ck-kpi--good" data-stat-key="completed_today" data-stat-title="Completed Today" data-stat-description="Housekeeping assignments completed today.">
                <span class="ck-kpi__icon"><i class="fas fa-check"></i></span>
                <span class="ck-kpi__label">Completed Today</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['completed_today']; ?></span>
            </button>
            <button type="button" class="ck-kpi ck-kpi--alert" data-stat-key="high_priority" data-stat-title="High Priority" data-stat-description="Urgent assignments needing immediate attention.">
                <span class="ck-kpi__icon"><i class="fas fa-exclamation-triangle"></i></span>
                <span class="ck-kpi__label">High Priority</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['high_priority']; ?></span>
            </button>
            <?php if ((int)$stats['blocked'] > 0): /* shown only when something is blocked */ ?>
            <button type="button" class="ck-kpi ck-kpi--alert" data-stat-key="blocked" data-stat-title="Blocked" data-stat-description="Assignments currently blocked and needing unblock action.">
                <span class="ck-kpi__icon"><i class="fas fa-ban"></i></span>
                <span class="ck-kpi__label">Blocked</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['blocked']; ?></span>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
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

        <?php /* Grid first (owner 2026-11-24): the timeline sits directly under the strip, in the first screen. */ ?>
        <?php if ($opsPayload) { require __DIR__ . '/includes/ops-timeline-view.php'; } ?>

        <!-- Needs action (shared layout: .ops-needs-list; the Maintenance page uses the same markup) -->
        <?php
        // Open cleaning task per room (pending / in progress), for the Assigned to column and the row Assign
        // (reassigns that task via bulk_assignments; bulk_assign_occupied itself skips a room that already has one).
        $hkOpenByRoom = [];
        foreach ($assignments as $hkOpenRow) {
            if (!in_array((string)($hkOpenRow['status'] ?? ''), ['pending', 'in_progress'], true)) {
                continue;
            }
            $hkOpenRid = (int)($hkOpenRow['individual_room_id'] ?? 0);
            if ($hkOpenRid > 0 && !isset($hkOpenByRoom[$hkOpenRid])) {
                $hkOpenByRoom[$hkOpenRid] = $hkOpenRow;
            }
        }
        // Owner 2026-11-28: a user who may check guests out sees Check out / Add nights on a room due out (today or
        // overdue). Both open the bookings list's own dialogs (balance gate, settlement, availability) and come back here.
        $hkCanCheckout = hasPermission((int)($user['id'] ?? 0), 'bookings') && hasPermission((int)($user['id'] ?? 0), 'checkout_guest');
        $hkNeedCols = [
            'room' => ['Room', 'The room number and name. Every room with a guest checked in, from current bookings, now.'],
            'status' => ['Status', 'Checkout today, Overdue (the checkout date has passed but the booking is still checked in: check the guest out on the booking or extend the stay; hover the pill for the date) or Occupied. A second pill shows when the guest has left and the departure clean has started.'],
            'guest' => ['Guest / checkout', 'The booking guest (and the room guest if different) and the booking checkout date.'],
            'assigned' => ['Assigned to', 'Who has the room\'s open cleaning task (pending or in progress). A dash means the room has no open task.'],
            'assign' => ['Assign', 'Pick a staff member for this room. With no open task, it adds a cleaning task for today; with an open task, it gives that task to the person picked (or Unassigned).'],
            'left' => ['Guest has left', 'For a room due out: use it once the guest has gone, so the departure clean can start. The room cannot be sold until the desk checks the guest out.'],
        ];
        ?>
        <section class="ops-panel ops-needs-list" id="hkNeedsAction" aria-labelledby="hkNeedsTitle">
            <div class="ops-panel__head">
                <h3 class="ops-panel__title" id="hkNeedsTitle"><i class="fas fa-bed" aria-hidden="true"></i> Needs action (<?php echo count($occupiedRooms); ?>) <i class="help" data-tip="Rooms with a guest checked in right now, from current bookings. Tick rooms to give them all a cleaning task at once, or use the Assign box on a row.">?</i></h3>
            </div>
            <?php if (empty($occupiedRooms)): ?>
                <p class="ops-empty">No occupied rooms need action right now.</p>
            <?php else: ?>
                <form method="POST" id="bulkAssignForm" class="ops-needs-list__form">
                    <input type="hidden" name="action" value="bulk_assign_occupied">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="room_ids" id="selectedRoomIds">
                    <div class="ops-needs-list__table" role="table" aria-label="Occupied rooms that need action">
                        <div class="ops-needs-list__cols" role="row">
                            <span class="ops-cell ops-cell--tick" role="columnheader"><input type="checkbox" id="hkSelectAllRooms" class="room-checkbox-all admin-bulk-check" aria-label="Select all rooms shown on this page" onchange="hkSelectVisibleRooms(this.checked)"></span>
                            <?php foreach ($hkNeedCols as $hkColKey => [$hkColLabel, $hkColHelp]): ?>
                                <span class="ops-cell ops-cell--<?php echo $hkColKey; ?>" role="columnheader"><?php echo htmlspecialchars($hkColLabel); ?> <i class="help" data-tip="<?php echo htmlspecialchars($hkColHelp); ?>">?</i></span>
                            <?php endforeach; ?>
                        </div>
                        <?php /* A list so the shared pager (admin-section-pagination.js, ul.ck-rows) shows 10 rows per page with the "Show" picker. */ ?>
                        <ul class="occupied-rooms-list ck-rows" role="rowgroup">
                            <?php foreach ($occupiedRooms as $room): ?>
                                <?php
                                $hkOccType = (string)$room['occupancy_type'];
                                $hkDueOut = in_array($hkOccType, ['checkout_today', 'overdue_checkout'], true);
                                $hkOccPill = $hkOccType === 'checkout_today' ? 'in_progress' : ($hkOccType === 'overdue_checkout' ? 'blocked' : 'occupied');
                                $hkOccLabel = $hkOccType === 'checkout_today' ? 'Checkout today' : ($hkOccType === 'overdue_checkout' ? 'Overdue' : 'Occupied');
                                // Hover says why (owner 2026-11-28): the checkout date, how late, and what clears it.
                                $hkOutTs = strtotime((string)$room['check_out_date']);
                                $hkLate = $hkOutTs ? (int)floor((strtotime(date('Y-m-d')) - $hkOutTs) / 86400) : 0;
                                $hkOccTip = $hkOccType === 'overdue_checkout'
                                    ? 'Due out ' . date('M j, Y', $hkOutTs) . ($hkLate > 0 ? ' (' . $hkLate . ' day' . ($hkLate === 1 ? '' : 's') . ' ago)' : '') . ' but the booking is still checked in. Check the guest out on the booking, or extend the stay, to clear this.'
                                    : ($hkOccType === 'checkout_today' ? 'The guest is due out today.' : 'A guest is checked in; due out ' . date('M j, Y', $hkOutTs) . '.');
                                $hkRoomLabel = trim($room['room_number'] . ' ' . ($room['room_name'] ?? ''));
                                $hkEarly = (string)($room['early_task_status'] ?? '');
                                $hkOpen = $hkOpenByRoom[(int)$room['id']] ?? null;
                                ?>
                                <li class="ops-needs-row" role="row" data-room-id="<?php echo (int)$room['id']; ?>">
                                    <span class="ops-cell ops-cell--tick" role="cell">
                                        <input type="checkbox" class="room-checkbox admin-bulk-check" value="<?php echo (int)$room['id']; ?>" aria-label="Select room <?php echo htmlspecialchars($hkRoomLabel); ?>" onchange="hkRoomTick(this)">
                                    </span>
                                    <span class="ops-cell ops-cell--room" role="rowheader"><strong><?php echo htmlspecialchars($hkRoomLabel); ?></strong></span>
                                    <span class="ops-cell ops-cell--status" role="cell">
                                        <span class="status-pill <?php echo $hkOccPill; ?>" title="<?php echo htmlspecialchars($hkOccTip); ?>"><?php echo $hkOccLabel; ?></span>
                                        <?php if ($hkDueOut && $hkEarly === 'completed'): ?>
                                            <span class="status-pill completed">Cleaned &middot; waiting for checkout</span>
                                        <?php elseif ($hkDueOut && $hkEarly !== ''): ?>
                                            <span class="status-pill <?php echo $hkEarly === 'blocked' ? 'blocked' : 'in_progress'; ?>">Guest left &middot; <?php echo $hkEarly === 'blocked' ? 'cleaning on hold' : 'cleaning before checkout'; ?></span>
                                        <?php endif; ?>
                                        <?php if ($hkDueOut && $hkCanCheckout && (int)($room['booking_id'] ?? 0) > 0): $hkBkId = (int)$room['booking_id']; ?>
                                            <span class="ops-needs-row__stay">
                                                <a class="btn btn-sm btn-outline" href="bookings.php?action=checkout&amp;booking_id=<?php echo $hkBkId; ?>&amp;from=housekeeping" title="Check this guest out: opens the bookings checkout (balance and any extra nights are settled there)"><i class="fas fa-right-from-bracket" aria-hidden="true"></i> Check out</a>
                                                <a class="btn btn-sm btn-outline" href="bookings.php?action=extend&amp;booking_id=<?php echo $hkBkId; ?>&amp;from=housekeeping" title="The guest is staying on: add nights to the booking (room availability is checked, extra nights at the booked rate)"><i class="fas fa-calendar-plus" aria-hidden="true"></i> Add nights</a>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="ops-cell ops-cell--guest" role="cell">
                                        <span class="ops-cell__main"><?php echo htmlspecialchars($room['guest_name'] ?? 'Guest'); ?><?php if (trim((string)($room['room_guest_name'] ?? '')) !== ''): ?> <small class="room-guest-line">&middot; Room guest: <?php echo htmlspecialchars((string)$room['room_guest_name']); ?></small><?php endif; ?></span>
                                        <small class="ops-cell__sub">Checkout <?php echo htmlspecialchars(date('M j, Y', strtotime($room['check_out_date']))); ?></small>
                                    </span>
                                    <span class="ops-cell ops-cell--assigned" role="cell">
                                        <?php if ($hkOpen): ?>
                                            <span class="ops-cell__main"><?php echo htmlspecialchars((string)($hkOpen['assigned_to_name'] ?? '') !== '' ? (string)$hkOpen['assigned_to_name'] : 'Unassigned'); ?></span>
                                            <small class="ops-cell__sub"><?php echo $hkOpen['status'] === 'in_progress' ? 'In progress' : 'Pending'; ?></small>
                                        <?php else: ?>
                                            <span class="ops-cell__none" aria-label="No open task">&ndash;</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="ops-cell ops-cell--assign" role="cell">
                                        <?php if ($hkOpen): $hkOpenTo = (int)($hkOpen['assigned_to'] ?? 0); ?>
                                            <select class="hk-inline-control ops-assign-select" aria-label="Assign the open task for room <?php echo htmlspecialchars($hkRoomLabel); ?> to" onchange="hkRowReassign(this, <?php echo (int)$hkOpen['id']; ?>, <?php echo htmlspecialchars((string)json_encode($hkRoomLabel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>)" data-current="<?php echo $hkOpenTo; ?>">
                                                <option value=""<?php echo $hkOpenTo === 0 ? ' selected' : ''; ?>><?php echo $hkOpenTo === 0 ? 'Assign to...' : 'Unassigned'; ?></option>
                                                <?php foreach ($users as $u): ?>
                                                    <option value="<?php echo (int)$u['id']; ?>"<?php echo (int)$u['id'] === $hkOpenTo ? ' selected' : ''; ?>><?php echo htmlspecialchars($u['username']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <select class="hk-inline-control ops-assign-select" aria-label="Assign room <?php echo htmlspecialchars($hkRoomLabel); ?> to" onchange="hkRowAssign(this, <?php echo (int)$room['id']; ?>, <?php echo htmlspecialchars((string)json_encode($hkRoomLabel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>)">
                                                <option value="">Assign to...</option>
                                                <?php foreach ($users as $u): ?>
                                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </span>
                                    <span class="ops-cell ops-cell--left" role="cell">
                                        <?php if ($hkDueOut && $hkEarly === ''): ?>
                                            <button type="button" class="btn btn-secondary btn-sm ops-row-btn" onclick="hkGuestLeft(<?php echo (int)$room['id']; ?>, <?php echo (int)$room['booking_id']; ?>, <?php echo htmlspecialchars((string)json_encode($hkRoomLabel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>)" title="The guest has physically gone: start the departure clean now">
                                                <i class="fas fa-door-open" aria-hidden="true"></i> Guest has left
                                            </button>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <details class="rh-col-help ops-col-help">
                        <summary>What the columns mean</summary>
                        <dl>
                            <?php foreach ($hkNeedCols as [$hkColLabel, $hkColHelp]): ?>
                                <dt><?php echo htmlspecialchars($hkColLabel); ?></dt>
                                <dd><?php echo htmlspecialchars($hkColHelp); ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    </details>
                    <?php /* Appears when a room is ticked; sticks to the bottom of the screen while the list is in view. */ ?>
                    <div class="ops-bulk-bar" id="hkBulkBar" hidden>
                        <span class="ops-bulk-bar__count"><span id="hkBulkCount">0</span> selected</span>
                        <select name="assigned_to" id="bulkAssignTo" class="hk-inline-control" aria-label="Assign selected rooms to">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="priority" class="hk-inline-control" aria-label="Priority for the new tasks">
                            <option value="medium">Medium Priority</option>
                            <option value="high">High Priority</option>
                            <option value="low">Low Priority</option>
                        </select>
                        <button type="submit" class="btn btn-primary hk-bulk-assign-btn" id="bulkAssignBtn" disabled>
                            <i class="fas fa-plus" aria-hidden="true"></i> Assign Selected (<span id="selectedCount">0</span>)
                        </button>
                        <button type="button" class="btn btn-secondary hk-bulk-assign-btn" onclick="hkClearRoomTicks()">Clear</button>
                    </div>
                </form>
                <!-- Separate from bulkAssignForm (a form inside a form is dropped by the browser). -->
                <form method="POST" id="hkGuestLeftForm">
                    <input type="hidden" name="action" value="guest_left">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="individual_room_id" id="hkGuestLeftRoom" value="">
                    <input type="hidden" name="booking_id" id="hkGuestLeftBooking" value="">
                </form>
                <?php /* Per-row Assign: the same bulk_assign_occupied action with one room and the bulk defaults (medium priority). */ ?>
                <form method="POST" id="hkRowAssignForm">
                    <input type="hidden" name="action" value="bulk_assign_occupied">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="room_ids" id="hkRowAssignRooms" value="">
                    <input type="hidden" name="assigned_to" id="hkRowAssignTo" value="">
                    <input type="hidden" name="priority" value="medium">
                </form>
                <?php /* Per-row Assign on a room that already has an open task: the assignments bulk bar's own assign (audit-logged). */ ?>
                <form method="POST" id="hkRowReassignForm">
                    <input type="hidden" name="action" value="bulk_assignments">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="bulk_op" value="assign">
                    <input type="hidden" name="bulk_ids[]" id="hkRowReassignId" value="">
                    <input type="hidden" name="assigned_to" id="hkRowReassignTo" value="">
                </form>
            <?php endif; ?>
        </section>

        <?php if (!empty($staffWorkload)): ?>
            <?php /* Staff workload: one slim chip row (same data as the old table). */ ?>
            <div class="ops-workload-chips" role="list" aria-label="Staff workload">
                <span class="ops-workload-chips__label">Workload <i class="help" data-tip="Open tasks (pending or in progress) per staff member right now, with high-priority tasks not yet started and tasks finished today. Staff with no housekeeping work today are left out.">?</i></span>
                <?php foreach ($staffWorkload as $staff): ?>
                    <?php
                    $hkWlOpen = (int)$staff['active_tasks'];
                    $hkWlHigh = (int)$staff['high_priority_pending'];
                    $hkWlDone = (int)$staff['completed_today'];
                    ?>
                    <span class="ops-workload-chip" role="listitem"><strong><?php echo htmlspecialchars($staff['username']); ?></strong> <?php echo $hkWlOpen; ?> open<?php if ($hkWlHigh > 0): ?> <span class="ops-workload-chip__high">&middot; <?php echo $hkWlHigh; ?> high priority</span><?php endif; ?><?php if ($hkWlDone > 0): ?> &middot; <?php echo $hkWlDone; ?> done today<?php endif; ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Assignments Table -->
        <div class="table-card" id="hkAssignmentsCard" data-rh-results>
            <?php if ($hkTableFiltered): ?>
                <div style="background: #eef3ff; border: 1px solid #cfd8ff; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px; color: #1f2d6b;">
                        <i class="fas fa-filter"></i>
                        <strong><?php echo $hkFilterDue === 'now' ? 'Tasks due now' : 'One room'; ?></strong>
                    </div>
                    <div style="color: #2f3a63; font-size: 13px;">
                        Showing <?php echo count($hkTableRows); ?> assignment<?php echo count($hkTableRows) === 1 ? '' : 's'; ?><?php if ($hkFilterDue === 'now'): ?> pending or in progress, due today or earlier (or with no date)<?php endif; ?><?php if ($hkFilterRoom !== ''): ?> for room <?php echo htmlspecialchars($hkFilterRoom); ?><?php endif; ?>.
                        <a href="housekeeping.php" style="margin-left: 8px; color: #1f2d6b; font-weight: 600;">Clear</a>
                    </div>
                </div>
            <?php endif; ?>
            <div class="hk-table-toolbar">
                <h3 class="hk-table-toolbar__title"><i class="fas fa-list-check"></i> <?php echo $hkTableFiltered ? 'Assignments' : 'All Assignments'; ?> (<?php echo count($hkTableRows); ?>)</h3>
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
                    <button type="button" id="tableFilterClear" class="btn btn-secondary hk-filter-clear" onclick="clearTableFilter()" hidden>Clear</button>
                </div>
            </div>
            <table id="assignmentsTable">
                <thead>
                    <tr>
                        <th class="admin-bulk-col"><?php echo adminBulkAllBox('Select all visible assignments'); ?></th>
                        <th data-help="Room|The room this task is for (room number and name).">Room</th>
                        <th data-help="Type|What kind of job it is: checkout cleanup after a guest leaves, regular cleaning, deep clean, turn-down or maintenance.">Type</th>
                        <th data-help="Priority|How urgent the task is: High, Medium or Low.">Priority</th>
                        <th data-help="Status|Where the task is: Pending (not started), In Progress, Completed, Verified (checked by a supervisor) or Blocked (waiting, e.g. room in maintenance).">Status</th>
                        <th data-help="Scheduled|The day (and time, if set) the task is due.">Scheduled</th>
                        <th data-help="Assigned To|The staff member doing the task. Unassigned means nobody has it yet.">Assigned To</th>
                        <th data-help="Notes|Instructions or remarks saved with the task.">Notes</th>
                        <th data-help="Actions|Start, mark done, edit, view the task's history, or verify a finished task.">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hkTableRows)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center;padding:24px;color:#9ca3af;"><?php echo $hkTableFiltered ? 'No assignments match this filter.' : 'No housekeeping assignments yet.'; ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($hkTableRows as $row): ?>
                            <tr data-ops-id="<?php echo (int)$row['id']; ?>" data-ops-room="<?php echo (int)($row['individual_room_id'] ?? 0); ?>" data-status="<?php echo htmlspecialchars((string)$row['status']); ?>" data-priority="<?php echo htmlspecialchars((string)($row['priority'] ?? '')); ?>" data-type="<?php echo htmlspecialchars((string)($row['assignment_type'] ?? '')); ?>" data-room="<?php echo htmlspecialchars(trim((string)($row['room_number'] ?? '') . ' ' . (string)($row['room_name'] ?? ''))); ?>" data-assignee="<?php echo htmlspecialchars((string)($row['assigned_to_name'] ?? 'Unassigned')); ?>" data-due-date="<?php echo htmlspecialchars((string)($row['due_date'] ?? '')); ?>">
                                <td class="admin-bulk-col"><?php echo adminBulkRowBox($row['id'], 'Select assignment for room ' . $row['room_number']); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['room_number'] . ' ' . ($row['room_name'] ?? '')); ?></strong></td>
                                <td><span class="type-badge <?php echo htmlspecialchars($row['assignment_type'] ?? ''); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['assignment_type'] ?? ''))); ?></span></td>
                                <td><span class="priority-badge <?php echo htmlspecialchars($row['priority'] ?? ''); ?>"><?php echo htmlspecialchars(ucfirst($row['priority'] ?? '')); ?></span></td>
                                <td><span class="status-pill <?php echo htmlspecialchars($row['status']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['status']))); ?></span></td>
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
            <?php /* Always printed (hidden until rows are ticked): the timeline's picks use this bar's actions even when the
                     list below is filtered to nothing (?due=now, ?room=), as on Room Maintenance. */ ?>
                <?php adminBulkBarStart('bulk_assignments', '#hkAssignmentsCard', $csrf_token); ?>
                    <select name="assigned_to" data-bulk-field aria-label="Assign selected to">
                        <option value="">Assign to...</option>
                        <option value="0">Unassigned</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php adminBulkButton('assign', 'Assign', 'fa-user-check', 'Assign {n} selected task(s) to the chosen staff member? Verified tasks are locked and will be skipped.', 'btn-primary', 'assigned_to'); ?>
                    <select name="priority" data-bulk-field aria-label="Set priority of selected">
                        <option value="">Priority...</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                    <?php adminBulkButton('priority', 'Set priority', 'fa-flag', 'Change the priority of {n} selected task(s)? Verified tasks are locked and will be skipped.', 'btn-secondary', 'priority'); ?>
                    <?php adminBulkButton('start', 'Start', 'fa-play', 'Mark {n} selected task(s) as started? Only pending tasks are changed.', 'btn-warning'); ?>
                    <?php adminBulkButton('complete', 'Mark done', 'fa-check', 'Mark {n} selected task(s) as completed? Only pending or in-progress tasks are changed.', 'btn-success'); ?>
                    <?php adminBulkButton('verify', 'Verify', 'fa-check-double', 'Verify {n} selected task(s)? Only completed tasks are verified. This cannot be undone.', 'btn-success'); ?>
                    <button type="button" name="bulk_op" value="extend" class="btn btn-secondary btn-sm" data-rh-extend="hk"><i class="fas fa-repeat" aria-hidden="true"></i> Repeat until...</button>
                    <?php adminBulkButton('delete', 'Delete', 'fa-trash', 'Delete {n} selected assignment(s)? This cannot be undone. Verified tasks are kept.', 'btn-danger', '', 'Delete', 'danger'); ?>
                <?php adminBulkBarEnd(); ?>
        </div>
    </div>

    <?php renderAdminModalStart('assignmentModal', 'Add Assignment', 'housekeeping-modal-content', 'assignmentForm'); ?>
        <input type="hidden" name="action" id="formAction" value="add_assignment">
        <input type="hidden" name="id" id="assignmentId">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <p class="tl-multi-note" id="assignmentMultiNote" hidden></p>
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
    <?php renderAdminModalFooterStart(); ?>
        <div id="assignmentFeedback" class="admin-modal-feedback" style="flex:1;"></div>
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
        <button type="submit" id="assignmentSaveBtn" class="btn btn-primary">Save</button>
    <?php renderAdminModalEnd(); ?>

    <!-- Confirm action modal (replaces window.confirm) -->
    <div class="modal-overlay" id="hkConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
        <div class="modal-content" style="max-width:420px;padding:0;overflow:hidden;">
            <div class="modal-header">
                <h3 id="hkConfirmTitle">Confirm Action</h3>
            </div>
            <div class="modal-body">
                <p style="margin:0;font-size:14px;color:#374151;" id="hkConfirmMessage">Are you sure?</p>
            </div>
            <div class="modal-footer">
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
    <?php renderAdminModalFooterStart(); ?>
        <button type="button" class="btn btn-secondary" onclick="closeAuditLogModal()">Close</button>
    <?php renderAdminModalEnd(); ?>

    <?php renderAdminModalStart('statsQuickModal', 'Housekeeping Snapshot', 'housekeeping-modal-content hk-stats-modal'); ?>
    <div id="hkStatsModalMeta" class="hk-stats-modal__meta"></div>
    <div id="hkStatsModalList" class="hk-stats-modal__list"></div>
    <?php renderAdminModalFooterStart(); ?>
        <button type="button" class="btn btn-secondary" onclick="closeStatsQuickModal()">Close</button>
    <?php renderAdminModalEnd(); ?>

    <?php renderAdminModalScript(); ?>

    <script>
        // Back from Check out / Add nights on the bookings list (?from=housekeeping): show its result here.
        (function () {
            var show = function () { try {
                var raw = sessionStorage.getItem('rh_booking_action_message');
                if (!raw) return;
                sessionStorage.removeItem('rh_booking_action_message');
                var m = JSON.parse(raw);
                if (!m || !m.message || !window.Alert || typeof window.Alert.show !== 'function') return;
                var esc = String(m.message).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                window.Alert.show(esc, m.type === 'warning' || m.type === 'error' ? m.type : 'success');
            } catch (e) {
                try { sessionStorage.removeItem('rh_booking_action_message'); } catch (e2) {}
            } };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', show, { once: true });
            else show();
        })();
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
                    return item.priority === 'high' && (item.status === 'pending' || item.status === 'in_progress' || item.status === 'blocked');
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

            titleEl.textContent = config.title + ' - Details';
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
            const card = event.target.closest('.ck-kpi[data-stat-key]');
            if (!card) return;
            const statKey = card.getAttribute('data-stat-key') || '';
            openStatsQuickModal(statKey);
        });

        document.addEventListener('keydown', function(event) {
            const card = event.target.closest('.ck-kpi[data-stat-key]');
            if (!card) return;
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            const statKey = card.getAttribute('data-stat-key') || '';
            openStatsQuickModal(statKey);
        });

        // Set minimum date to today
        document.addEventListener('DOMContentLoaded', function() {
            const today = rhYmd(new Date());
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
            hkSetMulti(null);
            document.getElementById('assignmentModal-title').textContent = 'Add Assignment';
            document.getElementById('formAction').value = 'add_assignment';
            document.getElementById('assignmentForm').reset();
            document.getElementById('assignmentId').value = '';
            document.getElementById('due_date').min = rhYmd(new Date());
            document.getElementById('recurringOptions').style.display = 'none';
            openAdminModal('assignmentModal');
        }

        // New task for the rooms / days picked on the timeline (admin/js/room-timeline-select.js calls hkNewTaskFor with
        // [{room, date, label}]). One room and day: the normal form, prefilled. Several: the same form is sent once per
        // room and day (add_assignment, the server checks each one as usual); Room and Due date are then not shown.
        var hkMulti = null;
        var HK_MULTI_MAX = 60;
        function hkDayText(d) {
            var L = window.RhGridSel && window.RhGridSel.logic;
            return L ? L.nightsText([d]) : d;
        }
        function hkSetMulti(list) {
            hkMulti = list && list.length > 1 ? list.slice() : null;
            var show = hkMulti ? 'none' : '';
            var rg = document.getElementById('roomSelect').closest('.form-group');
            var dg = document.getElementById('due_date').closest('.form-group');
            if (rg) rg.style.display = show;
            if (dg) dg.style.display = show;
            var note = document.getElementById('assignmentMultiNote');
            if (!note) return;
            note.hidden = !hkMulti;
            if (!hkMulti) { note.textContent = ''; return; }
            var byRoom = {}, order = [];
            hkMulti.forEach(function(p) {
                if (!byRoom[p.label]) { byRoom[p.label] = []; order.push(p.label); }
                byRoom[p.label].push(p.date);
            });
            var L = window.RhGridSel && window.RhGridSel.logic;
            var parts = order.map(function(r) { return r + ' on ' + (L ? L.nightsText(byRoom[r]) : byRoom[r].join(', ')); });
            note.textContent = 'This adds ' + hkMulti.length + ' tasks, one for each room and day: ' + parts.slice(0, 8).join('; ')
                + (parts.length > 8 ? '; and ' + (parts.length - 8) + ' more rooms' : '') + '. Everything else below is the same for all of them.';
        }
        window.hkNewTaskFor = function(list) {
            if (!list || !list.length) return;
            if (list.length > HK_MULTI_MAX) {
                if (window.Alert && typeof window.Alert.show === 'function') window.Alert.show('Pick at most ' + HK_MULTI_MAX + ' room days at a time.', 'warning');
                return;
            }
            openModal();
            document.getElementById('roomSelect').value = list[0].room;
            document.getElementById('due_date').value = list[0].date;
            hkSetMulti(list);
            if (hkMulti) document.getElementById('assignmentModal-title').textContent = 'Add ' + hkMulti.length + ' tasks';
        };
        function hkSaveMulti(form) {
            var list = hkMulti.slice();
            var saveBtn = document.getElementById('assignmentSaveBtn');
            var fb = document.getElementById('assignmentFeedback');
            var label = saveBtn.textContent;
            saveBtn.disabled = true;
            fb.className = 'admin-modal-feedback';
            fb.textContent = '';
            var done = 0, failed = [], lines = [];
            // Read the form ONCE: if staff close the form and open Edit on a row while this runs, the remaining
            // saves must still be these new tasks, never an update of that row.
            var snap = Array.from(new FormData(form).entries());
            var step = function(i) {
                if (i >= list.length) return Promise.resolve();
                saveBtn.textContent = 'Saving ' + (i + 1) + ' of ' + list.length + '...';
                var fd = new FormData();
                snap.forEach(function(kv) { fd.append(kv[0], kv[1]); });
                fd.set('action', 'add_assignment');
                fd.delete('assignment_id');
                fd.delete('id');
                fd.set('individual_room_id', list[i].room);
                fd.set('due_date', list[i].date);
                return fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                    .then(function(r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(function(res) {
                        if (res && res.success) { done++; return; }
                        failed.push(list[i]);
                        lines.push(list[i].label + ' on ' + hkDayText(list[i].date) + ': ' + ((res && res.message) || 'not added'));
                    }, function() {
                        failed.push(list[i]);
                        lines.push(list[i].label + ' on ' + hkDayText(list[i].date) + ': no answer from the server (check the list before trying again)');
                    })
                    .then(function() { return step(i + 1); });
            };
            step(0).then(function() {
                saveBtn.disabled = false;
                saveBtn.textContent = label;
                if (done > 0) refreshAssignmentsTable();
                if (document.getElementById('formAction').value !== 'add_assignment') {
                    // The form was reused for something else meanwhile: report only, never touch it.
                    if (window.Alert && typeof window.Alert.show === 'function') window.Alert.show('Added ' + done + ' of ' + list.length + ' tasks.' + (lines.length ? ' Not added: ' + lines.join('; ') + '.' : ''), failed.length ? 'warning' : 'success');
                    return;
                }
                if (!failed.length) {
                    closeModal();
                    if (window.Alert && typeof window.Alert.show === 'function') window.Alert.show('Added ' + done + ' tasks.', 'success');
                    return;
                }
                // Only the ones that were not added stay in the form, so Save again never adds a task twice.
                hkSetMulti(failed.length > 1 ? failed : null);
                if (failed.length === 1) {
                    document.getElementById('roomSelect').value = failed[0].room;
                    document.getElementById('due_date').value = failed[0].date;
                }
                fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                fb.textContent = 'Added ' + done + ' of ' + list.length + ' tasks. Not added: ' + lines.join('; ') + '.';
            });
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
            hkSetMulti(null);
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

        // Needs action list: only the rows on screen (the list is paged) are ticked by the header box.
        function hkSelectVisibleRooms(checked) {
            document.querySelectorAll('.ops-needs-row').forEach(function (row) {
                if (row.hidden) return;
                var cb = row.querySelector('.room-checkbox');
                if (!cb) return;
                cb.checked = checked;
                row.classList.toggle('selected', checked);
            });
            updateSelectedCount();
        }

        function hkRoomTick(cb) {
            var row = cb.closest('.ops-needs-row');
            if (row) row.classList.toggle('selected', cb.checked);
            updateSelectedCount();
        }

        function hkClearRoomTicks() {
            document.querySelectorAll('.room-checkbox:checked').forEach(function (cb) {
                cb.checked = false;
                var row = cb.closest('.ops-needs-row');
                if (row) row.classList.remove('selected');
            });
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const count = document.querySelectorAll('.room-checkbox:checked').length;
            const countEl = document.getElementById('selectedCount');
            const btn = document.getElementById('bulkAssignBtn');
            const bar = document.getElementById('hkBulkBar');
            const barCount = document.getElementById('hkBulkCount');
            const all = document.getElementById('hkSelectAllRooms');
            if (countEl) countEl.textContent = count;
            if (barCount) barCount.textContent = count;
            if (btn) btn.disabled = count === 0;
            if (bar) bar.hidden = count === 0;
            if (all) {
                const shown = Array.from(document.querySelectorAll('.ops-needs-row')).filter(function (r) { return !r.hidden; })
                    .map(function (r) { return r.querySelector('.room-checkbox'); }).filter(Boolean);
                const on = shown.filter(function (c) { return c.checked; }).length;
                all.checked = shown.length > 0 && on === shown.length;
                all.indeterminate = on > 0 && on < shown.length;
            }
        }

        // Per-row Assign: the same bulk_assign_occupied action as the bulk bar, with this one room and the default priority.
        function hkRowAssign(select, roomId, roomLabel) {
            var staffId = select.value;
            if (!staffId) return;
            var staffName = select.options[select.selectedIndex].text;
            select.value = '';
            var form = document.getElementById('hkRowAssignForm');
            if (!form) return;
            document.getElementById('hkRowAssignRooms').value = JSON.stringify([roomId]);
            document.getElementById('hkRowAssignTo').value = staffId;
            hkConfirm(form, 'Give room ' + roomLabel + ' a cleaning task for today and assign it to ' + staffName + '?', 'Assign', 'btn-primary');
        }

        // Per-row Assign when the room already has an open task: hands that task to someone else (or Unassigned).
        function hkRowReassign(select, taskId, roomLabel) {
            var staffId = select.value || '0';
            var current = select.getAttribute('data-current') || '0';
            if (staffId === current) return;
            var staffName = staffId === '0' ? 'nobody (Unassigned)' : select.options[select.selectedIndex].text;
            select.value = current === '0' ? '' : current;
            var form = document.getElementById('hkRowReassignForm');
            if (!form) return;
            document.getElementById('hkRowReassignId').value = taskId;
            document.getElementById('hkRowReassignTo').value = staffId;
            hkConfirm(form, 'Assign the open cleaning task for room ' + roomLabel + ' to ' + staffName + '?', 'Assign', 'btn-primary');
        }

        function quickAssignRoom(roomId) {
            hkSetMulti(null);
            document.getElementById('assignmentModal-title').textContent = 'Assign Room';
            document.getElementById('formAction').value = 'add_assignment';
            document.getElementById('assignmentForm').reset();
            document.getElementById('assignmentId').value = '';
            document.getElementById('due_date').value = rhYmd(new Date());
            document.getElementById('due_date').min = rhYmd(new Date());
            document.getElementById('roomSelect').value = roomId;
            document.getElementById('recurringOptions').style.display = 'none';
            openAdminModal('assignmentModal');
        }

        // "Guest has left": starts the departure clean before checkout; the room stays occupied until the desk checks out.
        function hkGuestLeft(roomId, bookingId, roomLabel) {
            const form = document.getElementById('hkGuestLeftForm');
            if (!form) return;
            document.getElementById('hkGuestLeftRoom').value = roomId;
            document.getElementById('hkGuestLeftBooking').value = bookingId;
            hkConfirm(form, 'Has the guest in room ' + roomLabel + ' left? A departure clean will be added now. The room stays occupied and cannot be sold until the desk checks the guest out.', 'Guest has left', 'btn-primary');
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
            if (hkMulti && document.getElementById('formAction').value === 'add_assignment') {
                hkSaveMulti(this);
                return;
            }
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
                    if (next && cur) {
                        cur.innerHTML = next.innerHTML;
                        hkRemountPager(cur);
                    }
                    if (nextStats && curStats) curStats.innerHTML = nextStats.innerHTML;
                    if (nextData && curData) curData.textContent = nextData.textContent;
                    if (window.AdminBulk) window.AdminBulk.refresh();
                    filterTable(true); // keep the search / status filter after the refresh
                    if (window.RoomTimeline && typeof window.RoomTimeline.reload === 'function') window.RoomTimeline.reload(); // timeline + card counts
                }).catch(function() {});
        }

        // The rows were replaced: drop the old pager (it points at the old rows) and let the shared pager mount again.
        function hkRemountPager(table) {
            Array.prototype.forEach.call(table.parentNode.querySelectorAll(':scope > [data-admin-auto-pagination-nav]'), function(nav) {
                nav.parentNode.removeChild(nav);
            });
            delete table.dataset.adminAutoPaginationBound;
            delete table.rhPagerRefresh;
            if (window.AdminAutoSectionPagination) window.AdminAutoSectionPagination.init();
        }

        // ── Table search + status filter ────────────────────────────────────
        // Searches every row (not just the page on screen). data-filter-hidden tells the shared pager
        // (admin-section-pagination.js) to leave a row out of its pages and counts; display covers a table too
        // short to have a pager. $keepPlace = true (after a refresh): no scroll.
        function filterTable(keepPlace) {
            var query = (document.getElementById('tableSearch').value || '').trim().toLowerCase();
            var status = (document.getElementById('tableStatusFilter').value || '').toLowerCase();
            var table = document.getElementById('assignmentsTable');
            if (!table) return;
            table.querySelectorAll('tbody tr[data-status]').forEach(function(row) {
                var text = row.textContent.toLowerCase();
                var rowStat = (row.getAttribute('data-status') || '').toLowerCase();
                var match = (!query || text.includes(query)) && (!status || rowStat === status)
                    && (!window.RoomTimeline || !window.RoomTimeline.opsRowMatch || window.RoomTimeline.opsRowMatch(table, row)); // a figure card is on
                row.dataset.filterHidden = match ? '' : '1';
                row.style.display = match ? '' : 'none';
            });
            if (typeof table.rhPagerRefresh === 'function') table.rhPagerRefresh(true);
            var clearBtn = document.getElementById('tableFilterClear');
            if (clearBtn) clearBtn.hidden = !(query || status);
            if (keepPlace !== true && (query || status)) {
                if (window.rhAnchors && typeof window.rhAnchors.scrollToResults === 'function') window.rhAnchors.scrollToResults(table);
                else table.scrollIntoView({ block: 'start' });
            }
        }

        function clearTableFilter() {
            document.getElementById('tableSearch').value = '';
            document.getElementById('tableStatusFilter').value = '';
            filterTable(true);
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
    <script src="js/admin-context-menu.js?v=<?php echo @filemtime(__DIR__ . '/js/admin-context-menu.js'); ?>"></script>
    <script src="js/room-timeline-select.js?v=<?php echo @filemtime(__DIR__ . '/js/room-timeline-select.js'); ?>"></script>
    <script src="js/admin-extend.js?v=<?php echo @filemtime(__DIR__ . '/js/admin-extend.js'); ?>"></script>
    <?php if ($opsPayload): ?>
    <script src="js/room-timeline.js?v=<?php echo @filemtime(__DIR__ . '/js/room-timeline.js'); ?>"></script>
    <script src="js/room-timeline-ops.js?v=<?php echo @filemtime(__DIR__ . '/js/room-timeline-ops.js'); ?>"></script>
    <script>
        (function () {
            var root = document.getElementById('opsTimeline');
            if (root && window.RoomTimeline) { window.RoomTimeline.init(root); }
        })();
    </script>
    <?php endif; ?>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

