<?php
/**
 * Migration 032 — Permissions tables.
 *
 * Backfills the missing schema for the granular RBAC system. The permissions
 * helper (`admin/includes/permissions.php`) reads/writes these tables but no
 * earlier migration ever created them, so per-user overrides silently failed
 * and only role defaults were honoured.
 *
 * Creates (idempotent):
 *   - user_permissions   ← per-user grant/deny overrides
 *   - permissions        ← canonical permission key catalogue (used by 030)
 *   - role_permissions   ← default role → permission mapping (used by 030)
 *
 * SAFE: Only CREATE TABLE IF NOT EXISTS — no destructive changes.
 */

$isCli032 = (PHP_SAPI === 'cli');

if ($isCli032) {
    require_once __DIR__ . '/../../config/database.php';
} else {
    require_once __DIR__ . '/../admin-init.php';
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin only.');
    }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:20px;'>";
}

function out032(string $m, string $t = 'info'): void {
    $p = $t === 'ok' ? '[OK]   ' : ($t === 'skip' ? '[SKIP] ' : '[INFO] ');
    echo $p . $m . PHP_EOL;
}

try {
    /* 1. user_permissions — per-user grant/deny overrides */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_permissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            permission_key VARCHAR(80) NOT NULL,
            is_granted TINYINT(1) NOT NULL DEFAULT 0,
            granted_by INT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_user_perm (user_id, permission_key),
            KEY idx_user (user_id),
            KEY idx_perm (permission_key),
            CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out032('user_permissions table OK', 'ok');

    /* 2. permissions — canonical key catalogue */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS permissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            permission_key VARCHAR(80) NOT NULL,
            label VARCHAR(150) NOT NULL,
            description VARCHAR(500) NULL,
            category VARCHAR(80) NULL DEFAULT 'General',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_perm_key (permission_key),
            KEY idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out032('permissions table OK', 'ok');

    /* 3. role_permissions — default role -> permission mapping */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS role_permissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            role VARCHAR(40) NOT NULL,
            permission_key VARCHAR(80) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_role_perm (role, permission_key),
            KEY idx_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out032('role_permissions table OK', 'ok');

    out032('--------------------------------------', 'info');
    out032('Migration 032 completed.', 'ok');
    out032('Tip: re-run 030_menu_visibility_room_service.php to seed', 'info');
    out032('     permissions/role_permissions rows.', 'info');

} catch (Throwable $e) {
    out032('FAILED: ' . $e->getMessage(), 'info');
    exit(1);
}

