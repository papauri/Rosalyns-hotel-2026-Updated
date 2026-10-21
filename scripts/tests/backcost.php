<?php
/**
 * Late cost test: rh_stock_backcost_settled_deficit() (config/database.php).
 * Usage: php scripts/tests/backcost.php
 *
 * Covers: a deficit of 6 settled by 4 costs the OLDEST sales first (FIFO) at the receipt's unit cost; a second
 * receipt settling the remaining 2 costs only the lines still open; running the same settlement again costs nothing
 * twice; a deficit that came from wastage (not a sale) costs no sale; a failure part-way applies nothing at all.
 * All database work is inside ONE transaction that is rolled back.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';

$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; } }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function near($a, float $b): bool { return abs((float)$a - $b) <= 0.0005; }

$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1");
if (!$adminId) { echo "FAIL Fixture: an admin user exists\n"; exit(1); }
$run = strtoupper(bin2hex(random_bytes(3)));

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, cost_per_unit, current_quantity) VALUES (?, 'ZZT', 'kg', 0, 0)")->execute(['Backcost ' . $run]);
    $ing = (int)$pdo->lastInsertId();

    // One order + one line + one unallocated, zero-cost POS deduction of $qty.
    $sale = static function (string $tag, float $qty) use ($pdo, $ing, $adminId, $run): array {
        $ref = 'ZZB' . $run . $tag;
        $pdo->prepare("INSERT INTO stock_orders (reference, order_type, table_number, customer_name, status, total_amount, subtotal, kitchen_status, fired_at, created_by, created_at)
                       VALUES (?, 'dine_in', 'ZZB', 'Backcost test', 'paid', 100, 100, 'new', NOW(), ?, NOW())")->execute([$ref, $adminId]);
        $oid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status)
                       VALUES (?, 1, 'food', 'ZZB item', 1, 100, 100, 'kitchen', 'served')")->execute([$oid]);
        $lid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at)
                       VALUES (?, ?, 'Backcost test sale', 'pos_order', ?, 0, ?, NOW())")->execute([$ing, -$qty, $lid, $adminId]);
        return ['order' => $oid, 'line' => $lid];
    };
    $cost = static fn(int $oid): float => (float)col($pdo, "SELECT total_cost FROM stock_orders WHERE id = ?", [$oid]);
    $audits = static fn(): int => (int)col($pdo, "SELECT COUNT(*) FROM stock_order_audit au INNER JOIN stock_orders o ON o.id = au.order_id WHERE au.event = 'cost_filled' AND o.reference LIKE ?", ['ZZB' . $run . '%']);

    // Three sales sold with nothing in stock: A (2), B (3), C (1) = deficit 6.
    $A = $sale('A', 2.0); $B = $sale('B', 3.0); $C = $sale('C', 1.0);

    // Receipt 1: 4 delivered at 10 settle 4 of the deficit of 6 -> oldest sales first: A 2, B 2.
    $r1 = rh_stock_backcost_settled_deficit($pdo, $ing, 6.0, 4.0, 10.0, $adminId, 'test receipt 1');
    check($r1['orders'] === 2 && near($r1['amount'], 40.0), 'Receipt 1: two orders costed, 40 in total', json_encode($r1));
    check(near($cost($A['order']), 20.0) && near($cost($B['order']), 20.0) && near($cost($C['order']), 0.0), 'Receipt 1: FIFO, A=20 (2x10), B=20 (2x10), C untouched', json_encode([$cost($A['order']), $cost($B['order']), $cost($C['order'])]));
    check($audits() === 2, 'Receipt 1: one audit row per costed order');

    // Receipt 2: 2 delivered at 20 settle the remaining 2 (B has 1 left, C has 1) and nothing already costed.
    $r2 = rh_stock_backcost_settled_deficit($pdo, $ing, 2.0, 2.0, 20.0, $adminId, 'test receipt 2');
    check($r2['orders'] === 2 && near($r2['amount'], 40.0), 'Receipt 2: only the two lines still open are costed (40)', json_encode($r2));
    check(near($cost($A['order']), 20.0), 'Receipt 2: A (fully costed before) is not touched', (string)$cost($A['order']));
    check(near($cost($B['order']), 40.0) && near($cost($C['order']), 20.0), 'Receipt 2: B gets its last 1 at 20 (total 40), C gets 1 at 20', json_encode([$cost($B['order']), $cost($C['order'])]));

    // Same settlement again, and a bigger one: nothing is left to cost.
    $before = [$cost($A['order']), $cost($B['order']), $cost($C['order'])];
    $r3 = rh_stock_backcost_settled_deficit($pdo, $ing, 2.0, 2.0, 20.0, $adminId, 'test re-run');
    $r4 = rh_stock_backcost_settled_deficit($pdo, $ing, 6.0, 6.0, 99.0, $adminId, 'test big re-run');
    check($r3['orders'] === 0 && $r4['orders'] === 0 && $before === [$cost($A['order']), $cost($B['order']), $cost($C['order'])], 'Re-run: nothing is costed twice', json_encode([$r3, $r4]));
    check($audits() === 4, 'Re-run: no extra audit rows');

    // A deficit from wastage (no sale involved): every sale is already costed, so none is costed again.
    $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at)
                   VALUES (?, -3, 'Backcost test wastage', 'wastage', NULL, 0, ?, NOW())")->execute([$ing, $adminId]);
    $r5 = rh_stock_backcost_settled_deficit($pdo, $ing, 3.0, 3.0, 50.0, $adminId, 'test wastage deficit');
    check($r5['orders'] === 0 && near($cost($A['order']) + $cost($B['order']) + $cost($C['order']), 80.0), 'Wastage deficit: no sale is costed', json_encode($r5));

    // A new unallocated sale D (4) after all that: only D is costed, and only for what the receipt settled (1 of 4).
    $D = $sale('D', 4.0);
    $r6 = rh_stock_backcost_settled_deficit($pdo, $ing, 4.0, 1.0, 5.0, $adminId, 'test later sale');
    check($r6['orders'] === 1 && near($cost($D['order']), 5.0) && near($cost($A['order']), 20.0), 'Later sale: only the new sale is costed, at the settled quantity (1 x 5)', json_encode($r6));

    // Never more than the settled quantity, whatever the caller passes as the receipt size.
    $r7 = rh_stock_backcost_settled_deficit($pdo, $ing, 1.0, 9.0, 5.0, $adminId, 'test over-settle');
    check(near($cost($D['order']), 10.0) && near($r7['amount'], 5.0), 'Settled quantity is capped at the deficit (1 x 5, not 9 x 5)', json_encode([$r7, $cost($D['order'])]));

    // Failure part-way: the audit insert is made to fail (over-long ip value) after the first cost update -> nothing applied.
    $E = $sale('E', 1.0); $F = $sale('F', 1.0);
    $costE = $cost($E['order']); $costF = $cost($F['order']); $auditsBefore = $audits();
    $realIp = $_SERVER['REMOTE_ADDR'];
    $_SERVER['REMOTE_ADDR'] = str_repeat('9', 400);   // too long for the audit ip column: the insert fails
    $rf = rh_stock_backcost_settled_deficit($pdo, $ing, 2.0, 2.0, 7.0, $adminId, 'test failure');
    $_SERVER['REMOTE_ADDR'] = $realIp;
    if ($audits() === $auditsBefore + 2) {
        echo "SKIP Failure: this database truncates over-long values, so a failure cannot be provoked this way\n";
    } else {
        check($rf['orders'] === 0 && near($cost($E['order']), $costE) && near($cost($F['order']), $costF) && $audits() === $auditsBefore, 'Failure part-way: no cost changed and no audit row left behind', json_encode($rf));
    }
    check($pdo->inTransaction(), 'Failure: the surrounding transaction (the delivery) is still open and usable');
} catch (Throwable $e) {
    $fail++;
    echo 'FAIL exception: ' . $e->getMessage() . "\n";
}
if ($pdo->inTransaction()) { $pdo->rollBack(); }
check((int)col($pdo, "SELECT COUNT(*) FROM stock_orders WHERE reference LIKE ?", ['ZZB' . $run . '%']) === 0, 'Rolled back: no test rows left behind');

// --- The two other places that settle a deficit, through the REAL admin pages (page_harness.php, rolled back) -------
$harness = __DIR__ . '/page_harness.php';
$pageRun = static function (string $do, string $cost) use ($harness): array {
    $hRun = strtoupper(bin2hex(random_bytes(3)));
    $verify = [
        "SELECT total_cost AS c FROM stock_orders WHERE reference = 'ZZB{$hRun}P'",
        "SELECT COUNT(*) AS n FROM stock_order_audit au INNER JOIN stock_orders o ON o.id = au.order_id WHERE au.event = 'cost_filled' AND o.reference = 'ZZB{$hRun}P'",
        "SELECT current_quantity AS q FROM stock_ingredients WHERE name = 'ZZB {$hRun} Item'",
    ];
    $cfg = base64_encode(json_encode(['post' => ['run' => $hRun, 'do' => $do, 'cost' => $cost], 'verify' => $verify]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/backcost_child.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr($out, -600), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $res = $x; } }
    return $res;
};
$val = static fn(array $r, int $i, string $k) => (float)(($r['verify'][$i][0] ?? [])[$k] ?? -1);
$clean = static fn(array $r) => !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']);

$r = $pageRun('count', 'yes');
check($clean($r), 'Stock count page: ran and rolled back, nothing leaked', json_encode($r['raw'] ?? $r));
check(near($val($r, 2, 'q'), 2.0) && near($val($r, 0, 'c'), 600.0) && near($val($r, 1, 'n'), 1.0), 'Stock count surplus settling a deficit of 3 costs the sale at the line unit cost (3 x 200 = 600) with one audit row', json_encode($r['verify'] ?? $r));
$r = $pageRun('adjust', 'yes');
check($clean($r), 'Manual adjust page: ran and rolled back, nothing leaked', json_encode($r['raw'] ?? $r));
check(near($val($r, 2, 'q'), 2.0) && near($val($r, 0, 'c'), 600.0) && near($val($r, 1, 'n'), 1.0), 'Manual "adjust add" settling a deficit of 3 costs the sale at the ingredient cost (3 x 200 = 600) with one audit row', json_encode($r['verify'] ?? $r));
$r = $pageRun('adjust', 'none');
check($clean($r) && near($val($r, 2, 'q'), 2.0) && near($val($r, 0, 'c'), 0.0) && near($val($r, 1, 'n'), 0.0), 'Manual "adjust add" with no cost on the ingredient: stock settles, the sale stays at 0 (nothing guessed)', json_encode($r['verify'] ?? $r));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
