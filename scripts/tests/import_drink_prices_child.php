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
        [$n('Rice'), $n('Food'), 'kg', '1500', '5', '3000', ''],
        [$n('Water'), $n('Softs'), 'bottle', '300', '5', '', ''],
        [$n('Gin'), $n('Spirits'), 'bottle', '20000', '2', '1500', $measureOn ? 'shot' : ''],
    ], 'a.csv'), 'zzd-a-' . $run . '.csv');
    [$vA, $A] = $view($jA, $admin);
    $ck('list: only drinks with a selling price are offered (food by weight and a blank price are not)', $vA['ok'] && isset($A[$n('Cola')], $A[$n('Lager')], $A[$n('Cheap')], $A[$n('Gin')]) && !isset($A[$n('Rice')]) && !isset($A[$n('Water')]), json_encode(array_keys($A)) . ' ' . json_encode($vA['error'] ?? ''));
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
    [, $B] = $view($jB, $admin);
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
} catch (Throwable $e) {
    $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made as $m) { if ($m) { import_delete_stored((string)$m); } }
    foreach ($tmpFiles as $f) { @unlink($f); }
    @rmdir($tmp);
}
echo json_encode(['checks' => $checks]);
