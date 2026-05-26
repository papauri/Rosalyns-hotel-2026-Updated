<?php
/**
 * Maintenance Schedules API
 * Enhanced with support for:
 * - Due date validation
 * - Maintenance types (repair, replacement, inspection, upgrade, emergency)
 * - Recurring tasks
 * - Duration tracking
 * - Verification workflow
 * - Booking linkage
 *
 * Endpoints:
 * GET    /api/maintenance-schedules?room_id=    - List maintenance for a room
 * POST   /api/maintenance-schedules              - Create maintenance schedule
 * PUT    /api/maintenance-schedules/{id}         - Update maintenance schedule
 * DELETE /api/maintenance-schedules/{id}         - Delete maintenance schedule
 * PATCH  /api/maintenance-schedules/{id}/complete  - Mark as completed
 * PATCH  /api/maintenance-schedules/{id}/verify     - Mark as verified
 */

if (!defined('API_ACCESS_ALLOWED')) {
    http_response_code(403);
    exit;
}

global $pdo, $auth, $client;
$method = $_SERVER['REQUEST_METHOD'];
$path   = $_SERVER['PATH_INFO'] ?? '';
$id     = null;
$completePath = false;
$verifyPath = false;

if (preg_match('#^/(\d+)/complete$#', $path, $m)) {
    $id = (int)$m[1];
    $completePath = true;
} elseif (preg_match('#^/(\d+)/verify$#', $path, $m)) {
    $id = (int)$m[1];
    $verifyPath = true;
} elseif (preg_match('#^/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
}

switch ($method) {
    case 'GET':
        listSchedules();
        break;
    case 'POST':
        createSchedule();
        break;
    case 'PUT':
        if (!$id) ApiResponse::error('Schedule ID required', 400);
        updateSchedule($id);
        break;
    case 'PATCH':
        if (!$id) ApiResponse::error('Schedule ID required', 400);
        if ($completePath) {
            completeSchedule($id);
        } elseif ($verifyPath) {
            verifySchedule($id);
        } else {
            ApiResponse::error('Use /{id}/complete or /{id}/verify for PATCH', 400);
        }
        break;
    case 'DELETE':
        if (!$id) ApiResponse::error('Schedule ID required', 400);
        deleteSchedule($id);
        break;
    default:
        ApiResponse::error('Method not allowed', 405);
}

/**
 * Check if a column exists in room_maintenance_schedules table
 */
function maintenanceApiColumnExists(PDO $pdo, string $column): bool {
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
 * Check if a table exists in the database
 */
function maintenanceApiTableExists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function maintenanceApiSetRoomStatus(PDO $pdo, int $roomId, string $newStatus, ?string $reason, ?int $performedBy): void {
    $statusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
    $statusStmt->execute([$roomId]);
    $oldStatus = (string)$statusStmt->fetchColumn();
    if ($oldStatus === '' || $oldStatus === $newStatus) {
        return;
    }

    $pdo->prepare("UPDATE individual_rooms SET status = ? WHERE id = ?")->execute([$newStatus, $roomId]);

    if (maintenanceApiTableExists($pdo, 'room_maintenance_log')) {
        $logStmt = $pdo->prepare("INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by) VALUES (?, ?, ?, ?, ?)");
        $logStmt->execute([$roomId, $oldStatus, $newStatus, $reason, $performedBy]);
    }
}

function maintenanceApiHasActiveBlockNow(PDO $pdo, int $roomId): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM room_maintenance_schedules
        WHERE individual_room_id = ?
          AND block_room = 1
          AND status IN ('pending', 'in_progress')
          AND start_date <= NOW()
          AND end_date > NOW()
    ");
    $stmt->execute([$roomId]);
    return (int)$stmt->fetchColumn() > 0;
}

function maintenanceApiSyncRoomStatus(PDO $pdo, int $roomId, ?int $performedBy = null, string $reason = 'Maintenance schedule API sync'): void {
    $statusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
    $statusStmt->execute([$roomId]);
    $current = (string)$statusStmt->fetchColumn();
    if ($current === '') {
        return;
    }

    $hasBlock = maintenanceApiHasActiveBlockNow($pdo, $roomId);
    if ($hasBlock) {
        if (!in_array($current, ['occupied', 'out_of_order', 'maintenance'], true)) {
            maintenanceApiSetRoomStatus($pdo, $roomId, 'maintenance', $reason, $performedBy);
        }
        return;
    }

    if ($current === 'maintenance') {
        maintenanceApiSetRoomStatus($pdo, $roomId, 'available', $reason, $performedBy);
    }
}

function maintenanceApiOverlaps(PDO $pdo, int $roomId, string $startDate, string $endDate, ?int $excludeId = null): bool {
    $sql = "
        SELECT COUNT(*)
        FROM room_maintenance_schedules
        WHERE individual_room_id = ?
          AND block_room = 1
          AND status IN ('pending', 'in_progress')
          AND NOT (end_date <= ? OR start_date >= ?)
    ";
    $params = [$roomId, $startDate, $endDate];
    if ($excludeId !== null) {
        $sql .= " AND id <> ?";
        $params[] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Validate due date - cannot be in the past
 */
function validateApiDueDate(string $dueDate): bool {
    $today = date('Y-m-d');
    $dueTimestamp = strtotime($dueDate);
    $todayTimestamp = strtotime($today);
    
    if ($dueTimestamp === false) {
        return false;
    }
    
    return $dueTimestamp >= $todayTimestamp;
}

function listSchedules() {
    global $pdo;
    $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
    $status = $_GET['status'] ?? null;

    $sql = "SELECT * FROM room_maintenance_schedules WHERE 1=1";
    $params = [];

    if ($roomId) {
        $sql .= " AND individual_room_id = ?";
        $params[] = $roomId;
    }

    if ($status) {
        $validStatuses = ['pending','in_progress','completed','verified','cancelled'];
        if (in_array($status, $validStatuses, true)) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
    }

    // Order by status priority, then due date/start date
    $sql .= " ORDER BY 
        CASE status 
            WHEN 'pending' THEN 1 
            WHEN 'in_progress' THEN 2 
            WHEN 'completed' THEN 3 
            WHEN 'verified' THEN 4 
            WHEN 'cancelled' THEN 5 
            ELSE 99 
        END";
    
    $hasDueDate = maintenanceApiColumnExists($pdo, 'due_date');
    if ($hasDueDate) {
        $sql .= ", due_date ASC";
    } else {
        $sql .= ", start_date ASC";
    }
    
    $sql .= ", created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        if (!empty($row['assigned_to'])) {
            $u = $pdo->prepare("SELECT username FROM admin_users WHERE id = ?");
            $u->execute([$row['assigned_to']]);
            $row['assigned_to_name'] = $u->fetchColumn();
        }
        if (!empty($row['created_by'])) {
            $u = $pdo->prepare("SELECT username FROM admin_users WHERE id = ?");
            $u->execute([$row['created_by']]);
            $row['created_by_name'] = $u->fetchColumn();
        }
        if (!empty($row['verified_by'])) {
            $u = $pdo->prepare("SELECT username FROM admin_users WHERE id = ?");
            $u->execute([$row['verified_by']]);
            $row['verified_by_name'] = $u->fetchColumn();
        }
    }

    ApiResponse::success($rows, 'Schedules fetched');
}

function createSchedule() {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) ApiResponse::error('Invalid JSON body', 400);

    $required = ['individual_room_id','title'];
    $errors = [];
    foreach ($required as $f) {
        if (empty($input[$f])) {
            $errors[$f] = ucfirst(str_replace('_', ' ', $f)) . ' is required';
        }
    }
    if ($errors) ApiResponse::validationError($errors);

    $chk = $pdo->prepare("SELECT id FROM individual_rooms WHERE id = ? AND is_active = 1");
    $chk->execute([(int)$input['individual_room_id']]);
    if (!$chk->fetch()) ApiResponse::error('Room not found or inactive', 404);

    // Check which columns exist
    $hasDueDate = maintenanceApiColumnExists($pdo, 'due_date');
    $hasMaintenanceType = maintenanceApiColumnExists($pdo, 'maintenance_type');
    $hasPriority = maintenanceApiColumnExists($pdo, 'priority');
    $hasIsRecurring = maintenanceApiColumnExists($pdo, 'is_recurring');
    $hasRecurringPattern = maintenanceApiColumnExists($pdo, 'recurring_pattern');
    $hasRecurringEndDate = maintenanceApiColumnExists($pdo, 'recurring_end_date');
    $hasEstimatedDuration = maintenanceApiColumnExists($pdo, 'estimated_duration');
    $hasCompletedAt = maintenanceApiColumnExists($pdo, 'completed_at');
    $hasVerifiedBy = maintenanceApiColumnExists($pdo, 'verified_by');
    $hasVerifiedAt = maintenanceApiColumnExists($pdo, 'verified_at');
    $hasLinkedBookingId = maintenanceApiColumnExists($pdo, 'linked_booking_id');
    $hasAutoCreated = maintenanceApiColumnExists($pdo, 'auto_created');

    // Get values with defaults
    $status = $input['status'] ?? 'pending';
    $priority = $input['priority'] ?? 'medium';
    $maintenanceType = $input['maintenance_type'] ?? 'repair';
    $blockRoom = isset($input['block_room']) ? (int)$input['block_room'] : 1;
    $startDate = $input['start_date'] ?? date('Y-m-d H:i:s');
    $endDate = $input['end_date'] ?? date('Y-m-d H:i:s', strtotime('+1 day'));
    $performedBy = isset($input['created_by']) ? (int)$input['created_by'] : null;
    $dueDate = $input['due_date'] ?? null;
    $estimatedDuration = $input['estimated_duration'] ?? 60;

    // Validation
    $validStatuses = ['pending','in_progress','completed','verified','cancelled'];
    if (!in_array($status, $validStatuses, true)) {
        ApiResponse::validationError(['status' => 'Invalid status']);
    }
    
    $validPriorities = ['low','medium','high','urgent'];
    if ($hasPriority && !in_array($priority, $validPriorities, true)) {
        ApiResponse::validationError(['priority' => 'Invalid priority']);
    }
    
    $validMaintenanceTypes = ['repair','replacement','inspection','upgrade','emergency'];
    if ($hasMaintenanceType && !in_array($maintenanceType, $validMaintenanceTypes, true)) {
        ApiResponse::validationError(['maintenance_type' => 'Invalid maintenance type']);
    }
    
    if (strtotime($startDate) === false || strtotime($endDate) === false || strtotime($endDate) <= strtotime($startDate)) {
        ApiResponse::validationError(['date_range' => 'Invalid start/end date range']);
    }
    
    if ($hasDueDate && $dueDate && !validateApiDueDate($dueDate)) {
        ApiResponse::validationError(['due_date' => 'Due date cannot be in the past']);
    }
    
    if ($blockRoom === 1 && in_array($status, ['pending', 'in_progress'], true) && maintenanceApiOverlaps($pdo, (int)$input['individual_room_id'], $startDate, $endDate)) {
        ApiResponse::validationError(['overlap' => 'Overlapping active maintenance block exists for this room']);
    }

    $pdo->beginTransaction();
    try {
        // Build INSERT columns and values based on available columns
        $insertColumns = ['individual_room_id', 'title', 'description', 'status', 'start_date', 'end_date', 'assigned_to', 'created_by'];
        $insertValues = ['?', '?', '?', '?', '?', '?', '?', '?'];
        $insertParams = [
            (int)$input['individual_room_id'],
            $input['title'],
            $input['description'] ?? null,
            $status,
            $startDate,
            $endDate,
            $input['assigned_to'] ?? null,
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
            $insertParams[] = $maintenanceType;
        }
        if ($hasPriority) {
            $insertColumns[] = 'priority';
            $insertValues[] = '?';
            $insertParams[] = $priority;
        }
        if ($hasIsRecurring) {
            $insertColumns[] = 'is_recurring';
            $insertValues[] = '?';
            $insertParams[] = isset($input['is_recurring']) ? (int)$input['is_recurring'] : 0;
        }
        if ($hasRecurringPattern) {
            $insertColumns[] = 'recurring_pattern';
            $insertValues[] = '?';
            $insertParams[] = $input['recurring_pattern'] ?? null;
        }
        if ($hasRecurringEndDate) {
            $insertColumns[] = 'recurring_end_date';
            $insertValues[] = '?';
            $insertParams[] = $input['recurring_end_date'] ?? null;
        }
        if ($hasEstimatedDuration) {
            $insertColumns[] = 'estimated_duration';
            $insertValues[] = '?';
            $insertParams[] = $estimatedDuration;
        }
        if ($hasCompletedAt && in_array($status, ['completed', 'verified'], true)) {
            $insertColumns[] = 'completed_at';
            $insertValues[] = '?';
            $insertParams[] = date('Y-m-d H:i:s');
        }
        if ($hasVerifiedAt && $status === 'verified') {
            $insertColumns[] = 'verified_at';
            $insertValues[] = '?';
            $insertParams[] = date('Y-m-d H:i:s');
        }
        if ($hasVerifiedBy && $status === 'verified') {
            $insertColumns[] = 'verified_by';
            $insertValues[] = '?';
            $insertParams[] = $performedBy;
        }
        if ($hasLinkedBookingId) {
            $insertColumns[] = 'linked_booking_id';
            $insertValues[] = '?';
            $insertParams[] = $input['linked_booking_id'] ?? null;
        }
        if ($hasAutoCreated) {
            $insertColumns[] = 'auto_created';
            $insertValues[] = '?';
            $insertParams[] = isset($input['auto_created']) ? (int)$input['auto_created'] : 0;
        }
        if (maintenanceApiColumnExists($pdo, 'block_room')) {
            $insertColumns[] = 'block_room';
            $insertValues[] = '?';
            $insertParams[] = $blockRoom;
        }
        
        $insertSql = "INSERT INTO room_maintenance_schedules (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")";
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute($insertParams);

        maintenanceApiSyncRoomStatus($pdo, (int)$input['individual_room_id'], $performedBy, 'Maintenance schedule created via API');
        $newId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    ApiResponse::success(['id' => $newId], 'Maintenance schedule created', 201);
}

function updateSchedule($id) {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) ApiResponse::error('Invalid JSON body', 400);

    $existingStmt = $pdo->prepare("SELECT * FROM room_maintenance_schedules WHERE id = ?");
    $existingStmt->execute([$id]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        ApiResponse::error('Schedule not found', 404);
    }

    // Check which columns exist
    $hasDueDate = maintenanceApiColumnExists($pdo, 'due_date');
    $hasMaintenanceType = maintenanceApiColumnExists($pdo, 'maintenance_type');
    $hasPriority = maintenanceApiColumnExists($pdo, 'priority');
    $hasIsRecurring = maintenanceApiColumnExists($pdo, 'is_recurring');
    $hasRecurringPattern = maintenanceApiColumnExists($pdo, 'recurring_pattern');
    $hasRecurringEndDate = maintenanceApiColumnExists($pdo, 'recurring_end_date');
    $hasEstimatedDuration = maintenanceApiColumnExists($pdo, 'estimated_duration');
    $hasActualDuration = maintenanceApiColumnExists($pdo, 'actual_duration');
    $hasCompletedAt = maintenanceApiColumnExists($pdo, 'completed_at');
    $hasVerifiedBy = maintenanceApiColumnExists($pdo, 'verified_by');
    $hasVerifiedAt = maintenanceApiColumnExists($pdo, 'verified_at');
    $hasLinkedBookingId = maintenanceApiColumnExists($pdo, 'linked_booking_id');

    $allowed = ['individual_room_id','title','description','status','priority','block_room','start_date','end_date','assigned_to','created_by'];
    
    if ($hasDueDate) $allowed[] = 'due_date';
    if ($hasMaintenanceType) $allowed[] = 'maintenance_type';
    if ($hasIsRecurring) $allowed[] = 'is_recurring';
    if ($hasRecurringPattern) $allowed[] = 'recurring_pattern';
    if ($hasRecurringEndDate) $allowed[] = 'recurring_end_date';
    if ($hasEstimatedDuration) $allowed[] = 'estimated_duration';
    if ($hasActualDuration) $allowed[] = 'actual_duration';
    if ($hasLinkedBookingId) $allowed[] = 'linked_booking_id';
    
    $fields = [];
    $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $input)) {
            $fields[] = "$f = ?";
            $params[] = $input[$f];
        }
    }
    if (!$fields) ApiResponse::error('No fields to update', 400);

    $roomId = (int)($input['individual_room_id'] ?? $existing['individual_room_id']);
    $status = (string)($input['status'] ?? $existing['status']);
    $blockRoom = (int)($input['block_room'] ?? $existing['block_room']);
    $startDate = (string)($input['start_date'] ?? $existing['start_date']);
    $endDate = (string)($input['end_date'] ?? $existing['end_date']);
    $dueDate = $hasDueDate ? ($input['due_date'] ?? $existing['due_date']) : null;
    $performedBy = isset($input['created_by']) ? (int)$input['created_by'] : ((isset($existing['created_by']) && $existing['created_by'] !== null) ? (int)$existing['created_by'] : null);

    // Validation
    $validStatuses = ['pending','in_progress','completed','verified','cancelled'];
    if (!in_array($status, $validStatuses, true)) {
        ApiResponse::validationError(['status' => 'Invalid status']);
    }
    
    if ($hasPriority) {
        $validPriorities = ['low','medium','high','urgent'];
        $priority = (string)($input['priority'] ?? $existing['priority']);
        if (!in_array($priority, $validPriorities, true)) {
            ApiResponse::validationError(['priority' => 'Invalid priority']);
        }
    }
    
    if (strtotime($startDate) === false || strtotime($endDate) === false || strtotime($endDate) <= strtotime($startDate)) {
        ApiResponse::validationError(['date_range' => 'Invalid start/end date range']);
    }
    
    if ($hasDueDate && $dueDate && !validateApiDueDate($dueDate)) {
        ApiResponse::validationError(['due_date' => 'Due date cannot be in the past']);
    }
    
    if ($blockRoom === 1 && in_array($status, ['pending', 'in_progress'], true) && maintenanceApiOverlaps($pdo, $roomId, $startDate, $endDate, $id)) {
        ApiResponse::validationError(['overlap' => 'Overlapping active maintenance block exists for this room']);
    }

    // Handle verified_by and verified_at
    if ($hasVerifiedBy && $hasVerifiedAt && $status === 'verified' && ($existing['status'] ?? '') !== 'verified') {
        $fields[] = 'verified_by = ?';
        $params[] = $performedBy;
        $fields[] = 'verified_at = ?';
        $params[] = date('Y-m-d H:i:s');
    } elseif ($status !== 'verified') {
        if ($hasVerifiedAt) {
            $fields[] = 'verified_at = NULL';
        }
        if ($hasVerifiedBy) {
            $fields[] = 'verified_by = NULL';
        }
    }

    // Handle completed_at
    if ($hasCompletedAt) {
        if (in_array($status, ['completed', 'verified'], true) && ($existing['status'] ?? '') !== 'completed' && ($existing['status'] ?? '') !== 'verified') {
            $fields[] = 'completed_at = ?';
            $params[] = date('Y-m-d H:i:s');
        } elseif (!in_array($status, ['completed', 'verified'], true) && in_array($existing['status'] ?? '', ['completed', 'verified'], true)) {
            $fields[] = 'completed_at = NULL';
        }
    }

    $pdo->beginTransaction();
    try {
        $params[] = $id;
        $sql = "UPDATE room_maintenance_schedules SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        maintenanceApiSyncRoomStatus($pdo, $roomId, $performedBy, 'Maintenance schedule updated via API');
        if ((int)$existing['individual_room_id'] !== $roomId) {
            maintenanceApiSyncRoomStatus($pdo, (int)$existing['individual_room_id'], $performedBy, 'Maintenance schedule moved via API');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    ApiResponse::success(null, 'Maintenance schedule updated');
}

function completeSchedule($id) {
    global $pdo;
    $rowStmt = $pdo->prepare("SELECT individual_room_id, created_by FROM room_maintenance_schedules WHERE id = ?");
    $rowStmt->execute([$id]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        ApiResponse::error('Schedule not found', 404);
    }

    $hasCompletedAt = maintenanceApiColumnExists($pdo, 'completed_at');

    $pdo->beginTransaction();
    try {
        $setColumns = ['status = ?', 'completed_at = ?'];
        $params = ['completed', date('Y-m-d H:i:s')];
        
        if (!$hasCompletedAt) {
            $setColumns = ['status = ?'];
            $params = ['completed'];
        }
        
        $params[] = $id;
        $sql = "UPDATE room_maintenance_schedules SET " . implode(', ', $setColumns) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        maintenanceApiSyncRoomStatus($pdo, (int)$row['individual_room_id'], isset($row['created_by']) ? (int)$row['created_by'] : null, 'Maintenance schedule completed via API');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    ApiResponse::success(null, 'Maintenance marked as completed');
}

function verifySchedule($id) {
    global $pdo;
    $hasVerifiedBy = maintenanceApiColumnExists($pdo, 'verified_by');
    $hasVerifiedAt = maintenanceApiColumnExists($pdo, 'verified_at');
    
    if (!$hasVerifiedBy || !$hasVerifiedAt) {
        ApiResponse::error('Verification feature requires database migration 005', 400);
    }
    
    $rowStmt = $pdo->prepare("SELECT individual_room_id, created_by, status FROM room_maintenance_schedules WHERE id = ?");
    $rowStmt->execute([$id]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        ApiResponse::error('Schedule not found', 404);
    }
    
    if (($row['status'] ?? '') !== 'completed') {
        ApiResponse::error('Schedule must be completed before verification', 400);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE room_maintenance_schedules SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ?");
        $stmt->execute([isset($row['created_by']) ? (int)$row['created_by'] : null, $id]);
        
        maintenanceApiSyncRoomStatus($pdo, (int)$row['individual_room_id'], isset($row['created_by']) ? (int)$row['created_by'] : null, 'Maintenance schedule verified via API');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    ApiResponse::success(null, 'Maintenance marked as verified');
}

function deleteSchedule($id) {
    global $pdo;
    $rowStmt = $pdo->prepare("SELECT individual_room_id, created_by FROM room_maintenance_schedules WHERE id = ?");
    $rowStmt->execute([$id]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        ApiResponse::error('Schedule not found', 404);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("DELETE FROM room_maintenance_schedules WHERE id = ?");
        $stmt->execute([$id]);
        maintenanceApiSyncRoomStatus($pdo, (int)$row['individual_room_id'], isset($row['created_by']) ? (int)$row['created_by'] : null, 'Maintenance schedule deleted via API');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    ApiResponse::success(null, 'Maintenance schedule deleted');
}
