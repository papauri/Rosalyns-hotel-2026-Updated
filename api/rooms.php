<?php
/**
 * Rooms API Endpoint
 * GET /api/rooms
 *
 * Returns list of available rooms with details
 * Requires permission: rooms.read
 *
 * SECURITY: This file must only be accessed through api/index.php
 * Direct access is blocked to prevent authentication bypass
 */

// Prevent direct access - must be accessed through api/index.php router
if (!defined('API_ACCESS_ALLOWED') || !isset($auth) || !isset($client)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Direct access to this endpoint is not allowed',
        'code' => 403,
        'message' => 'Please use the API router at /api/rooms'
    ]);
    exit;
}

// Check permission
if (!$auth->checkPermission($client, 'rooms.read')) {
    ApiResponse::error('Permission denied: rooms.read', 403);
}

try {
    // Get query parameters
    $isActive = isset($_GET['active']) ? (bool)$_GET['active'] : true;
    $isFeatured = isset($_GET['featured']) ? (bool)$_GET['featured'] : null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    
    // Build query
    $sql = "SELECT 
                id, name, slug, description, short_description,
                price_per_night, size_sqm, max_guests, 
                rooms_available, total_rooms, bed_type,
                image_url, badge, amenities,
                is_featured, is_active, display_order
            FROM rooms 
            WHERE 1=1";
    
    $params = [];
    
    if ($isActive) {
        $sql .= " AND is_active = 1";
    }
    
    if ($isFeatured !== null) {
        $sql .= " AND is_featured = ?";
        $params[] = $isFeatured ? 1 : 0;
    }
    
    $sql .= " ORDER BY display_order ASC, price_per_night ASC";
    
    if ($limit !== null) {
        $sql .= " LIMIT ?";
        $params[] = $limit;
    }
    
    if ($offset > 0) {
        $sql .= " OFFSET ?";
        $params[] = $offset;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process room data
    foreach ($rooms as &$room) {
        // Decode amenities if it's JSON
        if ($room['amenities'] && strpos($room['amenities'], '[') === 0) {
            $room['amenities'] = json_decode($room['amenities'], true);
        } elseif ($room['amenities']) {
            // Convert comma-separated string to array
            $room['amenities'] = array_map('trim', explode(',', $room['amenities']));
        } else {
            $room['amenities'] = [];
        }
        
        // Format price
        $currencySymbol = getSetting('currency_symbol');
        $room['price_per_night_formatted'] = $currencySymbol . ' ' . number_format($room['price_per_night'], 0);
        $room['currency'] = $currencySymbol;
        
        // Get gallery images for each room
        $galleryStmt = $pdo->prepare("
            SELECT image_url, title, description 
            FROM gallery 
            WHERE room_id = ? AND is_active = 1
            ORDER BY display_order ASC
        ");
        $galleryStmt->execute([$room['id']]);
        $room['gallery'] = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM rooms WHERE is_active = 1";
    $countStmt = $pdo->query($countSql);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Prepare response
    $response = [
        'rooms' => $rooms,
        'pagination' => [
            'total' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $limit !== null ? ($offset + count($rooms) < $totalCount) : false
        ],
        'metadata' => [
            'currency' => getSetting('currency_symbol'),
            'currency_code' => getSetting('currency_code'),
            'site_name' => getSetting('site_name')
        ]
    ];
    
    ApiResponse::success($response, 'Rooms retrieved successfully');
    
} catch (PDOException $e) {
    error_log("Rooms API Error: " . $e->getMessage());
    ApiResponse::error('Failed to retrieve rooms', 500);
}