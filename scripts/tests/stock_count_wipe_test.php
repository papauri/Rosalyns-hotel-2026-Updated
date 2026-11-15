<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Stock count wipe (admin/includes/stock-clear-lib.php rh_sc_count_wipe_preview / rh_sc_run_count_wipe), owner 2026-11-15.
 *
 * Usage (from the repo root of the TEST hotel): php scripts/tests/stock_count_wipe_test.php
 *
 * SAFETY: refuses to run on Rosalyn's (LIVE). Never commits: everything happens inside ONE transaction rolled back at the end
 * (marker rows named ZZCW_, two temporary accounts, the hotel's own counts moved out of the way). The wipe runs with
 * 'external_tx' => true and 'full_backup' => false, so the library never begins/commits/rolls back and no backup is taken.
 * Block scenarios run inside SAVEPOINTs (not DDL: they never commit). Snapshot files go to a temporary folder that is deleted.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    exit("Refusing to run on Rosalyn's (live). Run this from the Liwonde repo.\n");
}
require_once $root . '/config/security.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/stock-clear-lib.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/zz_cwtest_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0775, true);
function rm_tree(string $d): void
{
    if (!is_dir($d)) { return; }
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $p = $d . '/' . $f;
        is_dir($p) ? rm_tree($p) : @unlink($p);
    }
    @rmdir($d);
}
function finish(int $code): void
{
    global $tmpRoot, $pass, $fail;
    rm_tree($tmpRoot);
    echo "\n$pass passed, $fail failed\n";
    exit($code);
}
function guard_tx(PDO $pdo, string $where): void
{
    if (!$pdo->inTransaction()) {
        echo "FATAL: the test transaction is gone after $where. Stopping.\n";
        finish(2);
    }
}
/** Insert one row, filling every required column with a harmless value. */
function put(PDO $pdo, string $table, array $set): int
{
    $cols = $pdo->prepare("SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE, COLUMN_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    $cols->execute([$table]);
    $row = $set;
    foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $n = $c['COLUMN_NAME'];
        if (array_key_exists($n, $row) || stripos((string)$c['EXTRA'], 'auto_increment') !== false || preg_match('/(VIRTUAL|STORED) GENERATED/i', (string)$c['EXTRA'])) { continue; }
        if ($c['IS_NULLABLE'] === 'YES' || $c['COLUMN_DEFAULT'] !== null) { continue; }
        $t = strtolower($c['DATA_TYPE']);
        if (in_array($t, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'float', 'double'], true)) { $row[$n] = 0; }
        elseif ($t === 'enum') { preg_match("/^enum\('([^']*)'/i", $c['COLUMN_TYPE'], $m); $row[$n] = $m[1] ?? ''; }
        elseif ($t === 'date') { $row[$n] = '2000-01-01'; }
        elseif (in_array($t, ['datetime', 'timestamp'], true)) { $row[$n] = '2000-01-01 00:00:00'; }
        elseif ($t === 'json') { $row[$n] = '{}'; }
        elseif ($t === 'char') { $row[$n] = str_repeat('0', 64); }
        else { $row[$n] = 'ZZCW_'; }
    }
    $k = array_keys($row);
    $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $k) . '`) VALUES (' . implode(',', array_fill(0, count($k), '?')) . ')')->execute(array_values($row));
    return (int)$pdo->lastInsertId();
}
function one(PDO $pdo, string $sql, array $p = [])
{
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
}
function qty(PDO $pdo, int $ingId): float { return (float)one($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$ingId]); }
function has_reason(array $blocked, string $needle): bool
{
    foreach ($blocked as $b) { if (stripos($b, $needle) !== false) { return true; } }
    return false;
}

// ---- static checks (no database) ------------------------------------------------------------------------------------------
$src = php_strip_whitespace($root . '/admin/includes/stock-clear-lib.php');
check(stripos($src, 'TRUNCATE') === false && stripos($src, 'FOREIGN_KEY_CHECKS') === false && stripos($src, "LIKE 'stock") === false
    && !preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $src), 'the library has no TRUNCATE, FOREIGN_KEY_CHECKS, LIKE stock% or DDL');
$pageSrc = (string)file_get_contents($root . '/admin/stock-clear.php');
check(strpos($pageSrc, "'WIPE'") !== false && strpos($pageSrc, 'cw_understand') !== false && strpos($pageSrc, 'rh_sc_run_count_wipe') !== false,
    'the page asks for WIPE, the "I understand" tick and runs the wipe through the library');
$footerPos = strpos($pageSrc, "admin-footer.php");
check($footerPos !== false && strpos($pageSrc, 'cw_wipe') < $footerPos, 'the wipe screens are printed before the footer (inside the SPA container)');

foreach (['stock_counts', 'stock_count_lines', 'stock_batches', 'stock_adjustments', 'stock_ingredients', 'stock_opening_batches', 'stock_batch_deductions', 'admin_users'] as $t) {
    if (!rh_sc_table_exists($pdo, $t)) { echo "Table $t is missing: the live checks cannot run.\n"; finish(1); }
}

$pdo->beginTransaction();
try {
    // The hotel's own counts are moved out of the way (rolled back at the end) so only the test counts are in scope.
    $pdo->exec("DELETE FROM stock_opening_batches WHERE count_id IN (SELECT id FROM stock_counts)");
    $pdo->exec("DELETE FROM stock_count_lines");
    $pdo->exec("DELETE FROM stock_counts");
    guard_tx($pdo, 'moving the hotel counts aside');

    $pw = 'ZZcw_test_pw_7!';
    $adminId = put($pdo, 'admin_users', ['username' => 'ZZCW_admin', 'email' => 'zzcw_admin@example.invalid', 'full_name' => 'ZZCW Admin', 'role' => 'admin', 'is_active' => 1, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
    $mgrId = put($pdo, 'admin_users', ['username' => 'ZZCW_mgr', 'email' => 'zzcw_mgr@example.invalid', 'full_name' => 'ZZCW Manager', 'role' => 'manager', 'is_active' => 1, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
    $actor = ['id' => $adminId, 'username' => 'ZZCW_admin', 'full_name' => 'ZZCW Admin'];
    // data_reset is Tier S: a super admin holds it (a plain administrator only when a super admin switches it on for them).
    if ((int)one($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'is_super_admin'") > 0) {
        $pdo->prepare("UPDATE admin_users SET is_super_admin = 1 WHERE id = ?")->execute([$adminId]);
    }

    // ---- authorise / confirm ---------------------------------------------------------------------------------------------
    [$okA] = rh_sc_authorize($pdo, $adminId);
    [$okM, $whyM] = rh_sc_authorize($pdo, $mgrId);
    check($okA === true, 'an administrator with data_reset is allowed');
    check($okM === false && $whyM !== '', 'a manager is refused with a plain reason');
    [$c1ok] = rh_sc_check_confirm($pdo, $adminId, 'WIPE', 'wipe', $pw);
    [$c2ok, $c2why] = rh_sc_check_confirm($pdo, $adminId, 'WIPE', 'WIPE', 'not-my-password');
    [$c3ok] = rh_sc_check_confirm($pdo, $adminId, 'WIPE', 'CLEAR', $pw);
    [$c4ok] = rh_sc_check_confirm($pdo, $adminId, 'WIPE', 'WIPE', $pw);
    check($c1ok === false, 'WIPE must be typed exactly (lower case refused)');
    check($c2ok === false && strpos($c2why, 'Nothing was changed') === 0, 'a wrong password is refused');
    check($c3ok === false, 'CLEAR is not accepted for a wipe');
    check($c4ok === true, 'WIPE with the own password is accepted');

    // ---- fixtures ------------------------------------------------------------------------------------------------------------
    $p = 'ZZCW' . substr(bin2hex(random_bytes(3)), 0, 5);
    $ingA = put($pdo, 'stock_ingredients', ['name' => 'ZZCW_item_A', 'unit' => 'kg', 'current_quantity' => 0, 'cost_per_unit' => 100]);
    $ingB = put($pdo, 'stock_ingredients', ['name' => 'ZZCW_item_B', 'unit' => 'pcs', 'current_quantity' => 0, 'cost_per_unit' => 50]);
    $ingC = put($pdo, 'stock_ingredients', ['name' => 'ZZCW_item_C', 'unit' => 'pcs', 'current_quantity' => 0, 'cost_per_unit' => 10]);
    $ingD = put($pdo, 'stock_ingredients', ['name' => 'ZZCW_item_D', 'unit' => 'pcs', 'current_quantity' => 0, 'cost_per_unit' => 10]);
    // B has a separate delivery batch (5) that the wipe must keep.
    $delivery = put($pdo, 'stock_batches', ['ingredient_id' => $ingB, 'batch_number' => 'ZZCW-DEL', 'quantity_received' => 5, 'quantity_remaining' => 5, 'cost_per_unit' => 50, 'received_date' => date('Y-m-d'), 'status' => 'active', 'notes' => 'ZZCW delivery']);
    $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + 5 WHERE id = ?")->execute([$ingB]);

    $mkCount = static function (string $ref, string $status, int $opening = 0) use ($pdo, $adminId): int {
        return put($pdo, 'stock_counts', ['reference' => $ref, 'count_date' => date('Y-m-d'), 'status' => $status, 'is_opening' => $opening, 'counted_by' => $adminId]);
    };
    /** Mirror of the approval's surplus posting: line + positive variance adjustment + active batch + on-hand up. Returns [lineId, adjId, batchId]. */
    $surplus = static function (int $countId, string $ref, int $ingId, float $q, float $cost) use ($pdo, $adminId): array {
        $line = put($pdo, 'stock_count_lines', ['count_id' => $countId, 'ingredient_id' => $ingId, 'system_quantity' => 0, 'actual_quantity' => $q, 'variance' => $q, 'cost_per_unit' => $cost]);
        $adj = put($pdo, 'stock_adjustments', ['ingredient_id' => $ingId, 'quantity_change' => $q, 'reason' => 'Stock count ' . $ref, 'source_type' => 'variance', 'source_id' => $countId, 'cost_at_time' => $cost, 'adjusted_by' => $adminId]);
        $batch = put($pdo, 'stock_batches', ['ingredient_id' => $ingId, 'batch_number' => 'ZZCW-' . $countId . '-' . $ingId, 'quantity_received' => $q, 'quantity_remaining' => $q, 'cost_per_unit' => $cost, 'received_date' => date('Y-m-d'), 'status' => 'active', 'notes' => 'Stock count surplus ' . $ref]);
        $pdo->prepare("UPDATE stock_count_lines SET adjustment_id = ? WHERE id = ?")->execute([$adj, $line]);
        $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ? WHERE id = ?")->execute([$q, $ingId]);
        return [$line, $adj, $batch];
    };
    // Two approved counts whose references share a prefix (P-1 and P-10): batches must never be confused.
    $c1 = $mkCount($p . '-1', 'approved', 1);
    [, , $bA1] = $surplus($c1, $p . '-1', $ingA, 4, 100);
    // B keeps its delivery batch after the wipe, so its count surplus must carry no cost (a costed surplus averaged the unit cost: blocked, see below).
    [, $adjB1, $bB1] = $surplus($c1, $p . '-1', $ingB, 3, 0);
    $ob1 = put($pdo, 'stock_opening_batches', ['count_id' => $c1, 'ingredient_id' => $ingA, 'quantity' => 4, 'applied_batch_id' => $bA1]);
    $c10 = $mkCount($p . '-10', 'approved');
    [, , $bA10] = $surplus($c10, $p . '-10', $ingA, 2, 100);
    // A draft count and a submitted opening count (no stock moved yet), with a pending opening batch.
    $cD = $mkCount($p . '-D', 'draft');
    put($pdo, 'stock_count_lines', ['count_id' => $cD, 'ingredient_id' => $ingD, 'system_quantity' => 0, 'actual_quantity' => 7]);
    $cS = $mkCount($p . '-S', 'submitted', 1);
    put($pdo, 'stock_count_lines', ['count_id' => $cS, 'ingredient_id' => $ingD, 'system_quantity' => 0, 'actual_quantity' => 9, 'cost_per_unit' => 10]);
    put($pdo, 'stock_opening_batches', ['count_id' => $cS, 'ingredient_id' => $ingD, 'quantity' => 9]);
    $alertId = rh_sc_table_exists($pdo, 'ai_alerts') ? put($pdo, 'ai_alerts', ['user_id' => $adminId, 'alert_key' => 'count_approval:ZZCW' . $c1, 'title' => 'ZZCW alert', 'link_url' => 'stock-count.php']) : 0;
    $cacheId = rh_sc_table_exists($pdo, 'ai_review_cache') ? put($pdo, 'ai_review_cache', ['area' => 'stock', 'cache_day' => '2000-01-01', 'data_hash' => str_repeat('b', 64), 'cards_json' => '[]']) : 0;
    $jobId = rh_sc_table_exists($pdo, 'import_jobs') ? put($pdo, 'import_jobs', ['target' => 'stock', 'original_name' => 'ZZCW_import.xlsx', 'sha256' => str_repeat('c', 64), 'status' => 'committed', 'result_json' => json_encode(['opening_count_id' => (string)$c1])]) : 0;
    guard_tx($pdo, 'fixtures');
    check(abs(qty($pdo, $ingA) - 6) < 0.0001 && abs(qty($pdo, $ingB) - 8) < 0.0001, 'fixtures: A on hand 6 (counts only), B on hand 8 (5 delivered + 3 counted)');

    // ---- preview ---------------------------------------------------------------------------------------------------------------
    $pv = rh_sc_count_wipe_preview($pdo);
    $T = $pv['totals'];
    check(!$pv['blocked'], 'the preview is not blocked', implode(' | ', $pv['blocked']));
    check($T['counts'] === 4 && $T['lines'] === 5 && $T['batches'] === 3 && $T['adjustments'] === 3 && $T['opening_batches'] === 2,
        'the preview counts 4 counts, 5 lines, 3 batches, 3 adjustments, 2 opening batches', json_encode($T));
    check($T['items'] === 2 && $T['items_to_zero'] === 1 && abs($T['value'] - 750) < 0.005 && abs($T['value_added'] - 600) < 0.005,
        'the preview: 2 items change, 1 to 0; stock value removed = valuation drop 750 (A 6x100, B 3x50), approvals added 600', json_encode($T));
    $byRef = [];
    foreach ($pv['counts'] as $r) { $byRef[$r['reference']] = $r; }
    check(($byRef[$p . '-1']['batches'] ?? -1) === 2 && ($byRef[$p . '-10']['batches'] ?? -1) === 1, 'references P-1 and P-10 each keep only their own batches');
    check(($byRef[$p . '-1']['is_opening'] ?? false) === true && abs(($byRef[$p . '-1']['value'] ?? 0) - 400) < 0.005 && ($byRef[$p . '-D']['status'] ?? '') === 'draft',
        'each count row shows opening yes/no, status and the value its approval added');
    $itemB = array_values(array_filter($pv['items'], static fn($i) => $i['id'] === $ingB))[0] ?? null;
    check($itemB && abs($itemB['after'] - 5) < 0.0001 && !$itemB['to_zero'], 'item B would go from 8 to 5 (its delivery batch stays)');
    if ($jobId) {
        check(in_array($jobId, array_column($pv['import_jobs'], 'id'), true), 'the Import history entry that created count P-1 is found (id stored as text)');
    }
    check(qty($pdo, $ingA) === 6.0 && (int)one($pdo, "SELECT COUNT(*) FROM stock_counts") === 4, 'the preview changed nothing');

    // ---- guards ------------------------------------------------------------------------------------------------------------------
    $g1 = rh_sc_run_count_wipe($pdo, $tmpRoot, $actor, ['full_backup' => false]);
    check($g1['ok'] === false && strpos($g1['error'], 'Nothing was changed') !== false, 'a wipe without external_tx is refused while a transaction is open');
    $g2 = rh_sc_run_count_wipe($pdo, $tmpRoot, $actor, ['external_tx' => true]);
    check($g2['ok'] === false, 'a full backup is never taken inside a transaction');
    guard_tx($pdo, 'guards');

    // ---- block rules: each refuses with nothing changed (SAVEPOINT, rolled back after) -------------------------------------------------
    $state = static function () use ($pdo, $ingA, $ingB): string {
        return implode('|', [one($pdo, "SELECT COUNT(*) FROM stock_counts"), one($pdo, "SELECT COUNT(*) FROM stock_count_lines"),
            one($pdo, "SELECT COUNT(*) FROM stock_batches"), one($pdo, "SELECT COUNT(*) FROM stock_adjustments"), qty($pdo, $ingA), qty($pdo, $ingB)]);
    };
    $scenarios = [
        '(a) a count batch partly used' => [static function () use ($pdo, $bA1, $ingA) {
            $pdo->prepare("UPDATE stock_batches SET quantity_remaining = 3 WHERE id = ?")->execute([$bA1]);
            $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - 1 WHERE id = ?")->execute([$ingA]);
        }, 'partly used'],
        '(a) a deduction recorded on a count batch' => [static function () use ($pdo, $bB1) {
            put($pdo, 'stock_batch_deductions', ['batch_id' => $bB1, 'quantity_deducted' => 0]);
        }, 'used by sales'],
        '(b) an approved count with a shortage' => [static function () use ($pdo, $c10, $p, $ingC, $adminId) {
            $line = put($pdo, 'stock_count_lines', ['count_id' => $c10, 'ingredient_id' => $ingC, 'system_quantity' => 2, 'actual_quantity' => 0, 'variance' => -2]);
            $adj = put($pdo, 'stock_adjustments', ['ingredient_id' => $ingC, 'quantity_change' => -2, 'reason' => 'Stock count ' . $p . '-10', 'source_type' => 'variance', 'source_id' => $c10, 'cost_at_time' => 10, 'adjusted_by' => $adminId]);
            $pdo->prepare("UPDATE stock_count_lines SET adjustment_id = ? WHERE id = ?")->execute([$adj, $line]);
        }, 'took stock off'],
        'an item that keeps stock after a costed count surplus' => [static function () use ($pdo, $adjB1) {
            $pdo->prepare("UPDATE stock_adjustments SET cost_at_time = 50 WHERE id = ?")->execute([$adjB1]);
        }, 'averaged with the count'],
        '(c) an item whose stock moved outside its batches' => [static function () use ($pdo, $ingB) {
            $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + 2 WHERE id = ?")->execute([$ingB]);
        }, 'moved since'],
        '(c) an item that would go below 0' => [static function () use ($pdo, $ingA) {
            $pdo->prepare("UPDATE stock_ingredients SET current_quantity = 1 WHERE id = ?")->execute([$ingA]);
        }, 'below 0'],
    ];
    foreach ($scenarios as $label => [$mutate, $needle]) {
        $pdo->exec('SAVEPOINT zzcw_sp');
        $mutate();
        $before = $state();
        $bp = rh_sc_count_wipe_preview($pdo);
        $br = rh_sc_run_count_wipe($pdo, $tmpRoot, $actor, ['external_tx' => true, 'full_backup' => false]);
        guard_tx($pdo, $label);
        check(has_reason($bp['blocked'], $needle) && has_reason($bp['blocked'], 'Use the full Stock reset above instead'), "$label: the preview is blocked with a plain reason", implode(' | ', $bp['blocked']));
        check($br['ok'] === false && strpos($br['error'], 'Nothing was changed') !== false && has_reason($br['blocked'], $needle), "$label: the wipe refuses", $br['error']);
        check($state() === $before, "$label: nothing changed");
        $pdo->exec('ROLLBACK TO SAVEPOINT zzcw_sp');
    }
    $pdo->exec('RELEASE SAVEPOINT zzcw_sp');
    guard_tx($pdo, 'block scenarios');
    check(!rh_sc_count_wipe_preview($pdo)['blocked'], 'after the scenarios are rolled back the preview is clear again');

    // ---- the wipe ----------------------------------------------------------------------------------------------------------------
    $w = rh_sc_run_count_wipe($pdo, $tmpRoot, $actor, ['external_tx' => true, 'full_backup' => false, 'delete_import_history' => true]);
    guard_tx($pdo, 'the wipe');
    check($w['ok'] === true, 'the wipe succeeds', $w['error'] . ' ' . implode(' | ', $w['blocked']));
    if ($w['ok']) {
        check((int)one($pdo, "SELECT COUNT(*) FROM stock_counts") === 0 && (int)one($pdo, "SELECT COUNT(*) FROM stock_count_lines") === 0, 'all counts (approved, draft, submitted) and their lines are gone');
        check((int)one($pdo, "SELECT COUNT(*) FROM stock_opening_batches WHERE count_id IN (?,?,?,?)", [$c1, $c10, $cD, $cS]) === 0, 'their opening batches are gone');
        check((int)one($pdo, "SELECT COUNT(*) FROM stock_batches WHERE id IN (?,?,?)", [$bA1, $bB1, $bA10]) === 0, 'the batches the approvals created are gone');
        check((int)one($pdo, "SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'variance' AND source_id IN (?,?)", [$c1, $c10]) === 0, 'the variance adjustments are gone');
        check(abs(qty($pdo, $ingA)) < 0.000001, 'item A (counts were its only stock) is back to 0');
        check(abs(qty($pdo, $ingB) - 5) < 0.000001, 'item B is back to 5');
        check(abs((float)one($pdo, "SELECT quantity_remaining FROM stock_batches WHERE id = ?", [$delivery]) - 5) < 0.000001, "item B's delivery batch is kept untouched");
        if ($alertId) {
            check(one($pdo, "SELECT resolved_at FROM ai_alerts WHERE id = ?", [$alertId]) !== null, 'the open count-approval alert is closed');
        }
        if ($cacheId) {
            check((int)one($pdo, "SELECT COUNT(*) FROM ai_review_cache WHERE area = 'stock'") === 0, 'saved stock review panels are removed');
        }
        $man = rh_sc_manifest($tmpRoot, $w['id']);
        check(is_array($man) && ($man['kind'] ?? '') === 'count_wipe' && ($man['undo'] ?? null) === false, 'the manifest says count_wipe with no undo');
        check((int)($man['tables']['stock_counts'] ?? 0) === 4 && (int)($man['tables']['stock_batches'] ?? 0) === 3 && abs((float)($man['value'] ?? 0) - 750) < 0.005, 'the manifest records what was removed and its value');
        $fc = rh_sc_snapshot_counts(rh_sc_paths($tmpRoot, $w['id'])['snapshot']);
        check(($fc['stock_counts'] ?? 0) === 4 && ($fc['stock_count_lines'] ?? 0) === 5 && ($fc['stock_adjustments'] ?? 0) === 3 && ($fc['@ingredient_before'] ?? 0) === 2, 'the snapshot holds every removed row and the items before');
        $u = rh_sc_run_undo($pdo, $tmpRoot, $actor, $w['id'], ['external_tx' => true]);
        check($u['ok'] === false && strpos($u['error'], 'Restore from the full backup') !== false, 'Undo refuses a count wipe and points to the full backup', $u['error']);
        $list = rh_sc_list_clears($tmpRoot);
        check($list && rh_sc_manifest_is_count_wipe($list[0]) && strpos(rh_sc_count_wipe_restore_text($list[0]), 'Restore from the full backup') === 0, 'the past-clears list shows it with the restore text');
        check((int)one($pdo, "SELECT COUNT(*) FROM admin_activity_log WHERE user_id = ? AND action = 'stock_count_wipe'", [$adminId]) === 1, 'the wipe is in the activity log');
        if ($jobId) {
            $ir = $w['import'][0] ?? [];
            check(($ir['job_id'] ?? 0) === $jobId && ($ir['ok'] ?? true) === false && $ir['error'] !== '', 'Import history removal is skipped (and reported) while the caller owns the transaction');
            check((int)one($pdo, "SELECT COUNT(*) FROM import_jobs WHERE id = ?", [$jobId]) === 1, 'the Import history entry is untouched by the wipe itself');
        }
        $again = rh_sc_run_count_wipe($pdo, $tmpRoot, $actor, ['external_tx' => true, 'full_backup' => false]);
        check($again['ok'] === false && stripos($again['error'], 'no stock counts') !== false, 'a second wipe finds nothing and changes nothing');
    }
} catch (Throwable $e) {
    bad('unexpected error inside the test transaction', $e->getMessage() . ' @' . $e->getLine());
}

if ($pdo->inTransaction()) {
    $pdo->rollBack();
    ok('the test transaction was rolled back');
} else {
    bad('the test transaction was already closed before the rollback');
}
$left = (int)one($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ['ZZCW\\_%']) + (int)one($pdo, "SELECT COUNT(*) FROM admin_users WHERE username LIKE ?", ['ZZCW\\_%'])
    + (int)one($pdo, "SELECT COUNT(*) FROM stock_counts WHERE reference LIKE ?", ['ZZCW%']);
check($left === 0, 'no test rows or accounts remain after the rollback');
finish($fail > 0 ? 1 : 0);
