<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/stock_count_bigform.php (run by page_harness.php: one outer transaction
 * that can never commit, rolled back at the end). The harness signs in as an admin.
 *
 * POST fields: run, scenario (packed|packed_bad_id|packed_negative|packed_garbage|legacy|legacy_too_many|submit).
 * Builds a draft 420-line count (existing ingredients, topped up with rolled-back ZZT ones), then posts to the REAL
 * admin/stock-count.php page as a browser would.
 */
$run = (string)($_POST['run'] ?? 'X');
$scn = (string)($_POST['scenario'] ?? 'packed');
$csrfTok = (string)($_POST['csrf_token'] ?? '');
$me = (int)($_SESSION['admin_user_id'] ?? 0);
$N = 420;

if ($scn === 'stale_block' || $scn === 'stale_ok') {
    /* Two counts of the same item. X (approved) posted +5. Y (submitted, snapshot 5 min ago, line counted 3 min ago) shows the
     * same 5. stale_block: X was approved 1 min ago = AFTER Y's line was counted -> approving Y would add it twice -> refused.
     * stale_ok: X was approved 4 min ago = BEFORE Y's line was counted -> Y already saw it -> allowed. */
    $min = $scn === 'stale_block' ? 1 : 4;
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'Pantry', 'kg', 0, 100)")->execute(['ZZT BF ' . $run . ' stale']);
    $ing = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, is_opening, created_at, snapshot_at, approved_by, approved_at) VALUES (?, CURDATE(), 'full', 'approved', ?, 0, NOW() - INTERVAL 9 MINUTE, NOW() - INTERVAL 9 MINUTE, ?, NOW() - INTERVAL $min MINUTE)")
        ->execute(['20991231-BF' . $run . 'X', $me, $me]);
    $xId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at) VALUES (?, 5, 'ZZT stale test', 'variance', ?, 100, ?, NOW() - INTERVAL $min MINUTE)")->execute([$ing, $xId, $me]);
    $adj = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost, adjustment_id, counted_at) VALUES (?,?,0,5,5,100,500,?, NOW() - INTERVAL 9 MINUTE)")->execute([$xId, $ing, $adj]);
    $pdo->prepare("UPDATE stock_ingredients SET current_quantity = 5 WHERE id = ?")->execute([$ing]);
    $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, is_opening, created_at, snapshot_at) VALUES (?, CURDATE(), 'full', 'submitted', ?, 0, NOW() - INTERVAL 10 MINUTE, NOW() - INTERVAL 10 MINUTE)")
        ->execute(['20991231-BF' . $run . 'A', $me]);
    $yId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost, reason_code, counted_at) VALUES (?,?,0,5,5,100,500,'', NOW() - INTERVAL 3 MINUTE)")->execute([$yId, $ing]);
    $_POST = ['action' => 'approve_count', 'count_id' => (string)$yId, 'csrf_token' => $csrfTok];
    $_REQUEST = $_POST;
    chdir($root . '/admin');
    require $root . '/admin/stock-count.php';
    exit;
}

$ids = array_map('intval', $pdo->query("SELECT id FROM stock_ingredients WHERE is_archived = 0 ORDER BY id LIMIT $N")->fetchAll(PDO::FETCH_COLUMN));
$ins = $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'Pantry', 'kg', 10, 100)");
for ($i = count($ids); $i < $N; $i++) {
    $ins->execute(['ZZT BF ' . $run . ' ' . $i]);
    $ids[] = (int)$pdo->lastInsertId();
}
$hasCountedAt = true;
try { $pdo->query("SELECT counted_at FROM stock_count_lines LIMIT 0"); } catch (Throwable $e) { $hasCountedAt = false; }

$mkCount = function (string $tag, int $n) use ($pdo, $run, $me, $ids): array {
    $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, is_opening, created_at, snapshot_at) VALUES (?, CURDATE(), 'full', 'draft', ?, 0, NOW() - INTERVAL 5 MINUTE, NOW() - INTERVAL 5 MINUTE)")
        ->execute(['20991231-BF' . $run . $tag, $me]);
    $cid = (int)$pdo->lastInsertId();
    $lineIns = $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost) SELECT ?, id, current_quantity, 0, 0, cost_per_unit, 0 FROM stock_ingredients WHERE id = ?");
    for ($i = 0; $i < $n; $i++) { $lineIns->execute([$cid, $ids[$i]]); }
    $q = $pdo->prepare("SELECT id FROM stock_count_lines WHERE count_id = ? ORDER BY id");
    $q->execute([$cid]);
    return [$cid, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN))];
};

[$countId, $lineIds] = $mkCount('A', $N);
$post = ['action' => 'save_lines', 'count_id' => (string)$countId, 'csrf_token' => $csrfTok];
$packRows = function (array $rows): array {
    $chunks = [];
    foreach (array_chunk($rows, 50) as $c) { $chunks[] = json_encode($c); }
    return $chunks;
};

if ($scn === 'packed') {
    $rows = [];
    foreach ($lineIds as $k => $lid) { $rows[] = ['id' => $lid, 'actual' => (string)(($k % 7) + 1), 'touched' => '1', 'reason' => 'spillage', 'notes' => 'bf ' . $k]; }
    $post['lines_json'] = $packRows($rows);
} elseif ($scn === 'packed_bad_id') {
    [, $otherIds] = $mkCount('B', 3);
    $rows = [['id' => $lineIds[0], 'actual' => '5', 'touched' => '1'], ['id' => $otherIds[0], 'actual' => '5', 'touched' => '1']];
    $post['lines_json'] = $packRows($rows);
} elseif ($scn === 'packed_negative') {
    $rows = [['id' => $lineIds[0], 'actual' => '5', 'touched' => '1'], ['id' => $lineIds[1], 'actual' => '-3', 'touched' => '1']];
    $post['lines_json'] = $packRows($rows);
} elseif ($scn === 'packed_garbage') {
    $post['lines_json'] = ['{"id": 1, "actual"'];
} elseif ($scn === 'legacy') {
    foreach (array_slice($lineIds, 0, 5) as $k => $lid) { $post['line'][$lid] = ['actual' => (string)($k + 2), 'touched' => '1', 'reason' => '', 'notes' => 'old']; }
} elseif ($scn === 'legacy_too_many') {
    foreach ($lineIds as $lid) { $post['line'][$lid] = ['actual' => '4', 'touched' => '1', 'reason' => '', 'notes' => '']; }   // 420 x 4 = 1,680 fields > max_input_vars
} elseif ($scn === 'submit') {
    if ($hasCountedAt) {
        $pdo->prepare("UPDATE stock_count_lines SET actual_quantity = 3, variance = 0, variance_cost = 0, counted_at = NOW() WHERE count_id = ?")->execute([$countId]);
    } else {
        $pdo->prepare("UPDATE stock_count_lines SET actual_quantity = 3 WHERE count_id = ?")->execute([$countId]);
    }
    $pdo->prepare("UPDATE stock_count_lines SET reason_code = 'other' WHERE count_id = ?")->execute([$countId]);
    $post = ['action' => 'submit_count', 'count_id' => (string)$countId, 'csrf_token' => $csrfTok];
}

$_POST = $post;
$_REQUEST = $_POST;
chdir($root . '/admin');
require $root . '/admin/stock-count.php';
