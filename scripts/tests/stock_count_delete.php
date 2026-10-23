<?php
/**
 * Deleting a rejected stock count, or a draft count started by mistake (admin/stock-count.php, action delete_count).
 * Usage: php scripts/tests/stock_count_delete.php
 *
 * Runs through the REAL page in scripts/tests/page_harness.php (one outer transaction that never commits):
 * an administrator can delete a rejected count (lines and opening batches go, the deletion is logged, stock
 * quantity unchanged, no stock movement written); the same for a draft that is still being counted; a count waiting
 * for approval or approved can NOT be deleted; the person who started a draft can delete their OWN draft (even without
 * the approve permission); a non-admin approver can delete someone else's rejected or draft count but NOT their own
 * rejected count, and a user without the approve permission cannot delete anyone else's count. The page is the judge of the rule.
 * Nothing is left behind.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

$harness = __DIR__ . '/page_harness.php';
$hasOb = (int)col($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opening_batches'") > 0;
$manager = (string)col($pdo, "SELECT username FROM admin_users WHERE is_active = 1 AND role = 'manager' ORDER BY id LIMIT 1");

$scenario = function (array $post, bool $asManager) use ($harness, $hasOb, $manager): array {
    $run = strtoupper(bin2hex(random_bytes(3)));
    $like = "'20991231-DL" . $run . "%'";
    $verify = [
        "SELECT COUNT(*) AS n FROM stock_counts WHERE reference LIKE $like",
        "SELECT COUNT(*) AS n FROM stock_count_lines l INNER JOIN stock_counts c ON c.id = l.count_id WHERE c.reference LIKE $like",
        $hasOb ? "SELECT COUNT(*) AS n FROM stock_opening_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE i.name = 'ZZT DL " . $run . " Item'" : "SELECT 0 AS n",
        "SELECT current_quantity FROM stock_ingredients WHERE name = 'ZZT DL " . $run . " Item'",
        "SELECT COUNT(*) AS n FROM stock_adjustments a INNER JOIN stock_ingredients i ON i.id = a.ingredient_id WHERE i.name = 'ZZT DL " . $run . " Item'",
        "SELECT COUNT(*) AS n FROM admin_activity_log WHERE action = 'stock_count_deleted' AND details LIKE '%20991231-DL" . $run . "%'",
    ];
    $cfg = base64_encode(json_encode(['post' => $post + ['run' => $run], 'verify' => $verify] + ($asManager ? ['as_username' => $manager] : [])));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/stock_count_delete_child.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -600), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $res = $x; } }
    return $res;
};

$snapshot = static function () use ($pdo): array {
    return [
        'counts' => (int)col($pdo, "SELECT COUNT(*) FROM stock_counts"),
        'lines' => (int)col($pdo, "SELECT COUNT(*) FROM stock_count_lines"),
        'adj' => (int)col($pdo, "SELECT COUNT(*) FROM stock_adjustments"),
    ];
};
$before = $snapshot();

// [label, post, run as the manager?, count should be deleted?]
$cases = [
    ['admin, rejected count counted by someone else', ['status' => 'rejected', 'counter' => 'other'], false, true],
    ['admin, rejected count the admin counted', ['status' => 'rejected', 'counter' => 'me'], false, true],
    ['admin, count still being counted (a draft started by mistake)', ['status' => 'draft', 'counter' => 'other'], false, true],
    ['admin, a draft the admin started themselves', ['status' => 'draft', 'counter' => 'me'], false, true],
    ['admin, count waiting for approval', ['status' => 'submitted', 'counter' => 'other'], false, false],
    ['admin, approved count', ['status' => 'approved', 'counter' => 'other'], false, false],
    ['approver (manager), rejected count counted by someone else', ['status' => 'rejected', 'counter' => 'other', 'perm' => 'yes'], true, true],
    ['approver (manager), their OWN rejected count', ['status' => 'rejected', 'counter' => 'me', 'perm' => 'yes'], true, false],
    ['user without the approve permission, their OWN rejected count', ['status' => 'rejected', 'counter' => 'me', 'perm' => 'no'], true, false],
    ['user without the approve permission, rejected count of someone else', ['status' => 'rejected', 'counter' => 'other', 'perm' => 'no'], true, false],
    ['approver (manager), a draft counted by someone else', ['status' => 'draft', 'counter' => 'other', 'perm' => 'yes'], true, true],
    ['approver (manager), their OWN draft (the person who started it)', ['status' => 'draft', 'counter' => 'me', 'perm' => 'yes'], true, true],
    ['user without the approve permission, their OWN draft', ['status' => 'draft', 'counter' => 'me', 'perm' => 'no'], true, true],
    ['user without the approve permission, draft of someone else', ['status' => 'draft', 'counter' => 'other', 'perm' => 'no'], true, false],
];
if ($manager === '') {
    echo "SKIP manager scenarios: no active manager user\n";
    $cases = array_values(array_filter($cases, static fn($c) => !$c[2]));
}
foreach ($cases as [$label, $post, $asManager, $wantDeleted]) {
    $res = $scenario($post, $asManager);
    check(!empty($res['rolled_back']) && empty($res['leak']) && empty($res['marker_lost']), "Page ($label): ran and rolled back", json_encode($res['raw'] ?? $res));
    $n = (int)($res['verify'][0][0]['n'] ?? -1);
    $lines = (int)($res['verify'][1][0]['n'] ?? -1);
    $batches = (int)($res['verify'][2][0]['n'] ?? -1);
    check($n === ($wantDeleted ? 0 : 1), "Page ($label): count " . ($wantDeleted ? 'deleted' : 'kept'), 'rows=' . $n . ' ' . json_encode($res['output_flash'] ?? []));
    check($lines === ($wantDeleted ? 0 : 1), "Page ($label): its lines " . ($wantDeleted ? 'deleted too' : 'kept'), 'lines=' . $lines);
    if ($hasOb) { check($batches === ($wantDeleted ? 0 : 1), "Page ($label): its opening batches " . ($wantDeleted ? 'deleted too' : 'kept'), 'batches=' . $batches); }
    $qty = (float)($res['verify'][3][0]['current_quantity'] ?? -1);
    check(abs($qty - 100.0) < 0.0001, "Page ($label): stock quantity unchanged", (string)$qty);
    check((int)($res['verify'][4][0]['n'] ?? -1) === 0, "Page ($label): no stock movement written");
    check((int)($res['verify'][5][0]['n'] ?? -1) === ($wantDeleted ? 1 : 0), "Page ($label): deletion " . ($wantDeleted ? 'written to the activity log' : 'not logged'));
}

// Leak check
check((int)col($pdo, "SELECT COUNT(*) FROM stock_counts WHERE reference LIKE '20991231-DL%'") === 0, 'Leak: no test stock counts left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT DL %'") === 0, 'Leak: no test ingredients left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM admin_activity_log WHERE details LIKE '%20991231-DL%'") === 0, 'Leak: no test activity log rows left behind');
check($snapshot() === $before, 'Leak: real counts, lines and stock movements are exactly as before');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
