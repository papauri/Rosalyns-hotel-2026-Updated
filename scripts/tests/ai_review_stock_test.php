<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * The Stock "Decisions" panel (.claude/AI_REVIEW_PANELS_PARKED.md, owner go 2026-11-05): the Stock dashboard prints
 * nothing when the panel is off (AI off / no permission / stock module off: empty string, nothing printed, engine not
 * loaded) and its source differs from before only by one require and one echo inside an existing PHP block; the six
 * stock checks (run-out from till use, menu margins, waste and loss, money tied up, supplier prices, budget) on fixture
 * rows, each figure equal to the stock screen's own SQL; honest framing (spending on stock is never a saving: "Not
 * valued"), tiers, per-card permissions; the panel rendered after one fake wording call (supplier names never leave).
 *
 * Usage (from the repo root): php scripts/tests/ai_review_stock_test.php
 * No network (fake transport). Live DB: every fixture (labelled TESTREVIEW-) is written inside a transaction that is
 * rolled back; settings are overridden inside it only. The period checks run on a fixed past period that holds no
 * real stock rows, so they run on any database whatever its real stock.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0; $skip = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function skip(string $l): void { global $skip; $skip++; echo "SKIP $l\n"; }
function near(float $a, float $b): bool { return abs($a - $b) < BALANCE_TOLERANCE; }

require_once $root . '/config/database.php';
require_once $root . '/config/security.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/ai/ai-core.php';
require_once $root . '/admin/includes/review-panel.php';

$engineFile = realpath($root . '/includes/ai/ai-decisions.php');
$engineLoaded = static fn(): bool => in_array($engineFile, array_map('realpath', get_included_files()), true);
$onSql = ["UPDATE site_settings SET setting_value = '1' WHERE setting_key = 'ai_enabled'", "UPDATE site_settings SET setting_value = '' WHERE setting_key = 'ai_paused_reason'",
    "UPDATE enabled_modules SET is_enabled = 1 WHERE module_key = 'ai_assistant'", "UPDATE enabled_modules SET is_enabled = 1 WHERE module_key = 'stock'"];
$adm = $pdo->query("SELECT id, role, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adm) {
    echo "No active admin account: nothing can be checked.\n";
    exit(1);
}
$adm['id'] = (int)$adm['id'];
$_SESSION = ['admin_user_id' => $adm['id'], 'admin_role' => 'admin', 'admin_username' => (string)$adm['username'], 'admin_full_name' => (string)$adm['full_name']];
$mkUser = static function (PDO $db, string $tag, array $grant): int {
    $db->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 'receptionist', 1)")
        ->execute(['dectest_' . $tag . bin2hex(random_bytes(2)), 'dectest.' . $tag . '@example.com', password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), 'Dectest ' . ucfirst($tag)]);
    $id = (int)$db->lastInsertId();
    $g = $db->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)");
    foreach (array_keys(getAllPermissions()) as $k) {
        $g->execute([$id, $k, in_array($k, $grant, true) ? 1 : 0]);
    }
    return $id;
};
$render = static function (): array {
    ob_start();
    $h = rh_review_panel('stock');
    return [$h, (string)ob_get_clean()];
};

// ════ 1. The Stock dashboard is unchanged when the panel is off ════
$dash = 'admin/stock-dashboard.php';
$req = "require_once __DIR__ . '/includes/review-panel.php'; // the Decisions panel: prints nothing unless switched on and allowed\n";
$echoNew = "<?php if (\$error): showAlert(\$error, 'error'); endif; echo rh_review_panel('stock'); ?>";
$echoOld = "<?php if (\$error): showAlert(\$error, 'error'); endif; ?>";
$norm = static fn(string $s): string => str_replace("\r\n", "\n", $s);
$src = $norm((string)file_get_contents($root . '/' . $dash));
check(substr_count($src, "rh_review_panel('stock')") === 1 && substr_count($src, $echoNew) === 1 && substr_count($src, $req) === 1,
    'stock dashboard: one require and one echo, appended inside an existing PHP block (no new line, no whitespace printed)');
$content = strpos($src, '<div class="content">');
$head = strpos($src, '<div class="page-header">');
$at = strpos($src, $echoNew);
$banner = strpos($src, '<!-- ═══ HEALTH BANNER');
check($content !== false && $head > $content && $at > $head && $banner !== false && $at < $banner, 'stock dashboard: the panel sits inside the page container, after the page title and before the health banner');
$git = static fn(string $args): string => (string)shell_exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));
$intro = trim((string)preg_replace('/\s+/', ' ', $git('log --format=%H -S' . escapeshellarg("rh_review_panel('stock')") . ' -- ' . $dash)));
$intro = $intro !== '' ? (string)substr($intro, strrpos(' ' . $intro, ' ')) : '';
$after = $intro === '' ? $src : $norm($git('show ' . $intro . ':' . $dash));
$before = $norm($git('show ' . ($intro === '' ? 'HEAD' : $intro . '~1') . ':' . $dash));
if ($before === '') {
    skip('stock dashboard source before the panel could not be read from git');
} else {
    $stripped = str_replace([$req, $echoNew], ['', $echoOld], $after);
    check($stripped === $before, 'stock dashboard: with the one require and the one echo taken out, the source is byte-for-byte what it was before the panel', $intro === '' ? 'vs HEAD' : 'vs ' . $intro . '~1');
}

// ════ 2. Gate off: nothing at all ════
$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE site_settings SET setting_value = '0' WHERE setting_key = 'ai_enabled'");
    [$h, $printed] = $render();
    check($h === '' && $printed === '' && !$engineLoaded(), 'AI off: the stock panel is an empty string, nothing printed, the engine not loaded');
    foreach ($onSql as $q) { $pdo->exec($q); }
    if (!rh_ai_enabled($pdo)) {
        skip('AI could not be switched on inside the test transaction: permission / module cases skipped');
    } else {
        $noUse = $mkUser($pdo, 'nouse', ['dashboard', 'stock_dashboard', 'stock_management']);
        $noStock = $mkUser($pdo, 'nostock', ['dashboard', 'ai_assistant_use', 'bookings', 'accounting']);
        foreach ([[$noUse, 'no Assistant use permission'], [$noStock, 'no stock permission']] as [$u, $why]) {
            $_SESSION['admin_user_id'] = $u;
            $_SESSION['admin_role'] = 'receptionist';
            [$h, $printed] = $render();
            check($h === '' && $printed === '' && !$engineLoaded(), "permission off ($why): empty string, nothing printed, engine not loaded");
        }
        $_SESSION['admin_user_id'] = $adm['id'];
        $_SESSION['admin_role'] = 'admin';
        $GLOBALS['__rh_ai_module_override'] = ['stock' => false];
        [$h, $printed] = $render();
        check($h === '' && $printed === '' && !$engineLoaded(), 'stock module off: empty string, nothing printed, engine not loaded');
        unset($GLOBALS['__rh_ai_module_override']);
    }
} finally {
    $pdo->rollBack();
    $_SESSION['admin_user_id'] = $adm['id'];
    $_SESSION['admin_role'] = 'admin';
}

// ════ 3. The stock checks ════
require_once $root . '/includes/ai/ai-decisions.php';
if (!rh_dec_ready($pdo, true)) {
    skip('migration 075 has not run on this database: stock checks skipped');
    echo "\n$pass passed, $fail failed, $skip skipped\n";
    exit($fail > 0 ? 1 : 0);
}
$fr = rh_dec_finders()['stock'];
check(array_keys($fr) === ['stk_runout', 'stk_menu', 'stk_loss', 'stk_tied', 'stk_supplier', 'stk_budget'], 'stock has the six checks: run-out, menu margins, waste and loss, money tied up, supplier prices, budget', implode(',', array_keys($fr)));
$b0 = rh_dec_build($pdo, 'stock', rh_dec_context($pdo, true));
check(count($b0['checks']) === 6 && !array_filter($b0['checks'], static fn($k) => empty($k['ok'])), 'every stock check runs on this database as it is', json_encode(array_column($b0['checks'], 'ok', 'id')));
check(empty($fr['stk_budget']['requires']['perm']) === false && $fr['stk_budget']['requires']['perm'] === ['stock_budget'] && in_array('stock_management', $fr['stk_menu']['requires']['perm'], true)
    && in_array('stock_management', $fr['stk_runout']['requires']['perm'], true), 'each stock card asks for the permission of the screen it is read from (budget: stock_budget; recipes and reorder: stock_management)');
$engSrc = (string)file_get_contents($engineFile);
$s0 = strpos($engSrc, '// ── Finders: stock');
$s1 = strpos($engSrc, '// ── Build, score, select');
$stkSrc = ($s0 !== false && $s1 !== false) ? substr($engSrc, $s0, $s1 - $s0) : '';
preg_match_all('/(INSERT INTO|(?<!KEY )UPDATE|DELETE FROM)\s+`?([a-z_]+)/i', $engSrc, $wm);
check(!array_values(array_filter(array_unique($wm[2]), static fn($t) => strpos($t, 'ai_review_') !== 0)), 'the engine still writes only to the ai_review_* tables');
check($stkSrc !== '' && !preg_match('/\b(MRA|fiscal)\b/i', $stkSrc) && !preg_match('/[^=!<>]==[^=]\s*[0-9.]+|\$\w+\s*==\s*0\.0/', $stkSrc), 'no MRA wording and no float equality in the stock checks');
check($stkSrc !== '' && !preg_match('/rh_ai_mod\(/', $stkSrc), 'the stock checks read the module switch through rh_dec_mod (unreadable = partial, never "off")');
// Module off: every stock check runs and finds nothing
$GLOBALS['__rh_ai_module_override'] = ['stock' => false];
$offCtx = rh_dec_context($pdo, true);
$offOk = true;
foreach ($fr as $id => $def) {
    $r = call_user_func($def['fn'], $pdo, $offCtx);
    $offOk = $offOk && !empty($r['ok']) && $r['cards'] === [];
}
unset($GLOBALS['__rh_ai_module_override']);
check($offOk, 'stock module off: every stock check returns no card (and counts as run)');

// A fixed past period with no real stock rows (the supplier window included), so the figures are only the fixtures
$days = rh_dec_num('ai_review_base_days', 7, 92);
$look = max($days, rh_dec_num('ai_review_supplier_days', 7, 730));
$wTo = '';
foreach (['2001-06-30', '1991-06-30', '1981-06-30'] as $cand) {
    $wf = date('Y-m-d', strtotime($cand . ' -' . ($look + 120) . ' days'));
    $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM stock_adjustments WHERE created_at BETWEEN ? AND ?) + (SELECT COUNT(*) FROM stock_in_log WHERE created_at BETWEEN ? AND ?)
        + (SELECT COUNT(*) FROM stock_wastage WHERE recorded_date BETWEEN ? AND ?) + (SELECT COUNT(*) FROM stock_counts WHERE COALESCE(approved_at, count_date) BETWEEN ? AND ?)
        + (SELECT COUNT(*) FROM stock_orders WHERE COALESCE(paid_at, created_at) BETWEEN ? AND ?) + (SELECT COUNT(*) FROM stock_ingredients WHERE created_at < ? AND is_archived = 0)");
    $st->execute([$wf, $cand . ' 23:59:59', $wf, $cand . ' 23:59:59', $wf, $cand, $wf, $cand . ' 23:59:59', $wf, $cand . ' 23:59:59', $cand]);
    if ((int)$st->fetchColumn() === 0) {
        $wTo = $cand;
        break;
    }
}
// The screens' own SQL, read from their files (so a change there that the checks do not follow fails here)
$screen = static function (string $file, string $re, array $repl = []) use ($root): string {
    $s = (string)file_get_contents($root . '/' . $file);
    if (!preg_match($re, $s, $m)) {
        return '';
    }
    return strtr($m[1], $repl);
};
$vw = rh_voided_wastage_sql('sa');
$usageSql = $screen('admin/stock-reports.php', '/\$tab === \'usage\'\) \{\s*\$st = \$pdo->prepare\("(.*?)"\);/s', ['{$vwSa}' => $vw]);
$mcConcat = "\" . rh_stock_movement_cost_sql('sa', 'i') . \"";
$rateUseSql = $screen('admin/stock-reports.php', '/\$usageCompareStmt = \$pdo->prepare\("(.*?)"\);/s', ['{$vwSa}' => $vw, $mcConcat => rh_stock_movement_cost_sql('sa', 'i')]);
$rateWasteSql = $screen('admin/stock-reports.php', '/\$wastageRateStmt = \$pdo->prepare\("(.*?)"\);/s');
$stockInSql = $screen('admin/stock-reports.php', '/\$tot = \$pdo->prepare\("(SELECT COALESCE\(SUM\(cost_total\), 0\) FROM stock_in_log[^"]*)"\);/s');
$recipeSql = $screen('admin/stock-recipes.php', '/\$menuItems = \$pdo->query\("(.*?)"\)->fetchAll/s', ["\" . rh_stock_yield_sql('sri.yield_percent') . \"" => rh_stock_yield_sql('sri.yield_percent')]);
$onOrderSql = $screen('admin/stock-reorder.php', '/\$rows = \$pdo->query\("(.*?)"\)->fetchAll/s');
check($usageSql !== '' && $rateUseSql !== '' && $rateWasteSql !== '' && $stockInSql !== '' && $recipeSql !== '' && $onOrderSql !== '', 'the stock screens\' own queries were found to compare with (Usage, Wastage rate, Stock In, Recipes, Reorder)');

if ($wTo === '') {
    skip('fixture figures: no empty past period found to run them on');
} else {
$settingsKeep = $GLOBALS['_SITE_SETTINGS'] ?? [];
$GLOBALS['dec_calls'] = [];
putenv('RH_AI_FAKE_PROVIDER=dectest');
rh_ai_set_transport(static function ($method, $url, $headers, $body, $timeout) {
    $GLOBALS['dec_calls'][] = ['body' => (string)$body];
    $find = static function ($v) use (&$find) {
        if (is_string($v) && strpos($v, '"cards"') !== false) {
            $j = json_decode($v, true);
            if (is_array($j) && isset($j['cards'])) { return $j; }
        }
        if (is_array($v)) { foreach ($v as $x) { $r = $find($x); if ($r !== null) { return $r; } } }
        return null;
    };
    $p = $find(json_decode((string)$body, true));
    $out = ['order' => [], 'cards' => []];
    foreach ((array)($p['cards'] ?? []) as $c) {
        $out['order'][] = $c['id'];
        $out['cards'][$c['id']] = ['decision' => $c['decision'], 'why_now' => $c['why_now'], 'recommendation' => $c['recommendation']];
    }
    return ['http' => 200, 'body' => json_encode(rh_ai_fake_body('google', ['text' => json_encode($out), 'usage' => ['in' => 90, 'out' => 60]], 'test-fast-model'))];
});
$GLOBALS['__rh_dec_models'] = ['provider' => 'google', 'fast' => ['model_id' => 'test-fast-model', 'price_in_per_mtok' => null, 'price_out_per_mtok' => null]];
$pdo->beginTransaction();
try {
    foreach ($onSql as $q) { $pdo->exec($q); }
    $set = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach (['ai_cloud_enabled' => '1', 'ai_review_writer_enabled' => '1', 'ai_review_impact_min_pct' => '0.01', 'ai_review_cards_max' => '8'] as $k => $v) { $set->execute([$k, $v]); }
    rh_ai_settings($pdo, true);
    $pdo->exec("DELETE FROM ai_review_cache WHERE cache_day = CURDATE() AND area = 'stock'"); // rolled back below
    $ctx = $GLOBALS['__rh_dec_ctx'] = rh_dec_context_new($pdo, $wTo);
    $from = (string)$ctx['from'];
    $to = (string)$ctx['to'];
    $old = date('Y-m-d', strtotime($from . ' -200 days')) . ' 08:00:00';
    $high = rh_ai_menu_pct('ai_menu_cost_high_pct');
    $horizon = rh_dec_num('ai_review_runout_days', 1, 90);

    // Suppliers and stock items (created long before the period)
    $sup = $pdo->prepare("INSERT INTO stock_suppliers (name, lead_time_days, is_active) VALUES (?, ?, 1)");
    $sup->execute(['TESTREVIEW Supplier Alpha', 5]); $supA = (int)$pdo->lastInsertId();
    $sup->execute(['TESTREVIEW Supplier Beta', 3]); $supB = (int)$pdo->lastInsertId();
    $ing = $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, min_quantity, cost_per_unit, is_archived, created_at, reorder_point, par_level, lead_time_days, preferred_supplier_id)
                          VALUES (?, 'TESTREVIEW', ?, ?, 0, ?, 0, ?, 0, 0, 0, ?)");
    $mkIng = static function (string $name, string $unit, float $qty, float $cost, ?int $supId) use ($ing, $pdo, $old): int {
        $ing->execute(['TESTREVIEW ' . $name, $unit, $qty, $cost, $old, $supId]);
        return (int)$pdo->lastInsertId();
    };
    $flour = $mkIng('Flour', 'kg', 4, 1000, $supA);      // runs out: 30 kg used in 30 days, 4 kg left
    $soda = $mkIng('Soda', 'bottle', 100, 50, $supB);    // moves slowly, plenty left
    $sugar = $mkIng('Sugar', 'kg', 2, 10, $supA);        // would run out, but enough is on order
    $saffron = $mkIng('Saffron', 'g', 2, 5000, null);    // not used in the period: money tied up
    $adj = $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, cost_at_time, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    $adj->execute([$flour, -20, 'TESTREVIEW sale', 'pos_order', 1000, $from . ' 10:00:00']);
    $adj->execute([$flour, -13, 'TESTREVIEW sale', 'pos_order', 1000, $to . ' 10:00:00']);
    $adj->execute([$flour, 3, 'TESTREVIEW void restore', 'void_restore', 1000, $to . ' 11:00:00']);
    $adj->execute([$flour, -5, 'Voided to wastage: TESTREVIEW', 'pos_order', 1000, $to . ' 12:00:00']); // left out of use (the screens' rule)
    $adj->execute([$soda, -1, 'TESTREVIEW sale', 'pos_order', 50, $to . ' 10:00:00']);
    $adj->execute([$sugar, -30, 'TESTREVIEW sale', 'pos_order', 10, $from . ' 10:00:00']);
    $pdo->prepare("INSERT INTO stock_purchase_orders (reference, supplier_id, status, order_date, subtotal, total_cost) VALUES ('TESTREVIEW-PO1', ?, 'sent', ?, 100, 100)")->execute([$supA, $to]);
    $poId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_purchase_order_items (purchase_order_id, ingredient_id, description, unit, ordered_qty, received_qty, unit_cost, line_total) VALUES (?, ?, 'TESTREVIEW Sugar', 'kg', 10, 0, 10, 100)")->execute([$poId, $sugar]);

    // Waste and counts: 300.00 wasted, 400.00 short at one approved count in the period; Flour short at 2 counts (evening)
    $pdo->prepare("INSERT INTO stock_wastage (ingredient_id, quantity, cost_per_unit, wastage_cost, reason, recorded_date) VALUES (?, 6, 50, 300, 'TESTREVIEW spoiled', ?)")->execute([$soda, $to]);
    $cnt = $pdo->prepare("INSERT INTO stock_counts (reference, count_date, shift, scope, status, approved_at, total_variance_cost, shortage_cost, surplus_cost) VALUES (?, ?, 'evening', 'full', 'approved', ?, ?, ?, 0)");
    $line = $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost) VALUES (?, ?, 10, ?, ?, ?, ?)");
    $c1Day = date('Y-m-d', strtotime($to . ' -2 days'));
    $cnt->execute(['TESTREVIEW-C1', $c1Day, $c1Day . ' 21:00:00', -400, -400]); $c1 = (int)$pdo->lastInsertId();
    $line->execute([$c1, $flour, 9.8, -0.2, 1000, -200]);
    $line->execute([$c1, $soda, 6, -4, 50, -200]);
    $c2Day = date('Y-m-d', strtotime($from . ' -31 days'));
    if (date('l', strtotime($c2Day)) === date('l', strtotime($c1Day))) { $c2Day = date('Y-m-d', strtotime($c2Day . ' -1 day')); }
    $cnt->execute(['TESTREVIEW-C2', $c2Day, $c2Day . ' 21:00:00', -100, -100]); $c2 = (int)$pdo->lastInsertId();
    $line->execute([$c2, $flour, 9.9, -0.1, 1000, -100]);

    // Deliveries: Flour 800.00 a kg before the period, 1,000.00 in it (+25%): 6 kg x 200.00 = 1,200.00 above the earlier price
    $sin = $pdo->prepare("INSERT INTO stock_in_log (ingredient_id, quantity, cost_per_unit, cost_total, supplier_name, supplier_id, created_at, received_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $d0 = date('Y-m-d', strtotime($from . ' -20 days'));
    $d1 = date('Y-m-d', strtotime($to . ' -5 days'));
    $sin->execute([$flour, 10, 800, 8000, 'TESTREVIEW Supplier Alpha', $supA, $d0 . ' 09:00:00', $d0]);
    $sin->execute([$flour, 6, 1000, 6000, 'TESTREVIEW Supplier Alpha', $supA, $d1 . ' 09:00:00', $d1]);
    $sin->execute([$soda, 100, 50, 5000, 'TESTREVIEW Supplier Beta', $supB, $d1 . ' 10:00:00', $d1]);

    // A batch expiring soon (from today): 2 bottles of Soda at 50.00 = 100.00
    $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, expiry_date, status) VALUES (?, 'TESTREVIEW-B1', 2, 2, 50, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'active')")->execute([$soda]);

    // Menu: Pie (recipe 0.9 kg Flour = 900.00 on a price of 1,000.00: 90%) and Soda (bought in, 50.00 on a price of 40.00: below cost)
    $cat = $pdo->query("SELECT id, slug FROM menu_categories WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $mi = $pdo->prepare("INSERT INTO menu_items (category_id, item_name, price, is_available) VALUES (?, ?, ?, 1)");
    $mi->execute([(int)$cat['id'], 'TESTREVIEW Pie', 1000]); $pie = (int)$pdo->lastInsertId();
    $mi->execute([(int)$cat['id'], 'TESTREVIEW Soda', 40]); $sodaItem = (int)$pdo->lastInsertId();
    $rcp = $pdo->prepare("INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe) VALUES (?, ?, 1)");
    $rl = $pdo->prepare("INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent) VALUES (?, ?, ?, 100)");
    $rcp->execute([$pie, (string)$cat['slug']]); $rl->execute([(int)$pdo->lastInsertId(), $flour, 0.9]);
    $rcp->execute([$sodaItem, (string)$cat['slug']]); $rl->execute([(int)$pdo->lastInsertId(), $soda, 1]);
    $ord = $pdo->prepare("INSERT INTO stock_orders (reference, status, total_amount, subtotal, paid_at, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    $oi = $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, unit_price, quantity, line_total, kds_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $ord->execute(['TESTREVIEW-O1', 'paid', 10200, 10200, $to . ' 13:00:00', $to . ' 12:30:00']); $o1 = (int)$pdo->lastInsertId();
    $oi->execute([$o1, $pie, (string)$cat['slug'], 'TESTREVIEW Pie', 1000, 10, 10000, 'served']);
    $oi->execute([$o1, $sodaItem, (string)$cat['slug'], 'TESTREVIEW Soda', 40, 5, 200, 'served']);
    $oi->execute([$o1, $pie, (string)$cat['slug'], 'TESTREVIEW Pie', 1000, 3, 3000, 'void']);           // a voided line: not sold
    $ord->execute(['TESTREVIEW-O2', 'voided', 4000, 4000, null, $to . ' 14:00:00']); $o2 = (int)$pdo->lastInsertId();
    $oi->execute([$o2, $pie, (string)$cat['slug'], 'TESTREVIEW Pie', 1000, 4, 4000, 'served']);          // a voided order: not sold

    $b = rh_dec_build($pdo, 'stock', $ctx);
    $cards = [];
    foreach (array_merge($b['main'], $b['more']) as $c) { $cards[$c['key']] = $c; }
    check(!array_filter($b['checks'], static fn($k) => empty($k['ok'])), 'with the fixture rows every stock check still runs', json_encode(array_column($b['checks'], 'ok', 'id')));

    // Run-out forecast from real till use
    $st = $pdo->prepare($usageSql);
    $st->execute([$from, $to]);
    $usageRows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $usageRows[(int)$r['id']] = $r; }
    check(near((float)($usageRows[$flour]['total_used'] ?? -1), 30.0) && count($usageRows) === 3, 'run-out: the Stock Reports Usage tab reads 30 kg of Flour used (void restores taken off, voided-to-wastage lines left out)', json_encode(array_map(static fn($r) => $r['total_used'], $usageRows)));
    $ru = $cards['stk_runout'] ?? null;
    $cover = max($horizon, 5); // Flour has no delivery time of its own; its supplier's is 5 days
    $needCost = round(($cover * 1.0 - 4.0) * 1000, 2);
    $ruRows = (array)($ru['evidence']['rows'] ?? []);
    $flourRow = array_values(array_filter($ruRows, static fn($r) => strpos((string)$r['label'], 'TESTREVIEW Flour') === 0))[0] ?? null;
    check($ru !== null && near((float)$ru['at_stake'], $needCost) && $flourRow !== null && near((float)$flourRow['amount'], $needCost) && strpos((string)$flourRow['label'], 'about 4.0 days') !== false,
        'run-out: Flour at 1 kg a day lasts 4 days; the order to last ' . $cover . ' days costs ' . number_format($needCost, 2) . ' (the at-stake figure)', json_encode([$ru['at_stake'] ?? null, $flourRow]));
    check($ru !== null && count($ruRows) === 1 && (int)$ru['evidence']['total'] === 3, 'run-out: Sugar (enough on order) and Soda (plenty left) are not flagged; 3 stock items with till use checked', json_encode(array_column($ruRows, 'label')));
    $st = $pdo->query($onOrderSql);
    $oo = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $oo[(int)$r['ingredient_id']] = (float)$r['qty']; }
    check(near((float)(rh_dec_on_order($pdo)[$sugar] ?? -1), (float)($oo[$sugar] ?? -2)) && near((float)$oo[$sugar], 10.0), 'run-out: what is on order equals the Reorder screen\'s own query (Sugar: 10 kg)');
    check($ru !== null && !array_filter((array)$ru['options'], static fn($o) => empty($o['not_valued']) || $o['best'] !== null || $o['expected'] !== null || $o['worst'] !== null)
        && stripos((string)$ru['at_stake_note'], 'not a saving') !== false && (int)$ru['tier'] === 2 && $ru['link'][0] === 'stock-reorder.php',
        'run-out: ordering is money spent, never a saving: every option "Not valued"; tier 2 (one approval), the link opens the Reorder screen');

    // Menu margins
    $st = $pdo->query($recipeSql);
    $rec = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $rec[(int)$r['id']] = (float)$r['recipe_cost']; }
    check(near((float)($rec[$pie] ?? -1), 900.0) && near((float)($rec[$sodaItem] ?? -1), 50.0), 'menu: the recipe costs equal the Recipes screen\'s own query (Pie 900.00, Soda 50.00)');
    $mn = $cards['stk_menu'] ?? null;
    $pieShort = 10 * (900 - 1000 * $high / 100);
    $sodaShort = 5 * (50 - 40); // bought in and sold as is: only what it sells below cost (the dish band does not apply)
    $up = rh_ai_menu_view_under_priced(rh_ai_menu_items($pdo));
    check($high < 90 && $mn !== null && near((float)$mn['at_stake'], round($pieShort + $sodaShort, 2)), 'menu: at stake = the Pie margin short of the ' . $high . '% band + what the bought-in Soda sells below cost (50.00), on what the till sold (10 Pie, 5 Soda; the voided line and the voided order left out)', json_encode([$mn['at_stake'] ?? null, $pieShort + $sodaShort]));
    check(in_array('TESTREVIEW Pie', array_column($up['re_price'], 'item'), true) && in_array('TESTREVIEW Soda', array_column($up['bought_in'], 'item'), true) && $mn !== null && strpos((string)$mn['decision'], '1 sells below cost') !== false,
        'menu: the flagged items are the menu advisor\'s own "re-price" (Pie) and "bought in" (Soda, selling below cost) lists');
    check($mn !== null && (int)$mn['tier'] === 3 && !array_filter((array)$mn['options'], static fn($o) => empty($o['not_valued'])) && $mn['link'][0] === 'stock-recipes.php'
        && stripos((string)$mn['at_stake_note'], 'Recipes screen') !== false, 'menu: a price or recipe change is a manager\'s (tier 3, never automatic), every option "Not valued", the link opens Recipes');

    // Waste and loss
    $ls = $cards['stk_loss'] ?? null;
    $st = $pdo->prepare($rateUseSql); $st->execute([$from, $to]); $useCost = (float)$st->fetchColumn();
    $st = $pdo->prepare($rateWasteSql); $st->execute([$from, $to]); $wasteCost = (float)$st->fetchColumn();
    $ratePct = round($wasteCost / $useCost * 100, 1);
    $lsRows = (array)($ls['evidence']['rows'] ?? []);
    $rateRow = array_values(array_filter($lsRows, static fn($r) => strpos((string)$r['label'], 'Wastage as a share') === 0))[0] ?? null;
    check($ls !== null && near((float)$ls['at_stake'], 700.0), 'loss: at stake = 300.00 wasted + 400.00 short at the approved count (the shortage stored below zero is read as a cost above zero)', json_encode($ls['at_stake'] ?? null));
    check($rateRow !== null && (string)$rateRow['value'] === number_format($ratePct, 1) . '%' && near((float)$rateRow['amount'], $useCost) && near($useCost, 30350.0),
        'loss: the waste rate equals the Stock Reports Wastage tab\'s own queries (' . number_format($ratePct, 1) . '% of 30,350.00 used)', json_encode($rateRow));
    $repRow = array_values(array_filter($lsRows, static fn($r) => strpos((string)$r['label'], 'Short at 2 approved counts') === 0))[0] ?? null;
    check($repRow !== null && strpos((string)$repRow['label'], 'TESTREVIEW Flour') !== false && strpos((string)$repRow['label'], '2 on the evening shift') !== false && near((float)$repRow['amount'], 300.0),
        'loss: the same item short at 2 counts is listed with its pattern (2 on the evening shift, 300.00)', json_encode($repRow));
    $cntOpt = array_values(array_filter((array)($ls['options'] ?? []), static fn($o) => stripos((string)$o['label'], 'Count the items') === 0))[0] ?? null;
    $buyOpt = array_values(array_filter((array)($ls['options'] ?? []), static fn($o) => stripos((string)$o['label'], 'Buy smaller') === 0))[0] ?? null;
    check($cntOpt !== null && !empty($cntOpt['not_valued']) && $cntOpt['best'] === null && $buyOpt !== null && near((float)$buyOpt['best'], 300.0) && near((float)$buyOpt['worst'], 0.0),
        'loss: counting more often finds a loss but does not bring stock back ("Not valued"); buying smaller amounts can save at most what was wasted (300.00)');
    check($ls !== null && (int)$ls['recommend'] === 1 && $ls['link'][0] === 'stock-count.php' && stripos((string)$ls['recommendation'], 'Count') === 0, 'loss: more missing than wasted: counting is recommended and the link opens Stock Count');

    // Money tied up
    $td = $cards['stk_tied'] ?? null;
    $exp = rh_ai_menu_expiring($pdo, null);
    $expV = round(array_sum(array_column(array_filter($exp['soon'], static fn($x) => (int)$x['ingredient_id'] !== $saffron), 'value')), 2);
    check($td !== null && near((float)$td['at_stake'], 10000.0 + $expV) && $expV >= 100.0 - BALANCE_TOLERANCE, 'tied up: Saffron not used in the period (2 g x 5,000.00 = 10,000.00) + batches expiring soon (the expiry read, ' . number_format($expV, 2) . ')', json_encode([$td['at_stake'] ?? null, $expV]));
    $tdRows = array_column((array)($td['evidence']['rows'] ?? []), 'label');
    $valRow = array_values(array_filter((array)($td['evidence']['rows'] ?? []), static fn($r) => strpos((string)$r['label'], 'All stock on hand') === 0))[0] ?? null;
    check($td !== null && count(array_filter($tdRows, static fn($l) => strpos($l, 'Not used in') === 0)) === 1 && $valRow !== null && near((float)$valRow['amount'], rh_stock_valuation($pdo)),
        'tied up: only the unused item is listed (moved items are not), and the stock value equals the shared valuation', json_encode($tdRows));
    $useOpt = array_values(array_filter((array)($td['options'] ?? []), static fn($o) => stripos((string)$o['label'], 'Use the batches') === 0))[0] ?? null;
    check($td !== null && !empty($td['options'][0]['not_valued']) && $useOpt !== null && near((float)$useOpt['best'], $expV) && (int)$td['tier'] === 1 && stripos((string)$td['at_stake_note'], 'tied up, not lost') !== false,
        'tied up: stopping reorders is "Not valued" (money already spent); using expiring batches can save at most their value; money tied up is not called a loss');

    // Supplier prices and dependence
    $sp = $cards['stk_supplier'] ?? null;
    $lFrom = date('Y-m-d', strtotime($to . ' -' . ($look - 1) . ' days'));
    $st = $pdo->prepare($stockInSql); $st->execute([$lFrom, $to]); $spend = (float)$st->fetchColumn();
    $spRows = (array)($sp['evidence']['rows'] ?? []);
    $topRow = array_values(array_filter($spRows, static fn($r) => strpos((string)$r['label'], 'Bought from the largest supplier') === 0))[0] ?? null;
    check($sp !== null && near((float)$sp['at_stake'], 1200.0) && strpos((string)($spRows[0]['label'] ?? ''), '(+25.0%)') !== false, 'supplier: Flour up 25% on its earlier price; 6 kg x 200.00 = 1,200.00 paid above it in the period', json_encode([$sp['at_stake'] ?? null, $spRows[0] ?? null]));
    check($topRow !== null && near($spend, 19000.0) && strpos((string)$topRow['value'], number_format(14000 / 19000 * 100, 1) . '% of') !== false, 'supplier: the spend share is worked out from the Stock In report\'s own total (14,000.00 of 19,000.00)', json_encode($topRow));
    check($sp !== null && !array_filter((array)$sp['options'], static fn($o) => empty($o['not_valued'])) && (int)$sp['tier'] === 1 && (int)$sp['options'][1]['tier'] === 3
        && stripos((string)$sp['why_now'] . (string)$sp['decision'] . (string)$sp['recommendation'], 'TESTREVIEW Supplier') === false,
        'supplier: asking or switching is "Not valued" (switching is tier 3); supplier names stay in the evidence, never in the worded text');

    // Budget: holding orders is paid later, never a saving (the Stock Budget screen's figures, set here)
    $bctx = $ctx;
    $bctx['run'] = static fn(string $tool, array $args = []) => $tool === 'money_leaks'
        ? ['stock_budget_this_month' => ['budget' => 10000.0, 'used' => 8000.0, 'left' => 2000.0, 'pct_used' => 80.0, 'month_gone_pct' => 50.0, 'status' => 'at_risk', 'forecast' => 16000.0]] : null;
    $bd = rh_dec_f_stock_budget($pdo, $bctx)['cards'][0] ?? null;
    check($bd !== null && near((float)$bd['at_stake'], 3000.0) && !array_filter((array)$bd['options'], static fn($o) => empty($o['not_valued']) || $o['best'] !== null || $o['expected'] !== null || $o['worst'] !== null),
        'budget: 3,000.00 ahead of an even pace; neither holding nor buying claims money saved: both "Not valued"', json_encode($bd['options'] ?? null));
    check($bd !== null && stripos((string)$bd['options'][0]['effect'], 'paid later, not saved') !== false && stripos((string)$bd['at_stake_note'], 'not a saving') !== false
        && in_array('Likely spend for the month if buying goes on like this (the Stock Budget screen)', array_column((array)$bd['evidence']['rows'], 'label'), true), 'budget: holding orders is shown as paid later, not saved; the screen\'s month-end forecast is in the evidence');
    $bctx['run'] = static fn(string $tool, array $args = []) => $tool === 'money_leaks' ? ['wastage' => []] : null;
    check(rh_dec_f_stock_budget($pdo, $bctx)['cards'] === [], 'budget: no budget set: no card (nothing to compare)');

    // Every stock card: shape, plain wording, never surer than its figures, nothing claimed as saved by buying or holding
    foreach ($cards as $k => $cd) {
        check($cd['decision'] !== '' && count($cd['options']) >= 1 && in_array((int)$cd['tier'], [1, 2, 3], true) && $cd['evidence']['total'] > 0 && $cd['at_stake_note'] !== '',
            "card $k: decision, options, tier, a checked total and a note on what the money figure uses");
        // Owner 2026-11-05, very plain English: the fixed texts fit the wording caps (an unchanged card is never dropped) and use no trade words, no minus sign on money and no "(s)"
        $plain = $cd['decision'] . ' | ' . $cd['why_now'] . ' | ' . $cd['recommendation'] . ' | ' . $cd['at_stake_note'] . ' | ' . implode(' | ', array_column($cd['options'], 'label')) . ' | ' . implode(' | ', array_column($cd['options'], 'effect'));
        $fits = !array_filter(rh_dec_caps(), static fn($cap, $f) => mb_strlen((string)$cd[$f]) > $cap, ARRAY_FILTER_USE_BOTH);
        check($fits && !preg_match('/\b(folio|pace|pickup|variance|accrual|ADR|RevPAR|lead time|orphan|runway|exposure|dependency|dependence|tier|room-nights?|Evidence|Not valued)\b/i', $plain) && !preg_match('/[A-Z]{3} -\d|\(s\)|\(es\)/', $plain),
            "card $k: very plain wording (fits the wording caps; no trade words, minus money or (s))", $plain);
        check(!preg_match('/\b(AI|smart|insights?|assistant|generated|powered|model|MRA)\b/i', $cd['decision'] . ' ' . $cd['why_now'] . ' ' . $cd['recommendation'] . ' ' . implode(' ', array_column($cd['options'], 'label'))), "card $k: plain wording");
        check(!array_filter($cd['options'], static fn($o) => (float)$o['confidence'] > (float)$cd['confidence'] + 0.0001), "card $k: no option's likelihood is above the card's confidence");
        check(!array_filter($cd['options'], static fn($o) => preg_match('/\b(order|buy|hold)/i', (string)$o['label']) && !preg_match('/smaller amounts/i', (string)$o['label']) && empty($o['not_valued'])),
            "card $k: no option to order, buy or hold stock is valued as money saved");
    }
    check(rh_dec_proof_log($pdo, $cards['stk_menu'] ?? [], 'ran', $adm['id']) === 0, 'a tier 3 card (menu prices) can never be logged as run by itself');

    // A person with only the Stock dashboard sees none of the cards that need another stock screen's permission
    $dashOnly = $mkUser($pdo, 'dashonly', ['dashboard', 'stock_dashboard', 'ai_assistant_use']);
    $du = ['id' => $dashOnly, 'role' => 'receptionist'];
    check(!rh_dec_requires_ok($du, $fr['stk_budget']['requires']) && !rh_dec_requires_ok($du, $fr['stk_menu']['requires']) && !rh_dec_requires_ok($du, $fr['stk_runout']['requires']) && rh_dec_requires_ok($adm, $fr['stk_budget']['requires']),
        'permission per card: Stock dashboard only = no budget, menu or reorder card; an admin sees them');

    // The panel after one fake wording call
    $GLOBALS['dec_calls'] = [];
    [$hp, $pp] = $render();
    check($pp === '' && strpos($hp, 'data-dec-pending') !== false && trim(strip_tags($hp)) === '' && !$GLOBALS['dec_calls'], 'first page load: only a hidden, empty hook (no model call while the page loads)');
    $r = rh_dec_refresh($pdo, 'stock', true, $adm['id'], false);
    $body = (string)($GLOBALS['dec_calls'][0]['body'] ?? '');
    check($r['status'] === 'built' && $r['model_called'] && count($GLOBALS['dec_calls']) === 1, 'the first ask words the stock cards with one call');
    check($body !== '' && strpos($body, 'TESTREVIEW Supplier') === false && strpos($body, 'TESTREVIEW-C') === false && strpos($body, 'TESTREVIEW-B1') === false && strpos($body, 'TESTREVIEW-O') === false,
        'nothing that names a supplier or a record leaves: no supplier name, count, batch or order reference in the call');
    [$html, $printed] = $render();
    if ((string)getenv('RH_DEC_DUMP') !== '') {
        file_put_contents((string)getenv('RH_DEC_DUMP'), $html); // for the headless look check only (fixture figures)
    }
    check($html !== '' && $printed === '' && strpos($html, '>Decisions</h2>') !== false && strpos($html, 'rh-dec-tab') !== false, 'a good review: the stock panel is returned (printed only by the dashboard\'s echo), closed behind its tab');
    foreach (['stk_runout', 'stk_menu', 'stk_loss', 'stk_tied', 'stk_supplier'] as $k) {
        check(strpos($html, 'data-key="' . $k . '"') !== false, "panel shows card $k");
    }
    $ths = substr_count($html, '<th scope="col"');
    check($ths > 0 && $ths === preg_match_all('/<th scope="col" class="no-sort[^"]*" title="[^"]+">[^<]+<\/th>/', $html) && substr_count($html, '<table') === substr_count($html, 'class="rh-dec__legend"'),
        'every column header has a plain hover title, and every table one short key line under it');
    check(substr_count($html, 'class="rh-dec-card"') === substr_count($html, 'class="rh-dec-card__stake"') && (strpos($html, '>money involved<') !== false || strpos($html, '>No money figure<') !== false),
        'every card shows the money involved (or "No money figure") beside the decision');
    check(strpos($html, 'data-dec="refresh"') === false && strpos($html, 'Works the figures out') === false && strpos($html, 'Updated at ') !== false, 'no Refresh button or its help text; one "Updated at" line instead');
    check(strpos($html, '>No money figure<') !== false && strpos($html, '>How sure<') !== false, 'the panel shows "No money figure" where a choice is not money, and How sure for choices');
    check(!preg_match('/\b(AI|smart|insights?|assistant|generated|powered by|thinking)\b/i', strip_tags($html)) && !preg_match('/fa-(magic|wand|robot|brain|star|microchip|hat-wizard)|sparkle|gradient|\bMRA\b/i', $html), 'the panel has no AI-looking words or icons and no MRA wording');
    check(strpos($html, 'TESTREVIEW Supplier Alpha') !== false, 'supplier names are shown on the hotel\'s own screen (they never left it)');
    $GLOBALS['__rh_dec_models'] = ['provider' => ''];
    [$h2, $p2] = $render();
    check($h2 === '' && $p2 === '', 'no model set up: the stock panel disappears (empty string, no templated cards, no hook)');
} finally {
    $pdo->rollBack();
    $GLOBALS['_SITE_SETTINGS'] = $settingsKeep;
    rh_ai_settings($pdo, true);
    unset($GLOBALS['__rh_dec_models'], $GLOBALS['__rh_dec_ctx']);
    rh_ai_set_transport(null);
    putenv('RH_AI_FAKE_PROVIDER');
}
}
$cnt = static fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
check($cnt("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'TESTREVIEW %'") === 0 && $cnt("SELECT COUNT(*) FROM stock_suppliers WHERE name LIKE 'TESTREVIEW %'") === 0
    && $cnt("SELECT COUNT(*) FROM stock_adjustments WHERE reason LIKE '%TESTREVIEW%'") === 0 && $cnt("SELECT COUNT(*) FROM stock_counts WHERE reference LIKE 'TESTREVIEW-%'") === 0
    && $cnt("SELECT COUNT(*) FROM stock_orders WHERE reference LIKE 'TESTREVIEW-%'") === 0 && $cnt("SELECT COUNT(*) FROM menu_items WHERE item_name LIKE 'TESTREVIEW %'") === 0
    && $cnt("SELECT COUNT(*) FROM stock_purchase_orders WHERE reference LIKE 'TESTREVIEW-%'") === 0 && $cnt("SELECT COUNT(*) FROM stock_batches WHERE batch_number LIKE 'TESTREVIEW-%'") === 0
    && $cnt("SELECT COUNT(*) FROM stock_wastage WHERE reason LIKE 'TESTREVIEW%'") === 0 && $cnt("SELECT COUNT(*) FROM admin_users WHERE username LIKE 'dectest\\_%'") === 0
    && $cnt("SELECT COUNT(*) FROM ai_usage_daily WHERE model_id = 'test-fast-model'") === 0, 'everything the checks wrote was rolled back');

echo "\n$pass passed, $fail failed, $skip skipped\n";
exit($fail > 0 ? 1 : 0);
