<?php
/**
 * Stock count "big form" test (admin/stock-count.php, 2026-10-23 fault: 406 Not Acceptable on a 420-line count).
 * Usage: php scripts/tests/stock_count_bigform.php
 *
 * A 420-line count posted as line[ID][actual|touched|cost|reason|notes] is ~2,100 fields: PHP drops everything past
 * max_input_vars and the host firewall answers 406. The page now packs changed lines into a few lines_json[] fields.
 * Covers, through the REAL page in scripts/tests/page_harness.php (one outer transaction that is rolled back):
 *   - 420 readings saved from lines_json (9 fields) - every line counted, readings and reasons right
 *   - an id that belongs to another count, a negative reading, damaged JSON: refused, NOTHING saved (not even the valid lines)
 *   - legacy line[ID][...] still saves; a post with more fields than max_input_vars is refused, nothing saved
 *   - submit works on a fully counted 420-line draft
 * Plus static checks that the page packs before sending. Leaves nothing behind.
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
$hasCol = (int)col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_count_lines' AND COLUMN_NAME = 'counted_at'") > 0;
$countedExpr = $hasCol ? 'scl.counted_at IS NOT NULL' : 'ABS(scl.actual_quantity) > 0.00005';
$ingBefore = (int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT BF %'");
$cntBefore = (int)col($pdo, "SELECT COUNT(*) FROM stock_counts");

$scenario = function (string $name, array $grep = []) use ($harness, $run, $countedExpr): array {
    $ref = "'20991231-BF" . $run . "A'";
    $verify = [
        "SELECT status FROM stock_counts WHERE reference = $ref",
        "SELECT COUNT(*) AS n, SUM($countedExpr) AS counted, SUM(scl.reason_code = 'spillage') AS spill, SUM(scl.reason_notes LIKE 'bf %') AS noted, COALESCE(SUM(scl.actual_quantity),0) AS total FROM stock_count_lines scl INNER JOIN stock_counts sc ON sc.id = scl.count_id WHERE sc.reference = $ref",
    ];
    $cfg = base64_encode(json_encode(["post" => ["run" => $run, "scenario" => $name], "verify" => $verify, "grep" => $grep]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/stock_count_bigform_child.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -600), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $res = $x; } }
    return $res;
};
$flash = fn(array $r): string => json_encode($r['output_flash'] ?? []);

// 420 expected readings: (k % 7) + 1 for k = 0..419
$expectTotal = 0; for ($k = 0; $k < 420; $k++) { $expectTotal += ($k % 7) + 1; }

$r = $scenario('packed');
check(!empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']), 'Packed save: ran and rolled back', json_encode($r['raw'] ?? $r));
$v = $r['verify'][1][0] ?? [];
check((int)($v['n'] ?? 0) === 420, 'Packed save: the draft has 420 lines', json_encode($v));
check((int)($v['counted'] ?? -1) === 420, 'Packed save: all 420 readings saved from lines_json', json_encode($v) . $flash($r));
check((int)($v['spill'] ?? -1) === 420 && (int)($v['noted'] ?? -1) === 420, 'Packed save: reasons and notes saved on every line', json_encode($v));
check(abs((float)($v['total'] ?? -1) - $expectTotal) < 0.001, 'Packed save: every reading is the number typed (' . $expectTotal . ' in total)', json_encode($v));
check(stripos($flash($r), 'saved') !== false || (string)($r['verify'][0][0]['status'] ?? '') === 'draft', 'Packed save: count is still a draft', $flash($r));

foreach ([
    'packed_bad_id'   => ['A line from another count is refused', 'not part of this count'],
    'packed_negative' => ['A negative reading is refused', 'negative'],
    'packed_garbage'  => ['Damaged JSON is refused', 'could not be read'],
    'legacy_too_many' => ['More fields than max_input_vars is refused, never half-saved', 'too many fields'],
] as $name => [$label, $needle]) {
    $r = $scenario($name, [$needle]);
    check(!empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']), "$label: ran and rolled back", json_encode($r['raw'] ?? $r));
    $v = $r['verify'][1][0] ?? [];
    check((int)($v['counted'] ?? -1) === 0, "$label: nothing was saved (not even the valid lines)", json_encode($v));
    check(!empty($r["found"][$needle]), "$label: plain message shown", json_encode($r["found"] ?? null));
}

$r = $scenario('legacy');
$v = $r['verify'][1][0] ?? [];
check((int)($v['counted'] ?? -1) === 5 && abs((float)($v['total'] ?? -1) - 20) < 0.001, 'Legacy line[ID][...] post still saves (5 lines, 2+3+4+5+6)', json_encode($v) . $flash($r));

$r = $scenario('submit');
check((string)($r['verify'][0][0]['status'] ?? '') === 'submitted', 'Submit: a fully counted 420-line draft goes to "submitted"', json_encode($r['verify'][0] ?? null) . $flash($r));

// Stale-count guard: a second count of the same item, approved after the first was approved, must not double-post.
$r = $scenario('stale_block', ['out of date']);
check(!empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']), 'Stale count: ran and rolled back', json_encode($r['raw'] ?? $r));
check((string)($r['verify'][0][0]['status'] ?? '') === 'submitted' && !empty($r['found']['out of date']), 'Stale count: approving a count made out of date by another approval is refused and stays waiting', json_encode($r['verify'][0] ?? null) . json_encode($r['found'] ?? null));
$r = $scenario('stale_ok');
check((string)($r['verify'][0][0]['status'] ?? '') === 'approved', 'Stale count: a count taken AFTER the other one was approved still approves', json_encode($r['verify'][0] ?? null) . $flash($r));

// Static: the page packs before it sends.
$src = (string)file_get_contents(__DIR__ . '/../../admin/stock-count.php');
check(strpos($src, 'function scPackLines(form, pack)') !== false && strpos($src, "h.name = 'lines_json[]'") !== false, 'Page: readings are packed into lines_json[] chunks');
check(strpos($src, 'scPackLines(form, false);') !== false && strpos($src, "scPackLines(f, !!(s && s.value === 'save_lines'))") !== false, 'Page: save packs the changed lines, submit/approve/reject send no per-line fields');
check(strpos($src, 'scPostTruncated($_POST)') !== false, 'Page: a post cut off by max_input_vars is refused');

// Leak check
check((int)col($pdo, "SELECT COUNT(*) FROM stock_counts WHERE reference LIKE '20991231-BF%'") === 0, 'Leak: no test counts left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT BF %'") === $ingBefore, 'Leak: no test ingredients left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM stock_counts") === $cntBefore, 'Leak: the number of counts is unchanged');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
