<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Email send log + cancel without emailing (owner 2026-11-12). NO DATABASE: only the pure helpers in
 * includes/email-send-log.php and the history builder in includes/booking-history-format.php are loaded.
 * Checks: kind words, result words, the context stack (merge, guard ends it, no leak), who sent it, copy text,
 * the "Email the guest" default tick and choice, the history fold (one step per email, 2-minute window), the
 * System Logs filters and timeline line. Follow-up (2026-11-12): a quiet cancel still alerts the hotel by WhatsApp,
 * test sends are kind 'test' with no account, one row per send in preview mode, history folds only through the fixed
 * kind -> record-key map, the booking page writes cancellation_log. Usage (repo root): php scripts/tests/email_send_log_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

date_default_timezone_set('Africa/Blantyre');
require_once $root . '/includes/email-send-log.php';
require_once $root . '/includes/booking-history-format.php';
check(!function_exists('getSetting') && !isset($GLOBALS['pdo']), 'the helpers load without the database or settings');
check(!rh_email_send_log_ready(), 'no database: the log is "not ready" (nothing written, nothing thrown)');
rh_email_send_log_write(['status' => 'sent', 'to' => 'a@b.c', 'subject' => 'x']);
ok('writing without a database does nothing and does not throw');

/* ── kinds and results ── */
check(rh_email_kind_label('booking_cancelled') === 'Booking cancelled', 'kind label: booking_cancelled');
check(rh_email_kind_label('pre_arrival') === 'Before-arrival', 'kind label: pre_arrival (no doubled "email")');
check(rh_email_kind_label('some_new_kind') === 'Some new kind', 'kind label: an unknown kind in plain words');
check(rh_email_kind_label('') === 'Other', 'kind label: empty -> Other');
check(rh_email_kind_clean('Booking Confirmed!') === 'other' && rh_email_kind_clean('invoice') === 'invoice', 'kind key is cleaned');
$labels = array_map(function ($d) { return $d[0]; }, rh_email_kinds());
check(count(array_filter($labels, function ($l) { return preg_match('/email$/i', $l); })) === 0, 'no kind label ends in "email"');
check(rh_email_result_label('sent') === 'Sent', 'result: sent');
check(rh_email_result_label('failed', "SMTP connect()\n failed") === 'Failed: SMTP connect() failed', 'result: failed + reason on one line');
check(rh_email_result_label('not_sent', 'Staff chose not to email the guest') === 'Not sent: Staff chose not to email the guest', 'result: not sent + reason');
check(rh_email_result_label('preview') === 'Preview only (not sent)', 'result: preview');
check(strlen(rh_email_result_label('failed', str_repeat('x', 500))) <= 210, 'result: a long reason is shortened');
check(rh_email_result_word('not_sent') === 'Not sent' && rh_email_result_word('zzz') === 'Unknown', 'result words');

/* ── context ── */
check(rh_email_context_current() === [], 'context: empty at start');
$outer = rh_email_context(['by_label' => 'System (scheduler)']);
$inner = rh_email_context(['kind' => 'tentative_reminder'] + rh_email_ctx_account('room', 12, 'RBH-12'));
$c = rh_email_context_current();
check(($c['by_label'] ?? '') === 'System (scheduler)' && ($c['kind'] ?? '') === 'tentative_reminder' && ($c['account_id'] ?? 0) === 12,
    'context: inner merges over outer (who kept, kind + account added)');
$inner = null;
$c = rh_email_context_current();
check(($c['by_label'] ?? '') === 'System (scheduler)' && !isset($c['kind']), 'context: dropping the guard ends the inner context');
$outer = null;
check(rh_email_context_current() === [], 'context: dropping the outer guard ends it too');
function rh_test_sender_early_return(): string
{
    $emailCtx = rh_email_context(['kind' => 'booking_confirmed']);
    if (true) {
        return (string)(rh_email_context_current()['kind'] ?? '');
    }
}
check(rh_test_sender_early_return() === 'booking_confirmed', 'context: visible inside the sender');
check(rh_email_context_current() === [], 'context: an early return does not leak it to the next send');
function rh_test_sender_throws(): void
{
    $emailCtx = rh_email_context(['kind' => 'invoice']);
    throw new RuntimeException('boom');
}
try { rh_test_sender_throws(); } catch (RuntimeException $e) { }
check(rh_email_context_current() === [], 'context: an exception does not leak it either');
$g = rh_email_context(['kind' => 'x', 'account_ref' => '', 'account_id' => null]);
check(!array_key_exists('account_ref', rh_email_context_current()) && !array_key_exists('account_id', rh_email_context_current()), 'context: empty values are left out');
rh_email_context_clear();
check(rh_email_context_current() === [], 'context: clear empties the stack');
$g = null;
// A page handler at script scope (e.g. All Bookings "Resend email") must not leave its context open for a later send.
$emailCtx = rh_email_context(rh_email_ctx_account('room', 77, 'RBH-77'));
try {
    check((rh_email_context_current()['account_ref'] ?? '') === 'RBH-77', 'leak: the resend context is seen by its own send');
} finally {
    $emailCtx = null;
    rh_email_context_clear();
}
check(rh_email_context_current() === [], 'leak: after the handler the context is gone');
$later = (function () { $emailCtx = rh_email_context(['kind' => 'daily_report']); return rh_email_context_current(); })();
check($later === ['kind' => 'daily_report'], 'leak: a later send (daily report) is not "about" the resent booking', json_encode($later));
// Even if a page forgot to clear, the scheduler starts clean.
$stale = rh_email_context(['kind' => 'invoice'] + rh_email_ctx_account('room', 77, 'RBH-77'));
$sched = rh_email_context_fresh(['by_label' => 'System (scheduler)']);
check(rh_email_context_current() === ['by_label' => 'System (scheduler)'], 'scheduler: starts from a clean context, inherits nothing');
$inSender = (function () { $emailCtx = rh_email_context(['kind' => 'station_report']); return rh_email_context_current(); })();
check($inSender === ['by_label' => 'System (scheduler)', 'kind' => 'station_report'], 'scheduler: a job email has the scheduler and its own kind, no stale booking', json_encode($inSender));
$sched = null;
$stale = null;
rh_email_context_clear();
$schedSrc = (string)file_get_contents($root . '/includes/auto-scheduler.php');
check(strpos($schedSrc, "rh_email_context_fresh(['by_label' => 'System (scheduler)'])") !== false, 'scheduler: rh_auto_ctx_begin uses the fresh context');
check(preg_match('/function rh_auto_ctx_end\(\): void\s*\{[^}]*rh_email_context_clear\(\)/s', $schedSrc) === 1, 'scheduler: rh_auto_ctx_end clears the context');
$bkSrc = (string)file_get_contents($root . '/admin/bookings.php');
check(preg_match("/\\} finally \\{\\s*\\\$emailCtx = null;\\s*rh_email_context_clear\\(\\);/", $bkSrc) === 1, 'resend handler clears its context in a finally');
check(rh_email_ctx_account('room', 0, '') === ['account_type' => 'room'], 'account: no id or ref -> type only');
check(rh_email_ctx_account('Bad Type!', 5, 'R') === ['account_id' => 5, 'account_ref' => 'R'], 'account: a bad type is dropped');

/* ── who ── */
$sess = ['admin_user_id' => 9, 'admin_full_name' => 'Mercy Kamanga', 'admin_username' => 'mercy', 'admin_role' => 'receptionist'];
check(rh_email_who([], $sess, 'apache2handler', '/admin/bookings.php') === [9, 'Mercy Kamanga (receptionist)'], 'who: staff on an admin page');
check(rh_email_who([], $sess, 'apache2handler', '/rosalyns/admin/api/history.php') === [9, 'Mercy Kamanga (receptionist)'], 'who: staff on an admin API under a sub-folder');
check(rh_email_who(['by_label' => 'System (scheduler)'], $sess, 'apache2handler', '/admin/dashboard.php') === [null, 'System (scheduler)'],
    'who: the scheduler inside a staff request is still the scheduler');
check(rh_email_who([], $sess, 'apache2handler', '/booking.php') === [null, 'Guest website'], 'who: a shared session on the guest site is never credited to staff');
check(rh_email_who([], [], 'apache2handler', '/booking-lookup.php') === [null, 'Guest website'], 'who: guest self-cancel = Guest website');
check(rh_email_who([], [], 'cli', 'scripts/daily_reports.php') === [null, 'System'], 'who: command line = System');
check(rh_email_who([], [], 'fpm-fcgi', '/api/bookings.php') === [null, 'Online booking (API)'], 'who: key API');
check(rh_email_who([], [], 'fpm-fcgi', '/admin/forgot-password.php') === [null, 'System'], 'who: admin page without a session = System');
check(rh_email_staff_label('', 'p.msukwa', 'super_admin') === 'p.msukwa (super admin)', 'who: username when no full name, role in words');

/* ── copies ── */
check(rh_email_copy_text([['a@x.com', 'A'], ['B@Y.com', '']], [['c@z.com', ''], ['a@x.com', '']]) === 'a@x.com, b@y.com, c@z.com (hidden)',
    'copies: visible first, hidden marked, an address in both once');
check(rh_email_copy_text([], []) === '', 'copies: none -> empty');
check(rh_email_addr_list(['x@y.z', ' X@Y.Z ', '']) === ['x@y.z'], 'addresses: unique, lower-case, blanks dropped');

/* ── cancel without emailing ── */
check(rh_cancel_email_default_ticked('list', 'confirmed') === true, 'default tick: All Bookings, confirmed -> ticked');
check(rh_cancel_email_default_ticked('list', 'pending') === false, 'default tick: All Bookings, pending -> not ticked (never emailed before)');
check(rh_cancel_email_default_ticked('list', 'tentative') === false, 'default tick: All Bookings, tentative -> not ticked');
check(rh_cancel_email_default_ticked('details', 'pending') === true, 'default tick: booking page -> always ticked');
check(rh_cancel_email_default_ticked('tentative', 'tentative') === true, 'default tick: tentative holds -> always ticked');
check(rh_cancel_email_choice([], true) === true, 'choice: field absent (old page) -> old behaviour (email)');
check(rh_cancel_email_choice([], false) === false, 'choice: field absent and old code would not email -> no email');
check(rh_cancel_email_choice(['email_guest' => '1'], true) === true, 'choice: ticked -> email');
check(rh_cancel_email_choice(['email_guest' => '0'], true) === false, 'choice: unticked -> no email');
check(rh_cancel_email_choice(['email_guest' => '1'], false) === false, 'choice: ticked never emails a booking the old code would not (pending)');
check(rh_cancel_email_choice(['email_guest' => ['0', '1']], true) === true, 'choice: hidden 0 + ticked box (array form) -> the last value wins');
check(rh_cancel_email_skipped_reason() === 'Not sent: staff chose not to email the guest', 'cancel log status words');

/* ── wording ── */
$row = ['sent_at' => '2026-11-12 10:42:05', 'status' => 'sent', 'to_email' => 'guest@x.com', 'email_kind' => 'booking_cancelled',
    'sent_by_label' => 'Mercy K. (receptionist)', 'account_ref' => 'RBH-77', 'account_type' => 'room', 'account_id' => 77];
check(rh_email_timeline_line($row) === '10:42 · Booking cancelled email to guest@x.com · by Mercy K. (receptionist) · RBH-77 · Sent', 'timeline line', rh_email_timeline_line($row));
check(rh_email_what($row) === 'Booking cancelled email sent to guest@x.com', 'history: sent');
check(rh_email_what(['status' => 'failed'] + $row) === 'Booking cancelled email could not be sent to guest@x.com', 'history: failed');
check(rh_email_what(['status' => 'not_sent'] + $row) === 'Booking cancelled email not sent to guest@x.com (staff chose not to)', 'history: not sent');
check(rh_email_what(['email_kind' => 'other'] + $row) === 'Email sent to guest@x.com', 'history: other kind');
check(rh_email_about_label('room', 'RBH-77', 77) === 'Room booking RBH-77', 'about: room booking');
check(rh_email_about_label('conference', '', 4) === 'Conference #4', 'about: id when no ref');
check(rh_email_about_label(null, null) === '', 'about: nothing');
$days = rh_email_group_by_day([$row, ['sent_at' => '2026-11-12 09:00:00'] + $row, ['sent_at' => '2026-11-11 18:00:00'] + $row]);
check(array_keys($days) === ['2026-11-12', '2026-11-11'] && count($days['2026-11-12']) === 2, 'timeline: grouped by day, newest first kept');

/* ── filters ── */
$f = rh_email_log_filters([], '2026-11-12');
check($f['from'] === '2026-11-06' && $f['to'] === '2026-11-12' && $f['view'] === 'table' && $f['kind'] === 'all' && $f['result'] === 'all', 'filters: default last 7 days, table');
$f = rh_email_log_filters(['em_from' => '2026-11-20', 'em_to' => '2026-11-01', 'em_kind' => 'invoice', 'em_result' => 'not_sent', 'em_view' => 'timeline', 'em_q' => '  RBH  '], '2026-11-12');
check($f['from'] === '2026-11-01' && $f['to'] === '2026-11-20' && $f['kind'] === 'invoice' && $f['result'] === 'not_sent' && $f['view'] === 'timeline' && $f['q'] === 'RBH',
    'filters: swapped dates fixed, kind/result/view/search kept');
$f = rh_email_log_filters(['em_from' => '2025-01-01', 'em_to' => '2026-11-12', 'em_kind' => 'drop table', 'em_result' => 'x', 'em_view' => 'x'], '2026-11-12');
check($f['clamped'] && $f['from'] === '2026-08-13' && $f['kind'] === 'all' && $f['result'] === 'all' && $f['view'] === 'table', 'filters: long range clamped, bad values ignored');
$f = rh_email_log_filters(['em_from' => ['x'], 'em_q' => ['y']], '2026-11-12');
check($f['from'] === '2026-11-06' && $f['q'] === '', 'filters: array values ignored');

/* ── history: one step per email ── */
check(rh_email_hist_matches('pre_arrival', ['comm:pre_arrival']), 'match: pre-arrival comm row (real stage key)');
check(rh_email_hist_matches('post_stay_review', ['comm:post_stay']), 'match: after-stay comm row (real stage key)');
check(rh_email_hist_matches('tentative_reminder', ['auto:tentative_hold_reminder:r1', 'reminder']), 'match: tentative reminder auto row');
check(rh_email_hist_matches('tentative_reminder', ['tent:reminder_sent']), 'match: tentative reminder sent by staff');
check(rh_email_hist_matches('receipt', ['receipt:PAY-1']), 'match: receipt');
check(rh_email_hist_matches('invoice', ['email:' . md5('Invoice email sent by admin (Mercy)'), 'email:invoice']), 'match: invoice audit record');
check(rh_email_hist_matches('unpaid_reminder', ['email:' . md5('Payment reminder sent'), 'reminder']), 'match: unpaid reminder timeline record');
check(!rh_email_hist_matches('invoice', ['email:' . md5('Invoice sent via WhatsApp to +265')]), 'match: a WhatsApp record is never an email');
check(!rh_email_hist_matches('booking_cancelled', ['receipt:PAY-1']), 'match: different kinds do not match');
check(!rh_email_hist_matches('', ['comm:pre_arrival']), 'match: an empty kind (not sent / preview) never matches');
// Near misses the old word match paired wrongly ('reminder', 'expired', 'status', 'change').
check(!rh_email_hist_matches('booking_reminder', ['reminder', 'auto:unpaid_confirmed_reminder:r1']), 'near miss: booking reminder is not the unpaid-booking reminder');
check(!rh_email_hist_matches('tentative_reminder', ['auto:unpaid_confirmed_reminder:r1', 'reminder']), 'near miss: hold reminder is not the unpaid reminder');
check(!rh_email_hist_matches('pending_expired', ['auto:tentative_expired_notice:e1']), 'near miss: unconfirmed-expired is not hold-expired');
check(!rh_email_hist_matches('payment_reminder', ['auto:unpaid_confirmed_reminder:r1']), 'near miss: overdue payment reminder is not the unpaid reminder');
check(!rh_email_hist_matches('booking_status_update', ['status:confirmed']), 'near miss: a status email never folds into a status change');
check(!rh_email_hist_matches('booking_modified', ['email:' . md5('booking_created')]), 'near miss: a changed-booking email is not the emails at booking');
check(!rh_email_hist_matches('receipt', ['receipt:']), 'near miss: a bare prefix key is not a record');

$staff = [9 => ['name' => 'Mercy Kamanga', 'username' => 'mercy', 'role' => 'receptionist']];
$bk = ['id' => 77, 'booking_reference' => 'RBH-77', 'status' => 'confirmed', 'created_at' => '2026-11-10 08:00:00', 'room_id' => 1,
    'number_of_nights' => 2, 'check_in_date' => '2026-11-20', 'check_out_date' => '2026-11-22', 'total_amount' => 100000, 'total_with_vat' => 100000];
$src = ['booking' => $bk, 'staff' => $staff, 'cur' => 'K',
    'comms' => [['stage' => 'pre_arrival', 'sent_at' => '2026-11-18 08:00:00', 'sent_to' => 'guest@x.com']],
    'email_log' => [
        ['id' => 1, 'sent_at' => '2026-11-18 08:01:30', 'status' => 'sent', 'to_email' => 'guest@x.com', 'cc' => 'front@hotel.mw (hidden)',
            'subject' => 'See you soon', 'email_kind' => 'pre_arrival', 'sent_by_label' => 'System (scheduler)', 'account_type' => 'room', 'account_id' => 77],
        ['id' => 2, 'sent_at' => '2026-11-12 10:42:05', 'status' => 'not_sent', 'to_email' => 'guest@x.com', 'cc' => null, 'subject' => '',
            'email_kind' => 'booking_cancelled', 'sent_by_label' => 'Mercy Kamanga (receptionist)', 'error' => 'Staff chose not to email the guest'],
        ['id' => 3, 'sent_at' => '2026-11-11 09:00:00', 'status' => 'failed', 'to_email' => 'guest@x.com', 'subject' => 'Your invoice',
            'email_kind' => 'invoice', 'sent_by_label' => 'Mercy Kamanga (receptionist)', 'error' => 'SMTP connect() failed'],
    ],
];
$ev = rh_hist_build($src);
$emails = array_values(array_filter($ev, function ($e) { return $e['kind'] === 'email'; }));
$pre = array_values(array_filter($emails, function ($e) { return stripos($e['what'], 'arrival') !== false; }));
check(count($pre) === 1, 'history: the before-arrival email shows once (send log folded into the older record)', json_encode(array_column($emails, 'what')));
check(isset($pre[0]) && strpos($pre[0]['detail'], 'Copy: front@hotel.mw (hidden).') !== false && $pre[0]['who'] === 'System (scheduler)',
    'history: the folded step gains the copies and who', json_encode($pre[0] ?? null));
$ns = array_values(array_filter($emails, function ($e) { return strpos($e['what'], 'not sent') !== false; }));
check(count($ns) === 1 && $ns[0]['who'] === 'Mercy Kamanga (receptionist)', 'history: the decision not to email shows, with who');
$fl = array_values(array_filter($emails, function ($e) { return strpos($e['what'], 'could not be sent') !== false; }));
check(count($fl) === 1 && strpos($fl[0]['detail'], 'Problem: SMTP connect() failed') !== false, 'history: a failed send shows with its problem');
check(!array_filter($ev, function ($e) { return isset($e['email_kind']) || isset($e['sendlog']); }), 'history: no internal keys leak to the screen');

// Outside the 2-minute window the two records stay apart.
$far = $src; $far['email_log'] = [$src['email_log'][0]]; $far['email_log'][0]['sent_at'] = '2026-11-18 08:05:00';
$farEmails = array_values(array_filter(rh_hist_build($far), function ($e) { return $e['kind'] === 'email'; }));
check(count($farEmails) === 2, 'history: more than 2 minutes apart -> two steps (two different emails)');
// Two sends, one older record: only the nearest folds.
$two = $src; $two['email_log'] = [$src['email_log'][0], ['id' => 9, 'sent_at' => '2026-11-18 08:00:40'] + $src['email_log'][0]];
$twoEmails = array_values(array_filter(rh_hist_build($two), function ($e) { return $e['kind'] === 'email'; }));
check(count($twoEmails) === 2, 'history: two sends and one older record -> one folded, one own step');
// No send-log rows: history unchanged.
$plain = $src; unset($plain['email_log']);
$plainEmails = array_values(array_filter(rh_hist_build($plain), function ($e) { return $e['kind'] === 'email'; }));
check(count($plainEmails) === 1 && $plainEmails[0]['who'] === 'System', 'history: without the log (before migration 081) nothing changes');

// Near miss inside a whole history: a booking-reminder send 30s after the scheduler's unpaid reminder stays apart;
// an invoice send folds into the invoice audit record, never into the WhatsApp one 20s away.
$nm = ['booking' => $bk, 'staff' => $staff, 'cur' => 'K',
    'auto_emails' => [['job' => 'unpaid_confirmed_reminder', 'stage' => 'r1', 'status' => 'sent', 'subject' => 'Please pay', 'recipient' => 'guest@x.com', 'sent_at' => '2026-11-13 09:00:00']],
    'audit' => [
        ['action' => 'email_sent', 'note' => 'Invoice sent via WhatsApp to +265999', 'performed_at' => '2026-11-14 10:00:20', 'performed_by' => 9],
        ['action' => 'email_sent', 'note' => 'Invoice email sent by admin (Mercy Kamanga)', 'performed_at' => '2026-11-14 10:01:10', 'performed_by' => 9],
    ],
    'email_log' => [
        ['id' => 21, 'sent_at' => '2026-11-13 09:00:30', 'status' => 'sent', 'to_email' => 'guest@x.com', 'subject' => 'Reminder', 'email_kind' => 'booking_reminder', 'sent_by_label' => 'Mercy Kamanga (receptionist)'],
        ['id' => 22, 'sent_at' => '2026-11-14 10:00:00', 'status' => 'sent', 'to_email' => 'guest@x.com', 'subject' => 'Your invoice', 'email_kind' => 'invoice', 'sent_by_label' => 'Mercy Kamanga (receptionist)'],
    ],
];
$nmEmails = array_values(array_filter(rh_hist_build($nm), function ($e) { return $e['kind'] === 'email'; }));
$nmWhat = array_column($nmEmails, 'what');
check(count(array_filter($nmWhat, function ($w) { return stripos($w, 'Booking reminder email sent') === 0; })) === 1
    && count(array_filter($nmWhat, function ($w) { return stripos($w, 'Automatic email sent') === 0; })) === 1,
    'near miss: booking reminder and the scheduler unpaid reminder 30s apart stay two steps', json_encode($nmWhat));
$nmInv = array_values(array_filter($nmEmails, function ($e) { return strpos($e['detail'], 'Subject: Your invoice') !== false; }));
check(count($nmInv) === 1 && strpos($nmInv[0]['detail'], 'Invoice email sent by admin') !== false && strpos($nmInv[0]['detail'], 'WhatsApp') === false,
    'invoice: the send folds into the invoice email record, not the WhatsApp record 20s nearer', json_encode($nmEmails));
check(count($nmEmails) === 4, 'near miss: four steps (unpaid reminder, booking reminder, WhatsApp invoice, emailed invoice)', json_encode($nmWhat));

/* ── test sends (Automated Emails "Send test", email template tests) ── */
check(rh_email_kind_label('test') === 'Test' && rh_email_kind_label('test_email') === 'Test', 'test: kind label (older test_email rows too)');
check(rh_email_what(['email_kind' => 'test', 'status' => 'sent', 'to_email' => 'me@hotel.mw']) === 'Test email sent to me@hotel.mw', 'test: reads "Test email"');
check(rh_email_log_filters(['em_kind' => 'test'], '2026-11-12')['kind'] === 'test', 'test: a kind filter of its own');
check(rh_email_test_apply([], 'invoice', ['account_type' => 'room', 'account_id' => 7, 'account_ref' => 'RBH-7'], 'Your invoice')
    === ['invoice', ['account_type' => 'room', 'account_id' => 7, 'account_ref' => 'RBH-7'], 'Your invoice'], 'test: a real send is unchanged');
$tOuter = rh_email_context(['is_test' => 1, 'test_of' => 'Tentative hold reminder']);
$tCtx = (function () { $emailCtx = rh_email_context(['kind' => 'tentative_reminder'] + rh_email_ctx_account('room', 0, 'SAMPLE-1001')); return rh_email_context_current(); })();
$tOuter = null;
rh_email_context_clear();
[$tk, $ta, $ts] = rh_email_test_apply($tCtx, (string)$tCtx['kind'], ['account_type' => $tCtx['account_type'] ?? null, 'account_id' => null, 'account_ref' => $tCtx['account_ref'] ?? null], 'Your hold ends soon');
check($tk === 'test' && $ta === ['account_type' => null, 'account_id' => null, 'account_ref' => null] && $ts === '[Test: Tentative hold reminder] Your hold ends soon',
    'test: the sample send (inner sender kind + SAMPLE-1001) is logged as a test with no account', json_encode([$tk, $ta, $ts]));
[$tk, , $ts] = rh_email_test_apply(['is_test' => 1], 'payment_reminder', [], 'Payment due');
check($tk === 'test' && $ts === '[Test: Payment reminder] Payment due', 'test: no label given -> the real kind names it');
[, , $ts] = rh_email_test_apply(['is_test' => 1], 'other', [], '[TEST] Booking confirmed');
check($ts === '[Test: email template] Booking confirmed', 'test: the template page\'s own [TEST] prefix is not doubled');
[, $ta] = rh_email_test_apply(['is_test' => 1], 'credit_note', ['account_type' => 'room', 'account_id' => 5, 'account_ref' => 'RBH-5'], 'x');
check($ta['account_id'] === null && $ta['account_ref'] === null, 'test: an account passed on the row is cleared too');
$jobsSrc = (string)file_get_contents($root . '/includes/auto-email-jobs.php');
check(strpos($jobsSrc, "rh_email_context(['is_test' => 1, 'test_of' => rh_auto_sample_keys()[\$key]])") !== false && strpos($jobsSrc, "'test_email'") === false,
    'test: Automated Emails "Send test" marks its context as a test');
$bsSrc = (string)file_get_contents($root . '/admin/booking-settings.php');
check(substr_count($bsSrc, "rh_email_context(['is_test' => 1") === 3, 'test: the three template/settings test sends are marked as tests');
$logSrc = (string)file_get_contents($root . '/includes/email-send-log.php');
check(strpos($logSrc, '[$kind, $acct, $subject] = rh_email_test_apply($ctx, $kind, $acct,') !== false, 'test: the writer applies the test rule last');

/* ── one row per send in preview mode ── */
$emailSrc = (string)file_get_contents($root . '/config/email.php');
$invSrc = (string)file_get_contents($root . '/config/invoice.php');
$unguarded = [];
$guarded = 0;
foreach (['config/email.php' => $emailSrc, 'config/invoice.php' => $invSrc] as $fn => $src) {
    $off = 0;
    while (($p = strpos($src, "rh_email_send_log_mail('failed'", $off)) !== false) {
        $off = $p + 10;
        $after = substr($src, $p, 700);
        $fnEnd = strpos($after, "\nfunction ");
        if ($fnEnd !== false) {
            $after = substr($after, 0, $fnEnd);
        }
        if (strpos($after, 'createEmailPreview(') === false) {
            continue; // no preview fallback after this failure: it must log 'failed'
        }
        $before = substr($src, max(0, $p - 120), min(120, $p));
        if (preg_match('/if \(!\$(development_mode|dev_mode)\) \{[^\n]*\s*$/', $before)) {
            $guarded++;
        } else {
            $unguarded[] = $fn . ' @' . substr_count(substr($src, 0, $p), "\n");
        }
    }
}
check(!$unguarded && $guarded === 4, 'preview: every failure with a preview fallback logs one row (preview), not failed + preview', json_encode([$guarded, $unguarded]));
check(strpos($emailSrc, "rh_email_send_log_write(['status' => 'preview'") !== false, 'preview: the preview itself is still logged');

/* ── quiet cancel: nothing to the guest, the hotel's WhatsApp alert still goes ── */
$waSrc = (string)file_get_contents($root . '/includes/whatsapp-functions.php');
check(strpos($waSrc, "function sendBookingCancelledWhatsApp(array \$booking, array \$room = [], string \$only = ''): array") !== false, 'quiet: WhatsApp cancel has a guest/hotel switch (default both)');
check(strpos($waSrc, "\$guestPhone  = \$only === 'hotel' ? '' :") !== false && strpos($waSrc, "\$hotelPhone  = \$only === 'guest' ? '' :") !== false, 'quiet: hotel-only never reaches the guest phone');
check(preg_match("/function sendBookingCancelledHotelAlert\(array \\\$booking\): array.*?isWhatsAppEnabled\(\).*?sendBookingCancelledWhatsApp\(\\\$booking, \\\$room, 'hotel'\)/s", $emailSrc) === 1,
    'quiet: the hotel alert helper sends the hotel part only, when WhatsApp is on');
$cxStart = strpos($emailSrc, 'function sendBookingCancelledEmail(');
$cxFn = $cxStart === false ? '' : substr($emailSrc, $cxStart, strpos($emailSrc, "\nfunction ", $cxStart + 10) - $cxStart);
check(preg_match("/if \(\\\$dbTemplate\) \{.*?sendEmailWithCC\(.*?sendBookingCancelledWhatsApp\(\\\$bookingForWhatsApp, \\\$room\).*?return \\\$emailResult;\s*\}/s", $cxFn) === 1,
    'cancel: a saved "Booking Cancelled" template still sends the guest and hotel WhatsApp');
check(substr_count($cxFn, 'sendBookingCancelledWhatsApp(') === 2 && substr_count($cxFn, 'return sendEmailWithCC(') === 0,
    'cancel: both email paths (saved template, built-in) send the WhatsApp; none returns before it');
$bdSrc = (string)file_get_contents($root . '/admin/booking-details.php');
$bkSrc2 = (string)file_get_contents($root . '/admin/bookings.php');
$tbSrc = (string)file_get_contents($root . '/admin/tentative-bookings.php');
check(substr_count($bdSrc, 'sendBookingCancelledHotelAlert(') === 2 && substr_count($bkSrc2, 'sendBookingCancelledHotelAlert(') === 1 && substr_count($tbSrc, 'sendBookingCancelledHotelAlert(') === 1,
    'quiet: every quiet branch (booking page single + group, All Bookings, holds) alerts the hotel');
foreach (['booking page' => $bdSrc, 'All Bookings' => $bkSrc2, 'holds' => $tbSrc] as $nm => $s) {
    check(preg_match("/rh_email_send_log_not_sent\('booking_cancelled'[^;]*;\s*(if \(rh_cancel_email_default_ticked\('list'[^\n]*\n\s*)?sendBookingCancelledHotelAlert\(/", $s) === 1, 'quiet: ' . $nm . ' alerts the hotel right after recording the decision');
}
// All Bookings: the box starts unticked for pending / tentative (never emailed before); cancelling those sends no new
// hotel WhatsApp. Only a box staff really unticked (old rule would email) is a quiet cancel.
check(preg_match("/if \(rh_cancel_email_default_ticked\('list', \(string\)\\\$cn\['previous_status'\]\)\) \{\s*sendBookingCancelledHotelAlert\(\\\$booking\);/", $bkSrc2) === 1,
    'quiet: All Bookings alerts the hotel only when the old rule would have emailed (not for pending / tentative)');

/* ── booking page cancel writes cancellation_log ── */
check(substr_count($bdSrc, 'logCancellationToDatabase(') === 2, 'cancel log: booking page single and group both write cancellation_log');
check(preg_match("/foreach \(\\\$grpRes\['cancelled'\] as \\\$gc\) \{.*?logCancellationToDatabase\(\(int\)\\\$gc\['id'\].*?\n\s{28}\}/s", $bdSrc) === 1, 'cancel log: one row per cancelled booking of a group (inside the loop)');
check(substr_count($bdSrc, "['success' => false, 'message' => rh_cancel_email_skipped_reason()]") === 2, 'cancel log: unticked = not emailed, with the "Not sent:" status words');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
