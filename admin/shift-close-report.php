<?php

/**
 * Shift-Close Z-Report — printable view for a single shift close record
 * or a summary of all closes for a business date.
 *
 * Modes:
 *   ?id=N         — single shift close, full detail
 *   ?date=YYYY-MM-DD — all closes for that date (admin/manager overview)
 */

require_once 'admin-init.php';

/** @var array $user */
/** @var string $csrf_token */
/** @var PDO $pdo */

$siteName       = getSetting('site_name') ?: "Rosalyn's Beach Hotel";
$currencySymbol = getSetting('currency_symbol') ?: 'K ';

$fmt = function (float $n) use ($currencySymbol): string {
    return htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') . ' ' . number_format($n, 2);
};

// ---------------------------------------------------------------------------
// Route: single close  (id=N)  OR  date summary  (date=YYYY-MM-DD)
// ---------------------------------------------------------------------------
$mode  = 'unknown';
$close = null;      // single record row
$closes = [];       // all records for date

// Safe defaults — assigned in the relevant branch; pre-declare to satisfy static analysis
$paidOrders    = [];
$voidedOrders  = [];
$topItems      = [];
$reportDate    = date('Y-m-d');
$agg           = [
    'total_revenue'  => 0.0,
    'orders_count' => 0,
    'voids_count' => 0,
    'voids_amount' => 0.0,
    'settled_from_tabs_count' => 0,
    'settled_from_tabs_amount' => 0.0,
    'expected_cash' => 0.0,
    'declared_cash' => 0.0,
    'variance_cash' => 0.0,
    'expected_mobile' => 0.0,
    'declared_mobile' => 0.0,
    'variance_mobile' => 0.0,
    'expected_card' => 0.0,
    'declared_card' => 0.0,
    'variance_card' => 0.0,
];
$aggTotalVar   = 0.0;

if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
    // ----- Single Z-report -----
    $mode = 'single';
    $closeId = (int)$_GET['id'];

    // FOH may only see their own closes; admin/manager can see any.
    if (in_array($user['role'], ['admin', 'manager'], true)) {
        $stmt = $pdo->prepare("SELECT * FROM stock_shift_closes WHERE id = ?");
        $stmt->execute([$closeId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM stock_shift_closes WHERE id = ? AND user_id = ?");
        $stmt->execute([$closeId, $user['id']]);
    }
    $close = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$close) {
        http_response_code(404);
        echo '<p style="font-family:sans-serif;padding:40px;">Record not found or access denied.</p>';
        exit;
    }

    // Orders paid during this cashier's shift window — derive window from stock_shift_closes shift_date
    // We look up the restaurant window setting to reconstruct start/end times, or we just use the full day
    // (business date 00:00 to 23:59 with paid_at filter is the safest fallback without the POS settings).
    $dayStart = $close['shift_date'] . ' 00:00:00';
    $dayEnd   = $close['shift_date'] . ' 23:59:59';

    // Try to get the restaurant open/close hours from site_settings
    $openTime  = getSetting('restaurant_open_time')  ?: '06:00';
    $closeTime = getSetting('restaurant_close_time') ?: '23:59';

    // If close time looks like it crosses midnight (e.g. "02:00"), handle that
    $windowStart = $close['shift_date'] . ' ' . $openTime . ':00';
    $closeHour   = (int)substr($closeTime, 0, 2);
    if ($closeHour < 6) {
        $nextDay     = date('Y-m-d', strtotime($close['shift_date'] . ' +1 day'));
        $windowEnd   = $nextDay . ' ' . $closeTime . ':59';
    } else {
        $windowEnd   = $close['shift_date'] . ' ' . $closeTime . ':59';
    }

    // Fetch all paid orders during this window for the FOH user who closed this shift.
    $ordersSql = "
        SELECT
            o.id,
            o.reference AS order_ref,
            o.total_amount,
            o.payment_method,
            o.paid_at,
            o.created_by,
            o.status,
            (SELECT COUNT(*) FROM stock_order_items WHERE order_id = o.id) AS item_count
        FROM stock_orders o
        WHERE o.status = 'paid'
          AND o.paid_at BETWEEN ? AND ?
        ";
    $ordersParams = [$windowStart, $windowEnd];
    $ordersSql    .= " AND o.created_by = ?";
    $ordersParams[] = (int)$close['user_id'];
    $ordersSql .= " ORDER BY o.paid_at ASC";
    $ordersStmt = $pdo->prepare($ordersSql);
    $ordersStmt->execute($ordersParams);
    $paidOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Voided orders
    $voidsSql = "
        SELECT o.id, o.reference AS order_ref, o.total_amount, o.payment_method, o.voided_at, o.void_reason, o.created_by
        FROM stock_orders o
        WHERE o.status = 'voided'
          AND o.voided_at BETWEEN ? AND ?
        ";
    $voidsParams = [$windowStart, $windowEnd];
    $voidsSql    .= " AND o.created_by = ?";
    $voidsParams[] = (int)$close['user_id'];
    $voidsSql .= " ORDER BY o.voided_at ASC";
    $voidsStmt = $pdo->prepare($voidsSql);
    $voidsStmt->execute($voidsParams);
    $voidedOrders = $voidsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Top-selling items during shift
    $topItemsSql = "
        SELECT
            oi.item_name AS name,
            oi.menu_type AS category,
            SUM(oi.quantity) AS qty,
            SUM(oi.line_total) AS revenue
        FROM stock_order_items oi
        JOIN stock_orders o ON o.id = oi.order_id
        WHERE o.status = 'paid'
          AND o.paid_at BETWEEN ? AND ?
          AND o.created_by = ?
        GROUP BY oi.item_name, oi.menu_type
        ORDER BY revenue DESC
        LIMIT 10
    ";
    $topParams = [$windowStart, $windowEnd, (int)$close['user_id']];
    $topItemsStmt = $pdo->prepare($topItemsSql);
    $topItemsStmt->execute($topParams);
    $topItems = $topItemsStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif (isset($_GET['date'])) {
    // ----- Date summary (all closes for a business date) — admin/manager only -----
    if (!in_array($user['role'], ['admin', 'manager'], true)) {
        http_response_code(403);
        echo '<p style="font-family:sans-serif;padding:40px;">Access denied.</p>';
        exit;
    }
    $mode = 'date';
    $reportDate = $_GET['date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) || !strtotime($reportDate)) {
        $reportDate = date('Y-m-d');
    }
    $stmt = $pdo->prepare("
        SELECT * FROM stock_shift_closes
        WHERE shift_date = ?
        ORDER BY closed_at ASC
    ");
    $stmt->execute([$reportDate]);
    $closes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aggregate for summary
    $agg = [
        'total_revenue'  => 0,
        'orders_count' => 0,
        'voids_count' => 0,
        'voids_amount' => 0,
        'settled_from_tabs_count' => 0,
        'settled_from_tabs_amount' => 0,
        'expected_cash' => 0,
        'declared_cash' => 0,
        'variance_cash' => 0,
        'expected_mobile' => 0,
        'declared_mobile' => 0,
        'variance_mobile' => 0,
        'expected_card' => 0,
        'declared_card' => 0,
        'variance_card' => 0,
    ];
    foreach ($closes as $c) {
        foreach (array_keys($agg) as $k) {
            $agg[$k] += (float)($c[$k] ?? 0);
        }
    }
    $aggTotalVar = $agg['variance_cash'] + $agg['variance_mobile'] + $agg['variance_card'];
} else {
    // No valid params — show the last 30 closes for this user (FOH) or last 30 for any (admin)
    if (in_array($user['role'], ['admin', 'manager'], true)) {
        $stmt = $pdo->prepare("SELECT * FROM stock_shift_closes ORDER BY closed_at DESC LIMIT 30");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM stock_shift_closes WHERE user_id = ? ORDER BY closed_at DESC LIMIT 30");
        $stmt->execute([$user['id']]);
    }
    $closes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $mode   = 'list';
    $reportDate = date('Y-m-d');
}

$vColor = function (float $v): string {
    return $v < -0.01 ? '#c82333' : ($v > 0.01 ? '#856404' : '#155724');
};
$vLabel = function (float $v): string {
    return ($v > 0.01 ? '+' : '') . number_format($v, 2);
};

$printTitle = match ($mode) {
    'single' => 'Z-Report #' . ($close['id'] ?? '') . ' — ' . ($close['shift_date'] ?? ''),
    'date'   => 'All Shift Closes — ' . ($reportDate ?? ''),
    default  => 'Shift Close History',
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($printTitle); ?> — <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        /* ------------------------------------------------------------------ */
        /* Base                                                                 */
        /* ------------------------------------------------------------------ */
        :root {
            --ink: #1f1f24;
            --muted: #6b7280;
            --border: #e5e7eb;
            --bg-muted: #f9fafb;
            --green-bg: #d1fae5;
            --green-fg: #065f46;
            --red-bg: #fee2e2;
            --red-fg: #991b1b;
            --amber-bg: #fef3c7;
            --amber-fg: #92400e;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            font-size: 14px;
            color: var(--ink);
            background: #f0f2f5;
            margin: 0;
            padding: 0;
        }

        /* ------------------------------------------------------------------ */
        /* Screen chrome                                                        */
        /* ------------------------------------------------------------------ */
        .scr-toolbar {
            background: #1f1f24;
            color: #fff;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .scr-toolbar h1 {
            font-size: 15px;
            font-weight: 600;
            margin: 0;
        }

        .scr-toolbar small {
            font-size: 12px;
            color: #9ca3af;
        }

        .scr-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn-print {
            background: #22c55e;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 9px 18px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .btn-print:hover {
            background: #16a34a;
        }

        .btn-secondary {
            background: #374151;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 9px 16px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        /* ------------------------------------------------------------------ */
        /* Report wrapper                                                       */
        /* ------------------------------------------------------------------ */
        .report-wrap {
            max-width: 820px;
            margin: 28px auto;
            padding: 0 16px 60px;
        }

        .report-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .08);
            overflow: hidden;
            margin-bottom: 24px;
        }

        /* ------------------------------------------------------------------ */
        /* Z-report header                                                      */
        /* ------------------------------------------------------------------ */
        .zr-header {
            background: var(--ink);
            color: #fff;
            padding: 28px 32px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
        }

        .zr-header__label {
            font-size: 10px;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: #9ca3af;
            margin-bottom: 4px;
        }

        .zr-header__name {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -.4px;
        }

        .zr-header__username {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .zr-header__meta {
            text-align: right;
        }

        .zr-header__date {
            font-size: 14px;
            color: #d1d5db;
        }

        .zr-header__time {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .zr-header__id {
            font-size: 11px;
            color: #6b7280;
            margin-top: 6px;
        }

        /* Shortfall banner */
        .shortfall-banner {
            background: #c82333;
            color: #fff;
            padding: 12px 32px;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .override-banner {
            background: #fef3c7;
            color: #92400e;
            padding: 10px 32px;
            font-size: 12px;
            border-left: 4px solid #f59e0b;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        /* ------------------------------------------------------------------ */
        /* Summary strip                                                        */
        /* ------------------------------------------------------------------ */
        .summary-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
        }

        .summary-cell {
            padding: 18px 20px;
            border-right: 1px solid var(--border);
        }

        .summary-cell:last-child {
            border-right: none;
        }

        .summary-cell__label {
            font-size: 10px;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .summary-cell__value {
            font-size: 20px;
            font-weight: 700;
            color: var(--ink);
        }

        .summary-cell--alert .summary-cell__value {
            color: #c82333;
        }

        .summary-cell--good .summary-cell__value {
            color: #065f46;
        }

        /* ------------------------------------------------------------------ */
        /* Section labels                                                       */
        /* ------------------------------------------------------------------ */
        .section-label {
            font-size: 10px;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--muted);
            padding: 14px 20px 6px;
            border-top: 1px solid var(--border);
        }

        /* ------------------------------------------------------------------ */
        /* Tables                                                               */
        /* ------------------------------------------------------------------ */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px 20px;
            text-align: left;
            font-size: 13px;
        }

        th {
            background: var(--bg-muted);
            color: var(--muted);
            font-weight: 600;
            font-size: 11px;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        td {
            border-top: 1px solid var(--border);
        }

        tr:hover td {
            background: var(--bg-muted);
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            padding: 3px 9px;
            border-radius: 20px;
            white-space: nowrap;
        }

        .badge--balanced {
            background: var(--green-bg);
            color: var(--green-fg);
        }

        .badge--shortfall {
            background: var(--red-bg);
            color: var(--red-fg);
        }

        .badge--overage {
            background: var(--amber-bg);
            color: var(--amber-fg);
        }

        .note-box {
            background: var(--bg-muted);
            border-left: 3px solid var(--muted);
            margin: 0 20px 16px;
            padding: 10px 14px;
            border-radius: 0 6px 6px 0;
            font-size: 13px;
            color: #4b5563;
        }

        /* Row total */
        .tfoot-row td {
            background: #f3f4f6;
            font-weight: 700;
            border-top: 2px solid #d1d5db;
        }

        /* ------------------------------------------------------------------ */
        /* Date summary page                                                    */
        /* ------------------------------------------------------------------ */
        .date-agg-header {
            background: #1d6a3e;
            color: #fff;
            padding: 24px 28px;
        }

        .date-agg-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .date-agg-header p {
            margin: 4px 0 0;
            font-size: 13px;
            color: #a7f3d0;
        }

        /* ------------------------------------------------------------------ */
        /* List page                                                            */
        /* ------------------------------------------------------------------ */
        .list-header {
            padding: 24px 28px 16px;
            border-bottom: 1px solid var(--border);
        }

        .list-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }

        .list-header p {
            margin: 4px 0 0;
            font-size: 13px;
            color: var(--muted);
        }

        /* ------------------------------------------------------------------ */
        /* Responsive table scroll container                                    */
        /* ------------------------------------------------------------------ */
        .tbl-outer {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .tbl-outer table {
            min-width: 460px;
        }

        /* ------------------------------------------------------------------ */
        /* Tablet / Mobile (≤ 1024 px)                                         */
        /* ------------------------------------------------------------------ */
        @media (max-width: 1024px) {

            /* Toolbar */
            .scr-toolbar {
                flex-direction: column;
                align-items: flex-start;
                padding: 10px 14px;
                gap: 8px;
                position: relative;
                /* un-sticky on tiny screens saves space */
            }

            .scr-toolbar h1 {
                font-size: 13px;
            }

            .scr-actions {
                flex-wrap: wrap;
                gap: 6px;
                width: 100%;
            }

            .btn-print,
            .btn-secondary {
                padding: 7px 11px;
                font-size: 12px;
                flex-shrink: 0;
            }

            /* Report layout */
            .report-wrap {
                max-width: 100%;
                margin: 0;
                padding: 0 0 48px;
            }

            .report-card {
                border-radius: 0;
                margin-bottom: 10px;
            }

            /* Z-report header */
            .zr-header {
                padding: 18px 16px;
                gap: 12px;
            }

            .zr-header__name {
                font-size: 18px;
            }

            /* Date / list headers */
            .date-agg-header {
                padding: 16px 16px;
            }

            .date-agg-header h2 {
                font-size: 16px;
            }

            .list-header {
                padding: 16px 16px 10px;
            }

            .list-header h2 {
                font-size: 16px;
            }

            /* Summary strip — 2 cols */
            .summary-strip {
                grid-template-columns: 1fr 1fr;
            }

            .summary-cell {
                padding: 12px 12px;
            }

            .summary-cell__value {
                font-size: 15px;
            }

            /* Section labels + banners */
            .section-label {
                padding: 10px 14px 4px;
            }

            .shortfall-banner,
            .override-banner {
                padding: 10px 14px;
                font-size: 12px;
            }

            .note-box {
                margin: 0 14px 12px;
                font-size: 12px;
            }

            /* Tables */
            th,
            td {
                padding: 8px 10px;
                font-size: 12px;
            }

            /* Hide lower-priority columns in the wide date-summary table */
            .col-hide-mobile {
                display: none;
            }
        }

        @media (max-width: 400px) {
            .summary-strip {
                grid-template-columns: 1fr;
            }

            .summary-cell {
                border-right: none;
                border-top: 1px solid var(--border);
            }

            .summary-cell:first-child {
                border-top: none;
            }
        }

        /* ------------------------------------------------------------------ */
        /* Print-specific                                                       */
        /* ------------------------------------------------------------------ */
        @media print {
            body {
                background: #fff;
            }

            .scr-toolbar {
                display: none !important;
            }

            .report-wrap {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }

            .report-card {
                box-shadow: none;
                border-radius: 0;
                margin-bottom: 12px;
            }

            tr:hover td {
                background: transparent;
            }

            a {
                color: inherit;
                text-decoration: none;
            }

            .no-print {
                display: none !important;
            }

            .col-hide-mobile {
                display: table-cell !important;
            }

            .tbl-outer {
                overflow-x: visible;
            }
        }

        /* zr-header stacks on narrow screens */
        @media (max-width: 600px) {
            .zr-header {
                flex-direction: column;
            }

            .zr-header__meta {
                text-align: left;
            }
        }
    </style>
</head>

<body>

    <!-- Screen toolbar (hidden on print) -->
    <div class="scr-toolbar no-print">
        <div>
            <h1><i class="fas fa-file-invoice-dollar"></i> <?php echo htmlspecialchars($printTitle); ?></h1>
            <small><?php echo htmlspecialchars($siteName); ?></small>
        </div>
        <div class="scr-actions">
            <a class="btn-secondary" href="javascript:void(0)" onclick="history.back()"><i class="fas fa-arrow-left"></i> Back</a>
            <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print / Save PDF</button>
            <a class="btn-secondary" href="pos.php"><i class="fas fa-cash-register"></i> POS</a>
            <?php if (in_array($user['role'], ['admin', 'manager'], true)): ?>
                <a class="btn-secondary" href="shift-close-report.php?date=<?php echo urlencode(date('Y-m-d')); ?>"><i class="fas fa-layer-group"></i> Today's Closes</a>
            <?php endif; ?>
            <a class="btn-secondary" href="shift-close-report.php"><i class="fas fa-history"></i> History</a>
        </div>
    </div>

    <div class="report-wrap">

        <?php if ($mode === 'single' && $close): ?>
            <!-- ====================================================================== -->
            <!-- SINGLE Z-REPORT                                                         -->
            <!-- ====================================================================== -->
            <?php
            $vc     = (float)$close['variance_cash'];
            $vm     = (float)$close['variance_mobile'];
            $vca    = (float)$close['variance_card'];
            $vTotal = $vc + $vm + $vca;
            $hasShortfall = $vc < -0.01 || $vm < -0.01 || $vca < -0.01;
            ?>
            <div class="report-card">

                <!-- Header -->
                <div class="zr-header">
                    <div>
                        <div class="zr-header__label">Z-Report · Shift Close</div>
                        <div class="zr-header__name"><?php echo htmlspecialchars($close['user_name']); ?></div>
                        <div class="zr-header__username">ID <?php echo (int)$close['user_id']; ?></div>
                    </div>
                    <div class="zr-header__meta">
                        <div class="zr-header__date"><?php echo htmlspecialchars($close['shift_date']); ?></div>
                        <div class="zr-header__time">Closed <?php echo htmlspecialchars(substr($close['closed_at'] ?? '', 11, 5)); ?></div>
                        <div class="zr-header__id">Record #<?php echo (int)$close['id']; ?></div>
                        <?php if ($close['ip_address']): ?>
                            <div class="zr-header__id">IP <?php echo htmlspecialchars($close['ip_address']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Shortfall banner -->
                <?php if ($hasShortfall): ?>
                    <div class="shortfall-banner">
                        <i class="fas fa-exclamation-triangle"></i>
                        SHORTFALL DETECTED — cumulative variance: <?php echo ($vTotal > 0 ? '+' : '') . number_format($vTotal, 2); ?>.
                        Management review required.
                    </div>
                <?php endif; ?>

                <!-- Override notice -->
                <?php if (!empty($close['override_applied'])): ?>
                    <div class="override-banner">
                        <i class="fas fa-shield-alt" style="margin-top:2px;"></i>
                        <div><strong>Override applied.</strong>
                            <?php if (!empty($close['override_reason'])): ?> Reason: "<?php echo htmlspecialchars($close['override_reason']); ?>"<?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Summary strip -->
                <div class="summary-strip">
                    <div class="summary-cell">
                        <div class="summary-cell__label">Total Revenue</div>
                        <div class="summary-cell__value"><?php echo $fmt((float)$close['total_revenue']); ?></div>
                    </div>
                    <div class="summary-cell">
                        <div class="summary-cell__label">Paid Orders</div>
                        <div class="summary-cell__value"><?php echo number_format((int)$close['orders_count']); ?></div>
                    </div>
                    <div class="summary-cell<?php echo (float)$close['voids_amount'] > 0 ? ' summary-cell--alert' : ''; ?>">
                        <div class="summary-cell__label">Voids</div>
                        <div class="summary-cell__value"><?php echo (int)$close['voids_count']; ?> / <?php echo $fmt((float)$close['voids_amount']); ?></div>
                    </div>
                    <div class="summary-cell">
                        <div class="summary-cell__label">Settled Tabs</div>
                        <div class="summary-cell__value">
                            <?php echo (int)($close['settled_from_tabs_count'] ?? 0) > 0
                                ? ((int)$close['settled_from_tabs_count'] . ' / ' . $fmt((float)$close['settled_from_tabs_amount']))
                                : '—'; ?>
                        </div>
                    </div>
                </div>

                <!-- Tender reconciliation -->
                <div class="section-label">Tender Reconciliation</div>
                <div class="tbl-outer">
                    <table>
                        <thead>
                            <tr>
                                <th>Tender</th>
                                <th class="text-right">System (Expected)</th>
                                <th class="text-right">Counted (Declared)</th>
                                <th class="text-right">Variance</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (
                                [
                                    'cash'   => ['<i class="fas fa-money-bill-wave"></i> Cash', $vc],
                                    'mobile' => ['<i class="fas fa-mobile-alt"></i> Mobile Money', $vm],
                                    'card'   => ['<i class="fas fa-credit-card"></i> Card', $vca],
                                ] as $k => [$lbl, $v]
                            ):
                                $isBalanced = abs($v) < 0.02;
                                $isShort    = $v < -0.01;
                                $badgeCls   = $isBalanced ? 'balanced' : ($isShort ? 'shortfall' : 'overage');
                                $badgeText  = $isBalanced ? 'BALANCED' : ($isShort ? 'SHORTFALL' : 'OVERAGE');
                            ?>
                                <tr>
                                    <td><?php echo $lbl; ?></td>
                                    <td class="text-right"><?php echo $fmt((float)$close["expected_$k"]); ?></td>
                                    <td class="text-right"><?php echo $fmt((float)$close["declared_$k"]); ?></td>
                                    <td class="text-right" style="font-weight:700; color:<?php echo $vColor($v); ?>;"><?php echo $vLabel($v); ?></td>
                                    <td class="text-center"><span class="badge badge--<?php echo $badgeCls; ?>"><?php echo $badgeText; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="tfoot-row">
                                <td>TOTAL</td>
                                <td class="text-right"><?php echo $fmt((float)$close['expected_cash'] + (float)$close['expected_mobile'] + (float)$close['expected_card']); ?></td>
                                <td class="text-right"><?php echo $fmt((float)$close['declared_cash'] + (float)$close['declared_mobile'] + (float)$close['declared_card']); ?></td>
                                <td class="text-right" style="font-weight:700; color:<?php echo $vColor($vTotal); ?>;"><?php echo $vLabel($vTotal); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Shift note -->
                <?php if (!empty($close['notes'])): ?>
                    <div class="section-label">Shift Notes</div>
                    <div class="note-box"><?php echo nl2br(htmlspecialchars($close['notes'])); ?></div>
                <?php endif; ?>

            </div><!-- /report-card tender section -->

            <!-- Top-selling items -->
            <?php if (!empty($topItems)): ?>
                <div class="report-card">
                    <div class="section-label" style="border-top:none; padding-top:16px;">Top Items — <?php echo htmlspecialchars($close['shift_date']); ?></div>
                    <div class="tbl-outer">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Item</th>
                                    <th>Category</th>
                                    <th class="text-right">Qty</th>
                                    <th class="text-right">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topItems as $i => $item): ?>
                                    <tr>
                                        <td style="color:var(--muted); font-size:12px;"><?php echo $i + 1; ?></td>
                                        <td><?php echo htmlspecialchars($item['name'] ?? 'Unknown'); ?></td>
                                        <td style="color:var(--muted);"><?php echo htmlspecialchars($item['category'] ?? ''); ?></td>
                                        <td class="text-right"><?php echo number_format((int)$item['qty']); ?></td>
                                        <td class="text-right" style="font-weight:600;"><?php echo $fmt((float)$item['revenue']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Paid orders list -->
            <?php if (!empty($paidOrders)): ?>
                <div class="report-card">
                    <div class="section-label" style="border-top:none; padding-top:16px;">
                        Paid Orders — <?php echo count($paidOrders); ?> transactions
                    </div>
                    <div class="tbl-outer">
                        <table>
                            <thead>
                                <tr>
                                    <th>Ref</th>
                                    <th>Time</th>
                                    <th>Method</th>
                                    <th class="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paidOrders as $o): ?>
                                    <tr>
                                        <td style="font-family:monospace; font-size:12px;"><?php echo htmlspecialchars($o['order_ref']); ?></td>
                                        <td style="color:var(--muted); font-size:12px;"><?php echo htmlspecialchars(substr($o['paid_at'] ?? '', 11, 5)); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst($o['payment_method'] ?? '')); ?></td>
                                        <td class="text-right" style="font-weight:600;"><?php echo $fmt((float)$o['total_amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Voided orders -->
            <?php if (!empty($voidedOrders)): ?>
                <div class="report-card">
                    <div class="section-label" style="border-top:none; background:#fff1f2; padding-top:16px; color:#c82333;">
                        <i class="fas fa-ban"></i> Voided Orders — <?php echo count($voidedOrders); ?>
                    </div>
                    <div class="tbl-outer">
                        <table>
                            <thead>
                                <tr>
                                    <th>Ref</th>
                                    <th>Voided At</th>
                                    <th>Reason</th>
                                    <th class="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($voidedOrders as $o): ?>
                                    <tr>
                                        <td style="font-family:monospace; font-size:12px;"><?php echo htmlspecialchars($o['order_ref']); ?></td>
                                        <td style="color:var(--muted); font-size:12px;"><?php echo htmlspecialchars(substr($o['voided_at'] ?? '', 11, 5)); ?></td>
                                        <td style="color:var(--muted);"><?php echo htmlspecialchars($o['void_reason'] ?? '—'); ?></td>
                                        <td class="text-right" style="color:#c82333; font-weight:600;"><?php echo $fmt((float)$o['total_amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif ($mode === 'date'): ?>
            <!-- ====================================================================== -->
            <!-- DATE SUMMARY — all closes for this date                                 -->
            <!-- ====================================================================== -->
            <div class="report-card">
                <div class="date-agg-header">
                    <h2><i class="fas fa-layer-group"></i> Shift Closes — <?php echo htmlspecialchars($reportDate); ?></h2>
                    <p><?php echo count($closes); ?> cashier session<?php echo count($closes) !== 1 ? 's' : ''; ?> recorded</p>
                </div>

                <?php if ($aggTotalVar < -0.01): ?>
                    <div class="shortfall-banner">
                        <i class="fas fa-exclamation-triangle"></i>
                        TOTAL SHORTFALL ACROSS ALL CASHIERS: <?php echo number_format(abs($aggTotalVar), 2); ?> — investigate before EOD sign-off.
                    </div>
                <?php endif; ?>

                <!-- Aggregate summary strip -->
                <div class="summary-strip">
                    <div class="summary-cell">
                        <div class="summary-cell__label">Total Revenue</div>
                        <div class="summary-cell__value"><?php echo $fmt($agg['total_revenue']); ?></div>
                    </div>
                    <div class="summary-cell">
                        <div class="summary-cell__label">All Orders</div>
                        <div class="summary-cell__value"><?php echo number_format((int)$agg['orders_count']); ?></div>
                    </div>
                    <div class="summary-cell<?php echo $agg['voids_amount'] > 0 ? ' summary-cell--alert' : ''; ?>">
                        <div class="summary-cell__label">Total Voids</div>
                        <div class="summary-cell__value"><?php echo (int)$agg['voids_count']; ?> / <?php echo $fmt($agg['voids_amount']); ?></div>
                    </div>
                    <div class="summary-cell<?php echo abs($aggTotalVar) > 0.01 ? ' summary-cell--alert' : ' summary-cell--good'; ?>">
                        <div class="summary-cell__label">Net Variance</div>
                        <div class="summary-cell__value"><?php echo $vLabel($aggTotalVar); ?></div>
                    </div>
                </div>

                <!-- Per-cashier table -->
                <div class="section-label" style="border-top:1px solid var(--border);">Cashier Breakdown</div>
                <?php if (empty($closes)): ?>
                    <div style="padding:32px 20px; text-align:center; color:var(--muted);">No shift closes recorded for this date.</div>
                <?php else: ?>
                    <div class="tbl-outer">
                        <table>
                            <thead>
                                <tr>
                                    <th>Cashier</th>
                                    <th class="col-hide-mobile">Closed</th>
                                    <th class="text-right">Revenue</th>
                                    <th class="text-right col-hide-mobile">Orders</th>
                                    <th class="text-right col-hide-mobile">Voids</th>
                                    <th class="text-right col-hide-mobile">Cash Var</th>
                                    <th class="text-right col-hide-mobile">Mobile Var</th>
                                    <th class="text-right col-hide-mobile">Card Var</th>
                                    <th class="text-right">Variance</th>
                                    <th class="text-center col-hide-mobile">Override</th>
                                    <th class="text-center no-print">Report</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($closes as $c):
                                    $cVar = (float)$c['variance_cash'] + (float)$c['variance_mobile'] + (float)$c['variance_card'];
                                    $rowHasShortfall = $c['variance_cash'] < -0.01 || $c['variance_mobile'] < -0.01 || $c['variance_card'] < -0.01;
                                ?>
                                    <tr<?php echo $rowHasShortfall ? ' style="background:#fff5f5;"' : ''; ?>>
                                        <td style="font-weight:600;"><?php echo htmlspecialchars($c['user_name']); ?></td>
                                        <td class="col-hide-mobile" style="color:var(--muted); font-size:12px;"><?php echo htmlspecialchars(substr($c['closed_at'] ?? '', 11, 5)); ?></td>
                                        <td class="text-right" style="font-weight:600;"><?php echo $fmt((float)$c['total_revenue']); ?></td>
                                        <td class="text-right col-hide-mobile"><?php echo number_format((int)$c['orders_count']); ?></td>
                                        <td class="text-right col-hide-mobile<?php echo (float)$c['voids_amount'] > 0 ? '" style="color:#c82333;' : ''; ?>"><?php echo (int)$c['voids_count']; ?> / <?php echo $fmt((float)$c['voids_amount']); ?></td>
                                        <td class="text-right col-hide-mobile" style="color:<?php echo $vColor((float)$c['variance_cash']); ?>; font-weight:600;"><?php echo $vLabel((float)$c['variance_cash']); ?></td>
                                        <td class="text-right col-hide-mobile" style="color:<?php echo $vColor((float)$c['variance_mobile']); ?>; font-weight:600;"><?php echo $vLabel((float)$c['variance_mobile']); ?></td>
                                        <td class="text-right col-hide-mobile" style="color:<?php echo $vColor((float)$c['variance_card']); ?>; font-weight:600;"><?php echo $vLabel((float)$c['variance_card']); ?></td>
                                        <td class="text-right" style="color:<?php echo $vColor($cVar); ?>; font-weight:700;"><?php echo $vLabel($cVar); ?></td>
                                        <td class="text-center col-hide-mobile">
                                            <?php if (!empty($c['override_applied'])): ?>
                                                <span class="badge badge--overage" title="<?php echo htmlspecialchars($c['override_reason'] ?? ''); ?>">OVERRIDE</span>
                                            <?php else: ?>
                                                <span style="color:var(--muted);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center no-print">
                                            <a href="shift-close-report.php?id=<?php echo (int)$c['id']; ?>" target="_blank"
                                                style="color:#1d6a3e; text-decoration:none; font-size:12px; font-weight:600;">
                                                View <i class="fas fa-external-link-alt"></i>
                                            </a>
                                        </td>
                                        </tr>
                                    <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="tfoot-row">
                                    <td>TOTALS</td>
                                    <td class="col-hide-mobile"></td>
                                    <td class="text-right"><?php echo $fmt($agg['total_revenue']); ?></td>
                                    <td class="text-right col-hide-mobile"><?php echo number_format((int)$agg['orders_count']); ?></td>
                                    <td class="text-right col-hide-mobile"><?php echo (int)$agg['voids_count']; ?> / <?php echo $fmt($agg['voids_amount']); ?></td>
                                    <td class="text-right col-hide-mobile" style="color:<?php echo $vColor($agg['variance_cash']); ?>; font-weight:700;"><?php echo $vLabel($agg['variance_cash']); ?></td>
                                    <td class="text-right col-hide-mobile" style="color:<?php echo $vColor($agg['variance_mobile']); ?>; font-weight:700;"><?php echo $vLabel($agg['variance_mobile']); ?></td>
                                    <td class="text-right col-hide-mobile" style="color:<?php echo $vColor($agg['variance_card']); ?>; font-weight:700;"><?php echo $vLabel($agg['variance_card']); ?></td>
                                    <td class="text-right" style="color:<?php echo $vColor($aggTotalVar); ?>; font-weight:700;"><?php echo $vLabel($aggTotalVar); ?></td>
                                    <td class="col-hide-mobile"></td>
                                    <td class="no-print"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- ====================================================================== -->
            <!-- HISTORY LIST — recent shift closes                                       -->
            <!-- ====================================================================== -->
            <div class="report-card">
                <div class="list-header">
                    <h2><i class="fas fa-history"></i> Shift Close History</h2>
                    <p>Last 30 records<?php echo in_array($user['role'], ['admin', 'manager'], true) ? ' — all cashiers' : ' — your closes'; ?></p>
                </div>
                <?php if (empty($closes)): ?>
                    <div style="padding:40px; text-align:center; color:var(--muted);">No shift closes recorded yet.</div>
                <?php else: ?>
                    <div class="tbl-outer">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Cashier</th>
                                    <th class="col-hide-mobile">Closed At</th>
                                    <th class="text-right">Revenue</th>
                                    <th class="text-right col-hide-mobile">Orders</th>
                                    <th class="text-right">Variance</th>
                                    <th class="text-center no-print">Report</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($closes as $c):
                                    $cVar = (float)$c['variance_cash'] + (float)$c['variance_mobile'] + (float)$c['variance_card'];
                                    $isShort = $c['variance_cash'] < -0.01 || $c['variance_mobile'] < -0.01 || $c['variance_card'] < -0.01;
                                ?>
                                    <tr<?php echo $isShort ? ' style="background:#fff5f5;"' : ''; ?>>
                                        <td style="font-size:13px;"><?php echo htmlspecialchars($c['shift_date']); ?></td>
                                        <td style="font-weight:600;"><?php echo htmlspecialchars($c['user_name']); ?></td>
                                        <td class="col-hide-mobile" style="color:var(--muted); font-size:12px;"><?php echo htmlspecialchars(substr($c['closed_at'] ?? '', 11, 5)); ?></td>
                                        <td class="text-right" style="font-weight:600;"><?php echo $fmt((float)$c['total_revenue']); ?></td>
                                        <td class="text-right col-hide-mobile"><?php echo number_format((int)$c['orders_count']); ?></td>
                                        <td class="text-right" style="color:<?php echo $vColor($cVar); ?>; font-weight:700;"><?php echo $vLabel($cVar); ?></td>
                                        <td class="text-center no-print">
                                            <a href="shift-close-report.php?id=<?php echo (int)$c['id']; ?>" target="_blank"
                                                style="color:#1d6a3e; text-decoration:none; font-size:12px; font-weight:600;">
                                                View <i class="fas fa-external-link-alt"></i>
                                            </a>
                                        </td>
                                        </tr>
                                    <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div style="text-align:center; font-size:11px; color:var(--muted); padding-top:8px;" class="no-print">
            <?php echo htmlspecialchars($siteName); ?> · Generated <?php echo date('Y-m-d H:i'); ?> by <?php echo htmlspecialchars($user['full_name']); ?>
        </div>

    </div><!-- /report-wrap -->
</body>

</html>

