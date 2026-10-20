<?php
/**
 * Stock loss-prevention test (includes/stock-loss.php).
 * Usage: php scripts/tests/stock_loss.php
 *
 * Covers: the one variance alert rule (orange / red / none), the manual-additions list (adds only, last 30 days,
 * biggest cost first, who / when / qty / cost / reason), the count-reminder day maths, and the "Needs review"
 * list (manual adjustments, batch write-offs, big count shortages; plain wastage and small shortages are not
 * flagged) with per-person dismissal. All database work is inside ONE transaction that is rolled back.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/stock-loss.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

/* ---- 1. The alert rule (alert 2%, approval 10%, ignore under 5,000) ---- */
$lv = static fn(float $var, float $cost, float $base) => rh_stock_alert_level($var, $cost, $base, 2.0, 10.0, 5000.0);
check($lv(-1, -6000, 100) === 'none', 'Rule: 1% shortage (under the 2% alert level) is not flagged');
check($lv(-2, -6000, 100) === 'alert', 'Rule: exactly 2% shortage is over the alert level (orange)');
check($lv(-5, -6000, 100) === 'alert', 'Rule: 5% shortage is orange');
check($lv(-10, -6000, 100) === 'block', 'Rule: exactly 10% shortage is red (needs approval)');
check($lv(-30, -6000, 100) === 'block', 'Rule: 30% shortage is red');
check($lv(-30, -4999.99, 100) === 'none', 'Rule: a shortage worth less than the minimum is never flagged');
check($lv(5, 6000, 100) === 'none', 'Rule: a surplus is never flagged');
check($lv(0, 0, 100) === 'none', 'Rule: a balanced line is not flagged');
check($lv(-1, -6000, 0) === 'block', 'Rule: shortage with nothing expected counts as 100%');
check(rh_stock_alert_level(-15, -6000, 100, 20.0, 10.0, 5000.0) === 'block', 'Rule: red is still red when the alert level is set above the approval level');
check(rh_stock_alert_level(-1, -6000, 100, 0.0, 10.0, 5000.0) === 'alert', 'Rule: alert level 0 flags any shortage worth the minimum');
check(rh_stock_alert_level(-2.0, -6000, 100.0, 2.0, 10.0, 5000.0) === rh_stock_alert_level(-0.02 * 100, -6000, 100.0, 2.0, 10.0, 5000.0), 'Rule: same answer however the % is reached (tolerance)');

/* ---- 2. Count reminder day maths ---- */
$now = new DateTimeImmutable('2031-03-20 12:00:00');
$rem = static fn(int $d, ?string $last) => rh_stock_count_reminder_for($d, $last, $now);
check($rem(0, '2031-01-01 00:00:00') === null, 'Reminder: 0 = off');
check($rem(7, '2031-03-14 12:00:01') === null, 'Reminder: 5 days 23h 59m since the last count is not yet 7 days');
check(($r6 = $rem(7, '2031-03-13 12:00:00')) !== null && $r6['days'] === 7, 'Reminder: exactly 7 days shows');
check($r6 !== null && $r6['text'] === 'No stock count approved for 7 days', 'Reminder: wording');
check(($r1 = $rem(1, '2031-03-19 11:00:00')) !== null && $r1['text'] === 'No stock count approved for 1 day', 'Reminder: singular "1 day"');
check(($rn = $rem(7, null)) !== null && $rn['days'] === null && stripos($rn['text'], 'No stock count has been approved yet') === 0, 'Reminder: never counted shows the "approved yet" wording');
check(rh_stock_days_since('2031-03-20 11:59:59', $now) === 0, 'Reminder: under a day = 0 days');
check(rh_stock_days_since('2031-03-21 12:00:00', $now) === 0, 'Reminder: a future time never goes negative');
check(strpos(rh_stock_count_reminder_html($r6, 'stock-count.php'), 'start one') !== false, 'Reminder: notice has the "start one" link');
check(rh_stock_count_reminder_html(null) === '', 'Reminder: nothing shown when not due');
$live = rh_stock_count_reminder($pdo);
check($live === null || isset($live['text']), 'Reminder: live lookup runs and returns nothing or a notice');

/* ---- 3. Database parts (rolled back) ---- */
$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1");
if (!$adminId) { bad('Fixture: an admin user exists'); echo "\n$pass passed, $fail failed\n"; exit(1); }
$adminName = (string)col($pdo, "SELECT full_name FROM admin_users WHERE id = ?", [$adminId]);
$run = strtoupper(bin2hex(random_bytes(3)));
$fakeUser = 2000000000 + random_int(1, 99999);

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, cost_per_unit, current_quantity) VALUES (?, 'ZZT', 'kg', 1000, 50)")->execute(['Loss test ' . $run]);
    $ing = (int)$pdo->lastInsertId();
    $adj = function (float $qty, string $type, string $reason, string $when = 'NOW()', ?int $src = null) use ($pdo, $ing, $adminId): int {
        $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at) VALUES (?, ?, ?, ?, ?, 1000, ?, {$when})")
            ->execute([$ing, $qty, $reason, $type, $src, $adminId]);
        return (int)$pdo->lastInsertId();
    };

    // Manual additions list
    $addBig   = $adj(900, 'manual', 'Test big add ' . $run);
    $addSmall = $adj(3, 'manual', 'Test small add ' . $run);
    $addOld   = $adj(800, 'manual', 'Test old add ' . $run, 'DATE_SUB(NOW(), INTERVAL 31 DAY)');
    $takeOff  = $adj(-5, 'manual', 'Test take off ' . $run);
    $sale     = $adj(-2, 'pos_order', 'Test sale ' . $run);
    $list = rh_stock_manual_additions($pdo, 30, 500);
    $ids = array_map(static fn($r) => (int)$r['id'], $list);
    check(in_array($addBig, $ids, true) && in_array($addSmall, $ids, true), 'Additions: manual adds in the last 30 days are listed');
    check(!in_array($addOld, $ids, true), 'Additions: an add older than 30 days is not listed');
    check(!in_array($takeOff, $ids, true) && !in_array($sale, $ids, true), 'Additions: subtractions and sales are not listed');
    check(array_search($addBig, $ids, true) < array_search($addSmall, $ids, true), 'Additions: biggest cost first');
    $row = $list[array_search($addBig, $ids, true)] ?? [];
    check(($row['who'] ?? null) === $adminName && (float)($row['qty'] ?? 0) === 900.0 && abs((float)($row['total_cost'] ?? 0) - 900000.0) <= BALANCE_TOLERANCE
        && ($row['reason'] ?? '') === 'Test big add ' . $run && !empty($row['created_at']), 'Additions: who, when, qty, cost and reason are returned');
    check(count(rh_stock_manual_additions($pdo, 30, 1)) === 1, 'Additions: the limit is respected');

    // Needs review: write-off of a batch, plain wastage, and counts
    $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status, created_by) VALUES (?, ?, 10, 0, 1000, CURDATE(), 'wasted', ?)")->execute([$ing, 'ZZT-' . $run, $adminId]);
    $batch = (int)$pdo->lastInsertId();
    $wBatch = rh_insert_batch_wastage_row($pdo, $ing, $batch, 10, 1000, 'Test write-off ' . $run, $adminId);
    $adjWriteOff = $adj(-10, 'wastage', 'Test write-off ' . $run, 'NOW()', $wBatch);
    $pdo->prepare("INSERT INTO stock_wastage (ingredient_id, batch_id, quantity, cost_per_unit, wastage_cost, reason, recorded_date, recorded_by) VALUES (?, NULL, 1, 1000, 1000, ?, CURDATE(), ?)")->execute([$ing, 'Test plain wastage ' . $run, $adminId]);
    $adjPlain = $adj(-1, 'wastage', 'Test plain wastage ' . $run, 'NOW()', (int)$pdo->lastInsertId());

    $mkCount = function (string $ref, float $variance, float $varCost, float $actual) use ($pdo, $ing, $adminId): int {
        $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, approved_by, approved_at) VALUES (?, CURDATE(), 'spot', 'approved', ?, ?, NOW())")->execute([$ref, $adminId, $adminId]);
        $cid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost) VALUES (?, ?, ?, ?, ?, 1000, ?)")
            ->execute([$cid, $ing, $actual - $variance, $actual, $variance, $varCost]);
        return $cid;
    };
    $cntBig   = $mkCount('ZZT-B-' . $run, -20, -20000, 80);   // 20% short, worth 20,000
    $cntSmall = $mkCount('ZZT-S-' . $run, -1, -1000, 99);     // 1% short, worth 1,000
    $cntMid   = $mkCount('ZZT-M-' . $run, -5, -5000, 95);     // 5% short: over the alert level, under approval level

    $items = rh_stock_review_items($pdo, 10.0, 5000.0, 'MWK', 7);
    $keys = array_column($items, 'key');
    check(in_array('adj:' . $addBig, $keys, true) && in_array('adj:' . $takeOff, $keys, true), 'Review: manual adjustments (add and take off) are listed');
    check(in_array('adj:' . $adjWriteOff, $keys, true), 'Review: a batch write-off is listed');
    check(!in_array('adj:' . $adjPlain, $keys, true) && !in_array('adj:' . $sale, $keys, true), 'Review: ordinary wastage and sales are not listed');
    check(!in_array('adj:' . $addOld, $keys, true), 'Review: items older than 7 days are not listed');
    check(in_array('cnt:' . $cntBig, $keys, true), 'Review: a count approved with a shortage over the approval level is listed');
    check(!in_array('cnt:' . $cntSmall, $keys, true) && !in_array('cnt:' . $cntMid, $keys, true), 'Review: small shortages and shortages under the approval level are not listed');
    $bigItem = $items[array_search('cnt:' . $cntBig, $keys, true)] ?? [];
    check(strpos($bigItem['text'] ?? '', '1 shortage line over the approval level') !== false && strpos($bigItem['text'] ?? '', 'MWK 20,000.00') !== false && ($bigItem['who'] ?? '') === $adminName, 'Review: count item states lines, cost and who approved');
    check(strpos($items[array_search('adj:' . $addBig, $keys, true)]['text'] ?? '', 'Test big add ' . $run) !== false, 'Review: manual item shows the reason');

    // Per-person dismissal
    check(rh_stock_review_dismissed($fakeUser) === [], 'Dismiss: nothing dismissed to start with');
    rh_stock_review_dismiss($fakeUser, ['adj:' . $addBig, 'bad key', 'cnt:' . $cntBig, 'adj:' . $addBig]);
    $d = rh_stock_review_dismissed($fakeUser);
    check(count($d) === 2 && in_array('adj:' . $addBig, $d, true) && in_array('cnt:' . $cntBig, $d, true), 'Dismiss: valid keys saved once, junk ignored');
    check(rh_stock_review_dismissed($fakeUser + 1) === [], 'Dismiss: another person still sees the items');
} catch (Throwable $e) {
    bad('Database tests ran', $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    // updateSetting/getSetting cached the throw-away dismissed list for the fake user; remove it.
    foreach ([$fakeUser, $fakeUser + 1] as $u) { deleteCache('setting_' . rh_stock_review_dismissed_key($u)); }
}
check((int)col($pdo, "SELECT COUNT(*) FROM stock_adjustments WHERE reason LIKE ?", ['%' . $run]) === 0, 'Cleanup: nothing left behind');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
