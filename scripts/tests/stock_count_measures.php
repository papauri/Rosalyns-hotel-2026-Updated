<?php
/**
 * Stock counted in shots or glasses (Step U1, owner 2026-11-18). Usage: php scripts/tests/stock_count_measures.php
 *
 * Covers:
 *   - the maths (rh_stock_measure_*): both directions, both measures at once, 6 dp, N 1..1000, field checks, names, sizes in names
 *   - through the REAL pages in scripts/tests/page_harness.php (one outer transaction, rolled back):
 *       readings typed in shots / both / the stock unit sent in lines_json (the page's own figure is ignored),
 *       a changed N re-works draft and submitted lines but never an approved one, an approver switching a submitted
 *       line from bottles to shots, removing a measure in use refused, bad N refused, an approved count is not re-worked,
 *       the correction of an approved count (preview, confirm, opening count, again = refused, shortage / sold refused),
 *       CSRF, permissions, the Stock Items edit (saved + logged + re-worked; bad N; an old form never wipes it), the
 *       count page markup
 *   - static checks that every reader of the new columns is guarded and that the page sends mode / big / small
 * Leaves nothing behind (all ZZT rows are created inside the rolled-back transaction).
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
function isnul(array $row, string $k): bool { return array_key_exists($k, $row) && $row[$k] === null; }
function near($a, float $b, float $eps = 0.0000011): bool { return $a !== null && abs((float)$a - $b) < $eps; }

/* ---------- 1. The maths, no database ---------- */
check(rh_stock_measure_ready($pdo), 'Migration 088 has run (8 columns)');
$m = ['big' => 'bottle', 'small' => 'shot', 'n' => 30.0, 'side' => 'big'];
check(rh_stock_measure_to_stock('small', null, 602, 30, 'big') === 20.066667, '602 shots of a bottle of 30 = 20.066667 bottles (6 dp)');
check(rh_stock_measure_to_stock('both', 2, 15, 30, 'big') === 2.5, '2 bottles + 15 shots = 2.5 bottles');
check(rh_stock_measure_to_stock('both', 2, 7, 30, 'small') === 67.0, 'Kept in shots: 2 bottles + 7 shots = 67 shots');
check(rh_stock_measure_to_stock('big', 3, null, 30, 'small') === 90.0, 'Kept in shots: 3 bottles = 90 shots');
check(rh_stock_measure_to_stock('small', null, 1, 3, 'big') === 0.333333, 'Rounded to 6 dp (1 of 3 = 0.333333)');
check(rh_stock_measure_to_stock('small', null, 7, 4.2857, 'big') === round(7 / 4.2857, 6), 'Fractional N (glasses of a bottle) works');
check(rh_stock_measure_to_stock('small', null, -1, 30, 'big') === null && rh_stock_measure_to_stock('small', null, 5, 0, 'big') === null
    && rh_stock_measure_to_stock('small', null, 5, 1001, 'big') === null && rh_stock_measure_to_stock('litre', 1, 1, 30, 'big') === null
    && rh_stock_measure_to_stock('small', null, 5, 30, 'side') === null, 'Negative, N 0 or over 1000, an unknown mode or side: no figure');
[$x, $e] = rh_stock_measure_from_input([]);
check($x === null && $e === null, 'All fields empty = remove the measure');
[$x, $e] = rh_stock_measure_from_input(['measure_big_name' => ' bottle ', 'measure_small_name' => 'shot', 'measure_per_big' => '1000', 'measure_stock_side' => 'big']);
check($e === null && $x === ['big' => 'bottle', 'small' => 'shot', 'n' => 1000.0, 'side' => 'big'], 'N = 1000 accepted, names trimmed', json_encode([$x, $e]));
$refusals = [
    'one name only' => ['measure_big_name' => 'bottle', 'measure_per_big' => '30', 'measure_stock_side' => 'big'],
    'N = 0' => ['measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '0', 'measure_stock_side' => 'big'],
    'N = 0.5' => ['measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '0.5', 'measure_stock_side' => 'big'],
    'N = 1000.5' => ['measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '1000.5', 'measure_stock_side' => 'big'],
    'N not a number' => ['measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => 'thirty', 'measure_stock_side' => 'big'],
    'same names' => ['measure_big_name' => 'Shot', 'measure_small_name' => 'shot', 'measure_per_big' => '3', 'measure_stock_side' => 'big'],
    'bad side' => ['measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '30', 'measure_stock_side' => 'x'],
    'name over 30' => ['measure_big_name' => str_repeat('b', 31), 'measure_small_name' => 'shot', 'measure_per_big' => '30', 'measure_stock_side' => 'big'],
];
foreach ($refusals as $what => $in) {
    [$x, $e] = rh_stock_measure_from_input($in);
    check($x === null && is_string($e) && $e !== '', "Measure refused with a plain message: $what", (string)$e);
}
check(rh_stock_measure_label('both', 2, 7, $m) === '2 bottles + 7 shots' && rh_stock_measure_label('small', null, 1, $m) === '1 shot', 'Typed label reads "2 bottles + 7 shots" / "1 shot"');
check(rh_stock_measure_plural('glass', 2) === 'glasses' && rh_stock_measure_plural('glass', 1) === 'glass', 'Plurals: glass / glasses');
check(rh_stock_measure_name_ml('Gin 750ml') === 750.0 && rh_stock_measure_name_ml('Wine 75cl') === 750.0 && rh_stock_measure_name_ml('Juice 1L') === 1000.0
    && rh_stock_measure_name_ml('Mazoe 1.5 L') === 1500.0 && rh_stock_measure_name_ml('Lime') === null && rh_stock_measure_name_ml('Lager 330ml can') === 330.0, 'Size read from the name (750ml, 75cl, 1L, 1.5 L; none in "Lime")');
check(rh_stock_measure_describe($m) === 'bottle / shot x 30 (stock unit: bottle)' && rh_stock_measure_describe(null) === 'none', 'History wording of a measure');
$sizes = rh_stock_measure_sizes();
check($sizes['shot_ml'] > 0 && $sizes['glass_ml'] > 0, 'Shot and glass sizes are set (settings seeded by 088)', json_encode($sizes));

/* ---------- 2. Through the real pages ---------- */
$harness = __DIR__ . '/page_harness.php';
$run = strtoupper(bin2hex(random_bytes(3)));
$like = 'ZZT MS ' . $run . ' %';
$refLike = '20991231-MS' . $run . '%';
$verify = [
    "SELECT RIGHT(sc.reference, 1) AS c, sc.status, i.name AS nm, scl.actual_quantity AS a, scl.variance AS v, scl.variance_cost AS vc, scl.entry_mode AS em, scl.entry_big AS eb, scl.entry_small AS es, scl.entry_per_big AS en, scl.reason_code AS rc FROM stock_count_lines scl INNER JOIN stock_counts sc ON sc.id = scl.count_id INNER JOIN stock_ingredients i ON i.id = scl.ingredient_id WHERE sc.reference LIKE '$refLike' ORDER BY scl.id",
    "SELECT name, current_quantity AS q, cost_per_unit AS c, measure_big_name AS b, measure_small_name AS s, measure_per_big AS n, measure_stock_side AS sd FROM stock_ingredients WHERE name LIKE '$like' ORDER BY id",
    "SELECT b.quantity_received AS rcv, b.quantity_remaining AS rem, b.status FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE i.name LIKE '$like' ORDER BY b.id",
    "SELECT a.quantity_change AS qc, a.source_type AS st, a.source_id AS sid, a.cost_at_time AS cat, a.reason FROM stock_adjustments a INNER JOIN stock_ingredients i ON i.id = a.ingredient_id WHERE i.name LIKE '$like' ORDER BY a.id",
    "SELECT RIGHT(reference, 1) AS c, status, total_variance_cost AS t, surplus_cost AS s, shortage_cost AS sh FROM stock_counts WHERE reference LIKE '$refLike' ORDER BY id",
    "SELECT action, details FROM admin_activity_log WHERE details LIKE '%ZZT MS $run %' ORDER BY id",
];
$scenario = function (string $name, array $grep = []) use ($harness, $run, $verify): array {
    $cfg = base64_encode(json_encode(['post' => ['run' => $run, 'scenario' => $name], 'verify' => $verify, 'grep' => $grep]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/stock_count_measures_child.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -900), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $mm)) { $x = json_decode($mm[1], true); if (is_array($x)) { $res = $x; } }
    return $res;
};
$safe = function (array $r, string $label): void {
    check(!empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']), "$label: ran and rolled back", json_encode($r['raw'] ?? $r));
};
$flash = fn(array $r): string => json_encode($r['output_flash'] ?? []);
$lines = fn(array $r): array => $r['verify'][0] ?? [];
$acts = fn(array $r): array => array_column($r['verify'][5] ?? [], 'action');

// Readings typed in shots / both / stock, sent the way the page sends them.
$r = $scenario('save_modes');
$safe($r, 'Save in measures');
$L = $lines($r);
check(count($L) === 4, 'Save in measures: 4 lines', json_encode($L) . $flash($r));
check(near($L[0]['a'] ?? null, 20.066667) && near($L[0]['v'] ?? null, 10.066667) && ($L[0]['em'] ?? '') === 'small' && near($L[0]['es'] ?? null, 602) && isnul($L[0] ?? [], 'eb') && near($L[0]['en'] ?? null, 30),
    '602 shots saved as 20.066667 bottles (the page figure 999 ignored), typed shots and N kept', json_encode($L[0] ?? null));
check(near($L[1]['a'] ?? null, 67) && ($L[1]['em'] ?? '') === 'both' && near($L[1]['eb'] ?? null, 2) && near($L[1]['es'] ?? null, 7), 'Kept in shots: 2 bottles + 7 shots saved as 67 shots', json_encode($L[1] ?? null));
check(near($L[2]['a'] ?? null, 3) && ($L[2]['em'] ?? '') === 'stock' && isnul($L[2] ?? [], 'es'), 'Typed in the stock unit: 3 bottles, marked as stock', json_encode($L[2] ?? null));
check(near($L[3]['a'] ?? null, 6) && isnul($L[3] ?? [], 'em'), 'An item with no measure: the reading box counts, the measure fields are ignored', json_encode($L[3] ?? null));

$r = $scenario('save_bad_mode', ['measure the server does not know']);
$safe($r, 'Unknown measure');
check(!empty($r['found']['measure the server does not know']) && near(($lines($r)[0]['a'] ?? null), 0) && isnul($lines($r)[0] ?? [], 'em'), 'An unknown measure is refused, nothing saved', json_encode($lines($r)) . json_encode($r['found'] ?? null));

// N changed from a count line: draft + submitted re-worked, approved untouched, logged.
$r = $scenario('reconvert');
$safe($r, 'Change N');
$L = $lines($r);
$I = $r['verify'][1] ?? [];
check(near($I[0]['n'] ?? null, 20), 'Change N: the item now has 20 shots in a bottle', json_encode($I) . $flash($r));
check(near($L[0]['a'] ?? null, 3) && near($L[0]['v'] ?? null, -7) && near($L[0]['en'] ?? null, 20), 'Change N: the draft line (60 shots) is now 3 bottles, difference -7', json_encode($L[0] ?? null));
check(near($L[1]['a'] ?? null, 4.5) && near($L[1]['v'] ?? null, -5.5) && near($L[1]['vc'] ?? null, -1650, 0.0001), 'Change N: the submitted line (90 shots) is now 4.5 bottles, value re-worked', json_encode($L[1] ?? null));
check(near($L[2]['a'] ?? null, 1) && near($L[2]['en'] ?? null, 30), 'Change N: the approved line is never rewritten', json_encode($L[2] ?? null));
$C = $r['verify'][4] ?? [];
check(near($C[1]['t'] ?? null, -1650, 0.001), 'Change N: the submitted count total follows', json_encode($C));
check(in_array('stock_measure_changed', $acts($r), true) && strpos((string)($r['verify'][5][0]['details'] ?? ''), 'x 30') !== false && strpos((string)($r['verify'][5][0]['details'] ?? ''), 'x 20') !== false,
    'Change N: logged old -> new (stock_measure_changed)', json_encode($r['verify'][5] ?? null));

// Approver: a submitted line typed as 602 bottles was 602 shots.
$r = $scenario('line_mode');
$safe($r, 'Switch line to shots');
$L = $lines($r);
check(near($L[0]['a'] ?? null, 20.066667) && near($L[0]['v'] ?? null, 10.066667) && ($L[0]['em'] ?? '') === 'small' && near($L[0]['es'] ?? null, 602),
    'Switch line: 602 bottles -> 602 shots = 20.066667, difference 592 -> 10.066667', json_encode($L[0] ?? null) . $flash($r));
check(near(($r['verify'][4][0]['t'] ?? null), 3020, 0.006), 'Switch line: the count total follows (MWK 3,020)', json_encode($r['verify'][4] ?? null));
check(in_array('stock_count_line_measure_changed', $acts($r), true), 'Switch line: logged (stock_count_line_measure_changed)', json_encode($acts($r)));

$r = $scenario('remove_refused', ["can\u{2019}t be removed"]);
$safe($r, 'Remove a measure in use');
check(!empty($r['found']["can\u{2019}t be removed"]) && near(($r['verify'][1][0]['n'] ?? null), 30), 'Removing a measure an open line was typed in is refused, measure kept', json_encode($r['found'] ?? null) . json_encode($r['verify'][1] ?? null));

$r = $scenario('bad_n', ['between 1 and 1000']);
$safe($r, 'N over 1000');
check(!empty($r['found']['between 1 and 1000']) && near(($r['verify'][1][0]['n'] ?? null), 30), 'N = 1001 is refused, measure kept', json_encode($r['found'] ?? null));

$r = $scenario('approved_set_refused', ['are not re-worked']);
$safe($r, 'Set measure on approved');
check(!empty($r['found']['are not re-worked']) && near(($r['verify'][1][0]['n'] ?? null), 30) && near(($lines($r)[0]['a'] ?? null), 602), 'Set measure from an approved count is refused, nothing rewritten', json_encode($r['found'] ?? null));

// Correction of an APPROVED count.
$r = $scenario('fix_preview', ['Correct the count', '20.066667', '581.933333', '597', '15.066667', 'stay as recorded', 'Average cost']);
$safe($r, 'Correction preview');
check(!in_array(false, $r['found'] ?? [false], true), 'Preview shows counted 20.066667, stock 597 -> 15.066667, 581.933333 coming off, the sale since', json_encode($r['found'] ?? null));
check(near(($lines($r)[0]['a'] ?? null), 602) && count($r['verify'][3] ?? []) === 2, 'Preview saves nothing', json_encode($r['verify'][3] ?? null));

$r = $scenario('fix_apply');
$safe($r, 'Correction');
$L = $lines($r);
$B = $r['verify'][2] ?? [];
$A = $r['verify'][3] ?? [];
$I = $r['verify'][1] ?? [];
check(near($L[0]['a'] ?? null, 20.066667) && near($L[0]['v'] ?? null, 10.066667) && ($L[0]['em'] ?? '') === 'small' && near($L[0]['es'] ?? null, 602), 'Correction: line 602 -> 20.066667, difference 592 -> 10.066667, now marked as shots', json_encode($L[0] ?? null) . $flash($r));
check(near($B[0]['rcv'] ?? null, 10.066667) && near($B[0]['rem'] ?? null, 5.066667), 'Correction: the count batch rescaled (received 592 -> 10.066667, left 587 -> 5.066667)', json_encode($B));
check(near($I[0]['q'] ?? null, 15.066667), 'Correction: stock 597 -> 15.066667', json_encode($I));
$last = end($A) ?: [];
check(count($A) === 3 && near($last['qc'] ?? null, -581.933333) && ($last['st'] ?? '') === 'manual' && strpos((string)($last['reason'] ?? ''), 'correction') !== false, 'Correction: one manual adjustment of -581.933333 with a plain reason', json_encode($A));
check(near(($r['verify'][4][0]['t'] ?? null), 3020, 0.006) && near(($r['verify'][4][0]['s'] ?? null), 3020, 0.006), 'Correction: count value 177,600 -> 3,020 (cost per bottle unchanged)', json_encode($r['verify'][4] ?? null));
check(in_array('stock_count_measure_correction', $acts($r), true) && strpos((string)(end($r['verify'][5])['details'] ?? ''), '602 -> 20.066667') !== false, 'Correction: logged old -> new (stock_count_measure_correction)', json_encode($r['verify'][5] ?? null));
$sumB = array_sum(array_column($B, 'rem'));
check(near((float)($I[0]['q'] ?? -1) - $sumB, 10), 'Correction: stock and its batches move together (the 10 the fixture gave no batch stays the only gap)', $sumB . ' vs ' . json_encode($I));

check(near(($I[0]['c'] ?? null), 300) && !empty($last['sid']), 'Correction: average cost stays 300 (all stock came in at 300); the adjustment points at the count', json_encode($I) . json_encode($last));

// Item already holding stock at another cost: the average is re-worked; a line with no cost uses the adjustment's cost.
$r = $scenario('fix_avg');
$safe($r, 'Correction re-averages the cost');
$I = $r['verify'][1] ?? [];
$A = $r['verify'][3] ?? [];
$last = end($A) ?: [];
$expAvg = round((602 * 303.3223 - 581.933333 * 300) / 20.066667, 4);
check(near(($I[0]['q'] ?? null), 20.066667) && near(($I[0]['c'] ?? null), $expAvg, 0.0002), "Re-average: stock 602 -> 20.066667, average cost 303.3223 -> $expAvg (10 at 500 + 10.066667 at 300)", json_encode($I) . $flash($r));
check(near(($last['cat'] ?? null), 300) && near(($last['qc'] ?? null), -581.933333) && strpos((string)($last['reason'] ?? ''), 'average cost 303.3223 -> ') !== false, 'Re-average: line cost 0 falls back to the 300 the surplus was posted at; reason shows old -> new cost', json_encode($last));
check(strpos((string)(end($r['verify'][5])['details'] ?? ''), 'average cost 303.3223 -> ') !== false, 'Re-average: activity log shows old -> new average cost', json_encode($r['verify'][5] ?? null));

// Missing stock (last 30 days) + Needs review leave the correction out; a hand take-off stays, even one typed like it.
$r = $scenario('radar', [$like2 = 'ZZT MS ' . $run . ' Gin <span class="sc-item__unit">', 'ZZT MS ' . $run . ' Lime <span class="sc-item__unit">', 'ZZT MS ' . $run . ' Rum <span class="sc-item__unit">',
    '@@RV_GIN_OUT@@', '@@RV_LIME_IN@@', '@@RV_RUM_IN@@']);
$safe($r, 'Missing stock card');
$f = $r['found'] ?? [];
check(empty($f[$like2]) && !empty($f['ZZT MS ' . $run . ' Lime <span class="sc-item__unit">']) && !empty($f['ZZT MS ' . $run . ' Rum <span class="sc-item__unit">']), 'Missing stock card: the measure correction is not "taken off by hand"; real hand take-offs (and a look-alike reason) still show', json_encode($f));
check(!empty($f['@@RV_GIN_OUT@@']) && !empty($f['@@RV_LIME_IN@@']) && !empty($f['@@RV_RUM_IN@@']), 'Needs review: the correction is left out, hand take-offs stay', json_encode($f));

$r = $scenario('fix_opening');
$safe($r, 'Correct an opening count');
check(near(($lines($r)[0]['a'] ?? null), 20.066667) && near(($lines($r)[0]['v'] ?? null), 20.066667) && near(($r['verify'][1][0]['q'] ?? null), 20.066667) && near(($r['verify'][2][0]['rcv'] ?? null), 20.066667),
    'Opening count counted in shots: 602 -> 20.066667 bottles, stock and batch follow', json_encode($r['verify'][0] ?? null) . $flash($r));

$r = $scenario('fix_again', ['already in shots']);
$safe($r, 'Correct twice');
check(!empty($r['found']['already in shots']) && count($r['verify'][3] ?? []) === 1, 'A second correction is refused, nothing posted', json_encode($r['found'] ?? null) . json_encode($r['verify'][3] ?? null));

$r = $scenario('fix_shortage', ['found a shortage']);
$safe($r, 'Correction that turns into a shortage');
check(!empty($r['found']['found a shortage']) && count($r['verify'][3] ?? []) === 1, 'Refused when, read as shots, the count was short', json_encode($r['found'] ?? null));

$r = $scenario('fix_sold', ['more than the corrected count leaves']);
$safe($r, 'Correction after big sales');
check(!empty($r['found']['more than the corrected count leaves']) && near(($r['verify'][2][0]['rem'] ?? null), 577), 'Refused when sales since used more than the corrected count leaves; batch untouched', json_encode($r['found'] ?? null));

$r = $scenario('fix_csrf', ['This page has expired']);
$safe($r, 'Correction with a bad token');
check(!empty($r['found']['This page has expired']) && near(($lines($r)[0]['a'] ?? null), 602) && count($r['verify'][3] ?? []) === 2, 'A post without the right token changes nothing', json_encode($r['found'] ?? null));

$r = $scenario('perm_fix', ['correct an approved count']);
$safe($r, 'Correction without Approve permission');
check(!empty($r['found']['correct an approved count']) && near(($lines($r)[0]['a'] ?? null), 602), 'Correction needs both Stock Items and Approve: refused with Stock Items only', json_encode($r['found'] ?? null) . $flash($r));

$r = $scenario('perm_measure', ['change count measures']);
$safe($r, 'Set measure without permission');
check(!empty($r['found']['change count measures']) && isnul($r['verify'][1][0] ?? [], 'n'), 'Set measure needs Stock Items or Approve: refused for a counter', json_encode($r['found'] ?? null) . $flash($r));

$r = $scenario('render_draft', ['][mode]', 'In bottles + shots', 'sc-mconv', 'Change measure', 'Set measure', 'id="scMeasureModal"', 'name="measure_per_big"', '30 shots in one bottle', 'name="line_mode"']);
$safe($r, 'Count page');
$f = $r['found'] ?? [];
check(!empty($f['][mode]']) && !empty($f['In bottles + shots']) && !empty($f['sc-mconv']) && !empty($f['30 shots in one bottle']), 'Count page: the line has the measure picker, live conversion and the measure note', json_encode($f));
check(!empty($f['Change measure']) && !empty($f['Set measure']) && !empty($f['id="scMeasureModal"']) && !empty($f['name="measure_per_big"']), 'Count page: Set / Change measure buttons and the modal', json_encode($f));
check(empty($f['name="line_mode"']), 'Count page: a draft count does not offer the approver line switch', json_encode($f));

$r = $scenario('ing_update'); // the page redirects; its message travels in the session, so the database is checked
$safe($r, 'Stock Items edit');
check(near(($r['verify'][1][0]['n'] ?? null), 25) && near(($lines($r)[0]['a'] ?? null), 2.4), 'Stock Items: N 30 -> 25 saved, the open line (60 shots) re-worked to 2.4', json_encode($r['verify'][1] ?? null) . json_encode($lines($r)) . $flash($r));
check(in_array('stock_measure_changed', $acts($r), true), 'Stock Items: logged (stock_measure_changed)', json_encode($acts($r)));
$r = $scenario('ing_bad');
$safe($r, 'Stock Items bad N');
check(near(($r['verify'][1][0]['n'] ?? null), 30) && near(($lines($r)[0]['a'] ?? null), 2) && !in_array('stock_measure_changed', $acts($r), true), 'Stock Items: N = 0 refused, nothing changed', json_encode($r['verify'][1] ?? null));
$r = $scenario('ing_no_fields');
$safe($r, 'Stock Items old form');
check(near(($r['verify'][1][0]['n'] ?? null), 30) && ($r['verify'][1][0]['b'] ?? '') === 'bottle', 'Stock Items: a post without the measure fields never wipes the measure', json_encode($r['verify'][1] ?? null) . $flash($r));

/* ---------- 3. Static: guarded readers, the page sends what the server reads ---------- */
$sc = (string)file_get_contents(__DIR__ . '/../../admin/stock-count.php');
$si = (string)file_get_contents(__DIR__ . '/../../admin/stock-ingredients.php');
check(strpos($sc, '$mCols = $scMeasureReady ?') !== false && strpos($sc, "(\$mReady ? ', i.measure_big_name") !== false, 'Guarded: the count page reads the measure columns only after migration 088');
check(strpos($si, "rh_stock_measure_ready(\$pdo) && !empty(\$_POST['measure_present'])") !== false && strpos($si, '<?php if ($measureReady): ?>') !== false, 'Guarded: Stock Items saves and shows the measure only after migration 088');
check(strpos($sc, "'touched', 'mode', 'big', 'small']") !== false, 'Server reads mode / big / small from lines_json and line[...]');
check(strpos($sc, "['actual', 'cost', 'notes', 'big', 'small'].forEach") !== false && strpos($sc, 'row.mode = f.mode.value;') !== false, 'Page packs mode / big / small with the changed lines');

// Leak check
check((int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", [$like]) === 0, 'Leak: no test items left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM stock_counts WHERE reference LIKE ?", [$refLike]) === 0, 'Leak: no test counts left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM admin_users WHERE username LIKE ?", ['zztms' . strtolower($run) . '%']) === 0, 'Leak: no test users left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM admin_activity_log WHERE details LIKE ?", ['%ZZT MS ' . $run . ' %']) === 0, 'Leak: no test activity left behind');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
