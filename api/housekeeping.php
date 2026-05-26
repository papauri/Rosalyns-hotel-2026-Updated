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
                AND ha.status IN ('pending', 'in_progress')
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
            $sql .= " AND ha.status = ?";
            $params[] = $status;
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

    // Validate due date is not in the past
    if (!validateDueDate($input['due_date'])) {
        ApiResponse::error('Due date cannot be in the past', 400);
    }

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
        $recurringEndDate = $input['recurring_end_date'] ?? null;
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
        $status,
        $input['due_date'],
        $input['assigned_to'] ?? null,
        $input['created_by'] ?? null,
        $input['notes'] ?? null,
        $priority,
        $assignmentType,
        $isRecurring,
        $recurringPattern,
        $recurringEndDate,
        $input['estimated_duration'] ?? 30,
        $completedAt,
        $verifiedAt
    ]);

    ApiResponse::success(['id' => $pdo->lastInsertId()], 'Assignment created', 201);
}

/**
 * Update an existing assignment
 */
function updateAssignment($id): void {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) ApiResponse::error('Invalid JSON body', 400);

    // Validate due date if provided
    if (isset($input['due_date']) && !validateDueDate($input['due_date'])) {
        ApiResponse::error('Due date cannot be in the past', 400);
    }

    $allowed = ['status','due_date','assigned_to','created_by','notes','individual_room_id','priority',
                'assignment_type','is_recurring','recurring_pattern','recurring_end_date','estimated_duration','actual_duration'];
    $fields = [];
    $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $input)) {
            $fields[] = "$f = ?";
            $params[] = $input[$f];
        }
    }
    
    // Handle completed_at based on status
    if (isset($input['status'])) {
        if (in_array($input['status'], ['completed', 'verified'], true)) {
            $fields[] = "completed_at = ?";
            $params[] = date('Y-m-d H:i:s');
        }
    }
    
    // Handle verified_at and verified_by for verified status
    if (isset($input['status']) && $input['status'] === 'verified') {
        $fields[] = "verified_at = ?";
        $params[] = date('Y-m-d H:i:s');
        if (isset($input['verified_by'])) {
            $fields[] = "verified_by = ?";
            $params[] = $input['verified_by'];
        }
    }
    
    if (!$fields) ApiResponse::error('No fields to update', 400);

    $params[] = $id;
    $sql = "UPDATE housekeeping_assignments SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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

    $completedAt = in_array($status, ['completed', 'verified'], true) ? date('Y-m-d H:i:s') : null;
    $verifiedAt = $status === 'verified' ? date('Y-m-d H:i:s') : null;
    $verifiedBy = ($status === 'verified' && isset($input['verified_by'])) ? $input['verified_by'] : null;
    
    $stmt = $pdo->prepare("UPDATE housekeeping_assignments SET status = ?, completed_at = ?, verified_at = ?, verified_by = ? WHERE id = ?");
    $stmt->execute([$status, $completedAt, $verifiedAt, $verifiedBy, $id]);
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
    $chk = $pdo->prepare("SELECT status FROM housekeeping_assignments WHERE id = ?");
    $chk->execute([$id]);
    $assignment = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        ApiResponse::error('Assignment not found', 404);
    }
    if ($assignment['status'] !== 'completed') {
        ApiResponse::error('Assignment must be completed before verification', 400);
    }
    
    $stmt = $pdo->prepare("UPDATE housekeeping_assignments SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ?");
    $stmt->execute([$verifiedBy, $id]);
    ApiResponse::success(null, 'Assignment verified');
}

/**
 * Delete an assignment
 */
function deleteAssignment($id): void {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM housekeeping_assignments WHERE id = ?");
    $stmt->execute([$id]);
    ApiResponse::success(null, 'Assignment deleted');
}
