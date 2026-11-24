<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Scenarios for scripts/tests/stock_measure_auto.php (Stock Items: fill count measures from bottle sizes, owner 2026-11-24). Run by
 * page_harness.php: ONE outer transaction that can never commit, rolled back at the end. Every row is named 'ZZA <run> ...' (made-up
 * bar items, recipes on made-up menu item ids with menu_type 'zza-<run>'). The hotel's Drink measures list setting is never written:
 * the library scenarios pass their own lists; the page scenarios build their fixture from the live list.
 * POST fields: run, scenario. 'lib' prints {"checks":[[name, ok, detail], ...]}; the page_* scenarios run the REAL page.
 */
if (defined('DB_NAME') && stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    echo json_encode(['checks' => [["refused: Rosalyn's is live, run this from the Liwonde repo", false, '']]]), "\n";
    exit;
}
$run = (string)($_POST['run'] ?? 'X');
$scn = (string)($_POST['scenario'] ?? 'lib');
$csrfTok = (string)($_POST['csrf_token'] ?? '');
$me = (int)($_SESSION['admin_user_id'] ?? 0);
$P = 'ZZA ' . $run . ' ';
$TYPE = 'zza-' . strtolower($run);
$checks = [];
$ck = function (string $n, $c, string $d = '') use (&$checks) { $checks[] = [$n, (bool)$c, $d]; };

require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/stock-measure-auto-lib.php';

$menuId = 990000000 + random_int(0, 8000000);
$mkIng = function (string $tag, string $unit, ?float $vol, float $qty = 2.5, float $cost = 5000, ?array $m = null) use ($pdo, $P): int {
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, volume_ml, measure_big_name, measure_small_name, measure_per_big, measure_stock_side) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$P . $tag, 'ZZA Bar', $unit, $qty, $cost, $vol, $m[0] ?? null, $m[1] ?? null, $m[2] ?? null, $m[3] ?? null]);
    return (int)$pdo->lastInsertId();
};
// one menu item (recipe) per line: quantity in stock units, optional typed measure
$mkLine = function (int $ingId, float $q, ?string $measure = null, ?float $mQty = null, ?float $mMl = null) use ($pdo, &$menuId, $TYPE): void {
    $menuId++;
    $pdo->prepare('INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe) VALUES (?, ?, 1)')->execute([$menuId, $TYPE]);
    $rid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent, entry_measure, entry_qty, entry_ml) VALUES (?, ?, ?, 100, ?, ?, ?)')
        ->execute([$rid, $ingId, round($q, 6), $measure, $mQty, $mMl]);
};
$measureOf = function (int $id) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT measure_big_name, measure_small_name, measure_per_big, measure_stock_side FROM stock_ingredients WHERE id = ?');
    $st->execute([$id]);
    return rh_stock_measure_get((array)$st->fetch(PDO::FETCH_ASSOC));
};
$fixtureItems = function () use ($pdo, $P): array {
    return array_values(array_filter(rh_sma_items($pdo), static fn(array $r): bool => strpos((string)$r['name'], $P) === 0));
};
$byName = function (array $rows) use ($P): array {
    $o = [];
    foreach ($rows as $r) { $o[str_replace($P, '', $r['name'])] = $r; }
    return $o;
};

if ($scn === 'lib') {
    try {
        if (!rh_stock_measure_ready($pdo) || !rh_stock_drink_ready($pdo)) {
            $ck('count measures and bottle sizes are set up on this database (migrations 088 + 089)', false, 'not ready');
            echo json_encode(['checks' => $checks]);
            return;
        }
        $L = [['name' => 'shot', 'ml' => 25.0], ['name' => 'double', 'ml' => 50.0], ['name' => 'glass of wine', 'ml' => 175.0], ['name' => 'cocktail pour', 'ml' => 50.0], ['name' => 'cup', 'ml' => 250.0]];
        $L30 = [['name' => 'shot', 'ml' => 30.0], ['name' => 'double', 'ml' => 60.0], ['name' => 'glass of wine', 'ml' => 175.0]];
        $ids = [];
        $ids['Set'] = $mkIng('Gin Set', 'bottle', 750);                  // typed "1 shot"
        $mkLine($ids['Set'], 25 / 750, 'shot', 1, 25);
        $ids['Same'] = $mkIng('Rum Same', 'bottle', 750, 2.5, 5000, ['bottle', 'shot', 30, 'big']);
        $mkLine($ids['Same'], 25 / 750);                                // size only (the importer's lines)
        $ids['Diff'] = $mkIng('Whisky Diff', 'bottle', 750, 4, 9000, ['bottle', 'tot', 20, 'big']); // hand-set
        $mkLine($ids['Diff'], 25 / 750);
        $ids['NoSize'] = $mkIng('Vodka NoSize', 'bottle', null);
        $mkLine($ids['NoSize'], 0.04);
        $ids['Ml'] = $mkIng('Juice Ml', 'litre', null);
        $mkLine($ids['Ml'], 0.25);
        $ids['Mix'] = $mkIng('Wine Mix', 'bottle', 750);                // 30 ml + 175 ml
        $mkLine($ids['Mix'], 0.04);
        $mkLine($ids['Mix'], 175 / 750);
        $ids['Wine25'] = $mkIng('Wine TwentyFive', 'bottle', 750);      // 25 ml + 175 ml (175 = 7 x 25)
        $mkLine($ids['Wine25'], 25 / 750);
        $mkLine($ids['Wine25'], 175 / 750);
        $ids['Inexact'] = $mkIng('Brandy Inexact', 'bottle', 1000);     // 30 ml shots in a litre bottle
        $mkLine($ids['Inexact'], 0.03);
        $ids['DblShot'] = $mkIng('Tequila DblShot', 'bottle', 750);     // typed double + a size-only shot
        $mkLine($ids['DblShot'], 50 / 750, 'double', 1, 50);
        $mkLine($ids['DblShot'], 25 / 750);
        $ids['TwoShots'] = $mkIng('Liqueur TwoShots', 'bottle', 750);   // typed "2 shots"
        $mkLine($ids['TwoShots'], 50 / 750, 'shot', 2, 25);
        $ids['Open'] = $mkIng('Cognac Open', 'bottle', 750, 2, 7000, ['bottle', 'tot', 20, 'big']);
        $mkLine($ids['Open'], 25 / 750);
        $ids['NoLine'] = $mkIng('Beer NoLine', 'bottle', 330);
        $ids['Whole'] = $mkIng('Champagne Whole', 'bottle', 750);       // sold only as the whole bottle
        $mkLine($ids['Whole'], 1);
        $ids['Kg'] = $mkIng('Sugar Kg', 'kg', null, 3, 1500);
        $mkLine($ids['Kg'], 0.01);
        $ids['Crate'] = $mkIng('Cider Crate', 'crate', 330);
        $mkLine($ids['Crate'], 0.04);
        $ids['Unnamed'] = $mkIng('Sambuca Unnamed', 'bottle', 750);     // 30 ml, not on the list
        $mkLine($ids['Unnamed'], 0.04);
        // an open (draft) count with a line on Open typed in tots
        $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, is_opening, created_at, snapshot_at) VALUES (?, CURDATE(), 'full', 'draft', ?, 0, NOW(), NOW())")
            ->execute(['20991231-ZZA' . $run, $me]);
        $cid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost, reason_code, counted_at, entry_mode, entry_big, entry_small, entry_per_big)
            VALUES (?, ?, 2, 0.35, -1.65, 7000, -11550, '', NOW(), 'small', NULL, 7, 20)")->execute([$cid, $ids['Open']]);

        $items = $fixtureItems();
        $ck('fixture: 16 active ZZA items read by rh_sma_items', count($items) === 16, (string)count($items));
        $plan = rh_sma_plan($pdo, $items, $L);
        $r = $byName($plan['rows']);
        $st = static fn(string $k): string => (string)($r[$k]['status'] ?? 'missing');
        $ck('set: Gin (single measure: typed 1 shot, 750 ml) -> "30 shots in one bottle", ticked, no note', $st('Gin Set') === 'set' && $r['Gin Set']['tick'] === true && $r['Gin Set']['note'] === '' && strpos($r['Gin Set']['new'], '30 shots in one bottle') === 0, json_encode($r['Gin Set'] ?? null));
        $ck('same: Rum already 30 shots a bottle (size-only line) -> nothing to do, not ticked', $st('Rum Same') === 'same' && $r['Rum Same']['tick'] === false, json_encode($r['Rum Same'] ?? null));
        $ck('different: Whisky hand-set 20 tots -> shown UNticked with old and new', $st('Whisky Diff') === 'different' && $r['Whisky Diff']['tick'] === false
            && strpos($r['Whisky Diff']['old'], '20 tots') === 0 && strpos($r['Whisky Diff']['new'], '30 shots') === 0, json_encode($r['Whisky Diff'] ?? null));
        $ck('skip no size: "No bottle size: set Bottle size on the item, then run again"', $st('Vodka NoSize') === 'skip' && $r['Vodka NoSize']['why'] === 'No bottle size: set Bottle size on the item, then run again', json_encode($r['Vodka NoSize'] ?? null));
        $ck('skip ml unit: stock kept in litre', $st('Juice Ml') === 'skip' && strpos($r['Juice Ml']['why'], 'kept in litre') !== false, json_encode($r['Juice Ml'] ?? null));
        $ck('skip weight: stock kept in kg', $st('Sugar Kg') === 'skip' && strpos($r['Sugar Kg']['why'], 'a weight') !== false, json_encode($r['Sugar Kg'] ?? null));
        $ck('skip crate: one crate holds several bottles', $st('Cider Crate') === 'skip' && strpos($r['Cider Crate']['why'], 'holds several bottles') !== false, json_encode($r['Cider Crate'] ?? null));
        $ck('skip mixed (25-ml list): 30 ml + glass of wine are not whole multiples -> "Sold as 30 ml and glass of wine: set it yourself"', $st('Wine Mix') === 'skip'
            && $r['Wine Mix']['why'] === 'Sold as 30 ml and glass of wine: set it yourself', json_encode($r['Wine Mix'] ?? null));
        $ck('shot 25 + glass of wine 175 (7 x 25): set in shots but UNticked, note "Also sold as glass of wine (= 7 shots): check before ticking"', $st('Wine TwentyFive') === 'set'
            && $r['Wine TwentyFive']['tick'] === false && $r['Wine TwentyFive']['note'] === 'Also sold as glass of wine (= 7 shots): check before ticking' && strpos($r['Wine TwentyFive']['new'], '30 shots') === 0, json_encode($r['Wine TwentyFive'] ?? null));
        $ck('double + shot -> shot (the smallest), 30 a bottle, UNticked with "Also sold as double (= 2 shots): check before ticking"', $st('Tequila DblShot') === 'set'
            && $r['Tequila DblShot']['tick'] === false && $r['Tequila DblShot']['note'] === 'Also sold as double (= 2 shots): check before ticking' && strpos($r['Tequila DblShot']['new'], '30 shots') === 0 && $r['Tequila DblShot']['sold'] === 'shot, double', json_encode($r['Tequila DblShot'] ?? null));
        $ck('typed "2 shots" is a single measure (shot) -> shot, 30 a bottle, ticked, no note', $st('Liqueur TwoShots') === 'set' && $r['Liqueur TwoShots']['tick'] === true && $r['Liqueur TwoShots']['note'] === '' && strpos($r['Liqueur TwoShots']['new'], '30 shots') === 0, json_encode($r['Liqueur TwoShots'] ?? null));
        $ck('open-count-lines guard: Cognac (hand-set tots, 1 line on an open count) is skipped with the reason, never offered', $st('Cognac Open') === 'skip' && strpos($r['Cognac Open']['why'], 'open stock counts') !== false && $r['Cognac Open']['tick'] === false, json_encode($r['Cognac Open'] ?? null));
        $ck('skip not sold: no menu link -> "Not sold in a drink measure on any menu item"', $st('Beer NoLine') === 'skip' && $r['Beer NoLine']['why'] === 'Not sold in a drink measure on any menu item', json_encode($r['Beer NoLine'] ?? null));
        $ck('skip not sold: sold only as the whole bottle (a serving named after its own unit is ignored)', $st('Champagne Whole') === 'skip' && $r['Champagne Whole']['why'] === 'Not sold in a drink measure on any menu item', json_encode($r['Champagne Whole'] ?? null));
        $ck('skip: sold in 30 ml, which is not on the Drink measures list', $st('Sambuca Unnamed') === 'skip' && strpos($r['Sambuca Unnamed']['why'], 'Sold in 30 ml, which is not on the Drink measures list') === 0, json_encode($r['Sambuca Unnamed'] ?? null));
        $ck('counts: 4 set, 1 same, 1 different, 10 skip', $plan['counts'] === ['set' => 4, 'same' => 1, 'different' => 1, 'skip' => 10], json_encode($plan['counts']));
        $ck('public rows leave out the internal arrays', !array_key_exists('_new', rh_sma_public_rows($plan['rows'])[0]), '');

        // the 30-ml list: shot + glass of wine -> skip; 1000 ml / 30 ml -> not exact
        $plan30 = rh_sma_plan($pdo, array_values(array_filter($items, static fn($i) => in_array((int)$i['id'], [$ids['Mix'], $ids['Inexact']], true))), $L30);
        $r30 = $byName($plan30['rows']);
        $ck('skip mixed shot + glass of wine (shot 30, glass 175): "Sold as shot and glass of wine: set it yourself"', ($r30['Wine Mix']['status'] ?? '') === 'skip'
            && $r30['Wine Mix']['why'] === 'Sold as shot and glass of wine: set it yourself', json_encode($r30['Wine Mix'] ?? null));
        $ck('skip inexact: 1000 ml / 30 ml shot is not an exact number (never rounded)', ($r30['Brandy Inexact']['status'] ?? '') === 'skip' && strpos($r30['Brandy Inexact']['why'], 'not an exact number') !== false, json_encode($r30['Brandy Inexact'] ?? null));
        $ck('Brandy under the 25-ml list: 30 ml is not on it -> skipped with that reason', $st('Brandy Inexact') === 'skip' && strpos($r['Brandy Inexact']['why'], 'not on the Drink measures list') !== false, json_encode($r['Brandy Inexact'] ?? null));
        $ck('a litre (1000 ml) bottle sold in 25 ml shots works out (no "1,000" refused)', rh_sma_candidate(['unit' => 'bottle', 'volume_ml' => 1000, 'name' => 'x'], [['quantity_per_portion' => 0.025]], $L)['n'] === 40.0, '');

        $c3 = rh_sma_candidate(['unit' => 'bottle', 'volume_ml' => 750, 'name' => 'x'], [['quantity_per_portion' => 25 / 750], ['quantity_per_portion' => 50 / 750], ['quantity_per_portion' => 175 / 750]], $L);
        $ck('three measures: note "Also sold as double (= 2 shots) and glass of wine (= 7 shots): check before ticking"', $c3['ok'] && $c3['small'] === 'shot'
            && $c3['note'] === 'Also sold as double (= 2 shots) and glass of wine (= 7 shots): check before ticking', json_encode($c3));

        // ---- apply: tick Gin, Tequila, Liqueur; leave Wine TwentyFive (set) and Whisky Diff (different) unticked
        $snapSql = "SELECT id, current_quantity, cost_per_unit, unit, volume_ml FROM stock_ingredients WHERE name LIKE 'ZZA " . $run . " %' ORDER BY id";
        $before = $pdo->query($snapSql)->fetchAll(PDO::FETCH_ASSOC);
        $recipeSql = "SELECT sri.id, sri.quantity_per_portion, sri.entry_measure, sri.entry_qty FROM stock_recipe_ingredients sri JOIN stock_recipes sr ON sr.id = sri.recipe_id WHERE sr.menu_type = '" . $TYPE . "' ORDER BY sri.id";
        $recBefore = $pdo->query($recipeSql)->fetchAll(PDO::FETCH_ASSOC);
        $logBefore = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_measure_changed' AND details LIKE '%ZZA " . $run . "%'")->fetchColumn();
        $res = rh_sma_apply($pdo, $items, $L, $plan['fingerprint'], implode(',', [$ids['Set'], $ids['DblShot'], $ids['TwoShots']]), $me);
        $ck('apply: 3 ticked set, 0 changed; unticked rows unchanged', $res['ok'] && count($res['set']) === 3 && $res['changed'] === [] && in_array($P . 'Wine TwentyFive', $res['unchanged'], true)
            && in_array($P . 'Whisky Diff', $res['unchanged'], true), json_encode([$res['set'], $res['changed'], $res['errors']]));
        $mG = $measureOf($ids['Set']);
        $ck('apply: Gin now bottle / shot x 30, stock kept in the bottle', $mG && $mG['big'] === 'bottle' && $mG['small'] === 'shot' && abs($mG['n'] - 30) < 1e-9 && $mG['side'] === 'big', json_encode($mG));
        $ck('apply: an unticked "set" row is not saved (Wine TwentyFive has no measure)', $measureOf($ids['Wine25']) === null, '');
        $mD = $measureOf($ids['Diff']);
        $ck('hand-set never changed when not ticked: Whisky keeps 20 tots', $mD && $mD['small'] === 'tot' && abs($mD['n'] - 20) < 1e-9, json_encode($mD));
        $ck('apply: quantities, costs, units and bottle sizes exactly as before', $pdo->query($snapSql)->fetchAll(PDO::FETCH_ASSOC) === $before, '');
        $ck('apply: recipe lines unchanged', $pdo->query($recipeSql)->fetchAll(PDO::FETCH_ASSOC) === $recBefore, '');
        $logAfter = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_measure_changed' AND details LIKE '%ZZA " . $run . "%' AND details LIKE '%filled from bottle sizes%'")->fetchColumn();
        $ck('apply: one staff-activity row per saved item ("filled from bottle sizes")', $logAfter - $logBefore === 3, ($logAfter - $logBefore) . ' rows');

        // ---- stale preview refused, nothing saved
        $items = $fixtureItems();
        $res2 = rh_sma_apply($pdo, $items, $L, $plan['fingerprint'], (string)$ids['Wine25'], $me);
        $ck('stale fingerprint refused (the old preview), nothing saved', !$res2['ok'] && strpos($res2['errors'][0] ?? '', 'Something changed since the preview') !== false && $measureOf($ids['Wine25']) === null, json_encode($res2['errors']));
        $planB = rh_sma_plan($pdo, $items, $L);
        $pdo->prepare('UPDATE stock_ingredients SET volume_ml = 700 WHERE id = ?')->execute([$ids['Wine25']]);
        $res3 = rh_sma_apply($pdo, $fixtureItems(), $L, $planB['fingerprint'], (string)$ids['Wine25'], $me);
        $ck('stale fingerprint refused (a bottle size changed after the preview), nothing saved', !$res3['ok'] && $measureOf($ids['Wine25']) === null, json_encode($res3['errors']));
        $pdo->prepare('UPDATE stock_ingredients SET volume_ml = 750 WHERE id = ?')->execute([$ids['Wine25']]);
        $res4 = rh_sma_apply($pdo, $fixtureItems(), $L, $planB['fingerprint'], '', $me);
        $ck('apply with nothing ticked: refused', !$res4['ok'] && ($res4['errors'][0] ?? '') === 'No items are ticked.', json_encode($res4['errors']));

        // ---- ticks on rows that cannot be saved are refused as a whole
        $items = $fixtureItems();
        $planC = rh_sma_plan($pdo, $items, $L);
        $res5 = rh_sma_apply($pdo, $items, $L, $planC['fingerprint'], $ids['Wine25'] . ',' . $ids['Same'], $me);
        $ck('a ticked "same" row refuses the whole apply (nothing saved)', !$res5['ok'] && $measureOf($ids['Wine25']) === null, json_encode($res5['errors']));
        $res6 = rh_sma_apply($pdo, $items, $L, $planC['fingerprint'], (string)$ids['Open'], $me);
        $mO = $measureOf($ids['Open']);
        $ck('a ticked open-count row (skip) is refused; Cognac keeps 20 tots', !$res6['ok'] && $mO && $mO['small'] === 'tot', json_encode($res6['errors']));

        // ---- ticking a "different" row changes it
        $res7 = rh_sma_apply($pdo, $items, $L, $planC['fingerprint'], (string)$ids['Diff'], $me);
        $mD2 = $measureOf($ids['Diff']);
        $ck('ticked "different" row: Whisky changed to 30 shots; Wine TwentyFive (unticked) still none', $res7['ok'] && $res7['changed'] === [$P . 'Whisky Diff'] && $mD2 && $mD2['small'] === 'shot'
            && abs($mD2['n'] - 30) < 1e-9 && $measureOf($ids['Wine25']) === null, json_encode([$res7['changed'], $res7['errors'], $mD2]));
        $ck('the open count line is untouched', (string)$pdo->query('SELECT actual_quantity FROM stock_count_lines WHERE count_id = ' . $cid)->fetchColumn() === '0.350000'
            || abs((float)$pdo->query('SELECT actual_quantity FROM stock_count_lines WHERE count_id = ' . $cid)->fetchColumn() - 0.35) < 1e-9, '');

        // ---- column text
        $ck('column: "30 shots / bottle" from the count measure; null without one; "4 glasses of wine / bottle"', rh_sma_column_text(['measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => 30, 'measure_stock_side' => 'big']) === '30 shots / bottle'
            && rh_sma_column_text(['unit' => 'bottle']) === null
            && rh_sma_column_text(['measure_big_name' => 'bottle', 'measure_small_name' => 'glass of wine', 'measure_per_big' => 4, 'measure_stock_side' => 'big']) === '4 glasses of wine / bottle',
            (string)rh_sma_column_text(['measure_big_name' => 'bottle', 'measure_small_name' => 'glass of wine', 'measure_per_big' => 4, 'measure_stock_side' => 'big']));
    } catch (Throwable $e) {
        $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    echo json_encode(['checks' => $checks]);
    return;
}

// ---- page scenarios: a fixture sold in the live list's smallest measure, then the REAL page with the POST the browser sends
$list = rh_drink_measures_list();
usort($list, static fn($a, $b) => $a['ml'] <=> $b['ml']);
if (!$list) {
    echo json_encode(['ok' => false, 'errors' => ['TEST: the Drink measures list on this database is empty']]);
    exit;
}
$m0 = $list[0];
$pageId = $mkIng('Page Gin', 'bottle', (float)$m0['ml'] * 30);
$mkLine($pageId, 1 / 30);
$post = [];
switch ($scn) {
    case 'page_preview':
        $post = ['action' => 'preview_measure_auto'];
        break;
    case 'page_apply':
    case 'page_csrf':
        $post = ['action' => 'measure_auto_apply', 'ticked' => (string)$pageId,
            'fingerprint' => rh_sma_plan($pdo, rh_sma_items($pdo), rh_drink_measures_list())['fingerprint']];
        if ($scn === 'page_csrf') {
            $csrfTok = 'wrong-token';
        }
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
