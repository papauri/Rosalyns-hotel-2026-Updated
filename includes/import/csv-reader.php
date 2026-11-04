<?php
/**
 * Import engine - .csv / .tsv reader.
 *
 * Sniffs the encoding (UTF-8 BOM, UTF-16 BOM, UTF-8, else Windows-1252) and the delimiter
 * (comma, semicolon, tab, pipe). Returns the same shape as import_xlsx_read() with ONE sheet.
 * Every value is kept as TEXT exactly as written. A cell that starts with = + - or @ is just text:
 * nothing here ever evaluates it (it is flagged 'formula_like' so screens can show it as plain text).
 */

require_once __DIR__ . '/xlsx-reader.php';

if (!function_exists('import_csv_read')) {

    function import_csv_to_utf8(string $data): array
    {
        if (substr($data, 0, 3) === "\xEF\xBB\xBF") {
            return [substr($data, 3), 'UTF-8 (BOM)'];
        }
        if (substr($data, 0, 2) === "\xFF\xFE") {
            return [mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16LE'), 'UTF-16LE'];
        }
        if (substr($data, 0, 2) === "\xFE\xFF") {
            return [mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16BE'), 'UTF-16BE'];
        }
        if (mb_check_encoding($data, 'UTF-8')) {
            return [$data, 'UTF-8'];
        }
        return [mb_convert_encoding($data, 'UTF-8', 'Windows-1252'), 'Windows-1252'];
    }

    /** Pick the delimiter that splits the first lines into the most consistent number of columns. */
    function import_csv_sniff_delimiter(string $text, string $default = ','): string
    {
        $cands = [',', ';', "\t", '|'];
        $lines = [];
        $cur = array_fill_keys($cands, 0);
        $inQ = false;
        $len = min(strlen($text), 65536);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($ch === '"') {
                $inQ = !$inQ;
            } elseif (!$inQ) {
                if ($ch === "\n") {
                    $lines[] = $cur;
                    $cur = array_fill_keys($cands, 0);
                    if (count($lines) >= 30) {
                        break;
                    }
                } elseif (isset($cur[$ch])) {
                    $cur[$ch]++;
                }
            }
        }
        $best = $default;
        $bestScore = 0;
        foreach ($cands as $d) {
            $counts = [];
            foreach ($lines as $l) {
                if ($l[$d] > 0) {
                    $counts[] = $l[$d];
                }
            }
            if (!$counts) {
                continue;
            }
            $freq = array_count_values($counts);
            arsort($freq);
            $mode = array_key_first($freq);
            $score = $freq[$mode] * 10 + $mode;
            if ($score > $bestScore || ($score === $bestScore && $d === $default)) {
                $bestScore = $score;
                $best = $d;
            }
        }
        return $best;
    }

    function import_csv_parse(string $text, string $delim, int $maxRows, int $maxCols, int $maxCellLen)
    {
        $rows = [];
        $row = [];
        $field = '';
        $inQ = false;
        $wasQuoted = false;
        $len = strlen($text);
        $i = 0;
        $flush = function () use (&$row, &$field, &$wasQuoted) {
            $row[] = $field;
            $field = '';
            $wasQuoted = false;
        };
        while ($i < $len) {
            $ch = $text[$i];
            if ($inQ) {
                if ($ch === '"') {
                    if ($i + 1 < $len && $text[$i + 1] === '"') {
                        $field .= '"';
                        $i += 2;
                        continue;
                    }
                    $inQ = false;
                    $i++;
                    continue;
                }
                $field .= $ch;
                $i++;
            } else {
                if ($ch === '"' && $field === '') {
                    $inQ = true;
                    $wasQuoted = true;
                    $i++;
                } elseif ($ch === $delim) {
                    $flush();
                    $i++;
                } elseif ($ch === "\r" || $ch === "\n") {
                    $flush();
                    $rows[] = $row;
                    $row = [];
                    if ($ch === "\r" && $i + 1 < $len && $text[$i + 1] === "\n") {
                        $i++;
                    }
                    $i++;
                    if (count($rows) > $maxRows) {
                        return 'This file has more than ' . $maxRows . ' rows and was refused for safety.';
                    }
                } else {
                    $field .= $ch;
                    $i++;
                }
            }
            if (strlen($field) > $maxCellLen) {
                return 'A cell in this file is longer than ' . $maxCellLen . ' characters and the file was refused for safety.';
            }
            if (count($row) > $maxCols) {
                return 'This file has more than ' . $maxCols . ' columns and was refused for safety.';
            }
        }
        if ($field !== '' || $row) {
            $flush();
            $rows[] = $row;
        }
        return $rows;
    }

    /** Read CSV/TSV text. $name = display name of the file (sheet name = base name). */
    function import_csv_read_string(string $data, string $name = 'data.csv', array $limits = []): array
    {
        $fail = function (string $msg) {
            return ['ok' => false, 'error' => $msg, 'kind' => 'csv', 'date1904' => false, 'macros' => false, 'sheets' => []];
        };
        $maxBytes = (int)($limits['max_file_bytes'] ?? 15 * 1024 * 1024);
        if (strlen($data) > $maxBytes) {
            return $fail('The file is too large to import (limit ' . round($maxBytes / 1048576) . ' MB).');
        }
        if (substr($data, 0, 2) === 'PK') {
            return $fail('This file is a zip/Excel file but is named like a CSV. Please rename it to .xlsx or export a real CSV.');
        }
        $head = substr($data, 0, 8192);
        $bom16 = (substr($data, 0, 2) === "\xFF\xFE" || substr($data, 0, 2) === "\xFE\xFF");
        if (!$bom16 && strpos($head, "\0") !== false) {
            return $fail('This does not look like a text/CSV file (it contains binary data).');
        }
        [$text, $enc] = import_csv_to_utf8($data);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $delim = import_csv_sniff_delimiter($text, $ext === 'tsv' ? "\t" : ',');
        $parsed = import_csv_parse($text, $delim, (int)($limits['max_rows'] ?? 200000), (int)($limits['max_cols'] ?? 500), (int)($limits['max_cell_len'] ?? 32767));
        if (is_string($parsed)) {
            return $fail($parsed);
        }
        $cells = [];
        $maxRow = 0;
        $maxCol = 0;
        foreach ($parsed as $ri => $fields) {
            $r = $ri + 1;
            foreach ($fields as $ci => $val) {
                if ($val === '') {
                    continue;
                }
                $c = $ci + 1;
                $cells[$r][$c] = [
                    'ref' => import_cell_ref($r, $c), 'v' => $val, 'raw' => $val, 'formula' => false, 'cached' => true,
                    'date' => false, 'error' => false,
                    'formula_like' => (bool)preg_match('/^[=+\-@\t\r]/', $val),
                ];
                $maxRow = max($maxRow, $r);
                $maxCol = max($maxCol, $c);
            }
        }
        $base = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
        $sheet = [
            'name' => $base !== '' ? $base : 'Sheet1', 'hidden' => false, 'index' => 0, 'cells' => $cells,
            'dims' => ['rows' => $maxRow, 'cols' => $maxCol, 'ref' => $maxRow ? 'A1:' . import_cell_ref($maxRow, $maxCol) : ''],
            'merges' => [],
        ];
        return ['ok' => true, 'error' => '', 'kind' => 'csv', 'date1904' => false, 'macros' => false, 'encoding' => $enc, 'delimiter' => $delim, 'sheets' => [$sheet]];
    }

    function import_csv_read(string $path, string $name = '', array $limits = []): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'error' => 'The file could not be read.', 'kind' => 'csv', 'date1904' => false, 'macros' => false, 'sheets' => []];
        }
        $maxBytes = (int)($limits['max_file_bytes'] ?? 15 * 1024 * 1024);
        if (filesize($path) > $maxBytes) {
            return ['ok' => false, 'error' => 'The file is too large to import (limit ' . round($maxBytes / 1048576) . ' MB).', 'kind' => 'csv', 'date1904' => false, 'macros' => false, 'sheets' => []];
        }
        $data = file_get_contents($path);
        return import_csv_read_string($data === false ? '' : $data, $name !== '' ? $name : basename($path), $limits);
    }
}
