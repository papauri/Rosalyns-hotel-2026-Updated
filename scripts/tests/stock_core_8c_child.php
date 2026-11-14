<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process page scenarios for scripts/tests/stock_core_8c.php (run by page_harness.php: one outer transaction
 * that can never commit, rolled back at the end). Builds its ZZ8B fixtures inside that transaction, then runs the
 * REAL admin page with the posted fields. Prints @@FLASH@@[error, message] (the pages report through the session).
 * Fixture ids are passed in by the parent (fixed, unused ids) so two runs post exactly the same form.
 */
if (sha1(DB_NAME) !== '3b8976ab1ff31dab31141c7344d737ecc8725d41') { fwrite(STDERR, "child refused: not the Liwonde database\n"); exit(9); }
$sc = (string)($_POST['scenario'] ?? '');
$csrfTok = (string)($_POST['csrf_token'] ?? '');
$run = (string)($_POST['run'] ?? 'X');
$fx = json_decode((string)($_POST['fx'] ?? '{}'), true) ?: [];
require_once $root . '/admin/includes/procurement-schema.php';

register_shutdown_function(static function () {
    echo "\n@@FLASH@@" . json_encode([$_SESSION['stock_err'] ?? '', $_SESSION['stock_msg'] ?? '']) . "\n";
});

$n = static fn(string $s) => 'ZZ8B ' . $run . ' ' . $s;
$mkIng = static function (string $name, float $qty, float $avg = 10.0, int $archived = 0, ?int $id = null) use ($pdo): int {
    $pdo->prepare("INSERT INTO stock_ingredients (" . ($id ? 'id, ' : '') . "name, category, unit, current_quantity, cost_per_unit, is_archived) VALUES (" . ($id ? '?, ' : '') . "?, 'ZZ8B test', 'each', ?, ?, ?)")
        ->execute(array_merge($id ? [$id] : [], [$name, $qty, $avg, $archived]));
    return $id ?: (int)$pdo->lastInsertId();
};
$mkBatch = static function (int $ing, float $recv, float $rem, float $cost, string $status = 'active') use ($pdo, $run): int {
    static $seq = 0;
    $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status) VALUES (?, ?, ?, ?, ?, CURDATE(), ?)")
        ->execute([$ing, 'ZZ8B-' . $run . '-' . (++$seq), $recv, $rem, $cost, $status]);
    return (int)$pdo->lastInsertId();
};
$mkMenu = static function (string $name) use ($pdo): int {
    $cat = (int)$pdo->query("SELECT id FROM menu_categories WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO menu_items (category_id, item_name, price) VALUES (?, ?, 100)")->execute([$cat, $name]);
    return (int)$pdo->lastInsertId();
};
$runPage = static function (string $page, array $post, array $get = []) use ($root, $csrfTok): void {
    global $pdo;
    $_POST = $post + ['csrf_token' => $csrfTok];
    $_GET = $get;
    $_REQUEST = $_POST + $_GET;
    chdir($root . '/admin');
    require $root . '/admin/' . $page;
};

if ($sc === 's2_archive_in_recipe') {
    $ing = $mkIng($n('inrecipe'), 0);
    $mi = $mkMenu($n('Dish'));
    $pdo->prepare("INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe) VALUES (?, 'zz8b', 1)")->execute([$mi]);
    $pdo->prepare("INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent) VALUES (?, ?, 1, 100)")->execute([(int)$pdo->lastInsertId(), $ing]);
    $runPage('stock-ingredients.php', ['action' => 'archive', 'id' => (string)$ing]);
} elseif ($sc === 's2_archive_free') {
    $ing = $mkIng($n('free'), 0);
    $runPage('stock-ingredients.php', ['action' => 'archive', 'id' => (string)$ing]);
} elseif ($sc === 's2_recipe_archived') {
    $ing = $mkIng($n('oldstock'), 0, 10.0, 1);
    $mi = $mkMenu($n('Dish'));
    $runPage('stock-recipes.php', ['action' => 'save_recipe', 'menu_item_id' => (string)$mi, 'menu_type' => 'food', 'portions_per_recipe' => '1',
        'ingredient_id' => [(string)$ing], 'quantity_per_portion' => ['1'], 'yield_percent' => ['100']]);
} elseif ($sc === 's2_recipe_live') {
    $ing = $mkIng($n('live'), 0);
    $mi = $mkMenu($n('Dish'));
    $runPage('stock-recipes.php', ['action' => 'save_recipe', 'menu_item_id' => (string)$mi, 'menu_type' => 'food', 'portions_per_recipe' => '1',
        'ingredient_id' => [(string)$ing], 'quantity_per_portion' => ['1'], 'yield_percent' => ['100']]);
} elseif ($sc === 's2_receive_archived') {
    $ing = $mkIng($n('archrecv'), 0, 10.0, 1);
    $items = [['ingredient_id' => $ing, 'quantity' => 5, 'cost_per_unit' => 10, 'expiry_date' => '', 'name' => 'archrecv']];
    $runPage('stock-barcode-receive.php', ['items' => json_encode($items), 'supplier' => '', 'received_date' => ''], ['ajax' => 'receive_batch']);
} elseif ($sc === 's2_lookup_archived') {
    $ing = $mkIng($n('archscan'), 0, 10.0, 1);
    $pdo->prepare("INSERT INTO stock_ingredient_barcodes (barcode, ingredient_id, pack_size) VALUES (?, ?, 1)")->execute([(string)$fx['barcode'], $ing]);
    $runPage('stock-barcode-receive.php', ['barcode' => (string)$fx['barcode']], ['ajax' => 'lookup_barcode']);
} elseif ($sc === 's8_discard_depleted') {
    $ing = $mkIng($n('discard'), 0);
    $b = $mkBatch($ing, 5, 0, 10, 'depleted');
    $runPage('stock-ingredients.php', ['action' => 'discard_batch', 'batch_id' => (string)$b, 'reason' => 'Found spoiled in store', 'status' => 'wasted']);
} elseif ($sc === 's8_discard_active') {
    $ing = $mkIng($n('discard'), 5);
    $b = $mkBatch($ing, 5, 5, 10, 'active');
    $runPage('stock-ingredients.php', ['action' => 'discard_batch', 'batch_id' => (string)$b, 'reason' => 'Found spoiled in store', 'status' => 'wasted']);
} elseif ($sc === 's9_before_count' || $sc === 's9_same_day') {
    $ing = $mkIng($n('counted'), 10);
    $mkBatch($ing, 10, 10, 10);
    $countDate = date('Y-m-d', strtotime('-2 days'));
    $pdo->prepare("INSERT INTO stock_counts (reference, count_date, status, approved_at) VALUES (?, ?, 'approved', NOW())")->execute(['ZZ8B' . $run, $countDate]);
    $cid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, counted_at) VALUES (?, ?, 10, 10, 0, 10, ?)")
        ->execute([$cid, $ing, $countDate . ' 10:00:00']);
    $wDate = $sc === 's9_same_day' ? $countDate : date('Y-m-d', strtotime('-4 days'));
    $runPage('stock-wastage.php', ['recorded_date' => $wDate, 'ingredient_id' => [(string)$ing], 'quantity' => ['2'], 'reason' => ['Dropped tray']]);
} elseif ($sc === 's12_stock_in') {
    $ing = $mkIng($n('stockin'), 0, 10.0, 0, (int)$fx['ing']);
    $runPage('stock-ingredients.php', ['action' => 'stock_in', 'id' => (string)$ing, 'quantity' => '5', 'cost_per_unit' => '10',
        'supplier_id' => '0', 'supplier_name' => '', 'supplier_contact' => '', 'expiry_date' => '', 'expiry_alert_days' => '7', 'notes' => '']);
} elseif ($sc === 's12_po_receive') {
    $ing = $mkIng($n('poitem'), 0, 10.0, 0, (int)$fx['ing']);
    $pdo->prepare("INSERT INTO stock_purchase_orders (id, reference, status, order_date, created_by) VALUES (?, ?, 'sent', CURDATE(), ?)")
        ->execute([(int)$fx['po'], 'ZZ8B' . $run, (int)$_SESSION['admin_user_id']]);
    $fig = rh_po_line_figures($pdo, $ing, 'unit', 10, 10, 'unit');
    $pdo->prepare("INSERT INTO stock_purchase_order_items (id, purchase_order_id, ingredient_id, description, unit, ordered_qty, unit_cost, line_total, order_in) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([(int)$fx['line'], (int)$fx['po'], $ing, 'line', 'each', $fig['ordered_qty'], $fig['unit_cost'], $fig['line_total'], 'unit']);
    $runPage('purchase-orders.php', ['action' => 'receive', 'po_id' => (string)$fx['po'], 'line_id' => [(string)$fx['line']], 'receive_qty' => ['3'],
        'receive_qty_in' => ['unit'], 'receive_cost' => ['10'], 'receive_cost_in' => ['unit'], 'over_note' => [''], 'expiry_date' => ['']]);
} else {
    fwrite(STDERR, "unknown scenario $sc\n");
}
