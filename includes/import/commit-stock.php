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
 * One DB transaction, all-or-nothing. A second call for a committed job changes nothing and returns the stored result.
 * Everything is re-validated here (import_job_revalidate): the browser's statuses are never read.
 * $opts: fail_after_apply (CLI tests only: force a failure after the writes, to prove the rollback leaves nothing behind).
 */

require_once __DIR__ . '/review.php';
require_once dirname(__DIR__, 2) . '/admin/includes/procurement-schema.php';

if (!function_exists('import_commit_stock')) {

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
                    'action' => 'create', 'existing' => null, 'changes' => [], 'par' => (float)($it['max'] ?? 0), 'reorder' => (float)($it['min'] ?? 0),
                    'notes' => (string)($it['remarks'] ?? ''),
                ];
                continue;
            }
            $t = array_flip($it['ticked']);
            $changes = [];
            foreach (['category', 'cost', 'par', 'pack'] as $f) {
                if (isset($t[$f]) && isset($it['diff'][$f])) {
                    $changes[$f] = $it['diff'][$f];
                }
            }
            $plan['items'][$key] = $base + [
                'dept' => isset($t['category']) ? (string)$it['category'] : (string)$ex['category'], 'unit' => (string)$ex['unit'],
                'cost' => isset($t['cost']) ? $it['cost'] : null, 'pack' => isset($t['pack']) ? ($it['pack'] ?: null) : null,
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

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT status, result_json FROM import_jobs WHERE id = ? FOR UPDATE');
            $lock->execute([$jobId]);
            $cur = $lock->fetch(PDO::FETCH_ASSOC);
            if ($cur && $cur['status'] === 'committed') {
                $pdo->rollBack();
                return ['ok' => true, 'error' => '', 'already' => true, 'result' => json_decode((string)$cur['result_json'], true)];
            }
            if (!$cur || !in_array($cur['status'], ['analysed', 'reviewing', 'confirmed'], true)) {
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
            $ap = rh_ssi_apply($pdo, $plan, $userId);
            if ($ap['count_id']) {
                $note = 'Stock sheet import ' . date('Y-m-d') . ': opening balances from "' . mb_substr((string)$job['original_name'], 0, 120) . '" (Import #' . $jobId
                    . '). Counted by the import - a second manager must approve it. Ingredients not in the file are not in this count.';
                $pdo->prepare('UPDATE stock_counts SET notes = ? WHERE id = ? AND is_opening = 1')->execute([$note, (int)$ap['count_id']]);
            }
            if (PHP_SAPI === 'cli' && !empty($opts['fail_after_apply'])) {
                throw new RuntimeException('Forced failure after the writes (test).');
            }
            // what this hotel taught the matcher: "Heinken" is the existing "Heineken" (saved only now that the import succeeded)
            foreach ($v['res']['items'] as $it) {
                if ($it['status'] === 'ready' && $it['match'] && $it['match']['how'] === 'chosen' && import_norm_text($it['typed_name']) !== import_norm_text($it['match']['name'])) {
                    import_save_name_correction($pdo, import_norm_text($it['typed_name']), $it['match']['name'], $userId > 0 ? $userId : null);
                }
            }
            $notCounted = [];
            foreach ($v['res']['items'] as $it) {
                if ($it['status'] === 'ready' && empty($it['in_count']) && ($it['balance'] ?? 0) > 0.00005) {
                    $notCounted[] = $it['name'];
                }
            }
            $result = [
                'created' => (int)$ap['created'], 'updated' => (int)$ap['updated'], 'skipped_rows' => (int)$v['res']['counts']['skipped'],
                'items' => count($plan['items']), 'opening_count_id' => $ap['count_id'], 'opening_count_ref' => $ap['count_ref'],
                'count_lines' => (int)$ap['count_lines'], 'expiry_batches' => (int)$ap['batches'], 'left_out_overlap' => (int)($ap['left_out_overlap'] ?? 0),
                'stock_not_counted_no_cost' => $notCounted, 'committed_at' => date('Y-m-d H:i:s'),
                'next_step' => 'A different manager must approve the opening count on Stock Count before any stock appears.',
            ];
            $pdo->prepare("UPDATE import_jobs SET status = 'committed', committed_by = ?, committed_at = NOW(), result_json = ?, error_text = NULL WHERE id = ?")
                ->execute([$userId > 0 ? $userId : null, import_json($result), $jobId]);
            if (function_exists('logActivity')) {
                logActivity($userId, 'stock_import', 'Import #' . $jobId . ' (' . $job['original_name'] . '): ' . $result['created'] . ' ingredients created, ' . $result['updated'] . ' updated, opening count '
                    . ($result['opening_count_ref'] ?? 'none') . ' with ' . $result['count_lines'] . ' lines (submitted, waiting for approval).');
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
