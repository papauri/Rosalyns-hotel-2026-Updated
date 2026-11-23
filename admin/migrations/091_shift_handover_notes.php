<?php

/**
 * 091 - Shift handover notes + per-user dashboard preferences (Dashboard first-login plan, task C1, 2026-11-24).
 *
 *   shift_handover_notes    one short note left for the next shift: body (max 1000 chars), who wrote it (id + name at the
 *                           time, so the note still reads right if the user is renamed or removed), when, and when it
 *                           stops showing (expires_at).
 *   shift_handover_reads    who has read which note (one row per note + user; re-marking a note read is a no-op).
 *   admin_user_preferences  the table admin/api/nav-favorites.php used to create on demand; the DDL below is that exact
 *                           DDL, so a database that already has it is left untouched. admin_users.id is INT UNSIGNED,
 *                           matching user_id and its FK.
 *
 * Additive only (CREATE TABLE IF NOT EXISTS). Re-running is safe.
 *
 * ROLLBACK (manual, never run automatically; owner OK 2026-11-24): drop ONLY the two new tables, reads first:
 *     DROP TABLE IF EXISTS shift_handover_reads;
 *     DROP TABLE IF EXISTS shift_handover_notes;
 * NEVER drop admin_user_preferences: it predates this migration and holds users' nav favourites.
 * Take a backup first (php scripts/backup_database.php) and never run it on a database listed in .claude/LIVE_TARGETS
 * without the owner asking for that exact change.
 */

return [
    'name' => 'shift_handover_notes',

    'check' => function (PDO $pdo): bool {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('shift_handover_notes', 'shift_handover_reads', 'admin_user_preferences')");
        return (int)$st->fetchColumn() === 3;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS shift_handover_notes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            body VARCHAR(1000) NOT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_by_name VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            KEY idx_shn_expires (expires_at),
            KEY idx_shn_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS shift_handover_reads (
            note_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            read_at DATETIME NOT NULL,
            PRIMARY KEY (note_id, user_id),
            KEY idx_shr_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Exact DDL of admin/api/nav-favorites.php (nav_favorites_ensure_table).
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_user_preferences (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        preference_key VARCHAR(100) NOT NULL,
        preference_value TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_admin_user_pref (user_id, preference_key),
        KEY idx_admin_user_preferences_user (user_id),
        CONSTRAINT fk_admin_user_preferences_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
