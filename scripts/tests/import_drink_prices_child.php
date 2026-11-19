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
} catch (Throwable $e) {
    $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made as $m) { if ($m) { import_delete_stored((string)$m); } }
    foreach ($tmpFiles as $f) { @unlink($f); }
    @rmdir($tmp);
}
echo json_encode(['checks' => $checks]);
