<?php
/**
 * Import engine - cross-checker. Only ever ADDS questions/warnings; it never changes a value.
 *
 *   import_crosscheck(&$analysis, $opts)         one file: same item on different sheets disagreeing
 *                                                 on price/unit, case-price look, possible typos
 *   import_crosscheck_files(&$analyses, $opts)   several files of one upload batch: same item in two
 *                                                 files disagreeing on price/unit, typos across files
 * Conflicts list BOTH values with their sheet!cell. There is never a winner: a person picks.
 * opts: case_price_ratio (default 5.0), case_price_min_peers (4), typo_min_score (0.85),
 *       known_names (existing names to suggest from; suggestions only).
 */

require_once __DIR__ . '/validator.php';

if (!function_exists('import_crosscheck')) {

    function import_fmt_money($v): string
    {
        // unit prices are stored to 4 decimals: show up to 4 (at least 2 when there is a fraction), whole amounts without decimals
        $s = number_format((float)$v, 4, '.', ',');
        if (strpos($s, '.') !== false) {
            $s = rtrim(rtrim($s, '0'), '.');
            $p = strpos($s, '.');
            if ($p !== false && strlen($s) - $p - 1 < 2) {
                $s = number_format((float)$v, 2, '.', ',');
            }
        }
        return $s;
    }

    function import_unit_key(string $u): string
    {
        $n = import_norm_text($u);
        if (strlen($n) > 3 && substr($n, -1) === 's') {
            $n = substr($n, 0, -1);
        }
        return $n;
    }

    /** Collect item entries from analyses (by reference). $originOf = 'sheet' | 'file' */
    function import_cc_entries(array &$analyses, string $originOf): array
    {
        $entries = [];
        foreach ($analyses as $a => &$an) {
            foreach ($an['sheets'] as $s => &$sh) {
                if ($sh['triage']['action'] !== 'import') {
                    continue;
                }
                foreach ($sh['rows'] as $i => $row) {
                    $f = $row['fields'];
                    $entries[] = [
                        'a' => $a, 's' => $s, 'i' => $i,
                        'key' => import_norm_text($f['item_name']['value']),
                        'name' => $f['item_name']['value'],
                        'origin' => $originOf === 'file' ? (string)$a : ($a . '|' . $s),
                        'file' => $an['file']['name'] ?? '', 'sheet' => $sh['name'], 'row' => $row['row'], 'uid' => $row['uid'],
                        'price' => isset($f['unit_price']) ? $f['unit_price']['value'] : null,
                        'price_src' => isset($f['unit_price']) ? $f['unit_price']['source'] : null,
                        'unit' => isset($f['unit']) ? $f['unit']['value'] : null,
                        'unit_src' => isset($f['unit']) ? $f['unit']['source'] : null,
                    ];
                }
            }
            unset($sh);
        }
        unset($an);
        return $entries;
    }

    /** Add a question to every analysis it concerns. $uidsByAnalysis = [a => [uids]] */
    function import_cc_add_question(array &$analyses, array $q, array $uidsByAnalysis, ?array $conflict = null): void
    {
        foreach ($uidsByAnalysis as $a => $uids) {
            $qq = $q;
            $qq['row_uids'] = array_values(array_unique($uids));
            if ($conflict !== null) {
                $conflict['row_uids'] = $qq['row_uids'];
            }
            $id = import_register_question($analyses[$a], $qq);
            if ($conflict !== null) {
                $c = $conflict;
                $c['question_id'] = $id;
                $analyses[$a]['conflicts'][] = $c;
            }
        }
    }

    function import_cc_distinct_money(array $vals): array
    {
        $out = [];
        foreach ($vals as $v) {
            $dup = false;
            foreach ($out as $o) {
                if (import_money_equal($o, $v)) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $out[] = $v;
            }
        }
        sort($out);
        return $out;
    }

    function import_cc_sets_differ(array $a, array $b, bool $money): bool
    {
        if (count($a) !== count($b)) {
            return true;
        }
        foreach ($a as $i => $v) {
            if ($money ? !import_money_equal($v, $b[$i]) : $v !== $b[$i]) {
                return true;
            }
        }
        return false;
    }

    function import_cc_conflicts(array &$analyses, array $entries, string $mode): void
    {
        $byName = [];
        foreach ($entries as $e) {
            $byName[$e['key']][] = $e;
        }
        $n = 0;
        foreach ($byName as $key => $list) {
            foreach (['unit_price', 'unit'] as $field) {
                $isMoney = ($field === 'unit_price');
                $origins = [];
                foreach ($list as $e) {
                    $v = $isMoney ? $e['price'] : ($e['unit'] === null ? null : import_unit_key($e['unit']));
                    if ($v === null) {
                        continue;
                    }
                    $origins[$e['origin']][] = ['v' => $v, 'e' => $e];
                }
                if (count($origins) < 2) {
                    continue;
                }
                $sets = [];
                foreach ($origins as $o => $items) {
                    $vals = array_map(function ($x) {
                        return $x['v'];
                    }, $items);
                    $sets[$o] = $isMoney ? import_cc_distinct_money($vals) : array_values(array_unique($vals));
                    if (!$isMoney) {
                        sort($sets[$o]);
                    }
                }
                $keys = array_keys($sets);
                $differ = false;
                for ($i = 0; $i < count($keys) && !$differ; $i++) {
                    for ($j = $i + 1; $j < count($keys); $j++) {
                        if (import_cc_sets_differ($sets[$keys[$i]], $sets[$keys[$j]], $isMoney)) {
                            $differ = true;
                            break;
                        }
                    }
                }
                if (!$differ) {
                    continue;
                }
                // build the side-by-side list (one entry per origin+value), and the rows involved
                $values = [];
                $uidsBy = [];
                foreach ($origins as $o => $items) {
                    foreach ($items as $x) {
                        $e = $x['e'];
                        $uidsBy[$e['a']][] = $e['uid'];
                        $label = $isMoney ? import_fmt_money($e['price']) : (string)$e['unit'];
                        $src = $isMoney ? $e['price_src'] : $e['unit_src'];
                        $found = false;
                        foreach ($values as &$vv) {
                            if ($vv['origin'] === $o && ($isMoney ? import_money_equal($vv['value'], $e['price']) : import_unit_key($vv['value']) === import_unit_key($e['unit']))) {
                                $vv['sources'][] = $src;
                                $found = true;
                                break;
                            }
                        }
                        unset($vv);
                        if (!$found) {
                            $values[] = [
                                'origin' => $o, 'value' => $isMoney ? $e['price'] : $e['unit'], 'label' => $label,
                                'source' => $src, 'sources' => [$src], 'file' => $e['file'], 'sheet' => $e['sheet'], 'row' => $e['row'],
                            ];
                        }
                    }
                }
                $parts = [];
                $opts = [];
                $seenLabel = [];
                foreach ($values as $vv) {
                    $where = ($mode === 'files' && $vv['file'] !== '' ? $vv['file'] . ' > ' : '') . $vv['source'];
                    $parts[] = $vv['label'] . ' (' . $where . ')';
                    if (!isset($seenLabel[$vv['label']])) {
                        $seenLabel[$vv['label']] = true;
                        $opts[] = ['key' => 'use:' . $vv['label'], 'label' => 'Use ' . $vv['label']];
                    }
                }
                $opts[] = ['key' => 'skip', 'label' => 'Skip this item'];
                $displayName = $list[0]['name'];
                $word = $isMoney ? 'unit prices' : 'units';
                $q = import_q('conflict', '"' . $displayName . '" has different ' . $word . ': ' . implode(' vs ', $parts) . '. Which is right?', [
                    'sheet' => $values[0]['sheet'], 'row' => null, 'cells' => array_map(function ($vv) {
                        return $vv['source'];
                    }, $values),
                    'options' => $opts, 'conflict_field' => $field,
                ]);
                $conflict = [
                    'id' => 'c' . (++$n) . '-' . substr(md5($key . $field), 0, 6), 'field' => $field, 'item' => $displayName, 'name_key' => $key,
                    'values' => array_map(function ($vv) {
                        unset($vv['origin']);
                        return $vv;
                    }, $values),
                ];
                import_cc_add_question($analyses, $q, $uidsBy, $conflict);
            }
        }
    }

    /** "Price looks like a case price" questions: a price far above the others in its category/sheet. */
    function import_cc_case_prices(array &$analysis, array $opts): void
    {
        $ratio = (float)($opts['case_price_ratio'] ?? 5.0);
        $minPeers = (int)($opts['case_price_min_peers'] ?? 4);
        foreach ($analysis['sheets'] as $s => $sh) {
            if ($sh['triage']['action'] !== 'import') {
                continue;
            }
            $priced = [];
            foreach ($sh['rows'] as $i => $row) {
                if (isset($row['fields']['unit_price']) && $row['fields']['unit_price']['value'] > 0) {
                    $priced[$i] = (float)$row['fields']['unit_price']['value'];
                }
            }
            if (count($priced) < $minPeers + 1) {
                continue;
            }
            foreach ($priced as $i => $price) {
                $row = $sh['rows'][$i];
                $cat = $row['category'];
                $peers = [];
                $scope = 'sheet';
                if ($cat !== null) {
                    foreach ($priced as $j => $p) {
                        if ($j !== $i && $sh['rows'][$j]['category'] === $cat) {
                            $peers[] = $p;
                        }
                    }
                    if (count($peers) >= $minPeers) {
                        $scope = 'category';
                    } else {
                        $peers = [];
                    }
                }
                if (!$peers) {
                    foreach ($priced as $j => $p) {
                        if ($j !== $i) {
                            $peers[] = $p;
                        }
                    }
                }
                $med = import_median($peers);
                if ($med === null || $med <= 0 || $price < $ratio * $med) {
                    continue;
                }
                $src = $row['fields']['unit_price']['source'];
                $q = import_q('case-price', 'Row ' . $row['row'] . ' "' . $row['fields']['item_name']['value'] . '" has a unit price of ' . import_fmt_money($price)
                    . ' (' . $src . '), about ' . round($price / $med, 1) . ' times the usual ' . import_fmt_money($med) . ' in its ' . ($scope === 'category' ? 'category "' . $cat . '"' : 'sheet')
                    . '. Is that the price of one unit, or of a whole case/pack?', [
                    'sheet' => $sh['name'], 'row' => $row['row'], 'cells' => [$src],
                    'options' => [
                        ['key' => 'per-unit', 'label' => 'Price of one unit (import as written)'],
                        ['key' => 'per-case', 'label' => 'Price of a whole case/pack (I will enter the pack size)'],
                    ],
                    'case_ratio' => round($price / $med, 2),
                ]);
                $q['row_uids'] = [$row['uid']];
                import_register_question($analysis, $q);
            }
        }
    }

    /** Possible-typo suggestions (non-blocking). Names in $pools are compared pairwise or against known names. */
    function import_cc_typos(array &$analyses, array $entries, array $opts, string $mode): void
    {
        $min = (float)($opts['typo_min_score'] ?? 0.85);
        $known = $opts['known_names'] ?? [];
        // distinct names
        $names = [];
        foreach ($entries as $e) {
            $k = $e['key'];
            if (!isset($names[$k])) {
                $names[$k] = ['name' => $e['name'], 'rows' => [], 'files' => []];
            }
            $names[$k]['rows'][] = $e;
            $names[$k]['files'][$e['a']] = true;
        }
        if (count($names) > 3000) {
            return;
        }
        $keys = array_keys($names);
        $byLen = [];
        foreach ($keys as $k) {
            $byLen[strlen($k)][] = $k;
        }
        $done = [];
        foreach ($keys as $k) {
            $len = strlen($k);
            if ($len < 5) {
                continue;
            }
            for ($l = $len - 3; $l <= $len + 3; $l++) {
                foreach ($byLen[$l] ?? [] as $k2) {
                    if ($k2 === $k || isset($done[$k2 . '|' . $k]) || isset($done[$k . '|' . $k2])) {
                        continue;
                    }
                    $score = import_name_score_norm($k, $k2);
                    if ($score < $min) {
                        continue;
                    }
                    $sug = [['name' => $names[$k2]['name'], 'score' => round($score, 3)]];
                    $done[$k . '|' . $k2] = true;
                    $fa = array_keys($names[$k]['files']);
                    $fb = array_keys($names[$k2]['files']);
                    $crossFile = (count(array_unique(array_merge($fa, $fb))) > 1) && !array_intersect($fa, $fb);
                    if ($mode === 'files' && !$crossFile) {
                        continue;        // within-file pairs belong to the per-file pass
                    }
                    $n1 = $names[$k]['name'];
                    $n2 = $names[$k2]['name'];
                    $c1 = count($names[$k]['rows']);
                    $c2 = count($names[$k2]['rows']);
                    $q = import_q('possible-typo', '"' . $n1 . '" (' . $c1 . ' row' . ($c1 > 1 ? 's' : '') . ') and "' . $n2 . '" (' . $c2 . ' row' . ($c2 > 1 ? 's' : '') . ') look very similar. Is one a typing mistake?', [
                        'sheet' => $names[$k]['rows'][0]['sheet'], 'row' => null, 'blocking' => false, 'default' => 'keep',
                        'cells' => [import_source_ref($names[$k]['rows'][0]['sheet'], 'row ' . $names[$k]['rows'][0]['row']), import_source_ref($names[$k2]['rows'][0]['sheet'], 'row ' . $names[$k2]['rows'][0]['row'])],
                        'options' => [
                            ['key' => 'keep', 'label' => 'They are different — keep both as written'],
                            ['key' => 'use:' . $n1, 'label' => 'Use "' . $n1 . '" for both'],
                            ['key' => 'use:' . $n2, 'label' => 'Use "' . $n2 . '" for both'],
                        ],
                        'suggestion' => $sug[0],
                    ]);
                    $uidsBy = [];
                    foreach (array_merge($names[$k]['rows'], $names[$k2]['rows']) as $e) {
                        $uidsBy[$e['a']][] = $e['uid'];
                    }
                    import_cc_add_question($analyses, $q, $uidsBy);
                }
            }
        }
        // names that look like a slightly different spelling of an EXISTING name (suggestion only)
        if ($known && $mode === 'sheets') {
            $knownKeys = [];
            foreach ($known as $kn) {
                $knownKeys[import_norm_text($kn)] = true;
            }
            foreach ($names as $k => $info) {
                if (isset($knownKeys[$k])) {
                    continue;
                }
                $sug = import_suggest_names($info['name'], $known, ['min_score' => $min, 'max' => 1]);
                if (!$sug) {
                    continue;
                }
                $best = $sug[0]['name'];
                $q = import_q('possible-typo', '"' . $info['name'] . '" is not in the existing list, but "' . $best . '" is. Did you mean "' . $best . '"?', [
                    'sheet' => $info['rows'][0]['sheet'], 'row' => null, 'blocking' => false, 'default' => 'keep',
                    'cells' => [$info['rows'][0]['sheet'] . '!row ' . $info['rows'][0]['row']],
                    'options' => [
                        ['key' => 'keep', 'label' => 'No, "' . $info['name'] . '" is a new/different item'],
                        ['key' => 'use:' . $best, 'label' => 'Yes, use the existing "' . $best . '"'],
                    ],
                    'suggestion' => $sug[0], 'against' => 'existing',
                ]);
                $uidsBy = [];
                foreach ($info['rows'] as $e) {
                    $uidsBy[$e['a']][] = $e['uid'];
                }
                import_cc_add_question($analyses, $q, $uidsBy);
            }
        }
    }

    /** Checks inside ONE file. */
    function import_crosscheck(array &$analysis, array $opts = []): void
    {
        $analysis['conflicts'] = $analysis['conflicts'] ?? [];
        $wrap = [&$analysis];
        $entries = import_cc_entries($wrap, 'sheet');
        import_cc_conflicts($wrap, $entries, 'sheets');
        import_cc_case_prices($analysis, $opts);
        import_cc_typos($wrap, $entries, $opts, 'sheets');
        import_recompute_statuses($analysis);
    }

    /** Checks BETWEEN files of one upload batch. $analyses = list of import_analyze_file() results. */
    function import_crosscheck_files(array &$analyses, array $opts = []): void
    {
        foreach ($analyses as &$an) {
            if (!isset($an['conflicts'])) {
                $an['conflicts'] = [];
            }
        }
        unset($an);
        $entries = import_cc_entries($analyses, 'file');
        import_cc_conflicts($analyses, $entries, 'files');
        import_cc_typos($analyses, $entries, $opts, 'files');
        foreach ($analyses as &$an) {
            import_recompute_statuses($an);
        }
        unset($an);
    }
}
