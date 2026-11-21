<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
// Data Import engine (Phase 1a): PURE tests, no database. Runs in seconds.
// Run from the project root: php scripts/tests/import_engine_test.php
//
// Covers (2026-11-17 also): bought-as / pack-size / served-by layouts, a corpus of 7 synthetic layouts (fixtures/import/build_bar_layouts.php).
// Covers: pure-PHP zip reader (+ zip-bomb / truncation / encryption caps), xlsx reader quirks, csv reader,
// header mapper, structure finder (sections / totals / notes), validator, duplicate + conflict + case-price
// questions, "nothing invented" invariant, security cases, and (when present) the real hotel files.
// Expected numbers for the synthetic workbook come from fixtures/import/expected.json, written BY HAND.

$root = dirname(__DIR__, 2);
require $root . '/includes/import/default-aliases.php';
require $root . '/includes/import/analyze.php';
require $root . '/includes/import/resolve.php';
require __DIR__ . '/fixtures/import/build_fixture.php';

$ok = 0; $bad = 0;
$t = function ($c, string $n) use (&$ok, &$bad) { $c = (bool)$c; echo ($c ? 'PASS ' : 'FAIL ') . $n . "\n"; $c ? $ok++ : $bad++; };
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'import_engine_test_' . getmypid();
@mkdir($tmpDir);
$tmpFiles = [];
$put = function (string $bytes, string $name) use ($tmpDir, &$tmpFiles): string {
    $p = $tmpDir . DIRECTORY_SEPARATOR . count($tmpFiles) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    file_put_contents($p, $bytes);
    $tmpFiles[] = $p;
    return $p;
};
$al = import_default_aliases();
$expected = json_decode(file_get_contents(__DIR__ . '/fixtures/import/expected.json'), true);
$fixtureBytes = fx_build_main();
$fixturePath = __DIR__ . '/fixtures/import/synthetic_stock.xlsx';

// rewrite one part of a zip (used to plant XXE payloads)
$rewrite = function (string $zipBytes, array $changes) {
    $z = import_zip_open($zipBytes, true);
    $files = [];
    foreach ($z['entries'] as $e) {
        $r = import_zip_read($z, $e['name']);
        $files[$e['name']] = isset($changes[$e['name']]) ? $changes[$e['name']]($r['data']) : $r['data'];
    }
    return fx_zip($files);
};
$findCell = function (array $sheet, string $ref) {
    $p = import_parse_ref($ref);
    return $sheet['cells'][$p[0]][$p[1]] ?? null;
};

// =====================================================================================================
echo "-- committed fixture is current --\n";
$zc = import_zip_open($fixturePath); $zf = import_zip_open($fixtureBytes, true);
$same = $zc['ok'] && $zf['ok'] && array_keys($zc['entries']) === array_keys($zf['entries']);
if ($same) { foreach ($zc['entries'] as $k => $e) { if ($e['method'] !== $zf['entries'][$k]['method'] || import_zip_read($zc, $e['name'])['data'] !== import_zip_read($zf, $e['name'])['data']) { $same = false; } } }
$t($same, 'committed synthetic_stock.xlsx has the same parts and contents as what build_fixture.php produces now (compares unpacked parts, not zlib bytes)');

// =====================================================================================================
echo "-- zip reader --\n";
$z = import_zip_open($fixtureBytes, true);
$t($z['ok'] && import_zip_has($z, 'xl/workbook.xml') && import_zip_has($z, '[Content_Types].xml'), 'opens a normal workbook from a string');
$t(import_zip_open($fixturePath)['ok'], 'opens the same workbook from a file path');
$ct = import_zip_read($z, '[Content_Types].xml');
$t($ct['ok'] && $z['entries'][strtolower('[Content_Types].xml')]['method'] === 0 && strpos($ct['data'], '<Types') !== false, 'stored (method 0) entry reads');
$wb = import_zip_read($z, 'xl/workbook.xml');
$t($wb['ok'] && $z['entries']['xl/workbook.xml']['method'] === 8 && strpos($wb['data'], '<workbook') !== false, 'deflated (method 8) entry reads');
$t(!import_zip_read($z, 'nope.xml')['ok'], 'missing part reports an error');

$bomb = import_zip_open(fx_zip(['a.xml' => ['data' => '<x/>', 'usize' => 200 * 1024 * 1024]]), true);
$t(!$bomb['ok'] && strpos($bomb['error'], 'zip bomb') !== false, 'declared 200 MB unpacked size is refused');
$many = import_zip_open(fx_zip(['a.xml' => ['data' => '<x/>', 'usize' => 50 * 1024 * 1024], 'b.xml' => ['data' => '<x/>', 'usize' => 50 * 1024 * 1024], 'c.xml' => ['data' => '<x/>', 'usize' => 50 * 1024 * 1024]]), true);
$t(!$many['ok'] && strpos($many['error'], 'zip bomb') !== false, 'total declared size over the cap is refused');
$ratio = import_zip_open(fx_zip(['a.xml' => str_repeat('0', 3 * 1024 * 1024)]), true);
$t(!$ratio['ok'] && strpos($ratio['error'], 'compressed suspiciously') !== false, 'extreme compression ratio is refused');
$lie = import_zip_open(fx_zip(['a.xml' => ['data' => str_repeat('A', 100000), 'usize' => 10]]), true);
$t($lie['ok'] && !import_zip_read($lie, 'a.xml')['ok'], 'entry that inflates past its declared size is refused when read');
$lie2 = import_zip_open(fx_zip(['a.xml' => ['data' => 'tiny', 'usize' => 500]]), true);
$t($lie2['ok'] && !import_zip_read($lie2, 'a.xml')['ok'], 'entry smaller than its declared size is refused (wrong size)');
$crc = import_zip_open(fx_zip(['a.xml' => ['data' => 'hello world', 'crc' => 123]]), true);
$t($crc['ok'] && !import_zip_read($crc, 'a.xml')['ok'], 'bad CRC is refused');
$files2001 = [];
for ($i = 0; $i < 2001; $i++) { $files2001['p' . $i . '.xml'] = ['data' => 'x', 'method' => 0]; }
$t(!import_zip_open(fx_zip($files2001), true)['ok'], '2001 entries are refused (cap 2000)');
$cut = import_zip_open(substr($fixtureBytes, 0, -30), true);
$t(!$cut['ok'], 'zip with its end cut off (truncated upload) is refused');
$mid = substr($fixtureBytes, 0, 150) . substr($fixtureBytes, 400);
$zm = import_zip_open($mid, true);
$midBroken = !$zm['ok'];
if ($zm['ok']) { foreach ($zm['entries'] as $e) { if (!import_zip_read($zm, $e['name'])['ok']) { $midBroken = true; } } }
$t($midBroken, 'zip with a chunk missing from the middle is refused');
$t(!import_xlsx_read(substr($fixtureBytes, 0, (int)(strlen($fixtureBytes) * 0.6)), true)['ok'], 'xlsx cut at 60% is refused');
$enc = import_zip_open(fx_zip(['a.xml' => ['data' => 'secret', 'flags' => 1]]), true);
$t(!$enc['ok'] && strpos($enc['error'], 'password') !== false, 'encrypted entry is refused with a plain message');
$raw64 = fx_zip(['a.xml' => '<x/>']);
$p = strlen($raw64) - 22;
$raw64 = substr_replace($raw64, "\xFF\xFF\xFF\xFF", $p + 8, 4);
$z64 = import_zip_open($raw64, true);
$t(!$z64['ok'] && strpos($z64['error'], 'zip64') !== false, 'zip64 is refused with a plain message');
$dup = str_replace('bbb.xml', 'aaa.xml', fx_zip(['aaa.xml' => '<x/>', 'bbb.xml' => '<y/>']));
$t(!import_zip_open($dup, true)['ok'], 'duplicate part names are refused');
$t(!import_zip_open('not a zip at all', true)['ok'], 'plain text is not a zip');
$t(!import_zip_open(str_repeat('PK', 20), true)['ok'], 'junk starting with PK is not a zip');

// =====================================================================================================
echo "-- xlsx reader: quirks of the synthetic workbook --\n";
$x = import_xlsx_read($fixtureBytes, true);
$t($x['ok'] && count($x['sheets']) === 6, 'six sheets read');
$names = array_map(function ($s) { return $s['name']; }, $x['sheets']);
$t($names[1] === "\u{1F37A} BAR" && $names[2] === "\u{1F373} KITCHEN", 'emoji sheet names survive as UTF-8');
$t($x['sheets'][4]['hidden'] === true && $x['sheets'][1]['hidden'] === false, 'hidden sheet is flagged, others are not');
$bar = $x['sheets'][1];
$t($bar['dims']['ref'] === 'A1:I19', 'dims of BAR are A1:I19 (got ' . $bar['dims']['ref'] . ')');
$t(count($bar['merges']) === 2 && $bar['merges'][0]['ref'] === 'A1:I1', 'merged ranges listed');
$t(($findCell($bar, 'B1')['merged_into'] ?? '') === 'A1' && ($findCell($bar, 'A1')['merge_span'] ?? []) === [1, 9], 'merged title: covered cells point to the anchor');
$e4 = $findCell($bar, 'E4');
$t($e4['formula'] === true && $e4['cached'] === false && $e4['v'] === null && $e4['raw'] === null, 'formula with EMPTY <v></v> is unknown (cached=false, v=null, never 0)');
$e5 = $findCell($bar, 'E5');
$t($e5['formula'] === true && $e5['cached'] === false && $e5['v'] === null, 'formula with NO <v> element is unknown too');
$g4 = $findCell($bar, 'G4');
$t($g4['formula'] === true && $g4['cached'] === true && $g4['v'] === 120, 'formula with a stored result keeps it (120)');
$h4 = $findCell($bar, 'H4');
$t($h4['formula'] && $h4['cached'] && $h4['v'] === 'OK', 't="str" formula result is text');
$h5 = $findCell($bar, 'H5');
$t($h5['error'] === true && $h5['v'] === null && $h5['raw'] === '#N/A', 't="e" error cell has no value');
$t($findCell($x['sheets'][0], 'C3')['v'] === true, 't="b" boolean cell');
$f4 = $findCell($bar, 'F4');
$t($f4['date'] === true && $f4['v'] === '2027-03-27' && $f4['raw'] === '46473', 'built-in date format 14: serial 46473 = 2027-03-27');
$f5 = $findCell($bar, 'F5');
$t($f5['date'] === true && $f5['v'] === '2026-03-19', 'custom date format: serial 46100 = 2026-03-19');
$t($findCell($bar, 'A10')['v'] === 'Hill Stoot', 'inline string read');
$t($findCell($bar, 'A11')['v'] === 'Crown Premium', 'rich-text shared string concatenates its runs');
$t($findCell($bar, 'I18')['v'] === '1,800' && $findCell($bar, 'I13')['v'] === "\u{2014}", 'text cells are kept exactly as written');
$t($findCell($bar, 'C4')['v'] === 10 && $findCell($bar, 'C4')['date'] === false, 'plain number stays a number, not a date');
$t($findCell($bar, 'I2') === null, 'cells that do not exist are absent');
$t(strpos($findCell($bar, 'I3')['v'], "\n") !== false, 'header text keeps its newline (the mapper normalises it)');

// a custom NON-date number format containing letters ("MWK") must not turn numbers into dates; 1904 + 1900 systems
$spec1904 = ['date1904' => true, 'sheets' => [['name' => 'T', 'rows' => [1 => ['A' => fx_S('Item'), 'B' => fx_S('Qty'), 'C' => fx_S('Expiry')], 2 => ['A' => fx_S('Thing'), 'B' => fx_N(5, 4), 'C' => fx_D(36000)]]]]];
$r1904 = import_xlsx_read(fx_xlsx($spec1904), true);
$t($r1904['ok'] && $r1904['date1904'] === true, '1904 flag is read');
$t($r1904['sheets'][0]['cells'][2][3]['v'] === '2002-07-25', '1904 system: serial 36000 = 2002-07-25');
$t($r1904['sheets'][0]['cells'][2][2]['date'] === false && $r1904['sheets'][0]['cells'][2][2]['v'] === 5, 'number with a quoted-letters custom format is NOT a date');
$spec1900 = $spec1904; $spec1900['date1904'] = false;
$r1900 = import_xlsx_read(fx_xlsx($spec1900), true);
$t($r1900['sheets'][0]['cells'][2][3]['v'] === '1998-07-24', '1900 system: serial 36000 = 1998-07-24');
$t(import_xlsx_serial_to_iso(61.0) === '1900-03-01' && import_xlsx_serial_to_iso(59.0) === '1900-02-28', 'Excel 1900 leap-year quirk handled around serial 60');
$an1904 = import_analyze_file($put(fx_xlsx($spec1904), 'wb1904.xlsx'), 'wb1904.xlsx', $al);
$t($an1904['ok'] && $an1904['date1904'] === true && ($an1904['sheets'][0]['rows'][0]['fields']['expiry']['value'] ?? '') === '2002-07-25', 'analysis carries the 1904 date through');

// =====================================================================================================
echo "-- xlsx reader: security --\n";
$xxeDecl = '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/hostname">]>';
$xxeNeedles = ['root:', 'hostname', '/etc/'];
foreach (['xl/worksheets/sheet2.xml' => 'sheet XML', 'xl/sharedStrings.xml' => 'shared strings', 'xl/workbook.xml' => 'workbook', 'xl/styles.xml' => 'styles'] as $part => $label) {
    $evil = $rewrite($fixtureBytes, [$part => function ($xml) use ($xxeDecl) {
        return preg_replace('/^(<\?xml[^>]*\?>)/', '$1' . $xxeDecl, $xml) . '';
    }]);
    $res = import_xlsx_read($evil, true);
    $leak = false;
    foreach ($xxeNeedles as $needle) { if (stripos($res['error'], $needle) !== false) { $leak = true; } }
    $t(!$res['ok'] && !$leak, 'XXE payload (DOCTYPE/ENTITY) in ' . $label . ' is refused and nothing leaks');
}
$laughs = $rewrite($fixtureBytes, ['xl/sharedStrings.xml' => function ($xml) { return preg_replace('/^(<\?xml[^>]*\?>)/', '$1<!DOCTYPE l [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;">]>', $xml); }]);
$t(!import_xlsx_read($laughs, true)['ok'], 'entity-expansion ("billion laughs") payload is refused');
$ampOk = import_xlsx_read(fx_xlsx(['sheets' => [['name' => 'R&D <sheet>', 'rows' => [1 => ['A' => fx_S('Item'), 'B' => fx_S('Qty')], 2 => ['A' => fx_S('Soap & <Water>'), 'B' => fx_N(2)]]]]]), true);
$t($ampOk['ok'] && $ampOk['sheets'][0]['name'] === 'R&D <sheet>' && $ampOk['sheets'][0]['cells'][2][1]['v'] === 'Soap & <Water>', 'ordinary XML escapes (&amp; &lt;) still read correctly');
$macro = $rewrite($fixtureBytes, []);
$macroZ = import_zip_open($macro, true);
$files = [];
foreach ($macroZ['entries'] as $e) { $files[$e['name']] = import_zip_read($macroZ, $e['name'])['data']; }
$files['xl/vbaProject.bin'] = "\xD0\xCF\x11\xE0 fake macro payload";
$macroBook = import_xlsx_read(fx_zip($files), true);
$t($macroBook['ok'] && $macroBook['macros'] === true, '.xlsm with a macro part: cells read, macro flagged, nothing executed');

// =====================================================================================================
echo "-- csv reader --\n";
$inj = "Item,Qty,Price\n=cmd|' /C calc'!A0,5,100\n+1+1,3,200\n-2+3,1,300\n@SUM(A1),2,400\nNormal,4,500\n";
$c = import_csv_read_string($inj, 'inj.csv');
$cs = $c['sheets'][0];
$t($c['ok'] && $cs['cells'][2][1]['v'] === "=cmd|' /C calc'!A0" && $cs['cells'][2][1]['formula'] === false && $cs['cells'][2][1]['formula_like'] === true, 'CSV =cmd|... cell comes back byte-identical as plain text');
$t($cs['cells'][3][1]['v'] === '+1+1' && $cs['cells'][4][1]['v'] === '-2+3' && $cs['cells'][5][1]['v'] === '@SUM(A1)', '+ - @ cells stay plain text too');
$anInj = import_analyze_file($put($inj, 'inj.csv'), 'inj.csv', $al);
$injRow = null;
foreach ($anInj['sheets'][0]['rows'] as $r) { if ($r['fields']['item_name']['value'] === "=cmd|' /C calc'!A0") { $injRow = $r; } }
$t($injRow !== null && $injRow['status'] === 'needs-answer', 'a formula-looking item name is carried as text and needs a person to confirm');
$hasFormulaQ = false;
foreach ($anInj['questions'] as $q) { if ($q['type'] === 'formula-name') { $hasFormulaQ = true; } }
$t($hasFormulaQ, 'and a formula-name question is asked');
$semi = import_csv_read_string("Item;Qty\nA;1\nB;2\n", 's.csv');
$t($semi['delimiter'] === ';' && $semi['sheets'][0]['cells'][3][2]['v'] === '2', 'semicolon delimiter sniffed');
$tab = import_csv_read_string("Item\tQty\nA\t1\n", 't.tsv');
$t($tab['delimiter'] === "\t", 'tab delimiter for .tsv');
$pipe = import_csv_read_string("Item|Qty|Price\nA|1|2\nB|3|4\n", 'p.csv');
$t($pipe['delimiter'] === '|', 'pipe delimiter sniffed');
$w = import_csv_read_string("Item,Qty\nCaf\xE9,1\n", 'w.csv');
$t($w['encoding'] === 'Windows-1252' && $w['sheets'][0]['cells'][2][1]['v'] === "Caf\u{E9}", 'Windows-1252 fallback decodes to UTF-8');
$bom = import_csv_read_string("\xEF\xBB\xBFItem,Qty\nA,1\n", 'b.csv');
$t($bom['sheets'][0]['cells'][1][1]['v'] === 'Item', 'UTF-8 BOM is stripped');
$u16 = import_csv_read_string("\xFF\xFE" . mb_convert_encoding("Item,Qty\nA,1\n", 'UTF-16LE', 'UTF-8'), 'u.csv');
$t($u16['ok'] && $u16['sheets'][0]['cells'][2][1]['v'] === 'A', 'UTF-16 with BOM is converted');
$q = import_csv_read_string("Item,Note\n\"Smith, J\",\"line1\nline2\"\n", 'q.csv');
$t($q['sheets'][0]['cells'][2][1]['v'] === 'Smith, J' && $q['sheets'][0]['cells'][2][2]['v'] === "line1\nline2", 'quoted commas and newlines inside a cell');
$t(!import_csv_read_string("Item,Qty\n\0\0\0binary", 'x.csv')['ok'], 'binary data is not a CSV');
$t(!import_csv_read_string($fixtureBytes, 'actually.csv')['ok'], 'a zip named .csv is refused');

// =====================================================================================================
echo "-- header mapper --\n";
$mh = function (string $h) use ($al) { return import_match_header($h, $al); };
$cases = [
    'Item Name' => 'item_name', "Item  \n Name" => 'item_name', 'ITEM-NAME' => 'item_name', "\u{1F37A} Item Name" => 'item_name',
    "Unit Price\n(MWK)" => 'unit_price', 'unit price (mwk)' => 'unit_price', 'Qty.' => 'qty_single', 'Stores   Balance' => 'qty_secondary',
    'Bar Balance' => 'qty_primary', 'Kitchen Balance' => 'qty_primary', 'Total Balance' => 'qty_total', "Purchase Qty\n(Max - Balance)" => 'purchase_qty',
    'Expiry Date' => 'expiry', 'Days to Expiry' => 'days_to_expiry', 'Max Level' => 'max_level', 'Par' => 'max_level', 'Batch' => 'batch',
    'Units' => 'unit', 'Remarks' => 'remarks', 'Comments' => 'remarks', 'Total Cost (MWK)' => 'total_cost', 'Status' => 'status',
];
foreach ($cases as $h => $field) {
    $m = $mh($h);
    $t($m['field'] === $field && $m['conf'] >= 0.85, 'header ' . json_encode($h) . ' -> ' . $field . ' (got ' . ($m['field'] ?? 'null') . ' ' . $m['conf'] . ')');
}
$t($mh('Zzzz Qqqq')['field'] === null, 'unknown header maps to nothing');
$ty = $mh('Quantiy');
$t($ty['field'] === 'qty_single' && $ty['conf'] < 0.85, 'typo header gives a LOW-confidence candidate (a question, not a guess)');
$custom = ['fields' => ['item_name' => ['thing'], 'unit_price' => ['rate card']], 'noise_tokens' => []];
$t(import_match_header('Thing', $custom)['field'] === 'item_name' && import_match_header('Item Name', $custom)['field'] === null, 'alias set is a PARAMETER (custom set changes the result)');
$t(import_match_header('Thing', $al)['field'] === null, '...and the seed set does not know "Thing"');
$merged = import_merge_aliases($al, ['fields' => ['item_name' => ['thing']]]);
$t(import_match_header('Thing', $merged)['field'] === 'item_name', 'learned hotel aliases layer over the seed');

// mapping clash and value-shape check (each ends as a QUESTION, no guess)
$clash = import_analyze_file($put("Item,Cost,Price,Qty\nA,1,2,3\nB,1,2,3\nC,1,2,3\n", 'clash.csv'), 'clash.csv', $al);
$cq = array_filter($clash['questions'], function ($q) { return $q['type'] === 'mapping-unsure'; });
$t(count($cq) === 2, 'two columns that both look like the price: one question each, neither is applied (got ' . count($cq) . ')');
$t(count($clash['sheets'][0]['rows']) === 3 && $clash['sheets'][0]['rows'][0]['status'] === 'needs-answer' && !isset($clash['sheets'][0]['rows'][0]['fields']['unit_price']), 'rows wait for the answer and carry no price');
$shape = import_analyze_file($put("Item,Qty,Price\nA,1,cheap\nB,2,dear\nC,3,free\nD,4,pricey\n", 'shape.csv'), 'shape.csv', $al);
$sq = array_values(array_filter($shape['questions'], function ($q) { return $q['type'] === 'mapping-unsure'; }));
$t(count($sq) === 1 && strpos($sq[0]['text'], 'do not fit') !== false, 'a "Price" column full of words is demoted to a question (value-shape check)');
$t(!isset($shape['sheets'][0]['rows'][0]['fields']['unit_price']), '...and no price is proposed from it');

// =====================================================================================================
echo "-- numbers, dates, names --\n";
$nums = [
    ['1,234.50', 'ok', 1234.5], ['MK 12,500', 'ok', 12500], ['1 250', 'ok', 1250], ['12,5', 'ok', 12.5], ['1,250', 'ok', 1250],
    ['0.75', 'ok', 0.75], ["\u{2014}", 'empty', null], ['-', 'empty', null], ['', 'empty', null], ['n/a', 'empty', null],
    ['abc', 'invalid', null], ['12abc', 'invalid', null], [7, 'ok', 7], [2.5, 'ok', 2.5],
];
foreach ($nums as [$in, $st, $val]) {
    $p = import_parse_number($in);
    $t($p['status'] === $st && ($val === null || abs($p['value'] - $val) < 1e-9), 'number ' . json_encode($in) . ' -> ' . $st . ($val !== null ? ' ' . $val : ''));
}
$t(import_parse_number('-5')['neg'] === true && import_parse_number('(500)')['neg'] === true, 'negatives are detected ("-5", "(500)")');
$dates = [
    ['2027-03-27', '2027-03-27', false, 'as-written'], ['27/03/2027', '2027-03-27', false, 'converted-date'], ['03/27/2027', '2027-03-27', false, 'converted-date'],
    ['27 Mar 2027', '2027-03-27', false, 'converted-date'], ['04/05/2027', '2027-05-04', true, 'converted-date'],
];
foreach ($dates as [$in, $iso, $amb, $how]) {
    $p = import_parse_date_string($in);
    $t($p['ok'] && $p['iso'] === $iso && $p['ambiguous'] === $amb && $p['how'] === $how, 'date ' . $in . ' -> ' . $iso . ($amb ? ' (ambiguous, asks)' : ''));
}
$t(!import_parse_date_string('soon')['ok'] && !import_parse_date_string('31/02/2027')['ok'], 'non-dates and impossible dates are rejected');
$t(import_norm_text("  \u{1F37A} Mang\u{ED}na!! ") === 'mangina', 'name normaliser: emoji, accents, punctuation, case');
$s = import_suggest_names('Harbour Lagr', ['Harbour Lager', 'Lakeside Pilsner', 'Harbour Lagr'], []);
$t(count($s) === 1 && $s[0]['name'] === 'Harbour Lager' && $s[0]['score'] >= 0.85, 'suggest_names: Harbour Lagr -> Harbour Lager (and not itself)');
$t(import_suggest_names('Lakesyde pilsner', ['Lakeside pilsner'], [])[0]['name'] === 'Lakeside pilsner', 'suggest_names: Lakesyde pilsner -> Lakeside pilsner');
$t(import_suggest_names('Reedbed Stuot', ['Reedbed Stout'], [])[0]['name'] === 'Reedbed Stout', 'suggest_names: Reedbed Stuot -> Reedbed Stout');
$t(import_suggest_names('Blue 52', ['Blue 55'], []) === [], 'suggest_names: different numbers are different items');
$t(import_suggest_names('Harbour Lager', ['HARBOUR LAGER '], []) === [], 'suggest_names: same name after normalising is not a "suggestion"');

// =====================================================================================================
echo "-- synthetic workbook: whole analysis vs expected.json (frozen by hand) --\n";
$a = import_analyze_file($fixturePath, 'synthetic_stock.xlsx', $al);
$t($a['ok'] && $a['file']['sha256'] === hash_file('sha256', $fixturePath), 'analysis ok, SHA-256 recorded');
$tot = $a['totals'];
foreach (['sheets_total', 'sheets_imported', 'sheets_skipped', 'items_read', 'categories', 'skipped_totals_rows', 'ignored_derived_columns', 'rows_ready', 'rows_needs_answer', 'rows_blocked', 'questions_total', 'questions_blocking', 'conflicts'] as $k) {
    $t(($tot[$k] ?? null) === $expected[$k], 'total ' . $k . ' = ' . $expected[$k] . ' (got ' . json_encode($tot[$k] ?? null) . ')');
}
$t($tot['items_read'] === $tot['rows_ready'] + $tot['rows_needs_answer'] + $tot['rows_blocked'], 'every item row is accounted for (ready + needs-answer + blocked = items read)');
$types = [];
foreach ($a['questions'] as $q) { $types[$q['type']] = ($types[$q['type']] ?? 0) + 1; }
ksort($types); $exTypes = $expected['question_types']; ksort($exTypes);
$t($types === $exTypes, 'question types match: ' . json_encode($types));
foreach ($expected['sheets'] as $idx => $ex) {
    $sh = $a['sheets'][(int)$idx];
    $t($sh['triage']['action'] === $ex['action'], 'sheet ' . $idx . ' triage = ' . $ex['action'] . ' (' . ($ex['why'] ?? 'items') . ')');
    if ($ex['action'] !== 'import') { $t($sh['triage']['reason'] !== '' && count($sh['rows']) === 0, 'sheet ' . $idx . ' skipped with a stated reason and no rows'); continue; }
    $t($sh['header_row'] === $ex['header_row'], 'sheet ' . $idx . ' header row ' . $ex['header_row']);
    $t(count($sh['rows']) === $ex['items'] && $sh['counts']['sections'] === $ex['sections'] && $sh['counts']['totals'] === $ex['totals'], 'sheet ' . $idx . ' items/sections/totals = ' . $ex['items'] . '/' . $ex['sections'] . '/' . $ex['totals']);
    $t(count($sh['ignored_columns']) === $ex['derived_columns'], 'sheet ' . $idx . ' derived columns ignored = ' . $ex['derived_columns']);
    $t(array_map(function ($c) { return $c['name']; }, $sh['categories']) === $ex['categories'], 'sheet ' . $idx . ' categories ' . json_encode($ex['categories']));
    $st = [];
    foreach ($sh['rows'] as $r) { $st[(string)$r['row']] = $r['status']; }
    $t($st === $ex['status'], 'sheet ' . $idx . ' row statuses match');
    $sk = ['section' => [], 'total' => []];
    foreach ($sh['skipped_rows'] as $r) { if (isset($sk[$r['kind']])) { $sk[$r['kind']][] = $r['row']; } }
    $t($sk === $expected['skipped_row_numbers'][$idx], 'sheet ' . $idx . ' section/total row numbers ' . json_encode($sk));
}
// no section or total row ever became an item
$itemRows = [];
foreach ($a['sheets'] as $sh) { foreach ($sh['rows'] as $r) { $itemRows[$sh['index'] . ':' . $r['row']] = true; } }
$leak = 0;
foreach ($expected['skipped_row_numbers'] as $idx => $kinds) { foreach ($kinds as $rows) { foreach ($rows as $rn) { if (isset($itemRows[$idx . ':' . $rn])) { $leak++; } } } }
$t($leak === 0, 'no section or TOTAL row appears as an item');
// hand-computed values
$find = function (array $a, int $si, int $row) { foreach ($a['sheets'][$si]['rows'] as $r) { if ($r['row'] === $row) { return $r; } } return null; };
foreach ($expected['values'] as $v) {
    $r = $find($a, $v['sheet'], $v['row']);
    $f = $r['fields'][$v['field']] ?? null;
    $okv = $f !== null && $f['value'] === $v['value'] && $f['how'] === $v['how'] && $f['source'] === $a['sheets'][$v['sheet']]['name'] . '!' . explode('!', $v['source'])[1];
    $t($okv, 'value ' . $v['field'] . ' ' . json_encode($v['value']) . ' from ' . $v['source'] . ' (' . $v['how'] . ')' . ($okv ? '' : ' got ' . json_encode($f)));
}
foreach ($expected['category_of'] as $v) {
    $r = $find($a, $v['sheet'], $v['row']);
    $t($r !== null && $r['category'] === $v['category'], 'category of sheet ' . $v['sheet'] . ' row ' . $v['row'] . ' = ' . json_encode($v['category']));
}
foreach ($expected['absent'] as $v) {
    $r = $find($a, $v['sheet'], $v['row']);
    $t($r !== null && !isset($r['fields'][$v['field']]), 'no ' . $v['field'] . ' proposed for sheet ' . $v['sheet'] . ' row ' . $v['row'] . ' (' . $v['why'] . ')');
}
foreach ($expected['unknown'] as $v) {
    $r = $find($a, $v['sheet'], $v['row']);
    $t($r !== null && isset($r['unknown'][$v['field']]) && !isset($r['fields'][$v['field']]), 'unknown (never 0): ' . $v['field'] . ' on sheet ' . $v['sheet'] . ' row ' . $v['row']);
}
$r5 = $find($a, 1, 5);
$t(!isset($r5['computed']['qty_total']), 'computed total is withheld when a part is unknown');
$r4 = $find($a, 1, 4);
$t(($r4['computed']['qty_total']['value'] ?? null) === 30 && count($r4['computed']['qty_total']['sources']) === 2, 'computed total (10+20) lists both source cells and is not a "proposed value"');
$hasDerivedField = false;
foreach ($a['sheets'] as $sh) { foreach ($sh['rows'] as $r) { foreach (['qty_total', 'days_to_expiry', 'status', 'action', 'purchase_qty', 'total_cost'] as $d) { if (isset($r['fields'][$d])) { $hasDerivedField = true; } } } }
$t(!$hasDerivedField, 'derived columns (total, days, status, action, purchase qty, total cost) are never proposed');
$ig = $r4['ignored'];
$t(count($ig) === 3 && $ig[0]['how'] === 'derived-ignored' && $ig[0]['unknown'] === true, 'derived cells are listed greyed with how=derived-ignored (Total Balance unknown)');
// conflicts, case price, typos, duplicates, batches
$cf = $a['conflicts'][0];
$t(count($a['conflicts']) === 1 && $cf['item'] === $expected['conflict']['item'] && $cf['field'] === 'unit_price', 'one price conflict: Ginger Beer');
$vals = array_map(function ($v) { return (float)$v['value']; }, $cf['values']); sort($vals);
$srcs = array_map(function ($v) { return explode('!', $v['source'])[1]; }, $cf['values']); sort($srcs);
$t($vals === [1800.0, 2200.0] && $srcs === ['F6', 'I18'], 'conflict lists BOTH values with their cells (no winner)');
$cq = null; foreach ($a['questions'] as $q) { if ($q['type'] === 'conflict') { $cq = $q; } }
$t($cq !== null && strpos($cq['text'], '1,800') !== false && strpos($cq['text'], '2,200') !== false && $cq['blocking'] && $cq['answer'] === null && count($cq['row_uids']) === 2, 'conflict question names both prices and blocks both rows');
$cp = null; foreach ($a['questions'] as $q) { if ($q['type'] === 'case-price') { $cp = $q; } }
$t($cp !== null && $cp['row'] === $expected['case_price']['row'] && strpos($cp['text'], 'whole case') !== false, 'case-price question on Crown Premium (BAR row 11)');
$ty = null; foreach ($a['questions'] as $q) { if ($q['type'] === 'possible-typo') { $ty = $q; } }
$t($ty !== null && $ty['blocking'] === false && count($ty['row_uids']) === 2 && $find($a, 1, 9)['status'] === 'ready' && $find($a, 1, 10)['status'] === 'ready', 'typo pair Hill Stout / Hill Stoot is a NON-blocking suggestion, nothing renamed');
$t($find($a, 1, 10)['fields']['item_name']['value'] === 'Hill Stoot' && $find($a, 1, 9)['fields']['item_name']['value'] === 'Hill Stout', 'fuzzy match never auto-applies: both names kept as written');
$dq = null; foreach ($a['questions'] as $q) { if ($q['type'] === 'duplicate-batch-clash') { $dq = $q; } }
$t($dq !== null && $dq['rows'] === $expected['duplicate']['rows'] && strpos($dq['text'], 'Row 13 "Mango Cordial" appears 2 times') !== false && strpos($dq['text'], 'separate batches or a duplicate') !== false, 'duplicate without batch asks: ' . ($dq['text'] ?? ''));
$t(($find($a, 1, 7)['group']['resolution'] ?? '') === 'batches' && ($find($a, 1, 8)['group']['batches'] ?? []) === ['b1', 'b2'], 'same name with Batch B1/B2 = one item, two batches, no question');
$bv = null; foreach ($a['questions'] as $q) { if ($q['type'] === 'bad-value') { $bv = $q; } }
$t($bv !== null && $find($a, 1, 17)['status'] === 'blocked' && strpos($bv['text'], 'negative') !== false, 'negative quantity blocks the row with a plain message');
// the per-row warnings
$w6 = array_map(function ($w) { return $w['code']; }, $find($a, 2, 9)['warnings']);
$t(in_array('unknown-formula', $w6, true), 'unknown formula price is reported as a warning on the row');
// nothing invented
$readerCells = import_xlsx_read($fixturePath);
$t(import_assert_no_invented_values($a, $readerCells) === [], 'INVARIANT: every proposed value equals its source cell');
$tamper = $a; $tamper['sheets'][1]['rows'][0]['fields']['unit_price']['value'] = 2501;
$t(count(import_assert_no_invented_values($tamper, $readerCells)) === 1, 'invariant catches a changed number');
$tamper = $a; $tamper['sheets'][1]['rows'][0]['fields']['qty_total'] = ['value' => 30, 'source' => "\u{1F37A} BAR!E4", 'how' => 'as-written'];
$t(count(import_assert_no_invented_values($tamper, $readerCells)) >= 1, 'invariant catches a derived field smuggled in');
$tamper = $a; $tamper['sheets'][1]['rows'][0]['fields']['qty_secondary']['source'] = "\u{1F37A} BAR!E4";
$t(count(import_assert_no_invented_values($tamper, $readerCells)) >= 1, 'invariant catches a value pointed at an uncalculated formula cell');
$tamper = $a; $tamper['sheets'][1]['rows'][0]['fields']['item_name']['value'] = 'Lakeview Lagers';
$t(count(import_assert_no_invented_values($tamper, $readerCells)) === 1, 'invariant catches a changed name');
$tamper = $a; $tamper['sheets'][1]['rows'][0]['fields']['expiry']['value'] = '2027-03-28';
$t(count(import_assert_no_invented_values($tamper, $readerCells)) === 1, 'invariant catches a changed date');

// options: threshold parameter, forced sheets, known names, hidden
$a2 = import_analyze_file($fixturePath, 'synthetic_stock.xlsx', $al, ['case_price_min_peers' => 1000]);
$t(!in_array('case-price', array_map(function ($q) { return $q['type']; }, $a2['questions']), true) && $a2['totals']['rows_needs_answer'] === 4, 'case-price needs enough peers as evidence (a parameter; too few -> no question)');
$a3 = import_analyze_file($fixturePath, 'synthetic_stock.xlsx', $al, ['force_sheets' => ["\u{1F576} HIDDEN LIST"]]);
$t($a3['sheets'][4]['triage']['action'] === 'import' && count($a3['sheets'][4]['rows']) === $expected['hidden_forced_items'] && $a3['totals']['items_read'] === 22, 'a person can overrule the hidden-sheet skip');
$a4 = import_analyze_file($fixturePath, 'synthetic_stock.xlsx', $al, ['known_names' => ['Lakeview Larger', 'Palm Lager']]);
$kq = array_values(array_filter($a4['questions'], function ($q) { return ($q['against'] ?? '') === 'existing'; }));
$t(count($kq) === 1 && $kq[0]['blocking'] === false && strpos($kq[0]['text'], 'Did you mean "Lakeview Larger"') !== false && $a4['totals']['questions_blocking'] === 4, 'known (existing) names give a "did you mean" suggestion, non-blocking, nothing renamed');
$a5 = import_analyze_file($fixturePath, 'synthetic_stock.xlsx', $al, ['crosscheck' => false]);
$t($a5['totals']['conflicts'] === 0 && $a5['totals']['questions_total'] === 2, 'crosscheck can be switched off (only in-sheet questions remain)');

// =====================================================================================================
echo "-- other structure cases --\n";
$dupCsv = import_analyze_file($put("Item,Qty,Price\nHarbour Lager,3,2000\nHarbour Lager,4,2000\nStout,1,500\n", 'dup.csv'), 'dup.csv', $al);
$d = array_values(array_filter($dupCsv['questions'], function ($q) { return $q['type'] === 'duplicate-no-batch'; }));
$t(count($d) === 1 && strpos($d[0]['text'], 'Row 2 "Harbour Lager" appears 2 times (rows 2, 3) with no batch') !== false, 'no Batch column + same name twice: "Row 2 Harbour Lager appears 2 times with no batch"');
$amb = import_analyze_file($put("Item,Qty,Price\nApples,5,100\nPears,4,120\nPlums,3,130\nFigs,2,140\nDates,1,150\nVEG,0,0\nTomatoes,0,0\n", 'amb.csv'), 'amb.csv', $al);
$aq = array_values(array_filter($amb['questions'], function ($q) { return $q['type'] === 'section-or-item'; }));
$t(count($aq) === 1 && strpos($aq[0]['text'], 'Tomatoes') !== false && count($amb['sheets'][0]['categories']) === 1, 'zero-only row that could be either is ASKED (heading or out-of-stock item)');
$noHead = import_analyze_file($put("foo,bar\n1,2\n3,4\n5,6\n7,8\n9,10\n11,12\n13,14\n", 'misc.csv'), 'misc.csv', $al);
$t($noHead['sheets'][0]['triage']['action'] === 'ask' && $noHead['totals']['sheets_ask'] === 1 && $noHead['totals']['questions_blocking'] === 1, 'sheet with no recognisable headings is ASKED, not guessed');
$summ = import_analyze_file($put("Item,Qty,Price\nA,1,2\nB,2,3\n", 'summary.csv'), 'summary.csv', $al);
$t($summ['sheets'][0]['triage']['action'] === 'ask' && count($summ['sheets'][0]['rows']) === 0, 'sheet named like a summary but with item columns is asked, not silently imported');
$summF = import_analyze_file($put("Item,Qty,Price\nA,1,2\nB,2,3\n", 'summary.csv'), 'summary.csv', $al, ['force_sheets' => ['summary']]);
$t($summF['sheets'][0]['triage']['action'] === 'import' && count($summF['sheets'][0]['rows']) === 2, '...and importable once a person overrules');
$listOnly = import_analyze_file($put("Item,Qty\nApples\nPears\nPlums\n", 'names.csv'), 'names.csv', $al);
$t(count($listOnly['sheets'][0]['rows']) === 3 && count($listOnly['sheets'][0]['categories']) === 0, 'a plain list of names is a list of items, not a list of headings');
$ambDate = import_analyze_file($put("Item,Qty,Expiry\nMilk,2,04/05/2027\nEggs,3,27/03/2027\n", 'dates.csv'), 'dates.csv', $al);
$adq = array_values(array_filter($ambDate['questions'], function ($q) { return $q['type'] === 'ambiguous-date'; }));
$t(count($adq) === 1 && $adq[0]['options'][0]['key'] === 'date:2027-05-04' && $adq[0]['options'][1]['key'] === 'date:2027-04-05' && ($ambDate['sheets'][0]['rows'][1]['fields']['expiry']['value'] ?? '') === '2027-03-27', 'ambiguous 04/05/2027 asks; unambiguous 27/03/2027 converts');

// cross-file conflicts (two uploads of one batch)
$fa = import_analyze_file($put("Item,Qty,Price\nRiver Pale,3,1111\nHarbour Lager,2,3333\nSame,1,500\nSky,1,100\n", 'fileA.csv'), 'fileA.csv', $al);
$fb = import_analyze_file($put("Item,Qty,Price\nRiver Pale,5,2222\nHarbour Lager,1,4444\nSame,4,500.00002\nUnitX,2,9\n", 'fileB.csv'), 'fileB.csv', $al);
$pair = [$fa, $fb];
import_crosscheck_files($pair);
$cA = $pair[0]['conflicts']; $cB = $pair[1]['conflicts'];
$t(count($cA) === 2 && count($cB) === 2, 'two items disagree between the files (Same differs by 0.00002, inside the 0.00005 unit-price tolerance: not a conflict)');
$names = array_map(function ($c) { return $c['item']; }, $cA); sort($names);
$t($names === ['Harbour Lager', 'River Pale'], 'conflicting items: Harbour Lager, River Pale');
$cast = null; foreach ($cA as $c) { if ($c['item'] === 'River Pale') { $cast = $c; } }
$castVals = array_map(function ($v) { return (float)$v['value']; }, $cast['values']); sort($castVals);
$t($castVals === [1111.0, 2222.0] && count(array_unique(array_map(function ($v) { return $v['file']; }, $cast['values']))) === 2, 'conflict lists both prices with their file and cell');
$t($pair[0]['sheets'][0]['rows'][0]['status'] === 'needs-answer' && $pair[1]['sheets'][0]['rows'][1]['status'] === 'needs-answer' && $pair[0]['sheets'][0]['rows'][2]['status'] === 'ready', 'rows in conflict wait for an answer; the rest stay ready');

// =====================================================================================================
echo "-- file-level security --\n";
$tryFile = function (string $bytes, string $name, array $opts = []) use ($put, $al) { return import_analyze_file($put($bytes, $name), $name, $al, $opts); };
$r = $tryFile("PK\x03\x04junk", 'run.exe');
$t(!$r['ok'] && strpos($r['error'], 'cannot be imported') !== false, '.exe is refused by extension');
$r = $tryFile($fixtureBytes, 'stock.xlsx.php');
$t(!$r['ok'] && strpos($r['error'], 'cannot be imported') !== false, 'double extension ending .php is refused');
$r = $tryFile("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1 old excel", 'old.xls');
$t(!$r['ok'] && strpos($r['error'], '.xlsx') !== false, '.xls asks for a re-save as .xlsx');
$r = $tryFile("Item,Qty\nA,1\n", 'text.xlsx');
$t(!$r['ok'] && strpos($r['error'], 'not an Excel workbook') !== false, 'a text file named .xlsx is refused (wrong file type)');
$r = $tryFile($fixtureBytes, 'zip.csv');
$t(!$r['ok'] && strpos($r['error'], 'zip/Excel') !== false, 'a zip named .csv is refused');
$r = $tryFile("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1 encrypted", 'locked.xlsx');
$t(!$r['ok'] && strpos($r['error'], 'password') !== false, 'an OLE container named .xlsx (old or password-protected) is refused');
$r = $tryFile('%PDF-1.4', 'list.pdf');
$t(!$r['ok'] && strpos($r['error'], 'not supported yet') !== false, 'PDF/Word: clear "not supported yet" message');
$r = $tryFile('', 'empty.csv');
$t(!$r['ok'] && strpos($r['error'], 'empty') !== false, 'empty file is refused');
$r = $tryFile($fixtureBytes, 'big.xlsx', ['max_bytes' => 1000]);
$t(!$r['ok'] && strpos($r['error'], 'too large') !== false, 'oversize file is refused before it is parsed');
$r = import_analyze_file($tmpDir . '/does-not-exist.xlsx', 'x.xlsx', $al);
$t(!$r['ok'], 'missing file is refused');
$r = $tryFile(fx_zip(['a.xml' => ['data' => '<x/>', 'usize' => 200 * 1024 * 1024]]), 'bomb.xlsx');
$t(!$r['ok'] && strpos($r['error'], 'zip bomb') !== false, 'zip bomb named .xlsx is refused through the main entry point');
$r = $tryFile(fx_zip(['readme.txt' => 'hello']), 'notbook.xlsx');
$t(!$r['ok'] && strpos($r['error'], 'not an Excel workbook') !== false, 'zip that is not a workbook is refused');
$t(strlen(json_encode($a)) > 1000 && json_last_error() === JSON_ERROR_NONE, 'the whole analysis is JSON-serialisable (ready to stage in import_jobs)');

// =====================================================================================================
echo "-- real hotel files (local only, optional) --\n";
$realDir = __DIR__ . '/fixtures/import/real';
$realExp = is_file($realDir . '/expected.real.json') ? json_decode(file_get_contents($realDir . '/expected.real.json'), true) : null;
$real = [];
if (!$realExp) {
    echo "SKIP real files (scripts/tests/fixtures/import/real/expected.real.json not present on this machine)\n";
} else {
    // counts only are printed: the repo is public, real names and prices never reach test output
    foreach ($realExp['files'] as $fileName => $ex) {
        $path = $realDir . '/' . $fileName;
        if (!is_file($path)) { echo "SKIP real file #" . $ex['label'] . " not present\n"; continue; }
        $ra = import_analyze_file($path, $fileName, $al, ['known_names' => $realExp['known_names'] ?? []]);
        $label = 'real file #' . $ex['label'];
        $t($ra['ok'], $label . ' analyses');
        $got = [];
        foreach ($ra['sheets'] as $sh) {
            $got[$sh['index']] = $sh['triage']['action'] === 'import'
                ? ['items' => count($sh['rows']), 'sections' => $sh['counts']['sections'], 'totals' => $sh['counts']['totals']]
                : ['skip' => true];
        }
        $t($got == $ex['sheets'], $label . ' per-sheet items/sections/totals match expected.real.json');
        foreach (['items_read', 'categories', 'skipped_totals_rows', 'ignored_derived_columns'] as $k) {
            $t($ra['totals'][$k] === $ex[$k], $label . ' total ' . $k . ' = ' . $ex[$k] . ' (got ' . $ra['totals'][$k] . ')');
        }
        $sumLabelled = 0;
        foreach ($ra['sheets'] as $sh) { if ($sh['triage']['action'] === 'import') { $sumLabelled += count($sh['rows']) + count($sh['skipped_rows']); } }
        $t($sumLabelled === $ex['labelled_rows'], $label . ' every labelled row is an item, section or total (' . $sumLabelled . ' = ' . $ex['labelled_rows'] . ')');
        $types = [];
        foreach ($ra['questions'] as $q) { $types[$q['type']] = ($types[$q['type']] ?? 0) + 1; }
        foreach ($ex['question_counts'] as $type => $n) { $t(($types[$type] ?? 0) === $n, $label . ' ' . $type . ' questions = ' . $n . ' (got ' . ($types[$type] ?? 0) . ')'); }
        $t(count($ra['conflicts']) === $ex['conflicts_in_file'], $label . ' conflicts inside the file = ' . $ex['conflicts_in_file']);
        foreach ($ex['typo_pairs'] as $pair2) {
            $found = false;
            foreach ($ra['questions'] as $q) {
                if (($q['against'] ?? '') === 'existing' && strpos($q['text'], '"' . $pair2[0] . '" is not in the existing list, but "' . $pair2[1] . '" is') !== false && !$q['blocking']) { $found = true; }
            }
            $t($found, $label . ' typo #' . $pair2[2] . ' is offered as a non-blocking "did you mean" against the existing names');
        }
        $inv = import_assert_no_invented_values($ra, import_xlsx_read($path));
        $t($inv === [], $label . ' INVARIANT: no invented values (' . count($inv) . ' violations)');
        $real[] = $ra;
    }
    if (count($real) === 2) {
        import_crosscheck_files($real);
        $t(count($real[0]['conflicts']) === $realExp['cross_file']['conflicts_first'] && count($real[1]['conflicts']) === $realExp['cross_file']['conflicts_second'], 'real files: cross-file conflicts listed in both analyses (' . count($real[0]['conflicts']) . '/' . count($real[1]['conflicts']) . ')');
        $priceConf = 0;
        foreach ($real[0]['conflicts'] as $c2) {
            if ($c2['field'] === 'unit_price' && count(array_unique(array_map(function ($v) { return $v['file']; }, $c2['values']))) === 2) { $priceConf++; }
        }
        $t($priceConf === $realExp['cross_file']['price_conflicts'], 'real files: ' . $realExp['cross_file']['price_conflicts'] . ' items have different prices in the two files (got ' . $priceConf . ')');
        foreach ($realExp['cross_file']['must_conflict'] as $item => $pv) {
            $hit = false;
            foreach ($real[0]['conflicts'] as $c2) {
                $cv = array_map(function ($v) { return (float)$v['value']; }, $c2['values']); sort($cv); $ev = $pv; sort($ev);
                if ($c2['item'] === $item && $c2['field'] === 'unit_price' && $cv == $ev) { $hit = true; }
            }
            $t($hit, 'real files: the known price disagreement #' . (array_search($item, array_keys($realExp['cross_file']['must_conflict'])) + 1) . ' is listed side by side');
        }
    }
}

// ---- fix round: money rules (F1 pack follows its own cell, F2 four-decimal prices), F3 two names -> one ingredient, F4 limits ----
$fmCsv = function (array $rows) use ($put) {
    $fh = fopen('php://memory', 'w+');
    fputcsv($fh, ['Item Name', 'Batch', 'Balance', 'Unit', 'Unit Price', 'Max Level', 'Expiry Date']);
    foreach ($rows as $r) { fputcsv($fh, $r); }
    rewind($fh);
    return $put(stream_get_contents($fh), 'fix.csv');
};
$fmRun = function (array $rows, array $answers = [], array $ctx = []) use ($fmCsv, $al) {
    $an = import_analyze_file($fmCsv($rows), 'fix.csv', $al, ['known_names' => array_map(function ($e) { return $e['name']; }, (array)($ctx['existing'] ?? []))]);
    return import_resolve($an, $answers, $ctx + ['existing' => [], 'unit_options' => ['bottle', 'each'], 'corrections' => []]);
};
$fmQ = function (array $res, string $type) {
    foreach (array_merge($res['questions'], $res['extra_questions']) as $q) { if ($q['type'] === $type) { return $q; } }
    return null;
};
$fmItem = function (array $res, string $name) {
    foreach ($res['items'] as $it) { if ($it['name'] === $name) { return $it; } }
    return null;
};
// F2
$t(import_money_tolerance() === 0.00005, 'F2: the unit-price tolerance is 0.00005 (decimal(12,4) costs), not 0.01');
$t(!import_money_equal(0.004, 0.009) && import_money_equal(0.0100, 0.01) && !import_is_zero(0.008) && import_is_zero(0) && import_is_zero('0.0'), 'F2: 0.004 and 0.009 are different prices; 0.008 is not zero; only exactly 0 is zero');
$t(import_fmt_money(0.008) === '0.008' && import_fmt_money(5000) === '5,000' && import_fmt_money(12.5) === '12.50' && import_fmt_money(416.6667) === '416.6667' && import_fmt_money(0.0045) === '0.0045', 'F2: price labels show up to 4 decimals (0.008, 12.50, 416.6667), whole amounts none');
$fm = $fmRun([['Pepper', '', '5', 'each', '0.008', '', ''], ['Zero', '', '5', 'each', '0', '', '']]);
$pep = $fmItem($fm, 'Pepper');
$zer = $fmItem($fm, 'Zero');
$t($pep && abs($pep['cost'] - 0.008) < 0.0000001, 'F2: a price of 0.008 is kept as a cost of 0.008 (not read as "no price")');
$t($zer && $zer['cost'] === null, 'F2: a price of exactly 0 is still "no price"');
$fm = $fmRun([['Twin', 'B1', '3', 'each', '0.004', '', ''], ['Twin', 'B2', '4', 'each', '0.009', '', '']]);
$t($fmQ($fm, 'merge-conflict') !== null || $fmQ($fm, 'conflict') !== null, 'F2: 0.004 and 0.009 on two batches are a price disagreement, not merged');
// F1
$peers = [];
foreach (['Aaa', 'Bbb', 'Ccc', 'Ddd', 'Eee'] as $pn) { $peers[] = ['Zq ' . $pn, '', '1', 'bottle', '5000', '', '']; }
$rowsF1 = array_merge($peers, [['Qwerty', 'B1', '2', 'bottle', '60,000', '', ''], ['Qwerty', 'B2', '3', 'bottle', '5000', '', '']]);
$fm = $fmRun($rowsF1);
$cpq = $fmQ($fm, 'case-price');
$t($cpq !== null && strpos($cpq['text'], '60,000') !== false, 'F1: the 60,000 row is asked "per unit or per case?"');
$ans1 = $cpq ? [$cpq['qkey'] => ['key' => 'per-case', 'pack_size' => 12]] : [];
$fm = $fmRun($rowsF1, $ans1);
$mq = $fmQ($fm, 'merge-conflict');
$labels = $mq ? array_column($mq['options'], 'label') : [];
$t($mq !== null && in_array('Use 60,000 per case of 12 = 5,000 each', $labels, true) && in_array('Use 5,000', $labels, true), 'F1: the price question shows the converted value: "60,000 per case of 12 = 5,000 each"', json_encode($labels));
$optPlain = null; $optCase = null;
foreach (($mq['options'] ?? []) as $o) { if ($o['key'] === 'use:5,000') { $optPlain = $o['key']; } elseif (strpos($o['key'], 'per case of 12') !== false) { $optCase = $o['key']; } }
$fmA = $fmRun($rowsF1, $ans1 + [$mq['qkey'] => ['key' => (string)$optPlain]]);
$q1 = $fmItem($fmA, 'Qwerty');
$t($q1 && abs($q1['cost'] - 5000) < 0.00005 && $q1['pack'] === null && $q1['status'] === 'ready', 'F1: choosing the 5,000 price does NOT divide the case pack into it (cost stays 5,000, not 416.67)');
$t($q1 && count(array_filter($q1['warnings'], function ($w) { return strpos($w, 'not applied') !== false; })) === 1, 'F1: the ignored pack size is explained in a warning');
$fmB = $fmRun($rowsF1, $ans1 + [$mq['qkey'] => ['key' => (string)$optCase]]);
$q2 = $fmItem($fmB, 'Qwerty');
$t($q2 && abs($q2['cost'] - 5000) < 0.00005 && $q2['pack'] === 12, 'F1: choosing the 60,000 case price applies the pack: cost 5,000 each with pack 12');
// F3
$exRow = function ($id, $name) { return ['id' => $id, 'name' => $name, 'category' => 'Pantry', 'unit' => 'bottle', 'current_quantity' => 1, 'cost_per_unit' => 650, 'par_level' => 5, 'reorder_point' => 2, 'notes' => '', 'pack_name' => null, 'pack_size' => null, 'is_archived' => 0, 'recipes' => 0]; };
$ctxF3 = ['existing' => [$exRow(7, 'Heineken')]];
$rowsF3 = [['Heinekin', '', '4', 'bottle', '660', '', ''], ['Heinken', '', '6', 'bottle', '660', '', '']];
$fm = $fmRun($rowsF3, [], $ctxF3);
$typos = [];
foreach ($fm['questions'] as $q) { if ($q['type'] === 'possible-typo' && ($q['against'] ?? '') === 'existing') { $typos[] = $q; } }
$aF3 = [];
foreach ($typos as $q) { $aF3[$q['qkey']] = ['key' => 'use:Heineken']; }
$t(count($typos) === 2, 'F3 setup: both typed names get a "did you mean Heineken?" suggestion');
$fm = $fmRun($rowsF3, $aF3, $ctxF3);
$sq = [];
foreach ($fm['extra_questions'] as $q) { if ($q['type'] === 'same-existing-match') { $sq[] = $q; } }
$t(count($sq) === 2 && !$fm['can_confirm'] && $fm['counts']['blocked'] > 0, 'F3: two names matching one existing ingredient are blocked at review (not at commit)');
$aF3b = $aF3 + [($sq[0]['qkey'] ?? 'x') => ['key' => 'skip']];
$fm = $fmRun($rowsF3, $aF3b, $ctxF3);
$t($fm['can_confirm'] && $fm['committable_items'] === 1 && $fm['counts']['skipped'] === 1, 'F3: skipping one of them clears the block (one item left to update)', json_encode($fm['counts']));
// F4
$fm = $fmRun([[str_repeat('N', 101), '', '1', 'bottle', '500', '', ''], ['Okay Item', '', '1', 'bottle', '500', '', '']]);
$tl = $fmQ($fm, 'value-too-long');
$t($tl !== null && !$fm['can_confirm'] && !empty($tl['bad']) && strpos($tl['text'], '100 characters') !== false, 'F4: a name over 100 characters is a plain blocking reason (the manual Add limit)');
$fm = $fmRun([["Spaced   Out\x07 Name", '', '1', 'bottle', '500', '', '']]);
$t(isset($fm['items'][0]) && $fm['items'][0]['name'] === 'Spaced Out Name' && $fm['can_confirm'], 'F4: names are cleaned with rh_clean_text (spaces collapsed, control characters removed)');
$t(import_limit_problems(['match' => null, 'name' => 'x', 'unit' => str_repeat('u', 21), 'category' => 'c', 'ticked' => []]) === ['the unit is longer than 20 characters']
    && import_limit_problems(['match' => null, 'name' => 'x', 'unit' => 'kg', 'category' => str_repeat('c', 51), 'ticked' => []]) === ['the category is longer than 50 characters']
    && import_limit_problems(['match' => ['id' => 1], 'name' => str_repeat('x', 300), 'unit' => 'kg', 'category' => str_repeat('c', 90), 'ticked' => []]) === [], 'F4: unit 20 and category 50 limits; an existing item\'s own name/unit are not re-checked');

// ---- price plausibility (data-driven, no fixed ratio) + the physical storage limit ----
$pcRow = function ($id, $name, $cost, array $o = []) use ($exRow) { return array_merge($exRow($id, $name), ['cost_per_unit' => $cost, 'category' => 'Cat'], $o); };
$pcRun = function (array $rows, array $existing, array $ev = [], array $answers = [], array $more = [], bool $tickCost = true) use ($fmRun) {
    // the file's cost is only judged when it will be applied, so these scenarios tick "cost" on the existing items unless told not to
    if ($tickCost && !isset($answers['_updates'])) {
        $answers['_updates'] = [rh_ssi_key('Zeta') => ['cost'], rh_ssi_key('Qwerty') => ['cost'], rh_ssi_key('Hugeone') => ['cost']];
    }
    return $fmRun($rows, $answers, ['existing' => $existing] + ($ev ? ['price_evidence' => $ev] : []) + $more);
};
$pcQ = function (array $res) { foreach ($res['extra_questions'] as $q) { if ($q['type'] === 'price-check') { return $q; } } return null; };
$tight = ['history' => [7 => [1000, 1020, 980, 1010]]];
$wide = ['history' => [7 => [500, 1500, 800, 2000]]];
$r1 = $pcRun([['Zeta', '', '5', 'bottle', '1500', '', '']], [$pcRow(7, 'Zeta', 1000)], $tight);
$q1 = $pcQ($r1);
$t($q1 !== null && $q1['evidence']['basis'] === 'own-history' && $q1['evidence']['history_n'] === 4 && !$r1['can_confirm'], 'price check: an item with 4 tight history points flags a 1.5x jump (own spread is the yardstick)');
$r2 = $pcRun([['Zeta', '', '5', 'bottle', '1500', '', '']], [$pcRow(7, 'Zeta', 1000)], $wide);
$t($pcQ($r2) === null && $r2['can_confirm'], 'price check: an item whose own prices vary widely does NOT flag the same 1.5x jump');
$r3 = $pcRun([['Zeta', '', '5', 'bottle', '1500', '', '']], [$pcRow(7, 'Zeta', 1000)]);
$t($pcQ($r3) === null && $r3['can_confirm'], 'price check: no history and no hotel evidence = no question (1.5x, and the same with 7x)');
$r3b = $pcRun([['Zeta', '', '5', 'bottle', '7000', '', '']], [$pcRow(7, 'Zeta', 1000)]);
$t($pcQ($r3b) === null, 'price check: a 7x move with no evidence at all and no structural pattern is not asked about');
$stable = ['hotel_move' => ['v' => 0.02, 'n' => 12]];
$inflated = ['hotel_move' => ['v' => 0.30, 'n' => 12]];
$r4 = $pcRun([['Zeta', '', '5', 'bottle', '1600', '', '']], [$pcRow(7, 'Zeta', 1000)], $stable);
$r5 = $pcRun([['Zeta', '', '5', 'bottle', '1600', '', '']], [$pcRow(7, 'Zeta', 1000)], $inflated);
$t($pcQ($r4) !== null && $pcQ($r4)['evidence']['basis'] === 'hotel-moves' && $pcQ($r5) === null, 'price check: a hotel whose prices usually move a lot tolerates a 1.6x move that a stable hotel is asked about');
$r6 = $pcRun([['Zeta', '', '5', 'bottle', '1600', '', '']], [$pcRow(7, 'Zeta', 1000)], $inflated + ['category_move' => ['cat' => ['v' => 0.02, 'n' => 9]]]);
$t($pcQ($r6) !== null && $pcQ($r6)['evidence']['basis'] === 'category-moves', 'price check: similar items (same category) come before the whole hotel as the yardstick');
$mv = import_pc_typical_moves([1 => [100, 110, 121], 2 => [50, 50, 55], 3 => [10]], [1 => 'A', 2 => 'A', 3 => 'B'], import_pc_params());
$t(count($mv['category']) === 1 && $mv['category']['a']['n'] === 3 && $mv['category']['a']['v'] === null && $mv['hotel']['n'] === 3, 'price check: successive moves are counted per category; fewer than 5 moves is not enough evidence (v = null)');
// structural reasons
$r7 = $pcRun([['Zeta', '', '5', 'bottle', '28,750', '', '']], [$pcRow(7, 'Zeta', 2875)]);
$q7 = $pcQ($r7);
$t($q7 !== null && $q7['evidence']['reason'] === 'extra-zero' && strpos($q7['text'], '"Zeta" costs 2,875 now; the file says 28,750') !== false && strpos($q7['text'], '10 times higher, which often means an extra zero') !== false
    && strpos($q7['text'], 'Is 28,750 right?') !== false && strpos($q7['text'], 'VAT not added or removed') !== false, 'price check: x10 with no history is still asked, in plain words ("an extra zero")', (string)($q7['text'] ?? ''));
$t($q7 && array_column($q7['options'], 'key') === ['file', 'current', 'skip'] && !$q7['bad'] && $q7['blocking'], 'price check: an existing item is offered keep the file price / keep the current price / skip, and it blocks Confirm');
$r8 = $pcRun([['Zeta', '', '5', 'bottle', '287.5', '', '']], [$pcRow(7, 'Zeta', 2875)]);
$t(($pcQ($r8)['evidence']['reason'] ?? '') === 'missing-zero', 'price check: x0.1 is read as a missing zero');
$r9 = $pcRun([['Zeta', '', '5', 'bottle', '12,000', '', '']], [$pcRow(7, 'Zeta', 500, ['pack_name' => 'crate', 'pack_size' => 24])]);
// 2026-11-17: 12,000 = its own pack of 24 x the cost in stock is decisive: the case question is answered "per case of 24" for the person,
// so the cost is 500 and the price check has nothing to ask. Answering "per unit" brings the old pack-size-up price question back.
$cq9 = null;
foreach ($r9['questions'] as $q) { if ($q['type'] === 'case-price') { $cq9 = $q; } }
$it9 = $r9['items'][0] ?? [];
$t($cq9 && $cq9['answer'] === 'per-case' && (int)$cq9['answer_detail']['pack_size'] === 24 && !empty($cq9['auto']) && strpos((string)$cq9['auto_reason'], 'pack of 24 times the cost in stock now') !== false
    && abs(($it9['cost'] ?? 0) - 500) < 0.00005 && $pcQ($r9) === null, 'case evidence: x24 on an item with its own pack of 24 is pre-answered "per case of 24" (cost 500, no price question)', json_encode($cq9['auto_reason'] ?? null));
$r9 = $pcRun([['Zeta', '', '5', 'bottle', '12,000', '', '']], [$pcRow(7, 'Zeta', 500, ['pack_name' => 'crate', 'pack_size' => 24])], [], ['_updates' => [rh_ssi_key('Zeta') => ['cost']], $cq9['qkey'] => ['key' => 'per-unit']]);
$q9 = $pcQ($r9);
$t($q9 && $q9['evidence']['reason'] === 'pack-size-up' && (int)$q9['evidence']['pack_size'] === 24 && strpos($q9['text'], 'case price typed as a unit price') !== false, 'price check: x24 on an item with a 24 pack is flagged as a case price typed as a unit price');
$r10 = $pcRun([['Zeta', '', '5', 'bottle', '6,000', '', '']], [$pcRow(7, 'Zeta', 500), $pcRow(8, 'Other', 900, ['pack_name' => 'crate', 'pack_size' => 12])]);
$t(($pcQ($r10)['evidence']['reason'] ?? '') === 'pack-size-up', 'price check: a whole pack count that this hotel uses (12) is recognised even when the item has no pack size');
$r11 = $pcRun([['Zeta', '', '12', 'bottle', '12', '', '']], [$pcRow(7, 'Zeta', 100)], ['history' => [7 => [100, 101, 99, 100]]]);
$t(($pcQ($r11)['evidence']['reason'] ?? '') === 'swapped-columns' && strpos($pcQ($r11)['text'], 'columns may be swapped') !== false, 'price check: a price equal to the quantity on the row (and off from its usual price) says the columns may be swapped');
$r12 = $pcRun([['Zeta', '', '5', 'bottle', '5', '', '']], [$pcRow(7, 'Zeta', 100)]);
$t($pcQ($r12) === null, 'price check: price = quantity alone, with nothing else off, asks nothing');
$r13 = $pcRun([['Zeta', '', '5', 'bottle', '2.875', '', '']], [$pcRow(7, 'Zeta', 2875)]);
$t(($pcQ($r13)['evidence']['reason'] ?? '') === 'thousands-separator', 'price check: "2.875" against a cost of 2,875 is read as a thousands separator taken for a decimal point');
$r14 = $pcRun([['Zeta', '', '5', 'g', '5', '', '']], [$pcRow(7, 'Zeta', 5000, ['unit' => 'kg'])]);
$uc = null; foreach ($r14['extra_questions'] as $q) { if ($q['type'] === 'unit-clash') { $uc = $q; } }
$t($uc !== null && $uc['evidence']['reason'] === 'unit-differs' && strpos($uc['text'], 'about 1,000 times') !== false && strpos($uc['text'], 'g and kg') !== false && $pcQ($r14) === null, 'price check: kg vs g is explained in the unit question (and no second price question)', (string)($uc['text'] ?? ''));
// new items: only category peers are evidence
$peerRows = [];
$peerEx = [];
foreach ([1000, 1100, 900, 1050, 950] as $i => $c) { $peerEx[] = $pcRow(20 + $i, 'Peer ' . $i, $c, ['category' => 'fix']); }
$r15 = $pcRun([['Newbie', '', '5', 'bottle', '100000', '', '']], $peerEx);
$cat15 = $r15['items'][0]['category'] ?? '?';
$peerEx = [];
foreach ([1000, 1100, 900, 1050, 950] as $i => $c) { $peerEx[] = $pcRow(20 + $i, 'Peer ' . $i, $c, ['category' => $cat15]); }
$r16 = $pcRun([['Newbie', '', '5', 'bottle', '100000', '', '']], $peerEx);
$q16 = $pcQ($r16);
$t($q16 !== null && $q16['evidence']['basis'] === 'category-peers' && $q16['evidence']['peer_n'] === 5 && array_column($q16['options'], 'key') === ['file', 'skip'] && strpos($q16['text'], 'is new in stock') !== false, 'price check: a NEW item far from its category peers is asked (file / skip only)', json_encode($q16));
$r17 = $pcRun([['Newbie', '', '5', 'bottle', '1,200', '', '']], $peerEx);
$t($pcQ($r17) === null, 'price check: a new item near its peers is not asked');
$r18 = $pcRun([['Newbie', '', '5', 'bottle', '100000', '', '']], array_slice($peerEx, 0, 4));
$t($pcQ($r18) === null, 'price check: fewer than 5 peers = no evidence = no question for a new item');
// zero / blank old cost
$r19 = $pcRun([['Zeta', '', '5', 'bottle', '28,750', '', '']], [$pcRow(7, 'Zeta', 0)]);
$t($pcQ($r19) === null, 'price check: an existing item with a zero cost has nothing to compare');
// answers
$qk = $q7['qkey'];
$updCost = ['_updates' => [rh_ssi_key('Zeta') => ['cost', 'quantity']]];
$rA = $pcRun([['Zeta', '', '5', 'bottle', '28,750', '', '']], [$pcRow(7, 'Zeta', 2875)], [], [$qk => ['key' => 'file']] + $updCost);
$itA = $fmItem($rA, 'Zeta');
$t($rA['can_confirm'] && $itA['ticked'] === ['cost', 'quantity'] && abs($itA['cost'] - 28750) < 0.00005, 'price check: "the file\'s price is right" confirms it (the cost is still only applied when ticked)');
$rB = $pcRun([['Zeta', '', '5', 'bottle', '28,750', '', '']], [$pcRow(7, 'Zeta', 2875)], [], [$qk => ['key' => 'current']] + $updCost);
$itB = $fmItem($rB, 'Zeta');
$t($rB['can_confirm'] && !isset($itB['diff']['cost']) && $itB['ticked'] === ['quantity'] && $itB['in_count'] === true && abs($itB['line_cost'] - 2875) < 0.00005 && count(array_filter($itB['warnings'], function ($w) { return strpos($w, 'keep the current cost 2,875') !== false; })) === 1,
    'price check: "keep the current price" leaves the cost field out even if it was ticked, while the opening count line still imports (at the current cost)', json_encode([$itB['ticked'] ?? null, $itB['in_count'] ?? null]));
$rC = $pcRun([['Zeta', '', '5', 'bottle', '28,750', '', '']], [$pcRow(7, 'Zeta', 2875)], [], [$qk => ['key' => 'skip']]);
$t($rC['counts']['skipped'] === 1 && $rC['committable_items'] === 0, 'price check: skip drops the row');
$rD = $pcRun([['Zeta', '', '5', 'bottle', '28,750', '', '']], [$pcRow(7, 'Zeta', 2875)], [], ['abcdef' => ['key' => 'file']]);
$t(!$rD['can_confirm'] && $rD['counts']['needs-answer'] === 1, 'price check: unanswered = needs-answer and Confirm is blocked');
$kA = $pcQ($rD)['qkey'];
$rE = $pcRun([['Zeta', '', '5', 'bottle', '28,750', '', '']], [$pcRow(7, 'Zeta', 2876)], [], [$kA => ['key' => 'file']]);
$t(!$rE['can_confirm'], 'price check: an answer belongs to the exact prices compared (the stock cost changed = asked again)');
// F1/F2 kept: the converted per-unit price is compared, not the raw case price
$rowsCase = array_merge($peers, [['Qwerty', '', '2', 'bottle', '60,000', '', '']]);
$exQ = [$pcRow(7, 'Qwerty', 5000, ['pack_name' => 'crate', 'pack_size' => 12])];
$rP = $pcRun($rowsCase, $exQ);
$cpq2 = $fmQ($rP, 'case-price');
$t($cpq2 !== null && $pcQ($rP) === null, 'price check: while the per-case question is unanswered the raw case price is not compared');
$rP1 = $pcRun($rowsCase, $exQ, [], [$cpq2['qkey'] => ['key' => 'per-case', 'pack_size' => 12]]);
$itP = $fmItem($rP1, 'Qwerty');
$t($pcQ($rP1) === null && $itP && abs($itP['cost'] - 5000) < 0.00005, 'price check: per case of 12, 60,000 becomes 5,000 each and is compared as 5,000 (same as now: no question)');
$rP2 = $pcRun($rowsCase, $exQ, [], [$cpq2['qkey'] => ['key' => 'per-unit']]);
$t(($pcQ($rP2)['evidence']['reason'] ?? '') === 'pack-size-up' && abs($pcQ($rP2)['evidence']['new'] - 60000) < 0.00005, 'price check: answered "per unit", the 60,000 is compared as typed and is 12x = the pack size');
// the physical limit
$limOk = ['cost_limits' => ['cost' => 99999999.9999, 'line_value' => 9999999999.9999, 'from_database' => true]];
$rL = $fmRun([['Hugeone', '', '5', 'bottle', '123,456,789', '', ''], ['Fineone', '', '5', 'bottle', '99,999,999.9999', '', '']], [], $limOk + ['existing' => []]);
$ql = $fmQ($rL, 'cost-too-large');
$t($ql !== null && !empty($ql['bad']) && array_column($ql['options'], 'key') === ['skip'] && strpos($ql['text'], 'Hugeone') !== false && strpos($ql['text'], 'price 123,456,789 is too large for a stock cost') !== false
    && strpos($ql['text'], 'Fix the cell in your file and upload again, or skip this row') !== false && !$rL['can_confirm'] && $rL['counts']['blocked'] === 1 && $fmItem($rL, 'Fineone')['status'] === 'ready', 'storage limit: a cost that cannot be stored is a blocking question with only "skip"; the largest storable cost is fine', (string)($ql['text'] ?? ''));
$rL2 = $fmRun([['Hugeone', '', '5', 'bottle', '123,456,789', '', ''], ['Fineone', '', '5', 'bottle', '500', '', '']], [$ql['qkey'] => ['key' => 'skip']], $limOk + ['existing' => []]);
$t($rL2['can_confirm'] && $rL2['committable_items'] === 1, 'storage limit: skipping the row clears the block');
$rL3 = $fmRun([['Mid', '', '5', 'bottle', '2,000,000', '', '']], [], ['cost_limits' => ['cost' => 1000000.0]] + ['existing' => []]);
$t($fmQ($rL3, 'cost-too-large') !== null, 'storage limit: the limit comes from the column definition passed in (here 1,000,000), not from a number in the engine');
$rL4 = $fmRun([['Mid', '', '5', 'bottle', '99,999,999', '', '']], [], ['existing' => []]);
$rL5 = $fmRun([['Mid', '', '5', 'bottle', '100,000,000', '', '']], [], ['existing' => []]);
$t($fmQ($rL4, 'cost-too-large') === null && $fmQ($rL5, 'cost-too-large') !== null, 'storage limit: with no readable column the stock-count rule (99,999,999) is the fallback');
$rL6 = $fmRun([['Heavy', '', '10', 'bottle', '200,000', '', '']], [], ['cost_limits' => ['cost' => 99999999.9999, 'line_value' => 1000000.0]] + ['existing' => []]);
$ql6 = $fmQ($rL6, 'cost-too-large');
$t($ql6 !== null && strpos($ql6['text'], 'opening stock value') !== false, 'storage limit: balance x cost too large for the opening-count line column is blocked too');
$lim = import_cost_limits_from_ctx($limOk);
$t(import_cost_storage_problems(['cost' => 123456789, 'pack' => null], $lim) !== [] && import_cost_storage_problems(['cost' => 99999999.9999], $lim) === [] && import_cost_storage_problems(['cost' => null], $lim) === [], 'storage limit: the helper commit re-runs flags only what cannot be stored');
$t(abs(import_decimal_max(12, 4) - 99999999.9999) < 0.00001 && abs(import_decimal_max(14, 4) - 9999999999.9999) < 0.0001, 'storage limit: DECIMAL(12,4) holds 99,999,999.9999; DECIMAL(14,4) holds 9,999,999,999.9999');

// ---- accounting gate round: unchanged price, blank cost, only-when-applied, neutral pack wording, no fixed ratio in the file-peer check ----
$ev0 = import_pc_assess(['new' => 1000.00002, 'old' => 1000, 'history' => [1000, 1020, 980, 1010]], import_pc_params());
$t($ev0 === null && import_pc_assess(['new' => 1000, 'old' => 1000, 'raw' => '1000'], import_pc_params()) === null, 'gate F1: a price the same as the current cost never raises a question');
$rU = $pcRun([['Zeta', '', '5', 'bottle', '28,750', '', '']], [$pcRow(7, 'Zeta', 2875)], [], [], [], false);
$t($pcQ($rU) === null && $rU['can_confirm'], 'gate F2: with the cost NOT ticked (nothing will be written) no price question is asked');
$rU2 = $pcRun([['Zeta', '', '5', 'bottle', '28,750', '', '']], [$pcRow(7, 'Zeta', 2875)], [], ['_updates' => [rh_ssi_key('Zeta') => ['cost']]]);
$t($pcQ($rU2) !== null && !$rU2['can_confirm'], 'gate F2: ticking the cost makes the question appear (recomputed)');
$rU3 = $pcRun([['Zeta', '', '5', 'bottle', '28,750', '', '']], [$pcRow(7, 'Zeta', 2875)], [], ['_updates' => [rh_ssi_key('Zeta') => ['quantity']]]);
$t($pcQ($rU3) === null && $rU3['can_confirm'], 'gate F2: ticking only the quantity asks nothing about the price');
$limSmall = ['cost_limits' => ['cost' => 1000000.0]];
$rS1 = $pcRun([['Hugeone', '', '5', 'bottle', '2,000,000', '', '']], [$pcRow(9, 'Hugeone', 500)], [], [], $limSmall, false);
$rS2 = $pcRun([['Hugeone', '', '5', 'bottle', '2,000,000', '', '']], [$pcRow(9, 'Hugeone', 500)], [], [], $limSmall, true);
$t($fmQ($rS1, 'cost-too-large') === null && $fmItem($rS1, 'Hugeone')['cost_applied'] === false && $fmQ($rS2, 'cost-too-large') !== null && $fmItem($rS2, 'Hugeone')['cost_applied'] === true,
    'gate F6: a too-large cost is only blocked when it will be written (new item, or existing with the cost ticked)');
$rS3 = $fmRun([['Hugeone', '', '5', 'bottle', '2,000,000', '', '']], [], ['existing' => [$pcRow(9, 'Hugeone', 500)]] + $limSmall);
$t(import_cost_storage_problems(['cost' => 2000000, 'cost_applied' => false], import_cost_limits_from_ctx($limSmall)) === [] && import_cost_storage_problems(['cost' => 2000000, 'cost_applied' => true], import_cost_limits_from_ctx($limSmall)) !== [] && $fmQ($rS3, 'cost-too-large') === null,
    'gate F6: the commit-time helper ignores a cost that will not be written');
// F3: an existing item with a blank cost is judged like a new one (against similar items in stock)
$peerEx2 = [];
foreach ([1000, 1100, 900, 1050, 950] as $i => $c) { $peerEx2[] = $pcRow(30 + $i, 'Peer ' . $i, $c, ['category' => $cat15]); }
$rZ = $pcRun([['Blankie', '', '5', 'bottle', '100000', '', '']], array_merge($peerEx2, [$pcRow(41, 'Blankie', 0, ['category' => $cat15])]), [], ['_updates' => [rh_ssi_key('Blankie') => ['cost']]], [], false);
$qZ = $pcQ($rZ);
$t($qZ !== null && $qZ['evidence']['basis'] === 'category-peers' && array_column($qZ['options'], 'key') === ['file', 'skip'] && strpos($qZ['text'], 'has no cost in stock yet') !== false, 'gate F3: an existing item with a blank cost uses similar items as evidence (file / skip only)', (string)($qZ['text'] ?? ''));
// F5: a pack size is named only when the item has its own
$t(strpos($q9['text'], '24 units in its pack') !== false, 'gate F5: the item\'s own pack size is named');
$q10 = $pcQ($r10);
$t($q10 && $q10['evidence']['pack_is_own'] === false && strpos($q10['text'], 'units in its pack') === false && strpos($q10['text'], 'may be a case price or a typing slip') !== false, 'gate F5: another item\'s pack count is not named; neutral wording', (string)($q10['text'] ?? ''));
// F4: the file-peer ("case price?") check has no fixed ratio either: it follows the peers' own spread
$wideRows = [['A1', '', '1', 'bottle', '100', '', ''], ['A2', '', '1', 'bottle', '200', '', ''], ['A3', '', '1', 'bottle', '400', '', ''], ['A4', '', '1', 'bottle', '800', '', ''], ['A5', '', '1', 'bottle', '1600', '', ''], ['A6', '', '1', 'bottle', '3200', '', '']];
$fw = $fmRun($wideRows);
$t($fmQ($fw, 'case-price') === null, 'gate F4: in a sheet whose prices vary widely (8x the median) a high price is not called a case price');
$tightRows = [];
foreach (['B1', 'B2', 'B3', 'B4', 'B5'] as $i => $nm) { $tightRows[] = [$nm, '', '1', 'bottle', (string)(5000 + $i * 20), '', '']; }
$tightRows[] = ['Bcase', '', '1', 'bottle', '60,000', '', ''];
$ft = $fmRun($tightRows);
$cq = $fmQ($ft, 'case-price');
$t($cq !== null && array_column($cq['options'], 'key') === ['per-unit', 'per-case'] && strpos($cq['text'], 'Is that the price of one unit, or of a whole case/pack?') !== false, 'gate F4: among tightly priced peers the 60,000 is still asked, with the same wording and options');
$t(strpos((string)file_get_contents($root . '/includes/import/crosscheck.php'), 'case_price_ratio') === false, 'gate F4: no fixed case-price ratio is left in the cross-checker');

// Step 3 on the server (review-view.php): pure helpers, no database
require_once $root . '/includes/import/review-view.php';
$rp = import_rv_params(['status' => 'ready,bogus', 'sort' => 'x;DROP', 'size' => '999', 'type' => 'nope', 'kind' => 'matched', 'warn' => 'yes', 'q' => "  a\x00b  ", 'page' => '0', 'view' => 'cat']);
$t($rp['status'] === ['ready'] && $rp['sort'] === 'order' && $rp['size'] === 50 && $rp['type'] === '' && $rp['kind'] === 'matched' && $rp['warn'] === 1 && $rp['q'] === 'a b' && $rp['page'] === 1 && $rp['view'] === 'cat', 'review-view: request values are validated against allow-lists');
$t(import_rv_params([])['status'] === null && import_rv_params(['status' => 'all'])['status'] === [] && import_rv_resolve_status(import_rv_params([]), ['needs-answer' => 3])['status'] === ['needs-answer', 'blocked'] && import_rv_resolve_status(import_rv_params([]), ['needs-answer' => 0, 'blocked' => 0])['status'] === [], 'review-view: no status = "needs answer" while anything remains, else everything');
$e1 = ['hay' => mb_strtolower("zzt lager 00012\nbeers\n🍺 bar stock"), 'refs' => ['🍺 bar stock!c12', '🍺 bar stock!a5']];
$t(import_rv_search_match($e1, import_rv_compile_search('BAR!C12')) && !import_rv_search_match($e1, import_rv_compile_search('BAR!C13')) && import_rv_search_match($e1, import_rv_compile_search('c12')) && !import_rv_search_match($e1, import_rv_compile_search('c1')) && import_rv_search_match($e1, import_rv_compile_search('lager 12')) && import_rv_search_match($e1, import_rv_compile_search('lager 0001')), 'review-view: search matches words, and a cell like BAR!C12 (emoji sheet name ignored, cell exact)');
$rq = ['options' => [['key' => 'keep'], ['key' => 'use:Heineken'], ['key' => 'use:Other'], ['key' => 'per-case'], ['key' => 'skip']], 'suggestion' => ['name' => 'Heineken']];
$t(import_rv_roles($rq) === ['keep' => 'keep', 'use-suggestion' => 'use:Heineken', 'skip' => 'skip'], 'review-view: bulk roles - the suggestion becomes a role, other names and "per case" never do');
$t(!in_array('same-existing-match', import_rv_bulk_types(), true) && !in_array('no-unit', import_rv_bulk_types(), true) && !in_array('mapping-unsure', import_rv_bulk_types(), true) && !in_array('sheet-unknown', import_rv_bulk_types(), true), 'review-view: sheet, column and "same existing match" questions are never bulk-answerable');
$t(import_rv_scope(['row_uids' => [], 'row' => null, 'type' => 'sheet-unknown']) === 'structural' && import_rv_scope(['row_uids' => ['a'], 'row' => null, 'type' => 'no-unit']) === 'sheet' && import_rv_scope(['row_uids' => ['a'], 'row' => 4, 'type' => 'possible-typo']) === 'entry', 'review-view: questions are sorted into structural / whole-sheet / per-item');
// Speed without changing a single answer: the analysis of a big sheet used to be quadratic (the "case price" peers were rebuilt and sorted for every
// row; every cell re-encoded the whole alias vocabulary and ran an edit distance against every alias). The output is frozen from the code before the change.
mt_srand(5);
$looOk = true;
for ($rep = 0; $rep < 300 && $looOk; $rep++) {
    $n = mt_rand(2, 40);
    $prices = [];
    for ($z = 0; $z < $n; $z++) { $prices[] = mt_rand(0, 3) ? (float)mt_rand(1, 12) * 100 : round(mt_rand(1, 99999) / 7, 4); }     // plenty of ties
    $g = import_cc_group($prices);
    foreach ($prices as $ix => $pr) {
        $peers = $prices; unset($peers[$ix]);
        $direct = [import_median($peers), import_pc_spread(array_map('log', import_pc_positive($peers)))];
        $fast = import_cc_loo_stats($g, (float)$pr);
        if ($direct[0] !== $fast[0] || $direct[1] !== $fast[1]) { $looOk = false; break; }
    }
}
$t($looOk, 'speed: median / spread of a row\'s peers read from one sorted group equal the direct computation exactly (300 random groups with ties)');
$simOk = true;
for ($z = 0; $z < 2000 && $simOk; $z++) {
    $a = substr(md5((string)mt_rand()), 0, mt_rand(4, 24)); $b = substr(md5((string)mt_rand()), 0, mt_rand(4, 24));
    if (abs(strlen($a) - strlen($b)) * 5 > max(strlen($a), strlen($b)) && import_similarity($a, $b) >= 0.8) { $simOk = false; }
}
$t($simOk, 'speed: a length difference of more than a fifth always means a similarity under 0.8 (the header matcher skips those)');
// md5s re-frozen 2026-11-16 (flag-quality round): only the possible-typo / case-price question texts and option labels changed; with every question text
// and option label blanked the old and new engines give the same output on both inputs (checked against the previous commit's engine).
$tmpAnal = $put('', 'frozen.csv');
$fhz = fopen($tmpAnal, 'w'); fputcsv($fhz, ['Item Name', 'Batch', 'Balance', 'Unit', 'Unit Price', 'Max Level', 'Expiry Date']);
$wordsZ = ['Lager', 'Stout', 'Cider', 'Vodka', 'Rum', 'Flour', 'Sugar', 'Rice', 'Beans', 'Salt', 'Oil', 'Soap', 'Bleach', 'Mop', 'Paper', 'Pen'];
$catsZ = 100; $perZ = 50; $iz = 0; mt_srand(7);
for ($cz = 1; $cz <= $catsZ; $cz++) {
    fputcsv($fhz, ['ZZT CATEGORY ' . sprintf('%04d', $cz), '', '', '', '', '', '']);
    for ($kz = 0; $kz < $perZ && $iz < 5000; $kz++, $iz++) {
        $nm = 'ZZT ' . $wordsZ[$iz % 16] . ' ' . sprintf('%05d', $iz);
        if ($iz % 40 == 39) { $nm = 'ZZT ' . $wordsZ[($iz - 1) % 16] . ' ' . sprintf('%05d', $iz - 1) . 'x'; }
        $pz = 1000 + ($iz % 50) * 10; if ($iz % 23 == 0) { $pz *= 40; }
        if ($iz % 211 == 0 && $iz > 0) { $nm = 'ZZT ' . $wordsZ[($iz - 1) % 16] . ' ' . sprintf('%05d', $iz - 1); }
        fputcsv($fhz, [$nm, '', mt_rand(0, 50), ['bottle', 'kg', 'each', 'L'][$iz % 4], $pz, mt_rand(10, 100), '']);
    }
}
fclose($fhz);
$tz = microtime(true);
$anz = import_analyze_file($tmpAnal, 'g5k.csv', $al, ['max_bytes' => 50 << 20]);
$secz = microtime(true) - $tz;
$t(md5(json_encode($anz)) === '71ff50179a34b36e459b152a9a706d89', 'speed: a generated 5,000-item sheet analyses to byte-identical output (json md5 frozen from the code before the speed-up)');
$t($secz < 15, 'speed: 5,000 items analyse in well under 15 s (' . round($secz, 1) . ' s; it was 4-6 s before, 20,000 items 73 s, now about 11 s)');
$anx = import_analyze_file($root . '/scripts/tests/fixtures/import/synthetic_stock.xlsx', 'synthetic_stock.xlsx', $al, ['max_bytes' => 50 << 20]);
$anxNoHeal = $anx; unset($anxNoHeal['heals'], $anxNoHeal['heal_totals']);
$t(md5(json_encode($anxNoHeal)) === '18c6166fd11012e6a56bbdd0ef8e77a6', 'speed: the synthetic workbook analyses to byte-identical output apart from the new "Fixed for you" list (json md5 frozen from the code before the speed-up; the repair key is the only difference, see the next check)');
$t(count($anx['heals'] ?? []) === 1 && $anx['heals'][0]['rule'] === 'number-format' && $anx['heals'][0]['before'] === '1,800' && $anx['heals'][0]['after'] === '1800' && strpos($anx['heals'][0]['source'], 'I18') !== false && $anx['heal_totals'] === ['number-format' => 1], 'repairs: the one text number with a thousands separator in the synthetic workbook (BAR!I18 "1,800") is now listed as a repair; no other repair fires and nothing else in the output moved');
// Memory: a file that cannot fit ends with a plain message, never a fatal error; a normal file works under a small limit. Each case is a child
// process with its own memory_limit (a fatal error would leave no result line at all).
require_once $root . '/includes/import/memory.php';
$runMem = function (string $limit, string $mode, string $csvPath, bool $noIniSet = false) use ($root) {
    $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . $limit . ($noIniSet ? ' -d disable_functions=ini_set' : '') . ' ' . escapeshellarg($root . '/scripts/tests/import_memory_child.php') . ' ' . $mode . ' ' . escapeshellarg($csvPath) . ' 2>&1';
    $out = (string)shell_exec($cmd);
    foreach (preg_split('/\R/', $out) as $line) {
        if ($line !== '' && $line[0] === '{') { $j = json_decode($line, true); if (is_array($j)) { return $j + ['fatal' => false]; } }
    }
    return ['fatal' => true, 'raw' => substr($out, -300)];
};
$memCsv = function (int $rows) use ($put, $wordsZ) {
    $fh = fopen($put('', 'mem' . $rows . '.csv'), 'w');
    $path = stream_get_meta_data($fh)['uri'];
    fwrite($fh, import_mem_sample_csv($rows));
    fclose($fh);
    return $path;
};
$big = $memCsv(20000);
$r64 = $runMem('64M', 'run', $big, true);
$t(empty($r64['fatal']) && $r64['ok'] === false && preg_match('/^This file has 20,\d{3} rows/', $r64['message']) && strpos($r64['message'], 'too many to read in one go on this server') !== false && preg_match('/files of about [\d,]+ rows \(by sheet or category\)/', $r64['message']) && !isset($r64['items']), 'memory: 20,000 rows under a 64 MB limit (ini_set not allowed) = the plain "split it into files of about N rows" message, no fatal error: ' . json_encode($r64['message'] ?? $r64['raw'] ?? ''));
$r64b = $runMem('64M', 'run', $big, false);
$t(empty($r64b['fatal']) && $r64b['ok'] === true && ($r64b['items'] ?? 0) > 19000 && $r64b['limit_after'] !== '64M', 'memory: where the host allows ini_set the limit is raised to what the file needs and the whole upload + review work runs (' . ($r64b['limit_after'] ?? '?') . ', peak ' . ($r64b['peak_real_mb'] ?? '?') . ' MB real)');
$r128 = $runMem('128M', 'run', $memCsv(3000), true);
$t(empty($r128['fatal']) && $r128['ok'] === true && ($r128['items'] ?? 0) >= 2900 && ($r128['entries'] ?? 0) >= 2900, 'memory: a normal 3,000-row file still analyses, resolves and builds the review list under a 128 MB limit (peak ' . ($r128['peak_real_mb'] ?? '?') . ' MB real)');
$r128b = $runMem('128M', 'run', $memCsv(5000), true);
$t(empty($r128b['fatal']) && $r128b['ok'] === true && ($r128b['items'] ?? 0) >= 4900, 'memory: 5,000 rows also fit under 128 MB (peak ' . ($r128b['peak_real_mb'] ?? '?') . ' MB real)');
$cal = import_mem_calibration($al);
$t(!empty($cal['measured']) && $cal['cell_up'] > 100 && $cal['cell_review'] > 100 && $cal['sec_cell'] > 0 && $cal['real_factor'] >= 1.3, 'memory: the per-cell cost comes from a measured run of the real engine on a generated sample (' . round($cal['cell_up']) . ' / ' . round($cal['cell_review']) . ' bytes per cell)');
$chkOk = import_mem_check($memCsv(20000), 'x.csv', $al, ['limit' => 4 * 1073741824]);
$chkSmall = import_mem_check($memCsv(200), 'x.csv', $al, ['limit' => 1048576]);
$chkTime = import_mem_check($memCsv(20000), 'x.csv', $al, ['limit' => 4 * 1073741824, 'time_limit' => 5]);
$t($chkOk['ok'] && $chkSmall['ok'] && !$chkTime['ok'] && strpos($chkTime['message'], 'too many to read in one go') !== false, 'memory: plenty of memory = fine, a tiny file always fits, and a time limit that is too short is refused the same plain way');
$rv = import_mem_check_review(strlen(json_encode(['x' => str_repeat('a', 3000000)])), $al, ['limit' => 8 * 1048576]);
$t(!$rv['ok'] && strpos($rv['message'], 'too big for this server to open') !== false, 'memory: opening a stored job that cannot fit gives a plain message too');
$jsonSame = true;
foreach ([$anz, $anx] as $anCheck) { $jsonSame = $jsonSame && import_json_pieces($anCheck) === json_encode($anCheck, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); }
$t($jsonSame, 'memory: the analysis is stored with the piece-by-piece writer and the text is identical to one json_encode');
// the slimmer resolve (rows released as they are read) gives the same answers as the plain one
$stIn = $anz;
$resA = import_resolve($anz, [], ['existing' => [], 'unit_options' => ['each'], 'corrections' => []]);
$resB = import_resolve_inplace($stIn, [], ['existing' => [], 'unit_options' => ['each'], 'corrections' => []], true);
$t(json_encode($resA) === json_encode($resB), 'memory: resolving with the rows released as they are read gives byte-identical results');

// =====================================================================================================
echo "-- Import smarter: inference, self-healing, leaving rows out --\n";
require_once $root . '/includes/import/infer.php';
$csvRows = function (array $rows, string $name = 'x.csv') use ($put) {
    $p = $put('', $name);
    $fh = fopen($p, 'w');
    foreach ($rows as $r) { fputcsv($fh, $r); }
    fclose($fh);
    return $p;
};
$ctxBase = ['existing' => [], 'unit_options' => ['bottle', 'box', 'crate', 'kg', 'L', 'pcs', 'g', 'dozen'], 'corrections' => []];
$syn = $al['unit_synonyms'];

// ---- unit spelling (pure) ----
$t(import_unit_canon('btl', $syn) === 'bottle' && import_unit_canon('Bottles', $syn) === 'bottle' && import_unit_canon('LTR', $syn) === 'L' && import_unit_canon('kgs', $syn) === 'kg' && import_unit_canon('pc', $syn) === 'pcs' && import_unit_canon('widgets', $syn) === 'widgets', 'unit spelling: btl / Bottles / LTR / kgs / pc become the unit stock uses; an unknown word is left exactly as written');
$t(import_unit_canon('kg', $syn) === 'kg' && import_unit_canon('g', $syn) === 'g' && import_unit_canon('ml', $syn) === 'ml', 'unit spelling: a spelling fix is never a conversion (kg stays kg, g stays g, ml stays ml)');
$t(import_units_compatible('btl', 'bottle', $syn) && import_units_compatible('ltr', 'L', $syn) && import_units_compatible('each', 'pcs', $syn) && !import_units_compatible('kg', 'g', $syn) && !import_units_compatible('bottle', 'crate', $syn), 'unit spelling: btl = bottle, ltr = L, each = pcs, but kg is not g and a bottle is not a crate');

// ---- self-healing: a quantity typed with its unit ----
$pH = $csvRows([['Item Name', 'Balance', 'Unit Price'], ['ZZ Lager', '12 pcs', '500'], ['ZZ Rice', '2.5kg', '800'], ['ZZ Salt', 'abc', '10'], ['ZZ Beans', '1,250 g', '5'], ['ZZ Sugar', '7', '9']], 'heal.csv');
$aH = import_analyze_file($pH, 'heal.csv', $al, []);
$rowsH = []; foreach ($aH['sheets'][0]['rows'] as $r0) { $rowsH[$r0['fields']['item_name']['value']] = $r0; }
$t(($rowsH['ZZ Lager']['fields']['qty_single']['value'] ?? null) === 12 && ($rowsH['ZZ Lager']['fields']['unit']['value'] ?? '') === 'pcs' && ($rowsH['ZZ Lager']['fields']['qty_single']['how'] ?? '') === 'healed:number-unit', 'heal number-unit: "12 pcs" is read as quantity 12 and unit pcs, tagged as a repair, both from that cell');
$t(($rowsH['ZZ Rice']['fields']['qty_single']['value'] ?? null) === 2.5 && ($rowsH['ZZ Rice']['fields']['unit']['value'] ?? '') === 'kg', 'heal number-unit: "2.5kg" is 2.5 and kg - never converted to 2500 g');
$t(($rowsH['ZZ Beans']['fields']['qty_single']['value'] ?? null) === 1250 && ($rowsH['ZZ Beans']['fields']['unit']['value'] ?? '') === 'g', 'heal number-unit: "1,250 g" keeps its digits (1250, g)');
$t($rowsH['ZZ Salt']['status'] === 'blocked' && !isset($rowsH['ZZ Salt']['fields']['qty_single']), 'never heal: "abc" is not guessed - the row is blocked with its question');
$t(!isset($rowsH['ZZ Sugar']['fields']['qty_single']['how']) || $rowsH['ZZ Sugar']['fields']['qty_single']['how'] === 'as-written', 'never heal: a plain 7 is untouched');
$hl = $aH['heals'] ?? [];
$t(count($hl) === 3 && $hl[0]['rule'] === 'number-unit' && $hl[0]['before'] === '12 pcs' && $hl[0]['after'] === '12 pcs' && preg_match('/^heal\.csv!|!/', $hl[0]['source']) && $hl[1]['before'] === '2.5kg' && $hl[1]['after'] === '2.5 kg' && !empty($hl[0]['undoable']) && strlen($hl[0]['id']) === 12, 'heal log: each repair records before, after, the source cell, the rule and an id: ' . json_encode(array_slice($hl, 0, 2)));
$t(import_assert_no_invented_values($aH, import_csv_read($pH, 'heal.csv', [])) === [], 'INVARIANT still holds with the repairs: every repaired number and unit word is really in its cell');
$tm = $aH; $tm['sheets'][0]['rows'][1]['fields']['qty_single']['value'] = 2500;
$t(count(import_assert_no_invented_values($tm, import_csv_read($pH, 'heal.csv', []))) >= 1, 'INVARIANT catches a repaired number whose digits were changed (2.5kg -> 2500)');
$aU = import_analyze_file($pH, 'heal.csv', $al, ['undo_heals' => [$hl[0]['id']]]);
$rowU = null; foreach ($aU['sheets'][0]['rows'] as $r0) { if ($r0['fields']['item_name']['value'] === 'ZZ Lager') { $rowU = $r0; } }
$t($rowU && $rowU['status'] === 'blocked' && !isset($rowU['fields']['qty_single']) && count($aU['heals'] ?? []) === 2, 'undo: taking the repair back returns the original cell value and asks again (the row is blocked with its question; the other repairs stay)');
$pC = $csvRows([['Item Name', 'Balance', 'Unit'], ['ZZ Cola', '12 pcs', 'bottle'], ['ZZ Fanta', '6 btl', 'bottle']], 'healc.csv');
$aC = import_analyze_file($pC, 'healc.csv', $al, []);
$rc = []; foreach ($aC['sheets'][0]['rows'] as $r0) { $rc[$r0['fields']['item_name']['value']] = $r0; }
$t($rc['ZZ Cola']['status'] === 'blocked' && ($rc['ZZ Fanta']['fields']['qty_single']['value'] ?? null) === 6 && ($rc['ZZ Fanta']['fields']['unit']['value'] ?? '') === 'bottle', 'heal number-unit: a unit that disagrees with the unit column blocks the row (never overrides it); "6 btl" under unit "bottle" agrees and is read');


// ---- self-healing: numbers written with separators, a decimal comma or a currency word (listed, undoable, never a different number) ----
$pF = $csvRows([['Item Name', 'Balance', 'Unit Price'], ['ZZ Thou', '1,250', '2,500'], ['ZZ Comma', '12,5', '10'], ['ZZ Cur', '3', 'MK 900'], ['ZZ Plain', '7', '9.5'], ['ZZ Weird', '1.2.3', '4']], 'fmt.csv');
$aF = import_analyze_file($pF, 'fmt.csv', $al, []);
$fr = []; foreach ($aF['sheets'][0]['rows'] as $r0) { $fr[$r0['fields']['item_name']['value']] = $r0; }
$t(($fr['ZZ Thou']['fields']['qty_single']['value'] ?? null) === 1250 && ($fr['ZZ Thou']['fields']['unit_price']['value'] ?? null) === 2500 && ($fr['ZZ Comma']['fields']['qty_single']['value'] ?? null) === 12.5 && ($fr['ZZ Cur']['fields']['unit_price']['value'] ?? null) === 900, 'heal number-format: "1,250" is 1250, "2,500" is 2500, "12,5" is 12.5 and "MK 900" is 900 - the digits are the cell own');
$t(($fr['ZZ Weird']['status'] ?? '') === 'blocked' && !isset($fr['ZZ Weird']['fields']['qty_single']), 'never heal: "1.2.3" is not guessed (blocked with its question)');
$fh2 = $aF['heals'] ?? [];
$t(count($fh2) === 4 && array_unique(array_column($fh2, 'rule')) === ['number-format'] && $fh2[0]['before'] === '1,250' && $fh2[0]['after'] === '1250' && $aF['heal_totals'] === ['number-format' => 4] && !in_array('7', array_column($fh2, 'before'), true) && !in_array('9.5', array_column($fh2, 'before'), true), 'heal log: the 4 repairs are listed with before and after; plain numbers (7, 9.5) are not repairs');
$t(import_assert_no_invented_values($aF, import_csv_read($pF, 'fmt.csv', [])) === [], 'INVARIANT holds with number-format repairs (the value still equals the number the cell spells)');
$aF2 = import_analyze_file($pF, 'fmt.csv', $al, ['undo_heals' => [$fh2[0]['id']]]);
$fr2 = []; foreach ($aF2['sheets'][0]['rows'] as $r0) { $fr2[$r0['fields']['item_name']['value']] = $r0; }
$t(($fr2['ZZ Thou']['status'] ?? '') === 'blocked' && !isset($fr2['ZZ Thou']['fields']['qty_single']) && count($aF2['heals'] ?? []) === 3, 'undo: taking the "1,250" repair back means the cell is not read as a number (the question comes back); the other repairs stay');
$pCap = $csvRows(array_merge([['Item Name', 'Balance']], array_map(function ($i) { return ['ZZ Cap ' . $i, '1,' . str_pad((string)($i % 1000), 3, '0', STR_PAD_LEFT)]; }, range(1, 1500))), 'cap.csv');
$aCap = import_analyze_file($pCap, 'cap.csv', $al, []);
$t(count($aCap['heals']) === 1000 && $aCap['heal_totals']['number-format'] === 1500, 'heal log: every repair is counted (1500) but only the first 1000 are kept as records');
$rCap = import_resolve($aCap, [], $ctxBase);
$t($rCap['heal_counts']['number-format'] === 1500 && count($rCap['heals']) === 1000, 'resolve reports the true repair count, not the capped list');


// ---- the price check still compares like with like when stock spells the unit another way (btl = bottle) ----
$pPc = $csvRows([['Item Name', 'Balance', 'Unit', 'Unit Price'], ['ZZ NewOne', '5', 'bottle', '5000']], 'ZZ Cat.csv');
$aPc = import_analyze_file($pPc, 'ZZ Cat.csv', $al, []);
$exPc = []; for ($z = 0; $z < 8; $z++) { $exPc[] = ['id' => 500 + $z, 'name' => 'ZZ Peer ' . $z, 'category' => 'ZZ Cat', 'unit' => 'btl', 'current_quantity' => 0, 'cost_per_unit' => 100 + $z, 'par_level' => 0, 'reorder_point' => 0, 'notes' => '', 'pack_name' => null, 'pack_size' => null, 'is_archived' => 0, 'recipes' => 0]; }
$rPc = import_resolve($aPc, [], ['existing' => $exPc, 'unit_synonyms' => $syn] + $ctxBase);
$t(count(array_filter($rPc['extra_questions'], function ($q0) { return $q0['type'] === 'price-check'; })) === 1, 'price check: a new item in "bottle" is judged against stock counted in "btl" (same unit, spelled differently) - the unit spelling table does not silence the money check');

// ---- unit spelling inside the resolver: shown as a repair, undoable ----
$pS = $csvRows([['Item Name', 'Balance', 'Unit', 'Unit Price'], ['ZZ Water', '10', 'btl', '300'], ['ZZ Milk', '4', 'LTR', '900'], ['ZZ Rum', '3', 'bottle', '5000']], 'spell.csv');
$aS = import_analyze_file($pS, 'spell.csv', $al, []);
$rS = import_resolve($aS, [], $ctxBase);
$byName = []; foreach ($rS['items'] as $it0) { $byName[$it0['name']] = $it0; }
$t($byName['ZZ Water']['unit'] === 'bottle' && $byName['ZZ Milk']['unit'] === 'L' && $byName['ZZ Rum']['unit'] === 'bottle', 'resolve: "btl" imports as bottle and "LTR" as L');
$t(count($rS['heals']) === 2 && $rS['heals'][0]['rule'] === 'unit-spelling' && $rS['heals'][0]['before'] === 'btl' && $rS['heals'][0]['after'] === 'bottle' && $rS['heal_counts'] === ['unit-spelling' => 2] && !empty($rS['heals'][0]['source']), 'resolve: the spelling fixes are listed with before, after, cell and rule (the already-correct "bottle" is not listed)');
$hid0 = $rS['heals'][0]['id'];
$rS2 = import_resolve($aS, ['_heal_undo' => [$hid0 => ['rule' => 'unit-spelling', 'before' => 'btl', 'after' => 'bottle']]], $ctxBase);
$byName2 = []; foreach ($rS2['items'] as $it0) { $byName2[$it0['name']] = $it0; }
$t($byName2['ZZ Water']['unit'] === 'btl' && count($rS2['heals']) === 1 && count($rS2['heals_undone']) === 1, 'undo: the unit comes back exactly as written ("btl"), the fix stays listed as taken back');
$t(json_encode(array_map(function ($i) { return $i['unit']; }, import_resolve($aS, [], $ctxBase)['items'])) === json_encode(array_map(function ($i) { return $i['unit']; }, $rS['items'])), 'resolve is deterministic (same answers, same picture)');

// ---- suggestions: evidence only ----
$mkIt = function (string $name, string $cat, ?string $unit = null, array $o = []) {
    return $o + ['name' => $name, 'name_source' => 'S!A1', 'category' => $cat, 'category_how' => 'section-row', 'sheet' => 'S', 'unit' => $unit, 'match' => null, 'rows' => ['S!A1'], 'key' => import_norm_text($name)];
};
$peerItems = [];
for ($z = 0; $z < 13; $z++) { $peerItems[] = $mkIt('ZZ Beer ' . $z, 'Beers', $z < 11 ? 'bottle' : 'can'); }
$units = $ctxBase['unit_options'];
$ixP = import_infer_index([], ['unit_item' => [], 'unit_cat' => [], 'category' => []], $peerItems, $syn);
$sg = import_infer_unit($ixP, $mkIt('ZZ New Lager', 'Beers'), $units);
$t($sg['value'] === 'bottle' && $sg['confidence'] === 'high' && strpos($sg['reason'], '11 of the 13 other items under "Beers" are counted in bottle') !== false && $sg['evidence'][0]['kind'] === 'peers' && $sg['evidence'][0]['n'] === 11 && !$sg['mixed'], 'suggest (peers): "11 of the 13 other items under Beers are counted in bottle", high confidence, with the evidence: ' . $sg['reason']);
$mixPeers = [];
for ($z = 0; $z < 6; $z++) { $mixPeers[] = $mkIt('ZZ Spice ' . $z, 'Spices', ['kg', 'bottle', 'box'][$z % 3]); }
$sgm = import_infer_unit(import_infer_index([], ['unit_item' => [], 'unit_cat' => [], 'category' => []], $mixPeers, $syn), $mkIt('ZZ Paprika', 'Spices'), $units);
$t($sgm['value'] === null && $sgm['mixed'] === true && $sgm['confidence'] === 'low' && $sgm['candidates'], 'suggest (mixed): peers counted in three different units = no suggestion, marked "needs your choice" for that item');
$t(import_infer_unit($ixP, $mkIt('ZZ Odd', 'Unknown category'), $units)['value'] === null && import_infer_unit($ixP, $mkIt('ZZ Odd', 'Unknown category'), $units)['confidence'] === 'low', 'suggest: no peers and no other evidence = low confidence, no suggestion (just ask)');
$exDb = [['id' => 7, 'name' => 'Fanta Orange 500ml', 'category' => 'Soft drinks', 'unit' => 'bottle', 'is_archived' => 0]];
$sgd = import_infer_unit(import_infer_index($exDb, ['unit_item' => [], 'unit_cat' => [], 'category' => []], [], $syn), $mkIt('Fanta Orange', 'Misc'), $units);
$t($sgd['value'] === 'bottle' && $sgd['confidence'] === 'medium' && $sgd['evidence'][0]['kind'] === 'existing-item' && $sgd['evidence'][0]['id'] === 7, 'suggest (stock): an existing ingredient with the same name once the size is removed ("Fanta Orange" / "Fanta Orange 500ml") gives its unit, citing that ingredient');
$exArch = [['id' => 8, 'name' => 'Fanta Orange', 'category' => 'Soft drinks', 'unit' => 'crate', 'is_archived' => 1]];
$t(import_infer_unit(import_infer_index($exArch, ['unit_item' => [], 'unit_cat' => [], 'category' => []], [], $syn), $mkIt('Fanta Orange', 'Misc'), $units)['value'] === null, 'suggest (stock): an archived ingredient is not evidence');
$sgw = import_infer_unit(import_infer_index([], ['unit_item' => [], 'unit_cat' => [], 'category' => []], [], $syn), $mkIt('Soda water crate', 'Misc'), $units);
$t($sgw['value'] === 'crate' && $sgw['evidence'][0]['kind'] === 'name-word' && $sgw['evidence'][0]['word'] === 'crate', 'suggest (name): the unit word "crate" in the item name is evidence, citing the word');
$sgs = import_infer_unit(import_infer_index([], ['unit_item' => [], 'unit_cat' => [], 'category' => []], [], $syn), $mkIt('Cola 500ml x24', 'Misc'), $units);
$t($sgs['value'] === null, 'suggest (size tokens): "500ml" and "x24" describe the size of one, they are never proposed as the unit');
$t(import_infer_size_tokens('Fanta 500ml x24 pack of 12')[0]['kind'] === 'volume' && count(import_infer_size_tokens('Fanta 500ml x24 pack of 12')) === 3 && import_infer_strip_size('Fanta Orange 500ml x24') === 'fanta orange', 'suggest (size tokens): volume, count and pack words are found and stripped for name matching');
$lrn = ['unit_item' => ['zz pepper' => ['v' => 'g', 'by' => 3, 'at' => '2026-11-01 10:00:00']], 'unit_cat' => [], 'category' => []];
$sgl = import_infer_unit(import_infer_index([], $lrn, $mixPeers, $syn), $mkIt('ZZ Pepper', 'Spices'), $units);
$t($sgl['value'] === 'g' && $sgl['confidence'] === 'high' && strpos($sgl['reason'], 'Remembered from your earlier import') !== false && $sgl['evidence'][0]['by'] === 3, 'suggest (learned): a unit this hotel confirmed before wins over mixed peers, says "Remembered from your earlier import", and keeps who/when');
$t(import_infer_unit(import_infer_index([], ['unit_item' => ['zz pepper' => ['v' => 'furlong', 'by' => 1, 'at' => '']], 'unit_cat' => [], 'category' => []], [], $syn), $mkIt('ZZ Pepper', 'Spices'), $units)['value'] === null, 'suggest: a unit that is not in the hotel\'s unit list is never suggested, even if it was learned');
$cs = import_infer_category(import_infer_index($exDb, ['unit_item' => [], 'unit_cat' => [], 'category' => []], [], $syn), $mkIt('Fanta Orange', 'KITCHEN STOCK', null, ['category_how' => 'sheet-name']));
$t($cs && $cs['value'] === 'Soft drinks' && $cs['evidence'][0]['id'] === 7 && import_infer_category(import_infer_index($exDb, ['unit_item' => [], 'unit_cat' => [], 'category' => []], [], $syn), $mkIt('Fanta Orange', 'Beers')) === null, 'suggest (category): a missing heading gets the category of the same-named stock item, citing it; an item that has its own heading is never re-filed');

// ---- resolve: per-item units, suggestions, skip ----
$pN = [['Item Name', 'Balance', 'Unit Price']];
for ($z = 0; $z < 12; $z++) { $pN[] = ['ZZ Cola ' . $z, '5', '300']; }
$pN[] = ['ZZ Brandnew', '2', '400'];
$fN = $csvRows($pN, 'nounit.csv');
$aN = import_analyze_file($fN, 'nounit.csv', $al, []);
$exN = []; for ($z = 0; $z < 12; $z++) { $exN[] = ['id' => 100 + $z, 'name' => 'ZZ Cola ' . $z, 'category' => 'Some other category', 'unit' => 'bottle', 'current_quantity' => 0, 'cost_per_unit' => 300, 'par_level' => 0, 'reorder_point' => 0, 'notes' => '', 'pack_name' => null, 'pack_size' => null, 'is_archived' => 0, 'recipes' => 0]; }
$ctxN = ['existing' => $exN, 'unit_options' => $units, 'corrections' => [], 'unit_synonyms' => $syn, 'learned' => []];
$rN = import_resolve($aN, [], $ctxN);
$nu = null; foreach ($rN['items'] as $it0) { if ($it0['name'] === 'ZZ Brandnew') { $nu = $it0; } }
$nuq = null; foreach ($rN['extra_questions'] as $q0) { if ($q0['type'] === 'no-unit') { $nuq = $q0; } }
$t($nu && $nu['status'] === 'needs-answer' && $nu['unit'] === null && $nu['unit_suggestion']['value'] === null && $nuq && $nuq['answer'] === null && $nuq['pending'] === 1, 'no-unit: the new item with no unit stays unanswered; a suggestion is only data on the item, never applied');
$ctxN2 = $ctxN; $ctxN2['existing'][] = ['id' => 200, 'name' => 'ZZ Brandnew 330ml', 'category' => 'x', 'unit' => 'bottle', 'current_quantity' => 0, 'cost_per_unit' => 400, 'par_level' => 0, 'reorder_point' => 0, 'notes' => '', 'pack_name' => null, 'pack_size' => null, 'is_archived' => 0, 'recipes' => 0];
$rN2 = import_resolve($aN, [], $ctxN2);
$nu2 = null; foreach ($rN2['items'] as $it0) { if ($it0['name'] === 'ZZ Brandnew') { $nu2 = $it0; } }
$t($nu2['unit_suggestion']['value'] === 'bottle' && $nu2['status'] === 'needs-answer' && empty($nu2['unit_confirmed']), 'no-unit: a clear suggestion ("bottle", from stock) is shown but the item still needs the person: pre-selected is not answered');
$rN3 = import_resolve($aN, ['_unit_for' => [$nu2['key'] => ['unit' => 'bottle', 'src' => 'suggestion', 'by' => 1, 'at' => 'x']]], $ctxN2);
$nu3 = null; foreach ($rN3['items'] as $it0) { if ($it0['name'] === 'ZZ Brandnew') { $nu3 = $it0; } }
$nuq3 = null; foreach ($rN3['extra_questions'] as $q0) { if ($q0['type'] === 'no-unit') { $nuq3 = $q0; } }
$t($nu3['unit'] === 'bottle' && $nu3['status'] === 'ready' && !empty($nu3['unit_confirmed']) && $nuq3['answer'] === 'per-item' && $rN3['can_confirm'] === true, 'no-unit: the person\'s click (the unit chosen for that item) makes it ready; the sheet-wide question has nothing left to ask');
$rN4 = import_resolve($aN, ['_unit_for' => [$nu2['key'] => ['unit' => 'furlong', 'src' => 'pick']]], $ctxN2);
$nu4 = null; foreach ($rN4['items'] as $it0) { if ($it0['name'] === 'ZZ Brandnew') { $nu4 = $it0; } }
$t($nu4['status'] === 'needs-answer' && $nu4['unit'] === null, 'no-unit: a stored unit that is not in the unit list is ignored');

// ---- skipping blocked rows ----
$pB = $csvRows([['Item Name', 'Balance', 'Unit', 'Unit Price'], ['ZZ Good', '5', 'kg', '100'], ['ZZ Bad', 'lots', 'kg', '100'], ['ZZ Neg', '-3', 'kg', '100'], ['ZZ Twin', '2', 'kg', '50'], ['ZZ Twin', '3', 'kg', '60']], 'blocked.csv');
$aB = import_analyze_file($pB, 'blocked.csv', $al, []);
$rB = import_resolve($aB, [], $ctxBase);
$t($rB['counts']['blocked'] === 2 && $rB['counts']['needs-answer'] === 2 && $rB['can_confirm'] === false && strpos($rB['why_not'], 'skip them') !== false, 'blocked rows: two bad values are blocked and the message offers skipping');
$uidBad = null; $uidNeg = null; $uidTwin = [];
foreach ($rB['rows'] as $u0 => $r0) { if ($r0['name'] === 'ZZ Bad') { $uidBad = $u0; } if ($r0['name'] === 'ZZ Neg') { $uidNeg = $u0; } if ($r0['name'] === 'ZZ Twin') { $uidTwin[] = $u0; } }
$rB1 = import_resolve($aB, ['_skip_rows' => [$uidBad => ['why' => 'Balance is not a number', 'code' => 'bad-value', 'grp' => $uidBad]]], $ctxBase);
$t($rB1['rows'][$uidBad]['status'] === 'skipped' && $rB1['rows'][$uidBad]['skipped_why'] === 'Balance is not a number' && $rB1['rows'][$uidBad]['skip_code'] === 'bad-value' && $rB1['counts']['blocked'] === 1, 'skip (single): one blocked row answered "skip" is skipped with the reason it was skipped for');
$skipAll = [];
foreach (import_cont_entries(['res' => $rB], ['needs-answer', 'blocked']) as $e0) { foreach ($e0['uids'] as $u0) { $skipAll[$u0] = ['why' => $e0['why'], 'code' => $e0['code'], 'grp' => $e0['uids'][0]]; } }
$rB2 = import_resolve($aB, ['_skip_rows' => $skipAll], $ctxBase);
$t(count($skipAll) === 4 && $rB2['counts']['skipped'] === 4 && $rB2['counts']['blocked'] === 0 && $rB2['counts']['needs-answer'] === 0 && $rB2['can_confirm'] === true && $rB2['committable_items'] === 1, 'skip (bulk / continue): all blocked + unanswered rows skipped -> nothing open, confirm possible with the one good row');
$names2 = array_map(function ($i) { return $i['name']; }, $rB2['items']);
$t($names2 === ['ZZ Good'], 'skip: skipped rows are not items at all - they cannot reach the commit plan: ' . json_encode($names2));
$ent = import_cont_entries(['res' => $rB], ['blocked']);
$t(count($ent) === 2 && $ent[0]['code'] === 'bad-value' && $ent[1]['code'] === 'bad-value' && strpos($ent[0]['why'], 'not a number') !== false, 'blocked rows: each blocked entry has a plain reason and a reason code (bad-value) naming the cell');
$pBd = $csvRows([['Item Name', 'Balance', 'Unit'], ['ZZ Dup', '2', 'kg'], ['ZZ Dup', '3', 'kg'], ['ZZ Fine', '1', 'kg']], 'blockedd.csv');
$aBd = import_analyze_file($pBd, 'blockedd.csv', $al, []);
$dupQ = null; foreach ($aBd['questions'] as $q0) { if (strpos($q0['type'], 'duplicate') === 0) { $dupQ = $q0; } }
$rBd = import_resolve($aBd, [$dupQ['id'] => []], $ctxBase);
$qk = import_q_key($dupQ);
$rBd = import_resolve($aBd, [$qk => ['key' => 'separate-items']], $ctxBase);
$t($rBd['counts']['blocked'] === 2 && !empty(array_filter($rBd['rows'], function ($r0) { return ($r0['blocked_code'] ?? '') === 'separate-items'; })), 'blocked rows: "different items with one name" blocks both rows directly (no question card) and says so with a code');
$entD = import_cont_entries(['res' => $rBd], ['blocked']);
$skD = []; foreach ($entD as $e0) { foreach ($e0['uids'] as $u0) { $skD[$u0] = ['why' => $e0['why'], 'code' => $e0['code'], 'grp' => $e0['uids'][0]]; } }
$rBd2 = import_resolve($aBd, [$qk => ['key' => 'separate-items'], '_skip_rows' => $skD], $ctxBase);
$t($rBd2['counts']['blocked'] === 0 && $rBd2['counts']['skipped'] === 2 && $entD[0]['code'] === 'separate-items', 'skip: rows blocked with no question at all can be skipped too');
$t(import_answer_allowed(['row_uids' => ['a'], 'options' => [['key' => 'keep']]], '_skip') === '' && import_answer_allowed(['row_uids' => [], 'options' => []], '_skip') !== '' && import_answer_allowed(['row_uids' => ['a'], 'options' => [['key' => 'keep']]], 'nonsense') !== '', 'skip is a valid answer to any question that belongs to rows (blocked ones included), but not to a question about how a sheet is read');
// unit-clash / archived skip really takes the item out (it used to stay "ready")
$exC = [['id' => 300, 'name' => 'ZZ Good', 'category' => 'x', 'unit' => 'crate', 'current_quantity' => 0, 'cost_per_unit' => 100, 'par_level' => 0, 'reorder_point' => 0, 'notes' => '', 'pack_name' => null, 'pack_size' => null, 'is_archived' => 0, 'recipes' => 0]];
$rC = import_resolve($aB, [], ['existing' => $exC] + $ctxBase);
$clashQ = null; foreach ($rC['extra_questions'] as $q0) { if ($q0['type'] === 'unit-clash') { $clashQ = $q0; } }
$t($clashQ && $clashQ['bad'] === true && $rC['counts']['blocked'] >= 3, 'blocked rows: a unit that differs from stock is a blocked "unit clash" question');
$rC2 = import_resolve($aB, [$clashQ['qkey'] => ['key' => 'skip']], ['existing' => $exC] + $ctxBase);
$good2 = null; foreach ($rC2['rows'] as $r0) { if ($r0['name'] === 'ZZ Good') { $good2 = $r0; } }
$t($good2['status'] === 'skipped', 'skip: answering "skip" to a unit clash takes the item out (it used to stay ready)');
$exA = [['id' => 301, 'name' => 'ZZ Good', 'category' => 'x', 'unit' => 'kg', 'current_quantity' => 0, 'cost_per_unit' => 100, 'par_level' => 0, 'reorder_point' => 0, 'notes' => '', 'pack_name' => null, 'pack_size' => null, 'is_archived' => 1, 'recipes' => 0]];
$rA = import_resolve($aB, [], ['existing' => $exA] + $ctxBase);
$archQ = null; foreach ($rA['extra_questions'] as $q0) { if ($q0['type'] === 'archived-match') { $archQ = $q0; } }
$rA2 = import_resolve($aB, [$archQ['qkey'] => ['key' => 'skip']], ['existing' => $exA] + $ctxBase);
$goodA = null; foreach ($rA2['rows'] as $r0) { if ($r0['name'] === 'ZZ Good') { $goodA = $r0; } }
$t($archQ && $goodA['status'] === 'skipped', 'skip: answering "skip" to an archived-ingredient match takes the item out');

// ---- learned rows (what a commit may teach) ----
$lr = import_learned_rows([
    ['status' => 'ready', 'match' => null, 'name' => 'ZZ Alpha', 'unit' => 'bottle', 'unit_confirmed' => true, 'category' => 'Beers'],
    ['status' => 'ready', 'match' => null, 'name' => 'ZZ Beta', 'unit' => 'bottle', 'unit_confirmed' => true, 'category' => 'Beers'],
    ['status' => 'ready', 'match' => null, 'name' => 'ZZ Gamma', 'unit' => 'bottle', 'unit_confirmed' => true, 'category' => 'Beers', 'category_confirmed' => true],
    ['status' => 'ready', 'match' => null, 'name' => 'ZZ Delta', 'unit' => 'kg', 'category' => 'Beers'],
    ['status' => 'needs-answer', 'match' => null, 'name' => 'ZZ Eps', 'unit' => 'kg', 'unit_confirmed' => true, 'category' => 'Beers'],
    ['status' => 'ready', 'match' => ['id' => 1], 'name' => 'ZZ Zeta', 'unit' => 'kg', 'unit_confirmed' => true, 'category' => 'Beers'],
]);
$kinds = array_count_values(array_column($lr, 0));
$t(($kinds['learned_unit_item'] ?? 0) === 3 && ($kinds['learned_category'] ?? 0) === 1 && ($kinds['learned_unit_cat'] ?? 0) === 1 && !in_array('zz delta', array_column($lr, 1), true) && !in_array('zz eps', array_column($lr, 1), true) && !in_array('zz zeta', array_column($lr, 1), true), 'learning: only NEW, READY items whose unit/category a person confirmed teach anything (not defaults, not unanswered, not matched ones); 3 agreeing items teach the category habit');

// ---- nothing changes where no rule fires ----
$t(!array_key_exists('heals', $anz) && md5(json_encode($anz)) === '71ff50179a34b36e459b152a9a706d89', 'engine output is byte-identical where no rule fires: the generated 5,000-item sheet has no repair key and keeps its frozen md5');

// ---- our own Stock > Ingredients export + a renamed copy of it read with ZERO header questions (owner 2026-11-16) ----
echo "-- our own export headings and renamed copies --\n";
$hdrNorm = [];
$dupPhrase = [];
foreach ($al['fields'] as $fv => $ps) { foreach ($ps as $p) { $nv = import_norm_text($p); if (isset($hdrNorm[$nv]) && $hdrNorm[$nv] !== $fv) { $dupPhrase[] = $p; } $hdrNorm[$nv] = $fv; } }
$t(!$dupPhrase, 'vocabulary: no header phrase is listed under two fields (the database picks the first by id, so a duplicate could flip) ' . implode(', ', $dupPhrase));
$t(in_array('min_qty', $al['derived'], true) && in_array('lead_time', $al['derived'], true) && ($al['field_fallbacks']['min_qty'] ?? '') === 'min_level', 'vocabulary: Min Qty and Lead Time are derived (greyed); Min Qty stands in for the reorder level only as a fallback');
$t(import_merge_aliases($al, ['fields' => ['item_name' => ['zz']]])['field_fallbacks'] === ['min_qty' => 'min_level', 'qty_total' => 'qty_single'], 'merging learned header words keeps the keyed fallback list intact');
// Invented rows only (ZZT ...). Row 3 has no expiry: the cell stays blank and must stay blank.
$expRows = [
    ['ZZT Lager 330ml', 'ZZT Beers', 'bottle', 24, 6, 12, 48, 3, '850.50', '20412.00', '2027-03-27', 'Active'],
    ['ZZT Flour 2kg', 'ZZT Dry Goods', 'kg', 10.5, 2, 4, 20, 7, '1200.00', '12600.00', '2027-01-01', 'Active'],
    ['ZZT Bleach 5L', 'ZZT Cleaning', 'L', 3, 1, 2, 6, 14, '4300.25', '12900.75', '', 'Active'],
];
$ourHdr = ['Name', 'Category', 'Unit', 'Current Qty', 'Min Qty', 'Reorder Point', 'Par Level', 'Lead Time (days)', 'Cost/Unit', 'Stock Value', 'Next Expiry', 'Status'];
$ownerHdr = ['Name', 'Category', 'Unit', 'Current Quantity level', 'Minimum Quantity level', 'Reorder Point', 'Par Level', 'Lead Time (days)', 'Unit Cost', 'Stock Value', 'Next Expiry', 'Status'];
$csvOf = function (array $hdr, array $rows, array $drop = []) {   // same shape as stock-ingredients.php ?export=csv: BOM, fputcsv
    $fh = fopen('php://temp', 'w+'); fwrite($fh, "\xEF\xBB\xBF");
    $keep = function ($r) use ($drop) { foreach ($drop as $i) { unset($r[$i]); } return array_values($r); };
    fputcsv($fh, $keep($hdr)); foreach ($rows as $r) { fputcsv($fh, $keep($r)); }
    rewind($fh); $s = stream_get_contents($fh); fclose($fh); return $s;
};
$xlsxOf = function (array $hdr, array $rows, string $sheet) {      // the owner's renamed copy: a workbook, numbers as numbers, dates as dates, Stock Value a formula
    $L = range('A', 'L'); $out = [1 => []];
    foreach ($hdr as $i => $hv) { $out[1][$L[$i]] = fx_S($hv); }
    $serial = ['2027-03-27' => 46473, '2027-01-01' => 46388];
    foreach ($rows as $k => $r) {
        $rn = $k + 2; $row = [];
        foreach ($r as $i => $v) {
            if ($i === 9) { $row[$L[$i]] = fx_F('D' . $rn . '*I' . $rn, (string)((float)$r[3] * (float)$r[8])); }
            elseif ($i === 10) { if ($v !== '') { $row[$L[$i]] = fx_D($serial[$v]); } }
            elseif (is_int($v) || is_float($v) || ($i === 8)) { $row[$L[$i]] = fx_N((float)$v); }
            else { $row[$L[$i]] = fx_S((string)$v); }
        }
        $out[$rn] = $row;
    }
    return fx_xlsx(['sheets' => [['name' => $sheet, 'rows' => $out]]]);
};
$hdrQ = function (array $an) { return array_values(array_filter($an['questions'], function ($q) { return in_array($q['type'], ['mapping-unsure', 'no-item-column', 'sheet-unknown'], true); })); };
$colMap = function (array $sh) { $m = []; foreach ($sh['columns'] as $c) { $m[$c['letter']] = [$c['field'], !empty($c['derived']), $c['state'], $c['fallback_from'] ?? null]; } return $m; };
$expectCols = ['A' => 'item_name', 'B' => 'category', 'C' => 'unit', 'D' => 'qty_single', 'E' => 'min_qty', 'F' => 'min_level', 'G' => 'max_level',
    'H' => 'lead_time', 'I' => 'unit_price', 'J' => 'total_cost', 'K' => 'expiry', 'L' => 'status'];
$greyCols = ['E', 'H', 'J', 'L'];
$cases = [
    'our export (CSV)' => import_analyze_file($put($csvOf($ourHdr, $expRows), 'stock-management.csv'), 'stock-management.csv', $al),
    'owner\'s renamed copy (xlsx, sheet "in")' => import_analyze_file($put($xlsxOf($ownerHdr, $expRows, 'in'), 'revised.xlsx'), 'revised.xlsx', $al),
];
foreach ($cases as $label => $anE) {
    $sh = $anE['sheets'][0] ?? ['columns' => [], 'rows' => [], 'triage' => ['action' => '']];
    $cm = $colMap($sh);
    $t($anE['ok'] !== false && ($sh['triage']['action'] ?? '') === 'import' && !$hdrQ($anE), $label . ': read with zero header questions (' . count($hdrQ($anE)) . ')');
    $badCols = [];
    foreach ($expectCols as $L => $fv) {
        $isGrey = in_array($L, $greyCols, true);
        if (($cm[$L][0] ?? null) !== $fv || ($cm[$L][2] ?? '') !== 'mapped' || ($cm[$L][1] ?? null) !== $isGrey) { $badCols[] = $L . '=' . json_encode($cm[$L] ?? null); }
    }
    $t(!$badCols, $label . ': D Balance, F Reorder level, G Par level, I Unit price, K Expiry imported; E Min Qty, H Lead time, J Stock value, L Status greyed ' . implode(' ', $badCols));
    $byRow = []; foreach ($sh['rows'] as $rw) { $byRow[$rw['row']] = $rw; }
    $okVals = count($byRow) === 3;
    foreach ($expRows as $k => $er) {
        $rw = $byRow[$k + 2] ?? null;
        $okVals = $okVals && $rw
            && abs((float)($rw['fields']['qty_single']['value'] ?? -1) - (float)$er[3]) < 0.00005 && substr((string)$rw['fields']['qty_single']['source'], -3) === '!D' . ($k + 2)
            && abs((float)($rw['fields']['min_level']['value'] ?? -1) - (float)$er[5]) < 0.00005 && substr((string)$rw['fields']['min_level']['source'], -3) === '!F' . ($k + 2)
            && abs((float)($rw['fields']['unit_price']['value'] ?? -1) - (float)$er[8]) < 0.00005
            && !array_intersect(array_keys($rw['fields']), ['min_qty', 'lead_time', 'total_cost', 'status']);
    }
    $t($okVals, $label . ': each value comes from its own cell (qty from D, reorder level from F, never from Min Qty E); greyed columns never reach a row');
    $r4 = $byRow[4] ?? ['fields' => [], 'warnings' => []];
    $dateQ = array_filter($anE['questions'], function ($q) { return in_array($q['type'], ['ambiguous-date'], true); });
    $t(($byRow[2]['fields']['expiry']['value'] ?? '') === '2027-03-27' && (!isset($r4['fields']['expiry']) || $r4['fields']['expiry']['value'] === null || $r4['fields']['expiry']['value'] === '') && !$dateQ,
        $label . ': Next Expiry is read as a date, and a blank Next Expiry stays blank (no date invented, no question)');
}
// Without a Reorder Point column, Min Qty is the only minimum the sheet has: it becomes the reorder level
$anNoRp = import_analyze_file($put($csvOf($ourHdr, $expRows, [5]), 'no-reorder.csv'), 'no-reorder.csv', $al);
$cmN = $colMap($anNoRp['sheets'][0] ?? ['columns' => []]);
$rN = []; foreach (($anNoRp['sheets'][0]['rows'] ?? []) as $rw) { $rN[$rw['row']] = $rw; }
$t(!$hdrQ($anNoRp) && ($cmN['E'] ?? null) === ['min_level', false, 'mapped', 'min_qty'] && ($cmN['F'][0] ?? null) === 'max_level'
    && abs((float)($rN[2]['fields']['min_level']['value'] ?? -1) - 6) < 0.00005 && substr((string)($rN[2]['fields']['min_level']['source'] ?? ''), -3) === '!E2',
    'no Reorder Point column: Min Qty (E) is used as the reorder level from its own cells, with no question ' . json_encode($cmN['E'] ?? null));
// The owner's heading for E alone, with a Reorder Point that is only a QUESTION (a misspelt heading): E stays greyed, never asked
$anQ = import_analyze_file($put($csvOf(['Name', 'Qty', 'Minimum Quantity level', 'Reordr Pont'], [['ZZT A', 1, 2, 3], ['ZZT B', 4, 5, 6], ['ZZT C', 7, 8, 9]]), 'q.csv'), 'q.csv', $al);
$cmQ = $colMap($anQ['sheets'][0] ?? ['columns' => []]);
$t(($cmQ['C'] ?? null) === ['min_qty', true, 'mapped', null] && count($hdrQ($anQ)) === 1,
    'Min Qty stays greyed while a reorder-level column is still being asked about (only that one question) ' . json_encode($cmQ));
// headings that already had an owner must keep it (the new phrases steal nothing)
$keepMap = ['Main Store Balance' => 'qty_secondary', 'Bar Qty' => 'qty_primary', 'Stock Value' => 'total_cost', 'Reorder Qty' => 'purchase_qty', 'Days to Expiry' => 'days_to_expiry',
    'Max Stock Level' => 'max_level', 'Safety Stock' => 'min_level', 'Total Cost' => 'total_cost', 'Unit Cost' => 'unit_price', 'Current Qty' => 'qty_single',
    'Main Store Current Qty' => 'qty_secondary', 'Expiry Date' => 'expiry', 'Quantity in Stock' => 'qty_single', 'Inventory Value' => 'total_cost', 'Reorder Time in Days' => 'lead_time',
    'Quantity in Reorder' => 'purchase_qty', 'Stock Valuation' => 'total_cost', 'Commodity' => 'item_name', 'Commodity Code' => 'row_no', 'Unit Type' => 'unit'];
$keepBad = [];
foreach ($keepMap as $hv => $fv) { $m = import_match_header($hv, $al); if ($m['field'] !== $fv || $m['conf'] < import_map_auto_threshold()) { $keepBad[] = $hv . '->' . $m['field'] . '@' . $m['conf']; } }
$t(!$keepBad, 'headings map to their own field with confidence (old owners kept, new ones added) ' . implode('; ', $keepBad));
// QA 2026-11-16: a worked-out figure (total / value / amount / days) never lands on Unit price or Balance from part of its words
$figBad = [];
foreach (['Total Buying Cost', 'Avg Cost Value', 'Total Avg Cost', 'Average Cost Total', 'Weighted Average Cost Value', 'Total Unit Cost', 'Unit Price Total',
    'Value in Stock', 'Days in Stock', 'Current Level Value', 'Current Quantity Level Value', 'On Hand Qty Value', 'Days on Hand', 'Balance Amount',
    'Qty Total Value', 'Buying Cost Amount', 'Cost Unit Total'] as $hv) {
    $m = import_match_header($hv, $al);
    if ($m['conf'] >= import_map_auto_threshold() && !import_field_is_derived((string)$m['field'], $al)) { $figBad[] = $hv . '->' . $m['field']; }
}
$t(!$figBad, 'total / value / amount / days headings are never auto-read as an imported field (greyed or a question) ' . implode('; ', $figBad));
$figRun = function (array $hdr, array $rows) use ($put, $al) { $fh = fopen('php://temp', 'w+'); fputcsv($fh, $hdr); foreach ($rows as $r) { fputcsv($fh, $r); } rewind($fh);
    $s = stream_get_contents($fh); fclose($fh); return import_analyze_file($put($s, 'fig.csv'), 'fig.csv', $al); };
$figRows = function (array $an, string $f) { $v = []; foreach (($an['sheets'][0]['rows'] ?? []) as $rw) { if (isset($rw['fields'][$f])) { $v[] = (float)$rw['fields'][$f]['value']; } } return $v; };
$aTb = $figRun(['Item', 'Qty', 'Unit', 'Total Buying Cost'], [['ZZT A', 2, 'kg', 5000], ['ZZT B', 3, 'kg', 6000], ['ZZT C', 4, 'kg', 7000]]);
$aVs = $figRun(['Item', 'Bar', 'Stores', 'Value in Stock'], [['ZZT A', 2, 1, 5000], ['ZZT B', 3, 1, 6000], ['ZZT C', 4, 1, 7000]]);
$aDs = $figRun(['Item', 'Qty', 'Days in Stock'], [['ZZT A', 2, 12], ['ZZT B', 3, 14], ['ZZT C', 4, 16]]);
$t(!$figRows($aTb, 'unit_price') && !$figRows($aVs, 'qty_single') && $figRows($aDs, 'qty_single') === [2.0, 3.0, 4.0],
    'files: "Total Buying Cost" 5000 is never a unit price, "Value in Stock" 5000 never a balance, "Days in Stock" 12 never a balance (the real Qty column still is)');

// ---- the sheet's own value column vs Quantity x Unit Cost (owner 2026-11-16, report only; value-check.php) ----
require_once $root . '/includes/import/value-check.php';
require_once $root . '/includes/import/xlsx-reader.php';
$vcCsv = function (array $hdr, array $rows, string $name = 'vc.csv') use ($put) { $fh = fopen('php://temp', 'w+'); fputcsv($fh, $hdr); foreach ($rows as $r) { fputcsv($fh, $r); } rewind($fh);
    $b = stream_get_contents($fh); fclose($fh); return $put($b, $name); };
// every fixture gets a Unit column (last) so its items can be ready; the missing-price list comes from the resolved items, like the import
$vcRun = function (array $hdr, array $rows, array $answers = [], array $existing = []) use ($vcCsv, $al) {
    $hdr[] = 'Unit';
    $rows = array_map(function ($r) { $r[] = 'kg'; return $r; }, $rows);
    $an = import_analyze_file($vcCsv($hdr, $rows), 'vc.csv', $al, ['known_names' => array_column($existing, 'name')]);
    $before = md5(json_encode($an));
    $col = import_vc_collect($an);
    $res = import_resolve($an, $answers, ['existing' => $existing, 'unit_options' => ['kg', 'each', 'bottle'], 'corrections' => []]);
    if ($answers) { import_apply_answers($an, $answers, []); }
    $fin = import_vc_finish($col, $an['questions'], $res['items']);
    return [$an, $fin, $before, $res];
};
$vcHdr = ['Item', 'Qty', 'Unit Cost', 'Stock Value'];
$vcRows = [
    ['ZZV Match A', 2, 100, 200], ['ZZV Match B', 3, 50, 150],
    ['ZZV Over big', 1, 1000, 5000],            // +4,000
    ['ZZV Over small', 2, 100, 300],            // +100
    ['ZZV Under big', 10, 500, 2000],           // -3,000
    ['ZZV Under small', 4, 25, 50],             // -50
    ['ZZV No qty', 0, 300, 1200],               // +1,200, value with no quantity
    ['ZZV Rounding', 3, 33.33, 100],            // 99.99 vs 100: rounding
    ['ZZV Half pct', 1000, 10, 10040],          // 40 on 10,000 = 0.4%: rounding
    ['ZZV No price', 5, '', ''],                // stock with no unit cost
];
[$vcAn, $vcF, $vcMd5] = $vcRun($vcHdr, $vcRows);
$g = $vcF['groups'][0] ?? null;
$t($g && $g['header'] === 'Stock Value' && $g['compared'] === 9 && $g['differ'] === 5 && $g['more_n'] === 3 && abs($g['more_sum'] - 5300) < 0.005 && $g['less_n'] === 2 && abs($g['less_sum'] - 3050) < 0.005 && $g['no_qty_n'] === 1,
    'value check: 9 rows compared, 5 disagree: 3 show 5,300 more (one with no quantity), 2 show 3,050 less; the two rounding rows are not counted', json_encode($g));
$t($g && abs($g['calc_total'] - 16749.99) < 0.005 && abs($g['column_total'] - 19040) < 0.005, 'value check: sheet total = sum of Quantity x Unit Cost (16,749.99) and the column total (19,040) over the compared rows');
$t(array_column($vcF['rows'], 'name') === ['ZZV Over big', 'ZZV Under big', 'ZZV No qty', 'ZZV Over small', 'ZZV Under small'], 'value check: rows sorted by the largest difference first', json_encode(array_column($vcF['rows'], 'name')));
$t(($vcF['rows'][2]['kind'] ?? '') === 'no-qty' && ($vcF['rows'][0]['diff'] ?? 0) == 4000 && ($vcF['rows'][1]['diff'] ?? 0) == -3000 && ($vcF['rows'][0]['cell'] ?? '') === 'vc!D4', 'value check: signed differences, the no-quantity row is marked, the value cell is named', json_encode($vcF['rows'][0] ?? null));
$t($vcF['missing_n'] === 1 && $vcF['missing'][0]['name'] === 'ZZV No price' && $vcF['missing'][0]['row'] === 11 && $vcF['show'] === true, 'value check: the new item with stock and no unit cost is listed as a missing price (row 11)', json_encode($vcF['missing']));
// "missing prices" = the import's own "no cost" rule: an existing item counted at its stock cost is NOT listed, skipped rows are not listed
$exV = [['id' => 91, 'name' => 'ZZV Stocked', 'category' => 'vc', 'unit' => 'kg', 'current_quantity' => 1, 'cost_per_unit' => 650, 'par_level' => 0, 'reorder_point' => 0, 'notes' => '', 'pack_name' => null, 'pack_size' => null, 'is_archived' => 0, 'recipes' => 0]];
$mpRows = [['ZZV Stocked', 4, 0, ''], ['ZZV New free', 2, '', ''], ['ZZV New priced', 3, 40, '']];
$mpNo = function (array $res) { return import_cont_not_counted($res['items'], [], PHP_INT_MAX)['by']['no_cost']; };
[$mpAn, $mp1, , $mpR1] = $vcRun($vcHdr, $mpRows, [], $exV);
$t(array_column($mp1['missing'], 'name') === ['ZZV New free'] && $mp1['missing_n'] === $mpNo($mpR1)['n'], 'missing prices: an existing item with a stock cost and a sheet price of 0 is NOT listed, the new item with no price IS, and the count equals the "no cost" count of the import', json_encode([$mp1['missing'], $mpNo($mpR1)]));
$tickV = ['_updates' => [rh_ssi_key('ZZV Stocked') => ['quantity']]];
[, $mp2, , $mpR2] = $vcRun($vcHdr, $mpRows, $tickV, $exV);
$t(array_column($mp2['missing'], 'name') === ['ZZV New free'] && $mp2['missing_n'] === $mpNo($mpR2)['n'], 'missing prices: with its quantity ticked the existing item is counted at its stock cost and still not listed; counts match');
$uidFree = '';
foreach ($mpAn['sheets'][0]['rows'] as $rw) { if ($rw['fields']['item_name']['value'] === 'ZZV New free') { $uidFree = $rw['uid']; } }
[, $mp3, , $mpR3] = $vcRun($vcHdr, $mpRows, ['_skip_rows' => [$uidFree => ['why' => 'test', 'code' => 'chosen']]], $exV);
$t($uidFree !== '' && $mp3['missing_n'] === 0 && $mpNo($mpR3)['n'] === 0, 'missing prices: a skipped row is not listed (and the server does not count it as "no cost" either)');
$t(md5(json_encode($vcAn)) === $vcMd5, 'value check: reading the check changes nothing in the analysis');
$ov = import_vc_overview($vcF);
$t(!isset($ov['rows']) && count($ov['groups']) === 1 && $ov['differ'] === 5, 'value check: the summary carries counts and sums only, no row list');
$vcBig = $vcF; $vcBig['rows'] = array_merge($vcF['rows'], $vcF['rows'], $vcF['rows']);      // 15 rows: two pages of 10
$pg = import_vc_rows($vcBig, 2, 10);
$t($pg['total'] === 15 && $pg['pages'] === 2 && $pg['page'] === 2 && count($pg['rows']) === 5 && import_vc_rows($vcBig, 1, 0)['pages'] === 1 && count(import_vc_rows($vcBig, 1, 0)['rows']) === 15
    && import_vc_rows($vcBig, 1, 7)['size'] === 10 && import_vc_rows($vcBig, 9, 25)['page'] === 1, 'value check: rows come page by page (10/25/50/100, anything else = 10) or all at once');
// the import result is the same with and without the value column (the column is never imported)
$vcNoCol = array_map(function ($r) { return array_merge(array_slice($r, 0, 3), ['kg']); }, $vcRows);
$ctxV = ['existing' => [], 'unit_options' => ['kg', 'each'], 'corrections' => []];
$vcWithCol = array_map(function ($r) { return array_merge(array_slice($r, 0, 3), ['kg', $r[3]]); }, $vcRows);
$resWith = import_resolve(import_analyze_file($vcCsv(['Item', 'Qty', 'Unit Cost', 'Unit', 'Stock Value'], $vcWithCol), 'vc.csv', $al), [], $ctxV);
$resWithout = import_resolve(import_analyze_file($vcCsv(['Item', 'Qty', 'Unit Cost', 'Unit'], $vcNoCol), 'vc.csv', $al), [], $ctxV);
$noUid = function (array $items) { return array_map(function ($it) { unset($it['uids']); return $it; }, $items); };     // a row id carries a hash of the file bytes
$t(count($resWith['items']) === 10 && md5(json_encode($noUid($resWith['items']))) === md5(json_encode($noUid($resWithout['items']))) && $resWith['counts'] === $resWithout['counts'] && $resWith['can_confirm'] === $resWithout['can_confirm'],
    'value check: the import result (items, counts, can confirm) is identical with and without the value column');
// no warning when the column matches or is absent
[, $vcOk] = $vcRun($vcHdr, [['ZZV A', 2, 100, 200], ['ZZV B', 3, 50, 150], ['ZZV C', 3, 33.33, 100]]);
$t($vcOk['differ'] === 0 && $vcOk['show'] === false && !import_vc_overview($vcOk)['groups'], 'value check: no warning and no download when the column agrees (rounding included)');
[, $vcAbs] = $vcRun(['Item', 'Qty', 'Unit Cost'], [['ZZV A', 2, 100], ['ZZV B', 3, 50]]);
$t(!$vcAbs['groups'] && $vcAbs['differ'] === 0 && $vcAbs['show'] === false, 'value check: no warning when the sheet has no value column');
[, $vcAbsP] = $vcRun(['Item', 'Qty', 'Unit Cost'], [['ZZV A', 2, 100], ['ZZV B', 3, '']]);
$t(!$vcAbsP['groups'] && $vcAbsP['missing_n'] === 1 && $vcAbsP['show'] === true, 'value check: no value column but stock with no unit cost: no warning, the download is still offered');
// a tracker "Total Cost" that is Purchase Qty x Unit Price is a buying list, not a stock value: not reported
[, $vcPq] = $vcRun(['Item', 'Balance', 'Purchase Qty', 'Unit Price', 'Total Cost'], [['ZZV A', 2, 8, 100, 800], ['ZZV B', 5, 1, 50, 50], ['ZZV C', 1, 0, 30, 0], ['ZZV D', 4, 6, 10, 60]]);
$t(!$vcPq['groups'] && $vcPq['differ'] === 0, 'value check: a Total Cost column that is Purchase Qty x Unit Price is not reported as a wrong stock value');
[, $vcPqU] = $vcRun(['Item', 'Balance', 'Purchase Qty', 'Unit Price', 'Total Cost'], [['ZZV A', 2, '', 100, 800], ['ZZV B', 5, '', 50, 50], ['ZZV C', 1, '', 30, 0]]);
$t(!$vcPqU['groups'] && $vcPqU['differ'] === 0, 'value check: next to a Purchase Qty with no usable numbers (blank or uncached formulas) a Total Cost is not judged');
$t($vcF['rows_n'] === 10 && count(import_vc_collect($vcAn)['sheets'][0]['rows']) === 9, 'value check: rows_n counts every item row of the sheet; only the 9 rows with a value cell are kept');
// a per-case answer: the unit cost is the case price divided by the pack size, exactly as the import uses it
$vcPeers = [];
foreach (['Aaa', 'Bbb', 'Ccc', 'Ddd', 'Eee'] as $pn) { $vcPeers[] = ['Zq ' . $pn, 1, 'bottle', 5000, 5000]; }
$vcCaseRows = array_merge($vcPeers, [['Qwerty', 2, 'bottle', 60000, 120000]]);
$vcCaseHdr = ['Item Name', 'Balance', 'Unit', 'Unit Price', 'Stock Value'];
[$vcCaseAn, $vcCase0] = $vcRun($vcCaseHdr, $vcCaseRows);
$resCase = import_resolve($vcCaseAn, [], $ctxV + ['unit_options' => ['bottle']]);
$cpqV = null;
foreach ($resCase['questions'] as $q) { if ($q['type'] === 'case-price') { $cpqV = $q; } }
$t($cpqV !== null && $vcCase0['differ'] === 0, 'value check: before the per-case answer the 60,000 row agrees with its own Stock Value (2 x 60,000)');
[, $vcCase1] = $vcRun($vcCaseHdr, $vcCaseRows, $cpqV ? [$cpqV['qkey'] => ['key' => 'per-case', 'pack_size' => 12]] : []);
$r0 = $vcCase1['rows'][0] ?? [];
$t($vcCase1['differ'] === 1 && ($r0['pack'] ?? 0) === 12 && abs(($r0['cost'] ?? 0) - 5000) < 0.00005 && abs(($r0['calc'] ?? 0) - 10000) < 0.005 && abs(($r0['diff'] ?? 0) - 110000) < 0.005,
    'value check: after "per case of 12" the row is compared with 2 x 5,000 (the cost the import uses), so the 120,000 shows 110,000 more', json_encode($r0));
// the stock-check workbook: three tabs, the right rows and sums, values only, the real header names, the file name
$wb = import_vc_workbook($vcF, ['file' => 'RBH test list.xlsx', 'date' => '2026-11-16', 'currency' => 'MWK']);
$t(!empty($wb['ok']) && $wb['name'] === 'RBH test list stock check 2026-11-16.xlsx', 'stock check workbook: named "<file> stock check <date>.xlsx"', (string)($wb['name'] ?? ''));
$rd = import_xlsx_read((string)($wb['body'] ?? ''), true);
$tabs = array_column($rd['sheets'] ?? [], 'name');
$t(!empty($rd['ok']) && $tabs === ['Summary', 'Stock Value wrong', 'Missing prices'], 'stock check workbook: reads back with three tabs (Summary, "Stock Value wrong", Missing prices)', json_encode($tabs));
$cellsOf = function ($sh) { $o = []; foreach ($sh['cells'] as $r => $cs) { foreach ($cs as $c => $cl) { $o[$r][$c] = $cl['v']; } } return $o; };
$wd = isset($rd['sheets'][1]) ? $cellsOf($rd['sheets'][1]) : [];
$wm = isset($rd['sheets'][2]) ? $cellsOf($rd['sheets'][2]) : [];
$diffSum = 0; $diffNames = [];
foreach ($wd as $r => $cs) { if ($r > 1) { $diffSum += (float)($cs[9] ?? 0); $diffNames[] = $cs[2] ?? ''; } }
$t(count($wd) === 6 && $diffNames === array_column($vcF['rows'], 'name') && abs($diffSum - (5300 - 3050)) < 0.005 && ($wd[1][8] ?? '') === 'Sheet "Stock Value" says' && ($wd[1][12] ?? '') === "Manager's explanation",
    'stock check workbook: the wrong-value tab has the 5 rows in order, differences add up to 5,300 - 3,050, and a blank explanation column', json_encode([count($wd), $diffSum]));
$t(count($wm) === 2 && ($wm[2][2] ?? '') === 'ZZV No price' && ($wm[1][6] ?? '') === 'Unit Cost (fill in)', 'stock check workbook: the missing-prices tab lists the one item, with a "Unit Cost (fill in)" column');
$ws = isset($rd['sheets'][0]) ? $cellsOf($rd['sheets'][0]) : [];
$sumText = json_encode($ws);
$t(strpos($sumText, '=B2*C2 in D2') !== false && strpos($sumText, '16749.99') !== false && strpos($sumText, 'NOT proof of loss') !== false, 'stock check workbook: the summary gives the totals and the fix with the sheet\'s own letters (=B2*C2 in D2)');
$noF = true; foreach ($rd['sheets'] ?? [] as $sh) { foreach ($sh['cells'] as $cs) { foreach ($cs as $cl) { if (!empty($cl['is_formula'])) { $noF = false; } } } }
$t($noF, 'stock check workbook: values only, no formulas');
[, $vcInj] = $vcRun($vcHdr, [['=HYPERLINK("x")', 1, 10, 99]]);
$rdi = import_xlsx_read((string)import_vc_workbook($vcInj, ['file' => 'x.csv'])['body'], true);
$injOk = false; foreach ($rdi['sheets'][1]['cells'] ?? [] as $cs) { foreach ($cs as $cl) { if ($cl['v'] === '=HYPERLINK("x")' && empty($cl['is_formula'])) { $injOk = true; } } }
$t($injOk, 'stock check workbook: an item name that looks like a formula is written as plain text');

// ---- flag quality (owner 2026-11-16, "RBH REVISED STOCK NOV26"): typo false positives, case-price consequences, value-check reasons ----
echo "-- flag quality --\n";
// possible-typo: one whole different word (not a near-spelling) = a different product. Synthetic names of the same shapes as the owner file.
$distinctPairs = [['syrup peach', 'syrup pear'], ['green tea', 'green tie'], ['harbour red', 'harbour rose'], ['cape sweet red', 'cape sweet rose'], ['bramfidich12years 12years', 'bramiivet12years 12years']];
foreach ($distinctPairs as [$d1, $d2]) {
    $t(import_name_score_norm($d1, $d2) >= 0.85 && import_cc_distinct_one_word($d1, $d2), 'typo guard: "' . $d1 . '" / "' . $d2 . '" scored as similar before, now one different word = different products');
}
$nearPairs = [['hill stoot', 'hill stout'], ['heinkn', 'heineken'], ['kestrel lagr', 'kestrel lager'], ['windowhoek lager', 'windhoek lager'], ['budwiser', 'budweiser'], ['all purpose claner', 'all purpose cleaner'], ['reedbed stuot', 'reedbed stout'], ['heinekin', 'heineken']];
foreach ($nearPairs as [$d1, $d2]) {
    $t(!import_cc_distinct_one_word($d1, $d2), 'typo guard keeps a near-spelling as a question: "' . $d1 . '" / "' . $d2 . '"');
}
$t(!import_cc_distinct_one_word('red wine dry', 'dry wine red'), 'typo guard: two words differing is not decided by this rule');
$fq = $fmRun([['Syrup Peach', '', '3', 'bottle', '900', '', ''], ['Syrup Pear', '', '2', 'bottle', '950', '', ''], ['Green Tea', '', '5', 'each', '40', '', ''], ['Green Tie', '', '1', 'each', '700', '', '']]);
$t($fmQ($fq, 'possible-typo') === null, 'typo guard: Syrup Peach / Syrup Pear and Green Tea / Green Tie raise no possible-typo question');
// a typo that stays: the text shows each row's quantity and cost and says "Use ... for both" adds the quantities; answering it gives one ready item
$tyRows = [['Hilltop Stout', '', '4', 'bottle', '660', '', ''], ['Hilltop Stoot', '', '6', 'bottle', '680', '', ''], ['Pale Ale', '', '6', 'bottle', '600', '', '']];
$fq = $fmRun($tyRows);
$tq = $fmQ($fq, 'possible-typo');
$t($tq !== null && strpos($tq['text'], 'row 2: 4 bottle, 660 each') !== false && strpos($tq['text'], 'row 3: 6 bottle, 680 each') !== false
    && strpos($tq['text'], 'merges them into ONE item and ADDS the quantities (4 + 6 = 10 bottle)') !== false && strpos($tq['options'][1]['label'], 'quantities added') !== false,
    'typo text: each row\'s quantity and unit cost, and "Use ... for both" merges and ADDS the quantities (4 + 6 = 10)', (string)($tq['text'] ?? ''));
$fq2 = $fmRun($tyRows, $tq ? [$tq['qkey'] => ['key' => 'use:Hilltop Stout']] : []);
$hs = $fmItem($fq2, 'Hilltop Stout');
$t($hs && (float)$hs['balance'] === 10.0 && $fmItem($fq2, 'Hilltop Stoot') === null && $fmQ($fq2, 'merge-conflict') !== null, 'typo "Use X for both": one item, balance 4 + 6 = 10; the two prices are asked about side by side', json_encode($hs['balance'] ?? null));
$fq3 = $fmRun([['Hilltop Stout', '', '4', 'bottle', '660', '', ''], ['Hilltop Stoot', '', '6', 'bottle', '660', '', '']], []);
$tq3 = $fmQ($fq3, 'possible-typo');
$fq3 = $fmRun([['Hilltop Stout', '', '4', 'bottle', '660', '', ''], ['Hilltop Stoot', '', '6', 'bottle', '660', '', '']], $tq3 ? [$tq3['qkey'] => ['key' => 'use:Hilltop Stout']] : []);
$t(count($fq3['items']) === 1 && $fq3['items'][0]['status'] === 'ready' && $fq3['can_confirm'] && (float)$fq3['items'][0]['balance'] === 10.0, 'typo "Use X for both" (same price): one READY item of 10, the import can be confirmed (no dead end)', json_encode($fq3['counts']));
$fq4 = $fmRun([['Hilltop Stout', '', '4', 'bottle', '660', '', ''], ['Hilltop Stoot', '', '6', 'bottle', '660', '', '']], $tq3 ? [$tq3['qkey'] => ['key' => 'keep']] : []);
$t(count($fq4['items']) === 2 && $fq4['can_confirm'], 'typo "keep both": two items as written');
$fu = [['Hilltop Stout', '', '4', 'bottle', '660', '', ''], ['Hilltop Stoot', '', '2', 'crate', '660', '', '']];
$tqU = $fmQ($fmRun($fu), 'possible-typo');
$fqU = $fmRun($fu, $tqU ? [$tqU['qkey'] => ['key' => 'use:Hilltop Stout']] : []);
$blkU = array_values(array_filter($fqU['rows'], function ($r) { return ($r['blocked_code'] ?? '') === 'typo-units-differ'; }));
$t($tqU !== null && strpos($tqU['text'], 'units differ (bottle / crate), so their counts cannot be added') !== false && count($blkU) === 2 && !$fqU['can_confirm'] && strpos($blkU[0]['reason'], 'different units (bottle / crate)') !== false && !array_filter($fqU['items'], function ($it) { return (float)$it['balance'] === 6.0; }),
    'typo "Use X for both" with different units (4 bottle + 2 crate): never added, both rows blocked with a plain reason', json_encode($fqU['counts']));
$fs = [['Hilltop Stout', '', '4', 'bottle', '660', '', ''], ['Hilltop Stoot', '', '2', 'bottles', '660', '', '']];
$tqS = $fmQ($fmRun($fs), 'possible-typo');
$fqS = $fmRun($fs, $tqS ? [$tqS['qkey'] => ['key' => 'use:Hilltop Stout']] : []);
$t(count($fqS['items']) === 1 && (float)$fqS['items'][0]['balance'] === 6.0 && $fqS['can_confirm'], 'typo "Use X for both" with the same unit (bottle / bottles): one item, 4 + 2 = 6');
// "did you mean the existing ..." never offers a different product that differs by one whole word, still offers a near-spelling
$exT = function ($id, $name) { return ['id' => $id, 'name' => $name, 'category' => 'Pantry', 'unit' => 'each', 'current_quantity' => 1, 'cost_per_unit' => 50, 'par_level' => 0, 'reorder_point' => 0, 'notes' => '', 'pack_name' => null, 'pack_size' => null, 'is_archived' => 0, 'recipes' => 0]; };
$fq5 = $fmRun([['Green Tie', '', '1', 'each', '700', '', ''], ['Kestrel Lagr', '', '2', 'bottle', '500', '', '']], [], ['existing' => [$exT(5, 'Green Tea'), $exT(6, 'Kestrel Lager')]]);
$exQs = array_values(array_filter($fq5['questions'], function ($q) { return $q['type'] === 'possible-typo' && ($q['against'] ?? '') === 'existing'; }));
$t(count($exQs) === 1 && strpos($exQs[0]['text'], '"Kestrel Lagr" is not in the existing list, but "Kestrel Lager" is. Did you mean "Kestrel Lager"? In the sheet: row 3: 2 bottle, 500 each.') !== false && strpos($exQs[0]['text'], 'instead of creating a new item') !== false,
    'existing list: Green Tie is not offered as Green Tea; Kestrel Lagr -> Kestrel Lager is, with its row, quantity and cost', json_encode(array_column($exQs, 'text')));
// case-price: the unit as written, the counted quantity, the line value and what each answer does (option keys unchanged)
$cpRows = [['Peer A', '', '10', 'kgs', '1000', '', ''], ['Peer B', '', '10', 'kgs', '1100', '', ''], ['Peer C', '', '10', 'kgs', '900', '', ''], ['Peer D', '', '10', 'kgs', '1050', '', ''],
    ['Peer E', '', '10', 'kgs', '950', '', ''], ['Bulk Meal', '', '12', 'kgs', '20000', '', '']];
$fq6 = $fmRun($cpRows);
$cpq = $fmQ($fq6, 'case-price');
$t($cpq !== null && strpos($cpq['text'], '20,000 per kgs') !== false && strpos($cpq['text'], 'The sheet counts 12 kgs, so as written this line is worth 240,000') !== false
    && strpos($cpq['text'], 'Is that the price of one unit, or of a whole case/pack?') !== false && strpos($cpq['text'], 'counted in single units') !== false,
    'case-price text: unit as written, counted quantity and line value, and that the quantity stays in the sheet\'s unit', (string)($cpq['text'] ?? ''));
$t($cpq !== null && array_column($cpq['options'], 'key') === ['per-unit', 'per-case'] && strpos($cpq['options'][0]['label'], '12 kgs counted at 20,000 = 240,000') !== false
    && strpos($cpq['options'][1]['label'], 'for example 20 -> 1,000 per kgs, 12 kgs = 12,000') !== false,
    'case-price options: same keys; "one unit" shows 12 x 20,000, "whole pack" shows an example pack (price / usual price) and what it does', json_encode($cpq['options'] ?? null));
// value check: a grounded "what it most likely means" per row
$t(import_vc_why(68.3, 3000, 84900, 'packet') === ['qty-changed', 'The value was worked out for 28.3 packet; the count now says 68.3 (40 more). The quantity was changed without updating the value.'], 'value reason: value / cost lands on 28.3 -> the quantity was changed (40 more)');
$t(import_vc_why(20, 1000, 46000, 'bottle')[1] === 'The value was worked out for 46 bottle; the count now says 20 (26 fewer). The quantity was changed without updating the value.', 'value reason: fewer');
$t(import_vc_why(0, 500, 47500, 'bottle') === ['no-qty', 'The sheet counts 0 but still values 95 bottle. If they were sold or used, the value was not updated; if not, they are missing from the count.'], 'value reason: counts 0 but still valued');
$t(import_vc_why(22, 6000, 0, 'kgs')[0] === 'no-value' && strpos(import_vc_why(22, 6000, 0, 'kgs')[1], 'counts 22 kgs but values them at 0') !== false, 'value reason: counted but valued at 0');
$t(import_vc_why(3, 1000, 4321, 'kg')[0] === 'no-fit' && strpos(import_vc_why(3, 1000, 4321, 'kg')[1], 'does not fit a whole quantity') !== false, 'value reason: a value that fits no quantity -> cost changed or a typing mistake');
$t(import_vc_why(3, 5, 17, 'kg')[0] === 'no-fit', 'value reason: with a tiny unit cost (0.1 step under 1) nothing is claimed to fit');
$whys = array_column($vcF['rows'], 'why_code');
$t($whys === ['qty-changed', 'qty-changed', 'no-qty', 'qty-changed', 'qty-changed'] && strpos($vcF['rows'][0]['why'], 'worked out for 5 kg; the count now says 1 (4 fewer)') !== false && $pg['rows'][0]['why'] === $vcF['rows'][0]['why'],
    'value reason: built once in the rows (the Step 3 page carries the same text)', json_encode(array_column($vcF['rows'], 'why')));
$t(($wd[1][11] ?? '') === 'What it most likely means' && ($wd[1][12] ?? '') === "Manager's explanation" && ($wd[2][11] ?? '') === $vcF['rows'][0]['why'] && !isset($wd[2][12]),
    'stock check workbook: "What it most likely means" before the blank "Manager\'s explanation"');
$t(strpos($sumText, '5 of 5 rows: the value fits a different quantity at the same unit cost (1 of them now counts 0), so the quantity was changed') !== false && strpos($sumText, 'most likely not recalculated') === false,
    'stock check workbook: the summary states the counted fact (5 of 5 fit a different quantity), not a guess');
$t(strpos(import_rv_role_label('use-suggestion', 'possible-typo'), 'quantities added') !== false && import_rv_role_label('use-suggestion', 'conflict') === 'Use the suggested match for all', 'bulk "use the suggestion" on possible-typo questions says rows of one sheet become one item with the quantities added');
// units: "trays" is the same unit as "tray"
$t(import_unit_canon('trays', $al['unit_synonyms']) === 'tray' && import_unit_canon('Tray', $al['unit_synonyms']) === 'tray', 'unit spelling: trays -> tray, Tray -> tray');

echo "-- case or unit from this hotel's own data (2026-11-17) --\n";
// a sheet with no headings (category = sheet name) that mixes unit-priced beers with spirits: the engine's spread rule cannot see a crate
$csRows = [];
foreach ([['Lager A', 2500], ['Lager B', 2800], ['Lager C', 3000], ['Lager D', 3500], ['Lager E', 4000]] as [$nm, $pr]) { $csRows[] = [$nm, '', '10', 'bottle', (string)$pr, '', '']; }
foreach ([['Gin Dry', 30000], ['Vodka Blue', 45000], ['Rum Dark', 60000], ['Whisky Twelve', 190000], ['Whisky Fifteen', 250000], ['Brandy Old', 120000]] as [$nm, $pr]) { $csRows[] = [$nm, '', '5', 'bottle', (string)$pr, '', '']; }
$csRows[] = ['Kuchi', '', '60', 'bottle', '41,322', '', ''];
$csEx = function ($id, $name, $cost, array $o = []) use ($exRow) { return array_merge($exRow($id, $name), ['cost_per_unit' => $cost, 'category' => 'fix'], $o); };
$csCase = function (array $res, string $name) {
    foreach ($res['questions'] as $q) { if ($q['type'] === 'case-price' && strpos($q['text'], '"' . $name . '"') !== false) { return $q; } }
    return null;
};
$cs0 = $fmRun($csRows);
$t($csCase($cs0, 'Kuchi') === null && $csCase($cs0, 'Whisky Twelve') === null, 'case evidence: with no evidence at all the mixed sheet asks nothing about Kuchi or the whisky (the gap the owner saw)');
// own pack 24 and the cost in stock is a bottle price: 41,322 = 24 x 1,721.75 -> decided "per case of 24"
$csA = $fmRun($csRows, [], ['existing' => [$csEx(50, 'Kuchi', 1721.75, ['pack_name' => 'crate', 'pack_size' => 24]), $csEx(51, 'Whisky Twelve', 185000)]]);
$qA = $csCase($csA, 'Kuchi');
$iA = $fmItem($csA, 'Kuchi');
$t($qA && $qA['answer'] === 'per-case' && (int)$qA['answer_detail']['pack_size'] === 24 && !empty($qA['auto']) && $iA && abs($iA['cost'] - 1721.75) < 0.00005 && (int)$iA['pack'] === 24,
    'case evidence: a Kuchekuche-like crate price in a sheet with spirits is pre-answered "per case of 24" from its own pack size (cost 1,721.75)', json_encode([$qA['answer'] ?? null, $iA['cost'] ?? null]));
$t($csCase($csA, 'Whisky Twelve') === null, 'case evidence: a real spirit bottle price (190,000, in stock at 185,000) is not flagged');
// a stored "per unit" answer always wins over the pre-answer
$csA2 = $fmRun($csRows, [$qA['qkey'] => ['key' => 'per-unit']], ['existing' => [$csEx(50, 'Kuchi', 1721.75, ['pack_name' => 'crate', 'pack_size' => 24])]]);
$t(($csCase($csA2, 'Kuchi')['answer'] ?? '') === 'per-unit' && abs(($fmItem($csA2, 'Kuchi')['cost'] ?? 0) - 41322) < 0.00005, 'case evidence: the person\'s "per unit" answer replaces the pre-answer (cost 41,322 as written)');
// real deliveries say ~1,720 a bottle but no pack size is known -> asked (not decided), and Confirm waits for it
$evRec = ['receipts' => [50 => [1700, 1750, 1720]], 'sell' => [], 'packs' => []];
$csB0 = $fmRun($csRows, [], ['existing' => [$csEx(50, 'Kuchi', 41322)], 'case_evidence' => $evRec]);
$t($csCase($csB0, 'Kuchi') === null && !empty($fmItem($csB0, 'Kuchi')['cost_suspect']), 'case evidence: an existing item with Cost not ticked gets the stored-cost warning, not a question (its file cost is not written)');
$csB = $fmRun($csRows, ['_updates' => [rh_ssi_key('Kuchi') => ['cost']]], ['existing' => [$csEx(50, 'Kuchi', 41322)], 'case_evidence' => $evRec]);
$qB = $csCase($csB, 'Kuchi');
$t($qB && $qB['answer'] === null && strpos($qB['text'], 'usual delivery cost of 1,720') !== false && !$csB['can_confirm'], 'case evidence: deliveries at ~1,720 a bottle make 41,322 a question (no pack size known, so not decided)', (string)($qB['text'] ?? ''));
// opening counts are not deliveries: a cost history equal to the crate price (what a wrong import writes) is not evidence either way
$t($csCase($fmRun($csRows, [], ['existing' => [$csEx(50, 'Kuchi', 41322)], 'price_evidence' => ['history' => [50 => [41322, 41322, 41322]]]]), 'Kuchi') === null, 'case evidence: batch / opening-count history is not read as deliveries (nothing to say without real evidence)');
// selling price from a recipe link: 36,000 a bottle but the menu sells it for 2,000 a bottle
$fz = [['Fizzy', '', '24', 'bottle', '36,000', '', '']];
$evSell = ['sell' => [60 => ['per_unit' => 2000.0, 'item' => 'Fizzy (glass)', 'price' => 2000.0, 'qty' => 1.0]], 'receipts' => [], 'packs' => []];
$fzT = ['_updates' => [rh_ssi_key('Fizzy') => ['cost']]];
$csS = $fmRun($fz, $fzT, ['existing' => [$csEx(60, 'Fizzy', 36000)], 'case_evidence' => $evSell]);
$qS = $csCase($csS, 'Fizzy');
$t($qS && $qS['answer'] === null && strpos($qS['text'], 'menu sells it for about 2,000 per bottle') !== false && in_array('sell', $qS['case_evidence']['strong'], true), 'case evidence: a cost 18 times the recipe-linked selling price asks "per case?" with the reason', (string)($qS['text'] ?? ''));
$csS2 = $fmRun($fz, [], ['existing' => [$csEx(60, 'Fizzy', 36000, ['pack_size' => 24])], 'case_evidence' => $evSell]);
$t(($csCase($csS2, 'Fizzy')['answer'] ?? null) === 'per-case' && abs(($fmItem($csS2, 'Fizzy')['cost'] ?? 0) - 1500) < 0.00005, 'case evidence: selling price + its own pack of 24 (1,500 each, under the 2,000 selling price) is decided per case');
$csS3 = $fmRun($fz, $fzT, ['existing' => [$csEx(60, 'Fizzy', 36000, ['pack_size' => 12])], 'case_evidence' => $evSell]);
$t(($qS3 = $csCase($csS3, 'Fizzy')) && $qS3['answer'] === null && (int)$qS3['pack_hint'] === 12, 'case evidence: a pack of 12 would still cost 3,000 each, above the 2,000 selling price -> asked, not decided (12 offered for the pack box)');
// a tot-priced recipe (25 ml of a 750 ml bottle = 0.0333 bottle at 5,000 -> 150,000 a bottle) does not flag a 190,000 bottle
$evTot = ['sell' => [51 => ['per_unit' => 150000.0, 'item' => 'Whisky tot', 'price' => 5000.0, 'qty' => 0.0333]], 'receipts' => [], 'packs' => []];
$t($csCase($fmRun($csRows, [], ['existing' => [$csEx(51, 'Whisky Twelve', 185000)], 'case_evidence' => $evTot]), 'Whisky Twelve') === null, 'case evidence: a spirit sold by the tot (150,000 a bottle through its recipe) is not flagged at 190,000');
// Green: a per-case answer on an existing item; ticking Pack alone carries the divided cost with it
$grRows = [];
foreach (['G1', 'G2', 'G3', 'G4', 'G5'] as $gn) { $grRows[] = ['Lite ' . $gn, '', '3', 'bottle', '2,500', '', '']; }
$grRows[] = ['Green', '', '94', 'bottle', '50,420', '', ''];
$grEx = [$csEx(70, 'Green', 50420)];
$gr0 = $fmRun($grRows, [], ['existing' => $grEx]);
$gq = $csCase($gr0, 'Green');
$grAns = $gq ? [$gq['qkey'] => ['key' => 'per-case', 'pack_size' => 20], '_updates' => [rh_ssi_key('Green') => ['pack']]] : [];
$gr1 = $fmRun($grRows, $grAns, ['existing' => $grEx]);
$gi = $fmItem($gr1, 'Green');
$t($gq !== null && $gi && in_array('pack', $gi['ticked'], true) && in_array('cost', $gi['ticked'], true) && abs($gi['cost'] - 2521) < 0.00005 && $gi['cost_applied'] === true
    && count(array_filter($gi['warnings'], function ($w) { return strpos($w, 'Pack is ticked, so Cost is changed with it: 50,420 per case of 20 = 2,521 each') !== false; })) === 1,
    'Green fix: ticking Pack on a per-case row also ticks Cost (2,521 each) and says so', json_encode([$gi['ticked'] ?? null, $gi['warnings'] ?? null]));
// ... and when the price check is answered "keep the current price", the pack size is not saved either
$grEv = ['history' => [70 => [50000, 50420, 50300, 50100]]];
$gr2 = $fmRun($grRows, $grAns, ['existing' => $grEx, 'price_evidence' => $grEv]);
$gpq = $pcQ($gr2);
$gr3 = $gpq ? $fmRun($grRows, $grAns + [$gpq['qkey'] => ['key' => 'current']], ['existing' => $grEx, 'price_evidence' => $grEv]) : null;
$gi3 = $gr3 ? $fmItem($gr3, 'Green') : null;
$t($gpq !== null && $gi3 && !in_array('pack', $gi3['ticked'], true) && !in_array('cost', $gi3['ticked'], true)
    && count(array_filter($gi3['warnings'], function ($w) { return strpos($w, 'pack size of 20 is not saved') !== false; })) === 1, 'Green fix: "keep the current price" also leaves the pack size alone (never a pack without its divided cost)', json_encode($gi3['warnings'] ?? null));
// an EXISTING item whose stored cost already looks like a case price: a plain warning, never a question
$csW = $fmRun([['Budget Lager', '', '12', 'bottle', '156,000', '', '']], [], ['existing' => [$csEx(80, 'Budget Lager', 156000)], 'case_evidence' => ['sell' => [80 => ['per_unit' => 3000.0, 'item' => 'Budget Lager', 'price' => 3000.0, 'qty' => 1.0]], 'receipts' => [], 'packs' => []]]);
$iW = $fmItem($csW, 'Budget Lager');
$t($iW && !empty($iW['cost_suspect']) && count(array_filter($iW['warnings'], function ($w) { return strpos($w, 'The cost now in stock (156,000 per bottle) may be the price of a whole case') !== false; })) === 1,
    'case evidence: an existing item whose stored cost is 52 times its selling price gets a plain "may be a case price" warning', json_encode($iW['warnings'] ?? null));
// the shared stock check (the same judge the Stock > Ingredients note uses)
$stk = [['id' => 1, 'category' => 'Bar', 'unit' => 'bottle', 'cost_per_unit' => 156000, 'pack_size' => null, 'is_archived' => 0],
    ['id' => 2, 'category' => 'Bar', 'unit' => 'bottle', 'cost_per_unit' => 190000, 'pack_size' => null, 'is_archived' => 0],
    ['id' => 3, 'category' => 'Bar', 'unit' => 'bottle', 'cost_per_unit' => 156000, 'pack_size' => null, 'is_archived' => 1]];
$fl = import_cs_stock_flags($stk, ['sell' => [1 => ['per_unit' => 3000.0, 'item' => 'Lager', 'price' => 3000.0, 'qty' => 1.0], 2 => ['per_unit' => 150000.0, 'item' => 'Whisky tot', 'price' => 5000.0, 'qty' => 0.0333], 3 => ['per_unit' => 3000.0, 'item' => 'x', 'price' => 3000.0, 'qty' => 1.0]], 'receipts' => [], 'packs' => []]);
$t(isset($fl[1]) && !isset($fl[2]) && !isset($fl[3]), 'stock check: a lager stored at 52 times its selling price is flagged; a whisky sold by the tot and an archived item are not');
// groups of the same category and unit: a few prices far above a bigger group are suspect; a second kind of product (spirits) is not
$grp = [];
foreach ([2500, 2800, 3000, 3500, 4000] as $i => $c) { $grp['b' . $i] = ['group' => 'bar|bottle', 'cost' => $c]; }
$grp['crate1'] = ['group' => 'bar|bottle', 'cost' => 72000];
$grp['crate2'] = ['group' => 'bar|bottle', 'cost' => 66000];
$refs = import_cs_cluster_refs($grp);
$t(isset($refs['crate1'], $refs['crate2']) && count($refs) === 2 && abs($refs['crate1']['median'] - 3000) < 0.001, 'groups: two prices ~20x five bottle prices of the same category and unit are suspect');
foreach ([30000, 45000, 60000, 120000, 190000, 250000] as $i => $c) { $grp['s' . $i] = ['group' => 'bar|bottle', 'cost' => $c]; }
$t(import_cs_cluster_refs($grp) === [], 'groups: when the higher prices are as many as the lower ones (spirits next to beers), nothing is flagged by the groups');
$t(import_cs_judge(['cost' => 36000, 'own_pack' => 12, 'value_pack' => ['n' => 12, 'value' => 72000, 'qty' => 24, 'source' => 'S!F2'], 'unit' => 'bottle'])['verdict'] === 'case'
    && import_cs_judge(['cost' => 36000, 'value_pack' => ['n' => 3, 'value' => 288000, 'qty' => 24, 'source' => 'S!F2'], 'unit' => 'bottle']) === null,
    'value cell: qty x price / 12 with its own pack of 12 decides; a whole ratio that is no known pack size says nothing (a changed count looks the same)');


// ---- bar-sheet layouts and a corpus of other layouts (owner 2026-11-17: "read files like this without so many questions", "diverse enough") ----
echo "-- bought as / pack size / served by: the corpus (2026-11-17) --\n";
require_once __DIR__ . '/fixtures/import/build_bar_layouts.php';
$cpCtx = ['existing' => [], 'unit_options' => ['bottle', 'can', 'crate', 'case', 'kg', 'each', 'pcs', 'carton', 'packet', 'tray'], 'corrections' => []];
$cpRun = function (string $key, array $ctx = [], array $answers = []) use ($put, $al, $cpCtx) {
    $f = fx_corpus()[$key];
    $p = $put($f['bytes'], $f['name']);
    $an = import_analyze_file($p, $f['name'], $al);
    $res = import_resolve($an, $answers, $ctx + $cpCtx);
    $all = array_merge($res['questions'], array_values($res['extra_questions']));
    $open = array_values(array_filter($all, function ($q) { return $q['answer'] === null; }));
    $auto = array_values(array_filter($all, function ($q) { return $q['answer'] !== null && !empty($q['auto']); }));
    $items = [];
    foreach ($res['items'] as $it) { $items[$it['name']] = $it; }
    $cols = [];
    foreach ($an['sheets'] as $s) { foreach ($s['columns'] as $c) { $cols[$s['name']][$c['letter']] = $c['state'] === 'mapped' ? $c['field'] : $c['state']; } }
    $reader = substr($f['name'], -5) === '.xlsx' ? import_xlsx_read($p) : import_csv_read($p, $f['name']);
    return ['an' => $an, 'res' => $res, 'open' => $open, 'auto' => $auto, 'items' => $items, 'cols' => $cols, 'inv' => import_assert_no_invented_values($an, $reader), 'path' => $p];
};
$cpItem = function (array $r, string $name, $unit, $pack, $cost, $qty) {
    $it = $r['items'][$name] ?? null;
    return $it && (string)$it['unit'] === (string)$unit && $it['pack'] === $pack && ($cost === null ? $it['cost'] === null : abs((float)$it['cost'] - $cost) < 0.0001) && abs((float)$it['balance'] - $qty) < 0.0001;
};
$cpCounts = [];

// 1) the bar tracker layout (title A1, headings row 3, headings merged in A or typed in B, value column at selling price, TOTAL line)
$bt = $cpRun('bar_tracker');
$cpCounts['bar_tracker'] = count($bt['open']);
$t($bt['cols']['Sheet1'] === ['A' => 'item_name', 'B' => 'qty_single', 'C' => 'unit_price', 'D' => 'sell_price', 'E' => 'total_cost', 'F' => 'pack_name', 'G' => 'pack_size', 'H' => 'served_by'],
    'bar layout: every heading is read with no question (Total Balance = the quantity, Selling price never the unit price, bought as / pack size / served by) ' . json_encode($bt['cols']));
$btSh = $bt['an']['sheets'][0];
$t(array_column($btSh['categories'], 'name') === ['LOCAL LAGERS', 'SOFT DRINKS', 'SPIRITS', 'HOUSE WINE', 'RED BY BOTTLE', 'WHITE BY BOTTLE'],
    'bar layout: headings typed in column B (name cell empty) and merged headings (one with a word in the bought-as column) are categories, never items');
$btKinds = [];
foreach ($btSh['skipped_rows'] as $sr) { $btKinds[$sr['row']] = $sr['kind']; }
$t(($btKinds[4] ?? '') === 'section' && ($btKinds[10] ?? '') === 'section' && ($btKinds[19] ?? '') === 'total' && substr((string)($btSh['skipped_rows'][0]['source'] ?? ''), -3) === '!B4',
    'bar layout: the B-column heading points at its own cell, the merged "SPIRITS" + "Bottle" row is a heading, TOTAL is a total line');
$t($cpItem($bt, 'Zebra Lager', 'bottle', 24, 2000, 40) && $cpItem($bt, 'Kudu Lager', 'bottle', 12, 3000, 10),
    'bar layout: crate price / pack size = cost per bottle (48,000 / 24 = 2,000), decided from the row, quantity in bottles');
$t(strpos((string)$bt['items']['Zebra Lager']['cost_how'], '48,000 / 24 = 2,000 per bottle') !== false && $bt['items']['Zebra Lager']['pack_name'] === 'Crate',
    'bar layout: the divided cost carries its plain one-line reason and the pack word "Crate" from the cell');
$t($cpItem($bt, 'Fizz Tonic', 'can', null, 2500, 6) && (int)$bt['items']['Fizz Tonic']['pack_info'] === 24,
    'bar layout: same bought and sold unit (Can / Can): the price is per can, the 24 is information only (no "can of 24 cans")');
$t($cpItem($bt, 'Moon Gin', 'bottle', null, 30000, 12) && $cpItem($bt, 'Valley Red', 'Casket', null, 60000, 3),
    'bar layout: served by the shot / glass: counted and costed per bottle / casket (the bought unit)');
$t(isset($bt['items']['Twin Peak (Red by bottle)'], $bt['items']['Twin Peak (White by bottle)']) && count($bt['auto']) === 1 && $bt['auto'][0]['type'] === 'duplicate-no-batch'
    && $bt['auto'][0]['answer'] === 'section-items' && (float)$bt['items']['Twin Peak (Red by bottle)']['balance'] === 2.0,
    'bar layout: the same name under two different headings = two items named with their heading, decided with a reason (no question)');
$t($cpItem($bt, 'Paper Straw', 'each', null, null, 50), 'bar layout: Pack of 100 sold Each with price 0: unit each, no cost (0 = no price)');
$t(count($bt['open']) === 1 && $bt['open'][0]['type'] === 'case-price' && strpos($bt['open'][0]['text'], 'Over Lager') !== false && strpos($bt['open'][0]['text'], 'selling price of 3,000') !== false
    && (int)($bt['open'][0]['pack_hint'] ?? 0) === 24,
    'bar layout: only the row that does not add up is asked (5,000 per bottle after dividing, above its selling price 3,000), with the pack pre-filled');
$t($bt['inv'] === [], 'bar layout: INVARIANT holds (unit from the served-by / bought-as cell, pack size from its cell)' . json_encode($bt['inv']));
$btU = $cpRun('bar_tracker', [], [$bt['open'][0]['qkey'] => ['key' => 'per-unit']]);
$t($cpItem($btU, 'Over Lager', 'bottle', null, 120000, 5), 'bar layout: answering "one unit" takes the row\'s pack out again (cost = the price as written)');
// value column at the SELLING price: one summary line, no row reported
$btVc = import_vc_finish(import_vc_collect($bt['an']), $bt['res']['questions'], $bt['res']['items']);
$t($btVc['differ'] === 0 && count($btVc['selling']) === 1 && $btVc['selling'][0]['header'] === 'Stock Value' && $btVc['selling'][0]['agree'] === $btVc['selling'][0]['compared'],
    'value column: Quantity x Selling price on every row = stock at selling price, said once, never compared with the cost ' . json_encode($btVc['selling']));
$t(import_vc_overview($btVc)['selling'][0]['letter'] === 'E', 'value column: the selling-price line reaches the screen summary');

// 2) mangled headings: Bal, U/Price, S.Price, "Unit Bougth As" (a typing slip, confirmed by its container words), Pcs per ctn, Sold by
$mg = $cpRun('mangled');
$cpCounts['mangled'] = count($mg['open']);
$t($mg['cols']['mangled'] === ['A' => 'item_name', 'B' => 'qty_single', 'C' => 'unit_price', 'D' => 'sell_price', 'E' => 'pack_name', 'F' => 'pack_size', 'G' => 'served_by'] && !$mg['open'],
    'mangled headings: all read, no question ' . json_encode($mg['cols']));
$mgCol = null;
foreach ($mg['an']['sheets'][0]['columns'] as $c) { if ($c['letter'] === 'E') { $mgCol = $c; } }
$t(strpos((string)($mgCol['how_values'] ?? ''), 'close to "unit bought as"') !== false, 'mangled headings: a heading read with the help of its values says so (shown in step 2)');
$t($cpItem($mg, 'Alpha Juice', 'bottle', 12, 2000, 10) && $cpItem($mg, 'Delta Crisps', 'packet', 10, 600, 5) && $cpItem($mg, 'Gamma Water', 'bottle', null, 500, 30) && $mg['inv'] === [],
    'mangled headings: Ctn of 12 -> 2,000 per bottle, Box of 10 -> 600 per packet, Bottle of 1 -> per bottle');

// 3) the pack written in the NAME ("Hill Lager 6 pack"): decided only when the price agrees (far above its peers); a size alone ("24x500ml") is asked
$pn = $cpRun('pack_in_name');
$cpCounts['pack_in_name'] = count($pn['open']);
$t($cpItem($pn, 'Hill Lager 6 pack', 'can', 6, 700, 6) && count($pn['auto']) === 1 && strpos((string)$pn['auto'][0]['auto_reason'], '"6 pack"') !== false,
    'pack in the name: "6 pack" + a price 7x its peers = 700 per can, answered for the person with the reason');
$t(count($pn['open']) === 1 && strpos($pn['open'][0]['text'], 'Mountain Water') !== false && (int)($pn['open'][0]['pack_hint'] ?? 0) === 24 && $cpItem($pn, 'Sun Cola 330ml', 'can', null, 600, 24),
    'pack in the name: "24x500ml" (no pack word) is still asked, with 24 pre-filled; ordinary rows are untouched');

// 4) the pack written in the UNIT cell ("Case of 24", "Crate(12)", "ctn/12")
$pu = $cpRun('pack_in_unit');
$cpCounts['pack_in_unit'] = count($pu['open']);
$t(!$pu['open'] && $cpItem($pu, 'Tomato Paste', 'case', null, 36000, 4) && $cpItem($pu, 'Cooking Oil', 'crate', null, 60000, 3) && $cpItem($pu, 'Table Napkins', 'carton', null, 9000, 10)
    && $cpItem($pu, 'Farm Eggs', 'tray', null, 3000, 5) && $pu['inv'] === [],
    'pack in the unit cell: counted and priced per case / crate / carton (the word from the cell, checked by the invariant), no question');
$puRow = $pu['an']['sheets'][0]['rows'][0];
$t(($puRow['fields']['unit']['how'] ?? '') === 'stock-unit:pack-cell' && $puRow['fields']['unit']['value'] === 'Case' && (int)$puRow['pack_rule']['info_pack'] === 24,
    'pack in the unit cell: "Case of 24" -> unit "Case" (how = pack-cell), 24 kept as information');

// 5) a per-unit AND a per-pack price: the per-unit price is used; the pack price only checks it (the row that disagrees is asked)
$up = $cpRun('unit_and_pack_price');
$cpCounts['unit_and_pack_price'] = count($up['open']);
$t($up['cols']['unit and pack price']['F'] === 'pack_price' && $cpItem($up, 'Lake Lager', 'bottle', null, 1000, 48) && $cpItem($up, 'Rift Stout', 'bottle', null, 1500, 24),
    'per-unit + per-pack price: the per-unit price is the cost when the pack price agrees');
$t(count($up['open']) === 1 && $up['open'][0]['type'] === 'pack-price-check' && strpos($up['open'][0]['text'], 'Delta Cider') !== false,
    'per-unit + per-pack price: only the row whose prices disagree is asked');
$upP = $cpRun('unit_and_pack_price', [], [$up['open'][0]['qkey'] => ['key' => 'pack']]);
$t($cpItem($upP, 'Delta Cider', 'bottle', 6, 2500, 12), 'per-unit + per-pack price: answering "the pack price" divides 15,000 by the 6 on the row');

// 6) a two-sheet workbook (kitchen: plain unit + cost; bar: bought as / units per pack / served by)
$ts = $cpRun('two_sheets');
$cpCounts['two_sheets'] = count($ts['open']);
// A83 (owner S1): the bar sheet has NO selling price, so a crate price is not divided on the row's words alone: the two crate rows
// (same sheet, crate of 24, bottle) share ONE question, pack 24 pre-filled; the kitchen sheet and the rum (bottle of 1) are untouched
$tsQ = $ts['open'][0] ?? [];
$t(count($ts['open']) === 1 && ($tsQ['type'] ?? '') === 'case-price' && !empty($tsQ['no_sell_check']) && (int)($tsQ['pack_hint'] ?? 0) === 24
    && count($tsQ['row_uids'] ?? []) === 2 && ($tsQ['group_rows'] ?? []) === [2, 3] && strpos((string)$tsQ['text'], 'Sky Lager') !== false && strpos((string)$tsQ['text'], 'Star Cola') !== false
    && $cpItem($ts, 'Wheat Flour', 'kg', null, 1200, 20) && $cpItem($ts, 'Reed Rum', 'bottle', null, 25000, 6),
    'two sheets: no selling price -> the two crate rows are ONE question (pack 24 pre-filled); kitchen sheet and the rum (bottle of 1) need nothing ' . json_encode(array_map(function ($q) { return [$q['type'], substr($q['text'], 0, 120)]; }, $ts['open'])));
$tsC = $cpRun('two_sheets', [], [$tsQ['qkey'] => ['key' => 'per-case', 'pack_size' => 24]]);
$t(!$tsC['open'] && $cpItem($tsC, 'Sky Lager', 'bottle', 24, 1500, 48) && $cpItem($tsC, 'Star Cola', 'bottle', 24, 500, 30) && $tsC['inv'] === [],
    'two sheets: answering "the whole crate" (24) divides BOTH rows: 36,000 -> 1,500 and 12,000 -> 500 per bottle, pack 24 saved');
$tsU = $cpRun('two_sheets', [], [$tsQ['qkey'] => ['key' => 'per-unit']]);
$t(!$tsU['open'] && $cpItem($tsU, 'Sky Lager', 'bottle', null, 36000, 48) && $cpItem($tsU, 'Star Cola', 'bottle', null, 12000, 30),
    'two sheets: answering "one bottle" keeps BOTH prices as written and takes the row pack out (no pack saved)');
$tsVc = import_vc_finish(import_vc_collect($tsU['an']), $tsU['res']['questions'], $tsU['res']['items']);
$t(is_array($tsVc), 'two sheets: the value check runs with a grouped "one unit" answer');

// 7) notes above the headings (a CSV whose headings are not on the first line)
$nf = $cpRun('notes_first');
$cpCounts['notes_first'] = count($nf['open']);
$nfQ = $nf['open'][0] ?? [];
$t(count($nf['open']) === 1 && $nf['an']['sheets'][0]['header_row'] === 4 && ($nfQ['type'] ?? '') === 'case-price' && (int)($nfQ['pack_hint'] ?? 0) === 12 && count($nfQ['row_uids'] ?? []) === 2
    && $cpItem($nf, 'Lime Cordial', 'bottle', null, 9000, 4),
    'notes first: headings found on line 4 ("Bought In", "Qty per Case", "Sold As"); no selling price -> the two case rows are ONE question (12 pre-filled) ' . json_encode(array_map(function ($q) { return [$q['type'], substr($q['text'], 0, 120)]; }, $nf['open'])));
$nfC = $cpRun('notes_first', [], [$nfQ['qkey'] => ['key' => 'per-case', 'pack_size' => 12]]);
$t(!$nfC['open'] && $cpItem($nfC, 'Mango Juice', 'bottle', 12, 1500, 12) && $cpItem($nfC, 'Guava Juice', 'bottle', 12, 2000, 6),
    'notes first: answering "the whole case" (12) divides both: 18,000 -> 1,500 and 24,000 -> 2,000 per bottle');
// 8) A83 peers: a case row with no selling price beside case rows of the same sheet + pack whose selling price checked the division.
// Priced among them -> divided like them, no question (the real RBH sheet's Heineken); priced far below them -> asked.
$peerCsv = fx_csv([
    ['Description', 'Closing Balance', 'Cost Price', 'Selling Price', 'Bought In', 'Qty per Case', 'Sold As'],
    ['Lake Lager', 10, 115000, 14000, 'Case', 24, 'Bottle'],
    ['Hill Lager', 12, 120000, 14000, 'Case', 24, 'Bottle'],
    ['River Lager', 33, 110000, '', 'Case', 24, 'Bottle'],
    ['Odd Lager', 5, 4800, '', 'Case', 24, 'Bottle'],
]);
$peerP = $put($peerCsv, 'peers.csv');
$peerAn = import_analyze_file($peerP, 'peers.csv', $al);
$peerRes = import_resolve($peerAn, [], $cpCtx);
$peerOpen = array_values(array_filter(array_merge($peerRes['questions'], array_values($peerRes['extra_questions'])), function ($q) { return $q['answer'] === null; }));
$peerItems = [];
foreach ($peerRes['items'] as $it) { $peerItems[$it['name']] = $it; }
$t(isset($peerItems['River Lager']) && abs((float)$peerItems['River Lager']['cost'] - 110000 / 24) < 0.001 && (int)$peerItems['River Lager']['pack'] === 24,
    'A83 peers: a case price with no selling price, among checked case prices of the same sheet, is divided like them (110,000 / 24)');
$t(count($peerOpen) === 1 && strpos((string)$peerOpen[0]['text'], 'Odd Lager') !== false && !empty($peerOpen[0]['no_sell_check']),
    'A83 peers: a case price far below its checked peers is still asked ' . json_encode(array_map(function ($q) { return substr($q['text'], 0, 100); }, $peerOpen)));
echo 'corpus open questions per layout: ' . json_encode($cpCounts) . "\n";

// guards: the old layouts keep their meaning
$adj = import_analyze_file($put(fx_xlsx(['sheets' => [['name' => 'BAR', 'rows' => [
    1 => ['A' => fx_S('Item Name'), 'B' => fx_S('Bar Balance'), 'C' => fx_S('Stores Balance'), 'D' => fx_S('Total Balance'), 'E' => fx_S('Unit Price (MWK)')],
    2 => ['A' => fx_S('ZZ Lager'), 'B' => fx_N(3), 'C' => fx_N(4), 'D' => fx_FM('B2+C2'), 'E' => fx_N(900)],
    3 => ['A' => fx_S('ZZ Stout'), 'B' => fx_N(1), 'C' => fx_N(2), 'D' => fx_FM('B3+C3'), 'E' => fx_N(950)],
    4 => ['A' => fx_S('ZZ Cider'), 'B' => fx_N(5), 'C' => fx_N(0), 'D' => fx_FM('B4+C4'), 'E' => fx_N(990)],
]]]]), 'adj.xlsx'), 'adj.xlsx', $al);
$adjD = $adj['sheets'][0]['columns'][3];
$t($adjD['field'] === 'qty_total' && !empty($adjD['derived']) && empty($adjD['fallback_from']) && (float)$adj['sheets'][0]['rows'][0]['computed']['qty_total']['value'] === 7.0,
    'guard: with Bar + Stores balances, an uncalculated "Total Balance" stays greyed (never the quantity); the quantity is still bar + stores');
$bare = import_analyze_file($csvRows([['Item', 'Price', 'Total'], ['ZZ A', 10, 50], ['ZZ B', 20, 60], ['ZZ C', 30, 90]], 'bare.csv'), 'bare.csv', $al);
$t($bare['sheets'][0]['columns'][2]['field'] === 'qty_total' && !empty($bare['sheets'][0]['columns'][2]['derived']),
    'guard: a bare "Total" heading never stands in for the quantity');
$two = import_analyze_file($csvRows([['Item', 'Qty', 'Price', 'Cost'], ['ZZ A', 1, 1500, 1000], ['ZZ B', 2, 2500, 2000], ['ZZ C', 3, 900, 600], ['ZZ D', 1, 4000, 3100], ['ZZ E', 2, 1200, 800]], 'two.csv'), 'two.csv', $al);
$twoF = array_map(function ($c) { return $c['state'] === 'mapped' ? $c['field'] : $c['state']; }, $two['sheets'][0]['columns']);
$t($twoF === ['item_name', 'qty_single', 'sell_price', 'unit_price'] && !array_filter($two['questions'], function ($q) { return $q['type'] === 'mapping-unsure'; }),
    'two price columns the headings cannot tell apart: the one higher on every row is the selling price, the lower the cost ' . json_encode($twoF));
$pr = import_pack_parse_text('Case of 24', import_pack_vocab($al));
$t($pr['word'] === 'Case' && $pr['n'] === 24 && import_pack_parse_text('Crate(20)', import_pack_vocab($al))['n'] === 20 && import_pack_parse_text('ctn/12', import_pack_vocab($al))['word'] === 'ctn'
    && import_pack_parse_text("24's", import_pack_vocab($al))['word'] === null && import_pack_parse_text('Castle Lager', import_pack_vocab($al)) === null && import_pack_parse_text('Room 12', import_pack_vocab($al)) === null,
    'pack patterns: "Case of 24", "Crate(20)", "ctn/12", "24\'s"; a plain name or "Room 12" is no pack');

// an EXISTING item whose stored cost is the crate price (kept per bottle): the divided cost is shown old -> new, ticked by a person, never asked
$exZ = ['id' => 501, 'name' => 'Zebra Lager', 'category' => 'Bar', 'unit' => 'bottle', 'current_quantity' => 30, 'cost_per_unit' => 48000, 'par_level' => 0, 'reorder_point' => 0, 'notes' => '',
    'pack_name' => null, 'pack_size' => null, 'is_archived' => 0, 'recipes' => 0];
$exK = ['id' => 502, 'name' => 'Kudu Lager', 'unit' => 'crate', 'cost_per_unit' => 36000] + $exZ;
$bx = $cpRun('bar_tracker', ['existing' => [$exZ, $exK]], ['_updates' => [rh_ssi_key('Zebra Lager') => ['cost', 'quantity', 'pack']]]);
$zi = $bx['items']['Zebra Lager'];
$t($zi['action'] === 'update' && $zi['diff']['cost'] === [48000.0, 2000.0] && in_array('cost', $zi['ticked'], true) && $zi['diff']['pack'] === [null, 24.0]
    && !array_filter($bx['open'], function ($q) { return $q['type'] === 'price-check'; })
    && count(array_filter($zi['warnings'], function ($w) { return strpos($w, 'is the price of the whole crate of 24') !== false; })) === 1,
    'existing item: cost 48,000 -> 2,000 shown old -> new, applied only when ticked; no price question (the old cost is the row\'s own crate price) ' . json_encode($zi['warnings']));
$bx0 = $cpRun('bar_tracker', ['existing' => [$exZ]]);
$t(!in_array('cost', $bx0['items']['Zebra Lager']['ticked'], true) && isset($bx0['items']['Zebra Lager']['diff']['cost']), 'existing item: nothing changes until Cost is ticked');
$t((bool)array_filter($bx['open'], function ($q) { return $q['type'] === 'unit-clash' && strpos($q['text'], 'Kudu Lager') !== false; }),
    'existing item counted in crates while the sheet counts bottles: still a unit question (a real difference)');
$sc = import_cont_cost_scale(import_cont_field_targets($bx0['res']['items'], 'cost'));
$t(abs($sc['before'] - 40 * 48000) < 0.01 && abs($sc['after'] - 40 * 2000) < 0.01 && $sc['by_pack'] === 1 && $sc['down'] === 1,
    'tick cost for all: the summary gives the value before -> after (1,920,000 -> 80,000) and how many costs come from a crate price ' . json_encode($sc));
$bxDup = $cpRun('bar_tracker', ['existing' => [['id' => 503, 'name' => 'Twin Peak', 'unit' => 'bottle', 'cost_per_unit' => 20000] + $exZ]]);
$t(count(array_filter($bxDup['open'], function ($q) { return $q['type'] === 'duplicate-no-batch'; })) === 1,
    'same name under two headings but the name is already in stock: which heading it is stays the person\'s choice (one question)');

// the hook for an optional column reader: unsure columns with samples; a suggestion is attached, never an answer
$hk = import_analyze_file($csvRows([['Item', 'Qty', 'Zorbl'], ['ZZ A', 1, 'x1'], ['ZZ B', 2, 'x2'], ['ZZ C', 3, 'x3']], 'hook.csv'), 'hook.csv', $al);
$hkCells = [];
foreach (import_csv_read($tmpFiles[count($tmpFiles) - 1], 'hook.csv')['sheets'] as $s) { $hkCells[$s['name']] = $s; }
$uns = import_unsure_columns($hk, $hkCells);
$t(count($uns) === 1 && $uns[0]['letter'] === 'C' && $uns[0]['samples'] === ['x1', 'x2', 'x3'], 'hook: the unsure column is listed with its heading and sample values ' . json_encode($uns));
$hk2 = $hk;
$t(import_attach_column_suggestions($hk2, [['sheet' => $uns[0]['sheet'], 'letter' => 'C', 'field' => 'remarks', 'why' => 'notes'], ['sheet' => $uns[0]['sheet'], 'letter' => 'C', 'field' => 'shelf_colour']], $al) === 1
    && $hk2['sheets'][0]['columns'][2]['state'] === 'unmapped' && $hk2['sheets'][0]['columns'][2]['suggested']['field'] === 'remarks',
    'hook: a suggestion is attached to the column only (still unmapped until a person confirms); an unknown field is dropped');


// review fixes: a row the pack cells do NOT decide keeps the old case-price checks; a stray lower-case note in column B is not a heading
$ud = $csvRows([['Item', 'Qty', 'Unit Price', 'Bought As', 'Units per pack', 'Sold by'],
    ['Zulu One', 5, 24000, 'Crate', 24, 'Bottle'], ['Zulu Two', 5, 24500, 'Crate', 24, 'Bottle'], ['Zulu Three', 5, 23500, 'Crate', 24, 'Bottle'],
    ['Zulu Four', 5, 24200, 'Crate', 24, 'Bottle'], ['Zulu Five', 5, 24800, 'Crate', 24, 'Bottle'],
    ['Zulu Blank', 5, 30000, 'Crate', '', 'Bottle']], 'undecided.csv');
$udR = import_resolve(import_analyze_file($ud, 'undecided.csv', $al), [], $cpCtx);
$t((bool)array_filter($udR['questions'], function ($q) { return $q['type'] === 'case-price' && $q['answer'] === null && strpos($q['text'], 'Zulu Blank') !== false; }),
    'undecided row: "Crate" with a blank pack size among decided peers is still asked (never read as 30,000 per bottle silently)');
$so = $csvRows([['Item', 'Qty', 'Sold by', 'Price'], ['Yak One', 3, 'Bottle', 1000], ['Yak Two', 3, 'Bottle', 1100], ['Yak Three', 3, 'Bottle', 1200],
    ['Yak Four', 3, 'Bottle', 1050], ['Yak Five', 3, 'Bottle', 1150], ['Yak Crate', 3, 'Bottle', 24000]], 'soldby.csv');
$soR = import_resolve(import_analyze_file($so, 'soldby.csv', $al), [], $cpCtx);
$t((bool)array_filter($soR['questions'], function ($q) { return $q['type'] === 'case-price' && $q['answer'] === null && strpos($q['text'], 'Yak Crate') !== false; }),
    'only a "Sold by" column (no bought-as word): nothing is decided, the crate-sized price is still asked');
$nt = import_analyze_file($csvRows([['Item Name', 'Total Balance', 'Unit Price'], ['', 'LAGERS', ''], ['Zebra Ale', 1, 100], ['Kudu Ale', 2, 200],
    ['', 'checked by store', ''], ['Eland Ale', 3, 300], ['Oryx Ale', 4, 400]], 'note.csv'), 'note.csv', $al);
$ntK = [];
foreach ($nt['sheets'][0]['skipped_rows'] as $sr) { $ntK[$sr['label'] !== '' ? $sr['label'] : 'row' . $sr['row']] = $sr['kind']; }
$t(array_column($nt['sheets'][0]['categories'], 'name') === ['LAGERS'] && in_array('orphan', $ntK, true) && !isset($ntK['checked by store']),
    'a heading typed in column B counts only when it looks like one (capitals that set headings apart, or a section word); a lower-case note stays a row with no name ' . json_encode($ntK));
$pk = import_pack_decide(['price' => 5000, 'pack_name' => '2026-11-01', 'served' => 'Bottle', 'pack_size' => 12], import_pack_vocab($al));
$t($pk !== null && $pk['pack'] === null && !$pk['decided'] && $pk['unit'] === 'Bottle', 'a bought-as cell that is not a unit word (a date) is never the stock unit and decides nothing');
$pkd = import_analyze_file($csvRows([['Item', 'Qty', 'Unit', 'Price', 'Packing Date'], ['Ibis Rice', 2, 'kg', 900, '2026-10-01'], ['Heron Beans', 3, 'kg', 800, '2026-10-02'],
    ['Crane Oats', 4, 'kg', 700, '2026-10-03']], 'packdate.csv'), 'packdate.csv', $al);
$pkdE = $pkd['sheets'][0]['columns'][4];
$t($pkdE['state'] === 'unmapped' && !empty($pkdE['not_units']) && !array_filter($pkd['questions'], function ($q) { return $q['type'] === 'mapping-unsure'; })
    && ($pkd['sheets'][0]['rows'][0]['fields']['unit']['value'] ?? '') === 'kg',
    'a heading that only looks like "bought as" ("Packing Date") over dates is simply not read: no question, the unit column is untouched');
// ---- review fix round (accounting A1 A2, QA Q1 Q2 Q3) ----
$fxHead = ['Item Name', 'Total Balance', 'Unit Price', 'Selling price', 'Stock Value', 'Unit Bought As', 'Total Per Unit', 'Unit sold/Served by'];
$fxRun = function (array $rows, array $ctx = [], array $answers = []) use ($csvRows, $al, $fxHead, $cpCtx) {
    $an = import_analyze_file($csvRows(array_merge([$fxHead], $rows), 'fx.csv'), 'fx.csv', $al);
    $res = import_resolve($an, $answers, $ctx + $cpCtx);
    $items = [];
    foreach ($res['items'] as $it) { $items[$it['name']] = $it; }
    $open = array_values(array_filter(array_merge($res['questions'], array_values($res['extra_questions'])), function ($q) { return $q['answer'] === null; }));
    return ['an' => $an, 'res' => $res, 'items' => $items, 'open' => $open];
};
// A1: rows that do not fit Quantity x Selling price are listed even when most rows fit
$a1 = $fxRun([['Quail A', 2, 1000, 3000, 6000, 'Bottle', 1, 'Bottle'], ['Quail B', 3, 1000, 3000, 9000, 'Bottle', 1, 'Bottle'], ['Quail C', 4, 1000, 3000, 12000, 'Bottle', 1, 'Bottle'],
    ['Quail D', 5, 1000, 3000, 15000, 'Bottle', 1, 'Bottle'], ['Quail E', 1, 1000, 3000, 9999, 'Bottle', 1, 'Bottle']]);
$a1Vc = import_vc_finish(import_vc_collect($a1['an']), $a1['res']['questions'], $a1['res']['items']);
$a1Row = array_values(array_filter($a1Vc['rows'], function ($x) { return ($x['why_code'] ?? '') === 'sell-differs'; }));
$t(count($a1Vc['selling']) === 1 && $a1Vc['selling'][0]['differ'] === 1 && abs($a1Vc['selling'][0]['calc_total'] - 45000) < 0.01 && count($a1Row) === 1 && $a1Row[0]['name'] === 'Quail E'
    && $a1Vc['differ'] === 1 && $a1Vc['show'] === true,
    'A1: a value column read at the selling price still lists the row that does not fit (9,999 vs 1 x 3,000), with both totals ' . json_encode($a1Vc['selling']));
// A2: an existing item whose stored cost is the crate price, Quantity ticked, Cost NOT ticked: out of the opening count, never auto-ticked
$a2 = $cpRun('bar_tracker', ['existing' => [$exZ]], ['_updates' => [rh_ssi_key('Zebra Lager') => ['quantity']]]);
$a2i = $a2['items']['Zebra Lager'];
$t($a2i['ticked'] === ['quantity'] && $a2i['in_count'] === false && count(array_filter($a2i['warnings'], function ($w) { return strpos($w, 'stays out of the opening count until Cost is ticked') !== false; })) === 1
    && import_cont_not_counted_reason($a2i) === 'cost_not_ticked',
    'A2: quantity ticked on an item whose stored cost is the crate price: not counted at the crate price per bottle, Cost is not ticked for the person');
// Q1: a crate row whose price is already at or below what one bottle sells for is asked (never 2,521 / 20 = 126.05 silently); a year in the pack column is asked
$q1 = $fxRun([['Robin Lager', 10, 2521, 3500, 35000, 'Crate', 20, 'Bottle'], ['Finch Lager', 10, 50000, 3000, 30000, 'Crate', 2026, 'Bottle']]);
$q1R = array_values(array_filter($q1['open'], function ($q) { return $q['type'] === 'case-price' && strpos($q['text'], 'Robin Lager') !== false && strpos($q['text'], 'at or below its selling price') !== false; }));
$q1F = array_values(array_filter($q1['open'], function ($q) { return $q['type'] === 'case-price' && strpos($q['text'], 'Finch Lager') !== false && strpos($q['text'], 'says 2,026') !== false; }));
$t(count($q1R) === 1 && (int)($q1R[0]['pack_hint'] ?? 0) === 20, 'Q1: Crate | 20 | Bottle at 2,521 with a selling price of 3,500 is asked (that row only)');
$t(count($q1F) === 1 && $q1['items']['Finch Lager']['pack'] === null && abs((float)$q1['items']['Finch Lager']['cost'] - 50000) < 0.001,
    'Q1: 2026 in the pack column is not a pack size: never divided by, asked ' . json_encode(array_column($q1['open'], 'text')));
$q1one = $fxRun([['Robin Lager', 10, 2521, 3500, 35000, 'Crate', 20, 'Bottle']]);   // the question key belongs to this file
$q1u = $fxRun([['Robin Lager', 10, 2521, 3500, 35000, 'Crate', 20, 'Bottle']], [], [($q1one['open'][0]['qkey'] ?? 'x') => ['key' => 'per-unit']]);
$t($q1u['items']['Robin Lager']['pack'] === null && abs((float)$q1u['items']['Robin Lager']['cost'] - 2521) < 0.001 && !$q1u['open'], 'Q1: answered "one bottle": 2,521 per bottle');
// Q2: an existing item whose stored cost is already a correct per-bottle cost keeps the price check (no "whole crate" explanation)
$exR = ['id' => 601, 'name' => 'Robin Lager', 'cost_per_unit' => 2521] + $exZ;
$q2 = $fxRun([['Robin Lager', 10, 2521, 3500, 35000, 'Crate', 20, 'Bottle']], ['existing' => [$exR]], ['_updates' => [rh_ssi_key('Robin Lager') => ['cost']]]);
$t(!array_filter($q2['items']['Robin Lager']['warnings'], function ($w) { return strpos($w, 'is the price of the whole') !== false; })
    && (bool)array_filter($q2['open'], function ($q) { return in_array($q['type'], ['case-price', 'price-check'], true); }),
    'Q2: a stored cost that is already per bottle is never "explained" as a crate price; the row is still asked about');
// Q3: bought by the case of 12, served by the tot: is the count in cases or in bottles? asked; "single units" divides and lets the person pick the unit
$q3 = $fxRun([['Wren Rum', 3, 60000, 2000, 6000, 'Case', 12, 'Tot']]);
$q3Q = array_values(array_filter($q3['open'], function ($q) { return $q['type'] === 'count-unit'; }));
$t(count($q3Q) === 1 && strpos($q3Q[0]['text'], 'in cases, or in single units (12 to a case)') !== false, 'Q3: Case | 12 | Tot asks whether the count is in cases or single units ' . json_encode($q3Q[0]['text'] ?? null));
$q3s = $fxRun([['Wren Rum', 3, 60000, 2000, 6000, 'Case', 12, 'Tot']], [], [($q3Q[0]['qkey'] ?? 'x') => ['key' => 'single']]);
$q3p = $fxRun([['Wren Rum', 3, 60000, 2000, 6000, 'Case', 12, 'Tot']], [], [($q3Q[0]['qkey'] ?? 'x') => ['key' => 'pack']]);
$t($q3s['items']['Wren Rum']['pack'] === 12 && abs((float)$q3s['items']['Wren Rum']['cost'] - 5000) < 0.001 && $q3s['items']['Wren Rum']['unit'] === null
    && mb_strtolower((string)$q3p['items']['Wren Rum']['unit']) === 'case' && abs((float)$q3p['items']['Wren Rum']['cost'] - 60000) < 0.001,
    'Q3: "single units" = 60,000 / 12 = 5,000 each with the unit for the person to pick; "in cases" = 60,000 per case ' . json_encode([$q3s['items']['Wren Rum']['unit'] ?? 'x', $q3p['items']['Wren Rum']['unit'] ?? 'x']));
// B2: NO selling-price column, existing item stored at the crate price, Quantity ticked, Cost not: out of the count, one warning (decided from the file alone)
$b2An = import_analyze_file($csvRows([['Item Name', 'Total Balance', 'Unit Price', 'Unit Bought As', 'Total Per Unit', 'Unit sold/Served by'],
    ['Zebra Lager', 40, 48000, 'Crate', 24, 'Bottle']], 'b2.csv'), 'b2.csv', $al);
$b2 = import_resolve($b2An, ['_updates' => [rh_ssi_key('Zebra Lager') => ['quantity']]], ['existing' => [$exZ]] + $cpCtx);
$b2i = $b2['items'][0];
$b2w = array_values(array_filter($b2i['warnings'], function ($w) { return strpos($w, 'opening count') !== false; }));
$t($b2i['ticked'] === ['quantity'] && $b2i['in_count'] === false && count($b2w) === 1 && strpos($b2w[0], 'stays out of the opening count until Cost is ticked') !== false,
    'B2: with no selling price, a stored crate price (48,000 = the row\'s crate price) never values the bottle count; one warning ' . json_encode($b2i['warnings']));

// ---- Step U2 d (owner 2026-11-19): quantities counted in shots / tots / glasses are turned into the stock unit with N; never guessed ----
echo "-- counts in shots / tots / glasses --\n";
$muRun = function (array $rows, array $ctx = [], array $answers = []) use ($csvRows, $al) {
    $p = $csvRows($rows, 'mu.csv');
    $an = import_analyze_file($p, 'mu.csv', $al);
    $res = import_resolve($an, $answers, $ctx + ['existing' => [], 'unit_options' => ['bottle', 'each', 'tot'], 'corrections' => []]);
    $items = [];
    foreach ($res['items'] as $it) { $items[$it['name']] = $it; }
    $open = array_values(array_filter(array_merge($res['questions'], array_values($res['extra_questions'])), function ($q) { return $q['answer'] === null; }));
    return ['an' => $an, 'res' => $res, 'items' => $items, 'open' => $open, 'inv' => import_assert_no_invented_values($an, import_csv_read($p, 'mu.csv'))];
};
$muOpen = function (array $r, string $type) { return array_values(array_filter($r['open'], function ($q) use ($type) { return $q['type'] === $type; })); };
$exGin = ['id' => 701, 'name' => 'Gin', 'category' => 'Bar', 'unit' => 'bottle', 'current_quantity' => 1, 'cost_per_unit' => 9000, 'par_level' => 0, 'reorder_point' => 0, 'notes' => '',
    'pack_name' => null, 'pack_size' => null, 'is_archived' => 0, 'recipes' => 0, '_measure' => ['big' => 'bottle', 'small' => 'shot', 'n' => 30.0, 'side' => 'big'], '_ml' => 750.0];
$ginTick = ['_updates' => [rh_ssi_key('Gin') => ['quantity']]];
$muW = import_measure_words($al);
$t(isset($muW['shots'], $muW['tot'], $muW['glasses'], $muW['pours']) && !isset($muW['measure']) && !isset($muW['single']) && !isset($muW['portion']),
    'U2d: shots / tots / glasses / pours are measure words (the hotel\'s portion words); "measure", "single", "portion" name no amount and are not');
$t(import_measure_singular('glasses') === 'glass' && import_measure_singular('tots') === 'tot' && import_measure_plural('glass', 5) === 'glasses' && import_measure_plural('bottle', 2.2) === 'bottles' && import_measure_plural('L', 3) === 'L',
    'U2d: glasses -> glass, tots -> tot; 5 glasses, 2.2 bottles, 3 L');
$t(import_measure_n_valid('30') === 30.0 && import_measure_n_valid('33.3333') === 33.3333 && import_measure_n_valid('0') === null && import_measure_n_valid('0.5') === null && import_measure_n_valid('1001') === null
    && import_measure_n_valid('abc') === null && import_measure_n_valid('-5') === null && import_measure_n_valid(null) === null,
    'U2d: "how many in one" is a number from 1 to the item-measure limit (decimals allowed); 0, 0.5, 1001, text and negatives are refused');
// a heading in shots + the item's own count measure (Step U1): 66 shots = 2.2 bottles, nothing asked, nothing saved
$m1 = $muRun([['Item Name', 'Balance (shots)', 'Unit', 'Unit Price'], ['Gin', '66', 'bottle', '9000']], ['existing' => [$exGin]], $ginTick);
$m1i = $m1['items']['Gin'];
$t(abs($m1i['balance'] - 2.2) < 0.0000005 && $m1i['diff']['quantity'] === [1.0, 2.2] && $m1i['in_count'] === true && $m1i['measure_save'] === null && !$m1['open']
    && strpos((string)$m1i['qty_how'], '66 shots = 2.2 bottles (30 shots in one bottle') !== false && abs((float)$m1i['cost'] - 9000) < 0.00005,
    'U2d: "Balance (shots)" 66 with the item\'s own measure (30 shots in one bottle) = 2.2 bottles, shown as "66 shots = 2.2 bottles", cost unchanged ' . json_encode([$m1i['balance'], $m1i['qty_how']]));
$t($m1['inv'] === [] && ($m1['an']['sheets'][0]['rows'][0]['fields']['qty_single']['value'] ?? null) === 66, 'U2d: the row keeps the count as written (66, its own cell); the conversion is the resolver\'s, never stored as a source value');
// size / the hotel's measures list: 750 ml / 25 ml tot = 30, nothing asked, nothing saved
$m2 = $muRun([['Item Name', 'Balance (tots)', 'Unit', 'Unit Price'], ['Vodka 750ml', '60', 'bottle', '9000']], ['drink_measures' => [['name' => 'Tot', 'ml' => 25]]]);
$t(abs($m2['items']['Vodka 750ml']['balance'] - 2) < 0.0000005 && !$m2['open'] && $m2['items']['Vodka 750ml']['measure_save'] === null
    && strpos((string)$m2['items']['Vodka 750ml']['qty_how'], '750 ml / 25 ml tot') !== false,
    'U2d: no item measure: the size in the name (750 ml) / the tot on the hotel\'s measures list (25 ml) = 30 tots in one bottle: 60 tots = 2 bottles');
// unknown: ONE question for like items (same measure + size), stock waits; an answer converts both and is saved on each new item
$muRows3 = [['Item Name', 'Balance (tots)', 'Unit', 'Unit Price'], ['Rum 750ml', '66', 'bottle', '9000'], ['Brandy 750ml', '45', 'bottle', '12000']];
$m3 = $muRun($muRows3);
$m3q = $muOpen($m3, 'measure-n');
$t(count($m3q) === 1 && count($m3q[0]['row_uids']) === 2 && count($m3['open']) === 1 && strpos($m3q[0]['text'], 'How many tots are in one 750 ml bottle?') === 0
    && $m3['items']['Rum 750ml']['balance'] === null && $m3['items']['Brandy 750ml']['status'] === 'needs-answer',
    'U2d: with no measure and no tot size, ONE question "How many tots are in one 750 ml bottle?" for both items; no stock is proposed until it is answered ' . json_encode(array_column($m3['open'], 'text')));
$m3k = $m3q[0]['qkey'] ?? 'x';
$m3a = $muRun($muRows3, [], [$m3k => ['key' => 'n', 'pack_size' => 30]]);
$t(abs($m3a['items']['Rum 750ml']['balance'] - 2.2) < 0.0000005 && abs($m3a['items']['Brandy 750ml']['balance'] - 1.5) < 0.0000005 && !$m3a['open']
    && ($m3a['items']['Rum 750ml']['measure_save']['n'] ?? null) === 30.0 && ($m3a['items']['Rum 750ml']['measure_save']['small'] ?? '') === 'tot'
    && ($m3a['items']['Rum 750ml']['measure_save']['big'] ?? '') === 'bottle' && ($m3a['items']['Brandy 750ml']['measure_save']['side'] ?? '') === 'big'
    && abs((float)$m3a['items']['Rum 750ml']['cost'] - 9000) < 0.00005 && abs((float)$m3a['items']['Brandy 750ml']['cost'] - 12000) < 0.00005,
    'U2d: answered 30: 66 tots = 2.2 bottles, 45 tots = 1.5 bottles, "30 tots in one bottle" saved on each new item; the costs (9,000 / 12,000 per bottle) are never divided by 30');
$m3b = $muRun($muRows3, [], [$m3k => ['key' => 'n', 'pack_size' => 0]]);
$t(count($muOpen($m3b, 'measure-n')) === 1 && $m3b['items']['Rum 750ml']['balance'] === null && import_answer_allowed($m3q[0], ['key' => 'n', 'pack_size' => '0']) !== ''
    && import_answer_allowed($m3q[0], ['key' => 'n', 'pack_size' => '30']) === '' && import_answer_allowed($m3q[0], ['key' => 'n']) !== '',
    'U2d: 0 (or no number) is no answer: refused when typed and still asked');
$m3s = $muRun($muRows3, [], [$m3k => ['key' => 'skip']]);
$t(!$m3s['items'] && !$m3s['open'], 'U2d: "Skip these rows" leaves both items out');
// two quantity columns: bottles in the store + tots at the bar
$m4 = $muRun([['Item Name', 'Stores', 'Bar (shots)', 'Unit', 'Unit Price'], ['Gin', '2', '15', 'bottle', '9000']], ['existing' => [$exGin]], $ginTick);
$t(abs($m4['items']['Gin']['balance'] - 2.5) < 0.0000005 && !$m4['open'] && strpos((string)$m4['items']['Gin']['qty_how'], '2 bottles + 15 shots = 2.5 bottles') === 0,
    'U2d: "Stores" 2 bottles + "Bar (shots)" 15 shots = 2.5 bottles (the plain column is not converted) ' . json_encode($m4['items']['Gin']['qty_how']));
// a different measure word than the item's own: asked, and the item's own measure is never replaced
$m4t = $muRun([['Item Name', 'Bar (tots)', 'Unit', 'Unit Price'], ['Gin', '15', 'bottle', '9000']], ['existing' => [$exGin]], $ginTick);
$m4tq = $muOpen($m4t, 'measure-n');
$m4ta = $muRun([['Item Name', 'Bar (tots)', 'Unit', 'Unit Price'], ['Gin', '15', 'bottle', '9000']], ['existing' => [$exGin]], $ginTick + [($m4tq[0]['qkey'] ?? 'x') => ['key' => 'n', 'pack_size' => 30]]);
$t(count($m4tq) === 1 && abs($m4ta['items']['Gin']['balance'] - 0.5) < 0.0000005 && $m4ta['items']['Gin']['measure_save'] === null
    && (bool)array_filter($m4ta['items']['Gin']['warnings'], function ($w) { return strpos($w, 'already has its own count measure (30 shots in one bottle)') !== false; }),
    'U2d: counted in tots but the item\'s own measure is in shots: asked (never assumed equal), converted, and the item\'s own measure is kept');
// "66 shots" typed in the quantity cell itself
$m5 = $muRun([['Item Name', 'Balance', 'Unit', 'Unit Price'], ['Gin', '66 shots', 'bottle', '9000']], ['existing' => [$exGin]], $ginTick);
$m5f = $m5['an']['sheets'][0]['rows'][0]['fields'];
$t(abs($m5['items']['Gin']['balance'] - 2.2) < 0.0000005 && ($m5f['qty_single']['how'] ?? '') === 'measure-cell' && ($m5f['qty_single']['value'] ?? null) === 66 && ($m5f['unit']['value'] ?? '') === 'bottle' && $m5['inv'] === [],
    'U2d: a cell "66 shots" is 66 counted in shots (number and word from that cell; the unit column stays the unit) = 2.2 bottles; invariant holds');
$tamper = $m5['an'];
$tamper['sheets'][0]['rows'][0]['fields']['qty_single']['value'] = 2.2;
$t(count(import_assert_no_invented_values($tamper, import_csv_read($csvRows([['Item Name', 'Balance', 'Unit', 'Unit Price'], ['Gin', '66 shots', 'bottle', '9000']], 'mu.csv'), 'mu.csv'))) === 1,
    'U2d: invariant: a converted number put in place of the cell\'s own 66 is caught');
// glassware counted in glasses is a count of things
$m6 = $muRun([['Item Name', 'Qty (glasses)', 'Unit', 'Unit Price'], ['Wine Glass', '12', 'each', '500'], ['House Red 750ml', '10', 'bottle', '8000']], ['drink_measures' => [['name' => 'Glass', 'ml' => 150]]]);
$t($m6['items']['Wine Glass']['balance'] === 12 && $m6['items']['Wine Glass']['qty_how'] === null && abs($m6['items']['House Red 750ml']['balance'] - 2) < 0.0000005
    && strpos((string)$m6['items']['House Red 750ml']['qty_how'], '(5 glasses in one bottle') !== false,
    'U2d: "Wine Glass" counted under "Qty (glasses)" stays 12 (glassware); House Red 750 ml: 10 glasses / (750 / 150) = 2 bottles');
// the stock unit IS the measure: nothing to convert
$m11 = $muRun([['Item Name', 'Balance (tots)', 'Unit', 'Unit Price'], ['Gin', '66', 'tot', '300']]);
$t(abs((float)$m11['items']['Gin']['balance'] - 66) < 0.0000005 && $m11['items']['Gin']['qty_how'] === null && !$m11['open'], 'U2d: stock kept in tots and counted in tots: 66 as written');
// empty, 0 and negative counts in tots: empty stays empty, 0 tots is 0 bottles with nothing asked, a negative is held as today
$mE = $muRun([['Item Name', 'Balance (tots)', 'Unit', 'Unit Price'], ['Ea Rum', '', 'bottle', '9000'], ['Eb Rum', '0', 'bottle', '9000'], ['Ec Rum', '-30', 'bottle', '9000']]);
$t($mE['items']['Ea Rum']['balance'] === null && $mE['items']['Eb Rum']['balance'] !== null && abs((float)$mE['items']['Eb Rum']['balance']) < 0.0000005 && !$muOpen($mE, 'measure-n')
    && $mE['items']['Ec Rum']['status'] === 'blocked' && count($muOpen($mE, 'bad-value')) === 1,
    'U2d: an empty tots cell stays empty (not 0), "0" tots is 0 bottles without asking how many are in one, "-30" is held like any negative count');
// two measures in one row: never mixed, asked as a bad value
$m10 = $muRun([['Item Name', 'Stores', 'Bar (tots)', 'Unit', 'Unit Price'], ['Gin', '3 glasses', '15', 'bottle', '9000']]);
$t(count(array_filter($m10['open'], function ($q) { return $q['type'] === 'bad-value' && strpos($q['text'], 'different measures') !== false; })) === 1 && $m10['items']['Gin']['status'] === 'blocked',
    'U2d: glasses in one cell and tots in the other: the row is held with a plain question, never added together');
// a new item with no unit: waits for the unit, then converts with it
$m12r = [['Item Name', 'Balance (tots)', 'Unit Price'], ['Gin 750ml', '66', '9000']];
$m12 = $muRun($m12r, ['drink_measures' => [['name' => 'Tot', 'ml' => 25]]]);
$m12q = $muOpen($m12, 'no-unit');
$m12a = $muRun($m12r, ['drink_measures' => [['name' => 'Tot', 'ml' => 25]]], [($m12q[0]['qkey'] ?? 'x') => ['key' => 'unit:bottle']]);
$t($m12['items']['Gin 750ml']['balance'] === null && count($m12q) === 1 && abs($m12a['items']['Gin 750ml']['balance'] - 2.2) < 0.0000005 && $m12a['items']['Gin 750ml']['unit'] === 'bottle' && !$m12a['open'],
    'U2d: no unit in the file: the tots wait for the unit; once "bottle" is chosen, 66 tots / (750 / 25) = 2.2 bottles');
// a price headed "per tot": asked, never multiplied by N
$m9 = $muRun([['Item Name', 'Balance', 'Unit', 'Price/tot'], ['Gin', '3', 'bottle', '900']]);
$m9q = $muOpen($m9, 'price-per-measure');
$m9d = $muRun([['Item Name', 'Balance', 'Unit', 'Price/tot'], ['Gin', '3', 'bottle', '900']], [], [($m9q[0]['qkey'] ?? 'x') => ['key' => 'drop']]);
$m9k = $muRun([['Item Name', 'Balance', 'Unit', 'Price/tot'], ['Gin', '3', 'bottle', '900']], [], [($m9q[0]['qkey'] ?? 'x') => ['key' => 'keep']]);
$t(count($m9q) === 1 && $m9d['items']['Gin']['cost'] === null && $m9d['items']['Gin']['in_count'] === false && abs((float)$m9k['items']['Gin']['cost'] - 900) < 0.00005 && !$m9d['open'] && !$m9k['open'],
    'U2d: a price column headed "Price/tot" is asked about: leave the cost blank, or use 900 as written; never 900 x 30');
// a container stock unit: "Beer 330ml" is one bottle's size, never the crate's, so N is asked (or comes from a volume typed on the item)
$mcCtx = ['drink_measures' => [['name' => 'Glass', 'ml' => 150]], 'unit_options' => ['crate', 'bottle']];
$mcRows = [['Item Name', 'Qty (glasses)', 'Unit', 'Unit Price'], ['Beer 330ml', '11', 'crate', '24000']];
$mc1 = $muRun($mcRows, $mcCtx);
$mc1q = $muOpen($mc1, 'measure-n');
$exBeer = ['id' => 702, 'name' => 'Beer 330ml', 'category' => 'Bar', 'unit' => 'crate', 'current_quantity' => 1, 'cost_per_unit' => 24000, 'par_level' => 0, 'reorder_point' => 0, 'notes' => '',
    'pack_name' => null, 'pack_size' => null, 'is_archived' => 0, 'recipes' => 0, '_measure' => null, '_ml' => 330.0, '_ml_source' => 'name'];
$beerTick = ['_updates' => [rh_ssi_key('Beer 330ml') => ['quantity']]];
$mc2 = $muRun($mcRows, ['existing' => [$exBeer]] + $mcCtx, $beerTick);
$exBeer['_ml'] = 7920.0;
$exBeer['_ml_source'] = 'item';
$mc3 = $muRun([['Item Name', 'Qty (glasses)', 'Unit', 'Unit Price'], ['Beer 330ml', '66', 'crate', '24000']], ['existing' => [$exBeer]] + $mcCtx, $beerTick);
$mc4 = $muRun($mcRows, $mcCtx, [($mc1q[0]['qkey'] ?? 'x') => ['key' => 'n', 'pack_size' => '24']]);
$t(count($mc1q) === 1 && strpos($mc1q[0]['text'], 'How many glasses are in one crate of "Beer 330ml"?') === 0 && $mc1['items']['Beer 330ml']['balance'] === null
    && count($muOpen($mc2, 'measure-n')) === 1 && $mc2['items']['Beer 330ml']['balance'] === null
    && abs((float)$mc3['items']['Beer 330ml']['balance'] - 1.25) < 0.0000005 && !$mc3['open']
    && abs((float)$mc4['items']['Beer 330ml']['balance'] - 11 / 24) < 0.0000005 && abs((float)$mc4['items']['Beer 330ml']['cost'] - 24000) < 0.00005,
    'U2d: stock in crates: 330 ml in the name is one bottle, not the crate: never 330 / 150 = 2.2 glasses in a crate; asked (new item and existing item with only the name size); a volume typed on the item (7,920 ml) is used; answered 24: 11 glasses = 0.458 crates, cost 24,000 per crate unchanged '
    . json_encode([array_column($mc1['open'], 'text'), $mc2['items']['Beer 330ml']['balance'], $mc3['items']['Beer 330ml']['balance']]));
// a price headed "per tot" on a row with a pack size: "use as written" is exactly the sheet's price, never divided by the pack
$ppRows = [['Item Name', 'Total Balance', 'Price/tot', 'Unit Bought As', 'Total Per Unit', 'Unit sold/Served by'], ['Lager', '40', '48000', 'Crate', '24', 'Bottle']];
$pp = $muRun($ppRows);
$ppq = $muOpen($pp, 'price-per-measure');
$ppk = $muRun($ppRows, [], [($ppq[0]['qkey'] ?? 'x') => ['key' => 'keep']]);
$ppKeepLabel = array_values(array_filter($ppq[0]['options'] ?? [], function ($o) { return $o['key'] === 'keep'; }))[0]['label'] ?? '';
$t(count($ppq) === 1 && abs((float)$ppk['items']['Lager']['cost'] - 48000) < 0.00005 && strpos($ppKeepLabel, 'exactly as written') !== false && strpos($ppKeepLabel, 'not divided or multiplied') !== false
    && (bool)array_filter($ppk['items']['Lager']['warnings'], function ($w) { return strpos($w, 'not divided by the pack of 24') !== false; }),
    'U2d: "Price/tot" with a crate of 24: "use each price exactly as written" gives 48,000, not 48,000 / 24, and says so on the item ' . json_encode([$ppk['items']['Lager']['cost'] ?? null, $ppKeepLabel]));
// bought by the case, served by the tot, counted in tots (the count-unit question's third answer)
$q3t = $fxRun([['Wren Rum', 66, 60000, 2000, 6000, 'Case', 12, 'Tot']]);
$q3tQ = array_values(array_filter($q3t['open'], function ($q) { return $q['type'] === 'count-unit'; }));
$q3tKeys = array_column($q3tQ[0]['options'] ?? [], 'key');
$q3tA = [($q3tQ[0]['qkey'] ?? 'x') => ['key' => 'portion']];
$q3t2 = $fxRun([['Wren Rum', 66, 60000, 2000, 6000, 'Case', 12, 'Tot']], [], $q3tA);
$q3tNu = array_values(array_filter($q3t2['open'], function ($q) { return $q['type'] === 'no-unit'; }));
$q3tA[$q3tNu[0]['qkey'] ?? 'y'] = ['key' => 'unit:bottle'];
$q3t3 = $fxRun([['Wren Rum', 66, 60000, 2000, 6000, 'Case', 12, 'Tot']], [], $q3tA);
$q3tMn = array_values(array_filter($q3t3['open'], function ($q) { return $q['type'] === 'measure-n'; }));
$q3tA[$q3tMn[0]['qkey'] ?? 'z'] = ['key' => 'n', 'pack_size' => '30'];
$q3t4 = $fxRun([['Wren Rum', 66, 60000, 2000, 6000, 'Case', 12, 'Tot']], [], $q3tA);
$q3ti = $q3t4['items']['Wren Rum'];
$t($q3tKeys === ['pack', 'single', 'portion'] && strpos($q3tQ[0]['text'], ', or in tots?') !== false && count($q3tNu) === 1 && count($q3tMn) === 1
    && strpos($q3tMn[0]['text'], 'How many tots are in one bottle of "Wren Rum"?') === 0
    && abs($q3ti['balance'] - 2.2) < 0.0000005 && abs((float)$q3ti['cost'] - 5000) < 0.00005 && ($q3ti['measure_save']['n'] ?? null) === 30.0 && !$q3t4['open'],
    'U2d: Case | 12 | Tot counted "in tots": 60,000 / 12 = 5,000 per bottle (not / 30), the unit is picked, then 66 tots / 30 = 2.2 bottles ' . json_encode([$q3tKeys, $q3tMn[0]['text'] ?? null, $q3ti['balance'] ?? null]));
$t(count($fxRun([['Wren Gin', 3, 60000, 2000, 6000, 'Case', 12, 'Glass']])['open']) === 1
    && array_column(array_values(array_filter($fxRun([['Wren Pop', 3, 60000, 2000, 6000, 'Case', 12, 'Single']])['open'], function ($q) { return $q['type'] === 'count-unit'; }))[0]['options'] ?? [], 'key') === ['pack', 'single'],
    'U2d: served by "Single" (no fixed amount) gets no "in singles" answer');

// owner fault 2026-11-20: Step 2 told the owner the selling price column was "Not imported" although Step 4 "Drinks for the menu" uses it.
// The column is still kept out of stock (derived), but the mapping table must say it is recognised and where it is used.
$diJs = (string)file_get_contents($root . '/admin/js/data-import.js');
$diSell = preg_match("/c\\.field === 'sell_price'\\) \\{ what = '([^']*)'/", $diJs, $diM) ? $diM[1] : '';
$t($diSell !== '' && stripos($diSell, 'not imported') === false && strpos($diSell, 'Recognised') === 0 && strpos($diSell, 'Drinks for the menu') !== false
    && strpos($diSell, 'tick') !== false && strpos($diSell, 'below') !== false
    && strpos($diJs, "(c.derived && c.field !== 'sell_price') || !c.field ? 'is-grey'") !== false && strpos($diJs, "c.derived && c.field === 'sell_price') { sellCols++;") !== false,
    'Step 2: the selling price column reads as recognised and used in Step 4 "Drinks for the menu" (tick), not "Not imported", is not greyed or counted as a column left out ' . json_encode($diSell));
$diPhp = (string)file_get_contents($root . '/admin/data-import.php');
$t(strpos($diJs, "if (drinksOnMenu && canStockReports) {") !== false && strpos($diJs, "pp.href = 'stock-reports.php?tab=profit'") !== false
    && strpos($diPhp, "rhCanLinkTo(\$uid, 'stock-reports.php')") !== false
    && strpos((string)file_get_contents($root . '/admin/stock-reports.php'), "'profit' => 'Projected profit'") !== false,
    'Result: "See projected profit" opens Stock Reports tab=profit, only when the import put drinks on the menu and the person can open Stock Reports');

// owner 2026-11-20: bottle sizes optional; the "served by" cell is read by plain rules (no AI, no guess): list measure / ml / stock unit / as written
$svList = [['name' => 'shot', 'ml' => 25], ['name' => 'double', 'ml' => 50], ['name' => 'glass of wine', 'ml' => 175]];
$svSyn = (array)import_default_aliases()['unit_synonyms'];
$sv = function (string $cell, string $unit = 'bottle') use ($svList, $svSyn) { return import_served_read($cell, $unit, $svList, $svSyn); };
$t($sv('Shot')['kind'] === 'list' && $sv('Shot')['measure'] === 'shot' && $sv('shots')['measure'] === 'shot' && $sv('Per shot')['measure'] === 'shot'
    && $sv('1 Shot')['measure'] === 'shot' && $sv('Glasses of wine')['measure'] === 'glass of wine' && $sv('Double')['kind'] === 'list',
    'served by: a measure on the hotel\'s list in any spelling (Shot, shots, Per shot, 1 Shot, Glasses of wine) is that list measure');
$t($sv('25ml')['kind'] === 'ml' && $sv('25ml')['ml'] === 25.0 && $sv('Tot 25 ml')['ml'] === 25.0 && $sv('Tot 25 ml')['word'] === 'tot'
    && $sv('Glass 175ml')['ml'] === 175.0 && $sv('2.5cl')['ml'] === 25.0 && $sv('Per tot 25ml')['word'] === 'tot' && $sv('Shot 25ml')['word'] === 'shot',
    'served by: a volume written in the cell (25ml, Tot 25 ml, Glass 175ml, 2.5cl) is one sale of that many ml');
$t($sv('Btl')['kind'] === 'unit' && $sv('Bottle')['kind'] === 'unit' && $sv('Btl 750ml')['kind'] === 'unit' && $sv('Btl 750ml')['size_ml'] === 750.0
    && $sv('75cl Bottle')['size_ml'] === 750.0 && $sv('Bottle', 'Bottles')['kind'] === 'unit' && $sv('Can', 'can')['kind'] === 'unit',
    'served by: the stock unit itself (Btl, Bottle, "Btl 750ml" with its size, "75cl Bottle") is one main unit');
$t($sv('Tot')['kind'] === 'as_is' && $sv('Glass 175')['kind'] === 'as_is' && $sv('Glass 175')['ml'] === null && $sv('Single')['kind'] === 'as_is'
    && $sv('Glass')['kind'] === 'as_is' && $sv('Glass')['measure'] === 'Glass' && $sv('')['kind'] === 'as_is' && $sv('25ml or 35ml')['kind'] === 'as_is'
    && $sv('Measure')['kind'] === 'as_is',
    'served by: never a guess - Tot / Single / Glass / Measure not on the list, a bare number (Glass 175) and two different volumes stay as written');
$t(import_served_volume_in('1,000ml')[0] === 1000.0 && import_served_volume_in('1,5L')[0] === 1500.0 && import_served_volume_in('1 litre')[0] === 1000.0
    && import_served_volume_in('750') === null && import_served_volume_in('0ml') === null && import_served_volume_in('Gin') === null,
    'served by: volumes read from a cell (1,000ml, 1,5L, 1 litre); a bare number or 0 ml is not a volume');
$drJs = (string)file_get_contents($root . '/admin/js/data-import.js');
$drPhp = (string)file_get_contents($root . '/includes/import/drinks.php');
$drFrom = (int)strpos($drJs, 'function sizesSection');
$drBody = $drFrom > 0 ? substr($drJs, $drFrom, (int)strpos($drJs, 'function fixedSections') - $drFrom) : '';
$t($drBody !== '' && strpos($drJs, "'Optional: add sizes so these can go on the menu") !== false && strpos($drJs, 'sizesSection(p);') !== false
    && strpos($drBody, "post('sizes_list'") !== false && strpos($drBody, "post('sizes_set'") !== false
    && strpos($drBody, 'showStep') === false && strpos($drBody, 'next.') === false
    && strpos($drPhp, "'Add bottle size in Step 3 (optional)") !== false && stripos($drBody, 'smart') === false && stripos($drBody, 'magic') === false,
    'Step 3 bottle sizes: an optional section with its own list that never touches Next; a drink without one says "Add bottle size in Step 3"');
$skV = import_pack_vocab($al);
$skOk = true;
foreach (['Shot' => 'portion', 'Per shot' => 'portion', '1 Shot' => 'portion', 'Tot 25ml' => 'portion', 'Glass 175 ml' => 'portion', '25ml' => 'portion', 'Glasses of wine' => 'portion',
    'Btl 750ml' => '', 'Glass 175' => 'portion', 'Gls 200' => 'portion', '250 glass' => 'portion', 'Bottle' => '', 'Per case' => 'container'] as $cell => $want) {
    if (import_served_kind($cell, $skV) !== $want) { $skOk = false; echo "  served kind $cell: " . import_served_kind($cell, $skV) . "\n"; }
}
$t($skOk, 'served by: "Per shot", "1 Shot", "Tot 25ml", "Glass 175 ml", "25ml", "Glass 175", "Gls 200" are portions; "Btl 750ml" is not; "Per case" is a container');
$skP = import_pack_decide(['price' => 15000, 'unit' => 'bottle', 'served' => 'Glass 175ml'], $skV);
$skP2 = import_pack_decide(['price' => 15000, 'served' => 'Btl'], $skV);
$t($skP !== null && $skP['unit'] === 'bottle' && !empty($skP['served_portion']) && $skP2 !== null && $skP2['unit'] === 'Btl',
    'stock unit: served by "Glass 175ml" keeps the Unit cell (bottle); with no Unit cell, a served-by unit word ("Btl") is the unit ' . json_encode([$skP['unit'] ?? null, $skP2['unit'] ?? null]));
$skA = import_analyze_file($csvRows([['Item Name', 'Unit', 'Unit Price', 'Balance', 'Served By'], ['Kudu Gin', 'bottle', 20000, 2, 'Shot'], ['Oryx Rum', 'bottle', 18000, 2, 'Per shot'],
    ['Eland Vodka', 'bottle', 15000, 2, 'Shot'], ['Ibis Wine', 'bottle', 15000, 2, 'Glass 175ml'], ['Heron Brandy', 'bottle', 12000, 2, 'Shot'], ['Crane Whisky', 'bottle', 30000, 2, 'shots']], 'served.csv'), 'served.csv', $al);
$skF = null;
foreach ($skA['sheets'][0]['columns'] as $c) { if ($c['letter'] === 'E') { $skF = $c['field'] ?? null; } }
$t($skF === 'served_by', 'served by: a "Served By" column with "Per shot" and "Glass 175ml" among plain "Shot" cells is still read (not dropped as "not units") ' . json_encode($skF));
// review 2026-11-20 (blocker): a served-by / measure column never replaces a Unit cell that is there (Castle Lager: 3 crates at 12000, never 3 bottles)
$clP = $csvRows([['Item Name', 'Unit', 'Unit Price', 'Balance', 'Serving Size'], ['Castle Lager', 'Crate', 12000, 3, 'Bottle'], ['Carlsberg', 'Crate', 13000, 2, 'Bottle'],
    ['Kuche Kuche', 'Crate', 11000, 4, 'Bottle']], 'castle.csv');
$clA = import_analyze_file($clP, 'castle.csv', $al);
$clR = import_resolve($clA, [], $cpCtx);
$clI = null;
foreach ($clR['items'] as $it) { if ($it['name'] === 'Castle Lager') { $clI = $it; } }
$t($clI !== null && mb_strtolower((string)$clI['unit']) === 'crate' && abs((float)$clI['cost'] - 12000) < 0.0001 && abs((float)$clI['balance'] - 3) < 0.0001,
    'unit cell wins: Unit "Crate" + Serving Size "Bottle" stays 3 crates at 12000 (the served-by column only adds how it is sold) ' . json_encode($clI ? [$clI['unit'], $clI['cost'], $clI['balance']] : null));
$clD = import_pack_decide(['price' => 12000, 'unit' => 'Crate', 'served' => 'Bottle'], $skV);
$t($clD !== null && $clD['unit'] === 'Crate' && $clD['pack'] === null, 'unit cell wins in the pack rule too (no pack worked out of a served-by word) ' . json_encode($clD['unit'] ?? null));
$t(import_measure_plural('25ml', 2) === '25 ml measures' && import_measure_plural('25ml', 1) === '25 ml measure' && import_measure_plural('shot', 2) === 'shots',
    'a bare volume served by reads "In 25 ml measures", never "25mls"');

// owner 2026-11-20: "Glass could also mean a non standard glass maybe for milk or juice and can be measured in ml optionally while a carton of
// juice could be in litres or whatever optionally": glass size + container size, read from the file or typed, never guessed
$gaDef = import_default_aliases();
$glSet = import_word_set((array)($gaDef['glass_words'] ?? []));
$poSet = import_word_set((array)($gaDef['pour_containers'] ?? []));
$t((int)$gaDef['version'] >= 7 && isset($glSet['glass'], $glSet['gls'], $glSet['tumbler']) && isset($poSet['carton'], $poSet['casket'], $poSet['bag in box'], $poSet['tetra'], $poSet['jug'])
    && !isset($poSet['crate']) && !isset($poSet['case']) && !isset($poSet['pack']) && in_array('gls', (array)$gaDef['portion_words'], true),
    'aliases v7: glass words (glass, gls, tumbler) and pour containers (carton, casket, bag-in-box, tetra, jug) are seeded; crate / case / pack are not pour containers');
$gr = function (string $c) use ($glSet) { return import_glass_read($c, $glSet); };
$t($gr('Glass 175')['ml'] === 175.0 && $gr('250ml glass')['ml'] === 250.0 && $gr('Gls 200')['ml'] === 200.0 && $gr('Glass of wine 175ml')['ml'] === 175.0
    && $gr('Glass 0.25 L')['ml'] === 250.0 && $gr('Glass') !== null && $gr('Glass')['ml'] === null && $gr('Glasses')['word'] === 'glass' && $gr('Tumbler')['word'] === 'tumbler'
    && $gr('Glass 2')['ml'] === null && $gr('Shot') === null && $gr('Tot 25ml') === null && $gr('Bottle') === null && $gr('') === null,
    'glass read: "Glass 175", "250ml glass", "Gls 200", "Glass of wine 175ml", "Glass 0.25 L" give the glass size; "Glass" alone is a glass with no size (asked); "Glass 2" is not 2 ml; shots / bottles are not glasses');
$pc = function (string $u) use ($poSet) { return import_pour_container($u, $poSet); };
$t($pc('Carton 1 L')['word'] === 'carton' && $pc('Carton 1 L')['ml'] === 1000.0 && $pc('Casket 5 litre')['ml'] === 5000.0 && $pc('Ctn')['word'] === 'ctn'
    && $pc('carton')['ml'] === null && $pc('Bag-in-box')['word'] === 'bag in box' && $pc('Crate') === null && $pc('Bottle') === null && $pc('Case 24') === null,
    'pour containers: "Carton 1 L" is a carton of 1000 ml (read from the unit cell), "Casket 5 litre" 5000 ml; a plain carton has no size (asked); crates, cases and bottles are not');
$t(import_glass_qty(250.0, 1000.0) === 0.25 && abs(1 / import_glass_qty(250.0, 1000.0) - 4) < 0.0000001 && import_glass_qty(175.0, 750.0) === 0.233333
    && import_glass_qty(null, 1000.0) === null && import_glass_qty(250.0, null) === null && import_glass_qty(0.0, 1000.0) === null && import_glass_qty(1.0, 100000000.0) === null,
    'cost per glass: Orange juice, Carton 1 L, served Glass 250 = 0.25 of a carton (4 glasses); 175 of 750 = 0.233333; an unknown size = not worked out (never 0)');
$t(import_served_read('Double', 'bottle', [['name' => 'shot', 'ml' => 25]], $svSyn)['kind'] === 'as_is' && import_served_read('double', 'bottle', $svList, $svSyn)['measure'] === 'double',
    '"double" is only ever the hotel\'s own Double measure (two measures), never a shot');
// the optional reader (wiring only): a stub here, never a real call. Only checked values for the asked rows and fields are kept.
$aiAsk = [['key' => 'k1', 'name' => 'Fresh milk', 'unit' => 'carton', 'served' => 'Glass'], ['key' => 'k2', 'name' => 'Mango juice', 'unit' => 'box', 'served' => 'Gls']];
$aiAllowed = ['k1' => ['portion_ml' => true, 'container_ml' => true], 'k2' => ['served_by' => true]];
$aiSeen = null;
$aiStub = function (array $items) use (&$aiSeen) {
    $aiSeen = $items;
    return [
        ['key' => 'k1', 'field' => 'portion_ml', 'value' => 250, 'source' => 'Glass <script>alert(1)</script> on BAR C4'],
        ['key' => 'k1', 'field' => 'container_ml', 'value' => '1,000 ml', 'source' => 'name'],
        ['key' => 'k2', 'field' => 'served_by', 'value' => 'double', 'source' => 'C9 "Dbl"'],
        ['key' => 'k2', 'field' => 'portion_ml', 'value' => 50, 'source' => 'not asked'],          // a field not asked for this row
        ['key' => 'zz', 'field' => 'portion_ml', 'value' => 50, 'source' => 'unknown row'],
        ['key' => 'k1', 'field' => 'portion_ml', 'value' => 999, 'source' => 'second answer'],     // first one kept
        'junk', ['key' => 'k1', 'field' => 'sql', 'value' => 1],
    ];
};
$aiGot = import_measure_ai_merge($aiAsk, $aiAllowed, $aiStub);
$t(count($aiSeen ?? []) === 2 && ($aiGot['k1']['portion_ml']['v'] ?? null) === 250.0 && ($aiGot['k1']['container_ml']['v'] ?? null) === 1000.0
    && ($aiGot['k2']['served_by']['v'] ?? null) === 'double' && !isset($aiGot['k2']['portion_ml']) && !isset($aiGot['zz'])
    && strpos((string)$aiGot['k1']['portion_ml']['src'], '<') === false && strpos((string)$aiGot['k1']['portion_ml']['src'], '>') === false,
    'optional reader: only asked rows and fields are kept, ml checked (1,000 ml = 1000), the first answer wins, "<" / ">" never reach the screen from a source ' . json_encode($aiGot));
$aiBad = import_measure_ai_merge($aiAsk, $aiAllowed, function () {
    return [['key' => 'k1', 'field' => 'portion_ml', 'value' => -5], ['key' => 'k1', 'field' => 'container_ml', 'value' => 200000],
        ['key' => 'k2', 'field' => 'served_by', 'value' => str_repeat('x', 61)], ['key' => 'k1', 'field' => 'portion_ml', 'value' => 'abc']];
});
$t($aiBad === [] && import_measure_ai_merge($aiAsk, $aiAllowed, null) === [] && import_measure_ai_merge($aiAsk, $aiAllowed, function () { throw new RuntimeException('down'); }) === []
    && import_measure_ai_merge([], $aiAllowed, $aiStub) === [] && import_measure_ai_merge($aiAsk, $aiAllowed, function () { return 'nonsense'; }) === [],
    'optional reader: bad values (-5, 200000 ml, 61 characters, "abc") are dropped; off / missing / failing / nonsense = nothing, everything works as before');
$t(strpos($drPhp, "function_exists('rh_ai_import_measure_suggest')") !== false && strpos($drPhp, 'rh_ai_enabled($pdo)') !== false && strpos($drPhp, "'/ai/ai-import-suggest.php'") !== false
    && strpos($drPhp, 'count($ask) >= 40') !== false,
    'optional reader: loaded only when its file is there, used only while the Assistant is switched on, at most 40 rows a call');
// the cell's own words decide before any reading of "served by" is used (review 2026-11-20: "Half tot" / "Dbl tot" are never one shot)
$qSet = import_word_set((array)($gaDef['quantity_words'] ?? []));
$fl = [['name' => 'shot', 'ml' => 25], ['name' => 'double', 'ml' => 50]];
$fSyn = ['tot' => 'shot', 'tots' => 'shot'];
$vSet = import_word_set(array_merge((array)($gaDef['portion_words'] ?? []), (array)($gaDef['glass_words'] ?? []), (array)($gaDef['container_words'] ?? []), (array)($gaDef['pour_containers'] ?? []), (array)($gaDef['units'] ?? []), array_keys((array)($gaDef['unit_synonyms'] ?? []))));
$fits = function (string $cell, string $ai, ?array $syn = null) use ($fl, $fSyn, $glSet, $qSet, $vSet) { return import_served_ai_fits($cell, $ai, 'bottle', $fl, $syn ?? $fSyn, $glSet, $qSet, $vSet); };
$t(isset($qSet['half'], $qSet['dbl'], $qSet['double'], $qSet['triple']) && !$fits('Half tot', 'shot') && !$fits('Dbl tot', 'shot') && !$fits('Double tot', 'shot')
    && !$fits('2 tots', 'shot') && !$fits('Tot x2', 'shot') && !$fits('0.5 tot', 'shot') && !$fits('Triple', 'shot') && !$fits('Half tot', 'half'),
    'reading vs cell: "Half tot", "Dbl tot", "Double tot", "2 tots", "Tot x2", "0.5 tot", "Triple" read as one shot are all dropped (left blank for the person)');
$t($fits('Double', 'double') && !$fits('Double tot', 'double') && !$fits('Double pint', 'double') && !$fits('Double glass', 'double') && !$fits('Double can', 'double') && !$fits('Double measure', 'double') && !$fits('Dbl tot', 'double') && !$fits('Tot measure', 'shot') && !$fits('1 tot', 'shot') && $fits('Single shot', 'shot') && $fits('One double', 'double')
    && $fits('Tumbler', 'glass') && !$fits('Pint glass', 'shot') && !$fits('Shot glass', 'double') && !$fits('Dbl', 'triple') && !$fits('Whatever', '2 shots')
    && isset($vSet['pint'], $vSet['scoop'], $vSet['tot']) && !$fits('Pint', 'shot') && !$fits('Scoop', 'shot') && !$fits('Tot', 'glass') && !$fits('½ tot', 'shot') && !$fits('Can', 'glass'),
    'reading vs cell: kept only when the cell agrees ("Double" = the hotel\'s Double; "Single shot" = shot; "Tumbler" = glass); "Double tot" / "Double pint" / "Double glass" / "Double can" / "Double measure" (another served word next to the quantity), "Dbl tot", "Tot measure", "Pint", "Scoop", "Can" (an alias served/unit word that is not the reading), "½ tot" or another count is dropped and left for the person');
$t(strpos($drPhp, "!empty(\$answers['_sizes']['ai_done'])") !== false && strpos($drPhp, "\$a['_sizes']['ai_done'] = 1;") !== false && strpos($drPhp, 'import_served_ai_fits($fitCell, $aiServed, $unit, $list, $synonyms, $glassSet, $qtySet, $vocabSet)') !== false
    && strpos($drPhp, '$fitCell = $served;') !== false && strpos($drPhp, 'import_cell_shows_size($served, $aiPortion) && import_cell_strip_size($served, $aiPortion) !== \'\'') !== false,
    'optional reader: one call per import (job marker claimed under the row lock before asking); its served-by reading must fit the cell first (only a shot size the cell itself writes is set aside for that check)');
// shot size helpers (owner 2026-11-21): the numbers a cell writes, and whether it shows one shot size
$t(import_cell_numbers('Tot 3.5cl') === [35.0] && import_cell_numbers('Shot 35') === [35.0] && import_cell_numbers('2 x 25ml') === [2.0, 25.0]
    && import_cell_shows_size('Shot 35', 35) && import_cell_shows_size('35ml tot', 35) && import_cell_shows_size('1 shot 35', 35) && !import_cell_shows_size('Shot', 35)
    && !import_cell_shows_size('Shot 30', 35) && !import_cell_shows_size('2 x 35ml', 35) && !import_cell_shows_size('½ tot 35', 35)
    && import_cell_strip_size('Shot 35', 35) === 'shot' && import_cell_strip_size('35', 35) === '',
    'shot size: a cell shows a shot size only when every number it writes (besides a plain 1) is that size; "Shot", "Shot 30" (read as 35), "2 x 35ml", "½ tot 35" do not');

// cleanup
foreach ($tmpFiles as $f) { @unlink($f); }
@rmdir($tmpDir);
echo "\n" . $ok . ' passed, ' . $bad . " failed\n";
exit($bad === 0 ? 0 : 1);
