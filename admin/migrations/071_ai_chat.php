<?php

/**
 * 071 - the staff Assistant, Phase 2 (chat core): the privacy vault and the short-lived answer cache.
 *
 * Additive only (.claude/AI_ASSISTANT_PLAN.md "Local-first data" + "Owner direction"). Nothing here
 * switches the Assistant on (rh_ai_enabled() in includes/ai/ai-core.php stays fail-closed).
 *
 *   ai_vault       per conversation: the real guest/staff name, email, phone or booking reference behind
 *                  each token ([GUEST_7], [EMAIL_7], [BK_31], [STAFF_2]). The provider only ever sees the
 *                  token; replies are turned back into real values here, on the hotel's own server.
 *   ai_tool_cache  read-tool results and repeated answers kept for a minute or two per person and
 *                  permission set, so the same question asked twice costs nothing the second time.
 */

return [
    'name' => 'ai_chat',

    'check' => function (PDO $pdo): bool {
        foreach (['ai_vault', 'ai_tool_cache'] as $t) {
            if ($pdo->query("SHOW TABLES LIKE '" . $t . "'")->rowCount() === 0) {
                return false;
            }
        }
        return true;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_vault` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` INT UNSIGNED NOT NULL,
            `kind` VARCHAR(10) NOT NULL,
            `num` INT UNSIGNED NOT NULL,
            `value_text` VARCHAR(500) NOT NULL,
            `value_hash` CHAR(64) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ai_vault_token` (`conversation_id`, `kind`, `num`),
            KEY `idx_ai_vault_value` (`conversation_id`, `value_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: real value behind each privacy token, per conversation (never sent to a provider)'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_tool_cache` (
            `cache_key` CHAR(64) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `result_json` MEDIUMTEXT NOT NULL,
            `expires_at` DATETIME NOT NULL,
            PRIMARY KEY (`cache_key`),
            KEY `idx_ai_cache_expiry` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: short-lived cache of read results and repeated answers per person'");
    },
];
