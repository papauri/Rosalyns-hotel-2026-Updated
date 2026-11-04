<?php
/**
 * Import - job state machine and the answers API (Phase 1b-backend). Procedural, prepared statements only.
 *
 *   import_job_create($pdo, $tmpPath, $origName, $userId, $opts)   store the upload + create the job (see store.php)
 *   import_job_analyse($pdo, $jobId)                               read the stored file, run the engine with the hotel's aliases and
 *                                                                  the existing ingredient names, keep the analysis on the job
 *   import_job_answer($pdo, $jobId, $questionId, $answer, $userId) validate ONE answer against the question's own options and keep it
 *   import_job_set_update_fields($pdo, $jobId, $key, $fields, $userId)  tick which fields of a matched existing ingredient may change
 *   import_job_review_summary($pdo, $jobId)                        everything the review screen shows (counts, rows, items, questions)
 *   import_job_can_confirm($pdo, $jobId)                           false while any needs-answer / blocked row or open sheet question remains
 *   import_job_csv($pdo, $jobId)                                   the "what will be imported" CSV
 *   import_job_cancel($pdo, $jobId, $userId)                       stop; the staged file is deleted
 *   import_job_revalidate($pdo, $job)                              the SERVER-SIDE re-check commit runs: re-read the stored file, re-analyse,
 *                                                                  re-apply the stored answers, re-run the "nothing invented" invariant
 *
 * Answers live in import_jobs.answers_json: {"<question key>": {key, pack_size?, by, at}, "_overrides": {...}, "_updates": {...}}.
 * The browser sends answers only; a status sent by a browser is never read, statuses are recomputed here from the answers.
 * Answer shapes the screen sends (answer = the option 'key', or an array with it):
 *   use existing X / create new      possible-typo ('use:<Existing name>' | 'keep')
 *   skip a row                       'skip' (bad-value, formula-name, conflict ...)
 *   choose price A or B              conflict ('use:<label shown>' | 'skip')
 *   per case or per unit             case-price ('per-unit' | ['key'=>'per-case','pack_size'=>12])
 *   batches or duplicate             duplicate-* ('two-batches' | 'duplicate' | 'separate-items')
 *   a date                           ambiguous-date ('date:YYYY-MM-DD')
 *   unit for a sheet                 no-unit ('unit:<name>')    same item on two sheets: ('add' | 'first' | 'skip')
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/resolve.php';
require_once dirname(__DIR__, 2) . '/admin/includes/import-schema.php';

if (!function_exists('import_job_analyse')) {

    function import_json($v): string
    {
        return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The same text as import_json(), written piece by piece (the analysis, each sheet, each row) to a temporary stream and read back once:
     * json_encode of one huge array grows its buffer by doubling, which on a 20,000-row analysis is a 28 MB spike on top of everything else,
     * and collecting the pieces in an array is worse (thousands of small strings). $depth > 3 = encode the rest in one go (a row).
     */
    function import_json_pieces($v): string
    {
        $fh = fopen('php://temp/maxmemory:1048576', 'w+b');
        import_json_write($fh, $v, 0);
        rewind($fh);
        $out = (string)stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    function import_json_write($fh, $v, int $depth): void
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if (!is_array($v) || $depth > 3 || !$v) {
            fwrite($fh, json_encode($v, $flags));
            return;
        }
        $isList = true;
        $n = 0;
        foreach ($v as $k => $_) {
            if ($k !== $n++) {
                $isList = false;
                break;
            }
        }
        $first = true;
        fwrite($fh, $isList ? '[' : '{');
        foreach ($v as $k => $x) {
            if (!$first) {
                fwrite($fh, ',');
            }
            $first = false;
            if (!$isList) {
                fwrite($fh, json_encode((string)$k, $flags) . ':');
            }
            import_json_write($fh, $x, $depth + 1);
        }
        fwrite($fh, $isList ? ']' : '}');
    }
    function import_job_get(PDO $pdo, int $jobId): ?array
    {
        $st = $pdo->prepare('SELECT * FROM import_jobs WHERE id = ?');
        $st->execute([$jobId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    function import_job_create(PDO $pdo, string $tmpPath, string $origName, int $userId, array $opts = []): array
    {
        return import_store_upload($pdo, $tmpPath, $origName, $userId, $opts);
    }

    /** Stock data the resolver needs: existing ingredients, unit choices, this hotel's learned corrections. */
    function import_job_ctx(PDO $pdo): array
    {
        $hasPack = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_ingredients' AND COLUMN_NAME = 'pack_size'")->fetchColumn() > 0;
        $packCols = $hasPack ? 'pack_name, pack_size,' : 'NULL AS pack_name, NULL AS pack_size,';
        $existing = $pdo->query("SELECT id, name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point, notes, {$packCols} is_archived,
                                        (SELECT COUNT(*) FROM stock_recipe_ingredients x WHERE x.ingredient_id = i.id) AS recipes
                                 FROM stock_ingredients i ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $units = [];
        $aliasSet = import_load_aliases($pdo);
        foreach ($aliasSet['units'] as $u) {
            $n = rh_ssi_norm_unit((string)$u);
            if ($n !== '') {
                $units[$n] = true;
            }
        }
        foreach ($existing as $e) {
            if (trim((string)$e['unit']) !== '') {
                $units[trim((string)$e['unit'])] = true;
            }
        }
        $u = array_keys($units);
        sort($u, SORT_NATURAL | SORT_FLAG_CASE);
        return ['existing' => $existing, 'unit_options' => $u, 'corrections' => import_load_name_corrections($pdo),
            'price_evidence' => import_job_price_evidence($pdo, $existing), 'cost_limits' => import_cost_limits($pdo),
            'learned' => import_load_learned($pdo), 'unit_synonyms' => (array)$aliasSet['unit_synonyms']];
    }

    /**
     * The largest unit cost / opening-count line value the database columns can hold, read from the real column definitions
     * (a storage fact, not a policy). Cached for the request. Unreadable -> {} (price-check.php then falls back to the stock-count rule).
     */
    function import_cost_limits(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $out = [];
        try {
            $st = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                               AND ((TABLE_NAME = 'stock_ingredients' AND COLUMN_NAME = 'cost_per_unit') OR (TABLE_NAME = 'stock_count_lines' AND COLUMN_NAME IN ('cost_per_unit', 'variance_cost')))");
            $costMax = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((int)$r['NUMERIC_PRECISION'] <= 0) {
                    continue;
                }
                $max = import_decimal_max((int)$r['NUMERIC_PRECISION'], (int)$r['NUMERIC_SCALE']);
                if ($r['COLUMN_NAME'] === 'cost_per_unit') {
                    $costMax[] = $max;
                } else {
                    $out['line_value'] = $max;
                }
            }
            if ($costMax) {
                $out['cost'] = min($costMax);
                $out['from_database'] = true;
            }
        } catch (Throwable $e) {
            $out = [];
        }
        return $cache = $out;
    }

    /**
     * What this hotel's own data says about prices (price-check.php judges with it): each ingredient's unit costs oldest first (batch unit
     * costs = one row per delivery, plus the costs typed on APPROVED opening counts), and how big a price move usually is for the
     * category / the whole hotel. Bounded queries, no per-row lookups. Not cached (the numbers must follow the data).
     */
    function import_job_price_evidence(PDO $pdo, array $existing): array
    {
        $pts = [];          // id => [[date, seq, cost], ...]
        try {
            foreach ($pdo->query('SELECT ingredient_id, cost_per_unit, received_date, id FROM stock_batches WHERE cost_per_unit > 0 ORDER BY id DESC LIMIT 60000')->fetchAll(PDO::FETCH_NUM) as $r) {
                $pts[(int)$r[0]][] = [(string)$r[2], (int)$r[3], (float)$r[1]];
            }
        } catch (Throwable $e) {
            // no batches table: judged by what exists
        }
        try {
            foreach ($pdo->query("SELECT l.ingredient_id, l.cost_per_unit, c.count_date, l.id FROM stock_count_lines l INNER JOIN stock_counts c ON c.id = l.count_id
                                  WHERE c.is_opening = 1 AND c.status = 'approved' AND l.cost_per_unit > 0 AND l.actual_quantity > 0 ORDER BY l.id DESC LIMIT 60000")->fetchAll(PDO::FETCH_NUM) as $r) {
                $pts[(int)$r[0]][] = [(string)$r[2], 1000000000 + (int)$r[3], (float)$r[1]];
            }
        } catch (Throwable $e) {
            // no opening counts yet
        }
        $hist = [];
        foreach ($pts as $id => $list) {
            usort($list, function ($a, $b) {
                return [$a[0], $a[1]] <=> [$b[0], $b[1]];
            });
            $hist[$id] = array_map(function ($x) {
                return $x[2];
            }, $list);
        }
        $cat = [];
        foreach ($existing as $e) {
            $cat[(int)$e['id']] = (string)$e['category'];
        }
        $mv = import_pc_typical_moves($hist, $cat, import_pc_params());
        return ['history' => $hist, 'hotel_move' => $mv['hotel'], 'category_move' => $mv['category']];
    }

    function import_job_answers(array $job): array
    {
        $a = $job['answers_json'] !== null && $job['answers_json'] !== '' ? json_decode((string)$job['answers_json'], true) : [];
        return is_array($a) ? $a : [];
    }

    /** Engine options for a job: the hotel's aliases (+ header words a person confirmed), existing names, sheets a person overruled. */
    function import_job_engine_opts(PDO $pdo, array $answers, array $ctx): array
    {
        $aliases = import_load_aliases($pdo);
        $ov = (array)($answers['_overrides'] ?? []);
        if (!empty($ov['aliases'])) {
            $aliases = import_merge_aliases($aliases, ['fields' => (array)$ov['aliases']]);
        }
        return [
            'aliases' => $aliases,
            'opts' => [
                'known_names' => array_map(function ($e) {
                    return (string)$e['name'];
                }, $ctx['existing']),
                'force_sheets' => array_values(array_map('strval', (array)($ov['force_sheets'] ?? []))),
                'undo_heals' => array_values(array_map('strval', array_keys((array)($answers['_heal_undo'] ?? [])))),     // self-healing fixes the person took back
                'max_bytes' => max(1, (int)rh_import_setting('data_import_max_mb', $pdo)) * 1048576,
            ],
        ];
    }

    function import_read_cells(string $path, string $origName): array
    {
        $ext = strtolower(pathinfo(basename(str_replace('\\', '/', $origName)), PATHINFO_EXTENSION));
        return in_array($ext, ['xlsx', 'xlsm'], true) ? import_xlsx_read($path, false, []) : import_csv_read($path, basename($origName), []);
    }

    /** End a job that cannot be read: status failed with the plain reason, the staged file deleted (nothing half-made is left behind). */
    function import_job_fail(PDO $pdo, array $job, string $message): array
    {
        $pdo->prepare("UPDATE import_jobs SET status = 'failed', error_text = ?, stored_path = NULL WHERE id = ?")->execute([mb_substr($message, 0, 500), (int)$job['id']]);
        if (!empty($job['stored_path'])) {
            import_delete_stored((string)$job['stored_path']);
        }
        return ['ok' => false, 'error' => $message];
    }

    /**
     * Run the engine on the stored file and keep the result on the job. Safe to call again (re-analysis keeps the answers).
     * Returns ['ok'=>bool,'error'=>string].
     *
     * Before any work the file is measured against this server's memory_limit / max_execution_time (memory.php): a file that cannot fit ends as a
     * failed job with a plain "split it into files of about N rows" text, never as a fatal error. A "Reading..." marker is kept in error_text while
     * the engine runs, so a request that died (killed, timed out) is recognised later instead of leaving a job stuck: a fatal error is caught by the
     * shutdown handler; a hard kill is cleaned up by the staleness check below. $o['mem'] = test overrides for import_mem_check().
     */
    function import_job_analyse(PDO $pdo, int $jobId, array $o = []): array
    {
        require_once __DIR__ . '/memory.php';
        $job = import_job_get($pdo, $jobId);
        if (!$job) {
            return ['ok' => false, 'error' => 'That import was not found.'];
        }
        if (!in_array($job['status'], ['uploaded', 'analysed', 'reviewing'], true)) {
            return ['ok' => false, 'error' => 'This import is already ' . $job['status'] . '.'];
        }
        $path = import_job_file_path($job);
        if ($path === '') {
            return ['ok' => false, 'error' => 'The uploaded file is no longer on the server. Please upload it again.'];
        }
        $marker = (string)($job['error_text'] ?? '');
        if (strpos($marker, 'Reading') === 0) {
            $since = strtotime((string)substr($marker, strpos($marker, 'since ') !== false ? strpos($marker, 'since ') + 6 : 0));
            if ($since && time() - $since < 600) {
                return ['ok' => false, 'error' => 'This file is still being read. Wait a minute and reload the page.'];
            }
            return import_job_fail($pdo, $job, 'Reading this file did not finish the last time (the server ran out of memory or time). Split it into smaller files (by sheet or category) and import them one after another.');
        }
        $ctx = import_job_ctx($pdo);
        $eng = import_job_engine_opts($pdo, import_job_answers($job), $ctx);
        unset($job['analysis_json']);          // the old analysis text (large) is not needed to analyse again
        $chk = import_mem_check($path, (string)$job['original_name'], $eng['aliases'], (array)($o['mem'] ?? []));
        if (!$chk['ok']) {
            return import_job_fail($pdo, $job, $chk['message']);
        }
        $pdo->prepare('UPDATE import_jobs SET error_text = ? WHERE id = ?')->execute(['Reading... since ' . date('Y-m-d H:i:s'), $jobId]);
        $finished = false;
        $reserve = str_repeat('x', 262144);     // freed in the handler so there is room to write the failure down after "out of memory"
        register_shutdown_function(function () use (&$finished, &$reserve, $pdo, $job, $chk) {
            if ($finished) {
                return;
            }
            $reserve = null;
            $e = error_get_last();
            if (!$e || !in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            try {
                $rows = (int)($chk['rows'] ?? 0);
                $fit = $rows > 0 ? import_mem_rows_that_fit($rows, 2, 1) : 0;
                $why = stripos($e['message'], 'memory') !== false ? 'ran out of memory' : (stripos($e['message'], 'execution time') !== false ? 'ran out of time' : 'stopped');
                import_job_fail($pdo, $job, 'The server ' . $why . ' while reading this file.' . ($fit > 0 ? ' ' . import_mem_message($rows, $fit) : ' Split it into smaller files (by sheet or category) and import them one after another.'));
            } catch (Throwable $x) {
                // nothing more can be done at shutdown; the staleness check handles it on the next visit
            }
        });
        $analysis = import_analyze_file($path, (string)$job['original_name'], $eng['aliases'], $eng['opts']);
        if (empty($analysis['ok'])) {
            $finished = true;
            $pdo->prepare("UPDATE import_jobs SET status = 'failed', error_text = ? WHERE id = ?")->execute([mb_substr((string)$analysis['error'], 0, 500), $jobId]);
            return ['ok' => false, 'error' => (string)$analysis['error']];
        }
        $json = import_json_pieces($analysis);
        unset($analysis);
        $pdo->prepare("UPDATE import_jobs SET analysis_json = ?, status = IF(status = 'uploaded', 'analysed', status), error_text = NULL WHERE id = ?")
            ->execute([$json, $jobId]);
        $finished = true;
        return ['ok' => true, 'error' => ''];
    }
    /** [job, analysis, answers, ctx, resolved] for a job that has been analysed; null when it cannot be reviewed. */
    function import_job_state(PDO $pdo, int $jobId): ?array
    {
        static $memo = [];
        $job = import_job_get($pdo, $jobId);
        if (!$job || $job['analysis_json'] === null || $job['analysis_json'] === '') {
            return null;
        }
        $mkey = import_job_state_memo() ? $jobId . '|' . md5((string)$job['analysis_json']) . '|' . md5((string)$job['answers_json']) . '|' . $job['status'] : '';
        if ($mkey !== '' && isset($memo[$mkey])) {
            return $memo[$mkey];
        }
        $analysis = json_decode((string)$job['analysis_json'], true);
        if (!is_array($analysis)) {
            return null;
        }
        // Memory: the stored text (3.5 MB per 5,000 rows) is dropped once decoded, and the decoded analysis has ONE owner (the state) so the
        // answers are written into it directly instead of into a second copy.
        $job['analysis_json'] = null;
        $answers = import_job_answers($job);
        $ctx = import_job_ctx($pdo);
        $state = ['job' => $job, 'analysis' => $analysis, 'answers' => $answers, 'ctx' => $ctx, 'res' => null];
        unset($analysis);
        $state['row_src'] = import_review_row_sources($state['analysis']);      // slim copies of the little the screens need from the rows ...
        $state['uid_cat'] = import_review_uid_categories($state['analysis']);
        $state['res'] = import_resolve_inplace($state['analysis'], $answers, $ctx, true);     // ... because resolving lets go of the rows themselves
        if ($mkey !== '') {
            $memo = [$mkey => $state];          // one picture at a time (a 20,000-row state is large)
        }
        return $state;
    }

    /**
     * Opt-in per-request memo for import_job_state(): the page (admin/data-import.php) switches it on so one "answer" request resolves the
     * job twice instead of three times. The key is the job's own stored text (analysis + answers), so a changed answer never hits an old
     * picture. Off by default: tests and commit change stock data between calls and must always see it.
     */
    function import_job_state_memo(?bool $on = null): bool
    {
        static $enabled = false;
        if ($on !== null) {
            $enabled = $on;
        }
        return $enabled;
    }

    function import_job_find_question(array $state, string $questionId): ?array
    {
        foreach ($state['res']['questions'] as $q) {
            if ($q['id'] === $questionId) {
                return $q;
            }
        }
        foreach ($state['res']['extra_questions'] as $q) {
            if ($q['id'] === $questionId) {
                return $q;
            }
        }
        return null;
    }

    /** Column info (header text, flags) for a "mapping-unsure" / "no-item-column" question. */
    function import_job_column_of(array $analysis, string $sheet, string $headerRef = '', string $letter = ''): ?array
    {
        foreach ($analysis['sheets'] as $sh) {
            if ($sh['name'] !== $sheet) {
                continue;
            }
            foreach ($sh['columns'] as $c) {
                if (($headerRef !== '' && $c['header_ref'] === $headerRef) || ($letter !== '' && $c['letter'] === $letter)) {
                    return $c;
                }
            }
        }
        return null;
    }

    /**
     * Keep one answer. Returns ['ok'=>bool,'error'=>string,'can_confirm'=>bool,'reanalysed'=>bool].
     * A structural answer (import a sheet that was skipped, confirm what a column is, say which column holds the names) changes
     * what the engine reads, so the file is analysed again; the other answers are kept as they are.
     */
    function import_job_answer(PDO $pdo, int $jobId, string $questionId, $answer, int $userId = 0): array
    {
        $fail = function (string $m) {
            return ['ok' => false, 'error' => $m, 'can_confirm' => false, 'reanalysed' => false];
        };
        $state = import_job_state($pdo, $jobId);
        if (!$state) {
            return $fail('That import cannot be reviewed.');
        }
        if (!in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return $fail('This import is already ' . $state['job']['status'] . ' and cannot be changed.');
        }
        $q = import_job_find_question($state, $questionId);
        if (!$q) {
            return $fail('That question does not belong to this import.');
        }
        $why = import_answer_allowed($q, $answer);
        if ($why !== '') {
            return $fail($why);
        }
        $a = import_answer_shape($answer);
        if ($a['key'] === '_skip') {
            // "Skip this row/item": stored as skipped rows (the resolver leaves them out), not as an answer to the question
            $r = import_job_skip_question($pdo, $jobId, $state, $q, $userId);
            return $r['ok'] ? ['ok' => true, 'error' => '', 'can_confirm' => import_job_can_confirm($pdo, $jobId), 'reanalysed' => false] : $fail((string)$r['error']);
        }
        $keep = ['key' => $a['key']];
        if (($q['type'] ?? '') === 'case-price' && $a['key'] === 'per-case') {
            $keep['pack_size'] = (int)$a['pack_size'];
        }
        $keep['by'] = $userId > 0 ? $userId : null;
        $keep['at'] = date('Y-m-d H:i:s');
        $answers = $state['answers'];
        $reanalyse = false;
        // structural answers
        $type = (string)($q['type'] ?? '');
        $ov = (array)($answers['_overrides'] ?? []);
        if ($type === 'sheet-unknown' && $a['key'] === 'import') {
            $ov['force_sheets'] = array_values(array_unique(array_merge((array)($ov['force_sheets'] ?? []), [(string)$q['sheet']])));
            $reanalyse = true;
        } elseif ($type === 'mapping-unsure' && strpos($a['key'], 'use:') === 0) {
            $field = substr($a['key'], 4);
            $col = import_job_column_of($state['analysis'], (string)$q['sheet'], (string)($q['cells'][0] ?? ''));
            $allowedFields = array_keys(import_default_aliases()['fields']);
            if (!$col || $field === '' || !in_array($field, $allowedFields, true)) {
                return $fail('That column cannot be matched from here.');
            }
            if (!empty($col['clash']) || !empty($col['shape_fail'])) {
                return $fail('This column cannot be switched on from here (the values under it do not fit, or two columns compete). Fix the heading in the sheet and upload it again, or choose "ignore this column".');
            }
            $ov['aliases'][$field] = array_values(array_unique(array_merge((array)($ov['aliases'][$field] ?? []), [$col['header']])));
            $reanalyse = true;
        } elseif ($type === 'no-item-column' && strpos($a['key'], 'col:') === 0) {
            $col = import_job_column_of($state['analysis'], (string)$q['sheet'], '', substr($a['key'], 4));
            if (!$col || $col['header'] === '') {
                return $fail('That column cannot be used.');
            }
            $ov['aliases']['item_name'] = array_values(array_unique(array_merge((array)($ov['aliases']['item_name'] ?? []), [$col['header']])));
            $reanalyse = true;
        }
        $answers[$q['qkey']] = $keep;
        if ($ov) {
            $answers['_overrides'] = $ov;
        }
        $pdo->prepare("UPDATE import_jobs SET answers_json = ?, status = 'reviewing' WHERE id = ? AND status IN ('analysed','reviewing')")
            ->execute([import_json($answers), $jobId]);
        if ($reanalyse) {
            $r = import_job_analyse($pdo, $jobId);
            if (!$r['ok']) {
                return $fail($r['error']);
            }
        }
        return ['ok' => true, 'error' => '', 'can_confirm' => import_job_can_confirm($pdo, $jobId), 'reanalysed' => $reanalyse];
    }

    /** Tick which fields of a matched, existing ingredient the file may change ($key = rh_ssi_key of the stock name). Default: none. */
    function import_job_set_update_fields(PDO $pdo, int $jobId, string $key, array $fields, int $userId = 0): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state || !in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return ['ok' => false, 'error' => 'That import cannot be changed.'];
        }
        $item = null;
        foreach ($state['res']['items'] as $it) {
            if ($it['match'] && rh_ssi_key($it['match']['name']) === $key) {
                $item = $it;
            }
        }
        if (!$item) {
            return ['ok' => false, 'error' => 'That ingredient is not matched by this import.'];
        }
        $clean = [];
        foreach ($fields as $f) {
            $f = (string)$f;
            if (!in_array($f, import_update_fields(), true)) {
                return ['ok' => false, 'error' => 'You can only choose from: ' . implode(', ', import_update_fields()) . '.'];
            }
            if (!isset($item['diff'][$f])) {
                return ['ok' => false, 'error' => 'The file has no different value for "' . $f . '" on this ingredient.'];
            }
            $clean[$f] = true;
        }
        $answers = $state['answers'];
        $answers['_updates'][$key] = array_keys($clean);
        if (!$clean) {
            unset($answers['_updates'][$key]);
        }
        $pdo->prepare("UPDATE import_jobs SET answers_json = ?, status = 'reviewing' WHERE id = ? AND status IN ('analysed','reviewing')")->execute([import_json($answers), $jobId]);
        return ['ok' => true, 'error' => ''];
    }

    function import_job_can_confirm(PDO $pdo, int $jobId): bool
    {
        $state = import_job_state($pdo, $jobId);
        return $state !== null && !empty($state['res']['can_confirm']) && in_array($state['job']['status'], ['analysed', 'reviewing', 'confirmed'], true);
    }

    /** Active batch quantity per ingredient id for a set of ids, in ONE prepared query (no per-row lookups). */
    function import_batch_totals(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $out = [];
        if (!$ids) {
            return $out;
        }
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $st = $pdo->prepare("SELECT ingredient_id, COALESCE(SUM(quantity_remaining), 0) FROM stock_batches WHERE ingredient_id IN ($in) AND status = 'active' AND quantity_remaining > 0 GROUP BY ingredient_id");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
                    $out[(int)$r[0]] = (float)$r[1];
                }
            }
        } catch (Throwable $ex) {
            return [];                      // batches table not available: judge by the quantity alone
        }
        return $out;
    }

    /**
     * Stock already on hand for an existing ingredient: its current quantity plus active batches (batch totals come pre-fetched).
     * Returns ['qty'=>float,'unit'=>string,'batch_qty'=>float,'has_stock'=>bool,'recipes'=>int] or null when the ingredient is not found.
     */
    function import_item_on_hand(array $existingById, array $batchTotals, int $ingredientId): ?array
    {
        $e = $existingById[$ingredientId] ?? null;
        if ($e === null) {
            return null;
        }
        $batchQty = (float)($batchTotals[$ingredientId] ?? 0.0);
        $qty = (float)$e['current_quantity'];
        return ['qty' => $qty, 'unit' => (string)$e['unit'], 'batch_qty' => $batchQty, 'has_stock' => $qty > 0.00005 || $batchQty > 0.00005, 'recipes' => (int)($e['recipes'] ?? 0)];
    }

    /** The plain warning shown next to a ticked "cost per unit" on an item that already has stock. Warning only: it never blocks. */
    function import_cost_stock_warning(string $name, array $onHand, $old, $new): string
    {
        $q = $onHand['qty'] > 0.00005 ? $onHand['qty'] : $onHand['batch_qty'];
        $unit = trim((string)$onHand['unit']);
        $oldTxt = ($old === null || $old === '') ? 'blank' : import_fmt_money($old);
        $s = $name . ' already has ' . import_fmt_qty($q) . ($unit !== '' ? ' ' . $unit : '') . ' in stock. Changing its cost from ' . $oldTxt . ' to ' . import_fmt_money($new)
            . ' (as written, VAT not added or removed) changes recipe costs and the value of that stock straight away — before the opening count is approved.';
        if ($onHand['recipes'] > 0) {
            $s .= ' It is used in ' . $onHand['recipes'] . ' recipe' . ($onHand['recipes'] === 1 ? '' : 's') . '.';
        }
        return $s;
    }

    /** uid => where each shown value of that row came from (the proposed value of a row/item always names its source cell). */
    function import_review_row_sources(array $an): array
    {
        // Slim on purpose (a 20,000-row file keeps one of these per row): only what the screens read - the quantity cells and the par / reorder cells.
        $rowSrc = [];
        foreach ($an['sheets'] as $sh) {
            foreach ($sh['rows'] as $r) {
                // one flat array per row (nested arrays cost about 400 bytes each): [quantity cells, par cell, reorder cell]
                $rowSrc[$r['uid']] = ['qty_sources' => $r['computed']['qty_total']['sources'] ?? [], 'max' => $r['fields']['max_level']['source'] ?? null, 'min' => $r['fields']['min_level']['source'] ?? null];
            }
        }
        return $rowSrc;
    }

    /** uid => the category of that row (its heading, else the sheet name without leading symbols). */
    function import_review_uid_categories(array $an): array
    {
        $uidCat = [];
        foreach ($an['sheets'] as $sh) {
            $clean = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', (string)$sh['name']));
            $clean = mb_substr($clean !== '' ? $clean : (string)$sh['name'], 0, 100);
            foreach ($sh['rows'] as $r) {
                $uidCat[$r['uid']] = ($r['category'] !== null && $r['category'] !== '') ? (string)$r['category'] : $clean;
            }
        }
        return $uidCat;
    }

    /** Every question (file + synthetic) in the shape the screen uses, with the values of a price/unit conflict next to their source cells. */
    function import_review_question_payloads(array $state): array
    {
        $res = $state['res'];
        $an = $state['analysis'];
        $conflictByUids = [];
        $conflictByQ = [];
        foreach ($an['conflicts'] ?? [] as $c) {
            $u = array_map('strval', (array)($c['row_uids'] ?? []));
            sort($u);
            $conflictByUids[implode('|', $u) . '#' . $c['field']] = $c;
            $conflictByQ[$c['question_id']] = $c;
        }
        $qs = [];
        foreach (array_merge($res['questions'], $res['extra_questions']) as $q) {
            $cv = [];
            // a question whose rows were all left out ("Skip this row") is finished: it must not stay open in the counts
            $allOut = false;
            if (!empty($q['row_uids'])) {
                $allOut = true;
                foreach ($q['row_uids'] as $u0) {
                    if (($res['rows'][$u0]['status'] ?? '') !== 'skipped') {
                        $allOut = false;
                        break;
                    }
                }
            }
            if (in_array($q['type'], ['conflict', 'merge-conflict'], true)) {
                $u = array_map('strval', (array)($q['row_uids'] ?? []));
                sort($u);
                $c = $conflictByQ[$q['id']] ?? ($conflictByUids[implode('|', $u) . '#unit_price'] ?? ($conflictByUids[implode('|', $u) . '#unit'] ?? null));
                foreach ($c['values'] ?? [] as $v) {
                    $cv[] = ['key' => 'use:' . $v['label'], 'label' => $q['pack_labels']['use:' . $v['label']] ?? $v['label'], 'source' => $v['source'], 'field' => $c['field']];
                }
            }
            $extraQ = [];
            if ($q['type'] === 'no-unit') {
                $extraQ = ['unit_stats' => import_cont_unit_stats($res, (string)$q['sheet']), 'unit_items' => import_cont_unit_preview($res, (string)$q['sheet'])];
            }
            $qs[] = $extraQ + [
                'conflict_values' => $cv, 'pack_size' => $q['answer_detail']['pack_size'] ?? null,
                'id' => $q['id'], 'key' => $q['qkey'], 'type' => $q['type'], 'text' => $q['text'], 'blocking' => (bool)$q['blocking'],
                'bad' => !empty($q['bad']), 'sheet' => $q['sheet'], 'row' => $q['row'], 'cells' => $q['cells'], 'options' => $q['options'],
                'default' => $q['default'], 'answer' => ($allOut && ($q['answer'] ?? null) === null) ? '_skipped' : $q['answer'], 'auto_filled' => !empty($q['auto']), 'row_uids' => $q['row_uids'] ?? [],
                'can_skip' => !empty($q['blocking']) && !empty($q['row_uids']) && ($q['answer'] ?? null) === null && !$allOut,
                'suggestion' => $q['suggestion'] ?? null, 'synthetic' => !empty($q['synthetic']),
                'evidence' => $q['evidence'] ?? null,     // the numbers a price question was judged on (nothing invented), for the screen / read-only helper
                'conflict_field' => $conflictByQ[$q['id']]['field'] ?? ($q['conflict_field'] ?? null),
            ];
        }
        return $qs;
    }

    /** One item with the extras the screen shows (source cells, update key, stock on hand and the cost-on-stock warning). */
    function import_review_enrich_item(array $it, array $rowSrc, array $existingById, array $batchTotals): array
    {
        $bal = [];
        $max = null;
        $min = null;
        foreach ($it['uids'] as $uid) {
            foreach ($rowSrc[$uid]['qty_sources'] ?? [] as $s) {
                $bal[] = $s;
            }
            $max = $max ?? ($rowSrc[$uid]['max'] ?? null);
            $min = $min ?? ($rowSrc[$uid]['min'] ?? null);
        }
        $it['balance_sources'] = array_values(array_unique($bal));
        $it['max_source'] = $max;
        $it['min_source'] = $min;
        $it['update_key'] = $it['match'] ? rh_ssi_key((string)$it['match']['name']) : null;    // the key import_job_set_update_fields wants
        $it['on_hand'] = null;
        $it['cost_stock_warning'] = null;
        if ($it['match'] && isset($it['diff']['cost'])) {
            $it['on_hand'] = import_item_on_hand($existingById, $batchTotals, (int)$it['match']['id']);
            if (in_array('cost', (array)$it['ticked'], true) && $it['on_hand'] && $it['on_hand']['has_stock']) {
                $it['cost_stock_warning'] = import_cost_stock_warning((string)$it['match']['name'], $it['on_hand'], $it['diff']['cost'][0], $it['diff']['cost'][1]);
            }
        }
        return $it;
    }

    /** Per-sheet block of the summary (columns, categories, headings and totals that are not items). */
    function import_review_sheets_payload(array $an): array
    {
        return array_map(function ($sh) {
            return ['name' => $sh['name'], 'action' => $sh['triage']['action'], 'reason' => $sh['triage']['reason'], 'counts' => $sh['counts'],
                'ignored_columns' => $sh['ignored_columns'], 'unmapped_columns' => $sh['unmapped_columns'],
                'header_row' => $sh['header_row'] ?? null, 'hidden' => !empty($sh['hidden']), 'categories' => $sh['categories'] ?? [],
                'columns' => array_map(function ($c) {
                    return ['letter' => $c['letter'], 'header' => $c['header'], 'ref' => $c['header_ref'], 'field' => $c['field'], 'derived' => !empty($c['derived']),
                        'state' => $c['state'] ?? '', 'filled' => $c['shape']['filled'] ?? null];
                }, $sh['columns'] ?? []),
                'non_item_rows' => array_slice(array_map(function ($r) {
                    return ['row' => $r['row'], 'kind' => $r['kind'], 'label' => $r['label'], 'source' => $r['source'], 'reason' => $r['reason']];
                }, $sh['skipped_rows'] ?? []), 0, 300)];
        }, $an['sheets']);
    }

    /** Everything the review screen needs. Statuses here are computed now from the stored answers. */
    function import_job_review_summary(PDO $pdo, int $jobId): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state) {
            $j = import_job_get($pdo, $jobId);
            return ['ok' => false, 'error' => $j && $j['error_text'] ? (string)$j['error_text'] : 'That import cannot be reviewed.'];
        }
        $res = $state['res'];
        $an = $state['analysis'];
        $rowSrc = $state['row_src'] ?? import_review_row_sources($an);
        $existingById = [];
        foreach ($state['ctx']['existing'] as $e) {
            $existingById[(int)$e['id']] = $e;
        }
        $costIds = [];
        foreach ($res['items'] as $it) {
            if ($it['match'] && isset($it['diff']['cost'])) {
                $costIds[] = (int)$it['match']['id'];
            }
        }
        $batchTotals = import_batch_totals($pdo, $costIds);
        $items = [];
        foreach ($res['items'] as $it) {
            $items[] = import_review_enrich_item($it, $rowSrc, $existingById, $batchTotals);
        }
        $qs = import_review_question_payloads($state);
        return [
            'ok' => true, 'job' => [
                'id' => (int)$state['job']['id'], 'file' => $state['job']['original_name'], 'status' => $state['job']['status'], 'target' => $state['job']['target'],
                'created_at' => $state['job']['created_at'],
            ],
            'file' => $an['file'], 'warnings' => $an['warnings'] ?? [], 'totals' => $an['totals'] ?? [],
            'counts' => $res['counts'], 'by_sheet' => $res['by_sheet'], 'can_confirm' => $res['can_confirm'], 'why_not' => $res['why_not'],
            'open_sheet_questions' => $res['open_sheet_questions'], 'sheets' => import_review_sheets_payload($an),
            'items' => $items, 'rows' => array_values($res['rows']), 'questions' => $qs, 'unit_options' => $state['ctx']['unit_options'],
        ];
    }

    function import_job_csv(PDO $pdo, int $jobId): string
    {
        $state = import_job_state($pdo, $jobId);
        return $state ? import_resolve_csv($state['res']) : '';
    }

    function import_job_cancel(PDO $pdo, int $jobId, int $userId = 0): array
    {
        $job = import_job_get($pdo, $jobId);
        if (!$job) {
            return ['ok' => false, 'error' => 'That import was not found.'];
        }
        if (in_array($job['status'], ['committed', 'cancelled'], true)) {
            return ['ok' => false, 'error' => 'This import is already ' . $job['status'] . '.'];
        }
        $pdo->prepare("UPDATE import_jobs SET status = 'cancelled', stored_path = NULL WHERE id = ?")->execute([$jobId]);
        if ($job['stored_path']) {
            import_delete_stored((string)$job['stored_path']);
        }
        return ['ok' => true, 'error' => ''];
    }

    /**
     * The re-check commit runs on the SERVER, never trusting what the browser last showed:
     * the stored file must still have its recorded sha256; it is read again, analysed again with the same options, the stored
     * answers are applied, every proposed value is compared with its source cell, and the result must still be confirmable.
     * Returns ['ok'=>bool, 'error'=>string, 'analysis'=>..., 'res'=>..., 'ctx'=>..., 'violations'=>[...]].
     */
    function import_job_revalidate(PDO $pdo, array $job): array
    {
        $bad = function (string $m, array $v = []) {
            return ['ok' => false, 'error' => $m, 'violations' => $v];
        };
        $path = import_job_file_path($job);
        if ($path === '') {
            return $bad('The uploaded file is no longer on the server. Please upload it again.');
        }
        if (!hash_equals((string)$job['sha256'], (string)hash_file('sha256', $path))) {
            return $bad('The stored file does not match the file that was uploaded. Nothing was imported.');
        }
        $answers = import_job_answers($job);
        $ctx = import_job_ctx($pdo);
        $eng = import_job_engine_opts($pdo, $answers, $ctx);
        require_once __DIR__ . '/memory.php';
        $chk = import_mem_check($path, (string)$job['original_name'], $eng['aliases']);      // a plain message, not a fatal error, when the file cannot fit here
        if (!$chk['ok']) {
            return $bad($chk['message']);
        }
        $analysis = import_analyze_file($path, (string)$job['original_name'], $eng['aliases'], $eng['opts']);
        if (empty($analysis['ok'])) {
            return $bad((string)$analysis['error']);
        }
        $read = import_read_cells($path, (string)$job['original_name']);
        if (empty($read['ok'])) {
            return $bad((string)($read['error'] ?? 'The file could not be read again.'));
        }
        $violations = import_assert_no_invented_values($analysis, $read);
        unset($read);                     // the second cell grid is not needed any more
        if ($violations) {
            return $bad('A value in the preview does not match the file, so nothing was imported.', array_slice($violations, 0, 20));
        }
        $res = import_resolve_inplace($analysis, $answers, $ctx, true);      // rows are released as they are read; the commit only uses the resolved items
        if (!$res['can_confirm']) {
            return $bad($res['why_not'] !== '' ? $res['why_not'] : 'Some questions still need an answer.') + ['analysis' => $analysis, 'res' => $res, 'ctx' => $ctx];
        }
        // final sanity on what would be written
        $viol = [];
        foreach ($res['items'] as $it) {
            if ($it['status'] !== 'ready') {
                continue;
            }
            $label = $it['name'];
            foreach (import_limit_problems($it) as $lp) {      // manual-Add limits: name 100, category 50, unit 20 (values already cleaned by rh_clean_text)
                $viol[] = $label . ': ' . $lp;
            }
            if (!$it['match'] && ($it['unit'] === null || trim((string)$it['unit']) === '')) {
                $viol[] = $label . ': no usable unit';
            }
            foreach (import_cost_storage_problems($it, import_cost_limits_from_ctx($ctx)) as $sp) {     // the physical limit, checked again here (decimal overflow rolls the whole commit back)
                $viol[] = $label . ': ' . $sp;
            }
            foreach (['cost', 'balance', 'max'] as $n) {
                if ($it[$n] !== null && (!is_numeric($it[$n]) || $it[$n] < 0)) {
                    $viol[] = $label . ': ' . $n . ' is not a non-negative number';
                }
            }
            if ($it['pack'] !== null && ((int)$it['pack'] < 2 || (int)$it['pack'] > 100000)) {
                $viol[] = $label . ': pack size out of range';
            }
        }
        if ($viol) {
            return $bad('Some values cannot be imported.', $viol);
        }
        return ['ok' => true, 'error' => '', 'analysis' => $analysis, 'res' => $res, 'ctx' => $ctx, 'violations' => []];
    }
}

require_once __DIR__ . '/continue.php';
