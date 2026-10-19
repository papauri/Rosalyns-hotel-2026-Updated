<?php
/**
 * Stock budget test (.claude/plans/stock-budget.md).
 * Usage: php scripts/tests/stock_budget.php
 *
 * Covers spent / committed / left per category, uncategorised spend, pace and month-end forecast,
 * target-% of sales mode, the PO check on send (warn vs needs-approval, refused without manage permission,
 * allowed with a reason + audit), copy last month, suggest from the last 3 months and the audit rows.
 *
 * Runs on a far-future month (2031-03) with an injected "now", so no live data can leak into the numbers
 * (committed is the only live-wide figure and is read per test category). Everything runs in ONE
 * transaction that is rolled back - nothing is left in the live database. The schema (DDL) is made
 * before the transaction starts because DDL commits implicitly.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../admin/includes/stock-budget-lib.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function near($a, float $b): bool { return abs((float)$a - $b) <= BALANCE_TOLERANCE; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function line_of(array $c, string $cat): ?array { foreach ($c['lines'] as $l) { if ($l['category'] === $cat) return $l; } return null; }

if (!rh_sbud_ensure_schema($pdo)) { bad('Schema: budget tables exist'); echo "\n0 passed, 1 failed\n"; exit(1); }
ok('Schema: budget tables exist (idempotent)');
check(rh_sbud_ensure_schema($pdo), 'Schema: second call is a no-op');

$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1");
if (!$adminId) { bad('Fixture: an admin user exists'); echo "\n0 passed, 1 failed\n"; exit(1); }

$run = strtoupper(bin2hex(random_bytes(3)));
$catA = 'ZZT-A-' . $run;   // a category with a budget line
$catB = 'ZZT-B-' . $run;   // a category with a budget line (approval control)
$catC = 'ZZT-C-' . $run;   // a category with NO budget line
$ym = '2031-03';
$tz = new DateTimeZone(date_default_timezone_get());
$now = new DateTimeImmutable('2031-03-11 12:00:00', $tz);   // 10.5 of 31 days gone
$expectedPct = 10.5 / 31 * 100;

$pdo->beginTransaction();
try {
    $mkIng = function (string $cat, string $name) use ($pdo, $run): int {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, cost_per_unit) VALUES (?,?,'kg',10)")->execute([$name . ' ' . $run, $cat]);
        return (int)$pdo->lastInsertId();
    };
    $ingA = $mkIng($catA, 'Budget test A');
    $ingB = $mkIng($catB, 'Budget test B');
    $ingC = $mkIng($catC, 'Budget test C');
    $ingU = $mkIng('', 'Budget test uncategorised');
    $goodsIn = function (int $ing, float $total, string $at) use ($pdo) {
        $pdo->prepare("INSERT INTO stock_in_log (ingredient_id, quantity, cost_per_unit, cost_total, created_at) VALUES (?,1,?,?,?)")->execute([$ing, $total, $total, $at]);
    };
    $mkPo = function (string $status, array $lines) use ($pdo, $run, $adminId): int {
        static $n = 0; $n++;
        $pdo->prepare("INSERT INTO stock_purchase_orders (reference, status, order_date, created_by) VALUES (?,?,CURDATE(),?)")->execute(['ZZT' . $run . $n, $status, $adminId]);
        $id = (int)$pdo->lastInsertId();
        foreach ($lines as [$ing, $ord, $rec, $cost]) {
            $pdo->prepare("INSERT INTO stock_purchase_order_items (purchase_order_id, ingredient_id, description, ordered_qty, received_qty, unit_cost, line_total) VALUES (?,?,?,?,?,?,?)")
                ->execute([$id, $ing, 'line', $ord, $rec, $cost, round($ord * $cost, 2)]);
        }
        return $id;
    };

    // --- Saving budgets + audit ---------------------------------------------------------------
    $r = rh_sbud_save($pdo, $ym, [
        ['category' => $catA, 'mode' => 'fixed', 'amount' => '1000', 'control' => 'warn'],
        ['category' => $catB, 'mode' => 'fixed', 'amount' => '500', 'control' => 'approval'],
    ], $adminId, 'manual', $now);
    check($r['ok'] && $r['changed'] === 2, 'Save: two new lines saved', json_encode($r));
    check((int)col($pdo, "SELECT COUNT(*) FROM stock_budget_audit WHERE budget_month = ? AND action = 'set' AND category IN (?,?)", [$ym . '-01', $catA, $catB]) === 2, 'Audit: one "set" row per new line');
    $r = rh_sbud_save($pdo, $ym, [['category' => 'Not a category ' . $run, 'mode' => 'fixed', 'amount' => '5']], $adminId, 'manual', $now);
    check(!$r['ok'], 'Save: a category that is not an ingredient category is refused');
    $r = rh_sbud_save($pdo, $ym, [['category' => $catA, 'mode' => 'target_pct', 'target_pct' => '150']], $adminId, 'manual', $now);
    check(!$r['ok'], 'Save: target % above 100 is refused');
    $r = rh_sbud_save($pdo, '2020-01', [['category' => $catA, 'mode' => 'fixed', 'amount' => '5']], $adminId, 'manual', $now);
    check(!$r['ok'], 'Save: a past month is closed');

    // --- Spent / committed / left -------------------------------------------------------------
    $goodsIn($ingA, 300, '2031-03-03 10:00:00');
    $goodsIn($ingA, 100.50, '2031-03-09 10:00:00');
    $goodsIn($ingA, 999, '2031-02-28 23:30:00');     // last month: must not count
    $goodsIn($ingA, 999, '2031-04-01 00:30:00');     // next month: must not count
    $goodsIn($ingB, 200, '2031-03-05 09:00:00');
    $goodsIn($ingC, 70, '2031-03-06 09:00:00');      // category with no budget line
    $goodsIn($ingU, 30, '2031-03-06 09:00:00');      // uncategorised ingredient
    $spent = rh_sbud_spent_by_category($pdo, $ym, $now);
    check(near($spent[rh_sbud_cat_key($catA)] ?? 0, 400.50), 'Spent: goods-in inside the month only (hotel timezone edges excluded)', json_encode($spent));
    check(near($spent[''] ?? 0, 30), 'Spent: uncategorised ingredients are grouped on their own');

    $po1 = $mkPo('sent', [[$ingA, 10, 4, 20]]);      // outstanding 6 x 20 = 120
    $po2 = $mkPo('partial', [[$ingA, 5, 7, 10]]);     // over-received: outstanding clamps to 0
    $po3 = $mkPo('draft', [[$ingA, 100, 0, 100]]);    // draft: not committed
    $po4 = $mkPo('cancelled', [[$ingA, 100, 0, 100]]);// cancelled: not committed
    $c = rh_sbud_compute($pdo, $ym, ['now' => $now]);
    $a = line_of($c, $catA);
    check($a && near($a['spent'], 400.50), 'Line A: spent', json_encode($a));
    // Committed is counted for the CURRENT month only; the test month is "current" via the injected now.
    $commA = (float)(rh_sbud_committed_by_category($pdo)[rh_sbud_cat_key($catA)] ?? 0);
    check(near($commA, 120), 'Committed: sent + part-received outstanding only (draft, cancelled and over-received add nothing)', (string)$commA);
    check($a && near($a['committed'], 120) && near($a['left'], 1000 - 400.50 - 120), 'Line A: left = budget - spent - committed', json_encode($a));
    $b = line_of($c, $catB);
    check($b && near($b['spent'], 200) && near($b['committed'], 0) && near($b['left'], 300), 'Line B: spent / left', json_encode($b));
    check(near($c['other']['spent'], 100), 'Uncategorised + unbudgeted spend shows in "Other" (70 + 30)', json_encode($c['other']));
    check($c['summary']['basis'] === 'categories' && near($c['summary']['budget'], 1500) && near($c['summary']['spent'], 600.50), 'Summary: category lines added up', json_encode($c['summary']));
    $pastC = rh_sbud_compute($pdo, '2031-02', ['now' => $now]);
    check(near($pastC['total']['committed'], 0), 'Committed is not shown against a past month');

    // --- Pace and forecast --------------------------------------------------------------------
    check(abs($c['info']['month_pct'] - $expectedPct) < 0.01, 'Pace: share of the month gone', (string)$c['info']['month_pct']);
    $fc = rh_sbud_forecast($c['info'], 400.50, 120);
    check(near($fc, 400.50 / (10.5 / 31)), 'Forecast: current run rate to month end', (string)$fc);
    check(near((float)rh_sbud_forecast($c['info'], 10, 500), 510), 'Forecast: never below spent + committed');
    $early = rh_sbud_month_info($ym, new DateTimeImmutable('2031-03-02 12:00:00', $tz));
    check(rh_sbud_forecast($early, 50, 0) === null, 'Forecast: too early in the month (under 3 days) gives none');
    check(near((float)rh_sbud_forecast(rh_sbud_month_info('2031-02', $now), 77, 0), 77), 'Forecast: a past month is just what was spent');
    // 400.50 over 10.5 days -> ~1182 for the month, above the 1000 budget -> "ahead"; but used (520.5) is under 80%
    check($a['status'] === 'ahead', 'Status: under 80% used but forecast above budget = ahead of pace', $a['status']);
    check($b['status'] === 'ok' || $b['status'] === 'ahead', 'Status: line B is not over', $b['status']);
    check(rh_sbud_line_status(100, 80.0, null, null) === 'near' && rh_sbud_line_status(100, 100.005, null, null) === 'ok' || rh_sbud_line_status(100, 100.005, null, null) === 'near', 'Status: 80% or more = near; tolerance applies at 100%');
    check(rh_sbud_line_status(100, 100.02, null, null) === 'over', 'Status: beyond tolerance = over');
    check(rh_sbud_line_status(0, 0, null, null) === 'none', 'Status: no budget and no spend = none');

    // --- Whole-stock cap ----------------------------------------------------------------------
    $r = rh_sbud_save($pdo, $ym, [
        ['category' => $catA, 'mode' => 'fixed', 'amount' => '1000', 'control' => 'warn'],
        ['category' => $catB, 'mode' => 'fixed', 'amount' => '500', 'control' => 'approval'],
        ['category' => RH_SBUD_ALL, 'mode' => 'fixed', 'amount' => '100000', 'control' => 'warn'],
    ], $adminId, 'manual', $now);
    $c2 = rh_sbud_compute($pdo, $ym, ['now' => $now]);
    check($c2['summary']['basis'] === 'whole' && near($c2['summary']['budget'], 100000), 'Summary: the whole-stock cap is used when it exists');
    check(near($c2['summary']['spent'], $c2['total']['spent']), 'Whole-stock cap counts every category, budgeted or not');
    $r = rh_sbud_save($pdo, $ym, [
        ['category' => $catA, 'mode' => 'fixed', 'amount' => '1000', 'control' => 'warn'],
        ['category' => $catB, 'mode' => 'fixed', 'amount' => '500', 'control' => 'approval'],
    ], $adminId, 'manual', $now);
    check($r['ok'] && $r['changed'] === 1, 'Save: leaving a line out removes it (1 change)', json_encode($r));
    check((int)col($pdo, "SELECT COUNT(*) FROM stock_budget_audit WHERE budget_month = ? AND action = 'removed' AND category = ?", [$ym . '-01', RH_SBUD_ALL]) === 1, 'Audit: removal recorded');

    // --- Detail drawer data ------------------------------------------------------------------
    $d = rh_sbud_detail($pdo, $ym, $catA, $now);
    check(count($d['receipts']) === 2 && count($d['pos']) === 1 && (int)$d['pos'][0]['id'] === $po1, 'Detail: receipts and open POs behind a line', json_encode([count($d['receipts']), count($d['pos'])]));

    // --- Target-% of sales mode ---------------------------------------------------------------
    rh_sbud_sales_provider(function ($pdo, $m, $lastDay) { return 10000.0; });
    $r = rh_sbud_save($pdo, $ym, [
        ['category' => $catA, 'mode' => 'target_pct', 'target_pct' => '12.5', 'control' => 'warn'],
        ['category' => $catB, 'mode' => 'fixed', 'amount' => '500', 'control' => 'approval'],
    ], $adminId, 'manual', $now);
    check($r['ok'], 'Save: target % line accepted', json_encode($r));
    $c3 = rh_sbud_compute($pdo, $ym, ['now' => $now]);
    $a3 = line_of($c3, $catA);
    $proj = round(10000 / (10.5 / 31), 2);   // mid-month (day 11): so far / days elapsed x days in month
    check($c3['sales']['basis'] === 'month' && near($c3['sales']['expected'], $proj), 'Target %: mid-month expected sales = so far / days elapsed x days in month', json_encode($c3['sales']));
    check($a3 && near($a3['budget'], round(0.125 * $proj, 2)) && near($a3['forecast_budget'], $a3['budget']), 'Target %: budget = 12.5% of expected sales for the whole month', json_encode($a3));
    check(stripos(rh_sbud_sales_basis_text(12.5, $c3['sales'], 'MWK'), 'based on this month so far') !== false, 'Target %: basis wording says "this month so far"');
    // Day 2: under 4 days elapsed -> last month's actual total
    $day2 = new DateTimeImmutable('2031-03-02 12:00:00', $tz);
    rh_sbud_sales_provider(function ($pdo, $m, $lastDay) { return $m === '2031-03' ? 500.0 : 40000.0; });
    $c4 = rh_sbud_compute($pdo, $ym, ['now' => $day2]);
    check($c4['sales']['basis'] === 'last_month' && near($c4['sales']['expected'], 40000) && near(line_of($c4, $catA)['budget'], 5000), 'Target %: days 1-3 use last month\'s actual sales', json_encode($c4['sales']));
    check(stripos(rh_sbud_sales_basis_text(12.5, $c4['sales'], 'MWK'), 'based on last month') !== false, 'Target %: basis wording says "last month"');
    // Day 11 but no sales yet this month -> last month too
    rh_sbud_sales_provider(function ($pdo, $m, $lastDay) { return $m === '2031-03' ? 0.0 : 20000.0; });
    $c5 = rh_sbud_compute($pdo, $ym, ['now' => $now]);
    check($c5['sales']['basis'] === 'last_month' && near($c5['sales']['expected'], 20000), 'Target %: no sales yet this month falls back to last month', json_encode($c5['sales']));
    // Past month: its actual total
    $pdo->prepare("INSERT INTO stock_budgets (budget_month, category, mode, amount, target_pct, control) VALUES ('2031-02-01', ?, 'target_pct', 0, 10, 'warn')")->execute([$catA]);
    rh_sbud_sales_provider(function ($pdo, $m, $lastDay) { return $m === '2031-02' ? 30000.0 : 1.0; });
    $c6 = rh_sbud_compute($pdo, '2031-02', ['now' => $now]);
    check($c6['sales']['basis'] === 'actual' && near($c6['sales']['expected'], 30000) && near(line_of($c6, $catA)['budget'], 3000), 'Target %: a past month uses its actual sales', json_encode($c6['sales']));
    check((int)col($pdo, "SELECT COUNT(*) FROM stock_budget_audit WHERE budget_month = ? AND action = 'changed' AND category = ? AND new_value LIKE '12.5% of F&B sales%'", [$ym . '-01', $catA]) === 1, 'Audit: mode change written with old and new text');
    rh_sbud_sales_provider(null);

    // --- PO check on send: warn vs needs-approval ---------------------------------------------
    $rb = rh_sbud_save($pdo, $ym, [
        ['category' => $catA, 'mode' => 'fixed', 'amount' => '1000', 'control' => 'warn'],
        ['category' => $catB, 'mode' => 'fixed', 'amount' => '500', 'control' => 'approval'],
    ], $adminId, 'manual', $now);
    // A: left is 479.50. B: left is 300.
    $poOk = $mkPo('draft', [[$ingA, 10, 0, 40]]);                 // 400 -> A left 79.50, inside budget
    $e = rh_sbud_po_effect($pdo, $poOk, $now);
    check($e['applies'] && !$e['over'] && near($e['rows'][0]['left_before'], 479.50) && near($e['rows'][0]['left_after'], 79.50), 'PO effect: left before -> after, inside budget', json_encode($e['rows']));
    $x = rh_sbud_authorize_po($pdo, $poOk, $adminId, false, false, '', $now);
    check($x['ok'] && !$x['overridden'], 'Send: inside the budget needs nothing');

    $poWarn = $mkPo('draft', [[$ingA, 10, 0, 60]]);               // 600 > 479.50 -> over on a warn line
    $x = rh_sbud_authorize_po($pdo, $poWarn, $adminId, false, false, '', $now);
    check(!$x['ok'] && $x['effect']['over'] && !$x['effect']['needs_approval'], 'Warn line: refused until confirmed', $x['error']);
    $x = rh_sbud_authorize_po($pdo, $poWarn, $adminId, false, true, '', $now);
    check($x['ok'] && $x['overridden'], 'Warn line: allowed once confirmed, no manage permission needed', $x['error']);
    check(near((float)col($pdo, "SELECT COUNT(*) FROM stock_purchase_orders WHERE id = ? AND budget_override_by = ? AND budget_override_at IS NOT NULL", [$poWarn, $adminId]), 1), 'Warn line: override stored on the PO');

    $poApr = $mkPo('draft', [[$ingB, 10, 0, 40]]);                // 400 > 300 -> over on an approval line
    $x = rh_sbud_authorize_po($pdo, $poApr, $adminId, false, true, 'I need it', $now);
    check(!$x['ok'] && $x['effect']['needs_approval'] && stripos($x['error'], 'budget manager') !== false, 'Approval line: refused without the manage permission, even confirmed with a reason', $x['error']);
    check((int)col($pdo, "SELECT COUNT(*) FROM stock_purchase_orders WHERE id = ? AND budget_override_at IS NOT NULL", [$poApr]) === 0, 'Approval line: a refusal changes nothing on the PO');
    $x = rh_sbud_authorize_po($pdo, $poApr, $adminId, true, true, '', $now);
    check(!$x['ok'] && stripos($x['error'], 'reason') !== false, 'Approval line: a manager must give a reason', $x['error']);
    $x = rh_sbud_authorize_po($pdo, $poApr, $adminId, true, false, 'Supplier price rise, event on Saturday', $now);
    check($x['ok'] && $x['overridden'], 'Approval line: a manager with a reason may send', $x['error']);
    $row = $pdo->prepare("SELECT budget_override_reason, budget_override_by FROM stock_purchase_orders WHERE id = ?");
    $row->execute([$poApr]);
    $row = $row->fetch(PDO::FETCH_ASSOC);
    check($row && $row['budget_override_reason'] === 'Supplier price rise, event on Saturday' && (int)$row['budget_override_by'] === $adminId, 'Approval line: reason and approver stored on the PO');
    check((int)col($pdo, "SELECT COUNT(*) FROM stock_budget_audit WHERE action = 'po_override' AND purchase_order_id = ? AND reason = ? AND user_id = ?", [$poApr, 'Supplier price rise, event on Saturday', $adminId]) === 1, 'Approval line: override audited with the PO, reason and user');

    // A sent PO is not counted twice when it is checked again
    $pdo->prepare("UPDATE stock_purchase_orders SET status = 'sent' WHERE id = ?")->execute([$poOk]);
    $e2 = rh_sbud_po_effect($pdo, $poOk, $now);
    check(near($e2['rows'][0]['left_before'], 479.50), 'PO effect: a PO already sent is left out of its own "before"', json_encode($e2['rows']));

    // --- Copy last month / suggest from last 3 months -----------------------------------------
    $next = '2031-04';
    $pdo->prepare("INSERT INTO stock_budgets (budget_month, category, mode, amount, target_pct, control) VALUES (?,?,?,?,?,?)")
        ->execute(['2031-03-01', $catC, 'fixed', 250, 0, 'approval']);
    $cp = rh_sbud_copy_proposal($pdo, $next);
    $hit = null; foreach ($cp as $p) { if ($p['category'] === $catC) $hit = $p; }
    check($hit && near($hit['amount'], 250) && $hit['control'] === 'approval' && count($cp) === 3, 'Copy last month: proposes last month\'s lines, nothing saved', json_encode($cp));
    check((int)col($pdo, "SELECT COUNT(*) FROM stock_budgets WHERE budget_month = ?", [$next . '-01']) === 0, 'Copy last month: proposal alone writes nothing');
    $r = rh_sbud_save($pdo, $next, $cp, $adminId, 'copy', $now);
    check($r['ok'] && $r['changed'] === 3 && (int)col($pdo, "SELECT COUNT(*) FROM stock_budget_audit WHERE budget_month = ? AND source = 'copy'", [$next . '-01']) === 3, 'Copy last month: saved and audited as "copy"', json_encode($r));

    // Suggest for 2031-06 from March..May: A = 400.50 in March only, others none. Add April and May spend.
    $goodsIn($ingA, 600, '2031-04-10 10:00:00');
    $goodsIn($ingA, 500, '2031-05-10 10:00:00');
    $sg = rh_sbud_suggest_proposal($pdo, '2031-06');
    $sa = null; foreach ($sg as $p) { if ($p['category'] === $catA) $sa = $p; }
    // March 400.50 + April (999 on the 1st + 600) + May 500 = 2499.50 over 3 months = 833.17 -> 833
    check($sa && near($sa['amount'], 833) && $sa['mode'] === 'fixed', 'Suggest: average of the last 3 months per category', json_encode($sa));
    $sb = null; foreach ($sg as $p) { if ($p['category'] === $catB) $sb = $p; }
    check($sb && near($sb['amount'], round(200 / 3, 0)), 'Suggest: a category with goods-in in one month averages over the months that had any', json_encode($sb));
    check(rh_sbud_suggest_proposal($pdo, '2035-06') === [], 'Suggest: no history means no suggestion');
    $r = rh_sbud_save($pdo, '2031-06', $sg, $adminId, 'suggest', $now);
    check($r['ok'] && (int)col($pdo, "SELECT COUNT(*) FROM stock_budget_audit WHERE budget_month = '2031-06-01' AND source = 'suggest'") === count($sg) && $sg !== [], 'Suggest: saved and audited as "suggest"');

    // --- History ---------------------------------------------------------------------------
    $h = rh_sbud_history($pdo, '2031-04', 2);
    check(count($h) === 2 && $h[0]['ym'] === '2031-03' && $h[0]['has_budget'] && $h[0]['variance'] !== null, 'History: previous months listed newest first with a budget');

    // --- Dashboard summary -----------------------------------------------------------------
    $dash = rh_sbud_dashboard($pdo, $now);
    check($dash !== null && $dash['ym'] === $ym && $dash['worst'] !== null, 'Dashboard: this month\'s summary with the worst line');

    // --- Non-stock PO lines count as received (uncategorised) ------------------------------
    $before = (float)(rh_sbud_spent_by_category($pdo, $ym, $now)[''] ?? 0);
    rh_sbud_record_nonstock_receipt($pdo, 999999, 999999, 'Cleaning cloths', 4, 12.5, $adminId);
    $pdo->prepare("UPDATE stock_nonstock_receipts SET created_at = '2031-03-05 10:00:00' WHERE purchase_order_id = 999999")->execute();
    $after = (float)(rh_sbud_spent_by_category($pdo, $ym, $now)[''] ?? 0);
    check(near($after - $before, 50.0), 'Non-stock PO line received counts as uncategorised spend', "before $before after $after");
    $det = rh_sbud_detail($pdo, $ym, RH_SBUD_ALL, $now);
    check((bool)array_filter($det['receipts'] ?? [], fn($x) => str_contains((string)$x['name'], '(non-stock)')), 'Non-stock receipt listed in the whole-stock detail');
} catch (Throwable $e) {
    bad('Unexpected error', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
rh_sbud_sales_provider(null);
if ($pdo->inTransaction()) { $pdo->rollBack(); }
check((int)col($pdo, "SELECT COUNT(*) FROM stock_budgets WHERE category LIKE 'ZZT-%'") === 0, 'Rollback: no test budget lines left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ['%' . $run]) === 0, 'Rollback: no test ingredients left behind');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
