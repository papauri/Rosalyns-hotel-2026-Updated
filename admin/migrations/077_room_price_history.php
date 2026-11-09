<?php

/**
 * 077 - room_price_history: who changed a room or rate price, when, and from what to what (owner 2026-11-09:
 * "a full timeline breakdown of logs to every booking and payment").
 *
 * Additive only, re-running is safe. One row per changed field, written by includes/price-history.php from the room
 * type editor (price per night, single / double / triple prices), the room-type API, joined-rooms price override and
 * rate plans (create / update / delete / switch on-off). Until this runs, those saves work exactly as before and simply
 * record nothing. The booking History panel reads it to say who changed a price a booking was made at.
 * History rows are KEEP (never deleted with their room or plan).
 */

return [
    'name' => 'room_price_history',

    'check' => function (PDO $pdo): bool {
        return $pdo->query("SHOW TABLES LIKE 'room_price_history'")->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `room_price_history` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `entity_type` ENUM('room_type','individual_room','room_combination','rate_plan') NOT NULL,
            `entity_id` INT UNSIGNED NOT NULL,
            `field` VARCHAR(64) NOT NULL COMMENT 'price_per_night, price_double_occupancy, price_override, adjustment_value, is_active, created, deleted ...',
            `old_value` VARCHAR(255) NULL DEFAULT NULL,
            `new_value` VARCHAR(255) NULL DEFAULT NULL,
            `changed_by` INT UNSIGNED NULL DEFAULT NULL,
            `changed_by_name` VARCHAR(255) NULL DEFAULT NULL,
            `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_rph_entity` (`entity_type`, `entity_id`, `changed_at`),
            KEY `idx_rph_changed_at` (`changed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Room and rate price changes: who, when, old -> new'");
    },
];
