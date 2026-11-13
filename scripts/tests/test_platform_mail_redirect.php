<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Test platform mail guard (includes/test-platform-mail.php, security patch 2026-11-14).
 *
 *   php scripts/tests/test_platform_mail_redirect.php        pure checks, no database (any hotel)
 *   php scripts/tests/test_platform_mail_redirect.php --db   + real mail paths through scripts/tests/page_harness.php
 *                                                            (one rolled-back transaction, SMTP pointed at the
 *                                                            capture-only scripts/tests/fake-smtp.php). Refused when
 *                                                            DB_NAME contains 'rosalyn' (Rosalyn's is live).
 * Nothing is delivered anywhere and nothing is left in the database.
 */

// ---- harness child: runs inside page_harness.php (rolled-back transaction, fake SMTP) -------------------------
if (isset($GLOBALS['__h_cfg']) && ($_POST['tp_child'] ?? '') === '1') {
    $out = (string)($_POST['out'] ?? '');
    $res = ['steps' => []];
    try {
        if (stripos((string)DB_NAME, 'rosalyn') !== false) { throw new RuntimeException('refused: Rosalyn\'s database'); }
        $es = $pdo->prepare("INSERT INTO email_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0");
        foreach (['email_bcc_admin' => '1', 'email_admin_email' => 'staff-copy@example.invalid', 'invoice_recipients' => 'hotel-copy@example.invalid'] as $k => $v) { $es->execute([$k, $v]); }
        require_once $GLOBALS['root'] . '/config/email.php';
        require_once $GLOBALS['root'] . '/config/invoice.php';
        require_once $GLOBALS['root'] . '/includes/report-mailer.php';
        global $smtp_host, $email_bcc_admin;
        if ($smtp_host !== '127.0.0.1') { throw new RuntimeException('SMTP not on the capture server - aborted before any send'); }
        $res['bcc_on'] = (bool)$email_bcc_admin;
        $S = &$GLOBALS['_SITE_SETTINGS'];
        $set = static function (array $kv) use (&$S) { foreach ($kv as $k => $v) { $S[$k] = $v; } };
        $set(['site_is_test_platform' => '1', 'automated_email_test_recipient' => 'tp-test@example.invalid', 'site_test_platform_label' => 'Liwonde']);
        $res['mode'] = rh_test_platform_mode();
        $logMax = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM email_send_log")->fetchColumn();
        $tmpPdf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tp_invoice_' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($tmpPdf, "%PDF-1.4\n% test\n");
        $roomId = (int)$pdo->query("SELECT id FROM rooms ORDER BY id LIMIT 1")->fetchColumn();
        $booking = ['id' => 0, 'room_id' => $roomId, 'guest_name' => 'Guest Test', 'guest_email' => 'guest-status@example.invalid', 'booking_reference' => 'TPTEST1',
            'check_in_date' => date('Y-m-d'), 'check_out_date' => date('Y-m-d', strtotime('+1 day')), 'number_of_nights' => 1, 'total_amount' => 0];

        $r = sendEmailWithAttachments('guest-receipt@example.invalid', 'Guest Test', 'Payment receipt TPRCPT1', '<p>Receipt</p>',
            [['name' => 'receipt.pdf', 'mime' => 'application/pdf', 'content' => "%PDF-1.4\n"]], '', ['cc' => ['accounts-cc@example.invalid']]);
        $res['steps']['receipt'] = !empty($r['success']);
        $r = sendSimpleStatusUpdateEmail($booking, 'confirmed');
        $res['steps']['status'] = !empty($r['success']);
        $r = sendEmailWithAttachmentAndCC('guest-invoice@example.invalid', 'Guest Test', 'Invoice TPINV1', '<p>Invoice</p>', $tmpPdf, ['finance-cc@example.invalid']);
        $res['steps']['invoice'] = !empty($r['success']);
        // Scheduled job email (scheduler context, its own test mode off) and the report mailer.
        $GLOBALS['rh_automated_ctx'] = ['redirect' => '', 'bcc' => true];
        $r = sendEmail('guest-scheduled@example.invalid', 'Guest Test', 'Hold reminder TPSCHED1', '<p>Reminder</p>');
        $res['steps']['scheduled'] = !empty($r['success']);
        // Scheduler's own test mode on as well: the real guest is still listed as the original To.
        $GLOBALS['rh_automated_ctx'] = ['redirect' => 'tp-test@example.invalid', 'bcc' => false];
        $r = sendEmail('guest-sched2@example.invalid', 'Guest Test', 'Hold reminder TPSCHED2', '<p>Reminder</p>');
        $res['steps']['scheduled_testmode'] = !empty($r['success']);
        unset($GLOBALS['rh_automated_ctx']);
        $r = sendReportEmail([['email' => 'manager-report@example.invalid', 'name' => 'Manager']], 'Daily report TPREP1', '<p>Report</p>', [], '', ['owner-cc@example.invalid']);
        $res['steps']['report'] = !empty($r['success']);

        // Fail closed: test platform on, test address unusable -> nothing is sent to anyone.
        $set(['automated_email_test_recipient' => 'not-an-email']);
        $GLOBALS['rh_email_fast_fail'] = true;
        $r = sendEmail('guest-failclosed@example.invalid', 'Guest Test', 'Fail closed TPFAIL1', '<p>x</p>');
        $res['steps']['failclosed_refused'] = empty($r['success']);
        unset($GLOBALS['rh_email_fast_fail']);

        // WhatsApp refused on the test platform (provider without a key: no network even if the guard failed).
        $set(['automated_email_test_recipient' => 'tp-test@example.invalid', 'whatsapp_enabled' => '1', 'whatsapp_provider' => 'callmebot', 'whatsapp_callmebot_api_key' => '', 'whatsapp_log_enabled' => '0']);
        $w = sendWhatsAppMessage('+265999000111', 'test');
        $res['whatsapp'] = $w;

        // Setting off (as at Rosalyn's): unchanged behaviour - real To + hidden copy, no prefix.
        $set(['site_is_test_platform' => '0', 'whatsapp_enabled' => '0']);
        $r = sendEmail('guest-off@example.invalid', 'Guest Test', 'Normal email TPOFF1', '<p>Normal</p>');
        $res['steps']['off'] = !empty($r['success']);

        $st = $pdo->prepare("SELECT status, to_email, cc, subject FROM email_send_log WHERE id > ? ORDER BY id");
        $st->execute([$logMax]);
        $res['log'] = $st->fetchAll(PDO::FETCH_ASSOC);
        @unlink($tmpPdf);
    } catch (Throwable $e) {
        $res['error'] = $e->getMessage();
    }
    file_put_contents($out, json_encode($res));
    return;
}

$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

// ---- pure checks (no database) ---------------------------------------------------------------------------------
require_once $root . '/PHPMailer/src/Exception.php';
require_once $root . '/PHPMailer/src/PHPMailer.php';
require_once $root . '/PHPMailer/src/SMTP.php';
require_once $root . '/includes/test-platform-mail.php';
use PHPMailer\PHPMailer\PHPMailer;

ok('flag missing/empty = off', rh_test_platform_decide('', 'a@example.invalid') === ['active' => false, 'to' => '']);
ok('flag 0 = off', rh_test_platform_decide('0', 'a@example.invalid') === ['active' => false, 'to' => '']);
ok('flag other text = off', rh_test_platform_decide('yes', 'a@example.invalid')['active'] === false);
ok('flag 1 + valid recipient = on', rh_test_platform_decide('1', ' a@example.invalid ') === ['active' => true, 'to' => 'a@example.invalid']);
ok('flag 1 + bad recipient = on, no address (fail closed)', rh_test_platform_decide('1', 'nope') === ['active' => true, 'to' => '']);
ok('flag 1 + empty recipient = fail closed', rh_test_platform_decide('1', '') === ['active' => true, 'to' => '']);
ok('no getSetting = off', function_exists('getSetting') || rh_test_platform_mode()['active'] === false);
ok('subject prefixed once', rh_test_platform_subject(rh_test_platform_subject('Receipt', 'Liwonde'), 'Liwonde') === '[TEST - Liwonde] Receipt');
ok('scheduler [TEST] folded in', rh_test_platform_subject('[TEST] Reminder', 'Liwonde') === '[TEST - Liwonde] Reminder');
ok('notice escapes addresses', strpos(rh_test_platform_notice_html(['to' => ['<b>@x']]), '&lt;b&gt;@x') !== false);

$mk = static function (): PHPMailer {
    $m = new PHPMailer(true);
    $m->addAddress('guest@example.invalid', 'Guest');
    $m->addCC('cc@example.invalid');
    $m->addBCC('staff@example.invalid');
    $m->isHTML(true);
    $m->Subject = 'Your receipt';
    $m->Body = '<html><body><p>Hi</p></body></html>';
    $m->AltBody = 'Hi';
    return $m;
};
// Off (Rosalyn's): untouched.
$m = $mk();
ok('off: apply returns false', rh_test_platform_mail_apply($m, ['active' => false, 'to' => 'x@example.invalid']) === false);
ok('off: recipients unchanged', count($m->getToAddresses()) === 1 && count($m->getCcAddresses()) === 1 && count($m->getBccAddresses()) === 1);
ok('off: subject/body unchanged', $m->Subject === 'Your receipt' && $m->Body === '<html><body><p>Hi</p></body></html>' && $m->AltBody === 'Hi');
ok('off: not in the redirect registry', rh_test_platform_mail_original($m) === null);
// On.
$m = $mk();
$mode = ['active' => true, 'to' => 'tp@example.invalid'];
ok('on: apply returns true', rh_test_platform_mail_apply($m, $mode) === true);
$toList = array_map(static function ($a) { return $a[0]; }, $m->getToAddresses());
ok('on: only the test recipient', $toList === ['tp@example.invalid'] && !$m->getCcAddresses() && !$m->getBccAddresses());
ok('on: subject prefixed', strpos($m->Subject, '[TEST - ') === 0 && substr($m->Subject, -12) === 'Your receipt');
ok('on: html notice after <body>', strpos($m->Body, '<body><!--rh-test-platform-->') !== false && strpos($m->Body, 'guest@example.invalid') !== false && strpos($m->Body, 'staff@example.invalid') !== false);
ok('on: text notice on top', strpos($m->AltBody, '*** TEST PLATFORM') === 0 && strpos($m->AltBody, 'Original CC: cc@example.invalid') !== false);
// Idempotent; an address added after the first redirect is caught too.
$m->addBCC('late@example.invalid');
rh_test_platform_mail_apply($m, $mode);
$orig = rh_test_platform_mail_original($m);
ok('again: still only the test recipient', count($m->getToAddresses()) === 1 && !$m->getBccAddresses());
ok('again: originals kept + late copy added', $orig['to'] === ['guest@example.invalid'] && $orig['bcc'] === ['staff@example.invalid', 'late@example.invalid']);
ok('again: one notice, one prefix', substr_count($m->Body, '<!--rh-test-platform-->') === 1 && substr_count($m->AltBody, '*** TEST PLATFORM') === 1 && substr_count($m->Subject, '[TEST - ') === 1);
// Logo-hook form: notice goes into the given HTML.
$m = $mk();
$html = '<p>doc</p>';
rh_test_platform_mail_apply($m, $mode, $html);
ok('hook form: notice in the passed html', strpos($html, '<!--rh-test-platform-->') === 0 && strpos($html, '<p>doc</p>') !== false);
// Fail closed.
$m = $mk();
rh_test_platform_mail_apply($m, ['active' => true, 'to' => '']);
ok('fail closed: no recipients left', !$m->getToAddresses() && !$m->getCcAddresses() && !$m->getBccAddresses());
ok('log text lists originals', rh_test_platform_log_text(['to' => ['a@x'], 'cc' => [], 'bcc' => ['b@x']]) === 'TEST PLATFORM redirect - originally To: a@x; CC: (none); BCC: b@x');

// ---- database / real mail paths (Liwonde only) -----------------------------------------------------------------
if (in_array('--db', $argv, true)) {
    require_once $root . '/config/database.php';
    if (stripos((string)DB_NAME, 'rosalyn') !== false) {
        echo "[REFUSED] --db never runs against Rosalyn's (live) database\n";
        exit(1);
    }
    $port = 0;
    for ($try = 0; $try < 20 && !$port; $try++) {
        $cand = random_int(20000, 45000);
        $probe = @stream_socket_server('tcp://127.0.0.1:' . $cand, $e1, $e2);
        if ($probe) { fclose($probe); $port = $cand; }
    }
    $spool = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_tp_mail_' . bin2hex(random_bytes(4)) . '.jsonl';
    $outFile = $spool . '.out.json';
    $smtp = proc_open([PHP_BINARY, $root . '/scripts/tests/fake-smtp.php', (string)$port, $spool], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/rh_tp_smtp.out', 'a'], 2 => ['file', sys_get_temp_dir() . '/rh_tp_smtp.err', 'a']], $pipes);
    for ($i = 0; $i < 50 && !is_file($spool . '.ready'); $i++) { usleep(100000); }
    if (!$port || !is_resource($smtp) || !is_file($spool . '.ready')) { echo "[FAIL] capture SMTP server did not start\n"; exit(1); }
    register_shutdown_function(static function () use (&$smtp, $spool, $outFile) {
        if (is_resource($smtp)) { proc_terminate($smtp); proc_close($smtp); }
        @unlink($spool); @unlink($spool . '.ready'); @unlink($outFile);
    });
    $cfg = base64_encode(json_encode(['post' => ['tp_child' => '1', 'out' => $outFile], 'fake_smtp' => $port]));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' ' . escapeshellarg('scripts/tests/test_platform_mail_redirect.php') . ' ' . escapeshellarg($cfg) . ' 2>&1';
    $raw = (string)shell_exec($cmd);
    $h = preg_match('/@@RESULT@@(.*)$/m', $raw, $mm) ? (array)json_decode($mm[1], true) : [];
    ok('harness rolled back, no leak', !empty($h['rolled_back']) && empty($h['leak']) && empty($h['marker_lost']), substr($raw, -400));
    $res = is_file($outFile) ? (array)json_decode((string)file_get_contents($outFile), true) : [];
    ok('child ran', $res && empty($res['error']), (string)($res['error'] ?? substr($raw, -400)));
    ok('staff copy switch on in the run', !empty($res['bcc_on']));
    foreach (['receipt', 'status', 'invoice', 'scheduled', 'scheduled_testmode', 'report', 'off'] as $k) { ok("send ok: $k", !empty($res['steps'][$k])); }
    ok('fail closed: send refused', !empty($res['steps']['failclosed_refused']));
    ok('WhatsApp refused on the test platform', empty($res['whatsapp']['success']) && stripos((string)($res['whatsapp']['message'] ?? ''), 'test platform') !== false, json_encode($res['whatsapp'] ?? null));

    clearstatcache();
    $mails = [];
    foreach ((is_file($spool) ? file($spool, FILE_IGNORE_NEW_LINES) : []) as $l) { $j = json_decode($l, true); if (is_array($j)) { $mails[] = $j; } }
    $find = static function (string $tag) use ($mails): ?array { foreach ($mails as $m) { if (strpos((string)$m['subject'], $tag) !== false) { return $m; } } return null; };
    $expect = [
        'TPRCPT1' => ['guest-receipt@example.invalid', 'accounts-cc@example.invalid', 'staff-copy@example.invalid'],
        'TPTEST1' => ['guest-status@example.invalid', 'staff-copy@example.invalid'],
        'TPINV1' => ['guest-invoice@example.invalid', 'finance-cc@example.invalid', 'staff-copy@example.invalid'],
        'TPSCHED1' => ['guest-scheduled@example.invalid', 'staff-copy@example.invalid'],
        'TPSCHED2' => ['guest-sched2@example.invalid'],
        'TPREP1' => ['manager-report@example.invalid', 'owner-cc@example.invalid'],
    ];
    foreach ($expect as $tag => $origs) {
        $m = $find($tag);
        ok("$tag captured", $m !== null);
        if ($m === null) { continue; }
        ok("$tag: delivered ONLY to the test recipient", $m['to'] === ['tp-test@example.invalid'], json_encode($m['to']));
        ok("$tag: no visible CC", trim((string)$m['cc']) === '');
        ok("$tag: subject prefix [TEST - Liwonde]", strpos((string)$m['subject'], '[TEST - Liwonde] ') === 0, (string)$m['subject']);
        $missing = array_values(array_filter($origs, static function ($a) use ($m) { return strpos((string)$m['text'], $a) === false; }));
        ok("$tag: original recipients listed in the body", !$missing, 'missing ' . implode(', ', $missing));
    }
    ok('fail closed: nothing captured', $find('TPFAIL1') === null);
    $off = $find('TPOFF1');
    ok('setting off: real To + hidden copy, no prefix (unchanged)', $off !== null && in_array('guest-off@example.invalid', $off['to'], true)
        && in_array('staff-copy@example.invalid', $off['to'], true) && !in_array('tp-test@example.invalid', $off['to'], true)
        && strpos((string)$off['subject'], '[TEST') === false && strpos((string)$off['text'], 'TEST PLATFORM') === false, json_encode($off['to'] ?? null));
    $logs = (array)($res['log'] ?? []);
    $logFor = static function (string $tag) use ($logs): ?array { foreach ($logs as $r) { if (strpos((string)$r['subject'], $tag) !== false) { return $r; } } return null; };
    $lr = $logFor('TPINV1');
    ok('email_send_log records the redirect', $lr !== null && $lr['to_email'] === 'tp-test@example.invalid' && strpos((string)$lr['cc'], 'TEST PLATFORM redirect') === 0
        && strpos((string)$lr['cc'], 'guest-invoice@example.invalid') !== false && strpos((string)$lr['subject'], '[TEST - Liwonde]') === 0, json_encode($lr));
    $lo = $logFor('TPOFF1');
    ok('email_send_log unchanged with the setting off', $lo !== null && $lo['to_email'] === 'guest-off@example.invalid' && strpos((string)$lo['cc'], 'TEST PLATFORM') === false, json_encode($lo));
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
