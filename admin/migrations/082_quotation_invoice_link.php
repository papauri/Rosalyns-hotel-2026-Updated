<?php

/**
 * 082 - quotation -> invoice link (owner 2026-11-12: "add Hotel quotation to invoice logic for quotations").
 *
 * Quotations > Convert to invoice turns a sent/accepted room or conference quotation into ONE invoice for its booking
 * and links the two (includes/quotation-convert.php). These columns hold the quotation's side of the link:
 *   converted_invoice_number  the invoice number it became (from the normal invoice sequence, or the one the booking
 *                             already held)
 *   converted_invoice_path    the invoice file (invoices/<number>.pdf) for the Download link
 *   converted_total           the record's own total at conversion (rooms: the stay total, booked rate x nights;
 *                             extras on the bill are listed on the invoice besides)
 *   converted_at / converted_by  when and by which staff member
 * The quotation's status stays 'accepted' (converting is accepting); the register shows it as "Converted".
 *
 * Additive only: each column / the index is added only when missing, no rows are touched, re-running is safe.
 * Until this runs the Convert button is hidden with a plain note and everything else works as before.
 */

$rhQuotationLinkColumns = [
    'converted_invoice_number' => "ADD COLUMN `converted_invoice_number` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Invoice made from this quotation'",
    'converted_invoice_path'   => "ADD COLUMN `converted_invoice_path` VARCHAR(255) NULL DEFAULT NULL COMMENT 'invoices/<number>.pdf'",
    'converted_total'          => "ADD COLUMN `converted_total` DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'Total at conversion, gross: room stay total (booked rate x nights) or enquiry total'",
    'converted_at'             => "ADD COLUMN `converted_at` DATETIME NULL DEFAULT NULL COMMENT 'When it was converted (hotel time)'",
    'converted_by'             => "ADD COLUMN `converted_by` INT NULL DEFAULT NULL COMMENT 'admin_users.id who converted it'",
];

$rhQuotationLinkHas = static function (PDO $pdo, string $column): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations' AND COLUMN_NAME = ?"
    );
    $st->execute([$column]);
    return (int)$st->fetchColumn() > 0;
};

$rhQuotationLinkHasIndex = static function (PDO $pdo): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations' AND INDEX_NAME = 'idx_q_converted_invoice'"
    );
    $st->execute();
    return (int)$st->fetchColumn() > 0;
};

return [
    'name' => 'quotation_invoice_link',

    'check' => function (PDO $pdo) use ($rhQuotationLinkColumns, $rhQuotationLinkHas, $rhQuotationLinkHasIndex): bool {
        foreach (array_keys($rhQuotationLinkColumns) as $col) {
            if (!$rhQuotationLinkHas($pdo, $col)) {
                return false;
            }
        }
        return $rhQuotationLinkHasIndex($pdo);
    },

    'up' => function (PDO $pdo) use ($rhQuotationLinkColumns, $rhQuotationLinkHas, $rhQuotationLinkHasIndex): void {
        foreach ($rhQuotationLinkColumns as $col => $ddl) {
            if ($rhQuotationLinkHas($pdo, $col)) {
                continue;
            }
            $pdo->exec('ALTER TABLE `quotations` ' . $ddl);
            echo "    quotations.{$col}: added\n";
        }
        if (!$rhQuotationLinkHasIndex($pdo)) {
            $pdo->exec('ALTER TABLE `quotations` ADD INDEX `idx_q_converted_invoice` (`converted_invoice_number`)');
            echo "    quotations.idx_q_converted_invoice: added\n";
        }
    },
];
