<?php
/**
 * Quotation -> invoice ("Convert to invoice", owner 2026-11-12).
 *
 * A sent or accepted quotation becomes ONE invoice for the record it priced (a room booking or a conference enquiry):
 * the quotation is accepted (same rules and side effects as "Mark accepted"), the record gets its invoice number from
 * the normal invoice sequence, and the two are linked both ways (quotations.converted_invoice_number / converted_at /
 * converted_by; the booking's History says "made from quotation ...").
 *
 * Numbering (the rail is "numbering has to be perfect"):
 *   - The quotation row and the account row are locked (SELECT ... FOR UPDATE) inside ONE transaction, the rules are
 *     re-checked under those locks, the number is taken with finance_next_invoice_number() INSIDE that transaction
 *     and the link is written before COMMIT. A double click or two staff therefore wait on the lock and the second
 *     one is refused ("already converted"); a failure rolls the sequence back, so no number is skipped.
 *   - An account that already holds an invoice number keeps it (the quotation links to it, no second number).
 *   - The PDF, the email and the logs come AFTER the commit (generators write files; the log helpers may create their
 *     tables, which would commit a transaction implicitly). They always reuse the linked number.
 *
 * Event quotations are price offers for an event LISTING (events.id), not for an event booking, and gym quotations
 * are never registered here, so only room and conference quotations convert (their booking_id is the account id).
 *
 * The first block is PURE (no database): scripts/tests/quotation_convert_test.php loads this file together with
 * admin/includes/booking-lifecycle.php and admin/includes/finance-account-sync.php (both function-only files).
 * The DB helpers below expect config/invoice.php, admin/includes/finance-account-sync.php and
 * admin/includes/booking-lifecycle.php to be loaded by the page (admin/quotations.php).
 */

/* ── PURE rules ────────────────────────────────────────────────────────────────────────────────────────────── */

if (!function_exists('rh_qc_tol')) {
    function rh_qc_tol(): float
    {
        return defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
    }
}

if (!function_exists('rh_qc_convertible_types')) {
    /** Quotation types whose booking_id is a billable account with an invoice generator. */
    function rh_qc_convertible_types(): array
    {
        return ['room', 'conference'];
    }
}

if (!function_exists('rh_qc_permission_for_type')) {
    /**
     * The permission that guards issuing an invoice for this type (ASSUMPTION logged in BUILD_PLAN.md):
     * room = 'invoices' (the Invoices permission, "View and manage invoices"); conference = 'conference_financials'
     * (the one conference-management.php checks before sending a conference invoice). '' = not convertible.
     */
    function rh_qc_permission_for_type(string $type): string
    {
        $map = ['room' => 'invoices', 'conference' => 'conference_financials'];
        return $map[$type] ?? '';
    }
}

if (!function_exists('rh_qc_permission_label')) {
    function rh_qc_permission_label(string $type): string
    {
        $map = ['room' => 'Invoices', 'conference' => 'Conference Invoicing & Pricing'];
        return $map[$type] ?? 'Invoices';
    }
}

if (!function_exists('rh_qc_number_series')) {
    /**
     * The invoice series the type's own generator uses, so a converted invoice shares the exact sequence:
     * room = generateInvoicePDF (invoice_prefix, channel 'room'); conference = generateConferenceInvoicePDF
     * ('CONF-' . invoice_prefix, channel 'conference'). $invoicePrefixSetting is passed exactly as the generators
     * read it (getSetting('invoice_prefix', 'INV')), untouched, so the sequence key is identical.
     *
     * @return array{prefix:string,channel:string}|null
     */
    function rh_qc_number_series(string $type, string $invoicePrefixSetting): ?array
    {
        if ($type === 'room') {
            return ['prefix' => $invoicePrefixSetting, 'channel' => 'room'];
        }
        if ($type === 'conference') {
            return ['prefix' => 'CONF-' . $invoicePrefixSetting, 'channel' => 'conference'];
        }
        return null;
    }
}

if (!function_exists('rh_qc_is_converted')) {
    function rh_qc_is_converted(array $q): bool
    {
        return trim((string)($q['converted_at'] ?? '')) !== '' || trim((string)($q['converted_invoice_number'] ?? '')) !== '';
    }
}

if (!function_exists('rh_qc_display_status')) {
    /**
     * What the register shows: 'converted' (an invoice was made from it; its stored status stays 'accepted'),
     * 'expired' (a sent quotation past its valid-until date), else the stored status. $today = 'Y-m-d'.
     */
    function rh_qc_display_status(array $q, string $today): string
    {
        if (rh_qc_is_converted($q)) {
            return 'converted';
        }
        $st = trim((string)($q['status'] ?? ''));
        if ($st === '') {
            $st = 'sent';
        }
        $until = substr(trim((string)($q['valid_until'] ?? '')), 0, 10);
        if ($st === 'sent' && $until !== '' && $until < $today) {
            return 'expired';
        }
        return $st;
    }
}

if (!function_exists('rh_qc_quote_block_reason')) {
    /**
     * Rules that depend on the quotation alone. Returns the plain reason it cannot be converted, or null.
     * The expiry date applies to SENT quotations, as "Mark accepted" does: an accepted quotation keeps its price.
     */
    function rh_qc_quote_block_reason(array $q, string $today, bool $columnsPresent): ?string
    {
        if (!$columnsPresent) {
            return 'Converting a quotation needs a small database update first (082_quotation_invoice_link). Ask the administrator to run the updates, then try again.';
        }
        $type = trim((string)($q['booking_type'] ?? ''));
        if ($type === '') {
            $type = 'room';
        }
        if (!in_array($type, rh_qc_convertible_types(), true)) {
            if ($type === 'event') {
                return 'An event quotation is a price offer for an event listing, not a booking. Record the event booking first, then invoice it from Event Bookings.';
            }
            return 'Invoice this ' . $type . ' account from its own page.';
        }
        if (rh_qc_is_converted($q)) {
            $num = trim((string)($q['converted_invoice_number'] ?? ''));
            return 'This quotation was already converted' . ($num !== '' ? ' to invoice ' . $num : '') . '.';
        }
        $display = rh_qc_display_status($q, $today);
        if ($display === 'declined') {
            return 'This quotation was declined, so it cannot become an invoice. Send a new quotation if the guest changed their mind.';
        }
        if ($display === 'expired') {
            $until = substr(trim((string)($q['valid_until'] ?? '')), 0, 10);
            return 'This quotation expired' . ($until !== '' ? ' on ' . $until : '') . '. Send a new quotation first.';
        }
        if (!in_array($display, ['sent', 'accepted'], true)) {
            return 'Only a sent or accepted quotation can be converted to an invoice.';
        }
        if ((int)($q['booking_id'] ?? 0) <= 0) {
            return 'This quotation is not linked to a booking.';
        }
        return null;
    }
}

if (!function_exists('rh_qc_eligibility')) {
    /**
     * Full rule set. $acct (null = the linked record no longer exists):
     *   status, paid (net paid, canonical rule), due (balance still owed), other_conversion (?array: quote_reference,
     *   invoice_number of ANOTHER quotation already converted for the same record).
     * $ctx: today ('Y-m-d'), columns_present, can_invoice, can_confirm (room: Edit Booking permission).
     *
     * @return array{ok:bool,reason:string,offer:string,doc_state:string,will_confirm:bool,confirm_note:string}
     *   offer: 'invoice' (allowed), 'receipt' (fully paid: send the receipt instead), 'none'.
     */
    function rh_qc_eligibility(array $q, ?array $acct, array $ctx): array
    {
        $out = ['ok' => false, 'reason' => '', 'offer' => 'none', 'doc_state' => 'none', 'will_confirm' => false, 'confirm_note' => ''];
        $type = trim((string)($q['booking_type'] ?? ''));
        if ($type === '') {
            $type = 'room';
        }
        $block = rh_qc_quote_block_reason($q, (string)($ctx['today'] ?? date('Y-m-d')), !empty($ctx['columns_present']));
        if ($block !== null) {
            $out['reason'] = $block;
            return $out;
        }
        if (empty($ctx['can_invoice'])) {
            $out['reason'] = 'Issuing this invoice needs the "' . rh_qc_permission_label($type) . '" permission. Ask a colleague who has it.';
            return $out;
        }
        if ($acct === null) {
            $out['reason'] = $type === 'room'
                ? 'The booking this quotation was for no longer exists, so there is nothing to invoice.'
                : 'The enquiry this quotation was for no longer exists, so there is nothing to invoice.';
            return $out;
        }
        if (array_key_exists('link_ok', $acct) && $acct['link_ok'] === false) {
            $out['reason'] = 'This quotation does not match the ' . ($type === 'room' ? 'booking' : 'enquiry') . ' it points to (different reference), so no invoice can be made from it.';
            return $out;
        }
        if (!empty($acct['other_conversion']) && is_array($acct['other_conversion'])) {
            $o = $acct['other_conversion'];
            $out['reason'] = 'This ' . ($type === 'room' ? 'booking' : 'enquiry') . ' was already invoiced from quotation '
                . (string)($o['quote_reference'] ?? '') . ' as invoice ' . (string)($o['invoice_number'] ?? '') . '. One invoice per booking.';
            return $out;
        }

        $status = (string)($acct['status'] ?? '');
        $postStatus = $status;
        if ($type === 'room') {
            if (function_exists('bookingAllowsAction')) {
                $lc = bookingAllowsAction(['status' => $status !== '' ? $status : 'pending'], 'mark_quotation');
                if (empty($lc['allowed'])) {
                    $out['reason'] = (string)$lc['reason'];
                    return $out;
                }
            }
            $canConfirm = !empty($ctx['can_confirm']);
            if (in_array($status, ['pending', 'tentative'], true)) {
                if ($canConfirm) {
                    $out['will_confirm'] = true;
                    $postStatus = 'confirmed';
                } elseif ($status === 'tentative') {
                    $out['reason'] = 'This booking is still tentative. Converting confirms it, and that needs the Edit Booking permission (an invoice cannot be issued for a tentative booking). Ask a colleague who has it.';
                    return $out;
                } else {
                    $out['confirm_note'] = 'The booking stays pending: confirming it needs the Edit Booking permission.';
                }
            }
            if (function_exists('bookingAllowsAction')) {
                $lc = bookingAllowsAction(['status' => $postStatus !== '' ? $postStatus : 'pending'], 'generate_invoice');
                if (empty($lc['allowed'])) {
                    $out['reason'] = (string)$lc['reason'];
                    return $out;
                }
            }
        } elseif (in_array($status, ['cancelled', 'declined', 'expired'], true)) {
            $out['reason'] = 'This enquiry is ' . $status . ', so it cannot be invoiced.';
            return $out;
        }

        $state = rh_doc_state((float)($acct['paid'] ?? 0), (float)($acct['due'] ?? 0), $postStatus);
        $out['doc_state'] = $state;
        if ($state === 'invoice') {
            $out['ok'] = true;
            $out['offer'] = 'invoice';
            return $out;
        }
        if ($state === 'receipt') {
            $out['offer'] = 'receipt';
            $out['reason'] = 'This ' . ($type === 'room' ? 'booking' : 'enquiry') . ' is fully paid, so it gets a receipt, not an invoice. Send the receipt from the ' . ($type === 'room' ? 'booking' : 'enquiry') . ' page.';
            return $out;
        }
        $out['reason'] = 'Nothing is owed on this ' . ($type === 'room' ? 'booking' : 'enquiry') . ', so there is nothing to invoice.';
        return $out;
    }
}

if (!function_exists('rh_qc_amount_choice')) {
    /**
     * The invoice is the record's own CURRENT total (rooms: the booked rate x nights as stored on the booking, never
     * today's room price). When it differs from the quoted total by more than BALANCE_TOLERANCE both are shown and the
     * screen says the invoice uses the current total.
     *
     * @return array{amount:float,quoted:float,current:float,changed:bool}
     */
    function rh_qc_amount_choice(float $quoted, float $current): array
    {
        $q = round($quoted, 2);
        $c = round($current, 2);
        return ['amount' => $c, 'quoted' => $q, 'current' => $c, 'changed' => abs($q - $c) > rh_qc_tol()];
    }
}

if (!function_exists('rh_qc_pick_existing_number')) {
    /** First non-blank invoice number among the candidates (in priority order), '' when none. */
    function rh_qc_pick_existing_number(array $candidates): string
    {
        foreach ($candidates as $c) {
            $c = trim((string)$c);
            if ($c !== '') {
                return $c;
            }
        }
        return '';
    }
}

if (!function_exists('rh_qc_final_number_action')) {
    /**
     * PURE. What to do with bookings.final_invoice_number once Convert settled on $number:
     * 'set' (empty: store it, so Generate Invoice reuses it), 'same' (already it), 'differs' (another number is there:
     * keep both as today, log it; never overwrite).
     */
    function rh_qc_final_number_action(string $final, string $number): string
    {
        $final = trim($final);
        if ($final === '') {
            return 'set';
        }
        return $final === trim($number) ? 'same' : 'differs';
    }
}

if (!function_exists('rh_qc_payment_can_hold_number')) {
    /** A payment row may carry an invoice number only if it is a real payment: never a refund, never a void/failed row. */
    function rh_qc_payment_can_hold_number(array $p): bool
    {
        return strtolower(trim((string)($p['payment_type'] ?? ''))) !== 'refund'
            && !in_array(strtolower(trim((string)($p['payment_status'] ?? ''))), ['voided', 'void', 'cancelled', 'failed'], true);
    }
}

if (!function_exists('rh_qc_number_plan')) {
    /**
     * PURE. One invoice number per record. $payments: the record's live payment rows (id, invoice_number, payment_type,
     * payment_status), any order. $finalNumber: bookings.final_invoice_number (rooms; '' for enquiries).
     *   1. reuse the number on any numbered non-refund payment row (newest real payment first, then a voided one);
     *   2. else reuse the booking's own final_invoice_number ("Generate Invoice" before any number reached a payment);
     *   3. else a NEW number, stored on the newest real payment row ('payment'), else on the booking ('booking',
     *      rooms) or only on the quotation ('none', enquiries). Never on a refund or void row.
     *
     * @return array{number:string,write_to:string,payment_id:int}
     */
    function rh_qc_number_plan(string $type, array $payments, string $finalNumber): array
    {
        usort($payments, static function ($a, $b) {
            return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
        });
        $numbered = array_values(array_filter($payments, static function ($p) {
            return strtolower(trim((string)($p['payment_type'] ?? ''))) !== 'refund' && trim((string)($p['invoice_number'] ?? '')) !== '';
        }));
        foreach ([true, false] as $realOnly) {
            foreach ($numbered as $p) {
                if (!$realOnly || rh_qc_payment_can_hold_number($p)) {
                    return ['number' => trim((string)$p['invoice_number']), 'write_to' => 'none', 'payment_id' => 0];
                }
            }
        }
        if (trim($finalNumber) !== '') {
            return ['number' => trim($finalNumber), 'write_to' => 'none', 'payment_id' => 0];
        }
        foreach ($payments as $p) {
            if (rh_qc_payment_can_hold_number($p)) {
                return ['number' => '', 'write_to' => 'payment', 'payment_id' => (int)($p['id'] ?? 0)];
            }
        }
        return ['number' => '', 'write_to' => $type === 'room' ? 'booking' : 'none', 'payment_id' => 0];
    }
}

if (!function_exists('rh_qc_money')) {
    /** "MWK 356,000" (whole) / "MWK 356,000.50" (cents shown only when there are some). */
    function rh_qc_money(float $v, string $cur): string
    {
        $v = round($v, 2);
        $dec = abs($v - round($v)) > 0.004 ? 2 : 0;
        return trim($cur . ' ' . number_format($v, $dec));
    }
}

if (!function_exists('rh_qc_send_outcome')) {
    /**
     * What a sender's result means: 'emailed', 'preview' (development mode saved a preview file and sent nothing:
     * createEmailPreview() returns success with a preview_url / "preview" message) or 'failed'.
     */
    function rh_qc_send_outcome(array $sent): string
    {
        if (empty($sent['success'])) {
            return 'failed';
        }
        if (!empty($sent['preview_url']) || stripos((string)($sent['message'] ?? ''), 'preview') !== false) {
            return 'preview';
        }
        return 'emailed';
    }
}

if (!function_exists('rh_qc_record_keys')) {
    /**
     * Which table a quotation's booking_id points to, and the record's own reference / email columns.
     * room = bookings.id; conference = conference_inquiries.id; gym = gym_inquiries.id; event = events.id (event
     * quotations are offers against an event LISTING, registered by sendEventQuotationEmail with the event title as
     * their reference and a quote reference "EQ-<events.id>-...").
     *
     * @return array{table:string,ref:string[],email:string[]}|null
     */
    function rh_qc_record_keys(string $type): ?array
    {
        $map = [
            'room'       => ['table' => 'bookings', 'ref' => ['booking_reference'], 'email' => ['guest_email']],
            'conference' => ['table' => 'conference_inquiries', 'ref' => ['inquiry_reference', 'enquiry_reference'], 'email' => ['email', 'contact_email']],
            'gym'        => ['table' => 'gym_inquiries', 'ref' => ['reference_number'], 'email' => ['email']],
            'event'      => ['table' => 'events', 'ref' => ['title'], 'email' => []],
        ];
        return $map[$type] ?? null;
    }
}

if (!function_exists('rh_qc_record_matches')) {
    /**
     * Does this record really belong to the quotation? A quotation only says "booking_id = N"; before a PDF is rebuilt
     * or an invoice issued from it, the record found must also carry the quotation's own reference (or, for legacy
     * rows without one, the same email), so an id from another table can never produce someone else's document.
     * Events: the event title, or the "EQ-<events.id>-" quote reference (the title may have been edited since).
     */
    function rh_qc_record_matches(string $type, array $q, array $record): bool
    {
        $keys = rh_qc_record_keys($type);
        if ($keys === null || !$record) {
            return false;
        }
        $norm = static function ($v): string {
            return strtolower(trim((string)$v));
        };
        $first = static function (array $row, array $cols) use ($norm): string {
            foreach ($cols as $c) {
                $v = $norm($row[$c] ?? '');
                if ($v !== '') {
                    return $v;
                }
            }
            return '';
        };
        $qRef = $norm($q['booking_reference'] ?? '');
        $rRef = $first($record, $keys['ref']);
        if ($qRef !== '' && $rRef !== '' && $qRef === $rRef) {
            return true;
        }
        if ($type === 'event' && (int)($record['id'] ?? 0) > 0
            && strpos(strtoupper(trim((string)($q['quote_reference'] ?? ''))), 'EQ-' . (int)$record['id'] . '-') === 0) {
            return true;
        }
        if ($qRef === '' && $keys['email']) {
            $qMail = $norm($q['guest_email'] ?? '');
            $rMail = $first($record, $keys['email']);
            return $qMail !== '' && $rMail !== '' && $qMail === $rMail;
        }
        return false;
    }
}

if (!function_exists('rh_qc_rebuild_block_reason')) {
    /**
     * PURE. Why a converted invoice's missing file may NOT be rebuilt (null = it may): the record is gone, it does not
     * carry the quotation's reference, or it can no longer be invoiced (rooms: the booking lifecycle rule, e.g.
     * "Invoices cannot be sent for a cancelled booking."; enquiries: cancelled). Wording matches the other refusals.
     */
    function rh_qc_rebuild_block_reason(string $type, array $q, ?array $record): ?string
    {
        $word = $type === 'room' ? 'booking' : 'enquiry';
        if ($record === null || !$record) {
            return 'The ' . $word . ' for this invoice no longer exists, so the invoice cannot be rebuilt.';
        }
        if (!rh_qc_record_matches($type, $q, $record)) {
            return 'This quotation does not match the ' . $word . ' it points to (different reference), so the invoice cannot be rebuilt.';
        }
        $status = (string)($record['status'] ?? '');
        if ($type === 'room' && function_exists('bookingAllowsAction')) {
            $lc = bookingAllowsAction(['status' => $status !== '' ? $status : 'pending'], 'generate_invoice');
            if (empty($lc['allowed'])) {
                return (string)$lc['reason'];
            }
        } elseif (in_array($status, ['cancelled', 'declined', 'expired'], true)) {
            return 'Invoices cannot be sent for a ' . $status . ' ' . $word . '.';
        }
        return null;
    }
}

if (!function_exists('rh_qc_event_attendees')) {
    /**
     * Attendees on an event quotation (not stored): quoted total / today's ticket price, only when that is a whole
     * number >= 1 within BALANCE_TOLERANCE of the total. null = the price changed since (a rebuilt PDF would differ).
     * A free event (price 0) quoted at 0 is 1 attendee.
     */
    function rh_qc_event_attendees(float $quotedTotal, float $ticketPrice): ?int
    {
        $tol = rh_qc_tol();
        if ($ticketPrice <= $tol) {
            return abs($quotedTotal) <= $tol ? 1 : null;
        }
        $n = (int)round($quotedTotal / $ticketPrice);
        if ($n < 1 || abs($n * $ticketPrice - $quotedTotal) > $tol) {
            return null;
        }
        return $n;
    }
}

if (!function_exists('rh_qc_confirm_booking_sql')) {
    /**
     * The one UPDATE that confirms a room booking when its quotation is accepted or converted. Same rule as the
     * Tentative page's convert (admin/tentative-bookings.php, booking-details.php): is_tentative = 0, the hold expiry
     * cleared (tentative_expires_at = NULL), and a tentative hold records
     * converted_from_tentative / converted_to_confirmed_at. The CASE columns come BEFORE status: MySQL applies SET
     * left to right, so they still see the old status. Only pending/tentative bookings change.
     */
    function rh_qc_confirm_booking_sql(): string
    {
        return "UPDATE bookings SET
                converted_from_tentative = CASE WHEN status = 'tentative' THEN 1 ELSE converted_from_tentative END,
                converted_to_confirmed_at = CASE WHEN status = 'tentative' THEN NOW() ELSE converted_to_confirmed_at END,
                is_tentative = 0,
                tentative_expires_at = NULL,
                status = 'confirmed',
                updated_at = NOW()
            WHERE id = ? AND status IN ('pending','tentative')";
    }
}

/* ── DB helpers ────────────────────────────────────────────────────────────────────────────────────────────── */

if (!function_exists('rh_qc_columns_present')) {
    /** True once migration 082 added the link columns (a SELECT, never DDL: safe inside a transaction). */
    function rh_qc_columns_present(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations'
                AND COLUMN_NAME IN ('converted_invoice_number', 'converted_invoice_path', 'converted_total', 'converted_at', 'converted_by')");
            $st->execute();
            $cache = (int)$st->fetchColumn() === 5;
        } catch (Throwable $e) {
            error_log('[quotation-convert] column probe failed: ' . $e->getMessage());
            $cache = false;
        }
        return $cache;
    }
}

if (!function_exists('rh_qc_linked_invoice_number')) {
    /**
     * The invoice number a converted quotation gave this record ('' when none, or before migration 082). Lets the
     * conference invoice generator re-send the SAME number when no payment row holds one yet.
     */
    function rh_qc_linked_invoice_number(PDO $pdo, string $type, int $bookingId): string
    {
        if ($bookingId <= 0 || !rh_qc_columns_present($pdo)) {
            return '';
        }
        try {
            $st = $pdo->prepare("SELECT converted_invoice_number FROM quotations WHERE booking_type = ? AND booking_id = ?
                AND converted_at IS NOT NULL AND converted_invoice_number IS NOT NULL AND converted_invoice_number <> ''
                ORDER BY converted_at ASC, id ASC LIMIT 1");
            $st->execute([$type, $bookingId]);
            return trim((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            error_log('[quotation-convert] linked number: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('rh_qc_account')) {
    /**
     * The linked record as one shape: exists, status, paid, due, current_total, ref, name, email, row.
     * $lock = true (inside the convert transaction): the row is locked FOR UPDATE and a room's paid/owed figures are
     * re-derived from the ledger (recalculateBookingFinancials via rh_account_balance_due). false = read-only snapshot.
     */
    function rh_qc_account(PDO $pdo, string $type, int $id, bool $lock): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $forUpdate = $lock ? ' FOR UPDATE' : '';
        if ($type === 'room') {
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?' . $forUpdate);
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            if ($lock && function_exists('rh_account_balance_due')) {
                rh_account_balance_due($pdo, 'room', $id);
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: $row;
            }
            $current = function_exists('rh_doc_stay_lines')
                ? (float)rh_doc_stay_lines($row)['total']
                : (float)(($row['total_with_vat'] ?? 0) ?: ((float)($row['total_amount'] ?? 0) + (float)($row['vat_amount'] ?? 0)));
            return [
                'exists' => true, 'status' => (string)($row['status'] ?? ''),
                'paid' => (float)($row['amount_paid'] ?? 0), 'due' => (float)($row['amount_due'] ?? 0),
                'current_total' => round($current, 2), 'ref' => (string)($row['booking_reference'] ?? ''),
                'name' => (string)($row['guest_name'] ?? ''), 'email' => (string)($row['guest_email'] ?? ''), 'row' => $row,
            ];
        }
        if ($type === 'conference') {
            $st = $pdo->prepare('SELECT * FROM conference_inquiries WHERE id = ?' . $forUpdate);
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $gross = rh_account_gross_total($row);
            $paid = rh_sum_account_paid($pdo, 'conference', $id);
            return [
                'exists' => true, 'status' => (string)($row['status'] ?? ''),
                'paid' => $paid, 'due' => rh_account_amount_due($row, $gross, $paid),
                'current_total' => round($gross, 2),
                'ref' => (string)($row['inquiry_reference'] ?? ($row['enquiry_reference'] ?? '')),
                'name' => (string)($row['contact_person'] ?? ($row['contact_name'] ?? ($row['company_name'] ?? ''))),
                'email' => (string)($row['email'] ?? ($row['contact_email'] ?? '')), 'row' => $row,
            ];
        }
        return null;
    }
}

if (!function_exists('rh_qc_other_conversion')) {
    /** Another quotation already converted for the same record (one invoice per record), or null. */
    function rh_qc_other_conversion(PDO $pdo, string $type, int $bookingId, int $excludeQuotationId): ?array
    {
        $st = $pdo->prepare("SELECT quote_reference, converted_invoice_number AS invoice_number FROM quotations
            WHERE booking_type = ? AND booking_id = ? AND id <> ? AND converted_at IS NOT NULL ORDER BY converted_at DESC LIMIT 1");
        $st->execute([$type, $bookingId, $excludeQuotationId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('rh_qc_existing_invoice_number')) {
    /**
     * Where the record's invoice number lives (rows: the account's LIVE payment rows, any order; see rh_qc_number_plan).
     * $lock (inside the convert transaction): the payment rows are locked too, so a concurrent send cannot number one.
     *
     * @return array{number:string,write_to:string,payment_id:int}
     */
    function rh_qc_existing_invoice_number(PDO $pdo, string $type, int $bookingId, array $row, bool $lock = false): array
    {
        $st = $pdo->prepare("SELECT id, COALESCE(invoice_number, '') AS invoice_number, COALESCE(payment_type, '') AS payment_type,
                COALESCE(payment_status, '') AS payment_status
            FROM payments WHERE booking_type = ? AND booking_id = ? AND deleted_at IS NULL ORDER BY id DESC" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$type, $bookingId]);
        $final = $type === 'room' ? (string)($row['final_invoice_number'] ?? '') : '';
        return rh_qc_number_plan($type, $st->fetchAll(PDO::FETCH_ASSOC), $final);
    }
}

if (!function_exists('rh_qc_unpaid_conversions')) {
    /**
     * Invoices made from quotations that no payment row carries yet (so the payment-based Invoices list cannot show
     * them) and that still have a balance owed (a deposit may already be in). Read-only and touches no total: the
     * Invoices page shows them in their own list below the main one. One query per type (room, conference), never one
     * per row. Filters: $type ('' = all), $scopeTypes (enabled modules, [] = all), $search (quotation ref, invoice
     * number, booking ref, customer name or email).
     *
     * @return array<int,array{quotation_id:int,quote_reference:string,invoice_number:string,type:string,booking_id:int,ref:string,name:string,email:string,converted_at:string,due:float,total:float}>
     */
    function rh_qc_unpaid_conversions(PDO $pdo, string $type = '', array $scopeTypes = [], string $search = ''): array
    {
        if (!rh_qc_columns_present($pdo)) {
            return [];
        }
        $types = rh_qc_convertible_types();
        if ($type !== '') {
            $types = in_array($type, $types, true) ? [$type] : [];
        } elseif ($scopeTypes) {
            $types = array_values(array_intersect($types, $scopeTypes));
        }
        $notOnPayment = "q.converted_at IS NOT NULL AND q.converted_invoice_number IS NOT NULL AND q.converted_invoice_number <> ''
              AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_type = q.booking_type AND p.booking_id = q.booking_id
                              AND p.deleted_at IS NULL AND p.invoice_number = q.converted_invoice_number)";
        $tol = rh_qc_tol();
        $out = [];
        if (in_array('room', $types, true)) {
            // Rooms: the booking's own paid/owed snapshot (kept by recalculateBookingFinancials on every money write).
            $st = $pdo->query("SELECT q.id AS qc_qid, q.quote_reference AS qc_qref, q.converted_invoice_number AS qc_num, q.booking_id AS qc_bid,
                    q.converted_at AS qc_at, b.booking_reference, b.guest_name, b.guest_email, b.amount_paid, b.amount_due
                FROM quotations q JOIN bookings b ON b.id = q.booking_id
                WHERE q.booking_type = 'room' AND $notOnPayment
                ORDER BY q.converted_at DESC LIMIT 500");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $due = round((float)$r['amount_due'], 2);
                if ($due <= $tol) {
                    continue; // settled or cancelled: nothing owed on this invoice
                }
                $out[] = ['quotation_id' => (int)$r['qc_qid'], 'quote_reference' => (string)$r['qc_qref'], 'invoice_number' => (string)$r['qc_num'],
                    'type' => 'room', 'booking_id' => (int)$r['qc_bid'], 'ref' => (string)$r['booking_reference'], 'name' => (string)$r['guest_name'],
                    'email' => (string)$r['guest_email'], 'converted_at' => (string)$r['qc_at'], 'due' => $due,
                    'total' => round((float)$r['amount_paid'] + $due, 2)];
            }
        }
        if (in_array('conference', $types, true)) {
            // Conference: net paid from the ledger with the canonical rule (rh_sum_account_paid), grouped in one pass.
            $st = $pdo->query("SELECT ci.*, q.id AS qc_qid, q.quote_reference AS qc_qref, q.converted_invoice_number AS qc_num, q.booking_id AS qc_bid,
                    q.converted_at AS qc_at, COALESCE(pp.paid, 0) AS qc_paid
                FROM quotations q
                JOIN conference_inquiries ci ON ci.id = q.booking_id
                LEFT JOIN (SELECT booking_id, SUM(CASE
                        WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount
                        WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN -total_amount
                        ELSE 0 END) AS paid
                    FROM payments WHERE booking_type = 'conference' AND deleted_at IS NULL GROUP BY booking_id) pp ON pp.booking_id = q.booking_id
                WHERE q.booking_type = 'conference' AND $notOnPayment
                ORDER BY q.converted_at DESC LIMIT 500");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $paid = max(0.0, (float)$r['qc_paid']);
                $due = rh_account_amount_due($r, rh_account_gross_total($r), $paid);
                if ($due <= $tol) {
                    continue;
                }
                $out[] = ['quotation_id' => (int)$r['qc_qid'], 'quote_reference' => (string)$r['qc_qref'], 'invoice_number' => (string)$r['qc_num'],
                    'type' => 'conference', 'booking_id' => (int)$r['qc_bid'],
                    'ref' => (string)($r['inquiry_reference'] ?? ($r['enquiry_reference'] ?? '')),
                    'name' => (string)($r['contact_person'] ?? ($r['contact_name'] ?? ($r['company_name'] ?? ''))),
                    'email' => (string)($r['email'] ?? ($r['contact_email'] ?? '')), 'converted_at' => (string)$r['qc_at'],
                    'due' => round($due, 2), 'total' => round($paid + $due, 2)];
            }
        }
        $needle = strtolower(trim($search));
        if ($needle !== '') {
            $out = array_values(array_filter($out, static function ($row) use ($needle) {
                return strpos(strtolower(implode(' ', [$row['quote_reference'], $row['invoice_number'], $row['ref'], $row['name'], $row['email']])), $needle) !== false;
            }));
        }
        usort($out, static function ($a, $b) {
            return strcmp($b['converted_at'], $a['converted_at']);
        });
        return $out;
    }
}

if (!function_exists('rh_qc_user_perms')) {
    /** @return array{can_invoice:bool,can_confirm:bool} */
    function rh_qc_user_perms(int $userId, string $type): array
    {
        $perm = rh_qc_permission_for_type($type);
        return [
            'can_invoice' => $perm !== '' && function_exists('hasPermission') && (bool)hasPermission($userId, $perm),
            'can_confirm' => $type === 'room' && function_exists('hasPermission') && (bool)hasPermission($userId, 'edit_booking'),
        ];
    }
}

if (!function_exists('rh_qc_preview')) {
    /** Read-only facts for the convert screen (no locks, no writes). */
    function rh_qc_preview(PDO $pdo, array $q, int $userId): array
    {
        $type = trim((string)($q['booking_type'] ?? '')) ?: 'room';
        $today = date('Y-m-d');
        $cols = rh_qc_columns_present($pdo);
        $acct = in_array($type, rh_qc_convertible_types(), true) ? rh_qc_account($pdo, $type, (int)($q['booking_id'] ?? 0), false) : null;
        if ($acct !== null) {
            $acct['link_ok'] = rh_qc_record_matches($type, $q, (array)$acct['row']);
            if ($cols) {
                $acct['other_conversion'] = rh_qc_other_conversion($pdo, $type, (int)$q['booking_id'], (int)$q['id']);
            }
        }
        $perms = rh_qc_user_perms($userId, $type);
        $el = rh_qc_eligibility($q, $acct, ['today' => $today, 'columns_present' => $cols] + $perms);
        $out = ['type' => $type, 'eligibility' => $el, 'account' => $acct, 'amount' => null, 'existing_number' => '', 'rate_text' => ''];
        if ($acct !== null) {
            $out['amount'] = rh_qc_amount_choice((float)($q['total_amount'] ?? 0), (float)$acct['current_total']);
            $out['existing_number'] = rh_qc_existing_invoice_number($pdo, $type, (int)$q['booking_id'], $acct['row'])['number'];
            if ($type === 'room' && function_exists('rh_booked_rate_for_booking')) {
                $line = rh_booked_rate_for_booking($pdo, (int)$q['booking_id'], false);
                $out['rate_text'] = !empty($line['ok']) ? (string)$line['text'] : '';
            }
        }
        return $out;
    }
}

if (!function_exists('rh_qc_convert')) {
    /**
     * The money step, in ONE transaction: lock the quotation, lock the record, re-check every rule, accept the
     * quotation (room: confirm the booking from pending/tentative with Edit Booking, as "Mark accepted" does), take or
     * reuse the invoice number and write the links. Nothing else (no files, no email, no log tables) runs inside.
     *
     * @return array ok, message, and on success: quotation_id, quote_reference, type, booking_id, invoice_number,
     *               reused, confirmed, old_status, confirm_note, amount, account (ref/name/email/row), write_to, payment_id
     */
    function rh_qc_convert(PDO $pdo, int $quotationId, int $userId): array
    {
        if ($quotationId <= 0) {
            return ['ok' => false, 'message' => 'Quotation not found.'];
        }
        if (!rh_qc_columns_present($pdo)) {
            return ['ok' => false, 'message' => rh_qc_quote_block_reason([], date('Y-m-d'), false)];
        }
        if (function_exists('finance_ensure_sequence_tables')) {
            finance_ensure_sequence_tables($pdo); // before the transaction: it may need DDL the first time
        }
        // Everything read through helpers that might touch their own tables is read BEFORE the transaction.
        $permsByType = [];
        foreach (rh_qc_convertible_types() as $t) {
            $permsByType[$t] = rh_qc_user_perms($userId, $t);
        }
        $invPrefixSetting = (string)getSetting('invoice_prefix', 'INV');
        $invStartSetting  = (int)getSetting('invoice_start_number', 1000);
        $today = date('Y-m-d');
        // Which record (a plain read: a quotation's type and booking_id never change).
        $pre = $pdo->prepare('SELECT booking_type, booking_id FROM quotations WHERE id = ?');
        $pre->execute([$quotationId]);
        $preRow = $pre->fetch(PDO::FETCH_ASSOC);
        if (!$preRow) {
            return ['ok' => false, 'message' => 'Quotation not found.'];
        }
        $type = trim((string)($preRow['booking_type'] ?? '')) ?: 'room';
        $bookingId = (int)$preRow['booking_id'];
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // Lock order as cancel and payments: the record first, then the quotation (no lock cycles).
            $acct = in_array($type, rh_qc_convertible_types(), true) ? rh_qc_account($pdo, $type, $bookingId, true) : null;
            $st = $pdo->prepare('SELECT * FROM quotations WHERE id = ? FOR UPDATE');
            $st->execute([$quotationId]);
            $q = $st->fetch(PDO::FETCH_ASSOC);
            if (!$q) {
                throw new RuntimeException('Quotation not found.');
            }
            if ((trim((string)($q['booking_type'] ?? '')) ?: 'room') !== $type || (int)$q['booking_id'] !== $bookingId) {
                throw new RuntimeException('This quotation changed a moment ago. Refresh the page and try again.');
            }
            $block = rh_qc_quote_block_reason($q, $today, true);
            if ($block !== null) {
                throw new RuntimeException($block);
            }
            if ($acct !== null) {
                $acct['link_ok'] = rh_qc_record_matches($type, $q, (array)$acct['row']);
                $acct['other_conversion'] = rh_qc_other_conversion($pdo, $type, $bookingId, $quotationId);
            }
            $perms = $permsByType[$type] ?? ['can_invoice' => false, 'can_confirm' => false];
            $el = rh_qc_eligibility($q, $acct, ['today' => $today, 'columns_present' => true] + $perms);
            if (!$el['ok']) {
                throw new RuntimeException($el['reason']);
            }
            $amount = rh_qc_amount_choice((float)($q['total_amount'] ?? 0), (float)$acct['current_total']);

            $ex = rh_qc_existing_invoice_number($pdo, $type, $bookingId, $acct['row'], true);
            $reused = $ex['number'] !== '';
            if ($reused) {
                $number = $ex['number'];
            } else {
                $series = rh_qc_number_series($type, $invPrefixSetting);
                if ($series === null) {
                    throw new RuntimeException('This quotation type has no invoice series.');
                }
                // Inside this transaction: the sequence row stays locked until COMMIT and rolls back with it.
                $number = finance_next_invoice_number($pdo, $series['prefix'], $invStartSetting, $today, $series['channel']);
            }

            $up = $pdo->prepare("UPDATE quotations SET status = 'accepted', converted_invoice_number = ?, converted_total = ?, converted_at = NOW(),
                    converted_by = ?, updated_at = NOW()
                WHERE id = ? AND converted_at IS NULL AND (converted_invoice_number IS NULL OR converted_invoice_number = '')");
            $up->execute([$number, $amount['amount'], $userId > 0 ? $userId : null, $quotationId]);
            if ($up->rowCount() !== 1) {
                throw new RuntimeException('This quotation was converted a moment ago. Refresh the page to see its invoice.');
            }

            $oldStatus = (string)$acct['status'];
            $confirmed = false;
            if ($type === 'room' && $el['will_confirm']) {
                // Same confirm as Mark accepted and the Tentative page (is_tentative cleared).
                $cs = $pdo->prepare(rh_qc_confirm_booking_sql());
                $cs->execute([$bookingId]);
                $confirmed = $cs->rowCount() === 1;
            }

            if (!$reused && $ex['write_to'] !== 'none') {
                if ($ex['write_to'] === 'payment' && $ex['payment_id'] > 0) {
                    // Never onto a refund or void row (rh_qc_payment_can_hold_number), and only where no number is yet.
                    $nw = $pdo->prepare("UPDATE payments SET invoice_number = ? WHERE id = ? AND deleted_at IS NULL
                        AND (invoice_number IS NULL OR invoice_number = '') AND COALESCE(payment_type, '') <> 'refund'
                        AND COALESCE(payment_status, '') NOT IN ('voided', 'void', 'cancelled', 'failed')");
                    $nw->execute([$number, $ex['payment_id']]);
                } else {
                    $nw = $pdo->prepare("UPDATE bookings SET final_invoice_number = ? WHERE id = ? AND (final_invoice_number IS NULL OR final_invoice_number = '')");
                    $nw->execute([$number, $bookingId]);
                }
                if ($nw->rowCount() !== 1) {
                    throw new RuntimeException('The invoice number could not be stored on the ' . ($type === 'room' ? 'booking' : 'enquiry') . ' (it changed a moment ago). Nothing was changed; please try again.');
                }
            }

            // Rooms: the booking's own final_invoice_number is what "Generate Invoice" (booking-details.php) reuses, so
            // it must hold this number too, else Generate Invoice would take a SECOND one. Set only when empty; a
            // different existing value is kept (as today) and logged after the commit. Conference enquiries have no
            // such column: their generator already reuses the payment row's or this quotation's number.
            $finalNote = '';
            if ($type === 'room') {
                $finalAction = rh_qc_final_number_action((string)($acct['row']['final_invoice_number'] ?? ''), $number);
                if ($finalAction === 'set') {
                    $pdo->prepare("UPDATE bookings SET final_invoice_number = ? WHERE id = ? AND (final_invoice_number IS NULL OR final_invoice_number = '')")
                        ->execute([$number, $bookingId]);
                } elseif ($finalAction === 'differs') {
                    $finalNote = trim((string)$acct['row']['final_invoice_number']);
                }
            }

            if ($ownTx) {
                $pdo->commit();
            }
            return [
                'ok' => true, 'message' => '', 'quotation_id' => $quotationId, 'quote_reference' => (string)$q['quote_reference'],
                'type' => $type, 'booking_id' => $bookingId, 'invoice_number' => $number, 'reused' => $reused,
                'confirmed' => $confirmed, 'old_status' => $oldStatus, 'confirm_note' => $el['confirm_note'],
                'amount' => $amount, 'account' => $acct, 'write_to' => $reused ? 'none' : $ex['write_to'], 'payment_id' => (int)$ex['payment_id'],
                'final_number_differs' => $finalNote,
            ];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException || !($e instanceof RuntimeException)) {
                error_log('[quotation-convert] ' . $e->getMessage());
                return ['ok' => false, 'message' => 'The quotation could not be converted. Nothing was changed. Please try again; if it keeps happening, check System Logs.'];
            }
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('rh_qc_invoice_cc')) {
    /** The CC list every invoice sender builds: the invoice_recipients setting plus the SMTP mailbox. */
    function rh_qc_invoice_cc(): array
    {
        $cc = array_values(array_filter(array_map('trim', explode(',', (string)getEmailSetting('invoice_recipients', '')))));
        $smtp = (string)getEmailSetting('smtp_username', '');
        if ($smtp !== '' && !in_array($smtp, $cc, true)) {
            $cc[] = $smtp;
        }
        return $cc;
    }
}

if (!function_exists('rh_qc_generate_pdf')) {
    /** (Re)build the linked invoice's file under its linked number. @return array|false generator result */
    function rh_qc_generate_pdf(string $type, int $bookingId, string $number)
    {
        if ($type === 'room') {
            return generateInvoicePDF($bookingId, $number);
        }
        if ($type === 'conference') {
            return generateConferenceInvoicePDF($bookingId, $number);
        }
        return false;
    }
}

if (!function_exists('rh_qc_after_convert')) {
    /**
     * After COMMIT: build the PDF under the linked number, email it when asked (existing senders, CC rules unchanged),
     * then write History (room: booking_audit_log; conference: the activity log) and a system log line.
     *
     * @return array{message:string,warning:string}
     */
    function rh_qc_after_convert(PDO $pdo, array $res, bool $sendEmail, array $user): array
    {
        $type = (string)$res['type'];
        $bid = (int)$res['booking_id'];
        $num = (string)$res['invoice_number'];
        $qref = (string)$res['quote_reference'];
        $cur = (string)getSetting('currency_symbol', 'MWK');
        $word = $type === 'room' ? 'booking' : 'enquiry';
        $warn = [];
        // The invoice is committed: nothing below may undo it. Each step logs its own failure and carries on.
        $logFail = static function (string $step, Throwable $e) use ($qref, $num, $type, $bid): void {
            error_log('[quotation-convert] ' . $step . ': ' . $e->getMessage());
            if (function_exists('rh_log_event')) {
                rh_log_event('quotations', 'warning', 'Quotation ' . $qref . ' -> invoice ' . $num . ': ' . $step . ' failed', [
                    'account_type' => $type, 'account_id' => $bid, 'error' => $e->getMessage(),
                ]);
            }
        };

        // 1. The invoice file under the linked number, and where it is kept.
        $file = '';
        $gen = false;
        try {
            $gen = rh_qc_generate_pdf($type, $bid, $num);
        } catch (Throwable $e) {
            $logFail('PDF', $e);
        }
        if ($gen && !empty($gen['relative_path'])) {
            $file = (string)$gen['filepath'];
            try {
                $pdo->prepare('UPDATE quotations SET converted_invoice_path = ? WHERE id = ?')->execute([(string)$gen['relative_path'], (int)$res['quotation_id']]);
                if ($res['write_to'] === 'payment' && (int)$res['payment_id'] > 0) {
                    $pdo->prepare('UPDATE payments SET invoice_path = ?, invoice_generated = 1 WHERE id = ? AND invoice_number = ?')
                        ->execute([(string)$gen['relative_path'], (int)$res['payment_id'], $num]);
                } elseif ($type === 'room' && $res['write_to'] === 'booking') {
                    // Nothing paid yet: the booking holds the number, so it holds the file too (as "Generate Invoice"
                    // does), which puts the invoice in the booking's Invoices panel.
                    $pdo->prepare('UPDATE bookings SET final_invoice_path = ? WHERE id = ? AND final_invoice_path IS NULL AND final_invoice_number = ?')
                        ->execute([(string)$gen['relative_path'], $bid, $num]);
                }
            } catch (Throwable $e) {
                $logFail('saving the file path', $e);
            }
        } else {
            $warn[] = 'Invoice ' . $num . ' is issued, but its PDF could not be made just now. Use "Download invoice" on the quotation to try again.';
        }

        // 2. History, written BEFORE any email, so a slow or failing send never loses the step.
        $to = trim((string)($res['account']['email'] ?? ''));
        $canEmail = $sendEmail && $file !== '' && $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL);
        if (!$sendEmail) {
            $emailLine = 'Kept as a draft to send later.';
        } elseif ($file === '') {
            $warn[] = 'The invoice was not emailed because its PDF could not be made.';
            $emailLine = 'Not emailed (the PDF could not be made).';
        } elseif (!$canEmail) {
            $warn[] = 'The invoice was not emailed: there is no valid email address on the ' . $word . '.';
            $emailLine = 'Not emailed (no valid email address).';
        } else {
            $emailLine = 'Sending it to ' . $to . ' (the result is in System Logs > Emails sent and on this History).';
        }

        $a = (array)$res['amount'];
        // Rooms: the quotation priced the STAY (booked rate x nights); the invoice also lists any extras on the bill.
        $totalWord = $type === 'room' ? 'Stay total' : 'Invoice total';
        $amountLine = $totalWord . ' ' . rh_qc_money((float)$a['amount'], $cur) . ($type === 'room' ? ' (booked rate × nights)' : '') . '.';
        $extras = $type === 'room' ? (float)($res['account']['row']['folio_charges_total'] ?? 0) : 0.0;
        if ($extras > rh_qc_tol()) {
            $amountLine .= ' Extras on the bill ' . rh_qc_money($extras, $cur) . ' are on the invoice too.';
        }
        if (!empty($a['changed'])) {
            $amountLine .= ' Quoted ' . rh_qc_money((float)$a['quoted'], $cur) . '; the ' . ($type === 'room' ? 'stay' : 'enquiry')
                . ' total is now ' . rh_qc_money((float)$a['current'], $cur) . ', so the invoice uses the current total.';
        }
        $numberLine = !empty($res['reused']) ? 'Uses invoice number ' . $num . ', already issued for this ' . $word . '.' : '';
        $note = 'Invoice ' . $num . ' made from quotation ' . $qref . '. ' . $amountLine . ($numberLine !== '' ? ' ' . $numberLine : '') . ' ' . $emailLine;

        try {
            if ($type === 'room' && function_exists('logBookingAudit')) {
                if (!empty($res['confirmed'])) {
                    logBookingAudit($bid, 'confirmed', ['status' => (string)$res['old_status']], ['status' => 'confirmed'], 'Confirmed when quotation ' . $qref . ' was converted to invoice ' . $num . '.');
                }
                logBookingAudit($bid, 'quotation_converted', null, null, $note);
            } elseif (function_exists('logActivity')) {
                logActivity((int)($user['id'] ?? 0), 'quotation_converted', 'Conference enquiry ' . (string)($res['account']['ref'] ?? ('#' . $bid)) . ': ' . $note);
            }
            if (function_exists('rh_log_event')) {
                rh_log_event('quotations', 'info', 'Quotation ' . $qref . ' converted to invoice ' . $num, [
                    'quotation_id' => (int)$res['quotation_id'], 'account_type' => $type, 'account_id' => $bid,
                    'account_ref' => (string)($res['account']['ref'] ?? ''), 'invoice_number' => $num, 'reused_number' => !empty($res['reused']),
                    'amount' => (float)$a['amount'], 'quoted' => (float)$a['quoted'], 'booking_confirmed' => !empty($res['confirmed']),
                    'email_requested' => $canEmail, 'actor' => (string)($user['full_name'] ?? ($user['username'] ?? '')),
                ]);
            }
            if ((string)($res['final_number_differs'] ?? '') !== '' && function_exists('rh_log_event')) {
                // Kept as today (never overwritten): the booking already held another invoice number.
                rh_log_event('quotations', 'warning', 'Booking ' . (string)($res['account']['ref'] ?? ('#' . $bid)) . ' holds invoice number '
                    . (string)$res['final_number_differs'] . ' on the booking and ' . $num . ' on its payments; quotation ' . $qref . ' was linked to ' . $num, [
                    'account_type' => $type, 'account_id' => $bid, 'booking_final_invoice_number' => (string)$res['final_number_differs'], 'invoice_number' => $num,
                ]);
            }
        } catch (Throwable $e) {
            $logFail('History', $e);
        }

        // 3. The email, last (existing senders, CC rules unchanged; each send is in the email log itself).
        if ($canEmail) {
            try {
                if ($type === 'room') {
                    $fresh = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
                    $fresh->execute([$bid]);
                    $acctRow = $fresh->fetch(PDO::FETCH_ASSOC) ?: (array)($res['account']['row'] ?? []);
                    $sent = sendInvoiceEmailToGuestWithCC($acctRow, $file, rh_qc_invoice_cc(), $num);
                } else {
                    $sent = sendConferenceInvoiceEmailToClient((array)($res['account']['row'] ?? []), $file, rh_qc_invoice_cc());
                }
            } catch (Throwable $e) {
                $sent = ['success' => false, 'message' => $e->getMessage()];
            }
            $outcome = rh_qc_send_outcome((array)$sent);
            if ($outcome === 'emailed') {
                $emailLine = 'Emailed to ' . $to . '.';
            } elseif ($outcome === 'preview') {
                $emailLine = 'Preview saved (not emailed): this copy runs in development mode.';
            } else {
                $warn[] = 'The invoice could not be emailed: ' . (string)($sent['message'] ?? 'unknown error') . '. Send it later from the ' . $word . '.';
                $emailLine = 'Email failed; to be sent later.';
                if (function_exists('rh_log_event')) {
                    rh_log_event('quotations', 'warning', 'Invoice ' . $num . ' (quotation ' . $qref . ') could not be emailed', [
                        'account_type' => $type, 'account_id' => $bid, 'error' => (string)($sent['message'] ?? ''),
                    ]);
                }
            }
        }

        $msg = 'Quotation ' . $qref . ' converted to invoice ' . $num . '. ' . $amountLine;
        if (!empty($res['confirmed'])) {
            $msg .= ' The booking is now confirmed.';
        } elseif ((string)($res['confirm_note'] ?? '') !== '') {
            $msg .= ' ' . $res['confirm_note'];
        }
        $msg .= ' ' . $emailLine;
        return ['message' => $msg, 'warning' => implode(' ', $warn)];
    }
}
