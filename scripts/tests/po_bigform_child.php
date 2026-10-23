<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/po_bigform.php (run by page_harness.php: one outer transaction that can
 * never commit, rolled back at the end). Fixtures are built inside it, then the REAL admin pages run:
 * purchase-orders.php (receive; create_from_reorder) and stock-reorder.php (GET, to read back what its form posts).
 * After the harness result this file prints @@FLASH@@[error, message] (the PO page reports via the session).
 */
$sc = (string)($_POST['scenario'] ?? '');
$csrfTok = (string)($_POST['csrf_token'] ?? '');
$run = (string)($_POST['run'] ?? 'X');
$N = 300;
require_once $root . '/admin/includes/procurement-schema.php';

register_shutdown_function(static function () {
    echo "\n@@FLASH@@" . json_encode([$_SESSION['stock_err'] ?? '', $_SESSION['stock_msg'] ?? '']) . "\n";
});

$adminId = (int)$_SESSION['admin_user_id'];
$mkSupplier = static function (string $tag) use ($pdo, $run): int {
    $pdo->prepare("INSERT INTO stock_suppliers (name, phone, is_active) VALUES (?, '', 1)")->execute(['ZZB SUP ' . $tag . ' ' . $run]);
    return (int)$pdo->lastInsertId();
};
$mkIng = static function (string $name, int $supplier = 0, bool $reorder = false) use ($pdo): int {
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, preferred_supplier_id, reorder_point, par_level) VALUES (?, 'ZZB test', 'kg', 0, 5, ?, ?, ?)")
        ->execute([$name, $supplier ?: null, $reorder ? 10 : 0, $reorder ? 20 : 0]);
    return (int)$pdo->lastInsertId();
};
$mkPo = static function (string $tag, int $supplier = 0) use ($pdo, $run, $adminId): int {
    $pdo->prepare("INSERT INTO stock_purchase_orders (reference, supplier_id, status, order_date, created_by) VALUES (?, ?, 'sent', CURDATE(), ?)")->execute(['ZZB' . $run . $tag, $supplier ?: null, $adminId]);
    return (int)$pdo->lastInsertId();
};
$addLine = static function (int $po, int $ing, string $desc = 'line') use ($pdo): int {
    $pdo->prepare("INSERT INTO stock_purchase_order_items (purchase_order_id, ingredient_id, description, unit, ordered_qty, unit_cost, line_total, order_in) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$po, $ing, $desc, 'kg', 10, 5, 50, 'unit']);
    return (int)$pdo->lastInsertId();
};
$runPage = static function (string $page, array $post, array $get = []) use ($root, $csrfTok): void {
    global $pdo;
    $_POST = $post + ['csrf_token' => $csrfTok];
    $_GET = $get;
    $_REQUEST = $_POST + $_GET;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    chdir($root . '/admin');
    require $root . '/admin/' . $page;
};
$pack = static function (array $rows): array {
    $c = [];
    foreach (array_chunk($rows, 50) as $part) { $c[] = json_encode($part); }
    return $c;
};

if ($sc === 'view_receive') {
    /* Render the real receive form for a 300-line order (run with cfg.dump_html to look at it in a browser). */
    $po = $mkPo('V');
    for ($i = 0; $i < $N; $i++) { $addLine($po, $mkIng('ZZB ' . $run . ' ' . $i), 'ZZB line ' . $i); }
    $_POST = []; $_GET = ['id' => (string)$po]; $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    chdir($root . '/admin');
    require $root . '/admin/purchase-orders.php';
} elseif ($sc === 'view_reorder') {
    $sup = $mkSupplier('A');
    for ($i = 0; $i < $N; $i++) { $mkIng('ZZB ' . $run . ' ' . $i, $sup, true); }
    $_POST = []; $_GET = []; $_REQUEST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    chdir($root . '/admin');
    require $root . '/admin/stock-reorder.php';
} elseif (strpos($sc, 'receive_') === 0) {
    $po = $mkPo('A');
    $ids = []; $lines = [];
    $cnt = $sc === 'receive_legacy' ? 3 : $N;
    for ($i = 0; $i < $cnt; $i++) {
        $ing = $mkIng('ZZB ' . $run . ' ' . $i);
        $ids[] = $ing;
        $lines[] = $addLine($po, $ing, 'ZZB line ' . $i);
    }
    $post = ['action' => 'receive', 'po_id' => (string)$po];
    $rowOf = static fn(int $lid, string $q = '4') => ['id' => $lid, 'q' => $q, 'qi' => 'unit', 'c' => '5', 'ci' => 'unit', 'e' => '', 'n' => ''];
    if ($sc === 'receive_json') {
        $rows = [];
        foreach ($lines as $lid) { $rows[] = $rowOf($lid); }
        $post['lines_json'] = $pack($rows);
    } elseif ($sc === 'receive_json_badid') {
        $po2 = $mkPo('B');
        $other = $addLine($po2, $ids[0], 'ZZB other PO line');
        $post['lines_json'] = $pack([$rowOf($lines[0]), $rowOf($other)]);
    } elseif ($sc === 'receive_json_negative') {
        $post['lines_json'] = $pack([$rowOf($lines[0]), $rowOf($lines[1], '-2')]);
    } elseif ($sc === 'receive_json_garbage') {
        $post['lines_json'] = ['[{"id": 1, "q"'];
    } elseif ($sc === 'receive_json_badchoice') {
        $r = $rowOf($lines[0]); $r['qi'] = 'crate';
        $post['lines_json'] = $pack([$r]);
    } elseif ($sc === 'receive_legacy') {
        foreach ($lines as $k => $lid) {
            $post['line_id'][] = $lid; $post['receive_qty'][] = (string)($k + 1); $post['receive_cost'][] = '5';
            $post['receive_qty_in'][] = 'unit'; $post['receive_cost_in'][] = 'unit'; $post['over_note'][] = ''; $post['expiry_date'][] = '';
        }
    } elseif ($sc === 'receive_legacy_too_many') {
        foreach ($lines as $lid) {
            $post['line_id'][] = $lid; $post['receive_qty'][] = '4'; $post['receive_cost'][] = '5';
            $post['receive_qty_in'][] = 'unit'; $post['receive_cost_in'][] = 'unit'; $post['over_note'][] = ''; $post['expiry_date'][] = '';
        }
    }
    $runPage('purchase-orders.php', $post);
} elseif (strpos($sc, 'reorder_') === 0) {
    $sup = $mkSupplier('A');
    $sup2 = $mkSupplier('B');
    $cnt = $sc === 'reorder_legacy' ? 3 : $N;
    $ids = [];
    for ($i = 0; $i < $cnt; $i++) { $ids[] = $mkIng('ZZB ' . $run . ' ' . $i, $sup, true); }
    $post = ['action' => 'create_from_reorder', 'supplier_id' => (string)$sup];
    if ($sc === 'reorder_page') {
        /* Render the real Reorder page, read back what its "Create draft PO" form posts, and post exactly that. */
        $_POST = []; $_GET = []; $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        chdir($root . '/admin');
        ob_start();
        require $root . '/admin/stock-reorder.php';
        $html = (string)ob_get_clean();
        $perItem = 0; $packed = [];
        if (preg_match('#<h3>ZZB SUP A ' . preg_quote($run, '#') . '.*?</form>#s', $html, $m)) {
            $perItem = (int)preg_match_all('#name="(ingredient_id|order_qty|unit_cost|order_in)\[\]"#', $m[0]);
            preg_match_all('#name="lines_json\[\]" value="([^"]*)"#', $m[0], $mm);
            foreach ($mm[1] as $v) { $packed[] = html_entity_decode($v, ENT_QUOTES); }
        }
        fwrite(STDOUT, "\n@@PAGE@@" . json_encode(['perItem' => $perItem, 'chunks' => count($packed)]) . "\n");
        $post['lines_json'] = $packed;
    } elseif ($sc === 'reorder_json') {
        $rows = [];
        foreach ($ids as $k => $iid) { $rows[] = ['id' => $iid, 'in' => 'unit', 'q' => (string)(($k % 5) + 1), 'c' => '5']; }
        $post['lines_json'] = $pack($rows);
    } elseif ($sc === 'reorder_json_wrong_supplier') {
        $pdo->prepare("UPDATE stock_ingredients SET preferred_supplier_id = ? WHERE id = ?")->execute([$sup2, $ids[5]]);
        $rows = [];
        foreach ($ids as $iid) { $rows[] = ['id' => $iid, 'in' => 'unit', 'q' => '2', 'c' => '5']; }
        $post['lines_json'] = $pack($rows);
    } elseif ($sc === 'reorder_json_negative') {
        $post['lines_json'] = $pack([['id' => $ids[0], 'in' => 'unit', 'q' => '2', 'c' => '5'], ['id' => $ids[1], 'in' => 'unit', 'q' => '-1', 'c' => '5']]);
    } elseif ($sc === 'reorder_json_garbage') {
        $post['lines_json'] = ['not json'];
    } elseif ($sc === 'reorder_legacy') {
        foreach ($ids as $iid) { $post['ingredient_id'][] = (string)$iid; $post['order_in'][] = 'unit'; $post['order_qty'][] = '3'; $post['unit_cost'][] = '5'; }
    }
    $runPage('purchase-orders.php', $post);
}
