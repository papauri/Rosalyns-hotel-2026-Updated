<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
// Data Import engine (Phase 1a): PURE tests, no database. Runs in seconds.
// Run from the project root: php scripts/tests/import_engine_test.php
//
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

// cleanup
foreach ($tmpFiles as $f) { @unlink($f); }
@rmdir($tmpDir);
echo "\n" . $ok . ' passed, ' . $bad . " failed\n";
exit($bad === 0 ? 0 : 1);
