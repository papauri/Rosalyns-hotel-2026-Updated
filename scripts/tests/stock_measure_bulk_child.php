<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Scenarios for scripts/tests/stock_measure_bulk.php (Stock Items: set one count measure on many items, owner 2026-11-23). Run by
 * page_harness.php: ONE outer transaction that can never commit, rolled back at the end. Every row is named 'ZZB <run> ...'
 * (made-up bar items, quantities and costs).
 * POST fields: run, scenario. 'lib' prints {"checks":[[name, ok, detail], ...]}; the page_* scenarios build the items and then run
 * the REAL admin/stock-ingredients.php with the POST a browser would send (its JSON is the output; the runner checks it + verify SQL).
 */
if (defined('DB_NAME') && stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    echo json_encode(['checks' => [["refused: Rosalyn's is live, run this from the Liwonde repo", false, '']]]), "\n";
    exit;
}
$run = (string)($_POST['run'] ?? 'X');
$scn = (string)($_POST['scenario'] ?? 'lib');
$csrfTok = (string)($_POST['csrf_token'] ?? '');
$me = (int)($_SESSION['admin_user_id'] ?? 0);
$P = 'ZZB ' . $run . ' ';
$CAT = 'ZZB ' . $run . ' Bar';
$checks = [];
$ck = function (string $n, $c, string $d = '') use (&$checks) { $checks[] = [$n, (bool)$c, $d]; };

require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/stock-measure-bulk-lib.php';

$mkIng = function (string $tag, string $unit, float $qty, float $cost, ?array $m = null, ?string $cat = null) use ($pdo, $P, $CAT): int {
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, measure_big_name, measure_small_name, measure_per_big, measure_stock_side) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$P . $tag, $cat ?? $CAT, $unit, $qty, $cost, $m[0] ?? null, $m[1] ?? null, $m[2] ?? null, $m[3] ?? null]);
    return (int)$pdo->lastInsertId();
};
$build = function () use ($pdo, $mkIng, $P, $me, $run): array {
    $ids = [];
    $ids['A'] = $mkIng('Brandy A', 'bottle', 2.28, 4000);
    $ids['B'] = $mkIng('Gin B', 'bottle', 5, 6000);
    $ids['C'] = $mkIng('Rum C', 'Bottles', 0.5, 3000);
    $ids['Same'] = $mkIng('Vodka Same', 'bottle', 3, 5000, ['bottle', 'shot', 25, 'big']);
    $ids['Diff'] = $mkIng('Whisky Diff', 'bottle', 4, 9000, ['bottle', 'tot', 20, 'big']);
    $ids['Kg'] = $mkIng('Sugar Kg', 'kg', 3, 1500);
    $ids['Open'] = $mkIng('Tequila Open', 'bottle', 2, 7000, ['bottle', 'tot', 20, 'big']);
    // a batch on A (FIFO cost) that must not change
    $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, expiry_alert_days, status, notes, created_by) VALUES (?, ?, 2.28, 2.28, 4000, CURDATE(), 7, 'active', 'ZZB test', ?)")
        ->execute([$ids['A'], 'ZZB-' . $run . '-A', $me]);
    // an open (draft) count with a line on Open typed as 7 tots
    $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, is_opening, created_at, snapshot_at) VALUES (?, CURDATE(), 'full', 'draft', ?, 0, NOW(), NOW())")
        ->execute(['20991231-ZZB' . $run, $me]);
    $cid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost, reason_code, counted_at, entry_mode, entry_big, entry_small, entry_per_big)
        VALUES (?, ?, 2, 0.35, -1.65, 7000, -11550, '', NOW(), 'small', NULL, 7, 20)")->execute([$cid, $ids['Open']]);
    $ids['count'] = $cid;
    // 12 items in one category (more than one page of 10) + 2 in another, for "Tick all shown" with a category filter
    for ($k = 1; $k <= 12; $k++) {
        $ids['many'][] = $mkIng(sprintf('Many %02d', $k), 'bottle', $k + 0.5, 1000 + $k, null, 'ZZB ' . $run . ' Many');
    }
    $ids['other'][] = $mkIng('Other 1', 'bottle', 1, 100, null, 'ZZB ' . $run . ' Other');
    $ids['other'][] = $mkIng('Other 2', 'bottle', 1, 100, null, 'ZZB ' . $run . ' Other');
    $ids['arch'] = $mkIng('Archived', 'bottle', 0, 100, null, 'ZZB ' . $run . ' Many');
    $pdo->prepare('UPDATE stock_ingredients SET is_archived = 1 WHERE id = ?')->execute([$ids['arch']]);
    return $ids;
};
$snap = function (array $ids) use ($pdo): array {
    $ids = array_values(array_filter($ids, 'is_int'));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT i.id, i.current_quantity, i.cost_per_unit, i.unit, (SELECT COALESCE(SUM(b.quantity_remaining), 0) FROM stock_batches b WHERE b.ingredient_id = i.id) AS bq,
        (SELECT COALESCE(SUM(b.cost_per_unit), 0) FROM stock_batches b WHERE b.ingredient_id = i.id) AS bc FROM stock_ingredients i WHERE i.id IN ($in) ORDER BY i.id");
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$measureOf = function (int $id) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT measure_big_name, measure_small_name, measure_per_big, measure_stock_side FROM stock_ingredients WHERE id = ?');
    $st->execute([$id]);
    return rh_stock_measure_get((array)$st->fetch(PDO::FETCH_ASSOC));
};
$spec = function (array $in): array { return rh_smb_read_spec($in); };

if ($scn === 'lib') {
    try {
        if (!rh_stock_measure_ready($pdo)) {
            $ck('count measures are set up on this database (migration 088)', false, 'not ready');
            echo json_encode(['checks' => $checks]);
            return;
        }
        $ids = $build();
        $main = [$ids['A'], $ids['B'], $ids['C'], $ids['Same'], $ids['Diff'], $ids['Kg'], $ids['Open']];

        // ---- the measure typed once: validation and exactness (same limits as the per-item editor)
        $ok = $spec(['small_name' => 'shot', 'per_big' => '25', 'side' => 'big']);
        $ck('spec: shot, 25 in one bottle, kept in the bigger unit is accepted (bigger unit empty = each item\'s own unit)', $ok['ok'] && $ok['spec']['big'] === '' && abs($ok['spec']['n'] - 25) < 1e-9, json_encode($ok));
        foreach (['0' => 'between 1 and', '1001' => 'between 1 and', 'abc' => 'must be a number', '25.12345' => 'at most 4 decimals', '-5' => 'must be a number', '' => 'Type how many'] as $bad => $want) {
            $r = $spec(['small_name' => 'shot', 'per_big' => (string)$bad, 'side' => 'big']);
            $ck('spec: invalid N "' . $bad . '" is refused (' . $want . ')', !$r['ok'] && strpos(implode(' ', $r['errors']), $want) !== false, json_encode($r['errors']));
        }
        $r = $spec(['small_name' => '', 'per_big' => '25', 'side' => 'big']);
        $ck('spec: no smaller measure is refused', !$r['ok'] && strpos(implode(' ', $r['errors']), 'smaller measure') !== false, json_encode($r['errors']));
        $r = $spec(['big_name' => 'shot', 'small_name' => 'shots', 'per_big' => '25', 'side' => 'big']);
        $ck('spec: the same word for both is refused', !$r['ok'] && strpos(implode(' ', $r['errors']), 'different names') !== false, json_encode($r['errors']));
        $r = $spec(['small_name' => 'shot', 'per_big' => '25', 'side' => 'small']);
        $ck('spec: stock kept in the smaller measure needs the bigger unit typed', !$r['ok'] && strpos(implode(' ', $r['errors']), 'needs the bigger unit') !== false, json_encode($r['errors']));
        $r = $spec(['small_name' => 'shot', 'per_big' => '', 'bottle_ml' => '750', 'measure_ml' => '30', 'side' => 'big']);
        $ck('ml helper: 750 ml / 30 ml fills exactly 25 shots', $r['ok'] && abs($r['spec']['n'] - 25) < 1e-9 && $r['spec']['from_ml'] === true, json_encode($r));
        $r = $spec(['small_name' => 'shot', 'per_big' => '', 'bottle_ml' => '750', 'measure_ml' => '60', 'side' => 'big']);
        $ck('ml helper: 750 / 60 = exactly 12.5', $r['ok'] && abs($r['spec']['n'] - 12.5) < 1e-9, json_encode($r));
        $r = $spec(['small_name' => 'shot', 'per_big' => '', 'bottle_ml' => '1000', 'measure_ml' => '30', 'side' => 'big']);
        $ck('ml helper: 1000 / 30 is not exact and is refused with the reason (never rounded)', !$r['ok'] && strpos(implode(' ', $r['errors']), 'not an exact number') !== false, json_encode($r['errors']));
        $r = $spec(['small_name' => 'tot', 'per_big' => '', 'bottle_ml' => '750', 'measure_ml' => '35', 'side' => 'big']);
        $ck('ml helper: 750 / 35 is refused', !$r['ok'] && strpos(implode(' ', $r['errors']), 'not an exact number') !== false, json_encode($r['errors']));
        $r = $spec(['small_name' => 'shot', 'per_big' => '24', 'bottle_ml' => '750', 'measure_ml' => '30', 'side' => 'big']);
        $ck('ml helper: a typed N that does not match the ml sizes is refused', !$r['ok'] && strpos(implode(' ', $r['errors']), 'does not match') !== false, json_encode($r['errors']));
        $r = $spec(['small_name' => 'shot', 'per_big' => '25', 'bottle_ml' => '750', 'measure_ml' => '', 'side' => 'big']);
        $ck('ml helper: only one size typed is refused', !$r['ok'] && strpos(implode(' ', $r['errors']), 'Type both sizes') !== false, json_encode($r['errors']));
        [$n1, $e1] = rh_smb_n_from_ml('750', '25');
        [$n2, $e2] = rh_smb_n_from_ml('1000', '30');
        $ck('rh_smb_n_from_ml: 750/25 = 30; 1000/30 = refused', $n1 === 30.0 && $e1 === null && $n2 === null && $e2 !== null, json_encode([$n1, $e1, $n2, $e2]));

        // ---- the ticked items
        $s = rh_smb_select($pdo, ['mode' => 'ids', 'ids' => implode(',', $main)]);
        $ck('select by ticked ids: the 7 items', $s['ok'] && count($s['items']) === 7, json_encode($s['errors']));
        $s = rh_smb_select($pdo, ['mode' => 'ids', 'ids' => $ids['A'] . ',' . $ids['arch']]);
        $ck('select: an archived (or gone) item is refused with a reload message', !$s['ok'] && strpos($s['errors'][0], 'archived') !== false, json_encode($s['errors']));
        $s = rh_smb_select($pdo, ['mode' => 'ids', 'ids' => '1,abc']);
        $ck('select: a bad id list is refused', !$s['ok'], json_encode($s['errors']));
        $allMany = ['mode' => 'all', 'q' => '', 'category' => 'ZZB ' . $run . ' Many', 'status' => '', 'list_filter' => '', 'except' => '', 'shown' => '12'];
        $s = rh_smb_select($pdo, $allMany);
        $manyIds = array_map(static fn(array $r): int => (int)$r['id'], $s['items'] ?? []);
        sort($manyIds);
        $want = $ids['many'];
        sort($want);
        $ck('Tick all shown, category filter: all 12 items of the category (more than one page of 10), not the archived one, not other categories', $s['ok'] && $manyIds === $want, json_encode([$s['errors'], count($manyIds)]));
        $s = rh_smb_select($pdo, ['except' => $ids['many'][0] . ',' . $ids['many'][5]] + $allMany);
        $ck('Tick all shown: two boxes unticked afterwards are left out (10 left)', $s['ok'] && count($s['items']) === 10 && !in_array($ids['many'][0], array_map(static fn($r) => (int)$r['id'], $s['items']), true), json_encode($s['errors']));
        $s = rh_smb_select($pdo, ['shown' => '11'] + $allMany);
        $ck('Tick all shown: when the server finds a different number than the page showed it refuses (reload)', !$s['ok'] && strpos($s['errors'][0], 'The list changed') !== false, json_encode($s['errors']));
        $s = rh_smb_select($pdo, ['q' => 'many 1', 'shown' => '3'] + $allMany);
        $ck('Tick all shown with a search too: "many 1" in that category = Many 10, 11, 12', $s['ok'] && count($s['items']) === 3, json_encode([$s['errors'], count($s['items'] ?? [])]));
        $s = rh_smb_select($pdo, ['status' => 'unstocked', 'shown' => '0'] + $allMany);
        $ck('Tick all shown with a status that matches none: refused (nothing ticked)', !$s['ok'], json_encode($s['errors']));

        // ---- preview
        $items = rh_smb_select($pdo, ['mode' => 'ids', 'ids' => implode(',', $main)])['items'];
        $sp = $spec(['big_name' => 'bottle', 'small_name' => 'shot', 'per_big' => '25', 'side' => 'big'])['spec'];
        $plan = rh_smb_plan($pdo, $items, $sp);
        $by = [];
        foreach ($plan['rows'] as $r) { $by[$r['id']] = $r; }
        $ck('preview: 3 new-measure items "set", Same "same", Diff + Open "different" (kept), Kg skipped', $plan['counts'] === ['set' => 3, 'same' => 1, 'different' => 2, 'change' => 0, 'skip' => 1]
            && $by[$ids['A']]['status'] === 'set' && $by[$ids['C']]['status'] === 'set' && $by[$ids['Same']]['status'] === 'same' && $by[$ids['Diff']]['status'] === 'different' && $by[$ids['Kg']]['status'] === 'skip', json_encode($plan['counts']));
        $ck('preview: the unit-mismatch reason names the item\'s unit ("kg"), and says nothing is converted', strpos($by[$ids['Kg']]['why'], '"kg"') !== false && strpos($by[$ids['Kg']]['why'], 'nothing is converted') !== false, $by[$ids['Kg']]['why']);
        $ck('preview: "Bottles" (plural, capital) is the same word as the bigger unit: set', $by[$ids['C']]['status'] === 'set', json_encode($by[$ids['C']]));
        $ck('preview: Brandy 2.28 bottles shows "2.28 bottle" now and "2 bottles + 7 shots (57 shots)" after', $by[$ids['A']]['qty_now'] === '2.28 bottle' && $by[$ids['A']]['qty_after'] === '2 bottles + 7 shots (57 shots)', json_encode([$by[$ids['A']]['qty_now'], $by[$ids['A']]['qty_after']]));
        $ck('preview: Diff shows its old measure and the new one', strpos($by[$ids['Diff']]['old'], '20 tots in one bottle') === 0 && strpos($by[$ids['Diff']]['new'], '25 shots in one bottle') === 0, json_encode($by[$ids['Diff']]));
        $spC = $spec(['big_name' => 'bottle', 'small_name' => 'shot', 'per_big' => '25', 'side' => 'big', 'change_too' => '1'])['spec'];
        $planC = rh_smb_plan($pdo, $items, $spC);
        $byC = [];
        foreach ($planC['rows'] as $r) { $byC[$r['id']] = $r; }
        $ck('preview with "Change these too": Diff "change"; Open skipped (7 tots on an open count would be read as shots)', $byC[$ids['Diff']]['status'] === 'change' && $byC[$ids['Open']]['status'] === 'skip'
            && strpos($byC[$ids['Open']]['why'], 'open stock counts') !== false, json_encode([$byC[$ids['Diff']]['status'], $byC[$ids['Open']]]));
        $ck('preview: the fingerprint changes with "Change these too"', $plan['fingerprint'] !== $planC['fingerprint'], '');
        $spBig = $spec(['big_name' => 'casket', 'small_name' => 'glass', 'per_big' => '30', 'side' => 'big'])['spec'];
        $planB = rh_smb_plan($pdo, rh_smb_select($pdo, ['mode' => 'ids', 'ids' => $ids['A'] . ',' . $ids['B']])['items'], $spBig);
        $ck('preview: bigger unit "casket" typed, items kept in bottles: both skipped, never converted', $planB['counts']['skip'] === 2 && $planB['counts']['set'] === 0, json_encode($planB['counts']));
        $spSmall = $spec(['big_name' => 'bottle', 'small_name' => 'kg', 'per_big' => '2', 'side' => 'small'])['spec'];
        $planS = rh_smb_plan($pdo, rh_smb_select($pdo, ['mode' => 'ids', 'ids' => $ids['Kg'] . ',' . $ids['A']])['items'], $spSmall);
        $byS = [];
        foreach ($planS['rows'] as $r) { $byS[$r['id']] = $r; }
        $ck('preview: stock kept in the smaller measure "kg": the kg item is set, the bottle item skipped', $byS[$ids['Kg']]['status'] === 'set' && $byS[$ids['A']]['status'] === 'skip', json_encode($planS['counts']));
        $spOwn = $spec(['big_name' => '', 'small_name' => 'shot', 'per_big' => '25', 'side' => 'big'])['spec'];
        $planO = rh_smb_plan($pdo, rh_smb_select($pdo, ['mode' => 'ids', 'ids' => $ids['C'] . ',' . $ids['Kg']])['items'], $spOwn);
        $byO = [];
        foreach ($planO['rows'] as $r) { $byO[$r['id']] = $r; }
        $ck('preview: bigger unit left empty = each item\'s own unit (lower case): "25 shots in one bottles" for Rum C, "in one kg" for Sugar (shown, so it can be unticked)', $planO['counts']['set'] === 2
            && strpos($byO[$ids['C']]['new'], 'in one bottles') !== false && strpos($byO[$ids['Kg']]['new'], 'in one kg') !== false, json_encode([$byO[$ids['C']]['new'], $byO[$ids['Kg']]['new']]));

        // ---- apply (no "Change these too")
        $before = $snap(array_merge($main, $ids['many']));
        $lineBefore = $pdo->query('SELECT actual_quantity, variance, variance_cost FROM stock_count_lines WHERE count_id = ' . (int)$ids['count'])->fetch(PDO::FETCH_ASSOC);
        $logBefore = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_measure_changed' AND details LIKE '%ZZB " . $run . "%'")->fetchColumn();
        $res = rh_smb_apply($pdo, $items, $sp, $plan['fingerprint'], $me);
        $ck('apply: set on 3, changed 0, unchanged 3 (Same, Diff, Open), skipped 1 (Kg) with names', $res['ok'] && count($res['set']) === 3 && count($res['changed']) === 0 && count($res['unchanged']) === 3
            && count($res['skipped']) === 1 && $res['skipped'][0][0] === $P . 'Sugar Kg' && in_array($P . 'Brandy A', $res['set'], true), json_encode([$res['set'], $res['changed'], $res['unchanged'], $res['skipped'], $res['errors']]));
        $mA = $measureOf($ids['A']);
        $mC = $measureOf($ids['C']);
        $ck('apply: Brandy A now bottle / shot x 25, stock kept in the bigger unit; Rum C ("Bottles") gets the typed word "bottle"', $mA && $mA['big'] === 'bottle' && $mA['small'] === 'shot' && abs($mA['n'] - 25) < 1e-9 && $mA['side'] === 'big'
            && $mC && $mC['big'] === 'bottle', json_encode([$mA, $mC]));
        $mD = $measureOf($ids['Diff']);
        $ck('apply: Diff keeps its own measure (20 tots) without "Change these too"', $mD && $mD['small'] === 'tot' && abs($mD['n'] - 20) < 1e-9, json_encode($mD));
        $ck('apply: Kg has no measure', $measureOf($ids['Kg']) === null, '');
        $after = $snap(array_merge($main, $ids['many']));
        $ck('apply: quantities, costs, units and batches of every item are exactly as before', $before === $after, json_encode([$before[0] ?? null, $after[0] ?? null]));
        $lineAfter = $pdo->query('SELECT actual_quantity, variance, variance_cost FROM stock_count_lines WHERE count_id = ' . (int)$ids['count'])->fetch(PDO::FETCH_ASSOC);
        $ck('apply: the open count line typed in tots is untouched', $lineBefore === $lineAfter, json_encode([$lineBefore, $lineAfter]));
        $logAfter = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_measure_changed' AND details LIKE '%ZZB " . $run . "%' AND details LIKE '%set for several items%'")->fetchColumn();
        $ck('apply: one staff-activity row per saved item, like the single save (stock_measure_changed, "set for several items")', $logAfter - $logBefore === 3, ($logAfter - $logBefore) . ' rows');
        $rowA = $pdo->query('SELECT current_quantity, measure_big_name, measure_small_name, measure_per_big, measure_stock_side FROM stock_ingredients WHERE id = ' . (int)$ids['A'])->fetch(PDO::FETCH_ASSOC);
        $ck('rh_stock_qty_words now shows Brandy A (2.28 bottles, 25 shots a bottle) as "2 bottles + 7 shots (57 shots)"', rh_stock_qty_words((float)$rowA['current_quantity'], $rowA, 'bottle') === '2 bottles + 7 shots (57 shots)', rh_stock_qty_words((float)$rowA['current_quantity'], $rowA, 'bottle'));
        $ck('rh_stock_qty_words(2.28, 25 shots a bottle) = "2 bottles + 7 shots (57 shots)"', rh_stock_qty_words(2.28, ['measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => 25, 'measure_stock_side' => 'big'], 'bottle') === '2 bottles + 7 shots (57 shots)', '');

        // ---- the old preview is now out of date: refused, nothing saved (every request reads the items again)
        $items = rh_smb_select($pdo, ['mode' => 'ids', 'ids' => implode(',', $main)])['items'];
        $res2 = rh_smb_apply($pdo, $items, $sp, $plan['fingerprint'], $me);
        $ck('apply again with the old preview: refused ("Something changed since the preview")', !$res2['ok'] && strpos($res2['errors'][0], 'Something changed since the preview') !== false, json_encode($res2['errors']));
        $plan2 = rh_smb_plan($pdo, $items, $sp);
        $ck('preview again: A, B, C now "same" (nothing to do)', $plan2['counts']['same'] === 4 && $plan2['counts']['set'] === 0, json_encode($plan2['counts']));
        $res3 = rh_smb_apply($pdo, $items, $sp, '', $me);
        $ck('apply without a preview fingerprint: refused', !$res3['ok'], json_encode($res3['errors']));

        // ---- "Change these too"
        $planC2 = rh_smb_plan($pdo, $items, $spC);
        $resC = rh_smb_apply($pdo, $items, $spC, $planC2['fingerprint'], $me);
        $mD2 = $measureOf($ids['Diff']);
        $mO2 = $measureOf($ids['Open']);
        $ck('apply with "Change these too": Diff changed to 25 shots; Open skipped and keeps 20 tots; Kg skipped; nothing set again', $resC['ok'] && $resC['changed'] === [$P . 'Whisky Diff'] && $mD2 && $mD2['small'] === 'shot' && $mO2 && $mO2['small'] === 'tot'
            && count($resC['skipped']) === 2 && $resC['set'] === [] && count($resC['unchanged']) === 4, json_encode([$resC['changed'], $resC['skipped'], $resC['errors']]));
        $ck('apply with "Change these too": quantities, costs and batches still exactly as before', $snap(array_merge($main, $ids['many'])) === $before, '');
        $lineAfter2 = $pdo->query('SELECT actual_quantity, variance, variance_cost FROM stock_count_lines WHERE count_id = ' . (int)$ids['count'])->fetch(PDO::FETCH_ASSOC);
        $ck('apply with "Change these too": the open count line is still untouched', $lineBefore === $lineAfter2, json_encode($lineAfter2));

        // ---- someone else changes an item between preview and apply: the fingerprint catches it
        $itemsM = rh_smb_select($pdo, $allMany)['items'];
        $planM = rh_smb_plan($pdo, $itemsM, $sp);
        $pdo->prepare("UPDATE stock_ingredients SET measure_big_name = 'bottle', measure_small_name = 'glass', measure_per_big = 4, measure_stock_side = 'big' WHERE id = ?")->execute([$ids['many'][3]]);
        $resM = rh_smb_apply($pdo, rh_smb_select($pdo, $allMany)['items'], $sp, $planM['fingerprint'], $me);
        $ck('apply: an item changed by someone else after the preview refuses the whole apply (preview again)', !$resM['ok'] && $measureOf($ids['many'][0]) === null, json_encode($resM['errors']));
    } catch (Throwable $e) {
        $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    echo json_encode(['checks' => $checks]);
    return;
}

// ---- page scenarios: build the items, then run the REAL page with the POST the browser sends
$ids = $build();
$base = ['small_name' => 'shot', 'per_big' => '25', 'side' => 'big', 'big_name' => 'bottle', 'bottle_ml' => '', 'measure_ml' => '', 'change_too' => '0'];
$sel = ['mode' => 'ids', 'ids' => implode(',', [$ids['A'], $ids['B'], $ids['C'], $ids['Same'], $ids['Diff'], $ids['Kg']])];
$allMany = ['mode' => 'all', 'q' => '', 'category' => 'ZZB ' . $run . ' Many', 'status' => '', 'list_filter' => '', 'except' => '', 'shown' => '12'];
$fp = function (array $post) use ($pdo): string {
    $s = rh_smb_read_spec($post);
    $i = rh_smb_select($pdo, $post);
    return ($s['ok'] && $i['ok']) ? rh_smb_plan($pdo, $i['items'], $s['spec'])['fingerprint'] : 'none';
};
switch ($scn) {
    case 'page_preview':
        $post = ['action' => 'preview_measure_bulk'] + $base + $sel;
        break;
    case 'page_apply':
        $post = ['action' => 'measure_bulk_apply'] + $base + $sel;
        $post['fingerprint'] = $fp($post);
        break;
    case 'page_all_preview':
        $post = ['action' => 'preview_measure_bulk'] + $base + $allMany;
        break;
    case 'page_all_apply':
        $post = ['action' => 'measure_bulk_apply'] + $base + $allMany;
        $post['fingerprint'] = $fp($post);
        break;
    case 'page_csrf':
        $post = ['action' => 'measure_bulk_apply'] + $base + $sel;
        $post['fingerprint'] = $fp($post);
        $csrfTok = 'wrong-token';
        break;
    case 'page_bad_n':
        $post = ['action' => 'preview_measure_bulk', 'per_big' => '0'] + $base + $sel;
        break;
    default:
        echo "unknown scenario\n";
        exit;
}
$post['csrf_token'] = $csrfTok;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'] = '/admin/stock-ingredients.php';
$_POST = $post;
$_GET = [];
$_REQUEST = $_POST;
chdir($root . '/admin');
require $root . '/admin/stock-ingredients.php';
