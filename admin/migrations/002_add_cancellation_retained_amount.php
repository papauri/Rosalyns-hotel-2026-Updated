<?php

/**
 * 002 — add `bookings.cancellation_retained_amount`.
 *
 * Additive only. Cancelling a booking no longer rewrites its original totals: the room
 * charge kept after cancellation (first night under the 'keep_first_night' mode, 0/NULL
 * when the bill is voided) is stored here, and recalculateBookingFinancials() bills this
 * amount instead of total_with_vat for bookings whose status is 'cancelled'.
 */

return [
    'name' => 'add_bookings_cancellation_retained_amount',

    'check' => function (PDO $pdo): bool {
        $stmt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'cancellation_retained_amount'");
        return $stmt->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN cancellation_retained_amount DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'Room charge retained on cancellation (NULL/0 = bill voided). Used as the room bill when status = cancelled.'");
    },
];
