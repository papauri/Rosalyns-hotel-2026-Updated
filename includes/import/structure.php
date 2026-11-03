<?php
/**
 * Import engine - structure finder: sheet triage, header row, data region, and a kind for every row.
 *
 * kinds: item | ambiguous (zeros only, could be a heading or an out-of-stock item - asked) | section
 *        (category heading: NEVER an item) | total (total/summary line: NEVER an item) | note | blank |
 *        repeat-header | orphan (numbers with no name)
 * All words come from the alias set parameter; nothing here is hotel-specific.
 */

require_once __DIR__ . '/xlsx-reader.php';
require_once __DIR__ . '/mapper.php';

if (!function_exists('import_structure_sheet')) {

    /** Find the heading row. Returns ['row'=>int,'fields'=>[...],'has_item'=>bool] or null. */
    function import_find_header_row(array $sheet, array $aliases, int $maxScan = 40): ?array
    {
        $thr = import_map_auto_threshold();
        $best = null;
        $last = min($maxScan, (int)($sheet['dims']['rows'] ?? 0));
        for ($r = 1; $r <= $last; $r++) {
            if (empty($sheet['cells'][$r])) {
                continue;
            }
            $fields = [];
            foreach ($sheet['cells'][$r] as $cell) {
                $t = import_cell_text($cell);
                if ($t === '' || is_numeric($t)) {
                    continue;
                }
                $m = import_match_header($t, $aliases);
                if ($m['field'] !== null && $m['conf'] >= $thr) {
                    $fields[$m['field']] = true;
                }
            }
            $n = count($fields);
            $hasItem = isset($fields['item_name']);
            $ok = ($hasItem && $n >= 2) || (!$hasItem && $n >= 3);
            if (!$ok) {
                continue;
            }
            $score = $n + ($hasItem ? 100 : 0);
            if ($best === null || $score > $best['score']) {
                $best = ['row' => $r, 'fields' => array_keys($fields), 'has_item' => $hasItem, 'score' => $score];
            }
        }
        return $best;
    }

    function import_label_tokens(string $label): array
    {
        $n = import_norm_text($label);
        return $n === '' ? [] : explode(' ', $n);
    }

    /** Does the label start with a total word / phrase? */
    function import_label_is_total(string $label, array $aliases): bool
    {
        $norm = import_norm_text($label);
        if ($norm === '') {
            return false;
        }
        foreach (($aliases['total_words'] ?? []) as $w) {
            $wn = import_norm_text($w);
            if ($wn !== '' && ($norm === $wn || strpos($norm, $wn . ' ') === 0)) {
                return true;
            }
        }
        return false;
    }

    function import_label_has_summary_phrase(string $label, array $aliases): bool
    {
        $norm = ' ' . import_norm_text($label) . ' ';
        foreach (($aliases['summary_phrases'] ?? []) as $p) {
            $pn = import_norm_text($p);
            if ($pn !== '' && strpos($norm, ' ' . $pn . ' ') !== false) {
                return true;
            }
        }
        return false;
    }

    function import_label_has_section_word(string $label, array $aliases): bool
    {
        $norm = ' ' . import_norm_text($label) . ' ';
        foreach (($aliases['section_words'] ?? []) as $p) {
            $pn = import_norm_text($p);
            if ($pn !== '' && strpos($norm, ' ' . $pn . ' ') !== false) {
                return true;
            }
        }
        return false;
    }

    /** empty | zero | value  for a cell under a data column. */
    function import_cell_signal(?array $cell, string $kind): string
    {
        if (!import_cell_usable($cell)) {
            return 'empty';
        }
        $v = $cell['v'];
        if (is_string($v) && (trim($v) === '' || import_is_empty_marker($v))) {
            return 'empty';
        }
        if ($kind === 'number') {
            $p = import_parse_number($v);
            if ($p['status'] === 'ok') {
                return import_is_zero($p['value']) ? 'zero' : 'value';
            }
            return 'text';      // words in a number column: not a real value
        }
        return 'value';
    }

    function import_row_is_blank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (!empty($cell['merged_into'])) {
                continue;
            }
            if (import_cell_usable($cell) && !(is_string($cell['v']) && trim($cell['v']) === '')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Analyse the structure of one sheet.
     * Returns:
     * ['name','index','hidden','triage'=>['action','reason','overridable'],'header_row','columns','by_field',
     *  'rows'=>[rowNo=>['kind','label','category','category_ref','reason']], 'item_rows'=>[...],
     *  'categories'=>[ ['name','ref','row','items'] ], 'questions'=>[descriptors], 'unmapped_columns'=>[...],
     *  'ignored_columns'=>[...], 'name_list_mode'=>bool]
     */
    function import_structure_sheet(array $sheet, array $aliases, array $opts = []): array
    {
        $name = $sheet['name'];
        $res = [
            'name' => $name, 'index' => $sheet['index'] ?? 0, 'hidden' => !empty($sheet['hidden']),
            'triage' => ['action' => 'skip', 'reason' => '', 'overridable' => true],
            'header_row' => null, 'columns' => [], 'by_field' => [], 'rows' => [], 'item_rows' => [],
            'categories' => [], 'questions' => [], 'unmapped_columns' => [], 'ignored_columns' => [],
            'name_list_mode' => false,
        ];
        $maxScan = (int)($opts['header_scan_rows'] ?? 40);
        $force = !empty($opts['force']);   // a person overruled the skip

        if (empty($sheet['cells'])) {
            $res['triage']['reason'] = 'The sheet is empty.';
            return $res;
        }
        $header = import_find_header_row($sheet, $aliases, $maxScan);

        // name-based skip words
        $normName = ' ' . import_norm_text($name) . ' ';
        $nameWord = null;
        foreach (($aliases['skip_sheet_words'] ?? []) as $w) {
            $wn = import_norm_text($w);
            if ($wn !== '' && strpos($normName, ' ' . $wn . ' ') !== false) {
                $nameWord = $w;
                break;
            }
        }

        if (!empty($sheet['hidden']) && !$force) {
            $res['triage']['reason'] = 'This sheet is hidden in the workbook, so it is skipped. Tick it if you do want it imported.';
            if ($header === null) {
                return $res;
            }
        }
        if ($header === null) {
            if (!empty($sheet['hidden']) && !$force) {
                return $res;
            }
            $cellCount = 0;
            foreach ($sheet['cells'] as $rc) {
                $cellCount += count($rc);
            }
            if ($nameWord !== null) {
                $res['triage']['reason'] = 'Looks like a ' . $nameWord . ' sheet (by its name), not a list of items.';
            } elseif ($cellCount < 8) {
                $res['triage']['reason'] = 'Too little content to be a list.';
            } else {
                $res['triage'] = ['action' => 'ask', 'reason' => 'No column headings I recognise in the first ' . $maxScan . ' rows. Skip this sheet, or tell me which row holds the headings.', 'overridable' => true];
                $res['questions'][] = import_q('sheet-unknown', 'Sheet "' . $name . '" has no column headings I recognise. Skip it, or tell me which row holds the headings?', [
                    'sheet' => $name, 'options' => [['key' => 'skip', 'label' => 'Skip this sheet']],
                ]);
            }
            return $res;
        }

        $res['header_row'] = $header['row'];
        $map = import_map_columns($sheet, $header['row'], $aliases);
        $res['by_field'] = $map['by_field'];

        // sheet looks like a dashboard/summary by name but also has item columns => ask; else skip
        $hasQty = (bool)array_intersect(array_keys($map['by_field']), ['qty_primary', 'qty_secondary', 'qty_single', 'unit_price', 'unit']);
        if ($force) {
            $nameWord = null;
        }
        if ($nameWord !== null && !(isset($map['by_field']['item_name']) && $hasQty)) {
            $res['columns'] = $map['columns'];
            $res['triage']['reason'] = 'Looks like a ' . $nameWord . ' sheet (by its name), not a list of items.';
            return $res;
        }
        if (!isset($map['by_field']['item_name'])) {
            $res['columns'] = $map['columns'];
            $res['triage'] = ['action' => 'ask', 'reason' => 'I found headings but no clear item-name column.', 'overridable' => true];
            $cols = [];
            foreach ($map['columns'] as $c => $info) {
                if ($info['header'] !== '') {
                    $cols[] = ['key' => 'col:' . $info['letter'], 'label' => 'Column ' . $info['letter'] . ' "' . $info['header'] . '"'];
                }
            }
            $cols[] = ['key' => 'skip', 'label' => 'Skip this sheet'];
            $res['questions'][] = import_q('no-item-column', 'Which column in sheet "' . $name . '" holds the item names?', ['sheet' => $name, 'options' => $cols]);
            return $res;
        }

        // ---- classify rows ----
        $nameCol = $map['by_field']['item_name'];
        $dataCols = [];
        foreach ($map['columns'] as $c => $info) {
            if ($info['state'] === 'mapped' && !$info['derived'] && $info['field'] !== 'item_name') {
                $dataCols[$c] = import_field_kind($info['field']);
            }
        }
        $firstData = $header['row'] + 1;
        $lastRow = (int)$sheet['dims']['rows'];

        // pre-pass: how many rows carry data vs label only; are item labels mostly UPPER CASE?
        $labelOnly = 0;
        $withData = 0;
        $caps = 0;
        $capsBase = 0;
        for ($r = $firstData; $r <= $lastRow; $r++) {
            $cells = $sheet['cells'][$r] ?? [];
            $label = import_cell_text($cells[$nameCol] ?? null);
            if ($label === '' || import_is_empty_marker($label)) {
                continue;
            }
            $sig = ['value' => 0, 'zero' => 0];
            foreach ($dataCols as $c => $kind) {
                $s = import_cell_signal($cells[$c] ?? null, $kind);
                if (isset($sig[$s])) {
                    $sig[$s]++;
                }
            }
            if (import_label_is_total($label, $aliases)) {
                continue;
            }
            if ($sig['value'] > 0) {
                $withData++;
                if (preg_match('/\p{L}{3}/u', $label)) {
                    $capsBase++;
                    if (import_is_upper_label($label)) {
                        $caps++;
                    }
                }
            } elseif ($sig['zero'] === 0) {
                $labelOnly++;
            }
        }
        $nameListMode = ($labelOnly > $withData);
        $capsDiscriminates = ($capsBase > 0 && ($caps / $capsBase) < 0.5);
        $res['name_list_mode'] = $nameListMode;

        $curCat = null;
        $curCatRef = null;
        $catIndex = [];
        $catColField = isset($map['by_field']['category']) ? $map['by_field']['category'] : null;
        for ($r = $firstData; $r <= $lastRow; $r++) {
            $cells = $sheet['cells'][$r] ?? [];
            $info = ['kind' => 'blank', 'label' => '', 'category' => null, 'category_ref' => null, 'reason' => ''];
            if (!$cells || import_row_is_blank($cells)) {
                $res['rows'][$r] = $info;
                continue;
            }
            $labelCell = $cells[$nameCol] ?? null;
            $label = import_cell_text($labelCell);
            if (import_is_empty_marker($label)) {
                $label = '';
            }
            $info['label'] = $label;

            // repeated heading row
            $hf = [];
            foreach ($cells as $cell) {
                $t = import_cell_text($cell);
                if ($t === '' || is_numeric($t)) {
                    continue;
                }
                $m = import_match_header($t, $aliases);
                if ($m['field'] !== null && $m['conf'] >= 0.85) {
                    $hf[$m['field']] = true;
                }
            }
            if (count($hf) >= 3 || (count($hf) >= 2 && isset($hf['item_name']))) {
                $info['kind'] = 'repeat-header';
                $info['reason'] = 'Column headings repeated here.';
                $res['rows'][$r] = $info;
                continue;
            }

            // signals from the mapped data columns
            $sig = ['value' => 0, 'zero' => 0, 'text' => 0];
            $strong = false;    // a real price / unit / batch / expiry / remarks value
            foreach ($dataCols as $c => $kind) {
                $s = import_cell_signal($cells[$c] ?? null, $kind);
                if (isset($sig[$s])) {
                    $sig[$s]++;
                }
                $f = $map['columns'][$c]['field'];
                if ($s === 'value' && in_array($f, ['unit_price', 'unit', 'batch', 'expiry', 'remarks'], true)) {
                    $strong = true;
                }
            }

            if ($label === '') {
                $info['kind'] = 'orphan';
                $info['reason'] = 'Has values but no item name.';
                $res['rows'][$r] = $info;
                continue;
            }
            if (import_label_is_total($label, $aliases)) {
                $info['kind'] = 'total';
                $info['reason'] = 'Total line (starts with a total word).';
                $res['rows'][$r] = $info;
                continue;
            }
            if (!$strong && import_label_has_summary_phrase($label, $aliases)) {
                $info['kind'] = 'total';
                $info['reason'] = 'Summary line (not an item).';
                $res['rows'][$r] = $info;
                continue;
            }

            $merged = $labelCell && (!empty($labelCell['merge_span']) && $labelCell['merge_span'][1] >= 2);
            if ($sig['value'] === 0 && $sig['text'] === 0) {
                $words = count(import_label_tokens($label));
                if ($sig['zero'] === 0) {
                    // label only
                    if ($nameListMode && !$merged && !(import_is_upper_label($label) && $capsDiscriminates)) {
                        $info['kind'] = 'item';
                    } elseif ($words >= 7 || strlen($label) > 70) {
                        $info['kind'] = 'note';
                        $info['reason'] = 'Free-text note.';
                    } else {
                        $info['kind'] = 'section';
                    }
                } else {
                    // label + zeros only
                    if ($merged || import_label_has_section_word($label, $aliases) || (import_is_upper_label($label) && $capsDiscriminates)) {
                        $info['kind'] = 'section';
                    } else {
                        $info['kind'] = 'ambiguous';
                    }
                }
                if ($info['kind'] === 'section') {
                    $curCat = $label;
                    $curCatRef = import_source_ref($name, import_cell_ref($r, $nameCol));
                    $info['reason'] = 'Category heading: items below belong to it.';
                    $catIndex[] = ['name' => $label, 'ref' => $curCatRef, 'row' => $r, 'items' => 0];
                    $res['rows'][$r] = $info;
                    continue;
                }
                if ($info['kind'] === 'note') {
                    $res['rows'][$r] = $info;
                    continue;
                }
            } else {
                $info['kind'] = 'item';
            }

            // item (or ambiguous): attach category
            $cat = $curCat;
            $catRef = $curCatRef;
            if ($catColField !== null) {
                $cc = import_cell_text($cells[$catColField] ?? null);
                if ($cc !== '' && !import_is_empty_marker($cc)) {
                    $cat = $cc;
                    $catRef = import_source_ref($name, import_cell_ref($r, $catColField));
                }
            }
            $info['category'] = $cat;
            $info['category_ref'] = $catRef;
            if ($cat !== null && $catColField === null && $catIndex) {
                $catIndex[count($catIndex) - 1]['items']++;
            }
            $res['item_rows'][] = $r;
            $res['rows'][$r] = $info;
        }
        $res['categories'] = $catIndex;

        // Zero-only rows that are NOT in capitals, on a sheet whose confirmed headings are all in capitals,
        // are out-of-stock items (evidence from the sheet itself). Imported with a visible warning.
        // Anything else stays 'ambiguous' and is asked.
        if (count($catIndex) >= 2) {
            $upper = 0;
            foreach ($catIndex as $ci) {
                if (import_is_upper_label($ci['name'])) {
                    $upper++;
                }
            }
            if ($upper / count($catIndex) >= 0.8) {
                foreach ($res['rows'] as $rn => &$ri) {
                    if ($ri['kind'] === 'ambiguous' && !import_is_upper_label($ri['label'])) {
                        $ri['kind'] = 'item';
                        $ri['assumed_item'] = 'the headings on this sheet are all in capitals';
                    }
                }
                unset($ri);
            }
        } elseif (!$catIndex && count($res['item_rows']) >= 5) {
            foreach ($res['rows'] as $rn => &$ri) {
                if ($ri['kind'] === 'ambiguous') {
                    $ri['kind'] = 'item';
                    $ri['assumed_item'] = 'this sheet has no category headings';
                }
            }
            unset($ri);
        }

        // value-shape checks on the item rows, then derive questions / unmapped / ignored columns
        import_map_check_shapes($map, $sheet, $res['item_rows'], $aliases);
        $res['columns'] = $map['columns'];
        $res['by_field'] = $map['by_field'];
        foreach ($map['columns'] as $c => $info) {
            if ($info['state'] === 'question') {
                $res['questions'][] = import_q('mapping-unsure', import_map_question_text($info, $name), [
                    'sheet' => $name, 'cells' => [$info['header_ref']],
                    'options' => [
                        ['key' => 'use:' . ($info['suggested_field'] ?? ''), 'label' => 'Yes, use it as ' . str_replace('_', ' ', (string)($info['suggested_field'] ?? ''))],
                        ['key' => 'ignore', 'label' => 'No, ignore this column'],
                    ],
                ]);
            } elseif ($info['state'] === 'unmapped') {
                $hasData = false;
                foreach ($res['item_rows'] as $r) {
                    if (import_cell_usable($sheet['cells'][$r][$c] ?? null)) {
                        $hasData = true;
                        break;
                    }
                }
                if ($hasData) {
                    $res['unmapped_columns'][] = ['col' => $c, 'letter' => $info['letter'], 'header' => $info['header'], 'ref' => $info['header_ref']];
                }
            } elseif ($info['state'] === 'mapped' && $info['derived']) {
                $res['ignored_columns'][] = ['col' => $c, 'letter' => $info['letter'], 'header' => $info['header'], 'field' => $info['field'], 'ref' => $info['header_ref']];
            }
        }
        // essentially no items found => nothing to import
        if (!$res['item_rows']) {
            $res['triage'] = ['action' => 'skip', 'reason' => 'Headings found but no item rows under them.', 'overridable' => true];
        } else {
            $res['triage'] = ['action' => 'import', 'reason' => '', 'overridable' => true];
            if (!empty($sheet['hidden']) && !$force) {
                $res['triage'] = ['action' => 'skip', 'reason' => 'This sheet is hidden in the workbook, so it is skipped. Tick it if you do want it imported.', 'overridable' => true];
            } elseif ($nameWord !== null) {
                $res['triage'] = ['action' => 'ask', 'reason' => 'Named like a "' . $nameWord . '" sheet, but it has item columns. Import it?', 'overridable' => true];
                $res['questions'][] = import_q('sheet-unknown', 'Sheet "' . $name . '" is named like a ' . $nameWord . ' sheet but it has item columns. Should it be imported?', [
                    'sheet' => $name, 'options' => [['key' => 'import', 'label' => 'Yes, import it'], ['key' => 'skip', 'label' => 'No, skip it']],
                ]);
            }
        }
        return $res;
    }
}
