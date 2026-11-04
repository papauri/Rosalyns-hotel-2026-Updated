<?php
/**
 * Import - evidence-based suggestions (pure: no database, no output).
 *
 *   import_unit_canon($raw, $synonyms)         a unit as the stock list writes it ("btl", "Bottles", "LTR") -> the spelling stock uses ("bottle", "L")
 *   import_infer_index($existing, $learned, $items, $synonyms)
 *                                              ONE index built per resolve (hash lookups only, no per-row queries, no fuzzy matching against stock)
 *   import_infer_unit($ix, $item, $unitOptions)        {value, reason, confidence high|medium|low, mixed, evidence[], candidates[]}
 *   import_infer_category($ix, $item)                  {value, reason, confidence, evidence[]} or null
 *
 * Rules (the owner's: "it never invents"):
 *  - A suggestion is a unit/category that ALREADY EXISTS somewhere the person can check: a unit in this hotel's own unit list, an existing
 *    ingredient, the other items of the same category/sheet, a word in the item's own name, or a choice the hotel made before. Every suggestion
 *    carries that evidence (cells / ingredient ids); a suggestion with no evidence does not exist.
 *  - Evidence order: this hotel's learned choice for this item > an existing ingredient with the same name once sizes are removed > a unit
 *    word in the name ("crate", "dozen") > what the other items of the same category are counted in. Several sources that disagree = "mixed":
 *    no suggestion, the person chooses for that item.
 *  - A size in the name ("500ml", "2kg", "x24", "pack of 12") describes ONE item, not how stock is counted: it is never proposed as the unit
 *    and never becomes a pack size. It only strengthens a peer-based suggestion for a container unit.
 *  - low confidence = no suggestion. Nothing is applied here: the person presses "Use suggestions" or picks a unit.
 */

require_once dirname(__DIR__, 2) . '/admin/includes/stock-sheet-import-lib.php';   // rh_ssi_norm_unit
require_once __DIR__ . '/text-util.php';

if (!function_exists('import_infer_unit')) {

    /** Units that measure an amount: they describe a size and are never proposed from a name token alone. */
    function import_infer_measure_units(): array
    {
        return ['ml', 'cl', 'l', 'ltr', 'ltrs', 'lt', 'litre', 'litres', 'liter', 'liters', 'g', 'gm', 'gms', 'kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms',
            'gram', 'grams', 'oz', 'lb', 'lbs', 'mls'];
    }

    /** The unit as stock spells it: the hotel's synonym table first (import_aliases kind unit_synonym), then the stock-sheet normaliser. '' stays ''. */
    function import_unit_canon(string $raw, array $synonyms = []): string
    {
        $k = mb_strtolower(trim($raw));
        if ($k === '') {
            return '';
        }
        if (isset($synonyms[$k])) {
            return (string)$synonyms[$k];
        }
        return rh_ssi_norm_unit($raw);
    }

    /** Size and pack words in a name: [['token'=>'500ml','kind'=>'volume'|'weight'|'count']]. Evidence only. */
    function import_infer_size_tokens(string $name): array
    {
        $out = [];
        $n = mb_strtolower($name);
        if (preg_match_all('/(?<![\p{L}\p{N}])(\d+(?:[.,]\d+)?)\s?(ml|cl|ltr|litres?|liters?|l)(?![\p{L}])/u', $n, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[] = ['token' => trim($x[0]), 'kind' => 'volume'];
            }
        }
        if (preg_match_all('/(?<![\p{L}\p{N}])(\d+(?:[.,]\d+)?)\s?(kgs?|g|gm|gms|grams?|oz|lbs?)(?![\p{L}])/u', $n, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[] = ['token' => trim($x[0]), 'kind' => 'weight'];
            }
        }
        if (preg_match_all('/(?<![\p{L}\p{N}])(x\s?\d+|\d+\s?x\s?\d+|pack of \d+|\d+\s?(?:pk|pcs|pc|pack)s?)(?![\p{L}\p{N}])/u', $n, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[] = ['token' => trim($x[0]), 'kind' => 'count'];
            }
        }
        return $out;
    }

    /** The name without its size/pack words, normalised: "Fanta Orange 500ml x24" and "Fanta Orange" meet. */
    function import_infer_strip_size(string $name): string
    {
        $n = mb_strtolower($name);
        $n = (string)preg_replace('/(?<![\p{L}\p{N}])\d+(?:[.,]\d+)?\s?(?:ml|cl|ltr|litres?|liters?|l|kgs?|g|gm|gms|grams?|oz|lbs?)(?![\p{L}])/u', ' ', $n);
        $n = (string)preg_replace('/(?<![\p{L}\p{N}])(?:x\s?\d+|\d+\s?x\s?\d+|pack of \d+|\d+\s?(?:pk|pcs|pc|pack)s?)(?![\p{L}\p{N}])/u', ' ', $n);
        return import_norm_text($n);
    }

    /**
     * The index the suggestions read. $existing = stock_ingredients rows (name, category, unit, is_archived, id); $learned = this hotel's earlier
     * choices ['unit_item'=>[norm name=>['v','by','at']], 'unit_cat'=>[cat key=>...], 'category'=>[norm name=>...]]; $items = the resolved
     * items of this file (those with a unit and no existing match count as peers; those without a unit are the ones asked about).
     */
    function import_infer_index(array $existing, array $learned, array $items, array $synonyms = []): array
    {
        $ix = ['syn' => $synonyms, 'learned' => $learned, 'ex_name' => [], 'cat_units' => [], 'sheet_units' => [], 'ex_cat_by_name' => []];
        foreach ($existing as $e) {
            if ((int)($e['is_archived'] ?? 0) === 1) {
                continue;
            }
            $u = import_unit_canon((string)$e['unit'], $synonyms);
            if ($u === '') {
                continue;
            }
            $sk = import_infer_strip_size((string)$e['name']);
            if ($sk !== '') {
                $ix['ex_name'][$sk][] = ['id' => (int)$e['id'], 'name' => (string)$e['name'], 'unit' => $u, 'category' => (string)$e['category']];
            }
            $ck = import_norm_text((string)$e['category']);
            $ix['cat_units'][$ck][$u] = ($ix['cat_units'][$ck][$u] ?? 0) + 1;
        }
        foreach ($items as $it) {
            if (!empty($it['match']) || $it['unit'] === null || $it['unit'] === '') {
                continue;
            }
            $u = import_unit_canon((string)$it['unit'], $synonyms);
            if ($u === '') {
                continue;
            }
            $ck = import_norm_text((string)$it['category']);
            $ix['cat_units'][$ck][$u] = ($ix['cat_units'][$ck][$u] ?? 0) + 1;
            $ix['sheet_units'][(string)$it['sheet']][$u] = ($ix['sheet_units'][(string)$it['sheet']][$u] ?? 0) + 1;
        }
        return $ix;
    }

    /** The unit in $options spelled the way the list spells it (case-insensitive), or null when the hotel's list does not have it. */
    function import_infer_in_options(string $u, array $options): ?string
    {
        $k = mb_strtolower($u);
        foreach ($options as $o) {
            if (mb_strtolower((string)$o) === $k) {
                return (string)$o;
            }
        }
        return null;
    }

    function import_infer_top(array $counts): array
    {
        arsort($counts);
        $total = array_sum($counts);
        $u = (string)array_key_first($counts);
        return [$u, $total > 0 ? (int)$counts[$u] : 0, (int)$total];
    }

    /**
     * Suggest the unit of ONE item that has none. $item = a resolved item (name, name_source, category, category_how, sheet).
     * Returns ['value'=>?string,'reason'=>string,'confidence'=>'high'|'medium'|'low','mixed'=>bool,'evidence'=>[],'candidates'=>[unit=>why]].
     */
    function import_infer_unit(array $ix, array $item, array $unitOptions): array
    {
        $none = ['value' => null, 'reason' => '', 'confidence' => 'low', 'mixed' => false, 'evidence' => [], 'candidates' => []];
        $name = (string)$item['name'];
        $syn = $ix['syn'];
        $measure = array_flip(import_infer_measure_units());
        $nk = import_norm_text($name);
        $ck = import_norm_text((string)$item['category']);

        // 1. what this hotel decided before for exactly this item
        $l = $ix['learned']['unit_item'][$nk] ?? null;
        if ($l) {
            $v = import_infer_in_options((string)$l['v'], $unitOptions);
            if ($v !== null) {
                return ['value' => $v, 'reason' => 'Remembered from your earlier import: "' . $name . '" was counted in ' . $v . '.', 'confidence' => 'high', 'mixed' => false,
                    'evidence' => [['kind' => 'learned', 'item' => $nk, 'unit' => $v, 'by' => $l['by'] ?? null, 'at' => $l['at'] ?? null]], 'candidates' => [$v => 'remembered']];
            }
        }
        // 2. the strong sources: an existing ingredient with the same name once sizes are removed, a unit word in the name, a learned category habit
        $strong = [];          // unit => [evidence...]
        $sk = import_infer_strip_size($name);
        foreach ($ix['ex_name'][$sk] ?? [] as $e) {
            $v = import_infer_in_options($e['unit'], $unitOptions);
            if ($v !== null) {
                $strong[$v][] = ['kind' => 'existing-item', 'id' => $e['id'], 'name' => $e['name'], 'unit' => $v];
            }
        }
        foreach (preg_split('/\s+/u', $nk, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (isset($measure[$w]) || mb_strlen($w) < 3) {
                continue;
            }
            $c = import_unit_canon($w, $syn);
            $v = import_infer_in_options($c, $unitOptions);
            // the word must itself BE a unit word (equal to a unit of the list, or a known synonym of one): "crate", "btl", not "sugar"
            if ($v !== null && !isset($measure[mb_strtolower($v)]) && (mb_strtolower($w) === mb_strtolower($v) || isset($syn[$w]))) {
                $strong[$v][] = ['kind' => 'name-word', 'word' => $w, 'source' => (string)($item['name_source'] ?? ''), 'unit' => $v];
            }
        }
        $lc = $ix['learned']['unit_cat'][$ck] ?? null;
        if ($lc) {
            $v = import_infer_in_options((string)$lc['v'], $unitOptions);
            if ($v !== null) {
                $strong[$v][] = ['kind' => 'learned-category', 'category' => (string)$item['category'], 'unit' => $v, 'by' => $lc['by'] ?? null, 'at' => $lc['at'] ?? null];
            }
        }
        // 3. the other items of the same category (this file's items with a unit + the stock already there); the sheet when the category is only the sheet's name
        $peers = $ix['cat_units'][$ck] ?? [];
        $peerScope = 'category';
        if (array_sum($peers) < 3 && ($item['category_how'] ?? '') === 'sheet-name') {
            $peers = $ix['sheet_units'][(string)$item['sheet']] ?? [];
            $peerScope = 'sheet';
        }
        $pu = null;
        $pn = 0;
        $pt = 0;
        if ($peers) {
            [$pu, $pn, $pt] = import_infer_top($peers);
        }
        $peerEv = $peers ? [['kind' => 'peers', 'scope' => $peerScope, 'category' => (string)$item['category'], 'sheet' => (string)$item['sheet'], 'counts' => $peers, 'unit' => $pu, 'n' => $pn, 'of' => $pt]] : [];
        $share = $pt > 0 ? $pn / $pt : 0.0;
        $pv = $pu !== null ? import_infer_in_options($pu, $unitOptions) : null;
        $peerWords = function () use ($pv, $pn, $pt, $item, $peerScope) {
            return ($pn === $pt ? 'all ' . $pt : $pn . ' of the ' . $pt) . ' other items ' . ($peerScope === 'sheet' ? 'on this sheet' : 'under "' . $item['category'] . '"') . ' are counted in ' . $pv;
        };
        $sizes = import_infer_size_tokens($name);

        $cand = [];
        foreach ($strong as $u => $evs) {
            $cand[$u] = $evs[0]['kind'] === 'existing-item' ? 'the existing item "' . $evs[0]['name'] . '" is counted in ' . $u
                : ($evs[0]['kind'] === 'name-word' ? 'the name says "' . $evs[0]['word'] . '"' : 'you usually count this category in ' . $u);
        }
        if (count($strong) >= 2) {
            if ($pv !== null && !isset($cand[$pv])) {
                $cand[$pv] = $peerWords();
            }
            return ['value' => null, 'reason' => 'The evidence disagrees: ' . implode('; ', $cand) . '.', 'confidence' => 'low', 'mixed' => true,
                'evidence' => array_merge(array_merge(...array_values($strong)), $peerEv), 'candidates' => $cand];
        }
        if (count($strong) === 1) {
            $u = (string)array_key_first($strong);
            $ev = array_merge($strong[$u], $peerEv);
            if ($pv !== null && mb_strtolower($pv) !== mb_strtolower($u) && $pt >= 3 && $share >= 0.7) {
                $cand[$pv] = $peerWords();
                return ['value' => null, 'reason' => 'The evidence disagrees: ' . implode('; ', $cand) . '.', 'confidence' => 'low', 'mixed' => true, 'evidence' => $ev, 'candidates' => $cand];
            }
            $agree = $pv !== null && mb_strtolower($pv) === mb_strtolower($u) && $pt >= 3 && $share >= 0.6;
            return ['value' => $u, 'reason' => 'Suggested: ' . $u . ' - ' . $cand[$u] . ($agree ? ', and ' . $peerWords() : '') . '.', 'confidence' => $agree ? 'high' : 'medium',
                'mixed' => false, 'evidence' => $ev, 'candidates' => [$u => $cand[$u]]];
        }
        // only the peers speak
        if ($pv === null || $pt < 3) {
            return $none;
        }
        if ($share >= 0.8 && $pt >= 6) {
            $conf = 'high';
        } elseif ($share >= 0.7) {
            $conf = 'medium';
            if ($sizes && !isset($measure[mb_strtolower($pv)])) {
                $conf = 'high';             // a size in the name ("500ml") fits a container unit: the peers' answer is corroborated
            }
        } else {
            return ['value' => null, 'reason' => 'The other items here are counted in different units (' . implode(', ', array_map(function ($u, $n) {
                return $n . ' in ' . $u;
            }, array_keys($peers), array_values($peers))) . '), so there is no clear answer.', 'confidence' => 'low', 'mixed' => true,
                'evidence' => $peerEv, 'candidates' => array_map(function ($n) {
                    return $n . ' other item(s)';
                }, $peers)];
        }
        $why = 'Suggested: ' . $pv . ' - ' . $peerWords();
        if ($sizes) {
            $why .= ' (the name has "' . $sizes[0]['token'] . '", which is a size, not the counting unit)';
        }
        return ['value' => $pv, 'reason' => $why . '.', 'confidence' => $conf, 'mixed' => false, 'evidence' => array_merge($peerEv, $sizes ? [['kind' => 'size-token', 'token' => $sizes[0]['token']]] : []),
            'candidates' => [$pv => $peerWords()]];
    }

    /**
     * Suggest a category for a NEW item whose category is only the sheet's name (no heading above it). Sources: a category this hotel chose for the
     * same name before, or an existing ingredient with the same name once sizes are removed. Null = nothing to say (never a guess).
     */
    function import_infer_category(array $ix, array $item): ?array
    {
        if (($item['category_how'] ?? '') !== 'sheet-name' || !empty($item['match'])) {
            return null;
        }
        $name = (string)$item['name'];
        $nk = import_norm_text($name);
        $cur = mb_strtolower(trim((string)$item['category']));
        $l = $ix['learned']['category'][$nk] ?? null;
        if ($l && mb_strtolower(trim((string)$l['v'])) !== $cur) {
            return ['value' => (string)$l['v'], 'confidence' => 'high', 'reason' => 'Remembered from your earlier import: "' . $name . '" was filed under "' . $l['v'] . '".',
                'evidence' => [['kind' => 'learned', 'item' => $nk, 'category' => (string)$l['v'], 'by' => $l['by'] ?? null, 'at' => $l['at'] ?? null]]];
        }
        $cats = [];
        foreach ($ix['ex_name'][import_infer_strip_size($name)] ?? [] as $e) {
            if (trim($e['category']) !== '' && mb_strtolower(trim($e['category'])) !== $cur) {
                $cats[$e['category']][] = $e;
            }
        }
        if (count($cats) === 1) {
            $c = (string)array_key_first($cats);
            $e = $cats[$c][0];
            return ['value' => $c, 'confidence' => 'medium', 'reason' => 'The existing item "' . $e['name'] . '" is filed under "' . $c . '".',
                'evidence' => [['kind' => 'existing-item', 'id' => $e['id'], 'name' => $e['name'], 'category' => $c]]];
        }
        return null;
    }
}

if (!function_exists('import_units_compatible')) {
    /** Is a unit from the sheet the same unit as the ingredient's stock unit? Spelling differences (btl / bottle, ltr / L, each / pcs) do not count. */
    function import_units_compatible(string $sheetUnit, string $existingUnit, array $synonyms = []): bool
    {
        $a = mb_strtolower(import_unit_canon($sheetUnit, $synonyms));
        $b = mb_strtolower(import_unit_canon($existingUnit, $synonyms));
        $count = ['each', 'pcs'];
        $a = in_array($a, $count, true) ? 'pcs' : $a;
        $b = in_array($b, $count, true) ? 'pcs' : $b;
        return $a === $b;
    }
}
