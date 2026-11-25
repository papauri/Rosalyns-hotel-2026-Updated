<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Payment reminders before arrival + the CC on guest reminders (owner 2026-11-25). Usage (repo root, TEST HOTEL ONLY):
 *   php scripts/tests/prearrival_payment_reminders.php
 * Checks:
 *  - the days setting is parsed (1..365, each once, largest first, at most 6; nothing usable = 30,15,4);
 *  - the stage due now is the smallest stage >= days until arrival (a late booking gets only the stage due now);
 *  - the read-only planner (rh_auto_prearrival_plan) on real rows: confirmed + owing = due; paid / pay-later /
 *    company / pending / created in the last 24 h / too far out = nothing; a group = ONE reminder to its lead for the
 *    whole group's balance; each stage claimed once (automated_email_log);
 *  - the email: every tag filled, balance and days shown;
 *  - the CC list: parsing, only on the six guest-reminder jobs, never on test sends, never the To, no double copy.
 * Nothing is ever sent: the job itself is not run. Every database write runs in ONE transaction that is rolled back.
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
require_once $root . '/includes/billing-accounts.php';
require_once $root . '/includes/email-template-tags.php';
require_once $root . '/includes/email-identity.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= BALANCE_TOLERANCE;
// In memory only (never saved): test mode on.
$GLOBALS['_SITE_SETTINGS']['automated_email_test_recipient'] = 'test@example.invalid';
$GLOBALS['_SITE_SETTINGS']['prearrival_payment_reminder_days'] = '30,15,4';

/* ── days setting ── */
ok('days: default 30,15,4', rh_auto_prearrival_days('30,15,4') === [30, 15, 4]);
ok('days: any order, separators, duplicates', rh_auto_prearrival_days(' 4; 15 30,15 ') === [30, 15, 4], json_encode(rh_auto_prearrival_days(' 4; 15 30,15 ')));
ok('days: 0 / over 365 / words / decimals / negatives dropped -> default', rh_auto_prearrival_days('0,400,abc,7.5,-3') === [30, 15, 4]);
ok('days: empty -> default', rh_auto_prearrival_days('') === [30, 15, 4]);
ok('days: at most 6, the largest kept', rh_auto_prearrival_days('1,2,3,4,5,6,7,8') === [8, 7, 6, 5, 4, 3]);
ok('days: 365 kept, single value', rh_auto_prearrival_days('365') === [365]);
ok('days: null reads the saved setting', rh_auto_prearrival_days() === [30, 15, 4]);

/* ── stage picking ── */
$d = [30, 15, 4];
$got = [];
foreach ([45, 31, 30, 29, 16, 15, 10, 5, 4, 2, 1, 0, -3] as $n) {
    $got[$n] = rh_auto_prearrival_stage($n, $d);
}
ok('stage: 45/31 days out = none yet', $got[45] === 0 && $got[31] === 0, json_encode($got));
ok('stage: 30..16 days = the 30-day reminder', $got[30] === 30 && $got[29] === 30 && $got[16] === 30);
ok('stage: 15..5 days = the 15-day reminder', $got[15] === 15 && $got[10] === 15 && $got[5] === 15);
ok('stage: 4..1 days = the 4-day reminder', $got[4] === 4 && $got[2] === 4 && $got[1] === 4);
ok('stage: arrival day or past = none', $got[0] === 0 && $got[-3] === 0);
ok('stage: late booking 10 days out never gets the 30-day one', rh_auto_prearrival_stage(10, $d) === 15);
ok('stage: unsorted list works the same', rh_auto_prearrival_stage(10, [4, 30, 15]) === 15);

/* ── CC list ── */
ok('cc: valid kept, invalid dropped, case duplicates once', rh_auto_reminder_cc_list('gerald@example.invalid, not-an-email; PM@example.invalid pm@example.invalid')
    === ['gerald@example.invalid', 'PM@example.invalid'], json_encode(rh_auto_reminder_cc_list('gerald@example.invalid, not-an-email; PM@example.invalid pm@example.invalid')));
ok('cc: empty = none', rh_auto_reminder_cc_list('') === []);
$GLOBALS['_SITE_SETTINGS']['automated_email_reminder_cc'] = 'gerald@example.invalid,pm@example.invalid';
ok('cc: null reads the saved setting', rh_auto_reminder_cc_list() === ['gerald@example.invalid', 'pm@example.invalid']);

unset($GLOBALS['rh_automated_ctx']);
ok('cc: outside the scheduler = none', rh_automated_email_cc('guest@example.invalid') === []);
$GLOBALS['rh_automated_ctx'] = ['redirect' => 'test@example.invalid', 'bcc' => false, 'cc' => ['gerald@example.invalid']];
ok('cc: test send (redirect set) = none', rh_automated_email_cc('guest@example.invalid') === []);
$GLOBALS['rh_automated_ctx'] = ['redirect' => '', 'bcc' => true, 'cc' => ['gerald@example.invalid', 'GUEST@example.invalid', 'bad', 'Gerald@example.invalid', 'pm@example.invalid']];
ok('cc: live send = the list, never the To, invalid and repeats dropped', rh_automated_email_cc('guest@example.invalid') === ['gerald@example.invalid', 'pm@example.invalid'],
    json_encode(rh_automated_email_cc('guest@example.invalid')));
$GLOBALS['rh_automated_ctx'] = ['redirect' => '', 'bcc' => false];
ok('cc: a job without the flag = none', rh_automated_email_cc('guest@example.invalid') === []);
unset($GLOBALS['rh_automated_ctx']);

// The context the scheduler sets: test recipient on = list kept in the context but never applied.
rh_auto_ctx_begin(false, true);
ok('ctx: guest-reminder job in test mode -> list stored, nothing applied', ($GLOBALS['rh_automated_ctx']['cc'] ?? null) === ['gerald@example.invalid', 'pm@example.invalid']
    && rh_automated_email_cc('guest@example.invalid') === []);
rh_auto_ctx_end();
$GLOBALS['_SITE_SETTINGS']['automated_email_test_recipient'] = '';
rh_auto_ctx_begin(false, true);
ok('ctx: guest-reminder job live -> CC applied', rh_automated_email_cc('guest@example.invalid') === ['gerald@example.invalid', 'pm@example.invalid']);
rh_auto_ctx_end();
rh_auto_ctx_begin(true, false);
ok('ctx: other job live -> no CC', rh_automated_email_cc('guest@example.invalid') === [] && ($GLOBALS['rh_automated_ctx']['cc'] ?? null) === []);
rh_auto_ctx_end();
ok('ctx: ended -> no leak into the next send', !isset($GLOBALS['rh_automated_ctx']) && rh_automated_email_cc('guest@example.invalid') === []);
$GLOBALS['_SITE_SETTINGS']['automated_email_test_recipient'] = 'test@example.invalid';

// Which jobs carry the CC.
$ccJobs = [];
foreach (rh_scheduler_jobs() as $name => $job) {
    if (!empty($job['guest_cc'])) {
        $ccJobs[] = $name;
    }
}
sort($ccJobs);
ok('cc: exactly the six guest-reminder jobs', $ccJobs === ['overdue_payment_reminders', 'prearrival_payment_reminders', 'prearrival_reminders',
    'quotation_expiry_reminder', 'tentative_hold_reminder', 'unpaid_confirmed_reminder'], json_encode($ccJobs));
$job = rh_scheduler_jobs()['prearrival_payment_reminders'] ?? [];
ok('job: registered OFF by default, hourly, global BCC, own toggle', ($job['toggle'] ?? '') === 'automated_email_job_prearrival_payment' && ($job['toggle_default'] ?? '') === '0'
    && (int)($job['interval'] ?? 0) === 60 && ($job['bcc'] ?? '') === 'global' && empty($job['legacy']) && function_exists((string)($job['fn'] ?? '')));
ok('defaults: days 30,15,4, CC empty, job off', rh_auto_defaults()['prearrival_payment_reminder_days'] === '30,15,4'
    && rh_auto_defaults()['automated_email_reminder_cc'] === '' && rh_auto_defaults()['automated_email_job_prearrival_payment'] === '0');

// No double copy: an address on the CC is never added again as a hidden copy.
$m = new PHPMailer\PHPMailer\PHPMailer(true);
$m->addAddress('guest@example.invalid');
$GLOBALS['rh_automated_ctx'] = ['redirect' => '', 'bcc' => false, 'cc' => ['gerald@example.invalid']];
foreach (rh_automated_email_cc('guest@example.invalid') as $a) {
    $m->addCC($a);
}
unset($GLOBALS['rh_automated_ctx']);
rh_mail_add_hidden_copies($m, ['gerald@example.invalid', 'other@example.invalid'], 'guest@example.invalid');
$bccs = array_map(static fn($x) => $x[0], $m->getBccAddresses());
$ccs = array_map(static fn($x) => $x[0], $m->getCcAddresses());
ok('cc + bcc: an address on both gets the CC only', $ccs === ['gerald@example.invalid'] && $bccs === ['other@example.invalid'], json_encode([$ccs, $bccs]));
// Both senders add the CC after the To and before the hidden copies (so those skip a CC address).
$src = (string)file_get_contents($root . '/config/email.php');
foreach (['function sendEmail(' => 'rh_mail_add_staff_bcc($mail, $to, $bccAdmin)', 'function sendEmailWithAttachments(' => 'rh_mail_add_hidden_copies($mail, array_map'] as $fn => $after) {
    $at = strpos($src, $fn);
    $cc = strpos($src, 'rh_automated_email_cc($to)', (int)$at);
    $hid = strpos($src, $after, (int)$at);
    ok('sender ' . trim($fn, '( ') . ': CC added before the hidden copies', $at !== false && $cc !== false && $hid !== false && $cc < $hid);
    $tp = strpos($src, 'rh_test_platform_mail_apply($mail);', (int)$at);
    ok('sender ' . trim($fn, '( ') . ': test-platform redirect runs after the CC (clears it)', $tp !== false && $cc !== false && $cc < $tp);
}
if (is_file($root . '/vendor/autoload.php') || class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    if (function_exists('rh_test_platform_mail_apply') && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        $m2 = new PHPMailer\PHPMailer\PHPMailer(true);
        $m2->addAddress('guest@example.invalid');
        $m2->addCC('gerald@example.invalid');
        $applied = rh_test_platform_mail_apply($m2, ['active' => true, 'to' => 'test@example.invalid']);
        $ccLeft = array_map(static fn($x) => $x[0], $m2->getCcAddresses());
        ok('test platform: redirect leaves no CC', $ccLeft === [], json_encode([$applied, $ccLeft]));
    }
}
$jobsSrc0 = (string)file_get_contents($root . '/includes/auto-email-jobs.php');
ok('timeline: says the real days left, not the stage', strpos($jobsSrc0, "'Payment reminder before arrival sent (' . \$plan['days_until']") !== false);
$jobsSrc = (string)file_get_contents($root . '/includes/auto-email-jobs.php');
ok('Send test to me: its context has no CC', strpos($jobsSrc, "\$GLOBALS['rh_automated_ctx'] = ['redirect' => \$to, 'bcc' => false];") !== false);

/* ── template + tags ── */
$def = hotel_booking_template_defaults_map()['prearrival_payment_reminder'] ?? null;
ok('template: built-in default exists', is_array($def) && $def['name'] === 'Payment reminder before arrival');
preg_match_all('/\{\{([a-z0-9_]+)\}\}/', ($def['subject'] ?? '') . ($def['html'] ?? ''), $mm);
$used = array_values(array_unique($mm[1]));
$map = rh_email_template_tag_map()['prearrival_payment_reminder'] ?? [];
$defs = rh_email_tag_definitions();
$missing = array_values(array_filter($used, static fn($t) => !in_array($t, $map, true) || !isset($defs[$t])));
ok('template: every tag in the default is listed and explained', $map && !$missing, json_encode($missing));
ok('send log: kind label', rh_email_kind_label('prearrival_payment_reminder') === 'Payment reminder before arrival');
ok('editable on Automated Emails + sample', isset(rh_auto_sample_keys()['prearrival_payment_reminder'])
    && strpos((string)file_get_contents($root . '/admin/automated-emails.php'), "'tentative_booking_expired', 'prearrival_payment_reminder'];") !== false);

/* ── DB, rolled back ── */
/** A confirmed room booking 1,165 a night all-in, arriving in $inDays days, created $ageHours hours ago. */
function pp_booking(PDO $pdo, string $email, int $inDays, ?int $primaryId = null, int $ageHours = 72, string $status = 'confirmed'): int {
    $in = date('Y-m-d', strtotime('+' . $inDays . ' day'));
    $out = date('Y-m-d', strtotime($in . ' +2 day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'PPR TEST',?,'+265000000009','Malawi',1,1,0,50,?,?,2,2000,0,0,0,16.5,330,2330,'prearrival payment test',?,'unpaid',0,'single',?,?,
            NOW() - INTERVAL ? HOUR)")
        ->execute(['PPR' . random_int(100000, 999999), $email, $in, $out, $status, bin2hex(random_bytes(16)), $primaryId, $ageHours]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return $id;
}
function pp_pay(PDO $pdo, int $bid, float $amt): void {
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, 0, 0, ?, 'cash', 'partial_payment', 'completed', NOW())")
        ->execute(['PPRP' . random_int(100000, 999999), $bid, $amt, $amt]);
    recalculateBookingFinancials($bid);
}
function pp_row(PDO $pdo, int $bid): array {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$bid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}
$plan = static fn(PDO $pdo, int $id): array => rh_auto_prearrival_plan($pdo, $id, [30, 15, 4]);
$cands = static fn(PDO $pdo): array => rh_auto_prearrival_candidates($pdo, 30);

$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$chk = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
$arrCols = rh_checkin_arrangement_columns_present();
$logReady = rh_auto_log_table_ready($pdo);
$pdo->beginTransaction();
$s1 = 0;
try {
    // S1: single, confirmed 3 days ago, arriving in 10 days, unpaid.
    $s1 = pp_booking($pdo, 'single1@example.invalid', 10);
    $r1 = pp_row($pdo, $s1);
    $p = $plan($pdo, $s1);
    ok('S1 owing, 10 days out: due, the 15-day stage (late booking)', !empty($p['send']) && $p['stage'] === 15 && $p['days_until'] === 10, json_encode(array_diff_key($p, ['lead' => 1])));
    ok('S1 money: balance = amount_due, paid 0, total = paid + balance', $near($p['balance'] ?? -1, $r1['amount_due']) && $near($p['paid'] ?? -1, 0)
        && $near($p['total'] ?? -1, (float)$r1['amount_due']) && (float)$r1['amount_due'] > 1, json_encode([$p['balance'] ?? null, $r1['amount_due']]));
    ok('S1 to the guest', ($p['email'] ?? '') === 'single1@example.invalid' && (int)($p['lead']['id'] ?? 0) === $s1);
    ok('S1 is a candidate', in_array($s1, $cands($pdo), true));

    // The email, built from the real row (nothing sent).
    $tpl = rh_prearrival_payment_email_build($p['lead'], ['days_until' => $p['days_until'], 'total' => $p['total'], 'paid' => $p['paid'], 'balance' => $p['balance']]);
    $cur = (string)getSetting('currency_symbol', 'MWK');
    ok('email: built, no tag left unfilled', is_array($tpl) && strpos($tpl['subject'] . $tpl['html_body'], '{{') === false, is_array($tpl) ? '' : 'switched off?');
    ok('email: reference, balance due and days shown', is_array($tpl) && strpos($tpl['subject'], (string)$r1['booking_reference']) !== false
        && strpos($tpl['html_body'], $cur . ' ' . number_format((float)$p['balance'], 2)) !== false && strpos($tpl['html_body'], '10 days') !== false);

    // Once per stage (claims rolled back with everything else).
    if ($logReady) {
        $c1 = rh_auto_log_claim($pdo, 'prearrival_payment_reminders', 'room', $s1, 'test:pre:15', 'single1@example.invalid', 'x');
        $c2 = rh_auto_log_claim($pdo, 'prearrival_payment_reminders', 'room', $s1, 'test:pre:15', 'single1@example.invalid', 'x');
        $c3 = rh_auto_log_claim($pdo, 'prearrival_payment_reminders', 'room', $s1, 'test:pre:4', 'single1@example.invalid', 'x');
        ok('once per stage: first claim wins, the same stage again is refused, the next stage is its own', $c1 && !$c2 && $c3);
    } else {
        echo "[SKIP] once per stage: automated_email_log missing\n";
    }

    // Part payment lowers the balance; full payment stops it.
    pp_pay($pdo, $s1, 1000);
    $p = $plan($pdo, $s1);
    ok('S1 part paid: balance falls, paid so far shown, total foots', !empty($p['send']) && $near($p['paid'], pp_row($pdo, $s1)['amount_paid'])
        && $near($p['balance'], pp_row($pdo, $s1)['amount_due']) && $near($p['total'], $p['paid'] + $p['balance']), json_encode(array_diff_key($p, ['lead' => 1])));
    pp_pay($pdo, $s1, (float)pp_row($pdo, $s1)['amount_due']);
    $p = $plan($pdo, $s1);
    ok('S1 paid in full: nothing', empty($p['send']) && ($p['why'] ?? '') === 'paid', json_encode($p));
    ok('S1 paid in full: not a candidate', !in_array($s1, $cands($pdo), true));

    // Too far out, too new, pending.
    $far = pp_booking($pdo, 'far@example.invalid', 40);
    ok('40 days out: not a candidate, no stage', !in_array($far, $cands($pdo), true) && empty($plan($pdo, $far)['send']));
    $new = pp_booking($pdo, 'new@example.invalid', 10, null, 2);
    ok('confirmed 2 hours ago: nothing yet', empty($plan($pdo, $new)['send']) && ($plan($pdo, $new)['why'] ?? '') === 'recent');
    // Made 3 days ago as pending, confirmed by staff just now (booking-details 'confirm': timeline status change only).
    $staffConf = pp_booking($pdo, 'staffconf@example.invalid', 10);
    $okBefore = !empty($plan($pdo, $staffConf)['send']);
    require_once $root . '/includes/booking-timeline.php';
    logBookingStatusChange($staffConf, (string)pp_row($pdo, $staffConf)['booking_reference'], 'pending', 'confirmed', 'admin', null, 'PPR TEST');
    ok('staff confirmed a 3-day-old pending booking just now: nothing for 24 hours', $okBefore && ($plan($pdo, $staffConf)['why'] ?? '') === 'recent',
        json_encode(['before' => $okBefore, 'after' => $plan($pdo, $staffConf)['why'] ?? null]));
    $pend = pp_booking($pdo, 'pending@example.invalid', 10, null, 72, 'pending');
    ok('pending booking: never chased', !in_array($pend, $cands($pdo), true) && ($plan($pdo, $pend)['why'] ?? '') === 'not_confirmed');
    $soon = pp_booking($pdo, 'soon@example.invalid', 3);
    ok('3 days out: the 4-day stage', ($plan($pdo, $soon)['stage'] ?? 0) === 4);

    // Pay-later arrangement.
    if ($arrCols) {
        $pl = pp_booking($pdo, 'paylater@example.invalid', 10);
        $pdo->prepare('UPDATE bookings SET checkin_without_payment = 1 WHERE id = ?')->execute([$pl]);
        ok('pay-later arrangement: not a candidate, nothing planned', !in_array($pl, $cands($pdo), true) && empty($plan($pdo, $pl)['send'])
            && ($plan($pdo, $pl)['why'] ?? '') === 'pay_later_or_company');
    } else {
        echo "[SKIP] pay-later arrangement: migration 083 not run\n";
    }

    // Group: lead + 2 members, all confirmed and owing -> ONE reminder, to the lead, for the whole group.
    $g = pp_booking($pdo, 'lead@example.invalid', 12);
    $m1 = pp_booking($pdo, 'member@example.invalid', 12, $g);
    $m2 = pp_booking($pdo, 'member@example.invalid', 14, $g);
    pp_pay($pdo, $m1, 500);
    $want = (float)pp_row($pdo, $g)['amount_due'] + (float)pp_row($pdo, $m1)['amount_due'] + (float)pp_row($pdo, $m2)['amount_due'];
    $c = $cands($pdo);
    ok('group: the lead is the only candidate (members never on their own)', in_array($g, $c, true) && !in_array($m1, $c, true) && !in_array($m2, $c, true));
    $p = $plan($pdo, $g);
    ok('group: one reminder to the lead for the whole group balance', !empty($p['send']) && ($p['email'] ?? '') === 'lead@example.invalid'
        && $near($p['balance'] ?? -1, $want) && ($p['rooms'] ?? 0) === 3 && $near($p['paid'] ?? -1, 500), json_encode(array_diff_key($p, ['lead' => 1])) . ' want ' . $want);
    ok('group: stage from the earliest arrival (12 days -> 15)', ($p['stage'] ?? 0) === 15 && ($p['days_until'] ?? 0) === 12);
    $gt = rh_prearrival_payment_email_build($p['lead'], ['days_until' => $p['days_until'], 'total' => $p['total'], 'paid' => $p['paid'], 'balance' => $p['balance']]);
    ok('group email: lists the rooms of the group and the group balance', is_array($gt) && strpos($gt['html_body'], (string)pp_row($pdo, $m2)['booking_reference']) !== false
        && strpos($gt['html_body'], number_format($want, 2)) !== false);
    pp_pay($pdo, $g, (float)pp_row($pdo, $g)['amount_due']);
    pp_pay($pdo, $m1, (float)pp_row($pdo, $m1)['amount_due']);
    pp_pay($pdo, $m2, (float)pp_row($pdo, $m2)['amount_due']);
    ok('group paid in full: nothing, not a candidate', ($plan($pdo, $g)['why'] ?? '') === 'paid' && !in_array($g, $cands($pdo), true));

    if ($arrCols) {
        $g2 = pp_booking($pdo, 'lead2@example.invalid', 12);
        $g2m = pp_booking($pdo, 'member2@example.invalid', 12, $g2);
        $pdo->prepare('UPDATE bookings SET checkin_without_payment = 1 WHERE id = ?')->execute([$g2m]);
        ok('group with a pay-later room: the whole group is left out', empty($plan($pdo, $g2)['send']) && ($plan($pdo, $g2)['why'] ?? '') === 'pay_later_or_company');
    }
    if (function_exists('rh_billing_ready') && rh_billing_ready()) {
        $acc = (int)$pdo->query('SELECT id FROM billing_accounts ORDER BY id LIMIT 1')->fetchColumn();
        if ($acc > 0) {
            $co = pp_booking($pdo, 'company@example.invalid', 10);
            $pdo->prepare('UPDATE bookings SET billing_account_id = ? WHERE id = ?')->execute([$acc, $co]);
            ok('company-billed booking: never chased here', !in_array($co, $cands($pdo), true) && ($plan($pdo, $co)['why'] ?? '') === 'pay_later_or_company');
        } else {
            echo "[SKIP] company booking: no billing account on file\n";
        }
    } else {
        echo "[SKIP] company booking: migration 084 not run\n";
    }

    $in = pp_booking($pdo, 'inhouse@example.invalid', 10);
    $inm = pp_booking($pdo, 'inhouse@example.invalid', 10, $in);
    $pdo->prepare("UPDATE bookings SET status = 'checked-in' WHERE id = ?")->execute([$inm]);
    ok('group with a room already checked in: nothing', ($plan($pdo, $in)['why'] ?? '') === 'arrived');
} finally {
    if (!$pdo->inTransaction()) {
        fwrite(STDERR, "!! transaction was implicitly committed\n");
        exit(2);
    }
    $pdo->rollBack();
}
$st = $chk->prepare('SELECT COUNT(*) FROM bookings WHERE id = ?');
$st->execute([$s1]);
ok('rolled back: no test booking left (second connection)', (int)$st->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
