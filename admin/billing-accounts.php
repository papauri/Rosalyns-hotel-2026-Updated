<?php

/**
 * Company accounts (company pays later / on account / LPO) — owner 2026-11-13, decisions D1-D6, migration 084.
 *
 *   - list of companies with what each still owes (open bookings, overdue, oldest);
 *   - add / edit a company (billing email + CC, terms, active);
 *   - one company: its open bookings, statement (PDF + email to the billing email), the payments it sent;
 *   - Record company payment: ONE payment split exactly across its bookings (D5: excess refused), review step before
 *     saving, each line a normal room payment (includes/billing-accounts.php rh_billing_record_remittance()).
 * Page permission: company_accounts. Recording a payment also needs payment_add.
 */
require_once 'admin-init.php';
/** @var array  $user */
/** @var string $csrf_token */
/** @var PDO    $pdo */
require_once __DIR__ . '/../includes/billing-accounts.php';

$ready = rh_billing_ready($pdo);
$uid = (int)($user['id'] ?? 0);
$canPay = hasPermission($uid, 'payment_add');
$cur = rh_billing_currency();
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$message = (string)($_SESSION['ba_message'] ?? '');
$error = (string)($_SESSION['ba_error'] ?? '');
unset($_SESSION['ba_message'], $_SESSION['ba_error']);
$viewId = (int)($_GET['id'] ?? 0);

// ── Statement PDF download ─────────────────────────────────────────────────────
if ($ready && isset($_GET['statement'])) {
    $acc = rh_billing_account($pdo, (int)$_GET['statement']);
    if ($acc) {
        require_once __DIR__ . '/../config/email.php';
        try {
            $pdf = bookingRenderPdfFromHtml(rh_billing_statement_html($pdo, $acc, rh_billing_open_bookings($pdo, (int)$acc['id'])), 'Statement ' . $acc['name']);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', 'Statement-' . $acc['name'] . '-' . date('Ymd')) . '.pdf"');
            echo $pdf;
            exit;
        } catch (Throwable $ex) {
            $_SESSION['ba_error'] = 'The statement could not be made: ' . $ex->getMessage();
        }
    }
    header('Location: billing-accounts.php' . ($acc ? '?id=' . (int)$acc['id'] : ''));
    exit;
}

// ── POST actions ──────────────────────────────────────────────────────────────
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'billing-accounts.php';
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['ba_error'] = 'Security token invalid. Refresh the page and try again.';
        header('Location: ' . $back);
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_account') {
            $id = (int)($_POST['account_id'] ?? 0);
            $name = mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? ''))), 0, 150);
            if ($name === '') {
                throw new Exception('Enter the company name.');
            }
            $email = trim((string)($_POST['billing_email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('The billing email is not a valid email address.');
            }
            $ccRaw = trim((string)($_POST['billing_cc'] ?? ''));
            $cc = implode(', ', rh_billing_email_list($ccRaw));
            if ($ccRaw !== '' && $cc === '') {
                throw new Exception('The copy (CC) list has no valid email address.');
            }
            $vals = [
                'name' => $name, 'contact_name' => mb_substr(trim((string)($_POST['contact_name'] ?? '')), 0, 150), 'billing_email' => $email, 'billing_cc' => $cc,
                'phone' => mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 50), 'address' => mb_substr(trim((string)($_POST['address'] ?? '')), 0, 255),
                'tax_number' => mb_substr(trim((string)($_POST['tax_number'] ?? '')), 0, 60), 'terms_days' => max(0, min(365, (int)($_POST['terms_days'] ?? 30))),
                'active' => !empty($_POST['active']) ? 1 : 0, 'notes' => mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255),
            ];
            $dupe = $pdo->prepare('SELECT id FROM billing_accounts WHERE name = ? AND id <> ?');
            $dupe->execute([$name, $id]);
            if ($dupe->fetchColumn()) {
                throw new Exception('A company with that name already exists.');
            }
            $nul = static fn($v) => ($v === '' ? null : $v);
            if ($id > 0) {
                $old = rh_billing_account($pdo, $id);
                if (!$old) {
                    throw new Exception('Company not found.');
                }
                $pdo->prepare('UPDATE billing_accounts SET name = ?, contact_name = ?, billing_email = ?, billing_cc = ?, phone = ?, address = ?, tax_number = ?, terms_days = ?, active = ?, notes = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$vals['name'], $nul($vals['contact_name']), $nul($vals['billing_email']), $nul($vals['billing_cc']), $nul($vals['phone']), $nul($vals['address']),
                        $nul($vals['tax_number']), $vals['terms_days'], $vals['active'], $nul($vals['notes']), $id]);
                $changes = [];
                foreach ($vals as $k => $v) {
                    if ((string)($old[$k] ?? '') !== (string)$v) {
                        $changes[$k] = ['old' => (string)($old[$k] ?? ''), 'new' => (string)$v];
                    }
                }
                rh_log_event('payments', 'info', 'Company account changed', ['account_id' => $id, 'company' => $name, 'changes' => $changes, 'by' => $user['username'] ?? null]);
                $_SESSION['ba_message'] = $changes ? 'Company saved.' : 'Nothing changed.';
            } else {
                $pdo->prepare('INSERT INTO billing_accounts (name, contact_name, billing_email, billing_cc, phone, address, tax_number, terms_days, active, notes, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([$vals['name'], $nul($vals['contact_name']), $nul($vals['billing_email']), $nul($vals['billing_cc']), $nul($vals['phone']), $nul($vals['address']),
                        $nul($vals['tax_number']), $vals['terms_days'], $vals['active'], $nul($vals['notes']), $uid ?: null]);
                $id = (int)$pdo->lastInsertId();
                rh_log_event('payments', 'info', 'Company account added', ['account_id' => $id, 'company' => $name, 'by' => $user['username'] ?? null]);
                $_SESSION['ba_message'] = 'Company added. Put bookings on its account from Booking Details > Who pays.';
            }
            $back = 'billing-accounts.php?id=' . $id;
        } elseif ($action === 'record_payment') {
            $id = (int)($_POST['account_id'] ?? 0);
            $back = 'billing-accounts.php?id=' . $id;
            if (!$canPay) {
                throw new Exception('You do not have permission to record payments (Add Payment).');
            }
            $res = rh_billing_record_remittance($pdo, $id, [
                'amount' => $_POST['amount'] ?? '0', 'payment_method' => $_POST['payment_method'] ?? '', 'received_date' => $_POST['received_date'] ?? '',
                'bank_reference' => $_POST['bank_reference'] ?? '', 'notes' => $_POST['notes'] ?? '', 'lines' => (array)($_POST['line'] ?? []),
            ], $user);
            $msg = 'Company payment ' . $res['reference'] . ' of ' . rh_billing_money($res['amount']) . ' recorded on ' . count($res['legs']) . ' booking(s).';
            if (!empty($_POST['email_advice'])) {
                $adv = rh_billing_send_advice($pdo, (int)$res['remittance_id']);
                $msg .= ' Payment advice: ' . $adv['message'];
            }
            $_SESSION['ba_message'] = $msg;
        } elseif ($action === 'send_statement') {
            $id = (int)($_POST['account_id'] ?? 0);
            $back = 'billing-accounts.php?id=' . $id;
            $r = rh_billing_send_statement($pdo, $id);
            rh_log_event('payments', $r['success'] ? 'info' : 'warning', 'Company statement emailed', ['account_id' => $id, 'result' => $r['message'], 'by' => $user['username'] ?? null]);
            $_SESSION[$r['success'] ? 'ba_message' : 'ba_error'] = 'Statement: ' . $r['message'];
        } elseif ($action === 'send_advice') {
            $id = (int)($_POST['account_id'] ?? 0);
            $back = 'billing-accounts.php?id=' . $id;
            $r = rh_billing_send_advice($pdo, (int)($_POST['remittance_id'] ?? 0));
            rh_log_event('payments', $r['success'] ? 'info' : 'warning', 'Company payment advice emailed', ['account_id' => $id, 'remittance_id' => (int)($_POST['remittance_id'] ?? 0), 'result' => $r['message'], 'by' => $user['username'] ?? null]);
            $_SESSION[$r['success'] ? 'ba_message' : 'ba_error'] = 'Payment advice: ' . $r['message'];
        }
    } catch (Throwable $ex) {
        $_SESSION['ba_error'] = $ex->getMessage();
    }
    header('Location: ' . $back);
    exit;
}

$accounts = $ready ? rh_billing_accounts($pdo) : [];
$acc = $ready && $viewId > 0 ? rh_billing_account($pdo, $viewId) : null;
// One load of open bookings (batch split): the list view loads every company's at once, the company view only its own.
$totals = [];
$sumOwed = 0.0;
$sumOverdue = 0.0;
$receivedMonth = 0.0;
$open = $acc ? rh_billing_open_bookings($pdo, (int)$acc['id']) : [];
if ($ready && !$acc) {
    $openAll = rh_billing_open_bookings_all($pdo);
    foreach ($accounts as $a) {
        $t = rh_billing_totals_from_rows($openAll[(int)$a['id']] ?? []);
        $totals[(int)$a['id']] = $t;
        $sumOwed += $t['owed'];
        $sumOverdue += $t['overdue'];
    }
    // Company money received this month, net of refunds of those payments (refunds dated this month).
    $receivedMonth = rh_billing_received_net($pdo, date('Y-m-01'));
}
$rems = [];
if ($acc) {
    $rs = $pdo->prepare('SELECT r.*, (SELECT COUNT(*) FROM billing_remittance_lines l WHERE l.remittance_id = r.id) AS line_count
        FROM billing_remittances r WHERE r.billing_account_id = ? ORDER BY r.received_date DESC, r.id DESC LIMIT 200');
    $rs->execute([(int)$acc['id']]);
    $rems = $rs->fetchAll(PDO::FETCH_ASSOC);
}
$money = static fn(float $v): string => $cur . ' ' . number_format($v, 2);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Company Accounts — Admin</title>
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <?php
    $hasEmail = $acc && trim((string)$acc['billing_email']) !== '';
    // "?" column / figure helper (shared .help dot; on phones admin-mobile.js lists them under "What the columns mean").
    $help = static fn(string $tip): string => ' <i class="help" data-tip="' . htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') . '">?</i>';
    ?>
    <div class="admin-container finance-page billing-accounts-page">
        <header class="rh-page-head">
            <div class="rh-page-head__main">
                <div class="rh-page-head__title">
                    <h1><?php echo $acc ? $e($acc['name']) : 'Company Accounts'; ?></h1>
                    <?php if ($acc && (int)$acc['active'] !== 1): ?><span class="badge badge-inactive">Inactive</span><?php endif; ?>
                </div>
                <p class="rh-page-head__meta"><?php echo $acc
                    ? 'Company account: its open bookings, statement and the payments it sent.'
                    : 'Companies that pay later for their guests (on account / LPO). The room stay is billed to the company; the guest is never chased for it.'; ?></p>
            </div>
            <?php if ($ready): ?>
            <div class="rh-page-head__actions">
                <?php if ($acc): ?>
                    <a class="btn btn-secondary" href="billing-accounts.php"><i class="fas fa-arrow-left"></i> All companies</a>
                    <a class="btn btn-secondary" href="billing-accounts.php?statement=<?php echo (int)$acc['id']; ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Statement (PDF)</a>
                    <form method="POST" data-admin-confirm="<?php echo $e('Email the statement to ' . ($hasEmail ? rh_billing_recipients_text((string)$acc['billing_email'], rh_billing_email_list((string)$acc['billing_cc'])) : 'the company (no billing email: it will not be sent)') . '?'); ?>" data-admin-confirm-title="Email statement">
                        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf_token); ?>"><input type="hidden" name="action" value="send_statement"><input type="hidden" name="account_id" value="<?php echo (int)$acc['id']; ?>">
                        <button type="submit" class="btn btn-primary"<?php echo $hasEmail ? '' : ' disabled title="Add a billing email first"'; ?>><i class="fas fa-envelope"></i> Email statement</button>
                    </form>
                <?php else: ?>
                    <a class="btn btn-primary" href="#ba-company-form"><i class="fas fa-plus"></i> Add a company</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </header>

        <?php if ($message !== ''): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $e($message); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $e($error); ?></div><?php endif; ?>

        <?php if (!$ready): ?>
            <div class="alert alert-warning"><i class="fas fa-circle-info"></i> Company accounts are not available yet: database update 084 has not been run.</div>
        <?php else: ?>

        <?php if (!$acc): ?>
            <div class="ck-kpis ck-kpis--4">
                <div class="ck-kpi"><span class="ck-kpi__label">Companies</span><span class="ck-kpi__value"><?php echo count($accounts); ?></span>
                    <span class="ck-kpi__sub">Every company on file, active or not</span></div>
                <div class="ck-kpi ck-kpi--warn"><span class="ck-kpi__label">Owed by companies</span><span class="ck-kpi__value ck-kpi__value--money"><?php echo $e($money($sumOwed)); ?></span>
                    <span class="ck-kpi__sub">What companies still owe for their guests' room stays (and extras when they cover them), after their payments. Part of Owed to us.</span></div>
                <div class="ck-kpi ck-kpi--bad"><span class="ck-kpi__label">Overdue</span><span class="ck-kpi__value ck-kpi__value--money"><?php echo $e($money($sumOverdue)); ?></span>
                    <span class="ck-kpi__sub">Of that, the bookings past their due date (check-out + the company's terms, or the date set on the booking)</span></div>
                <div class="ck-kpi ck-kpi--good"><span class="ck-kpi__label">Received this month</span><span class="ck-kpi__value ck-kpi__value--money"><?php echo $e($money($receivedMonth)); ?></span>
                    <span class="ck-kpi__sub">Company payments recorded with a received date from the 1st of this month</span></div>
            </div>

            <section class="rh-panel rh-panel--flush ba-panel" id="ba-companies">
                <div class="rh-panel__head"><h2 class="rh-panel__title">Companies</h2></div>
                <div class="rh-panel__body">
                <table class="table">
                    <thead><tr>
                        <th>Company<?php echo $help('The company that pays later for its guests'); ?></th>
                        <th>Billing email<?php echo $help('Where invoices, statements and payment advice are emailed (plus the copy list)'); ?></th>
                        <th>Terms<?php echo $help('Days after check-out the company has to pay, unless a due date is set on the booking'); ?></th>
                        <th class="text-right">Open bookings<?php echo $help('Bookings the company still owes money on'); ?></th>
                        <th class="text-right">Owed<?php echo $help('What the company still owes in total: room stays (and extras when it covers them), less its payments'); ?></th>
                        <th class="text-right">Overdue<?php echo $help('Of that, the part past its due date'); ?></th>
                        <th>Oldest late<?php echo $help('Days the oldest unpaid booking is past its due date'); ?></th>
                        <th class="text-right">Actions<?php echo $help('Open the company: its bookings, statement and payments'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$accounts): ?>
                        <tr><td colspan="8" class="rh-empty">No companies yet. Add the first one below.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($accounts as $a): $t = $totals[(int)$a['id']]; ?>
                        <tr>
                            <td><strong><?php echo $e($a['name']); ?></strong><?php echo (int)$a['active'] === 1 ? '' : ' <span class="badge badge-inactive">Inactive</span>'; ?></td>
                            <td><?php echo trim((string)$a['billing_email']) !== '' ? $e($a['billing_email']) : '<span class="badge badge-danger">No billing email</span>'; ?><?php echo trim((string)$a['billing_cc']) !== '' ? '<br><small class="ba-cc">Copy: ' . $e($a['billing_cc']) . '</small>' : ''; ?></td>
                            <td><?php echo (int)$a['terms_days']; ?> days</td>
                            <td class="text-right"><?php echo (int)$t['count']; ?></td>
                            <td class="text-right"><?php echo $e($money($t['owed'])); ?></td>
                            <td class="text-right"><?php echo $t['overdue'] > BALANCE_TOLERANCE ? '<strong class="rh-text-danger">' . $e($money($t['overdue'])) . '</strong>' : '-'; ?></td>
                            <td><?php echo $t['oldest_days'] > 0 ? '<span class="badge badge-overdue">' . (int)$t['oldest_days'] . ' days</span>' : '-'; ?></td>
                            <td class="text-right"><a class="btn btn-sm btn-primary" href="billing-accounts.php?id=<?php echo (int)$a['id']; ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($acc): $t = rh_billing_totals_from_rows($open); ?>
            <div class="ck-kpis ck-kpis--4">
                <div class="ck-kpi ck-kpi--warn"><span class="ck-kpi__label">Company owes</span><span class="ck-kpi__value ck-kpi__value--money"><?php echo $e($money($t['owed'])); ?></span>
                    <span class="ck-kpi__sub">On <?php echo (int)$t['count']; ?> booking(s): room stays (and extras when it covers them), after every payment the company sent</span></div>
                <div class="ck-kpi ck-kpi--bad"><span class="ck-kpi__label">Overdue</span><span class="ck-kpi__value ck-kpi__value--money"><?php echo $e($money($t['overdue'])); ?></span>
                    <span class="ck-kpi__sub">Bookings past their due date<?php echo $t['oldest_days'] > 0 ? '; oldest ' . (int)$t['oldest_days'] . ' days late' : ''; ?></span></div>
                <div class="ck-kpi"><span class="ck-kpi__label">Billing email</span><span class="ck-kpi__value ba-kpi-text"><?php echo $hasEmail ? $e($acc['billing_email']) : 'None yet'; ?></span>
                    <span class="ck-kpi__sub">Invoices, statements and payment advice go here<?php echo trim((string)$acc['billing_cc']) !== '' ? ' (copy: ' . $e($acc['billing_cc']) . ')' : ''; ?></span></div>
                <div class="ck-kpi"><span class="ck-kpi__label">Terms</span><span class="ck-kpi__value"><?php echo (int)$acc['terms_days']; ?> days</span>
                    <span class="ck-kpi__sub">Due this many days after check-out, unless a booking has its own due date</span></div>
            </div>

            <section class="rh-panel rh-panel--flush ba-panel" id="ba-open">
                <div class="rh-panel__head"><h2 class="rh-panel__title">Bookings the company owes on</h2></div>
                <div class="rh-panel__body">
                <table class="table">
                    <thead><tr>
                        <th>Booking<?php echo $help('The booking, the guest who stayed and the booking status'); ?></th>
                        <th>Stay<?php echo $help('Check-in to check-out'); ?></th>
                        <th>LPO<?php echo $help("The company's order number for this stay"); ?></th>
                        <th>Invoice<?php echo $help('The final invoice sent to the company at checkout'); ?></th>
                        <th>Due<?php echo $help("Check-out + the company's terms, or the date set on the booking"); ?></th>
                        <th class="text-right">Company owes<?php echo $help('What the company still owes on this booking (room stay, plus extras when it covers them), after its payments'); ?></th>
                        <th class="text-right">Guest owes<?php echo $help('Extras the guest pays themselves, still unpaid'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$open): ?><tr><td colspan="7" class="rh-empty">Nothing owed. Bookings appear here once they are on this company's account (Booking Details &gt; Who pays).</td></tr><?php endif; ?>
                    <?php foreach ($open as $r): $b = $r['booking']; $s = $r['split']; ?>
                        <tr>
                            <td><a href="booking-details.php?id=<?php echo (int)$b['id']; ?>"><strong><?php echo $e($b['booking_reference']); ?></strong></a><br><small><?php echo $e($b['guest_name']); ?></small>
                                <br><span class="badge badge-<?php echo $e($b['status']); ?>"><?php echo $e(ucfirst(str_replace('-', ' ', (string)$b['status']))); ?></span></td>
                            <td><?php echo $e(date('j M', strtotime((string)$b['check_in_date'])) . ' - ' . date('j M Y', strtotime((string)$b['check_out_date']))); ?></td>
                            <td><?php echo $s['reference'] !== '' ? $e($s['reference']) : '-'; ?></td>
                            <td><?php echo trim((string)($b['final_invoice_number'] ?? '')) !== '' ? $e($b['final_invoice_number']) : '<small>At checkout</small>'; ?></td>
                            <td><?php echo $s['due_date'] !== '' ? $e(date('j M Y', strtotime($s['due_date']))) . ($s['days_past_due'] > 0 ? '<br><span class="badge badge-overdue">' . (int)$s['days_past_due'] . ' days late</span>' : '') : '-'; ?></td>
                            <td class="text-right"><strong><?php echo $e($money((float)$s['company_due'])); ?></strong></td>
                            <td class="text-right"><?php echo $s['guest_due'] > BALANCE_TOLERANCE ? $e($money((float)$s['guest_due'])) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </section>

            <?php if ($canPay && $open): ?>
            <section class="rh-panel ba-panel" id="ba-pay">
                <div class="rh-panel__head"><h2 class="rh-panel__title">Record company payment</h2></div>
                <div class="rh-panel__body">
                <form method="POST" id="ba-pay-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $e($csrf_token); ?>"><input type="hidden" name="action" value="record_payment"><input type="hidden" name="account_id" value="<?php echo (int)$acc['id']; ?>">
                    <p class="rh-form-hint">Enter what the company paid, then split it across its bookings. The lines must add up to the amount exactly; an amount that is not for these bookings cannot be recorded here. Nothing is saved until you review and confirm.</p>
                    <div id="ba-step1" class="rh-form-grid">
                        <div class="form-group"><label for="ba-amount">Amount received (<?php echo $e($cur); ?>)</label><input type="text" inputmode="decimal" name="amount" id="ba-amount" class="form-control" required></div>
                        <div class="form-group"><label for="ba-method">How it was paid</label><select name="payment_method" id="ba-method" class="form-control">
                            <?php foreach (rh_billing_methods() as $k => $lbl): ?><option value="<?php echo $e($k); ?>"><?php echo $e($lbl); ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="form-group"><label for="ba-date">Date received</label><input type="date" name="received_date" id="ba-date" class="form-control" value="<?php echo $e(date('Y-m-d')); ?>" max="<?php echo $e(date('Y-m-d')); ?>"></div>
                        <div class="form-group"><label for="ba-bankref">Bank / cheque reference</label><input type="text" name="bank_reference" id="ba-bankref" maxlength="80" class="form-control"></div>
                        <div class="form-group rh-form-grid__full"><label for="ba-notes">Notes</label><input type="text" name="notes" id="ba-notes" maxlength="255" class="form-control"></div>
                    </div>
                    <div class="table-responsive ba-grid-wrap" data-disable-auto-pagination>
                        <table class="table" id="ba-grid">
                            <thead><tr>
                                <th>Booking<?php echo $help('The booking the money is for, and its guest'); ?></th>
                                <th>LPO<?php echo $help('Its LPO / order number'); ?></th>
                                <th class="text-right">Company owes<?php echo $help('What the company owes on this booking now'); ?></th>
                                <th class="text-right">Apply<?php echo $help('How much of this payment goes to this booking (at most what the company owes)'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($open as $r): $b = $r['booking']; $s = $r['split']; ?>
                                <tr>
                                    <td><strong><?php echo $e($b['booking_reference']); ?></strong><br><small><?php echo $e($b['guest_name']); ?></small></td>
                                    <td><?php echo $s['reference'] !== '' ? $e($s['reference']) : '-'; ?></td>
                                    <td class="text-right"><?php echo $e($money((float)$s['company_due'])); ?></td>
                                    <td class="text-right"><input type="text" inputmode="decimal" class="form-control ba-line" name="line[<?php echo (int)$b['id']; ?>]" data-max="<?php echo $e(number_format((float)$s['company_due'], 2, '.', '')); ?>" data-ref="<?php echo $e($b['booking_reference']); ?>" value="" placeholder="0.00" aria-label="<?php echo $e('Amount for ' . $b['booking_reference']); ?>"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p id="ba-sum" class="ba-sum" aria-live="polite"></p>
                    <div id="ba-review" class="rh-review-card" hidden></div>
                    <label class="rh-check"><input type="checkbox" name="email_advice" value="1"<?php echo $hasEmail ? ' checked' : ' disabled'; ?>> Email a payment advice to the company<?php echo $hasEmail ? '' : ' (no billing email)'; ?></label>
                    <p id="ba-error" role="alert" class="rh-text-danger ba-error" style="display:none;"></p>
                    <div class="rh-actions-row">
                        <button type="button" class="btn btn-secondary" id="ba-fill"><i class="fas fa-list-ol"></i> Fill oldest first</button>
                        <button type="button" class="btn btn-secondary" id="ba-back" style="display:none;"><i class="fas fa-arrow-left"></i> Back</button>
                        <button type="button" class="btn btn-primary" id="ba-review-btn"><i class="fas fa-magnifying-glass"></i> Review payment</button>
                        <button type="submit" class="btn btn-primary" id="ba-confirm" style="display:none;"><i class="fas fa-check"></i> Confirm and record</button>
                    </div>
                </form>
                </div>
            </section>
            <?php endif; ?>

            <section class="rh-panel rh-panel--flush ba-panel" id="ba-payments">
                <div class="rh-panel__head"><h2 class="rh-panel__title">Payments received from <?php echo $e($acc['name']); ?></h2></div>
                <div class="rh-panel__body">
                <table class="table">
                    <thead><tr>
                        <th>Reference<?php echo $help('Our reference for the company payment'); ?></th>
                        <th>Received<?php echo $help('The date the money was received'); ?></th>
                        <th>Method<?php echo $help('How the company paid, and its bank or cheque reference'); ?></th>
                        <th class="text-right">Bookings<?php echo $help('How many bookings the payment was split across'); ?></th>
                        <th class="text-right">Amount<?php echo $help('The whole amount the company paid'); ?></th>
                        <th class="text-right">Actions<?php echo $help('Email the payment advice (which bookings it paid) to the company again'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$rems): ?><tr><td colspan="6" class="rh-empty">No payments from this company yet.</td></tr><?php endif; ?>
                    <?php foreach ($rems as $rm): ?>
                        <tr>
                            <td><strong><?php echo $e($rm['remittance_reference']); ?></strong></td>
                            <td><?php echo $e(date('j M Y', strtotime((string)$rm['received_date']))); ?></td>
                            <td><?php echo $e(rh_billing_methods()[(string)$rm['payment_method']] ?? $rm['payment_method']); ?><?php echo trim((string)$rm['bank_reference']) !== '' ? '<br><small>' . $e($rm['bank_reference']) . '</small>' : ''; ?></td>
                            <td class="text-right"><?php echo (int)$rm['line_count']; ?></td>
                            <td class="text-right"><?php echo $e($money((float)$rm['amount'])); ?></td>
                            <td class="text-right"><form method="POST" class="ba-inline-form"><input type="hidden" name="csrf_token" value="<?php echo $e($csrf_token); ?>"><input type="hidden" name="action" value="send_advice"><input type="hidden" name="account_id" value="<?php echo (int)$acc['id']; ?>"><input type="hidden" name="remittance_id" value="<?php echo (int)$rm['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-secondary"<?php echo $hasEmail ? '' : ' disabled title="Add a billing email first"'; ?>><i class="fas fa-envelope"></i> Email advice</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </section>
        <?php endif; ?>

        <?php $ea = $acc ?: ['id' => 0, 'name' => '', 'contact_name' => '', 'billing_email' => '', 'billing_cc' => '', 'phone' => '', 'address' => '', 'tax_number' => '', 'terms_days' => 30, 'active' => 1, 'notes' => '']; ?>
        <section class="rh-panel ba-panel" id="ba-company-form">
            <div class="rh-panel__head"><h2 class="rh-panel__title"><?php echo $acc ? 'Edit company' : 'Add a company'; ?></h2></div>
            <div class="rh-panel__body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf_token); ?>"><input type="hidden" name="action" value="save_account"><input type="hidden" name="account_id" value="<?php echo (int)$ea['id']; ?>">
                <div class="rh-form-grid">
                    <div class="form-group"><label for="ba-f-name">Company name</label><input type="text" name="name" id="ba-f-name" maxlength="150" required class="form-control" value="<?php echo $e($ea['name']); ?>"></div>
                    <div class="form-group"><label for="ba-f-contact">Contact person</label><input type="text" name="contact_name" id="ba-f-contact" maxlength="150" class="form-control" value="<?php echo $e($ea['contact_name']); ?>"></div>
                    <div class="form-group"><label for="ba-f-email">Billing email</label><input type="email" name="billing_email" id="ba-f-email" maxlength="190" class="form-control" value="<?php echo $e($ea['billing_email']); ?>"></div>
                    <div class="form-group"><label for="ba-f-cc">Copy (CC), comma separated</label><input type="text" name="billing_cc" id="ba-f-cc" maxlength="255" class="form-control" value="<?php echo $e($ea['billing_cc']); ?>"></div>
                    <div class="form-group"><label for="ba-f-phone">Phone</label><input type="text" name="phone" id="ba-f-phone" maxlength="50" class="form-control" value="<?php echo $e($ea['phone']); ?>"></div>
                    <div class="form-group"><label for="ba-f-terms">Terms (days after check-out)</label><input type="number" name="terms_days" id="ba-f-terms" min="0" max="365" class="form-control" value="<?php echo (int)$ea['terms_days']; ?>"></div>
                    <div class="form-group"><label for="ba-f-tax">Tax number</label><input type="text" name="tax_number" id="ba-f-tax" maxlength="60" class="form-control" value="<?php echo $e($ea['tax_number']); ?>"></div>
                    <div class="form-group"><label for="ba-f-address">Address</label><input type="text" name="address" id="ba-f-address" maxlength="255" class="form-control" value="<?php echo $e($ea['address']); ?>"></div>
                    <div class="form-group rh-form-grid__full"><label for="ba-f-notes">Notes</label><input type="text" name="notes" id="ba-f-notes" maxlength="255" class="form-control" value="<?php echo $e($ea['notes']); ?>"></div>
                </div>
                <div class="rh-actions-row">
                    <label class="rh-check ba-active-check"><input type="checkbox" name="active" value="1"<?php echo (int)$ea['active'] === 1 ? ' checked' : ''; ?>> Active (can be chosen on bookings)</label>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> <?php echo $acc ? 'Save company' : 'Add company'; ?></button>
                </div>
            </form>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        // Record company payment: lines must add up to the amount exactly (owner D5); review step before saving.
        var form = document.getElementById('ba-pay-form');
        if (!form) return;
        var cur = <?php echo json_encode($cur); ?>;
        var num = function (v) { var n = parseFloat(String(v || '').replace(/[,\s]/g, '')); return isFinite(n) ? n : 0; };
        var fmt = function (n) { return cur + ' ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
        var lines = Array.prototype.slice.call(form.querySelectorAll('.ba-line'));
        var amountEl = document.getElementById('ba-amount');
        var sumEl = document.getElementById('ba-sum');
        var errEl = document.getElementById('ba-error');
        var review = document.getElementById('ba-review');
        var show = function (id, on) { var el = document.getElementById(id); if (el) el.style.display = on ? '' : 'none'; };
        var err = function (m) { errEl.textContent = m || ''; errEl.style.display = m ? '' : 'none'; };
        function totals() {
            var sum = 0; lines.forEach(function (l) { sum += num(l.value); });
            var amt = num(amountEl.value), left = Math.round((amt - sum) * 100) / 100;
            sumEl.textContent = 'Split so far: ' + fmt(sum) + ' of ' + fmt(amt) + (Math.abs(left) <= 0.01 ? ' - matches.' : (left > 0 ? ' - ' + fmt(left) + ' still to split.' : ' - ' + fmt(-left) + ' too much.'));
            return { sum: sum, amt: amt, left: left };
        }
        function step(n) {
            form.querySelectorAll('input, select').forEach(function (el) { if (el.name !== 'csrf_token' && el.type !== 'hidden') el.readOnly = (n === 2); });
            review.hidden = n !== 2;
            // Under review the figures are locked (a select cannot be read-only): Back unlocks them.
            ['ba-step1', 'ba-grid'].forEach(function (id) { var el = document.getElementById(id); if (el) { el.style.pointerEvents = n === 2 ? 'none' : ''; el.style.opacity = n === 2 ? '0.75' : ''; } });
            show('ba-review-btn', n === 1); show('ba-fill', n === 1); show('ba-back', n === 2); show('ba-confirm', n === 2);
        }
        lines.concat([amountEl]).forEach(function (el) { el.addEventListener('input', function () { err(''); totals(); }); });
        document.getElementById('ba-fill').addEventListener('click', function () {
            var left = num(amountEl.value);
            lines.forEach(function (l) { var take = Math.min(left, num(l.dataset.max)); l.value = take > 0 ? take.toFixed(2) : ''; left = Math.round((left - take) * 100) / 100; });
            totals();
            if (left > 0.01) err('The amount is ' + fmt(left) + ' more than the company owes on these bookings. Only what is owed can be recorded.');
        });
        document.getElementById('ba-review-btn').addEventListener('click', function () {
            var t = totals();
            if (t.amt <= 0.01) { err('Enter the amount the company paid.'); return; }
            var bad = lines.filter(function (l) { return num(l.value) - num(l.dataset.max) > 0.01 || num(l.value) < 0; });
            if (bad.length) { err(bad[0].dataset.ref + ': more than the company owes on it.'); return; }
            if (Math.abs(t.left) > 0.01) { err('The lines must add up to the amount received exactly.'); return; }
            review.textContent = '';
            var add = function (label, value) { var d = document.createElement('div'); var b = document.createElement('strong'); b.textContent = label + ' '; d.appendChild(b); d.appendChild(document.createTextNode(value)); review.appendChild(d); };
            add('Company payment:', fmt(t.amt) + ' by ' + document.getElementById('ba-method').selectedOptions[0].textContent + ', received ' + document.getElementById('ba-date').value);
            lines.forEach(function (l) { if (num(l.value) > 0) add(l.dataset.ref + ':', fmt(num(l.value)) + ' (company owed ' + fmt(num(l.dataset.max)) + ', then ' + fmt(Math.max(0, num(l.dataset.max) - num(l.value))) + ')'); });
            add('Each line:', 'a payment on that booking with its own receipt number, shown in its History.');
            err(''); step(2);
        });
        document.getElementById('ba-back').addEventListener('click', function () { step(1); });
        form.addEventListener('submit', function (ev) { if (review.hidden) { ev.preventDefault(); } });
        totals();
    })();
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
