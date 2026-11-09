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
 *     is NOT reactivated, a fresh batch takes the stock; a second restore puts nothing back twice.
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

    // c2: after the sweep the expired batch is not reactivated.
    $g = $mkIng('G', 15);
    $gOld = $mkBatch($g, 10, $past, 100);
    $gNew = $mkBatch($g, 5, $future, 120);
    $srcG = $pickSrc();
    deductStockBatchFIFO($g, 4, 'room_service', $srcG, $adminId);
    $left = $sweepWouldWriteOff($g);
    check(near($left, 6), 'c2: 6 left on the expired batch');
    // Same writes runStockExpiryCheck() makes, for this one batch only.
    $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - ? WHERE id = ?")->execute([$left, $g]);
    $pdo->prepare("UPDATE stock_batches SET quantity_remaining = 0, status = 'expired' WHERE id = ?")->execute([$gOld]);
    check(near($ingQty($g), 5), 'c2: after the sweep ingredient 5 (15 - 4 sold - 6 written off)');
    check(restoreStockForMenuItem(0, 'food', 1, 'ZZ TEST void', $adminId, $srcG, 'room_service'), 'c2: restore after the sweep succeeds');
    $bo = $batch($gOld);
    check(($bo['status'] ?? '') === 'expired' && near($bo['quantity_remaining'] ?? -1, 0), 'c2: expired batch stays expired at 0 (not revived)', json_encode($bo));
    check((int)col($pdo, "SELECT COUNT(*) FROM stock_batches WHERE ingredient_id = ? AND id NOT IN (?, ?) AND status = 'active' AND expiry_date IS NULL AND quantity_remaining BETWEEN 3.9999 AND 4.0001", [$g, $gOld, $gNew]) === 1, 'c2: a fresh active batch of 4 takes the restored stock');
    check(near($ingQty($g), 9) && near($activeSum($g), 9), 'c2: ingredient 9 = active batches 9');
} catch (Throwable $e) {
    check(false, 'No exception', $e->getMessage());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

/* ---- Nothing left behind ---- */
$leftIng = (int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ["ZZ TEST expiry fifo $run%"]);
$leftRows = 0;
if ($ingIds) {
    $in = implode(',', array_map('intval', $ingIds));
    $leftRows += (int)col($pdo, "SELECT COUNT(*) FROM stock_batches WHERE ingredient_id IN ($in)");
    $leftRows += (int)col($pdo, "SELECT COUNT(*) FROM stock_adjustments WHERE ingredient_id IN ($in)");
}
if ($srcIds) {
    $leftRows += (int)col($pdo, "SELECT COUNT(*) FROM stock_adjustments WHERE source_id IN (" . implode(',', array_map('intval', $srcIds)) . ")");
}
check($leftIng === 0 && $leftRows === 0, 'Rolled back: no test rows left', "ingredients=$leftIng other=$leftRows");

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
