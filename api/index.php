<?php
/**
 * Hotel Booking API
 * RESTful API for external websites to access booking system
 * 
 * Base endpoint: /api/
 * 
 * Endpoints:
 * - GET  /api/rooms           - List available rooms
 * - GET  /api/availability    - Check room availability
 * - POST /api/bookings        - Create a new booking
 * - GET  /api/bookings/{id}   - Get booking status
 * 
 * Authentication: API Key in X-API-Key header
 */

// Enable CORS for external websites
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include database and authentication
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../includes/system-logger.php';

function rh_api_log_current_response(int $responseCode): void {
    if (!empty($GLOBALS['rh_api_usage_logged'])) {
        return;
    }

    $auth = $GLOBALS['rh_api_auth'] ?? null;
    $client = $GLOBALS['rh_api_client'] ?? null;
    if (!$auth instanceof ApiAuth || !is_array($client) || empty($client['id'])) {
        return;
    }

    $responseTime = microtime(true) - (float)($GLOBALS['rh_api_start_time'] ?? microtime(true));
    $auth->logUsage(
        (int)$client['id'],
        (string)($GLOBALS['rh_api_endpoint'] ?? 'unknown'),
        (string)($GLOBALS['rh_api_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'unknown')),
        $responseCode,
        $responseTime
    );
    $GLOBALS['rh_api_usage_logged'] = true;
}


function rh_api_get_header_value(string $name): ?string {
    $target = strtolower($name);

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $headerName => $value) {
                if (strtolower((string)$headerName) === $target && $value !== '') {
                    return (string)$value;
                }
            }
        }
    }

    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey]) && $_SERVER[$serverKey] !== '') {
        return (string)$_SERVER[$serverKey];
    }

    return null;
}

function rh_api_endpoint_from_path(string $path): string {
    $path = '/' . trim($path, '/');

    if ($path === '/api') {
        return '';
    }

    $apiPosition = strpos($path . '/', '/api/');
    if ($apiPosition === false) {
        return trim($path, '/');
    }

    return trim(substr($path, $apiPosition + strlen('/api/')), '/');
}

// API Authentication class
class ApiAuth {
    private PDO $pdo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Authenticate API request
     */
    public function authenticate() {
        $apiKey = $this->getApiKey();
        
        if (!$apiKey) {
            $this->sendError('API key is required', 401);
        }
        
        $client = $this->validateApiKey($apiKey);
        
        if (!$client) {
            $this->sendError('Invalid API key', 401);
        }
        
        // Get rate limit for this key
        $rateLimit = $this->getRateLimit($client['id']);
        
        // Check rate limiting BEFORE allowing request (strict enforcement)
        if (!$this->checkRateLimitStrict($client['id'], $rateLimit)) {
            $this->sendError('Rate limit exceeded. Please try again later.', 429);
        }
        
        // Update usage stats
        $this->updateUsage($client['id']);
        
        // Store rate limit for client info
        $client['rate_limit_per_hour'] = $rateLimit;
        
        return $client;
    }
    
    /**
     * Get rate limit for API key
     */
    private function getRateLimit(int $apiKeyId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT rate_limit_per_hour
                FROM api_keys
                WHERE id = ?
            ");
            $stmt->execute([$apiKeyId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['rate_limit_per_hour'];
        } catch (PDOException $e) {
            error_log("Get Rate Limit Error: " . $e->getMessage());
            return 100; // Default fallback
        }
    }
    
    /**
     * Get API key from request
     */
    private function getApiKey() {
        $headerKey = rh_api_get_header_value('X-API-Key');
        if ($headerKey !== null) {
            return $headerKey;
        }

        if (isset($_GET['api_key']) && $_GET['api_key'] !== '') {
            return (string)$_GET['api_key'];
        }

        return null;
    }
    
    /**
     * Validate API key
     */
    private function validateApiKey(string $apiKey) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, api_key, client_name, client_website, client_email, 
                       permissions, rate_limit_per_hour, is_active, usage_count
                FROM api_keys 
                WHERE is_active = 1
            ");
            $stmt->execute();
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($clients as $client) {
                if (password_verify($apiKey, $client['api_key'])) {
                    // Decode permissions
                    $client['permissions'] = json_decode($client['permissions'], true) ?? [];
                    return $client;
                }
            }
            
            return null;
        } catch (PDOException $e) {
            error_log("API Auth Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check rate limit (strict - prevents exceeding limit by 1)
     * This checks if adding 1 more request would exceed the limit
     */
    private function checkRateLimitStrict(int $apiKeyId, int $rateLimit) {
        try {
            // Get usage in the last hour
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM api_usage_logs
                WHERE api_key_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$apiKeyId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Strict check: current count must be LESS than rate limit
            // This prevents the (limit + 1)th request from being allowed
            return ($result['count'] < $rateLimit);
        } catch (PDOException $e) {
            error_log("Rate Limit Check Error: " . $e->getMessage());
            return true; // Allow on error (fail-open for reliability)
        }
    }
    
    /**
     * Update usage stats
     */
    private function updateUsage(int $apiKeyId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE api_keys 
                SET last_used_at = NOW(), 
                    usage_count = usage_count + 1 
                WHERE id = ?
            ");
            $stmt->execute([$apiKeyId]);
        } catch (PDOException $e) {
            error_log("Update Usage Error: " . $e->getMessage());
        }
    }
    
    /**
     * Log API usage
     */
    public function logUsage(int $apiKeyId, string $endpoint, string $method, int $responseCode, float $responseTime) {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO api_usage_logs 
                (api_key_id, endpoint, method, ip_address, user_agent, response_code, response_time)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $apiKeyId,
                $endpoint,
                $method,
                $ip,
                $userAgent,
                $responseCode,
                $responseTime
            ]);
        } catch (PDOException $e) {
            error_log("API Log Error: " . $e->getMessage());
        }
    }
    
    /**
     * Check permission
     */
    public function checkPermission(array $client, string $permission) {
        return in_array($permission, $client['permissions']);
    }
    
    /**
     * Send error response
     */
    private function sendError(string $message, $code = 400) {
        if (function_exists('rh_log_event')) {
            rh_log_event('api', $code >= 500 ? 'error' : 'warning', 'API authentication failed', ['code' => $code, 'message' => $message]);
        }
        rh_api_log_current_response((int)$code);
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => $code
        ]);
        exit;
    }
}

// API Response helper
class ApiResponse {
    /**
     * Send success response
     */
    public static function success($data = null, $message = 'Success', $code = 200) {
        rh_api_log_current_response((int)$code);
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ]);
        exit;
    }
    
    /**
     * Send error response
     */
    public static function error(string $message, $code = 400, $details = null) {
        if (function_exists('rh_log_event') && $code >= 400) {
            rh_log_event('api', $code >= 500 ? 'error' : 'warning', 'API response error', ['code' => $code, 'message' => $message]);
        }
        rh_api_log_current_response((int)$code);
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'details' => $details,
            'code' => $code,
            'timestamp' => date('c')
        ]);
        exit;
    }
    
    /**
     * Send validation error
     */
    public static function validationError(array $errors) {
        self::error('Validation failed', 422, $errors);
    }
}

// Initialize API
try {
    // Start timing
    $startTime = microtime(true);
    $GLOBALS['rh_api_start_time'] = $startTime;
    
    // Get request method and path
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $endpoint = rh_api_endpoint_from_path($path);
    $GLOBALS['rh_api_method'] = $method;
    $GLOBALS['rh_api_endpoint'] = $endpoint;
    
    // Initialize authentication
    $auth = new ApiAuth($pdo);
    $GLOBALS['rh_api_auth'] = $auth;
    $client = $auth->authenticate();
    $GLOBALS['rh_api_client'] = $client;
    
    // Define constant to allow access to endpoint files
    define('API_ACCESS_ALLOWED', true);
    
    // Route the request
    switch ($endpoint) {
        case 'rooms':
            if ($method === 'GET') {
                require_once __DIR__ . '/rooms.php';
            } else {
                ApiResponse::error('Method not allowed', 405);
            }
            break;
            
        case 'room-types':
        case strpos($endpoint, 'room-types/') === 0:
            require_once __DIR__ . '/room-types.php';
            break;
            
        case 'individual-rooms':
        case strpos($endpoint, 'individual-rooms/') === 0:
            require_once __DIR__ . '/individual-rooms.php';
            break;

        case 'room-amenities':
        case strpos($endpoint, 'room-amenities/') === 0:
            require_once __DIR__ . '/room-amenities.php';
            break;

        case 'room-photos':
        case strpos($endpoint, 'room-photos/') === 0:
            require_once __DIR__ . '/room-photos.php';
            break;

        case 'maintenance-schedules':
        case strpos($endpoint, 'maintenance-schedules/') === 0:
            require_once __DIR__ . '/maintenance-schedules.php';
            break;

        case 'housekeeping':
        case strpos($endpoint, 'housekeeping/') === 0:
            require_once __DIR__ . '/housekeeping.php';
            break;

        case 'blocked-dates':
        case strpos($endpoint, 'blocked-dates/') === 0:
            require_once __DIR__ . '/blocked-dates.php';
            break;

        case 'availability':
            if ($method === 'GET') {
                require_once __DIR__ . '/availability.php';
            } else {
                ApiResponse::error('Method not allowed', 405);
            }
            break;
            
        case 'bookings':
            if ($method === 'POST') {
                require_once __DIR__ . '/bookings.php';
            } elseif ($method === 'GET' && isset($_GET['id'])) {
                require_once __DIR__ . '/booking-details.php';
            } else {
                ApiResponse::error('Method not allowed or missing booking ID', 405);
            }
            break;

        case strpos($endpoint, 'bookings/') === 0:
            if ($method === 'GET') {
                $bookingIdPath = trim(substr($endpoint, strlen('bookings/')), '/');
                if ($bookingIdPath !== '') {
                    // Support path-style booking lookup: /api/bookings/{id}
                    // Keep compatibility with booking-details.php which reads $_GET['id']
                    $_GET['id'] = $bookingIdPath;
                    require_once __DIR__ . '/booking-details.php';
                } else {
                    ApiResponse::error('Method not allowed or missing booking ID', 405);
                }
            } else {
                ApiResponse::error('Method not allowed or missing booking ID', 405);
            }
            break;
            
        case 'payments':
        case strpos($endpoint, 'payments/') === 0:
            require_once __DIR__ . '/payments.php';
            break;
            
        case 'site-settings':
            // Dynamic site settings from database
            if ($method === 'GET') {
                require_once __DIR__ . '/site-settings.php';
            } else {
                ApiResponse::error('Method not allowed', 405);
            }
            break;
            
        case '':
            // API documentation/info
            ApiResponse::success([
                'api' => getSetting('site_name') . ' Booking API',
                'version' => '1.0.0',
                'endpoints' => [
                    'GET /api/rooms' => 'List available rooms',
                    'GET /api/room-types' => 'List room types',
                    'POST /api/room-types' => 'Create room type',
                    'GET /api/room-types/{id}' => 'Get room type details',
                    'PUT /api/room-types/{id}' => 'Update room type',
                    'DELETE /api/room-types/{id}' => 'Delete room type',
                    'GET /api/individual-rooms' => 'List individual rooms',
                    'POST /api/individual-rooms' => 'Create individual room',
                    'GET /api/individual-rooms/{id}' => 'Get individual room details',
                    'PUT /api/individual-rooms/{id}' => 'Update individual room',
                    'PUT /api/individual-rooms/{id}/status' => 'Update room status',
                    'DELETE /api/individual-rooms/{id}' => 'Delete individual room',
                    'GET /api/room-amenities?room_id=' => 'List room amenities',
                    'POST /api/room-amenities' => 'Create room amenity',
                    'PUT /api/room-amenities/{id}' => 'Update room amenity',
                    'DELETE /api/room-amenities/{id}' => 'Delete room amenity',
                    'GET /api/room-photos?room_id=' => 'List room photos',
                    'POST /api/room-photos' => 'Create room photo record',
                    'PUT /api/room-photos/{id}' => 'Update room photo',
                    'DELETE /api/room-photos/{id}' => 'Delete room photo record',
                    'GET /api/maintenance-schedules?room_id=' => 'List maintenance schedules',
                    'POST /api/maintenance-schedules' => 'Create maintenance schedule',
                    'PUT /api/maintenance-schedules/{id}' => 'Update maintenance schedule',
                    'PATCH /api/maintenance-schedules/{id}/complete' => 'Complete maintenance schedule',
                    'DELETE /api/maintenance-schedules/{id}' => 'Delete maintenance schedule',
                    'GET /api/housekeeping?room_id=' => 'List housekeeping assignments',
                    'POST /api/housekeeping' => 'Create housekeeping assignment',
                    'PUT /api/housekeeping/{id}' => 'Update housekeeping assignment',
                    'PUT /api/housekeeping/{id}/status' => 'Update housekeeping status',
                    'DELETE /api/housekeeping/{id}' => 'Delete housekeeping assignment',
                    'GET /api/availability' => 'Check room availability',
                    'POST /api/bookings' => 'Create a new booking',
                    'GET /api/bookings?id={id}' => 'Get booking status',
                    'GET /api/payments' => 'List all payments (with filters)',
                    'POST /api/payments' => 'Create a new payment',
                    'GET /api/payments/{id}' => 'Get payment details',
                    'PUT /api/payments/{id}' => 'Update payment',
                    'DELETE /api/payments/{id}' => 'Delete payment (soft delete)',
                    'GET /api/site-settings' => 'Get dynamic site settings'
                ],
                'authentication' => 'API Key required in X-API-Key header',
                'documentation' => 'Contact admin for full API documentation'
            ]);
            break;
            
        default:
            ApiResponse::error('Endpoint not found', 404);
    }
    
    // Calculate response time
    rh_api_log_current_response(200);
    
} catch (Exception $e) {
    // Log error
    rh_api_log_current_response(500);
    rh_log_event('api', 'error', 'API request failed with exception', ['endpoint' => $endpoint ?? 'unknown', 'error' => $e->getMessage()]);
    
    ApiResponse::error('Internal server error', 500, $e->getMessage());
}
