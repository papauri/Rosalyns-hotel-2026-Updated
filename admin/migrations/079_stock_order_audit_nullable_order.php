<?php

/**
 * 079 - till audit rows that belong to no order can be saved (owner 2026-11-10: "make sure we have it right").
 *
 * stock_order_audit.order_id was NOT NULL with FK fk_soa_order -> stock_orders(id), so every audit write for an
 * event that has no order (shift closed, float set, menu item 86 on/off, barcode assigned, accountant shift close)
 * was written with order_id 0, rejected by the foreign key and swallowed by the writer's try/catch: those events
 * were never recorded. The writers (pos_logAudit in admin/pos.php, rh_pos_accounting_log in admin/pos-accounting.php)
 * now pass NULL for "no order"; this migration lets the column hold it.
 *
 * Only the nullability changes: the column type (read from information_schema at run time, e.g. INT UNSIGNED) and
 * comment are kept, and fk_soa_order stays exactly as it is (rows with NULL sit outside the FK and its cascade).
 * No rows are touched. Skipped when the column is already NULL-able or the table/column is missing, so re-running
 * is safe.
 */

$rhSoaOrderColumn = static function (PDO $pdo): ?array {
    $st = $pdo->prepare(
        "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_order_audit' AND COLUMN_NAME = 'order_id'"
    );
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

return [
    'name' => 'stock_order_audit_nullable_order',

    'check' => function (PDO $pdo) use ($rhSoaOrderColumn): bool {
        $col = $rhSoaOrderColumn($pdo);
        return $col === null || strtoupper((string)$col['IS_NULLABLE']) === 'YES';
    },

    'up' => function (PDO $pdo) use ($rhSoaOrderColumn): void {
        $col = $rhSoaOrderColumn($pdo);
        if ($col === null || strtoupper((string)$col['IS_NULLABLE']) === 'YES') {
            return;
        }
        $type = trim((string)$col['COLUMN_TYPE']);
        if (!preg_match('/^(tiny|small|medium|big)?int(\(\d+\))?( unsigned)?( zerofill)?$/i', $type)) {
            throw new RuntimeException("stock_order_audit.order_id has unexpected type '$type'; not changed");
        }
        $comment = (string)$col['COLUMN_COMMENT'];
        $pdo->exec(
            "ALTER TABLE stock_order_audit MODIFY `order_id` $type NULL DEFAULT NULL"
            . ($comment !== '' ? ' COMMENT ' . $pdo->quote($comment) : '')
        );
        echo "    stock_order_audit.order_id: now $type NULL (fk_soa_order kept); events with no order are recorded\n";
    },
];
