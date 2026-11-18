<?php

/**
 * 088 - stock counted in a smaller or bigger measure (owner 2026-11-18, Step U1: "they count per shot that make up a
 * bottle ... a glass to make up a juice bottle"; "The bar count will be mixed. Manager should be able to decide.").
 *
 *   stock_ingredients   measure_big_name / measure_small_name ("bottle" / "shot"), measure_per_big (N shots in a bottle),
 *                       measure_stock_side ('big' or 'small': which of the two IS the item's stock unit). All NULL = no measure.
 *   stock_count_lines   entry_mode ('stock' | 'big' | 'small' | 'both'), entry_big, entry_small, entry_per_big: what the counter
 *                       typed and the N used. actual_quantity stays the stock-unit figure (6 dp).
 *   site_settings       stock_measure_shot_ml (25) and stock_measure_glass_ml (175), only used to SUGGEST N from a size in the
 *                       item name. INSERT IGNORE: a value already set is never changed.
 *
 * Additive only. Re-running is safe. Until this runs the measure fields stay hidden (rh_stock_measure_ready() is false).
 */

$rhSm088Cols = [
    'stock_ingredients' => [
        'measure_big_name'   => "VARCHAR(30) NULL DEFAULT NULL COMMENT 'Bigger count measure, e.g. bottle'",
        'measure_small_name' => "VARCHAR(30) NULL DEFAULT NULL COMMENT 'Smaller count measure, e.g. shot'",
        'measure_per_big'    => "DECIMAL(12,4) NULL DEFAULT NULL COMMENT 'How many small measures make one big measure'",
        'measure_stock_side' => "ENUM('big','small') NULL DEFAULT NULL COMMENT 'Which measure is the stock unit'",
    ],
    'stock_count_lines' => [
        'entry_mode'    => "VARCHAR(10) NULL DEFAULT NULL COMMENT 'stock / big / small / both: how the reading was typed'",
        'entry_big'     => "DECIMAL(16,6) NULL DEFAULT NULL COMMENT 'Big measures typed'",
        'entry_small'   => "DECIMAL(16,6) NULL DEFAULT NULL COMMENT 'Small measures typed'",
        'entry_per_big' => "DECIMAL(12,4) NULL DEFAULT NULL COMMENT 'Small measures per big measure used for the reading'",
    ],
];

$rhSm088Has = static function (PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
};

return [
    'name' => 'stock_count_measures',

    'check' => function (PDO $pdo) use ($rhSm088Cols, $rhSm088Has): bool {
        foreach ($rhSm088Cols as $table => $cols) {
            foreach (array_keys($cols) as $col) {
                if (!$rhSm088Has($pdo, $table, $col)) {
                    return false;
                }
            }
        }
        $st = $pdo->query("SELECT COUNT(*) FROM site_settings WHERE setting_key IN ('stock_measure_shot_ml', 'stock_measure_glass_ml')");
        return (int)$st->fetchColumn() >= 2;
    },

    'up' => function (PDO $pdo) use ($rhSm088Cols, $rhSm088Has): void {
        foreach ($rhSm088Cols as $table => $cols) {
            foreach ($cols as $col => $def) {
                if (!$rhSm088Has($pdo, $table, $col)) {
                    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
                }
            }
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'stock')");
        $ins->execute(['stock_measure_shot_ml', '25']);
        $ins->execute(['stock_measure_glass_ml', '175']);
    },
];
