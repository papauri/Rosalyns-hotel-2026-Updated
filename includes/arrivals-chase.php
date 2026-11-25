<?php
/**
 * Arrivals with a balance to chase (owner 2026-11-25: "FOH are notified to chase up payments"). ONE list feeds the
 * Dashboard "Needs attention" item, the Bookings filter (?chase=arrivals) and the daily staff email
 * (scheduler job 'arrivals_chase'), so all three always show the same bookings.
 *
 * Rules (the same as "Payment reminders before arrival", minus its 24-hour and stage rules):
 *  - a group (lead booking + its rooms) with a CONFIRMED room arriving after today and within the window, where the
 *    window = the LARGEST value of the "Payment reminders before arrival" days setting (prearrival_payment_reminder_days);
 *  - left out: any live room on a pay-later arrangement or a company account, any room already checked in;
 *  - money = the confirmed rooms' amount_paid / amount_due summed (the reminder's own rule); owing = above BALANCE_TOLERANCE;
 *  - the arrival = the earliest confirmed arrival; a group whose earliest confirmed room is due today or earlier is left
 *    out (it is on the arrivals / overdue arrivals lists already);
 *  - urgent = arriving within the SMALLEST value of that setting.
 * Scripts/tests/arrivals_chase.php checks it.
 */

require_once __DIR__ . '/auto-scheduler.php'; // rh_auto_setting (no database at include)

if (!function_exists('rh_auto_prearrival_days')) {
    /**
     * PURE. The "days before check-in" stages: whole numbers 1..365, each once, largest first, at most
     * 6 (the largest ones; more would read as spam). Nothing usable = [30, 15, 4]. $raw null = the saved setting.
     *
     * @return int[]
     */
    function rh_auto_prearrival_days(?string $raw = null): array
    {
        if ($raw === null) {
            $raw = rh_auto_setting('prearrival_payment_reminder_days');
        }
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string)$raw) ?: [] as $p) {
            $p = trim($p);
            if ($p === '' || !ctype_digit($p)) {
                continue;
            }
            $n = (int)$p;
            if ($n >= 1 && $n <= 365) {
                $out[$n] = $n;
            }
        }
        $out = array_values($out);
        rsort($out);
        $out = array_slice($out, 0, 6);
        return $out ?: [30, 15, 4];
    }
}

if (!function_exists('rh_arrivals_to_chase')) {

    /**
     * Groups (by lead id: primary_booking_id, else the booking itself) with a confirmed room arriving after today and
     * within $maxDays that still owes money, leaving out pay-later arrangements and company-billed bookings. The ONE
     * WHERE shared by the payment reminders before arrival (rh_auto_prearrival_candidates) and the chase list.
     *
     * @return int[]
     */
    function rh_prearrival_owing_roots(PDO $pdo, int $maxDays, int $limit = 200): array
    {
        $noArr = rh_checkin_arrangement_exclude_sql('b');
        $limit = max(1, min(5000, $limit));
        $st = $pdo->prepare("SELECT DISTINCT COALESCE(NULLIF(b.primary_booking_id, 0), b.id) AS root FROM bookings b
            WHERE b.status = 'confirmed' AND b.deleted_at IS NULL
              AND b.check_in_date > CURDATE() AND b.check_in_date <= (CURDATE() + INTERVAL ? DAY)
              AND b.amount_due > ?{$noArr}
            ORDER BY root LIMIT {$limit}");
        $st->execute([max(1, $maxDays), BALANCE_TOLERANCE]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * PURE. Window and urgent threshold from the "Payment reminders before arrival" days: window = the largest value,
     * urgent = the smallest. $days null = the saved setting.
     * @return array{days:int[], window:int, urgent:int}
     */
    function rh_arrivals_chase_limits(?array $days = null): array
    {
        $days = $days === null ? rh_auto_prearrival_days() : rh_auto_prearrival_days(implode(',', array_map('strval', $days)));
        return ['days' => $days, 'window' => (int)max($days), 'urgent' => (int)min($days)];
    }

    /**
     * Read-only. The arrivals to chase, urgent first, then by arrival.
     * Each row: lead_id (the booking the list and the email link to), root_id, ref, guest_name, phone, email, arrival
     * (Y-m-d), days_until, rooms (confirmed rooms), total, paid, balance, last_reminder (null or ['stage' => days,
     * 'at' => 'Y-m-d H:i:s'], the last "Payment reminder before arrival" sent), urgent (bool).
     * $days null = the saved setting; $today (midnight timestamp) for tests.
     * @return array{rows: array<int, array>, window:int, urgent_days:int, days:int[], urgent:int}
     */
    function rh_arrivals_to_chase(PDO $pdo, ?array $days = null, ?int $today = null): array
    {
        $lim = rh_arrivals_chase_limits($days);
        $out = ['rows' => [], 'window' => $lim['window'], 'urgent_days' => $lim['urgent'], 'days' => $lim['days'], 'urgent' => 0];
        $today = $today ?? strtotime(date('Y-m-d'));
        $tol = (float)BALANCE_TOLERANCE;

        $roots = rh_prearrival_owing_roots($pdo, $lim['window'], 2000);
        if (!$roots) {
            return $out;
        }
        $rootSet = array_flip($roots);
        $in = implode(',', array_fill(0, count($roots), '?'));
        $noArr = rh_checkin_arrangement_exclude_sql('b');
        // Every live room of those groups (the reminder's own set: not deleted, not cancelled / no-show / expired /
        // checked out), with whether it passes the pay-later / company rule.
        $st = $pdo->prepare("SELECT b.id, b.primary_booking_id, b.status, b.booking_reference, b.guest_name, b.guest_phone, b.guest_email,
                b.check_in_date, b.amount_paid, b.amount_due, (CASE WHEN 1 = 1{$noArr} THEN 1 ELSE 0 END) AS arr_ok
            FROM bookings b
            WHERE (b.id IN ({$in}) OR b.primary_booking_id IN ({$in})) AND b.deleted_at IS NULL
              AND b.status NOT IN ('cancelled', 'no-show', 'expired', 'checked-out')
            ORDER BY b.id");
        $st->execute(array_merge($roots, $roots));
        $groups = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            $g = isset($rootSet[$id]) ? $id : (int)$r['primary_booking_id'];
            if (isset($rootSet[$g])) {
                $groups[$g][] = $r;
            }
        }

        $last = rh_arrivals_chase_last_reminders($pdo, array_keys($groups));

        foreach ($groups as $root => $rows) {
            $confirmed = [];
            $skip = false;
            foreach ($rows as $r) {
                $s = strtolower((string)$r['status']);
                if ((int)$r['arr_ok'] !== 1 || $s === 'checked-in') {
                    $skip = true; // pay-later / company room, or a room already in house: the whole group is left out
                    break;
                }
                if ($s === 'confirmed') {
                    $confirmed[] = $r;
                }
            }
            if ($skip || !$confirmed) {
                continue;
            }
            $first = null;
            $paid = 0.0;
            $balance = 0.0;
            foreach ($confirmed as $r) {
                $ci = strtotime((string)$r['check_in_date']);
                if ($ci !== false && ($first === null || $ci < $first)) {
                    $first = $ci;
                }
                $paid += max(0.0, (float)($r['amount_paid'] ?? 0));
                $balance += max(0.0, (float)($r['amount_due'] ?? 0));
            }
            if ($balance <= $tol || $first === null) {
                continue;
            }
            $daysUntil = (int)round(($first - $today) / 86400);
            if ($daysUntil <= 0 || $daysUntil > $lim['window']) {
                continue; // due today or overdue: on the arrivals lists already
            }
            // The row the list shows and the email links to: the lead booking while it is live, else the earliest
            // confirmed room (lowest id on a tie).
            $lead = null;
            foreach ($rows as $r) {
                if ((int)$r['id'] === (int)$root) {
                    $lead = $r;
                }
            }
            if ($lead === null) {
                $byArrival = $confirmed;
                usort($byArrival, static fn($a, $b) => [(string)$a['check_in_date'], (int)$a['id']] <=> [(string)$b['check_in_date'], (int)$b['id']]);
                $lead = $byArrival[0];
            }
            $pick = static function (string $col) use ($lead, $confirmed): string {
                $v = trim((string)($lead[$col] ?? ''));
                if ($v !== '') {
                    return $v;
                }
                foreach ($confirmed as $r) {
                    $v = trim((string)($r[$col] ?? ''));
                    if ($v !== '') {
                        return $v;
                    }
                }
                return '';
            };
            $urgent = $daysUntil <= $lim['urgent'];
            $out['rows'][] = [
                'lead_id' => (int)$lead['id'],
                'root_id' => (int)$root,
                'ref' => (string)$lead['booking_reference'],
                'guest_name' => $pick('guest_name'),
                'phone' => $pick('guest_phone'),
                'email' => $pick('guest_email'),
                'arrival' => date('Y-m-d', $first),
                'days_until' => $daysUntil,
                'rooms' => count($confirmed),
                'total' => round($paid + $balance, 2),
                'paid' => round($paid, 2),
                'balance' => round($balance, 2),
                'last_reminder' => $last[(int)$root] ?? null,
                'urgent' => $urgent,
            ];
            if ($urgent) {
                $out['urgent']++;
            }
        }
        usort($out['rows'], static fn($a, $b) => [$a['urgent'] ? 0 : 1, $a['arrival'], $a['lead_id']] <=> [$b['urgent'] ? 0 : 1, $b['arrival'], $b['lead_id']]);
        return $out;
    }

    /**
     * The last "Payment reminder before arrival" sent per group lead (automated_email_log, real sends only, never test
     * sends). @return array<int, array{stage:int, at:string}>
     */
    function rh_arrivals_chase_last_reminders(PDO $pdo, array $roots): array
    {
        $roots = array_values(array_filter(array_map('intval', $roots), static fn($v) => $v > 0));
        if (!$roots || !rh_auto_log_table_ready($pdo)) {
            return [];
        }
        $out = [];
        try {
            $st = $pdo->prepare("SELECT account_id, stage, updated_at FROM automated_email_log
                WHERE job = 'prearrival_payment_reminders' AND account_type = 'room' AND status = 'sent' AND stage LIKE 'pre:%'
                  AND account_id IN (" . implode(',', array_fill(0, count($roots), '?')) . ")
                ORDER BY updated_at, id");
            $st->execute($roots);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['account_id']] = ['stage' => (int)substr((string)$r['stage'], 4), 'at' => (string)$r['updated_at']];
            }
        } catch (Throwable $e) {
            error_log('arrivals chase: last reminders: ' . $e->getMessage());
        }
        return $out;
    }

    /** Lead booking ids of the chase list (the Bookings ?chase=arrivals filter lists exactly these). @return int[] */
    function rh_arrivals_chase_ids(array $chase): array
    {
        return array_values(array_map(static fn($r) => (int)$r['lead_id'], (array)($chase['rows'] ?? [])));
    }

    /** Plain words for the rule, with the real numbers from the setting (Dashboard hint, email, help text). */
    function rh_arrivals_chase_rule_words(int $window, int $urgent): string
    {
        return 'Confirmed, arriving in the next ' . $window . ' day' . ($window === 1 ? '' : 's') . ', still owing; urgent (red) when arriving within '
            . $urgent . ' day' . ($urgent === 1 ? '' : 's') . '. Follows the "Payment reminders before arrival" days setting';
    }

    /* ── daily staff email (scheduler job 'arrivals_chase') ── */

    /**
     * PURE. Which hotel day the email is for now: today, once today's trading day has started (the same opening the
     * daily reports email waits for, rh_pos_last_ended_trading_date); null before that (the run waits). $windowFor for
     * tests (see rh_pos_last_ended_trading_date).
     */
    function rh_arrivals_chase_day(DateTimeImmutable $now, ?callable $windowFor = null): ?string
    {
        require_once __DIR__ . '/../admin/includes/pos-shift-totals.php'; // pure helpers only at include
        $ended = rh_pos_last_ended_trading_date($now, $windowFor);
        $day = (new DateTimeImmutable($ended, $now->getTimezone()))->modify('+1 day')->format('Y-m-d');
        return $day === $now->format('Y-m-d') ? $day : null;
    }

    /** automated_email_log key for one day (test sends on their own 'test:' stage, never blocking the real one). */
    function rh_arrivals_chase_key(string $day, bool $test = false): array
    {
        return ['job' => 'arrivals_chase', 'type' => 'day', 'id' => (int)str_replace('-', '', $day), 'stage' => ($test ? 'test:' : '') . 'day:' . $day];
    }

    /**
     * PURE. Who gets the email. To: the "Arrivals to chase email goes to" list (automated_job_arrivals_chase_to), empty =
     * the hotel's main email (email_main, Hotel Details > Main contact email), else the reservations email
     * (email_reservations). CC: automated_job_arrivals_chase_cc, valid addresses only, no repeats, never a To address.
     * Test mode: ONLY the test address, no CC, subject [TEST]; an unusable test address refuses (never the real people).
     * @return array{action:string, to:array, cc:string[], subject_prefix:string, source:string, note:string}
     */
    function rh_arrivals_chase_recipients(string $toSetting, string $ccSetting, string $mainEmail, string $reservationsEmail, string $redirect, bool $testMode): array
    {
        require_once __DIR__ . '/daily-reports-send.php'; // pure parse / CC helpers
        $plan = ['action' => 'send', 'to' => [], 'cc' => [], 'subject_prefix' => '', 'source' => '', 'note' => ''];
        if ($testMode) {
            $redirect = trim($redirect);
            if (!filter_var($redirect, FILTER_VALIDATE_EMAIL)) {
                return ['action' => 'skip', 'note' => 'Test mode is on but the test address is not a valid email, so nothing was sent.'] + $plan;
            }
            return ['to' => [['email' => $redirect, 'name' => '']], 'subject_prefix' => '[TEST] ', 'source' => 'Test recipient'] + $plan;
        }
        foreach ([[$toSetting, 'Arrivals to chase email goes to'], [$mainEmail, 'Main contact email (Hotel Details)'], [$reservationsEmail, 'Reservations email (Hotel Details)']] as [$raw, $label]) {
            $to = rh_daily_reports_parse_recipients((string)$raw);
            if ($to) {
                $plan['to'] = $to;
                $plan['source'] = $label;
                break;
            }
        }
        if (!$plan['to']) {
            return ['action' => 'skip', 'note' => 'No one to send it to: add an address under "Arrivals to chase email goes to" (Automated Emails) or set the Main contact email in Hotel Details.'] + $plan;
        }
        $plan['cc'] = rh_daily_reports_cc($plan['to'], $ccSetting);
        return $plan;
    }

    /**
     * PURE. The email: subject + HTML, every value escaped. $siteUrl = the site address (links to admin/booking-details.php).
     * @return array{subject:string, html:string}
     */
    function rh_arrivals_chase_email(array $chase, string $day, string $siteName, string $siteUrl, string $currency, string $subjectPrefix = ''): array
    {
        $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $money = static fn($v): string => $currency . ' ' . number_format((float)$v, 2);
        $rows = (array)($chase['rows'] ?? []);
        $n = count($rows);
        $urgentN = (int)($chase['urgent'] ?? 0);
        $window = (int)($chase['window'] ?? 0);
        $urgentDays = (int)($chase['urgent_days'] ?? 0);
        $subject = $subjectPrefix . sprintf('[%s] Arrivals with a balance to chase: %d booking%s%s - %s', $siteName, $n, $n === 1 ? '' : 's',
            $urgentN > 0 ? ' (' . $urgentN . ' urgent)' : '', $day);
        $subject = trim((string)preg_replace('/[\r\n]+/', ' ', $subject)); // never a header break
        $base = rtrim($siteUrl, '/');
        $sumBal = 0.0;
        $tr = '';
        foreach ($rows as $r) {
            $sumBal += (float)$r['balance'];
            $url = $base . '/admin/booking-details.php?id=' . (int)$r['lead_id'];
            $lr = $r['last_reminder'] ?? null;
            $last = is_array($lr) ? 'The ' . (int)$lr['stage'] . '-day reminder, ' . date('j M Y H:i', strtotime((string)$lr['at']) ?: time()) : 'None sent yet';
            $style = !empty($r['urgent']) ? ' style="background:#fdecea;"' : '';
            $tr .= '<tr' . $style . '>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;"><a href="' . $h($url) . '">' . $h($r['ref']) . '</a>' . ((int)$r['rooms'] > 1 ? '<br><small>' . (int)$r['rooms'] . ' rooms</small>' : '') . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;">' . $h($r['guest_name']) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;">' . $h($r['phone'] !== '' ? $r['phone'] : '-') . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;">' . $h(date('D j M Y', strtotime((string)$r['arrival']))) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:right;">' . (int)$r['days_until'] . (!empty($r['urgent']) ? ' <strong>urgent</strong>' : '') . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:right;"><strong>' . $h($money($r['balance'])) . '</strong></td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:right;">' . $h($money($r['paid'])) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;">' . $h($last) . '</td>'
                . '</tr>';
        }
        $th = static fn(string $t, bool $right = false): string => '<th style="padding:6px 8px;text-align:' . ($right ? 'right' : 'left') . ';background:#f2f2f2;border-bottom:1px solid #ccc;">' . $t . '</th>';
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;">'
            . '<p>Good morning,</p>'
            . '<p>These confirmed bookings arrive in the next ' . $window . ' day' . ($window === 1 ? '' : 's') . ' and still owe money. Please call or message each guest to arrange payment before they arrive.</p>'
            . '<p><strong>' . $n . ' booking' . ($n === 1 ? '' : 's') . '</strong>, ' . $h($money($sumBal)) . ' still to collect'
            . ($urgentN > 0 ? '; <strong>' . $urgentN . ' urgent</strong> (arriving within ' . $urgentDays . ' day' . ($urgentDays === 1 ? '' : 's') . ', shaded red, listed first)' : '') . '.</p>'
            . '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;font-size:13px;">'
            . '<thead><tr>' . $th('Booking') . $th('Guest') . $th('Phone') . $th('Arrival') . $th('Days left', true) . $th('Balance', true) . $th('Paid', true) . $th('Last reminder to the guest') . '</tr></thead>'
            . '<tbody>' . $tr . '</tbody></table>'
            . '<p style="color:#666;font-size:12px;">The list follows the "Payment reminders before arrival" days setting (' . $h(implode(', ', array_map('intval', (array)($chase['days'] ?? [])))) . '): '
            . 'arrivals within the largest (' . $window . ' days) are listed, those within the smallest (' . $urgentDays . ' days) are urgent. '
            . 'Bookings on a pay-later arrangement or a company account, and groups with a room already checked in, are left out. '
            . 'Balance and paid are for the whole group. The same list is on the Dashboard under "Arrivals with a balance to chase".</p>'
            . '<p style="color:#666;font-size:12px;">' . $h($siteName) . ' - sent once a day by the system (Automated Emails).</p>'
            . '</div>';
        return ['subject' => $subject, 'html' => $html];
    }

    /**
     * Scheduler job 'arrivals_chase' (owner 2026-11-25): once a day, soon after the trading day opens, the staff get the
     * list of arrivals to chase. Every run that reaches the day leaves one automated_email_log row for that day: 'sent',
     * 'skipped' (nothing to chase, with the reason) or 'failed' (with the error; retried an hour later, up to 3 times).
     * Before the day opens nothing is written (the run waits). $o: test_mode (bool); now (DateTimeImmutable), window_for
     * (see rh_arrivals_chase_day), list (a ready rh_arrivals_to_chase() result) and send (callable, same arguments as
     * sendReportEmail) for tests only.
     */
    function rh_job_arrivals_chase(PDO $pdo, array $o = []): array
    {
        $res = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => []];
        if (function_exists('rh_auto_module_on') && !rh_auto_module_on('bookings')) {
            $res['skipped'] = 1;
            return $res;
        }
        require_once __DIR__ . '/station-hours.php'; // rh_site_timezone
        $testMode = !empty($o['test_mode']);
        $now = ($o['now'] ?? null) instanceof DateTimeImmutable ? $o['now'] : new DateTimeImmutable('now', rh_site_timezone());
        $day = rh_arrivals_chase_day($now, $o['window_for'] ?? null);
        if ($day === null) {
            $res['skipped'] = 1; // today's trading day has not opened yet
            return $res;
        }
        $k = rh_arrivals_chase_key($day, $testMode);
        $done = $pdo->prepare('SELECT status FROM automated_email_log WHERE job = ? AND account_type = ? AND account_id = ? AND stage = ?');
        $done->execute([$k['job'], $k['type'], $k['id'], $k['stage']]);
        $prev = $done->fetchColumn();
        $done->closeCursor();
        if ($prev === 'sent' || $prev === 'skipped') {
            $res['skipped'] = 1; // today is done
            return $res;
        }
        if (!rh_scheduler_budget_ok()) {
            $res['deferred'] = true;
            return $res;
        }
        $chase = isset($o['list']) && is_array($o['list']) ? $o['list'] : rh_arrivals_to_chase($pdo);
        $res['checked'] = count($chase['rows']);
        if (!$chase['rows']) {
            $note = 'Nothing to chase: no confirmed booking arriving in the next ' . $chase['window'] . ' days still owes money, so no email was sent today.';
            if ($prev === false) {
                rh_auto_log_skip($pdo, $k['job'], $k['type'], $k['id'], $k['stage'], $note);
            } elseif (rh_auto_log_claim($pdo, $k['job'], $k['type'], $k['id'], $k['stage'], '', '')) {
                rh_auto_log_finish($pdo, $k['job'], $k['type'], $k['id'], $k['stage'], 'skipped', $note); // an earlier failed try, now nothing owes
            }
            $res['skipped'] = 1;
            return $res;
        }
        $plan = rh_arrivals_chase_recipients(rh_auto_setting('automated_job_arrivals_chase_to'), rh_auto_setting('automated_job_arrivals_chase_cc'),
            (string)getSetting('email_main', ''), (string)getSetting('email_reservations', ''), rh_auto_setting('automated_email_test_recipient'), $testMode);
        $siteName = (string)(getSetting('site_name') ?: 'Hotel');
        $mail = rh_arrivals_chase_email($chase, $day, $siteName, (string)($GLOBALS['email_site_url'] ?? getSetting('site_url', '')),
            (string)(getSetting('currency_symbol') ?: 'MWK'), $plan['subject_prefix']);
        $emails = implode(', ', array_column($plan['to'], 'email'));
        if (!rh_auto_log_claim($pdo, $k['job'], $k['type'], $k['id'], $k['stage'], $emails, $mail['subject'])) {
            $res['skipped'] = 1; // already sent (or being sent) today
            return $res;
        }
        if ($plan['action'] !== 'send') {
            rh_auto_record_send($pdo, $k, false, $plan['note'], '', $res, 'Arrivals to chase ' . $day);
            return $res;
        }
        $r = null;
        $msg = '';
        try {
            rh_scheduler_budget_spend();
            require_once __DIR__ . '/report-mailer.php';
            $send = isset($o['send']) && is_callable($o['send']) ? $o['send'] : 'sendReportEmail';
            $emailCtx = function_exists('rh_email_context') ? rh_email_context(['kind' => 'arrivals_chase']) : null; // System Logs > Emails sent
            try {
                $r = $send($plan['to'], $mail['subject'], $mail['html'], [], '', $plan['cc']);
            } finally {
                $emailCtx = null;
            }
            $msg = (string)($r['message'] ?? 'send failed');
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            error_log('auto-scheduler arrivals chase: ' . $msg);
        }
        rh_auto_record_send($pdo, $k, is_array($r) && !empty($r['success']), $msg, '', $res, 'Arrivals to chase ' . $day);
        return $res;
    }
}
