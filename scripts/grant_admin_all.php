<?php
/**
 * grant_admin_all.php — idempotent guarantee that every "admin" role user
 * has every permission in the system.
 *
 * The admin role already auto-grants all permissions via
 * `hasPermission()`'s short-circuit (admin/includes/permissions.php), but
 * stale rows in `user_permissions` (from when a user was previously demoted
 * and is now admin again, or from manual edits) can mislead the
 * user-management UI. This script:
 *   - Lists every active admin user
 *   - Deletes any explicit user_permissions rows for them so the bypass
 *     is the single source of truth
 *   - Reports counts
 *
 * Idempotent. Safe to re-run. No writes other than DELETE on stale rows.
 *
 * Usage: php scripts/grant_admin_all.php
 */
declare(strict_types=1);

chdir(dirname(__DIR__));
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../admin/includes/permissions.php';

global $pdo;

try {
    $admins = $pdo->query("SELECT id, username, full_name, is_active FROM admin_users WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "Could not query admin_users: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$admins) {
    echo "No users with role='admin' found.\n";
    exit(0);
}

$allPerms = array_keys(getAllPermissions());
echo "Found " . count($admins) . " admin user(s). Total permission keys in system: " . count($allPerms) . ".\n\n";

$totalDeleted = 0;
foreach ($admins as $u) {
    $userId = (int)$u['id'];
    $label = $u['username'] . ' (' . ($u['full_name'] ?: '—') . ')';
    if (!$u['is_active']) {
        echo "  - {$label} [INACTIVE — skipped]\n";
        continue;
    }
    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM user_permissions WHERE user_id = ?");
        $countStmt->execute([$userId]);
        $rows = (int)$countStmt->fetchColumn();
        if ($rows > 0) {
            $del = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $del->execute([$userId]);
            echo "  - {$label}: cleared {$rows} stale per-user permission row(s) (admin bypass now authoritative).\n";
            $totalDeleted += $rows;
        } else {
            echo "  - {$label}: already clean (0 explicit rows; admin bypass grants all " . count($allPerms) . " permissions).\n";
        }
    } catch (PDOException $e) {
        // user_permissions table may not exist on older installs — not an error.
        if (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'Base table')) {
            echo "  - {$label}: user_permissions table not present — admin bypass alone grants all " . count($allPerms) . " permissions. OK.\n";
        } else {
            echo "  - {$label}: ERROR — " . $e->getMessage() . "\n";
        }
    }
}

echo "\nDone. Cleared {$totalDeleted} stale row(s) total.\n";
echo "Admin users now receive all " . count($allPerms) . " permissions via the role short-circuit in hasPermission().\n";
