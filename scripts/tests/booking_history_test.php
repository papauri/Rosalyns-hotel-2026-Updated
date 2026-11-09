<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Booking / payment history builder (includes/booking-history-format.php). NO DATABASE: the pure merge/format
 * helpers are fed fixture rows that reproduce the Guest A story (owner 2026-11-09):
 *   created 7 Nov 09:36 by the receptionist at K356,000 x 3 = K1,068,000; confirmed 8 Nov; 9 Nov 10:29 the accountant
 *   records K1,350,900 cash with the overpay box ticked; credit note CN-2026-000003 for K282,900 issued automatically,
 *   refund leg REF-2026-000003 (method credit note), credit note emailed; today's Superior Suite price K450,300.
 * Checks the order, the de-duplication, the plain-English lines and the price-change explanation with and without a
 * price-history row. Usage (repo root): php scripts/tests/booking_history_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

date_default_timezone_set('Africa/Blantyre');
require_once $root . '/includes/booking-history-format.php';
check(!function_exists('getSetting') && !isset($GLOBALS['pdo']), 'the pure helpers load without the database or settings');

$staff = [
    1 => ['name' => 'Owner Admin', 'username' => 'owner', 'role' => 'admin'],
    5 => ['name' => 'Rosalyns Reservations', 'username' => 'reservations', 'role' => 'receptionist'],
    7 => ['name' => 'Pat Ledger', 'username' => 'p.ledger', 'role' => 'accountant'],
];
$booking = [
    'id' => 2100008830, 'booking_reference' => 'RBH2026796618', 'room_id' => 2, 'occupancy_type' => 'double',
    'number_of_nights' => 3, 'number_of_guests' => 2, 'check_in_date' => '2026-11-09', 'check_out_date' => '2026-11-12',
    'total_amount' => 910278.48, 'vat_amount' => 157721.52, 'total_with_vat' => 1068000.00, 'tourism_levy_amount' => 9012.66,
    'tourism_levy_percent' => 1, 'vat_rate' => 17.5, 'child_supplement_total' => 0, 'rate_plan_label' => null, 'rate_plan_discount' => null,
    'status' => 'confirmed', 'created_at' => '2026-11-07 09:36:10',
];
$payments = [
    ['id' => 2099976256, 'payment_reference' => 'PAY202611D1E39A', 'payment_type' => 'payment', 'payment_status' => 'partially_refunded',
        'payment_method' => 'cash', 'total_amount' => 1350900.00, 'payment_amount' => 1139957.81, 'recorded_by' => null, 'processed_by' => 7,
        'receipt_number' => 'RCT-2026-000101', 'payment_date' => '2026-11-09', 'created_at' => '2026-11-09 10:29:40',
        'original_payment_id' => null, 'credit_note_id' => null, 'deleted_at' => null, 'receipt_emailed_at' => null],
    ['id' => 2099976257, 'payment_reference' => 'REF-2026-000003', 'payment_type' => 'refund', 'payment_status' => 'completed',
        'payment_method' => 'credit_note', 'total_amount' => 282900.00, 'refund_amount' => 282900.00, 'refund_status' => 'completed',
        'refund_reason' => 'overpayment', 'refund_notes' => 'Moved to credit note CN-2026-000003', 'original_payment_id' => 2099976256,
        'credit_note_id' => 3, 'recorded_by' => 7, 'created_at' => '2026-11-09 10:29:41', 'deleted_at' => null],
];
$creditNotes = [
    ['id' => 3, 'credit_note_number' => 'CN-2026-000003', 'booking_id' => 2100008830, 'booking_type' => 'room', 'original_amount' => 282900.00,
        'reason' => 'overpayment', 'reason_notes' => 'Overpaid by 282,900.00 on payment PAY202611D1E39A [account: room #2100008830]',
        'issued_by' => 7, 'issued_at' => '2026-11-09 10:29:41', 'email_sent_at' => '2026-11-09 10:29:45', 'guest_email' => 'guest@example.com',
        'voided_at' => null, 'original_payment_id' => null],
];
$audit = [
    ['action' => 'confirmed', 'old_values' => '{"status":"pending"}', 'new_values' => '{"status":"confirmed"}', 'note' => null,
        'performed_by' => 5, 'performed_by_name' => 'Rosalyns Reservations', 'performed_at' => '2026-11-08 08:15:02'],
    ['action' => 'created', 'old_values' => null, 'new_values' => '{"status":"pending","payment_status":"unpaid","total_rooms":1}',
        'note' => 'Created manually by admin (Rosalyns Reservations)', 'performed_by' => 5, 'performed_by_name' => 'Rosalyns Reservations',
        'performed_at' => '2026-11-07 09:36:11'],
];
$priceRow = ['id' => 11, 'entity_type' => 'room_type', 'entity_id' => 2, 'field' => 'price_double_occupancy', 'old_value' => '356000.00',
    'new_value' => '450300.00', 'changed_by' => 1, 'changed_by_name' => 'Owner Admin', 'changed_at' => '2026-11-07 10:06:30'];

$base = [
    'booking' => $booking, 'staff' => $staff, 'cur' => 'K', 'room_type_name' => 'Superior Suite',
    'entity_names' => ['room_type|2' => 'Superior Suite'], 'today_price' => 450300.00,
    'audit' => $audit, 'payments' => $payments, 'credit_notes' => $creditNotes,
];

// ---- money / time / who ----
check(rh_hist_money(282900, 'K') === 'K282,900', 'money: K282,900');
check(rh_hist_money(282900, 'MWK') === 'MWK 282,900', 'money: MWK gets a space');
check(rh_hist_money(100.5, 'K') === 'K100.50', 'money: tambala shown only when present');
check(rh_hist_time_label('2026-11-07 09:36:10') === '7 Nov 2026, 09:36', 'time label is local and plain');
check(rh_hist_who($staff, 7) === 'Pat Ledger (accountant)', 'who: staff name + role');
check(rh_hist_who($staff, 0, 'p.ledger') === 'Pat Ledger (accountant)', 'who: stored username resolves to the person');
check(rh_hist_who($staff, 0, 'Jane Guest', 'guest') === 'Guest (Jane Guest)', 'who: guest');
check(rh_hist_who($staff, 0, null, 'system') === 'System', 'who: system');
check(rh_hist_who($staff, 0, null) === 'Not recorded', 'who: nothing recorded says so');

// ---- 1. the story without a price-history row ----
$ev = rh_hist_build($base);
$whats = array_column($ev, 'what');
$kinds = array_column($ev, 'kind');
check(count(array_keys($kinds, 'created', true)) === 1, 'one "created" step (booking row + audit row merged)', implode(' | ', $whats));
check(($ev[0]['kind'] ?? '') === 'created' && $ev[0]['who'] === 'Rosalyns Reservations (receptionist)', 'step 1: created by the receptionist', json_encode($ev[0] ?? null));
check(($ev[0]['at'] ?? '') === '2026-11-07 09:36:10', 'step 1 keeps the earliest time of the merged rows');
check(strpos($ev[0]['detail'], 'Superior Suite, 3 nights') !== false && strpos($ev[0]['detail'], 'Total K1,068,000') !== false, 'step 1 says room, nights and total', $ev[0]['detail']);
check(strpos($ev[0]['detail'], 'Created manually by admin') === false, 'the generic audit note is not repeated');
check(($ev[1]['kind'] ?? '') === 'price' && $ev[1]['what'] === 'How the price was worked out', 'step 2: how the price was worked out, right after creation');
$px = $ev[1]['detail'] ?? '';
check(strpos($px, 'K356,000 a night × 3 nights = K1,068,000') !== false, 'price: nightly rate × nights = total', $px);
check(strpos($px, "Today's price for this room is K450,300 a night — the booking keeps the price it was made at.") !== false, 'price: today\'s price differs and the booking keeps its price', $px);
check(strpos($px, 'before price changes were recorded') !== false, 'price: no history row -> made before changes were recorded', $px);
check(strpos($px, 'VAT K157,721.52') !== false && strpos($px, '17.5%') !== false && strpos($px, 'tourism levy K9,012.66') !== false, 'price: stored VAT and levy shown', $px);
$conf = array_values(array_filter($ev, function ($e) { return $e['kind'] === 'status'; }));
check(count($conf) === 1 && $conf[0]['what'] === 'Booking confirmed' && $conf[0]['who'] === 'Rosalyns Reservations (receptionist)', 'confirmed on 8 Nov by the same user');
$pay = array_values(array_filter($ev, function ($e) { return $e['kind'] === 'payment'; }));
check(count($pay) === 1, 'one payment step');
check(($pay[0]['what'] ?? '') === 'Payment PAY202611D1E39A of K1,350,900 recorded (cash)', 'payment sentence', $pay[0]['what'] ?? '');
check(($pay[0]['who'] ?? '') === 'Pat Ledger (accountant)', 'payment by the accountant');
check(strpos($pay[0]['detail'] ?? '', 'the overpay box was ticked, so K282,900 went to credit note CN-2026-000003') !== false, 'payment says the overpay box was ticked (from its credit note)', $pay[0]['detail'] ?? '');
$cn = array_values(array_filter($ev, function ($e) { return $e['kind'] === 'credit_note'; }));
check(count($cn) === 1 && $cn[0]['who'] === 'System' && strpos($cn[0]['what'], 'CN-2026-000003 for K282,900 issued automatically') !== false, 'credit note issued automatically by the system', json_encode($cn));
check(strpos($cn[0]['detail'] ?? '', 'saved by Pat Ledger (accountant)') !== false && strpos($cn[0]['detail'] ?? '', '[account:') === false, 'credit note names who saved the payment; internal tag hidden', $cn[0]['detail'] ?? '');
$ref = array_values(array_filter($ev, function ($e) { return $e['kind'] === 'refund'; }));
check(count($ref) === 1 && strpos($ref[0]['what'], 'Refund leg REF-2026-000003 moved K282,900 into credit note CN-2026-000003') !== false, 'refund leg with method credit note', $ref[0]['what'] ?? '');
$em = array_values(array_filter($ev, function ($e) { return $e['kind'] === 'email'; }));
check(count($em) === 1 && $em[0]['what'] === 'Credit note CN-2026-000003 emailed to the guest', 'credit note email step');
$order = implode(',', $kinds);
check($order === 'created,price,status,payment,credit_note,refund,email', 'order oldest first: created, price, confirmed, payment, credit note, refund leg, email', $order);

// ---- 2. with a price-history row ----
$ev2 = rh_hist_build($base + ['price_changes' => [$priceRow], 'price_history' => [$priceRow]]);
$px2 = '';
foreach ($ev2 as $e) if ($e['what'] === 'How the price was worked out') $px2 = $e['detail'];
check(strpos($px2, 'before price changes were recorded') === false, 'with a history row: no "before recorded" line', $px2);
check(strpos($px2, 'Price changed on 7 Nov 2026, 10:06 by Owner Admin (admin): Superior Suite double-occupancy price changed from K356,000 to K450,300') !== false, 'with a history row: who changed the price and when', $px2);
$ph = array_values(array_filter($ev2, function ($e) { return $e['kind'] === 'price' && $e['what'] !== 'How the price was worked out'; }));
check(count($ph) === 1 && $ph[0]['at'] === '2026-11-07 10:06:30' && $ph[0]['who'] === 'Owner Admin (admin)', 'the price change is its own step at its own time');
check(implode(',', array_column($ev2, 'kind')) === 'created,price,price,status,payment,credit_note,refund,email', 'price change sits between creation and confirmation');

// ---- 3. de-duplication across tables ----
$dup = $base;
$dup['timeline'] = [
    ['action' => 'Booking created', 'action_type' => 'create', 'description' => 'New booking created', 'new_value' => 'pending',
        'performed_by_type' => 'admin', 'performed_by_id' => 5, 'performed_by_name' => 'Rosalyns Reservations', 'metadata' => null, 'created_at' => '2026-11-07 09:36:13'],
    ['action' => 'credit_note_issued', 'action_type' => 'financial', 'description' => 'Credit note CN-2026-000003 issued for K 282,900.00 (reason: overpayment)',
        'old_value' => null, 'new_value' => 'CN-2026-000003', 'performed_by_type' => 'admin', 'performed_by_id' => 7, 'created_at' => '2026-11-09 10:29:42'],
    ['action' => 'Payment recorded', 'action_type' => 'payment', 'description' => 'Received K1,350,900 by cash.', 'performed_by_type' => 'admin',
        'performed_by_id' => 7, 'metadata' => json_encode(['payment_ref' => 'PAY202611D1E39A', 'payment_id' => 2099976256, 'balance_before' => 1068000, 'balance_after' => -282900]),
        'created_at' => '2026-11-09 10:29:43'],
    ['action' => 'Status changed', 'action_type' => 'status_change', 'old_value' => 'pending', 'new_value' => 'confirmed', 'performed_by_type' => 'admin',
        'performed_by_id' => 5, 'created_at' => '2026-11-08 08:15:04'],
];
$dup['status_log'] = [['old_status' => 'pending', 'new_status' => 'confirmed', 'changed_by' => 5, 'change_reason' => '', 'created_at' => '2026-11-08 08:15:01']];
$dup['payment_audit'] = [['payment_id' => 2099976256, 'payment_reference' => 'PAY202611D1E39A', 'action' => 'created', 'field_name' => null, 'old_value' => null,
    'new_value' => 'Received K1,350,900 by cash. Owed before: K1,068,000. Owed after: K0. The overpay box was ticked.', 'performed_by' => 7,
    'performed_by_name' => 'Pat Ledger', 'created_at' => '2026-11-09 10:29:41']];
$ev3 = rh_hist_build($dup);
check(implode(',', array_column($ev3, 'kind')) === 'created,price,status,payment,credit_note,refund,email', 'same steps when the action is also in the timeline, status log and payment audit', implode(' | ', array_column($ev3, 'what')));
$p3 = array_values(array_filter($ev3, function ($e) { return $e['kind'] === 'payment'; }));
check(strpos($p3[0]['detail'], 'Owed before: K1,068,000. Owed after: K0. The overpay box was ticked.') !== false, 'merged payment keeps the logged owed-before/after line', $p3[0]['detail']);
check($p3[0]['what'] === 'Payment PAY202611D1E39A of K1,350,900 recorded (cash)', 'merged payment keeps the payment record\'s own sentence');
$c3 = array_values(array_filter($ev3, function ($e) { return $e['kind'] === 'credit_note'; }));
check(count($c3) === 1 && $c3[0]['who'] === 'System', 'credit note logged twice shows once and stays "System"');

// outside the 5-second window: two steps
$far = rh_hist_merge([
    rh_hist_ev('2026-11-08 08:15:00', 'A', 'Booking confirmed', '', 'status', ['status:confirmed'], 2),
    rh_hist_ev('2026-11-08 08:15:06', 'B', 'Booking confirmed', '', 'status', ['status:confirmed'], 1),
]);
check(count($far) === 2, 'same action 6 s apart stays two steps');
$near = rh_hist_merge([
    rh_hist_ev('2026-11-08 08:15:00', 'Not recorded', 'Booking confirmed', 'x', 'status', ['status:confirmed'], 2),
    rh_hist_ev('2026-11-08 08:15:05', 'Ann (manager)', 'Status changed', 'y', 'status', ['status:confirmed'], 1),
]);
check(count($near) === 1 && $near[0]['who'] === 'Ann (manager)' && $near[0]['what'] === 'Booking confirmed' && $near[0]['detail'] === "x\ny", 'within 5 s: one step, richer record kept, known person and both details kept');

// a creation logged 40 s after the booking row is still one step 1 (one booking is created once)
$late = $base;
$late['audit'][1]['performed_at'] = '2026-11-07 09:36:50';
$evL = rh_hist_build($late);
$cr = array_values(array_filter($evL, function ($e) { return $e['kind'] === 'created'; }));
check(count($cr) === 1 && $cr[0]['who'] === 'Rosalyns Reservations (receptionist)' && $cr[0]['at'] === '2026-11-07 09:36:10', 'created logged 40 s later: still one step 1, by the receptionist, at the booking time', json_encode($cr));
// two real payments of the same amount 2 s apart stay two payments (money is never merged away)
$twoPay = rh_hist_build(['cur' => 'K', 'staff' => $staff, 'payments' => [
    ['id' => 1, 'payment_reference' => 'PAYA', 'payment_type' => 'payment', 'payment_status' => 'completed', 'payment_method' => 'cash', 'total_amount' => 50000, 'processed_by' => 7, 'created_at' => '2026-11-09 11:00:00'],
    ['id' => 2, 'payment_reference' => 'PAYB', 'payment_type' => 'payment', 'payment_status' => 'completed', 'payment_method' => 'cash', 'total_amount' => 50000, 'processed_by' => 7, 'created_at' => '2026-11-09 11:00:02'],
]]);
check(count($twoPay) === 2, 'two payments of the same amount 2 s apart: two steps', implode(' | ', array_column($twoPay, 'what')));
// the logged payment line written long after the payment record still joins it (same reference)
$lateAudit = $dup;
$lateAudit['payment_audit'][0]['created_at'] = '2026-11-09 10:30:30';
$evA = rh_hist_build($lateAudit);
check(count(array_filter($evA, function ($e) { return $e['kind'] === 'payment'; })) === 1, 'payment audit line 50 s later joins its payment by reference');

// who saved an older payment that recorded nobody
$anon = $base;
$anon['payments'][0]['processed_by'] = null;
$pA = array_values(array_filter(rh_hist_build($anon), function ($e) { return $e['kind'] === 'payment'; }));
check(($pA[0]['who'] ?? '') === 'Pat Ledger (accountant) — from the credit note issued with it', 'no recorder: who comes from the credit note naming this exact payment', $pA[0]['who'] ?? '');
$anon2 = $anon;
$anon2['credit_notes'][0]['reason_notes'] = 'Overpaid by 282,900.00 on payment PAY202611D1E39AX [account: room #1]';
$pB = array_values(array_filter(rh_hist_build($anon2), function ($e) { return $e['kind'] === 'payment'; }));
check(($pB[0]['who'] ?? '') === 'Pat Ledger (accountant) — from the refund leg recorded with it', 'credit note names a longer reference: not used; refund leg within 5 s is', $pB[0]['who'] ?? '');
$anon3 = $anon2;
$anon3['payments'][1]['created_at'] = '2026-11-09 10:29:50';
$pC = array_values(array_filter(rh_hist_build($anon3), function ($e) { return $e['kind'] === 'payment'; }));
check(($pC[0]['who'] ?? '') === 'Not recorded', 'refund leg 10 s later and no matching credit note: not recorded', $pC[0]['who'] ?? '');
$anon4 = $anon;
$anon4['payment_audit'] = [['payment_id' => 2099976256, 'payment_reference' => 'PAY202611D1E39A', 'action' => 'created', 'field_name' => null,
    'new_value' => 'Received K1,350,900 by cash.', 'performed_by' => 5, 'created_at' => '2026-11-09 10:29:41']];
$pD = array_values(array_filter(rh_hist_build($anon4), function ($e) { return $e['kind'] === 'payment'; }));
check(($pD[0]['who'] ?? '') === 'Rosalyns Reservations (receptionist)', 'a "created" audit line names the person: no fallback', $pD[0]['who'] ?? '');

// ---- 4. payment history ----
$pf = rh_hist_payment_filter($ev, [2099976256, 2099976257]);
check(implode(',', array_column($pf, 'kind')) === 'created,price,payment,credit_note,refund,email', 'payment history: booking money steps + this payment, no status steps', implode(',', array_column($pf, 'kind')));

// ---- 5. snapshot (new bookings), override and rate plan ----
$snapEv = rh_hist_price_explanation($booking, ['source' => 'room_type', 'rate' => 356000, 'list_rate' => 356000, 'nights' => 3, 'total_with_vat' => 1068000],
    356000.0, [], ['cur' => 'K', 'staff' => $staff, 'room_type_name' => 'Superior Suite', 'entity_names' => []]);
check(strpos($snapEv['detail'], 'K356,000 a night × 3 nights = K1,068,000, using the double-occupancy price of Superior Suite at the time.') !== false, 'snapshot: says which price was used', $snapEv['detail']);
check(strpos($snapEv['detail'], "Today's price") === false, 'snapshot: same price today -> no difference line');
$ovEv = rh_hist_price_explanation($booking, ['source' => 'override', 'rate' => 300000, 'nights' => 3, 'total_with_vat' => 900000],
    450300.0, [], ['cur' => 'K', 'staff' => $staff, 'room_type_name' => 'Superior Suite', 'entity_names' => []]);
check(strpos($ovEv['detail'], 'Price set by hand: K900,000 for the whole stay (3 nights).') !== false && strpos($ovEv['detail'], "Today's price") === false, 'override: set by hand, no today comparison', $ovEv['detail']);
$rpB = $booking; $rpB['rate_plan_label'] = 'Long stay'; $rpB['rate_plan_discount'] = 35600;
$rpEv = rh_hist_price_explanation($rpB, null, 450300.0, [], ['cur' => 'K', 'staff' => $staff, 'room_type_name' => 'Superior Suite', 'entity_names' => []]);
check(strpos($rpEv['detail'], 'Rate plan applied: Long stay (discount K35,600).') !== false && strpos($rpEv['detail'], 'part of the difference may be the rate plan') !== false, 'old booking with a rate plan: says so, no false claim', $rpEv['detail']);

// ---- 6. the note written when a payment is saved ----
$note = rh_hist_payment_saved_note(1350900, 'cash', 1068000, -282900, true, 'CN-2026-000003', 282900, 'K');
check($note === 'Received K1,350,900 by cash. Owed before: K1,068,000. Owed after: K0. The overpay box was ticked. Credit note CN-2026-000003 for K282,900 issued for the extra.', 'payment saved note', $note);

// ---- 7. empty / missing data ----
check(rh_hist_build(['cur' => 'K']) === [], 'no booking and no rows -> empty list');
$noNights = $booking; $noNights['number_of_nights'] = 0;
check(rh_hist_price_explanation($noNights, null, 1.0, [], ['cur' => 'K', 'staff' => [], 'entity_names' => []]) === null, 'zero nights -> no price explanation (no division by zero)');
$bad = rh_hist_build(['cur' => 'K', 'payments' => [['id' => 1, 'payment_reference' => 'P1', 'payment_type' => 'payment', 'total_amount' => 5, 'created_at' => '0000-00-00 00:00:00']]]);
check($bad === [], 'a row with no real time is left out, not shown at 1970');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
