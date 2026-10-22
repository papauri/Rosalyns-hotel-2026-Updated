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

            // ── Over-delivery flag on PO lines (supplier delivered more than ordered; note is mandatory) ──
            rh_add_column($pdo, 'stock_purchase_order_items', 'over_received_qty', "DECIMAL(12,3) NOT NULL DEFAULT 0");
            rh_add_column($pdo, 'stock_purchase_order_items', 'over_note', "VARCHAR(500) DEFAULT NULL");

            // ── Purchase packs: an item is counted in its stock unit but may be bought in packs ──
            // pack_size NULL = no pack. PO lines always hold stock-unit quantities and costs; order_in and
            // pack_size_snapshot only remember how the line was typed so it can be shown as "2 crates = 48 bottles".
            rh_add_column($pdo, 'stock_ingredients', 'pack_name', "VARCHAR(30) DEFAULT NULL");
            rh_add_column($pdo, 'stock_ingredients', 'pack_size', "DECIMAL(12,3) DEFAULT NULL");
            rh_add_column($pdo, 'stock_purchase_order_items', 'order_in', "VARCHAR(8) NOT NULL DEFAULT 'unit'");
            rh_add_column($pdo, 'stock_purchase_order_items', 'pack_size_snapshot', "DECIMAL(12,3) DEFAULT NULL");
            rh_add_column($pdo, 'stock_purchase_order_items', 'pack_name_snapshot', "VARCHAR(30) DEFAULT NULL");

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

/* ── Purchase packs (e.g. a crate of 24 bottles) ───────────────────────────────────────────────
 * Stock, batches, counts, costs and the budget always stay in the item's STOCK unit. A pack only
 * changes how a purchase-order line is typed and how a buying suggestion is worded. */

if (!function_exists('rh_pack_label')) {
    /** "crate" -> "crate" / "crates" depending on the number. */
    function rh_pack_label(?string $name, float $n): string
    {
        $name = trim((string)$name);
        if ($name === '') {
            $name = 'pack';
        }
        if (abs($n - 1.0) < 0.0005) {
            return $name;
        }
        if (preg_match('/(s|x|z|ch|sh)$/i', $name)) {
            return $name . 'es';
        }
        return $name . 's';
    }
}

if (!function_exists('rh_unit_label')) {
    /** Stock unit worded for a number: "bottle" -> "bottles"; short codes (kg, L, pcs, g) are left alone. */
    function rh_unit_label(?string $unit, float $n): string
    {
        $unit = trim((string)$unit);
        if ($unit === '' || abs($n - 1.0) < 0.0005 || !preg_match('/^[a-z]{4,}$/i', $unit) || preg_match('/s$/i', $unit)) {
            return $unit;
        }
        return rh_pack_label($unit, $n);
    }
}

if (!function_exists('rh_pack_num')) {
    /** Plain number text: no trailing zeros, thousands separated. */
    function rh_pack_num(float $n, int $dp = 3): string
    {
        $s = number_format($n, $dp, '.', ',');
        return strpos($s, '.') !== false ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}

if (!function_exists('rh_pack_convert')) {
    /**
     * Turn what was typed on a PO line into stock-unit figures.
     *
     * @param string $orderIn   'unit' or 'pack': what $qty counts.
     * @param float  $qty       Number of units or packs ordered.
     * @param float  $price     Price typed (0 = none).
     * @param string $priceIn   'unit' or 'pack': what $price is per.
     * @param ?float $packSize  Units in one pack (null / 0 = the item has no pack).
     * @return array{order_in:string,packs:?float,ordered_qty:float,unit_cost:float,line_total:float,pack_size:?float}
     */
    function rh_pack_convert(string $orderIn, float $qty, float $price, string $priceIn, ?float $packSize): array
    {
        $hasPack = $packSize !== null && $packSize > 0.0;
        $orderIn = $orderIn === 'pack' ? 'pack' : 'unit';
        $priceIn = $priceIn === 'pack' ? 'pack' : 'unit';
        if (($orderIn === 'pack' || $priceIn === 'pack') && !$hasPack) {
            throw new RuntimeException('This item has no pack size. Enter the quantity and price per unit, or set a pack size on the item first.');
        }
        $units = $orderIn === 'pack' ? round($qty * (float)$packSize, 6) : round($qty, 6);
        $unitCost = $priceIn === 'pack' ? round($price / (float)$packSize, 4) : round($price, 4);
        return [
            'order_in'    => $orderIn,
            'packs'       => $orderIn === 'pack' ? $qty : null,
            'ordered_qty' => $units,
            'unit_cost'   => $unitCost,
            'line_total'  => round($units * $unitCost, 2),
            'pack_size'   => $hasPack ? (float)$packSize : null,
        ];
    }
}

if (!function_exists('rh_po_line_figures')) {
    /**
     * Stock-unit figures for a PO line. The pack size always comes from the item in the database (never from
     * the form). Ordered quantity, unit cost and line total are in the item's STOCK unit whatever was typed.
     * Returns rh_pack_convert() plus pack_name.
     */
    function rh_po_line_figures(PDO $pdo, int $ingredientId, string $orderIn, float $qty, float $price, string $priceIn): array
    {
        $packSize = null; $packName = null;
        if ($ingredientId > 0) {
            $st = $pdo->prepare("SELECT pack_size, pack_name FROM stock_ingredients WHERE id = ?");
            $st->execute([$ingredientId]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $packSize = $r['pack_size'] !== null && (float)$r['pack_size'] > 0 ? (float)$r['pack_size'] : null;
                $packName = $r['pack_name'] !== null && trim((string)$r['pack_name']) !== '' ? trim((string)$r['pack_name']) : null;
            }
        }
        $f = rh_pack_convert($orderIn, $qty, $price, $priceIn, $packSize);
        $f['pack_name'] = $packName;
        if ($f['ordered_qty'] <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }
        return $f;
    }
}

if (!function_exists('rh_pack_suggest')) {
    /**
     * Round a buying suggestion (in stock units) up to whole packs when the item has a pack size.
     *
     * @return array{packs:?int,units:float}  packs is null when the item has no pack.
     */
    function rh_pack_suggest(float $units, ?float $packSize): array
    {
        if ($packSize === null || $packSize <= 0.0 || $units <= 0.0001) {
            return ['packs' => null, 'units' => $units];
        }
        // A few thousandths of a unit over a whole pack is rounding dust, not another pack.
        $packs = (int)ceil(($units - 0.0005) / $packSize);
        $packs = max(1, $packs);
        return ['packs' => $packs, 'units' => round($packs * $packSize, 3)];
    }
}

if (!function_exists('rh_pack_describe')) {
    /** "2 crates = 48 bottles" for a pack line, else "48 bottles" (unit may be blank). */
    function rh_pack_describe(float $units, ?string $unit, ?string $orderIn, ?float $packSize, ?string $packName): string
    {
        $u = rh_unit_label($unit, $units);
        $base = rh_pack_num($units) . ($u !== '' ? ' ' . $u : '');
        if ($orderIn === 'pack' && $packSize !== null && $packSize > 0) {
            $packs = $units / $packSize;
            return rh_pack_num($packs) . ' ' . rh_pack_label($packName, $packs) . ' = ' . $base;
        }
        return $base;
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
        ?string $notes = null,
        ?string $adjustmentReason = null
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
        // Blank / 0 cost falls back to the current average (never dilutes the weighted average);
        // refuses when there is no cost at all.
        $cost = rh_stock_receipt_cost($cost, $oldAvg);
        // Settle any unallocated deficit (sales beyond batches) so batch sum == current_quantity.
        $settle = rh_stock_receipt_settlement($pdo, $ingredientId, $oldQty, $qty);
        $batchRemaining = $settle['remaining'];
        $batchStatus = $batchRemaining < 0.0001 ? 'depleted' : 'active';
        if ($settle['settled'] > 0) {
            // Late cost: sales made at zero cost for this deficit get costed at this delivery's unit cost.
            rh_stock_backcost_settled_deficit($pdo, $ingredientId, (float)$settle['deficit'], (float)$settle['settled'], $cost, $doneBy, $purchaseOrderId ? 'PO #' . $purchaseOrderId : 'receipt');
            $notes = trim(($notes ? $notes . ' | ' : '') . 'Settled ' . rtrim(rtrim(number_format($settle['settled'], 6, '.', ''), '0'), '.') . ' unallocated deficit');
        }
        // Combined batch / stock_in_log note stays short (PO ref + over-delivery note + settlement text).
        $notes = $notes !== null ? mb_substr($notes, 0, 250) : null;
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
                VALUES (?, '', ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)
            ");
            $bIns->execute([
                $ingredientId, $qty, $batchRemaining, $cost, $supplierId ?: null, $purchaseOrderId ?: null,
                $supplierName ?: null, $supplierContact ?: null,
                ($expiry !== null && $expiry !== '') ? $expiry : null, $alertDays, $batchStatus, $notes ?: null, $doneBy,
            ]);
        } else {
            $bIns = $pdo->prepare("
                INSERT INTO stock_batches
                    (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit,
                     supplier_name, supplier_contact, received_date, expiry_date, expiry_alert_days, status, notes, created_by)
                VALUES (?, '', ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)
            ");
            $bIns->execute([
                $ingredientId, $qty, $batchRemaining, $cost, $supplierName ?: null, $supplierContact ?: null,
                ($expiry !== null && $expiry !== '') ? $expiry : null, $alertDays, $batchStatus, $notes ?: null, $doneBy,
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
        ")->execute([$ingredientId, $qty, $adjustmentReason !== null && $adjustmentReason !== ''
            ? mb_substr($adjustmentReason . ($settle['settled'] > 0 ? ' (' . rtrim(rtrim(number_format($settle['settled'], 6, '.', ''), '0'), '.') . ' settled unallocated deficit)' : ''), 0, 255)
            : ($settle['settled'] > 0
                ? 'Received against purchase order (' . rtrim(rtrim(number_format($settle['settled'], 6, '.', ''), '0'), '.') . ' settled unallocated deficit)'
                : 'Received against purchase order'), $batchId, $cost, $doneBy]);

        return $batchId;
    }
}

if (!function_exists('rh_backfill_suppliers_from_batches')) {
    /**
     * One-time-safe: create supplier master rows from distinct free-text
     * supplier_name values already present on batches / stock_in_log, then
     * link those rows back by supplier_id. Idempotent — only fills gaps.
     *
     * Only rows NOT yet linked (supplier_id IS NULL) are considered. Previously
     * every historical name was re-checked on each page load, so renaming a
     * supplier re-created its old name as a ghost duplicate (the old text still
     * sits on its batches), and a deleted supplier would have come back.
     *
     * @return int Number of supplier rows created.
     */
    function rh_backfill_suppliers_from_batches(PDO $pdo): int
    {
        if (!rh_table_exists($pdo, 'stock_suppliers')) {
            return 0;
        }
        $created = 0;

        $batchUnlinked = rh_column_exists($pdo, 'stock_batches', 'supplier_id') ? ' AND supplier_id IS NULL' : '';
        $logUnlinked   = rh_column_exists($pdo, 'stock_in_log', 'supplier_id') ? ' AND supplier_id IS NULL' : '';

        // Distinct non-empty supplier names on not-yet-linked receiving rows.
        $names = $pdo->query("
            SELECT TRIM(supplier_name) AS nm, MAX(supplier_contact) AS ct
            FROM (
                SELECT supplier_name, supplier_contact FROM stock_batches
                WHERE supplier_name IS NOT NULL AND TRIM(supplier_name) <> ''{$batchUnlinked}
                UNION ALL
                SELECT supplier_name, supplier_contact FROM stock_in_log
                WHERE supplier_name IS NOT NULL AND TRIM(supplier_name) <> ''{$logUnlinked}
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

if (!function_exists('rh_supplier_usage')) {
    /**
     * How much stock/purchasing history references a supplier. Anything in
     * deliveries or purchase_orders means the supplier must be kept (deactivate,
     * don't delete); preferred_items are just defaults and can be unassigned.
     *
     * @return array{deliveries:int,purchase_orders:int,preferred_items:int}
     */
    function rh_supplier_usage(PDO $pdo, int $supplierId): array
    {
        $count = static function (string $sql) use ($pdo, $supplierId): int {
            $st = $pdo->prepare($sql);
            $st->execute([$supplierId]);
            return (int)$st->fetchColumn();
        };

        $batches = rh_column_exists($pdo, 'stock_batches', 'supplier_id')
            ? $count("SELECT COUNT(*) FROM stock_batches WHERE supplier_id = ?") : 0;
        $logs = rh_column_exists($pdo, 'stock_in_log', 'supplier_id')
            ? $count("SELECT COUNT(*) FROM stock_in_log WHERE supplier_id = ?") : 0;
        $pos = rh_table_exists($pdo, 'stock_purchase_orders')
            ? $count("SELECT COUNT(*) FROM stock_purchase_orders WHERE supplier_id = ?") : 0;
        $preferred = rh_column_exists($pdo, 'stock_ingredients', 'preferred_supplier_id')
            ? $count("SELECT COUNT(*) FROM stock_ingredients WHERE preferred_supplier_id = ?") : 0;

        return [
            'deliveries'      => max($batches, $logs),
            'purchase_orders' => $pos,
            'preferred_items' => $preferred,
        ];
    }
}

if (!function_exists('rh_supplier_id_by_name')) {
    /**
     * Resolve a typed supplier name to its master record (the name column's
     * collation makes this case-insensitive). Returns null when there's no
     * match, so the delivery stays a free-text one-off.
     */
    function rh_supplier_id_by_name(PDO $pdo, ?string $name): ?int
    {
        $name = trim((string)$name);
        if ($name === '' || !rh_table_exists($pdo, 'stock_suppliers')) {
            return null;
        }
        $st = $pdo->prepare("SELECT id FROM stock_suppliers WHERE name = ? LIMIT 1");
        $st->execute([$name]);
        $id = (int)($st->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    }
}
