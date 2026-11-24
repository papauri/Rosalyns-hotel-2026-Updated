<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Child of scripts/tests/import_lock_gaps.php (CONCURRENCY_PLAN task 4, 2026-11-25), run by page_harness.php: ONE outer transaction, rolled
 * back at the end. For each of the four review writes (the main answer, "Use this" on a column suggestion, one drink's count unit, the
 * "which fields may change" ticks): the normal save works; another write between the read and the save -> refused with the import_cont_save
 * message, nothing saved, nothing learned, the other person's change kept; the import no longer reviewable -> refused, nothing saved.
 * Every row it makes is named 'ZZT <run> ...' / 'zzt-lg...-<run>.csv'. Prints {"checks":[...]}.
 */
if (defined('DB_NAME') && stripos((string)DB_NAME, 'rosalyn') !== false) {
    echo json_encode(['checks' => [["refused: Rosalyn's is live, run this from the Liwonde repo", false, '']]]), "\n";
    exit;
}
$run = (string)($_POST['run'] ?? 'X');
$checks = [];
$ck = function (string $n, $c, string $d = '') use (&$checks) { $checks[] = [$n, (bool)$c, $d]; };
$made = [];
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'import_lock_gaps_' . getmypid();
@mkdir($tmp);
$tmpFiles = [];
const LG_CHANGED = 'Somebody changed this import while you were looking at it, so nothing was changed.';
const LG_CLOSED = 'This import can no longer be changed.';

try {
    require_once $root . '/admin/includes/permissions.php';
    require_once $root . '/includes/import/commit-stock.php';
    $n = function (string $s) use ($run) { return 'ZZT ' . $run . ' ' . $s; };
    $csvOf = function (array $rows): string { $fh = fopen('php://memory', 'w+'); foreach ($rows as $r0) { fputcsv($fh, $r0); } rewind($fh); return (string)stream_get_contents($fh); };
    $put = function (string $bytes, string $name) use ($tmp, &$tmpFiles) {
        $p = $tmp . DIRECTORY_SEPARATOR . count($tmpFiles) . '_' . $name;
        file_put_contents($p, $bytes);
        $tmpFiles[] = $p;
        return $p;
    };
    $admin = (int)($_SESSION['admin_user_id'] ?? 0);
    $job = function (array $rows, string $tag) use ($pdo, $put, $csvOf, $admin, $run, &$made) {
        $r = import_job_create($pdo, $put($csvOf($rows), $tag . '.csv'), 'zzt-' . $tag . '-' . $run . '.csv', $admin, ['from_upload' => false]);
        if (empty($r['ok'])) { throw new RuntimeException('upload ' . $tag . ': ' . $r['error']); }
        $made[] = (string)import_job_get($pdo, (int)$r['job_id'])['stored_path'];
        import_job_analyse($pdo, (int)$r['job_id']);
        return (int)$r['job_id'];
    };
    $answersOf = function (int $jobId) use ($pdo) { return import_job_answers(import_job_get($pdo, $jobId)); };
    // what "another person" does between the read and the save: a different answer, or the import is cancelled / committed meanwhile
    $otherWrite = function (int $jobId) use ($pdo) {
        return function () use ($pdo, $jobId) {
            import_cont_save($pdo, $jobId, '', function (array &$a) { $a['_zz_other'] = 1; return []; });
        };
    };
    $closeIt = function (int $jobId) use ($pdo) {
        return function () use ($pdo, $jobId) {
            $pdo->prepare("UPDATE import_jobs SET status = 'cancelled' WHERE id = ?")->execute([$jobId]);
        };
    };
    $learnedRow = function (string $kind, string $term, string $maps) use ($pdo) {
        $st = $pdo->prepare('SELECT created_at FROM import_aliases WHERE kind = ? AND term = ? AND maps_to = ? AND is_hotel_learned = 1');
        $st->execute([$kind, $term, $maps]);
        return $st->fetchColumn();
    };
    $refusedWith = function (array $r, string $msg) { return empty($r['ok']) && strpos((string)($r['error'] ?? ''), $msg) === 0; };
    $mqOf = function (array $s, string $head) { foreach ($s['questions'] as $q0) { if ($q0['type'] === 'mapping-unsure' && strpos($q0['text'], '"' . $head . '"') !== false) { return $q0; } } return null; };
    $colsOf = function (array $s) { $o = []; foreach ($s['sheets'][0]['columns'] ?? [] as $c0) { $o[$c0['header']] = $c0; } return $o; };
    $ck('setup: an administrator is signed in', $admin > 0);

    // ---- 1. the main answer (import_job_answer): "Shots" = measures per unit, learned for this hotel only once saved ----
    // a learned "shots" from earlier use is taken away inside this rolled-back run, so "not learned" can be seen
    $pdo->exec("DELETE FROM import_aliases WHERE is_hotel_learned = 1 AND kind IN ('field', 'field_yes', 'field_not') AND term = 'shots'");
    $hdrL = ['Item Name', 'Balance', 'Unit', 'Unit Price', 'Served By', 'Shots'];
    $ansJob = function (string $v) use ($job, $n, $hdrL) {
        return $job([$hdrL, [$n('LG Gin ' . $v), '2', 'bottle', '9000', 'Bottle', '30'], [$n('LG Rum ' . $v), '3', 'bottle', '9000', 'Bottle', '25']], 'lga' . $v);
    };
    foreach (['changed' => $otherWrite, 'closed' => $closeIt] as $v => $mk) {
        $jid = $ansJob($v);
        $q = $mqOf(import_job_review_summary($pdo, $jid), 'Shots');
        $r = $q ? import_job_answer($pdo, $jid, $q['id'], 'use:measures_per_unit', $admin, $mk($jid)) : ['ok' => false, 'error' => 'no Shots question'];
        $a = $answersOf($jid);
        $saved = isset($a[(string)($q['key'] ?? '-')]) || !empty($a['_overrides']['aliases']['measures_per_unit']);
        if ($v === 'changed') {
            $ck('answer: another write between the read and the save -> refused with the "somebody changed" message; nothing saved, nothing learned, the other change kept',
                $refusedWith($r, LG_CHANGED) && !$saved && !empty($a['_zz_other']) && $learnedRow('field', 'shots', 'measures_per_unit') === false, json_encode([$r, $a]));
        } else {
            $ck('answer: the import is no longer reviewable -> refused ("can no longer be changed"), nothing saved or learned',
                $refusedWith($r, LG_CLOSED) && !$saved && $learnedRow('field', 'shots', 'measures_per_unit') === false, json_encode([$r, $a]));
        }
    }
    $jid = $ansJob('ok');
    $q = $mqOf(import_job_review_summary($pdo, $jid), 'Shots');
    $r = $q ? import_job_answer($pdo, $jid, $q['id'], 'use:measures_per_unit', $admin) : ['ok' => false, 'error' => 'no Shots question'];
    $cols = $colsOf(import_job_review_summary($pdo, $jid));
    $ck('answer: the normal answer is saved, the column is read now and the heading is learned',
        !empty($r['ok']) && !empty($r['reanalysed']) && ($cols['Shots']['field'] ?? '') === 'measures_per_unit' && $learnedRow('field', 'shots', 'measures_per_unit') !== false,
        json_encode([$r, $cols['Shots'] ?? null]));
    // a plain (non-structural) answer: the one-group count question of a drink sold by the shot
    $hdrB = ['Item Name', 'Total Balance', 'Unit Price (MWK)', 'Selling price', 'Stock Value', 'Unit Bought As', 'Total Per Unit', 'Unit sold/Served by'];
    $barJob = function (string $v) use ($job, $n, $hdrB) {
        return $job([$hdrB, [$n('LG BR Gin ' . $v), 12, 30000, 2500, 30000, 'Bottle', 1, 'Shot']], 'lgb' . $v);
    };
    $countQ = function (int $jid) use ($pdo) {
        foreach (import_job_review_summary($pdo, $jid)['questions'] as $q0) { if ($q0['type'] === 'count-in') { return $q0; } }
        return null;
    };
    $jid = $barJob('ans');
    $q = $countQ($jid);
    $r = $q ? import_job_answer($pdo, $jid, $q['id'], 'stock', $admin, $otherWrite($jid)) : ['ok' => false, 'error' => 'no count question'];
    $a = $answersOf($jid);
    $ck('answer (plain): another write in between -> refused, the answer is not saved',
        $refusedWith($r, LG_CHANGED) && !isset($a[(string)($q['key'] ?? '-')]) && !empty($a['_zz_other']), json_encode([$r, $a]));
    $r = $q ? import_job_answer($pdo, $jid, $q['id'], 'stock', $admin) : ['ok' => false];
    $a = $answersOf($jid);
    $ck('answer (plain): asked again on the fresh answers, it is saved',
        !empty($r['ok']) && (($a[(string)($q['key'] ?? '-')]['key'] ?? '') === 'stock'), json_encode([$r, $q['key'] ?? null]));

    // ---- 2. "Use this" on a column suggestion (import_job_column_use) ----
    $pdo->exec("DELETE FROM import_aliases WHERE is_hotel_learned = 1 AND kind IN ('field', 'field_yes', 'field_not') AND term = 'tots in btl approx'");
    $hdrA = ['Item Name', 'Balance', 'Unit', 'Unit Price', 'Served By', 'Tots in btl (approx)'];
    $useJob = function (string $v) use ($pdo, $job, $n, $hdrA, $colsOf) {
        $jid = $job([$hdrA, [$n('LG AS Gin ' . $v), '2', 'bottle', '9000', 'Bottle', '30'], [$n('LG AS Rum ' . $v), '3', 'bottle', '9000', 'Bottle', '25']], 'lgc' . $v);
        $ref = (string)($colsOf(import_job_review_summary($pdo, $jid))['Tots in btl (approx)']['ref'] ?? '');
        $stub = function (array $items) use ($ref) {
            return [['key' => 'col:' . $ref, 'field' => 'column', 'value' => ['field' => 'measures_per_unit', 'measure' => 'tots', 'unit' => 'btl'], 'source' => 'Tots in btl']];
        };
        $nA = import_job_sizes_ai_fill($pdo, $jid, $stub, false, true);
        return [$jid, $ref, $nA];
    };
    foreach (['changed' => $otherWrite, 'closed' => $closeIt] as $v => $mk) {
        [$jid, $ref, $nA] = $useJob($v);
        $r = import_job_column_use($pdo, $jid, $ref, 'measures_per_unit', $admin, $mk($jid));
        $a = $answersOf($jid);
        $saved = !empty($a['_cols_ai'][$ref]['used']) || !empty($a['_overrides']['aliases']['measures_per_unit']);
        if ($v === 'changed') {
            $ck('use this: another write between the read and the save -> refused; not marked used, no override, nothing learned, the other change kept',
                $nA === 1 && $refusedWith($r, LG_CHANGED) && !$saved && !empty($a['_zz_other']) && $learnedRow('field', 'tots in btl approx', 'measures_per_unit') === false, json_encode([$nA, $r, $a]));
        } else {
            $ck('use this: the import is no longer reviewable -> refused, nothing saved or learned',
                $nA === 1 && $refusedWith($r, LG_CLOSED) && !$saved && $learnedRow('field', 'tots in btl approx', 'measures_per_unit') === false, json_encode([$nA, $r, $a]));
        }
    }
    // the suggestion itself was taken away in between (re-checked inside the save)
    [$jid, $ref, $nA] = $useJob('gone');
    $r = import_job_column_use($pdo, $jid, $ref, 'measures_per_unit', $admin, function () use ($pdo, $jid, $ref) {
        $st = $pdo->prepare('SELECT answers_json FROM import_jobs WHERE id = ?');
        $st->execute([$jid]);
        $a0 = json_decode((string)$st->fetchColumn(), true);
        unset($a0['_cols_ai'][$ref]);
        $pdo->prepare('UPDATE import_jobs SET answers_json = ? WHERE id = ?')->execute([json_encode($a0), $jid]);
    });
    $ck('use this: refused when the answers changed (here: the suggestion was removed), nothing learned',
        empty($r['ok']) && $learnedRow('field', 'tots in btl approx', 'measures_per_unit') === false, json_encode($r));
    [$jid, $ref, $nA] = $useJob('ok');
    $r = import_job_column_use($pdo, $jid, $ref, 'measures_per_unit', $admin);
    $a = $answersOf($jid);
    $cols = $colsOf(import_job_review_summary($pdo, $jid));
    $ck('use this: the normal click is saved (marked used), the column is read now and learned',
        !empty($r['ok']) && !empty($a['_cols_ai'][$ref]['used']) && ($cols['Tots in btl (approx)']['field'] ?? '') === 'measures_per_unit'
        && $learnedRow('field', 'tots in btl approx', 'measures_per_unit') !== false, json_encode([$r, $cols['Tots in btl (approx)'] ?? null]));

    // ---- 3. one drink's own count unit (import_job_count_row) ----
    foreach (['changed' => $otherWrite, 'closed' => $closeIt] as $v => $mk) {
        $jid = $barJob('row' . $v);
        $q = $countQ($jid);
        $uid = (string)($q['row_uids'][0] ?? '');
        $r = $q ? import_job_count_row($pdo, $jid, $q['id'], $uid, 'measure', $admin, $mk($jid)) : ['ok' => false, 'error' => 'no count question'];
        $a = $answersOf($jid);
        $ck('count row (' . $v . '): ' . ($v === 'changed' ? 'another write in between -> refused with the "somebody changed" message, the row choice not saved, the other change kept'
                : 'the import is no longer reviewable -> refused ("can no longer be changed"), nothing saved'),
            $uid !== '' && $refusedWith($r, $v === 'changed' ? LG_CHANGED : LG_CLOSED) && !isset($a['_count_in'][$uid]) && ($v !== 'changed' || !empty($a['_zz_other'])), json_encode([$r, $a]));
    }
    $jid = $barJob('rowok');
    $q = $countQ($jid);
    $uid = (string)($q['row_uids'][0] ?? '');
    $r = $q ? import_job_count_row($pdo, $jid, $q['id'], $uid, 'measure', $admin) : ['ok' => false];
    $a = $answersOf($jid);
    $r2 = $q ? import_job_count_row($pdo, $jid, $q['id'], $uid, '', $admin) : ['ok' => false];
    $a2 = $answersOf($jid);
    $ck('count row: the normal choice is saved, and "back to the group\'s answer" clears it',
        $uid !== '' && !empty($r['ok']) && ($a['_count_in'][$uid]['key'] ?? '') === 'measure' && !empty($r2['ok']) && !isset($a2['_count_in']), json_encode([$r, $a['_count_in'] ?? null, $r2]));

    // ---- 4. "which fields may change" on an existing ingredient (import_job_set_update_fields) ----
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, 'Pantry', 'bottle', 0, 700, 5, 2)")->execute([$n('LG Tickme')]);
    $key = rh_ssi_key($n('LG Tickme'));
    $tickJob = function (string $v) use ($job, $n) {
        return $job([['Item Name', 'Batch', 'Balance', 'Unit', 'Unit Price', 'Max Level', 'Expiry Date'], [$n('LG Tickme'), '', '30', 'bottle', '900', '', ''], [$n('LG New ' . $v), '', '1', 'bottle', '100', '', '']], 'lgd' . $v);
    };
    foreach (['changed' => $otherWrite, 'closed' => $closeIt] as $v => $mk) {
        $jid = $tickJob($v);
        $r = import_job_set_update_fields($pdo, $jid, $key, ['cost'], $admin, $mk($jid));
        $a = $answersOf($jid);
        $ck('fields (' . $v . '): ' . ($v === 'changed' ? 'another write in between -> refused with the "somebody changed" message, the tick not saved, the other change kept'
                : 'the import is no longer reviewable -> refused ("can no longer be changed"), nothing saved'),
            $refusedWith($r, $v === 'changed' ? LG_CHANGED : LG_CLOSED) && !isset($a['_updates'][$key]) && ($v !== 'changed' || !empty($a['_zz_other'])), json_encode([$r, $a]));
    }
    $jid = $tickJob('ok');
    $r = import_job_set_update_fields($pdo, $jid, $key, ['cost'], $admin);
    $a = $answersOf($jid);
    $r2 = import_job_set_update_fields($pdo, $jid, $key, [], $admin);
    $a2 = $answersOf($jid);
    $ck('fields: the normal tick is saved, and unticking clears it',
        !empty($r['ok']) && ($a['_updates'][$key] ?? null) === ['cost'] && !empty($r2['ok']) && !isset($a2['_updates'][$key]), json_encode([$r, $a['_updates'] ?? null, $r2]));

    // ---- 5. the per-answer stale check (key_hash, 2026-11-25): the screen sends the hash of THAT answer as it was shown ----
    // (a) an old screen's click on an answer someone else changed since -> refused, the stored answer unchanged; (b) someone else's answer on a
    // DIFFERENT key, then this click -> saved; (c) two clicks in a row from one screen, the second with the hash from the first reply -> both
    // saved; (d) no hash sent -> refused. Asked BEFORE the click (the request's own read), so the base-hash check does not come into it.
    $KSTALE = 'Someone else changed this answer since you opened the page, so nothing was saved.';
    $KMISS = 'This page was opened before a change to how saving works.';
    $rawAns = function (int $jid) use ($pdo) { $st = $pdo->prepare('SELECT answers_json FROM import_jobs WHERE id = ?'); $st->execute([$jid]); return (string)$st->fetchColumn(); };
    $optOther = function ($q, string $mine) { foreach ((array)($q['options'] ?? []) as $o) { $k = (string)($o['key'] ?? ''); if ($k !== '' && $k !== $mine && $k !== '_skip' && strpos($k, 'col:') !== 0) { return $k; } } return ''; };
    $forget = function () use ($pdo) { $pdo->exec("DELETE FROM import_aliases WHERE is_hotel_learned = 1 AND kind IN ('field', 'field_yes', 'field_not') AND term IN ('shots', 'tots in btl approx')"); };
    $forget();

    // 5.1 the main answer: the plain count question (bar layout) + the structural "Shots" question (learned only when saved)
    $jid = $barJob('ka');
    $q = $countQ($jid);
    $kh = (string)($q['khash'] ?? '');
    $oth = $optOther($q, 'stock');
    $r0 = import_job_answer($pdo, $jid, (string)$q['id'], $oth, $admin);              // the other person (another screen) answers first
    $before = $rawAns($jid);
    $r = import_job_answer($pdo, $jid, (string)$q['id'], 'stock', $admin, null, $kh);  // the old screen's click
    $ck('key hash, answer (a): a stale click on the same question -> refused ("someone else changed this answer"), the other person\'s answer kept',
        $kh === 'none' && $oth !== '' && !empty($r0['ok']) && $refusedWith($r, $KSTALE) && $rawAns($jid) === $before && (($answersOf($jid)[(string)$q['key']]['key'] ?? '') === $oth),
        json_encode([$kh, $oth, $r0, $r]));
    $jid = $barJob('kb');
    $q = $countQ($jid);
    $kh = (string)($q['khash'] ?? '');
    $u0 = (string)($q['row_uids'][0] ?? '');
    $r0 = import_job_count_row($pdo, $jid, (string)$q['id'], $u0, 'measure', $admin);  // the other person answers a different key
    $r = import_job_answer($pdo, $jid, (string)$q['id'], 'stock', $admin, null, $kh);
    $a = $answersOf($jid);
    $ck('key hash, answer (b): another person\'s answer on a different key, then this click -> saved, both kept',
        !empty($r0['ok']) && !empty($r['ok']) && (($a[(string)$q['key']]['key'] ?? '') === 'stock') && (($a['_count_in'][$u0]['key'] ?? '') === 'measure'), json_encode([$r0, $r]));
    $jid = $barJob('kc');
    $q = $countQ($jid);
    $oth = $optOther($q, 'stock');
    $r1 = import_job_answer($pdo, $jid, (string)$q['id'], 'stock', $admin, null, (string)($q['khash'] ?? ''));
    $r2 = import_job_answer($pdo, $jid, (string)$q['id'], $oth, $admin, null, (string)($r1['key_hash'] ?? ''));
    $a = $answersOf($jid);
    $ck('key hash, answer (c): two clicks in a row from one screen, the second with the reply\'s hash -> both saved, the reply hash is the stored answer\'s',
        !empty($r1['ok']) && !empty($r2['ok']) && (($a[(string)$q['key']]['key'] ?? '') === $oth) && ($r2['key_hash'] ?? '') === import_key_hash($a[(string)$q['key']] ?? null),
        json_encode([$r1, $r2]));
    $jid = $barJob('kd');
    $q = $countQ($jid);
    $r = import_job_answer($pdo, $jid, (string)$q['id'], 'stock', $admin, null, '');
    $ck('key hash, answer (d): no hash sent -> refused ("reload"), nothing saved',
        $refusedWith($r, $KMISS) && !isset($answersOf($jid)[(string)$q['key']]), json_encode($r));
    $jid = $ansJob('kd');
    $q = $mqOf(import_job_review_summary($pdo, $jid), 'Shots');
    $r = $q ? import_job_answer($pdo, $jid, (string)$q['id'], 'use:measures_per_unit', $admin, null, '') : ['ok' => false, 'error' => 'no Shots question'];
    $a = $answersOf($jid);
    $ck('key hash, answer (d, structural): no hash -> refused, no column override, nothing learned',
        $refusedWith($r, $KMISS) && !isset($a[(string)($q['key'] ?? '-')]) && empty($a['_overrides']['aliases']['measures_per_unit']) && $learnedRow('field', 'shots', 'measures_per_unit') === false,
        json_encode([$r, $a]));
    $jid = $ansJob('ka');
    $q = $mqOf(import_job_review_summary($pdo, $jid), 'Shots');
    $kh = (string)($q['khash'] ?? '');
    $oth = $optOther($q, 'use:measures_per_unit');
    $r0 = $q ? import_job_answer($pdo, $jid, (string)$q['id'], $oth, $admin) : ['ok' => false];
    $before = $rawAns($jid);
    $r = $q ? import_job_answer($pdo, $jid, (string)$q['id'], 'use:measures_per_unit', $admin, null, $kh) : ['ok' => false, 'error' => 'no Shots question'];
    $ck('key hash, answer (a, structural): a stale click after someone else answered "Shots" -> refused, answers unchanged, "measures per unit" not learned',
        $oth !== '' && !empty($r0['ok']) && $refusedWith($r, $KSTALE) && $rawAns($jid) === $before && $learnedRow('field', 'shots', 'measures_per_unit') === false,
        json_encode([$oth, $r0, $r]));
    $forget();

    // 5.2 "Use this" on a column suggestion: the hash is the stored suggestion's (shown on the column card)
    $sugHash = function (int $jid) use ($pdo, $colsOf) { return (string)($colsOf(import_job_review_summary($pdo, $jid))['Tots in btl (approx)']['suggest']['khash'] ?? ''); };
    [$jid, $ref, $nA] = $useJob('kd');
    $r = import_job_column_use($pdo, $jid, $ref, 'measures_per_unit', $admin, null, '');
    $a = $answersOf($jid);
    $ck('key hash, use this (d): no hash sent -> refused ("reload"), not used, no override, nothing learned',
        $nA === 1 && $refusedWith($r, $KMISS) && empty($a['_cols_ai'][$ref]['used']) && empty($a['_overrides']['aliases']['measures_per_unit'])
        && $learnedRow('field', 'tots in btl approx', 'measures_per_unit') === false, json_encode([$nA, $r]));
    [$jid, $ref, $nA] = $useJob('ka');
    $kh = $sugHash($jid);
    $r0 = import_job_column_use($pdo, $jid, $ref, 'measures_per_unit', $admin);       // the other person's "Use this" first
    $before = $rawAns($jid);
    $r = import_job_column_use($pdo, $jid, $ref, 'measures_per_unit', $admin, null, $kh);
    $ck('key hash, use this (a): a stale click on the same suggestion -> refused ("someone else changed this answer"), answers unchanged',
        $kh !== '' && $kh !== 'none' && !empty($r0['ok']) && $refusedWith($r, $KSTALE) && $rawAns($jid) === $before, json_encode([$kh, $r0, $r]));
    $forget();
    [$jid, $ref, $nA] = $useJob('kb');
    $kh = $sugHash($jid);
    ($otherWrite($jid))();                                                               // someone else's answer on a different key
    $r = import_job_column_use($pdo, $jid, $ref, 'measures_per_unit', $admin, null, $kh);
    $a = $answersOf($jid);
    $ck('key hash, use this (b): another person\'s answer on a different key, then this click -> saved, both kept',
        !empty($r['ok']) && !empty($a['_cols_ai'][$ref]['used']) && !empty($a['_zz_other']), json_encode($r));
    $forget();
    [$jid, $ref, $nA] = $useJob('kc');
    $r1 = import_job_column_use($pdo, $jid, $ref, 'measures_per_unit', $admin, null, $sugHash($jid));
    $a = $answersOf($jid);
    $r2 = import_job_column_use($pdo, $jid, $ref, 'measures_per_unit', $admin, null, (string)($r1['key_hash'] ?? ''));
    // "Use this" can only be clicked once per column (the column is read after it): the second click with the reply's hash passes the hash
    // check and stops at "already read", never at "someone else changed"
    $ck('key hash, use this (c): saved; the reply hash is the stored suggestion\'s, and a second click with it passes the hash check',
        !empty($r1['ok']) && !empty($a['_cols_ai'][$ref]['used']) && ($r1['key_hash'] ?? '') === import_key_hash($a['_cols_ai'][$ref])
        && empty($r2['ok']) && strpos((string)($r2['error'] ?? ''), 'This column is already read') === 0, json_encode([$r1, $r2]));
    $forget();

    // 5.3 one drink's own count unit: two drinks in one count question, so "a different key" is the other drink
    $bar2 = function (string $v) use ($job, $n, $hdrB) {
        return $job([$hdrB, [$n('LG B2 Gin ' . $v), 12, 30000, 2500, 30000, 'Bottle', 1, 'Shot'], [$n('LG B2 Rum ' . $v), 8, 30000, 2500, 30000, 'Bottle', 1, 'Shot']], 'lge' . $v);
    };
    $rowHash = function ($q, string $uid) { foreach ((array)($q['count_rows'] ?? []) as $cr) { if ((string)($cr['uid'] ?? '') === $uid) { return (string)($cr['khash'] ?? ''); } } return ''; };
    $jid = $bar2('ka');
    $q = $countQ($jid);
    [$u0, $u1] = [(string)($q['row_uids'][0] ?? ''), (string)($q['row_uids'][1] ?? '')];
    $kh = $rowHash($q, $u0);
    $r0 = import_job_count_row($pdo, $jid, (string)$q['id'], $u0, 'stock', $admin);
    $before = $rawAns($jid);
    $r = import_job_count_row($pdo, $jid, (string)$q['id'], $u0, 'measure', $admin, null, $kh);
    $ck('key hash, count row (a): a stale click on the same drink -> refused, the other person\'s choice kept',
        $u1 !== '' && $kh === 'none' && !empty($r0['ok']) && $refusedWith($r, $KSTALE) && $rawAns($jid) === $before && (($answersOf($jid)['_count_in'][$u0]['key'] ?? '') === 'stock'),
        json_encode([$kh, $r0, $r]));
    $jid = $bar2('kb');
    $q = $countQ($jid);
    [$u0, $u1] = [(string)($q['row_uids'][0] ?? ''), (string)($q['row_uids'][1] ?? '')];
    $kh = $rowHash($q, $u0);
    $r0 = import_job_count_row($pdo, $jid, (string)$q['id'], $u1, 'stock', $admin);    // the other person: the OTHER drink
    $r = import_job_count_row($pdo, $jid, (string)$q['id'], $u0, 'measure', $admin, null, $kh);
    $a = $answersOf($jid);
    $ck('key hash, count row (b): another person\'s choice on a different drink, then this click -> saved, both kept',
        $u1 !== '' && !empty($r0['ok']) && !empty($r['ok']) && (($a['_count_in'][$u0]['key'] ?? '') === 'measure') && (($a['_count_in'][$u1]['key'] ?? '') === 'stock'),
        json_encode([$r0, $r]));
    $jid = $bar2('kc');
    $q = $countQ($jid);
    $u0 = (string)($q['row_uids'][0] ?? '');
    $r1 = import_job_count_row($pdo, $jid, (string)$q['id'], $u0, 'measure', $admin, null, $rowHash($q, $u0));
    $r2 = import_job_count_row($pdo, $jid, (string)$q['id'], $u0, 'stock', $admin, null, (string)($r1['key_hash'] ?? ''));
    $a = $answersOf($jid);
    $ck('key hash, count row (c): two clicks in a row from one screen with the reply\'s hash -> both saved',
        !empty($r1['ok']) && !empty($r2['ok']) && (($a['_count_in'][$u0]['key'] ?? '') === 'stock') && ($r2['key_hash'] ?? '') === import_key_hash($a['_count_in'][$u0]), json_encode([$r1, $r2]));
    $jid = $bar2('kd');
    $q = $countQ($jid);
    $u0 = (string)($q['row_uids'][0] ?? '');
    $r = import_job_count_row($pdo, $jid, (string)$q['id'], $u0, 'measure', $admin, null, '');
    $ck('key hash, count row (d): no hash sent -> refused ("reload"), nothing saved',
        $u0 !== '' && $refusedWith($r, $KMISS) && !isset($answersOf($jid)['_count_in'][$u0]), json_encode($r));

    // 5.4 "which fields may change": two existing ingredients, so "a different key" is the other ingredient
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, 'Pantry', 'bottle', 0, 700, 5, 2)")->execute([$n('LG Tickme2')]);
    $key2 = rh_ssi_key($n('LG Tickme2'));
    $tick2 = function (string $v) use ($job, $n) {
        return $job([['Item Name', 'Batch', 'Balance', 'Unit', 'Unit Price', 'Max Level', 'Expiry Date'], [$n('LG Tickme'), '', '30', 'bottle', '900', '', ''],
            [$n('LG Tickme2'), '', '20', 'bottle', '900', '', ''], [$n('LG New2 ' . $v), '', '1', 'bottle', '100', '', '']], 'lgf' . $v);
    };
    $updHash = function (int $jid, string $k) use ($pdo) { foreach (import_job_review_summary($pdo, $jid)['items'] ?? [] as $it0) { if (($it0['update_key'] ?? null) === $k) { return (string)($it0['upd_khash'] ?? ''); } } return ''; };
    $jid = $tick2('ka');
    $kh = $updHash($jid, $key);
    $r0 = import_job_set_update_fields($pdo, $jid, $key, ['cost'], $admin);
    $before = $rawAns($jid);
    $r = import_job_set_update_fields($pdo, $jid, $key, [], $admin, null, $kh);
    $ck('key hash, fields (a): a stale click on the same ingredient -> refused, the other person\'s ticks kept',
        $kh === 'none' && !empty($r0['ok']) && $refusedWith($r, $KSTALE) && $rawAns($jid) === $before && (($answersOf($jid)['_updates'][$key] ?? null) === ['cost']), json_encode([$kh, $r0, $r]));
    $jid = $tick2('kb');
    $kh = $updHash($jid, $key);
    $r0 = import_job_set_update_fields($pdo, $jid, $key2, ['cost'], $admin);          // the other person: the OTHER ingredient
    $r = import_job_set_update_fields($pdo, $jid, $key, ['cost'], $admin, null, $kh);
    $a = $answersOf($jid);
    $ck('key hash, fields (b): another person\'s ticks on a different ingredient, then this click -> saved, both kept',
        $kh === 'none' && !empty($r0['ok']) && !empty($r['ok']) && ($a['_updates'][$key] ?? null) === ['cost'] && ($a['_updates'][$key2] ?? null) === ['cost'], json_encode([$r0, $r]));
    $jid = $tick2('kc');
    $r1 = import_job_set_update_fields($pdo, $jid, $key, ['cost'], $admin, null, $updHash($jid, $key));
    $r2 = import_job_set_update_fields($pdo, $jid, $key, [], $admin, null, (string)($r1['key_hash'] ?? ''));
    $a = $answersOf($jid);
    $ck('key hash, fields (c): tick then untick from one screen with the reply\'s hash -> both saved',
        !empty($r1['ok']) && !empty($r2['ok']) && !isset($a['_updates'][$key]) && ($r2['key_hash'] ?? '') === 'none', json_encode([$r1, $r2]));
    $jid = $tick2('kd');
    $r = import_job_set_update_fields($pdo, $jid, $key, ['cost'], $admin, null, '');
    $ck('key hash, fields (d): no hash sent -> refused ("reload"), nothing saved',
        $refusedWith($r, $KMISS) && !isset($answersOf($jid)['_updates'][$key]), json_encode($r));
} catch (Throwable $e) {
    $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made as $m) { if ($m) { import_delete_stored((string)$m); } }
    foreach ($tmpFiles as $f) { @unlink($f); }
    @rmdir($tmp);
}
echo json_encode(['checks' => $checks]);
