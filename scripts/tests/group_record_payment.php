<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Whole-group Record payment (owner 2026-11-13). Usage (repo root, TEST HOTEL ONLY):
 *   php scripts/tests/group_record_payment.php [lead booking id, default 2100008837]
 * 1. The split rule (admin/includes/group-payment.php rh_group_pay_split): sums exactly, never above a room's balance,
 *    rooms paid in full get nothing, the extra above the group's balance goes to one room.
 * 2. Rehearsal on a real mirrored group, in ONE transaction that is rolled back: N rows, N receipt numbers, sum = amount,
 *    each <= its room's balance, group invoice Amount paid +amount, Still owed -amount, History on every room, a double
 *    submit records nothing more, moved balances refuse, overpay needs the tick and makes one credit note.
 * 3. Through the real page (scripts/tests/page_harness.php, rolled back, SMTP pointed at the capture-only
 *    scripts/tests/fake-smtp.php so nothing is delivered): the preview JSON, a whole-group POST (one receipt email per
 *    room, captured), "This room only" = one row as today, a pending whole-group payment refused.
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
require_once $root . '/admin/includes/receivables.php';
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/booking-history-format.php';
require_once $root . '/includes/billing-accounts.php';
require_once $root . '/includes/idempotency.php';
require_once $root . '/config/credit-notes.php';
require_once $root . '/config/invoice.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= 0.005;
$tol = (float)BALANCE_TOLERANCE;

// PDFs written during the run (receipts, credit notes) are removed at the end; files that existed before are never touched.
function gp_pdfs(string $root): array {
    $set = [];
    foreach (['invoices', 'receipts', 'credit-notes'] as $d) {
        if (!is_dir($root . '/' . $d)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') { $set[$f->getPathname()] = true; }
        }
    }
    return $set;
}
$pdfBefore = gp_pdfs($root);

// ---- 1. the split rule -----------------------------------------------------------------------------------------
$sixteen = array_fill_keys(range(1, 16), 413400.0);
foreach ([1000000.07, 6614399.99, 0.05, 6614400.00, 1234567.89] as $amt) {
    $s = rh_group_pay_split($sixteen, $amt, 1);
    $cap = true;
    foreach ($s['shares'] as $id => $v) { if ($v > $sixteen[$id] + 0.001 || $v < 0) { $cap = false; } }
    ok("split $amt: sums exactly", $near(array_sum($s['shares']), $amt), (string)array_sum($s['shares']));
    ok("split $amt: no room above its balance, no extra", $cap && $s['excess'] == 0.0);
}
$s = rh_group_pay_split($sixteen, 1000000.07, 1);
$vals = array_count_values(array_map(static fn($v) => number_format($v, 2, '.', ''), $s['shares']));
ok('split 1,000,000.07 over 16 equal rooms: 15 x 62,500.00 and one room takes the 0.07', ($vals['62500.00'] ?? 0) === 15 && ($vals['62500.07'] ?? 0) === 1, json_encode($vals));
$mixed = [10 => 0.0, 11 => 300.0, 12 => 100.0, 13 => 0.004];
$s = rh_group_pay_split($mixed, 200.0, 10);
ok('split by balance: paid-in-full rooms get nothing', $s['shares'][10] == 0.0 && $s['shares'][13] == 0.0);
ok('split by balance: 3:1 shares (150 / 50)', $near($s['shares'][11], 150) && $near($s['shares'][12], 50));
$s = rh_group_pay_split($mixed, 450.0, 10);
ok('above the group balance: every room paid in full, the extra (50) on the one chosen room only', $near($s['shares'][11], 300) && $near($s['shares'][12], 100) && $near($s['shares'][10], 50) && $near($s['excess'], 50));
$s = rh_group_pay_split([1 => 0.01, 2 => 0.01, 3 => 99999999.0], 99999999.01, 1);
ok('huge figures still sum exactly and stay capped', $near(array_sum($s['shares']), 99999999.01) && $s['shares'][3] <= 99999999.0 && $s['shares'][1] <= 0.01 && $s['shares'][2] <= 0.01);

// ---- 2. rehearsal on the mirrored group ------------------------------------------------------------------------
$lead = (int)($argv[1] ?? 2100008837);
$rootQ = $pdo->prepare('SELECT COALESCE(NULLIF(primary_booking_id, 0), id) FROM bookings WHERE id = ?');
$rootQ->execute([$lead]);
$root0 = (int)$rootQ->fetchColumn();
$groupAll = $pdo->prepare('SELECT id FROM bookings WHERE id = ? OR primary_booking_id = ? ORDER BY id');
$groupAll->execute([$root0, $root0]);
$gids = array_map('intval', $groupAll->fetchAll(PDO::FETCH_COLUMN));
$user = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = ['id' => (int)$user['id'], 'username' => (string)$user['username'], 'full_name' => (string)$user['full_name']];
$vatRate = rh_vat_enabled() ? (float)getSetting('vat_rate') : 0.0;
$txnCol = finance_payment_transaction_column($pdo);
$mods = ['conference' => false, 'gym' => false, 'events' => false, 'tabs' => false];

$snap = static function () use ($pdo, $gids, $mods, $root0): array {
    $owed = 0.0;
    foreach (rh_receivable_rows($pdo, $mods) as $r) {
        if ($r['type'] === 'room' && in_array((int)$r['id'], $gids, true)) { $owed += (float)$r['amount_due']; }
    }
    $rows = rh_invoice_doc_rows($pdo, $root0);
    $t = null;
    buildInvoiceHTML($rows['booking'], 'TEST-GRP', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rows['group_bookings'], null, false, $t);
    $c = $pdo->query('SELECT COUNT(*) FROM payments WHERE booking_type = \'room\' AND deleted_at IS NULL AND booking_id IN (' . implode(',', $gids) . ')')->fetchColumn();
    return ['still_owed' => round($owed, 2), 'inv_paid' => (float)($t['paid'] ?? 0), 'inv_balance' => (float)($t['balance'] ?? 0), 'rows' => (int)$c];
};
$in = static function (float $amt, array $expect, string $uuid, bool $tick = false) use ($vatRate, $txnCol): array {
    return ['amount' => $amt, 'method' => 'cash', 'date' => date('Y-m-d'), 'status' => 'completed', 'reference' => 'GRPTEST', 'notes' => '',
        'cc_emails' => '', 'client_uuid' => $uuid, 'confirm_overpay' => $tick, 'expect' => $expect, 'vat_rate' => $vatRate,
        'processed_by' => 'group payment test', 'txn_column' => $txnCol];
};

$pre = rh_group_pay_preview($pdo, $lead, 1000000.07);
if (empty($pre['is_group']) || empty($pre['can_group'])) {
    ok('mirrored group ' . $lead . ' is a group that can take a whole-group payment', false, json_encode(array_intersect_key($pre, ['is_group' => 1, 'can_group' => 1, 'reason' => 1])));
} else {
    $nRooms = count($pre['rooms']);
    $noShow = array_values(array_filter($pre['rooms'], static fn($r) => $r['status'] === 'no-show'));
    ok("preview: $nRooms rooms, group owes " . number_format($pre['group_owed'], 2), $nRooms >= 2 && $pre['group_owed'] > 0);
    ok('preview: a no-show room still owing counts', !$noShow || $noShow[0]['owed'] > 0);

    // A. 1,000,000.07 across the group
    $amt = 1000000.07;
    $pdo->beginTransaction();
    try {
        $before = $snap();
        $uuid = 'grptest-' . bin2hex(random_bytes(8));
        $res = rh_group_pay_record($pdo, $lead, $in($amt, $pre['expect'], $uuid), $user);
        $owedAfter = rh_group_pay_log_history($pdo, $res, $user, 'cash', 'completed', false);
        $after = $snap();
        $legs = $res['legs'];
        $sum = array_sum(array_column($legs, 'amount'));
        $capOk = true; $vatOk = true; $match = true;
        $prevApply = array_column($pre['rooms'], 'apply', 'id');
        foreach ($legs as $l) {
            if ($l['amount'] > $l['owed_before'] + $tol) { $capOk = false; }
            if (!$near($l['amount'], $prevApply[$l['booking_id']] ?? -1)) { $match = false; }
            $r = $pdo->query('SELECT payment_amount, vat_amount, total_amount, receipt_number, payment_status, payment_type FROM payments WHERE id = ' . (int)$l['payment_id'])->fetch(PDO::FETCH_ASSOC);
            if (!$near((float)$r['payment_amount'] + (float)$r['vat_amount'], (float)$r['total_amount']) || $r['payment_status'] !== 'completed' || $r['receipt_number'] === '') { $vatOk = false; }
        }
        $receipts = array_filter(array_unique(array_column($legs, 'receipt_number')));
        $hist = (int)$pdo->query('SELECT COUNT(DISTINCT payment_id) FROM payment_audit_log WHERE action = \'created\' AND payment_id IN (' . implode(',', array_column($legs, 'payment_id')) . ')')->fetchColumn();
        $owingRooms = count(array_filter($pre['rooms'], static fn($r) => $r['owed'] > $tol));
        ok('A: one payments row per owing room (' . count($legs) . ')', count($legs) === $owingRooms && $after['rows'] - $before['rows'] === count($legs));
        ok('A: one receipt number per room', count($receipts) === count($legs));
        ok('A: rows add up to the amount taken', $near($sum, $amt), (string)$sum);
        ok('A: no room above its balance', $capOk);
        ok('A: each row net + VAT = gross, completed, with a receipt', $vatOk);
        ok('A: recorded split = the split shown in the preview', $match);
        ok('A: group invoice Amount paid rises by exactly the amount', $near($after['inv_paid'] - $before['inv_paid'], $amt), ($after['inv_paid'] - $before['inv_paid']) . '');
        ok('A: Still owed drops by exactly the amount', $near($before['still_owed'] - $after['still_owed'], $amt), ($before['still_owed'] - $after['still_owed']) . '');
        ok('A: group still owes = before - amount', $near($owedAfter, $pre['group_owed'] - $amt), (string)$owedAfter);
        ok('A: History on every room', $hist === count($legs));
        if ($noShow) {
            ok('A: the no-show room took its share', in_array($noShow[0]['id'], array_column($legs, 'booking_id'), true));
        }
        echo '    rehearsal A: ' . count($legs) . ' rows, sum ' . number_format($sum, 2) . ', invoice paid ' . number_format($before['inv_paid'], 2) . ' -> ' . number_format($after['inv_paid'], 2)
            . ', still owed ' . number_format($before['still_owed'], 2) . ' -> ' . number_format($after['still_owed'], 2) . "\n";

        // Double submit: the page's existing check finds the group by its uuid; the database refuses a second set too.
        $dup = idem_find_existing_payment($pdo, $uuid);
        ok('double submit: the client_uuid finds the first room\'s payment (page redirects to it)', $dup && (int)$dup['id'] === (int)$legs[0]['payment_id']);
        $pdo->exec('SAVEPOINT gp_dup');
        $refused = false;
        try {
            $exp2 = array_column(rh_group_pay_rooms($pdo, $lead, false), 'owed', 'id');
            rh_group_pay_record($pdo, $lead, $in($amt, array_combine(array_map('strval', array_keys($exp2)), $exp2), $uuid), $user);
        } catch (Throwable $e) {
            $refused = true;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT gp_dup');
        ok('double submit: a second set with the same uuid is refused, nothing more recorded', $refused && $snap()['rows'] === $after['rows']);
    } finally {
        $pdo->rollBack();
    }

    // B. a balance moved after the preview: refused, nothing recorded.
    $pdo->beginTransaction();
    try {
        $before = $snap();
        $first = (int)$pre['rooms'][0]['id'];
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), 1000, 0, 0, 1000, 'cash', 'partial_payment', 'completed', NOW())")
            ->execute(['GPT' . random_int(100000, 999999), $first]);
        recalculateBookingFinancials($first);
        $msg = '';
        try {
            rh_group_pay_record($pdo, $lead, $in(5000, $pre['expect'], 'grptest-' . bin2hex(random_bytes(8))), $user);
        } catch (Exception $e) {
            $msg = $e->getMessage();
        }
        ok('B: balances changed since the preview -> refused, nothing recorded', stripos($msg, 'changed') !== false && $snap()['rows'] === $before['rows'] + 1, $msg);
    } finally {
        $pdo->rollBack();
    }

    // C. exactly what the group owes: every room paid in full.
    $pdo->beginTransaction();
    try {
        $res = rh_group_pay_record($pdo, $lead, $in($pre['group_owed'], $pre['expect'], 'grptest-' . bin2hex(random_bytes(8))), $user);
        $types = array_unique(array_map(static fn($l) => $pdo->query('SELECT payment_type FROM payments WHERE id = ' . (int)$l['payment_id'])->fetchColumn(), $res['legs']));
        $left = array_sum(array_column(rh_group_pay_rooms($pdo, $lead, false), 'owed'));
        ok('C: the whole group balance -> every room full_payment, nothing owed after', $types === ['full_payment'] && $left <= $tol, json_encode($types) . ' left ' . $left);
    } finally {
        $pdo->rollBack();
    }

    // D. more than the group owes: refused without the tick; with it, one credit note on one room for the extra.
    $pdo->beginTransaction();
    try {
        $msg = '';
        try {
            rh_group_pay_record($pdo, $lead, $in($pre['group_owed'] + 500, $pre['expect'], 'grptest-' . bin2hex(random_bytes(8))), $user);
        } catch (Exception $e) {
            $msg = $e->getMessage();
        }
        ok('D: above the group balance without the tick -> refused', stripos($msg, 'more than') !== false, $msg);
        $res = rh_group_pay_record($pdo, $lead, $in($pre['group_owed'] + 500, $pre['expect'], 'grptest-' . bin2hex(random_bytes(8)), true), $user);
        $cnCount = $res['credit'] ? count($res['credit']['notes']) : 0;
        $cb = (float)$pdo->query('SELECT COALESCE(SUM(credit_balance), 0) FROM bookings WHERE id IN (' . implode(',', $gids) . ')')->fetchColumn();
        ok('D: with the tick -> one credit note for 500 on one room, credit balance zeroed', $cnCount === 1 && $near($res['credit']['excess'], 500) && $cb <= $tol,
            json_encode(['cn' => $cnCount, 'excess' => $res['credit']['excess'] ?? null, 'credit_balance' => $cb]));
        ok('D: the extra went to the group lead', (int)$res['excess_room_id'] === rh_booking_group_effective_lead($pdo, $lead));
    } finally {
        $pdo->rollBack();
    }

    // E. not money received -> refused.
    $pdo->beginTransaction();
    try {
        $msg = '';
        try {
            rh_group_pay_record($pdo, $lead, ['status' => 'pending'] + $in(1000, $pre['expect'], 'grptest-' . bin2hex(random_bytes(8))), $user);
        } catch (Exception $e) {
            $msg = $e->getMessage();
        }
        ok('E: a pending whole-group payment is refused, in plain words', stripos($msg, 'Pending whole-group payments are not allowed') !== false, $msg);
    } finally {
        $pdo->rollBack();
    }

    // F. company-billed group: no whole-group choice, the reason in plain words (preview and server).
    {
        $pdo->beginTransaction();
        try {
            // A test company account inside the rolled-back transaction (no DDL: billing_accounts already exists).
            $pdo->prepare("INSERT INTO billing_accounts (name, billing_email, terms_days, active, created_at) VALUES (?, NULL, 30, 1, NOW())")
                ->execute(['ZZ GRP PAY CO ' . random_int(1000, 9999)]);
            $acc = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE bookings SET billing_account_id = ? WHERE id = ?')->execute([$acc, $root0]);
            $cp = rh_group_pay_preview($pdo, $lead, 1000);
            ok('F: company-billed group -> preview says why (no whole-group choice)', !empty($cp['is_group']) && empty($cp['can_group'])
                && stripos((string)$cp['reason'], 'Company-billed group') !== false, (string)($cp['reason'] ?? ''));
            $msg = '';
            try {
                rh_group_pay_record($pdo, $lead, $in(1000, $pre['expect'], 'grptest-' . bin2hex(random_bytes(8))), $user);
            } catch (Exception $e) {
                $msg = $e->getMessage();
            }
            ok('F: company-billed group -> server refuses with the same reason', stripos($msg, 'Company-billed group') !== false, $msg);
        } finally {
            $pdo->rollBack();
        }
    }

    // G. group counts drawn with the page (no group check for single bookings).
    $cnt = rh_group_pay_counts($pdo, [$lead]);
    ok('G: group room count for the lead = ' . count($pre['rooms']), (int)($cnt[$lead] ?? 0) === count($pre['rooms']));
}
$single = $pdo->query("SELECT b.id FROM bookings b WHERE b.primary_booking_id IS NULL AND b.deleted_at IS NULL AND b.status IN ('confirmed','checked-in')
    AND NOT EXISTS (SELECT 1 FROM bookings s WHERE s.primary_booking_id = b.id) ORDER BY b.id DESC LIMIT 1")->fetchColumn();
if ($single) {
    $sp = rh_group_pay_preview($pdo, (int)$single, 100);
    ok('a single booking is not a group (no question shown)', empty($sp['is_group']));
    ok('a single booking counts as 1 room (dialogs skip the group check)', (int)(rh_group_pay_counts($pdo, [(int)$single])[(int)$single] ?? 0) === 1);
}

// ---- 3. through the real page (rolled back, SMTP captured) ----------------------------------------------------
$harness = $root . '/scripts/tests/page_harness.php';
$port = 0;
for ($try = 0; $try < 20 && !$port; $try++) {
    $cand = random_int(20000, 45000);
    $probe = @stream_socket_server('tcp://127.0.0.1:' . $cand, $e1, $e2);
    if ($probe) { fclose($probe); $port = $cand; }
}
$spool = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_grp_pay_' . bin2hex(random_bytes(4)) . '.jsonl';
$smtp = $port ? proc_open([PHP_BINARY, $root . '/scripts/tests/fake-smtp.php', (string)$port, $spool], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/rh_grp_smtp.out', 'a'], 2 => ['file', sys_get_temp_dir() . '/rh_grp_smtp.err', 'a']], $pipes) : null;
for ($i = 0; $i < 50 && !is_file($spool . '.ready'); $i++) { usleep(100000); }
register_shutdown_function(function () use (&$smtp, $spool, $root, $pdfBefore) {
    if (is_resource($smtp)) { proc_terminate($smtp); proc_close($smtp); }
    @unlink($spool); @unlink($spool . '.ready');
    foreach (array_diff_key(gp_pdfs($root), $pdfBefore) as $f => $_) {
        for ($i = 0; $i < 10 && is_file($f) && !@unlink($f); $i++) { usleep(300000); clearstatcache(true, $f); }
    }
});
$spoolPos = 0;
function gp_emails(): array {
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
function gp_run(array $post, array $opts = []): array {
    global $harness, $port;
    $cfg = base64_encode(json_encode(['post' => $post, 'get' => $opts['get'] ?? [], 'method' => $opts['method'] ?? 'POST', 'verify' => $opts['verify'] ?? [],
        'setup' => $opts['setup'] ?? [], 'fake_smtp' => $port, 'ajax' => !empty($opts['ajax'])]));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($opts['page'] ?? 'admin/payment-add.php') . ' ' . escapeshellarg($cfg) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    $res['emails'] = gp_emails();
    return $res;
}
$clean = static fn(array $r): bool => !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']);
if (!$port || !is_resource($smtp) || !is_file($spool . '.ready')) {
    ok('capture SMTP server started (page checks need it)', false);
} elseif (!empty($pre['can_group'])) {
    $idList = implode(',', $gids);
    // Real-looking addresses inside the rolled-back transaction, so each receipt is sent (to the capture server only).
    $setup = ["UPDATE bookings SET guest_email = CONCAT('grp-test-', id, '@example.com') WHERE id IN ($idList)"];

    $r = gp_run([], ['method' => 'GET', 'get' => ['rh_group_split' => '1', 'booking_id' => (string)$lead, 'amount' => '1000000.07']]);
    $j = $r['json'] ?? [];
    ok('page: preview JSON lists the group and its split', $clean($r) && !empty($j['is_group']) && count($j['rooms'] ?? []) === count($pre['rooms'])
        && $near(array_sum(array_column($j['rooms'] ?? [], 'apply')), 1000000.07), substr(json_encode($r), 0, 400));

    $u = 'grppage-' . bin2hex(random_bytes(8));
    $r = gp_run(['booking_type' => 'room', 'booking_id' => (string)$lead, 'payment_amount' => '1000000.07', 'payment_method' => 'cash', 'payment_status' => 'completed',
        'payment_date' => date('Y-m-d'), 'client_uuid' => $u, 'pay_scope' => 'group', 'group_expect' => json_encode($pre['expect'])], [
        'setup' => $setup,
        'verify' => [
            "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS s, COUNT(DISTINCT receipt_number) AS r FROM payments WHERE client_uuid = '$u' OR client_uuid LIKE '$u:g%'",
            "SELECT COUNT(DISTINCT payment_id) AS h FROM payment_audit_log WHERE payment_id IN (SELECT id FROM payments WHERE client_uuid = '$u' OR client_uuid LIKE '$u:g%')",
        ]]);
    $v = $r['verify'][0][0] ?? [];
    $receiptMails = count(array_filter($r['emails'], static fn($m) => preg_match('/receipt/i', (string)($m['subject'] ?? ''))));
    $owingRooms = count(array_filter($pre['rooms'], static fn($x) => $x['owed'] > $tol));
    ok('page: whole group -> one row and one receipt number per owing room, sum = amount', $clean($r) && (int)($v['n'] ?? 0) === $owingRooms
        && (int)($v['r'] ?? 0) === $owingRooms && $near($v['s'] ?? 0, 1000000.07), json_encode($v) . ' ' . substr((string)($r['raw'] ?? ''), 0, 300));
    ok('page: History on every room', (int)($r['verify'][1][0]['h'] ?? 0) === $owingRooms);
    ok('page: one receipt email per room (captured, never delivered)', $receiptMails === $owingRooms, $receiptMails . ' receipt emails');

    $u = 'grppage-' . bin2hex(random_bytes(8));
    $r = gp_run(['booking_type' => 'room', 'booking_id' => (string)$lead, 'payment_amount' => '1000.00', 'payment_method' => 'cash', 'payment_status' => 'completed',
        'payment_date' => date('Y-m-d'), 'client_uuid' => $u, 'pay_scope' => 'room'], [
        'setup' => $setup,
        'verify' => ["SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS s, MIN(booking_id) AS b FROM payments WHERE client_uuid = '$u' OR client_uuid LIKE '$u:g%'"]]);
    $v = $r['verify'][0][0] ?? [];
    ok('page: this room only -> one row on this room, as today', $clean($r) && (int)($v['n'] ?? 0) === 1 && (int)($v['b'] ?? 0) === $lead && $near($v['s'] ?? 0, 1000), json_encode($v));

    $u = 'grppage-' . bin2hex(random_bytes(8));
    $r = gp_run(['booking_type' => 'room', 'booking_id' => (string)$lead, 'payment_amount' => '1000.00', 'payment_method' => 'cash', 'payment_status' => 'pending',
        'payment_date' => date('Y-m-d'), 'client_uuid' => $u, 'pay_scope' => 'group', 'group_expect' => json_encode($pre['expect'])], [
        'verify' => ["SELECT COUNT(*) AS n FROM payments WHERE client_uuid = '$u' OR client_uuid LIKE '$u:g%'"]]);
    ok('page: pending whole-group payment refused, nothing recorded', $clean($r) && (int)($r['verify'][0][0]['n'] ?? -1) === 0);

    // Payments & balance (Bookings list): the group room count comes with the booking; whole group goes through the same code.
    $r = gp_run(['action' => 'consolidation_fetch', 'id' => (string)$lead], ['page' => 'admin/bookings.php', 'ajax' => true]);
    ok('Payments & balance: booking carries its group room count', $clean($r) && (int)($r['json']['booking']['group_rooms'] ?? 0) === count($pre['rooms']),
        substr(json_encode($r['json']['booking'] ?? $r), 0, 300));
    $u = 'grppage-' . bin2hex(random_bytes(8));
    $r = gp_run(['action' => 'consolidation_record', 'id' => (string)$lead, 'amount' => '1000000.07', 'payment_method' => 'bank_transfer', 'payment_type' => 'partial_payment',
        'payment_date' => date('Y-m-d'), 'notes' => '', 'accountant_notes' => '', 'pay_scope' => 'group', 'group_expect' => json_encode($pre['expect']), 'client_uuid' => $u], [
        'page' => 'admin/bookings.php', 'ajax' => true, 'setup' => $setup,
        'verify' => ["SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS s, COUNT(DISTINCT receipt_number) AS r FROM payments WHERE client_uuid = '$u' OR client_uuid LIKE '$u:g%'"]]);
    $v = $r['verify'][0][0] ?? [];
    $receiptMails = count(array_filter($r['emails'], static fn($m) => preg_match('/receipt/i', (string)($m['subject'] ?? ''))));
    ok('Payments & balance: whole group -> one row + receipt per owing room, sum = amount', $clean($r) && !empty($r['json']['success'])
        && (int)($v['n'] ?? 0) === $owingRooms && (int)($v['r'] ?? 0) === $owingRooms && $near($v['s'] ?? 0, 1000000.07),
        json_encode($v) . ' ' . substr(json_encode($r['json'] ?? ($r['raw'] ?? '')), 0, 300));
    ok('Payments & balance: one receipt email per room (captured)', $receiptMails === $owingRooms, $receiptMails . ' receipt emails');
    $u = 'grppage-' . bin2hex(random_bytes(8));
    $r = gp_run(['action' => 'consolidation_record', 'id' => (string)$lead, 'amount' => '1000.00', 'payment_method' => 'cash', 'payment_type' => 'partial_payment',
        'payment_date' => date('Y-m-d'), 'pay_scope' => 'group', 'group_expect' => '{}', 'client_uuid' => $u], [
        'page' => 'admin/bookings.php', 'ajax' => true,
        'verify' => ["SELECT COUNT(*) AS n FROM payments WHERE client_uuid = '$u' OR client_uuid LIKE '$u:g%'"]]);
    ok('Payments & balance: stale split refused, nothing recorded', $clean($r) && empty($r['json']['success']) && (int)($r['verify'][0][0]['n'] ?? -1) === 0
        && stripos((string)($r['json']['message'] ?? ''), 'changed') !== false, substr(json_encode($r['json'] ?? ''), 0, 200));
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
