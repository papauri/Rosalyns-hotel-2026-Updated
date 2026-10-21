<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/backcost.php (run by page_harness.php: one outer transaction that can
 * never commit, rolled back at the end). Builds an ingredient that was sold below zero (3 sold with nothing in
 * stock, booked at zero cost on one order), then settles that deficit through a REAL admin page:
 *   do=count  : stock-count.php approves a count line that finds 2 (variance +5 from -3) at 200 a unit
 *   do=adjust : stock-ingredients.php manual "adjust add" of 5 (ingredient average cost 200, or 0 when cost=none)
 * The parent reads the order's cost with verify SQL.
 */
$run = (string)($_POST['run'] ?? 'X');
$do = (string)($_POST['do'] ?? 'count');
$costNone = (string)($_POST['cost'] ?? 'yes') === 'none';
$csrfTok = (string)($_POST['csrf_token'] ?? '');
$me = (int)($_SESSION['admin_user_id'] ?? 0);

$avg = $costNone ? 0 : 200;
$pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'Pantry', 'kg', -3, ?)")->execute(['ZZB ' . $run . ' Item', $avg]);
$ingId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO stock_orders (reference, order_type, table_number, customer_name, status, total_amount, subtotal, kitchen_status, fired_at, created_by, created_at)
               VALUES (?, 'dine_in', 'ZZB', 'Backcost page test', 'paid', 100, 100, 'new', NOW(), ?, NOW())")->execute(['ZZB' . $run . 'P', $me]);
$oid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status) VALUES (?, 1, 'food', 'ZZB item', 1, 100, 100, 'kitchen', 'served')")->execute([$oid]);
$lid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at) VALUES (?, -3, 'Backcost page test sale', 'pos_order', ?, 0, ?, NOW())")->execute([$ingId, $lid, $me]);

if ($do === 'count') {
    updateSetting('stock_count_second_approver', '0');
    updateSetting('stock_variance_block_pct', '10');
    updateSetting('stock_variance_min_cost', '5000');
    $hasCountedAt = true;
    try { $pdo->query("SELECT counted_at FROM stock_count_lines LIMIT 0"); } catch (Throwable $e) { $hasCountedAt = false; }
    $ref = '20991231-BC' . $run;
    $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, is_opening, created_at) VALUES (?, CURDATE(), 'spot', 'submitted', ?, 0, NOW() - INTERVAL 5 MINUTE)")->execute([$ref, $me]);
    $countId = (int)$pdo->lastInsertId();
    $sql = "INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost, reason_code" . ($hasCountedAt ? ', counted_at' : '') . ") VALUES (?,?,?,?,?,?,?,?" . ($hasCountedAt ? ", NOW() - INTERVAL 1 MINUTE" : '') . ")";
    $pdo->prepare($sql)->execute([$countId, $ingId, -3, 2, 5, 200, 1000, '']);
    $pdo->prepare("UPDATE stock_counts SET shortage_cost = 0, surplus_cost = 1000 WHERE id = ?")->execute([$countId]);
    $_POST = ['action' => 'approve_count', 'count_id' => (string)$countId, 'csrf_token' => $csrfTok];
    $_REQUEST = $_POST;
    chdir($root . '/admin');
    require $root . '/admin/stock-count.php';
} else {
    $_POST = ['action' => 'adjust', 'id' => (string)$ingId, 'direction' => 'add', 'quantity' => '5', 'reason' => 'Backcost page test add', 'csrf_token' => $csrfTok];
    $_REQUEST = $_POST;
    chdir($root . '/admin');
    require $root . '/admin/stock-ingredients.php';
}
