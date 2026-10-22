<?php

/**
 * 066 - optional name per room in a group booking: `bookings.room_guest_name`.
 *
 * Additive only (owner request 2026-10-23). The lead booker stays the booking's guest
 * (guest_name / email / phone, billing unchanged); each room of a group booking may carry the
 * name of the person who sleeps there, e.g. "Mr & Mrs Banda". NULL when no name was given.
 */

return [
    'name' => 'bookings_room_guest_name',

    'check' => function (PDO $pdo): bool {
        return $pdo->query("SHOW COLUMNS FROM `bookings` LIKE 'room_guest_name'")->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE `bookings` ADD COLUMN `room_guest_name` VARCHAR(150) NULL DEFAULT NULL AFTER `guest_name`");
    },
];
