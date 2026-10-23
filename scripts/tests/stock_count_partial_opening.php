<?php
/**
 * Partial opening stock counts (admin/stock-count.php): an OPENING count can cover everything, one category or
 * only chosen items, so the items that already have a cost can be counted now and the rest later.
 * Usage: php scripts/tests/stock_count_partial_opening.php
 *
 * Runs through the REAL page in scripts/tests/page_harness.php (one outer transaction that never commits):
 *  - start an opening count by category / chosen items / everything: right lines, flagged opening, scope kept;
 *  - an item may be in only ONE open (being counted / waiting for approval) opening count: starting a second one
 *    that shares an item is refused with a plain message naming the item and the count, and writes nothing;
 *    approved or rejected opening counts, other items, and ordinary (non-opening) counts do not block;
 *  - reopening a rejected opening count is refused while another open opening count holds one of its items;
 *  - approving a partial opening count posts only the counted items (items not in it keep their stock, and get
 *    no stock movement); two partial counts of different items are independent;
 *  - an opening count that was started by mistake (a draft) can be deleted, and never changed stock;
 *  - names typed by users (stock item, category) reach the screen as text, never as markup;
 *  - the 30-second double-click guard merges identical counts but never an opening count with a regular one.
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
$run = strtoupper(bin2hex(random_bytes(3)));
$me = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1");   // who the harness signs in as
$other = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 AND id <> ? ORDER BY id LIMIT 1", [$me]);
if ($me <= 0 || $other <= 0) { echo "SKIP: needs two active users\n"; exit(0); }
$hasCountedAt = (int)col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_count_lines' AND COLUMN_NAME = 'counted_at'") > 0;
$hasOpening = (int)col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_counts' AND COLUMN_NAME = 'is_opening'") > 0;
if (!$hasOpening) { echo "SKIP: stock_counts.is_opening not added yet (open the Stock Counts page once)\n"; exit(0); }

$cat1 = "ZZT PO $run C1";
$cat2 = "ZZT PO $run C2";
$ingSetup = [
    // Rolled back with everything else: real open opening counts (such as the stock sheet import's) must not decide these checks.
    "UPDATE stock_counts SET status = 'rejected' WHERE is_opening = 1 AND status IN ('draft', 'submitted')",
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES ('ZZT PO $run A1', '$cat1', 'kg', 0, 0)",
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES ('ZZT PO $run A2', '$cat1', 'kg', 0, 0)",
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES ('ZZT PO $run B1', '$cat2', 'kg', 50, 200)",
    "INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, expiry_alert_days, status, notes) SELECT id, 'ZZT-PO-$run', 50, 50, 200, CURDATE(), 7, 'active', 'ZZT test' FROM stock_ingredients WHERE name = 'ZZT PO $run B1'",
];
$vars = [
    'a1' => "SELECT id FROM stock_ingredients WHERE name = 'ZZT PO $run A1'",
    'a2' => "SELECT id FROM stock_ingredients WHERE name = 'ZZT PO $run A2'",
    'b1' => "SELECT id FROM stock_ingredients WHERE name = 'ZZT PO $run B1'",
];
$ref = static fn(string $tag): string => '20991231-PO' . $run . $tag;
/** A ready-made count with the given items ([name suffix => actual quantity]); counted by $by. */
$mkCount = static function (string $tag, int $opening, string $status, array $items, int $by) use ($ref, $run, $hasCountedAt): array {
    $r = $ref($tag);
    $sql = ["INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, is_opening, created_at" . ($status === 'rejected' ? ', rejection_reason' : '') . ") VALUES ('$r', CURDATE(), 'spot', '$status', $by, $opening, NOW() - INTERVAL 5 MINUTE" . ($status === 'rejected' ? ", 'test'" : '') . ")"];
    foreach ($items as $suffix => $actual) {
        $sql[] = "INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost" . ($hasCountedAt && $status !== 'draft' ? ', counted_at' : '') . ")
                  SELECT sc.id, i.id, i.current_quantity, $actual, $actual - i.current_quantity, 100, ($actual - i.current_quantity) * 100" . ($hasCountedAt && $status !== 'draft' ? ', NOW() - INTERVAL 4 MINUTE' : '') . "
                  FROM stock_counts sc, stock_ingredients i WHERE sc.reference = '$r' AND i.name = 'ZZT PO $run $suffix'";
    }
    return $sql;
};

/** Runs the real page once in the harness. The messages the page shows (the result banner) come back in output_flash. */
$exec = function (array $post, array $setup, array $vars2, array $verify) use ($harness): array {
    $dump = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sc_po_' . bin2hex(random_bytes(4)) . '.html';
    $cfg = base64_encode(json_encode(['post' => $post, 'setup' => $setup, 'vars' => $vars2, 'verify' => $verify, 'dump_html' => $dump]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' admin/stock-count.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr($out, -700), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $res = $x; } }
    $html = is_file($dump) ? (string)file_get_contents($dump) : '';
    @unlink($dump);
    $res['html'] = $html;
    $res['output_flash'] = [];
    // The page shows its messages in a centred banner (no timer): each paragraph of it, as printed (escaped once).
    if (preg_match('#<div class="modal-body">\s*((?:<p class="sc-banner-msg"[^>]*>.*?</p>\s*)+)</div>#s', $html, $mm) && preg_match_all('#<p class="sc-banner-msg"[^>]*>(.*?)</p>#s', $mm[1], $pp)) {
        foreach ($pp[1] as $t) { $res['output_flash'][] = (string)$t; }
    }
    return $res;
};
$page = function (array $post, array $setup, array $verify) use ($exec, $vars): array { return $exec($post, $setup, $vars, $verify); };
$clean = static fn(array $r): bool => !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']);

$mark = "ZZTPO$run";
$verify = [
    // 0: counts the page made (by the marker in the notes)
    "SELECT is_opening, scope, scope_value, status FROM stock_counts WHERE notes = '$mark' ORDER BY id",
    // 1: the items on those counts
    "SELECT i.name FROM stock_count_lines l INNER JOIN stock_counts sc ON sc.id = l.count_id INNER JOIN stock_ingredients i ON i.id = l.ingredient_id WHERE sc.notes = '$mark' ORDER BY i.name",
    // 2: stock of the three test items
    "SELECT name, current_quantity FROM stock_ingredients WHERE name LIKE 'ZZT PO $run %' ORDER BY name",
    // 3: stock movements per test item
    "SELECT i.name, COUNT(a.id) AS n FROM stock_ingredients i LEFT JOIN stock_adjustments a ON a.ingredient_id = i.id WHERE i.name LIKE 'ZZT PO $run %' GROUP BY i.name ORDER BY i.name",
    // 4: the ready-made counts
    "SELECT reference, status FROM stock_counts WHERE reference LIKE '20991231-PO{$run}%' ORDER BY reference",
    // 5: batches of the test items
    "SELECT i.name, COALESCE(SUM(b.quantity_remaining), 0) AS q FROM stock_ingredients i LEFT JOIN stock_batches b ON b.ingredient_id = i.id WHERE i.name LIKE 'ZZT PO $run %' GROUP BY i.name ORDER BY i.name",
];
$names = static fn(array $r): array => array_map(static fn($x) => (string)($x['name'] ?? ''), (array)($r['verify'][1] ?? []));
$short = static fn(array $list): array => array_map(static fn($n) => preg_replace('/^ZZT PO [0-9A-F]{6} /', '', $n), $list);
$qtyOf = static function (array $r, string $item) use ($run): ?float {
    foreach ((array)($r['verify'][2] ?? []) as $x) { if ($x['name'] === "ZZT PO $run $item") { return (float)$x['current_quantity']; } }
    return null;
};
$movesOf = static function (array $r, string $item) use ($run): int {
    foreach ((array)($r['verify'][3] ?? []) as $x) { if ($x['name'] === "ZZT PO $run $item") { return (int)$x['n']; } }
    return -1;
};
$flash = static fn(array $r): string => implode(' | ', (array)($r['output_flash'] ?? []));
$startPost = ['action' => 'start_count', 'opening' => '1', 'notes' => $mark];

$snapshot = static function () use ($pdo): array {
    return ['counts' => (int)col($pdo, "SELECT COUNT(*) FROM stock_counts"), 'lines' => (int)col($pdo, "SELECT COUNT(*) FROM stock_count_lines"), 'adj' => (int)col($pdo, "SELECT COUNT(*) FROM stock_adjustments")];
};
$before = $snapshot();

// ---- 1. Starting an opening count: by category, chosen items, everything -----------------------------------------
$r = $page($startPost + ['scope' => 'category', 'scope_value' => $cat1], $ingSetup, $verify);
$c0 = $r['verify'][0][0] ?? [];
check($clean($r) && count($r['verify'][0] ?? []) === 1 && (int)($c0['is_opening'] ?? 0) === 1 && ($c0['scope'] ?? '') === 'category' && ($c0['scope_value'] ?? '') === $cat1 && ($c0['status'] ?? '') === 'draft',
    'Opening count by category: one draft opening count, scope kept', json_encode($r['verify'][0] ?? $r['raw'] ?? $r));
check($short($names($r)) === ['A1', 'A2'], 'Opening count by category: only that category\'s items are on it (not the other category)', json_encode($names($r)));
check(abs(($qtyOf($r, 'B1') ?? -1) - 50) < 0.0001 && $movesOf($r, 'A1') === 0, 'Opening count by category: nothing changes stock when it is started');

$r = $page($startPost + ['scope' => 'spot', 'scope_value' => '{a1},{b1}'], $ingSetup, $verify);
$c0 = $r['verify'][0][0] ?? [];
check($clean($r) && (int)($c0['is_opening'] ?? 0) === 1 && ($c0['scope'] ?? '') === 'spot' && $short($names($r)) === ['A1', 'B1'], 'Opening count of chosen items: exactly the items picked, flagged opening', json_encode([$c0, $names($r)]));

$r = $page($startPost + ['scope' => 'full'], $ingSetup, $verify);
$c0 = $r['verify'][0][0] ?? [];
$n3 = array_values(array_intersect($short($names($r)), ['A1', 'A2', 'B1']));
check($clean($r) && (int)($c0['is_opening'] ?? 0) === 1 && ($c0['scope'] ?? '') === 'full' && $n3 === ['A1', 'A2', 'B1'], 'Opening count of everything still works (every item, scope full)', json_encode([$c0, $n3]));

$r = $page($startPost + ['scope' => 'spot', 'scope_value' => ''], $ingSetup, $verify);
check($clean($r) && count($r['verify'][0] ?? []) === 0 && stripos($flash($r), 'at least one item') !== false, 'Opening count with no item chosen is refused in plain words', $flash($r));

// An ordinary (non-opening) count is unchanged by all this.
$r = $page(['action' => 'start_count', 'scope' => 'spot', 'scope_value' => '{a1},{b1}', 'notes' => $mark], $ingSetup, $verify);
$c0 = $r['verify'][0][0] ?? [];
check($clean($r) && (int)($c0['is_opening'] ?? 1) === 0 && $short($names($r)) === ['A1', 'B1'], 'An ordinary count of chosen items is still not an opening count', json_encode($c0));

// ---- 2. The same item cannot be in two open opening counts ------------------------------------------------------
foreach (['draft', 'submitted'] as $st) {
    $setup = array_merge($ingSetup, $mkCount('X', 1, $st, ['A1' => 10], $other));
    $r = $page($startPost + ['scope' => 'category', 'scope_value' => $cat1], $setup, $verify);
    $f = $flash($r);
    check($clean($r) && count($r['verify'][0] ?? []) === 0, "Overlap: an opening count that would share an item with an open ($st) opening count is refused and writes nothing", $f);
    check(stripos($f, 'ZZT PO ' . $run . ' A1') !== false && stripos($f, $ref('X')) !== false && stripos($f, 'only one open opening count') !== false, "Overlap ($st): the message names the item and the other count", $f);
}
$setup = array_merge($ingSetup, $mkCount('X', 1, 'draft', ['A1' => 10], $other));
$r = $page($startPost + ['scope' => 'full'], $setup, $verify);
check($clean($r) && count($r['verify'][0] ?? []) === 0 && stripos($flash($r), 'Only the items I choose') !== false, 'Overlap: a count of everything is refused too, and the message says how to leave the items out', $flash($r));
$r = $page($startPost + ['scope' => 'spot', 'scope_value' => '{b1},{a2}'], $setup, $verify);
check($clean($r) && count($r['verify'][0] ?? []) === 1 && $short($names($r)) === ['A2', 'B1'], 'Overlap: choosing only the items that are not in the other open count works', json_encode($names($r)));
$r = $page($startPost + ['scope' => 'category', 'scope_value' => $cat2], $setup, $verify);
check($clean($r) && count($r['verify'][0] ?? []) === 1 && $short($names($r)) === ['B1'], 'Two partial opening counts of different items can be open at the same time', json_encode($names($r)));
foreach (['approved', 'rejected'] as $st) {
    $setup = array_merge($ingSetup, $mkCount('X', 1, $st, ['A1' => 10], $other));
    $r = $page($startPost + ['scope' => 'category', 'scope_value' => $cat1], $setup, $verify);
    check($clean($r) && count($r['verify'][0] ?? []) === 1 && $short($names($r)) === ['A1', 'A2'], "No overlap with a $st opening count: it is not open, so it does not block", $flash($r));
}
$setup = array_merge($ingSetup, $mkCount('X', 0, 'draft', ['A1' => 10], $other));
$r = $page($startPost + ['scope' => 'category', 'scope_value' => $cat1], $setup, $verify);
check($clean($r) && count($r['verify'][0] ?? []) === 1, 'An ordinary count that is open does not block an opening count', $flash($r));

// ---- 3. Reopening a rejected opening count ----------------------------------------------------------------------
$setup = array_merge($ingSetup, $mkCount('R', 1, 'rejected', ['A1' => 10], $other), $mkCount('D', 1, 'draft', ['A1' => 5], $other));
/** Runs an action on one of the ready-made counts ({rid} = that count's id, found by its tag). */
$pageRid = function (array $post, array $setup, string $tag) use ($exec, $vars, $ref, $verify): array {
    return $exec($post, $setup, $vars + ['rid' => "SELECT id FROM stock_counts WHERE reference = '" . $ref($tag) . "'"], $verify);
};
$statusOf = static function (array $r, string $tag) use ($ref): string {
    foreach ((array)($r['verify'][4] ?? []) as $x) { if ($x['reference'] === $ref($tag)) { return (string)$x['status']; } }
    return '?';
};
$r = $pageRid(['action' => 'reopen_count', 'count_id' => '{rid}'], $setup, 'R');
check($clean($r) && $statusOf($r, 'R') === 'rejected' && stripos($flash($r), 'already in another opening stock count') !== false, 'Reopen: refused while another open opening count holds one of its items, and it stays rejected', $flash($r) . ' ' . $statusOf($r, 'R'));
$setup = array_merge($ingSetup, $mkCount('R', 1, 'rejected', ['A1' => 10], $other));
$r = $pageRid(['action' => 'reopen_count', 'count_id' => '{rid}'], $setup, 'R');
check($clean($r) && $statusOf($r, 'R') === 'draft', 'Reopen: a rejected opening count with no shared items reopens as a draft', $flash($r) . ' ' . $statusOf($r, 'R'));

// ---- 4. Approving a partial opening count posts only what was counted ------------------------------------------
$setup = array_merge($ingSetup, $mkCount('P', 1, 'submitted', ['A1' => 10], $other));
$r = $pageRid(['action' => 'approve_count', 'count_id' => '{rid}'], $setup, 'P');
check($clean($r) && $statusOf($r, 'P') === 'approved', 'Approve a partial opening count (one item): approved', $flash($r) . ' ' . $statusOf($r, 'P'));
check(abs(($qtyOf($r, 'A1') ?? -1) - 10) < 0.0001 && $movesOf($r, 'A1') === 1, 'Approve partial: the counted item gets its opening stock (10) and one movement');
check(abs(($qtyOf($r, 'B1') ?? -1) - 50) < 0.0001 && $movesOf($r, 'B1') === 0 && abs(($qtyOf($r, 'A2') ?? -1) - 0) < 0.0001 && $movesOf($r, 'A2') === 0, 'Approve partial: items not in the count keep their stock exactly, with no stock movement');
$bat = []; foreach ((array)($r['verify'][5] ?? []) as $x) { $bat[$x['name']] = (float)$x['q']; }
check(abs(($bat["ZZT PO $run A1"] ?? -1) - 10) < 0.0001 && abs(($bat["ZZT PO $run B1"] ?? -1) - 50) < 0.0001, 'Approve partial: batches match (A1 got a batch of 10, B1 batch untouched)', json_encode($bat));
// Two partial counts of different items are independent: approving one does not touch the other count or its items.
$setup = array_merge($ingSetup, $mkCount('P', 1, 'submitted', ['A1' => 10], $other), $mkCount('Q', 1, 'submitted', ['A2' => 7], $other));
$r = $pageRid(['action' => 'approve_count', 'count_id' => '{rid}'], $setup, 'P');
check($clean($r) && $statusOf($r, 'P') === 'approved' && $statusOf($r, 'Q') === 'submitted' && abs(($qtyOf($r, 'A2') ?? -1) - 0) < 0.0001, 'Two partial opening counts: approving one leaves the other waiting and its item untouched', json_encode($r['verify'][4] ?? ''));

// ---- 5. A draft opening count started by mistake can be deleted -------------------------------------------------
$setup = array_merge($ingSetup, $mkCount('M', 1, 'draft', ['A1' => 0, 'A2' => 0], $other));
$r = $pageRid(['action' => 'delete_count', 'count_id' => '{rid}'], $setup, 'M');
check($clean($r) && $statusOf($r, 'M') === '?' && abs(($qtyOf($r, 'A1') ?? -1) - 0) < 0.0001 && $movesOf($r, 'A1') === 0, 'Delete a draft opening count: it is gone and stock was not changed', $flash($r));
$setup = array_merge($ingSetup, $mkCount('M', 1, 'submitted', ['A1' => 3], $other));
$r = $pageRid(['action' => 'delete_count', 'count_id' => '{rid}'], $setup, 'M');
check($clean($r) && $statusOf($r, 'M') === 'submitted', 'A count waiting for approval cannot be deleted', $flash($r));

// ---- 6. Names reach the screen as text, never as markup ---------------------------------------------------------
$evilName = '<b>Bold</b> & Co';
$evilCat = "ZZT PO $run <i>C3</i> & more";
$setup = [
    "UPDATE stock_counts SET status = 'rejected' WHERE is_opening = 1 AND status IN ('draft', 'submitted')",
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES ('ZZT PO $run $evilName', '$evilCat', 'kg', 0, 0)",
];
$setup = array_merge($setup, $mkCount('X', 1, 'draft', [$evilName => 10], $other));
$r = $page($startPost + ['scope' => 'category', 'scope_value' => $evilCat], $setup, $verify);
$f = $flash($r);
check($clean($r) && count($r['verify'][0] ?? []) === 0 && strpos($f, 'already in another opening stock count') !== false, 'Escaping: the refusal for an item with markup in its name is shown', $f);
check(strpos($f, '&lt;b&gt;Bold&lt;/b&gt; &amp; Co') !== false && strpos($f, '<b>') === false, 'Escaping: the item name in the refusal message is HTML-escaped (a name with <b> and & cannot run in the banner)', $f);
check(strpos((string)($r['html'] ?? ''), '<b>Bold</b>') === false && strpos((string)($r['html'] ?? ''), '<i>C3</i>') === false, 'Escaping: the raw markup never appears in the page');
// No double escaping on screen: what the banner shows after the browser reads the escaped text is the plain name.
check(html_entity_decode($f, ENT_QUOTES, 'UTF-8') !== $f && strpos(html_entity_decode($f, ENT_QUOTES, 'UTF-8'), $evilName) !== false, 'Escaping: decoded once (as the browser does) the message shows the plain name, escaped only one time', $f);

// ---- 7. The 30-second double-click guard ------------------------------------------------------------------------
$mkDraft = static function (string $tag, int $opening) use ($ref, $me, $cat1, $run): array {
    $r = $ref($tag);
    $sql = ["INSERT INTO stock_counts (reference, count_date, scope, scope_value, status, counted_by, is_opening, created_at) VALUES ('$r', CURDATE(), 'category', '$cat1', 'draft', $me, $opening, NOW())"];
    foreach (['A1', 'A2'] as $suffix) {
        $sql[] = "INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost)
                  SELECT sc.id, i.id, i.current_quantity, 0, 0, 0, 0 FROM stock_counts sc, stock_ingredients i WHERE sc.reference = '$r' AND i.name = 'ZZT PO $run $suffix'";
    }
    return $sql;
};
$regularPost = ['action' => 'start_count', 'scope' => 'category', 'scope_value' => $cat1, 'notes' => $mark];
$r = $page($startPost + ['scope' => 'category', 'scope_value' => $cat1], array_merge($ingSetup, $mkDraft('G', 0)), $verify);
check($clean($r) && count($r['verify'][0] ?? []) === 1 && (int)($r['verify'][0][0]['is_opening'] ?? 0) === 1, 'Double-click guard: an opening count is NOT merged with a regular count of the same scope started a moment ago', json_encode($r['verify'][0] ?? $r['raw'] ?? $r));
$r = $page($regularPost, array_merge($ingSetup, $mkDraft('G', 1)), $verify);
check($clean($r) && count($r['verify'][0] ?? []) === 1 && (int)($r['verify'][0][0]['is_opening'] ?? 1) === 0, 'Double-click guard: a regular count is NOT merged with an opening count of the same scope started a moment ago', json_encode($r['verify'][0] ?? $r['raw'] ?? $r));
$r = $page($startPost + ['scope' => 'category', 'scope_value' => $cat1], array_merge($ingSetup, $mkDraft('G', 1)), $verify);
check($clean($r) && count($r['verify'][0] ?? []) === 0, 'Double-click guard: the same opening count pressed twice is still merged into the one that exists');
$r = $page($regularPost, array_merge($ingSetup, $mkDraft('G', 0)), $verify);
check($clean($r) && count($r['verify'][0] ?? []) === 0, 'Double-click guard: the same regular count pressed twice is still merged into the one that exists');

// ---- Leak check --------------------------------------------------------------------------------------------------
check((int)col($pdo, "SELECT COUNT(*) FROM stock_counts WHERE reference LIKE '20991231-PO%' OR notes LIKE 'ZZTPO%'") === 0, 'Leak: no test stock counts left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT PO %'") === 0, 'Leak: no test ingredients left behind');
check($snapshot() === $before, 'Leak: real counts, lines and stock movements are exactly as before');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
