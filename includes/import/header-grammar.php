<?php
/**
 * Import engine - a header GRAMMAR for the three "how many / how big" columns (owner 2026-11-23, build #10: "extend it to read other
 * wordings too that do the same thing so it's not always hard coded"). Pure: no database, no model.
 *
 *   measures_per_unit  "[count words] [measure] [connector] [container]"   "Glasses from a casket", "Tots in each bottle", "Shots/bottle",
 *                      or "[container] (= | : | x | a number) [measure]"     "No. of 25ml measures per btl", "Btl = 25 shots"
 *   unit_ml            "[container] (size | volume | capacity | ml | cl | l)" "Casket capacity (L)", "Size of bottle", "ml in a btl"
 *   measure_ml         "[measure] (size | volume | measure | ml | cl)"        "Tot ml", "Glass volume (cl)", "ml per shot"
 *
 * Every word of the heading must be a grammar word, or the grammar says nothing (the plain alias match stays as it was). The measure,
 * container and unit words are the HOTEL'S OWN lists (shot_words, glass_words, portion_words minus the generic ones, the Drink measures list
 * names, units / pour_containers / unit_synonyms); only the connectors, count words, size words and a handful of abbreviations are language.
 * A heading with a measure but no container ("Shots", "No of shots"), a size with no container or measure ("Volume (ml)"), a generic word
 * ("Unit size", "Portion size") or a container that is also a pack ("Box size": ml or units in the box?) is ASKED, never read on its own.
 *
 *   import_header_grammar($header, $aliases)   null | ['field', 'ask' => bool, 'conf', 'alts' => [fields a question offers], 'measure', 'unit', 'ml_mul']
 *   import_col_ai_check($s, $col, $aliases)    an Assistant suggestion for a column, checked against the hotel's words, the heading and the values
 *   import_col_label($field, $measure, $unit)  "Shots per bottle", "Bottle size (ml)", "Shot size (ml)"
 */

require_once __DIR__ . '/text-util.php';
require_once __DIR__ . '/measure.php';

if (!function_exists('import_header_grammar')) {

    /** The three read fields this grammar knows. */
    function import_hg_fields(): array
    {
        return ['measures_per_unit', 'unit_ml', 'measure_ml'];
    }

    /** Language, not hotel facts: abbreviations hotels write in headings that the unit lists may not carry. */
    function import_hg_abbreviations(): array
    {
        return ['bttl' => 'bottle', 'bttls' => 'bottle', 'btle' => 'bottle', 'btles' => 'bottle', 'cskt' => 'casket', 'cskts' => 'casket'];
    }

    /** ml per unit word of a size ("ml" = 1, "cl" = 10, "l" / "litres" = 1000). */
    function import_hg_volume_units(): array
    {
        return ['ml' => 1.0, 'mls' => 1.0, 'millilitre' => 1.0, 'millilitres' => 1.0, 'milliliter' => 1.0, 'milliliters' => 1.0,
            'cl' => 10.0, 'cls' => 10.0, 'centilitre' => 10.0, 'centilitres' => 10.0, 'centiliter' => 10.0, 'centiliters' => 10.0,
            'l' => 1000.0, 'lt' => 1000.0, 'lts' => 1000.0, 'ltr' => 1000.0, 'ltrs' => 1000.0, 'litre' => 1000.0, 'litres' => 1000.0, 'liter' => 1000.0, 'liters' => 1000.0];
    }

    /** The word classes, built from the hotel's own lists (cached per list content). */
    function import_hg_vocab(array $aliases): array
    {
        static $lastIn = null;
        static $lastOut = null;
        $sub = [$aliases['units'] ?? [], $aliases['pour_containers'] ?? [], $aliases['unit_synonyms'] ?? [], $aliases['shot_words'] ?? [], $aliases['glass_words'] ?? [],
            $aliases['portion_words'] ?? [], $aliases['container_words'] ?? [], $aliases['measure_names'] ?? []];
        if ($lastIn !== null && $sub === $lastIn) {
            return $lastOut;
        }
        $generic = [];
        foreach (import_measure_generic_words() as $w) {
            if (!in_array($w, ['double', 'doubles'], true)) {        // a double is a measure of its own ("Doubles per bottle", as import_per_unit_words)
                $generic[$w] = true;
            }
        }
        $glass = import_word_set((array)($aliases['glass_words'] ?? []));
        $meas = [];         // word => canonical measure word (singular, lower case)
        $addM = function (string $w) use (&$meas, $generic, $glass) {
            $n = import_norm_text($w);
            if ($n === '' || strpos($n, ' ') !== false || isset($generic[$n])) {
                return;
            }
            $s = import_measure_singular($n);
            $c = (isset($glass[$n]) || isset($glass[$s])) ? 'glass' : $s;       // "Gls" / "Glas" are a glass
            $meas[$n] = $c;
            $meas[$s] = $c;
        };
        foreach (array_merge((array)($aliases['shot_words'] ?? []), (array)($aliases['glass_words'] ?? []), (array)($aliases['portion_words'] ?? []), ['double', 'doubles']) as $w) {
            $addM((string)$w);
        }
        foreach ((array)($aliases['measure_names'] ?? []) as $w) {
            $n = import_norm_text((string)$w);
            if ($n === '' || strpos($n, ' ') !== false || isset(import_hg_volume_units()[$n])) {
                continue;
            }
            $meas[$n] = import_measure_singular($n);
            $meas[import_norm_text(import_measure_plural($n, 2))] = import_measure_singular($n);
        }
        $syn = [];
        foreach ((array)($aliases['unit_synonyms'] ?? []) as $k => $v) {
            $syn[mb_strtolower((string)$k)] = (string)$v;
        }
        foreach (import_hg_abbreviations() as $k => $v) {
            $syn[$k] = $syn[$k] ?? $v;
        }
        $pour = import_word_set((array)($aliases['pour_containers'] ?? []));
        $packs = import_word_set((array)($aliases['container_words'] ?? []));      // crate, case, pack, carton, box ...
        $vol = import_hg_volume_units();
        $notUnit = ['each' => true, 'ea' => true, 'unit' => true, 'units' => true, 'kg' => true, 'kgs' => true, 'kilo' => true, 'kilos' => true, 'kilogram' => true,
            'kilograms' => true, 'g' => true, 'gm' => true, 'gms' => true, 'gram' => true, 'grams' => true, 'gallon' => true, 'gallons' => true];
        $cont = [];         // word => ['canon' => singular container word, 'pack' => bool (also a pack word: carton, box)]
        foreach (array_merge((array)($aliases['units'] ?? []), (array)($aliases['pour_containers'] ?? []), array_keys($syn)) as $w) {
            $n = import_norm_text((string)$w);
            if ($n === '' || strpos($n, ' ') !== false || isset($notUnit[$n]) || isset($vol[$n]) || isset($meas[$n])) {
                continue;
            }
            $canon = mb_strtolower((string)($syn[$n] ?? $syn[import_measure_singular($n)] ?? $n));
            $cn = import_norm_text($canon);
            if (isset($vol[$cn]) || isset($notUnit[$cn]) || in_array($cn, ['kg', 'g', 'pcs'], true)) {
                continue;                                                   // a weight / volume / piece, never a container
            }
            $isPack = isset($packs[$n]) || isset($packs[$cn]);
            if ($isPack && !isset($pour[$n]) && !isset($pour[$cn])) {
                continue;                                                   // crate / case / pack hold bottles: never "one unit" of a drink
            }
            $cont[$n] = ['canon' => import_measure_singular($cn), 'pack' => $isPack];
        }
        $lastIn = $sub;
        return $lastOut = ['meas' => $meas, 'generic' => $generic, 'cont' => $cont, 'vol' => $vol,
            'size' => ['size' => true, 'sizes' => true, 'volume' => true, 'volumes' => true, 'vol' => true, 'capacity' => true, 'content' => true, 'contents' => true],
            'count' => ['no' => true, 'number' => true, 'num' => true, 'nr' => true, '#' => true, 'how' => true, 'many' => true, 'qty' => true, 'quantity' => true],
            'conn' => ['per' => true, 'in' => true, 'to' => true, 'from' => true, 'each' => true, 'ea' => true, 'a' => true, 'an' => true, 'one' => true, 'the' => true,
                'of' => true, 'x' => true, '/' => true, '=' => true, ':' => true],
            'generic_unit' => ['unit' => true, 'units' => true]];
    }

    /** "No. of 25ml measures / btl" -> no, of, 25, ml, measures, /, btl. */
    function import_hg_tokens(string $header): array
    {
        $s = mb_strtolower(import_fold_accents($header), 'UTF-8');
        $s = (string)preg_replace('/(\d)(\p{L})/u', '$1 $2', $s);
        $s = (string)preg_replace('/(\p{L})(\d)/u', '$1 $2', $s);
        $s = (string)preg_replace('/(?<!\d)\.|\.(?!\d)/u', ' ', $s);          // "No." -> "No"; "0.75" stays a number
        $s = (string)preg_replace('/([\/=:#])/u', ' $1 ', $s);
        $s = (string)preg_replace('/[^\p{L}\p{N}\/=:#.]+/u', ' ', $s);
        return preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The grammar reading of one heading, or null when a word of it is not a grammar word (or it names none of the three fields).
     * conf 0.9 = read (like a contained alias match); ask = a question whatever else matched ('alts' = the fields the question offers).
     */
    function import_header_grammar(string $header, array $aliases): ?array
    {
        $toks = import_hg_tokens($header);
        if (!$toks || count($toks) > 12) {
            return null;
        }
        $v = import_hg_vocab($aliases);
        $cls = [];
        foreach ($toks as $t) {
            if (isset($v['count'][$t]) && !isset($v['conn'][$t])) {
                $cls[] = ['K', $t];
            } elseif (isset($v['conn'][$t])) {
                $cls[] = ['X', $t];
            } elseif (preg_match('/^\d+(?:\.\d+)?$/', $t)) {
                $cls[] = ['N', $t];
            } elseif (isset($v['vol'][$t])) {
                $cls[] = ['V', $t];
            } elseif (isset($v['size'][$t])) {
                $cls[] = ['S', $t];
            } elseif (isset($v['meas'][$t])) {
                $cls[] = ['M', $v['meas'][$t]];
            } elseif (isset($v['generic'][$t])) {
                $cls[] = ['G', $t];
            } elseif (isset($v['generic_unit'][$t])) {
                $cls[] = ['U', ''];
            } elseif (isset($v['cont'][$t])) {
                $cls[] = ['C', $v['cont'][$t]['canon'], $v['cont'][$t]['pack']];
            } else {
                return null;            // a word the grammar does not know ("Total", "Price", "Stock", "Selling"): never read by the grammar
            }
        }
        // "Shot measure": a measure word after a measure is its size word
        $n = count($cls);
        for ($i = 1; $i < $n; $i++) {
            if ($cls[$i][0] === 'G' && in_array($cls[$i][1], ['measure', 'measures'], true) && $cls[$i - 1][0] === 'M') {
                $cls[$i] = ['S', $cls[$i][1]];
            }
        }
        // a size in front of a measure ("25ml measures", "25 shots") qualifies the measure: it is not the column's unit
        for ($i = 0; $i < $n; $i++) {
            if ($cls[$i][0] !== 'N') {
                continue;
            }
            $j = $i + 1;
            if ($j < $n && $cls[$j][0] === 'V' && $j + 1 < $n && in_array($cls[$j + 1][0], ['M', 'G'], true)) {
                $cls[$i][0] = 'Q';
                $cls[$j][0] = 'Q';
            } elseif ($j < $n && in_array($cls[$j][0], ['M', 'G'], true)) {
                $cls[$i][0] = 'Q';
            } else {
                return null;            // any other number in a heading: not this grammar
            }
        }
        $pos = ['M' => [], 'C' => [], 'S' => [], 'V' => [], 'K' => []];
        foreach ($cls as $i => $c) {
            $k = $c[0] === 'G' ? 'M' : ($c[0] === 'U' ? 'C' : $c[0]);
            if (isset($pos[$k])) {
                $pos[$k][] = $i;
            }
        }
        if (count($pos['M']) > 1 || count($pos['C']) > 1) {
            return null;                // two measures or two containers ("Shots per glass per bottle"): not read
        }
        $m = $pos['M'] ? $cls[$pos['M'][0]] : null;
        $c = $pos['C'] ? $cls[$pos['C'][0]] : null;
        $hasSize = (bool)$pos['S'] || (bool)$pos['V'];
        $mul = null;
        foreach ($pos['V'] as $i) {
            $x = $v['vol'][$cls[$i][1]];
            if ($mul !== null && $mul !== $x) {
                return null;            // two different units ("ml / cl"): never a pick
            }
            $mul = $x;
        }
        // count words only in front of the measure ("No of shots", "How many tots"), never without one; "qty" / "quantity" only as "qty of":
        // "Qty (glasses)" is a count in stock written in glasses (the quantity column), never "how many in one"
        foreach ($pos['K'] as $i) {
            if (!$m || $i > $pos['M'][0]) {
                return null;
            }
            if (in_array($cls[$i][1], ['qty', 'quantity'], true) && (($cls[$i + 1][1] ?? '') !== 'of')) {
                return null;
            }
        }
        $only = function (int $from, int $to, array $classes) use ($cls): bool {
            for ($i = $from; $i <= $to; $i++) {
                if (!in_array($cls[$i][0], $classes, true)) {
                    return false;
                }
            }
            return true;
        };
        $mWord = $m && $m[0] === 'M' ? (string)$m[1] : '';
        $uWord = $c && $c[0] === 'C' ? (string)$c[1] : '';
        $out = function (string $field, bool $ask, array $alts) use ($mWord, $uWord, $mul): array {
            return ['field' => $field, 'ask' => $ask, 'conf' => $ask ? 0.6 : 0.9, 'alts' => $alts, 'measure' => $mWord, 'unit' => $uWord, 'ml_mul' => $mul];
        };
        if ($m && $c && !$hasSize) {
            $mi = $pos['M'][0];
            $ci = $pos['C'][0];
            if ($mi < $ci) {
                // "Shots per bottle": at least one connector between, nothing else
                if ($ci - $mi < 2 || !$only($mi + 1, $ci - 1, ['X'])) {
                    return null;
                }
            } else {
                // "Btl = 25 shots": '=' / ':' / 'x' or the number between them
                $mid = false;
                for ($i = $ci + 1; $i < $mi; $i++) {
                    if (!in_array($cls[$i][0], ['X', 'Q'], true)) {
                        return null;
                    }
                    if ($cls[$i][0] === 'Q' || in_array($cls[$i][1], ['=', ':', 'x'], true)) {
                        $mid = true;
                    }
                }
                if (!$mid) {
                    return null;
                }
            }
            return $out('measures_per_unit', false, ['measures_per_unit']);
        }
        if ($m && !$c && !$hasSize) {
            // "Shots", "No of shots": in one bottle, or in stock? always asked; a generic word alone ("Portions") is not read
            return $m[0] === 'M' ? $out('measures_per_unit', true, ['measures_per_unit']) : null;
        }
        if ($pos['K'] && !($m && $c)) {
            return null;
        }
        if (!$m && $c && $hasSize) {
            if ($c[0] === 'U') {
                return $out('unit_ml', true, ['unit_ml']);                          // "Unit size": no container named
            }
            if (!empty($c[2])) {
                return $out('unit_ml', true, ['unit_ml', 'pack_size']);            // "Box size": ml in a box, or units in a box?
            }
            return $out('unit_ml', false, ['unit_ml']);
        }
        if ($m && !$c && $hasSize) {
            if ($m[0] === 'G' && $mul === null) {
                return $out('measure_ml', true, ['measure_ml']);                    // "Portion size" (grams? ml?): asked
            }
            return $out('measure_ml', false, ['measure_ml']);
        }
        if (!$m && !$c && $pos['V']) {
            return $out('unit_ml', true, ['unit_ml', 'measure_ml']);                // "Volume (ml)": one bottle, one shot, or all the stock?
        }
        if ($m && $c && $hasSize) {
            $first = $pos['S'] ? $pos['S'][0] : $pos['V'][0];
            $f = $first === $pos['M'][0] + 1 ? 'measure_ml' : ($first === $pos['C'][0] + 1 ? 'unit_ml' : 'measures_per_unit');
            return $out($f, true, import_hg_fields());
        }
        return null;
    }

    /** "Shots per bottle", "Bottle size (ml)", "Shot size (ml)", "Measures per unit". */
    function import_col_label(string $field, string $measure = '', string $unit = ''): string
    {
        if ($field === 'measures_per_unit') {
            return ucfirst(import_measure_plural($measure !== '' ? $measure : 'measure', 2)) . ' per ' . ($unit !== '' ? $unit : 'unit');
        }
        if ($field === 'unit_ml') {
            return ($unit !== '' ? ucfirst($unit) : 'Bottle / container') . ' size (ml)';
        }
        if ($field === 'measure_ml') {
            return ($measure !== '' ? ucfirst($measure) : 'Shot / glass') . ' size (ml)';
        }
        if ($field === 'pack_size') {
            return 'Pack size (units in one pack)';
        }
        return ucfirst(str_replace('_', ' ', $field));
    }

    /** The answer label of one field in a column question ("Yes: how many shots one bottle holds"). */
    function import_col_option_label(string $field, array $per): string
    {
        $m = (string)($per['measure'] ?? '');
        $u = (string)($per['unit'] ?? '');
        if (isset(import_hg_volume_units()[mb_strtolower($u)]) || in_array(mb_strtolower($u), ['kg', 'g'], true)) {
            $u = '';            // "Volume (ml)": ml is the size's unit, not a container ("the size of one ml" never)
        }
        if ($field === 'measures_per_unit') {
            return 'How many ' . ($m !== '' ? import_measure_plural($m, 2) : 'measures (shots, glasses ...)') . ' one ' . ($u !== '' ? $u : 'stock unit') . ' holds (' . import_col_label($field, $m, $u) . ')';
        }
        if ($field === 'unit_ml') {
            return 'The size of one ' . ($u !== '' ? $u : 'bottle or container') . ' in ml';
        }
        if ($field === 'measure_ml') {
            return 'The size of one ' . ($m !== '' ? $m : 'shot or glass') . ' in ml';
        }
        if ($field === 'pack_size') {
            return 'How many units one pack holds (pack size)';
        }
        return 'Yes, use it as ' . str_replace('_', ' ', $field);
    }

    /**
     * An Assistant suggestion for one column ($s = ['field', 'measure', 'unit']; $col = the analysis column: header, samples), checked here:
     * the field is one of the three; the measure word and the unit word are words of THIS heading and of the hotel's own lists (or a Drink
     * measures list name); every sample value passes the field's value check (N 1..1000 at 4 dp; a size 1..100,000 ml in the heading's unit);
     * a field the person said "not this" to for this heading is never suggested. Anything else = null (dropped, nothing shown).
     */
    function import_col_ai_check(array $s, array $col, array $aliases): ?array
    {
        $field = is_string($s['field'] ?? null) ? trim((string)$s['field']) : '';
        if (!in_array($field, import_hg_fields(), true)) {
            return null;
        }
        $header = (string)($col['header'] ?? '');
        $hk = import_norm_text($header);
        if ($hk === '' || isset($aliases['field_not'][$hk][$field])) {
            return null;
        }
        $v = import_hg_vocab($aliases);
        $mIn = [];
        $uIn = [];
        foreach (import_hg_tokens($header) as $t) {
            if (isset($v['meas'][$t])) {
                $mIn[$v['meas'][$t]] = true;
            }
            if (isset($v['cont'][$t])) {
                $uIn[$v['cont'][$t]['canon']] = true;
            }
        }
        $word = function ($w): ?string {
            if ($w === null || $w === '') {
                return '';
            }
            if (!is_string($w) || mb_strlen($w) > 40) {
                return null;
            }
            $n = import_norm_text($w);
            return ($n === '' || strpos($n, ' ') !== false) ? null : $n;
        };
        $mw = $word($s['measure'] ?? '');
        $uw = $word($s['unit'] ?? '');
        if ($mw === null || $uw === null) {
            return null;
        }
        if ($mw !== '') {
            if (isset($v['generic'][$mw])) {
                $mw = '';                                       // "measure": the drink's served-by measure
            } elseif (!isset($v['meas'][$mw]) || !isset($mIn[$v['meas'][$mw]])) {
                return null;                                    // not a measure word of this hotel, or not in this heading
            } else {
                $mw = $v['meas'][$mw];
            }
        }
        if ($uw !== '') {
            if (!isset($v['cont'][$uw]) || !isset($uIn[$v['cont'][$uw]['canon']])) {
                return null;
            }
            $uw = $v['cont'][$uw]['canon'];
        }
        if (($field === 'unit_ml' && $mw !== '') || ($field === 'measure_ml' && $uw !== '')) {
            return null;
        }
        $mul = import_header_ml_mul($header);
        $seen = 0;
        foreach ((array)($col['samples'] ?? []) as $x) {
            if ($x === null || (is_string($x) && (trim($x) === '' || import_is_empty_marker($x)))) {
                continue;
            }
            $seen++;
            if ($field === 'measures_per_unit') {
                $p = import_parse_number($x);
                if ($p['status'] !== 'ok' || import_measure_n_valid(is_float($p['value']) || is_int($p['value']) ? $p['value'] : null) === null
                    || abs(round((float)$p['value'], 4) - (float)$p['value']) > 1e-9) {
                    return null;
                }
            } else {
                $p = import_parse_volume($x, $mul);
                if ($p['status'] !== 'ok' || (float)$p['ml'] < 1 || (float)$p['ml'] > 100000) {
                    return null;
                }
            }
        }
        if ($seen === 0) {
            return null;                                        // nothing to check the suggestion against
        }
        return ['field' => $field, 'measure' => $mw, 'unit' => $uw, 'label' => import_col_label($field, $mw, $uw)];
    }
}
