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
        $ck('sizes: an item with its own saved size is never asked and costs one shot from it (18000 x ' . $shotMl . ' / 700)',
            !isset($S0[$n('Rum')]) && !empty($D0[$n('Rum')]['tickable']) && abs((float)$D0[$n('Rum')]['cost'] - 18000 * $shotMl / 700) <= BALANCE_TOLERANCE, json_encode($D0[$n('Rum')] ?? null));
        $ck('sizes: a size in the item name is shown as read from the file (its cell), and the drink can be ticked without an answer',
            ($S0[$n('Brandy 750ml')]['from'] ?? '') === 'name' && abs((float)($S0[$n('Brandy 750ml')]['ml'] ?? 0) - 750) < 0.000001 && preg_match('/A5$/', (string)($S0[$n('Brandy 750ml')]['name_source'] ?? '')) === 1
            && !empty($D0[$n('Brandy 750ml')]['tickable']), json_encode($S0[$n('Brandy 750ml')] ?? null));
        $groups = array_column((array)($sv0['groups'] ?? []), null, 'key');
        $ck('sizes: one group for the shot drinks kept in bottles, one for the glass drinks (Red Wine, Glass 175ml: its 175 ml read from the file); blank by default; counts',
            isset($groups['m:shot|bottle'], $groups['gl:bottle']) && $groups['m:shot|bottle']['ml'] === null && ($sv0['counts']['need'] ?? 0) === 5 && ($sv0['counts']['filled'] ?? -1) === 1,
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
    }
} catch (Throwable $e) {
    $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made as $m) { if ($m) { import_delete_stored((string)$m); } }
    foreach ($tmpFiles as $f) { @unlink($f); }
    @rmdir($tmp);
}
echo json_encode(['checks' => $checks]);
