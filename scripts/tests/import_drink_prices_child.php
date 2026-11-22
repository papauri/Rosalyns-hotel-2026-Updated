<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Scenarios for scripts/tests/import_drink_prices.php (run by page_harness.php: ONE outer transaction that can never commit and is
 * rolled back at the end). Prints one JSON line: {"checks":[[name, ok, detail], ...]}. Every row is named 'ZZD <run> ...'.
 */
if (defined('DB_NAME') && stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    echo json_encode(['checks' => [["refused: Rosalyn's is live, run this from the Liwonde repo", false, '']]]), "\n";
    exit;
}
$run = (string)($_POST['run'] ?? 'X');
$checks = [];
$ck = function (string $n, $c, string $d = '') use (&$checks) { $checks[] = [$n, (bool)$c, $d]; };
$made = [];
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'import_drinks_' . getmypid();
@mkdir($tmp);
$tmpFiles = [];

try {
    require_once $root . '/admin/includes/permissions.php';
    require_once $root . '/includes/import/commit-stock.php';
    $n = function (string $s) use ($run) { return 'ZZD ' . $run . ' ' . $s; };
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('data_import_enabled', '1', 'import') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
    $admin = (int)($_SESSION['admin_user_id'] ?? 0);
    $mkUser = function (string $role) use ($pdo, $n) {
        $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, ?, 1)")
            ->execute([substr($n($role), 0, 90), strtolower(preg_replace('/\W+/', '', $n($role))) . '@example.invalid', $n($role), $role]);
        return (int)$pdo->lastInsertId();
    };
    $mgrNoMenu = $mkUser('manager');
    $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'menu', 0)")->execute([$mgrNoMenu]);
    $file = function (array $head, array $rows, string $name) use ($tmp, &$tmpFiles) {
        $p = $tmp . DIRECTORY_SEPARATOR . count($tmpFiles) . '_' . $name;
        $fh = fopen($p, 'w');
        fputcsv($fh, $head);
        foreach ($rows as $r) { fputcsv($fh, $r); }
        fclose($fh);
        $tmpFiles[] = $p;
        return $p;
    };
    $start = function (string $path, string $name) use ($pdo, $admin, &$made) {
        $r = import_job_create($pdo, $path, $name, $admin, ['from_upload' => false]);
        if (empty($r['ok'])) { throw new RuntimeException('upload: ' . ($r['error'] ?? '?')); }
        $made[] = import_job_get($pdo, $r['job_id'])['stored_path'];
        $an = import_job_analyse($pdo, (int)$r['job_id']);
        if (empty($an['ok'])) { throw new RuntimeException('analyse: ' . json_encode($an)); }
        for ($i = 0; $i < 40; $i++) {           // answer every open blocking question the safe way
            $s = import_job_review_summary($pdo, (int)$r['job_id']);
            $open = null;
            foreach ($s['questions'] as $q) { if ($q['blocking'] && $q['answer'] === null && $q['options']) { $open = $q; break; } }
            if (!$open) { break; }
            $keys = array_column($open['options'], 'key');
            $pick = $keys[0];
            foreach (['per-unit', 'keep'] as $pref) { if (in_array($pref, $keys, true)) { $pick = $pref; } }
            import_job_answer($pdo, (int)$r['job_id'], $open['id'], $pick, $admin);
        }
        return (int)$r['job_id'];
    };
    $view = function (int $jid, int $uid) use ($pdo) {
        $v = import_job_drinks_view($pdo, $jid, $uid, ['size' => 100]);
        $by = [];
        foreach ((array)($v['rows'] ?? []) as $r) { $by[$r['name']] = $r; }
        return [$v, $by];
    };
    $col = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
    $seedStock = function (int $ingId, float $qty) use ($pdo) {   // test-only stock on hand (rolled back), the same way the menu tests seed it
        $pdo->prepare('UPDATE stock_ingredients SET current_quantity = ? WHERE id = ?')->execute([$qty, $ingId]);
        $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status) VALUES (?, ?, ?, ?, 0, CURDATE(), 'active')")
            ->execute([$ingId, 'ZZD-' . $ingId, $qty, $qty]);
    };

    // ---- made-up starting point: one drink already on the menu (no stock link yet), one spirit with its own count measure ----------
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'ZZD', 'bottle', 0, 1000)")->execute([$n('Lager')]);
    $lagerIng = (int)$pdo->lastInsertId();
    $lagerCat = $n('Beers');
    $pdo->prepare("INSERT INTO drink_menu (item_name, description, price, category, is_available, display_order, tags, station, show_pos, show_room_service) VALUES (?, '', '2000.00', ?, 1, 1, '', 'bar', 1, 1)")
        ->execute([$n('Lager'), $lagerCat]);
    $lagerMenu = (int)$pdo->lastInsertId();
    rh_menu_pos_sync_item($pdo, 'drink', ['item_name' => $n('Lager'), 'description' => '', 'price' => '2000.00', 'category' => $lagerCat, 'station' => 'bar',
        'is_available' => 1, 'display_order' => 1, 'show_pos' => 1, 'show_room_service' => 1, 'tags' => ''], null, null);
    $measureOn = function_exists('rh_stock_measure_ready') && rh_stock_measure_ready($pdo);
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'ZZD', 'bottle', 0, 20000)")->execute([$n('Gin')]);
    $ginIng = (int)$pdo->lastInsertId();
    if ($measureOn) {
        $pdo->prepare("UPDATE stock_ingredients SET measure_big_name = 'bottle', measure_small_name = 'shot', measure_per_big = 25, measure_stock_side = 'big' WHERE id = ?")->execute([$ginIng]);
    }

    // ---- file A: a category column and a selling price column ------------------------------------------------------------------
    $head = ['Item Name', 'Category', 'Unit', 'Unit Price', 'Balance', 'Selling Price', 'Served By'];
    $jA = $start($file($head, [
        [$n('Cola'), $n('Softs'), 'bottle', '500', '10', '1234.50', ''],
        [$n('Lager'), $n('Softs'), 'bottle', '1000', '5', '2500', ''],
        [$n('Cheap'), $n('Softs'), 'bottle', '3000', '5', '2000', ''],
        [$n('Rice'), $n('Food'), 'kg', '1500', '5', '', ''],
        [$n('Water'), $n('Softs'), 'bottle', '300', '5', '', ''],
        [$n('Gin'), $n('Spirits'), 'bottle', '20000', '2', '1500', $measureOn ? 'shot' : ''],
    ], 'a.csv'), 'zzd-a-' . $run . '.csv');
    [$vA, $A] = $view($jA, $admin);
    $ck('list: only items with a selling price (or a served-by cell) are offered; no price and nothing else = not listed (Rice, Water)', $vA['ok'] && isset($A[$n('Cola')], $A[$n('Lager')], $A[$n('Cheap')], $A[$n('Gin')]) && !isset($A[$n('Rice')]) && !isset($A[$n('Water')]), json_encode(array_keys($A)) . ' ' . json_encode($vA['error'] ?? ''));
    $ck('list: every row is unticked by default', array_sum(array_map(function ($r) { return $r['ticked'] ? 1 : 0; }, $A)) === 0 && ($vA['counts']['ticked'] ?? -1) === 0);
    $co = $A[$n('Cola')] ?? [];
    $ck('list: the price is the sheet\'s cell exactly (VAT included, nothing added) with its cell', abs((float)($co['price'] ?? 0) - 1234.5) < 0.000001 && preg_match('/F2$/', (string)($co['price_source'] ?? '')) === 1, json_encode($co));
    $ck('list: category comes from the sheet\'s category column', ($co['category'] ?? '') === $n('Softs') && ($co['category_from'] ?? '') === 'column', json_encode($co));
    $ck('list: a new drink with no "served by" is one main unit; cost of one 500, profit 734.50', ($co['action'] ?? '') === 'add' && ($co['measure'] ?? '') === '1 bottle' && abs((float)$co['cost'] - 500) < 0.0001 && abs((float)$co['profit'] - 734.5) < 0.0001, json_encode($co));
    $ch = $A[$n('Cheap')] ?? [];
    $ck('list: selling below cost is warned, and can still be ticked', !empty($ch['below']) && (float)$ch['profit'] < 0 && !empty($ch['tickable']), json_encode($ch));
    $la = $A[$n('Lager')] ?? [];
    $ck('list: a drink already on the menu shows Update price 2000 -> 2500 and keeps its menu category', ($la['action'] ?? '') === 'update' && abs((float)$la['old_price'] - 2000) < 0.0001 && ($la['menu_id'] ?? 0) === $lagerMenu && ($la['category'] ?? '') === $lagerCat, json_encode($la));
    if ($measureOn) {
        $gi = $A[$n('Gin')] ?? [];
        $ck('list: a spirit sold by the shot costs one shot (20000 / 25 = 800)', ($gi['measure'] ?? '') === '1 shot' && abs((float)$gi['cost'] - 800) < 0.0001 && abs((float)$gi['profit'] - 700) < 0.0001, json_encode($gi));
    }

    // ---- permission: ticking needs the Menu permission --------------------------------------------------------------------------
    $rNo = import_job_drinks_set($pdo, $jA, $mgrNoMenu, ['mode' => 'tick', 'key' => $co['key'] ?? '', 'on' => true]);
    [$vNo] = $view($jA, $mgrNoMenu);
    $ck('permission: without the Menu permission a drink cannot be ticked (and the list says so)', empty($rNo['ok']) && stripos((string)$rNo['error'], 'Menu permission') !== false && empty($vNo['can_menu']), json_encode($rNo));

    // ---- category: per row, set for all; an update row never moves --------------------------------------------------------------
    $rAll = import_job_drinks_set($pdo, $jA, $admin, ['mode' => 'category_all', 'value' => $n('Mixers')]);
    [, $A2] = $view($jA, $admin);
    $ck('category: "set for all" changes every drink that would be added, not one already on the menu', !empty($rAll['ok']) && ($A2[$n('Cola')]['category'] ?? '') === $n('Mixers') && ($A2[$n('Cheap')]['category'] ?? '') === $n('Mixers') && ($A2[$n('Lager')]['category'] ?? '') === $lagerCat, json_encode($rAll));
    $rOne = import_job_drinks_set($pdo, $jA, $admin, ['mode' => 'category', 'key' => $co['key'], 'value' => $n('Softs')]);
    $rUp = import_job_drinks_set($pdo, $jA, $admin, ['mode' => 'category', 'key' => $la['key'], 'value' => $n('Softs')]);
    [, $A3] = $view($jA, $admin);
    $ck('category: one row can be changed; a drink already on the menu keeps its category (refused)', !empty($rOne['ok']) && ($A3[$n('Cola')]['category'] ?? '') === $n('Softs') && empty($rUp['ok']), json_encode([$rOne, $rUp]));

    // ---- tick all / untick all: the list as shown (search + Show filter, every page) ---------------------------------------------
    $tickedNow = function ($rows) { return array_keys(array_filter($rows, function ($r) { return !empty($r['ticked']); })); };
    $rTaNo = import_job_drinks_set($pdo, $jA, $mgrNoMenu, ['mode' => 'tick_all', 'on' => true]);
    $ck('tick all: refused without the Menu permission', empty($rTaNo['ok']) && stripos((string)$rTaNo['error'], 'Menu permission') !== false, json_encode($rTaNo));
    $rTaAdd = import_job_drinks_set($pdo, $jA, $admin, ['mode' => 'tick_all', 'on' => true, 'show' => 'add']);
    [, $AT] = $view($jA, $admin);
    $wantAdd = $measureOn ? [$n('Cola'), $n('Cheap'), $n('Gin')] : [$n('Cola'), $n('Cheap')];
    $gotAdd = $tickedNow($AT); sort($gotAdd); sort($wantAdd);
    $ck('tick all: Show "New on the menu" ticks only the new drinks (not the price change)', !empty($rTaAdd['ok']) && $gotAdd === $wantAdd && (int)($rTaAdd['changed'] ?? 0) === count($wantAdd), json_encode([$rTaAdd['changed'] ?? null, $gotAdd]));
    $rTaQ = import_job_drinks_set($pdo, $jA, $admin, ['mode' => 'tick_all', 'on' => false, 'q' => $n('Cheap')]);
    [, $AT2] = $view($jA, $admin);
    $ck('untick all: a search unticks only the drinks it finds', !empty($rTaQ['ok']) && (int)($rTaQ['changed'] ?? 0) === 1 && empty($AT2[$n('Cheap')]['ticked']) && !empty($AT2[$n('Cola')]['ticked']), json_encode($rTaQ));
    $rTaAll = import_job_drinks_set($pdo, $jA, $admin, ['mode' => 'tick_all', 'on' => true]);
    [$vTa, $AT3] = $view($jA, $admin);
    $ck('tick all: no filter ticks every drink that can be ticked (Lager price change and Cheap below cost too)', !empty($rTaAll['ok']) && ($vTa['counts']['ticked'] ?? 0) === ($measureOn ? 4 : 3)
        && !empty($AT3[$n('Lager')]['ticked']) && !empty($AT3[$n('Cheap')]['ticked']), json_encode($vTa['counts'] ?? []));
    $rTaAgain = import_job_drinks_set($pdo, $jA, $admin, ['mode' => 'tick_all', 'on' => true]);
    $ck('tick all: when everything is already ticked it says so', empty($rTaAgain['ok']) && stripos((string)$rTaAgain['error'], 'already ticked') !== false, json_encode($rTaAgain));
    $rUa = import_job_drinks_set($pdo, $jA, $admin, ['mode' => 'tick_all', 'on' => false]);
    [$vUa] = $view($jA, $admin);
    $ck('untick all: every drink is unticked again', !empty($rUa['ok']) && ($vUa['counts']['ticked'] ?? -1) === 0, json_encode($vUa['counts'] ?? []));

    // ---- ticks ------------------------------------------------------------------------------------------------------------------
    foreach (['Cola', 'Lager', 'Cheap'] + ($measureOn ? [3 => 'Gin'] : []) as $nm) {
        $r = import_job_drinks_set($pdo, $jA, $admin, ['mode' => 'tick', 'key' => $A[$n($nm)]['key'], 'on' => true]);
        if (empty($r['ok'])) { $ck("tick $nm", false, json_encode($r)); }
    }
    [$vT] = $view($jA, $admin);
    $ck('ticks: saved on the job (counts: ticked, to add, to update)', ($vT['counts']['ticked'] ?? 0) === ($measureOn ? 4 : 3) && ($vT['counts']['update'] ?? 0) === 1, json_encode($vT['counts'] ?? []));

    // ---- import refused for a person without the Menu permission: nothing at all is written ------------------------------------
    $preIng = (int)$col("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ['ZZD ' . $run . ' %']);
    $cNo = import_commit_stock($pdo, $jA, $mgrNoMenu);
    $ck('permission: importing ticked drinks without the Menu permission is refused, nothing written', empty($cNo['ok']) && stripos((string)$cNo['error'], 'Menu permission') !== false
        && (int)$col("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ['ZZD ' . $run . ' %']) === $preIng
        && (int)$col('SELECT COUNT(*) FROM drink_menu WHERE item_name = ?', [$n('Cola')]) === 0
        && abs((float)$col('SELECT price FROM drink_menu WHERE id = ?', [$lagerMenu]) - 2000) < 0.0001, json_encode($cNo));

    // ---- import: the ticked rows go on the menu, the till and the stock link -----------------------------------------------------
    $cA = import_commit_stock($pdo, $jA, $admin);
    $resA = $cA['result'] ?? [];
    $ck('import: ok, drinks added and updated are reported', !empty($cA['ok']) && ($resA['drinks_added'] ?? -1) === ($measureOn ? 3 : 2) && ($resA['drinks_updated'] ?? -1) === 1, json_encode($cA));
    $cm = $pdo->prepare('SELECT * FROM drink_menu WHERE item_name = ?');
    $cm->execute([$n('Cola')]);
    $colaMenu = $cm->fetchAll(PDO::FETCH_ASSOC);
    $ck('add: Cola is on the drinks menu once, at 1234.50 exactly (no VAT added), in its category, station bar, available', count($colaMenu) === 1 && (string)$colaMenu[0]['price'] === '1234.50'
        && $colaMenu[0]['category'] === $n('Softs') && $colaMenu[0]['station'] === 'bar' && (int)$colaMenu[0]['is_available'] === 1, json_encode($colaMenu));
    $pos = rh_menu_pos_find($pdo, $n('Cola'), $n('Softs'));
    $ck('add: Cola is on the till at the same price', $pos && abs((float)$col('SELECT price FROM menu_items WHERE id = ?', [$pos['id']]) - 1234.5) < 0.000001, json_encode($pos));
    $colaIng = (int)$col('SELECT id FROM stock_ingredients WHERE name = ?', [$n('Cola')]);
    $link = $pdo->prepare('SELECT sri.ingredient_id, sri.quantity_per_portion FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id WHERE sr.menu_item_id = ? AND sr.menu_type = ?');
    $link->execute([$pos['id'] ?? 0, $pos['slug'] ?? '']);
    $lk = $link->fetchAll(PDO::FETCH_ASSOC);
    $ck('add: the stock link is one line, one bottle of the new Cola stock item', count($lk) === 1 && (int)$lk[0]['ingredient_id'] === $colaIng && abs((float)$lk[0]['quantity_per_portion'] - 1) < 0.000001, json_encode($lk));
    $ck('update: Lager\'s price is now 2500.00, still one row, still in its own category (no duplicate)', (string)$col('SELECT price FROM drink_menu WHERE id = ?', [$lagerMenu]) === '2500.00'
        && (int)$col('SELECT COUNT(*) FROM drink_menu WHERE item_name = ?', [$n('Lager')]) === 1 && (string)$col('SELECT category FROM drink_menu WHERE id = ?', [$lagerMenu]) === $lagerCat
        && (int)$col('SELECT COUNT(*) FROM menu_items WHERE item_name = ?', [$n('Lager')]) === 1);
    $lpos = rh_menu_pos_find($pdo, $n('Lager'), $lagerCat);
    $ck('update: the till price changed too, and the Lager got its one-bottle stock link', $lpos && abs((float)$col('SELECT price FROM menu_items WHERE id = ?', [$lpos['id']]) - 2500) < 0.0001
        && (int)$col('SELECT COUNT(*) FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id WHERE sr.menu_item_id = ? AND sr.menu_type = ? AND sri.ingredient_id = ?', [$lpos['id'], $lpos['slug'], $lagerIng]) === 1);
    $log = $pdo->prepare("SELECT details FROM admin_activity_log WHERE action = 'drink_menu_import' AND details LIKE ? ORDER BY id DESC LIMIT 1");
    $log->execute(['Import #' . $jA . ':%' . $n('Lager') . '%']);
    $ld = (string)$log->fetchColumn();
    $ck('history: the price change is logged who/when/old -> new (2000.00 to 2500.00)', strpos($ld, 'from 2000.00 to 2500.00') !== false, $ld);
    $ck('history: one log line per drink written', (int)$col("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'drink_menu_import' AND details LIKE ?", ['Import #' . $jA . ':%']) === ($measureOn ? 4 : 3));

    // ---- one sale takes exactly one measure off stock ------------------------------------------------------------------------------
    $seedStock($colaIng, 10);
    deductStockForMenuItem((int)$pos['id'], (string)$pos['slug'], 1.0, 'manual', null, $admin);
    $ck('sale: one Cola sold takes exactly 1 bottle off stock (10 -> 9)', abs((float)$col('SELECT current_quantity FROM stock_ingredients WHERE id = ?', [$colaIng]) - 9) < 0.000001);
    if ($measureOn) {
        $gpos = rh_menu_pos_find($pdo, $n('Gin'), $n('Mixers'));
        $seedStock($ginIng, 2);
        deductStockForMenuItem((int)($gpos['id'] ?? 0), (string)($gpos['slug'] ?? ''), 1.0, 'manual', null, $admin);
        $ck('sale: one Gin shot sold takes exactly 1/25 bottle off stock (2 -> 1.96)', $gpos && abs((float)$col('SELECT current_quantity FROM stock_ingredients WHERE id = ?', [$ginIng]) - 1.96) < 0.000001,
            json_encode([$gpos, $col('SELECT current_quantity FROM stock_ingredients WHERE id = ?', [$ginIng])]));
    }

    // ---- file B: no category column, nothing ticked -> nothing on the menu; then "Bar" when ticked ------------------------------
    $headB = ['Item Name', 'Unit', 'Unit Price', 'Balance', 'Selling Price'];
    $jB = $start($file($headB, [[$n('Tonic'), 'can', '400', '6', '900'], [$n('Soda'), 'can', '350', '6', '800']], 'b.csv'), 'zzd-b-' . $run . '.csv');
    // nothing in this file says which menu (no category, no served-by): never guessed, the person answers once for the group; Assistant off
    [, $B0] = $view($jB, $admin);
    $tB0 = import_job_drinks_set($pdo, $jB, $admin, ['mode' => 'tick', 'key' => $B0[$n('Tonic')]['key'] ?? '', 'on' => true]);
    $aiOffB = import_job_sizes_ai_fill($pdo, $jB, null, false);
    $gB = (string)($B0[$n('Tonic')]['group'] ?? '');
    $rGB = import_job_drinks_set($pdo, $jB, $admin, ['mode' => 'group_menu', 'group' => $gB, 'value' => 'drink']);
    [, $B] = $view($jB, $admin);
    $ck('menu: uncertain rows (no category, no served-by) say "Choose menu" and cannot be ticked; with the Assistant off nothing is asked; one group answer makes both tickable',
        ($B0[$n('Tonic')]['status'] ?? '') === 'menu' && empty($B0[$n('Tonic')]['tickable']) && empty($tB0['ok']) && stripos((string)($tB0['error'] ?? ''), 'Drinks or Food') !== false
        && $aiOffB === 0 && !empty($rGB['ok']) && ($B[$n('Tonic')]['menu'] ?? '') === 'drink' && ($B[$n('Tonic')]['menu_from'] ?? '') === 'group'
        && !empty($B[$n('Tonic')]['tickable']) && !empty($B[$n('Soda')]['tickable']), json_encode([$B0[$n('Tonic')]['status'] ?? null, $tB0, $rGB, $B[$n('Tonic')]['menu_from'] ?? null]));
    $ck('category: with no category column the drink goes to "Bar"', mb_strtolower((string)($B[$n('Tonic')]['category'] ?? '')) === 'bar' && ($B[$n('Tonic')]['category_from'] ?? '') === 'default', json_encode($B[$n('Tonic')] ?? null));
    import_job_drinks_set($pdo, $jB, $admin, ['mode' => 'tick', 'key' => $B[$n('Soda')]['key'], 'on' => true]);
    $menuBefore = (int)$col('SELECT COUNT(*) FROM drink_menu');
    $tillBefore = (int)$col('SELECT COUNT(*) FROM menu_items');
    $cB = import_commit_stock($pdo, $jB, $admin);
    $ck('unticked: Tonic (not ticked) changed nothing on the menu or the till; only the ticked Soda was added', !empty($cB['ok']) && (int)$col('SELECT COUNT(*) FROM drink_menu WHERE item_name = ?', [$n('Tonic')]) === 0
        && (int)$col('SELECT COUNT(*) FROM menu_items WHERE item_name = ?', [$n('Tonic')]) === 0 && (int)$col('SELECT COUNT(*) FROM drink_menu') === $menuBefore + 1
        && (int)$col('SELECT COUNT(*) FROM menu_items') === $tillBefore + 1 && mb_strtolower((string)$col('SELECT category FROM drink_menu WHERE item_name = ?', [$n('Soda')])) === 'bar', json_encode($cB));

    // ---- file C: nothing ticked at all -> the menu is untouched, no Menu permission needed -------------------------------------
    $jC = $start($file($headB, [[$n('Juice'), 'bottle', '600', '4', '1500']], 'c.csv'), 'zzd-c-' . $run . '.csv');
    $cC = import_commit_stock($pdo, $jC, $mgrNoMenu);
    $ck('unticked: an import with no drink ticked writes nothing to the menu, and needs no Menu permission', !empty($cC['ok']) && (int)$col('SELECT COUNT(*) FROM drink_menu WHERE item_name = ?', [$n('Juice')]) === 0
        && (int)$col('SELECT COUNT(*) FROM menu_items WHERE item_name = ?', [$n('Juice')]) === 0 && ($cC['result']['drinks_added'] ?? -1) === 0, json_encode($cC));

    // ---- file D: bottle sizes in step 3 (optional, owner 2026-11-20): asked per group, a different one per row, never guessed ----------
    $shotMl = null;
    foreach (rh_drink_measures_list() as $dm) { if (mb_strtolower($dm['name']) === 'shot') { $shotMl = (float)$dm['ml']; } }
    if (!rh_stock_drink_ready($pdo) || $shotMl === null) {
        $ck('sizes: skipped (this database has no bottle size column or no "shot" on its Drink measures list)', true);
    } else {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'ZZD', 'bottle', 0, 15000)")->execute([$n('Vodka')]);
        $vodIng = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, volume_ml) VALUES (?, 'ZZD', 'bottle', 0, 18000, 700)")->execute([$n('Rum')]);
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'ZZD', 'bottle', 0, 9000)")->execute([$n('Tequila')]);
        $teqIng = (int)$pdo->lastInsertId();
        $headD = ['Item Name', 'Unit', 'Unit Price', 'Balance', 'Selling Price', 'Served By'];
        $jD = $start($file($headD, [
            [$n('Vodka'), 'bottle', '15000', '2', '1500', 'Shot'],
            [$n('Rum'), 'bottle', '18000', '2', '1800', 'Per shot'],
            [$n('Whisky'), 'bottle', '30000', '2', '2500', 'shots'],
            [$n('Brandy 750ml'), 'bottle', '12000', '2', '1200', 'Shot'],
            [$n('Red Wine'), 'bottle', '15000', '2', '3000', 'Glass 175ml'],
            [$n('Tequila'), 'bottle', '9000', '2', '1000', 'Shot'],
        ], 'd.csv'), 'zzd-d-' . $run . '.csv');
        $sizes = function () use ($pdo, $jD, $admin) {
            $v = import_job_sizes_view($pdo, $jD, $admin, ['size' => 100, 'ai' => false]);
            $by = [];
            foreach ((array)($v['rows'] ?? []) as $r) { $by[$r['name']] = $r; }
            return [$v, $by];
        };
        [$sv0, $S0] = $sizes();
        [, $D0] = $view($jD, $admin);
        $why = (string)($D0[$n('Vodka')]['why_not'] ?? '');
        $ck('sizes: a shot drink with no size anywhere is not tickable, and says "Add bottle size in Step 3"; nothing is filled in',
            empty($D0[$n('Vodka')]['tickable']) && strpos($why, 'Add bottle size in Step 3') === 0 && array_key_exists('ml', $S0[$n('Vodka')] ?? []) && $S0[$n('Vodka')]['ml'] === null && $S0[$n('Vodka')]['from'] === '', json_encode([$D0[$n('Vodka')] ?? null, $S0[$n('Vodka')] ?? null]));
        $ck('sizes: an item with its own saved size is never asked its bottle size (listed for its shot size only, from the list) and costs one shot from it (18000 x ' . $shotMl . ' / 700)',
            isset($S0[$n('Rum')]) && empty($S0[$n('Rum')]['cont_need']) && ($S0[$n('Rum')]['from'] ?? '') === 'own' && !empty($S0[$n('Rum')]['shot_need'])
            && ($S0[$n('Rum')]['shot_from'] ?? '') === 'list' && !empty($D0[$n('Rum')]['tickable']) && abs((float)$D0[$n('Rum')]['cost'] - 18000 * $shotMl / 700) <= BALANCE_TOLERANCE, json_encode($D0[$n('Rum')] ?? null));
        $ck('sizes: a size in the item name is shown as read from the file (its cell), and the drink can be ticked without an answer',
            ($S0[$n('Brandy 750ml')]['from'] ?? '') === 'name' && abs((float)($S0[$n('Brandy 750ml')]['ml'] ?? 0) - 750) < 0.000001 && preg_match('/A5$/', (string)($S0[$n('Brandy 750ml')]['name_source'] ?? '')) === 1
            && !empty($D0[$n('Brandy 750ml')]['tickable']), json_encode($S0[$n('Brandy 750ml')] ?? null));
        $groups = array_column((array)($sv0['groups'] ?? []), null, 'key');
        $ck('sizes: one group for the shot drinks kept in bottles, one for the glass drinks (Red Wine, Glass 175ml: its 175 ml read from the file); blank by default; counts',
            isset($groups['m:shot|bottle'], $groups['gl:bottle']) && $groups['m:shot|bottle']['ml'] === null && ($sv0['counts']['need'] ?? 0) === 6 && ($sv0['counts']['filled'] ?? -1) === 2,
            json_encode([$sv0['groups'] ?? null, $sv0['counts'] ?? null]));
        $rBad = import_job_sizes_set($pdo, $jD, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'value' => 'abc']);
        $rOdd = import_job_sizes_set($pdo, $jD, $admin, ['mode' => 'group', 'key' => 'm:nothing|bottle', 'value' => '750']);
        $ck('sizes: a size that is not a number of ml, or a group that is not in the list, is refused', empty($rBad['ok']) && empty($rOdd['ok']), json_encode([$rBad, $rOdd]));
        $rSm = import_job_sizes_set($pdo, $jD, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'value' => '20']);
        $rTiny = import_job_sizes_set($pdo, $jD, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'value' => '40']);
        $ck('sizes: a bottle smaller than one shot, or under 50 ml, is kept but warned (never refused)',
            !empty($rSm['ok']) && strpos((string)($rSm['warning'] ?? ''), 'less than one sale') !== false && !empty($rTiny['ok']) && strpos((string)($rTiny['warning'] ?? ''), 'very small') !== false,
            json_encode([$rSm, $rTiny]));
        $rG = import_job_sizes_set($pdo, $jD, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'value' => '750']);
        $rW = import_job_sizes_set($pdo, $jD, $admin, ['mode' => 'row', 'key' => $S0[$n('Whisky')]['key'] ?? '', 'value' => '700']);
        [, $D1] = $view($jD, $admin);
        [, $S1] = $sizes();
        $vo = $D1[$n('Vodka')] ?? [];
        $ck('sizes: the group answer makes Vodka tickable; cost of one shot = 15000 / (750 / ' . $shotMl . '), the price stays exactly 1500',
            !empty($rG['ok']) && !empty($vo['tickable']) && abs((float)$vo['cost'] - 15000 / (750 / $shotMl)) <= BALANCE_TOLERANCE && abs((float)$vo['price'] - 1500) < 0.000001
            && ($S1[$n('Vodka')]['from'] ?? '') === 'group', json_encode([$rG, $vo]));
        $ck('sizes: a row answer wins over the group (Whisky 700 ml: 30000 x ' . $shotMl . ' / 700); the name size wins over the group (Brandy stays 750 from its name)',
            !empty($rW['ok']) && ($S1[$n('Whisky')]['from'] ?? '') === 'row' && abs((float)($D1[$n('Whisky')]['cost'] ?? 0) - 30000 * $shotMl / 700) <= BALANCE_TOLERANCE
            && ($S1[$n('Brandy 750ml')]['from'] ?? '') === 'name', json_encode([$rW, $S1[$n('Whisky')] ?? null]));
        $ck('sizes: the "175 ml" group is still blank, so Red Wine is still not tickable (a blank never blocks the import)', empty($D1[$n('Red Wine')]['tickable']) && strpos((string)($D1[$n('Red Wine')]['why_not'] ?? ''), 'Add bottle size in Step 3') === 0, json_encode($D1[$n('Red Wine')] ?? null));
        import_job_sizes_set($pdo, $jD, $admin, ['mode' => 'row', 'key' => $S0[$n('Red Wine')]['key'] ?? '', 'value' => '750']);
        foreach (['Vodka', 'Whisky', 'Red Wine', 'Tequila'] as $nm) { import_job_drinks_set($pdo, $jD, $admin, ['mode' => 'tick', 'key' => $D1[$n($nm)]['key'], 'on' => true]); }
        $rClr = import_job_sizes_set($pdo, $jD, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'value' => '']);
        [, $D2] = $view($jD, $admin);
        $ck('sizes: clearing the group unticks the drinks that now have no size (Vodka, Tequila), never the ones with their own (Whisky)',
            !empty($rClr['ok']) && (int)($rClr['unticked'] ?? 0) === 2 && empty($D2[$n('Vodka')]['ticked']) && !empty($D2[$n('Whisky')]['ticked']), json_encode([$rClr, $D2[$n('Vodka')]['ticked'] ?? null]));
        import_job_sizes_set($pdo, $jD, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'value' => '750']);
        foreach (['Vodka', 'Tequila'] as $nm) { import_job_drinks_set($pdo, $jD, $admin, ['mode' => 'tick', 'key' => $D1[$n($nm)]['key'], 'on' => true]); }
        // since the review, somebody set Tequila's size in Stock: the import never replaces it
        $pdo->prepare('UPDATE stock_ingredients SET volume_ml = 1000 WHERE id = ?')->execute([$teqIng]);
        $cD = import_commit_stock($pdo, $jD, $admin);
        $resD = $cD['result'] ?? [];
        $vol = function (string $name) use ($col) { $v = $col('SELECT volume_ml FROM stock_ingredients WHERE name = ? ORDER BY id DESC LIMIT 1', [$name]); return $v === null || $v === false ? null : (float)$v; };
        $ck('import: the typed sizes are saved on their stock items (Vodka 750 group, Whisky 700 row, Red Wine 750 row); counted in the result',
            !empty($cD['ok']) && ($resD['sizes_saved'] ?? -1) === 3 && $vol($n('Vodka')) === 750.0 && $vol($n('Whisky')) === 700.0 && $vol($n('Red Wine')) === 750.0, json_encode([$cD['ok'] ?? null, $cD['error'] ?? null, $resD['sizes_saved'] ?? null]));
        $ck('import: a size already on an item is never replaced (Rum 700, Tequila 1000 set in Stock since); a size from the name is not written (Brandy)',
            $vol($n('Rum')) === 700.0 && $vol($n('Tequila')) === 1000.0 && $vol($n('Brandy 750ml')) === null, json_encode([$vol($n('Rum')), $vol($n('Tequila')), $vol($n('Brandy 750ml'))]));
        $vlog = (string)$col("SELECT details FROM admin_activity_log WHERE action = 'stock_volume_changed' AND details LIKE ? ORDER BY id DESC LIMIT 1", ['%' . $n('Vodka') . '%Import #' . $jD . '%']);
        $ck('history: the saved size is logged old -> new in System Logs (not set -> 750 ml, Import #)', strpos($vlog, 'not set -> 750 ml') !== false, $vlog);
        $qpp = function (string $name, string $cat) use ($pdo, $col) {
            $p = rh_menu_pos_find($pdo, $name, $cat);
            return $p ? $col('SELECT sri.quantity_per_portion FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id WHERE sr.menu_item_id = ? AND sr.menu_type = ?', [$p['id'], $p['slug']]) : null;
        };
        $ck('import: each sale takes one shot (' . $shotMl . ' / 750 of a bottle of Vodka) and 175 ml (175 / 750 of a bottle of Red Wine) off stock',
            abs((float)$qpp($n('Vodka'), 'Bar') - $shotMl / 750) < 0.000001 && abs((float)$qpp($n('Red Wine'), 'Bar') - 175 / 750) < 0.000001,
            json_encode([$qpp($n('Vodka'), 'Bar'), $qpp($n('Red Wine'), 'Bar')]));
        $ck('import: no stock quantity or cost was changed by a bottle size', abs((float)$col('SELECT current_quantity FROM stock_ingredients WHERE id = ?', [$vodIng])) < 0.000001
            && abs((float)$col('SELECT cost_per_unit FROM stock_ingredients WHERE id = ?', [$vodIng]) - 15000) < 0.000001);

        // ---- file E: glass size + container size (owner 2026-11-20: "Glass could also mean a non standard glass maybe for milk or juice and can
        // be measured in ml optionally while a carton of juice could be in litres or whatever optionally"); the optional reader is a stub here
        $stubOurs = false;
        if (!function_exists('rh_ai_import_measure_suggest')) {
            function rh_ai_import_measure_suggest(array $items): array { $GLOBALS['zzd_ai_calls'][] = $items; return (array)($GLOBALS['zzd_ai_reply'] ?? []); }
            $stubOurs = true;
        }
        // a local stub, never the real reader by name (no model call from a test shell, even if the Assistant file was loaded first)
        $stubFn = function (array $items): array { $GLOBALS['zzd_ai_calls'][] = $items; return (array)($GLOBALS['zzd_ai_reply'] ?? []); };
        $headE = ['Item Name', 'Unit', 'Unit Price', 'Balance', 'Selling Price', 'Served By'];
        $jE = $start($file($headE, [
            [$n('Orange juice'), 'Carton 1 L', '3000', '5', '1500', 'Glass 250'],
            [$n('Fresh milk'), 'Carton', '2000', '5', '1000', 'Glass'],
            [$n('Castle Lager'), 'Crate', '12000', '3', '1500', 'Bottle'],
            [$n('Pineapple juice'), 'Carton', '2400', '5', '1200', 'Glass'],
            [$n('Mystery'), 'bottle', '9000', '2', '900', 'Dubbel'],    // a typo the plain rules cannot read; no other served word in it
            [$n('Half rum'), 'bottle', '9000', '2', '450', 'Half tot'],
            [$n('Dbl gin'), 'bottle', '9000', '2', '1800', 'Dbl tot'],
            [$n('Big glass'), 'Carton 250ml', '1000', '2', '3000', 'Glass 500'],
            [$n('Coke'), 'bottle', '500', '24', '1000', 'Bottle'], [$n('Fanta'), 'bottle', '500', '24', '1000', 'Bottle'], [$n('Sprite'), 'bottle', '500', '24', '1000', 'Bottle'],   // most cells read, so the column stays a served-by column
        ], 'e.csv'), 'zzd-e-' . $run . '.csv');
        $sizesE = function () use ($pdo, $jE, $admin) {
            $v = import_job_sizes_view($pdo, $jE, $admin, ['size' => 100, 'ai' => false]);
            $by = [];
            foreach ((array)($v['rows'] ?? []) as $r) { $by[$r['name']] = $r; }
            return [$v, $by];
        };
        [$svE, $SE] = $sizesE();
        [, $E0] = $view($jE, $admin);
        $oj = $E0[$n('Orange juice')] ?? [];
        $ck('glass: Orange juice, Unit "Carton 1 L", served "Glass 250" = 4 glasses a carton: tickable with no answer, cost of one glass 3000 x 250 / 1000 = 750, price exactly 1500',
            !empty($oj['tickable']) && abs((float)$oj['cost'] - 750) <= BALANCE_TOLERANCE && abs((float)$oj['price'] - 1500) < 0.000001,
            json_encode([$oj, $SE[$n('Orange juice')] ?? null]));
        $ck('glass: Orange juice ("Carton 1 L") sits in the cartons group, not a group of its own', ($SE[$n('Orange juice')]['group'] ?? '') === 'gl:carton', (string)($SE[$n('Orange juice')]['group'] ?? ''));
        $fm = $E0[$n('Fresh milk')] ?? [];
        $ck('glass: a milk glass with no size and a carton with no size is not tickable, with the plain hint; nothing is guessed',
            empty($fm['tickable']) && strpos((string)($fm['why_not'] ?? ''), 'Add glass size and carton size in Step 3 (optional)') === 0 && array_key_exists('glass_ml', $SE[$n('Fresh milk')] ?? [])
            && $SE[$n('Fresh milk')]['glass_ml'] === null, json_encode([$fm, $SE[$n('Fresh milk')] ?? null]));
        $ck('glass: Castle Lager (Crate, served Bottle) is still crates: no glass or container question', !isset($SE[$n('Castle Lager')]), json_encode(array_keys($SE)));
        $gE = array_column((array)($svE['groups'] ?? []), null, 'key');
        $ck('glass: one "Sold by the glass, kept in cartons" group asks both sizes', isset($gE['gl:carton']) && !empty($gE['gl:carton']['glass_q']) && !empty($gE['gl:carton']['cont']), json_encode($svE['groups'] ?? null));
        // the optional reader (stub): only rows the plain rules could not read; fills only blanks, shown as read from the file; "double" never a shot
        $pj = $E0[$n('Pineapple juice')] ?? [];
        $my = $E0[$n('Mystery')] ?? [];
        $GLOBALS['zzd_ai_calls'] = [];
        $GLOBALS['zzd_ai_reply'] = [
            ['key' => $pj['key'] ?? '', 'field' => 'container_ml', 'value' => 1000, 'source' => '<script>alert(1)</script> Pineapple 1L on sheet'],
            ['key' => $pj['key'] ?? '', 'field' => 'portion_ml', 'value' => 200, 'source' => 'cell F5'],
            ['key' => $my['key'] ?? '', 'field' => 'served_by', 'value' => 'double', 'source' => 'cell F6 "Dubbel"'],
            ['key' => $E0[$n('Half rum')]['key'] ?? '', 'field' => 'served_by', 'value' => 'shot', 'source' => 'cell F7 "Half tot"'],
            ['key' => $E0[$n('Dbl gin')]['key'] ?? '', 'field' => 'served_by', 'value' => 'shot', 'source' => 'cell F8 "Dbl tot"'],
            ['key' => $oj['key'] ?? '', 'field' => 'portion_ml', 'value' => 999, 'source' => 'not asked'],
        ];
        $nAi = import_job_sizes_ai_fill($pdo, $jE, $stubFn);
        $sent = array_column((array)($GLOBALS['zzd_ai_calls'][0] ?? []), 'key');
        [, $SE2] = $sizesE();
        [, $E2] = $view($jE, $admin);
        $pj2 = $SE2[$n('Pineapple juice')] ?? [];
        $ck('reader: only the rows the plain rules could not read are sent (not Orange juice, not Castle Lager); its sizes fill the blanks as read from the file, markup stripped',
            $nAi === 5 && in_array($pj['key'] ?? '-', $sent, true) && in_array($my['key'] ?? '-', $sent, true) && !in_array($oj['key'] ?? '-', $sent, true)
            && ($pj2['from'] ?? '') === 'ai' && ($pj2['glass_from'] ?? '') === 'ai' && !empty($E2[$n('Pineapple juice')]['tickable'])
            && abs((float)$E2[$n('Pineapple juice')]['cost'] - 2400 * 200 / 1000) <= BALANCE_TOLERANCE && strpos(json_encode($pj2), '<script') === false
            && abs((float)($E2[$n('Orange juice')]['cost'] ?? 0) - 750) <= BALANCE_TOLERANCE, json_encode([$nAi, $sent, $pj2]));
        $my2 = $E2[$n('Mystery')] ?? [];
        $ck('reader: "double" is only ever the hotel\'s own Double measure (two shots), never one shot',
            ($my2['measure_from'] ?? '') === 'ai' && stripos((string)($my2['measure'] ?? ''), 'double') !== false && stripos((string)($my2['measure'] ?? ''), 'shot') === false, json_encode([$my2, $SE2[$n('Mystery')] ?? null]));
        $wBig = import_job_sizes_set($pdo, $jE, $admin, ['mode' => 'row', 'key' => $fm['key'] ?? '', 'field' => 'ml', 'value' => '1000']);
        $wBig2 = import_job_sizes_set($pdo, $jE, $admin, ['mode' => 'row', 'key' => $fm['key'] ?? '', 'field' => 'glass', 'value' => '2000']);
        $wTiny = import_job_sizes_set($pdo, $jE, $admin, ['mode' => 'row', 'key' => $fm['key'] ?? '', 'field' => 'glass', 'value' => '20']);
        $wBad = import_job_sizes_set($pdo, $jE, $admin, ['mode' => 'row', 'key' => $fm['key'] ?? '', 'field' => 'glass', 'value' => 'big']);
        $ck('glass: a glass bigger than its carton, or under 30 ml, is kept but warned (never refused); text is refused',
            !empty($wBig['ok']) && !empty($wBig2['ok']) && strpos((string)($wBig2['warning'] ?? ''), 'more than one carton') !== false && !empty($wTiny['ok'])
            && strpos((string)($wTiny['warning'] ?? ''), 'very small for a glass') !== false && empty($wBad['ok']) && strpos((string)($wBad['error'] ?? ''), 'Glass size') === 0,
            json_encode([$wBig2, $wTiny, $wBad]));
        $wG = import_job_sizes_set($pdo, $jE, $admin, ['mode' => 'group', 'key' => 'gl:carton', 'field' => 'glass', 'value' => '250']);
        import_job_sizes_set($pdo, $jE, $admin, ['mode' => 'row', 'key' => $fm['key'] ?? '', 'field' => 'glass', 'value' => '']);
        [, $E1] = $view($jE, $admin);
        $fm1 = $E1[$n('Fresh milk')] ?? [];
        $ck('glass: group glass 250 + row carton 1000 makes Fresh milk tickable: cost 2000 x 250 / 1000 = 500 (0.25 of a carton a sale); the read 200 ml still wins on Pineapple',
            !empty($wG['ok']) && !empty($fm1['tickable']) && abs((float)$fm1['cost'] - 500) <= BALANCE_TOLERANCE
            && abs((float)($E1[$n('Pineapple juice')]['cost'] ?? 0) - 480) <= BALANCE_TOLERANCE, json_encode([$fm1, $E1[$n('Pineapple juice')] ?? null]));
        $ck('reader: "Half tot" and "Dbl tot" read as one shot are dropped by the cell\'s own words: left as written for the person, never costed as a shot',
            ($E2[$n('Half rum')]['measure_from'] ?? '') !== 'ai' && ($E2[$n('Dbl gin')]['measure_from'] ?? '') !== 'ai' && empty($E2[$n('Half rum')]['tickable']) && empty($E2[$n('Dbl gin')]['tickable'])
            && stripos((string)($E2[$n('Half rum')]['measure'] ?? ''), 'shot') === false && stripos((string)($E2[$n('Dbl gin')]['measure'] ?? ''), 'shot') === false,
            json_encode([$E2[$n('Half rum')] ?? null, $E2[$n('Dbl gin')] ?? null]));
        $ck('glass: "Glass 500" from a "Carton 250ml" (both read from the file) shows the row warning (kept, not refused)',
            strpos((string)($SE2[$n('Big glass')]['warn'] ?? ''), 'more than one carton') !== false && strpos((string)($E2[$n('Big glass')]['size_warn'] ?? ''), '500 ml glass') !== false
            && ($SE2[$n('Fresh milk')]['warn'] ?? 'x') === '', json_encode($SE2[$n('Big glass')] ?? null));
        $rClrAi = import_job_sizes_set($pdo, $jE, $admin, ['mode' => 'row', 'key' => $pj['key'] ?? '', 'field' => 'glass', 'value' => '']);
        $nAgain = import_job_sizes_ai_fill($pdo, $jE, $stubFn);
        [, $SE3] = $sizesE();
        $ck('reader: clearing a read glass size drops it (the group size now applies), the read carton size stays, and the row is not asked again',
            !empty($rClrAi['ok']) && $nAgain === 0 && ($SE3[$n('Pineapple juice')]['glass_from'] ?? '') === 'group' && (float)($SE3[$n('Pineapple juice')]['glass_ml'] ?? 0) === 250.0
            && ($SE3[$n('Pineapple juice')]['from'] ?? '') === 'ai', json_encode($SE3[$n('Pineapple juice')] ?? null));
        if ($stubOurs) {
            $hook = import_measure_ai_hook($pdo);
            $on = function_exists('rh_ai_enabled') && rh_ai_enabled($pdo);
            $ck('reader: the page hook finds the reader only through function_exists and the Assistant switch (fail-closed)', $on ? is_callable($hook) : $hook === null, json_encode(['on' => $on, 'hook' => $hook !== null]));
        }
        // ---- file F: 60 cells the plain rules cannot read, three loads: the paid reader is called ONCE for the whole import (40 rows), never again
        $rowsF = [];
        for ($i = 1; $i <= 60; $i++) {      // 3 readable cells to each unread one, so the column stays a served-by column
            $rowsF[] = [$n('Odd ' . $i), 'bottle', '9000', '1', '900', 'Odd pour ' . $i];
            for ($k = 1; $k <= 3; $k++) { $rowsF[] = [$n('Soft ' . $i . '-' . $k), 'bottle', '500', '1', '1000', 'Bottle']; }
        }
        $jF = $start($file($headE, $rowsF, 'f.csv'), 'zzd-f-' . $run . '.csv');
        $callsF = [];
        $cntFn = function (array $items) use (&$callsF): array { $callsF[] = count($items); return []; };
        $nF = [import_job_sizes_ai_fill($pdo, $jF, $cntFn), import_job_sizes_ai_fill($pdo, $jF, $cntFn), import_job_sizes_ai_fill($pdo, $jF, $cntFn)];
        $ck('reader: 60 unread rows over 3 loads = exactly 1 call (40 rows); rows past 40 stay for the person', count($callsF) === 1 && ($callsF[0] ?? 0) === 40 && $nF === [0, 0, 0],
            json_encode([$callsF, $nF]));
        // ---- tick Orange juice + Fresh milk, import: one sale of each takes 0.25 of a carton off stock; the read size is saved on Orange juice
        foreach (['Orange juice', 'Fresh milk'] as $nm) { import_job_drinks_set($pdo, $jE, $admin, ['mode' => 'tick', 'key' => $E1[$n($nm)]['key'], 'on' => true]); }
        $cE = import_commit_stock($pdo, $jE, $admin);
        $ojIng = (int)$col('SELECT id FROM stock_ingredients WHERE name = ? ORDER BY id DESC LIMIT 1', [$n('Orange juice')]);
        $fmIng = (int)$col('SELECT id FROM stock_ingredients WHERE name = ? ORDER BY id DESC LIMIT 1', [$n('Fresh milk')]);
        $ck('import: the carton sizes are saved on their stock items (Orange juice 1000 read from its unit cell and ticked, Fresh milk 1000 typed); Pineapple (not ticked) is not',
            !empty($cE['ok']) && $vol($n('Orange juice')) === 1000.0 && $vol($n('Fresh milk')) === 1000.0 && $vol($n('Pineapple juice')) === null,
            json_encode([$cE['ok'] ?? null, $cE['error'] ?? null, $vol($n('Orange juice')), $vol($n('Fresh milk')), $vol($n('Pineapple juice'))]));
        $seedStock($ojIng, 5);
        $seedStock($fmIng, 5);
        foreach (['Orange juice' => $ojIng, 'Fresh milk' => $fmIng] as $nm => $iid) {
            $pos = rh_menu_pos_find($pdo, $n($nm), 'Bar');
            if ($pos) { deductStockForMenuItem((int)$pos['id'], (string)$pos['slug'], 1.0, 'manual', null, $admin); }
            $left = (float)$col('SELECT current_quantity FROM stock_ingredients WHERE id = ?', [$iid]);
            $ck('sale: one glass of ' . $nm . ' takes 0.25 of a carton (5 -> 4.75)', $pos && abs($left - 4.75) < 0.000001, json_encode([$pos, $left]));
        }

        // ---- file G: shot size in step 3 (owner 2026-11-21: "set the shot size in advance ... same as Glass size"). Default = the hotel's list
        // shot (unchanged link), a group / row answer, the file ("Tot 35ml"), the optional reader (stub) for "Shot 30"; never the opening count.
        require_once $root . '/admin/includes/pos-drink-servings.php';
        $listBefore = (string)$col("SELECT setting_value FROM site_settings WHERE setting_key = 'stock_measures_list'");
        $listLogs = (int)$col("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_measures_list_changed'");
        $mkIng = function (string $name, float $cost, ?float $vol) use ($pdo) {
            $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, volume_ml) VALUES (?, 'ZZD', 'bottle', 0, ?, ?)")->execute([$name, $cost, $vol]);
            return (int)$pdo->lastInsertId();
        };
        $whIng = $mkIng($n('Whisky G'), 20000, 750);
        $giIng = $mkIng($n('Gin G'), 20000, 750);
        $mkIng($n('Vodka G'), 20000, 750);
        $mkIng($n('Brandy G'), 15000, 700);
        $mkIng($n('Rum G'), 18000, 750);
        $mkIng($n('Rum2 G'), 18000, 750);
        $coIng = $mkIng($n('Cognac G'), 20000, 750);
        $teIng = $mkIng($n('Tequila G'), 9000, null);
        if ($measureOn) {
            $pdo->prepare("UPDATE stock_ingredients SET measure_big_name = 'bottle', measure_small_name = 'shot', measure_per_big = 30, measure_stock_side = 'big' WHERE id IN (?, ?)")->execute([$coIng, $teIng]);
        }
        $jG = $start($file(['Item Name', 'Unit', 'Unit Price', 'Balance', 'Selling Price', 'Served By'], [
            [$n('Whisky G'), 'bottle', '20000', '2', '2500', 'Shot'],
            [$n('Gin G'), 'bottle', '20000', '2', '2500', 'Shot'],
            [$n('Vodka G'), 'bottle', '20000', '2', '2500', 'Shot'],
            [$n('Brandy G'), 'bottle', '15000', '2', '2000', 'Tot 35ml'],
            [$n('Rum G'), 'bottle', '18000', '2', '2000', 'Shot 30'],
            [$n('Rum2 G'), 'bottle', '18000', '2', '2000', 'Shot 30'],
            [$n('Cognac G'), 'bottle', '20000', '66 shots', '3000', 'Shot'],
            [$n('Grappa G 750ml'), 'bottle', '15000', '66 shots', '2000', 'Shot'],
            [$n('Tequila G'), 'bottle', '9000', '2', '1000', 'Shot'],
            [$n('Coke G'), 'bottle', '500', '24', '1000', 'Bottle'], [$n('Fanta G'), 'bottle', '500', '24', '1000', 'Bottle'],
        ], 'g.csv'), 'zzd-g-' . $run . '.csv');
        $sizesG = function () use ($pdo, &$jG, $admin) {
            $v = import_job_sizes_view($pdo, $jG, $admin, ['size' => 100, 'ai' => false]);
            $by = [];
            foreach ((array)($v['rows'] ?? []) as $r) { $by[$r['name']] = $r; }
            return [$v, $by];
        };
        $countOf = function () use ($pdo, &$jG, $n) {     // the opening-count quantity and count measure the import would file, per item
            $st = import_job_state($pdo, $jG);
            $o = [];
            foreach ((array)($st['res']['items'] ?? []) as $it) {
                if (in_array($it['name'] ?? '', [$n('Cognac G'), $n('Grappa G 750ml')], true)) {
                    $o[$it['name']] = ['balance' => $it['balance'] ?? null, 'measure_save' => $it['measure_save'] ?? null, 'qty_how' => $it['qty_how'] ?? null];
                }
            }
            return $o;
        };
        $GLOBALS['zzd_ai_calls'] = [];
        [, $G0] = $view($jG, $admin);
        $GLOBALS['zzd_ai_reply'] = [
            ['key' => $G0[$n('Rum G')]['key'] ?? '', 'field' => 'served_by', 'value' => 'shot', 'source' => 'cell E6 "Shot 30"'],
            ['key' => $G0[$n('Rum G')]['key'] ?? '', 'field' => 'portion_ml', 'value' => 30, 'source' => 'cell E6 "Shot 30"'],
            ['key' => $G0[$n('Rum2 G')]['key'] ?? '', 'field' => 'served_by', 'value' => 'shot', 'source' => 'cell E7'],
            ['key' => $G0[$n('Rum2 G')]['key'] ?? '', 'field' => 'portion_ml', 'value' => 35, 'source' => 'cell E7'],   // the cell says 30: dropped
        ];
        $nAiG = import_job_sizes_ai_fill($pdo, $jG, $stubFn);
        $count0 = $countOf();
        [$svG0, $SG0] = $sizesG();
        [, $G1] = $view($jG, $admin);
        $wh = $SG0[$n('Whisky G')] ?? [];
        $ck('shot: default = the Drink measures list shot (' . $shotMl . ' ml), labelled so; listed although the bottle size is known; cost 20000 x ' . $shotMl . ' / 750, unchanged',
            !empty($wh['shot_need']) && ($wh['shot_from'] ?? '') === 'list' && (float)($wh['shot_ml'] ?? 0) === $shotMl && empty($wh['cont_need']) && ($wh['from'] ?? '') === 'own'
            && ($G1[$n('Whisky G')]['measure'] ?? '') === '1 shot' && abs((float)($G1[$n('Whisky G')]['cost'] ?? 0) - 20000 * $shotMl / 750) <= BALANCE_TOLERANCE && !empty($G1[$n('Whisky G')]['tickable']),
            json_encode([$wh, $G1[$n('Whisky G')] ?? null]));
        $br = $SG0[$n('Brandy G')] ?? [];
        $ck('shot: "Tot 35ml" in the served-by cell pre-fills 35 ml as read from the file (its cell), in the shot group; cost 15000 x 35 / 700',
            ($br['shot_from'] ?? '') === 'cell' && (float)($br['shot_ml'] ?? 0) === 35.0 && preg_match('/F5$/', (string)($br['shot_src'] ?? '')) === 1 && ($br['group'] ?? '') === 'm:shot|bottle'
            && abs((float)($G1[$n('Brandy G')]['cost'] ?? 0) - 15000 * 35 / 700) <= BALANCE_TOLERANCE, json_encode([$br, $G1[$n('Brandy G')] ?? null]));
        $ru = $SG0[$n('Rum G')] ?? [];
        $ck('shot reader: "Shot 30" (not read by the plain rules) is read as one shot of 30 ml by the reader: pre-filled "ai" with its source, cost 18000 x 30 / 750; one call',
            $nAiG >= 2 && count($GLOBALS['zzd_ai_calls']) === 1 && ($ru['shot_from'] ?? '') === 'ai' && (float)($ru['shot_ml'] ?? 0) === 30.0 && strpos((string)($ru['shot_src'] ?? ''), 'E6') !== false
            && ($G1[$n('Rum G')]['measure_from'] ?? '') === 'ai' && abs((float)($G1[$n('Rum G')]['cost'] ?? 0) - 18000 * 30 / 750) <= BALANCE_TOLERANCE, json_encode([$nAiG, $ru, $G1[$n('Rum G')] ?? null]));
        $ck('shot reader: a reading whose size the cell does not write ("Shot 30" read as 35 ml) is dropped: left as written, not a shot, not tickable',
            ($G1[$n('Rum2 G')]['measure_from'] ?? '') !== 'ai' && empty($SG0[$n('Rum2 G')]['shot_need']) && empty($G1[$n('Rum2 G')]['tickable']), json_encode([$G1[$n('Rum2 G')] ?? null, $SG0[$n('Rum2 G')] ?? null]));
        if ($measureOn) {
            $co = $SG0[$n('Cognac G')] ?? [];
            $ck('shot: an item with its own count measure (30 shots in a 750 ml bottle) shows its shot from it (25 ml), no warning, costs 1/30 bottle as before',
                ($co['shot_from'] ?? '') === 'count' && (float)($co['shot_ml'] ?? 0) === 25.0 && ($co['warn'] ?? 'x') === '' && abs((float)($G1[$n('Cognac G')]['cost'] ?? 0) - 20000 / 30) <= BALANCE_TOLERANCE,
                json_encode([$co, $G1[$n('Cognac G')] ?? null]));
        }
        if ($measureOn) {
            // a group BOTTLE size is not put on a drink whose own count measure gives the shot (Tequila G: 30 shots, no bottle size): nothing changes for it
            $rGm = import_job_sizes_set($pdo, $jG, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'field' => 'ml', 'value' => '750']);
            [, $SGt] = $sizesG();
            [, $Gt] = $view($jG, $admin);
            $te = $SGt[$n('Tequila G')] ?? [];
            $ck('shot: an item whose own count measure gives the shot, with no bottle size, is listed (bottle optional); a group bottle size does not touch it; 1/30 bottle as before',
                !empty($rGm['ok']) && ($te['shot_from'] ?? '') === 'count' && !empty($te['cont_optional']) && ($te['from'] ?? 'x') === '' && !empty($te['done'])
                && abs((float)($Gt[$n('Tequila G')]['cost'] ?? 0) - 9000 / 30) <= BALANCE_TOLERANCE && !empty($Gt[$n('Tequila G')]['tickable']), json_encode([$rGm, $te, $Gt[$n('Tequila G')] ?? null]));
        }
        // group answer 35
        $rG35 =import_job_sizes_set($pdo, $jG, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'field' => 'shot', 'value' => '35']);
        [, $SG1] = $sizesG();
        [, $G2] = $view($jG, $admin);
        $ck('shot: group answer 35 -> Gin cost 20000 x 35 / 750, "35 ml (shot)", labelled as the group\'s; the file (Brandy 35 from its cell) and the reading (Rum 30) still win',
            !empty($rG35['ok']) && ($SG1[$n('Gin G')]['shot_from'] ?? '') === 'group' && abs((float)($G2[$n('Gin G')]['cost'] ?? 0) - 20000 * 35 / 750) <= BALANCE_TOLERANCE
            && ($G2[$n('Gin G')]['measure'] ?? '') === '35 ml (shot)' && ($SG1[$n('Brandy G')]['shot_from'] ?? '') === 'cell' && ($SG1[$n('Rum G')]['shot_from'] ?? '') === 'ai'
            && !empty($G2[$n('Gin G')]['tickable']), json_encode([$rG35, $SG1[$n('Gin G')] ?? null, $G2[$n('Gin G')] ?? null]));
        if ($measureOn) {
            $ck('shot: a shot size that disagrees with the item\'s count measure and bottle (750 / 35 = 21.4, count says 30) shows a row warning; the size is kept, never refused',
                strpos((string)($SG1[$n('Cognac G')]['warn'] ?? ''), 'count measure says 30 shots in one bottle') !== false && strpos((string)($SG1[$n('Cognac G')]['warn'] ?? ''), 'opening count still uses 30') !== false
                && ($SG1[$n('Cognac G')]['shot_from'] ?? '') === 'group', json_encode($SG1[$n('Cognac G')] ?? null));
            $ck('shot: once the group shot size (35) applies to Tequila G, it needs a bottle size and takes the group\'s 750: cost 9000 x 35 / 750',
                ($SG1[$n('Tequila G')]['shot_from'] ?? '') === 'group' && ($SG1[$n('Tequila G')]['from'] ?? '') === 'group' && empty($SG1[$n('Tequila G')]['cont_optional'])
                && abs((float)($G2[$n('Tequila G')]['cost'] ?? 0) - 9000 * 35 / 750) <= BALANCE_TOLERANCE, json_encode([$SG1[$n('Tequila G')] ?? null, $G2[$n('Tequila G')] ?? null]));
        }
        // row beats group; a row answer equal to the list shot keeps the list link
        $rW25 = import_job_sizes_set($pdo, $jG, $admin, ['mode' => 'row', 'key' => $wh['key'] ?? '', 'field' => 'shot', 'value' => '25']);
        [, $SG2] = $sizesG();
        [, $G3] = $view($jG, $admin);
        $ck('shot: a row answer beats the group (Whisky ' . $shotMl . ' ml while the group says 35): its own label, cost back to 20000 x ' . $shotMl . ' / 750, "1 shot"',
            !empty($rW25['ok']) && ($SG2[$n('Whisky G')]['shot_from'] ?? '') === 'row' && (float)($SG2[$n('Whisky G')]['shot_ml'] ?? 0) === 25.0
            && abs((float)($G3[$n('Whisky G')]['cost'] ?? 0) - 20000 * $shotMl / 750) <= BALANCE_TOLERANCE && ($G3[$n('Whisky G')]['measure'] ?? '') === ($shotMl === 25.0 ? '1 shot' : '25 ml (shot)'),
            json_encode([$rW25, $SG2[$n('Whisky G')] ?? null, $G3[$n('Whisky G')] ?? null]));
        // validation on Vodka: text and more than the bottle refused; very small / large warned; then cleared -> back to the group
        $vk = $SG2[$n('Vodka G')]['key'] ?? '';
        $vBad = import_job_sizes_set($pdo, $jG, $admin, ['mode' => 'row', 'key' => $vk, 'field' => 'shot', 'value' => 'tot']);
        $vBig = import_job_sizes_set($pdo, $jG, $admin, ['mode' => 'row', 'key' => $vk, 'field' => 'shot', 'value' => '800']);
        $gBig = import_job_sizes_set($pdo, $jG, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'field' => 'shot', 'value' => '760']);
        $vTiny = import_job_sizes_set($pdo, $jG, $admin, ['mode' => 'row', 'key' => $vk, 'field' => 'shot', 'value' => '5']);
        $vLot = import_job_sizes_set($pdo, $jG, $admin, ['mode' => 'row', 'key' => $vk, 'field' => 'shot', 'value' => '150']);
        $ck('shot: text is refused ("Shot size ..."), a shot bigger than its bottle is refused (row 800 and group 760 > 750); under 10 ml or over 100 ml is kept with a warning',
            empty($vBad['ok']) && strpos((string)($vBad['error'] ?? ''), 'Shot size') === 0 && empty($vBig['ok']) && strpos((string)($vBig['error'] ?? ''), 'more than one bottle') !== false
            && empty($gBig['ok']) && !empty($vTiny['ok']) && strpos((string)($vTiny['warning'] ?? ''), 'very small') !== false && !empty($vLot['ok']) && strpos((string)($vLot['warning'] ?? ''), 'a lot') !== false,
            json_encode([$vBad, $vBig, $gBig, $vTiny['warning'] ?? null, $vLot['warning'] ?? null]));
        $vClr = import_job_sizes_set($pdo, $jG, $admin, ['mode' => 'row', 'key' => $vk, 'field' => 'shot', 'value' => '']);
        [, $SG3] = $sizesG();
        $ck('shot: clearing a row answer falls back to the next size (the group\'s 35 ml)', !empty($vClr['ok']) && ($SG3[$n('Vodka G')]['shot_from'] ?? '') === 'group' && (float)($SG3[$n('Vodka G')]['shot_ml'] ?? 0) === 35.0,
            json_encode($SG3[$n('Vodka G')] ?? null));
        $rGr = import_job_sizes_set($pdo, $jG, $admin, ['mode' => 'row', 'key' => $SG3[$n('Grappa G 750ml')]['key'] ?? '', 'field' => 'shot', 'value' => '35']);
        $count1 = $countOf();
        $ck('shot: no shot answer changes the opening count: "66 shots" stays 2.2 bottles (list shot / count measure), the count measure to save is the same',
            !empty($rGr['ok']) && count($count0) === 2 && $count0 === $count1 && abs((float)($count1[$n('Grappa G 750ml')]['balance'] ?? 0) - 2.2) < 0.0000005
            && (!$measureOn || abs((float)($count1[$n('Cognac G')]['balance'] ?? 0) - 2.2) < 0.0000005), json_encode([$count0, $count1]));
        // import: tick Whisky (list shot), Gin (group 35), Grappa (new, row 35)
        [, $G4] = $view($jG, $admin);
        foreach (['Whisky G', 'Gin G', 'Grappa G 750ml'] as $nm) {
            $r = import_job_drinks_set($pdo, $jG, $admin, ['mode' => 'tick', 'key' => $G4[$n($nm)]['key'] ?? '', 'on' => true]);
            if (empty($r['ok'])) { $ck('shot: tick ' . $nm, false, json_encode([$r, $G4[$n($nm)] ?? null])); }
        }
        $cG = import_commit_stock($pdo, $jG, $admin);
        $lineOf = function (string $name) use ($pdo) {
            $p = rh_menu_pos_find($pdo, $name, 'Bar');
            if (!$p) { return null; }
            $st = $pdo->prepare('SELECT sri.quantity_per_portion, sri.entry_measure, sri.entry_qty, sri.entry_ml, i.name, i.unit' . rh_drink_ing_cols('i')
                . ' FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id JOIN stock_ingredients i ON i.id = sri.ingredient_id WHERE sr.menu_item_id = ? AND sr.menu_type = ?');
            $st->execute([$p['id'], $p['slug']]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return count($rows) === 1 ? $rows[0] + ['pos' => $p] : null;
        };
        $lW = $lineOf($n('Whisky G'));
        $lG = $lineOf($n('Gin G'));
        $lP = $lineOf($n('Grappa G 750ml'));
        $ck('shot import: the list shot keeps the exact same link as before (1 shot = ' . $shotMl . ' / 750 of a bottle, typed as "shot"), till tile "Shot · ' . $shotMl . ' ml"',
            !empty($cG['ok']) && $lW && $lW['entry_measure'] === 'shot' && abs((float)$lW['entry_qty'] - 1) < 0.000001 && abs((float)$lW['quantity_per_portion'] - round($shotMl / 750, 6)) < 0.0000005
            && rh_pos_serving_label((array)rh_pos_serving_of($lW, $lW, rh_drink_measures_list())) === 'Shot · ' . $shotMl . ' ml', json_encode([$cG['ok'] ?? null, $cG['error'] ?? null, $lW]));
        $ck('shot import: a 35 ml shot links 35 ml of the bottle (35 / 750 = 0.046667 a sale), typed as 35 ml; the till tile shows "35 ml"; Grappa (new, row 35) the same',
            $lG && $lG['entry_measure'] === 'ml' && abs((float)$lG['entry_qty'] - 35) < 0.000001 && abs((float)$lG['quantity_per_portion'] - round(35 / 750, 6)) < 0.0000005
            && rh_pos_serving_label((array)rh_pos_serving_of($lG, $lG, rh_drink_measures_list())) === '35 ml'
            && $lP && abs((float)$lP['quantity_per_portion'] - round(35 / 750, 6)) < 0.0000005, json_encode([$lG, $lP]));
        $seedStock($giIng, 2);
        deductStockForMenuItem((int)($lG['pos']['id'] ?? 0), (string)($lG['pos']['slug'] ?? ''), 1.0, 'manual', null, $admin);
        $giLeft = (float)$col('SELECT current_quantity FROM stock_ingredients WHERE id = ?', [$giIng]);
        $ck('shot sale: one 35 ml Gin shot takes 35 / 750 of a bottle off stock (2 -> 1.953333)', abs($giLeft - (2 - round(35 / 750, 6))) < 0.000001, (string)$giLeft);
        $grIng = (int)$col('SELECT id FROM stock_ingredients WHERE name = ? ORDER BY id DESC LIMIT 1', [$n('Grappa G 750ml')]);
        $grLine = $col('SELECT scl.actual_quantity FROM stock_count_lines scl WHERE scl.ingredient_id = ? ORDER BY scl.id DESC LIMIT 1', [$grIng]);
        $ck('shot import: the opening count line of Grappa ("66 shots") is 2.2 bottles, whatever its 35 ml shot size', $grLine !== false && abs((float)$grLine - 2.2) < 0.000001, json_encode($grLine));
        if ($measureOn) {
            $ck('shot import: the item\'s count measure is untouched (Cognac still 30 shots in one bottle, bottle side)',
                abs((float)$col('SELECT measure_per_big FROM stock_ingredients WHERE id = ?', [$coIng]) - 30) < 0.000001 && (string)$col('SELECT measure_small_name FROM stock_ingredients WHERE id = ?', [$coIng]) === 'shot');
        }
        $ck('shot import: the hotel\'s Drink measures list is unchanged by any shot size typed in the import', (string)$col("SELECT setting_value FROM site_settings WHERE setting_key = 'stock_measures_list'") === $listBefore
            && (int)$col("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_measures_list_changed'") === $listLogs, $listBefore);
        $ck('shot import: the stock quantity and cost of Whisky are not changed by its shot size', abs((float)$col('SELECT cost_per_unit FROM stock_ingredients WHERE id = ?', [$whIng]) - 20000) < 0.000001
            && abs((float)$col('SELECT current_quantity FROM stock_ingredients WHERE id = ?', [$whIng])) < 0.000001);

        // ---- file H: mass set in step 3 + "Sold as" (owner 2026-11-21: "mass set like if default shot size is 30ml ... or even change within
        // whether by glass or shot"). Row answers in one save, the one-row checks per drink, menu side only (never the opening count).
        $listBeforeH = (string)$col("SELECT setting_value FROM site_settings WHERE setting_key = 'stock_measures_list'");
        $mkIng($n('Gin H1'), 20000, 750);
        $mkIng($n('Gin H2'), 20000, 750);
        $mkIng($n('Gin H3'), 20000, 750);
        $mkIng($n('Vodka H4'), 20000, null);
        $mkIng($n('Mini H5'), 2000, 50);
        $mkIng($n('Wine H6'), 15000, 750);
        $coHIng = $mkIng($n('Cognac H10'), 20000, 750);
        if ($measureOn) {
            $pdo->prepare("UPDATE stock_ingredients SET measure_big_name = 'bottle', measure_small_name = 'shot', measure_per_big = 30, measure_stock_side = 'big' WHERE id = ?")->execute([$coHIng]);
        }

        $rowsH = [
            [$n('Gin H1'), 'bottle', '20000', '2', '2500', 'Shot'],
            [$n('Gin H2'), 'bottle', '20000', '2', '2500', 'Shot'],
            [$n('Gin H3'), 'bottle', '20000', '2', '2500', 'Shot'],
            [$n('Vodka H4'), 'bottle', '20000', '2', '2500', 'Shot'],
            [$n('Mini H5'), 'bottle', '2000', '2', '1000', 'Shot'],
            [$n('Wine H6'), 'bottle', '15000', '2', '3000', 'Glass'],
            [$n('Brandy H9 750ml'), 'bottle', '20000', '66 shots', '3000', 'Shot'],
            [$n('Cognac H10'), 'bottle', '20000', '2', '3000', 'Shot'],
        ];
        for ($i = 1; $i <= 12; $i++) {       // twelve glass drinks: more than one page of 10
            $mkIng($n('Juice H' . sprintf('%02d', $i)), 1000, 1000);
            $rowsH[] = [$n('Juice H' . sprintf('%02d', $i)), 'bottle', '1000', '2', '500', 'Glass'];
        }
        $jH = $start($file(['Item Name', 'Unit', 'Unit Price', 'Balance', 'Selling Price', 'Served By'], $rowsH, 'h.csv'), 'zzd-h-' . $run . '.csv');
        $sizesH = function (array $p = []) use ($pdo, &$jH, $admin) {
            $v = import_job_sizes_view($pdo, $jH, $admin, $p + ['size' => 100, 'ai' => false]);
            $by = [];
            foreach ((array)($v['rows'] ?? []) as $r) { $by[$r['name']] = $r; }
            return [$v, $by];
        };
        $countH = function () use ($pdo, &$jH, $n) {
            foreach ((array)(import_job_state($pdo, $jH)['res']['items'] ?? []) as $it) {
                if (($it['name'] ?? '') === $n('Brandy H9 750ml')) { return ['balance' => $it['balance'] ?? null, 'measure_save' => $it['measure_save'] ?? null, 'qty_how' => $it['qty_how'] ?? null, 'served_by' => $it['served_by'] ?? null]; }
            }
            return null;
        };
        $countH0 = $countH();
        [$svH0, $SH0] = $sizesH();
        $kH = function (string $nm) use (&$SH0, $n) { return (string)($SH0[$n($nm)]['key'] ?? ''); };
        $optV = array_column((array)($svH0['sold_options'] ?? []), 'v');
        $ck('mass: the list gives the "Sold as" choices (shot, glass, one whole unit, the Drink measures list) and a short menu status per row',
            in_array('shot', $optV, true) && in_array('glass', $optV, true) && in_array('unit', $optV, true) && in_array('m:double', $optV, true)
            && ($SH0[$n('Gin H1')]['menu_status'] ?? '') === 'Ready for step 4' && ($SH0[$n('Vodka H4')]['menu_status'] ?? '') === 'Needs bottle size'
            && ($SH0[$n('Juice H01')]['menu_status'] ?? '') === 'Needs glass size' && !empty($svH0['cols']['glass']) && !empty($svH0['cols']['shot']), json_encode([$optV, $SH0[$n('Vodka H4')] ?? null]));

        // bulk shot 30 on Gin H1..H3 + Mini H5 (a 50 ml bottle: 60 refused for it only)
        $bBad = import_job_sizes_bulk($pdo, $jH, $admin, ['keys' => [$kH('Gin H1')], 'shot' => 'abc']);
        $ck('mass: text is refused for all and nothing is changed', empty($bBad['ok']) && strpos((string)($bBad['error'] ?? ''), 'Shot size must be a number') === 0, json_encode($bBad));
        $b30 = import_job_sizes_bulk($pdo, $jH, $admin, ['keys' => [$kH('Gin H1'), $kH('Gin H2'), $kH('Gin H3')], 'shot' => '30']);
        [, $SH1] = $sizesH();
        [, $DH1] = $view($jH, $admin);
        $ok30 = true;
        foreach (['Gin H1', 'Gin H2', 'Gin H3'] as $nm) {
            $ok30 = $ok30 && ($SH1[$n($nm)]['shot_from'] ?? '') === 'row' && (float)($SH1[$n($nm)]['shot_ml'] ?? 0) === 30.0
                && abs((float)($DH1[$n($nm)]['cost'] ?? 0) - 20000 * 30 / 750) <= BALANCE_TOLERANCE && ($DH1[$n($nm)]['measure'] ?? '') === '30 ml (shot)';
        }
        $ck('mass: shot 30 ml on three ticked drinks: each a row answer, cost 20000 x 30 / 750 = 800, "30 ml (shot)"; "3 changed"',
            !empty($b30['ok']) && (int)($b30['changed'] ?? -1) === 3 && (int)($b30['skipped'] ?? -1) === 0 && $ok30, json_encode([$b30, $SH1[$n('Gin H1')] ?? null, $DH1[$n('Gin H1')] ?? null]));
        $b60 = import_job_sizes_bulk($pdo, $jH, $admin, ['keys' => [$kH('Gin H3'), $kH('Mini H5')], 'shot' => '60']);
        [, $SH2] = $sizesH();
        $ck('mass: a shot bigger than one drink\'s bottle (60 ml > Mini 50 ml) skips only that drink, with the reason; the other is changed',
            !empty($b60['ok']) && (int)$b60['changed'] === 1 && (int)$b60['skipped'] === 1 && strpos((string)($b60['reasons'][0]['why'] ?? ''), 'more than one whole bottle (50 ml)') !== false
            && in_array($n('Mini H5'), (array)($b60['reasons'][0]['names'] ?? []), true) && ($SH2[$n('Mini H5')]['shot_from'] ?? '') === 'list' && (float)($SH2[$n('Gin H3')]['shot_ml'] ?? 0) === 60.0, json_encode([$b60, $SH2[$n('Mini H5')] ?? null]));
        import_job_sizes_bulk($pdo, $jH, $admin, ['keys' => [$kH('Gin H3')], 'shot' => '30']);
        // bulk bottle 750 + shot 30 on Vodka H4 (no bottle size): one save
        $b750 = import_job_sizes_bulk($pdo, $jH, $admin, ['keys' => [$kH('Vodka H4'), $kH('Gin H1')], 'ml' => '750', 'shot' => '30']);
        [, $SH3] = $sizesH();
        [, $DH3] = $view($jH, $admin);
        $ck('mass: bottle 750 + shot 30 together: Vodka (no size) gets both (cost 800, tickable); Gin H1 (size saved on the item) takes only the shot, "1 already had these"',
            !empty($b750['ok']) && (int)$b750['changed'] === 1 && (int)$b750['same'] === 1 && ($SH3[$n('Vodka H4')]['from'] ?? '') === 'row' && (float)($SH3[$n('Vodka H4')]['ml'] ?? 0) === 750.0
            && abs((float)($DH3[$n('Vodka H4')]['cost'] ?? 0) - 800) <= BALANCE_TOLERANCE && !empty($DH3[$n('Vodka H4')]['tickable']) && ($SH3[$n('Gin H1')]['from'] ?? '') === 'own',
            json_encode([$b750, $SH3[$n('Vodka H4')] ?? null]));
        $bNa = import_job_sizes_bulk($pdo, $jH, $admin, ['keys' => [$kH('Gin H1')], 'glass' => '200']);
        $ck('mass: a size a drink does not use (a glass size on a shot drink) is not put on it: skipped "not sold by the glass"',
            !empty($bNa['ok']) && (int)$bNa['skipped'] === 1 && strpos((string)($bNa['reasons'][0]['why'] ?? ''), 'not sold by the glass') !== false, json_encode($bNa));

        // Tick all shown with a group filter: every glass drink (12, more than one page), no shot drink
        [$svG10] = $sizesH(['group' => 'gl:bottle', 'size' => 10]);
        $bAll = import_job_sizes_bulk($pdo, $jH, $admin, ['all' => true, 'group' => 'gl:bottle', 'q' => '', 'show' => 'all', 'glass' => '250']);
        [, $SH4] = $sizesH();
        $glassOk = true;
        for ($i = 1; $i <= 12; $i++) { $glassOk = $glassOk && ($SH4[$n('Juice H' . sprintf('%02d', $i))]['glass_from'] ?? '') === 'row' && (float)($SH4[$n('Juice H' . sprintf('%02d', $i))]['glass_ml'] ?? 0) === 250.0; }
        $ck('mass: "tick all shown" with the glass group filter covers every page (13 keys on a 10-row page) and changes only that group (Gin H2 untouched)',
            count((array)($svG10['keys'] ?? [])) === 13 && count((array)($svG10['rows'] ?? [])) === 10 && !empty($bAll['ok']) && (int)$bAll['changed'] === 13 && $glassOk
            && ($SH4[$n('Wine H6')]['glass_from'] ?? '') === 'row' && ($SH4[$n('Gin H2')]['glass_need'] ?? true) === false && (float)($SH4[$n('Gin H2')]['shot_ml'] ?? 0) === 30.0,
            json_encode([count((array)($svG10['keys'] ?? [])), $bAll]));

        // Sold as: Gin H2 shot -> glass (+ glass 200); Wine H6 glass -> shot; then Wine H6 back to the file's cell
        $bSg = import_job_sizes_bulk($pdo, $jH, $admin, ['keys' => [$kH('Gin H2')], 'sold' => 'glass', 'glass' => '200']);
        $bWs = import_job_sizes_set($pdo, $jH, $admin, ['mode' => 'row', 'key' => $kH('Wine H6'), 'field' => 'sold', 'value' => 'shot']);
        [, $SH5] = $sizesH();
        [, $DH5] = $view($jH, $admin);
        $g2 = $SH5[$n('Gin H2')] ?? [];
        $w6 = $SH5[$n('Wine H6')] ?? [];
        $ck('sold as: Gin H2 shot -> glass with glass 200 in the same save: glass group, "Your answer" (file cell still shown), cost 20000 x 200 / 750; its shot answer no longer used',
            !empty($bSg['ok']) && ($g2['sold'] ?? '') === 'glass' && ($g2['measure_from'] ?? '') === 'answer' && ($g2['served_cell'] ?? '') === 'Shot' && ($g2['group'] ?? '') === 'gl:bottle'
            && empty($g2['shot_need']) && (float)($g2['glass_ml'] ?? 0) === 200.0 && abs((float)($DH5[$n('Gin H2')]['cost'] ?? 0) - 20000 * 200 / 750) <= BALANCE_TOLERANCE,
            json_encode([$bSg, $g2, $DH5[$n('Gin H2')] ?? null]));
        $ck('sold as: Wine H6 glass -> shot: shot group, the list shot, cost 15000 x ' . $shotMl . ' / 750, "1 shot"',
            !empty($bWs['ok']) && ($w6['sold'] ?? '') === 'shot' && ($w6['group'] ?? '') === 'm:shot|bottle' && !empty($w6['shot_need']) && empty($w6['glass_need'])
            && abs((float)($DH5[$n('Wine H6')]['cost'] ?? 0) - 15000 * $shotMl / 750) <= BALANCE_TOLERANCE && ($DH5[$n('Wine H6')]['measure'] ?? '') === '1 shot', json_encode([$bWs, $w6, $DH5[$n('Wine H6')] ?? null]));
        $bBadSold = import_job_sizes_bulk($pdo, $jH, $admin, ['keys' => [$kH('Wine H6')], 'sold' => 'm:nothing such']);
        $ck('sold as: a choice that is not on the list is refused', empty($bBadSold['ok']), json_encode($bBadSold));
        $bWf = import_job_sizes_set($pdo, $jH, $admin, ['mode' => 'row', 'key' => $kH('Wine H6'), 'field' => 'sold', 'value' => '']);
        [, $SH6] = $sizesH();
        [, $DH6] = $view($jH, $admin);
        $ck('sold as: cleared, Wine H6 goes back to the file\'s cell ("Glass"; its row glass 250 applies again): cost 15000 x 250 / 750',
            !empty($bWf['ok']) && ($SH6[$n('Wine H6')]['sold'] ?? 'x') === '' && ($SH6[$n('Wine H6')]['measure_from'] ?? '') === 'cell' && !empty($SH6[$n('Wine H6')]['glass_need'])
            && abs((float)($DH6[$n('Wine H6')]['cost'] ?? 0) - 15000 * 250 / 750) <= BALANCE_TOLERANCE, json_encode([$bWf, $SH6[$n('Wine H6')] ?? null, $DH6[$n('Wine H6')] ?? null]));
        // Brandy H9 (count measure 30 shots, "66 shots" counted): sold as glass 150 -> the menu changes, the opening count does not
        $bBr = import_job_sizes_bulk($pdo, $jH, $admin, ['keys' => [$kH('Brandy H9 750ml'), $kH('Cognac H10')], 'sold' => 'glass', 'glass' => '150']);
        [, $DH7] = $view($jH, $admin);
        $countH1 = $countH();
        $ck('sold as: Brandy H9 (counted "66 shots") sold as a 150 ml glass costs 20000 x 150 / 750; the opening count, count measure and the file\'s served-by cell the count reads are unchanged',
            !empty($bBr['ok']) && abs((float)($DH7[$n('Brandy H9 750ml')]['cost'] ?? 0) - 20000 * 150 / 750) <= BALANCE_TOLERANCE && $countH0 !== null && $countH0 === $countH1
            && ($countH1['served_by'] ?? '') === 'Shot' && abs((float)($countH1['balance'] ?? 0) - 2.2) < 0.0000005, json_encode([$bBr, $countH0, $countH1, $DH7[$n('Brandy H9 750ml')] ?? null]));

        // permissions unchanged: sizes need Import only (as a one-row save); ticking still needs the Menu permission
        $bMgr = import_job_sizes_bulk($pdo, $jH, $mgrNoMenu, ['keys' => [$kH('Gin H3')], 'shot' => '30']);
        $tMgr = import_job_drinks_set($pdo, $jH, $mgrNoMenu, ['mode' => 'tick', 'key' => $kH('Gin H1'), 'on' => true]);
        $ck('permission: mass set follows the one-row size save (no Menu permission needed); ticking for the menu still needs it',
            !empty($bMgr['ok']) && empty($tMgr['ok']) && stripos((string)($tMgr['error'] ?? ''), 'Menu permission') !== false, json_encode([$bMgr, $tMgr]));

        // import: tick Gin H1 (30 ml shot), Gin H2 (glass 200), Brandy H9 (glass 150), Wine H6 (glass 250)
        foreach (['Gin H1', 'Gin H2', 'Brandy H9 750ml', 'Wine H6'] as $nm) {
            $r = import_job_drinks_set($pdo, $jH, $admin, ['mode' => 'tick', 'key' => $kH($nm), 'on' => true]);
            if (empty($r['ok'])) { $ck('mass: tick ' . $nm, false, json_encode([$r, $DH7[$n($nm)] ?? null])); }
        }
        $cH = import_commit_stock($pdo, $jH, $admin);
        $l1 = $lineOf($n('Gin H1'));
        $l2 = $lineOf($n('Gin H2'));
        $l9 = $lineOf($n('Brandy H9 750ml'));
        $ck('mass import: Gin H1 links 30 ml a sale (30 / 750), Gin H2 sold as a glass links 200 ml (200 / 750), Brandy H9 150 ml (150 / 750)',
            !empty($cH['ok']) && $l1 && $l1['entry_measure'] === 'ml' && abs((float)$l1['entry_qty'] - 30) < 0.000001 && abs((float)$l1['quantity_per_portion'] - round(30 / 750, 6)) < 0.0000005
            && $l2 && abs((float)$l2['quantity_per_portion'] - round(200 / 750, 6)) < 0.0000005 && $l9 && abs((float)$l9['quantity_per_portion'] - round(150 / 750, 6)) < 0.0000005,
            json_encode([$cH['ok'] ?? null, $cH['error'] ?? null, $l1, $l2, $l9]));
        $brHIng = (int)$col('SELECT id FROM stock_ingredients WHERE name = ? ORDER BY id DESC LIMIT 1', [$n('Brandy H9 750ml')]);
        $brLine = $col('SELECT scl.actual_quantity FROM stock_count_lines scl WHERE scl.ingredient_id = ? ORDER BY scl.id DESC LIMIT 1', [$brHIng]);
        $ck('mass import: the opening count of Brandy H9 ("66 shots") is 2.2 bottles, whatever it is sold as', $brLine !== false && abs((float)$brLine - 2.2) < 0.000001, json_encode($brLine));
        if ($measureOn) {
            $ck('mass import: Cognac H10 (its own count measure, 30 shots) sold as a glass keeps its count measure: measure_per_big still 30, shot side',
                abs((float)$col('SELECT measure_per_big FROM stock_ingredients WHERE id = ?', [$coHIng]) - 30) < 0.000001 && (string)$col('SELECT measure_small_name FROM stock_ingredients WHERE id = ?', [$coHIng]) === 'shot'
                && abs((float)$col('SELECT current_quantity FROM stock_ingredients WHERE id = ?', [$coHIng])) < 0.000001);
        }
        $msH = $countH0['measure_save'] ?? null;
        if ($measureOn && is_array($msH)) {
            $ck('mass import: Brandy H9 gets the count measure the file gave (' . $msH['n'] . ' ' . $msH['small'] . ' in one ' . $msH['big'] . '), not one from its "Sold as" glass: measure_per_big as before',
                abs((float)$col('SELECT measure_per_big FROM stock_ingredients WHERE id = ?', [$brHIng]) - (float)$msH['n']) < 0.000001
                && (string)$col('SELECT measure_small_name FROM stock_ingredients WHERE id = ?', [$brHIng]) === (string)$msH['small'], json_encode($msH));
        }
        $ck('mass import: the hotel\'s Drink measures list is unchanged by mass set and Sold as', (string)$col("SELECT setting_value FROM site_settings WHERE setting_key = 'stock_measures_list'") === $listBeforeH
            && (int)$col("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_measures_list_changed'") === $listLogs);
        $ck('mass import: the typed bottle size is saved on Vodka H4 (750), none is put on an item that has one',
            (float)$col('SELECT volume_ml FROM stock_ingredients WHERE name = ? ORDER BY id DESC LIMIT 1', [$n('Vodka H4')]) === 750.0
            && (float)$col('SELECT volume_ml FROM stock_ingredients WHERE name = ? ORDER BY id DESC LIMIT 1', [$n('Mini H5')]) === 50.0);
    }
    // ---- file F (owner 2026-11-22): any item on the menu (food too), grouped by category, a typed price, a suggestion, the money maths ----------
    $headF = ['Item Name', 'Category', 'Unit', 'Unit Price', 'Balance', 'Selling Price', 'Served By'];
    $jF = $start($file($headF, [
        [$n('Crisps'), $n('Snacks'), 'packet', '500', '10', '1200', ''],
        [$n('Burger'), $n('Kitchen'), 'each', '2500', '4', '6000', ''],
        [$n('Rice F'), $n('Kitchen'), 'kg', '1500', '5', '3000', ''],
        [$n('Mystery A'), $n('Misc'), 'each', '400', '3', '1000', ''],
        [$n('Mystery B'), $n('Misc'), 'each', '700', '3', '2000', ''],
        [$n('Tonic NP'), $n('Beers'), 'bottle', '400', '6', '', 'Bottle'],
        [$n('Kuche'), $n('Beers'), 'bottle', '2066.115', '6', '5000', 'Bottle'],
        [$n('Special'), $n('Beers'), 'bottle', '2521.0085', '6', '6000', 'Bottle'],
    ], 'f.csv'), 'zzd-f-' . $run . '.csv');
    $mv = function (array $p = []) use ($pdo, $jF, $admin) {
        $v = import_job_menu_view($pdo, $jF, $admin, $p + ['ai' => false]);
        $g = [];
        foreach ((array)($v['groups'] ?? []) as $x) { $g[$x['label']] = $x; }
        return [$v, $g];
    };
    $rowsF = function () use ($pdo, $jF) {
        $st = import_job_state($pdo, $jF);
        $by = [];
        foreach (import_drinks_build($pdo, (array)$st['res']['items'], (array)$st['answers'])['rows'] as $r) { $by[$r['name']] = $r; }
        return $by;
    };
    [$vF0, $GF0] = $mv();
    $F0 = $rowsF();
    $ck('groups: one group per category heading of the file; Snacks and Kitchen go to Food, Beers to Drinks (certain words); Misc says nothing for certain',
        ($GF0[$n('Snacks')]['menu'] ?? '') === 'food' && ($GF0[$n('Kitchen')]['menu'] ?? '') === 'food' && ($GF0[$n('Beers')]['menu'] ?? '') === 'drink'
        && ($GF0[$n('Misc')]['need_menu'] ?? 0) === 2 && ($GF0[$n('Misc')]['menu'] ?? 'x') === '' && count($vF0['groups'] ?? []) === 4, json_encode($vF0['groups'] ?? $vF0));
    $ck('food: a kg item with a selling price goes to Food (kept by weight), one sale = 1 kg: cost 1500, profit 1500; it can never be a drink',
        ($F0[$n('Rice F')]['menu'] ?? '') === 'food' && ($F0[$n('Rice F')]['menu_from'] ?? '') === 'unit' && abs((float)($F0[$n('Rice F')]['cost'] ?? 0) - 1500) < 0.0001
        && abs((float)($F0[$n('Rice F')]['profit'] ?? 0) - 1500) < 0.0001 && empty(import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'menu', 'key' => $F0[$n('Rice F')]['key'] ?? '', 'value' => 'drink'])['ok']),
        json_encode($F0[$n('Rice F')] ?? null));
    $ck('food: no glass / shot / bottle size is asked for a food item, its category is the file\'s', empty($F0[$n('Crisps')]['size_need']) && ($F0[$n('Crisps')]['category'] ?? '') === $n('Snacks')
        && !empty($F0[$n('Crisps')]['tickable']), json_encode($F0[$n('Crisps')] ?? null));
    $tMis = import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'tick', 'key' => $F0[$n('Mystery A')]['key'] ?? '', 'on' => true]);
    $ck('menu: an uncertain row cannot be ticked until a person answers (Choose menu)', empty($tMis['ok']) && ($F0[$n('Mystery A')]['status'] ?? '') === 'menu', json_encode($tMis));
    // the Assistant (a test double, no paid call): suggests Food + "ZZD Bites" for both Misc rows; shown, never applied until accepted
    $mGroup = (string)($F0[$n('Mystery A')]['group'] ?? '');
    $sugFn = function (array $ask) use ($n) {
        $o = [];
        foreach ($ask as $x) {
            if (strpos((string)$x['name'], 'Mystery') !== false) {
                $o[] = ['key' => $x['key'], 'field' => 'menu', 'value' => 'food', 'source' => (string)$x['name']];
                $o[] = ['key' => $x['key'], 'field' => 'menu_cat', 'value' => $n('Bites'), 'source' => (string)$x['name']];
                $o[] = ['key' => $x['key'], 'field' => 'menu', 'value' => 'pudding', 'source' => 'x'];         // not drink / food: dropped
            }
        }
        return $o;
    };
    $nSug = import_job_sizes_ai_fill($pdo, $jF, $sugFn);
    [, $GF1] = $mv();
    $F1 = $rowsF();
    $ck('suggestion: kept as "Suggested" for the group (menu Food, category Bites), NOT applied: rows still need a menu and cannot be ticked',
        $nSug >= 4 && ($GF1[$n('Misc')]['suggest']['menu'] ?? '') === 'food' && ($GF1[$n('Misc')]['suggest']['cat'] ?? '') === $n('Bites')
        && !empty($F1[$n('Mystery A')]['need_menu']) && empty($F1[$n('Mystery A')]['tickable']), json_encode([$nSug, $GF1[$n('Misc')] ?? null]));
    $acc = import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'accept', 'group' => $mGroup]);
    $F2 = $rowsF();
    $ck('suggestion: one click accepts it for the group: both rows Food, category Bites, tickable (nothing ticked by itself)',
        !empty($acc['ok']) && ($F2[$n('Mystery A')]['menu'] ?? '') === 'food' && ($F2[$n('Mystery B')]['menu_from'] ?? '') === 'group' && ($F2[$n('Mystery B')]['category'] ?? '') === $n('Bites')
        && !empty($F2[$n('Mystery A')]['tickable']) && empty($F2[$n('Mystery A')]['ticked']), json_encode([$acc, $F2[$n('Mystery A')] ?? null]));
    $ovr = import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'menu', 'key' => $F2[$n('Mystery B')]['key'] ?? '', 'value' => 'drink']);
    $F3 = $rowsF();
    $ovrClr = import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'menu', 'key' => $F2[$n('Mystery B')]['key'] ?? '', 'value' => '']);
    $F3b = $rowsF();
    $ck('group answer applies to every row; a row answer wins (Mystery B -> Drinks) and clearing it goes back to the group (Food)',
        !empty($ovr['ok']) && ($F3[$n('Mystery B')]['menu'] ?? '') === 'drink' && ($F3[$n('Mystery B')]['menu_from'] ?? '') === 'row' && ($F3[$n('Mystery A')]['menu'] ?? '') === 'food'
        && !empty($ovrClr['ok']) && ($F3b[$n('Mystery B')]['menu'] ?? '') === 'food', json_encode([$ovr, $F3[$n('Mystery B')]['menu_from'] ?? null]));
    // a typed price: validation, set, clear, "same as the file" = no override; stock untouched
    $kNP = (string)($F0[$n('Tonic NP')]['key'] ?? '');
    $ck('price: an item with no price in the file is listed (heading Beers: Drinks) as Needs price, not tickable',
        ($F0[$n('Tonic NP')]['status'] ?? '') === 'price' && ($F0[$n('Tonic NP')]['menu'] ?? '') === 'drink' && empty($F0[$n('Tonic NP')]['tickable']), json_encode($F0[$n('Tonic NP')] ?? null));
    $bad = [];
    foreach (['1,50', 'abc', '0', '-5', '123456789', '12.345'] as $bp) {
        $rb = import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'price', 'key' => $kNP, 'value' => $bp]);
        if (!empty($rb['ok'])) { $bad[] = $bp; }
    }
    $rNoPerm = import_job_drinks_set($pdo, $jF, $mgrNoMenu, ['mode' => 'price', 'key' => $kNP, 'value' => '1500']);
    $ck('price: refused when not a price (1,50 abc 0 -5 123456789 12.345) and without the Menu permission', !$bad && empty($rNoPerm['ok']) && stripos((string)$rNoPerm['error'], 'Menu permission') !== false, json_encode($bad));
    $rP = import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'price', 'key' => $kNP, 'value' => '1,500.50']);
    $F4 = $rowsF();
    $ck('price: "1,500.50" typed -> 1500.50, from you, the row is tickable; profit 1500.50 - 400 = 1100.50',
        !empty($rP['ok']) && abs((float)($F4[$n('Tonic NP')]['price'] ?? 0) - 1500.5) < 0.000001 && ($F4[$n('Tonic NP')]['price_from'] ?? '') === 'typed'
        && !empty($F4[$n('Tonic NP')]['tickable']) && abs((float)($F4[$n('Tonic NP')]['profit'] ?? 0) - 1100.5) < 0.000001, json_encode($F4[$n('Tonic NP')] ?? null));
    $kCr = (string)($F0[$n('Crisps')]['key'] ?? '');
    import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'price', 'key' => $kCr, 'value' => '1300']);
    $F5 = $rowsF();
    import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'price', 'key' => $kCr, 'value' => '1200']);
    $F5b = $rowsF();
    $ck('price: a typed price wins over the file (Crisps 1300, file 1200); typing the file\'s own price again leaves no override',
        abs((float)($F5[$n('Crisps')]['price'] ?? 0) - 1300) < 0.000001 && abs((float)($F5[$n('Crisps')]['file_price'] ?? 0) - 1200) < 0.000001
        && ($F5b[$n('Crisps')]['price_from'] ?? '') === 'file' && !isset(import_job_answers(import_job_get($pdo, $jF))['_drinks'][$kCr]['price']), json_encode([$F5[$n('Crisps')]['price'] ?? null, $F5b[$n('Crisps')]['price_from'] ?? null]));
    import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'tick', 'key' => $kNP, 'on' => true]);
    $rClr = import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'price', 'key' => $kNP, 'value' => '']);
    $F6 = $rowsF();
    $ck('price: clearing it goes back to the file (none here: Needs price again) and unticks the row', !empty($rClr['ok']) && (int)($rClr['unticked'] ?? 0) === 1
        && ($F6[$n('Tonic NP')]['status'] ?? '') === 'price' && empty($F6[$n('Tonic NP')]['ticked']), json_encode($rClr));
    import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'price', 'key' => $kNP, 'value' => '1500']);
    import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'price', 'key' => $kCr, 'value' => '1300']);
    // money maths: shown cost (2 dp) + profit = price on every row; Kuche 2066.115 -> 2066.12 + 2933.88; Special 2521.0085 -> 2521.01 + 3478.99
    $F7 = $rowsF();
    $sumOk = true;
    foreach ($F7 as $r) {
        if ($r['cost'] !== null && $r['price'] !== null && abs(round((float)$r['cost'], 2) + (float)$r['profit'] - (float)$r['price']) > 0.000001) { $sumOk = false; }
    }
    $ck('maths: profit = price - cost of one to 2 dp (as Stock Items > Put on the menu): shown cost + profit = price on every row (Kuche 2933.88, Special 3478.99)',
        $sumOk && abs((float)($F7[$n('Kuche')]['profit'] ?? 0) - 2933.88) < 0.000001 && abs((float)($F7[$n('Special')]['profit'] ?? 0) - 3478.99) < 0.000001
        && abs((float)($F7[$n('Special')]['cost'] ?? 0) - 2521.0085) < 0.00001, json_encode([$F7[$n('Kuche')]['cost'] ?? null, $F7[$n('Kuche')]['profit'] ?? null, $F7[$n('Special')]['cost'] ?? null]));
    $stm = function ($ingCost, $price) { return round($price - round($ingCost, 2), 2); };     // admin/includes/stock-to-menu-lib.php stm_build, the same sum
    $ck('maths: the same profit as Stock Items > Put on the menu for the same cost and price', abs($stm(2066.115, 5000) - (float)import_menu_profit(5000.0, 2066.115)) < 0.000001
        && abs($stm(2521.0085, 6000) - (float)import_menu_profit(6000.0, 2521.0085)) < 0.000001 && import_menu_profit(null, 1.0) === null);
    // tick all within a group, then the whole list; the step 4 counts (drinks and food apart)
    $gBeers = (string)($F0[$n('Kuche')]['group'] ?? '');
    $taG = import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'tick_all', 'on' => true, 'mgroup' => $gBeers]);
    [$vF8, $GF8] = $mv();
    $ck('tick all in one group: only that group\'s tickable rows (3 Beers), nothing elsewhere', !empty($taG['ok']) && (int)$taG['changed'] === 3 && ($GF8[$n('Beers')]['ticked'] ?? 0) === 3
        && ($vF8['counts']['ticked'] ?? 0) === 3, json_encode([$taG, $vF8['counts'] ?? null]));
    $taAll = import_job_drinks_set($pdo, $jF, $admin, ['mode' => 'tick_all', 'on' => true]);
    [$vF9] = $mv();
    $c9 = $vF9['counts'] ?? [];
    $ck('tick all: every tickable row (8); step 4 counts: 3 drinks + 5 food, 8 new, 0 price changes, 0 below cost',
        !empty($taAll['ok']) && ($c9['ticked'] ?? 0) === 8 && ($c9['ticked_drink'] ?? 0) === 3 && ($c9['ticked_food'] ?? 0) === 5 && ($c9['add'] ?? 0) === 8 && ($c9['update'] ?? -1) === 0
        && ($c9['below'] ?? -1) === 0 && ($c9['todo'] ?? -1) === 0, json_encode($c9));
    $cF = import_commit_stock($pdo, $jF, $admin);
    $resF = $cF['result'] ?? [];
    $fm = $pdo->prepare('SELECT * FROM food_menu WHERE item_name = ?');
    $fm->execute([$n('Crisps')]);
    $crispsMenu = $fm->fetchAll(PDO::FETCH_ASSOC);
    $cpos = rh_menu_pos_find($pdo, $n('Crisps'), $n('Snacks'));
    $crIng = (int)$col('SELECT id FROM stock_ingredients WHERE name = ?', [$n('Crisps')]);
    $link->execute([$cpos['id'] ?? 0, $cpos['slug'] ?? '']);
    $crLk = $link->fetchAll(PDO::FETCH_ASSOC);
    $ck('food import: Crisps is on the FOOD menu once at the typed 1300.00 (kitchen), not the drinks menu; the till has it at 1300; its stock link is one packet',
        !empty($cF['ok']) && count($crispsMenu) === 1 && (string)$crispsMenu[0]['price'] === '1300.00' && $crispsMenu[0]['station'] === 'kitchen' && $crispsMenu[0]['category'] === $n('Snacks')
        && (int)$col('SELECT COUNT(*) FROM drink_menu WHERE item_name = ?', [$n('Crisps')]) === 0 && $cpos && abs((float)$col('SELECT price FROM menu_items WHERE id = ?', [$cpos['id']]) - 1300) < 0.000001
        && count($crLk) === 1 && (int)$crLk[0]['ingredient_id'] === $crIng && abs((float)$crLk[0]['quantity_per_portion'] - 1) < 0.000001, json_encode([$cF['error'] ?? null, $crispsMenu, $crLk]));
    $ck('food import: the result counts drinks and food apart (3 drinks added, 5 food added); Mystery A in Bites, Rice F per kg',
        ($resF['drinks_added'] ?? -1) === 3 && ($resF['food_added'] ?? -1) === 5 && (int)$col('SELECT COUNT(*) FROM food_menu WHERE item_name = ? AND category = ?', [$n('Mystery A'), $n('Bites')]) === 1
        && (int)$col('SELECT COUNT(*) FROM food_menu WHERE item_name = ?', [$n('Rice F')]) === 1, json_encode($resF));
    $ck('price override never touches stock: Crisps cost stays 500 (the file\'s unit price), the opening count line stays 10 packets',
        abs((float)$col('SELECT cost_per_unit FROM stock_ingredients WHERE id = ?', [$crIng]) - 500) < 0.000001
        && abs((float)$col('SELECT actual_quantity FROM stock_count_lines WHERE ingredient_id = ? ORDER BY id DESC LIMIT 1', [$crIng]) - 10) < 0.000001);
    $tl = (string)$col("SELECT details FROM admin_activity_log WHERE action = 'drink_menu_import' AND details LIKE ? ORDER BY id DESC LIMIT 1", ['Import #' . $jF . ':%' . $n('Tonic NP') . '%']);
    $ck('history: a typed price is logged as typed in step 3', strpos($tl, 'typed in step 3') !== false && strpos($tl, '1500.00') !== false, $tl);

    // ---- file M (2026-11-22): the Assistant's menu suggestion rides on the ONE reader call per import, for unclear groups only ----------
    // the reader is the test double rh_ai_import_measure_suggest (never the real one: no paid call from a test shell)
    $stubM = !empty($stubOurs);
    if (!function_exists('rh_ai_import_measure_suggest')) {
        function rh_ai_import_measure_suggest(array $items): array { $GLOBALS['zzd_ai_calls'][] = $items; return (array)($GLOBALS['zzd_ai_reply'] ?? []); }
        $stubM = true;
    }
    if (!function_exists('rh_ai_enabled') && is_file($root . '/includes/ai/ai-core.php')) {
        require_once $root . '/includes/ai/ai-core.php';
    }
    $aiSwitch = function (string $v) use ($pdo) {
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('ai_enabled', ?, 'ai') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
        $pdo->prepare("UPDATE site_settings SET setting_value = '' WHERE setting_key IN ('ai_paused_reason', 'ai_paused_kind')")->execute();
        $pdo->prepare("UPDATE enabled_modules SET is_enabled = 1 WHERE module_key = 'ai_assistant'")->execute();
        if (function_exists('rh_ai_settings')) { rh_ai_settings($pdo, true); }
    };
    $jM = $start($file($headF, [
        [$n('Thing A'), $n('Odds'), 'each', '300', '4', '900', ''],
        [$n('Thing B'), $n('Odds'), 'each', '350', '4', '950', ''],
        [$n('Gadget C'), $n('Gizmo'), 'each', '200', '2', '700', ''],
        [$n('Gadget D'), $n('Gear'), 'each', '200', '2', '700', ''],
        [$n('Chips M'), $n('Snacks'), 'packet', '500', '10', '1200', ''],
        [$n('Pop M'), $n('Mixbag'), 'bottle', '400', '6', '1000', 'Pint'],    // a drink-only measure ("Bottle" alone decides nothing since QA 2026-11-22)
        [$n('Widget M'), $n('Mixbag'), 'each', '400', '6', '1100', ''],
    ], 'm.csv'), 'zzd-m-' . $run . '.csv');
    $mvM = function (array $p = []) use ($pdo, $jM, $admin) {
        $v = import_job_menu_view($pdo, $jM, $admin, $p);
        $g = [];
        foreach ((array)($v['groups'] ?? []) as $x) { $g[$x['label']] = $x; }
        return [$v, $g];
    };
    $rowsM = function () use ($pdo, $jM) {
        $st = import_job_state($pdo, $jM);
        $by = [];
        foreach (import_drinks_build($pdo, (array)$st['res']['items'], (array)$st['answers'])['rows'] as $r) { $by[$r['name']] = $r; }
        return $by;
    };
    $noSug = function ($g) { return is_array($g) && array_key_exists('suggest', $g) && $g['suggest'] === null; };
    $ansM = function () use ($pdo, $jM) { return import_job_answers(import_job_get($pdo, $jM)); };
    $M0 = $rowsM();
    $kM = function (string $nm) use (&$M0, $n) { return (string)($M0[$n($nm)]['key'] ?? ''); };
    $long = str_repeat('Long category ', 36);      // ~500 characters
    $GLOBALS['zzd_ai_calls'] = [];
    $GLOBALS['zzd_ai_reply'] = [
        ['key' => $kM('Thing A'), 'field' => 'menu', 'value' => 'food', 'source' => $n('Thing A')],
        ['key' => $kM('Thing A'), 'field' => 'menu_cat', 'value' => mb_strtolower($n('Bites')), 'source' => $n('Thing A')],   // an existing Food category, other case
        ['key' => $kM('Thing B'), 'field' => 'menu', 'value' => 'Food', 'source' => $n('Thing B')],
        ['key' => $kM('Thing B'), 'field' => 'menu_cat', 'value' => mb_strtoupper($n('Bites')), 'source' => $n('Thing B')],
        ['key' => $kM('Gadget C'), 'field' => 'menu', 'value' => 'pizza', 'source' => 'x'],
        ['key' => $kM('Gadget C'), 'field' => 'menu', 'value' => '', 'source' => 'x'],
        ['key' => $kM('Gadget C'), 'field' => 'menu_cat', 'value' => 'Snacks', 'source' => 'x'],
        ['key' => $kM('Gadget D'), 'field' => 'menu', 'value' => 'food', 'source' => 'x'],
        ['key' => $kM('Gadget D'), 'field' => 'menu_cat', 'value' => $long, 'source' => 'x'],
        ['key' => $kM('Chips M'), 'field' => 'menu', 'value' => 'drink', 'source' => 'x'],          // a certain row: never asked, never used
        ['key' => $kM('Chips M'), 'field' => 'menu_cat', 'value' => 'Beers', 'source' => 'x'],
        ['key' => $kM('Pop M'), 'field' => 'menu', 'value' => 'food', 'source' => 'x'],
        ['key' => $kM('Widget M'), 'field' => 'menu', 'value' => 'drink', 'source' => 'x'],
        ['key' => $kM('Widget M'), 'field' => 'menu_cat', 'value' => $n('Fizz new'), 'source' => 'x'],
    ];
    // Assistant off: no call at all, nothing marked, the same list as with the reader never asked
    $aiSwitch('0');
    [$vOff, $GOff] = $mvM();
    [$vNo] = $mvM(['ai' => false]);
    $strip = function (array $v) { unset($v['size_groups']); return json_encode($v['groups'] ?? null) . json_encode($v['counts'] ?? null); };
    $ck('menu suggestion: Assistant off = no reader call, no suggestion, nothing marked, the list is exactly as without it',
        !empty($vOff['ok']) && $GLOBALS['zzd_ai_calls'] === [] && empty($ansM()['_sizes']['ai_done']) && $strip($vOff) === $strip($vNo)
        && $noSug($GOff[$n('Odds')] ?? null) && ($GOff[$n('Odds')]['need_menu'] ?? 0) === 2, json_encode([$GLOBALS['zzd_ai_calls'], $GOff[$n('Odds')] ?? null]));
    // Assistant on: the page's own load asks the reader once (through the hook when it can: the stub), never again
    $aiSwitch('1');
    $viaHook = $stubM && function_exists('rh_ai_enabled') && rh_ai_enabled($pdo) && is_callable(import_measure_ai_hook($pdo));
    if ($viaHook) {
        [$vOn1] = $mvM();
        [$vOn2, $GM1] = $mvM();
    } else {
        import_job_sizes_ai_fill($pdo, $jM, $stubFn ?? function (array $i): array { $GLOBALS['zzd_ai_calls'][] = $i; return (array)($GLOBALS['zzd_ai_reply'] ?? []); });
        [$vOn2, $GM1] = $mvM(['ai' => false]);
    }
    import_job_sizes_ai_fill($pdo, $jM, function (array $i): array { $GLOBALS['zzd_ai_calls'][] = $i; return []; });
    $sentM = (array)($GLOBALS['zzd_ai_calls'][0] ?? []);
    $askedMenu = array_column(array_filter($sentM, function ($x) { return !empty($x['ask_menu']); }), 'key');
    sort($askedMenu);
    $wantAsk = [$kM('Thing A'), $kM('Thing B'), $kM('Gadget C'), $kM('Gadget D'), $kM('Widget M')];
    sort($wantAsk);
    $ck('menu suggestion: exactly ONE reader call for the import (page loads + a later ask), the menu asked only for the unclear rows (never Chips M, never Pop M)' . ($viaHook ? ' [through the page hook]' : ' [direct]'),
        count($GLOBALS['zzd_ai_calls']) === 1 && $askedMenu === $wantAsk, json_encode(['via_hook' => $viaHook, 'calls' => count($GLOBALS['zzd_ai_calls']), 'asked' => $askedMenu]));
    $M1 = $rowsM();
    $sO = $GM1[$n('Odds')]['suggest'] ?? null;
    $ck('menu suggestion: the unclear group gets "Suggested: Food · Bites" (joined to the existing category whatever its case), not new',
        is_array($sO) && $sO['menu'] === 'food' && $sO['cat'] === $n('Bites') && empty($sO['cat_new']), json_encode($GM1[$n('Odds')] ?? null));
    $ck('menu suggestion: NOT applied until accepted: no group answer, rows still need a menu, not tickable, nothing ticked',
        !isset($ansM()['_menu_g'][$M1[$n('Thing A')]['group'] ?? '-']) && !empty($M1[$n('Thing A')]['need_menu']) && !empty($M1[$n('Thing B')]['need_menu'])
        && empty($M1[$n('Thing A')]['tickable']) && ($GM1[$n('Odds')]['ticked'] ?? -1) === 0, json_encode($M1[$n('Thing A')] ?? null));
    $ck('menu suggestion: bad replies are ignored: menu "pizza" / empty = no suggestion (Gizmo); a 500-character category is dropped (Gear: Food, no category)',
        $noSug($GM1[$n('Gizmo')] ?? null) && empty($M1[$n('Gadget C')]['suggest'])
        && ($M1[$n('Gadget D')]['suggest']['menu'] ?? '') === 'food' && ($M1[$n('Gadget D')]['suggest']['cat'] ?? 'x') === '' && strpos(json_encode($M1[$n('Gadget D')]), 'Long category') === false,
        json_encode([$M1[$n('Gadget C')]['suggest'] ?? null, $M1[$n('Gadget D')]['suggest'] ?? null]));
    $ck('menu suggestion: a certain group is never overridden: Chips M stays Food from its heading with no suggestion; Pop M stays a drink',
        ($M1[$n('Chips M')]['menu'] ?? '') === 'food' && empty($M1[$n('Chips M')]['suggest']) && $noSug($GM1[$n('Snacks')] ?? null)
        && ($M1[$n('Pop M')]['menu'] ?? '') === 'drink' && empty($M1[$n('Pop M')]['suggest']), json_encode([$M1[$n('Chips M')]['suggest'] ?? null, $M1[$n('Pop M')]['menu'] ?? null]));
    $accMix = import_job_drinks_set($pdo, $jM, $admin, ['mode' => 'accept', 'group' => (string)($M1[$n('Widget M')]['group'] ?? '')]);
    $ck('menu suggestion: a group with a certain row gets no group suggestion (accepting it is refused); its unclear row keeps its own, new category marked',
        $noSug($GM1[$n('Mixbag')] ?? null) && empty($accMix['ok']) && ($M1[$n('Widget M')]['suggest']['menu'] ?? '') === 'drink'
        && !empty($M1[$n('Widget M')]['suggest']['cat_new']) && ($rowsM()[$n('Pop M')]['menu_from'] ?? '') !== 'group', json_encode([$accMix, $M1[$n('Widget M')]['suggest'] ?? null]));
    $accO = import_job_drinks_set($pdo, $jM, $admin, ['mode' => 'accept', 'group' => (string)($M1[$n('Thing A')]['group'] ?? '')]);
    $M2 = $rowsM();
    $gAnsO = $ansM()['_menu_g'][$M1[$n('Thing A')]['group'] ?? '-'] ?? [];
    $ck('menu suggestion: "Use this" answers the whole group as a person would (group answer Food + Bites): both rows Food in Bites, tickable, none ticked',
        !empty($accO['ok']) && ($gAnsO['menu'] ?? '') === 'food' && ($gAnsO['cat'] ?? '') === $n('Bites')
        && ($M2[$n('Thing A')]['menu_from'] ?? '') === 'group' && ($M2[$n('Thing B')]['menu'] ?? '') === 'food' && ($M2[$n('Thing B')]['category'] ?? '') === $n('Bites')
        && !empty($M2[$n('Thing A')]['tickable']) && empty($M2[$n('Thing A')]['ticked']) && empty($M2[$n('Thing B')]['ticked'])
        && ($M2[$n('Chips M')]['menu'] ?? '') === 'food' && ($M2[$n('Pop M')]['menu'] ?? '') === 'drink', json_encode([$accO, $gAnsO, $M2[$n('Thing A')] ?? null]));
    [, $GM2] = $mvM(['ai' => false]);
    $ck('menu suggestion: once answered the group shows no suggestion any more', $noSug($GM2[$n('Odds')] ?? null), json_encode($GM2[$n('Odds')] ?? null));
    $aiSwitch('0');

    // ---- file N (QA 2026-11-22): "already on a menu" always beats a group answer; "can" and the Assistant's reading never decide a menu ----
    // one group (no category column) with drinks and food; Lager is on the Drinks menu by its stock link (file A's import)
    $headN = ['Item Name', 'Unit', 'Unit Price', 'Balance', 'Selling Price', 'Served By'];
    $jN = $start($file($headN, [
        [$n('Lager'), 'bottle', '1000', '5', '2700', ''],
        [$n('Pie N'), 'each', '800', '5', '1500', ''],
        [$n('Baked beans N'), 'each', '300', '5', '700', 'Can'],
        [$n('Coke N'), 'each', '300', '5', '600', 'Can'],
        [$n('Odd N'), 'bottle', '9000', '2', '900', 'Sngl msr'],
        [$n('Juice N1'), 'bottle', '1000', '2', '500', 'Glass'],
        [$n('Juice N2'), 'bottle', '1000', '2', '500', 'Glass'],
    ], 'n.csv'), 'zzd-n-' . $run . '.csv');
    $rowsN = function () use ($pdo, $jN) {
        $st = import_job_state($pdo, $jN);
        $by = [];
        foreach (import_drinks_build($pdo, (array)$st['res']['items'], (array)$st['answers'])['rows'] as $r) { $by[$r['name']] = $r; }
        return $by;
    };
    $N0 = $rowsN();
    $ck('menu: "Can" decides nothing on its own (Baked beans N and Coke N, no category: a person chooses); Lager, on the Drinks menu by its stock link, is Drinks with a price change',
        !empty($N0[$n('Baked beans N')]['need_menu']) && !empty($N0[$n('Coke N')]['need_menu']) && empty($N0[$n('Coke N')]['tickable'])
        && ($N0[$n('Lager')]['menu'] ?? '') === 'drink' && ($N0[$n('Lager')]['menu_from'] ?? '') === 'menu' && ($N0[$n('Lager')]['match_how'] ?? '') === 'link'
        && ($N0[$n('Lager')]['action'] ?? '') === 'update', json_encode([$N0[$n('Coke N')] ?? null, $N0[$n('Lager')] ?? null]));
    if ($shotMl !== null) {
        import_job_sizes_ai_fill($pdo, $jN, function (array $ask) use ($n) {
            $o = [];
            foreach ($ask as $x) {
                if ($x['name'] === $n('Odd N')) { $o[] = ['key' => $x['key'], 'field' => 'served_by', 'value' => 'shot', 'source' => 'cell F6 "Sngl msr"']; }
            }
            return $o;
        });
        $N1 = $rowsN();
        $ck('menu: the Assistant reading "Sngl msr" as a shot is not certain: Odd N is read as a shot, still needs a person (only "Suggested: Drinks")',
            ($N1[$n('Odd N')]['measure_from'] ?? '') === 'ai' && !empty($N1[$n('Odd N')]['need_menu']) && empty($N1[$n('Odd N')]['tickable'])
            && ($N1[$n('Odd N')]['suggest']['menu'] ?? '') === 'drink', json_encode($N1[$n('Odd N')] ?? null));
    }
    $gN = (string)($N0[$n('Lager')]['group'] ?? '');
    $oneGroupN = ($N0[$n('Pie N')]['group'] ?? '-') === $gN && ($N0[$n('Juice N1')]['group'] ?? '-') === $gN;
    $rGN = import_job_drinks_set($pdo, $jN, $admin, ['mode' => 'group_menu', 'group' => $gN, 'value' => 'food']);
    $rGNc = import_job_drinks_set($pdo, $jN, $admin, ['mode' => 'group_cat', 'group' => $gN, 'value' => $n('Pies')]);
    $N2 = $rowsN();
    $ck('group answer Food on a mixed group fills only rows with no certain signal (Pie N, Coke N); Lager stays Drinks with its price change, Juice N1 (by the glass) stays Drinks',
        $oneGroupN && !empty($rGN['ok']) && !empty($rGNc['ok']) && ($N2[$n('Pie N')]['menu'] ?? '') === 'food' && ($N2[$n('Pie N')]['menu_from'] ?? '') === 'group'
        && ($N2[$n('Coke N')]['menu'] ?? '') === 'food' && ($N2[$n('Lager')]['menu'] ?? '') === 'drink' && ($N2[$n('Lager')]['menu_from'] ?? '') === 'menu'
        && ($N2[$n('Lager')]['action'] ?? '') === 'update' && ($N2[$n('Lager')]['menu_id'] ?? 0) === $lagerMenu && !empty($N2[$n('Lager')]['tickable'])
        && ($N2[$n('Juice N1')]['menu'] ?? '') === 'drink', json_encode([$rGN, $N2[$n('Lager')] ?? null, $N2[$n('Juice N1')]['menu_from'] ?? null]));
    $kLag = (string)($N2[$n('Lager')]['key'] ?? '');
    $rOv = import_job_drinks_set($pdo, $jN, $admin, ['mode' => 'menu', 'key' => $kLag, 'value' => 'food']);
    $N3 = $rowsN();
    $tOv = import_job_drinks_set($pdo, $jN, $admin, ['mode' => 'tick', 'key' => $kLag, 'on' => true]);
    $stN = import_job_state($pdo, $jN);
    $ansForced = (array)$stN['answers'];
    $ansForced['_drinks'][$kLag]['tick'] = 1;       // a forced tick (never saved): the import must refuse it
    $cErr = '';
    try {
        import_drinks_commit($pdo, (array)$stN['res']['items'], $ansForced, $jN, $admin);
    } catch (RuntimeException $e) {
        $cErr = $e->getMessage();
    }
    $ck('row answer Food on an item already on the Drinks menu: not tickable ("Already on the Drinks menu; change it in Menu Management"), the tick and the import refuse it',
        !empty($rOv['ok']) && empty($N3[$n('Lager')]['tickable']) && strpos((string)($N3[$n('Lager')]['why_not'] ?? ''), 'Already on the Drinks menu; change it in Menu Management') === 0
        && ($N3[$n('Lager')]['status'] ?? '') === 'blocked' && ($N3[$n('Lager')]['menu'] ?? '') === 'drink' && empty($tOv['ok'])
        && strpos($cErr, 'Already on the Drinks menu') !== false && (int)$col('SELECT COUNT(*) FROM food_menu WHERE item_name = ?', [$n('Lager')]) === 0,
        json_encode([$N3[$n('Lager')]['why_not'] ?? null, $N3[$n('Lager')]['status'] ?? null, $tOv, $cErr]));
    $rOvClr = import_job_drinks_set($pdo, $jN, $admin, ['mode' => 'menu', 'key' => $kLag, 'value' => '']);
    foreach (['Lager', 'Pie N'] as $nm) {
        $r = import_job_drinks_set($pdo, $jN, $admin, ['mode' => 'tick', 'key' => $N2[$n($nm)]['key'] ?? '', 'on' => true]);
        if (empty($r['ok'])) { $ck('file N: tick ' . $nm, false, json_encode([$r, $rOvClr])); }
    }
    $tillLager = (int)$col('SELECT COUNT(*) FROM menu_items WHERE item_name = ?', [$n('Lager')]);
    $cN = import_commit_stock($pdo, $jN, $admin);
    $lposN = rh_menu_pos_find($pdo, $n('Lager'), $lagerCat);
    $ck('import: Lager keeps its one Drinks menu row, now 2700.00: no Food copy, no new till item, still one stock link line; Pie N goes on the Food menu',
        !empty($cN['ok']) && (string)$col('SELECT price FROM drink_menu WHERE id = ?', [$lagerMenu]) === '2700.00' && (int)$col('SELECT COUNT(*) FROM drink_menu WHERE item_name = ?', [$n('Lager')]) === 1
        && (int)$col('SELECT COUNT(*) FROM food_menu WHERE item_name = ?', [$n('Lager')]) === 0 && $tillLager === 1 && (int)$col('SELECT COUNT(*) FROM menu_items WHERE item_name = ?', [$n('Lager')]) === 1
        && $lposN && (int)$col('SELECT COUNT(*) FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id WHERE sr.menu_item_id = ? AND sr.menu_type = ?', [$lposN['id'], $lposN['slug']]) === 1
        && (int)$col('SELECT COUNT(*) FROM food_menu WHERE item_name = ?', [$n('Pie N')]) === 1 && ($cN['result']['drinks_updated'] ?? -1) === 1 && ($cN['result']['drinks_added'] ?? -1) === 0,
        json_encode([$cN['ok'] ?? null, $cN['error'] ?? null, $cN['result'] ?? null]));
    // "Can" with a category that IS certain: an existing Drinks menu category (no drink word in it) makes Coke P a drink; "Tins" / "Sides" do not
    $pdo->prepare("INSERT INTO drink_menu (item_name, description, price, category, is_available, display_order, tags, station, show_pos, show_room_service) VALUES (?, '', '1000.00', ?, 1, 1, '', 'bar', 1, 1)")
        ->execute([$n('Pop holder'), $n('Pops')]);
    $jP = $start($file($headF, [
        [$n('Coke P'), $n('Pops'), 'each', '300', '5', '600', 'Can'],
        [$n('Beans P'), $n('Tins'), 'each', '300', '5', '700', 'Can'],
        [$n('Hot chocolate P'), $n('Sides'), 'each', '300', '5', '900', 'Cup'],
    ], 'p.csv'), 'zzd-p-' . $run . '.csv');
    $stP = import_job_state($pdo, $jP);
    $P0 = [];
    foreach (import_drinks_build($pdo, (array)$stP['res']['items'], (array)$stP['answers'])['rows'] as $r) { $P0[$r['name']] = $r; }
    $ck('menu: Coke P sold by "Can" is a drink only because its category is an existing Drinks menu category; Beans P ("Tins", "Can") and Hot chocolate P ("Sides") need a person',
        ($P0[$n('Coke P')]['menu'] ?? '') === 'drink' && ($P0[$n('Coke P')]['menu_from'] ?? '') === 'menu_cat' && !empty($P0[$n('Beans P')]['need_menu'])
        && !empty($P0[$n('Hot chocolate P')]['need_menu']), json_encode([$P0[$n('Coke P')] ?? null, $P0[$n('Beans P')]['menu'] ?? null, $P0[$n('Hot chocolate P')]['menu'] ?? null]));

    // ---- the count-unit question (owner 2026-11-22): a bar sheet laid out like the owner's bar workbook, where the count of a drink sold by the
    // shot is the SHOTS left (57 = 57 shots) and the Stock Value column is count x the price of one shot. Never guessed: asked, blocks confirm.
    $headC = ['Item Name', 'Categories', 'Total Balance', 'Unit Price (MWK)', 'Selling price', 'Stock Value', 'Unit Bought As', 'Total Per Unit', 'Unit sold/Served by'];
    $rC = import_job_create($pdo, $file($headC, [
        [$n('Premier C'), 'BRANDYS', '57', '30000', '4000', '228000', 'Bottle', '1', 'Shot'],
        [$n('Klip C'), 'BRANDYS', '50', '32000', '4500', '225000', 'Bottle', '1', 'Shot'],
        [$n('Lager C'), 'LOCAL BEERS', '94', '50420', '6000', '564000', 'Crate', '20', 'Bottle'],
    ], 'c.csv'), 'zzd-c-' . $run . '.csv', $admin, ['from_upload' => false]);
    $jC = (int)($rC['job_id'] ?? 0);
    $made[] = import_job_get($pdo, $jC)['stored_path'] ?? null;
    import_job_analyse($pdo, $jC);
    for ($i = 0; $i < 40; $i++) {           // every other blocking question the safe way; the count-unit question is left open
        $s = import_job_review_summary($pdo, $jC);
        $open = null;
        foreach ($s['questions'] as $q) { if ($q['blocking'] && $q['answer'] === null && $q['options'] && $q['type'] !== 'count-in') { $open = $q; break; } }
        if (!$open) { break; }
        $keys = array_column($open['options'], 'key');
        import_job_answer($pdo, $jC, $open['id'], in_array('per-unit', $keys, true) ? 'per-unit' : $keys[0], $admin);
    }
    $cItems = function () use ($pdo, &$jC) {
        $st = import_job_state($pdo, $jC);
        $o = [];
        foreach ((array)$st['res']['items'] as $it) { $o[$it['name']] = $it; }
        return $o;
    };
    $sC = import_job_review_summary($pdo, $jC);
    $qC = array_values(array_filter($sC['questions'], function ($q) { return $q['type'] === 'count-in'; }));
    $vC = import_job_review_view($pdo, $jC, [], ['facets' => true]);
    $sheetQ = array_column((array)($vC['page']['sheet_questions'] ?? []), 'type');
    $ck('count unit: ONE question "is it in bottles or in shots?" for the 2 drinks sold by the shot, shown once at the top of step 3, with the evidence as a suggestion; unanswered = cannot confirm',
        count($qC) === 1 && $qC[0]['answer'] === null && $qC[0]['blocking'] && count($qC[0]['count_rows'] ?? []) === 2 && ($qC[0]['suggest']['key'] ?? '') === 'measure'
        && strpos((string)$qC[0]['text'], 'is it in bottles or in shots?') !== false && in_array('count-in', $sheetQ, true) && !import_job_can_confirm($pdo, $jC)
        && ($cItems()[$n('Premier C')]['status'] ?? '') === 'needs-answer' && ($cItems()[$n('Lager C')]['status'] ?? '') === 'ready',
        json_encode([$qC[0]['text'] ?? null, $qC[0]['suggest'] ?? null, $sheetQ]));
    $qid = $qC[0]['id'] ?? '';
    $uidOf = [];
    foreach ((array)($qC[0]['count_rows'] ?? []) as $cr) { $uidOf[$cr['name']] = $cr['uid']; }
    $aM = import_job_answer($pdo, $jC, $qid, 'measure', $admin);
    // no bottle size yet: the drinks wait ("how many shots in one bottle?"), but are listed in the step 3 sizes so the sizes can be typed there
    [$svC0] = [import_job_sizes_view($pdo, $jC, $admin, ['size' => 100, 'ai' => false])];
    $grpC = array_column((array)($svC0['groups'] ?? []), 'key');
    [, $C0] = $view($jC, $admin);
    $ck('count unit "in shots" with no bottle size: the drinks wait for "How many shots in one bottle?", and are listed under the step 3 sizes (not tickable for the menu yet)',
        !empty($aM['ok']) && ($cItems()[$n('Premier C')]['status'] ?? '') === 'needs-answer' && ($cItems()[$n('Premier C')] ?? ['balance' => 1])['balance'] === null
        && in_array('m:shot|bottle', $grpC, true) && isset($C0[$n('Premier C')]) && empty($C0[$n('Premier C')]['tickable'])
        && preg_match('/is in shots and is not worked out yet|Add bottle size in Step 3/', (string)($C0[$n('Premier C')]['why_not'] ?? '')) === 1,
        json_encode([$aM, $grpC, $C0[$n('Premier C')]['why_not'] ?? null]));
    $z1 = import_job_sizes_set($pdo, $jC, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'field' => 'ml', 'value' => '750']);
    $z2 = import_job_sizes_set($pdo, $jC, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'field' => 'shot', 'value' => '30']);
    $I1 = $cItems();
    [, $C1] = $view($jC, $admin);
    $pc = $I1[$n('Premier C')] ?? [];
    $ck('count unit "in shots" + step 3 sizes 750 ml bottle / 30 ml shot: 57 shots = 2.28 bottles in the opening count (exact), "57 shots = 2 bottles + 7 shots"; cost 30,000 a bottle, not divided',
        !empty($z1['ok']) && !empty($z2['ok']) && ($pc['status'] ?? '') === 'ready' && abs((float)($pc['balance'] ?? 0) - 2.28) < 1e-9 && abs((float)($pc['cost'] ?? 0) - 30000) < 1e-9
        && ($pc['qty_words']['text'] ?? '') === '57 shots = 2 bottles + 7 shots' && abs((float)($I1[$n('Klip C')]['balance'] ?? 0) - 2) < 1e-9 && import_job_can_confirm($pdo, $jC),
        json_encode([$z1, $z2, $pc['balance'] ?? null, $pc['qty_how'] ?? null, $pc['qty_words'] ?? null]));
    $ck('count unit: "Put items on the menu" is unchanged by the answer: one shot costs the bottle cost x 30 / 750 = 1,200 (never the count)',
        abs((float)($C1[$n('Premier C')]['cost'] ?? 0) - 1200) <= BALANCE_TOLERANCE && !empty($C1[$n('Premier C')]['tickable']) && ($C1[$n('Premier C')]['measure'] ?? '') === '30 ml (shot)',
        json_encode($C1[$n('Premier C')] ?? null));
    // the bottle size changed in step 3 re-works the count
    import_job_sizes_set($pdo, $jC, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'field' => 'ml', 'value' => '1000']);
    $ck('count unit: changing the bottle size in step 3 (1,000 ml) re-works the count: 57 / (1000 / 30) = 1.710002 bottles',
        abs((float)($cItems()[$n('Premier C')]['balance'] ?? 0) - round(57 / 33.3333, 6)) < 1e-9, json_encode($cItems()[$n('Premier C')]['balance'] ?? null));
    import_job_sizes_set($pdo, $jC, $admin, ['mode' => 'group', 'key' => 'm:shot|bottle', 'field' => 'ml', 'value' => '750']);
    // one drink differs from its group
    $rKs = import_job_count_row($pdo, $jC, $qid, $uidOf[$n('Klip C')] ?? '', 'stock', $admin);
    $rBadU = import_job_count_row($pdo, $jC, $qid, 'nope', 'stock', $admin);
    $rBadK = import_job_count_row($pdo, $jC, $qid, $uidOf[$n('Klip C')] ?? '', 'cases', $admin);
    $I2 = $cItems();
    $ck('count unit: a drink\'s own answer ("Klip C" in bottles: 50) wins over the group; a drink not in the question or a word that is not a choice is refused',
        !empty($rKs['ok']) && abs((float)($I2[$n('Klip C')]['balance'] ?? 0) - 50) < 1e-9 && abs((float)($I2[$n('Premier C')]['balance'] ?? 0) - 2.28) < 1e-9
        && empty($rBadU['ok']) && empty($rBadK['ok']), json_encode([$rKs['error'] ?? null, $rBadU['error'] ?? null, $I2[$n('Klip C')]['balance'] ?? null]));
    import_job_count_row($pdo, $jC, $qid, $uidOf[$n('Klip C')] ?? '', '', $admin);
    // "in bottles" = the result before this question; the menu cost of one is the same
    import_job_answer($pdo, $jC, $qid, 'stock', $admin);
    $I3 = $cItems();
    [, $C3] = $view($jC, $admin);
    $ck('count unit "in bottles": 57 bottles (today\'s result), no words; one shot still costs 1,200 on the menu',
        abs((float)($I3[$n('Premier C')]['balance'] ?? 0) - 57) < 1e-9 && empty($I3[$n('Premier C')]['qty_words'])
        && abs((float)($C3[$n('Premier C')]['cost'] ?? 0) - 1200) <= BALANCE_TOLERANCE, json_encode([$I3[$n('Premier C')]['balance'] ?? null, $C3[$n('Premier C')]['cost'] ?? null]));
    // import it "in shots": the opening count gets 2.28 bottles at 30,000 (value 68,400), the step 3 bottle size is saved on the item
    import_job_answer($pdo, $jC, $qid, 'measure', $admin);
    $cC = import_commit_stock($pdo, $jC, $admin);
    $ingC = (int)$col('SELECT id FROM stock_ingredients WHERE name = ?', [$n('Premier C')]);
    $lnC = $pdo->prepare('SELECT actual_quantity, cost_per_unit FROM stock_count_lines WHERE count_id = ? AND ingredient_id = ?');
    $lnC->execute([(int)($cC['result']['opening_count_id'] ?? 0), $ingC]);
    $lnC = $lnC->fetch(PDO::FETCH_ASSOC) ?: [];
    $ck('count unit at import: the opening count line is 2.28 bottles at 30,000 (value 68,400), the 750 ml bottle size typed in step 3 is saved on the item',
        !empty($cC['ok']) && $ingC > 0 && abs((float)($lnC['actual_quantity'] ?? 0) - 2.28) < 0.0000005 && abs((float)($lnC['cost_per_unit'] ?? 0) - 30000) < 0.005
        && abs((float)($lnC['actual_quantity'] ?? 0) * (float)($lnC['cost_per_unit'] ?? 0) - 68400) < 0.01
        && abs((float)$col('SELECT volume_ml FROM stock_ingredients WHERE id = ?', [$ingC]) - 750) < 0.005,
        json_encode([$cC['ok'] ?? null, $cC['error'] ?? null, $lnC]));

    // the import's own wording when a drink's stock item is gone (49ddf7d had made it "Nothing was saved.", the Put on the menu wording)
    $linkErr = '';
    try {
        import_drinks_menu_write($pdo, ['action' => 'add', 'name' => $n('No Stock Link'), 'price' => 100, 'category' => $n('Link Err'), 'measure' => '1 bottle', 'link' => null],
            0, $admin, false, 'test');
    } catch (RuntimeException $e) {
        $linkErr = $e->getMessage();
    }
    // the import's commit (includes/import/commit-stock.php) starts every stop with "Nothing was imported: ", so the writer adds no ending: said once
    $shown = 'Nothing was imported: ' . $linkErr;
    $ck('import: a ticked drink whose stock item is gone stops with "Nothing was imported: ... could not be made." (said once)', substr($linkErr, -strlen('could not be made.')) === 'could not be made.'
        && substr_count($shown, 'Nothing was imported') === 1, $shown);
} catch (Throwable $e) {
    $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made as $m) { if ($m) { import_delete_stored((string)$m); } }
    foreach ($tmpFiles as $f) { @unlink($f); }
    @rmdir($tmp);
}
echo json_encode(['checks' => $checks]);
