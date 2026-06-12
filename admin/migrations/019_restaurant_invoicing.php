<?php
/**
 * Migration 019: Restaurant POS — Full Financial Capacity
 *
 * Adds invoicing/receipting fields to stock_orders and a delivery log so the
 * same receipt can be re-issued to email today and WhatsApp later.
 */
require_once __DIR__ . '/../../config/database.php';

function out019(string $msg, string $tag = 'info') { echo "[$tag] $msg\n"; }

try {
    /* ---------- 1. Add invoicing/financial columns to stock_orders ---------- */
    $cols = [
        'customer_email'     => "VARCHAR(255) NULL AFTER customer_name",
        'customer_phone'     => "VARCHAR(50) NULL AFTER customer_email",
        'subtotal'           => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount",
        'discount_amount'    => "DECIMAL(12,2) NOT NULL DEFAULT 0",
        'discount_reason'    => "VARCHAR(255) NULL",
        'service_charge'     => "DECIMAL(12,2) NOT NULL DEFAULT 0",
        'tax_amount'         => "DECIMAL(12,2) NOT NULL DEFAULT 0",
        'invoice_number'     => "VARCHAR(50) NULL",
        'invoice_generated_at' => "TIMESTAMP NULL",
        'receipt_sent_at'    => "TIMESTAMP NULL",
        'receipt_sent_to'    => "VARCHAR(255) NULL",
        'receipt_send_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
        'whatsapp_sent_at'   => "TIMESTAMP NULL",
        'whatsapp_sent_to'   => "VARCHAR(50) NULL",
    ];

    $existing = $pdo->query("SHOW COLUMNS FROM stock_orders")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $col => $def) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec("ALTER TABLE stock_orders ADD COLUMN {$col} {$def}");
            out019("stock_orders.{$col} added", 'ok');
        } else {
            out019("stock_orders.{$col} already present", 'skip');
        }
    }

    // Unique index on invoice_number (NULLs allowed)
    $idx = $pdo->query("SHOW INDEX FROM stock_orders WHERE Key_name = 'uq_so_invoice_number'")->fetchAll();
    if (empty($idx)) {
        $pdo->exec("ALTER TABLE stock_orders ADD UNIQUE KEY uq_so_invoice_number (invoice_number)");
        out019('stock_orders.uq_so_invoice_number unique key added', 'ok');
    }

    /* ---------- 2. Receipt delivery log (audit + idempotency for email/WhatsApp) ---------- */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_order_deliveries (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id INT UNSIGNED NOT NULL,
            channel ENUM('email','whatsapp','print','manual') NOT NULL,
            recipient VARCHAR(255) NOT NULL,
            status ENUM('queued','sent','failed','preview') NOT NULL DEFAULT 'queued',
            error_message TEXT NULL,
            sent_by INT UNSIGNED NULL,
            sent_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sod_order (order_id),
            KEY idx_sod_channel (channel, status),
            CONSTRAINT fk_sod_order FOREIGN KEY (order_id) REFERENCES stock_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out019('stock_order_deliveries table OK', 'ok');

    /* ---------- 3. Default settings ---------- */
    $defaults = [
        'restaurant_invoice_prefix' => 'RST-',
        'restaurant_service_charge_pct' => '0',
        'restaurant_tax_pct' => '0',
        'restaurant_receipt_footer' => 'Thank you for dining with us!',
        'restaurant_whatsapp_enabled' => '0', // future provision
    ];
    $ins = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = setting_value");
    foreach ($defaults as $k => $v) {
        $ins->execute([$k, $v]);
        out019("site_settings.{$k} ensured", 'ok');
    }

    out019('Migration 019 completed.', 'done');
} catch (Throwable $e) {
    out019('FAILED: ' . $e->getMessage(), 'err');
    exit(1);
}

