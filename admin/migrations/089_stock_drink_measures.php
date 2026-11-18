<?php

/**
 * 089 - drink measures everywhere (owner 2026-11-19, Step U2 a + b: "drink is 1litre and is entered as 1 litre in stock it
 * means 1 shot can be 30ml ... one full bottle is X shots and even better for cocktails or in menus when entering").
 *
 *   site_settings             stock_measures_list: the hotel's own list of drink measures as JSON
 *                             [{"name":"shot","ml":25}, ...]. Seeded once from stock_measure_shot_ml / stock_measure_glass_ml
 *                             (088). INSERT IGNORE: a list already there is never changed. The hotel edits it on Stock Items.
 *   stock_ingredients         volume_ml: size of one bottle / container in ml (NULL = not known; the size in the name is used).
 *   stock_recipe_ingredients  entry_measure / entry_qty / entry_ml: what was typed on a recipe line ("2" "shot", 25 ml each),
 *                             for display only. quantity_per_portion stays the stock-unit figure every sale deducts.
 *
 * Additive only. Re-running is safe. Until this runs the measure pickers stay hidden (rh_stock_drink_ready() is false).
 */

$rhDm089Cols = [
    'stock_ingredients' => [
        'volume_ml' => "DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Size of one bottle or container in ml (drink measures)'",
    ],
    'stock_recipe_ingredients' => [
        'entry_measure' => "VARCHAR(30) NULL DEFAULT NULL COMMENT 'Measure the line was typed in, e.g. shot, ml'",
        'entry_qty'     => "DECIMAL(12,4) NULL DEFAULT NULL COMMENT 'How many of that measure were typed'",
        'entry_ml'      => "DECIMAL(12,4) NULL DEFAULT NULL COMMENT 'ml of one measure when it was typed'",
    ],
];

$rhDm089Has = static function (PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
};

return [
    'name' => 'stock_drink_measures',

    'check' => function (PDO $pdo) use ($rhDm089Cols, $rhDm089Has): bool {
        foreach ($rhDm089Cols as $table => $cols) {
            foreach (array_keys($cols) as $col) {
                if (!$rhDm089Has($pdo, $table, $col)) {
                    return false;
                }
            }
        }
        $st = $pdo->query("SELECT COUNT(*) FROM site_settings WHERE setting_key = 'stock_measures_list'");
        return (int)$st->fetchColumn() >= 1;
    },

    'up' => function (PDO $pdo) use ($rhDm089Cols, $rhDm089Has): void {
        foreach ($rhDm089Cols as $table => $cols) {
            foreach ($cols as $col => $def) {
                if (!$rhDm089Has($pdo, $table, $col)) {
                    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
                }
            }
        }
        // Seed sizes: the hotel's own shot and glass from 088 when set; the usual sizes otherwise. Only here, never in code paths.
        $get = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $read = static function (string $key, float $fallback) use ($get): float {
            $get->execute([$key]);
            $v = $get->fetchColumn();
            return (is_numeric($v) && (float)$v > 0 && (float)$v <= 5000) ? round((float)$v, 2) : $fallback;
        };
        $shot = $read('stock_measure_shot_ml', 25.0);
        $glass = $read('stock_measure_glass_ml', 175.0);
        $list = [
            ['name' => 'shot', 'ml' => $shot],
            ['name' => 'double', 'ml' => round($shot * 2, 2)],
            ['name' => 'glass of wine', 'ml' => $glass],
            ['name' => 'cocktail pour', 'ml' => round($shot * 2, 2)],
            ['name' => 'cup', 'ml' => 250.0],
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'stock')");
        $ins->execute(['stock_measures_list', json_encode($list)]);
    },
];
