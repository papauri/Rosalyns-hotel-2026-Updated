<?php

/**
 * 080 - super admin flag on staff accounts (owner 2026-11-11: "a new super admin role ... even capable of changing the
 * admins' permissions. Power over every single thing.").
 *
 * A super admin is an admin_users row with role 'admin' AND is_super_admin = 1, so every existing role === 'admin' check,
 * the licence seat count and the last-admin guards keep working unchanged. See rh_is_super_admin() in
 * admin/includes/permissions.php. The first one is set from the command line: php scripts/make-super-admin.php <email>.
 *
 * Adds the column only when it is missing. No rows are touched (everyone starts as 0), so re-running is safe.
 */

$rhSuperAdminColumnExists = static function (PDO $pdo): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'is_super_admin'"
    );
    $st->execute();
    return (int)$st->fetchColumn() > 0;
};

return [
    'name' => 'admin_super_admin_flag',

    'check' => function (PDO $pdo) use ($rhSuperAdminColumnExists): bool {
        return $rhSuperAdminColumnExists($pdo);
    },

    'up' => function (PDO $pdo) use ($rhSuperAdminColumnExists): void {
        if ($rhSuperAdminColumnExists($pdo)) {
            return;
        }
        $pdo->exec(
            "ALTER TABLE admin_users ADD COLUMN `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'Super admin (role admin + this flag): can change administrators'' permissions'"
        );
        echo "    admin_users.is_super_admin: added (everyone 0; set the first with php scripts/make-super-admin.php <email>)\n";
    },
];
