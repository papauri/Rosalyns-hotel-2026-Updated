<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/stock_count_approval.php (run by page_harness.php: one outer transaction
 * that can never commit, rolled back at the end). The harness signs in as an admin ("me").
 *
 * POST fields: run, kind (opening|big), rule (on|off = the stock_count_second_approver setting), perm (yes|no =
 * whether "me" holds stock_count_approve), counter (me|other), do (approve_count|reject_count|remind_count).
 * Builds a submitted count whose counter is me or another user, then performs the action through the REAL
 * admin/stock-count.php page. The parent reads the result with verify SQL on the count reference.
 */
$run = (string)($_POST['run'] ?? 'X');
$kind = (string)($_POST['kind'] ?? 'opening');
$ruleOn = (string)($_POST['rule'] ?? 'on') !== 'off';
$perm = (string)($_POST['perm'] ?? 'yes') !== 'no';
$do = (string)($_POST['do'] ?? 'approve_count');
$csrfTok = (string)($_POST['csrf_token'] ?? '');

$me = (int)($_SESSION['admin_user_id'] ?? 0);
$other = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND id <> " . $me . " ORDER BY id LIMIT 1")->fetchColumn();
$counter = ((string)($_POST['counter'] ?? 'me') === 'me') ? $me : $other;

if (!$perm) {
    $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'stock_count_approve', 0) ON DUPLICATE KEY UPDATE is_granted = 0")->execute([$me]);
}
updateSetting('stock_count_second_approver', $ruleOn ? '1' : '0');
updateSetting('stock_variance_block_pct', '10');
updateSetting('stock_variance_min_cost', '5000');

$hasCountedAt = true;
try { $pdo->query("SELECT counted_at FROM stock_count_lines LIMIT 0"); } catch (Throwable $e) { $hasCountedAt = false; }

$opening = $kind === 'opening' ? 1 : 0;
$qty = $opening ? 0 : 100;
$pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'Pantry', 'kg', ?, 200)")->execute(['ZZT ' . $run . ' Item', $qty]);
$ingId = (int)$pdo->lastInsertId();
if (!$opening) {
    $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, expiry_alert_days, status, notes) VALUES (?, ?, 100, 100, 200, CURDATE(), 7, 'active', 'ZZT test')")
        ->execute([$ingId, 'ZZT-' . $run]);
}

$ref = '20991231-CH' . $run . substr($kind, 0, 1);
$pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, is_opening, created_at) VALUES (?, CURDATE(), 'full', ?, ?, ?, NOW() - INTERVAL 5 MINUTE)")
    ->execute([$ref, $do === 'submit_count' ? 'draft' : 'submitted', $counter, $opening]);   // submit_count starts from a draft
$countId = (int)$pdo->lastInsertId();
$actual = $opening ? 10 : 50;            // opening: 10 added at cost 200 (unit cost present); big: short 50 of 100 = 50%, worth 10,000
$variance = $actual - $qty;
$sql = "INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost, reason_code" . ($hasCountedAt ? ', counted_at' : '') . ") VALUES (?,?,?,?,?,?,?,?" . ($hasCountedAt ? ", NOW() - INTERVAL 1 MINUTE" : '') . ")";
$pdo->prepare($sql)->execute([$countId, $ingId, $qty, $actual, $variance, 200, round($variance * 200, 4), $variance < 0 ? 'spillage' : '']);
$pdo->prepare("UPDATE stock_counts SET shortage_cost = ?, surplus_cost = ? WHERE id = ?")->execute([$variance < 0 ? round($variance * 200, 4) : 0, $variance > 0 ? round($variance * 200, 4) : 0, $countId]);

/* Partial-submit scenarios (do = submit_count): 'blanks' adds one not-counted line (Blank) and one typed-0 line (Zero);
 * 'allblank' un-counts the main line too, so nothing has a reading. */
if ((string)($_POST['blanks'] ?? '') === 'yes' || (string)($_POST['blanks'] ?? '') === 'allblank') {
    foreach (['Blank', 'Zero'] as $nm) {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'Pantry', 'kg', 0, 200)")->execute(['ZZT ' . $run . ' ' . $nm]);
        $xid = (int)$pdo->lastInsertId();
        if ($nm === 'Blank') {   // not counted: stock 5 at the start, no reading (actual 0 / variance 0, counted_at NULL)
            $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost) VALUES (?,?,5,0,0,200,0)")->execute([$countId, $xid]);
        } else {                 // typed 0 on an item with nothing in stock: a real reading
            $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost" . ($hasCountedAt ? ', counted_at' : '') . ") VALUES (?,?,0,0,0,200,0" . ($hasCountedAt ? ', NOW()' : '') . ")")->execute([$countId, $xid]);
        }
    }
    if ((string)$_POST['blanks'] === 'allblank') {
        $pdo->prepare("DELETE FROM stock_count_lines WHERE count_id = ? AND ingredient_id <> ? AND actual_quantity = 0 AND system_quantity = 0")->execute([$countId, $ingId]);
        $pdo->prepare("UPDATE stock_count_lines SET actual_quantity = 0, variance = 0, variance_cost = 0" . ($hasCountedAt ? ', counted_at = NULL' : '') . ", system_quantity = 5 WHERE count_id = ?")->execute([$countId]);
    }
}

$_POST = ['action' => $do, 'count_id' => (string)$countId, 'csrf_token' => $csrfTok, 'rejection_reason' => 'Test rejection reason for the check'];
$_REQUEST = $_POST;
chdir($root . '/admin');
require $root . '/admin/stock-count.php';
