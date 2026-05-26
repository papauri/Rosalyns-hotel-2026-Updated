<?php

declare(strict_types=1);

/**
 * Atomic finance numbering helpers for receipt and invoice sequences.
 */

if (!function_exists('finance_ensure_sequence_tables')) {
    function finance_ensure_sequence_tables(PDO $pdo): void
    {
        static $checked = false;
        if ($checked || $pdo->inTransaction()) {
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS finance_sequences (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sequence_name VARCHAR(80) NOT NULL,
            sequence_scope VARCHAR(20) NOT NULL,
            next_number INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_finance_sequence_name_scope (sequence_name, sequence_scope)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $checked = true;
    }
}

if (!function_exists('finance_sequence_setting')) {
    function finance_sequence_setting(string $key, string $default): string
    {
        if (function_exists('getSetting')) {
            return (string)getSetting($key, $default);
        }

        return $default;
    }
}

if (!function_exists('finance_sequence_scope_from_date')) {
    function finance_sequence_scope_from_date(?string $dateValue): string
    {
        $timestamp = false;
        if ($dateValue !== null && trim($dateValue) !== '') {
            $timestamp = strtotime($dateValue);
        }

        if ($timestamp === false) {
            $timestamp = time();
        }

        return date('Y', $timestamp);
    }
}

if (!function_exists('finance_sequence_key_fragment')) {
    function finance_sequence_key_fragment(string $value): string
    {
        $fragment = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?: 'default';
        return trim($fragment, '_') ?: 'default';
    }
}

if (!function_exists('finance_next_sequence_number')) {
    function finance_next_sequence_number(PDO $pdo, string $sequenceName, string $sequenceScope, int $startNumber): int
    {
        finance_ensure_sequence_tables($pdo);

        $startNumber = max(1, $startNumber);
        $ownTransaction = !$pdo->inTransaction();

        try {
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }

            $ensureStmt = $pdo->prepare("INSERT INTO finance_sequences (sequence_name, sequence_scope, next_number)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE updated_at = updated_at");
            $ensureStmt->execute([$sequenceName, $sequenceScope, $startNumber]);

            $lockStmt = $pdo->prepare("SELECT next_number FROM finance_sequences WHERE sequence_name = ? AND sequence_scope = ? FOR UPDATE");
            $lockStmt->execute([$sequenceName, $sequenceScope]);
            $nextNumber = max($startNumber, (int)$lockStmt->fetchColumn());

            $updateStmt = $pdo->prepare("UPDATE finance_sequences SET next_number = ? WHERE sequence_name = ? AND sequence_scope = ?");
            $updateStmt->execute([$nextNumber + 1, $sequenceName, $sequenceScope]);

            if ($ownTransaction) {
                $pdo->commit();
            }

            return $nextNumber;
        } catch (Throwable $exception) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }
}

if (!function_exists('finance_payment_value_exists')) {
    function finance_payment_value_exists(PDO $pdo, string $column, string $value): bool
    {
        if (!in_array($column, ['receipt_number', 'invoice_number'], true)) {
            throw new InvalidArgumentException('Unsafe finance column.');
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE {$column} = ?");
        $stmt->execute([$value]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('finance_invoice_number_exists')) {
    function finance_invoice_number_exists(PDO $pdo, string $invoiceNumber): bool
    {
        if (finance_payment_value_exists($pdo, 'invoice_number', $invoiceNumber)) {
            return true;
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE final_invoice_number = ?");
            $stmt->execute([$invoiceNumber]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $exception) {
            return false;
        }
    }
}

if (!function_exists('finance_next_receipt_number')) {
    function finance_next_receipt_number(PDO $pdo, ?string $paymentDate = null): string
    {
        $prefix = trim(finance_sequence_setting('receipt_prefix', 'RCP'));
        if ($prefix === '') {
            $prefix = 'RCP';
        }

        $scope = finance_sequence_scope_from_date($paymentDate);
        $startNumber = (int)finance_sequence_setting('receipt_start_number', '1');
        $sequenceName = 'receipt:' . finance_sequence_key_fragment($prefix);

        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $nextNumber = finance_next_sequence_number($pdo, $sequenceName, $scope, $startNumber);
            $receiptNumber = $prefix . $scope . str_pad((string)$nextNumber, 6, '0', STR_PAD_LEFT);

            if (!finance_payment_value_exists($pdo, 'receipt_number', $receiptNumber)) {
                return $receiptNumber;
            }
        }

        throw new RuntimeException('Unable to allocate a unique receipt number.');
    }
}

if (!function_exists('finance_next_invoice_number')) {
    function finance_next_invoice_number(PDO $pdo, string $invoicePrefix, int $invoiceStart, ?string $invoiceDate = null, string $channel = 'standard'): string
    {
        $prefix = trim($invoicePrefix) !== '' ? trim($invoicePrefix) : 'INV';
        $scope = finance_sequence_scope_from_date($invoiceDate);
        $startNumber = max(1, $invoiceStart);
        $sequenceName = 'invoice:' . finance_sequence_key_fragment($channel . ':' . $prefix);

        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $nextNumber = finance_next_sequence_number($pdo, $sequenceName, $scope, $startNumber);
            $invoiceNumber = $prefix . '-' . $scope . '-' . str_pad((string)$nextNumber, 6, '0', STR_PAD_LEFT);

            if (!finance_invoice_number_exists($pdo, $invoiceNumber)) {
                return $invoiceNumber;
            }
        }

        throw new RuntimeException('Unable to allocate a unique invoice number.');
    }
}

if (!function_exists('finance_next_credit_note_number')) {
    function finance_next_credit_note_number(PDO $pdo, ?string $issueDate = null): string
    {
        $prefix = trim(finance_sequence_setting('credit_note_prefix', 'CN'));
        if ($prefix === '') {
            $prefix = 'CN';
        }

        $scope        = finance_sequence_scope_from_date($issueDate);
        $startNumber  = max(1, (int)finance_sequence_setting('credit_note_start_number', '1'));
        $sequenceName = 'credit_note:' . finance_sequence_key_fragment($prefix);

        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $nextNumber = finance_next_sequence_number($pdo, $sequenceName, $scope, $startNumber);
            $cnNumber   = $prefix . '-' . $scope . '-' . str_pad((string)$nextNumber, 6, '0', STR_PAD_LEFT);

            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM credit_notes WHERE credit_note_number = ?");
                $stmt->execute([$cnNumber]);
                if ((int)$stmt->fetchColumn() === 0) {
                    return $cnNumber;
                }
            } catch (Throwable $e) {
                // Table may not exist yet — issueCreditNote creates it; number is safe to use
                return $cnNumber;
            }
        }

        throw new RuntimeException('Unable to allocate a unique credit note number.');
    }
}
