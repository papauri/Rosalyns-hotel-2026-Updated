<?php

/**
 * 020 — POS / stock payments-audit fixes (additive only).
 *
 *   stock_orders.paid_by            who actually took the money (shift cash is
 *                                   attributed to this user, not to whoever opened
 *                                   the tab). NULL for orders paid before this.
 *   stock_adjustments.restore_of_type
 *                                   which kind of deduction a 'void_restore' row
 *                                   reverses ('pos_order' / 'room_service' / ...),
 *                                   so restores are matched on source type + id
 *                                   instead of id alone and a recall -> re-bump ->
 *                                   void sequence pairs correctly.
 *   idempotency_keys                response cache used by includes/idempotency.php
 *                                   (idem_begin / idem_finish); created here because
 *                                   nothing else creates it. Used by add_to_tab.
 *
 * Nothing is dropped, renamed or back-filled.
 */

return [
    'name' => 'pos_payments_audit_fixes',

    'check' => function (PDO $pdo): bool {
        $col = static function (PDO $pdo, string $table, string $column): bool {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        };
        $tbl = $pdo->query("SHOW TABLES LIKE 'idempotency_keys'")->rowCount() > 0;
        return $col($pdo, 'stock_orders', 'paid_by')
            && $col($pdo, 'stock_adjustments', 'restore_of_type')
            && $tbl;
    },

    'up' => function (PDO $pdo): void {
        $col = static function (PDO $pdo, string $table, string $column): bool {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        };
        if (!$col($pdo, 'stock_orders', 'paid_by')) {
            $pdo->exec("ALTER TABLE stock_orders ADD COLUMN paid_by INT UNSIGNED NULL DEFAULT NULL");
        }
        if (!$col($pdo, 'stock_adjustments', 'restore_of_type')) {
            $pdo->exec("ALTER TABLE stock_adjustments ADD COLUMN restore_of_type VARCHAR(20) NULL DEFAULT NULL");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS idempotency_keys (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_uuid VARCHAR(64) NOT NULL,
            endpoint VARCHAR(255) NOT NULL,
            response_status INT NOT NULL DEFAULT 200,
            response_body MEDIUMTEXT NULL,
            entity_type VARCHAR(48) NULL,
            entity_id INT NULL,
            entity_reference VARCHAR(64) NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_idem_uuid_endpoint (client_uuid, endpoint),
            KEY idx_idem_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
