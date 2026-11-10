<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Stock FIFO and expired-but-not-yet-swept batches.
 * Usage (project root): php scripts/tests/stock_expiry_fifo.php --confirm-db
 *
 * A batch past its expiry date stays 'active' until runStockExpiryCheck() sweeps it (dashboard / batches
 * page load). Sales made before the sweep must come out of that batch, so the sweep writes off only what is
 * really left (no double removal, no reconciliation batch minted for stock that is on the shelf).
 *
 * Everything runs in ONE transaction that is rolled back; throwaway ingredients are named "ZZ TEST expiry
 * fifo ...". runStockExpiryCheck() itself is NEVER called (it would sweep the hotel's real batches); the
 * test asserts what it would write off instead. Needs --confirm-db so it never runs by accident.
 *
 *  a. Expired batch 10 + fresh batch 5, sell 4: coverage mints nothing, the expired batch drops to 6,
 *     ingredient -4, the sweep would write off 6 (not 10). A sale spanning both batches empties the expired one.
 *  b. Selling more than all batches: coverage mints only the real gap; without coverage the shortfall goes
 *     negative unallocated (unchanged behaviour).
 *  c. Void restore before the sweep goes back into the still-active batch; after the sweep the expired batch
 *     is NOT reactivated and NO batch is minted: the restored stock keeps its batch's expiry, so it is written
 *     off as expired at the cost it left at (ASSUMPTION 2026-11-10); the restore is valued at the FIFO cost
 *     deducted; a second restore / second sweep writes nothing twice; a recalled or wasted batch never takes stock
 *     back either (written off as recall / wastage at its cost); one call locks several lines' ingredients.
 *  d. Lock order (static): ingredient row before its batches on every stock write path; sweep per ingredient.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (!in_array('--confirm-db', $argv ?? [], true)) {
    fwrite(STDERR, "This test writes (and rolls back) rows in the configured database. Re-run with --confirm-db.\n");
    exit(2);
}
require_once $root . '/config/database.php';

$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): bool { global $pass, $fail; if ($c) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } return $c; }
function near($a, float $b): bool { return abs((float)$a - $b) <= 0.0005; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

if (!ensureStockTablesExist()) { check(false, 'Stock tables exist'); echo "\n$pass passed, $fail failed\n"; exit(1); }
$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1") ?: null;
$today = date('Y-m-d');
$past = date('Y-m-d', strtotime('-2 days'));   // clearly expired whatever the MySQL/PHP timezone gap
$future = date('Y-m-d', strtotime('+30 days'));
$run = strtoupper(bin2hex(random_bytes(3)));
$GLOBALS['zzBatchSeq'] = 0; // batch_number is unique: one suffix per test batch
$ingIds = [];
$srcIds = [];

/* A source id no real row uses (any source type), so restore can never read a real trail. */
$pickSrc = static function () use ($pdo, &$srcIds): int {
    for ($i = 0; $i < 50; $i++) {
        $id = 2000000000 + random_int(1, 140000000);
        if ((int)col($pdo, "SELECT COUNT(*) FROM stock_adjustments WHERE source_id = ?", [$id]) === 0 && !in_array($id, $srcIds, true)) {
            $srcIds[] = $id;
            return $id;
        }
    }
    throw new RuntimeException('Could not find an unused source id.');
};

try {
    $pdo->beginTransaction();

    $mkIng = static function (string $label, float $qty) use ($pdo, $run, &$ingIds): int {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'ZZ TEST', 'each', ?, 100)")
            ->execute(["ZZ TEST expiry fifo $run $label", $qty]);
        $id = (int)$pdo->lastInsertId();
        $ingIds[] = $id;
        return $id;
    };
    $mkBatch = static function (int $ing, float $qty, ?string $expiry, float $cost) use ($pdo, $run): int {
        $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, expiry_date, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 'active')")
            ->execute([$ing, "ZZT-$run-" . (++$GLOBALS["zzBatchSeq"]), $qty, $qty, $cost, date('Y-m-d', strtotime('-20 days')), $expiry]);
        return (int)$pdo->lastInsertId();
    };
    $batch = static fn(int $id): array => (function () use ($pdo, $id) { $s = $pdo->prepare("SELECT * FROM stock_batches WHERE id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: []; })();
    $ingQty = static fn(int $id): float => (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$id]);
    $batchCount = static fn(int $id): int => (int)col($pdo, "SELECT COUNT(*) FROM stock_batches WHERE ingredient_id = ?", [$id]);
    $activeSum = static fn(int $id): float => (float)col($pdo, "SELECT COALESCE(SUM(quantity_remaining), 0) FROM stock_batches WHERE ingredient_id = ? AND status = 'active'", [$id]);
    // Exactly what runStockExpiryCheck() would write off for this ingredient today.
    $sweepWouldWriteOff = static fn(int $id): float => (float)col($pdo, "SELECT COALESCE(SUM(quantity_remaining), 0) FROM stock_batches WHERE ingredient_id = ? AND status = 'active' AND expiry_date IS NOT NULL AND expiry_date < ?", [$id, $today]);

    /* ---- a. Expired-but-unswept batch is used first ---- */
    $a = $mkIng('A', 15);
    $aOld = $mkBatch($a, 10, $past, 100);
    $aNew = $mkBatch($a, 5, $future, 120);
    $gap = ensureStockBatchCoverageForDeduction($a, 4, 100, $adminId, 'ZZ TEST cover');
    check(near($gap, 0), 'a: coverage counts the expired-but-unswept batch (no gap)', "gap=$gap");
    check($batchCount($a) === 2, 'a: no reconciliation (TMP) batch was created');
    $srcA = $pickSrc();
    $adjA = (int)(deductStockBatchFIFO($a, 4, 'room_service', $srcA, $adminId) ?? 0);
    check($adjA > 0, 'a: sale of 4 deducted');
    $bo = $batch($aOld); $bn = $batch($aNew);
    check(near($bo['quantity_remaining'] ?? -1, 6) && ($bo['status'] ?? '') === 'active', 'a: expired batch 10 -> 6, still active', json_encode($bo));
    check(near($bn['quantity_remaining'] ?? -1, 5), 'a: fresh batch untouched (5)');
    check(near($ingQty($a), 11), 'a: ingredient 15 -> 11 (-4)', (string)$ingQty($a));
    check(near(col($pdo, "SELECT COALESCE(SUM(quantity_deducted),0) FROM stock_batch_deductions WHERE adjustment_id = ? AND batch_id = ?", [$adjA, $aOld]), 4), 'a: batch trail records 4 from the expired batch');
    check(near(col($pdo, "SELECT cost_at_time FROM stock_adjustments WHERE id = ?", [$adjA]), 100), 'a: cost uses the expired batch cost (100)');
    check(near($sweepWouldWriteOff($a), 6), 'a: the sweep would write off 6 (not 10)', (string)$sweepWouldWriteOff($a));
    check(near($ingQty($a) - $sweepWouldWriteOff($a), 5) && near($activeSum($a) - $sweepWouldWriteOff($a), 5), 'a: after the sweep ingredient and batches both hold 5 (no negative)');

    // A sale spanning both: expired batch empties first, the rest from the fresh one.
    $a2 = $mkIng('A2', 8);
    $a2Old = $mkBatch($a2, 3, $past, 100);
    $a2New = $mkBatch($a2, 5, $future, 120);
    check(near(ensureStockBatchCoverageForDeduction($a2, 4, 100, $adminId, 'ZZ TEST cover'), 0), 'a2: no gap for a sale spanning both batches');
    deductStockBatchFIFO($a2, 4, 'room_service', $pickSrc(), $adminId);
    $bo = $batch($a2Old); $bn = $batch($a2New);
    check(near($bo['quantity_remaining'] ?? -1, 0) && ($bo['status'] ?? '') === 'depleted', 'a2: expired batch 3 used up (depleted)');
    check(near($bn['quantity_remaining'] ?? -1, 4), 'a2: fresh batch 5 -> 4');
    check(near($sweepWouldWriteOff($a2), 0) && near($ingQty($a2), 4), 'a2: sweep would write off nothing; ingredient 4');

    /* ---- b. More than all batches: cover / deficit behaviour ---- */
    $b = $mkIng('B', 5);
    $mkBatch($b, 2, $past, 100);
    $mkBatch($b, 3, $future, 120);
    $gapB = ensureStockBatchCoverageForDeduction($b, 8, 100, $adminId, 'ZZ TEST cover');
    check(near($gapB, 3), 'b: coverage mints only the real gap (3)', "gap=$gapB");
    check((int)col($pdo, "SELECT COUNT(*) FROM stock_batches WHERE ingredient_id = ? AND expiry_date IS NULL AND status = 'active' AND quantity_remaining BETWEEN 2.9999 AND 3.0001", [$b]) === 1, 'b: one cover batch of 3, no expiry');
    check(near($ingQty($b), 5), 'b: coverage does not change the ingredient total');
    deductStockBatchFIFO($b, 8, 'room_service', $pickSrc(), $adminId);
    check(near($activeSum($b), 0) && near($ingQty($b), -3), 'b: all batches used, ingredient 5 -> -3', $activeSum($b) . ' / ' . $ingQty($b));

    $d = $mkIng('D', 1);
    $dOld = $mkBatch($d, 1, $past, 100);
    $adjD = (int)(deductStockBatchFIFO($d, 3, 'room_service', $pickSrc(), $adminId) ?? 0);
    check($adjD > 0 && near($ingQty($d), -2), 'b: no coverage -> ingredient goes negative (1 -> -2)');
    check(near(col($pdo, "SELECT COALESCE(SUM(quantity_deducted),0) FROM stock_batch_deductions WHERE adjustment_id = ?", [$adjD]), 1)
        && near(col($pdo, "SELECT quantity_change FROM stock_adjustments WHERE id = ?", [$adjD]), -3), 'b: 1 from the expired batch, 2 unallocated, adjustment -3');

    /* ---- c. Void restore ---- */
    // c1: before the sweep the stock goes back into the same (still active, expired) batch: those units are the expired ones.
    check(restoreStockForMenuItem(0, 'food', 1, 'ZZ TEST void', $adminId, $srcA, 'room_service'), 'c1: restore before the sweep succeeds');
    $bo = $batch($aOld);
    check(near($bo['quantity_remaining'] ?? -1, 10) && ($bo['status'] ?? '') === 'active', 'c1: back into the expired batch (10), sweep will write it off');
    check(near($ingQty($a), 15) && near($sweepWouldWriteOff($a), 10), 'c1: ingredient back to 15; sweep would write off 10');
    restoreStockForMenuItem(0, 'food', 1, 'ZZ TEST void', $adminId, $srcA, 'room_service');
    check(near($ingQty($a), 15) && near($batch($aOld)['quantity_remaining'] ?? -1, 10), 'c1: a second restore puts nothing back twice');

    // c2: after the sweep the expired batch is not reactivated, and the restored stock is NOT put back on
    // the shelf in a no-expiry batch: it keeps its batch's (passed) expiry, so it is written off as expired
    // at the cost it left at (ASSUMPTION 2026-11-10).
    $g = $mkIng('G', 15);
    $pdo->prepare("UPDATE stock_ingredients SET cost_per_unit = 150 WHERE id = ?")->execute([$g]); // average differs from the FIFO cost
    $gOld = $mkBatch($g, 10, $past, 100);
    $gNew = $mkBatch($g, 5, $future, 120);
    $srcG = $pickSrc();
    $adjG = (int)(deductStockBatchFIFO($g, 4, 'room_service', $srcG, $adminId) ?? 0);
    $left = $sweepWouldWriteOff($g);
    check(near($left, 6), 'c2: 6 left on the expired batch');
    // The sweep's own per-ingredient writer (runStockExpiryCheck() is never called: it would sweep real batches).
    check(rh_stock_expiry_sweep_ingredient($pdo, $g, $today) === 1, 'c2: sweep moves the expired batch');
    check(near($ingQty($g), 5), 'c2: after the sweep ingredient 5 (15 - 4 sold - 6 written off)');
    check(rh_stock_expiry_sweep_ingredient($pdo, $g, $today) === 0 && near($ingQty($g), 5), 'c2: a second sweep (retry) writes nothing off twice');
    $wBefore = (int)col($pdo, "SELECT COUNT(*) FROM stock_wastage WHERE ingredient_id = ?", [$g]);
    check(restoreStockForMenuItem(0, 'food', 1, 'ZZ TEST void', $adminId, $srcG, 'room_service'), 'c2: restore after the sweep succeeds');
    $bo = $batch($gOld);
    check(($bo['status'] ?? '') === 'expired' && near($bo['quantity_remaining'] ?? -1, 0), 'c2: expired batch stays expired at 0 (not revived)', json_encode($bo));
    check($batchCount($g) === 2, 'c2: no new batch minted (no no-expiry batch)', (string)$batchCount($g));
    check(near($ingQty($g), 5) && near($activeSum($g), 5), 'c2: ingredient stays 5 = active batches 5 (restored 4 written off)', $ingQty($g) . ' / ' . $activeSum($g));
    $wRow = $pdo->prepare("SELECT * FROM stock_wastage WHERE ingredient_id = ? AND batch_id = ? AND reason LIKE '%restored from voided charge%'");
    $wRow->execute([$g, $gOld]);
    $wRows = $wRow->fetchAll(PDO::FETCH_ASSOC);
    check(count($wRows) === 1 && near($wRows[0]['quantity'], 4) && near($wRows[0]['cost_per_unit'], 100) && near($wRows[0]['wastage_cost'], 400) && ($wRows[0]['recorded_date'] ?? '') === $today,
        'c2: one expiry wastage row: 4 at the batch cost 100 = 400, dated today', json_encode($wRows));
    check(near(col($pdo, "SELECT COALESCE(SUM(quantity_change),0) FROM stock_adjustments WHERE source_type = 'expiry' AND source_id = ?", [$gOld]), -10), 'c2: expiry adjustments on the batch total -10 (6 swept + 4 restored)');
    $restCost = (float)col($pdo, "SELECT cost_at_time FROM stock_adjustments WHERE source_type = 'void_restore' AND source_id = ? AND ingredient_id = ?", [$srcG, $g]);
    check(near($restCost, 100) && near(col($pdo, "SELECT cost_at_time FROM stock_adjustments WHERE id = ?", [$adjG]), 100), 'c2: restore valued at the FIFO cost it left at (100), not the average (150)', (string)$restCost);
    restoreStockForMenuItem(0, 'food', 1, 'ZZ TEST void', $adminId, $srcG, 'room_service');
    check(near($ingQty($g), 5) && $batchCount($g) === 2 && (int)col($pdo, "SELECT COUNT(*) FROM stock_wastage WHERE ingredient_id = ?", [$g]) === $wBefore + 1, 'c2: a second restore writes nothing more');

    // c3: deduction spanned two batches; the used-up one is past its date: deducted cost - restored cost = 0,
    // and the 1 restored to the used-up expired batch is written off (not revived), the 2 go back on the shelf.
    $h = $mkIng('H', 6);
    $pdo->prepare("UPDATE stock_ingredients SET cost_per_unit = 150 WHERE id = ?")->execute([$h]);
    $hOld = $mkBatch($h, 1, $past, 90);
    $hNew = $mkBatch($h, 5, $future, 130);
    $srcH = $pickSrc();
    $adjH = (int)(deductStockBatchFIFO($h, 3, 'room_service', $srcH, $adminId) ?? 0);
    $dedCost = 3 * (float)col($pdo, "SELECT cost_at_time FROM stock_adjustments WHERE id = ?", [$adjH]);
    check(near($dedCost, 350), 'c3: FIFO deduction 1 x 90 + 2 x 130 = 350', (string)$dedCost);
    check(rh_stock_expiry_sweep_ingredient($pdo, $h, $today) === 0, "c3: sweep skips the used-up (depleted) expired batch");
    restoreStockForMenuItem(0, 'food', 3, 'ZZ TEST void', $adminId, $srcH, 'room_service');
    $restVal = (float)col($pdo, "SELECT COALESCE(SUM(quantity_change * cost_at_time),0) FROM stock_adjustments WHERE source_type = 'void_restore' AND source_id = ? AND ingredient_id = ?", [$srcH, $h]);
    check(abs($dedCost - $restVal) <= 0.01, 'c3: deducted cost - restored cost = 0', "$dedCost vs $restVal");
    check(near($batch($hNew)['quantity_remaining'] ?? -1, 5) && $batchCount($h) === 2, 'c3: 2 back into the fresh batch (5), no batch minted');
    check(near(col($pdo, "SELECT COALESCE(SUM(wastage_cost),0) FROM stock_wastage WHERE ingredient_id = ? AND batch_id = ?", [$h, $hOld]), 90) && near($ingQty($h), 5),
        'c3: the 1 from the expired batch is wastage at 90; ingredient 5 = shelf', (string)$ingQty($h));

    // c4: a recalled batch (still in date) never takes stock back and no batch is minted: the restored 2
    // are written off at the batch's cost as a recall.
    $k = $mkIng('K', 5);
    $kB = $mkBatch($k, 5, $future, 110);
    $srcK = $pickSrc();
    deductStockBatchFIFO($k, 2, 'room_service', $srcK, $adminId);
    $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - 3 WHERE id = ?")->execute([$k]);
    $pdo->prepare("UPDATE stock_batches SET quantity_remaining = 0, status = 'recalled' WHERE id = ?")->execute([$kB]);
    check(restoreStockForMenuItem(0, 'food', 1, 'ZZ TEST void', $adminId, $srcK, 'room_service'), 'c4: restore into a recalled batch succeeds');
    check($batchCount($k) === 1 && ($batch($kB)['status'] ?? '') === 'recalled' && near($batch($kB)['quantity_remaining'] ?? -1, 0), 'c4: no new batch; recalled batch stays recalled at 0');
    check(near($ingQty($k), 0) && near($activeSum($k), 0), 'c4: ingredient stays 0 (restored 2 written off), nothing back on the shelf', (string)$ingQty($k));
    $wk = $pdo->prepare("SELECT quantity, cost_per_unit, wastage_cost, reason FROM stock_wastage WHERE ingredient_id = ? AND batch_id = ?");
    $wk->execute([$k, $kB]);
    $wkRows = $wk->fetchAll(PDO::FETCH_ASSOC);
    check(count($wkRows) === 1 && near($wkRows[0]['quantity'], 2) && near($wkRows[0]['wastage_cost'], 220) && strncmp((string)$wkRows[0]['reason'], 'Recall:', 7) === 0,
        'c4: one "Recall:" wastage row, 2 at the batch cost 110 = 220', json_encode($wkRows));
    check(near(col($pdo, "SELECT COALESCE(SUM(quantity_change),0) FROM stock_adjustments WHERE source_type = 'recall' AND source_id = ? AND ingredient_id = ?", [$kB, $k]), -2), 'c4: recall adjustment -2 against the batch');
    restoreStockForMenuItem(0, 'food', 1, 'ZZ TEST void', $adminId, $srcK, 'room_service');
    check(near($ingQty($k), 0) && (int)col($pdo, "SELECT COUNT(*) FROM stock_wastage WHERE ingredient_id = ?", [$k]) === 1, 'c4: a second restore writes nothing more');

    // c5: a batch written off as wasted: same, as wastage (adjustment keyed on the wastage row).
    $m = $mkIng('M', 4);
    $mB = $mkBatch($m, 4, $future, 80);
    $srcM = $pickSrc();
    deductStockBatchFIFO($m, 1, 'room_service', $srcM, $adminId);
    $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - 3 WHERE id = ?")->execute([$m]);
    $pdo->prepare("UPDATE stock_batches SET quantity_remaining = 0, status = 'wasted' WHERE id = ?")->execute([$mB]);
    restoreStockForMenuItem(0, 'food', 1, 'ZZ TEST void', $adminId, $srcM, 'room_service');
    $wmId = (int)col($pdo, "SELECT id FROM stock_wastage WHERE ingredient_id = ? AND batch_id = ? AND ABS(wastage_cost - 80) < 0.0001", [$m, $mB]);
    check($batchCount($m) === 1 && near($ingQty($m), 0) && $wmId > 0
        && near(col($pdo, "SELECT COALESCE(SUM(quantity_change),0) FROM stock_adjustments WHERE source_type = 'wastage' AND source_id = ? AND ingredient_id = ?", [$wmId, $m]), -1),
        'c5: wasted batch: no new batch, 1 written off at 80, wastage adjustment keyed on the wastage row');

    // c6: lock order across lines: one call locks every ingredient of several lines (recipes + recorded
    // deductions), ascending id, inside the transaction.
    $locked = rh_stock_lock_ingredients_for_lines($pdo, [['menu_item_id' => 0, 'source_type' => 'room_service', 'source_id' => $srcK], ['menu_item_id' => 0, 'source_type' => 'room_service', 'source_id' => $srcG], ['menu_item_id' => 0, 'source_type' => 'room_service', 'source_id' => $srcA]]);
    $want = [$a, $g, $k];
    sort($want);
    check($locked === $want, 'c6: lines of three ingredients locked together, ascending id', json_encode($locked) . ' vs ' . json_encode($want));
} catch (Throwable $e) {
    check(false, 'No exception', $e->getMessage());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

/* ---- d. Lock order (static): every stock write path locks the ingredient row before its batches ---- */
$firstPos = static function (string $src, string $table): int {
    return preg_match('/\b' . $table . '\b[^;]{0,400}?FOR UPDATE/s', $src, $m, PREG_OFFSET_CAPTURE) ? (int)$m[0][1] : -1;
};
$fnSrc = static function (string $fn): string {
    $r = new ReflectionFunction($fn);
    $lines = file($r->getFileName());
    return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
};
foreach (['ensureStockBatchCoverageForDeduction', 'deductStockBatchFIFO', 'restoreStockForMenuItem', 'rh_stock_expiry_sweep_ingredient'] as $fn) {
    $src = $fnSrc($fn);
    $i = $firstPos($src, 'stock_ingredients'); $bt = $firstPos($src, 'stock_batches');
    check($i >= 0 && $bt >= 0 && $i < $bt, "d: $fn locks stock_ingredients before stock_batches", "ingredient@$i batch@$bt");
}
$sweepSrc = $fnSrc('runStockExpiryCheck');
check($firstPos($sweepSrc, 'stock_batches') === -1 && strpos($sweepSrc, 'rh_stock_expiry_sweep_ingredient(') !== false && strpos($sweepSrc, 'rh_is_lock_conflict(') !== false,
    'd: the sweep locks nothing itself, writes per ingredient, retries once on a lock conflict');
$dfm = $fnSrc('deductStockForMenuItem');
check(strpos($dfm, 'ksort($needByIngredient)') !== false, 'd: a recipe is deducted in ascending ingredient id order');
// Admin single-batch writers: the ingredient lock comes before the batch lock in the same action.
$adminChecks = [
    ['admin/stock-batches.php', "action === 'mark_wasted'"], ['admin/stock-batches.php', "action === 'recall'"],
    ['admin/stock-ingredients.php', "action === 'discard_batch'"],
];
foreach ($adminChecks as [$file, $anchor]) {
    $src = (string)file_get_contents($root . '/' . $file);
    $p = strpos($src, $anchor);
    $blk = $p === false ? '' : substr($src, $p, 2500);
    $i = $firstPos($blk, 'stock_ingredients'); $bt = $firstPos($blk, 'stock_batches');
    check($i >= 0 && $bt >= 0 && $i < $bt, "d: $file ($anchor) locks the ingredient before the batch", "ingredient@$i batch@$bt");
}
$cnt = (string)file_get_contents($root . '/admin/stock-count.php');
check(strpos($cnt, 'sort($countIngIds);') !== false && strpos($cnt, 'sort($countIngIds);') < strpos($cnt, '$liveSel->execute([$ingredientId]);'), 'd: count approval locks all counted ingredients by ascending id first');
$rps = (string)file_get_contents($root . '/admin/includes/restaurant-payment-sync.php');
check(strpos($rps, 'ksort($reqs);') !== false, 'd: item wastage walks ingredients in ascending id order');
// Multi-line paths lock every ingredient of all their lines first (one transaction, ascending id).
foreach (['api/kds-action.php' => 2, 'admin/includes/restaurant-payment-sync.php' => 2, 'admin/includes/restaurant-order-serve.php' => 1, 'admin/pos.php' => 2,
          'admin/includes/pos-order-close.php' => 2, 'admin/includes/pos-order-delete.php' => 1, 'admin/room-service-dashboard.php' => 1, 'admin/stock-orders.php' => 1] as $file => $min) {
    $n = preg_match_all('/rh_stock_lock_ingredients_for_(lines|orders)\(/', (string)file_get_contents($root . '/' . $file));
    check($n >= $min, "d: $file pre-locks the ingredients of all its lines ($n call(s))");
}

/* ---- Nothing left behind ---- */
$leftIng = (int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ["ZZ TEST expiry fifo $run%"]);
$leftRows = 0;
if ($ingIds) {
    $in = implode(',', array_map('intval', $ingIds));
    $leftRows += (int)col($pdo, "SELECT COUNT(*) FROM stock_batches WHERE ingredient_id IN ($in)");
    $leftRows += (int)col($pdo, "SELECT COUNT(*) FROM stock_adjustments WHERE ingredient_id IN ($in)");
    $leftRows += (int)col($pdo, "SELECT COUNT(*) FROM stock_wastage WHERE ingredient_id IN ($in)");
}
if ($srcIds) {
    $leftRows += (int)col($pdo, "SELECT COUNT(*) FROM stock_adjustments WHERE source_id IN (" . implode(',', array_map('intval', $srcIds)) . ")");
}
check($leftIng === 0 && $leftRows === 0, 'Rolled back: no test rows left', "ingredients=$leftIng other=$leftRows");

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
