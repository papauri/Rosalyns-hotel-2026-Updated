<?php
/**
 * Import engine - cross-checker. Only ever ADDS questions/warnings; it never changes a value.
 *
 *   import_crosscheck(&$analysis, $opts)         one file: same item on different sheets disagreeing
 *                                                 on price/unit, case-price look, possible typos
 *   import_crosscheck_files(&$analyses, $opts)   several files of one upload batch: same item in two
 *                                                 files disagreeing on price/unit, typos across files
 * Conflicts list BOTH values with their sheet!cell. There is never a winner: a person picks.
 * opts: case_price_min_peers (4: the smallest number of peers that counts as evidence), typo_min_score (0.85),
 *       known_names (existing names to suggest from; suggestions only).
 */

require_once __DIR__ . '/validator.php';
require_once __DIR__ . '/price-check.php';      // the same evidence rule as the price check (no fixed ratio)

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
                        'qty' => $row['computed']['qty_total']['value'] ?? null,      // the counted quantity the import uses (unknown = null)
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

    /** A group of prices for leave-one-out statistics: sorted prices, their logs, and the sorted deviations per centre (filled on demand). */
    function import_cc_group(array $prices): array
    {
        $p = array_map('floatval', $prices);
        sort($p);
        return ['p' => $p, 'l' => array_map('log', $p), 'dev' => []];
    }

    /** First index of $v in the sorted list $a (exact float match), or -1. */
    function import_cc_find(array $a, float $v): int
    {
        $lo = 0;
        $hi = count($a);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($a[$mid] < $v) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return ($lo < count($a) && $a[$lo] === $v) ? $lo : -1;
    }

    /** Median of the sorted list $a without the element at index $skip: the same arithmetic as import_median() on the shorter list. */
    function import_cc_loo_median(array $a, int $skip): ?float
    {
        $n = count($a) - 1;
        if ($n <= 0) {
            return null;
        }
        $mid = intdiv($n, 2);
        $at = function (int $k) use ($a, $skip): float {
            return (float)$a[$k < $skip ? $k : $k + 1];
        };
        return $n % 2 ? $at($mid) : ($at($mid - 1) + $at($mid)) / 2;
    }

    /**
     * [median, robust spread] of a group's prices WITHOUT one member that has price $price - exactly what import_median($peers) and
     * import_pc_spread(logs of $peers) give for the peer list, without building it. Falls back to that direct computation if the price is not found.
     */
    function import_cc_loo_stats(array &$g, float $price): array
    {
        $r = import_cc_find($g['p'], $price);
        if ($r >= 0) {
            $med = import_cc_loo_median($g['p'], $r);
            $mp = import_cc_loo_median($g['l'], $r);
            if ($med !== null && $mp !== null) {
                $key = sprintf('%.17g', $mp);
                if (!isset($g['dev'][$key])) {
                    $d = array_map(function ($x) use ($mp) {
                        return abs($x - $mp);
                    }, $g['l']);
                    sort($d);
                    $g['dev'][$key] = $d;
                }
                $dr = abs($g['l'][$r] - $mp);
                $q = import_cc_find($g['dev'][$key], $dr);
                if ($q >= 0) {
                    return [$med, 1.4826 * (float)import_cc_loo_median($g['dev'][$key], $q)];
                }
            }
        }
        $peers = $g['p'];
        $ix = $r >= 0 ? $r : array_search($price, $peers, true);
        if ($ix !== false && $ix !== null) {
            unset($peers[$ix]);
        }
        return [import_median($peers), import_pc_spread(array_map('log', import_pc_positive($peers)))];
    }

    /**
     * The case-price question text: the row's unit as written, the counted quantity and what the line is worth as written, then what each
     * answer does. Everything comes from the row's own cells and the peers' median; the pack size in the example is only an illustration
     * (the price divided to about the usual one), never applied.
     */
    function import_cc_case_text(array $row, float $price, float $med, string $where, string $src): string
    {
        $u = trim((string)($row['fields']['unit']['value'] ?? ''));
        $per = $u !== '' ? 'per ' . $u : 'per unit';
        $qty = $row['computed']['qty_total']['value'] ?? null;
        $t = 'Row ' . $row['row'] . ' "' . $row['fields']['item_name']['value'] . '": the unit price is ' . import_fmt_money($price) . ' ' . $per . ' (' . $src . '), about '
            . round($price / $med, 1) . ' times the usual ' . import_fmt_money($med) . ' in its ' . $where . '.';
        if ($qty !== null && (float)$qty > 0) {
            $t .= ' The sheet counts ' . import_cc_qty((float)$qty) . ($u !== '' ? ' ' . $u : '') . ', so as written this line is worth ' . import_fmt_money(round((float)$qty * $price, 2)) . '.';
        } elseif ($qty !== null) {
            $t .= ' The sheet counts 0, so this answer only sets the cost of one ' . ($u !== '' ? $u : 'unit') . ' for later stock.';
        }
        return $t . ' Is that the price of one unit, or of a whole case/pack? The quantity stays in the sheet\'s unit' . ($u !== '' ? ' (' . $u . ')' : '')
            . ', so choose "whole pack" only if the quantity was counted in single units, not in packs.';
    }

    /** Options of the case-price question: the keys never change (per-unit / per-case); the labels say what each answer does with this row's numbers. */
    function import_cc_case_options(array $row, float $price, float $med): array
    {
        $u = trim((string)($row['fields']['unit']['value'] ?? ''));
        $one = $u !== '' ? $u : 'unit';
        $qty = $row['computed']['qty_total']['value'] ?? null;
        $hasQty = $qty !== null && (float)$qty > 0;
        $unitLbl = 'One ' . $one . ' costs ' . import_fmt_money($price) . ' (import as written)';
        if ($hasQty) {
            $unitLbl .= ': ' . import_cc_qty((float)$qty) . ' ' . $one . ' counted at ' . import_fmt_money($price) . ' = ' . import_fmt_money(round((float)$qty * $price, 2));
        }
        $ex = max(2, (int)round($price / $med));
        $each = round($price / $ex, 4);
        $caseLbl = 'It is the price of a whole case/pack: I will type how many ' . $one . ' one case/pack holds (for example ' . $ex . ' -> ' . import_fmt_money($each) . ' per ' . $one;
        if ($hasQty) {
            $caseLbl .= ', ' . import_cc_qty((float)$qty) . ' ' . $one . ' = ' . import_fmt_money(round((float)$qty * $each, 2));
        }
        return [
            ['key' => 'per-unit', 'label' => $unitLbl],
            ['key' => 'per-case', 'label' => $caseLbl . ')'],
        ];
    }

    /**
     * "Price looks like a case price" questions: a price far ABOVE the others in its category (or the sheet), judged on a log scale from
     * the peers' own spread - the same rule as the price check (price-check.php): ask when ln(price / median) is more than
     * k x max(robust spread of the peers' logs, floor). No fixed ratio: a sheet of widely different prices tolerates more.
     */
    function import_cc_case_prices(array &$analysis, array $opts): void
    {
        $pcp = import_pc_params($opts);
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
            // The peers of a row are its category (or the whole sheet) WITHOUT the row itself. Each group is sorted once and the median / spread
            // without one member are read from it (import_cc_loo_*), instead of rebuilding and sorting the peer list for every row (that was O(n^2 log n)).
            $sheetGroup = import_cc_group(array_values($priced));
            $catGroups = [];
            foreach ($priced as $i => $price) {
                $c = $sh['rows'][$i]['category'];
                if ($c !== null) {
                    $catGroups['c:' . $c][] = $price;
                }
            }
            foreach ($catGroups as $ck => $list) {
                $catGroups[$ck] = count($list) - 1 >= $minPeers ? import_cc_group($list) : null;
            }
            foreach ($priced as $i => $price) {
                $row = $sh['rows'][$i];
                $cat = $row['category'];
                $scope = 'sheet';
                $grp = &$sheetGroup;
                if ($cat !== null && $catGroups['c:' . $cat] !== null) {
                    $scope = 'category';
                    $grp = &$catGroups['c:' . $cat];
                }
                $st = import_cc_loo_stats($grp, $price);
                unset($grp);
                $med = $st[0];
                if ($med === null || $med <= 0) {
                    continue;
                }
                $sp = $st[1];
                // A case holds at least two units (structural fact, not a hotel setting): a price under twice the usual one cannot be a case price.
                if ($price < 2 * $med || log($price / $med) <= $pcp['k'] * max($sp, $pcp['spread_floor'])) {
                    continue;
                }
                $src = $row['fields']['unit_price']['source'];
                $q = import_q('case-price', import_cc_case_text($row, $price, $med, $scope === 'category' ? 'category "' . $cat . '"' : 'sheet', $src), [
                    'sheet' => $sh['name'], 'row' => $row['row'], 'cells' => [$src],
                    'options' => import_cc_case_options($row, $price, $med),
                    'case_ratio' => round($price / $med, 2),
                ]);
                $q['row_uids'] = [$row['uid']];
                import_register_question($analysis, $q);
            }
        }
    }

    /**
     * Two normalised names that differ in exactly ONE word, where that word pair is not a near-spelling of each other, are different
     * products, not a typing mistake ("essence almond" / "essence lemon", "black tea" / "black tie", "sweet red" / "sweet rose").
     * Near-spelling = edit distance at most max(1, 20% of the longer word) OR at least 80% similar letters ("heinken" / "heineken",
     * "windowhoek" / "windhoek", "stoot" / "stout" stay a question). Generic text rule, no hotel words.
     */
    function import_cc_distinct_one_word(string $k1, string $k2): bool
    {
        $w1 = explode(' ', $k1);
        $w2 = explode(' ', $k2);
        if (count($w1) !== count($w2)) {
            return false;
        }
        $diff = [];
        foreach ($w1 as $i => $w) {
            if ($w !== $w2[$i]) {
                $diff[] = [$w, $w2[$i]];
            }
        }
        if (count($diff) !== 1) {
            return false;
        }
        [$a, $b] = $diff[0];
        $long = max(strlen($a), strlen($b));
        $dist = levenshtein($a, $b);
        similar_text($a, $b, $pct);
        return $dist > max(1, (int)floor(0.2 * $long)) && $pct < 79.9999;
    }

    /** "row 9: 4 bottle, 660 each" for the possible-typo text (what the person compares before merging). */
    function import_cc_row_desc(array $e): string
    {
        $u = trim((string)($e['unit'] ?? ''));
        $q = ($e['qty'] ?? null) !== null ? import_cc_qty((float)$e['qty']) . ($u !== '' ? ' ' . $u : '') : 'quantity unknown';
        $p = (($e['price'] ?? null) !== null && (float)$e['price'] > 0) ? import_fmt_money($e['price']) . ' each' : 'no unit cost';
        return 'row ' . $e['row'] . ': ' . $q . ', ' . $p;
    }

    /** The rows of one name, described (at most 3, then "and N more rows"). */
    function import_cc_rows_desc(array $rows): string
    {
        $parts = array_map('import_cc_row_desc', array_slice($rows, 0, 3));
        $more = count($rows) - 3;
        return implode('; ', $parts) . ($more > 0 ? '; and ' . $more . ' more row' . ($more > 1 ? 's' : '') : '');
    }

    /** A quantity as text: up to 3 decimals, no trailing zeros (4, 28.3, 1,250). */
    function import_cc_qty(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ','), '0'), '.');
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
                    if ($score < $min || import_cc_distinct_one_word($k, $k2)) {
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
                    $rowsAll = array_merge($names[$k]['rows'], $names[$k2]['rows']);
                    $sheetsOf = array_unique(array_map(function ($e) {
                        return $e['a'] . '|' . $e['s'];
                    }, $rowsAll));
                    // what "Use X for both" really does on each path (resolve.php): one sheet = one item, quantities added;
                    // two sheets = one name, then the "same item on two sheets" question (add, or first sheet only); two files = one name only
                    $tail = '';
                    if ($mode === 'files') {
                        $effect = '"Use ... for both" only gives them the same name: each file is imported on its own, with its own quantity.';
                    } elseif (count($sheetsOf) > 1) {
                        $effect = '"Use ... for both" gives them one name; because they are on different sheets you are then asked whether to add the quantities together or use the first sheet only.';
                    } else {
                        $units = array_unique(array_map(function ($e) {
                            return import_unit_key((string)($e['unit'] ?? ''));
                        }, $rowsAll));
                        $qs = array_column($rowsAll, 'qty');
                        $sumTxt = '';
                        if (count($qs) === count($rowsAll) && !in_array(null, $qs, true) && count($units) === 1) {
                            $u0 = trim((string)($rowsAll[0]['unit'] ?? ''));
                            $sumTxt = ' (' . implode(' + ', array_map(function ($v) {
                                return import_cc_qty((float)$v);
                            }, $qs)) . ' = ' . import_cc_qty((float)array_sum($qs)) . ($u0 !== '' ? ' ' . $u0 : '') . ')';
                        }
                        $effect = '"Use ... for both" merges them into ONE item and ADDS the quantities' . $sumTxt . '. If they are different products, keep both.';
                        $tail = ' (one item, quantities added)';
                        $unitsSet = array_values(array_filter(array_unique(array_map(function ($e) {
                            return trim((string)($e['unit'] ?? ''));
                        }, $rowsAll)), 'strlen'));
                        if (count(array_filter(array_keys(array_flip(array_map('import_unit_key', $unitsSet))), 'strlen')) > 1) {
                            $effect = 'Their units differ (' . implode(' / ', $unitsSet) . '), so their counts cannot be added: keep both, or fix the unit in the sheet first.';
                            $tail = '';
                        }
                    }
                    $q = import_q('possible-typo', '"' . $n1 . '" (' . import_cc_rows_desc($names[$k]['rows']) . ') and "' . $n2 . '" (' . import_cc_rows_desc($names[$k2]['rows'])
                        . ') look very similar. Is one a typing mistake? ' . $effect, [
                        'sheet' => $names[$k]['rows'][0]['sheet'], 'row' => null, 'blocking' => false, 'default' => 'keep',
                        'cells' => [import_source_ref($names[$k]['rows'][0]['sheet'], 'row ' . $names[$k]['rows'][0]['row']), import_source_ref($names[$k2]['rows'][0]['sheet'], 'row ' . $names[$k2]['rows'][0]['row'])],
                        'options' => [
                            ['key' => 'keep', 'label' => 'They are different — keep both as written'],
                            ['key' => 'use:' . $n1, 'label' => 'Use "' . $n1 . '" for both' . $tail],
                            ['key' => 'use:' . $n2, 'label' => 'Use "' . $n2 . '" for both' . $tail],
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
                // one whole word differs (Black tea / Black tie): a different product, not a spelling of the existing one - the next closest is tried
                $sug = array_values(array_filter(import_suggest_names($info['name'], $known, ['min_score' => $min, 'max' => 3]), function ($x) use ($k) {
                    return !import_cc_distinct_one_word($k, import_norm_text($x['name']));
                }));
                if (!$sug) {
                    continue;
                }
                $best = $sug[0]['name'];
                $q = import_q('possible-typo', '"' . $info['name'] . '" is not in the existing list, but "' . $best . '" is. Did you mean "' . $best . '"? In the sheet: '
                    . import_cc_rows_desc($info['rows']) . '. "Yes" puts ' . (count($info['rows']) > 1 ? 'these rows' : 'this row') . ' on the existing "' . $best . '" instead of creating a new item. If it is a different product, answer no.', [
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
