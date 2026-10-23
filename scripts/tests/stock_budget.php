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
    check(rh_sbud_forecast($early, 50, 0) === null, 'Forecast: too early in the month (day 2) gives none');
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

    // --- Finding 1: sales for a % budget are measured BEFORE VAT -------------------------------
    $savedSet = [];
    foreach (['vat_enabled', 'vat_rate', 'vat_pricing_mode'] as $k) { $savedSet[$k] = $GLOBALS['_SITE_SETTINGS'][$k] ?? null; }
    $setVat = function (string $enabled, string $rate, string $mode) { $GLOBALS['_SITE_SETTINGS']['vat_enabled'] = $enabled; $GLOBALS['_SITE_SETTINGS']['vat_rate'] = $rate; $GLOBALS['_SITE_SETTINGS']['vat_pricing_mode'] = $mode; };
    $setVat('1', '16.5', 'inclusive');
    check(vat_mode() === 'inclusive' && near(rh_sbud_ex_vat(116.5), 100.0), 'Ex-VAT: "prices include VAT" takes 16.5% VAT out of the till figure', (string)rh_sbud_ex_vat(116.5));
    $setVat('1', '16.5', 'exclusive');
    check(vat_mode() === 'exclusive' && near(rh_sbud_ex_vat(116.5), 100.0), 'Ex-VAT: "VAT added on top" - F&B till prices are still gross, VAT taken out', (string)rh_sbud_ex_vat(116.5));
    $setVat('0', '16.5', 'inclusive');
    check(vat_mode() === 'off' && near(rh_sbud_ex_vat(116.5), 116.5), 'Ex-VAT: "No VAT" leaves the figure as it is', (string)rh_sbud_ex_vat(116.5));
    check(near(rh_sbud_ex_vat(0), 0.0) && near(rh_sbud_ex_vat(-5), -5.0), 'Ex-VAT: zero / negative amounts pass through');
    $setVat('1', '16.5', 'inclusive');
    check(near(rh_sbud_ex_vat(-116.5), -100.0) && near(rh_sbud_ex_vat(0), 0.0), 'Ex-VAT: a negative day (refunds bigger than sales) has VAT taken out too, zero stays zero', (string)rh_sbud_ex_vat(-116.5));
    // rh_pos_margin_basis follows the same VAT switch as vat_mode(): "yes" / case variants count as on.
    foreach (['1', 'yes', 'YES', 'On', 'true'] as $onVal) {
        $setVat($onVal, '16.5', 'inclusive');
        check(near(rh_pos_margin_basis(116.5, 0.0)['net_ex_vat'], 100.0), 'Margin basis: vat_enabled "' . $onVal . '" takes VAT out', (string)rh_pos_margin_basis(116.5, 0.0)['net_ex_vat']);
    }
    foreach (['0', '', 'no', 'false'] as $offVal) {
        $setVat($offVal, '16.5', 'inclusive');
        check(near(rh_pos_margin_basis(116.5, 0.0)['net_ex_vat'], 116.5), 'Margin basis: vat_enabled "' . $offVal . '" leaves the amount as is');
    }
    foreach ($savedSet as $k => $v) { if ($v === null) { unset($GLOBALS['_SITE_SETTINGS'][$k]); } else { $GLOBALS['_SITE_SETTINGS'][$k] = $v; } }
    check(stripos(rh_sbud_sales_basis_text(30, ['basis' => 'month', 'expected' => 1000000.0, 'has_basis' => true], 'MWK'), 'of expected sales before VAT') !== false, 'Target %: basis wording says "sales before VAT"');

    // --- Finding 2: a % line with no sales basis is "no sales basis yet", never over ----------------
    rh_sbud_sales_provider(function ($pdo, $m, $lastDay) { return 0.0; });
    $r = rh_sbud_save($pdo, $ym, [
        ['category' => $catA, 'mode' => 'target_pct', 'target_pct' => '30', 'control' => 'approval'],
        ['category' => $catB, 'mode' => 'fixed', 'amount' => '500', 'control' => 'warn'],
    ], $adminId, 'manual', $now);
    $cn = rh_sbud_compute($pdo, $ym, ['now' => $now]);
    $an = line_of($cn, $catA);
    check($cn['sales'] && $cn['sales']['basis'] === 'none' && !$cn['sales']['has_basis'], 'No basis: no sales anywhere = basis "none"', json_encode($cn['sales']));
    check($an && $an['status'] === 'nobasis' && $an['no_basis'] && $an['spent'] > 100 && near($an['left'], 0), 'No basis: the line shows "no sales basis yet" (not over) although goods came in', json_encode($an));
    check(stripos(rh_sbud_sales_basis_text(30, $cn['sales'], 'MWK'), 'no sales basis yet') !== false, 'No basis: plain-words text says so');
    check($cn['summary']['status'] !== 'over' && near($cn['summary']['budget'], 500) && $cn['summary']['nobasis_lines'] === 1 && stripos($cn['summary']['verdict'], 'no sales basis') !== false, 'No basis: the unbased line is left out of the summary and the verdict says so', json_encode($cn['summary']));
    // Hidden spend: the unbased line's real spend still counts in Received / Already ordered (only its budget is left out).
    check(near($cn['summary']['received'], $an['spent'] + line_of($cn, $catB)['spent']) && $cn['summary']['received'] > $cn['summary']['spent'] + 100
        && near($cn['summary']['ordered'], $an['committed'] + line_of($cn, $catB)['committed']) && near($cn['summary']['budget'], 500) && near($cn['summary']['left'], 500 - $cn['summary']['used']),
        'No basis: its spend still shows in Received / Already ordered, but not in Budget / Left', json_encode($cn['summary']));
    $pdo->prepare("INSERT INTO stock_budgets (budget_month, category, mode, amount, target_pct, control) VALUES ('2031-02-01', ?, 'fixed', 100, 0, 'warn')")->execute([$catB]);
    $hFeb = rh_sbud_history($pdo, '2031-03', 1)[0];
    check($hFeb['has_budget'] && near($hFeb['budget'], 100) && $hFeb['actual'] >= 999 - BALANCE_TOLERANCE, 'No basis: history Received includes the unbased line spend', json_encode($hFeb));
    $poNb = $mkPo('draft', [[$ingA, 100, 0, 1000]]);     // 100,000 into a line with no basis: must not block or warn
    $e = rh_sbud_po_effect($pdo, $poNb, $now);
    $x = rh_sbud_authorize_po($pdo, $poNb, $adminId, false, false, '', $now);
    check(!$e['over'] && !$e['needs_approval'] && $x['ok'] && !$x['overridden'], 'No basis: a PO send is never blocked or warned on that line (even on an approval line)', $x['error']);
    $dn = rh_sbud_dashboard($pdo, $now);
    check($dn !== null && $dn['status'] !== 'over' && $dn['notice'] === '' && ($dn['worst']['category'] ?? '') !== $catA, 'No basis: the dashboard does not call it over and does not pick it as worst', json_encode($dn));
    rh_sbud_save($pdo, $ym, [['category' => $catA, 'mode' => 'target_pct', 'target_pct' => '30', 'control' => 'warn']], $adminId, 'manual', $now);
    $cn2 = rh_sbud_compute($pdo, $ym, ['now' => $now]);
    check($cn2['summary']['status'] === 'nobasis' && stripos($cn2['summary']['verdict'], 'No sales basis') === 0, 'No basis: only unbased % lines = summary "no sales basis yet"', $cn2['summary']['status']);
    check(rh_sbud_dashboard($pdo, $now) === null, 'No basis: the dashboard tile has nothing to show');
    check(rh_sbud_pill_text('nobasis', $cn2['info']) === 'No sales basis yet', 'No basis: pill wording');
    $h0 = rh_sbud_history($pdo, '2031-04', 1);
    check($h0[0]['variance'] === null && !$h0[0]['has_budget'], 'No basis: a past month with only an unbased % line shows no variance', json_encode($h0));

    // --- Finding 3: next month's basis ---------------------------------------------------------
    rh_sbud_sales_provider(function ($pdo, $m, $lastDay) { return ['2031-03' => 10000.0, '2031-02' => 20000.0][$m] ?? 0.0; });
    $nx = rh_sbud_expected_sales($pdo, '2031-04', $now);   // day 11 (10.5 of 31 days gone)
    check($nx['basis'] === 'projected' && near($nx['expected'], round(10000 / (10.5 / 31), 2)) && $nx['has_basis'], 'Next month: this month projected to the full month once 7+ days have gone', json_encode($nx));
    $day5 = new DateTimeImmutable('2031-03-05 12:00:00', $tz);   // 4.5 days gone
    $nx5 = rh_sbud_expected_sales($pdo, '2031-04', $day5);
    check($nx5['basis'] === 'last_month' && near($nx5['expected'], 20000), 'Next month: before 7 days the last full month is used', json_encode($nx5));
    // One rule for every % basis: the current month also waits for 7 days of pace.
    $cur5 = rh_sbud_expected_sales($pdo, '2031-03', $day5);
    check($cur5['basis'] === 'last_month' && near($cur5['expected'], 20000), 'Current month: 4.5 days gone still uses last month sales (needs 7 days)', json_encode($cur5));
    $cur7 = rh_sbud_expected_sales($pdo, '2031-03', new DateTimeImmutable('2031-03-07 12:00:00', $tz));
    check($cur7['basis'] === 'last_month', 'Current month: 6.5 days gone still uses last month sales', json_encode($cur7));
    $cur8 = rh_sbud_expected_sales($pdo, '2031-03', new DateTimeImmutable('2031-03-08 12:00:00', $tz));
    check($cur8['basis'] === 'month' && near($cur8['expected'], round(10000 / (7.5 / 31), 2)), 'Current month: from 7 days it uses its own pace', json_encode($cur8));
    $nx2 = rh_sbud_expected_sales($pdo, '2031-05', $now);
    check($nx2['basis'] === 'last_month' && near($nx2['expected'], 20000), 'Two months ahead: the last full month\'s sales', json_encode($nx2));
    $day7 = new DateTimeImmutable('2031-03-07 12:00:00', $tz);   // 6.5 days: still under 7
    check(rh_sbud_expected_sales($pdo, '2031-04', $day7)['basis'] === 'last_month', 'Next month: 6.5 days gone is still too early to project');
    rh_sbud_sales_provider(function ($pdo, $m, $lastDay) { return 0.0; });
    check(rh_sbud_expected_sales($pdo, '2031-04', $now)['basis'] === 'none' && rh_sbud_expected_sales($pdo, '2031-06', $now)['basis'] === 'none', 'Future months with no sales anywhere: no sales basis yet');
    check(rh_sbud_expected_sales($pdo, '2031-02', $now)['basis'] === 'none', 'A past month with no sales has no basis');
    rh_sbud_sales_provider(null);

    // --- Finding 5: no forecast verdicts in the first 7 days -----------------------------------
    check(rh_sbud_forecast(rh_sbud_month_info($ym, $day5), 400, 0) === null, 'Forecast: 4.5 days in = too early (none)');
    check(rh_sbud_forecast(rh_sbud_month_info($ym, $day7), 400, 0) === null, 'Forecast: 6.5 days in = still too early');
    check(rh_sbud_forecast(rh_sbud_month_info($ym, new DateTimeImmutable('2031-03-08 12:00:00', $tz)), 400, 0) !== null, 'Forecast: 7.5 days in = shown');
    rh_sbud_save($pdo, $ym, [
        ['category' => $catA, 'mode' => 'fixed', 'amount' => '1000', 'control' => 'warn'],
        ['category' => $catB, 'mode' => 'fixed', 'amount' => '500', 'control' => 'approval'],
    ], $adminId, 'manual', $now);
    $ce = rh_sbud_compute($pdo, $ym, ['now' => $day5]);
    $ea = line_of($ce, $catA);
    check($ea && $ea['status'] === 'ok' && rh_sbud_pill_text($ea['status'], $ce['info']) === 'Too early to tell', 'Early month: no "on track" / "ahead" pill, it says too early to tell', json_encode($ea));
    check(stripos($ce['summary']['verdict'], 'too early') !== false && stripos($ce['summary']['verdict'], 'ahead') === false && stripos($ce['summary']['verdict'], 'on track') === false, 'Early month: the verdict says too early to tell', $ce['summary']['verdict']);

    // --- Finding 4: a PO is "received" only when EVERY line has arrived ----------------------------
    $poMixed = $mkPo('partial', [[$ingA, 10, 12, 5], [$ingB, 10, 4, 5]]);   // one over-delivered, one short
    check(!rh_sbud_po_fully_received($pdo, $poMixed), 'PO status: an over-delivered line cannot hide a short line (stays partial)');
    check(near(rh_sbud_committed_by_category($pdo)[rh_sbud_cat_key($catB)] ?? 0, 30), 'PO status: the short line\'s outstanding value is still committed (6 x 5)');
    $poFull = $mkPo('draft', [[$ingA, 10, 12, 5], [$ingB, 10, 10, 5]]);
    check(rh_sbud_po_fully_received($pdo, $poFull), 'PO status: every line in full (one over) = received');
    $poTol = $mkPo('draft', [[$ingA, 10, 9.99995, 5]]);
    check(rh_sbud_po_fully_received($pdo, $poTol), 'PO status: a tiny rounding shortfall is within tolerance');
    $poShort = $mkPo('draft', [[$ingA, 10, 9.9, 5]]);
    check(!rh_sbud_po_fully_received($pdo, $poShort), 'PO status: a real shortfall is not');
    $poEmpty = $mkPo('draft', []);
    check(!rh_sbud_po_fully_received($pdo, $poEmpty), 'PO status: a PO with no lines is not received');

    // --- Finding 6: capped detail lists say so ---------------------------------------------------
    $dd = rh_sbud_detail($pdo, $ym, $catA, $now);
    check($dd['receipts_total'] === count($dd['receipts']) && $dd['pos_capped'] === false, 'Detail: under the cap, total = shown and no "capped" flag', json_encode([$dd['receipts_total'], count($dd['receipts'])]));
    for ($i = 0; $i < RH_SBUD_DETAIL_RECEIPTS + 5; $i++) { $goodsIn($ingC, 1, '2031-03-04 08:00:00'); }
    $dc = rh_sbud_detail($pdo, $ym, $catC, $now);
    check(count($dc['receipts']) === RH_SBUD_DETAIL_RECEIPTS && $dc['receipts_total'] === RH_SBUD_DETAIL_RECEIPTS + 6, 'Detail: capped at 200 shown while the real count is reported (205 + 1 earlier)', json_encode([count($dc['receipts']), $dc['receipts_total']]));

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

    // --- Category as at receipt + delivery date (migration 069) -----------------------------
    $hasDated = (int)col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_in_log' AND COLUMN_NAME IN ('category','received_date')") === 2;
    check($hasDated, 'Migration 069 applied: stock_in_log has category + received_date (run php admin/migrations/migrate.php --run)');
    if ($hasDated) {
        $catD = 'ZZT-D-' . $run; $catE = 'ZZT-E-' . $run;
        $ingD = $mkIng($catD, 'Budget test D');
        $dated = function (int $ing, float $total, string $createdAt, ?string $received, ?string $cat) use ($pdo) {
            $pdo->prepare("INSERT INTO stock_in_log (ingredient_id, quantity, cost_per_unit, cost_total, created_at, received_date, category) VALUES (?,1,?,?,?,?,?)")
                ->execute([$ing, $total, $total, $createdAt, $received, $cat]);
        };
        $dated($ingD, 100, '2032-07-10 10:00:00', '2032-07-10', $catD);
        // The item is moved to another category afterwards: July's spend must stay where it was received.
        $pdo->prepare("UPDATE stock_ingredients SET category = ? WHERE id = ?")->execute([$catE, $ingD]);
        $sp = rh_sbud_spent_by_category($pdo, '2032-07');
        check(near($sp[rh_sbud_cat_key($catD)] ?? 0, 100) && !isset($sp[rh_sbud_cat_key($catE)]), 'Spent: recategorising an item later does not move its closed-month spend', json_encode($sp));

        // Delivered on the 31st, entered on the 2nd: counts in the delivery month.
        $dated($ingD, 40, '2032-08-02 09:00:00', '2032-07-31', $catD);
        $sp7 = rh_sbud_spent_by_category($pdo, '2032-07');
        $sp8 = rh_sbud_spent_by_category($pdo, '2032-08');
        check(near($sp7[rh_sbud_cat_key($catD)] ?? 0, 140), 'Spent: a receipt delivered on the 31st counts in that month though entered on the 2nd', json_encode($sp7));
        check(!isset($sp8[rh_sbud_cat_key($catD)]), 'Spent: ...and not in the month it was typed in', json_encode($sp8));
        $det7 = rh_sbud_detail($pdo, '2032-07', $catD);
        check(near(array_sum(array_map(fn($r) => (float)$r['cost_total'], $det7['receipts'])), 140) && $det7['receipts_total'] === 2, 'Detail: lists the back-dated receipt under its delivery month and its original category', json_encode($det7['receipts']));
        $det7e = rh_sbud_detail($pdo, '2032-07', $catE);
        check($det7e['receipts_total'] === 0, 'Detail: the item\'s new category does not list its old receipts', (string)$det7e['receipts_total']);

        // Rows written before the columns existed (both NULL): month from created_at, category from the item as it is now.
        $dated($ingD, 25, '2032-07-15 12:00:00', null, null);
        $spOld = rh_sbud_spent_by_category($pdo, '2032-07');
        check(near($spOld[rh_sbud_cat_key($catE)] ?? 0, 25) && near($spOld[rh_sbud_cat_key($catD)] ?? 0, 140), 'Spent: an old row without the new columns still counts (created_at month, item category)', json_encode($spOld));

        // Suggestions and history use the same basis.
        $sg2 = rh_sbud_suggest_proposal($pdo, '2032-10');
        $sd = null; foreach ($sg2 as $p) { if ($p['category'] === $catD) $sd = $p; }
        check($sd && near($sd['amount'], 140), 'Suggest: uses category and delivery month as received', json_encode($sg2));
        $hist = rh_sbud_history($pdo, '2032-08', 1);
        check(isset($hist[0]) && $hist[0]['ym'] === '2032-07' && $hist[0]['actual'] >= 165 - BALANCE_TOLERANCE, 'History: the month total includes the back-dated receipt', json_encode($hist));
    }
} catch (Throwable $e) {
    bad('Unexpected error', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
rh_sbud_sales_provider(null);
if ($pdo->inTransaction()) { $pdo->rollBack(); }
check((int)col($pdo, "SELECT COUNT(*) FROM stock_budgets WHERE category LIKE 'ZZT-%'") === 0, 'Rollback: no test budget lines left behind');
check((int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ['%' . $run]) === 0, 'Rollback: no test ingredients left behind');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
