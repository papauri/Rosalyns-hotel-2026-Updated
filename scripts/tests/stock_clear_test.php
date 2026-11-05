<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Clear Stock Data (admin/stock-clear.php + admin/includes/stock-clear-lib.php), owner feature 2026-11-06.
 *
 * Usage (from the repo root): php scripts/tests/stock_clear_test.php
 *
 * SAFETY: this test NEVER commits a clear. Everything happens inside ONE transaction that is rolled back at the end:
 * its own marker rows (names start ZZ_CLEARTEST_), two temporary accounts, and the clear + undo run with
 * 'external_tx' => true (the library then never begins/commits/rolls back). Snapshot files go to a temporary folder that
 * the test deletes. The full-database backup is skipped (it must not run inside a transaction). No DDL is run after
 * the transaction opens (DDL would silently commit it); the test checks inTransaction() after every library call.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0; $skip = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function skip(string $l): void { global $skip; $skip++; echo "SKIP $l\n"; }

require_once $root . '/config/database.php';
require_once $root . '/config/security.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/stock-clear-lib.php';

$tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/zz_cleartest_' . bin2hex(random_bytes(4));
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
function cleanup_and_exit(int $code): void
{
    global $tmpRoot, $pass, $fail, $skip;
    rm_tree($tmpRoot);
    echo "\n$pass passed, $fail failed, $skip skipped\n";
    exit($code);
}
function guard_tx(PDO $pdo, string $where): void
{
    if (!$pdo->inTransaction()) {
        echo "FATAL: the test transaction is gone after $where. Stopping. (Nothing the test did can be rolled back; check the database.)\n";
        cleanup_and_exit(2);
    }
}

/** Insert one row, filling every required column with a harmless value. Returns the new id. */
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
        else { $row[$n] = 'ZZ_CLEARTEST_'; }
    }
    $k = array_keys($row);
    $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $k) . '`) VALUES (' . implode(',', array_fill(0, count($k), '?')) . ')')->execute(array_values($row));
    return (int)$pdo->lastInsertId();
}
function cnt(PDO $pdo, string $t): int { return (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
function has(PDO $pdo, string $t): bool { return rh_sc_table_exists($pdo, $t); }

// ---- 0) static facts about the library (no database) ------------------------------------------------------------------
$src = php_strip_whitespace($root . '/admin/includes/stock-clear-lib.php'); // code only, comments dropped
check(stripos($src, 'TRUNCATE') === false && stripos($src, 'FOREIGN_KEY_CHECKS') === false && stripos($src, "LIKE 'stock") === false,
    'the library never uses TRUNCATE, FOREIGN_KEY_CHECKS or LIKE stock%');
check(!preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $src), 'the library runs no DDL (DDL would silently commit the transaction)');

$area = rh_sc_area('stock');
$never = ['stock_orders', 'stock_order_items', 'stock_order_audit', 'stock_order_deliveries', 'stock_order_splits', 'stock_kds_events', 'stock_shift_closes', 'stock_shift_opens', 'payments', 'menu_items', 'import_aliases', 'import_jobs'];
check(!array_intersect($never, $area['order']), 'restaurant order, shift, money, menu and import tables are not in the registry');
check(count($area['order']) === 18 && count(array_unique($area['order'])) === 18, 'the registry lists exactly the 18 approved stock tables');
$o = array_flip($area['order']);
check($o['stock_batch_deductions'] < $o['stock_adjustments'] && $o['stock_batch_deductions'] < $o['stock_batches']
    && $o['stock_in_log'] < $o['stock_batches'] && $o['stock_wastage'] < $o['stock_batches'] && $o['stock_count_lines'] < $o['stock_counts']
    && $o['stock_recipe_ingredients'] < $o['stock_recipes'] && $o['stock_batches'] < ($o['stock_ingredients'])
    && $o['stock_adjustments'] < $o['stock_ingredients'] && $o['stock_ingredient_barcodes'] < $o['stock_ingredients'], 'delete order puts children before parents');
$r = rh_sc_resolve('stock', ['ingredients']);
check(in_array('purchase_orders', $r['groups'], true) && isset($r['forced']['purchase_orders']), 'ticking stock items forces purchase orders (and says why)');
check(in_array('stock_recipe_ingredients', $r['tables'], true) && !in_array('stock_recipes', $r['tables'], true), 'stock items remove recipe lines but keep the recipes');
$r = rh_sc_resolve('stock', ['suppliers']);
check($r['tables'] === ['stock_suppliers'] && !$r['forced'], 'suppliers alone clears only the suppliers table');
$r = rh_sc_resolve('stock', ['nope']);
check($r['unknown'] === ['nope'] && !$r['groups'], 'an unknown option is reported, never guessed');
$mgr = getDefaultPermissionsForRole('manager');
check(!in_array('data_reset', (array)$mgr, true), 'data_reset is not in the manager defaults');
check(isset(getAllPermissions()['data_reset']), 'data_reset is a defined permission');
$pageSrc = (string)file_get_contents($root . '/admin/stock-clear.php');
check(strpos($pageSrc, "'admin'") !== false && strpos($pageSrc, 'validateCsrfToken') !== false && strpos($pageSrc, 'rh_sc_check_confirm') !== false,
    'the page hard-checks the admin role, checks CSRF and the CLEAR word + password');

// ---- tables needed -----------------------------------------------------------------------------------------------------
if (function_exists('ensureStockTablesExist')) { ensureStockTablesExist(); } // DDL: before the transaction, never inside it
$needed = ['stock_ingredients', 'stock_batches', 'stock_adjustments', 'stock_recipes', 'stock_recipe_ingredients', 'stock_suppliers', 'admin_users'];
foreach ($needed as $t) {
    if (!has($pdo, $t)) { echo "Table $t is missing on this database: the live checks cannot run.\n"; cleanup_and_exit(1); }
}

// ---- 1) everything below runs inside ONE transaction that is rolled back --------------------------------------------------
$pdo->beginTransaction();
try {
    $pw = 'ZZ_Cleartest_pw_9!';
    $adminId = put($pdo, 'admin_users', ['username' => 'ZZ_CLEARTEST_admin', 'email' => 'zz_cleartest_admin@example.invalid', 'full_name' => 'ZZ_CLEARTEST Admin', 'role' => 'admin', 'is_active' => 1, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
    $mgrId = put($pdo, 'admin_users', ['username' => 'ZZ_CLEARTEST_mgr', 'email' => 'zz_cleartest_mgr@example.invalid', 'full_name' => 'ZZ_CLEARTEST Manager', 'role' => 'manager', 'is_active' => 1, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
    guard_tx($pdo, 'temporary accounts');
    $actor = ['id' => $adminId, 'username' => 'ZZ_CLEARTEST_admin', 'full_name' => 'ZZ_CLEARTEST Admin'];

    // access + confirmation
    [$okA] = rh_sc_authorize($pdo, $adminId);
    [$okM, $whyM] = rh_sc_authorize($pdo, $mgrId);
    check($okA === true, 'an administrator is allowed');
    check($okM === false && stripos($whyM, 'administrator') !== false, 'a manager is refused with a plain reason');
    [$okW] = rh_sc_check_confirm($pdo, $adminId, 'CLEAR', 'clear', $pw);
    [$okP, $whyP] = rh_sc_check_confirm($pdo, $adminId, 'CLEAR', 'CLEAR', 'wrong-password');
    [$okE] = rh_sc_check_confirm($pdo, $adminId, 'CLEAR', 'CLEAR', '');
    [$okG] = rh_sc_check_confirm($pdo, $adminId, 'CLEAR', 'CLEAR', $pw);
    check($okW === false, 'the word must be typed exactly (lower case refused)');
    check($okP === false && strpos($whyP, 'Nothing was changed') === 0, 'a wrong password is refused');
    check($okE === false, 'an empty password is refused');
    check($okG === true, 'the right word and own password are accepted');
    [$okU] = rh_sc_check_confirm($pdo, $adminId, 'UNDO', 'CLEAR', $pw);
    check($okU === false, 'the undo word is UNDO (CLEAR is refused there)');

    // marker rows
    $supId = put($pdo, 'stock_suppliers', ['name' => 'ZZ_CLEARTEST_supplier']);
    $ingId = put($pdo, 'stock_ingredients', ['name' => 'ZZ_CLEARTEST_item']);
    $batchId = put($pdo, 'stock_batches', ['ingredient_id' => $ingId, 'supplier_id' => $supId]);
    $adjId = put($pdo, 'stock_adjustments', ['ingredient_id' => $ingId]);
    $recId = put($pdo, 'stock_recipes', []);
    $lineId = put($pdo, 'stock_recipe_ingredients', ['recipe_id' => $recId, 'ingredient_id' => $ingId]);
    $alertId = $cacheId = 0;
    if (has($pdo, 'ai_alerts')) {
        $alertId = put($pdo, 'ai_alerts', ['user_id' => $adminId, 'alert_key' => 'wastage:ZZ_CLEARTEST', 'title' => 'ZZ_CLEARTEST alert', 'link_url' => 'stock-wastage.php']);
    }
    if (has($pdo, 'ai_review_cache')) {
        $cacheId = put($pdo, 'ai_review_cache', ['area' => 'stock', 'cache_day' => '2000-01-01', 'data_hash' => str_repeat('a', 64), 'cards_json' => '[]']);
    }
    guard_tx($pdo, 'marker rows');

    $clearTables = rh_sc_resolve('stock', ['ingredients', 'recipes', 'suppliers'])['tables'];
    $keepTables = array_values(array_filter(array_merge($area['order'], $never), static fn($t) => !in_array($t, $clearTables, true) && has($pdo, $t)));
    $before = [];
    foreach ($clearTables as $t) { if (has($pdo, $t)) { $before[$t] = cnt($pdo, $t); } }
    $keepBefore = [];
    foreach ($keepTables as $t) { $keepBefore[$t] = cnt($pdo, $t); }
    check(($before['stock_ingredients'] ?? 0) >= 1 && ($before['stock_suppliers'] ?? 0) >= 1, 'marker rows are in place');

    // guards on the clear function itself
    $g1 = rh_sc_run_clear($pdo, $tmpRoot, $actor, 'stock', ['suppliers'], ['full_backup' => false]); // not external while a transaction is open
    check($g1['ok'] === false && strpos($g1['error'], 'Nothing was changed') !== false, 'a clear without external_tx is refused while a transaction is open');
    $g2 = rh_sc_run_clear($pdo, $tmpRoot, $actor, 'stock', ['suppliers'], ['external_tx' => true]); // real backup requested inside a transaction
    check($g2['ok'] === false, 'a full backup is never run inside a transaction');
    $g3 = rh_sc_run_clear($pdo, $tmpRoot, $actor, 'stock', [], ['external_tx' => true, 'full_backup' => false]);
    check($g3['ok'] === false, 'ticking nothing clears nothing');
    guard_tx($pdo, 'guard checks');
    check(cnt($pdo, 'stock_suppliers') === $before['stock_suppliers'], 'refused clears changed nothing');

    // preview is read-only
    $pv = rh_sc_preview($pdo, 'stock', ['ingredients', 'recipes', 'suppliers']);
    check($pv['counts']['stock_ingredients'] === $before['stock_ingredients'] && strpos($pv['sentence'], 'stock item') !== false, 'the preview counts rows in plain words and changes nothing');

    // ---- the clear -------------------------------------------------------------------------------------------------------
    $c1 = rh_sc_run_clear($pdo, $tmpRoot, $actor, 'stock', ['ingredients', 'recipes', 'suppliers'], ['external_tx' => true, 'full_backup' => false]);
    guard_tx($pdo, 'the clear');
    check($c1['ok'] === true, 'the clear succeeds', (string)$c1['error']);
    if ($c1['ok']) {
        foreach ($clearTables as $t) {
            if (has($pdo, $t)) { check(cnt($pdo, $t) === 0, "$t is empty after the clear"); }
        }
        check(in_array('purchase_orders', $c1['groups'], true), 'the forced purchase-orders group was cleared too');
        $paths = rh_sc_paths($tmpRoot, $c1['id']);
        check(is_file($paths['snapshot']) && is_file($paths['manifest']), 'the snapshot and its manifest were written');
        $fc = rh_sc_snapshot_counts($paths['snapshot']);
        $man = rh_sc_manifest($tmpRoot, $c1['id']);
        foreach ($before as $t => $n) {
            if ($n > 0) { check(($fc[$t] ?? 0) === $n && (int)($man['tables'][$t] ?? -1) === $n, "snapshot and manifest hold $n row(s) of $t"); }
        }
        check(($man['by_username'] ?? '') === 'ZZ_CLEARTEST_admin' && !empty($man['created_at']) && !empty($man['groups']), 'the manifest records who, when and which groups');
        check(isset($fc['@alert_ids']) || !has($pdo, 'ai_alerts'), 'the alert ids are in the snapshot');
        if ($alertId) {
            $st = $pdo->prepare("SELECT resolved_at FROM ai_alerts WHERE id = ?"); $st->execute([$alertId]);
            check($st->fetchColumn() !== null, 'the open stock alert was closed');
            check(in_array($alertId, (array)($man['residue']['alert_ids'] ?? []), true), 'the closed alert id is in the manifest');
        }
        if ($cacheId) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM ai_review_cache WHERE area = 'stock'"); $st->execute();
            check((int)$st->fetchColumn() === 0, 'saved stock review panels were removed');
        }
        foreach ($keepTables as $t) { check(cnt($pdo, $t) === $keepBefore[$t], "$t (not part of this clear) is unchanged"); }
        $logged = $pdo->prepare("SELECT COUNT(*) FROM admin_activity_log WHERE user_id = ? AND action = 'stock_clear'"); $logged->execute([$adminId]);
        check((int)$logged->fetchColumn() === 1, 'the clear was written to the activity log');

        // ---- undo --------------------------------------------------------------------------------------------------------
        $u1 = rh_sc_run_undo($pdo, $tmpRoot, $actor, $c1['id'], ['external_tx' => true]);
        guard_tx($pdo, 'the undo');
        check($u1['ok'] === true, 'the undo succeeds', (string)$u1['error']);
        foreach ($before as $t => $n) { check(cnt($pdo, $t) === $n, "$t is back to $n row(s) after the undo"); }
        $st = $pdo->prepare("SELECT name FROM stock_ingredients WHERE id = ?"); $st->execute([$ingId]);
        check($st->fetchColumn() === 'ZZ_CLEARTEST_item', 'the same row came back with the same id');
        $u2 = rh_sc_run_undo($pdo, $tmpRoot, $actor, $c1['id'], ['external_tx' => true]);
        check($u2['ok'] === false && stripos($u2['error'], 'already undone') !== false, 'a clear cannot be undone twice');

        // ---- undo refuses when new stock was added since ----------------------------------------------------------------------
        $c2 = rh_sc_run_clear($pdo, $tmpRoot, $actor, 'stock', ['suppliers'], ['external_tx' => true, 'full_backup' => false]);
        guard_tx($pdo, 'the second clear');
        check($c2['ok'] === true && $c2['id'] !== $c1['id'], 'a second clear in the same second gets its own snapshot name', (string)$c2['error']);
        if ($c2['ok']) {
            $newSup = put($pdo, 'stock_suppliers', ['name' => 'ZZ_CLEARTEST_new_supplier']);
            $u3 = rh_sc_run_undo($pdo, $tmpRoot, $actor, $c2['id'], ['external_tx' => true]);
            check($u3['ok'] === false && strpos($u3['error'], 'New stock has been added since this clear') !== false, 'undo refuses, in plain words, when new rows exist');
            check(cnt($pdo, 'stock_suppliers') === 1, 'the refused undo changed nothing');
            $pdo->prepare("DELETE FROM stock_suppliers WHERE id = ?")->execute([$newSup]);
            $u4 = rh_sc_run_undo($pdo, $tmpRoot, $actor, $c2['id'], ['external_tx' => true]);
            check($u4['ok'] === true, 'undo works once the new row is gone', (string)$u4['error']);
            $u5 = rh_sc_run_undo($pdo, $tmpRoot, $actor, '../../etc/passwd', ['external_tx' => true]);
            check($u5['ok'] === false, 'a path-like clear id is refused');
        }
        $list = rh_sc_list_clears($tmpRoot);
        check(count($list) === 2 && strcmp($list[0]['id'], $list[1]['id']) > 0, 'past clears are listed newest first');

        // ---- pointers from KEPT rows (no foreign key): blanked by the clear, restored by undo ------------------------------------
        if (has($pdo, 'stock_purchase_orders')) {
            $poId = put($pdo, 'stock_purchase_orders', ['supplier_id' => $supId]);
            $pdo->prepare("UPDATE stock_ingredients SET preferred_supplier_id = ? WHERE id = ?")->execute([$supId, $ingId]);
            $pdo->prepare("UPDATE stock_batches SET supplier_id = ?, purchase_order_id = ? WHERE id = ?")->execute([$supId, $poId, $batchId]);
            $ptr = static function () use ($pdo, $ingId, $batchId, $poId): array {
                $a = $pdo->query("SELECT preferred_supplier_id FROM stock_ingredients WHERE id = " . (int)$ingId)->fetchColumn();
                $b = $pdo->query("SELECT supplier_id, purchase_order_id FROM stock_batches WHERE id = " . (int)$batchId)->fetch(PDO::FETCH_NUM);
                $c = $pdo->query("SELECT supplier_id FROM stock_purchase_orders WHERE id = " . (int)$poId)->fetchColumn();
                return [$a, $b[0], $b[1], $c];
            };
            $was = $ptr();
            check($was[0] !== null && $was[1] !== null && $was[2] !== null && $was[3] !== null, 'pointer test rows are set up');

            $s1 = rh_sc_run_clear($pdo, $tmpRoot, $actor, 'stock', ['suppliers'], ['external_tx' => true, 'full_backup' => false]);
            guard_tx($pdo, 'the suppliers-only clear');
            check($s1['ok'] === true, 'a suppliers-only clear succeeds', (string)$s1['error']);
            if ($s1['ok']) {
                $now = $ptr();
                check($now[0] === null && $now[1] === null && $now[3] === null, 'suppliers-only clear blanks the supplier on kept items, batches and purchase orders');
                check((string)$now[2] === (string)$was[2], 'suppliers-only clear leaves the purchase order number alone');
                $m1 = rh_sc_manifest($tmpRoot, $s1['id']);
                check(array_sum((array)($m1['fk_nulls'] ?? [])) >= 3, 'the manifest counts the blanked pointers');
                check(($sc = rh_sc_snapshot_counts(rh_sc_paths($tmpRoot, $s1['id'])['snapshot']))['@fk_nulls'] >= 3, 'the snapshot holds the old pointer values');
                $su = rh_sc_run_undo($pdo, $tmpRoot, $actor, $s1['id'], ['external_tx' => true]);
                guard_tx($pdo, 'the suppliers-only undo');
                check($su['ok'] === true && $ptr() === $was, 'undo puts the suppliers and every pointer back', (string)$su['error']);
            }

            $s2 = rh_sc_run_clear($pdo, $tmpRoot, $actor, 'stock', ['purchase_orders'], ['external_tx' => true, 'full_backup' => false]);
            guard_tx($pdo, 'the purchase-orders-only clear');
            check($s2['ok'] === true, 'a purchase-orders-only clear succeeds', (string)$s2['error']);
            if ($s2['ok']) {
                $now = $ptr();
                check($now[2] === null && (string)$now[1] === (string)$was[1] && (string)$now[0] === (string)$was[0], 'purchase-orders-only clear blanks the purchase order but keeps the supplier');
                $pu = rh_sc_run_undo($pdo, $tmpRoot, $actor, $s2['id'], ['external_tx' => true]);
                guard_tx($pdo, 'the purchase-orders-only undo');
                check($pu['ok'] === true && $ptr() === $was, 'undo puts the purchase orders and the pointer back', (string)$pu['error']);
            }
        } else {
            skip('purchase orders table missing: pointer checks');
        }
    }
} catch (Throwable $e) {
    bad('unexpected error inside the test transaction', $e->getMessage());
}

// ---- 2) roll everything back and prove nothing stayed ---------------------------------------------------------------------
if ($pdo->inTransaction()) {
    $pdo->rollBack();
    ok('the test transaction was rolled back');
} else {
    bad('the test transaction was already closed before the rollback');
}
$left = 0;
foreach (['stock_suppliers' => 'name', 'stock_ingredients' => 'name'] as $t => $col) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$col` LIKE ?"); $st->execute(['ZZ_CLEARTEST_%']);
    $left += (int)$st->fetchColumn();
}
$st = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username LIKE ?"); $st->execute(['ZZ_CLEARTEST_%']);
$left += (int)$st->fetchColumn();
check($left === 0, 'no test rows or accounts remain after the rollback');
check(!is_dir($root . '/backups/stock-clear') || !glob($root . '/backups/stock-clear/stock-clear-*ZZ*'), 'no test snapshot was written to the real backups folder');
cleanup_and_exit($fail > 0 ? 1 : 0);
