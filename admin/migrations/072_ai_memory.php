<?php

/**
 * 072 - the staff Assistant, Phase 4b (learning loop + self-healing): its local memory, the mistakes it
 * learns from, staff feedback, the nightly checks and the fault list for the owner.
 *
 * Additive only (.claude/AI_ASSISTANT_PLAN.md "Learning loop", "Self-healing", "Continuous train & test").
 * Nothing here switches the Assistant on (rh_ai_enabled() in includes/ai/ai-core.php stays fail-closed),
 * and existing settings are never overwritten (INSERT IGNORE), so re-running is safe. Nothing in these
 * tables holds a guest's or staff member's real name, email, phone or booking reference: the text is
 * stored with privacy tokens or refused (includes/ai/ai-memory.php).
 *
 *   ai_memory      what the hotel has taught it: facts, reply preferences, lessons, playbooks, glossary.
 *                  Each item keeps score (used / helped / did not help) and a status (probation, active,
 *                  retired, archived). It changes knowledge and wording only, never tools, permissions,
 *                  limits or the confirm step (those live in code).
 *   ai_incidents   mistakes and problems: a lookup that kept failing, a refused or failed card, a reply
 *                  marked "Not right", an automatic pause. The nightly review learns from these.
 *   ai_feedback    the Helpful / Not right taps on replies, with any correction typed.
 *   ai_eval_runs   each run of the fixed question set (nightly, smoke, model upgrade): score, tokens per
 *                  answer, and whether the day counted as clean for the completion guardrail.
 *   ai_eval_cases  regression questions added when a fault is fixed (source = incident); the shipped
 *                  questions live in includes/ai/evals.json.
 *   ai_faults      plain write-ups of problems that need a code fix, for the owner to forward as a PATCH.
 *                  The Assistant never changes its own code.
 */

return [
    'name' => 'ai_memory',

    'check' => function (PDO $pdo): bool {
        foreach (['ai_memory', 'ai_incidents', 'ai_feedback', 'ai_eval_runs', 'ai_eval_cases', 'ai_faults'] as $t) {
            if ($pdo->query("SHOW TABLES LIKE '" . $t . "'")->rowCount() === 0) {
                return false;
            }
        }
        $s = $pdo->query("SELECT COUNT(*) FROM site_settings WHERE setting_key = 'ai_learning_enabled'");
        return (int)$s->fetchColumn() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_memory` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `kind` VARCHAR(12) NOT NULL,
            `scope` VARCHAR(8) NOT NULL DEFAULT 'hotel',
            `scope_ref` VARCHAR(80) NULL DEFAULT NULL,
            `text` VARCHAR(600) NOT NULL,
            `terms` VARCHAR(1000) NOT NULL DEFAULT '',
            `meta_json` TEXT NULL,
            `source` VARCHAR(12) NOT NULL DEFAULT 'staff',
            `source_ref` VARCHAR(80) NULL DEFAULT NULL,
            `confidence` DECIMAL(4,2) NOT NULL DEFAULT 0.50,
            `uses` INT UNSIGNED NOT NULL DEFAULT 0,
            `wins` INT UNSIGNED NOT NULL DEFAULT 0,
            `losses` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(10) NOT NULL DEFAULT 'probation',
            `pinned` TINYINT(1) NOT NULL DEFAULT 0,
            `created_by` INT UNSIGNED NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `last_used_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ai_memory_status` (`status`, `kind`),
            KEY `idx_ai_memory_scope` (`scope`, `scope_ref`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: what the hotel has taught it (knowledge and wording only, never tools or limits)'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_incidents` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `kind` VARCHAR(40) NOT NULL,
            `severity` VARCHAR(10) NOT NULL DEFAULT 'info',
            `user_id` INT UNSIGNED NULL DEFAULT NULL,
            `conversation_id` INT UNSIGNED NULL DEFAULT NULL,
            `action_id` INT UNSIGNED NULL DEFAULT NULL,
            `tool_name` VARCHAR(80) NULL DEFAULT NULL,
            `summary` VARCHAR(500) NOT NULL DEFAULT '',
            `context_json` TEXT NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT 'open',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `reviewed_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ai_inc_created` (`created_at`),
            KEY `idx_ai_inc_kind` (`kind`, `created_at`),
            KEY `idx_ai_inc_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: mistakes and problems it learns from (tokenised context only)'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_feedback` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` BIGINT UNSIGNED NOT NULL,
            `conversation_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `rating` TINYINT NOT NULL DEFAULT 0,
            `correction` VARCHAR(1000) NULL DEFAULT NULL,
            `memory_id` INT UNSIGNED NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ai_feedback_msg_user` (`message_id`, `user_id`),
            KEY `idx_ai_feedback_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: Helpful / Not right taps and corrections'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_eval_runs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `kind` VARCHAR(10) NOT NULL,
            `run_date` DATE NOT NULL,
            `is_daily` TINYINT(1) NOT NULL DEFAULT 0,
            `provider` VARCHAR(20) NULL DEFAULT NULL,
            `model_id` VARCHAR(120) NULL DEFAULT NULL,
            `total` INT UNSIGNED NOT NULL DEFAULT 0,
            `run` INT UNSIGNED NOT NULL DEFAULT 0,
            `passed` INT UNSIGNED NOT NULL DEFAULT 0,
            `failed` INT UNSIGNED NOT NULL DEFAULT 0,
            `skipped` INT UNSIGNED NOT NULL DEFAULT 0,
            `pass_pct` DECIMAL(5,2) NULL DEFAULT NULL,
            `tokens_total` INT UNSIGNED NOT NULL DEFAULT 0,
            `tokens_per_answer` INT UNSIGNED NOT NULL DEFAULT 0,
            `cost_usd` DECIMAL(12,6) NOT NULL DEFAULT 0,
            `passed_ids` TEXT NULL,
            `failed_ids` TEXT NULL,
            `details_json` MEDIUMTEXT NULL,
            `clean_day` TINYINT(1) NULL DEFAULT NULL,
            `evidence_json` TEXT NULL,
            `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `finished_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ai_eval_kind_date` (`kind`, `run_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: runs of the fixed question set (quality and tokens per answer)'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_eval_cases` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `case_key` VARCHAR(80) NOT NULL,
            `question` VARCHAR(600) NOT NULL,
            `expect_json` TEXT NOT NULL,
            `smoke` TINYINT(1) NOT NULL DEFAULT 0,
            `source` VARCHAR(12) NOT NULL DEFAULT 'incident',
            `fault_id` INT UNSIGNED NULL DEFAULT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ai_eval_case_key` (`case_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: regression questions added from fixed faults'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_faults` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `fault_key` VARCHAR(120) NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `detail` TEXT NULL,
            `evidence_json` TEXT NULL,
            `status` VARCHAR(12) NOT NULL DEFAULT 'open',
            `regression_case_id` INT UNSIGNED NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `resolved_at` DATETIME NULL DEFAULT NULL,
            `resolved_by` INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ai_faults_status` (`status`),
            KEY `idx_ai_faults_key` (`fault_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assistant: problems that need a code fix, written up for the owner'");

        // Settings (group 'ai'), never overwriting a value already there.
        $set = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai')");
        foreach ([
            'ai_learning_enabled'    => '1',
            'ai_cloud_enabled'       => '1',
            'ai_feature_state'       => 'learning',
            'ai_complete_days'       => '14',
            'ai_complete_eval_pct'   => '95',
            'ai_eval_smoke_min_pct'  => '80',
        ] as $k => $v) {
            $set->execute([$k, $v]);
        }
    },
];
