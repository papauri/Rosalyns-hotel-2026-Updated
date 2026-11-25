<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Arrivals with a balance to chase (owner 2026-11-25). Usage (repo root, TEST HOTEL ONLY):
 *   php scripts/tests/arrivals_chase.php
 * Checks:
 *  - the shared list (rh_arrivals_to_chase): confirmed + owing inside the window = listed, no 24-hour or stage rule,
 *    pay-later / company / a room checked in / pending / paid / too far / today = left out, a group = ONE row for the
 *    whole group (the same money as the payment reminder before arrival), urgent first;
 *  - window = the LARGEST and urgent = the SMALLEST value of the "Payment reminders before arrival" days (30,15,4 and 20,7);
 *  - the Dashboard count = the rows Bookings ?chase=arrivals lists (the same WHERE and COUNT as admin/bookings.php);
 *  - the daily staff email: every value escaped, no tag left, To fallback, CC parsing, test redirect = no CC, once a day;
 *  - every run leaves its automated_email_log row (sent / skipped with the reason / failed with the error) and every
 *    send its System Logs > Emails sent row with kind arrivals_chase.
 * Nothing is ever sent: the send is a stub, or the real sender with an unusable test address (fails before SMTP).
 * Every database write runs in ONE transaction that is rolled back.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
// Every require (and any table seeding they do) BEFORE the transaction: DDL would commit it implicitly.
require_once $root . '/includes/auto-email-jobs.php';
require_once $root . '/includes/arrivals-chase.php';
require_once $root . '/includes/billing-accounts.php';
require_once $root . '/includes/email-send-log.php';
require_once $root . '/includes/report-mailer.php';
require_once $root . '/includes/daily-reports-send.php';
require_once $root . '/includes/station-hours.php';
require_once $root . '/includes/settings-change-log.php';
require_once $root . '/admin/includes/bookings-list-filters.php';
require_once $root . '/admin/includes/pos-shift-totals.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= BALANCE_TOLERANCE;
// In memory only (never saved).
$GLOBALS['_SITE_SETTINGS']['prearrival_payment_reminder_days'] = '30,15,4';
$GLOBALS['_SITE_SETTINGS']['automated_email_test_recipient'] = 'test@example.invalid';
$GLOBALS['_SITE_SETTINGS']['automated_job_arrivals_chase_to'] = '';
$GLOBALS['_SITE_SETTINGS']['automated_job_arrivals_chase_cc'] = '';
$GLOBALS['_SITE_SETTINGS']['email_main'] = 'frontoffice@example.invalid';
$GLOBALS['_SITE_SETTINGS']['email_reservations'] = 'reservations@example.invalid';
unset($GLOBALS['rh_automated_ctx']);

/* ── limits from the days setting (never a fixed number) ── */
$l = rh_arrivals_chase_limits([30, 15, 4]);
ok('limits 30,15,4: window 30 (largest), urgent 4 (smallest)', $l['window'] === 30 && $l['urgent'] === 4, json_encode($l));
$l = rh_arrivals_chase_limits([7, 20]);
ok('limits 20,7: window 20, urgent 7', $l['window'] === 20 && $l['urgent'] === 7 && $l['days'] === [20, 7], json_encode($l));
$l = rh_arrivals_chase_limits([12]);
ok('limits single value: window = urgent', $l['window'] === 12 && $l['urgent'] === 12);
ok('limits null = the saved setting', rh_arrivals_chase_limits()['window'] === 30 && rh_arrivals_chase_limits()['urgent'] === 4);
$w = rh_arrivals_chase_rule_words(20, 7);
ok('rule words: the setting\'s numbers and its name', strpos($w, 'next 20 days') !== false && strpos($w, 'within 7 days') !== false
    && strpos($w, 'Payment reminders before arrival') !== false, $w);
require_once $root . '/admin/includes/dashboard-attention.php';
$aeSrc = (string)file_get_contents($root . '/admin/automated-emails.php');
$aeAt = strpos($aeSrc, 'id="chaseto"');
$src = (string)file_get_contents($root . '/includes/arrivals-chase.php') . rh_dash_attention_defs()['arrivals_owing']['hint']
    . ($aeAt !== false ? substr($aeSrc, $aeAt, 2500) : 'missing');
ok('no fixed "4 days" / "30 days" in the wording (list, email, Dashboard hint, help text)', !preg_match('/\b(4|30) days\b/', $src) && $aeAt !== false);

/* ── recipients (pure) ── */
$p = rh_arrivals_chase_recipients('a@example.invalid, b@example.invalid', '', 'main@example.invalid', 'res@example.invalid', 'test@example.invalid', false);
ok('to: the setting first', $p['action'] === 'send' && array_column($p['to'], 'email') === ['a@example.invalid', 'b@example.invalid'] && $p['source'] === 'Arrivals to chase email goes to');
$p = rh_arrivals_chase_recipients('', '', 'main@example.invalid', 'res@example.invalid', '', false);
ok('to: empty = the Main contact email (Hotel Details)', array_column($p['to'], 'email') === ['main@example.invalid'] && $p['source'] === 'Main contact email (Hotel Details)');
$p = rh_arrivals_chase_recipients('not-an-email', '', '', 'res@example.invalid', '', false);
ok('to: then the reservations email', array_column($p['to'], 'email') === ['res@example.invalid'] && $p['source'] === 'Reservations email (Hotel Details)');
$p = rh_arrivals_chase_recipients('', 'pm@example.invalid', '', '', '', false);
ok('to: nobody = refused with a plain note, no CC', $p['action'] === 'skip' && $p['to'] === [] && $p['cc'] === [] && strpos($p['note'], 'No one to send it to') === 0);
$p = rh_arrivals_chase_recipients('a@example.invalid', "pm@example.invalid, bad; PM@example.invalid A@example.invalid\r\nBcc: evil@example.invalid gerald@example.invalid", '', '', '', false);
// A line break splits the text into separate words: each is an address or dropped, never a header line.
ok('cc: valid only, no repeats, never the To, no header break', $p['cc'] === ['pm@example.invalid', 'evil@example.invalid', 'gerald@example.invalid'], json_encode($p['cc']));
ok('cc: "Bcc:" text is never an address', !in_array('Bcc:', $p['cc'], true) && !preg_grep('/[\r\n]/', $p['cc']));
$p = rh_arrivals_chase_recipients('a@example.invalid', 'pm@example.invalid', 'main@example.invalid', '', 'test@example.invalid', true);
ok('test mode: ONLY the test address, no CC, [TEST]', array_column($p['to'], 'email') === ['test@example.invalid'] && $p['cc'] === [] && $p['subject_prefix'] === '[TEST] ');
$p = rh_arrivals_chase_recipients('a@example.invalid', 'pm@example.invalid', 'main@example.invalid', '', 'nope', true);
ok('test mode with an unusable test address: refused, never the real people', $p['action'] === 'skip' && $p['to'] === [] && $p['cc'] === []);

/* ── the email (pure): escaped, no tags ── */
$fake = ['window' => 20, 'urgent_days' => 7, 'days' => [20, 7], 'urgent' => 1, 'rows' => [
    ['lead_id' => 11, 'root_id' => 11, 'ref' => 'RH<1>', 'guest_name' => '<script>alert(1)</script> & "Co"', 'phone' => "+265 '1'", 'email' => 'x@example.invalid',
     'arrival' => '2031-03-15', 'days_until' => 3, 'rooms' => 2, 'total' => 1000, 'paid' => 250, 'balance' => 750, 'last_reminder' => ['stage' => 7, 'at' => '2031-03-10 08:00:00'], 'urgent' => true],
    ['lead_id' => 12, 'root_id' => 12, 'ref' => 'RH2', 'guest_name' => 'Plain', 'phone' => '', 'email' => '', 'arrival' => '2031-03-25', 'days_until' => 13, 'rooms' => 1,
     'total' => 500, 'paid' => 0, 'balance' => 500, 'last_reminder' => null, 'urgent' => false],
]];
$e = rh_arrivals_chase_email($fake, '2031-03-12', "Hotel\r\nBcc: evil@example.invalid", 'https://example.invalid/', 'MWK');
ok('email: subject one line, count + urgent + day', strpos($e['subject'], "\n") === false && strpos($e['subject'], "\r") === false
    && strpos($e['subject'], '2 bookings (1 urgent) - 2031-03-12') !== false, $e['subject']);
ok('email: guest name, ref and phone escaped', strpos($e['html'], '<script>') === false && strpos($e['html'], '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Co&quot;') !== false
    && strpos($e['html'], 'RH&lt;1&gt;') !== false && strpos($e['html'], '+265 &#039;1&#039;') !== false);
ok('email: no unfilled tag', strpos($e['subject'] . $e['html'], '{{') === false);
ok('email: link to the admin booking, balance, paid, days left, last reminder', strpos($e['html'], 'https://example.invalid/admin/booking-details.php?id=11') !== false
    && strpos($e['html'], 'MWK 750.00') !== false && strpos($e['html'], 'MWK 250.00') !== false && strpos($e['html'], 'The 7-day reminder') !== false
    && strpos($e['html'], 'None sent yet') !== false && strpos($e['html'], 'MWK 1,250.00 still to collect') !== false);
ok('email: wording follows the setting (20 / 7), urgent shaded', strpos($e['html'], 'next 20 days') !== false && strpos($e['html'], 'within 7 days') !== false
    && strpos($e['html'], '(20, 7)') !== false && substr_count($e['html'], 'background:#fdecea') === 1);

/* ── day + key ── */
$tz = rh_site_timezone();
$opens6 = static fn(string $d): array => ['trading_end' => new DateTimeImmutable($d . ' 06:00:00 +1 day', rh_site_timezone())];
ok('day: after the opening = today', rh_arrivals_chase_day(new DateTimeImmutable('2031-03-12 10:00:00', $tz), $opens6) === '2031-03-12');
ok('day: before the opening = wait (null)', rh_arrivals_chase_day(new DateTimeImmutable('2031-03-12 03:00:00', $tz), $opens6) === null);
ok('key: one per day, test sends on their own stage', rh_arrivals_chase_key('2031-03-12') === ['job' => 'arrivals_chase', 'type' => 'day', 'id' => 20310312, 'stage' => 'day:2031-03-12']
    && rh_arrivals_chase_key('2031-03-12', true)['stage'] === 'test:day:2031-03-12');

/* ── registration ── */
$job = rh_scheduler_jobs()['arrivals_chase'] ?? [];
ok('job: registered OFF by default, own toggle, real function', ($job['toggle'] ?? '') === 'automated_job_arrivals_chase' && ($job['toggle_default'] ?? '') === '0'
    && function_exists((string)($job['fn'] ?? '')) && empty($job['guest_cc']));
ok('defaults: job off, To and CC empty', rh_auto_defaults()['automated_job_arrivals_chase'] === '0' && rh_auto_defaults()['automated_job_arrivals_chase_to'] === ''
    && rh_auto_defaults()['automated_job_arrivals_chase_cc'] === '');
ok('System Logs kind: plain label', rh_email_kind_label('arrivals_chase') === 'Arrivals with a balance to chase (staff)');
$lm = rh_settings_log_label_map();
ok('settings change log: labels for the switch, To and CC', isset($lm['automated_job_arrivals_chase'], $lm['automated_job_arrivals_chase_to'], $lm['automated_job_arrivals_chase_cc']));
$ae = (string)file_get_contents($root . '/admin/automated-emails.php');
ok('Automated Emails: To + CC fields saved only when changed', strpos($ae, 'name="automated_job_arrivals_chase_to"') !== false && strpos($ae, 'name="automated_job_arrivals_chase_cc"') !== false
    && substr_count($ae, "'automated_job_arrivals_chase_to'") >= 2);

/* ── DB, rolled back ── */
/** A confirmed room booking arriving in $inDays days, created $ageHours hours ago. */
function ac_booking(PDO $pdo, string $email, int $inDays, ?int $primaryId = null, int $ageHours = 72, string $status = 'confirmed'): int {
    $in = date('Y-m-d', strtotime('+' . $inDays . ' day'));
    $out = date('Y-m-d', strtotime($in . ' +2 day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'ARC TEST',?,'+265000000008','Malawi',1,1,0,50,?,?,2,2000,0,0,0,16.5,330,2330,'arrivals chase test',?,'unpaid',0,'single',?,?,
            NOW() - INTERVAL ? HOUR)")
        ->execute(['ARC' . random_int(100000, 999999), $email, $in, $out, $status, bin2hex(random_bytes(16)), $primaryId, $ageHours]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return $id;
}
function ac_pay(PDO $pdo, int $bid, float $amt): void {
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, 0, 0, ?, 'cash', 'partial_payment', 'completed', NOW())")
        ->execute(['ARCP' . random_int(100000, 999999), $bid, $amt, $amt]);
    recalculateBookingFinancials($bid);
}
function ac_row(PDO $pdo, int $bid): array {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$bid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}
function ac_log(PDO $pdo, string $stage): array {
    $st = $pdo->prepare("SELECT status, recipient, subject, error FROM automated_email_log WHERE job = 'arrivals_chase' AND account_type = 'day' AND stage = ?");
    $st->execute([$stage]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}
function ac_sendlog(PDO $pdo, int $afterId): array {
    $st = $pdo->prepare("SELECT status, to_email, cc, subject, email_kind, error FROM email_send_log WHERE id > ? AND email_kind = 'arrivals_chase' ORDER BY id");
    $st->execute([$afterId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
$byLead = static function (array $chase): array {
    $o = [];
    foreach ($chase['rows'] as $r) { $o[(int)$r['lead_id']] = $r; }
    return $o;
};

$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$chk = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
$arrCols = rh_checkin_arrangement_columns_present();
$logReady = rh_auto_log_table_ready($pdo);
$sendLogReady = rh_email_send_log_ready($pdo);
$hasCombos = bookingAvailabilityTableExists('room_combinations');
$maxSendLog = $sendLogReady ? (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM email_send_log')->fetchColumn() : 0;
$pdo->beginTransaction();
$a = 0;
try {
    // A: single, 10 days out, unpaid, made 3 days ago.
    $a = ac_booking($pdo, 'a@example.invalid', 10);
    // B: 3 days out (urgent with 30,15,4); T: tomorrow; Z: today (on the arrivals list already).
    $b = ac_booking($pdo, 'b@example.invalid', 3);
    $t = ac_booking($pdo, 't@example.invalid', 1);
    $z = ac_booking($pdo, 'z@example.invalid', 0);
    $far = ac_booking($pdo, 'far@example.invalid', 40);
    $new = ac_booking($pdo, 'new@example.invalid', 10, null, 1);           // made 1 hour ago: no 24-hour rule here
    $pend = ac_booking($pdo, 'pend@example.invalid', 10, null, 72, 'pending');
    $paid = ac_booking($pdo, 'paid@example.invalid', 10);
    ac_pay($pdo, $paid, (float)ac_row($pdo, $paid)['amount_due']);
    // Group: lead + 2 members, one part paid -> one row for the whole group.
    $g = ac_booking($pdo, 'lead@example.invalid', 12);
    $gm1 = ac_booking($pdo, 'm1@example.invalid', 12, $g);
    $gm2 = ac_booking($pdo, 'm2@example.invalid', 14, $g);
    ac_pay($pdo, $gm1, 500);
    // Group whose lead was cancelled: the earliest confirmed room is the row.
    $c = ac_booking($pdo, 'clead@example.invalid', 9);
    $cm1 = ac_booking($pdo, 'cm1@example.invalid', 11, $c);
    $cm2 = ac_booking($pdo, 'cm2@example.invalid', 8, $c);
    $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$c]);
    // Group with a room already checked in.
    $in = ac_booking($pdo, 'inhouse@example.invalid', 10);
    $inm = ac_booking($pdo, 'inhouse@example.invalid', 10, $in);
    $pdo->prepare("UPDATE bookings SET status = 'checked-in' WHERE id = ?")->execute([$inm]);
    $pl = $plg = $co = 0;
    if ($arrCols) {
        $pl = ac_booking($pdo, 'paylater@example.invalid', 10);
        $pdo->prepare('UPDATE bookings SET checkin_without_payment = 1 WHERE id = ?')->execute([$pl]);
        $plg = ac_booking($pdo, 'plg@example.invalid', 10);
        $plgm = ac_booking($pdo, 'plgm@example.invalid', 10, $plg);
        $pdo->prepare('UPDATE bookings SET checkin_without_payment = 1 WHERE id = ?')->execute([$plgm]);
    }
    if (function_exists('rh_billing_ready') && rh_billing_ready()) {
        $acc = (int)$pdo->query('SELECT id FROM billing_accounts ORDER BY id LIMIT 1')->fetchColumn();
        if ($acc === 0) { // none on file: a test account (rolled back with everything else)
            $pdo->prepare("INSERT INTO billing_accounts (name, billing_email, terms_days, active, created_at) VALUES (?, NULL, 30, 1, NOW())")
                ->execute(['ZZ ARC TEST CO ' . random_int(1000, 9999)]);
            $acc = (int)$pdo->lastInsertId();
        }
        if ($acc > 0) {
            $co = ac_booking($pdo, 'company@example.invalid', 10);
            $pdo->prepare('UPDATE bookings SET billing_account_id = ? WHERE id = ?')->execute([$acc, $co]);
        }
    }
    // A already had its 15-day reminder (a real send) and a test send: listed anyway (no stage rule), the real one shown.
    if ($logReady) {
        $pdo->prepare("INSERT INTO automated_email_log (job, account_type, account_id, stage, recipient, subject, status) VALUES ('prearrival_payment_reminders','room',?, 'pre:15', 'a@example.invalid', 'x', 'sent')")->execute([$a]);
        $pdo->prepare("INSERT INTO automated_email_log (job, account_type, account_id, stage, recipient, subject, status) VALUES ('prearrival_payment_reminders','room',?, 'test:pre:4', 'a@example.invalid', 'x', 'sent')")->execute([$a]);
    }

    $chase = rh_arrivals_to_chase($pdo, [30, 15, 4]);
    $L = $byLead($chase);
    ok('list: window 30, urgent 4 from 30,15,4', $chase['window'] === 30 && $chase['urgent_days'] === 4);
    ok('A: confirmed + owing 10 days out = listed, not urgent', isset($L[$a]) && $L[$a]['days_until'] === 10 && $L[$a]['urgent'] === false && $L[$a]['phone'] === '+265000000008'
        && $L[$a]['email'] === 'a@example.invalid' && $L[$a]['guest_name'] === 'ARC TEST');
    ok('A: money = the reminder\'s own (balance = amount_due, paid 0)', isset($L[$a]) && $near($L[$a]['balance'], ac_row($pdo, $a)['amount_due']) && $near($L[$a]['paid'], 0)
        && $near($L[$a]['total'], $L[$a]['balance']));
    if ($logReady) {
        ok('A: already reminded at 15 days -> still listed, last reminder = the real one (test sends ignored)', ($L[$a]['last_reminder']['stage'] ?? 0) === 15,
            json_encode($L[$a]['last_reminder'] ?? null));
    }
    ok('B: 3 days out = urgent', isset($L[$b]) && $L[$b]['urgent'] === true);
    ok('T: tomorrow = listed, urgent', isset($L[$t]) && $L[$t]['days_until'] === 1 && $L[$t]['urgent'] === true);
    ok('Z: arriving today = left out (on the arrivals list)', !isset($L[$z]));
    ok('40 days out = left out', !isset($L[$far]));
    ok('made 1 hour ago = listed (no 24-hour rule)', isset($L[$new]));
    ok('pending = left out', !isset($L[$pend]));
    ok('paid in full = left out', !isset($L[$paid]));
    ok('group with a room checked in = left out', !isset($L[$in]) && !isset($L[$inm]));
    if ($arrCols) {
        ok('pay-later = left out; group with a pay-later room = left out', !isset($L[$pl]) && !isset($L[$plg]));
    } else {
        echo "[SKIP] pay-later: migration 083 not run\n";
    }
    if ($co) {
        ok('company-billed = left out', !isset($L[$co]));
    } else {
        echo "[SKIP] company booking: no billing account / migration 084\n";
    }
    // Group: same money and rooms as the payment reminder before arrival's plan.
    $plan = rh_auto_prearrival_plan($pdo, $g, [30, 15, 4]);
    ok('group: ONE row on the lead, members never on their own', isset($L[$g]) && !isset($L[$gm1]) && !isset($L[$gm2]));
    ok('group: rooms, paid, balance, total = rh_auto_prearrival_plan', isset($L[$g]) && $L[$g]['rooms'] === ($plan['rooms'] ?? -1) && $near($L[$g]['paid'], $plan['paid'] ?? -1)
        && $near($L[$g]['balance'], $plan['balance'] ?? -1) && $near($L[$g]['total'], $plan['total'] ?? -1) && $near($L[$g]['paid'], 500),
        json_encode([$L[$g] ?? null, array_diff_key($plan, ['lead' => 1])]));
    ok('group: arrival = the earliest room (12 days)', ($L[$g]['days_until'] ?? 0) === 12);
    $cRow = null;
    foreach ($chase['rows'] as $r) { if ((int)$r['root_id'] === $c) { $cRow = $r; } }
    $cEff = rh_booking_group_effective_lead($pdo, $c);
    $cPlan = rh_auto_prearrival_plan($pdo, $c, [30, 15, 4]);
    ok('group with a cancelled lead: one row on the group\'s effective lead (the room that took over), the live rooms\' money, arrival = earliest',
        $cRow !== null && in_array($cEff, [$cm1, $cm2], true) && (int)$cRow['lead_id'] === $cEff && $cRow['rooms'] === 2
        && $cRow['days_until'] === 8 && $near($cRow['balance'], (float)ac_row($pdo, $cm1)['amount_due'] + (float)ac_row($pdo, $cm2)['amount_due']), json_encode([$cRow, $cEff]));
    ok('cancelled lead: the guest reminder goes to the same room the list shows, keyed on the root', (int)($cPlan['lead']['id'] ?? 0) === $cEff
        && in_array($c, rh_auto_prearrival_candidates($pdo, 30, [30, 15, 4], 'test:', null, 5000), true) && !in_array($cEff, rh_auto_prearrival_candidates($pdo, 30, [30, 15, 4], 'test:', null, 5000), true),
        json_encode([(int)($cPlan['lead']['id'] ?? 0), $cEff]));
    if ($logReady) {
        // The reminder log stays keyed on the root: the list's "last reminder" for the group follows it.
        $pdo->prepare("INSERT INTO automated_email_log (job, account_type, account_id, stage, recipient, subject, status) VALUES ('prearrival_payment_reminders','room',?, 'pre:15', 'x@example.invalid', 'x', 'sent')")->execute([$c]);
        $cRow2 = null;
        foreach (rh_arrivals_to_chase($pdo, [30, 15, 4])['rows'] as $r) { if ((int)$r['root_id'] === $c) { $cRow2 = $r; } }
        ok('cancelled lead: last reminder found under the root while the row shows the effective lead', ($cRow2['last_reminder']['stage'] ?? 0) === 15 && (int)($cRow2['lead_id'] ?? 0) === $cEff);
    }

    // Overpaid room in a group: its credit offsets the others; the group balance is netted, never the overpaid room as 0.
    $ov = ac_booking($pdo, 'ovlead@example.invalid', 13);
    $ovm = ac_booking($pdo, 'ovm@example.invalid', 13, $ov);
    ac_pay($pdo, $ovm, (float)ac_row($pdo, $ovm)['amount_due'] + 300);
    $ovWant = max(0.0, (float)ac_row($pdo, $ov)['amount_due'] - (float)ac_row($pdo, $ovm)['credit_balance']);
    $ovRow = $byLead(rh_arrivals_to_chase($pdo, [30, 15, 4]))[$ov] ?? null;
    $ovPlan = rh_auto_prearrival_plan($pdo, $ov, [30, 15, 4]);
    ok('overpaid room in a group: credit 300 offsets the lead\'s balance (list = reminder)', $near(ac_row($pdo, $ovm)['credit_balance'], 300) && $ovRow !== null
        && $near($ovRow['balance'], $ovWant) && $near($ovRow['balance'], (float)ac_row($pdo, $ov)['amount_due'] - 300) && $near($ovPlan['balance'] ?? -1, $ovWant)
        && $near($ovRow['total'], $ovRow['paid'] + $ovRow['balance']), json_encode([$ovRow, $ovWant]));
    // Overpaid by more than the rest owes: the group owes nothing, it is not listed (floored at 0 for the group).
    ac_pay($pdo, $ovm, (float)ac_row($pdo, $ov)['amount_due']);
    ok('group whose overpaid room covers the rest: left out, reminder says paid', !isset($byLead(rh_arrivals_to_chase($pdo, [30, 15, 4]))[$ov])
        && (rh_auto_prearrival_plan($pdo, $ov, [30, 15, 4])['why'] ?? '') === 'paid');
    ok('money helper: netted, floored at 0, tolerance', rh_prearrival_group_money([
            ['check_in_date' => '2031-01-05', 'amount_paid' => 0, 'amount_due' => 100, 'credit_balance' => 0],
            ['check_in_date' => '2031-01-03', 'amount_paid' => 260, 'amount_due' => 0, 'credit_balance' => 60]]) === ['first' => strtotime('2031-01-03'), 'paid' => 260.0, 'balance' => 40.0, 'total' => 300.0]
        && rh_prearrival_group_money([['check_in_date' => '2031-01-05', 'amount_paid' => 100, 'amount_due' => 0.005, 'credit_balance' => 0]])['balance'] === 0.0
        && rh_prearrival_group_money([['check_in_date' => '2031-01-05', 'amount_paid' => 500, 'amount_due' => 50, 'credit_balance' => 0], ['check_in_date' => '2031-01-05', 'amount_paid' => 0, 'amount_due' => 0, 'credit_balance' => 80]])['balance'] === 0.0);
    $flags = array_map(static fn($r) => $r['urgent'] ? 0 : 1, $chase['rows']);
    $sorted = $flags;
    sort($sorted);
    $okOrder = $flags === $sorted;
    for ($i = 1; $i < count($chase['rows']); $i++) {
        $p0 = $chase['rows'][$i - 1]; $p1 = $chase['rows'][$i];
        if ($p0['urgent'] === $p1['urgent'] && $p0['arrival'] > $p1['arrival']) { $okOrder = false; }
    }
    ok('order: urgent first, then by arrival', $okOrder);
    ok('urgent count = urgent rows', $chase['urgent'] === count(array_filter($chase['rows'], static fn($r) => $r['urgent'])));

    // Another days setting: 20,7 -> window 20, urgent within 7 (saved setting and explicit both).
    $d18 = ac_booking($pdo, 'd18@example.invalid', 18);
    $d25 = ac_booking($pdo, 'd25@example.invalid', 25);
    $d6 = ac_booking($pdo, 'd6@example.invalid', 6);
    $GLOBALS['_SITE_SETTINGS']['prearrival_payment_reminder_days'] = '20,7';
    $c27 = rh_arrivals_to_chase($pdo);
    $L27 = $byLead($c27);
    ok('20,7: window 20, urgent 7 (from the saved setting)', $c27['window'] === 20 && $c27['urgent_days'] === 7);
    ok('20,7: 18 days listed not urgent, 25 days left out, 6 days urgent, 10 days not urgent', isset($L27[$d18]) && !$L27[$d18]['urgent'] && !isset($L27[$d25])
        && !empty($L27[$d6]['urgent']) && isset($L27[$a]) && !$L27[$a]['urgent']);
    ok('20,7 vs 30,15,4: 25 days is in the 30-day window', isset($byLead(rh_arrivals_to_chase($pdo, [30, 15, 4]))[$d25]));
    $GLOBALS['_SITE_SETTINGS']['prearrival_payment_reminder_days'] = '30,15,4';

    // Dashboard count = the rows Bookings ?chase=arrivals lists (admin/bookings.php's own filter, WHERE and COUNT).
    $dash = rh_arrivals_to_chase($pdo);
    $f = rh_bk_filters_from_get(['chase' => 'arrivals']);
    $f['chase_ids'] = rh_arrivals_chase_ids($dash);
    $sc = rh_bk_list_scope($f, $hasCombos);
    $where = 'WHERE ' . implode(' AND ', $sc['clauses']);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id {$where}");
    $cnt->execute($sc['params']);
    $ids = $pdo->prepare("SELECT b.id FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id {$where} ORDER BY b.id");
    $ids->execute($sc['params']);
    $listIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
    $want = rh_arrivals_chase_ids($dash);
    sort($want);
    ok('filter: ?chase=arrivals is a filter (the list re-counts), with its chip', $f['chase'] === 'arrivals' && rh_bk_filters_active($f)
        && in_array('Arrivals with a balance to chase (balance = whole group)', array_column(rh_bk_chips($f, []), 'label'), true));
    // The chase view's Balance due = the list's (and the email's) whole-group figure for each listed booking.
    $balMap = rh_arrivals_chase_balances($dash);
    $Ld = $byLead($dash);
    $balOk = count($balMap) === count($dash['rows']);
    foreach ($Ld as $lid => $r) {
        if (!isset($balMap[$lid]) || !$near($balMap[$lid]['balance'], $r['balance']) || $balMap[$lid]['rooms'] !== $r['rooms']) { $balOk = false; }
    }
    ok('chase view: each listed booking shows the list\'s group balance and room count (group of 3 = the 3 rooms)', $balOk && isset($balMap[$g])
        && $balMap[$g]['rooms'] === 3 && $near($balMap[$g]['balance'], $Ld[$g]['balance']), json_encode($balMap[$g] ?? null));
    $bkSrc = (string)file_get_contents($root . '/admin/bookings.php');
    ok('bookings.php: the chase view prints the shared figure (not the booking\'s own), labelled "Group balance"', strpos($bkSrc, '$bk_chase_bal = rh_arrivals_chase_balances($bk_chase_list);') !== false
        && strpos($bkSrc, 'number_format($bk_left_shown, 2)') !== false && strpos($bkSrc, "'Group balance, '") !== false
        && strpos($bkSrc, 'data-balance="<?php echo $bk_balance_due === null') !== false);
    ok('Dashboard count = Bookings ?chase=arrivals rows (same bookings)', (int)$cnt->fetchColumn() === count($dash['rows']) && $listIds === $want && count($want) > 0,
        json_encode([count($dash['rows']), $listIds === $want]));
    $f0 = rh_bk_filters_from_get(['chase' => 'arrivals']);
    $f0['chase_ids'] = [];
    ok('filter: an empty list shows no rows (never every booking)', in_array('1 = 0', rh_bk_list_scope($f0, $hasCombos)['clauses'], true));
    ok('filter: other values ignored', rh_bk_filters_from_get(['chase' => 'x'])['chase'] === '' && rh_bk_keep_params($f)['chase'] === 'arrivals');

    // The email from the real list.
    $mail = rh_arrivals_chase_email($dash, date('Y-m-d'), 'Liwonde Test', 'https://example.invalid', 'MWK');
    ok('email (real list): every test booking linked, no tag left', strpos($mail['html'], 'booking-details.php?id=' . $a . '"') !== false
        && strpos($mail['html'], 'booking-details.php?id=' . $g . '"') !== false && strpos($mail['html'] . $mail['subject'], '{{') === false
        && strpos($mail['subject'], count($dash['rows']) . ' booking') !== false);

    /* ── the job: log rows (Automated Emails > Recent activity) + System Logs > Emails sent ── */
    if ($logReady) {
        $calls = [];
        $stub = static function (array $to, string $subject, string $html, array $att, string $text, array $cc) use (&$calls): array {
            $ctx = rh_email_context_current();
            $calls[] = ['to' => array_column($to, 'email'), 'cc' => $cc, 'subject' => $subject, 'kind' => $ctx['kind'] ?? null];
            // What sendReportEmail logs after a real send (the message's own To / CC).
            $m = new PHPMailer\PHPMailer\PHPMailer(true);
            foreach ($to as $r) { $m->addAddress($r['email']); }
            foreach ($cc as $x) { $m->addCC($x); }
            rh_email_send_log_mail('sent', $m, $subject);
            return ['success' => true, 'message' => 'sent'];
        };
        $at = static fn(string $d, string $h = '10:00:00') => new DateTimeImmutable($d . ' ' . $h, rh_site_timezone());

        // Before the opening: waits, writes nothing.
        $r0 = rh_job_arrivals_chase($pdo, ['test_mode' => true, 'now' => $at('2031-03-12', '03:00:00'), 'window_for' => $opens6, 'send' => $stub, 'list' => $dash]);
        ok('job: before the trading day opens = waits, no row, no send', $r0['skipped'] === 1 && !ac_log($pdo, 'test:day:2031-03-12') && !$calls);

        // Test mode: ONLY the test address, no CC, [TEST]; logged twice over.
        $GLOBALS['_SITE_SETTINGS']['automated_job_arrivals_chase_cc'] = 'pm@example.invalid, gerald@example.invalid';
        $r1 = rh_job_arrivals_chase($pdo, ['test_mode' => true, 'now' => $at('2031-03-12'), 'window_for' => $opens6, 'send' => $stub, 'list' => $dash]);
        $row = ac_log($pdo, 'test:day:2031-03-12');
        ok('job test mode: sent to the test address only, no CC, [TEST], kind set', $r1['sent'] === 1 && count($calls) === 1 && $calls[0]['to'] === ['test@example.invalid']
            && $calls[0]['cc'] === [] && strpos($calls[0]['subject'], '[TEST] ') === 0 && $calls[0]['kind'] === 'arrivals_chase', json_encode([$r1, $calls]));
        ok('job: Recent activity row = sent, To and subject', ($row['status'] ?? '') === 'sent' && ($row['recipient'] ?? '') === 'test@example.invalid'
            && strpos((string)($row['subject'] ?? ''), 'Arrivals with a balance to chase') !== false, json_encode($row));
        ok('job: the email context is closed after the send', (rh_email_context_current()['kind'] ?? null) !== 'arrivals_chase');
        $r2 = rh_job_arrivals_chase($pdo, ['test_mode' => true, 'now' => $at('2031-03-12', '15:00:00'), 'window_for' => $opens6, 'send' => $stub, 'list' => $dash]);
        ok('job: once a day (a second run the same day sends nothing)', $r2['skipped'] === 1 && $r2['sent'] === 0 && count($calls) === 1);
        ok('once a day: a double claim is refused', !rh_auto_log_claim($pdo, 'arrivals_chase', 'day', 20310312, 'test:day:2031-03-12', 'x', 'x'));

        // Live: To falls back to the Main contact email, CC from the setting.
        $r3 = rh_job_arrivals_chase($pdo, ['test_mode' => false, 'now' => $at('2031-03-13'), 'window_for' => $opens6, 'send' => $stub, 'list' => $dash]);
        ok('job live: To = Main contact email (empty setting), CC = the list', $r3['sent'] === 1 && ($calls[1]['to'] ?? null) === ['frontoffice@example.invalid']
            && ($calls[1]['cc'] ?? null) === ['pm@example.invalid', 'gerald@example.invalid'] && strpos((string)($calls[1]['subject'] ?? ''), '[TEST]') === false, json_encode($calls[1] ?? null));
        ok('job live: a live day is its own row (test send never blocks it)', (ac_log($pdo, 'day:2031-03-13')['status'] ?? '') === 'sent');
        if ($sendLogReady) {
            $sl = ac_sendlog($pdo, $maxSendLog);
            ok('System Logs > Emails sent: one row per send, kind arrivals_chase, To + CC + subject', count($sl) === 2 && $sl[0]['to_email'] === 'test@example.invalid'
                && $sl[1]['to_email'] === 'frontoffice@example.invalid' && strpos((string)$sl[1]['cc'], 'pm@example.invalid') !== false
                && strpos((string)$sl[1]['cc'], 'gerald@example.invalid') !== false && $sl[1]['status'] === 'sent' && $sl[1]['subject'] === $calls[1]['subject'], json_encode($sl));
        } else {
            echo "[SKIP] email_send_log: migration 081 not run\n";
        }

        // A failed send: logged with the error (retried later).
        $failStub = static fn(array $to, string $s, string $h, array $a2, string $t, array $cc): array => ['success' => false, 'message' => 'SMTP down (test)'];
        $r4 = rh_job_arrivals_chase($pdo, ['test_mode' => false, 'now' => $at('2031-03-14'), 'window_for' => $opens6, 'send' => $failStub, 'list' => $dash]);
        $row = ac_log($pdo, 'day:2031-03-14');
        ok('job failed send: row failed with the error', ($row['status'] ?? '') === 'failed' && ($row['error'] ?? '') === 'SMTP down (test)' && $r4['errors'], json_encode([$row, $r4]));

        // The real sender, unusable test address: fails before SMTP, still in System Logs with the kind.
        $GLOBALS['rh_automated_ctx'] = ['redirect' => 'not-an-email', 'bcc' => false];
        $r5 = rh_job_arrivals_chase($pdo, ['test_mode' => false, 'now' => $at('2031-03-15'), 'window_for' => $opens6, 'list' => $dash]);
        unset($GLOBALS['rh_automated_ctx']);
        $row = ac_log($pdo, 'day:2031-03-15');
        ok('job, real sender, bad test address: nothing sent, row failed with the reason', ($row['status'] ?? '') === 'failed' && stripos((string)($row['error'] ?? ''), 'test address') !== false,
            json_encode([$row, $r5]));
        if ($sendLogReady) {
            $sl = ac_sendlog($pdo, $maxSendLog);
            $last = end($sl) ?: [];
            ok('System Logs: the refused send is logged failed under arrivals_chase', count($sl) === 3 && ($last['status'] ?? '') === 'failed'
                && stripos((string)($last['error'] ?? ''), 'test address') !== false, json_encode($last));
        }

        // Nothing to chase: one skipped row with the reason, no email, never retried that day.
        $empty = ['rows' => [], 'window' => 30, 'urgent_days' => 4, 'days' => [30, 15, 4], 'urgent' => 0];
        $n0 = count($calls);
        $r6 = rh_job_arrivals_chase($pdo, ['test_mode' => false, 'now' => $at('2031-03-16'), 'window_for' => $opens6, 'send' => $stub, 'list' => $empty]);
        $row = ac_log($pdo, 'day:2031-03-16');
        ok('job empty day: skipped row "Nothing to chase" with the window, no email', $r6['skipped'] === 1 && ($row['status'] ?? '') === 'skipped'
            && strpos((string)($row['error'] ?? ''), 'Nothing to chase') === 0 && strpos((string)$row['error'], 'next 30 days') !== false && count($calls) === $n0, json_encode($row));
        $r7 = rh_job_arrivals_chase($pdo, ['test_mode' => false, 'now' => $at('2031-03-16', '18:00:00'), 'window_for' => $opens6, 'send' => $stub, 'list' => $dash]);
        ok('job empty day: no later send that day', $r7['skipped'] === 1 && count($calls) === $n0);

        // No one to send to: failed with the plain note; bad test address in test mode: failed, never the real people.
        $GLOBALS['_SITE_SETTINGS']['email_main'] = '';
        $GLOBALS['_SITE_SETTINGS']['email_reservations'] = '';
        rh_job_arrivals_chase($pdo, ['test_mode' => false, 'now' => $at('2031-03-17'), 'window_for' => $opens6, 'send' => $stub, 'list' => $dash]);
        $row = ac_log($pdo, 'day:2031-03-17');
        ok('job no recipient: failed with "No one to send it to", no send', ($row['status'] ?? '') === 'failed' && strpos((string)$row['error'], 'No one to send it to') === 0 && count($calls) === $n0);
        $GLOBALS['_SITE_SETTINGS']['automated_email_test_recipient'] = 'nope';
        rh_job_arrivals_chase($pdo, ['test_mode' => true, 'now' => $at('2031-03-18'), 'window_for' => $opens6, 'send' => $stub, 'list' => $dash]);
        $row = ac_log($pdo, 'test:day:2031-03-18');
        ok('job test mode, bad test address: failed, nobody emailed', ($row['status'] ?? '') === 'failed' && count($calls) === $n0, json_encode($row));
        $GLOBALS['_SITE_SETTINGS']['automated_email_test_recipient'] = 'test@example.invalid';
        $GLOBALS['_SITE_SETTINGS']['automated_job_arrivals_chase_cc'] = '';
    } else {
        echo "[SKIP] job log: automated_email_log missing\n";
    }
} finally {
    if (!$pdo->inTransaction()) {
        fwrite(STDERR, "!! transaction was implicitly committed\n");
        exit(2);
    }
    $pdo->rollBack();
}
$st = $chk->prepare('SELECT COUNT(*) FROM bookings WHERE id = ?');
$st->execute([$a]);
ok('rolled back: no test booking left (second connection)', (int)$st->fetchColumn() === 0);
$st = $chk->query("SELECT COUNT(*) FROM automated_email_log WHERE job = 'arrivals_chase' AND account_id BETWEEN 20310312 AND 20310318");
ok('rolled back: no test log row left (second connection)', (int)$st->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
