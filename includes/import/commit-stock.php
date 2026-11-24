<?php
/**
 * Import - commit a reviewed stock list (Phase 1b-backend).
 *
 *   import_commit_stock($pdo, $jobId, $userId, $opts)
 *
 * Reuses the existing, owner-approved write path admin/includes/stock-sheet-import-lib.php: only rh_ssi_apply() is called (never
 * rh_ssi_plan(), which carries one hotel's own sheet layout). This file builds the plan array from the REVIEWED rows:
 *   - new ingredients: name, category (section row / sheet name), unit, cost (price), par (max level), pack (per-case answer only);
 *   - an existing ingredient is matched by name and only the fields a person ticked change (default: nothing changes);
 *   - ONE opening count left SUBMITTED with staged expiry batches: a different manager approves it (two-person rule) and only then does
 *     stock appear. Nothing is stocked in directly, no quantity is UPDATEd, nothing is deleted/archived, recipes are untouched.
 *   - stock on hand with no cost is NOT put in the count (shown as a warning on the review screen).
 *   - drinks a person TICKED under "Drinks for the menu" go on the drinks menu + till at the sheet's selling price (VAT included), with
 *     a one-measure stock link (includes/import/drinks.php; needs the Menu permission too). Unticked = the menu is not touched.
 * One DB transaction, all-or-nothing, one import commit at a time (named lock, taken before the transaction). A second call for a
 * committed job changes nothing and returns the stored result.
 * Everything is re-validated here (import_job_revalidate): the browser's statuses are never read.
 * $opts: fail_after_apply (CLI tests only: force a failure after the writes, to prove the rollback leaves nothing behind).
 */

require_once __DIR__ . '/review.php';
require_once __DIR__ . '/drinks.php';
require_once __DIR__ . '/diff-report.php';
require_once dirname(__DIR__, 2) . '/admin/includes/procurement-schema.php';

if (!function_exists('import_commit_stock')) {

    /**
     * The "Differences from the uploaded file" report (diff-report.php) for this commit, from what was really written: the opening count's
     * own lines (count and cost per item, read back inside this transaction), the reviewed items, the menu rows and the count measures.
     */
    function import_commit_diff_report(PDO $pdo, array $job, int $jobId, array $v, array $plan, array $ap, ?array $vcFin, array $menuRows, array $measureList, array $measureNot): array
    {
        $byKey = [];
        if (!empty($ap['count_id'])) {
            $st = $pdo->prepare('SELECT l.actual_quantity, l.cost_per_unit, i.name FROM stock_count_lines l JOIN stock_ingredients i ON i.id = l.ingredient_id WHERE l.count_id = ?');
            $st->execute([(int)$ap['count_id']]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $byKey[rh_ssi_key((string)$l['name'])] = ['qty' => (float)$l['actual_quantity'], 'cost' => (float)$l['cost_per_unit']];
            }
        }
        $planned = [];
        $lines = [];
        foreach ((array)$v['res']['items'] as $it) {
            $k = rh_ssi_key((string)$it['name']);
            if (($it['status'] ?? '') !== 'ready' || !isset($plan['items'][$k])) {
                continue;
            }
            $planned[(string)$it['key']] = true;
            if (isset($byKey[$k])) {
                $lines[(string)$it['key']] = $byKey[$k];
            }
        }
        return import_diff_build([
            'file' => (string)$job['original_name'], 'job_id' => $jobId, 'count_id' => $ap['count_id'] ?? null, 'count_ref' => (string)($ap['count_ref'] ?? ''),
            'term' => import_count_term(empty($ap['is_opening']) ? 'regular' : 'opening'),
            'res' => (array)$v['res'], 'planned' => $planned, 'lines' => $lines,
            'vc_col' => (array)($v['vc_col'] ?? []), 'vc_fin' => (array)($vcFin ?? []),
            'menu_rows' => $menuRows, 'measures_list' => $measureList, 'measures_not' => $measureNot,
        ]);
    }

    function import_commit_fail(string $msg, array $extra = []): array
    {
        return $extra + ['ok' => false, 'error' => $msg, 'already' => false, 'result' => null];
    }

    /** Build the plan rh_ssi_apply() expects from the resolved items that are ready. Returns [plan, skippedNames] or an error string. */
    function import_build_stock_plan(array $res, array $ctx)
    {
        $byId = [];
        foreach ($ctx['existing'] as $e) {
            $byId[(int)$e['id']] = $e;
        }
        $plan = ['items' => [], 'skipped' => [], 'duplicates' => [], 'row_count' => 0, 'budget_month' => date('Y-m'), 'budgets' => [], 'budget_lines' => [],
            'qty_name_mismatch' => [], 'existing_not_in_sheet' => [], 'covered_ids' => [], 'existing_count' => []];
        foreach ($res['items'] as $it) {
            if ($it['status'] !== 'ready') {
                continue;
            }
            $ex = $it['match'] ? ($byId[$it['match']['id']] ?? null) : null;
            if ($it['match'] && !$ex) {
                return 'An ingredient this import matched ("' . $it['match']['name'] . '") no longer exists. Please review again.';
            }
            $key = rh_ssi_key($it['name']);
            if (isset($plan['items'][$key])) {
                return 'Two rows end up as the same stock item ("' . $it['name'] . '"). Skip one of them and try again.';
            }
            $batches = [];
            foreach ($it['batches'] as $b) {
                $batches[] = ['label' => (string)$b['label'], 'qty' => (float)$b['qty'], 'expiry' => $b['expiry']];
            }
            $base = [
                'key' => $key, 'name' => $it['name'], 'rows' => $it['rows'], 'section' => $it['category'], 'balance' => (float)($it['balance'] ?? 0),
                'batches' => $batches, 'flags' => [], 'note_set' => [], 'notes_changed' => false, 'in_count' => !empty($it['in_count']),
                'already_counted' => false, 'unit_assumed' => false, 'max' => (float)($it['max'] ?? 0), 'price' => $it['price'], 'pack_status' => 'n/a',
                'cost_status' => 'ok', 'value' => 0.0, 'pack_hint' => [], 'half_fit' => [], 'pack_fits' => [], 'pack_reason' => '',
            ];
            if (!$ex) {
                $plan['items'][$key] = $base + [
                    'dept' => (string)$it['category'], 'unit' => (string)$it['unit'], 'cost' => $it['cost'], 'pack' => $it['pack'] ?: null,
                    'pack_name' => $it['pack'] ? ($it['pack_name'] ?? null) : null,
                    'action' => 'create', 'existing' => null, 'changes' => [], 'par' => (float)($it['max'] ?? 0), 'reorder' => (float)($it['min'] ?? 0),
                    'notes' => (string)($it['remarks'] ?? ''),
                ];
                continue;
            }
            $t = array_flip($it['ticked']);
            // backup of the resolver's rule: a per-case pack size never goes in without its divided cost (the case price would stay the cost of one unit)
            if (isset($t['pack']) && !empty($it['pack']) && isset($it['diff']['pack']) && isset($it['diff']['cost']) && !isset($t['cost'])) {
                return '"' . $it['name'] . '": the pack size is ticked without the cost divided by it. Please review again.';
            }
            $changes = [];
            foreach (['category', 'cost', 'par', 'pack'] as $f) {
                if (isset($t[$f]) && isset($it['diff'][$f])) {
                    $changes[$f] = $it['diff'][$f];
                }
            }
            // a ticked par also sets the reorder point when the file has a minimum (rh_ssi_apply writes only the listed fields)
            if (isset($changes['par']) && $it['min'] !== null && abs((float)$it['min'] - (float)$ex['reorder_point']) > 0.00005) {
                $changes['reorder'] = [(float)$ex['reorder_point'], (float)$it['min']];
            }
            $plan['items'][$key] = $base + [
                'dept' => isset($t['category']) ? (string)$it['category'] : (string)$ex['category'], 'unit' => (string)$ex['unit'],
                'cost' => isset($t['cost']) ? $it['cost'] : null, 'pack' => isset($t['pack']) ? ($it['pack'] ?: null) : null,
                'pack_name' => isset($t['pack']) && $it['pack'] ? ($it['pack_name'] ?? null) : null,
                'action' => 'update', 'existing' => $ex, 'changes' => $changes,
                'par' => isset($t['par']) ? (float)$it['max'] : (float)$ex['par_level'],
                'reorder' => (isset($t['par']) && $it['min'] !== null) ? (float)$it['min'] : (float)$ex['reorder_point'],
                'notes' => (string)($ex['notes'] ?? ''),
            ];
        }
        return $plan;
    }

    function import_commit_stock(PDO $pdo, int $jobId, int $userId, array $opts = []): array
    {
        $job = import_job_get($pdo, $jobId);
        if (!$job) {
            return import_commit_fail('That import was not found.');
        }
        if ($job['status'] === 'committed') {      // idempotent: the same answer again, nothing written
            return ['ok' => true, 'error' => '', 'already' => true, 'result' => json_decode((string)$job['result_json'], true)];
        }
        if (!rh_import_can_use($userId, $pdo)) {
            return import_commit_fail('You do not have access to Import.');
        }
        if (($job['target'] ?? '') !== 'stock') {
            return import_commit_fail('Only stock lists can be imported.');
        }
        if (!in_array($job['status'], ['analysed', 'reviewing', 'confirmed'], true)) {
            return import_commit_fail('This import is ' . $job['status'] . ' and cannot be imported.');
        }
        // DDL first (it commits on its own): the staged-batch table and the opening-count flag
        rh_ssi_ensure_table($pdo);
        $hasOpening = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_counts' AND COLUMN_NAME = 'is_opening'")->fetchColumn() > 0;
        if (!$hasOpening) {
            $pdo->exec('ALTER TABLE stock_counts ADD COLUMN is_opening TINYINT(1) NOT NULL DEFAULT 0');
        }
        // the opening count's link back to this job (the "Differences from the uploaded file" report on Stock Count): nullable, added the same
        // way; where it cannot be added the import still runs (the count then shows no report)
        $opts['_diff_link'] = import_diff_link_ready($pdo, true);

        // One import commit at a time (I1): taken BEFORE the transaction starts, so this transaction's read snapshot is made after
        // any earlier import has committed. A second import that shares a new item then sees it as existing instead of creating
        // it again. Same pattern as the count approval lock in admin/stock-count.php. Named lock = no row locks, no deadlock.
        $commitLock = import_commit_lock_name();
        if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($commitLock) . ', 10)')->fetchColumn() !== 1) {
            return import_commit_fail('Another import is being saved right now, so nothing was imported. Wait a few seconds and press Import again.');
        }
        // drinks ticked for the menu: also the lock Menu Management takes for an add / edit, so the two never write the menu at once
        $menuLock = import_drinks_any_ticked(import_job_answers($job)) ? import_drinks_menu_lock_name() : '';
        try {
            if ($menuLock !== '' && (int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($menuLock) . ', 10)')->fetchColumn() !== 1) {
                $menuLock = '';
                return import_commit_fail('Somebody is saving a menu item right now, so nothing was imported. Wait a few seconds and press Import again.');
            }
            return import_commit_stock_locked($pdo, $jobId, $userId, $opts);
        } finally {
            try {
                if ($menuLock !== '') {
                    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($menuLock) . ')');
                }
                $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($commitLock) . ')');
            } catch (Throwable $e) {
                // closing the connection releases it
            }
        }
    }

    /** Name of the import-commit lock (one per database). */
    function import_commit_lock_name(): string
    {
        return substr('rh_import_commit_' . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64);
    }

    /** The commit itself; the caller holds the import-commit lock. The job row is read again here, inside the lock. */
    function import_commit_stock_locked(PDO $pdo, int $jobId, int $userId, array $opts): array
    {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT * FROM import_jobs WHERE id = ? FOR UPDATE');
            $lock->execute([$jobId]);
            $cur = $lock->fetch(PDO::FETCH_ASSOC);
            $job = $cur ?: [];      // the row as it is now, inside the lock (another request may have changed it while this one waited)
            if ($cur && $cur['status'] === 'committed') {
                $pdo->rollBack();
                return ['ok' => true, 'error' => '', 'already' => true, 'result' => json_decode((string)$cur['result_json'], true)];
            }
            if (!$cur || ($cur['target'] ?? '') !== 'stock' || !in_array($cur['status'], ['analysed', 'reviewing', 'confirmed'], true)) {
                $pdo->rollBack();
                return import_commit_fail('This import can no longer be imported.');
            }
            $v = import_job_revalidate($pdo, $job);
            if (!$v['ok']) {
                $pdo->rollBack();
                return import_commit_fail($v['error'], ['violations' => $v['violations'] ?? []]);
            }
            $plan = import_build_stock_plan($v['res'], $v['ctx']);
            if (is_string($plan)) {
                $pdo->rollBack();
                return import_commit_fail($plan);
            }
            if (!$plan['items']) {
                $pdo->rollBack();
                return import_commit_fail('There is nothing ready to import.');
            }
            $answersNow = import_job_answers($job);
            if (import_drinks_any_ticked($answersNow) && !import_drinks_can_menu($userId)) {
                $pdo->rollBack();
                return import_commit_fail('Drinks are ticked for the menu, and your account cannot change the menu (it needs the Menu permission). Untick them, or ask a manager who has it. Nothing was imported.');
            }
            // When the stock in the file was counted (T) and which kind of count it files, both decided again here, inside the locks:
            // T must still be usable, and the kind must be the one the review showed (an opening count approved meanwhile turns this
            // import into a regular count, with other rules for shortages: the person reviews again).
            $countAt = import_count_time_get($answersNow);
            $tProblem = import_count_time_problem($pdo, $countAt, [], true);
            if ($tProblem !== '') {
                $pdo->rollBack();
                return import_commit_fail($tProblem);
            }
            $kind = import_count_kind($pdo, true);
            if (isset($opts['expect_kind']) && (string)$opts['expect_kind'] !== $kind) {
                $pdo->rollBack();
                return import_commit_fail('The kind of count this import files changed while you were reviewing (it is now a ' . import_count_term($kind)
                    . ', with its own rules for shortages). Nothing was imported: review again, then press Import.');
            }
            $term = import_count_term($kind);
            $ap = rh_ssi_apply($pdo, $plan, $userId, ['counted_at' => $countAt, 'kind' => $kind,
                'after_lock' => function (array $ids) use ($pdo, $countAt) {
                    // the item checks (a later approved count, a later unit change) on the locked rows
                    $p = import_count_time_problem($pdo, $countAt, $ids, true);
                    if ($p !== '') {
                        throw new RuntimeException($p);
                    }
                }]);
            if ($ap['count_id']) {
                $note = 'Stock sheet import ' . date('Y-m-d') . ': ' . ($kind === 'regular' ? 'stock count' : 'opening balances') . ' from "' . mb_substr((string)$job['original_name'], 0, 120) . '" (Import #' . $jobId
                    . '), counted ' . import_count_fmt((string)$countAt) . '. ' . ($ap['count_status'] === 'draft' ? 'Each shortage needs a reason before it is submitted; then a' : 'Counted by the import - a')
                    . ' second manager must approve it. Ingredients not in the file are not in this count.';
                if (!empty($opts['_diff_link'])) {
                    $pdo->prepare('UPDATE stock_counts SET notes = ?, import_job_id = ? WHERE id = ?')->execute([$note, $jobId, (int)$ap['count_id']]);
                } else {
                    $pdo->prepare('UPDATE stock_counts SET notes = ? WHERE id = ?')->execute([$note, (int)$ap['count_id']]);
                }
            }
            // "how many tots in one bottle?" answered on the review screen: saved on the item as its count measure (Step U2 d), through the stock
            // lead's own path (rh_stock_measure_save: checked, logged as stock_measure_changed), in this same transaction. Never a raw UPDATE.
            $measureSaved = 0;
            $measureList = [];     // the items now counted in a measure (step 4 / result: "Premier Brandy: 25 shots in one bottle")
            $measureNot = [];      // converted counts whose measure is not saved, with the reason
            foreach ($v['res']['items'] as $it) {
                if ($it['status'] !== 'ready' || empty($it['measure_save']) || !isset($plan['items'][rh_ssi_key($it['name'])])) {
                    continue;
                }
                if (!function_exists('rh_stock_measure_ready') || !rh_stock_measure_ready($pdo)) {
                    break;                              // before database update 088 there is nowhere to keep it: the converted count still goes in
                }
                $ms = $it['measure_save'];
                [$mNew, $mErr] = rh_stock_measure_from_input(['measure_big_name' => (string)$ms['big'], 'measure_small_name' => (string)$ms['small'],
                    'measure_per_big' => (string)$ms['n'], 'measure_stock_side' => (string)$ms['side']]);
                if ($mNew === null) {
                    throw new RuntimeException('"' . $it['name'] . '": the count measure could not be saved (' . (string)$mErr . '). Please review again.');
                }
                if ($it['match']) {
                    $ingId = (int)$it['match']['id'];
                } else {
                    $fq = $pdo->prepare('SELECT id FROM stock_ingredients WHERE name = ? ORDER BY id DESC LIMIT 1');
                    $fq->execute([$it['name']]);
                    $ingId = (int)$fq->fetchColumn();
                }
                if ($ingId <= 0) {
                    throw new RuntimeException('"' . $it['name'] . '" was not found after the import, so its count measure could not be saved. Nothing was imported.');
                }
                $cur = $pdo->prepare('SELECT measure_big_name, measure_small_name, measure_per_big, measure_stock_side FROM stock_ingredients WHERE id = ? FOR UPDATE');
                $cur->execute([$ingId]);
                $curRow = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
                $curM = rh_stock_measure_get($curRow);
                $was = null;
                if ($curM !== null) {
                    if (import_measure_same($curM, $mNew)) {
                        continue;                       // already this measure: nothing to do
                    }
                    if (empty($ms['replace']) || !import_measure_same($curM, (array)$ms['replace'])) {
                        // an item's own measure set since the review is never replaced by an import
                        $measureNot[] = ['name' => (string)$it['name'], 'why' => 'its count measure was changed in ' . import_stock_page_name() . ' after the review (' . rh_stock_measure_describe($curM) . '); it is kept'];
                        continue;
                    }
                    // "Change to ..." was chosen. Lines on open counts typed in the old measure would be re-worked by rh_stock_measure_save (a new N, or
                    // "7 shots" read as "7 doubles"): then it is kept, whatever changes (a line typed since the review included), with the reason.
                    $ol = (int)(import_measure_open_lines($pdo, $ingId)[$ingId] ?? 0);
                    if ($ol > 0) {
                        $measureNot[] = ['name' => (string)$it['name'], 'why' => import_measure_open_why($curM, $ol)];
                        continue;
                    }
                    $was = $curM;
                }
                rh_stock_measure_save($pdo, $ingId, $mNew, $userId, 'Import #' . $jobId);
                $measureSaved++;
                if (count($measureList) < 500) {
                    $measureList[] = ['name' => (string)$it['name'], 'small' => (string)$mNew['small'], 'text' =>import_measure_fmt((float)$mNew['n']) . ' ' . import_measure_plural((string)$mNew['small'], (float)$mNew['n']) . ' in one ' . $mNew['big'],
                        'was' => $was !== null ? import_measure_fmt((float)$was['n']) . ' ' . import_measure_plural((string)$was['small'], (float)$was['n']) . ' in one ' . $was['big'] : null];
                }
            }
            foreach ($v['res']['items'] as $it) {
                if ($it['status'] === 'ready' && !empty($it['measure_not']) && isset($plan['items'][rh_ssi_key($it['name'])]) && count($measureNot) < 500) {
                    $measureNot[] = ['name' => (string)$it['name'], 'why' => (string)$it['measure_not']];
                }
            }
            // bottle sizes a person gave in step 3 (optional), saved before the drinks so a shot / ml stock link can be worked out
            $sizes = import_drinks_sizes_commit($pdo, (array)$v['res']['items'], $answersNow, $jobId, $userId);
            // "Drinks for the menu": only the ticked rows, re-checked now (after the stock part, so a new item and its measure exist)
            $drinks = import_drinks_commit($pdo, (array)$v['res']['items'], $answersNow, $jobId, $userId);
            if (PHP_SAPI === 'cli' && !empty($opts['fail_after_apply'])) {
                throw new RuntimeException('Forced failure after the writes (test).');
            }
            // what this hotel taught the matcher: "Heinken" is the existing "Heineken" (saved only now that the import succeeded)
            foreach ($v['res']['items'] as $it) {
                if ($it['status'] === 'ready' && $it['match'] && $it['match']['how'] === 'chosen' && import_norm_text($it['typed_name']) !== import_norm_text($it['match']['name'])) {
                    import_save_name_correction($pdo, import_norm_text($it['typed_name']), $it['match']['name'], $userId > 0 ? $userId : null);
                }
            }
            // what the person CONFIRMED (a unit or category they picked or accepted) is remembered for next time; written here, in the same transaction,
            // so a rolled-back import teaches nothing
            $learnRows = import_learned_rows($v['res']['items']);
            $learned = $learnRows ? import_save_learned($pdo, $learnRows, $userId > 0 ? $userId : null) : 0;
            // a column heading a person confirmed ("Yes, use it as ...") is remembered for this hotel, so the same layout is not asked again (A87)
            $learned += import_save_learned_headers($pdo, (array)(import_job_answers($job)['_overrides']['aliases'] ?? []), $userId > 0 ? $userId : null);
            // stock left out of the count, by its TRUE reason (an unticked quantity is not "no price"); the overlap with another unfinished
            // opening count is decided inside rh_ssi_apply under its lock and reported as left_out_overlap
            $nc = import_cont_not_counted($v['res']['items'], [], PHP_INT_MAX)['by'];
            $draft = ($ap['count_status'] ?? '') === 'draft';
            $result = [
                'created' => (int)$ap['created'], 'updated' => (int)$ap['updated'], 'skipped_rows' => (int)$v['res']['counts']['skipped'],
                'items' => count($plan['items']), 'opening_count_id' => $ap['count_id'], 'opening_count_ref' => $ap['count_ref'],
                'count_lines' => (int)$ap['count_lines'], 'expiry_batches' => (int)$ap['batches'], 'left_out_overlap' => (int)($ap['left_out_overlap'] ?? 0),
                'stock_not_counted_no_cost' => $nc['no_cost']['names'], 'stock_not_counted_not_ticked' => $nc['not_ticked']['names'],
                'stock_not_counted_cost_not_ticked' => $nc['cost_not_ticked']['names'], 'stock_not_counted_same' => $nc['same']['names'], 'committed_at' => date('Y-m-d H:i:s'), 'learned' => $learned,
                'measures_saved' => $measureSaved, 'measures_list' => $measureList, 'measures_not' => $measureNot, 'sizes_saved' => $sizes['saved'], 'sizes_names' => $sizes['names'],
                'drinks_added' => $drinks['added'] - $drinks['food_added'], 'drinks_updated' => $drinks['updated'] - $drinks['food_updated'], 'drinks_linked' => $drinks['linked'], 'drinks_names' => $drinks['names'],
                'food_added' => $drinks['food_added'], 'food_updated' => $drinks['food_updated'],      // 2026-11-22: food / snack items put on the food menu
                'skipped_by_you' => (int)import_cont_overview($v['res'])['skipped_by_you'], 'fixed_for_you' => (int)array_sum($v['res']['heal_counts']),
                'count_kind' => $kind, 'count_term' => $term, 'count_status' => $ap['count_status'] ?? null, 'counted_at' => $countAt,
                'next_step' => $draft
                    ? 'The ' . $term . ' is saved as a draft because some items are short: open it on Stock Count, pick a reason for each shortage and submit it. Then a different manager must approve it before the stock changes.'
                    : 'A different manager must approve the ' . $term . ' on Stock Count before any stock changes.',
            ];
            // report only: does the sheet's own value column disagree with Quantity x Unit Cost, or is there stock with no unit cost?
            // (shown on the result screen and in Import history as the stock-check workbook; never changes what was imported)
            try {
                $vcFin = import_vc_finish((array)($v['vc_col'] ?? []), (array)($v['analysis']['questions'] ?? []), (array)($v['res']['items'] ?? []));
                $result['stock_check'] = ['differ' => (int)$vcFin['differ'], 'missing' => (int)$vcFin['missing_n']];
            } catch (Throwable $e) {
                // a fault in the report never stops the import (no stock_check key = no stock-check link in Import history)
            }
            // "Differences from the uploaded file" (owner 2026-11-23): built now, from what was really written, and stored frozen with the job.
            // The opening count's own lines give the imported count and cost of each item. A fault here never stops the import (no report key).
            try {
                $result['diff_report'] = import_commit_diff_report($pdo, $job, $jobId, $v, $plan, $ap, $vcFin ?? null, (array)($drinks['rows'] ?? []), $measureList, $measureNot);
            } catch (Throwable $e) {
                error_log('import_commit_stock #' . $jobId . ' differences report: ' . $e->getMessage());
            }
            $pdo->prepare("UPDATE import_jobs SET status = 'committed', committed_by = ?, committed_at = NOW(), result_json = ?, error_text = NULL WHERE id = ?")
                ->execute([$userId > 0 ? $userId : null, import_json($result), $jobId]);
            if (function_exists('logActivity')) {
                logActivity($userId, 'stock_import', 'Import #' . $jobId . ' (' . $job['original_name'] . '): ' . $result['created'] . ' ingredients created, ' . $result['updated'] . ' updated, ' . $term . ' '
                    . ($result['opening_count_ref'] ?? 'none') . ' with ' . $result['count_lines'] . ' lines counted ' . (string)$countAt
                    . ($result['opening_count_ref'] ? ($draft ? ' (draft: shortages need a reason).' : ' (submitted, waiting for approval).') : '.'));
            }
            // audit trail for money: every changed cost, old -> new, one line per ingredient (written in the same transaction)
            if (function_exists('logActivity')) {
                foreach ($plan['items'] as $pi) {
                    if (($pi['action'] ?? '') === 'update' && isset($pi['changes']['cost'])) {
                        logActivity($userId, 'stock_import_cost', 'Import #' . $jobId . ': cost of "' . $pi['name'] . '" changed from '
                            . number_format((float)$pi['changes']['cost'][0], 4, '.', '') . ' to ' . number_format((float)$pi['changes']['cost'][1], 4, '.', '') . ' per ' . (string)$pi['unit'] . '.');
                    }
                }
            }
            $pdo->commit();
            rh_ssi_release_opening_lock($pdo);
            // the stock dashboard shows costs and counts: drop its cached figures (as stock-ingredients.php does after a save)
            if (function_exists('deleteCache') && function_exists('rh_clear_stock_dashboard_cache')) {
                try {
                    rh_clear_stock_dashboard_cache();
                } catch (Throwable $e) {
                    // a stale dashboard for a few minutes is not worth failing a finished import
                }
            }
        } catch (Throwable $e) {
            try {
                $pdo->rollBack();
            } catch (Throwable $e2) {
                // already closed
            }
            rh_ssi_release_opening_lock($pdo);
            error_log('import_commit_stock #' . $jobId . ': ' . $e->getMessage());
            try {
                $pdo->prepare('UPDATE import_jobs SET error_text = ? WHERE id = ? AND status <> \'committed\'')->execute([mb_substr(($e instanceof PDOException) ? 'Database error (see the error log).' : $e->getMessage(), 0, 480), $jobId]);
            } catch (Throwable $e3) {
                // keep the original error
            }
            // Database errors can name tables/keys: staff get a plain sentence, the detail stays in the error log.
            $plain = ($e instanceof PDOException) ? 'The database refused part of the import.' : $e->getMessage();
            return import_commit_fail('Nothing was imported: ' . $plain);
        }
        if ($job['stored_path']) {            // the staged file is no longer needed
            import_delete_stored((string)$job['stored_path']);
            try {
                $pdo->prepare('UPDATE import_jobs SET stored_path = NULL WHERE id = ?')->execute([$jobId]);
            } catch (Throwable $e) {
                // purge will do it
            }
        }
        return ['ok' => true, 'error' => '', 'already' => false, 'result' => $result];
    }
}
