<?php

/**
 * 081 - email_send_log: every email the system sends, who sent it and when (owner 2026-11-12: "add logs for emails
 * that have gone out: full logs of the whos and the whens in simple table format").
 *
 * Additive only, re-running is safe. One row per email (or per decision not to send one), written by
 * includes/email-send-log.php from every mail send point (config/email.php, config/invoice.php, config/credit-notes.php,
 * includes/report-mailer.php). Until this runs, every send works exactly as before and simply records nothing.
 * Read by System Logs > "Emails sent" and by the booking History panel. No foreign keys: the log outlives deleted rows.
 * sent_at is written with NOW() through the app connection (session time zone = hotel time).
 */

return [
    'name' => 'email_send_log',

    'check' => function (PDO $pdo): bool {
        return $pdo->query("SHOW TABLES LIKE 'email_send_log'")->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `email_send_log` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Hotel time',
            `status` ENUM('sent','failed','preview','not_sent') NOT NULL DEFAULT 'sent',
            `to_email` VARCHAR(500) NOT NULL DEFAULT '',
            `to_name` VARCHAR(255) NULL DEFAULT NULL,
            `cc` TEXT NULL COMMENT 'Copies, hidden ones marked (hidden)',
            `subject` VARCHAR(500) NOT NULL DEFAULT '',
            `email_kind` VARCHAR(40) NOT NULL DEFAULT 'other' COMMENT 'booking_confirmed, booking_cancelled, invoice, receipt, daily_report ...',
            `account_type` VARCHAR(20) NULL DEFAULT NULL COMMENT 'room, conference, gym, event, pos ...',
            `account_id` INT NULL DEFAULT NULL,
            `account_ref` VARCHAR(64) NULL DEFAULT NULL,
            `sent_by_user_id` INT NULL DEFAULT NULL,
            `sent_by_label` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Staff name (role), System (scheduler), Guest website ...',
            `error` TEXT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_esl_sent_at` (`sent_at`),
            KEY `idx_esl_account` (`account_type`, `account_id`),
            KEY `idx_esl_account_ref` (`account_ref`),
            KEY `idx_esl_kind` (`email_kind`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Every email sent (or not sent): who, when, to whom, result'");
    },
];
