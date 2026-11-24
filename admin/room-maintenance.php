<?php

/**
 * Room Maintenance Management - Admin Panel
 * Enhanced with priority-based assignments, rooms needing maintenance auto-fetch,
 * staff workload tracking, and verification workflow
 * Mirrors housekeeping.php implementation patterns
 */
require_once 'admin-init.php';
require_once 'includes/admin-modal.php';
require_once __DIR__ . '/../includes/form-validation.php';
require_once __DIR__ . '/../includes/room-management.php';
require_once __DIR__ . '/../includes/maintenance-recurring.php';
require_once __DIR__ . '/../includes/room-night-clash.php'; // a job that closes the room never lands on a booked night (GRID_UNIFY_PLAN Phase 2)
require_once __DIR__ . '/../includes/ops-extend.php'; // Extend... and the end handle (GRID_UNIFY_PLAN Phase 3)
require_once __DIR__ . '/includes/bulk-actions.php';
require_once __DIR__ . '/includes/front-desk-counts.php'; // rh_fd_out_of_service_statuses: the Dashboard chip rule
/** @var array{id: int, username: string, role: string} $user */
/** @var string $csrf_token */

if (!hasPermission($user['id'], 'room_maintenance')) {
    rhDenyAndRedirectHome((int)$_SESSION['admin_user_id'], (string)($_SESSION['admin_role'] ?? ''), basename($_SERVER['PHP_SELF']));
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
/* Single-row workflow steps shared by the one-by-one buttons and the bulk bar, so both enforce
 * the same rules and write the same audit trail. Each runs inside the caller's transaction and
 * returns '' on success or the reason the row was skipped. */
function mtLoadSchedule(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM room_maintenance_schedules WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mtLogicalStatus(array $row): string
{
    return rh_maint_status_from_db($row['status'] ?? '', $row['verified_at'] ?? null);
}

function mtStartSchedule(PDO $pdo, int $id, ?int $userId, ?string $userName): string
{
    $before = mtLoadSchedule($pdo, $id);
    if (!$before) return 'not found';
    if (mtLogicalStatus($before) !== 'pending') return 'not pending';
    if (maintenanceColumnExists($pdo, 'started_at')) {
        $pdo->prepare("UPDATE room_maintenance_schedules SET started_at = COALESCE(started_at, NOW()), status = CASE WHEN status IN ('planned', 'pending', '') THEN 'in_progress' ELSE status END WHERE id = ?")->execute([$id]);
    } else {
        $pdo->prepare("UPDATE room_maintenance_schedules SET status = 'in_progress' WHERE id = ? AND status IN ('planned', 'pending', '')")->execute([$id]);
    }
    logMaintenanceAction($id, 'status_changed', $before, mtLoadSchedule($pdo, $id), $userId, $userName);
    reconcileMaintenanceRoomStatus($pdo, (int)$before['individual_room_id'], $userId);
    return '';
}

function mtCompleteSchedule(PDO $pdo, int $id, ?int $userId, ?string $userName): string
{
    $before = mtLoadSchedule($pdo, $id);
    if (!$before) return 'not found';
    if (!in_array(mtLogicalStatus($before), ['pending', 'in_progress'], true)) return 'only pending or in-progress tasks can be marked done';
    $setClause = "status = 'completed', completed_at = COALESCE(completed_at, NOW())";
    if (maintenanceColumnExists($pdo, 'started_at')) {
        $setClause .= ", started_at = COALESCE(started_at, NOW())";
    }
    $pdo->prepare("UPDATE room_maintenance_schedules SET {$setClause} WHERE id = ?")->execute([$id]);
    logMaintenanceAction($id, 'status_changed', $before, mtLoadSchedule($pdo, $id), $userId, $userName);
    reconcileMaintenanceRoomStatus($pdo, (int)$before['individual_room_id'], $userId);
    return '';
}

function mtVerifySchedule(PDO $pdo, int $id, ?int $userId, ?string $userName): string
{
    if (!maintenanceColumnExists($pdo, 'verified_by') || !maintenanceColumnExists($pdo, 'verified_at')) {
        return 'verification needs database migration 005';
    }
    $before = mtLoadSchedule($pdo, $id);
    if (!$before) return 'not found';
    $stmt = $pdo->prepare("UPDATE room_maintenance_schedules SET verified_by = ?, verified_at = NOW() WHERE id = ? AND status = 'completed' AND verified_at IS NULL");
    $stmt->execute([$userId, $id]);
    if ($stmt->rowCount() === 0) return 'not completed or already verified';
    logMaintenanceAction($id, 'verified', $before, mtLoadSchedule($pdo, $id), $userId, $userName);
    if (!empty($before['individual_room_id'])) {
        reconcileMaintenanceRoomStatus($pdo, (int)$before['individual_room_id'], $userId);
    }
    return '';
}

function mtDeleteSchedule(PDO $pdo, int $id, ?int $userId, ?string $userName): string
{
    $before = mtLoadSchedule($pdo, $id);
    if (!$before) return 'not found';
    rh_log_task_snapshot_before_delete($pdo, 'maintenance', $id, $userId, $userName);
    $pdo->prepare("DELETE FROM room_maintenance_schedules WHERE id = ?")->execute([$id]);
    reconcileMaintenanceRoomStatus($pdo, (int)($before['individual_room_id'] ?? 0), $userId);
    logMaintenanceAction($id, 'deleted', $before, null, $userId, $userName);
    return '';
}

function mtAssignSchedule(PDO $pdo, int $id, ?int $assignTo, ?int $userId, ?string $userName): string
{
    $before = mtLoadSchedule($pdo, $id);
    if (!$before) return 'not found';
    if (mtLogicalStatus($before) === 'verified') return 'verified tasks are locked';
    if (($before['assigned_to'] !== null ? (int)$before['assigned_to'] : null) === $assignTo) return 'already set';
    $pdo->prepare("UPDATE room_maintenance_schedules SET assigned_to = ? WHERE id = ?")->execute([$assignTo, $id]);
    logMaintenanceAction($id, $assignTo ? 'assigned' : 'unassigned', $before, mtLoadSchedule($pdo, $id), $userId, $userName);
    return '';
}

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
 * Rooms that need work (owner 2026-11-24, HK_MAINT_LAYOUT_PLAN Q2): one row per active room that is out of
 * service (rh_fd_out_of_service_statuses: maintenance / out of order) OR has an open maintenance job (stored
 * status not completed / cancelled / verified, the rule of checkIndividualRoomAvailability and
 * rh_maint_status_is_open; '' and NULL count as open). An occupied room is NOT listed for being occupied; when a
 * listed room has a checked-in guest (primary room pointer or a held booking_rooms row) the guest is shown as info.
 * open_job_id = the oldest open job (open_job_count says how many), with its title / status / assignee, so the
 * list can reassign that job (bulk_schedules assign) instead of creating a second one.
 */
function getRoomsNeedingMaintenance(PDO $pdo): array
{
    $oos = rh_fd_out_of_service_statuses();
    $oosPh = implode(', ', array_fill(0, count($oos), '?'));
    $openJob = "COALESCE(rms.status, '') NOT IN ('completed', 'cancelled', 'verified')";
    $joinedArm = bookingAvailabilityTableExists('booking_rooms')
        ? " OR EXISTS (SELECT 1 FROM booking_rooms br WHERE br.booking_id = b.id AND br.individual_room_id = ir.id AND br.released_at IS NULL)"
        : '';
    $sql = "
        SELECT
            x.id,
            x.room_number,
            x.room_name,
            x.room_status,
            x.open_job_count,
            x.open_job_id,
            j.title AS open_job_title,
            j.status AS open_job_status,
            j.assigned_to AS open_job_assigned_to,
            u.username AS open_job_assigned_name,
            x.booking_id,
            b.guest_name,
            b.room_guest_name,
            b.check_out_date,
            b.status AS booking_status,
            CASE
                WHEN x.room_status = 'out_of_order' THEN 'out_of_order'
                WHEN x.room_status = 'maintenance' THEN 'maintenance'
                ELSE 'open_job'
            END AS room_condition
        FROM (
            SELECT
                ir.id,
                ir.room_number,
                ir.room_name,
                ir.status AS room_status,
                (SELECT COUNT(*) FROM room_maintenance_schedules rms WHERE rms.individual_room_id = ir.id AND {$openJob}) AS open_job_count,
                (SELECT MIN(rms.id) FROM room_maintenance_schedules rms WHERE rms.individual_room_id = ir.id AND {$openJob}) AS open_job_id,
                (SELECT b.id FROM bookings b
                  WHERE b.status = 'checked-in' AND (b.individual_room_id = ir.id{$joinedArm})
                  ORDER BY b.check_in_date DESC, b.id DESC LIMIT 1) AS booking_id
            FROM individual_rooms ir
            WHERE ir.is_active = 1
              AND (
                  ir.status IN ({$oosPh})
                  OR EXISTS (SELECT 1 FROM room_maintenance_schedules rms WHERE rms.individual_room_id = ir.id AND {$openJob})
              )
        ) x
        LEFT JOIN room_maintenance_schedules j ON j.id = x.open_job_id
        LEFT JOIN admin_users u ON u.id = j.assigned_to
        LEFT JOIN bookings b ON b.id = x.booking_id
        ORDER BY
            CASE x.room_status WHEN 'out_of_order' THEN 1 WHEN 'maintenance' THEN 2 ELSE 3 END,
            x.room_number ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($oos));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['open_job_count'] = (int)($row['open_job_count'] ?? 0);
        $row['open_job_id'] = $row['open_job_id'] !== null ? (int)$row['open_job_id'] : null;
        if ($row['open_job_id'] !== null) {
            $row['open_job_status'] = rh_maint_status_from_db($row['open_job_status'] ?? ''); // planned / '' read as pending
        }
    }
    unset($row);
    return $rows;
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
        "rms.status IN ('planned', 'pending', '', 'in_progress')"
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
          AND ir.status IN ('out_of_order', 'maintenance')
          AND b.id IS NULL -- never raise a task for a room with a guest checked in
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
        ? "COUNT(CASE WHEN rms.status IN ('planned', 'pending', '') AND rms.priority IN ('urgent', 'high') THEN 1 END) as high_priority_pending"
        : "0 as high_priority_pending";

    $sql = "
        SELECT
            u.id,
            u.username,
            COUNT(CASE WHEN rms.status IN ('planned', 'pending', '', 'in_progress') THEN 1 END) as active_tasks,
            {$highPriorityCase},
            COUNT(CASE WHEN rms.status = 'completed' AND DATE(rms.completed_at) = CURDATE() THEN 1 END) as completed_today
        FROM admin_users u
        LEFT JOIN room_maintenance_schedules rms ON rms.assigned_to = u.id
            AND (rms.status IN ('planned', 'pending', '', 'in_progress') OR (rms.status = 'completed' AND DATE(rms.completed_at) = CURDATE()))
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
function autoCreateMaintenanceTasks(PDO $pdo, int $performedBy, array &$notes = []): int
{
    $hasDueDate = maintenanceColumnExists($pdo, 'due_date');
    $hasMaintenanceType = maintenanceColumnExists($pdo, 'maintenance_type');
    $hasPriority = maintenanceColumnExists($pdo, 'priority');
    $hasAutoCreated = maintenanceColumnExists($pdo, 'auto_created');
    $hasLinkedBookingId = maintenanceColumnExists($pdo, 'linked_booking_id');
    $hasBlockRoom = maintenanceColumnExists($pdo, 'block_room');

    // Owner answer 3 (2026-11-23): the job is created either way (a work reminder), but it closes the room only when
    // no booking sleeps there - decided under the room locks, which are taken before anything else is read.
    $maintenanceRooms = getMaintenanceNeededRooms($pdo);
    $plan = rh_room_nights_lock_plan($pdo, [['scope' => 'room', 'room_ids' => array_map(static fn($r) => (int)$r['id'], $maintenanceRooms)]]);
    $pdo->beginTransaction();
    rh_room_nights_take_locks($pdo, $plan);
    $created = 0;

    foreach ($maintenanceRooms as $room) {
        // Check if assignment already exists
        $checkStmt = $pdo->prepare("
            SELECT id FROM room_maintenance_schedules
            WHERE individual_room_id = ? AND status IN ('planned', 'pending', '', 'in_progress')
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
            'planned',
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
        $jobBlock = rh_maint_job_can_close_room($pdo, (int)$room['id'], (string)$insertParams[3], (string)$insertParams[4]) ? 1 : 0;
        if ($hasBlockRoom) {
            $insertColumns[] = 'block_room';
            $insertValues[] = '?';
            $insertParams[] = $jobBlock;
        }
        if ($jobBlock === 0) {
            $notes[] = rh_block_label($pdo, null, [(int)$room['id']]) . ' is booked on those nights, so its job does not close the room.';
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
        if ($hasBlockRoom) $newData['block_room'] = $jobBlock;

        logMaintenanceAction($newMaintenanceId, 'created', null, $newData, $performedBy);
    }

    $pdo->commit();
    return $created;
}

/**
 * Reconcile individual room maintenance status.
 *
 * - An ACTIVE schedule (in progress; planned/pending whose start date has arrived in the hotel
 *   timezone; or completed-but-unverified) holds the room in maintenance. A schedule planned for
 *   a future date does not.
 * - Release happens only when $allowRelease is true (the explicit add/edit/delete/start/complete/
 *   verify actions, never the page-load sweep) AND the room's current maintenance status was set
 *   by a schedule (its latest status log entry to 'maintenance' is the "Maintenance assignment
 *   active" one). A room put into maintenance manually is never released here. A released room
 *   goes to cleaning with a housekeeping task, not straight to available.
 */
function reconcileMaintenanceRoomStatus(PDO $pdo, int $roomId, ?int $performedBy = null, bool $allowRelease = true): void
{
    $roomStatusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
    $roomStatusStmt->execute([$roomId]);
    $roomStatus = (string)$roomStatusStmt->fetchColumn();
    if ($roomStatus === '') {
        return;
    }

    if (rh_room_has_active_maintenance($pdo, $roomId)) {
        // Only set to maintenance if not occupied / out of order
        if (!in_array($roomStatus, ['occupied', 'out_of_order'], true)) {
            maintenanceSetRoomStatus($pdo, $roomId, 'maintenance', 'Maintenance assignment active', $performedBy);
        }
        return;
    }

    if (!$allowRelease || $roomStatus !== 'maintenance') {
        return;
    }

    // Provenance: release only a maintenance status that a schedule put the room into.
    $setBySchedule = false;
    if (maintenanceTableExists($pdo, 'room_maintenance_log')) {
        $logStmt = $pdo->prepare("SELECT reason FROM room_maintenance_log WHERE individual_room_id = ? AND status_to = 'maintenance' ORDER BY created_at DESC, id DESC LIMIT 1");
        $logStmt->execute([$roomId]);
        $lastReason = $logStmt->fetchColumn();
        $setBySchedule = is_string($lastReason) && strpos($lastReason, 'Maintenance assignment active') === 0;
    }
    if (!$setBySchedule) {
        return;
    }

    maintenanceSetRoomStatus($pdo, $roomId, 'cleaning', 'Maintenance assignment cleared - room needs cleaning', $performedBy);
    createHousekeepingAssignment($roomId, $performedBy, ['notes' => 'Post-maintenance cleaning']);
}

/**
 * Create recurring maintenance assignments.
 * The logic lives in includes/maintenance-recurring.php (shared with the daily scheduler job).
 */
function createRecurringMaintenance(PDO $pdo, int $performedBy): int
{
    return rh_create_recurring_maintenance($pdo, $performedBy);
}

/** Shared field checks for add/update; returns an error string or ''. */
function maintenanceValidateFields(string $title, string $description, int $estimated, bool $isRecurring, ?string $recurEnd, string $dueDate): string
{
    if (mb_strlen($title) > 255) {
        return 'Title is too long (255 characters maximum).';
    }
    if (mb_strlen($description) > 5000) {
        return 'Description is too long (5000 characters maximum).';
    }
    if ($estimated < 1 || $estimated > 100000) {
        return 'Estimated duration must be a positive number of minutes.';
    }
    if ($dueDate !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $dueDate);
        if (!$d || $d->format('Y-m-d') !== $dueDate) {
            return 'Invalid due date format.';
        }
    }
    if ($isRecurring && $recurEnd !== null && $recurEnd !== '') {
        $e = DateTime::createFromFormat('Y-m-d', $recurEnd);
        if (!$e || $e->format('Y-m-d') !== $recurEnd) {
            return 'Recurring end date is not a valid date.';
        }
        if ($dueDate !== '' && $recurEnd < $dueDate) {
            return 'Recurring end date cannot be before the due date.';
        }
    }
    return '';
}

/**
 * An open request (planned / in progress) for the same room with the same title already exists.
 * Returns the existing row ['id','value'] or null. $excludeId skips the row being edited.
 */
function maintenanceFindOpenDuplicate(PDO $pdo, int $roomId, string $title, ?int $excludeId = null): ?array
{
    return rh_find_duplicate($pdo, 'room_maintenance_schedules', 'title', $title,
        ['individual_room_id' => $roomId], $excludeId, 'id', "status IN ('planned','in_progress')");
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
            $title = rh_clean_text($_POST['title'] ?? '');
            $description = trim((string)($_POST['description'] ?? ''));
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
            } elseif (($fieldErr = maintenanceValidateFields($title, $description, $estimated_duration, (bool)$is_recurring, $recurring_end_date, $hasDueDate ? (string)$due_date : '')) !== '') {
                $error = $fieldErr;
            } else {
                // One open request per room + title: check and insert under one lock so a double click cannot add two.
                $mtLockName = substr('rh_form_maint_schedule_' . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64);
                if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($mtLockName) . ', 10)')->fetchColumn() !== 1) {
                    $mtLockName = '';
                    throw new DomainException('Someone else is saving a maintenance request right now. Please try again in a moment.');
                }
                if (in_array($status, ['pending', 'in_progress'], true)) {
                    $dupMt = maintenanceFindOpenDuplicate($pdo, $room_id, $title);
                    if ($dupMt) {
                        throw new DomainException('This room already has an open maintenance request called "' . $dupMt['value'] . '" (request #' . $dupMt['id'] . '). Edit that one instead.');
                    }
                }
                // An open job that closes the room: room locks first (the booking lock order), then the booking check.
                // A booked night refuses the save (owner 2026-11-23); the message lists the bookings and the free dates.
                $mtPlan = $block_room === 1 && rh_maint_status_is_open(rh_maint_status_to_db($status))
                    ? rh_room_nights_lock_plan($pdo, [['scope' => 'room', 'room_ids' => [$room_id]]]) : null;
                $pdo->beginTransaction();
                if ($mtPlan) {
                    rh_room_nights_take_locks($pdo, $mtPlan);
                    $mtClash = rh_maint_clash_check($pdo, $room_id, (string)$start_date, (string)$end_date);
                    if (!$mtClash['ok']) {
                        throw new DomainException($mtClash['message']);
                    }
                }

                $completedAt = in_array($status, ['completed', 'verified'], true) ? date('Y-m-d H:i:s') : null;
                $verifiedAt = ($hasVerifiedAt && $status === 'verified') ? date('Y-m-d H:i:s') : null;
                $verifiedBy = ($hasVerifiedBy && $status === 'verified') ? ($user['id'] ?? null) : null;

                // Build INSERT columns and values based on available columns
                $insertColumns = ['individual_room_id', 'title', 'description', 'status', 'start_date', 'end_date', 'assigned_to', 'created_by'];
                $insertValues = ['?', '?', '?', '?', '?', '?', '?', '?'];
                $insertParams = [$room_id, $title, $description, rh_maint_status_to_db($status), $start_date, $end_date, $assigned_to, $user['id'] ?? null];

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
                try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($mtLockName) . ')'); } catch (Throwable $e2) {}
                $mtLockName = '';
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
            $title = rh_clean_text($_POST['title'] ?? '');
            $description = trim((string)($_POST['description'] ?? ''));
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
            } elseif (($fieldErr = maintenanceValidateFields($title, $description, $estimated_duration, (bool)$is_recurring, $recurring_end_date, $hasDueDate ? (string)$due_date : '')) !== '') {
                $error = $fieldErr;
            } else {
                // Saved as an open job that closes the room: lock the room first (no read before the locks), then
                // read the stored row and check only the nights it newly covers (all nights when the room changes or
                // a closed job is reopened). A booked night refuses the save.
                $mtPlan = $block_room === 1 && rh_maint_status_is_open(rh_maint_status_to_db($status))
                    ? rh_room_nights_lock_plan($pdo, [['scope' => 'room', 'room_ids' => [$room_id]]]) : null;
                $pdo->beginTransaction();
                if ($mtPlan) {
                    rh_room_nights_take_locks($pdo, $mtPlan);
                }
                $existsStmt = $pdo->prepare("SELECT id, individual_room_id, title, status, verified_by FROM room_maintenance_schedules WHERE id = ?");
                $existsStmt->execute([$id]);
                $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    throw new RuntimeException('Maintenance schedule does not exist.');
                }
                $existing['status'] = rh_maint_status_from_db($existing['status'] ?? '', $existing['verified_by'] ?? null);
                if (($existing['status'] ?? '') === 'verified') {
                    throw new DomainException('Verified maintenance schedules are locked and cannot be edited.');
                }
                if ($mtPlan) {
                    $oldStmt = $pdo->prepare("SELECT * FROM room_maintenance_schedules WHERE id = ?");
                    $oldStmt->execute([$id]);
                    $mtOld = $oldStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    $mtClash = rh_maint_clash_check($pdo, $room_id, (string)$start_date, (string)$end_date, $mtOld);
                    if (!$mtClash['ok']) {
                        throw new DomainException($mtClash['message']);
                    }
                }

                // Changing room or title onto an existing open request would create a duplicate.
                if (in_array($status, ['pending', 'in_progress'], true)
                    && ((int)$existing['individual_room_id'] !== $room_id
                        || mb_strtolower(rh_clean_text($existing['title'] ?? '')) !== mb_strtolower($title))) {
                    $dupMt = maintenanceFindOpenDuplicate($pdo, $room_id, $title, $id);
                    if ($dupMt) {
                        throw new DomainException('This room already has an open maintenance request called "' . $dupMt['value'] . '" (request #' . $dupMt['id'] . '). Edit that one instead.');
                    }
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
                $updateParams = [$room_id, $title, $description, rh_maint_status_to_db($status), $start_date, $end_date, $assigned_to];

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
                $why = mtDeleteSchedule($pdo, $id, $user['id'] ?? null, $user['username'] ?? null);
                if ($why !== '') {
                    throw new RuntimeException('Schedule not found.');
                }
                $pdo->commit();
                $message = 'Maintenance schedule deleted successfully.';
            }
        } elseif ($action === 'bulk_schedules' && (string)($_POST['bulk_op'] ?? '') === 'extend') {
            // Extend... / the bar's end handle (GRID_UNIFY_PLAN Phase 3): includes/ops-extend.php, one transaction,
            // room locks first; a job that closes its room is refused on booked nights. preview=1 saves nothing.
            $extHow = ['mode' => (string)($_POST['mode'] ?? 'by') === 'until' ? 'until' : 'by', 'n' => (int)($_POST['n'] ?? 0),
                'unit' => (string)($_POST['unit'] ?? 'days'), 'until' => (string)($_POST['until'] ?? '')];
            $extRes = rh_maint_extend($pdo, adminBulkIds(), $extHow, ['id' => isset($user['id']) ? (int)$user['id'] : null, 'name' => $user['username'] ?? null], [
                'preview' => !empty($_POST['preview']),
                'resize' => !empty($_POST['resize']),
                'reconcile' => static function (int $rid) use ($pdo, $user): void { reconcileMaintenanceRoomStatus($pdo, $rid, $user['id'] ?? null); },
            ]);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => $extRes['ok'], 'message' => $extRes['message'], 'preview' => !empty($_POST['preview']), 'done' => $extRes['done'],
                    'refused' => count($extRes['refused'])]);
                exit;
            }
            if ($extRes['ok']) {
                $message = $extRes['message'];
            } else {
                $error = $extRes['message'];
            }
        } elseif ($action === 'bulk_schedules') {
            // Bulk bar: every selected row goes through the same single-row step as its button.
            $bulkOp = (string)($_POST['bulk_op'] ?? '');
            $bulkIds = adminBulkIds();
            $bulkAssignTo = null;
            if (!in_array($bulkOp, ['start', 'complete', 'verify', 'assign', 'delete'], true)) {
                $error = 'Choose a bulk action.';
            } elseif (!$bulkIds) {
                $error = 'Select at least one schedule.';
            } elseif ($bulkOp === 'assign' && ($_POST['assigned_to'] ?? '') !== '' && !maintenanceUserExists($pdo, (int)$_POST['assigned_to'])) {
                $error = 'Assigned user is invalid.';
            } else {
                $bulkAssignTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
                $bulkDone = 0;
                $bulkSkipped = [];
                $actorId = $user['id'] ?? null;
                $actorName = $user['username'] ?? null;
                $pdo->beginTransaction();
                foreach ($bulkIds as $bulkId) {
                    $why = match ($bulkOp) {
                        'start'    => mtStartSchedule($pdo, $bulkId, $actorId, $actorName),
                        'complete' => mtCompleteSchedule($pdo, $bulkId, $actorId, $actorName),
                        'verify'   => mtVerifySchedule($pdo, $bulkId, $actorId, $actorName),
                        'delete'   => mtDeleteSchedule($pdo, $bulkId, $actorId, $actorName),
                        'assign'   => mtAssignSchedule($pdo, $bulkId, $bulkAssignTo, $actorId, $actorName),
                    };
                    if ($why === '') {
                        $bulkDone++;
                    } else {
                        adminBulkSkip($bulkSkipped, $why);
                    }
                }
                $pdo->commit();
                $bulkVerbs = ['start' => 'started', 'complete' => 'marked done', 'verify' => 'verified', 'assign' => 'assigned', 'delete' => 'deleted'];
                [$message, $error] = adminBulkOutcome($bulkVerbs[$bulkOp], $bulkDone, $bulkSkipped);
            }
        } elseif ($action === 'auto_create_tasks') {
            // The function runs its own transaction (room locks first, then the reads and inserts).
            $autoNotes = [];
            $created = autoCreateMaintenanceTasks($pdo, (int)($user['id'] ?? 0), $autoNotes);
            $message = "Created {$created} maintenance tasks." . ($autoNotes ? ' ' . implode(' ', $autoNotes) : '');
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
            } elseif ($hasPriority && !in_array($priority, $validPriorities, true)) {
                $error = 'Invalid priority.';
            } elseif ($hasMaintenanceType && !in_array($maintenance_type, $validMaintenanceTypes, true)) {
                $error = 'Invalid maintenance type.';
            } else {
                // Owner answer 3 (2026-11-23): each job closes its room only when no booking sleeps there, decided under
                // the room locks (taken first: the valid rooms are worked out before the transaction).
                $room_ids = array_values(array_filter(array_unique(array_map('intval', $room_ids)), static fn($r) => $r > 0 && maintenanceRoomExists($pdo, $r)));
                $hasBlockRoom = maintenanceColumnExists($pdo, 'block_room');
                $bulkPlan = rh_room_nights_lock_plan($pdo, [['scope' => 'room', 'room_ids' => $room_ids]]);
                $bulkNotes = [];
                $pdo->beginTransaction();
                rh_room_nights_take_locks($pdo, $bulkPlan);
                $created = 0;
                $today = date('Y-m-d');

                foreach ($room_ids as $room_id) {
                    $room_id = (int)$room_id;

                    // Check if pending assignment already exists
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM room_maintenance_schedules
                        WHERE individual_room_id = ? AND status IN ('planned', 'pending', '', 'in_progress')
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
                        'planned',
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
                    $jobBlock = rh_maint_job_can_close_room($pdo, $room_id, (string)$insertParams[3], (string)$insertParams[4]) ? 1 : 0;
                    if ($hasBlockRoom) {
                        $insertColumns[] = 'block_room';
                        $insertValues[] = '?';
                        $insertParams[] = $jobBlock;
                    }
                    if ($jobBlock === 0) {
                        $bulkNotes[] = rh_block_label($pdo, null, [$room_id]) . ' is booked on those nights, so its job does not close the room.';
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
                    if ($hasBlockRoom) $newData['block_room'] = $jobBlock;

                    logMaintenanceAction($newMaintenanceId, 'created', null, $newData, $user['id'] ?? null, $user['username'] ?? null);

                    reconcileMaintenanceRoomStatus($pdo, $room_id, $user['id'] ?? null);
                }

                $pdo->commit();
                $message = "Bulk assigned {$created} rooms for maintenance." . ($bulkNotes ? ' ' . implode(' ', $bulkNotes) : '');
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
                        SET verified_by = ?, verified_at = NOW()
                        WHERE id = ? AND status = 'completed' AND verified_at IS NULL
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
                    $pdo->prepare("UPDATE room_maintenance_schedules SET started_at = COALESCE(started_at, NOW()), status = CASE WHEN status IN ('planned', 'pending', '') THEN 'in_progress' ELSE status END WHERE id = ?")
                        ->execute([$id]);
                } else {
                    $pdo->prepare("UPDATE room_maintenance_schedules SET status = 'in_progress' WHERE id = ? AND status IN ('planned', 'pending', '')")
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
        if (!empty($mtLockName)) {
            try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($mtLockName) . ')'); } catch (Throwable $e2) {}
            $mtLockName = '';
        }
        $error = $e instanceof DomainException ? $e->getMessage() : (rh_is_lock_conflict($e) ? rh_room_nights_lock_message() : ('Database error: ' . $e->getMessage()));
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

// Page-load sweep: may put rooms INTO maintenance (a schedule started) but never releases one.
try {
    $roomRows = $pdo->query("SELECT DISTINCT individual_room_id FROM room_maintenance_schedules")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($roomRows as $roomId) {
        reconcileMaintenanceRoomStatus($pdo, (int)$roomId, $user['id'] ?? null, false);
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

// Rooms needing work: out of service or with an open job, one row per room (the query picks at most one
// checked-in guest per room, so the "(N)" heading counts rooms).
$roomsNeedingMaintenance = getRoomsNeedingMaintenance($pdo);

// ?status=out_of_service (Dashboard "Rooms out of service"): only rooms in maintenance or out of order, not the
// rooms this list also shows for an open job alone. Whitelisted; notice + Clear above the list.
$rmFilterStatus = (is_scalar($_GET['status'] ?? null) && (string)$_GET['status'] === 'out_of_service') ? 'out_of_service' : '';
$rmOutOfServiceUnits = 0;
if ($rmFilterStatus !== '') {
    $roomsNeedingMaintenance = array_values(array_filter($roomsNeedingMaintenance, static function (array $r): bool {
        return in_array((string)($r['room_status'] ?? ''), rh_fd_out_of_service_statuses(), true);
    }));
    $rmOutOfServiceUnits = rh_room_units_count(rh_fd_out_of_service_statuses()); // what the Dashboard chip shows
}

// Get staff workload
$staffWorkload = getMaintenanceStaffWorkload($pdo);

// Maintenance tasks are never created as a side effect of loading this page.
// Task creation only happens through the explicit "Create Tasks for Rooms" button (POST + CSRF).

// Get all schedules with enhanced sorting
// Backward compatible: works with or without migration 005 columns
$hasPriority = maintenanceColumnExists($pdo, 'priority');
$hasVerifiedBy = maintenanceColumnExists($pdo, 'verified_by');
$hasMaintenanceType = maintenanceColumnExists($pdo, 'maintenance_type');
$hasDueDate = maintenanceColumnExists($pdo, 'due_date');

// Build ORDER BY clause based on available columns
$orderByClauses = [
    "CASE rms.status WHEN 'pending' THEN 1 WHEN 'planned' THEN 1 WHEN '' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'completed' THEN 3 WHEN 'verified' THEN 4 WHEN 'cancelled' THEN 5 ELSE 99 END"
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
foreach ($schedules as &$schedRow) {
    // storage enum -> workflow status (planned / '' => pending, completed + verified_at => verified)
    $schedRow['status'] = rh_maint_status_from_db($schedRow['status'] ?? '', $schedRow['verified_at'] ?? null);
}
unset($schedRow);

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

    if ($hasPriority && in_array((string)($s['priority'] ?? ''), ['high', 'urgent'], true) && in_array($status, ['pending', 'in_progress'], true)) {
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

    // open emergency jobs only - finished or cancelled ones are history, not an alert
    if ($hasMaintenanceType && ($s['maintenance_type'] ?? null) === 'emergency' && in_array($status, ['pending', 'in_progress'], true)) {
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

// Room timeline (CALENDAR_UI_DECISION 10): first paint for 31 days so every range button works without a fetch.
// Every open job shows (block_room 0 too); the Calendar keeps drawing only the jobs that block the room.
// Deep links: ?id= (job) &room= (room id) &date= &view=timeline|list &days=3|7|14|30; invalid values are ignored.
// Read-only; on any failure the page keeps its list and old cards (the timeline section is simply not printed).
require_once __DIR__ . '/includes/ops-timeline-data.php';
$opsMode = 'maintenance';
$opsPayload = null;
$opsInitial = rh_ops_deeplink($_GET, 'maintenance');
$opsInitial['status'] = '';
$opsToday = date('Y-m-d');
try {
    $opsStart = $opsInitial['date'] ?? $opsToday;
    if ($opsInitial['date'] === null && $opsInitial['id'] > 0) {
        $opsItemDay = rh_ops_item_date($pdo, 'maintenance', $opsInitial['id']);
        if ($opsItemDay !== null && ($opsItemDay < $opsToday || $opsItemDay > date('Y-m-d', strtotime('+30 days')))) {
            $opsStart = $opsItemDay;
        }
    }
    $opsPayload = rh_ops_payload($pdo, 'maintenance', $opsStart, 31, ['user_id' => (int)($user['id'] ?? 0)]);
} catch (Throwable $e) {
    error_log('maintenance timeline: ' . $e->getMessage());
    $opsPayload = null;
}
$opsPage = ['list' => '#maintenanceSchedulesContainer', 'table' => '#maintenanceSchedulesContainer table', 'confirm' => 'rmConfirm', 'edit' => 'editSchedule',
    'bulk' => '#maintenanceSchedulesContainer form[data-bulk-bar]', 'new' => 'rmNewJobFor'];
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
    <link rel="stylesheet" href="css/ops-pages.css?v=<?php echo @filemtime(__DIR__ . '/css/ops-pages.css'); ?>">
    <link rel="stylesheet" href="css/room-maintenance.css?v=<?php echo @filemtime(__DIR__ . '/css/room-maintenance.css'); ?>">
    <link rel="stylesheet" href="css/admin-cockpit.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-cockpit.css'); ?>">
    <link rel="stylesheet" href="css/room-timeline.css?v=<?php echo @filemtime(__DIR__ . '/css/room-timeline.css'); ?>">
    <link rel="stylesheet" href="css/room-timeline-ops.css?v=<?php echo @filemtime(__DIR__ . '/css/room-timeline-ops.css'); ?>">
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
                    <button class="btn btn-secondary" type="button" onclick="rmConfirm(this.closest('form'), 'Create a maintenance task for every room currently in Maintenance / Out of order that has no open task? Rooms with a guest checked in are skipped.', 'Create tasks', 'btn-warning')">
                        <i class="fas fa-list-check"></i> Create Tasks for Rooms
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
        <?php if ($opsPayload): ?>
        <?php
        /* Same four cards and counts as the Calendar's Maintenance tab (rh_cal_maint_tab). A click filters the timeline
           and the list to those jobs (or rooms) and scrolls to them (room-timeline-ops.js); click again to show all. */
        $opsCards = [
            'open' => ['Open', 'info', 'Maintenance jobs not yet completed or cancelled (planned and in progress), all rooms.', 'open', 'Show these jobs'],
            'progress' => ['In progress', 'warn', 'Maintenance jobs being worked on now.', 'progress', 'Show these jobs'],
            'out' => ['Out of order now', 'bad', 'Rooms in maintenance or out of order right now (as the Dashboard counts them).', 'out_now', 'Show these rooms'],
            'due' => ['Due this week', 'plain', 'Open jobs due (or starting, when no due date) by 6 days from today, including overdue ones.', 'due', 'Show these jobs'],
        ];
        ?>
        <?php /* Shared page strip (same markup as housekeeping.php): one row of compact chips. room-timeline-ops.js fills .ck-kpi__value and sets is-active / aria-pressed. */ ?>
        <div class="ops-page-strip" id="rmStatsDashboard" role="group" aria-label="Maintenance counts. Click one to show only those jobs or rooms.">
            <?php foreach ($opsCards as $opsKey => [$opsLabel, $opsTone, $opsHelp, $opsCount, $opsSub]): ?>
                <button type="button" class="ops-chip ops-chip--<?php echo $opsTone; ?>" data-ops-card="<?php echo $opsKey; ?>" data-ops-count="<?php echo $opsCount; ?>" data-ops-sub="<?php echo $opsSub; ?>" aria-pressed="false" title="<?php echo htmlspecialchars($opsSub); ?>. Click again to show all.">
                    <span class="ck-kpi__value ops-chip__value"><?php echo (int)($opsPayload['counts'][$opsCount] ?? 0); ?></span>
                    <span class="ops-chip__label"><?php echo htmlspecialchars($opsLabel); ?> <i class="help" data-tip="<?php echo htmlspecialchars($opsHelp); ?>">?</i></span>
                </button>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="ck-kpis" id="rmStatsDashboard">
            <button type="button" class="ck-kpi" data-stat-key="today_total" data-stat-title="Today's Maintenance Tasks" data-stat-description="All maintenance schedules set for today.">
                <span class="ck-kpi__icon"><i class="fas fa-calendar-day"></i></span>
                <span class="ck-kpi__label">Today's Tasks</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['today_total']; ?></span>
                <span class="ck-kpi__sub"><i class="fas fa-circle-info"></i> Click to see these</span>
            </button>
            <button type="button" class="ck-kpi ck-kpi--info" data-stat-key="pending" data-stat-title="Pending Tasks" data-stat-description="Maintenance tasks waiting for a technician.">
                <span class="ck-kpi__icon"><i class="fas fa-clock"></i></span>
                <span class="ck-kpi__label">Pending Tasks</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['pending']; ?></span>
                <span class="ck-kpi__sub"><i class="fas fa-circle-info"></i> Click to see these</span>
            </button>
            <button type="button" class="ck-kpi ck-kpi--warn" data-stat-key="in_progress" data-stat-title="In Progress" data-stat-description="Repairs currently in active work.">
                <span class="ck-kpi__icon"><i class="fas fa-spinner"></i></span>
                <span class="ck-kpi__label">In Progress</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['in_progress']; ?></span>
                <span class="ck-kpi__sub"><i class="fas fa-circle-info"></i> Click to see these</span>
            </button>
            <button type="button" class="ck-kpi ck-kpi--good" data-stat-key="completed_today" data-stat-title="Completed Today" data-stat-description="Maintenance jobs completed today.">
                <span class="ck-kpi__icon"><i class="fas fa-check"></i></span>
                <span class="ck-kpi__label">Completed Today</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['completed_today']; ?></span>
                <span class="ck-kpi__sub"><i class="fas fa-circle-info"></i> Click to see these</span>
            </button>
            <button type="button" class="ck-kpi ck-kpi--good" data-stat-key="verified_today" data-stat-title="Verified Today" data-stat-description="Maintenance jobs verified today.">
                <span class="ck-kpi__icon"><i class="fas fa-check-double"></i></span>
                <span class="ck-kpi__label">Verified Today</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['verified_today']; ?></span>
                <span class="ck-kpi__sub"><i class="fas fa-circle-info"></i> Click to see these</span>
            </button>
            <button type="button" class="ck-kpi ck-kpi--alert" data-stat-key="high_priority" data-stat-title="High/Urgent Priority" data-stat-description="Critical tasks that should be handled first.">
                <span class="ck-kpi__icon"><i class="fas fa-exclamation-triangle"></i></span>
                <span class="ck-kpi__label">High/Urgent Priority</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['high_priority']; ?></span>
                <span class="ck-kpi__sub"><i class="fas fa-circle-info"></i> Click to see these</span>
            </button>
            <button type="button" class="ck-kpi ck-kpi--alert" data-stat-key="emergency_type" data-stat-title="Emergency" data-stat-description="Open emergency maintenance jobs across rooms.">
                <span class="ck-kpi__icon"><i class="fas fa-bolt"></i></span>
                <span class="ck-kpi__label">Emergency</span>
                <span class="ck-kpi__value"><?php echo (int)$stats['emergency_type']; ?></span>
                <span class="ck-kpi__sub"><i class="fas fa-circle-info"></i> Click to see these</span>
            </button>
        </div>
        <?php endif; ?>
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

        <?php /* Grid first (same order as housekeeping.php): the timeline sits directly under the strip, in the first screen. */ ?>
        <?php if ($opsPayload) { require __DIR__ . '/includes/ops-timeline-view.php'; } ?>

        <?php if ($rmFilterStatus !== ''): ?>
            <div style="background: #eef3ff; border: 1px solid #cfd8ff; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px; color: #1f2d6b;">
                    <i class="fas fa-filter"></i>
                    <strong>Rooms out of service</strong>
                </div>
                <div style="color: #2f3a63; font-size: 13px;">
                    Showing <?php echo count($roomsNeedingMaintenance); ?> room<?php echo count($roomsNeedingMaintenance) === 1 ? '' : 's'; ?> in maintenance or out of order<?php if ($rmOutOfServiceUnits !== count($roomsNeedingMaintenance)): ?> (the Dashboard counts <?php echo (int)$rmOutOfServiceUnits; ?>: a joined room pair counts once)<?php endif; ?>.
                    <a href="room-maintenance.php" style="margin-left: 8px; color: #1f2d6b; font-weight: 600;">Clear</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Rooms needing work (shared layout: .ops-needs-list, the same markup as housekeeping.php) -->
        <?php
        $rmNeedCols = [
            'room' => ['Room', 'The room number and name. Rooms in Maintenance or Out of order, or with an open maintenance job, now.'],
            'status' => ['Room status', 'Out of order or Maintenance (the room is closed for sale), or Open job (the room is still in use but has a job waiting). A guest name shows when a guest is checked in.'],
            'job' => ['Open job', 'The oldest open maintenance job on the room (planned or in progress), and how many more there are. A dash means the room has no open job.'],
            'assigned' => ['Assigned', 'Who has that open job. A dash means the room has no open job.'],
            'assign' => ['Assign', 'Pick a staff member. A room with an open job gives that job to them; a room with no job gets a new inspection job (medium priority) for them.'],
            'open' => ['Open', 'Opens the room\'s open job. Shown only when the room has one.'],
        ];
        ?>
        <section class="ops-panel ops-needs-list" id="rmNeedsAction" aria-labelledby="rmNeedsTitle">
            <div class="ops-panel__head">
                <h3 class="ops-panel__title" id="rmNeedsTitle"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> Rooms needing work (<?php echo count($roomsNeedingMaintenance); ?>) <i class="help" data-tip="Rooms in Maintenance or Out of order, or with an open maintenance job, now. Tick rooms to give them all a job at once, or use the Assign box on a row.">?</i></h3>
            </div>
            <?php if (empty($roomsNeedingMaintenance)): ?>
                <p class="ops-empty">No rooms need work right now.</p>
            <?php else: ?>
                <form method="POST" id="bulkAssignForm" class="ops-needs-list__form">
                    <input type="hidden" name="action" value="bulk_assign_rooms">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="room_ids" id="selectedRoomIds">
                    <div class="ops-needs-list__table" role="table" aria-label="Rooms needing work">
                        <div class="ops-needs-list__cols" role="row">
                            <span class="ops-cell ops-cell--tick" role="columnheader"><input type="checkbox" id="rmSelectAllRooms" class="room-checkbox-all admin-bulk-check" aria-label="Select all rooms shown on this page" onchange="rmSelectVisibleRooms(this.checked)"></span>
                            <?php foreach ($rmNeedCols as $rmColKey => [$rmColLabel, $rmColHelp]): ?>
                                <span class="ops-cell ops-cell--<?php echo $rmColKey; ?>" role="columnheader"><?php echo htmlspecialchars($rmColLabel); ?> <i class="help" data-tip="<?php echo htmlspecialchars($rmColHelp); ?>">?</i></span>
                            <?php endforeach; ?>
                        </div>
                        <?php /* A list so the shared pager (admin-section-pagination.js, ul.ck-rows) shows 10 rows per page with the "Show" picker. */ ?>
                        <ul class="occupied-rooms-list ck-rows" role="rowgroup">
                            <?php foreach ($roomsNeedingMaintenance as $room): ?>
                                <?php
                                $rmCond = (string)($room['room_condition'] ?? 'open_job');
                                $rmCondPill = $rmCond === 'out_of_order' ? 'blocked' : ($rmCond === 'maintenance' ? 'in_progress' : 'pending');
                                $rmCondLabel = $rmCond === 'out_of_order' ? 'Out of order' : ($rmCond === 'maintenance' ? 'Maintenance' : 'Open job');
                                $rmRoomLabel = trim($room['room_number'] . ' ' . ($room['room_name'] ?? ''));
                                $rmJobId = (int)($room['open_job_id'] ?? 0);
                                $rmJobCount = (int)($room['open_job_count'] ?? 0);
                                $rmJobTitle = trim((string)($room['open_job_title'] ?? ''));
                                $rmJobAssignee = trim((string)($room['open_job_assigned_name'] ?? ''));
                                $rmJsLabel = htmlspecialchars((string)json_encode($rmRoomLabel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                                ?>
                                <li class="ops-needs-row" role="row" data-room-id="<?php echo (int)$room['id']; ?>">
                                    <span class="ops-cell ops-cell--tick" role="cell">
                                        <input type="checkbox" class="room-checkbox admin-bulk-check" value="<?php echo (int)$room['id']; ?>" aria-label="Select room <?php echo htmlspecialchars($rmRoomLabel); ?>" onchange="rmRoomTick(this)">
                                    </span>
                                    <span class="ops-cell ops-cell--room" role="rowheader"><strong><?php echo htmlspecialchars($rmRoomLabel); ?></strong></span>
                                    <span class="ops-cell ops-cell--status" role="cell">
                                        <span class="status-pill <?php echo $rmCondPill; ?>"><?php echo $rmCondLabel; ?></span>
                                        <?php if (!empty($room['guest_name'])): ?>
                                            <small class="ops-cell__sub">Guest: <?php echo htmlspecialchars((string)$room['guest_name']); ?><?php if (trim((string)($room['room_guest_name'] ?? '')) !== ''): ?> <span class="room-guest-line">&middot; Room guest: <?php echo htmlspecialchars((string)$room['room_guest_name']); ?></span><?php endif; ?></small>
                                        <?php endif; ?>
                                    </span>
                                    <span class="ops-cell ops-cell--job" role="cell">
                                        <?php if ($rmJobId > 0): ?>
                                            <span class="ops-cell__main"><?php echo htmlspecialchars($rmJobTitle !== '' ? $rmJobTitle : 'Maintenance job'); ?></span>
                                            <small class="ops-cell__sub"><?php echo (string)($room['open_job_status'] ?? '') === 'in_progress' ? 'In progress' : 'Planned'; ?><?php if ($rmJobCount > 1): ?> &middot; <?php echo $rmJobCount - 1; ?> more open<?php endif; ?></small>
                                        <?php else: ?>
                                            <span class="ops-cell__none" aria-label="No open job">&ndash;</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="ops-cell ops-cell--assigned" role="cell">
                                        <?php if ($rmJobId > 0): ?>
                                            <span class="ops-cell__main"><?php echo htmlspecialchars($rmJobAssignee !== '' ? $rmJobAssignee : 'Unassigned'); ?></span>
                                        <?php else: ?>
                                            <span class="ops-cell__none" aria-label="No open job">&ndash;</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="ops-cell ops-cell--assign" role="cell">
                                        <select class="rm-inline-control ops-assign-select" aria-label="Assign room <?php echo htmlspecialchars($rmRoomLabel); ?> to" onchange="rmRowAssign(this, <?php echo (int)$room['id']; ?>, <?php echo $rmJobId; ?>, <?php echo $rmJsLabel; ?>)">
                                            <option value="">Assign to...</option>
                                            <?php foreach ($users as $u): ?>
                                                <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </span>
                                    <span class="ops-cell ops-cell--open" role="cell">
                                        <?php if ($rmJobId > 0): ?>
                                            <a class="btn btn-secondary btn-sm ops-row-btn" href="room-maintenance.php?id=<?php echo $rmJobId; ?>" title="Open this room's job"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Open</a>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <details class="rh-col-help ops-col-help">
                        <summary>What the columns mean</summary>
                        <dl>
                            <?php foreach ($rmNeedCols as [$rmColLabel, $rmColHelp]): ?>
                                <dt><?php echo htmlspecialchars($rmColLabel); ?></dt>
                                <dd><?php echo htmlspecialchars($rmColHelp); ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    </details>
                    <?php /* Appears when a room is ticked; sticks to the bottom of the screen while the list is in view. */ ?>
                    <div class="ops-bulk-bar" id="rmBulkBar" hidden>
                        <span class="ops-bulk-bar__count"><span id="rmBulkCount">0</span> selected</span>
                        <select name="assigned_to" id="bulkAssignTo" class="rm-inline-control" aria-label="Assign selected rooms to">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="maintenance_type" class="rm-inline-control" aria-label="Type for the new jobs">
                            <option value="inspection">Inspection</option>
                            <option value="repair">Repair</option>
                            <option value="replacement">Replacement</option>
                            <option value="upgrade">Upgrade</option>
                            <option value="emergency">Emergency</option>
                        </select>
                        <select name="priority" class="rm-inline-control" aria-label="Priority for the new jobs">
                            <option value="medium">Medium Priority</option>
                            <option value="high">High Priority</option>
                            <option value="urgent">Urgent Priority</option>
                            <option value="low">Low Priority</option>
                        </select>
                        <button type="submit" class="btn btn-primary rm-bulk-assign-btn" id="bulkAssignBtn" disabled>
                            <i class="fas fa-plus" aria-hidden="true"></i> Assign Selected (<span id="selectedCount">0</span>)
                        </button>
                        <button type="button" class="btn btn-secondary rm-bulk-assign-btn" onclick="rmClearRoomTicks()">Clear</button>
                    </div>
                </form>
                <?php /* Per-row Assign: separate forms (a form inside a form is dropped by the browser). Room with no open job: the same bulk_assign_rooms action with one room and the bulk defaults. */ ?>
                <form method="POST" id="rmRowAssignRoomForm">
                    <input type="hidden" name="action" value="bulk_assign_rooms">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="room_ids" id="rmRowAssignRooms" value="">
                    <input type="hidden" name="assigned_to" id="rmRowAssignRoomTo" value="">
                    <input type="hidden" name="priority" value="medium">
                    <input type="hidden" name="maintenance_type" value="inspection">
                </form>
                <?php /* Room with an open job: the same bulk_schedules assign the maintenance table's bulk bar posts, for that one job. */ ?>
                <form method="POST" id="rmRowAssignJobForm">
                    <input type="hidden" name="action" value="bulk_schedules">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="bulk_op" value="assign">
                    <input type="hidden" name="bulk_ids[]" id="rmRowAssignJobId" value="">
                    <input type="hidden" name="assigned_to" id="rmRowAssignJobTo" value="">
                </form>
            <?php endif; ?>
        </section>

        <?php if (!empty($staffWorkload)): ?>
            <?php /* Staff workload: one slim chip row (same data as the old table). */ ?>
            <div class="ops-workload-chips" role="list" aria-label="Staff workload">
                <span class="ops-workload-chips__label">Workload <i class="help" data-tip="Open maintenance jobs (planned or in progress) per active staff member right now, with urgent or high-priority jobs not started yet and jobs marked done today that wait to be verified.">?</i></span>
                <?php foreach ($staffWorkload as $staff): ?>
                    <?php
                    $rmWlOpen = (int)$staff['active_tasks'];
                    $rmWlHigh = (int)$staff['high_priority_pending'];
                    $rmWlDone = (int)$staff['completed_today'];
                    ?>
                    <span class="ops-workload-chip" role="listitem"><strong><?php echo htmlspecialchars($staff['username']); ?></strong> <?php echo $rmWlOpen; ?> open<?php if ($rmWlHigh > 0): ?> <span class="ops-workload-chip__high">&middot; <?php echo $rmWlHigh; ?> high priority</span><?php endif; ?><?php if ($rmWlDone > 0): ?> &middot; <?php echo $rmWlDone; ?> done today<?php endif; ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Maintenance Schedules Table -->
        <?php
        // Every task is in the table; the shared pager (admin-section-pagination.js) shows
        // 10 per page with the "Show" picker, so the search and status filter cover every task, not one page.
        $sched_display = $schedules;
        ?>
        <div class="table-card" id="maintenanceSchedulesContainer" data-rh-results>
            <div class="rm-table-toolbar">
                <h3 class="rm-table-toolbar__title"><i class="fas fa-list-check"></i> All Maintenance Tasks (<?php echo count($schedules); ?>)</h3>
                <div class="rm-table-toolbar__filters">
                    <input type="text" id="tableSearch" class="rm-inline-control rm-inline-control--search" placeholder="Search room, title, staff..." oninput="filterTable()" aria-describedby="rmStatusHelp">
                    <select id="tableStatusFilter" class="rm-inline-control" onchange="filterTable()">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="verified">Verified</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <button type="button" id="tableFilterClear" class="btn btn-secondary rm-filter-clear" onclick="clearTableFilter()" hidden>Clear</button>
                </div>
            </div>
            <p id="rmStatusHelp" class="rm-status-help">
                <strong>Completed does not release the room.</strong> The room stays in Maintenance until the task is <strong>Verified</strong> (the inspection check, use the double-tick button) or Cancelled/deleted. Only then does the room go back to Available.
            </p>
            <table>
                <thead>
                    <tr>
                        <th class="admin-bulk-col"><?php echo adminBulkAllBox('Select all visible schedules'); ?></th>
                        <th data-help="Room|The room the work is for (room number and name).">Room</th>
                        <th data-help="Title|A short name for the job, e.g. what needs fixing.">Title</th>
                        <th data-help="Type|The kind of job (for example repair, preventive or inspection).">Type</th>
                        <th data-help="Priority|How urgent the job is.">Priority</th>
                        <th data-help="Status|Pending (not started), In Progress, Completed (done, waiting to be checked), Verified (checked; the room is released) or Cancelled.">Status</th>
                        <th data-help="Scheduled|The day the job is due (or starts). A green play icon shows the start time, a green tick the finish time.">Scheduled</th>
                        <th data-help="Assigned To|The staff member doing the job.">Assigned To</th>
                        <th data-help="Actions|Start, mark done, edit, view history, verify a finished job, or delete it.">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center;padding:24px;">No maintenance schedules.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sched_display as $row): ?>
                            <tr data-ops-id="<?php echo (int)$row['id']; ?>" data-ops-room="<?php echo (int)($row['individual_room_id'] ?? 0); ?>" data-ops-cards="<?php echo implode(' ', array_keys(array_filter(rh_ops_maint_cards($row, rh_ops_maint_status($row), $opsToday)))); ?>" data-status="<?php echo htmlspecialchars((string)$row['status']); ?>" data-priority="<?php echo htmlspecialchars((string)($row['priority'] ?? '')); ?>" data-type="<?php echo htmlspecialchars((string)($row['maintenance_type'] ?? '')); ?>" data-room="<?php echo htmlspecialchars(trim((string)($row['room_number'] ?? '') . ' ' . (string)($row['room_name'] ?? ''))); ?>" data-assignee="<?php echo htmlspecialchars((string)($row['assigned_to_name'] ?? 'Unassigned')); ?>" data-due-date="<?php echo htmlspecialchars((string)($row['due_date'] ?? '')); ?>">
                                <td class="admin-bulk-col"><?php echo adminBulkRowBox($row['id'], 'Select schedule ' . $row['title']); ?></td>
                                <td><?php echo htmlspecialchars($row['room_number'] . ' ' . ($row['room_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($row['title']); ?></td>
                                <td>
                                    <?php if ($hasMaintenanceType): ?>
                                        <span class="type-badge <?php echo htmlspecialchars((string)$row['maintenance_type']); ?>"><?php echo htmlspecialchars(ucfirst((string)$row['maintenance_type'])); ?></span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($hasPriority): ?>
                                        <span class="priority-badge <?php echo htmlspecialchars((string)$row['priority']); ?>"><?php echo htmlspecialchars(ucfirst((string)$row['priority'])); ?></span>
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
                                                <button class="btn btn-success btn-sm" type="button" title="Mark done (room stays in Maintenance until verified)" onclick="rmConfirm(this.closest('form'), 'Mark this maintenance as completed? The room stays in Maintenance until the task is verified.', 'Mark Done', 'btn-success')"><i class="fas fa-check"></i></button>
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
                                                <button class="btn btn-success btn-sm" type="button" title="Verify (inspect the room, then release it from Maintenance)" onclick="rmConfirm(this.closest('form'), 'Mark this maintenance as verified? The room will be released from Maintenance and this cannot be undone.', 'Verify', 'btn-success')"><i class="fas fa-check-double"></i></button>
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
            <?php adminBulkBarStart('bulk_schedules', '#maintenanceSchedulesContainer', $csrf_token); ?>
                <select name="assigned_to" aria-label="Assign selected to">
                    <option value="">Unassigned</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php adminBulkButton('assign', 'Assign', 'fa-user-check', 'Assign {n} selected schedule(s) to the chosen person? Verified tasks are locked and skipped.', 'btn-primary'); ?>
                <?php adminBulkButton('start', 'Start', 'fa-play', 'Mark {n} selected schedule(s) as started? Only pending ones change.', 'btn-warning'); ?>
                <?php adminBulkButton('complete', 'Mark done', 'fa-check', 'Mark {n} selected schedule(s) as completed? Rooms stay in Maintenance until verified.', 'btn-success'); ?>
                <?php adminBulkButton('verify', 'Verify', 'fa-check-double', 'Verify {n} selected schedule(s)? Only completed ones are verified and their rooms released. This cannot be undone.', 'btn-success'); ?>
                <button type="button" name="bulk_op" value="extend" class="btn btn-secondary btn-sm" data-rh-extend="maint"><i class="fas fa-arrows-left-right" aria-hidden="true"></i> Extend...</button>
                <?php adminBulkButton('delete', 'Delete', 'fa-trash', 'Delete {n} selected schedule(s)? This cannot be undone.', 'btn-danger', '', 'Delete', 'danger'); ?>
            <?php adminBulkBarEnd(); ?>
        </div>

        <!-- Maintenance Log -->
        <?php if (!empty($maintenanceLogs)): ?>
            <div class="table-card" style="margin-top:16px;">
                <div style="padding:12px 16px;border-bottom:1px solid #eef2f7;font-weight:700;color:#1f2d3d;">
                    <i class="fas fa-history"></i> Maintenance Log
                </div>
                <table>
                    <thead>
                        <tr>
                            <th data-help="Room|The room whose status changed.">Room</th>
                            <th data-help="From|The room status before the change.">From</th>
                            <th data-help="To|The room status after the change.">To</th>
                            <th data-help="Reason|Why the status changed (a checkout, a maintenance task, a manual change...).">Reason</th>
                            <th data-help="By|The staff member who made the change (blank when the system did it).">By</th>
                            <th data-help="At|When the change was made.">At</th>
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

    <?php renderAdminModalStart('scheduleModal', 'Add Maintenance', 'maintenance-modal-content', 'scheduleForm'); ?>
        <input type="hidden" name="action" id="formAction" value="add_schedule">
        <input type="hidden" name="id" id="scheduleId">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <p class="tl-multi-note" id="scheduleMultiNote" hidden></p>
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
                    <option value="completed">Completed (room stays in Maintenance)</option>
                    <option value="verified">Verified (releases the room)</option>
                    <option value="cancelled">Cancelled (releases the room)</option>
                </select>
                <small style="color: #6b7280; font-size: 12px;">The room stays in Maintenance until the task is Verified or Cancelled.</small>
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
    <?php renderAdminModalFooterStart(); ?>
        <div id="scheduleFeedback" class="admin-modal-feedback" style="flex:1;"></div>
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
        <button type="submit" id="scheduleSaveBtn" class="btn btn-primary">Save</button>
    <?php renderAdminModalEnd(); ?>

    <?php renderAdminModalStart('auditLogModal', 'Audit History', 'audit-log-modal-content'); ?>
    <div id="auditLogContent">
        <div style="text-align: center; padding: 20px;">
            <i class="fas fa-spinner fa-spin"></i> Loading...
        </div>
    </div>
    <?php renderAdminModalFooterStart(); ?>
        <button type="button" class="btn btn-secondary" onclick="closeAuditLogModal()">Close</button>
    <?php renderAdminModalEnd(); ?>

    <?php renderAdminModalStart('statsQuickModal', 'Maintenance Snapshot', 'maintenance-modal-content rm-stats-modal'); ?>
    <div id="rmStatsModalMeta" class="rm-stats-modal__meta"></div>
    <div id="rmStatsModalList" class="rm-stats-modal__list"></div>
    <?php renderAdminModalFooterStart(); ?>
        <button type="button" class="btn btn-secondary" onclick="closeStatsQuickModal()">Close</button>
    <?php renderAdminModalEnd(); ?>

    <!-- Confirm action modal (replaces window.confirm) -->
    <div class="modal-overlay" id="rmConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
        <div class="modal-content" style="max-width:420px;padding:0;overflow:hidden;">
            <div class="modal-header">
                <h3 id="rmConfirmTitle">Confirm Action</h3>
            </div>
            <div class="modal-body">
                <p style="margin:0;font-size:14px;color:#374151;" id="rmConfirmMessage">Are you sure?</p>
            </div>
            <div class="modal-footer">
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
                    return (item.priority === 'high' || item.priority === 'urgent') && (item.status === 'pending' || item.status === 'in_progress');
                }
            },
            emergency_type: {
                title: 'Emergency Type',
                description: 'Open emergency maintenance jobs across rooms.',
                match: function(item) {
                    return item.type === 'emergency' && (item.status === 'pending' || item.status === 'in_progress');
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

            titleEl.textContent = config.title + ' - Details';
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
                startDateInput.value = rhYmdHm(now);
            }
            if (endDateInput) {
                endDateInput.value = rhYmdHm(tomorrow);
            }
        });

        function openModal() {
            rmSetMulti(null);
            document.getElementById('scheduleModal-title').textContent = 'Add Maintenance';
            document.getElementById('formAction').value = 'add_schedule';
            document.getElementById('scheduleForm').reset();
            document.getElementById('scheduleId').value = '';
            document.getElementById('due_date').min = rhYmd(new Date());
            document.getElementById('recurringOptions').style.display = 'none';

            // Set default dates
            const now = new Date();
            const tomorrow = new Date(now);
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('start_date').value = rhYmdHm(now);
            document.getElementById('end_date').value = rhYmdHm(tomorrow);

            openAdminModal('scheduleModal');
        }

        // New job for the rooms / days picked on a timeline (admin/js/room-timeline-select.js calls rmNewJobFor with
        // [{room, date, label}]; the Calendar links here with ?new_job=1&rooms=&from=&to=). One job per room, from the
        // first to the last picked day (08:00 to 17:00; a job holds a room for whole days, the same rule the calendar
        // and the booking check use). Several rooms: the same form is sent once per room (add_schedule, checked by the
        // server as usual) and the Room field is not shown.
        var rmMulti = null;
        var RM_MULTI_MAX = 30;
        function rmSetMulti(rooms) {
            rmMulti = rooms && rooms.length > 1 ? rooms.slice() : null;
            var rg = document.getElementById('roomSelect').closest('.form-group');
            if (rg) rg.style.display = rmMulti ? 'none' : '';
            var note = document.getElementById('scheduleMultiNote');
            if (!note) return;
            note.hidden = !rmMulti;
            note.textContent = rmMulti
                ? 'This adds ' + rmMulti.length + ' jobs, one for each room: ' + rmMulti.map(function(r) { return r.label; }).join(', ') + '. Everything else below is the same for all of them.'
                : '';
        }
        window.rmNewJobFor = function(list) {
            if (!list || !list.length) return;
            var rooms = [], seen = {}, from = null, to = null;
            list.forEach(function(p) {
                if (!seen[p.room]) { seen[p.room] = true; rooms.push({ room: String(p.room), label: p.label }); }
                if (from === null || p.date < from) from = p.date;
                if (to === null || p.date > to) to = p.date;
            });
            if (rooms.length > RM_MULTI_MAX) {
                if (window.Alert && typeof window.Alert.show === 'function') window.Alert.show('Pick at most ' + RM_MULTI_MAX + ' rooms at a time.', 'warning');
                return;
            }
            openModal();
            document.getElementById('roomSelect').value = rooms[0].room;
            document.getElementById('due_date').value = from;
            document.getElementById('start_date').value = from + 'T08:00';
            document.getElementById('end_date').value = to + 'T17:00';
            rmSetMulti(rooms);
            if (rmMulti) document.getElementById('scheduleModal-title').textContent = 'Add ' + rmMulti.length + ' maintenance jobs';
        };
        function rmSaveMulti(form) {
            var list = rmMulti.slice();
            var saveBtn = document.getElementById('scheduleSaveBtn');
            var fb = document.getElementById('scheduleFeedback');
            var label = saveBtn.textContent;
            saveBtn.disabled = true;
            fb.className = 'admin-modal-feedback';
            fb.textContent = '';
            var done = 0, failed = [], lines = [];
            // Read the form ONCE: if staff close the form and open Edit on a row while this runs, the remaining
            // saves must still be these new jobs, never an update of that row.
            var snap = Array.from(new FormData(form).entries());
            var step = function(i) {
                if (i >= list.length) return Promise.resolve();
                saveBtn.textContent = 'Saving ' + (i + 1) + ' of ' + list.length + '...';
                var fd = new FormData();
                snap.forEach(function(kv) { fd.append(kv[0], kv[1]); });
                fd.set('action', 'add_schedule');
                fd.delete('schedule_id');
                fd.delete('id');
                fd.set('individual_room_id', list[i].room);
                return fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                    .then(function(r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(function(res) {
                        if (res && res.success) { done++; return; }
                        failed.push(list[i]);
                        lines.push(list[i].label + ': ' + ((res && res.message) || 'not added'));
                    }, function() {
                        failed.push(list[i]);
                        lines.push(list[i].label + ': no answer from the server (check the list before trying again)');
                    })
                    .then(function() { return step(i + 1); });
            };
            step(0).then(function() {
                saveBtn.disabled = false;
                saveBtn.textContent = label;
                if (done > 0) refreshMaintenanceTable();
                if (document.getElementById('formAction').value !== 'add_schedule') {
                    // The form was reused for something else meanwhile: report only, never touch it.
                    if (window.Alert && typeof window.Alert.show === 'function') window.Alert.show('Added ' + done + ' of ' + list.length + ' jobs.' + (lines.length ? ' Not added: ' + lines.join('; ') + '.' : ''), failed.length ? 'warning' : 'success');
                    return;
                }
                if (!failed.length) {
                    closeModal();
                    if (window.Alert && typeof window.Alert.show === 'function') window.Alert.show('Added ' + done + ' maintenance jobs.', 'success');
                    return;
                }
                // Only the rooms that were not added stay in the form, so Save again never adds a job twice.
                rmSetMulti(failed.length > 1 ? failed : null);
                if (failed.length === 1) document.getElementById('roomSelect').value = failed[0].room;
                fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                fb.textContent = 'Added ' + done + ' of ' + list.length + ' jobs. Not added: ' + lines.join('; ') + '.';
            });
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
            rmSetMulti(null);
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

        // Rooms needing work list: only the rows on screen (the list is paged) are ticked by the header box.
        function rmSelectVisibleRooms(checked) {
            document.querySelectorAll('.ops-needs-row').forEach(function (row) {
                if (row.hidden) return;
                var cb = row.querySelector('.room-checkbox');
                if (!cb) return;
                cb.checked = checked;
                row.classList.toggle('selected', checked);
            });
            updateSelectedCount();
        }

        function rmRoomTick(cb) {
            var row = cb.closest('.ops-needs-row');
            if (row) row.classList.toggle('selected', cb.checked);
            updateSelectedCount();
        }

        function rmClearRoomTicks() {
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
            const bar = document.getElementById('rmBulkBar');
            const barCount = document.getElementById('rmBulkCount');
            const all = document.getElementById('rmSelectAllRooms');
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

        // Per-row Assign. A room with an open job gives that job to the person (bulk_schedules assign, one id);
        // a room with no job gets a new one (bulk_assign_rooms, one room, inspection, medium priority).
        function rmRowAssign(select, roomId, jobId, roomLabel) {
            var staffId = select.value;
            if (!staffId) return;
            var staffName = select.options[select.selectedIndex].text;
            select.value = '';
            if (jobId > 0) {
                var jf = document.getElementById('rmRowAssignJobForm');
                if (!jf) return;
                document.getElementById('rmRowAssignJobId').value = jobId;
                document.getElementById('rmRowAssignJobTo').value = staffId;
                rmConfirm(jf, 'Give the open job on room ' + roomLabel + ' to ' + staffName + '?', 'Assign', 'btn-primary');
                return;
            }
            var rf = document.getElementById('rmRowAssignRoomForm');
            if (!rf) return;
            document.getElementById('rmRowAssignRooms').value = JSON.stringify([roomId]);
            document.getElementById('rmRowAssignRoomTo').value = staffId;
            rmConfirm(rf, 'Create an inspection job for room ' + roomLabel + ' and assign it to ' + staffName + '?', 'Assign', 'btn-primary');
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
            if (rmMulti && document.getElementById('formAction').value === 'add_schedule') {
                rmSaveMulti(this);
                return;
            }
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
                    fb.innerHTML = '<i class="fas fa-' + (res.success ? 'check-circle' : 'exclamation-circle') + '"></i> '; fb.appendChild(document.createTextNode(String(res.message || ''))); // text, never HTML: clash messages carry guest names
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
                    if (next && cur) {
                        // Keep what the user typed / chose: the toolbar is inside the replaced block.
                        var keepQuery = (document.getElementById('tableSearch') || {}).value || '';
                        var keepStatus = (document.getElementById('tableStatusFilter') || {}).value || '';
                        cur.outerHTML = next.outerHTML;
                        if (document.getElementById('tableSearch')) document.getElementById('tableSearch').value = keepQuery;
                        if (document.getElementById('tableStatusFilter')) document.getElementById('tableStatusFilter').value = keepStatus;
                        // The new table has no pager yet: let the shared pager mount on it.
                        if (window.AdminAutoSectionPagination) window.AdminAutoSectionPagination.init();
                        if (window.AdminBulk) window.AdminBulk.refresh();
                        filterTable(true);
                    }
                    if (nextStats && curStats) curStats.innerHTML = nextStats.innerHTML;
                    if (nextData && curData) curData.textContent = nextData.textContent;
                    if (window.RoomTimeline && typeof window.RoomTimeline.reload === 'function') window.RoomTimeline.reload(); // timeline + card counts
                }).catch(function() {});
        }

        // Searches every task (not just the page on screen). data-filter-hidden tells the shared pager
        // (admin-section-pagination.js) to leave a row out of its pages and counts; display covers a table too
        // short to have a pager. keepPlace = true (after a refresh / Clear): no scroll.
        function filterTable(keepPlace) {
            var query = (document.getElementById('tableSearch').value || '').trim().toLowerCase();
            var status = (document.getElementById('tableStatusFilter').value || '').toLowerCase();
            var container = document.getElementById('maintenanceSchedulesContainer');
            var table = container ? container.querySelector('table') : null;
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

        // From the Calendar: ?new_job=1&rooms=12,13&from=Y-m-d&to=Y-m-d opens the add form for those rooms and days.
        // After DOMContentLoaded: the page fills the form's default dates then, and must not overwrite these.
        (function() {
            var run = function() {
            var p = new URLSearchParams(window.location.search);
            if (p.get('new_job') !== '1') return;
            var ymd = /^\d{4}-\d{2}-\d{2}$/;
            var from = p.get('from') || '', to = p.get('to') || '';
            var ids = String(p.get('rooms') || '').split(',').filter(function(x) { return /^\d+$/.test(x); });
            ['new_job', 'rooms', 'from', 'to'].forEach(function(k) { p.delete(k); });
            try { window.history.replaceState(window.history.state, '', window.location.pathname + (p.toString() ? '?' + p.toString() : '') + window.location.hash); } catch (err) { /* keep the URL */ }
            if (!ymd.test(from) || !ymd.test(to) || to < from || !ids.length) return;
            var sel = document.getElementById('roomSelect');
            var list = [];
            ids.slice(0, RM_MULTI_MAX + 1).forEach(function(id) {
                var o = sel ? sel.querySelector('option[value="' + id + '"]') : null;
                if (o) list.push({ room: id, date: from, label: 'Room ' + o.textContent.trim() });
            });
            if (!list.length) return;
            list.push({ room: list[0].room, date: to, label: list[0].label });
            window.rmNewJobFor(list);
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
            else run();
        })();
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

