<?php
/**
 * Room Types API Endpoint
 * 
 * Endpoints:
 * GET    /api/room-types          - List all room types
 * GET    /api/room-types/:id      - Get single room type
 * POST   /api/room-types          - Create new room type
 * PUT    /api/room-types/:id      - Update room type
 * DELETE /api/room-types/:id      - Delete room type
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
if (preg_match('#^/(\d+)$#', $path, $matches)) {
    $id = (int)$matches[1];
}

try {
    switch ($method) {
        case 'GET':
            if ($id) {
                getRoomType($id);
            } else {
                listRoomTypes();
            }
            break;
            
        case 'POST':
            createRoomType();
            break;
            
        case 'PUT':
            if (!$id) {
                ApiResponse::error('Room type ID required', 400);
            }
            updateRoomType($id);
            break;
            
        case 'DELETE':
            if (!$id) {
                ApiResponse::error('Room type ID required', 400);
            }
            deleteRoomType($id);
            break;
            
        default:
            ApiResponse::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log("Room Types API Error: " . $e->getMessage());
    ApiResponse::error('Internal server error', 500);
}

/**
 * List all room types with individual room counts
 */
function listRoomTypes() {
    global $pdo, $auth, $client;
    
    $isActive = isset($_GET['active']) ? (bool)$_GET['active'] : null;
    $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
    
    $sql = "SELECT 
                rt.id,
                rt.name,
                rt.slug,
                rt.description,
                rt.short_description,
                rt.price_per_night,
                rt.price_single_occupancy,
                rt.price_double_occupancy,
                rt.price_triple_occupancy,
                rt.size_sqm,
                rt.max_guests,
                rt.bed_type,
                rt.amenities,
                rt.image_url,
                rt.badge,
                rt.is_featured,
                rt.is_active,
                rt.display_order,
                COUNT(DISTINCT ir.id) as individual_rooms_count,
                SUM(CASE WHEN ir.status = 'available' THEN 1 ELSE 0 END) as available_count
            FROM rooms rt
            LEFT JOIN individual_rooms ir ON rt.id = ir.room_type_id AND ir.is_active = 1
            WHERE 1=1";
    
    $params = [];
    
    if (!$includeInactive) {
        $sql .= " AND rt.is_active = 1";
    }
    
    $sql .= " GROUP BY rt.id
              ORDER BY rt.display_order ASC, rt.name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $roomTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process amenities JSON
    foreach ($roomTypes as &$type) {
        if ($type['amenities']) {
            $type['amenities'] = json_decode($type['amenities'], true) ?: [];
        } else {
            $type['amenities'] = [];
        }
        
        // Format price
        $currencySymbol = getSetting('currency_symbol');
        $type['price_per_night_formatted'] = $currencySymbol . ' ' . number_format($type['price_per_night'], 0);
    }
    
    ApiResponse::success([
        'room_types' => $roomTypes,
        'count' => count($roomTypes)
    ], 'Room types retrieved successfully');
}

/**
 * Get single room type with individual rooms
 */
function getRoomType($id) {
    global $pdo, $auth, $client;
    
    $stmt = $pdo->prepare("
        SELECT * FROM rooms WHERE id = ?
    ");
    $stmt->execute([$id]);
    $roomType = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$roomType) {
        ApiResponse::error('Room type not found', 404);
    }
    
    // Get individual rooms for this type
    $roomsStmt = $pdo->prepare("
        SELECT 
            id,
            room_number,
            room_name,
            floor,
            status,
            specific_amenities,
            is_active,
            display_order
        FROM individual_rooms
        WHERE room_type_id = ? AND is_active = 1
        ORDER BY display_order ASC, room_number ASC
    ");
    $roomsStmt->execute([$id]);
    $individualRooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process amenities
    if ($roomType['amenities']) {
        $roomType['amenities'] = json_decode($roomType['amenities'], true) ?: [];
    } else {
        $roomType['amenities'] = [];
    }
    
    // Process individual room amenities
    foreach ($individualRooms as &$room) {
        if ($room['specific_amenities']) {
            $room['specific_amenities'] = json_decode($room['specific_amenities'], true) ?: [];
        } else {
            $room['specific_amenities'] = [];
        }
    }
    
    $roomType['individual_rooms'] = $individualRooms;
    $roomType['individual_rooms_count'] = count($individualRooms);
    
    // Format price
    $currencySymbol = getSetting('currency_symbol');
    $roomType['price_per_night_formatted'] = $currencySymbol . ' ' . number_format($roomType['price_per_night'], 0);
    
    ApiResponse::success($roomType, 'Room type retrieved successfully');
}

/**
 * Create new room type
 */
function createRoomType() {
    global $pdo, $user, $auth, $client;
    
    // Check permission
    if (!isset($auth) || !$auth->checkPermission($client, 'rooms.create')) {
        ApiResponse::error('Permission denied: rooms.create', 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        ApiResponse::error('Invalid JSON request body', 400);
    }
    
    // Validate required fields
    $required = ['name', 'price_per_night'];
    $missing = [];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        ApiResponse::validationError(['fields' => 'Missing required fields: ' . implode(', ', $missing)]);
    }
    
    // Generate slug from name if not provided
    if (empty($input['slug'])) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $input['name'])));
        $slug = preg_replace('/-+/', '-', $slug);
        
        // Ensure uniqueness (use rooms table)
        $counter = 1;
        $originalSlug = $slug;
        while (true) {
            $check = $pdo->prepare("SELECT COUNT(*) as count FROM rooms WHERE slug = ?");
            $check->execute([$slug]);
            if ($check->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
                break;
            }
            $slug = $originalSlug . '-' . $counter++;
        }
    } else {
        $slug = $input['slug'];
    }
    
    // Encode amenities as JSON
    $amenities = isset($input['amenities']) ? json_encode($input['amenities']) : null;
    
    $stmt = $pdo->prepare("
        INSERT INTO rooms (
            name, slug, description, short_description,
            price_per_night, price_single_occupancy, price_double_occupancy, price_triple_occupancy,
            size_sqm, max_guests, bed_type, amenities,
            image_url, badge, is_featured, is_active, display_order,
            video_path, video_type
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $input['name'],
        $slug,
        $input['description'] ?? null,
        $input['short_description'] ?? null,
        $input['price_per_night'],
        $input['price_single_occupancy'] ?? null,
        $input['price_double_occupancy'] ?? null,
        $input['price_triple_occupancy'] ?? null,
        $input['size_sqm'] ?? 0,
        $input['max_guests'] ?? 2,
        $input['bed_type'] ?? 'Double',
        $amenities,
        $input['image_url'] ?? null,
        $input['badge'] ?? null,
        isset($input['is_featured']) ? (int)$input['is_featured'] : 0,
        isset($input['is_active']) ? (int)$input['is_active'] : 1,
        $input['display_order'] ?? 0,
        $input['video_path'] ?? null,
        $input['video_type'] ?? null
    ]);
    
    $roomTypeId = $pdo->lastInsertId();
    
    // Clear cache
    require_once __DIR__ . '/../config/cache.php';
    clearRoomCache();
    
    ApiResponse::success([
        'id' => $roomTypeId,
        'slug' => $slug
    ], 'Room type created successfully', 201);
}

/**
 * Update room type
 */
function updateRoomType($id) {
    global $pdo, $user, $auth, $client;
    
    // Check permission
    if (!isset($auth) || !$auth->checkPermission($client, 'rooms.update')) {
        ApiResponse::error('Permission denied: rooms.update', 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        ApiResponse::error('Invalid JSON request body', 400);
    }
    
    // Check if room type exists (use rooms table)
    $check = $pdo->prepare("SELECT id FROM rooms WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetch()) {
        ApiResponse::error('Room type not found', 404);
    }
    
    // Build update query dynamically
    $fields = [];
    $params = [];
    
    $updatable = [
        'name', 'slug', 'description', 'short_description',
        'price_per_night', 'price_single_occupancy', 'price_double_occupancy', 'price_triple_occupancy',
        'size_sqm', 'max_guests', 'bed_type',
        'image_url', 'badge', 'is_featured', 'is_active', 'display_order',
        'video_path', 'video_type'
    ];
    
    foreach ($updatable as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            $params[] = $input[$field];
        }
    }
    
    // Handle amenities separately (JSON)
    if (isset($input['amenities'])) {
        $fields[] = "amenities = ?";
        $params[] = json_encode($input['amenities']);
    }
    
    if (empty($fields)) {
        ApiResponse::error('No fields to update', 400);
    }
    
    $params[] = $id;
    
    $sql = "UPDATE rooms SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Clear cache
    require_once __DIR__ . '/../config/cache.php';
    clearRoomCache();
    
    ApiResponse::success(null, 'Room type updated successfully');
}

/**
 * Delete room type
 */
function deleteRoomType($id) {
    global $pdo, $user, $auth, $client;
    
    // Check permission
    if (!isset($auth) || !$auth->checkPermission($client, 'rooms.delete')) {
        ApiResponse::error('Permission denied: rooms.delete', 403);
    }
    
    // Check if room type exists (use rooms table)
    $check = $pdo->prepare("SELECT id, name FROM rooms WHERE id = ?");
    $check->execute([$id]);
    $roomType = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$roomType) {
        ApiResponse::error('Room type not found', 404);
    }
    
    // Check for existing individual rooms
    $roomsCheck = $pdo->prepare("SELECT COUNT(*) as count FROM individual_rooms WHERE room_type_id = ?");
    $roomsCheck->execute([$id]);
    $roomsCount = $roomsCheck->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($roomsCount > 0) {
        ApiResponse::error("Cannot delete room type with {$roomsCount} individual rooms. Please delete or reassign rooms first.", 400);
    }
    
    // Check for existing bookings
    $bookingsCheck = $pdo->prepare("\n        SELECT COUNT(*) as count\n        FROM bookings b\n        LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id\n        WHERE b.room_id = ? OR ir.room_type_id = ?\n    ");
    $bookingsCheck->execute([$id, $id]);
    $bookingsCount = $bookingsCheck->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($bookingsCount > 0) {
        ApiResponse::error("Cannot delete room type with {$bookingsCount} existing bookings.", 400);
    }
    
    // Delete room type (use rooms table)
    $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    
    // Clear cache
    require_once __DIR__ . '/../config/cache.php';
    clearRoomCache();
    
    ApiResponse::success(null, 'Room type deleted successfully');
}
