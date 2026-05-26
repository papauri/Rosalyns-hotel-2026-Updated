<?php
/**
 * Get user permissions API endpoint
 *
 * GET /admin/api/user-permissions.php?user_id=<id>
 * Returns JSON with user's current permissions
 */
require_once '../admin-init.php';
/** @var array $user */

// Check permission
if (!hasPermission($user['id'], 'user_permissions')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit;
}

// Check if user can manage this target user
if (!canManageUser($user['id'], $user_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Cannot manage this user']);
    exit;
}

// Get user's permissions
$permissions = getUserPermissions($user_id);

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'permissions' => $permissions
]);
