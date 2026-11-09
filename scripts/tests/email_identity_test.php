<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Email identity test (includes/email-identity.php): who an email is from, where replies go, who gets copies.
 * Usage (from the project root, TEST HOTEL ONLY): php scripts/tests/email_identity_test.php
 *
 * Rules under test (owner, 2026-11-09):
 *   - From is always the SMTP mailbox with the hotel's sender name; the mailbox appears in no other header and
 *     in no email text.
 *   - Reply-To = Email Settings "Reply-to and contact email", else Hotel Details main email, else none.
 *   - Hotel copies (invoice copies, the mailbox's own copy, receipt copies, booking CC list) are hidden copies:
 *     in the SMTP recipients, never in a Cc/To header.
 *   - The staff copy email gets exactly one copy of every email while the switch is on (guest emails and
 *     staff alerts), none extra when it is already a recipient.
 *   - Credit notes and contact-form replies are sent From the mailbox too.
 *   - The alert recipient resolvers follow the same fallback chains as before.
 *
 * Each scenario runs this same file as "the page" inside scripts/tests/page_harness.php: one outer transaction that
 * can never commit and is rolled back, with the SMTP settings pointed at scripts/tests/fake-smtp.php (capture only,
 * 127.0.0.1). Only made-up .test / .invalid addresses are used. Nothing is delivered, nothing is left in the
 * database, and credit-note PDFs written during the run are deleted at the end.
 */

if (isset($GLOBALS['__h_cfg'])) {
    // ===================================== CHILD (inside the harness) =====================================
    $prm = json_decode((string)($_POST['params'] ?? '{}'), true) ?: [];
    $sc = (string)($_POST['scenario'] ?? '');
    $ei_apply = static function (array $email, array $site): void {
        global $pdo;
        $es = $pdo->prepare("INSERT INTO email_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0");
        foreach ($email as $k => $v) { $es->execute([(string)$k, (string)$v]); }
        $ss = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($site as $k => $v) { $ss->execute([(string)$k, (string)$v]); }
        $GLOBALS['_SITE_SETTINGS'] = []; // getSetting() keeps values in memory for the request
    };
    $ei_apply((array)($prm['email'] ?? []), (array)($prm['site'] ?? []));
    $root = dirname(__DIR__, 2);
    require_once $root . '/config/email.php';
    require_once $root . '/config/invoice.php';
    require_once $root . '/config/credit-notes.php';

    $out = ['scenario' => $sc, 'results' => []];
    $g = 'guest@guest.test';
    try {
        if ($sc === 'guest') {
            $out['results']['send'] = sendEmail($g, 'EI Guest', 'EI basic', '<p>Hello</p>');
            $out['results']['invoice'] = sendEmailWithAttachmentAndCC($g, 'EI Guest', 'EI invoice', '<p>Invoice</p>', $root . '/scripts/tests/__no_such_file.pdf',
                ['accounts@hotel.test', (string)getEmailSetting('smtp_username', '')]);
            $out['results']['receipt'] = sendEmailWithAttachments($g, 'EI Guest', 'EI receipt', '<p>Receipt</p>', [], '', ['cc' => ['accounts@hotel.test', 'extra@company.test', $g]]);
            $out['results']['cclist'] = sendEmailWithCC($g, 'EI Guest', 'EI cclist', '<p>Cancelled</p>', '', ['cc1@hotel.test']);
            $out['results']['tostaff'] = sendEmail('staff@hotel.test', 'EI Staff', 'EI to staff', '<p>Staff</p>');
            $out['results']['reply'] = sendContactInquiryReplyEmail(['email' => $g, 'name' => 'EI Guest', 'subject' => 'Question', 'reference_number' => 'EICI1'], 'EI contact reply', 'Thank you');
        } elseif ($sc === 'reply_only') {
            $out['results']['send'] = sendEmail($g, 'EI Guest', 'EI basic', '<p>Hello</p>');
            $out['results']['reply'] = sendContactInquiryReplyEmail(['email' => $g, 'name' => 'EI Guest', 'subject' => 'Question', 'reference_number' => 'EICI2'], 'EI contact reply', 'Thank you');
            $out['contact'] = rh_guest_contact_email();
        } elseif ($sc === 'mailbox_staff') {
            $out['results']['send'] = sendEmail($g, 'EI Guest', 'EI basic', '<p>Hello</p>');
            $out['results']['invoice'] = sendEmailWithAttachmentAndCC($g, 'EI Guest', 'EI invoice', '<p>Invoice</p>', $root . '/scripts/tests/__no_such_file.pdf',
                ['accounts@hotel.test', (string)getEmailSetting('smtp_username', '')]);
        } elseif ($sc === 'alert') {
            $out['results']['alert'] = sendAdminNotificationEmail(['id' => 0, 'booking_reference' => 'EIALERT1', 'guest_name' => 'EI Guest', 'guest_email' => $g,
                'guest_phone' => '+265000000009', 'room_name' => 'EI Room', 'check_in_date' => date('Y-m-d', strtotime('+400 day')),
                'check_out_date' => date('Y-m-d', strtotime('+401 day')), 'number_of_guests' => 2, 'total_amount' => 100, 'status' => 'pending']);
        } elseif ($sc === 'credit_note') {
            $adminId = (int)$pdo->query("SELECT id FROM admin_users ORDER BY id LIMIT 1")->fetchColumn();
            $pdo->prepare("INSERT INTO credit_notes (credit_note_number, booking_type, guest_name, guest_email, original_amount, amount_used, balance, reason, status, issued_by, issued_at, expires_at)
                VALUES (?, 'goodwill', 'EI GUEST', ?, 10, 5, 5, 'goodwill', 'partially_applied', ?, NOW(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))")
                ->execute(['CNEI' . strtoupper(bin2hex(random_bytes(3))), $g, $adminId]);
            $out['results']['credit_note'] = sendCreditNoteEmail($pdo, (int)$pdo->lastInsertId());
        } elseif ($sc === 'resolvers') {
            $r = [];
            $ei_apply(['email_admin_email' => 'staff@hotel.test'], ['booking_notification_email' => 'res@hotel.test', 'admin_notification_email' => 'hd@hotel.test',
                'conference_email' => 'conf@hotel.test', 'gym_email' => 'gym@hotel.test', 'email_main' => 'main@hotel.test', 'email_restaurant' => 'food@hotel.test']);
            $r['own'] = [rh_booking_alert_recipient(), rh_conference_alert_recipient(), rh_gym_alert_recipient(), rh_restaurant_contact_recipient(), rh_staff_copy_recipient()];
            $ei_apply([], ['booking_notification_email' => '', 'conference_email' => 'not-an-email', 'gym_email' => '', 'email_restaurant' => '']);
            $r['level2'] = [rh_booking_alert_recipient(), rh_conference_alert_recipient(), rh_gym_alert_recipient(), rh_restaurant_contact_recipient()];
            $ei_apply(['email_admin_email' => 'staff@hotel.test'], ['admin_notification_email' => '', 'email_main' => '']);
            $r['level3'] = [rh_booking_alert_recipient(), rh_conference_alert_recipient(), rh_gym_alert_recipient()];
            $ei_apply(['email_admin_email' => ''], []);
            $r['none'] = [rh_booking_alert_recipient(), rh_staff_copy_recipient()];
            $out['resolvers'] = $r;
        }
    } catch (Throwable $e) {
        $out['error'] = get_class($e) . ': ' . $e->getMessage();
    }
    echo json_encode($out);
    return;
}

// ======================================== RUNNER ========================================
$root = dirname(__DIR__, 2);
chdir($root);
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

// ---- capture-only SMTP server (same as documents_flow.php) ----
$port = 0;
for ($try = 0; $try < 20 && !$port; $try++) {
    $cand = random_int(20000, 45000);
    $probe = @stream_socket_server('tcp://127.0.0.1:' . $cand, $e1, $e2);
    if ($probe) { fclose($probe); $port = $cand; }
}
if (!$port) { echo "[FAIL] no free local port for the capture SMTP server\n"; exit(1); }
$spool = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_email_identity_' . bin2hex(random_bytes(4)) . '.jsonl';
@unlink($spool . '.ready');
$smtp = proc_open([PHP_BINARY, $root . '/scripts/tests/fake-smtp.php', (string)$port, $spool], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/rh_ei_smtp.out', 'a'], 2 => ['file', sys_get_temp_dir() . '/rh_ei_smtp.err', 'a']], $pipes);
for ($i = 0; $i < 50 && !is_file($spool . '.ready'); $i++) { usleep(100000); }
if (!is_resource($smtp) || !is_file($spool . '.ready')) { echo "[FAIL] capture SMTP server did not start\n"; exit(1); }

function ei_files(string $root): array {
    $set = [];
    foreach (glob($root . '/invoices/credit-notes/*') ?: [] as $f) { if (is_file($f)) { $set[$f] = true; } }
    return $set;
}
$filesBefore = ei_files($root);
register_shutdown_function(function () use (&$smtp, $spool, $root, $filesBefore) {
    if (is_resource($smtp)) { proc_terminate($smtp); proc_close($smtp); }
    @unlink($spool); @unlink($spool . '.ready');
    foreach (array_diff_key(ei_files($root), $filesBefore) as $f => $_) { @unlink($f); }
});

$spoolPos = 0;
function ei_new_emails(): array {
    global $spool, $spoolPos;
    clearstatcache();
    $out = [];
    if (is_file($spool)) {
        $lines = file($spool, FILE_IGNORE_NEW_LINES) ?: [];
        foreach (array_slice($lines, $spoolPos) as $l) { $j = json_decode($l, true); if (is_array($j)) { $out[] = $j; } }
        $spoolPos = count($lines);
    }
    return $out;
}
function ei_run(string $scenario, array $params): array {
    global $harness, $port;
    $cfg = base64_encode(json_encode(['post' => ['scenario' => $scenario, 'params' => json_encode($params)], 'fake_smtp' => $port]));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg('scripts/tests/email_identity_test.php') . ' ' . escapeshellarg($cfg) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    $res['emails'] = ei_new_emails();
    return $res;
}
function ei_clean(array $r): bool { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); }
function ei_by_subject(array $emails, string $subject): array { return array_values(array_filter($emails, fn($m) => strpos((string)$m['subject'], $subject) !== false)); }
function ei_rcpt(array $m): array { return array_map('strtolower', (array)($m['to'] ?? [])); }
function ei_count_rcpt(array $emails, string $addr): int {
    $n = 0;
    foreach ($emails as $m) { foreach (ei_rcpt($m) as $a) { if ($a === strtolower($addr)) { $n++; } } }
    return $n;
}
/** Every header except From, plus the readable text: the mailbox must appear in none of it. */
function ei_mailbox_hidden(array $m, string $mailbox): bool {
    $headers = preg_replace('/^From:.*$/mi', '', (string)($m['headers'] ?? ''));
    return stripos((string)$headers, $mailbox) === false && stripos((string)($m['text'] ?? ''), $mailbox) === false;
}
function ei_show(array $m): string { return json_encode(['to' => $m['to'] ?? null, 'from' => $m['from'] ?? null, 'reply_to' => $m['reply_to'] ?? null, 'cc' => $m['cc'] ?? null, 'to_header' => $m['to_header'] ?? null]); }

$MAILBOX = 'fake@example.invalid'; // what page_harness points smtp_username at
$base = ['email_from_email' => 'contact@hotel.test', 'email_from_name' => 'EI Test Hotel', 'email_admin_email' => 'staff@hotel.test', 'email_bcc_admin' => '1',
         'invoice_recipients' => 'accounts@hotel.test'];

echo "== guest emails: From, Reply-To, hidden copies ==\n";
$r = ei_run('guest', ['email' => $base, 'site' => ['email_main' => 'main@hotel.test', 'phone_main' => '']]);
ok('guest scenario rolled back', ei_clean($r), json_encode(['rb' => $r['rolled_back'] ?? null, 'err' => $r['json']['error'] ?? ($r['raw'] ?? '')]));
$res = (array)($r['json']['results'] ?? []);
foreach (['send', 'invoice', 'receipt', 'cclist', 'tostaff', 'reply'] as $k) {
    ok("send '$k' succeeded", !empty($res[$k]['success']), json_encode($res[$k] ?? ($r['json']['error'] ?? $r['raw'] ?? '')));
}
$E = $r['emails'];
$basic = ei_by_subject($E, 'EI basic')[0] ?? [];
ok('capture decodes the email text (so the text checks below mean something)', stripos((string)($basic['text'] ?? ''), 'Hello') !== false, substr((string)($basic['text'] ?? ''), 0, 200));
ok('From = SMTP mailbox with the sender name', stripos((string)($basic['from'] ?? ''), $MAILBOX) !== false && stripos((string)($basic['from'] ?? ''), 'EI Test Hotel') !== false, ei_show($basic));
ok('Reply-To = Reply-to and contact email', stripos((string)($basic['reply_to'] ?? ''), 'contact@hotel.test') !== false, ei_show($basic));
ok('staff copy email gets a hidden copy of a guest email', in_array('staff@hotel.test', ei_rcpt($basic), true) && stripos((string)($basic['headers'] ?? ''), 'staff@hotel.test') === false, ei_show($basic));

$inv = ei_by_subject($E, 'EI invoice')[0] ?? [];
ok('invoice: hotel copy + mailbox copy are delivered', in_array('accounts@hotel.test', ei_rcpt($inv), true) && in_array($MAILBOX, ei_rcpt($inv), true), ei_show($inv));
ok('invoice: no Cc header, To header is the guest only', ($inv['cc'] ?? 'x') === '' && stripos((string)($inv['to_header'] ?? ''), 'accounts@') === false && stripos((string)($inv['to_header'] ?? ''), 'guest@guest.test') !== false, ei_show($inv));

$rec = ei_by_subject($E, 'EI receipt')[0] ?? [];
ok('receipt copies are hidden copies', in_array('accounts@hotel.test', ei_rcpt($rec), true) && in_array('extra@company.test', ei_rcpt($rec), true) && ($rec['cc'] ?? 'x') === '', ei_show($rec));
ok('receipt: the guest is not also a copy', ei_count_rcpt([$rec], 'guest@guest.test') === 1, ei_show($rec));

$ccl = ei_by_subject($E, 'EI cclist')[0] ?? [];
ok('booking CC list (getCCEmails) is hidden', in_array('cc1@hotel.test', ei_rcpt($ccl), true) && ($ccl['cc'] ?? 'x') === '', ei_show($ccl));

$ts = ei_by_subject($E, 'EI to staff')[0] ?? [];
ok('email to the staff copy email itself: one copy only', ei_count_rcpt([$ts], 'staff@hotel.test') === 1, ei_show($ts));

$rp = ei_by_subject($E, 'EI contact reply')[0] ?? [];
ok('contact-form reply: From = SMTP mailbox', stripos((string)($rp['from'] ?? ''), $MAILBOX) !== false, ei_show($rp));
ok('contact-form reply: Reply-To = contact email', stripos((string)($rp['reply_to'] ?? ''), 'contact@hotel.test') !== false, ei_show($rp));

foreach ([$basic, $inv, $rec, $ccl, $rp] as $m) {
    ok('mailbox appears in no header but From, and in no text: ' . ($m['subject'] ?? '?'), $m !== [] && ei_mailbox_hidden($m, $MAILBOX), ei_show($m));
}

echo "== Reply-to and contact email left blank ==\n";
$r = ei_run('reply_only', ['email' => ['email_from_email' => ''] + $base, 'site' => ['email_main' => 'main@hotel.test']]);
$b = ei_by_subject($r['emails'], 'EI basic')[0] ?? [];
ok('blank contact email: Reply-To = Hotel Details main email', ei_clean($r) && stripos((string)($b['reply_to'] ?? ''), 'main@hotel.test') !== false, ei_show($b));

$r = ei_run('reply_only', ['email' => ['email_from_email' => ''] + $base, 'site' => ['email_main' => '']]);
$b = ei_by_subject($r['emails'], 'EI basic')[0] ?? [];
$rp = ei_by_subject($r['emails'], 'EI contact reply')[0] ?? [];
ok('both blank: no Reply-To header at all', ei_clean($r) && $b !== [] && ($b['reply_to'] ?? 'x') === '', ei_show($b));
ok('both blank: contact-form reply still sent, From = mailbox, no contact line', !empty($r['json']['results']['reply']['success']) && stripos((string)($rp['from'] ?? ''), $MAILBOX) !== false
    && stripos((string)($rp['text'] ?? ''), 'mailto:') === false && ei_mailbox_hidden($rp, $MAILBOX), json_encode($r['json']['results']['reply'] ?? null) . ' ' . ei_show($rp));
ok('both blank: rh_guest_contact_email() is empty', ($r['json']['contact'] ?? 'x') === '', json_encode($r['json']['contact'] ?? null));

$r = ei_run('reply_only', ['email' => ['email_from_email' => strtoupper($MAILBOX)] + $base, 'site' => ['email_main' => 'main@hotel.test']]);
$b = ei_by_subject($r['emails'], 'EI basic')[0] ?? [];
ok('contact email typed as the mailbox: settings decide, Reply-To = mailbox', ei_clean($r) && stripos((string)($b['reply_to'] ?? ''), $MAILBOX) !== false && stripos((string)($b['cc'] ?? ''), $MAILBOX) === false, ei_show($b));

echo "== real setup: the staff copy email IS the sending mailbox ==\n";
$r = ei_run('mailbox_staff', ['email' => ['email_admin_email' => $MAILBOX] + $base, 'site' => ['email_main' => 'main@hotel.test']]);
$b = ei_by_subject($r['emails'], 'EI basic')[0] ?? [];
$i = ei_by_subject($r['emails'], 'EI invoice')[0] ?? [];
ok('mailbox as staff copy: guest email copied to it once, hidden', ei_clean($r) && ei_count_rcpt([$b], $MAILBOX) === 1 && ei_mailbox_hidden($b, $MAILBOX), ei_show($b));
ok('mailbox as staff copy: invoice copy + staff copy deduped to one, hidden', ei_count_rcpt([$i], $MAILBOX) === 1 && ei_mailbox_hidden($i, $MAILBOX) && ($i['cc'] ?? 'x') === '', ei_show($i));

echo "== staff alerts: the staff copy email gets exactly one copy ==\n";
$alertSite = ['booking_notification_email' => 'res@hotel.test', 'booking_notification_cc_emails' => 'mgr@hotel.test'];
$r = ei_run('alert', ['email' => $base, 'site' => $alertSite]);
$A = ei_by_subject($r['emails'], 'EIALERT1');
ok('alert: primary + CC copy sent', ei_clean($r) && count($A) === 2 && ei_count_rcpt($A, 'res@hotel.test') === 1 && ei_count_rcpt($A, 'mgr@hotel.test') === 1, json_encode(array_map('ei_show', $A)));
ok('alert: staff copy email gets one hidden copy (switch on)', ei_count_rcpt($A, 'staff@hotel.test') === 1, json_encode(array_map('ei_show', $A)));
$footerOk = true;
foreach ($A as $m) { $footerOk = $footerOk && stripos((string)$m['text'], 'reservations@') === false; }
ok('alert footer no longer names a made-up reservations@ address', $footerOk);
$primary = array_values(array_filter($A, fn($m) => in_array('res@hotel.test', ei_rcpt($m), true)))[0] ?? [];
ok('alert footer names the real recipient', stripos((string)($primary['text'] ?? ''), 'res@hotel.test') !== false);

$r = ei_run('alert', ['email' => $base, 'site' => ['booking_notification_cc_emails' => 'mgr@hotel.test, staff@hotel.test'] + $alertSite]);
$A = ei_by_subject($r['emails'], 'EIALERT1');
ok('alert: staff copy email on the CC list gets its own copy, no extra hidden one', ei_clean($r) && count($A) === 3 && ei_count_rcpt($A, 'staff@hotel.test') === 1, json_encode(array_map('ei_show', $A)));

$r = ei_run('alert', ['email' => ['email_bcc_admin' => '0'] + $base, 'site' => $alertSite]);
$A = ei_by_subject($r['emails'], 'EIALERT1');
ok('alert: switch off, no staff copy', ei_clean($r) && count($A) === 2 && ei_count_rcpt($A, 'staff@hotel.test') === 0, json_encode(array_map('ei_show', $A)));

$r = ei_run('alert', ['email' => $base, 'site' => ['booking_notification_email' => 'staff@hotel.test', 'booking_notification_cc_emails' => '']]);
$A = ei_by_subject($r['emails'], 'EIALERT1');
ok('alert: staff copy email is the alert recipient, one copy', ei_clean($r) && count($A) === 1 && ei_count_rcpt($A, 'staff@hotel.test') === 1, json_encode(array_map('ei_show', $A)));

echo "== credit note ==\n";
$r = ei_run('credit_note', ['email' => $base, 'site' => ['email_main' => 'main@hotel.test']]);
$C = ei_by_subject($r['emails'], 'Credit Note')[0] ?? ($r['emails'][0] ?? []);
ok('credit note sent', ei_clean($r) && !empty($r['json']['results']['credit_note']['success']), json_encode($r['json']['results']['credit_note'] ?? ($r['json']['error'] ?? $r['raw'] ?? '')));
ok('credit note: From = SMTP mailbox, Reply-To = contact email', stripos((string)($C['from'] ?? ''), $MAILBOX) !== false && stripos((string)($C['reply_to'] ?? ''), 'contact@hotel.test') !== false, ei_show($C));
ok('credit note: hotel copy hidden, no Cc header', in_array('accounts@hotel.test', ei_rcpt($C), true) && ($C['cc'] ?? 'x') === '', ei_show($C));
ok('credit note: mailbox in no header but From, and in no text', $C !== [] && ei_mailbox_hidden($C, $MAILBOX), ei_show($C));

echo "== recipient resolvers (same chains as the sending code) ==\n";
$r = ei_run('resolvers', ['email' => $base, 'site' => []]);
$R = (array)($r['json']['resolvers'] ?? []);
$em = static fn(array $list): array => array_map(fn($x) => (string)($x['email'] ?? ''), $list);
ok('resolvers rolled back', ei_clean($r), json_encode($r['json']['error'] ?? ($r['raw'] ?? '')));
ok('own boxes win', $em((array)($R['own'] ?? [])) === ['res@hotel.test', 'conf@hotel.test', 'gym@hotel.test', 'food@hotel.test', 'staff@hotel.test'], json_encode($R['own'] ?? null));
ok('blank/invalid box: Hotel Details staff notification / main email', $em((array)($R['level2'] ?? [])) === ['hd@hotel.test', 'main@hotel.test', 'main@hotel.test', ''], json_encode($R['level2'] ?? null));
ok('blank box says where it falls back to', strpos((string)($R['level2'][0]['source'] ?? ''), 'Hotel Details') !== false, json_encode($R['level2'][0] ?? null));
ok('last fallback: staff copy email', $em((array)($R['level3'] ?? [])) === ['staff@hotel.test', 'staff@hotel.test', 'staff@hotel.test'], json_encode($R['level3'] ?? null));
ok('nothing set: no alert recipient; staff copies fall back to the mailbox', $em((array)($R['none'] ?? [])) === ['', $MAILBOX], json_encode($R['none'] ?? null));

echo "\nemail_identity: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
