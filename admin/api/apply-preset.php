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

requireApiPermission('module_settings');

$preset_key = trim((string)($_POST['preset_key'] ?? ''));
$presets = getBusinessPresets();

if (!isset($presets[$preset_key])) {
    echo json_encode(['success' => false, 'error' => 'Unknown preset.']);
    exit;
}

$preset = $presets[$preset_key];
$locked = ['finance']; // These modules cannot be disabled — always required

try {
    // Ensure table exists by triggering auto-create
    moduleEnabled('bookings');

    $applied_modules = [];
    foreach ($preset['modules'] as $module_key => $enable) {
        if (in_array($module_key, $locked, true)) {
            // Locked modules are always on — skip, don't attempt to change them.
            $applied_modules[$module_key] = true;
            continue;
        }

        $is_enabled = (int)!empty($enable);

        $stmt = $pdo->prepare("UPDATE enabled_modules SET is_enabled = ? WHERE module_key = ?");
        $stmt->execute([$is_enabled, $module_key]);

        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("INSERT INTO enabled_modules (module_key, module_name, is_enabled) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_enabled = ?");
            $stmt->execute([$module_key, $module_key, $is_enabled, $is_enabled]);
        }

        $applied_modules[$module_key] = (bool)$is_enabled;
    }

    // Guest-facing pages that aren't derivable from module flags alone
    // (e.g. POS being on for a gym till doesn't mean "show a restaurant page").
    $front_end = $preset['front_end'] ?? [];
    if (isset($front_end['restaurant_page'])) {
        updateSetting('restaurant_system_enabled', $front_end['restaurant_page'] ? '1' : '0');
    }
    if (isset($front_end['events_page'])) {
        updateSetting('events_system_enabled', $front_end['events_page'] ? '1' : '0');
    }

    // Seed POS starter categories for the business type. Additive-only: a
    // category is inserted only if its slug doesn't already exist — existing
    // catalog rows are never modified or removed. default_station='bar' means
    // products auto-serve at payment and never fire kitchen tickets.
    $seeded_categories = [];
    foreach (($preset['starter_categories'] ?? []) as $i => $starter) {
        $name = trim((string)($starter['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        $chk = $pdo->prepare("SELECT COUNT(*) FROM menu_categories WHERE slug = ?");
        $chk->execute([$slug]);
        if ((int)$chk->fetchColumn() > 0) {
            continue;
        }
        $ins = $pdo->prepare("INSERT INTO menu_categories (name, slug, business_context, description, icon, default_station, sort_order, shows_on_pos, shows_on_room_service, display_order, is_active) VALUES (?, ?, 'retail', '', ?, 'bar', ?, 1, 0, ?, 1)");
        $ins->execute([$name, $slug, (string)($starter['icon'] ?? 'fa-tag'), $i, $i]);
        $seeded_categories[] = $name;
    }

    if (function_exists('rh_log_event')) {
        rh_log_event('admin/module-settings', 'info',
            'Business preset applied: ' . $preset_key,
            ['user' => $user['username'] ?? '', 'user_id' => $user['id'] ?? null, 'modules' => $applied_modules]
        );
    }

    echo json_encode([
        'success' => true,
        'preset_key' => $preset_key,
        'applied_modules' => $applied_modules,
        'seeded_categories' => $seeded_categories,
    ]);
} catch (Throwable $e) {
    error_log('apply-preset: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error — please try again.']);
}
exit;
