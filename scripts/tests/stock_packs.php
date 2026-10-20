<?php
/**
 * Purchase packs test (crate of 24 bottles etc.).
 * Usage: php scripts/tests/stock_packs.php
 *
 * Covers: pack conversion for PO lines typed in units or packs (quantity, cost per unit, line total),
 * price per pack vs per unit, an item with no pack refusing pack input, receiving a pack line into
 * stock in STOCK units (batch, stock_in_log, current quantity, cost per unit from the pack price),
 * the over-delivery arithmetic staying in stock units, the buying suggestion rounding up to whole
 * packs, the wording ("2 crates = 48 bottles"), and the budget "committed" value being the same
 * whether the same order is typed in units or in packs.
 *
 * Everything runs in ONE transaction that is rolled back. The schema (DDL) is made before it starts.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../admin/includes/procurement-schema.php';
require_once __DIR__ . '/../../admin/includes/stock-budget-lib.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function near($a, float $b): bool { return abs((float)$a - $b) <= 0.0005; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

if (!ensureProcurementSchema($pdo) || !rh_sbud_ensure_schema($pdo)) { bad('Schema: procurement and budget tables exist'); echo "\n0 passed, 1 failed\n"; exit(1); }
foreach ([['stock_ingredients', 'pack_name'], ['stock_ingredients', 'pack_size'], ['stock_purchase_order_items', 'order_in'], ['stock_purchase_order_items', 'pack_size_snapshot']] as [$t, $c]) {
    check(rh_column_exists($pdo, $t, $c), "Schema: $t.$c exists");
}
check(ensureProcurementSchema($pdo), 'Schema: second call is a no-op');

$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1");
if (!$adminId) { bad('Fixture: an admin user exists'); echo "\n0 passed, 1 failed\n"; exit(1); }
$run = strtoupper(bin2hex(random_bytes(3)));
$cat = 'ZZT-PK-' . $run;

// --- Pure conversion -------------------------------------------------------------------------
$f = rh_pack_convert('pack', 2, 120000, 'pack', 24);
check($f['order_in'] === 'pack' && near($f['ordered_qty'], 48) && near($f['unit_cost'], 5000) && near($f['line_total'], 240000), '2 crates of 24 at 120,000 a crate = 48 bottles at 5,000 = 240,000', json_encode($f));
$f2 = rh_pack_convert('unit', 48, 5000, 'unit', 24);
check($f2['order_in'] === 'unit' && near($f2['ordered_qty'], 48) && near($f2['unit_cost'], 5000) && near($f2['line_total'], 240000), '48 bottles at 5,000 each: same stock figures as 2 crates', json_encode($f2));
$f3 = rh_pack_convert('unit', 48, 120000, 'pack', 24);
check(near($f3['unit_cost'], 5000) && near($f3['line_total'], 240000), 'Units ordered but price given per crate: cost per bottle is price / pack size', json_encode($f3));
$f4 = rh_pack_convert('pack', 2, 5000, 'unit', 24);
check(near($f4['ordered_qty'], 48) && near($f4['line_total'], 240000), 'Packs ordered but price given per bottle', json_encode($f4));
$f5 = rh_pack_convert('pack', 2, 100, 'pack', 3);
check(near($f5['ordered_qty'], 6) && near($f5['unit_cost'], 33.3333) && near($f5['line_total'], 200.0), 'Price that does not divide evenly: cost per unit is 4 places, line total rounds to the cent (6 x 33.3333 = 200.00)', json_encode($f5));
$f6 = rh_pack_convert('pack', 1.5, 60, 'pack', 12);
check(near($f6['ordered_qty'], 18) && near($f6['line_total'], 90), 'Half packs allowed (1.5 x 12 = 18)', json_encode($f6));
$refused = false;
try { rh_pack_convert('pack', 2, 10, 'pack', null); } catch (RuntimeException $e) { $refused = true; }
check($refused, 'An item with no pack size refuses pack input');
$plain = rh_pack_convert('unit', 5, 10, 'unit', null);
check($plain['order_in'] === 'unit' && near($plain['ordered_qty'], 5) && near($plain['line_total'], 50) && $plain['pack_size'] === null, 'An item with no pack still works in units');

// --- Suggestion rounding ---------------------------------------------------------------------
$s1 = rh_pack_suggest(30, 24);
check($s1['packs'] === 2 && near($s1['units'], 48), 'Suggestion: 30 bottles short -> 2 crates (48)', json_encode($s1));
$s2 = rh_pack_suggest(24, 24);
check($s2['packs'] === 1 && near($s2['units'], 24), 'Suggestion: exactly one crate stays one crate', json_encode($s2));
$s3 = rh_pack_suggest(24.0004, 24);
check($s3['packs'] === 1, 'Suggestion: float dust above a whole crate does not add another', json_encode($s3));
$s4 = rh_pack_suggest(1, 24);
check($s4['packs'] === 1 && near($s4['units'], 24), 'Suggestion: 1 bottle short still needs a whole crate', json_encode($s4));
$s5 = rh_pack_suggest(7.5, null);
check($s5['packs'] === null && near($s5['units'], 7.5), 'Suggestion: no pack size -> plain units', json_encode($s5));
$s6 = rh_pack_suggest(48.001, 24);
check($s6['packs'] === 3, 'Suggestion: a real fraction over two crates rounds up to three', json_encode($s6));

// --- Wording -------------------------------------------------------------------------------
check(rh_pack_describe(48, 'bottle', 'pack', 24, 'crate') === '2 crates = 48 bottles', 'Wording: "2 crates = 48 bottles"', rh_pack_describe(48, 'bottle', 'pack', 24, 'crate'));
check(rh_pack_describe(24, 'bottle', 'pack', 24, 'crate') === '1 crate = 24 bottles', 'Wording: one crate', rh_pack_describe(24, 'bottle', 'pack', 24, 'crate'));
check(rh_pack_describe(10, 'kg', 'unit', null, null) === '10 kg', 'Wording: a unit line is just the quantity');
check(rh_pack_label('box', 3) === 'boxes' && rh_pack_label('tray', 2) === 'trays', 'Wording: plurals (boxes, trays)');

$pdo->beginTransaction();
try {
    $mk = function (string $name, ?string $pn, ?float $ps) use ($pdo, $cat, $run): int {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, cost_per_unit, pack_name, pack_size) VALUES (?,?,?,?,?,?)")
            ->execute([$name . ' ' . $run, $cat, 'bottle', 0, $pn, $ps]);
        return (int)$pdo->lastInsertId();
    };
    $ingPack = $mk('Pack test lager', 'crate', 24.0);
    $ingPlain = $mk('Pack test plain', null, null);
    check(near(col($pdo, "SELECT pack_size FROM stock_ingredients WHERE id = ?", [$ingPack]), 24) && col($pdo, "SELECT pack_name FROM stock_ingredients WHERE id = ?", [$ingPack]) === 'crate', 'Saved: pack name and size on the item');
    check(col($pdo, "SELECT pack_size FROM stock_ingredients WHERE id = ?", [$ingPlain]) === null, 'Saved: no pack = NULL');

    $mkPo = function (string $status) use ($pdo, $run, $adminId): int {
        static $n = 0; $n++;
        $pdo->prepare("INSERT INTO stock_purchase_orders (reference, status, order_date, created_by) VALUES (?,?,CURDATE(),?)")->execute(['ZZP' . $run . $n, $status, $adminId]);
        return (int)$pdo->lastInsertId();
    };
    // Same insert the Purchase Orders page does, so the saved row is what the page saves.
    $addLine = function (int $po, int $ing, string $orderIn, float $qty, float $price, string $priceIn) use ($pdo): int {
        $fig = rh_po_line_figures($pdo, $ing, $orderIn, $qty, $price, $priceIn);
        $isPack = $fig['order_in'] === 'pack';
        $pdo->prepare("INSERT INTO stock_purchase_order_items (purchase_order_id, ingredient_id, description, unit, ordered_qty, unit_cost, line_total, order_in, pack_size_snapshot, pack_name_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$po, $ing, 'line', 'bottle', $fig['ordered_qty'], $fig['unit_cost'], $fig['line_total'], $fig['order_in'], $isPack ? $fig['pack_size'] : null, $isPack ? $fig['pack_name'] : null]);
        return (int)$pdo->lastInsertId();
    };

    // --- PO create: packs vs units ------------------------------------------------------------
    $poA = $mkPo('sent');
    $lineA = $addLine($poA, $ingPack, 'pack', 2, 120000, 'pack');
    $rowA = $pdo->query("SELECT * FROM stock_purchase_order_items WHERE id = $lineA")->fetch(PDO::FETCH_ASSOC);
    check(near($rowA['ordered_qty'], 48) && near($rowA['unit_cost'], 5000) && near($rowA['line_total'], 240000) && $rowA['order_in'] === 'pack' && near($rowA['pack_size_snapshot'], 24) && $rowA['pack_name_snapshot'] === 'crate', 'PO line in packs saved in stock units with the pack remembered for display', json_encode($rowA));
    $poB = $mkPo('draft');
    $lineB = $addLine($poB, $ingPack, 'unit', 48, 5000, 'unit');
    $rowB = $pdo->query("SELECT * FROM stock_purchase_order_items WHERE id = $lineB")->fetch(PDO::FETCH_ASSOC);
    check(near($rowB['ordered_qty'], 48) && near($rowB['unit_cost'], 5000) && near($rowB['line_total'], 240000) && $rowB['order_in'] === 'unit' && $rowB['pack_size_snapshot'] === null, 'PO line in units: identical stock figures, no pack remembered', json_encode($rowB));
    $refused = false;
    try { $addLine($mkPo('draft'), $ingPlain, 'pack', 1, 10, 'pack'); } catch (RuntimeException $e) { $refused = true; }
    check($refused, 'PO line in packs for an item with no pack is refused');
    $refused = false;
    try { $addLine($mkPo('draft'), 0, 'pack', 1, 10, 'unit'); } catch (RuntimeException $e) { $refused = true; }
    check($refused, 'PO line in packs for a non-stock line is refused');

    // --- Budget: committed does not depend on how it was typed ---------------------------------
    $key = rh_sbud_cat_key($cat);
    $commPack = (float)(rh_sbud_committed_by_category($pdo)[$key] ?? 0);
    check(near($commPack, 240000), 'Budget committed: 2 crates sent = 240,000', (string)$commPack);
    $pdo->prepare("UPDATE stock_purchase_orders SET status = 'cancelled' WHERE id = ?")->execute([$poA]);
    $pdo->prepare("UPDATE stock_purchase_orders SET status = 'sent' WHERE id = ?")->execute([$poB]);
    $commUnit = (float)(rh_sbud_committed_by_category($pdo)[$key] ?? 0);
    check(near($commUnit, $commPack), 'Budget committed: the same order typed in bottles gives the same value', (string)$commUnit);
    $pdo->prepare("UPDATE stock_purchase_orders SET status = 'sent' WHERE id = ?")->execute([$poA]);
    $pdo->prepare("UPDATE stock_purchase_orders SET status = 'cancelled' WHERE id = ?")->execute([$poB]);

    // --- Receive (stock units) ------------------------------------------------------------------
    // The page receives a quantity in STOCK units against ordered_qty (also in stock units).
    $batch = rh_receive_stock_line($pdo, $ingPack, 24.0, (float)$rowA['unit_cost'], null, null, null, null, 7, $poA, $adminId, 'PO test');
    $pdo->prepare("UPDATE stock_purchase_order_items SET received_qty = received_qty + 24 WHERE id = ?")->execute([$lineA]);
    $ing = $pdo->query("SELECT current_quantity, cost_per_unit FROM stock_ingredients WHERE id = $ingPack")->fetch(PDO::FETCH_ASSOC);
    check(near($ing['current_quantity'], 24) && near($ing['cost_per_unit'], 5000), 'Receive: one crate (24) arrives as 24 bottles at 5,000 each (price per crate / 24)', json_encode($ing));
    $log = $pdo->query("SELECT quantity, cost_per_unit, cost_total FROM stock_in_log WHERE batch_id = $batch")->fetch(PDO::FETCH_ASSOC);
    check($log && near($log['quantity'], 24) && near($log['cost_per_unit'], 5000) && near($log['cost_total'], 120000), 'Receive: stock_in_log holds bottles, not crates', json_encode($log));
    $b = $pdo->query("SELECT quantity_received, quantity_remaining, cost_per_unit FROM stock_batches WHERE id = $batch")->fetch(PDO::FETCH_ASSOC);
    check($b && near($b['quantity_received'], 24) && near($b['quantity_remaining'], 24) && near($b['cost_per_unit'], 5000), 'Receive: the batch is in bottles', json_encode($b));
    $commHalf = (float)(rh_sbud_committed_by_category($pdo)[$key] ?? 0);
    check(near($commHalf, 120000), 'Budget committed after receiving a crate: the other crate (120,000) is still committed', (string)$commHalf);
    // Over-delivery rule works on stock units: outstanding is 24 bottles, receiving 30 is 6 over.
    $line = $pdo->query("SELECT ordered_qty, received_qty FROM stock_purchase_order_items WHERE id = $lineA")->fetch(PDO::FETCH_ASSOC);
    $outstanding = max(0.0, (float)$line['ordered_qty'] - (float)$line['received_qty']);
    check(near($outstanding, 24) && (30 > $outstanding + 0.0001) && near(round(30 - $outstanding, 3), 6), 'Over-delivery: judged in bottles (24 outstanding, 30 received = 6 over)');
    check(near($outstanding / 24, 1), 'Display: outstanding shown as 1 crate');
} catch (Throwable $e) {
    bad('Unexpected error', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
if ($pdo->inTransaction()) { $pdo->rollBack(); }
check((int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ['%' . $run]) === 0, 'Rollback: no test ingredients left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM stock_purchase_orders WHERE reference LIKE ?", ['ZZP' . $run . '%']) === 0, 'Rollback: no test purchase orders left behind');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
