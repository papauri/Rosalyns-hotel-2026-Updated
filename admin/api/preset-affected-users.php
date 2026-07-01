<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../includes/module-presets.php';
/** @var array $user */
/** @var PDO $pdo */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireApiPermission('booking_settings');

$preset_key = trim((string)($_GET['preset_key'] ?? ''));
$presets = getBusinessPresets();

if (!isset($presets[$preset_key])) {
    echo json_encode(['success' => false, 'error' => 'Unknown preset.']);
    exit;
}

$preset_modules = $presets[$preset_key]['modules'];
$locked_modules = ['finance'];

// Modules this preset would turn OFF (locked modules can never be disabled)
$modules_disabled = [];
foreach ($preset_modules as $module_key => $enabled) {
    if (!$enabled && !in_array($module_key, $locked_modules, true)) {
        $modules_disabled[] = $module_key;
    }
}

$affected_permissions = [];
foreach ($modules_disabled as $module_key) {
    foreach (getPermissionsForModule($module_key) as $permKey) {
        $affected_permissions[$permKey] = true;
    }
}
$affected_permissions = array_keys($affected_permissions);

$all_permissions_meta = getAllPermissions();
$affected_users = [];

if (!empty($affected_permissions)) {
    try {
        $stmt = $pdo->query("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 ORDER BY full_name ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            // Admin always retains full access regardless of module state — not "affected".
            if (($row['role'] ?? '') === 'admin') {
                continue;
            }

            $userPerms = getUserPermissions((int)$row['id']);
            $lost = [];
            foreach ($affected_permissions as $permKey) {
                if (!empty($userPerms[$permKey])) {
                    $lost[] = [
                        'key'   => $permKey,
                        'label' => $all_permissions_meta[$permKey]['label'] ?? $permKey,
                    ];
                }
            }

            if (!empty($lost)) {
                $affected_users[] = [
                    'id'        => (int)$row['id'],
                    'username'  => $row['username'],
                    'full_name' => $row['full_name'],
                    'role'      => $row['role'],
                    'permissions_lost' => $lost,
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('preset-affected-users: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error — please try again.']);
        exit;
    }
}

echo json_encode([
    'success' => true,
    'preset_key' => $preset_key,
    'modules_disabled' => $modules_disabled,
    'affected_users' => $affected_users,
]);
exit;
