<?php
/**
 * API Endpoint: Get Maintenance Audit Log
 * Returns audit history for a specific maintenance schedule
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to client
ini_set('log_errors', 1);

// Only allow AJAX requests (more lenient check for compatibility)
$requestType = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
if (strtolower($requestType) !== 'xmlhttprequest') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'AJAX only', 'logs' => []]);
    exit;
}

// Include required files
try {
    require_once __DIR__ . '/api-init.php';
} catch (Throwable $e) {
    error_log('get-maintenance-audit.php init error: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server initialization error', 'logs' => []]);
    exit;
}

// Check permissions
if (!function_exists('hasPermission') || !isset($user['id'])) {
    error_log('get-maintenance-audit.php: hasPermission or user not available');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication error', 'logs' => []]);
    exit;
}

if (!hasPermission($user['id'], 'room_maintenance')) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied', 'logs' => []]);
    exit;
}

// Get maintenance ID
$maintenanceId = (int)($_GET['id'] ?? 0);

if ($maintenanceId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid maintenance ID', 'logs' => []]);
    exit;
}

try {
    // Check if function exists
    if (!function_exists('getMaintenanceAuditLog')) {
        error_log('get-maintenance-audit.php: getMaintenanceAuditLog function not found');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Audit function not available', 'logs' => []]);
        exit;
    }
    
    // Get audit log for the maintenance schedule
    $logs = getMaintenanceAuditLog($maintenanceId);
    
    header('Content-Type: application/json');
    echo json_encode(['logs' => $logs, 'error' => null]);
} catch (Throwable $e) {
    error_log('get-maintenance-audit.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to load audit history', 'logs' => []]);
}
