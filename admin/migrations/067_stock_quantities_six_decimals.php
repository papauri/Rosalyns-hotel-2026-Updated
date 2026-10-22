<?php

/**
 * 067 - stock QUANTITIES carry 6 decimals (was 4) + `stock_ingredients.pieces_per_unit`.
 *
 * Owner-approved 2026-10-23. One piece out of a packet of 6 is 0.166667 packet; at 4 decimals
 * (0.1667) six sales took 1.0002 packets off the shelf. Every quantity column a recipe deduction,
 * FIFO batch movement, receipt, wastage line or count can carry a fraction into is widened to
 * DECIMAL(16,6). Widening only: existing values are kept exactly (0.1667 stays 0.166700). Money
 * columns (cost_per_unit, cost_total, wastage_cost, variance_cost, ...) are NOT touched.
 *
 * `pieces_per_unit` DECIMAL(10,2) NULL: "how many pieces in one <unit>" (6 per packet; a dozen is
 * implied 12). Menu Management reads it so staff can type whole pieces.
 *
 * Idempotent: each column is only altered while it is still scale 4; nullability, default and
 * comment are carried over from information_schema.
 */

const RH_MIG067_COLUMNS = [
    'stock_ingredients'          => ['current_quantity', 'min_quantity', 'reorder_point', 'par_level'],
    'stock_recipe_ingredients'   => ['quantity_per_portion'],
    'stock_batches'              => ['quantity_received', 'quantity_remaining'],
    'stock_batch_deductions'     => ['quantity_deducted'],
    'stock_opening_batches'      => ['quantity'],
    'stock_adjustments'          => ['quantity_change'],
    'stock_in_log'               => ['quantity'],
    'stock_wastage'              => ['quantity'],
    'stock_count_lines'          => ['system_quantity', 'actual_quantity', 'variance'],
    'stock_purchase_order_items' => ['ordered_qty', 'received_qty'],
];

function rh_mig067_pending(PDO $pdo): array
{
    $pending = [];
    $stmt = $pdo->prepare("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT, NUMERIC_SCALE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    foreach (RH_MIG067_COLUMNS as $table => $cols) {
        foreach ($cols as $col) {
            $stmt->execute([$table, $col]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['NUMERIC_SCALE'] < 6) {
                $pending[] = [$table, $col, $row];
            }
        }
    }
    return $pending;
}

return [
    'name' => 'stock_quantities_six_decimals',

    'check' => function (PDO $pdo): bool {
        return rh_mig067_pending($pdo) === []
            && $pdo->query("SHOW COLUMNS FROM `stock_ingredients` LIKE 'pieces_per_unit'")->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        foreach (rh_mig067_pending($pdo) as [$table, $col, $row]) {
            $null = $row['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
            $default = '';
            // MariaDB reports a NULL default as the text 'NULL', MySQL as a real null: neither is a number.
            $hasDefault = $row['COLUMN_DEFAULT'] !== null && strtoupper(trim((string)$row['COLUMN_DEFAULT'])) !== 'NULL';
            if ($hasDefault && is_numeric($row['COLUMN_DEFAULT'])) {
                $default = ' DEFAULT ' . number_format((float)$row['COLUMN_DEFAULT'], 6, '.', '');
            } elseif (!$hasDefault && $row['IS_NULLABLE'] === 'YES') {
                $default = ' DEFAULT NULL';
            }
            $comment = $row['COLUMN_COMMENT'] !== '' ? ' COMMENT ' . $pdo->quote($row['COLUMN_COMMENT']) : '';
            $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$col` DECIMAL(16,6) $null$default$comment");
        }
        if ($pdo->query("SHOW COLUMNS FROM `stock_ingredients` LIKE 'pieces_per_unit'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `stock_ingredients` ADD COLUMN `pieces_per_unit` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'How many pieces in one unit (6 per packet); NULL = not set, dozen implied 12'");
        }
    },
];
