<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../includes/module-presets.php';
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

requireApiPermission('booking_settings');

$preset_key = trim((string)($_POST['preset_key'] ?? ''));
$presets = getBusinessPresets();

if (!isset($presets[$preset_key])) {
    echo json_encode(['success' => false, 'error' => 'Unknown preset.']);
    exit;
}

$front_end = $presets[$preset_key]['front_end'] ?? [];

try {
    if (isset($front_end['restaurant_page'])) {
        updateSetting('restaurant_system_enabled', $front_end['restaurant_page'] ? '1' : '0');
    }

    if (function_exists('rh_log_event')) {
        rh_log_event('admin/module-settings', 'info',
            'Guest-site pages synced to preset: ' . $preset_key,
            ['user' => $user['username'] ?? '', 'user_id' => $user['id'] ?? null]
        );
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('apply-preset-frontend: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error — please try again.']);
}
exit;
