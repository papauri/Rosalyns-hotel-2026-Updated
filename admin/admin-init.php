<?php

/**
 * Admin Initialization
 * PHP-only initialization for admin pages (no HTML output)
 *
 * This file MUST be included BEFORE any HTML output
 *
 * Features:
 * - Secure session management
 * - CSRF token generation
 * - Security headers
 * - Database connection
 * - User data setup
 */

// Include base URL override (if configured) before auto-detection
$override_file = __DIR__ . '/../config/base-url-override.php';
if (file_exists($override_file)) {
    require_once $override_file;
}

// Include base URL configuration for proper redirects
require_once __DIR__ . '/../config/base-url.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define admin access constant (for security checks in included files)
define('ADMIN_ACCESS', true);

// Check authentication
if (!isset($_SESSION['admin_user_id'])) {
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $requestPath = (string)parse_url($requestUri, PHP_URL_PATH);
    $requestQuery = (string)parse_url($requestUri, PHP_URL_QUERY);
    $requestedFile = basename($requestPath);

    if ($requestedFile !== '' && $requestedFile !== 'login.php') {
        $redirectTarget = $requestedFile . ($requestQuery !== '' ? '?' . $requestQuery : '');
        $_SESSION['admin_redirect_after_login'] = $redirectTarget;
        header('Location: login.php?redirect=' . rawurlencode($redirectTarget));
        exit;
    }

    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/system-logger.php';

$site_name = getSetting('site_name');
$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$current_page = basename($_SERVER['PHP_SELF']);
$csrf_token = generateCsrfToken();

// ---- Permission-based Access Control ----
// Load permissions system and enforce page-level access
require_once __DIR__ . '/includes/permissions.php';

$_required_permission = getPermissionForPage($current_page);
if ($_required_permission !== null && !hasPermission($user['id'], $_required_permission)) {
    // Restaurant staff get bounced to their till instead of the dashboard.
    if (($user['role'] ?? '') === 'restaurant_staff' && $current_page !== 'pos.php') {
        header('Location: pos.php');
        exit;
    }
    // Chefs get bounced to the Kitchen Display.
    if (($user['role'] ?? '') === 'chef' && $current_page !== 'kds.php') {
        header('Location: kds.php');
        exit;
    }
    // User doesn't have access to this page
    header('Location: dashboard.php?error=access_denied');
    exit;
}

// ---- Module-based Access Control ----
// A page may be permission-granted (e.g. via role defaults) yet still belong
// to a module the installation has disabled (e.g. "bookings" on a Bar/Restaurant
// preset). Block direct navigation to such pages, not just hide their nav link.
$_required_module = getModuleForPage($current_page);
if ($_required_module !== null && ($user['role'] ?? '') !== 'admin') {
    $_requiredModuleKeys = is_array($_required_module) ? $_required_module : [$_required_module];
    $_moduleAccessOk = true;
    foreach ($_requiredModuleKeys as $_requiredModuleKey) {
        if (!moduleEnabled((string)$_requiredModuleKey)) {
            $_moduleAccessOk = false;
            break;
        }
    }
    if (!$_moduleAccessOk) {
        header('Location: dashboard.php?error=module_disabled');
        exit;
    }
}

// ---- Audit Functions ----
// Load audit logging functions for housekeeping and maintenance
require_once __DIR__ . '/includes/audit-functions.php';
// ---- Offline replay logging helpers (rh_log_offline_replay, rh_stamp_order_offline) ----
require_once __DIR__ . '/includes/offline-log.php';

// ---- Global formatting helpers ----
if (!function_exists('rh_format_age')) {
    /**
     * Convert a duration in whole minutes to a human-readable string.
     * < 1 min  → "< 1 min"
     * 1–59     → "5 min"
     * 60–1439  → "2h 15m"  (omits minutes when 0)
     * 1440+    → "1d 3h"   (omits hours when 0)
     */
    function rh_format_age(int $minutes): string {
        if ($minutes < 1)    return '< 1 min';
        if ($minutes < 60)   return $minutes . ' min';
        if ($minutes < 1440) {
            $h = (int) floor($minutes / 60);
            $m = $minutes % 60;
            return $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '');
        }
        $d = (int) floor($minutes / 1440);
        $h = (int) floor(($minutes % 1440) / 60);
        return $d . 'd' . ($h > 0 ? ' ' . $h . 'h' : '');
    }
}

