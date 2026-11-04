<?php
/**
 * Import - pure-PHP .xlsx WRITER (no ZipArchive, no Composer package; only zlib + SPL).
 *
 * The mirror of xlsx-reader.php / zip-reader.php. Built for the stock workbook export (export-stock.php) but generic:
 *   - rows are written one at a time into a per-sheet temp stream, so a 5,000-item workbook never holds a sheet in memory;
 *   - strings go into ONE shared-string table (identical text stored once);
 *   - styles (fonts / fills / borders / number formats / alignment) are registered once and referenced by index;
 *   - the zip is written straight to a temp file: stored or deflate entries, CRC-32 and sizes patched into the local header
 *     (no data descriptors), so the output opens in Excel and LibreOffice without a repair prompt;
 *   - VALUES ONLY: no formulas are ever written (nothing recalculates; what you see is what the database held).
 *
 * Safety: text that starts with = + - @ (or a tab / carriage return) is a string cell with quotePrefix, so a spreadsheet program
 * never evaluates it; characters XML 1.0 cannot hold are removed; text is capped at 32,767 characters; sheet names are made
 * valid (31 UTF-16 units, none of [ ] : * ? / \, unique ignoring case).
 *
 * Usage:
 *   $w  = new ImportXlsxWriter(['title' => '...', 'creator' => '...']);
 *   $st = $w->style(['font' => ['b' => true, 'sz' => 10, 'color' => 'FFFFFFFF'], 'fill' => 'FF1F3864', 'border' => 'FF999999',
 *                    'num' => '#,##0.00', 'h' => 'center', 'v' => 'center', 'wrap' => true]);
 *   $si = $w->addSheet('Bar', ['tab' => 'FFC00000', 'cols' => [32, 12], 'freeze' => [1, 3], 'gridlines' => false, 'landscape' => true,
 *                              'repeat_rows' => [3, 3]]);
 *   $w->row($si, 1, [1 => ['str' => 'Hello', 's' => $st], 2 => ['num' => '12.50', 's' => $st], 3 => ['s' => $st]], 30.0);
 *   $w->merge($si, 'A1:C1');  $w->filter($si, 'A3:C10');  $w->link($si, 'A5', "'Other'!A1");
 *   $out = $w->finish();     // ['ok'=>true, 'stream'=>resource, 'size'=>int]  (caller fclose()s the stream)
 */

if (!class_exists('ImportXlsxWriter')) {

    final class ImportXlsxWriter
    {
        private $opts;
        private $fonts = [];
        private $fills = [];
        private $borders = [];
        private $numFmts = [];      // code => id (164+)
        private $xfs = [];          // key => index
        private $xfList = [];
        private $sst = [];          // text => index
        private $sstList = [];
        private $sstRefs = 0;
        private $sheets = [];
        private $usedNames = [];

        public function __construct(array $opts = [])
        {
            $this->opts = $opts + ['title' => '', 'creator' => '', 'font' => 'Arial', 'size' => 10];
            $this->fonts[] = $this->fontXml(['b' => false, 'i' => false, 'sz' => $this->opts['size'], 'color' => null, 'name' => $this->opts['font']]);
            $this->fills[] = '<fill><patternFill patternType="none"/></fill>';
            $this->fills[] = '<fill><patternFill patternType="gray125"/></fill>';
            $this->borders[] = '<border><left/><right/><top/><bottom/><diagonal/></border>';
            $this->xfList[] = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
            $this->xfs['default'] = 0;
        }

        // ---------------------------------------------------------------- helpers

        public static function xml(string $s): string
        {
            return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        /** Remove what XML 1.0 cannot contain, normalise line breaks, cap the length. */
        public static function cleanText(string $s): string
        {
            if (!mb_check_encoding($s, 'UTF-8')) {
                $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            }
            $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $s) ?? '';
            $s = str_replace("\r\n", "\n", $s);
            $s = str_replace("\r", "\n", $s);
            if (mb_strlen($s) > 32767) {
                $s = mb_substr($s, 0, 32767);
            }
            return $s;
        }

        public static function colName(int $n): string
        {
            $s = '';
            while ($n > 0) {
                $m = ($n - 1) % 26;
                $s = chr(65 + $m) . $s;
                $n = intdiv($n - 1, 26);
            }
            return $s;
        }

        /** Excel serial number (1900 date system) of an ISO date YYYY-MM-DD, or null. */
        public static function dateSerial(?string $iso): ?int
        {
            if ($iso === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return null;
            }
            $base = gmmktime(0, 0, 0, 12, 30, 1899);
            $t = gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
            return (int)round(($t - $base) / 86400);
        }

        /** A number as the shortest exact decimal text (no exponent), from a decimal string or a float. */
        public static function numText($v): ?string
        {
            if (is_int($v)) {
                return (string)$v;
            }
            if (is_string($v)) {
                $v = trim($v);
                if (preg_match('/^-?\d+(\.\d+)?$/', $v)) {
                    if (strpos($v, '.') !== false) {
                        $v = rtrim(rtrim($v, '0'), '.');
                    }
                    return ($v === '' || $v === '-' || $v === '-0') ? '0' : $v;
                }
                if (!is_numeric($v)) {
                    return null;
                }
                $v = (float)$v;
            }
            if (!is_float($v) || is_nan($v) || is_infinite($v)) {
                return null;
            }
            $t = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
            return ($t === '' || $t === '-0' || $t === '-') ? '0' : $t;
        }

        private static function utf16Len(string $s): int
        {
            return (int)(strlen((string)mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2);
        }

        /** A valid, unique sheet name from free text. */
        public function sheetName(string $name): string
        {
            $n = self::cleanText($name);
            $n = preg_replace('/[\x00-\x1F\[\]:*?\/\\\\]/u', '-', $n) ?? '';
            $n = trim($n);
            $n = trim($n, "'");
            if ($n === '') {
                $n = 'Sheet';
            }
            $fit = function (string $s, int $max): string {
                while ($s !== '' && self::utf16Len($s) > $max) {
                    $s = mb_substr($s, 0, mb_strlen($s) - 1);
                }
                return rtrim($s);
            };
            $base = $fit($n, 31);
            if (strcasecmp($base, 'History') === 0) {
                $base = 'History (2)';
            }
            $cand = $base;
            $i = 2;
            while (isset($this->usedNames[mb_strtolower($cand)])) {
                $suffix = ' (' . $i++ . ')';
                $cand = $fit($base, 31 - strlen($suffix)) . $suffix;
            }
            $this->usedNames[mb_strtolower($cand)] = true;
            return $cand;
        }

        // ---------------------------------------------------------------- styles

        private function fontXml(array $f): string
        {
            $x = '<font>' . (!empty($f['b']) ? '<b/>' : '') . (!empty($f['i']) ? '<i/>' : '') . '<sz val="' . (float)($f['sz'] ?? 10) . '"/>';
            if (!empty($f['color'])) {
                $x .= '<color rgb="' . self::xml((string)$f['color']) . '"/>';
            }
            return $x . '<name val="' . self::xml((string)($f['name'] ?? 'Arial')) . '"/><family val="2"/></font>';
        }

        private function pool(array &$list, string $xml): int
        {
            $i = array_search($xml, $list, true);
            if ($i === false) {
                $list[] = $xml;
                $i = count($list) - 1;
            }
            return (int)$i;
        }

        /**
         * Register a cell style; identical specs share one index.
         * spec: font[b,i,sz,color,name] fill(rgb) border(rgb colour of a thin box) num(format code) h v wrap indent quote(bool)
         */
        public function style(array $spec): int
        {
            $key = json_encode($spec);
            if (isset($this->xfs[$key])) {
                return $this->xfs[$key];
            }
            $font = (array)($spec['font'] ?? []);
            $fontId = $this->pool($this->fonts, $this->fontXml($font + ['name' => $this->opts['font'], 'sz' => $this->opts['size']]));
            $fillId = 0;
            if (!empty($spec['fill'])) {
                $fillId = $this->pool($this->fills, '<fill><patternFill patternType="solid"><fgColor rgb="' . self::xml((string)$spec['fill']) . '"/><bgColor indexed="64"/></patternFill></fill>');
            }
            $borderId = 0;
            if (!empty($spec['border'])) {
                $c = '<color rgb="' . self::xml((string)$spec['border']) . '"/>';
                $borderId = $this->pool($this->borders, '<border><left style="thin">' . $c . '</left><right style="thin">' . $c . '</right><top style="thin">' . $c . '</top><bottom style="thin">' . $c . '</bottom><diagonal/></border>');
            }
            $numId = 0;
            if (!empty($spec['num'])) {
                $code = (string)$spec['num'];
                $builtin = ['General' => 0, '0' => 1, '0.00' => 2, '#,##0' => 3, '#,##0.00' => 4];
                if (isset($builtin[$code])) {
                    $numId = $builtin[$code];
                } else {
                    if (!isset($this->numFmts[$code])) {
                        $this->numFmts[$code] = 164 + count($this->numFmts);
                    }
                    $numId = $this->numFmts[$code];
                }
            }
            $al = '';
            if (!empty($spec['h']) || !empty($spec['v']) || !empty($spec['wrap']) || !empty($spec['indent'])) {
                $al = '<alignment' . (!empty($spec['h']) ? ' horizontal="' . self::xml((string)$spec['h']) . '"' : '')
                    . (!empty($spec['v']) ? ' vertical="' . self::xml((string)$spec['v']) . '"' : '')
                    . (!empty($spec['wrap']) ? ' wrapText="1"' : '')
                    . (!empty($spec['indent']) ? ' indent="' . (int)$spec['indent'] . '"' : '') . '/>';
            }
            $xf = '<xf numFmtId="' . $numId . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
                . ($numId ? ' applyNumberFormat="1"' : '') . ' applyFont="1"' . ($fillId ? ' applyFill="1"' : '') . ($borderId ? ' applyBorder="1"' : '')
                . ($al !== '' ? ' applyAlignment="1"' : '') . (!empty($spec['quote']) ? ' quotePrefix="1"' : '')
                . ($al !== '' ? '>' . $al . '</xf>' : '/>');
            $this->xfList[] = $xf;
            return $this->xfs[$key] = count($this->xfList) - 1;
        }

        /** The same style with quotePrefix on (used automatically for text that starts with = + - @). */
        private function quoted(int $s): int
        {
            $key = 'q' . $s;
            if (isset($this->xfs[$key])) {
                return $this->xfs[$key];
            }
            $xf = $this->xfList[$s];
            if (strpos($xf, 'quotePrefix') === false) {
                $xf = preg_replace('/^<xf /', '<xf quotePrefix="1" ', $xf);
            }
            $this->xfList[] = $xf;
            return $this->xfs[$key] = count($this->xfList) - 1;
        }

        // ---------------------------------------------------------------- sheets

        /**
         * opts: tab(rgb) cols[widths] freeze[xSplit,ySplit] gridlines(bool) landscape(bool) repeat_rows[first,last] footer(string)
         * Returns the sheet index. The returned name is available from sheetTitle().
         */
        public function addSheet(string $name, array $opts = []): int
        {
            $this->sheets[] = [
                'name' => $this->sheetName($name), 'opts' => $opts, 'buf' => fopen('php://temp/maxmemory:2097152', 'w+b'),
                'last' => 0, 'maxCol' => 1, 'merges' => [], 'filter' => null, 'links' => [], 'closed' => false,
            ];
            return count($this->sheets) - 1;
        }

        public function sheetTitle(int $si): string
        {
            return $this->sheets[$si]['name'];
        }

        public function sheetCount(): int
        {
            return count($this->sheets);
        }

        /**
         * Write one row. $cells: [col(1-based) => ['str'=>text | 'num'=>number-or-decimal-string | (neither = empty styled cell), 's'=>style]].
         * Rows must come in strictly ascending order.
         */
        public function row(int $si, int $r, array $cells, ?float $height = null): void
        {
            $sh =& $this->sheets[$si];
            if ($r <= $sh['last'] || $r > 1048576) {
                throw new InvalidArgumentException('Rows must be written in ascending order (row ' . $r . ' after ' . $sh['last'] . ').');
            }
            ksort($cells);
            $x = '<row r="' . $r . '"' . ($height !== null ? ' ht="' . self::numText($height) . '" customHeight="1"' : '') . '>';
            foreach ($cells as $c => $cell) {
                $c = (int)$c;
                if ($c < 1 || $c > 16384) {
                    throw new InvalidArgumentException('Column out of range.');
                }
                $sh['maxCol'] = max($sh['maxCol'], $c);
                $ref = self::colName($c) . $r;
                $s = (int)($cell['s'] ?? 0);
                if (isset($cell['str']) && $cell['str'] !== '') {
                    $t = self::cleanText((string)$cell['str']);
                    if ($t === '') {
                        $x .= '<c r="' . $ref . '"' . ($s ? ' s="' . $s . '"' : '') . '/>';
                        continue;
                    }
                    if (preg_match('/^[=+\-@\t\r]/', $t)) {
                        $s = $this->quoted($s);
                    }
                    if (!isset($this->sst[$t])) {
                        $this->sst[$t] = count($this->sstList);
                        $this->sstList[] = $t;
                    }
                    $this->sstRefs++;
                    $x .= '<c r="' . $ref . '"' . ($s ? ' s="' . $s . '"' : '') . ' t="s"><v>' . $this->sst[$t] . '</v></c>';
                } elseif (isset($cell['num'])) {
                    $n = self::numText($cell['num']);
                    if ($n === null) {
                        throw new InvalidArgumentException('Not a number for ' . $ref);
                    }
                    $x .= '<c r="' . $ref . '"' . ($s ? ' s="' . $s . '"' : '') . '><v>' . $n . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '"' . ($s ? ' s="' . $s . '"' : '') . '/>';
                }
            }
            fwrite($sh['buf'], $x . '</row>');
            $sh['last'] = $r;
            unset($sh);
        }

        public function merge(int $si, string $range): void
        {
            $this->sheets[$si]['merges'][] = $range;
        }

        public function filter(int $si, string $range): void
        {
            $this->sheets[$si]['filter'] = $range;
        }

        /** Internal link: $location like "'Sheet name'!A1". */
        public function link(int $si, string $ref, string $location, string $tip = ''): void
        {
            $this->sheets[$si]['links'][] = [$ref, $location, $tip];
        }

        // ---------------------------------------------------------------- parts

        private function stylesXml(): string
        {
            $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
            if ($this->numFmts) {
                $x .= '<numFmts count="' . count($this->numFmts) . '">';
                foreach ($this->numFmts as $code => $id) {
                    $x .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::xml($code) . '"/>';
                }
                $x .= '</numFmts>';
            }
            $x .= '<fonts count="' . count($this->fonts) . '">' . implode('', $this->fonts) . '</fonts>'
                . '<fills count="' . count($this->fills) . '">' . implode('', $this->fills) . '</fills>'
                . '<borders count="' . count($this->borders) . '">' . implode('', $this->borders) . '</borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="' . count($this->xfList) . '">' . implode('', $this->xfList) . '</cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                . '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium9" defaultPivotStyle="PivotStyleLight16"/></styleSheet>';
            return $x;
        }

        private function sheetHead(array $sh): string
        {
            $o = $sh['opts'];
            $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
            $x .= '<sheetPr>' . (!empty($o['tab']) ? '<tabColor rgb="' . self::xml((string)$o['tab']) . '"/>' : '')
                . (!empty($o['landscape']) ? '<pageSetUpPr fitToPage="1"/>' : '') . '</sheetPr>';
            $x .= '<dimension ref="A1:' . self::colName($sh['maxCol']) . max(1, $sh['last']) . '"/>';
            $x .= '<sheetViews><sheetView' . (isset($o['gridlines']) && !$o['gridlines'] ? ' showGridLines="0"' : '') . ' workbookViewId="0"' . (!empty($o['first']) ? ' tabSelected="1"' : '') . '>';
            if (!empty($o['freeze']) && ((int)$o['freeze'][0] > 0 || (int)$o['freeze'][1] > 0)) {
                $xs = (int)$o['freeze'][0];
                $ys = (int)$o['freeze'][1];
                $tl = self::colName($xs + 1) . ($ys + 1);
                $pane = ($xs > 0 && $ys > 0) ? 'bottomRight' : ($ys > 0 ? 'bottomLeft' : 'topRight');
                $x .= '<pane' . ($xs > 0 ? ' xSplit="' . $xs . '"' : '') . ($ys > 0 ? ' ySplit="' . $ys . '"' : '') . ' topLeftCell="' . $tl . '" activePane="' . $pane . '" state="frozen"/>';
                if ($xs > 0 && $ys > 0) {
                    $x .= '<selection pane="topRight" activeCell="' . self::colName($xs + 1) . '1" sqref="' . self::colName($xs + 1) . '1"/>'
                        . '<selection pane="bottomLeft" activeCell="A' . ($ys + 1) . '" sqref="A' . ($ys + 1) . '"/>'
                        . '<selection pane="bottomRight" activeCell="' . $tl . '" sqref="' . $tl . '"/>';
                } else {
                    $x .= '<selection pane="' . $pane . '" activeCell="' . $tl . '" sqref="' . $tl . '"/>';
                }
            }
            $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
            if (!empty($o['cols'])) {
                $x .= '<cols>';
                foreach (array_values($o['cols']) as $i => $w) {
                    $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . self::numText((float)$w) . '" customWidth="1"/>';
                }
                $x .= '</cols>';
            }
            return $x . '<sheetData>';
        }

        private function sheetTail(array $sh): string
        {
            $x = '</sheetData>';
            if ($sh['filter']) {
                $x .= '<autoFilter ref="' . self::xml($sh['filter']) . '"/>';
            }
            if ($sh['merges']) {
                $x .= '<mergeCells count="' . count($sh['merges']) . '">';
                foreach ($sh['merges'] as $m) {
                    $x .= '<mergeCell ref="' . self::xml($m) . '"/>';
                }
                $x .= '</mergeCells>';
            }
            if ($sh['links']) {
                $x .= '<hyperlinks>';
                foreach ($sh['links'] as [$ref, $loc, $tip]) {
                    $x .= '<hyperlink ref="' . self::xml($ref) . '" location="' . self::xml($loc) . '"' . ($tip !== '' ? ' tooltip="' . self::xml($tip) . '"' : '') . ' display="' . self::xml($ref) . '"/>';
                }
                $x .= '</hyperlinks>';
            }
            $x .= '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>';
            $o = $sh['opts'];
            if (!empty($o['landscape'])) {
                $x .= '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
            }
            $foot = (string)($o['footer'] ?? '');
            $x .= '<headerFooter><oddFooter>' . self::xml('&L' . str_replace('&', '&&', $foot) . '&RPage &P of &N') . '</oddFooter></headerFooter>';
            return $x . '</worksheet>';
        }

        // ---------------------------------------------------------------- zip

        private $zout;
        private $zcentral = '';
        private $zcount = 0;

        private function zipBegin(): void
        {
            $this->zout = fopen('php://temp/maxmemory:4194304', 'w+b');
            $this->zcentral = '';
            $this->zcount = 0;
        }

        /** $data: string, or a stream resource (read from its start). */
        private function zipAdd(string $name, $data, bool $deflate = true): void
        {
            $offset = ftell($this->zout);
            $gp = 1 << 11;       // UTF-8 names
            fwrite($this->zout, "PK\x03\x04" . pack('vvvvvVVVvv', 20, $gp, $deflate ? 8 : 0, 0, (0 << 9) | (1 << 5) | 1, 0, 0, 0, strlen($name), 0) . $name);
            $crc = hash_init('crc32b');
            $usize = 0;
            $csize = 0;
            $ctx = $deflate ? deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]) : null;
            $feed = function (string $chunk) use (&$crc, &$usize, &$csize, $ctx) {
                hash_update($crc, $chunk);
                $usize += strlen($chunk);
                $out = $ctx ? deflate_add($ctx, $chunk, ZLIB_NO_FLUSH) : $chunk;
                $csize += strlen($out);
                fwrite($this->zout, $out);
            };
            if (is_resource($data)) {
                rewind($data);
                while (!feof($data)) {
                    $chunk = fread($data, 65536);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $feed($chunk);
                }
            } else {
                $feed((string)$data);
            }
            if ($ctx) {
                $tail = deflate_add($ctx, '', ZLIB_FINISH);
                $csize += strlen($tail);
                fwrite($this->zout, $tail);
            }
            $crcInt = hexdec(hash_final($crc));
            $end = ftell($this->zout);
            fseek($this->zout, $offset + 14);
            fwrite($this->zout, pack('VVV', $crcInt, $csize, $usize));
            fseek($this->zout, $end);
            $this->zcentral .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, $gp, $deflate ? 8 : 0, 0, (0 << 9) | (1 << 5) | 1, $crcInt, $csize, $usize, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            $this->zcount++;
        }

        private function zipEnd(): void
        {
            $cdOffset = ftell($this->zout);
            fwrite($this->zout, $this->zcentral . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $this->zcount, $this->zcount, strlen($this->zcentral), $cdOffset, 0));
            $this->zcentral = '';
        }

        /**
         * Assemble the workbook. Returns ['ok'=>true, 'stream'=>resource positioned at 0, 'size'=>bytes] or ['ok'=>false, 'error'=>...].
         * The writer cannot be used afterwards.
         */
        public function finish(): array
        {
            if (!$this->sheets) {
                return ['ok' => false, 'error' => 'A workbook needs at least one sheet.'];
            }
            $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
            $hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
            $this->zipBegin();
            $ct = $hdr . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
            $rels = '';
            $wbSheets = '';
            $names = '';
            foreach ($this->sheets as $i => $sh) {
                $n = $i + 1;
                $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
                $wbSheets .= '<sheet name="' . self::xml($sh['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
                $q = "'" . str_replace("'", "''", $sh['name']) . "'";
                if (!empty($sh['opts']['repeat_rows'])) {
                    $names .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $i . '">' . self::xml($q . '!$' . (int)$sh['opts']['repeat_rows'][0] . ':$' . (int)$sh['opts']['repeat_rows'][1]) . '</definedName>';
                }
                if ($sh['filter'] && preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $sh['filter'], $m)) {
                    $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">' . self::xml($q . '!$' . $m[1] . '$' . $m[2] . ':$' . $m[3] . '$' . $m[4]) . '</definedName>';
                }
            }
            $k = count($this->sheets);
            $rels .= '<Relationship Id="rId' . ($k + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '<Relationship Id="rId' . ($k + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
            $ct .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>';

            $this->zipAdd('[Content_Types].xml', $ct);
            $this->zipAdd('_rels/.rels', $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
            $this->zipAdd('docProps/core.xml', $hdr . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                . '<dc:title>' . self::xml(self::cleanText((string)$this->opts['title'])) . '</dc:title><dc:creator>' . self::xml(self::cleanText((string)$this->opts['creator'])) . '</dc:creator>'
                . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
            $this->zipAdd('xl/workbook.xml', $hdr . '<workbook ' . $ns . '><workbookPr/><bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $wbSheets . '</sheets>'
                . ($names !== '' ? '<definedNames>' . $names . '</definedNames>' : '') . '</workbook>');
            $this->zipAdd('xl/_rels/workbook.xml.rels', $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
            $this->zipAdd('xl/styles.xml', $this->stylesXml());
            foreach ($this->sheets as $i => &$sh) {
                $buf = fopen('php://temp/maxmemory:2097152', 'w+b');
                fwrite($buf, $this->sheetHead($sh));
                rewind($sh['buf']);
                stream_copy_to_stream($sh['buf'], $buf);
                fwrite($buf, $this->sheetTail($sh));
                fclose($sh['buf']);
                $sh['buf'] = null;
                $this->zipAdd('xl/worksheets/sheet' . ($i + 1) . '.xml', $buf);
                fclose($buf);
            }
            unset($sh);
            $sx = fopen('php://temp/maxmemory:2097152', 'w+b');
            fwrite($sx, $hdr . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $this->sstRefs . '" uniqueCount="' . count($this->sstList) . '">');
            foreach ($this->sstList as $t) {
                fwrite($sx, '<si><t xml:space="preserve">' . self::xml($t) . '</t></si>');
            }
            fwrite($sx, '</sst>');
            $this->zipAdd('xl/sharedStrings.xml', $sx);
            fclose($sx);
            $this->zipEnd();
            $size = ftell($this->zout);
            rewind($this->zout);
            $stream = $this->zout;
            $this->zout = null;
            $this->sstList = [];
            $this->sst = [];
            return ['ok' => true, 'stream' => $stream, 'size' => $size];
        }
    }
}
