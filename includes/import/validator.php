<?php
/**
 * Import engine - row validator.
 *
 * Turns the item rows found by structure.php into proposed rows. EVERY proposed value is
 *   ['value'=>..., 'source'=>'Sheet!C12', 'how'=>'as-written'|'converted-date'|'formula-result'|'unit-from-remarks']
 * and nothing else can enter a row: a value that is not in a source cell does not exist. Derived columns
 * are listed under 'ignored' (how = 'derived-ignored') and never imported. A formula cell with no
 * cached result is 'unknown' (listed under 'unknown'), never 0.
 *
 * Row status: ready | needs-answer | skipped | blocked (see import_recompute_statuses()).
 */

require_once __DIR__ . '/structure.php';

if (!function_exists('import_validate_sheet')) {

    function import_field_labels(): array
    {
        return [
            'item_name' => 'Item name', 'batch' => 'Batch', 'qty_primary' => 'Bar/Kitchen balance',
            'qty_secondary' => 'Stores balance', 'qty_single' => 'Balance', 'unit' => 'Unit', 'expiry' => 'Expiry date',
            'max_level' => 'Max level', 'min_level' => 'Min level', 'unit_price' => 'Unit price', 'remarks' => 'Remarks',
            'category' => 'Category',
        ];
    }

    function import_cell_source(string $sheetName, array $cell): string
    {
        return import_source_ref($sheetName, $cell['ref']);
    }

    /** Text value of a cell exactly as written (whitespace tidied), or null when empty. */
    function import_cell_as_text(?array $cell): ?string
    {
        if (!import_cell_usable($cell)) {
            return null;
        }
        $v = $cell['v'];
        if (is_bool($v)) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            $s = (string)($cell['raw'] !== null && $cell['raw'] !== '' ? $cell['raw'] : $v);
        } else {
            $s = (string)$v;
        }
        $s = import_norm_ws($s);
        return $s === '' ? null : $s;
    }

    /** Read an expiry cell. status: ok | empty | unknown | invalid | ambiguous */
    function import_read_expiry(?array $cell, bool $date1904 = false): array
    {
        if ($cell !== null && !$cell['cached']) {
            return ['status' => 'unknown'];
        }
        if (!import_cell_usable($cell)) {
            return ['status' => 'empty'];
        }
        $v = $cell['v'];
        $iso = null;
        $how = 'as-written';
        $alt = null;
        if (!empty($cell['date'])) {
            if (is_string($v) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $v, $m)) {
                $iso = $m[1];
                $how = 'converted-date';
            } else {
                return ['status' => 'invalid', 'why' => 'it is a time, not a date'];
            }
        } elseif (is_string($v)) {
            if (trim($v) === '' || import_is_empty_marker($v)) {
                return ['status' => 'empty'];
            }
            $p = import_parse_date_string($v);
            if (!$p['ok']) {
                return ['status' => 'invalid', 'why' => '"' . import_norm_ws($v) . '" is not a date'];
            }
            if ($p['ambiguous']) {
                return ['status' => 'ambiguous', 'iso' => $p['iso'], 'alt' => $p['alt'], 'raw' => import_norm_ws($v)];
            }
            $iso = $p['iso'];
            $how = $p['how'];
        } elseif (is_int($v) || is_float($v)) {
            if ($v > 20000 && $v < 80000) {
                $conv = import_xlsx_serial_to_iso((float)$v, $date1904);
                if ($conv === null || strlen($conv) < 10) {
                    return ['status' => 'invalid', 'why' => 'the number ' . $v . ' is not a date'];
                }
                $iso = substr($conv, 0, 10);
                $how = 'converted-date';
            } else {
                return ['status' => 'invalid', 'why' => 'the number ' . $v . ' is not a date'];
            }
        } else {
            return ['status' => 'invalid', 'why' => 'it is not a date'];
        }
        $year = (int)substr($iso, 0, 4);
        if ($year < 1990 || $year > 2100) {
            return ['status' => 'invalid', 'why' => $iso . ' is not a believable date'];
        }
        return ['status' => 'ok', 'iso' => $iso, 'how' => $how];
    }

    function import_unit_words(array $aliases): array
    {
        $set = [];
        foreach (($aliases['units'] ?? []) as $u) {
            $set[import_norm_text($u)] = true;
        }
        return $set;
    }

    function import_row_uid(string $fileShort, int $sheetIndex, int $row): string
    {
        return $fileShort . ':' . $sheetIndex . ':' . $row;
    }

    /**
     * Validate every item row of one imported sheet.
     * Returns ['rows'=>[...], 'questions'=>[descriptors with row_uids], 'skipped_rows'=>[...]]
     */
    function import_validate_sheet(array $sheet, array $struct, array $aliases, array $opts = [], string $fileShort = 'f'): array
    {
        $name = $sheet['name'];
        $by = $struct['by_field'];
        $unitWords = import_unit_words($aliases);
        $labels = import_field_labels();
        $rows = [];
        $questions = [];
        $skipped = [];

        // rows that are not items
        foreach ($struct['rows'] as $r => $info) {
            if (in_array($info['kind'], ['section', 'total', 'note', 'repeat-header', 'orphan'], true)) {
                $cellRef = isset($by['item_name']) ? import_cell_ref($r, $by['item_name']) : import_cell_ref($r, 1);
                $skipped[] = [
                    'row' => $r, 'kind' => $info['kind'], 'label' => $info['label'], 'source' => import_source_ref($name, $cellRef),
                    'status' => 'skipped', 'reason' => $info['reason'],
                ];
            }
        }

        $numberFields = ['qty_primary', 'qty_secondary', 'qty_single', 'max_level', 'min_level', 'unit_price'];
        $hasUnitCol = isset($by['unit']);

        foreach ($struct['item_rows'] as $r) {
            $info = $struct['rows'][$r];
            $cells = $sheet['cells'][$r] ?? [];
            $uid = import_row_uid($fileShort, (int)$sheet['index'], $r);
            $row = [
                'uid' => $uid, 'sheet' => $name, 'sheet_index' => (int)$sheet['index'], 'row' => $r,
                'ref' => import_source_ref($name, import_cell_ref($r, $by['item_name'])),
                'kind' => $info['kind'], 'status' => 'ready', 'fields' => [], 'ignored' => [], 'unknown' => [],
                'category' => $info['category'], 'category_source' => $info['category_ref'],
                'computed' => [], 'warnings' => [], 'question_ids' => [], 'suggestions' => [],
            ];
            $rowQs = [];

            // ---- item name ----
            $nameCell = $cells[$by['item_name']] ?? null;
            $nameText = import_cell_as_text($nameCell);
            if ($nameText === null) {
                continue;   // cannot happen (structure only emits labelled rows), but never invent a name
            }
            $row['fields']['item_name'] = ['value' => $nameText, 'source' => import_cell_source($name, $nameCell), 'how' => 'as-written'];
            if (strlen($nameText) > 190) {
                $rowQs[] = import_q('bad-value', 'Row ' . $r . ' item name is longer than 190 characters and cannot be imported as written.', [
                    'sheet' => $name, 'row' => $r, 'cells' => [$row['ref']], 'bad' => true,
                    'options' => [['key' => 'skip', 'label' => 'Skip this row']],
                ]);
            }
            if (preg_match('/^[=+\-@]/', $nameText)) {
                $rowQs[] = import_q('formula-name', 'Row ' . $r . ' item name "' . $nameText . '" starts with = + - or @, which spreadsheets treat as a formula. It is kept as plain text — is it really the item name?', [
                    'sheet' => $name, 'row' => $r, 'cells' => [$row['ref']],
                    'options' => [['key' => 'keep', 'label' => 'Yes, import it as written'], ['key' => 'skip', 'label' => 'Skip this row']],
                ]);
            }
            if (!empty($info['assumed_item'])) {
                $row['warnings'][] = ['code' => 'all-zero-row', 'text' => 'Every column on this row is 0 or blank. It is treated as an out-of-stock item (not a heading) because ' . $info['assumed_item'] . '.'];
            }
            if ($info['kind'] === 'ambiguous') {
                $rowQs[] = import_q('section-or-item', 'Row ' . $r . ' "' . $nameText . '" has zero in every column. Is it a category heading, or an item that is out of stock?', [
                    'sheet' => $name, 'row' => $r, 'cells' => [$row['ref']],
                    'options' => [['key' => 'item', 'label' => 'An item (out of stock)'], ['key' => 'section', 'label' => 'A category heading for the rows below']],
                ]);
            }

            // ---- numbers ----
            foreach ($numberFields as $f) {
                if (!isset($by[$f])) {
                    continue;
                }
                $cell = $cells[$by[$f]] ?? null;
                if ($cell !== null && !$cell['cached']) {
                    $row['unknown'][$f] = import_source_ref($name, $cell['ref']);
                    $row['warnings'][] = ['code' => 'unknown-formula', 'text' => ($labels[$f] ?? $f) . ' in ' . $cell['ref'] . ' is a formula that was never calculated, so it is left blank (not read as 0).'];
                    continue;
                }
                if (!import_cell_usable($cell)) {
                    continue;
                }
                $p = import_parse_number($cell['v']);
                $src = import_cell_source($name, $cell);
                if ($p['status'] === 'empty') {
                    continue;
                }
                if ($p['status'] === 'invalid') {
                    $rowQs[] = import_q('bad-value', 'Row ' . $r . ': ' . ($labels[$f] ?? $f) . ' in ' . $cell['ref'] . ' is "' . import_norm_ws((string)$cell['v']) . '", which is not a number. Fix it in the sheet and upload again, or skip this row.', [
                        'sheet' => $name, 'row' => $r, 'cells' => [$src], 'bad' => true,
                        'options' => [['key' => 'skip', 'label' => 'Skip this row']],
                    ]);
                    continue;
                }
                if ($p['neg']) {
                    $rowQs[] = import_q('bad-value', 'Row ' . $r . ': ' . ($labels[$f] ?? $f) . ' in ' . $cell['ref'] . ' is negative (' . $p['value'] . '). Negative amounts are not imported. Fix it in the sheet and upload again, or skip this row.', [
                        'sheet' => $name, 'row' => $r, 'cells' => [$src], 'bad' => true,
                        'options' => [['key' => 'skip', 'label' => 'Skip this row']],
                    ]);
                    continue;
                }
                $row['fields'][$f] = ['value' => $p['value'], 'source' => $src, 'how' => $cell['formula'] ? 'formula-result' : 'as-written'];
                if ($f === 'unit_price') {
                    // the price as typed (for the price check: "2.875" vs "2,875"); never imported, only compared
                    $row['fields'][$f]['raw'] = import_norm_ws((string)(($cell['raw'] !== null && $cell['raw'] !== '') ? $cell['raw'] : $cell['v']));
                }
                if ($cell['formula']) {
                    $row['warnings'][] = ['code' => 'formula-result', 'text' => ($labels[$f] ?? $f) . ' in ' . $cell['ref'] . ' is the stored result of a formula.'];
                }
                if ($f === 'unit_price' && import_is_zero($p['value'])) {
                    $row['warnings'][] = ['code' => 'zero-price', 'text' => 'Unit price in ' . $cell['ref'] . ' is 0; treat it as "no price".'];
                }
            }

            // ---- expiry ----
            if (isset($by['expiry'])) {
                $cell = $cells[$by['expiry']] ?? null;
                $e = import_read_expiry($cell, !empty($opts['date1904']));
                if ($e['status'] === 'ok') {
                    $row['fields']['expiry'] = ['value' => $e['iso'], 'source' => import_cell_source($name, $cell), 'how' => $e['how']];
                } elseif ($e['status'] === 'unknown') {
                    $row['unknown']['expiry'] = import_source_ref($name, $cell['ref']);
                } elseif ($e['status'] === 'invalid') {
                    $rowQs[] = import_q('bad-value', 'Row ' . $r . ': expiry in ' . $cell['ref'] . ' cannot be used — ' . $e['why'] . '. Fix it in the sheet and upload again, or skip this row.', [
                        'sheet' => $name, 'row' => $r, 'cells' => [import_cell_source($name, $cell)], 'bad' => true,
                        'options' => [['key' => 'skip', 'label' => 'Skip this row']],
                    ]);
                } elseif ($e['status'] === 'ambiguous') {
                    $rowQs[] = import_q('ambiguous-date', 'Row ' . $r . ': expiry "' . $e['raw'] . '" in ' . $cell['ref'] . ' could be ' . $e['iso'] . ' (day first) or ' . $e['alt'] . ' (month first). Which is it?', [
                        'sheet' => $name, 'row' => $r, 'cells' => [import_cell_source($name, $cell)],
                        'options' => [['key' => 'date:' . $e['iso'], 'label' => $e['iso']], ['key' => 'date:' . $e['alt'], 'label' => $e['alt']]],
                    ]);
                }
            }

            // ---- text fields ----
            foreach (['batch', 'unit', 'remarks'] as $f) {
                if (!isset($by[$f])) {
                    continue;
                }
                $cell = $cells[$by[$f]] ?? null;
                $t = import_cell_as_text($cell);
                if ($t === null || import_is_empty_marker($t)) {
                    continue;
                }
                if ($f === 'remarks' && !$hasUnitCol && isset($unitWords[import_norm_text($t)])) {
                    $row['fields']['unit'] = ['value' => $t, 'source' => import_cell_source($name, $cell), 'how' => 'unit-from-remarks'];
                    continue;
                }
                $row['fields'][$f] = ['value' => $t, 'source' => import_cell_source($name, $cell), 'how' => 'as-written'];
            }

            // ---- derived columns: shown, never imported ----
            foreach ($struct['columns'] as $c => $col) {
                if ($col['state'] === 'mapped' && $col['derived']) {
                    $cell = $cells[$c] ?? null;
                    if ($cell === null) {
                        continue;
                    }
                    $row['ignored'][] = [
                        'field' => $col['field'], 'header' => $col['header'], 'source' => import_cell_source($name, $cell),
                        'value' => import_cell_usable($cell) ? $cell['v'] : null, 'unknown' => !$cell['cached'], 'how' => 'derived-ignored',
                    ];
                }
            }

            // ---- computed (NOT a source value; listed with its sources) ----
            $parts = [];
            $srcs = [];
            $unknownQty = false;
            foreach (['qty_primary', 'qty_secondary', 'qty_single'] as $qf) {
                if (isset($row['fields'][$qf])) {
                    $parts[] = $row['fields'][$qf]['value'];
                    $srcs[] = $row['fields'][$qf]['source'];
                } elseif (isset($row['unknown'][$qf])) {
                    $unknownQty = true;
                }
            }
            if ($parts && !$unknownQty) {
                $row['computed']['qty_total'] = ['value' => array_sum($parts), 'sources' => $srcs, 'how' => 'sum-of-cells'];
            }
            if (!isset($row['fields']['qty_primary']) && !isset($row['fields']['qty_secondary']) && !isset($row['fields']['qty_single'])) {
                $row['warnings'][] = ['code' => 'no-quantity', 'text' => 'No quantity found for this row, so no opening stock is proposed.'];
            }

            $row['_qs'] = $rowQs;
            $rows[] = $row;
        }

        // ---- duplicates inside the sheet ----
        $groups = [];
        foreach ($rows as $i => $row) {
            $groups[import_norm_text($row['fields']['item_name']['value'])][] = $i;
        }
        $hasBatchCol = isset($by['batch']);
        foreach ($groups as $key => $idxs) {
            if (count($idxs) < 2) {
                continue;
            }
            $batches = [];
            $allBatched = $hasBatchCol;
            foreach ($idxs as $i) {
                $b = isset($rows[$i]['fields']['batch']) ? import_norm_text($rows[$i]['fields']['batch']['value']) : '';
                if ($b === '') {
                    $allBatched = false;
                }
                $batches[] = $b;
            }
            $distinct = count(array_unique($batches)) === count($batches);
            $nm = $rows[$idxs[0]]['fields']['item_name']['value'];
            $rowNums = array_map(function ($i) use ($rows) {
                return $rows[$i]['row'];
            }, $idxs);
            foreach ($idxs as $i) {
                $rows[$i]['group'] = ['key' => $key, 'size' => count($idxs), 'rows' => $rowNums];
            }
            if ($allBatched && $distinct) {
                foreach ($idxs as $i) {
                    $rows[$i]['group']['batches'] = $batches;
                    $rows[$i]['group']['resolution'] = 'batches';
                }
                continue;
            }
            $why = $hasBatchCol ? 'and the Batch column is blank or the same for them' : 'with no batch';
            $q = import_q($hasBatchCol ? 'duplicate-batch-clash' : 'duplicate-no-batch',
                'Row ' . $rowNums[0] . ' "' . $nm . '" appears ' . count($idxs) . ' times (rows ' . implode(', ', $rowNums) . ') ' . $why . ' — are these separate batches or a duplicate?', [
                'sheet' => $name, 'row' => $rowNums[0], 'rows' => $rowNums,
                'cells' => array_map(function ($i) use ($rows) {
                    return $rows[$i]['ref'];
                }, $idxs),
                'options' => [
                    ['key' => 'two-batches', 'label' => 'Separate batches of the same item (import all)'],
                    ['key' => 'duplicate', 'label' => 'A duplicate: import it once (skip the later rows)'],
                    ['key' => 'separate-items', 'label' => 'Different items: keep them as separate items'],
                ],
            ]);
            $q['row_uids'] = array_map(function ($i) use ($rows) {
                return $rows[$i]['uid'];
            }, $idxs);
            $questions[] = $q;
        }

        // row-level questions get their uid
        foreach ($rows as &$row) {
            foreach ($row['_qs'] as $q) {
                $q['row_uids'] = [$row['uid']];
                $questions[] = $q;
            }
            unset($row['_qs']);
        }
        unset($row);
        return ['rows' => $rows, 'questions' => $questions, 'skipped_rows' => $skipped];
    }

    /** Register a question descriptor on an analysis; returns its id and links it to the rows it names. */
    function import_register_question(array &$analysis, array $q): string
    {
        $n = count($analysis['questions']) + 1;
        $id = 'q' . ($analysis['file']['short'] ?? 'f') . '-' . $n;
        $q['id'] = $id;
        $q['answer'] = null;
        $uids = $q['row_uids'] ?? [];
        unset($q['row_uids']);
        $q['row_uids'] = $uids;
        $analysis['questions'][] = $q;
        $want = array_flip($uids);
        if ($want) {
            foreach ($analysis['sheets'] as &$sh) {
                foreach ($sh['rows'] as &$row) {
                    if (isset($want[$row['uid']])) {
                        $row['question_ids'][] = $id;
                    }
                }
                unset($row);
            }
            unset($sh);
        }
        return $id;
    }

    /** ready | needs-answer | blocked from the registered questions (skipped sheets have no rows). */
    function import_recompute_statuses(array &$analysis): void
    {
        $byId = [];
        foreach ($analysis['questions'] as $q) {
            $byId[$q['id']] = $q;
        }
        foreach ($analysis['sheets'] as &$sh) {
            $sheetOpen = false;
            foreach ($analysis['questions'] as $q) {
                if ($q['sheet'] === $sh['name'] && $q['row'] === null && empty($q['row_uids']) && $q['blocking'] && $q['answer'] === null && $sh['triage']['action'] === 'import') {
                    $sheetOpen = true;
                }
            }
            foreach ($sh['rows'] as &$row) {
                $status = 'ready';
                foreach ($row['question_ids'] as $qid) {
                    $q = $byId[$qid] ?? null;
                    if (!$q || !$q['blocking'] || $q['answer'] !== null) {
                        continue;
                    }
                    if (!empty($q['bad'])) {
                        $status = 'blocked';
                        break;
                    }
                    $status = 'needs-answer';
                }
                if ($status === 'ready' && $sheetOpen) {
                    $status = 'needs-answer';
                    $row['sheet_question_open'] = true;
                }
                $row['status'] = $status;
            }
            unset($row);
        }
        unset($sh);
    }

    /** Score 0..1 for two already-normalised names (0 when their digits differ: Blue 52 is not Blue 55). */
    function import_name_score_norm(string $n, string $cn): float
    {
        preg_match_all('/\d+/', $n, $d1);
        preg_match_all('/\d+/', $cn, $d2);
        if ($d1[0] !== $d2[0]) {
            return 0.0;
        }
        $lev = 1.0 - (levenshtein($n, $cn) / max(strlen($n), strlen($cn)));
        similar_text($n, $cn, $pct);
        return max($lev, $pct / 100);
    }

    /**
     * Fuzzy NAME suggestions. Returns suggestions ONLY (never applied):
     * [['name'=>candidate,'score'=>0..1], ...] best first. Candidates that equal $name after
     * normalisation are not suggestions (they are the same name). Names whose digits differ are never
     * suggested (Blue 52 / Blue 55 are different items).
     */
    function import_suggest_names(string $name, array $candidateNames, array $opts = []): array
    {
        $min = (float)($opts['min_score'] ?? 0.85);
        $max = (int)($opts['max'] ?? 3);
        $minLen = (int)($opts['min_len'] ?? 5);
        $n = import_norm_text($name);
        if (strlen($n) < $minLen) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($candidateNames as $cand) {
            $cn = import_norm_text($cand);
            if ($cn === '' || $cn === $n || strlen($cn) < $minLen || isset($seen[$cn])) {
                continue;
            }
            if (abs(strlen($cn) - strlen($n)) > 3) {
                continue;
            }
            $score = import_name_score_norm($n, $cn);
            if ($score >= $min) {
                $seen[$cn] = true;
                $out[] = ['name' => (string)$cand, 'score' => round($score, 3)];
            }
        }
        usort($out, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        return array_slice($out, 0, $max);
    }
}
