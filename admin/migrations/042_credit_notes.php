<?php

/**
 * Migration 042 — Credit Notes System
 *
 * Creates:
 *  - credit_notes            : the credit note ledger (balance tracking)
 *  - credit_note_applications: redemption history per CN
 * Alters:
 *  - payments.credit_note_id : FK to credit_notes when CN was applied as payment
 *  - payments.payment_method : adds 'credit_note' to the ENUM
 * Inserts:
 *  - site_settings row: credit_note_expiry_months = 12
 */

declare(strict_types=1);

$isCli042 = PHP_SAPI === 'cli';

if ($isCli042) {
    require_once __DIR__ . '/../../config/database.php';
} else {
    require_once __DIR__ . '/../admin-init.php';
    /** @var array $user */
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin only.');
    }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:24px;line-height:1.6;'>";
}

function out042(string $msg, string $tag = 'info'): void
{
    $icons = ['ok' => '✓', 'warn' => '⚠', 'done' => '★', 'info' => '→', 'skip' => '·'];
    $icon  = $icons[$tag] ?? '→';
    echo $icon . '  ' . $msg . PHP_EOL;
}

function tableExists042(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$table]);
    return (int)$s->fetchColumn() > 0;
}

function colExists042(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

function indexExists042(PDO $pdo, string $table, string $index): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $s->execute([$table, $index]);
    return (int)$s->fetchColumn() > 0;
}

function settingExists042(PDO $pdo, string $key): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM site_settings WHERE setting_key = ?");
    $s->execute([$key]);
    return (int)$s->fetchColumn() > 0;
}

out042('Migration 042 — Credit Notes System', 'info');
out042('');

// ─────────────────────────────────────────────────────────────────────────────
// 1. credit_notes table
// ─────────────────────────────────────────────────────────────────────────────
if (!tableExists042($pdo, 'credit_notes')) {
    $pdo->exec("CREATE TABLE credit_notes (
        id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
        credit_note_number  VARCHAR(25)  NOT NULL COMMENT 'CN-YYYY-000001 format',
        booking_id          INT UNSIGNED NULL COMMENT 'Originating booking ID (room or conference)',
        booking_reference   VARCHAR(50)  NULL,
        booking_type        ENUM('room','conference','restaurant','goodwill') NOT NULL DEFAULT 'room',
        guest_name          VARCHAR(150) NOT NULL,
        guest_email         VARCHAR(150) NULL,
        original_amount     DECIMAL(10,2) NOT NULL COMMENT 'Face value of the credit note',
        amount_used         DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Total redeemed so far',
        balance             DECIMAL(10,2) NOT NULL COMMENT 'original_amount - amount_used',
        vat_rate            DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
        vat_amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        reason              ENUM('cancellation','service_issue','early_checkout','overpayment','goodwill','pricing_error','other') NOT NULL DEFAULT 'other',
        reason_notes        TEXT NULL,
        status              ENUM('active','partially_applied','fully_applied','voided','expired') NOT NULL DEFAULT 'active',
        issued_by           INT UNSIGNED NOT NULL COMMENT 'Admin user ID',
        issued_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at          DATE         NULL COMMENT 'NULL = never expires; typically 12 months',
        voided_at           DATETIME     NULL,
        voided_by           INT UNSIGNED NULL,
        void_reason         TEXT         NULL,
        original_payment_id INT UNSIGNED NULL COMMENT 'Linked refund payment row if CN came from a refund',
        pdf_path            VARCHAR(255) NULL,
        pdf_generated       TINYINT(1)   NOT NULL DEFAULT 0,
        email_sent          TINYINT(1)   NOT NULL DEFAULT 0,
        email_sent_at       DATETIME     NULL,
        created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_credit_note_number (credit_note_number),
        KEY idx_cn_status         (status),
        KEY idx_cn_booking_id     (booking_id),
        KEY idx_cn_guest_email    (guest_email),
        KEY idx_cn_issued_at      (issued_at),
        KEY idx_cn_expires_at     (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    out042('Created table: credit_notes', 'ok');
} else {
    out042('Table credit_notes already exists — skipped', 'skip');
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. credit_note_applications table
// ─────────────────────────────────────────────────────────────────────────────
if (!tableExists042($pdo, 'credit_note_applications')) {
    $pdo->exec("CREATE TABLE credit_note_applications (
        id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        credit_note_id              INT UNSIGNED NOT NULL,
        payment_id                  INT UNSIGNED NULL COMMENT 'payments row created for this redemption',
        applied_to_booking_id       INT UNSIGNED NULL,
        applied_to_booking_reference VARCHAR(50) NULL,
        applied_to_booking_type     ENUM('room','conference','restaurant') NOT NULL DEFAULT 'room',
        amount_applied              DECIMAL(10,2) NOT NULL,
        applied_by                  INT UNSIGNED NOT NULL,
        applied_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notes                       TEXT NULL,
        PRIMARY KEY (id),
        KEY idx_cna_credit_note_id  (credit_note_id),
        KEY idx_cna_booking_id      (applied_to_booking_id),
        KEY idx_cna_payment_id      (payment_id),
        CONSTRAINT fk_cna_credit_note FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    out042('Created table: credit_note_applications', 'ok');
} else {
    out042('Table credit_note_applications already exists — skipped', 'skip');
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. ALTER payments — add credit_note_id column
// ─────────────────────────────────────────────────────────────────────────────
if (!colExists042($pdo, 'payments', 'credit_note_id')) {
    $pdo->exec("ALTER TABLE payments ADD COLUMN credit_note_id INT UNSIGNED NULL COMMENT 'FK to credit_notes.id when this payment redeems a CN' AFTER original_payment_id");
    out042('Added column: payments.credit_note_id', 'ok');
} else {
    out042('Column payments.credit_note_id already exists — skipped', 'skip');
}

if (!indexExists042($pdo, 'payments', 'idx_payments_credit_note_id')) {
    try {
        $pdo->exec("ALTER TABLE payments ADD INDEX idx_payments_credit_note_id (credit_note_id)");
        out042('Added index: idx_payments_credit_note_id', 'ok');
    } catch (Throwable $e) {
        out042('Index idx_payments_credit_note_id skipped: ' . $e->getMessage(), 'warn');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. ALTER payments.payment_method ENUM — add 'credit_note'
//    We read the current ENUM definition and only alter if 'credit_note' is missing.
// ─────────────────────────────────────────────────────────────────────────────
$enumStmt = $pdo->prepare("
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payments'
      AND COLUMN_NAME  = 'payment_method'
");
$enumStmt->execute();
$enumDef = (string)($enumStmt->fetchColumn() ?: '');

if (strpos($enumDef, 'credit_note') === false) {
    // Preserve existing values + add credit_note
    $pdo->exec("ALTER TABLE payments MODIFY COLUMN payment_method ENUM(
        'cash','bank_transfer','mobile_money','credit_card','debit_card','cheque','credit_note','other'
    ) NULL");
    out042("Updated payments.payment_method ENUM to include 'credit_note'", 'ok');
} else {
    out042("payments.payment_method already includes 'credit_note' — skipped", 'skip');
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. site_settings rows
// ─────────────────────────────────────────────────────────────────────────────
$settings = [
    'credit_note_expiry_months' => ['12',    'How many months a credit note remains valid (0 = never expires)'],
];

foreach ($settings as $key => [$value, $desc]) {
    if (!settingExists042($pdo, $key)) {
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)")
            ->execute([$key, $value]);
        out042("Inserted site_settings: {$key} = {$value}", 'ok');
    } else {
        out042("site_settings.{$key} already exists — skipped", 'skip');
    }
}

out042('');
out042('Migration 042 complete.', 'done');

if (!$isCli042) {
    echo "</pre>";
    echo "<p style='font-family:sans-serif;'><a href='../accounting-dashboard.php'>← Accounting Dashboard</a> &nbsp;|&nbsp; <a href='../credit-notes.php'>→ Credit Notes</a></p>";
}

