<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Scenarios for scripts/tests/stock_to_menu_test.php (run by page_harness.php: ONE outer transaction that can never commit and is
 * rolled back at the end). Prints one JSON line: {"checks":[[name, ok, detail], ...]}. Every row is named 'ZZM <run> ...'
 * (made-up drinks, costs and prices).
 */
if (defined('DB_NAME') && stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    echo json_encode(['checks' => [["refused: Rosalyn's is live, run this from the Liwonde repo", false, '']]]), "\n";
    exit;
}
$run = (string)($_POST['run'] ?? 'X');
$checks = [];
$ck = function (string $n, $c, string $d = '') use (&$checks) { $checks[] = [$n, (bool)$c, $d]; };

try {
    require_once $root . '/admin/includes/permissions.php';
    require_once $root . '/admin/includes/stock-to-menu-lib.php';
    $n = function (string $s) use ($run) { return 'ZZM ' . $run . ' ' . $s; };
    $col = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
    $admin = (int)($_SESSION['admin_user_id'] ?? 0);
    // the hotel's Drink measures list for this run: shot 25 ml, glass of wine 175 ml
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('stock_measures_list', ?, 'stock') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([json_encode([['name' => 'shot', 'ml' => 25], ['name' => 'glass of wine', 'ml' => 175]])]);
    unset($GLOBALS['_SITE_SETTINGS']['stock_measures_list']);
    if (function_exists('deleteCache')) { deleteCache('setting_stock_measures_list'); }
    $measureOn = rh_stock_measure_ready($pdo);

    $mk = function (string $name, string $unit, float $cost, ?float $vol = null, string $cat = 'ZZM') use ($pdo, $n) {
        $pdo->prepare('INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit' . ($vol !== null ? ', volume_ml' : '') . ') VALUES (?, ?, ?, 0, ?' . ($vol !== null ? ', ?' : '') . ')')
            ->execute(array_merge([$n($name), $cat, $unit, $cost], $vol !== null ? [$vol] : []));
        return (int)$pdo->lastInsertId();
    };
    $gin = $mk('Gin 750', 'bottle', 15000, 750);
    $vodka = $mk('Vodka', 'bottle', 12000);
    if ($measureOn) {
        $pdo->prepare("UPDATE stock_ingredients SET measure_big_name = 'bottle', measure_small_name = 'shot', measure_per_big = 30, measure_stock_side = 'big' WHERE id = ?")->execute([$vodka]);
    }
    $lager = $mk('Lager', 'bottle', 800, null, $n('Softs'));
    $cola = $mk('Cola', 'can', 500);
    $sugar = $mk('Sugar', 'kg', 2000);
    $rum = $mk('Rum', 'bottle', 9000);
    $same1 = $mk('Same A', 'bottle', 100);
    $same2 = $mk('Same B', 'bottle', 100);

    // Cola already on the drinks menu (ZZM Softs, 1000) with its till copy and a one-can stock link: written the import's way
    $w = import_drinks_menu_write($pdo, ['action' => 'add', 'name' => $n('Cola'), 'price' => 1000, 'category' => $n('Softs'), 'measure' => '1 can',
        'link' => ['kind' => 'unit', 'qty' => 1, 'name' => 'can']], $cola, $admin, rh_stock_drink_ready($pdo), 'test');
    $ck('setup: Cola is on the drinks menu with a till copy and a stock link', $w['action'] === 'add' && $w['linked'] && $w['menu_id'] > 0, json_encode($w));

    $rowsOf = function (array $b) { $o = []; foreach ($b['rows'] as $r) { $o[$r['ing_id']] = $r; } return $o; };

    // ---- build: Sold as choices, cost of one, defaults, statuses
    $B = $rowsOf(stm_build($pdo, [['ing_id' => $gin], ['ing_id' => $vodka], ['ing_id' => $lager], ['ing_id' => $cola], ['ing_id' => $sugar], ['ing_id' => $rum, 'sold' => 'm:shot']]));
    $optV = function ($r) { return array_column($r['sold_options'], 'v'); };
    $ck('build: a bottle with a size can be sold as 1 bottle, a shot (25 ml) or a glass of wine; default 1 bottle', $B[$gin]['sold'] === 'unit'
        && $optV($B[$gin]) === ['unit', 'm:shot', 'm:glass of wine'] && abs((float)$B[$gin]['cost'] - 15000) < 0.0001, json_encode($B[$gin]));
    if ($measureOn) {
        $ck('build: an item with its own count measure (30 shots) is sold by the shot by default, cost 12000 / 30 = 400', $B[$vodka]['sold'] === 'm:shot'
            && abs((float)$B[$vodka]['cost'] - 400) < 0.005 && strpos($B[$vodka]['measure'], '30 in one bottle') !== false, json_encode($B[$vodka]));
    }
    $ck('build: the stock category becomes the menu category when the drinks menu has it; else Bar', $B[$lager]['category'] === $n('Softs') && $B[$gin]['category'] === 'Bar', $B[$lager]['category'] . ' / ' . $B[$gin]['category']);
    $ck('build: Cola is found on the menu by its stock link: Price change shown once a price is typed, category locked', $B[$cola]['action'] === 'update'
        && $B[$cola]['category_locked'] && $B[$cola]['sold_locked'] && $B[$cola]['ready'] === false && strpos($B[$cola]['status_text'], 'Type the new price') !== false, json_encode($B[$cola]));
    $ck('build: an item kept by weight cannot go on the menu, with the reason', $B[$sugar]['status'] === 'blocked' && stripos($B[$sugar]['why_not'], 'kept by weight') !== false, $B[$sugar]['why_not']);
    $ck('build: a bottle with no size cannot be sold by the shot: refused with what to set', $B[$rum]['status'] === 'blocked'
        && strpos($B[$rum]['why_not'], 'cannot be sold by "shot"') !== false && stripos($B[$rum]['why_not'], 'size in ml') !== false && $optV($B[$rum]) === ['unit'], $B[$rum]['why_not']);
    $P = $rowsOf(stm_build($pdo, [['ing_id' => $gin, 'sold' => 'm:shot', 'price' => '100', 'tick' => 1], ['ing_id' => $lager, 'price' => 'abc', 'tick' => 1]]));
    $ck('build: cost over price warns and stays ready (shot cost 500 > price 100)', $P[$gin]['below'] && $P[$gin]['ready'] && $P[$gin]['warn'] !== '' && abs((float)$P[$gin]['cost'] - 500) < 0.005, json_encode($P[$gin]));
    $ck('build: a price that is not a number is refused', !$P[$lager]['ready'] && stripos($P[$lager]['why_not'], 'price must be a number') !== false, $P[$lager]['why_not']);
    $D = $rowsOf(stm_build($pdo, [['ing_id' => $same1, 'name' => $n('Same'), 'price' => '10', 'tick' => 1], ['ing_id' => $same2, 'name' => $n('Same'), 'price' => '10', 'tick' => 1]]));
    $ck('build: two ticked rows that are the same drink: neither goes', !$D[$same1]['ready'] && !$D[$same2]['ready'] && strpos($D[$same1]['why_not'], 'Untick one') !== false, json_encode([$D[$same1]['why_not'], $D[$same2]['why_not']]));

    // ---- no Menu permission: refused, nothing written
    $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'manager', 1)")
        ->execute([substr($n('mgr'), 0, 90), strtolower(preg_replace('/\W+/', '', $n('mgr'))) . '@example.invalid', $n('mgr')]);
    $mgr = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'menu', 0)")->execute([$mgr]);
    $drinksBefore = (int)$col('SELECT COUNT(*) FROM drink_menu');
    $s0 = stm_save($pdo, $mgr, [['ing_id' => $lager, 'price' => '2000', 'tick' => 1]]);
    $ck('save: no Menu permission is refused, nothing written', !$s0['ok'] && stripos($s0['error'], 'Menu permission') !== false && (int)$col('SELECT COUNT(*) FROM drink_menu') === $drinksBefore, json_encode($s0));

    // ---- the menu lock: held elsewhere = refused, nothing written
    $lockName = import_drinks_menu_lock_name();
    $other = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $held = (int)$other->query('SELECT GET_LOCK(' . $other->quote($lockName) . ', 5)')->fetchColumn() === 1;
    $t0 = microtime(true);
    $s1 = $held ? stm_save($pdo, $admin, [['ing_id' => $lager, 'price' => '2000', 'tick' => 1]]) : ['ok' => true];
    $other->query('SELECT RELEASE_LOCK(' . $other->quote($lockName) . ')');
    $other = null;
    $ck('save: while somebody holds the menu lock nothing is saved (plain message)', $held && !$s1['ok'] && stripos((string)$s1['error'], 'saving a menu item right now') !== false
        && (int)$col('SELECT COUNT(*) FROM drink_menu') === $drinksBefore, json_encode([$held, $s1, round(microtime(true) - $t0, 1)]));

    // ---- save: new by bottle, by list shot, by own count measure; Cola price change; Sugar skipped
    $logBefore = (int)$col("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'drink_menu_from_stock'");
    $in = [
        ['ing_id' => $lager, 'name' => $n('Lager'), 'sold' => 'unit', 'price' => '2,000', 'category' => '', 'tick' => 1],
        ['ing_id' => $gin, 'name' => $n('Gin Shot'), 'sold' => 'm:shot', 'price' => '1500', 'category' => 'Bar', 'tick' => 1],
        ['ing_id' => $cola, 'name' => $n('Cola'), 'sold' => 'unit', 'price' => '1200.5', 'category' => 'Bar', 'tick' => 1],
        ['ing_id' => $sugar, 'price' => '10', 'tick' => 1],
        ['ing_id' => $rum, 'price' => '999', 'tick' => 0],
    ];
    if ($measureOn) {
        $in[] = ['ing_id' => $vodka, 'name' => $n('Vodka Shot'), 'sold' => 'm:shot', 'price' => '1000', 'category' => 'Bar', 'tick' => 1];
    }
    $s2 = stm_save($pdo, $admin, $in);
    $ck('save: ok, ' . ($measureOn ? '3' : '2') . ' added, Cola updated, Sugar skipped with its reason, the unticked Rum ignored', $s2['ok'] && count($s2['added']) === ($measureOn ? 3 : 2) && count($s2['updated']) === 1
        && count($s2['skipped']) === 1 && stripos($s2['skipped'][0]['why'], 'weight') !== false, json_encode($s2));
    $dm = function (string $name) use ($pdo) { $st = $pdo->prepare('SELECT * FROM drink_menu WHERE item_name = ?'); $st->execute([$name]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; };
    $line = function (string $name, string $cat) use ($pdo, $col) {
        $p = rh_menu_pos_find($pdo, $name, $cat);
        if (!$p) { return null; }
        $st = $pdo->prepare('SELECT sri.ingredient_id, sri.quantity_per_portion, (SELECT COUNT(*) FROM stock_recipe_ingredients x WHERE x.recipe_id = sr.id) AS n FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id WHERE sr.menu_item_id = ? AND sr.menu_type = ?');
        $st->execute([$p['id'], $p['slug']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return ['pos' => $p, 'price' => $col('SELECT price FROM menu_items WHERE id = ?', [$p['id']]), 'line' => $r ?: null];
    };
    $l = $dm($n('Lager'));
    $lL = $line($n('Lager'), $n('Softs'));
    $ck('save: Lager is on the drinks menu at exactly 2000.00 (VAT not added), in its stock category, bar, available', $l && (string)$l['price'] === '2000.00' && $l['category'] === $n('Softs')
        && $l['station'] === 'bar' && (int)$l['is_available'] === 1, json_encode($l));
    $ck('save: Lager has a till copy at 2000.00 and a stock link of 1 bottle', $lL && (string)$lL['price'] === '2000.00' && $lL['line'] && (int)$lL['line']['ingredient_id'] === $lager
        && abs((float)$lL['line']['quantity_per_portion'] - 1) < 0.000001 && (int)$lL['line']['n'] === 1, json_encode($lL));
    $gL = $line($n('Gin Shot'), 'Bar');
    $ck('save: Gin Shot (list shot 25 ml of a 750 ml bottle) links 25 / 750 of a bottle, till price 1500.00', $gL && (string)$gL['price'] === '1500.00' && $gL['line']
        && abs((float)$gL['line']['quantity_per_portion'] - round(25 / 750, 6)) < 0.0000005, json_encode($gL));
    if ($measureOn) {
        $vL = $line($n('Vodka Shot'), 'Bar');
        $ck('save: Vodka Shot (its own count measure, 30 shots) links 1 / 30 of a bottle', $vL && $vL['line'] && abs((float)$vL['line']['quantity_per_portion'] - round(1 / 30, 6)) < 0.0000005, json_encode($vL));
    }
    $c = $dm($n('Cola'));
    $cL = $line($n('Cola'), $n('Softs'));
    $ck('save: Cola price 1000.00 -> 1200.50 on the menu and the till; category kept (Bar typed is ignored); stock link kept (one line, 1 can)', $c && (string)$c['price'] === '1200.50'
        && $c['category'] === $n('Softs') && $cL && (string)$cL['price'] === '1200.50' && $cL['line'] && (int)$cL['line']['n'] === 1 && abs((float)$cL['line']['quantity_per_portion'] - 1) < 0.000001, json_encode([$c, $cL]));
    $ck('save: the weight item and the unticked item were not written', $dm($n('Sugar')) === null && $dm($n('Rum')) === null);
    $logs = $pdo->prepare("SELECT user_id, details FROM admin_activity_log WHERE action = 'drink_menu_from_stock' ORDER BY id DESC LIMIT 10");
    $logs->execute();
    $logRows = $logs->fetchAll(PDO::FETCH_ASSOC);
    $colaLog = array_values(array_filter($logRows, function ($r) use ($n) { return strpos($r['details'], 'from 1000.00 to 1200.50') !== false && strpos($r['details'], $n('Cola')) !== false; }));
    $ck('save: one log row per drink, who = the user, the price change logged old -> new', (int)$col("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'drink_menu_from_stock'") - $logBefore === ($measureOn ? 4 : 3)
        && $colaLog && (int)$colaLog[0]['user_id'] === $admin, json_encode($logRows));
    $A = $rowsOf(stm_build($pdo, [['ing_id' => $lager, 'price' => '2000']]));
    $ck('build after save: Lager shows "Already on the menu at this price"', $A[$lager]['status'] === 'same', json_encode($A[$lager]));

    // ---- matched by name: a renamed row with an existing drink's name updates that drink
    $before = (int)$col('SELECT COUNT(*) FROM drink_menu');
    $s3 = stm_save($pdo, $admin, [['ing_id' => $rum, 'name' => $n('Rum'), 'price' => '700', 'tick' => 1], ['ing_id' => $same1, 'name' => $n('Lager'), 'price' => '1', 'tick' => 1]]);
    $ck('save: a row matched to an existing drink by name is a price change, not a second drink', $s3['ok'] && count($s3['added']) === 1 && count($s3['updated']) === 1
        && (int)$col('SELECT COUNT(*) FROM drink_menu') === $before + 1 && (string)$dm($n('Lager'))['price'] === '1.00', json_encode($s3));
} catch (Throwable $e) {
    $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
echo json_encode(['checks' => $checks]);
