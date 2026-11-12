<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Company pays later / on account (owner 2026-11-13, D1-D6, migration 084). Run from the TEST hotel's root only (Liwonde):
 *   php scripts/tests/billing_remittance.php
 * Pure: the who-owes split (stay vs extras, guest money covers extras first, refunds, cancellation, everything).
 * Rolled back (scripts/tests/page_harness.php): put on account (083 switch + note, History), labels, receivables (one row,
 * payer), checkout gate D2, chasers excluded, company payment split exactly (D5 refusals), legs = normal payments,
 * refund of a leg, Edit payment refused on a leg, take off account, group member follows its primary, documents with no
 * billing email (not sent, logged), invoice and statement addressed to the company, page renders. No email is sent.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/includes/billing-accounts.php';
$harness = $root . '/scripts/tests/page_harness.php';
function br_pdf_set(string $root): array {
    $set = [];
    foreach (['invoices', 'receipts'] as $d) {
        if (!is_dir($root . '/' . $d)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') { $set[$f->getPathname()] = true; }
        }
    }
    return $set;
}
$pdfBefore = br_pdf_set($root);
register_shutdown_function(function () use ($root, $pdfBefore) {
    foreach (array_diff_key(br_pdf_set($root), $pdfBefore) as $f => $_) {
        for ($i = 0; $i < 10 && is_file($f) && !@unlink($f); $i++) { usleep(300000); clearstatcache(true, $f); }
    }
});
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function br_run(string $page, array $post = [], array $opts = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => $post, 'get' => $opts['get'] ?? [], 'ajax' => !empty($opts['ajax']), 'verify' => $opts['verify'] ?? [], 'setup' => $opts['setup'] ?? [],
        'vars' => $opts['vars'] ?? [], 'method' => $opts['method'] ?? 'POST', 'grep' => $opts['grep'] ?? []]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -1200), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    if (empty($res['rolled_back']) || !empty($res['leak']) || !empty($res['marker_lost'])) {
        echo "HARNESS PROBLEM: " . json_encode(array_intersect_key($res, ['rolled_back' => 1, 'leak' => 1, 'marker_lost' => 1, 'raw' => 1, 'error' => 1])) . "\n";
    }
    return $res;
}
function br_child(string $scenario): array {
    $r = br_run('scripts/tests/billing_remittance_child.php', ['scenario' => $scenario]);
    if (!isset($r['json'])) { echo "child $scenario output: " . substr((string)($r['raw'] ?? json_encode($r)), -1500) . "\n"; }
    return $r['json'] ?? [];
}
$near = static function ($a, $b): bool { return abs((float)$a - (float)$b) <= 0.011; };

echo "== split (pure) ==\n";
$f = static fn(array $row, bool $on, string $covers, float $cp) => rh_billing_split_figures($row, $on, $covers, $cp);
$r = ['status' => 'checked-in', 'total_with_vat' => 1000, 'folio_charges_total' => 200, 'amount_paid' => 0, 'amount_due' => 1200];
$s = $f($r, false, 'stay', 0);
ok('not on account: the guest owes everything', $near($s['guest_due'], 1200) && $near($s['company_due'], 0));
$s = $f($r, true, 'stay', 0);
ok('stay only: company 1,000, guest 200 (extras)', $near($s['company_due'], 1000) && $near($s['guest_due'], 200));
$s = $f(['amount_paid' => 300, 'amount_due' => 900] + $r, true, 'stay', 0);
ok('guest paid 300: covers its 200 extras first, the 100 excess lowers the company share (900 / 0)', $near($s['company_due'], 900) && $near($s['guest_due'], 0));
$s = $f(['amount_paid' => 400, 'amount_due' => 800] + $r, true, 'stay', 400);
ok('company paid 400: company 600, guest still owes its 200', $near($s['company_due'], 600) && $near($s['guest_due'], 200));
$s = $f($r, true, 'everything', 0);
ok('everything: the company owes the whole balance', $near($s['company_due'], 1200) && $near($s['guest_due'], 0));
$s = $f(['status' => 'cancelled', 'cancellation_retained_amount' => 500, 'folio_charges_total' => 0, 'amount_due' => 500] + $r, true, 'stay', 0);
ok('cancelled, first night kept: the company owes the kept 500', $near($s['company_due'], 500));
$s = $f(['amount_paid' => 1200, 'amount_due' => 0] + $r, true, 'stay', 1000);
ok('all paid: nothing owed by anyone', $near($s['company_due'], 0) && $near($s['guest_due'], 0));
$inv = true;
foreach ([[0, 0], [300, 0], [400, 400], [1100, 500], [50, 0]] as [$paid, $cp]) {
    $x = $f(['amount_paid' => $paid, 'amount_due' => max(0, 1200 - $paid)] + $r, true, 'stay', $cp);
    $inv = $inv && $near($x['company_due'] + $x['guest_due'], max(0, 1200 - $paid));
}
ok('company_due + guest_due = amount_due in every case', $inv);
ok('label: on account, with the LPO', rh_booking_pay_label(['on_account' => true, 'name' => 'ACME', 'reference' => 'LPO 9', 'company_due' => 10, 'guest_due' => 0]) === 'On account - ACME (LPO 9)');
ok('label: not on account = normal label', rh_booking_pay_label(['on_account' => false]) === '');

echo "== test mode routing (pure) ==\n";
$rt = rh_billing_mail_route('billing@co.example.invalid', ['cc1@example.invalid'], 'Account statement', '');
ok('test mode off: the company billing email + its CC', $rt['send'] && $rt['to'] === 'billing@co.example.invalid' && $rt['cc'] === ['cc1@example.invalid']);
$rt = rh_billing_mail_route('billing@co.example.invalid', ['cc1@example.invalid', 'cc2@example.invalid'], 'Account statement', 'tester@example.invalid');
ok('test mode on: ONLY the test recipient, no CC, [TEST] subject', $rt['send'] && $rt['to'] === 'tester@example.invalid' && $rt['cc'] === [] && $rt['subject'] === '[TEST] Account statement', json_encode($rt));
$rt = rh_billing_mail_route('billing@co.example.invalid', ['cc1@example.invalid'], 'Account statement', 'not-an-email');
ok('test mode with an unusable test address: nothing sent (never the real company)', !$rt['send'] && $rt['to'] === '' && $rt['cc'] === []);
$jobSrc = (string)file_get_contents($root . '/includes/auto-email-jobs.php');
ok('the company statements job routes through the same test-mode rule', strpos($jobSrc, "rh_billing_mail_route(\$to, \$cc, 'Account statement - '") !== false);
$sx = rh_billing_exclude_sql('b', true);
ok('chaser exclusion: own company AND a group member whose primary is on account', strpos($sx, 'b.billing_account_id IS NULL') !== false && strpos($sx, 'bill_pb.id = b.primary_booking_id AND bill_pb.billing_account_id IS NOT NULL') !== false, $sx);

echo "== schema ==\n";
ok('migration 084 has run here (Liwonde)', rh_billing_ready($pdo));

echo "== flow (rolled back) ==\n";
$a = br_child('flow');
ok('a bad due date is refused', stripos((string)($a['set_bad_date'] ?? ''), 'due date') !== false, (string)($a['set_bad_date'] ?? ''));
ok('put on account: 083 switch on, automatic note with the LPO, company + covers saved', (int)($a['b1_flags']['checkin_without_payment'] ?? 0) === 1
    && strpos((string)($a['b1_flags']['checkin_arrangement_note'] ?? ''), 'On account - ') === 0 && strpos((string)$a['b1_flags']['checkin_arrangement_note'], 'LPO-77') !== false
    && (string)($a['b1_flags']['billing_covers'] ?? '') === 'stay', json_encode($a['b1_flags'] ?? []));
ok('History: "Company account set" old -> new + audit row', ($a['b1_history']['action'] ?? '') === 'Company account set' && strpos((string)$a['b1_history']['description'], 'Guest pays →') !== false
    && ($a['b1_audit']['action'] ?? '') === 'billing_changed', json_encode([$a['b1_history'] ?? [], $a['b1_audit'] ?? []]));
ok('split: company owes the 2,330 stay, guest owes the 200 bar bill', $near($a['split1']['company_due'] ?? 0, 2330) && $near($a['split1']['guest_due'] ?? 0, 200) && $near($a['split1']['amount_due'] ?? 0, 2530), json_encode($a['split1'] ?? []));
ok('split: due date = check-out + 30 days terms', ($a['split1']['due_date'] ?? '') === date('Y-m-d', strtotime('+30 day')));
ok('covers everything: the company owes the whole balance', $near($a['split2']['company_due'] ?? 0, 1165) && $near($a['split2']['guest_due'] ?? -1, 0));
ok('label on the list', strpos((string)($a['label1'] ?? ''), 'On account - ZZ TEST CO') === 0 && strpos((string)$a['label1'], 'guest owes') !== false, (string)($a['label1'] ?? ''));
$rv = $a['recv'] ?? [];
ok('receivables: one row per booking, same amount_due, payer and company name', count($rv) === 2 && $near($rv[0]['company_due'] + $rv[0]['guest_due'], $rv[0]['amount_due'])
    && in_array($rv[0]['payer'], ['both', 'company'], true) && strpos($rv[0]['client'], 'ZZ TEST CO') === 0, json_encode($rv));
ok('checkout gate (D2): only the guest\'s 200 is gated, the company 2,330 is not', empty($a['gate1']['allowed']) && $near($a['gate1']['balance'] ?? 0, 200) && $near($a['gate1']['company_due'] ?? 0, 2330), json_encode($a['gate1'] ?? []));
ok('checkout gate (D2): company covers everything = checkout allowed with nothing for the guest', !empty($a['gate2']['allowed']) && $near($a['gate2']['balance'] ?? -1, 0), json_encode($a['gate2'] ?? []));
ok('payment chasers leave company bookings out', strpos((string)($a['exclude_sql'] ?? ''), 'billing_account_id IS NULL') !== false, (string)($a['exclude_sql'] ?? ''));
ok('D5: lines not adding up to the amount are refused', stripos((string)($a['r_mismatch'] ?? ''), 'must match exactly') !== false, (string)($a['r_mismatch'] ?? ''));
ok('more than the company owes on a booking is refused', stripos((string)($a['r_over'] ?? ''), 'owes') !== false, (string)($a['r_over'] ?? ''));
ok('a future date is refused', stripos((string)($a['r_future'] ?? ''), 'future') !== false);
ok('a booking not on this company\'s account is refused', stripos((string)($a['r_not_on_acc'] ?? ''), 'not on') !== false, (string)($a['r_not_on_acc'] ?? ''));
ok('refusals saved nothing', (int)($a['no_rem_rows'] ?? -1) === 0);
ok('company payment recorded: REM reference, 2 legs, 3,495', preg_match('/^REM-\d{4}-\d{4}$/', (string)($a['rem']['reference'] ?? '')) === 1 && (int)($a['rem']['legs'] ?? 0) === 2 && $near($a['rem']['amount'] ?? 0, 3495), json_encode($a['rem'] ?? []));
$legOk = count($a['legs'] ?? []) === 2;
foreach ($a['legs'] ?? [] as $l) {
    $legOk = $legOk && $l['payment_status'] === 'completed' && $l['payment_method'] === 'bank_transfer' && trim((string)$l['receipt_number']) !== ''
        && $near((float)$l['payment_amount'] + (float)$l['vat_amount'], (float)$l['total_amount']) && strpos((string)$l['notes'], 'LPO') !== false;
}
ok('each leg is a normal completed payment: own receipt number, VAT split inside the gross, LPO in the notes', $legOk, json_encode($a['legs'] ?? []));
ok('b1: paid 2,330, guest still owes its 200', $near($a['after1']['amount_paid'] ?? 0, 2330) && $near($a['after1']['amount_due'] ?? 0, 200) && ($a['after1']['payment_status'] ?? '') === 'partial', json_encode($a['after1'] ?? []));
ok('b2: paid in full', $near($a['after2']['amount_due'] ?? -1, 0) && ($a['after2']['payment_status'] ?? '') === 'paid');
ok('b1 split after: company 0, guest 200, company paid 2,330', $near($a['split1_after']['company_due'] ?? -1, 0) && $near($a['split1_after']['guest_due'] ?? 0, 200) && $near($a['split1_after']['company_paid'] ?? 0, 2330), json_encode($a['split1_after'] ?? []));
ok('History on the booking: "Company payment recorded"', ($a['rem_history']['action'] ?? '') === 'Company payment recorded');
ok('Edit payment refuses a company leg', stripos((string)($a['edit_leg'] ?? ''), 'company payment') !== false, (string)($a['edit_leg'] ?? ''));
ok('a 500 refund of a company leg puts 500 back on the company (read from the payment rows)', $near($a['split1_refund']['company_paid'] ?? 0, 1830) && $near($a['split1_refund']['company_due'] ?? 0, 500)
    && $near($a['split1_refund']['guest_due'] ?? 0, 200), json_encode($a['split1_refund'] ?? []));
ok('taking the company off: the automatic 083 switch goes off too', (int)($a['b1_off']['checkin_without_payment'] ?? 1) === 0 && empty($a['b1_off']['billing_account_id']), json_encode($a['b1_off'] ?? []));

echo "== group ==\n";
$g = br_child('group');
ok('a group member follows its primary booking\'s company', !empty($g['member']['on_account']) && ($g['member']['reference'] ?? '') === 'LPO-G' && $near($g['member']['company_due'] ?? 0, 1165), json_encode($g['member'] ?? []));
ok('the company page lists the primary and the member', (int)($g['open'] ?? 0) === 2);
ok('unpaid reminder / auto-release: a member of an on-account primary is NOT a candidate; a normal booking is', empty($g['chase']['member']) && empty($g['chase']['primary']) && !empty($g['chase']['control']), json_encode($g['chase'] ?? []));
ok('auto-release re-check under the lock leaves the member alone', empty($g['release_member']) && !empty($g['release_control']), json_encode([$g['release_member'] ?? null, $g['release_control'] ?? null]));
ok('083 the same way: a member of a "Check in before payment" primary is not chased', empty($g['chase_083_member']));

echo "== documents with no billing email ==\n";
$d = br_child('docs');
ok('statement: not sent, staff told to add a billing email', empty($d['statement']['success']) && stripos((string)($d['statement']['message'] ?? ''), 'no billing email') !== false, json_encode($d['statement'] ?? []));
ok('statement: email_send_log not_sent (company)', ($d['statement_log']['status'] ?? '') === 'not_sent' && ($d['statement_log']['email_kind'] ?? '') === 'company_statement', json_encode($d['statement_log'] ?? []));
ok('final invoice: generated, NOT sent to anyone (not even when the guest box is ticked), staff note', !empty($d['invoice']['success']) && empty($d['invoice']['email_sent'])
    && stripos((string)($d['invoice']['staff_note'] ?? ''), 'no billing email') !== false, json_encode($d['invoice'] ?? []));
ok('final invoice: not_sent logged, History "Checked out on company account"', ($d['invoice_log']['status'] ?? '') === 'not_sent' && ($d['invoice_history']['action'] ?? '') === 'Checked out on company account', json_encode([$d['invoice_log'] ?? [], $d['invoice_history'] ?? []]));
ok('statement PDF lists the LPO', !empty($d['statement_html_has_lpo']));
ok('invoice is billed to the company with the LPO', !empty($d['invoice_billed_to_company']));
ok('no email was sent at all', (int)($d['sent_rows'] ?? -1) === 0);

echo "== pages (rolled back) ==\n";
$p = br_run('admin/billing-accounts.php', [], ['method' => 'GET', 'grep' => ['Company Accounts', 'Owed by companies', 'Add a company']]);
ok('Company accounts page renders', !empty($p['found']['Company Accounts']) && !empty($p['found']['Owed by companies']) && !empty($p['found']['Add a company']), json_encode($p['found'] ?? $p['raw'] ?? ''));
$name = 'ZZ PAGE CO ' . random_int(1000, 9999);
$p = br_run('admin/billing-accounts.php', ['action' => 'save_account', 'account_id' => '0', 'name' => $name, 'billing_email' => 'not-an-email'],
    ['verify' => ["SELECT COUNT(*) c FROM billing_accounts WHERE name = '" . $name . "'"]]);
ok('a bad billing email is refused (nothing saved)', (int)($p['verify'][0][0]['c'] ?? -1) === 0);
$p = br_run('admin/billing-accounts.php', ['action' => 'save_account', 'account_id' => '0', 'name' => $name, 'billing_email' => 'billing@example.invalid', 'terms_days' => '45', 'active' => '1'],
    ['verify' => ["SELECT terms_days, billing_email FROM billing_accounts WHERE name = '" . $name . "'"]]);
ok('a company is added', (int)($p['verify'][0][0]['terms_days'] ?? 0) === 45 && ($p['verify'][0][0]['billing_email'] ?? '') === 'billing@example.invalid', json_encode($p['verify'] ?? $p['raw'] ?? ''));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
