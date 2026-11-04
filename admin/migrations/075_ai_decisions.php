<?php

/**
 * 075 - the in-page Decisions panels, Phase 1 (shared engine): the daily cache of decision cards per area, the proof
 * log (what was found, what was done about it, who approved it, what came of it) and the one daily brief per person.
 *
 * Additive only (.claude/AI_REVIEW_PANELS_PARKED.md, owner go 2026-11-05). Nothing here switches anything on and
 * existing settings are never overwritten (INSERT IGNORE), so re-running is safe. Before this has run the panels and
 * the brief print nothing.
 *
 *   ai_review_cache  one row per area per day: the cards worked out by the fixed checks (SQL), the hash of the figures
 *                    behind them and, when a model worded them, that wording (tied to the hash). Old days are removed
 *                    after ai_review_keep_days.
 *   ai_review_proof  append-only: one row per thing done about a card (noted, dismissed, approved, ran by itself) and
 *                    its outcome when known. Never edited except to record the outcome once.
 *   ai_review_brief  one brief per person per day (counts, titles and amounts only, no guest names).
 */

return [
    'name' => 'ai_decisions',

    'check' => function (PDO $pdo): bool {
        foreach (['ai_review_cache', 'ai_review_proof', 'ai_review_brief'] as $t) {
            if ($pdo->query("SHOW TABLES LIKE '" . $t . "'")->rowCount() === 0) {
                return false;
            }
        }
        $s = $pdo->query("SELECT COUNT(*) FROM site_settings WHERE setting_key = 'ai_review_impact_min_pct'");
        return (int)$s->fetchColumn() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_review_cache` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `area` VARCHAR(20) NOT NULL,
            `cache_day` DATE NOT NULL,
            `data_hash` CHAR(64) NOT NULL,
            `cards_json` MEDIUMTEXT NOT NULL,
            `checks_json` TEXT NULL DEFAULT NULL,
            `wording_json` MEDIUMTEXT NULL DEFAULT NULL,
            `wording_hash` CHAR(64) NULL DEFAULT NULL,
            `worded_model` VARCHAR(120) NULL DEFAULT NULL,
            `built_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `worded_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ai_review_cache_area_day` (`area`, `cache_day`),
            KEY `idx_ai_review_cache_day` (`cache_day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Decisions panels: cards per area per day (fixed checks) and their wording'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_review_proof` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `area` VARCHAR(20) NOT NULL,
            `card_key` VARCHAR(80) NOT NULL,
            `card_day` DATE NOT NULL,
            `decision` VARCHAR(255) NOT NULL,
            `at_stake` DECIMAL(14,2) NULL DEFAULT NULL,
            `tier` TINYINT UNSIGNED NOT NULL DEFAULT 3,
            `action` VARCHAR(20) NOT NULL,
            `option_label` VARCHAR(160) NULL DEFAULT NULL,
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `approved_by` INT UNSIGNED NULL DEFAULT NULL,
            `outcome` VARCHAR(20) NULL DEFAULT NULL,
            `outcome_note` VARCHAR(255) NULL DEFAULT NULL,
            `outcome_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ai_review_proof_card` (`card_key`, `card_day`),
            KEY `idx_ai_review_proof_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Decisions panels: proof log (finding, action, who approved, outcome)'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_review_brief` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `brief_day` DATE NOT NULL,
            `brief_json` MEDIUMTEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ai_review_brief_user_day` (`user_id`, `brief_day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Today''s brief: one per person per day (counts, titles, amounts; no guest names)'");

        // Settings (group 'ai'), never overwriting a value already there. Same defaults as rh_ai_setting_defaults().
        $set = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai')");
        foreach ([
            'ai_review_impact_min_pct'   => '1',   // a card shows when its money at stake is at least this % of the money collected in the base period
            'ai_review_base_days'        => '30',  // the base period (days back) for "share of takings" and for the money checks
            'ai_review_cards_max'        => '8',   // cards shown at most per panel (the rest under "More")
            'ai_review_history_min_days' => '28',  // a projected range needs at least this many days of history, else it is not shown
            'ai_review_writer_enabled'   => '1',   // '0' = cards keep their plain fixed wording (no model call)
            'ai_review_writer_tokens'    => '900', // most words (tokens) the wording call may send back
            'ai_review_refresh_minutes'  => '10',  // the Refresh button rebuilds an area at most once in this many minutes
            'ai_review_keep_days'        => '35',  // cached days and briefs older than this are removed
        ] as $k => $v) {
            $set->execute([$k, $v]);
        }
    },
];
