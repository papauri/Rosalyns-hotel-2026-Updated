<?php
/**
 * Import engine - quantities counted in a small measure (Step U2 d, owner 2026-11-19: "make the math simple for everyone"). Pure: no database.
 * A bar sheet can count part of its stock in shots / tots / glasses / pours. Such a quantity is turned into the item's stock unit with N
 * (how many of the measure make one stock unit), never guessed:
 *   1. the item's own count measure (Step U1, stock kept in the bigger unit, same small word);
 *   2. else its size: ml in one stock unit (a volume unit, the item's saved size, or a size in its name) / ml of that measure on the hotel's
 *      measures list (same name);
 *   3. else the person is asked "How many tots in one bottle?" (1..RH_STOCK_MEASURE_MAX_N); items with the same size share one question.
 * Only an ANSWERED N is saved on the item (at commit, rh_stock_measure_save). Costs are never divided by N. Measure words are the hotel's
 * portion words (import_aliases kind portion_word) minus the generic ones below, which name no fixed amount of a drink.
 *
 *   import_measure_words($aliases, $list)   portion words a quantity can be counted in (normalised word => true), plus the hotel's own
 *                                           Drink measures list names (one-word names, one and many: jigger / jiggers)
 *   import_measure_in_text($text, $words)   the first measure word written in a heading, or null
 *   import_measure_cell($v, $words)         "66 shots" -> ['value'=>66, 'word'=>'shots'] (number and word both from that cell), or null
 *   import_measure_decide(...)              where N comes from for one item: none / wait / n / ask
 *   import_count_in_read(...)               the count-unit question (owner 2026-11-22): is a served-by cell a measure a plain count could be in?
 *   import_count_in_step3($rd, $nk, $sizes)  the step 3 bottle / shot / glass sizes that give N for a count answered "in shots"
 *   import_qty_words($qty, $cn)             "57 shots = 2 bottles + 7 shots" (display only; the stored figure is never changed)
 */

require_once __DIR__ . '/text-util.php';

if (!function_exists('import_measure_words')) {

    /** Portion words that are not a measure a count can be in: "Unit of Measure", "Singles", "Portions" (a kitchen portion is not N to a bottle). */
    function import_measure_generic_words(): array
    {
        return ['measure', 'measures', 'single', 'singles', 'double', 'doubles', 'dash', 'dashes', 'portion', 'portions', 'serving', 'servings'];
    }

    /**
     * $list = the hotel's Drink measures list ([['name','ml']], Stock Items). A one-word name on it is a measure word even when it is one of the
     * generic words above ("double 60 ml": the hotel has said what one holds). Names of more than one word ("half tot") are not read from a
     * sheet: such a count is still asked about, never guessed.
     */
    function import_measure_words(array $aliases, array $list = []): array
    {
        $skip = array_flip(import_measure_generic_words());
        $out = [];
        foreach ((array)($aliases['portion_words'] ?? []) as $w) {
            $n = import_norm_text((string)$w);
            if ($n !== '' && strpos($n, ' ') === false && !isset($skip[$n])) {
                $out[$n] = true;
            }
        }
        foreach ($list as $dm) {
            $n = is_array($dm) ? import_norm_text((string)($dm['name'] ?? '')) : '';
            if ($n === '' || strpos($n, ' ') !== false || preg_match('/^(ml|mls|cl|l)$/', $n)) {
                continue;
            }
            $out[$n] = true;
            $out[import_norm_text(import_measure_plural($n, 2))] = true;
        }
        return $out;
    }

    /** "shots" -> "shot", "glasses" -> "glass", "tots" -> "tot" (comparison only; never written anywhere). */
    function import_measure_singular(string $w): string
    {
        $w = mb_strtolower(trim($w, " .\t"));
        if (preg_match('/^(.+?)(\s+of\s+.+)$/u', $w, $of)) {
            return import_measure_singular($of[1]) . $of[2];      // "glasses of wine" -> "glass of wine" (the plural of import_measure_plural)
        }
        if (preg_match('/^(.+(?:ss|sh|ch|x))es$/u', $w, $m)) {
            return $m[1];
        }
        if (mb_strlen($w) > 3 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') {
            return mb_substr($w, 0, -1);
        }
        return $w;
    }

    /** One of / some of: "shot" / "shots", "glass" / "glasses", "bottle" / "bottles"; short unit words ("L", "kg", "ml") stay as they are. */
    function import_measure_plural(string $w, float $qty): string
    {
        $w = trim($w);
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(ml|cl|l)$/iu', $w, $vm)) {
            // a bare volume served by ("25ml"): "25 ml measure" / "25 ml measures", never "25mls"
            return $vm[1] . ' ' . mb_strtolower($vm[2]) . (abs($qty - 1.0) < 1e-9 ? ' measure' : ' measures');
        }
        if (abs($qty - 1.0) < 1e-9 || mb_strlen($w) <= 2) {
            return $w;
        }
        if (preg_match('/^(.+?)(\s+of\s+.+)$/iu', $w, $of)) {
            return import_measure_plural($of[1], $qty) . $of[2];        // "glass of wine" -> "glasses of wine" (never "glass of wines")
        }
        if (preg_match('/(ss|sh|ch|x)$/iu', $w)) {
            return $w . 'es';
        }
        if (preg_match('/s$/iu', $w)) {
            return $w;                          // already plural ("tots", "pours")
        }
        if (preg_match('/[^aeiou]y$/iu', $w)) {
            return mb_substr($w, 0, -1) . 'ies';
        }
        return $w . 's';
    }

    /** The first measure word written in a heading ("Bar Balance (tots)" -> "tots"), or null. */
    function import_measure_in_text(string $text, array $words): ?string
    {
        foreach (explode(' ', import_norm_text($text)) as $tok) {
            if ($tok !== '' && isset($words[$tok])) {
                return $tok;
            }
        }
        return null;
    }

    /** True when the item's own name has the measure word in it ("Wine Glass", "Shot Glass"): glassware counted in glasses is a count of things. */
    function import_measure_in_name(string $name, string $word): bool
    {
        $s = import_measure_singular($word);
        foreach (explode(' ', import_norm_text($name)) as $tok) {
            if ($tok !== '' && import_measure_singular($tok) === $s) {
                return true;
            }
        }
        return false;
    }

    /** "66 shots" / "12.5 tots" -> ['value'=>66, 'word'=>'shots'] when the word is a measure word; the number is exactly the cell's digits. */
    function import_measure_cell($v, array $words): ?array
    {
        $h = import_heal_number_unit($v, null);
        if ($h === null) {
            return null;
        }
        $w = import_norm_text((string)$h['unit']);
        return isset($words[$w]) ? ['value' => $h['value'], 'word' => (string)$h['unit']] : null;
    }

    function import_measure_max_n(): float
    {
        return defined('RH_STOCK_MEASURE_MAX_N') ? (float)RH_STOCK_MEASURE_MAX_N : 1000.0;
    }

    /** A typed N: a number 1..max (4 dp, the same as the item's count measure), else null. */
    function import_measure_n_valid($v): ?float
    {
        if (is_string($v)) {
            $v = trim($v);
        }
        if (!is_int($v) && !is_float($v) && !(is_string($v) && preg_match('/^\d+(?:\.\d+)?$/', $v))) {
            return null;
        }
        $n = round((float)$v, 4);
        return (is_finite($n) && $n >= 1 && $n <= import_measure_max_n()) ? $n : null;
    }

    /** ml in one stock unit from the unit itself (L = 1000, cl = 10, ml = 1), else null. Same spellings as rh_drink_unit_ml(). */
    function import_measure_unit_ml(string $unit): ?float
    {
        $u = mb_strtolower(trim($unit));
        if (preg_match('/^(ml|mls|millilit(re|er)s?)$/u', $u)) {
            return 1.0;
        }
        if (preg_match('/^(cl|centilit(re|er)s?)$/u', $u)) {
            return 10.0;
        }
        if (preg_match('/^(l|ltrs?|litres?|liters?)$/u', $u)) {
            return 1000.0;
        }
        return null;
    }

    /** The one size written in a name ("Gin 750ml" -> 750, "Vodka 1L" -> 1000); null when none or two different sizes. */
    function import_measure_name_ml(string $name): ?float
    {
        $found = [];
        foreach (import_infer_size_tokens($name) as $t) {
            if ($t['kind'] !== 'volume' || !preg_match('/^(\d+(?:[.,]\d+)?)\s?([a-z]+)$/u', $t['token'], $m)) {
                continue;
            }
            $v = (float)str_replace(',', '.', $m[1]);
            $u = $m[2];
            $ml = $u === 'ml' ? $v : ($u === 'cl' ? $v * 10 : $v * 1000);
            if ($ml > 0 && $ml <= 100000) {
                $found[(string)round($ml, 2)] = $ml;
            }
        }
        return count($found) === 1 ? (float)array_values($found)[0] : null;
    }

    /**
     * Where N comes from for one item. $in: word (as written), stock_unit (null = not chosen yet), name, existing (ctx row or null: its
     * '_measure' and '_ml' are filled by import_job_ctx), list (hotel measures [['name','ml']]), synonyms, nk (item key).
     * Returns ['mode' => 'none'|'wait'|'n'|'ask', 'n', 'how' ('item'|'size'), 'size_ml', 'measure_ml', 'key' (ask: the shared-question key), 'kept' (an
     * existing item's own different measure, kept as it is)].
     */
    function import_measure_decide(array $in): array
    {
        $w = import_measure_singular((string)$in['word']);
        $unit = trim((string)($in['stock_unit'] ?? ''));
        $ex = $in['existing'] ?? null;
        $out = ['mode' => 'wait', 'n' => null, 'how' => '', 'size_ml' => null, 'measure_ml' => null, 'key' => '', 'kept' => null];
        if ($unit === '') {
            return $out;
        }
        $syn = (array)($in['synonyms'] ?? []);
        $unitS = import_measure_singular(mb_strtolower((string)($syn[mb_strtolower($unit)] ?? $unit)));
        if ($unitS === $w) {
            $out['mode'] = 'none';           // the stock unit IS the measure: the count is already in it
            return $out;
        }
        $m = is_array($ex) ? ($ex['_measure'] ?? null) : null;
        // v11: the file's own values ("Shots per bottle" = N, "Bottle size", "Shot size"), already checked by the resolver (import_file_meas_check):
        // only values nothing certain disagrees with arrive here. skip_own = the person chose the file's N over the item's own measure (Change).
        $fl = (isset($in['file']) && is_array($in['file'])) ? $in['file'] : [];
        $flPos = function ($v): ?float {
            return is_numeric($v) && (float)$v > 0 && is_finite((float)$v) ? (float)$v : null;
        };
        $skipOwn = !empty($fl['skip_own']);
        if (is_array($m) && import_measure_singular((string)$m['small']) === $w && $skipOwn && ($m['side'] ?? '') === 'big') {
            $m = null;
        }
        if (is_array($m) && import_measure_singular((string)$m['small']) === $w) {
            if (($m['side'] ?? '') === 'small') {
                $out['mode'] = 'none';
                return $out;
            }
            $out['mode'] = 'n';
            $out['n'] = (float)$m['n'];
            $out['how'] = 'item';
            return $out;
        }
        if (is_array($m)) {
            $out['kept'] = $m;
        }
        $fileN = $flPos($fl['n'] ?? null);
        if ($fileN !== null && import_measure_n_valid($fileN) !== null) {
            // v11: N written in the file ("Shots per bottle" 30): certain, used as written
            $out['mode'] = 'n';
            $out['n'] = round($fileN, 4);
            $out['how'] = 'file';
            $out['n_src'] = (string)($fl['n_src'] ?? '');
            return $out;
        }
        // a container stock unit (crate, case, pack ...): "Beer 330ml" is the size of ONE bottle in it, not of the crate, so the name never gives N;
        // only the item's own measure (above), a volume typed on the item for its stock unit, or the person's answer
        $isCont = false;
        foreach ((array)($in['containers'] ?? []) as $cw) {
            if (import_measure_singular(mb_strtolower(trim((string)$cw))) === $unitS) {
                $isCont = true;
                break;
            }
        }
        if (!$isCont && function_exists('rh_stock_unit_is_container')) {
            $isCont = rh_stock_unit_is_container($unit) || rh_stock_unit_is_container($unitS);
        }
        $size = null;
        if (is_array($ex)) {
            $exMl = isset($ex['_ml']) && is_numeric($ex['_ml']) && (float)$ex['_ml'] > 0 ? (float)$ex['_ml'] : null;
            $size = (!$isCont || ($ex['_ml_source'] ?? '') === 'item') ? $exMl : null;
        } else {
            $size = import_measure_unit_ml($unit) ?? ($isCont ? null : import_measure_name_ml((string)$in['name']));
        }
        $mml = null;
        foreach ((array)($in['list'] ?? []) as $dm) {
            if (is_array($dm) && isset($dm['name'], $dm['ml']) && import_measure_singular((string)$dm['name']) === $w && (float)$dm['ml'] > 0) {
                $mml = (float)$dm['ml'];
                break;
            }
        }
        // the count-unit answer "in shots" (owner 2026-11-22): the step 3 sizes come next after the item's own count measure, in the same order
        // as step 3 shows them (own saved size / volume unit > the row's typed size > a size in the name > the group's size; shot: row > cell >
        // group > the Drink measures list). Never an optional reading (a count is never set by a guess). Changing a size there re-works N here.
        $sizeFrom = $size !== null ? (is_array($ex) ? ((($ex['_ml_source'] ?? '') === 'name') ? 'name' : 'item') : (import_measure_unit_ml($unit) !== null ? 'unit' : 'name')) : '';
        $s3 = (isset($in['step3']) && is_array($in['step3'])) ? $in['step3'] : null;
        $mFrom = $mml !== null ? 'list' : '';
        // v11: the file's own sizes come right after what a person typed for this row: bottle = own saved size / volume unit > row typed > the file's
        // "Bottle size" > a size in the name > group; measure = row typed > served-by cell > the file's "Shot size" > group > list (import_count_in_step3)
        $fileMl = $flPos($fl['unit_ml'] ?? null);
        $sizeSrc = '';
        $mSrc = '';
        if ($s3 !== null) {
            $pos = function ($v): ?float {
                return is_numeric($v) && (float)$v > 0 && is_finite((float)$v) ? (float)$v : null;
            };
            $typedOk = !$isCont || !empty($s3['pour']);       // a crate holds bottles: a bottle size is never the crate's (a carton of juice is poured from)
            $rowMl = $typedOk ? $pos($s3['row_ml'] ?? null) : null;
            $grpMl = $typedOk ? $pos($s3['group_ml'] ?? null) : null;
            if ($fileMl !== null && $typedOk && !empty($fl['unit_first']) && ($size === null || $sizeFrom === 'name')) {
                // the person chose the file's bottle size over the one typed in step 3 (import_file_typed_check)
                [$size, $sizeFrom, $sizeSrc] = [$fileMl, 'file', (string)($fl['unit_ml_src'] ?? '')];
            } elseif ($rowMl !== null && ($size === null || $sizeFrom === 'name')) {
                [$size, $sizeFrom] = [$rowMl, 'row'];
            } elseif ($fileMl !== null && $typedOk && ($size === null || $sizeFrom === 'name')) {
                [$size, $sizeFrom, $sizeSrc] = [$fileMl, 'file', (string)($fl['unit_ml_src'] ?? '')];
            } elseif ($size === null && $grpMl !== null) {
                [$size, $sizeFrom] = [$grpMl, 'group'];
            }
            $sm = $pos($s3['measure_ml'] ?? null);
            if ($sm !== null) {
                [$mml, $mFrom] = [$sm, (string)($s3['measure_from'] ?? 'step3')];
                $mSrc = $mFrom === 'file' ? (string)($s3['measure_src'] ?? '') : '';
            } elseif (!empty($s3['unknown'])) {
                [$mml, $mFrom] = [null, ''];        // "Shot 30" with a 25 ml shot on the list: the size of this shot is not known (never the list's)
            }
        } else {
            if ($fileMl !== null && !$isCont && ($size === null || $sizeFrom === 'name')) {
                [$size, $sizeFrom, $sizeSrc] = [$fileMl, 'file', (string)($fl['unit_ml_src'] ?? '')];
            }
            $fm = $flPos($fl['measure_ml'] ?? null);
            if ($fm !== null) {
                [$mml, $mFrom, $mSrc] = [$fm, 'file', (string)($fl['measure_ml_src'] ?? '')];
            }
        }
        $out['size_from'] = $sizeFrom;
        $out['measure_from'] = $mFrom;
        $out['size_src'] = $sizeSrc;
        $out['measure_src'] = $mSrc;
        $out['size_ml'] = $size;
        $out['measure_ml'] = $mml;
        if ($size !== null && $mml !== null) {
            $n = round($size / $mml, 4);
            if ($n >= 1 && $n <= import_measure_max_n()) {
                $out['mode'] = 'n';
                $out['n'] = $n;
                $out['how'] = 'size';
                return $out;
            }
        }
        $out['mode'] = 'ask';
        // the same unknown for every item of the same size and stock unit ("how many tots in one 750 ml bottle?"); no size = this item only
        $out['key'] = $size !== null ? 'size|' . $w . '|' . round($size, 2) . '|' . $unitS : 'item|' . $w . '|' . (string)($in['nk'] ?? '');
        if ($s3 !== null && !empty($s3['unknown'])) {
            // "Shot 30" / "Tot 35": shared only with the items whose served-by cell writes that same number (never with a plain "Shot")
            $out['key'] .= '|cell:' . ($s3['cell_num'] !== null ? import_measure_fmt((float)$s3['cell_num']) : 'x' . (string)($in['nk'] ?? ''));
        }
        return $out;
    }

    /**
     * A row's quantity split into what is counted in a measure and what is plain stock (the validator's $row['qty_measure'] tags):
     * ['word', 'amount' (sum of the measure cells), 'plain' (sum of the other quantity cells), 'srcs'] or null. Nothing is converted here.
     */
    function import_measure_row_split(array $row): ?array
    {
        if (empty($row['qty_measure']) || !isset($row['computed']['qty_total']) || !is_numeric($row['computed']['qty_total']['value'])) {
            return null;
        }
        $amount = 0.0;
        $plain = 0.0;
        $srcs = [];
        $word = '';
        foreach (['qty_primary', 'qty_secondary', 'qty_single'] as $qf) {
            if (!isset($row['fields'][$qf])) {
                continue;
            }
            if (isset($row['qty_measure'][$qf])) {
                $amount += (float)$row['fields'][$qf]['value'];
                $srcs[] = (string)$row['fields'][$qf]['source'];
                $word = $word !== '' ? $word : (string)$row['qty_measure'][$qf]['word'];
            } else {
                $plain += (float)$row['fields'][$qf]['value'];
            }
        }
        return $word === '' ? null : ['word' => $word, 'amount' => $amount, 'plain' => $plain, 'srcs' => $srcs];
    }

    /** A question shared by several items (same measure + size): one entry in $extra with every item's rows and cells. Returns the merged question. */
    function import_measure_share_xq(array &$extra, array $xq, array $uids, array $cells): array
    {
        $k = (string)$xq['qkey'];
        $prevU = isset($extra[$k]) ? (array)$extra[$k]['row_uids'] : (array)$xq['row_uids'];
        $prevC = isset($extra[$k]) ? (array)$extra[$k]['cells'] : (array)$xq['cells'];
        $xq['row_uids'] = array_values(array_unique(array_merge(array_map('strval', $prevU), array_map('strval', $uids))));
        $xq['cells'] = array_values(array_unique(array_merge(array_map('strval', $prevC), array_map('strval', $cells))));
        $extra[$k] = $xq;
        return $xq;
    }

    /** Two count measures ['big','small','n','side'] are the same (names compared without case, one shot = shots, N to 4 decimals). */
    function import_measure_same(array $a, array $b): bool
    {
        return mb_strtolower(trim((string)($a['big'] ?? ''))) === mb_strtolower(trim((string)($b['big'] ?? '')))
            && import_measure_singular(mb_strtolower(trim((string)($a['small'] ?? '')))) === import_measure_singular(mb_strtolower(trim((string)($b['small'] ?? ''))))
            && abs((float)($a['n'] ?? 0) - (float)($b['n'] ?? 0)) < 0.00005 && (string)($a['side'] ?? '') === (string)($b['side'] ?? '');
    }

    /**
     * Why an item keeps its own count measure ['big','small','n'] while lines on open (draft / submitted) stock counts use it ($lines > 0):
     * rh_stock_measure_save would re-work those lines with a new N or read them in a new measure, so an import never offers a change then
     * (the same rule as the bulk setter). "it keeps 30 shots in one bottle: 2 open stock count lines use it; change it in Stock Items after the count is approved"
     */
    function import_measure_open_why(array $m, int $lines): string
    {
        $n = (float)($m['n'] ?? 0);
        return 'it keeps ' . import_measure_fmt($n) . ' ' . import_measure_plural((string)($m['small'] ?? ''), $n) . ' in one ' . (string)($m['big'] ?? '') . ': '
            . $lines . ' open stock count line' . ($lines === 1 ? ' uses' : 's use') . ' it; change it in ' . import_stock_page_name() . ' after the count is approved';
    }

    /**
     * Why a count measure the import would save cannot be kept on the item ('' = it can): the same limits as the Stock Items form
     * (rh_stock_measure_from_input: both names, at most 30 characters, different names, N 1..max at 4 decimals, side big / small).
     */
    function import_measure_save_problem(array $m): string
    {
        $big = trim((string)($m['big'] ?? ''));
        $small = trim((string)($m['small'] ?? ''));
        $n = (float)($m['n'] ?? 0);
        if ($big === '' || $small === '') {
            return 'the stock unit or the measure has no name';
        }
        if (mb_strlen($big) > 30 || mb_strlen($small) > 30) {
            return 'the name "' . (mb_strlen($big) > 30 ? $big : $small) . '" is longer than the 30 characters a count measure can have';
        }
        if (import_measure_singular(mb_strtolower($big)) === import_measure_singular(mb_strtolower($small))) {
            return 'the measure has the same name as the stock unit';
        }
        if (!is_finite($n) || $n < 1 || $n > import_measure_max_n() || abs(round($n, 4) - $n) > 1e-9) {
            return import_measure_fmt($n) . ' ' . import_measure_plural($small, 2) . ' in one ' . $big . ' cannot be kept as a count measure (1 to ' . import_measure_fmt(import_measure_max_n()) . ', 4 decimals)';
        }
        if (($m['side'] ?? '') !== 'big' && ($m['side'] ?? '') !== 'small') {
            return 'the stock side is not known';
        }
        return '';
    }

    /** 2.2 / 30 / 0.033333 (up to 6 decimals, no trailing zeros) */
    function import_measure_fmt(float $x): string
    {
        return rtrim(rtrim(number_format($x, 6, '.', ','), '0'), '.');
    }

    /**
     * The ONE volume written in a cell: "25ml", "Btl 750 ml", "75cl", "1L", "1.5 litres" -> [ml, the text without it]; null when there is
     * none, or two different ones (never a pick). "1,000ml" is a thousand; "1,5L" is one and a half. A bare number ("Glass 175") is not a volume.
     */
    function import_served_volume_in(string $t): ?array
    {
        if (!preg_match_all('/(?<![\d.,])(\d{1,3}(?:,\d{3})+|\d+(?:[.,]\d+)?)\s*(ml|mls|millilit(?:re|er)s?|cl|centilit(?:re|er)s?|l|lt|ltrs?|litres?|liters?)(?![\p{L}\d])/iu', $t, $m, PREG_SET_ORDER)) {
            return null;
        }
        $seen = [];
        foreach ($m as $x) {
            $num = preg_match('/^\d{1,3}(?:,\d{3})+$/', $x[1]) ? str_replace(',', '', $x[1]) : str_replace(',', '.', $x[1]);
            $u = mb_strtolower($x[2]);
            $ml = (float)$num * ($u[0] === 'm' ? 1 : ($u[0] === 'c' ? 10 : 1000));
            $seen[(string)round($ml, 2)] = $ml;
        }
        if (count($seen) !== 1) {
            return null;
        }
        $ml = round((float)array_values($seen)[0], 2);
        if ($ml <= 0 || $ml > 100000) {
            return null;
        }
        $rest = $t;
        foreach ($m as $x) {
            $rest = str_replace($x[0], ' ', $rest);
        }
        return [$ml, trim((string)preg_replace('/\s+/u', ' ', $rest))];
    }

    /**
     * v11: a size cell of a "Bottle size" / "Shot size" column in ml. "750ml", "750 ml", "75cl", "0.75 L" carry their own unit; a bare number
     * ("750", "25") is in the unit the heading names ($mul: "Bottle size (L)" = 1000), and with no unit in the heading it is ml only when it is 10 or
     * more ("0.75" or "1" with no unit could be litres: never guessed). Returns ['status' => ok|empty|invalid, 'ml' => float|null, 'why' => text].
     */
    function import_parse_volume($v, ?float $mul): array
    {
        $bad = function (string $why): array {
            return ['status' => 'invalid', 'ml' => null, 'why' => $why];
        };
        if ($v === null || is_bool($v)) {
            return $v === null ? ['status' => 'empty', 'ml' => null, 'why' => ''] : $bad('it is not a size');
        }
        $s = is_string($v) ? trim(str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $v)) : '';
        if (is_string($v) && import_is_empty_marker($s)) {
            return ['status' => 'empty', 'ml' => null, 'why' => ''];
        }
        $ml = null;
        $pn = (is_int($v) || is_float($v) || !preg_match('/\p{L}/u', $s)) ? import_parse_number($v) : null;
        if ($pn !== null) {
            if ($pn['status'] !== 'ok' || $pn['neg']) {
                return $bad('it is not a size');
            }
            $n = (float)$pn['value'];
            if ($mul !== null) {
                $ml = $n * $mul;
            } elseif ($n >= 10) {
                $ml = $n;
            } else {
                return $bad('a size under 10 with no unit could be litres or ml (write "750ml" or "0.75 L", or put the unit in the heading)');
            }
        } else {
            $vol = import_served_volume_in($s);
            if ($vol === null || trim((string)$vol[1]) !== '') {
                return $bad('it is not one size in ml, cl or litres');
            }
            $ml = (float)$vol[0];
        }
        $ml = round($ml, 2);
        if (!is_finite($ml) || $ml < 1 || $ml > 100000) {
            return $bad('a size must be from 1 to 100,000 ml');
        }
        return ['status' => 'ok', 'ml' => $ml, 'why' => ''];
    }

    /**
     * What one sale is, read from a "served by" cell (plain rules, owner 2026-11-20: hotels write it many ways). Never a guess:
     *   'unit'  the stock unit itself ("Bottle", "Btl", "Btl 750ml"): one main unit; a volume written with it is that unit's size (size_ml)
     *   'list'  a measure on the hotel's Drink measures list ("Shot", "shots", "Per shot", "1 shot"): measure = its name on the list
     *   'ml'    a volume written in the cell ("25ml", "Tot 25ml", "Glass 175 ml", "Per tot 25 ml"): one sale is that many ml (ml)
     *   'as_is' anything else ("Tot" when the list has no tot, "Glass 175" with no ml): the cell exactly as written
     * word = the measure word (singular, lower case) for the screen. $list = [['name','ml']]; $synonyms = unit_synonyms ("btl" => "bottle").
     */
    function import_served_read(string $served, string $unit, array $list, array $synonyms): array
    {
        $out = ['kind' => 'as_is', 'measure' => $served, 'word' => '', 'ml' => null, 'size_ml' => null];
        $t = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $served)));
        if ($t === '') {
            return $out;
        }
        $vol = import_served_volume_in($t);
        $rest = $vol ? $vol[1] : $t;
        $rest = trim((string)preg_replace('/^(?:per|a|an|one|1|x)\s+/u', '', $rest));
        $rest = trim((string)preg_replace('/\s+(?:of|x|@)$/u', '', $rest), " .-/()@");
        $one = function (string $w): string {      // "glasses of wine" -> "glass of wine", "shots" -> "shot"
            return preg_match('/^(.+?)(\s+of\s+.+)$/u', $w, $p) ? import_measure_singular($p[1]) . $p[2] : import_measure_singular($w);
        };
        $unitL = mb_strtolower(trim($unit));
        $restU = (string)($synonyms[$rest] ?? $synonyms[$one($rest)] ?? $rest);
        $unitU = (string)($synonyms[$unitL] ?? $unitL);
        if ($rest !== '' && $unitU !== '' && $one($restU) === $one($unitU)) {
            return ['kind' => 'unit', 'measure' => '', 'word' => '', 'ml' => null, 'size_ml' => $vol ? $vol[0] : null];
        }
        $listName = null;
        foreach ($list as $dm) {
            $n = is_array($dm) ? mb_strtolower(trim((string)($dm['name'] ?? ''))) : '';
            if ($rest !== '' && $n !== '' && ($rest === $n || $one($rest) === $one($n))) {
                $listName = (string)$dm['name'];
                break;
            }
        }
        if ($vol) {
            return ['kind' => 'ml', 'measure' => 'ml', 'word' => $listName !== null ? mb_strtolower($listName) : $one($rest), 'ml' => $vol[0], 'size_ml' => null];
        }
        if ($listName !== null) {
            return ['kind' => 'list', 'measure' => $listName, 'word' => mb_strtolower($listName), 'ml' => null, 'size_ml' => null];
        }
        $out['word'] = $one($rest);
        return $out;
    }

    /** A word list from the alias set (glass_words / pour_containers) as a set of normalised singular words. */
    function import_word_set(array $words): array
    {
        $o = [];
        foreach ($words as $w) {
            $n = import_norm_text((string)$w);
            if ($n !== '') {
                $o[$n] = true;
                $o[import_measure_singular($n)] = true;
            }
        }
        return $o;
    }

    /**
     * A served GLASS (owner 2026-11-20: "Glass could also mean a non standard glass, maybe for milk or juice, measured in ml optionally"):
     * the "served by" cell's word is a glass word ("Glass", "Glasses of wine", "Gls", "Tumbler"). Its size, when the cell writes one:
     * a volume ("250ml glass", "Glass 175 ml", "Glass 0.25 L") or a bare number of 10 or more next to the glass word ("Glass 175", "Gls 200" = ml). Never a guess:
     * no number = ml null (asked in step 3, optional). Returns ['word' => 'glass', 'ml' => ?float] or null (not a glass).
     */
    function import_glass_read(string $served, array $glassSet): ?array
    {
        $t = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $served)));
        if ($t === '' || !$glassSet) {
            return null;
        }
        $vol = import_served_volume_in($t);
        $rest = $vol ? $vol[1] : $t;
        $rest = trim((string)preg_replace('/^(?:per|a|an|one|1|x)\s+/u', '', $rest));
        $rest = trim((string)preg_replace('/\s+(?:of|x|@)$/u', '', $rest), " .-/()@");
        if (preg_match('/^(.+?)\s+of\s+.+$/u', $rest, $p)) {
            $rest = trim($p[1]);
        }
        $ml = $vol ? (float)$vol[0] : null;
        if (!$vol) {
            $num = null;
            if (preg_match('/^(\p{L}[\p{L} ]*?)\s*(\d{1,6}(?:\.\d+)?)$/u', $rest, $p)) {
                [$rest, $num] = [trim($p[1]), $p[2]];          // "glass 175", "gls200"
            } elseif (preg_match('/^(\d{1,6}(?:\.\d+)?)\s*(\p{L}[\p{L} ]*)$/u', $rest, $p)) {
                [$rest, $num] = [trim($p[2]), $p[1]];          // "250 glass"
            }
            if ($num !== null) {
                $n = (float)$num;
                $ml = ($n >= 10 && $n <= 100000) ? round($n, 2) : null;    // "Glass 2" is a count, not 2 ml: left blank (asked), never read as a size
            }
        }
        $w = import_norm_text($rest);
        if ($w === '' || (!isset($glassSet[$w]) && !isset($glassSet[import_measure_singular($w)]))) {
            return null;
        }
        return ['word' => import_measure_singular($w), 'ml' => $ml];
    }

    /**
     * Does an optional reading of a "served by" cell agree with the cell's own words? Checked by the plain rules BEFORE the reading is used:
     * - any number other than 1 in the cell ("2 tots", "x2") = more than one measure: dropped;
     * - a quantity word (alias quantity_words: half, double, dbl, triple ...) = dropped unless the reading names that same measure and no other served word is in the cell (the word
     *   itself, or the hotel measure it reads as): "Dbl tot" read as "shot" is dropped;
     * - otherwise every cell word the plain rules know (a hotel measure or its other name, a glass word, the stock unit) must be the same
     *   served word as the reading: "Pint glass" read as "shot" is dropped.
     * - a served or unit word of the alias lists ($vocabSet: portion, glass, container, unit words) that is not on the hotel's measures list must
     *   be the reading's own word: "Pint", "Scoop" or "Tot" read as "shot" or "glass" is dropped ("single", "one" and a plain 1 are neutral);
     *   this holds next to a quantity word too: "Double tot", "Double pint", "Double measure" read as "double" are dropped, only "Double" alone is kept.
     * The reading itself must be one measure the plain rules know (a hotel measure, the stock unit, a glass or ml). Returns false = leave blank.
     */
    function import_served_ai_fits(string $cell, string $aiServed, string $unit, array $list, array $synonyms, array $glassSet, array $qtySet, array $vocabSet): bool
    {
        $ai = import_served_read($aiServed, $unit, $list, $synonyms);
        $aiGlass = $ai['kind'] === 'as_is' ? import_glass_read($aiServed, $glassSet) : null;
        $aiWord = $ai['kind'] === 'list' ? 'm:' . $ai['word'] : ($ai['kind'] === 'unit' ? 'unit' : ($aiGlass !== null ? 'glass' : ($ai['kind'] === 'ml' ? 'ml' : '')));
        if ($aiWord === '') {
            return false;
        }
        $aiNorm = import_measure_singular(import_norm_text($aiServed));
        $t = mb_strtolower((string)preg_replace('/(?<=\d)(?=\p{L})|(?<=\p{L})(?=\d)/u', ' ', $cell));
        $t = (string)preg_replace('/(\d)[.,](\d)/u', '$1_$2', $t);           // keep "0.5" one token
        $words = preg_split('/\s+/u', trim((string)preg_replace('/[^\p{L}\p{N}_]+/u', ' ', $t))) ?: [];
        $wordOf = function (string $w) use ($unit, $list, $synonyms, $glassSet): string {
            $r = import_served_read($w, $unit, $list, $synonyms);
            if ($r['kind'] === 'list') {
                return 'm:' . $r['word'];
            }
            if ($r['kind'] === 'unit') {
                return 'unit';
            }
            return isset($glassSet[$w]) || isset($glassSet[import_measure_singular($w)]) ? 'glass' : '';
        };
        $qty = [];
        $base = [];
        foreach ($words as $w) {
            if ($w === '') {
                continue;
            }
            if (preg_match('/\p{N}/u', $w)) {      // any count but a plain 1 ("2", "0.5", "½") = not one measure
                if (!preg_match('/^\d+(?:_\d+)?$/u', $w) || (float)str_replace('_', '.', $w) != 1.0) {
                    return false;
                }
                continue;
            }
            if ($w === 'single' || $w === 'one') {
                continue;
            }
            if (isset($qtySet[$w]) || isset($qtySet[import_measure_singular($w)])) {
                $qty[] = $w;
                continue;
            }
            $m = $wordOf($w);
            if ($m !== '') {
                $base[] = $m;
            } elseif (isset($vocabSet[$w]) || isset($vocabSet[import_measure_singular($w)])) {
                // a served / unit word of the alias lists that is not on the hotel's measures list ("Pint", "Scoop", "Tot"): only the
                // same word may be read from it, never another measure
                $base[] = import_measure_singular($w) === $aiNorm ? $aiWord : 'w:' . import_measure_singular($w);
            }
        }
        if ($qty) {
            foreach ($qty as $w) {
                $m = $wordOf($w);
                if (!(($m !== '' && $m === $aiWord) || import_measure_singular($w) === $aiNorm)) {
                    return false;
                }
            }
        }
        foreach ($base as $m) {
            if ($m !== $aiWord) {
                return false;
            }
        }
        return true;
    }

    /**
     * A stock unit a drink is poured from by the glass (alias pour_containers: carton, box, casket, jug, can, tetra ...), as written, with the one
     * volume written in it ("Carton 1 L" -> ml 1000, read from that cell). Returns ['word' => 'carton', 'ml' => ?float] or null.
     */
    function import_pour_container(string $unit, array $pourSet): ?array
    {
        $t = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $unit)));
        if ($t === '' || !$pourSet) {
            return null;
        }
        $vol = import_served_volume_in($t);
        $w = import_norm_text($vol ? $vol[1] : $t);
        if ($w === '' || (!isset($pourSet[$w]) && !isset($pourSet[import_measure_singular($w)]))) {
            return null;
        }
        return ['word' => import_measure_singular($w), 'ml' => $vol ? (float)$vol[0] : null];
    }

    /** Stock units in one glass: glass ml / container ml, 6 dp (the same rounding as a recipe line); null when either is unknown or it rounds to 0. */
    function import_glass_qty(?float $glassMl, ?float $containerMl): ?float
    {
        if ($glassMl === null || $containerMl === null || !is_finite($glassMl) || !is_finite($containerMl) || $glassMl <= 0 || $containerMl <= 0) {
            return null;
        }
        $q = round($glassMl / $containerMl, 6);
        return $q > 0 ? $q : null;
    }

    /** The numbers written in a cell, in ml when a volume unit is written with one ("Shot 35" -> [35], "Tot 3.5cl" -> [35], "2 x 25ml" -> [2, 25]). */
    function import_cell_numbers(string $cell): array
    {
        $out = [];
        foreach (import_cell_number_tokens($cell)[1] as $x) {
            $out[] = $x['ml'];
        }
        return $out;
    }

    /** [the cell, lower case, digits and letters apart, [['text','ml'], ...]] (helper of import_cell_numbers / import_cell_strip_size). */
    function import_cell_number_tokens(string $cell): array
    {
        $t = mb_strtolower((string)preg_replace('/(?<=\d)(?=\p{L})|(?<=\p{L})(?=\d)/u', ' ', $cell));
        $toks = [];
        if (preg_match_all('/(?<![\d.,])(\d+(?:[.,]\d+)?)(?:\s*(ml|mls|cl|l|lt|ltrs?|litres?|liters?)(?![\p{L}]))?/u', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $v = (float)str_replace(',', '.', $x[1]);
                $u = (string)($x[2] ?? '');
                $toks[] = ['text' => $x[0], 'ml' => $u === '' || $u[0] === 'm' ? $v : ($u[0] === 'c' ? $v * 10 : $v * 1000)];
            }
        }
        return [$t, $toks];
    }

    /**
     * The cell with each written number equal to $ml taken out ("Shot 35" with 35 -> "shot"). Used ONLY to check the other words of a cell whose
     * size is checked on its own (a shot read together with its size from one cell); never shown, never stored.
     */
    function import_cell_strip_size(string $cell, float $ml): string
    {
        [$t, $toks] = import_cell_number_tokens($cell);
        foreach ($toks as $x) {
            if (abs($x['ml'] - $ml) < 0.005) {
                $t = str_replace($x['text'], ' ', $t);
            }
        }
        return trim((string)preg_replace('/\s+/u', ' ', $t));
    }

    /**
     * Does the cell itself show this shot size? True when it writes at least one number besides a plain 1 and every such number is $ml
     * ("Shot 35", "35ml tot"); false for "Shot" (nothing written: a size from elsewhere is not "read from the file"), "Shot 30" read as 35,
     * "2 x 25ml", "½ tot". An optional reading of a shot size is kept only when this holds.
     */
    function import_cell_shows_size(string $cell, float $ml): bool
    {
        if (preg_match('/\p{No}/u', $cell)) {
            return false;               // "½", "¼": part of a measure, never one size
        }
        $seen = false;
        foreach (import_cell_numbers($cell) as $v) {
            if (abs($v - 1.0) < 1e-9) {
                continue;
            }
            if (abs($v - $ml) >= 0.005) {
                return false;
            }
            $seen = true;
        }
        return $seen;
    }

    /**
     * Optional help reading what the plain rules could not (wiring only; the Assistant side is the AI lead's). $ask = [['key','name','unit','served']],
     * $allowed = [key => [field => true]] (only the blanks of that row: served_by | portion_ml | container_ml | menu | menu_cat), $fn = the suggester or null (off / missing).
     * Every suggestion is checked here: a known key, an allowed field, an ml of 0 < x <= 100000 (2 dp), a short plain served-by text; anything else is
     * dropped. Never throws. Returns [key => [field => ['v' => value, 'src' => plain text]]]: only ever a pre-fill the person can change or clear.
     */
    function import_measure_ai_merge(array $ask, array $allowed, ?callable $fn): array
    {
        if ($fn === null || !$ask) {
            return [];
        }
        try {
            $got = $fn(array_values($ask));
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach (is_array($got) ? $got : [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $key = (string)($s['key'] ?? '');
            $field = (string)($s['field'] ?? '');
            if ($key === '' || empty($allowed[$key][$field]) || isset($out[$key][$field])) {
                continue;
            }
            $src = trim((string)preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', mb_substr(is_scalar($s['source'] ?? null) ? (string)$s['source'] : '', 0, 120)));
            $v = $s['value'] ?? null;
            if ($field === 'column') {
                // #10: what a column holds: {field: measures_per_unit | unit_ml | measure_ml, measure, unit}; 'none' and anything else = nothing.
                // The hotel's words, the heading and the values are checked by import_col_ai_check() before anything is shown.
                $f = is_array($v) && is_string($v['field'] ?? null) ? trim((string)$v['field']) : '';
                $mw = is_array($v) && is_string($v['measure'] ?? null) ? mb_substr(trim((string)$v['measure']), 0, 40) : '';
                $uw = is_array($v) && is_string($v['unit'] ?? null) ? mb_substr(trim((string)$v['unit']), 0, 40) : '';
                if (!in_array($f, ['measures_per_unit', 'unit_ml', 'measure_ml'], true)) {
                    continue;
                }
                $out[$key][$field] = ['v' => ['field' => $f, 'measure' => $mw, 'unit' => $uw], 'src' => $src];
                continue;
            }
            if ($field === 'menu') {
                // which menu (2026-11-22): only "drink" or "food"; a suggestion only, shown as "Suggested" until a person accepts it
                $v = is_string($v) ? mb_strtolower(trim($v)) : '';
                $v = ['drink' => 'drink', 'drinks' => 'drink', 'food' => 'food', 'foods' => 'food'][$v] ?? '';
                if ($v === '') {
                    continue;
                }
            } elseif ($field === 'menu_cat') {
                $v = is_string($v) ? trim((string)preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', $v)) : '';
                if ($v === '' || mb_strlen($v) > 100) {
                    continue;
                }
            } elseif ($field === 'served_by') {
                $v = is_string($v) ? trim((string)preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', $v)) : '';
                if ($v === '' || mb_strlen($v) > 60) {
                    continue;
                }
            } else {
                if (is_string($v)) {
                    $v = trim(str_replace(',', '', preg_replace('/\s*ml$/i', '', $v)));
                }
                if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
                    continue;
                }
                $v = round((float)$v, 2);
                if (!is_finite($v) || $v <= 0 || $v > 100000) {
                    continue;
                }
            }
            $out[$key][$field] = ['v' => $v, 'src' => $src];
        }
        return $out;
    }

    /** "66 shots = 2.2 bottles (30 shots in one bottle: your answer)"; plain stock written next to it is added in. */
    function import_measure_text(float $plain, float $amount, string $word, float $n, string $unit, float $result, string $how): string
    {
        $fmt = 'import_measure_fmt';
        $s = ($plain > 0 ? $fmt($plain) . ' ' . import_measure_plural($unit, $plain) . ' + ' : '') . $fmt($amount) . ' ' . $word . ' = ' . $fmt($result) . ' ' . import_measure_plural($unit, $result)
            . ' (' . $fmt($n) . ' ' . import_measure_plural(import_measure_singular($word), $n) . ' in one ' . $unit . ': ' . $how . ').';
        return $s;
    }

    /**
     * The count-unit question (owner 2026-11-22: "57 = 57 shots, not bottles"): what a served-by cell says one sale is, when it is a measure a
     * plain count could be written in (a shot / tot, a glass, a pour / pint, a drink quantity word like "double", any name on the hotel's Drink
     * measures list, also with a bare number or other words next to it: "Shot 30", "Wine glass"). null = not such a measure (the stock unit
     * itself, a bare volume, "Can", "Each", words the plain rules cannot read).
     * Returns ['kind' => shot|glass|measure, 'word' (singular, lower case), 'cell_ml' (a size written in the cell), 'list_ml', 'gkey' (the step 3
     * size group of import_drinks_build: "m:shot|bottle", "gl:casket"), 'pour' (the stock unit is a carton / casket poured from)].
     */
    function import_count_in_read(string $served, string $unit, array $list, array $synonyms, array $shotSet, array $glassSet, array $pourSet = []): ?array
    {
        $served = trim($served);
        if ($served === '') {
            return null;
        }
        $r = import_count_in_exact($served, $unit, $list, $synonyms, $shotSet, $glassSet, $pourSet);
        if (is_array($r) || $r === 'unit') {
            return is_array($r) ? $r : null;
        }
        // QA 2026-11-22 ("nothing may be read as bottles silently when served by a measure"): the cell names a measure next to other text the
        // plain rules above do not read whole: a bare number ("Shot 30", "Tot 35": a number with no ml is never a size here, cell_ml stays
        // null), another word ("Wine glass", "Double tot"), a drink quantity word ("Double" not on the list). Still asked, never guessed.
        $t = mb_strtolower((string)preg_replace('/\s+/u', ' ', $served));
        $vol = import_served_volume_in($t);
        $cellMl = $vol ? (float)$vol[0] : null;
        $rest = $vol ? $vol[1] : $t;
        $rest = (string)preg_replace('/(?<=\d)(?=\p{L})|(?<=\p{L})(?=\d)/u', ' ', $rest);
        // QA LATER 2026-11-22 (fixed 2026-11-23): a bare number next to the measure ("Shot 30", "Tot 35", "½ tot") is no size, but it is not nothing
        // either: unless it is exactly the size the list gives that measure, the size of this row's measure is UNKNOWN (typed in step 3, a size
        // column of the file, or asked), never the list's or the group's size. A plain 1 ("1 shot") says nothing.
        $bare = [];
        if (preg_match_all('/(?<![\p{L}\p{N}])(\d+(?:[.,]\d+)?)(?![\p{L}\p{N}])|(\p{No})/u', $rest, $bm, PREG_SET_ORDER)) {
            foreach ($bm as $b) {
                $num = isset($b[2]) && $b[2] !== '' ? null : (float)str_replace(',', '.', $b[1]);
                if ($num === null || abs($num - 1.0) >= 1e-9) {
                    $bare[] = $num;
                }
            }
        }
        $rest = (string)preg_replace('/(?<![\p{L}\p{N}])\d+(?:[.,]\d+)?(?![\p{L}\p{N}])|\p{No}/u', ' ', $rest);
        $rest = trim((string)preg_replace('/\s+/u', ' ', $rest));
        $withMl = function (array $x) use ($cellMl, $bare): array {
            if ($cellMl !== null && $x['cell_ml'] === null) {
                $x['cell_ml'] = $cellMl;
            }
            if ($bare && $x['cell_ml'] === null) {
                $one = count(array_unique(array_map('strval', $bare))) === 1 && $bare[0] !== null ? (float)$bare[0] : null;
                $x['cell_num'] = $one;
                if (!($one !== null && $x['list_ml'] !== null && abs($one - (float)$x['list_ml']) < 0.005)) {
                    $x['list_ml'] = null;
                    $x['size_unknown'] = true;      // "Shot 30" with a 25 ml shot on the list: the 25 is never used for this row
                }
            }
            return $x;
        };
        if ($rest !== '' && $rest !== $t) {
            $r = import_count_in_exact($rest, $unit, $list, $synonyms, $shotSet, $glassSet, $pourSet);
            if (is_array($r) || $r === 'unit') {
                return is_array($r) ? $withMl($r) : null;
            }
        }
        $u = mb_strtolower(trim($unit));
        $unitS = $u !== '' ? import_measure_singular(mb_strtolower((string)($synonyms[$u] ?? $u))) : '';
        $qtySet = array_flip(['double', 'doubles', 'dbl', 'dbls', 'dble', 'triple', 'triples', 'treble', 'trebles', 'tpl', 'half', 'halves', 'halfs']);
        $measSet = array_flip(['single', 'singles', 'pour', 'pours', 'pint', 'pints', 'measure', 'measures']);
        $in = function (string $tok, array $set): bool {
            return isset($set[$tok]) || isset($set[import_measure_singular($tok)]);
        };
        $hasQ = false;
        $shotTok = $glassTok = $measTok = '';
        $words = [];
        foreach (explode(' ', import_norm_text($rest)) as $tok) {
            if ($tok === '' || in_array($tok, ['per', 'a', 'an', 'one', 'of', 'x', 'the', 'by'], true)) {
                continue;
            }
            $s = import_measure_singular($tok);
            if ($in($tok, $qtySet)) {
                $hasQ = true;
                $words[] = $s;
            } elseif ($in($tok, $shotSet)) {
                $shotTok = $shotTok !== '' ? $shotTok : $s;
                $words[] = $s;
            } elseif ($in($tok, $glassSet)) {
                $glassTok = $glassTok !== '' ? $glassTok : $s;
                $words[] = $s;
            } elseif ($in($tok, $measSet)) {
                $measTok = $measTok !== '' ? $measTok : $s;
                $words[] = $s;
            }
        }
        $pour = $u !== '' ? import_pour_container($unit, $pourSet) : null;
        $loose = function (string $w) use ($withMl, $pour): array {
            // a measure the hotel's list does not have under this name: no step 3 group, N comes from the item's own measure or is asked
            return $withMl(['kind' => 'measure', 'word' => $w, 'cell_ml' => null, 'list_ml' => null, 'gkey' => '', 'pour' => $pour !== null]);
        };
        if ($hasQ) {
            $w = implode(' ', array_unique($words));       // "double", "double tot", "half glass"
            return ($unitS !== '' && $unitS === $w) ? null : $loose($w);
        }
        foreach ([$shotTok, $glassTok, $measTok] as $one) {
            if ($one === '' || ($unitS !== '' && $unitS === $one)) {
                continue;
            }
            $r = import_count_in_exact($one, $unit, $list, $synonyms, $shotSet, $glassSet, $pourSet);
            if ($r === 'unit') {
                return null;
            }
            return is_array($r) ? $withMl($r) : $loose($one);
        }
        return null;
    }

    /**
     * import_count_in_read() for a cell the plain rules read whole: the read, 'unit' (served by the stock unit itself: nothing to ask) or null
     * (not read whole). A name on the hotel's Drink measures list is a measure even when it is generic ("double") or of more than one word.
     */
    function import_count_in_exact(string $served, string $unit, array $list, array $synonyms, array $shotSet, array $glassSet, array $pourSet = [])
    {
        $u = mb_strtolower(trim($unit));
        $pour = $u !== '' ? import_pour_container($unit, $pourSet) : null;
        $sv = import_served_read($served, $unit, $list, $synonyms);
        if ($sv['kind'] === 'unit') {
            return 'unit';            // served by the stock unit itself ("Bottle"): the count is in it, nothing to ask
        }
        $generic = array_flip(import_measure_generic_words());
        $gr = in_array($sv['kind'], ['ml', 'as_is'], true) ? import_glass_read($served, $glassSet) : null;
        if ($gr !== null) {
            return ['kind' => 'glass', 'word' => (string)$gr['word'], 'cell_ml' => $gr['ml'] !== null ? (float)$gr['ml'] : null, 'list_ml' => null,
                'gkey' => 'gl:' . ($pour !== null ? (string)$pour['word'] : $u), 'pour' => $pour !== null];
        }
        $w = import_measure_singular(mb_strtolower(trim($sv['kind'] === 'list' ? (string)$sv['measure'] : (string)$sv['word'])));
        $listMl = null;
        $listed = false;
        foreach ($list as $dm) {
            if (is_array($dm) && $w !== '' && import_measure_singular(mb_strtolower(trim((string)($dm['name'] ?? '')))) === $w) {
                $listed = true;
                $listMl = is_numeric($dm['ml'] ?? null) && (float)$dm['ml'] > 0 ? (float)$dm['ml'] : null;
                break;
            }
        }
        if ($w === '' || (!$listed && (strpos($w, ' ') !== false || isset($generic[$w]))) || ($u !== '' && import_measure_singular($u) === $w)) {
            return null;
        }
        if (isset($shotSet[$w])) {
            // the shot measure of the hotel's list (same name first, else its first shot measure), grouped the way step 3 groups it
            $shotName = $w;
            $listMl = null;
            foreach ([true, false] as $same) {
                foreach ($list as $dm) {
                    $dn = is_array($dm) ? import_measure_singular(mb_strtolower(trim((string)($dm['name'] ?? '')))) : '';
                    if ($dn !== '' && is_numeric($dm['ml'] ?? null) && (float)$dm['ml'] > 0 && ($same ? $dn === $w : isset($shotSet[$dn]))) {
                        [$listMl, $shotName] = [(float)$dm['ml'], mb_strtolower((string)$dm['name'])];
                        break 2;
                    }
                }
            }
            // a list measure of ANOTHER name ("Tot" with only "shot" on the list) gives the group, never the size of this tot
            return ['kind' => 'shot', 'word' => $w, 'cell_ml' => $sv['kind'] === 'ml' ? (float)$sv['ml'] : null,
                'list_ml' => import_measure_singular($shotName) === $w ? $listMl : null,
                'gkey' => ($sv['kind'] === 'as_is' && $listMl === null) ? '' : 'm:' . $shotName . '|' . $u, 'pour' => $pour !== null];
        }
        if ($listed) {
            // a measure on the hotel's list ("Double", "Half tot", "Double 50ml"): its own size from the list, a size written in the cell first
            return ['kind' => 'measure', 'word' => $w, 'cell_ml' => $sv['kind'] === 'ml' ? (float)$sv['ml'] : null, 'list_ml' => $listMl,
                'gkey' => 'm:' . ((string)$sv['word'] !== '' ? mb_strtolower((string)$sv['word']) : $w) . '|' . $u, 'pour' => $pour !== null];
        }
        return null;
    }

    /**
     * The step 3 sizes for one item counted in a measure ($rd = import_count_in_read, $nk = the item key, $sizes = answers['_sizes']): the typed
     * bottle / container size of the row and of its group, and the measure size (row > the cell > group > the Drink measures list). Only what a
     * person typed or the file / list says; never an optional reading. Fed to import_measure_decide() as 'step3'.
     */
    function import_count_in_step3(array $rd, string $nk, array $sizes, array $file = []): array
    {
        $pos = function ($v): ?float {
            return is_numeric($v) && (float)$v > 0 && is_finite((float)$v) ? (float)$v : null;
        };
        $rowA = is_array($sizes['r'][$nk] ?? null) ? $sizes['r'][$nk] : [];
        $grpA = ($rd['gkey'] ?? '') !== '' && is_array($sizes['g'][$rd['gkey']] ?? null) ? $sizes['g'][$rd['gkey']] : [];
        $f = $rd['kind'] === 'glass' ? 'glass' : ($rd['kind'] === 'shot' ? 'shot' : '');
        // v11: the file's "Shot size" / "Glass size" (checked by the resolver against the served-by cell; measure_first = the person chose it over
        // the cell's size). "Shot 30" with a different list size (size_unknown): never the list's size, and a group size only when it is that number.
        $fm = $pos($file['measure_ml'] ?? null);
        $unknown = !empty($rd['size_unknown']);
        $cellNum = isset($rd['cell_num']) && $rd['cell_num'] !== null ? (float)$rd['cell_num'] : null;
        $mm = null;
        $mf = '';
        $grpV = $f !== '' ? $pos($grpA[$f] ?? null) : null;
        $rowV = $f !== '' ? $pos($rowA[$f] ?? null) : null;
        $grpOk = $grpV !== null && (!$unknown || ($cellNum !== null && abs($grpV - $cellNum) < 0.005));
        if ($fm !== null && !empty($file['measure_over_typed'])) {
            [$mm, $mf] = [$fm, 'file'];             // the person chose the file's size over the one typed in step 3 (import_file_typed_check)
        } elseif ($rowV !== null) {
            [$mm, $mf] = [$rowV, 'row'];
        } elseif ($fm !== null && !empty($file['measure_first'])) {
            [$mm, $mf] = [$fm, 'file'];
        } elseif ($rd['cell_ml'] !== null) {
            [$mm, $mf] = [(float)$rd['cell_ml'], 'cell'];
        } elseif ($fm !== null) {
            [$mm, $mf] = [$fm, 'file'];
        } elseif ($grpOk) {
            [$mm, $mf] = [$grpV, 'group'];
        } elseif ($rd['list_ml'] !== null && !$unknown) {
            [$mm, $mf] = [(float)$rd['list_ml'], 'list'];
        }
        return ['row_ml' => $pos($rowA['ml'] ?? null), 'group_ml' => $pos($grpA['ml'] ?? null), 'measure_ml' => $mm, 'measure_from' => $mf, 'pour' => !empty($rd['pour']),
            'measure_src' => $mf === 'file' ? (string)($file['measure_ml_src'] ?? '') : '', 'unknown' => $unknown, 'cell_num' => $cellNum,
            'row_meas' => $rowV, 'group_meas' => $grpOk ? $grpV : null];      // the measure sizes typed in step 3 (compared with the file's)
    }

    /**
     * A quantity of an item counted in a measure, in words (owner 2026-11-22: "57 shots = 2 bottles + 7 shots"), the same rounding as the Stock
     * screens (rh_stock_qty_words: smalls to 1 dp, 29.99 of 30 is the next bottle). DISPLAY ONLY. $cn = ['n', 'small', 'big'] (N smalls in one big).
     * Whole bottles: "50 shots = 2 bottles"; under one bottle: "7 shots"; 0: "0 shots". 'exact' = the stored figure in the stock unit (6 dp).
     * No $cn (or a bad N) = today's text: the number and the unit.
     */
    function import_qty_words(float $qty, ?array $cn, string $unit = ''): array
    {
        $exact = import_measure_fmt($qty) . ($unit !== '' ? ' ' . import_measure_plural($unit, $qty) : '');
        $n = is_array($cn) ? (float)($cn['n'] ?? 0) : 0.0;
        if (!is_array($cn) || $n < 1 || !is_finite($n) || trim((string)($cn['small'] ?? '')) === '' || $qty < 0) {
            return ['text' => $exact, 'exact' => $exact];
        }
        $big = trim((string)($cn['big'] ?? '')) !== '' ? trim((string)$cn['big']) : $unit;
        $small = import_measure_singular(mb_strtolower(trim((string)$cn['small'])));
        $exact = import_measure_fmt($qty) . ' ' . import_measure_plural($big, $qty);
        $one = function (float $x): string {
            return rtrim(rtrim(number_format($x, 1, '.', ','), '0'), '.');
        };
        $smalls = round($qty * $n, 1);
        $bigs = (int)floor($smalls / $n + 1e-9);
        $rest = round($smalls - $bigs * $n, 1);
        if ($rest < 0.05) {
            $rest = 0.0;
        } elseif ($rest >= round($n, 1) - 1e-9) {
            $bigs++;
            $rest = 0.0;
        }
        $head = $one($smalls) . ' ' . import_measure_plural($small, $smalls);
        if ($bigs === 0) {
            return ['text' => $head, 'exact' => $exact];
        }
        $parts = $bigs . ' ' . import_measure_plural($big, (float)$bigs) . ($rest > 0 ? ' + ' . $one($rest) . ' ' . import_measure_plural($small, $rest) : '');
        return ['text' => $head . ' = ' . $parts, 'exact' => $exact];
    }
}
