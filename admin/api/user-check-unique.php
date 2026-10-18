<?php

/**
 * Is a staff username / email already taken?
 *
 * GET /admin/api/user-check-unique.php?field=email|username&value=<v>[&exclude_id=<id>]
 * Lets the Add / Edit User forms flag a duplicate while the person types. The server still
 * checks again on save (user-management.php); this only gives the message earlier.
 */
require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../../includes/form-validation.php';
/** @var array $user */

header('Content-Type: application/json');

if (!hasPermission($user['id'], 'user_create') && !hasPermission($user['id'], 'user_edit')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$field = (string)($_GET['field'] ?? '');
$value = rh_clean_text($_GET['value'] ?? '');
$excludeId = (int)($_GET['exclude_id'] ?? 0);

if (!in_array($field, ['email', 'username'], true) || $value === '' || strlen($value) > 255) {
    echo json_encode(['success' => true, 'taken' => false]);
    exit;
}
if ($field === 'email') {
    $value = strtolower($value);
}

$dup = rh_find_duplicate($pdo, 'admin_users', $field, $value, [], $excludeId > 0 ? $excludeId : null);
echo json_encode([
    'success' => true,
    'taken' => (bool)$dup,
    'message' => $dup
        ? ($field === 'email'
            ? 'This email already belongs to another user.'
            : 'This username is already taken. Choose a different one.')
        : '',
]);
