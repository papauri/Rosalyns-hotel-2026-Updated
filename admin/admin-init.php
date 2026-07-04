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
require_once __DIR__ . '/../includes/booking-functions.php';

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
    // Deactivated accounts: hasPermission() returns false for inactive users on
    // every page, so without this check they'd redirect-loop. Kill the session.
    try {
        $_active_stmt = $pdo->prepare("SELECT is_active FROM admin_users WHERE id = ?");
        $_active_stmt->execute([$user['id']]);
        if (!(int)$_active_stmt->fetchColumn()) {
            session_unset();
            session_destroy();
            header('Location: login.php?error=account_disabled');
            exit;
        }
    } catch (Throwable $e) { /* fall through to normal deny handling */ }

    // Station-role staff bounce to their own home screen instead of the
    // dashboard (which their default permission sets don't include).
    $_role_home_map = [
        'restaurant_staff' => 'pos.php',
        'chef'             => 'kds.php',
        'bar_staff'        => 'bds.php',
        'coffee_staff'     => 'cds.php',
        'room_service'     => 'room-service-dashboard.php',
    ];
    $_role_home = $_role_home_map[$user['role'] ?? ''] ?? 'dashboard.php';

    if ($current_page !== $_role_home) {
        header('Location: ' . $_role_home . ($_role_home === 'dashboard.php' ? '?error=access_denied' : ''));
        exit;
    }

    // Loop-safety: the user was denied on their own home page (custom
    // permission overrides, or a role like bar_staff denied 'dashboard'
    // after a redirect here). Render a minimal no-access page — never loop.
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>No Access</title></head>'
        . '<body style="font-family:system-ui,sans-serif;background:#f5f2eb;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;">'
        . '<div style="background:#fff;border:1px solid #d5cfc4;border-radius:6px;padding:36px 40px;max-width:420px;text-align:center;">'
        . '<div style="font-size:2rem;margin-bottom:10px;">&#128274;</div>'
        . '<h1 style="font-size:1.1rem;color:#3e3930;margin:0 0 10px;">No accessible pages</h1>'
        . '<p style="font-size:.9rem;color:#7a6f63;line-height:1.6;margin:0 0 20px;">Your account (' . htmlspecialchars((string)($user['username'] ?? '')) . ') does not currently have permission to view this area. Please contact your administrator.</p>'
        . '<a href="logout.php" style="display:inline-block;background:#8B7355;color:#fff;padding:10px 22px;border-radius:4px;text-decoration:none;font-size:.9rem;">Sign out</a>'
        . '</div></body></html>';
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
        if (!rh_module_key_enabled((string)$_requiredModuleKey)) {
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

