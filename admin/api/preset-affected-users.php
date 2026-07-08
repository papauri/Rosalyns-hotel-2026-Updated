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

requireApiPermission('module_settings');

$preset_key = trim((string)($_GET['preset_key'] ?? ''));
$presets = getBusinessPresets();

if (!isset($presets[$preset_key])) {
    echo json_encode(['success' => false, 'error' => 'Unknown preset.']);
    exit;
}

$preset_modules = $presets[$preset_key]['modules'];
$locked_modules = ['finance'];

// Diff the preset against the CURRENT module state so the dialog shows what
// actually changes — not just everything the preset sets to 0.
$modules_disabled = []; // preset turns OFF something currently on
$modules_enabled  = []; // preset turns ON something currently off
foreach ($preset_modules as $module_key => $enabled) {
    if (in_array($module_key, $locked_modules, true)) {
        continue;
    }
    $currently_on = moduleEnabled($module_key);
    if (!$enabled && $currently_on) {
        $modules_disabled[] = $module_key;
    } elseif ($enabled && !$currently_on) {
        $modules_enabled[] = $module_key;
    }
}

// The guest-facing restaurant flag rides alongside modules (front_end key)
$preset_restaurant = (bool)($presets[$preset_key]['front_end']['restaurant_page'] ?? true);
$current_restaurant = !function_exists('isRestaurantEnabled') || isRestaurantEnabled();
if (!$preset_restaurant && $current_restaurant) {
    $modules_disabled[] = 'restaurant_page';
} elseif ($preset_restaurant && !$current_restaurant) {
    $modules_enabled[] = 'restaurant_page';
}

// Same for the guest events page flag
$preset_events = (bool)($presets[$preset_key]['front_end']['events_page'] ?? true);
$current_events = !function_exists('isEventsEnabled') || isEventsEnabled();
if (!$preset_events && $current_events) {
    $modules_disabled[] = 'events_page';
} elseif ($preset_events && !$current_events) {
    $modules_enabled[] = 'events_page';
}

// Human-readable admin-menu / guest-site areas per module key, so the dialog
// can say exactly which menus appear or disappear. Mirrors admin-header.php.
$menu_area_map = [
    'bookings'             => 'Bookings, Calendar, Blocked Dates, Rooms & Room Maintenance, Rate Plans/Packages + guest Rooms & Booking pages',
    'housekeeping'         => 'Housekeeping',
    'pos'                  => 'POS Till, Stations group, Menu/Products, Deals, Offline Log, POS Accounting',
    'stock'                => 'Stock group (Dashboard, Ingredients, Batches, Orders, Counts, Wastage, Reports)',
    'conference'           => 'Conference Rooms + guest Conference page',
    'gym'                  => 'Gym Packages & Gym Inquiries + guest Gym page',
    'website_cms'          => 'Gallery, Media Portal, Events, Reviews, Contact Inquiries, Footer & Page Management',
    'station_kds'          => 'Kitchen Display (KDS)',
    'station_bds'          => 'Bar Display (BDS)',
    'station_cds'          => 'Coffee Bar Display (CDS)',
    'station_room_service' => 'Room Service station',
    'restaurant_page'      => 'Restaurant Tables, Recipes, Station Hours/Reports + guest Restaurant page',
    'events_page'          => 'Events Management & Event Inquiries + guest Events page',
];
$menus_removed = array_values(array_filter(array_map(fn($k) => $menu_area_map[$k] ?? null, $modules_disabled)));
$menus_added   = array_values(array_filter(array_map(fn($k) => $menu_area_map[$k] ?? null, $modules_enabled)));

// restaurant_page / events_page aren't real modules — strip before permission lookups below
$modules_disabled_real = array_values(array_diff($modules_disabled, ['restaurant_page', 'events_page']));

$affected_permissions = [];
foreach ($modules_disabled_real as $module_key) {
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
    'modules_enabled' => $modules_enabled,
    'menus_removed' => $menus_removed,
    'menus_added' => $menus_added,
    'affected_users' => $affected_users,
]);
exit;
