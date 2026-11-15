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
 * Never touched: stock_orders and its item/audit/delivery/split/KDS/shift tables, payments, menu_items. import_* is never
 * touched by a clear; the stock count wipe (owner 2026-11-15, rh_sc_run_count_wipe) may, when ticked, ask the Import
 * feature's own import_job_delete() to remove the Import history entries that created the wiped counts.
 *
 * Stock count wipe: removes every stock count, its lines and opening batches, and the stock its approval added (the surplus
 * batches and the variance adjustments), and puts each item's on-hand quantity back. It is refused, with a plain reason,
 * whenever stock has moved in a way that cannot be reversed exactly. No Undo: only the full backup taken first restores it.
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

/**
 * Active administrator-role account that holds stock_wipe_all (owner 2026-11-16, Tier S: super admins always; a plain
 * administrator only when a super admin switched it on). Fails closed when the permission system is not loaded.
 * [ok, plain reason, user row].
 */
function rh_sc_authorize_wipe_all(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare("SELECT id, username, full_name, role, is_active FROM admin_users WHERE id = ?");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || !(int)$u['is_active']) {
        return [false, 'This account is not active.', null];
    }
    if ((string)$u['role'] !== 'admin') {
        return [false, 'Only an administrator account can wipe all stock.', null];
    }
    if (!function_exists('hasPermission') || !hasPermission($userId, 'stock_wipe_all')) {
        return [false, 'This account does not have permission to wipe all stock.', null];
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
 *                      kind ('wipe_all')    marks the manifest as made by "Wipe all stock" (rh_sc_run_wipe_all only)
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
        if (($opts['kind'] ?? '') === 'wipe_all') {
            $manifest['kind'] = 'wipe_all'; // made by "Wipe all stock" (stock_wipe_all may undo only these)
        }
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
    if (rh_sc_manifest_is_count_wipe($man)) {
        return $fail('Nothing was changed. A stock count wipe has no Undo. ' . rh_sc_count_wipe_restore_text($man));
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

// ---------------------------------------------------------------------------------------------------------------------
// Stock count wipe (owner 2026-11-15): every stock count and the stock its approval added. No Undo; full backup only.
// ---------------------------------------------------------------------------------------------------------------------

if (!defined('RH_SC_CW_TOL')) {
    define('RH_SC_CW_TOL', 0.0001); // quantities are DECIMAL(16,6): never compare with raw float equality
}

/** True when a manifest describes a stock count wipe (these have no Undo). */
function rh_sc_manifest_is_count_wipe(array $man): bool
{
    return ($man['kind'] ?? '') === 'count_wipe' || (array_key_exists('undo', $man) && $man['undo'] === false);
}

/** "Restore from the full backup <name> on the Backups page." for a count wipe manifest. */
function rh_sc_count_wipe_restore_text(array $man): string
{
    $name = trim((string)($man['full_backup'] ?? ''));
    return 'Restore from the full backup ' . ($name !== '' ? $name : '(none was recorded)') . ' on the Backups page.';
}

/** The sentence every block reason ends with. */
function rh_sc_cw_tail(): string
{
    return ' Use the full Stock reset above instead.';
}

/** 12.5 rather than 12.500000. */
function rh_sc_cw_qty(float $q): string
{
    $s = rtrim(rtrim(number_format($q, 6, '.', ''), '0'), '.');
    return $s === '-0' ? '0' : $s;
}

/** Count reference as staff see it (the old SC- prefix dropped, as on Stock Count). */
function rh_sc_cw_ref(string $ref): string
{
    $ref = trim($ref);
    return $ref === '' ? '(no reference)' : (string)preg_replace('/^SC-/i', '', $ref);
}

/** "?,?,?" for a list (callers never pass an empty list). */
function rh_sc_cw_ph(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

/** Does a batch note belong to this count reference? ("Stock count surplus REF" exactly, or followed by a space.) */
function rh_sc_cw_note_matches(string $note, string $ref): bool
{
    $base = 'Stock count surplus ' . $ref;
    return $note === $base || strncmp($note, $base . ' ', strlen($base) + 1) === 0;
}

/**
 * Read everything the wipe would touch and work out whether it can be reversed exactly.
 * $lock = true: locking reads inside the caller's transaction, ingredient rows first by ascending id (the lock order every
 * stock write path uses), then counts, lines, adjustments, batches.
 * @return array raw rows (for the snapshot) + 'view' (for the preview) + 'blocked' (plain reasons)
 */
function rh_sc_cw_gather(PDO $pdo, bool $lock): array
{
    $fu = $lock ? ' FOR UPDATE' : '';
    $out = [
        'counts' => [], 'lines' => [], 'adjs' => [], 'batches' => [], 'batch_count' => [], 'opening' => [], 'ings' => [], 'alert_ids' => [],
        'review_rows' => [], 'import_jobs' => [], 'adj_sum_exact' => [], 'blocked' => [], 'view' => [],
    ];
    if (!rh_sc_table_exists($pdo, 'stock_counts') || !rh_sc_table_exists($pdo, 'stock_count_lines')) {
        $out['view'] = rh_sc_cw_view($out, [], [], []);
        return $out;
    }
    $all = static function (string $sql, array $p = []) use ($pdo): array {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $block = static function (string $why) use (&$out): void {
        $out['blocked'][] = $why . rh_sc_cw_tail();
    };

    // 1) Ingredient rows first (lock order), from a plain read of which items the counts' approvals changed.
    $preIng = [];
    if ($lock) {
        $preIng = array_map('intval', array_column($all(
            "SELECT DISTINCT a.ingredient_id FROM stock_adjustments a JOIN stock_counts c ON a.source_id = c.id WHERE a.source_type = ? ORDER BY a.ingredient_id",
            ['variance']
        ), 'ingredient_id'));
        if ($preIng) {
            $all("SELECT id FROM stock_ingredients WHERE id IN (" . rh_sc_cw_ph($preIng) . ") ORDER BY id FOR UPDATE", $preIng);
        }
    }

    // 2) Counts, all lines, the counts' variance adjustments.
    $counts = $all("SELECT * FROM stock_counts ORDER BY id" . $fu);
    $out['counts'] = $counts;
    $countById = [];
    foreach ($counts as $c) {
        $countById[(int)$c['id']] = $c;
    }
    $allLines = $all("SELECT * FROM stock_count_lines ORDER BY id" . $fu);
    $orphanLines = 0;
    $linesByCount = [];
    foreach ($allLines as $l) {
        $cid = (int)$l['count_id'];
        if (!isset($countById[$cid])) {
            $orphanLines++;
            continue;
        }
        $out['lines'][] = $l;
        $linesByCount[$cid][] = $l;
    }
    if ($orphanLines > 0) {
        $block($orphanLines . ' count line' . ($orphanLines === 1 ? ' belongs' : 's belong') . ' to no stock count, so the counts cannot be matched exactly.');
    }
    if (!$counts) {
        $out['view'] = rh_sc_cw_view($out, [], [], []);
        return $out;
    }
    $countIds = array_keys($countById);
    $adjs = $all("SELECT * FROM stock_adjustments WHERE source_type = ? AND source_id IN (" . rh_sc_cw_ph($countIds) . ") ORDER BY id" . $fu,
        array_merge(['variance'], $countIds));
    $out['adjs'] = $adjs;
    $adjByCount = [];
    $adjIngIds = [];
    foreach ($adjs as $a) {
        $adjByCount[(int)$a['source_id']][] = $a;
        $adjIngIds[(int)$a['ingredient_id']] = true;
    }
    $adjIngIds = array_keys($adjIngIds);
    sort($adjIngIds);
    if ($lock && array_diff($adjIngIds, $preIng)) {
        $out['blocked'][] = 'A stock count was approved while this was being checked. Try again.';
    }

    // 3) The surplus batches each approval created: notes "Stock count surplus <ref>" exactly, or followed by a space
    //    (" batch <label>", " (settled ...)"). The reference is escaped, so REF-1 never matches REF-10.
    $batchCount = [];
    foreach ($counts as $c) {
        $ref = (string)$c['reference'];
        if (trim($ref) === '') {
            continue;
        }
        $like = 'Stock count surplus ' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $ref) . ' %';
        foreach ($all("SELECT * FROM stock_batches WHERE notes = ? OR notes LIKE ? ESCAPE '!' ORDER BY id" . $fu, ['Stock count surplus ' . $ref, $like]) as $b) {
            $bid = (int)$b['id'];
            if (!rh_sc_cw_note_matches((string)$b['notes'], $ref)) {
                continue; // collation-insensitive match that is not an exact one: not this count's batch
            }
            if (isset($batchCount[$bid])) {
                $block('A stock batch (#' . $bid . ') matches two counts, so it cannot be told which count added it.');
                continue;
            }
            $batchCount[$bid] = (int)$c['id'];
            $out['batches'][] = $b;
        }
    }
    $out['batch_count'] = $batchCount;
    $batchIds = array_keys($batchCount);

    // Names for messages (plain read).
    $nameIds = $adjIngIds;
    foreach ($out['lines'] as $l) {
        $nameIds[] = (int)$l['ingredient_id'];
    }
    foreach ($out['batches'] as $b) {
        $nameIds[] = (int)$b['ingredient_id'];
    }
    $nameIds = array_values(array_unique($nameIds));
    $names = [];
    if ($nameIds) {
        foreach ($all("SELECT id, name FROM stock_ingredients WHERE id IN (" . rh_sc_cw_ph($nameIds) . ")", $nameIds) as $r) {
            $names[(int)$r['id']] = (string)$r['name'];
        }
    }
    $nm = static fn(int $id): string => isset($names[$id]) && $names[$id] !== '' ? '"' . $names[$id] . '"' : 'stock item #' . $id;

    // 4) Per count: lines' adjustment ids == its variance adjustments; (b) no shortage; batches == what the approval added.
    foreach ($counts as $c) {
        $cid = (int)$c['id'];
        $ref = rh_sc_cw_ref((string)$c['reference']);
        $lineAdj = [];
        foreach ($linesByCount[$cid] ?? [] as $l) {
            if ((int)($l['adjustment_id'] ?? 0) > 0) {
                $lineAdj[] = (int)$l['adjustment_id'];
            }
        }
        $cAdjIds = array_map(static fn($a) => (int)$a['id'], $adjByCount[$cid] ?? []);
        sort($lineAdj);
        sort($cAdjIds);
        if ($lineAdj !== $cAdjIds) {
            $block('Count ' . $ref . ': the stock changes recorded by its approval do not match its lines, so they cannot be reversed exactly.');
        }
        $short = [];
        $posByIng = [];
        foreach ($adjByCount[$cid] ?? [] as $a) {
            $q = (float)$a['quantity_change'];
            if ($q < -RH_SC_CW_TOL) {
                $short[] = $nm((int)$a['ingredient_id']) . ' (' . rh_sc_cw_qty(-$q) . ' short)';
            } elseif ($q > RH_SC_CW_TOL) {
                $posByIng[(int)$a['ingredient_id']] = ($posByIng[(int)$a['ingredient_id']] ?? 0.0) + $q;
            }
        }
        if ($short) {
            $block('Count ' . $ref . ' took stock off for ' . count($short) . ' item' . (count($short) === 1 ? '' : 's') . ' (' . implode(', ', array_slice($short, 0, 5))
                . (count($short) > 5 ? ' and ' . (count($short) - 5) . ' more' : '') . '). A shortage is taken from the delivery batches, so it cannot be put back exactly.');
        }
        $recvByIng = [];
        foreach ($out['batches'] as $b) {
            if ($batchCount[(int)$b['id']] === $cid) {
                $recvByIng[(int)$b['ingredient_id']] = ($recvByIng[(int)$b['ingredient_id']] ?? 0.0) + (float)$b['quantity_received'];
            }
        }
        foreach (array_unique(array_merge(array_keys($posByIng), array_keys($recvByIng))) as $iid) {
            $added = $posByIng[$iid] ?? 0.0;
            $inBatches = $recvByIng[$iid] ?? 0.0;
            if (abs($added - $inBatches) > RH_SC_CW_TOL) {
                $block('Count ' . $ref . ': for ' . $nm((int)$iid) . ' its approval added ' . rh_sc_cw_qty($added) . ' but the stock batches it created hold '
                    . rh_sc_cw_qty($inBatches) . ', so they cannot be matched exactly.');
            }
        }
    }

    // 5) (a) Every count batch untouched: nothing taken, no deduction recorded against it, still active.
    $deductByBatch = [];
    if ($batchIds && rh_sc_table_exists($pdo, 'stock_batch_deductions')) {
        foreach ($all("SELECT id, batch_id FROM stock_batch_deductions WHERE batch_id IN (" . rh_sc_cw_ph($batchIds) . ") ORDER BY id" . $fu, $batchIds) as $d) {
            $deductByBatch[(int)$d['batch_id']] = ($deductByBatch[(int)$d['batch_id']] ?? 0) + 1;
        }
    }
    foreach ($out['batches'] as $b) {
        $bid = (int)$b['id'];
        $ref = rh_sc_cw_ref((string)$countById[$batchCount[$bid]]['reference']);
        $rec = (float)$b['quantity_received'];
        $rem = (float)$b['quantity_remaining'];
        if (abs($rec - $rem) > RH_SC_CW_TOL) {
            $block('Count ' . $ref . ': the batch it added for ' . $nm((int)$b['ingredient_id']) . ' has been partly used (' . rh_sc_cw_qty($rem) . ' of ' . rh_sc_cw_qty($rec) . ' left).');
        } elseif (!empty($deductByBatch[$bid])) {
            $block('Count ' . $ref . ': stock from the batch it added for ' . $nm((int)$b['ingredient_id']) . ' has been used by sales or other stock movements.');
        } elseif ((string)$b['status'] !== 'active') {
            $block('Count ' . $ref . ': the batch it added for ' . $nm((int)$b['ingredient_id']) . ' is marked ' . (string)$b['status'] . ', so the stock has moved since.');
        }
    }

    // 6) Opening batches of these counts: an applied one must point at a batch that same count added.
    if (rh_sc_table_exists($pdo, 'stock_opening_batches')) {
        $out['opening'] = $all("SELECT * FROM stock_opening_batches WHERE count_id IN (" . rh_sc_cw_ph($countIds) . ") ORDER BY id" . $fu, $countIds);
        foreach ($out['opening'] as $ob) {
            $ap = (int)($ob['applied_batch_id'] ?? 0);
            if ($ap > 0 && ($batchCount[$ap] ?? 0) !== (int)$ob['count_id']) {
                $block('Count ' . rh_sc_cw_ref((string)($countById[(int)$ob['count_id']]['reference'] ?? '')) . ': an opening batch for ' . $nm((int)$ob['ingredient_id'])
                    . ' points to a stock batch that cannot be matched to the count.');
            }
        }
    }

    // 7) (c) Each item: on hand minus what the counts added must equal its OTHER active batches, and not go below 0.
    $other = [];
    if ($adjIngIds) {
        $out['ings'] = $all("SELECT id, name, unit, current_quantity, cost_per_unit, updated_at FROM stock_ingredients WHERE id IN (" . rh_sc_cw_ph($adjIngIds) . ") ORDER BY id" . $fu, $adjIngIds);
        $adjIdList = array_map(static fn($a) => (int)$a['id'], $adjs);
        foreach ($all("SELECT ingredient_id, SUM(quantity_change) AS s FROM stock_adjustments WHERE id IN (" . rh_sc_cw_ph($adjIdList) . ") GROUP BY ingredient_id", $adjIdList) as $r) {
            $out['adj_sum_exact'][(int)$r['ingredient_id']] = (string)$r['s'];
        }
        $excl = $batchIds ? ' AND id NOT IN (' . rh_sc_cw_ph($batchIds) . ')' : '';
        foreach ($all("SELECT ingredient_id, COALESCE(SUM(quantity_remaining), 0) AS s FROM stock_batches WHERE ingredient_id IN (" . rh_sc_cw_ph($adjIngIds) . ") AND status = 'active'" . $excl . " GROUP BY ingredient_id" . $fu,
            array_merge($adjIngIds, $batchIds)) as $r) {
            $other[(int)$r['ingredient_id']] = (float)$r['s'];
        }
        $found = array_map(static fn($i) => (int)$i['id'], $out['ings']);
        foreach (array_diff($adjIngIds, $found) as $missing) {
            $block('Stock item #' . (int)$missing . ', which a count changed, no longer exists.');
        }
        // Items whose count surplus carried a cost: approval averaged that cost into the item's unit cost, and the cost before
        // approval is not stored, so an item that keeps stock afterwards would be valued wrong (no restore formula is guessed).
        $costed = [];
        foreach ($adjs as $a) {
            if ((float)$a['quantity_change'] > RH_SC_CW_TOL && (float)$a['cost_at_time'] > 0) {
                $costed[(int)$a['ingredient_id']] = true;
            }
        }
        foreach ($out['ings'] as $i) {
            $iid = (int)$i['id'];
            $cur = (float)$i['current_quantity'];
            $sub = (float)($out['adj_sum_exact'][$iid] ?? 0);
            $after = $cur - $sub;
            $oth = $other[$iid] ?? 0.0;
            if ($after > RH_SC_CW_TOL && !empty($costed[$iid])) {
                $block($nm($iid) . ' would keep ' . rh_sc_cw_qty($after) . ' in stock, but its unit cost was averaged with the count, so what is left would be valued wrong.');
            }
            if ($after < -RH_SC_CW_TOL) {
                $block($nm($iid) . ': on hand is ' . rh_sc_cw_qty($cur) . ' but the counts added ' . rh_sc_cw_qty($sub) . ', so taking that off would go below 0.');
            } elseif (abs($after - $oth) > RH_SC_CW_TOL) {
                $block($nm($iid) . ': on hand is ' . rh_sc_cw_qty($cur) . ' and the counts added ' . rh_sc_cw_qty($sub) . ', but its other batches hold ' . rh_sc_cw_qty($oth)
                    . ', so the stock has moved since.');
            }
        }
    }

    // 8) Residue: open count-approval alerts, saved stock review panels; the Import history entries that made these counts.
    if (rh_sc_table_exists($pdo, 'ai_alerts')) {
        $out['alert_ids'] = array_map('intval', array_column($all("SELECT id FROM ai_alerts WHERE resolved_at IS NULL AND dismissed_at IS NULL AND alert_key LIKE ? ORDER BY id" . $fu, ['count_approval:%']), 'id'));
    }
    if (rh_sc_table_exists($pdo, 'ai_review_cache')) {
        $out['review_rows'] = $all("SELECT * FROM ai_review_cache WHERE area = ? ORDER BY id" . $fu, ['stock']);
    }
    $out['import_jobs'] = rh_sc_cw_import_jobs($pdo, $countIds);

    $out['view'] = rh_sc_cw_view($out, $linesByCount, $adjByCount, $other);
    return $out;
}

/** The first record of each named @-type in a snapshot file (used to verify the alert ids and Import history lines). */
function rh_sc_cw_snapshot_records(string $file, array $types): array
{
    $want = array_flip($types);
    $out = [];
    $gz = @gzopen($file, 'rb');
    if (!$gz) {
        throw new RuntimeException('The snapshot file could not be opened');
    }
    while (!gzeof($gz)) {
        $line = gzgets($gz);
        if ($line === false || trim($line) === '') {
            continue;
        }
        $o = json_decode($line, true);
        $t = is_array($o) ? (string)($o['t'] ?? '') : '';
        if (isset($want[$t]) && !isset($out[$t])) {
            $out[$t] = $o;
        }
    }
    gzclose($gz);
    return $out;
}

/** Import history entries (import_jobs) whose result names one of these counts as its opening count. Read-only. */
function rh_sc_cw_import_jobs(PDO $pdo, array $countIds): array
{
    if (!$countIds || !rh_sc_table_exists($pdo, 'import_jobs')) {
        return [];
    }
    $want = array_flip(array_map('intval', $countIds));
    $st = $pdo->prepare("SELECT id, original_name, status, result_json FROM import_jobs WHERE result_json IS NOT NULL AND result_json LIKE ? ORDER BY id");
    $st->execute(['%opening_count_id%']);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $j = json_decode((string)$r['result_json'], true);
        $cid = is_array($j) ? (int)($j['opening_count_id'] ?? 0) : 0;
        if ($cid > 0 && isset($want[$cid])) {
            $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['original_name'], 'status' => (string)$r['status'], 'count_id' => $cid];
        }
    }
    return $out;
}

/** The preview shape built from gathered rows. */
function rh_sc_cw_view(array $g, array $linesByCount, array $adjByCount, array $other): array
{
    $batchesByCount = [];
    foreach ((array)($g['batch_count'] ?? []) as $cid) {
        $batchesByCount[$cid] = ($batchesByCount[$cid] ?? 0) + 1;
    }
    $openingByCount = [];
    foreach ($g['opening'] as $ob) {
        $openingByCount[(int)$ob['count_id']] = ($openingByCount[(int)$ob['count_id']] ?? 0) + 1;
    }
    $rows = [];
    $value = 0.0;
    foreach ($g['counts'] as $c) {
        $cid = (int)$c['id'];
        $v = 0.0;
        foreach ($adjByCount[$cid] ?? [] as $a) {
            $v += (float)$a['quantity_change'] * (float)$a['cost_at_time'];
        }
        $value += $v;
        $rows[] = [
            'id' => $cid, 'reference' => rh_sc_cw_ref((string)$c['reference']), 'count_date' => (string)$c['count_date'], 'status' => (string)$c['status'],
            'is_opening' => !empty($c['is_opening']), 'lines' => count($linesByCount[$cid] ?? []), 'adjustments' => count($adjByCount[$cid] ?? []),
            'batches' => (int)($batchesByCount[$cid] ?? 0), 'opening_batches' => (int)($openingByCount[$cid] ?? 0), 'value' => round($v, 2),
        ];
    }
    $items = [];
    $toZero = 0;
    $drop = 0.0;
    foreach ($g['ings'] as $i) {
        $iid = (int)$i['id'];
        $cur = (float)$i['current_quantity'];
        $sub = (float)($g['adj_sum_exact'][$iid] ?? 0);
        $after = $cur - $sub;
        $zero = abs($after) <= RH_SC_CW_TOL;
        if ($zero) {
            $toZero++;
            $after = 0.0;
        }
        // Stock value = quantity x the item's unit cost (rh_stock_valuation), so the real drop is before minus after at that cost.
        $cpu = (float)$i['cost_per_unit'];
        $itemDrop = $cur * $cpu - $after * $cpu;
        $drop += $itemDrop;
        $items[] = ['id' => $iid, 'name' => (string)$i['name'], 'unit' => (string)($i['unit'] ?? ''), 'on_hand' => $cur, 'remove' => $sub,
            'after' => $after, 'other_batches' => (float)($other[$iid] ?? 0), 'to_zero' => $zero, 'value_drop' => round($itemDrop, 2)];
    }
    return [
        'counts' => $rows,
        'items'  => $items,
        'totals' => [
            'counts' => count($g['counts']), 'lines' => count($g['lines']), 'batches' => count($g['batches']), 'adjustments' => count($g['adjs']),
            'opening_batches' => count($g['opening']), 'items' => count($items), 'items_to_zero' => $toZero,
            'value' => round($drop, 2), 'value_added' => round($value, 2),
            'alerts' => count($g['alert_ids']), 'review_cache' => count($g['review_rows']), 'import_jobs' => count($g['import_jobs']),
        ],
    ];
}

/**
 * What a stock count wipe would remove, without changing anything.
 * @return array{counts:array,items:array,totals:array,import_jobs:array,blocked:string[]}
 */
function rh_sc_count_wipe_preview(PDO $pdo): array
{
    $g = rh_sc_cw_gather($pdo, false);
    return $g['view'] + ['import_jobs' => $g['import_jobs'], 'blocked' => $g['blocked']];
}

/**
 * Wipe every stock count and the stock its approval added. Same safety pattern as rh_sc_run_clear: full backup before the
 * transaction, one transaction, locking reads, verified snapshot, delete by id with counts checked, post-check, commit.
 * Every block rule is checked again inside the transaction under lock.
 *
 * @param array $opts external_tx (bool), full_backup (callable|false: tests only, with external_tx),
 *                    delete_import_history (bool): after the commit, remove the Import history entries that created the counts
 * @return array{ok:bool,error:string,blocked:string[],id:string,counts:array,sentence:string,snapshot:string,full_backup:string,totals:array,import:array}
 */
function rh_sc_run_count_wipe(PDO $pdo, string $root, array $actor, array $opts = []): array
{
    $fail = static function (string $why, array $blocked = []): array {
        return ['ok' => false, 'error' => $why . ' Nothing was changed.', 'blocked' => $blocked, 'id' => '', 'counts' => [], 'sentence' => '',
            'snapshot' => '', 'full_backup' => '', 'totals' => [], 'import' => []];
    };
    $external = !empty($opts['external_tx']);
    if ($external !== $pdo->inTransaction()) {
        return $fail($external ? 'No transaction is open.' : 'Another change is in progress on this connection.');
    }
    // Fast refusal before the (slow) full backup; the same rules run again under lock below.
    $pre = rh_sc_cw_gather($pdo, false);
    if ($pre['blocked']) {
        return $fail('The stock counts cannot be wiped exactly.', $pre['blocked']);
    }
    if (!$pre['counts']) {
        return $fail('There are no stock counts to remove.');
    }

    // 1) Folder + full database backup, before any transaction opens.
    try {
        rh_sc_dir($root);
    } catch (Throwable $e) {
        return $fail($e->getMessage());
    }
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

    // No count may be approved while this runs: the same named lock approval takes (GET_LOCK never commits anything).
    $lockName = substr('rh_stock_count_approve_' . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64);
    if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 30)')->fetchColumn() !== 1) {
        return $fail('A stock count is being approved at this moment. Wait a few seconds and try again (the full backup ' . $fullBackupName . ' was still saved).');
    }
    $release = static function () use ($pdo, $lockName): void {
        try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')'); } catch (Throwable $e) { /* released when the connection closes */ }
    };

    $id = rh_sc_new_id($root);
    $p = rh_sc_paths($root, $id);
    $started = false;
    $manifestWritten = false;
    $g = ['blocked' => []];
    $tables = [];
    $residue = ['alerts_resolved' => 0, 'review_cache_rows' => 0];
    $rowIds = static fn(array $rows): array => array_map(static fn($r) => (int)$r['id'], $rows);
    try {
        if (!$external) {
            $pdo->beginTransaction();
            $started = true;
        }
        // 2) Locking read + every block rule again.
        $g = rh_sc_cw_gather($pdo, true);
        if ($g['blocked']) {
            throw new RuntimeException('BLOCKED');
        }
        if (!$g['counts']) {
            throw new RuntimeException('There are no stock counts to remove');
        }
        $hasOpening = rh_sc_table_exists($pdo, 'stock_opening_batches');
        $tables = [
            'stock_counts'          => count($g['counts']),
            'stock_count_lines'     => count($g['lines']),
            'stock_opening_batches' => count($g['opening']),
            'stock_adjustments'     => count($g['adjs']),
            'stock_batches'         => count($g['batches']),
        ];

        // 3) Snapshot: every row removed, each item's row before, the alerts closed and the review panels removed.
        $gz = @gzopen($p['tmp'], 'wb6');
        if (!$gz) {
            throw new RuntimeException('The snapshot file could not be created');
        }
        rh_sc_write_line($gz, ['t' => '@header', 'id' => $id, 'area' => 'stock', 'kind' => 'count_wipe', 'at' => date('Y-m-d H:i:s')]);
        $map = ['stock_counts' => 'counts', 'stock_count_lines' => 'lines', 'stock_opening_batches' => 'opening', 'stock_adjustments' => 'adjs', 'stock_batches' => 'batches'];
        foreach ($map as $t => $k) {
            foreach ($g[$k] as $row) {
                rh_sc_write_line($gz, ['t' => $t, 'r' => $row]);
            }
        }
        foreach ($g['ings'] as $row) {
            rh_sc_write_line($gz, ['t' => '@ingredient_before', 'r' => $row, 'subtract' => (string)($g['adj_sum_exact'][(int)$row['id']] ?? '0')]);
        }
        rh_sc_write_line($gz, ['t' => '@alert_ids', 'ids' => $g['alert_ids']]);
        foreach ($g['review_rows'] as $row) {
            rh_sc_write_line($gz, ['t' => '@ai_review_cache', 'r' => $row]);
        }
        rh_sc_write_line($gz, ['t' => '@import_jobs', 'jobs' => $g['import_jobs']]);
        gzclose($gz);
        $fileCounts = rh_sc_snapshot_counts($p['tmp']);
        foreach ($tables as $t => $n) {
            if ((int)($fileCounts[$t] ?? 0) !== $n) {
                throw new RuntimeException('The snapshot did not match the data (' . $t . ')');
            }
        }
        if ((int)($fileCounts['@ingredient_before'] ?? 0) !== count($g['ings']) || (int)($fileCounts['@ai_review_cache'] ?? 0) !== count($g['review_rows'])) {
            throw new RuntimeException('The snapshot did not match the data (stock items)');
        }
        if ((int)($fileCounts['@alert_ids'] ?? 0) !== 1 || (int)($fileCounts['@import_jobs'] ?? 0) !== 1) {
            throw new RuntimeException('The snapshot did not match the data (alerts and Import history)');
        }
        $rec = rh_sc_cw_snapshot_records($p['tmp'], ['@alert_ids', '@import_jobs']);
        if (array_map('intval', (array)($rec['@alert_ids']['ids'] ?? [])) !== $g['alert_ids']
            || array_map(static fn($j) => (int)($j['id'] ?? 0), (array)($rec['@import_jobs']['jobs'] ?? [])) !== array_map(static fn($j) => (int)$j['id'], $g['import_jobs'])) {
            throw new RuntimeException('The snapshot did not match the data (alerts and Import history)');
        }
        if (!@rename($p['tmp'], $p['snapshot'])) {
            throw new RuntimeException('The snapshot file could not be saved');
        }
        $totals = $g['view']['totals'];
        $manifest = [
            'id'             => $id,
            'area'           => 'stock',
            'kind'           => 'count_wipe',
            'undo'           => false,
            'created_at'     => date('Y-m-d H:i:s'),
            'by_user_id'     => (int)($actor['id'] ?? 0),
            'by_username'    => (string)($actor['username'] ?? ''),
            'by_name'        => (string)($actor['full_name'] ?? ''),
            'groups'         => ['count_wipe'],
            'forced'         => [],
            'tables'         => $tables,
            'count_refs'     => array_map(static fn($c) => (string)$c['reference'], $g['counts']),
            'items'          => (int)$totals['items'],
            'items_to_zero'  => (int)$totals['items_to_zero'],
            'value'          => (float)$totals['value'],
            'residue'        => ['alert_ids' => $g['alert_ids'], 'review_cache_rows' => count($g['review_rows'])],
            'fk_nulls'       => [],
            'import_jobs'    => array_map(static fn($j) => (int)$j['id'], $g['import_jobs']),
            'snapshot_file'  => basename($p['snapshot']),
            'snapshot_bytes' => (int)@filesize($p['snapshot']),
            'full_backup'    => $fullBackupName,
            'undone_at'      => null,
            'undone_by'      => null,
        ];
        if (@file_put_contents($p['manifest'], json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException('The snapshot description could not be saved');
        }
        $manifestWritten = true;

        // 4) Delete by id, children first, every count verified.
        $del = static function (string $t, array $list) use ($pdo): void {
            $n = 0;
            foreach (array_chunk($list, 500) as $chunk) {
                $s = $pdo->prepare("DELETE FROM `$t` WHERE id IN (" . rh_sc_cw_ph($chunk) . ")");
                $s->execute($chunk);
                $n += $s->rowCount();
            }
            if ($n !== count($list)) {
                throw new RuntimeException($t . ': removed ' . $n . ', expected ' . count($list));
            }
        };
        $del('stock_count_lines', $rowIds($g['lines']));
        if ($hasOpening) {
            $del('stock_opening_batches', $rowIds($g['opening']));
        }
        $del('stock_counts', $rowIds($g['counts']));
        $del('stock_adjustments', $rowIds($g['adjs']));
        $del('stock_batches', $rowIds($g['batches']));

        // 5) Each item's on-hand back by exactly what the counts added (exact DECIMAL sum from SQL, subtracted in SQL).
        $upd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - CAST(? AS DECIMAL(20,6)), updated_at = NOW() WHERE id = ?");
        foreach ($g['ings'] as $i) {
            $sub = (string)($g['adj_sum_exact'][(int)$i['id']] ?? '0');
            if (!preg_match('/^-?\d+(\.\d+)?$/', $sub)) {
                throw new RuntimeException('Unexpected quantity for stock item #' . (int)$i['id']);
            }
            $upd->execute([$sub, (int)$i['id']]);
        }

        // 6) Residue: open count-approval alerts closed, saved stock review panels removed.
        foreach (array_chunk($g['alert_ids'], 500) as $chunk) {
            $s = $pdo->prepare("UPDATE ai_alerts SET resolved_at = NOW() WHERE resolved_at IS NULL AND dismissed_at IS NULL AND id IN (" . rh_sc_cw_ph($chunk) . ")");
            $s->execute($chunk);
            $residue['alerts_resolved'] += $s->rowCount();
        }
        foreach (array_chunk($rowIds($g['review_rows']), 500) as $chunk) {
            $s = $pdo->prepare("DELETE FROM ai_review_cache WHERE area = ? AND id IN (" . rh_sc_cw_ph($chunk) . ")");
            $s->execute(array_merge(['stock'], $chunk));
            $residue['review_cache_rows'] += $s->rowCount();
        }

        // 7) Post-check: none of those rows left, no lines on the removed counts, each item exactly before minus added, never below 0.
        $checks = ['stock_counts' => $rowIds($g['counts']), 'stock_count_lines' => $rowIds($g['lines']), 'stock_adjustments' => $rowIds($g['adjs']), 'stock_batches' => $rowIds($g['batches'])];
        if ($hasOpening) {
            $checks['stock_opening_batches'] = $rowIds($g['opening']);
        }
        foreach ($checks as $t => $list) {
            foreach (array_chunk($list, 500) as $chunk) {
                $s = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE id IN (" . rh_sc_cw_ph($chunk) . ")");
                $s->execute($chunk);
                if ((int)$s->fetchColumn() !== 0) {
                    throw new RuntimeException('Post-check: rows are left in ' . $t);
                }
            }
        }
        $cids = $rowIds($g['counts']);
        $s = $pdo->prepare("SELECT COUNT(*) FROM stock_count_lines WHERE count_id IN (" . rh_sc_cw_ph($cids) . ")");
        $s->execute($cids);
        if ((int)$s->fetchColumn() !== 0) {
            throw new RuntimeException('Post-check: count lines were added while the wipe was running');
        }
        $chk = $pdo->prepare("SELECT current_quantity FROM stock_ingredients WHERE id = ?");
        foreach ($g['ings'] as $i) {
            $chk->execute([(int)$i['id']]);
            $now = (float)$chk->fetchColumn();
            $want = (float)$i['current_quantity'] - (float)($g['adj_sum_exact'][(int)$i['id']] ?? 0);
            if (abs($now - $want) > 0.000001 || $now < -RH_SC_CW_TOL) {
                throw new RuntimeException('Post-check: the quantity of stock item #' . (int)$i['id'] . ' is not what was expected');
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
        $release();
        if ($e->getMessage() === 'BLOCKED') {
            return $fail('The stock counts cannot be wiped exactly.', (array)($g['blocked'] ?? []));
        }
        error_log('Stock count wipe failed: ' . $e->getMessage());
        return $fail('The wipe did not complete (' . rtrim($e->getMessage(), '.') . ').');
    }
    $release();

    // 8) After the commit: activity log, cached numbers, then (when ticked) the Import history entries.
    $totals = $g['view']['totals'];
    $sentence = rh_sc_sentence('stock', $tables);
    if (function_exists('logActivity')) {
        logActivity((int)($actor['id'] ?? 0), 'stock_count_wipe',
            'Wiped stock counts: ' . $sentence . '. ' . (int)$totals['items'] . ' stock item(s) reduced by what the counts added ('
            . (int)$totals['items_to_zero'] . ' to 0); stock value removed ' . number_format((float)$totals['value'], 2)
            . '. Counts: ' . implode(', ', array_map(static fn($c) => rh_sc_cw_ref((string)$c['reference']), $g['counts']))
            . '. Snapshot ' . basename($p['snapshot']) . '; full backup ' . $fullBackupName . '; ' . $residue['alerts_resolved'] . ' alert(s) closed. No Undo.');
    }
    if (function_exists('rh_clear_stock_dashboard_cache')) {
        rh_clear_stock_dashboard_cache();
    }
    $import = [];
    if (!empty($opts['delete_import_history']) && $g['import_jobs']) {
        $file = dirname(__DIR__, 2) . '/includes/import/delete.php';
        foreach ($g['import_jobs'] as $j) {
            $row = ['job_id' => (int)$j['id'], 'name' => (string)$j['name'], 'ok' => false, 'error' => '', 'snapshot' => ''];
            try {
                if ($pdo->inTransaction()) {
                    // Under external_tx the caller still owns an open transaction: the Import delete runs its own, so it is not called.
                    $row['error'] = 'Not removed: another change is still open on this connection.';
                } else {
                    if (!function_exists('import_job_delete') && file_exists($file)) {
                        require_once $file;
                    }
                    if (!function_exists('import_job_delete')) {
                        $row['error'] = 'Not removed: removing Import history is not available on this system yet.';
                    } else {
                        $r = import_job_delete($pdo, $root, (int)$j['id'], $actor, ['log_note' => 'Removed as part of the stock count wipe; the counts and the stock they added were wiped too.']);
                        $row['ok'] = !empty($r['ok']);
                        $row['error'] = (string)($r['error'] ?? '');
                        $row['snapshot'] = (string)($r['snapshot'] ?? '');
                    }
                }
            } catch (Throwable $e) {
                if (!$external && $pdo->inTransaction()) {
                    try { $pdo->rollBack(); } catch (Throwable $e2) { /* the wipe itself is already committed */ }
                }
                $row['error'] = 'Not removed (' . $e->getMessage() . ').';
            }
            $import[] = $row;
        }
    }
    return [
        'ok' => true, 'error' => '', 'blocked' => [], 'id' => $id, 'counts' => $tables, 'sentence' => $sentence,
        'snapshot' => basename($p['snapshot']), 'full_backup' => $fullBackupName, 'totals' => $totals + $residue, 'import' => $import,
    ];
}

// ---------------------------------------------------------------------------------------------------------------------
// Wipe all stock and every record (owner 2026-11-16, permission stock_wipe_all)
// ---------------------------------------------------------------------------------------------------------------------
// The same clear as ticking every group (full backup + verified snapshot + Undo on the page), then every finished Import
// history entry is removed with the Import feature's own import_job_delete(). Unfinished (in progress) imports are kept and
// listed; a refused or failed import entry never fails the wipe. Import history comes back only from its backup copies.

if (!defined('RH_SC_WIPE_ALL_WORD')) {
    define('RH_SC_WIPE_ALL_WORD', 'WIPE ALL');
}

/**
 * True when a manifest was made by "Wipe all stock" (marker kind = wipe_all, every group). A Clear with every group ticked,
 * and any older manifest without the marker, is NOT one: those stay data_reset only.
 */
function rh_sc_manifest_is_full_wipe(array $man): bool
{
    if (($man['kind'] ?? '') !== 'wipe_all' || rh_sc_manifest_is_count_wipe($man) || ($man['area'] ?? '') !== 'stock') {
        return false;
    }
    $all = array_keys(rh_sc_area('stock')['groups']);
    return !array_diff($all, (array)($man['groups'] ?? []));
}

/** May this person undo this clear? data_reset: any clear. stock_wipe_all alone: only a clear made by "Wipe all stock". */
function rh_sc_undo_allowed(bool $canReset, bool $canWipeAll, ?array $man): bool
{
    return $canReset || ($canWipeAll && is_array($man) && rh_sc_manifest_is_full_wipe($man));
}

/** Past clears this person sees: all of them with data_reset; with stock_wipe_all alone only the "Wipe all stock" ones. */
function rh_sc_visible_clears(array $clears, bool $canReset): array
{
    return $canReset ? $clears : array_values(array_filter($clears, 'rh_sc_manifest_is_full_wipe'));
}

/** Load the Import delete helpers when they exist. True when import_job_delete() is available. */
function rh_sc_load_import_delete(): bool
{
    if (!function_exists('import_job_delete')) {
        $file = dirname(__DIR__, 2) . '/includes/import/delete.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
    return function_exists('import_job_delete') && function_exists('import_job_delete_block_reason');
}

/**
 * Import history entries as they are now (read-only).
 * @return array{total:int,removable:int,kept:array<int,array{job_id:int,name:string,status:string,reason:string}>,jobs:array}
 */
function rh_sc_wipe_all_import_summary(PDO $pdo): array
{
    $out = ['total' => 0, 'removable' => 0, 'kept' => [], 'jobs' => []];
    if (!rh_sc_table_exists($pdo, 'import_jobs')) {
        return $out;
    }
    $canDelete = rh_sc_load_import_delete();
    $rows = $pdo->query('SELECT id, original_name, status, error_text FROM import_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $j) {
        $out['total']++;
        $out['jobs'][] = $j;
        $why = $canDelete ? import_job_delete_block_reason($j) : 'Removing Import history is not available on this system yet.';
        if ($why === '') {
            $out['removable']++;
        } else {
            $out['kept'][] = ['job_id' => (int)$j['id'], 'name' => (string)$j['original_name'], 'status' => (string)$j['status'], 'reason' => $why];
        }
    }
    return $out;
}

/** What the full wipe would remove right now (read-only): the clear preview over every group, plus Import history. */
function rh_sc_wipe_all_preview(PDO $pdo): array
{
    $pv = rh_sc_preview($pdo, 'stock', array_keys(rh_sc_area('stock')['groups']));
    $pv['import'] = rh_sc_wipe_all_import_summary($pdo);
    return $pv;
}

/**
 * Run the full wipe. No permission check here: callers use rh_sc_wipe_all_request() (the page) or check first.
 *
 * @param array $opts external_tx / full_backup: passed to rh_sc_run_clear() (tests only); under external_tx the Import
 *                    deletes run inside the caller's transaction too.
 * @return array rh_sc_run_clear()'s result plus import (one row per entry: job_id,name,status,ok,error,snapshot),
 *               import_removed and import_kept.
 */
function rh_sc_run_wipe_all(PDO $pdo, string $root, array $actor, array $opts = []): array
{
    $external = !empty($opts['external_tx']);
    $clearOpts = array_intersect_key($opts, ['external_tx' => 1, 'full_backup' => 1]) + ['kind' => 'wipe_all'];
    $r = rh_sc_run_clear($pdo, $root, $actor, 'stock', array_keys(rh_sc_area('stock')['groups']), $clearOpts);
    $r += ['import' => [], 'import_removed' => 0, 'import_kept' => 0];
    if (empty($r['ok'])) {
        return $r; // the stock part did not complete: Import history is left alone
    }

    // Import history, one entry at a time, each with its own backup. Nothing here can undo the wipe above.
    $import = [];
    try {
        $summary = rh_sc_wipe_all_import_summary($pdo);
    } catch (Throwable $e) {
        $summary = ['jobs' => []];
        $import[] = ['job_id' => 0, 'name' => 'Import history', 'status' => '', 'ok' => false, 'error' => 'Could not be read (' . $e->getMessage() . '). Nothing in it was removed.', 'snapshot' => ''];
    }
    $canDelete = rh_sc_load_import_delete();
    foreach ($summary['jobs'] as $j) {
        $row = ['job_id' => (int)$j['id'], 'name' => (string)$j['original_name'], 'status' => (string)$j['status'], 'ok' => false, 'error' => '', 'snapshot' => ''];
        try {
            $why = $canDelete ? import_job_delete_block_reason($j) : 'Removing Import history is not available on this system yet.';
            if ($why !== '') {
                $row['error'] = 'Kept: ' . $why;
            } elseif ($external !== $pdo->inTransaction()) {
                $row['error'] = 'Kept: another change is still open on this connection.';
            } else {
                $d = import_job_delete($pdo, $root, (int)$j['id'], $actor, ['log_note' => 'Removed with the full stock wipe.', 'external_tx' => $external]);
                $row['ok'] = !empty($d['ok']);
                $row['error'] = $row['ok'] ? '' : 'Kept: ' . (string)($d['error'] ?? 'not removed.');
                $row['snapshot'] = (string)($d['snapshot'] ?? '');
            }
        } catch (Throwable $e) {
            if (!$external && $pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (Throwable $e2) { /* the wipe itself is already committed */ }
            }
            $row['error'] = 'Kept: not removed (' . $e->getMessage() . ').';
        }
        $import[] = $row;
    }
    $removed = count(array_filter($import, static fn($x) => $x['ok']));
    $kept = count($import) - $removed;
    if (function_exists('logActivity')) {
        logActivity((int)($actor['id'] ?? 0), 'stock_wipe_all',
            'Wiped all stock and every record: ' . ($r['sentence'] !== '' ? $r['sentence'] : 'no stock rows') . '. Import history: '
            . $removed . ' entr' . ($removed === 1 ? 'y' : 'ies') . ' removed, ' . $kept . ' kept. Snapshot ' . $r['snapshot']
            . '; full backup ' . ($r['full_backup'] !== '' ? $r['full_backup'] : 'none') . '.');
    }
    $r['import'] = $import;
    $r['import_removed'] = $removed;
    $r['import_kept'] = $kept;
    return $r;
}

/**
 * The page's POST for "Wipe all stock": permission first, then the "I understand" tick, then the typed words WIPE ALL and
 * the person's own password, then the run. The page counts a wrong-password lockout only for stage 'confirm'.
 *
 * @param array $post  the posted fields: wa_understand, confirm_word, confirm_password
 * @param array $opts  passed to rh_sc_run_wipe_all() (tests only)
 * @return array{ok:bool,stage:string,error:string,result:?array} stage: permission|understand|confirm|empty|run|done
 */
function rh_sc_wipe_all_request(PDO $pdo, string $root, array $actor, array $post, array $opts = []): array
{
    $uid = (int)($actor['id'] ?? 0);
    [$okA, $whyA] = rh_sc_authorize_wipe_all($pdo, $uid);
    if (!$okA) {
        return ['ok' => false, 'stage' => 'permission', 'error' => rtrim((string)$whyA, '.') . '. Nothing was changed.', 'result' => null];
    }
    if (empty($post['wa_understand'])) {
        return ['ok' => false, 'stage' => 'understand', 'error' => 'Nothing was changed. Tick "I understand" to show you have read what this does.', 'result' => null];
    }
    [$okC, $whyC] = rh_sc_check_confirm($pdo, $uid, RH_SC_WIPE_ALL_WORD, (string)($post['confirm_word'] ?? ''), (string)($post['confirm_password'] ?? ''));
    if (!$okC) {
        return ['ok' => false, 'stage' => 'confirm', 'error' => (string)$whyC, 'result' => null];
    }
    $pv = rh_sc_wipe_all_preview($pdo);
    if ((int)$pv['total'] === 0 && (int)$pv['import']['removable'] === 0) {
        return ['ok' => false, 'stage' => 'empty', 'error' => 'There is no stock data and no Import history that can be removed, so there is nothing to wipe. Nothing was changed.', 'result' => null];
    }
    $r = rh_sc_run_wipe_all($pdo, $root, $actor, $opts);
    if (empty($r['ok'])) {
        return ['ok' => false, 'stage' => 'run', 'error' => (string)$r['error'], 'result' => $r];
    }
    return ['ok' => true, 'stage' => 'done', 'error' => '', 'result' => $r];
}
