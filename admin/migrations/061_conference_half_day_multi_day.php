<?php

/**
 * 061 - conference half-day and multi-day pricing: `conference_rooms.half_day_rate`,
 * `conference_inquiries.end_date`.
 *
 * Additive only (owner decision 2026-10-08). Until now every enquiry was quoted one day at
 * `daily_rate` whatever its length. `half_day_rate` NULL = the room has no half-day price
 * (short events then pay the daily rate); `end_date` NULL = a single-day event on `event_date`.
 */

return [
    'name' => 'conference_half_day_multi_day',

    'check' => function (PDO $pdo): bool {
        return $pdo->query("SHOW COLUMNS FROM conference_rooms LIKE 'half_day_rate'")->rowCount() > 0
            && $pdo->query("SHOW COLUMNS FROM conference_inquiries LIKE 'end_date'")->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        if ($pdo->query("SHOW COLUMNS FROM conference_rooms LIKE 'half_day_rate'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE conference_rooms ADD COLUMN `half_day_rate` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Price for an event of up to 5 hours on one day (NULL = no half-day price)' AFTER `daily_rate`");
        }
        if ($pdo->query("SHOW COLUMNS FROM conference_inquiries LIKE 'end_date'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE conference_inquiries ADD COLUMN `end_date` DATE NULL DEFAULT NULL COMMENT 'Last day of a multi-day event (NULL = single day on event_date)' AFTER `event_date`");
        }
    },
];
