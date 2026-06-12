<?php

/**
 * Migration 040 — Quotations table
 * Creates a persistent record for every quotation issued from any booking type.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
/** @var PDO $pdo */

$pdo->exec("
    CREATE TABLE IF NOT EXISTS quotations (
        id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        booking_id       INT UNSIGNED     NOT NULL,
        booking_type     ENUM('room','conference','event') NOT NULL DEFAULT 'room',
        quote_reference  VARCHAR(100)     NOT NULL,
        booking_reference VARCHAR(100)    NULL,
        guest_name       VARCHAR(200)     NULL,
        guest_email      VARCHAR(200)     NULL,
        room_name        VARCHAR(200)     NULL,
        total_amount     DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        valid_days       INT UNSIGNED     NOT NULL DEFAULT 7,
        valid_until      DATE             NULL,
        quotation_notes  TEXT             NULL,
        pdf_path         VARCHAR(500)     NULL,
        status           ENUM('sent','accepted','expired','declined') NOT NULL DEFAULT 'sent',
        sent_at          DATETIME         NULL,
        sent_by          INT UNSIGNED     NULL,
        created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_booking     (booking_id, booking_type),
        INDEX idx_sent_at     (sent_at),
        INDEX idx_status      (status),
        INDEX idx_valid_until (valid_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='One row per quotation issued — room, conference, or event'
");

echo "Migration 040 complete: quotations table created.\n";

