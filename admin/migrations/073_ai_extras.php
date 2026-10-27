<?php

/**
 * 073 - the staff Assistant, Phases 4c + 5 (owner extras and polish): in-panel alerts, the tamper-evident audit
 * trail of confirmed cards, and the lost-demand log behind rate suggestions.
 *
 * Additive only (.claude/AI_ASSISTANT_PLAN.md "Phase 4c", "Phase 5"). Nothing here switches the Assistant on, and
 * existing settings are never overwritten (INSERT IGNORE), so re-running is safe. Before this has run every new
 * path is silent: alerts show nothing, cards are not sealed, guest searches are not logged.
 *
 *   ai_alerts   one row per person per finding per day (deduped): what needs them now, worked out from what their own
 *               account can see. Dismissed alerts stay dismissed for the day. Counts and titles only, no guest names.
 *   ai_audit    append-only hash chain over Assistant action cards: each confirm / result / cancel adds one link
 *               (keyed hash of the card row + the previous link). A hand-edited or deleted card breaks it.
 *   demand_log  guest website searches that found no room (lost demand): room type, dates, party size, why. No name,
 *               no email, no address; the visitor is a one-way hash used only to count each search once.
 */

return [
    'name' => 'ai_extras',

    'check' => function (PDO $pdo): bool {
        foreach (['ai_alerts', 'ai_audit', 'demand_log'] as $t) {
            if ($pdo->query("SHOW TABLES LIKE '" . $t . "'")->rowCount() === 0) {
                return false;
            }
        }
        $s = $pdo->query("SELECT COUNT(*) FROM site_settings WHERE setting_key = 'ai_idle_lock_minutes'");
        return (int)$s->fetchColumn() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_alerts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `alert_key` VARCHAR(80) NOT NULL,
            `kind` VARCHAR(12) NOT NULL DEFAULT 'time',
            `title` VARCHAR(200) NOT NULL,
            `detail` VARCHAR(400) NULL DEFAULT NULL,
            `link_url` VARCHAR(255) NULL DEFAULT NULL,
            `link_label` VARCHAR(80) NULL DEFAULT NULL,
            `item_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `score` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `dismissed_at` DATETIME NULL DEFAULT NULL,
            `resolved_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ai_alerts_user_key` (`user_id`, `alert_key`),
            KEY `idx_ai_alerts_open` (`user_id`, `resolved_at`, `dismissed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: alerts per person (deduped per day, from what their account can see)'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_audit` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `action_id` INT UNSIGNED NOT NULL,
            `status` VARCHAR(12) NOT NULL,
            `row_hash` CHAR(64) NOT NULL,
            `prev_hash` CHAR(64) NOT NULL,
            `chain_hash` CHAR(64) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ai_audit_action` (`action_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: append-only hash chain over confirmed / finished action cards'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `demand_log` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `room_type_id` INT UNSIGNED NOT NULL,
            `check_in` DATE NOT NULL,
            `check_out` DATE NOT NULL,
            `guests` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `reason` VARCHAR(20) NOT NULL DEFAULT 'unavailable',
            `visitor_hash` CHAR(16) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_demand_dates` (`check_in`, `room_type_id`),
            KEY `idx_demand_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Guest website searches that found no room (lost demand). No personal data.'");

        // Settings (group 'ai'), never overwriting a value already there.
        $set = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai')");
        foreach ([
            'ai_idle_lock_minutes' => '15',
            'ai_digest_enabled'    => '1',
            'ai_digest_sent_week'  => '',
            'ai_budget_warned'     => '',
        ] as $k => $v) {
            $set->execute([$k, $v]);
        }
    },
];
