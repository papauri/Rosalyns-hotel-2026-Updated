<?php

/**
 * 069 - `stock_in_log.category` + `stock_in_log.received_date`.
 *
 * The stock budget reads goods-in from stock_in_log. It used the ingredient's CURRENT category (so
 * recategorising an item, or renaming a category, moved closed months' spend) and the row's
 * created_at (so goods delivered on the 31st but entered on the 2nd counted in the wrong month).
 * Each receipt now carries the category the item had when it came in and the delivery date.
 *
 * Additive and non-destructive: two nullable columns + an index on received_date; existing rows are
 * backfilled (category = the ingredient's current category; received_date = the linked batch's
 * received_date, else DATE(created_at)). Only NULL cells are ever written, so it is safe to run twice
 * and never overwrites a value the application has already recorded. The category column copies the
 * type/length of stock_ingredients.category.
 */

function rh_mig069_category_type(PDO $pdo): string
{
    $t = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_ingredients' AND COLUMN_NAME = 'category'")->fetchColumn();
    $t = is_string($t) ? trim($t) : '';
    // Only a plain varchar/char type is copied; anything unexpected falls back to the usual width.
    return preg_match('/^(var)?char\(\d{1,4}\)$/i', $t) ? $t : 'VARCHAR(100)';
}

function rh_mig069_has_column(PDO $pdo, string $col): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_in_log' AND COLUMN_NAME = ?");
    $st->execute([$col]);
    return (int)$st->fetchColumn() > 0;
}

return [
    'name' => 'stock_in_log_category_received_date',

    'check' => function (PDO $pdo): bool {
        if (!rh_mig069_has_column($pdo, 'category') || !rh_mig069_has_column($pdo, 'received_date')) {
            return false;
        }
        $idx = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_in_log' AND INDEX_NAME = 'idx_stockin_received_date'")->fetchColumn();
        if ($idx === 0) {
            return false;
        }
        // Applied only once nothing is left to backfill.
        return (int)$pdo->query("SELECT COUNT(*) FROM stock_in_log WHERE received_date IS NULL AND created_at IS NOT NULL")->fetchColumn() === 0
            && (int)$pdo->query("SELECT COUNT(*) FROM stock_in_log l INNER JOIN stock_ingredients i ON i.id = l.ingredient_id
                WHERE l.category IS NULL AND i.category IS NOT NULL")->fetchColumn() === 0;
    },

    'up' => function (PDO $pdo): void {
        if (!rh_mig069_has_column($pdo, 'category')) {
            $pdo->exec("ALTER TABLE `stock_in_log` ADD COLUMN `category` " . rh_mig069_category_type($pdo)
                . " NULL DEFAULT NULL COMMENT 'Item category when the stock came in (budget grouping)'");
        }
        if (!rh_mig069_has_column($pdo, 'received_date')) {
            $pdo->exec("ALTER TABLE `stock_in_log` ADD COLUMN `received_date` DATE NULL DEFAULT NULL COMMENT 'Delivery date (budget month)'");
        }
        $idx = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_in_log' AND INDEX_NAME = 'idx_stockin_received_date'")->fetchColumn();
        if ($idx === 0) {
            $pdo->exec("ALTER TABLE `stock_in_log` ADD INDEX `idx_stockin_received_date` (`received_date`)");
        }

        // Backfill (NULL cells only): delivery date from the linked batch, else the day the row was written.
        $pdo->exec("UPDATE stock_in_log l
            LEFT JOIN stock_batches b ON b.id = l.batch_id
            SET l.received_date = COALESCE(b.received_date, DATE(l.created_at))
            WHERE l.received_date IS NULL");
        // Category as it is today (the best that can be known for rows written before this column existed).
        $pdo->exec("UPDATE stock_in_log l
            INNER JOIN stock_ingredients i ON i.id = l.ingredient_id
            SET l.category = i.category
            WHERE l.category IS NULL AND i.category IS NOT NULL");
    },
];
