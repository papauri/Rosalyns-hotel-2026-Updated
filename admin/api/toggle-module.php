<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api-init.php';
/** @var array $user */
/** @var PDO $pdo */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token invalid. Refresh the page.']);
    exit;
}

requireApiPermission('module_settings');

$allowed = ['bookings', 'housekeeping', 'pos', 'stock', 'conference', 'gym', 'finance', 'website_cms',
            'station_kds', 'station_bds', 'station_cds', 'station_room_service'];
$locked  = ['finance']; // These modules cannot be disabled — always required

// Guest-facing page flags: not rows in enabled_modules, they ride site settings.
// Exposed here so the Module Settings UI can toggle them like any other module.
$front_end_flags = [
    'events_page'     => 'events_system_enabled',
    'restaurant_page' => 'restaurant_system_enabled',
];

$module_key = trim((string)($_POST['module_key'] ?? ''));
$is_enabled = (int)!empty($_POST['is_enabled']);

if (isset($front_end_flags[$module_key])) {
    try {
        updateSetting($front_end_flags[$module_key], $is_enabled ? '1' : '0');
        if (function_exists('rh_log_event')) {
            rh_log_event('admin/module-settings', $is_enabled ? 'info' : 'warning',
                'Guest page ' . ($is_enabled ? 'enabled' : 'disabled') . ': ' . $module_key,
                ['user' => $user['username'] ?? '', 'user_id' => $user['id'] ?? null, 'module' => $module_key]
            );
        }
        echo json_encode(['success' => true, 'module_key' => $module_key, 'is_enabled' => (bool)$is_enabled]);
    } catch (Throwable $e) {
        error_log('toggle-module (front-end flag): ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error — please try again.']);
    }
    exit;
}

if (!in_array($module_key, $allowed, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid module key.']);
    exit;
}

if (!$is_enabled && in_array($module_key, $locked, true)) {
    echo json_encode(['success' => false, 'error' => 'Finance & Accounting cannot be disabled — it is required by all business types.']);
    exit;
}

try {
    // Ensure table exists by triggering auto-create
    moduleEnabled('bookings');

    $stmt = $pdo->prepare("UPDATE enabled_modules SET is_enabled = ? WHERE module_key = ?");
    $stmt->execute([$is_enabled, $module_key]);

    if ($stmt->rowCount() === 0) {
        $stmt = $pdo->prepare("INSERT INTO enabled_modules (module_key, module_name, is_enabled) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_enabled = ?");
        $stmt->execute([$module_key, $module_key, $is_enabled, $is_enabled]);
    }

    if (function_exists('rh_log_event')) {
        rh_log_event('admin/module-settings', $is_enabled ? 'info' : 'warning',
            'Module ' . ($is_enabled ? 'enabled' : 'disabled') . ': ' . $module_key,
            ['user' => $user['username'] ?? '', 'user_id' => $user['id'] ?? null, 'module' => $module_key]
        );
    }

    echo json_encode(['success' => true, 'module_key' => $module_key, 'is_enabled' => (bool)$is_enabled]);
} catch (Throwable $e) {
    error_log('toggle-module: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error — please try again.']);
}
exit;
