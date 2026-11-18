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

    /** "66 shots = 2.2 bottles (30 shots in one bottle: your answer)"; plain stock written next to it is added in. */
    function import_measure_text(float $plain, float $amount, string $word, float $n, string $unit, float $result, string $how): string
    {
        $fmt = 'import_measure_fmt';
        $s = ($plain > 0 ? $fmt($plain) . ' ' . import_measure_plural($unit, $plain) . ' + ' : '') . $fmt($amount) . ' ' . $word . ' = ' . $fmt($result) . ' ' . import_measure_plural($unit, $result)
            . ' (' . $fmt($n) . ' ' . import_measure_plural(import_measure_singular($word), $n) . ' in one ' . $unit . ': ' . $how . ').';
        return $s;
    }
}
