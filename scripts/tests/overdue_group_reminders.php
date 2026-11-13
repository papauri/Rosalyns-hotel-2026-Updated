<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Overdue payment reminders for GROUP bookings (owner 2026-11-13). Usage (repo root, TEST HOTEL ONLY):
 *   php scripts/tests/overdue_group_reminders.php
 * Checks the read-only planner of includes/auto-email-jobs.php (rh_auto_group_reminders / rh_auto_overdue_preview):
 *  - ONE reminder per group for the whole group balance once every live room is checked out (no-show = finished,
 *    its owed balance included; a cancelled room only for what it kept); never while a room is still in the house;
 *  - a group's lead is no longer chased on its own balance; a single booking is unchanged;
 *  - a cancelled lead hands the reminder (recipient, reference) to the next active room;
 *  - a company group: only the guest share (own key); the company share is never chased here (weekly statement);
 *  - dedupe key ('room', group root) = the old per-lead key, so an earlier stage is never sent again;
 *  - the reminder amount equals the attached group invoice's balance.
 * Nothing is ever sent: the job itself is not run. Everything runs in ONE transaction that is rolled back.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/includes/auto-email-jobs.php';
require_once $root . '/includes/billing-accounts.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= BALANCE_TOLERANCE;
// In memory only (never saved): test mode on, default stages and window.
$GLOBALS['_SITE_SETTINGS']['automated_email_test_recipient'] = 'test@example.invalid';
$GLOBALS['_SITE_SETTINGS']['automated_email_reminder_stages'] = '1,3,7';
$GLOBALS['_SITE_SETTINGS']['automated_email_lookback_days'] = '30';

/* ── pure ── */
$f = rh_auto_group_finished([
    ['status' => 'checked-out', 'checkout_completed_at' => '2026-01-03 10:00:00', 'check_out_date' => '2026-01-02'],
    ['status' => 'checked-out', 'checkout_completed_at' => null, 'check_out_date' => '2026-01-05'],
    ['status' => 'no-show', 'check_out_date' => '2026-01-09'],
    ['status' => 'cancelled', 'check_out_date' => '2026-01-09'],
    ['status' => 'checked-in', 'deleted_at' => '2026-01-01 00:00:00', 'check_out_date' => '2026-01-09'],
]);
ok('finished: checked-out + no-show + cancelled + deleted = finished, last checkout counted', $f['finished'] && $f['last_out'] === '2026-01-05', json_encode($f));
$f = rh_auto_group_finished([['status' => 'checked-out', 'check_out_date' => '2026-01-02'], ['status' => 'checked-in', 'check_out_date' => '2026-01-04']]);
ok('finished: a room still in the house = not due', !$f['finished'] && $f['open'] === 1);
$f = rh_auto_group_finished([['status' => 'no-show', 'check_out_date' => '2026-01-02'], ['status' => 'no-show', 'check_out_date' => '2026-01-02']]);
ok('finished: an all-no-show group is not chased (no checkout)', !$f['finished']);
ok('stages 1,3,7: 0/1/3/6/10 days', [rh_auto_stage_for_days(0, [1, 3, 7]), rh_auto_stage_for_days(1, [1, 3, 7]), rh_auto_stage_for_days(3, [1, 3, 7]),
    rh_auto_stage_for_days(6, [1, 3, 7]), rh_auto_stage_for_days(10, [1, 3, 7])] === [0, 1, 2, 2, 3]);

/* ── DB, rolled back ── */
/** A room booking 1,165 a night all-in, $nights nights ending $endAgo days ago. */
function og_booking(PDO $pdo, string $email, int $endAgo, ?int $primaryId = null, int $nights = 2): int {
    $out = date('Y-m-d', strtotime('-' . $endAgo . ' day'));
    $in = date('Y-m-d', strtotime($out . ' -' . $nights . ' day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'OGR TEST',?,'+265000000009','Malawi',1,1,0,50,?,?,?,?,0,0,0,16.5,?,?,'overdue group test','checked-in','unpaid',0,'single',?,?,NOW())")
        ->execute(['OGR' . random_int(100000, 999999), $email, $in, $out, $nights, 1000 * $nights, 165 * $nights, 1165 * $nights, bin2hex(random_bytes(16)), $primaryId]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return $id;
}
function og_status(PDO $pdo, int $bid, string $status, int $endAgo = 0): void {
    if ($status === 'checked-out') {
        $d = date('Y-m-d', strtotime('-' . $endAgo . ' day'));
        $pdo->prepare("UPDATE bookings SET status = 'checked-out', checkout_completed_at = CONCAT(?, ' 11:00:00') WHERE id = ?")->execute([$d, $bid]);
    } else {
        $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?')->execute([$status, $bid]);
    }
    recalculateBookingFinancials($bid);
}
function og_pay(PDO $pdo, int $bid, float $amt): void {
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, 0, 0, ?, 'cash', 'partial_payment', 'completed', NOW())")
        ->execute(['OGRP' . random_int(100000, 999999), $bid, $amt, $amt]);
    recalculateBookingFinancials($bid);
}
function og_due(PDO $pdo, int $bid): float {
    $st = $pdo->prepare('SELECT amount_due FROM bookings WHERE id = ?');
    $st->execute([$bid]);
    return (float)$st->fetchColumn();
}
function og_ref(PDO $pdo, int $bid): string {
    $st = $pdo->prepare('SELECT booking_reference FROM bookings WHERE id = ?');
    $st->execute([$bid]);
    return (string)$st->fetchColumn();
}
$stages = [1, 3, 7];
$plan = static fn(PDO $pdo, int $root): array => rh_auto_group_reminders($pdo, $root, $stages, 30);
$previewFor = static function (PDO $pdo, int $id): array {
    return array_values(array_filter(rh_auto_overdue_preview($pdo, true), static fn($r) => $r['type'] === 'room' && (int)$r['key_id'] === $id));
};

$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$chk = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
$pdo->beginTransaction();
$g1 = 0;
try {
    // G1: lead + 3 members, ended 2 days ago. Lead part-paid; member A no-show owing; member B cancelled (nothing kept); member C in house.
    $g1 = og_booking($pdo, 'booker1@example.invalid', 2);
    $a = og_booking($pdo, 'member@example.invalid', 2, $g1);
    $b = og_booking($pdo, 'member@example.invalid', 2, $g1);
    $c = og_booking($pdo, 'member@example.invalid', 2, $g1);
    og_pay($pdo, $g1, 500);
    og_status($pdo, $g1, 'checked-out', 2);
    og_status($pdo, $a, 'no-show');
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = 0 WHERE id = ?")->execute([$b]);
    recalculateBookingFinancials($b);
    $p = $plan($pdo, $g1);
    ok('G1 room still in the house: no reminder', !$p['entries'] && $p['reason'] === 'rooms still open', json_encode($p));
    og_status($pdo, $c, 'checked-out', 3);
    $want = og_due($pdo, $g1) + og_due($pdo, $a) + og_due($pdo, $c);
    $p = $plan($pdo, $g1);
    $e = $p['entries'][0] ?? [];
    ok('G1 all finished: ONE reminder', count($p['entries']) === 1, json_encode($p));
    ok('G1 amount = lead + owing no-show + checked-out member (cancelled room adds nothing)', $near($e['amount'] ?? -1, $want) && $want > 2 * 2330 - 500 - 0.01,
        ($e['amount'] ?? '-') . ' vs ' . $want);
    ok('G1 days from the LAST checkout (2 days) -> stage 1', ($e['days_overdue'] ?? -1) === 2 && ($e['stage'] ?? -1) === 1, json_encode($e));
    ok('G1 key = (room, group root), payer guest, booker on the lead', ($e['key_type'] ?? '') === 'room' && ($e['root'] ?? 0) === $g1
        && ($e['payer'] ?? '') === 'guest' && ($e['email'] ?? '') === 'booker1@example.invalid');
    $pv = $previewFor($pdo, $g1);
    ok('G1 in the preview once, as a group (no single-room row for the lead)', count($pv) === 1 && !empty($pv[0]['group']), json_encode($pv));
    $docs = rh_auto_group_entry_docs($pdo, $e);
    ok('G1 reminder amount = the attached group invoice balance', count($docs) === 1 && $near($docs[0][2], $e['amount'] ?? -2)
        && strpos($docs[0][1], (string)og_ref($pdo, $g1)) !== false, json_encode(array_map(static fn($d) => [$d[0], $d[2]], $docs)));

    // Dedupe: a stage 1 already sent under the OLD per-lead key ('room', lead id) is the same key -> no second stage 1.
    $pdo->prepare("INSERT INTO automated_email_log (job, account_type, account_id, stage, recipient, subject, status) VALUES ('overdue_payment_reminders', 'room', ?, 'test:r1', 'x', 'x', 'sent')")
        ->execute([$g1]);
    $pv = $previewFor($pdo, $g1);
    ok('G1 stage 1 sent under the old key shows as sent', ($pv[0]['log'] ?? '') === 'sent', json_encode($pv));
    ok('G1 claim of the same stage refused (never twice)', !rh_auto_log_claim($pdo, 'overdue_payment_reminders', 'room', $g1, 'test:r1', 'x', 'x'));

    // A payment that settles the group stops it.
    og_pay($pdo, $c, og_due($pdo, $c));
    og_pay($pdo, $a, og_due($pdo, $a));
    og_pay($pdo, $g1, og_due($pdo, $g1));
    $p = $plan($pdo, $g1);
    ok('G1 settled: no reminder', !$p['entries'] && $p['reason'] === 'nothing owed', json_encode($p));

    // G2: cancelled lead (nothing kept) + 2 members checked out 4 days ago -> chased, from the next active room.
    $g2 = og_booking($pdo, 'booker2@example.invalid', 4);
    $m1 = og_booking($pdo, 'room2@example.invalid', 4, $g2);
    $m2 = og_booking($pdo, 'room3@example.invalid', 4, $g2);
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = 0 WHERE id = ?")->execute([$g2]);
    recalculateBookingFinancials($g2);
    og_status($pdo, $m1, 'checked-out', 4);
    og_status($pdo, $m2, 'checked-out', 4);
    $eff = rh_booking_group_effective_lead($pdo, $g2);
    $p = $plan($pdo, $g2);
    $e = $p['entries'][0] ?? [];
    ok('G2 cancelled lead: the group is chased (was never chased before)', count($p['entries']) === 1, json_encode($p));
    ok('G2 amount = both members; key stays on the root', $near($e['amount'] ?? -1, og_due($pdo, $m1) + og_due($pdo, $m2)) && ($e['root'] ?? 0) === $g2 && ($e['key_type'] ?? '') === 'room');
    ok('G2 recipient + reference = the effective lead', $eff !== $g2 && ($e['ref'] ?? '') === og_ref($pdo, $eff)
        && ($e['email'] ?? '') === ($eff === $m1 ? 'room2@example.invalid' : 'room3@example.invalid'), "eff=$eff " . json_encode($e));
    ok('G2 4 days -> stage 2', ($e['stage'] ?? 0) === 2);

    // Pay-later arrangement on any room: the group is not chased.
    if (rh_checkin_arrangement_columns_present($pdo)) {
        $pdo->prepare('UPDATE bookings SET checkin_without_payment = 1 WHERE id = ?')->execute([$m2]);
        $p = $plan($pdo, $g2);
        ok('G2 pay-later arrangement on a room: not chased', !$p['entries'] && $p['reason'] === 'pay-later arrangement');
        $pdo->prepare('UPDATE bookings SET checkin_without_payment = 0 WHERE id = ?')->execute([$m2]);
    }

    // Single: exactly as before (own balance, own key).
    $s = og_booking($pdo, 'single@example.invalid', 1);
    og_status($pdo, $s, 'checked-out', 1);
    $pv = $previewFor($pdo, $s);
    ok('single: one row, own balance, key (room, id), stage 1', count($pv) === 1 && empty($pv[0]['group']) && $near($pv[0]['amount'], og_due($pdo, $s))
        && $pv[0]['key_type'] === 'room' && $pv[0]['stage'] === 1, json_encode($pv));

    // G3: company group (covers the stay, terms 0), a bar charge on a member for the guest.
    if (rh_billing_ready($pdo)) {
        $pdo->prepare("INSERT INTO billing_accounts (name, billing_email, terms_days, active, created_at) VALUES ('ZZ OGR CO', 'co@example.invalid', 0, 1, NOW())")->execute();
        $acc = (int)$pdo->lastInsertId();
        $g3 = og_booking($pdo, 'booker3@example.invalid', 2);
        $n1 = og_booking($pdo, 'room@example.invalid', 2, $g3);
        $pdo->prepare("UPDATE bookings SET billing_account_id = ?, billing_covers = 'stay' WHERE id = ?")->execute([$acc, $g3]);
        $pdo->prepare("INSERT INTO booking_charges (booking_id, charge_type, description, quantity, unit_price, line_subtotal, vat_rate, vat_amount, line_total, posted_at, voided)
            VALUES (?, 'other', 'Bar (test)', 1, 300, 300, 0, 0, 300, NOW(), 0)")->execute([$n1]);
        og_status($pdo, $g3, 'checked-out', 2);
        og_status($pdo, $n1, 'checked-out', 2);
        $p = $plan($pdo, $g3);
        $by = [];
        foreach ($p['entries'] as $x) { $by[$x['payer']] = $x; }
        ok('G3 company group: ONE reminder, the guest share only (company money is chased by the weekly statement)',
            count($p['entries']) === 1 && isset($by['guest']) && !isset($by['company']), json_encode($p));
        ok('G3 guest share = the bar charge, to the booker, key room_g:<id>', $near($by['guest']['amount'] ?? -1, 300)
            && ($by['guest']['email'] ?? '') === 'booker3@example.invalid' && ($by['guest']['key_type'] ?? '') === 'room_g:' . $acc);
        $gd = rh_auto_group_entry_docs($pdo, $by['guest'] ?? []);
        ok('G3 guest gets the extras document of the room with the bar charge (never the company invoice)', count($gd) === 1
            && strpos($gd[0][0], 'EXT-') === 0 && $near($gd[0][2], $by['guest']['amount'] ?? -1) && ($by['guest']['invoice_no'] ?? '') === $gd[0][0],
            json_encode(array_map(static fn($d) => [$d[0], $d[2]], $gd)));
        // Guest pays the bar charge: nothing left for this job (the company's stay share is never chased here).
        og_pay($pdo, $n1, 300);
        $p = $plan($pdo, $g3);
        ok('G3 guest share paid: no reminder at all, though the company still owes the stay', !$p['entries'] && og_due($pdo, $g3) > 1, json_encode($p));
    } else {
        echo "[SKIP] company groups: migration 084 not run\n";
    }
} finally {
    if (!$pdo->inTransaction()) {
        fwrite(STDERR, "!! transaction was implicitly committed\n");
        exit(2);
    }
    $pdo->rollBack();
}
$st = $chk->prepare('SELECT COUNT(*) FROM bookings WHERE id = ?');
$st->execute([$g1]);
ok('rolled back: no test booking left (second connection)', (int)$st->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
