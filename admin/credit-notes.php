<?php

/**
 * Credit Notes Management — Admin
 *
 * Lists, issues, applies, voids and re-sends credit notes.
 * Covers: room bookings, conference bookings, and goodwill CNs.
 */

require_once 'admin-init.php';
/** @var array  $user */
/** @var string $csrf_token */
/** @var PDO    $pdo */

require_once '../config/credit-notes.php';
require_once '../includes/finance-sequences.php';

// Run expired-CN sweep once per page load (fast if nothing to do)
checkExpiredCreditNotes($pdo);

$message = '';
$error   = '';

// ─────────────────────────────────────────────────────────────────────────────
// POST ACTIONS
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }

    $action = trim($_POST['action'] ?? '');

    try {
        // ── Issue a goodwill CN manually ──────────────────────────────────────
        if ($action === 'issue_cn') {
            $guestName   = trim($_POST['guest_name']   ?? '');
            $guestEmail  = trim($_POST['guest_email']  ?? '');
            $amount      = (float)($_POST['amount']    ?? 0);
            $reason      = trim($_POST['reason']       ?? 'goodwill');
            $reasonNotes = trim($_POST['reason_notes'] ?? '');
            $sendEmail   = !empty($_POST['send_email_to_guest']);

            if ($guestName === '') {
                throw new RuntimeException('Guest name is required.');
            }
            if ($amount <= 0) {
                throw new RuntimeException('Credit note amount must be greater than zero.');
            }

            $issueBkId   = (int)($_POST['booking_id']       ?? 0);
            $issueBkRef  = trim($_POST['booking_reference']  ?? '');
            $issueBkType = trim($_POST['booking_type']        ?? '');

            $result = issueCreditNote($pdo, [
                'booking_type'      => in_array($issueBkType, ['room', 'conference'], true) ? $issueBkType : 'goodwill',
                'booking_id'        => $issueBkId > 0 ? $issueBkId : null,
                'booking_reference' => $issueBkRef !== '' ? $issueBkRef : null,
                'guest_name'        => $guestName,
                'guest_email'       => $guestEmail,
                'amount'            => $amount,
                'reason'            => $reason,
                'reason_notes'      => $reasonNotes,
                'issued_by'         => (int)$user['id'],
                'send_email'        => $sendEmail && $guestEmail !== '',
                'generate_pdf'      => true,
            ]);

            if (!$result['success']) {
                throw new RuntimeException($result['error'] ?? 'Unknown error.');
            }
            $message = 'Credit note ' . htmlspecialchars((string)$result['credit_note_number']) . ' issued successfully.';
        }

        // ── Apply a CN to a booking ───────────────────────────────────────────
        if ($action === 'apply_cn') {
            $cnId        = (int)($_POST['credit_note_id'] ?? 0);
            $bookingId   = (int)($_POST['booking_id']     ?? 0);
            $bookingType = trim($_POST['booking_type']    ?? 'room');
            $amount      = (float)($_POST['amount']       ?? 0);
            $notes       = trim($_POST['notes']           ?? '');

            if ($cnId <= 0) {
                throw new RuntimeException('Credit note ID is required.');
            }
            if ($bookingId <= 0) {
                throw new RuntimeException('A valid booking must be selected.');
            }
            if ($amount <= 0) {
                throw new RuntimeException('Amount to apply must be greater than zero.');
            }

            $result = applyCreditNote($pdo, $cnId, [
                'booking_id'   => $bookingId,
                'booking_type' => $bookingType,
            ], $amount, (int)$user['id'], $notes);

            if (!$result['success']) {
                throw new RuntimeException($result['error'] ?? 'Unknown error.');
            }
            $message = 'Credit note applied. Remaining balance: ' . getSetting('currency_symbol') . ' ' . number_format($result['remaining_balance'], 2);
        }

        // ── Void a CN ─────────────────────────────────────────────────────────
        if ($action === 'void_cn') {
            $cnId       = (int)($_POST['credit_note_id'] ?? 0);
            $voidReason = trim($_POST['void_reason']     ?? '');

            if ($cnId <= 0) {
                throw new RuntimeException('Credit note ID is required.');
            }
            if ($voidReason === '') {
                throw new RuntimeException('A void reason is required.');
            }

            $result = voidCreditNote($pdo, $cnId, $voidReason, (int)$user['id']);
            if (!$result['success']) {
                throw new RuntimeException($result['error'] ?? 'Unknown error.');
            }
            $message = 'Credit note voided.';
        }

        // ── Regenerate PDF ────────────────────────────────────────────────────
        if ($action === 'regenerate_pdf') {
            $cnId = (int)($_POST['credit_note_id'] ?? 0);
            if ($cnId <= 0) {
                throw new RuntimeException('Credit note ID is required.');
            }
            $result = generateCreditNotePDF($pdo, $cnId);
            if (!$result) {
                throw new RuntimeException('PDF generation failed.');
            }
            $message = 'PDF regenerated.';
        }

        // ── Resend email ──────────────────────────────────────────────────────
        if ($action === 'resend_email') {
            $cnId = (int)($_POST['credit_note_id'] ?? 0);
            if ($cnId <= 0) {
                throw new RuntimeException('Credit note ID is required.');
            }
            $result = sendCreditNoteEmail($pdo, $cnId);
            if (!$result['success']) {
                throw new RuntimeException($result['message']);
            }
            $message = 'Email sent: ' . $result['message'];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FILTER PARAMS
// ─────────────────────────────────────────────────────────────────────────────
$filterStatus    = $_GET['status']     ?? 'all';
$filterType      = $_GET['type']       ?? 'all';

// Module flags — gate booking-type pickers/filters by the active preset.
$cn_mod_bookings = function_exists('moduleEnabled') && moduleEnabled('bookings');
$cn_mod_conf     = function_exists('moduleEnabled') && moduleEnabled('conference');
$cn_any_booking  = $cn_mod_bookings || $cn_mod_conf;
$filterDateFrom  = $_GET['date_from']  ?? '';
$filterDateTo    = $_GET['date_to']    ?? '';
$search          = trim($_GET['search'] ?? '');
$page            = max(1, (int)($_GET['page'] ?? 1));
$limit           = 30;
$offset          = ($page - 1) * $limit;

// ─────────────────────────────────────────────────────────────────────────────
// DATA FETCH — list
// ─────────────────────────────────────────────────────────────────────────────
$where   = [];
$params  = [];

if ($filterStatus !== 'all') {
    $where[]  = 'cn.status = ?';
    $params[] = $filterStatus;
}
if ($filterType !== 'all') {
    $where[]  = 'cn.booking_type = ?';
    $params[] = $filterType;
}

// Preset scoping: credit notes only carry room/conference/goodwill types.
// Hide rows for DISABLED modules by default; goodwill/standalone notes always
// show. ?type= deep links and ?scope=all bypass; nothing is deleted.
$scopeAll           = (($_GET['scope'] ?? '') === 'all');
$cnModuleTypes      = ['room' => 'bookings', 'conference' => 'conference'];
$hiddenBookingTypes = [];
foreach ($cnModuleTypes as $cnType => $cnModule) {
    if (!(function_exists('moduleEnabled') && moduleEnabled($cnModule))) {
        $hiddenBookingTypes[] = $cnType;
    }
}
$scopeActive = $filterType === 'all' && !$scopeAll && $hiddenBookingTypes !== [];
if ($scopeActive) {
    $where[] = 'cn.booking_type NOT IN (' . implode(',', array_fill(0, count($hiddenBookingTypes), '?')) . ')';
    $params  = array_merge($params, $hiddenBookingTypes);
}
if ($filterDateFrom !== '') {
    $where[]  = 'DATE(cn.issued_at) >= ?';
    $params[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $where[]  = 'DATE(cn.issued_at) <= ?';
    $params[] = $filterDateTo;
}
if ($search !== '') {
    $where[]  = '(cn.credit_note_number LIKE ? OR cn.guest_name LIKE ? OR cn.guest_email LIKE ? OR cn.booking_reference LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// KPIs for this filter set
$kpiStmt = $pdo->prepare("
    SELECT
        COUNT(*)                                                                        AS total_issued,
        COALESCE(SUM(cn.original_amount), 0)                                            AS total_value,
        COALESCE(SUM(cn.amount_used), 0)                                                AS total_redeemed,
        COALESCE(SUM(CASE WHEN cn.status IN ('active','partially_applied') THEN cn.balance ELSE 0 END), 0) AS total_outstanding,
        COALESCE(SUM(CASE WHEN cn.status = 'active'            THEN 1 ELSE 0 END), 0)  AS count_active,
        COALESCE(SUM(CASE WHEN cn.status = 'partially_applied' THEN 1 ELSE 0 END), 0)  AS count_partial,
        COALESCE(SUM(CASE WHEN cn.status = 'fully_applied'     THEN 1 ELSE 0 END), 0)  AS count_used,
        COALESCE(SUM(CASE WHEN cn.status = 'voided'            THEN 1 ELSE 0 END), 0)  AS count_voided,
        COALESCE(SUM(CASE WHEN cn.status = 'expired'           THEN 1 ELSE 0 END), 0)  AS count_expired
    FROM credit_notes cn
    $whereSql
");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM credit_notes cn $whereSql");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($total / $limit);

$listParams = array_merge($params, [$limit, $offset]);
$listStmt   = $pdo->prepare("
    SELECT cn.*,
           au.full_name AS issued_by_name
    FROM credit_notes cn
    LEFT JOIN admin_users au ON au.id = cn.issued_by
    $whereSql
    ORDER BY cn.issued_at DESC, cn.id DESC
    LIMIT ? OFFSET ?
");
$listStmt->execute($listParams);
$creditNotes = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Rows hidden by preset scoping (for the notice above the table)
$hiddenScopedCount = 0;
if ($scopeActive) {
    $hPh = implode(',', array_fill(0, count($hiddenBookingTypes), '?'));
    $hStmt = $pdo->prepare("SELECT COUNT(*) FROM credit_notes cn WHERE cn.booking_type IN ($hPh)");
    $hStmt->execute($hiddenBookingTypes);
    $hiddenScopedCount = (int)$hStmt->fetchColumn();
}

$currencySymbol = getSetting('currency_symbol') ?: 'MWK';

// ─────────────────────────────────────────────────────────────────────────────
// ISSUE MODAL CONTENT
// ─────────────────────────────────────────────────────────────────────────────
ob_start();
?>
<form method="post" id="issue-cn-form" action="credit-notes.php">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="issue_cn">
    <input type="hidden" name="booking_id" id="issue-booking-id" value="">
    <input type="hidden" name="booking_type" id="issue-booking-type-hidden" value="">
    <input type="hidden" name="booking_reference" id="issue-booking-ref" value="">

    <!-- Optional: link to an existing booking -->
    <div class="cn-issue-booking-link">
        <?php if ($cn_any_booking): ?>
        <label class="cn-issue-booking-link__toggle">
            <input type="checkbox" id="issue-link-booking-toggle" onchange="cnToggleIssueBookingSearch()">
            <i class="fas fa-link" style="color:var(--finance-accent,#8A775F);"></i>
            <span>Link to Existing Booking <small class="text-muted">(optional — leave unchecked for walk-in)</small></span>
        </label>
        <?php endif; ?>
        <div class="cn-issue-booking-link__fields" id="issue-booking-search-wrap" style="display:none;">
            <div class="form-group" style="margin-bottom:8px;">
                <select id="issue-search-booking-type" class="form-control" onchange="cnIssueSearchBooking()">
                    <?php if ($cn_mod_bookings): ?><option value="room">Room Booking</option><?php endif; ?>
                    <?php if ($cn_mod_conf): ?><option value="conference">Conference Booking</option><?php endif; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:6px;">
                <input type="text" id="issue-booking-search" class="form-control"
                    placeholder="Search by reference, name, email or phone..."
                    oninput="cnIssueSearchBooking()" autocomplete="off">
                <div id="issue-booking-results" class="cn-booking-results"></div>
            </div>
            <div id="issue-booking-selected" style="display:none;">
                <div class="cn-selected-booking" id="issue-selected-booking-info"></div>
            </div>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label"><?php echo $cn_mod_bookings ? 'Guest' : 'Customer'; ?> Name <span class="required">*</span></label>
        <input type="text" name="guest_name" class="form-control" required maxlength="150" placeholder="Full name">
    </div>
    <div class="form-group">
        <label class="form-label"><?php echo $cn_mod_bookings ? 'Guest' : 'Customer'; ?> Email</label>
        <input type="email" name="guest_email" class="form-control" maxlength="150" placeholder="<?php echo $cn_mod_bookings ? 'guest' : 'customer'; ?>@example.com">
    </div>
    <div class="form-group">
        <label class="form-label">Credit Note Value <span class="required">*</span></label>
        <input type="number" name="amount" class="form-control" required min="0.01" step="0.01" placeholder="0.00" data-currency="<?php echo htmlspecialchars($currencySymbol, ENT_QUOTES); ?>">
    </div>
    <div class="form-group">
        <label class="form-label">Reason <span class="required">*</span></label>
        <select name="reason" class="form-control">
            <option value="goodwill">Goodwill Gesture</option>
            <option value="cancellation"><?php echo $cn_any_booking ? 'Booking Cancellation' : 'Order Cancellation'; ?></option>
            <option value="service_issue">Service Issue / Complaint</option>
            <?php if ($cn_mod_bookings): ?><option value="early_checkout">Early Checkout</option><?php endif; ?>
            <option value="overpayment">Overpayment</option>
            <option value="pricing_error">Pricing / Billing Error</option>
            <option value="other">Other</option>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Notes (optional)</label>
        <textarea name="reason_notes" class="form-control" rows="3" placeholder="Additional context..." maxlength="2000"></textarea>
    </div>
    <div class="form-group">
        <label class="form-check">
            <input type="checkbox" name="send_email_to_guest" value="1">
            <span>Send credit note by email to guest</span>
        </label>
    </div>
</form>
<?php
$issueModalContent = ob_get_clean();

// ─────────────────────────────────────────────────────────────────────────────
// APPLY MODAL CONTENT
// ─────────────────────────────────────────────────────────────────────────────
ob_start();
?>
<form method="post" id="apply-cn-form" action="credit-notes.php">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="apply_cn">
    <input type="hidden" name="credit_note_id" id="apply-cn-id" value="">

    <div class="cn-apply-info" id="apply-cn-info">
        <p class="cn-apply-number">CN: <strong id="apply-cn-number">—</strong></p>
        <p class="cn-apply-balance">Available Balance: <strong><?php echo htmlspecialchars($currencySymbol); ?> <span id="apply-cn-balance">0.00</span></strong></p>
    </div>

    <div class="form-group">
        <label class="form-label">Booking Type <span class="required">*</span></label>
        <select name="booking_type" id="apply-booking-type" class="form-control" onchange="cnSearchBooking()">
            <?php if ($cn_mod_bookings): ?><option value="room">Room Booking</option><?php endif; ?>
            <?php if ($cn_mod_conf): ?><option value="conference">Conference Booking</option><?php endif; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Search Booking <span class="required">*</span></label>
        <input type="text" id="apply-booking-search" class="form-control" placeholder="Reference, guest name, email or phone..." oninput="cnSearchBooking()">
        <div id="apply-booking-results" class="cn-booking-results"></div>
    </div>
    <input type="hidden" name="booking_id" id="apply-booking-id" value="">
    <div class="form-group" id="apply-booking-selected" style="display:none;">
        <div class="cn-selected-booking" id="apply-selected-booking-info"></div>
    </div>
    <div class="form-group">
        <label class="form-label">Amount to Apply <span class="required">*</span></label>
        <input type="number" name="amount" id="apply-cn-amount" class="form-control" required min="0.01" step="0.01" placeholder="0.00" data-currency="<?php echo htmlspecialchars($currencySymbol, ENT_QUOTES); ?>">
    </div>
    <div class="form-group">
        <label class="form-label">Notes (optional)</label>
        <input type="text" name="notes" class="form-control" maxlength="500" placeholder="Internal notes...">
    </div>
</form>
<?php
$applyModalContent = ob_get_clean();

// ─────────────────────────────────────────────────────────────────────────────
// VOID MODAL CONTENT
// ─────────────────────────────────────────────────────────────────────────────
ob_start();
?>
<form method="post" id="void-cn-form" action="credit-notes.php">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="void_cn">
    <input type="hidden" name="credit_note_id" id="void-cn-id" value="">
    <p class="text-muted">You are about to void credit note <strong id="void-cn-number">—</strong>. This action cannot be undone.</p>
    <div class="form-group">
        <label class="form-label">Reason for Voiding <span class="required">*</span></label>
        <textarea name="void_reason" class="form-control" rows="3" required maxlength="1000" placeholder="Explain why this credit note is being voided..."></textarea>
    </div>
</form>
<?php
$voidModalContent = ob_get_clean();

// ─────────────────────────────────────────────────────────────────────────────
// HISTORY MODAL CONTENT
// ─────────────────────────────────────────────────────────────────────────────
$historyModalContent = '<div id="cn-history-content"><p class="text-muted">Loading...</p></div>';

require_once '../includes/modal.php';
$issueModalFooter = '<button type="button" class="btn btn--secondary" data-modal-close>Cancel</button>'
    . '<button type="submit" form="issue-cn-form" class="btn btn--primary">Issue Credit Note</button>';
$applyModalFooter = '<button type="button" class="btn btn--secondary" data-modal-close>Cancel</button>'
    . '<button type="submit" form="apply-cn-form" class="btn btn--primary" id="apply-cn-submit">Apply Credit Note</button>';
$voidModalFooter  = '<button type="button" class="btn btn--secondary" data-modal-close>Cancel</button>'
    . '<button type="submit" form="void-cn-form" class="btn btn--danger">Void Credit Note</button>';

ob_start();
renderModal('modal-issue-cn',   'Issue Credit Note',    $issueModalContent,   ['size' => 'md', 'footer' => $issueModalFooter]);
renderModal('modal-apply-cn',   'Apply Credit Note',    $applyModalContent,   ['size' => 'lg', 'footer' => $applyModalFooter]);
renderModal('modal-void-cn',    'Void Credit Note',     $voidModalContent,    ['size' => 'md', 'footer' => $voidModalFooter]);
renderModal('modal-cn-history', 'Redemption History',   $historyModalContent, ['size' => 'lg']);
$modalsHtml = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Credit Notes — Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
    <link rel="stylesheet" href="css/credit-notes.css?v=<?php echo @filemtime(__DIR__ . '/css/credit-notes.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="admin-container finance-page credit-notes-page">

        <?php if ($message): ?>
            <div class="alert alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert--danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Page header -->
        <div class="cn-page-header">
            <div class="cn-page-header__left">
                <h2 class="section-title"><i class="fas fa-file-invoice"></i> Credit Notes</h2>
                <p class="cn-page-header__sub">Manage, issue and apply guest credit notes</p>
            </div>
            <div class="cn-page-header__actions">
                <button class="btn btn--primary" onclick="openIssueCN()">
                    <i class="fas fa-plus"></i> Issue Credit Note
                </button>
            </div>
        </div>

        <!-- KPI Strip -->
        <div class="acct-kpis cn-kpis">
            <div class="acct-kpi acct-kpi--revenue">
                <div class="acct-kpi__label">Total Issued</div>
                <div class="acct-kpi__value"><span class="acct-kpi__currency"><?php echo htmlspecialchars($currencySymbol); ?></span><?php echo number_format((float)($kpi['total_value'] ?? 0), 2); ?></div>
                <div class="acct-kpi__meta"><?php echo (int)($kpi['total_issued'] ?? 0); ?> credit note<?php echo (int)($kpi['total_issued'] ?? 0) !== 1 ? 's' : ''; ?></div>
            </div>
            <div class="acct-kpi acct-kpi--paid">
                <div class="acct-kpi__label">Total Redeemed</div>
                <div class="acct-kpi__value"><span class="acct-kpi__currency"><?php echo htmlspecialchars($currencySymbol); ?></span><?php echo number_format((float)($kpi['total_redeemed'] ?? 0), 2); ?></div>
                <div class="acct-kpi__meta">Applied to bookings</div>
            </div>
            <div class="acct-kpi acct-kpi--pending">
                <div class="acct-kpi__label">Outstanding Balance</div>
                <div class="acct-kpi__value"><span class="acct-kpi__currency"><?php echo htmlspecialchars($currencySymbol); ?></span><?php echo number_format((float)($kpi['total_outstanding'] ?? 0), 2); ?></div>
                <div class="acct-kpi__meta"><?php echo (int)($kpi['count_active'] ?? 0) + (int)($kpi['count_partial'] ?? 0); ?> active</div>
            </div>
            <div class="acct-kpi">
                <div class="acct-kpi__label">Status Breakdown</div>
                <div class="acct-kpi__value" style="font-size:18px;"><?php echo (int)($kpi['count_active'] ?? 0); ?> active</div>
                <div class="acct-kpi__meta">
                    <?php echo (int)($kpi['count_partial'] ?? 0); ?> partial &middot;
                    <?php echo (int)($kpi['count_used'] ?? 0); ?> used &middot;
                    <?php echo (int)($kpi['count_voided'] ?? 0); ?> voided &middot;
                    <?php echo (int)($kpi['count_expired'] ?? 0); ?> expired
                </div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="filter-section">
            <form method="get" action="credit-notes.php" class="filter-bar">
                <input type="text" name="search" class="filter-input" placeholder="Search CN#, guest name, email, booking ref..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="status" class="filter-select">
                    <option value="all" <?php if ($filterStatus === 'all') echo ' selected'; ?>>All Statuses</option>
                    <option value="active" <?php if ($filterStatus === 'active') echo ' selected'; ?>>Active</option>
                    <option value="partially_applied" <?php if ($filterStatus === 'partially_applied') echo ' selected'; ?>>Partially Applied</option>
                    <option value="fully_applied" <?php if ($filterStatus === 'fully_applied') echo ' selected'; ?>>Fully Applied</option>
                    <option value="voided" <?php if ($filterStatus === 'voided') echo ' selected'; ?>>Voided</option>
                    <option value="expired" <?php if ($filterStatus === 'expired') echo ' selected'; ?>>Expired</option>
                </select>
                <select name="type" class="filter-select">
                    <option value="all" <?php if ($filterType === 'all') echo ' selected'; ?>>All Types</option>
                    <?php if ($cn_mod_bookings || $filterType === 'room'): ?><option value="room" <?php if ($filterType === 'room') echo ' selected'; ?>>Room Booking</option><?php endif; ?>
                    <?php if ($cn_mod_conf || $filterType === 'conference'): ?><option value="conference" <?php if ($filterType === 'conference') echo ' selected'; ?>>Conference</option><?php endif; ?>
                    <option value="goodwill" <?php if ($filterType === 'goodwill') echo ' selected'; ?>>Goodwill</option>
                </select>
                <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($filterDateFrom); ?>" placeholder="From">
                <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($filterDateTo); ?>" placeholder="To">
                <button type="submit" class="btn btn--primary btn--sm"><i class="fas fa-search"></i> Search</button>
                <?php if ($filterStatus !== 'all' || $filterType !== 'all' || $search !== '' || $filterDateFrom !== '' || $filterDateTo !== ''): ?>
                    <a href="credit-notes.php" class="btn btn--ghost btn--sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php
        $scopeQs = $_GET;
        unset($scopeQs['scope'], $scopeQs['page']);
        if ($scopeActive && $hiddenScopedCount > 0): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-filter" style="margin-right:6px;"></i>Showing credit notes for your active modules only (<?php echo number_format($hiddenScopedCount); ?> older record<?php echo $hiddenScopedCount === 1 ? '' : 's'; ?> from disabled modules hidden).</span>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($scopeQs, ['scope' => 'all']))); ?>" style="color:#8B7355; font-weight:600; text-decoration:none;">Show all history &rarr;</a>
            </div>
        <?php elseif ($scopeAll && $filterType === 'all'): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-clock-rotate-left" style="margin-right:6px;"></i>Showing full credit-note history, including records from disabled modules.</span>
                <a href="?<?php echo htmlspecialchars(http_build_query($scopeQs)); ?>" style="color:#8B7355; font-weight:600; text-decoration:none;">Show relevant only &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- Credit Notes Table -->
        <div class="table-container">
            <?php if (empty($creditNotes)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-invoice"></i>
                    <p>No credit notes found<?php echo $search !== '' ? ' matching "' . htmlspecialchars($search) . '"' : ''; ?>.</p>
                    <button class="btn btn--primary" data-modal-open="modal-issue-cn">Issue First Credit Note</button>
                </div>
            <?php else: ?>
                <table class="admin-table cn-table">
                    <thead>
                        <tr>
                            <th>CN Number</th>
                            <th>Guest</th>
                            <th>Type / Booking</th>
                            <th>Reason</th>
                            <th class="text-right">Face Value</th>
                            <th class="text-right">Used</th>
                            <th class="text-right">Balance</th>
                            <th>Status</th>
                            <th>Issued</th>
                            <th>Expires</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($creditNotes as $cn): ?>
                            <?php
                            $isActive    = in_array($cn['status'], ['active', 'partially_applied'], true);
                            $isVoided    = $cn['status'] === 'voided';
                            $isExpired   = $cn['status'] === 'expired';
                            $isFull      = $cn['status'] === 'fully_applied';
                            $balance     = max(0.0, (float)$cn['balance']);
                            $expiresSoon = $isActive && $cn['expires_at'] && $cn['expires_at'] <= date('Y-m-d', strtotime('+30 days'));
                            $reasonLabels = [
                                'cancellation'  => 'Cancellation',
                                'service_issue' => 'Service Issue',
                                'early_checkout' => 'Early Checkout',
                                'overpayment'   => 'Overpayment',
                                'goodwill'      => 'Goodwill',
                                'pricing_error' => 'Pricing Error',
                                'other'         => 'Other',
                            ];
                            ?>
                            <tr class="cn-row<?php echo $isVoided ? ' cn-row--voided' : ($isExpired ? ' cn-row--expired' : ($isFull ? ' cn-row--used' : '')); ?>">
                                <td>
                                    <strong class="cn-number"><?php echo htmlspecialchars((string)$cn['credit_note_number']); ?></strong>
                                    <?php if ($cn['email_sent']): ?>
                                        <span class="cn-badge cn-badge--emailed" title="Email sent"><i class="fas fa-envelope-check"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="cn-guest"><?php echo htmlspecialchars((string)$cn['guest_name']); ?></div>
                                    <?php if ($cn['guest_email']): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars((string)$cn['guest_email']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="cn-type-badge cn-type-badge--<?php echo htmlspecialchars((string)$cn['booking_type']); ?>">
                                        <?php echo htmlspecialchars(ucfirst((string)$cn['booking_type'])); ?>
                                    </span>
                                    <?php if ($cn['booking_reference']): ?>
                                        <br><small><?php echo htmlspecialchars((string)$cn['booking_reference']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span title="<?php echo htmlspecialchars((string)($cn['reason_notes'] ?? '')); ?>">
                                        <?php echo htmlspecialchars($reasonLabels[$cn['reason']] ?? ucfirst((string)$cn['reason'])); ?>
                                    </span>
                                </td>
                                <td class="text-right"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo number_format((float)$cn['original_amount'], 2); ?></td>
                                <td class="text-right"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo number_format((float)$cn['amount_used'], 2); ?></td>
                                <td class="text-right<?php echo $balance > 0 && $isActive ? ' cn-balance--available' : ''; ?>">
                                    <strong><?php echo htmlspecialchars($currencySymbol); ?> <?php echo number_format($balance, 2); ?></strong>
                                </td>
                                <td>
                                    <?php
                                    $statusLabels = ['active' => 'Active', 'partially_applied' => 'Partial', 'fully_applied' => 'Used', 'voided' => 'Voided', 'expired' => 'Expired'];
                                    $statusClass  = ['active' => 'success', 'partially_applied' => 'warning', 'fully_applied' => 'info', 'voided' => 'danger', 'expired' => 'muted'];
                                    ?>
                                    <span class="badge badge-<?php echo $statusClass[$cn['status']] ?? 'info'; ?>">
                                        <?php echo $statusLabels[$cn['status']] ?? ucfirst((string)$cn['status']); ?>
                                    </span>
                                    <?php if ($expiresSoon): ?>
                                        <br><span class="cn-badge cn-badge--warn" title="Expires soon"><i class="fas fa-clock"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d M Y', strtotime((string)$cn['issued_at'])); ?></td>
                                <td>
                                    <?php if ($cn['expires_at']): ?>
                                        <span class="<?php echo $expiresSoon ? 'text-warning' : 'text-muted'; ?>">
                                            <?php echo date('d M Y', strtotime((string)$cn['expires_at'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-cell">
                                    <?php if ($isActive && $balance > 0 && $cn_any_booking): ?>
                                        <button class="quick-action"
                                            onclick="openApplyCN(<?php echo (int)$cn['id']; ?>, '<?php echo htmlspecialchars((string)$cn['credit_note_number']); ?>', <?php echo number_format($balance, 2, '.', ''); ?>)"
                                            title="Apply to booking" style="color:var(--color-success,#1f7a42);">
                                            <i class="fas fa-check-double"></i>
                                        </button>
                                    <?php endif; ?>
                                    <div class="actions-more">
                                        <button type="button" class="quick-action actions-more-toggle" title="More actions" aria-label="More actions" onclick="toggleCNActionsMore(this, event)">
                                            <i class="fas fa-ellipsis-vertical"></i>
                                        </button>
                                        <div class="actions-more-menu">
                                            <a href="api/credit-notes.php?action=view_pdf&id=<?php echo (int)$cn['id']; ?>" target="_blank"><i class="fas fa-eye"></i> View PDF</a>
                                            <button type="button" onclick="openCNHistory(<?php echo (int)$cn['id']; ?>, '<?php echo htmlspecialchars((string)$cn['credit_note_number']); ?>')"><i class="fas fa-history"></i> Redemption history</button>
                                            <button type="button" onclick="cnPdfAction(this,<?php echo (int)$cn['id']; ?>)"><i class="fas fa-file-pdf"></i> Regenerate PDF</button>
                                            <?php if ($cn['guest_email']): ?>
                                                <button type="button" onclick="cnEmailAction(this,<?php echo (int)$cn['id']; ?>)"><i class="fas fa-envelope"></i> Resend email</button>
                                            <?php endif; ?>
                                            <?php if ($isActive): ?>
                                                <hr class="menu-divider">
                                                <button type="button" class="text-danger" onclick="openVoidCN(<?php echo (int)$cn['id']; ?>, '<?php echo htmlspecialchars((string)$cn['credit_note_number']); ?>')"><i class="fas fa-ban"></i> Void credit note</button>
                                            <?php endif; ?>
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
                            'status'    => $filterStatus !== 'all' ? $filterStatus : '',
                            'type'      => $filterType !== 'all' ? $filterType : '',
                            'date_from' => $filterDateFrom,
                            'date_to'   => $filterDateTo,
                            'search'    => $search,
                        ]));
                        for ($p = 1; $p <= $totalPages; $p++): ?>
                            <a href="credit-notes.php?page=<?php echo $p; ?>&<?php echo $q; ?>"
                                class="pagination-item<?php echo $p === $page ? ' pagination-item--active' : ''; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

                <div class="table-footer-info">
                    Showing <?php echo count($creditNotes); ?> of <?php echo $total; ?> credit notes
                </div>
            <?php endif; ?>
        </div><!-- .table-container -->

    </div><!-- .admin-container -->

    <?php echo $modalsHtml; ?>

    <script>
        window._rhCsrf = <?php echo json_encode($csrf_token); ?>;

        // ── Open Apply CN modal ──────────────────────────────────────────────────────
        function openApplyCN(id, number, balance) {
            document.getElementById('apply-cn-id').value = id;
            document.getElementById('apply-cn-number').textContent = number;
            document.getElementById('apply-cn-balance').textContent = balance.toFixed(2);
            document.getElementById('apply-cn-amount').max = balance;
            document.getElementById('apply-cn-amount').value = '';
            document.getElementById('apply-booking-search').value = '';
            document.getElementById('apply-booking-results').innerHTML = '';
            document.getElementById('apply-booking-id').value = '';
            document.getElementById('apply-booking-selected').style.display = 'none';
            if (window.Modal) Modal.open('modal-apply-cn');
        }

        // ── Open Void CN modal ───────────────────────────────────────────────────────
        function openVoidCN(id, number) {
            document.getElementById('void-cn-id').value = id;
            document.getElementById('void-cn-number').textContent = number;
            document.querySelector('#void-cn-form textarea[name="void_reason"]').value = '';
            if (window.Modal) Modal.open('modal-void-cn');
        }

        // ── Load CN history ──────────────────────────────────────────────────────────
        function openCNHistory(id, number) {
            const modal = document.getElementById('modal-cn-history');
            const content = document.getElementById('cn-history-content');
            if (!modal || !content) return;
            content.innerHTML = '<p class="text-muted">Loading...</p>';
            if (window.Modal) Modal.open('modal-cn-history');
            document.querySelector('#modal-cn-history .modal__title').textContent = 'Redemption History — ' + number;

            fetch('api/credit-notes.php?action=get_history&credit_note_id=' + encodeURIComponent(id), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        content.innerHTML = '<p class="text-muted">No redemption history found.</p>';
                        return;
                    }
                    const apps = data.data.applications;
                    if (!apps || apps.length === 0) {
                        content.innerHTML = '<p class="text-muted">This credit note has not been applied yet.</p>';
                        return;
                    }
                    let html = '<table class="admin-table"><thead><tr><th>Date</th><th>Booking</th><th>Type</th><th class="text-right">Amount Applied</th><th>Processed By</th></tr></thead><tbody>';
                    apps.forEach(function(a) {
                        html += '<tr><td>' + (a.applied_at || '—') + '</td>' +
                            '<td>' + (a.applied_to_booking_reference || 'N/A') + '</td>' +
                            '<td>' + (a.applied_to_booking_type || '') + '</td>' +
                            '<td class="text-right"><strong>' + (a.amount_applied || '0.00') + '</strong></td>' +
                            '<td>' + (a.applied_by_name || 'Admin') + '</td></tr>';
                    });
                    html += '</tbody></table>';
                    content.innerHTML = html;
                })
                .catch(function() {
                    content.innerHTML = '<p class="text-muted">Failed to load history.</p>';
                });
        }

        // ── Booking search for Apply modal ──────────────────────────────────────────
        let cnSearchTimer = null;

        function cnSearchBooking() {
            clearTimeout(cnSearchTimer);
            const q = document.getElementById('apply-booking-search').value.trim();
            const type = document.getElementById('apply-booking-type').value;
            const box = document.getElementById('apply-booking-results');
            if (q.length < 2) {
                box.innerHTML = '';
                return;
            }
            cnSearchTimer = setTimeout(function() {
                fetch('api/credit-notes.php?action=search_booking&q=' + encodeURIComponent(q) + '&booking_type=' + encodeURIComponent(type), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(res => res.json())
                    .then(data => {
                        box.innerHTML = '';
                        if (!data.success || !data.data.length) {
                            box.innerHTML = '<div class="cn-booking-result-item cn-booking-result-item--empty">No matching bookings found</div>';
                            return;
                        }
                        data.data.forEach(function(b) {
                            const item = document.createElement('div');
                            item.className = 'cn-booking-result-item';
                            item.innerHTML = '<strong>' + (b.reference || '') + '</strong> — ' + (b.guest_name || '') +
                                (b.guest_email ? '<br><small class="text-muted">' + b.guest_email + (b.meta ? ' &middot; ' + b.meta : '') + '</small>' : '') +
                                (b.balance ? ' <span class="text-muted" style="float:right;font-size:.85em;">' + b.balance + '</span>' : '');
                            item.addEventListener('click', function() {
                                document.getElementById('apply-booking-id').value = b.id;
                                document.getElementById('apply-booking-search').value = (b.reference || '') + ' — ' + (b.guest_name || '');
                                document.getElementById('apply-selected-booking-info').innerHTML =
                                    '<i class="fas fa-check-circle" style="color:var(--finance-success,#1f7a42)"></i> ' +
                                    '<strong>' + (b.reference || '') + '</strong> &nbsp;|&nbsp; ' + (b.guest_name || '') +
                                    (b.guest_email ? ' <small class="text-muted">&nbsp;' + b.guest_email + '</small>' : '') +
                                    (b.balance ? ' &nbsp;|&nbsp; Balance: <strong>' + b.balance + '</strong>' : '');
                                document.getElementById('apply-booking-selected').style.display = 'block';
                                box.innerHTML = '';
                            });
                            box.appendChild(item);
                        });
                    })
                    .catch(function() {
                        box.innerHTML = '';
                    });
            }, 350);
        }

        // ── AJAX quick-actions: PDF regenerate + email resend ───────────────────────
        function cnToast(msg, type) {
            var t = document.getElementById('cn-toast');
            if (t) t.remove();
            t = document.createElement('div');
            t.id = 'cn-toast';
            t.className = 'alert alert--' + (type === 'success' ? 'success' : 'danger');
            t.style.cssText = 'position:fixed;top:76px;right:20px;z-index:9999;min-width:260px;max-width:440px;padding:12px 16px;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.18);';
            t.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-triangle') + '"></i> ' + msg;
            document.body.appendChild(t);
            setTimeout(function() {
                if (t.parentNode) t.parentNode.removeChild(t);
            }, 4000);
        }

        function cnPdfAction(btn, id) {
            var origHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            fetch('api/credit-notes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'regenerate_pdf',
                        credit_note_id: id,
                        csrf_token: window._rhCsrf || ''
                    })
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    btn.disabled = false;
                    btn.innerHTML = origHTML;
                    d.success ? cnToast('PDF regenerated.', 'success') : cnToast(d.error || 'PDF generation failed.', 'error');
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.innerHTML = origHTML;
                    cnToast('Network error. Please try again.', 'error');
                });
        }

        function cnEmailAction(btn, id) {
            var origHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            fetch('api/credit-notes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'resend_email',
                        credit_note_id: id,
                        csrf_token: window._rhCsrf || ''
                    })
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    btn.disabled = false;
                    if (d.success) {
                        btn.innerHTML = '<i class="fas fa-check"></i>';
                        btn.classList.add('btn--success');
                        btn.classList.remove('btn--ghost');
                        setTimeout(function() {
                            btn.innerHTML = origHTML;
                            btn.classList.remove('btn--success');
                            btn.classList.add('btn--ghost');
                        }, 3000);
                        cnToast('<i class="fas fa-envelope-check"></i> ' + (d.message || 'Email sent successfully.'), 'success');
                    } else {
                        btn.innerHTML = origHTML;
                        cnToast(d.error || 'Email send failed.', 'error');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.innerHTML = origHTML;
                    cnToast('Network error. Please try again.', 'error');
                });
        }

        // ── Open Issue CN modal (reset state) ──────────────────────────────────────
        function openIssueCN() {
            document.getElementById('issue-link-booking-toggle').checked = false;
            document.getElementById('issue-booking-search-wrap').style.display = 'none';
            document.getElementById('issue-booking-id').value = '';
            document.getElementById('issue-booking-type-hidden').value = '';
            document.getElementById('issue-booking-ref').value = '';
            document.getElementById('issue-booking-selected').style.display = 'none';
            document.getElementById('issue-booking-search').value = '';
            document.getElementById('issue-booking-results').innerHTML = '';
            document.getElementById('issue-cn-form').reset();
            if (window.Modal) Modal.open('modal-issue-cn');
        }

        // ── Toggle issue modal booking search ──────────────────────────────────────
        function cnToggleIssueBookingSearch() {
            var checked = document.getElementById('issue-link-booking-toggle').checked;
            document.getElementById('issue-booking-search-wrap').style.display = checked ? 'block' : 'none';
            if (!checked) {
                document.getElementById('issue-booking-id').value = '';
                document.getElementById('issue-booking-type-hidden').value = '';
                document.getElementById('issue-booking-ref').value = '';
                document.getElementById('issue-booking-selected').style.display = 'none';
                document.getElementById('issue-booking-search').value = '';
                document.getElementById('issue-booking-results').innerHTML = '';
            }
        }

        // ── Booking search in Issue CN modal ───────────────────────────────────────
        var cnIssueSearchTimer = null;

        function cnIssueSearchBooking() {
            clearTimeout(cnIssueSearchTimer);
            var q = document.getElementById('issue-booking-search').value.trim();
            var type = document.getElementById('issue-search-booking-type').value;
            var box = document.getElementById('issue-booking-results');
            if (q.length < 2) {
                box.innerHTML = '';
                return;
            }
            cnIssueSearchTimer = setTimeout(function() {
                fetch('api/credit-notes.php?action=search_booking&q=' + encodeURIComponent(q) + '&booking_type=' + encodeURIComponent(type), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        box.innerHTML = '';
                        if (!data.success || !data.data.length) {
                            box.innerHTML = '<div class="cn-booking-result-item cn-booking-result-item--empty">No matching bookings found</div>';
                            return;
                        }
                        data.data.forEach(function(b) {
                            var item = document.createElement('div');
                            item.className = 'cn-booking-result-item';
                            item.innerHTML = '<strong>' + (b.reference || '') + '</strong> — ' + (b.guest_name || '') +
                                (b.guest_email ? '<br><small class="text-muted">' + b.guest_email + '</small>' : '');
                            item.addEventListener('click', function() {
                                document.getElementById('issue-booking-id').value = b.id;
                                document.getElementById('issue-booking-type-hidden').value = type;
                                document.getElementById('issue-booking-ref').value = b.reference || '';
                                var nameField = document.querySelector('#issue-cn-form input[name="guest_name"]');
                                var emailField = document.querySelector('#issue-cn-form input[name="guest_email"]');
                                if (nameField && !nameField.value) nameField.value = b.guest_name || '';
                                if (emailField && !emailField.value) emailField.value = b.guest_email || '';
                                document.getElementById('issue-booking-search').value = (b.reference || '') + ' — ' + (b.guest_name || '');
                                document.getElementById('issue-selected-booking-info').innerHTML =
                                    '<i class="fas fa-check-circle" style="color:var(--finance-success,#1f7a42)"></i> ' +
                                    '<strong>' + (b.reference || '') + '</strong> &nbsp;|&nbsp; ' + (b.guest_name || '') +
                                    (b.guest_email ? ' <small class="text-muted">&nbsp;' + b.guest_email + '</small>' : '');
                                document.getElementById('issue-booking-selected').style.display = 'block';
                                box.innerHTML = '';
                            });
                            box.appendChild(item);
                        });
                    })
                    .catch(function() {
                        box.innerHTML = '';
                    });
            }, 350);
        }

        // ── Actions-more dropdown ─────────────────────────────────────────────────
        function _cnCloseAllMenus(except) {
            document.querySelectorAll('.cn-table .actions-more.open').forEach(function(el) {
                if (el !== except) el.classList.remove('open');
            });
        }

        function toggleCNActionsMore(btn, e) {
            if (e) e.stopPropagation();
            var wrap = btn.closest('.actions-more');
            if (!wrap) return;
            var menu = wrap.querySelector('.actions-more-menu');
            if (!menu) return;
            var isOpen = wrap.classList.contains('open');
            _cnCloseAllMenus(null);
            if (isOpen) return;

            wrap.classList.add('open');
            // Position using fixed so overflow doesn't clip it
            var rect = btn.getBoundingClientRect();
            var menuW = 200;
            var left = rect.right - menuW;
            var top = rect.bottom + 4;
            left = Math.max(8, Math.min(left, window.innerWidth - menuW - 8));
            top  = Math.min(top, window.innerHeight - 160);
            menu.style.cssText = 'display:block;position:fixed;z-index:12050;top:' + Math.round(top) + 'px;left:' + Math.round(left) + 'px;width:' + menuW + 'px;';
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.actions-more')) _cnCloseAllMenus(null);
        });
        document.addEventListener('scroll', function() { _cnCloseAllMenus(null); }, true);

        // ── Submit-button spinner for modal forms ──────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            [
                ['issue-cn-form', '#modal-issue-cn .modal__footer .btn--primary'],
                ['apply-cn-form', '#modal-apply-cn .modal__footer .btn--primary'],
                ['void-cn-form', '#modal-void-cn .modal__footer .btn--danger']
            ].forEach(function(pair) {
                var form = document.getElementById(pair[0]);
                if (!form) return;
                form.addEventListener('submit', function() {
                    var btn = document.querySelector(pair[1]);
                    if (btn && !btn.disabled) {
                        btn._origLabel = btn.innerHTML;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
                        btn.disabled = true;
                    }
                });
            });
        });
    </script>
    <script src="js/admin-components.js"></script>
    <?php require_once 'includes/admin-footer.php'; ?>

