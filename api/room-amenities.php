<?php
/**
 * Room Amenities API
 *
 * Endpoints:
 * GET    /api/room-amenities?room_id=    - List amenities for a room
 * POST   /api/room-amenities              - Create amenity (requires room_id, amenity_key)
 * PUT    /api/room-amenities/{id}         - Update amenity
 * DELETE /api/room-amenities/{id}         - Delete amenity
 */

if (!defined('API_ACCESS_ALLOWED')) {
    http_response_code(403);
    exit;
}

global $pdo, $auth, $client;
$method = $_SERVER['REQUEST_METHOD'];
$path   = $_SERVER['PATH_INFO'] ?? '';
$id     = null;

if (preg_match('#^/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
}

switch ($method) {
    case 'GET':
        listAmenities();
        break;
    case 'POST':
        createAmenity();
        break;
    case 'PUT':
        if (!$id) ApiResponse::error('Amenity ID required', 400);
        updateAmenity($id);
        break;
    case 'DELETE':
        if (!$id) ApiResponse::error('Amenity ID required', 400);
        deleteAmenity($id);
        break;
    default:
        ApiResponse::error('Method not allowed', 405);
}

function listAmenities() {
    global $pdo;
    $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
    if (!$roomId) ApiResponse::validationError(['room_id' => 'room_id is required']);

    $stmt = $pdo->prepare("SELECT * FROM individual_room_amenities WHERE individual_room_id = ? ORDER BY display_order ASC, id ASC");
    $stmt->execute([$roomId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ApiResponse::success($rows, 'Amenities fetched');
}

function createAmenity() {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) ApiResponse::error('Invalid JSON body', 400);

    $roomId = (int)($input['individual_room_id'] ?? 0);
    $key    = trim($input['amenity_key'] ?? '');
    $label  = trim($input['amenity_label'] ?? '');

    $errors = [];
    if (!$roomId) $errors['individual_room_id'] = 'individual_room_id is required';
    if (!$key)    $errors['amenity_key'] = 'amenity_key is required';
    if ($errors) ApiResponse::validationError($errors);

    // Ensure room exists
    $chk = $pdo->prepare("SELECT id FROM individual_rooms WHERE id = ? AND is_active = 1");
    $chk->execute([$roomId]);
    if (!$chk->fetch()) ApiResponse::error('Room not found or inactive', 404);

    $stmt = $pdo->prepare("INSERT INTO individual_room_amenities (individual_room_id, amenity_key, amenity_label, amenity_value, is_included, display_order, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $roomId,
        $key,
        $label ?: $key,
        $input['amenity_value'] ?? null,
        isset($input['is_included']) ? (int)$input['is_included'] : 1,
        $input['display_order'] ?? 0,
        $input['notes'] ?? null
    ]);

    ApiResponse::success(['id' => $pdo->lastInsertId()], 'Amenity created', 201);
}

function updateAmenity($id) {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) ApiResponse::error('Invalid JSON body', 400);

    $fields = [];
    $params = [];
    $updatable = ['amenity_key','amenity_label','amenity_value','is_included','display_order','notes'];
    foreach ($updatable as $f) {
        if (array_key_exists($f, $input)) {
            $fields[] = "$f = ?";
            $params[] = $input[$f];
        }
    }
    if (isset($input['individual_room_id'])) {
        $fields[] = "individual_room_id = ?";
        $params[] = (int)$input['individual_room_id'];
    }
    if (!$fields) ApiResponse::error('No fields to update', 400);

    $params[] = $id;
    $sql = "UPDATE individual_room_amenities SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    ApiResponse::success(null, 'Amenity updated');
}

function deleteAmenity($id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM individual_room_amenities WHERE id = ?");
    $stmt->execute([$id]);
    ApiResponse::success(null, 'Amenity deleted');
}
