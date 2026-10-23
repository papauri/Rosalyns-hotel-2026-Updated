<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/stock_count_delete.php (run by page_harness.php: one outer transaction
 * that can never commit, rolled back at the end). The harness signs in as an admin, or as an existing manager
 * (cfg.as_username) so a non-admin approver can be tested.
 *
 * POST fields: run, status (draft|submitted|approved|rejected), counter (me|other), perm (yes|no = whether the
 * signed-in user holds stock_count_approve). Builds a count with one line (and an opening batch when that table
 * exists) for a throw-away ingredient, then presses "delete_count" on the REAL admin/stock-count.php page.
 */
$run = (string)($_POST['run'] ?? 'X');
$status = (string)($_POST['status'] ?? 'rejected');
$perm = (string)($_POST['perm'] ?? 'yes') !== 'no';
$csrfTok = (string)($_POST['csrf_token'] ?? '');

$me = (int)($_SESSION['admin_user_id'] ?? 0);
$other = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND id <> " . $me . " ORDER BY id LIMIT 1")->fetchColumn();
$counter = ((string)($_POST['counter'] ?? 'me') === 'me') ? $me : $other;

if (!$perm) {
    $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'stock_count_approve', 0) ON DUPLICATE KEY UPDATE is_granted = 0")->execute([$me]);
}

$pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'Pantry', 'kg', 100, 200)")->execute(['ZZT DL ' . $run . ' Item']);
$ingId = (int)$pdo->lastInsertId();

$ref = '20991231-DL' . $run;
$pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, rejection_reason) VALUES (?, CURDATE(), 'full', ?, ?, ?)")
    ->execute([$ref, $status, $counter, $status === 'rejected' ? 'Test rejection reason for the check' : null]);
$countId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost) VALUES (?, ?, 100, 90, -10, 200, -2000)")
    ->execute([$countId, $ingId]);
$hasOb = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opening_batches'")->fetchColumn() > 0;
if ($hasOb) {
    $pdo->prepare("INSERT INTO stock_opening_batches (count_id, ingredient_id, batch_label, quantity) VALUES (?, ?, 'ZZT', 5)")->execute([$countId, $ingId]);
}

$_POST = ['action' => 'delete_count', 'count_id' => (string)$countId, 'csrf_token' => $csrfTok];
$_REQUEST = $_POST;
chdir($root . '/admin');
require $root . '/admin/stock-count.php';
