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

// Prevent direct access - must be accessed through api/index.php router
if (!defined('API_ACCESS_ALLOWED') || !isset($auth) || !isset($client)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Direct access to this endpoint is not allowed',
        'code' => 403,
        'message' => 'Please use the API router at /api/blocked-dates'
    ]);
    exit;
}

// getBlockedDates()/blockRoomDate()/etc. are defined in config/database.php,
// already loaded by the router before this file is included.

// Get authenticated API client (used in place of $admin_user for created_by tracking)
$admin_user = $client;

// Every block / unblock made through the API is recorded like a staff change (System Logs > Staff activity, and a
// note on each booking that sleeps on the nights), naming the API client. includes/block-log.php.
require_once __DIR__ . '/../includes/block-log.php';
$apiBlockLog = static function (string $op, array $entries, ?string $type = null, ?string $reason = null) use ($pdo, $client): void {
    $who = 'API: ' . (string)($client['client_name'] ?? 'unknown client');
    rh_block_log_change($pdo, $op, $entries, ['block_type' => rh_normalize_block_type($type), 'reason' => rh_normalize_block_reason($reason),
        'user_id' => 0, 'user_name' => $who, 'actor_type' => 'api', 'via' => 'the integration API (' . (string)($client['client_name'] ?? 'unknown client') . ')']);
};

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

/**
 * Look a block up by id. Room-type blocks (blocked_dates) and individual-room blocks
 * (individual_room_blocked_dates) are different tables with their own id sequences, so a
 * bare id can match one row of each. The caller must pass the scope; without it an id that
 * matches both is rejected instead of silently acting on the wrong block.
 */
function findBlockedDateById(int $id, ?string $scope) {
    $matches = [];
    foreach (getBlockedDates(null, null, null) as $bd) {
        if ((int)$bd['id'] !== $id) {
            continue;
        }
        $bdScope = $bd['block_scope'] ?? 'type';
        if ($scope !== null && $scope !== '' && $scope !== $bdScope) {
            continue;
        }
        $matches[] = $bd;
    }
    if (count($matches) > 1) {
        sendError('Ambiguous id: both a room-type block and an individual-room block have this id. Pass scope=type or scope=individual.', 409);
    }
    return $matches[0] ?? null;
}

/** Existing bookings that sleep on the blocked nights (blocks never cancel bookings). */
function blockOverlapWarning(?int $roomTypeId, ?int $individualRoomId, array $dates): array {
    $rows = getBookingsOverlappingBlockDates($roomTypeId, $individualRoomId, $dates);
    return [
        'overlapping_bookings' => array_map(function ($b) {
            return [
                'id' => (int)$b['id'],
                'booking_reference' => $b['booking_reference'],
                'check_in_date' => $b['check_in_date'],
                'check_out_date' => $b['check_out_date'],
                'status' => $b['status']
            ];
        }, $rows)
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

// Creating, changing or removing a block takes rooms on or off sale: the API key needs "rooms.block"
// (the integration equivalent of the staff "Block Rooms" permission). Reading blocks stays open to any key.
if ($method !== 'GET' && !$auth->checkPermission($client, 'rooms.block')) {
    sendError('This API key needs the Block Rooms permission (rooms.block) to block or unblock rooms.', 403);
}

// Handle different HTTP methods
switch ($method) {
    case 'GET':
        // Get blocked dates
        $room_id = isset($_GET['room_id']) ? ($_GET['room_id'] === 'all' ? null : (int)$_GET['room_id']) : null;
        $individual_room_id = isset($_GET['individual_room_id']) ? ($_GET['individual_room_id'] === 'all' ? null : (int)$_GET['individual_room_id']) : null;
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
        
        // Get specific blocked date by ID (scope=type|individual disambiguates)
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $blocked_date = findBlockedDateById($id, $_GET['scope'] ?? null);
            
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

        // Date range: start_date .. end_date are the first and last blocked NIGHT (inclusive);
        // a guest may still check out on the day after end_date. Expanded into a dates list.
        if (!isset($input['block_date']) && !isset($input['dates']) && isset($input['start_date'], $input['end_date'])) {
            $range_dates = rh_block_dates_in_range((string)$input['start_date'], (string)$input['end_date']);
            if (empty($range_dates)) {
                sendError('Invalid date range: start_date must be on or before end_date (Y-m-d)', 422);
            }
            $input['dates'] = $range_dates;
        }

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
                    $apiBlockLog('block', [rh_block_entry($pdo, 'room', null, [$individual_room_id], [$block_date])], $block_type, $reason);
                    $blocked_dates = getIndividualRoomBlockedDates($individual_room_id, $block_date, $block_date);
                    $created_date = !empty($blocked_dates) ? $blocked_dates[0] : null;
                    
                    sendResponse([
                        'success' => true,
                        'message' => 'Individual room date blocked successfully',
                        'data' => $created_date,
                        'warnings' => blockOverlapWarning(null, $individual_room_id, [$block_date])
                    ], 201);
                } else {
                    sendError('Failed to block date', 500);
                }
            } else {
                // Room type blocking
                $room_id = isset($input['room_id']) && $input['room_id'] !== '' ? (int)$input['room_id'] : null;
                $result = blockRoomDate($room_id, $block_date, $block_type, $reason, $created_by);

                if ($result) {
                    $apiBlockLog('block', [rh_block_entry($pdo, $room_id === null ? 'all' : 'type', $room_id, [], [$block_date])], $block_type, $reason);
                    $blocked_dates = getBlockedDates($room_id, $block_date, $block_date);
                    $created_date = !empty($blocked_dates) ? $blocked_dates[0] : null;
                    
                    sendResponse([
                        'success' => true,
                        'message' => 'Room type date blocked successfully',
                        'data' => $created_date,
                        'warnings' => blockOverlapWarning($room_id, null, [$block_date])
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
                    $apiBlockLog('block', [rh_block_entry($pdo, 'room', null, [$individual_room_id], array_filter(array_map(static fn($d) => rh_normalize_block_date((string)$d), $valid_dates)))], $block_type, $reason);
                    sendResponse([
                        'success' => true,
                        'message' => "Successfully blocked {$blocked_count} date(s) for individual room",
                        'data' => [
                            'blocked_count' => $blocked_count,
                            'total_requested' => count($input['dates']),
                            'errors' => $errors
                        ],
                        'warnings' => blockOverlapWarning(null, $individual_room_id, $valid_dates)
                    ], 201);
                } else {
                    sendError('Failed to block dates', 500);
                }
            } else {
                // Room type blocking
                $room_id = isset($input['room_id']) && $input['room_id'] !== '' ? (int)$input['room_id'] : null;
                $blocked_count = blockRoomDates($room_id, $valid_dates, $block_type, $reason, $created_by);

                if ($blocked_count > 0) {
                    $apiBlockLog('block', [rh_block_entry($pdo, $room_id === null ? 'all' : 'type', $room_id, [], array_filter(array_map(static fn($d) => rh_normalize_block_date((string)$d), $valid_dates)))], $block_type, $reason);
                    sendResponse([
                        'success' => true,
                        'message' => "Successfully blocked {$blocked_count} date(s)",
                        'data' => [
                            'blocked_count' => $blocked_count,
                            'total_requested' => count($input['dates']),
                            'errors' => $errors
                        ],
                        'warnings' => blockOverlapWarning($room_id, null, $valid_dates)
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
        
        // Get current blocked date (scope=type|individual disambiguates colliding ids)
        $current_date = findBlockedDateById($id, $_GET['scope'] ?? ($input['block_scope'] ?? null));
        
        if (!$current_date) {
            sendError('Blocked date not found', 404);
        }
        
        $block_scope = $current_date['block_scope'] ?? 'type';
        $block_date = $input['block_date'] ?? $current_date['block_date'];
        $block_type = $input['block_type'] ?? $current_date['block_type'];
        $reason = $input['reason'] ?? $current_date['reason'];
        $created_by = $admin_user['id'];
        
        // Delete the old block and create the new one in ONE transaction: if the new one cannot be saved, the old
        // block stays (before, a failed create left the room open for sale with the old block already gone).
        $oldDate = substr((string)$current_date['block_date'], 0, 10);
        $pdo->beginTransaction();
        try {
            if ($block_scope === 'individual' && !empty($current_date['individual_room_id'])) {
                $individual_room_id = (int)$current_date['individual_room_id'];
                $ok = unblockIndividualRoomDate($individual_room_id, $oldDate)
                    && blockIndividualRoomDate($individual_room_id, $block_date, $block_type, $reason, $created_by);
                $oldEntry = rh_block_entry($pdo, 'room', null, [$individual_room_id], [$oldDate]);
                $newEntry = rh_block_entry($pdo, 'room', null, [$individual_room_id], [(string)rh_normalize_block_date((string)$block_date)]);
            } else {
                $oldTypeId = ($current_date['room_id'] ?? null) === null || $current_date['room_id'] === '' ? null : (int)$current_date['room_id'];
                $room_id = isset($input['room_id']) && $input['room_id'] !== '' ? (int)$input['room_id'] : $oldTypeId;
                $ok = unblockRoomDate($oldTypeId, $oldDate) && blockRoomDate($room_id, $block_date, $block_type, $reason, $created_by);
                $oldEntry = rh_block_entry($pdo, $oldTypeId === null ? 'all' : 'type', $oldTypeId, [], [$oldDate]);
                $newEntry = rh_block_entry($pdo, $room_id === null ? 'all' : 'type', $room_id, [], [(string)rh_normalize_block_date((string)$block_date)]);
            }
            if (!$ok) {
                throw new RuntimeException('block update failed');
            }
            $apiBlockLog('unblock', [$oldEntry]);
            $apiBlockLog('block', [$newEntry], $block_type, $reason);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('api blocked-dates PUT: ' . $e->getMessage());
            sendError('Failed to update blocked date; the old block was kept', 500);
        }
        if ($block_scope === 'individual' && !empty($current_date['individual_room_id'])) {
            $updated_dates = getIndividualRoomBlockedDates((int)$current_date['individual_room_id'], $block_date, $block_date);
        } else {
            $updated_dates = getBlockedDates($room_id, $block_date, $block_date);
        }
        sendResponse([
            'success' => true,
            'message' => 'Blocked date updated successfully',
            'data' => !empty($updated_dates) ? $updated_dates[0] : null
        ]);
        break;
        
    case 'DELETE':
        // Delete blocked date(s)
        
        // Handle single date deletion by ID
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            
            // Get the blocked date (scope=type|individual disambiguates colliding ids)
            $target_date = findBlockedDateById($id, $_GET['scope'] ?? null);
            
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
                $delTypeId = ($target_date['room_id'] ?? null) === null || $target_date['room_id'] === '' ? null : (int)$target_date['room_id'];
                $apiBlockLog('unblock', [$block_scope === 'individual' && !empty($target_date['individual_room_id'])
                    ? rh_block_entry($pdo, 'room', null, [(int)$target_date['individual_room_id']], [$target_date['block_date']])
                    : rh_block_entry($pdo, $delTypeId === null ? 'all' : 'type', $delTypeId, [], [$target_date['block_date']])]);
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
            
            // Only nights that really had a block are recorded as unblocked.
            $delDates = array_values(array_unique(array_filter(array_map(static fn($d) => rh_normalize_block_date((string)$d), $input['dates']))));
            if ($block_scope === 'individual') {
                $individual_room_id = isset($input['individual_room_id']) ? (int)$input['individual_room_id'] : null;
                // This room's OWN blocks only (getIndividualRoomBlockedDates also returns room-type / All rooms rows).
                $hadBlock = [];
                if ($delDates && $individual_room_id) {
                    $hbPh = implode(',', array_fill(0, count($delDates), '?'));
                    $hbSt = $pdo->prepare("SELECT block_date FROM individual_room_blocked_dates WHERE individual_room_id = ? AND block_date IN ($hbPh)");
                    $hbSt->execute(array_merge([(int)$individual_room_id], $delDates));
                    $hadBlock = array_map(static fn($d) => substr((string)$d, 0, 10), $hbSt->fetchAll(PDO::FETCH_COLUMN));
                }
                $unblocked_count = unblockIndividualRoomDates($individual_room_id, $input['dates']);
                $delEntry = rh_block_entry($pdo, 'room', null, [(int)$individual_room_id], array_values(array_intersect($delDates, $hadBlock)));
            } else {
                $room_id = isset($input['room_id']) && $input['room_id'] !== '' ? (int)$input['room_id'] : null;
                $hadBlock = [];
                foreach (getBlockedDates($room_id) as $bdRow) {
                    if (($bdRow['block_scope'] ?? 'type') === 'type' && (($bdRow['room_id'] === null && $room_id === null) || (int)$bdRow['room_id'] === (int)$room_id && $room_id !== null)) {
                        $hadBlock[] = substr((string)$bdRow['block_date'], 0, 10);
                    }
                }
                $unblocked_count = unblockRoomDates($room_id, $input['dates']);
                $delEntry = rh_block_entry($pdo, $room_id === null ? 'all' : 'type', $room_id, [], array_values(array_intersect($delDates, $hadBlock)));
            }

            if ($unblocked_count > 0) {
                $apiBlockLog('unblock', [$delEntry]);
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
