<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Whole-group payment receipts (owner 2026-11-20). Usage (repo root, TEST HOTEL ONLY):
 *   php scripts/tests/group_receipt_combined_test.php [group lead booking id, default 2100008837]
 * Each room keeps its own receipt number; every leg's PDF and email body shows the live whole-group total and every
 * leg; one email carries every leg's PDF and stamps every leg. Every case runs in a transaction that is rolled back;
 * emails go to the capture-only scripts/tests/fake-smtp.php (nothing is delivered); PDFs written are removed and any
 * receipt PDF that already existed under the same name is restored.
 *  (a) 2 legs: each PDF has the total and both receipt numbers   (b) 3 rooms, one paid in full: 2 legs, N = 2
 *  (c) one leg edited: the total follows the live amounts         (d) the combined send: N PDFs, every leg stamped
 *  (e) a later auto-send on another leg: already_sent             (f) overpay leg with a credit note
 *  (g) a single room payment: no group section, exactly 1 PDF     (h) legacy pair linked; 10-second window / method
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/admin/includes/finance-schema.php';
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/admin/includes/group-payment.php';
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/booking-history-format.php';
require_once $root . '/config/credit-notes.php';
require_once $root . '/config/receipts.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$tol = (float)BALANCE_TOLERANCE;
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= $tol;

// ---- PDF files: remove what this run wrote, restore any receipt PDF it overwrote ------------------------------------
function grc_pdfs(string $root): array {
    $set = [];
    foreach (['invoices', 'receipts', 'credit-notes'] as $d) {
        if (!is_dir($root . '/' . $d)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') { $set[$f->getPathname()] = true; }
        }
    }
    return $set;
}
$pdfBefore = grc_pdfs($root);
$backups = [];
function grc_protect(string $number): void { // call before a receipt PDF with this number can be (re)written
    global $root, $backups;
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $number);
    $path = $root . '/invoices/receipts/' . $safe . '.pdf';
    if ($safe !== '' && !array_key_exists($path, $backups) && is_file($path)) { $backups[$path] = file_get_contents($path); }
}

// ---- capture-only SMTP --------------------------------------------------------------------------------------------
$port = 0;
for ($try = 0; $try < 20 && !$port; $try++) {
    $cand = random_int(20000, 45000);
    $probe = @stream_socket_server('tcp://127.0.0.1:' . $cand, $e1, $e2);
    if ($probe) { fclose($probe); $port = $cand; }
}
$spool = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_grc_' . bin2hex(random_bytes(4)) . '.jsonl';
$smtp = $port ? proc_open([PHP_BINARY, $root . '/scripts/tests/fake-smtp.php', (string)$port, $spool], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/rh_grc_smtp.out', 'a'], 2 => ['file', sys_get_temp_dir() . '/rh_grc_smtp.err', 'a']], $pipes) : null;
for ($i = 0; $i < 50 && !is_file($spool . '.ready'); $i++) { usleep(100000); }
register_shutdown_function(function () use (&$smtp, $spool, $root, $pdfBefore) {
    global $backups;
    if (is_resource($smtp)) { proc_terminate($smtp); proc_close($smtp); }
    @unlink($spool); @unlink($spool . '.ready');
    foreach (array_diff_key(grc_pdfs($root), $pdfBefore) as $f => $_) {
        for ($i = 0; $i < 10 && is_file($f) && !@unlink($f); $i++) { usleep(300000); clearstatcache(true, $f); }
    }
    foreach ($backups as $path => $bytes) { file_put_contents($path, $bytes); }
});
$smtpOk = $port && is_resource($smtp) && is_file($spool . '.ready');
if ($smtpOk) {
    // Point this process's mailer at the capture server only (the settings were read into globals by config/email.php).
    foreach (['smtp_host' => '127.0.0.1', 'smtp_port' => $port, 'smtp_secure' => '', 'smtp_username' => 'fake@example.invalid', 'smtp_password' => 'x',
              'smtp_timeout' => 5, 'smtp_debug' => 0, 'development_mode' => false, 'email_preview_enabled' => false, 'email_bcc_admin' => false,
              'email_log_enabled' => false] as $k => $v) {
        $GLOBALS[$k] = $v;
    }
    $GLOBALS['rh_email_fast_fail'] = true;
}
$spoolPos = 0;
function grc_emails(): array {
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
$pdfNames = static fn(array $mail): array => array_values(preg_grep('/\.pdf$/i', (array)($mail['attachments'] ?? [])));
$canSend = static fn(): bool => $GLOBALS['smtp_host'] === '127.0.0.1' && (int)$GLOBALS['smtp_port'] === $GLOBALS['port'] && $GLOBALS['smtpOk'];

receipt_ensure_schema($pdo); // its CREATE TABLE IF NOT EXISTS commits: run it before any transaction

// ---- fixture: the mirrored 16-room group, cut down inside each rolled-back transaction ------------------------------
$lead = (int)($argv[1] ?? 2100008837);
$rq = $pdo->prepare('SELECT COALESCE(NULLIF(primary_booking_id, 0), id) FROM bookings WHERE id = ?');
$rq->execute([$lead]);
$root0 = (int)$rq->fetchColumn();
$ga = $pdo->prepare('SELECT id FROM bookings WHERE (id = ? OR primary_booking_id = ?) AND deleted_at IS NULL ORDER BY id');
$ga->execute([$root0, $root0]);
$gids = array_map('intval', $ga->fetchAll(PDO::FETCH_COLUMN));
$user = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = ['id' => (int)$user['id'], 'username' => (string)$user['username'], 'full_name' => (string)$user['full_name']];
$vatRate = rh_vat_enabled() ? (float)getSetting('vat_rate') : 0.0;
$txnCol = finance_payment_transaction_column($pdo);
$cur = (string)getSetting('currency_symbol', 'MWK');

if ($root0 !== $lead || count($gids) < 3) {
    ok("fixture: $lead is the primary room of a group of at least 3 rooms", false, json_encode($gids));
    echo "\n$pass passed, $fail failed\n";
    exit(1);
}

/** Keep only $keep rooms in the group (others detached, rolled back later), test emails, no issued final invoice. */
$shrink = static function (array $keep) use ($pdo, $root0, $gids): void {
    $drop = array_values(array_diff($gids, $keep));
    if ($drop) {
        $pdo->prepare('UPDATE bookings SET primary_booking_id = NULL WHERE id IN (' . implode(',', array_fill(0, count($drop), '?')) . ')')->execute($drop);
    }
    $pdo->prepare("UPDATE bookings SET guest_email = CONCAT('grc-test-', id, '@example.invalid'), final_invoice_generated = 0 WHERE id IN ("
        . implode(',', array_fill(0, count($keep), '?')) . ')')->execute($keep);
};
$in = static function (float $amt, array $expect, bool $tick = false) use ($vatRate, $txnCol): array {
    return ['amount' => $amt, 'method' => 'cash', 'date' => date('Y-m-d'), 'status' => 'completed', 'reference' => 'GRCTEST', 'notes' => '',
        'cc_emails' => 'grc-cc@example.invalid', 'client_uuid' => 'grctest-' . bin2hex(random_bytes(8)), 'confirm_overpay' => $tick, 'expect' => $expect,
        'vat_rate' => $vatRate, 'processed_by' => 'group receipt test', 'txn_column' => $txnCol];
};
$record = static function (float $amt, bool $tick = false) use ($pdo, $lead, $user, $in): array {
    $pre = rh_group_pay_preview($pdo, $lead, $amt);
    $res = rh_group_pay_record($pdo, $lead, $in($amt, $pre['expect'], $tick), $user);
    foreach ($res['legs'] as $l) { grc_protect((string)$l['receipt_number']); }
    return $res;
};
$legHtml = static function (int $paymentId) use ($pdo): array {
    $p = receipt_get_payment($pdo, $paymentId);
    $ctx = receipt_hydrate_context($pdo, $p);
    return ['pdf' => receipt_render_payment_pdf_html($p, $ctx), 'mail' => receipt_build_pos_style_html_raw($p, $ctx, $pdo), 'ctx' => $ctx, 'p' => $p];
};
$money = static fn(float $v): string => number_format($v, 2);

// (a) + (d) + (e): 2 rooms, 2 legs
$pdo->beginTransaction();
try {
    $shrink([$gids[0], $gids[1]]);
    $res = $record(200000.00);
    $legs = $res['legs'];
    ok('(a) 2 rooms -> 2 legs, each with its own receipt number', count($legs) === 2 && $legs[0]['receipt_number'] !== $legs[1]['receipt_number']);
    foreach ($legs as $l) {
        $h = $legHtml((int)$l['payment_id']);
        $all = strpos($h['pdf'], $legs[0]['receipt_number']) !== false && strpos($h['pdf'], $legs[1]['receipt_number']) !== false;
        ok('(a) PDF of ' . $l['receipt_number'] . ': whole-group total 200,000.00, 2 rooms, both receipt numbers',
            strpos($h['pdf'], 'Whole-group payment: ' . $cur . ' 200,000.00 split across 2 rooms') !== false && $all);
        ok('(a) PDF of ' . $l['receipt_number'] . ': Total received stays this leg\'s amount', strpos($h['pdf'], $money((float)$l['amount'])) !== false
            && (int)$h['ctx']['group_payment']['this_payment_id'] === (int)$l['payment_id']);
        ok('(a) email body of ' . $l['receipt_number'] . ' lists the total and both receipts', strpos($h['mail'], 'Whole-group payment: ' . $cur . ' 200,000.00') !== false
            && strpos($h['mail'], $legs[0]['receipt_number']) !== false && strpos($h['mail'], $legs[1]['receipt_number']) !== false);
    }

    // Room label from the sibling SQL = what getBookingRoomLabel() gave (room type + number), for every leg.
    $tq = $pdo->prepare('SELECT r.name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?');
    $labelsOk = true;
    foreach (rh_group_pay_siblings($pdo, (int)$legs[0]['payment_id'], null, true) as $sl) {
        $tq->execute([$sl['booking_id']]);
        $type = trim((string)$tq->fetchColumn());
        $no = trim(getBookingRoomLabel($sl['booking_id']));
        $want = $no !== '' && stripos($type, $no) === false ? trim($type . ' - ' . $no, ' -') : $type;
        if ($sl['room'] !== $want) { $labelsOk = false; echo "  label {$sl['room']} vs $want\n"; }
    }
    ok('(a) room label from the sibling query matches getBookingRoomLabel for every leg', $labelsOk);

    // Per-request cache: one lookup serves every leg; a second hydrate of the sibling leg runs no sibling query.
    rh_group_pay_siblings_reset();
    $l0 = (int)$legs[0]['payment_id'];
    $l1 = (int)$legs[1]['payment_id'];
    $p1 = receipt_get_payment($pdo, $l1);
    receipt_hydrate_context($pdo, receipt_get_payment($pdo, $l0)); // fills the cache under both leg ids
    $cached = rh_group_pay_siblings_cache();
    $pdo->prepare('UPDATE payments SET total_amount = total_amount - 1000 WHERE id = ?')->execute([$l0]);
    $hitCtx = receipt_hydrate_context($pdo, $p1)['group_payment']; // a query would see the edit; the cache does not
    ok('(a) cache: set stored under both leg ids; the sibling\'s hydrate is served from it (no query)',
        isset($cached[$l0], $cached[$l1]) && $near($hitCtx['total'] ?? 0, 200000), json_encode($hitCtx['total'] ?? null));
    rh_group_pay_siblings_reset();
    ok('(a) cache: after rh_group_pay_siblings_reset() the live total is read (199,000.00)',
        $near(receipt_hydrate_context($pdo, $p1)['group_payment']['total'] ?? 0, 199000));
    $pdo->prepare('UPDATE payments SET total_amount = total_amount + 1000 WHERE id = ?')->execute([$l0]);
    rh_group_pay_siblings_reset();

    if (!$canSend()) {
        ok('(d) capture SMTP server started (send checks need it)', false);
    } else {
        grc_emails();
        $leadLeg = $legs[0]['booking_id'] === $root0 ? $legs[0] : $legs[1];
        $other = $leadLeg === $legs[0] ? $legs[1] : $legs[0];
        $send = receipt_send_email($pdo, (int)$leadLeg['payment_id'], null, $user);
        $mails = array_values(array_filter(grc_emails(), static fn($m) => stripos((string)($m['subject'] ?? ''), 'receipt') !== false));
        $names = $mails ? $pdfNames($mails[0]) : [];
        sort($names);
        $want = [$legs[0]['receipt_number'] . '.pdf', $legs[1]['receipt_number'] . '.pdf'];
        sort($want);
        ok('(d) ONE email carrying both legs\' PDFs', count($mails) === 1 && $names === $want, json_encode([count($mails), $names]));
        ok('(d) sent to the lead room\'s guest, message says 1 email, 2 receipts', !empty($send['success']) && (int)($send['receipts'] ?? 0) === 2
            && strpos((string)$send['message'], 'grc-test-' . $root0 . '@example.invalid') !== false, (string)($send['message'] ?? ''));
        $ids = [(int)$legs[0]['payment_id'], (int)$legs[1]['payment_id']];
        $st = $pdo->query('SELECT id, receipt_emailed_at, receipt_email_count FROM payments WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_ASSOC);
        $stamped = count(array_filter($st, static fn($r) => !empty($r['receipt_emailed_at']) && (int)$r['receipt_email_count'] === 1)) === 2;
        $ev = (int)$pdo->query("SELECT COUNT(DISTINCT payment_id) FROM receipt_events WHERE event_type = 'emailed' AND payment_id IN (" . implode(',', $ids) . ')')->fetchColumn();
        ok('(d) every leg stamped: receipt_emailed_at, count 1, an emailed receipt_events row', $stamped && $ev === 2, json_encode([$st, $ev]));
        $auto = receipt_auto_send($pdo, (int)$other['payment_id'], $user);
        ok('(e) a later auto-send on the other leg -> already_sent, nothing sent', ($auto['reason'] ?? '') === 'already_sent' && grc_emails() === [], json_encode($auto));
    }
} finally {
    $pdo->rollBack();
}

// (d2) the after-commit path: lead leg first, one email, the staff message
$tpl = function_exists('getBookingEmailTemplateConfig') ? getBookingEmailTemplateConfig('payment_receipt', []) : [];
if ($canSend() && !(is_array($tpl) && array_key_exists('is_active', $tpl) && (int)$tpl['is_active'] === 0)) {
    $pdo->beginTransaction();
    try {
        $shrink([$gids[0], $gids[1]]);
        $res = $record(200000.00);
        grc_emails();
        $after = rh_group_pay_after_commit($pdo, $res, $user, 'cash', 'completed', false, 'group-receipt-test');
        $mails = array_values(array_filter(grc_emails(), static fn($m) => stripos((string)($m['subject'] ?? ''), 'receipt') !== false));
        ok('(d) after commit: one receipt email with 2 PDFs, message "Receipts emailed (1 email, 2 receipts)"', count($mails) === 1 && count($pdfNames($mails[0])) === 2
            && strpos($after['message'], 'Receipts emailed (1 email, 2 receipts).') !== false, count($mails) . ' ' . $after['message']);
    } finally {
        $pdo->rollBack();
    }
} else {
    echo "[SKIP] (d) after-commit send: payment receipt emails are switched off here or the capture server is down\n";
}

// (b) 3 rooms, one already paid in full: 2 legs, N = 2
$pdo->beginTransaction();
try {
    $shrink([$gids[0], $gids[1], $gids[2]]);
    $paid = $gids[2];
    $owed = rh_account_balance_due($pdo, 'room', $paid);
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, 0, 0, ?, 'cash', 'full_payment', 'completed', NOW())")
        ->execute(['GRC' . random_int(100000, 999999), $paid, $owed, $owed]);
    recalculateBookingFinancials($paid);
    rh_sync_account_payments($pdo, 'room', $paid);
    $res = $record(150000.00);
    $legIds = array_column($res['legs'], 'booking_id');
    ok('(b) the room paid in full takes no share: 2 legs', count($res['legs']) === 2 && !in_array($paid, $legIds, true), json_encode($legIds));
    $h = $legHtml((int)$res['legs'][0]['payment_id']);
    ok('(b) N on the receipt = 2 legs (split across 2 rooms)', (int)$h['ctx']['group_payment']['count'] === 2
        && strpos($h['pdf'], 'split across 2 rooms') !== false);
} finally {
    $pdo->rollBack();
}

// (c) one leg edited afterwards: the total follows the live amounts (never the note)
$pdo->beginTransaction();
try {
    $shrink([$gids[0], $gids[1]]);
    $res = $record(200000.00);
    $pdo->prepare('UPDATE payments SET total_amount = total_amount - 1000, payment_amount = payment_amount - 1000 WHERE id = ?')->execute([(int)$res['legs'][1]['payment_id']]);
    rh_group_pay_siblings_reset(); // a writer in the same request resets the cache
    $h = $legHtml((int)$res['legs'][0]['payment_id']);
    ok('(c) after Edit payment on one leg the total is 199,000.00 (live), the note still says 200,000.00',
        $near($h['ctx']['group_payment']['total'], 199000) && strpos($h['pdf'], $cur . ' 199,000.00 split across 2 rooms') !== false
        && strpos((string)$h['p']['notes'], '200,000.00') !== false, (string)($h['ctx']['group_payment']['total'] ?? ''));
} finally {
    $pdo->rollBack();
}

// (f) overpay: one leg carries the extra and its credit note; receipts still list both legs
$pdo->beginTransaction();
try {
    $shrink([$gids[0], $gids[1]]);
    $groupOwed = (float)rh_group_pay_preview($pdo, $lead, 1)['group_owed'];
    $res = $record($groupOwed + 500, true);
    ok('(f) overpay: one credit note for 500', !empty($res['credit']) && $near($res['credit']['excess'], 500), json_encode($res['credit']['excess'] ?? null));
    $okAll = true;
    foreach ($res['legs'] as $l) {
        $h = $legHtml((int)$l['payment_id']);
        if (!$near($h['ctx']['group_payment']['total'] ?? -1, $groupOwed + 500) || strpos($h['pdf'], $res['legs'][0]['receipt_number']) === false
            || strpos($h['pdf'], $res['legs'][1]['receipt_number']) === false) {
            $okAll = false;
        }
    }
    ok('(f) both receipts (one on the credit-note leg) show the whole amount and both legs', count($res['legs']) === 2 && $okAll);
} finally {
    $pdo->rollBack();
}

// (g) a single non-group room payment: unchanged (no group section, exactly one PDF)
$single = (int)$pdo->query("SELECT b.id FROM bookings b WHERE b.primary_booking_id IS NULL AND b.deleted_at IS NULL AND b.status IN ('confirmed','checked-in')
    AND NOT EXISTS (SELECT 1 FROM bookings s WHERE s.primary_booking_id = b.id) ORDER BY b.id DESC LIMIT 1")->fetchColumn();
if ($single > 0) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE bookings SET guest_email = 'grc-single@example.invalid', final_invoice_generated = 0 WHERE id = ?")->execute([$single]);
        $num = finance_next_receipt_number($pdo, date('Y-m-d'));
        grc_protect($num);
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, processed_by, notes, receipt_number, created_at)
                VALUES (?, 'room', ?, 'X', CURDATE(), 1000, 0, 0, 1000, 'cash', 'partial_payment', 'completed', 'group receipt test', 'single test', ?, NOW())")
            ->execute(['GRC' . random_int(100000, 999999), $single, $num]);
        $pid = (int)$pdo->lastInsertId();
        $h = $legHtml($pid);
        $ctxOld = $h['ctx'];
        unset($ctxOld['group_payment']);
        ok('(g) single payment: no group context, no group section in PDF or email', $h['ctx']['group_payment'] === null
            && strpos($h['pdf'], 'Whole-group') === false && strpos($h['mail'], 'Whole-group') === false);
        ok('(g) single payment: PDF and email HTML identical to the pre-change rendering (no group key)',
            receipt_render_payment_pdf_html($h['p'], $ctxOld) === $h['pdf'] && receipt_build_pos_style_html_raw($h['p'], $ctxOld, $pdo) === $h['mail']);
        if ($canSend()) {
            grc_emails();
            $send = receipt_send_email($pdo, $pid, null, $user);
            $mails = grc_emails();
            ok('(g) single payment: one email with exactly 1 PDF, plain message', count($mails) === 1 && $pdfNames($mails[0]) === [$num . '.pdf']
                && ($send['message'] ?? '') === 'Receipt emailed to grc-single@example.invalid' && !isset($send['receipts']), json_encode([$send, $mails[0]['attachments'] ?? null]));
        }
    } finally {
        $pdo->rollBack();
    }
} else {
    echo "[SKIP] (g) no single confirmed/checked-in booking found\n";
}

// (h) legacy pairs written by hand (each its own number): linked only on the full match key
$pdo->beginTransaction();
try {
    $shrink([$gids[0], $gids[1]]);
    $note = 'Part of a whole-group payment of ' . $cur . ' 2,000.00 split across 2 rooms.';
    $insLegacy = $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, processed_by, notes, receipt_number, created_at)
            VALUES (?, 'room', ?, 'X', ?, 1000, 0, 0, 1000, ?, 'partial_payment', 'completed', 'legacy test', ?, ?, ?)");
    $legacy = static function (int $bookingId, string $date, string $method, string $created) use ($pdo, $insLegacy, $note): int {
        $insLegacy->execute(['GRC' . random_int(100000, 999999), $bookingId, $date, $method, $note, finance_next_receipt_number($pdo, $date), $created]);
        return (int)$pdo->lastInsertId();
    };
    // Dates far from today so no real payment can match.
    $a1 = $legacy($gids[0], '2001-01-10', 'cash', '2001-01-10 10:00:00');
    $a2 = $legacy($gids[1], '2001-01-10', 'cash', '2001-01-10 10:00:03');
    $sib = rh_group_pay_siblings($pdo, $a1);
    ok('(h) legacy pair (own numbers, same date/method/staff/note, 3 s apart, same group) is linked', array_column($sib, 'payment_id') === [$a1, $a2]
        && count(array_unique(array_column($sib, 'receipt_number'))) === 2, json_encode(array_column($sib, 'payment_id')));
    $b1 = $legacy($gids[0], '2001-01-11', 'cash', '2001-01-11 10:00:00');
    $b2 = $legacy($gids[1], '2001-01-11', 'cash', '2001-01-11 10:00:30');
    ok('(h) a pair 30 s apart is NOT linked', count(rh_group_pay_siblings($pdo, $b1)) === 1 && count(rh_group_pay_siblings($pdo, $b2)) === 1);
    $c1 = $legacy($gids[0], '2001-01-12', 'cash', '2001-01-12 10:00:00');
    $c2 = $legacy($gids[1], '2001-01-12', 'mobile_money', '2001-01-12 10:00:01');
    ok('(h) a pair with a different method is NOT linked', count(rh_group_pay_siblings($pdo, $c1)) === 1 && count(rh_group_pay_siblings($pdo, $c2)) === 1);
    $pdo->prepare("UPDATE payments SET notes = 'edited by staff' WHERE id = ?")->execute([$a2]);
    rh_group_pay_siblings_reset();
    ok('(h) a leg whose note was edited is no longer linked (and its receipt has no group section)', count(rh_group_pay_siblings($pdo, $a1)) === 1
        && rh_group_pay_siblings($pdo, $a2) === [] && $legHtml($a1)['ctx']['group_payment'] === null);
} finally {
    $pdo->rollBack();
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
