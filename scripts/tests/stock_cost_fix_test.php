<?php
/**
 * Stock > Fix crate prices (admin/includes/stock-cost-fix-lib.php).
 * Usage: php scripts/tests/stock_cost_fix_test.php   (TEST PLATFORM ONLY: prints SKIP and exits 0 unless site_is_test_platform = 1)
 *
 * Apply runs its own transaction (the real path), so the fixtures are committed rows, all tagged with a run id: their own
 * category (no effect on the mirror's case-price clusters), batch numbers and names. They are deleted in finally, with the
 * activity-log rows they wrote, and the test checks nothing is left. No existing item is ever passed to apply.
 *
 * Covers: N parsing (N < 2 refused), preview numbers, 2 active + 1 used batch (only the active ones divided), a stale preview
 * refused, all-or-nothing, a second apply refused (also for a tiny cost where BALANCE_TOLERANCE would wrongly pass),
 * unit / quantity / used batch unchanged, the log row, the CSV prefill (header below a title row, case + outer spaces,
 * unmatched, Y skipped, bad N, two items with one name), and the clean-up.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/form-validation.php';
require_once __DIR__ . '/../../admin/includes/stock-cost-fix-lib.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function same4($a, float $b): bool { return abs((float)$a - $b) < 0.00005; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

if ((string)getSetting('site_is_test_platform') !== '1') {
    echo "SKIP: this test writes (and then deletes) rows; it runs only on the test platform (Liwonde).\n";
    exit(0);
}

$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1");
$run = 'ZZT-SCF-' . strtoupper(bin2hex(random_bytes(3)));
$cat = $run;
$ids = [];

// ---- pure helpers ----
check(rh_scf_parse_n('1') === null && rh_scf_parse_n('0') === null && rh_scf_parse_n('-3') === null, 'N: 1, 0 and negative refused');
check(rh_scf_parse_n('2.5') === null && rh_scf_parse_n('abc') === null && rh_scf_parse_n('') === null, 'N: fraction, text and blank refused');
check(rh_scf_parse_n('24') === 24 && rh_scf_parse_n(' 24.0 ') === 24 && rh_scf_parse_n(12) === 12, 'N: whole numbers accepted');
check(rh_scf_clean_pack('') === 'crate' && rh_scf_clean_pack('  case ') === 'case' && rh_scf_clean_pack(str_repeat('x', 31)) === null, 'Pack name: blank = crate, trimmed, max 30');
[$l0, $e0] = rh_scf_lines_from_json('[{"id":5,"n":"24","pack":""},{"id":6,"n":""},{"id":5,"n":"12"}]', false);
check(count($l0) === 1 && isset($l0[5]) && count($e0) === 1, 'Lines: blank N dropped, a repeated item is refused');

try {
    // ---- fixtures (committed: apply opens its own transaction) ----
    $mkIng = function (string $name, string $unit, float $qty, float $cost) use ($pdo, $cat, &$ids): int {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point, min_quantity)
                       VALUES (?, ?, ?, ?, ?, 48, 12, 6)")->execute([$name, $cat, $unit, $qty, $cost]);
        $id = (int)$pdo->lastInsertId();
        $ids[] = $id;
        return $id;
    };
    $mkBatch = function (int $ing, string $no, float $recv, float $left, float $cost, string $status) use ($pdo): int {
        $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status)
                       VALUES (?, ?, ?, ?, ?, CURDATE(), ?)")->execute([$ing, $no, $recv, $left, $cost, $status]);
        return (int)$pdo->lastInsertId();
    };
    $nameA = 'Crate test lager ' . $run;
    $nameB = 'Tiny cost soda ' . $run;
    $nameC = 'Never divide ' . $run;
    $A = $mkIng($nameA, 'bottle', 60, 41322.31);
    $B1 = $mkBatch($A, $run . '-A1', 48, 40, 41322.31, 'active');
    $B2 = $mkBatch($A, $run . '-A2', 24, 20, 41322.31, 'active');
    $B3 = $mkBatch($A, $run . '-A3', 24, 0, 41322.31, 'depleted');
    $B = $mkIng($nameB, 'can', 10, 0.02);
    $mkBatch($B, $run . '-B1', 10, 10, 0.02, 'active');
    $C = $mkIng($nameC, 'bottle', 5, 900);
    $logCount = function (int $id) use ($pdo, $run): int {
        return (int)col($pdo, "SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_cost_correction' AND details LIKE ? AND details LIKE ?",
            ['%' . $run . '%', '%item #' . $id . '.%']);
    };
    $cost = function (int $id) use ($pdo): float { return (float)col($pdo, "SELECT cost_per_unit FROM stock_ingredients WHERE id = ?", [$id]); };
    $bcost = function (int $id) use ($pdo): float { return (float)col($pdo, "SELECT cost_per_unit FROM stock_batches WHERE id = ?", [$id]); };

    // ---- preview ----
    $pv = rh_scf_preview($pdo, [$A => ['id' => $A, 'n' => '24', 'pack' => '']]);
    $row = $pv['rows'][0] ?? null;
    check(!$pv['errors'] && $row !== null, 'Preview: no errors');
    check($row && same4($row['new_cost'], 1721.7629) && same4($row['old_cost'], 41322.31), 'Preview: 41,322.31 / 24 = 1,721.7629 (4 dp)', json_encode($row['new_cost'] ?? null));
    check($row && abs($row['old_value'] - 2479338.60) < BALANCE_TOLERANCE && abs($row['new_value'] - 103305.77) < BALANCE_TOLERANCE, 'Preview: value 2,479,338.60 -> 103,305.77');
    check($row && count($row['batches']) === 2 && !in_array($B3, array_column($row['batches'], 'id'), true), 'Preview: the 2 active batches listed, the used one not');
    check(abs($pv['before'] - 2479338.60) < BALANCE_TOLERANCE && abs($pv['after'] - 103305.77) < BALANCE_TOLERANCE, 'Preview: totals before -> after');
    check(same4($cost($A), 41322.31), 'Preview changes nothing');

    // ---- refusals (nothing changes) ----
    $r = rh_scf_apply($pdo, [$A => ['id' => $A, 'n' => '24', 'pack' => '', 'old' => '40000.0000']], $adminId, 'scf-test');
    check(!$r['ok'] && strpos(implode(' ', $r['errors']), $nameA) !== false, 'Stale preview refused and the item named');
    check(same4($cost($A), 41322.31) && same4($bcost($B1), 41322.31) && $logCount($A) === 0, 'Stale: cost, batches and log unchanged');

    $r = rh_scf_apply($pdo, [$C => ['id' => $C, 'n' => '1', 'pack' => '', 'old' => '900.0000']], $adminId, 'scf-test');
    check(!$r['ok'] && strpos(implode(' ', $r['errors']), $nameC) !== false && same4($cost($C), 900), 'N < 2 refused and named, cost unchanged');

    $r = rh_scf_apply($pdo, [
        $A => ['id' => $A, 'n' => '24', 'pack' => '', 'old' => '41322.3100'],
        $C => ['id' => $C, 'n' => '0', 'pack' => '', 'old' => '900.0000'],
    ], $adminId, 'scf-test');
    check(!$r['ok'] && same4($cost($A), 41322.31) && same4($bcost($B2), 41322.31), 'All or nothing: one refused item stops the good one too');

    // ---- apply ----
    $payA = [$A => ['id' => $A, 'n' => '24', 'pack' => '', 'old' => '41322.3100']];
    $r = rh_scf_apply($pdo, $payA, $adminId, 'scf-test');
    check($r['ok'], 'Apply: ok', implode(' | ', $r['errors']));
    $it = $pdo->prepare("SELECT unit, current_quantity, cost_per_unit, pack_name, pack_size, par_level, reorder_point, min_quantity FROM stock_ingredients WHERE id = ?");
    $it->execute([$A]);
    $it = $it->fetch(PDO::FETCH_ASSOC);
    check(same4($it['cost_per_unit'], 1721.7629) && $it['pack_name'] === 'crate' && abs((float)$it['pack_size'] - 24) < 0.0005, 'Apply: item cost 1,721.7629, crate of 24');
    check($it['unit'] === 'bottle' && abs((float)$it['current_quantity'] - 60) < 0.000001 && abs((float)$it['par_level'] - 48) < 0.000001
        && abs((float)$it['reorder_point'] - 12) < 0.000001 && abs((float)$it['min_quantity'] - 6) < 0.000001, 'Apply: unit, quantity and levels unchanged');
    check(same4($bcost($B1), 1721.7629) && same4($bcost($B2), 1721.7629), 'Apply: both active batches divided');
    check(same4($bcost($B3), 41322.31), 'Apply: the used batch keeps its number (history)');
    check($logCount($A) === 1, 'Apply: one stock_cost_correction log row');
    $det = (string)col($pdo, "SELECT details FROM admin_activity_log WHERE action = 'stock_cost_correction' AND details LIKE ? ORDER BY id DESC LIMIT 1", ['%item #' . $A . '.%']);
    check(strpos($det, $nameA) === 0 && strpos($det, '41,322.31 → 1,721.7629') !== false && strpos($det, 'crate of 24') !== false
        && strpos($det, '#' . $B1) !== false && strpos($det, '#' . $B2) !== false && strpos($det, '#' . $B3 . ' ') === false
        && strpos($det, '2,479,338.60 → 103,305.77') !== false, 'Log: name first, old -> new, N, pack, batch ids, value before -> after', $det);

    // ---- second apply ----
    $r = rh_scf_apply($pdo, $payA, $adminId, 'scf-test');
    check(!$r['ok'] && strpos(implode(' ', $r['errors']), $nameA) !== false, 'Second apply refused and named');
    check(same4($cost($A), 1721.7629) && same4($bcost($B1), 1721.7629) && $logCount($A) === 1, 'Second apply: nothing divided twice, no second log row');

    // Tiny cost: 0.02 -> 0.01 is within BALANCE_TOLERANCE of 0.02, so only the explicit 4-dp tolerance refuses the repeat.
    $payB = [$B => ['id' => $B, 'n' => '2', 'pack' => 'six-pack', 'old' => '0.0200']];
    $r = rh_scf_apply($pdo, $payB, $adminId, 'scf-test');
    check($r['ok'] && same4($cost($B), 0.01), 'Tiny cost: 0.02 / 2 = 0.01 applied');
    $r = rh_scf_apply($pdo, $payB, $adminId, 'scf-test');
    check(!$r['ok'] && same4($cost($B), 0.01), 'Tiny cost: second apply refused');
    check(!rh_scf_apply($pdo, [], $adminId)['ok'], 'Empty apply refused');

    // ---- an item on a count not yet approved is refused (approving it later would bring the old cost back) ----
    $nameD = 'On open count ' . $run;
    $D = $mkIng($nameD, 'bottle', 0, 50420);
    $pdo->prepare("INSERT INTO stock_counts (reference, count_date, shift, scope, scope_value, status, counted_by, notes) VALUES (?, CURDATE(), NULL, 'spot', NULL, 'draft', ?, NULL)")
        ->execute([$run . '-SC', $adminId]);
    $scId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost) VALUES (?, ?, 0, 60, 60, 50420, 3025200)")
        ->execute([$scId, $D]);
    $r = rh_scf_apply($pdo, [$D => ['id' => $D, 'n' => '20', 'pack' => '', 'old' => '50420.0000']], $adminId, 'scf-test');
    check(!$r['ok'] && strpos(implode(' ', $r['errors']), $run . '-SC (draft)') !== false && same4($cost($D), 50420) && $logCount($D) === 0,
        'Open count: refused, count named, cost unchanged', implode(' | ', $r['errors']));
    $pdo->prepare("UPDATE stock_counts SET status = 'approved' WHERE id = ?")->execute([$scId]);
    $r = rh_scf_apply($pdo, [$D => ['id' => $D, 'n' => '20', 'pack' => '', 'old' => '50420.0000']], $adminId, 'scf-test');
    check($r['ok'] && same4($cost($D), 2521), 'Approved count: correction allowed (50,420 / 20 = 2,521)', implode(' | ', $r['errors']));

    // ---- CSV prefill (pure: items passed in) ----
    $items = [['id' => $A, 'name' => $nameA], ['id' => $B, 'name' => $nameB], ['id' => $C, 'name' => $nameC],
              ['id' => 900000001, 'name' => 'Twin ' . $run], ['id' => 900000002, 'name' => 'twin ' . $run . ' ']];
    $csv = "Crate price list,,\n"
        . "Item,Units in one crate/case (FILL IN),Real per-unit price? (Y if the price is for one)\n"
        . '"  ' . strtoupper($nameA) . '  ",24,' . "\n"
        . $nameC . ",12,Y\n"
        . 'Nope ' . $run . ",6,\n"
        . $nameB . ",1,\n"
        . 'Twin ' . $run . ",6,\n"
        . "Blank row item,,\n";
    $p = rh_scf_csv_prefill($csv, $items);
    check($p['ok'] && $p['fill'] === [$A => 24], 'CSV: header found under a title row; matched ignoring case and outer spaces', json_encode($p['fill']));
    check($p['unmatched'] === ['Nope ' . $run], 'CSV: unmatched names listed', json_encode($p['unmatched']));
    check($p['real'] === [$nameC] && $p['bad'] === [$nameB] && $p['ambiguous'] === ['Twin ' . $run], 'CSV: Y skipped, bad N listed, a shared name not filled');
    $p2 = rh_scf_csv_prefill("Name,Qty\nx,2\n", $items);
    check(!$p2['ok'] && $p2['error'] !== '', 'CSV: missing columns refused with a message');
    $p3 = rh_scf_csv_prefill("Item;Units in one crate/case (FILL IN)\n" . $nameA . ";12\n", $items);
    check($p3['ok'] && $p3['fill'] === [$A => 12], 'CSV: semicolon file read too');
} catch (Throwable $e) {
    bad('Unexpected error', $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->prepare("DELETE FROM admin_activity_log WHERE action = 'stock_cost_correction' AND details LIKE ?")->execute(['%' . $run . '%']);
    $pdo->prepare("DELETE l FROM stock_count_lines l JOIN stock_counts sc ON sc.id = l.count_id WHERE sc.reference = ?")->execute([$run . '-SC']);
    $pdo->prepare("DELETE FROM stock_counts WHERE reference = ?")->execute([$run . '-SC']);
    $pdo->prepare("DELETE FROM stock_batches WHERE batch_number LIKE ?")->execute([$run . '-%']);
    $pdo->prepare("DELETE FROM stock_ingredients WHERE category = ?")->execute([$cat]);
}
$left = (int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE category = ?", [$cat])
      + (int)col($pdo, "SELECT COUNT(*) FROM stock_batches WHERE batch_number LIKE ?", [$run . '-%'])
      + (int)col($pdo, "SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_cost_correction' AND details LIKE ?", ['%' . $run . '%'])
      + (int)col($pdo, "SELECT COUNT(*) FROM stock_counts WHERE reference = ?", [$run . '-SC']);
check($left === 0, 'Clean-up: no fixture, batch or log row left');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
