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

// Guarded: admin/includes/procurement-schema.php defines the same two helpers (same queries), and the daily
// report timer can load this file at the end of any admin page (includes/daily-reports-send.php).
if (!function_exists('rh_table_exists')) {
    function rh_table_exists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('rh_column_exists')) {
    function rh_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
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
                   b.total_amount, b.total_with_vat, b.vat_amount, b.amount_paid, b.amount_due, b.payment_status, b.status,
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
        'Total (incl. VAT & levy)', 'Paid', 'Due', 'Payment Status', 'Booking Status',
        'Created',
    ];

    $rows = [];
    foreach ($stmt as $b) {
        $rows[] = [
            $b['id'], $b['booking_reference'], $b['guest_name'], $b['guest_email'], $b['guest_phone'],
            $b['room_name'], $b['check_in_date'], $b['check_out_date'], $b['number_of_nights'],
            $b['number_of_guests'], $b['adult_guests'], $b['child_guests'], $b['occupancy_type'],
            rh_money(rh_booking_full_total($b)), rh_money((float)$b['amount_paid']), rh_money((float)$b['amount_due']),
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
 * Per-station POS performance for a date range, on the till Z definition (owner 2026-11-11): an order counts
 * when it was sold at the till (by payment time, before refunds and voids) or charged to a guest room, inside
 * the trading days of [start, end] (first day's opening to the day after end's first opening) - the same window
 * and rule as the Z and End of Day. "Charged to room" is its own column. Voids: voided lines by void time.
 *
 * Two CSV sections joined into one file: top-level station summary + line detail, after a Z total block.
 */
function buildStationDayReport(string $startDate, string $endDate): array
{
    global $pdo;
    require_once __DIR__ . '/station-hours.php';
    require_once __DIR__ . '/../admin/includes/pos-shift-totals.php';

    $wS = rh_station_union_window_for_date($startDate);
    $wE = rh_station_union_window_for_date($endDate);
    $a = (string)$wS['start_sql'];
    $b = (string)$wE['trading_end_sql'];
    // Hotel-time label (the *_sql strings are in the DB session zone).
    $windowLabel = $wS['start']->format('j M Y H:i') . ' to ' . $wE['trading_end']->format('j M Y H:i');
    $z = rh_pos_z_window_totals($pdo, $a, $b);
    // Detail dates in hotel time: stored times are in the DB session zone (date_default_timezone_get()).
    $hotelOffset = rh_site_timezone()->getOffset($wS['start']) - (new DateTimeZone(date_default_timezone_get()))->getOffset($wS['start']);
    $q = rh_pos_station_report_sql($a, $b, $hotelOffset); // the summary + detail SQL (pure, unit tested)

    /* 1. Station summary */
    $sumStmt = $pdo->prepare($q['summary']['sql']);
    $sumStmt->execute($q['summary']['params']);

    /* 2. Per-item detail (date = when the sale, room charge or void happened) */
    $detStmt = $pdo->prepare($q['detail']['sql']);
    $detStmt->execute($q['detail']['params']);

    /* Render — Z block, then summary section, a blank row, then detail section */
    $fp = fopen('php://temp', 'r+');
    if ($fp === false) {
        throw new RuntimeException('Unable to open CSV stream');
    }

    fputcsv($fp, ['Station Day Report']);
    fputcsv($fp, ['Period', $startDate . ' to ' . $endDate]);
    fputcsv($fp, ['Trading window', $windowLabel . ' (opening to the next opening, the same window as the till Z and End of Day)']);
    fputcsv($fp, []);

    fputcsv($fp, ['ORDERS AND SALES (same rule as the till Z and End of Day, before refunds and voids)']);
    fputcsv($fp, ['Line', 'Orders', 'Amount']);
    fputcsv($fp, ['Paid at the till', $z['till_count'], rh_money((float)$z['till_amount'])]);
    fputcsv($fp, ['Charged to room (on guest bills, not in a drawer)', $z['room_charge_count'], rh_money((float)$z['room_charge_amount'])]);
    fputcsv($fp, ['Total', $z['orders'], rh_money((float)$z['sales'])]);
    fputcsv($fp, []);

    fputcsv($fp, ['SUMMARY BY STATION (line totals)']);
    fputcsv($fp, ['Station', 'Orders', 'Items', 'Till Sales', 'Charged to Room', 'Total Sales', 'Voided Lines', 'Voided Amount']);
    $totalRows = 0;
    foreach ($sumStmt as $r) {
        fputcsv($fp, [
            $r['station'], $r['orders'], (float)$r['items'],
            rh_money((float)$r['till_sales']), rh_money((float)$r['room_charged']),
            rh_money((float)$r['total_sales']),
            $r['voided_lines'], rh_money((float)$r['voided_amount']),
        ]);
        $totalRows++;
    }

    fputcsv($fp, []);
    fputcsv($fp, ['ITEM DETAIL']);
    fputcsv($fp, ['Date (hotel time)', 'Station', 'Type', 'Item', 'Qty Sold', 'Till Sales', 'Charged to Room', 'Voided']);
    foreach ($detStmt as $r) {
        fputcsv($fp, [
            $r['day'], $r['station'], $r['menu_type'], $r['item_name'],
            (float)$r['qty'], rh_money((float)$r['till_sales']),
            rh_money((float)$r['room_charged']), rh_money((float)$r['voided']),
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
        'window_label' => $windowLabel,
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
                COUNT(CASE WHEN COALESCE(payment_type, '') <> 'refund' THEN 1 END) AS tx_count,
                SUM(CASE WHEN payment_type = 'refund' THEN -total_amount ELSE total_amount END) AS gross,
                SUM(CASE WHEN payment_type = 'refund' THEN -vat_amount ELSE vat_amount END) AS vat
         FROM payments
         WHERE deleted_at IS NULL
           AND ((payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund')
                OR (payment_type = 'refund' AND refund_status IN ('completed','processing')))
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
        "SELECT payment_method, COUNT(CASE WHEN COALESCE(payment_type, '') <> 'refund' THEN 1 END) AS tx_count, SUM(CASE WHEN payment_type = 'refund' THEN -total_amount ELSE total_amount END) AS gross
         FROM payments
         WHERE deleted_at IS NULL
           AND ((payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund')
                OR (payment_type = 'refund' AND refund_status IN ('completed','processing')))
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

    $complianceStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS completed_sales,
            SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' AND (receipt_number IS NULL OR receipt_number = '') THEN 1 ELSE 0 END) AS missing_receipts,
            SUM(CASE WHEN invoice_generated = 1 AND (invoice_number IS NULL OR invoice_number = '') THEN 1 ELSE 0 END) AS generated_invoices_missing_numbers
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

    fputcsv($fp, []);

    /* Outstanding receivables (booking-level snapshot at "now") */
    $outStmt = $pdo->query(
        "SELECT b.booking_reference, b.guest_name, r.name AS room_name,
                b.check_in_date, b.check_out_date,
                b.total_amount, b.total_with_vat, b.vat_amount, b.amount_paid, b.amount_due, b.payment_status
         FROM bookings b
         LEFT JOIN rooms r ON r.id = b.room_id
         WHERE b.amount_due > 0
           AND b.status IN ('confirmed','checked-in','checked-out','pending','tentative')
         ORDER BY b.check_in_date DESC"
    );

    fputcsv($fp, ['OUTSTANDING RECEIVABLES (current snapshot)']);
    fputcsv($fp, ['Reference', 'Guest', 'Room', 'Check-in', 'Check-out', 'Total (incl. VAT & levy)', 'Paid', 'Due', 'Status']);
    $outstandingTotal = 0.0;
    foreach ($outStmt as $r) {
        fputcsv($fp, [
            $r['booking_reference'], $r['guest_name'], $r['room_name'],
            $r['check_in_date'], $r['check_out_date'],
            rh_money(rh_booking_full_total($r)),
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
        "SELECT id, user_id, closed_at, total_revenue, shift_date, user_name, expected_cash, declared_cash, variance_cash,
                expected_mobile, declared_mobile, variance_mobile,
                expected_card, declared_card, variance_card,
                orders_count, voids_count, voids_amount, notes
         FROM stock_shift_closes
         WHERE shift_date BETWEEN :s AND :e
         ORDER BY shift_date DESC, id DESC"
    );

    try {
        $closeStmt->execute(['s' => $startDate, 'e' => $endDate]);
        // Orders on the Z definition (owner 2026-11-11): till orders as stored + charged to room read now over the
        // close's own window. Drawer columns (expected / declared / variance) stay till-only, as stored.
        require_once __DIR__ . '/station-hours.php';
        require_once __DIR__ . '/../admin/includes/pos-shift-totals.php';
        fputcsv($fp, ['POS SHIFT CLOSES (Charged to room is read now; everything else as stored at close)']);
        fputcsv($fp, ['Date', 'Cashier', 'Exp Cash', 'Decl Cash', 'Var Cash',
                      'Exp Mobile', 'Decl Mobile', 'Var Mobile',
                      'Exp Card', 'Decl Card', 'Var Card',
                      'Orders (till + room)', 'Till Orders', 'Charged to Room Orders', 'Charged to Room Amount', 'Voids', 'Void Amount', 'Notes']);
        $closeRows = $closeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $closeRoom = rh_pos_closes_room_charges_batch($pdo, $closeRows); // two queries for all closes
        foreach ($closeRows as $r) {
            // NULL = the room read failed: say so, never 0 (absence rule). Till figures stay as stored.
            $roomOk = $closeRoom !== null;
            $r = rh_pos_close_add_room($r, $roomOk ? ($closeRoom[(int)$r['id']] ?? ['count' => 0, 'amount' => 0.0]) : ['count' => 0, 'amount' => 0.0]);
            fputcsv($fp, [
                $r['shift_date'], $r['user_name'],
                rh_money((float)$r['expected_cash']), rh_money((float)$r['declared_cash']), rh_money((float)$r['variance_cash']),
                rh_money((float)$r['expected_mobile']), rh_money((float)$r['declared_mobile']), rh_money((float)$r['variance_mobile']),
                rh_money((float)$r['expected_card']), rh_money((float)$r['declared_card']), rh_money((float)$r['variance_card']),
                $roomOk ? $r['orders_count'] : 'could not be loaded', $r['till_orders_count'],
                $roomOk ? $r['room_charge_count'] : 'Charged to room could not be loaded', $roomOk ? rh_money((float)$r['room_charge_amount']) : 'could not be loaded', $r['voids_count'], rh_money((float)$r['voids_amount']),
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
    /* Low / out: the ONE shared alert rule (config/database.php rh_stock_alert_summary).
     * Ingredients whose opening stock was never recorded are listed separately, not as alerts. */
    $thr  = rh_stock_threshold_sql('i');
    $hist = rh_stock_history_sql($pdo, 'i');
    $alertRows = $pdo->query(
        "SELECT i.id, i.name, i.category, i.unit, i.current_quantity, {$thr} AS threshold, i.cost_per_unit,
                (CASE WHEN {$hist} THEN 0 ELSE 1 END) AS never_stocked
         FROM stock_ingredients i
         WHERE i.is_archived = 0
           AND ((i.current_quantity <= 0.0001) OR ({$thr} > 0 AND i.current_quantity <= {$thr}))
         ORDER BY (i.current_quantity - {$thr}) ASC, i.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    fputcsv($fp, ['LOW / OUT OF STOCK INGREDIENTS']);
    fputcsv($fp, ['ID', 'Name', 'Category', 'Unit', 'On Hand', 'Min', 'Cost/unit']);
    $lowCount = 0;
    $neverRows = [];
    foreach ($alertRows as $r) {
        if ((int)$r['never_stocked'] === 1 && (float)$r['current_quantity'] <= 0) {
            $neverRows[] = $r;
            continue;
        }
        fputcsv($fp, [
            $r['id'], $r['name'], $r['category'], $r['unit'],
            (float)$r['current_quantity'], (float)$r['threshold'],
            rh_money((float)$r['cost_per_unit']),
        ]);
        $lowCount++;
    }
    fputcsv($fp, ['TOTAL LOW', $lowCount]);
    fputcsv($fp, []);
    fputcsv($fp, ['NOT YET STOCKED (opening stock not recorded - record a stock count)']);
    fputcsv($fp, ['ID', 'Name', 'Category', 'Unit', 'Min']);
    foreach ($neverRows as $r) {
        fputcsv($fp, [$r['id'], $r['name'], $r['category'], $r['unit'], (float)$r['threshold']]);
    }
    fputcsv($fp, ['TOTAL NOT YET STOCKED', count($neverRows)]);
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
                ROUND(GREATEST(0, i.current_quantity) * i.cost_per_unit, 2) AS value
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
