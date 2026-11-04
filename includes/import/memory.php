<?php
/**
 * Import - "will this file fit on this server?" (memory and time), checked BEFORE the work starts so a big file ends with a plain message
 * instead of a fatal error half way through an upload.
 *
 *   import_mem_calibration($aliases)      bytes of memory / seconds per spreadsheet cell, MEASURED on a small generated sample with the real engine
 *                                         (two sizes, so the fixed cost drops out); cached in storage/imports/.mem_calibration.json against the
 *                                         PHP version and the engine files, so it is measured again after any change to the engine
 *   import_mem_count($path, $ext)         rows and cells of a file, counted without reading it into the engine
 *   import_mem_check($path, $name, ...)   projected need vs memory_limit / max_execution_time; tries to raise them where the host allows;
 *                                         returns ['ok'=>false, 'message'=>plain text with the row count and a size to split into]
 *   import_mem_check_review($bytes, ...)  the same for opening a job that is already analysed (the stored analysis is decoded and resolved)
 *
 * Nothing here is a hand-picked per-row number: the per-cell cost is what the engine used on the sample. (PHP before 8.2 cannot reset its peak,
 * so there the sustained size is multiplied by a documented transient factor instead.)
 */

require_once __DIR__ . '/review-view.php';

if (!function_exists('import_mem_check')) {

    function import_mem_ini_bytes($v): int
    {
        $v = trim((string)$v);
        if ($v === '' || $v === '-1') {
            return PHP_INT_MAX;
        }
        $n = (float)$v;
        switch (strtolower(substr($v, -1))) {
            case 'g':
                $n *= 1024;
                // no break
            case 'm':
                $n *= 1024;
                // no break
            case 'k':
                $n *= 1024;
        }
        return $n > 0 ? (int)min($n, PHP_INT_MAX) : PHP_INT_MAX;
    }

    function import_mem_limit(): int
    {
        return import_mem_ini_bytes(ini_get('memory_limit'));
    }

    function import_mem_fmt(int $bytes): string
    {
        return $bytes >= PHP_INT_MAX / 2 ? 'no limit' : number_format($bytes / 1048576, 0) . ' MB';
    }

    /**
     * memory_limit counts the memory PHP has taken from the system in 2 MB chunks, which is more than the bytes in use at the peak: freed rows leave
     * holes that new small arrays only partly fill. Measured on 5,000 and 20,000 rows in fresh processes (real peak / bytes-in-use peak): upload
     * 1.15 and 1.21, opening a job 1.64 and 1.61. It cannot be measured inside a running request (the allocator keeps chunks from earlier work), so it
     * is the worst of those measurements, rounded up.
     */
    function import_mem_real_factor(): float
    {
        return 1.7;
    }

    /** A generated stock list shaped like a real one (same columns, headings, distinct names), as a CSV string. */
    function import_mem_sample_csv(int $rows): string
    {
        $out = "Item Name,Batch,Balance,Unit,Unit Price,Max Level,Expiry Date\n";
        $words = ['Lager', 'Stout', 'Cider', 'Vodka', 'Rum', 'Flour', 'Sugar', 'Rice', 'Beans', 'Salt', 'Oil', 'Soap', 'Bleach', 'Mop', 'Paper', 'Pen'];
        $units = ['bottle', 'kg', 'each', 'L'];
        $perCat = 50;
        for ($i = 0, $c = 0; $i < $rows; $i++) {
            if ($i % $perCat === 0) {
                $out .= 'SAMPLE CATEGORY ' . sprintf('%04d', ++$c) . ",,,,,,\n";
            }
            $out .= 'Sample ' . $words[$i % 16] . ' ' . sprintf('%05d', $i) . ',,' . (($i * 7) % 50) . ',' . $units[$i % 4] . ',' . (1000 + ($i % 50) * 10) . ',' . (10 + ($i * 3) % 90) . ",\n";
        }
        return $out;
    }

    /** One measured run of the whole workflow on a sample of $rows rows: [cells, upload peak, review peak, seconds, json bytes]. */
    function import_mem_measure(int $rows, array $aliases): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ims');
        file_put_contents($path, import_mem_sample_csv($rows));
        $can = function_exists('memory_reset_peak_usage');
        $factor = 2.2;                  // only used when the peak cannot be read per step (PHP < 8.2)
        gc_collect_cycles();
        if ($can) {
            memory_reset_peak_usage();
        }
        $base = memory_get_usage();              // bytes in use: exact, and not disturbed by chunks the allocator already holds from earlier work
        $t = microtime(true);
        $an = import_analyze_file($path, 'sample.csv', $aliases, ['max_bytes' => 50 * 1048576]);
        $sec = microtime(true) - $t;
        $up = $can ? memory_get_peak_usage() - $base : (int)((memory_get_usage() - $base) * $factor);
        $js = json_encode($an, JSON_UNESCAPED_UNICODE);
        $jsonBytes = strlen((string)$js);
        unset($an);
        gc_collect_cycles();
        if ($can) {
            memory_reset_peak_usage();
        }
        $base2 = memory_get_usage();
        $an = json_decode((string)$js, true);
        unset($js);
        $state = ['analysis' => $an, 'res' => null, 'ctx' => ['existing' => [], 'unit_options' => ['each'], 'corrections' => []]];
        unset($an);
        $state['row_src'] = import_review_row_sources($state['analysis']);
        $state['uid_cat'] = import_review_uid_categories($state['analysis']);
        $state['res'] = import_resolve_inplace($state['analysis'], [], $state['ctx'], true);
        $V = import_rv_build($state, []);
        $rev = $can ? memory_get_peak_usage() - $base2 : (int)((memory_get_usage() - $base2) * $factor);
        unset($state, $V);
        @unlink($path);
        return ['cells' => $rows * 7, 'up' => max(1, $up), 'review' => max(1, $rev), 'sec' => max(0.0001, $sec), 'json' => max(1, $jsonBytes)];
    }

    /**
     * Per-cell memory (upload step and review step), per-JSON-byte memory (review step) and seconds per cell, from two sample sizes.
     * ['cell_up'=>float, 'cell_review'=>float, 'json_review'=>float, 'sec_cell'=>float, 'measured'=>true]
     */
    function import_mem_calibration(array $aliases, bool $force = false): array
    {
        static $mem = null;
        if ($mem !== null && !$force) {
            return $mem;
        }
        $files = glob(__DIR__ . '/*.php') ?: [];
        $sig = md5(PHP_VERSION . '|' . implode('|', array_map(function ($f) {
            return basename($f) . ':' . (int)@filemtime($f);
        }, $files)));
        $cache = import_storage_dir() . '/.mem_calibration.json';
        if (!$force && is_file($cache)) {
            $c = json_decode((string)@file_get_contents($cache), true);
            if (is_array($c) && ($c['sig'] ?? '') === $sig && !empty($c['cell_up'])) {
                return $mem = $c;
            }
        }
        $a = import_mem_measure(300, $aliases);
        $b = import_mem_measure(900, $aliases);
        $slope = function (string $k, string $per) use ($a, $b) {
            $s = ($b[$k] - $a[$k]) / max(1, $b[$per] - $a[$per]);
            return $s > 0 ? $s : $b[$k] / $b[$per];          // fixed costs only (should not happen): fall back to the average
        };
        $mem = [
            'sig' => $sig, 'measured' => true, 'php' => PHP_VERSION,
            'cell_up' => $slope('up', 'cells'), 'cell_review' => $slope('review', 'cells'), 'json_review' => $slope('review', 'json'),
            'sec_cell' => $slope('sec', 'cells'), 'sample_cells' => $b['cells'], 'sample_cols' => 7, 'real_factor' => import_mem_real_factor(),
        ];
        @file_put_contents($cache, json_encode($mem));
        return $mem;
    }

    /** Rows and cells of an uploaded file, without running the engine on it. null = could not tell (then the engine's own limits apply). */
    function import_mem_count(string $path, string $ext): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        if ($ext === 'csv' || $ext === 'tsv') {
            $fh = @fopen($path, 'rb');
            if (!$fh) {
                return null;
            }
            $first = (string)fgets($fh);
            $delim = $ext === 'tsv' ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ','));
            $cols = max(1, count(str_getcsv(rtrim($first, "\r\n"), $delim)));
            $rows = $first === '' ? 0 : 1;
            while (!feof($fh)) {
                $chunk = (string)fread($fh, 1048576);
                $rows += substr_count($chunk, "\n");
            }
            fclose($fh);
            return ['rows' => $rows, 'cells' => $rows * $cols];
        }
        if ($ext === 'xlsx' || $ext === 'xlsm') {
            $zip = import_zip_open($path, false, []);
            if (empty($zip['ok'])) {
                return null;
            }
            $rows = 0;
            $cells = 0;
            foreach ($zip['entries'] as $key => $e) {
                if (strpos($key, 'xl/worksheets/') !== 0 || substr($key, -4) !== '.xml') {
                    continue;
                }
                $r = import_zip_read($zip, $e['name']);
                if (empty($r['ok'])) {
                    continue;
                }
                $rows += substr_count($r['data'], '<row ');
                $cells += substr_count($r['data'], '<c ');
                unset($r);
            }
            return ['rows' => $rows, 'cells' => $cells];
        }
        return null;
    }

    /** Try to raise memory_limit to $bytes where the host allows it. Returns the limit in force afterwards. Never goes above $cap. */
    function import_mem_raise(int $bytes, int $cap = 1073741824): int
    {
        $cur = import_mem_limit();
        if ($cur >= $bytes || $cur >= PHP_INT_MAX / 2) {
            return $cur;
        }
        $want = min($bytes, max($cap, $cur));
        if ($want > $cur && function_exists('ini_set')) {          // some hosts switch the function off: then the limit simply stays
            @ini_set('memory_limit', (string)((int)ceil($want / 1048576)) . 'M');
        }
        return import_mem_limit();
    }

    /** "about M rows" for a message: the rows that fit, rounded down to a number a person can use (500s, never below 100). */
    function import_mem_rows_that_fit(int $rows, int $need, int $available): int
    {
        $fit = (int)floor($rows * max(0, $available) / max(1, $need) * 0.9);
        $step = $fit >= 5000 ? 1000 : ($fit >= 1000 ? 500 : 100);
        return max(100, (int)(floor($fit / $step) * $step));
    }

    function import_mem_message(int $rows, int $fit): string
    {
        return 'This file has ' . number_format($rows) . ' rows - too many to read in one go on this server. Split it into files of about '
            . number_format($fit) . ' rows (by sheet or category) and import them one after another.';
    }

    /**
     * Will this file fit? Options (tests): 'limit' (bytes), 'time_limit' (seconds, 0 = none), 'count' (rows/cells), 'calibration'.
     * Returns ['ok'=>bool, 'message'=>string, 'rows'=>int, 'cells'=>int, 'need'=>int, 'limit'=>int, 'fit_rows'=>int, 'seconds'=>float].
     */
    function import_mem_check(string $path, string $name, array $aliases, array $o = []): array
    {
        $ext = strtolower(pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_EXTENSION));
        $n = $o['count'] ?? import_mem_count($path, $ext);
        $ok = ['ok' => true, 'message' => '', 'rows' => (int)($n['rows'] ?? 0), 'cells' => (int)($n['cells'] ?? 0), 'need' => 0, 'limit' => import_mem_limit(), 'fit_rows' => 0, 'seconds' => 0.0];
        if (!$n || max($n['cells'], $n['rows'] * 7) < 3000) {
            return $ok;                 // small files always fit; nothing to measure
        }
        $cal = $o['calibration'] ?? import_mem_calibration($aliases);
        $perCell = max($cal['cell_up'], $cal['cell_review']) * (float)($cal['real_factor'] ?? import_mem_real_factor());
        // A sparse sheet has few cells but every row still becomes an item: count at least as many cells as the sample had per row.
        $cells = max($n['cells'], $n['rows'] * (int)($cal['sample_cols'] ?? 7));
        $need = (int)ceil($cells * $perCell * 1.15) + 4 * 1048576;       // 15% for the spread of real files, plus the stored text and the database call
        $headroom = 6 * 1048576;
        $override = isset($o['limit']);
        $limit = $override ? (int)$o['limit'] : import_mem_limit();
        $used = $override ? 0 : memory_get_usage();
        if (!$override && $limit < $used + $need + $headroom) {
            $limit = import_mem_raise($used + $need + $headroom);
        }
        $available = $limit >= PHP_INT_MAX / 2 ? PHP_INT_MAX : $limit - $used - $headroom;
        $sec = $cells * $cal['sec_cell'] * 1.5;
        $tl = isset($o['time_limit']) ? (int)$o['time_limit'] : (int)ini_get('max_execution_time');
        if (!isset($o['time_limit']) && $tl > 0 && $sec + 5 > $tl) {
            if (function_exists('set_time_limit')) {
                @set_time_limit((int)ceil($sec) + 30);
            }
            $tl = (int)ini_get('max_execution_time');
        }
        $ok['need'] = $need;
        $ok['limit'] = $limit;
        $ok['seconds'] = $sec;
        $fit = 0;
        if ($available < $need) {
            $fit = import_mem_rows_that_fit($n['rows'], $need, $available);
        }
        if ($tl > 0 && $sec + 5 > $tl) {
            $fit = $fit > 0 ? min($fit, import_mem_rows_that_fit($n['rows'], (int)ceil($sec), max(0, $tl - 5))) : import_mem_rows_that_fit($n['rows'], (int)ceil($sec), max(0, $tl - 5));
        }
        if ($fit > 0) {
            $ok['ok'] = false;
            $ok['fit_rows'] = $fit;
            $ok['message'] = import_mem_message($n['rows'], $fit);
        }
        return $ok;
    }

    /**
     * Opening a job that is already analysed: the stored analysis ($analysisBytes of JSON) is decoded and resolved on every request.
     * Raises memory where allowed; otherwise a plain message instead of a fatal error.
     */
    function import_mem_check_review(int $analysisBytes, array $aliases, array $o = []): array
    {
        $cal = $o['calibration'] ?? import_mem_calibration($aliases);
        $need = (int)ceil($analysisBytes * $cal['json_review'] * (float)($cal['real_factor'] ?? import_mem_real_factor()) * 1.15) + 4 * 1048576;
        $override = isset($o['limit']);
        $limit = $override ? (int)$o['limit'] : import_mem_limit();
        $used = $override ? 0 : memory_get_usage();
        if (!$override && $limit < $used + $need + 6 * 1048576) {
            $limit = import_mem_raise($used + $need + 6 * 1048576);
        }
        $fits = $limit >= PHP_INT_MAX / 2 || $limit - $used - 6 * 1048576 >= $need;
        return ['ok' => $fits, 'need' => $need, 'limit' => $limit,
            'message' => $fits ? '' : 'This import is too big for this server to open (it needs about ' . import_mem_fmt($need) . ' and the limit is ' . import_mem_fmt($limit)
                . '). Cancel it, split the file into smaller files (by sheet or category) and import them one after another.'];
    }
}
