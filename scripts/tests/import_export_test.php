<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Stock workbook export (Import, Part B).  Usage (project root): php scripts/tests/import_export_test.php
 *
 * PART 1 (pure, no database): the .xlsx writer - zip structure, [Content_Types], every part well-formed XML (LIBXML_NONET), styles
 *   referenced exist, rows/cells sorted, shared-string counts, no formulas, formula-injection text, sheet-name rules, reader round
 *   trip, and 5,000 rows time/memory.
 * PART 2 (database, rolled back): scripts/tests/import_export_child.php through page_harness.php - ZZT rows only; stock definitions
 *   against independent SQL and the stock screens; the ROUND TRIP (export -> Import engine -> proposes no change); 5,000 items.
 * PART 3: the download endpoint in admin/data-import.php (gates, CSRF, scope, real file).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/includes/import/default-aliases.php';
require_once $root . '/includes/import/analyze.php';
require_once $root . '/includes/import/export-xlsx.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$PK = "PK" . chr(3) . chr(4);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'import_export_test_' . getmypid();
@mkdir($tmp);

// ======================================================================= PART 1: the writer
$w = new ImportXlsxWriter(['title' => 'T & <x>', 'creator' => "O'Brien"]);
$head = $w->style(['font' => ['b' => true, 'color' => 'FFFFFFFF'], 'fill' => 'FF1F3864', 'border' => 'FF999999', 'h' => 'center', 'wrap' => true]);
$num = $w->style(['num' => '#,##0.00##', 'border' => 'FF999999']);
$date = $w->style(['num' => 'dd\-mmm\-yyyy']);
$plain = $w->style([]);
ok('style(): the same spec gives the same index', $w->style(['num' => '#,##0.00##', 'border' => 'FF999999']) === $num);
$s1 = $w->addSheet("\u{1F37A} Bar: [x]/y?*\\ and a very long name that goes past thirty one characters", ['tab' => 'FFC00000', 'freeze' => [1, 3], 'gridlines' => false, 'landscape' => true, 'repeat_rows' => [3, 3], 'cols' => [30, 10, 12], 'first' => true, 'footer' => 'A & B']);
$s2 = $w->addSheet("\u{1F37A} Bar: [x]/y?*\\ and a very long name that goes past thirty one characters", []);
$s3 = $w->addSheet('history', []);
$s4 = $w->addSheet("O'Neil's", []);
$names = [$w->sheetTitle($s1), $w->sheetTitle($s2), $w->sheetTitle($s3), $w->sheetTitle($s4)];
$u16 = function ($s) { return strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2; };
ok('sheet names: at most 31 UTF-16 units, none of [ ] : * ? / \\', max(array_map($u16, $names)) <= 31 && !preg_match('/[\[\]:*?\/\\\\]/', implode('', $names)), json_encode($names));
ok('sheet names: unique ignoring case, "History" is not used', count(array_unique(array_map('mb_strtolower', $names))) === 4 && strcasecmp($names[2], 'History') !== 0);
$w->row($s1, 1, [1 => ['str' => 'Title', 's' => $head], 2 => ['s' => $head]], 30.0);
$w->merge($s1, 'A1:B1');
$w->row($s1, 3, [1 => ['str' => "Item\nName", 's' => $head], 2 => ['str' => 'Qty', 's' => $head], 3 => ['str' => 'Expiry', 's' => $head]], 38.25);
$evil = ['=cmd|calc', '+1+1', '-2+3', '@SUM(A1)', "\tTAB", '=HYPERLINK("http://x")'];
$r = 4;
foreach ($evil as $e) { $w->row($s1, $r++, [1 => ['str' => $e, 's' => $plain], 2 => ['num' => '12.5000', 's' => $num], 3 => ['num' => ImportXlsxWriter::dateSerial('2027-03-27'), 's' => $date]]); }
$w->row($s1, $r++, [1 => ['str' => "bad\x01chars\x0B ok", 's' => $plain], 2 => ['num' => 0.1 + 0.2, 's' => $num], 3 => ['num' => '-0', 's' => $plain]]);
$w->row($s1, $r++, [1 => ['str' => 'dup', 's' => $plain]]);
$w->row($s1, $r++, [1 => ['str' => 'dup', 's' => $plain]]);
$w->filter($s1, 'A3:C' . ($r - 1));
$w->link($s2, 'A1', "'" . $names[0] . "'!A1", 'go');
$w->row($s2, 1, [1 => ['str' => 'link', 's' => $plain]]);
$thrown = false;
try { $w->row($s1, 2, [1 => ['str' => 'late']]); } catch (InvalidArgumentException $e) { $thrown = true; }
ok('rows must be written in ascending order (a late row is refused)', $thrown);
$thrown = false;
try { $w->row($s3, 1, [1 => ['num' => 'abc']]); } catch (InvalidArgumentException $e) { $thrown = true; }
ok('a non-number in a number cell is refused, never written', $thrown);
$out = $w->finish();
ok('finish() returns a stream and its size', !empty($out['ok']) && is_resource($out['stream']) && $out['size'] > 500);
rewind($out['stream']);
$bytes = stream_get_contents($out['stream']);
fclose($out['stream']);
ok('size matches the bytes', strlen($bytes) === $out['size']);
$pkg = $tmp . DIRECTORY_SEPARATOR . 'pkg.xlsx';
file_put_contents($pkg, $bytes);

require_once $root . '/includes/import/zip-reader.php';
$z = import_zip_open($bytes, true);
ok('zip: opens with the pure-PHP reader (CRC-32 and sizes verified on read)', !empty($z['ok']));
$names = import_zip_names($z);
$parts = [];
$allOk = true; $bad = '';
foreach ($names as $nm) {
    $d = import_zip_read($z, $nm);
    if (!$d['ok']) { $allOk = false; $bad = $nm . ': ' . $d['error']; break; }
    $parts[$nm] = $d['data'];
}
ok('zip: every entry inflates and passes its CRC', $allOk, $bad);
ok('zip: first entry is [Content_Types].xml and the package has the required parts', $names[0] === '[Content_Types].xml' && isset($parts['_rels/.rels'], $parts['xl/workbook.xml'], $parts['xl/styles.xml'], $parts['xl/sharedStrings.xml'], $parts['xl/_rels/workbook.xml.rels']));
$dom = [];
$allWell = true; $bad = '';
foreach ($parts as $nm => $xml) {
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$d->loadXML($xml, LIBXML_NONET)) { $allWell = false; $bad = $nm; break; }
    $dom[$nm] = $d;
}
ok('every part is well-formed XML', $allWell, $bad);
$ctDom = $dom['[Content_Types].xml'];
$overrides = [];
foreach ($ctDom->getElementsByTagName('Override') as $o) { $overrides[$o->getAttribute('PartName')] = $o->getAttribute('ContentType'); }
$missing = [];
foreach ($names as $nm) {
    if ($nm === '[Content_Types].xml' || substr($nm, -5) === '.rels') { continue; }
    if (!isset($overrides['/' . $nm])) { $missing[] = $nm; }
}
ok('[Content_Types].xml has an Override for every part', $missing === [], json_encode($missing));
$rels = [];
foreach ($dom['xl/_rels/workbook.xml.rels']->getElementsByTagName('Relationship') as $rel) { $rels[$rel->getAttribute('Id')] = $rel->getAttribute('Target'); }
$relOk = true;
foreach ($rels as $t) { if (!isset($parts['xl/' . $t])) { $relOk = false; } }
ok('workbook relationships point at parts that exist', $relOk && count($rels) === 6);
$wbNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$sheetEls = $dom['xl/workbook.xml']->getElementsByTagName('sheet');
$rIdOk = true;
foreach ($sheetEls as $se) { if (!isset($rels[$se->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')])) { $rIdOk = false; } }
ok('every <sheet> has a relationship id; sheet names in workbook.xml are the valid ones', $rIdOk && $sheetEls->length === 4);

// styles
$st = $dom['xl/styles.xml'];
$cnt = function (DOMDocument $d, string $tag): array { $e = $d->getElementsByTagName($tag)->item(0); return [$e ? (int)$e->getAttribute('count') : -1, $e ? $e->childNodes->length : -2]; };
$fonts = $cnt($st, 'fonts'); $fills = $cnt($st, 'fills'); $borders = $cnt($st, 'borders'); $xfsC = $cnt($st, 'cellXfs');
ok('styles: count attributes match the number of children (fonts, fills, borders, cellXfs)', $fonts[0] === $fonts[1] && $fills[0] === $fills[1] && $borders[0] === $borders[1] && $xfsC[0] === $xfsC[1], json_encode([$fonts, $fills, $borders, $xfsC]));
$fl = $st->getElementsByTagName('fills')->item(0)->childNodes;
ok('styles: fill 0 is none and fill 1 is gray125 (Excel requires both)', $fl->item(0)->firstChild->getAttribute('patternType') === 'none' && $fl->item(1)->firstChild->getAttribute('patternType') === 'gray125');
$fmtIds = [];
foreach ($st->getElementsByTagName('numFmt') as $nf) { $fmtIds[(int)$nf->getAttribute('numFmtId')] = true; }
$xfOk = true;
foreach ($st->getElementsByTagName('cellXfs')->item(0)->childNodes as $xf) {
    $nid = (int)$xf->getAttribute('numFmtId');
    if ((int)$xf->getAttribute('fontId') >= $fonts[0] || (int)$xf->getAttribute('fillId') >= $fills[0] || (int)$xf->getAttribute('borderId') >= $borders[0] || ($nid >= 164 && !isset($fmtIds[$nid]))) { $xfOk = false; }
}
ok('styles: every xf points at a font, fill, border and number format that exists', $xfOk);

// shared strings
$sst = $dom['xl/sharedStrings.xml']->documentElement;
$uniq = $sst->getElementsByTagName('si')->length;
ok('sharedStrings: uniqueCount equals the number of <si>', (int)$sst->getAttribute('uniqueCount') === $uniq);
$refs = 0; $maxIdx = -1; $unsorted = ''; $badStyle = ''; $formulas = 0; $badOrder = '';
$xfCount = $xfsC[0];
$sheetParts = array_values(array_filter(array_keys($parts), function ($n) { return strpos($n, 'xl/worksheets/sheet') === 0; }));
foreach ($sheetParts as $sp) {
    $d = $dom[$sp];
    $formulas += $d->getElementsByTagName('f')->length;
    $lastRow = 0;
    foreach ($d->getElementsByTagName('row') as $row) {
        $rn = (int)$row->getAttribute('r');
        if ($rn <= $lastRow) { $unsorted = $sp . ' row ' . $rn; }
        $lastRow = $rn;
        $lastCol = 0;
        foreach ($row->getElementsByTagName('c') as $c) {
            if (!preg_match('/^([A-Z]+)(\d+)$/', $c->getAttribute('r'), $m) || (int)$m[2] !== $rn) { $unsorted = $sp . ' ref ' . $c->getAttribute('r'); continue; }
            $col = 0; foreach (str_split($m[1]) as $ch) { $col = $col * 26 + ord($ch) - 64; }
            if ($col <= $lastCol) { $unsorted = $sp . ' col ' . $c->getAttribute('r'); }
            $lastCol = $col;
            if ($c->hasAttribute('s') && (int)$c->getAttribute('s') >= $xfCount) { $badStyle = $c->getAttribute('r'); }
            if ($c->getAttribute('t') === 's') { $refs++; $maxIdx = max($maxIdx, (int)$c->getElementsByTagName('v')->item(0)->nodeValue); }
        }
    }
    // child order of <worksheet>
    $order = [];
    foreach ($d->documentElement->childNodes as $ch) { $order[] = $ch->nodeName; }
    $want = ['sheetPr', 'dimension', 'sheetViews', 'sheetFormatPr', 'cols', 'sheetData', 'autoFilter', 'mergeCells', 'hyperlinks', 'pageMargins', 'pageSetup', 'headerFooter'];
    $pos = array_map(function ($n) use ($want) { return array_search($n, $want, true); }, $order);
    if (in_array(false, $pos, true) || $pos !== array_values(array_unique($pos)) || $pos !== (function ($p) { $q = $p; sort($q); return $q; })($pos)) { $badOrder = $sp . ' ' . implode(',', $order); }
}
ok('worksheets: rows ascending, cells in column order, refs match their row', $unsorted === '', $unsorted);
ok('worksheets: every s="" is a real cell style', $badStyle === '', $badStyle);
ok('worksheets: elements appear in the order the schema requires', $badOrder === '', $badOrder);
ok('sharedStrings: count equals the number of string cells, every index exists', (int)$sst->getAttribute('count') === $refs && $maxIdx < $uniq);
ok('values only: no <f> formula element in any sheet', $formulas === 0);
ok('security: no external link, no macro part, no DOCTYPE', strpos(implode('', $parts), '<!DOCTYPE') === false && strpos(implode('|', $names), 'vba') === false && strpos(implode('', $parts), 'TargetMode="External"') === false);
$wbx = $parts['xl/workbook.xml'];
ok('defined names: Print_Titles and _FilterDatabase point at the sheet, apostrophes doubled', strpos($wbx, '_xlnm.Print_Titles') !== false && strpos($wbx, '_xlnm._FilterDatabase') !== false);
$s2x = $parts['xl/worksheets/sheet2.xml'];
ok('internal hyperlink is written with a location (no external relationship)', strpos($s2x, '<hyperlink ') !== false && strpos($s2x, 'location=') !== false && strpos($s2x, 'r:id') === false);

// read back with our own reader: values, dates, injection text
$back = import_xlsx_read($pkg);
ok('round trip through our own reader', !empty($back['ok']), (string)($back['error'] ?? ''));
$bs = $back['sheets'][0];
$isText = true;
foreach ([4, 5, 6, 7, 8, 9] as $rr) { $c = $bs['cells'][$rr][1] ?? null; if (!$c || $c['formula'] || $c['v'] !== $evil[$rr - 4]) { $isText = false; } }
ok('injection: text starting = + - @ (and tab) is read back as the same plain text, never a formula', $isText);
ok('injection: those cells carry quotePrefix so a spreadsheet will not evaluate them', substr_count($parts['xl/styles.xml'], 'quotePrefix="1"') >= 1);
ok('control characters are removed, other text kept', ($bs['cells'][10][1]['v'] ?? '') === 'badchars ok');
ok('numbers: 0.1+0.2 is written as 0.3, "-0" as 0', ($bs['cells'][10][2]['v'] ?? null) === 0.3 && ($bs['cells'][10][3]['v'] ?? null) === 0);
ok('dates: the serial is read back as 2027-03-27 (same as the tracker date system)', ($bs['cells'][4][3]['v'] ?? '') === '2027-03-27' && ImportXlsxWriter::dateSerial('2027-03-27') === 46473);
ok('header cell keeps its line break', ($bs['cells'][3][1]['v'] ?? '') === "Item\nName");
ok('shared strings: identical text is stored once', substr_count($parts['xl/sharedStrings.xml'], '>dup<') === 1);
ok('numText: exact decimal text, no float noise, no exponent', ImportXlsxWriter::numText('2500.5000') === '2500.5' && ImportXlsxWriter::numText('17.1234') === '17.1234' && ImportXlsxWriter::numText('0.000000') === '0' && ImportXlsxWriter::numText(1e-7) === '0.0000001' && ImportXlsxWriter::numText('abc') === null);
ok('the engine opens the writer file with the shared reader without questions about its structure', !empty(import_analyze_file($pkg, 'pkg.xlsx', import_default_aliases())['ok']));

// 5,000 rows through the writer alone (generator, nothing kept)
$gen = function () { for ($i = 1; $i <= 5000; $i++) { yield $i; } };
$m0 = memory_get_usage(true);
if (function_exists('memory_reset_peak_usage')) { memory_reset_peak_usage(); }
$t0 = microtime(true);
$bw = new ImportXlsxWriter();
$sa = $bw->style(['border' => 'FF999999', 'fill' => 'FFF2F2F2']);
$sb = $bw->style(['border' => 'FF999999', 'num' => '#,##0.00##']);
$bsi = $bw->addSheet('Big', ['freeze' => [1, 3]]);
foreach ($gen() as $i) {
    $bw->row($bsi, $i + 3, [1 => ['str' => 'Item number ' . $i, 's' => $sa], 2 => ['str' => 'B' . ($i % 50), 's' => $sa], 3 => ['num' => (string)($i % 97) . '.250000', 's' => $sb], 4 => ['str' => 'each', 's' => $sa],
        5 => ['num' => 46473 + ($i % 300), 's' => $sa], 6 => ['num' => $i % 300, 's' => $sa], 7 => ['str' => 'ACTIVE', 's' => $sa], 8 => ['str' => 'Normal', 's' => $sa], 9 => ['str' => '', 's' => $sa],
        10 => ['num' => 100, 's' => $sb], 11 => ['num' => 50, 's' => $sb], 12 => ['num' => '12.3456', 's' => $sb], 13 => ['num' => 617.28, 's' => $sb]]);
}
$bo = $bw->finish();
$secs = microtime(true) - $t0;
$grow = (memory_get_peak_usage(true) - $m0) / 1048576;
ok('writer, 5,000 rows x 13 columns: under 10 seconds and under 32 MB extra memory', $secs < 10 && $grow < 32, round($secs, 2) . 's, +' . round($grow, 1) . ' MB');
echo '  (info) writer alone, 5,000 rows: ' . round($secs, 2) . 's, peak +' . round($grow, 1) . ' MB, file ' . round($bo['size'] / 1024) . " KB\n";
fclose($bo['stream']);

// pure helpers of the exporter (no database)
require_once $root . '/includes/import/export-stock.php';
$h = import_export_headers("Stock Rosalyn's \u{00E9} 2026-11-04.xlsx", 1234);
$hs = implode("\n", $h);
ok('download headers: attachment, xlsx type, length, no-store, nosniff, UTF-8 name form', strpos($hs, 'attachment; filename="Stock Rosalyns  2026-11-04.xlsx"') !== false && strpos($hs, 'filename*=UTF-8') !== false && strpos($hs, 'Content-Length: 1234') !== false
    && strpos($hs, 'no-store') !== false && strpos($hs, 'nosniff') !== false && strpos($hs, 'spreadsheetml.sheet') !== false, $hs);
ok('file name: hotel apostrophe and slashes removed', import_export_filename("Rosalyn's / Beach <Hotel>", '2026-11-04') === 'Stock Rosalyns Beach Hotel 2026-11-04.xlsx' && import_export_filename('', '2026-11-04') === 'Stock 2026-11-04.xlsx');
ok('status tiers match the Batch Tracker (<0 expired, 0-7 at risk, 8-30 check soon, >30 active)',
    import_export_expiry_status(-1) === 'EXPIRED' && import_export_expiry_status(0) === 'AT RISK' && import_export_expiry_status(7) === 'AT RISK' && import_export_expiry_status(8) === 'CHECK SOON'
    && import_export_expiry_status(30) === 'CHECK SOON' && import_export_expiry_status(31) === 'ACTIVE' && import_export_expiry_status(null) === '');
$ic = import_default_tab_icons();
ok('tab icons: a generic word gives an icon, an unknown category gets none (nothing invented)', import_export_icon_for('Bar', $ic) !== '' && import_export_icon_for('Kitchen Stores', $ic) !== '' && import_export_icon_for('Zzyzx', $ic) === '');
ok('no staff wording and no AI/sparkle words in the export code', !preg_match('/\b(staff list|sparkle|magic|smart|robot|wand)\b/i', (string)file_get_contents($root . '/includes/import/export-stock.php') . (string)file_get_contents($root . '/includes/import/export-xlsx.php')));

// ======================================================================= PART 2: database (rolled back)
$run = strtoupper(bin2hex(random_bytes(3)));
$cfg = base64_encode(json_encode(['post' => ['run' => $run]]));
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' scripts/tests/import_export_child.php ' . escapeshellarg($cfg) . ' 2>&1');
$hres = ['error' => 'no result'];
if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $hres = $x; } }
ok('harness: the scenarios ran and were rolled back, nothing leaked', !empty($hres['rolled_back']) && empty($hres['leak']) && empty($hres['marker_lost']), substr((string)$out, -600));
$j = $hres['json'] ?? null;
if (!is_array($j) || !isset($j['checks'])) {
    ok('child printed its checks', false, substr((string)$out, -900));
} else {
    foreach ($j['checks'] as [$name, $good, $detail]) { ok($name, (bool)$good, (string)$detail); }
    if (!empty($j['info'])) { echo '  (info) 5,000 items: ' . json_encode($j['info']) . "\n"; }
}
require_once $root . '/config/database.php';
ok('no ZZT rows were left behind', (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE '%ZZT $run %'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM import_jobs WHERE original_name LIKE 'zzt-export-$run%'")->fetchColumn() === 0);

// ======================================================================= PART 3: the download endpoint
$runPage = function (array $cfg) use ($root, $PK) {
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' admin/data-import.php ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $raw = (string)$o;
    $p = strpos($raw, "PK");
    $q = strpos($raw, "
@@RESULT@@");
    $body = ($q !== false) ? substr($raw, 0, $q) : $raw;      // what the page printed: the file itself when it is a download
    if (preg_match('/@@RESULT@@(.*)$/m', $raw, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $x['body'] = $body; return $x; } }
    return ['error' => 'no result', 'body' => $body, 'raw' => substr($raw, -300)];
};
$clean = function (array $r) { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); };
$on = "INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('data_import_enabled', '1', 'import') ON DUPLICATE KEY UPDATE setting_value = '1'";
$off = "INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('data_import_enabled', '0', 'import') ON DUPLICATE KEY UPDATE setting_value = '0'";
$dump = $tmp . DIRECTORY_SEPARATOR . 'endpoint.xlsx';
@unlink($dump);
$r = $runPage(['post' => ['action' => 'export', 'scope' => 'all'], 'setup' => [$on]]);
$bin = (string)($r['body'] ?? ''); $p0 = strpos($bin, "PK"); if ($p0 !== false) { $bin = substr($bin, $p0); }
// NOTE: on Windows a pipe cuts binary output at the first Ctrl-Z byte, so only the start of the file is checked here; the whole file is validated above.
ok('endpoint: an administrator with CSRF gets a real .xlsx download (zip header, [Content_Types].xml first)', substr($bin, 0, 4) === "PK" && strpos(substr($bin, 0, 80), '[Content_Types].xml') !== false, substr($bin, 0, 80));
@unlink($dump);
$r = $runPage(['post' => ['action' => 'export', 'scope' => 'all', 'csrf_token' => 'wrong'], 'setup' => [$on]]);
$bin = (string)($r['body'] ?? ''); $p0 = strpos($bin, "PK"); if ($p0 !== false) { $bin = substr($bin, $p0); }
ok('endpoint: a wrong CSRF token is refused, no file', substr($bin, 0, 4) !== "PK");
@unlink($dump);
$r = $runPage(['post' => ['action' => 'export', 'scope' => 'all'], 'setup' => [$off]]);
$bin = (string)($r['body'] ?? ''); $p0 = strpos($bin, "PK"); if ($p0 !== false) { $bin = substr($bin, $p0); }
ok('endpoint: switched OFF (kill switch) gives no file', substr($bin, 0, 4) !== "PK");
@unlink($dump);
$nonAdmin = (string)$pdo->query("SELECT username FROM admin_users WHERE is_active = 1 AND role = 'receptionist' ORDER BY id LIMIT 1")->fetchColumn();
if ($nonAdmin !== '') {
    $r = $runPage(['post' => ['action' => 'export', 'scope' => 'all'], 'as_username' => $nonAdmin, 'setup' => [$on]]);
    $bin = (string)($r['body'] ?? ''); $p0 = strpos($bin, "PK"); if ($p0 !== false) { $bin = substr($bin, $p0); }
    ok('endpoint: a receptionist (no Import permission) gets no file', substr($bin, 0, 4) !== "PK");
    @unlink($dump);
} else {
    echo "SKIP endpoint non-permitted user check (no receptionist user in this database)\n";
}
$r = $runPage(['post' => ['action' => 'export', 'scope' => 'pick', 'tabs' => ['no such tab']], 'setup' => [$on]]);
$bin = (string)($r['body'] ?? ''); $p0 = strpos($bin, "PK"); if ($p0 !== false) { $bin = substr($bin, $p0); }
ok('endpoint: picking only a tab that does not exist gives a plain message, no file', substr($bin, 0, 4) !== "PK" && $bin !== '');
@unlink($dump);
$r = $runPage(['method' => 'GET', 'setup' => [$on], 'grep' => ['Export current stock', 'name="action" value="export"', 'Download the stock list as an Excel workbook']]);
ok('Import page header offers "Export current stock" with the scope choice and a plain helper line', !empty($r['found']['Export current stock']) && !empty($r['found']['name="action" value="export"']), json_encode($r['found'] ?? $r));
$js = (string)file_get_contents($root . '/admin/js/data-import.js');
ok('Result step offers "Download the updated stock workbook" (posts the export action with the page token)', strpos($js, 'Download the updated stock workbook') !== false && strpos($js, "'export'") !== false);

foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) { @unlink($f); }
@rmdir($tmp);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
