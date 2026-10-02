<?php

/**
 * 040 — create `automated_email_log`.
 *
 * Additive only. Idempotency + activity log for the web-triggered scheduler
 * (includes/auto-scheduler.php). One row per (job, account_type, account_id, stage):
 * the row is claimed (INSERT IGNORE on the UNIQUE key) BEFORE the email is sent, so
 * concurrent requests can never double-send, then finished as sent/failed/skipped.
 * Failed rows may be retried (attempts column) by the job code.
 */

return [
    'name' => 'create_automated_email_log',

    'check' => function (PDO $pdo): bool {
        $stmt = $pdo->query("SHOW TABLES LIKE 'automated_email_log'");
        return $stmt->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE automated_email_log (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job          VARCHAR(60)  NOT NULL,
                account_type VARCHAR(30)  NOT NULL,
                account_id   INT UNSIGNED NOT NULL DEFAULT 0,
                stage        VARCHAR(40)  NOT NULL,
                recipient    VARCHAR(255) NULL,
                subject      VARCHAR(255) NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT 'sending',
                attempts     TINYINT UNSIGNED NOT NULL DEFAULT 1,
                error        VARCHAR(500) NULL,
                sent_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_job_account_stage (job, account_type, account_id, stage),
                KEY idx_sent_at (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
];
