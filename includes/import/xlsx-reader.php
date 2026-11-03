<?php
/**
 * Import engine - .xlsx / .xlsm reader (pure PHP; uses zip-reader.php + XMLReader).
 *
 * import_xlsx_read($path|$data, $isData) returns
 *   ['ok'=>bool, 'error'=>string, 'kind'=>'xlsx', 'date1904'=>bool, 'macros'=>bool,
 *    'sheets'=>[ ['name','hidden','index','cells'=>[row][col]=>cell, 'dims'=>['rows','cols','ref'],
 *                 'merges'=>[ ['ref','r1','c1','r2','c2'] ] ] ]]
 * Cell = ['ref','v','raw','formula'=>bool,'cached'=>bool,'date'=>bool,'error'=>bool,
 *         'merged_into'?=>'A1', 'merge_span'?=>[rows,cols]]
 *   v        display-typed value (string | int | float | bool | ISO date string | null)
 *   raw      the stored text as written in the file (null when a formula has no cached value)
 *   formula  the cell holds a formula
 *   cached   a value is stored (always true for plain cells; false for a formula that was never
 *            calculated = UNKNOWN, v is null - it must never be read as 0)
 *   date     the number format is a date/time format, v is the ISO conversion of the serial
 * Security: LIBXML_NONET, entities are never loaded, any DOCTYPE/ENTITY is refused outright.
 */

require_once __DIR__ . '/zip-reader.php';

if (!function_exists('import_xlsx_read')) {

    function import_col_to_num(string $letters): int
    {
        $n = 0;
        $letters = strtoupper($letters);
        for ($i = 0, $l = strlen($letters); $i < $l; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n;
    }

    function import_num_to_col(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }

    function import_cell_ref(int $row, int $col): string
    {
        return import_num_to_col($col) . $row;
    }

    /** 'C12' => [12, 3] or null */
    function import_parse_ref(string $ref): ?array
    {
        if (!preg_match('/^\$?([A-Za-z]{1,3})\$?(\d{1,7})$/', trim($ref), $m)) {
            return null;
        }
        return [(int)$m[2], import_col_to_num($m[1])];
    }

    /** Refuse any XML that declares a DOCTYPE or ENTITY (never present in genuine office files). */
    function import_xml_is_safe(string $xml): bool
    {
        $head = substr($xml, 0, 4096);
        if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
            return false;
        }
        return stripos($xml, '<!ENTITY') === false && stripos($xml, '<!DOCTYPE') === false;
    }

    function import_xml_reader(string $xml): ?XMLReader
    {
        if (!import_xml_is_safe($xml)) {
            return null;
        }
        $r = new XMLReader();
        if (!$r->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
            return null;
        }
        return $r;
    }

    /** Small-document tree: ['n'=>local name,'a'=>[attr=>val],'c'=>[children],'t'=>text]. */
    function import_xml_tree(string $xml): ?array
    {
        $r = import_xml_reader($xml);
        if (!$r) {
            return null;
        }
        $stack = [];
        $root = null;
        $guard = 0;
        while (@$r->read()) {
            if (++$guard > 2000000) {
                return null;
            }
            if ($r->nodeType === XMLReader::ELEMENT) {
                $node = ['n' => $r->localName, 'a' => [], 'c' => [], 't' => ''];
                if ($r->hasAttributes) {
                    $r->moveToFirstAttribute();
                    do {
                        $node['a'][$r->localName] = $r->value;
                    } while ($r->moveToNextAttribute());
                    $r->moveToElement();
                }
                $empty = $r->isEmptyElement;
                if ($stack) {
                    $stack[count($stack) - 1]['c'][] = &$node;
                } else {
                    $root = &$node;
                }
                if (!$empty) {
                    $stack[] = &$node;
                }
                unset($node);
            } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                array_pop($stack);
            } elseif (($r->nodeType === XMLReader::TEXT || $r->nodeType === XMLReader::CDATA) && $stack) {
                $stack[count($stack) - 1]['t'] .= $r->value;
            }
        }
        $r->close();
        $err = libxml_get_errors();
        libxml_clear_errors();
        if ($root === null) {
            return null;
        }
        // a fatal parse error means truncated/garbled XML
        foreach ($err as $e) {
            if ($e->level >= LIBXML_ERR_FATAL) {
                return null;
            }
        }
        return $root;
    }

    function import_xml_find(array $node, string $name): array
    {
        $out = [];
        foreach ($node['c'] as $c) {
            if ($c['n'] === $name) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** Text of an <si>/<is> element given its serialised XML: all <t> except phonetic runs. */
    function import_xml_rich_text(string $outer): string
    {
        $outer = preg_replace('~<(\w+:)?rPh\b.*?</(\w+:)?rPh>~s', '', $outer);
        if (!preg_match_all('~<(?:\w+:)?t\b[^>]*?(?:/>|>(.*?)</(?:\w+:)?t>)~s', $outer, $m)) {
            return '';
        }
        $s = '';
        foreach ($m[1] as $piece) {
            $s .= html_entity_decode($piece, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return $s;
    }

    function import_xlsx_shared_strings(string $xml): ?array
    {
        $r = import_xml_reader($xml);
        if (!$r) {
            return null;
        }
        $out = [];
        $ok = @$r->read();
        while ($ok) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
                $out[] = import_xml_rich_text($r->readOuterXml());
                $ok = @$r->next();       // skip the subtree, land on the next sibling
            } else {
                $ok = @$r->read();
            }
        }
        $r->close();
        libxml_clear_errors();
        return $out;
    }

    /** Is this number format a date/time format? */
    function import_xlsx_is_date_format(int $id, ?string $code): bool
    {
        if (($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 45 && $id <= 47) || ($id >= 50 && $id <= 58)) {
            return true;
        }
        if ($code === null || $code === '') {
            return false;
        }
        $c = preg_replace('/"[^"]*"/', '', $code);         // quoted literals
        $c = preg_replace('/\\\\./', '', $c);               // escaped chars
        $c = preg_replace('/\[(?!h\]|hh\]|m\]|mm\]|s\]|ss\])[^\]]*\]/i', '', $c); // [Red], [$MWK-409]
        $c = preg_replace('/[_*]./', '', $c);               // padding
        if (strcasecmp(trim($c), 'general') === 0) {
            return false;
        }
        return (bool)preg_match('/[dmyhs]/i', $c) && !preg_match('/^[#0?.,%\s\-+()E]*$/i', $c);
    }

    /** Excel serial => ISO date (Y-m-d) or datetime (Y-m-d H:i:s) or time (H:i:s) for serial < 1. */
    function import_xlsx_serial_to_iso(float $serial, bool $date1904 = false): ?string
    {
        if ($serial < 0 || $serial > 2958465) {
            return null;
        }
        $days = (int)floor($serial);
        $secs = (int)round(($serial - $days) * 86400);
        if ($secs >= 86400) {
            $days++;
            $secs -= 86400;
        }
        if ($serial < 1 && !$date1904) {
            return gmdate('H:i:s', $secs);
        }
        if ($date1904) {
            $unixDays = $days - 24107;
        } elseif ($days < 60) {
            $unixDays = $days - 25568;   // 1900 system: 1 = 1900-01-01 (before the fake 29 Feb)
        } else {
            $unixDays = $days - 25569;
        }
        $ts = $unixDays * 86400;
        return $secs > 0 ? gmdate('Y-m-d H:i:s', $ts + $secs) : gmdate('Y-m-d', $ts);
    }

    function import_xlsx_styles(string $xml): ?array
    {
        $tree = import_xml_tree($xml);
        $isDate = [];
        if (!$tree) {
            return null;
        }
        $codes = [];
        foreach (import_xml_find($tree, 'numFmts') as $nf) {
            foreach (import_xml_find($nf, 'numFmt') as $f) {
                $codes[(int)($f['a']['numFmtId'] ?? 0)] = $f['a']['formatCode'] ?? '';
            }
        }
        foreach (import_xml_find($tree, 'cellXfs') as $xfs) {
            foreach (import_xml_find($xfs, 'xf') as $i => $xf) {
                $id = (int)($xf['a']['numFmtId'] ?? 0);
                $isDate[$i] = import_xlsx_is_date_format($id, $codes[$id] ?? null);
            }
        }
        return $isDate;
    }

    function import_xlsx_resolve_target(string $target): string
    {
        if ($target !== '' && $target[0] === '/') {
            $path = ltrim($target, '/');
        } else {
            $path = 'xl/' . $target;
        }
        $parts = [];
        foreach (explode('/', $path) as $p) {
            if ($p === '..') {
                array_pop($parts);
            } elseif ($p !== '.' && $p !== '') {
                $parts[] = $p;
            }
        }
        return implode('/', $parts);
    }

    /** Turn one raw <c> into a reader cell (or null if empty). */
    function import_xlsx_make_cell(int $row, int $col, string $t, bool $hasV, string $v, ?string $is, bool $formula, bool $isDateStyle, array $shared, bool $date1904): ?array
    {
        $cell = [
            'ref' => import_cell_ref($row, $col), 'v' => null, 'raw' => null,
            'formula' => $formula, 'cached' => true, 'date' => false, 'error' => false,
        ];
        if ($formula && $is === null && (!$hasV || (trim($v) === '' && $t !== 'str'))) {
            $cell['cached'] = false;           // never calculated => UNKNOWN, not 0
            return $cell;
        }
        if (!$formula && !$hasV && $is === null) {
            return null;                       // styled-empty cell
        }
        if ($t === 'inlineStr' || $is !== null) {
            $s = $is !== null ? import_xml_rich_text($is) : $v;
            $cell['raw'] = $s;
            $cell['v'] = $s;
        } elseif ($t === 's') {
            $s = $shared[(int)$v] ?? '';
            $cell['raw'] = $s;
            $cell['v'] = $s;
        } elseif ($t === 'str') {
            $cell['raw'] = $v;
            $cell['v'] = $v;
        } elseif ($t === 'b') {
            $cell['raw'] = $v;
            $cell['v'] = ($v === '1');
        } elseif ($t === 'e') {
            $cell['raw'] = $v;
            $cell['error'] = true;
        } elseif ($t === 'd') {
            $cell['raw'] = $v;
            $cell['v'] = substr(trim($v), 0, 10);
            $cell['date'] = true;
        } else {
            $cell['raw'] = $v;
            $vt = trim($v);
            if ($vt === '') {
                $cell['v'] = '';
            } elseif (is_numeric($vt)) {
                $f = (float)$vt;
                if ($isDateStyle && $f >= 0) {
                    $iso = import_xlsx_serial_to_iso($f, $date1904);
                    if ($iso !== null) {
                        $cell['v'] = $iso;
                        $cell['date'] = true;
                        $cell['serial'] = $f;
                        return $cell;
                    }
                }
                if (preg_match('/^-?\d{1,15}$/', $vt)) {
                    $cell['v'] = (int)$vt;
                } else {
                    $r = round($f, 10);
                    $cell['v'] = (abs($r - $f) < 1e-9 * max(1, abs($f))) ? $r : $f;
                }
            } else {
                $cell['v'] = $v;
            }
        }
        return $cell;
    }

    /** Stream one worksheet. Returns [cells, merges] or an error string. */
    function import_xlsx_parse_sheet(string $xml, array $shared, array $dateStyles, bool $date1904, int $maxCells)
    {
        $r = import_xml_reader($xml);
        if (!$r) {
            return 'A sheet in this file could not be read (damaged or unsafe XML).';
        }
        $cells = [];
        $merges = [];
        $count = 0;
        $curRow = 0;
        $nextCol = 0;
        $inC = false;
        $c = null;
        while (@$r->read()) {
            $type = $r->nodeType;
            $name = $r->localName;
            if ($type === XMLReader::ELEMENT) {
                if ($name === 'row') {
                    $rr = $r->getAttribute('r');
                    $curRow = $rr !== null && ctype_digit($rr) ? (int)$rr : $curRow + 1;
                    $nextCol = 0;
                } elseif ($name === 'c') {
                    $ref = $r->getAttribute('r');
                    $pos = $ref !== null ? import_parse_ref($ref) : null;
                    if ($pos) {
                        $curRow = $pos[0];
                        $col = $pos[1];
                    } else {
                        $col = $nextCol + 1;
                    }
                    $nextCol = $col;
                    $s = $r->getAttribute('s');
                    $c = [
                        'row' => $curRow, 'col' => $col, 't' => (string)$r->getAttribute('t'),
                        'dateStyle' => $s !== null && !empty($dateStyles[(int)$s]),
                        'hasV' => false, 'v' => '', 'is' => null, 'f' => false,
                    ];
                    if ($r->isEmptyElement) {
                        $inC = false;
                        $c = null;
                    } else {
                        $inC = true;
                    }
                } elseif ($inC && $name === 'v') {
                    $c['hasV'] = true;
                    $c['v'] = $r->readString();
                } elseif ($inC && $name === 'f') {
                    $c['f'] = true;
                } elseif ($inC && $name === 'is') {
                    $c['is'] = $r->readOuterXml();
                } elseif ($name === 'mergeCell') {
                    $ref = (string)$r->getAttribute('ref');
                    if (preg_match('/^([A-Za-z]+)(\d+):([A-Za-z]+)(\d+)$/', $ref, $m)) {
                        $merges[] = [
                            'ref' => strtoupper($ref), 'r1' => (int)$m[2], 'c1' => import_col_to_num($m[1]),
                            'r2' => (int)$m[4], 'c2' => import_col_to_num($m[3]),
                        ];
                    }
                }
            } elseif ($type === XMLReader::END_ELEMENT && $name === 'c' && $inC) {
                $cell = import_xlsx_make_cell($c['row'], $c['col'], $c['t'], $c['hasV'], $c['v'], $c['is'], $c['f'], $c['dateStyle'], $shared, $date1904);
                if ($cell !== null) {
                    $cells[$c['row']][$c['col']] = $cell;
                    if (++$count > $maxCells) {
                        $r->close();
                        return 'This sheet has too many cells and was refused for safety.';
                    }
                }
                $inC = false;
                $c = null;
            }
        }
        $r->close();
        $errs = libxml_get_errors();
        libxml_clear_errors();
        foreach ($errs as $e) {
            if ($e->level >= LIBXML_ERR_FATAL) {
                return 'A sheet in this file is damaged or truncated and could not be read completely.';
            }
        }
        return [$cells, $merges];
    }

    function import_xlsx_apply_merges(array &$cells, array $merges, int $maxRow, int $maxCol): void
    {
        $budget = 200000;
        foreach ($merges as $m) {
            $r2 = min($m['r2'], $maxRow);
            $c2 = min($m['c2'], $maxCol);
            $anchor = import_cell_ref($m['r1'], $m['c1']);
            if (isset($cells[$m['r1']][$m['c1']])) {
                $cells[$m['r1']][$m['c1']]['merge_span'] = [$m['r2'] - $m['r1'] + 1, $m['c2'] - $m['c1'] + 1];
            }
            for ($r = $m['r1']; $r <= $r2; $r++) {
                for ($c = $m['c1']; $c <= $c2; $c++) {
                    if ($r === $m['r1'] && $c === $m['c1']) {
                        continue;
                    }
                    if (--$budget < 0) {
                        return;
                    }
                    if (!isset($cells[$r][$c])) {
                        $cells[$r][$c] = [
                            'ref' => import_cell_ref($r, $c), 'v' => null, 'raw' => '', 'formula' => false,
                            'cached' => true, 'date' => false, 'error' => false, 'merged_into' => $anchor,
                        ];
                    } else {
                        $cells[$r][$c]['merged_into'] = $anchor;
                    }
                }
            }
        }
        foreach ($cells as &$row) {
            ksort($row);
        }
        unset($row);
        ksort($cells);
    }

    /**
     * Read an .xlsx/.xlsm workbook. $source = file path, or raw bytes when $isData.
     */
    function import_xlsx_read(string $source, bool $isData = false, array $limits = []): array
    {
        // libxml global state is switched on only for the duration of the read and always put back
        $prevErrors = libxml_use_internal_errors(true);
        $prevLoader = null;
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $prevLoader = @libxml_disable_entity_loader(true);
        }
        try {
            return import_xlsx_read_inner($source, $isData, $limits);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevErrors);
            if ($prevLoader !== null) {
                @libxml_disable_entity_loader($prevLoader);
            }
        }
    }

    function import_xlsx_read_inner(string $source, bool $isData, array $limits): array
    {
        $fail = function (string $msg) {
            return ['ok' => false, 'error' => $msg, 'kind' => 'xlsx', 'date1904' => false, 'macros' => false, 'sheets' => []];
        };
        $zip = import_zip_open($source, $isData, $limits);
        if (!$zip['ok']) {
            return $fail($zip['error']);
        }
        if (!import_zip_has($zip, 'xl/workbook.xml')) {
            return $fail('This zip file is not an Excel workbook (xl/workbook.xml is missing).');
        }
        $macros = import_zip_has($zip, 'xl/vbaProject.bin');
        $wbRead = import_zip_read($zip, 'xl/workbook.xml');
        if (!$wbRead['ok']) {
            return $fail($wbRead['error']);
        }
        $wb = import_xml_tree($wbRead['data']);
        if (!$wb) {
            return $fail('The workbook could not be read (damaged or unsafe XML).');
        }
        $date1904 = false;
        foreach (import_xml_find($wb, 'workbookPr') as $pr) {
            $d = strtolower($pr['a']['date1904'] ?? '');
            $date1904 = ($d === '1' || $d === 'true');
        }
        $rels = [];
        $relRead = import_zip_read($zip, 'xl/_rels/workbook.xml.rels');
        if ($relRead['ok']) {
            $relTree = import_xml_tree($relRead['data']);
            if ($relTree) {
                foreach (import_xml_find($relTree, 'Relationship') as $rel) {
                    $rels[$rel['a']['Id'] ?? ''] = $rel['a']['Target'] ?? '';
                }
            }
        }
        $shared = [];
        if (import_zip_has($zip, 'xl/sharedStrings.xml')) {
            $ss = import_zip_read($zip, 'xl/sharedStrings.xml');
            if (!$ss['ok']) {
                return $fail($ss['error']);
            }
            $parsed = import_xlsx_shared_strings($ss['data']);
            if ($parsed === null) {
                return $fail('The text table of this workbook could not be read (damaged or unsafe XML).');
            }
            $shared = $parsed;
        }
        $dateStyles = [];
        if (import_zip_has($zip, 'xl/styles.xml')) {
            $st = import_zip_read($zip, 'xl/styles.xml');
            if (!$st['ok']) {
                return $fail($st['error']);
            }
            $dateStyles = import_xlsx_styles($st['data']);
            if ($dateStyles === null) {
                return $fail('The cell styles of this workbook could not be read (damaged or unsafe XML).');
            }
        }

        $sheets = [];
        $sheetsNode = import_xml_find($wb, 'sheets');
        $idx = 0;
        $maxCells = (int)($limits['max_cells'] ?? 1000000);
        foreach ($sheetsNode ? import_xml_find($sheetsNode[0], 'sheet') : [] as $sn) {
            $name = (string)($sn['a']['name'] ?? ('Sheet' . ($idx + 1)));
            $rid = $sn['a']['id'] ?? '';   // r:id => localName 'id'
            $state = strtolower($sn['a']['state'] ?? 'visible');
            $target = $rels[$rid] ?? '';
            if ($target === '') {
                return $fail('Sheet "' . $name . '" could not be located in the file.');
            }
            $part = import_xlsx_resolve_target($target);
            $sx = import_zip_read($zip, $part);
            if (!$sx['ok']) {
                return $fail($sx['error']);
            }
            $res = import_xlsx_parse_sheet($sx['data'], $shared, $dateStyles, $date1904, $maxCells);
            if (is_string($res)) {
                return $fail($res);
            }
            [$cells, $merges] = $res;
            $maxRow = 0;
            $maxCol = 0;
            foreach ($cells as $rn => $rowCells) {
                $maxRow = max($maxRow, $rn);
                foreach ($rowCells as $cn => $_) {
                    $maxCol = max($maxCol, $cn);
                }
            }
            if ($merges) {
                import_xlsx_apply_merges($cells, $merges, $maxRow, $maxCol);
            } else {
                ksort($cells);
                foreach ($cells as &$rc) {
                    ksort($rc);
                }
                unset($rc);
            }
            $sheets[] = [
                'name' => $name, 'hidden' => ($state === 'hidden' || $state === 'veryhidden'),
                'index' => $idx, 'cells' => $cells,
                'dims' => ['rows' => $maxRow, 'cols' => $maxCol, 'ref' => $maxRow ? 'A1:' . import_cell_ref($maxRow, $maxCol) : ''],
                'merges' => $merges,
            ];
            $idx++;
        }
        if (!$sheets) {
            return $fail('This workbook has no sheets.');
        }
        return ['ok' => true, 'error' => '', 'kind' => 'xlsx', 'date1904' => $date1904, 'macros' => $macros, 'sheets' => $sheets];
    }
}
