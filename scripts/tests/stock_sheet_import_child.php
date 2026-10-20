<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/stock_sheet_import.php (run by page_harness.php: one outer transaction
 * that can never commit, rolled back at the end). The harness signs in as an admin (the "second manager").
 *
 * scenario "apply": plan + apply a small made-up sheet, then plan again (idempotency), print JSON.
 * scenario "approve": same, then approve the opening count through the real admin/stock-count.php page as the
 *                     signed-in admin (counted_by is another admin, so the "different manager" rule is met).
 * scenario "self_approve": counted_by is the signed-in admin, so approval must be refused.
 */
$sc = (string)($_POST['scenario'] ?? '');
$csrfTok = (string)($_POST['csrf_token'] ?? '');
$run = (string)($_POST['run'] ?? 'X');
require_once $root . '/admin/includes/procurement-schema.php';
require_once $root . '/admin/includes/stock-budget-lib.php';
require_once $root . '/admin/includes/stock-sheet-import-lib.php';

$me = (int)($_SESSION['admin_user_id'] ?? 0);
$other = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND id <> " . $me . " ORDER BY id LIMIT 1")->fetchColumn();
$counter = $sc === 'self_approve' ? $me : $other;

$n = static fn(string $s) => 'ZZT ' . $run . ' ' . $s;
$row = static function (string $dept, string $section, string $name, string $batch, string $unit, string $bal, string $max, string $price, string $exp, int $r): array {
    return ['department' => $dept, 'section' => $section, 'name' => $name, 'batch' => $batch, 'unit' => $unit, 'balance' => $bal, 'max_level' => $max, 'unit_price' => $price, 'expiry' => $exp, 'sheet_row' => (string)$r];
};

// An existing ingredient (matched by name, different case and spacing): minus stock like the live ones.
$pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, notes) VALUES (?, 'Pantry', 'kg', -2, 5000, 'keep this note')")->execute([$n('Rice')]);
$riceId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'Pantry', 'kg', 0, 800)")->execute([$n('Tomato paste')]);

$rows = [
    $row('Bar', 'Imported Beers', $n('Castelite'), 'B1', 'bottles', '11', '48', '120000', '2031-01-01', 9001),
    $row('Bar', 'Imported Beers', $n('Castelite'), 'B2', 'bottles', '5', '48', '120000', '2030-01-01', 9002),
    $row('Bar', 'Whiskys', $n('Spirit with no price'), '', 'bottles', '4', '10', '', '', 9003),
    $row('Bar', 'Whiskys', $n('Vodka per bottle'), '', 'bottles', '6', '10', '30000', '', 9004),
    $row('Bar', 'Whiskys', $n('Odd crate'), '', 'bottles', '3', '48', '50000', '', 9005),
    $row('Kitchen', 'Dry goods', $n('RICE '), '', 'kg', '10', '20', '4800', '', 9006),
    $row('Kitchen', 'Dry goods', $n('Flour'), '', 'kg', '10', '20', '2000', '', 9007),
    $row('Kitchen', 'Dry goods', $n('Tomato paste'), '', 'tins', '6', '12', '1500', '', 9008),
    $row('77 Mug', 'Utensils', $n('Flour'), '', 'bottles', '2', '5', '', '', 9009),
    $row('Kitchen', '', '', '', 'kg', '1', '1', '1', '', 9010),
    $row('Stationery', '', $n('Pens (Box)'), '', '', '8', '20', '', '', 9011),
    $row('Bar', 'Minerals', $n('Fizz'), '', 'bottles', '6', '10', '15000', '', 9012),
    $row('Kitchen', 'Others', $n('Charcoal'), '', '', '2', '1', '30000', '', 9013),
    $row('Bar', 'Whiskys', $n('Pricey whisky'), '', 'bottles', '2', '5', '300000', '', 9014),
];
$qty = [
    9001 => ['name' => $n('Castelite'), 'qty' => 2.0, 'formula' => false],
    9002 => ['name' => $n('Castelite'), 'qty' => 0.0, 'formula' => false],
    9004 => ['name' => $n('Vodka per bottle'), 'qty' => 4.0, 'formula' => true],
    9005 => ['name' => $n('Odd crate'), 'qty' => 7.0, 'formula' => false],
    9012 => ['name' => $n('Fizz'), 'qty' => 4.0, 'formula' => true],
    9014 => ['name' => $n('Pricey whisky'), 'qty' => 3.0, 'formula' => true],
];
$opts = ['budget_month' => '2031-05', 'budgets' => ['Bar' => 3387814.0, 'Kitchen' => 4730287.5, '77 Mug' => 0.0]];

$countsBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_counts WHERE notes LIKE 'Stock sheet import%' AND status = 'submitted'")->fetchColumn();
$plan = rh_ssi_plan($pdo, $rows, $qty, [], $opts);
$items = $plan['items'];
$out = ['plan' => [
    'skipped' => count($plan['skipped']),
    'castelite' => ['balance' => $items[rh_ssi_key($n('Castelite'))]['balance'], 'pack' => $items[rh_ssi_key($n('Castelite'))]['pack'], 'cost' => $items[rh_ssi_key($n('Castelite'))]['cost'], 'batches' => count($items[rh_ssi_key($n('Castelite'))]['batches'])],
    'noprice_in_count' => $items[rh_ssi_key($n('Spirit with no price'))]['in_count'],
    'vodka_cost' => $items[rh_ssi_key($n('Vodka per bottle'))]['cost'], 'vodka_status' => $items[rh_ssi_key($n('Vodka per bottle'))]['cost_status'],
    'odd_status' => $items[rh_ssi_key($n('Odd crate'))]['cost_status'], 'odd_in_count' => $items[rh_ssi_key($n('Odd crate'))]['in_count'],
    'rice_action' => $items[rh_ssi_key($n('Rice'))]['action'], 'rice_changes' => array_keys($items[rh_ssi_key($n('Rice'))]['changes']),
    'paste_action' => $items[rh_ssi_key($n('Tomato paste'))]['action'],
    'flour77' => isset($items[rh_ssi_key($n('Flour (77 Mug)'))]),
    'dups' => count($plan['duplicates']),
    'fizz' => ['cost' => $items[rh_ssi_key($n('Fizz'))]['cost'], 'notes' => $items[rh_ssi_key($n('Fizz'))]['notes']],
    'pricey' => ['cost' => $items[rh_ssi_key($n('Pricey whisky'))]['cost']],
    'spirit_notes' => $items[rh_ssi_key($n('Vodka per bottle'))]['notes'],
    'charcoal' => ['unit' => $items[rh_ssi_key($n('Charcoal'))]['unit'], 'notes' => $items[rh_ssi_key($n('Charcoal'))]['notes']],
    'noprice_notes' => $items[rh_ssi_key($n('Spirit with no price'))]['notes'],
    'paste_notes' => $items[rh_ssi_key($n('Tomato paste'))]['notes'],
]];

$res = rh_ssi_apply($pdo, $plan, $counter);
$out['apply'] = $res;
$out['count'] = $pdo->query("SELECT id, status, is_opening, counted_by FROM stock_counts WHERE id = " . (int)$res['count_id'])->fetch();
$out['rice_after_apply'] = $pdo->query("SELECT category, unit, current_quantity, cost_per_unit, par_level, reorder_point, notes FROM stock_ingredients WHERE id = " . $riceId)->fetch();
$out['qty_before_approval'] = (float)$pdo->query("SELECT COALESCE(SUM(current_quantity),0) FROM stock_ingredients WHERE name LIKE 'ZZT " . $run . " %' AND id <> " . $riceId)->fetchColumn();
$out['budget_lines'] = $pdo->query("SELECT category, amount, control FROM stock_budgets WHERE budget_month = '2031-05-01' ORDER BY category")->fetchAll();

// Second run changes nothing
$plan2 = rh_ssi_plan($pdo, $rows, $qty, [], $opts);
$res2 = rh_ssi_apply($pdo, $plan2, $counter);
$out['second_run'] = ['created' => $res2['created'], 'updated' => $res2['updated'], 'count_id' => $res2['count_id'], 'budget_changed' => $res2['budget_changed']];
$out['counts_total'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_counts WHERE notes LIKE 'Stock sheet import%' AND status = 'submitted'")->fetchColumn() - $countsBefore;
$out['report_ok'] = strlen(rh_ssi_report($plan)) > 500;
echo json_encode(['scenario' => $sc] + $out) . "\n";

if ($sc === 'approve' || $sc === 'self_approve') {
    $_POST = ['action' => 'approve_count', 'count_id' => (string)$res['count_id'], 'csrf_token' => $csrfTok];
    $_REQUEST = $_POST;
    chdir($root . '/admin');
    require $root . '/admin/stock-count.php';
}
