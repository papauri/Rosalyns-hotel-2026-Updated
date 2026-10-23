<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Purchase order "big form" test (2026-10-23): the Receive form and the Reorder page's "Create draft PO".
 * Usage (project root): php scripts/tests/po_bigform.php
 *
 * The receive form posted ~8 fields per line (passes PHP's max_input_vars at ~125 lines), the Reorder page 4 hidden
 * fields per item. Both now travel as a few JSON chunks (lines_json[]). Every scenario runs the REAL pages inside
 * scripts/tests/page_harness.php (one outer transaction that can never commit, rolled back, leak-checked).
 *   - 300-line receive via lines_json: every line received, batches booked, order partly received
 *   - a line of another order / a negative number / damaged JSON / an unknown choice: refused, NOTHING saved
 *   - the old line_id[]/receive_qty[] arrays still work; a post with more fields than max_input_vars is refused
 *   - Reorder: the page itself posts 300 items in a handful of fields, and that exact post makes a 300-line draft PO
 *   - Reorder post with an item of another supplier / negative quantity / damaged JSON: refused, no order created
 *   - old ingredient_id[]/order_qty[] arrays still make an order
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

$harness = $root . '/scripts/tests/page_harness.php';
$run = strtoupper(bin2hex(random_bytes(3)));
$ingBefore = (int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZB %'");
$poBefore = (int)col($pdo, "SELECT COUNT(*) FROM stock_purchase_orders");
$supBefore = (int)col($pdo, "SELECT COUNT(*) FROM stock_suppliers WHERE name LIKE 'ZZB SUP %'");

$like = "'ZZB $run %'";
$vRecv = "SELECT COUNT(*) AS nlines, COALESCE(SUM(l.received_qty > 0),0) AS got, COALESCE(SUM(l.received_qty),0) AS total FROM stock_purchase_order_items l INNER JOIN stock_ingredients i ON i.id = l.ingredient_id WHERE i.name LIKE $like AND l.description LIKE 'ZZB line %'";
$vBatch = "SELECT COUNT(*) AS n FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE i.name LIKE $like";
$vPoStatus = "SELECT status FROM stock_purchase_orders WHERE reference = 'ZZB{$run}A'";
$vNewPo = "SELECT COUNT(*) AS pos, COALESCE(SUM((SELECT COUNT(*) FROM stock_purchase_order_items x WHERE x.purchase_order_id = p.id)),0) AS nlines FROM stock_purchase_orders p WHERE p.supplier_id IN (SELECT id FROM stock_suppliers WHERE name = 'ZZB SUP A $run')";

function pb_run(string $scenario, string $run, array $verify, array $grep = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => ['scenario' => $scenario, 'run' => $run], 'verify' => $verify, 'grep' => $grep]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/po_bigform_child.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr($out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $res = $x; } }
    $res['flash'] = ['', ''];
    if (preg_match('/@@FLASH@@(.*)$/m', $out, $m)) { $f = json_decode($m[1], true); if (is_array($f)) { $res['flash'] = $f; } }
    $res['page'] = null;
    if (preg_match('/@@PAGE@@(.*)$/m', $out, $m)) { $res['page'] = json_decode($m[1], true); }
    return $res;
}
function pb_clean(array $r, string $label): void {
    ok("$label: ran and rolled back, nothing leaked", !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']), json_encode($r['raw'] ?? $r));
}

// 1. 300-line receive through lines_json
$r = pb_run('receive_json', $run, [$vRecv, $vBatch, $vPoStatus]);
pb_clean($r, 'Receive 300 lines (packed)');
$v = $r['verify'][0][0] ?? [];
ok('Receive 300 lines: no error shown', ($r['flash'][0] ?? 'x') === '' && stripos((string)($r['flash'][1] ?? ''), 'received') !== false, json_encode($r['flash']));
ok('Receive 300 lines: every line was received (300 of 300, 4 each = 1200)', (int)($v['nlines'] ?? 0) === 300 && (int)($v['got'] ?? 0) === 300 && abs((float)($v['total'] ?? 0) - 1200) < 0.001, json_encode($v));
ok('Receive 300 lines: a stock batch was booked for every item', (int)($r['verify'][1][0]['n'] ?? 0) === 300, json_encode($r['verify'][1] ?? null));
ok('Receive 300 lines: the order is "partial" (4 of 10 received)', (string)($r['verify'][2][0]['status'] ?? '') === 'partial', json_encode($r['verify'][2] ?? null));

// 2. Refused posts save nothing
foreach ([
    'receive_json_badid'    => ['A line of another order is refused', 'not part of this purchase order'],
    'receive_json_negative' => ['A negative quantity is refused', 'not negative'],
    'receive_json_garbage'  => ['Damaged JSON is refused', 'could not be read'],
    'receive_json_badchoice' => ['An unknown packs/units choice is refused', 'does not accept'],
    'receive_legacy_too_many' => ['More fields than max_input_vars is refused', 'too many fields'],
] as $name => [$label, $needle]) {
    $r = pb_run($name, $run, [$vRecv, $vBatch, $vPoStatus]);
    pb_clean($r, $label);
    $v = $r['verify'][0][0] ?? [];
    ok("$label: nothing was received (not even the valid lines)", (int)($v['got'] ?? -1) === 0 && (int)($r['verify'][1][0]['n'] ?? -1) === 0 && (string)($r['verify'][2][0]['status'] ?? '') === 'sent', json_encode([$v, $r['verify'][1] ?? null, $r['verify'][2] ?? null]));
    ok("$label: plain message shown", stripos((string)($r['flash'][0] ?? ''), $needle) !== false, json_encode($r['flash']));
}

// 3. Old arrays still work
$r = pb_run('receive_legacy', $run, [$vRecv, $vBatch, $vPoStatus]);
pb_clean($r, 'Legacy receive arrays');
$v = $r['verify'][0][0] ?? [];
ok('Legacy receive arrays still work (3 lines, 1+2+3 received)', (int)($v['got'] ?? 0) === 3 && abs((float)($v['total'] ?? 0) - 6) < 0.001, json_encode([$v, $r['flash']]));

// 4. Reorder -> draft PO
$r = pb_run('reorder_page', $run, [$vNewPo]);
pb_clean($r, 'Reorder page (300 items)');
ok('Reorder page: the supplier form carries NO per-item fields and at most 7 packed fields', is_array($r['page']) && (int)$r['page']['perItem'] === 0 && (int)$r['page']['chunks'] >= 6 && (int)$r['page']['chunks'] <= 7, json_encode($r['page']));
$v = $r['verify'][0][0] ?? [];
ok('Reorder page: its own post makes one draft PO with all 300 lines', (int)($v['pos'] ?? 0) === 1 && (int)($v['nlines'] ?? 0) === 300, json_encode([$v, $r['flash']]));
$r = pb_run('reorder_json', $run, [$vNewPo]);
pb_clean($r, 'Reorder 300 items (packed)');
$v = $r['verify'][0][0] ?? [];
ok('Reorder 300 items: one draft PO with 300 lines', (int)($v['pos'] ?? 0) === 1 && (int)($v['nlines'] ?? 0) === 300, json_encode([$v, $r['flash']]));
foreach ([
    'reorder_json_wrong_supplier' => ['An item of another supplier is refused', 'no longer bought from this supplier'],
    'reorder_json_negative'       => ['A negative quantity is refused', 'greater than zero'],
    'reorder_json_garbage'        => ['Damaged JSON is refused', 'could not be read'],
] as $name => [$label, $needle]) {
    $r = pb_run($name, $run, [$vNewPo]);
    pb_clean($r, $label);
    $v = $r['verify'][0][0] ?? [];
    ok("$label: no order was created", (int)($v['pos'] ?? -1) === 0, json_encode([$v, $r['flash']]));
    ok("$label: plain message shown", stripos((string)($r['flash'][0] ?? ''), $needle) !== false, json_encode($r['flash']));
}
$r = pb_run('reorder_legacy', $run, [$vNewPo]);
pb_clean($r, 'Legacy reorder arrays');
$v = $r['verify'][0][0] ?? [];
ok('Legacy reorder arrays still make an order (3 lines)', (int)($v['pos'] ?? 0) === 1 && (int)($v['nlines'] ?? 0) === 3, json_encode([$v, $r['flash']]));

// 5. Static: the page packs before it sends
$src = (string)file_get_contents($root . '/admin/purchase-orders.php');
ok('Page: the receive form packs lines into lines_json[] and switches the per-line inputs off while sending', strpos($src, 'onsubmit="rcPackLines(this)"') !== false && strpos($src, "h.name = 'lines_json[]'") !== false && strpos($src, 'el.disabled = true') !== false);
ok('Page: a post cut off by max_input_vars is refused', strpos($src, 'rh_po_post_truncated($_POST)') !== false);
$src2 = (string)file_get_contents($root . '/admin/stock-reorder.php');
ok('Reorder page: items travel as lines_json[] chunks, not 4 hidden fields each', strpos($src2, 'name="lines_json[]"') !== false && strpos($src2, 'name="ingredient_id[]"') === false);

// Leak check
ok('Leak: no test ingredients, suppliers or orders left behind',
    (int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZB %'") === $ingBefore
    && (int)col($pdo, "SELECT COUNT(*) FROM stock_suppliers WHERE name LIKE 'ZZB SUP %'") === $supBefore
    && (int)col($pdo, "SELECT COUNT(*) FROM stock_purchase_orders") === $poBefore);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
