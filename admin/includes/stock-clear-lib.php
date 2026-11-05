<?php
/**
 * Clear Stock Data - library (owner feature 2026-11-06).
 *
 * One registry of "areas" (only 'stock' ships) so other areas can be added later. For an area it knows the groups an
 * administrator can tick, the exact tables each group removes (an explicit whitelist, children before parents), and the
 * "residue" that must go with it (alerts, cached review panels, cached dashboard numbers).
 *
 * Safety design:
 *  - Whole tables only, by explicit name. Never TRUNCATE, never FOREIGN_KEY_CHECKS=0, never LIKE 'stock%'. Rows are
 *    deleted by id in chunks, inside ONE transaction, after the same rows were read FOR UPDATE and written to a snapshot.
 *  - The snapshot (backups/stock-clear/stock-clear-<id>.jsonl.gz + .manifest.json) is re-read and its counts verified
 *    before anything is deleted. Anything that goes wrong rolls back: "Nothing was changed".
 *  - A full database backup is taken BEFORE the transaction opens (it must never run inside one: dumping can commit).
 *  - No DDL (CREATE/ALTER/LOCK TABLES) is ever run here, because DDL silently commits an open transaction.
 *  - Undo re-inserts only that snapshot, parents first, and refuses if any of those tables has rows again.
 *  - Both functions accept 'external_tx' => true: the caller owns the transaction (used by the test, which rolls back).
 *
 * Never touched: stock_orders and its item/audit/delivery/split/KDS/shift tables, payments, menu_items, import_*.
 */

if (!defined('RH_SC_SNAPSHOT_DIR')) {
    define('RH_SC_SNAPSHOT_DIR', 'backups/stock-clear');
}

/** The areas that can be cleared. Only Stock ships; add another entry here to extend. */
function rh_sc_areas(): array
{
    static $areas = null;
    if ($areas !== null) {
        return $areas;
    }
    $areas = [
        'stock' => [
            'label' => 'Stock',
            // Delete order: children before parents. The snapshot is written in the reverse order (parents first) so Undo can insert as it reads.
            'order' => [
                'stock_batch_deductions', 'stock_in_log', 'stock_wastage', 'stock_adjustments', 'stock_ingredient_barcodes',
                'stock_count_lines', 'stock_counts', 'stock_recipe_ingredients', 'stock_recipes', 'stock_batches',
                'stock_opening_batches', 'stock_purchase_order_items', 'stock_purchase_orders', 'stock_nonstock_receipts',
                'stock_budget_audit', 'stock_budgets', 'stock_ingredients', 'stock_suppliers',
            ],
            'table_labels' => [
                'stock_batch_deductions'     => ['batch deduction', 'batch deductions', 'which batch each stock movement took from'],
                'stock_in_log'               => ['receiving log entry', 'receiving log entries', 'each delivery received into stock'],
                'stock_wastage'              => ['wastage record', 'wastage records', 'items written off as waste'],
                'stock_adjustments'          => ['stock adjustment', 'stock adjustments', 'every change to a quantity, with its reason'],
                'stock_ingredient_barcodes'  => ['barcode', 'barcodes', 'barcodes linked to stock items'],
                'stock_count_lines'          => ['count line', 'count lines', 'the quantity counted for each item'],
                'stock_counts'               => ['stock count', 'stock counts', 'each count session and its approval'],
                'stock_recipe_ingredients'   => ['recipe line', 'recipe lines', 'which stock item a menu item uses, and how much'],
                'stock_recipes'              => ['recipe', 'recipes', 'the recipe header for each menu item'],
                'stock_batches'              => ['batch', 'batches', 'delivery batches (FIFO) with their cost and expiry'],
                'stock_opening_batches'      => ['opening batch', 'opening batches', 'starting quantities entered when stock was set up'],
                'stock_purchase_order_items' => ['purchase order line', 'purchase order lines', 'the items on each purchase order'],
                'stock_purchase_orders'      => ['purchase order', 'purchase orders', 'orders placed with suppliers'],
                'stock_nonstock_receipts'    => ['non-stock receipt', 'non-stock receipts', 'purchases received that are not stock items'],
                'stock_budget_audit'         => ['budget history entry', 'budget history entries', 'who changed which budget'],
                'stock_budgets'              => ['stock budget', 'stock budgets', 'spending budgets'],
                'stock_ingredients'          => ['stock item', 'stock items', 'the stock items themselves'],
                'stock_suppliers'            => ['supplier', 'suppliers', 'the supplier list'],
            ],
            // Columns that point at a table with no foreign key. When the parent is cleared and the table holding the column is kept,
            // the pointers are set to NULL (and recorded in the snapshot as @fk_nulls so Undo can put them back).
            'fk_refs' => [
                'stock_suppliers' => [
                    ['stock_ingredients', 'preferred_supplier_id'], ['stock_batches', 'supplier_id'],
                    ['stock_in_log', 'supplier_id'], ['stock_purchase_orders', 'supplier_id'],
                ],
                'stock_purchase_orders' => [
                    ['stock_batches', 'purchase_order_id'], ['stock_in_log', 'purchase_order_id'], ['stock_budget_audit', 'purchase_order_id'],
                ],
            ],
            'groups' => [
                'ingredients' => [
                    'label'  => 'Stock items and everything recorded against them',
                    'helper' => 'Stock items, delivery batches, opening batches, stock counts, adjustments, wastage, the receiving log, barcodes and recipe lines. This is the main reset.',
                    'tables' => ['stock_batch_deductions', 'stock_in_log', 'stock_wastage', 'stock_adjustments', 'stock_ingredient_barcodes', 'stock_count_lines', 'stock_counts', 'stock_recipe_ingredients', 'stock_batches', 'stock_opening_batches', 'stock_ingredients'],
                    'forces' => ['purchase_orders' => 'Purchase order lines point at stock items, so they cannot be kept once the items are gone.'],
                    'note'   => 'Recipe lines (what each menu item uses) are removed too, because they point at the stock items. The recipes themselves stay unless you also tick Recipes.',
                ],
                'recipes' => [
                    'label'  => 'Recipes (how menu items use stock)',
                    'helper' => 'The recipe for each menu item and its recipe lines. The menu items themselves are not touched.',
                    'tables' => ['stock_recipe_ingredients', 'stock_recipes'],
                    'forces' => [],
                    'note'   => '',
                ],
                'suppliers' => [
                    'label'  => 'Suppliers',
                    'helper' => 'The supplier list. Batches and stock items you keep will no longer show a supplier. Undo puts it back.',
                    'tables' => ['stock_suppliers'],
                    'forces' => [],
                    'note'   => '',
                ],
                'purchase_orders' => [
                    'label'  => 'Purchase orders',
                    'helper' => 'Purchase orders, their lines and the non-stock receipts. Batches and receiving entries you keep will no longer show a purchase order. Undo puts it back.',
                    'tables' => ['stock_purchase_order_items', 'stock_purchase_orders', 'stock_nonstock_receipts'],
                    'forces' => [],
                    'note'   => '',
                ],
                'budgets' => [
                    'label'  => 'Stock budgets',
                    'helper' => 'Spending budgets and their change history.',
                    'tables' => ['stock_budget_audit', 'stock_budgets'],
                    'forces' => [],
                    'note'   => '',
                ],
            ],
        ],
    ];
    return $areas;
}

function rh_sc_area(string $area): ?array
{
    return rh_sc_areas()[$area] ?? null;
}

/** Plain-English "433 stock items, 77 opening batches" from table => count (zero counts left out). */
function rh_sc_sentence(string $area, array $counts): string
{
    $a = rh_sc_area($area);
    $parts = [];
    foreach ($counts as $table => $n) {
        $n = (int)$n;
        if ($n <= 0 || !isset($a['table_labels'][$table])) {
            continue;
        }
        $parts[] = number_format($n) . ' ' . ($n === 1 ? $a['table_labels'][$table][0] : $a['table_labels'][$table][1]);
    }
    return $parts ? implode(', ', $parts) : 'nothing';
}

/**
 * Which groups and tables a tick-list means, with dependencies applied.
 * @return array{groups:string[],forced:array<string,string>,tables:string[],unknown:string[]} tables are in delete order
 */
function rh_sc_resolve(string $area, array $keys): array
{
    $a = rh_sc_area($area);
    $out = ['groups' => [], 'forced' => [], 'tables' => [], 'unknown' => []];
    if (!$a) {
        $out['unknown'] = array_map('strval', $keys);
        return $out;
    }
    $picked = [];
    foreach ($keys as $k) {
        $k = (string)$k;
        if (isset($a['groups'][$k])) {
            $picked[$k] = true;
        } else {
            $out['unknown'][] = $k;
        }
    }
    // Forced dependencies (repeat until nothing new is added).
    do {
        $added = false;
        foreach (array_keys($picked) as $k) {
            foreach ($a['groups'][$k]['forces'] as $force => $why) {
                if (!isset($picked[$force])) {
                    $picked[$force] = true;
                    $out['forced'][$force] = $why;
                    $added = true;
                }
            }
        }
    } while ($added);
    $tables = [];
    foreach (array_keys($a['groups']) as $k) { // registry order keeps the result stable
        if (isset($picked[$k])) {
            $out['groups'][] = $k;
            foreach ($a['groups'][$k]['tables'] as $t) {
                $tables[$t] = true;
            }
        }
    }
    foreach ($a['order'] as $t) {
        if (isset($tables[$t])) {
            $out['tables'][] = $t;
        }
    }
    return $out;
}

function rh_sc_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

/** Only registry tables ever reach SQL. */
function rh_sc_assert_table(string $area, string $table): void
{
    $a = rh_sc_area($area);
    if (!$a || !in_array($table, $a['order'], true) || !preg_match('/^[a-z0-9_]+$/', $table)) {
        throw new RuntimeException('That table is not part of Clear Stock Data.');
    }
}

/** Columns of a table in order (generated columns left out: they cannot be written). */
function rh_sc_columns(PDO $pdo, string $table): array
{
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%VIRTUAL GENERATED%' AND EXTRA NOT LIKE '%STORED GENERATED%' ORDER BY ORDINAL_POSITION");
    $st->execute([$table]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Row counts for the given registry tables that exist. $lock true = locking read (current rows, inside a transaction). */
function rh_sc_counts(PDO $pdo, string $area, array $tables, bool $lock = false): array
{
    $out = [];
    foreach ($tables as $t) {
        rh_sc_assert_table($area, $t);
        if (!rh_sc_table_exists($pdo, $t)) {
            continue;
        }
        $out[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`" . ($lock ? ' FOR UPDATE' : ''))->fetchColumn();
    }
    return $out;
}

/** The residue that goes with a Stock clear, as counts (read-only). */
function rh_sc_residue_counts(PDO $pdo, string $area): array
{
    $r = ['alerts' => 0, 'review_cache' => 0, 'tool_cache' => 0];
    if ($area !== 'stock') {
        return $r;
    }
    if (rh_sc_table_exists($pdo, 'ai_alerts')) {
        $r['alerts'] = count(rh_sc_stock_alert_ids($pdo));
    }
    if (rh_sc_table_exists($pdo, 'ai_review_cache')) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM ai_review_cache WHERE area = ?");
        $st->execute(['stock']);
        $r['review_cache'] = (int)$st->fetchColumn();
    }
    return $r;
}

/** Ids of open (not resolved, not dismissed) Assistant alerts about stock. $lock = FOR UPDATE. */
function rh_sc_stock_alert_ids(PDO $pdo, bool $lock = false): array
{
    $st = $pdo->prepare("SELECT id FROM ai_alerts WHERE resolved_at IS NULL AND dismissed_at IS NULL
                           AND (link_url LIKE ? OR alert_key LIKE ? OR alert_key LIKE ?) ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute(['stock-%', 'wastage:%', 'count_approval:%']);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * What a tick-list would remove, without changing anything.
 * @return array{groups:array,forced:array,tables:array,counts:array,group_counts:array,total:int,residue:array,sentence:string}
 */
function rh_sc_preview(PDO $pdo, string $area, array $keys): array
{
    $res = rh_sc_resolve($area, $keys);
    $counts = rh_sc_counts($pdo, $area, $res['tables']);
    $a = rh_sc_area($area);
    $groupCounts = [];
    foreach ($a['groups'] as $k => $g) {
        $groupCounts[$k] = rh_sc_counts($pdo, $area, $g['tables']);
    }
    return $res + [
        'counts'       => $counts,
        'group_counts' => $groupCounts,
        'total'        => (int)array_sum($counts),
        'residue'      => rh_sc_residue_counts($pdo, $area),
        'sentence'     => rh_sc_sentence($area, $counts),
    ];
}

// ---------------------------------------------------------------------------------------------------------------------
// Who may do this
// ---------------------------------------------------------------------------------------------------------------------

/** Active administrator-role account that also holds data_reset. [ok, plain reason, user row]. */
function rh_sc_authorize(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare("SELECT id, username, full_name, role, is_active FROM admin_users WHERE id = ?");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || !(int)$u['is_active']) {
        return [false, 'This account is not active.', null];
    }
    if ((string)$u['role'] !== 'admin') {
        return [false, 'Only an administrator account can clear stock data.', null];
    }
    if (function_exists('hasPermission') && !hasPermission($userId, 'data_reset')) {
        return [false, 'This account does not have permission to clear stock data.', null];
    }
    return [true, '', $u];
}

/** The typed word must match exactly and the password must be this signed-in person's own. [ok, plain reason]. */
function rh_sc_check_confirm(PDO $pdo, int $userId, string $expectedWord, string $typedWord, string $password): array
{
    if ($typedWord !== $expectedWord) {
        return [false, 'Nothing was changed. To go ahead, type the word ' . $expectedWord . ' exactly, in capital letters.'];
    }
    if ($password === '') {
        return [false, 'Nothing was changed. Enter your own password to confirm.'];
    }
    $st = $pdo->prepare("SELECT password_hash FROM admin_users WHERE id = ? AND is_active = 1");
    $st->execute([$userId]);
    $hash = $st->fetchColumn();
    if (!is_string($hash) || $hash === '' || !password_verify($password, $hash)) {
        return [false, 'Nothing was changed. That password is not correct.'];
    }
    return [true, ''];
}

// ---------------------------------------------------------------------------------------------------------------------
// Snapshot files
// ---------------------------------------------------------------------------------------------------------------------

function rh_sc_dir(string $root, bool $create = true): string
{
    $dir = rtrim(str_replace('\\', '/', $root), '/') . '/' . RH_SC_SNAPSHOT_DIR;
    if ($create && !is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('The backup folder for this clear could not be created.');
        }
    }
    if ($create && is_dir($dir)) {
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('The backup folder for this clear cannot be written to.');
        }
    }
    return $dir;
}

function rh_sc_valid_id(string $id): bool
{
    return (bool)preg_match('/^\d{8}-\d{6}(-\d{1,2})?$/', $id);
}

function rh_sc_paths(string $root, string $id): array
{
    $dir = rh_sc_dir($root, false);
    return [
        'snapshot' => $dir . '/stock-clear-' . $id . '.jsonl.gz',
        'tmp'      => $dir . '/.stock-clear-' . $id . '.jsonl.gz.tmp',
        'manifest' => $dir . '/stock-clear-' . $id . '.manifest.json',
    ];
}

/** A snapshot id that is not used yet (a second clear in the same second gets a -1, -2 suffix). */
function rh_sc_new_id(string $root): string
{
    $base = date('Ymd-His');
    for ($i = 0; $i < 50; $i++) {
        $id = $i === 0 ? $base : $base . '-' . $i;
        $p = rh_sc_paths($root, $id);
        if (!is_file($p['snapshot']) && !is_file($p['manifest']) && !is_file($p['tmp'])) {
            return $id;
        }
    }
    throw new RuntimeException('Could not choose a name for the snapshot file.');
}

function rh_sc_manifest(string $root, string $id): ?array
{
    if (!rh_sc_valid_id($id)) {
        return null;
    }
    $p = rh_sc_paths($root, $id);
    if (!is_file($p['manifest'])) {
        return null;
    }
    $m = json_decode((string)@file_get_contents($p['manifest']), true);
    return is_array($m) && ($m['id'] ?? '') === $id ? $m : null;
}

/** Past clears, newest first. */
function rh_sc_list_clears(string $root, string $area = 'stock'): array
{
    $dir = rh_sc_dir($root, false);
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    foreach ((array)glob($dir . '/stock-clear-*.manifest.json') as $f) {
        if (!preg_match('/stock-clear-(\d{8}-\d{6}(?:-\d{1,2})?)\.manifest\.json$/', $f, $m)) {
            continue;
        }
        $man = rh_sc_manifest($root, $m[1]);
        if ($man && ($man['area'] ?? '') === $area) {
            $man['snapshot_exists'] = is_file(rh_sc_paths($root, $m[1])['snapshot']);
            $out[] = $man;
        }
    }
    usort($out, static fn($x, $y) => strcmp((string)$y['id'], (string)$x['id']));
    return $out;
}

/** Count rows per table in a snapshot file (streams it; also proves the file is readable gzip + JSON). */
function rh_sc_snapshot_counts(string $file): array
{
    $gz = @gzopen($file, 'rb');
    if (!$gz) {
        throw new RuntimeException('The snapshot file could not be opened.');
    }
    $counts = [];
    while (!gzeof($gz)) {
        $line = gzgets($gz);
        if ($line === false || trim($line) === '') {
            continue;
        }
        $o = json_decode($line, true);
        if (!is_array($o) || !isset($o['t'])) {
            gzclose($gz);
            throw new RuntimeException('The snapshot file is damaged.');
        }
        if ($o['t'] === '@header') {
            continue;
        }
        $counts[$o['t']] = ($counts[$o['t']] ?? 0) + 1;
    }
    gzclose($gz);
    return $counts;
}

/**
 * Pointer columns (no foreign key) that would be left dangling by clearing $tables: the parent is cleared, the table holding
 * the column is kept, and both table and column exist. Names come from the registry only.
 * @return array<int,array{0:string,1:string}>
 */
function rh_sc_fk_refs(PDO $pdo, string $area, array $tables): array
{
    $a = rh_sc_area($area);
    $out = [];
    foreach (($a['fk_refs'] ?? []) as $parent => $refs) {
        if (!in_array($parent, $tables, true)) {
            continue;
        }
        foreach ($refs as [$t, $c]) {
            if (in_array($t, $tables, true) || !rh_sc_table_exists($pdo, $t)) {
                continue;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $st->execute([$t, $c]);
            if ((int)$st->fetchColumn() > 0) {
                $out[] = [$t, $c];
            }
        }
    }
    return $out;
}

function rh_sc_write_line($gz, array $o): void
{
    $j = json_encode($o, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    if ($j === false || @gzwrite($gz, $j . "\n") === false) {
        throw new RuntimeException('The snapshot could not be written (is the disk full?).');
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Full-database safety backup (the same call the Backups screen makes)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Must be called OUTSIDE any transaction. Returns ['ok'=>bool, 'path'=>?string, 'error'=>string].
 * stamp is false on Windows so a laptop copy never stamps "last backup" on the shared database.
 */
function rh_sc_full_backup(PDO $pdo, string $root): array
{
    if (!function_exists('rh_db_backup_run')) {
        require_once dirname(__DIR__, 2) . '/includes/db-backup.php';
    }
    @set_time_limit(900);
    @ignore_user_abort(true);
    $r = rh_db_backup_run($pdo, $root, ['stamp' => PHP_OS_FAMILY !== 'Windows']);
    if (!empty($r['ok'])) {
        return ['ok' => true, 'path' => (string)$r['path'], 'error' => ''];
    }
    if (!empty($r['skipped'])) {
        return ['ok' => false, 'path' => null, 'error' => 'another backup is running right now'];
    }
    return ['ok' => false, 'path' => null, 'error' => (string)($r['error'] ?? 'the backup did not finish')];
}

// ---------------------------------------------------------------------------------------------------------------------
// Clear
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Clear the ticked groups.
 *
 * @param array $actor  ['id'=>int,'username'=>string,'full_name'=>string]
 * @param array $opts   external_tx (bool)   caller owns the transaction: no begin/commit/rollback here (tests)
 *                      full_backup (callable|false)  default = the real full backup; false = skip (tests only; not allowed without external_tx)
 * @return array{ok:bool,error:string,id:string,counts:array,sentence:string,snapshot:string,full_backup:string,groups:array,forced:array,residue:array}
 */
function rh_sc_run_clear(PDO $pdo, string $root, array $actor, string $area, array $groupKeys, array $opts = []): array
{
    $fail = static function (string $why): array {
        return ['ok' => false, 'error' => $why . ' Nothing was changed.', 'id' => '', 'counts' => [], 'sentence' => '', 'snapshot' => '', 'full_backup' => '', 'groups' => [], 'forced' => [], 'residue' => []];
    };
    $external = !empty($opts['external_tx']);
    $a = rh_sc_area($area);
    if (!$a) {
        return $fail('That area cannot be cleared.');
    }
    $res = rh_sc_resolve($area, $groupKeys);
    if ($res['unknown'] || !$res['groups']) {
        return $fail($res['unknown'] ? 'One of the ticked options is not recognised.' : 'Nothing was ticked.');
    }
    $tables = array_values(array_filter($res['tables'], static fn($t) => rh_sc_table_exists($pdo, $t)));
    if ($external !== $pdo->inTransaction()) {
        return $fail($external ? 'No transaction is open.' : 'Another change is in progress on this connection.');
    }

    // 1) Folder + full database backup, before any transaction opens.
    try {
        $dir = rh_sc_dir($root);
    } catch (Throwable $e) {
        return $fail($e->getMessage());
    }
    $fullBackupName = '';
    $fb = array_key_exists('full_backup', $opts) ? $opts['full_backup'] : 'real';
    if ($fb === false) {
        if (!$external) {
            return $fail('The safety backup cannot be skipped.');
        }
        $fullBackupName = '(skipped in test)';
    } else {
        if ($external) {
            return $fail('A safety backup cannot be taken inside a transaction.');
        }
        $b = is_callable($fb) ? $fb($pdo, $root) : rh_sc_full_backup($pdo, $root);
        if (empty($b['ok'])) {
            return $fail('The safety backup of the whole database could not be saved (' . trim((string)($b['error'] ?? 'unknown reason')) . ').');
        }
        $fullBackupName = basename((string)$b['path']);
    }

    $id = rh_sc_new_id($root);
    $p = rh_sc_paths($root, $id);
    $counts = [];
    $residueInfo = ['alerts_resolved' => 0, 'review_cache_rows' => 0, 'tool_cache_rows' => 0];
    $started = false;
    $manifestWritten = false;
    try {
        // 2) One transaction from here: read FOR UPDATE -> snapshot -> verify -> delete -> residue -> commit.
        if (!$external) {
            $pdo->beginTransaction();
            $started = true;
        }
        $ids = [];
        $gz = @gzopen($p['tmp'], 'wb6');
        if (!$gz) {
            throw new RuntimeException('The snapshot file could not be created.');
        }
        rh_sc_write_line($gz, ['t' => '@header', 'id' => $id, 'area' => $area, 'at' => date('Y-m-d H:i:s')]);
        foreach (array_reverse($tables) as $t) { // parents first, so Undo can insert in file order
            rh_sc_assert_table($area, $t);
            $counts[$t] = 0;
            $ids[$t] = [];
            $last = 0;
            do {
                $st = $pdo->prepare("SELECT * FROM `$t` WHERE id > ? ORDER BY id LIMIT 500 FOR UPDATE");
                $st->execute([$last]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    rh_sc_write_line($gz, ['t' => $t, 'r' => $row]);
                    $ids[$t][] = (int)$row['id'];
                    $last = (int)$row['id'];
                    $counts[$t]++;
                }
            } while (count($rows) === 500);
        }
        // Residue that goes with a Stock clear: open alerts (ids are kept), cached review panels (rows are kept), cached tool answers.
        $alertIds = [];
        $reviewIds = [];
        if ($area === 'stock') {
            if (rh_sc_table_exists($pdo, 'ai_alerts')) {
                $alertIds = rh_sc_stock_alert_ids($pdo, true);
                rh_sc_write_line($gz, ['t' => '@alert_ids', 'ids' => $alertIds]);
            }
            if (rh_sc_table_exists($pdo, 'ai_review_cache')) {
                $st = $pdo->prepare("SELECT * FROM ai_review_cache WHERE area = ? FOR UPDATE");
                $st->execute(['stock']);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    rh_sc_write_line($gz, ['t' => '@ai_review_cache', 'r' => $row]);
                    $reviewIds[] = (int)$row['id'];
                }
            }
        }
        // Pointers (no foreign key) from KEPT tables to rows being removed: noted now, set to NULL below, put back by Undo.
        $fkRows = [];
        $fkTotal = 0;
        foreach (rh_sc_fk_refs($pdo, $area, $tables) as [$ft, $fc]) {
            rh_sc_assert_table($area, $ft);
            if (!preg_match('/^[a-z0-9_]+$/', $fc)) {
                throw new RuntimeException('Unexpected column name.');
            }
            $st = $pdo->prepare("SELECT id, `$fc` AS v FROM `$ft` WHERE `$fc` IS NOT NULL ORDER BY id FOR UPDATE");
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                rh_sc_write_line($gz, ['t' => '@fk_nulls', 'table' => $ft, 'col' => $fc, 'id' => (int)$row['id'], 'val' => $row['v']]);
                $fkRows[$ft . '.' . $fc][] = (int)$row['id'];
                $fkTotal++;
            }
        }
        gzclose($gz);

        // 3) Re-read the file and check it matches what was read from the database.
        $fileCounts = rh_sc_snapshot_counts($p['tmp']);
        foreach ($counts as $t => $n) {
            if ((int)($fileCounts[$t] ?? 0) !== $n) {
                throw new RuntimeException('The snapshot did not match the data (' . $t . ').');
            }
        }
        if ((int)($fileCounts['@fk_nulls'] ?? 0) !== $fkTotal) {
            throw new RuntimeException('The snapshot did not match the data (pointers to removed rows).');
        }
        if (!@rename($p['tmp'], $p['snapshot'])) {
            throw new RuntimeException('The snapshot file could not be saved.');
        }
        $manifest = [
            'id'              => $id,
            'area'            => $area,
            'created_at'      => date('Y-m-d H:i:s'),
            'by_user_id'      => (int)($actor['id'] ?? 0),
            'by_username'     => (string)($actor['username'] ?? ''),
            'by_name'         => (string)($actor['full_name'] ?? ''),
            'groups'          => $res['groups'],
            'forced'          => array_keys($res['forced']),
            'tables'          => $counts,
            'residue'         => ['alert_ids' => $alertIds, 'review_cache_rows' => count($reviewIds)],
            'fk_nulls'        => array_map('count', $fkRows),
            'snapshot_file'  => basename($p['snapshot']),
            'snapshot_bytes'  => (int)@filesize($p['snapshot']),
            'full_backup'     => $fullBackupName,
            'undone_at'       => null,
            'undone_by'       => null,
        ];
        if (@file_put_contents($p['manifest'], json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException('The snapshot description could not be saved.');
        }
        $manifestWritten = true;

        // 4) Clear the pointers to the removed rows (kept rows only), then delete by id, children first.
        foreach ($fkRows as $ref => $rowIds) {
            [$ft, $fc] = explode('.', $ref, 2);
            foreach (array_chunk($rowIds, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $pdo->prepare("UPDATE `$ft` SET `$fc` = NULL WHERE id IN ($in)")->execute($chunk);
            }
        }
        foreach ($tables as $t) {
            foreach (array_chunk($ids[$t], 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $pdo->prepare("DELETE FROM `$t` WHERE id IN ($in)")->execute($chunk);
            }
        }
        // 5) Residue.
        if ($area === 'stock') {
            foreach (array_chunk($alertIds, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $s = $pdo->prepare("UPDATE ai_alerts SET resolved_at = NOW() WHERE resolved_at IS NULL AND dismissed_at IS NULL AND id IN ($in)");
                $s->execute($chunk);
                $residueInfo['alerts_resolved'] += $s->rowCount();
            }
            foreach (array_chunk($reviewIds, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $s = $pdo->prepare("DELETE FROM ai_review_cache WHERE area = ? AND id IN ($in)");
                $s->execute(array_merge(['stock'], $chunk));
                $residueInfo['review_cache_rows'] += $s->rowCount();
            }
            if (rh_sc_table_exists($pdo, 'ai_tool_cache')) {
                // Cached Assistant tool answers live up to 2 days (ai-memory.php); drop only the ones that talk about stock.
                $s = $pdo->prepare("DELETE FROM ai_tool_cache WHERE expires_at > NOW()
                                      AND (result_json LIKE ? OR result_json LIKE ? OR result_json LIKE ? OR result_json LIKE ?)");
                $s->execute(['%stock%', '%ingredient%', '%recipe%', '%supplier%']);
                $residueInfo['tool_cache_rows'] = $s->rowCount();
            }
        }
        // 6) Prove nothing is left (locking read sees current rows, including any that slipped in).
        foreach (rh_sc_counts($pdo, $area, $tables, true) as $t => $n) {
            if ($n !== 0) {
                throw new RuntimeException('Some rows were added while the clear was running (' . $t . ').');
            }
        }
        if ($started) {
            $pdo->commit();
            $started = false;
        }
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $e2) { /* connection gone: nothing was committed */ }
        }
        if (isset($gz) && is_resource($gz)) {
            @gzclose($gz);
        }
        foreach ([$p['tmp'], $p['snapshot'], $manifestWritten || is_file($p['manifest']) ? $p['manifest'] : ''] as $f) {
            if ($f !== '' && is_file($f)) {
                @unlink($f);
            }
        }
        error_log('Clear Stock Data failed: ' . $e->getMessage());
        return $fail('The clear did not complete (' . rtrim($e->getMessage(), '.') . ').');
    }

    // 7) After the commit: cached numbers, then the activity log.
    if (function_exists('rh_clear_stock_dashboard_cache')) {
        rh_clear_stock_dashboard_cache();
    }
    if (function_exists('logActivity')) {
        logActivity((int)($actor['id'] ?? 0), 'stock_clear',
            'Cleared stock data (' . implode(', ', $res['groups']) . '): ' . rh_sc_sentence($area, $counts)
            . '. Snapshot ' . basename($p['snapshot']) . '; full backup ' . ($fullBackupName !== '' ? $fullBackupName : 'none')
            . '; ' . $residueInfo['alerts_resolved'] . ' alert(s) closed.');
    }
    return [
        'ok' => true, 'error' => '', 'id' => $id, 'counts' => $counts, 'sentence' => rh_sc_sentence($area, $counts),
        'snapshot' => basename($p['snapshot']), 'full_backup' => $fullBackupName, 'groups' => $res['groups'],
        'forced' => $res['forced'], 'residue' => $residueInfo,
    ];
}

// ---------------------------------------------------------------------------------------------------------------------
// Undo
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Put back exactly what one clear removed. Refuses (changing nothing) if any of those tables has rows again.
 * @return array{ok:bool,error:string,counts:array,sentence:string}
 */
function rh_sc_run_undo(PDO $pdo, string $root, array $actor, string $id, array $opts = []): array
{
    $fail = static function (string $why): array {
        return ['ok' => false, 'error' => $why, 'counts' => [], 'sentence' => ''];
    };
    $external = !empty($opts['external_tx']);
    if (!rh_sc_valid_id($id)) {
        return $fail('Nothing was changed. That is not a valid clear.');
    }
    $man = rh_sc_manifest($root, $id);
    if (!$man) {
        return $fail('Nothing was changed. The record for that clear was not found.');
    }
    $area = (string)($man['area'] ?? '');
    $a = rh_sc_area($area);
    if (!$a) {
        return $fail('Nothing was changed. That clear belongs to an area this version cannot undo.');
    }
    if (!empty($man['undone_at'])) {
        return $fail('Nothing was changed. This clear was already undone on ' . (string)$man['undone_at'] . '.');
    }
    $p = rh_sc_paths($root, $id);
    if (!is_file($p['snapshot'])) {
        return $fail('Nothing was changed. The snapshot file for that clear is no longer on the server.');
    }
    $want = [];
    foreach ((array)($man['tables'] ?? []) as $t => $n) {
        if (in_array($t, $a['order'], true) && (int)$n > 0) {
            $want[$t] = (int)$n;
        }
    }
    if ($external !== $pdo->inTransaction()) {
        return $fail('Nothing was changed. ' . ($external ? 'No transaction is open.' : 'Another change is in progress on this connection.'));
    }
    try {
        $fileCounts = rh_sc_snapshot_counts($p['snapshot']);
    } catch (Throwable $e) {
        return $fail('Nothing was changed. The snapshot file is damaged, so it cannot be used.');
    }
    foreach ($want as $t => $n) {
        if ((int)($fileCounts[$t] ?? 0) !== $n) {
            return $fail('Nothing was changed. The snapshot file does not match its description, so it cannot be used.');
        }
    }
    if ((int)($fileCounts['@fk_nulls'] ?? 0) !== (int)array_sum((array)($man['fk_nulls'] ?? []))) {
        return $fail('Nothing was changed. The snapshot file does not match its description, so it cannot be used.');
    }
    $started = false;
    try {
        if (!$external) {
            $pdo->beginTransaction();
            $started = true;
        }
        foreach (array_keys($want) as $t) {
            rh_sc_assert_table($area, $t);
            if (!rh_sc_table_exists($pdo, $t)) {
                throw new RuntimeException('A table needed for the undo no longer exists (' . $t . ').');
            }
        }
        // Refuse if anything has been added since: old and new rows must never mix.
        foreach (rh_sc_counts($pdo, $area, array_keys($want), true) as $t => $n) {
            if ($n > 0) {
                if ($started) { $pdo->rollBack(); $started = false; }
                return $fail('New stock has been added since this clear. Undo would mix old and new data, so nothing was changed.');
            }
        }
        $gz = @gzopen($p['snapshot'], 'rb');
        if (!$gz) {
            throw new RuntimeException('The snapshot file could not be opened.');
        }
        $cols = [];
        $buf = [];
        $bufTable = '';
        $flush = static function () use ($pdo, &$buf, &$bufTable): void {
            if (!$buf) {
                return;
            }
            $keys = array_keys($buf[0]);
            $one = '(' . implode(',', array_fill(0, count($keys), '?')) . ')';
            $sql = 'INSERT INTO `' . $bufTable . '` (`' . implode('`,`', $keys) . '`) VALUES ' . implode(',', array_fill(0, count($buf), $one));
            $vals = [];
            foreach ($buf as $r) {
                foreach ($keys as $k) {
                    $vals[] = $r[$k];
                }
            }
            $pdo->prepare($sql)->execute($vals);
            $buf = [];
        };
        $inserted = [];
        while (!gzeof($gz)) {
            $line = gzgets($gz);
            if ($line === false || trim($line) === '') {
                continue;
            }
            $o = json_decode($line, true, 512, JSON_BIGINT_AS_STRING);
            if (!is_array($o) || !isset($o['t'])) {
                throw new RuntimeException('The snapshot file is damaged.');
            }
            $t = (string)$o['t'];
            if ($t === '' || $t[0] === '@' || !isset($want[$t])) {
                continue; // header, residue records and tables that were empty are not restored
            }
            if ($t !== $bufTable) {
                $flush();
                $bufTable = $t;
            }
            if (!isset($cols[$t])) {
                $cols[$t] = rh_sc_columns($pdo, $t); // column names come from the live table, never from the file
            }
            $row = (array)($o['r'] ?? []);
            $keys = array_keys($row);
            if (!$keys || array_diff($keys, $cols[$t])) {
                throw new RuntimeException('The layout of ' . $t . ' has changed since this clear.');
            }
            if ($buf && array_keys($buf[0]) !== $keys) {
                $flush();
            }
            $buf[] = $row;
            $inserted[$t] = ($inserted[$t] ?? 0) + 1;
            if (count($buf) >= 200) {
                $flush();
            }
        }
        $flush();
        gzclose($gz);
        foreach ($want as $t => $n) {
            if ((int)($inserted[$t] ?? 0) !== $n) {
                throw new RuntimeException('Not every row could be put back (' . $t . ').');
            }
        }
        foreach (rh_sc_counts($pdo, $area, array_keys($want), true) as $t => $n) {
            if ($n !== $want[$t]) {
                throw new RuntimeException('The restored counts do not match (' . $t . ').');
            }
        }
        // Put back the supplier / purchase order pointers on rows that were kept (rows that no longer exist are skipped).
        if (!empty($man['fk_nulls'])) {
            $allowed = [];
            foreach (($a['fk_refs'] ?? []) as $refs) {
                foreach ($refs as [$rt, $rc]) {
                    $allowed[$rt . '.' . $rc] = [$rt, $rc];
                }
            }
            $gz = @gzopen($p['snapshot'], 'rb');
            if (!$gz) {
                throw new RuntimeException('The snapshot file could not be opened.');
            }
            while (!gzeof($gz)) {
                $line = gzgets($gz);
                if ($line === false || trim($line) === '') {
                    continue;
                }
                $o = json_decode($line, true, 512, JSON_BIGINT_AS_STRING);
                if (!is_array($o) || ($o['t'] ?? '') !== '@fk_nulls') {
                    continue;
                }
                $key = (string)($o['table'] ?? '') . '.' . (string)($o['col'] ?? '');
                if (!isset($allowed[$key])) {
                    throw new RuntimeException('The snapshot names a column this version does not know.');
                }
                [$rt, $rc] = $allowed[$key];
                if (!rh_sc_table_exists($pdo, $rt) || !in_array($rc, rh_sc_columns($pdo, $rt), true)) {
                    continue;
                }
                $pdo->prepare("UPDATE `$rt` SET `$rc` = ? WHERE id = ? AND `$rc` IS NULL")->execute([$o['val'], (int)$o['id']]);
            }
            gzclose($gz);
        }
        if ($started) {
            $pdo->commit();
            $started = false;
        }
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $e2) { /* nothing was committed */ }
        }
        if (isset($gz) && is_resource($gz)) {
            @gzclose($gz);
        }
        error_log('Undo Clear Stock Data failed: ' . $e->getMessage());
        return $fail('Nothing was changed. The undo did not complete (' . rtrim($e->getMessage(), '.') . ').');
    }

    $man['undone_at'] = date('Y-m-d H:i:s');
    $man['undone_by'] = (string)($actor['username'] ?? '');
    @file_put_contents($p['manifest'], json_encode($man, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if (function_exists('rh_clear_stock_dashboard_cache')) {
        rh_clear_stock_dashboard_cache();
    }
    if (function_exists('logActivity')) {
        logActivity((int)($actor['id'] ?? 0), 'stock_clear_undo', 'Undid the stock clear ' . $id . ': ' . rh_sc_sentence($area, $want) . ' put back.');
    }
    return ['ok' => true, 'error' => '', 'counts' => $want, 'sentence' => rh_sc_sentence($area, $want)];
}
