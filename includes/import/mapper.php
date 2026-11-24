<?php
/**
 * Import engine - column mapper: header text -> logical field, per sheet.
 *
 * Header text is compared case/space/emoji/punctuation/newline-insensitively against the alias set
 * (a PARAMETER: see default-aliases.php). A match is exact (1.0), contained (0.9 / 0.7) or a typo-level
 * fuzzy hit (0.6). Anything below the auto threshold is turned into a QUESTION, never applied.
 * A second pass checks the VALUES under each header ("a price column must look like money").
 */

require_once __DIR__ . '/text-util.php';
require_once __DIR__ . '/pack-rule.php';
require_once __DIR__ . '/measure.php';           // import_parse_volume(): a size column ("750ml", "0.75 L")
require_once __DIR__ . '/header-grammar.php';    // #10: "Glasses from a casket", "Btl = 25 shots", "Casket capacity (L)" read from the hotel's own words

if (!function_exists('import_match_header')) {

    function import_map_auto_threshold(): float
    {
        return 0.85;
    }

    /** Prepared lookup of an alias set (cached by content). */
    function import_alias_index(array $aliases): array
    {
        static $cache = [];
        static $lastIn = null;
        static $lastOut = null;
        // The same alias array is passed on every call of a run (once per cell): recognising it is a pointer / value comparison,
        // not a json_encode + md5 of the whole vocabulary per cell (that was most of the time of reading a big sheet).
        $sub = [$aliases['fields'] ?? [], $aliases['noise_tokens'] ?? [], $aliases['guard_words'] ?? [], $aliases['derived'] ?? [], $aliases['ask_phrases'] ?? []];
        if ($lastIn !== null && $sub === $lastIn) {
            return $lastOut;
        }
        $key = md5(json_encode($sub));
        if (isset($cache[$key])) {
            $lastIn = $sub;
            return $lastOut = $cache[$key];
        }
        $noise = [];
        foreach (($aliases['noise_tokens'] ?? []) as $n) {
            $noise[import_norm_text($n)] = true;
        }
        $exact = [];
        $list = [];
        foreach (($aliases['fields'] ?? []) as $field => $phrases) {
            foreach ($phrases as $p) {
                $norm = import_norm_text($p);
                if ($norm === '') {
                    continue;
                }
                $tokens = explode(' ', $norm);
                if (!isset($exact[$norm])) {
                    $exact[$norm] = $field;
                }
                $list[] = ['field' => $field, 'norm' => $norm, 'tokens' => $tokens, 'n' => count($tokens)];
            }
        }
        // longest alias first so the most specific wins
        usort($list, function ($a, $b) {
            return $b['n'] <=> $a['n'];
        });
        $guard = [];
        foreach (($aliases['guard_words'] ?? []) as $g) {
            $guard[import_norm_text($g)] = true;
        }
        $ask = [];
        foreach (($aliases['ask_phrases'] ?? []) as $p) {
            $ask[import_norm_text($p)] = true;
        }
        $lastIn = $sub;
        return $lastOut = $cache[$key] = ['noise' => $noise, 'exact' => $exact, 'list' => $list, 'guard' => $guard,
            'derived' => array_flip(array_map('strval', $aliases['derived'] ?? [])), 'ask' => $ask];
    }

    function import_header_tokens(string $text, array $idx): array
    {
        $norm = import_norm_text($text);
        if ($norm === '') {
            return [];
        }
        $out = [];
        foreach (explode(' ', $norm) as $t) {
            if (!isset($idx['noise'][$t])) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * Match one header text. Returns
     * ['field'=>string|null, 'conf'=>float, 'alias'=>string|null, 'candidates'=>[['field','conf','alias']...]]
     */
    function import_match_header(string $text, array $aliases): array
    {
        $idx = import_alias_index($aliases);
        $tokens = import_header_tokens($text, $idx);
        $none = ['field' => null, 'conf' => 0.0, 'alias' => null, 'candidates' => []];
        if (!$tokens) {
            return $none;
        }
        $key = implode(' ', $tokens);
        $normText = import_norm_text($text);
        // #10: a heading this hotel said is NOT one of the read fields ("not this" in step 2): THAT field only is never read or asked for it
        // again (it is left out of the matching below); every other field's aliases still match as before
        $notThis = empty($aliases['field_not']) ? [] : (array)($aliases['field_not'][$key] ?? []) + (array)($aliases['field_not'][$normText] ?? []);
        // #10: a heading a person answered in step 2 is read as THEIR latest answer, word for word, ahead of a seed alias of another field with
        // the same words ("Volume (ml)" answered "shot size" is never read as the bottle size by the seed alias "volume ml")
        if (!empty($aliases['learned_heads'])) {
            $lh = array_diff_key((array)($aliases['learned_heads'][$normText] ?? ($aliases['learned_heads'][$key] ?? [])), $notThis);
            if ($lh) {
                $lf = (string)array_key_last($lh);
                return ['field' => $lf, 'conf' => 1.0, 'alias' => $key, 'candidates' => [['field' => $lf, 'conf' => 1.0, 'alias' => $key]]];
            }
        }
        $dropped = null;        // a field the heading matched that the hotel said it is not
        $cands = [];
        $exactField = $idx['exact'][$key] ?? null;
        if ($exactField !== null && isset($notThis[$exactField])) {
            $dropped = $exactField;
            $exactField = null;
            foreach ($idx['list'] as $a) {
                if ($a['norm'] === $key && !isset($notThis[$a['field']])) {
                    $exactField = $a['field'];          // another field with the same words still reads it
                    break;
                }
            }
        }
        if ($exactField !== null) {
            $cands[] = ['field' => $exactField, 'conf' => 1.0, 'alias' => $key];
        }
        // contiguous containment of an alias inside the header
        $nTok = count($tokens);
        $seen = [];
        foreach ($idx['list'] as $a) {
            if ($a['n'] > $nTok || isset($seen[$a['field']])) {
                continue;
            }
            $found = false;
            for ($i = 0; $i + $a['n'] <= $nTok; $i++) {
                if (array_slice($tokens, $i, $a['n']) === $a['tokens']) {
                    $found = true;
                    break;
                }
            }
            if ($found && isset($notThis[$a['field']])) {
                $dropped = $dropped ?? (string)$a['field'];
                continue;
            }
            if ($found && $a['norm'] !== $key) {
                $cov = $a['n'] / $nTok;
                $cands[] = ['field' => $a['field'], 'conf' => $cov >= 0.5 ? 0.9 : 0.7, 'alias' => $a['norm'], 'n' => $a['n']];
                $seen[$a['field']] = true;
            }
        }
        // typo-level fuzzy
        if (!$cands) {
            foreach ($idx['list'] as $a) {
                if (isset($seen[$a['field']]) || strlen($a['norm']) < 4) {
                    continue;
                }
                // similarity = 1 - levenshtein / longest, and levenshtein >= the length difference: when that alone puts it under 0.8
                // the answer is already known (saves the edit-distance run against every alias for every cell of a big sheet)
                $la = strlen($key);
                $lb = strlen($a['norm']);
                if (abs($la - $lb) * 5 > max($la, $lb)) {
                    continue;
                }
                $sim = import_similarity($key, $a['norm']);
                if ($sim >= 0.8) {
                    if (isset($notThis[$a['field']])) {
                        $dropped = $dropped ?? (string)$a['field'];
                        continue;
                    }
                    $cands[] = ['field' => $a['field'], 'conf' => 0.6, 'alias' => $a['norm'], 'sim' => $sim];
                    $seen[$a['field']] = true;
                }
            }
        }
        // #10: the header grammar ("Glasses from a casket", "Btl = 25 shots", "Casket capacity (L)"): only when no alias matches word for word
        // (an exact alias keeps working: "Carton size" stays the pack size), and only when every word of the heading is a grammar word
        if ($exactField === null) {
            $g = import_header_grammar($text, $aliases);
            if ($g !== null && $notThis) {
                // a field the hotel said "not this" to is never offered again; a question keeps the fields it still has, else it says nothing
                $alts = array_values(array_filter((array)$g['alts'], function ($f) use ($notThis) {
                    return !isset($notThis[$f]);
                }));
                $keep = array_values(array_intersect($alts, import_hg_fields()));
                if (!isset($notThis[$g['field']])) {
                    $g['alts'] = $alts;
                } elseif ($g['ask'] && $keep) {
                    $g['field'] = $keep[0];
                    $g['alts'] = $alts;
                } else {
                    $dropped = $dropped ?? (string)$g['field'];
                    $g = null;
                }
            }
            if ($g !== null) {
                $other = 0.0;
                foreach ($cands as $c) {
                    if ($c['field'] !== $g['field'] && $c['conf'] > $other) {
                        $other = $c['conf'];
                    }
                }
                // a heading another field also matches (at any score) keeps exactly the reading / question it had before the grammar
                // ("Qty of shots" stays a quantity question, "Glass" a category question, "Shot measure" a unit question): the grammar only
                // asks where nothing else matched, and only reads where nothing else reads it on its own
                if ($other > 0.0 && ($g['ask'] || $other >= import_map_auto_threshold())) {
                    $g = null;
                }
            }
            if ($g !== null) {
                $conf = (float)$g['conf'];
                $list = [['field' => $g['field'], 'conf' => $conf, 'alias' => $key]];
                foreach ($cands as $c) {
                    if ($c['field'] !== $g['field']) {
                        $list[] = ['field' => $c['field'], 'conf' => $c['conf'], 'alias' => $c['alias']];
                    }
                }
                return ['field' => $g['field'], 'conf' => $conf, 'alias' => $key, 'candidates' => $list, 'grammar' => $g];
            }
        }
        if (!$cands) {
            return $dropped !== null ? $none + ['not_this' => $dropped] : $none;        // #10: shown as "not read: you told us ..."
        }
        usort($cands, function ($x, $y) {
            if ($x['conf'] !== $y['conf']) {
                return $y['conf'] <=> $x['conf'];
            }
            return ($y['n'] ?? 0) <=> ($x['n'] ?? 0);
        });
        $best = $cands[0];
        // two different fields tied on the same score with the same alias length => ambiguous
        $conf = $best['conf'];
        if (isset($cands[1]) && $cands[1]['conf'] === $best['conf'] && ($cands[1]['n'] ?? 0) === ($best['n'] ?? 0) && $cands[1]['field'] !== $best['field']) {
            $conf = min($conf, 0.6);
        }
        // a worked-out figure ("Total Buying Cost", "Value in Stock", "Days in Stock") is never read as an imported NUMBER or DATE from part of
        // its words: only a word-for-word match is applied, anything else becomes a question
        if ($conf < 1.0 && $idx['guard'] && !isset($idx['derived'][$best['field']]) && import_field_kind((string)$best['field']) !== 'text' && array_intersect_key($idx['guard'], array_flip($tokens))) {
            $conf = min($conf, 0.6);
        }
        // v11: a heading that can mean two things ("No of shots": in one bottle, or in stock) is always asked, never read on its own
        if (!empty($idx['ask']) && isset($idx['ask'][(string)$best['alias']])) {
            $conf = min($conf, 0.6);
        }
        return ['field' => $best['field'], 'conf' => $conf, 'alias' => $best['alias'], 'candidates' => array_map(function ($c) {
            return ['field' => $c['field'], 'conf' => $c['conf'], 'alias' => $c['alias']];
        }, $cands)];
    }

    function import_field_is_derived(string $field, array $aliases): bool
    {
        return in_array($field, $aliases['derived'] ?? [], true);
    }

    /** The quantity columns (bar / stores / single balance): one family. */
    function import_qty_fields(): array
    {
        return ['qty_primary', 'qty_secondary', 'qty_single'];
    }

    function import_field_kind(string $field): string
    {
        if (in_array($field, ['qty_primary', 'qty_secondary', 'qty_single', 'max_level', 'min_level', 'unit_price', 'pack_size', 'pack_price', 'sell_price', 'measures_per_unit'], true)) {
            return 'number';
        }
        if ($field === 'unit_ml' || $field === 'measure_ml') {
            return 'volume';            // v11: a size in ml ("750", "750ml", "0.75 L")
        }
        if ($field === 'expiry') {
            return 'date';
        }
        return 'text';
    }

    /**
     * A derived field that stands in for a real one ($aliases['field_fallbacks'], e.g. min_qty => min_level): its column is used
     * as that field ONLY when no column of the sheet is (or is asked about as) that field; otherwise it stays greyed and is never
     * imported or asked about. A promoted column carries 'fallback_from'; nothing changes on a sheet where the rule does not fire.
     */
    function import_map_apply_fallbacks(array &$columns, array $aliases): void
    {
        $fallbacks = (array)($aliases['field_fallbacks'] ?? []);
        if (!$fallbacks) {
            return;
        }
        $present = [];
        foreach ($columns as $info) {
            if ($info['state'] === 'mapped' && $info['field'] !== null) {
                $present[$info['field']] = true;
            } elseif ($info['state'] === 'question' && !empty($info['suggested_field'])) {
                $present[$info['suggested_field']] = true;
            }
        }
        $idx = import_alias_index($aliases);
        foreach ($columns as $c => $info) {
            $from = $info['field'];
            if ($info['state'] !== 'mapped' || $from === null || !isset($fallbacks[$from]) || !import_field_is_derived($from, $aliases)) {
                continue;
            }
            $to = (string)$fallbacks[$from];
            if ($to === '' || import_field_is_derived($to, $aliases)) {
                continue;
            }
            // the quantity columns are one family: a "Total Balance" never stands in for the quantity when the sheet has a bar, stores or
            // balance column of its own (it is then their sum, and an uncalculated sum must stay unknown)
            $family = in_array($to, import_qty_fields(), true) ? import_qty_fields() : [$to];
            if (array_intersect_key($present, array_flip($family))) {
                continue;
            }
            // the heading must also name the field it stands in for ("Total BALANCE", "MIN qty"): a bare "Total" / "Grand total" never does
            $tokens = array_flip(import_header_tokens((string)$info['header'], $idx));
            $named = false;
            foreach ((array)($aliases['fields'][$to] ?? []) as $p) {
                $pn = import_norm_text((string)$p);
                if ($pn !== '' && strpos($pn, ' ') === false && isset($tokens[$pn])) {
                    $named = true;
                    break;
                }
            }
            // ... and name nothing else: "Total Stock Cost", "Total Balance Value", "Total Qty Sold" are other figures, never the quantity.
            // Every word must come from the derived field's own phrases ("total", "balance", "min", "qty") or be a one-word name of the field
            $own = [];
            foreach ((array)($aliases['fields'][$from] ?? []) as $p) {
                foreach (explode(' ', import_norm_text((string)$p)) as $w) {
                    $own[$w] = true;
                }
            }
            foreach ((array)($aliases['fields'][$to] ?? []) as $p) {
                $pn = import_norm_text((string)$p);
                if ($pn !== '' && strpos($pn, ' ') === false) {
                    $own[$pn] = true;
                }
            }
            if (!$named || array_diff_key($tokens, $own)) {
                continue;
            }
            $present[$to] = true;           // one column stands in, never two
            $columns[$c]['field'] = $to;
            $columns[$c]['derived'] = false;
            $columns[$c]['fallback_from'] = $from;
        }
    }

    /** Header cell text of a cell (string) or ''. */
    function import_cell_text(?array $cell): string
    {
        if (!$cell || !$cell['cached'] || $cell['v'] === null || is_bool($cell['v'])) {
            return '';
        }
        return import_norm_ws((string)$cell['v']);
    }

    /**
     * Header-only mapping for the header row of a sheet.
     * Returns ['columns'=>[col=>info], 'by_field'=>[field=>col], 'questions'=>[]]
     * info = ['col','letter','header','header_ref','field'|null,'conf','derived','candidates','state'=>'mapped'|'question'|'unmapped'|'empty']
     */
    function import_map_columns(array $sheet, int $headerRow, array $aliases): array
    {
        $columns = [];
        $row = $sheet['cells'][$headerRow] ?? [];
        $maxCol = $sheet['dims']['cols'] ?? 0;
        $thr = import_map_auto_threshold();
        $byField = [];
        $questions = [];
        for ($c = 1; $c <= $maxCol; $c++) {
            $cell = $row[$c] ?? null;
            $text = import_cell_text($cell);
            $info = [
                'col' => $c, 'letter' => import_num_to_col($c), 'header' => $text,
                'header_ref' => import_source_ref($sheet['name'], import_cell_ref($headerRow, $c)),
                'field' => null, 'conf' => 0.0, 'derived' => false, 'candidates' => [], 'state' => 'empty',
            ];
            if ($text !== '') {
                $m = import_match_header($text, $aliases);
                $info['candidates'] = $m['candidates'];
                $info['conf'] = $m['conf'];
                if ($m['field'] !== null && $m['conf'] >= $thr) {
                    $info['field'] = $m['field'];
                    $info['derived'] = import_field_is_derived($m['field'], $aliases);
                    $info['state'] = 'mapped';
                } elseif ($m['field'] !== null) {
                    $info['state'] = 'question';
                    $info['suggested_field'] = $m['field'];
                } else {
                    $info['state'] = 'unmapped';
                }
                // #10: which fields a question about this heading offers (the grammar's, else the one suggested); what this hotel told us before.
                // Keys are added only when they apply, so every other analysis stays byte-identical.
                $sug = (string)($m['field'] ?? '');
                if ($info['state'] === 'question' && (in_array($sug, import_hg_fields(), true) || !empty($m['grammar']))) {
                    $g = $m['grammar'] ?? import_header_grammar($text, $aliases);
                    $info['hg_alts'] = $g !== null && in_array($sug, (array)$g['alts'], true) ? array_values((array)$g['alts']) : [$sug];
                    $hgNot = (array)($aliases['field_not'][import_norm_text($text)] ?? []);
                    if ($hgNot) {           // a field the hotel said "not this" to is never offered again (the suggested one is never one of them)
                        $info['hg_alts'] = array_values(array_filter($info['hg_alts'], function ($f) use ($hgNot, $sug) {
                            return $f === $sug || !isset($hgNot[$f]);
                        }));
                    }
                }
                $nk = import_norm_text($text);
                if ($info['state'] === 'mapped' && isset($aliases['learned_heads'][$nk][$sug])) {
                    $info['learned'] = ['field' => $sug, 'at' => (string)$aliases['learned_heads'][$nk][$sug]];
                }
                if (!empty($m['not_this'])) {
                    $nf = (string)$m['not_this'];
                    $info['learned_not'] = ['field' => $nf, 'at' => (string)($aliases['field_not'][$nk][$nf] ?? '')];
                }
            }
            $columns[$c] = $info;
        }
        import_map_apply_fallbacks($columns, $aliases);
        // two columns claiming the same non-derived field: highest confidence keeps it, rest become questions
        $claims = [];
        foreach ($columns as $c => $info) {
            if ($info['state'] === 'mapped') {
                $claims[$info['field']][] = $c;
            }
        }
        foreach ($claims as $field => $cols) {
            if (count($cols) < 2) {
                continue;
            }
            usort($cols, function ($a, $b) use ($columns) {
                return $columns[$b]['conf'] <=> $columns[$a]['conf'] ?: $a <=> $b;
            });
            $tied = $columns[$cols[0]]['conf'] === $columns[$cols[1]]['conf'];
            if (import_field_is_derived($field, $aliases)) {
                continue; // several derived columns of one kind are harmless
            }
            if ($tied) {
                // cannot tell them apart: none is applied until a person picks
                foreach ($cols as $c) {
                    $columns[$c]['state'] = 'question';
                    $columns[$c]['suggested_field'] = $field;
                    $columns[$c]['field'] = null;
                    $columns[$c]['clash'] = true;
                }
            } else {
                foreach (array_slice($cols, 1) as $c) {
                    $columns[$c]['state'] = 'question';
                    $columns[$c]['suggested_field'] = $field;
                    $columns[$c]['field'] = null;
                    $columns[$c]['clash'] = true;
                }
            }
        }
        foreach ($columns as $c => $info) {
            if ($info['state'] === 'mapped') {
                $byField[$info['field']] = $byField[$info['field']] ?? $c;
            }
        }
        return ['columns' => $columns, 'by_field' => $byField, 'questions' => $questions];
    }

    /** Is a cell a usable (known) value cell? */
    function import_cell_usable(?array $cell): bool
    {
        return $cell !== null && $cell['cached'] && !$cell['error'] && $cell['v'] !== null;
    }

    /**
     * Value-shape check over the item rows. Demotes a mapped column whose values do not fit the field
     * (state => 'question') and returns the list of question descriptors.
     * $itemRows = list of row numbers.
     */
    function import_map_check_shapes(array &$map, array $sheet, array $itemRows, array $aliases): array
    {
        $issues = [];
        foreach ($map['columns'] as $c => &$info) {
            if ($info['state'] !== 'mapped' || $info['derived']) {
                continue;
            }
            $kind = import_field_kind($info['field']);
            $total = 0;
            $good = 0;
            $pos = 0;
            foreach ($itemRows as $r) {
                $cell = $sheet['cells'][$r][$c] ?? null;
                if (!import_cell_usable($cell)) {
                    continue;
                }
                $v = $cell['v'];
                if ($v === '' || import_is_empty_marker($v)) {
                    continue;
                }
                $total++;
                if ($kind === 'number') {
                    $p = import_parse_number($v);
                    if ($p['status'] === 'ok') {
                        $good++;
                        if ($p['value'] > 0) {
                            $pos++;
                        }
                    } elseif ($info['field'] !== 'unit_price' && is_string($v) && import_heal_number_unit($v, $unitWordsShape ?? ($unitWordsShape = import_unit_words($aliases))) !== null) {
                        $good++;            // "12 pcs": the validator reads it as 12 and pcs (a repair the person can undo), so the column does hold quantities
                    }
                } elseif ($kind === 'volume') {
                    if (import_parse_volume($v, import_header_ml_mul((string)$info['header']))['status'] === 'ok') {
                        $good++;
                    }
                } elseif ($kind === 'date') {
                    if (!empty($cell['date'])) {
                        $good++;
                    } elseif (is_string($v) && import_parse_date_string($v)['ok']) {
                        $good++;
                    } elseif (is_numeric($v) && $v > 30000 && $v < 80000) {
                        $good++;
                    }
                } elseif ($info['field'] === 'item_name') {
                    if (!is_numeric($v)) {
                        $good++;
                    }
                } elseif ($info['field'] === 'pack_name' || $info['field'] === 'served_by') {
                    // a bought-as / served-by cell must be a unit, pack or portion word (or a pack written out: "Case of 24"): "Packing Date" is not
                    $pv = $pv ?? import_pack_vocab($aliases);
                    $wn = import_norm_text((string)$v);
                    $wk = $info['field'] === 'served_by' ? import_served_kind((string)$v, $pv) : import_pack_word_kind((string)$v, $pv);   // "Per shot", "Tot 25ml" (owner 2026-11-20)
                    // a bought-as word with its size ("Bottle(750ML)", owner 2026-11-25) is a unit word too: the validator splits it (word + size, one cell)
                    $wSized = $info['field'] === 'pack_name' && is_string($v) && function_exists('import_validate_unit_size') && import_validate_unit_size($v, $pv) !== null;
                    if ($wk !== '' || isset($pv['unit'][$wn]) || $wSized || (is_string($v) && import_pack_parse_text($v, $pv) !== null)) {
                        $good++;
                    }
                } else {
                    $good++;
                }
            }
            $info['shape'] = ['filled' => $total, 'fits' => $good];
            if ($total >= 3 && $good / $total < 0.7 && in_array($info['field'], ['pack_name', 'served_by'], true)) {
                // a heading that only looked like "bought as" / "served by" ("Packing Date"): its cells are not unit words, so it is simply not read
                $info['state'] = 'unmapped';
                $info['field'] = null;
                $info['not_units'] = true;
            } elseif ($total >= 3 && $good / $total < 0.7) {
                $info['state'] = 'question';
                $info['suggested_field'] = $info['field'];
                $info['field'] = null;
                $info['shape_fail'] = true;
                $issues[] = $c;
            }
        }
        unset($info);
        // refresh by_field
        $map['by_field'] = [];
        foreach ($map['columns'] as $c => $info) {
            if ($info['state'] === 'mapped') {
                $map['by_field'][$info['field']] = $map['by_field'][$info['field']] ?? $c;
            }
        }
        // v11: what a measures-per-unit heading names ("Shots per bottle": shot, bottle) and the unit a size heading is in ("Bottle size (ml)")
        foreach ($map['columns'] as $c => $info) {
            if ($info['state'] === 'mapped' && $info['field'] === 'measures_per_unit') {
                $map['columns'][$c]['per'] = import_per_unit_words((string)$info['header'], $aliases);
            } elseif ($info['state'] === 'mapped' && in_array($info['field'], ['unit_ml', 'measure_ml'], true)) {
                $map['columns'][$c]['ml_mul'] = import_header_ml_mul((string)$info['header']);
                $map['columns'][$c]['per'] = import_per_unit_words((string)$info['header'], $aliases);      // "Glass size": a glass
            }
        }
        return $issues;
    }

    /**
     * v11: the measure word and the unit word a "how many in one" heading names, both from the heading itself: "Shots per bottle" -> shot / bottle,
     * "Glasses per casket" -> glass / casket, "No of tots" -> tot / '' (the stock unit), "Measures per unit" -> '' / '' (a generic heading: the
     * measure is the row's served-by measure). Singular, lower case. 'generic' = the heading's measure word names no fixed measure.
     */
    function import_per_unit_words(string $header, array $aliases): array
    {
        $measSet = import_word_set(array_merge((array)($aliases['shot_words'] ?? []), (array)($aliases['glass_words'] ?? []), (array)($aliases['portion_words'] ?? []),
            ['double', 'doubles'], (array)($aliases['measure_names'] ?? [])));       // #10: + the hotel's Drink measures list names ("Jiggers per bottle")
        $glassSet = import_word_set((array)($aliases['glass_words'] ?? []));
        $generic = ['measure' => true, 'serving' => true, 'portion' => true, 'unit' => true, 'single' => true];
        $syn = (array)($aliases['unit_synonyms'] ?? []) + import_hg_abbreviations();
        $unitSet = import_word_set(array_merge((array)($aliases['units'] ?? []), (array)($aliases['pour_containers'] ?? []), array_keys($syn)));
        $m = '';
        $u = '';
        $isGeneric = false;
        foreach (explode(' ', import_norm_text($header)) as $tok) {
            if ($tok === '' || in_array($tok, ['per', 'of', 'in', 'a', 'an', 'one', 'no', 'number'], true)) {
                continue;
            }
            $s = import_measure_singular($tok);
            if ($m === '' && !$isGeneric && (isset($measSet[$tok]) || isset($measSet[$s]) || isset($generic[$s]))) {
                if (isset($generic[$s])) {
                    $isGeneric = true;
                } else {
                    $m = (isset($glassSet[$tok]) || isset($glassSet[$s])) ? 'glass' : $s;        // #10: "Gls per btl" is a glass
                }
                continue;
            }
            if ($u === '' && !isset($generic[$s]) && (isset($unitSet[$tok]) || isset($unitSet[$s]))) {
                $c = (string)($syn[$tok] ?? $syn[$s] ?? $s);
                $u = import_measure_singular(mb_strtolower($c));
            }
        }
        return ['measure' => $m, 'unit' => $u, 'generic' => $m === ''];
    }

    /** v11: the unit a size heading names ("Bottle size (ml)" = 1, "(cl)" = 10, "(L)" / "litres" = 1000), or null when it names none. */
    function import_header_ml_mul(string $header): ?float
    {
        foreach (explode(' ', import_norm_text($header)) as $tok) {
            if (in_array($tok, ['ml', 'mls', 'millilitre', 'millilitres', 'milliliter', 'milliliters'], true)) {
                return 1.0;
            }
            if (in_array($tok, ['cl', 'centilitre', 'centilitres'], true)) {
                return 10.0;
            }
            if (in_array($tok, ['l', 'lt', 'ltr', 'ltrs', 'litre', 'litres', 'liter', 'liters'], true)) {
                return 1000.0;
            }
        }
        return null;
    }

    /** Plain-English question for a column that could not be mapped with confidence. */
    function import_map_question_text(array $info, string $sheetName): string
    {
        $h = $info['header'];
        $label = ucfirst(str_replace('_', ' ', (string)($info['suggested_field'] ?? '')));
        if (!empty($info['shape_fail'])) {
            return 'Column ' . $info['letter'] . ' "' . $h . '" in ' . $sheetName . ' looks like it holds "' . $label . '", but most of the values under it do not fit. What is this column?';
        }
        if (!empty($info['clash'])) {
            return 'Columns in ' . $sheetName . ' both look like "' . $label . '" (column ' . $info['letter'] . ' "' . $h . '"). Which one holds the real ' . strtolower($label) . '?';
        }
        return 'I am not sure what column ' . $info['letter'] . ' "' . $h . '" in ' . $sheetName . ' is. Is it "' . $label . '"?';
    }

    /** What the cells of one column look like: counts of filled cells, numbers, small whole numbers, container / unit / portion words. */
    function import_map_value_profile(array $sheet, int $col, array $rows, array $vocab, array $unitWords): array
    {
        $p = ['filled' => 0, 'num' => 0, 'small_int' => 0, 'container' => 0, 'portion' => 0, 'unitword' => 0, 'samples' => []];
        foreach ($rows as $r) {
            $cell = $sheet['cells'][$r][$col] ?? null;
            if (!import_cell_usable($cell)) {
                continue;
            }
            $v = $cell['v'];
            if (is_string($v) && (trim($v) === '' || import_is_empty_marker($v))) {
                continue;
            }
            $p['filled']++;
            if (count($p['samples']) < 5) {
                $p['samples'][] = is_string($v) ? import_norm_ws($v) : $v;
            }
            $n = import_parse_number($v);
            if ($n['status'] === 'ok') {
                $p['num']++;
                if ($n['value'] >= 1 && $n['value'] <= 1000 && abs($n['value'] - round($n['value'])) < 1e-9) {
                    $p['small_int']++;
                }
                continue;
            }
            $w = import_norm_text((string)$v);
            if (isset($vocab['container'][$w])) {
                $p['container']++;
            } elseif (isset($vocab['portion'][$w])) {
                $p['portion']++;
            } elseif (isset($unitWords[$w])) {
                $p['unitword']++;
            }
        }
        return $p;
    }

    /**
     * Reading a column from its VALUES as well as its heading (owner 2026-11-17: "pick up other patterns or abnormal column names"). Only ever
     * makes a heading question unnecessary when the values leave no doubt; every column read this way says so ('how_values', shown in step 2):
     *   - a typo-level heading ("Unit Bougth As") whose values fit that field (container words, small whole numbers, numbers) -> mapped;
     *   - an unrecognised heading over container words -> bought as; over unit words (bottle, can, shot) -> sold / served by (or unit);
     *     small whole numbers right next to the bought-as column -> pack size;
     *   - two price columns the headings cannot tell apart, one higher on (nearly) every row -> the lower is the cost, the higher the selling price.
     * Uses up to 2,000 rows under the heading whose name cell is filled. Never touches a mapped column, a guard-word heading or a shape question.
     */
    function import_map_infer_by_values(array &$map, array $sheet, int $headerRow, array $aliases): void
    {
        $nameCol = $map['by_field']['item_name'] ?? null;
        if ($nameCol === null) {
            return;
        }
        $rows = [];
        $last = min((int)($sheet['dims']['rows'] ?? 0), $headerRow + 2000);
        for ($r = $headerRow + 1; $r <= $last; $r++) {
            if (import_cell_text($sheet['cells'][$r][$nameCol] ?? null) !== '') {
                $rows[] = $r;
            }
        }
        if (count($rows) < 3) {
            return;
        }
        $vocab = import_pack_vocab($aliases);
        $unitWords = [];
        foreach (array_merge((array)($aliases['units'] ?? []), array_keys((array)($aliases['unit_synonyms'] ?? []))) as $u) {
            $unitWords[import_norm_text((string)$u)] = true;
        }
        $taken = [];
        foreach ($map['columns'] as $info) {
            if ($info['state'] === 'mapped' && $info['field'] !== null) {
                $taken[$info['field']] = true;
            }
        }
        $words = function (array $p) {
            return $p['container'] + $p['portion'] + $p['unitword'];
        };
        $fits = function (string $field, array $p) use ($words) {
            if ($p['filled'] < 3) {
                return false;
            }
            if ($field === 'pack_name') {
                return $p['container'] > 0 && $p['container'] + $p['unitword'] >= 0.7 * $p['filled'];
            }
            if ($field === 'served_by' || $field === 'unit') {
                return $words($p) >= 0.7 * $p['filled'];
            }
            if ($field === 'pack_size') {
                return $p['small_int'] >= 0.8 * $p['filled'];
            }
            // a plain number says nothing about WHICH number (balance, par, reorder level, price): a misspelt number heading stays a question
            return false;
        };
        $promote = function (int $c, string $field, string $why) use (&$map, &$taken, $aliases) {
            $map['columns'][$c]['field'] = $field;
            $map['columns'][$c]['derived'] = import_field_is_derived($field, $aliases);
            $map['columns'][$c]['state'] = 'mapped';
            $map['columns'][$c]['how_values'] = $why;
            unset($map['columns'][$c]['suggested_field'], $map['columns'][$c]['clash']);
            $taken[$field] = true;
        };
        // 1) a misspelt heading confirmed by its values: the field the heading is closest to (a typo-level match, or the whole heading within
        //    a typing slip of a bought-as / served-by / pack-size phrase: "Unit Bougth As") whose cells fit that field without doubt
        $idx = import_alias_index($aliases);
        foreach ($map['columns'] as $c => $info) {
            if ($info['state'] !== 'question' || !empty($info['clash']) || !empty($info['shape_fail']) || empty($info['suggested_field'])) {
                continue;
            }
            $cands = [];
            if ((float)($info['candidates'][0]['conf'] ?? 0) === 0.6) {
                $cands[] = [(string)$info['suggested_field'], (string)($info['candidates'][0]['alias'] ?? $info['suggested_field'])];
            }
            $key = implode(' ', import_header_tokens((string)$info['header'], $idx));
            $best = null;
            foreach ($idx['list'] as $a) {
                if (!in_array($a['field'], ['pack_name', 'served_by', 'pack_size'], true) || strlen($a['norm']) < 6) {
                    continue;
                }
                $sim = import_similarity($key, $a['norm']);
                if ($sim >= 0.8 && ($best === null || $sim > $best[2])) {
                    $best = [$a['field'], $a['norm'], $sim];
                }
            }
            if ($best !== null) {
                array_unshift($cands, [$best[0], $best[1]]);
            }
            $p = null;
            foreach ($cands as [$f, $alias]) {
                if (isset($taken[$f])) {
                    continue;
                }
                $p = $p ?? import_map_value_profile($sheet, $c, $rows, $vocab, $unitWords);
                if ($fits($f, $p)) {
                    $promote($c, $f, 'the heading is close to "' . $alias . '" and ' . $p['filled'] . ' of its cells fit');
                    break;
                }
            }
        }
        // 2) an unrecognised heading: container words / unit words / pack sizes beside the bought-as column
        $packCol = null;
        foreach ($map['columns'] as $c => $info) {
            if ($info['state'] === 'mapped' && $info['field'] === 'pack_name') {
                $packCol = $c;
            }
        }
        $profiles = [];
        foreach ($map['columns'] as $c => $info) {
            if ($info['state'] === 'unmapped') {
                $profiles[$c] = import_map_value_profile($sheet, $c, $rows, $vocab, $unitWords);
            }
        }
        foreach ($profiles as $c => $p) {
            if ($p['filled'] >= 3 && !isset($taken['pack_name']) && $p['container'] >= 0.6 * $p['filled'] && $words($p) >= 0.8 * $p['filled']) {
                $promote($c, 'pack_name', 'mostly container words such as "' . $p['samples'][0] . '"');
                $packCol = $c;
                unset($profiles[$c]);
            }
        }
        foreach ($profiles as $c => $p) {
            if ($p['filled'] >= 3 && $words($p) >= 0.8 * $p['filled'] && $p['container'] < 0.5 * $p['filled']) {
                $f = $packCol !== null ? 'served_by' : 'unit';
                if (!isset($taken['unit']) && !isset($taken['served_by'])) {
                    $promote($c, $f, 'mostly unit words such as "' . $p['samples'][0] . '"');
                    unset($profiles[$c]);
                }
            }
        }
        if ($packCol !== null && !isset($taken['pack_size'])) {
            foreach ([$packCol + 1, $packCol - 1] as $c) {
                if (isset($profiles[$c]) && $profiles[$c]['filled'] >= 3 && $profiles[$c]['small_int'] >= 0.9 * $profiles[$c]['filled']) {
                    // a number that prices are DIVIDED by is never taken from its place alone (it could be a count, or a bottle size in ml):
                    // the person confirms the heading once, and a confirmed heading is remembered for this hotel (A87)
                    $map['columns'][$c]['state'] = 'question';
                    $map['columns'][$c]['suggested_field'] = 'pack_size';
                    $map['columns'][$c]['value_hint'] = 'small whole numbers next to the bought-as column';
                    break;
                }
            }
        }
        // 3) two price columns the headings cannot tell apart: the one that is higher on (nearly) every row is the selling price
        $clash = [];
        foreach ($map['columns'] as $c => $info) {
            if ($info['state'] === 'question' && !empty($info['clash']) && ($info['suggested_field'] ?? '') === 'unit_price') {
                $clash[] = $c;
            }
        }
        // ... and only when both headings are plain price words ("Cost" / "Price"): a heading with a word of its own ("Old price" / "New price",
        // "Price USD" / "Price MWK") says something the values cannot, so those two stay a question
        if (count($clash) === 2 && !isset($taken['sell_price']) && (float)$map['columns'][$clash[0]]['conf'] >= 1.0 && (float)$map['columns'][$clash[1]]['conf'] >= 1.0) {
            [$a, $b] = $clash;
            $n = 0;
            $aLow = 0;
            $bLow = 0;
            foreach ($rows as $r) {
                $ca = $sheet['cells'][$r][$a] ?? null;
                $cb = $sheet['cells'][$r][$b] ?? null;
                if (!import_cell_usable($ca) || !import_cell_usable($cb)) {
                    continue;
                }
                $va = import_parse_number($ca['v']);
                $vb = import_parse_number($cb['v']);
                if ($va['status'] !== 'ok' || $vb['status'] !== 'ok' || $va['value'] <= 0 || $vb['value'] <= 0) {
                    continue;
                }
                $n++;
                $aLow += $va['value'] < $vb['value'] ? 1 : 0;
                $bLow += $vb['value'] < $va['value'] ? 1 : 0;
            }
            if ($n >= 5 && max($aLow, $bLow) >= 0.9 * $n) {
                [$cost, $sell] = $aLow > $bLow ? [$a, $b] : [$b, $a];
                $promote($cost, 'unit_price', 'lower than the other price column on ' . max($aLow, $bLow) . ' of ' . $n . ' rows, so it is the cost');
                $promote($sell, 'sell_price', 'higher than the other price column on ' . max($aLow, $bLow) . ' of ' . $n . ' rows, so it is the selling price');
            }
        }
        $map['by_field'] = [];
        foreach ($map['columns'] as $c => $info) {
            if ($info['state'] === 'mapped') {
                $map['by_field'][$info['field']] = $map['by_field'][$info['field']] ?? $c;
            }
        }
    }

    /**
     * HOOK for an optional helper (the Assistant lead's column reader; Import works fully without it): the columns of an analysis that are not
     * read with confidence (a heading question, or an unrecognised heading with data), each with its heading, cell and up to 5 sample values
     * taken from the analysed rows. Returns [['sheet','letter','header','ref','state','suggested_field','samples'=>[...]], ...]. Pure, read-only.
     * (Values of unread columns are not kept in the rows: pass $sheetsCells = [sheet name => reader sheet] to sample those too.)
     */
    function import_unsure_columns(array $analysis, array $sheetsCells = []): array
    {
        $out = [];
        foreach ((array)($analysis['sheets'] ?? []) as $sh) {
            foreach ((array)($sh['columns'] ?? []) as $c) {
                if (!in_array($c['state'] ?? '', ['question', 'unmapped'], true) || ($c['header'] ?? '') === '') {
                    continue;
                }
                $samples = [];
                $grid = $sheetsCells[(string)$sh['name']] ?? null;
                if ($grid && !empty($sh['header_row'])) {
                    foreach ((array)($sh['rows'] ?? []) as $row) {
                        $cell = $grid['cells'][(int)$row['row']][(int)$c['col']] ?? null;
                        if (import_cell_usable($cell)) {
                            $samples[] = $cell['v'];
                        }
                        if (count($samples) >= 5) {
                            break;
                        }
                    }
                }
                $out[] = ['sheet' => (string)$sh['name'], 'letter' => (string)$c['letter'], 'header' => (string)$c['header'], 'ref' => (string)($c['header_ref'] ?? ''),
                    'state' => (string)$c['state'], 'suggested_field' => $c['suggested_field'] ?? null, 'samples' => $samples];
            }
        }
        return $out;
    }

    /**
     * HOOK, the other half: a SUGGESTED reading of an unsure column (["sheet" => "Sheet1", "letter" => "F", "field" => "pack_name", "why" => ...]) is
     * attached to that column as 'suggested' and is NEVER an answer: the person confirms it like any other answer (the column's own question).
     * Only a known field is accepted, only on a column that is unsure; anything else is dropped (rule 4). Returns how many were attached.
     */
    function import_attach_column_suggestions(array &$analysis, array $suggest, array $aliases): int
    {
        $known = array_keys((array)($aliases['fields'] ?? []));
        $n = 0;
        foreach ($suggest as $s) {
            $field = (string)($s['field'] ?? '');
            if (!in_array($field, $known, true)) {
                continue;
            }
            foreach ($analysis['sheets'] as &$sh) {
                if ((string)$sh['name'] !== (string)($s['sheet'] ?? '')) {
                    continue;
                }
                foreach ($sh['columns'] as &$c) {
                    if ((string)$c['letter'] === (string)($s['letter'] ?? '') && in_array($c['state'], ['question', 'unmapped'], true)) {
                        $c['suggested'] = ['field' => $field, 'why' => mb_substr((string)($s['why'] ?? ''), 0, 200), 'confirmed' => false];
                        $n++;
                    }
                }
                unset($c);
            }
            unset($sh);
        }
        return $n;
    }
}
