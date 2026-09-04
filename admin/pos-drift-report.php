<?php

/**
 * POS Ledger Drift Report — READ ONLY.
 *
 * Quantifies (never corrects) two historical bugs fixed in this build round:
 *   1. Tips were booked as VAT-rated revenue on the sale but excluded on the refund
 *      (see .claude/POS_KDS_ACCOUNTING_PLAN.md D3). Any payments row here predates the fix.
 *   2. Split-tender orders were reported under the LAST leg's payment method only, so the
 *      Z-report/accounting page misattributed cash/mobile/card on mixed-tender splits (D2).
 *      Historical stock_shift_closes rows still carry whatever expected/variance figures
 *      that produced at the time.
 *
 * This page issues SELECT statements only. It writes nothing.
 */

require_once 'admin-init.php';
/** @var array $user */
/** @var PDO $pdo */
require_once '../includes/station-hours.php';

if (!hasPermission((int)($user['id'] ?? 0), 'pos_accounting')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$site_name = getSetting('site_name') ?: 'Admin';
$currency_symbol = getSetting('currency_symbol') ?: 'MWK ';

/* Same formula as admin/pos.php::pos_calculateRestaurantVatParts() — POS menu prices are
 * gross, VAT is extracted from within. Duplicated locally the same way admin/restaurant-tables.php
 * and admin/stock-orders.php each already carry their own copy; there is no shared include. */
function drift_calculateVatParts(float $grossAmount): array
{
    $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0.0;
    if ($grossAmount <= 0 || $vatRate <= 0) {
        return ['net' => round($grossAmount, 2), 'vat' => 0.0, 'gross' => round($grossAmount, 2)];
    }
    $net = round($grossAmount / (1 + ($vatRate / 100)), 2);
    $vat = round($grossAmount - $net, 2);
    return ['net' => $net, 'vat' => $vat, 'gross' => round($grossAmount, 2)];
}

function drift_money(float $amount, string $currencySymbol): string
{
    return htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') . ' ' . number_format($amount, 2);
}

/* Recompute this cashier's expected cash/mobile/card for a window using the FIXED
 * split-aware logic (mirrors admin/pos.php close_shift after the D2 fix) — sourcing
 * split-order tenders from stock_order_splits instead of stock_orders.payment_method. */
function drift_recomputeExpected(PDO $pdo, int $userId, string $windowStart, string $windowEnd): array
{
    $nonSplit = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN payment_method='cash' THEN total_amount + COALESCE(tip_amount,0) ELSE 0 END),0) AS cash,
               COALESCE(SUM(CASE WHEN payment_method='mobile_money' THEN total_amount + COALESCE(tip_amount,0) ELSE 0 END),0) AS mobile,
               COALESCE(SUM(CASE WHEN payment_method IN ('card_manual','card_pos') THEN total_amount + COALESCE(tip_amount,0) ELSE 0 END),0) AS card
        FROM stock_orders
        WHERE created_by = ? AND status = 'paid' AND COALESCE(split_count,1) <= 1
          AND ((paid_at IS NOT NULL AND paid_at >= ? AND paid_at < ?) OR (paid_at IS NULL AND created_at >= ? AND created_at < ?))
    ");
    $nonSplit->execute([$userId, $windowStart, $windowEnd, $windowStart, $windowEnd]);
    $ns = $nonSplit->fetch(PDO::FETCH_ASSOC) ?: ['cash' => 0, 'mobile' => 0, 'card' => 0];

    $split = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN s.payment_method='cash' THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END),0) AS cash,
               COALESCE(SUM(CASE WHEN s.payment_method='mobile_money' THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END),0) AS mobile,
               COALESCE(SUM(CASE WHEN s.payment_method IN ('card_manual','card_pos') THEN s.split_amount + COALESCE(s.tip_amount,0) ELSE 0 END),0) AS card
        FROM stock_order_splits s
        INNER JOIN stock_orders o ON o.id = s.order_id
        WHERE o.created_by = ? AND o.status = 'paid' AND COALESCE(o.split_count,1) > 1
          AND ((o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at < ?) OR (o.paid_at IS NULL AND o.created_at >= ? AND o.created_at < ?))
    ");
    $split->execute([$userId, $windowStart, $windowEnd, $windowStart, $windowEnd]);
    $sp = $split->fetch(PDO::FETCH_ASSOC) ?: ['cash' => 0, 'mobile' => 0, 'card' => 0];

    return [
        'cash'   => round((float)$ns['cash']   + (float)$sp['cash'], 2),
        'mobile' => round((float)$ns['mobile'] + (float)$sp['mobile'], 2),
        'card'   => round((float)$ns['card']   + (float)$sp['card'], 2),
    ];
}

// ---------------------------------------------------------------------------
// Date range filter (defaults to last 90 days — adjustable; "All time" clears it)
// ---------------------------------------------------------------------------
$today = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-90 days'));
$startDate = $_GET['start'] ?? $defaultStart;
$endDate   = $_GET['end'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !strtotime($startDate)) $startDate = $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || !strtotime($endDate)) $endDate = $today;
$allTime = isset($_GET['all']) && $_GET['all'] === '1';
$queryStart = $allTime ? '2000-01-01' : $startDate;
$queryEnd   = $allTime ? '2100-01-01' : $endDate . ' 23:59:59';

// ---------------------------------------------------------------------------
// 1) Tip/VAT overstatement register — sale rows whose recorded gross matches the
//    bug signature (order_total + tip) rather than order_total alone.
// ---------------------------------------------------------------------------
$tipDriftStmt = $pdo->prepare("
    SELECT p.id AS payment_id, p.payment_reference, p.payment_date, p.total_amount AS recorded_gross,
           p.vat_amount AS recorded_vat, p.payment_method,
           o.id AS order_id, o.reference, o.total_amount AS order_total, o.tip_amount, o.status AS order_status,
           COALESCE(NULLIF(u.full_name,''), u.username) AS cashier_name
    FROM payments p
    INNER JOIN stock_orders o ON o.id = p.booking_id
    LEFT JOIN admin_users u ON u.id = o.created_by
    WHERE p.booking_type = 'restaurant'
      AND COALESCE(p.payment_type,'') != 'refund'
      AND p.deleted_at IS NULL
      AND o.tip_amount > 0
      AND p.payment_date BETWEEN ? AND ?
      AND ABS(p.total_amount - (o.total_amount + o.tip_amount)) < 0.02
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 500
");
$tipDriftStmt->execute([$queryStart, $allTime ? $queryEnd : $endDate]);
$tipDriftRows = $tipDriftStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Batch-fetch refund totals for the affected orders so we can flag residual imbalance
// (a refund of a tipped order reverses only order_total, leaving the ledger short by the
// tip on a transaction that should have netted to zero).
$refundedGrossByOrder = [];
if ($tipDriftRows) {
    $orderIds = array_unique(array_map(fn($r) => (int)$r['order_id'], $tipDriftRows));
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $refStmt = $pdo->prepare("SELECT booking_id, SUM(total_amount) AS refunded_gross FROM payments WHERE booking_type='restaurant' AND payment_type='refund' AND deleted_at IS NULL AND booking_id IN ($ph) GROUP BY booking_id");
    $refStmt->execute($orderIds);
    foreach ($refStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $refundedGrossByOrder[(int)$r['booking_id']] = (float)$r['refunded_gross'];
    }
}

$tipDrift = [];
$tipKpiOverstatedRevenue = 0.0;
$tipKpiOverstatedVat = 0.0;
$tipKpiResidualCount = 0;
$tipKpiResidualAmount = 0.0;
foreach ($tipDriftRows as $r) {
    $correctVat = drift_calculateVatParts((float)$r['order_total']);
    $overRevenue = round((float)$r['recorded_gross'] - (float)$r['order_total'], 2);
    $overVat = round((float)$r['recorded_vat'] - $correctVat['vat'], 2);
    $orderId = (int)$r['order_id'];
    $isRefunded = $r['order_status'] === 'refunded';
    $residual = null;
    if ($isRefunded && isset($refundedGrossByOrder[$orderId])) {
        $residual = round((float)$r['recorded_gross'] - $refundedGrossByOrder[$orderId], 2);
        if (abs($residual) > 0.01) {
            $tipKpiResidualCount++;
            $tipKpiResidualAmount += $residual;
        }
    }
    $tipKpiOverstatedRevenue += $overRevenue;
    $tipKpiOverstatedVat += $overVat;
    $tipDrift[] = $r + [
        'correct_gross' => (float)$r['order_total'],
        'correct_vat' => $correctVat['vat'],
        'over_revenue' => $overRevenue,
        'over_vat' => $overVat,
        'is_refunded' => $isRefunded,
        'residual' => $residual,
    ];
}

// ---------------------------------------------------------------------------
// 2) Historical shift-close accuracy — recompute expected cash/mobile/card under the
//    fixed split-aware logic and compare against what was actually stored at close time.
// ---------------------------------------------------------------------------
$closesStmt = $pdo->prepare("
    SELECT id, user_id, user_name, shift_date, closed_at,
           expected_cash, declared_cash, variance_cash,
           expected_mobile, declared_mobile, variance_mobile,
           expected_card, declared_card, variance_card,
           notes
    FROM stock_shift_closes
    WHERE shift_date BETWEEN ? AND ?
    ORDER BY shift_date DESC, id DESC
    LIMIT 300
");
$closesStmt->execute([$startDate, $allTime ? $today : $endDate]);
$closeRows = $closesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$shiftDrift = [];
$shiftKpiAffectedCount = 0;
$shiftKpiOverrideAffectedCount = 0;
foreach ($closeRows as $c) {
    $window = rh_station_union_window_for_date((string)$c['shift_date']);
    $recomputed = drift_recomputeExpected($pdo, (int)$c['user_id'], $window['start_sql'], $window['end_sql']);
    $deltaCash = round($recomputed['cash'] - (float)$c['expected_cash'], 2);
    $deltaMobile = round($recomputed['mobile'] - (float)$c['expected_mobile'], 2);
    $deltaCard = round($recomputed['card'] - (float)$c['expected_card'], 2);
    $affected = abs($deltaCash) > 0.01 || abs($deltaMobile) > 0.01 || abs($deltaCard) > 0.01;
    if (!$affected) continue;
    $isOverride = stripos((string)($c['notes'] ?? ''), 'OVERRIDE') !== false;
    $shiftKpiAffectedCount++;
    if ($isOverride) $shiftKpiOverrideAffectedCount++;
    $shiftDrift[] = $c + [
        'recomputed_cash' => $recomputed['cash'],
        'recomputed_mobile' => $recomputed['mobile'],
        'recomputed_card' => $recomputed['card'],
        'delta_cash' => $deltaCash,
        'delta_mobile' => $deltaMobile,
        'delta_card' => $deltaCard,
        'recomputed_variance_cash' => round((float)$c['declared_cash'] - $recomputed['cash'], 2),
        'recomputed_variance_mobile' => round((float)$c['declared_mobile'] - $recomputed['mobile'], 2),
        'recomputed_variance_card' => round((float)$c['declared_card'] - $recomputed['card'], 2),
        'is_override' => $isOverride,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Ledger Drift Report | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content finance-page">
        <div class="acct-page-header">
            <div class="acct-page-header__copy">
                <h1 class="acct-page-header__title">POS Ledger Drift Report</h1>
                <p class="acct-page-header__subtitle">Read-only. Quantifies how much historical POS accounting was affected by the tip/VAT and split-tender bugs fixed in this build round — writes nothing, corrects nothing.</p>
            </div>
            <form method="GET" class="acct-filter-form">
                <label class="acct-filter-field">
                    <span>From</span>
                    <input type="date" name="start" value="<?php echo htmlspecialchars($startDate); ?>">
                </label>
                <label class="acct-filter-field">
                    <span>To</span>
                    <input type="date" name="end" value="<?php echo htmlspecialchars($endDate); ?>">
                </label>
                <button type="submit" class="acct-btn acct-btn--primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="pos-drift-report.php?all=1" class="acct-btn acct-btn--ghost"><i class="fas fa-infinity"></i> All time</a>
                <a href="pos-accounting.php" class="acct-btn acct-btn--ghost"><i class="fas fa-arrow-left"></i> POS Accounting</a>
            </form>
        </div>

        <?php if ($allTime): ?><p style="color:#6c757d;font-size:13px;margin:-8px 0 14px;"><i class="fas fa-infinity"></i> Showing all-time results (date filter above is ignored while this is active).</p><?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card warning">
                <div class="stat-label">Tip/VAT overstated revenue</div>
                <div class="acct-kpi__value"><?php echo drift_money($tipKpiOverstatedRevenue, $currency_symbol); ?></div>
                <div class="stat-sub"><?php echo count($tipDrift); ?> sale row(s) affected</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-label">VAT overstated on tips</div>
                <div class="acct-kpi__value"><?php echo drift_money($tipKpiOverstatedVat, $currency_symbol); ?></div>
                <div class="stat-sub">Declared VAT was too high by this much</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-label">Unresolved after refund</div>
                <div class="acct-kpi__value"><?php echo drift_money($tipKpiResidualAmount, $currency_symbol); ?></div>
                <div class="stat-sub"><?php echo $tipKpiResidualCount; ?> refunded order(s) still don't net to zero</div>
            </div>
            <div class="stat-card info">
                <div class="stat-label">Shift closes affected</div>
                <div class="stat-value"><?php echo $shiftKpiAffectedCount; ?></div>
                <div class="stat-sub"><?php echo $shiftKpiOverrideAffectedCount; ?> of those needed a manager override at the time</div>
            </div>
        </div>

        <div class="section-card">
            <div class="pos-acct-section-head">
                <div>
                    <h3><i class="fas fa-receipt"></i> Tip/VAT overstatement register</h3>
                    <p>Every sale payment whose recorded total matches the bug signature (order total + tip, rather than order total alone). Fixed going forward — this is history only.</p>
                </div>
            </div>
            <div class="acct-table-wrap">
                <table class="acct-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Cashier</th>
                            <th>Date</th>
                            <th class="num">Tip</th>
                            <th class="num">Recorded gross</th>
                            <th class="num">Correct gross</th>
                            <th class="num">Revenue overstated</th>
                            <th class="num">VAT overstated</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tipDrift)): ?>
                            <tr><td colspan="9" class="acct-empty acct-empty--good"><i class="fas fa-check-circle"></i> No tip/VAT drift found in this range.</td></tr>
                        <?php else: foreach ($tipDrift as $d): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$d['reference']); ?></td>
                                <td><?php echo htmlspecialchars((string)($d['cashier_name'] ?? '—')); ?></td>
                                <td><?php echo htmlspecialchars((string)$d['payment_date']); ?></td>
                                <td class="num"><?php echo drift_money((float)$d['tip_amount'], $currency_symbol); ?></td>
                                <td class="num"><?php echo drift_money((float)$d['recorded_gross'], $currency_symbol); ?></td>
                                <td class="num"><?php echo drift_money((float)$d['correct_gross'], $currency_symbol); ?></td>
                                <td class="num"><?php echo drift_money((float)$d['over_revenue'], $currency_symbol); ?></td>
                                <td class="num"><?php echo drift_money((float)$d['over_vat'], $currency_symbol); ?></td>
                                <td>
                                    <?php if ($d['is_refunded']): ?>
                                        <span class="pos-acct-pill pos-acct-pill--warn" title="Residual imbalance after refund">Refunded<?php if ($d['residual'] !== null && abs($d['residual']) > 0.01): ?> · <?php echo drift_money((float)$d['residual'], $currency_symbol); ?> unresolved<?php endif; ?></span>
                                    <?php else: ?>
                                        <span class="pos-acct-pill pos-acct-pill--open">Paid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section-card">
            <div class="pos-acct-section-head">
                <div>
                    <h3><i class="fas fa-scale-unbalanced"></i> Historical shift-close accuracy</h3>
                    <p>Every closed shift's expected cash/mobile/card, recomputed under the fixed split-aware logic and compared against what was actually recorded at close time. Only shows shifts where the two figures differ.</p>
                </div>
            </div>
            <div class="acct-table-wrap">
                <table class="acct-table">
                    <thead>
                        <tr>
                            <th>Cashier</th>
                            <th>Shift date</th>
                            <th class="num">Recorded expected (cash / mobile / card)</th>
                            <th class="num">Corrected expected (cash / mobile / card)</th>
                            <th class="num">Corrected variance (cash / mobile / card)</th>
                            <th>Override at the time?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($shiftDrift)): ?>
                            <tr><td colspan="6" class="acct-empty acct-empty--good"><i class="fas fa-check-circle"></i> No shift closes in this range would have balanced differently under the fix.</td></tr>
                        <?php else: foreach ($shiftDrift as $s): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$s['user_name']); ?></td>
                                <td><?php echo htmlspecialchars((string)$s['shift_date']); ?></td>
                                <td class="num"><?php echo drift_money((float)$s['expected_cash'], $currency_symbol); ?> / <?php echo drift_money((float)$s['expected_mobile'], $currency_symbol); ?> / <?php echo drift_money((float)$s['expected_card'], $currency_symbol); ?></td>
                                <td class="num"><?php echo drift_money((float)$s['recomputed_cash'], $currency_symbol); ?> / <?php echo drift_money((float)$s['recomputed_mobile'], $currency_symbol); ?> / <?php echo drift_money((float)$s['recomputed_card'], $currency_symbol); ?></td>
                                <td class="num"><?php echo drift_money((float)$s['recomputed_variance_cash'], $currency_symbol); ?> / <?php echo drift_money((float)$s['recomputed_variance_mobile'], $currency_symbol); ?> / <?php echo drift_money((float)$s['recomputed_variance_card'], $currency_symbol); ?></td>
                                <td><?php if ($s['is_override']): ?><span class="pos-acct-pill pos-acct-pill--warn">Yes — review</span><?php else: ?><span class="pos-acct-pill pos-acct-pill--open">No</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <p style="color:#6c757d;font-size:12px;margin-top:6px;">This page never writes to the database. If any figure above needs correcting in the books, that is a deliberate decision for finance/ownership to make — not something this report or any agent should do automatically.</p>
    </div>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
