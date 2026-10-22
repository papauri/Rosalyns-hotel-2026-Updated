<?php

/**
 * 063 - child charge rules per room type and per individual room.
 *
 * Additive only (owner decision 2026-10-22). Managers can now set, per room type and per individual room
 * (the room overrides the type): the child charge as a % of the room rate OR a fixed amount per child per
 * night, a number of free children (the first N are free), and a maximum number of children per room.
 *
 *   rooms             child_charge_mode ('percent'|'fixed', default percent), child_charge_amount (fixed MWK per
 *                     child per night, NULL = not used), free_children (default 0), max_children (NULL = no extra limit).
 *                     rooms.child_price_multiplier stays the % value.
 *   individual_rooms  the same four columns, every one NULL = inherit from the room type.
 *   bookings          snapshot of the rule the booking was priced with (child_charge_mode, child_charge_amount,
 *                     free_children) next to the existing child_price_multiplier, so a saved booking keeps its rule.
 *
 * Existing rows get percent mode with their current %, free_children 0 - every price stays exactly as today.
 */

return [
    'name' => 'child_charge_rules',

    'check' => function (PDO $pdo): bool {
        $need = [
            'rooms' => ['child_charge_mode', 'child_charge_amount', 'free_children', 'max_children'],
            'individual_rooms' => ['child_charge_mode', 'child_charge_amount', 'free_children', 'max_children'],
            'bookings' => ['child_charge_mode', 'child_charge_amount', 'free_children'],
        ];
        foreach ($need as $table => $cols) {
            foreach ($cols as $col) {
                if ($pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->rowCount() === 0) {
                    return false;
                }
            }
        }
        return true;
    },

    'up' => function (PDO $pdo): void {
        $add = function (string $table, string $col, string $definition) use ($pdo): void {
            if ($pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
            }
        };

        // Room types: always a rule (percent of the rate by default, nobody free, no extra limit).
        $add('rooms', 'child_charge_mode', "VARCHAR(10) NOT NULL DEFAULT 'percent' COMMENT 'percent = % of room rate (child_price_multiplier); fixed = child_charge_amount per child per night'");
        $add('rooms', 'child_charge_amount', "DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'Fixed MWK per child per night (fixed mode); 0 = free'");
        $add('rooms', 'free_children', "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The first N children per room are free'");
        $add('rooms', 'max_children', "INT UNSIGNED NULL DEFAULT NULL COMMENT 'Most children per room; NULL = no limit beyond max guests'");

        // Individual rooms: NULL = inherit from the room type.
        $add('individual_rooms', 'child_charge_mode', "VARCHAR(10) NULL DEFAULT NULL COMMENT 'NULL = inherit from room type'");
        $add('individual_rooms', 'child_charge_amount', "DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'NULL = inherit from room type'");
        $add('individual_rooms', 'free_children', "INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = inherit from room type'");
        $add('individual_rooms', 'max_children', "INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = inherit from room type'");

        // Bookings: the rule the booking was priced with.
        $add('bookings', 'child_charge_mode', "VARCHAR(10) NOT NULL DEFAULT 'percent' COMMENT 'Rule the child supplement was priced with'");
        $add('bookings', 'child_charge_amount', "DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'Fixed MWK per child per night when the booking was priced'");
        $add('bookings', 'free_children', "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Free children per room when the booking was priced'");
    },
];
