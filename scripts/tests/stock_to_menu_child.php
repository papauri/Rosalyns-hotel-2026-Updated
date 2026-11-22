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
    $B = $rowsOf(stm_build($pdo, [['ing_id' => $gin], ['ing_id' => $vodka], ['ing_id' => $lager], ['ing_id' => $cola], ['ing_id' => $sugar], ['ing_id' => $rum, 'sold' => 'm:shot']], true));
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
    $P = $rowsOf(stm_build($pdo, [['ing_id' => $gin, 'sold' => 'm:shot', 'price' => '100', 'tick' => 1], ['ing_id' => $lager, 'price' => 'abc', 'tick' => 1]], true));
    $ck('build: cost over price warns and stays ready (shot cost 500 > price 100)', $P[$gin]['below'] && $P[$gin]['ready'] && $P[$gin]['warn'] !== '' && abs((float)$P[$gin]['cost'] - 500) < 0.005, json_encode($P[$gin]));
    $ck('build: a price that is not a number is refused', !$P[$lager]['ready'] && stripos($P[$lager]['why_not'], 'price must be a number') !== false, $P[$lager]['why_not']);

    // ---- price commas: only between thousands ("1,50" is never 150)
    $ck('price: "1,500" = 1500, "12,500.50" = 12500.50, "1,234,567.5" and "1500" read too', stm_parse_price('1,500') === 1500.0 && stm_parse_price('12,500.50') === 12500.5
        && stm_parse_price('1,234,567.5') === 1234567.5 && stm_parse_price('1500') === 1500.0 && stm_parse_price('1.50') === 1.5,
        json_encode([stm_parse_price('1,500'), stm_parse_price('12,500.50'), stm_parse_price('1,234,567.5')]));
    $C = $rowsOf(stm_build($pdo, [['ing_id' => $gin, 'price' => '1,50', 'tick' => 1], ['ing_id' => $lager, 'price' => '15,0', 'tick' => 1], ['ing_id' => $vodka, 'price' => '1,5000', 'tick' => 1]], true));
    $commaOk = true;
    foreach ([$gin, $lager, $vodka] as $cid) {
        $commaOk = $commaOk && $C[$cid]['ready'] === false && $C[$cid]['price'] === null && $C[$cid]['why_not'] === STM_PRICE_COMMA_MSG;
    }
    $ck('price: "1,50", "15,0" and "1,5000" are refused with "Use a dot for decimals, e.g. 1.50, and commas only between thousands, e.g. 1,500."', $commaOk
        && stm_parse_price('1,50') === false && stm_parse_price('15,0') === false && stm_parse_price('1,5000') === false
        && stm_parse_price('0,500') === false && stm_price_comma_wrong('0,500') && stm_parse_price('100,000') === 100000.0,
        json_encode([$C[$gin]['why_not'], $C[$lager]['why_not'], $C[$vodka]['why_not']]));
    $K = $rowsOf(stm_build($pdo, [['ing_id' => $gin, 'price' => '12,500.50', 'tick' => 1]], true));
    $ck('price: "12,500.50" previews as 12500.50, ready', $K[$gin]['ready'] && abs((float)$K[$gin]['price'] - 12500.5) < 0.001, json_encode($K[$gin]['price']));

    // ---- the ids from Stock Items: one field, every id reaches the screen; over the cap is said, never cut
    $many = [];
    for ($i = 1; $i <= 1200; $i++) { $many[] = $i; }
    $pi = stm_parse_ids(implode(',', $many) . ',5,abc,-3,0,' . $gin);
    $ck('ids: 1200 ids in one field all come through (duplicates and junk dropped)', !$pi['over'] && count($pi['ids']) === $pi['count'] && $pi['count'] === ($gin > 1200 ? 1201 : 1200) && $pi['ids'][1199] === 1200, (string)$pi['count']);
    $big = [];
    for ($i = 1; $i <= STM_MAX_ITEMS + 1; $i++) { $big[] = $i; }
    $po = stm_parse_ids(implode(',', $big));
    $ck('ids: more than ' . STM_MAX_ITEMS . ' is flagged as over (the page says so), never silently cut', $po['over'] && $po['ids'] === [] && $po['count'] === STM_MAX_ITEMS + 1);

    // ---- costs only with the Stock permission
    $H = $rowsOf(stm_build($pdo, [['ing_id' => $gin, 'sold' => 'm:shot', 'price' => '100', 'tick' => 1]], false));
    $ck('cost: without the Stock permission no cost, profit or cost warning is sent; the row is still ready', $H[$gin]['cost'] === null && $H[$gin]['profit'] === null
        && $H[$gin]['below'] === false && $H[$gin]['warn'] === '' && $H[$gin]['cost_note'] === '' && $H[$gin]['ready'] === true, json_encode($H[$gin]));
    // ---- "Show profit without VAT" (owner 2026-11-22): follows the hotel's VAT setting (set in memory only here, never saved, then put back)
    $vatItem = $mk('Vat Item', 'bottle', 2521);
    $vatWas = [];
    foreach (['vat_enabled', 'vat_rate', 'vat_pricing_mode'] as $vk) {
        $vatWas[$vk] = array_key_exists($vk, $GLOBALS['_SITE_SETTINGS'] ?? []) ? $GLOBALS['_SITE_SETTINGS'][$vk] : null;
    }
    $vatSet = function (string $on, string $rate, string $mode) {
        $GLOBALS['_SITE_SETTINGS']['vat_enabled'] = $on;
        $GLOBALS['_SITE_SETTINGS']['vat_rate'] = $rate;
        $GLOBALS['_SITE_SETTINGS']['vat_pricing_mode'] = $mode;
    };
    $vIn = [['ing_id' => $vatItem, 'price' => '6,000', 'tick' => 1]];
    try {
        $vatSet('1', '17.5', 'inclusive');
        $Vi = $rowsOf(stm_build($pdo, $vIn, true))[$vatItem];
        $ck('VAT inclusive 17.5%: price 6,000, cost 2,521: profit 3,479.00 (VAT included), without VAT 2,585.38 (6000 / 1.175 = 5106.38 - 2521); the page sends {mode, rate}',
            abs((float)$Vi['profit'] - 3479.0) < 0.000001 && abs((float)($Vi['profit_ex_vat'] ?? 0) - 2585.38) < 0.000001 && $Vi['below'] === false
            && (import_menu_vat_view()['mode'] ?? '') === 'inclusive' && abs((float)(import_menu_vat_view()['rate'] ?? 0) - 17.5) < 0.000001, json_encode($Vi));
        $Vn = $rowsOf(stm_build($pdo, $vIn, false))[$vatItem];
        $ck('VAT: without the Stock permission no profit without VAT is sent (as cost and profit)', !array_key_exists('profit_ex_vat', $Vn) && $Vn['profit'] === null, json_encode($Vn));
        $vatSet('1', '17.5', 'exclusive');
        $Ve = $rowsOf(stm_build($pdo, $vIn, true))[$vatItem];
        $ck('VAT exclusive: the menu price holds no VAT, so profit without VAT = the profit (3,479.00)', array_key_exists('profit_ex_vat', $Ve) && $Ve['profit_ex_vat'] === $Ve['profit']
            && abs((float)$Ve['profit'] - 3479.0) < 0.000001 && (import_menu_vat_view()['mode'] ?? '') === 'exclusive', json_encode($Ve));
        $vatSet('0', '17.5', 'inclusive');
        $Vo = $rowsOf(stm_build($pdo, $vIn, true))[$vatItem];
        $ck('VAT off: no profit without VAT and no tick box (vat null); the profit is unchanged', !array_key_exists('profit_ex_vat', $Vo) && import_menu_vat_view() === null
            && abs((float)$Vo['profit'] - 3479.0) < 0.000001, json_encode($Vo));
    } finally {
        foreach ($vatWas as $vk => $vv) {
            if ($vv === null) { unset($GLOBALS['_SITE_SETTINGS'][$vk]); } else { $GLOBALS['_SITE_SETTINGS'][$vk] = $vv; }
        }
    }
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

    // ---- a Menu-only user (no Stock permission) and a Menu + Stock user (both made up, rolled back)
    $mkUser = function (string $tag, array $perms) use ($pdo, $n) {
        $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'receptionist', 1)")
            ->execute([substr($n($tag), 0, 90), strtolower(preg_replace('/\W+/', '', $n($tag))) . '@example.invalid', $n($tag)]);
        $u = (int)$pdo->lastInsertId();
        foreach ($perms as $k => $g) {
            $pdo->prepare('INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)')->execute([$u, $k, $g]);
        }
        return $u;
    };
    $menuOnly = $mkUser('menuonly', ['menu' => 1, 'stock_management' => 0]);
    $menuStock = $mkUser('menustock', ['menu' => 1, 'stock_management' => 1]);
    $ck('users: Menu-only may change the menu but not see costs; Menu + Stock sees them', stm_can_menu($menuOnly) && !stm_can_see_cost($menuOnly) && stm_can_menu($menuStock) && stm_can_see_cost($menuStock),
        json_encode([stm_can_menu($menuOnly), stm_can_see_cost($menuOnly), stm_can_menu($menuStock), stm_can_see_cost($menuStock)]));
    $s4 = stm_save($pdo, $menuOnly, [['ing_id' => $same2, 'name' => $n('Menu Only Drink'), 'sold' => 'unit', 'price' => '1,250', 'category' => 'Bar', 'tick' => 1]]);
    $mo = $dm($n('Menu Only Drink'));
    $ck('save: a Menu-only user can still save (price 1,250 -> 1250.00), no cost in what comes back', $s4['ok'] && count($s4['added']) === 1 && $mo && (string)$mo['price'] === '1250.00'
        && strpos(json_encode($s4), 'cost') === false, json_encode($s4));

    // ---- the writer's missing-stock-item wording: no ending by default (the import's commit starts it with "Nothing was imported: ");
    // Put on the menu adds its own "Nothing was saved."
    $e1 = $e2 = '';
    try { import_drinks_menu_write($pdo, ['action' => 'add', 'name' => $n('Gone A'), 'price' => 10, 'category' => $n('Err'), 'measure' => '1 bottle', 'link' => null], 0, $admin, false, 'test'); } catch (RuntimeException $e) { $e1 = $e->getMessage(); }
    try { import_drinks_menu_write($pdo, ['action' => 'add', 'name' => $n('Gone B'), 'price' => 10, 'category' => $n('Err'), 'measure' => '1 bottle', 'link' => null], 0, $admin, false, 'test', ''); } catch (RuntimeException $e) { $e2 = $e->getMessage(); }
    $ck('writer: no ending by default (the import prefixes "Nothing was imported: " once); Put on the menu passes no ending (its save adds "Nothing was saved." once)', substr($e1, -18) === 'could not be made.' && strpos($e1, 'Nothing') === false
        && substr($e2, -18) === 'could not be made.' && strpos($e2, 'Nothing') === false, json_encode([$e1, $e2]));
} catch (Throwable $e) {
    $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
echo json_encode(['checks' => $checks]);
