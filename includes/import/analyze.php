<?php
/**
 * Import engine - single entry point (pure: no database, no output).
 *
 *   import_analyze_file($path, $origName, $aliases, $opts)  reader -> triage -> structure -> mapper ->
 *                                                           validator -> crosscheck, returns the analysis
 *   import_assert_no_invented_values($analysis, $readerCells)  returns [] when EVERY proposed value equals
 *                                                           its source cell (used by tests and the commit step)
 *
 * opts: max_bytes (25 MB), force_sheets [names to import although skipped], crosscheck (true),
 *       case_price_ratio, known_names, limits (zip/csv limits)
 */

require_once __DIR__ . '/csv-reader.php';
require_once __DIR__ . '/crosscheck.php';

if (!function_exists('import_analyze_file')) {

    function import_allowed_extensions(): array
    {
        return ['xlsx', 'xlsm', 'csv', 'tsv'];
    }

    function import_fail_analysis(string $name, string $msg, array $file = []): array
    {
        return [
            'ok' => false, 'error' => $msg, 'file' => array_merge(['name' => $name, 'short' => 'f'], $file),
            'sheets' => [], 'questions' => [], 'conflicts' => [], 'totals' => [],
        ];
    }

    /** Checks name, size and magic bytes BEFORE anything is parsed. Returns an error string or ''. */
    function import_precheck_file(string $path, string $origName, int $maxBytes, string &$ext): string
    {
        $base = basename(str_replace('\\', '/', $origName));
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if ($ext === 'xls') {
            return 'This is an old-format Excel file (.xls). Please open it in Excel and use Save As > Excel Workbook (.xlsx), then upload that.';
        }
        if (in_array($ext, ['doc', 'docx', 'pdf'], true)) {
            return 'Word and PDF files are not supported yet. Please upload the Excel (.xlsx) or CSV version of the list.';
        }
        if (!in_array($ext, import_allowed_extensions(), true)) {
            return 'This file type (' . ($ext !== '' ? '.' . $ext : 'no extension') . ') cannot be imported. Please upload an Excel (.xlsx) or CSV file.';
        }
        if (!is_file($path) || !is_readable($path)) {
            return 'The file could not be read.';
        }
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return 'The file is empty.';
        }
        if ($size > $maxBytes) {
            return 'The file is too large to import (limit ' . round($maxBytes / 1048576) . ' MB).';
        }
        $fh = fopen($path, 'rb');
        $head = $fh ? (string)fread($fh, 8) : '';
        if ($fh) {
            fclose($fh);
        }
        if (in_array($ext, ['xlsx', 'xlsm'], true)) {
            if (substr($head, 0, 4) === "\xD0\xCF\x11\xE0") {
                return 'This file is an old-format or password-protected Excel file named .' . $ext . '. Please remove the password / save it as a normal .xlsx and retry.';
            }
            if (substr($head, 0, 2) !== 'PK') {
                return 'This file is named .' . $ext . ' but is not an Excel workbook (wrong file type).';
            }
        }
        return '';
    }

    function import_analyze_file(string $path, string $origName, array $aliases, array $opts = []): array
    {
        $display = basename(str_replace('\\', '/', $origName));
        $maxBytes = (int)($opts['max_bytes'] ?? 25 * 1024 * 1024);
        $ext = '';
        $err = import_precheck_file($path, $origName, $maxBytes, $ext);
        if ($err !== '') {
            return import_fail_analysis($display, $err);
        }
        $sha = hash_file('sha256', $path);
        $file = ['name' => $display, 'ext' => $ext, 'size' => filesize($path), 'sha256' => $sha, 'short' => substr($sha, 0, 8)];

        $limits = (array)($opts['limits'] ?? []);
        if (in_array($ext, ['xlsx', 'xlsm'], true)) {
            $read = import_xlsx_read($path, false, $limits);
        } else {
            $read = import_csv_read($path, $display, $limits);
        }
        if (!$read['ok']) {
            return import_fail_analysis($display, $read['error'], $file);
        }

        $analysis = [
            'ok' => true, 'error' => '', 'file' => $file, 'kind' => $read['kind'],
            'macros_ignored' => !empty($read['macros']), 'date1904' => !empty($read['date1904']),
            'sheets' => [], 'questions' => [], 'conflicts' => [], 'warnings' => [], 'totals' => [],
        ];
        if (!empty($read['macros'])) {
            $analysis['warnings'][] = 'This workbook contains macros. They are never run; only the cells are read.';
        }
        $force = array_flip((array)($opts['force_sheets'] ?? []));
        $pending = [];     // [sheetIdx => descriptors]

        foreach ($read['sheets'] as $sheet) {
            $struct = import_structure_sheet($sheet, $aliases, ['force' => isset($force[$sheet['name']])] + $opts);
            $entry = [
                'index' => $sheet['index'], 'name' => $sheet['name'], 'hidden' => $sheet['hidden'],
                'dims' => $sheet['dims']['ref'], 'merged_ranges' => count($sheet['merges']),
                'triage' => $struct['triage'], 'header_row' => $struct['header_row'],
                'columns' => array_values($struct['columns']), 'unmapped_columns' => $struct['unmapped_columns'],
                'ignored_columns' => $struct['ignored_columns'], 'categories' => $struct['categories'],
                'name_list_mode' => $struct['name_list_mode'],
                'rows' => [], 'skipped_rows' => [], 'counts' => [],
            ];
            $qs = $struct['questions'];
            if ($struct['triage']['action'] === 'import') {
                $val = import_validate_sheet($sheet, $struct, $aliases, $opts + ['date1904' => !empty($read['date1904'])], $file['short']);
                $entry['rows'] = $val['rows'];
                $entry['skipped_rows'] = $val['skipped_rows'];
                $qs = array_merge($qs, $val['questions']);
            } elseif ($struct['triage']['action'] === 'ask') {
                $entry['skipped_rows'] = [];
            }
            $analysis['sheets'][] = $entry;
            $pending[] = $qs;
        }
        foreach ($pending as $qs) {
            foreach ($qs as $q) {
                import_register_question($analysis, $q);
            }
        }
        // non-item rows belong to their sheet: a blank-row count and the kinds, for the "accounted for" line
        foreach ($analysis['sheets'] as $i => &$sh) {
            $sh['counts'] = [
                'items' => count($sh['rows']),
                'sections' => count(array_filter($sh['skipped_rows'], function ($r) {
                    return $r['kind'] === 'section';
                })),
                'totals' => count(array_filter($sh['skipped_rows'], function ($r) {
                    return $r['kind'] === 'total';
                })),
                'notes' => count(array_filter($sh['skipped_rows'], function ($r) {
                    return in_array($r['kind'], ['note', 'repeat-header', 'orphan'], true);
                })),
            ];
        }
        unset($sh);

        import_recompute_statuses($analysis);
        if (($opts['crosscheck'] ?? true) !== false) {
            import_crosscheck($analysis, $opts);
        }
        $analysis['totals'] = import_analysis_totals($analysis);
        return $analysis;
    }

    function import_analysis_totals(array $analysis): array
    {
        $t = [
            'items_read' => 0, 'categories' => 0, 'skipped_totals_rows' => 0, 'skipped_other_rows' => 0,
            'ignored_derived_columns' => 0, 'sheets_total' => count($analysis['sheets']), 'sheets_imported' => 0,
            'sheets_skipped' => 0, 'sheets_ask' => 0,
            'rows_ready' => 0, 'rows_needs_answer' => 0, 'rows_blocked' => 0,
            'questions_total' => count($analysis['questions']), 'questions_blocking' => 0, 'conflicts' => count($analysis['conflicts']),
        ];
        foreach ($analysis['sheets'] as $sh) {
            $a = $sh['triage']['action'];
            if ($a === 'import') {
                $t['sheets_imported']++;
                $t['items_read'] += count($sh['rows']);
                $t['categories'] += count($sh['categories']);
                $t['ignored_derived_columns'] += count($sh['ignored_columns']);
                foreach ($sh['skipped_rows'] as $r) {
                    if ($r['kind'] === 'total') {
                        $t['skipped_totals_rows']++;
                    } elseif ($r['kind'] !== 'section') {
                        $t['skipped_other_rows']++;
                    }
                }
                foreach ($sh['rows'] as $row) {
                    if ($row['status'] === 'ready') {
                        $t['rows_ready']++;
                    } elseif ($row['status'] === 'blocked') {
                        $t['rows_blocked']++;
                    } else {
                        $t['rows_needs_answer']++;
                    }
                }
            } elseif ($a === 'ask') {
                $t['sheets_ask']++;
            } else {
                $t['sheets_skipped']++;
            }
        }
        foreach ($analysis['questions'] as $q) {
            if ($q['blocking'] && $q['answer'] === null) {
                $t['questions_blocking']++;
            }
        }
        return $t;
    }

    // ---------------------------------------------------------------- the "nothing invented" invariant

    /** Index reader output by sheet name: accepts import_*_read() output or [name => cells]. */
    function import_cells_by_sheet(array $readerCells): array
    {
        if (isset($readerCells['sheets']) && is_array($readerCells['sheets'])) {
            $out = [];
            foreach ($readerCells['sheets'] as $sh) {
                $out[$sh['name']] = $sh['cells'];
            }
            return $out;
        }
        return $readerCells;
    }

    function import_source_cell(array $bySheet, string $source): ?array
    {
        [$sheetName, $ref] = import_split_source($source);
        $pos = import_parse_ref($ref);
        if (!$pos || !isset($bySheet[$sheetName][$pos[0]][$pos[1]])) {
            return null;
        }
        return $bySheet[$sheetName][$pos[0]][$pos[1]];
    }

    /**
     * Every proposed value must equal the content of the source cell it names.
     * Returns a list of violation strings (empty = fine).
     */
    function import_assert_no_invented_values(array $analysis, array $readerCells): array
    {
        $bySheet = import_cells_by_sheet($readerCells);
        $allowed = ['item_name', 'batch', 'qty_primary', 'qty_secondary', 'qty_single', 'unit', 'expiry', 'max_level', 'min_level', 'unit_price', 'remarks'];
        $numeric = ['qty_primary', 'qty_secondary', 'qty_single', 'max_level', 'min_level', 'unit_price'];
        $bad = [];
        foreach ($analysis['sheets'] as $sh) {
            if (($sh['triage']['action'] ?? '') !== 'import' && $sh['rows']) {
                $bad[] = 'Rows were proposed for a sheet that was not set to import: ' . $sh['name'];
            }
            $nonItem = [];
            foreach ($sh['skipped_rows'] as $sr) {
                $nonItem[$sr['row']] = $sr['kind'];
            }
            foreach ($sh['rows'] as $row) {
                $where = $sh['name'] . ' row ' . $row['row'];
                if (isset($nonItem[$row['row']])) {
                    $bad[] = $where . ': a ' . $nonItem[$row['row']] . ' row was proposed as an item';
                }
                foreach ($row['fields'] as $f => $fd) {
                    if (!in_array($f, $allowed, true)) {
                        $bad[] = $where . ': field "' . $f . '" must never be imported';
                        continue;
                    }
                    if ($fd['value'] === null) {
                        continue;
                    }
                    $cell = import_source_cell($bySheet, $fd['source']);
                    if ($cell === null) {
                        $bad[] = $where . ': ' . $f . ' names a source cell that does not exist (' . $fd['source'] . ')';
                        continue;
                    }
                    if (!$cell['cached'] || !empty($cell['error']) || $cell['v'] === null) {
                        $bad[] = $where . ': ' . $f . ' comes from a cell with no stored value (' . $fd['source'] . ')';
                        continue;
                    }
                    if ($fd['how'] === 'formula-result' && empty($cell['formula'])) {
                        $bad[] = $where . ': ' . $f . ' says formula-result but ' . $fd['source'] . ' is not a formula';
                    }
                    if ($f === 'expiry') {
                        $iso = null;
                        $v = $cell['v'];
                        if (!empty($cell['date']) && is_string($v)) {
                            $iso = substr($v, 0, 10);
                        } elseif (is_string($v)) {
                            $p = import_parse_date_string($v);
                            $iso = $p['ok'] ? $p['iso'] : null;
                            if ($p['ok'] && $p['ambiguous'] && $fd['value'] === $p['alt']) {
                                $iso = $p['alt'];
                            }
                        } elseif (is_numeric($v)) {
                            $c = import_xlsx_serial_to_iso((float)$v, !empty($analysis['date1904']));
                            $iso = $c !== null ? substr($c, 0, 10) : null;
                        }
                        if ($iso !== $fd['value']) {
                            $bad[] = $where . ': expiry ' . $fd['value'] . ' does not match ' . $fd['source'];
                        }
                    } elseif (in_array($f, $numeric, true)) {
                        $p = import_parse_number($cell['v']);
                        if ($p['status'] !== 'ok' || abs((float)$p['value'] - (float)$fd['value']) > 1e-9) {
                            $bad[] = $where . ': ' . $f . ' ' . json_encode($fd['value']) . ' does not match ' . $fd['source'];
                        }
                    } else {
                        $txt = is_bool($cell['v']) ? '' : import_norm_ws(($cell['raw'] !== null && is_numeric($cell['v'])) ? $cell['raw'] : (string)$cell['v']);
                        if ($txt !== $fd['value']) {
                            $bad[] = $where . ': ' . $f . ' "' . $fd['value'] . '" does not match ' . $fd['source'];
                        }
                    }
                }
                if (!empty($row['category']) && !empty($row['category_source'])) {
                    $cell = import_source_cell($bySheet, $row['category_source']);
                    if ($cell === null || import_norm_ws((string)$cell['v']) !== $row['category']) {
                        $bad[] = $where . ': category "' . $row['category'] . '" does not match ' . $row['category_source'];
                    }
                }
            }
        }
        return $bad;
    }
}
