<?php
/**
 * Stock > Change main unit (Step U3, admin/includes/stock-unit-change-lib.php + the restore / deduct guards in config/database.php).
 * Usage: php scripts/tests/stock_change_unit.php   (LIWONDE TEST DATABASE ONLY: refuses anywhere else, nothing written)
 *
 * Apply runs its own transaction (the real path), so the fixtures are committed rows tagged with a run id; everything is
 * deleted in finally (with the activity-log rows) and the test checks nothing is left. No existing item is ever touched.
 *
 * Covers: permission refusal (preview + apply), preview numbers + value before = after, stale preview refused, bottle -> shot
 * (item, settings, pack, all batches incl. used-up, recipe, open PO line with its total kept, closed PO line untouched, draft
 * count line, approved count untouched, opening batch, barcode, 8c, change row, log old -> new), a sale restored after the
 * change (rescaled qty + cost, same batch), a sale after the change, a draft count with movements refuses, shot -> bottle
 * (old unit name back, round trip equal), a restore across two changes, no double restore, the precision refusal (value would
 * move > BALANCE_TOLERANCE), no measure / qty not equal to batches refused, and the deduct guard when a change commits after
 * the sale's snapshot (second connection).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

const SEED_TEST_DB_SHA1 = '3b8976ab1ff31dab31141c7344d737ecc8725d41'; // sha1 of the Liwonde test database name (scripts/seed_liwonde_test_menu.php)

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';

$connectedDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (sha1((string)(defined('DB_NAME') ? DB_NAME : '')) !== SEED_TEST_DB_SHA1 || sha1($connectedDb) !== SEED_TEST_DB_SHA1) {
    fwrite(STDERR, "Refused: this is not the Liwonde test database. Nothing was written.\n");
    exit(9);
}
require_once __DIR__ . '/../../admin/includes/permissions.php';
require_once __DIR__ . '/../../admin/includes/stock-unit-change-lib.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function near($a, $b, float $tol = 0.000001): bool { return abs((float)$a - (float)$b) <= $tol; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function row(PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC) ?: []; }

if (!rh_stock_unit_changes_ready($pdo)) {
    echo "FAIL migration 090 has not run here\n";
    exit(1);
}

$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1");
$noPermId = 0;
foreach ($pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role NOT IN ('admin','manager') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $uid) {
    if (!rh_suc_allowed((int)$uid)) { $noPermId = (int)$uid; break; }
}
$run = 'ZZT-SUC-' . strtoupper(bin2hex(random_bytes(3)));
$ids = []; $poIds = []; $countIds = []; $recipeIds = []; $orderIds = [];
$menuType = 'zzsuc';
$menuItem = 4000000000 + random_int(0, 99999);
$srcBase = 3900000000 + random_int(0, 99999);

$snap = function (int $id) use ($pdo): array {
    return row($pdo, "SELECT i.*, COALESCE((SELECT SUM(b.quantity_remaining) FROM stock_batches b WHERE b.ingredient_id = i.id AND b.status = 'active'), 0) AS bq,
        COALESCE((SELECT SUM(b.quantity_remaining * b.cost_per_unit) FROM stock_batches b WHERE b.ingredient_id = i.id AND b.status = 'active'), 0) AS bv
        FROM stock_ingredients i WHERE i.id = ?", [$id]);
};

try {
    $mkIng = function (string $name, string $unit, float $qty, float $cost, ?array $measure) use ($pdo, $run, &$ids): int {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point, min_quantity, pack_name, pack_size,
                measure_big_name, measure_small_name, measure_per_big, measure_stock_side)
            VALUES (?, ?, ?, ?, ?, 12, 4, 2, 'case', 12, ?, ?, ?, ?)")
            ->execute([$name, $run, $unit, $qty, $cost, $measure[0] ?? null, $measure[1] ?? null, $measure[2] ?? null, $measure[3] ?? null]);
        $id = (int)$pdo->lastInsertId();
        $ids[] = $id;
        return $id;
    };
    $mkBatch = function (int $ing, string $no, float $recv, float $left, float $cost, string $status) use ($pdo): int {
        $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status)
            VALUES (?, ?, ?, ?, ?, CURDATE(), ?)")->execute([$ing, $no, $recv, $left, $cost, $status]);
        return (int)$pdo->lastInsertId();
    };

    // ---- A: whisky kept in "btl", 30 shots in one ----
    $A = $mkIng('Unit test whisky ' . $run, 'btl', 10, 24000, ['bottle', 'shot', 30, 'big']);
    $A1 = $mkBatch($A, $run . '-A1', 12, 6, 24000, 'active');
    $A2 = $mkBatch($A, $run . '-A2', 6, 4, 24600, 'active');
    $A3 = $mkBatch($A, $run . '-A3', 6, 0, 23400, 'depleted');
    $pdo->prepare("INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe) VALUES (?, ?, 1)")->execute([$menuItem, $menuType]);
    $recipeIds[] = $R = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion) VALUES (?, ?, 0.5)")->execute([$R, $A]);
    $RL = (int)$pdo->lastInsertId();
    $mkPo = function (string $ref, string $status) use ($pdo, &$poIds): int {
        $pdo->prepare("INSERT INTO stock_purchase_orders (reference, status, order_date, subtotal, total_cost) VALUES (?, ?, CURDATE(), 144000, 144000)")->execute([$ref, $status]);
        return $poIds[] = (int)$pdo->lastInsertId();
    };
    $poOpen = $mkPo($run . '-PO1', 'draft');
    $poDone = $mkPo($run . '-PO2', 'received');
    $pdo->prepare("INSERT INTO stock_purchase_order_items (purchase_order_id, ingredient_id, description, unit, ordered_qty, received_qty, unit_cost, line_total, pack_size_snapshot, order_in)
        VALUES (?, ?, 'whisky', 'btl', 6, 0, 24000, 144000, 12, 'unit')")->execute([$poOpen, $A]);
    $PL1 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_purchase_order_items (purchase_order_id, ingredient_id, description, unit, ordered_qty, received_qty, unit_cost, line_total, order_in)
        VALUES (?, ?, 'whisky', 'btl', 6, 6, 24000, 144000, 'unit')")->execute([$poDone, $A]);
    $PL2 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_ingredient_barcodes (ingredient_id, barcode, pack_label, pack_size) VALUES (?, ?, 'bottle', 1)")->execute([$A, $run . '-BC']);
    $BC = (int)$pdo->lastInsertId();

    // A sale BEFORE the change (restored after it): 1 portion = 0.5 btl from A1.
    check(deductStockForMenuItem($menuItem, $menuType, 1, 'manual', $srcBase + 1, $adminId) === true, 'sale before the change deducted');
    $s = $snap($A);
    check(near($s['current_quantity'], 9.5) && near(col($pdo, 'SELECT quantity_remaining FROM stock_batches WHERE id = ?', [$A1]), 5.5), 'sale took 0.5 btl from batch A1');

    // Counts (started after that sale): a draft with an opening batch, and an approved one.
    $mkCount = function (string $ref, string $status) use ($pdo, &$countIds): int {
        $pdo->prepare("INSERT INTO stock_counts (reference, count_date, status, snapshot_at) VALUES (?, CURDATE(), ?, NOW())")->execute([$ref, $status]);
        return $countIds[] = (int)$pdo->lastInsertId();
    };
    sleep(1); // the draft count starts strictly after the sale
    $cDraft = $mkCount($run . '-C1', 'draft');
    $cAppr = $mkCount($run . '-C2', 'approved');
    $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost, entry_mode)
        VALUES (?, ?, 9.5, 9, -0.5, 24000, -12000, 'stock')")->execute([$cDraft, $A]);
    $CL1 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost)
        VALUES (?, ?, 10, 10, 0, 24000, 0)")->execute([$cAppr, $A]);
    $CL2 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_opening_batches (count_id, ingredient_id, batch_label, quantity) VALUES (?, ?, 'opening', 2)")->execute([$cDraft, $A]);
    $OB = (int)$pdo->lastInsertId();

    // ---- permission ----
    if ($noPermId > 0) {
        $pv = rh_suc_preview($pdo, $A, $noPermId);
        $ap = rh_suc_apply($pdo, $A, 'x', $noPermId);
        check(!$pv['ok'] && !$ap['ok'] && stripos($ap['errors'][0] ?? '', 'permission') !== false && $snap($A)['unit'] === 'btl', 'no permission: preview and apply refused, nothing changed');
    } else {
        bad('no permission', 'no active non-manager user without the permission to test with');
    }
    check(rh_suc_allowed($adminId) && hasPermission((int)col($pdo, "SELECT id FROM admin_users WHERE role = 'manager' AND is_active = 1 ORDER BY id LIMIT 1") ?: $adminId, 'stock_change_unit'), 'admin and manager have the permission by default');

    // ---- preview ----
    $pv = rh_suc_preview($pdo, $A, $adminId);
    $p = $pv['plan'] ?? [];
    check($pv['ok'] && ($p['new_unit'] ?? '') === 'shot' && near($p['new_qty'] ?? 0, 285) && near($p['new_cost'] ?? 0, 800, 0.00005), 'preview: 9.5 btl at 24000 -> 285 shot at 800', json_encode($pv['errors'] ?? []));
    check(near($p['value_before'] ?? -1, 228000, 0.0001) && near($p['value_after'] ?? -2, 228000, 0.0001) && near($p['batch_value_before'] ?? -1, $p['batch_value_after'] ?? -2, 0.0001), 'preview: stock value before = after (item and batches)');
    $placeText = implode(' | ', array_map(static fn($l) => $l['place'] . ': ' . $l['old'] . ' -> ' . $l['new'], $pv['lines'] ?? []));
    check(strpos($placeText, 'Recipe: menu item #' . $menuItem) !== false && strpos($placeText, 'Purchase order ' . $run . '-PO1') !== false && strpos($placeText, 'Count ' . $run . '-C1') !== false
        && strpos($placeText, $run . '-PO2') === false && strpos($placeText, $run . '-C2') === false, 'preview lists recipe, open PO, draft count; not the received PO or approved count');

    // ---- stale preview ----
    $pdo->prepare('UPDATE stock_ingredients SET par_level = 13 WHERE id = ?')->execute([$A]);
    $ap = rh_suc_apply($pdo, $A, $pv['fingerprint'], $adminId);
    check(!$ap['ok'] && stripos($ap['errors'][0] ?? '', 'changed since the preview') !== false && $snap($A)['unit'] === 'btl', 'stale preview refused, nothing changed');
    $pdo->prepare('UPDATE stock_ingredients SET par_level = 12 WHERE id = ?')->execute([$A]);
    $pv = rh_suc_preview($pdo, $A, $adminId);
    $lastAdjBefore = (int)col($pdo, 'SELECT MAX(id) FROM stock_adjustments WHERE ingredient_id = ?', [$A]);
    $adjCountBefore = (int)col($pdo, 'SELECT COUNT(*) FROM stock_adjustments WHERE ingredient_id = ?', [$A]);

    // ---- apply: btl -> shot ----
    $ap = rh_suc_apply($pdo, $A, $pv['fingerprint'], $adminId);
    check($ap['ok'], 'apply btl -> shot', json_encode($ap['errors']));
    $s = $snap($A);
    check($s['unit'] === 'shot' && $s['measure_stock_side'] === 'small' && near($s['current_quantity'], 285) && near($s['cost_per_unit'], 800, 0.00005), 'item: 285 shot at 800, kept in the small measure');
    check(near($s['min_quantity'], 60) && near($s['reorder_point'], 120) && near($s['par_level'], 360) && near($s['pack_size'], 360, 0.0005), 'item: min 60, reorder 120, par 360, case of 360');
    check(near($s['current_quantity'], (float)$s['bq']) && near(round((float)$s['current_quantity'] * (float)$s['cost_per_unit'], 2), 228000, 0.01) && near($s['bv'], 230400, 0.01), '8c holds; item and batch value unchanged');
    $b1 = row($pdo, 'SELECT * FROM stock_batches WHERE id = ?', [$A1]);
    $b3 = row($pdo, 'SELECT * FROM stock_batches WHERE id = ?', [$A3]);
    check(near($b1['quantity_remaining'], 165) && near($b1['quantity_received'], 360) && near($b1['cost_per_unit'], 800, 0.00005)
        && near($b3['quantity_received'], 180) && near($b3['cost_per_unit'], 780, 0.00005) && $b3['status'] === 'depleted', 'batches: active and used-up rescaled (qty x30, cost /30)');
    check(near(col($pdo, 'SELECT quantity_per_portion FROM stock_recipe_ingredients WHERE id = ?', [$RL]), 15), 'recipe: 0.5 btl -> 15 shot per portion');
    $l1 = row($pdo, 'SELECT * FROM stock_purchase_order_items WHERE id = ?', [$PL1]);
    $l2 = row($pdo, 'SELECT * FROM stock_purchase_order_items WHERE id = ?', [$PL2]);
    check($l1['unit'] === 'shot' && near($l1['ordered_qty'], 180) && near($l1['unit_cost'], 800, 0.00005) && near($l1['line_total'], 144000, 0.001) && near($l1['pack_size_snapshot'], 360, 0.0005), 'open PO line: 180 shot at 800, line total kept');
    check($l2['unit'] === 'btl' && near($l2['ordered_qty'], 6) && near($l2['unit_cost'], 24000, 0.00005), 'received PO line untouched');
    check(near(col($pdo, 'SELECT total_cost FROM stock_purchase_orders WHERE id = ?', [$poOpen]), 144000, 0.001), 'PO total unchanged');
    $c1 = row($pdo, 'SELECT * FROM stock_count_lines WHERE id = ?', [$CL1]);
    $c2 = row($pdo, 'SELECT * FROM stock_count_lines WHERE id = ?', [$CL2]);
    check(near($c1['system_quantity'], 285) && near($c1['actual_quantity'], 270) && near($c1['variance'], -15) && near($c1['cost_per_unit'], 800, 0.00005) && near($c1['variance_cost'], -12000, 0.0001), 'draft count line rescaled, difference value kept');
    check(near($c2['system_quantity'], 10) && near($c2['actual_quantity'], 10) && near($c2['cost_per_unit'], 24000, 0.00005), 'approved count line untouched');
    check(near(col($pdo, 'SELECT quantity FROM stock_opening_batches WHERE id = ?', [$OB]), 60) && near(col($pdo, 'SELECT pack_size FROM stock_ingredient_barcodes WHERE id = ?', [$BC]), 30, 0.00005), 'opening batch 60 shot; barcode pack 30 shot');
    check((int)col($pdo, 'SELECT COUNT(*) FROM stock_adjustments WHERE ingredient_id = ?', [$A]) === $adjCountBefore, 'ledger: no movement written for the change');
    $chg = row($pdo, 'SELECT * FROM stock_unit_changes WHERE ingredient_id = ? ORDER BY id DESC LIMIT 1', [$A]);
    check(($chg['old_unit'] ?? '') === 'btl' && $chg['new_unit'] === 'shot' && $chg['new_side'] === 'small' && (int)$chg['last_adjustment_id'] === $lastAdjBefore
        && (int)$chg['changed_by'] === $adminId && near($chg['value_before'], $chg['value_after'], 0.01), 'change row: btl -> shot, last movement id, who, value');
    $log = (string)col($pdo, "SELECT details FROM admin_activity_log WHERE action = 'stock_main_unit_changed' AND details LIKE ? ORDER BY id DESC LIMIT 1", ['%' . $run . '%']);
    check(strpos($log, 'main unit btl -> shot') !== false && strpos($log, 'On hand: 9.5 btl -> 285 shot') !== false, 'activity log: old -> new per place', $log);
    $hist = rh_suc_history($pdo, $A);
    check(count($hist) === 1 && $hist[0]['old_unit'] === 'btl', 'item history lists the change');

    // ---- the sale before the change is restored in shots, at the same value ----
    check(restoreStockForMenuItem($menuItem, $menuType, 1, 'unit test restore', $adminId, $srcBase + 1, 'manual') === true, 'restore of a pre-change sale');
    $s = $snap($A);
    $ra = row($pdo, "SELECT quantity_change, cost_at_time FROM stock_adjustments WHERE source_type = 'void_restore' AND restore_of_type = 'manual' AND source_id = ? AND ingredient_id = ?", [$srcBase + 1, $A]);
    check(near($s['current_quantity'], 300) && near(col($pdo, 'SELECT quantity_remaining FROM stock_batches WHERE id = ?', [$A1]), 180) && near($s['current_quantity'], (float)$s['bq']), 'restored 15 shot into A1; 8c holds');
    check(near($ra['quantity_change'] ?? 0, 15) && near($ra['cost_at_time'] ?? 0, 800, 0.00005), 'restore row: +15 shot at 800 (was 0.5 btl at 24000)');
    check(restoreStockForMenuItem($menuItem, $menuType, 1, 'unit test restore again', $adminId, $srcBase + 1, 'manual') === true && near($snap($A)['current_quantity'], 300), 'no double restore');

    // ---- a sale after the change ----
    check(deductStockForMenuItem($menuItem, $menuType, 2, 'manual', $srcBase + 2, $adminId) === true, 'sale after the change deducted');
    $s = $snap($A);
    check(near($s['current_quantity'], 270) && near($s['current_quantity'], (float)$s['bq']), 'sale after: 30 shot off (2 x 15); 8c holds');

    // ---- the draft count now has movements since it started: refused ----
    $pv = rh_suc_preview($pdo, $A, $adminId);
    check(!$pv['ok'] && strpos(implode(' ', $pv['errors']), $run . '-C1') !== false, 'draft count with movements since it started refuses the change');
    $pdo->prepare("UPDATE stock_counts SET status = 'rejected' WHERE id = ?")->execute([$cDraft]);

    // ---- shot -> bottle: the old unit name comes back, round trip equal ----
    $pv = rh_suc_preview($pdo, $A, $adminId);
    $ap = $pv['ok'] ? rh_suc_apply($pdo, $A, $pv['fingerprint'], $adminId) : ['ok' => false, 'errors' => $pv['errors']];
    check($ap['ok'], 'apply shot -> btl', json_encode($ap['errors']));
    $s = $snap($A);
    check($s['unit'] === 'btl' && $s['measure_stock_side'] === 'big' && near($s['current_quantity'], 9) && near($s['cost_per_unit'], 24000, 0.00005), 'back to 9 btl at 24000 (unit name btl back)');
    check(near($s['min_quantity'], 2) && near($s['reorder_point'], 4) && near($s['par_level'], 12) && near($s['pack_size'], 12, 0.0005), 'settings round trip exact');
    $b1 = row($pdo, 'SELECT * FROM stock_batches WHERE id = ?', [$A1]);
    $b2 = row($pdo, 'SELECT * FROM stock_batches WHERE id = ?', [$A2]);
    check(near($b1['quantity_remaining'], 5) && near($b1['quantity_received'], 12) && near($b1['cost_per_unit'], 24000, 0.00005) && near($b2['quantity_remaining'], 4) && near($b2['cost_per_unit'], 24600, 0.00005), 'batches round trip exact');
    check(near(col($pdo, 'SELECT quantity_per_portion FROM stock_recipe_ingredients WHERE id = ?', [$RL]), 0.5) && near(col($pdo, 'SELECT unit_cost FROM stock_purchase_order_items WHERE id = ?', [$PL1]), 24000, 0.00005)
        && col($pdo, 'SELECT unit FROM stock_purchase_order_items WHERE id = ?', [$PL1]) === 'btl', 'recipe and PO line round trip exact');
    $c1 = row($pdo, 'SELECT * FROM stock_count_lines WHERE id = ?', [$CL1]);
    check(near($c1['system_quantity'], 9.5) && near($c1['actual_quantity'], 9), 'rejected count line converted back too');

    // ---- restore across two changes: the after-first-change sale (30 shot at 800) -> 1 btl at 24000 ----
    check(restoreStockForMenuItem($menuItem, $menuType, 2, 'unit test restore 2', $adminId, $srcBase + 2, 'manual') === true, 'restore of a sale made between the two changes');
    $s = $snap($A);
    $ra = row($pdo, "SELECT quantity_change, cost_at_time FROM stock_adjustments WHERE source_type = 'void_restore' AND restore_of_type = 'manual' AND source_id = ? AND ingredient_id = ?", [$srcBase + 2, $A]);
    check(near($s['current_quantity'], 10) && near(col($pdo, 'SELECT quantity_remaining FROM stock_batches WHERE id = ?', [$A1]), 6) && near($ra['quantity_change'] ?? 0, 1) && near($ra['cost_at_time'] ?? 0, 24000, 0.00005), 'restored 1 btl at 24000 into A1; back to 10');
    check(near($s['current_quantity'], (float)$s['bq']), '8c holds after both restores');

    // ---- refusals ----
    $B = $mkIng('Unit test gin ' . $run, 'btl', 12, 25000, ['bottle', 'shot', 30, 'big']);
    $mkBatch($B, $run . '-B1', 12, 12, 25000, 'active');
    $pv = rh_suc_preview($pdo, $B, $adminId);
    check(!$pv['ok'] && stripos(implode(' ', $pv['errors']), 'cannot be stored exactly') !== false, 'value would move by more than ' . BALANCE_TOLERANCE . ' (12 x 25000 / 30): refused');
    $ap = rh_suc_apply($pdo, $B, (string)$pv['fingerprint'], $adminId);
    check(!$ap['ok'] && $snap($B)['unit'] === 'btl', 'precision refusal: apply changes nothing');
    $C = $mkIng('Unit test no measure ' . $run, 'btl', 0, 100, null);
    check(!rh_suc_preview($pdo, $C, $adminId)['ok'], 'no count measure: refused');
    $D = $mkIng('Unit test no batches ' . $run, 'btl', 5, 300, ['bottle', 'shot', 30, 'big']);
    $pv = rh_suc_preview($pdo, $D, $adminId);
    check(!$pv['ok'] && stripos(implode(' ', $pv['errors']), 'does not match its batches') !== false, 'stock not equal to its batches: refused');

    // ---- deduct guard: a change committed after the sale's snapshot fails the sale (nothing deducted) ----
    $E = $mkIng('Unit test rum ' . $run, 'btl', 3, 9000, ['bottle', 'shot', 30, 'big']);
    $mkBatch($E, $run . '-E1', 3, 3, 9000, 'active');
    $menuItemE = $menuItem + 1;
    $pdo->prepare("INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe) VALUES (?, ?, 1)")->execute([$menuItemE, $menuType]);
    $recipeIds[] = $RE = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion) VALUES (?, ?, 1)")->execute([$RE, $E]);
    $pdo2 = new PDO('mysql:host=' . DB_HOST . (defined('DB_PORT') ? ';port=' . DB_PORT : '') . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo2->exec("SET time_zone = '" . (string)$pdo->query('SELECT @@session.time_zone')->fetchColumn() . "'");
    $pdo->beginTransaction();
    $pdo->query('SELECT COUNT(*) FROM stock_recipe_ingredients WHERE recipe_id = ' . (int)$RE)->fetchColumn(); // the sale's snapshot starts here
    $pvE = rh_suc_preview($pdo2, $E, $adminId);
    $apE = $pvE['ok'] ? rh_suc_apply($pdo2, $E, $pvE['fingerprint'], $adminId) : ['ok' => false, 'errors' => $pvE['errors']];
    $guarded = deductStockForMenuItem($menuItemE, $menuType, 1, 'manual', $srcBase + 3, $adminId);
    $pdo->rollBack();
    $s = $snap($E);
    check($apE['ok'] && $guarded === false && near($s['current_quantity'], 90) && $s['unit'] === 'shot', 'sale read the recipe before a committed change: refused, nothing deducted', json_encode($apE['errors']));
    check(deductStockForMenuItem($menuItemE, $menuType, 1, 'manual', $srcBase + 4, $adminId) === true && near($snap($E)['current_quantity'], 60), 'the retried sale deducts in the new unit (1 portion = 30 shot)');
    $pdo2 = null;

    // ---- late cost (backcost) after a change: a sale before it keeps its cost of sale ----
    $F = $mkIng('Unit test vodka ' . $run, 'btl', 1, 5000, ['bottle', 'shot', 10, 'big']);
    $mkBatch($F, $run . '-F1', 1, 1, 5000, 'active');
    $mkSale = function (string $tag, int $ing, float $qty) use ($pdo, $run, $adminId, &$orderIds): int {
        $pdo->prepare("INSERT INTO stock_orders (reference, order_type, table_number, customer_name, status, total_amount, subtotal, kitchen_status, fired_at, created_by, created_at)
            VALUES (?, 'dine_in', 'ZZS', 'Unit change test', 'paid', 100, 100, 'new', NOW(), ?, NOW())")->execute([$run . '-' . $tag, $adminId]);
        $oid = $orderIds[] = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status)
            VALUES (?, 1, 'food', 'ZZS item', 1, 100, 100, 'bar', 'served')")->execute([$oid]);
        $lid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at)
            VALUES (?, ?, 'Unit change test sale at no cost', 'pos_order', ?, 0, ?, NOW())")->execute([$ing, -$qty, $lid, $adminId]);
        return $oid;
    };
    $O1 = $mkSale('O1', $F, 2.0); // 2 btl sold at no cost BEFORE the change (already settled then)
    $o1Cost = (float)col($pdo, 'SELECT total_cost FROM stock_orders WHERE id = ?', [$O1]);
    $pv = rh_suc_preview($pdo, $F, $adminId);
    $ap = $pv['ok'] ? rh_suc_apply($pdo, $F, $pv['fingerprint'], $adminId) : ['ok' => false, 'errors' => $pv['errors']];
    check($ap['ok'] && $snap($F)['unit'] === 'shot', 'vodka: btl -> shot', json_encode($ap['errors']));
    $O2 = $mkSale('O2', $F, 5.0); // 5 shot sold at no cost AFTER the change
    // A delivery settles a deficit of 20 shot (the 5 sold + 15 from elsewhere) at 500 a shot.
    $bc = rh_stock_backcost_settled_deficit($pdo, $F, 20.0, 20.0, 500.0, $adminId, 'unit change test receipt');
    check(near(col($pdo, 'SELECT total_cost FROM stock_orders WHERE id = ?', [$O1]), $o1Cost, 0.0001), 'late cost: the sale before the change keeps its cost of sale', json_encode($bc));
    check($bc['orders'] === 1 && near($bc['amount'], 2500, 0.0001) && near(col($pdo, 'SELECT total_cost FROM stock_orders WHERE id = ?', [$O2]), 2500, 0.0001), 'late cost: only the sale after the change is costed (5 shot x 500)', json_encode($bc));

    // ---- void of a started line after a change: wastage in today's unit, at the value the usage carried ----
    require_once __DIR__ . '/../../admin/includes/restaurant-payment-sync.php';
    $G = $mkIng('Unit test brandy ' . $run, 'btl', 4, 12000, ['bottle', 'shot', 10, 'big']);
    $mkBatch($G, $run . '-G1', 4, 4, 12000, 'active');
    $menuItemG = $menuItem + 2;
    $pdo->prepare("INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe) VALUES (?, ?, 1)")->execute([$menuItemG, $menuType]);
    $recipeIds[] = $RG = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion) VALUES (?, ?, 0.5)")->execute([$RG, $G]);
    $lineG = $srcBase + 5;
    check(deductStockForMenuItem($menuItemG, $menuType, 1, 'pos_order', $lineG, $adminId) === true, 'brandy: started line used 0.5 btl (6000) before the change');
    $pv = rh_suc_preview($pdo, $G, $adminId);
    $ap = $pv['ok'] ? rh_suc_apply($pdo, $G, $pv['fingerprint'], $adminId) : ['ok' => false, 'errors' => $pv['errors']];
    check($ap['ok'] && near($snap($G)['current_quantity'], 35), 'brandy: btl -> shot (35 shot)', json_encode($ap['errors']));
    $wr = rh_record_item_wastage($pdo, $menuItemG, $menuType, 1, false, $adminId, 'Unit change test void ' . $run, 'pos_order', $lineG);
    $w = row($pdo, 'SELECT quantity, cost_per_unit, wastage_cost FROM stock_wastage WHERE ingredient_id = ? ORDER BY id DESC LIMIT 1', [$G]);
    check($wr === 1 && near($w['quantity'] ?? 0, 5) && near($w['cost_per_unit'] ?? 0, 1200, 0.00005) && near($w['wastage_cost'] ?? 0, 6000, 0.0001), 'void after the change: wastage 5 shot at 1200 = 6000 (the usage value)', json_encode($w));
    check(near($snap($G)['current_quantity'], 35), 'void after the change: stock not moved again');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    bad('unexpected error', $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE bd FROM stock_batch_deductions bd INNER JOIN stock_adjustments a ON a.id = bd.adjustment_id WHERE a.ingredient_id IN ($in)");
        $pdo->exec("DELETE FROM stock_adjustments WHERE ingredient_id IN ($in)");
        $pdo->exec("DELETE FROM stock_wastage WHERE ingredient_id IN ($in)");
        $pdo->exec("DELETE FROM stock_recipe_ingredients WHERE ingredient_id IN ($in)");
        $pdo->exec("DELETE FROM stock_opening_batches WHERE ingredient_id IN ($in)");
        $pdo->exec("DELETE FROM stock_count_lines WHERE ingredient_id IN ($in)");
        $pdo->exec("DELETE FROM stock_purchase_order_items WHERE ingredient_id IN ($in)");
        $pdo->exec("DELETE FROM stock_ingredient_barcodes WHERE ingredient_id IN ($in)");
        $pdo->exec("DELETE FROM stock_batches WHERE ingredient_id IN ($in)");
        $pdo->exec("DELETE FROM stock_unit_changes WHERE ingredient_id IN ($in)");
        $pdo->exec("DELETE FROM stock_ingredients WHERE id IN ($in)");
    }
    if ($recipeIds) {
        $pdo->exec('DELETE FROM stock_recipes WHERE id IN (' . implode(',', array_map('intval', $recipeIds)) . ')');
    }
    if ($countIds) {
        $pdo->exec('DELETE FROM stock_counts WHERE id IN (' . implode(',', array_map('intval', $countIds)) . ')');
    }
    if ($poIds) {
        $pdo->exec('DELETE FROM stock_purchase_orders WHERE id IN (' . implode(',', array_map('intval', $poIds)) . ')');
    }
    if ($orderIds) {
        $oin = implode(',', array_map('intval', $orderIds));
        $pdo->exec("DELETE FROM stock_order_audit WHERE order_id IN ($oin)");
        $pdo->exec("DELETE FROM stock_order_items WHERE order_id IN ($oin)");
        $pdo->exec("DELETE FROM stock_orders WHERE id IN ($oin)");
    }
    $pdo->prepare("DELETE FROM admin_activity_log WHERE action = 'stock_main_unit_changed' AND details LIKE ?")->execute(['%' . $run . '%']);
    $left = (int)col($pdo, 'SELECT COUNT(*) FROM stock_ingredients WHERE category = ?', [$run])
        + (int)col($pdo, 'SELECT COUNT(*) FROM stock_recipes WHERE menu_type = ? AND menu_item_id IN (?, ?, ?)', [$menuType, $menuItem, $menuItem + 1, $menuItem + 2])
        + (int)col($pdo, 'SELECT COUNT(*) FROM stock_orders WHERE reference LIKE ?', [$run . '-O%'])
        + (int)col($pdo, 'SELECT COUNT(*) FROM stock_purchase_orders WHERE reference LIKE ?', [$run . '%'])
        + (int)col($pdo, 'SELECT COUNT(*) FROM stock_counts WHERE reference LIKE ?', [$run . '%'])
        + (int)col($pdo, "SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_main_unit_changed' AND details LIKE ?", ['%' . $run . '%']);
    check($left === 0, 'clean-up: nothing left', (string)$left);
    if (function_exists('rh_clear_stock_dashboard_cache')) {
        rh_clear_stock_dashboard_cache();
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
