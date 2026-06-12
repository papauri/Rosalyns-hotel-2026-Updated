<?php
/**
 * Migration 015: Stock Management System
 *
 * Creates the full stock management schema:
 *  - 10 new tables (ingredients, recipes, batches, orders, wastage, adjustments, etc.)
 *  - 1 new column on booking_charges (stock_tracked)
 *
 * Idempotent: safe to re-run. Uses IF NOT EXISTS guards everywhere.
 *
 * Usage:
 *   - CLI:     php admin/migrations/015_stock_management.php
 *   - Browser: /admin/migrations/015_stock_management.php (admin login required)
 */

// CLI vs browser detection
$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    require_once __DIR__ . '/../admin-init.php';
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin only.');
    }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:20px;'>";
} else {
    require_once __DIR__ . '/../../config/database.php';
}

function out(string $msg, string $level = 'info') {
    global $is_cli;
    $prefix = ['ok' => '[OK]   ', 'info' => '[INFO] ', 'warn' => '[WARN] ', 'err' => '[ERR]  '][$level] ?? '';
    echo $prefix . $msg . PHP_EOL;
}

out('=== Migration 015: Stock Management System ===');

global $pdo;

// NOTE: MySQL DDL (CREATE/ALTER) implicitly commits, so we don't wrap in a
// transaction. Each CREATE TABLE IF NOT EXISTS is itself idempotent.

try {
    // ---------- 1. stock_ingredients ----------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_ingredients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            category VARCHAR(100) NOT NULL DEFAULT 'General',
            unit VARCHAR(50) NOT NULL DEFAULT 'g',
            current_quantity DECIMAL(12,4) NOT NULL DEFAULT 0,
            min_quantity DECIMAL(12,4) NOT NULL DEFAULT 0,
            cost_per_unit DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'Weighted average cost',
            yield_percent DECIMAL(6,2) NOT NULL DEFAULT 100.00 COMMENT 'Default usable yield %',
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_si_category (category),
            KEY idx_si_archived (is_archived),
            KEY idx_si_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out('stock_ingredients table OK', 'ok');

    // ---------- 2. stock_recipes ----------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_recipes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            menu_item_id INT UNSIGNED NOT NULL,
            menu_type ENUM('food','drink') NOT NULL,
            portions_per_recipe INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'How many portions this recipe yields',
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_recipe_item (menu_item_id, menu_type),
            KEY idx_recipe_type (menu_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out('stock_recipes table OK', 'ok');

    // ---------- 3. stock_recipe_ingredients ----------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_recipe_ingredients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipe_id INT UNSIGNED NOT NULL,
            ingredient_id INT UNSIGNED NOT NULL,
            quantity_per_portion DECIMAL(12,4) NOT NULL,
            yield_percent DECIMAL(6,2) NOT NULL DEFAULT 100.00 COMMENT 'Per-line yield override',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sri_recipe (recipe_id),
            KEY idx_sri_ingredient (ingredient_id),
            CONSTRAINT fk_sri_recipe FOREIGN KEY (recipe_id) REFERENCES stock_recipes(id) ON DELETE CASCADE,
            CONSTRAINT fk_sri_ingredient FOREIGN KEY (ingredient_id) REFERENCES stock_ingredients(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out('stock_recipe_ingredients table OK', 'ok');

    // ---------- 4. stock_batches ----------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_batches (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ingredient_id INT UNSIGNED NOT NULL,
            batch_number VARCHAR(50) NOT NULL DEFAULT '',
            quantity_received DECIMAL(12,4) NOT NULL,
            quantity_remaining DECIMAL(12,4) NOT NULL,
            cost_per_unit DECIMAL(12,4) NOT NULL DEFAULT 0,
            supplier_name VARCHAR(255) NULL,
            supplier_contact VARCHAR(255) NULL,
            received_date DATE NOT NULL,
            expiry_date DATE NULL,
            expiry_alert_days INT UNSIGNED NOT NULL DEFAULT 7,
            status ENUM('active','depleted','expired','recalled','wasted') NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_batch_number (batch_number),
            KEY idx_sb_ingredient_status (ingredient_id, status),
            KEY idx_sb_expiry (expiry_date, status),
            KEY idx_sb_received (received_date),
            CONSTRAINT fk_sb_ingredient FOREIGN KEY (ingredient_id) REFERENCES stock_ingredients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out('stock_batches table OK', 'ok');

    // ---------- 5. stock_adjustments ----------
    // Created BEFORE stock_batch_deductions so the FK works.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_adjustments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ingredient_id INT UNSIGNED NOT NULL,
            quantity_change DECIMAL(12,4) NOT NULL COMMENT 'Signed: + add, - deduct',
            reason VARCHAR(255) NULL,
            source_type ENUM('pos_order','room_service','manual','stock_in','void_restore','wastage','expiry','recall') NOT NULL DEFAULT 'manual',
            source_id INT UNSIGNED NULL COMMENT 'order_id / charge_id / wastage_id / batch_id depending on source_type',
            cost_at_time DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'Snapshot of cost_per_unit at time of adjustment',
            adjusted_by INT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sa_ingredient (ingredient_id, created_at),
            KEY idx_sa_source (source_type, source_id),
            KEY idx_sa_created (created_at),
            CONSTRAINT fk_sa_ingredient FOREIGN KEY (ingredient_id) REFERENCES stock_ingredients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out('stock_adjustments table OK', 'ok');

    // ---------- 6. stock_batch_deductions ----------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_batch_deductions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id INT UNSIGNED NOT NULL,
            adjustment_id INT UNSIGNED NULL,
            quantity_deducted DECIMAL(12,4) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sbd_batch (batch_id),
            KEY idx_sbd_adjustment (adjustment_id),
            CONSTRAINT fk_sbd_batch FOREIGN KEY (batch_id) REFERENCES stock_batches(id) ON DELETE CASCADE,
            CONSTRAINT fk_sbd_adjustment FOREIGN KEY (adjustment_id) REFERENCES stock_adjustments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out('stock_batch_deductions table OK', 'ok');

    // ---------- 7. stock_in_log ----------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_in_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ingredient_id INT UNSIGNED NOT NULL,
            batch_id INT UNSIGNED NULL,
            quantity DECIMAL(12,4) NOT NULL,
            cost_per_unit DECIMAL(12,4) NOT NULL DEFAULT 0,
            cost_total DECIMAL(14,4) NOT NULL DEFAULT 0,
            supplier_name VARCHAR(255) NULL,
            supplier_contact VARCHAR(255) NULL,
            avg_cost_before DECIMAL(12,4) NOT NULL DEFAULT 0,
            avg_cost_after DECIMAL(12,4) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sil_ingredient (ingredient_id, created_at),
            KEY idx_sil_batch (batch_id),
            CONSTRAINT fk_sil_ingredient FOREIGN KEY (ingredient_id) REFERENCES stock_ingredients(id) ON DELETE CASCADE,
            CONSTRAINT fk_sil_batch FOREIGN KEY (batch_id) REFERENCES stock_batches(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out('stock_in_log table OK', 'ok');

    // ---------- 8. stock_orders ----------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_orders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference VARCHAR(50) NOT NULL DEFAULT '',
            order_type ENUM('walk_in','dine_in','takeaway','room_service','other') NOT NULL DEFAULT 'walk_in',
            table_number VARCHAR(50) NULL,
            customer_name VARCHAR(255) NULL,
            notes TEXT NULL,
            status ENUM('placed','paid','cancelled','pending','confirmed') NOT NULL DEFAULT 'placed',
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_cost DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'Sum of ingredient costs at time of order',
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_so_reference (reference),
            KEY idx_so_status (status),
            KEY idx_so_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out('stock_orders table OK', 'ok');

    // ---------- 9. stock_order_items ----------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_order_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id INT UNSIGNED NOT NULL,
            menu_item_id INT UNSIGNED NOT NULL,
            menu_type ENUM('food','drink') NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_soi_order (order_id),
            KEY idx_soi_menu (menu_item_id, menu_type),
            CONSTRAINT fk_soi_order FOREIGN KEY (order_id) REFERENCES stock_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out('stock_order_items table OK', 'ok');

    // ---------- 10. stock_wastage ----------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_wastage (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ingredient_id INT UNSIGNED NOT NULL,
            batch_id INT UNSIGNED NULL,
            quantity DECIMAL(12,4) NOT NULL,
            cost_per_unit DECIMAL(12,4) NOT NULL DEFAULT 0,
            wastage_cost DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'qty x cost_per_unit at time of entry',
            reason VARCHAR(255) NOT NULL DEFAULT 'other',
            recorded_date DATE NOT NULL,
            recorded_by INT UNSIGNED NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sw_ingredient (ingredient_id, recorded_date),
            KEY idx_sw_reason (reason),
            KEY idx_sw_batch (batch_id),
            KEY idx_sw_recorded_date (recorded_date),
            CONSTRAINT fk_sw_ingredient FOREIGN KEY (ingredient_id) REFERENCES stock_ingredients(id) ON DELETE CASCADE,
            CONSTRAINT fk_sw_batch FOREIGN KEY (batch_id) REFERENCES stock_batches(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out('stock_wastage table OK', 'ok');

    // ---------- 11. ALTER booking_charges ----------
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute(['booking_charges', 'stock_tracked']);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE booking_charges ADD COLUMN stock_tracked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Set to 1 only when stock was successfully deducted'");
        out('booking_charges.stock_tracked column added', 'ok');
    } else {
        out('booking_charges.stock_tracked column already exists', 'info');
    }

    // Summary
    out('');
    out('=== Migration 015 complete ===');
    $tables = ['stock_ingredients', 'stock_recipes', 'stock_recipe_ingredients', 'stock_batches', 'stock_adjustments', 'stock_batch_deductions', 'stock_in_log', 'stock_orders', 'stock_order_items', 'stock_wastage'];
    foreach ($tables as $t) {
        $cnt = $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        out(sprintf('  %-30s rows: %d', $t, (int)$cnt), 'info');
    }

} catch (Throwable $e) {
    out('FAILED: ' . $e->getMessage(), 'err');
    out('Stack: ' . $e->getTraceAsString(), 'err');
    if (!$is_cli) echo "</pre>";
    exit(1);
}

if (!$is_cli) echo "</pre>";

