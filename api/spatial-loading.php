<?php
/**
 * Spatial Loading API
 * Provides tiles and sector data for multi-directional canvas
 * @version 1.0.0
 */

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300'); // 5 minute cache

require_once '../config/database.php';
require_once '../config/base-url.php';

// CORS headers for cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get request parameters
$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? '';
$sector_x = isset($_GET['sector_x']) ? (int)$_GET['sector_x'] : 0;
$sector_y = isset($_GET['sector_y']) ? (int)$_GET['sector_y'] : 0;
$tile_id = $_GET['tile_id'] ?? '';
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 12;

/**
 * Fetch tile data (metadata, images, etc.)
 */
function fetchTileData($pdo, $tile_id, $type) {
    $response = [
        'success' => false,
        'data' => null,
        'error' => null
    ];
    
    try {
        switch ($type) {
            case 'room':
                $stmt = $pdo->prepare("
                    SELECT id, slug, name, description, amenities, 
                           price_per_night, max_occupancy, rooms_available,
                           image_url, badge, is_featured
                    FROM rooms
                    WHERE id = ? AND is_active = 1
                ");
                $stmt->execute([$tile_id]);
                $room = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($room) {
                    $amenities = !empty($room['amenities']) 
                        ? array_map('trim', explode(',', $room['amenities']))
                        : [];
                    
                    $room['amenities'] = $amenities;
                    $room['features'] = getRoomFeatures($pdo, $tile_id);
                    
                    $response['success'] = true;
                    $response['data'] = $room;
                } else {
                    $response['error'] = 'Room not found';
                }
                break;
                
            case 'facility':
                $stmt = $pdo->prepare("
                    SELECT id, name, short_description, long_description,
                           icon_class, page_url, image_url, operating_hours
                    FROM facilities
                    WHERE id = ? AND is_active = 1
                ");
                $stmt->execute([$tile_id]);
                $facility = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($facility) {
                    $response['success'] = true;
                    $response['data'] = $facility;
                } else {
                    $response['error'] = 'Facility not found';
                }
                break;
                
            case 'event':
                $stmt = $pdo->prepare("
                    SELECT id, title, description, event_date, event_time,
                           location, image_url, price
                    FROM events
                    WHERE id = ? AND is_active = 1
                ");
                $stmt->execute([$tile_id]);
                $event = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($event) {
                    $response['success'] = true;
                    $response['data'] = $event;
                } else {
                    $response['error'] = 'Event not found';
                }
                break;
                
            default:
                $response['error'] = 'Invalid type';
        }
    } catch (PDOException $e) {
        error_log("[Spatial Loading API] Tile fetch error: " . $e->getMessage());
        $response['error'] = 'Database error';
    }
    
    return $response;
}

/**
 * Fetch sector data (grid of tiles)
 */
function fetchSectorData($pdo, $sector_x, $sector_y, $type, $limit) {
    $response = [
        'success' => false,
        'sector' => ['x' => $sector_x, 'y' => $sector_y],
        'tiles' => [],
        'has_more' => false,
        'error' => null
    ];
    
    try {
        $offset = ($sector_y * 12) + ($sector_x * $limit);
        
        switch ($type) {
            case 'rooms':
                $stmt = $pdo->prepare("
                    SELECT id, slug, name, short_description, 
                           price_per_night, image_url, badge, 
                           rooms_available, total_rooms, is_featured
                    FROM rooms
                    WHERE is_active = 1
                    ORDER BY display_order ASC, id ASC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$limit, $offset]);
                $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $response['tiles'] = array_map(function($room) {
                    return [
                        'id' => $room['id'],
                        'type' => 'room',
                        'slug' => $room['slug'],
                        'title' => $room['name'],
                        'description' => $room['short_description'],
                        'price' => $room['price_per_night'],
                        'image_url' => $room['image_url'],
                        'badge' => $room['badge'],
                        'available' => $room['rooms_available'],
                        'html' => generateRoomTileHTML($room)
                    ];
                }, $rooms);
                
                // Check if more rooms available
                $countStmt = $pdo->query("SELECT COUNT(*) as total FROM rooms WHERE is_active = 1");
                $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
                $response['has_more'] = ($offset + $limit) < $total;
                
                $response['success'] = true;
                break;
                
            case 'facilities':
                $stmt = $pdo->prepare("
                    SELECT id, name, short_description, icon_class, page_url
                    FROM facilities
                    WHERE is_active = 1
                    ORDER BY display_order ASC, id ASC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$limit, $offset]);
                $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $response['tiles'] = array_map(function($facility) {
                    return [
                        'id' => $facility['id'],
                        'type' => 'facility',
                        'title' => $facility['name'],
                        'description' => $facility['short_description'],
                        'icon' => $facility['icon_class'],
                        'url' => $facility['page_url'],
                        'html' => generateFacilityTileHTML($facility)
                    ];
                }, $facilities);
                
                $response['success'] = true;
                break;
                
            case 'events':
                $stmt = $pdo->prepare("
                    SELECT id, title, description, event_date, 
                           event_time, location, image_url, price
                    FROM events
                    WHERE is_active = 1 
                      AND event_date >= CURDATE()
                    ORDER BY event_date ASC, id ASC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$limit, $offset]);
                $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $response['tiles'] = array_map(function($event) {
                    return [
                        'id' => $event['id'],
                        'type' => 'event',
                        'title' => $event['title'],
                        'description' => $event['description'],
                        'date' => $event['event_date'],
                        'time' => $event['event_time'],
                        'location' => $event['location'],
                        'image_url' => $event['image_url'],
                        'price' => $event['price'],
                        'html' => generateEventTileHTML($event)
                    ];
                }, $events);
                
                $response['success'] = true;
                break;
                
            case 'gallery':
                $stmt = $pdo->prepare("
                    SELECT id, image_path, caption, category
                    FROM hotel_gallery
                    WHERE is_active = 1
                    ORDER BY display_order ASC, id ASC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$limit, $offset]);
                $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $response['tiles'] = array_map(function($image) {
                    return [
                        'id' => $image['id'],
                        'type' => 'gallery',
                        'caption' => $image['caption'],
                        'image_url' => $image['image_path'],
                        'html' => generateGalleryTileHTML($image)
                    ];
                }, $images);
                
                $response['success'] = true;
                break;
                
            default:
                $response['error'] = 'Invalid type';
        }
    } catch (PDOException $e) {
        error_log("[Spatial Loading API] Sector fetch error: " . $e->getMessage());
        $response['error'] = 'Database error';
    }
    
    return $response;
}

/**
 * Get additional room features
 */
function getRoomFeatures($pdo, $room_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT feature_name
            FROM room_features
            WHERE room_id = ?
            ORDER BY display_order ASC
        ");
        $stmt->execute([$room_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Generate room tile HTML
 */
function generateRoomTileHTML($room) {
    $currency_symbol = getSetting('currency_symbol');
    $available = $room['rooms_available'] ?? 0;
    $total = $room['total_rooms'] ?? 1;
    
    $availability_status = $available == 0 ? 'sold-out' : ($available <= 2 ? 'limited' : '');
    $availability_text = $available == 0 ? 'Sold Out' : 
                       ($available <= 2 ? "Only {$available} left" : "{$available} rooms available");
    $availability_icon = $available == 0 ? 'fa-times-circle' : 
                         ($available <= 2 ? 'fa-exclamation-triangle' : 'fa-check-circle');
    
    $amenities = !empty($room['amenities']) ? 
                 explode(',', $room['amenities']) : [];
    $amenities_display = array_slice($amenities, 0, 3);
    
    return "
        <div class='spatial-tile-content'>
            <div class='spatial-tile-image'>
                <img src='{$room['image_url']}' alt='{$room['name']}' 
                     loading='lazy' decoding='async' width='400' height='300'>
                " . ($room['badge'] ? "<span class='spatial-tile-badge'>{$room['badge']}</span>" : "") . "
            </div>
            <div class='spatial-tile-info'>
                <h3 class='spatial-tile-title'>{$room['name']}</h3>
                <div class='spatial-tile-price'>
                    <span class='price-amount'>{$currency_symbol}" . number_format($room['price_per_night'], 0) . "</span>
                    <span class='price-period'>per night</span>
                </div>
                <p class='spatial-tile-description'>{$room['short_description']}</p>
                " . (!empty($amenities_display) ? "
                <div class='spatial-tile-amenities'>
                    " . implode('', array_map(function($amenity) {
                        return "<span class='amenity-tag'>" . htmlspecialchars(trim($amenity)) . "</span>";
                    }, $amenities_display)) . "
                </div>" : "") . "
                <div class='spatial-tile-availability {$availability_status}'>
                    <i class='fas {$availability_icon}'></i>
                    <span>{$availability_text}</span>
                </div>
                <a href='room.php?room=" . urlencode($room['slug']) . "' 
                   class='spatial-tile-cta'>View & Book</a>
            </div>
        </div>
        <div class='spatial-tile-metadata'>
            <!-- Additional content loaded on hover -->
        </div>
    ";
}

/**
 * Generate facility tile HTML
 */
function generateFacilityTileHTML($facility) {
    return "
        <div class='spatial-tile-content spatial-facility-tile'>
            <div class='spatial-facility-icon'>
                <i class='{$facility['icon_class']}'></i>
            </div>
            <div class='spatial-tile-info'>
                <h3 class='spatial-tile-title'>{$facility['name']}</h3>
                <p class='spatial-tile-description'>{$facility['short_description']}</p>
                " . ($facility['page_url'] ? "
                <a href='{$facility['page_url']}' class='spatial-tile-cta'>
                    Learn More <i class='fas fa-arrow-right'></i>
                </a>" : "") . "
            </div>
        </div>
        <div class='spatial-tile-metadata'>
            <!-- Additional content loaded on hover -->
        </div>
    ";
}

/**
 * Generate event tile HTML
 */
function generateEventTileHTML($event) {
    $date = date('F j', strtotime($event['event_date']));
    $time = date('g:i A', strtotime($event['event_time']));
    
    return "
        <div class='spatial-tile-content spatial-event-tile'>
            <div class='spatial-tile-image'>
                <img src='{$event['image_url']}' alt='{$event['title']}' 
                     loading='lazy' decoding='async' width='400' height='300'>
                <div class='spatial-event-date'>
                    <span class='event-date-text'>{$date}</span>
                </div>
            </div>
            <div class='spatial-tile-info'>
                <h3 class='spatial-tile-title'>{$event['title']}</h3>
                <div class='spatial-event-meta'>
                    <span><i class='fas fa-clock'></i> {$time}</span>
                    <span><i class='fas fa-map-marker-alt'></i> {$event['location']}</span>
                </div>
                <p class='spatial-tile-description'>{$event['description']}</p>
                " . ($event['price'] ? "
                <div class='spatial-event-price'>{$event['price']}</div>
                " : "") . "
                <a href='events.php#event-{$event['id']}' class='spatial-tile-cta'>
                    View Details
                </a>
            </div>
        </div>
        <div class='spatial-tile-metadata'>
            <!-- Additional content loaded on hover -->
        </div>
    ";
}

/**
 * Generate gallery tile HTML
 */
function generateGalleryTileHTML($image) {
    return "
        <div class='spatial-tile-content spatial-gallery-tile'>
            <div class='spatial-tile-image'>
                <img src='{$image['image_path']}' alt='{$image['caption']}' 
                     loading='lazy' decoding='async' width='400' height='300'>
            </div>
            " . ($image['caption'] ? "
            <div class='spatial-gallery-caption'>
                {$image['caption']}
            </div>
            " : "") . "
        </div>
        <div class='spatial-tile-metadata'>
            <!-- Additional content loaded on hover -->
        </div>
    ";
}

// ============================================
// ROUTE REQUESTS
// ============================================

try {
    switch ($action) {
        case 'fetch_tile':
            if (empty($tile_id) || empty($type)) {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                exit;
            }
            
            $result = fetchTileData($pdo, $tile_id, $type);
            echo json_encode($result);
            break;
            
        case 'fetch_sector':
            if (empty($type)) {
                echo json_encode(['success' => false, 'error' => 'Missing type']);
                exit;
            }
            
            $result = fetchSectorData($pdo, $sector_x, $sector_y, $type, $limit);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
                'available_actions' => ['fetch_tile', 'fetch_sector']
            ]);
    }
} catch (Exception $e) {
    error_log("[Spatial Loading API] Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Server error'
    ]);
}