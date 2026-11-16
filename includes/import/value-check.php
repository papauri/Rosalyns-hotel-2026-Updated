<?php
/**
 * Import: the sheet's own "value" column (field total_cost, e.g. "Stock Value") checked against Quantity x Unit Cost. Pure: no database.
 *
 * The value column is derived and is NEVER imported (validator: derived-ignored). Managers still read it, and a sheet whose value
 * column was not recalculated after quantities or costs changed looks as if stock or money went missing after the import. This file
 * only REPORTS that: it never blocks, asks, or changes anything that is imported.
 *
 * Everything comes from the cells the analysis already holds (no second read of the file):
 *   quantity  = the row's computed qty_total (the same sum of balance cells the import uses; unknown = not compared)
 *   unit cost = the row's own unit-price cell, divided by the pack size only when THIS row's per-case question was answered
 *               "per case" with a pack size (the pack belongs to that price cell, A29) - exactly how the import works out the cost
 *   value     = the cached number in the value column (a formula with no stored result, or a blank cell, is not compared: blank is not 0)
 * Rows are compared whatever their answers (skipped or not), so the figures describe the sheet and do not move while answering.
 * A row disagrees when |value - qty x cost| > max(0.5, 0.005 x |qty x cost|) (rounding never trips it).
 * A "Total Cost" column that is really Purchase Qty x Unit Price (a tracker's buying list) is recognised and not reported.
 *
 *   import_vc_collect($analysis)          before resolving (the rows are let go while resolving): slim rows of the sheets that qualify
 *   import_vc_finish($collected, $qs)      after resolving ($qs = the analysis questions with their answers): the comparison
 *   import_vc_overview($fin)               counts and sums only (the screens' summary; no row lists)
 *   import_vc_rows($fin, $page, $size)     one page of the disagreeing rows (size 0 = all)
 *   import_vc_workbook($fin, $meta)        the stock-check workbook (Summary / "<header> wrong" / Missing prices), values only
 */

require_once __DIR__ . '/text-util.php';

if (!function_exists('import_vc_collect')) {

    function import_vc_tolerance(float $calc): float
    {
        return max(0.5, 0.005 * abs($calc));
    }

    /** Column letter of a source like "Sheet!J12" ('' when it cannot be read). */
    function import_vc_src_letter(string $src): string
    {
        return preg_match('/!\$?([A-Z]{1,3})\$?\d+$/', $src, $m) ? $m[1] : '';
    }

    /**
     * Slim rows of every sheet that has a mapped quantity column AND a mapped unit-price column (the value column is optional: without it
     * nothing is compared). Only rows with a value cell are kept. Row = ['', row, name, category,
     * unit, qty|null, price|null, price source, value (one value column: number|null; several: [letter => value]), purchase qty|null,
     * [case-price question ids]].
     */
    function import_vc_collect(array $an): array
    {
        $caseQ = [];
        foreach ((array)($an['questions'] ?? []) as $q) {
            if (($q['type'] ?? '') === 'case-price') {
                $caseQ[$q['id']] = true;
            }
        }
        $out = [];
        foreach ((array)($an['sheets'] ?? []) as $sh) {
            if (($sh['triage']['action'] ?? '') !== 'import' || empty($sh['rows'])) {
                continue;
            }
            $qtyCols = [];
            $qtyHeads = [];
            $priceCol = '';
            $priceHead = '';
            $valueCols = [];
            $pqCol = '';
            foreach ((array)($sh['columns'] ?? []) as $c) {
                if (($c['state'] ?? '') !== 'mapped') {
                    continue;
                }
                $f = (string)($c['field'] ?? '');
                if (in_array($f, ['qty_primary', 'qty_secondary', 'qty_single'], true)) {
                    $qtyCols[] = (string)$c['letter'];
                    $qtyHeads[] = (string)$c['header'];
                } elseif ($f === 'unit_price' && $priceCol === '') {
                    $priceCol = (string)$c['letter'];
                    $priceHead = (string)$c['header'];
                } elseif ($f === 'total_cost') {
                    $valueCols[(string)$c['letter']] = (string)$c['header'];
                } elseif ($f === 'purchase_qty' && $pqCol === '') {
                    $pqCol = (string)$c['letter'];
                }
            }
            if (!$qtyCols || $priceCol === '') {
                continue;
            }
            $rows = [];
            $single = count($valueCols) === 1;          // memory: one value column (the usual case) = a plain number per row, not an array
            foreach ($sh['rows'] as $r) {
                $f = $r['fields'];
                $qty = isset($r['computed']['qty_total']) && is_numeric($r['computed']['qty_total']['value']) ? (float)$r['computed']['qty_total']['value'] : null;
                $price = isset($f['unit_price']) && is_numeric($f['unit_price']['value']) ? (float)$f['unit_price']['value'] : null;
                $vals = [];
                $pq = null;
                foreach ((array)$r['ignored'] as $ig) {
                    if (!empty($ig['unknown']) || $ig['value'] === null) {
                        continue;
                    }
                    if ($ig['field'] === 'total_cost' || $ig['field'] === 'purchase_qty') {
                        $p = import_parse_number($ig['value']);
                        if ($p['status'] !== 'ok') {
                            continue;
                        }
                        if ($ig['field'] === 'purchase_qty') {
                            if (import_vc_src_letter((string)$ig['source']) === $pqCol) {
                                $pq = (float)$p['value'];
                            }
                        } else {
                            $l = import_vc_src_letter((string)$ig['source']);
                            if (isset($valueCols[$l])) {
                                $vals[$l] = (float)$p['value'];
                            }
                        }
                    }
                }
                $cq = [];
                foreach ((array)$r['question_ids'] as $qid) {
                    if (isset($caseQ[$qid])) {
                        $cq[] = $qid;
                    }
                }
                if (!$vals) {
                    continue;           // nothing to compare: not kept (a 20,000-row sheet keeps only what it reports on)
                }
                if ($single) {
                    $vals = $vals ? reset($vals) : null;
                }
                $rows[] = ['', (int)$r['row'], (string)$f['item_name']['value'], (string)($r['category'] ?? ''),
                    isset($f['unit']) ? (string)$f['unit']['value'] : '', $qty, $price, isset($f['unit_price']) ? (string)$f['unit_price']['source'] : '', $vals, $pq, $cq];
            }
            $out[] = ['name' => (string)$sh['name'], 'index' => (int)($sh['index'] ?? 0), 'header_row' => (int)($sh['header_row'] ?? 0),
                'qty_cols' => $qtyCols, 'qty_heads' => $qtyHeads, 'price_col' => $priceCol, 'price_head' => $priceHead,
                'value_cols' => $valueCols, 'pq_col' => $pqCol, 'n_rows' => count($sh['rows']), 'rows' => $rows];
        }
        return ['sheets' => $out];
    }

    /**
     * "Missing prices": the items whose stock is left out of the opening count because there is NO cost - exactly the import's own rule
     * (import_cont_not_counted_reason() = 'no_cost', continue.php), so the list always equals the server's "no cost" count. An existing item that
     * already has a cost in stock is not listed (it is counted at that cost); skipped items are not listed. $items = the resolved items.
     */
    function import_vc_missing(array $items): array
    {
        $out = [];
        if (!function_exists('import_cont_not_counted_reason')) {
            return $out;
        }
        foreach ($items as $it) {
            if (import_cont_not_counted_reason($it) !== 'no_cost') {
                continue;
            }
            $ref = (string)(((array)($it['rows'] ?? []))[0] ?? '');
            $row = preg_match('/(\d+)$/', $ref, $m) ? (int)$m[1] : 0;
            $unit = $it['unit'] ?? ($it['unit_raw'] ?? '');
            $out[] = ['sheet' => (string)($it['sheet'] ?? ''), 'row' => $row, 'name' => (string)$it['name'], 'category' => (string)($it['category'] ?? ''),
                'unit' => (string)$unit, 'qty' => (float)$it['balance'], 'cell' => (string)($it['price_source'] ?? '')];
        }
        return $out;
    }

    /**
     * "What it most likely means" for one disagreeing row, worked out from its own cells only (shown on Step 3 and in the workbook).
     * value / unit cost is the quantity the sheet's value was worked out for. It "fits" when a quantity to 0.1 times the cost gives the value
     * within 0.5 (currency rounding) and the 0.1 step is worth at least 1 (with a tiny cost every value would fit, which proves nothing).
     * Returns [code, text]: code qty-changed | no-qty (counts 0, still valued) | no-value (counted, valued at 0) | no-fit. Never says loss or theft (a gap is not proof of loss).
     */
    function import_vc_why(?float $qty, float $cost, float $value, string $unit): array
    {
        $u = trim($unit) !== '' ? ' ' . trim($unit) : '';
        $fq = function (float $v): string {
            return rtrim(rtrim(number_format($v, 3, '.', ','), '0'), '.');
        };
        $fits = false;
        $iq = null;
        if (abs($cost) > 0.00005 && 0.1 * abs($cost) >= 1) {
            $iq = round($value / $cost, 1);
            $fits = $iq >= 0 && abs($iq * $cost - $value) <= 0.5;
        }
        $zero = $qty !== null && abs($qty) < 1e-9;
        if (!$zero && $qty !== null && abs($value) <= 0.5) {
            return ['no-value', 'The sheet counts ' . $fq($qty) . $u . ' but values them at 0. The quantity was entered or changed without updating the value.'];
        }
        if ($zero) {
            $fits = $fits && $iq > 0;
            return ['no-qty', 'The sheet counts 0 but still values ' . ($fits ? $fq($iq) . $u : 'stock') . '. If ' . ($fits ? 'they were' : 'it was')
                . ' sold or used, the value was not updated; if not, ' . ($fits ? 'they are' : 'it is') . ' missing from the count.'];
        }
        if ($fits && $qty !== null) {
            $d = round($qty - $iq, 3);
            return ['qty-changed', 'The value was worked out for ' . $fq($iq) . $u . '; the count now says ' . $fq($qty) . ' (' . $fq(abs($d)) . ($d > 0 ? ' more' : ' fewer')
                . '). The quantity was changed without updating the value.'];
        }
        return ['no-fit', 'The value does not fit a whole quantity at this unit cost: the unit cost was changed, or there is a typing mistake.'];
    }

    /** The comparison. $questions = the analysis questions after the answers were applied (answer / answer_detail); $items = the resolved items (missing prices). */
    function import_vc_finish(array $col, array $questions, array $items = []): array
    {
        $pack = [];
        $packAt = [];           // sheet + row => pack: a case-price question added at resolve time (case-signals.php) is not in the collected ids
        foreach ($questions as $q) {
            if (($q['type'] ?? '') === 'case-price' && ($q['answer'] ?? null) === 'per-case') {
                $p = (int)($q['answer_detail']['pack_size'] ?? 0);
                if ($p >= 2) {
                    $pack[$q['id']] = $p;
                    if (isset($q['sheet'], $q['row'])) {
                        $packAt[(string)$q['sheet'] . "\x1f" . (int)$q['row']] = $p;
                    }
                }
            }
        }
        $groups = [];
        $all = [];
        $missing = import_vc_missing($items);
        $sheetsOut = [];
        foreach ((array)($col['sheets'] ?? []) as $si => $sh) {
            $sheetsOut[] = ['name' => $sh['name'], 'header_row' => $sh['header_row'], 'qty_cols' => $sh['qty_cols'], 'qty_heads' => $sh['qty_heads'],
                'price_col' => $sh['price_col'], 'price_head' => $sh['price_head'], 'value_cols' => $sh['value_cols']];
            $valOf = function (array $r, string $letter) {
                return is_array($r[8]) ? ($r[8][$letter] ?? null) : $r[8];
            };
            $packed = function (array $r) use ($pack, $packAt, $sh) {
                foreach ($r[10] as $qid) {
                    if (isset($pack[$qid])) {
                        return $pack[$qid];
                    }
                }
                return $packAt[(string)$sh['name'] . "\x1f" . (int)$r[1]] ?? null;
            };
            foreach ($sh['value_cols'] as $letter => $header) {
                // a "Total Cost" that is Purchase Qty x Unit Price (a buying list) is not a stock value: recognised, not reported
                if ($sh['pq_col'] !== '') {
                    $pqKnown = false;
                    foreach ($sh['rows'] as $r) {
                        if ($r[9] !== null) {
                            $pqKnown = true;
                            break;
                        }
                    }
                    if (!$pqKnown) {
                        continue;       // a "Total Cost" next to a Purchase Qty whose cells are all blank or formulas with no stored result: cannot tell, not reported
                    }
                    $n = 0;
                    $agreeP = 0;
                    $agreeQ = 0;
                    foreach ($sh['rows'] as $r) {
                        $v = $valOf($r, $letter);
                        if ($r[6] === null || $r[9] === null || $v === null) {
                            continue;
                        }
                        $pk = $packed($r);
                        $cost = $pk ? $r[6] / $pk : $r[6];
                        $n++;
                        $cp = $r[9] * $cost;
                        if (abs($v - $cp) <= import_vc_tolerance($cp)) {
                            $agreeP++;
                        }
                        if ($r[5] !== null) {
                            $cq = $r[5] * $cost;
                            if (abs($v - $cq) <= import_vc_tolerance($cq)) {
                                $agreeQ++;
                            }
                        }
                    }
                    if ($n > 0 && $agreeP > $agreeQ && $agreeP * 2 >= $n) {
                        continue;
                    }
                }
                $g = ['sheet' => $sh['name'], 'header' => $header, 'letter' => $letter, 'compared' => 0, 'differ' => 0, 'more_n' => 0, 'more_sum' => 0.0,
                    'less_n' => 0, 'less_sum' => 0.0, 'no_qty_n' => 0, 'calc_total' => 0.0, 'column_total' => 0.0, 'packs' => 0,
                    'why_qty-changed' => 0, 'why_no-qty' => 0, 'why_no-value' => 0, 'why_no-fit' => 0];
                foreach ($sh['rows'] as $ord => $r) {
                    $v = $valOf($r, $letter);
                    if ($r[5] === null || $r[6] === null || $v === null) {
                        continue;
                    }
                    $pk = $packed($r);
                    $cost = $pk ? $r[6] / $pk : $r[6];
                    $calc = $r[5] * $cost;
                    $diff = $v - $calc;
                    $g['compared']++;
                    $g['calc_total'] += $calc;
                    $g['column_total'] += $v;
                    if ($pk) {
                        $g['packs']++;
                    }
                    if (abs($diff) <= import_vc_tolerance($calc)) {
                        continue;
                    }
                    $g['differ']++;
                    $noQty = abs($r[5]) < 1e-9;
                    if ($diff > 0) {
                        $g['more_n']++;
                        $g['more_sum'] += $diff;
                    } else {
                        $g['less_n']++;
                        $g['less_sum'] += -$diff;
                    }
                    if ($noQty) {
                        $g['no_qty_n']++;
                    }
                    $why = import_vc_why($r[5] === null ? null : (float)$r[5], (float)$cost, (float)$v, (string)$r[4]);
                    $g['why_' . $why[0]] = ($g['why_' . $why[0]] ?? 0) + 1;
                    $all[] = ['sheet' => $sh['name'], 'si' => $si, 'row' => $r[1], 'name' => $r[2], 'category' => $r[3], 'unit' => $r[4], 'qty' => $r[5],
                        'why_code' => $why[0], 'why' => $why[1],
                        'price' => $r[6], 'pack' => $pk, 'cost' => $cost, 'calc' => round($calc, 2), 'value' => $v, 'diff' => round($diff, 2), 'abs' => abs($diff),
                        'kind' => $noQty ? 'no-qty' : ($diff > 0 ? 'more' : 'less'), 'header' => $header,
                        'implied' => abs($cost) > 0.00005 ? round($v / $cost, 2) : null,
                        'cell' => import_source_ref($sh['name'], $letter . $r[1]), 'price_cell' => $r[7]];
                }
                foreach (['more_sum', 'less_sum', 'calc_total', 'column_total'] as $k) {
                    $g[$k] = round($g[$k], 2);
                }
                if ($g['compared'] > 0) {
                    $groups[] = $g;
                }
            }
        }
        usort($all, function ($a, $b) {
            return [$b['abs'], $a['si'], $a['row']] <=> [$a['abs'], $b['si'], $b['row']];
        });
        foreach ($all as &$x) {
            unset($x['abs'], $x['si']);
        }
        unset($x);
        $differ = array_sum(array_column($groups, 'differ'));
        $rowsN = 0;
        foreach ((array)($col['sheets'] ?? []) as $sh) {
            $rowsN += (int)($sh['n_rows'] ?? count($sh['rows']));
        }
        return ['groups' => $groups, 'rows' => $all, 'missing' => $missing, 'sheets' => $sheetsOut, 'rows_n' => $rowsN,
            'differ' => (int)$differ, 'missing_n' => count($missing), 'show' => $differ > 0 || count($missing) > 0];
    }

    /** Counts and sums only (what every summary reply carries). */
    function import_vc_overview(array $fin): array
    {
        $groups = array_values(array_filter($fin['groups'], function ($g) {
            return $g['differ'] > 0;
        }));
        $sheets = [];
        foreach ($fin['sheets'] as $s) {
            $sheets[$s['name']] = ['qty_heads' => $s['qty_heads'], 'price_head' => $s['price_head']];
        }
        return ['show' => (bool)$fin['show'], 'differ' => (int)$fin['differ'], 'missing_n' => (int)$fin['missing_n'], 'groups' => $groups, 'sheets' => $sheets,
            'missing_sample' => array_slice(array_column($fin['missing'], 'name'), 0, 10)];
    }

    /** One page of the disagreeing rows (sorted by the largest difference first). $size 0 = every row. */
    function import_vc_rows(array $fin, int $page, int $size): array
    {
        $total = count($fin['rows']);
        $size = in_array($size, [10, 25, 50, 100, 0], true) ? $size : 10;
        $pages = $size === 0 ? 1 : max(1, (int)ceil($total / $size));
        $page = min(max(1, $page), $pages);
        $rows = $size === 0 ? $fin['rows'] : array_slice($fin['rows'], ($page - 1) * $size, $size);
        return ['total' => $total, 'page' => $page, 'pages' => $pages, 'size' => $size, 'rows' => array_values($rows)];
    }

    /** "=D2*I2" (or "=(D2+E2)*I2") for the first data row of a sheet, with the sheet's own column letters. */
    function import_vc_formula_hint(array $s): string
    {
        $r = max(1, (int)$s['header_row'] + 1);
        $q = array_map(function ($l) use ($r) {
            return $l . $r;
        }, $s['qty_cols']);
        $qt = count($q) > 1 ? '(' . implode('+', $q) . ')' : $q[0];
        return '=' . $qt . '*' . $s['price_col'] . $r;
    }

    /**
     * The stock-check workbook. $meta: file (original name), date (YYYY-MM-DD), currency, committed (bool), system_value (float|null),
     * count_ref (string|null), valued_at (string). Returns ['ok', 'name', 'body'] (values only, no formulas).
     */
    function import_vc_workbook(array $fin, array $meta): array
    {
        require_once __DIR__ . '/export-xlsx.php';
        $file = (string)($meta['file'] ?? 'stock list');
        $date = (string)($meta['date'] ?? date('Y-m-d'));
        $cur = trim((string)($meta['currency'] ?? ''));
        $inCur = $cur !== '' ? ' (' . $cur . ')' : '';
        $base = trim((string)pathinfo($file, PATHINFO_FILENAME));
        $base = $base !== '' ? $base : 'stock list';
        $multi = count(array_unique(array_merge(array_column($fin['sheets'], 'name'), array_column($fin['missing'], 'sheet')))) > 1;
        $heads = array_values(array_unique(array_column($fin['groups'], 'header')));
        $valueHead = count($heads) === 1 ? $heads[0] : 'Value column';
        $priceHeads = array_values(array_unique(array_filter(array_column($fin['sheets'], 'price_head'))));
        $priceHead = count($priceHeads) === 1 ? $priceHeads[0] : 'Unit cost';
        $qtyHead = count($fin['sheets']) === 1 ? implode(' + ', $fin['sheets'][0]['qty_heads']) : 'Quantity';

        $w = new ImportXlsxWriter(['title' => 'Stock check of ' . $file, 'creator' => 'Import']);
        $h = $w->style(['font' => ['b' => true, 'sz' => 10, 'color' => 'FFFFFFFF'], 'fill' => 'FF1F3864', 'border' => 'FF999999', 'v' => 'center', 'wrap' => true]);
        $t = $w->style(['font' => ['b' => true, 'sz' => 14]]);
        $tx = $w->style(['border' => 'FFDDDDDD', 'v' => 'top', 'wrap' => true]);
        $nm = $w->style(['border' => 'FFDDDDDD', 'num' => '#,##0.00', 'v' => 'top']);
        $qn = $w->style(['border' => 'FFDDDDDD', 'num' => '#,##0.##', 'v' => 'top']);
        $red = $w->style(['border' => 'FFDDDDDD', 'num' => '#,##0.00', 'font' => ['b' => true, 'color' => 'FFC00000'], 'v' => 'top']);
        $grn = $w->style(['border' => 'FFDDDDDD', 'num' => '#,##0.00', 'font' => ['color' => 'FF2E7D32'], 'v' => 'top']);
        $fill = $w->style(['border' => 'FF999999', 'fill' => 'FFFFF2CC', 'v' => 'top']);
        $s = function ($v, $st) {
            return ['str' => (string)$v, 's' => $st];
        };
        $n = function ($v, $st) {
            $x = ImportXlsxWriter::numText(is_int($v) ? $v : round((float)$v, 6));
            return $x === null ? ['str' => '', 's' => $st] : ['num' => $x, 's' => $st];
        };

        $compared = array_sum(array_column($fin['groups'], 'compared'));
        $calcT = round(array_sum(array_column($fin['groups'], 'calc_total')), 2);
        $colT = round(array_sum(array_column($fin['groups'], 'column_total')), 2);
        $moreN = array_sum(array_column($fin['groups'], 'more_n'));
        $lessN = array_sum(array_column($fin['groups'], 'less_n'));
        $moreS = round(array_sum(array_column($fin['groups'], 'more_sum')), 2);
        $lessS = round(array_sum(array_column($fin['groups'], 'less_sum')), 2);

        // ---- Summary ----
        $si = $w->addSheet('Summary', ['cols' => [52, 22], 'gridlines' => false]);
        $w->row($si, 1, [1 => $s('Stock sheet check (' . $file . ')', $t)], 22.0);
        $lines = [];
        if (!empty($meta['committed'])) {
            if (isset($meta['system_value']) && $meta['system_value'] !== null) {
                $at = trim((string)($meta['valued_at'] ?? ''));
                $lines[] = ['System stock value' . ($at !== '' ? ' at ' . $at : ' now') . ' (all stock, quantity x cost per unit)' . $inCur, (float)$meta['system_value']];
            }
            $lines[] = ['Opening count from this import', (string)($meta['count_ref'] ?? '') !== '' ? (string)$meta['count_ref'] : 'none'];
        }
        $lines[] = ['Item rows read from the sheet', (int)($fin['rows_n'] ?? 0)];
        $lines[] = ['Rows compared (quantity, unit cost and "' . $valueHead . '" all filled in)', (int)$compared];
        $lines[] = ['Quantity x Unit Cost, rows compared' . $inCur, $calcT];
        $lines[] = ['Total of the sheet\'s "' . $valueHead . '" column, same rows' . $inCur, $colT];
        $lines[] = ['Rows where "' . $valueHead . '" is wrong', (int)$fin['differ']];
        $lines[] = ['  of which it shows MORE than Quantity x Unit Cost (rows)', (int)$moreN];
        $lines[] = ['  ... by' . $inCur, $moreS];
        $lines[] = ['  of which it shows LESS than Quantity x Unit Cost (rows)', (int)$lessN];
        $lines[] = ['  ... by' . $inCur, $lessS];
        $lines[] = ['Items with stock but no unit cost (not in the opening count)', (int)$fin['missing_n']];
        $rn = 3;
        foreach ($lines as [$label, $val]) {
            $w->row($si, $rn++, [1 => $s($label, $tx), 2 => is_string($val) ? $s($val, $tx) : $n($val, is_int($val) ? $qn : $nm)]);
        }
        $rn++;
        $wQ = array_sum(array_column($fin['groups'], 'why_qty-changed'));
        $wZ = array_sum(array_column($fin['groups'], 'why_no-qty'));
        $wQ += array_sum(array_column($fin['groups'], 'why_no-value'));       // valued at 0 = worked out for a quantity of 0
        $wN = array_sum(array_column($fin['groups'], 'why_no-fit'));
        $notes = ['The import uses Quantity x Unit Cost for every row. The sheet\'s "' . $valueHead . '" column is never imported.'];
        if ($wQ + $wZ > 0) {
            $notes[] = ($wQ + $wZ) . ' of ' . (int)$fin['differ'] . ' rows: the value fits a different quantity at the same unit cost' . ($wZ > 0 ? ' (' . $wZ . ' of them now count' . ($wZ === 1 ? 's' : '') . ' 0)' : '')
                . ', so the quantity was changed and the "' . $valueHead . '" column was not updated.';
        }
        if ($wN > 0) {
            $notes[] = $wN . ' of ' . (int)$fin['differ'] . ' rows: the value fits no whole quantity at this unit cost, so the unit cost was changed or there is a typing mistake.';
        }
        $notes[] = 'A gap is NOT proof of loss: it can be stock sold or used since an older count, a typing or counting mistake, or real loss. The column "What it most likely means" on the next tab explains each row from its own cells; ask the manager to explain each row in the last column, starting at the top.';
        foreach ($fin['sheets'] as $sh) {
            foreach ($sh['value_cols'] as $letter => $hd) {
                $r = max(1, (int)$sh['header_row'] + 1);
                $notes[] = 'Fix the sheet' . ($multi ? ' "' . $sh['name'] . '"' : '') . ': put ' . import_vc_formula_hint($sh) . ' in ' . $letter . $r . ' ("' . $hd . '") and fill down.';
            }
        }
        if ($fin['missing_n'] > 0) {
            $notes[] = 'Tab "Missing prices": fill in the unit cost ("' . $priceHead . '") so these items can be counted.';
        }
        if (array_sum(array_column($fin['groups'], 'packs')) > 0) {
            $notes[] = 'Where you answered that a price is for a whole case or pack, the unit cost is that price divided by the pack size, as the import uses it.';
        }
        foreach ($notes as $note) {
            $w->row($si, $rn, [1 => $s($note, $tx)], 32.0);
            $w->merge($si, 'A' . $rn . ':B' . $rn);
            $rn++;
        }

        // ---- the disagreeing rows ----
        $tab = mb_substr($valueHead, 0, 24) . ' wrong';
        $cols = $multi ? [18] : [];
        $frz = [$multi ? 2 : 1, 1];          // the header row and the row number stay in view
        $di = $w->addSheet($tab, ['cols' => array_merge($cols, [8, 28, 20, 10, 10, 14, 16, 16, 16, 14, 48, 40]), 'freeze' => $frz]);
        $hdr = [];
        $c = 1;
        if ($multi) {
            $hdr[$c++] = $s('Sheet', $h);
        }
        foreach (['Sheet row', 'Item', 'Category', 'Unit', 'Qty in sheet', 'Unit cost', 'Correct value (Qty x Cost)', 'Sheet "' . $valueHead . '" says',
            'Difference (sheet minus correct)', 'Qty the sheet value implies', 'What it most likely means', 'Manager\'s explanation'] as $lbl) {
            $hdr[$c++] = $s($lbl, $h);
        }
        $w->row($di, 1, $hdr, 42.0);
        $rn = 2;
        foreach ($fin['rows'] as $d) {
            $c = 1;
            $cells = [];
            if ($multi) {
                $cells[$c++] = $s($d['sheet'], $tx);
            }
            $cells[$c++] = $n((int)$d['row'], $qn);
            $cells[$c++] = $s($d['name'], $tx);
            $cells[$c++] = $s($d['category'], $tx);
            $cells[$c++] = $s($d['unit'], $tx);
            $cells[$c++] = $n($d['qty'], $qn);
            $cells[$c++] = $n($d['cost'], $nm);
            $cells[$c++] = $n($d['calc'], $nm);
            $cells[$c++] = $n($d['value'], $nm);
            $cells[$c++] = $n($d['diff'], $d['diff'] > 0 ? $red : $grn);
            $cells[$c++] = $d['implied'] === null ? $s('', $tx) : $n($d['implied'], $qn);
            $cells[$c++] = $s((string)($d['why'] ?? ''), $tx);
            $cells[$c++] = $s('', $fill);
            $w->row($di, $rn++, $cells);
        }
        $lastCol = ImportXlsxWriter::colName(count($hdr));
        $w->filter($di, 'A1:' . $lastCol . max(1, $rn - 1));

        // ---- missing prices ----
        $mi = $w->addSheet('Missing prices', ['cols' => array_merge($cols, [8, 28, 22, 10, 12, 16, 36]), 'freeze' => $frz]);
        $hdr = [];
        $c = 1;
        if ($multi) {
            $hdr[$c++] = $s('Sheet', $h);
        }
        foreach (['Sheet row', 'Item', 'Category', 'Unit', 'Qty in sheet', $priceHead . ' (fill in)', 'Notes'] as $lbl) {
            $hdr[$c++] = $s($lbl, $h);
        }
        $w->row($mi, 1, $hdr, 32.0);
        $rn = 2;
        foreach ($fin['missing'] as $p) {
            $c = 1;
            $cells = [];
            if ($multi) {
                $cells[$c++] = $s($p['sheet'], $tx);
            }
            $cells[$c++] = $n((int)$p['row'], $qn);
            $cells[$c++] = $s($p['name'], $tx);
            $cells[$c++] = $s($p['category'], $tx);
            $cells[$c++] = $s($p['unit'], $tx);
            $cells[$c++] = $n($p['qty'], $qn);
            $cells[$c++] = $s('', $fill);
            $cells[$c++] = $s('', $fill);
            $w->row($mi, $rn++, $cells);
        }

        $res = $w->finish();
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => 'The workbook could not be built.'];
        }
        rewind($res['stream']);
        $body = (string)stream_get_contents($res['stream']);
        fclose($res['stream']);
        $safe = trim((string)preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/', '_', $base));
        return ['ok' => true, 'name' => ($safe !== '' ? $safe : 'stock list') . ' stock check ' . $date . '.xlsx', 'body' => $body];
    }
}
