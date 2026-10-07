<?php
/**
 * Housekeeping Assignments API
 * Enhanced with priority, assignment types, recurring tasks, and verification
 *
 * Endpoints:
 * GET    /api/housekeeping                      - List housekeeping assignments
 * GET    /api/housekeeping/occupied-rooms       - Get occupied rooms needing housekeeping
 * GET    /api/housekeeping/checkout-cleanup     - Get rooms needing checkout cleanup
 * GET    /api/housekeeping/staff-workload       - Get staff workload statistics
 * POST   /api/housekeeping                      - Create assignment
 * PUT    /api/housekeeping/{id}                 - Update assignment
 * PUT    /api/housekeeping/{id}/status          - Update status
 * PUT    /api/housekeeping/{id}/verify          - Verify assignment
 * DELETE /api/housekeeping/{id}                 - Delete assignment
 */

if (!defined('API_ACCESS_ALLOWED')) {
    http_response_code(403);
    exit;
}

global $pdo, $auth, $client;
$method = $_SERVER['REQUEST_METHOD'];
$path   = $_SERVER['PATH_INFO'] ?? '';
$id     = null;
$statusPath = false;
$verifyPath = false;
$specialPath = null;

if (preg_match('#^/(\d+)/verify$#', $path, $m)) {
    $id = (int)$m[1];
    $verifyPath = true;
} elseif (preg_match('#^/(\d+)/status$#', $path, $m)) {
    $id = (int)$m[1];
    $statusPath = true;
} elseif (preg_match('#^/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
} elseif ($path === '/occupied-rooms') {
    $specialPath = 'occupied-rooms';
} elseif ($path === '/checkout-cleanup') {
    $specialPath = 'checkout-cleanup';
} elseif ($path === '/staff-workload') {
    $specialPath = 'staff-workload';
}

switch ($method) {
    case 'GET':
        if ($specialPath) {
            getSpecialEndpoint($specialPath);
        } else {
            listAssignments();
        }
        break;
    case 'POST':
        createAssignment();
        break;
    case 'PUT':
        if (!$id) ApiResponse::error('Assignment ID required', 400);
        if ($verifyPath) {
            verifyAssignment($id);
        } elseif ($statusPath) {
            updateStatus($id);
        } else {
            updateAssignment($id);
        }
        break;
    case 'DELETE':
        if (!$id) ApiResponse::error('Assignment ID required', 400);
        deleteAssignment($id);
        break;
    default:
        ApiResponse::error('Method not allowed', 405);
}

/**
 * Validate due date - cannot be in the past
 */
function validateDueDate(string $dueDate): bool {
    $today = date('Y-m-d');
    $dueTimestamp = strtotime($dueDate);
    $todayTimestamp = strtotime($today);
    
    if ($dueTimestamp === false) {
        return false;
    }
    
    return $dueTimestamp >= $todayTimestamp;
}

/**
 * Normalise a date input to Y-m-d, or null when it is not a real date
 * (strict SQL rejects free text such as "tomorrow" in DATE columns).
 */
function apiHkNormaliseDate($value): ?string {
    if ($value === null || $value === '') return null;
    $ts = strtotime((string)$value);
    return $ts === false ? null : date('Y-m-d', $ts);
}

/**
 * True when an admin user with this id exists (assigned_to / verified_by / created_by).
 */
function apiHkUserExists($userId): bool {
    global $pdo;
    if (!is_numeric($userId) || (int)$userId <= 0) return false;
    $chk = $pdo->prepare("SELECT 1 FROM admin_users WHERE id = ?");
    $chk->execute([(int)$userId]);
    return (bool)$chk->fetchColumn();
}

/**
 * housekeeping_assignments.status is enum(pending, in_progress, completed, blocked): there is no
 * 'verified' value and strict SQL rejects one. 'verified' is stored as completed + verified_at set
 * and translated back on read.
 */
function apiHkStatusToDb(string $status): string {
    return $status === 'verified' ? 'completed' : $status;
}

function apiHkStatusFromRow(array $row): string {
    $status = (string)($row['status'] ?? '');
    $marker = $row['verified_at'] ?? null;
    if ($status === 'completed' && $marker !== null && $marker !== '' && strpos((string)$marker, '0000-00-00') !== 0) {
        return 'verified';
    }
    return $status;
}

function apiHkAssignmentExists(int $id): bool {
    global $pdo;
    $chk = $pdo->prepare("SELECT 1 FROM housekeeping_assignments WHERE id = ?");
    $chk->execute([$id]);
    return (bool)$chk->fetchColumn();
}

/**
 * Get occupied rooms that need housekeeping
 */
function getOccupiedRooms(): array {
    global $pdo;
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
 */
function getCheckoutCleanupRooms(): array {
    global $pdo;
    $sql = "
        SELECT DISTINCT 
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
          AND NOT EXISTS (
              SELECT 1 FROM housekeeping_assignments ha
              WHERE ha.individual_room_id = ir.id
                AND ha.assignment_type = 'checkout_cleanup'
                AND ha.status IN ('pending', 'in_progress', 'completed', 'verified')
                AND ha.linked_booking_id = b.id
          )
        ORDER BY b.check_out_date ASC, ir.room_number ASC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get staff workload statistics
 */
function getStaffWorkload(): array {
    global $pdo;
    $sql = "
        SELECT 
            u.id,
            u.username,
            COUNT(CASE WHEN ha.status IN ('pending', 'in_progress') THEN 1 END) as active_tasks,
            COUNT(CASE WHEN ha.status = 'pending' AND ha.priority = 'high' THEN 1 END) as high_priority_pending,
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
 * Sync individual_rooms status/housekeeping_status after an assignment changes.
 * Mirror of reconcileIndividualRoomHousekeeping() in admin/housekeeping.php.
 */
function apiReconcileRoom(int $assignmentId): void {
    global $pdo;
    $row = $pdo->prepare("SELECT individual_room_id FROM housekeeping_assignments WHERE id = ?");
    $row->execute([$assignmentId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) apiReconcileRoomById((int)$r['individual_room_id']);
}

function apiReconcileRoomById(int $roomId): void {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT status FROM housekeeping_assignments
        WHERE individual_room_id = ?
          AND status IN ('pending','in_progress','blocked')
        ORDER BY CASE status WHEN 'in_progress' THEN 1 WHEN 'pending' THEN 2 WHEN 'blocked' THEN 3 ELSE 99 END
        LIMIT 1
    ");
    $stmt->execute([$roomId]);
    $open = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($open) {
        $hsStatus = in_array($open['status'], ['pending','in_progress'], true) ? $open['status'] : 'pending';
        $pdo->prepare("UPDATE individual_rooms SET housekeeping_status = ? WHERE id = ?")
            ->execute([$hsStatus, $roomId]);
        $rs = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
        $rs->execute([$roomId]);
        if ((string)$rs->fetchColumn() === 'available') {
            $pdo->prepare("UPDATE individual_rooms SET status = 'cleaning' WHERE id = ?")
                ->execute([$roomId]);
        }
    } else {
        $pdo->prepare("UPDATE individual_rooms SET housekeeping_status = 'completed', housekeeping_notes = NULL WHERE id = ?")
            ->execute([$roomId]);
        $rs = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
        $rs->execute([$roomId]);
        if ((string)$rs->fetchColumn() === 'cleaning') {
            $pdo->prepare("UPDATE individual_rooms SET status = 'available' WHERE id = ?")
                ->execute([$roomId]);
        }
    }
}

/**
 * Handle special endpoints
 */
function getSpecialEndpoint(string $endpoint): void {
    switch ($endpoint) {
        case 'occupied-rooms':
            ApiResponse::success(getOccupiedRooms(), 'Occupied rooms fetched');
            break;
        case 'checkout-cleanup':
            ApiResponse::success(getCheckoutCleanupRooms(), 'Checkout cleanup rooms fetched');
            break;
        case 'staff-workload':
            ApiResponse::success(getStaffWorkload(), 'Staff workload fetched');
            break;
        default:
            ApiResponse::error('Unknown endpoint', 404);
    }
}

/**
 * List all housekeeping assignments
 */
function listAssignments(): void {
    global $pdo;
    $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
    $status = $_GET['status'] ?? null;
    $priority = $_GET['priority'] ?? null;
    $assignedTo = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;
    
    $sql = "SELECT ha.*, ir.room_number, ir.room_name 
            FROM housekeeping_assignments ha
            LEFT JOIN individual_rooms ir ON ha.individual_room_id = ir.id
            WHERE 1=1";
    $params = [];

    if ($roomId) {
        $sql .= " AND ha.individual_room_id = ?";
        $params[] = $roomId;
    }
    if ($status) {
        $validStatuses = ['pending','in_progress','completed','verified','blocked'];
        if (in_array($status, $validStatuses, true)) {
            if ($status === 'verified') {
                $sql .= " AND ha.status = 'completed' AND ha.verified_at IS NOT NULL";
            } elseif ($status === 'completed') {
                $sql .= " AND ha.status = 'completed' AND ha.verified_at IS NULL";
            } else {
                $sql .= " AND ha.status = ?";
                $params[] = $status;
            }
        }
    }
    if ($priority) {
        $validPriorities = ['high','medium','low'];
        if (in_array($priority, $validPriorities, true)) {
            $sql .= " AND ha.priority = ?";
            $params[] = $priority;
        }
    }
    if ($assignedTo) {
        $sql .= " AND ha.assigned_to = ?";
        $params[] = $assignedTo;
    }

    $sql .= " ORDER BY 
        CASE ha.status
            WHEN 'pending' THEN 1
            WHEN 'in_progress' THEN 2
            WHEN 'completed' THEN 3
            WHEN 'verified' THEN 4
            WHEN 'blocked' THEN 5
            ELSE 99
        END,
        CASE ha.priority
            WHEN 'high' THEN 1
            WHEN 'medium' THEN 2
            WHEN 'low' THEN 3
            ELSE 4
        END,
        ha.due_date ASC, ha.created_at DESC";
        
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['status'] = apiHkStatusFromRow($row);
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

    ApiResponse::success($rows, 'Housekeeping assignments fetched');
}

/**
 * Create a new housekeeping assignment
 */
function createAssignment(): void {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) ApiResponse::error('Invalid JSON body', 400);

    $required = ['individual_room_id','due_date'];
    $errors = [];
    foreach ($required as $f) {
        if (empty($input[$f])) {
            $errors[$f] = ucfirst(str_replace('_', ' ', $f)) . ' is required';
        }
    }
    if ($errors) ApiResponse::validationError($errors);

    // Validate due date is a real date and not in the past
    $dueDate = apiHkNormaliseDate($input['due_date']);
    if ($dueDate === null) {
        ApiResponse::error('Due date is not a valid date', 400);
    }
    if (!validateDueDate($dueDate)) {
        ApiResponse::error('Due date cannot be in the past', 400);
    }
    foreach (['assigned_to', 'created_by'] as $uf) {
        if (!empty($input[$uf]) && !apiHkUserExists($input[$uf])) {
            ApiResponse::error(ucfirst(str_replace('_', ' ', $uf)) . ' user not found', 404);
        }
    }
    $estimatedDuration = isset($input['estimated_duration']) && is_numeric($input['estimated_duration'])
        ? max(0, min(1440, (int)$input['estimated_duration'])) : 30;

    // Validate room exists
    $chk = $pdo->prepare("SELECT id FROM individual_rooms WHERE id = ? AND is_active = 1");
    $chk->execute([(int)$input['individual_room_id']]);
    if (!$chk->fetch()) ApiResponse::error('Room not found or inactive', 404);

    // Validate priority
    $priority = $input['priority'] ?? 'medium';
    $validPriorities = ['high','medium','low'];
    if (!in_array($priority, $validPriorities, true)) {
        ApiResponse::error('Invalid priority level', 400);
    }

    // Validate assignment type
    $assignmentType = $input['assignment_type'] ?? 'regular_cleaning';
    $validTypes = ['checkout_cleanup','regular_cleaning','maintenance','deep_clean','turn_down'];
    if (!in_array($assignmentType, $validTypes, true)) {
        ApiResponse::error('Invalid assignment type', 400);
    }

    // Validate status
    $status = $input['status'] ?? 'pending';
    $validStatuses = ['pending','in_progress','completed','verified','blocked'];
    if (!in_array($status, $validStatuses, true)) {
        ApiResponse::error('Invalid status', 400);
    }

    // Handle recurring settings
    $isRecurring = !empty($input['is_recurring']) ? 1 : 0;
    $recurringPattern = null;
    $recurringEndDate = null;
    if ($isRecurring) {
        $validPatterns = ['daily','weekly','monthly'];
        $recurringPattern = $input['recurring_pattern'] ?? null;
        if (!in_array($recurringPattern, $validPatterns, true)) {
            ApiResponse::error('Invalid recurring pattern', 400);
        }
        $recurringEndDate = apiHkNormaliseDate($input['recurring_end_date'] ?? null);
    }

    $completedAt = in_array($status, ['completed', 'verified'], true) ? date('Y-m-d H:i:s') : null;
    $verifiedAt = $status === 'verified' ? date('Y-m-d H:i:s') : null;
    
    $stmt = $pdo->prepare("
        INSERT INTO housekeeping_assignments 
        (individual_room_id, status, due_date, assigned_to, created_by, notes, priority, assignment_type,
         is_recurring, recurring_pattern, recurring_end_date, estimated_duration, completed_at, verified_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int)$input['individual_room_id'],
        apiHkStatusToDb($status),
        $dueDate,
        !empty($input['assigned_to']) ? (int)$input['assigned_to'] : null,
        !empty($input['created_by']) ? (int)$input['created_by'] : null,
        isset($input['notes']) ? (string)$input['notes'] : null,
        $priority,
        $assignmentType,
        $isRecurring,
        $recurringPattern,
        $recurringEndDate,
        $estimatedDuration,
        $completedAt,
        $verifiedAt
    ]);
    $newId = $pdo->lastInsertId();

    // An open task puts the room into 'cleaning' / housekeeping pending (same as the admin page)
    apiReconcileRoomById((int)$input['individual_room_id']);

    ApiResponse::success(['id' => $newId], 'Assignment created', 201);
}

/**
 * Update an existing assignment
 */
function updateAssignment($id): void {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) ApiResponse::error('Invalid JSON body', 400);

    if (!apiHkAssignmentExists((int)$id)) {
        ApiResponse::error('Assignment not found', 404);
    }

    // Validate due date if provided
    if (isset($input['due_date'])) {
        $normDue = apiHkNormaliseDate($input['due_date']);
        if ($normDue === null) {
            ApiResponse::error('Due date is not a valid date', 400);
        }
        if (!validateDueDate($normDue)) {
            ApiResponse::error('Due date cannot be in the past', 400);
        }
        $input['due_date'] = $normDue;
    }
    // Enum / reference columns: strict SQL throws on bad values, so reject them cleanly
    $enumRules = [
        'status' => ['pending','in_progress','completed','verified','blocked'],
        'priority' => ['high','medium','low'],
        'assignment_type' => ['checkout_cleanup','regular_cleaning','maintenance','deep_clean','turn_down'],
        'recurring_pattern' => ['daily','weekly','monthly'],
    ];
    foreach ($enumRules as $ef => $allowedValues) {
        if (array_key_exists($ef, $input) && !($ef === 'recurring_pattern' && $input[$ef] === null)
            && !in_array($input[$ef], $allowedValues, true)) {
            ApiResponse::error('Invalid ' . str_replace('_', ' ', $ef), 400);
        }
    }
    if (array_key_exists('recurring_end_date', $input)) {
        $input['recurring_end_date'] = apiHkNormaliseDate($input['recurring_end_date']);
    }
    foreach (['assigned_to', 'created_by'] as $uf) {
        if (!empty($input[$uf]) && !apiHkUserExists($input[$uf])) {
            ApiResponse::error(ucfirst(str_replace('_', ' ', $uf)) . ' user not found', 404);
        }
        if (array_key_exists($uf, $input)) {
            $input[$uf] = empty($input[$uf]) ? null : (int)$input[$uf];
        }
    }
    foreach (['estimated_duration', 'actual_duration'] as $df) {
        if (array_key_exists($df, $input)) {
            if ($input[$df] === null || $input[$df] === '') {
                $input[$df] = null;
            } elseif (!is_numeric($input[$df])) {
                ApiResponse::error('Invalid ' . str_replace('_', ' ', $df), 400);
            } else {
                $input[$df] = max(0, min(1440, (int)$input[$df]));
            }
        }
    }
    if (array_key_exists('individual_room_id', $input)) {
        $rchk = $pdo->prepare("SELECT 1 FROM individual_rooms WHERE id = ? AND is_active = 1");
        $rchk->execute([(int)$input['individual_room_id']]);
        if (!$rchk->fetchColumn()) ApiResponse::error('Room not found or inactive', 404);
        $input['individual_room_id'] = (int)$input['individual_room_id'];
    }

    $allowed = ['status','due_date','assigned_to','created_by','notes','individual_room_id','priority',
                'assignment_type','is_recurring','recurring_pattern','recurring_end_date','estimated_duration','actual_duration'];
    $fields = [];
    $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $input)) {
            $fields[] = "$f = ?";
            $params[] = ($f === 'status') ? apiHkStatusToDb((string)$input[$f]) : $input[$f];
        }
    }
    
    // Handle completed_at based on status
    if (isset($input['status'])) {
        if (in_array($input['status'], ['completed', 'verified'], true)) {
            $fields[] = "completed_at = COALESCE(completed_at, ?)";
            $params[] = date('Y-m-d H:i:s');
            if ($input['status'] === 'completed') {
                // Plain 'completed' is not 'verified': drop any earlier verification
                $fields[] = "verified_at = NULL";
                $fields[] = "verified_by = NULL";
            }
        } else {
            // Re-opened task: it is no longer completed or verified
            $fields[] = "completed_at = NULL";
            $fields[] = "verified_at = NULL";
            $fields[] = "verified_by = NULL";
        }
    }
    
    // Handle verified_at and verified_by for verified status
    if (isset($input['status']) && $input['status'] === 'verified') {
        $fields[] = "verified_at = ?";
        $params[] = date('Y-m-d H:i:s');
        if (!empty($input['verified_by']) && apiHkUserExists($input['verified_by'])) {
            $fields[] = "verified_by = ?";
            $params[] = (int)$input['verified_by'];
        }
    }
    
    if (!$fields) ApiResponse::error('No fields to update', 400);

    // Capture old room_id before update in case it changes
    $oldRoom = $pdo->prepare("SELECT individual_room_id FROM housekeeping_assignments WHERE id = ?");
    $oldRoom->execute([$id]);
    $oldRoomId = (int)($oldRoom->fetchColumn() ?: 0);

    $params[] = $id;
    $sql = "UPDATE housekeeping_assignments SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    apiReconcileRoomById($oldRoomId);
    if (isset($input['individual_room_id']) && (int)$input['individual_room_id'] !== $oldRoomId) {
        apiReconcileRoomById((int)$input['individual_room_id']);
    }

    ApiResponse::success(null, 'Assignment updated');
}

/**
 * Update assignment status only
 */
function updateStatus($id): void {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) ApiResponse::error('Invalid JSON body', 400);
    $status = $input['status'] ?? null;
    $validStatuses = ['pending','in_progress','completed','verified','blocked'];
    if (!$status || !in_array($status, $validStatuses, true)) {
        ApiResponse::validationError(['status' => 'Invalid status']);
    }

    if (!apiHkAssignmentExists((int)$id)) {
        ApiResponse::error('Assignment not found', 404);
    }

    $completedAt = in_array($status, ['completed', 'verified'], true) ? date('Y-m-d H:i:s') : null;
    $verifiedAt = $status === 'verified' ? date('Y-m-d H:i:s') : null;
    $verifiedBy = ($status === 'verified' && !empty($input['verified_by']) && apiHkUserExists($input['verified_by'])) ? (int)$input['verified_by'] : null;
    
    $stmt = $pdo->prepare("UPDATE housekeeping_assignments SET status = ?, completed_at = ?, verified_at = ?, verified_by = ? WHERE id = ?");
    $stmt->execute([apiHkStatusToDb($status), $completedAt, $verifiedAt, $verifiedBy, $id]);
    apiReconcileRoom($id);
    ApiResponse::success(null, 'Status updated');
}

/**
 * Verify an assignment (mark as verified)
 */
function verifyAssignment($id): void {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    $verifiedBy = $input['verified_by'] ?? null;
    
    if (!$verifiedBy) {
        ApiResponse::error('verified_by is required', 400);
    }
    
    // Verify user exists
    $chk = $pdo->prepare("SELECT id FROM admin_users WHERE id = ? AND is_active = 1");
    $chk->execute([$verifiedBy]);
    if (!$chk->fetch()) {
        ApiResponse::error('Verifier not found or inactive', 404);
    }
    
    // Check if assignment is completed
    $chk = $pdo->prepare("SELECT status, verified_at FROM housekeeping_assignments WHERE id = ?");
    $chk->execute([$id]);
    $assignment = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        ApiResponse::error('Assignment not found', 404);
    }
    if (apiHkStatusFromRow($assignment) !== 'completed') {
        ApiResponse::error('Assignment must be completed before verification', 400);
    }
    
    $stmt = $pdo->prepare("UPDATE housekeeping_assignments SET verified_by = ?, verified_at = NOW() WHERE id = ? AND status = 'completed'");
    $stmt->execute([$verifiedBy, $id]);
    apiReconcileRoom($id);
    ApiResponse::success(null, 'Assignment verified');
}

/**
 * Delete an assignment
 */
function deleteAssignment($id): void {
    global $pdo;
    $row = $pdo->prepare("SELECT individual_room_id FROM housekeeping_assignments WHERE id = ?");
    $row->execute([$id]);
    $roomId = (int)($row->fetchColumn() ?: 0);
    $pdo->prepare("DELETE FROM housekeeping_assignments WHERE id = ?")->execute([$id]);
    if ($roomId) apiReconcileRoomById($roomId);
    ApiResponse::success(null, 'Assignment deleted');
}
