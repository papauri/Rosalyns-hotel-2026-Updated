<?php
/**
 * Migration 018 — Stock count / variance reconciliation.
 *
 * Goal: every gram of physical stock that disappears MUST tie back to either
 * (a) a recorded sale (pos_order / room_service), (b) recorded wastage,
 * (c) recorded expiry/recall, or (d) an explicit variance from a stock count
 * with a reason and a named accountable manager. Anything else = theft signal.
 */
$isCli018 = (PHP_SAPI === 'cli');

if ($isCli018) {
    require_once __DIR__ . '/../../config/database.php';
} else {
    require_once __DIR__ . '/../admin-init.php';
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin only.');
    }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:20px;'>";
}

function out018(string $m, string $t = 'info'): void {
    echo ($t === 'ok' ? '[OK] ' : ($t === 'warn' ? '[WARN] ' : '[INFO] ')) . $m . PHP_EOL;
}

try {
    // 1) Extend source_type enum to include 'variance' (physical count delta)
    $pdo->exec("ALTER TABLE stock_adjustments MODIFY source_type ENUM(
        'pos_order','room_service','manual','stock_in','void_restore',
        'wastage','expiry','recall','variance'
    ) NOT NULL DEFAULT 'manual'");
    out018('stock_adjustments.source_type now allows variance', 'ok');

    // 2) stock_counts header — one row per count session
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_counts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference VARCHAR(50) NOT NULL,
            count_date DATE NOT NULL,
            shift VARCHAR(30) NULL,
            scope ENUM('full','category','spot') NOT NULL DEFAULT 'spot',
            scope_value VARCHAR(100) NULL,
            status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
            counted_by INT UNSIGNED NULL,
            approved_by INT UNSIGNED NULL,
            approved_at TIMESTAMP NULL DEFAULT NULL,
            rejection_reason VARCHAR(500) NULL,
            total_variance_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            shortage_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            surplus_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sc_ref (reference),
            KEY idx_sc_date (count_date),
            KEY idx_sc_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out018('stock_counts table OK', 'ok');

    // 3) stock_count_lines — per-ingredient reading
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_count_lines (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            count_id INT UNSIGNED NOT NULL,
            ingredient_id INT UNSIGNED NOT NULL,
            system_quantity DECIMAL(12,4) NOT NULL DEFAULT 0,
            actual_quantity DECIMAL(12,4) NOT NULL DEFAULT 0,
            variance DECIMAL(12,4) NOT NULL DEFAULT 0,
            cost_per_unit DECIMAL(12,4) NOT NULL DEFAULT 0,
            variance_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
            reason_code ENUM('','spillage','expired','staff_meal','sampling','prep_waste','theft_suspected','correction','other') NOT NULL DEFAULT '',
            reason_notes VARCHAR(500) NULL,
            adjustment_id INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_scl_count_ing (count_id, ingredient_id),
            KEY idx_scl_count (count_id),
            KEY idx_scl_ing (ingredient_id),
            CONSTRAINT fk_scl_count FOREIGN KEY (count_id) REFERENCES stock_counts(id) ON DELETE CASCADE,
            CONSTRAINT fk_scl_ing FOREIGN KEY (ingredient_id) REFERENCES stock_ingredients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out018('stock_count_lines table OK', 'ok');

    // 4) Optional: shortage tolerance thresholds in site_settings
    $vals = [
        'stock_variance_alert_pct'   => '2',     // 2% variance flags for review
        'stock_variance_block_pct'   => '10',    // 10%+ blocks auto-approval, requires admin
        'stock_variance_min_cost'    => '5000',  // any line < MWK 5,000 cost is auto-tolerable
    ];
    foreach ($vals as $k => $v) {
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = setting_value")
             ->execute([$k, $v]);
    }
    out018('variance threshold settings ensured', 'ok');

    out018('=== Migration 018 complete ===');
} catch (Throwable $e) {
    out018('FAILED: ' . $e->getMessage(), 'warn');
    if (!$isCli018) echo "</pre>";
    exit(1);
}
if (!$isCli018) echo "</pre>";

