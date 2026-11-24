<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Stale-edit helper (includes/stale-edit.php, CONCURRENCY_PLAN task 1). Pure hash checks, then DB checks on ONE existing
 * food_menu (or, when that is empty, drink_menu) row inside ONE transaction that is always rolled back (no DDL, nothing committed). Refuses a LIVE database.
 * Usage (repo root): php scripts/tests/stale_edit_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function throws(callable $f, string $class): bool
{
    try { $f(); } catch (Throwable $e) { return $e instanceof $class; }
    return false;
}

require_once $root . '/includes/stale-edit.php';

/* ---- Pure checks (no database) ---- */
$cols = ['item_name', 'price', 'description'];
$row = ['id' => 7, 'item_name' => 'Chambo', 'price' => '12500.00', 'description' => 'Grilled', 'display_order' => 3];
$h = rh_stale_hash($row, $cols);
check(preg_match('/^[a-f0-9]{32}$/', $h) === 1, 'hash is a 32-char md5');
check(rh_stale_hash($row, $cols) === $h, 'same row gives the same hash');
check(rh_stale_hash(array_reverse($row, true), $cols) === $h, 'row key order does not matter, only the column list order');
check(rh_stale_hash(['display_order' => 99] + $row, $cols) === $h, 'unhashed column change keeps the hash');
check(rh_stale_hash(['price' => '12500.01'] + $row, $cols) !== $h, 'hashed column change alters the hash');
check(rh_stale_hash(['a' => 5], ['a']) === rh_stale_hash(['a' => '5'], ['a']), 'int 5 and string "5" hash the same (PDO read paths differ)');
check(rh_stale_hash(['a' => null], ['a']) !== rh_stale_hash(['a' => ''], ['a']), 'NULL and empty string hash differently');
check(rh_stale_hash(['a' => 'x|y', 'b' => 'z'], ['a', 'b']) !== rh_stale_hash(['a' => 'x', 'b' => 'y|z'], ['a', 'b']), 'no separator collision');
check(rh_stale_hash(['a' => "\xff\xfe"], ['a']) !== rh_stale_hash(['a' => "\xfe\xff"], ['a']), 'invalid UTF-8 values still hash distinctly');
check(throws(function () use ($row) { rh_stale_hash($row, ['item_name', 'no_such_col']); }, 'InvalidArgumentException'), 'column missing from the row throws');
check(throws(function () use ($row) { rh_stale_hash($row, []); }, 'InvalidArgumentException'), 'empty column list throws');
check(throws(function () use ($row) { rh_stale_hash($row, ['price', 'price']); }, 'InvalidArgumentException'), 'duplicate column throws');
$f = rh_stale_field('"><script>');
check(strpos($f, 'name="_row_hash"') !== false && strpos($f, '<script>') === false, 'hidden field is named _row_hash and escaped');
$_POST = [];
check(rh_stale_posted() === '', 'posted: absent -> empty');
$_POST = ['_row_hash' => ['x']];
check(rh_stale_posted() === '', 'posted: array -> empty');
$_POST = ['_row_hash' => 'not-a-hash'];
check(rh_stale_posted() === '', 'posted: non-md5 text -> empty');
$_POST = ['_row_hash' => $h];
check(rh_stale_posted() === $h, 'posted: valid md5 returned');
$_POST = [];

/* ---- Database checks (Liwonde only, one rolled-back transaction) ---- */
require_once $root . '/config/database.php';
$liveGuard = $root . '/.claude/LIVE_TARGETS';
if (!defined('DB_NAME') || (string)DB_NAME === '') {
    exit("Refused: no database name.\n");
}
if (is_file($liveGuard) && stripos((string)file_get_contents($liveGuard), (string)DB_NAME) !== false) {
    exit("Refused: " . DB_NAME . " is listed in .claude/LIVE_TARGETS.\n");
}

$tbl = ''; $id = 0;
foreach (['food_menu', 'drink_menu'] as $t) { // same menu columns; use whichever has a row
    $id = (int)$pdo->query('SELECT id FROM `' . $t . '` ORDER BY id LIMIT 1')->fetchColumn();
    if ($id > 0) { $tbl = $t; break; }
}
if ($id <= 0) {
    bad('food_menu or drink_menu has a row to test on');
    echo "\n$pass passed, $fail failed\n";
    exit(1);
}
$load = function () use ($pdo, $id, $tbl): array {
    $st = $pdo->prepare('SELECT * FROM `' . $tbl . '` WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
};
$before = $load();
$hash = rh_stale_hash($before, $cols);

check(throws(function () use ($pdo, $tbl, $id, $cols, $hash) { rh_stale_verify($pdo, $tbl, $id, $cols, $hash); }, 'LogicException'), 'verify outside a transaction throws');

$pdo->beginTransaction();
try {
    $v = rh_stale_verify($pdo, $tbl, $id, $cols, $hash);
    check($v['ok'] === true && $v['error'] === '' && is_array($v['row']) && rh_stale_hash($v['row'], $cols) === $hash, 'unchanged row: verify ok, locked row returned');

    $v = rh_stale_verify($pdo, $tbl, $id, $cols, '');
    check($v['ok'] === false && strpos($v['error'], 'opened before a change to how saving works') !== false, 'missing posted hash refused', $v['error']);
    $v = rh_stale_verify($pdo, $tbl, $id, $cols, strtoupper($hash));
    check($v['ok'] === false, 'malformed (upper-case) posted hash refused');

    check(throws(function () use ($pdo, $id, $cols, $hash) { rh_stale_verify($pdo, 'admin_users', $id, $cols, $hash); }, 'InvalidArgumentException'), 'table outside the allow-list throws');
    check(throws(function () use ($pdo, $tbl, $id, $hash) { rh_stale_verify($pdo, $tbl, $id, ['price` FROM admin_users -- '], $hash); }, 'InvalidArgumentException'), 'injected column name throws');
    check(throws(function () use ($pdo, $tbl, $id, $hash) { rh_stale_verify($pdo, $tbl, $id, [], $hash); }, 'InvalidArgumentException'), 'empty column list throws');
    check(throws(function () use ($pdo, $tbl, $cols, $hash) { rh_stale_verify($pdo, $tbl, 0, $cols, $hash); }, 'InvalidArgumentException'), 'id 0 throws');
    check($pdo->inTransaction(), 'the caller transaction is still open after refusals and throws');

    $maxId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM `' . $tbl . '`')->fetchColumn();
    $v = rh_stale_verify($pdo, $tbl, $maxId + 1000, $cols, $hash);
    check($v['ok'] === false && $v['row'] === null && strpos($v['error'], 'no longer exists') !== false, 'missing record refused');

    foreach (rh_stale_tables() as $t) {
        $tid = (int)$pdo->query('SELECT COALESCE(MIN(id), 1) FROM `' . $t . '`')->fetchColumn();
        try {
            $vt = rh_stale_verify($pdo, $t, max(1, $tid), ['id'], md5('x'));
            check($vt['ok'] === false, "allow-listed table $t reads by id");
        } catch (Throwable $e) {
            bad("allow-listed table $t reads by id", $e->getMessage());
        }
    }

    // Unhashed column changed by "someone else": still ok.
    $pdo->prepare('UPDATE `' . $tbl . '` SET display_order = display_order + 1 WHERE id = ?')->execute([$id]);
    $v = rh_stale_verify($pdo, $tbl, $id, $cols, $hash);
    check($v['ok'] === true, 'unhashed column changed in the DB: verify still ok');

    // Hashed column changed by "someone else": the canonical caller pattern writes nothing.
    $pdo->prepare('UPDATE `' . $tbl . '` SET price = price + 1 WHERE id = ?')->execute([$id]);
    $pdo->exec('SAVEPOINT stale_caller');
    $v = rh_stale_verify($pdo, $tbl, $id, $cols, $hash);
    if ($v['ok']) {
        $pdo->prepare('UPDATE `' . $tbl . "` SET item_name = 'ZZ stale-edit caller write' WHERE id = ?")->execute([$id]);
    } else {
        $pdo->exec('ROLLBACK TO SAVEPOINT stale_caller');
    }
    $now = $load();
    check($v['ok'] === false && strpos($v['error'], 'changed by someone else') !== false, 'hashed column changed in the DB: verify refused', $v['error']);
    check($now['item_name'] === $before['item_name'], 'refused save wrote nothing (name unchanged)');
    check(abs((float)$now['price'] - ((float)$before['price'] + 1)) < 0.005, 'helper itself wrote nothing (only the simulated change is present)');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
$after = $load();
check($after == $before, "rolled back: the $tbl row is exactly as before the test");

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
