<?php
/**
 * Stock/finance integrity migration.
 *
 * Keeps restaurant POS orders compatible with decimal quantities and lets paid
 * POS orders sync into the normal payments/accounting ledger.
 */
$isCli016 = (PHP_SAPI === 'cli');

if ($isCli016) {
    require_once __DIR__ . '/../../config/database.php';
} else {
    require_once __DIR__ . '/../admin-init.php';
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin only.');
    }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:20px;'>";
}

function out016(string $message, string $type = 'info'): void {
    $prefix = $type === 'ok' ? '[OK]' : ($type === 'warn' ? '[WARN]' : '[INFO]');
    echo $prefix . ' ' . $message . PHP_EOL;
}

try {
    $pdo->exec("ALTER TABLE stock_order_items MODIFY quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000");
    out016('stock_order_items.quantity supports decimals', 'ok');

    $pdo->exec("ALTER TABLE stock_orders MODIFY order_type ENUM('walk_in','dine_in','takeaway','room_service','other') NOT NULL DEFAULT 'walk_in'");
    out016('stock_orders.order_type supports walk-in POS orders', 'ok');

    $pdo->exec("ALTER TABLE payments MODIFY booking_type ENUM('room','conference','restaurant') NOT NULL");
    out016('payments.booking_type supports restaurant/POS orders', 'ok');
} catch (Throwable $e) {
    out016($e->getMessage(), 'warn');
    throw $e;
}

if (!$isCli016) {
    echo "</pre>";
}