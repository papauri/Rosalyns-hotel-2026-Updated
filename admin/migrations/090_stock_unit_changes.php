<?php

/**
 * 090 - "Change main unit" per stock item (owner 2026-11-19, Step U3: switch an item between bottle and shot with
 * Preview + Confirm; everything using the item rescales at once).
 *
 *   stock_unit_changes   one row per change: the item, unit before -> after, the count measure (N and the side that became
 *                        the stock unit), stock value before -> after, who and when, and last_adjustment_id = the item's
 *                        newest stock_adjustments id at the change. Stock movements recorded up to that id are in the OLD
 *                        unit: a later restore of one of them (void, refund, KDS recall) is rescaled with this row
 *                        (restoreStockForMenuItem). details = what changed, place by place, old -> new (JSON).
 *
 * Additive only (one new table). Re-running is safe. Until this runs "Change main unit" stays hidden
 * (rh_stock_unit_changes_ready() is false) and nothing else changes.
 */

return [
    'name' => 'stock_unit_changes',

    'check' => function (PDO $pdo): bool {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_unit_changes'");
        return (int)$st->fetchColumn() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_unit_changes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ingredient_id INT UNSIGNED NOT NULL,
            old_unit VARCHAR(50) NOT NULL,
            new_unit VARCHAR(50) NOT NULL,
            measure_n DECIMAL(12,4) NOT NULL COMMENT 'Smaller measures in one bigger (the item measure at the change)',
            new_side ENUM('big','small') NOT NULL COMMENT 'small = quantities multiplied by N, big = divided by N',
            last_adjustment_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Newest stock_adjustments id of the item at the change (old unit up to here)',
            value_before DECIMAL(16,4) NOT NULL DEFAULT 0,
            value_after DECIMAL(16,4) NOT NULL DEFAULT 0,
            details MEDIUMTEXT NULL COMMENT 'JSON: every place that changed, old -> new',
            changed_by INT UNSIGNED NULL,
            changed_at DATETIME NOT NULL,
            KEY idx_suc_ingredient (ingredient_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
];
