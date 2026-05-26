<?php
/**
 * Individual Rooms API Endpoint
 * 
 * Endpoints:
 * GET    /api/individual-rooms              - List all individual rooms
 * GET    /api/individual-rooms/:id          - Get single individual room
 * POST   /api/individual-rooms              - Create new individual room
 * PUT    /api/individual-rooms/:id          - Update individual room
 * DELETE /api/individual-rooms/:id          - Delete individual room
 * PUT    /api/individual-rooms/:id/status   - Update room status
 *
 * SECURITY: This file must only be accessed through api/index.php
 */

// Prevent direct access
if (!defined('API_ACCESS_ALLOWED') || !isset($auth) || !isset($client)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Direct access to this endpoint is not allowed',
        'code' => 403
    ]);
    exit;
}

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '';

// Extract ID from path if present
$id = null;
if (preg_match('#^/(\d+)(?:/status)?$#', $path, $matches)) {
    $id = (int)$matches[1];
}

// Check if this is a status update
$isStatusUpdate = (strpos($path, '/status') !== false);

try {
    switch ($method) {
        case 'GET':
            if ($id) {
                getIndividualRoom($id);
            } else {
                listIndividualRooms();
            }
            break;
            
        case 'POST':
            createIndividualRoom();
            break;
            
        case 'PUT':
            if (!$id) {
                ApiResponse::error('Individual room ID required', 400);
            }
            if ($isStatusUpdate) {
                updateRoomStatus($id);
            } else {
                updateIndividualRoom($id);
            }
            break;
            
        case 'DELETE':
            if (!$id) {
                ApiResponse::error('Individual room ID required', 400);
            }
            deleteIndividualRoom($id);
            break;
            
        default:
            ApiResponse::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log("Individual Rooms API Error: " . $e->getMessage());
    ApiResponse::error('Internal server error', 500);
}

/**
 * List all individual rooms with optional filtering
 */
function listIndividualRooms() {
    global $pdo;
    
    $roomTypeId = isset($_GET['room_type_id']) ? (int)$_GET['room_type_id'] : null;
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
    $includeRoomType = isset($_GET['include_room_type']) && $_GET['include_room_type'] === 'true';
    
    $sql = "SELECT 
                ir.id,
                ir.room_type_id,
                ir.room_number,
                ir.room_name,
                ir.floor,
                ir.status,
                ir.notes,
                ir.specific_amenities,
                ir.is_active,
                ir.display_order,
                ir.created_at,
                ir.updated_at";
    
    if ($includeRoomType) {
        $sql .= ",
                rt.name as room_type_name,
                rt.slug as room_type_slug,
                rt.price_per_night,
                rt.image_url as room_type_image";
    }
    
    $sql .= " FROM individual_rooms ir";
    
    if ($includeRoomType) {
        $sql .= " JOIN rooms rt ON ir.room_type_id = rt.id";
    }
    
    $sql .= " WHERE 1=1";
    $params = [];
    
    if ($roomTypeId) {
        $sql .= " AND ir.room_type_id = ?";
        $params[] = $roomTypeId;
    }
    
    if ($status) {
        $sql .= " AND ir.status = ?";
        $params[] = $status;
    }
    
    if (!$includeInactive) {
        $sql .= " AND ir.is_active = 1";
    }
    
    $sql .= " ORDER BY ir.room_type_id, ir.display_order ASC, ir.room_number ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process specific amenities JSON
    foreach ($rooms as &$room) {
        if ($room['specific_amenities']) {
            $room['specific_amenities'] = json_decode($room['specific_amenities'], true) ?: [];
        } else {
            $room['specific_amenities'] = [];
        }
        
        // Get current booking if room is occupied
        if ($room['status'] === 'occupied') {
            $bookingStmt = $pdo->prepare("
                SELECT id, booking_reference, guest_name, check_in_date, check_out_date
                FROM bookings
                WHERE individual_room_id = ?
                AND status IN ('confirmed', 'checked-in')
                AND check_out_date >= CURDATE()
                ORDER BY check_in_date DESC
                LIMIT 1
            ");
            $bookingStmt->execute([$room['id']]);
            $room['current_booking'] = $bookingStmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    ApiResponse::success([
        'individual_rooms' => $rooms,
        'count' => count($rooms)
    ], 'Individual rooms retrieved successfully');
}

/**
 * Get single individual room with details
 */
function getIndividualRoom($id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT 
            ir.*,
            rt.name as room_type_name,
            rt.slug as room_type_slug,
            rt.description as room_type_description,
            rt.price_per_night,
            rt.max_guests,
            rt.amenities as room_type_amenities,
            rt.image_url as room_type_image
        FROM individual_rooms ir
        JOIN rooms rt ON ir.room_type_id = rt.id
        WHERE ir.id = ?
    ");
    $stmt->execute([$id]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$room) {
        ApiResponse::error('Individual room not found', 404);
    }
    
    // Process amenities
    if ($room['specific_amenities']) {
        $room['specific_amenities'] = json_decode($room['specific_amenities'], true) ?: [];
    } else {
        $room['specific_amenities'] = [];
    }
    
    if ($room['room_type_amenities']) {
        $room['room_type_amenities'] = json_decode($room['room_type_amenities'], true) ?: [];
    } else {
        $room['room_type_amenities'] = [];
    }
    
    // Get maintenance log
    $logStmt = $pdo->prepare("
        SELECT 
            rml.*,
            u.username as performed_by_name
        FROM room_maintenance_log rml
        LEFT JOIN admin_users u ON rml.performed_by = u.id
        WHERE rml.individual_room_id = ?
        ORDER BY rml.created_at DESC
        LIMIT 20
    ");
    $logStmt->execute([$id]);
    $room['maintenance_log'] = $logStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get current booking if occupied
    if ($room['status'] === 'occupied') {
        $bookingStmt = $pdo->prepare("
            SELECT id, booking_reference, guest_name, guest_email, 
                   guest_phone, check_in_date, check_out_date, status
            FROM bookings
            WHERE individual_room_id = ?
            AND status IN ('confirmed', 'checked-in')
            AND check_out_date >= CURDATE()
            ORDER BY check_in_date DESC
            LIMIT 1
        ");
        $bookingStmt->execute([$id]);
        $room['current_booking'] = $bookingStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get upcoming bookings
    $upcomingStmt = $pdo->prepare("
        SELECT id, booking_reference, guest_name, check_in_date, check_out_date
        FROM bookings
        WHERE individual_room_id = ?
        AND status IN ('confirmed', 'pending')
        AND check_in_date > CURDATE()
        ORDER BY check_in_date ASC
        LIMIT 5
    ");
    $upcomingStmt->execute([$id]);
    $room['upcoming_bookings'] = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);
    
    ApiResponse::success($room, 'Individual room retrieved successfully');
}

/**
 * Create new individual room
 */
function createIndividualRoom() {
    global $pdo, $user;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        ApiResponse::error('Invalid JSON request body', 400);
    }
    
    // Validate required fields
    $required = ['room_type_id', 'room_number'];
    $missing = [];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        ApiResponse::validationError(['fields' => 'Missing required fields: ' . implode(', ', $missing)]);
    }
    
    // Check if room type exists (canonical rooms table)
    $typeCheck = $pdo->prepare("SELECT id, name FROM rooms WHERE id = ? AND is_active = 1");
    $typeCheck->execute([$input['room_type_id']]);
    $roomType = $typeCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$roomType) {
        ApiResponse::error('Room type not found or inactive', 404);
    }
    
    // Check if room number already exists
    $numberCheck = $pdo->prepare("SELECT COUNT(*) as count FROM individual_rooms WHERE room_number = ?");
    $numberCheck->execute([$input['room_number']]);
    if ($numberCheck->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
        ApiResponse::validationError(['room_number' => 'Room number already exists']);
    }
    
    // Encode specific amenities as JSON
    $amenities = isset($input['specific_amenities']) ? json_encode($input['specific_amenities']) : null;
    
    $stmt = $pdo->prepare("
        INSERT INTO individual_rooms (
            room_type_id, room_number, room_name, floor, status,
            notes, specific_amenities, is_active, display_order
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $input['room_type_id'],
        $input['room_number'],
        $input['room_name'] ?? null,
        $input['floor'] ?? null,
        $input['status'] ?? 'available',
        $input['notes'] ?? null,
        $amenities,
        isset($input['is_active']) ? (int)$input['is_active'] : 1,
        $input['display_order'] ?? 0
    ]);
    
    $roomId = $pdo->lastInsertId();
    
    // Log initial status
    if (isset($user['id'])) {
        $logStmt = $pdo->prepare("
            INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, performed_by)
            VALUES (?, NULL, ?, ?)
        ");
        $logStmt->execute([$roomId, $input['status'] ?? 'available', $user['id']]);
    }
    
    // Clear cache
    require_once __DIR__ . '/../config/cache.php';
    clearRoomCache();
    
    ApiResponse::success([
        'id' => $roomId,
        'room_number' => $input['room_number']
    ], 'Individual room created successfully', 201);
}

/**
 * Update individual room
 */
function updateIndividualRoom($id) {
    global $pdo, $user;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        ApiResponse::error('Invalid JSON request body', 400);
    }
    
    // Check if room exists
    $check = $pdo->prepare("SELECT id, room_number, status FROM individual_rooms WHERE id = ?");
    $check->execute([$id]);
    $room = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$room) {
        ApiResponse::error('Individual room not found', 404);
    }
    
    // Build update query dynamically
    $fields = [];
    $params = [];
    
    $updatable = [
        'room_type_id', 'room_number', 'room_name', 'floor',
        'notes', 'is_active', 'display_order'
    ];
    
    foreach ($updatable as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            $params[] = $input[$field];
        }
    }
    
    // Handle specific amenities separately (JSON)
    if (isset($input['specific_amenities'])) {
        $fields[] = "specific_amenities = ?";
        $params[] = json_encode($input['specific_amenities']);
    }
    
    if (empty($fields)) {
        ApiResponse::error('No fields to update', 400);
    }
    
    $params[] = $id;
    
    $sql = "UPDATE individual_rooms SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Clear cache
    require_once __DIR__ . '/../config/cache.php';
    clearRoomCache();
    
    ApiResponse::success(null, 'Individual room updated successfully');
}

/**
 * Update room status with logging
 */
function updateRoomStatus($id) {
    global $pdo, $user;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        ApiResponse::error('Invalid JSON request body', 400);
    }
    
    if (empty($input['status'])) {
        ApiResponse::validationError(['status' => 'Status is required']);
    }
    
    $validStatuses = ['available', 'occupied', 'maintenance', 'cleaning', 'out_of_order'];
    if (!in_array($input['status'], $validStatuses)) {
        ApiResponse::validationError(['status' => 'Invalid status. Must be one of: ' . implode(', ', $validStatuses)]);
    }
    
    // Get current room data
    $check = $pdo->prepare("SELECT id, status, room_number FROM individual_rooms WHERE id = ?");
    $check->execute([$id]);
    $room = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$room) {
        ApiResponse::error('Individual room not found', 404);
    }
    
    $oldStatus = $room['status'];
    $newStatus = $input['status'];
    
    // Validate status transitions
    $validTransitions = [
        'available' => ['occupied', 'maintenance', 'out_of_order'],
        'occupied' => ['cleaning', 'maintenance', 'available'],
        'cleaning' => ['available', 'maintenance'],
        'maintenance' => ['available', 'out_of_order'],
        'out_of_order' => ['available', 'maintenance']
    ];
    
    if ($oldStatus !== $newStatus && !in_array($newStatus, $validTransitions[$oldStatus] ?? [])) {
        ApiResponse::error("Invalid status transition from {$oldStatus} to {$newStatus}", 400);
    }
    
    // Update status
    $stmt = $pdo->prepare("UPDATE individual_rooms SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $id]);
    
    // Log the status change
    $logStmt = $pdo->prepare("
        INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $logStmt->execute([
        $id,
        $oldStatus,
        $newStatus,
        $input['reason'] ?? null,
        $user['id'] ?? null
    ]);
    
    // Clear cache
    require_once __DIR__ . '/../config/cache.php';
    clearRoomCache();
    
    ApiResponse::success([
        'old_status' => $oldStatus,
        'new_status' => $newStatus
    ], 'Room status updated successfully');
}

/**
 * Delete individual room
 */
function deleteIndividualRoom($id) {
    global $pdo, $user;
    
    // Check if room exists
    $check = $pdo->prepare("SELECT id, room_number, status FROM individual_rooms WHERE id = ?");
    $check->execute([$id]);
    $room = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$room) {
        ApiResponse::error('Individual room not found', 404);
    }
    
    // Check for active bookings
    $bookingsCheck = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM bookings 
        WHERE individual_room_id = ? 
        AND status IN ('pending', 'confirmed', 'checked-in')
        AND check_out_date >= CURDATE()
    ");
    $bookingsCheck->execute([$id]);
    $bookingsCount = $bookingsCheck->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($bookingsCount > 0) {
        ApiResponse::error("Cannot delete room with {$bookingsCount} active bookings. Please cancel or reassign bookings first.", 400);
    }
    
    // Delete room (cascade will handle maintenance log and amenities)
    $stmt = $pdo->prepare("DELETE FROM individual_rooms WHERE id = ?");
    $stmt->execute([$id]);
    
    // Clear cache
    require_once __DIR__ . '/../config/cache.php';
    clearRoomCache();
    
    ApiResponse::success(null, 'Individual room deleted successfully');
}
