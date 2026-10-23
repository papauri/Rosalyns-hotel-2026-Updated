<?php

/**
 * 068 - `stock_opening_batches` exists on every hotel, with a 6-decimal quantity.
 *
 * The table holds opening-stock batches waiting for an opening count to be approved. Until now it
 * was created on first use by admin/includes/stock-sheet-import-lib.php (rh_ssi_ensure_table), so a
 * hotel that had never imported a stock sheet had no table and migration 067 could not widen it.
 * Additive only: creates the table when it is missing (same columns as the lib, quantity widened to
 * DECIMAL(16,6) to match 067); where it already exists nothing is touched.
 */

return [
    'name' => 'stock_opening_batches_table',

    'check' => function (PDO $pdo): bool {
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opening_batches'")->fetchColumn() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS stock_opening_batches (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                count_id INT UNSIGNED NOT NULL,
                ingredient_id INT UNSIGNED NOT NULL,
                batch_label VARCHAR(50) DEFAULT NULL,
                quantity DECIMAL(16,6) NOT NULL DEFAULT 0,
                expiry_date DATE DEFAULT NULL,
                applied_batch_id INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sob_count (count_id, ingredient_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
];
