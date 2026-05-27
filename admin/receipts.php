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
if (!in_array($filters['type'], ['all', 'room', 'conference', 'restaurant'], true)) {
    $filters['type'] = 'all';
}
if (!in_array($filters['status'], ['all', 'missing', 'generated', 'emailed'], true)) {
    $filters['status'] = 'all';
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
        COALESCE(SUM(p.total_amount), 0) AS receipt_value
    FROM payments p $whereSql");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-responsive-enhancements.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/admin-finance.css">
    <link rel="stylesheet" href="css/receipts.css">
    <script src="js/receipts.js" defer></script>
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content receipts-page">
        <div class="acct-page-header">
            <div class="acct-page-header__copy">
                <h1 class="acct-page-header__title">Receipts</h1>
                <p class="acct-page-header__subtitle">Track every receipt from room, conference, restaurant, POS, credit-note and adjustment payments.</p>
            </div>
            <form method="get" class="acct-filter-form">
                <label class="acct-filter-field"><span>Type</span><select name="type">
                        <option value="all">All</option>
                        <option value="room" <?php echo $filters['type'] === 'room' ? 'selected' : ''; ?>>Rooms</option>
                        <option value="conference" <?php echo $filters['type'] === 'conference' ? 'selected' : ''; ?>>Conference</option>
                        <option value="restaurant" <?php echo $filters['type'] === 'restaurant' ? 'selected' : ''; ?>>Restaurant/POS</option>
                    </select></label>
                <label class="acct-filter-field"><span>Status</span><select name="status">
                        <option value="all">All</option>
                        <option value="missing" <?php echo $filters['status'] === 'missing' ? 'selected' : ''; ?>>Missing No.</option>
                        <option value="generated" <?php echo $filters['status'] === 'generated' ? 'selected' : ''; ?>>Generated</option>
                        <option value="emailed" <?php echo $filters['status'] === 'emailed' ? 'selected' : ''; ?>>Emailed</option>
                    </select></label>
                <label class="acct-filter-field"><span>From</span><input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>"></label>
                <label class="acct-filter-field"><span>To</span><input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>"></label>
                <label class="acct-filter-field"><span>Search</span><input type="text" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="Receipt, payment, booking"></label>
                <button class="acct-btn acct-btn--primary" type="submit"><i class="fas fa-filter"></i> Apply</button>
                <a class="acct-btn acct-btn--ghost" href="receipts.php"><i class="fas fa-times"></i> Clear</a>
            </form>
        </div>

        <?php if ($message): ?><div class="pos-acct-alert pos-acct-alert--success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="pos-acct-alert pos-acct-alert--danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="acct-kpi-grid">
            <div class="acct-kpi">
                <div class="acct-kpi__label">Receipt rows</div>
                <div class="acct-kpi__value"><?php echo number_format((int)($summary['total_receipts'] ?? 0)); ?></div>
            </div>
            <div class="acct-kpi acct-kpi--receivables">
                <div class="acct-kpi__label">Missing numbers</div>
                <div class="acct-kpi__value"><?php echo number_format((int)($summary['missing_receipts'] ?? 0)); ?></div>
            </div>
            <div class="acct-kpi acct-kpi--cash">
                <div class="acct-kpi__label">Generated PDFs</div>
                <div class="acct-kpi__value"><?php echo number_format((int)($summary['generated_receipts'] ?? 0)); ?></div>
            </div>
            <div class="acct-kpi acct-kpi--vat">
                <div class="acct-kpi__label">Receipt value</div>
                <div class="acct-kpi__value"><?php echo receipts_money((float)($summary['receipt_value'] ?? 0), $currency_symbol); ?></div>
            </div>
        </div>

        <div class="acct-panel" data-admin-pagination-scope data-receipts-pagination-scope data-page-size="<?php echo (int)$limit; ?>" data-current-page="<?php echo (int)$page; ?>" data-total-pages="<?php echo (int)$totalPages; ?>">
            <div class="acct-panel__head">
                <div>
                    <h3><i class="fas fa-receipt"></i> Receipt Register</h3>
                    <p>Generate missing receipts, open PDFs, email guests, export CSV and share a manual WhatsApp message.</p>
                </div>
                <div class="pos-acct-bulk-actions">
                    <form method="post" class="receipts-inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="backfill_receipts">
                        <button class="acct-btn acct-btn--primary" type="submit"><i class="fas fa-wand-magic-sparkles"></i> Generate Missing</button>
                    </form>
                    <a class="acct-btn acct-btn--ghost" href="receipts.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
                </div>
            </div>
            <div class="acct-table-wrap">
                <table class="acct-table">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>Payment</th>
                            <th>Source</th>
                            <th>Date</th>
                            <th class="num">Amount</th>
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
                            ?>
                            <tr data-receipts-row data-page-index="<?php echo (int)floor($index / max(1, $limit)) + 1; ?>">
                                <td data-label="Receipt"><strong><?php echo htmlspecialchars((string)($payment['receipt_number'] ?: 'Missing')); ?></strong><small><?php echo !empty($payment['receipt_path']) ? htmlspecialchars((string)$payment['receipt_path']) : 'No PDF stored yet'; ?></small></td>
                                <td data-label="Payment"><span class="acct-row-label"><i class="fas fa-money-check"></i><?php echo htmlspecialchars((string)$payment['payment_reference']); ?></span><small><?php echo htmlspecialchars((string)($payment['recorded_by_name'] ?? 'System')); ?></small></td>
                                <td data-label="Source"><?php echo htmlspecialchars(ucfirst((string)$payment['booking_type'])); ?><small><?php echo htmlspecialchars((string)$payment['booking_reference']); ?></small></td>
                                <td data-label="Date"><?php echo htmlspecialchars(date('d M Y', strtotime((string)$payment['payment_date']))); ?></td>
                                <td data-label="Amount" class="num"><?php echo receipts_money((float)$payment['total_amount'], $currency_symbol); ?></td>
                                <td data-label="Status"><span class="pos-acct-pill <?php echo !empty($payment['receipt_generated']) ? 'pos-acct-pill--closed' : 'pos-acct-pill--warn'; ?>"><?php echo !empty($payment['receipt_generated']) ? 'PDF ready' : 'Needs PDF'; ?></span><?php if (!empty($payment['receipt_emailed_at'])): ?><small>Emailed <?php echo htmlspecialchars(date('d M Y H:i', strtotime((string)$payment['receipt_emailed_at']))); ?></small><?php endif; ?></td>
                                <td data-label="Actions">
                                    <form method="post" class="receipts-inline-form receipts-inline-form--row">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="payment_id" value="<?php echo (int)$payment['id']; ?>">
                                        <div class="receipts-action-buttons">
                                            <button class="btn btn-sm btn-outline" name="action" value="generate_receipt" type="submit"><i class="fas fa-file-pdf"></i> Generate</button>
                                            <?php if (!empty($payment['receipt_path'])): ?><a class="btn btn-sm btn-outline" href="../<?php echo htmlspecialchars((string)$payment['receipt_path']); ?>" target="_blank"><i class="fas fa-eye"></i> View</a><?php endif; ?>
                                            <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($waUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                                        </div>
                                        <div class="receipts-action-email">
                                            <input type="email" name="recipient" class="receipts-recipient-input" placeholder="Recipient email (optional)" value="<?php echo htmlspecialchars((string)($waContext['guest_email'] ?? '')); ?>">
                                            <button class="btn btn-sm btn-outline" name="action" value="email_receipt" type="submit"><i class="fas fa-envelope"></i> Email</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$payments): ?><tr>
                                <td colspan="7" class="pos-acct-empty">No receipt rows match the current filters.</td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?><nav class="bookings-pagination receipts-pagination" aria-label="Receipt pagination">
                    <?php if ($page > 1): ?>
                        <a class="acct-btn acct-btn--ghost receipts-pagination__link" href="receipts.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['page' => $page - 1])), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-chevron-left"></i> Prev</a>
                    <?php else: ?>
                        <span class="acct-btn acct-btn--ghost receipts-pagination__link is-disabled"><i class="fas fa-chevron-left"></i> Prev</span>
                    <?php endif; ?>

                    <?php for ($i = $windowStart; $i <= $windowEnd; $i++): ?>
                        <a class="acct-btn receipts-pagination__link <?php echo $i === $page ? 'acct-btn--primary' : 'acct-btn--ghost'; ?>" href="receipts.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['page' => $i])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a class="acct-btn acct-btn--ghost receipts-pagination__link" href="receipts.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['page' => $page + 1])), ENT_QUOTES, 'UTF-8'); ?>">Next <i class="fas fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="acct-btn acct-btn--ghost receipts-pagination__link is-disabled">Next <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </nav><?php endif; ?>
        </div>

        <div class="acct-panel receipts-panel-spacing">
            <div class="acct-panel__head">
                <div>
                    <h3><i class="fas fa-pen-to-square"></i> Editable Receipt Templates</h3>
                    <p>Placeholders: {{site_name}}, {{guest_name}}, {{receipt_number}}, {{payment_reference}}, {{booking_reference}}, {{payment_date}}, {{payment_method}}, {{payment_type}}, {{total_amount}}, {{contact_email}}.</p>
                </div>
                <button type="button" class="acct-btn acct-btn--ghost" id="receiptsPreviewToggle"><i class="fas fa-eye"></i> Preview</button>
            </div>
            <form method="post" class="vat-edit-form" id="receiptTemplateForm" data-receipt-placeholder-tokens="<?php echo htmlspecialchars((string)json_encode($receiptPlaceholderTokens), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="save_templates">
                <div class="vat-edit-fields">
                    <label class="vat-field-group vat-field-group--wide"><span class="vat-field-group__label">Email subject</span><input class="vat-field-group__control" id="receiptEmailSubject" name="receipt_email_subject" value="<?php echo htmlspecialchars($templateSubject); ?>"></label>
                    <label class="vat-field-group vat-field-group--wide"><span class="vat-field-group__label">Email HTML</span><textarea class="vat-field-group__control" id="receiptEmailTemplate" name="receipt_email_template" rows="8"><?php echo htmlspecialchars($templateHtml); ?></textarea></label>
                    <label class="vat-field-group vat-field-group--wide"><span class="vat-field-group__label">WhatsApp message</span><textarea class="vat-field-group__control" id="receiptWhatsappTemplate" name="receipt_whatsapp_template" rows="3"><?php echo htmlspecialchars($templateWhatsapp); ?></textarea></label>
                </div>
                <button class="acct-btn acct-btn--primary" type="submit"><i class="fas fa-save"></i> Save Templates</button>
            </form>

            <div class="receipts-template-preview" id="receiptsTemplatePreview" hidden data-preview-map="<?php echo htmlspecialchars((string)json_encode($templatePreviewMap), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="receipts-template-preview__section">
                    <h4 class="receipts-template-preview__title">Subject Preview</h4>
                    <p class="receipts-template-preview__subject" id="receiptPreviewSubject"></p>
                </div>
                <div class="receipts-template-preview__section">
                    <h4 class="receipts-template-preview__title">Email Body Preview</h4>
                    <iframe class="receipts-template-preview__frame" id="receiptPreviewFrame" title="Receipt email preview" sandbox=""></iframe>
                </div>
                <div class="receipts-template-preview__section">
                    <h4 class="receipts-template-preview__title">WhatsApp Preview</h4>
                    <p class="receipts-template-preview__whatsapp" id="receiptPreviewWhatsapp"></p>
                </div>
            </div>
        </div>

        <div class="acct-panel receipts-panel-spacing">
            <div class="acct-panel__head">
                <div>
                    <h3><i class="fas fa-clock-rotate-left"></i> Recent Receipt Activity</h3>
                </div>
            </div>
            <div class="pos-acct-log-list">
                <?php foreach ($recentEvents as $event): ?>
                    <div class="pos-acct-log-item"><strong><?php echo htmlspecialchars(ucfirst((string)$event['event_type'])); ?></strong> <?php echo htmlspecialchars((string)($event['receipt_number'] ?? $event['payment_reference'] ?? '')); ?><small><?php echo htmlspecialchars((string)$event['created_at']); ?><?php echo $event['recipient'] ? ' - ' . htmlspecialchars((string)$event['recipient']) : ''; ?></small></div>
                <?php endforeach; ?>
                <?php if (!$recentEvents): ?><div class="pos-acct-empty">No receipt events yet.</div><?php endif; ?>
            </div>
        </div>

    </div>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
