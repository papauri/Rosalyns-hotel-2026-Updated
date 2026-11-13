<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Group-document follow-ups (owner 2026-11-13). Usage (Liwonde root): php scripts/tests/invoice_followups.php
 * Everything runs inside ONE transaction that is rolled back (a second connection checks nothing leaked); invoice
 * numbers used here are made-up labels, never taken from the sequence. Refuses to run against Rosalyn's (LIVE).
 *  F1 - a group has ONE invoice number per payer: after the lead is cancelled, the invoice built from the new lead reuses
 *       the number already issued on the group (original lead first, then any room, then a payment row; refund rows
 *       never); split payers keep their own numbers; a single booking is never looked up.
 *  F2 - a cancelled booking's invoice prints only what was kept on cancellation ("Cancelled on <date> - ..."), its lines
 *       foot to the unchanged total (folio summary), in both VAT modes and the custom template; extras still print;
 *       a fully cancelled group's lead gets the same kept-only row; a no-show still prints its full stay.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/includes/booking-functions.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/includes/billing-accounts.php';
require_once $root . '/config/invoice.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= 0.011;

/** A room booking 1,165 a night all-in (1,000 net + 165 VAT, no levy), far in the future. */
function iv_booking(PDO $pdo, ?int $primaryId = null, string $status = 'confirmed', int $nights = 2): int {
    $in = date('Y-m-d', strtotime('+700 day'));
    $out = date('Y-m-d', strtotime('+' . (700 + $nights) . ' day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'IVFU TEST','','+265000000009','Malawi',1,1,0,50,?,?,?,?,0,0,0,16.5,?,?,'invoice follow-up test',?,'unpaid',0,'single',?,?,NOW())")
        ->execute(['IVFU' . random_int(100000, 999999), $in, $out, $nights, 1000 * $nights, 165 * $nights, 1165 * $nights, $status, bin2hex(random_bytes(16)), $primaryId]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return $id;
}
function iv_pay(PDO $pdo, int $bid, float $amt, string $invoiceNo = '', bool $refund = false): void {
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, refund_status, refund_amount, refund_reason, invoice_number, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, 0, 0, ?, 'cash', ?, 'completed', ?, ?, ?, ?, NOW())")
        ->execute(['IVFP' . random_int(100000, 999999), $bid, $amt, $amt, $refund ? 'refund' : 'partial_payment', $refund ? 'completed' : null, $refund ? $amt : 0,
            $refund ? 'cancellation' : null,
            $invoiceNo !== '' ? $invoiceNo : null]);
    recalculateBookingFinancials($bid);
}
/** Cancelled as the given mode leaves it: status, what was kept, the history entry every cancel path writes. */
function iv_cancel(PDO $pdo, int $bid, float $kept): void {
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = ? WHERE id = ?")->execute([$kept, $bid]);
    logBookingEvent($bid, 'IVFU', 'Cancellation settled', 'financial', 'invoice follow-up test', 'confirmed', 'cancelled', 'admin', null, null, ['test' => true]);
    recalculateBookingFinancials($bid);
}
function iv_doc(PDO $pdo, int $anyId): array {
    $rows = rh_invoice_doc_rows($pdo, $anyId);
    $t = null;
    $html = buildInvoiceHTML($rows['booking'], 'TEST-IVFU', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rows['group_bookings'], null, false, $t);
    $grand = 0.0; $paid = 0.0;
    foreach ($t['ids'] as $id) {
        $fs = getBookingFolioSummary((int)$id);
        $grand += (float)($fs['grand_total'] ?? 0);
        $paid += (float)($fs['amount_paid'] ?? 0);
    }
    return ['rows' => $rows, 't' => $t, 'html' => (string)$html, 'text' => html_entity_decode(strip_tags((string)$html), ENT_QUOTES), 'grand' => round($grand, 2), 'paid' => round($paid, 2)];
}
$foots = static function (array $t) use ($near): bool {
    $m = vat_mode();
    $sum = (float)$t['subtotal'] + ($m === 'exclusive' ? (float)$t['vat'] : 0.0) + (float)$t['levy'];
    return $near($sum, $t['total']) || ($m === 'off' && $near((float)$t['subtotal'] + (float)$t['levy'], $t['total']));
};
$num = static function (PDO $pdo, int $anyId): string {
    $r = rh_invoice_doc_rows($pdo, $anyId);
    return rh_invoice_group_number($pdo, $r['scope_ids'], $r['root_id']);
};
$admin = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();

if (function_exists('ensureBookingAuditTable')) {
    ensureBookingAuditTable($pdo); // DDL commits implicitly: never inside the test transaction
}
$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$chk = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
$made = [];
$files = [];
$tag = 'IVFU' . random_int(100000, 999999);
$pdo->beginTransaction();
if (($argv[1] ?? '') === 'custom') {
    // Sub-run (the template choice is cached once per process): the admin-customised stored template path.
    try {
        $GLOBALS['_SITE_SETTINGS']['invoice_document_template_mode'] = 'custom'; // in memory only, before any render
        $c3 = iv_booking($pdo); $made[] = $c3;
        $pdo->prepare("INSERT INTO booking_charges (booking_id, charge_type, description, quantity, unit_price, line_subtotal, vat_rate, vat_amount, line_total, posted_at, voided)
            VALUES (?, 'other', 'Bar IVFU', 1, 200, 200, 0, 0, 200, NOW(), 0)")->execute([$c3]);
        iv_cancel($pdo, $c3, 1165.0);
        $d = iv_doc($pdo, $c3);
        if (strpos($d['html'], '#F7F3EE') === false) {
            ok('F2 custom template: skipped (no stored template at this hotel)', true);
        } else {
            ok('F2 custom template: kept-only row, no full stay line', strpos($d['text'], 'Cancelled on ' . date('d M Y')) !== false && strpos($d['text'], 'Accommodation') === false
                && strpos($d['text'], 'Bar IVFU') !== false && $near($d['t']['total'], 1365), mb_substr($d['text'], 0, 300));
        }
    } catch (Throwable $e) {
        ok('custom: no exception', false, $e->getMessage() . ' @' . $e->getLine());
    } finally {
        $pdo->rollBack();
    }
    $s = $chk->prepare('SELECT COUNT(*) FROM bookings WHERE id IN (' . implode(',', array_fill(0, max(1, count($made)), '?')) . ')');
    $s->execute($made ?: [0]);
    ok('custom: rolled back', (int)$s->fetchColumn() === 0);
    echo "\n$pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}
try {
    // ---- F1-A: group numbered on its lead, lead cancelled, invoiced from the new lead -> the same number ----
    $g0 = iv_booking($pdo); $g1 = iv_booking($pdo, $g0); $g2 = iv_booking($pdo, $g0);
    $made = array_merge($made, [$g0, $g1, $g2]);
    $gNo = $tag . '-GROUP';
    $pdo->prepare('UPDATE bookings SET final_invoice_number = ? WHERE id = ?')->execute([$gNo, $g0]);
    $r = rh_invoice_doc_rows($pdo, $g2);
    ok('F1-A: scope = every room of the group, root = the lead', $r['root_id'] === $g0 && $r['scope_ids'] === [$g0, $g1, $g2], json_encode([$r['root_id'], $r['scope_ids']]));
    cancelRoomBookingSettled($pdo, $g0, $admin, 'invoice follow-up test (lead)');
    $r = rh_invoice_doc_rows($pdo, $g2);
    ok('F1-A: lead cancelled -> document built from the next room', (int)$r['booking']['id'] === $g1, (string)$r['booking']['id']);
    $found = $num($pdo, $g2);
    ok('F1-A: the group number on the cancelled lead is found', $found === $gNo, $found);
    if ($found === $gNo) { // only then: a broken lookup would take a real number from the sequence
        foreach ([$g1, $g2] as $from) {
            $gen = generateInvoicePDF($from, null);
            if ($gen) {
                $files[] = $gen['filepath'];
            }
            ok('F1-A: invoice made from room ' . ($from === $g1 ? 'new lead' : 'member') . ' reuses the group number', $gen && $gen['invoice_number'] === $gNo, json_encode($gen ? $gen['invoice_number'] : false));
        }
        $stm = rh_issue_paid_statement($pdo, $g2, false); // not emailed
        if (!empty($stm['filepath'])) {
            $files[] = $stm['filepath'];
        }
        ok('F1-A: paid statement from a member carries the group number (not a STMT- label)', ($stm['invoice_number'] ?? '') === $gNo, json_encode($stm));
    }

    // ---- F1-B: order - original lead's number, then any room's, then a payment row's; refund rows never ----
    $h0 = iv_booking($pdo); $h1 = iv_booking($pdo, $h0);
    $made = array_merge($made, [$h0, $h1]);
    ok('F1-B: nothing issued yet -> "" (the sequence decides)', $num($pdo, $h1) === '');
    iv_pay($pdo, $h0, 500, $tag . '-PAY');
    iv_pay($pdo, $h1, 100, $tag . '-CN', true);
    ok('F1-B: number held on a payment row is reused; a refund row\'s credit-note number never', $num($pdo, $h1) === $tag . '-PAY', $num($pdo, $h1));
    $pdo->prepare('UPDATE bookings SET final_invoice_number = ? WHERE id = ?')->execute([$tag . '-MEM', $h1]);
    ok('F1-B: a room\'s final invoice number wins over a payment row', $num($pdo, $h0) === $tag . '-MEM', $num($pdo, $h0));
    $pdo->prepare('UPDATE bookings SET final_invoice_number = ? WHERE id = ?')->execute([$tag . '-ROOT', $h0]);
    ok('F1-B: the original lead\'s number wins over another room\'s', $num($pdo, $h1) === $tag . '-ROOT', $num($pdo, $h1));
    $pdo->prepare('UPDATE bookings SET final_invoice_number = ? WHERE id = ?')->execute(['STMT-' . $tag, $h0]);
    ok('F1-B: a STMT- statement label is never reused as an invoice number', $num($pdo, $h1) === $tag . '-MEM', $num($pdo, $h1));

    // ---- F1-B2: payments.invoice_number is UNIQUE: the group number already on one payment row is not written onto another ----
    $pidOf = static function (PDO $pdo, int $bid): int {
        $s = $pdo->prepare("SELECT MAX(id) FROM payments WHERE booking_id = ? AND booking_type = 'room' AND COALESCE(payment_type, '') <> 'refund'");
        $s->execute([$bid]);
        return (int)$s->fetchColumn();
    };
    iv_pay($pdo, $h1, 50);
    $p2 = $pidOf($pdo, $h1);
    $stored = rh_invoice_payment_save($pdo, $p2, 'invoices/' . $tag . '-PAY.pdf', $tag . '-PAY');
    $pr = $pdo->prepare('SELECT invoice_number, invoice_path, invoice_generated FROM payments WHERE id = ?');
    $pr->execute([$p2]);
    $pr = $pr->fetch(PDO::FETCH_ASSOC);
    ok('F1-B2: number already on another payment row -> only this row\'s file is refreshed (no duplicate)', $stored === false && ($pr['invoice_number'] ?? null) === null
        && $pr['invoice_path'] === 'invoices/' . $tag . '-PAY.pdf' && (int)$pr['invoice_generated'] === 1, json_encode($pr));
    $stored = rh_invoice_payment_save($pdo, $p2, 'invoices/' . $tag . '-NEW.pdf', $tag . '-NEW');
    ok('F1-B2: a number not held elsewhere is stored on the row', $stored === true);

    // ---- F1-C: a single booking is never looked up (unchanged) ----
    $s0 = iv_booking($pdo);
    $made[] = $s0;
    $pdo->prepare('UPDATE bookings SET final_invoice_number = ? WHERE id = ?')->execute([$tag . '-SOLO', $s0]);
    $r = rh_invoice_doc_rows($pdo, $s0);
    ok('F1-C: single booking: scope = itself, no group lookup', $r['scope_ids'] === [$s0] && rh_invoice_group_number($pdo, $r['scope_ids'], $r['root_id']) === '', json_encode($r['scope_ids']));

    // ---- F1-D: split payers keep their own numbers ----
    if (rh_billing_ready($pdo)) {
        $pdo->prepare("INSERT INTO billing_accounts (name, contact_name, billing_email, terms_days, active, created_at) VALUES (?, 'Test Contact', NULL, 30, 1, NOW())")
            ->execute(['ZZ IVFU CO ' . random_int(1000, 9999)]);
        $acc = (int)$pdo->lastInsertId();
        $k0 = iv_booking($pdo); $k1 = iv_booking($pdo, $k0); $k2 = iv_booking($pdo, $k0); $k3 = iv_booking($pdo, $k0);
        $made = array_merge($made, [$k0, $k1, $k2, $k3]);
        $pdo->prepare('UPDATE bookings SET billing_account_id = ? WHERE id IN (?, ?)')->execute([$acc, $k2, $k3]);
        $pdo->prepare('UPDATE bookings SET final_invoice_number = ? WHERE id = ?')->execute([$tag . '-GUEST', $k0]);
        $rc = rh_invoice_doc_rows($pdo, $k3);
        ok('F1-D: company rooms are their own scope', $rc['scope_ids'] === [$k2, $k3], json_encode($rc['scope_ids']));
        ok('F1-D: the guest document\'s number is not used for the company\'s', $num($pdo, $k3) === '', $num($pdo, $k3));
        $pdo->prepare('UPDATE bookings SET final_invoice_number = ? WHERE id = ?')->execute([$tag . '-CO', $k2]);
        ok('F1-D: company rooms share the company number; guest rooms keep theirs', $num($pdo, $k3) === $tag . '-CO' && $num($pdo, $k1) === $tag . '-GUEST',
            $num($pdo, $k3) . ' / ' . $num($pdo, $k1));
    } else {
        ok('F1-D: skipped (company billing not installed)', true);
    }

    // ---- F2: cancelled single booking prints only what was kept ----
    $today = date('d M Y');
    $modes = ['void_refund_all', 'void_keep_credit', 'keep_first_night'];
    // void_refund_all: paid 1,000, refunded 1,000, nothing kept
    $c1 = iv_booking($pdo); $made[] = $c1;
    iv_pay($pdo, $c1, 1000);
    iv_pay($pdo, $c1, 1000, '', true);
    iv_cancel($pdo, $c1, 0.0);
    // void_keep_credit: paid 1,000, nothing kept, held as credit
    $c2 = iv_booking($pdo); $made[] = $c2;
    iv_pay($pdo, $c2, 1000);
    iv_cancel($pdo, $c2, 0.0);
    // keep_first_night: first night (1,165) kept, + a 200 bar bill posted to the room
    $c3 = iv_booking($pdo); $made[] = $c3;
    $pdo->prepare("INSERT INTO booking_charges (booking_id, charge_type, description, quantity, unit_price, line_subtotal, vat_rate, vat_amount, line_total, posted_at, voided)
        VALUES (?, 'other', 'Bar IVFU', 1, 200, 200, 0, 0, 200, NOW(), 0)")->execute([$c3]);
    iv_cancel($pdo, $c3, 1165.0);
    foreach (['inclusive', 'exclusive'] as $vm) {
        $GLOBALS['_SITE_SETTINGS']['vat_pricing_mode'] = $vm; // in memory only
        foreach ($modes as $mode) {
            $GLOBALS['_SITE_SETTINGS']['cancellation_refund_mode'] = $mode; // in memory only: printing must not depend on it
            foreach ([$c1 => 'nothing billed for the stay', $c2 => "MWK 1,000.00 paid is held as the guest's credit", $c3 => 'the first night is kept'] as $cid => $want) {
                $d = iv_doc($pdo, $cid);
                $lbl = "F2 [$vm, setting $mode] booking " . ($cid === $c1 ? 'refunded' : ($cid === $c2 ? 'credit' : 'first night'));
                ok("$lbl: says when it was cancelled and what was kept", strpos($d['text'], 'Cancelled on ' . $today . ' - ') !== false && strpos($d['text'], $want) !== false,
                    mb_substr($d['text'], (int)strpos($d['text'], 'DESCRIPTION'), 400));
                ok("$lbl: the booked stay is not printed", stripos($d['text'], 'booked at') === false);
                ok("$lbl: total / paid = the folio summary (unchanged)", $near($d['t']['total'], $d['grand']) && $near($d['t']['paid'], $d['paid']), json_encode($d['t']));
                ok("$lbl: the lines foot to the total", $foots($d['t']), json_encode($d['t']));
            }
        }
    }
    $d = iv_doc($pdo, $c3);
    ok('F2: kept first night + extras = 1,365; the bar bill still prints', $near($d['t']['total'], 1365) && strpos($d['text'], 'Bar IVFU') !== false, json_encode($d['t']));
    ok('F2: refunded booking total 0, VAT 0, levy 0', $near(iv_doc($pdo, $c1)['t']['total'], 0) && $near(iv_doc($pdo, $c1)['t']['vat'], 0) && $near(iv_doc($pdo, $c1)['t']['levy'], 0));
    unset($GLOBALS['_SITE_SETTINGS']['vat_pricing_mode'], $GLOBALS['_SITE_SETTINGS']['cancellation_refund_mode']);

    // ---- F2: fully cancelled group: the lead prints its kept-only row too ----
    $q0 = iv_booking($pdo); $q1 = iv_booking($pdo, $q0);
    $made = array_merge($made, [$q0, $q1]);
    iv_pay($pdo, $q1, 300); // money on the member keeps it listed (group layout)
    iv_cancel($pdo, $q0, 0.0);
    iv_cancel($pdo, $q1, 1165.0);
    $d = iv_doc($pdo, $q1);
    ok('F2 group: built from the original lead, both rooms listed', (int)$d['rows']['booking']['id'] === $q0 && count($d['t']['ids']) === 2, json_encode($d['t']['ids']));
    ok('F2 group: no booked-stay line; the lead says it was cancelled', stripos($d['text'], 'booked at') === false && substr_count($d['text'], 'Cancelled on ' . $today) === 2,
        mb_substr($d['text'], (int)strpos($d['text'], 'DESCRIPTION'), 600));
    ok('F2 group: total = folio summary, lines foot', $near($d['t']['total'], $d['grand']) && $foots($d['t']), json_encode($d['t']));

    // ---- No-show is not cancelled: it keeps printing the full stay ----
    $n0 = iv_booking($pdo, null, 'no-show'); $made[] = $n0;
    $d = iv_doc($pdo, $n0);
    ok('no-show: full stay still printed (unchanged)', stripos($d['text'], 'booked at') !== false && strpos($d['text'], 'Cancelled on') === false && $near($d['t']['total'], 2330), json_encode($d['t']));
} catch (Throwable $e) {
    ok('no exception', false, $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($files as $f) {
        if (is_string($f) && is_file($f) && strpos(basename($f), $tag) === 0) {
            @unlink($f);
        }
    }
}
if ($made) {
    $s = $chk->prepare('SELECT COUNT(*) FROM bookings WHERE id IN (' . implode(',', array_fill(0, count($made), '?')) . ')');
    $s->execute($made);
    ok('rolled back: no test booking left', (int)$s->fetchColumn() === 0);
}
ok('rolled back: no test payment left', (int)$chk->query("SELECT COUNT(*) FROM payments WHERE payment_reference LIKE 'IVFP%'")->fetchColumn() === 0);
ok('rolled back: no test invoice number left', (int)$chk->query("SELECT COUNT(*) FROM bookings WHERE final_invoice_number LIKE " . $chk->quote($tag . '%')
    . " OR final_invoice_number LIKE " . $chk->quote('STMT-' . $tag))->fetchColumn() === 0);
ok('no test invoice file left', !glob($root . '/invoices/' . $tag . '*'));
// The custom (stored) template case runs in its own process (own transaction, rolled back).
$sub = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' custom 2>&1', $sub, $subRc);
echo implode("\n", $sub), "\n";
ok('custom template sub-run passed', $subRc === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
