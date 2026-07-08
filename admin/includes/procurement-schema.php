<?php

/**
 * Procurement schema bootstrap.
 *
 * Idempotent, additive-only DDL for the supplier master and purchasing
 * (purchase order) workflow, plus reorder/par columns on stock_ingredients.
 * Safe to call on every request — a static guard makes it run once, and each
 * change is guarded by an information_schema existence check so re-running is
 * a no-op. Mirrors the "self-healing schema" convention used elsewhere.
 *
 * Call ensureProcurementSchema($pdo) at the top of any page that reads or
 * writes suppliers / purchase orders / reorder fields.
 */

if (!function_exists('rh_table_exists')) {
    function rh_table_exists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('rh_column_exists')) {
    function rh_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('rh_add_column')) {
    /**
     * ADD COLUMN only if it does not already exist. MySQL 8 has no
     * "ADD COLUMN IF NOT EXISTS", so we guard via information_schema.
     * $definition is the column spec after the name, e.g. "DECIMAL(12,4) NOT NULL DEFAULT 0".
     */
    function rh_add_column(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (rh_column_exists($pdo, $table, $column)) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

if (!function_exists('rh_index_exists')) {
    function rh_index_exists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
        );
        $stmt->execute([$table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('rh_add_index')) {
    function rh_add_index(PDO $pdo, string $table, string $index, string $columns): void
    {
        if (rh_index_exists($pdo, $table, $index)) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
    }
}

if (!function_exists('ensureProcurementSchema')) {
    function ensureProcurementSchema(PDO $pdo): bool
    {
        static $done = null;
        if ($done !== null) {
            return $done;
        }

        try {
            // ── Supplier master ──────────────────────────────────────────────
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS stock_suppliers (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    contact_name VARCHAR(255) DEFAULT NULL,
                    email VARCHAR(255) DEFAULT NULL,
                    phone VARCHAR(60) DEFAULT NULL,
                    address VARCHAR(500) DEFAULT NULL,
                    lead_time_days SMALLINT UNSIGNED NOT NULL DEFAULT 3,
                    payment_terms VARCHAR(100) DEFAULT NULL,
                    account_ref VARCHAR(100) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_by INT UNSIGNED DEFAULT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_supplier_name (name),
                    KEY idx_supplier_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // ── Purchase orders (procurement header) ─────────────────────────
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS stock_purchase_orders (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    reference VARCHAR(50) NOT NULL,
                    supplier_id INT UNSIGNED DEFAULT NULL,
                    status ENUM('draft','sent','partial','received','closed','cancelled') NOT NULL DEFAULT 'draft',
                    order_date DATE NOT NULL,
                    expected_date DATE DEFAULT NULL,
                    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
                    total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
                    notes TEXT DEFAULT NULL,
                    created_by INT UNSIGNED DEFAULT NULL,
                    sent_at TIMESTAMP NULL DEFAULT NULL,
                    received_at TIMESTAMP NULL DEFAULT NULL,
                    closed_at TIMESTAMP NULL DEFAULT NULL,
                    cancelled_at TIMESTAMP NULL DEFAULT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_po_reference (reference),
                    KEY idx_po_supplier (supplier_id),
                    KEY idx_po_status (status),
                    KEY idx_po_order_date (order_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // ── Purchase order lines ─────────────────────────────────────────
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS stock_purchase_order_items (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    purchase_order_id INT UNSIGNED NOT NULL,
                    ingredient_id INT UNSIGNED DEFAULT NULL,
                    description VARCHAR(255) NOT NULL,
                    unit VARCHAR(50) DEFAULT NULL,
                    ordered_qty DECIMAL(12,4) NOT NULL DEFAULT 0,
                    received_qty DECIMAL(12,4) NOT NULL DEFAULT 0,
                    unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
                    line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_poi_po (purchase_order_id),
                    KEY idx_poi_ingredient (ingredient_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // ── Reorder / par columns on ingredients ─────────────────────────
            rh_add_column($pdo, 'stock_ingredients', 'reorder_point', "DECIMAL(12,4) NOT NULL DEFAULT 0");
            rh_add_column($pdo, 'stock_ingredients', 'par_level', "DECIMAL(12,4) NOT NULL DEFAULT 0");
            rh_add_column($pdo, 'stock_ingredients', 'lead_time_days', "SMALLINT UNSIGNED NOT NULL DEFAULT 0");
            rh_add_column($pdo, 'stock_ingredients', 'preferred_supplier_id', "INT UNSIGNED DEFAULT NULL");
            rh_add_index($pdo, 'stock_ingredients', 'idx_ing_pref_supplier', 'preferred_supplier_id');

            // ── Supplier linkage on receiving tables ─────────────────────────
            rh_add_column($pdo, 'stock_batches', 'supplier_id', "INT UNSIGNED DEFAULT NULL");
            rh_add_column($pdo, 'stock_batches', 'purchase_order_id', "INT UNSIGNED DEFAULT NULL");
            rh_add_index($pdo, 'stock_batches', 'idx_batch_supplier', 'supplier_id');
            rh_add_column($pdo, 'stock_in_log', 'supplier_id', "INT UNSIGNED DEFAULT NULL");
            rh_add_column($pdo, 'stock_in_log', 'purchase_order_id', "INT UNSIGNED DEFAULT NULL");
            rh_add_index($pdo, 'stock_in_log', 'idx_stockin_supplier', 'supplier_id');

            $done = true;
        } catch (Throwable $e) {
            error_log('ensureProcurementSchema error: ' . $e->getMessage());
            $done = false;
        }

        return $done;
    }
}

if (!function_exists('rh_next_po_reference')) {
    /**
     * Generate the next sequential purchase-order reference (PO-000001).
     * Derives from MAX(id) so it stays gapless-enough for display without a
     * separate sequence table.
     */
    function rh_next_po_reference(PDO $pdo): string
    {
        $max = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM stock_purchase_orders")->fetchColumn();
        return 'PO-' . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('rh_receive_stock_line')) {
    /**
     * Receive a quantity of an ingredient into stock: creates a batch, a
     * stock_in_log row, a stock_adjustments row, and updates the ingredient's
     * current_quantity + weighted-average cost. Mirrors the manual stock-in
     * flow in stock-ingredients.php so PO receiving and manual receiving stay
     * costing-consistent. Must be called inside a transaction by the caller.
     *
     * @return int The new batch id.
     */
    function rh_receive_stock_line(
        PDO $pdo,
        int $ingredientId,
        float $qty,
        float $cost,
        ?int $supplierId,
        ?string $supplierName,
        ?string $supplierContact,
        ?string $expiry,
        int $alertDays,
        ?int $purchaseOrderId,
        ?int $doneBy,
        ?string $notes = null
    ): int {
        if ($qty <= 0) {
            throw new RuntimeException('Receive quantity must be greater than zero.');
        }

        $sel = $pdo->prepare("SELECT current_quantity, cost_per_unit FROM stock_ingredients WHERE id = ? FOR UPDATE");
        $sel->execute([$ingredientId]);
        $ing = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$ing) {
            throw new RuntimeException('Ingredient #' . $ingredientId . ' not found.');
        }

        $oldQty = (float)$ing['current_quantity'];
        $oldAvg = (float)$ing['cost_per_unit'];
        $newAvg = function_exists('calculateWeightedAvgCost')
            ? calculateWeightedAvgCost($oldQty, $oldAvg, $qty, $cost)
            : (($oldQty + $qty) > 0 ? (($oldQty * $oldAvg) + ($qty * $cost)) / ($oldQty + $qty) : $cost);

        $hasBatchSupplier = rh_column_exists($pdo, 'stock_batches', 'supplier_id');
        $hasLogSupplier   = rh_column_exists($pdo, 'stock_in_log', 'supplier_id');

        // Batch
        if ($hasBatchSupplier) {
            $bIns = $pdo->prepare("
                INSERT INTO stock_batches
                    (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit,
                     supplier_id, purchase_order_id, supplier_name, supplier_contact, received_date, expiry_date, expiry_alert_days, status, notes, created_by)
                VALUES (?, '', ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, 'active', ?, ?)
            ");
            $bIns->execute([
                $ingredientId, $qty, $qty, $cost, $supplierId ?: null, $purchaseOrderId ?: null,
                $supplierName ?: null, $supplierContact ?: null,
                ($expiry !== null && $expiry !== '') ? $expiry : null, $alertDays, $notes ?: null, $doneBy,
            ]);
        } else {
            $bIns = $pdo->prepare("
                INSERT INTO stock_batches
                    (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit,
                     supplier_name, supplier_contact, received_date, expiry_date, expiry_alert_days, status, notes, created_by)
                VALUES (?, '', ?, ?, ?, ?, ?, CURDATE(), ?, ?, 'active', ?, ?)
            ");
            $bIns->execute([
                $ingredientId, $qty, $qty, $cost, $supplierName ?: null, $supplierContact ?: null,
                ($expiry !== null && $expiry !== '') ? $expiry : null, $alertDays, $notes ?: null, $doneBy,
            ]);
        }
        $batchId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE stock_batches SET batch_number = ? WHERE id = ?")
            ->execute(['B' . str_pad((string)$batchId, 6, '0', STR_PAD_LEFT), $batchId]);

        // Stock-in log
        if ($hasLogSupplier) {
            $logIns = $pdo->prepare("
                INSERT INTO stock_in_log
                    (ingredient_id, batch_id, quantity, cost_per_unit, cost_total, supplier_id, purchase_order_id, supplier_name, supplier_contact,
                     avg_cost_before, avg_cost_after, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $logIns->execute([
                $ingredientId, $batchId, $qty, $cost, $qty * $cost, $supplierId ?: null, $purchaseOrderId ?: null,
                $supplierName ?: null, $supplierContact ?: null, $oldAvg, $newAvg, $notes ?: null, $doneBy,
            ]);
        } else {
            $logIns = $pdo->prepare("
                INSERT INTO stock_in_log
                    (ingredient_id, batch_id, quantity, cost_per_unit, cost_total, supplier_name, supplier_contact,
                     avg_cost_before, avg_cost_after, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $logIns->execute([
                $ingredientId, $batchId, $qty, $cost, $qty * $cost, $supplierName ?: null, $supplierContact ?: null,
                $oldAvg, $newAvg, $notes ?: null, $doneBy,
            ]);
        }

        // Ingredient qty + weighted avg
        $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ?, cost_per_unit = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$qty, $newAvg, $ingredientId]);

        // Adjustment ledger
        $pdo->prepare("
            INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by)
            VALUES (?, ?, ?, 'stock_in', ?, ?, ?)
        ")->execute([$ingredientId, $qty, 'Received against purchase order', $batchId, $cost, $doneBy]);

        return $batchId;
    }
}

if (!function_exists('rh_backfill_suppliers_from_batches')) {
    /**
     * One-time-safe: create supplier master rows from distinct free-text
     * supplier_name values already present on batches / stock_in_log, then
     * link those rows back by supplier_id. Idempotent — only fills gaps.
     *
     * @return int Number of supplier rows created.
     */
    function rh_backfill_suppliers_from_batches(PDO $pdo): int
    {
        if (!rh_table_exists($pdo, 'stock_suppliers')) {
            return 0;
        }
        $created = 0;

        // Distinct non-empty supplier names across both receiving tables.
        $names = $pdo->query("
            SELECT DISTINCT TRIM(supplier_name) AS nm, MAX(supplier_contact) AS ct
            FROM (
                SELECT supplier_name, supplier_contact FROM stock_batches
                WHERE supplier_name IS NOT NULL AND TRIM(supplier_name) <> ''
                UNION ALL
                SELECT supplier_name, supplier_contact FROM stock_in_log
                WHERE supplier_name IS NOT NULL AND TRIM(supplier_name) <> ''
            ) t
            GROUP BY TRIM(supplier_name)
        ")->fetchAll(PDO::FETCH_ASSOC);

        $findByName = $pdo->prepare("SELECT id FROM stock_suppliers WHERE name = ? LIMIT 1");
        $insSup = $pdo->prepare("INSERT INTO stock_suppliers (name, phone, is_active) VALUES (?, ?, 1)");

        foreach ($names as $r) {
            $nm = trim((string)$r['nm']);
            if ($nm === '') {
                continue;
            }
            $findByName->execute([$nm]);
            $sid = (int)($findByName->fetchColumn() ?: 0);
            if ($sid <= 0) {
                $insSup->execute([mb_substr($nm, 0, 255), mb_substr((string)($r['ct'] ?? ''), 0, 60) ?: null]);
                $sid = (int)$pdo->lastInsertId();
                $created++;
            }
            // Link existing rows lacking a supplier_id.
            if (rh_column_exists($pdo, 'stock_batches', 'supplier_id')) {
                $pdo->prepare("UPDATE stock_batches SET supplier_id = ? WHERE supplier_id IS NULL AND TRIM(supplier_name) = ?")
                    ->execute([$sid, $nm]);
            }
            if (rh_column_exists($pdo, 'stock_in_log', 'supplier_id')) {
                $pdo->prepare("UPDATE stock_in_log SET supplier_id = ? WHERE supplier_id IS NULL AND TRIM(supplier_name) = ?")
                    ->execute([$sid, $nm]);
            }
        }

        return $created;
    }
}
