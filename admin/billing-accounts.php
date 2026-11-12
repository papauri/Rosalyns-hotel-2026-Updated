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
$totals = [];
$sumOwed = 0.0;
$sumOverdue = 0.0;
foreach ($accounts as $a) {
    $t = rh_billing_account_totals($pdo, (int)$a['id']);
    $totals[(int)$a['id']] = $t;
    $sumOwed += $t['owed'];
    $sumOverdue += $t['overdue'];
}
$receivedMonth = 0.0;
if ($ready) {
    $rm = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM billing_remittances WHERE received_date >= ?');
    $rm->execute([date('Y-m-01')]);
    $receivedMonth = (float)$rm->fetchColumn();
}
$open = $acc ? rh_billing_open_bookings($pdo, (int)$acc['id']) : [];
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

    <div class="admin-container finance-page billing-accounts-page">
        <?php if ($message !== ''): ?><div class="alert alert--success"><i class="fas fa-check-circle"></i> <?php echo $e($message); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert alert--danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $e($error); ?></div><?php endif; ?>

        <div class="cn-page-header" style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;align-items:flex-end;">
            <div>
                <h2 class="section-title"><i class="fas fa-building"></i> Company Accounts</h2>
                <p style="margin:4px 0 0;color:#6b7280;">Companies that pay later for their guests (on account / LPO). The room stay is billed to the company; the guest is never chased for it.</p>
            </div>
            <?php if ($ready && $acc): ?><a class="btn btn--secondary" href="billing-accounts.php"><i class="fas fa-arrow-left"></i> All companies</a><?php endif; ?>
        </div>

        <?php if (!$ready): ?>
            <div class="alert alert--warning"><i class="fas fa-circle-info"></i> Company accounts are not available yet: database update 084 has not been run.</div>
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

            <div class="filter-section">
                <table class="admin-table">
                    <thead><tr>
                        <th title="The company that pays later for its guests">Company</th>
                        <th title="Where invoices, statements and payment advice are emailed (plus the copy list)">Billing email</th>
                        <th title="Days after check-out the company has to pay, unless a due date is set on the booking">Terms</th>
                        <th title="Bookings the company still owes money on" class="text-right">Open bookings</th>
                        <th title="What the company still owes in total" class="text-right">Owed</th>
                        <th title="Of that, the part past its due date" class="text-right">Overdue</th>
                        <th title="Days the oldest unpaid booking is past its due date">Oldest late</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$accounts): ?>
                        <tr><td colspan="8" style="text-align:center;color:#6b7280;">No companies yet. Add the first one below.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($accounts as $a): $t = $totals[(int)$a['id']]; ?>
                        <tr>
                            <td><strong><?php echo $e($a['name']); ?></strong><?php echo (int)$a['active'] === 1 ? '' : ' <small>(inactive)</small>'; ?></td>
                            <td><?php echo trim((string)$a['billing_email']) !== '' ? $e($a['billing_email']) : '<span style="color:#b91c1c;">none: documents cannot be sent</span>'; ?></td>
                            <td><?php echo (int)$a['terms_days']; ?> days</td>
                            <td class="text-right"><?php echo (int)$t['count']; ?></td>
                            <td class="text-right"><?php echo $e($money($t['owed'])); ?></td>
                            <td class="text-right"><?php echo $t['overdue'] > BALANCE_TOLERANCE ? '<strong style="color:#b91c1c;">' . $e($money($t['overdue'])) . '</strong>' : '-'; ?></td>
                            <td><?php echo $t['oldest_days'] > 0 ? (int)$t['oldest_days'] . ' days' : '-'; ?></td>
                            <td><a class="btn btn--sm btn--primary" href="billing-accounts.php?id=<?php echo (int)$a['id']; ?>" style="min-height:36px;">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($acc): $t = rh_billing_account_totals($pdo, (int)$acc['id']); ?>
            <div class="ck-kpis ck-kpis--4">
                <div class="ck-kpi ck-kpi--warn"><span class="ck-kpi__label"><?php echo $e($acc['name']); ?> owes</span><span class="ck-kpi__value ck-kpi__value--money"><?php echo $e($money($t['owed'])); ?></span>
                    <span class="ck-kpi__sub">On <?php echo (int)$t['count']; ?> booking(s), after every payment the company sent</span></div>
                <div class="ck-kpi ck-kpi--bad"><span class="ck-kpi__label">Overdue</span><span class="ck-kpi__value ck-kpi__value--money"><?php echo $e($money($t['overdue'])); ?></span>
                    <span class="ck-kpi__sub">Bookings past their due date<?php echo $t['oldest_days'] > 0 ? '; oldest ' . (int)$t['oldest_days'] . ' days late' : ''; ?></span></div>
                <div class="ck-kpi"><span class="ck-kpi__label">Billing email</span><span class="ck-kpi__value" style="font-size:1rem;"><?php echo trim((string)$acc['billing_email']) !== '' ? $e($acc['billing_email']) : 'None yet'; ?></span>
                    <span class="ck-kpi__sub">Invoices, statements and payment advice go here<?php echo trim((string)$acc['billing_cc']) !== '' ? ' (copy: ' . $e($acc['billing_cc']) . ')' : ''; ?></span></div>
                <div class="ck-kpi"><span class="ck-kpi__label">Terms</span><span class="ck-kpi__value"><?php echo (int)$acc['terms_days']; ?> days</span>
                    <span class="ck-kpi__sub">Due this many days after check-out, unless a booking has its own due date</span></div>
            </div>

            <div class="filter-section" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                <a class="btn btn--secondary" href="billing-accounts.php?statement=<?php echo (int)$acc['id']; ?>" target="_blank" rel="noopener" style="min-height:44px;"><i class="fas fa-file-pdf"></i> Statement (PDF)</a>
                <form method="POST" style="margin:0;" data-admin-confirm="<?php echo $e('Email the statement to ' . (trim((string)$acc['billing_email']) !== '' ? $acc['billing_email'] : 'the company (no billing email: it will not be sent)') . '?'); ?>" data-admin-confirm-title="Email statement">
                    <input type="hidden" name="csrf_token" value="<?php echo $e($csrf_token); ?>"><input type="hidden" name="action" value="send_statement"><input type="hidden" name="account_id" value="<?php echo (int)$acc['id']; ?>">
                    <button type="submit" class="btn btn--primary" style="min-height:44px;"<?php echo trim((string)$acc['billing_email']) === '' ? ' disabled title="Add a billing email first"' : ''; ?>><i class="fas fa-envelope"></i> Email statement</button>
                </form>
            </div>

            <h3 class="section-title" style="font-size:1.05rem;">Bookings the company owes on</h3>
            <div class="filter-section">
                <table class="admin-table">
                    <thead><tr>
                        <th title="The booking and the guest who stayed">Booking</th>
                        <th title="Check-in to check-out">Stay</th>
                        <th title="The company's order number for this stay">LPO</th>
                        <th title="The final invoice sent to the company at checkout">Invoice</th>
                        <th title="Check-out + the company's terms, or the date set on the booking">Due</th>
                        <th class="text-right" title="What the company still owes on this booking (room stay, plus extras when it covers them), after its payments">Company owes</th>
                        <th class="text-right" title="Extras the guest pays themselves, still unpaid">Guest owes</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$open): ?><tr><td colspan="7" style="text-align:center;color:#6b7280;">Nothing owed. Bookings appear here once they are on this company's account (Booking Details > Who pays).</td></tr><?php endif; ?>
                    <?php foreach ($open as $r): $b = $r['booking']; $s = $r['split']; ?>
                        <tr>
                            <td><a href="booking-details.php?id=<?php echo (int)$b['id']; ?>"><?php echo $e($b['booking_reference']); ?></a><br><small><?php echo $e($b['guest_name']); ?> &middot; <?php echo $e($b['status']); ?></small></td>
                            <td><?php echo $e(date('j M', strtotime((string)$b['check_in_date'])) . ' - ' . date('j M Y', strtotime((string)$b['check_out_date']))); ?></td>
                            <td><?php echo $s['reference'] !== '' ? $e($s['reference']) : '-'; ?></td>
                            <td><?php echo trim((string)($b['final_invoice_number'] ?? '')) !== '' ? $e($b['final_invoice_number']) : '<small>at checkout</small>'; ?></td>
                            <td><?php echo $s['due_date'] !== '' ? $e(date('j M Y', strtotime($s['due_date']))) . ($s['days_past_due'] > 0 ? '<br><small style="color:#b91c1c;">' . (int)$s['days_past_due'] . ' days late</small>' : '') : '-'; ?></td>
                            <td class="text-right"><strong><?php echo $e($money((float)$s['company_due'])); ?></strong></td>
                            <td class="text-right"><?php echo $s['guest_due'] > BALANCE_TOLERANCE ? $e($money((float)$s['guest_due'])) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($canPay && $open): ?>
            <h3 class="section-title" style="font-size:1.05rem;" id="ba-pay">Record company payment</h3>
            <form method="POST" id="ba-pay-form" class="filter-section" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf_token); ?>"><input type="hidden" name="action" value="record_payment"><input type="hidden" name="account_id" value="<?php echo (int)$acc['id']; ?>">
                <p style="margin:0 0 10px;color:#6b7280;">Enter what the company paid, then split it across its bookings. The lines must add up to the amount exactly; an amount that is not for these bookings cannot be recorded here. Nothing is saved until you review and confirm.</p>
                <div id="ba-step1" style="display:flex;flex-wrap:wrap;gap:12px;">
                    <label style="display:flex;flex-direction:column;gap:4px;">Amount received (<?php echo $e($cur); ?>)<input type="text" inputmode="decimal" name="amount" id="ba-amount" class="filter-input" required style="min-height:44px;"></label>
                    <label style="display:flex;flex-direction:column;gap:4px;">How it was paid<select name="payment_method" id="ba-method" class="filter-select" style="min-height:44px;">
                        <?php foreach (rh_billing_methods() as $k => $lbl): ?><option value="<?php echo $e($k); ?>"><?php echo $e($lbl); ?></option><?php endforeach; ?>
                    </select></label>
                    <label style="display:flex;flex-direction:column;gap:4px;">Date received<input type="date" name="received_date" id="ba-date" class="filter-input" value="<?php echo $e(date('Y-m-d')); ?>" max="<?php echo $e(date('Y-m-d')); ?>" style="min-height:44px;"></label>
                    <label style="display:flex;flex-direction:column;gap:4px;">Bank / cheque reference<input type="text" name="bank_reference" maxlength="80" class="filter-input" style="min-height:44px;"></label>
                    <label style="display:flex;flex-direction:column;gap:4px;flex:1 1 220px;">Notes<input type="text" name="notes" maxlength="255" class="filter-input" style="min-height:44px;"></label>
                </div>
                <div data-disable-auto-pagination style="margin-top:12px;overflow-x:auto;">
                    <table class="admin-table" id="ba-grid">
                        <thead><tr>
                            <th title="The booking the money is for">Booking</th>
                            <th title="Its LPO / order number">LPO</th>
                            <th class="text-right" title="What the company owes on this booking now">Company owes</th>
                            <th class="text-right" title="How much of this payment goes to this booking (at most what the company owes)">Apply</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($open as $r): $b = $r['booking']; $s = $r['split']; ?>
                            <tr>
                                <td><?php echo $e($b['booking_reference']); ?><br><small><?php echo $e($b['guest_name']); ?></small></td>
                                <td><?php echo $s['reference'] !== '' ? $e($s['reference']) : '-'; ?></td>
                                <td class="text-right"><?php echo $e(number_format((float)$s['company_due'], 2)); ?></td>
                                <td class="text-right"><input type="text" inputmode="decimal" class="filter-input ba-line" name="line[<?php echo (int)$b['id']; ?>]" data-max="<?php echo $e(number_format((float)$s['company_due'], 2, '.', '')); ?>" data-ref="<?php echo $e($b['booking_reference']); ?>" value="" style="max-width:140px;min-height:44px;text-align:right;" aria-label="<?php echo $e('Amount for ' . $b['booking_reference']); ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p id="ba-sum" aria-live="polite" style="margin:10px 0;font-weight:600;"></p>
                <div id="ba-review" hidden style="background:#f7f3ee;border-radius:6px;padding:10px 12px;margin:10px 0;font-size:13px;line-height:1.7;"></div>
                <label style="display:flex;align-items:center;gap:8px;min-height:44px;"><input type="checkbox" name="email_advice" value="1"<?php echo trim((string)$acc['billing_email']) !== '' ? ' checked' : ' disabled'; ?>> Email a payment advice to the company<?php echo trim((string)$acc['billing_email']) === '' ? ' (no billing email)' : ''; ?></label>
                <div style="display:flex;flex-wrap:wrap;gap:10px;">
                    <button type="button" class="btn btn--secondary" id="ba-fill" style="min-height:44px;">Fill oldest first</button>
                    <button type="button" class="btn btn--primary" id="ba-review-btn" style="min-height:44px;">Review payment</button>
                    <button type="button" class="btn btn--secondary" id="ba-back" style="display:none;min-height:44px;">Back</button>
                    <button type="submit" class="btn btn--success" id="ba-confirm" style="display:none;min-height:44px;"><i class="fas fa-check"></i> Confirm and record</button>
                </div>
                <p id="ba-error" role="alert" style="display:none;color:#b91c1c;margin:8px 0 0;"></p>
            </form>
            <?php endif; ?>

            <h3 class="section-title" style="font-size:1.05rem;">Payments received from <?php echo $e($acc['name']); ?></h3>
            <div class="filter-section">
                <table class="admin-table">
                    <thead><tr>
                        <th title="Our reference for the company payment">Reference</th>
                        <th title="The date the money was received">Received</th>
                        <th title="How the company paid, and its bank or cheque reference">Method</th>
                        <th class="text-right" title="How many bookings the payment was split across">Bookings</th>
                        <th class="text-right" title="The whole amount the company paid">Amount</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$rems): ?><tr><td colspan="6" style="text-align:center;color:#6b7280;">No payments from this company yet.</td></tr><?php endif; ?>
                    <?php foreach ($rems as $rm): ?>
                        <tr>
                            <td><?php echo $e($rm['remittance_reference']); ?></td>
                            <td><?php echo $e(date('j M Y', strtotime((string)$rm['received_date']))); ?></td>
                            <td><?php echo $e(rh_billing_methods()[(string)$rm['payment_method']] ?? $rm['payment_method']); ?><?php echo trim((string)$rm['bank_reference']) !== '' ? '<br><small>' . $e($rm['bank_reference']) . '</small>' : ''; ?></td>
                            <td class="text-right"><?php echo (int)$rm['line_count']; ?></td>
                            <td class="text-right"><?php echo $e($money((float)$rm['amount'])); ?></td>
                            <td><form method="POST" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo $e($csrf_token); ?>"><input type="hidden" name="action" value="send_advice"><input type="hidden" name="account_id" value="<?php echo (int)$acc['id']; ?>"><input type="hidden" name="remittance_id" value="<?php echo (int)$rm['id']; ?>">
                                <button type="submit" class="btn btn--sm btn--secondary" style="min-height:36px;"<?php echo trim((string)$acc['billing_email']) === '' ? ' disabled title="Add a billing email first"' : ''; ?>>Email advice</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php $ea = $acc ?: ['id' => 0, 'name' => '', 'contact_name' => '', 'billing_email' => '', 'billing_cc' => '', 'phone' => '', 'address' => '', 'tax_number' => '', 'terms_days' => 30, 'active' => 1, 'notes' => '']; ?>
        <h3 class="section-title" style="font-size:1.05rem;"><?php echo $acc ? 'Edit company' : 'Add a company'; ?></h3>
        <form method="POST" class="filter-section" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf_token); ?>"><input type="hidden" name="action" value="save_account"><input type="hidden" name="account_id" value="<?php echo (int)$ea['id']; ?>">
            <label style="display:flex;flex-direction:column;gap:4px;flex:1 1 220px;">Company name<input type="text" name="name" maxlength="150" required class="filter-input" value="<?php echo $e($ea['name']); ?>" style="min-height:44px;"></label>
            <label style="display:flex;flex-direction:column;gap:4px;flex:1 1 200px;">Contact person<input type="text" name="contact_name" maxlength="150" class="filter-input" value="<?php echo $e($ea['contact_name']); ?>" style="min-height:44px;"></label>
            <label style="display:flex;flex-direction:column;gap:4px;flex:1 1 220px;">Billing email<input type="email" name="billing_email" maxlength="190" class="filter-input" value="<?php echo $e($ea['billing_email']); ?>" style="min-height:44px;"></label>
            <label style="display:flex;flex-direction:column;gap:4px;flex:1 1 220px;">Copy (CC), comma separated<input type="text" name="billing_cc" maxlength="255" class="filter-input" value="<?php echo $e($ea['billing_cc']); ?>" style="min-height:44px;"></label>
            <label style="display:flex;flex-direction:column;gap:4px;">Phone<input type="text" name="phone" maxlength="50" class="filter-input" value="<?php echo $e($ea['phone']); ?>" style="min-height:44px;"></label>
            <label style="display:flex;flex-direction:column;gap:4px;">Terms (days after check-out)<input type="number" name="terms_days" min="0" max="365" class="filter-input" value="<?php echo (int)$ea['terms_days']; ?>" style="min-height:44px;"></label>
            <label style="display:flex;flex-direction:column;gap:4px;flex:1 1 220px;">Address<input type="text" name="address" maxlength="255" class="filter-input" value="<?php echo $e($ea['address']); ?>" style="min-height:44px;"></label>
            <label style="display:flex;flex-direction:column;gap:4px;">Tax number<input type="text" name="tax_number" maxlength="60" class="filter-input" value="<?php echo $e($ea['tax_number']); ?>" style="min-height:44px;"></label>
            <label style="display:flex;flex-direction:column;gap:4px;flex:1 1 220px;">Notes<input type="text" name="notes" maxlength="255" class="filter-input" value="<?php echo $e($ea['notes']); ?>" style="min-height:44px;"></label>
            <label style="display:flex;align-items:center;gap:8px;min-height:44px;"><input type="checkbox" name="active" value="1"<?php echo (int)$ea['active'] === 1 ? ' checked' : ''; ?>> Active (can be chosen on bookings)</label>
            <button type="submit" class="btn btn--primary" style="min-height:44px;"><i class="fas fa-floppy-disk"></i> <?php echo $acc ? 'Save company' : 'Add company'; ?></button>
        </form>
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
