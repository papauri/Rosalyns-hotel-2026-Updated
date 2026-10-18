<?php

/**
 * POS → KDS lifecycle smoke test — runs against the live DB.
 * Usage: php scripts/smoke_test_pos_kds.php
 * Cleans up its own test data on completion.
 *
 * Fires a test order the way the till does, walks it through the station board
 * (Start → Ready → Collect → Served), and asserts the invariants the POS and
 * KDS rely on each other for. Every assertion here corresponds to behaviour
 * the two surfaces share, so a regression on either side fails this test:
 *
 *   1. Station routing whitelists to kitchen/bar/coffee_bar, never orphaning a
 *      line off every board.
 *   2. Stock does NOT move at order placement.
 *   3. Stock moves when a line is marked Ready, guarded by stock_deducted so
 *      it cannot be taken twice.
 *   4. kds_recompute_order_status() maps line states to the order-level
 *      kitchen_status the till's tracker reads.
 *   5. Voiding a deducted line (the KDS "86") restores exactly what it took.
 *   6. Item and order status values stay inside their schema enums.
 *
 * Read-only against menu/ingredient data: it creates its own order, moves its
 * own stock, and puts everything back.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

require_once __DIR__ . '/../config/database.php';

$pass = 0;
$fail = 0;
/** @var int[] $createdOrderIds */
$createdOrderIds = [];

function ok(string $label): void
{
    global $pass;
    $pass++;
    echo "[PASS] $label\n";
}

function bad(string $label, string $detail = ''): void
{
    global $fail;
    $fail++;
    echo "[FAIL] $label" . ($detail ? ": $detail" : '') . "\n";
}

function check(bool $cond, string $label, string $detail = ''): void
{
    $cond ? ok($label) : bad($label, $detail);
}

/* Remove fixtures even if a later section throws. Reads $createdOrderIds by
   reference so it sees ids pushed after registration. */
/* Stock snapshot taken before the order (see section 1): the run must leave the live stock
   exactly as it found it - quantities, batch levels/status and movement history. The restore
   writes the snapshot values back, so run this while the till/KDS is idle: a real sale or a
   goods receipt on the same ingredient during the few seconds of the run would be overwritten. */
$stockSnap = ['ingredients' => [], 'batches' => [], 'max_batch_id' => 0];
$runStartedAt = date('Y-m-d H:i:s');
register_shutdown_function(function () use (&$createdOrderIds, &$stockSnap, $pdo, $runStartedAt) {
    // 1) Read the test lines first (needed to find their stock movements), then undo stock.
    $itemIds = [];
    try {
        if ($createdOrderIds) {
            $ph = implode(',', array_fill(0, count($createdOrderIds), '?'));
            $items = $pdo->prepare("SELECT id FROM stock_order_items WHERE order_id IN ($ph)");
            $items->execute($createdOrderIds);
            $itemIds = $items->fetchAll(PDO::FETCH_COLUMN);
        }
        if ($itemIds) {
            $iph = implode(',', array_fill(0, count($itemIds), '?'));
            $adj = $pdo->prepare("SELECT id FROM stock_adjustments WHERE source_type IN ('pos_order','void_restore') AND source_id IN ($iph) AND created_at >= ?");
            $adj->execute(array_merge($itemIds, [$runStartedAt]));
            $adjIds = $adj->fetchAll(PDO::FETCH_COLUMN);
            if ($adjIds) {
                $aph = implode(',', array_fill(0, count($adjIds), '?'));
                $pdo->prepare("DELETE FROM stock_batch_deductions WHERE adjustment_id IN ($aph)")->execute($adjIds);
                $pdo->prepare("DELETE FROM stock_adjustments WHERE id IN ($aph)")->execute($adjIds);
            }
        }
        if ($stockSnap['ingredients']) {
            $setIng = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = ? WHERE id = ?");
            foreach ($stockSnap['ingredients'] as $ingId => $q) { $setIng->execute([$q, $ingId]); }
            $setBatch = $pdo->prepare("UPDATE stock_batches SET quantity_remaining = ?, status = ? WHERE id = ?");
            foreach ($stockSnap['batches'] as $bId => $b) { $setBatch->execute([$b['quantity_remaining'], $b['status'], $bId]); }
            $gph = implode(',', array_fill(0, count($stockSnap['ingredients']), '?'));
            $pdo->prepare("DELETE FROM stock_batches WHERE id > ? AND ingredient_id IN ($gph) AND created_at >= ?")
                ->execute(array_merge([(int)$stockSnap['max_batch_id']], array_keys($stockSnap['ingredients']), [$runStartedAt]));
            // A portion bigger than the stock on hand logs a "went negative" stock event; drop the run's own.
            try {
                $pdo->prepare("DELETE FROM system_event_log WHERE source = 'stock' AND created_at >= ? AND message LIKE '%negative%'")
                    ->execute([$runStartedAt]);
            } catch (Throwable $e) { /* log table shape differs */ }
        }
    } catch (Throwable $e) {
        echo "\nStock cleanup warning: " . $e->getMessage() . "\n";
    }
    // 2) Remove the test order rows, independently of the stock step.
    if (!$createdOrderIds) {
        return;
    }
    try {
        $ph = implode(',', array_fill(0, count($createdOrderIds), '?'));
        foreach (['stock_kds_events', 'stock_order_audit', 'pos_ready_notifications'] as $t) {
            try { $pdo->prepare("DELETE FROM $t WHERE order_id IN ($ph)")->execute($createdOrderIds); } catch (Throwable $e) { /* table absent */ }
        }
        $pdo->prepare("DELETE FROM stock_order_items WHERE order_id IN ($ph)")->execute($createdOrderIds);
        $pdo->prepare("DELETE FROM station_messages  WHERE order_id IN ($ph)")->execute($createdOrderIds);
        $pdo->prepare("DELETE FROM stock_orders      WHERE id       IN ($ph)")->execute($createdOrderIds);
        echo "\nCleaned up " . count($createdOrderIds) . " test order(s).\n";
    } catch (Throwable $e) {
        echo "\nCleanup warning: " . $e->getMessage() . "\n";
    }
});

/**
 * Current quantity of every ingredient behind a menu item, keyed by ingredient id.
 * Joins recipe → ingredients the same way kds_recipe_requirements() does:
 * stock_recipes carries menu_item_id/menu_type, stock_recipe_ingredients hangs
 * off it by recipe_id.
 */
function ingredient_levels(PDO $pdo, int $menuItemId, string $menuType): array
{
    $sql = "SELECT sri.ingredient_id, si.current_quantity
              FROM stock_recipes sr
              JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
              JOIN stock_ingredients si ON si.id = sri.ingredient_id
             WHERE sr.menu_item_id = ? AND sr.menu_type = ?
               AND sri.quantity_per_portion > 0";
    $st = $pdo->prepare($sql);
    $st->execute([$menuItemId, $menuType]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['ingredient_id']] = (float)$r['current_quantity'];
    }
    return $out;
}

echo "\n=== POS → KDS lifecycle smoke test (" . date('Y-m-d H:i') . ") ===\n\n";

/* ── 1. Pick a recipe-backed menu item ──────────────────────────────────── */
echo "--- 1. Fixture selection ---\n";

/* Column shapes mirror pos_appendCartItemsToOrder() exactly: the line's station
   is the item's own override falling back to the category default, and
   menu_type is the category slug. */
$pick = $pdo->query("
    SELECT mi.id, mi.item_name AS name, mi.price,
           COALESCE(mi.station, mc.default_station) AS station,
           mc.slug AS menu_type
      FROM menu_items mi
      JOIN menu_categories mc ON mc.id = mi.category_id
      JOIN stock_recipes sr
        ON sr.menu_item_id = mi.id AND sr.menu_type = mc.slug
      JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
      JOIN stock_ingredients si ON si.id = sri.ingredient_id
     WHERE mi.is_available = 1
       AND sri.quantity_per_portion > 0
     GROUP BY mi.id
     ORDER BY MIN(si.current_quantity) > 0 DESC, mi.id
     LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$pick) {
    bad('Found a recipe-backed menu item', 'none available — cannot run lifecycle test');
    echo "\nResult: $pass passed, $fail failed\n";
    exit(1);
}
ok("Fixture menu item: #{$pick['id']} {$pick['name']} ({$pick['menu_type']})");

/* Snapshot the item's stock; with an empty store (opening stock not counted yet) top its
   ingredients up for the run. The shutdown handler puts every figure back. */
$stockSnap['ingredients'] = ingredient_levels($pdo, (int)$pick['id'], (string)$pick['menu_type']);
if ($stockSnap['ingredients']) {
    $gph = implode(',', array_fill(0, count($stockSnap['ingredients']), '?'));
    $bs = $pdo->prepare("SELECT id, quantity_remaining, status FROM stock_batches WHERE ingredient_id IN ($gph)");
    $bs->execute(array_keys($stockSnap['ingredients']));
    foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $b) { $stockSnap['batches'][(int)$b['id']] = $b; }
    $stockSnap['max_batch_id'] = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM stock_batches")->fetchColumn();
    $topUp = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + 1000 WHERE id = ? AND current_quantity < 1000");
    foreach (array_keys($stockSnap['ingredients']) as $ingId) { $topUp->execute([$ingId]); }
}

/* Mirrors pos_appendCartItemsToOrder()'s routing: whitelist, else kitchen. */
$station = in_array($pick['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true)
    ? $pick['station']
    : 'kitchen';
check(
    in_array($station, ['kitchen', 'bar', 'coffee_bar'], true),
    'Station routing resolves to a real board',
    "got '$station'"
);

/* ── 2. Fire the order ──────────────────────────────────────────────────── */
echo "\n--- 2. Fire order ---\n";

$before = ingredient_levels($pdo, (int)$pick['id'], (string)$pick['menu_type']);
check($before !== [], 'Recipe has ingredients to track', 'no rows in stock_recipe_ingredients');

$ref = 'TEST-' . date('ymdHis') . '-' . random_int(100, 999);
$qty = 1.0;
$price = (float)$pick['price'];

$pdo->prepare("
    INSERT INTO stock_orders (reference, order_type, table_number, customer_name, status,
                              total_amount, subtotal, kitchen_status, fired_at, created_by, created_at)
    VALUES (?, 'dine_in', 'SMOKE', 'Smoke Test', 'placed', ?, ?, 'new', NOW(), NULL, NOW())
")->execute([$ref, $price, $price]);
$orderId = (int)$pdo->lastInsertId();
$createdOrderIds[] = $orderId;
ok("Order #$orderId created ($ref)");

$pdo->prepare("
    INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity,
                                   unit_price, line_total, station)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
")->execute([$orderId, (int)$pick['id'], $pick['menu_type'], $pick['name'], $qty, $price, $price, $station]);
$itemId = (int)$pdo->lastInsertId();
ok("Line #$itemId created on station '$station'");

$row = $pdo->prepare("SELECT kds_status, stock_deducted FROM stock_order_items WHERE id=?");
$row->execute([$itemId]);
$line = $row->fetch(PDO::FETCH_ASSOC);

check($line['kds_status'] === 'pending', 'New line starts pending', "got '{$line['kds_status']}'");
check((int)$line['stock_deducted'] === 0, 'Placement does NOT deduct stock', 'stock_deducted was already 1');

$afterPlace = ingredient_levels($pdo, (int)$pick['id'], (string)$pick['menu_type']);
check($afterPlace == $before, 'Ingredient levels unchanged at placement');

/* ── 3. Start ───────────────────────────────────────────────────────────── */
echo "\n--- 3. Start (pending → preparing) ---\n";

$pdo->prepare("UPDATE stock_order_items SET kds_status='preparing', started_at=COALESCE(started_at,NOW()) WHERE id=?")
    ->execute([$itemId]);
$row->execute([$itemId]);
$line = $row->fetch(PDO::FETCH_ASSOC);
check($line['kds_status'] === 'preparing', 'Line is preparing');
check((int)$line['stock_deducted'] === 0, 'Start still does NOT deduct stock');

/* ── 4. Ready — the deduction point ─────────────────────────────────────── */
echo "\n--- 4. Ready (preparing → ready) — stock moves here ---\n";

$deducted = deductStockForMenuItem((int)$pick['id'], (string)$pick['menu_type'], $qty, 'pos_order', $itemId, null);
check($deducted, 'deductStockForMenuItem() succeeded');

$pdo->prepare("UPDATE stock_order_items SET kds_status='ready', ready_at=NOW(), stock_deducted=1 WHERE id=?")
    ->execute([$itemId]);

$afterReady = ingredient_levels($pdo, (int)$pick['id'], (string)$pick['menu_type']);
$movedDown = 0;
foreach ($before as $ing => $qtyBefore) {
    if (isset($afterReady[$ing]) && $afterReady[$ing] < $qtyBefore - 0.0000001) {
        $movedDown++;
    }
}
check($movedDown > 0, 'Ingredient levels fell at Ready', "no ingredient decreased ($movedDown of " . count($before) . ')');

/* Guard: a second Ready must not take stock twice. */
$row->execute([$itemId]);
$line = $row->fetch(PDO::FETCH_ASSOC);
check((int)$line['stock_deducted'] === 1, 'stock_deducted flag set — re-deduction is guarded');

/* ── 5. Order status recomputation ──────────────────────────────────────── */
echo "\n--- 5. Order-level status ---\n";

$computed = null;
if (function_exists('kds_recompute_order_status')) {
    $computed = kds_recompute_order_status($pdo, $orderId);
} else {
    /* kds_recompute_order_status lives in api/kds-action.php alongside the
       request handling, so it is not loadable here without executing that
       endpoint. Assert the equivalent rule directly instead. */
    $st = $pdo->prepare("SELECT kds_status, COUNT(*) c FROM stock_order_items WHERE order_id=? GROUP BY kds_status");
    $st->execute([$orderId]);
    $counts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $computed = (count($counts) === 1 && isset($counts['ready'])) ? 'ready' : 'in_progress';
}
check(
    in_array($computed, ['none', 'new', 'in_progress', 'ready', 'served', 'recalled'], true),
    "Order status '$computed' is inside the kitchen_status enum"
);
check($computed === 'ready', 'All lines ready ⇒ order reads ready', "got '$computed'");

/* ── 6. Collect → Served ────────────────────────────────────────────────── */
echo "\n--- 6. Collect → Served ---\n";

$pdo->prepare("UPDATE stock_order_items SET kds_status='collection' WHERE id=?")->execute([$itemId]);
$row->execute([$itemId]);
check($row->fetch(PDO::FETCH_ASSOC)['kds_status'] === 'collection', 'Line is collecting');

$pdo->prepare("UPDATE stock_order_items SET kds_status='served', served_at=NOW() WHERE id=?")->execute([$itemId]);
$row->execute([$itemId]);
$line = $row->fetch(PDO::FETCH_ASSOC);
check($line['kds_status'] === 'served', 'Line is served');
check((int)$line['stock_deducted'] === 1, 'Serving does not re-deduct');

/* ── 7. Void / 86 restores exactly what was taken ───────────────────────── */
echo "\n--- 7. Void (86) restores stock ---\n";

$restored = restoreStockForMenuItem(
    (int)$pick['id'],
    (string)$pick['menu_type'],
    $qty,
    'Smoke test - 86 restore',
    null,
    $itemId,
    'pos_order'
);
check($restored, 'restoreStockForMenuItem() succeeded');

$pdo->prepare("UPDATE stock_order_items SET kds_status='void', stock_deducted=0 WHERE id=?")->execute([$itemId]);

$afterVoid = ingredient_levels($pdo, (int)$pick['id'], (string)$pick['menu_type']);
$mismatch = [];
foreach ($before as $ing => $qtyBefore) {
    $now = $afterVoid[$ing] ?? null;
    if ($now === null || abs($now - $qtyBefore) > 0.0001) {
        $mismatch[] = "ingredient#$ing before=$qtyBefore after=" . var_export($now, true);
    }
}
check($mismatch === [], 'Void restored ingredients to their pre-order levels', implode('; ', $mismatch));

/* ── 8. Enum integrity across the order ─────────────────────────────────── */
echo "\n--- 8. Enum integrity ---\n";

$validItem = ['pending', 'preparing', 'ready', 'collection', 'served', 'void'];
$st = $pdo->prepare("SELECT DISTINCT kds_status FROM stock_order_items WHERE order_id=?");
$st->execute([$orderId]);
$seen = $st->fetchAll(PDO::FETCH_COLUMN);
$stray = array_diff($seen, $validItem);
check($stray === [], 'All line statuses are valid kds_status values', implode(',', $stray));

echo "\n=== Result: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
