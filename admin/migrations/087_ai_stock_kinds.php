<?php

/**
 * 087 - Assistant: stock name check (owner 2026-11-17, spec .claude/AI_STOCK_HELPER_SPEC.md D7 + D14).
 *
 *   ai_stock_kinds   what the item names were read as: one row per (item name + unit, source). source 'model' = the two-model
 *                    check (expires), 'person' = taught by an administrator or manager (never expires, always wins).
 *                    Product names only: no guest, staff or supplier data, so no vault is needed.
 *   site_settings    the helper's settings (group 'ai'), the switch OFF. INSERT IGNORE: a value already set is never changed.
 *
 * Additive only. Re-running is safe. Until this runs the helper stays hidden everywhere (rh_ai_sk_ready() is false).
 * 'sql' is read by scripts/tests/ai_stock_helper_test.php to build the same table as TEMPORARY (no schema change in tests).
 */

$rhSk087Sql = "CREATE TABLE IF NOT EXISTS `ai_stock_kinds` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_key` CHAR(64) NOT NULL COMMENT 'sha256 of the normalised item name | unit',
    `name_norm` VARCHAR(190) NOT NULL,
    `unit` VARCHAR(30) NOT NULL DEFAULT '',
    `type` VARCHAR(20) NULL,
    `sale` VARCHAR(8) NULL,
    `unit_ml` INT NULL,
    `pack_n` SMALLINT UNSIGNED NULL,
    `judged_cost` DECIMAL(14,4) NULL,
    `one_unit` TINYINT(1) NULL COMMENT '1 = price of one, 0 = crate price, NULL = unclear',
    `conf` VARCHAR(6) NULL,
    `agree` VARCHAR(6) NULL COMMENT 'both / single / split / none',
    `source` VARCHAR(6) NOT NULL COMMENT 'model / person',
    `models` VARCHAR(160) NULL,
    `person_id` INT NULL,
    `why` VARCHAR(80) NULL,
    `detail` VARCHAR(255) NULL COMMENT 'JSON: sizes offered, use-all, state',
    `updated_at` DATETIME NOT NULL,
    `expires_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ai_sk_name_source` (`name_key`, `source`),
    KEY `idx_ai_sk_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$rhSk087Settings = [
    'ai_stock_helper_enabled' => '0',
    'ai_stock_two_models'     => '1',
    'ai_stock_max_items'      => '200',
    'ai_stock_max_chunks'     => '5',
    'ai_stock_timeout_s'      => '45',
    'ai_stock_cache_days'     => '180',
    'ai_stock_anchor_max'     => '60',
    'ai_stock_tot_ml'         => '25',
    'ai_stock_glass_ml'       => '175',
    'ai_stock_bottle_ml'      => '750',
    'ai_stock_prefill_enabled' => '0',
];

$rhSk087Has = static function (PDO $pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_stock_kinds'");
    $st->execute();
    return (int)$st->fetchColumn() > 0;
};

return [
    'name' => 'ai_stock_kinds',
    'sql' => $rhSk087Sql,

    'check' => function (PDO $pdo) use ($rhSk087Has, $rhSk087Settings): bool {
        if (!$rhSk087Has($pdo)) {
            return false;
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM site_settings WHERE setting_key = 'ai_stock_helper_enabled'");
        $st->execute();
        return (int)$st->fetchColumn() > 0;
    },

    'up' => function (PDO $pdo) use ($rhSk087Sql, $rhSk087Has, $rhSk087Settings): void {
        if (!$rhSk087Has($pdo)) {
            $pdo->exec($rhSk087Sql);
            echo "    ai_stock_kinds: created\n";
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai')");
        foreach ($rhSk087Settings as $k => $v) {
            $ins->execute([$k, $v]);
        }
        echo "    ai_stock_* settings: seeded (the stock name check stays OFF)\n";
    },
];
