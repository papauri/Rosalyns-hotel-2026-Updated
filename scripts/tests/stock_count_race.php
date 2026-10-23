<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Two stock counts of the SAME item approved at the same moment (2026-10-23). Usage: php scripts/tests/stock_count_race.php
 * The stale-count guard in admin/stock-count.php refuses a count made out of date by another approval, but it reads the
 * other counts with a plain read: two approvals at the same instant could both pass it and post the same stock twice.
 * Approvals now take a named lock (GET_LOCK) first. Two real PHP processes (scripts/tests/stock_count_race_child.php)
 * run the page's real approve_count with normal COMMITTING connections, because a rolled-back harness cannot show a race.
 * Everything runs on a THROWAWAY ingredient and counts (reference 20991231-RC...), deleted by id when the test ends
 * (leftovers of an aborted run are removed first). No schema changes.
 *   1. both approve together (3 rounds)         -> exactly one posts, the other is refused with the "out of date" message, stock +5 once
 *   2. the lock is held by someone else         -> an approval WAITS (nothing posted), then completes once the lock is released
 *   3. a single approval on its own             -> posts normally
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$run = strtoupper(bin2hex(random_bytes(3)));
$child = $root . '/scripts/tests/stock_count_race_child.php';
$made = ['ing' => [], 'counts' => []];

function rc_cleanup(PDO $pdo): void {
    global $made;
    $ingIds = $pdo->query("SELECT id FROM stock_ingredients WHERE name LIKE 'ZZRACE %'")->fetchAll(PDO::FETCH_COLUMN);
    $cntIds = $pdo->query("SELECT id FROM stock_counts WHERE reference LIKE '20991231-RC%'")->fetchAll(PDO::FETCH_COLUMN);
    $ing = implode(',', array_map('intval', array_unique(array_merge($ingIds, $made['ing']))));
    $cnt = implode(',', array_map('intval', array_unique(array_merge($cntIds, $made['counts']))));
    if ($cnt !== '') {
        $pdo->exec("DELETE FROM stock_count_lines WHERE count_id IN ($cnt)");
        $pdo->exec("DELETE FROM stock_adjustments WHERE source_type = 'variance' AND source_id IN ($cnt)");
    }
    if ($ing !== '') {
        $tabs = $pdo->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'ingredient_id' AND TABLE_NAME LIKE 'stock\\_%' AND TABLE_NAME <> 'stock_ingredients'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tabs as $t) { $pdo->exec("DELETE FROM `$t` WHERE ingredient_id IN ($ing)"); }
        $pdo->exec("DELETE FROM stock_ingredients WHERE id IN ($ing)");
    }
    if ($cnt !== '') { $pdo->exec("DELETE FROM stock_counts WHERE id IN ($cnt)"); }
    $made = ['ing' => [], 'counts' => []];
}
register_shutdown_function(function () use ($pdo) { try { rc_cleanup($pdo); } catch (Throwable $e) { echo "CLEANUP ERROR: " . $e->getMessage() . "\n"; } });
rc_cleanup($pdo);

$adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$snapBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_counts")->fetchColumn();

/** A fresh item and two submitted counts of it, both showing 5 counted 3 minutes ago (snapshot 10 minutes ago). */
function rc_fixture(PDO $pdo, string $run, string $tag, int $adminId): array {
    global $made;
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'Pantry', 'kg', 0, 100)")->execute(["ZZRACE $run $tag"]);
    $ing = (int)$pdo->lastInsertId(); $made['ing'][] = $ing;
    $cnts = [];
    foreach (['X', 'Y'] as $k) {
        $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, is_opening, created_at, snapshot_at) VALUES (?, CURDATE(), 'full', 'submitted', ?, 0, NOW() - INTERVAL 10 MINUTE, NOW() - INTERVAL 10 MINUTE)")
            ->execute(["20991231-RC$run$tag$k", $adminId]);
        $cid = (int)$pdo->lastInsertId(); $made['counts'][] = $cid;
        $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost, reason_code, counted_at) VALUES (?,?,0,5,5,100,500,'', NOW() - INTERVAL 3 MINUTE)")->execute([$cid, $ing]);
        $cnts[$k] = $cid;
    }
    return ['ing' => $ing, 'X' => $cnts['X'], 'Y' => $cnts['Y']];
}
function rc_start(string $tag, int $countId, float $startAt): array {
    global $child;
    $cfg = base64_encode(json_encode(['count_id' => $countId, 'start_at' => $startAt, 'tag' => $tag]));
    $p = proc_open([PHP_BINARY, $child, $cfg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$p, $pipes, $tag];
}
function rc_finish(array $h): array {
    [$p, $pipes, $tag] = $h;
    $text = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $res = ['tag' => $tag, 'flash' => '', 'err' => '', 'alerts' => [], 'raw' => substr($text, -400), 'ran' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', $text, $m) && is_array($j = json_decode($m[1], true))) { $res = $j + ['raw' => '', 'ran' => true]; }
    return $res;
}
function rc_status(PDO $pdo, int $id): string { $s = $pdo->prepare("SELECT status FROM stock_counts WHERE id = ?"); $s->execute([$id]); return (string)$s->fetchColumn(); }
function rc_qty(PDO $pdo, int $ing): float { $s = $pdo->prepare("SELECT current_quantity FROM stock_ingredients WHERE id = ?"); $s->execute([$ing]); return (float)$s->fetchColumn(); }
function rc_adj(PDO $pdo, int $ing): int { $s = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments WHERE ingredient_id = ? AND source_type = 'variance'"); $s->execute([$ing]); return (int)$s->fetchColumn(); }

echo "== 1. two counts of the same item approved at the same moment ==\n";
for ($round = 1; $round <= 3; $round++) {
    $f = rc_fixture($pdo, $run, "R$round", $adminId);
    $start = microtime(true) + 2.5; // PHP start-up and sign-in happen before this shared start line
    $a = rc_start('X', $f['X'], $start); $b = rc_start('Y', $f['Y'], $start);
    $ra = rc_finish($a); $rb = rc_finish($b);
    $sx = rc_status($pdo, $f['X']); $sy = rc_status($pdo, $f['Y']);
    ok("round $round: both processes ran the page", $ra['ran'] && $rb['ran'], json_encode([$ra['raw'], $rb['raw']]));
    ok("round $round: exactly ONE count was approved, the other stays waiting", (($sx === 'approved') xor ($sy === 'approved')) && ($sx === 'submitted' || $sy === 'submitted'), "X=$sx Y=$sy");
    ok("round $round: the stock was posted once (+5, one adjustment), not twice", abs(rc_qty($pdo, $f['ing']) - 5.0) < 0.0005 && rc_adj($pdo, $f['ing']) === 1, 'qty=' . rc_qty($pdo, $f['ing']) . ' adj=' . rc_adj($pdo, $f['ing']));
    $loser = $sx === 'approved' ? $rb : $ra;
    ok("round $round: the other approval gets the plain 'out of date' message (no database error)", !empty($loser['stale']) || stripos(implode(' ', array_merge($loser['alerts'], [$loser['err']])), 'out of date') !== false, json_encode($loser));
}

echo "== 2. the approval lock is held by someone else ==\n";
$f = rc_fixture($pdo, $run, 'LK', $adminId);
$lockName = substr('rh_stock_count_approve_' . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64);
$holder = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$got = (int)$holder->query('SELECT GET_LOCK(' . $holder->quote($lockName) . ', 5)')->fetchColumn();
ok('test holds the approval lock', $got === 1);
$h = rc_start('X', $f['X'], microtime(true) + 1.0);
sleep(6); // start-up + 1s; the approval must still be waiting for the lock
ok('while the lock is held the approval waits: nothing posted yet', rc_status($pdo, $f['X']) === 'submitted' && abs(rc_qty($pdo, $f['ing'])) < 0.0005);
$holder->query('SELECT RELEASE_LOCK(' . $holder->quote($lockName) . ')');
$r = rc_finish($h);
ok('after the lock is released the waiting approval completes and posts once', $r['ran'] && rc_status($pdo, $f['X']) === 'approved' && abs(rc_qty($pdo, $f['ing']) - 5.0) < 0.0005, json_encode([$r['alerts'], $r['err'], $r['raw']]));
$free = (int)$holder->query('SELECT IS_FREE_LOCK(' . $holder->quote($lockName) . ')')->fetchColumn();
ok('the approval lock is released again once the approval has finished', $free === 1);

echo "== 3. a single approval on its own ==\n";
$f = rc_fixture($pdo, $run, 'ONE', $adminId);
$r = rc_finish(rc_start('X', $f['X'], microtime(true) + 1.0));
ok('a single approval posts normally (+5) and says so', $r['ran'] && rc_status($pdo, $f['X']) === 'approved' && abs(rc_qty($pdo, $f['ing']) - 5.0) < 0.0005 && stripos($r['flash'], 'approved') !== false, json_encode([$r['flash'], $r['err'], $r['alerts'], $r['raw']]));

// clean-up check
rc_cleanup($pdo);
$left = (int)$pdo->query("SELECT COUNT(*) FROM stock_counts WHERE reference LIKE '20991231-RC%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZRACE %'")->fetchColumn();
ok('everything the test created has been removed', $left === 0 && (int)$pdo->query("SELECT COUNT(*) FROM stock_counts")->fetchColumn() === $snapBefore, "left=$left");

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
