<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/stock_receiving.php (run by page_harness.php: one outer transaction
 * that can never commit, rolled back at the end). Builds the fixtures inside that transaction, then runs the REAL
 * admin page (purchase-orders.php receive form, stock-barcode-receive.php ajax) with the posted fields.
 * After the harness has printed its result this file prints one extra line, @@FLASH@@[error, message], because
 * the Purchase Orders page reports through the session and then redirects.
 */
$sc = (string)($_POST['scenario'] ?? '');
$csrfTok = (string)($_POST['csrf_token'] ?? '');
$run = (string)($_POST['run'] ?? 'X');
require_once $root . '/admin/includes/procurement-schema.php';

register_shutdown_function(static function () {
    echo "\n@@FLASH@@" . json_encode([$_SESSION['stock_err'] ?? '', $_SESSION['stock_msg'] ?? '']) . "\n";
});

$n = static fn(string $s) => 'ZZR ' . $run . ' ' . $s;
$mkIng = static function (string $name, ?string $packName, ?float $packSize, float $avg = 0.0) use ($pdo): int {
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, pack_name, pack_size) VALUES (?, 'ZZR test', 'bottle', 0, ?, ?, ?)")
        ->execute([$name, $avg, $packName, $packSize]);
    return (int)$pdo->lastInsertId();
};
$poNo = 0;
$mkPo = static function () use ($pdo, $run, &$poNo): int {
    $poNo++;
    $adminId = (int)$_SESSION['admin_user_id'];
    $pdo->prepare("INSERT INTO stock_purchase_orders (reference, status, order_date, created_by) VALUES (?, 'sent', CURDATE(), ?)")->execute(['ZZR' . $run . $poNo, $adminId]);
    return (int)$pdo->lastInsertId();
};
// Same figures the Purchase Orders page saves for a line.
$addLine = static function (int $po, int $ing, string $orderIn, float $qty, float $price, string $priceIn) use ($pdo): int {
    $fig = rh_po_line_figures($pdo, $ing, $orderIn, $qty, $price, $priceIn);
    $isPack = $fig['order_in'] === 'pack';
    $pdo->prepare("INSERT INTO stock_purchase_order_items (purchase_order_id, ingredient_id, description, unit, ordered_qty, unit_cost, line_total, order_in, pack_size_snapshot, pack_name_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$po, $ing, 'line', 'bottle', $fig['ordered_qty'], $fig['unit_cost'], $fig['line_total'], $fig['order_in'], $isPack ? $fig['pack_size'] : null, $isPack ? $fig['pack_name'] : null]);
    return (int)$pdo->lastInsertId();
};
$runPage = static function (string $page, array $post, array $get = []) use ($root, $csrfTok): void {
    global $pdo;   // the page files use $pdo directly, as they do at top level
    $_POST = $post + ['csrf_token' => $csrfTok];
    $_GET = $get;
    $_REQUEST = $_POST + $_GET;
    chdir($root . '/admin');
    require $root . '/admin/' . $page;
};

if ($sc === 'receive_mixed') {
    // A: 2 crates at 120,000 a crate on the order; delivered 1 crate typed in packs, invoice says 126,000 a crate.
    $a = $mkIng($n('lager'), 'crate', 24.0);
    // B: no price on the order, no average cost: staff type 40 a bottle.
    $b = $mkIng($n('noprice'), null, null);
    // C: 1 crate at 24,000 a crate on the order; delivered 12 bottles typed in units, invoice 1,100 a bottle.
    $c = $mkIng($n('crateunits'), 'crate', 24.0);
    // D: no price on the order but the item has an average of 7: a blank price falls back to it.
    $d = $mkIng($n('avgitem'), null, null, 7.0);
    $po = $mkPo();
    $la = $addLine($po, $a, 'pack', 2, 120000, 'pack');
    $lb = $addLine($po, $b, 'unit', 10, 0, 'unit');
    $lc = $addLine($po, $c, 'pack', 1, 24000, 'pack');
    $ld = $addLine($po, $d, 'unit', 10, 0, 'unit');
    $runPage('purchase-orders.php', [
        'action' => 'receive', 'po_id' => (string)$po,
        'line_id' => [$la, $lb, $lc, $ld],
        'receive_qty' => ['1', '5', '12', '3'],
        'receive_qty_in' => ['pack', 'unit', 'unit', 'unit'],
        'receive_cost' => ['126000', '40', '1100', ''],
        'receive_cost_in' => ['pack', 'unit', 'unit', 'unit'],
        'over_note' => ['', '', '', ''],
        'expiry_date' => ['2031-01-01', '', '', ''],
    ]);
} elseif ($sc === 'no_price_refused') {
    $b = $mkIng($n('noprice'), null, null);
    $po = $mkPo();
    $lb = $addLine($po, $b, 'unit', 10, 0, 'unit');
    $runPage('purchase-orders.php', [
        'action' => 'receive', 'po_id' => (string)$po,
        'line_id' => [$lb], 'receive_qty' => ['5'], 'receive_qty_in' => ['unit'],
        'receive_cost' => [''], 'receive_cost_in' => ['unit'], 'over_note' => [''], 'expiry_date' => [''],
    ]);
} elseif ($sc === 'over_packs' || $sc === 'over_packs_note') {
    // 1 crate ordered, 2 crates typed in packs = 48 bottles: 24 over, needs a note.
    $a = $mkIng($n('lager'), 'crate', 24.0);
    $po = $mkPo();
    $la = $addLine($po, $a, 'pack', 1, 24000, 'pack');
    $runPage('purchase-orders.php', [
        'action' => 'receive', 'po_id' => (string)$po,
        'line_id' => [$la], 'receive_qty' => ['2'], 'receive_qty_in' => ['pack'],
        'receive_cost' => ['24000'], 'receive_cost_in' => ['pack'],
        'over_note' => [$sc === 'over_packs_note' ? 'Supplier sent a spare crate' : ''], 'expiry_date' => [''],
    ]);
} elseif ($sc === 'forged_pack') {
    // A line ordered in units: a posted "packs" choice is ignored, because the line has no pack size.
    $b = $mkIng($n('plainitem'), 'crate', 24.0);   // the item has a pack, but this line was ordered in units
    $po = $mkPo();
    $lb = $addLine($po, $b, 'unit', 10, 5, 'unit');
    $runPage('purchase-orders.php', [
        'action' => 'receive', 'po_id' => (string)$po,
        'line_id' => [$lb], 'receive_qty' => ['3'], 'receive_qty_in' => ['pack'],
        'receive_cost' => ['5'], 'receive_cost_in' => ['pack'], 'over_note' => [''], 'expiry_date' => [''],
        'pack_size' => '1000',
    ]);
} elseif ($sc === 'stale_form') {
    // A receive form opened before packs existed posts no crates/bottles choice: it means bottles, as it always did.
    $a = $mkIng($n('lager'), 'crate', 24.0);
    $po = $mkPo();
    $la = $addLine($po, $a, 'pack', 2, 120000, 'pack');
    $runPage('purchase-orders.php', [
        'action' => 'receive', 'po_id' => (string)$po,
        'line_id' => [$la], 'receive_qty' => ['3'], 'receive_cost' => ['5000'], 'over_note' => [''], 'expiry_date' => [''],
    ]);
} elseif ($sc === 'bc_expiry' || $sc === 'bc_expiry_past' || $sc === 'bc_expiry_bad' || $sc === 'bc_no_expiry') {
    $a = $mkIng($n('scanned'), 'crate', 24.0);
    $exp = ['bc_expiry' => '2031-06-30', 'bc_expiry_past' => '2020-01-01', 'bc_expiry_bad' => '31/06/2031', 'bc_no_expiry' => ''][$sc];
    $items = [['ingredient_id' => $a, 'quantity' => 24, 'cost_per_unit' => 50, 'expiry_date' => $exp, 'name' => 'scanned']];
    $runPage('stock-barcode-receive.php', ['items' => json_encode($items), 'supplier' => '', 'received_date' => ''], ['ajax' => 'receive_batch']);
} elseif ($sc === 'bc_register_default' || $sc === 'bc_register_typed') {
    $a = $mkIng($n('scanned'), 'crate', 24.0);
    $bc = '9' . str_pad((string)random_int(0, 99999999999), 11, '0', STR_PAD_LEFT);
    $post = ['barcode' => $bc, 'ingredient_id' => (string)$a, 'pack_size' => '', 'pack_label' => ''];
    if ($sc === 'bc_register_typed') { $post['pack_size'] = '12'; $post['pack_label'] = 'half crate'; }
    $runPage('stock-barcode-receive.php', $post, ['ajax' => 'register_barcode']);
}
