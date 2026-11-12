<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

require_once '../includes/modal.php';
require_once '../includes/alert.php';
require_once 'includes/finance-schema.php';
require_once 'includes/finance-filters.php';
require_once 'includes/finance-refunds.php'; // the one refund rule + row wording (pure)
require_once 'includes/receivables.php';     // the one "still owed" rule (Dashboard, Invoices, Reports, End of Day)
require_once 'includes/log-pager.php';      // rh_lp_page_size / rh_lp_size_picker: rows per page (?size=)

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

// Get filter parameters
// Text-only reads: an array such as ?status[]=x is ignored (admin/includes/finance-filters.php).
$bookingType = rh_fin_get_str($_GET, 'booking_type');
$bookingId = rh_fin_get_int($_GET, 'booking_id');
$status = rh_fin_get_str($_GET, 'status');
$paymentMethod = rh_fin_get_str($_GET, 'payment_method');
// Refund workflow filter (whitelisted): ?refund_status=pending, or a set such as pending,processing (the
// Dashboard "Refunds to approve" queue). Unknown statuses are dropped; the kept set is rebuilt in a fixed order.
$refundStatusList = rh_fin_refund_statuses(rh_fin_get_str($_GET, 'refund_status'));
$refundStatus = implode(',', $refundStatusList);
$refundStatusLabels = ['pending' => 'pending', 'processing' => 'processing', 'completed' => 'completed', 'failed' => 'failed', 'rejected' => 'rejected'];
// Real Y-m-d dates only (anything else is ignored, so the boxes show what is actually applied).
$startDate = rh_fin_valid_date($_GET['start_date'] ?? '');
$endDate = rh_fin_valid_date($_GET['end_date'] ?? '');
$searchText = rh_fin_search_term($_GET); // ?search_text=, or ?q= (older Dashboard links) when that is empty
// "Outstanding balances" view: shows accounts (bookings/conference/gym/event)
// that still owe money — i.e. amount_due > 0 — NOT payment-ledger rows. This is
// what the dashboard "Outstanding Balances" card counts; those accounts often
// have zero payment rows yet, so they can never appear in the payments list.
// ?balance=unconfirmed: the same account list, for deposits taken on accounts not confirmed yet (never in Still owed).
$showUnconfirmed = (rh_fin_get_str($_GET, 'balance') === 'unconfirmed');
$showOutstanding = (rh_fin_get_str($_GET, 'balance') === 'outstanding') || $showUnconfirmed;
$has_active_payment_filters = $bookingType !== '' || $bookingId > 0 || $status !== '' || $refundStatus !== '' || $paymentMethod !== '' || $startDate !== '' || $endDate !== '' || $searchText !== '';

// Preset scoping: by default the list shows only rows whose module is enabled
// (a gym sees gym/event/till payments, not room-booking history). Explicit
// ?booking_type= deep links and ?scope=all bypass it; nothing is ever deleted.
$scopeAll            = (rh_fin_get_str($_GET, 'scope') === 'all');
$allBookingTypes     = ['room', 'conference', 'restaurant', 'gym', 'event'];
$enabledBookingTypes = function_exists('rh_enabled_booking_types') ? rh_enabled_booking_types() : [];
// A refund-status queue is never module-scoped: it lists every refund in that state, as the Dashboard counts them.
$scopeActive         = $bookingType === '' && !$scopeAll && $refundStatus === ''
    && !empty($enabledBookingTypes)
    && count($enabledBookingTypes) < count($allBookingTypes);
$hiddenBookingTypes  = $scopeActive ? array_values(array_diff($allBookingTypes, $enabledBookingTypes)) : [];

$page = max(1, rh_fin_get_int($_GET, 'page', 1));
$limit = rh_lp_page_size($_GET['size'] ?? null); // 10 / 25 / 50 / 100 per page, default 10 (log-pager.php)
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
        END as account_amount_due,
        -- The account's own status, and whether the shared receivables rule (admin/includes/receivables.php) counts
        -- it as owed, so a row's still-owes note and the Still owed card use one rule (rh_pay_row_state).
        CASE
            WHEN p.booking_type = 'room' THEN CASE WHEN b.deleted_at IS NOT NULL THEN 'deleted' ELSE b.status END
            WHEN p.booking_type = 'conference' THEN ci.status
            WHEN p.booking_type = 'gym' THEN gi.status
            WHEN p.booking_type = 'event' THEN ei.status
            ELSE NULL
        END as account_status,
        CASE
            WHEN p.booking_type = 'room' AND b.id IS NOT NULL AND (" . rh_receivable_status_sql('room', 'b') . ") THEN 1
            WHEN p.booking_type = 'conference' AND ci.id IS NOT NULL AND (" . rh_receivable_status_sql('conference', 'ci') . ") THEN 1
            WHEN p.booking_type = 'gym' AND gi.id IS NOT NULL AND (" . rh_receivable_status_sql('gym', 'gi') . ") THEN 1
            WHEN p.booking_type = 'event' AND ei.id IS NOT NULL AND (" . rh_receivable_status_sql('event', 'ei') . ") THEN 1
            ELSE 0
        END as account_in_rule,
        CASE
            WHEN p.booking_type = 'room' AND b.id IS NOT NULL AND (" . rh_unconfirmed_status_sql('room', 'b') . ") THEN 1
            WHEN p.booking_type = 'conference' AND ci.id IS NOT NULL AND (" . rh_unconfirmed_status_sql('conference', 'ci') . ") THEN 1
            WHEN p.booking_type = 'gym' AND gi.id IS NOT NULL AND (" . rh_unconfirmed_status_sql('gym', 'gi') . ") THEN 1
            WHEN p.booking_type = 'event' AND ei.id IS NOT NULL AND (" . rh_unconfirmed_status_sql('event', 'ei') . ") THEN 1
            ELSE 0
        END as account_unconfirmed,
        -- Room bookings: stored figures for the booked rate x nights line (includes/booked-rate.php).
        b.total_with_vat AS bk_total_with_vat, b.total_amount AS bk_total_amount, b.vat_amount AS bk_vat_amount,
        b.number_of_nights AS bk_nights, b.package_total AS bk_package_total, b.child_supplement_total AS bk_child
" . rh_fin_payments_from();

// ONE filter set for the cards, the list, the row count and the CSV export (admin/includes/finance-filters.php),
// so a figure on a card is always the sum of the rows the list shows for the same filters.
$payFilter = rh_fin_payments_where([
    'booking_type'   => $bookingType,
    'scope_types'    => $scopeActive ? $enabledBookingTypes : [],
    'booking_id'     => $bookingId,
    'search'         => $searchText,
    'status'         => $status,
    'refund_status'  => $refundStatus,
    'payment_method' => $paymentMethod,
    'start_date'     => (string)$startDate,
    'end_date'       => (string)$endDate,
], $paymentTransactionColumn, $conferenceFields);
$payFromSql = rh_fin_payments_from();
$payWhereSql = $payFilter['sql'];
$payFilterParams = $payFilter['params'];

$sql .= ' ' . $payWhereSql;

// Get total count
$countSql = "SELECT COUNT(*) as total " . $payFromSql . ' ' . $payWhereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($payFilterParams);
$total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Add ordering and pagination
// Header sort (whole list, fixed key -> ORDER BY map; never request text).
require_once __DIR__ . '/includes/server-sort.php';
[$payOrderBy, $paySortKey, $paySortDir] = rh_server_sort_resolve([
    'reference'   => 'p.payment_reference',
    'booking'     => 'booking_description',
    'type'        => 'p.booking_type',
    'date'        => 'p.payment_date',
    'amount'      => 'p.total_amount',
    'outstanding' => 'account_amount_due',
    'method'      => 'p.payment_method',
    'status'      => 'p.payment_status',
    'created'     => 'p.created_at',
], 'p.payment_date DESC, p.created_at DESC', 'p.id DESC');
$sql .= " ORDER BY " . $payOrderBy;
$sql .= " LIMIT ? OFFSET ?";

$stmt = $pdo->prepare($sql);
// The shared filter values, then LIMIT / OFFSET bound as integers.
$payListArgs = array_values($payFilterParams);
foreach ($payListArgs as $i => $v) {
    $stmt->bindValue($i + 1, $v);
}
$stmt->bindValue(count($payListArgs) + 1, (int)$limit, PDO::PARAM_INT);
$stmt->bindValue(count($payListArgs) + 2, (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Room payments: "K356,000 × 3" = the nightly price the booking was MADE at x nights (owner 2026-11-09).
require_once __DIR__ . '/../includes/booked-rate.php';
$payCur = trim((string)getSetting('currency_symbol', 'MWK'));
foreach ($payments as &$payRow) {
    $payRow['booked_rate_compact'] = '';
    if ((string)$payRow['booking_type'] === 'room' && $payRow['bk_nights'] !== null) {
        $payBr = rh_booked_rate_line([
            'total_with_vat' => $payRow['bk_total_with_vat'], 'total_amount' => $payRow['bk_total_amount'],
            'vat_amount' => $payRow['bk_vat_amount'], 'number_of_nights' => $payRow['bk_nights'],
            'package_total' => $payRow['bk_package_total'], 'child_supplement_total' => $payRow['bk_child'],
        ], null, ['currency' => $payCur]);
        $payRow['booked_rate_compact'] = $payBr['ok'] ? $payBr['compact'] : '';
    }
}
unset($payRow);

// ── Still owed (accounts still owing) ──────────────────────────────────────
// The one shared receivables rule (admin/includes/receivables.php), the same rows the Dashboard, Invoices
// "Outstanding" card, Reports aging and End of Day add up, so the Still owed card and this list always total to
// those figures. Read once: the card always shows it, ?balance=outstanding lists it.
// ?booking_type= narrows it to one source (the Invoices "outstanding by source" links), card and list alike.
$outstandingRows = [];
$outstandingTotal = 0.0;
$outstandingFailed = [];
foreach (rh_receivable_rows($pdo, rh_receivable_mods(), $outstandingFailed) as $rcvRow) {
    if ($bookingType !== '' && $rcvRow['type'] !== $bookingType) {
        continue;
    }
    $outstandingRows[] = [
        'src' => $rcvRow['type'], 'id' => $rcvRow['id'], 'ref' => $rcvRow['reference'], 'client' => $rcvRow['client'],
        'status' => $rcvRow['status'], 'amount_paid' => $rcvRow['amount_paid'], 'amount_due' => $rcvRow['amount_due'],
    ];
    $outstandingTotal += (float)$rcvRow['amount_due'];
}
$outstandingTotal = round($outstandingTotal, 2);
if ($showOutstanding) {
    usort($outstandingRows, static fn($a, $b) => (float)$b['amount_due'] <=> (float)$a['amount_due']);
}

// ── Deposits taken, not yet confirmed (owner 2026-11-12: "some hotels can take part payments just to confirm") ──
// Accounts outside the owed rule and not closed that already paid something (rh_unconfirmed_deposit_rows). Shown as
// their own line under Still owed and never added to it, so the shared figure (Dashboard, Reports, End of Day) does
// not move. Amount = what they still have to pay. Same booking_type narrowing as Still owed.
$depositRows = [];
$depositTotal = 0.0;
$depositFailed = [];
foreach (rh_unconfirmed_deposit_rows($pdo, rh_receivable_mods(), $depositFailed) as $dRow) {
    if ($bookingType !== '' && $dRow['type'] !== $bookingType) {
        continue;
    }
    $depositRows[] = [
        'src' => $dRow['type'], 'id' => $dRow['id'], 'ref' => $dRow['reference'], 'client' => $dRow['client'],
        'status' => $dRow['status'], 'amount_paid' => $dRow['amount_paid'], 'amount_due' => $dRow['amount_due'],
    ];
    $depositTotal += (float)$dRow['amount_due'];
}
$depositTotal = round($depositTotal, 2);
usort($depositRows, static fn($a, $b) => (float)$b['amount_due'] <=> (float)$a['amount_due']);

// The account list the balance view shows: Still owed, or (?balance=unconfirmed) the deposits not yet confirmed.
$viewRows   = $showUnconfirmed ? $depositRows : $outstandingRows;
$viewTotal  = $showUnconfirmed ? $depositTotal : $outstandingTotal;
$viewFailed = $showUnconfirmed ? $depositFailed : $outstandingFailed;
$viewBalanceKey = $showUnconfirmed ? 'unconfirmed' : 'outstanding';

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
// The cards read exactly the payment rows the list shows: same FROM, same WHERE, same params as the list
// and the count (rh_fin_payments_where). The card SUM/COUNT expressions below run over those rows only.
$analyticsWhereSql = "WHERE id IN (SELECT p.id " . $payFromSql . ' ' . $payWhereSql . ")";
$analyticsParams = $payFilterParams;

$kpiStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS txn_count,
        COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS gross_collected,
        COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN vat_amount   ELSE 0 END), 0) AS vat_collected,
        COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS pending_total,
        COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') AND COALESCE(payment_type, '') <> 'refund' THEN 1 ELSE 0 END), 0) AS pending_count,
        " . rh_refund_settled_sum_sql() . " AS refunds_total,
        " . rh_refund_settled_count_sql() . " AS refunds_count,
        " . rh_refund_pending_sum_sql() . " AS refunds_pending_total,
        " . rh_refund_pending_count_sql() . " AS refunds_pending_count,
        " . rh_refund_to_credit_sum_sql() . " AS refunds_to_credit_total
    FROM payments
    $analyticsWhereSql
");
$kpiStmt->execute($analyticsParams);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Today / This month (always — independent of filters, useful sidebar info)
$periodStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN DATE(payment_date) = CURDATE() AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS today_collected,
        COALESCE(SUM(CASE WHEN DATE(payment_date) = CURDATE() AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN 1 ELSE 0 END), 0) AS today_count,
        COALESCE(SUM(CASE WHEN YEAR(payment_date)=YEAR(CURDATE()) AND MONTH(payment_date)=MONTH(CURDATE()) AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS month_collected,
        COALESCE(SUM(CASE WHEN payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS week_collected
    FROM payments
    WHERE deleted_at IS NULL
");
$periodStmt->execute();
$period = $periodStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Breakdown by booking_type (filter-aware)
$bySourceStmt = $pdo->prepare("
    SELECT booking_type,
           COUNT(*) AS txns,
           COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS collected
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
           COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS collected
    FROM payments
    $analyticsWhereSql
    GROUP BY payment_method
    ORDER BY collected DESC
");
$byMethodStmt->execute($analyticsParams);
$byMethod = $byMethodStmt->fetchAll(PDO::FETCH_ASSOC);

// Status mix for the visual pill bar
$byStatusStmt = $pdo->prepare("
    SELECT payment_status, COUNT(*) AS txns, COALESCE(SUM(CASE WHEN COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END),0) AS amt
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
            p.receipt_number, p.invoice_number,
            p.created_at, p.processed_by,
            CASE
                WHEN p.booking_type = 'room' THEN b.guest_name
                WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['company']}
                WHEN p.booking_type = 'restaurant' THEN COALESCE(NULLIF(so.customer_name,''), CONCAT('Order ', so.reference))
                WHEN p.booking_type = 'gym' THEN gi.name
                WHEN p.booking_type = 'event' THEN ei.name
            END AS customer
        " . $payFromSql . ' ' . $payWhereSql . "
        ORDER BY p.payment_date DESC, p.created_at DESC
    ";
    // Same filters as the list and the cards (no pagination)
    $exportParams = $payFilterParams;
    $exportStmt = $pdo->prepare($exportSql);
    $exportStmt->execute($exportParams);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments-' . date('Y-m-d-His') . '.csv"');
    $out = fopen('php://output', 'w');
    require_once __DIR__ . '/includes/finance-account-sync.php';
    // Room rows hold net + tourism levy in payment_amount; the levy is outside the VAT base, so it gets its
    // own column and VAT % is VAT / net without levy (Net + Tourism levy + VAT = Total).
    fputcsv($out, ['Reference', 'Type', 'BookingID', 'BookingRef', 'Customer', 'Date', 'Net (excl. VAT and levy)', 'Tourism levy', 'VAT %', 'VAT', 'Total', 'Method', 'PaymentType', 'Status', 'ReceiptNo', 'InvoiceNo', 'TransactionRef', 'Created', 'ProcessedBy']);
    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        $csvVat = rh_payment_vat_lines($pdo, $row, true);
        fputcsv($out, array_map('payments_csv_cell', [
            $row['payment_reference'],
            $row['booking_type'],
            $row['booking_id'],
            $row['booking_reference'],
            $row['customer'],
            $row['payment_date'],
            number_format((float)$csvVat['net'], 2, '.', ''),
            number_format((float)$csvVat['levy'], 2, '.', ''),
            number_format((float)$csvVat['rate'], 2, '.', ''),
            $row['vat_amount'],
            $row['total_amount'],
            $row['payment_method'],
            $row['payment_type'],
            $row['payment_status'],
            $row['receipt_number'],
            $row['invoice_number'],
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
        <div class="ck-kpis ck-kpis--4">
            <?php /* Plain scope words for the filtered cards: the dates the list is showing. */
            $payScopeWords = ($startDate || $endDate) ? (($startDate ? date('j M Y', strtotime($startDate)) : 'the start') . ' to ' . ($endDate ? date('j M Y', strtotime($endDate)) : 'today')) : 'all dates (no date filter)'; ?>
            <div class="ck-kpi ck-kpi--good">
                <span class="ck-kpi__label">Collected (filtered)</span>
                <span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span><?php echo number_format($gross_collected_total, 0); ?></span>
                <span class="ck-kpi__sub"><?php echo number_format((int)($kpi['txn_count'] ?? 0)); ?> txns · VAT <?php echo $currency_symbol . number_format((float)($kpi['vat_collected'] ?? 0), 0); ?></span>
                <span class="ck-kpi__sub" style="margin-top:0">Money received (VAT included) for <?php echo htmlspecialchars($payScopeWords); ?>, before refunds are taken off</span>
            </div>
            <?php /* Still owed: what accounts still have to pay (rh_receivable_rows above), NOT payment rows marked pending.
                     A part-paid booking's payments are recorded as received ('completed'), so only its balance shows the debt. */ ?>
            <div class="ck-kpi ck-kpi--warn">
                <span class="ck-kpi__label">Still owed</span>
                <span class="ck-kpi__value ck-kpi__value--money"><?php if (!empty($outstandingFailed)): ?>Unavailable<?php else: ?><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span><?php echo number_format($outstandingTotal, 0); ?><?php endif; ?></span>
                <span class="ck-kpi__sub"><?php echo number_format(count($outstandingRows)); ?> account<?php echo count($outstandingRows) === 1 ? '' : 's'; ?> owing<?php echo $bookingType !== '' ? ' (' . htmlspecialchars(rh_receivable_type_label($bookingType)) . ' only)' : ''; ?> ·
                    <a href="<?php echo htmlspecialchars('payments.php?' . http_build_query(array_filter(['balance' => 'outstanding', 'booking_type' => $bookingType], static fn($v) => $v !== ''))); ?>" class="acct-link">see who owes</a>
                </span>
                <span class="ck-kpi__sub" style="margin-top:0">Unpaid balances on bookings, conferences, events and open tabs, after every payment received (part-payments included). Same figure as the Dashboard and Reports; not affected by the date, status, method or search filters.<?php if (!empty($outstandingFailed)): ?> Some accounts could not be read (System Logs), so no total is shown.<?php endif; ?></span>
                <?php if (!empty($depositRows) || !empty($depositFailed)): ?>
                <?php /* Deposits on accounts not confirmed yet: their own line, never added to Still owed (rh_unconfirmed_deposit_rows). */ ?>
                <span class="ck-kpi__sub" style="margin-top:0" title="Accounts that paid a deposit but are not confirmed yet (an unconfirmed enquiry, a tentative hold). The amount is what they still have to pay. It joins Still owed once the account is confirmed.">Not yet confirmed, deposit paid · still to pay: <?php echo !empty($depositFailed) ? 'unavailable' : htmlspecialchars($currency_symbol) . ' ' . number_format($depositTotal, 0); ?> (<?php echo number_format(count($depositRows)); ?> account<?php echo count($depositRows) === 1 ? '' : 's'; ?>) ·
                    <a href="<?php echo htmlspecialchars('payments.php?' . http_build_query(array_filter(['balance' => 'unconfirmed', 'booking_type' => $bookingType], static fn($v) => $v !== ''))); ?>" class="acct-link">see them</a>
                </span>
                <span class="ck-kpi__sub" style="margin-top:0">Still to pay on accounts that paid a deposit but are not confirmed yet; not in Still owed until confirmed.</span>
                <?php endif; ?>
                <?php if ((int)($kpi['pending_count'] ?? 0) > 0): ?>
                <span class="ck-kpi__sub" style="margin-top:0"><?php echo number_format((int)($kpi['pending_count'] ?? 0)); ?> payment record<?php echo (int)($kpi['pending_count'] ?? 0) === 1 ? '' : 's'; ?> still marked pending (not received) ·
                    <?php /* Same filters plus "pending or partial" (refunds excluded): opens exactly those payment records. */ ?>
                    <a href="<?php echo htmlspecialchars('payments.php?' . http_build_query(array_merge($_GET, ['status' => in_array($status, ['pending', 'partial'], true) ? $status : 'pending_partial', 'page' => 1, 'balance' => null, 'export' => null]))); ?>" class="acct-link">view these</a>
                </span>
                <?php endif; ?>
            </div>
            <div class="ck-kpi">
                <span class="ck-kpi__label">Today</span>
                <span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span><?php echo number_format((float)($period['today_collected'] ?? 0), 0); ?></span>
                <span class="ck-kpi__sub">                    <?php echo number_format((int)($period['today_count'] ?? 0)); ?> today ·
                    <?php echo $currency_symbol . number_format((float)($period['week_collected'] ?? 0), 0); ?> last 7d ·
                    <?php echo $currency_symbol . number_format((float)($period['month_collected'] ?? 0), 0); ?> this month
                </span>
                <span class="ck-kpi__sub" style="margin-top:0">All payments, not affected by filters</span>
            </div>
            <div class="ck-kpi ck-kpi--info">
                <span class="ck-kpi__label">Refunds (filtered)</span>
                <span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span><?php echo number_format((float)($kpi['refunds_total'] ?? 0), 0); ?></span>
                <span class="ck-kpi__sub"><?php echo number_format((int)($kpi['refunds_count'] ?? 0)); ?> refund<?php echo (int)($kpi['refunds_count'] ?? 0) === 1 ? '' : 's'; ?> paid out or being paid<?php if ((int)($kpi['refunds_pending_count'] ?? 0) > 0): ?> ·
                    <a href="<?php echo htmlspecialchars('payments.php?' . http_build_query(array_merge($_GET, ['refund_status' => 'pending', 'page' => 1, 'balance' => null, 'export' => null]))); ?>" class="acct-link"><?php echo htmlspecialchars($currency_symbol) . number_format((float)($kpi['refunds_pending_total'] ?? 0), 0); ?> awaiting approval</a><?php endif; ?></span>
                <?php $payRefundSplit = rh_refund_split_text((float)($kpi['refunds_total'] ?? 0), (float)($kpi['refunds_to_credit_total'] ?? 0), (string)$currency_symbol); ?>
                <?php if ($payRefundSplit !== ''): ?><span class="ck-kpi__sub" style="margin-top:0"><?php echo htmlspecialchars($payRefundSplit); ?></span><?php endif; ?>
                <span class="ck-kpi__sub" style="margin-top:0">Refunds for <?php echo htmlspecialchars($payScopeWords); ?> (VAT included): money paid back plus overpayments or store credit moved to a credit note. Awaiting approval is not in the total. Accounting and Reports use the same rule for their own dates.</span>
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

        <?php /* Source / method / status splits repeat the Accounting page, so they sit folded
                 above the ledger; the filters and the ledger itself come first. */ ?>
        <details class="acct-breakdown-details">
            <summary class="acct-vat-details__summary"><i class="fas fa-chart-pie"></i> Breakdown by source, method and status</summary>
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
                    <span class="acct-panel__sub"><?php echo number_format(array_sum(array_column($byStatus, 'txns'))); ?> transactions (amounts exclude refund rows). The status of each payment record: "Received" means the money came in, not that the bill is settled.</span>
                </div>
                <div style="padding: 14px 18px; display:flex; flex-wrap:wrap; gap:8px;">
                    <?php foreach ($byStatus as $s): ?>
                        <a href="?status=<?php echo htmlspecialchars((string)$s['payment_status']); ?>" class="acct-pill acct-pill--<?php echo htmlspecialchars((string)$s['payment_status']); ?>" style="text-decoration:none;">
                            <?php $sPs = (string)$s['payment_status']; echo htmlspecialchars(in_array($sPs, ['completed', 'paid'], true) ? 'Received (' . $sPs . ')' : ucfirst(str_replace('_', ' ', $sPs))); ?> · <?php echo number_format((int)$s['txns']); ?> · <?php echo $currency_symbol . number_format((float)$s['amt'], 0); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        </details>

        <!-- Detailed Filters -->
        <form method="GET" class="filter-section" data-live-search-form="payments">
            <?php echo rh_server_sort_hidden($paySortKey, $paySortDir); ?>
            <?php if ($limit !== 10): // keep the rows-per-page choice when the filters change ?><input type="hidden" name="size" value="<?php echo (int)$limit; ?>"><?php endif; ?>
            <?php if ($refundStatus !== ''): // keep the refund-status filter when the other filters change ?>
            <input type="hidden" name="refund_status" value="<?php echo htmlspecialchars($refundStatus); ?>">
            <?php endif; ?>
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
                    <input type="number" name="booking_id" value="<?php echo $bookingId ? (int)$bookingId : ''; ?>" placeholder="Enter ID">
                </div>

                <div class="filter-group">
                    <label>Quick Search</label>
                    <input type="text" name="search_text" value="<?php echo htmlspecialchars($searchText); ?>" data-live-search-input="payments" placeholder="Ref, guest, email, phone, amount">
                </div>

                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="pending_partial" <?php echo $status === 'pending_partial' ? 'selected' : ''; ?>>Pending or partial</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="partial" <?php echo $status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid (money received)</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed (money received)</option>
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

        <?php if ($refundStatus !== ''): ?>
            <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7c2d12;">
                <span><i class="fas fa-rotate-left" style="margin-right:6px;"></i>Showing refunds that are <strong><?php echo htmlspecialchars(implode(' or ', array_map(static fn($k) => $refundStatusLabels[$k], $refundStatusList))); ?></strong> only<?php echo $bookingType === '' ? ', from every source' : ''; ?>.</span>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['refund_status' => null, 'page' => null]))); ?>" style="color:#7E684B; font-weight:600; text-decoration:none;"><i class="fas fa-times"></i> Clear</a>
            </div>
        <?php endif; ?>

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
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($scopeQs, ['scope' => 'all']))); ?>" style="color:#7E684B; font-weight:600; text-decoration:none;">Show all history &rarr;</a>
            </div>
        <?php elseif ($scopeAll && $bookingType === ''): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-clock-rotate-left" style="margin-right:6px;"></i>Showing full payment history, including records from disabled modules.</span>
                <a href="?<?php echo htmlspecialchars(http_build_query($scopeQs)); ?>" style="color:#7E684B; font-weight:600; text-decoration:none;">Show relevant only &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- Outstanding balances toggle -->
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin:0 0 14px;">
            <?php if ($showOutstanding): ?>
                <a href="payments.php" class="btn-reset" style="padding:9px 16px; border-radius:var(--radius); text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                    <i class="fas fa-arrow-left"></i> Back to payment ledger
                </a>
                <span class="acct-pill acct-pill--pending" style="align-self:center;">
                    <i class="fas fa-hand-holding-dollar"></i> <?php echo count($viewRows); ?> account<?php echo count($viewRows) === 1 ? '' : 's'; ?> <?php echo $showUnconfirmed ? 'with a deposit, not yet confirmed' : 'owing'; ?> · <?php echo !empty($viewFailed) ? 'total n/a' : $currency_symbol . number_format($viewTotal, 0) . ($showUnconfirmed ? ' still to pay' : ''); ?>
                </span>
                <span class="acct-muted" style="align-self:center;">
                    <?php if ($bookingType !== ''): ?>
                        <?php echo htmlspecialchars(rh_receivable_type_label($bookingType)); ?> only · <a href="payments.php?balance=<?php echo $viewBalanceKey; ?>" class="acct-link">show all sources</a> ·
                    <?php endif; ?>
                    <?php if ($showUnconfirmed): ?>
                    Accounts that paid a deposit but are not confirmed yet. Not in Still owed (Dashboard, Invoices, Reports) until confirmed; not affected by the date, status, method or search filters. <a href="payments.php?balance=outstanding" class="acct-link">Show Still owed</a>
                    <?php else: ?>
                    Same rule as the Dashboard, Invoices and Reports owed figures; not affected by the date, status, method or search filters.
                    <?php endif; ?>
                    <?php if (!empty($viewFailed)): ?> Some accounts could not be read (System Logs), so no total is shown.<?php endif; ?>
                </span>
            <?php else: ?>
                <a href="payments.php?balance=outstanding" class="btn-filter" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                    <i class="fas fa-hand-holding-dollar"></i> View Outstanding Balances
                </a>
            <?php endif; ?>
        </div>

        <?php if ($showOutstanding): ?>
        <!-- Outstanding Balances Table -->
        <div class="table-container" data-rh-results>
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
                    <?php if (!empty($viewRows)): ?>
                        <?php
                        $srcMeta = [
                            'room'       => ['label' => 'Room',       'badge' => 'room',       'link' => 'booking-details.php?id='],
                            'conference' => ['label' => 'Conference', 'badge' => 'conference', 'link' => 'conference-management.php?id='],
                            'gym'        => ['label' => 'Gym',        'badge' => 'gym',        'link' => 'gym-inquiries.php?id='],
                            'event'      => ['label' => 'Event',      'badge' => 'event',      'link' => 'events-inquiries.php?id='],
                            // Open tabs have no account page: their pending payment rows in this ledger.
                            'restaurant' => ['label' => 'Open tab',   'badge' => 'restaurant', 'link' => 'payments.php?booking_type=restaurant&booking_id='],
                        ];
                        foreach ($viewRows as $oRow):
                            $src = (string)($oRow['src'] ?? '');
                            $oid = (int)($oRow['id'] ?? 0);
                            // Other sources (no account page): their own type name, a neutral badge, and their own
                            // pending rows in this ledger by type + id (rh_fin_outstanding_src_meta).
                            $meta = rh_fin_outstanding_src_meta($src, $srcMeta);
                            // No link when the row cannot be pinned to one account (no id, or no type for the ledger
                            // filter): a link would open other rows, so the reference stays plain text.
                            $viewUrl = ($oid > 0 && $src !== '') ? $meta['link'] . $oid : '';
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
                                        <?php if ($viewUrl !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($viewUrl); ?>" class="btn btn-primary btn-sm" title="View account"><i class="fas fa-eye"></i></a>
                                        <?php endif; ?>
                                        <?php if (in_array($src, ['room', 'conference', 'gym', 'event'], true)): ?>
                                        <a href="payment-add.php?booking_type=<?php echo htmlspecialchars($src); ?>&booking_id=<?php echo $oid; ?>" class="btn btn-success btn-sm" title="Record a payment against this account"><i class="fas fa-plus"></i> Collect</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                <i class="fas fa-circle-check"></i>
                                <p><?php echo $showUnconfirmed ? "No deposits waiting: no unconfirmed account has paid part of its bill." : "No outstanding balances — every account is fully paid."; ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>

        <!-- Payments Table -->
        <?php /* Server-paged (?page= + ?size=), so the shared client pager stays off this table. */ ?>
        <div class="table-container" data-disable-auto-pagination>
            <table class="table fit-or-card rh-phone-actions rh-fold-actions" data-server-sort="1">
                <thead>
                    <tr>
                        <?php rh_server_sort_th('Reference', 'reference', $paySortKey, $paySortDir); ?>
                        <?php rh_server_sort_th('Booking', 'booking', $paySortKey, $paySortDir, 'col-optional'); ?>
                        <?php rh_server_sort_th('Type', 'type', $paySortKey, $paySortDir, 'col-optional-lg'); ?>
                        <?php rh_server_sort_th('Payment Date', 'date', $paySortKey, $paySortDir, 'col-optional-md'); ?>
                        <?php rh_server_sort_th('<span title="The money this payment record moved, VAT included. Refunds show with a minus: money paid back.">Amount</span>', 'amount', $paySortKey, $paySortDir); ?>
                        <?php rh_server_sort_th('<span title="What the booking or account behind this payment still has to pay today (its full bill less every payment received). A dash: till orders, settled at the till.">Outstanding</span>', 'outstanding', $paySortKey, $paySortDir, 'col-optional'); ?>
                        <?php rh_server_sort_th('Method', 'method', $paySortKey, $paySortDir, 'col-optional-lg'); ?>
                        <?php rh_server_sort_th('<span title="Whether this payment&#39;s money came in (Received) or a refund&#39;s step. The line under it says whether the booking&#39;s bill is settled or still owes.">Status</span>', 'status', $paySortKey, $paySortDir); ?>
                        <?php rh_server_sort_th('Created', 'created', $paySortKey, $paySortDir, 'col-optional-lg'); ?>
                        <th class="col-optional-lg">Receipt / Txn</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($payments)): ?>
                        <?php foreach ($payments as $payment): ?>
                            <?php // Row wording from the columns already joined above (no query per row): admin/includes/finance-refunds.php
                            $payState = rh_pay_row_state($payment, (float)BALANCE_TOLERANCE, (string)$currency_symbol);
                            $payNoteColor = ['owe' => '#c0392b', 'ok' => '#1f7a45', 'warn' => '#8a5a00', 'muted' => '#666'][$payState['tone']] ?? '#666'; ?>
                            <tr id="payment-<?php echo (int)$payment['id']; ?>" data-focus="payment-<?php echo (int)$payment['id']; ?>">
                                <td><strong><?php echo htmlspecialchars($payment['payment_reference']); ?></strong>
                                    <span class="rh-phone-sub rh-phone-sub--dark"><?php echo htmlspecialchars($payment['booking_description'] . ($payment['booked_rate_compact'] !== '' ? ' · booked at ' . $payment['booked_rate_compact'] : '')); ?></span>
                                    <span class="rh-phone-sub rh-phone-sub--md"><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></span></td>
                                <td class="col-optional">
                                    <div><?php echo htmlspecialchars($payment['booking_description']); ?></div>
                                    <?php if ($payment['booked_rate_compact'] !== ''): ?>
                                        <small style="color: #666;" title="Booked at: the nightly price this booking was made at (VAT and levy included) × nights">Booked at <?php echo htmlspecialchars($payment['booked_rate_compact']); ?></small><br>
                                    <?php endif; ?>
                                    <?php if ($payment['contact_email']): ?>
                                        <small style="color: #666;"><?php echo htmlspecialchars($payment['contact_email']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['contact_phone'])): ?>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars($payment['contact_phone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="col-optional-lg">
                                    <span class="badge badge-<?php echo htmlspecialchars((string)$payment['booking_type']); ?>">
                                        <?php echo htmlspecialchars(ucfirst((string)$payment['booking_type'])); ?>
                                    </span>
                                </td>
                                <td class="col-optional-md">
                                    <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                    <br><small style="color: #666; font-size: 11px;">
                                        <i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($payment['created_at'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <strong><?php echo $payState['amount_sign'] === '-' ? '&minus;' : ''; ?><?php echo $currency_symbol; ?><?php echo number_format($payment['total_amount'], 0); ?></strong>
                                    <?php if ($payment['vat_amount'] > 0): ?>
                                        <span class="rh-hide-md"><br><small style="color: #666;">(incl. <?php echo $currency_symbol; ?><?php echo number_format($payment['vat_amount'], 0); ?> VAT)</small></span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-optional">
                                    <?php
                                    // Outstanding balance on the underlying account. NULL means the
                                    // source type carries no receivable (e.g. restaurant/POS).
                                    $acctDue = $payment['account_amount_due'];
                                    if ($acctDue === null) {
                                        echo '<span style="color:#999;">—</span>';
                                    } elseif ((float)$acctDue > BALANCE_TOLERANCE) {
                                        echo '<strong style="color:#c0392b;">' . $currency_symbol . number_format((float)$acctDue, 0) . '</strong>';
                                    } else {
                                        echo '<span style="color:#1f7a45;"><i class="fas fa-check-circle"></i> Bill settled</span>';
                                    }
                                    ?>
                                </td>
                                <td class="col-optional-lg"><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                <td>
                                    <?php /* "Received" = this payment's money came in; the bill's own position is the note under it. */ ?>
                                    <span class="badge badge-<?php echo htmlspecialchars($payState['badge']); ?>">
                                        <?php echo htmlspecialchars($payState['label']); ?>
                                    </span>
                                    <?php if ($payState['note'] !== ''): ?>
                                        <br><small style="color: <?php echo $payNoteColor; ?>; font-size: 11px;"><?php echo htmlspecialchars($payState['note']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="col-optional-lg">
                                    <small style="color: #666; font-size: 11px;">
                                        <i class="fas fa-clock"></i> <?php echo date('M j, H:i', strtotime($payment['created_at'])); ?>
                                    </small>
                                    <?php if ($payment['updated_at'] && $payment['updated_at'] != $payment['created_at']): ?>
                                        <br><small style="color: #999; font-size: 10px;">
                                            <i class="fas fa-edit"></i> <?php echo date('M j, H:i', strtotime($payment['updated_at'])); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td class="col-optional-lg">
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
                                    <?php
                                        $payWaPhone = !empty($payment['contact_phone']) ? preg_replace('/[^0-9]/', '', (string)$payment['contact_phone']) : '';
                                        $payCanRefund = in_array($payment['payment_status'], ['completed', 'paid'], true) && $payment['payment_type'] != 'refund';
                                        $payCanEdit = !in_array($payment['payment_status'], ['completed', 'paid'], true);
                                        $payHasMore = $payCanRefund || $payCanEdit || $payWaPhone !== '';
                                    ?>
                                    <?php /* View, History + Invoice stay on the row; refund, edit and WhatsApp sit in the shared More menu. */ ?>
                                    <div class="quick-actions actions-row">
                                        <a href="payment-details.php?id=<?php echo $payment['id']; ?>" class="btn btn-primary btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button type="button" class="btn btn-secondary btn-sm" data-rh-history="payment:<?php echo (int)$payment['id']; ?>" title="History: every step for this payment" aria-label="History of payment <?php echo htmlspecialchars((string)$payment['payment_reference'], ENT_QUOTES); ?>">
                                            <i class="fas fa-clock-rotate-left"></i>
                                        </button>
                                        <?php if (function_exists('rh_module_key_enabled') && rh_module_key_enabled('billing')): ?>
                                        <a href="invoices.php?search=<?php echo urlencode($payment['payment_reference']); ?>" class="btn btn-secondary btn-sm" title="Invoice">
                                            <i class="fas fa-file-invoice"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($payHasMore): ?>
                                        <div class="actions-more">
                                            <button type="button" class="btn btn-secondary btn-sm actions-more-toggle" title="More actions" aria-label="More actions" aria-haspopup="true" onclick="togglePaymentActionsMore(this, event)">
                                                <i class="fas fa-ellipsis-vertical"></i>
                                            </button>
                                            <div class="actions-more-menu">
                                                <?php if ($payCanEdit): ?>
                                                    <a href="payment-add.php?edit=<?php echo $payment['id']; ?>"><i class="fas fa-edit"></i> Edit payment</a>
                                                <?php endif; ?>
                                                <?php if ($payWaPhone !== ''): ?>
                                                    <a href="https://wa.me/<?php echo htmlspecialchars($payWaPhone); ?>?text=<?php echo urlencode('Hello, this is ' . $site_name . ' accounts. Payment reference: ' . $payment['payment_reference']); ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> WhatsApp guest</a>
                                                <?php endif; ?>
                                                <?php if ($payCanRefund): ?>
                                                    <hr class="menu-divider">
                                                    <a href="payment-refund.php?id=<?php echo $payment['id']; ?>" class="text-danger"
                                                        data-admin-confirm="Open the refund form for this payment?"
                                                        data-admin-confirm-title="Confirm refund action"
                                                        data-admin-confirm-details="Payment: <?php echo htmlspecialchars($payment['payment_reference'], ENT_QUOTES); ?>|Amount: <?php echo htmlspecialchars($currency_symbol . number_format($payment['total_amount'], 0), ENT_QUOTES); ?>"
                                                        data-admin-confirm-ok="Open Refund"
                                                        data-admin-confirm-icon="fa-rotate-left">
                                                        <i class="fas fa-undo"></i> Process refund
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
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

        <!-- Pagination: shown whenever there are more than 10 payments, so the rows-per-page choice is always there -->
        <?php if ($totalPages > 1 || $total > 10): ?>
            <div class="pagination">
                <?php if ($totalPages > 1): ?>
                <?php if ($page > 1): ?>
                    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-left"></i> Previous</span>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>
                <?php endif; ?>
                <?php /* Rows per page: keeps every filter, the sort and the balance view; starts again at page 1. */ echo rh_lp_size_picker('payments.php', $_GET, (int)$limit); ?>
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
        // More-actions menu (shared placement from admin-components.js)
        function closePaymentMenus() {
            document.querySelectorAll('.table-container .actions-more.open').forEach(function (el) {
                el.classList.remove('open');
                var m = el.querySelector('.actions-more-menu');
                if (m) m.removeAttribute('style');
            });
        }

        function togglePaymentActionsMore(btn, e) {
            if (e) e.stopPropagation();
            var wrap = btn.closest('.actions-more');
            var menu = wrap ? wrap.querySelector('.actions-more-menu') : null;
            if (!menu) return;
            var wasOpen = wrap.classList.contains('open');
            closePaymentMenus();
            if (wasOpen) return;
            wrap.classList.add('open');
            if (window.rhPlaceActionMenu) window.rhPlaceActionMenu(btn, menu);
        }
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.actions-more')) closePaymentMenus();
        });
        document.addEventListener('scroll', function (e) {
            if (!(e.target && e.target.closest && e.target.closest('.actions-more-menu'))) closePaymentMenus();
        }, true);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closePaymentMenus();
        });

    </script>
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

    <?php require_once __DIR__ . '/includes/history-panel.php'; ?>
    <?php require_once 'includes/admin-footer.php'; ?>

