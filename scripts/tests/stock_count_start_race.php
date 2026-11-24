<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * One open stock count per scope (CONCURRENCY_PLAN task 5, 2026-11-25). Usage: php scripts/tests/stock_count_start_race.php
 * Starting a count is refused when ANOTHER person already has a draft or submitted count with the same scope, items,
 * label (shift) and kind (opening or regular); the check runs under a start lock (GET_LOCK rh_stock_count_start_<DB>),
 * taken before the opening lock, and every lock is released on every path. Real PHP processes
 * (scripts/tests/stock_count_start_race_child.php) run the page's real start_count with normal COMMITTING connections.
 * Everything runs on THROWAWAY rows: categories "ZZSTART <run> ...", their items, a zz_scstart_ manager and counts in
 * those categories, deleted by id when the test ends (leftovers of an aborted run are removed first). No schema changes.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/admin/includes/permissions.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$run = strtoupper(bin2hex(random_bytes(3)));
$child = $root . '/scripts/tests/stock_count_start_race_child.php';
$made = ['ing' => [], 'counts' => [], 'users' => []];

function ss_cleanup(PDO $pdo): void {
    global $made;
    $ingIds = $pdo->query("SELECT id FROM stock_ingredients WHERE name LIKE 'ZZSTART %' AND category LIKE 'ZZSTART %'")->fetchAll(PDO::FETCH_COLUMN);
    $cntIds = $pdo->query("SELECT id FROM stock_counts WHERE (scope = 'category' AND scope_value LIKE 'ZZSTART %') OR reference LIKE '20991231-SS%'")->fetchAll(PDO::FETCH_COLUMN);
    $usrIds = $pdo->query("SELECT id FROM admin_users WHERE username LIKE 'zz\\_scstart\\_%' AND email LIKE '%@example.invalid'")->fetchAll(PDO::FETCH_COLUMN);
    $ing = implode(',', array_map('intval', array_unique(array_merge($ingIds, $made['ing']))));
    if ($ing !== '') {
        $cntIds = array_merge($cntIds, $pdo->query("SELECT DISTINCT count_id FROM stock_count_lines WHERE ingredient_id IN ($ing)")->fetchAll(PDO::FETCH_COLUMN));
    }
    $cnt = implode(',', array_map('intval', array_unique(array_merge($cntIds, $made['counts']))));
    $usr = implode(',', array_map('intval', array_unique(array_merge($usrIds, $made['users']))));
    if ($cnt !== '') {
        $pdo->exec("DELETE FROM stock_count_lines WHERE count_id IN ($cnt)");
        $pdo->exec("DELETE FROM stock_counts WHERE id IN ($cnt)");
    }
    if ($ing !== '') { $pdo->exec("DELETE FROM stock_ingredients WHERE id IN ($ing)"); }
    if ($usr !== '') {
        foreach (['admin_presence', 'admin_activity_log', 'user_permissions'] as $t) {
            try { $pdo->exec("DELETE FROM `$t` WHERE user_id IN ($usr)"); } catch (Throwable $e) { /* table may not exist */ }
        }
        $pdo->exec("DELETE FROM admin_users WHERE id IN ($usr)");
    }
    $made = ['ing' => [], 'counts' => [], 'users' => []];
}
register_shutdown_function(function () use ($pdo) { try { ss_cleanup($pdo); } catch (Throwable $e) { echo "CLEANUP ERROR: " . $e->getMessage() . "\n"; } });
ss_cleanup($pdo);
$snapBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_counts")->fetchColumn();

$adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'manager', 1)")
    ->execute(['zz_scstart_' . strtolower($run), 'zz_scstart_' . strtolower($run) . '@example.invalid', "Zz Start Tester $run"]);
$mgrId = (int)$pdo->lastInsertId(); $made['users'][] = $mgrId;
$mgrName = "Zz Start Tester $run";
ok('a second person (throwaway manager) can open the stock count page', rhCanOpenPage($mgrId, 'stock-count.php') && rhCanOpenPage($adminId, 'stock-count.php'));

$cat = "ZZSTART $run A"; $cat2 = "ZZSTART $run B"; $catEmpty = "ZZSTART $run EMPTY";
foreach ([$cat, $cat2] as $c) {
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, ?, 'kg', 4, 10)")->execute([$c . ' item', $c]);
    $made['ing'][] = (int)$pdo->lastInsertId();
}
$hasOpening = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_counts' AND COLUMN_NAME = 'is_opening'")->fetchColumn() > 0;

/** Wipe this run's counts (between cases). */
function ss_reset(PDO $pdo): void {
    global $made;
    $ids = $pdo->query("SELECT id FROM stock_counts WHERE (scope = 'category' AND scope_value LIKE 'ZZSTART %') OR reference LIKE '20991231-SS%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM stock_count_lines WHERE count_id IN ($in)");
        $pdo->exec("DELETE FROM stock_counts WHERE id IN ($in)");
    }
}
/** A count put straight in the table (as if someone started it earlier). */
function ss_count(PDO $pdo, string $tag, int $by, string $cat, string $status, ?string $shift, int $opening, int $agoSec): array {
    global $made, $run, $hasOpening;
    $ref = "20991231-SS$run$tag";
    $pdo->prepare("INSERT INTO stock_counts (reference, count_date, shift, scope, scope_value, status, counted_by" . ($hasOpening ? ', is_opening' : '') . ", created_at)
        VALUES (?, CURDATE(), ?, 'category', ?, ?, ?" . ($hasOpening ? ', ' . $opening : '') . ", NOW() - INTERVAL $agoSec SECOND)")
        ->execute([$ref, $shift, $cat, $status, $by]);
    $id = (int)$pdo->lastInsertId(); $made['counts'][] = $id;
    $c = $pdo->prepare("SELECT created_at FROM stock_counts WHERE id = ?"); $c->execute([$id]);
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', (string)$c->fetchColumn());
    return ['id' => $id, 'ref' => $ref, 'when' => $dt->format('H:i') . ' on ' . $dt->format('j M Y')];
}
function ss_n(PDO $pdo, string $cat): int { $s = $pdo->prepare("SELECT COUNT(*) FROM stock_counts WHERE scope = 'category' AND scope_value = ?"); $s->execute([$cat]); return (int)$s->fetchColumn(); }
function ss_start(int $uid, string $tag, float $startAt, string $cat, string $shift = '', bool $opening = false) {
    global $child;
    $cfg = base64_encode(json_encode(['user_id' => $uid, 'start_at' => $startAt, 'tag' => $tag, 'category' => $cat, 'shift' => $shift, 'opening' => $opening]));
    $p = proc_open([PHP_BINARY, $child, $cfg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$p, $pipes, $tag];
}
function ss_finish(array $h): array {
    [$p, $pipes, $tag] = $h;
    $text = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $res = ['tag' => $tag, 'flash' => '', 'err' => '', 'link' => '', 'start_held' => true, 'opening_held' => true, 'raw' => substr($text, -500), 'ran' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', $text, $m) && is_array($j = json_decode($m[1], true))) { $res = $j + ['raw' => substr($text, -300), 'ran' => true]; }
    return $res;
}
function ss_one(int $uid, string $cat, string $shift = '', bool $opening = false): array { return ss_finish(ss_start($uid, 'ONE', microtime(true) + 0.5, $cat, $shift, $opening)); }
function ss_free(array $r): bool { return $r['ran'] && empty($r['start_held']) && empty($r['opening_held']) && empty($r['in_txn']); }

echo "== 1. another person's open count of the same scope ==\n";
foreach (['draft', 'submitted'] as $st) {
    ss_reset($pdo);
    $o = ss_count($pdo, 'O' . strtoupper($st[0]), $mgrId, $cat, $st, null, 0, 300);
    $r = ss_one($adminId, $cat);
    ok("$st: the start is refused, nothing made", $r['ran'] && ss_n($pdo, $cat) === 1 && $r['flash'] === '', json_encode($r));
    ok("$st: the message names the person, the count reference and the time", strpos($r['err'], "$mgrName already started this count ({$o['ref']}) at {$o['when']}. Open it to carry on.") === 0, $r['err'] . ' | expected ' . "$mgrName ... {$o['ref']} ... {$o['when']}");
    ok("$st: the dialog links to that count (stock-count.php?id={$o['id']})", $r['link'] === 'stock-count.php?id=' . $o['id'], $r['link']);
    ok("$st: no lock is left held and no transaction open on the refusal path", ss_free($r), json_encode($r));
}

echo "== 2. cases that are still allowed ==\n";
ss_reset($pdo);
ss_count($pdo, 'AP', $mgrId, $cat, 'approved', null, 0, 300);
$r = ss_one($adminId, $cat);
ok('another person\'s APPROVED count does not block: a new count starts', $r['ran'] && ss_n($pdo, $cat) === 2 && stripos($r['flash'], 'started') !== false && $r['err'] === '', json_encode($r));
ok('success path: no lock left held', ss_free($r), json_encode($r));

ss_reset($pdo);
ss_count($pdo, 'SH', $mgrId, $cat, 'draft', 'Night', 0, 300);
$r = ss_one($adminId, $cat, '');
ok('a different label (shift Night vs none) does not block', $r['ran'] && ss_n($pdo, $cat) === 2 && $r['err'] === '', json_encode($r));
$r = ss_one($adminId, $cat, 'Morning');
ok('a different label (shift Morning vs Night) does not block', $r['ran'] && ss_n($pdo, $cat) === 3 && $r['err'] === '', json_encode($r));
ss_reset($pdo);
ss_count($pdo, 'SM', $mgrId, $cat, 'draft', 'Night', 0, 300);
$r = ss_one($adminId, $cat, 'Night');
ok('the SAME label (shift Night) by another person is refused', $r['ran'] && ss_n($pdo, $cat) === 1 && strpos($r['err'], "$mgrName already started this count") === 0, json_encode($r));

ss_reset($pdo);
ss_count($pdo, 'CT', $mgrId, $cat, 'draft', null, 0, 300);
$r = ss_one($adminId, $cat2);
ok('a different category does not block', $r['ran'] && ss_n($pdo, $cat2) === 1 && $r['err'] === '', json_encode($r));

if ($hasOpening) {
    ss_reset($pdo);
    ss_count($pdo, 'OP', $mgrId, $cat, 'draft', null, 1, 300);
    $r = ss_one($adminId, $cat, '', false);
    ok('another person\'s OPENING count does not block a regular count', $r['ran'] && ss_n($pdo, $cat) === 2 && $r['err'] === '', json_encode($r));
    ss_reset($pdo);
    ss_count($pdo, 'RG', $mgrId, $cat, 'draft', null, 0, 300);
    $r = ss_one($adminId, $cat, '', true);
    ok('another person\'s REGULAR count does not block an opening count', $r['ran'] && ss_n($pdo, $cat) === 2 && $r['err'] === '' && stripos($r['flash'], 'Opening stock count') !== false, json_encode($r));
    ok('opening success path: start lock and opening lock both released', ss_free($r), json_encode($r));
    ss_reset($pdo);
    ss_count($pdo, 'OO', $mgrId, $cat, 'draft', null, 1, 300);
    $r = ss_one($adminId, $cat, '', true);
    ok('another person\'s OPENING count blocks an opening count of the same scope', $r['ran'] && ss_n($pdo, $cat) === 1 && strpos($r['err'], "$mgrName already started this count") === 0, json_encode($r));
} else {
    echo "[SKIP] opening vs regular (no is_opening column)\n";
}

echo "== 3. the same person (unchanged behaviour) ==\n";
ss_reset($pdo);
$own = ss_count($pdo, 'OW', $adminId, $cat, 'draft', null, 0, 5);
$r = ss_one($adminId, $cat);
ok('own draft from 5 s ago (double click): opens it, nothing new made', $r['ran'] && ss_n($pdo, $cat) === 1 && stripos($r['flash'], 'already started') !== false && $r['err'] === '', json_encode($r));
ok('double-click path: no lock left held', ss_free($r), json_encode($r));
ss_reset($pdo);
ss_count($pdo, 'OL', $adminId, $cat, 'draft', null, 0, 120);
$r = ss_one($adminId, $cat);
ok('own draft from 2 minutes ago: a new count starts, as before', $r['ran'] && ss_n($pdo, $cat) === 2 && stripos($r['flash'], 'started') !== false && stripos($r['flash'], 'already') === false, json_encode($r));

echo "== 4. an error inside the start (no items) ==\n";
ss_reset($pdo);
$r = ss_one($adminId, $catEmpty, '', $hasOpening);
ok('empty category: refused with the plain message, nothing made', $r['ran'] && ss_n($pdo, $catEmpty) === 0 && stripos($r['err'], 'no stock items to count') !== false, json_encode($r));
ok('error path (inside the transaction' . ($hasOpening ? ', opening lock taken' : '') . '): every lock released, transaction rolled back', ss_free($r), json_encode($r));

echo "== 5. two people start the same count at the same moment ==\n";
for ($round = 1; $round <= 3; $round++) {
    ss_reset($pdo);
    $start = microtime(true) + 2.5; // PHP start-up and sign-in happen before this shared start line
    $a = ss_start($adminId, 'A', $start, $cat); $b = ss_start($mgrId, 'M', $start, $cat);
    $ra = ss_finish($a); $rb = ss_finish($b);
    ok("round $round: both processes ran the page", $ra['ran'] && $rb['ran'], json_encode([$ra['raw'], $rb['raw']]));
    ok("round $round: exactly ONE count was made", ss_n($pdo, $cat) === 1, 'n=' . ss_n($pdo, $cat));
    $w = $pdo->prepare("SELECT id, counted_by FROM stock_counts WHERE scope = 'category' AND scope_value = ?"); $w->execute([$cat]); $win = $w->fetch(PDO::FETCH_ASSOC) ?: ['id' => 0, 'counted_by' => 0];
    $loser = (int)$win['counted_by'] === $adminId ? $rb : $ra;
    $winnerName = (int)$win['counted_by'] === $adminId ? (string)$pdo->query("SELECT COALESCE(NULLIF(TRIM(full_name), ''), username) FROM admin_users WHERE id = $adminId")->fetchColumn() : $mgrName;
    ok("round $round: the other person is refused, naming the winner, with a link to that count", strpos($loser['err'], "$winnerName already started this count") === 0 && $loser['link'] === 'stock-count.php?id=' . (int)$win['id'], json_encode($loser));
    ok("round $round: neither process left a lock held", ss_free($ra) && ss_free($rb), json_encode([$ra, $rb]));
}
ss_reset($pdo);
$start = microtime(true) + 2.5;
$a = ss_start($adminId, 'A1', $start, $cat); $b = ss_start($adminId, 'A2', $start, $cat);
$ra = ss_finish($a); $rb = ss_finish($b);
ok('same person twice at the same moment: exactly one count, the other opens it', $ra['ran'] && $rb['ran'] && ss_n($pdo, $cat) === 1
    && ((stripos($ra['flash'], 'already started') !== false) xor (stripos($rb['flash'], 'already started') !== false)), json_encode([$ra, $rb]));

echo "== 6. the start lock is held by someone else ==\n";
$lockName = substr('rh_stock_count_start_' . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64);
$holder = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ss_reset($pdo);
ok('test holds the start lock', (int)$holder->query('SELECT GET_LOCK(' . $holder->quote($lockName) . ', 5)')->fetchColumn() === 1);
$h = ss_start($adminId, 'W', microtime(true) + 1.0, $cat);
sleep(5);
ok('while the lock is held the start waits: nothing made yet', ss_n($pdo, $cat) === 0);
$holder->query('SELECT RELEASE_LOCK(' . $holder->quote($lockName) . ')');
$r = ss_finish($h);
ok('after the lock is released the waiting start completes once', $r['ran'] && ss_n($pdo, $cat) === 1 && stripos($r['flash'], 'started') !== false, json_encode($r));
ss_reset($pdo);
ok('test holds the start lock again', (int)$holder->query('SELECT GET_LOCK(' . $holder->quote($lockName) . ', 5)')->fetchColumn() === 1);
$r = ss_finish(ss_start($adminId, 'T', microtime(true) + 0.5, $cat));
$holder->query('SELECT RELEASE_LOCK(' . $holder->quote($lockName) . ')');
ok('held for more than 10 s: the start gives up with a plain message, nothing made', $r['ran'] && ss_n($pdo, $cat) === 0 && stripos($r['err'], 'starting a stock count at this moment') !== false && $r['link'] === '', json_encode($r));
ok('timeout path: no lock left held', ss_free($r), json_encode($r));
ok('the start lock is free once everything has finished', (int)$holder->query('SELECT IS_FREE_LOCK(' . $holder->quote($lockName) . ')')->fetchColumn() === 1);
$holder = null;

// clean-up check
ss_cleanup($pdo);
$left = (int)$pdo->query("SELECT COUNT(*) FROM stock_counts WHERE (scope = 'category' AND scope_value LIKE 'ZZSTART %') OR reference LIKE '20991231-SS%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZSTART %'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE username LIKE 'zz\\_scstart\\_%'")->fetchColumn();
ok('everything the test created has been removed', $left === 0 && (int)$pdo->query("SELECT COUNT(*) FROM stock_counts")->fetchColumn() === $snapBefore, "left=$left");

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
