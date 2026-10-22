<?php

/**
 * 064 - staff presence: `admin_presence` (one row per signed-in staff member).
 *
 * Additive only (owner approved 2026-10-22: "in admin audits also mention who is currently
 * online on the system logged in and active"). One row per user, updated by a cheap heartbeat
 * from admin-init.php on each page load (throttled) and from the admin page script while a
 * person is actually using a tab; deleted on logout.
 *
 *   last_seen_at       last heartbeat (Active now <= 2 min, Idle <= 30 min, then offline)
 *   current_page       basename of the admin page they are on (no query string)  - kept so a
 *                      later "someone else is booking" banner can read what each person has open
 *   session_started_at when this sign-in began
 *   ip / user_agent_short  shown to administrators only (ip), never to managers
 */

return [
    'name' => 'admin_presence',

    'check' => function (PDO $pdo): bool {
        return $pdo->query("SHOW TABLES LIKE 'admin_presence'")->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_presence` (
            `user_id` INT UNSIGNED NOT NULL,
            `last_seen_at` DATETIME NOT NULL,
            `current_page` VARCHAR(120) NULL DEFAULT NULL,
            `session_started_at` DATETIME NOT NULL,
            `ip` VARCHAR(45) NULL DEFAULT NULL,
            `user_agent_short` VARCHAR(120) NULL DEFAULT NULL,
            PRIMARY KEY (`user_id`),
            KEY `idx_admin_presence_last_seen` (`last_seen_at`),
            KEY `idx_admin_presence_page` (`current_page`, `last_seen_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Who is signed in to the admin and which screen they are on'");
    },
];
