<?php

/**
 * 070 - the staff Assistant (chat helper in the admin portal): foundation tables, OFF by default.
 *
 * Additive only (owner approved 2026-10-26, .claude/AI_ASSISTANT_PLAN.md Phase 1). Nothing here
 * switches the Assistant on: the module row is seeded with is_enabled = 0 and the setting
 * ai_enabled = '0', and includes/ai/ai-core.php rh_ai_enabled() is fail-closed (it needs BOTH).
 * Existing settings are never overwritten (INSERT IGNORE), so re-running is safe.
 *
 *   ai_providers      one row per provider: encrypted key (ai1:...), last 4 characters, last test result
 *   ai_models         models offered by a provider (catalog + live list), chosen ones and their role
 *   ai_conversations  one chat thread per person
 *   ai_messages       messages in a thread (tool results truncated to 8 KB by the code)
 *   ai_actions        action cards the Assistant proposed and what happened to them
 *   ai_user_prefs     each person's reply preferences (tone, length, language, nickname, briefing)
 *   ai_usage_daily    requests / tokens / estimated cost per day, person, provider and model
 */

return [
    'name' => 'ai_assistant',

    'check' => function (PDO $pdo): bool {
        foreach (['ai_providers', 'ai_models', 'ai_conversations', 'ai_messages', 'ai_actions', 'ai_user_prefs', 'ai_usage_daily'] as $t) {
            if ($pdo->query("SHOW TABLES LIKE '" . $t . "'")->rowCount() === 0) {
                return false;
            }
        }
        $s = $pdo->query("SELECT COUNT(*) FROM site_settings WHERE setting_key = 'ai_enabled'");
        return (int)$s->fetchColumn() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_providers` (
            `provider` VARCHAR(20) NOT NULL,
            `api_key_enc` TEXT NULL,
            `key_last4` VARCHAR(8) NULL DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'none',
            `last_test_at` DATETIME NULL DEFAULT NULL,
            `last_test_ms` INT UNSIGNED NULL DEFAULT NULL,
            `last_test_message` VARCHAR(255) NULL DEFAULT NULL,
            `updated_by` INT UNSIGNED NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`provider`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: provider keys (encrypted) and last connection test'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_models` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `provider` VARCHAR(20) NOT NULL,
            `model_id` VARCHAR(120) NOT NULL,
            `label` VARCHAR(160) NOT NULL DEFAULT '',
            `released_at` DATE NULL DEFAULT NULL,
            `context_tokens` INT UNSIGNED NULL DEFAULT NULL,
            `price_in_per_mtok` DECIMAL(10,4) NULL DEFAULT NULL,
            `price_out_per_mtok` DECIMAL(10,4) NULL DEFAULT NULL,
            `price_edited` TINYINT(1) NOT NULL DEFAULT 0,
            `capability_rank` DECIMAL(8,3) NOT NULL DEFAULT 0,
            `source` VARCHAR(10) NOT NULL DEFAULT 'catalog',
            `is_new` TINYINT(1) NOT NULL DEFAULT 0,
            `is_selected` TINYINT(1) NOT NULL DEFAULT 0,
            `role` VARCHAR(10) NULL DEFAULT NULL,
            `sort` INT NOT NULL DEFAULT 0,
            `last_seen_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ai_models_provider_model` (`provider`, `model_id`),
            KEY `idx_ai_models_selected` (`provider`, `is_selected`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: models a provider offers, the chosen ones and their role'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_conversations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(160) NOT NULL DEFAULT '',
            `page_path` VARCHAR(120) NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `archived` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_ai_conv_user` (`user_id`, `archived`, `updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: chat threads, one owner each'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_messages` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` INT UNSIGNED NOT NULL,
            `role` VARCHAR(12) NOT NULL,
            `agent` VARCHAR(40) NULL DEFAULT NULL,
            `content` MEDIUMTEXT NULL,
            `tool_name` VARCHAR(80) NULL DEFAULT NULL,
            `tool_args_json` TEXT NULL,
            `tool_result_json` TEXT NULL,
            `provider` VARCHAR(20) NULL DEFAULT NULL,
            `model_id` VARCHAR(120) NULL DEFAULT NULL,
            `tokens_in` INT UNSIGNED NOT NULL DEFAULT 0,
            `tokens_out` INT UNSIGNED NOT NULL DEFAULT 0,
            `cost_usd` DECIMAL(12,6) NOT NULL DEFAULT 0,
            `latency_ms` INT UNSIGNED NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ai_msg_conv` (`conversation_id`, `id`),
            KEY `idx_ai_msg_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: messages in a chat thread'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_actions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `action_key` VARCHAR(60) NOT NULL,
            `params_json` TEXT NULL,
            `preview_json` TEXT NULL,
            `status` ENUM('proposed','confirmed','executed','failed','cancelled','expired') NOT NULL DEFAULT 'proposed',
            `client_uuid` CHAR(36) NULL DEFAULT NULL,
            `result_json` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NULL DEFAULT NULL,
            `executed_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ai_actions_uuid` (`client_uuid`),
            KEY `idx_ai_actions_user` (`user_id`, `status`, `created_at`),
            KEY `idx_ai_actions_conv` (`conversation_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: proposed action cards and their outcome (a person always confirms)'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_user_prefs` (
            `user_id` INT UNSIGNED NOT NULL,
            `prefs_json` TEXT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: each person''s reply preferences'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_usage_daily` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `day` DATE NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `provider` VARCHAR(20) NOT NULL,
            `model_id` VARCHAR(120) NOT NULL,
            `requests` INT UNSIGNED NOT NULL DEFAULT 0,
            `tokens_in` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `tokens_out` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `cost_usd` DECIMAL(12,6) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ai_usage_day` (`day`, `user_id`, `provider`, `model_id`),
            KEY `idx_ai_usage_user_day` (`user_id`, `day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: requests, tokens and estimated cost per day, person and model'");

        // Module row OFF. Only when the modules table already exists: creating it here would stop
        // moduleEnabled() from seeding the other modules on a fresh install. Without the row the
        // Assistant simply stays off (rh_ai_enabled() is fail-closed); the settings page adds it.
        if ($pdo->query("SHOW TABLES LIKE 'enabled_modules'")->rowCount() > 0) {
            $pdo->prepare("INSERT IGNORE INTO enabled_modules (module_key, module_name, description, is_enabled, sort_order) VALUES (?, ?, ?, 0, ?)")
                ->execute(['ai_assistant', 'Assistant', 'Staff chat helper in the admin portal (off until set up in Assistant Settings)', 90]);
        }

        // Settings (group 'ai'), never overwriting a value already there.
        $set = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai')");
        foreach ([
            'ai_enabled'              => '0',
            'ai_provider'             => '',
            'ai_user_daily_token_cap' => '200000',
            'ai_monthly_budget_usd'   => '20',
            'ai_rate_limit_per_10min' => '30',
            'ai_retention_days'       => '0',
        ] as $k => $v) {
            $set->execute([$k, $v]);
        }
    },
];
