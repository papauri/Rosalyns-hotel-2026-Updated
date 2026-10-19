<?php
/**
 * User Management
 * Comprehensive admin user and permission management
 *
 * @version 2.0.0
 */
require_once 'admin-init.php';
require_once __DIR__ . '/includes/staff-invite-lib.php';
require_once __DIR__ . '/../includes/form-validation.php';
/** @var array $user */
/** @var string $csrf_token */

$csrf_token = $csrf_token ?? generateCsrfToken();

// Require access to user management module
if (!hasPermission($user['id'], 'user_management')) {
    rhDenyAndRedirectHome((int)$_SESSION['admin_user_id'], (string)($_SESSION['admin_role'] ?? ''), basename($_SERVER['PHP_SELF']));
    exit;
}

/** The reason part of a failed send, without the layers of "Invitation email failed: Failed to send email:". */
function rh_invite_failure_reason(string $message): string
{
    $m = trim(preg_replace('/^(Invitation email failed:\s*)?(Failed to send email:\s*)?/i', '', $message));
    return $m !== '' ? rtrim($m, '. ') . '.' : 'unknown error.';
}

$site_name = getSetting('site_name');
$success_msg = '';
$error_msg = '';
$open_permissions_for = 0; // new user whose invitation waits for "Save & send invitation"
$action = '';

// Get all roles for use throughout the page
$all_roles = getAllRoles();

// ── Preset relevance ────────────────────────────────────────────────────────
// Staff roles only make sense when their module is on for this business
// preset (a supermarket doesn't hire a receptionist or a chef). Role CHOICES
// (create dropdown, role-template cards, filter buttons) are filtered to
// relevant roles; EXISTING users always keep displaying whatever role they
// hold — validation and the edit dropdown still accept every role.
$rh_role_module_map = [
    'receptionist'     => ['bookings'],
    'housekeeping'     => ['housekeeping'],
    'restaurant_staff' => ['pos'],
    'chef'             => ['pos', 'station_kds'],
    'bar_staff'        => ['pos', 'station_bds'],
    'coffee_staff'     => ['pos', 'station_cds'],
    'room_service'     => ['pos', 'station_room_service'],
    'gym_staff'        => ['gym'],
    'conference_staff' => ['conference'],
    // admin / manager / accountant / viewer: every business needs them.
];
$rh_role_is_relevant = static function (string $roleKey) use ($rh_role_module_map): bool {
    if (!isset($rh_role_module_map[$roleKey])) {
        return true;
    }
    if (!function_exists('moduleEnabled')) {
        return true;
    }
    foreach ($rh_role_module_map[$roleKey] as $mk) {
        if (!moduleEnabled($mk)) {
            return false;
        }
    }
    return true;
};
$relevant_roles = array_filter($all_roles, static fn($k) => $rh_role_is_relevant((string)$k), ARRAY_FILTER_USE_KEY);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Finish the whole request even if the browser gives up while the mail server is slow.
    ignore_user_abort(true);
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error_msg = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ---- ADD NEW USER ----
        if ($action === 'add_user') {
            if (!hasPermission($user['id'], 'user_create')) {
                $error_msg = 'You do not have permission to create users.';
            } else {
                $username = rh_clean_text($_POST['username'] ?? '');
                $email = strtolower(rh_clean_text($_POST['email'] ?? ''));
                $full_name = rh_clean_text($_POST['full_name'] ?? '');
                $role = (string)($_POST['role'] ?? '');
                $password = $_POST['password'] ?? '';
                $send_welcome = !empty($_POST['send_welcome']);
                // Hold the invitation until permissions are saved (admins have every permission,
                // so there is nothing to choose for them).
                $customize_first = $send_welcome && !empty($_POST['customize_first'])
                    && $role !== 'admin' && hasPermission($user['id'], 'user_permissions');

                if (empty($username) || empty($email) || empty($full_name)) {
                    $error_msg = 'Name, username and email are required.';
                } elseif ($password === '' && !$send_welcome) {
                    $error_msg = 'Set a password, or tick "Send invitation" so they choose their own.';
                } elseif ($password !== '' && strlen($password) < 8) {
                    $error_msg = 'Password must be at least 8 characters.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                    $error_msg = 'Please enter a valid email address.';
                } elseif (strlen($username) > 100 || strlen($full_name) > 255) {
                    $error_msg = 'Username (max 100) or full name (max 255) is too long.';
                } elseif (strlen($username) < 3 || preg_match('/\s/', $username)) {
                    $error_msg = 'Username must be at least 3 characters with no spaces.';
                } elseif (!isset($all_roles[$role])) {
                    $error_msg = 'Choose a role for this user.';
                } else {
                    // Check for duplicate username/email
                    // (case-insensitive, under a lock so a double-click cannot create two)
                    $dup_msg = null;
                    $new_user_id = 0;
                    try {
                        rh_with_create_lock($pdo, 'admin_user', function () use ($pdo, $username, $email, $full_name, $role, $password, &$dup_msg, &$new_user_id) {
                            $dupU = rh_find_duplicate($pdo, 'admin_users', 'username', $username);
                            $dupE = rh_find_duplicate($pdo, 'admin_users', 'email', $email);
                            if ($dupU) {
                                $dup_msg = 'The username "' . $dupU['value'] . '" is already taken. Choose a different username.';
                                return;
                            }
                            if ($dupE) {
                                $dup_msg = 'The email "' . $dupE['value'] . '" already belongs to another user.';
                                return;
                            }
                            // No password set = nobody can sign in until the invitation is accepted.
                            $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : rh_staff_invite_placeholder_hash();
                            $stmt = $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                            $stmt->execute([$username, $email, $hash, $full_name, $role]);
                            $new_user_id = (int)$pdo->lastInsertId();
                        });
                    } catch (RuntimeException $e) {
                        $dup_msg = $e->getMessage();
                    }
                    if ($dup_msg !== null) {
                        $error_msg = $dup_msg;
                    } else {

                        // Log the action
                        logActivity($user['id'], 'user_created', "Created user '{$username}' ({$full_name}) with role '{$role}'");

                        $success_msg = "User '{$full_name}' created successfully.";

                        // Invitation: a one-time link to choose a password (no password is emailed).
                        if ($customize_first) {
                            $open_permissions_for = (int)$new_user_id;
                            $success_msg .= ' Choose their permissions, then press "Save & send invitation".';
                        } elseif ($send_welcome) {
                            $invite = rh_staff_invite_send($pdo, (int)$new_user_id, (string)($user['full_name'] ?? 'An administrator'));
                            if (!empty($invite['success'])) {
                                logActivity($user['id'], 'user_invited', "Sent invitation to '{$username}' ({$email})");
                                $success_msg .= ' Invitation sent to ' . htmlspecialchars($email) . ' (link valid ' . RH_STAFF_INVITE_HOURS . ' h).';
                            } else {
                                error_log('Invitation failed for ' . $email . ': ' . $invite['message']);
                                $success_msg .= ' The email could not be sent: ' . rh_invite_failure_reason((string)$invite['message']) . ' Use "Resend invitation" once that is fixed.';
                            }
                        }
                    }
                }
            }
        }

        // ---- UPDATE USER ----
        elseif ($action === 'update_user') {
            if (!hasPermission($user['id'], 'user_edit')) {
                $error_msg = 'You do not have permission to edit users.';
            } else {
                $uid = (int)($_POST['user_id'] ?? 0);
                $full_name = rh_clean_text($_POST['full_name'] ?? '');
                $email = strtolower(rh_clean_text($_POST['email'] ?? ''));
                $role = (string)($_POST['role'] ?? 'receptionist');
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $new_password = $_POST['new_password'] ?? '';

                // Check if user can manage this target user
                if (!canManageUser($user['id'], $uid)) {
                    $error_msg = 'You cannot edit this user.';
                } elseif ($uid <= 0 || empty($full_name) || empty($email)) {
                    $error_msg = 'Full name and email are required.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255 || strlen($full_name) > 255) {
                    $error_msg = 'Please enter a valid email address and a full name under 255 characters.';
                } elseif (!isset($all_roles[$role])) {
                    $error_msg = 'Invalid role selected.';
                } else {
                    // Check email uniqueness (excluding current user)
                    $dupE = rh_find_duplicate($pdo, 'admin_users', 'email', $email, [], $uid);
                    if ($dupE) {
                        $error_msg = 'The email "' . $dupE['value'] . '" already belongs to another user.';
                    } else {
                        // Check if this is the last admin
                        $current_role_stmt = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
                        $current_role_stmt->execute([$uid]);
                        $current_role = $current_role_stmt->fetchColumn();

                        $target_active_stmt = $pdo->prepare("SELECT is_active FROM admin_users WHERE id = ?");
                        $target_active_stmt->execute([$uid]);
                        $target_was_active = (int)$target_active_stmt->fetchColumn() === 1;
                        if ($current_role === 'admin' && $target_was_active && ($role !== 'admin' || !$is_active)) {
                            $admin_count = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                            if ($admin_count <= 1) {
                                $error_msg = 'Cannot change role or deactivate: this is the last active admin.';
                            }
                        }

                        if (!$error_msg) {
                            if (!empty($new_password)) {
                                if (strlen($new_password) < 8) {
                                    $error_msg = 'Password must be at least 8 characters.';
                                } else {
                                    $hash = password_hash($new_password, PASSWORD_DEFAULT);
                                    $stmt = $pdo->prepare("UPDATE admin_users SET full_name = ?, email = ?, role = ?, is_active = ?, password_hash = ? WHERE id = ?");
                                    $stmt->execute([$full_name, $email, $role, $is_active, $hash, $uid]);
                                    $success_msg = "User updated successfully (including password).";
                                }
                            } else {
                                $stmt = $pdo->prepare("UPDATE admin_users SET full_name = ?, email = ?, role = ?, is_active = ? WHERE id = ?");
                                $stmt->execute([$full_name, $email, $role, $is_active, $uid]);
                                $success_msg = "User updated successfully.";
                            }

                            // Reset permissions to role defaults if role changed
                            if ($current_role !== $role && $role !== 'admin') {
                                resetUserPermissionsToDefault($uid, $user['id']);
                                $success_msg .= ' Permissions reset to role defaults.';
                            }

                            logActivity($user['id'], 'user_updated', "Updated user ID {$uid}");
                        }
                    }
                }
            }
        }

        // ---- SAVE PERMISSIONS ----
        elseif ($action === 'save_permissions') {
            if (!hasPermission($user['id'], 'user_permissions')) {
                $error_msg = 'You do not have permission to modify user permissions.';
            } else {
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($uid <= 0) {
                    $error_msg = 'Invalid user.';
                } elseif (!canManageUser($user['id'], $uid)) {
                    $error_msg = 'You cannot modify this user\'s permissions.';
                } else {
                    // Ensure not editing admin's permissions
                    $check_role = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
                    $check_role->execute([$uid]);
                    $target_role = $check_role->fetchColumn();

                    if ($target_role === 'admin') {
                        $error_msg = 'Cannot modify admin permissions.';
                    } else {
                        $all_perms = getAllPermissions();
                        $granted = $_POST['permissions'] ?? [];
                        $perms_to_set = [];

                        foreach ($all_perms as $key => $info) {
                            if ($key === 'user_management') continue; // Admin-only
                            // Prevent privilege escalation: non-admins cannot grant perms they don't hold
                            if ($user['role'] !== 'admin' && !hasPermission($user['id'], $key)) {
                                continue;
                            }
                            $perms_to_set[$key] = in_array($key, $granted);
                        }

                        if (setUserPermissions($uid, $perms_to_set, $user['id'])) {
                            $success_msg = "Permissions updated successfully.";
                            // "Save & send invitation": the invite goes out only once the
                            // permissions above are stored, so the first sign-in already has them.
                            if (!empty($_POST['send_invite_after']) && hasPermission($user['id'], 'user_edit')) {
                                $invite = rh_staff_invite_send($pdo, $uid, (string)($user['full_name'] ?? 'An administrator'));
                                if (!empty($invite['success'])) {
                                    logActivity($user['id'], 'user_invited', "Sent invitation to user #{$uid} after setting permissions");
                                    $success_msg .= ' ' . $invite['message'] . ' (link valid ' . RH_STAFF_INVITE_HOURS . ' h).';
                                } else {
                                    error_log('Invitation failed for user #' . $uid . ': ' . $invite['message']);
                                    $success_msg = '';
                                    $error_msg = 'Permissions saved. The invitation email could not be sent: ' . rh_invite_failure_reason((string)$invite['message']) . ' Use "Resend invitation" once that is fixed.';
                                }
                            }
                        } else {
                            $error_msg = "Failed to update permissions.";
                        }
                    }
                }
            }
        }

        // ---- RESET PERMISSIONS ----
        elseif ($action === 'reset_permissions') {
            if (!hasPermission($user['id'], 'user_permissions')) {
                $error_msg = 'You do not have permission to modify user permissions.';
            } else {
                $uid = (int)($_POST['user_id'] ?? 0);

                if (!canManageUser($user['id'], $uid)) {
                    $error_msg = 'You cannot modify this user\'s permissions.';
                } elseif (resetUserPermissionsToDefault($uid, $user['id'])) {
                    $success_msg = "Permissions reset to role defaults.";
                } else {
                    $error_msg = "Failed to reset permissions.";
                }
            }
        }

        // ---- RESEND WELCOME EMAIL (issues a fresh temporary password) ----
        elseif ($action === 'resend_welcome') {
            if (!hasPermission($user['id'], 'user_edit')) {
                $error_msg = 'You do not have permission to manage users.';
            } else {
                $uid = (int)($_POST['user_id'] ?? 0);
                if (!canManageUser($user['id'], $uid)) {
                    $error_msg = 'You cannot manage this user.';
                } else {
                    $tstmt = $pdo->prepare("SELECT username, email, full_name, role FROM admin_users WHERE id = ?");
                    $tstmt->execute([$uid]);
                    $target = $tstmt->fetch(PDO::FETCH_ASSOC);
                    if (!$target) {
                        $error_msg = 'User not found.';
                    } elseif (empty($target['email']) || !filter_var($target['email'], FILTER_VALIDATE_EMAIL)) {
                        $error_msg = 'This user has no valid email address on file.';
                    } else {
                        // Fresh one-time invitation link; the current password is left alone and
                        // earlier unused links stop working.
                        $invite = rh_staff_invite_send($pdo, $uid, (string)($user['full_name'] ?? 'An administrator'));
                        if (!empty($invite['success'])) {
                            logActivity($user['id'], 'user_invited', "Resent invitation to '{$target['username']}'");
                            $success_msg = 'Invitation sent to ' . htmlspecialchars((string)$target['email']) . ' (link valid ' . RH_STAFF_INVITE_HOURS . ' h).';
                        } else {
                            $error_msg = 'The invitation email could not be sent: ' . rh_invite_failure_reason((string)$invite['message']);
                        }
                    }
                }
            }
        }

        // ---- DELETE USER ----
        elseif ($action === 'delete_user') {
            if (!hasPermission($user['id'], 'user_delete')) {
                $error_msg = 'You do not have permission to delete users.';
            } else {
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($uid <= 0) {
                    $error_msg = 'Invalid user.';
                } elseif ($uid === (int)$user['id']) {
                    $error_msg = 'You cannot delete your own account.';
                } elseif (!canManageUser($user['id'], $uid)) {
                    $error_msg = 'You cannot delete this user.';
                } else {
                    // Don't allow deleting the last admin
                    $admin_count = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                    $check_role = $pdo->prepare("SELECT role, full_name FROM admin_users WHERE id = ?");
                    $check_role->execute([$uid]);
                    $target = $check_role->fetch(PDO::FETCH_ASSOC);

                    if ($target && $target['role'] === 'admin' && $admin_count <= 1) {
                        $error_msg = 'Cannot delete the last admin user.';
                    } else {
                        // Delete user permissions first
                        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$uid]);

                        // Delete user
                        $stmt = $pdo->prepare("DELETE FROM admin_users WHERE id = ?");
                        $stmt->execute([$uid]);

                        logActivity($user['id'], 'user_deleted', "Deleted user '{$target['full_name']}'");
                        $success_msg = "User deleted successfully.";
                    }
                }
            }
        }

        // ---- BULK ROLE CHANGE ----
        elseif ($action === 'bulk_role_change') {
            if (!hasPermission($user['id'], 'user_edit')) {
                $error_msg = 'You do not have permission to edit users.';
            } else {
                $user_ids = is_array($_POST['user_ids'] ?? null) ? $_POST['user_ids'] : [];
                $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static fn($i) => $i > 0)));
                $new_role = (string)($_POST['new_role'] ?? '');

                if (!isset($all_roles[$new_role])) {
                    $error_msg = 'Invalid role selected.';
                } elseif (empty($user_ids)) {
                    $error_msg = 'No users selected.';
                } else {
                    $count = 0;
                    foreach ($user_ids as $uid) {
                        $uid = (int)$uid;
                        if (canManageUser($user['id'], $uid)) {
                            // Skip if it's the last admin
                            $check = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
                            $check->execute([$uid]);
                            $current_role = $check->fetchColumn();
                            if ($current_role === false || $current_role === $new_role) continue; // missing user or no change

                            if ($current_role === 'admin' && $new_role !== 'admin') {
                                $admin_count = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                                if ($admin_count <= 1) continue; // Skip last admin
                            }

                            $pdo->prepare("UPDATE admin_users SET role = ? WHERE id = ?")->execute([$new_role, $uid]);
                            resetUserPermissionsToDefault($uid, $user['id']);
                            $count++;
                        }
                    }
                    $success_msg = "Updated role for {$count} user(s).";
                    logActivity($user['id'], 'bulk_role_change', "Changed {$count} users to role '{$new_role}'");
                }
            }
        }
    }
}

// A failed Add / Edit reopens its form with what was typed and the reason inside it,
// so the person sees why nothing was saved (passwords are never sent back).
$rh_reopen_form = null;
if ($error_msg !== '' && in_array($action, ['add_user', 'update_user'], true)) {
    $rh_reopen_form = [
        'form' => $action === 'add_user' ? 'add' : 'edit',
        'error' => $error_msg,
        'user_id' => (int)($_POST['user_id'] ?? 0),
        'full_name' => (string)($_POST['full_name'] ?? ''),
        'username' => (string)($_POST['username'] ?? ''),
        'email' => (string)($_POST['email'] ?? ''),
        'role' => (string)($_POST['role'] ?? ''),
        'is_active' => !empty($_POST['is_active']),
        'send_welcome' => !empty($_POST['send_welcome']),
        'customize_first' => !empty($_POST['customize_first']),
    ];
}

// Fetch all users with last activity
$users_stmt = $pdo->query("
    SELECT u.*,
           (SELECT action FROM admin_activity_log WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_action,
           (SELECT created_at FROM admin_activity_log WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_activity
    FROM admin_users u
    ORDER BY u.role ASC, u.full_name ASC
");
$all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
// Rows are JSON-encoded into the page for the Edit/Permissions buttons; never ship credentials.
foreach ($all_users as &$rh_u) {
    unset($rh_u['password_hash'], $rh_u['password'], $rh_u['remember_token'], $rh_u['two_factor_secret']);
}
unset($rh_u);

// Get user counts by role
$user_counts = getUserCountByRole();

// If editing a specific user's permissions
$editing_user_id = isset($_GET['permissions']) ? (int)$_GET['permissions'] : 0;
$editing_user = null;
$editing_permissions = [];
if ($editing_user_id > 0) {
    foreach ($all_users as $u) {
        if ($u['id'] == $editing_user_id) {
            $editing_user = $u;
            break;
        }
    }
    if ($editing_user && $editing_user['role'] !== 'admin') {
        $editing_permissions = getUserPermissions($editing_user_id);
    }
}

$all_permissions = getAllPermissions();
$permission_categories = [];
// Tag permissions whose page belongs to a module that's off for this preset.
// The JS matrix hides them as new OFFERS but still renders any the user
// already holds — existing grants are never hidden.
$rh_perm_module_off = static function (array $info): bool {
    if (!function_exists('getModuleForPage') || !function_exists('rh_module_key_enabled')) {
        return false;
    }
    $mod = getModuleForPage((string)($info['page'] ?? ''));
    if ($mod === null) {
        return false;
    }
    foreach ((array)$mod as $mk) {
        if (!rh_module_key_enabled((string)$mk)) {
            return true;
        }
    }
    return false;
};
foreach ($all_permissions as $key => $info) {
    if ($key === 'user_management') continue;
    $info['module_off'] = $rh_perm_module_off($info);
    $permission_categories[$info['category']][$key] = $info;
}

// Get navigation categories for ordered display
$nav_categories = getNavCategories();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | <?php echo htmlspecialchars($site_name); ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/user-management.css?v=<?php echo @filemtime(__DIR__ . '/css/user-management.css'); ?>">
</head>
<body>

<?php require_once 'includes/admin-header.php'; ?>

<div class="user-management-container">

    <?php if ($success_msg): ?>
    <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert alert-error" style="background: #f8d7da; color: #721c24; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-users-cog"></i> User Management</h1>
        <?php if (hasPermission($user['id'], 'user_create')): ?>
        <button class="btn-add" onclick="openModal('addUserModal')">
            <i class="fas fa-user-plus"></i> Add User
        </button>
        <?php endif; ?>
    </div>

    <!-- Role Overview — roles relevant to this business preset, plus any
         off-preset role that still has users (existing staff are never hidden) -->
    <div class="role-overview">
        <?php foreach ($all_roles as $role_key => $role_data): ?>
        <?php
            $count = $user_counts[$role_key] ?? 0;
            $roleRelevant = isset($relevant_roles[$role_key]);
            if (!$roleRelevant && $count === 0) { continue; }
        ?>
        <div class="role-card" <?php echo $roleRelevant ? '' : 'style="opacity:.55;" title="This role\'s module is disabled for the current business preset"'; ?>>
            <div class="role-icon" style="background: <?php echo $role_data['color']; ?>20; color: <?php echo $role_data['color']; ?>;">
                <i class="fas <?php echo $role_data['icon']; ?>"></i>
            </div>
            <div class="role-count"><?php echo $count; ?></div>
            <div class="role-label"><?php echo $role_data['label']; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Users List -->
    <div class="users-section" data-rh-results>
        <div class="users-section-header">
            <h3><i class="fas fa-users"></i> All Users (<?php echo count($all_users); ?>)</h3>
            <div class="user-filters">
                <button class="filter-btn active" onclick="filterUsers('all')">All</button>
                <?php foreach ($all_roles as $role_key => $role_data): ?>
                <?php if (!isset($relevant_roles[$role_key]) && ($user_counts[$role_key] ?? 0) === 0) { continue; } ?>
                <button class="filter-btn" onclick="filterUsers('<?php echo $role_key; ?>')">
                    <?php echo $role_data['label']; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <table class="users-table" id="usersTable">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Active</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_users as $u): ?>
                <tr data-role="<?php echo htmlspecialchars((string)$u['role']); ?>">
                    <td>
                        <div class="user-info">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($u['full_name'], 0, 1)); ?>
                            </div>
                            <div class="user-details">
                                <div class="user-name"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                <div class="user-email"><?php echo htmlspecialchars($u['email']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="role-badge <?php echo htmlspecialchars((string)$u['role']); ?>">
                            <i class="fas <?php echo $all_roles[$u['role']]['icon'] ?? 'fa-user'; ?>"></i>
                            <?php echo $all_roles[$u['role']]['label'] ?? ucfirst($u['role']); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo $u['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                            <i class="fas fa-circle"></i>
                            <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($u['last_activity']): ?>
                        <span title="<?php echo htmlspecialchars($u['last_action']); ?>">
                            <?php echo date('M j, g:ia', strtotime($u['last_activity'])); ?>
                        </span>
                        <?php else: ?>
                        <span style="color: #999;">Never</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="actions-cell">
                            <?php if (hasPermission($user['id'], 'user_edit') && canManageUser($user['id'], $u['id'])): ?>
                            <button type="button" class="btn-sm btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES); ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <?php endif; ?>

                            <?php if ($u['role'] !== 'admin' && hasPermission($user['id'], 'user_permissions') && canManageUser($user['id'], $u['id'])): ?>
                            <button type="button" class="btn-sm btn-permissions" onclick="openPermissionsModal(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES); ?>)">
                                <i class="fas fa-shield-alt"></i> Permissions
                            </button>
                            <?php endif; ?>

                            <?php if ($u['id'] != $user['id'] && !empty($u['email']) && hasPermission($user['id'], 'user_edit') && canManageUser($user['id'], $u['id'])): ?>
                            <button type="button" class="btn-sm btn-edit" title="Resend the invitation (a fresh one-time link to set their password)"
                                onclick="if (confirm('Resend the invitation to <?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?>? They get a fresh one-time link to set their password; earlier links stop working.')) { submitResendWelcome(<?php echo (int)$u['id']; ?>); }">
                                <i class="fas fa-envelope"></i> Resend invitation
                            </button>
                            <?php endif; ?>

                            <?php if ($u['id'] != $user['id'] && hasPermission($user['id'], 'user_delete') && canManageUser($user['id'], $u['id'])): ?>
                            <button type="button" class="btn-sm btn-delete" onclick="confirmDelete(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Permissions Modal -->
    <?php if (hasPermission($user['id'], 'user_permissions')): ?>
    <div class="modal-overlay" id="permissionsModal">
        <div class="modal-content">
            <form method="POST" id="permissionsForm" class="permissions-modal__form" data-busy-text="Saving...">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="save_permissions">
                <input type="hidden" name="user_id" id="perm-user-id">
                <input type="hidden" name="send_invite_after" id="perm-send-invite" value="0">

                <div class="modal-header">
                    <h3><i class="fas fa-shield-alt"></i> <span id="perm-modal-title">Edit Permissions</span></h3>
                    <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('permissionsModal')">&times;</button>
                </div>

                <div class="modal-body permissions-modal__body">
                    <div class="permissions-modal__summary">
                        <div id="perm-user-avatar" class="permissions-modal__avatar"></div>
                        <div class="permissions-modal__summary-copy">
                            <div class="permissions-modal__summary-name" id="perm-user-name"></div>
                            <div id="perm-user-role-badge" class="permissions-modal__role-badge"></div>
                        </div>
                    </div>

                    <div class="quick-actions permissions-modal__quick-actions">
                        <button type="button" class="btn-select-all" onclick="selectAllPerms(true)">
                            <i class="fas fa-check-double"></i> Select All
                        </button>
                        <button type="button" class="btn-select-none" onclick="selectAllPerms(false)">
                            <i class="fas fa-times"></i> Deselect All
                        </button>
                        <button type="button" class="btn-select-defaults" onclick="selectDefaults()">
                            <i class="fas fa-undo"></i> Role Defaults
                        </button>
                    </div>

                    <div class="permissions-modal__feedback" id="permissions-modal-feedback" hidden></div>

                    <div id="perm-categories-container" class="permissions-modal__categories">
                        <!-- Populated by JS -->
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal('permissionsModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn-save-perms" id="perm-save-btn">
                        <i class="fas fa-save"></i> <span id="perm-save-label">Save Permissions</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add User Modal -->
<?php if (hasPermission($user['id'], 'user_create')): ?>
<div class="modal-overlay" id="addUserModal">
    <div class="modal-content">
        <form method="POST" id="addUserForm" data-busy-text="Creating user...">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="add_user">

            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New User</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('addUserModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="um-form-error" id="add-form-error" role="alert" hidden></div>
                <div class="form-row">
                    <label for="add-fullname">Full Name</label>
                    <input type="text" id="add-fullname" name="full_name" autocomplete="off" required maxlength="255" placeholder="e.g. Jane Banda">
                </div>
                <div class="form-row">
                    <label for="add-username">Username</label>
                    <input type="text" id="add-username" name="username" autocomplete="off" required placeholder="e.g. jane.b" minlength="3" maxlength="100" pattern="[a-zA-Z0-9._-]+" title="At least 3 characters: letters, numbers, dots, dashes, underscores only" data-unique="username">
                    <div class="um-field-error" id="add-username-error" hidden></div>
                </div>
                <div class="form-row">
                    <label for="add-email">Email</label>
                    <input type="email" id="add-email" name="email" autocomplete="off" required maxlength="255" placeholder="e.g. jane@example.com" data-unique="email">
                    <div class="um-field-error" id="add-email-error" hidden></div>
                </div>
                <div class="form-row">
                    <label for="add-role">Role</label>
                    <?php /* No preselected role: the first option used to be Administrator, so a
                             missed dropdown created a full-access account. */ ?>
                    <select id="add-role" name="role" required>
                        <option value="" selected disabled>Choose a role...</option>
                        <?php foreach ($relevant_roles as $role_key => $role_data): ?>
                        <option value="<?php echo $role_key; ?>"><?php echo $role_data['label']; ?> - <?php echo $role_data['description']; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Role determines default permissions. You can customize later.</div>
                </div>
                <div class="form-row">
                    <label for="add-password">Password <span style="font-weight:400;color:#888;">(optional)</span></label>
                    <input type="password" id="add-password" name="password" autocomplete="new-password" minlength="8" placeholder="Leave blank - they set it from the invitation">
                    <div class="hint">With "Send invitation" ticked, the person gets a one-time link (valid 72 h) to choose their own password. No password is ever emailed.</div>
                </div>
                <div class="form-row">
                    <label class="edit-active-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; font-size: 13px; color: var(--navy);">
                        <input type="checkbox" name="send_welcome" id="add-send-welcome" value="1" checked style="width: auto;">
                        Send invitation email
                    </label>
                    <?php if (hasPermission($user['id'], 'user_permissions')): ?>
                    <label class="edit-active-label" id="add-customize-first-row" style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; font-size: 13px; color: var(--navy); margin-top: 8px;">
                        <input type="checkbox" name="customize_first" id="add-customize-first" value="1" style="width: auto;">
                        Set their permissions before the invitation goes out
                    </label>
                    <div class="hint">Ticked: you choose permissions next, and the invitation is sent when you press "Save &amp; send invitation". Unticked: it goes now with the role's default permissions.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addUserModal')">Cancel</button>
                <button type="submit" class="btn-save-perms">
                    <i class="fas fa-user-plus"></i> Create User
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Edit User Modal -->
<?php if (hasPermission($user['id'], 'user_edit')): ?>
<div class="modal-overlay" id="editUserModal">
    <div class="modal-content">
        <form method="POST" id="editUserForm" data-busy-text="Saving...">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="edit-user-id">

            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Edit User</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="um-form-error" id="edit-form-error" role="alert" hidden></div>
                <div class="form-row">
                    <label for="edit-username">Username <span style="font-weight:400; color:#888;">(read-only)</span></label>
                    <input type="text" id="edit-username" disabled style="background:#f8f9fb; color:#888; cursor:not-allowed;">
                </div>
                <div class="form-row">
                    <label for="edit-fullname">Full Name</label>
                    <input type="text" id="edit-fullname" name="full_name" required maxlength="255">
                </div>
                <div class="form-row">
                    <label for="edit-email">Email</label>
                    <input type="email" id="edit-email" name="email" autocomplete="off" required maxlength="255" data-unique="email">
                    <div class="um-field-error" id="edit-email-error" hidden></div>
                </div>
                <div class="form-row">
                    <label for="edit-role">Role</label>
                    <?php /* Edit keeps EVERY role selectable — the user being edited may
                             hold a role whose module is off; off-preset roles are labelled
                             so admins don't newly assign them by accident. */ ?>
                    <select id="edit-role" name="role">
                        <?php foreach ($all_roles as $role_key => $role_data): ?>
                        <option value="<?php echo $role_key; ?>"><?php echo $role_data['label']; ?><?php echo isset($relevant_roles[$role_key]) ? '' : ' (module disabled)'; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label class="edit-active-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; font-size: 13px; color: var(--navy);">
                        <input type="checkbox" name="is_active" id="edit-active" value="1" style="width: auto;">
                        Active Account
                    </label>
                </div>
                <div class="form-row">
                    <label for="edit-password">New Password <span style="font-weight:400; color:#888;">(leave blank to keep current)</span></label>
                    <input type="password" id="edit-password" name="new_password" autocomplete="new-password" minlength="8" placeholder="Leave blank to keep unchanged">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editUserModal')">Cancel</button>
                <button type="submit" class="btn-save-perms">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Delete Confirmation Modal -->
<?php if (hasPermission($user['id'], 'user_delete')): ?>
<div class="modal-overlay" id="deleteConfirmModal">
    <div class="modal-content" style="max-width: 420px;">
        <div class="modal-header">
            <h3><i class="fas fa-triangle-exclamation"></i> Delete User</h3>
            <button type="button" class="modal-close" aria-label="Close" onclick="closeModal('deleteConfirmModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin: 0 0 8px; color: var(--navy, #231F1C); font-size: 14px;">
                Are you sure you want to delete <strong id="delete-confirm-name"></strong>?
            </p>
            <p style="margin: 0; color: #888; font-size: 12px;">
                <i class="fas fa-circle-info"></i> This will permanently remove the user and all their permissions.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('deleteConfirmModal')">Cancel</button>
            <button type="button" class="btn-delete-confirm" onclick="submitDeleteUser()">
                <i class="fas fa-trash"></i> Delete User
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete Form (hidden) -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="user_id" id="delete-user-id">
</form>

<!-- Resend Welcome Form (hidden) -->
<form method="POST" id="resendWelcomeForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <input type="hidden" name="action" value="resend_welcome">
    <input type="hidden" name="user_id" id="resend-welcome-user-id">
</form>

<script>
// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// Edit user modal
function openEditModal(userData) {
    document.getElementById('edit-user-id').value = userData.id;
    document.getElementById('edit-username').value = userData.username;
    document.getElementById('edit-fullname').value = userData.full_name;
    document.getElementById('edit-email').value = userData.email;
    document.getElementById('edit-role').value = userData.role;
    document.getElementById('edit-active').checked = userData.is_active == 1;
    document.getElementById('edit-password').value = '';
    umClearFormErrors('editUserForm');
    openModal('editUserModal');
}

// Delete confirmation
function confirmDelete(userId, userName) {
    document.getElementById('delete-user-id').value = userId;
    document.getElementById('delete-confirm-name').textContent = userName;
    openModal('deleteConfirmModal');
}

// "Set their permissions before the invitation goes out" only applies when inviting.
(function () {
    const send = document.getElementById('add-send-welcome');
    const first = document.getElementById('add-customize-first');
    if (!send || !first) return;
    const sync = () => {
        first.disabled = !send.checked;
        if (!send.checked) first.checked = false;
    };
    send.addEventListener('change', sync);
    sync();
})();

<?php
$rh_pending_invite_user = null;
if ($open_permissions_for > 0) {
    foreach ($all_users as $u) {
        if ((int)$u['id'] === $open_permissions_for) {
            $rh_pending_invite_user = $u;
            break;
        }
    }
}
if ($rh_pending_invite_user): ?>
// Just created with "set permissions first": open the permissions step right away.
document.addEventListener('DOMContentLoaded', function () {
    openPermissionsModal(<?php echo json_encode($rh_pending_invite_user, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, true);
});
<?php endif; ?>

// Form checks: a duplicate username / email is flagged while typing, reasons show inside the
// form, and the button shows it is busy so a slow save (the invitation email) never looks
// like nothing happened. The server checks everything again on save.
function umShowFieldError(input, message) {
    const box = document.getElementById(input.id + '-error');
    input.setCustomValidity(message || '');
    input.classList.toggle('um-input-invalid', !!message);
    if (box) {
        box.textContent = message || '';
        box.hidden = !message;
    }
}

function umShowFormError(formId, message) {
    const box = document.getElementById(formId === 'addUserForm' ? 'add-form-error' : 'edit-form-error');
    if (!box) return;
    box.textContent = message || '';
    box.hidden = !message;
}

function umClearFormErrors(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.querySelectorAll('[data-unique]').forEach(function (input) { umShowFieldError(input, ''); });
    umShowFormError(formId, '');
}

(function () {
    const timers = new WeakMap();

    function checkUnique(input) {
        const value = input.value.trim();
        input.setCustomValidity('');
        // Format problems are the browser's job; only ask the server about well-formed values.
        if (value === '' || !input.validity.valid) return;
        const excludeId = input.form && input.form.id === 'editUserForm' ? document.getElementById('edit-user-id').value : '';
        const url = 'api/user-check-unique.php?field=' + encodeURIComponent(input.dataset.unique)
            + '&value=' + encodeURIComponent(value)
            + (excludeId ? '&exclude_id=' + encodeURIComponent(excludeId) : '');
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (input.value.trim() !== value) return; // typed on since
                umShowFieldError(input, data && data.taken ? data.message : '');
            })
            .catch(function () { /* the server checks again on save */ });
    }

    document.querySelectorAll('#addUserForm [data-unique], #editUserForm [data-unique]').forEach(function (input) {
        input.addEventListener('input', function () {
            umShowFieldError(input, '');
            clearTimeout(timers.get(input));
            timers.set(input, setTimeout(function () { checkUnique(input); }, 450));
        });
        input.addEventListener('blur', function () {
            clearTimeout(timers.get(input));
            checkUnique(input);
        });
    });

    ['addUserForm', 'editUserForm', 'permissionsForm'].forEach(function (formId) {
        const form = document.getElementById(formId);
        if (!form) return;
        // Back button restores the page from cache with the button still busy: free it again.
        window.addEventListener('pageshow', function (e) {
            if (!e.persisted || form.dataset.submitting !== '1') return;
            form.dataset.submitting = '';
            const btn = form.querySelector('button[type="submit"]');
            if (btn && window.ButtonLoader) window.ButtonLoader.hide(btn);
        });
        form.addEventListener('submit', function (e) {
            const invalid = form.querySelector('.um-input-invalid');
            if (invalid) {
                e.preventDefault();
                invalid.focus();
                return;
            }
            if (form.dataset.submitting === '1') {
                e.preventDefault(); // second click while the first save is still running
                return;
            }
            form.dataset.submitting = '1';
            let text = form.dataset.busyText || 'Saving...';
            const send = document.getElementById('add-send-welcome');
            const first = document.getElementById('add-customize-first');
            if (formId === 'addUserForm' && send && send.checked && !(first && first.checked)) {
                text = 'Creating and sending invitation...';
            }
            if (formId === 'permissionsForm' && document.getElementById('perm-send-invite').value === '1') {
                text = 'Saving and sending invitation...';
            }
            const btn = form.querySelector('button[type="submit"]');
            if (btn && window.ButtonLoader) {
                window.ButtonLoader.show(btn, { text: text });
            } else if (btn) {
                btn.disabled = true;
            }
        });
    });
})();

<?php
if ($rh_reopen_form && $rh_reopen_form['form'] === 'edit') {
    foreach ($all_users as $u) {
        if ((int)$u['id'] === $rh_reopen_form['user_id']) {
            $rh_reopen_form['username'] = (string)$u['username'];
            break;
        }
    }
}
if ($rh_reopen_form): ?>
// The last Add / Edit was not saved: reopen it with what was typed and the reason.
(function () {
    const r = <?php echo json_encode($rh_reopen_form, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    if (r.form === 'add' && document.getElementById('addUserForm')) {
        document.getElementById('add-fullname').value = r.full_name;
        document.getElementById('add-username').value = r.username;
        document.getElementById('add-email').value = r.email;
        if (r.role) document.getElementById('add-role').value = r.role;
        const send = document.getElementById('add-send-welcome');
        const first = document.getElementById('add-customize-first');
        if (send) { send.checked = r.send_welcome; send.dispatchEvent(new Event('change')); }
        if (first && !first.disabled) first.checked = r.customize_first;
        umShowFormError('addUserForm', r.error);
        openModal('addUserModal');
    } else if (r.form === 'edit' && document.getElementById('editUserForm')) {
        document.getElementById('edit-user-id').value = r.user_id;
        document.getElementById('edit-username').value = r.username;
        document.getElementById('edit-fullname').value = r.full_name;
        document.getElementById('edit-email').value = r.email;
        if (r.role) document.getElementById('edit-role').value = r.role;
        document.getElementById('edit-active').checked = r.is_active;
        document.getElementById('edit-password').value = '';
        umShowFormError('editUserForm', r.error);
        openModal('editUserModal');
    }
})();
<?php endif; ?>

function submitResendWelcome(userId) {
    document.getElementById('resend-welcome-user-id').value = userId;
    document.getElementById('resendWelcomeForm').submit();
}

function submitDeleteUser() {
    document.getElementById('deleteForm').submit();
}

<?php
// Each role's real default permission keys (same source the server uses when no explicit grant exists).
$rh_role_defaults = [];
foreach (array_keys($all_roles) as $rh_rk) {
    $rh_role_defaults[$rh_rk] = array_values(getDefaultPermissionsForRole((string)$rh_rk));
}
?>
// Permission data (embedded for client-side rendering)
const permissionData = <?php echo json_encode([
    'categories' => $nav_categories,
    'permissionsByCategory' => $permission_categories,
    'allPermissions' => getAllPermissions(),
    'roles' => $all_roles,
    'roleDefaults' => $rh_role_defaults
]); ?>;

// Open permissions modal
function openPermissionsModal(userData, sendInviteAfter) {
    const container = document.getElementById('perm-categories-container');

    document.getElementById('perm-send-invite').value = sendInviteAfter ? '1' : '0';
    document.getElementById('perm-save-label').textContent = sendInviteAfter ? 'Save & send invitation' : 'Save Permissions';

    document.getElementById('perm-user-id').value = userData.id;
    document.getElementById('perm-user-name').textContent = userData.full_name;
    document.getElementById('perm-user-avatar').textContent = userData.full_name.charAt(0).toUpperCase();
    setPermissionsModalFeedback('');
    container.innerHTML = '<div class="permissions-modal__loading"><i class="fas fa-spinner fa-spin"></i><span>Loading permissions...</span></div>';

    // Create role badge
    const role = userData.role;
    const roleData = permissionData.roles[role] || {};
    const roleBadge = `<span class="role-badge ${role}"><i class="fas ${roleData.icon || 'fa-user'}"></i> ${roleData.label || role}</span>`;
    document.getElementById('perm-user-role-badge').innerHTML = roleBadge;
    openModal('permissionsModal');

    // Fetch permissions for this user
    fetch('api/user-permissions.php?user_id=' + encodeURIComponent(userData.id), {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            populatePermissionsForm(data.permissions, userData.role);
        } else {
            container.innerHTML = '';
            setPermissionsModalFeedback(data.error || 'Failed to load permissions.');
        }
    })
    .catch(() => {
        container.innerHTML = '';
        setPermissionsModalFeedback('Failed to load permissions. Please try again.');
    });
}

function setPermissionsModalFeedback(message) {
    const feedback = document.getElementById('permissions-modal-feedback');

    if (!message) {
        feedback.hidden = true;
        feedback.textContent = '';
        feedback.className = 'permissions-modal__feedback';
        return;
    }

    feedback.hidden = false;
    feedback.className = 'permissions-modal__feedback permissions-modal__feedback--error';
    feedback.innerHTML = `<i class="fas fa-circle-exclamation"></i><span>${message}</span>`;
}

// Populate permissions form from data
function populatePermissionsForm(permissions, role) {
    const container = document.getElementById('perm-categories-container');
    const defaultPerms = getDefaultPermissionsForRole(role);

    container.innerHTML = '';

    for (const [catName, catInfo] of Object.entries(permissionData.categories)) {
        if (!permissionData.permissionsByCategory[catName]) continue;

        const categoryHtml = document.createElement('div');
        categoryHtml.className = 'perm-category';

        let categoryContent = `
            <h4 class="perm-category-title">
                <i class="fas ${catInfo.icon}"></i> ${catName}
            </h4>
            <div class="perm-grid">
        `;

        let renderedInCategory = 0;
        for (const [permKey, permInfo] of Object.entries(permissionData.permissionsByCategory[catName])) {
            if (permKey === 'user_management') continue; // Skip admin-only

            const isChecked = permissions[permKey] || false;
            // Off-preset permissions aren't offered as new grants, but any the
            // user already holds stay visible (and revocable).
            if (permInfo.module_off && !isChecked) continue;
            renderedInCategory++;
            const isDefault = defaultPerms.includes(permKey);

            categoryContent += `
                <label class="perm-item ${isChecked ? 'checked' : ''}" id="perm-label-${permKey}">
                    <div class="perm-icon">
                        <i class="fas ${permInfo.icon}"></i>
                    </div>
                    <input type="checkbox"
                           name="permissions[]"
                           value="${permKey}"
                           ${isChecked ? 'checked' : ''}
                           onchange="togglePermItem(this)"
                           data-default="${isDefault ? '1' : '0'}">
                    <div class="perm-info">
                        <div class="perm-label">${permInfo.label}</div>
                        <div class="perm-desc">${permInfo.description}</div>
                    </div>
                </label>
            `;
        }

        categoryContent += '</div>';
        if (renderedInCategory === 0) continue; // whole category off-preset
        categoryHtml.innerHTML = categoryContent;
        container.appendChild(categoryHtml);
    }
}

// Get default permissions for a role
function getDefaultPermissionsForRole(role) {
    return (permissionData.roleDefaults && permissionData.roleDefaults[role]) || [];
}

// Permission toggle
function togglePermItem(checkbox) {
    const label = checkbox.closest('.perm-item');
    if (checkbox.checked) {
        label.classList.add('checked');
    } else {
        label.classList.remove('checked');
    }
}

function selectAllPerms(checked) {
    document.querySelectorAll('#permissionsForm input[type="checkbox"]').forEach(cb => {
        cb.checked = checked;
        togglePermItem(cb);
    });
}

function selectDefaults() {
    document.querySelectorAll('#permissionsForm input[type="checkbox"]').forEach(cb => {
        cb.checked = cb.dataset.default === '1';
        togglePermItem(cb);
    });
}

// User filter
function filterUsers(role) {
    const rows = document.querySelectorAll('#usersTable tbody tr');
    const buttons = document.querySelectorAll('.filter-btn');

    buttons.forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');

    rows.forEach(row => {
        if (role === 'all' || row.dataset.role === role) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Close modal on outside click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>

