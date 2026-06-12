<?php
/**
 * Migration 039 - Finance sequences and MRA readiness.
 *
 * Adds atomic receipt/invoice sequence storage, MRA submission metadata, and
 * backfills missing receipt numbers for completed sales without calling MRA.
 */

declare(strict_types=1);

$isCli039 = PHP_SAPI === 'cli';

if ($isCli039) {
    require_once __DIR__ . '/../../config/database.php';
} else {
    require_once __DIR__ . '/../admin-init.php';
    /** @var array $user */
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin only.');
    }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:20px;'>";
}

require_once __DIR__ . '/../../includes/finance-sequences.php';

function out039(string $message, string $tag = 'info'): void
{
    $prefix = $tag === 'ok' ? '[OK]' : ($tag === 'warn' ? '[WARN]' : ($tag === 'done' ? '[DONE]' : '[INFO]'));
    echo $prefix . ' ' . $message . PHP_EOL;
}

function colExists039(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists039(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function tableExists039(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function duplicateValueCount039(PDO $pdo, string $table, string $column): int
{
    $sql = "SELECT COUNT(*) FROM (
        SELECT {$column}
        FROM {$table}
        WHERE {$column} IS NOT NULL
        GROUP BY {$column}
        HAVING COUNT(*) > 1
    ) duplicates";
    return (int)$pdo->query($sql)->fetchColumn();
}

function addUniqueIndexIfSafe039(PDO $pdo, string $table, string $column, string $index): void
{
    if (indexExists039($pdo, $table, $index)) {
        out039("{$index} already exists", 'info');
        return;
    }

    $duplicates = duplicateValueCount039($pdo, $table, $column);
    if ($duplicates > 0) {
        out039("Skipped {$index}: {$duplicates} duplicate {$column} value group(s) need manual cleanup first", 'warn');
        return;
    }

    $pdo->exec("ALTER TABLE {$table} ADD UNIQUE KEY {$index} ({$column})");
    out039("{$index} added", 'ok');
}

function mapPaymentMethod039(?string $method): string
{
    return match ($method) {
        'cash' => 'cash',
        'mobile_money' => 'mobile_money',
        'card_manual', 'card_pos' => 'credit_card',
        default => 'other',
    };
}

try {
    finance_ensure_sequence_tables($pdo);
    out039('finance_sequences table ready', 'ok');

    $settings = [
        ['receipt_prefix', 'RCP', 'finance'],
        ['receipt_start_number', '1', 'finance'],
        ['mra_eis_enabled', '0', 'mra'],
        ['mra_eis_mode', 'sandbox', 'mra'],
        ['mra_eis_timeout_seconds', '15', 'mra'],
        ['mra_eis_retry_limit', '5', 'mra'],
    ];
    $settingStmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_group = VALUES(setting_group)");
    foreach ($settings as $setting) {
        $settingStmt->execute($setting);
    }
    out039('finance/MRA settings ensured', 'ok');

    $paymentColumns = [
        'receipt_number' => "VARCHAR(50) NULL",
        'invoice_number' => "VARCHAR(50) NULL",
        'invoice_path' => "VARCHAR(255) NULL",
        'invoice_generated' => "TINYINT(1) NOT NULL DEFAULT 0",
        'mra_status' => "ENUM('not_required','pending','queued','submitted','accepted','rejected','failed') NOT NULL DEFAULT 'pending'",
        'mra_fiscal_no' => "VARCHAR(100) NULL",
        'mra_signature' => "TEXT NULL",
        'mra_qr_payload' => "TEXT NULL",
        'mra_payload_hash' => "CHAR(64) NULL",
        'mra_submitted_at' => "DATETIME NULL",
        'mra_accepted_at' => "DATETIME NULL",
        'mra_last_error' => "TEXT NULL",
        'mra_retry_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
        'mra_response_json' => "JSON NULL",
    ];

    foreach ($paymentColumns as $column => $definition) {
        if (colExists039($pdo, 'payments', $column)) {
            out039("payments.{$column} exists", 'info');
            continue;
        }
        $pdo->exec("ALTER TABLE payments ADD COLUMN {$column} {$definition}");
        out039("payments.{$column} added", 'ok');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS mra_submission_queue (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        payment_id INT UNSIGNED NOT NULL,
        submission_type ENUM('sale','refund') NOT NULL DEFAULT 'sale',
        status ENUM('pending','processing','accepted','rejected','failed') NOT NULL DEFAULT 'pending',
        payload_json JSON NULL,
        payload_hash CHAR(64) NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        next_attempt_at DATETIME NULL,
        last_error TEXT NULL,
        response_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_mra_submission_payment (payment_id),
        KEY idx_mra_submission_status_next (status, next_attempt_at),
        KEY idx_mra_submission_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    out039('mra_submission_queue table ready', 'ok');

    $missingReceiptStmt = $pdo->query("SELECT id, payment_date FROM payments
        WHERE deleted_at IS NULL
          AND COALESCE(payment_type, '') <> 'refund'
          AND payment_status IN ('completed','paid')
          AND (receipt_number IS NULL OR receipt_number = '')
        ORDER BY payment_date ASC, id ASC");
    $receiptUpdate = $pdo->prepare("UPDATE payments SET receipt_number = ? WHERE id = ? AND (receipt_number IS NULL OR receipt_number = '')");
    $backfilledReceipts = 0;
    while ($payment = $missingReceiptStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->beginTransaction();
        $receiptNumber = finance_next_receipt_number($pdo, (string)($payment['payment_date'] ?? date('Y-m-d')));
        $receiptUpdate->execute([$receiptNumber, (int)$payment['id']]);
        $pdo->commit();
        $backfilledReceipts++;
    }
    out039("Backfilled {$backfilledReceipts} missing payment receipt(s)", $backfilledReceipts > 0 ? 'ok' : 'info');

    if (tableExists039($pdo, 'stock_orders')) {
        $paidOrdersStmt = $pdo->query("SELECT so.id, so.reference, so.total_amount, so.payment_method, so.paid_at, so.customer_name, so.created_by
            FROM stock_orders so
            LEFT JOIN payments p ON p.booking_type = 'restaurant'
                AND p.booking_id = so.id
                AND COALESCE(p.payment_type, '') <> 'refund'
                AND p.deleted_at IS NULL
            WHERE so.status = 'paid'
              AND p.id IS NULL
            ORDER BY COALESCE(so.paid_at, so.created_at) ASC, so.id ASC");
        $restaurantPaymentInsert = $pdo->prepare("INSERT INTO payments (
            payment_reference, booking_type, booking_id, booking_reference,
            payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, receipt_number, invoice_generated,
            status, notes, recorded_by
        ) VALUES (?, 'restaurant', ?, ?, ?, ?, ?, ?, ?, ?, 'full_payment', 'completed', ?, 0, 'completed', ?, ?)");
        $syncedOrders = 0;
        $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
        $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0.0;
        while ($order = $paidOrdersStmt->fetch(PDO::FETCH_ASSOC)) {
            $gross = (float)$order['total_amount'];
            $vatAmount = $vatRate > 0 ? round($gross - ($gross / (1 + ($vatRate / 100))), 2) : 0.0;
            $netAmount = round($gross - $vatAmount, 2);
            $paymentDate = $order['paid_at'] ? date('Y-m-d', strtotime((string)$order['paid_at'])) : date('Y-m-d');
            $baseReference = 'POS-' . (string)$order['reference'];
            $paymentReference = $baseReference;
            $suffix = 1;
            $referenceCheck = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
            $referenceCheck->execute([$paymentReference]);
            while ((int)$referenceCheck->fetchColumn() > 0) {
                $suffix++;
                $paymentReference = $baseReference . '-' . $suffix;
                $referenceCheck->execute([$paymentReference]);
            }

            $pdo->beginTransaction();
            $receiptNumber = finance_next_receipt_number($pdo, $paymentDate);
            $restaurantPaymentInsert->execute([
                $paymentReference,
                (int)$order['id'],
                (string)$order['reference'],
                $paymentDate,
                $netAmount,
                $vatRate,
                $vatAmount,
                $gross,
                mapPaymentMethod039((string)($order['payment_method'] ?? 'other')),
                $receiptNumber,
                trim('Backfilled restaurant order ' . (string)$order['reference'] . (!empty($order['customer_name']) ? ' - ' . (string)$order['customer_name'] : '')),
                $order['created_by'] !== null ? (int)$order['created_by'] : null,
            ]);
            $pdo->commit();
            $syncedOrders++;
        }
        out039("Synced {$syncedOrders} paid POS order(s) into payments", $syncedOrders > 0 ? 'ok' : 'info');
    }

    addUniqueIndexIfSafe039($pdo, 'payments', 'payment_reference', 'uniq_payments_payment_reference');
    addUniqueIndexIfSafe039($pdo, 'payments', 'receipt_number', 'uniq_payments_receipt_number');
    addUniqueIndexIfSafe039($pdo, 'payments', 'invoice_number', 'uniq_payments_invoice_number');

    out039('Migration 039 complete', 'done');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    out039('FAILED: ' . $exception->getMessage(), 'warn');
    if (!$isCli039) {
        echo '</pre>';
    }
    exit(1);
}

if (!$isCli039) {
    echo '</pre>';
}
