<?php
require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */
/** @var PDO $pdo */

require_once '../config/receipts.php';
require_once 'includes/finance-schema.php';
require_once 'includes/finance-filters.php';
require_once 'includes/log-pager.php'; // rh_lp_page_size / rh_lp_size_picker: rows per page (?size=)

if (!hasPermission((int)($user['id'] ?? 0), 'receipts')) {
    rhDenyAndRedirectHome((int)$_SESSION['admin_user_id'], (string)($_SESSION['admin_role'] ?? ''), basename($_SERVER['PHP_SELF']));
    exit;
}

receipt_ensure_schema($pdo);
$currency_symbol = getSetting('currency_symbol', 'MWK');
$message = '';
$error = '';

function receipts_money(float $amount, string $symbol): string
{
    return '<span class="finance-money"><span class="finance-money__currency">'
        . htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8')
        . '</span><span class="finance-money__amount">'
        . htmlspecialchars(number_format($amount, 2), ENT_QUOTES, 'UTF-8')
        . '</span></span>';
}

function receipts_csv_cell(mixed $value): string
{
    $value = str_replace(["\r", "\n"], ' ', (string)$value);
    return '"' . str_replace('"', '""', $value) . '"';
}

/**
 * Where each receipt's guest name lives, per payments.booking_type (the same source receipt_hydrate_context() uses,
 * config/receipts.php). Only tables and columns that exist on this install are returned (conference name columns
 * differ per install; a hotel without gym/events may not have those tables), so the SQL built from it never fails.
 * @return array<string, array{0:string,1:string}> booking_type => [table, column]
 */
function receipts_guest_sources(PDO $pdo): array
{
    static $sources = null;
    if ($sources !== null) {
        return $sources;
    }
    $candidates = [
        'room'       => ['bookings', ['guest_name']],
        'conference' => ['conference_inquiries', ['contact_person', 'contact_name', 'company_name', 'organization_name']],
        'gym'        => ['gym_inquiries', ['name']],
        'event'      => ['event_inquiries', ['name']],
        'restaurant' => ['stock_orders', ['customer_name']],
    ];
    $sources = [];
    foreach ($candidates as $type => [$table, $names]) {
        try {
            $cols = receipt_table_columns($pdo, $table);
        } catch (Throwable $e) {
            continue; // table not on this install
        }
        foreach ($names as $n) {
            if (isset($cols[$n])) {
                $sources[$type] = [$table, $n]; // first existing column, as the hydrator picks it
                break;
            }
        }
    }
    return $sources;
}

/** "(p.booking_type = 'room' AND EXISTS (... LIKE ?) OR ...)": guest-name search, one LIKE placeholder per source. */
function receipts_guest_search_sql(PDO $pdo): string
{
    $parts = [];
    foreach (receipts_guest_sources($pdo) as $type => [$table, $col]) {
        // Type, table and column are fixed names from receipts_guest_sources(), never user text.
        $parts[] = "(p.booking_type = '{$type}' AND EXISTS (SELECT 1 FROM `{$table}` gs WHERE gs.id = p.booking_id AND gs.`{$col}` LIKE ? ESCAPE '!'))";
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '';
}

/** Export columns: one name lookup per source, each only on its own booking_type (separate columns, no collation mix). */
function receipts_guest_select_sql(PDO $pdo): string
{
    $parts = [];
    foreach (receipts_guest_sources($pdo) as $type => [$table, $col]) {
        $parts[] = "CASE WHEN p.booking_type = '{$type}' THEN (SELECT gs.`{$col}` FROM `{$table}` gs WHERE gs.id = p.booking_id LIMIT 1) END AS guest_{$type}";
    }
    return $parts ? ', ' . implode(', ', $parts) : '';
}

/** The export's Guest cell, with the list's fallback: unknown is "Walk-in" for a till sale, blank otherwise. */
function receipts_guest_export_name(array $row): string
{
    $type = (string)($row['booking_type'] ?? '');
    $name = trim((string)($row['guest_' . $type] ?? ''));
    if ($name === '' || $name === 'Guest') {
        return $type === 'restaurant' ? 'Walk-in' : '';
    }
    // Names are typed by guests: a leading = + - @ or tab would run as a spreadsheet formula (same guard as rh_sa_csv_cell).
    if (strpos("=+-@\t", $name[0]) !== false) {
        $name = "'" . $name;
    }
    return $name;
}

function receipts_build_where(PDO $pdo, array $input, array &$params): string
{
    $where = ["p.deleted_at IS NULL"];
    $where[] = "p.payment_status IN ('completed','paid','refunded','partially_refunded')";
    if (($input['type'] ?? 'all') !== 'all') {
        $where[] = 'p.booking_type = ?';
        $params[] = $input['type'];
    } elseif (!empty($input['_scope_types']) && is_array($input['_scope_types'])) {
        // Preset scoping: default view shows only enabled modules' receipts.
        $where[] = 'p.booking_type IN (' . implode(',', array_fill(0, count($input['_scope_types']), '?')) . ')';
        foreach ($input['_scope_types'] as $t) { $params[] = $t; }
    }
    if (($input['status'] ?? 'all') === 'missing') {
        // "Missing" = a collected payment with no receipt number yet, or no PDF on file: the same rows the list marks "Missing" / "Needs PDF".
        // Refund rows are not receipts, so they are never "missing".
        $where[] = "(COALESCE(p.payment_type, '') <> 'refund' AND ((p.receipt_number IS NULL OR p.receipt_number = '') OR COALESCE(p.receipt_generated, 0) <> 1))";
    } elseif (($input['status'] ?? 'all') === 'generated') {
        $where[] = 'p.receipt_generated = 1';
    } elseif (($input['status'] ?? 'all') === 'emailed') {
        $where[] = 'p.receipt_emailed_at IS NOT NULL';
    }
    // Real Y-m-d dates only; "To" covers the whole day (admin/includes/finance-filters.php).
    $where = array_merge($where, rh_fin_date_conditions('p.payment_date', $input['date_from'] ?? '', $input['date_to'] ?? '', $params));
    $search = rh_fin_get_str($input, 'search');
    if ($search !== '') {
        // % and _ typed by staff match literally (ESCAPE '!'): admin/includes/finance-filters.php.
        $searchSql = rh_fin_like_any(['p.receipt_number', 'p.payment_reference', 'p.booking_reference', 'p.notes']);
        // Guest name too, from the booking/account/order the receipt belongs to. Every placeholder in this block
        // takes the same $like value, so the count below keeps the order right.
        $guestSql = receipts_guest_search_sql($pdo);
        if ($guestSql !== '') {
            $searchSql = '(' . $searchSql . ' OR ' . $guestSql . ')';
        }
        $where[] = $searchSql;
        $like = rh_fin_like_param($search);
        foreach (array_fill(0, substr_count($searchSql, '?'), $like) as $v) {
            $params[] = $v;
        }
    }
    return 'WHERE ' . implode(' AND ', $where);
}

$filters = [
    // Text-only reads: an array such as ?search[]=x is ignored (admin/includes/finance-filters.php).
    'type' => rh_fin_get_str($_GET, 'type', 'all'),
    'status' => rh_fin_get_str($_GET, 'status', 'all'),
    'date_from' => rh_fin_valid_date($_GET['date_from'] ?? ''),
    'date_to' => rh_fin_valid_date($_GET['date_to'] ?? ''),
    'search' => rh_fin_get_str($_GET, 'search'),
];
if (!in_array($filters['type'], ['all', 'room', 'conference', 'restaurant', 'gym', 'event'], true)) {
    $filters['type'] = 'all';
}
if (!in_array($filters['status'], ['all', 'missing', 'generated', 'emailed'], true)) {
    $filters['status'] = 'all';
}

// Preset scoping: default list shows receipts for enabled modules only.
// ?type= deep links and ?scope=all bypass; history is never deleted.
$scopeAll            = (rh_fin_get_str($_GET, 'scope') === 'all');
$allBookingTypes     = ['room', 'conference', 'restaurant', 'gym', 'event'];
$enabledBookingTypes = function_exists('rh_enabled_booking_types') ? rh_enabled_booking_types() : [];
$scopeActive         = $filters['type'] === 'all' && !$scopeAll
    && !empty($enabledBookingTypes)
    && count($enabledBookingTypes) < count($allBookingTypes);
$hiddenBookingTypes  = $scopeActive ? array_values(array_diff($allBookingTypes, $enabledBookingTypes)) : [];
if ($scopeActive) {
    $filters['_scope_types'] = $enabledBookingTypes;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Refresh and try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            $paymentId = (int)($_POST['payment_id'] ?? 0);

            if ($action === 'generate_receipt') {
                receipt_generate_pdf($pdo, $paymentId, $user);
                $message = 'Receipt generated.';
            } elseif ($action === 'email_receipt') {
                $recipient = trim((string)($_POST['recipient'] ?? ''));
                $result = receipt_send_email($pdo, $paymentId, $recipient !== '' ? $recipient : null, $user);
                $message = $result['message'];
            } elseif ($action === 'backfill_receipts') {
                // Every collected payment without a receipt number or without its PDF (refund rows are not receipts: skipped).
                $stmt = $pdo->query("SELECT id FROM payments WHERE deleted_at IS NULL AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' AND ((receipt_number IS NULL OR receipt_number = '') OR COALESCE(receipt_generated, 0) <> 1) ORDER BY payment_date ASC, id ASC LIMIT 500");
                $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
                $count = 0;
                $failed = 0;
                foreach ($ids as $id) {
                    try {
                        receipt_generate_pdf($pdo, (int)$id, $user);
                        $count++;
                    } catch (Throwable $genEx) {
                        $failed++;
                        error_log('receipts backfill payment ' . (int)$id . ': ' . $genEx->getMessage());
                    }
                }
                $message = 'Generated receipts for ' . $count . ' payment(s).' . ($failed > 0 ? ' ' . $failed . ' could not be generated - see System Logs.' : '');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$params = [];
$whereSql = receipts_build_where($pdo, $filters, $params);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $guestSelect = receipts_guest_select_sql($pdo);
    $stmt = $pdo->prepare("SELECT p.id, p.receipt_number, p.payment_reference, p.booking_type, p.booking_reference, p.payment_date, p.payment_method, p.payment_type, p.payment_status, p.total_amount, p.receipt_generated, p.receipt_emailed_at, COALESCE(au.full_name, au.username, p.processed_by) AS recorded_by_name{$guestSelect}
        FROM payments p
        LEFT JOIN admin_users au ON au.id = p.recorded_by
        $whereSql
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 5000");
    $stmt->execute($params);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="receipts-' . date('Ymd-His') . '.csv"');
    echo "Receipt Number,Payment Reference,Guest,Type,Booking Reference,Payment Date,Method,Payment Type,Status,Amount,Generated,Emailed At,Recorded By\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo implode(',', [
            receipts_csv_cell($row['receipt_number'] ?? ''),
            receipts_csv_cell($row['payment_reference'] ?? ''),
            receipts_csv_cell(receipts_guest_export_name($row)),
            receipts_csv_cell($row['booking_type'] ?? ''),
            receipts_csv_cell($row['booking_reference'] ?? ''),
            receipts_csv_cell($row['payment_date'] ?? ''),
            receipts_csv_cell($row['payment_method'] ?? ''),
            receipts_csv_cell($row['payment_type'] ?? ''),
            receipts_csv_cell($row['payment_status'] ?? ''),
            receipts_csv_cell(number_format((float)($row['total_amount'] ?? 0), 2, '.', '')),
            receipts_csv_cell(!empty($row['receipt_generated']) ? 'yes' : 'no'),
            receipts_csv_cell($row['receipt_emailed_at'] ?? ''),
            receipts_csv_cell($row['recorded_by_name'] ?? ''),
        ]) . "\n";
    }
    exit;
}

$summaryStmt = $pdo->prepare("SELECT COUNT(*) AS total_receipts,
        COALESCE(SUM(CASE WHEN (p.receipt_number IS NULL OR p.receipt_number = '') AND COALESCE(p.payment_type, '') <> 'refund' THEN 1 ELSE 0 END), 0) AS missing_numbers,
        COALESCE(SUM(CASE WHEN COALESCE(p.receipt_generated, 0) <> 1 AND COALESCE(p.payment_type, '') <> 'refund' THEN 1 ELSE 0 END), 0) AS missing_receipts,
        COALESCE(SUM(CASE WHEN p.receipt_generated = 1 THEN 1 ELSE 0 END), 0) AS generated_receipts,
        COALESCE(SUM(CASE WHEN p.receipt_emailed_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS emailed_receipts,
        COALESCE(SUM(CASE WHEN COALESCE(p.payment_type,'') = 'refund' THEN -p.total_amount ELSE p.total_amount END), 0) AS receipt_value
    FROM payments p $whereSql");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Rows hidden by preset scoping (for the notice above the table)
$hiddenScopedCount = 0;
if ($scopeActive && $hiddenBookingTypes !== []) {
    $hPh = implode(',', array_fill(0, count($hiddenBookingTypes), '?'));
    $hStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p WHERE p.deleted_at IS NULL AND p.payment_status IN ('completed','paid','refunded','partially_refunded') AND p.booking_type IN ($hPh)");
    $hStmt->execute($hiddenBookingTypes);
    $hiddenScopedCount = (int)$hStmt->fetchColumn();
}

$page = max(1, rh_fin_get_int($_GET, 'page', 1));
$limit = rh_lp_page_size($_GET['size'] ?? null); // 10 / 25 / 50 / 100, default 10
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
$page = min($page, $totalPages);
$windowStart = max(1, $page - 2);
$windowEnd = min($totalPages, $windowStart + 4);
if (($windowEnd - $windowStart) < 4) {
    $windowStart = max(1, $windowEnd - 4);
}

$listStmt = $pdo->prepare("SELECT p.*, COALESCE(au.full_name, au.username, p.processed_by) AS recorded_by_name,
        (SELECT COUNT(*) FROM receipt_events re WHERE re.payment_id = p.id) AS event_count
    FROM payments p
    LEFT JOIN admin_users au ON au.id = p.recorded_by
    $whereSql
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT ? OFFSET ?");
// This page's rows only (it fetched every receipt before): the filter values, then LIMIT / OFFSET bound as integers.
$listArgs = array_values($params);
foreach ($listArgs as $i => $v) {
    $listStmt->bindValue($i + 1, $v);
}
$listStmt->bindValue(count($listArgs) + 1, (int)$limit, PDO::PARAM_INT);
$listStmt->bindValue(count($listArgs) + 2, (int)(($page - 1) * $limit), PDO::PARAM_INT);
$listStmt->execute();
$payments = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$eventsStmt = $pdo->query("SELECT re.*, p.payment_reference FROM receipt_events re LEFT JOIN payments p ON p.id = re.payment_id ORDER BY re.created_at DESC, re.id DESC LIMIT 20");
$recentEvents = $eventsStmt ? $eventsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// The receipt wording (email, PDF, WhatsApp) is edited on the Email Templates page; only the WhatsApp text is read here, for the share links.
$templateWhatsapp = getSetting('receipt_whatsapp_template', '');
$site_name = getSetting('site_name', 'Hotel');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipts | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
    <link rel="stylesheet" href="css/receipts.css?v=<?php echo @filemtime(__DIR__ . '/css/receipts.css'); ?>">
    <script src="js/receipts.js" defer></script>
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="admin-container finance-page receipts-page">

        <!-- Page header -->
        <header class="rh-page-head">
            <div class="rh-page-head__main">
                <div class="rh-page-head__title">
                    <h1>Receipts</h1>
                </div>
                <p class="rh-page-head__meta">Track every receipt from room, conference, restaurant and POS payments.</p>
            </div>
            <div class="rh-page-head__actions">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="backfill_receipts">
                    <button class="btn btn--primary" type="submit"><i class="fas fa-file-circle-plus"></i> Generate Missing</button>
                </form>
                <a class="btn btn--ghost" href="receipts.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert--danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- KPI Strip -->
        <div class="rh-strip" role="group" aria-label="Receipt summary">
            <div class="rh-strip__cell">
                <span class="rh-strip__label">Total receipts</span>
                <strong class="rh-strip__value"><?php echo number_format((int)($summary['total_receipts'] ?? 0)); ?></strong>
                <span class="rh-strip__sub">payment records</span>
            </div>
            <div class="rh-strip__cell">
                <span class="rh-strip__label">Missing / Needs PDF</span>
                <strong class="rh-strip__value <?php echo (int)($summary['missing_receipts'] ?? 0) > 0 ? 'rh-strip__value--alert' : ''; ?>"><?php echo number_format((int)($summary['missing_receipts'] ?? 0)); ?></strong>
                <span class="rh-strip__sub"><?php echo number_format((int)($summary['missing_numbers'] ?? 0)); ?> without a number</span>
            </div>
            <div class="rh-strip__cell">
                <span class="rh-strip__label">Generated PDFs</span>
                <strong class="rh-strip__value"><?php echo number_format((int)($summary['generated_receipts'] ?? 0)); ?></strong>
                <span class="rh-strip__sub"><?php echo number_format((int)($summary['emailed_receipts'] ?? 0)); ?> emailed</span>
            </div>
            <div class="rh-strip__cell">
                <span class="rh-strip__label">Receipt value</span>
                <strong class="rh-strip__value"><?php echo htmlspecialchars($currency_symbol); ?> <?php echo number_format((float)($summary['receipt_value'] ?? 0), 2); ?></strong>
                <span class="rh-strip__sub">all matched payments</span>
            </div>
        </div>

        <!-- Filter bar -->
        <section class="rh-panel">
            <div class="rh-panel__body">
            <form method="get" action="receipts.php" class="filter-bar">
                <?php if ($limit !== 10): ?><input type="hidden" name="size" value="<?php echo (int)$limit; ?>"><?php endif; ?>
                <input type="text" name="search" class="filter-input" placeholder="Guest name, receipt #, payment ref, booking ref..." value="<?php echo htmlspecialchars($filters['search']); ?>">
                <select name="type" class="filter-select">
                    <option value="all">All Types</option>
                    <?php $rc_mod_bookings = function_exists('moduleEnabled') && moduleEnabled('bookings');
                          $rc_mod_conf     = function_exists('moduleEnabled') && moduleEnabled('conference');
                          $rc_mod_pos      = function_exists('moduleEnabled') && moduleEnabled('pos');
                          $rc_mod_gym      = function_exists('moduleEnabled') && moduleEnabled('gym');
                          $rc_mod_events   = function_exists('isEventsEnabled') && isEventsEnabled(); ?>
                    <?php if ($rc_mod_bookings || $filters['type'] === 'room'): ?><option value="room" <?php echo $filters['type'] === 'room' ? 'selected' : ''; ?>>Rooms</option><?php endif; ?>
                    <?php if ($rc_mod_conf || $filters['type'] === 'conference'): ?><option value="conference" <?php echo $filters['type'] === 'conference' ? 'selected' : ''; ?>>Conference</option><?php endif; ?>
                    <?php if ($rc_mod_pos || $filters['type'] === 'restaurant'): ?><option value="restaurant" <?php echo $filters['type'] === 'restaurant' ? 'selected' : ''; ?>><?php echo (function_exists('isRestaurantEnabled') && isRestaurantEnabled()) ? 'Restaurant/POS' : 'POS / Till'; ?></option><?php endif; ?>
                    <?php if ($rc_mod_gym || $filters['type'] === 'gym'): ?><option value="gym" <?php echo $filters['type'] === 'gym' ? 'selected' : ''; ?>>Gym</option><?php endif; ?>
                    <?php if ($rc_mod_events || $filters['type'] === 'event'): ?><option value="event" <?php echo $filters['type'] === 'event' ? 'selected' : ''; ?>>Event</option><?php endif; ?>
                </select>
                <select name="status" class="filter-select">
                    <option value="all">All Statuses</option>
                    <option value="missing" <?php echo $filters['status'] === 'missing' ? 'selected' : ''; ?>>Missing No. / PDF</option>
                    <option value="generated" <?php echo $filters['status'] === 'generated' ? 'selected' : ''; ?>>Generated</option>
                    <option value="emailed" <?php echo $filters['status'] === 'emailed' ? 'selected' : ''; ?>>Emailed</option>
                </select>
                <label class="rh-inline-field"><span class="rh-inline-field__text">From</span><input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($filters['date_from']); ?>" title="First payment date to show (that day included)"></label>
                <label class="rh-inline-field"><span class="rh-inline-field__text">To</span><input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($filters['date_to']); ?>" title="Last payment date to show (that day included)"></label>
                <button type="submit" class="btn btn--primary btn--sm"><i class="fas fa-search"></i> Search</button>
                <?php if ($filters['type'] !== 'all' || $filters['status'] !== 'all' || $filters['search'] !== '' || $filters['date_from'] !== '' || $filters['date_to'] !== ''): ?>
                    <a href="receipts.php" class="btn btn--ghost btn--sm">Clear</a>
                <?php endif; ?>
            </form>
            </div>
        </section>

        <?php
        $scopeQs = $_GET;
        unset($scopeQs['scope'], $scopeQs['page']);
        if ($scopeActive && $hiddenScopedCount > 0): ?>
            <p class="receipts-scope-note">
                <span><i class="fas fa-filter"></i> Showing receipts for your active modules only (<?php echo number_format($hiddenScopedCount); ?> older record<?php echo $hiddenScopedCount === 1 ? '' : 's'; ?> from disabled modules hidden).</span>
                <a class="rh-mini-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($scopeQs, ['scope' => 'all']))); ?>">Show all history</a>
            </p>
        <?php elseif ($scopeAll && $filters['type'] === 'all'): ?>
            <p class="receipts-scope-note">
                <span><i class="fas fa-clock-rotate-left"></i> Showing full receipt history, including records from disabled modules.</span>
                <a class="rh-mini-link" href="?<?php echo htmlspecialchars(http_build_query($scopeQs)); ?>">Show relevant only</a>
            </p>
        <?php endif; ?>

        <!-- Receipts Table -->
        <section class="rh-panel rh-panel--flush receipts-list-panel">
        <div class="rh-panel__head">
            <h2 class="rh-panel__title">Receipts</h2>
            <span class="rh-page-head__meta"><?php echo number_format($totalRows); ?> match<?php echo $totalRows === 1 ? '' : 'es'; ?></span>
        </div>
        <div class="rh-panel__body">
        <div class="table-container" data-rh-results data-admin-pagination-scope data-receipts-pagination-scope data-disable-auto-pagination data-page-size="<?php echo (int)$limit; ?>" data-current-page="<?php echo (int)$page; ?>" data-total-pages="<?php echo (int)$totalPages; ?>">
            <?php if (!$payments): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>No receipt rows match the current filters.</p>
                </div>
            <?php else: ?>
                <table class="table receipts-table">
                    <thead>
                        <tr>
                            <th class="rc-ref">Receipt / Payment</th>
                            <th class="rc-guest">Guest</th>
                            <th class="rc-source">Source</th>
                            <th class="rc-date">Date</th>
                            <th class="rc-amount text-right">Amount</th>
                            <th class="rc-status">Status</th>
                            <th class="rc-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $index => $payment): ?>
                            <?php
                            $waContext = receipt_hydrate_context($pdo, $payment);
                            $waPlaceholders = receipt_placeholders($pdo, $payment, $waContext);
                            $waMessage = html_entity_decode(str_replace(array_keys($waPlaceholders), array_values($waPlaceholders), $templateWhatsapp), ENT_QUOTES, 'UTF-8');
                            $waPhone = preg_replace('/[^0-9]+/', '', (string)$waContext['guest_phone']);
                            $waUrl = ($waPhone !== '' ? 'https://wa.me/' . $waPhone : 'https://wa.me/') . '?text=' . rawurlencode($waMessage);
                            $guestEmail = htmlspecialchars((string)($waContext['guest_email'] ?? ''), ENT_QUOTES, 'UTF-8');
                            // Guest column: name from the linked booking/account/order; 'Guest' is the hydrator's fallback for unknown or walk-in.
                            $rcGuestName = trim((string)($waContext['guest_name'] ?? ''));
                            $rcGuestKnown = $rcGuestName !== '' && $rcGuestName !== 'Guest';
                            $rcGuestEmail = trim((string)($waContext['guest_email'] ?? ''));
                            $rcGuestPhone = trim((string)($waContext['guest_phone'] ?? ''));
                            ?>
                            <tr data-receipts-row data-page-index="<?php echo (int)floor($index / max(1, $limit)) + 1; ?>">
                                <td class="rc-ref">
                                    <strong class="tbl-ref"><?php echo htmlspecialchars((string)($payment['receipt_number'] ?: '—')); ?></strong>
                                    <?php if (empty($payment['receipt_number'])): ?>
                                        <span class="badge badge-warning" style="margin-top:3px;display:inline-block;">Missing</span>
                                    <?php endif; ?>
                                    <div class="rc-ref__payment">
                                        <small class="text-muted rc-ref__payref"><?php echo htmlspecialchars((string)$payment['payment_reference']); ?></small>
                                        <small class="text-muted rc-ref__by"><?php echo htmlspecialchars((string)($payment['recorded_by_name'] ?? 'System')); ?></small>
                                    </div>
                                </td>
                                <td class="rc-guest">
                                    <?php if ($rcGuestKnown): ?>
                                        <span class="rc-guest__name"><?php echo htmlspecialchars($rcGuestName, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php else: ?>
                                        <span class="rc-guest__name text-muted"><?php echo (string)($payment['booking_type'] ?? '') === 'restaurant' ? 'Walk-in' : '—'; ?></span>
                                    <?php endif; ?>
                                    <?php if ($rcGuestEmail !== ''): ?>
                                        <small class="text-muted rc-guest__email" title="<?php echo htmlspecialchars($rcGuestEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($rcGuestEmail, ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php endif; ?>
                                    <?php if ($rcGuestPhone !== ''): ?>
                                        <small class="text-muted rc-guest__phone"><?php echo htmlspecialchars($rcGuestPhone, ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="rc-source">
                                    <span class="badge badge-info"><?php echo htmlspecialchars(ucfirst((string)$payment['booking_type'])); ?></span>
                                    <small class="text-muted" style="display:block;margin-top:2px;"><?php echo htmlspecialchars((string)$payment['booking_reference']); ?></small>
                                </td>
                                <td class="rc-date"><?php echo htmlspecialchars(date('d M Y', strtotime((string)$payment['payment_date']))); ?></td>
                                <td class="rc-amount text-right"><strong><?php echo receipts_money((float)$payment['total_amount'], $currency_symbol); ?></strong></td>
                                <td class="rc-status">
                                    <?php $isRefundRow = (string)($payment['payment_type'] ?? '') === 'refund'; ?>
                                    <span class="badge <?php echo !empty($payment['receipt_generated']) ? 'badge-success' : ($isRefundRow ? 'badge-info' : 'badge-warning'); ?>">
                                        <?php echo !empty($payment['receipt_generated']) ? 'PDF ready' : ($isRefundRow ? 'Refund' : 'Needs PDF'); ?>
                                    </span>
                                    <?php if (!empty($payment['receipt_emailed_at'])): ?>
                                        <small class="text-muted" style="display:block;margin-top:2px;">Emailed <?php echo htmlspecialchars(date('d M Y', strtotime((string)$payment['receipt_emailed_at']))); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="rc-actions actions-cell">
                                    <form method="post" class="receipts-generate-form" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="payment_id" value="<?php echo (int)$payment['id']; ?>">
                                        <button type="submit" name="action" value="generate_receipt" class="quick-action" title="Generate / Regenerate PDF" style="color:var(--color-primary,#766550);">
                                            <i class="fas fa-file-pdf"></i>
                                        </button>
                                    </form>
                                    <?php if (!empty($payment['receipt_path'])): ?>
                                        <a class="quick-action" href="document-download.php?type=receipt&amp;id=<?php echo (int)$payment['id']; ?>" target="_blank" title="View PDF">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    <?php endif; ?>
                                    <div class="actions-more">
                                        <button type="button" class="quick-action actions-more-toggle" title="More actions" aria-label="More actions" onclick="toggleReceiptActionsMore(this, event)">
                                            <i class="fas fa-ellipsis-vertical"></i>
                                        </button>
                                        <div class="actions-more-menu">
                                            <a href="<?php echo htmlspecialchars($waUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Send WhatsApp</a>
                                            <button type="button" onclick="openReceiptEmailModal(<?php echo (int)$payment['id']; ?>, '<?php echo $guestEmail; ?>')"><i class="fas fa-envelope"></i> Email receipt</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination: shown whenever there are more than 10 receipts, so the rows-per-page choice is always there -->
                <?php if ($totalPages > 1 || $totalRows > 10): ?>
                    <div class="pagination-bar">
                        <?php
                        $rcptKeep = array_filter([
                            'type'      => $filters['type'] !== 'all' ? $filters['type'] : '',
                            'status'    => $filters['status'] !== 'all' ? $filters['status'] : '',
                            'date_from' => $filters['date_from'],
                            'date_to'   => $filters['date_to'],
                            'search'    => $filters['search'],
                            'scope'     => $scopeAll ? 'all' : '',
                        ], static fn($v) => $v !== '' && $v !== null);
                        $q = http_build_query($rcptKeep + ($limit !== 10 ? ['size' => (string)$limit] : []));
                        if ($totalPages > 1):
                        for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                            <a href="receipts.php?page=<?php echo $p; ?>&<?php echo htmlspecialchars($q, ENT_QUOTES); ?>"
                                class="pagination-item<?php echo $p === $page ? ' pagination-item--active' : ''; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor;
                        endif; ?>
                        <?php echo rh_lp_size_picker('receipts.php', $rcptKeep, $limit); ?>
                    </div>
                <?php endif; ?>

                <div class="table-footer-info">
                    Showing <?php echo count($payments); ?> of <?php echo $totalRows; ?> receipts
                </div>
            <?php endif; ?>
        </div>
        </div>
        </section>

        <!-- Email modal -->
        <div class="modal" id="modal-receipt-email" role="dialog" aria-modal="true" aria-label="Email Receipt" style="display:none;">
            <div class="modal__backdrop" onclick="closeReceiptEmailModal()"></div>
            <div class="modal__dialog modal__dialog--md">
                <div class="modal__header">
                    <h3 class="modal__title"><i class="fas fa-envelope"></i> Email Receipt</h3>
                    <button type="button" class="modal__close" onclick="closeReceiptEmailModal()" aria-label="Close">&times;</button>
                </div>
                <form method="post" id="receipt-email-form">
                    <div class="modal__body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="email_receipt">
                        <input type="hidden" name="payment_id" id="receipt-email-payment-id" value="">
                        <div class="form-group">
                            <label class="form-label">Recipient Email <span class="required">*</span></label>
                            <input type="email" name="recipient" id="receipt-email-recipient" class="form-control" placeholder="guest@example.com" required>
                        </div>
                    </div>
                    <div class="modal__footer">
                        <button type="button" class="btn btn--secondary" onclick="closeReceiptEmailModal()">Cancel</button>
                        <button type="submit" class="btn btn--primary"><i class="fas fa-envelope"></i> Send Receipt</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Receipt wording lives on the Email Templates page -->
        <?php if (hasPermission((int)($user['id'] ?? 0), 'edit_templates') || hasPermission((int)($user['id'] ?? 0), 'booking_settings')): ?>
        <p class="receipts-scope-note">
            <span><i class="fas fa-envelope-open-text" aria-hidden="true"></i> Receipt wording (email, PDF and WhatsApp message) is edited on the Email Templates page.</span>
            <a href="email-templates.php#email-templates">Open Email Templates</a>
        </p>
        <?php endif; ?>

        <!-- Recent Activity panel -->
        <section class="rh-panel">
            <div class="rh-panel__head">
                <h2 class="rh-panel__title">Recent receipt activity</h2>
            </div>
            <div class="receipts-activity-log rh-panel__body">
                <?php foreach ($recentEvents as $event): ?>
                    <div class="receipts-activity-item">
                        <strong><?php echo htmlspecialchars(ucfirst((string)$event['event_type'])); ?></strong>
                        <span><?php echo htmlspecialchars((string)($event['receipt_number'] ?? $event['payment_reference'] ?? '')); ?></span>
                        <small class="text-muted"><?php echo htmlspecialchars((string)$event['created_at']); ?><?php echo $event['recipient'] ? ' &middot; ' . htmlspecialchars((string)$event['recipient']) : ''; ?></small>
                    </div>
                <?php endforeach; ?>
                <?php if (!$recentEvents): ?>
                    <p class="rh-empty">No receipt events yet.</p>
                <?php endif; ?>
            </div>
        </section>

    </div>

    <script>
        // ── Receipt email modal ───────────────────────────────────────────────────────
        function openReceiptEmailModal(paymentId, email) {
            document.getElementById('receipt-email-payment-id').value = paymentId;
            document.getElementById('receipt-email-recipient').value = email || '';
            var modal = document.getElementById('modal-receipt-email');
            if (modal) modal.style.display = 'flex';
        }
        function closeReceiptEmailModal() {
            var modal = document.getElementById('modal-receipt-email');
            if (modal) modal.style.display = 'none';
        }

        // ── Actions-more dropdown ─────────────────────────────────────────────────
        function _rcpCloseAllMenus() {
            document.querySelectorAll('.receipts-table .actions-more.open').forEach(function(el) {
                el.classList.remove('open');
                var m = el.querySelector('.actions-more-menu');
                if (m) m.removeAttribute('style'); // the inline display:block kept a "closed" menu on screen
            });
        }

        function toggleReceiptActionsMore(btn, e) {
            if (e) e.stopPropagation();
            var wrap = btn.closest('.actions-more');
            if (!wrap) return;
            var menu = wrap.querySelector('.actions-more-menu');
            if (!menu) return;
            var isOpen = wrap.classList.contains('open');
            _rcpCloseAllMenus();
            if (isOpen) return;

            wrap.classList.add('open');
            window.rhPlaceActionMenu(btn, menu); // shared placement (admin-components.js)
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.actions-more')) _rcpCloseAllMenus();
        });
        document.addEventListener('scroll', function() { _rcpCloseAllMenus(); }, true);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                _rcpCloseAllMenus();
                closeReceiptEmailModal();
            }
        });
    </script>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

