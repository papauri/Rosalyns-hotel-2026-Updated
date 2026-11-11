<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Quotation -> invoice rules (includes/quotation-convert.php). NO DATABASE: only the pure helpers are loaded, with the
 * two function-only files they use (booking lifecycle rules, rh_doc_state). Covers eligibility, the amount choice,
 * the document-state rule (owed = invoice, paid = receipt), the double-convert guard and the numbering order inside
 * the transaction (read from the source). Usage (repo root): php scripts/tests/quotation_convert_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

define('BALANCE_TOLERANCE', 0.01);
require_once $root . '/admin/includes/booking-lifecycle.php';
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/includes/quotation-convert.php';
check(!function_exists('getSetting') && !isset($GLOBALS['pdo']), 'the pure helpers load without the database or settings');

$today = '2026-11-12';
$q = static function (array $over = []): array {
    return $over + ['id' => 7, 'booking_type' => 'room', 'booking_id' => 42, 'quote_reference' => 'QT-RBH1', 'status' => 'sent',
        'valid_until' => '2026-11-19', 'total_amount' => 1068000, 'converted_at' => null, 'converted_invoice_number' => null];
};
$acct = static function (array $over = []): array {
    return $over + ['exists' => true, 'status' => 'confirmed', 'paid' => 0.0, 'due' => 1068000.0, 'other_conversion' => null];
};
$ctx = static function (array $over = []) use ($today): array {
    return $over + ['today' => $today, 'columns_present' => true, 'can_invoice' => true, 'can_confirm' => true];
};

// 1. display status
check(rh_qc_display_status($q(), $today) === 'sent', 'sent and in date shows Sent');
check(rh_qc_display_status($q(['valid_until' => '2026-11-11']), $today) === 'expired', 'sent past its date shows Expired');
check(rh_qc_display_status($q(['status' => 'accepted', 'valid_until' => '2026-11-01']), $today) === 'accepted', 'accepted keeps its price past the date');
check(rh_qc_display_status($q(['status' => 'accepted', 'converted_at' => '2026-11-12 10:00:00', 'converted_invoice_number' => 'INV-2026-000101']), $today) === 'converted', 'converted shows Converted (stored status stays accepted)');
check(rh_qc_display_status($q(['status' => '']), $today) === 'sent', 'blank status reads as sent');

// 2. quotation-only rules
check(strpos((string)rh_qc_quote_block_reason($q(), $today, false), '082_quotation_invoice_link') !== false, 'before migration 082: plain note naming the update');
check(rh_qc_quote_block_reason($q(), $today, true) === null, 'a sent, in-date room quotation may convert');
check(rh_qc_quote_block_reason($q(['status' => 'accepted', 'valid_until' => '2026-10-01']), $today, true) === null, 'an accepted quotation may convert after its date');
check(strpos((string)rh_qc_quote_block_reason($q(['valid_until' => '2026-11-11']), $today, true), 'expired on 2026-11-11') !== false, 'an expired quotation is refused');
check(strpos((string)rh_qc_quote_block_reason($q(['status' => 'expired']), $today, true), 'expired') !== false, 'a stored-expired quotation is refused');
check(strpos((string)rh_qc_quote_block_reason($q(['status' => 'declined']), $today, true), 'declined') !== false, 'a declined quotation is refused');
check(strpos((string)rh_qc_quote_block_reason($q(['status' => 'draft']), $today, true), 'Only a sent or accepted') !== false, 'a draft is refused');
check(strpos((string)rh_qc_quote_block_reason($q(['booking_type' => 'event']), $today, true), 'event listing') !== false, 'event quotations (listing offers) never convert');
check(strpos((string)rh_qc_quote_block_reason($q(['booking_type' => 'gym']), $today, true), 'its own page') !== false, 'gym quotations are invoiced from their own page');
check(rh_qc_quote_block_reason($q(['booking_type' => 'conference']), $today, true) === null, 'conference quotations convert');
check(rh_qc_quote_block_reason($q(['booking_type' => '']), $today, true) === null, 'untyped (legacy) rows are room quotations');
check(strpos((string)rh_qc_quote_block_reason($q(['booking_id' => 0]), $today, true), 'not linked') !== false, 'no linked record is refused');

// 3. double-convert guard
$conv = $q(['status' => 'accepted', 'converted_at' => '2026-11-12 10:00:00', 'converted_invoice_number' => 'INV-2026-000101']);
check(strpos((string)rh_qc_quote_block_reason($conv, $today, true), 'already converted to invoice INV-2026-000101') !== false, 'a converted quotation cannot convert twice');
check(rh_qc_is_converted(['converted_invoice_number' => 'INV-1']) && rh_qc_is_converted(['converted_at' => '2026-11-12']) && !rh_qc_is_converted(['converted_invoice_number' => '  ']), 'converted = a date or a number');
$e = rh_qc_eligibility($q(), $acct(['other_conversion' => ['quote_reference' => 'QT-OLD', 'invoice_number' => 'INV-2026-000090']]), $ctx());
check(!$e['ok'] && strpos($e['reason'], 'QT-OLD') !== false && strpos($e['reason'], 'INV-2026-000090') !== false, 'one invoice per booking: a second quotation for the same booking is refused');

// 4. eligibility: permission, record, lifecycle
$e = rh_qc_eligibility($q(), $acct(), $ctx(['can_invoice' => false]));
check(!$e['ok'] && strpos($e['reason'], '"Invoices" permission') !== false, 'room: needs the Invoices permission');
$e = rh_qc_eligibility($q(['booking_type' => 'conference']), $acct(), $ctx(['can_invoice' => false]));
check(!$e['ok'] && strpos($e['reason'], 'Conference Invoicing & Pricing') !== false, 'conference: needs Conference Invoicing & Pricing');
$e = rh_qc_eligibility($q(), null, $ctx());
check(!$e['ok'] && strpos($e['reason'], 'no longer exists') !== false, 'deleted booking: nothing to invoice');
$e = rh_qc_eligibility($q(), $acct(), $ctx());
check($e['ok'] && $e['offer'] === 'invoice' && $e['doc_state'] === 'invoice' && !$e['will_confirm'], 'confirmed booking with a balance owed: convert allowed, no confirm');
$e = rh_qc_eligibility($q(), $acct(['status' => 'tentative']), $ctx(['can_confirm' => false]));
check(!$e['ok'] && strpos($e['reason'], 'tentative') !== false && strpos($e['reason'], 'Edit Booking') !== false, 'tentative booking without Edit Booking: refused before any write');
$e = rh_qc_eligibility($q(), $acct(['status' => 'tentative']), $ctx());
check($e['ok'] && $e['will_confirm'], 'tentative booking with Edit Booking: converted and confirmed');
$e = rh_qc_eligibility($q(), $acct(['status' => 'pending']), $ctx(['can_confirm' => false]));
check($e['ok'] && !$e['will_confirm'] && strpos($e['confirm_note'], 'stays pending') !== false, 'pending booking without Edit Booking: invoice issued, booking stays pending (as Mark accepted)');
$e = rh_qc_eligibility($q(), $acct(['status' => 'pending']), $ctx());
check($e['ok'] && $e['will_confirm'], 'pending booking with Edit Booking: confirmed');
foreach (['cancelled', 'no-show', 'expired', 'checked-out'] as $st) {
    $e = rh_qc_eligibility($q(), $acct(['status' => $st]), $ctx());
    check(!$e['ok'], $st . ' booking: refused (Mark accepted rule)', $e['reason']);
}
$e = rh_qc_eligibility($q(['booking_type' => 'conference']), $acct(['status' => 'cancelled']), $ctx());
check(!$e['ok'] && strpos($e['reason'], 'cancelled') !== false, 'cancelled conference enquiry: refused');
$e = rh_qc_eligibility($q(['booking_type' => 'conference']), $acct(['status' => 'pending', 'paid' => 50000, 'due' => 25000]), $ctx(['can_confirm' => false]));
check($e['ok'] && !$e['will_confirm'], 'conference with a balance: allowed, never confirms the enquiry here');

// 5. document state: owed = invoice, paid = receipt, nothing = none
$e = rh_qc_eligibility($q(), $acct(['paid' => 1068000, 'due' => 0]), $ctx());
check(!$e['ok'] && $e['offer'] === 'receipt' && $e['doc_state'] === 'receipt' && strpos($e['reason'], 'receipt') !== false, 'fully paid: no invoice, the receipt is offered');
$e = rh_qc_eligibility($q(), $acct(['paid' => 1068000, 'due' => 0.004]), $ctx());
check(!$e['ok'] && $e['offer'] === 'receipt', 'a balance inside BALANCE_TOLERANCE counts as paid');
$e = rh_qc_eligibility($q(), $acct(['paid' => 1000000, 'due' => 68000]), $ctx());
check($e['ok'] && $e['offer'] === 'invoice', 'part paid with a balance owed: invoice');
$e = rh_qc_eligibility($q(), $acct(['paid' => 0, 'due' => 0]), $ctx());
check(!$e['ok'] && $e['offer'] === 'none' && strpos($e['reason'], 'Nothing is owed') !== false, 'nothing paid and nothing owed: nothing to invoice');

// 6. amount: the record's own current total; both shown when they differ
$a = rh_qc_amount_choice(1068000, 1068000);
check(!$a['changed'] && abs($a['amount'] - 1068000) < 0.001, 'same total: not flagged');
$a = rh_qc_amount_choice(1068000, 1068000.004);
check(!$a['changed'], 'a difference inside BALANCE_TOLERANCE is not a change');
$a = rh_qc_amount_choice(1068000, 1424000);
check($a['changed'] && abs($a['amount'] - 1424000) < 0.001 && abs($a['quoted'] - 1068000) < 0.001, 'changed total (extended stay): invoice uses the current total, quoted kept for display');
$a = rh_qc_amount_choice(1068000, 712000);
check($a['changed'] && abs($a['amount'] - 712000) < 0.001, 'lower current total: invoice uses the current total');

// 7. numbering series mirror the generators
check(rh_qc_number_series('room', 'INV') === ['prefix' => 'INV', 'channel' => 'room'], 'room series = generateInvoicePDF (invoice_prefix, room)');
check(rh_qc_number_series('conference', 'INV') === ['prefix' => 'CONF-INV', 'channel' => 'conference'], 'conference series = generateConferenceInvoicePDF (CONF-prefix, conference)');
check(rh_qc_number_series('room', '') === ['prefix' => '', 'channel' => 'room'], 'prefix passed untouched (sequence key identical to the generator)');
check(rh_qc_number_series('gym', 'INV') === null && rh_qc_number_series('event', 'INV') === null, 'no series for types that do not convert');
check(rh_qc_pick_existing_number(['', '  ', 'INV-2026-000088', 'INV-X']) === 'INV-2026-000088' && rh_qc_pick_existing_number([]) === '', 'an existing invoice number is reused before a new one is taken');

// 8. money text
check(rh_qc_money(1068000, 'MWK') === 'MWK 1,068,000' && rh_qc_money(1068000.5, 'MWK') === 'MWK 1,068,000.50', 'money: whole numbers plain, cents when present');

// 9. the transaction order in the source: lock, re-check, number, link, commit; files/email/logs only after
$src = (string)file_get_contents($root . '/includes/quotation-convert.php');
$fn = substr($src, (int)strpos($src, 'function rh_qc_convert('));
$fn = substr($fn, 0, (int)strpos($fn, "if (!function_exists('rh_qc_invoice_cc'))"));
$pos = static function (string $needle) use ($fn): int { $p = strpos($fn, $needle); return $p === false ? -1 : $p; };
$order = [
    $pos('finance_ensure_sequence_tables'), $pos('beginTransaction'), $pos('rh_qc_account($pdo, $type, $bookingId, true)'),
    $pos("FROM quotations WHERE id = ? FOR UPDATE"), $pos('rh_qc_eligibility('), $pos('finance_next_invoice_number('),
    $pos("UPDATE quotations SET status = 'accepted', converted_invoice_number"), $pos('->commit()'),
];
check(!in_array(-1, $order, true) && $order === array_values(array_unique($order)) && $order === (function ($o) { sort($o); return $o; })($order),
    'convert: sequence table ready, then BEGIN, lock record, lock quotation (cancel order), re-check, take number, write link, COMMIT', json_encode($order));
check(strpos($fn, 'converted_at IS NULL') !== false && strpos($fn, 'rowCount() !== 1') !== false, 'the link write only lands on an unconverted row (second click refused)');
check(strpos($fn, 'rollBack') !== false, 'a failure rolls back (the sequence number is not used up)');
foreach (['generateInvoicePDF', 'generateConferenceInvoicePDF', 'logBookingAudit', 'rh_log_event', 'sendInvoiceEmailToGuestWithCC', 'logActivity'] as $late) {
    check(strpos($fn, $late . '(') === false, $late . ' is not called inside the transaction');
}
$txBody = substr($fn, (int)strpos($fn, 'beginTransaction'), max(0, (int)strpos($fn, '->commit()') - (int)strpos($fn, 'beginTransaction')));
check($txBody !== '' && strpos($txBody, 'rh_qc_user_perms(') === false && strpos($txBody, 'getSetting(') === false,
    'permissions and settings are read before the transaction opens');
// 10. FAULT fix: a quotation's booking_id is only trusted when the record carries the quotation's own reference
$evQ = ['booking_type' => 'event', 'booking_id' => 5, 'quote_reference' => 'EQ-5-1A2B3C', 'booking_reference' => 'Jazz Night', 'guest_email' => 'a@x.mw'];
check(rh_qc_record_keys('event')['table'] === 'events', 'event quotations point at the events listing, not event_inquiries');
check(rh_qc_record_matches('event', $evQ, ['id' => 5, 'title' => 'Jazz Night']), 'event: matching listing title');
check(rh_qc_record_matches('event', $evQ, ['id' => 5, 'title' => 'Jazz Night (renamed)']), 'event: renamed listing still matches by its EQ-<id>- quote reference');
check(!rh_qc_record_matches('event', ['quote_reference' => 'EQ-9-ABC', 'booking_reference' => 'Jazz Night'], ['id' => 5, 'title' => 'Comedy Club']), 'event: a quotation made for another listing never matches this one');
check(!rh_qc_record_matches('event', $evQ, ['id' => 6, 'title' => 'Other show']), 'event: another listing never matches');
$rq = ['booking_type' => 'room', 'booking_reference' => 'RBH2026796618', 'guest_email' => 'g@x.mw'];
check(rh_qc_record_matches('room', $rq, ['booking_reference' => 'rbh2026796618 ', 'guest_email' => 'other@x.mw']), 'room: same booking reference (case/space-insensitive)');
check(!rh_qc_record_matches('room', $rq, ['booking_reference' => 'RBH2026000001', 'guest_email' => 'g@x.mw']), 'room: a different booking reference is refused even with the same email');
check(rh_qc_record_matches('room', ['booking_reference' => '', 'guest_email' => 'G@x.mw'], ['booking_reference' => 'RBH1', 'guest_email' => 'g@x.mw']), 'legacy row without a reference: matched by the same email');
check(!rh_qc_record_matches('room', ['booking_reference' => '', 'guest_email' => ''], ['booking_reference' => 'RBH1', 'guest_email' => 'g@x.mw']), 'nothing to compare: refused');
check(rh_qc_record_matches('conference', ['booking_reference' => 'CONF-77'], ['inquiry_reference' => 'CONF-77']), 'conference: matched by inquiry reference');
check(!rh_qc_record_matches('gym', ['booking_reference' => 'GYM-1'], ['reference_number' => 'GYM-2']), 'gym: different reference refused');
$e = rh_qc_eligibility($q(), $acct(['link_ok' => false]), $ctx());
check(!$e['ok'] && strpos($e['reason'], 'does not match') !== false, 'convert is refused when the booking does not carry the quotation reference');
check(rh_qc_event_attendees(150000, 50000) === 3 && rh_qc_event_attendees(150000, 40000) === null && rh_qc_event_attendees(0, 0) === 1 && rh_qc_event_attendees(100, 0) === null,
    'event attendees = quoted total / ticket price only when whole (a changed price is refused)');
$qp = (string)file_get_contents($root . '/admin/quotations.php');
$dl = substr($qp, (int)strpos($qp, 'Handle PDF download'), 6000);
check(strpos($dl, 'FROM event_inquiries') === false && strpos($dl, 'rh_qc_record_matches(') !== false && strpos($dl, 'rh_qc_record_keys(') !== false,
    'quotation download: no event_inquiries lookup; every type verifies the record before generating');

// 11. FAULT fix: confirming clears is_tentative (Mark accepted and Convert share one UPDATE)
$cs = rh_qc_confirm_booking_sql();
check(strpos($cs, 'is_tentative = 0') !== false && strpos($cs, 'tentative_expires_at = NULL') !== false
    && strpos($cs, "status IN ('pending','tentative')") !== false, 'confirm SQL clears the tentative flag and hold expiry, only from pending/tentative');
check(strpos($cs, 'converted_from_tentative') < strpos($cs, "status = 'confirmed'") && strpos($cs, 'converted_to_confirmed_at') < strpos($cs, "status = 'confirmed'"),
    'the tentative-conversion columns are set before status (MySQL SET runs left to right)');
$ma = substr($qp, (int)strpos($qp, "action === 'mark_accepted'"), 2500);
check(strpos($ma, 'rh_qc_confirm_booking_sql()') !== false && strpos($ma, "SET status = 'confirmed', updated_at") === false, 'Mark accepted uses the shared confirm (is_tentative cleared)');
check(strpos($fn, 'rh_qc_confirm_booking_sql()') !== false, 'Convert uses the shared confirm');

// 12. FAULT fix: a development-mode preview is never reported as emailed
check(rh_qc_send_outcome(['success' => true, 'message' => 'Email sent successfully']) === 'emailed', 'real send: emailed');
check(rh_qc_send_outcome(['success' => true, 'message' => 'Email preview created (development mode)', 'preview_url' => 'logs/x.html']) === 'preview', 'preview send: preview');
check(rh_qc_send_outcome(['success' => true, 'message' => 'Email preview created (development mode)']) === 'preview', 'preview message without url: preview');
check(rh_qc_send_outcome(['success' => false, 'message' => 'SMTP down']) === 'failed', 'failed send: failed');
check(strpos($src, "Preview saved (not emailed)") !== false, 'the convert message says "Preview saved (not emailed)"');

// 13. BLOCKER fix: one invoice number per booking (rh_qc_number_plan)
$pay = static function (int $id, string $num = '', string $type = 'payment', string $status = 'completed'): array {
    return ['id' => $id, 'invoice_number' => $num, 'payment_type' => $type, 'payment_status' => $status];
};
$p = rh_qc_number_plan('room', [$pay(11)], 'INV-2026-000050');
check($p['number'] === 'INV-2026-000050' && $p['write_to'] === 'none', 'Generate Invoice set a number, then a blank deposit: the booking number is reused, nothing written on the deposit');
$p = rh_qc_number_plan('room', [$pay(12), $pay(11, 'INV-2026-000040')], '');
check($p['number'] === 'INV-2026-000040' && $p['write_to'] === 'none', 'numbered older payment + newer blank one: the older number is reused');
$p = rh_qc_number_plan('room', [$pay(12, 'INV-2026-000041'), $pay(13, '', 'refund', 'completed')], 'INV-2026-000050');
check($p['number'] === 'INV-2026-000041', 'a numbered real payment wins over the booking number and over a newer refund');
$p = rh_qc_number_plan('room', [$pay(11), $pay(14, '', 'refund')], '');
check($p['number'] === '' && $p['write_to'] === 'payment' && $p['payment_id'] === 11, 'refund newest, nothing numbered: a new number goes on the real payment, never the refund');
$p = rh_qc_number_plan('room', [$pay(14, 'REF-X', 'refund')], '');
check($p['number'] === '' && $p['write_to'] === 'booking', "a refund row's reference is never taken as the invoice number; only a refund: the booking holds the new number");
$p = rh_qc_number_plan('room', [$pay(15, '', 'payment', 'voided'), $pay(16, '', 'payment', 'cancelled')], '');
check($p['write_to'] === 'booking', 'void/cancelled rows never receive a number');
$p = rh_qc_number_plan('room', [$pay(15, 'INV-2026-000030', 'payment', 'voided')], '');
check($p['number'] === 'INV-2026-000030', "a number already issued on a voided payment is still that booking's number (reused, never a second one)");
$p = rh_qc_number_plan('room', [], '');
check($p['number'] === '' && $p['write_to'] === 'booking', 'nothing numbered, nothing paid: a new number, kept on the booking');
$p = rh_qc_number_plan('conference', [], '');
check($p['number'] === '' && $p['write_to'] === 'none', 'conference, nothing paid: a new number, kept on the quotation');
$p = rh_qc_number_plan('conference', [$pay(20), $pay(21, 'CONF-INV-2026-000007')], '');
check($p['number'] === 'CONF-INV-2026-000007', 'conference: an existing numbered payment is reused');
check(!rh_qc_payment_can_hold_number($pay(1, '', 'refund')) && !rh_qc_payment_can_hold_number($pay(1, '', 'payment', 'voided')) && rh_qc_payment_can_hold_number($pay(1)),
    'only real payments may hold an invoice number');
check(strpos($fn, '$nw->rowCount() !== 1') !== false && strpos($fn, "COALESCE(payment_type, '') <> 'refund'") !== false,
    'the number write is checked (rolls back if it did not land) and can never land on a refund row');

// 14. Rebuilding a converted invoice's missing file
$rq2 = ['booking_reference' => 'RBH1'];
check(strpos((string)rh_qc_rebuild_block_reason('room', $rq2, null), 'no longer exists') !== false, 'rebuild refused: booking deleted');
check(strpos((string)rh_qc_rebuild_block_reason('room', $rq2, ['booking_reference' => 'RBH1', 'status' => 'cancelled']), 'cancelled booking') !== false, 'rebuild refused: cancelled booking (lifecycle wording)');
check(rh_qc_rebuild_block_reason('room', $rq2, ['booking_reference' => 'RBH1', 'status' => 'confirmed']) === null, 'rebuild allowed: confirmed booking');
check(strpos((string)rh_qc_rebuild_block_reason('room', $rq2, ['booking_reference' => 'RBH2', 'status' => 'confirmed']), 'does not match') !== false, 'rebuild refused: another booking');
check(strpos((string)rh_qc_rebuild_block_reason('conference', ['booking_reference' => 'C1'], ['inquiry_reference' => 'C1', 'status' => 'cancelled']), 'cancelled enquiry') !== false, 'rebuild refused: cancelled enquiry');
$ivh = substr($qp, (int)strpos($qp, 'Download the invoice a quotation was converted to'), 4000);
check(strpos($ivh, 'rh_qc_rebuild_block_reason(') !== false && strpos($ivh, 'rh_qc_rebuild_block_reason(') < strpos($ivh, 'rh_qc_generate_pdf('), '?invoice= checks the record before rebuilding');

// 15. After the commit: History is written before the email; path updates never undo the invoice
$ac = substr($src, (int)strpos($src, 'function rh_qc_after_convert('));
$ac = substr($ac, 0, (int)strpos($ac, '$msg = '));
$acHist = strpos($ac, "'quotation_converted', null, null, \$note");
check($acHist !== false && $acHist < strpos($ac, 'sendInvoiceEmailToGuestWithCC('), 'History is written before the invoice is emailed');
check(substr_count($ac, '$logFail(') >= 3 && strpos($ac, 'rollBack') === false, 'after-commit steps log their own failures and never roll back the invoice');

// 16. Generate Invoice (booking page) reuses bookings.final_invoice_number: Convert keeps it in step
check(rh_qc_final_number_action('', 'INV-2026-000041') === 'set', 'number reused from a payment row, booking number empty: the booking gets it too');
check(rh_qc_final_number_action('  ', 'INV-2026-000060') === 'set', 'new number on a deposit row, booking number blank: the booking gets it too');
check(rh_qc_final_number_action('INV-2026-000041', 'INV-2026-000041') === 'same', 'already the same: nothing to write');
check(rh_qc_final_number_action('INV-2026-000050', 'INV-2026-000041') === 'differs', 'a different booking number is never overwritten (kept and logged)');
check(strpos($fn, 'rh_qc_final_number_action(') !== false && strpos($fn, 'rh_qc_final_number_action(') < strpos($fn, '->commit()')
    && strpos($fn, "SET final_invoice_number = ? WHERE id = ? AND (final_invoice_number IS NULL OR final_invoice_number = '')") !== false,
    'the booking number is set inside the same transaction, only when empty');
check(strpos($ac, 'final_number_differs') !== false, 'a differing booking number is logged after the commit');

$acctFn = substr($src, (int)strpos($src, 'function rh_qc_account('), 1600);
check(strpos($acctFn, "' FOR UPDATE'") !== false, 'the linked record row is locked FOR UPDATE inside the convert');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
