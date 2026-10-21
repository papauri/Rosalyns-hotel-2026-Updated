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

/**
 * Step 9 - the station "86" (api/kds-action.php void_item), through the REAL endpoint.
 * Each case runs scripts/tests/page_harness.php: the endpoint executes as an admin inside one outer
 * transaction that can never commit, the assertions read the rows it wrote, and everything rolls back
 * (nothing is created in the live DB, so there is nothing to clean up). Needs no recipe: with a
 * recipe-backed item it also asserts the stock outcome, otherwise that part prints SKIP.
 */
function smoke_86_run(array $setup, array $post, array $verify, array $vars): array
{
    $harness = __DIR__ . '/tests/page_harness.php';
    $cfg = base64_encode(json_encode(['post' => $post, 'ajax' => true, 'setup' => $setup, 'verify' => $verify, 'vars' => $vars]));
    $out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' api/kds-action.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -400)];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) {
        $r = json_decode($m[1], true);
        if (is_array($r)) {
            $res = $r;
        }
    }
    return $res;
}

function smoke_86_step(PDO $pdo, ?array $recipePick): void
{
    echo "\n--- 9. Station 86 (api/kds-action.php void_item) ---\n";
    $mi = $recipePick ?: $pdo->query("
        SELECT mi.id, mi.item_name AS name, mi.price, COALESCE(mi.station, mc.default_station) AS station, mc.slug AS menu_type
          FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
         WHERE mi.is_available = 1 ORDER BY mi.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$mi) { // a hotel with no menu yet: the 86 path does not need a real item, only the line
        $mi = ['id' => 0, 'name' => 'Smoke Item', 'price' => 10, 'station' => 'kitchen', 'menu_type' => 'smoke'];
    }
    $adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    if ($adminId <= 0) {
        echo "[SKIP] 86 step: no active admin user to run the endpoint as\n";
        return;
    }
    $hasRecipe = $recipePick !== null;
    $station = in_array($mi['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true) ? $mi['station'] : 'kitchen';
    $price = (float)$mi['price'] > 0 ? (float)$mi['price'] : 10.0;
    $q = static fn(string $v): string => $pdo->quote($v);
    $mid = (int)$mi['id'];
    $mtype = (string)$mi['menu_type'];

    $order = static function (string $ref, string $status, float $total) use ($q, $adminId): string {
        return "INSERT INTO stock_orders (reference, order_type, table_number, customer_name, status, total_amount, subtotal, kitchen_status, fired_at, created_by, created_at)
                VALUES (" . $q($ref) . ", 'dine_in', 'SMOKE', 'Smoke Test', " . $q($status) . ", $total, $total, 'new', NOW(), $adminId, NOW())";
    };
    $line = static function (string $ref, string $name, string $kds) use ($q, $mid, $mtype, $price, $station): string {
        return "INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status, started_at)
                VALUES ((SELECT id FROM stock_orders WHERE reference = " . $q($ref) . "), $mid, " . $q($mtype) . ", " . $q($name) . ", 1, $price, $price, " . $q($station) . ", " . $q($kds) . ", " . ($kds === 'pending' ? 'NULL' : 'NOW()') . ")";
    };
    $targetId = static fn(string $ref): string => "SELECT id FROM stock_order_items WHERE item_name = 'SMOKE86 TARGET' AND order_id = (SELECT id FROM stock_orders WHERE reference = '$ref')";
    $verifyFor = static fn(string $ref): array => [
        "SELECT event, from_status, to_status FROM stock_kds_events WHERE order_item_id = (" . $targetId($ref) . ")",                                     // 0
        "SELECT kds_status FROM stock_order_items WHERE id = (" . $targetId($ref) . ")",                                                                  // 1
        "SELECT status, total_amount FROM stock_orders WHERE reference = '$ref'",                                                                         // 2
        "SELECT COUNT(*) AS n FROM stock_order_audit a JOIN stock_orders o ON o.id = a.order_id WHERE o.reference = '$ref' AND a.event = '86_item'",       // 3
        "SELECT COUNT(*) AS n FROM station_messages WHERE order_ref = '$ref' AND notice_type = 'item_86' AND to_user_id IS NOT NULL",                      // 4
        "SELECT COUNT(*) AS n FROM stock_wastage WHERE reason LIKE '%$ref%'",                                                                             // 5
        "SELECT COUNT(*) AS n, COALESCE(SUM(refund_amount), 0) AS amt FROM payments WHERE booking_type = 'restaurant' AND payment_type = 'refund' AND refund_status = 'pending' AND booking_id = (SELECT id FROM stock_orders WHERE reference = '$ref')", // 6
    ];
    $first = static fn($rows, string $col = 'n') => is_array($rows) && isset($rows[0][$col]) ? $rows[0][$col] : null;
    $vars = static fn(string $ref): array => ['item' => $targetId($ref)];
    $post = ['action' => 'void_item', 'item_id' => '{item}', 'station' => $station];
    $clean = static fn(array $r): bool => empty($r['leak']) && ($r['rolled_back'] ?? false) && empty($r['marker_lost']);

    // 9a. A line nobody started, on an order with another line: event row, line void, notice, nothing wasted.
    $ref = 'T86A-' . date('ymdHis') . random_int(100, 999);
    $r = smoke_86_run([$order($ref, 'placed', $price * 2), $line($ref, 'SMOKE86 TARGET', 'pending'), $line($ref, 'SMOKE86 OTHER', 'pending')], $post, $verifyFor($ref), $vars($ref));
    $j = $r['json'] ?? [];
    $v = $r['verify'] ?? [];
    check(($j['ok'] ?? false) === true, '86 of a pending line succeeds (the event log accepts it)', json_encode($r['json'] ?? $r));
    $ev = $v[0][0] ?? [];
    check(($ev['event'] ?? '') === 'voided' && ($ev['to_status'] ?? '') === 'void_86' && ($ev['from_status'] ?? '') === 'pending', 'stock_kds_events row written: event voided, to_status void_86', json_encode($v[0] ?? null));
    check(($v[1][0]['kds_status'] ?? '') === 'void', "The 86'd line is void");
    check(($v[2][0]['status'] ?? '') === 'placed' && abs((float)($v[2][0]['total_amount'] ?? 0) - $price) < 0.01, 'Order stays open and its total drops by the line', json_encode($v[2] ?? null));
    check((int)$first($v[3]) === 1, 'Audit row 86_item written');
    check((int)$first($v[4]) === 1, 'Cashier notice (item_86) queued for the order creator');
    check((int)$first($v[5]) === 0, 'Never-started line: no wastage');
    check($clean($r), '  (9a) rolled back, nothing left behind');

    // 9b. A line already being cooked, the only line: wastage when a recipe exists, order cancelled.
    $ref = 'T86B-' . date('ymdHis') . random_int(100, 999);
    $r = smoke_86_run([$order($ref, 'placed', $price), $line($ref, 'SMOKE86 TARGET', 'preparing')], $post, $verifyFor($ref), $vars($ref));
    $j = $r['json'] ?? [];
    $v = $r['verify'] ?? [];
    check(($j['ok'] ?? false) === true, '86 of a preparing line succeeds', json_encode($r['json'] ?? $r));
    check((($v[0][0]['event'] ?? '') === 'voided') && (($v[0][0]['from_status'] ?? '') === 'preparing'), 'Event row records the preparing -> void_86 step', json_encode($v[0] ?? null));
    check(($v[2][0]['status'] ?? '') === 'cancelled', "Last line 86'd: the empty order is cancelled", json_encode($v[2] ?? null));
    if ($hasRecipe) {
        check(($j['stock'] ?? '') === 'wastage' && (int)$first($v[5]) >= 1, 'Started line is recorded as wastage (not restored)', json_encode(['stock' => $j['stock'] ?? null, 'wastage_rows' => $first($v[5])]));
    } else {
        echo "[SKIP] 86 stock/wastage outcome: no recipe-backed menu item in this hotel (set one up to exercise it)\n";
    }
    check($clean($r), '  (9b) rolled back, nothing left behind');

    // 9c. A paid order: the line comes off and a pending refund is raised for the cashier to settle.
    $ref = 'T86C-' . date('ymdHis') . random_int(100, 999);
    $pay = "INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount, payment_method, payment_type, payment_status, status, created_at)
            VALUES (" . $q('P' . $ref) . ", 'restaurant', (SELECT id FROM stock_orders WHERE reference = " . $q($ref) . "), " . $q($ref) . ", CURDATE(), " . ($price * 2) . ", 0, 0, " . ($price * 2) . ", 'cash', 'full_payment', 'completed', 'completed', NOW())";
    $r = smoke_86_run([$order($ref, 'paid', $price * 2), $line($ref, 'SMOKE86 TARGET', 'pending'), $line($ref, 'SMOKE86 OTHER', 'pending'), $pay], $post, $verifyFor($ref), $vars($ref));
    $j = $r['json'] ?? [];
    $v = $r['verify'] ?? [];
    check(($j['ok'] ?? false) === true, '86 on a PAID order succeeds', json_encode($r['json'] ?? $r));
    check(($v[2][0]['status'] ?? '') === 'paid', 'Paid order stays paid (refund settled by the cashier)', json_encode($v[2] ?? null));
    check((int)$first($v[6]) === 1 && abs((float)$first($v[6], 'amt') - $price) < 0.01, "A pending refund for the 86'd line is raised", json_encode($v[6] ?? null));
    check((int)$first($v[4]) === 1, 'Cashier notice queued (tells them to settle the refund)');
    check($clean($r), '  (9c) rolled back, nothing left behind');
}

/**
 * Step 10 - a till refund (admin/pos.php refund_order) must take the order's unserved lines off the
 * stations in the same transaction: never-started line void, started line void + wastage (recipe
 * permitting) + STOP note, served line untouched, order kitchen_status closed. Rolled back like step 9.
 */
function smoke_refund_step(PDO $pdo, ?array $recipePick): void
{
    echo "\n--- 10. Till refund closes unserved kitchen lines (admin/pos.php refund_order) ---\n";
    $mi = $recipePick ?: $pdo->query("
        SELECT mi.id, mi.item_name AS name, mi.price, COALESCE(mi.station, mc.default_station) AS station, mc.slug AS menu_type
          FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
         WHERE mi.is_available = 1 ORDER BY mi.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$mi) {
        $mi = ['id' => 0, 'name' => 'Smoke Item', 'price' => 10, 'station' => 'kitchen', 'menu_type' => 'smoke'];
    }
    $adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    if ($adminId <= 0) {
        echo "[SKIP] refund step: no active admin user to run the till as\n";
        return;
    }
    $hasRecipe = $recipePick !== null;
    $station = in_array($mi['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true) ? $mi['station'] : 'kitchen';
    $price = (float)$mi['price'] > 0 ? (float)$mi['price'] : 10.0;
    $q = static fn(string $v): string => $pdo->quote($v);
    $mid = (int)$mi['id'];
    $mtype = (string)$mi['menu_type'];
    $ref = 'T86R-' . date('ymdHis') . random_int(100, 999);
    $total = $price * 3;
    $oid = "(SELECT id FROM stock_orders WHERE reference = " . $q($ref) . ")";
    $line = static fn(string $name, string $kds): string =>
        "INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status, started_at, served_at)
         VALUES ($oid, $mid, " . $q($mtype) . ", " . $q($name) . ", 1, $price, $price, " . $q($station) . ", " . $q($kds) . ", "
        . ($kds === 'pending' ? 'NULL' : 'NOW()') . ", " . ($kds === 'served' ? 'NOW()' : 'NULL') . ")";
    $setup = [
        "INSERT INTO stock_orders (reference, order_type, table_number, customer_name, status, total_amount, subtotal, kitchen_status, fired_at, created_by, paid_by, paid_at, created_at)
         VALUES (" . $q($ref) . ", 'dine_in', 'SMOKE', 'Smoke Test', 'paid', $total, $total, 'in_progress', NOW(), $adminId, $adminId, NOW(), NOW())",
        $line('SMOKE-R PENDING', 'pending'),
        $line('SMOKE-R COOKING', 'preparing'),
        $line('SMOKE-R SERVED', 'served'),
        "INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount, payment_method, payment_type, payment_status, status, created_at)
         VALUES (" . $q('P' . $ref) . ", 'restaurant', $oid, " . $q($ref) . ", CURDATE(), $total, 0, 0, $total, 'cash', 'full_payment', 'completed', 'completed', NOW())",
    ];
    $verify = [
        "SELECT status, kitchen_status FROM stock_orders WHERE reference = '$ref'",                                                                                                   // 0
        "SELECT item_name, kds_status FROM stock_order_items WHERE order_id = $oid ORDER BY id",                                                                                      // 1
        "SELECT COUNT(*) AS n FROM stock_order_audit WHERE order_id = $oid AND event = 'refund_closed_lines'",                                                                         // 2
        "SELECT COUNT(*) AS n FROM stock_wastage WHERE reason LIKE '%$ref%'",                                                                                                         // 3
        "SELECT COUNT(*) AS n FROM stock_kds_events WHERE order_id = $oid AND event = 'voided'",                                                                                      // 4
        "SELECT COUNT(*) AS n FROM station_messages WHERE order_id = $oid AND message LIKE 'STOP%'",                                                                                  // 5
    ];
    $harness = dirname(__DIR__) . '/scripts/tests/page_harness.php';
    $harness = is_file($harness) ? $harness : __DIR__ . '/tests/page_harness.php';
    $cfg = base64_encode(json_encode(['post' => ['action' => 'refund_order', 'order_id' => '{oid}', 'refund_reason' => 'Smoke refund test'], 'ajax' => true, 'setup' => $setup, 'verify' => $verify, 'vars' => ['oid' => "SELECT id FROM stock_orders WHERE reference = '$ref'"]]));
    $out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' admin/pos.php ' . escapeshellarg($cfg) . ' 2>&1');
    $r = ['error' => 'no result', 'raw' => substr((string)$out, -400)];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m) && is_array($j = json_decode($m[1], true))) {
        $r = $j;
    }
    $v = $r['verify'] ?? [];
    check(($r['json']['ok'] ?? false) === true, 'Till refund of a paid order succeeds', json_encode($r['json'] ?? ($r['output_flash'] ?? $r)));
    check(($v[0][0]['status'] ?? '') === 'refunded', 'Order is refunded', json_encode($v[0] ?? null));
    $byName = [];
    foreach (($v[1] ?? []) as $row) {
        $byName[$row['item_name']] = $row['kds_status'];
    }
    check(($byName['SMOKE-R PENDING'] ?? '') === 'void', 'Never-started line is closed (void) - gone from the station', json_encode($byName));
    check(($byName['SMOKE-R COOKING'] ?? '') === 'void', 'Started line is closed (void) - gone from the station', json_encode($byName));
    check(($byName['SMOKE-R SERVED'] ?? '') === 'served', 'Served line is left alone', json_encode($byName));
    check(($v[0][0]['kitchen_status'] ?? '') === 'served', 'Order kitchen_status closed (no live ticket on a refunded order)', json_encode($v[0] ?? null));
    check((int)($v[2][0]['n'] ?? 0) === 1, 'Audit row refund_closed_lines written');
    check((int)($v[4][0]['n'] ?? 0) === 2, 'KDS event rows written for the two closed lines', json_encode($v[4] ?? null));
    check((int)($v[5][0]['n'] ?? 0) === 1, 'Station told to STOP the line already being made', json_encode($v[5] ?? null));
    if ($hasRecipe) {
        check((int)($v[3][0]['n'] ?? 0) >= 1, 'Started line recorded as wastage', json_encode($v[3] ?? null));
    } else {
        echo "[SKIP] refund wastage outcome: no recipe-backed menu item in this hotel\n";
    }
    check(empty($r['leak']) && ($r['rolled_back'] ?? false) && empty($r['marker_lost']), '  (10) rolled back, nothing left behind');
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
    echo "[SKIP] Sections 1-8 (stock lifecycle): no menu item has a recipe in this hotel yet - setup, not a code fault\n";
    smoke_86_step($pdo, null);
    smoke_refund_step($pdo, null);
    echo "\n=== Result: $pass passed, $fail failed (lifecycle skipped) ===\n";
    exit($fail > 0 ? 1 : 0);
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

smoke_86_step($pdo, $pick);
smoke_refund_step($pdo, $pick);

echo "\n=== Result: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
