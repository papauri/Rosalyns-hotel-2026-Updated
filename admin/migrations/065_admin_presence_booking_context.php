<?php

/**
 * 065 - "someone else is booking" banner: `admin_presence.booking_context`.
 *
 * Additive only (owner approved 2026-10-22: be open with staff who can make bookings that
 * someone else is also making a booking). A tiny JSON note kept on the person's own presence row
 * while their Create Booking tab is open: {"ci":"2026-11-12","co":"2026-11-14","rooms":[3,5],"since":1766400000}
 * (check-in, check-out, chosen room type ids, when they opened the page). No guest details are
 * ever stored here. Other staff who can open Create Booking read it to show the banner and to
 * tell whether the two bookings overlap. NULL for everyone not on that screen.
 */

return [
    'name' => 'admin_presence_booking_context',

    'check' => function (PDO $pdo): bool {
        return $pdo->query("SHOW COLUMNS FROM `admin_presence` LIKE 'booking_context'")->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE `admin_presence` ADD COLUMN `booking_context` VARCHAR(255) NULL DEFAULT NULL AFTER `user_agent_short`");
    },
];
