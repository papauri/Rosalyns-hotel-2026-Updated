<?php
/**
 * Blocked Dates API Endpoint
 * Hotel Website - Room Availability System
 * 
 * This API handles CRUD operations for blocked room dates
 * Endpoints:
 * - GET    /api/blocked-dates.php - Get all blocked dates (with optional filters)
 * - GET    /api/blocked-dates.php?id=X - Get specific blocked date
 * - POST   /api/blocked-dates.php - Create new blocked date(s)
 * - PUT    /api/blocked-dates.php - Update blocked date
 * - DELETE /api/blocked-dates.php - Delete blocked date(s)
 */

// Enable CORS for cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include database configuration
require_once __DIR__ . '/../config/database.php';

// Include API authentication
require_once __DIR__ . '/index.php';

// Initialize API auth
$apiAuth = new ApiAuth($pdo);

// Authenticate request
$authResult = $apiAuth->authenticate();
if (!$authResult['success']) {
    ApiResponse::error($authResult['message'], 401);
    exit;
}

// Get authenticated admin user
$admin_user = $authResult['user'];

/**
 * Send JSON response
 */
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

/**
 * Send error response
 */
function sendError($message, $statusCode = 400, $details = null) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'details' => $details
    ]);
    exit;
}

/**
 * Validate blocked date data
 */
function validateBlockedDateData($data, $isUpdate = false) {
    $errors = [];
    
    // Required fields for creation
    if (!$isUpdate) {
        if (empty($data['block_date'])) {
            $errors['block_date'] = 'Block date is required';
        }
    }
    
    // Validate block_date if provided
    if (!empty($data['block_date'])) {
        try {
            $date = new DateTime($data['block_date']);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            
            if ($date < $today) {
                $errors['block_date'] = 'Block date cannot be in the past';
            }
        } catch (Exception $e) {
            $errors['block_date'] = 'Invalid date format';
        }
    }
    
    // Validate block_type if provided
    if (!empty($data['block_type'])) {
        $valid_types = ['maintenance', 'event', 'manual', 'full'];
        if (!in_array($data['block_type'], $valid_types)) {
            $errors['block_type'] = 'Invalid block type. Must be one of: maintenance, event, manual, full';
        }
    }
    
    // Validate room_id if provided (room type)
    if (isset($data['room_id']) && $data['room_id'] !== '' && $data['room_id'] !== null) {
        if (!is_numeric($data['room_id'])) {
            $errors['room_id'] = 'Room ID must be a number';
        } else {
            // Check if room exists
            global $pdo;
            $stmt = $pdo->prepare("SELECT id FROM rooms WHERE id = ?");
            $stmt->execute([$data['room_id']]);
            if (!$stmt->fetch()) {
                $errors['room_id'] = 'Room not found';
            }
        }
    }
    
    // Validate individual_room_id if provided
    if (isset($data['individual_room_id']) && $data['individual_room_id'] !== '' && $data['individual_room_id'] !== null) {
        if (!is_numeric($data['individual_room_id'])) {
            $errors['individual_room_id'] = 'Individual Room ID must be a number';
        } else {
            // Check if individual room exists
            global $pdo;
            $stmt = $pdo->prepare("SELECT id FROM individual_rooms WHERE id = ?");
            $stmt->execute([$data['individual_room_id']]);
            if (!$stmt->fetch()) {
                $errors['individual_room_id'] = 'Individual room not found';
            }
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Parse request body for POST/PUT
$input = [];
if ($method === 'POST' || $method === 'PUT') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?? [];
}

// Handle different HTTP methods
switch ($method) {
    case 'GET':
        // Get blocked dates
        $room_id = isset($_GET['room_id']) ? ($_GET['room_id'] === 'all' ? null : (int)$_GET['room_id']) : null;
        $individual_room_id = isset($_GET['individual_room_id']) ? ($_GET['individual_room_id'] === 'all' ? null : (int)$_GET['individual_room_id']) : null;
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
        
        // Get specific blocked date by ID
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $blocked_dates = getBlockedDates(null, null, null);
            $blocked_date = null;
            
            foreach ($blocked_dates as $bd) {
                if ($bd['id'] == $id) {
                    $blocked_date = $bd;
                    break;
                }
            }
            
            if ($blocked_date) {
                sendResponse([
                    'success' => true,
                    'data' => $blocked_date
                ]);
            } else {
                sendError('Blocked date not found', 404);
            }
        }
        
        // Get blocked dates with filters (supports both room type and individual room)
        $blocked_dates = getBlockedDates($room_id, $start_date, $end_date, $individual_room_id);
        
        sendResponse([
            'success' => true,
            'data' => $blocked_dates,
            'count' => count($blocked_dates)
        ]);
        break;
        
    case 'POST':
        // Create new blocked date(s)
        
        $block_scope = $input['block_scope'] ?? 'type'; // 'type' or 'individual'
        
        // Handle single date creation
        if (isset($input['block_date'])) {
            // Validate data
            $validation = validateBlockedDateData($input);
            if (!$validation['valid']) {
                sendError('Validation failed', 422, $validation['errors']);
            }
            
            $block_date = $input['block_date'];
            $block_type = $input['block_type'] ?? 'manual';
            $reason = $input['reason'] ?? null;
            $created_by = $admin_user['id'];
            
            if ($block_scope === 'individual') {
                // Individual room blocking
                $individual_room_id = isset($input['individual_room_id']) ? (int)$input['individual_room_id'] : null;
                
                if (empty($individual_room_id)) {
                    sendError('individual_room_id is required for individual room blocks', 422);
                }
                
                $result = blockIndividualRoomDate($individual_room_id, $block_date, $block_type, $reason, $created_by);
                
                if ($result) {
                    $blocked_dates = getIndividualRoomBlockedDates($individual_room_id, $block_date, $block_date);
                    $created_date = !empty($blocked_dates) ? $blocked_dates[0] : null;
                    
                    sendResponse([
                        'success' => true,
                        'message' => 'Individual room date blocked successfully',
                        'data' => $created_date
                    ], 201);
                } else {
                    sendError('Failed to block date', 500);
                }
            } else {
                // Room type blocking
                $room_id = isset($input['room_id']) && $input['room_id'] !== '' ? (int)$input['room_id'] : null;
                $result = blockRoomDate($room_id, $block_date, $block_type, $reason, $created_by);
                
                if ($result) {
                    $blocked_dates = getBlockedDates($room_id, $block_date, $block_date);
                    $created_date = !empty($blocked_dates) ? $blocked_dates[0] : null;
                    
                    sendResponse([
                        'success' => true,
                        'message' => 'Room type date blocked successfully',
                        'data' => $created_date
                    ], 201);
                } else {
                    sendError('Failed to block date', 500);
                }
            }
        }
        
        // Handle multiple dates creation
        elseif (isset($input['dates']) && is_array($input['dates'])) {
            $block_type = $input['block_type'] ?? 'manual';
            $reason = $input['reason'] ?? null;
            $created_by = $admin_user['id'];
            
            // Validate dates
            $valid_dates = [];
            $errors = [];
            
            foreach ($input['dates'] as $date) {
                try {
                    $dt = new DateTime($date);
                    $today = new DateTime();
                    $today->setTime(0, 0, 0);
                    
                    if ($dt >= $today) {
                        $valid_dates[] = $date;
                    } else {
                        $errors[] = "Date {$date} is in the past";
                    }
                } catch (Exception $e) {
                    $errors[] = "Invalid date format: {$date}";
                }
            }
            
            if (empty($valid_dates)) {
                sendError('No valid dates provided', 422, $errors);
            }
            
            if ($block_scope === 'individual') {
                // Individual room blocking
                $individual_room_id = isset($input['individual_room_id']) ? (int)$input['individual_room_id'] : null;
                
                if (empty($individual_room_id)) {
                    sendError('individual_room_id is required for individual room blocks', 422);
                }
                
                $blocked_count = blockIndividualRoomDates($individual_room_id, $valid_dates, $block_type, $reason, $created_by);
                
                if ($blocked_count > 0) {
                    sendResponse([
                        'success' => true,
                        'message' => "Successfully blocked {$blocked_count} date(s) for individual room",
                        'data' => [
                            'blocked_count' => $blocked_count,
                            'total_requested' => count($input['dates']),
                            'errors' => $errors
                        ]
                    ], 201);
                } else {
                    sendError('Failed to block dates', 500);
                }
            } else {
                // Room type blocking
                $room_id = isset($input['room_id']) && $input['room_id'] !== '' ? (int)$input['room_id'] : null;
                $blocked_count = blockRoomDates($room_id, $valid_dates, $block_type, $reason, $created_by);
                
                if ($blocked_count > 0) {
                    sendResponse([
                        'success' => true,
                        'message' => "Successfully blocked {$blocked_count} date(s)",
                        'data' => [
                            'blocked_count' => $blocked_count,
                            'total_requested' => count($input['dates']),
                            'errors' => $errors
                        ]
                    ], 201);
                } else {
                    sendError('Failed to block dates', 500);
                }
            }
        }
        
        else {
            sendError('block_date or dates array is required', 422);
        }
        break;
        
    case 'PUT':
        // Update blocked date
        if (!isset($_GET['id'])) {
            sendError('Blocked date ID is required', 422);
        }
        
        $id = (int)$_GET['id'];
        
        // Validate data
        $validation = validateBlockedDateData($input, true);
        if (!$validation['valid']) {
            sendError('Validation failed', 422, $validation['errors']);
        }
        
        // Get current blocked date
        $current_dates = getBlockedDates(null, null, null);
        $current_date = null;
        
        foreach ($current_dates as $bd) {
            if ($bd['id'] == $id) {
                $current_date = $bd;
                break;
            }
        }
        
        if (!$current_date) {
            sendError('Blocked date not found', 404);
        }
        
        $block_scope = $current_date['block_scope'] ?? 'type';
        $block_date = $input['block_date'] ?? $current_date['block_date'];
        $block_type = $input['block_type'] ?? $current_date['block_type'];
        $reason = $input['reason'] ?? $current_date['reason'];
        $created_by = $admin_user['id'];
        
        // First delete the old one
        if ($block_scope === 'individual' && !empty($current_date['individual_room_id'])) {
            unblockIndividualRoomDate($current_date['individual_room_id'], $current_date['block_date']);
            
            // Then create the new one
            $individual_room_id = $current_date['individual_room_id'];
            $result = blockIndividualRoomDate($individual_room_id, $block_date, $block_type, $reason, $created_by);
            
            if ($result) {
                $updated_dates = getIndividualRoomBlockedDates($individual_room_id, $block_date, $block_date);
                $updated_date = !empty($updated_dates) ? $updated_dates[0] : null;
                
                sendResponse([
                    'success' => true,
                    'message' => 'Blocked date updated successfully',
                    'data' => $updated_date
                ]);
            } else {
                sendError('Failed to update blocked date', 500);
            }
        } else {
            unblockRoomDate($current_date['room_id'], $current_date['block_date']);
            
            // Then create the new one
            $room_id = isset($input['room_id']) && $input['room_id'] !== '' ? (int)$input['room_id'] : $current_date['room_id'];
            $result = blockRoomDate($room_id, $block_date, $block_type, $reason, $created_by);
            
            if ($result) {
                $updated_dates = getBlockedDates($room_id, $block_date, $block_date);
                $updated_date = !empty($updated_dates) ? $updated_dates[0] : null;
                
                sendResponse([
                    'success' => true,
                    'message' => 'Blocked date updated successfully',
                    'data' => $updated_date
                ]);
            } else {
                sendError('Failed to update blocked date', 500);
            }
        }
        break;
        
    case 'DELETE':
        // Delete blocked date(s)
        
        // Handle single date deletion by ID
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            
            // Get the blocked date
            $current_dates = getBlockedDates(null, null, null);
            $target_date = null;
            
            foreach ($current_dates as $bd) {
                if ($bd['id'] == $id) {
                    $target_date = $bd;
                    break;
                }
            }
            
            if (!$target_date) {
                sendError('Blocked date not found', 404);
            }
            
            // Delete the blocked date based on scope
            $block_scope = $target_date['block_scope'] ?? 'type';
            if ($block_scope === 'individual' && !empty($target_date['individual_room_id'])) {
                $result = unblockIndividualRoomDate($target_date['individual_room_id'], $target_date['block_date']);
            } else {
                $result = unblockRoomDate($target_date['room_id'], $target_date['block_date']);
            }
            
            if ($result) {
                sendResponse([
                    'success' => true,
                    'message' => 'Blocked date removed successfully'
                ]);
            } else {
                sendError('Failed to remove blocked date', 500);
            }
        }
        
        // Handle multiple dates deletion
        elseif (isset($input['dates']) && is_array($input['dates'])) {
            $block_scope = $input['block_scope'] ?? 'type';
            
            if ($block_scope === 'individual') {
                $individual_room_id = isset($input['individual_room_id']) ? (int)$input['individual_room_id'] : null;
                $unblocked_count = unblockIndividualRoomDates($individual_room_id, $input['dates']);
            } else {
                $room_id = isset($input['room_id']) && $input['room_id'] !== '' ? (int)$input['room_id'] : null;
                $unblocked_count = unblockRoomDates($room_id, $input['dates']);
            }
            
            if ($unblocked_count > 0) {
                sendResponse([
                    'success' => true,
                    'message' => "Successfully unblocked {$unblocked_count} date(s)",
                    'data' => [
                        'unblocked_count' => $unblocked_count,
                        'total_requested' => count($input['dates'])
                    ]
                ]);
            } else {
                sendError('Failed to unblock dates', 500);
            }
        }
        
        else {
            sendError('ID or dates array is required', 422);
        }
        break;
        
    default:
        sendError('Method not allowed', 405);
        break;
}
