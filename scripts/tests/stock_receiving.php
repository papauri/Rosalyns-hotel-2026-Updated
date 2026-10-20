<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Stock receiving test: the Purchase Orders receive form and the barcode Receive Stock screen.
 * Usage (project root): php scripts/tests/stock_receiving.php
 *
 * Every scenario runs the REAL admin page inside scripts/tests/page_harness.php (one outer transaction that can
 * never commit, rolled back at the end, with a leak check from a second connection). Fixtures are made inside it.
 *
 * Covers: a pack line received in packs with an invoice price per pack that differs from the order price (the
 * batch uses the invoice price, the order line is left alone); a line with no price on the order and no average
 * cost received with a typed price; the same line refused when no price is typed; a blank price falling back to
 * the item's average; a pack line received in units; over-delivery judged in stock units (refused without a note,
 * booked with one); a posted "packs" choice ignored on a line with no pack size; barcode receive with an expiry
 * date (saved, refused when before the received date or not a date, optional); a new barcode defaulting to the
 * item's own pack size and name.
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
function near($a, float $b): bool { return abs((float)$a - $b) <= 0.0005; }

$harness = $root . '/scripts/tests/page_harness.php';
$run = strtoupper(bin2hex(random_bytes(3)));
$like = "'ZZR {$run} %'";

function sr_run(string $scenario, string $run, array $verify, array $opts = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => ['scenario' => $scenario, 'run' => $run], 'verify' => $verify, 'ajax' => !empty($opts['ajax'])]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/stock_receiving_child.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr($out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $res = $x; } }
    $res['flash'] = ['', ''];
    if (preg_match('/@@FLASH@@(.*)$/m', $out, $m)) { $f = json_decode($m[1], true); if (is_array($f)) { $res['flash'] = $f; } }
    return $res;
}
function sr_clean(array $r, string $label): void {
    ok("$label: ran and rolled back, nothing leaked", !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']), json_encode($r['raw'] ?? $r));
}

$vBatches = "SELECT i.name, b.quantity_received AS qty, b.cost_per_unit AS cost, b.expiry_date AS expiry FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE i.name LIKE $like ORDER BY i.name";
$vIng     = "SELECT name, current_quantity AS qty, cost_per_unit AS cost FROM stock_ingredients WHERE name LIKE $like ORDER BY name";
$vLines   = "SELECT i.name, l.ordered_qty, l.unit_cost, l.received_qty, l.over_received_qty AS over_qty FROM stock_purchase_order_items l INNER JOIN stock_ingredients i ON i.id = l.ingredient_id WHERE i.name LIKE $like ORDER BY i.name";
$vLog     = "SELECT COUNT(*) AS n FROM stock_in_log g INNER JOIN stock_ingredients i ON i.id = g.ingredient_id WHERE i.name LIKE $like";
$byName = static function (array $rows, string $suffix): ?array {
    foreach ($rows as $r) { if (is_array($r) && substr((string)$r['name'], -strlen($suffix)) === $suffix) { return $r; } }
    return null;
};

// 1. Mixed delivery through the real receive form
$r = sr_run('receive_mixed', $run, [$vBatches, $vIng, $vLines]);
sr_clean($r, 'Receive form');
[$batches, $ings, $lines] = $r['verify'] ?? [[], [], []];
ok('Receive form: it was accepted (no error shown)', ($r['flash'][0] ?? 'x') === '' && stripos((string)($r['flash'][1] ?? ''), 'received') !== false, json_encode($r['flash']));
$b = $byName((array)$batches, 'lager');
ok('Pack line received in packs: 1 crate = 24 bottles, booked at the invoice price per pack / 24 (126,000 / 24 = 5,250)', $b && near($b['qty'], 24) && near($b['cost'], 5250), json_encode($b));
ok('Pack line: the expiry date typed on the receive form is on the batch', $b && $b['expiry'] === '2031-01-01', json_encode($b));
$l = $byName((array)$lines, 'lager');
ok('Pack line: the order line itself is unchanged (still 5,000 a bottle, 48 ordered) and shows 24 received', $l && near($l['unit_cost'], 5000) && near($l['ordered_qty'], 48) && near($l['received_qty'], 24) && near($l['over_qty'], 0), json_encode($l));
$i = $byName((array)$ings, 'lager');
ok('Pack line: stock is 24 bottles at an average of 5,250', $i && near($i['qty'], 24) && near($i['cost'], 5250), json_encode($i));
$b = $byName((array)$batches, 'noprice');
ok('No price on the order and no average: the price typed at the door (40 a bottle) is used', $b && near($b['qty'], 5) && near($b['cost'], 40), json_encode($b));
$b = $byName((array)$batches, 'crateunits');
ok('Pack line received in units: 12 bottles at the invoice price per bottle (1,100)', $b && near($b['qty'], 12) && near($b['cost'], 1100), json_encode($b));
$l = $byName((array)$lines, 'crateunits');
ok('Pack line received in units: order line keeps 1,000 a bottle and shows 12 of 24 received', $l && near($l['unit_cost'], 1000) && near($l['received_qty'], 12) && near($l['ordered_qty'], 24), json_encode($l));
$b = $byName((array)$batches, 'avgitem');
ok('Blank price with an average cost: falls back to the average (7)', $b && near($b['qty'], 3) && near($b['cost'], 7), json_encode($b));

// 2. No price anywhere: refused, nothing booked, and the message names the line
$r = sr_run('no_price_refused', $run, [$vBatches, $vLines, $vLog]);
sr_clean($r, 'No price');
[$batches, $lines, $log] = $r['verify'] ?? [[], [], [['n' => -1]]];
ok('No price on the order, no average, no price typed: refused with a plain message', stripos((string)($r['flash'][0] ?? ''), 'enter the price') !== false, json_encode($r['flash']));
ok('No price: no batch, no stock-in row, nothing marked received', count((array)$batches) === 0 && (int)($log[0]['n'] ?? -1) === 0 && near(($lines[0]['received_qty'] ?? -1), 0), json_encode([$batches, $log, $lines]));

// 3. Over-delivery is judged in stock units, even when typed in packs
$r = sr_run('over_packs', $run, [$vBatches, $vLines]);
sr_clean($r, 'Over-delivery without a note');
ok('Over-delivery typed in packs (2 crates for 1 ordered): refused without a note', stripos((string)($r['flash'][0] ?? ''), 'over-delivery note') !== false && count((array)($r['verify'][0] ?? [])) === 0, json_encode($r['flash']));
$r = sr_run('over_packs_note', $run, [$vBatches, $vLines]);
sr_clean($r, 'Over-delivery with a note');
$b = $byName((array)($r['verify'][0] ?? []), 'lager');
$l = $byName((array)($r['verify'][1] ?? []), 'lager');
ok('Over-delivery with a note: 48 bottles booked, 24 flagged over', $b && near($b['qty'], 48) && $l && near($l['received_qty'], 48) && near($l['over_qty'], 24), json_encode([$b, $l]));

// 4. A line with no pack size ignores a posted "packs" choice
$r = sr_run('forged_pack', $run, [$vBatches, $vLines]);
sr_clean($r, 'Forged pack choice');
$b = $byName((array)($r['verify'][0] ?? []), 'plainitem');
ok('Line ordered in units: a posted packs choice or pack size is ignored (3 bottles at 5, not 3 crates)', $b && near($b['qty'], 3) && near($b['cost'], 5), json_encode($b));

// 4b. An old receive form (no crates/bottles choice posted) is read as bottles, never multiplied by the crate size
$r = sr_run('stale_form', $run, [$vBatches, $vLines]);
sr_clean($r, 'Old receive form');
$b = $byName((array)($r['verify'][0] ?? []), 'lager');
ok('Old receive form on a crate line: 3 means 3 bottles at 5,000 a bottle (not 3 crates, not 5,000 a crate)', $b && near($b['qty'], 3) && near($b['cost'], 5000), json_encode($b));

// 5. Barcode receive with an expiry date
$r = sr_run('bc_expiry', $run, [$vBatches], ['ajax' => true]);
sr_clean($r, 'Barcode receive with expiry');
$b = $byName((array)($r['verify'][0] ?? []), 'scanned');
ok('Barcode receive: the expiry date typed on the line is saved on the batch', !empty($r['json']['ok']) && $b && near($b['qty'], 24) && $b['expiry'] === '2031-06-30', json_encode([$r['json'] ?? null, $b]));
$r = sr_run('bc_no_expiry', $run, [$vBatches], ['ajax' => true]);
$b = $byName((array)($r['verify'][0] ?? []), 'scanned');
ok('Barcode receive: the expiry date is optional (batch has none)', !empty($r['json']['ok']) && $b && $b['expiry'] === null, json_encode([$r['json'] ?? null, $b]));
$r = sr_run('bc_expiry_past', $run, [$vBatches], ['ajax' => true]);
ok('Barcode receive: an expiry before the received date is refused and nothing is booked', empty($r['json']['ok']) && !empty($r['json']['error']) && count((array)($r['verify'][0] ?? [])) === 0, json_encode($r['json'] ?? $r));
$r = sr_run('bc_expiry_bad', $run, [$vBatches], ['ajax' => true]);
ok('Barcode receive: something that is not a date is refused and nothing is booked', empty($r['json']['ok']) && !empty($r['json']['error']) && count((array)($r['verify'][0] ?? [])) === 0, json_encode($r['json'] ?? $r));

// 6. New barcode defaults to the item's own pack
$vMap = "SELECT i.name, m.pack_size, m.pack_label FROM stock_ingredient_barcodes m INNER JOIN stock_ingredients i ON i.id = m.ingredient_id WHERE i.name LIKE $like";
$r = sr_run('bc_register_default', $run, [$vMap], ['ajax' => true]);
sr_clean($r, 'Register barcode, nothing typed');
$m = ($r['verify'][0][0] ?? null);
ok('Register barcode with no pack typed: uses the item\'s own pack (24, crate)', !empty($r['json']['ok']) && $m && near($m['pack_size'], 24) && $m['pack_label'] === 'crate', json_encode([$r['json'] ?? null, $m]));
$r = sr_run('bc_register_typed', $run, [$vMap], ['ajax' => true]);
$m = ($r['verify'][0][0] ?? null);
ok('Register barcode with a pack typed: what staff typed wins (12, half crate)', !empty($r['json']['ok']) && $m && near($m['pack_size'], 12) && $m['pack_label'] === 'half crate', json_encode([$r['json'] ?? null, $m]));

// Leak check from here (a different connection): nothing left behind
$left = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZR {$run} %'")->fetchColumn()
      + (int)$pdo->query("SELECT COUNT(*) FROM stock_purchase_orders WHERE reference LIKE 'ZZR{$run}%'")->fetchColumn();
ok('Rollback: no test ingredients or purchase orders left behind', $left === 0, (string)$left);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
