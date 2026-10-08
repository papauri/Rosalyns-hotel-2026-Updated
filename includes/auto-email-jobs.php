<?php

/**
 * Automated email jobs run by includes/auto-scheduler.php (and scripts/auto-scheduler-run.php).
 *
 *  - overdue_payment_reminders  checked-out room balances + conference / event / gym accounts
 *                               past their due date; stages from automated_email_reminder_stages
 *                               (default 1,3,7 days overdue, max 3), invoice PDF attached.
 *  - quotation_expiry_reminder  sent quotations that expire within automated_email_quotation_days.
 *  - tentative_hold_reminder    one reminder per hold, tentative_reminder_hours before it lapses.
 *  - tentative_expired_notice   guest + hotel notice once a hold has lapsed (last 24 h).
 *  - nightly_backup             runs scripts/backup_database.php once a day after 01:00.
 *  - prearrival_reminders / poststay_review_requests / gym_membership_renewal
 *                               the former cron senders; they call the SAME library functions the
 *                               scripts/ wrappers call and keep their original settings and dedupe
 *                               tables (guest_communication_log, gym_reminder_log).
 *
 * Every job returns ['checked','sent','skipped','errors'[,'deferred']]. Sends are idempotent via
 * automated_email_log (UNIQUE job/account_type/account_id/stage, claimed BEFORE sending) and
 * re-check the live balance immediately before sending, so a payment made a second ago stops the email.
 * While automated_email_test_recipient is set, dedupe rows use a 'test:' stage prefix so test sends
 * never block the real ones.
 */

require_once __DIR__ . '/auto-scheduler.php';
require_once __DIR__ . '/../config/email.php';

if (!function_exists('rh_job_overdue_payment_reminders')) {

    /* ── helpers ─────────────────────────────────────────────────────── */

    function rh_auto_module_on(string $key): bool
    {
        if ($key === 'events') {
            return !function_exists('isEventsEnabled') || isEventsEnabled();
        }
        return !function_exists('moduleEnabled') || moduleEnabled($key);
    }

    /** @return int[] ascending, 1..3 distinct day thresholds. */
    function rh_auto_reminder_stages(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', rh_auto_setting('automated_email_reminder_stages')) as $p) {
            $n = (int)$p;
            if ($n >= 1 && $n <= 365) {
                $out[$n] = $n;
            }
        }
        $out = array_values($out);
        sort($out);
        $out = array_slice($out, 0, 3);
        return $out ?: [1, 3, 7];
    }

    function rh_auto_terms_days(): int
    {
        return max(0, min(120, (int)rh_auto_setting('automated_email_payment_terms_days')));
    }

    function rh_auto_lookback_days(): int
    {
        return max(1, min(365, (int)rh_auto_setting('automated_email_lookback_days')));
    }

    function rh_auto_site_base(): string
    {
        $base = trim((string)getSetting('site_url', ''));
        if ($base === '' && defined('BASE_URL')) {
            $base = (string)BASE_URL;
        }
        return rtrim($base, '/');
    }

    function rh_auto_money(float $v): string
    {
        return number_format($v, 2);
    }

    /** "How to pay" block (HTML) for the reminder templates. */
    function rh_auto_pay_instructions(string $reference, string $type): string
    {
        $e = static function ($v): string {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };
        $html = '<p style="margin:0 0 8px;"><strong>How to pay</strong> &mdash; please quote <strong>' . $e($reference) . '</strong> as your payment reference.</p>';
        $bank = [
            'Bank'         => trim((string)getSetting('bank_name', '')),
            'Account name' => trim((string)getSetting('bank_account_name', '')),
            'Account no.'  => trim((string)getSetting('bank_account_number', '')),
            'Branch'       => trim((string)getSetting('bank_branch', '')),
        ];
        $lines = [];
        foreach ($bank as $label => $val) {
            if ($val !== '') {
                $lines[] = $e($label) . ': <strong>' . $e($val) . '</strong>';
            }
        }
        if ($lines) {
            $html .= '<p style="margin:0 0 8px;">' . implode('<br>', $lines) . '</p>';
        }
        $html .= '<p style="margin:0 0 8px;">You can also pay at reception or by mobile money / card by arrangement';
        $phone = trim((string)getSetting('phone_main', ''));
        if ($phone !== '') {
            $html .= ' &mdash; call us on ' . $e($phone);
        }
        $html .= '.</p>';
        $base = rh_auto_site_base();
        if ($type === 'room' && $base !== '') {
            $html .= '<p style="margin:0;">View your booking online: <a href="' . $e($base . '/booking-lookup.php') . '" style="color:#524b3f;">' . $e($base . '/booking-lookup.php') . '</a></p>';
        }
        return $html;
    }

    /** Template variables for the reminder / quotation templates (shell vars come from buildBookingEmailVariables). */
    function rh_auto_template_vars(string $name, string $email, array $extra): array
    {
        return buildBookingEmailVariables(['guest_name' => $name, 'guest_email' => $email], null, $extra);
    }

    /* ── overdue account loading ─────────────────────────────────────── */

    /**
     * Load ONE account fresh from the DB with every eligibility rule applied
     * (status, balance above BALANCE_TOLERANCE, valid e-mail). Null = not chaseable right now.
     */
    function rh_auto_load_account(PDO $pdo, string $type, int $id, int $terms): ?array
    {
        $tol = BALANCE_TOLERANCE;
        if ($type === 'room') {
            $st = $pdo->prepare("SELECT b.*, r.name AS room_name, r.image_url FROM bookings b JOIN rooms r ON r.id = b.room_id
                WHERE b.id = ? AND b.status = 'checked-out' AND b.primary_booking_id IS NULL AND b.amount_due > ?");
            $st->execute([$id, $tol]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return null;
            }
            $due = !empty($r['checkout_completed_at']) ? substr((string)$r['checkout_completed_at'], 0, 10) : (string)$r['check_out_date'];
            $a = ['name' => $r['guest_name'], 'email' => $r['guest_email'], 'ref' => $r['booking_reference'], 'due' => $due,
                'invoice_no' => trim((string)($r['final_invoice_number'] ?? '')) !== '' ? (string)$r['final_invoice_number'] : 'STMT-' . $r['booking_reference']];
        } elseif ($type === 'conference') {
            $st = $pdo->prepare("SELECT ci.*, cr.name AS room_name FROM conference_inquiries ci LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
                WHERE ci.id = ? AND ci.status IN ('confirmed','completed') AND ci.amount_due > ?");
            $st->execute([$id, $tol]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return null;
            }
            $base = !empty($r['event_date']) ? (string)$r['event_date'] : substr((string)$r['created_at'], 0, 10);
            $a = ['name' => $r['contact_person'] ?: $r['company_name'], 'email' => $r['email'], 'ref' => $r['inquiry_reference'],
                'due' => date('Y-m-d', strtotime($base . ' +' . $terms . ' days')), 'invoice_no' => 'STMT-' . $r['inquiry_reference']];
        } elseif ($type === 'gym') {
            $st = $pdo->prepare("SELECT gi.* FROM gym_inquiries gi WHERE gi.id = ? AND gi.status IN ('confirmed','completed') AND gi.amount_due > ?");
            $st->execute([$id, $tol]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return null;
            }
            $a = ['name' => $r['name'], 'email' => $r['email'], 'ref' => $r['reference_number'],
                'due' => date('Y-m-d', strtotime(substr((string)$r['created_at'], 0, 10) . ' +' . $terms . ' days')), 'invoice_no' => 'STMT-' . $r['reference_number']];
        } elseif ($type === 'event') {
            $st = $pdo->prepare("SELECT ei.*, e.title AS event_title, e.event_date AS event_date FROM event_inquiries ei LEFT JOIN events e ON e.id = ei.event_id
                WHERE ei.id = ? AND ei.status IN ('confirmed','completed') AND ei.amount_due > ?");
            $st->execute([$id, $tol]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return null;
            }
            $base = !empty($r['event_date']) ? (string)$r['event_date'] : substr((string)$r['created_at'], 0, 10);
            $a = ['name' => $r['name'], 'email' => $r['email'], 'ref' => $r['reference_number'],
                'due' => date('Y-m-d', strtotime($base . ' +' . $terms . ' days')), 'invoice_no' => 'STMT-' . $r['reference_number']];
        } else {
            return null;
        }
        $email = trim((string)$a['email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $days = (int)floor((strtotime(date('Y-m-d')) - strtotime($a['due'])) / 86400);
        return $a + ['type' => $type, 'id' => $id, 'email' => $email, 'amount_due' => (float)$r['amount_due'], 'days_overdue' => $days, 'row' => $r];
    }

    /** Candidate ids per account type (cheap SQL pre-filter; each is re-loaded fresh before sending). */
    function rh_auto_overdue_candidates(PDO $pdo, int $minDays, int $lookback, int $terms): array
    {
        $tol = BALANCE_TOLERANCE;
        $out = [];
        $run = static function (string $sql, array $p) use ($pdo): array {
            $st = $pdo->prepare($sql);
            $st->execute($p);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        };
        if (rh_auto_module_on('bookings')) {
            $out['room'] = $run("SELECT b.id FROM bookings b WHERE b.status = 'checked-out' AND b.primary_booking_id IS NULL AND b.amount_due > ?
                AND b.guest_email <> '' AND COALESCE(DATE(b.checkout_completed_at), b.check_out_date) BETWEEN (CURDATE() - INTERVAL ? DAY) AND (CURDATE() - INTERVAL ? DAY)
                ORDER BY b.id LIMIT 200", [$tol, $lookback, $minDays]);
        }
        if (rh_auto_module_on('conference')) {
            $out['conference'] = $run("SELECT ci.id FROM conference_inquiries ci WHERE ci.status IN ('confirmed','completed') AND ci.amount_due > ? AND ci.email <> ''
                AND DATE_ADD(COALESCE(ci.event_date, DATE(ci.created_at)), INTERVAL ? DAY) BETWEEN (CURDATE() - INTERVAL ? DAY) AND (CURDATE() - INTERVAL ? DAY)
                ORDER BY ci.id LIMIT 200", [$tol, $terms, $lookback, $minDays]);
        }
        if (rh_auto_module_on('events')) {
            $out['event'] = $run("SELECT ei.id FROM event_inquiries ei LEFT JOIN events e ON e.id = ei.event_id WHERE ei.status IN ('confirmed','completed') AND ei.amount_due > ? AND ei.email <> ''
                AND DATE_ADD(COALESCE(e.event_date, DATE(ei.created_at)), INTERVAL ? DAY) BETWEEN (CURDATE() - INTERVAL ? DAY) AND (CURDATE() - INTERVAL ? DAY)
                ORDER BY ei.id LIMIT 200", [$tol, $terms, $lookback, $minDays]);
        }
        if (rh_auto_module_on('gym')) {
            $out['gym'] = $run("SELECT gi.id FROM gym_inquiries gi WHERE gi.status IN ('confirmed','completed') AND gi.amount_due > ? AND gi.email <> ''
                AND DATE_ADD(DATE(gi.created_at), INTERVAL ? DAY) BETWEEN (CURDATE() - INTERVAL ? DAY) AND (CURDATE() - INTERVAL ? DAY)
                ORDER BY gi.id LIMIT 200", [$tol, $terms, $lookback, $minDays]);
        }
        return $out;
    }

    /* ── invoice PDF (no new invoice number is ever consumed) ─────────── */

    function rh_auto_require_invoice(): void
    {
        require_once __DIR__ . '/../config/invoice.php';
    }

    /** @return array{0:string,1:string} [filename, pdf bytes] */
    function rh_auto_invoice_pdf(array $a): array
    {
        global $pdo;
        rh_auto_require_invoice();
        $site = (string)getSetting('site_name');
        $mail = (string)getSetting('email_from_email');
        $phone = (string)getSetting('phone_main');
        $addr = getSetting('address_line1') . ', ' . getSetting('address_line2') . ', ' . getSetting('address_country');
        $cur = (string)getSetting('currency_symbol');
        $no = (string)$a['invoice_no'];
        $row = $a['row'];
        if ($a['type'] === 'room') {
            if (function_exists('getBookingRoomLabel')) {
                $lbl = getBookingRoomLabel((int)$row['id']);
                if ($lbl !== '') {
                    $row['room_name'] = trim((string)$row['room_name'] . ' - ' . $lbl);
                }
            }
            $st = $pdo->prepare("SELECT b.id, b.booking_reference, b.total_amount, b.child_supplement_total, b.tourism_levy_amount, b.vat_amount, b.total_with_vat,
                    b.occupancy_type, b.number_of_nights, r.name AS room_name FROM bookings b JOIN rooms r ON b.room_id = r.id
                    WHERE (b.id = ? OR b.primary_booking_id = ?) ORDER BY b.id ASC");
            $st->execute([(int)$row['id'], (int)$row['id']]);
            $group = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($group) <= 1) {
                $group = [];
            } elseif (function_exists('getBookingRoomLabel')) {
                foreach ($group as &$g) {
                    $lbl = getBookingRoomLabel((int)$g['id']);
                    if ($lbl !== '') {
                        $g['room_name'] = trim((string)$g['room_name'] . ' - ' . $lbl);
                    }
                }
                unset($g);
            }
            $html = buildInvoiceHTML($row, $no, $site, $mail, $phone, $addr, $cur, $group);
        } elseif ($a['type'] === 'conference') {
            $html = buildConferenceInvoiceHTML($row, $no, $site, $mail, $phone, $addr, $cur);
        } elseif ($a['type'] === 'gym') {
            $html = buildGymInvoiceHTML($row, $no, $site, $mail, $phone, $addr, $cur);
        } else {
            $html = buildEventInvoiceHTML($row, $no, $site, $mail, $phone, $addr, $cur);
        }
        $file = preg_replace('/[^A-Za-z0-9_-]+/', '-', $no) . '.pdf';
        return [$file, bookingRenderPdfFromHtml($html, 'Invoice ' . $no)];
    }

    /* ── job: overdue payment reminders ──────────────────────────────── */

    function rh_job_overdue_payment_reminders(PDO $pdo, array $o = []): array
    {
        $res = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => []];
        $job = 'overdue_payment_reminders';
        $stages = rh_auto_reminder_stages();
        $terms = rh_auto_terms_days();
        $prefix = !empty($o['test_mode']) ? 'test:' : '';
        $cands = rh_auto_overdue_candidates($pdo, $stages[0], rh_auto_lookback_days(), $terms);

        foreach ($cands as $type => $ids) {
            foreach ($ids as $id) {
                if (!rh_scheduler_budget_ok()) {
                    $res['deferred'] = true;
                    break 2;
                }
                $res['checked']++;
                $a = rh_auto_load_account($pdo, $type, $id, $terms); // fresh balance re-check
                if (!$a) {
                    $res['skipped']++;
                    continue;
                }
                $due = [];
                foreach ($stages as $i => $threshold) {
                    if ($a['days_overdue'] >= $threshold) {
                        $due[] = $i + 1;
                    }
                }
                if (!$due) {
                    $res['skipped']++;
                    continue;
                }
                $s = max($due);
                for ($i = 1; $i < $s; $i++) {
                    rh_auto_log_skip($pdo, $job, $type, $id, $prefix . 'r' . $i, 'superseded by stage ' . $s);
                }
                $stage = $prefix . 'r' . $s;

                $vars = rh_auto_template_vars((string)$a['name'], $a['email'], [
                    'amount_due' => rh_auto_money($a['amount_due']),
                    'due_date' => date('F j, Y', strtotime($a['due'])),
                    'days_overdue' => (string)$a['days_overdue'],
                    'invoice_number' => $a['invoice_no'],
                    'account_reference' => $a['ref'],
                    'pay_instructions' => rh_auto_pay_instructions((string)$a['ref'], $type),
                ]);
                $tpl = renderBookingEmailTemplate('payment_reminder_' . min($s, 3), $vars);
                if (!$tpl) {
                    $res['skipped']++; // template switched off in the editor = stage switched off
                    continue;
                }
                if (!rh_auto_log_claim($pdo, $job, $type, $id, $stage, $a['email'], $tpl['subject'])) {
                    $res['skipped']++; // already sent / being sent
                    continue;
                }
                try {
                    $attach = [];
                    try {
                        [$file, $bytes] = rh_auto_invoice_pdf($a);
                        $attach[] = ['content' => $bytes, 'name' => $file, 'mime' => 'application/pdf'];
                    } catch (Throwable $e) {
                        error_log('auto-email invoice pdf ' . $type . '#' . $id . ': ' . $e->getMessage());
                    }
                    rh_scheduler_budget_spend();
                    $r = sendEmailWithAttachments($a['email'], (string)$a['name'], $tpl['subject'], $tpl['html_body'], $attach, (string)$tpl['text_body']);
                    if (!empty($r['success'])) {
                        rh_auto_log_finish($pdo, $job, $type, $id, $stage, 'sent');
                        $res['sent']++;
                    } else {
                        rh_auto_log_finish($pdo, $job, $type, $id, $stage, 'failed', (string)($r['message'] ?? 'unknown'));
                        $res['errors'][] = $a['ref'] . ': ' . (string)($r['message'] ?? 'send failed');
                    }
                } catch (Throwable $e) {
                    rh_auto_log_finish($pdo, $job, $type, $id, $stage, 'failed', $e->getMessage());
                    $res['errors'][] = $a['ref'] . ': ' . $e->getMessage();
                }
            }
        }
        return $res;
    }

    /* ── job: quotation expiry ───────────────────────────────────────── */

    function rh_auto_days_left_text(string $validUntil): string
    {
        $d = (int)floor((strtotime(substr($validUntil, 0, 10)) - strtotime(date('Y-m-d'))) / 86400);
        return $d <= 0 ? 'today' : ($d === 1 ? 'tomorrow' : 'in ' . $d . ' days');
    }

    function rh_job_quotation_expiry_reminder(PDO $pdo, array $o = []): array
    {
        $res = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => []];
        $job = 'quotation_expiry_reminder';
        $prefix = !empty($o['test_mode']) ? 'test:' : '';
        $days = max(1, min(30, (int)rh_auto_setting('automated_email_quotation_days')));

        $st = $pdo->prepare("SELECT q.id FROM quotations q WHERE q.status = 'sent' AND q.valid_until IS NOT NULL AND q.guest_email <> ''
            AND DATE(q.valid_until) BETWEEN CURDATE() AND (CURDATE() + INTERVAL ? DAY) ORDER BY q.valid_until LIMIT 100");
        $st->execute([$days]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $get = $pdo->prepare("SELECT * FROM quotations WHERE id = ? AND status = 'sent' AND valid_until IS NOT NULL AND DATE(valid_until) >= CURDATE()");
        foreach ($ids as $id) {
            if (!rh_scheduler_budget_ok()) {
                $res['deferred'] = true;
                break;
            }
            $res['checked']++;
            $get->execute([$id]);
            $q = $get->fetch(PDO::FETCH_ASSOC);
            $email = $q ? trim((string)$q['guest_email']) : '';
            if (!$q || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $res['skipped']++;
                continue;
            }
            if (($q['booking_type'] ?? 'room') === 'room' && !empty($q['booking_id'])) {
                $b = $pdo->prepare('SELECT status FROM bookings WHERE id = ?');
                $b->execute([(int)$q['booking_id']]);
                $bs = $b->fetchColumn();
                if ($bs !== false && !in_array((string)$bs, ['pending', 'tentative'], true)) {
                    $res['skipped']++; // already confirmed / cancelled / expired - nothing to chase
                    continue;
                }
            }
            $vars = rh_auto_template_vars((string)$q['guest_name'], $email, [
                'quote_reference' => $q['quote_reference'],
                'valid_until' => date('F j, Y', strtotime((string)$q['valid_until'])),
                'days_left' => rh_auto_days_left_text((string)$q['valid_until']),
                'quote_total' => rh_auto_money((float)$q['total_amount']),
            ]);
            $tpl = renderBookingEmailTemplate('quotation_expiry_reminder', $vars);
            if (!$tpl) {
                $res['skipped']++;
                continue;
            }
            $stage = $prefix . 'expiry:' . substr((string)$q['valid_until'], 0, 10);
            if (!rh_auto_log_claim($pdo, $job, 'quotation', $id, $stage, $email, $tpl['subject'])) {
                $res['skipped']++;
                continue;
            }
            try {
                rh_scheduler_budget_spend();
                $r = sendEmail($email, (string)$q['guest_name'], $tpl['subject'], $tpl['html_body'], (string)$tpl['text_body']);
                if (!empty($r['success'])) {
                    rh_auto_log_finish($pdo, $job, 'quotation', $id, $stage, 'sent');
                    $res['sent']++;
                } else {
                    rh_auto_log_finish($pdo, $job, 'quotation', $id, $stage, 'failed', (string)($r['message'] ?? 'unknown'));
                    $res['errors'][] = $q['quote_reference'] . ': ' . (string)($r['message'] ?? 'send failed');
                }
            } catch (Throwable $e) {
                rh_auto_log_finish($pdo, $job, 'quotation', $id, $stage, 'failed', $e->getMessage());
                $res['errors'][] = $q['quote_reference'] . ': ' . $e->getMessage();
            }
        }
        return $res;
    }

    /* ── jobs: tentative holds (replaces scripts/expire_tentative_bookings.php) ─ */

    function rh_auto_tentative_on(): bool
    {
        return (string)getSetting('tentative_bookings_enabled', '1') !== '0' && rh_auto_module_on('bookings');
    }

    /** Remind the guest once, tentative_reminder_hours before the hold lapses (same email as the manual button). */
    function rh_job_tentative_hold_reminder(PDO $pdo, array $o = []): array
    {
        $res = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => []];
        if (!rh_auto_tentative_on()) {
            return $res;
        }
        $job = 'tentative_hold_reminder';
        $testMode = !empty($o['test_mode']);
        $prefix = $testMode ? 'test:' : '';
        $hours = max(1, min(168, (int)getSetting('tentative_reminder_hours', 24)));

        // Skip holds made in the last hour: the guest has just had the confirmation email.
        $st = $pdo->prepare("SELECT b.id FROM bookings b WHERE b.is_tentative = 1 AND b.status = 'tentative' AND COALESCE(b.reminder_sent, 0) = 0
            AND b.guest_email <> '' AND b.tentative_expires_at > NOW() AND b.tentative_expires_at <= (NOW() + INTERVAL ? HOUR)
            AND b.created_at <= (NOW() - INTERVAL 1 HOUR) ORDER BY b.tentative_expires_at LIMIT 100");
        $st->execute([$hours]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $get = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id
            WHERE b.id = ? AND b.is_tentative = 1 AND b.status = 'tentative' AND COALESCE(b.reminder_sent, 0) = 0 AND b.tentative_expires_at > NOW()");
        foreach ($ids as $id) {
            if (!rh_scheduler_budget_ok()) {
                $res['deferred'] = true;
                break;
            }
            $res['checked']++;
            $get->execute([$id]);
            $b = $get->fetch(PDO::FETCH_ASSOC);
            $email = $b ? trim((string)$b['guest_email']) : '';
            if (!$b || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $res['skipped']++;
                continue;
            }
            $stage = $prefix . 'hold:' . substr((string)$b['tentative_expires_at'], 0, 16);
            if (!rh_auto_log_claim($pdo, $job, 'room', $id, $stage, $email, 'Tentative hold reminder ' . $b['booking_reference'])) {
                $res['skipped']++;
                continue;
            }
            try {
                rh_scheduler_budget_spend();
                $r = sendTentativeBookingReminderEmail($b);
                if (!empty($r['success'])) {
                    rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'sent');
                    if (!$testMode) {
                        $pdo->prepare('UPDATE bookings SET reminder_sent = 1, reminder_sent_at = NOW() WHERE id = ?')->execute([$id]);
                        logTentativeBookingAction($id, 'reminder_sent', ['source' => 'automatic reminder']);
                    }
                    $res['sent']++;
                } else {
                    rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'failed', (string)($r['message'] ?? 'unknown'));
                    $res['errors'][] = $b['booking_reference'] . ': ' . (string)($r['message'] ?? 'send failed');
                }
            } catch (Throwable $e) {
                rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'failed', $e->getMessage());
                $res['errors'][] = $b['booking_reference'] . ': ' . $e->getMessage();
            }
        }
        return $res;
    }

    /**
     * Tell the guest (and the hotel) once that a hold lapsed. config/database.php expires stale holds on
     * every page load, so this looks for holds that became 'expired' in the last 24 hours.
     */
    function rh_job_tentative_expired_notice(PDO $pdo, array $o = []): array
    {
        $res = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => []];
        if (!rh_auto_tentative_on()) {
            return $res;
        }
        $job = 'tentative_expired_notice';
        $prefix = !empty($o['test_mode']) ? 'test:' : '';

        $st = $pdo->prepare("SELECT b.id FROM bookings b WHERE b.status = 'expired' AND b.tentative_expires_at IS NOT NULL
            AND b.tentative_expires_at BETWEEN (NOW() - INTERVAL 24 HOUR) AND NOW() AND b.guest_email <> '' ORDER BY b.id LIMIT 100");
        $st->execute();
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $get = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ? AND b.status = 'expired'");
        foreach ($ids as $id) {
            if (!rh_scheduler_budget_ok()) {
                $res['deferred'] = true;
                break;
            }
            $res['checked']++;
            $get->execute([$id]);
            $b = $get->fetch(PDO::FETCH_ASSOC);
            $email = $b ? trim((string)$b['guest_email']) : '';
            if (!$b || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $res['skipped']++;
                continue;
            }
            $stage = $prefix . 'expired';
            if (!rh_auto_log_claim($pdo, $job, 'room', $id, $stage, $email, 'Tentative hold expired ' . $b['booking_reference'])) {
                $res['skipped']++;
                continue;
            }
            try {
                rh_scheduler_budget_spend();
                $r = sendTentativeBookingExpiredEmail($b);
                if (!empty($r['success'])) {
                    rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'sent');
                    $res['sent']++;
                    try {
                        sendAdminBookingExpiredNotification($b, 'tentative');
                    } catch (Throwable $e) {
                        error_log('auto-email tentative admin notice #' . $id . ': ' . $e->getMessage());
                    }
                } else {
                    rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'failed', (string)($r['message'] ?? 'unknown'));
                    $res['errors'][] = $b['booking_reference'] . ': ' . (string)($r['message'] ?? 'send failed');
                }
            } catch (Throwable $e) {
                rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'failed', $e->getMessage());
                $res['errors'][] = $b['booking_reference'] . ': ' . $e->getMessage();
            }
        }
        return $res;
    }

    /* ── jobs: unpaid pending lapse + confirmed-but-unpaid pay-by deadline ─ */

    /**
     * Guest + hotel notice once an unpaid 'pending' booking lapsed (config/database.php
     * _expireStalePendingBookings() sets status 'expired' on every page load). Pending lapses are the
     * expired bookings that were never tentative holds (no tentative_expires_at), expired in the last 24 h.
     */
    function rh_job_pending_expired_notice(PDO $pdo, array $o = []): array
    {
        $res = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => []];
        if (!rh_auto_module_on('bookings')) {
            return $res;
        }
        $job = 'pending_expired_notice';
        $prefix = !empty($o['test_mode']) ? 'test:' : '';

        $st = $pdo->prepare("SELECT b.id FROM bookings b WHERE b.status = 'expired' AND b.tentative_expires_at IS NULL AND COALESCE(b.is_tentative, 0) = 0
            AND b.expired_at >= (NOW() - INTERVAL 24 HOUR) AND b.guest_email <> '' ORDER BY b.id LIMIT 100");
        $st->execute();
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $get = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ? AND b.status = 'expired'");
        foreach ($ids as $id) {
            if (!rh_scheduler_budget_ok()) {
                $res['deferred'] = true;
                break;
            }
            $res['checked']++;
            $get->execute([$id]);
            $b = $get->fetch(PDO::FETCH_ASSOC);
            $email = $b ? trim((string)$b['guest_email']) : '';
            if (!$b || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $res['skipped']++;
                continue;
            }
            $stage = $prefix . 'expired';
            if (!rh_auto_log_claim($pdo, $job, 'room', $id, $stage, $email, 'Pending booking expired ' . $b['booking_reference'])) {
                $res['skipped']++;
                continue;
            }
            try {
                rh_scheduler_budget_spend();
                $r = sendPendingBookingExpiredEmail($b);
                if (!empty($r['success'])) {
                    rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'sent');
                    $res['sent']++;
                    try {
                        sendAdminBookingExpiredNotification($b, 'pending');
                    } catch (Throwable $e) {
                        error_log('auto-email pending admin notice #' . $id . ': ' . $e->getMessage());
                    }
                } else {
                    rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'failed', (string)($r['message'] ?? 'unknown'));
                    $res['errors'][] = $b['booking_reference'] . ': ' . (string)($r['message'] ?? 'send failed');
                }
            } catch (Throwable $e) {
                rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'failed', $e->getMessage());
                $res['errors'][] = $b['booking_reference'] . ': ' . $e->getMessage();
            }
        }
        return $res;
    }

    /** Candidate ids: confirmed, nothing paid, check-in in the future, confirmed at least $minAgeHours ago. */
    function rh_auto_unpaid_candidates(PDO $pdo, int $minAgeHours): array
    {
        $noPay = rh_booking_has_payment_sql('b');
        $st = $pdo->prepare("SELECT b.id FROM bookings b WHERE b.status = 'confirmed' AND b.check_in_date > CURDATE()
            AND GREATEST(b.created_at, COALESCE(b.converted_to_confirmed_at, b.created_at)) <= (NOW() - INTERVAL ? HOUR)
            AND NOT {$noPay} ORDER BY b.check_in_date, b.id LIMIT 100");
        $st->execute([$minAgeHours]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Remind the guest once that payment is due before the room is released. */
    function rh_job_unpaid_confirmed_reminder(PDO $pdo, array $o = []): array
    {
        $res = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => []];
        $release = rh_unpaid_release_hours();
        $lead = rh_unpaid_reminder_hours();
        if ($release <= 0 || $lead <= 0 || !rh_auto_module_on('bookings')) {
            return $res;
        }
        if (!function_exists('logBookingEvent')) {
            require_once __DIR__ . '/booking-timeline.php';
        }
        $job = 'unpaid_confirmed_reminder';
        $prefix = !empty($o['test_mode']) ? 'test:' : '';

        $ids = rh_auto_unpaid_candidates($pdo, max(1, $release - $lead));
        $get = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ? AND b.status = 'confirmed'");
        foreach ($ids as $id) {
            if (!rh_scheduler_budget_ok()) {
                $res['deferred'] = true;
                break;
            }
            $res['checked']++;
            $get->execute([$id]);
            $b = $get->fetch(PDO::FETCH_ASSOC);
            $email = $b ? trim((string)$b['guest_email']) : '';
            $deadline = $b ? rh_unpaid_confirmed_deadline($b) : null;
            if (!$b || $deadline === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $res['skipped']++;
                continue;
            }
            // The deadline the guest is told: never earlier than one reminder-lead from now.
            $told = date('Y-m-d H:i:s', max(strtotime($deadline), time() + $lead * 3600));
            $stage = $prefix . 'reminder';
            if (!rh_auto_log_claim($pdo, $job, 'room', $id, $stage, $email, 'Payment reminder ' . $b['booking_reference'])) {
                $res['skipped']++;
                continue;
            }
            try {
                rh_scheduler_budget_spend();
                $r = sendUnpaidConfirmedReminderEmail($b, date('F j, Y g:i A', strtotime($told)));
                if (!empty($r['success'])) {
                    rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'sent');
                    if ($prefix === '' && function_exists('logBookingEvent')) {
                        logBookingEvent($id, (string)$b['booking_reference'], 'Payment reminder sent', 'reminder',
                            'Confirmed booking has no payment; guest told to pay by ' . date('j M Y H:i', strtotime($told)) . ' or the room is released.',
                            null, null, 'system', null, 'Scheduler', ['pay_by' => $told]);
                    }
                    $res['sent']++;
                } else {
                    rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'failed', (string)($r['message'] ?? 'unknown'));
                    $res['errors'][] = $b['booking_reference'] . ': ' . (string)($r['message'] ?? 'send failed');
                }
            } catch (Throwable $e) {
                rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'failed', $e->getMessage());
                $res['errors'][] = $b['booking_reference'] . ': ' . $e->getMessage();
            }
        }
        return $res;
    }

    /**
     * Release a confirmed booking that still has no payment: re-check under a row lock, cancel it with nothing
     * retained (nothing was ever paid), free the room stock and any assigned unit, and write the reason to the
     * status timeline and cancellation_log. @return bool true when this call released it.
     */
    function rh_release_unpaid_confirmed_booking(PDO $pdo, int $id, int $hours): bool
    {
        $reason = 'Auto-released: no payment received within ' . $hours . ' hours of booking (pay-by deadline passed)';
        $noPay = rh_booking_has_payment_sql('b');
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare("SELECT b.* FROM bookings b WHERE b.id = ? FOR UPDATE");
            $lock->execute([$id]);
            $b = $lock->fetch(PDO::FETCH_ASSOC);
            $still = $pdo->prepare("SELECT 1 FROM bookings b WHERE b.id = ? AND b.status = 'confirmed' AND b.check_in_date > CURDATE() AND NOT {$noPay}");
            $still->execute([$id]);
            if (!$b || !$still->fetchColumn()) {
                $pdo->rollBack();
                return false;
            }
            $pdo->prepare("UPDATE bookings SET status = 'cancelled', is_tentative = 0, tentative_expires_at = NULL, cancellation_retained_amount = 0, updated_at = NOW() WHERE id = ? AND status = 'confirmed'")
                ->execute([$id]);
            // Same stock/unit release cancelRoomBookingSettled() performs for a confirmed booking.
            $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")->execute([$b['room_id']]);
            updateBookingRoomsStatus($id, 'available', 'Booking auto-released (unpaid): ' . $b['booking_reference'], null);
            if (!recalculateBookingFinancials($id)) {
                throw new RuntimeException('could not recalculate balance');
            }
            if (function_exists('logBookingStatusChange')) {
                logBookingStatusChange($id, (string)$b['booking_reference'], 'confirmed', 'cancelled', 'system', null, 'Scheduler', $reason);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('unpaid release #' . $id . ': ' . $e->getMessage());
            return false;
        }
        logCancellationToDatabase($id, (string)$b['booking_reference'], 'room', (string)($b['guest_email'] ?? ''), 0, $reason, false, 'auto-release');
        return true;
    }

    /** Release confirmed bookings still unpaid once the pay-by deadline (and the reminder notice period) passed. */
    function rh_job_unpaid_confirmed_release(PDO $pdo, array $o = []): array
    {
        $res = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => []];
        $hours = rh_unpaid_release_hours();
        // Test runs never change booking state.
        if ($hours <= 0 || !empty($o['test_mode']) || !rh_auto_module_on('bookings')) {
            return $res;
        }
        if (!function_exists('logBookingEvent')) {
            require_once __DIR__ . '/booking-timeline.php';
        }
        $job = 'unpaid_confirmed_release';
        $lead = rh_unpaid_reminder_hours();

        $ids = rh_auto_unpaid_candidates($pdo, $hours);
        $get = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?");
        // The guest must have had the reminder (sent or given up after 3 tries) and a full notice period.
        $rem = $pdo->prepare("SELECT 1 FROM automated_email_log WHERE job = 'unpaid_confirmed_reminder' AND account_type = 'room' AND account_id = ? AND stage = 'reminder'
            AND ((status = 'sent' AND updated_at <= (NOW() - INTERVAL ? HOUR)) OR (status = 'failed' AND attempts >= 3))");
        foreach ($ids as $id) {
            $res['checked']++;
            $get->execute([$id]);
            $b = $get->fetch(PDO::FETCH_ASSOC);
            if (!$b) {
                $res['skipped']++;
                continue;
            }
            $email = trim((string)$b['guest_email']);
            $hasEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            if ($lead > 0 && $hasEmail) {
                $rem->execute([$id, max(1, $lead)]);
                if (!$rem->fetchColumn()) {
                    $res['skipped']++;
                    continue;
                }
            }
            if (!rh_release_unpaid_confirmed_booking($pdo, $id, $hours)) {
                $res['skipped']++;
                continue;
            }
            $res['sent']++;
            try {
                if ($hasEmail && rh_scheduler_budget_ok()) {
                    $stage = 'released';
                    if (rh_auto_log_claim($pdo, $job, 'room', $id, $stage, $email, 'Booking released ' . $b['booking_reference'])) {
                        rh_scheduler_budget_spend();
                        $r = sendUnpaidConfirmedReleasedEmail($b);
                        if (!empty($r['success'])) {
                            rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'sent');
                        } else {
                            rh_auto_log_finish($pdo, $job, 'room', $id, $stage, 'failed', (string)($r['message'] ?? 'unknown'));
                            $res['errors'][] = $b['booking_reference'] . ': ' . (string)($r['message'] ?? 'send failed');
                        }
                    }
                }
                sendAdminBookingExpiredNotification($b, 'unpaid_confirmed');
            } catch (Throwable $e) {
                $res['errors'][] = $b['booking_reference'] . ': ' . $e->getMessage();
            }
        }
        return $res;
    }

    /* ── migrated cron senders (same library functions the scripts/ wrappers call) ─ */

    function rh_auto_normalise_legacy(array $r): array
    {
        return ['checked' => (int)($r['checked'] ?? 0), 'sent' => (int)($r['sent'] ?? 0), 'skipped' => (int)($r['skipped'] ?? 0),
            'errors' => (array)($r['errors'] ?? []), 'disabled' => !empty($r['disabled'])];
    }

    function rh_job_prearrival_reminders(PDO $pdo, array $o = []): array
    {
        require_once __DIR__ . '/../admin/includes/guest-lifecycle-lib.php';
        $r = rh_auto_normalise_legacy(guest_run_prearrival_reminders($pdo));
        rh_auto_log_run_summary($pdo, 'prearrival_reminders', $r);
        return $r;
    }

    function rh_job_poststay_review_requests(PDO $pdo, array $o = []): array
    {
        require_once __DIR__ . '/../admin/includes/guest-lifecycle-lib.php';
        $r = rh_auto_normalise_legacy(guest_run_poststay_review_requests($pdo));
        rh_auto_log_run_summary($pdo, 'poststay_review_requests', $r);
        return $r;
    }

    function rh_job_gym_membership_renewal(PDO $pdo, array $o = []): array
    {
        require_once __DIR__ . '/../admin/includes/gym-reminders-lib.php';
        $raw = gym_run_expiry_reminders($pdo);
        $r = rh_auto_normalise_legacy($raw);
        if (!empty($raw['pending_migration'])) {
            $r['errors'][] = 'gym_reminder_log table missing';
        }
        rh_auto_log_run_summary($pdo, 'gym_membership_renewal', $r);
        return $r;
    }

    /* ── job: nightly database backup (replaces the backup crontab line) ─ */

    /** CLI php binary: under PHP-FPM / LiteSpeed, PHP_BINARY is the server binary, not the CLI. */
    function rh_auto_php_cli(): string
    {
        $bin = (string)PHP_BINARY;
        $base = strtolower(basename($bin));
        if (PHP_SAPI !== 'cli' && ($bin === '' || strpos($base, 'fpm') !== false || strpos($base, 'lsphp') !== false || strpos($base, 'cgi') !== false)) {
            foreach ([PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php'] as $cand) {
                if (@is_file($cand) && @is_executable($cand)) {
                    return $cand;
                }
            }
        }
        return $bin !== '' ? $bin : 'php';
    }

    /** Run a project PHP script in a child process. @return array{ok:bool,exit:int,output:string} */
    function rh_auto_run_php_script(string $script, array $args = []): array
    {
        $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
        $can = static function (string $f) use ($disabled): bool {
            return function_exists($f) && !in_array($f, $disabled, true);
        };
        if (!$can('escapeshellarg') || (!$can('proc_open') && !$can('exec'))) {
            return ['ok' => false, 'exit' => 127, 'output' => 'proc_open and exec are disabled on this server'];
        }
        $cmd = escapeshellarg(rh_auto_php_cli()) . ' ' . escapeshellarg($script);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg((string)$a);
        }
        if ($can('proc_open')) {
            $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($script, 2));
            if (is_resource($proc)) {
                $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = (int)proc_close($proc);
                return ['ok' => $exit === 0, 'exit' => $exit, 'output' => trim($out)];
            }
        }
        if ($can('exec')) {
            $lines = [];
            $exit = 1;
            @exec($cmd . ' 2>&1', $lines, $exit);
            return ['ok' => $exit === 0, 'exit' => (int)$exit, 'output' => trim(implode("\n", $lines))];
        }
        return ['ok' => false, 'exit' => 127, 'output' => 'proc_open and exec are disabled on this server'];
    }

    /**
     * One backup a day: at the first scheduler run after 01:00 once the last backup
     * (last_backup_at, written by includes/db-backup.php) is over 20 hours old.
     * The engine keeps its own lock, rotation (14 daily / 8 weekly / 12 monthly) and log.
     */
    function rh_job_nightly_backup(PDO $pdo, array $o = []): array
    {
        $res = ['checked' => 1, 'sent' => 0, 'skipped' => 0, 'errors' => []];
        // Only on the server: a local copy (Windows, or a localhost request) would dump the live
        // database onto a laptop and stamp last_backup_at, so the server would skip its own backup.
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (PHP_OS_FAMILY === 'Windows' || preg_match('/localhost|127\.0\.0\.1|\.local$/', $host)) {
            $res['skipped'] = 1;
            return $res;
        }
        $last = strtotime(rh_auto_setting_fresh($pdo, 'last_backup_at')) ?: 0;
        if ((time() - $last) < 20 * 3600 || (int)date('G') < 1) {
            $res['skipped'] = 1;
            return $res;
        }
        @set_time_limit(900);
        @ignore_user_abort(true);
        // In-process: no child process, so it works where shell functions are disabled.
        require_once __DIR__ . '/db-backup.php';
        $r = rh_db_backup_run($pdo, dirname(__DIR__));
        if ($r['ok']) {
            $res['sent'] = 1; // shown as "1 of 1" on Admin -> Automated Emails
        } else {
            $res['errors'][] = 'backup failed: ' . mb_substr((string)$r['error'], 0, 300);
            error_log('auto-scheduler nightly backup: ' . $r['error']);
        }
        rh_auto_log_run_summary($pdo, 'nightly_backup', $res);
        return $res;
    }

    /** Create the next occurrence of due recurring maintenance series. No email; 'sent' = schedules created. */
    function rh_job_recurring_maintenance(PDO $pdo, array $o = []): array
    {
        $res = ['checked' => 1, 'sent' => 0, 'skipped' => 0, 'errors' => []];
        require_once __DIR__ . '/maintenance-recurring.php';
        try {
            $res['sent'] = rh_create_recurring_maintenance($pdo, null);
        } catch (Throwable $e) {
            $res['errors'][] = $e->getMessage();
            error_log('auto-scheduler recurring maintenance: ' . $e->getMessage());
        }
        return $res;
    }

    /* ── sample sends ("Send test to me" on Automated Emails) ─ */

    /** @return array<string,string> template/job key => label */
    function rh_auto_sample_keys(): array
    {
        return [
            'payment_reminder_1' => 'Payment reminder 1 (friendly)',
            'payment_reminder_2' => 'Payment reminder 2 (firm)',
            'payment_reminder_3' => 'Payment reminder 3 (final)',
            'quotation_expiry_reminder' => 'Quotation expiry reminder',
            'tentative_booking_reminder' => 'Tentative hold reminder',
            'tentative_booking_expired' => 'Tentative hold expired',
            'prearrival_reminders' => 'Pre-arrival reminder',
            'poststay_review_requests' => 'Post-stay review request',
            'gym_membership_renewal' => 'Gym membership renewal',
        ];
    }

    /** SAMPLE-watermarked invoice PDF built from fixed sample data (reads/writes nothing). */
    function rh_auto_sample_invoice_pdf(string $to): array
    {
        rh_auto_require_invoice();
        $site = (string)getSetting('site_name');
        $addr = getSetting('address_line1') . ', ' . getSetting('address_line2') . ', ' . getSetting('address_country');
        $booking = [
            'id' => 0, 'booking_reference' => 'SAMPLE-1001', 'guest_name' => 'Sample Guest', 'guest_email' => $to, 'guest_phone' => '+265 999 000 000',
            'room_name' => 'Deluxe Lake View - Room 204', 'check_in_date' => date('Y-m-d', strtotime('-6 days')),
            'check_out_date' => date('Y-m-d', strtotime('-3 days')), 'number_of_nights' => 3, 'adult_guests' => 2, 'child_guests' => 0,
            'total_amount' => 480000, 'package_total' => 30000, 'child_supplement_total' => 0, 'tourism_levy_amount' => 12000,
            'tourism_levy_percent' => 2.5, 'rate_plan_discount' => 0, 'rate_plan_label' => '', 'primary_booking_id' => null,
        ];
        $grand = vat_components(480000.0)['total'] + 12000;
        $pay = [['payment_date' => date('Y-m-d', strtotime('-8 days')), 'payment_method' => 'mobile_money', 'payment_type' => 'deposit', 'total_amount' => 200000, 'payment_reference' => 'PAY-SAMPLE-1']];
        $preload = ['packages' => [], 'folio_charges' => [], 'payments' => $pay,
            'summary' => ['grand_total' => $grand, 'amount_paid' => 200000.0, 'balance_due' => $grand - 200000]];
        $html = buildInvoiceHTML($booking, 'STMT-SAMPLE-1001', $site, (string)getSetting('email_from_email'), (string)getSetting('phone_main'), $addr, (string)getSetting('currency_symbol'), [], $preload);
        return ['STMT-SAMPLE-1001.pdf', bookingRenderPdfFromHtml($html, 'Invoice STMT-SAMPLE-1001', ['watermark' => 'SAMPLE'])];
    }

    /**
     * Render + send one sample email with SAMPLE data to $to. Creates no DB records; the automated
     * send context forces the recipient to $to, so no real guest can ever receive it.
     *
     * @return array{success:bool,message:string}
     */
    function rh_auto_send_sample(string $key, string $to): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'No valid recipient address.'];
        }
        if (!isset(rh_auto_sample_keys()[$key])) {
            return ['success' => false, 'message' => 'Unknown template.'];
        }
        $GLOBALS['rh_automated_ctx'] = ['redirect' => $to, 'bcc' => false];
        try {
            if (strpos($key, 'payment_reminder_') === 0) {
                $n = (int)substr($key, -1);
                $pay = rh_auto_pay_instructions('SAMPLE-1001', 'room');
                $vars = rh_auto_template_vars('Sample Guest', $to, [
                    'amount_due' => rh_auto_money(292000), 'due_date' => date('F j, Y', strtotime('-' . [1 => 1, 2 => 3, 3 => 7][$n] . ' days')),
                    'days_overdue' => (string)[1 => 1, 2 => 3, 3 => 7][$n], 'invoice_number' => 'STMT-SAMPLE-1001',
                    'account_reference' => 'SAMPLE-1001', 'pay_instructions' => $pay,
                ]);
                $tpl = renderBookingEmailTemplate($key, $vars);
                if (!$tpl) {
                    return ['success' => false, 'message' => 'Template is switched off or missing.'];
                }
                [$file, $bytes] = rh_auto_sample_invoice_pdf($to);
                return sendEmailWithAttachments($to, 'Sample Guest', $tpl['subject'], $tpl['html_body'],
                    [['content' => $bytes, 'name' => $file, 'mime' => 'application/pdf']], (string)$tpl['text_body']);
            }
            if ($key === 'quotation_expiry_reminder') {
                $vars = rh_auto_template_vars('Sample Guest', $to, [
                    'quote_reference' => 'QT-SAMPLE-1001', 'valid_until' => date('F j, Y', strtotime('+2 days')),
                    'days_left' => 'in 2 days', 'quote_total' => rh_auto_money(480000),
                ]);
                $tpl = renderBookingEmailTemplate($key, $vars);
                if (!$tpl) {
                    return ['success' => false, 'message' => 'Template is switched off or missing.'];
                }
                return sendEmail($to, 'Sample Guest', $tpl['subject'], $tpl['html_body'], (string)$tpl['text_body']);
            }
            if ($key === 'tentative_booking_reminder' || $key === 'tentative_booking_expired') {
                $roomId = (int)$GLOBALS['pdo']->query('SELECT id FROM rooms ORDER BY id LIMIT 1')->fetchColumn();
                $b = ['id' => 0, 'booking_reference' => 'SAMPLE-1001', 'guest_name' => 'Sample Guest', 'guest_email' => $to, 'room_id' => $roomId,
                    'check_in_date' => date('Y-m-d', strtotime('+10 days')), 'check_out_date' => date('Y-m-d', strtotime('+13 days')),
                    'number_of_nights' => 3, 'total_amount' => 480000,
                    'tentative_expires_at' => date('Y-m-d H:i:s', strtotime($key === 'tentative_booking_reminder' ? '+20 hours' : '-1 hour'))];
                return $key === 'tentative_booking_reminder' ? sendTentativeBookingReminderEmail($b) : sendTentativeBookingExpiredEmail($b);
            }
            if ($key === 'prearrival_reminders') {
                return sendPreArrivalReminderEmail(['guest_email' => $to, 'guest_name' => 'Sample Guest', 'booking_reference' => 'SAMPLE-1001',
                    'room_id' => 0, 'check_in_date' => date('Y-m-d', strtotime('+1 day'))]);
            }
            if ($key === 'poststay_review_requests') {
                return sendPostStayReviewRequestEmail(['guest_email' => $to, 'guest_name' => 'Sample Guest', 'booking_reference' => 'SAMPLE-1001', 'room_id' => 0]);
            }
            return sendGymRenewalReminderEmail(['email' => $to, 'full_name' => 'Sample Member', 'membership_type' => 'Monthly - Full Access',
                'expiry_date' => date('Y-m-d', strtotime('+3 days')), 'monthly_fee' => 60000], 3);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            unset($GLOBALS['rh_automated_ctx']);
        }
    }
}
