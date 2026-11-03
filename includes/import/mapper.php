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

if (!function_exists('import_match_header')) {

    function import_map_auto_threshold(): float
    {
        return 0.85;
    }

    /** Prepared lookup of an alias set (cached by content). */
    function import_alias_index(array $aliases): array
    {
        static $cache = [];
        $key = md5(json_encode([$aliases['fields'] ?? [], $aliases['noise_tokens'] ?? []]));
        if (isset($cache[$key])) {
            return $cache[$key];
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
        return $cache[$key] = ['noise' => $noise, 'exact' => $exact, 'list' => $list];
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
        $cands = [];
        if (isset($idx['exact'][$key])) {
            $cands[] = ['field' => $idx['exact'][$key], 'conf' => 1.0, 'alias' => $key];
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
                $sim = import_similarity($key, $a['norm']);
                if ($sim >= 0.8) {
                    $cands[] = ['field' => $a['field'], 'conf' => 0.6, 'alias' => $a['norm'], 'sim' => $sim];
                    $seen[$a['field']] = true;
                }
            }
        }
        if (!$cands) {
            return $none;
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
        return ['field' => $best['field'], 'conf' => $conf, 'alias' => $best['alias'], 'candidates' => array_map(function ($c) {
            return ['field' => $c['field'], 'conf' => $c['conf'], 'alias' => $c['alias']];
        }, $cands)];
    }

    function import_field_is_derived(string $field, array $aliases): bool
    {
        return in_array($field, $aliases['derived'] ?? [], true);
    }

    function import_field_kind(string $field): string
    {
        if (in_array($field, ['qty_primary', 'qty_secondary', 'qty_single', 'max_level', 'min_level', 'unit_price'], true)) {
            return 'number';
        }
        if ($field === 'expiry') {
            return 'date';
        }
        return 'text';
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
            }
            $columns[$c] = $info;
        }
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
                } else {
                    $good++;
                }
            }
            $info['shape'] = ['filled' => $total, 'fits' => $good];
            if ($total >= 3 && $good / $total < 0.7) {
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
        return $issues;
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
}
