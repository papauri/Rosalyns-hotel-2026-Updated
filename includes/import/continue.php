<?php
/**
 * Import - leaving rows out, choosing units item by item, taking a self-healing fix back, and the lists Step 4 shows.
 *
 *   import_job_skip($pdo, $jobId, $p, $userId)        skip rows a person could not fix: mode uids (one entry), blocked (all blocked rows), open (all rows still
 *                                                     waiting for an answer or blocked). dry = describe only. Apply re-checks the set under the job row lock.
 *   import_job_unskip($pdo, $jobId, $uids, $userId)   take a skip back (the row is asked again)
 *   import_job_unit_items($pdo, $jobId, $sheet, $p)   the new items of one sheet that have no unit, each with its suggestion (search / filter / page)
 *   import_job_unit_choice($pdo, $jobId, $p, $userId) choose units: "suggested" (every item whose evidence is clear) or explicit {item key: unit}
 *   import_job_heal_undo($pdo, $jobId, $healId, $undo, $userId)   take a "Fixed for you" repair back (or put it back)
 *   import_job_list_view($pdo, $jobId, $kind, $p)     skipped rows / repairs for Step 4 (grouped, searchable, paged)
 *   import_job_skipped_export($pdo, $jobId, $format)  the skipped rows as a file the person can fix and import later (csv | xlsx)
 *   import_learned_rows($items)                       what a COMMIT teaches the matcher (units / categories a person confirmed); written by commit-stock.php only
 *
 * Skipped rows are stored as answers_json['_skip_rows'][uid] = {why, code, grp, by, at}. The resolver turns them into status "skipped"
 * (import_resolve_inplace) so they never become items and never reach the database; commit re-resolves with the same answers.
 * The browser sends row ids and choices only; every id is checked against the job's own rows, and statuses are recomputed here.
 */

require_once __DIR__ . '/review.php';

if (!function_exists('import_job_skip')) {

    /** Plain words for the reason a row was blocked / needed an answer (grouping in Step 3 and Step 4). */
    function import_cont_code_labels(): array
    {
        return [
            'bad-value' => 'A value in the row cannot be used (not a number, negative, a bad date, or a name that is too long)',
            'unit-clash' => 'The unit in the file is not the unit this ingredient has in stock',
            'archived-match' => 'The name matches an archived ingredient',
            'ambiguous-match' => 'The name could be several ingredients in stock',
            'cost-too-large' => 'The price is too large to be stored',
            'value-too-long' => 'A name, category or unit is too long',
            'same-existing-match' => 'Two rows in the file match the same ingredient in stock',
            'separate-items' => 'Two different items have the same name',
            'no-unit' => 'No unit was chosen',
            'conflict' => 'The file gives two different prices or units for one item',
            'merge-conflict' => 'The rows of one item give different prices or units',
            'duplicate-no-batch' => 'The same item is listed more than once',
            'duplicate-batch-clash' => 'The same item is listed more than once',
            'same-item-two-sheets' => 'The same item is on more than one sheet',
            'case-price' => 'The price may be for a case, not one unit',
            'price-check' => 'The price looks unusual',
            'ambiguous-date' => 'The expiry date could be read two ways',
            'formula-name' => 'The name starts with = + - or @',
            'section-or-item' => 'It is not clear if the row is a heading or an item',
            'possible-typo' => 'The name may be a typing slip',
            'needs-answer' => 'A question about it is still open',
            'answered-skip' => 'You answered skip',
            'by-answer' => 'Left out because of an answer you gave',
            'chosen' => 'You chose to leave it out',
        ];
    }

    function import_cont_code_label(string $code): string
    {
        return import_cont_code_labels()[$code] ?? $code;
    }

    /** uid => [open blocking questions that name it], for the questions of the file and the resolver's own (no-unit, unit-clash ...). */
    function import_cont_open_questions_by_uid(array $res): array
    {
        $by = [];
        foreach (array_merge($res['questions'], $res['extra_questions']) as $q) {
            if (empty($q['blocking']) || ($q['answer'] ?? null) !== null) {
                continue;
            }
            foreach ((array)($q['row_uids'] ?? []) as $u) {
                $by[(string)$u][] = $q;
            }
        }
        return $by;
    }

    /**
     * The entries a skip can act on: one per item and one per row that is no item, with the rows they cover. $statuses = which statuses count.
     * Returns a list of ['uids'=>[...], 'name', 'sheet', 'ref', 'status', 'code', 'why'].
     */
    function import_cont_entries(array $state, array $statuses): array
    {
        $res = $state['res'];
        $oq = import_cont_open_questions_by_uid($res);
        $describe = function (array $uids, string $fallbackReason, string $blockedCode) use ($oq) {
            $texts = [];
            $bad = '';
            $first = '';
            foreach ($uids as $u) {
                foreach ($oq[(string)$u] ?? [] as $q) {
                    $texts[$q['type'] === 'no-unit' ? 'No unit was chosen for this item.' : $q['text']] = true;
                    $first = $first !== '' ? $first : (string)$q['type'];
                    $bad = $bad !== '' || empty($q['bad']) ? $bad : (string)$q['type'];
                }
            }
            if (!$texts && $fallbackReason !== '') {
                $texts[$fallbackReason] = true;
            }
            $code = $bad !== '' ? $bad : ($first !== '' ? $first : ($blockedCode !== '' ? $blockedCode : 'needs-answer'));
            return [$code, mb_substr(implode(' ', array_slice(array_keys($texts), 0, 2)), 0, 300)];
        };
        $out = [];
        $inItem = [];
        foreach ($res['items'] as $it) {
            foreach ($it['uids'] as $u) {
                $inItem[$u] = true;
            }
            if (!in_array($it['status'], $statuses, true)) {
                continue;
            }
            [$code, $why] = $describe($it['uids'], '', '');
            $out[] = ['uids' => array_values($it['uids']), 'name' => (string)$it['typed_name'], 'sheet' => (string)$it['sheet'], 'ref' => (string)$it['name_source'], 'status' => (string)$it['status'], 'code' => $code, 'why' => $why];
        }
        foreach ($res['rows'] as $uid => $r) {
            if (isset($inItem[$uid]) || !in_array($r['status'], $statuses, true)) {
                continue;
            }
            [$code, $why] = $describe([$uid], (string)($r['reason'] ?? ''), (string)($r['blocked_code'] ?? ''));
            $out[] = ['uids' => [(string)$uid], 'name' => (string)$r['name'], 'sheet' => (string)$r['sheet'], 'ref' => (string)$r['ref'], 'status' => (string)$r['status'], 'code' => $code, 'why' => $why];
        }
        return $out;
    }

    function import_cont_hash(array $parts): string
    {
        return substr(sha1(implode("\x1e", array_map('strval', $parts))), 0, 24);
    }

    /** Merge skip records for $entries into $answers (pure). Returns the number of rows newly skipped. */
    function import_cont_apply_skips(array &$answers, array $entries, int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $n = 0;
        foreach ($entries as $e) {
            $grp = (string)$e['uids'][0];
            foreach ($e['uids'] as $u) {
                if (isset($answers['_skip_rows'][$u])) {
                    continue;
                }
                $answers['_skip_rows'][$u] = ['why' => (string)$e['why'], 'code' => (string)$e['code'], 'grp' => $grp, 'by' => $userId > 0 ? $userId : null, 'at' => $now];
                $n++;
            }
        }
        return $n;
    }

    /** Write the new answers_json under the job row lock, but only if nobody changed the answers since $baseHash was taken. */
    function import_cont_save(PDO $pdo, int $jobId, string $baseHash, callable $mutate): array
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare('SELECT answers_json, status FROM import_jobs WHERE id = ? FOR UPDATE');
            $st->execute([$jobId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || !in_array($row['status'], ['analysed', 'reviewing'], true)) {
                throw new RuntimeException('This import can no longer be changed.');
            }
            if ($baseHash !== '' && !hash_equals($baseHash, md5((string)$row['answers_json']))) {
                throw new RuntimeException('Somebody changed this import while you were looking at it, so nothing was changed. Reload the list and check the new counts.');
            }
            $answers = $row['answers_json'] !== null && $row['answers_json'] !== '' ? json_decode((string)$row['answers_json'], true) : [];
            $answers = is_array($answers) ? $answers : [];
            $res = $mutate($answers);
            $pdo->prepare("UPDATE import_jobs SET answers_json = ?, status = 'reviewing' WHERE id = ? AND status IN ('analysed','reviewing')")->execute([import_json($answers), $jobId]);
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $ex) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $ex instanceof RuntimeException ? $ex->getMessage() : 'The change could not be saved. Nothing was changed.'];
        }
        return ['ok' => true] + (is_array($res) ? $res : []);
    }

    /**
     * Skip rows. $p: mode 'uids' (uids = ids of one list entry), 'blocked' or 'open'; dry (bool); fingerprint (from the dry run).
     * The dry run says how many rows, which entries (names, cells, plain reasons) and whether a question about how a sheet is read is still
     * open (those are answered in step 2, never dropped here). Apply refuses when the set or the answers changed since the dry run.
     */
    function import_job_skip(PDO $pdo, int $jobId, array $p, int $userId = 0): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state) {
            return ['ok' => false, 'error' => 'That import cannot be reviewed.'];
        }
        if (!in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return ['ok' => false, 'error' => 'This import is already ' . $state['job']['status'] . ' and cannot be changed.'];
        }
        $mode = (string)($p['mode'] ?? '');
        if (!in_array($mode, ['uids', 'blocked', 'open'], true)) {
            return ['ok' => false, 'error' => 'Choose which rows to skip.'];
        }
        $all = import_cont_entries($state, $mode === 'blocked' ? ['blocked'] : ['needs-answer', 'blocked']);
        if ($mode === 'uids') {
            $want = array_flip(array_map('strval', (array)($p['uids'] ?? [])));
            $all = array_values(array_filter($all, function ($e) use ($want) {
                foreach ($e['uids'] as $u) {
                    if (isset($want[$u])) {
                        return true;
                    }
                }
                return false;
            }));
            if (!$all) {
                return ['ok' => false, 'error' => 'That row is not waiting for an answer, so there is nothing to skip.'];
            }
        }
        $uids = [];
        foreach ($all as $e) {
            foreach ($e['uids'] as $u) {
                $uids[] = $u;
            }
        }
        sort($uids);
        $answersHash = md5((string)($state['job']['answers_json'] ?? ''));
        $fp = import_cont_hash(array_merge([$mode, $answersHash], $uids));
        $groups = [];
        foreach ($all as $e) {
            $groups[$e['code']] = ($groups[$e['code']] ?? 0) + 1;
        }
        $structural = count($state['res']['open_sheet_questions'] ?? []);
        $report = [
            'entries' => count($all), 'rows' => count($uids), 'structural_open' => $structural, 'fingerprint' => $fp,
            'groups' => array_map(function ($c, $n) {
                return ['code' => $c, 'label' => import_cont_code_label($c), 'n' => $n];
            }, array_keys($groups), array_values($groups)),
            'samples' => array_map(function ($e) {
                return ['name' => $e['name'], 'sheet' => $e['sheet'], 'ref' => $e['ref'], 'why' => $e['why'], 'code' => $e['code']];
            }, array_slice($all, 0, 40)),
        ];
        if (!empty($p['dry'])) {
            return ['ok' => true, 'dry' => true] + $report;
        }
        if (!$all) {
            return ['ok' => true, 'skipped_now' => 0] + $report;
        }
        if ($structural > 0 && $mode !== 'uids') {      // the rule the screen shows, enforced here too: answer the sheet-wide questions first
            return ['ok' => false, 'error' => 'Answer the questions that apply to a whole sheet first (they are listed at the top of Step 3), then skip the rest.'] + $report;
        }
        if (!hash_equals($fp, (string)($p['fingerprint'] ?? '')) && $mode !== 'uids') {
            return ['ok' => false, 'error' => 'The list changed since you looked at it, so nothing was skipped. Check the new count and try again.'];
        }
        $r = import_cont_save($pdo, $jobId, $answersHash, function (&$answers) use ($all, $userId) {
            return ['skipped_now' => import_cont_apply_skips($answers, $all, $userId)];
        });
        return $r + $report;
    }

    /** Skip the rows of ONE question (the "Skip this row" choice on a question card). Called by import_job_answer. */
    function import_job_skip_question(PDO $pdo, int $jobId, array $state, array $q, int $userId): array
    {
        $want = array_flip(array_map('strval', (array)($q['row_uids'] ?? [])));
        $entries = array_values(array_filter(import_cont_entries($state, ['needs-answer', 'blocked', 'ready']), function ($e) use ($want) {
            foreach ($e['uids'] as $u) {
                if (isset($want[$u])) {
                    return true;
                }
            }
            return false;
        }));
        if (($q['type'] ?? '') === 'no-unit') {
            // "skip these items" on the sheet's unit question leaves out only the items still waiting for a unit: one the person already gave a unit stays
            $keep = [];
            foreach ($state['res']['items'] as $it) {
                if (!empty($it['unit_confirmed'])) {
                    foreach ($it['uids'] as $u) {
                        $keep[(string)$u] = true;
                    }
                }
            }
            $entries = array_values(array_filter($entries, function ($e) use ($keep) {
                foreach ($e['uids'] as $u) {
                    if (isset($keep[$u])) {
                        return false;
                    }
                }
                return true;
            }));
        }
        if (!$entries) {
            return ['ok' => false, 'error' => 'Nothing to skip: the rows of this question are already out.'];
        }
        foreach ($entries as &$e) {
            $e['why'] = mb_substr((string)$q['text'], 0, 300);
            $e['code'] = (string)$q['type'];
        }
        unset($e);
        return import_cont_save($pdo, $jobId, '', function (&$answers) use ($entries, $userId) {
            return ['skipped_now' => import_cont_apply_skips($answers, $entries, $userId)];
        });
    }

    function import_job_unskip(PDO $pdo, int $jobId, array $uids, int $userId = 0): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state || !in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return ['ok' => false, 'error' => 'That import cannot be changed.'];
        }
        $want = array_flip(array_map('strval', $uids));
        return import_cont_save($pdo, $jobId, '', function (&$answers) use ($want) {
            $groups = [];
            foreach ((array)($answers['_skip_rows'] ?? []) as $u => $rec) {
                if (isset($want[(string)$u])) {
                    $groups[(string)($rec['grp'] ?? $u)] = true;
                }
            }
            $n = 0;
            foreach ((array)($answers['_skip_rows'] ?? []) as $u => $rec) {
                if (isset($groups[(string)($rec['grp'] ?? $u)])) {
                    unset($answers['_skip_rows'][$u]);
                    $n++;
                }
            }
            if (empty($answers['_skip_rows'])) {
                unset($answers['_skip_rows']);
            }
            return ['restored' => $n];
        });
    }

    // ---------------------------------------------------------------- units, item by item

    /** One compact record per new item of the sheet that has no unit (what the question card lists). */
    function import_cont_unit_item(array $it): array
    {
        $sg = $it['unit_suggestion'] ?? null;
        $state = !empty($it['unit_confirmed']) ? 'chosen' : (!$sg ? 'ask' : (!empty($sg['mixed']) ? 'mixed' : ($sg['value'] !== null && in_array($sg['confidence'], ['high', 'medium'], true) ? 'suggested' : 'ask')));
        return [
            'nk' => (string)$it['key'], 'name' => (string)$it['name'], 'row' => (string)preg_replace('/^.*![A-Za-z]+/', '', (string)($it['rows'][0] ?? '')), 'rows' => count($it['rows']), 'source' => (string)$it['name_source'],
            'category' => (string)$it['category'], 'category_source' => $it['category_source'], 'state' => $state, 'unit' => !empty($it['unit_confirmed']) ? (string)$it['unit'] : null,
            'unit_how' => !empty($it['unit_confirmed']) ? (string)$it['unit_how'] : null,
            'suggestion' => $sg ? ['value' => $sg['value'], 'reason' => $sg['reason'], 'confidence' => $sg['confidence'], 'mixed' => !empty($sg['mixed']), 'candidates' => $sg['candidates'], 'evidence' => $sg['evidence']] : null,
        ];
    }

    function import_cont_unit_stats(array $res, string $sheet): array
    {
        $st = ['total' => 0, 'chosen' => 0, 'suggested' => 0, 'mixed' => 0, 'ask' => 0];
        foreach ($res['no_unit_index'][$sheet] ?? [] as $i) {
            $st['total']++;
            $st[import_cont_unit_item($res['items'][$i])['state']]++;
        }
        return $st;
    }

    /** The first few items still waiting for a unit (the card shows these; "show all" asks for the rest through import_job_unit_items). */
    function import_cont_unit_preview(array $res, string $sheet, int $max = 8): array
    {
        $out = [];
        foreach ($res['no_unit_index'][$sheet] ?? [] as $i) {
            $r = import_cont_unit_item($res['items'][$i]);
            if ($r['state'] === 'chosen') {
                continue;
            }
            $out[] = $r;
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    function import_job_unit_items(PDO $pdo, int $jobId, string $sheet, array $p = []): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state) {
            return ['ok' => false, 'error' => 'That import cannot be reviewed.'];
        }
        $res = $state['res'];
        if (!isset($res['no_unit_index'][$sheet])) {
            return ['ok' => false, 'error' => 'That sheet has no items waiting for a unit.'];
        }
        $q = mb_strtolower(trim((string)($p['q'] ?? '')));
        $which = (string)($p['which'] ?? 'all');
        $size = max(10, min(200, (int)($p['size'] ?? 50)));
        $rows = [];
        $counts = ['all' => 0, 'ask' => 0, 'mixed' => 0, 'suggested' => 0, 'chosen' => 0];
        foreach ($res['no_unit_index'][$sheet] as $i) {
            $r = import_cont_unit_item($res['items'][$i]);
            if ($q !== '' && mb_strpos(mb_strtolower($r['name'] . "\n" . $r['category'] . "\n" . $r['source'] . "\n" . ($r['unit'] ?? '')), $q) === false) {
                continue;
            }
            $counts['all']++;
            $counts[$r['state']]++;
            if ($which === 'all' || $which === $r['state']) {
                $rows[] = $r;
            }
        }
        $total = count($rows);
        $pages = max(1, (int)ceil($total / $size));
        $page = max(1, min($pages, (int)($p['page'] ?? 1)));
        return ['ok' => true, 'items' => array_slice($rows, ($page - 1) * $size, $size), 'total' => $total, 'page' => $page, 'pages' => $pages, 'counts' => $counts, 'unit_options' => $state['ctx']['unit_options']];
    }

    /**
     * Choose units for new items. $p: sheet; mode 'suggested' (every item whose suggestion is high or medium evidence and not mixed) or 'pick' with
     * choices {item key: unit} ('' clears a choice); dry. Units must be in this hotel's unit list; item keys must be items of that sheet that
     * are waiting for a unit. Nothing is applied by a suggestion on its own: this is the click.
     */
    function import_job_unit_choice(PDO $pdo, int $jobId, array $p, int $userId = 0): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state) {
            return ['ok' => false, 'error' => 'That import cannot be reviewed.'];
        }
        if (!in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return ['ok' => false, 'error' => 'This import is already ' . $state['job']['status'] . ' and cannot be changed.'];
        }
        $sheet = (string)($p['sheet'] ?? '');
        $res = $state['res'];
        $idx = $res['no_unit_index'][$sheet] ?? null;
        if ($idx === null) {
            return ['ok' => false, 'error' => 'That sheet has no items waiting for a unit.'];
        }
        $byKey = [];
        foreach ($idx as $i) {
            $byKey[(string)$res['items'][$i]['key']] = $res['items'][$i];
        }
        $opts = array_map('strval', (array)$state['ctx']['unit_options']);
        $set = [];          // item key => [unit, src] ; unit '' = clear
        if (($p['mode'] ?? '') === 'suggested') {
            foreach ($byKey as $nk => $it) {
                $r = import_cont_unit_item($it);
                if ($r['state'] === 'suggested') {
                    $set[$nk] = [(string)$r['suggestion']['value'], 'suggestion'];
                }
            }
        } else {
            foreach ((array)($p['choices'] ?? []) as $nk => $u) {
                $nk = (string)$nk;
                $u = trim((string)$u);
                if (!isset($byKey[$nk])) {
                    return ['ok' => false, 'error' => 'One of those items is not waiting for a unit any more. Reload the list.'];
                }
                if ($u !== '') {
                    $hit = null;
                    foreach ($opts as $o) {
                        if (mb_strtolower($o) === mb_strtolower($u)) {
                            $hit = $o;
                            break;
                        }
                    }
                    if ($hit === null) {
                        return ['ok' => false, 'error' => '"' . $u . '" is not one of the units in your list.'];
                    }
                    $u = $hit;
                }
                $set[$nk] = [$u, 'pick'];
            }
            if (!$set) {
                return ['ok' => false, 'error' => 'Choose a unit first.'];
            }
        }
        $names = [];
        foreach (array_slice(array_keys($set), 0, 5) as $nk) {
            $names[] = (string)$byKey[$nk]['name'];
        }
        $answersHash = md5((string)($state['job']['answers_json'] ?? ''));
        $fp = import_cont_hash(array_merge([$sheet, $p['mode'] ?? '', $answersHash], array_map(function ($k, $v) {
            return $k . '=' . $v[0];
        }, array_keys($set), array_values($set))));
        $report = ['n' => count($set), 'samples' => $names, 'fingerprint' => $fp];
        if (!empty($p['dry'])) {
            return ['ok' => true, 'dry' => true] + $report;
        }
        if (!$set) {
            return ['ok' => true, 'applied' => 0] + $report;
        }
        if (($p['mode'] ?? '') === 'suggested' && !hash_equals($fp, (string)($p['fingerprint'] ?? ''))) {
            return ['ok' => false, 'error' => 'The suggestions changed since you looked at them, so nothing was applied. Check them again.'];
        }
        $r = import_cont_save($pdo, $jobId, $answersHash, function (&$answers) use ($set, $userId) {
            $now = date('Y-m-d H:i:s');
            foreach ($set as $nk => [$u, $src]) {
                if ($u === '') {
                    unset($answers['_unit_for'][$nk]);
                } else {
                    $answers['_unit_for'][$nk] = ['unit' => $u, 'src' => $src, 'by' => $userId > 0 ? $userId : null, 'at' => $now];
                }
            }
            if (empty($answers['_unit_for'])) {
                unset($answers['_unit_for']);
            }
            return ['applied' => count($set)];
        });
        return $r + $report;
    }

    // ---------------------------------------------------------------- "Fixed for you": take a repair back

    function import_job_heal_undo(PDO $pdo, int $jobId, string $healId, bool $undo, int $userId = 0): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state || !in_array($state['job']['status'], ['analysed', 'reviewing'], true)) {
            return ['ok' => false, 'error' => 'That import cannot be changed.'];
        }
        $res = $state['res'];
        $found = null;
        foreach ($undo ? $res['heals'] : $res['heals_undone'] as $h) {
            if ((string)$h['id'] === $healId) {
                $found = $h;
                break;
            }
        }
        if (!$found) {
            return ['ok' => false, 'error' => 'That fix is not in this import any more. Reload the page.'];
        }
        if ($undo && empty($found['undoable'])) {
            return ['ok' => false, 'error' => 'That fix cannot be taken back.'];
        }
        $r = import_cont_save($pdo, $jobId, '', function (&$answers) use ($found, $healId, $undo, $userId) {
            if ($undo) {
                $answers['_heal_undo'][$healId] = ['rule' => (string)$found['rule'], 'field' => (string)($found['field'] ?? ''), 'sheet' => (string)($found['sheet'] ?? ''), 'row' => $found['row'] ?? null,
                    'source' => (string)($found['source'] ?? ''), 'before' => (string)$found['before'], 'after' => (string)$found['after'], 'text' => (string)($found['text'] ?? ''),
                    'undoable' => true, 'by' => $userId > 0 ? $userId : null, 'at' => date('Y-m-d H:i:s')];
            } else {
                unset($answers['_heal_undo'][$healId]);
                if (empty($answers['_heal_undo'])) {
                    unset($answers['_heal_undo']);
                }
            }
            return [];
        });
        if (!$r['ok']) {
            return $r;
        }
        $reread = in_array(($found['rule'] ?? ''), ['number-unit', 'number-format'], true);
        if ($reread) {          // these repairs happen while the file is read: read it again without (or with) it
            $a = import_job_analyse($pdo, $jobId);
            if (empty($a['ok'])) {
                return ['ok' => false, 'error' => (string)$a['error']];
            }
        }
        return ['ok' => true, 'reanalysed' => $reread];
    }

    // ---------------------------------------------------------------- Step 4 lists

    /** Rows left out (with the plain reason they were left out) or repairs, filtered / searched / paged. $kind: skipped | heals. */
    function import_job_list_view(PDO $pdo, int $jobId, string $kind, array $p = []): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state) {
            return ['ok' => false, 'error' => 'That import cannot be reviewed.'];
        }
        $res = $state['res'];
        $q = mb_strtolower(trim((string)($p['q'] ?? '')));
        $code = (string)($p['code'] ?? '');
        $size = max(10, min(200, (int)($p['size'] ?? 50)));
        $rows = [];
        $groups = [];
        if ($kind === 'heals') {
            $rules = ['unit-spelling' => 'Unit spelled the way your stock spells it', 'number-unit' => 'Quantity written with its unit (like "12 pcs")', 'number-format' => 'Number written with a separator, a decimal comma or a currency word'];
            foreach (array_merge($res['heals'], $res['heals_undone']) as $h) {
                $hay = mb_strtolower($h['before'] . "\n" . $h['after'] . "\n" . ($h['source'] ?? '') . "\n" . ($h['sheet'] ?? '') . "\n" . ($h['item'] ?? ''));
                if ($q !== '' && mb_strpos($hay, $q) === false) {
                    continue;
                }
                $groups[$h['rule']] = ($groups[$h['rule']] ?? 0) + 1;
                if ($code !== '' && $code !== $h['rule']) {
                    continue;
                }
                $rows[] = ['id' => (string)$h['id'], 'rule' => (string)$h['rule'], 'label' => $rules[$h['rule']] ?? (string)$h['rule'], 'sheet' => (string)($h['sheet'] ?? ''), 'row' => $h['row'] ?? null,
                    'source' => (string)($h['source'] ?? ''), 'item' => (string)($h['item'] ?? ''), 'before' => (string)$h['before'], 'after' => (string)$h['after'], 'text' => (string)($h['text'] ?? ''),
                    'undoable' => !empty($h['undoable']), 'undone' => !empty($h['undone'])];
            }
            $groupOut = array_map(function ($c, $n) use ($rules) {
                return ['code' => $c, 'label' => $rules[$c] ?? $c, 'n' => $n];
            }, array_keys($groups), array_values($groups));
        } else {
            $skipRows = (array)($state['answers']['_skip_rows'] ?? []);
            foreach ($res['rows'] as $uid => $r) {
                if ($r['status'] !== 'skipped') {
                    continue;
                }
                $c = (string)($r['skip_code'] ?? 'by-answer');
                $why = (string)($r['skipped_why'] ?? '') !== '' ? (string)$r['skipped_why'] : (string)$r['reason'];
                $hay = mb_strtolower($r['name'] . "\n" . $r['sheet'] . "\n" . $r['ref'] . "\n" . $why);
                if ($q !== '' && mb_strpos($hay, $q) === false) {
                    continue;
                }
                $groups[$c] = ($groups[$c] ?? 0) + 1;
                if ($code !== '' && $code !== $c) {
                    continue;
                }
                $rows[] = ['uid' => (string)$uid, 'name' => (string)$r['name'], 'sheet' => (string)$r['sheet'], 'row' => $r['row'], 'ref' => (string)$r['ref'], 'code' => $c,
                    'label' => import_cont_code_label($c), 'why' => $why, 'by_you' => isset($skipRows[$uid]), 'at' => $skipRows[$uid]['at'] ?? null];
            }
            $groupOut = array_map(function ($c, $n) {
                return ['code' => $c, 'label' => import_cont_code_label($c), 'n' => $n];
            }, array_keys($groups), array_values($groups));
        }
        usort($groupOut, function ($a, $b) {
            return $b['n'] <=> $a['n'];
        });
        $total = count($rows);
        $pages = max(1, (int)ceil($total / $size));
        $page = max(1, min($pages, (int)($p['page'] ?? 1)));
        return ['ok' => true, 'kind' => $kind, 'rows' => array_slice($rows, ($page - 1) * $size, $size), 'total' => $total, 'page' => $page, 'pages' => $pages, 'groups' => $groupOut];
    }

    /** Counts for the lean overview (Step 3 / 4 cards): rows left out by reason, repairs by rule. */
    function import_cont_overview(array $res): array
    {
        $skip = [];
        $byYou = 0;
        foreach ($res['rows'] as $r) {
            if ($r['status'] === 'skipped') {
                $c = (string)($r['skip_code'] ?? 'by-answer');
                $skip[$c] = ($skip[$c] ?? 0) + 1;
                if (isset($r['skipped_why'])) {
                    $byYou++;
                }
            }
        }
        return ['skipped_by_code' => $skip, 'skipped_by_you' => $byYou, 'heal_counts' => $res['heal_counts'], 'heal_total' => array_sum($res['heal_counts']), 'heals_undone' => count($res['heals_undone'])];
    }

    /** Rows the engine handled by itself (not items): headings used as categories, total lines left out, notes / repeated headings / stray rows left out. */
    function import_cont_handled_rows(array $an): array
    {
        $o = ['sections' => 0, 'totals' => 0, 'notes' => 0];
        foreach ($an['sheets'] as $sh) {
            if (($sh['triage']['action'] ?? '') !== 'import') {
                continue;
            }
            foreach (array_keys($o) as $k) {
                $o[$k] += (int)($sh['counts'][$k] ?? 0);
            }
        }
        return $o;
    }

    // ---------------------------------------------------------------- download: the skipped rows, so they can be fixed and imported later

    /** The cell text as the file shows it (nothing converted). */
    function import_cont_cell_text($cell): string
    {
        if (!is_array($cell) || empty($cell['cached']) || $cell['v'] === null || is_bool($cell['v'])) {
            return '';
        }
        return (string)(($cell['raw'] !== null && $cell['raw'] !== '' && is_numeric($cell['v'])) ? $cell['raw'] : $cell['v']);
    }

    /**
     * Build the file. csv = one flat list (sheet, row, cell, name, why, what the row said cell by cell); xlsx = one tab per sheet with the sheet's own
     * heading row and the skipped rows below it, plus a first column "Why it was skipped", ready to fix and upload again.
     * Returns ['ok','name','mime','body'] or ['ok'=>false,'error'].
     */
    function import_job_skipped_export(PDO $pdo, int $jobId, string $format): array
    {
        $state = import_job_state($pdo, $jobId);
        if (!$state) {
            return ['ok' => false, 'error' => 'That import cannot be reviewed.'];
        }
        $res = $state['res'];
        $skipped = [];
        foreach ($res['rows'] as $uid => $r) {
            if ($r['status'] === 'skipped') {
                $skipped[(string)$uid] = $r;
            }
        }
        if (!$skipped) {
            return ['ok' => false, 'error' => 'No rows were skipped in this import.'];
        }
        require_once __DIR__ . '/memory.php';
        $job = $state['job'];
        $path = import_job_file_path($job);
        if ($path === '') {
            return ['ok' => false, 'error' => 'The uploaded file is no longer on the server, so the skipped rows cannot be copied from it.'];
        }
        $chk = import_mem_check($path, (string)$job['original_name'], import_load_aliases($pdo));
        if (!$chk['ok']) {
            return ['ok' => false, 'error' => $chk['message']];
        }
        $read = import_read_cells($path, (string)$job['original_name']);
        if (empty($read['ok'])) {
            return ['ok' => false, 'error' => 'The file could not be read again.'];
        }
        $headerRow = [];
        foreach ($state['analysis']['sheets'] as $sh) {
            $headerRow[(string)$sh['name']] = (int)($sh['header_row'] ?? 0);
        }
        $bySheet = [];
        foreach ($read['sheets'] as $sh) {
            $bySheet[(string)$sh['name']] = $sh;
        }
        $whyOf = function (array $r) {
            return (string)($r['skipped_why'] ?? '') !== '' ? (string)$r['skipped_why'] : (string)$r['reason'];
        };
        $rowCells = function (array $sh, int $row) {
            $out = [];
            foreach (($sh['cells'][$row] ?? []) as $c => $cell) {
                $out[(int)$c] = import_cont_cell_text($cell);
            }
            return $out;
        };
        $base = 'skipped-rows-import-' . $jobId;
        if ($format === 'csv') {
            $fh = fopen('php://memory', 'w+');
            fputcsv($fh, ['Sheet', 'Row in your file', 'Item as written', 'Why it was skipped', 'What the row said (cell by cell)']);
            foreach ($skipped as $r) {
                $sh = $bySheet[(string)$r['sheet']] ?? null;
                $cells = $sh ? $rowCells($sh, (int)$r['row']) : [];
                ksort($cells);
                $said = [];
                foreach ($cells as $c => $t) {
                    if ($t !== '') {
                        $said[] = import_num_to_col($c) . ': ' . $t;
                    }
                }
                fputcsv($fh, array_map('import_csv_safe', [$r['sheet'], $r['row'], $r['name'], $whyOf($r), implode(' | ', $said)]));
            }
            rewind($fh);
            return ['ok' => true, 'name' => $base . '.csv', 'mime' => 'text/csv; charset=utf-8', 'body' => "\xEF\xBB\xBF" . stream_get_contents($fh)];
        }
        require_once __DIR__ . '/export-xlsx.php';
        $W = new ImportXlsxWriter(['title' => 'Skipped rows', 'creator' => 'Import']);
        $byS = [];
        foreach ($skipped as $r) {
            $byS[(string)$r['sheet']][] = $r;
        }
        $made = 0;
        foreach ($byS as $sheetName => $list) {
            $sh = $bySheet[$sheetName] ?? null;
            if (!$sh) {
                continue;
            }
            $si = $W->addSheet($sheetName, ['gridlines' => true]);
            $hr = $headerRow[$sheetName] ?? 0;
            $line = 1;
            $put = function (array $cells, string $why, int $line) use ($W, $si) {
                $c = [1 => ['str' => $why]];
                foreach ($cells as $col => $t) {
                    if ($t === '') {
                        continue;
                    }
                    $nt = is_numeric($t) ? ImportXlsxWriter::numText($t) : null;
                    $c[$col + 1] = $nt !== null ? ['num' => $nt] : ['str' => $t];
                }
                $W->row($si, $line, $c);
            };
            if ($hr > 0) {
                $put($rowCells($sh, $hr), 'Why it was skipped', $line++);
            }
            usort($list, function ($a, $b) {
                return $a['row'] <=> $b['row'];
            });
            foreach ($list as $r) {
                $put($rowCells($sh, (int)$r['row']), $whyOf($r), $line++);
            }
            $made++;
        }
        unset($read);
        if (!$made) {
            return ['ok' => false, 'error' => 'The skipped rows could not be copied from the file.'];
        }
        $out = $W->finish();
        if (empty($out['ok'])) {
            return ['ok' => false, 'error' => 'The workbook could not be built.'];
        }
        rewind($out['stream']);
        $body = (string)stream_get_contents($out['stream']);
        fclose($out['stream']);
        return ['ok' => true, 'name' => $base . '.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'body' => $body];
    }

    // ---------------------------------------------------------------- learning (written by commit only)

    /**
     * What a COMMITTED import teaches the matcher: for each NEW item whose unit or category a person confirmed (a click, never a default), the item's name
     * -> unit / category; and category -> unit when at least 3 confirmed items of one category agree. Returns [[kind, term, maps_to], ...].
     */
    function import_learned_rows(array $items): array
    {
        $rows = [];
        $catUnits = [];
        foreach ($items as $it) {
            if (($it['status'] ?? '') !== 'ready' || !empty($it['match'])) {
                continue;
            }
            $nk = import_norm_text((string)$it['name']);
            if ($nk === '') {
                continue;
            }
            if (!empty($it['unit_confirmed']) && trim((string)$it['unit']) !== '') {
                $rows[] = ['learned_unit_item', $nk, (string)$it['unit']];
                $catUnits[import_norm_text((string)$it['category'])][(string)$it['unit']][] = 1;
            }
            if (!empty($it['category_confirmed']) && trim((string)$it['category']) !== '') {
                $rows[] = ['learned_category', $nk, (string)$it['category']];
            }
        }
        foreach ($catUnits as $ck => $units) {
            if ($ck !== '' && count($units) === 1 && count(reset($units)) >= 3) {
                $rows[] = ['learned_unit_cat', $ck, (string)array_key_first($units)];
            }
        }
        return array_slice($rows, 0, 3000);
    }
}
