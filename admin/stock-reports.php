<?php

/**
 * Stock Management — Reports
 *
 * Tabbed reports: Inventory, Stock-In, Usage, Wastage, Yield, Adjustments, Expiry.
 * Default range = last 30 days. CSV export per tab.
 */
require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */
require_once '../includes/alert.php';

$message = '';
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);
$currency_symbol = getSetting('currency_symbol');

if (!ensureStockTablesExist()) {
    showAlert('Stock tables not yet created. Please run admin/migrations/015_stock_management.php first.', 'error');
    require_once 'includes/admin-header.php';
    require_once 'includes/admin-footer.php';
    exit;
}

$tabs = ['inventory' => 'Inventory', 'stock_in' => 'Stock-In', 'usage' => 'Usage', 'wastage' => 'Wastage', 'yield' => 'Yield', 'adjustments' => 'Adjustments', 'expiry' => 'Expiry'];
$tab = $_GET['tab'] ?? 'inventory';
if (!isset($tabs[$tab])) {
    $tab = 'inventory';
}

function stock_reports_normalize_date(mixed $value, string $fallback): string
{
    if (!is_string($value)) {
        return $fallback;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    if ($date === false || !is_array($errors) || $errors['warning_count'] > 0 || $errors['error_count'] > 0) {
        return $fallback;
    }

    return $date->format('Y-m-d');
}

function stock_reports_metric(string $label, string $value, string $hint = ''): array
{
    return [
        'label' => $label,
        'value' => $value,
        'hint' => $hint,
    ];
}

/** "12.5 kg · 3 L": subtotals per unit of measure (kg, L and pcs are never added together). */
function stock_reports_by_unit(array $totals, bool $signed = false): string
{
    ksort($totals);
    $parts = [];
    foreach ($totals as $unit => $qty) {
        $parts[] = ($signed && $qty > 0 ? '+' : '') . rtrim(rtrim(number_format((float)$qty, 3), '0'), '.') . ' ' . $unit;
    }
    return $parts ? implode(' · ', $parts) : 'none';
}

function stock_reports_money(float $amount, string $currencySymbol): string
{
    return $currencySymbol . ' ' . number_format($amount, 2);
}

function stock_reports_helper_content(string $tab, string $dateFrom, string $dateTo, string $currencySymbol): array
{
    $range = $dateFrom . ' to ' . $dateTo;
    $content = [
        'title' => 'How to read this report',
        'summary' => 'These KPI cards summarize the selected tab for ' . $range . '. Each value is already calculated from the rows below, so you can use cards for quick decisions and the table for detail checks.',
        'example' => 'If Top usage ingredient shows Chicken Breast with ' . $currencySymbol . ' 210,000.00 and 22.5%, then that single ingredient accounts for nearly a quarter of total usage cost in this range.',
    ];

    if ($tab === 'inventory') {
        $content['example'] = 'If Low stock items is 7, then 7 ingredients are at or below their threshold and should be prioritized for re-order before service impact.';
    } elseif ($tab === 'stock_in') {
        $content['example'] = 'If Total stock-in spend is ' . $currencySymbol . ' 1,200,000.00 and Stock-in records is 24, then average stock-in value should be about ' . $currencySymbol . ' 50,000.00 per entry.';
    } elseif ($tab === 'wastage') {
        $content['example'] = 'If Wastage rate is 8.5%, it means wastage cost is 8.5% of your usage cost in this window; sustained high rates indicate leakage or prep-process issues.';
    } elseif ($tab === 'yield') {
        $content['example'] = 'If Overall yield is 86.0%, then 14.0% of the value of stock that left was lost to wastage/expiry; focus on ingredients with low yield badges first.';
    } elseif ($tab === 'adjustments') {
        $content['example'] = 'If Net quantity change is strongly negative with many decrease events, audit adjustment reasons to separate valid consumption from shrinkage risk.';
    } elseif ($tab === 'expiry') {
        $content['example'] = 'If At-risk value (7d) is ' . $currencySymbol . ' 95,000.00, that is the estimated value likely to expire within 7 days unless consumed or transferred.';
    }

    return $content;
}

function stock_reports_question_mark_help(array $helperContent, array $analysisCards): string
{
    $title = (string)($helperContent['title'] ?? 'How to read this report');
    $summary = (string)($helperContent['summary'] ?? 'Use KPI cards for quick signals, then check table rows for details.');
    $example = (string)($helperContent['example'] ?? 'Example data is shown per active tab.');

    $lines = [];
    foreach ($analysisCards as $card) {
        $label = trim((string)($card['label'] ?? ''));
        $hint = trim((string)($card['hint'] ?? ''));
        if ($label === '' || $hint === '') {
            continue;
        }
        $lines[] = '- ' . $label . ': ' . $hint;
    }

    $body = $summary . "\n\nExample: " . $example;
    if (!empty($lines)) {
        $body .= "\n\nKey cards:\n" . implode("\n", $lines);
    }

    return $title . '|' . $body;
}

$defaultFrom = date('Y-m-d', strtotime('-30 days'));
$defaultTo = date('Y-m-d');
$dateFrom = stock_reports_normalize_date($_GET['from'] ?? null, $defaultFrom);
$dateTo = stock_reports_normalize_date($_GET['to'] ?? null, $defaultTo);
if (strtotime($dateFrom) > strtotime($dateTo)) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
if (($_GET['export'] ?? '') === 'csv') {
    // The CSV is the whole date range, not just the 50 rows of the page on screen.
    $perPage = 100000;
    $offset = 0;
}

$vwSa = rh_voided_wastage_sql('sa');
$rows = [];
$totalCount = 0;
$summary = [];
$analysis_cards = [];
$analysis_helper = stock_reports_helper_content($tab, $dateFrom, $dateTo, $currency_symbol);
$question_mark_help = '';

try {
    if ($tab === 'inventory') {
        $rows = $pdo->query("
            SELECT i.*, GREATEST(0, i.current_quantity) * i.cost_per_unit AS stock_value,
                   (SELECT COUNT(*) FROM stock_batches b WHERE b.ingredient_id = i.id AND b.status = 'active' AND b.quantity_remaining > 0) AS active_batches
            FROM stock_ingredients i
            WHERE i.is_archived = 0
            ORDER BY stock_value DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $summary['total_value'] = array_sum(array_map(fn($r) => (float)$r['stock_value'], $rows));
        $totalItems = count($rows);
        // Out / low / never-stocked come from the ONE shared alert rule (config/database.php);
        // the row loop is only needed for the active-batch total.
        $alertSummary = rh_stock_alert_summary($pdo, 1);
        $outOfStock = $alertSummary['out'];
        $lowStock = $alertSummary['low'];
        $neverStocked = $alertSummary['never_stocked'];
        $activeBatchTotal = 0;
        foreach ($rows as $row) {
            $activeBatchTotal += (int)($row['active_batches'] ?? 0);
        }

        $analysis_cards = [
            stock_reports_metric('Tracked ingredients', number_format($totalItems), 'Active ingredients in inventory'),
            stock_reports_metric('Average stock value', $totalItems > 0 ? stock_reports_money(((float)$summary['total_value']) / $totalItems, $currency_symbol) : stock_reports_money(0, $currency_symbol), 'Per ingredient baseline'),
            stock_reports_metric('Low stock items', number_format($lowStock), 'At or below configured threshold'),
            stock_reports_metric('Out of stock items', number_format($outOfStock), 'Needs immediate replenishment'),
            stock_reports_metric('Not yet stocked', number_format($neverStocked), 'Opening stock not recorded - record a stock count'),
            stock_reports_metric('Active batches', number_format($activeBatchTotal), 'Live batches across all ingredients'),
        ];
    } elseif ($tab === 'stock_in') {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM stock_in_log WHERE DATE(created_at) BETWEEN ? AND ?");
        $cnt->execute([$dateFrom, $dateTo]);
        $totalCount = (int)$cnt->fetchColumn();
        $st = $pdo->prepare("
            SELECT s.*, i.name AS ingredient_name, i.unit, b.batch_number, u.full_name AS created_by_name
            FROM stock_in_log s
            INNER JOIN stock_ingredients i ON i.id = s.ingredient_id
            LEFT JOIN stock_batches b ON b.id = s.batch_id
            LEFT JOIN admin_users u ON u.id = s.created_by
            WHERE DATE(s.created_at) BETWEEN ? AND ?
            ORDER BY s.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $st->execute([$dateFrom, $dateTo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $tot = $pdo->prepare("SELECT COALESCE(SUM(cost_total), 0) FROM stock_in_log WHERE DATE(created_at) BETWEEN ? AND ?");
        $tot->execute([$dateFrom, $dateTo]);
        $summary['total_spend'] = (float)$tot->fetchColumn();
        $distinctIngredientsStmt = $pdo->prepare("SELECT COUNT(DISTINCT ingredient_id) FROM stock_in_log WHERE DATE(created_at) BETWEEN ? AND ?");
        $distinctIngredientsStmt->execute([$dateFrom, $dateTo]);
        $distinctIngredients = (int)$distinctIngredientsStmt->fetchColumn();

        $analysis_cards = [
            stock_reports_metric('Stock-in records', number_format($totalCount), 'Entries in selected date range'),
            stock_reports_metric('Total stock-in spend', stock_reports_money((float)$summary['total_spend'], $currency_symbol), 'Direct procurement cost'),
            stock_reports_metric('Average stock-in value', $totalCount > 0 ? stock_reports_money(((float)$summary['total_spend']) / $totalCount, $currency_symbol) : stock_reports_money(0, $currency_symbol), 'Per stock-in record'),
            stock_reports_metric('Ingredients received', number_format($distinctIngredients), 'Distinct ingredients restocked'),
        ];
    } elseif ($tab === 'usage') {
        $st = $pdo->prepare("
            SELECT i.id, i.name, i.unit,
                   COALESCE(SUM(CASE WHEN sa.source_type IN ('pos_order','room_service') AND NOT {$vwSa} THEN ABS(sa.quantity_change) WHEN sa.source_type = 'void_restore' THEN -ABS(sa.quantity_change) ELSE 0 END), 0) AS total_used,
                   COALESCE(SUM(CASE WHEN sa.source_type IN ('pos_order','room_service') AND NOT {$vwSa} THEN ABS(sa.quantity_change) * sa.cost_at_time WHEN sa.source_type = 'void_restore' THEN -ABS(sa.quantity_change) * sa.cost_at_time ELSE 0 END), 0) AS total_cost
            FROM stock_ingredients i
            LEFT JOIN stock_adjustments sa ON sa.ingredient_id = i.id AND DATE(sa.created_at) BETWEEN ? AND ?
            WHERE i.is_archived = 0
            GROUP BY i.id, i.name, i.unit
            HAVING total_used > 0
            ORDER BY total_cost DESC
        ");
        $st->execute([$dateFrom, $dateTo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $summary['total_cost'] = array_sum(array_map(fn($r) => (float)$r['total_cost'], $rows));
        $usedByUnit = [];
        $topUsageName = 'n/a';
        $topUsageCost = 0.0;
        if (!empty($rows)) {
            $topUsageName = (string)($rows[0]['name'] ?? 'n/a');
            $topUsageCost = (float)($rows[0]['total_cost'] ?? 0);
            foreach ($rows as $row) {
                $usedUnit = trim((string)($row['unit'] ?? '')) !== '' ? (string)$row['unit'] : 'units';
                $usedByUnit[$usedUnit] = ($usedByUnit[$usedUnit] ?? 0.0) + (float)($row['total_used'] ?? 0);
            }
        }

        $topShare = (float)$summary['total_cost'] > 0 ? ($topUsageCost / (float)$summary['total_cost']) * 100 : 0;
        $analysis_cards = [
            stock_reports_metric('Ingredients consumed', number_format(count($rows)), 'Ingredients with usage movement'),
            stock_reports_metric('Total usage cost', stock_reports_money((float)$summary['total_cost'], $currency_symbol), 'Cost of POS and room service usage'),
            stock_reports_metric('Top usage ingredient', $topUsageName, $topUsageName === 'n/a' ? 'No usage rows in range' : stock_reports_money($topUsageCost, $currency_symbol) . ' (' . number_format($topShare, 1) . '% of usage cost)'),
            stock_reports_metric('Quantity used', stock_reports_by_unit($usedByUnit), 'Subtotal per unit of measure'),
        ];
    } elseif ($tab === 'wastage') {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM stock_wastage WHERE recorded_date BETWEEN ? AND ?");
        $cnt->execute([$dateFrom, $dateTo]);
        $totalCount = (int)$cnt->fetchColumn();
        $st = $pdo->prepare("
            SELECT w.*, i.name AS ingredient_name, i.unit, u.full_name AS recorded_by_name
            FROM stock_wastage w
            INNER JOIN stock_ingredients i ON i.id = w.ingredient_id
            LEFT JOIN admin_users u ON u.id = w.recorded_by
            WHERE w.recorded_date BETWEEN ? AND ?
            ORDER BY w.recorded_date DESC, w.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $st->execute([$dateFrom, $dateTo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $tot = $pdo->prepare("SELECT COALESCE(SUM(wastage_cost),0), COUNT(DISTINCT ingredient_id) FROM stock_wastage WHERE recorded_date BETWEEN ? AND ?");
        $tot->execute([$dateFrom, $dateTo]);
        $r = $tot->fetch(PDO::FETCH_NUM);
        $summary['total_cost'] = (float)$r[0];
        $summary['ingredient_count'] = (int)$r[1];
        // Rate uses the same rule as the Usage tab: archived ingredients are left out of both sides.
        $usageCompareStmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN sa.source_type IN ('pos_order','room_service') AND NOT {$vwSa} THEN ABS(sa.quantity_change) * " . rh_stock_movement_cost_sql('sa', 'i') . " WHEN sa.source_type = 'void_restore' THEN -ABS(sa.quantity_change) * " . rh_stock_movement_cost_sql('sa', 'i') . " ELSE 0 END), 0)
            FROM stock_adjustments sa
            INNER JOIN stock_ingredients i ON i.id = sa.ingredient_id AND i.is_archived = 0
            WHERE DATE(sa.created_at) BETWEEN ? AND ?
        ");
        $usageCompareStmt->execute([$dateFrom, $dateTo]);
        $usageCostWindow = (float)$usageCompareStmt->fetchColumn();
        $wastageRateStmt = $pdo->prepare("
            SELECT COALESCE(SUM(w.wastage_cost), 0)
            FROM stock_wastage w
            INNER JOIN stock_ingredients i ON i.id = w.ingredient_id AND i.is_archived = 0
            WHERE w.recorded_date BETWEEN ? AND ?
        ");
        $wastageRateStmt->execute([$dateFrom, $dateTo]);
        $hasUsageCost = $usageCostWindow > BALANCE_TOLERANCE;
        $wastageRate = $hasUsageCost ? ((float)$wastageRateStmt->fetchColumn() / $usageCostWindow) * 100 : 0;

        $topReasonStmt = $pdo->prepare("
            SELECT reason, COALESCE(SUM(wastage_cost), 0) AS total_cost
            FROM stock_wastage
            WHERE recorded_date BETWEEN ? AND ?
            GROUP BY reason
            ORDER BY total_cost DESC
            LIMIT 1
        ");
        $topReasonStmt->execute([$dateFrom, $dateTo]);
        $topReason = $topReasonStmt->fetch(PDO::FETCH_ASSOC);

        $analysis_cards = [
            stock_reports_metric('Wastage entries', number_format($totalCount), 'Rows in selected date range'),
            stock_reports_metric('Wastage total', stock_reports_money((float)$summary['total_cost'], $currency_symbol), 'Direct write-off value'),
            stock_reports_metric('Wastage rate', $hasUsageCost ? number_format($wastageRate, 1) . '%' : '–', $hasUsageCost ? 'As share of usage cost' : 'No comparable usage cost in range'),
            stock_reports_metric('Impacted ingredients', number_format((int)$summary['ingredient_count']), 'Distinct ingredients with wastage'),
            stock_reports_metric('Top wastage reason', (string)($topReason['reason'] ?? 'n/a'), isset($topReason['total_cost']) ? stock_reports_money((float)$topReason['total_cost'], $currency_symbol) : 'No reason data'),
        ];
    } elseif ($tab === 'yield') {
        $st = $pdo->prepare("
            SELECT i.id, i.name, i.unit, i.cost_per_unit,
                   COALESCE(SUM(CASE WHEN sa.source_type IN ('pos_order','room_service') AND NOT {$vwSa} THEN ABS(sa.quantity_change) WHEN sa.source_type = 'void_restore' THEN -ABS(sa.quantity_change) ELSE 0 END), 0) AS used,
                   COALESCE(SUM(CASE WHEN sa.source_type = 'wastage' OR (sa.source_type IN ('pos_order','room_service') AND {$vwSa}) THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS wasted,
                   COALESCE(SUM(CASE WHEN sa.source_type = 'expiry' THEN ABS(sa.quantity_change) ELSE 0 END), 0) AS expired,
                   COALESCE(SUM(CASE WHEN sa.source_type IN ('wastage','expiry') OR (sa.source_type IN ('pos_order','room_service') AND {$vwSa}) THEN ABS(sa.quantity_change) * " . rh_stock_movement_cost_sql('sa', 'i') . " ELSE 0 END), 0) AS loss_value,
                   COALESCE(SUM(CASE WHEN sa.source_type IN ('pos_order','room_service') AND NOT {$vwSa} THEN ABS(sa.quantity_change) * " . rh_stock_movement_cost_sql('sa', 'i') . " WHEN sa.source_type = 'void_restore' THEN -ABS(sa.quantity_change) * " . rh_stock_movement_cost_sql('sa', 'i') . " ELSE 0 END), 0) AS used_value
            FROM stock_ingredients i
            LEFT JOIN stock_adjustments sa ON sa.ingredient_id = i.id AND DATE(sa.created_at) BETWEEN ? AND ?
            WHERE i.is_archived = 0
            GROUP BY i.id, i.name, i.unit, i.cost_per_unit
            HAVING used + wasted + expired > 0
            ORDER BY loss_value DESC
        ");
        $st->execute([$dateFrom, $dateTo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $usedValueTotal = 0.0;
        $atRiskIngredients = 0;
        $lossCostTotal = 0.0;
        foreach ($rows as $row) {
            $usedQty = (float)($row['used'] ?? 0);
            $wastedQty = (float)($row['wasted'] ?? 0);
            $expiredQty = (float)($row['expired'] ?? 0);
            $totalOutQty = $usedQty + $wastedQty + $expiredQty;
            $yieldPct = $totalOutQty > 0 ? ($usedQty / $totalOutQty) * 100 : 0;
            if ($yieldPct < 85) {
                $atRiskIngredients++;
            }

            $usedValueTotal += max(0.0, (float)($row['used_value'] ?? 0));
            $lossCostTotal += (float)($row['loss_value'] ?? 0); // valued at cost when it happened
        }

        // Value at cost, not raw quantity: kg, litres and pieces cannot be added together.
        $hasYieldMovement = ($usedValueTotal + $lossCostTotal) > BALANCE_TOLERANCE;
        $overallYield = $hasYieldMovement
            ? ($usedValueTotal / ($usedValueTotal + $lossCostTotal)) * 100
            : 0;

        $analysis_cards = [
            stock_reports_metric('Ingredients in yield analysis', number_format(count($rows)), 'Rows with movement in selected range'),
            stock_reports_metric('Overall yield', $hasYieldMovement ? number_format($overallYield, 1) . '%' : '–', $hasYieldMovement ? 'Share of stock value used, not wasted or expired' : 'No stock used or lost in this range'),
            stock_reports_metric('At-risk ingredients', number_format($atRiskIngredients), 'Yield below 85% threshold'),
            stock_reports_metric('Loss cost', stock_reports_money($lossCostTotal, $currency_symbol), 'Wasted + expired value'),
        ];
    } elseif ($tab === 'adjustments') {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments WHERE DATE(created_at) BETWEEN ? AND ?");
        $cnt->execute([$dateFrom, $dateTo]);
        $totalCount = (int)$cnt->fetchColumn();
        $st = $pdo->prepare("
            SELECT sa.*, i.name AS ingredient_name, i.unit, u.full_name AS adjusted_by_name
            FROM stock_adjustments sa
            INNER JOIN stock_ingredients i ON i.id = sa.ingredient_id
            LEFT JOIN admin_users u ON u.id = sa.adjusted_by
            WHERE DATE(sa.created_at) BETWEEN ? AND ?
            ORDER BY sa.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $st->execute([$dateFrom, $dateTo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $adjustmentMixStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN quantity_change > 0 THEN 1 ELSE 0 END) AS increases,
                SUM(CASE WHEN quantity_change < 0 THEN 1 ELSE 0 END) AS decreases,
                COUNT(DISTINCT source_type) AS source_count
            FROM stock_adjustments
            WHERE DATE(created_at) BETWEEN ? AND ?
        ");
        $adjustmentMixStmt->execute([$dateFrom, $dateTo]);
        $adjustmentMix = $adjustmentMixStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Whole date range (not just this page), one subtotal per unit of measure.
        $netByUnitStmt = $pdo->prepare("
            SELECT i.unit, COALESCE(SUM(sa.quantity_change), 0) AS net_change
            FROM stock_adjustments sa
            INNER JOIN stock_ingredients i ON i.id = sa.ingredient_id
            WHERE DATE(sa.created_at) BETWEEN ? AND ?
            GROUP BY i.unit
        ");
        $netByUnitStmt->execute([$dateFrom, $dateTo]);
        $netByUnit = [];
        foreach ($netByUnitStmt->fetchAll(PDO::FETCH_ASSOC) as $netRow) {
            $netUnit = trim((string)($netRow['unit'] ?? '')) !== '' ? (string)$netRow['unit'] : 'units';
            $netByUnit[$netUnit] = ($netByUnit[$netUnit] ?? 0.0) + (float)$netRow['net_change'];
        }

        $analysis_cards = [
            stock_reports_metric('Adjustment entries', number_format($totalCount), 'Rows in selected date range'),
            stock_reports_metric('Increase events', number_format((int)($adjustmentMix['increases'] ?? 0)), 'Positive quantity adjustments'),
            stock_reports_metric('Decrease events', number_format((int)($adjustmentMix['decreases'] ?? 0)), 'Negative quantity adjustments'),
            stock_reports_metric('Source types', number_format((int)($adjustmentMix['source_count'] ?? 0)), 'Distinct source types in period'),
            stock_reports_metric('Net quantity change', stock_reports_by_unit($netByUnit, true), 'Subtotal per unit of measure, whole date range'),
        ];
    } elseif ($tab === 'expiry') {
        $rows = $pdo->query("
            SELECT b.*, i.name AS ingredient_name, i.unit, COALESCE(NULLIF(b.cost_per_unit, 0), i.cost_per_unit) AS cost_per_unit, DATEDIFF(b.expiry_date, CURDATE()) AS days_left
            FROM stock_batches b
            INNER JOIN stock_ingredients i ON i.id = b.ingredient_id
            WHERE b.expiry_date IS NOT NULL
              AND ((b.status = 'active' AND b.quantity_remaining > 0) OR (b.status = 'expired' AND b.expiry_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)))
            ORDER BY b.expiry_date ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $expiringSoon = 0;
        $expiredRecent = 0;
        $atRiskValue = 0.0;
        foreach ($rows as $row) {
            $days = (int)($row['days_left'] ?? 0);
            if ($days < 0) {
                $expiredRecent++;
            }
            if ($days >= 0 && $days <= 7) {
                $expiringSoon++;
            }

            $qtyRemaining = (float)($row['quantity_remaining'] ?? 0);
            $costPerUnit = (float)($row['cost_per_unit'] ?? 0);
            if ($days <= 7) {
                $atRiskValue += $qtyRemaining * $costPerUnit;
            }
        }

        $analysis_cards = [
            stock_reports_metric('Tracked batches', number_format(count($rows)), 'Active plus recently expired batches'),
            stock_reports_metric('Expiring in 7 days', number_format($expiringSoon), 'Needs urgent action'),
            stock_reports_metric('Expired recently', number_format($expiredRecent), 'Expired in or before this window'),
            stock_reports_metric('At-risk value (7d)', stock_reports_money($atRiskValue, $currency_symbol), 'Potential near-term write-off risk'),
        ];
    }
} catch (Throwable $e) {
    error_log('DB Error [stock-reports]: ' . $e->getMessage());
    $error = 'Unable to load stock report data.';
}

$question_mark_help = stock_reports_question_mark_help($analysis_helper, $analysis_cards);

function stock_reports_csv_cell(mixed $value): mixed
{
    if (is_string($value) && preg_match('/^[=+\-@]/', ltrim($value))) {
        return "'" . $value;
    }

    return $value;
}

function stock_reports_yield_class(float $yieldPercent): string
{
    if ($yieldPercent >= 95) {
        return 'yield-indicator yield-indicator--good';
    }

    if ($yieldPercent >= 85) {
        return 'yield-indicator yield-indicator--warn';
    }

    return 'yield-indicator yield-indicator--bad';
}

function stock_reports_days_left_class(int $daysLeft): string
{
    if ($daysLeft < 0) {
        return 'days-left days-left--expired';
    }

    if ($daysLeft <= 3) {
        return 'days-left days-left--urgent';
    }

    if ($daysLeft <= 7) {
        return 'days-left days-left--warning';
    }

    return 'days-left days-left--healthy';
}

function stock_reports_label_attr(string $label): string
{
    return 'data-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"';
}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock-' . $tab . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($out, array_map('stock_reports_csv_cell', $r));
    } else {
        fputcsv($out, ['No data']);
    }
    fclose($out);
    exit;
}

$totalPages = $totalCount > 0 ? (int)ceil($totalCount / $perPage) : 1;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Stock Reports</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/stock-reports.css?v=<?php echo @filemtime(__DIR__ . '/css/stock-reports.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title stock-reports__title"><i class="fas fa-chart-area stock-reports__title-icon" aria-hidden="true"></i> Stock Reports</h2>
        </div>

        <?php if ($error): showAlert(htmlspecialchars($error, ENT_QUOTES, 'UTF-8'), 'error');
        endif; ?>

        <div class="tabs">
            <?php foreach ($tabs as $k => $label): ?>
                <a class="tab <?php echo $tab === $k ? 'active' : ''; ?>" href="?tab=<?php echo $k; ?>&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (!in_array($tab, ['inventory', 'expiry'])): ?>
            <form method="GET" class="filter-bar">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                <label class="filter-bar__label" for="stock-report-from">From</label>
                <input id="stock-report-from" type="date" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                <label class="filter-bar__label" for="stock-report-to">To</label>
                <input id="stock-report-to" type="date" name="to" value="<?php echo htmlspecialchars($dateTo); ?>">
                <button type="submit" class="btn-add">Apply</button>
                <a class="btn-export" href="?tab=<?php echo $tab; ?>&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>&export=csv"><i class="fas fa-download"></i> CSV</a>
            </form>
        <?php else: ?>
            <div class="filter-bar">
                <span class="filter-bar__snapshot">Snapshot as of <?php echo date('Y-m-d H:i'); ?></span>
                <a class="btn-export" href="?tab=<?php echo $tab; ?>&export=csv"><i class="fas fa-download"></i> CSV</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($summary)): ?>
            <div class="summary-strip">
                <?php if (isset($summary['total_value'])): ?><strong>Total inventory value:</strong> <i class="help" tabindex="0" role="button" aria-label="About total inventory value" data-tip="Stock on hand now at cost: quantity times cost per unit for every ingredient not archived. Negative quantities count as nothing.">?</i> <?php echo stock_reports_money((float)$summary['total_value'], $currency_symbol); ?><?php endif; ?>
                    <?php if (isset($summary['total_spend'])): ?><strong>Total stock-in spend:</strong> <i class="help" tabindex="0" role="button" aria-label="About total stock-in spend" data-tip="Cost of all stock received between the From and To dates.">?</i> <?php echo stock_reports_money((float)$summary['total_spend'], $currency_symbol); ?><?php endif; ?>
                        <?php if (isset($summary['total_cost']) && $tab === 'usage'): ?><strong>Total usage cost:</strong> <i class="help" tabindex="0" role="button" aria-label="About total usage cost" data-tip="What the ingredients used by orders cost between the From and To dates.">?</i> <?php echo stock_reports_money((float)$summary['total_cost'], $currency_symbol); ?><?php endif; ?>
                            <?php if (isset($summary['total_cost']) && $tab === 'wastage'): ?><strong>Total wastage cost:</strong> <i class="help" tabindex="0" role="button" aria-label="About total wastage cost" data-tip="Cost of everything recorded as wasted between the From and To dates.">?</i> <?php echo stock_reports_money((float)$summary['total_cost'], $currency_symbol); ?> across <?php echo (int)($summary['ingredient_count'] ?? 0); ?> item(s)<?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        // Plain-words helper for each card (shown by the shared ? bubble).
        $cardTips = [
            'Tracked ingredients' => 'Ingredients that are not archived, as they are right now. Not tied to the date range.',
            'Average stock value' => 'Total stock value (quantity on hand times cost per unit, added up) divided by the number of ingredients tracked.',
            'Low stock items' => 'Ingredients above zero but at or below their reorder point, or their minimum quantity if no reorder point is set.',
            'Out of stock items' => 'Ingredients at zero or below that have stock history. Items never stocked are counted under Not yet stocked.',
            'Not yet stocked' => 'Ingredients at zero with no stock history at all, because opening stock was never recorded.',
            'Active batches' => 'Batches of any ingredient that are active and still have stock left.',
            'Stock-in records' => 'How many stock-in entries were logged between the From and To dates.',
            'Total stock-in spend' => 'Cost of all stock received between the From and To dates.',
            'Average stock-in value' => 'Total stock-in spend divided by the number of stock-in records.',
            'Ingredients received' => 'How many different ingredients were received between the From and To dates.',
            'Ingredients consumed' => 'Ingredients used by restaurant and room-service orders between the From and To dates, after cancelled orders put stock back.',
            'Total usage cost' => 'What the ingredients used by orders cost between the From and To dates, at the cost recorded when each was used.',
            'Top usage ingredient' => 'The ingredient with the highest usage cost in the date range, and its share of the total usage cost.',
            'Quantity used' => 'Total quantity used in the date range, shown separately for each unit of measure.',
            'Wastage entries' => 'How many wastage records are dated between the From and To dates.',
            'Wastage total' => 'Cost of everything recorded as wasted between the From and To dates.',
            'Wastage rate' => 'Wastage cost as a percent of the usage cost over the same dates. Archived ingredients are left out of both, the same as the Usage tab, so it can differ slightly from the Wastage total. Shows a dash (–) when there was no usage.',
            'Impacted ingredients' => 'How many different ingredients had wastage between the From and To dates.',
            'Top wastage reason' => 'The reason that cost the most in wastage in the date range, with its cost.',
            'Ingredients in yield analysis' => 'Ingredients that were used, wasted or expired between the From and To dates.',
            'Overall yield' => 'Value used as a percent of the value of everything that left stock (used plus wasted plus expired), each valued at the cost recorded when it happened, in the date range. Worked out by value so kilograms, litres and pieces are never added together. Higher is better. Shows a dash (–) when nothing was used or lost.',
            'At-risk ingredients' => 'Ingredients whose own yield is below 85 percent in the date range.',
            'Loss cost' => 'Cost of stock wasted or expired in the date range, at the cost recorded when it happened.',
            'Adjustment entries' => 'Every stock movement logged between the From and To dates: sales, deliveries, wastage, counts and hand changes.',
            'Increase events' => 'Movements in the date range that added stock.',
            'Decrease events' => 'Movements in the date range that took stock away.',
            'Source types' => 'How many different kinds of movement (sale, delivery, count and so on) appear in the date range.',
            'Net quantity change' => 'Stock added minus stock removed over the whole date range, shown separately for each unit of measure.',
            'Tracked batches' => 'Active batches with stock left that have an expiry date, plus batches that expired in the last 30 days.',
            'Expiring in 7 days' => 'Batches that expire between today and 7 days from now.',
            'Expired recently' => 'Batches already past their expiry date: those still listed, going back 30 days.',
            'At-risk value (7d)' => 'Quantity left times cost per unit, for batches that expire within 7 days or are already past their date.',
        ];
        ?>
        <?php if (!empty($analysis_cards)): ?>
            <section class="report-analysis" aria-label="Report analysis">
                <?php foreach ($analysis_cards as $card): ?>
                    <article class="report-analysis__card">
                        <p class="report-analysis__label"><?php echo htmlspecialchars($card['label']); ?><?php if (isset($cardTips[$card['label']])): ?> <i class="help" aria-label="About <?php echo htmlspecialchars($card['label']); ?>" data-tip="<?php echo htmlspecialchars($cardTips[$card['label']]); ?>">?</i><?php endif; ?></p>
                        <p class="report-analysis__value"><?php echo htmlspecialchars($card['value']); ?></p>
                        <?php if (!empty($card['hint'])): ?>
                            <p class="report-analysis__hint"><?php echo htmlspecialchars($card['hint']); ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <div class="table-responsive" data-rh-results>
            <table class="stock-table">
                <?php if ($tab === 'inventory'): ?>
                    <thead>
                        <tr>
                            <th>Ingredient</th>
                            <th>Category</th>
                            <th class="stock-table__num-head">Current qty <i class="help" aria-label="About current quantity" data-tip="Stock on hand right now, in the ingredient&#x27;s own unit.">?</i></th>
                            <th class="stock-table__num-head">Cost / unit <i class="help" aria-label="About cost per unit" data-tip="The ingredient&#x27;s current average cost for one unit.">?</i></th>
                            <th class="stock-table__num-head">Stock value <i class="help" aria-label="About stock value" data-tip="Current quantity times cost per unit. Negative stock counts as nothing.">?</i></th>
                            <th class="stock-table__num-head">Active batches <i class="help" aria-label="About active batches" data-tip="Batches of this ingredient that are active and still have stock left.">?</i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td <?php echo stock_reports_label_attr('Ingredient'); ?>><?php echo htmlspecialchars($r['name']); ?></td>
                                <td <?php echo stock_reports_label_attr('Category'); ?>><?php echo htmlspecialchars($r['category']); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Current qty'); ?>><?php echo number_format((float)$r['current_quantity'], 3) . ' ' . htmlspecialchars($r['unit']); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Cost / unit'); ?>><?php echo $currency_symbol . ' ' . number_format((float)$r['cost_per_unit'], 4); ?></td>
                                <td class="stock-table__num stock-table__value" <?php echo stock_reports_label_attr('Stock value'); ?>><?php echo $currency_symbol . ' ' . number_format((float)$r['stock_value'], 2); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Active batches'); ?>><?php echo (int)$r['active_batches']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php elseif ($tab === 'stock_in'): ?>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Ingredient</th>
                            <th>Batch</th>
                            <th class="stock-table__num-head">Qty <i class="help" aria-label="About quantity received" data-tip="How much was received in this entry, in the ingredient&#x27;s own unit.">?</i></th>
                            <th class="stock-table__num-head">Unit cost <i class="help" aria-label="About unit cost" data-tip="The price paid for one unit in this delivery.">?</i></th>
                            <th class="stock-table__num-head">Total <i class="help" aria-label="About total cost" data-tip="Quantity received times the unit cost for this entry.">?</i></th>
                            <th>Supplier</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="stock-table__muted" <?php echo stock_reports_label_attr('Date'); ?>><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))); ?></td>
                                <td <?php echo stock_reports_label_attr('Ingredient'); ?>><?php echo htmlspecialchars($r['ingredient_name']); ?></td>
                                <td <?php echo stock_reports_label_attr('Batch'); ?>><?php echo htmlspecialchars($r['batch_number'] ?: '—'); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Qty'); ?>><?php echo number_format((float)$r['quantity'], 3) . ' ' . htmlspecialchars($r['unit']); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Unit cost'); ?>><?php echo $currency_symbol . ' ' . number_format((float)$r['cost_per_unit'], 4); ?></td>
                                <td class="stock-table__num stock-table__value" <?php echo stock_reports_label_attr('Total'); ?>><?php echo $currency_symbol . ' ' . number_format((float)$r['cost_total'], 2); ?></td>
                                <td <?php echo stock_reports_label_attr('Supplier'); ?>><?php echo htmlspecialchars($r['supplier_name'] ?: '—'); ?></td>
                                <td class="stock-table__muted" <?php echo stock_reports_label_attr('By'); ?>><?php echo htmlspecialchars($r['created_by_name'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php elseif ($tab === 'usage'): ?>
                    <thead>
                        <tr>
                            <th>Ingredient</th>
                            <th class="stock-table__num-head">Quantity used <i class="help" aria-label="About quantity used" data-tip="How much of this ingredient was used by orders in the date range, after cancelled orders put stock back.">?</i></th>
                            <th class="stock-table__num-head">Cost <i class="help" aria-label="About usage cost" data-tip="Quantity used valued at the cost recorded when each use happened. The list is ranked by this.">?</i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td <?php echo stock_reports_label_attr('Ingredient'); ?>><?php echo htmlspecialchars($r['name']); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Quantity used'); ?>><?php echo number_format((float)$r['total_used'], 3) . ' ' . htmlspecialchars($r['unit']); ?></td>
                                <td class="stock-table__num stock-table__value" <?php echo stock_reports_label_attr('Cost'); ?>><?php echo $currency_symbol . ' ' . number_format((float)$r['total_cost'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php elseif ($tab === 'wastage'): ?>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Ingredient</th>
                            <th class="stock-table__num-head">Qty</th>
                            <th class="stock-table__num-head">Cost <i class="help" aria-label="About wastage cost" data-tip="What the wasted quantity cost, worked out when it was recorded.">?</i></th>
                            <th>Reason</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td <?php echo stock_reports_label_attr('Date'); ?>><?php echo htmlspecialchars($r['recorded_date']); ?></td>
                                <td <?php echo stock_reports_label_attr('Ingredient'); ?>><?php echo htmlspecialchars($r['ingredient_name']); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Qty'); ?>><?php echo number_format((float)$r['quantity'], 3) . ' ' . htmlspecialchars($r['unit']); ?></td>
                                <td class="stock-table__num stock-table__value" <?php echo stock_reports_label_attr('Cost'); ?>><?php echo $currency_symbol . ' ' . number_format((float)$r['wastage_cost'], 2); ?></td>
                                <td <?php echo stock_reports_label_attr('Reason'); ?>><?php echo htmlspecialchars($r['reason']); ?></td>
                                <td class="stock-table__muted" <?php echo stock_reports_label_attr('By'); ?>><?php echo htmlspecialchars($r['recorded_by_name'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php elseif ($tab === 'yield'): ?>
                    <thead>
                        <tr>
                            <th>Ingredient</th>
                            <th class="stock-table__num-head">Used <i class="help" aria-label="About used" data-tip="Quantity used by orders in the date range, in the ingredient&#x27;s own unit.">?</i></th>
                            <th class="stock-table__num-head">Wasted <i class="help" aria-label="About wasted" data-tip="Quantity recorded as wasted in the date range, including orders cancelled as wasted.">?</i></th>
                            <th class="stock-table__num-head">Expired <i class="help" aria-label="About expired" data-tip="Quantity written off because it passed its expiry date in the date range.">?</i></th>
                            <th class="stock-table__num-head">Yield % <i class="help" aria-label="About yield percent" data-tip="Used divided by used plus wasted plus expired. The badge changes at 95 percent and at 85 percent: below 85 needs attention.">?</i></th>
                            <th class="stock-table__num-head">Loss cost <i class="help" aria-label="About loss cost" data-tip="What the wasted and expired quantity cost, at the cost recorded when it happened. The list is ranked by this.">?</i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $used = (float)$r['used'];
                            $wasted = (float)$r['wasted'];
                            $expired = (float)$r['expired'];
                            $totalOut = $used + $wasted + $expired;
                            $yieldPct = $totalOut > 0 ? ($used / $totalOut) * 100 : 0;
                            $lossCost = (float)$r['loss_value']; // valued at the cost when it happened, so rows add up to the Loss cost card
                        ?>
                            <tr>
                                <td <?php echo stock_reports_label_attr('Ingredient'); ?>><?php echo htmlspecialchars($r['name']); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Used'); ?>><?php echo number_format($used, 3) . ' ' . htmlspecialchars($r['unit']); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Wasted'); ?>><?php echo number_format($wasted, 3); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Expired'); ?>><?php echo number_format($expired, 3); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Yield %'); ?>><span class="<?php echo stock_reports_yield_class($yieldPct); ?>"><?php echo number_format($yieldPct, 1); ?>%</span></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Loss cost'); ?>><?php echo $currency_symbol . ' ' . number_format($lossCost, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php elseif ($tab === 'adjustments'): ?>
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Ingredient</th>
                            <th>Source <i class="help" aria-label="About source" data-tip="What caused the movement, such as a sale, delivery, count, wastage or a hand change.">?</i></th>
                            <th class="stock-table__num-head">Δ Qty <i class="help" aria-label="About change in quantity" data-tip="How much stock moved. Plus means added, minus means removed.">?</i></th>
                            <th>Reason</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $q = (float)$r['quantity_change'];
                        ?>
                            <tr>
                                <td class="stock-table__muted" <?php echo stock_reports_label_attr('When'); ?>><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))); ?></td>
                                <td <?php echo stock_reports_label_attr('Ingredient'); ?>><?php echo htmlspecialchars($r['ingredient_name']); ?></td>
                                <td <?php echo stock_reports_label_attr('Source'); ?>><span class="source-pill"><?php echo htmlspecialchars($r['source_type']); ?></span></td>
                                <td class="stock-table__num <?php echo $q >= 0 ? 'qty-pos' : 'qty-neg'; ?>" <?php echo stock_reports_label_attr('Δ Qty'); ?>><?php echo ($q >= 0 ? '+' : '') . number_format($q, 3) . ' ' . htmlspecialchars($r['unit']); ?></td>
                                <td <?php echo stock_reports_label_attr('Reason'); ?>><?php echo htmlspecialchars($r['reason']); ?></td>
                                <td class="stock-table__muted" <?php echo stock_reports_label_attr('By'); ?>><?php echo htmlspecialchars($r['adjusted_by_name'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php elseif ($tab === 'expiry'): ?>
                    <thead>
                        <tr>
                            <th>Batch</th>
                            <th>Ingredient</th>
                            <th>Expiry date</th>
                            <th class="stock-table__num-head">Days left <i class="help" aria-label="About days left" data-tip="Days until the batch expires. The badge changes at 7 days and at 3 days. Expired batches show how many days ago.">?</i></th>
                            <th class="stock-table__num-head">Remaining <i class="help" aria-label="About remaining" data-tip="How much of this batch is left, in the ingredient&#x27;s own unit.">?</i></th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $d = (int)$r['days_left'];
                        ?>
                            <tr>
                                <td <?php echo stock_reports_label_attr('Batch'); ?>><strong><?php echo htmlspecialchars($r['batch_number']); ?></strong></td>
                                <td <?php echo stock_reports_label_attr('Ingredient'); ?>><?php echo htmlspecialchars($r['ingredient_name']); ?></td>
                                <td <?php echo stock_reports_label_attr('Expiry date'); ?>><?php echo htmlspecialchars($r['expiry_date']); ?></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Days left'); ?>><span class="<?php echo stock_reports_days_left_class($d); ?>"><?php echo $d < 0 ? 'Expired ' . abs($d) . 'd ago' : $d . 'd left'; ?></span></td>
                                <td class="stock-table__num" <?php echo stock_reports_label_attr('Remaining'); ?>><?php echo number_format((float)$r['quantity_remaining'], 3) . ' ' . htmlspecialchars($r['unit']); ?></td>
                                <td <?php echo stock_reports_label_attr('Status'); ?>><?php echo htmlspecialchars(ucfirst($r['status'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php endif; ?>
                <?php if (empty($rows)): ?>
                    <tbody>
                        <tr>
                            <td colspan="8" class="stock-table__empty">No data for the selected range.</td>
                        </tr>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($p = max(1, $page - 4); $p <= min($totalPages, $page + 4); $p++): ?>
                    <?php if ($p === $page): ?>
                        <span class="current"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="?tab=<?php echo $tab; ?>&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
    <script>
        (function() {
            const helpText = <?php echo json_encode($question_mark_help, JSON_UNESCAPED_SLASHES); ?>;
            const toggle = document.getElementById('rhHelpFloatingToggle') || document.querySelector('.rh-help-toggle');
            if (!toggle || !helpText) {
                return;
            }
            toggle.setAttribute('data-help', helpText);
        })();
    </script>
</body>

</html>

