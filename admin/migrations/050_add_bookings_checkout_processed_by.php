<?php

/**
 * 050 - add `bookings.checkout_processed_by`.
 *
 * Additive only. generateAndSendFinalInvoice() (config/invoice.php) stamps the admin who processed
 * checkout into this column, but nothing ever created it, so the final-invoice UPDATE failed with
 * "Unknown column" and every checkout through processGuestCheckout() aborted.
 */

return [
    'name' => 'add_bookings_checkout_processed_by',

    'check' => function (PDO $pdo): bool {
        $stmt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'checkout_processed_by'");
        return $stmt->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN checkout_processed_by INT UNSIGNED NULL DEFAULT NULL COMMENT 'Admin user who processed checkout / final invoice'");
    },
];
