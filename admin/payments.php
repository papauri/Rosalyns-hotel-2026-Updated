<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

require_once '../includes/modal.php';
require_once '../includes/alert.php';
require_once 'includes/finance-schema.php';

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
$conferenceFields = finance_conference_fields($pdo);

// Module flags — gate structural UI (filter options, links) by the active
// business preset. Historical ledger rows are never hidden.
$mod_bookings   = function_exists('moduleEnabled') && moduleEnabled('bookings');
$mod_pos        = function_exists('moduleEnabled') && moduleEnabled('pos');
$mod_conference = function_exists('moduleEnabled') && moduleEnabled('conference');
$mod_gym        = function_exists('moduleEnabled') && moduleEnabled('gym');
$mod_events     = function_exists('isEventsEnabled') && isEventsEnabled();
$paymentTransactionColumn = finance_payment_transaction_column($pdo);
$paymentColumns = finance_table_columns($pdo, 'payments');
$hasMraStatus = isset($paymentColumns['mra_status']);

// Get filter parameters
$bookingType = isset($_GET['booking_type']) ? $_GET['booking_type'] : '';
$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';
$paymentMethod = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$searchText = trim((string)($_GET['search_text'] ?? ''));
// "Outstanding balances" view: shows accounts (bookings/conference/gym/event)
// that still owe money — i.e. amount_due > 0 — NOT payment-ledger rows. This is
// what the dashboard "Outstanding Balances" card counts; those accounts often
// have zero payment rows yet, so they can never appear in the payments list.
$showOutstanding = (($_GET['balance'] ?? '') === 'outstanding');
$has_active_payment_filters = $bookingType !== '' || $bookingId > 0 || $status !== '' || $paymentMethod !== '' || $startDate !== '' || $endDate !== '' || $searchText !== '';

// Preset scoping: by default the list shows only rows whose module is enabled
// (a gym sees gym/event/till payments, not room-booking history). Explicit
// ?booking_type= deep links and ?scope=all bypass it; nothing is ever deleted.
$scopeAll            = (($_GET['scope'] ?? '') === 'all');
$allBookingTypes     = ['room', 'conference', 'restaurant', 'gym', 'event'];
$enabledBookingTypes = function_exists('rh_enabled_booking_types') ? rh_enabled_booking_types() : [];
$scopeActive         = $bookingType === '' && !$scopeAll
    && !empty($enabledBookingTypes)
    && count($enabledBookingTypes) < count($allBookingTypes);
$hiddenBookingTypes  = $scopeActive ? array_values(array_diff($allBookingTypes, $enabledBookingTypes)) : [];

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Build query
$sql = "
    SELECT
        p.*,
        CASE
            WHEN p.booking_type = 'room' THEN CONCAT(b.guest_name, ' (', b.booking_reference, ')')
            WHEN p.booking_type = 'conference' THEN CONCAT(ci.{$conferenceFields['company']}, ' (', ci.{$conferenceFields['reference']}, ')')
            WHEN p.booking_type = 'restaurant' THEN CONCAT('Restaurant order ', so.reference, COALESCE(CONCAT(' - ', NULLIF(so.customer_name, '')), ''))
            WHEN p.booking_type = 'gym' THEN CONCAT(gi.name, ' (', gi.reference_number, ')')
            WHEN p.booking_type = 'event' THEN CONCAT(ei.name, ' (', ei.reference_number, ')')
            ELSE 'Unknown'
        END as booking_description,
        CASE
            WHEN p.booking_type = 'room' THEN b.booking_reference
            WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['reference']}
            WHEN p.booking_type = 'restaurant' THEN so.reference
            WHEN p.booking_type = 'gym' THEN gi.reference_number
            WHEN p.booking_type = 'event' THEN ei.reference_number
            ELSE NULL
        END as booking_reference,
        CASE
            WHEN p.booking_type = 'room' THEN b.guest_email
            WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['email']}
            WHEN p.booking_type = 'gym' THEN gi.email
            WHEN p.booking_type = 'event' THEN ei.email
            ELSE NULL
        END as contact_email,
        CASE
            WHEN p.booking_type = 'room' THEN b.guest_phone
            WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['phone']}
            WHEN p.booking_type = 'gym' THEN gi.phone
            WHEN p.booking_type = 'event' THEN ei.phone
            ELSE NULL
        END as contact_phone,
        -- Outstanding balance still owed on the underlying account (not the
        -- individual payment). Restaurant/POS orders settle at the till, so
        -- they carry no receivable.
        CASE
            WHEN p.booking_type = 'room' THEN b.amount_due
            WHEN p.booking_type = 'conference' THEN ci.amount_due
            WHEN p.booking_type = 'gym' THEN gi.amount_due
            WHEN p.booking_type = 'event' THEN ei.amount_due
            ELSE NULL
        END as account_amount_due
    FROM payments p
    LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
    LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
    LEFT JOIN stock_orders so ON p.booking_type = 'restaurant' AND p.booking_id = so.id
    LEFT JOIN gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id
    LEFT JOIN event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id
";

$where_conditions = ["p.deleted_at IS NULL"];
$params = [];

if ($bookingType) {
    $where_conditions[] = "p.booking_type = ?";
    $params[] = $bookingType;
} elseif ($scopeActive) {
    $where_conditions[] = "p.booking_type IN (" . implode(',', array_fill(0, count($enabledBookingTypes), '?')) . ")";
    $params = array_merge($params, $enabledBookingTypes);
}

if ($bookingId) {
    $where_conditions[] = "p.booking_id = ?";
    $params[] = $bookingId;
}

if ($searchText !== '') {
    $where_conditions[] = "(
        p.payment_reference LIKE ?
        OR p.receipt_number LIKE ?
        OR p.invoice_number LIKE ?
        OR p.booking_reference LIKE ?
        OR p.{$paymentTransactionColumn} LIKE ?
        OR p.payment_method LIKE ?
        OR p.payment_status LIKE ?
        OR CAST(p.booking_id AS CHAR) LIKE ?
        OR CAST(p.total_amount AS CHAR) LIKE ?
        OR COALESCE(b.guest_name, '') LIKE ?
        OR COALESCE(b.guest_email, '') LIKE ?
        OR COALESCE(b.guest_phone, '') LIKE ?
        OR COALESCE(b.booking_reference, '') LIKE ?
        OR COALESCE(ci.{$conferenceFields['company']}, '') LIKE ?
        OR COALESCE(ci.{$conferenceFields['reference']}, '') LIKE ?
        OR COALESCE(ci.{$conferenceFields['email']}, '') LIKE ?
        OR COALESCE(ci.{$conferenceFields['phone']}, '') LIKE ?
        OR COALESCE(so.reference, '') LIKE ?
        OR COALESCE(so.customer_name, '') LIKE ?
    )";
    $searchLike = '%' . $searchText . '%';
    $params = array_merge($params, array_fill(0, 19, $searchLike));
}

if ($status) {
    $where_conditions[] = "p.payment_status = ?";
    $params[] = $status;
}

if ($paymentMethod) {
    $where_conditions[] = "p.payment_method = ?";
    $params[] = $paymentMethod;
}

if ($startDate) {
    $where_conditions[] = "p.payment_date >= ?";
    $params[] = $startDate;
}

if ($endDate) {
    $where_conditions[] = "p.payment_date <= ?";
    $params[] = $endDate;
}

if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(' AND ', $where_conditions);
}

// Get total count
$countSql = "
    SELECT COUNT(*) as total
    FROM payments p
    LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
    LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
    LEFT JOIN stock_orders so ON p.booking_type = 'restaurant' AND p.booking_id = so.id
    LEFT JOIN gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id
    LEFT JOIN event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id
";

if (!empty($where_conditions)) {
    $countSql .= " WHERE " . implode(' AND ', $where_conditions);
}
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Add ordering and pagination
$sql .= " ORDER BY p.payment_date DESC, p.created_at DESC";
$sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Outstanding balances (accounts still owing) ────────────────────────────
// Built from the source tables, scoped to the enabled modules — mirrors the
// dashboard "Outstanding Balances" card so the numbers line up exactly.
$outstandingRows = [];
$outstandingTotal = 0.0;
if ($showOutstanding) {
    $confRef = $conferenceFields['reference'];
    $confCompany = $conferenceFields['company'];
    $confContact = $conferenceFields['contact_name'] ?? 'contact_person';
    $outstandingSources = [];
    if ($mod_bookings) {
        $outstandingSources[] = "SELECT 'room' AS src, id, booking_reference AS ref, guest_name AS client, status, total_amount, amount_paid, amount_due FROM bookings WHERE amount_due > 0 AND status IN ('pending','confirmed','checked-in')";
    }
    if ($mod_conference) {
        $outstandingSources[] = "SELECT 'conference' AS src, id, {$confRef} AS ref, COALESCE(NULLIF({$confCompany},''), {$confContact}) AS client, status, total_amount, amount_paid, amount_due FROM conference_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled')";
    }
    if ($mod_gym) {
        $outstandingSources[] = "SELECT 'gym' AS src, id, reference_number AS ref, name AS client, status, total_amount, amount_paid, amount_due FROM gym_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled','closed')";
    }
    if ($mod_events) {
        $outstandingSources[] = "SELECT 'event' AS src, id, reference_number AS ref, name AS client, status, total_amount, amount_paid, amount_due FROM event_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled')";
    }
    foreach ($outstandingSources as $osql) {
        try {
            foreach ($pdo->query($osql)->fetchAll(PDO::FETCH_ASSOC) as $oRow) {
                $outstandingRows[] = $oRow;
                $outstandingTotal += (float)($oRow['amount_due'] ?? 0);
            }
        } catch (Throwable $e) {
            // A module's table may not exist yet — skip it.
        }
    }
    usort($outstandingRows, static fn($a, $b) => (float)$b['amount_due'] <=> (float)$a['amount_due']);
}

// Get unique payment methods for filter
$methodsStmt = $pdo->query("
    SELECT DISTINCT payment_method
    FROM payments
    WHERE deleted_at IS NULL
    ORDER BY payment_method
");
$paymentMethods = $methodsStmt->fetchAll(PDO::FETCH_COLUMN);

$totalPages = ceil($total / $limit);

// How many rows the preset scoping is currently hiding (drives the notice).
$hiddenScopedCount = 0;
if ($scopeActive && $hiddenBookingTypes !== []) {
    $hPh = implode(',', array_fill(0, count($hiddenBookingTypes), '?'));
    $hStmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE deleted_at IS NULL AND booking_type IN ($hPh)");
    $hStmt->execute($hiddenBookingTypes);
    $hiddenScopedCount = (int)$hStmt->fetchColumn();
}

// ──────────────────────────────────────────────────────────────────────────
// Filter-aware analytics (respect current GET filters)
// ──────────────────────────────────────────────────────────────────────────
$analyticsWhere = ["deleted_at IS NULL"];
$analyticsParams = [];
if ($bookingType) {
    $analyticsWhere[] = "booking_type = ?";
    $analyticsParams[] = $bookingType;
} elseif ($scopeActive) {
    // Keep the summary cards consistent with the scoped list below them.
    $analyticsWhere[] = "booking_type IN (" . implode(',', array_fill(0, count($enabledBookingTypes), '?')) . ")";
    $analyticsParams = array_merge($analyticsParams, $enabledBookingTypes);
}
if ($bookingId) {
    $analyticsWhere[] = "booking_id = ?";
    $analyticsParams[] = $bookingId;
}
if ($searchText !== '') {
    $analyticsWhere[] = "(
                payment_reference LIKE ?
                OR receipt_number LIKE ?
                OR invoice_number LIKE ?
                OR booking_reference LIKE ?
                OR {$paymentTransactionColumn} LIKE ?
                OR payment_method LIKE ?
                OR payment_status LIKE ?
                OR CAST(booking_id AS CHAR) LIKE ?
                OR EXISTS (
                        SELECT 1
                        FROM bookings bx
                        WHERE payments.booking_type = 'room'
                            AND payments.booking_id = bx.id
                            AND (
                                bx.guest_name LIKE ?
                                OR bx.guest_email LIKE ?
                                OR bx.guest_phone LIKE ?
                                OR bx.booking_reference LIKE ?
                            )
                )
                OR EXISTS (
                        SELECT 1
                        FROM conference_inquiries cix
                        WHERE payments.booking_type = 'conference'
                            AND payments.booking_id = cix.id
                            AND (
                                cix.{$conferenceFields['company']} LIKE ?
                                OR cix.{$conferenceFields['reference']} LIKE ?
                                OR cix.{$conferenceFields['email']} LIKE ?
                                OR cix.{$conferenceFields['phone']} LIKE ?
                            )
                )
                OR EXISTS (
                        SELECT 1
                        FROM stock_orders sox
                        WHERE payments.booking_type = 'restaurant'
                            AND payments.booking_id = sox.id
                            AND (
                                sox.reference LIKE ?
                                OR sox.customer_name LIKE ?
                            )
                )
                OR EXISTS (
                        SELECT 1
                        FROM gym_inquiries gix
                        WHERE payments.booking_type = 'gym'
                            AND payments.booking_id = gix.id
                            AND (
                                gix.name LIKE ?
                                OR gix.reference_number LIKE ?
                                OR gix.email LIKE ?
                                OR gix.phone LIKE ?
                            )
                )
                OR EXISTS (
                        SELECT 1
                        FROM event_inquiries eix
                        WHERE payments.booking_type = 'event'
                            AND payments.booking_id = eix.id
                            AND (
                                eix.name LIKE ?
                                OR eix.reference_number LIKE ?
                                OR eix.email LIKE ?
                                OR eix.phone LIKE ?
                            )
                )
        )";
    $analyticsSearchLike = '%' . $searchText . '%';
    $analyticsParams = array_merge($analyticsParams, array_fill(0, 26, $analyticsSearchLike));
}
if ($status) {
    $analyticsWhere[] = "payment_status = ?";
    $analyticsParams[] = $status;
}
if ($paymentMethod) {
    $analyticsWhere[] = "payment_method = ?";
    $analyticsParams[] = $paymentMethod;
}
if ($startDate) {
    $analyticsWhere[] = "payment_date >= ?";
    $analyticsParams[] = $startDate;
}
if ($endDate) {
    $analyticsWhere[] = "payment_date <= ?";
    $analyticsParams[] = $endDate;
}
$analyticsWhereSql = "WHERE " . implode(' AND ', $analyticsWhere);

$kpiStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS txn_count,
        COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS gross_collected,
        COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS vat_collected,
        COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') THEN total_amount ELSE 0 END), 0) AS pending_total,
        COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') THEN 1 ELSE 0 END), 0) AS pending_count,
        COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN total_amount ELSE 0 END), 0) AS refunds_total,
        COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN 1 ELSE 0 END), 0) AS refunds_count
    FROM payments
    $analyticsWhereSql
");
$kpiStmt->execute($analyticsParams);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Today / This month (always — independent of filters, useful sidebar info)
$periodStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN DATE(payment_date) = CURDATE() AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS today_collected,
        COALESCE(SUM(CASE WHEN DATE(payment_date) = CURDATE() AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN 1 ELSE 0 END), 0) AS today_count,
        COALESCE(SUM(CASE WHEN YEAR(payment_date)=YEAR(CURDATE()) AND MONTH(payment_date)=MONTH(CURDATE()) AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS month_collected,
        COALESCE(SUM(CASE WHEN payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS week_collected
    FROM payments
    WHERE deleted_at IS NULL
");
$periodStmt->execute();
$period = $periodStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Breakdown by booking_type (filter-aware)
$bySourceStmt = $pdo->prepare("
    SELECT booking_type,
           COUNT(*) AS txns,
           COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS collected
    FROM payments
    $analyticsWhereSql
    GROUP BY booking_type
    ORDER BY collected DESC
");
$bySourceStmt->execute($analyticsParams);
$bySource = $bySourceStmt->fetchAll(PDO::FETCH_ASSOC);

// Breakdown by payment method (filter-aware)
$byMethodStmt = $pdo->prepare("
    SELECT payment_method,
           COUNT(*) AS txns,
           COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS collected
    FROM payments
    $analyticsWhereSql
    GROUP BY payment_method
    ORDER BY collected DESC
");
$byMethodStmt->execute($analyticsParams);
$byMethod = $byMethodStmt->fetchAll(PDO::FETCH_ASSOC);

// Status mix for the visual pill bar
$byStatusStmt = $pdo->prepare("
    SELECT payment_status, COUNT(*) AS txns, COALESCE(SUM(total_amount),0) AS amt
    FROM payments
    $analyticsWhereSql
    GROUP BY payment_status
");
$byStatusStmt->execute($analyticsParams);
$byStatus = $byStatusStmt->fetchAll(PDO::FETCH_ASSOC);

$gross_collected_total = (float)($kpi['gross_collected'] ?? 0);

function payments_csv_cell(mixed $value): mixed
{
    if (is_string($value) && preg_match('/^[=+\-@]/', ltrim($value))) {
        return "'" . $value;
    }

    return $value;
}

// ──────────────────────────────────────────────────────────────────────────
// CSV export (uses same filters; bypasses pagination)
// ──────────────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportSql = "
        SELECT
            p.payment_reference, p.booking_type, p.booking_id, p.booking_reference,
            p.payment_date, p.payment_amount, p.vat_rate, p.vat_amount, p.total_amount,
            p.payment_method, p.payment_type, p.payment_status, p.{$paymentTransactionColumn} AS transaction_ref,
            p.receipt_number, p.invoice_number, " . ($hasMraStatus ? "p.mra_status" : "NULL AS mra_status") . ",
            p.created_at, p.processed_by,
            CASE
                WHEN p.booking_type = 'room' THEN b.guest_name
                WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['company']}
                WHEN p.booking_type = 'restaurant' THEN COALESCE(NULLIF(so.customer_name,''), CONCAT('Order ', so.reference))
                WHEN p.booking_type = 'gym' THEN gi.name
                WHEN p.booking_type = 'event' THEN ei.name
            END AS customer
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        LEFT JOIN stock_orders so ON p.booking_type = 'restaurant' AND p.booking_id = so.id
        LEFT JOIN gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id
        LEFT JOIN event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id
        WHERE " . implode(' AND ', $where_conditions) . "
        ORDER BY p.payment_date DESC, p.created_at DESC
    ";
    // Reuse main filter params (without the pagination LIMIT/OFFSET appended later)
    $exportParams = array_slice($params, 0, count($params) - 2);
    $exportStmt = $pdo->prepare($exportSql);
    $exportStmt->execute($exportParams);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments-' . date('Y-m-d-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Reference', 'Type', 'BookingID', 'BookingRef', 'Customer', 'Date', 'Subtotal', 'VAT %', 'VAT', 'Total', 'Method', 'PaymentType', 'Status', 'ReceiptNo', 'InvoiceNo', 'MRAStatus', 'TransactionRef', 'Created', 'ProcessedBy']);
    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, array_map('payments_csv_cell', [
            $row['payment_reference'],
            $row['booking_type'],
            $row['booking_id'],
            $row['booking_reference'],
            $row['customer'],
            $row['payment_date'],
            $row['payment_amount'],
            $row['vat_rate'],
            $row['vat_amount'],
            $row['total_amount'],
            $row['payment_method'],
            $row['payment_type'],
            $row['payment_status'],
            $row['receipt_number'],
            $row['invoice_number'],
            $row['mra_status'],
            $row['transaction_ref'],
            $row['created_at'],
            $row['processed_by']
        ]));
    }
    fclose($out);
    exit;
}

// Helper: build URL preserving filters but overriding/dropping date range
function payments_url_with_dates(?string $start, ?string $end): string
{
    $params = $_GET;
    unset($params['page']);
    if ($start === null) {
        unset($params['start_date']);
    } else {
        $params['start_date'] = $start;
    }
    if ($end === null) {
        unset($params['end_date']);
    } else {
        $params['end_date'] = $end;
    }
    return 'payments.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}
$today = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-6 days'));
$monthStart = date('Y-m-01');
$quickActive = function ($s, $e) use ($startDate, $endDate) {
    return ($startDate === $s && $endDate === $e) ? ' is-active' : '';
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments | <?php echo htmlspecialchars($site_name); ?> Admin</title>

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
                <h1 class="acct-page-header__title">Payments Ledger</h1>
                <p class="acct-page-header__subtitle">
                    <?php echo number_format($total); ?> transactions match your filters · viewing
                    <?php echo $startDate || $endDate ? htmlspecialchars(($startDate ?: '—') . ' to ' . ($endDate ?: '—')) : 'all dates'; ?><?php if ($searchText !== ''): ?> · search &ldquo;<?php echo htmlspecialchars($searchText); ?>&rdquo;<?php endif; ?>
                </p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="payment-add.php" class="acct-quick-action acct-quick-action--accent">
                    <i class="fas fa-plus"></i> Record Payment
                </a>
                <a href="<?php echo htmlspecialchars('payments.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="acct-quick-action">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <a href="accounting-dashboard.php" class="acct-quick-action">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a>
                <?php if (function_exists('rh_module_key_enabled') && rh_module_key_enabled('billing')): ?>
                <a href="invoices.php" class="acct-quick-action">
                    <i class="fas fa-file-invoice-dollar"></i> Invoices
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- KPI Strip -->
        <div class="acct-kpis">
            <div class="acct-kpi acct-kpi--revenue">
                <div class="acct-kpi__label">Collected (filtered)</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . number_format($gross_collected_total, 0); ?></div>
                <div class="acct-kpi__meta">
                    <?php echo number_format((int)($kpi['txn_count'] ?? 0)); ?> txns · VAT <?php echo $currency_symbol . number_format((float)($kpi['vat_collected'] ?? 0), 0); ?>
                </div>
            </div>
            <div class="acct-kpi acct-kpi--receivables">
                <div class="acct-kpi__label">Pending / Partial</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . number_format((float)($kpi['pending_total'] ?? 0), 0); ?></div>
                <div class="acct-kpi__meta">
                    <?php echo number_format((int)($kpi['pending_count'] ?? 0)); ?> awaiting ·
                    <a href="?status=pending" class="acct-link">view all</a>
                </div>
            </div>
            <div class="acct-kpi acct-kpi--cash">
                <div class="acct-kpi__label">Today</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . number_format((float)($period['today_collected'] ?? 0), 0); ?></div>
                <div class="acct-kpi__meta">
                    <?php echo number_format((int)($period['today_count'] ?? 0)); ?> today ·
                    <?php echo $currency_symbol . number_format((float)($period['week_collected'] ?? 0), 0); ?> last 7d
                </div>
            </div>
            <div class="acct-kpi acct-kpi--vat">
                <div class="acct-kpi__label">Refunds (filtered)</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . number_format((float)($kpi['refunds_total'] ?? 0), 0); ?></div>
                <div class="acct-kpi__meta">
                    <?php echo number_format((int)($kpi['refunds_count'] ?? 0)); ?> txns · MTD <?php echo $currency_symbol . number_format((float)($period['month_collected'] ?? 0), 0); ?>
                </div>
            </div>
        </div>

        <!-- Quick date pills -->
        <div class="acct-quick-actions" style="margin: 12px 0 16px;">
            <a href="<?php echo htmlspecialchars(payments_url_with_dates(null, null)); ?>" class="acct-quick-action<?php echo (!$startDate && !$endDate) ? ' is-active' : ''; ?>">All time</a>
            <a href="<?php echo htmlspecialchars(payments_url_with_dates($today, $today)); ?>" class="acct-quick-action<?php echo $quickActive($today, $today); ?>">Today</a>
            <a href="<?php echo htmlspecialchars(payments_url_with_dates($weekAgo, $today)); ?>" class="acct-quick-action<?php echo $quickActive($weekAgo, $today); ?>">Last 7 days</a>
            <a href="<?php echo htmlspecialchars(payments_url_with_dates($monthStart, $today)); ?>" class="acct-quick-action<?php echo $quickActive($monthStart, $today); ?>">This month</a>
            <a href="<?php echo htmlspecialchars(payments_url_with_dates(date('Y-m-d', strtotime('-29 days')), $today)); ?>" class="acct-quick-action">Last 30 days</a>
            <a href="<?php echo htmlspecialchars(payments_url_with_dates(date('Y-01-01'), $today)); ?>" class="acct-quick-action">YTD</a>
        </div>

        <!-- Breakdown panels: source × method × status -->
        <div class="acct-grid acct-grid--2" style="margin-bottom: 18px;">
            <div class="acct-panel">
                <div class="acct-panel__head">
                    <h3 class="acct-panel__title">Revenue by Source</h3>
                    <span class="acct-panel__sub">Within current filters</span>
                </div>
                <div class="acct-table-wrap">
                    <table class="acct-table fit-or-card">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th class="num">Txns</th>
                                <th class="num">Collected</th>
                                <th>Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bySource)): ?>
                                <tr>
                                    <td colspan="4" class="acct-muted" style="text-align:center;">No payments in selection</td>
                                </tr>
                                <?php else: foreach ($bySource as $row):
                                    $share = $gross_collected_total > 0 ? ((float)$row['collected'] / $gross_collected_total) * 100 : 0;
                                    $label = ['room' => 'Rooms', 'conference' => 'Conferences', 'restaurant' => rh_pos_category_label(), 'gym' => 'Gym', 'event' => 'Events'][$row['booking_type']] ?? ucfirst((string)$row['booking_type']);
                                ?>
                                    <tr>
                                        <td>
                                            <a href="?booking_type=<?php echo htmlspecialchars((string)$row['booking_type']); ?>" class="acct-link">
                                                <span class="acct-pill acct-pill--<?php echo htmlspecialchars((string)$row['booking_type']); ?>"><?php echo htmlspecialchars($label); ?></span>
                                            </a>
                                        </td>
                                        <td class="num"><?php echo number_format((int)$row['txns']); ?></td>
                                        <td class="num"><strong><?php echo $currency_symbol . number_format((float)$row['collected'], 0); ?></strong></td>
                                        <td>
                                            <div class="acct-bar">
                                                <div class="acct-bar__fill" style="width: <?php echo number_format($share, 1); ?>%;"></div>
                                            </div><small class="acct-muted"><?php echo number_format($share, 1); ?>%</small>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="acct-panel">
                <div class="acct-panel__head">
                    <h3 class="acct-panel__title">By Payment Method</h3>
                    <span class="acct-panel__sub">Within current filters</span>
                </div>
                <div class="acct-table-wrap">
                    <table class="acct-table fit-or-card">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th class="num">Txns</th>
                                <th class="num">Collected</th>
                                <th>Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($byMethod)): ?>
                                <tr>
                                    <td colspan="4" class="acct-muted" style="text-align:center;">—</td>
                                </tr>
                                <?php else: foreach ($byMethod as $row):
                                    $share = $gross_collected_total > 0 ? ((float)$row['collected'] / $gross_collected_total) * 100 : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <a href="?payment_method=<?php echo htmlspecialchars((string)$row['payment_method']); ?>" class="acct-link">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$row['payment_method']))); ?>
                                            </a>
                                        </td>
                                        <td class="num"><?php echo number_format((int)$row['txns']); ?></td>
                                        <td class="num"><strong><?php echo $currency_symbol . number_format((float)$row['collected'], 0); ?></strong></td>
                                        <td>
                                            <div class="acct-bar">
                                                <div class="acct-bar__fill" style="width: <?php echo number_format($share, 1); ?>%;"></div>
                                            </div><small class="acct-muted"><?php echo number_format($share, 1); ?>%</small>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Status pill bar -->
        <?php if (!empty($byStatus)): ?>
            <div class="acct-panel" style="margin-bottom: 18px;">
                <div class="acct-panel__head">
                    <h3 class="acct-panel__title">Status Breakdown</h3>
                    <span class="acct-panel__sub"><?php echo number_format(array_sum(array_column($byStatus, 'txns'))); ?> transactions</span>
                </div>
                <div style="padding: 14px 18px; display:flex; flex-wrap:wrap; gap:8px;">
                    <?php foreach ($byStatus as $s): ?>
                        <a href="?status=<?php echo htmlspecialchars((string)$s['payment_status']); ?>" class="acct-pill acct-pill--<?php echo htmlspecialchars((string)$s['payment_status']); ?>" style="text-decoration:none;">
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$s['payment_status']))); ?> · <?php echo number_format((int)$s['txns']); ?> · <?php echo $currency_symbol . number_format((float)$s['amt'], 0); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Detailed Filters -->
        <form method="GET" class="filter-section" data-live-search-form="payments">
            <div class="filter-form">
                <div class="filter-group">
                    <label><?php echo $mod_bookings ? 'Booking Type' : 'Payment Source'; ?></label>
                    <select name="booking_type">
                        <option value="">All Types</option>
                        <?php if ($mod_bookings || $bookingType === 'room'): ?><option value="room" <?php echo $bookingType === 'room' ? 'selected' : ''; ?>>Room</option><?php endif; ?>
                        <?php if ($mod_conference || $bookingType === 'conference'): ?><option value="conference" <?php echo $bookingType === 'conference' ? 'selected' : ''; ?>>Conference</option><?php endif; ?>
                        <?php if ($mod_pos || $bookingType === 'restaurant'): ?><option value="restaurant" <?php echo $bookingType === 'restaurant' ? 'selected' : ''; ?>><?php echo isRestaurantEnabled() ? 'Restaurant' : 'POS / Till'; ?></option><?php endif; ?>
                        <?php if ($mod_gym || $bookingType === 'gym'): ?><option value="gym" <?php echo $bookingType === 'gym' ? 'selected' : ''; ?>>Gym</option><?php endif; ?>
                        <?php if ($mod_events || $bookingType === 'event'): ?><option value="event" <?php echo $bookingType === 'event' ? 'selected' : ''; ?>>Event</option><?php endif; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Booking ID</label>
                    <input type="number" name="booking_id" value="<?php echo $bookingId; ?>" placeholder="Enter ID">
                </div>

                <div class="filter-group">
                    <label>Quick Search</label>
                    <input type="text" name="search_text" value="<?php echo htmlspecialchars($searchText); ?>" data-live-search-input="payments" placeholder="Ref, guest, email, phone, amount">
                </div>

                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="partial" <?php echo $status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                        <option value="refunded" <?php echo $status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Payment Method</label>
                    <select name="payment_method">
                        <option value="">All Methods</option>
                        <?php foreach ($paymentMethods as $method): ?>
                            <option value="<?php echo htmlspecialchars($method); ?>" <?php echo $paymentMethod === $method ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $method)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                </div>

                <div class="filter-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="payments.php" class="btn-reset" style="padding: 10px 20px; border-radius: var(--radius); text-decoration: none; display: inline-block;">
                        <i class="fas fa-times"></i> Reset
                    </a>
                </div>
            </div>
        </form>

        <?php if ($has_active_payment_filters): ?>
            <div style="background: #eef3ff; border: 1px solid #cfd8ff; border-radius: 10px; padding: 12px 14px; margin: 0 0 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px; color: #1f2d6b;">
                    <i class="fas fa-filter"></i>
                    <strong>Search filtered results</strong>
                </div>
                <div style="color: #2f3a63; font-size: 13px;">
                    Showing <?php echo number_format($total); ?> payment result<?php echo (int)$total === 1 ? '' : 's'; ?><?php if ($searchText !== ''): ?> for &ldquo;<?php echo htmlspecialchars($searchText); ?>&rdquo;<?php endif; ?>.
                </div>
            </div>
        <?php endif; ?>

        <?php
        // Preset-scope notice + escape hatch (only when scoping actually hides rows)
        $scopeQs = $_GET;
        unset($scopeQs['scope'], $scopeQs['page']);
        if ($scopeActive && $hiddenScopedCount > 0): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-filter" style="margin-right:6px;"></i>Showing records for your active modules only (<?php echo number_format($hiddenScopedCount); ?> older record<?php echo $hiddenScopedCount === 1 ? '' : 's'; ?> from disabled modules hidden).</span>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($scopeQs, ['scope' => 'all']))); ?>" style="color:#8B7355; font-weight:600; text-decoration:none;">Show all history &rarr;</a>
            </div>
        <?php elseif ($scopeAll && $bookingType === ''): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-clock-rotate-left" style="margin-right:6px;"></i>Showing full payment history, including records from disabled modules.</span>
                <a href="?<?php echo htmlspecialchars(http_build_query($scopeQs)); ?>" style="color:#8B7355; font-weight:600; text-decoration:none;">Show relevant only &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- Outstanding balances toggle -->
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin:0 0 14px;">
            <?php if ($showOutstanding): ?>
                <a href="payments.php" class="btn-reset" style="padding:9px 16px; border-radius:var(--radius); text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                    <i class="fas fa-arrow-left"></i> Back to payment ledger
                </a>
                <span class="acct-pill acct-pill--pending" style="align-self:center;">
                    <i class="fas fa-hand-holding-dollar"></i> <?php echo count($outstandingRows); ?> account<?php echo count($outstandingRows) === 1 ? '' : 's'; ?> owing · <?php echo $currency_symbol . number_format($outstandingTotal, 0); ?>
                </span>
            <?php else: ?>
                <a href="payments.php?balance=outstanding" class="btn-filter" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                    <i class="fas fa-hand-holding-dollar"></i> View Outstanding Balances
                </a>
            <?php endif; ?>
        </div>

        <?php if ($showOutstanding): ?>
        <!-- Outstanding Balances Table -->
        <div class="table-container">
            <table class="table fit-or-card">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Client</th>
                        <th>Type</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Outstanding</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($outstandingRows)): ?>
                        <?php
                        $srcMeta = [
                            'room'       => ['label' => 'Room',       'badge' => 'room',       'link' => 'booking-details.php?id='],
                            'conference' => ['label' => 'Conference', 'badge' => 'conference', 'link' => 'conference-management.php?id='],
                            'gym'        => ['label' => 'Gym',        'badge' => 'gym',        'link' => 'gym-inquiries.php?id='],
                            'event'      => ['label' => 'Event',      'badge' => 'event',      'link' => 'events-inquiries.php?id='],
                        ];
                        foreach ($outstandingRows as $oRow):
                            $src = (string)($oRow['src'] ?? '');
                            $meta = $srcMeta[$src] ?? ['label' => ucfirst($src), 'badge' => $src, 'link' => '#'];
                            $oid = (int)($oRow['id'] ?? 0);
                            $viewUrl = $meta['link'] . $oid;
                        ?>
                            <tr>
                                <td data-label="Reference"><strong><?php echo htmlspecialchars((string)($oRow['ref'] ?? '—')); ?></strong></td>
                                <td data-label="Client"><?php echo htmlspecialchars((string)($oRow['client'] ?? '—')); ?></td>
                                <td data-label="Type"><span class="badge badge-<?php echo htmlspecialchars($meta['badge']); ?>"><?php echo htmlspecialchars($meta['label']); ?></span></td>
                                <?php
                                // Grand total (gross, incl. VAT + any room folio extras) is
                                // paid + due — the authoritative invoiced total, so Outstanding
                                // can never exceed Total. total_amount alone is the NET base.
                                $oGrand = (float)($oRow['amount_paid'] ?? 0) + (float)($oRow['amount_due'] ?? 0);
                                ?>
                                <td data-label="Total"><?php echo $currency_symbol . number_format($oGrand, 0); ?></td>
                                <td data-label="Paid"><?php echo $currency_symbol . number_format((float)($oRow['amount_paid'] ?? 0), 0); ?></td>
                                <td data-label="Outstanding"><strong style="color:#c0392b;"><?php echo $currency_symbol . number_format((float)($oRow['amount_due'] ?? 0), 0); ?></strong></td>
                                <td data-label="Status"><span class="badge badge-<?php echo htmlspecialchars((string)($oRow['status'] ?? '')); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($oRow['status'] ?? '')))); ?></span></td>
                                <td data-label="Actions">
                                    <div class="quick-actions">
                                        <a href="<?php echo htmlspecialchars($viewUrl); ?>" class="btn btn-primary btn-sm" title="View account"><i class="fas fa-eye"></i></a>
                                        <a href="payment-add.php?booking_type=<?php echo htmlspecialchars($src); ?>&booking_id=<?php echo $oid; ?>" class="btn btn-success btn-sm" title="Record a payment against this account"><i class="fas fa-plus"></i> Collect</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                <i class="fas fa-circle-check"></i>
                                <p>No outstanding balances — every account is fully paid.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>

        <!-- Payments Table -->
        <div class="table-container">
            <table class="table fit-or-card">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Booking</th>
                        <th>Type</th>
                        <th>Payment Date</th>
                        <th>Amount</th>
                        <th>Outstanding</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Receipt / Txn</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($payments)): ?>
                        <?php foreach ($payments as $payment): ?>
                            <tr id="payment-<?php echo (int)$payment['id']; ?>" data-focus="payment-<?php echo (int)$payment['id']; ?>">
                                <td><strong><?php echo htmlspecialchars($payment['payment_reference']); ?></strong></td>
                                <td>
                                    <div><?php echo htmlspecialchars($payment['booking_description']); ?></div>
                                    <?php if ($payment['contact_email']): ?>
                                        <small style="color: #666;"><?php echo htmlspecialchars($payment['contact_email']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['contact_phone'])): ?>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars($payment['contact_phone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $payment['booking_type']; ?>">
                                        <?php echo ucfirst($payment['booking_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                    <br><small style="color: #666; font-size: 11px;">
                                        <i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($payment['created_at'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <strong><?php echo $currency_symbol; ?><?php echo number_format($payment['total_amount'], 0); ?></strong>
                                    <?php if ($payment['vat_amount'] > 0): ?>
                                        <br><small style="color: #666;">(incl. <?php echo $currency_symbol; ?><?php echo number_format($payment['vat_amount'], 0); ?> VAT)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    // Outstanding balance on the underlying account. NULL means the
                                    // source type carries no receivable (e.g. restaurant/POS).
                                    $acctDue = $payment['account_amount_due'];
                                    if ($acctDue === null) {
                                        echo '<span style="color:#999;">—</span>';
                                    } elseif ((float)$acctDue > 0.001) {
                                        echo '<strong style="color:#c0392b;">' . $currency_symbol . number_format((float)$acctDue, 0) . '</strong>';
                                    } else {
                                        echo '<span style="color:#1f7a45;"><i class="fas fa-check-circle"></i> Settled</span>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $payment['payment_status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $payment['payment_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <small style="color: #666; font-size: 11px;">
                                        <i class="fas fa-clock"></i> <?php echo date('M j, H:i', strtotime($payment['created_at'])); ?>
                                    </small>
                                    <?php if ($payment['updated_at'] && $payment['updated_at'] != $payment['created_at']): ?>
                                        <br><small style="color: #999; font-size: 10px;">
                                            <i class="fas fa-edit"></i> <?php echo date('M j, H:i', strtotime($payment['updated_at'])); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($payment['receipt_number'])): ?>
                                        <span style="color: #28a745;"><i class="fas fa-receipt"></i> <?php echo htmlspecialchars($payment['receipt_number']); ?></span>
                                    <?php elseif (in_array($payment['payment_status'], ['completed', 'paid'], true) && $payment['payment_type'] !== 'refund'): ?>
                                        <span style="color: #dc3545;"><i class="fas fa-triangle-exclamation"></i> Missing</span>
                                    <?php else: ?>
                                        <span style="color: #999;">N/A</span>
                                    <?php endif; ?>
                                    <?php if (!empty($payment[$paymentTransactionColumn])): ?>
                                        <br><small style="color: #666;">Txn: <?php echo htmlspecialchars($payment[$paymentTransactionColumn]); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="quick-actions">
                                        <a href="payment-details.php?id=<?php echo $payment['id']; ?>" class="btn btn-primary btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (function_exists('rh_module_key_enabled') && rh_module_key_enabled('billing')): ?>
                                        <a href="invoices.php?search=<?php echo urlencode($payment['payment_reference']); ?>" class="btn btn-secondary btn-sm" title="Invoice">
                                            <i class="fas fa-file-invoice"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if (in_array($payment['payment_status'], ['completed', 'paid'], true) && $payment['payment_type'] != 'refund'): ?>
                                            <a href="payment-refund.php?id=<?php echo $payment['id']; ?>" class="btn btn-warning btn-sm" title="Process Refund"
                                                data-admin-confirm="Open the refund form for this payment?"
                                                data-admin-confirm-title="Confirm refund action"
                                                data-admin-confirm-details="Payment: <?php echo htmlspecialchars($payment['payment_reference'], ENT_QUOTES); ?>|Amount: <?php echo htmlspecialchars($currency_symbol . number_format($payment['total_amount'], 0), ENT_QUOTES); ?>"
                                                data-admin-confirm-ok="Open Refund"
                                                data-admin-confirm-icon="fa-rotate-left">
                                                <i class="fas fa-undo"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!in_array($payment['payment_status'], ['completed', 'paid'], true)): ?>
                                            <a href="payment-add.php?edit=<?php echo $payment['id']; ?>" class="btn btn-info btn-sm" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($payment['contact_phone'])): ?>
                                            <?php $waPhone = preg_replace('/[^0-9]/', '', (string)$payment['contact_phone']); ?>
                                            <?php if ($waPhone !== ''): ?>
                                                <a href="https://wa.me/<?php echo htmlspecialchars($waPhone); ?>?text=<?php echo urlencode('Hello, this is ' . $site_name . ' accounts. Payment reference: ' . $payment['payment_reference']); ?>" target="_blank" rel="noopener" class="btn btn-success btn-sm" title="WhatsApp">
                                                    <i class="fab fa-whatsapp"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p><?php echo $has_active_payment_filters ? 'No payments match your current search filters.' : 'No payments found'; ?></p>
                                <?php if ($has_active_payment_filters): ?>
                                    <a href="payments.php" style="display: inline-block; margin-top: 8px; padding: 8px 14px; border: 1px solid #ddd; border-radius: 8px; color: #444; text-decoration: none; font-size: 13px;">Clear filters</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-left"></i> Previous</span>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($total > 0): ?>
            <p style="text-align: center; color: #666; margin-top: 16px;">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total); ?> of <?php echo $total; ?> payments
            </p>
        <?php endif; ?>
        <?php endif; // end !$showOutstanding payments-table branch ?>
    </div>

    <script>
        (function initPaymentsLiveSearch() {
            const searchForm = document.querySelector('[data-live-search-form="payments"]');
            const searchInput = searchForm ? searchForm.querySelector('[data-live-search-input="payments"]') : null;
            if (!searchForm || !searchInput) {
                return;
            }

            // Instant, client-side filter of the rows currently on the page — the
            // table narrows as you type with no reload, so it feels immediate.
            // A debounced server submit still runs behind it so matches on OTHER
            // pages (the list is paginated) are pulled in too.
            const table = document.querySelector('.table-container table');
            const tbody = table ? table.querySelector('tbody') : null;

            function instantFilter(query) {
                if (!tbody) return;
                const q = query.trim().toLowerCase();
                const rows = tbody.querySelectorAll('tr');
                let shown = 0;
                rows.forEach(function(row) {
                    // Skip the "no results" placeholder row.
                    if (row.querySelector('.empty-state')) return;
                    const match = q === '' || row.textContent.toLowerCase().indexOf(q) !== -1;
                    row.style.display = match ? '' : 'none';
                    if (match) shown++;
                });

                // Live "no local matches" hint row so an empty filtered view isn't
                // just a blank table while the server search catches up.
                let hint = tbody.querySelector('.js-payments-filter-empty');
                if (q !== '' && shown === 0) {
                    if (!hint) {
                        hint = document.createElement('tr');
                        hint.className = 'js-payments-filter-empty';
                        const colCount = table.querySelectorAll('thead th').length || 11;
                        hint.innerHTML = '<td colspan="' + colCount + '" style="text-align:center; color:#888; padding:22px;">'
                            + '<i class="fas fa-magnifying-glass"></i> No matches on this page — searching all records…</td>';
                        tbody.appendChild(hint);
                    }
                    hint.style.display = '';
                } else if (hint) {
                    hint.style.display = 'none';
                }
            }

            let searchDebounceTimer = null;
            searchInput.addEventListener('input', function() {
                // 1) Immediate visual filter of the loaded rows.
                instantFilter(searchInput.value);

                // 2) Debounced authoritative server search across all pages.
                window.clearTimeout(searchDebounceTimer);
                searchDebounceTimer = window.setTimeout(function() {
                    const query = searchInput.value.trim();
                    if (query.length === 0 || query.length >= 2) {
                        searchForm.requestSubmit();
                    }
                }, 450);
            });

            // Apply once on load so a value restored from the URL filters instantly.
            if (searchInput.value.trim() !== '') {
                instantFilter(searchInput.value);
            }
        })();
    </script>

    <script src="js/admin-components.js"></script>

    <?php require_once 'includes/admin-footer.php'; ?>

