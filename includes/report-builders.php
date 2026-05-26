<?php
/**
 * CSV report builders.
 *
 * Each function returns ['filename' => string, 'csv' => string] so the result
 * can be saved to disk OR attached directly to an email without round-trip
 * through the filesystem.
 *
 * All builders are read-only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/* -------------------------------------------------------------------------- */
/* Internals                                                                  */
/* -------------------------------------------------------------------------- */

/**
 * Render rows + header to a CSV string in memory.
 */
function rh_csv_render(array $header, iterable $rows): string
{
    $fp = fopen('php://temp', 'r+');
    if ($fp === false) {
        throw new RuntimeException('Unable to open in-memory CSV stream');
    }
    fputcsv($fp, $header);
    foreach ($rows as $row) {
        fputcsv($fp, array_map(static fn ($v) => is_scalar($v) || $v === null ? (string)$v : json_encode($v), $row));
    }
    rewind($fp);
    $csv = stream_get_contents($fp) ?: '';
    fclose($fp);
    return $csv;
}

function rh_money(float $amount): string
{
    return number_format($amount, 2, '.', '');
}

function rh_filename(string $base, string $start, string $end): string
{
    $base = preg_replace('/[^a-z0-9_-]/i', '-', $base);
    $start = preg_replace('/[^0-9-]/', '', $start);
    $end = preg_replace('/[^0-9-]/', '', $end);
    return ($start === $end ? "{$base}-{$start}" : "{$base}-{$start}_to_{$end}") . '.csv';
}

function rh_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function rh_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/* -------------------------------------------------------------------------- */
/* Bookings report                                                            */
/* -------------------------------------------------------------------------- */

/**
 * Bookings created or active in [start, end]. Includes financial fields and status.
 */
function buildBookingsReport(string $startDate, string $endDate): array
{
    global $pdo;

    $sql = "SELECT b.id, b.booking_reference, b.guest_name, b.guest_email, b.guest_phone,
                   r.name AS room_name, b.check_in_date, b.check_out_date, b.number_of_nights,
                   b.number_of_guests, b.adult_guests, b.child_guests, b.occupancy_type,
                   b.total_amount, b.amount_paid, b.amount_due, b.payment_status, b.status,
                   b.created_at
            FROM bookings b
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE (DATE(b.created_at) BETWEEN :s1 AND :e1)
               OR (b.check_in_date BETWEEN :s2 AND :e2)
            ORDER BY b.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['s1' => $startDate, 'e1' => $endDate, 's2' => $startDate, 'e2' => $endDate]);

    $header = [
        'ID', 'Reference', 'Guest', 'Email', 'Phone',
        'Room', 'Check-in', 'Check-out', 'Nights',
        'Guests', 'Adults', 'Children', 'Occupancy',
        'Total', 'Paid', 'Due', 'Payment Status', 'Booking Status',
        'Created',
    ];

    $rows = [];
    foreach ($stmt as $b) {
        $rows[] = [
            $b['id'], $b['booking_reference'], $b['guest_name'], $b['guest_email'], $b['guest_phone'],
            $b['room_name'], $b['check_in_date'], $b['check_out_date'], $b['number_of_nights'],
            $b['number_of_guests'], $b['adult_guests'], $b['child_guests'], $b['occupancy_type'],
            rh_money((float)$b['total_amount']), rh_money((float)$b['amount_paid']), rh_money((float)$b['amount_due']),
            $b['payment_status'], $b['status'],
            $b['created_at'],
        ];
    }

    return [
        'filename' => rh_filename('bookings-report', $startDate, $endDate),
        'csv'      => rh_csv_render($header, $rows),
        'rows'     => count($rows),
    ];
}

/* -------------------------------------------------------------------------- */
/* Station day report (kitchen / bar / coffee_bar)                            */
/* -------------------------------------------------------------------------- */

/**
 * Per-station POS performance for a date range:
 * total orders, total items, gross revenue (paid), voided count/amount.
 *
 * Two CSV sections joined into one file: top-level station summary + line detail.
 */
function buildStationDayReport(string $startDate, string $endDate): array
{
    global $pdo;

    /* 1. Station summary */
    $summarySql = "SELECT COALESCE(NULLIF(oi.station, ''), 'unassigned') AS station,
                          COUNT(DISTINCT o.id) AS orders,
                          SUM(oi.quantity) AS items,
                          SUM(CASE WHEN o.status='paid' THEN oi.line_total ELSE 0 END) AS gross_paid,
                          SUM(CASE WHEN o.status='voided' THEN oi.line_total ELSE 0 END) AS voided_amount,
                          SUM(CASE WHEN o.status='voided' THEN 1 ELSE 0 END) AS voided_lines
                   FROM stock_order_items oi
                   INNER JOIN stock_orders o ON o.id = oi.order_id
                   WHERE DATE(o.created_at) BETWEEN :s AND :e
                   GROUP BY station
                   ORDER BY gross_paid DESC";
    $sumStmt = $pdo->prepare($summarySql);
    $sumStmt->execute(['s' => $startDate, 'e' => $endDate]);

    /* 2. Per-item detail */
    $detailSql = "SELECT DATE(o.created_at) AS day,
                         COALESCE(NULLIF(oi.station,''),'unassigned') AS station,
                         oi.menu_type, oi.item_name,
                         SUM(oi.quantity) AS qty,
                         SUM(oi.line_total) AS line_total,
                         SUM(CASE WHEN o.status='paid' THEN oi.line_total ELSE 0 END) AS revenue,
                         SUM(CASE WHEN o.status='voided' THEN oi.line_total ELSE 0 END) AS voided
                  FROM stock_order_items oi
                  INNER JOIN stock_orders o ON o.id = oi.order_id
                  WHERE DATE(o.created_at) BETWEEN :s AND :e
                  GROUP BY day, station, oi.menu_type, oi.item_name
                  ORDER BY day, station, revenue DESC";
    $detStmt = $pdo->prepare($detailSql);
    $detStmt->execute(['s' => $startDate, 'e' => $endDate]);

    /* Render — write summary section, then a blank row, then detail section */
    $fp = fopen('php://temp', 'r+');
    if ($fp === false) {
        throw new RuntimeException('Unable to open CSV stream');
    }

    fputcsv($fp, ['Station Day Report']);
    fputcsv($fp, ['Period', $startDate . ' to ' . $endDate]);
    fputcsv($fp, []);

    fputcsv($fp, ['SUMMARY BY STATION']);
    fputcsv($fp, ['Station', 'Orders', 'Items', 'Gross Paid', 'Voided Lines', 'Voided Amount']);
    $totalRows = 0;
    foreach ($sumStmt as $r) {
        fputcsv($fp, [
            $r['station'], $r['orders'], (float)$r['items'],
            rh_money((float)$r['gross_paid']),
            $r['voided_lines'], rh_money((float)$r['voided_amount']),
        ]);
        $totalRows++;
    }

    fputcsv($fp, []);
    fputcsv($fp, ['ITEM DETAIL']);
    fputcsv($fp, ['Day', 'Station', 'Type', 'Item', 'Qty', 'Line Total', 'Paid Revenue', 'Voided']);
    foreach ($detStmt as $r) {
        fputcsv($fp, [
            $r['day'], $r['station'], $r['menu_type'], $r['item_name'],
            (float)$r['qty'], rh_money((float)$r['line_total']),
            rh_money((float)$r['revenue']), rh_money((float)$r['voided']),
        ]);
        $totalRows++;
    }

    rewind($fp);
    $csv = stream_get_contents($fp) ?: '';
    fclose($fp);

    return [
        'filename' => rh_filename('station-day-report', $startDate, $endDate),
        'csv'      => $csv,
        'rows'     => $totalRows,
    ];
}

/* -------------------------------------------------------------------------- */
/* Accounting summary                                                         */
/* -------------------------------------------------------------------------- */

function buildAccountingSummary(string $startDate, string $endDate): array
{
    global $pdo;

    $fp = fopen('php://temp', 'r+');
    if ($fp === false) {
        throw new RuntimeException('Unable to open CSV stream');
    }

    fputcsv($fp, ['Accounting Summary']);
    fputcsv($fp, ['Period', $startDate . ' to ' . $endDate]);
    fputcsv($fp, []);

    /* Revenue by booking_type from payments */
    $sumStmt = $pdo->prepare(
        "SELECT booking_type,
                COUNT(*) AS tx_count,
                SUM(total_amount) AS gross,
                SUM(vat_amount) AS vat
         FROM payments
         WHERE deleted_at IS NULL
           AND payment_status IN ('completed','paid','partial')
           AND payment_date BETWEEN :s AND :e
         GROUP BY booking_type"
    );
    $sumStmt->execute(['s' => $startDate, 'e' => $endDate]);

    fputcsv($fp, ['REVENUE BY BOOKING TYPE']);
    fputcsv($fp, ['Booking Type', 'Transactions', 'Gross', 'VAT']);
    $tot = ['tx' => 0, 'gross' => 0.0, 'vat' => 0.0];
    foreach ($sumStmt as $r) {
        fputcsv($fp, [$r['booking_type'], $r['tx_count'], rh_money((float)$r['gross']), rh_money((float)$r['vat'])]);
        $tot['tx']    += (int)$r['tx_count'];
        $tot['gross'] += (float)$r['gross'];
        $tot['vat']   += (float)$r['vat'];
    }
    fputcsv($fp, ['TOTAL', $tot['tx'], rh_money($tot['gross']), rh_money($tot['vat'])]);

    fputcsv($fp, []);

    /* Payment method breakdown */
    $methodStmt = $pdo->prepare(
        "SELECT payment_method, COUNT(*) AS tx_count, SUM(total_amount) AS gross
         FROM payments
         WHERE deleted_at IS NULL
           AND payment_status IN ('completed','paid','partial')
           AND payment_date BETWEEN :s AND :e
         GROUP BY payment_method
         ORDER BY gross DESC"
    );
    $methodStmt->execute(['s' => $startDate, 'e' => $endDate]);

    fputcsv($fp, ['BY PAYMENT METHOD']);
    fputcsv($fp, ['Method', 'Transactions', 'Gross']);
    foreach ($methodStmt as $r) {
        fputcsv($fp, [
            ucfirst(str_replace('_', ' ', (string)$r['payment_method'])),
            $r['tx_count'], rh_money((float)$r['gross']),
        ]);
    }

    fputcsv($fp, []);

    $hasMraStatus = rh_column_exists($pdo, 'payments', 'mra_status');
    $mraPendingSql = $hasMraStatus
        ? "SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' AND mra_status NOT IN ('accepted','not_required') THEN 1 ELSE 0 END)"
        : "0";
    $complianceStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS completed_sales,
            SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' AND (receipt_number IS NULL OR receipt_number = '') THEN 1 ELSE 0 END) AS missing_receipts,
            SUM(CASE WHEN invoice_generated = 1 AND (invoice_number IS NULL OR invoice_number = '') THEN 1 ELSE 0 END) AS generated_invoices_missing_numbers,
            {$mraPendingSql} AS mra_pending_or_unsubmitted
         FROM payments
         WHERE deleted_at IS NULL
           AND payment_date BETWEEN :s AND :e"
    );
    $complianceStmt->execute(['s' => $startDate, 'e' => $endDate]);
    $compliance = $complianceStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $paidPosWithoutLedger = 0;
    if (rh_table_exists($pdo, 'stock_orders')) {
        $posGapStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM stock_orders so
             LEFT JOIN payments p ON p.booking_type = 'restaurant'
                AND p.booking_id = so.id
                AND COALESCE(p.payment_type, '') <> 'refund'
                AND p.deleted_at IS NULL
             WHERE so.status = 'paid'
               AND DATE(COALESCE(so.paid_at, so.created_at)) BETWEEN :s AND :e
               AND p.id IS NULL"
        );
        $posGapStmt->execute(['s' => $startDate, 'e' => $endDate]);
        $paidPosWithoutLedger = (int)$posGapStmt->fetchColumn();
    }

    fputcsv($fp, ['COMPLIANCE CHECKS']);
    fputcsv($fp, ['Check', 'Count']);
    fputcsv($fp, ['Completed sales in period', (int)($compliance['completed_sales'] ?? 0)]);
    fputcsv($fp, ['Completed sales missing receipt number', (int)($compliance['missing_receipts'] ?? 0)]);
    fputcsv($fp, ['Generated invoices missing invoice number', (int)($compliance['generated_invoices_missing_numbers'] ?? 0)]);
    fputcsv($fp, ['Paid POS orders missing payments ledger row', $paidPosWithoutLedger]);
    fputcsv($fp, ['MRA pending/unsubmitted sales', (int)($compliance['mra_pending_or_unsubmitted'] ?? 0)]);

    fputcsv($fp, []);

    /* Outstanding receivables (booking-level snapshot at "now") */
    $outStmt = $pdo->query(
        "SELECT b.booking_reference, b.guest_name, r.name AS room_name,
                b.check_in_date, b.check_out_date,
                b.total_amount, b.amount_paid, b.amount_due, b.payment_status
         FROM bookings b
         LEFT JOIN rooms r ON r.id = b.room_id
         WHERE b.amount_due > 0
           AND b.status IN ('confirmed','checked-in','checked-out','pending','tentative')
         ORDER BY b.check_in_date DESC"
    );

    fputcsv($fp, ['OUTSTANDING RECEIVABLES (current snapshot)']);
    fputcsv($fp, ['Reference', 'Guest', 'Room', 'Check-in', 'Check-out', 'Total', 'Paid', 'Due', 'Status']);
    $outstandingTotal = 0.0;
    foreach ($outStmt as $r) {
        fputcsv($fp, [
            $r['booking_reference'], $r['guest_name'], $r['room_name'],
            $r['check_in_date'], $r['check_out_date'],
            rh_money((float)$r['total_amount']),
            rh_money((float)$r['amount_paid']),
            rh_money((float)$r['amount_due']),
            $r['payment_status'],
        ]);
        $outstandingTotal += (float)$r['amount_due'];
    }
    fputcsv($fp, ['TOTAL OUTSTANDING', '', '', '', '', '', '', rh_money($outstandingTotal)]);

    fputcsv($fp, []);

    /* POS shift closes (variance) */
    $closeStmt = $pdo->prepare(
        "SELECT shift_date, user_name, expected_cash, declared_cash, variance_cash,
                expected_mobile, declared_mobile, variance_mobile,
                expected_card, declared_card, variance_card,
                orders_count, voids_count, voids_amount, notes
         FROM stock_shift_closes
         WHERE shift_date BETWEEN :s AND :e
         ORDER BY shift_date DESC, id DESC"
    );

    try {
        $closeStmt->execute(['s' => $startDate, 'e' => $endDate]);
        fputcsv($fp, ['POS SHIFT CLOSES']);
        fputcsv($fp, ['Date', 'Cashier', 'Exp Cash', 'Decl Cash', 'Var Cash',
                      'Exp Mobile', 'Decl Mobile', 'Var Mobile',
                      'Exp Card', 'Decl Card', 'Var Card',
                      'Orders', 'Voids', 'Void Amount', 'Notes']);
        foreach ($closeStmt as $r) {
            fputcsv($fp, [
                $r['shift_date'], $r['user_name'],
                rh_money((float)$r['expected_cash']), rh_money((float)$r['declared_cash']), rh_money((float)$r['variance_cash']),
                rh_money((float)$r['expected_mobile']), rh_money((float)$r['declared_mobile']), rh_money((float)$r['variance_mobile']),
                rh_money((float)$r['expected_card']), rh_money((float)$r['declared_card']), rh_money((float)$r['variance_card']),
                $r['orders_count'], $r['voids_count'], rh_money((float)$r['voids_amount']),
                $r['notes'],
            ]);
        }
    } catch (Throwable $e) {
        fputcsv($fp, ['POS SHIFT CLOSES — unavailable: ' . $e->getMessage()]);
    }

    rewind($fp);
    $csv = stream_get_contents($fp) ?: '';
    fclose($fp);

    return [
        'filename' => rh_filename('accounting-summary', $startDate, $endDate),
        'csv'      => $csv,
        'totals'   => $tot,
        'outstanding' => $outstandingTotal,
    ];
}

/* -------------------------------------------------------------------------- */
/* Stock health                                                               */
/* -------------------------------------------------------------------------- */

function buildStockHealthReport(): array
{
    global $pdo;

    $fp = fopen('php://temp', 'r+');
    if ($fp === false) {
        throw new RuntimeException('Unable to open CSV stream');
    }

    fputcsv($fp, ['Stock Health Report']);
    fputcsv($fp, ['Generated', date('Y-m-d H:i:s')]);
    fputcsv($fp, []);

    /* Low stock */
    $low = $pdo->query(
        "SELECT id, name, category, unit, current_quantity, min_quantity, cost_per_unit
         FROM stock_ingredients
         WHERE is_archived = 0 AND current_quantity <= min_quantity
         ORDER BY (current_quantity - min_quantity) ASC"
    );
    fputcsv($fp, ['LOW / OUT OF STOCK INGREDIENTS']);
    fputcsv($fp, ['ID', 'Name', 'Category', 'Unit', 'On Hand', 'Min', 'Cost/unit']);
    $lowCount = 0;
    foreach ($low as $r) {
        fputcsv($fp, [
            $r['id'], $r['name'], $r['category'], $r['unit'],
            (float)$r['current_quantity'], (float)$r['min_quantity'],
            rh_money((float)$r['cost_per_unit']),
        ]);
        $lowCount++;
    }
    fputcsv($fp, ['TOTAL LOW', $lowCount]);
    fputcsv($fp, []);

    /* Expiring batches (next 14 days) */
    $exp = $pdo->query(
        "SELECT b.id, i.name, b.batch_number, b.quantity_remaining, b.expiry_date,
                DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry, b.status
         FROM stock_batches b
         INNER JOIN stock_ingredients i ON i.id = b.ingredient_id
         WHERE b.status = 'active'
           AND b.expiry_date IS NOT NULL
           AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
         ORDER BY b.expiry_date ASC"
    );
    fputcsv($fp, ['BATCHES EXPIRING WITHIN 14 DAYS']);
    fputcsv($fp, ['ID', 'Ingredient', 'Batch', 'Qty Remaining', 'Expiry Date', 'Days', 'Status']);
    $expCount = 0;
    foreach ($exp as $r) {
        fputcsv($fp, [
            $r['id'], $r['name'], $r['batch_number'],
            (float)$r['quantity_remaining'], $r['expiry_date'],
            (int)$r['days_to_expiry'], $r['status'],
        ]);
        $expCount++;
    }
    fputcsv($fp, ['TOTAL EXPIRING', $expCount]);
    fputcsv($fp, []);

    /* Stock value snapshot */
    $val = $pdo->query(
        "SELECT i.id, i.name, i.unit, i.current_quantity, i.cost_per_unit,
                ROUND(i.current_quantity * i.cost_per_unit, 2) AS value
         FROM stock_ingredients i
         WHERE i.is_archived = 0
         ORDER BY value DESC"
    );
    fputcsv($fp, ['STOCK VALUE SNAPSHOT (top by value)']);
    fputcsv($fp, ['ID', 'Name', 'Unit', 'On Hand', 'Cost/unit', 'Value']);
    $totalValue = 0.0;
    $valueCount = 0;
    foreach ($val as $r) {
        fputcsv($fp, [
            $r['id'], $r['name'], $r['unit'],
            (float)$r['current_quantity'],
            rh_money((float)$r['cost_per_unit']),
            rh_money((float)$r['value']),
        ]);
        $totalValue += (float)$r['value'];
        $valueCount++;
    }
    fputcsv($fp, ['TOTAL STOCK VALUE', '', '', '', '', rh_money($totalValue)]);

    rewind($fp);
    $csv = stream_get_contents($fp) ?: '';
    fclose($fp);

    return [
        'filename'    => 'stock-health-' . date('Y-m-d') . '.csv',
        'csv'         => $csv,
        'low_count'   => $lowCount,
        'expiring'    => $expCount,
        'total_value' => $totalValue,
        'rows'        => $valueCount,
    ];
}
