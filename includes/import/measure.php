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
     * $allowed = [key => [field => true]] (only the blanks of that row: served_by | portion_ml | container_ml), $fn = the suggester or null (off / missing).
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
            if ($field === 'served_by') {
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
}
