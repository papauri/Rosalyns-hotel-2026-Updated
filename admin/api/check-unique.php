<?php

/**
 * Is this name / number already used? (read-only hint for admin create/edit forms)
 *
 * GET /admin/api/check-unique.php?form=<key>&value=<v>[&exclude_id=<id>][&category=<c>]
 *
 * Lets a form flag a duplicate while the person types. The server still checks again on save
 * (each page's own handler); this only gives the message earlier.
 *
 * Which table and column a form maps to is a FIXED map below - the request only picks a key from
 * it and never supplies a table or column name. Scope values (e.g. the menu category) are bound
 * parameters. The caller needs the same access as the page that owns the form.
 */
require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../../includes/form-validation.php';
/** @var array $user */

header('Content-Type: application/json');

$forms = [
    'menu_food' => [
        'table' => 'food_menu', 'column' => 'item_name', 'page' => 'menu-management.php',
        'scope' => ['category' => 'category'],
        'message' => 'A food item with this name already exists in this category.',
    ],
    'menu_drink' => [
        'table' => 'drink_menu', 'column' => 'item_name', 'page' => 'menu-management.php',
        'scope' => ['category' => 'category'],
        'message' => 'A drink item with this name already exists in this category.',
    ],
    'stock_ingredient' => [
        'table' => 'stock_ingredients', 'column' => 'name', 'page' => 'stock-ingredients.php',
        'message' => 'A stock item with this name already exists (archived items count too).',
    ],
    'stock_supplier_name' => [
        'table' => 'stock_suppliers', 'column' => 'name', 'page' => 'stock-suppliers.php',
        'message' => 'A supplier with this name already exists.',
    ],
    'stock_supplier_email' => [
        'table' => 'stock_suppliers', 'column' => 'email', 'page' => 'stock-suppliers.php', 'lower' => true,
        'message' => 'Another supplier already uses this email address.',
    ],
    'room_number' => [
        'table' => 'individual_rooms', 'column' => 'room_number', 'page' => 'individual-rooms.php',
        'message' => 'This room number is already used by another room.',
    ],
    'room_type_name' => [
        'table' => 'rooms', 'column' => 'name', 'page' => 'room-management.php',
        'message' => 'A room type with this name already exists.',
    ],
    'restaurant_table' => [
        'table' => 'restaurant_tables', 'column' => 'table_number', 'page' => 'restaurant-tables.php',
        'message' => 'Another table already has this name.',
    ],
];

$key = (string)($_GET['form'] ?? '');
if (!isset($forms[$key])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown form']);
    exit;
}
$def = $forms[$key];

if (!rhCanOpenPage((int)$user['id'], $def['page'])) {
    api_json_forbidden();
}

$value = rh_clean_text($_GET['value'] ?? '');
$excludeId = (int)($_GET['exclude_id'] ?? 0);
if ($value === '' || mb_strlen($value) > 255) {
    echo json_encode(['success' => true, 'taken' => false]);
    exit;
}
if (!empty($def['lower'])) {
    $value = mb_strtolower($value);
}

$scope = [];
foreach (($def['scope'] ?? []) as $param => $column) {
    $scopeValue = rh_clean_text($_GET[$param] ?? '');
    if ($scopeValue === '') {
        // The scope (e.g. category) is not chosen yet, so there is nothing to compare against.
        echo json_encode(['success' => true, 'taken' => false]);
        exit;
    }
    $scope[$column] = $scopeValue;
}

try {
    $dup = rh_find_duplicate($pdo, $def['table'], $def['column'], $value, $scope, $excludeId > 0 ? $excludeId : null);
} catch (Throwable $e) {
    // A table that does not exist yet (module not installed) is simply "not taken".
    $dup = null;
}

echo json_encode([
    'success' => true,
    'taken' => (bool)$dup,
    'message' => $dup ? $def['message'] : '',
]);
