<?php
/**
 * Migration 025: Restaurant order room-service sync.
 *
 * Adds durable links between stock_orders, checked-in bookings, individual rooms
 * and booking_charges so room-service food/drink can be managed from restaurant
 * orders while still landing on the guest folio for checkout accounting.
 */
require_once __DIR__ . '/../../config/database.php';

function out025(string $message, string $tag = 'info'): void { echo "[$tag] $message\n"; }

function colExists025(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists025(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $pdo->exec("ALTER TABLE stock_orders MODIFY status ENUM('placed','paid','cancelled','voided','pending','confirmed','completed') NOT NULL DEFAULT 'placed'");
    out025('stock_orders.status supports completed room-service orders', 'ok');

    $cols = [
        'booking_id'         => "INT UNSIGNED NULL AFTER order_type",
        'individual_room_id' => "INT UNSIGNED NULL AFTER booking_id",
        'room_number'        => "VARCHAR(50) NULL AFTER table_number",
        'folio_posted_at'    => "DATETIME NULL AFTER total_cost",
    ];
    foreach ($cols as $column => $definition) {
        if (!colExists025($pdo, 'stock_orders', $column)) {
            $pdo->exec("ALTER TABLE stock_orders ADD COLUMN {$column} {$definition}");
            out025("stock_orders.{$column} added", 'ok');
        } else {
            out025("stock_orders.{$column} exists", 'skip');
        }
    }

    foreach ([
        'idx_stock_orders_booking' => 'booking_id',
        'idx_stock_orders_room' => 'individual_room_id',
        'idx_stock_orders_type_status' => 'order_type, status',
    ] as $index => $columns) {
        if (!indexExists025($pdo, 'stock_orders', $index)) {
            $pdo->exec("CREATE INDEX {$index} ON stock_orders ({$columns})");
            out025("stock_orders.{$index} created", 'ok');
        } else {
            out025("stock_orders.{$index} exists", 'skip');
        }
    }

    if (!colExists025($pdo, 'booking_charges', 'stock_order_id')) {
        $pdo->exec("ALTER TABLE booking_charges ADD COLUMN stock_order_id INT UNSIGNED NULL AFTER booking_id");
        out025('booking_charges.stock_order_id added', 'ok');
    } else {
        out025('booking_charges.stock_order_id exists', 'skip');
    }

    if (!indexExists025($pdo, 'booking_charges', 'idx_booking_charges_stock_order')) {
        $pdo->exec("CREATE INDEX idx_booking_charges_stock_order ON booking_charges (stock_order_id)");
        out025('booking_charges.idx_booking_charges_stock_order created', 'ok');
    } else {
        out025('booking_charges.idx_booking_charges_stock_order exists', 'skip');
    }

    out025('Migration 025 complete', 'done');
} catch (Throwable $e) {
    out025('FAILED: ' . $e->getMessage(), 'err');
    exit(1);
}
