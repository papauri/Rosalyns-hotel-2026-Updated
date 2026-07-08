<?php
require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */
/** @var PDO $pdo */

require_once '../config/receipts.php';
require_once 'includes/finance-schema.php';

if (!hasPermission((int)($user['id'] ?? 0), 'receipts')) {
    header('Location: dashboard.php?error=access_denied');
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

function receipts_build_where(array $input, array &$params): string
{
    $where = ["p.deleted_at IS NULL"];
    $where[] = "p.payment_status IN ('completed','paid','refunded')";
    if (($input['type'] ?? 'all') !== 'all') {
        $where[] = 'p.booking_type = ?';
        $params[] = $input['type'];
    } elseif (!empty($input['_scope_types']) && is_array($input['_scope_types'])) {
        // Preset scoping: default view shows only enabled modules' receipts.
        $where[] = 'p.booking_type IN (' . implode(',', array_fill(0, count($input['_scope_types']), '?')) . ')';
        foreach ($input['_scope_types'] as $t) { $params[] = $t; }
    }
    if (($input['status'] ?? 'all') === 'missing') {
        $where[] = "(p.receipt_number IS NULL OR p.receipt_number = '')";
    } elseif (($input['status'] ?? 'all') === 'generated') {
        $where[] = 'p.receipt_generated = 1';
    } elseif (($input['status'] ?? 'all') === 'emailed') {
        $where[] = 'p.receipt_emailed_at IS NOT NULL';
    }
    if (($input['date_from'] ?? '') !== '') {
        $where[] = 'p.payment_date >= ?';
        $params[] = $input['date_from'];
    }
    if (($input['date_to'] ?? '') !== '') {
        $where[] = 'p.payment_date <= ?';
        $params[] = $input['date_to'];
    }
    if (($input['search'] ?? '') !== '') {
        $like = '%' . $input['search'] . '%';
        $where[] = '(p.receipt_number LIKE ? OR p.payment_reference LIKE ? OR p.booking_reference LIKE ? OR p.notes LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    return 'WHERE ' . implode(' AND ', $where);
}

$filters = [
    'type' => $_GET['type'] ?? 'all',
    'status' => $_GET['status'] ?? 'all',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'search' => trim((string)($_GET['search'] ?? '')),
];
if (!in_array($filters['type'], ['all', 'room', 'conference', 'restaurant', 'gym', 'event'], true)) {
    $filters['type'] = 'all';
}
if (!in_array($filters['status'], ['all', 'missing', 'generated', 'emailed'], true)) {
    $filters['status'] = 'all';
}

// Preset scoping: default list shows receipts for enabled modules only.
// ?type= deep links and ?scope=all bypass; history is never deleted.
$scopeAll            = (($_GET['scope'] ?? '') === 'all');
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
                $stmt = $pdo->query("SELECT id FROM payments WHERE deleted_at IS NULL AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund' AND (receipt_number IS NULL OR receipt_number = '') ORDER BY payment_date ASC, id ASC LIMIT 500");
                $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
                $count = 0;
                foreach ($ids as $id) {
                    receipt_generate_pdf($pdo, (int)$id, $user);
                    $count++;
                }
                $message = 'Generated receipts for ' . $count . ' completed payment(s).';
            } elseif ($action === 'save_templates') {
                $subject = trim((string)($_POST['receipt_email_subject'] ?? ''));
                $html = trim((string)($_POST['receipt_email_template'] ?? ''));
                $whatsapp = trim((string)($_POST['receipt_whatsapp_template'] ?? ''));
                if ($subject === '' || $html === '' || $whatsapp === '') {
                    throw new RuntimeException('All receipt template fields are required.');
                }
                if (function_exists('upsertBookingEmailTemplateConfig')) {
                    $existingReceiptTemplate = getBookingEmailTemplateConfig('payment_receipt', [
                        'text_body' => '',
                        'is_active' => 1,
                    ]);
                    upsertBookingEmailTemplateConfig(
                        'payment_receipt',
                        'Payment Receipt Email',
                        $subject,
                        $html,
                        (string)($existingReceiptTemplate['text_body'] ?? ''),
                        (int)($existingReceiptTemplate['is_active'] ?? 1)
                    );
                }
                $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'finance') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
                $stmt->execute(['receipt_whatsapp_template', $whatsapp]);
                rh_log_event('receipts', 'info', 'Receipt templates updated', ['by' => $user['username'] ?? null]);
                $message = 'Receipt templates saved.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$params = [];
$whereSql = receipts_build_where($filters, $params);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("SELECT p.id, p.receipt_number, p.payment_reference, p.booking_type, p.booking_reference, p.payment_date, p.payment_method, p.payment_type, p.payment_status, p.total_amount, p.receipt_generated, p.receipt_emailed_at, COALESCE(au.full_name, au.username, p.processed_by) AS recorded_by_name
        FROM payments p
        LEFT JOIN admin_users au ON au.id = p.recorded_by
        $whereSql
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 5000");
    $stmt->execute($params);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="receipts-' . date('Ymd-His') . '.csv"');
    echo "Receipt Number,Payment Reference,Type,Booking Reference,Payment Date,Method,Payment Type,Status,Amount,Generated,Emailed At,Recorded By\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo implode(',', [
            receipts_csv_cell($row['receipt_number'] ?? ''),
            receipts_csv_cell($row['payment_reference'] ?? ''),
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
        COALESCE(SUM(CASE WHEN p.receipt_number IS NULL OR p.receipt_number = '' THEN 1 ELSE 0 END), 0) AS missing_receipts,
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
    $hStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p WHERE p.deleted_at IS NULL AND p.payment_status IN ('completed','paid','refunded') AND p.booking_type IN ($hPh)");
    $hStmt->execute($hiddenBookingTypes);
    $hiddenScopedCount = (int)$hStmt->fetchColumn();
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
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
    ORDER BY p.payment_date DESC, p.id DESC");
$listStmt->execute($params);
$payments = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$eventsStmt = $pdo->query("SELECT re.*, p.payment_reference FROM receipt_events re LEFT JOIN payments p ON p.id = re.payment_id ORDER BY re.created_at DESC, re.id DESC LIMIT 20");
$recentEvents = $eventsStmt ? $eventsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$receiptEmailTemplate = function_exists('getBookingEmailTemplateConfig')
    ? getBookingEmailTemplateConfig('payment_receipt', [
        'subject' => 'Receipt {{receipt_number}} - {{site_name}}',
        'html_body' => '',
    ])
    : [
        'subject' => 'Receipt {{receipt_number}} - {{site_name}}',
        'html_body' => '',
    ];
$templateSubject = (string)($receiptEmailTemplate['subject'] ?? 'Receipt {{receipt_number}} - {{site_name}}');
$templateHtml = (string)($receiptEmailTemplate['html_body'] ?? '');
$templateWhatsapp = getSetting('receipt_whatsapp_template', '');
$site_name = getSetting('site_name', 'Admin');
$templatePreviewMap = [
    '{{site_name}}' => $site_name,
    '{{guest_name}}' => 'Jane Mwale',
    '{{receipt_number}}' => 'RCP-20260521-0042',
    '{{payment_reference}}' => 'PAY-20260521-0874',
    '{{booking_reference}}' => 'BK-2026-1048',
    '{{payment_date}}' => date('d M Y'),
    '{{payment_method}}' => 'Mobile Money',
    '{{payment_type}}' => 'Full Payment',
    '{{payment_status}}' => 'Completed',
    '{{payment_amount}}' => $currency_symbol . ' 200,000.00',
    '{{vat_amount}}' => $currency_symbol . ' 45,000.00',
    '{{total_amount}}' => $currency_symbol . ' 245,000.00',
    '{{description}}' => 'Room booking payment for Deluxe Ocean Suite',
    '{{contact_email}}' => (string)(getEmailSetting('email_from_email', '') ?: 'reservations@example.com'),
];
$receiptPlaceholderTokens = array_keys($templatePreviewMap);
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
        <div class="cn-page-header">
            <div class="cn-page-header__left">
                <h2 class="section-title"><i class="fas fa-receipt"></i> Receipts</h2>
                <p class="cn-page-header__sub">Track every receipt from room, conference, restaurant and POS payments.</p>
            </div>
            <div class="cn-page-header__actions">
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="backfill_receipts">
                    <button class="btn btn--primary" type="submit"><i class="fas fa-wand-magic-sparkles"></i> Generate Missing</button>
                </form>
                <a class="btn btn--ghost" href="receipts.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert--danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- KPI Strip -->
        <div class="acct-kpis">
            <div class="acct-kpi">
                <div class="acct-kpi__label">Total Receipts</div>
                <div class="acct-kpi__value"><?php echo number_format((int)($summary['total_receipts'] ?? 0)); ?></div>
                <div class="acct-kpi__meta">payment records</div>
            </div>
            <div class="acct-kpi acct-kpi--pending">
                <div class="acct-kpi__label">Missing Numbers</div>
                <div class="acct-kpi__value"><?php echo number_format((int)($summary['missing_receipts'] ?? 0)); ?></div>
                <div class="acct-kpi__meta">need generation</div>
            </div>
            <div class="acct-kpi acct-kpi--paid">
                <div class="acct-kpi__label">Generated PDFs</div>
                <div class="acct-kpi__value"><?php echo number_format((int)($summary['generated_receipts'] ?? 0)); ?></div>
                <div class="acct-kpi__meta"><?php echo number_format((int)($summary['emailed_receipts'] ?? 0)); ?> emailed</div>
            </div>
            <div class="acct-kpi acct-kpi--revenue">
                <div class="acct-kpi__label">Receipt Value</div>
                <div class="acct-kpi__value"><span class="acct-kpi__currency"><?php echo htmlspecialchars($currency_symbol); ?></span><?php echo number_format((float)($summary['receipt_value'] ?? 0), 2); ?></div>
                <div class="acct-kpi__meta">all matched payments</div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="filter-section">
            <form method="get" action="receipts.php" class="filter-bar">
                <input type="text" name="search" class="filter-input" placeholder="Receipt #, payment ref, booking ref..." value="<?php echo htmlspecialchars($filters['search']); ?>">
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
                    <option value="missing" <?php echo $filters['status'] === 'missing' ? 'selected' : ''; ?>>Missing No.</option>
                    <option value="generated" <?php echo $filters['status'] === 'generated' ? 'selected' : ''; ?>>Generated</option>
                    <option value="emailed" <?php echo $filters['status'] === 'emailed' ? 'selected' : ''; ?>>Emailed</option>
                </select>
                <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($filters['date_to']); ?>">
                <button type="submit" class="btn btn--primary btn--sm"><i class="fas fa-search"></i> Search</button>
                <?php if ($filters['type'] !== 'all' || $filters['status'] !== 'all' || $filters['search'] !== '' || $filters['date_from'] !== '' || $filters['date_to'] !== ''): ?>
                    <a href="receipts.php" class="btn btn--ghost btn--sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php
        $scopeQs = $_GET;
        unset($scopeQs['scope'], $scopeQs['page']);
        if ($scopeActive && $hiddenScopedCount > 0): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-filter" style="margin-right:6px;"></i>Showing receipts for your active modules only (<?php echo number_format($hiddenScopedCount); ?> older record<?php echo $hiddenScopedCount === 1 ? '' : 's'; ?> from disabled modules hidden).</span>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($scopeQs, ['scope' => 'all']))); ?>" style="color:#8B7355; font-weight:600; text-decoration:none;">Show all history &rarr;</a>
            </div>
        <?php elseif ($scopeAll && $filters['type'] === 'all'): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-clock-rotate-left" style="margin-right:6px;"></i>Showing full receipt history, including records from disabled modules.</span>
                <a href="?<?php echo htmlspecialchars(http_build_query($scopeQs)); ?>" style="color:#8B7355; font-weight:600; text-decoration:none;">Show relevant only &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- Receipts Table -->
        <div class="table-container" data-admin-pagination-scope data-receipts-pagination-scope data-page-size="<?php echo (int)$limit; ?>" data-current-page="<?php echo (int)$page; ?>" data-total-pages="<?php echo (int)$totalPages; ?>">
            <?php if (!$payments): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>No receipt rows match the current filters.</p>
                </div>
            <?php else: ?>
                <table class="table receipts-table">
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <th>Payment</th>
                            <th>Source</th>
                            <th>Date</th>
                            <th class="text-right">Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
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
                            ?>
                            <tr data-receipts-row data-page-index="<?php echo (int)floor($index / max(1, $limit)) + 1; ?>">
                                <td>
                                    <strong class="tbl-ref"><?php echo htmlspecialchars((string)($payment['receipt_number'] ?: '—')); ?></strong>
                                    <?php if (empty($payment['receipt_number'])): ?>
                                        <span class="badge badge-warning" style="margin-top:3px;display:inline-block;">Missing</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars((string)$payment['payment_reference']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars((string)($payment['recorded_by_name'] ?? 'System')); ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo htmlspecialchars(ucfirst((string)$payment['booking_type'])); ?></span>
                                    <small class="text-muted" style="display:block;margin-top:2px;"><?php echo htmlspecialchars((string)$payment['booking_reference']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars(date('d M Y', strtotime((string)$payment['payment_date']))); ?></td>
                                <td class="text-right"><strong><?php echo receipts_money((float)$payment['total_amount'], $currency_symbol); ?></strong></td>
                                <td>
                                    <span class="badge <?php echo !empty($payment['receipt_generated']) ? 'badge-success' : 'badge-warning'; ?>">
                                        <?php echo !empty($payment['receipt_generated']) ? 'PDF ready' : 'Needs PDF'; ?>
                                    </span>
                                    <?php if (!empty($payment['receipt_emailed_at'])): ?>
                                        <small class="text-muted" style="display:block;margin-top:2px;">Emailed <?php echo htmlspecialchars(date('d M Y', strtotime((string)$payment['receipt_emailed_at']))); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-cell">
                                    <form method="post" class="receipts-generate-form" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="payment_id" value="<?php echo (int)$payment['id']; ?>">
                                        <button type="submit" name="action" value="generate_receipt" class="quick-action" title="Generate / Regenerate PDF" style="color:var(--color-primary,#8A775F);">
                                            <i class="fas fa-file-pdf"></i>
                                        </button>
                                    </form>
                                    <?php if (!empty($payment['receipt_path'])): ?>
                                        <a class="quick-action" href="../<?php echo htmlspecialchars((string)$payment['receipt_path']); ?>" target="_blank" title="View PDF">
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

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-bar">
                        <?php
                        $q = http_build_query(array_filter([
                            'type'      => $filters['type'] !== 'all' ? $filters['type'] : '',
                            'status'    => $filters['status'] !== 'all' ? $filters['status'] : '',
                            'date_from' => $filters['date_from'],
                            'date_to'   => $filters['date_to'],
                            'search'    => $filters['search'],
                        ]));
                        for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                            <a href="receipts.php?page=<?php echo $p; ?>&<?php echo $q; ?>"
                                class="pagination-item<?php echo $p === $page ? ' pagination-item--active' : ''; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

                <div class="table-footer-info">
                    Showing <?php echo count($payments); ?> of <?php echo $totalRows; ?> receipts
                </div>
            <?php endif; ?>
        </div>

        <!-- Email modal -->
        <div class="modal" id="modal-receipt-email" role="dialog" aria-modal="true" aria-label="Email Receipt" style="display:none;">
            <div class="modal__backdrop" onclick="closeReceiptEmailModal()"></div>
            <div class="modal__dialog modal__dialog--md">
                <div class="modal__header">
                    <h3 class="modal__title"><i class="fas fa-envelope"></i> Email Receipt</h3>
                    <button class="modal__close" onclick="closeReceiptEmailModal()" aria-label="Close">&times;</button>
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

        <!-- Templates panel -->
        <div class="receipts-panel">
            <div class="receipts-panel__head">
                <div>
                    <h3 class="section-title" style="font-size:1.1rem;"><i class="fas fa-pen-to-square"></i> Editable Receipt Templates</h3>
                    <p class="text-muted" style="font-size:0.85rem;margin:4px 0 0;">Placeholders: {{site_name}}, {{guest_name}}, {{receipt_number}}, {{payment_reference}}, {{booking_reference}}, {{payment_date}}, {{payment_method}}, {{payment_type}}, {{total_amount}}, {{contact_email}}.</p>
                </div>
                <button type="button" class="btn btn--ghost btn--sm" id="receiptsPreviewToggle"><i class="fas fa-eye"></i> Preview</button>
            </div>
            <form method="post" class="receipts-template-form" id="receiptTemplateForm" data-receipt-placeholder-tokens="<?php echo htmlspecialchars((string)json_encode($receiptPlaceholderTokens), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="save_templates">
                <div class="receipts-template-fields">
                    <div class="form-group">
                        <label class="form-label">Email Subject</label>
                        <input class="form-control" id="receiptEmailSubject" name="receipt_email_subject" value="<?php echo htmlspecialchars($templateSubject); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email HTML Body</label>
                        <textarea class="form-control" id="receiptEmailTemplate" name="receipt_email_template" rows="8"><?php echo htmlspecialchars($templateHtml); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">WhatsApp Message</label>
                        <textarea class="form-control" id="receiptWhatsappTemplate" name="receipt_whatsapp_template" rows="3"><?php echo htmlspecialchars($templateWhatsapp); ?></textarea>
                    </div>
                </div>
                <button class="btn btn--primary" type="submit"><i class="fas fa-save"></i> Save Templates</button>
            </form>

            <div class="receipts-template-preview" id="receiptsTemplatePreview" hidden data-preview-map="<?php echo htmlspecialchars((string)json_encode($templatePreviewMap), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="receipts-template-preview__section">
                    <h4 class="receipts-template-preview__title">Subject Preview</h4>
                    <p class="receipts-template-preview__subject" id="receiptPreviewSubject"></p>
                </div>
                <div class="receipts-template-preview__section">
                    <h4 class="receipts-template-preview__title">Email Body Preview</h4>
                    <iframe class="receipts-template-preview__frame" id="receiptPreviewFrame" title="Receipt email preview" sandbox="allow-same-origin"></iframe>
                </div>
                <div class="receipts-template-preview__section">
                    <h4 class="receipts-template-preview__title">WhatsApp Preview</h4>
                    <p class="receipts-template-preview__whatsapp" id="receiptPreviewWhatsapp"></p>
                </div>
            </div>
        </div>

        <!-- Recent Activity panel -->
        <div class="receipts-panel">
            <div class="receipts-panel__head">
                <div>
                    <h3 class="section-title" style="font-size:1.1rem;"><i class="fas fa-clock-rotate-left"></i> Recent Receipt Activity</h3>
                </div>
            </div>
            <div class="receipts-activity-log">
                <?php foreach ($recentEvents as $event): ?>
                    <div class="receipts-activity-item">
                        <strong><?php echo htmlspecialchars(ucfirst((string)$event['event_type'])); ?></strong>
                        <span><?php echo htmlspecialchars((string)($event['receipt_number'] ?? $event['payment_reference'] ?? '')); ?></span>
                        <small class="text-muted"><?php echo htmlspecialchars((string)$event['created_at']); ?><?php echo $event['recipient'] ? ' &middot; ' . htmlspecialchars((string)$event['recipient']) : ''; ?></small>
                    </div>
                <?php endforeach; ?>
                <?php if (!$recentEvents): ?>
                    <div class="empty-state" style="padding:2rem;"><i class="fas fa-inbox"></i><p>No receipt events yet.</p></div>
                <?php endif; ?>
            </div>
        </div>

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
            var rect = btn.getBoundingClientRect();
            var menuW = 190;
            var left = rect.right - menuW;
            var top = rect.bottom + 4;
            left = Math.max(8, Math.min(left, window.innerWidth - menuW - 8));
            top = Math.min(top, window.innerHeight - 120);
            menu.style.cssText = 'display:block;position:fixed;z-index:12050;top:' + Math.round(top) + 'px;left:' + Math.round(left) + 'px;width:' + menuW + 'px;';
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

