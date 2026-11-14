<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Step 8c Build B - stock core.  Usage (project root): php scripts/tests/stock_core_8c.php
 *
 * LIWONDE ONLY (sha1 of the database name; parent and child refuse any other database).
 * Part 1 (engine, in this process, ONE transaction rolled back, throwaway "ZZ8B" rows):
 *   S1 a. restore of a sale that went partly beyond the batches, after a delivery settled the deficit: the untraced
 *         part comes back as an R-batch at its own cost (6@100 + 4 untraced@150 -> cost_at_time 120, R-batch 4@150);
 *         quantity = SUM(active batches); batch value restored = cost of sales reversed (10 x 120).
 *      b. same sale restored before any delivery: no R-batch (the traced batch is enough).
 *      c. recipe fallback (no trail) whose batches have no room: the leftover comes back as an R-batch.
 *   S2 rhLookupBarcode returns is_archived for an archived ingredient (no filter).
 * Part 2 (real admin pages through scripts/tests/page_harness.php + stock_core_8c_child.php, rolled back):
 *   S2 archive refused while in a recipe; recipe save refuses an archived ingredient; Receive Stock refuses one.
 *   S8 discard of an empty / closed batch refused.  S9 backdated wastage before an approved count refused.
 *   S12 the identical stock-in / PO receive submitted twice from the same session: second one refused.
 *       (Two page runs on the same fixed fixture ids; the guard values the first run left in its session are
 *       carried into the second run's session.)
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
if (sha1(DB_NAME) !== '3b8976ab1ff31dab31141c7344d737ecc8725d41') { fwrite(STDERR, "Refused: this suite runs only on the Liwonde test database.\n"); exit(9); }

$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): bool { global $pass, $fail; if ($c) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } return $c; }
function near($a, float $b, float $tol = 0.0005): bool { return abs((float)$a - $b) <= $tol; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

// Schema helpers may run DDL (implicit commit): call them before the transaction opens.
if (!ensureStockTablesExist()) { check(false, 'Stock tables exist'); echo "\n$pass passed, $fail failed\n"; exit(1); }
rh_stock_adjustments_has_restore_col();
$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1") ?: null;
$run = strtoupper(bin2hex(random_bytes(3)));
$unused = static function (string $table) use ($pdo): int {
    for ($i = 0; $i < 50; $i++) {
        $id = 2100000000 + random_int(1, 40000000);
        if ((int)col($pdo, "SELECT COUNT(*) FROM `$table` WHERE id = ?", [$id]) === 0) { return $id; }
    }
    throw new RuntimeException("no free id in $table");
};
$srcIds = [];
$pickSrc = static function () use ($pdo, &$srcIds): int {
    for ($i = 0; $i < 50; $i++) {
        $id = 2000000000 + random_int(1, 140000000);
        if ((int)col($pdo, "SELECT COUNT(*) FROM stock_adjustments WHERE source_id = ?", [$id]) === 0) { $srcIds[] = $id; return $id; }
    }
    throw new RuntimeException('no free source id');
};

/* ================= Part 1: engine ================= */
$ingIds = [];
try {
    $pdo->beginTransaction();
    $mkIng = static function (string $label, float $qty, float $avg) use ($pdo, $run, &$ingIds): int {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'ZZ8B test', 'each', ?, ?)")
            ->execute(["ZZ8B $run $label", $qty, $avg]);
        return $ingIds[] = (int)$pdo->lastInsertId();
    };
    $seq = 0;
    $mkBatch = static function (int $ing, float $recv, float $rem, float $cost) use ($pdo, $run, &$seq): int {
        $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, expiry_date, status) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?)")
            ->execute([$ing, "ZZ8B-$run-" . (++$seq), $recv, $rem, $cost, date('Y-m-d', strtotime('+60 days')), $rem > 0.0001 ? 'active' : 'depleted']);
        return (int)$pdo->lastInsertId();
    };
    $qty = static fn(int $id): float => (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$id]);
    $active = static fn(int $id): float => (float)col($pdo, "SELECT COALESCE(SUM(quantity_remaining),0) FROM stock_batches WHERE ingredient_id = ? AND status = 'active'", [$id]);
    $value = static fn(int $id): float => (float)col($pdo, "SELECT COALESCE(SUM(quantity_remaining * cost_per_unit),0) FROM stock_batches WHERE ingredient_id = ? AND status = 'active'", [$id]);
    $nBatches = static fn(int $id): int => (int)col($pdo, "SELECT COUNT(*) FROM stock_batches WHERE ingredient_id = ?", [$id]);

    /* ---- S1 a: settled deficit ---- */
    $a = $mkIng('S1a', 6, 150);
    $mkBatch($a, 6, 6, 100);
    $src = $pickSrc();
    $adj = deductStockBatchFIFO($a, 10, 'room_service', $src, $adminId);
    $cat = (float)col($pdo, "SELECT cost_at_time FROM stock_adjustments WHERE id = ?", [(int)$adj]);
    check(near($qty($a), -4) && near($cat, 120), 'S1a: sale of 10 from 6@100 + 4 beyond the batches at 150 -> -4, cost 120', "qty={$qty($a)} cost=$cat");
    // A delivery of 10 @ 200 settles the deficit of 4 (same engine call the receive screens make).
    $settle = rh_stock_receipt_settlement($pdo, $a, $qty($a), 10);
    $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status) VALUES (?, ?, 10, ?, 200, CURDATE(), 'active')")
        ->execute([$a, "ZZ8B-$run-" . (++$seq), $settle['remaining']]);
    $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + 10 WHERE id = ?")->execute([$a]);
    check(near($qty($a), 6) && near($active($a), 6), 'S1a: delivery settles the deficit (6 on hand, batches 6)', "qty={$qty($a)} batches={$active($a)}");
    $valBefore = $value($a);
    $batchesBefore = $nBatches($a);
    $ok = restoreStockForMenuItem(0, 'zz8b', 10, 'ZZ8B restore', $adminId, $src, 'room_service');
    $restCost = (float)col($pdo, "SELECT cost_at_time FROM stock_adjustments WHERE source_type = 'void_restore' AND source_id = ? AND ingredient_id = ?", [$src, $a]);
    check($ok && near($qty($a), 16), 'S1a: restore puts the 10 back on the total', "qty={$qty($a)}");
    check(near($qty($a), $active($a)), 'S1a: quantity = SUM(active batches) after the restore', "qty={$qty($a)} batches={$active($a)}");
    $rb = $pdo->prepare("SELECT quantity_remaining, cost_per_unit, batch_number FROM stock_batches WHERE ingredient_id = ? AND batch_number LIKE 'R%' ORDER BY id DESC LIMIT 1");
    $rb->execute([$a]);
    $r = $rb->fetch(PDO::FETCH_ASSOC) ?: [];
    check($nBatches($a) === $batchesBefore + 1 && near($r['quantity_remaining'] ?? 0, 4) && near($r['cost_per_unit'] ?? 0, 150), 'S1a: untraced 4 back as an R-batch at its own cost 150 (not the blended 120)', json_encode($r));
    check(near($value($a) - $valBefore, 10 * $restCost, 0.01) && near($restCost * 10, 1200, 0.01), 'S1a: batch value restored (1,200) = cost of sales reversed (10 x 120)', 'delta=' . ($value($a) - $valBefore) . " reversed=" . ($restCost * 10));
    $restLogs = (int)col($pdo, "SELECT COUNT(*) FROM stock_adjustments WHERE ingredient_id = ? AND source_type NOT IN ('room_service','void_restore')", [$a]);
    check($restLogs === 0, 'S1a: the R-batch is a batch row only (no extra adjustment)', "extra=$restLogs");

    /* ---- S1 b: restored before any delivery ---- */
    $b = $mkIng('S1b', 6, 150);
    $mkBatch($b, 6, 6, 100);
    $srcB = $pickSrc();
    deductStockBatchFIFO($b, 10, 'room_service', $srcB, $adminId);
    $nb = $nBatches($b);
    restoreStockForMenuItem(0, 'zz8b', 10, 'ZZ8B restore', $adminId, $srcB, 'room_service');
    check(near($qty($b), 6) && near($active($b), 6) && $nBatches($b) === $nb, 'S1b: unsettled sale restored: 6 back in its batch, no R-batch', "qty={$qty($b)} batches={$active($b)} n={$nBatches($b)}/$nb");

    /* ---- S1 c: recipe fallback leftover ---- */
    $c = $mkIng('S1c', 5, 80);
    $mkBatch($c, 5, 5, 80); // full: no room to take stock back
    $menuId = $unused('menu_items');
    $pdo->prepare("INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe) VALUES (?, 'zz8b', 1)")->execute([$menuId]);
    $pdo->prepare("INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent) VALUES (?, ?, 1, 100)")->execute([(int)$pdo->lastInsertId(), $c]);
    $srcC = $pickSrc();
    restoreStockForMenuItem($menuId, 'zz8b', 2, 'ZZ8B restore', $adminId, $srcC, 'room_service');
    $rc = $pdo->prepare("SELECT quantity_remaining, cost_per_unit FROM stock_batches WHERE ingredient_id = ? AND batch_number LIKE 'R%'");
    $rc->execute([$c]);
    $rcr = $rc->fetch(PDO::FETCH_ASSOC) ?: [];
    check(near($qty($c), 7) && near($active($c), 7), 'S1c: recipe fallback leftover: quantity = SUM(active batches)', "qty={$qty($c)} batches={$active($c)}");
    check(near($rcr['quantity_remaining'] ?? 0, 2) && near($rcr['cost_per_unit'] ?? 0, 80), 'S1c: leftover 2 back as an R-batch at the restore cost (80)', json_encode($rcr));

    /* ---- S2: barcode lookup tells the screen the item is archived ---- */
    $d = $mkIng('S2arch', 0, 10);
    $pdo->prepare("UPDATE stock_ingredients SET is_archived = 1 WHERE id = ?")->execute([$d]);
    $bc = '29' . str_pad((string)random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO stock_ingredient_barcodes (barcode, ingredient_id, pack_size) VALUES (?, ?, 1)")->execute([$bc, $d]);
    $hit = rhLookupBarcode($pdo, $bc, 'stock_first');
    check(($hit['kind'] ?? '') === 'ingredient' && (int)($hit['row']['ingredient_id'] ?? 0) === $d, 'S2: rhLookupBarcode still finds an archived ingredient (no filter)');
    check(isset($hit['row']['is_archived']) && (int)$hit['row']['is_archived'] === 1, 'S2: rhLookupBarcode returns is_archived', json_encode($hit['row'] ?? null));
} catch (Throwable $e) {
    check(false, 'Part 1 ran without error', $e->getMessage());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
$probe = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$left = (int)col($probe, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ["ZZ8B $run%"])
    + ($srcIds ? (int)col($probe, "SELECT COUNT(*) FROM stock_adjustments WHERE source_id IN (" . implode(',', array_map('intval', $srcIds)) . ")") : 0)
    + ($ingIds ? (int)col($probe, "SELECT COUNT(*) FROM stock_batches WHERE ingredient_id IN (" . implode(',', array_map('intval', $ingIds)) . ")") : 0);
check($left === 0, 'Part 1 rolled back: nothing left behind (second connection)', "left=$left");

/* ================= Part 2: pages ================= */
$harness = $root . '/scripts/tests/page_harness.php';
$sessDir = $root . '/scripts/tests/sessions';
$like = "'ZZ8B {$run} %'";
function pg(string $scenario, array $verify, array $fx = [], array $session = []): array {
    global $harness, $run;
    $cfg = base64_encode(json_encode(['post' => ['scenario' => $scenario, 'run' => $run, 'fx' => json_encode($fx)], 'verify' => $verify, 'session' => $session]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/stock_core_8c_child.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['raw' => substr($out, -600)];
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $res = $x + $res; } }
    $res['flash'] = ['', ''];
    if (preg_match('/@@FLASH@@(.*)$/m', $out, $m)) { $f = json_decode($m[1], true); if (is_array($f)) { $res['flash'] = $f; } }
    check(!empty($res['rolled_back']) && empty($res['leak']) && empty($res['marker_lost']), "$scenario: ran and rolled back, nothing leaked", json_encode($res['raw']));
    return $res;
}
/* Session values a page left behind (guard fingerprints), found by the probe key the run was given. */
function sessionGuards(string $dir, string $probeVal): array {
    foreach (glob($dir . '/sess_harness*') ?: [] as $f) {
        $raw = (string)@file_get_contents($f);
        if (strpos($raw, $probeVal) === false) { continue; }
        @unlink($f);
        $out = [];
        if (preg_match_all('/(\w+_last_(?:fp|at))\|(s:\d+:"[^"]*";|i:-?\d+;)/', $raw, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m1) { $out[$m1[1]] = unserialize($m1[2]); }
        }
        return $out;
    }
    return [];
}
$vIng = "SELECT name, is_archived, current_quantity FROM stock_ingredients WHERE name LIKE $like ORDER BY id";

// S2 archive
$r = pg('s2_archive_in_recipe', [$vIng]);
check(($r['verify'][0][0]['is_archived'] ?? null) == 0 && stripos($r['flash'][0], 'Dish') !== false, 'S2: archive refused while the item is in a recipe (reason names the dish)', json_encode([$r['flash'], $r['verify'][0] ?? null]));
$r = pg('s2_archive_free', [$vIng]);
check(($r['verify'][0][0]['is_archived'] ?? null) == 1 && $r['flash'][0] === '', 'S2: archive of an item in no recipe still works', json_encode($r['flash']));
// S2 recipe save
$vRec = "SELECT COUNT(*) AS n FROM stock_recipe_ingredients sri INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id WHERE i.name LIKE $like";
$r = pg('s2_recipe_archived', [$vRec]);
check((int)($r['verify'][0][0]['n'] ?? -1) === 0 && stripos($r['flash'][0], 'archived') !== false, 'S2: recipe save refuses an archived ingredient', json_encode([$r['flash'], $r['verify'] ?? null]));
$r = pg('s2_recipe_live', [$vRec]);
check((int)($r['verify'][0][0]['n'] ?? -1) === 1, 'S2: recipe save with a live ingredient still works', json_encode($r['flash']));
// S2 receive screen
$vBat = "SELECT COUNT(*) AS n FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE i.name LIKE $like";
$r = pg('s2_receive_archived', [$vBat, $vIng]);
check((int)($r['verify'][0][0]['n'] ?? -1) === 0 && near($r['verify'][1][0]['current_quantity'] ?? -1, 0) && stripos((string)($r['json']['error'] ?? ''), 'archived') !== false,
    'S2: Receive Stock refuses an archived item with a plain reason', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
$r = pg('s2_lookup_archived', [], ['barcode' => '28' . str_pad((string)random_int(0, 9999999999), 10, '0', STR_PAD_LEFT)]);
check((int)($r['json']['ingredient']['is_archived'] ?? 0) === 1, 'S2: scan lookup tells the Receive screen the item is archived', json_encode($r['json'] ?? null));
// S8
$vB = "SELECT b.status, b.quantity_remaining FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE i.name LIKE $like";
$vW = "SELECT COUNT(*) AS n FROM stock_wastage w INNER JOIN stock_ingredients i ON i.id = w.ingredient_id WHERE i.name LIKE $like";
$r = pg('s8_discard_depleted', [$vB]);
check(($r['verify'][0][0]['status'] ?? '') === 'depleted' && $r['flash'][0] !== '', 'S8: discard of an empty batch refused (stays depleted)', json_encode([$r['flash'], $r['verify'] ?? null]));
$r = pg('s8_discard_active', [$vB, $vIng]);
check(($r['verify'][0][0]['status'] ?? '') === 'wasted' && near($r['verify'][1][0]['current_quantity'] ?? -1, 0) && near($r['verify'][0][0]['quantity_remaining'] ?? -1, 0), 'S8: discard of an active batch still works (qty = batches = 0)', json_encode([$r['flash'], $r['verify'] ?? null]));
// S9
$r = pg('s9_before_count', [$vW, $vIng, "SELECT COALESCE(SUM(b.quantity_remaining),0) AS s FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE i.name LIKE $like AND b.status = 'active'"]);
check((int)($r['verify'][0][0]['n'] ?? -1) === 0 && near($r['verify'][1][0]['current_quantity'] ?? -1, 10) && near($r['verify'][2][0]['s'] ?? -1, 10) && stripos($r['flash'][0], 'already in the count') !== false,
    'S9: wastage dated before an approved count of the item refused (qty = batches = 10)', json_encode([$r['flash'], $r['verify'] ?? null]));
$r = pg('s9_same_day', [$vW, $vIng]);
check((int)($r['verify'][0][0]['n'] ?? -1) === 1 && near($r['verify'][1][0]['current_quantity'] ?? -1, 8), 'S9: wastage dated the same day as the count still recorded', json_encode($r['flash']));
// S12
foreach (['s12_stock_in' => ['ing' => $unused('stock_ingredients')], 's12_po_receive' => ['ing' => $unused('stock_ingredients'), 'po' => $unused('stock_purchase_orders'), 'line' => $unused('stock_purchase_order_items')]] as $sc => $fx) {
    $probeVal = 'zz8b' . bin2hex(random_bytes(6));
    $first = pg($sc, [$vIng], $fx, ['zz8b_probe' => $probeVal]);
    $guards = sessionGuards($sessDir, $probeVal);
    check(near($first['verify'][0][0]['current_quantity'] ?? -1, $sc === 's12_stock_in' ? 5 : 3) && $first['flash'][0] === '', "S12 $sc: first submit received", json_encode($first['flash']));
    $second = pg($sc, [$vIng], $fx, $guards);
    check(near($second['verify'][0][0]['current_quantity'] ?? -1, 0) && stripos($second['flash'][0], 'duplicate') !== false, "S12 $sc: identical second submit from the same session refused", json_encode([$second['flash'], $second['verify'] ?? null, array_keys($guards)]));
}
// Leftover harness session files from this run's other scenarios are left as the harness leaves them.

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
