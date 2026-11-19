<?php
/**
 * Stock > Change main unit (Step U3, owner 2026-11-19; migration 090).
 *
 * An item with a count measure (bottle = 30 shots) switches the measure it is KEPT in: bottle -> shot or back. One
 * transaction rescales by the factor f (to the smaller measure f = N, to the bigger f = 1/N):
 *   - the item: on hand, min / reorder point / par, purchase pack size x f; cost per unit / f; unit; measure side;
 *   - every batch of the item (received, remaining x f; cost / f) - used-up batches too, so a later restore fits them;
 *   - recipe amounts (quantity per portion x f; how the line was typed is display only and stays);
 *   - lines of OPEN purchase orders (draft / sent / part received): ordered, received, over-received, pack size x f,
 *     unit price / f; the line total and the order totals stay (the money does not change);
 *   - lines of counts not approved (draft / submitted / rejected): system, actual, difference x f, cost / f; the
 *     difference value stays; opening-stock batches of those counts x f;
 *   - barcode pack sizes x f.
 * Never rewritten: approved counts, stock_adjustments, stock_batch_deductions, stock_in_log, stock_wastage (history).
 * Restores of movements recorded before the change are rescaled by restoreStockForMenuItem() (stock_unit_changes row).
 *
 * The measure flips side: a bottle item with "shot, 30 per bottle" becomes a shot item whose bigger measure is the
 * bottle (30 shots). Stock value (item: on hand x cost; each batch: remaining x cost) must be the same before and after
 * within BALANCE_TOLERANCE, else nothing is changed.
 *
 * Refused (nothing changes): no measure / same size; archived; less than nothing on hand; on hand not equal to the
 * batches; pieces per unit set; a draft or submitted count of the item with stock movements since it started (its
 * expected figure mixes units); a number that cannot be stored in the new unit; the preview no longer current.
 *
 * Used by admin/stock-ingredients.php; tested by scripts/tests/stock_change_unit.php.
 */

if (!function_exists('rh_suc_apply')) {

    if (!defined('RH_SUC_QTY_TOL')) {
        define('RH_SUC_QTY_TOL', 0.000001);
    }

    /** May this person change main units? Needs Change main unit AND Ingredients & Recipes. */
    function rh_suc_allowed(int $userId): bool
    {
        if ($userId <= 0 || !function_exists('hasPermission')) {
            return false;
        }
        return hasPermission($userId, 'stock_change_unit') && hasPermission($userId, 'stock_management');
    }

    function rh_suc_fmt($q): string
    {
        return rh_stock_qty_fmt((float)$q, 0);
    }

    /** Rounded to what the column can hold; null when it cannot hold it (too big). */
    function rh_suc_round(float $v, int $dp, float $max): ?float
    {
        $r = round($v, $dp);
        return abs($r) >= $max ? null : $r;
    }

    /**
     * Works out the change (no writes). $lock = inside a transaction: lock every row it will write (open count headers
     * first, then the item, then the rest - the order stock approval and sales use).
     * Returns ['ok', 'errors' => [plain sentences], 'item', 'plan' => [...], 'fingerprint'].
     */
    function rh_suc_compute(PDO $pdo, int $ingId, bool $lock): array
    {
        $fu = $lock ? ' FOR UPDATE' : '';
        $fail = static fn(string $msg): array => ['ok' => false, 'errors' => [$msg], 'item' => null, 'plan' => null, 'fingerprint' => ''];
        if (!rh_stock_unit_changes_ready($pdo)) {
            return $fail('Change main unit is not set up on this system yet (database update 090 has not run).');
        }
        if ($lock) {
            // Count headers before the item: stock count approval locks its header, then the items.
            $pdo->prepare("SELECT sc.id FROM stock_counts sc WHERE sc.status IN ('draft','submitted','rejected')
                AND sc.id IN (SELECT scl.count_id FROM stock_count_lines scl WHERE scl.ingredient_id = ?) ORDER BY sc.id FOR UPDATE")->execute([$ingId]);
        }
        $st = $pdo->prepare('SELECT * FROM stock_ingredients WHERE id = ?' . $fu);
        $st->execute([$ingId]);
        $it = $st->fetch(PDO::FETCH_ASSOC);
        if (!$it) {
            return $fail('This stock item was not found (it may have been deleted).');
        }
        $name = (string)$it['name'];
        if ((int)($it['is_archived'] ?? 0) === 1) {
            return $fail($name . ' is archived. Restore it before changing its main unit.');
        }
        $m = rh_stock_measure_get($it);
        if ($m === null) {
            return $fail($name . ' has no count measure yet (for example: bottle = 30 shots). Add one in Edit, under "Count in a smaller or bigger measure", then change the main unit.');
        }
        $n = (float)$m['n'];
        if ($n <= 1.0001) {
            return $fail('The two measures of ' . $name . ' are the same size, so there is nothing to convert.');
        }
        if ((float)($it['pieces_per_unit'] ?? 0) > 0) {
            return $fail($name . ' also has "pieces in one ' . $it['unit'] . '" set. Clear that in Edit first, then change the main unit.');
        }
        $oldSide = $m['side'];
        $newSide = $oldSide === 'big' ? 'small' : 'big';
        $f = rh_stock_unit_change_factor($n, $newSide);
        $oldUnit = (string)$it['unit'];
        $newUnit = $newSide === 'small' ? $m['small'] : $m['big'];

        // Flipping back: the unit it had before the last change (e.g. "btl"), when that change was the exact opposite.
        $hist = $pdo->prepare('SELECT id, old_unit, new_unit, new_side, measure_n FROM stock_unit_changes WHERE ingredient_id = ? ORDER BY id DESC LIMIT 1');
        $hist->execute([$ingId]);
        $last = $hist->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($last && (string)$last['new_side'] === $oldSide && (string)$last['new_unit'] === $oldUnit
            && abs((float)$last['measure_n'] - $n) < 0.00005 && trim((string)$last['old_unit']) !== '') {
            $newUnit = (string)$last['old_unit'];
        }
        if (mb_strtolower(trim($newUnit)) === mb_strtolower(trim($oldUnit))) {
            return $fail('The measure of ' . $name . ' has the same name as its unit (' . $oldUnit . '). Rename the measure in Edit first.');
        }
        if (mb_strlen($newUnit) > 20) {
            return $fail('"' . $newUnit . '" is too long for a unit (20 characters at most). Shorten the measure name in Edit first.');
        }

        $errs = [];
        $big = 9999999999.0; // DECIMAL(16,6)
        $q = (float)$it['current_quantity'];
        if ($q < -RH_SUC_QTY_TOL) {
            return $fail($name . ' shows less than nothing in stock (' . rh_suc_fmt($q) . ' ' . $oldUnit . '). Count or receive it first, then change the main unit.');
        }
        $cost = (float)$it['cost_per_unit'];
        $newCost = rh_suc_round($cost / $f, 4, 99999999.0);
        if ($newCost === null || ($cost > 0 && $newCost <= 0)) {
            $errs[] = 'The price of one ' . $newUnit . ' (' . number_format($cost / $f, 6) . ') cannot be stored.';
            $newCost = 0.0;
        }

        // Batches: all of them (a restore can put stock back into a used-up one).
        $bs = $pdo->prepare('SELECT id, batch_number, status, quantity_received, quantity_remaining, cost_per_unit FROM stock_batches WHERE ingredient_id = ? ORDER BY id' . $fu);
        $bs->execute([$ingId]);
        $batches = [];
        $activeSum = 0.0;
        $newActiveSum = 0.0;
        $valBatchBefore = 0.0;
        $valBatchAfter = 0.0;
        foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $rem = (float)$b['quantity_remaining'];
            $rec = (float)$b['quantity_received'];
            $bc = (float)$b['cost_per_unit'];
            $nRem = rh_suc_round($rem * $f, 6, $big);
            $nRec = rh_suc_round($rec * $f, 6, $big);
            $nCost = rh_suc_round($bc / $f, 4, 99999999.0);
            if ($nRem === null || $nRec === null || $nCost === null || ($bc > 0 && $nCost <= 0)) {
                $errs[] = 'Batch ' . $b['batch_number'] . ' cannot be stored in ' . $newUnit . ' (number too big or too small).';
                continue;
            }
            $row = ['id' => (int)$b['id'], 'batch_number' => (string)$b['batch_number'], 'status' => (string)$b['status'],
                'rec' => $rec, 'rem' => $rem, 'cost' => $bc, 'new_rec' => $nRec, 'new_rem' => $nRem, 'new_cost' => $nCost,
                'value' => round($rem * $bc, 4), 'new_value' => round($nRem * $nCost, 4)];
            if ($row['status'] === 'active') {
                $activeSum += $rem;
                $newActiveSum += $nRem;
                $valBatchBefore += $rem * $bc;
                $valBatchAfter += $nRem * $nCost;
                if (abs($row['new_value'] - $row['value']) > BALANCE_TOLERANCE) {
                    $errs[] = 'Batch ' . $row['batch_number'] . ': the price of one ' . $newUnit . ' (' . number_format($bc / $f, 6) . ') cannot be stored exactly enough to keep its value the same ('
                        . number_format($row['value'], 2) . ' now, ' . number_format($row['new_value'], 2) . ' after).';
                }
            }
            $batches[] = $row;
        }
        if (abs($q - $activeSum) > RH_SUC_QTY_TOL) {
            return $fail('The stock of ' . $name . ' (' . rh_suc_fmt($q) . ') does not match its batches (' . rh_suc_fmt($activeSum) . '). Count it first so they agree, then change the main unit.');
        }
        // On hand = its batches after the change too (8c), each rounded to 6 places.
        $newQ = round($newActiveSum, 6);
        $valBefore = round(max(0.0, $q) * $cost, 4);
        $valAfter = round(max(0.0, $newQ) * $newCost, 4);
        if (abs($valAfter - $valBefore) > BALANCE_TOLERANCE) {
            $errs[] = 'The price of one ' . $newUnit . ' (' . number_format($cost / $f, 6) . ') cannot be stored exactly enough to keep the stock value the same ('
                . number_format($valBefore, 2) . ' now, ' . number_format($valAfter, 2) . ' after). Nothing was changed.';
        }
        if (abs($valBatchAfter - $valBatchBefore) > BALANCE_TOLERANCE) {
            $errs[] = 'The batches of ' . $name . ' would change value (' . number_format($valBatchBefore, 2) . ' now, ' . number_format($valBatchAfter, 2) . ' after).';
        }

        // Item settings.
        $fields = [];
        foreach (['min_quantity' => 'Minimum', 'reorder_point' => 'Reorder point', 'par_level' => 'Par level'] as $col => $label) {
            $v = (float)($it[$col] ?? 0);
            $nv = rh_suc_round($v * $f, 6, $big);
            if ($nv === null) {
                $errs[] = $label . ' is too big to store in ' . $newUnit . '.';
                $nv = $v;
            }
            $fields[$col] = ['label' => $label, 'old' => $v, 'new' => $nv];
        }
        $packOld = ($it['pack_size'] ?? null) !== null && (float)$it['pack_size'] > 0 ? (float)$it['pack_size'] : null;
        $packNew = null;
        if ($packOld !== null) {
            $packNew = rh_suc_round($packOld * $f, 3, 999999999.0);
            if ($packNew === null || $packNew <= 0 || abs($packNew - $packOld * $f) > 0.0000005) {
                $errs[] = 'The purchase pack (' . rh_suc_fmt($packOld) . ' ' . $oldUnit . ' in one ' . ($it['pack_name'] ?: 'pack') . ') cannot be written exactly in ' . $newUnit . ' (3 decimals at most). Change the pack in Edit first.';
                $packNew = $packOld;
            }
        }
        $volNew = null;
        if ($oldSide === 'big' && array_key_exists('volume_ml', $it) && ($it['volume_ml'] === null || (float)$it['volume_ml'] <= 0)
            && function_exists('rh_drink_unit_ml')) {
            $uMl = rh_drink_unit_ml($oldUnit); // a litre-kept item: keep its size once the unit is no longer a volume
            if ($uMl !== null && $uMl > 0) {
                $volNew = $uMl;
            }
        }

        // Recipe lines.
        $rs = $pdo->prepare("SELECT sri.id, sri.quantity_per_portion, sr.menu_item_id, sr.menu_type, COALESCE(mi.item_name, CONCAT('menu item #', sr.menu_item_id)) AS dish
            FROM stock_recipe_ingredients sri INNER JOIN stock_recipes sr ON sr.id = sri.recipe_id LEFT JOIN menu_items mi ON mi.id = sr.menu_item_id
            WHERE sri.ingredient_id = ? ORDER BY sri.id" . ($lock ? ' FOR UPDATE' : ''));
        $rs->execute([$ingId]);
        $recipes = [];
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = (float)$r['quantity_per_portion'];
            $nv = rh_suc_round($v * $f, 6, $big);
            if ($nv === null || ($v > 0 && $nv <= 0)) {
                $errs[] = 'The recipe amount for ' . $r['dish'] . ' cannot be stored in ' . $newUnit . '.';
                continue;
            }
            $recipes[] = ['id' => (int)$r['id'], 'dish' => (string)$r['dish'], 'old' => $v, 'new' => $nv];
        }

        // Open purchase order lines (PO lines always hold stock-unit quantities and costs; order_in is display).
        $ps = $pdo->prepare("SELECT poi.id, poi.purchase_order_id, poi.unit, poi.ordered_qty, poi.received_qty, poi.over_received_qty, poi.unit_cost,
                poi.line_total, poi.pack_size_snapshot, po.reference, po.status
            FROM stock_purchase_order_items poi INNER JOIN stock_purchase_orders po ON po.id = poi.purchase_order_id
            WHERE poi.ingredient_id = ? AND po.status IN ('draft','sent','partial') ORDER BY poi.id" . $fu);
        $ps->execute([$ingId]);
        $poLines = [];
        foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $row = ['id' => (int)$p['id'], 'po_id' => (int)$p['purchase_order_id'], 'reference' => (string)$p['reference'], 'status' => (string)$p['status'],
                'unit' => $p['unit'], 'ordered' => (float)$p['ordered_qty'], 'received' => (float)$p['received_qty'],
                'over' => $p['over_received_qty'] !== null ? (float)$p['over_received_qty'] : null, 'cost' => (float)$p['unit_cost'],
                'line_total' => (float)$p['line_total'], 'pack' => $p['pack_size_snapshot'] !== null ? (float)$p['pack_size_snapshot'] : null];
            $row['new_unit'] = mb_strtolower(trim((string)$row['unit'])) === mb_strtolower(trim($oldUnit)) ? $newUnit : $row['unit'];
            $row['new_ordered'] = rh_suc_round($row['ordered'] * $f, 6, $big);
            $row['new_received'] = rh_suc_round($row['received'] * $f, 6, $big);
            $row['new_cost'] = rh_suc_round($row['cost'] / $f, 4, 99999999.0);
            $row['new_over'] = $row['over'] !== null ? rh_suc_round($row['over'] * $f, 3, 999999999.0) : null;
            $row['new_pack'] = $row['pack'] !== null ? rh_suc_round($row['pack'] * $f, 3, 999999999.0) : null;
            $bad = $row['new_ordered'] === null || $row['new_received'] === null || $row['new_cost'] === null || ($row['cost'] > 0 && $row['new_cost'] <= 0)
                || ($row['over'] !== null && ($row['new_over'] === null || abs($row['new_over'] - $row['over'] * $f) > 0.0000005))
                || ($row['pack'] !== null && ($row['new_pack'] === null || abs($row['new_pack'] - $row['pack'] * $f) > 0.0000005));
            if ($bad) {
                $errs[] = 'Purchase order ' . $row['reference'] . ' has a line for ' . $name . ' that cannot be written exactly in ' . $newUnit . '. Receive or change that order first.';
                continue;
            }
            $poLines[] = $row;
        }

        // Count lines of counts not approved yet.
        $cs = $pdo->prepare("SELECT scl.id, scl.count_id, scl.system_quantity, scl.actual_quantity, scl.variance, scl.cost_per_unit, scl.variance_cost,
                sc.reference, sc.status, sc.snapshot_at, sc.created_at AS count_created
            FROM stock_count_lines scl INNER JOIN stock_counts sc ON sc.id = scl.count_id
            WHERE scl.ingredient_id = ? AND sc.status IN ('draft','submitted','rejected') ORDER BY scl.id" . $fu);
        $cs->execute([$ingId]);
        $mv = $pdo->prepare('SELECT COUNT(*) FROM stock_adjustments WHERE ingredient_id = ? AND created_at > ?');
        $countLines = [];
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $ref = (string)($c['reference'] ?: ('#' . $c['count_id']));
            if ($c['status'] !== 'rejected') {
                // Its expected figure adds the movements since it started (in the unit they were recorded in).
                $start = (string)($c['snapshot_at'] ?: $c['count_created']);
                $mv->execute([$ingId, $start]);
                if ((int)$mv->fetchColumn() > 0) {
                    $errs[] = 'Count ' . $ref . ' (' . $c['status'] . ') includes ' . $name . ' and stock of it has moved since the count started. Approve or reject that count first, then change the main unit.';
                    continue;
                }
            }
            $row = ['id' => (int)$c['id'], 'count_id' => (int)$c['count_id'], 'reference' => $ref, 'status' => (string)$c['status'],
                'system' => (float)$c['system_quantity'], 'actual' => $c['actual_quantity'] !== null ? (float)$c['actual_quantity'] : null,
                'variance' => $c['variance'] !== null ? (float)$c['variance'] : null, 'cost' => (float)$c['cost_per_unit']];
            $row['new_system'] = rh_suc_round($row['system'] * $f, 6, $big);
            $row['new_actual'] = $row['actual'] !== null ? rh_suc_round($row['actual'] * $f, 6, $big) : null;
            $row['new_variance'] = $row['variance'] !== null ? rh_suc_round($row['variance'] * $f, 6, $big) : null;
            $row['new_cost'] = rh_suc_round($row['cost'] / $f, 4, 99999999.0);
            if ($row['new_system'] === null || ($row['actual'] !== null && $row['new_actual'] === null) || ($row['variance'] !== null && $row['new_variance'] === null) || $row['new_cost'] === null) {
                $errs[] = 'Count ' . $ref . ' has a line for ' . $name . ' that cannot be stored in ' . $newUnit . '.';
                continue;
            }
            $countLines[] = $row;
        }
        $openings = [];
        if (rh_stock_table_present($pdo, 'stock_opening_batches')) {
            $os = $pdo->prepare("SELECT sob.id, sob.count_id, sob.quantity, sc.reference FROM stock_opening_batches sob INNER JOIN stock_counts sc ON sc.id = sob.count_id
                WHERE sob.ingredient_id = ? AND sc.status IN ('draft','submitted','rejected') AND sob.applied_batch_id IS NULL ORDER BY sob.id" . $fu);
            $os->execute([$ingId]);
            foreach ($os->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $nv = rh_suc_round((float)$o['quantity'] * $f, 6, $big);
                if ($nv === null) {
                    $errs[] = 'An opening batch on count ' . $o['reference'] . ' cannot be stored in ' . $newUnit . '.';
                    continue;
                }
                $openings[] = ['id' => (int)$o['id'], 'reference' => (string)$o['reference'], 'old' => (float)$o['quantity'], 'new' => $nv];
            }
        }
        $barcodes = [];
        if (rh_stock_table_present($pdo, 'stock_ingredient_barcodes')) {
            $gs = $pdo->prepare('SELECT id, barcode, pack_size FROM stock_ingredient_barcodes WHERE ingredient_id = ? ORDER BY id' . $fu);
            $gs->execute([$ingId]);
            foreach ($gs->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $v = (float)$g['pack_size'];
                $nv = rh_suc_round($v * $f, 4, 999999.0);
                if ($nv === null || ($v > 0 && ($nv <= 0 || abs($nv - $v * $f) > 0.00000005))) {
                    $errs[] = 'Barcode ' . $g['barcode'] . ' (' . rh_suc_fmt($v) . ' ' . $oldUnit . ') cannot be written exactly in ' . $newUnit . '. Change it first.';
                    continue;
                }
                $barcodes[] = ['id' => (int)$g['id'], 'barcode' => (string)$g['barcode'], 'old' => $v, 'new' => $nv];
            }
        }

        // What makes the preview current: everything read above + the newest movement and change of the item.
        $lastAdj = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM stock_adjustments WHERE ingredient_id = ?');
        $lastAdj->execute([$ingId]);
        $lastAdjId = (int)$lastAdj->fetchColumn();
        $plan = [
            'ingredient_id' => $ingId, 'name' => $name, 'old_unit' => $oldUnit, 'new_unit' => $newUnit, 'n' => $n,
            'old_side' => $oldSide, 'new_side' => $newSide, 'factor' => $f, 'big' => $m['big'], 'small' => $m['small'],
            'qty' => $q, 'new_qty' => $newQ, 'cost' => $cost, 'new_cost' => $newCost,
            'value_before' => $valBefore, 'value_after' => $valAfter,
            'batch_value_before' => round($valBatchBefore, 4), 'batch_value_after' => round($valBatchAfter, 4),
            'fields' => $fields, 'pack_name' => (string)($it['pack_name'] ?? ''), 'pack_old' => $packOld, 'pack_new' => $packNew, 'volume_new' => $volNew,
            'batches' => $batches, 'recipes' => $recipes, 'po_lines' => $poLines, 'count_lines' => $countLines,
            'openings' => $openings, 'barcodes' => $barcodes, 'last_adjustment_id' => $lastAdjId, 'last_change_id' => $last ? (int)$last['id'] : 0,
        ];
        $fp = sha1(json_encode([
            $it['current_quantity'], $it['cost_per_unit'], $it['unit'], $it['measure_big_name'], $it['measure_small_name'], $it['measure_per_big'],
            $it['measure_stock_side'], $it['min_quantity'], $it['reorder_point'], $it['par_level'], $it['pack_size'], $it['pieces_per_unit'],
            $it['is_archived'], $it['volume_ml'] ?? null,
            array_map(static fn($b) => [$b['id'], $b['status'], $b['rec'], $b['rem'], $b['cost']], $batches),
            array_map(static fn($r) => [$r['id'], $r['old']], $recipes),
            array_map(static fn($p) => [$p['id'], $p['status'], $p['unit'], $p['ordered'], $p['received'], $p['over'], $p['cost'], $p['pack']], $poLines),
            array_map(static fn($c) => [$c['id'], $c['status'], $c['system'], $c['actual'], $c['variance'], $c['cost']], $countLines),
            array_map(static fn($o) => [$o['id'], $o['old']], $openings),
            array_map(static fn($g) => [$g['id'], $g['old']], $barcodes),
            $lastAdjId, $plan['last_change_id'], $errs,
        ]));
        return ['ok' => !$errs, 'errors' => $errs, 'item' => $it, 'plan' => $plan, 'fingerprint' => $fp];
    }

    /** Preview for the screen: what changes, place by place, old -> new (nothing is saved). */
    function rh_suc_preview(PDO $pdo, int $ingId, int $userId): array
    {
        if (!rh_suc_allowed($userId)) {
            return ['ok' => false, 'errors' => ['You do not have permission to change main units. Ask a manager or administrator.'], 'plan' => null, 'fingerprint' => '', 'lines' => [], 'history' => []];
        }
        $c = rh_suc_compute($pdo, $ingId, false);
        $c['lines'] = $c['plan'] ? rh_suc_lines($c['plan']) : [];
        $c['history'] = rh_suc_history($pdo, $ingId);
        unset($c['item']);
        return $c;
    }

    /** Plain-word lines "place: old -> new" for the preview and the log. */
    function rh_suc_lines(array $p): array
    {
        $ou = $p['old_unit'];
        $nu = $p['new_unit'];
        $out = [];
        $out[] = ['place' => 'On hand', 'old' => rh_suc_fmt($p['qty']) . ' ' . $ou, 'new' => rh_suc_fmt($p['new_qty']) . ' ' . $nu];
        $out[] = ['place' => 'Cost of one', 'old' => number_format($p['cost'], 4) . ' per ' . $ou, 'new' => number_format($p['new_cost'], 4) . ' per ' . $nu];
        foreach ($p['fields'] as $fd) {
            if (abs($fd['old']) > RH_SUC_QTY_TOL) {
                $out[] = ['place' => $fd['label'], 'old' => rh_suc_fmt($fd['old']) . ' ' . $ou, 'new' => rh_suc_fmt($fd['new']) . ' ' . $nu];
            }
        }
        if ($p['pack_old'] !== null) {
            $pn = $p['pack_name'] !== '' ? $p['pack_name'] : 'pack';
            $out[] = ['place' => 'Purchase pack (' . $pn . ')', 'old' => rh_suc_fmt($p['pack_old']) . ' ' . $ou, 'new' => rh_suc_fmt($p['pack_new']) . ' ' . $nu];
        }
        $out[] = ['place' => 'Count measure', 'old' => 'kept in ' . $ou . ' (1 ' . $p['big'] . ' = ' . rh_suc_fmt($p['n']) . ' ' . rh_stock_measure_plural($p['small'], $p['n']) . ')',
            'new' => 'kept in ' . $nu . ' (same measure, other side)'];
        foreach ($p['batches'] as $b) {
            if ($b['status'] !== 'active' && $b['rem'] <= RH_SUC_QTY_TOL) {
                continue; // used-up batches are converted too, but not listed one by one
            }
            $out[] = ['place' => 'Batch ' . $b['batch_number'], 'old' => rh_suc_fmt($b['rem']) . ' ' . $ou . ' at ' . number_format($b['cost'], 4) . ' = ' . number_format($b['value'], 2),
                'new' => rh_suc_fmt($b['new_rem']) . ' ' . $nu . ' at ' . number_format($b['new_cost'], 4) . ' = ' . number_format($b['new_value'], 2)];
        }
        $usedUp = count(array_filter($p['batches'], static fn($b) => !($b['status'] === 'active' || $b['rem'] > RH_SUC_QTY_TOL)));
        if ($usedUp > 0) {
            $out[] = ['place' => 'Used-up batches', 'old' => $usedUp . ' in ' . $ou, 'new' => 'converted to ' . $nu . ' (same value)'];
        }
        foreach ($p['recipes'] as $r) {
            $out[] = ['place' => 'Recipe: ' . $r['dish'], 'old' => rh_suc_fmt($r['old']) . ' ' . $ou . ' per portion', 'new' => rh_suc_fmt($r['new']) . ' ' . $nu . ' per portion'];
        }
        foreach ($p['po_lines'] as $l) {
            $out[] = ['place' => 'Purchase order ' . $l['reference'] . ' (' . $l['status'] . ')',
                'old' => rh_suc_fmt($l['ordered']) . ' ' . $l['unit'] . ' at ' . number_format($l['cost'], 4) . ($l['received'] > RH_SUC_QTY_TOL ? ', ' . rh_suc_fmt($l['received']) . ' received' : ''),
                'new' => rh_suc_fmt($l['new_ordered']) . ' ' . $l['new_unit'] . ' at ' . number_format($l['new_cost'], 4) . ($l['received'] > RH_SUC_QTY_TOL ? ', ' . rh_suc_fmt($l['new_received']) . ' received' : '') . ' (line total ' . number_format($l['line_total'], 2) . ' unchanged)'];
        }
        foreach ($p['count_lines'] as $c) {
            $out[] = ['place' => 'Count ' . $c['reference'] . ' (' . $c['status'] . ')',
                'old' => 'expected ' . rh_suc_fmt($c['system']) . ($c['actual'] !== null ? ', counted ' . rh_suc_fmt($c['actual']) : '') . ' ' . $ou,
                'new' => 'expected ' . rh_suc_fmt($c['new_system']) . ($c['new_actual'] !== null ? ', counted ' . rh_suc_fmt($c['new_actual']) : '') . ' ' . $nu];
        }
        foreach ($p['openings'] as $o) {
            $out[] = ['place' => 'Opening batch on count ' . $o['reference'], 'old' => rh_suc_fmt($o['old']) . ' ' . $ou, 'new' => rh_suc_fmt($o['new']) . ' ' . $nu];
        }
        foreach ($p['barcodes'] as $g) {
            $out[] = ['place' => 'Barcode ' . $g['barcode'], 'old' => rh_suc_fmt($g['old']) . ' ' . $ou, 'new' => rh_suc_fmt($g['new']) . ' ' . $nu];
        }
        return $out;
    }

    /** Earlier changes of the item, newest first (who / when / old -> new). */
    function rh_suc_history(PDO $pdo, int $ingId, int $limit = 20): array
    {
        if (!rh_stock_unit_changes_ready($pdo)) {
            return [];
        }
        $st = $pdo->prepare('SELECT c.id, c.old_unit, c.new_unit, c.measure_n, c.value_before, c.value_after, c.changed_at, c.changed_by,
                COALESCE(NULLIF(u.full_name, \'\'), u.username) AS who
            FROM stock_unit_changes c LEFT JOIN admin_users u ON u.id = c.changed_by
            WHERE c.ingredient_id = ? ORDER BY c.id DESC LIMIT ' . max(1, min(100, $limit)));
        try {
            $st->execute([$ingId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** One activity-log text: who sees exactly what changed, old -> new. */
    function rh_suc_log_details(array $p): string
    {
        $parts = [];
        foreach (rh_suc_lines($p) as $l) {
            $parts[] = $l['place'] . ': ' . $l['old'] . ' -> ' . $l['new'];
        }
        $head = $p['name'] . ' (#' . $p['ingredient_id'] . '): main unit ' . $p['old_unit'] . ' -> ' . $p['new_unit']
            . ' (1 ' . $p['big'] . ' = ' . rh_suc_fmt($p['n']) . ' ' . $p['small'] . '). Stock value ' . number_format($p['value_before'], 2) . ' -> ' . number_format($p['value_after'], 2) . '. ';
        return mb_substr($head . implode('; ', $parts), 0, 4000);
    }

    /**
     * Confirm. Re-checks permission and that the preview ($fingerprint) is still current, under the row locks; then
     * rescales everything, checks the stock value again and records the change. All or nothing.
     * Returns ['ok', 'errors', 'plan', 'change_id'].
     */
    function rh_suc_apply(PDO $pdo, int $ingId, string $fingerprint, int $userId): array
    {
        $no = static fn(array $errs): array => ['ok' => false, 'errors' => $errs, 'plan' => null, 'change_id' => 0];
        if (!rh_suc_allowed($userId)) {
            return $no(['You do not have permission to change main units. Ask a manager or administrator.']);
        }
        if ($fingerprint === '') {
            return $no(['Open the preview first, then confirm.']);
        }
        if ($pdo->inTransaction()) {
            return $no(['Nothing was changed: please try again.']);
        }
        $pdo->beginTransaction();
        try {
            $c = rh_suc_compute($pdo, $ingId, true);
            if (!$c['ok']) {
                $pdo->rollBack();
                return $no($c['errors']);
            }
            if (!hash_equals($c['fingerprint'], $fingerprint)) {
                $pdo->rollBack();
                return $no(['Something about this item changed since the preview (a sale, delivery, count or edit). Nothing was changed: open the preview again and check it.']);
            }
            $p = $c['plan'];
            $now = (string)$pdo->query('SELECT NOW()')->fetchColumn();
            $pdo->prepare('INSERT INTO stock_unit_changes (ingredient_id, old_unit, new_unit, measure_n, new_side, last_adjustment_id, value_before, value_after, details, changed_by, changed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$ingId, $p['old_unit'], $p['new_unit'], sprintf('%.4F', $p['n']), $p['new_side'],
                $p['last_adjustment_id'], sprintf('%.4F', $p['value_before']), sprintf('%.4F', $p['value_after']),
                json_encode(['lines' => rh_suc_lines($p), 'factor' => $p['factor']], JSON_UNESCAPED_UNICODE), $userId > 0 ? $userId : null, $now]);
            $changeId = (int)$pdo->lastInsertId();

            $set = 'unit = ?, measure_stock_side = ?, current_quantity = ?, cost_per_unit = ?, min_quantity = ?, reorder_point = ?, par_level = ?, pack_size = ?';
            $args = [$p['new_unit'], $p['new_side'], sprintf('%.6F', $p['new_qty']), sprintf('%.4F', $p['new_cost']),
                sprintf('%.6F', $p['fields']['min_quantity']['new']), sprintf('%.6F', $p['fields']['reorder_point']['new']), sprintf('%.6F', $p['fields']['par_level']['new']),
                $p['pack_new'] !== null ? sprintf('%.3F', $p['pack_new']) : $c['item']['pack_size']];
            if ($p['volume_new'] !== null) {
                $set .= ', volume_ml = ?';
                $args[] = $p['volume_new'];
            }
            $args[] = $ingId;
            $pdo->prepare('UPDATE stock_ingredients SET ' . $set . ', updated_at = NOW() WHERE id = ?')->execute($args);

            $ub = $pdo->prepare('UPDATE stock_batches SET quantity_received = ?, quantity_remaining = ?, cost_per_unit = ?, updated_at = NOW() WHERE id = ? AND ingredient_id = ?');
            foreach ($p['batches'] as $b) {
                $ub->execute([sprintf('%.6F', $b['new_rec']), sprintf('%.6F', $b['new_rem']), sprintf('%.4F', $b['new_cost']), $b['id'], $ingId]);
            }
            $ur = $pdo->prepare('UPDATE stock_recipe_ingredients SET quantity_per_portion = ? WHERE id = ? AND ingredient_id = ?');
            foreach ($p['recipes'] as $r) {
                $ur->execute([sprintf('%.6F', $r['new']), $r['id'], $ingId]);
            }
            $up = $pdo->prepare('UPDATE stock_purchase_order_items SET unit = ?, ordered_qty = ?, received_qty = ?, over_received_qty = ?, unit_cost = ?, pack_size_snapshot = ? WHERE id = ? AND ingredient_id = ?');
            foreach ($p['po_lines'] as $l) {
                $up->execute([$l['new_unit'], sprintf('%.6F', $l['new_ordered']), sprintf('%.6F', $l['new_received']),
                    $l['new_over'] !== null ? sprintf('%.3F', $l['new_over']) : null, sprintf('%.4F', $l['new_cost']),
                    $l['new_pack'] !== null ? sprintf('%.3F', $l['new_pack']) : null, $l['id'], $ingId]);
            }
            $uc = $pdo->prepare('UPDATE stock_count_lines SET system_quantity = ?, actual_quantity = ?, variance = ?, cost_per_unit = ? WHERE id = ? AND ingredient_id = ?');
            foreach ($p['count_lines'] as $cl) {
                $uc->execute([sprintf('%.6F', $cl['new_system']), $cl['new_actual'] !== null ? sprintf('%.6F', $cl['new_actual']) : null,
                    $cl['new_variance'] !== null ? sprintf('%.6F', $cl['new_variance']) : null, sprintf('%.4F', $cl['new_cost']), $cl['id'], $ingId]);
            }
            $uo = $pdo->prepare('UPDATE stock_opening_batches SET quantity = ? WHERE id = ? AND ingredient_id = ?');
            foreach ($p['openings'] as $o) {
                $uo->execute([sprintf('%.6F', $o['new']), $o['id'], $ingId]);
            }
            $ug = $pdo->prepare('UPDATE stock_ingredient_barcodes SET pack_size = ? WHERE id = ? AND ingredient_id = ?');
            foreach ($p['barcodes'] as $g) {
                $ug->execute([sprintf('%.4F', $g['new']), $g['id'], $ingId]);
            }

            // The value check again, on what is stored now.
            $chk = $pdo->prepare("SELECT i.current_quantity, i.cost_per_unit,
                    COALESCE((SELECT SUM(b.quantity_remaining) FROM stock_batches b WHERE b.ingredient_id = i.id AND b.status = 'active'), 0) AS bq,
                    COALESCE((SELECT SUM(b.quantity_remaining * b.cost_per_unit) FROM stock_batches b WHERE b.ingredient_id = i.id AND b.status = 'active'), 0) AS bv
                FROM stock_ingredients i WHERE i.id = ?");
            $chk->execute([$ingId]);
            $now2 = $chk->fetch(PDO::FETCH_ASSOC);
            $valNow = round(max(0.0, (float)$now2['current_quantity']) * (float)$now2['cost_per_unit'], 4);
            if (abs($valNow - $p['value_before']) > BALANCE_TOLERANCE || abs((float)$now2['bv'] - $p['batch_value_before']) > BALANCE_TOLERANCE
                || abs((float)$now2['current_quantity'] - (float)$now2['bq']) > RH_SUC_QTY_TOL) {
                throw new RuntimeException('value check failed after the change');
            }

            if (function_exists('logActivity')) {
                logActivity($userId, 'stock_main_unit_changed', rh_suc_log_details($p)); // same connection: commits with the change
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('stock unit change: ' . $e->getMessage());
            return $no(['Nothing was changed: the change could not be saved safely. Please try again.']);
        }
        if (function_exists('rh_clear_stock_dashboard_cache')) {
            try {
                rh_clear_stock_dashboard_cache();
            } catch (Throwable $e) {
            }
        }
        return ['ok' => true, 'errors' => [], 'plan' => $p, 'change_id' => $changeId];
    }
}
