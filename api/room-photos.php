<?php
/**
 * Room Photos API
 *
 * Endpoints:
 * GET    /api/room-photos?room_id=    - List photos for a room
 * POST   /api/room-photos              - Create photo record (expects image_path or upload handling upstream)
 * PUT    /api/room-photos/{id}         - Update photo metadata (caption, display_order, is_primary, is_active)
 * DELETE /api/room-photos/{id}         - Delete photo record
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
        listPhotos();
        break;
    case 'POST':
        createPhoto();
        break;
    case 'PUT':
        if (!$id) ApiResponse::error('Photo ID required', 400);
        updatePhoto($id);
        break;
    case 'DELETE':
        if (!$id) ApiResponse::error('Photo ID required', 400);
        deletePhoto($id);
        break;
    default:
        ApiResponse::error('Method not allowed', 405);
}

function listPhotos() {
    global $pdo;
    $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
    if (!$roomId) ApiResponse::validationError(['room_id' => 'room_id is required']);

    $stmt = $pdo->prepare("SELECT * FROM individual_room_photos WHERE individual_room_id = ? AND is_active = 1 ORDER BY display_order ASC, id ASC");
    $stmt->execute([$roomId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ApiResponse::success($rows, 'Photos fetched');
}

function createPhoto() {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) ApiResponse::error('Invalid JSON body', 400);

    $roomId = (int)($input['individual_room_id'] ?? 0);
    $image  = trim($input['image_path'] ?? '');
    $errors = [];
    if (!$roomId) $errors['individual_room_id'] = 'individual_room_id is required';
    if (!$image)  $errors['image_path'] = 'image_path is required';
    if ($errors) ApiResponse::validationError($errors);

    // Ensure room exists
    $chk = $pdo->prepare("SELECT id FROM individual_rooms WHERE id = ? AND is_active = 1");
    $chk->execute([$roomId]);
    if (!$chk->fetch()) ApiResponse::error('Room not found or inactive', 404);

    // If is_primary requested, unset others
    $isPrimary = isset($input['is_primary']) ? (int)$input['is_primary'] : 0;
    if ($isPrimary === 1) {
        $pdo->prepare("UPDATE individual_room_photos SET is_primary = 0 WHERE individual_room_id = ?")->execute([$roomId]);
    }

    $stmt = $pdo->prepare("INSERT INTO individual_room_photos (individual_room_id, image_path, caption, display_order, is_primary, is_active) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $roomId,
        $image,
        $input['caption'] ?? null,
        $input['display_order'] ?? 0,
        $isPrimary,
        isset($input['is_active']) ? (int)$input['is_active'] : 1
    ]);

    ApiResponse::success(['id' => $pdo->lastInsertId()], 'Photo created', 201);
}

function updatePhoto($id) {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) ApiResponse::error('Invalid JSON body', 400);

    $fields = [];
    $params = [];
    $updatable = ['image_path','caption','display_order','is_primary','is_active','individual_room_id'];
    foreach ($updatable as $f) {
        if (array_key_exists($f, $input)) {
            $fields[] = "$f = ?";
            $params[] = $input[$f];
        }
    }
    if (!$fields) ApiResponse::error('No fields to update', 400);

    // Handle primary toggle: if is_primary set to 1, unset others for that room
    if (isset($input['is_primary']) && (int)$input['is_primary'] === 1) {
        $roomId = $input['individual_room_id'] ?? null;
        if (!$roomId) {
            // Fetch room id of this photo
            $r = $pdo->prepare("SELECT individual_room_id FROM individual_room_photos WHERE id = ?");
            $r->execute([$id]);
            $roomId = $r->fetchColumn();
        }
        if ($roomId) {
            $pdo->prepare("UPDATE individual_room_photos SET is_primary = 0 WHERE individual_room_id = ? AND id != ?")->execute([(int)$roomId, $id]);
        }
    }

    $params[] = $id;
    $sql = "UPDATE individual_room_photos SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    ApiResponse::success(null, 'Photo updated');
}

function deletePhoto($id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM individual_room_photos WHERE id = ?");
    $stmt->execute([$id]);
    ApiResponse::success(null, 'Photo deleted');
}
