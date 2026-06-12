<?php
/**
 * Migration 017 — POS payment + anti-cheat columns.
 *
 * Adds payment fields to stock_orders so a restaurant order is paid AS IT IS PLACED
 * (closing the "place order, pocket cash, never mark paid" loophole). Also adds void
 * audit columns and a future-proof `card_pos` provision.
 */
$isCli017 = (PHP_SAPI === 'cli');

if ($isCli017) {
    require_once __DIR__ . '/../../config/database.php';
} else {
    require_once __DIR__ . '/../admin-init.php';
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin only.');
    }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:20px;'>";
}

function out017(string $msg, string $t = 'info'): void {
    $p = $t === 'ok' ? '[OK]' : ($t === 'warn' ? '[WARN]' : '[INFO]');
    echo $p . ' ' . $msg . PHP_EOL;
}

function colExists017(PDO $pdo, string $table, string $col): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

try {
    // Extend status enum to include 'voided' (separate from cancelled — voided=after-paid reversal)
    $pdo->exec("ALTER TABLE stock_orders MODIFY status ENUM('placed','paid','cancelled','voided','pending','confirmed') NOT NULL DEFAULT 'placed'");
    out017('stock_orders.status now allows voided', 'ok');

    $colsToAdd = [
        'payment_method'         => "ENUM('cash','mobile_money','card_manual','card_pos','other') NULL DEFAULT NULL AFTER status",
        'tendered_amount'        => "DECIMAL(12,2) NULL DEFAULT NULL AFTER payment_method",
        'change_due'             => "DECIMAL(12,2) NULL DEFAULT NULL AFTER tendered_amount",
        'mobile_wallet_provider' => "VARCHAR(50) NULL DEFAULT NULL AFTER change_due",
        'mobile_wallet_reference'=> "VARCHAR(100) NULL DEFAULT NULL AFTER mobile_wallet_provider",
        'card_last4'             => "VARCHAR(4) NULL DEFAULT NULL AFTER mobile_wallet_reference",
        'card_auth_code'         => "VARCHAR(50) NULL DEFAULT NULL AFTER card_last4",
        'pos_terminal_ref'       => "VARCHAR(100) NULL DEFAULT NULL AFTER card_auth_code",
        'paid_at'                => "TIMESTAMP NULL DEFAULT NULL AFTER pos_terminal_ref",
        'voided_by'              => "INT UNSIGNED NULL DEFAULT NULL AFTER paid_at",
        'void_reason'            => "VARCHAR(500) NULL DEFAULT NULL AFTER voided_by",
        'voided_at'              => "TIMESTAMP NULL DEFAULT NULL AFTER void_reason",
    ];
    foreach ($colsToAdd as $col => $def) {
        if (colExists017($pdo, 'stock_orders', $col)) {
            out017("stock_orders.{$col} already exists — skip");
            continue;
        }
        $pdo->exec("ALTER TABLE stock_orders ADD COLUMN {$col} {$def}");
        out017("stock_orders.{$col} added", 'ok');
    }

    // Helpful index for shift reconciliation reports
    $idxExists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_orders' AND INDEX_NAME = 'idx_stock_orders_paid_method'");
    $idxExists->execute();
    if ((int)$idxExists->fetchColumn() === 0) {
        $pdo->exec("CREATE INDEX idx_stock_orders_paid_method ON stock_orders (paid_at, payment_method)");
        out017('idx_stock_orders_paid_method created', 'ok');
    } else {
        out017('idx_stock_orders_paid_method already exists');
    }

    // Audit log table for POS actions (lightweight; admin_activity_log already exists for app-wide)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_order_audit (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id INT UNSIGNED NOT NULL,
            actor_id INT UNSIGNED NULL,
            actor_name VARCHAR(150) NULL,
            event VARCHAR(40) NOT NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_soa_order (order_id),
            KEY idx_soa_actor (actor_id),
            KEY idx_soa_event (event),
            CONSTRAINT fk_soa_order FOREIGN KEY (order_id) REFERENCES stock_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out017('stock_order_audit table OK', 'ok');

    // Map payment_method values onto the existing payments.payment_method enum:
    //  cash         -> cash
    //  mobile_money -> mobile_money
    //  card_manual  -> credit_card
    //  card_pos     -> credit_card  (provision)
    //  other        -> other
    out017('=== Migration 017 complete ===');
} catch (Throwable $e) {
    out017('FAILED: ' . $e->getMessage(), 'warn');
    if (!$isCli017) echo "</pre>";
    exit(1);
}

if (!$isCli017) echo "</pre>";

