<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.
"); } // never runnable over the web
/**
 * Builds the SYNTHETIC stock workbook used by scripts/tests/import_engine_test.php.
 * Every name and price is invented; the quirks mirror the real hotel files (see .claude/IMPORT_PLAN.md).
 * No ZipArchive needed (hand-written zip writer, fixed timestamps => the output is byte-stable).
 *
 *   php scripts/tests/fixtures/import/build_fixture.php      writes synthetic_stock.xlsx next to this file
 *
 * The expected counts live in expected.json and were written BY HAND from the row list in
 * fx_main_spec() below - never produced by running the parser. If you change a row here, change
 * expected.json by hand too.
 */

// ---------------------------------------------------------------- tiny zip writer

function fx_dos_date(): int
{
    return (0 << 9) | (1 << 5) | 1;       // 1980-01-01, fixed => byte-stable output
}

/**
 * $files: name => string data | ['data'=>string, 'method'=>0|8, 'usize'=>int, 'csize'=>int, 'crc'=>int, 'flags'=>int]
 * (the usize/csize/crc keys LIE on purpose, for the security tests).
 */
function fx_zip(array $files): string
{
    $out = '';
    $central = '';
    $n = 0;
    foreach ($files as $name => $spec) {
        if (!is_array($spec)) {
            $spec = ['data' => $spec, 'method' => 8];
        }
        $data = $spec['data'];
        $method = $spec['method'] ?? 8;
        $flags = $spec['flags'] ?? 0;
        $crc = $spec['crc'] ?? (crc32($data) & 0xFFFFFFFF);
        $packed = $method === 8 ? gzdeflate($data, 9) : $data;
        $csize = $spec['csize'] ?? strlen($packed);
        $usize = $spec['usize'] ?? strlen($data);
        $offset = strlen($out);
        $local = "PK\x03\x04" . pack('vvvvvVVVvv', 20, $flags, $method, 0, fx_dos_date(), $crc, $csize, $usize, strlen($name), 0) . $name;
        $out .= $local . $packed;
        $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, $flags, $method, 0, fx_dos_date(), $crc, $csize, $usize, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $n++;
    }
    $cdOffset = strlen($out);
    return $out . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($central), $cdOffset, 0);
}

// ---------------------------------------------------------------- cell constructors

function fx_S(string $t): array { return ['k' => 's', 'v' => $t]; }                      // shared string
function fx_I(string $t): array { return ['k' => 'i', 'v' => $t]; }                      // inline string
function fx_R(array $parts): array { return ['k' => 'r', 'v' => $parts]; }               // rich-text shared string
function fx_N($n, int $style = 0): array { return ['k' => 'n', 'v' => $n, 's' => $style]; } // number
function fx_D(int $serial): array { return fx_N($serial, 1); }                           // date, built-in format 14
function fx_DC(int $serial): array { return fx_N($serial, 2); }                          // date, custom format dd-mmm-yyyy
function fx_B(bool $b): array { return ['k' => 'b', 'v' => $b ? '1' : '0']; }           // t="b"
function fx_E(string $e): array { return ['k' => 'e', 'v' => $e]; }                      // t="e"
function fx_F(string $f, $cached = null): array { return ['k' => 'f', 'f' => $f, 'v' => $cached, 'shape' => 'empty']; }   // <v></v> when not cached
function fx_FM(string $f): array { return ['k' => 'f', 'f' => $f, 'v' => null, 'shape' => 'missing']; }                  // no <v> element at all
function fx_FS(string $f, string $text): array { return ['k' => 'fs', 'f' => $f, 'v' => $text]; }                         // t="str" cached text

function fx_x(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function fx_col_num(string $c): int
{
    $n = 0;
    for ($i = 0; $i < strlen($c); $i++) {
        $n = $n * 26 + ord($c[$i]) - 64;
    }
    return $n;
}

/**
 * $spec = ['date1904'=>bool, 'sheets'=>[ ['name'=>, 'state'=>'visible'|'hidden', 'merges'=>['A1:C1'], 'rows'=>[rowNo=>[col=>cell]]] ]]
 */
function fx_xlsx(array $spec): string
{
    $shared = [];
    $sharedIdx = [];
    $addShared = function (string $xml) use (&$shared, &$sharedIdx): int {
        if (!isset($sharedIdx[$xml])) {
            $sharedIdx[$xml] = count($shared);
            $shared[] = $xml;
        }
        return $sharedIdx[$xml];
    };
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $files = [];
    $sheetsXml = '';
    $relsXml = '';
    $ctSheets = '';
    foreach ($spec['sheets'] as $i => $sh) {
        $n = $i + 1;
        $sd = '';
        ksort($sh['rows']);
        foreach ($sh['rows'] as $r => $cols) {
            uksort($cols, function ($a, $b) {
                return fx_col_num($a) <=> fx_col_num($b);
            });
            $sd .= '<row r="' . $r . '">';
            foreach ($cols as $col => $c) {
                $ref = $col . $r;
                $s = isset($c['s']) && $c['s'] ? ' s="' . $c['s'] . '"' : '';
                switch ($c['k']) {
                    case 's':
                        $sd .= '<c r="' . $ref . '" t="s"><v>' . $addShared('<si><t xml:space="preserve">' . fx_x($c['v']) . '</t></si>') . '</v></c>';
                        break;
                    case 'r':
                        $x = '<si>';
                        foreach ($c['v'] as $k => $p) {
                            $x .= '<r>' . ($k % 2 ? '<rPr><b/></rPr>' : '') . '<t xml:space="preserve">' . fx_x($p) . '</t></r>';
                        }
                        $sd .= '<c r="' . $ref . '" t="s"><v>' . $addShared($x . '</si>') . '</v></c>';
                        break;
                    case 'i':
                        $sd .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . fx_x($c['v']) . '</t></is></c>';
                        break;
                    case 'n':
                        $sd .= '<c r="' . $ref . '"' . $s . '><v>' . $c['v'] . '</v></c>';
                        break;
                    case 'b':
                        $sd .= '<c r="' . $ref . '" t="b"><v>' . $c['v'] . '</v></c>';
                        break;
                    case 'e':
                        $sd .= '<c r="' . $ref . '" t="e"><v>' . fx_x($c['v']) . '</v></c>';
                        break;
                    case 'fs':
                        $sd .= '<c r="' . $ref . '" t="str"><f>' . fx_x($c['f']) . '</f><v>' . fx_x($c['v']) . '</v></c>';
                        break;
                    case 'f':
                        $v = $c['v'] !== null ? '<v>' . $c['v'] . '</v>' : ($c['shape'] === 'empty' ? '<v></v>' : '');
                        $sd .= '<c r="' . $ref . '"><f>' . fx_x($c['f']) . '</f>' . $v . '</c>';
                        break;
                }
            }
            $sd .= '</row>';
        }
        $merge = '';
        if (!empty($sh['merges'])) {
            $merge = '<mergeCells count="' . count($sh['merges']) . '">';
            foreach ($sh['merges'] as $m) {
                $merge .= '<mergeCell ref="' . $m . '"/>';
            }
            $merge .= '</mergeCells>';
        }
        $files['xl/worksheets/sheet' . $n . '.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet ' . $ns . '><sheetData>' . $sd . '</sheetData>' . $merge . '</worksheet>';
        $state = (isset($sh['state']) && $sh['state'] !== 'visible') ? ' state="' . $sh['state'] . '"' : '';
        $sheetsXml .= '<sheet name="' . fx_x($sh['name']) . '" sheetId="' . $n . '"' . $state . ' r:id="rId' . $n . '"/>';
        $relsXml .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $ctSheets .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $k = count($spec['sheets']);
    $relsXml .= '<Relationship Id="rId' . ($k + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    $relsXml .= '<Relationship Id="rId' . ($k + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $pr = !empty($spec['date1904']) ? '<workbookPr date1904="1"/>' : '<workbookPr/>';
    $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook ' . $ns . '>' . $pr . '<sheets>' . $sheetsXml . '</sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relsXml . '</Relationships>';
    $files['xl/sharedStrings.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">' . implode('', $shared) . '</sst>';
    // cellXfs: 0 general | 1 built-in 14 (date) | 2 custom date | 3 #,##0 | 4 custom NON-date format with quoted letters
    $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd\-mmm\-yyyy"/><numFmt numFmtId="165" formatCode="&quot;MWK&quot; #,##0.00"/></numFmts>'
        . '<cellXfs count="5"><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/><xf numFmtId="3"/><xf numFmtId="165"/></cellXfs></styleSheet>';
    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $ctSheets . '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    // [Content_Types].xml stored (method 0) on purpose so both zip methods are exercised
    $files['[Content_Types].xml'] = ['data' => $files['[Content_Types].xml'], 'method' => 0];
    return fx_zip($files);
}

// ---------------------------------------------------------------- the synthetic workbook

/**
 * One row per line, with what it is meant to prove. Serial dates (worked out on paper):
 *   46473 = 2027-03-27   (46388 = 2027-01-01, +85 days)
 *   46100 = 2026-03-19   (46473 - 373)
 */
function fx_main_spec(): array
{
    $dash = [
        1 => ['A' => fx_S('SUNNY BAY HOTEL - DASHBOARD (SYNTHETIC)')],                      // merged title
        3 => ['A' => fx_S('BAR ITEMS'), 'B' => fx_F('COUNTA(Bar!A4:A19)', 11), 'C' => fx_B(true)],   // t="b"
        5 => ['A' => fx_S('Sheet'), 'B' => fx_S('Description')],                            // navigator table
        6 => ['A' => fx_S('BAR'), 'B' => fx_S('Bar items')],
    ];
    $bar = [
        1 => ['A' => fx_S('SUNNY BAY HOTEL - BAR STOCK (SYNTHETIC)')],                      // merged title A1:I1
        3 => ['A' => fx_S('Item Name'), 'B' => fx_S('Batch'), 'C' => fx_S('Bar Balance'), 'D' => fx_S('Stores Balance'),
              'E' => fx_S('Total Balance'), 'F' => fx_S('Expiry Date'), 'G' => fx_S('Days to Expiry'), 'H' => fx_S('Status'),
              'I' => fx_S("Unit Price\n(MWK)")],                                            // header at row 3, newline in header
        4 => ['A' => fx_S('Lakeview Lager'), 'C' => fx_N(10), 'D' => fx_N(20), 'E' => fx_F('C4+D4'), 'F' => fx_D(46473),
              'G' => fx_F('F4-TODAY()', 120), 'H' => fx_FS('IF(G4<0,"EXPIRED","OK")', 'OK'), 'I' => fx_N(2500)],   // date14; <v></v> formula
        5 => ['A' => fx_S('Sunrise Stout'), 'C' => fx_N(5), 'D' => fx_F('Z1'), 'E' => fx_FM('C5+D5'), 'F' => fx_DC(46100),
              'H' => fx_E('#N/A'), 'I' => fx_N(3100)],                                      // D5 = DATA formula never calculated => unknown; E5 no <v>; custom date
        6 => ['A' => fx_S('LOCAL BEERS'), 'C' => fx_N(0), 'D' => fx_N(0), 'I' => fx_N(0)],  // zero-valued category row
        7 => ['A' => fx_S('Palm Lager'), 'B' => fx_S('B1'), 'C' => fx_N(0), 'D' => fx_N(0), 'F' => fx_D(46473), 'I' => fx_N(2600)],   // batch pair B1
        8 => ['A' => fx_S('Palm Lager'), 'B' => fx_S('B2'), 'C' => fx_N(4), 'D' => fx_N(6), 'F' => fx_DC(46100), 'I' => fx_N(2600)],  // batch pair B2
        9 => ['A' => fx_S('Hill Stout'), 'C' => fx_N(7), 'D' => fx_N(1), 'I' => fx_N(2400)],
        10 => ['A' => fx_I('Hill Stoot'), 'C' => fx_N(3), 'D' => fx_N(0), 'I' => fx_N(2400)],                 // typo'd twin, inline string
        11 => ['A' => fx_R(['Crown ', 'Premium']), 'C' => fx_N(3), 'D' => fx_N(0), 'I' => fx_N(118000)],      // rich text; case-price looking
        12 => ['A' => fx_S('CORDIALS')],                                                    // label-only merged heading
        13 => ['A' => fx_S('Mango Cordial'), 'C' => fx_N(4), 'D' => fx_N(2), 'I' => fx_S("\xE2\x80\x94")],     // em-dash price = empty
        14 => ['A' => fx_S('Mango Cordial'), 'C' => fx_N(1), 'D' => fx_N(0), 'I' => fx_N(1800)],              // duplicate name, blank batch
        15 => ['A' => fx_S('TOTAL EXPIRED ITEMS'), 'C' => fx_F('SUM(C4:C14)', 3)],           // total row
        // row 16 left out on purpose: blank row
        17 => ['A' => fx_S('Cola Syrup'), 'C' => fx_N(-2), 'D' => fx_N(0), 'I' => fx_N(900)],                 // negative quantity => blocked
        18 => ['A' => fx_S('Ginger Beer'), 'C' => fx_N(6), 'D' => fx_N(0), 'I' => fx_S('1,800')],             // text price with thousands separator
        19 => ['A' => fx_S('TOTAL'), 'C' => fx_F('SUM(C4:C18)')],                           // total row, formula never calculated
    ];
    $kit = [
        1 => ['A' => fx_S('Kitchen stock list (synthetic)')],                               // title, not a header
        2 => ['A' => fx_S('Product'), 'B' => fx_S('Stores Balance'), 'C' => fx_S('Kitchen Balance'), 'D' => fx_S('Units'),
              'E' => fx_S('Max Level'), 'F' => fx_S('Price'), 'G' => fx_S('Total Cost'), 'H' => fx_S('Remarks')],   // header at row 2; Units column; different order
        3 => ['A' => fx_S('PROTEIN')],                                                      // label-only heading (not merged)
        4 => ['A' => fx_S('Chicken Fillet'), 'B' => fx_N(5), 'C' => fx_N(2), 'D' => fx_S('Packets'), 'E' => fx_N(30), 'F' => fx_N(15300), 'G' => fx_F('(B4+C4)*F4', 107100), 'H' => fx_S('frozen')],
        5 => ['A' => fx_S('Beef Cut'), 'B' => fx_N(2.5), 'C' => fx_N(2), 'D' => fx_S('kg'), 'E' => fx_N(5), 'F' => fx_N(17500), 'G' => fx_F('(B5+C5)*F5', 78750)],
        6 => ['A' => fx_S('Ginger Beer'), 'B' => fx_N(0), 'C' => fx_N(6), 'D' => fx_S('bottles'), 'F' => fx_N(2200)],   // same item as Bar, different price => conflict
        7 => ['A' => fx_S('DAIRY'), 'B' => fx_N(0), 'C' => fx_N(0), 'F' => fx_N(0)],        // zero-valued heading (capitals; items are mixed case)
        8 => ['A' => fx_S('Fresh Milk'), 'B' => fx_N(4), 'C' => fx_N(0), 'D' => fx_S('litres'), 'E' => fx_N(10), 'F' => fx_N(1900)],
        9 => ['A' => fx_S('Eggs Tray'), 'B' => fx_N(3), 'C' => fx_N(1), 'D' => fx_S('trays'), 'E' => fx_N(10), 'F' => fx_FM('E9*190')],   // price formula, no <v> element => unknown
        10 => ['A' => fx_S('TOTAL KITCHEN STOCK'), 'G' => fx_F('SUM(G4:G9)', 185850)],      // total row
        11 => ['A' => fx_S('Items below reorder level: 3 of 6')],                           // merged summary line
    ];
    $chem = [
        1 => ['A' => fx_S('Item Name'), 'B' => fx_S('Balance'), 'C' => fx_S('Remarks'), 'D' => fx_S('Unit Price'), 'E' => fx_S('Purchase Qty (Max - Balance)')],   // header at row 1; unit lives in Remarks
        2 => ['A' => fx_S('Floor Cleaner'), 'B' => fx_N(3), 'C' => fx_S('litres'), 'D' => fx_N(4200), 'E' => fx_F('10-B2', 7)],
        3 => ['A' => fx_S('Bleach'), 'B' => fx_N(0), 'C' => fx_S('bottles'), 'D' => fx_S("\xE2\x80\x94"), 'E' => fx_F('10-B3')],
        4 => ['A' => fx_S('Sponge'), 'B' => fx_N(12), 'C' => fx_S('piece'), 'D' => fx_N(450), 'E' => fx_F('10-B4', 0)],
        5 => ['A' => fx_S('Glass Wipe'), 'B' => fx_N(2), 'C' => fx_S('see supplier')],      // remark that is NOT a unit
    ];
    $hidden = [
        1 => ['A' => fx_S('Item Name'), 'B' => fx_S('Balance')],
        2 => ['A' => fx_S('Secret Item'), 'B' => fx_N(1)],
        3 => ['A' => fx_S('Other Item'), 'B' => fx_N(2)],
    ];
    $summary = [
        1 => ['A' => fx_S('Department'), 'B' => fx_S('Estimated Cost')],
        2 => ['A' => fx_S('Bar'), 'B' => fx_N(100)],
        3 => ['A' => fx_S('GRAND TOTAL'), 'B' => fx_F('SUM(B2:B2)', 100)],
    ];
    return [
        'date1904' => false,
        'sheets' => [
            ['name' => "\xF0\x9F\x93\x8A DASHBOARD", 'rows' => $dash, 'merges' => ['A1:D1']],
            ['name' => "\xF0\x9F\x8D\xBA BAR", 'rows' => $bar, 'merges' => ['A1:I1', 'A12:I12']],
            ['name' => "\xF0\x9F\x8D\xB3 KITCHEN", 'rows' => $kit, 'merges' => ['A11:H11']],
            ['name' => "\xF0\x9F\xA7\xB4 CHEMICAL", 'rows' => $chem],
            ['name' => "\xF0\x9F\x95\xB6 HIDDEN LIST", 'state' => 'hidden', 'rows' => $hidden],
            ['name' => "\xF0\x9F\x92\xB0 SUMMARY", 'rows' => $summary],
        ],
    ];
}

function fx_build_main(): string
{
    return fx_xlsx(fx_main_spec());
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $target = __DIR__ . '/synthetic_stock.xlsx';
    file_put_contents($target, fx_build_main());
    echo 'Wrote ' . $target . ' (' . filesize($target) . " bytes)\n";
}
