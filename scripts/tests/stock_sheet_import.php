<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Stock sheet import test (scripts/import_stock_sheet.php + admin/includes/stock-sheet-import-lib.php).
 * Usage (project root): php scripts/tests/stock_sheet_import.php
 *
 * Pure checks (crate size rule, units, names, reorder point) plus scenarios that run in page_harness.php (one
 * outer transaction that can never commit, rolled back): a made-up sheet is planned and applied, applied again
 * (nothing changes), and the opening count it leaves SUBMITTED is approved through the real stock-count.php page
 * by a different admin (stock and expiry batches appear only then) or refused for the person who counted it.
 * The only thing written outside the harness is the empty stock_opening_batches table (additive, same as --apply).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
require_once $root . '/admin/includes/stock-sheet-import-lib.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function near($a, float $b): bool { return abs((float)$a - $b) <= 0.0005; }

// ── Pure rules ─────────────────────────────────────────────────────────────────────────────
$r = rh_ssi_infer_pack(71, 100, 2.0);
ok('Crate size: Green (71 of 100, bought 2) can only be a crate of 24', $r['pack'] === 24, json_encode($r));
$r = rh_ssi_infer_pack(55, 100, 2.0);
ok('Crate size: Coke (55 of 100, bought 2) can only be a crate of 24', $r['pack'] === 24, json_encode($r));
$r = rh_ssi_infer_pack(19, 30, 2.0);
ok('Crate size: Tonic (19 of 30, bought 2) can only be a crate of 6', $r['pack'] === 6, json_encode($r));
$r = rh_ssi_infer_pack(104, 100, 0.0);
ok('Crate size: nothing to buy fits every size, so it is flagged', $r['pack'] === null && $r['fits'] === [6, 12, 24], json_encode($r));
$r = rh_ssi_infer_pack(52, 100, 3.0);
ok('Crate size: a quantity no size gives is flagged', $r['pack'] === null && $r['fits'] === [], json_encode($r));
$r = rh_ssi_infer_pack(11, 24, 0.5);
ok('Crate size: half a crate is not a whole number, flagged', $r['pack'] === null, json_encode($r));
$r = rh_ssi_infer_pack(5, 24, 2.0, true);
ok('Crate size: the sheet\'s own bottle-count formula says nothing', $r['pack'] === null && strpos($r['reason'], 'formula') !== false, json_encode($r));
ok('Crate size hint: half crates point to the one size that explains them', rh_ssi_half_crate_fits(11, 24, 0.5) === [24], json_encode(rh_ssi_half_crate_fits(11, 24, 0.5)));
ok('Name key: case, spaces and non-breaking spaces ignored', rh_ssi_key("  Castelite\xC2\xA0  B1 ") === 'castelite b1');
ok('Units: sheet spellings become one stock unit', rh_ssi_norm_unit('Bottles') === 'bottle' && rh_ssi_norm_unit('Litres') === 'L' && rh_ssi_norm_unit('mls') === 'ml' && rh_ssi_norm_unit('') === '');
ok('Units: each / pcs / piece are the same, kg and bottle are not', rh_ssi_units_compatible('Pieces', 'each') && !rh_ssi_units_compatible('bottle', 'kg') && rh_ssi_units_compatible('litres', 'L'));
ok('Reorder point: about 30% of max, whole bottles, never above par', rh_ssi_reorder_point(100, 'bottle') === 30.0 && rh_ssi_reorder_point(1, 'bottle') === 1.0 && rh_ssi_reorder_point(0, 'bottle') === 0.0 && near(rh_ssi_reorder_point(5, 'kg'), 1.5));
ok('Notes: the section line is replaced, not repeated', rh_ssi_merge_notes("mine\nStock sheet section: Old", 'Whiskys') === "mine\nStock sheet section: Whiskys" && rh_ssi_merge_notes(rh_ssi_merge_notes('', 'A'), 'A') === 'Stock sheet section: A');

// ── Harness scenarios ──────────────────────────────────────────────────────────────────────
rh_ssi_ensure_table($pdo);   // the only DDL, and the same one --apply runs (the harness skips DDL)
$harness = $root . '/scripts/tests/page_harness.php';
$run = strtoupper(bin2hex(random_bytes(3)));
function ssi_run(string $scenario, string $run, array $verify = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => ['scenario' => $scenario, 'run' => $run], 'verify' => $verify]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/stock_sheet_import_child.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $res = $x; } }
    if (isset($res['json'])) { $res['child'] = $res['json']; }
    return $res;
}
$countsBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_counts WHERE notes LIKE 'Stock sheet import%'")->fetchColumn();
$like = "'ZZT {$run} %'";
$verify = [
    "SELECT name, current_quantity, cost_per_unit, category, unit, par_level, reorder_point, pack_size FROM stock_ingredients WHERE name LIKE $like ORDER BY name",
    "SELECT i.name, b.quantity_received, b.quantity_remaining, b.expiry_date, b.status, b.cost_per_unit FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE i.name LIKE $like ORDER BY i.name, b.expiry_date",
    "SELECT c.status, COUNT(l.id) AS nlines FROM stock_counts c LEFT JOIN stock_count_lines l ON l.count_id = c.id WHERE c.notes LIKE 'Stock sheet import%' AND EXISTS (SELECT 1 FROM stock_count_lines l2 INNER JOIN stock_ingredients i2 ON i2.id = l2.ingredient_id WHERE l2.count_id = c.id AND i2.name LIKE $like) GROUP BY c.id",
    "SELECT COUNT(*) AS n, SUM(applied_batch_id IS NOT NULL) AS applied FROM stock_opening_batches o INNER JOIN stock_ingredients i ON i.id = o.ingredient_id WHERE i.name LIKE $like",
];

$a = ssi_run('apply', $run, $verify);
ok('Apply scenario ran and rolled back', !empty($a['rolled_back']) && empty($a['leak']), json_encode($a['raw'] ?? $a));
$c = $a['child'] ?? [];
$p = $c['plan'] ?? [];
ok('Plan: the blank-name row is skipped', ($p['skipped'] ?? -1) === 1, json_encode($p));
ok('Plan: Castelite B1 + B2 are one item, balance 16, crate of 24, cost 5000 a bottle, two expiry batches', near($p['castelite']['balance'] ?? 0, 16) && ($p['castelite']['pack'] ?? 0) === 24 && near($p['castelite']['cost'] ?? 0, 5000) && ($p['castelite']['batches'] ?? 0) === 2, json_encode($p['castelite'] ?? null));
ok('Plan: an item with no price is not in the opening count', ($p['noprice_in_count'] ?? true) === false);
ok('Plan: a price on the sheet\'s bottle-count rows is taken per bottle', near($p['vodka_cost'] ?? 0, 30000) && ($p['vodka_status'] ?? '') === 'per_bottle', json_encode([$p['vodka_cost'] ?? null, $p['vodka_status'] ?? null]));
ok('Plan: a crate size that does not fit is flagged and its stock left out of the count', ($p['odd_status'] ?? '') === 'pack_unknown' && ($p['odd_in_count'] ?? true) === false, json_encode([$p['odd_status'] ?? null, $p['odd_in_count'] ?? null]));
ok('Plan: existing ingredient matched case-insensitively and updated', ($p['rice_action'] ?? '') === 'update' && in_array('category', $p['rice_changes'] ?? [], true) && in_array('cost', $p['rice_changes'] ?? [], true), json_encode([$p['rice_action'] ?? null, $p['rice_changes'] ?? null]));
ok('Plan: unit clash (tins vs kg) leaves the existing ingredient alone', ($p['paste_action'] ?? '') === 'skip_conflict');
ok('Plan: same name in another department becomes its own item "(77 Mug)"', ($p['flour77'] ?? false) === true);
ok('Notes: a soft drink priced like a crate gets a BLANK cost and the crate note', array_key_exists('cost', $p['fizz'] ?? []) && $p['fizz']['cost'] === null && strpos($p['fizz']['notes'] ?? '', 'looks like a crate/case price; crate size unknown - set the pack size and cost.') !== false, json_encode($p['fizz'] ?? null));
ok('Notes: a spirit priced plausibly per bottle keeps it, with a note', strpos($p['spirit_notes'] ?? '', 'Cost taken as per bottle') !== false);
ok('Notes: a spirit price too big for one bottle is blank', array_key_exists('cost', $p['pricey'] ?? []) && $p['pricey']['cost'] === null);
ok('Notes: no unit -> a unit is chosen and noted', ($p['charcoal']['unit'] ?? '') === 'each' && rh_ssi_choose_unit('Charcoal') === 'bag' && rh_ssi_choose_unit('Ream Paper') === 'ream' && strpos($p['charcoal']['notes'] ?? '', 'Unit chosen at import - please check.') !== false, json_encode($p['charcoal'] ?? null));
ok('Notes: no price -> note says add the cost', strpos($p['noprice_notes'] ?? '', 'Price not on the July 2026 sheet - add the cost.') !== false);
ok('Notes: an existing item left alone gets a clash note with the sheet value', strpos($p['paste_notes'] ?? '', 'Sheet clash:') !== false && strpos($p['paste_notes'] ?? '', '1,500') !== false, (string)($p['paste_notes'] ?? ''));
$ap = $c['apply'] ?? [];
ok('Apply: new ingredients created (not the clash)', ($ap['created'] ?? 0) === 10, json_encode($ap));
ok('Apply: one existing ingredient updated', ($ap['updated'] ?? 0) === 1, json_encode($ap));
ok('Apply: ONE opening count left submitted, never approved', ($c['count']['status'] ?? '') === 'submitted' && (int)($c['count']['is_opening'] ?? 0) === 1, json_encode($c['count'] ?? null));
ok('Apply: expiry batches are waiting (2 for Castelite)', ($ap['batches'] ?? 0) === 2, json_encode($ap));
ok('Apply: no stock exists before the count is approved', near($c['qty_before_approval'] ?? -1, 0), json_encode($c['qty_before_approval'] ?? null));
$rice = $c['rice_after_apply'] ?? [];
ok('Apply: the existing ingredient kept its unit and note, took category, par and reorder', ($rice['category'] ?? '') === 'Kitchen' && ($rice['unit'] ?? '') === 'kg' && strpos((string)($rice['notes'] ?? ''), 'keep this note') !== false && strpos((string)($rice['notes'] ?? ''), 'Stock sheet section: Dry goods') !== false && near($rice['par_level'] ?? 0, 20) && near($rice['reorder_point'] ?? 0, 6) && near($rice['cost_per_unit'] ?? 0, 4800), json_encode($rice));
ok('Apply: the existing ingredient\'s stock is not touched by the import', near($rice['current_quantity'] ?? 0, -2), json_encode($rice));
$bl = $c['budget_lines'] ?? [];
ok('Apply: fixed budget lines set (zero skipped), control warn', count($bl) === 2 && near($bl[0]['amount'] ?? 0, 3387814) && near($bl[1]['amount'] ?? 0, 4730287.5) && ($bl[0]['control'] ?? '') === 'warn', json_encode($bl));
ok('Re-run changes nothing: no new ingredients, no new count, no budget change', ($c['second_run']['created'] ?? -1) === 0 && array_key_exists('count_id', $c['second_run'] ?? []) && $c['second_run']['count_id'] === null && ($c['second_run']['budget_changed'] ?? -1) === 0 && ($c['counts_total'] ?? 0) === 1, json_encode([$c['second_run'] ?? null, $c['counts_total'] ?? null]));
ok('Report renders', !empty($c['report_ok']));
$v = $a['verify'] ?? [];
ok('Nothing was written to batches by the import itself', is_array($v[1] ?? null) && count($v[1]) === 0, json_encode($v[1] ?? null));

$pa = ssi_run('partial', $run);
ok('Partial scenario ran and rolled back', !empty($pa['rolled_back']) && empty($pa['leak']), json_encode($pa['raw'] ?? $pa));
$pc = $pa['child'] ?? [];
ok('Partial: an item already in another open opening count is left out of the import\'s count', ($pc['rice']['in_count'] ?? true) === false && ($pc['rice']['already'] ?? false) === true, json_encode($pc['rice'] ?? $pc));
ok('Partial: an item that is only in a rejected opening count still goes into the import\'s count', ($pc['flour']['in_count'] ?? false) === true && ($pc['flour']['already'] ?? true) === false, json_encode($pc['flour'] ?? $pc));
ok('Partial: the plan report still renders', !empty($pc['report_ok']));

$ga = ssi_run('gap', $run);
ok('Gap scenario ran and rolled back', !empty($ga['rolled_back']) && empty($ga['leak']), json_encode($ga['raw'] ?? $ga));
$gc = $ga['child'] ?? [];
ok('Gap: an item put in another open opening count after the plan was made is left out at apply time', ($gc['rice_in_import_count'] ?? true) === false && ($gc['left_out'] ?? 0) >= 1, json_encode($gc));
ok('Gap: the other items still go into the import\'s count', ($gc['lines'] ?? 0) > 0, json_encode($gc));

$b = ssi_run('approve', $run, $verify);
ok('Approve scenario ran and rolled back', !empty($b['rolled_back']) && empty($b['leak']), json_encode($b['raw'] ?? $b));
$v = $b['verify'] ?? [];
$ing = []; foreach (($v[0] ?? []) as $x) { $ing[$x['name']] = $x; }
$cast = $ing["ZZT {$run} Castelite"] ?? null;
ok('Approved: Castelite stock is 16 at 5000 a bottle', $cast && near($cast['current_quantity'], 16) && near($cast['cost_per_unit'], 5000), json_encode($cast));
$cb = array_values(array_filter($v[1] ?? [], fn($x) => $x['name'] === "ZZT {$run} Castelite"));
ok('Approved: Castelite becomes two batches with their own expiry (earliest first)', count($cb) === 2 && $cb[0]['expiry_date'] === '2030-01-01' && near($cb[0]['quantity_remaining'], 5) && $cb[1]['expiry_date'] === '2031-01-01' && near($cb[1]['quantity_remaining'], 11), json_encode($cb));
$flour = $ing["ZZT {$run} Flour"] ?? null;
$fb = array_values(array_filter($v[1] ?? [], fn($x) => $x['name'] === "ZZT {$run} Flour"));
ok('Approved: Flour (no expiry) is one plain batch of 10', $flour && near($flour['current_quantity'], 10) && count($fb) === 1 && $fb[0]['expiry_date'] === null && near($fb[0]['quantity_remaining'], 10), json_encode([$flour, $fb]));
$rice = $ing["ZZT {$run} Rice"] ?? null;
ok('Approved: Rice that was at -2 ends at the counted 10, batches add up to it', $rice && near($rice['current_quantity'], 10), json_encode($rice));
$rb = array_values(array_filter($v[1] ?? [], fn($x) => $x['name'] === "ZZT {$run} Rice"));
ok('Approved: Rice batch remaining equals the stock on hand', count($rb) >= 1 && near(array_sum(array_column($rb, 'quantity_remaining')), 10), json_encode($rb));
$noPrice = $ing["ZZT {$run} Spirit with no price"] ?? null;
ok('Approved: the item with no price got no stock', $noPrice && near($noPrice['current_quantity'], 0));
ok('Approved: the count is approved and every waiting batch was used', ($v[2][0]['status'] ?? '') === 'approved' && (int)($v[3][0]['n'] ?? -1) === 2 && (int)($v[3][0]['applied'] ?? -1) === 2, json_encode([$v[2] ?? null, $v[3] ?? null]));

$s = ssi_run('self_approve', $run, $verify);
ok('Self-approve scenario ran and rolled back', !empty($s['rolled_back']) && empty($s['leak']), json_encode($s['raw'] ?? $s));
$v = $s['verify'] ?? [];
ok('The person who counted cannot approve it: still submitted', ($v[2][0]['status'] ?? '') === 'submitted', json_encode($v[2] ?? null));
$ing = []; foreach (($v[0] ?? []) as $x) { $ing[$x['name']] = $x; }
ok('Refused approval puts no stock in', near(($ing["ZZT {$run} Castelite"]['current_quantity'] ?? -1), 0) && count($v[1] ?? []) === 0);

$s = ssi_run('self_approve_off', $run, $verify);
ok('Self-approve (rule OFF) scenario ran and rolled back', !empty($s['rolled_back']) && empty($s['leak']), json_encode($s['raw'] ?? $s));
$v = $s['verify'] ?? [];
$ing = []; foreach (($v[0] ?? []) as $x) { $ing[$x['name']] = $x; }
ok('Rule OFF: the person who counted can approve it, and the stock goes in', ($v[2][0]['status'] ?? '') === 'approved' && near(($ing["ZZT {$run} Castelite"]['current_quantity'] ?? -1), 16), json_encode([$v[2] ?? null, $ing["ZZT {$run} Castelite"] ?? null]));

$left = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT {$run} %'")->fetchColumn();
ok('Rollback: no test ingredients left behind', $left === 0, (string)$left);
$leftC = (int)$pdo->query("SELECT COUNT(*) FROM stock_counts WHERE notes LIKE 'Stock sheet import%'")->fetchColumn() - $countsBefore;
ok('Rollback: no stock sheet count left behind', $leftC === 0, (string)$leftC);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
