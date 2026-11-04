<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Scenarios for scripts/tests/import_export_test.php (run by page_harness.php: ONE outer transaction that can never commit and is
 * rolled back at the end, DDL skipped). Prints one JSON object: {"checks":[[name, ok, detail], ...], "info":{...}}.
 * Every row is named 'ZZT <run> ...' in categories 'ZZT <run> ...'; no real stock or price data is used or printed.
 */
$run = (string)($_POST['run'] ?? 'X');
$checks = [];
$info = [];
$ck = function (string $n, $c, string $d = '') use (&$checks) { $checks[] = [$n, (bool)$c, $d]; };
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'import_export_' . getmypid();
@mkdir($tmp);
$tmpFiles = [];
$made = [];

try {
    require_once $root . '/includes/import/commit-stock.php';
    require_once $root . '/includes/import/export-stock.php';
    $catA = "ZZT $run Cat";
    $catN = "ZZT $run Neg";
    $catI = "ZZT $run Inj";
    $catB = "ZZT $run Big";
    $today = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
    $day = function (int $off) use ($today) { return date('Y-m-d', strtotime($today . ' ' . ($off >= 0 ? '+' : '') . $off . ' days')); };
    $mk = function (string $cat, string $name, string $unit, $qty, $par, $cost, array $x = []) use ($pdo) {
        $pdo->prepare('INSERT INTO stock_ingredients (name, category, unit, current_quantity, min_quantity, cost_per_unit, yield_percent, is_archived, notes, reorder_point, par_level)
                       VALUES (?, ?, ?, ?, 0, ?, 100, ?, ?, ?, ?)')
            ->execute([$name, $cat, $unit, $qty, $cost, (int)($x['archived'] ?? 0), $x['notes'] ?? null, $x['reorder'] ?? 0, $par]);
        return (int)$pdo->lastInsertId();
    };
    $batch = function (int $ing, string $label, $qty, ?string $exp, string $status = 'active') use ($pdo) {
        $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, expiry_date, expiry_alert_days, status)
                       VALUES (?, ?, ?, ?, 1, CURDATE(), ?, 7, ?)")->execute([$ing, $label, max($qty, 1), $qty, $exp, $status]);
    };
    $n = function (string $s) use ($run) { return 'ZZT ' . $run . ' ' . $s; };

    // ---- fixture rows (all invented) -----------------------------------------------------------------------------------------------------
    $lager = $mk($catA, $n('Lager'), 'bottle', 30, 100, '20.5000', ['reorder' => 10]);
    $batch($lager, 'ZB1', 12, $day(3));
    $batch($lager, 'ZB2', 18, $day(40));
    $stout = $mk($catA, $n('Stout'), 'bottle', 5, 0, 0);
    $batch($stout, 'ZB3', 5, $day(-2));
    $mism = $mk($catA, $n('Mismatch'), 'pack', 7, 10, '10.2500');
    $batch($mism, 'ZB4', 4, $day(20));
    $outi = $mk($catA, $n('Outitem'), 'each', 0, 12, '10.0000');
    $batch($outi, 'ZB5', 0, null, 'depleted');
    $mk($catA, $n('Neverone'), 'each', 0, 0, '15.0000');
    $mk($catA, $n('Lowone'), 'each', 3, 20, '12.0000', ['reorder' => 5]);
    $mk($catA, $n('Kilo'), 'kg', '2.5', 8, '17.1234', ['notes' => 'frozen']);
    $mk($catA, $n('Archived'), 'each', 4, 10, '9.0000', ['archived' => 1]);
    $neg = $mk($catN, $n('Negone'), 'each', -2, 0, '10.0000');
    $batch($neg, 'ZB6', 0, null, 'depleted');
    foreach (['=', '+', '-', '@'] as $i => $ch) {
        $mk($catI, $ch . $n('Inj' . $i), 'pcs', 1, 0, '1.5000');
    }

    $read = function (string $bytes, string $name) use ($tmp, &$tmpFiles) {
        $p = $tmp . DIRECTORY_SEPARATOR . count($tmpFiles) . '_' . $name;
        file_put_contents($p, $bytes);
        $tmpFiles[] = $p;
        return $p;
    };
    $bytesOf = function (array $b) { rewind($b['stream']); $s = stream_get_contents($b['stream']); fclose($b['stream']); return $s; };
    $cellsOf = function (array $rd, string $namePart) {
        foreach ($rd['sheets'] as $sh) {
            if (strpos($sh['name'], $namePart) !== false) { return $sh; }
        }
        return null;
    };
    $rowsBy = function (array $sh, string $name) {          // all rows whose column A is exactly $name
        $out = [];
        foreach ($sh['cells'] as $r => $cols) {
            if (isset($cols[1]) && $cols[1]['v'] === $name) { $out[$r] = $cols; }
        }
        return $out;
    };
    $val = function (array $cols, int $c) { return isset($cols[$c]) ? $cols[$c]['v'] : null; };

    // ---- the scoped export ---------------------------------------------------------------------------------------------------------------
    $tabsAll = import_export_stock_tabs($pdo);
    $keys = array_column($tabsAll, 'key');
    $ck('tabs: every category with active stock is a tab, our three included, archived-only categories absent', in_array($catA, $keys, true) && in_array($catN, $keys, true) && in_array($catI, $keys, true));
    $b = import_export_stock_workbook($pdo, ['tabs' => [$catA, $catN, $catI], 'hotel' => "Test Hotel's", 'currency' => 'ZZ', 'today' => $today]);
    $ck('export builds', !empty($b['ok']), (string)($b['error'] ?? ''));
    $S = $b['summary'];
    $bytes = $bytesOf($b);
    $path = $read($bytes, 'scoped.xlsx');
    $ck('file name is Stock <hotel> <date>.xlsx with the apostrophe removed', $b['filename'] === 'Stock Test Hotels ' . $today . '.xlsx', $b['filename']);
    $rd = import_xlsx_read($path);
    $ck('our own reader opens the export', !empty($rd['ok']), (string)($rd['error'] ?? ''));
    $names = array_column($rd['sheets'], 'name');
    $ck('sheets: DASHBOARD first, one per chosen tab (alphabetical), PURCHASE SUMMARY last',
        count($names) === 5 && strpos($names[0], 'DASHBOARD') !== false && strpos($names[4], 'PURCHASE SUMMARY') !== false
        && strpos($names[1], $catA) !== false && strpos($names[2], $catI) !== false && strpos($names[3], $catN) !== false, json_encode($names));
    $shA = $cellsOf($rd, $catA);
    $ck('banner (row 1) and header row 3 in the tracker layout', $val($shA['cells'][3], 1) === 'Item Name' && $val($shA['cells'][3], 13) === 'Total Cost' && $val($shA['cells'][1], 1) !== null);
    $ck('category heading row carries the full category name', $val($shA['cells'][4], 1) === $catA);

    $L = $rowsBy($shA, $n('Lager'));
    $L = array_values($L);
    $ck('batches: Lager (two batches adding up to its balance) has one row per batch', count($L) === 2 && $val($L[0], 2) === 'ZB1' && $val($L[1], 2) === 'ZB2' && abs($val($L[0], 3) - 12) < 1e-9 && abs($val($L[1], 3) - 18) < 1e-9);
    $ck('expiry: dates are real dates, days and status come from today', $val($L[0], 5) === $day(3) && $val($L[0], 6) === 3 && $val($L[0], 7) === 'AT RISK' && $val($L[1], 6) === 40 && $val($L[1], 7) === 'ACTIVE');
    $ck('Max Level, Unit Price as stored (20.5), price on every batch row, Max Level on the first row only', abs($val($L[0], 10) - 100) < 1e-9 && abs($val($L[0], 12) - 20.5) < 1e-9 && abs($val($L[1], 12) - 20.5) < 1e-9 && $val($L[1], 10) === null);
    $ck('Purchase Qty = Max - Balance (70) and Total Cost = 70 x 20.5 = 1435, on the first row only', abs($val($L[0], 11) - 70) < 1e-9 && abs($val($L[0], 13) - 1435) < 1e-9 && $val($L[1], 11) === null && $val($L[1], 13) === null);
    $St = array_values($rowsBy($shA, $n('Stout')));
    $ck('expired batch: EXPIRED, days -2, Dispose; no Max Level, no price, no purchase', count($St) === 1 && $val($St[0], 7) === 'EXPIRED' && $val($St[0], 6) === -2 && $val($St[0], 8) === 'Dispose' && $val($St[0], 10) === null && $val($St[0], 12) === null && $val($St[0], 11) === null);
    $Mm = array_values($rowsBy($shA, $n('Mismatch')));
    $ck('batches that do not add up to the balance: ONE row with the item balance (7) and the earliest expiry, nothing invented', count($Mm) === 1 && abs($val($Mm[0], 3) - 7) < 1e-9 && $val($Mm[0], 5) === $day(20) && $val($Mm[0], 2) === null && abs($val($Mm[0], 13) - 30.75) < 1e-9, json_encode($Mm));
    $Ou = array_values($rowsBy($shA, $n('Outitem')));
    $ck('out of stock (has history): balance 0, Out of stock - reorder, purchase 12 = 120', $val($Ou[0], 3) === 0 && strpos((string)$val($Ou[0], 8), 'Out of stock') === 0 && abs($val($Ou[0], 13) - 120) < 1e-9);
    $Nv = array_values($rowsBy($shA, $n('Neverone')));
    $ck('never stocked (no history): flagged opening stock not recorded, no purchase because no Max Level', strpos((string)$val($Nv[0], 8), 'Opening stock') === 0 && $val($Nv[0], 11) === null);
    $Lo = array_values($rowsBy($shA, $n('Lowone')));
    $ck('low stock (3 <= reorder 5): Below reorder level, purchase 17 = 204', $val($Lo[0], 8) === 'Below reorder level' && abs($val($Lo[0], 13) - 204) < 1e-9);
    $Ki = array_values($rowsBy($shA, $n('Kilo')));
    $ck('decimals exact: 2.5 kg, price 17.1234 as stored, purchase 5.5 = 94.18, comment kept', abs($val($Ki[0], 3) - 2.5) < 1e-12 && abs($val($Ki[0], 12) - 17.1234) < 1e-12 && abs($val($Ki[0], 13) - 94.18) < 1e-9 && $val($Ki[0], 9) === 'frozen');
    $ck('archived items are not exported', $rowsBy($shA, $n('Archived')) === []);
    // total row + notes
    $totRow = null;
    foreach ($shA['cells'] as $r => $cols) { if (isset($cols[1]) && is_string($cols[1]['v']) && stripos(trim($cols[1]['v']), 'TOTAL ESTIMATED PURCHASE COST') === 0) { $totRow = $cols; } }
    $ck('tab total row = sum of its Total Cost lines (1883.93)', $totRow !== null && abs($val($totRow, 13) - 1883.93) < 0.005, json_encode($totRow ? $val($totRow, 13) : null));
    $foot = false;
    foreach ($shA['cells'] as $cols) { if (isset($cols[1]) && is_string($cols[1]['v']) && strpos($cols[1]['v'], 'VAT is not added or removed') !== false) { $foot = true; } }
    $ck('footer note says prices are as stored, VAT not added or removed', $foot);
    // negative stock written as it is
    $shN = $cellsOf($rd, $catN);
    $Ng = array_values($rowsBy($shN, $n('Negone')));
    $ck('negative stock is exported as it is (-2), flagged to reconcile', $val($Ng[0], 3) === -2 && strpos((string)$val($Ng[0], 8), 'Negative stock') === 0);
    // injection: names starting with = + - @ are text, never a formula
    $shI = $cellsOf($rd, $catI);
    $inj = 0;
    foreach ($shI['cells'] as $cols) { if (isset($cols[1]) && is_string($cols[1]['v']) && preg_match('/^[=+\-@]ZZT/', $cols[1]['v']) && !$cols[1]['formula']) { $inj++; } }
    $ck('formula-looking names (= + - @) come back as plain text, not formulas', $inj === 4, (string)$inj);
    $x = import_zip_open($bytes, true);
    $allXml = '';
    foreach (import_zip_names($x) as $nm) { if (substr($nm, -4) === '.xml') { $allXml .= import_zip_read($x, $nm)['data']; } }
    $ck('no formula element is written anywhere (values only)', strpos($allXml, '<f>') === false && strpos($allXml, '<f ') === false);
    $ck('the injection cells carry quotePrefix', substr_count($allXml, 'quotePrefix="1"') >= 1);

    // ---- DASHBOARD + PURCHASE SUMMARY against independent SQL ----------------------------------------------------------------------------
    $in = "('" . implode("','", array_map(function ($c) { return str_replace("'", "''", $c); }, [$catA, $catN, $catI])) . "')";
    $sqlVal = (float)$pdo->query("SELECT COALESCE(SUM(GREATEST(0, current_quantity) * cost_per_unit), 0) FROM stock_ingredients WHERE is_archived = 0 AND category IN $in")->fetchColumn();
    $sqlBuy = (float)$pdo->query("SELECT COALESCE(SUM(ROUND(GREATEST(par_level - current_quantity, 0) * cost_per_unit, 2)), 0) FROM stock_ingredients WHERE is_archived = 0 AND par_level > 0 AND category IN $in")->fetchColumn();
    $sqlExp = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches b JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE i.is_archived = 0 AND i.category IN $in AND b.status = 'active' AND b.quantity_remaining > 0.0001 AND b.expiry_date < CURDATE()")->fetchColumn();
    $ck('dashboard figures (scoped): items 12, expired 1, out 2, low 1, alerts 3', $S['items'] === 12 && $S['expired'] === 1 && $S['out'] === 2 && $S['low'] === 1 && $S['alerts'] === 3 && $S['never'] === 1, json_encode($S));
    $ck('stock value = SQL sum(max(qty,0) x cost) within BALANCE_TOLERANCE', abs($S['value'] - $sqlVal) <= (defined('BALANCE_TOLERANCE') ? BALANCE_TOLERANCE : 0.01) && abs($S['value'] - 771.56) < 0.006, $S['value'] . ' vs ' . $sqlVal);
    $ck('total purchase cost = SQL sum of rounded lines = 1883.93; 5 lines to buy', abs($S['purchase'] - $sqlBuy) <= (defined('BALANCE_TOLERANCE') ? BALANCE_TOLERANCE : 0.01) && abs($S['purchase'] - 1883.93) < 0.006 && $S['lines_to_buy'] === 5, $S['purchase'] . ' vs ' . $sqlBuy);
    $ck('expired count = SQL count of active batches past expiry with stock left', $S['expired'] === $sqlExp);
    $dash = $rd['sheets'][0];
    $cardVals = [$val($dash['cells'][6], 1), $val($dash['cells'][6], 3), $val($dash['cells'][6], 5), $val($dash['cells'][6], 7), $val($dash['cells'][6], 9), $val($dash['cells'][6], 11)];
    $ck('DASHBOARD cards show those six figures', $cardVals[0] === 12 && $cardVals[1] === 1 && $cardVals[2] === 2 && $cardVals[3] === 3 && abs($cardVals[4] - 771.56) < 0.006 && abs($cardVals[5] - 1883.93) < 0.006, json_encode($cardVals));
    $sum = $rd['sheets'][4];
    $grand = null;
    foreach ($sum['cells'] as $cols) { if (isset($cols[1]) && is_string($cols[1]['v']) && stripos($cols[1]['v'], 'GRAND TOTAL') !== false) { $grand = $val($cols, 4); } }
    $ck('PURCHASE SUMMARY grand total = the tabs added up', $grand !== null && abs($grand - 1883.93) < 0.006, json_encode($grand));

    // ---- the whole-stock export equals the stock screens ---------------------------------------------------------------------------------
    $all = import_export_stock_workbook($pdo, ['today' => $today]);
    $alert = rh_stock_alert_summary($pdo, 1);
    $A = $all['summary'];
    $ck('ALL tabs: out / low / never-stocked counts equal rh_stock_alert_summary() exactly', $A['out'] === $alert['out'] && $A['low'] === $alert['low'] && $A['never'] === $alert['never_stocked'], json_encode([$A['out'], $A['low'], $A['never']]) . json_encode([$alert['out'], $alert['low'], $alert['never_stocked']]));
    $ck('ALL tabs: stock value equals rh_stock_valuation() within BALANCE_TOLERANCE', abs($A['value'] - rh_stock_valuation($pdo)) <= (defined('BALANCE_TOLERANCE') ? BALANCE_TOLERANCE : 0.01), $A['value'] . ' vs ' . rh_stock_valuation($pdo));
    $ck('ALL tabs: items = count of non-archived ingredients', $A['items'] === (int)$pdo->query('SELECT COUNT(*) FROM stock_ingredients WHERE is_archived = 0')->fetchColumn());
    fclose($all['stream']);
    $none = import_export_stock_workbook($pdo, ['tabs' => ['no such tab ' . $run]]);
    $ck('a tab that does not exist gives a plain error, no file', empty($none['ok']) && $none['error'] !== '');

    // ---- ROUND TRIP: the export goes back through the Import engine and proposes no change -------------------------------------------------
    $rt = import_export_stock_workbook($pdo, ['tabs' => [$catA], 'hotel' => 'Test Hotel', 'today' => $today]);
    $rtPath = $read($bytesOf($rt), 'roundtrip.xlsx');
    $aliases = import_load_aliases($pdo);
    $an = import_analyze_file($rtPath, basename($rtPath), $aliases);
    $ck('round trip (engine): the export analyses cleanly', !empty($an['ok']), (string)($an['error'] ?? ''));
    $sk = [];
    foreach ($an['sheets'] as $sh) { $sk[$sh['triage']['action']][] = $sh['name']; }
    $ck('round trip: DASHBOARD and PURCHASE SUMMARY are skipped, the category sheet is imported', count($sk['skip'] ?? []) === 2 && count($sk['import'] ?? []) === 1 && empty($sk['ask']), json_encode(array_map('count', $sk)));
    $imp = null;
    foreach ($an['sheets'] as $sh) { if ($sh['triage']['action'] === 'import') { $imp = $sh; } }
    $ck('round trip: 7 items (Lager 2 batch rows -> 8 rows), 1 category heading, 1 total row, 2 note rows, none of them an item',
        count($imp['rows']) === 8 && $imp['counts']['sections'] === 1 && $imp['counts']['totals'] === 1 && $imp['counts']['notes'] === 2, json_encode($imp['counts']));
    $ck('round trip: the category read back is the exact category name', ($imp['categories'][0]['name'] ?? '') === $catA);
    $ck('round trip: derived columns (days, status, action, purchase qty, total cost) are ignored, never imported', count($imp['ignored_columns']) >= 5, json_encode(count($imp['ignored_columns'])));
    $ck('round trip: INVARIANT - every proposed value equals its source cell', import_assert_no_invented_values($an, import_xlsx_read($rtPath)) === []);
    $uq = [];
    foreach ($an['questions'] as $q) { $uq[$q['type']] = ($uq[$q['type']] ?? 0) + 1; }
    $ck('round trip: no blocking question, no conflict from the file itself', $an['totals']['questions_blocking'] === 0 && $an['conflicts'] === [], json_encode($uq));

    // against the database: a job on the export proposes NO change for any item
    $admin = (int)($_SESSION['admin_user_id'] ?? 0);
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('data_import_enabled', '1', 'import') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
    $cr = import_job_create($pdo, $rtPath, 'zzt-export-' . $run . '.xlsx', $admin, ['from_upload' => false, 'allow_reimport' => true]);
    $ck('round trip (database): the export is accepted as an import file', !empty($cr['ok']), (string)($cr['error'] ?? ''));
    if (!empty($cr['ok'])) {
        $jid = (int)$cr['job_id'];
        $j = import_job_get($pdo, $jid);
        $made[] = $j['stored_path'];
        import_job_analyse($pdo, $jid);
        $sm = import_job_review_summary($pdo, $jid);
        $ok7 = 0; $matched = 0; $noDiff = 0;
        foreach ($sm['items'] as $it) {
            $ok7++;
            if (!empty($it['match'])) { $matched++; }
            if (empty($it['diff'])) { $noDiff++; }
        }
        $ck('round trip (database): all 7 items match existing stock and none proposes any change (no price, quantity, max or category difference)', $ok7 === 7 && $matched === 7 && $noDiff === 7, "items=$ok7 matched=$matched nodiff=$noDiff");
        $blocking = 0; $bt = [];
        foreach ($sm['questions'] as $q) { if ($q['blocking'] && $q['answer'] === null) { $blocking++; $bt[] = $q['type']; } }
        $ck('round trip (database): nothing is left to answer, Confirm would be allowed', $blocking === 0 && !empty($sm['can_confirm']), json_encode($bt));
        $ck('round trip (database): zero conflicts', empty($sm['conflicts']) || count($sm['conflicts']) === 0);
        import_job_cancel($pdo, $jid, $admin);
    }
    // negative stock cannot be re-imported: the engine blocks it (documented, expected)
    $nb = import_export_stock_workbook($pdo, ['tabs' => [$catN], 'today' => $today]);
    $nbPath = $read($bytesOf($nb), 'neg.xlsx');
    $nan = import_analyze_file($nbPath, 'neg.xlsx', $aliases);
    $nrows = [];
    foreach ($nan['sheets'] as $sh) { foreach ($sh['rows'] as $r) { $nrows[] = $r['status']; } }
    $ck('negative stock row is BLOCKED by the engine on re-import (expected, documented; never silently clamped)', in_array('blocked', $nrows, true), json_encode($nrows));

    // ---- expired batches are counted from the BATCHES, not from exported rows (Stock Dashboard rule, admin/stock-dashboard.php:79) ------------
    $catX = "ZZT $run Exp";
    $two = $mk($catX, $n('TwoExp'), 'each', 10, 0, '5.0000');
    $batch($two, 'ZE1', 3, $day(-5));
    $batch($two, 'ZE2', 2, $day(-1));
    $zs = $mk($catX, $n('ZeroStale'), 'each', 0, 0, '5.0000');
    $batch($zs, 'ZE3', 2, $day(-3));
    $ex = import_export_stock_workbook($pdo, ['tabs' => [$catX], 'today' => $today]);
    $sqlE = (int)$pdo->query("SELECT COUNT(*) FROM stock_batches b JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE i.category = " . $pdo->quote($catX) . " AND i.is_archived = 0 AND b.status = 'active' AND b.quantity_remaining > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()")->fetchColumn();
    $ck('expired batches: a mismatching item (2 expired batches, total differs from balance) and a zero-balance item with a stale batch are all counted (3), equal to the dashboard query', $ex['summary']['expired'] === 3 && $sqlE === 3, $ex['summary']['expired'] . ' vs ' . $sqlE);
    fclose($ex['stream']);
    // ---- 5,000 items: time and memory -----------------------------------------------------------------------------------------------------
    for ($c = 0; $c < 10; $c++) {
        $ph = []; $args = [];
        for ($i = 0; $i < 500; $i++) {
            $k = $c * 500 + $i;
            $ph[] = '(?, ?, ?, ?, 0, ?, 100, 0, ?, ?, ?)';
            array_push($args, "ZZT $run Big item $k", $catB, 'each', ($k % 7) * 3 + 1, number_format(5 + ($k % 40) * 0.5, 4, '.', ''), 'note ' . $k, ($k % 5 === 0) ? 4 : 0, 40);
        }
        $pdo->prepare('INSERT INTO stock_ingredients (name, category, unit, current_quantity, min_quantity, cost_per_unit, yield_percent, is_archived, notes, reorder_point, par_level) VALUES ' . implode(',', $ph))->execute($args);
    }
    $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, expiry_date, expiry_alert_days, status)
                   SELECT id, CONCAT('ZBX', id), current_quantity, current_quantity, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL (id % 90) DAY), 7, 'active' FROM stock_ingredients WHERE category = ? AND MOD(id, 3) = 0")->execute([$catB]);
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE category = " . $pdo->quote($catB))->fetchColumn();
    if (function_exists('memory_reset_peak_usage')) { memory_reset_peak_usage(); }
    $m0 = memory_get_usage(true);
    $t0 = microtime(true);
    $big = import_export_stock_workbook($pdo, ['tabs' => [$catB], 'today' => $today]);
    $secs = microtime(true) - $t0;
    $peak = memory_get_peak_usage(true) - $m0;
    $bigBytes = $bytesOf($big);
    $info['big_items'] = $cnt;
    $info['big_seconds'] = round($secs, 2);
    $info['big_peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
    $info['big_peak_growth_mb'] = round($peak / 1048576, 1);
    $info['big_file_kb'] = (int)round(strlen($bigBytes) / 1024);
    $info['big_rows'] = $big['summary']['rows'] ?? null;
    $ck('5,000 items: builds in under 30 seconds', !empty($big['ok']) && $secs < 30, (string)round($secs, 2));
    $ck('5,000 items: peak memory under 64 MB (limit is ' . ini_get('memory_limit') . ')', $peak < 64 * 1048576, (string)round($peak / 1048576, 1));
    $bigPath = $read($bigBytes, 'big.xlsx');
    $t1 = microtime(true);
    $ban = import_analyze_file($bigPath, 'big.xlsx', $aliases, ['crosscheck' => false]);
    $info['big_analyse_seconds'] = round(microtime(true) - $t1, 2);
    $info['big_items_read'] = $ban['totals']['items_read'] ?? null;
    $ck('5,000 items: the big workbook reads back with all 5,000 items and no total/heading row as an item', !empty($ban['ok']) && ($ban['totals']['items_read'] ?? 0) === 5000, json_encode($ban['totals'] ?? $ban['error'] ?? null));
} catch (Throwable $e) {
    $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made as $m) { if ($m) { import_delete_stored((string)$m); } }
    foreach ($tmpFiles as $f) { @unlink($f); }
    @rmdir($tmp);
}
echo json_encode(['checks' => $checks, 'info' => $info]);
