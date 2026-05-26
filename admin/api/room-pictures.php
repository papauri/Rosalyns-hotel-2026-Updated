<?php
/**
 * Room Pictures API
 * Hotel Website - Admin API for managing room gallery images
 * 
 * Endpoints:
 * - GET: Fetch all pictures for a room
 * - POST: Upload a new picture
 * - PUT: Update picture details
 * - DELETE: Delete a picture
 * - PUT (action=set_featured): Set featured image for a room
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set JSON response header
header('Content-Type: application/json');

// Include database configuration
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/permissions.php';

// Start session and enforce admin auth/permission
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_user_id'])) {
    sendError('Authentication required', 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$required_permission = ($method === 'GET') ? 'rooms' : 'media_edit';
if (!hasPermission((int)$_SESSION['admin_user_id'], $required_permission) && !hasPermission((int)$_SESSION['admin_user_id'], 'rooms')) {
    sendError('Access denied', 403);
}

// Helper function to send JSON response
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Helper function to send error response
function sendError($message, $statusCode = 400, $details = null) {
    $response = [
        'success' => false,
        'message' => $message
    ];
    if ($details !== null) {
        $response['details'] = $details;
    }
    sendResponse($response, $statusCode);
}

// Helper function to validate image file
function validateImageFile($file) {
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    // Check if file was uploaded
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return [
            'valid' => false,
            'error' => 'File upload failed: ' . ($file['error'] ?? 'Unknown error')
        ];
    }
    
    // Check file size
    if ($file['size'] > $maxSize) {
        return [
            'valid' => false,
            'error' => 'File size exceeds maximum limit of 5MB'
        ];
    }
    
    // Check file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        return [
            'valid' => false,
            'error' => 'Invalid file type. Allowed types: JPG, JPEG, PNG, WEBP'
        ];
    }
    
    // Check file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions)) {
        return [
            'valid' => false,
            'error' => 'Invalid file extension. Allowed extensions: jpg, jpeg, png, webp'
        ];
    }
    
    // Verify it's actually an image
    if (!getimagesize($file['tmp_name'])) {
        return [
            'valid' => false,
            'error' => 'Uploaded file is not a valid image'
        ];
    }
    
    return [
        'valid' => true,
        'extension' => $extension,
        'mime_type' => $mimeType
    ];
}

/**
 * Sync canonical media mapping for a single room gallery row.
 */
function syncRoomGalleryManagedMediaById(int $pictureId): void {
    global $pdo;

    if (!function_exists('upsertManagedMediaForSource')) {
        return;
    }

    $stmt = $pdo->prepare("SELECT g.id, g.room_id, g.image_url, g.title, g.description, g.display_order, g.is_active, r.name AS room_name FROM gallery g LEFT JOIN rooms r ON r.id = g.room_id WHERE g.id = ? LIMIT 1");
    $stmt->execute([$pictureId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return;
    }

    $mediaUrl = ((int)($row['is_active'] ?? 1) === 1) ? ($row['image_url'] ?? null) : null;

    upsertManagedMediaForSource('gallery', (int)$row['id'], 'image_url', $mediaUrl, [
        'title' => ($row['title'] ?: (($row['room_name'] ?? 'Room') . ' Gallery Image')),
        'description' => $row['description'] ?? null,
        'caption' => $row['description'] ?? null,
        'alt_text' => ($row['title'] ?: (($row['room_name'] ?? 'Room') . ' gallery image')),
        'placement_key' => 'rooms.gallery.image_url',
        'page_slug' => 'room',
        'section_key' => 'room_gallery',
        'entity_type' => 'room',
        'entity_id' => (int)($row['room_id'] ?? 0),
        'display_order' => (int)($row['display_order'] ?? 0),
        'use_case' => 'room_gallery_image',
        'media_type' => 'image',
    ]);
}

/**
 * Sync canonical media mapping for a room featured image.
 */
function syncRoomFeaturedManagedMedia(int $roomId): void {
    global $pdo;

    if (!function_exists('upsertManagedMediaForSource')) {
        return;
    }

    $stmt = $pdo->prepare("SELECT id, name, image_url, short_description, description, display_order FROM rooms WHERE id = ? LIMIT 1");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        return;
    }

    upsertManagedMediaForSource('rooms', (int)$room['id'], 'image_url', $room['image_url'] ?? null, [
        'title' => ($room['name'] ?? 'Room') . ' (Image)',
        'description' => $room['short_description'] ?? ($room['description'] ?? null),
        'caption' => $room['short_description'] ?? null,
        'alt_text' => $room['name'] ?? 'Room image',
        'placement_key' => 'rooms.image_url',
        'page_slug' => 'rooms-gallery',
        'section_key' => 'rooms_collection',
        'entity_type' => 'room',
        'entity_id' => (int)$room['id'],
        'display_order' => (int)($room['display_order'] ?? 0),
        'use_case' => 'featured_image',
        'media_type' => 'image',
    ]);
}

// Get request method (already initialized above)

// Parse request body for PUT/POST requests
$input = [];
if ($method === 'POST' || $method === 'PUT') {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $input = json_decode($rawInput, true) ?? [];
    }
    // Also merge with $_POST for multipart/form-data
    if (!empty($_POST)) {
        $input = array_merge($input, $_POST);
    }
}

try {
    switch ($method) {
        case 'GET':
            // Fetch all pictures for a specific room
            if (!isset($_GET['room_id'])) {
                sendError('room_id parameter is required', 400);
            }
            
            $room_id = (int)$_GET['room_id'];
            
            // Validate room exists
            $stmt = $pdo->prepare("SELECT id, name, image_url FROM rooms WHERE id = ?");
            $stmt->execute([$room_id]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$room) {
                sendError('Room not found', 404);
            }
            
            // Fetch gallery images
            $stmt = $pdo->prepare("
                SELECT id, room_id, image_url, title, description, display_order, is_active
                FROM gallery
                WHERE room_id = ?
                ORDER BY display_order ASC, id ASC
            ");
            $stmt->execute([$room_id]);
            $gallery = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add is_featured field to each gallery image
            $featuredImageUrl = $room['image_url'];
            foreach ($gallery as &$image) {
                $image['is_featured'] = ($image['image_url'] === $featuredImageUrl);
            }
            
            sendResponse([
                'success' => true,
                'data' => [
                    'room' => $room,
                    'gallery' => $gallery
                ]
            ]);
            break;
            
        case 'POST':
            // Upload a new picture
            if (!isset($_FILES['image'])) {
                sendError('No image file uploaded', 400);
            }
            
            if (!isset($_POST['room_id'])) {
                sendError('room_id is required', 400);
            }
            
            $room_id = (int)$_POST['room_id'];
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            
            // Validate room exists
            $stmt = $pdo->prepare("SELECT id FROM rooms WHERE id = ?");
            $stmt->execute([$room_id]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                sendError('Room not found', 404);
            }
            
            // Validate image file
            $validation = validateImageFile($_FILES['image']);
            if (!$validation['valid']) {
                sendError($validation['error'], 400);
            }
            
            // Create upload directory if it doesn't exist
            $uploadDir = __DIR__ . '/../../images/rooms/gallery/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    sendError('Failed to create upload directory', 500);
                }
            }
            
            // Generate unique filename
            $timestamp = time();
            $filename = "room_{$room_id}_gallery_{$timestamp}.{$validation['extension']}";
            $filepath = $uploadDir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
                sendError('Failed to save uploaded file', 500);
            }
            
            // Get next display order
            $stmt = $pdo->prepare("
                SELECT COALESCE(MAX(display_order), 0) + 1 as next_order
                FROM gallery
                WHERE room_id = ?
            ");
            $stmt->execute([$room_id]);
            $nextOrder = $stmt->fetch(PDO::FETCH_ASSOC)['next_order'];
            
            // Insert into gallery table
            $image_url = "images/rooms/gallery/" . $filename;
            $stmt = $pdo->prepare("
                INSERT INTO gallery (room_id, image_url, title, description, display_order, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $room_id,
                $image_url,
                $title,
                $description,
                $nextOrder
            ]);
            
            $picture_id = $pdo->lastInsertId();

            syncRoomGalleryManagedMediaById((int)$picture_id);
            
            sendResponse([
                'success' => true,
                'message' => 'Picture uploaded successfully',
                'data' => [
                    'id' => $picture_id,
                    'image_url' => $image_url,
                    'title' => $title,
                    'description' => $description,
                    'display_order' => $nextOrder,
                    'is_active' => 1
                ]
            ], 201);
            break;
            
        case 'PUT':
            // Handle different PUT actions
            $action = $_GET['action'] ?? '';
            
            if ($action === 'set_featured') {
                // Set featured image for a room
                if (!isset($input['room_id']) || !isset($input['picture_id'])) {
                    sendError('room_id and picture_id are required', 400);
                }
                
                $room_id = (int)$input['room_id'];
                $picture_id = (int)$input['picture_id'];
                
                // Validate room exists
                $stmt = $pdo->prepare("SELECT id FROM rooms WHERE id = ?");
                $stmt->execute([$room_id]);
                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    sendError('Room not found', 404);
                }
                
                // Get gallery image
                $stmt = $pdo->prepare("
                    SELECT image_url
                    FROM gallery
                    WHERE id = ? AND room_id = ?
                ");
                $stmt->execute([$picture_id, $room_id]);
                $galleryImage = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$galleryImage) {
                    sendError('Gallery image not found', 404);
                }
                
                // Update room's featured image
                $stmt = $pdo->prepare("
                    UPDATE rooms
                    SET image_url = ?
                    WHERE id = ?
                ");
                $stmt->execute([$galleryImage['image_url'], $room_id]);

                syncRoomFeaturedManagedMedia($room_id);
                
                sendResponse([
                    'success' => true,
                    'message' => 'Featured image updated successfully'
                ]);
                
            } else {
                // Update picture details
                if (!isset($input['picture_id'])) {
                    sendError('picture_id is required', 400);
                }
                
                $picture_id = (int)$input['picture_id'];
                
                // Build update query dynamically
                $updateFields = [];
                $params = [];
                
                if (isset($input['title'])) {
                    $updateFields[] = 'title = ?';
                    $params[] = $input['title'];
                }
                
                if (isset($input['description'])) {
                    $updateFields[] = 'description = ?';
                    $params[] = $input['description'];
                }
                
                if (isset($input['display_order'])) {
                    $updateFields[] = 'display_order = ?';
                    $params[] = (int)$input['display_order'];
                }
                
                if (isset($input['is_active'])) {
                    $updateFields[] = 'is_active = ?';
                    $params[] = (int)$input['is_active'];
                }
                
                if (empty($updateFields)) {
                    sendError('No fields to update', 400);
                }
                
                $params[] = $picture_id;
                
                // Update gallery record
                $sql = "UPDATE gallery SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                if ($stmt->rowCount() === 0) {
                    sendError('Picture not found or no changes made', 404);
                }
                
                // Fetch updated record
                $stmt = $pdo->prepare("
                    SELECT id, room_id, image_url, title, description, display_order, is_active
                    FROM gallery
                    WHERE id = ?
                ");
                $stmt->execute([$picture_id]);
                $updatedPicture = $stmt->fetch(PDO::FETCH_ASSOC);

                syncRoomGalleryManagedMediaById($picture_id);
                
                sendResponse([
                    'success' => true,
                    'message' => 'Picture updated successfully',
                    'data' => $updatedPicture
                ]);
            }
            break;
            
        case 'DELETE':
            // Delete a picture
            if (!isset($_GET['picture_id'])) {
                sendError('picture_id parameter is required', 400);
            }
            
            $picture_id = (int)$_GET['picture_id'];
            
            // Get picture details before deletion
            $stmt = $pdo->prepare("
                SELECT id, image_url
                FROM gallery
                WHERE id = ?
            ");
            $stmt->execute([$picture_id]);
            $picture = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$picture) {
                sendError('Picture not found', 404);
            }
            
            // Delete physical file
            $filepath = __DIR__ . '/../../' . $picture['image_url'];
            if (file_exists($filepath)) {
                if (!unlink($filepath)) {
                    error_log("Failed to delete file: {$filepath}");
                    // Continue with database deletion even if file deletion fails
                }
            }
            
            // Delete from database
            $stmt = $pdo->prepare("DELETE FROM gallery WHERE id = ?");
            $stmt->execute([$picture_id]);

            if (function_exists('upsertManagedMediaForSource')) {
                upsertManagedMediaForSource('gallery', $picture_id, 'image_url', null, [
                    'placement_key' => 'rooms.gallery.image_url',
                    'page_slug' => 'room',
                    'section_key' => 'room_gallery',
                    'entity_type' => 'room',
                    'entity_id' => null,
                    'use_case' => 'room_gallery_image',
                    'media_type' => 'image',
                ]);
            }
            
            sendResponse([
                'success' => true,
                'message' => 'Picture deleted successfully'
            ]);
            break;
            
        default:
            sendError('Method not allowed', 405);
            break;
    }
    
} catch (PDOException $e) {
    error_log("Database error in room-pictures.php: " . $e->getMessage());
    sendError('Database error occurred', 500, $e->getMessage());
} catch (Exception $e) {
    error_log("Error in room-pictures.php: " . $e->getMessage());
    sendError('An error occurred', 500, $e->getMessage());
}
