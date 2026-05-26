<?php
/**
 * Migration 021: Minimalist POS enhancements (parity with Square / Toast / Loyverse).
 *
 *  - stock_order_items.notes        — per-line modifiers ("no onion", "spicy", "well done")
 *  - stock_orders.kitchen_printed_at — when the KOT was sent / printed
 *  - stock_orders.served_at          — when food was marked served (future-friendly)
 *  - stock_orders.opened_as_tab      — 1 if order was parked before payment (open-tab flow)
 *  - stock_shift_closes              — Z-report: cashier declares counted cash vs expected,
 *                                      records variance per tender for anti-theft.
 */
require_once __DIR__ . '/../../config/database.php';

function out021(string $m, string $t = 'info'): void { echo "[$t] $m\n"; }

function colExists021(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
}
function tableExists021(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    if (!colExists021($pdo, 'stock_order_items', 'notes')) {
        $pdo->exec("ALTER TABLE stock_order_items ADD COLUMN notes VARCHAR(255) NULL AFTER line_total");
        out021('stock_order_items.notes added', 'ok');
    } else { out021('stock_order_items.notes exists', 'skip'); }

    foreach (['kitchen_printed_at' => 'DATETIME NULL', 'served_at' => 'DATETIME NULL', 'opened_as_tab' => "TINYINT(1) NOT NULL DEFAULT 0"] as $c => $def) {
        if (!colExists021($pdo, 'stock_orders', $c)) {
            $pdo->exec("ALTER TABLE stock_orders ADD COLUMN {$c} {$def}");
            out021("stock_orders.{$c} added", 'ok');
        } else { out021("stock_orders.{$c} exists", 'skip'); }
    }

    if (!tableExists021($pdo, 'stock_shift_closes')) {
        $pdo->exec("
            CREATE TABLE stock_shift_closes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                user_name VARCHAR(150) NOT NULL,
                shift_date DATE NOT NULL,
                closed_at DATETIME NOT NULL,
                expected_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
                declared_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
                variance_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
                expected_mobile DECIMAL(12,2) NOT NULL DEFAULT 0,
                declared_mobile DECIMAL(12,2) NOT NULL DEFAULT 0,
                variance_mobile DECIMAL(12,2) NOT NULL DEFAULT 0,
                expected_card DECIMAL(12,2) NOT NULL DEFAULT 0,
                declared_card DECIMAL(12,2) NOT NULL DEFAULT 0,
                variance_card DECIMAL(12,2) NOT NULL DEFAULT 0,
                orders_count INT NOT NULL DEFAULT 0,
                voids_count INT NOT NULL DEFAULT 0,
                voids_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                notes TEXT NULL,
                ip_address VARCHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user_date (user_id, shift_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        out021('stock_shift_closes table created', 'ok');
    } else { out021('stock_shift_closes exists', 'skip'); }

    out021('Migration 021 done.', 'done');
} catch (Throwable $e) {
    out021('FAIL: ' . $e->getMessage(), 'err');
    exit(1);
}
