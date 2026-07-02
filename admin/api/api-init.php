<?php

/**
 * API Initialization
 * Lightweight initialization for admin API endpoints (no HTML output, no redirects)
 *
 * This file MUST be included by API endpoints BEFORE any output
 *
 * Features:
 * - Secure session management
 * - CSRF token generation
 * - Security headers
 * - Database connection
 * - User data setup
 * - Permission functions
 * - Audit functions
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define admin access constant (for security checks in included files)
define('ADMIN_ACCESS', true);

// Check authentication - return error instead of redirect for API
if (!isset($_SESSION['admin_user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated', 'logs' => []]);
    exit;
}

// Include required files
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/system-logger.php';
require_once __DIR__ . '/../../includes/booking-functions.php';

// Setup user data
$site_name = getSetting('site_name');
$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$current_page = basename($_SERVER['PHP_SELF']);
$csrf_token = generateCsrfToken();

// Load permissions system
require_once __DIR__ . '/../includes/permissions.php';

// Load audit logging functions
require_once __DIR__ . '/../includes/audit-functions.php';

if (!function_exists('api_json_forbidden')) {
    function api_json_forbidden(string $message = 'Permission denied'): void
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
}

if (!function_exists('requireApiPermission')) {
    function requireApiPermission(string $permissionKey): void
    {
        global $user;

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0 || !hasPermission($userId, $permissionKey)) {
            api_json_forbidden();
        }
    }
}

if (!function_exists('requireApiAnyPermission')) {
    function requireApiAnyPermission(array $permissionKeys): void
    {
        global $user;

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            api_json_forbidden();
        }

        foreach ($permissionKeys as $permissionKey) {
            if (is_string($permissionKey) && $permissionKey !== '' && hasPermission($userId, $permissionKey)) {
                return;
            }
        }

        api_json_forbidden();
    }
}

