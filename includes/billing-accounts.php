<?php
/**
 * Company pays later / on account (owner 2026-11-13; decisions D1-D6 in .claude/BUILD_PLAN.md, design in
 * .claude/COMPANY_BILLING_PLAN.md). Migration 084 adds the tables and columns; until it runs rh_billing_ready()
 * is false and every caller behaves exactly as before.
 *
 * ONE rule for every screen:
 *   - "On account" = the 083 pay-later switch (bookings.checkin_without_payment) ON plus a company
 *     (bookings.billing_account_id). A group member with no company of its own follows its primary booking.
 *   - The room stay is always the company's; extras (folio, room service, charge-to-room) are the company's only when
 *     the booking says billing_covers = 'everything' (D1, front desk chooses per booking). Default 'stay'.
 *   - The money columns never change meaning: amount_paid / amount_due / payment_status stay as
 *     recalculateBookingFinancials() writes them. rh_booking_bill_split() only says WHO owes the amount_due.
 *     Company money = the payment rows created by a company remittance (billing_remittance_lines.payment_id), read
 *     from the payment rows themselves (net of their refunds), never from the line amount.
 *     Guest money covers the guest's extras first; any excess lowers the company's share.
 *     company_due + guest_due = amount_due (within BALANCE_TOLERANCE).
 *   - Company documents (invoice, statement, payment advice, reminders) go to the company billing email (+ its CC).
 *     No billing email = nothing is sent, staff are told, the decision is logged. The guest is never chased for the
 *     company share.
 */

if (!function_exists('rh_billing_ready')) {

    /** True once migration 084 has run (three tables + the four booking columns). Read-only probe, cached. */
    function rh_billing_ready(?PDO $pdo = null): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
        if (!$pdo instanceof PDO) {
            return false;
        }
        try {
            $t = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('billing_accounts','billing_remittances','billing_remittance_lines')")->fetchColumn();
            $c = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'
                AND COLUMN_NAME IN ('billing_account_id','billing_reference','billing_due_date','billing_covers')")->fetchColumn();
            $cache = ($t === 3 && $c === 4);
        } catch (Throwable $e) {
            error_log('[billing] schema probe failed: ' . $e->getMessage());
            $cache = false;
        }
        return $cache;
    }

    function rh_billing_tol(): float
    {
        return defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
    }

    function rh_billing_currency(): string
    {
        return function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
    }

    function rh_billing_money(float $v): string
    {
        return rh_billing_currency() . ' ' . number_format($v, 2);
    }

    /** Valid, de-duplicated email addresses from a comma / semicolon / space separated list. */
    function rh_billing_email_list(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $e) {
            $e = trim($e);
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($e)] = $e;
            }
        }
        return array_values($out);
    }

    /** One company row (null when missing / not ready). */
    function rh_billing_account(PDO $pdo, int $id): ?array
    {
        if ($id <= 0 || !rh_billing_ready($pdo)) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM billing_accounts WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Every company, name order. $activeOnly: leave out deactivated ones. */
    function rh_billing_accounts(PDO $pdo, bool $activeOnly = false): array
    {
        if (!rh_billing_ready($pdo)) {
            return [];
        }
        return $pdo->query('SELECT * FROM billing_accounts' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** id => name for every company (one query; for list labels). */
    function rh_billing_account_names(PDO $pdo): array
    {
        static $names = null;
        if ($names !== null) {
            return $names;
        }
        $names = [];
        foreach (rh_billing_accounts($pdo) as $a) {
            $names[(int)$a['id']] = (string)$a['name'];
        }
        return $names;
    }

    /**
     * Who pays this booking. A group member without its own company follows its primary booking.
     * @return array{on_account:bool,account_id:int,account:?array,name:string,reference:string,due_date:string,
     *               covers:string,billing_email:string,billing_cc:array,terms_days:int,from_primary:bool}
     */
    function rh_booking_billing(PDO $pdo, array $b, ?array $accounts = null): array
    {
        $out = ['on_account' => false, 'account_id' => 0, 'account' => null, 'name' => '', 'reference' => '', 'due_date' => '',
            'covers' => 'stay', 'billing_email' => '', 'billing_cc' => [], 'terms_days' => 0, 'from_primary' => false];
        if (!rh_billing_ready($pdo)) {
            return $out;
        }
        $src = $b;
        if (!array_key_exists('billing_account_id', $src) && !empty($src['id'])) {
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
            $st->execute([(int)$src['id']]);
            $src = $st->fetch(PDO::FETCH_ASSOC) ?: $src;
        }
        if ((int)($src['billing_account_id'] ?? 0) <= 0 && (int)($src['primary_booking_id'] ?? 0) > 0) {
            if (array_key_exists('pb_billing_account_id', $src)) {
                // The primary's billing columns were joined into the row (list / batch queries): no extra query.
                $p = ['billing_account_id' => $src['pb_billing_account_id'], 'billing_reference' => $src['pb_billing_reference'] ?? null,
                    'billing_due_date' => $src['pb_billing_due_date'] ?? null, 'billing_covers' => $src['pb_billing_covers'] ?? null];
            } else {
                $st = $pdo->prepare('SELECT billing_account_id, billing_reference, billing_due_date, billing_covers, check_out_date FROM bookings WHERE id = ?');
                $st->execute([(int)$src['primary_booking_id']]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
            }
            if ($p && (int)$p['billing_account_id'] > 0) {
                $src = array_merge($src, ['billing_account_id' => $p['billing_account_id'], 'billing_reference' => $p['billing_reference'],
                    'billing_due_date' => $p['billing_due_date'], 'billing_covers' => $p['billing_covers']]);
                $out['from_primary'] = true;
            }
        }
        $accId = (int)($src['billing_account_id'] ?? 0);
        $acc = $accounts !== null ? ($accounts[$accId] ?? null) : rh_billing_account($pdo, $accId);
        if (!$acc) {
            return $out;
        }
        $terms = max(0, (int)$acc['terms_days']);
        $due = trim((string)($src['billing_due_date'] ?? ''));
        if ($due === '' && !empty($src['check_out_date'])) {
            $due = date('Y-m-d', strtotime(substr((string)$src['check_out_date'], 0, 10) . ' +' . $terms . ' days'));
        }
        return [
            'on_account' => true,
            'account_id' => (int)$acc['id'],
            'account' => $acc,
            'name' => (string)$acc['name'],
            'reference' => trim((string)($src['billing_reference'] ?? '')),
            'due_date' => $due,
            'covers' => ((string)($src['billing_covers'] ?? '') === 'everything') ? 'everything' : 'stay',
            'billing_email' => (string)(rh_billing_email_list((string)($acc['billing_email'] ?? ''))[0] ?? ''),
            'billing_cc' => rh_billing_email_list((string)($acc['billing_cc'] ?? '')),
            'terms_days' => $terms,
            'from_primary' => $out['from_primary'],
        ];
    }

    /** Net money the company paid on a booking: its remittance payment rows, less refunds of those rows. */
    function rh_billing_company_paid(PDO $pdo, int $bookingId): float
    {
        return rh_billing_company_paid_map($pdo, [$bookingId])[$bookingId] ?? 0.0;
    }

    /**
     * Net company money per booking for many bookings in one query: booking_id => paid legs (canonical paid statuses)
     * less refunds of those legs (completed / processing). A booking with no company money is left out (= 0).
     */
    function rh_billing_company_paid_map(PDO $pdo, array $bookingIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $bookingIds), static fn($i) => $i > 0)));
        if (!$ids || !rh_billing_ready($pdo)) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            // Two halves, one statement and one GROUP BY: the paid legs, and the refunds of those legs (kept apart so a
            // join never counts a leg twice).
            $st = $pdo->prepare("SELECT x.booking_id, SUM(x.paid) - SUM(x.refunded) AS net FROM (
                    SELECT l.booking_id, CASE WHEN p.payment_status IN ('completed','paid','refunded','partially_refunded')
                        AND COALESCE(p.payment_type,'') <> 'refund' THEN p.total_amount ELSE 0 END AS paid, 0 AS refunded
                    FROM billing_remittance_lines l JOIN payments p ON p.id = l.payment_id AND p.deleted_at IS NULL WHERE l.booking_id IN ($in)
                    UNION ALL
                    SELECT l.booking_id, 0 AS paid, r.total_amount AS refunded
                    FROM payments r JOIN billing_remittance_lines l ON l.payment_id = r.original_payment_id
                    WHERE l.booking_id IN ($in) AND r.payment_type = 'refund' AND r.refund_status IN ('completed','processing') AND r.deleted_at IS NULL
                ) x GROUP BY x.booking_id");
            $st->execute(array_merge($chunk, $chunk));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['booking_id']] = round(max(0.0, (float)$r['net']), 2);
            }
        }
        return $out;
    }

    /**
     * PURE. Who owes the booking's amount_due. $row: bookings row (status, total_with_vat / total_amount + vat_amount,
     * cancellation_retained_amount, folio_charges_total, amount_paid, amount_due); $covers 'stay' | 'everything'.
     * @return array{company_due:float,guest_due:float,company_paid:float,guest_paid:float,stay:float,extras:float}
     */
    function rh_billing_split_figures(array $row, bool $onAccount, string $covers, float $companyPaid): array
    {
        $tol = rh_billing_tol();
        $stay = (float)($row['total_with_vat'] ?? 0);
        if ($stay <= 0) {
            $stay = (float)($row['total_amount'] ?? 0) + (float)($row['vat_amount'] ?? 0);
        }
        if (($row['status'] ?? '') === 'cancelled') {
            $stay = max(0.0, (float)($row['cancellation_retained_amount'] ?? 0));
        }
        $extras = max(0.0, (float)($row['folio_charges_total'] ?? 0));
        $paid = max(0.0, (float)($row['amount_paid'] ?? 0));
        $due = max(0.0, (float)($row['amount_due'] ?? 0));
        $companyPaid = min($paid, max(0.0, $companyPaid));
        $guestPaid = round($paid - $companyPaid, 2);
        $out = ['company_due' => 0.0, 'guest_due' => round($due, 2), 'company_paid' => round($companyPaid, 2), 'guest_paid' => $guestPaid,
            'stay' => round($stay, 2), 'extras' => round($extras, 2)];
        if (!$onAccount) {
            return $out;
        }
        if ($covers === 'everything') {
            $out['company_due'] = round($due, 2);
            $out['guest_due'] = 0.0;
            return $out;
        }
        $guestExtraCover = min($guestPaid, $extras);
        $guestStayCover = $guestPaid - $guestExtraCover;
        $company = max(0.0, $stay - $companyPaid - $guestStayCover);
        $guest = max(0.0, $extras - $guestExtraCover);
        // Keep the invariant against the stored amount_due (rounding / older rows): the company share never exceeds it.
        $company = min($company, $due);
        $guest = max(0.0, $due - $company);
        $out['company_due'] = $company <= $tol ? 0.0 : round($company, 2);
        $out['guest_due'] = $guest <= $tol ? 0.0 : round($guest, 2);
        return $out;
    }

    /**
     * Who owes a booking's balance. Reads the booking row (or uses $row), the company link and the company's payments.
     * @return array split figures + on_account, name, reference, due_date, covers, account_id, amount_due, days_past_due
     */
    function rh_booking_bill_split(PDO $pdo, int $bookingId, ?array $row = null): array
    {
        if ($row === null || !array_key_exists('amount_due', $row)) {
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
            $st->execute([$bookingId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $bill = rh_booking_billing($pdo, $row + ['id' => $bookingId]);
        return rh_billing_split_result($row, $bill, $bill['on_account'] ? rh_billing_company_paid($pdo, $bookingId) : 0.0);
    }

    /** PURE. The array rh_booking_bill_split() returns, from the row, its billing and the company's net money. */
    function rh_billing_split_result(array $row, array $bill, float $companyPaid): array
    {
        $fig = rh_billing_split_figures($row, $bill['on_account'], $bill['covers'], $bill['on_account'] ? $companyPaid : 0.0);
        $daysPast = 0;
        if ($bill['on_account'] && $bill['due_date'] !== '') {
            $daysPast = max(0, (int)floor((strtotime(date('Y-m-d')) - strtotime($bill['due_date'])) / 86400));
        }
        return $fig + [
            'on_account' => $bill['on_account'], 'account_id' => $bill['account_id'], 'name' => $bill['name'], 'reference' => $bill['reference'],
            'due_date' => $bill['due_date'], 'covers' => $bill['covers'], 'billing_email' => $bill['billing_email'], 'billing_cc' => $bill['billing_cc'],
            'amount_due' => round(max(0.0, (float)($row['amount_due'] ?? 0)), 2), 'days_past_due' => $daysPast,
        ];
    }

    /**
     * Splits for many bookings at once (lists, receivables, the company page): id => the same array as
     * rh_booking_bill_split(). $rows: bookings rows (SELECT b.*; optionally with pb_billing_* joined from the primary).
     * Queries: one for the companies, at most one for group primaries not joined in, one for the company money.
     */
    function rh_billing_splits_for_rows(PDO $pdo, array $rows): array
    {
        $out = [];
        if (!$rows) {
            return $out;
        }
        $accounts = [];
        if (rh_billing_ready($pdo)) {
            foreach (rh_billing_accounts($pdo) as $a) {
                $accounts[(int)$a['id']] = $a;
            }
            $need = [];
            foreach ($rows as $r) {
                if ((int)($r['billing_account_id'] ?? 0) <= 0 && (int)($r['primary_booking_id'] ?? 0) > 0 && !array_key_exists('pb_billing_account_id', $r)) {
                    $need[(int)$r['primary_booking_id']] = true;
                }
            }
            $prim = [];
            foreach (array_chunk(array_keys($need), 500) as $chunk) {
                $st = $pdo->prepare('SELECT id, billing_account_id, billing_reference, billing_due_date, billing_covers FROM bookings WHERE id IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ')');
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $prim[(int)$p['id']] = $p;
                }
            }
            foreach ($rows as $i => $r) {
                $pid = (int)($r['primary_booking_id'] ?? 0);
                if ((int)($r['billing_account_id'] ?? 0) <= 0 && $pid > 0 && !array_key_exists('pb_billing_account_id', $r)) {
                    $p = $prim[$pid] ?? [];
                    $rows[$i] += ['pb_billing_account_id' => $p['billing_account_id'] ?? null, 'pb_billing_reference' => $p['billing_reference'] ?? null,
                        'pb_billing_due_date' => $p['billing_due_date'] ?? null, 'pb_billing_covers' => $p['billing_covers'] ?? null];
                }
            }
        }
        $bills = [];
        $onIds = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $bills[$id] = rh_booking_billing($pdo, $r, $accounts);
            if ($bills[$id]['on_account']) {
                $onIds[] = $id;
            }
        }
        $paid = rh_billing_company_paid_map($pdo, $onIds);
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $out[$id] = rh_billing_split_result($r, $bills[$id], $paid[$id] ?? 0.0);
        }
        return $out;
    }

    /**
     * The split summed over a booking's group (primary + members; a single booking = itself), as the room invoice
     * combines them: on_account, company_due, guest_due, company_paid, guest_paid, stay, extras, guest_extra_cover
     * (guest money that paid extras), ids, leg_ids (the company's payment rows).
     */
    function rh_billing_group_split(PDO $pdo, int $bookingId): array
    {
        $out = ['on_account' => false, 'company_due' => 0.0, 'guest_due' => 0.0, 'company_paid' => 0.0, 'guest_paid' => 0.0, 'stay' => 0.0,
            'extras' => 0.0, 'guest_extra_cover' => 0.0, 'ids' => [], 'leg_ids' => []];
        $st = $pdo->prepare('SELECT COALESCE(NULLIF(primary_booking_id, 0), id) FROM bookings WHERE id = ?');
        $st->execute([$bookingId]);
        $root = (int)$st->fetchColumn();
        if ($root <= 0) {
            return $out;
        }
        $st = $pdo->prepare('SELECT id FROM bookings WHERE id = ? OR primary_booking_id = ? ORDER BY id');
        $st->execute([$root, $root]);
        return rh_billing_split_for_ids($pdo, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * The split summed over EXACTLY these bookings (the rooms one document lists - never more), same keys as
     * rh_billing_group_split().
     */
    function rh_billing_split_for_ids(PDO $pdo, array $ids): array
    {
        $out = ['on_account' => false, 'company_due' => 0.0, 'guest_due' => 0.0, 'company_paid' => 0.0, 'guest_paid' => 0.0, 'stay' => 0.0,
            'extras' => 0.0, 'guest_extra_cover' => 0.0, 'ids' => [], 'leg_ids' => []];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) {
            return $out;
        }
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach (rh_billing_splits_for_rows($pdo, $rows) as $id => $sp) {
            $out['ids'][] = (int)$id;
            if (empty($sp['on_account'])) {
                continue;
            }
            $out['on_account'] = true;
            foreach (['company_due', 'guest_due', 'company_paid', 'guest_paid', 'stay', 'extras'] as $k) {
                $out[$k] = round($out[$k] + (float)$sp[$k], 2);
            }
            $out['guest_extra_cover'] = round($out['guest_extra_cover'] + min((float)$sp['guest_paid'], (float)$sp['extras']), 2);
        }
        if ($out['ids'] && rh_billing_ready($pdo)) {
            $st = $pdo->prepare('SELECT payment_id FROM billing_remittance_lines WHERE booking_id IN (' . implode(',', array_fill(0, count($out['ids']), '?')) . ')');
            $st->execute($out['ids']);
            $out['leg_ids'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        return $out;
    }

    /**
     * PURE. Who is owed the money going back on an on-account booking. $companyLeft / $guestLeft: what is still
     * refundable on the company's payments / everybody else's; $billAfter: the bill once this refund is done
     * (paid - refund); $extras: folio extras; $covers 'stay' | 'everything'. The company is responsible for the stay
     * (and the extras when it covers everything); the guest for the extras it pays. Guest money above its extras first
     * fills any gap in the company's share; only the rest is the guest's own overpayment.
     * @return array{company:float,guest:float} the overpaid share of each
     */
    function rh_billing_refund_overpaid(float $companyLeft, float $guestLeft, float $billAfter, float $extras, string $covers): array
    {
        $billAfter = max(0.0, $billAfter);
        $guestShare = $covers === 'everything' ? 0.0 : min(max(0.0, $extras), $billAfter);
        $companyShare = $billAfter - $guestShare;
        return [
            'company' => round(max(0.0, $companyLeft - $companyShare), 2),
            'guest' => round(max(0.0, $guestLeft - $guestShare - max(0.0, $companyShare - $companyLeft)), 2),
        ];
    }

    /**
     * Refund order for an on-account booking (called by allocateBookingRefunds): the company's payments first, up to
     * the company's overpaid share; then the guest's own payments, up to the guest's overpaid share; anything left
     * (should not happen) newest payment first, logged. null = not on account / no company money: the caller keeps
     * its newest-first order. $rows: the caller's candidate payments (newest first, with already_refunded).
     * Each entry: ['payment' => row, 'amount' => float, 'payer' => 'company'|'guest'].
     */
    function rh_billing_refund_plan(PDO $pdo, int $bookingId, array $rows, float $amount): ?array
    {
        $tol = rh_billing_tol();
        if (!rh_billing_ready($pdo) || $amount <= $tol) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $st->execute([$bookingId]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            return null;
        }
        $bill = rh_booking_billing($pdo, $b);
        if (!$bill['on_account']) {
            return null;
        }
        $st = $pdo->prepare('SELECT payment_id FROM billing_remittance_lines WHERE booking_id = ?');
        $st->execute([$bookingId]);
        $legs = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        if (!$legs) {
            return null;
        }
        // Everything still refundable on the booking (all methods), split by payer.
        $st = $pdo->prepare("SELECT p.id, p.total_amount - COALESCE((SELECT SUM(COALESCE(r.refund_amount, r.total_amount)) FROM payments r
                    WHERE r.original_payment_id = p.id AND r.payment_type = 'refund' AND r.refund_status IN ('completed','processing','pending') AND r.deleted_at IS NULL), 0) AS lft
            FROM payments p WHERE p.booking_type = 'room' AND p.booking_id = ? AND COALESCE(p.payment_type, '') <> 'refund'
              AND p.payment_status IN ('completed','paid','refunded','partially_refunded') AND p.deleted_at IS NULL");
        $st->execute([$bookingId]);
        $cLeft = 0.0;
        $gLeft = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $l = max(0.0, round((float)$p['lft'], 2));
            if (isset($legs[(int)$p['id']])) {
                $cLeft += $l;
            } else {
                $gLeft += $l;
            }
        }
        $over = rh_billing_refund_overpaid($cLeft, $gLeft, $cLeft + $gLeft - $amount, (float)($b['folio_charges_total'] ?? 0), $bill['covers']);
        $left = round($amount, 2);
        $cap = ['company' => min($left, $over['company']), 'guest' => $over['guest']];
        $take = [];
        $room = [];
        foreach ($rows as $p) {
            $room[(int)$p['id']] = max(0.0, round((float)$p['total_amount'] - (float)$p['already_refunded'], 2));
        }
        foreach (['company', 'guest', 'rest'] as $pass) {
            foreach ($rows as $p) {
                $pid = (int)$p['id'];
                $payer = isset($legs[$pid]) ? 'company' : 'guest';
                if ($left <= $tol || ($pass !== 'rest' && $payer !== $pass) || $room[$pid] <= $tol) {
                    continue;
                }
                $n = $pass === 'rest' ? min($left, $room[$pid]) : min($left, $room[$pid], $cap[$pass]);
                $n = round($n, 2);
                if ($n <= $tol) {
                    continue;
                }
                if ($pass !== 'rest') {
                    $cap[$pass] = round($cap[$pass] - $n, 2);
                } elseif (function_exists('rh_log_event')) {
                    rh_log_event('payments', 'warning', 'Refund on a company account booking went beyond the payer shares', [
                        'booking_id' => $bookingId, 'payment_id' => $pid, 'amount' => $n, 'payer' => $payer]);
                }
                $room[$pid] = round($room[$pid] - $n, 2);
                $left = round($left - $n, 2);
                if (isset($take[$pid])) {
                    $take[$pid]['amount'] = round($take[$pid]['amount'] + $n, 2);
                } else {
                    $take[$pid] = ['payment' => $p, 'amount' => $n, 'payer' => $payer];
                }
            }
        }
        return array_values($take);
    }

    /**
     * Who is told about a refund of $payment (the refund row or the original payment): when the money refunded is a
     * company payment, the company (billing email, test-mode routed); null = not a company payment (the guest is told).
     * @return array{name:string,to:string,note:string,billing_email:string}|null to '' = do not send (note says why); billing_email = the real address
     */
    function rh_billing_refund_recipient(PDO $pdo, array $payment): ?array
    {
        if (!rh_billing_ready($pdo) || (string)($payment['booking_type'] ?? 'room') !== 'room') {
            return null;
        }
        $legId = (string)($payment['payment_type'] ?? '') === 'refund' ? (int)($payment['original_payment_id'] ?? 0) : (int)($payment['id'] ?? 0);
        if (!rh_billing_payment_is_company_leg($pdo, $legId)) {
            return null;
        }
        $st = $pdo->prepare('SELECT a.* FROM billing_remittance_lines l JOIN billing_remittances r ON r.id = l.remittance_id JOIN billing_accounts a ON a.id = r.billing_account_id WHERE l.payment_id = ?');
        $st->execute([$legId]);
        $acc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$acc) {
            return null;
        }
        $to = (string)(rh_billing_email_list((string)($acc['billing_email'] ?? ''))[0] ?? '');
        if ($to === '') {
            return ['name' => (string)$acc['name'], 'to' => '', 'note' => (string)$acc['name'] . ' has no billing email', 'billing_email' => ''];
        }
        $route = rh_billing_mail_route($to, [], '', rh_billing_test_recipient());
        return ['name' => (string)$acc['name'], 'to' => $route['send'] ? $route['to'] : '', 'note' => $route['send'] ? '' : $route['note'], 'billing_email' => $to];
    }

    /**
     * Company money received from $from (Y-m-d) on: company payments received in the period, less refunds of
     * their payment rows dated in the period (completed / processing). $accountId null = every company.
     */
    function rh_billing_received_net(PDO $pdo, string $from, ?int $accountId = null): float
    {
        if (!rh_billing_ready($pdo)) {
            return 0.0;
        }
        $accSql = $accountId !== null ? ' AND r.billing_account_id = ?' : '';
        $args = $accountId !== null ? [$from, $accountId] : [$from];
        $st = $pdo->prepare('SELECT COALESCE(SUM(r.amount), 0) FROM billing_remittances r WHERE r.received_date >= ?' . $accSql);
        $st->execute($args);
        $in = (float)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(SUM(f.total_amount), 0) FROM payments f
            JOIN billing_remittance_lines l ON l.payment_id = f.original_payment_id JOIN billing_remittances r ON r.id = l.remittance_id
            WHERE f.payment_type = 'refund' AND f.refund_status IN ('completed','processing') AND f.deleted_at IS NULL AND f.payment_date >= ?" . $accSql);
        $st->execute($args);
        return round($in - (float)$st->fetchColumn(), 2);
    }

    /** Short status words for lists: '' when the booking is not on account (callers keep their normal label). */
    function rh_booking_pay_label(array $split): string
    {
        if (empty($split['on_account'])) {
            return '';
        }
        $tol = rh_billing_tol();
        $who = 'On account - ' . $split['name'] . ($split['reference'] !== '' ? ' (' . $split['reference'] . ')' : '');
        if ($split['company_due'] > $tol && $split['guest_due'] > $tol) {
            return $who . ' · guest owes ' . rh_billing_money((float)$split['guest_due']);
        }
        if ($split['company_due'] > $tol) {
            return $who;
        }
        if ($split['guest_due'] > $tol) {
            return 'Company paid · guest owes ' . rh_billing_money((float)$split['guest_due']);
        }
        return '';
    }

    /** SQL (leading AND) that leaves out bookings on a company account (by its own link). '' before 084. */
    function rh_billing_exclude_sql(string $alias = 'b', ?bool $ready = null): string
    {
        if (!($ready ?? rh_billing_ready())) {
            return '';
        }
        // The booking's own company, or (group member) its primary booking's company.
        $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
        $outer = $a !== '' ? $a : 'bookings';
        return ' AND ' . ($a !== '' ? $a . '.' : '') . 'billing_account_id IS NULL'
            . ' AND NOT EXISTS (SELECT 1 FROM bookings bill_pb WHERE bill_pb.id = ' . $outer . '.primary_booking_id AND bill_pb.billing_account_id IS NOT NULL)';
    }

    /**
     * Bookings a company still owes money on (company share above the tolerance), oldest due first.
     * A group member with no company of its own counts when its primary is on this account.
     */
    function rh_billing_open_bookings(PDO $pdo, int $accountId, bool $includeSettled = false): array
    {
        if (!rh_billing_ready($pdo) || $accountId <= 0) {
            return [];
        }
        if (!function_exists('rh_receivable_status_sql')) {
            require_once __DIR__ . '/../admin/includes/receivables.php';
        }
        $st = $pdo->prepare("SELECT b.*, pb.billing_account_id AS pb_billing_account_id, pb.billing_reference AS pb_billing_reference,
                pb.billing_due_date AS pb_billing_due_date, pb.billing_covers AS pb_billing_covers
            FROM bookings b LEFT JOIN bookings pb ON pb.id = b.primary_booking_id
            WHERE (b.billing_account_id = ? OR (b.billing_account_id IS NULL AND pb.billing_account_id = ?))
              AND " . rh_receivable_status_sql('room', 'b') . " ORDER BY b.check_out_date, b.id");
        $st->execute([$accountId, $accountId]);
        return rh_billing_open_rows($pdo, $st->fetchAll(PDO::FETCH_ASSOC), $includeSettled)[$accountId] ?? [];
    }

    /**
     * Every company's open bookings in ONE load (the Company accounts list): account id => rows exactly as
     * rh_billing_open_bookings() returns them.
     */
    function rh_billing_open_bookings_all(PDO $pdo): array
    {
        if (!rh_billing_ready($pdo)) {
            return [];
        }
        if (!function_exists('rh_receivable_status_sql')) {
            require_once __DIR__ . '/../admin/includes/receivables.php';
        }
        $found = $pdo->query("SELECT b.*, pb.billing_account_id AS pb_billing_account_id, pb.billing_reference AS pb_billing_reference,
                pb.billing_due_date AS pb_billing_due_date, pb.billing_covers AS pb_billing_covers
            FROM bookings b LEFT JOIN bookings pb ON pb.id = b.primary_booking_id
            WHERE (b.billing_account_id IS NOT NULL OR pb.billing_account_id IS NOT NULL)
              AND " . rh_receivable_status_sql('room', 'b') . " ORDER BY b.check_out_date, b.id")->fetchAll(PDO::FETCH_ASSOC);
        return rh_billing_open_rows($pdo, $found, false);
    }

    /** Batch split of booking rows grouped by company: account id => [['booking' => row, 'split' => split], ...], oldest due first. */
    function rh_billing_open_rows(PDO $pdo, array $found, bool $includeSettled): array
    {
        $splits = rh_billing_splits_for_rows($pdo, $found);
        $by = [];
        foreach ($found as $b) {
            $sp = $splits[(int)$b['id']];
            if (!$sp['on_account'] || (!$includeSettled && $sp['company_due'] <= rh_billing_tol())) {
                continue;
            }
            unset($b['pb_billing_account_id'], $b['pb_billing_reference'], $b['pb_billing_due_date'], $b['pb_billing_covers']);
            $by[(int)$sp['account_id']][] = ['booking' => $b, 'split' => $sp];
        }
        foreach ($by as &$rows) {
            usort($rows, static function ($x, $y) {
                return strcmp((string)$x['split']['due_date'], (string)$y['split']['due_date']) ?: ((int)$x['booking']['id'] <=> (int)$y['booking']['id']);
            });
        }
        unset($rows);
        return $by;
    }

    /** Totals for a company: owed, overdue, count, oldest days past due. */
    function rh_billing_account_totals(PDO $pdo, int $accountId): array
    {
        return rh_billing_totals_from_rows(rh_billing_open_bookings($pdo, $accountId));
    }

    /** PURE. Totals from rh_billing_open_bookings() rows: owed, overdue, count, oldest days past due. */
    function rh_billing_totals_from_rows(array $rows): array
    {
        $out = ['owed' => 0.0, 'overdue' => 0.0, 'count' => 0, 'oldest_days' => 0];
        foreach ($rows as $r) {
            $out['owed'] += $r['split']['company_due'];
            $out['count']++;
            if ($r['split']['days_past_due'] > 0) {
                $out['overdue'] += $r['split']['company_due'];
                $out['oldest_days'] = max($out['oldest_days'], (int)$r['split']['days_past_due']);
            }
        }
        $out['owed'] = round($out['owed'], 2);
        $out['overdue'] = round($out['overdue'], 2);
        return $out;
    }

    /* ── setting / removing the company on a booking ───────────────────────────── */

    /**
     * Set or remove the company that pays this booking (Booking Details "Who pays"). One transaction, History old -> new.
     * $in: account_id (0 = the guest pays), reference, due_date (Y-m-d or ''), covers ('stay' | 'everything').
     * Setting a company also switches on "Check in before payment" (083) with the note "On account - <name> ...";
     * removing it switches that off again only when the note is the automatic one.
     * Throws Exception with a staff-readable message (nothing saved).
     */
    function rh_billing_set_on_booking(PDO $pdo, int $bookingId, array $in, array $user): array
    {
        if (!rh_billing_ready($pdo)) {
            throw new Exception('Company billing is not available yet: database update 084 has not been run.');
        }
        $accId = max(0, (int)($in['account_id'] ?? 0));
        $ref = mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($in['reference'] ?? ''))), 0, 80);
        $due = trim((string)($in['due_date'] ?? ''));
        $covers = ((string)($in['covers'] ?? 'stay')) === 'everything' ? 'everything' : 'stay';
        if ($due !== '') {
            $d = DateTime::createFromFormat('!Y-m-d', $due);
            if (!$d || $d->format('Y-m-d') !== $due) {
                throw new Exception('Pick a valid due date (or leave it empty to use the company\'s payment terms).');
            }
        }
        $acc = $accId > 0 ? rh_billing_account($pdo, $accId) : null;
        if ($accId > 0 && !$acc) {
            throw new Exception('Choose an active company.');
        }
        $arrCols = function_exists('rh_checkin_arrangement_columns_present') && rh_checkin_arrangement_columns_present($pdo);
        $uid = (int)($user['id'] ?? 0);
        $who = (string)($user['full_name'] ?? ($user['username'] ?? ''));

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $st->execute([$bookingId]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b) {
                throw new Exception('Booking not found.');
            }
            if (!in_array((string)$b['status'], ['pending', 'tentative', 'confirmed', 'checked-in', 'checked-out'], true)) {
                throw new Exception('Who pays can only be changed on a live or checked-out booking.');
            }
            $curId = (int)($b['billing_account_id'] ?? 0);
            // A deactivated company stays on the bookings it already has (an LPO / due date edit keeps it); it cannot be
            // newly chosen.
            if ($acc && (int)$acc['active'] !== 1 && (int)$acc['id'] !== $curId) {
                throw new Exception('Choose an active company.');
            }
            // Fix 7: the company's payments belong to it. While any are on this booking (or on a group member that
            // follows it), the company cannot be removed or switched: move or refund them first.
            if ($curId > 0 && $curId !== ($acc ? (int)$acc['id'] : 0)) {
                $grp = $pdo->prepare('SELECT id FROM bookings WHERE id = ? OR (primary_booking_id = ? AND billing_account_id IS NULL)');
                $grp->execute([$bookingId, $bookingId]);
                $held = array_sum(rh_billing_company_paid_map($pdo, array_map('intval', $grp->fetchAll(PDO::FETCH_COLUMN))));
                if ($held > rh_billing_tol()) {
                    $curName = (string)((rh_billing_account($pdo, $curId)['name'] ?? '') ?: ('#' . $curId));
                    if ($ownTx) {
                        $pdo->rollBack();
                    }
                    if (function_exists('rh_log_event')) {
                        rh_log_event('bookings', 'warning', 'Company account change refused: company money on the booking', ['booking_id' => $bookingId,
                            'ref' => $b['booking_reference'], 'company' => $curName, 'company_paid' => round($held, 2), 'wanted' => $acc ? (string)$acc['name'] : 'Guest pays',
                            'by' => $user['username'] ?? null]);
                    }
                    throw new Exception('Move or refund ' . $curName . '\'s payment first: ' . rh_billing_money($held) . ' of ' . $curName
                        . '\'s money is on this booking' . ($acc ? '' : ', so the company cannot be taken off') . '. Nothing was changed.');
                }
            }
            $names = rh_billing_account_names($pdo);
            $old = [
                'company' => (int)$b['billing_account_id'] > 0 ? ($names[(int)$b['billing_account_id']] ?? ('#' . (int)$b['billing_account_id'])) : 'Guest pays',
                'lpo' => (string)($b['billing_reference'] ?? ''),
                'due_date' => (string)($b['billing_due_date'] ?? ''),
                'extras' => (string)($b['billing_covers'] ?? '') === 'everything' ? 'company' : 'guest',
            ];
            $new = [
                'company' => $acc ? (string)$acc['name'] : 'Guest pays',
                'lpo' => $acc ? $ref : '',
                'due_date' => $acc ? $due : '',
                'extras' => ($acc && $covers === 'everything') ? 'company' : 'guest',
            ];
            if ($old === $new) {
                if ($ownTx) {
                    $pdo->rollBack();
                }
                return ['changed' => false, 'old' => $old, 'new' => $new];
            }
            $pdo->prepare('UPDATE bookings SET billing_account_id = ?, billing_reference = ?, billing_due_date = ?, billing_covers = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$acc ? (int)$acc['id'] : null, $acc && $ref !== '' ? $ref : null, $acc && $due !== '' ? $due : null, $acc ? $covers : null, $bookingId]);
            $arrNote = null;
            if ($arrCols) {
                $oldNote = trim((string)($b['checkin_arrangement_note'] ?? ''));
                $autoNote = strpos($oldNote, 'On account - ') === 0;
                if ($acc) {
                    $arrNote = mb_substr('On account - ' . $acc['name'] . ($ref !== '' ? ' (LPO ' . $ref . ')' : ''), 0, 255);
                    if ($oldNote !== '' && !$autoNote) {
                        $arrNote = $oldNote; // staff wrote their own reason: keep it
                    }
                    $pdo->prepare('UPDATE bookings SET checkin_without_payment = 1, checkin_arrangement_note = ? WHERE id = ?')->execute([$arrNote, $bookingId]);
                } elseif ($autoNote) {
                    $pdo->prepare('UPDATE bookings SET checkin_without_payment = 0, checkin_arrangement_note = NULL WHERE id = ?')->execute([$bookingId]);
                }
            }
            $parts = [];
            foreach (['company' => 'Who pays', 'lpo' => 'LPO / order no.', 'due_date' => 'Due date', 'extras' => 'Extras paid by'] as $k => $label) {
                if ($old[$k] !== $new[$k]) {
                    $parts[] = $label . ': ' . ($old[$k] !== '' ? $old[$k] : '-') . ' → ' . ($new[$k] !== '' ? $new[$k] : '-');
                }
            }
            $desc = implode('; ', $parts) . '.';
            if (function_exists('logBookingEvent')) {
                logBookingEvent($bookingId, (string)$b['booking_reference'], $acc ? 'Company account set' : 'Company account removed', 'update', $desc,
                    $old['company'], $new['company'], 'admin', $uid ?: null, $who !== '' ? $who : null, ['old' => $old, 'new' => $new]);
            }
            if (function_exists('logBookingAudit')) {
                logBookingAudit($bookingId, 'billing_changed', $old, $new, $desc, (string)$b['booking_reference']);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e instanceof Exception ? $e : new Exception($e->getMessage());
        }
        if (function_exists('rh_log_event')) {
            rh_log_event('bookings', 'info', $acc ? 'Booking put on company account' : 'Booking taken off company account',
                ['booking_id' => $bookingId, 'ref' => $b['booking_reference'], 'old' => $old, 'new' => $new, 'by' => $user['username'] ?? null]);
        }
        return ['changed' => true, 'old' => $old, 'new' => $new, 'description' => $desc];
    }

    /* ── company payments (one payment split exactly across bookings, D5) ──────── */

    function rh_billing_methods(): array
    {
        return ['bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'mobile_money' => 'Mobile money', 'cash' => 'Cash',
            'credit_card' => 'Credit card', 'debit_card' => 'Debit card', 'other' => 'Other'];
    }

    function rh_billing_next_remittance_ref(PDO $pdo, string $date): string
    {
        if (!function_exists('finance_next_sequence_number')) {
            require_once __DIR__ . '/finance-sequences.php';
        }
        $year = substr($date, 0, 4);
        for ($i = 0; $i < 50; $i++) {
            $n = finance_next_sequence_number($pdo, 'billing_remittance', $year, 1);
            $ref = 'REM-' . $year . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
            $st = $pdo->prepare('SELECT COUNT(*) FROM billing_remittances WHERE remittance_reference = ?');
            $st->execute([$ref]);
            if ((int)$st->fetchColumn() === 0) {
                return $ref;
            }
        }
        throw new RuntimeException('Could not allocate a company payment reference.');
    }

    /**
     * Record one company payment split across its bookings. $in: amount, payment_method, received_date (Y-m-d, not in
     * the future), bank_reference, notes, lines (booking_id => amount). The lines must add up to the amount exactly
     * (D5: any excess is refused) and each line may not exceed what the company owes on that booking.
     * Each line becomes a normal room payment (VAT split per booking, receipt number, recalculated balance, History).
     * One transaction: all lines or nothing. Throws Exception with a staff-readable message.
     */
    function rh_billing_record_remittance(PDO $pdo, int $accountId, array $in, array $user): array
    {
        if (!rh_billing_ready($pdo)) {
            throw new Exception('Company billing is not available yet: database update 084 has not been run.');
        }
        foreach (['/../admin/includes/finance-account-sync.php', '/../admin/includes/booking-lifecycle.php', '/finance-sequences.php', '/booking-timeline.php'] as $f) {
            if (is_file(__DIR__ . $f)) {
                require_once __DIR__ . $f;
            }
        }
        $tol = rh_billing_tol();
        $acc = rh_billing_account($pdo, $accountId);
        if (!$acc) {
            throw new Exception('Company not found.');
        }
        $amount = round((float)str_replace([',', ' '], '', (string)($in['amount'] ?? '0')), 2);
        if ($amount <= $tol) {
            throw new Exception('Enter the amount the company paid.');
        }
        $method = (string)($in['payment_method'] ?? '');
        if (!isset(rh_billing_methods()[$method])) {
            throw new Exception('Choose how the company paid.');
        }
        $date = trim((string)($in['received_date'] ?? ''));
        $dObj = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$dObj || $dObj->format('Y-m-d') !== $date) {
            throw new Exception('Pick the date the money was received.');
        }
        if ($date > date('Y-m-d')) {
            throw new Exception('The date received cannot be in the future.');
        }
        $bankRef = mb_substr(trim((string)($in['bank_reference'] ?? '')), 0, 80);
        $notes = mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($in['notes'] ?? ''))), 0, 255);
        $lines = [];
        foreach ((array)($in['lines'] ?? []) as $bid => $amt) {
            $a = round((float)str_replace([',', ' '], '', (string)$amt), 2);
            if ((int)$bid > 0 && $a > $tol) {
                $lines[(int)$bid] = $a;
            } elseif ((int)$bid > 0 && $a < -$tol) {
                throw new Exception('A booking line cannot be negative.');
            }
        }
        if (!$lines) {
            throw new Exception('Split the payment across at least one booking.');
        }
        $sum = round(array_sum($lines), 2);
        if (abs($sum - $amount) > $tol) {
            throw new Exception('The booking lines add up to ' . rh_billing_money($sum) . ' but the company paid ' . rh_billing_money($amount)
                . '. They must match exactly: any amount that is not for these bookings cannot be recorded here.');
        }
        $uid = (int)($user['id'] ?? 0);
        $who = (string)($user['full_name'] ?? ($user['username'] ?? ''));
        $vatOn = function_exists('rh_vat_enabled') ? rh_vat_enabled() : false;
        $fallbackRate = $vatOn ? (float)getSetting('vat_rate') : 0.0;

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        $legs = [];
        try {
            // 1) Check every line under its booking lock BEFORE anything is written, so a refused line saves nothing.
            ksort($lines);
            $lk = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $checked = [];
            foreach ($lines as $bid => $lineAmt) {
                $lk->execute([$bid]);
                $b = $lk->fetch(PDO::FETCH_ASSOC);
                if (!$b) {
                    throw new Exception('Booking #' . $bid . ' was not found.');
                }
                if (!recalculateBookingFinancials($bid)) {
                    throw new Exception('Could not recalculate the balance of ' . $b['booking_reference'] . '. Nothing was recorded.');
                }
                $lk->execute([$bid]);
                $b = $lk->fetch(PDO::FETCH_ASSOC) ?: $b;
                $bill = rh_booking_billing($pdo, $b);
                if (!$bill['on_account'] || $bill['account_id'] !== $accountId) {
                    throw new Exception($b['booking_reference'] . ' is not on ' . $acc['name'] . '\'s account.');
                }
                $guard = function_exists('bookingAllowsAction') ? bookingAllowsAction($b, 'record_payment') : ['allowed' => true];
                if (empty($guard['allowed'])) {
                    throw new Exception($b['booking_reference'] . ': ' . ($guard['reason'] ?: 'a payment cannot be recorded on this booking.'));
                }
                $sp = rh_booking_bill_split($pdo, $bid, $b);
                if ($lineAmt > $sp['company_due'] + $tol) {
                    throw new Exception($b['booking_reference'] . ': ' . $acc['name'] . ' owes ' . rh_billing_money($sp['company_due'])
                        . ' on this booking, less than the ' . rh_billing_money($lineAmt) . ' entered.');
                }
                $checked[$bid] = [$b, $bill, $sp];
            }
            // 2) Write: the company payment, then one normal room payment per line.
            $ref = rh_billing_next_remittance_ref($pdo, $date);
            $pdo->prepare('INSERT INTO billing_remittances (billing_account_id, remittance_reference, received_date, payment_method, bank_reference, amount, notes, recorded_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$accountId, $ref, $date, $method, $bankRef !== '' ? $bankRef : null, $amount, $notes !== '' ? $notes : null, $uid ?: null]);
            $remId = (int)$pdo->lastInsertId();
            foreach ($lines as $bid => $lineAmt) {
                [$b, $bill, $sp] = $checked[$bid];
                $split = rh_account_vat_split($pdo, 'room', $bid, $lineAmt, $fallbackRate); // VAT pro rata to this booking
                do {
                    $payRef = 'COMP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                    $chk = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE payment_reference = ?');
                    $chk->execute([$payRef]);
                } while ((int)$chk->fetchColumn() > 0);
                $receipt = finance_next_receipt_number($pdo, $date);
                $payType = abs($lineAmt - (float)$b['amount_due']) <= $tol ? 'full_payment' : 'partial_payment';
                $pNotes = 'Company payment ' . $ref . ' from ' . $acc['name'] . ($bill['reference'] !== '' ? ' (LPO ' . $bill['reference'] . ')' : '')
                    . ($bankRef !== '' ? ', bank ref ' . $bankRef : '') . ($notes !== '' ? '. ' . $notes : '');
                $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount,
                        total_amount, payment_method, payment_type, payment_status, receipt_number, notes, recorded_by, created_at)
                    VALUES (?, 'room', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, NOW())")
                    ->execute([$payRef, $bid, $b['booking_reference'], $date, $split['net'], $split['rate'], $split['vat'], $lineAmt, $method, $payType,
                        $receipt, mb_substr($pNotes, 0, 1000), $uid ?: null]);
                $pid = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO billing_remittance_lines (remittance_id, booking_id, payment_id, amount) VALUES (?, ?, ?, ?)')
                    ->execute([$remId, $bid, $pid, $lineAmt]);
                if (!recalculateBookingFinancials($bid)) {
                    throw new Exception('Could not recalculate the balance of ' . $b['booking_reference'] . '. Nothing was recorded.');
                }
                $lk->execute([$bid]);
                $after = $lk->fetch(PDO::FETCH_ASSOC) ?: [];
                $spAfter = rh_booking_bill_split($pdo, $bid, $after);
                $desc = $acc['name'] . ' paid ' . rh_billing_money($lineAmt) . ' (' . (rh_billing_methods()[$method] ?? $method) . ', ' . $ref . ', receipt ' . $receipt . ').'
                    . ' Company owes ' . rh_billing_money($sp['company_due']) . ' → ' . rh_billing_money($spAfter['company_due'])
                    . '; balance ' . rh_billing_money((float)$b['amount_due']) . ' → ' . rh_billing_money((float)($after['amount_due'] ?? 0)) . '.';
                if (function_exists('logBookingEvent')) {
                    logBookingEvent($bid, (string)$b['booking_reference'], 'Company payment recorded', 'payment', $desc,
                        number_format((float)$b['amount_due'], 2, '.', ''), number_format((float)($after['amount_due'] ?? 0), 2, '.', ''), 'admin', $uid ?: null,
                        $who !== '' ? $who : null, ['remittance' => $ref, 'payment_id' => $pid, 'amount' => $lineAmt, 'company_due_before' => $sp['company_due'],
                            'company_due_after' => $spAfter['company_due']]);
                }
                if (function_exists('rh_log_payment_audit')) {
                    rh_log_payment_audit($pdo, $pid, $payRef, 'created', [], $uid ?: null, $who !== '' ? $who : 'staff', 'Company payment ' . $ref . ' from ' . $acc['name']);
                }
                $legs[] = ['booking_id' => $bid, 'reference' => (string)$b['booking_reference'], 'guest_name' => (string)$b['guest_name'], 'payment_id' => $pid,
                    'payment_reference' => $payRef, 'receipt_number' => $receipt, 'amount' => $lineAmt, 'company_due_after' => $spAfter['company_due']];
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e instanceof Exception ? $e : new Exception($e->getMessage());
        }
        if (function_exists('rh_log_event')) {
            rh_log_event('payments', 'info', 'Company payment recorded', ['account_id' => $accountId, 'company' => $acc['name'], 'remittance' => $ref,
                'amount' => $amount, 'method' => $method, 'lines' => array_map(static fn($l) => [$l['reference'], $l['amount']], $legs), 'by' => $user['username'] ?? null]);
        }
        return ['remittance_id' => $remId, 'reference' => $ref, 'amount' => $amount, 'legs' => $legs, 'account' => $acc];
    }

    /** True when a payment row is part of a company payment (Edit payment refuses those: void and record again). */
    function rh_billing_payment_is_company_leg(PDO $pdo, int $paymentId): bool
    {
        if (!rh_billing_ready($pdo) || $paymentId <= 0) {
            return false;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM billing_remittance_lines WHERE payment_id = ?');
        $st->execute([$paymentId]);
        return (int)$st->fetchColumn() > 0;
    }

    /* ── company documents: statement + payment advice ─────────────────────────── */

    /** Themed statement PDF HTML for a company: every booking it still owes on. */
    function rh_billing_statement_html(PDO $pdo, array $acc, array $rows): string
    {
        if (!function_exists('rh_pdf_document_shell')) {
            require_once __DIR__ . '/../config/document-theme.php';
        }
        $cur = rh_billing_currency();
        $items = [];
        $total = 0.0;
        $overdue = 0.0;
        foreach ($rows as $r) {
            $b = $r['booking'];
            $s = $r['split'];
            $total += $s['company_due'];
            if ($s['days_past_due'] > 0) {
                $overdue += $s['company_due'];
            }
            $items[] = [
                rh_pdf_e((string)$b['booking_reference']) . '<br><span style="font-size:7.5pt;">' . rh_pdf_e((string)$b['guest_name']) . '</span>',
                rh_pdf_e(date('d M Y', strtotime((string)$b['check_in_date'])) . ' - ' . date('d M Y', strtotime((string)$b['check_out_date']))),
                rh_pdf_e($s['reference'] !== '' ? $s['reference'] : '-'),
                rh_pdf_e(trim((string)($b['final_invoice_number'] ?? '')) !== '' ? (string)$b['final_invoice_number'] : '-'),
                rh_pdf_e($s['due_date'] !== '' ? date('d M Y', strtotime($s['due_date'])) . ($s['days_past_due'] > 0 ? ' (' . $s['days_past_due'] . ' days late)' : '') : '-'),
                rh_pdf_e(number_format((float)$s['company_due'], 2)),
            ];
        }
        $body = rh_pdf_items_table([
            ['label' => 'Booking / guest', 'width' => 22], ['label' => 'Stay', 'width' => 22], ['label' => 'LPO', 'width' => 12],
            ['label' => 'Invoice', 'width' => 14], ['label' => 'Due', 'width' => 16], ['label' => 'Owed (' . $cur . ')', 'width' => 14, 'align' => 'right'],
        ], $items);
        $body .= rh_pdf_totals_table([
            ['label' => 'Overdue', 'value' => $cur . ' ' . number_format($overdue, 2), 'type' => $overdue > rh_billing_tol() ? 'danger' : 'normal'],
            ['label' => 'Total owed', 'value' => $cur . ' ' . number_format($total, 2), 'type' => 'total'],
        ]);
        $meta = [
            ['Statement for', (string)$acc['name']],
            ['Date', date('d M Y')],
            ['Billing email', (string)($acc['billing_email'] ?? '')],
            ['Payment terms', (int)$acc['terms_days'] . ' days after check-out'],
        ];
        return rh_pdf_document_shell('Account statement', $meta, $body, ['banner' => ['text' => $total > rh_billing_tol() ? 'Balance due' : 'Nothing owed', 'tone' => $total > rh_billing_tol() ? 'danger' : 'neutral']]);
    }

    /**
     * Email a PDF to a company's billing email (+ its CC + $extraCc). No billing email = not sent: an email_send_log
     * 'not_sent' row and a staff message. Returns ['success'=>bool,'message'=>string,'to'=>string].
     */
    function rh_billing_send_pdf(PDO $pdo, array $acc, string $kind, string $subject, string $htmlBody, string $pdfBytes, string $fileName, array $extraCc = [], array $ctx = []): array
    {
        if (!function_exists('rh_email_send_log_not_sent')) {
            require_once __DIR__ . '/email-send-log.php';
        }
        $to = (string)(rh_billing_email_list((string)($acc['billing_email'] ?? ''))[0] ?? '');
        $account = rh_email_ctx_account('company', (int)$acc['id'], (string)$acc['name']) + $ctx;
        if ($to === '') {
            rh_email_send_log_not_sent($kind, '', (string)$acc['name'], $account, 'The company has no billing email');
            return ['success' => false, 'message' => 'Not sent: ' . $acc['name'] . ' has no billing email. Add one on Company accounts.', 'to' => ''];
        }
        if (!function_exists('sendEmailWithAttachmentAndCC')) {
            require_once __DIR__ . '/../config/invoice.php';
        }
        $cc = array_values(array_unique(array_merge(rh_billing_email_list((string)($acc['billing_cc'] ?? '')), $extraCc)));
        // Test mode (automated_email_test_recipient set, the same rule as the scheduler's rh_automated_email_adjust):
        // ONLY the test address, no copies, [TEST] subject; an unusable test address = nothing sent.
        $route = rh_billing_mail_route($to, $cc, $subject, rh_billing_test_recipient());
        if (!$route['send']) {
            rh_email_send_log_not_sent($kind, $to, (string)$acc['name'], $account, $route['note']);
            return ['success' => false, 'message' => 'Not sent: ' . $route['note'], 'to' => ''];
        }
        [$to, $cc, $subject] = [$route['to'], $route['cc'], $route['subject']];
        $tmp = tempnam(sys_get_temp_dir(), 'rhbill');
        $path = $tmp . '-' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', $fileName);
        @rename($tmp, $path);
        file_put_contents($path, $pdfBytes);
        try {
            $emailCtx = function_exists('rh_email_context') ? rh_email_context(['kind' => $kind] + $account) : null;
            try {
                $r = sendEmailWithAttachmentAndCC($to, (string)$acc['name'], $subject, $htmlBody, $path, $cc);
            } finally {
                $emailCtx = null;
            }
        } finally {
            @unlink($path);
        }
        return ['success' => !empty($r['success']), 'message' => !empty($r['success']) ? 'Sent to ' . $to . ($cc ? ' (copy: ' . implode(', ', $cc) . ')' : '') : 'Not sent: ' . ($r['message'] ?? 'unknown error'), 'to' => $to];
    }

    /**
     * The test address while test mode is on: the scheduler's context redirect when a run set one, else the
     * "Test recipient" on Automated Emails (automated_email_test_recipient). '' = test mode off.
     */
    function rh_billing_test_recipient(): string
    {
        $ctx = $GLOBALS['rh_automated_ctx'] ?? null;
        if (is_array($ctx) && trim((string)($ctx['redirect'] ?? '')) !== '') {
            return trim((string)$ctx['redirect']);
        }
        return function_exists('getSetting') ? trim((string)getSetting('automated_email_test_recipient', '')) : '';
    }

    /**
     * PURE. Where a company email goes. Test mode ($testRecipient set): only that address, no CC, "[TEST]" subject;
     * an invalid test address = do not send (fail closed, never the real company). Otherwise unchanged.
     * @return array{send:bool,to:string,cc:array,subject:string,note:string}
     */
    function rh_billing_mail_route(string $to, array $cc, string $subject, string $testRecipient): array
    {
        $testRecipient = trim($testRecipient);
        if ($testRecipient === '') {
            return ['send' => true, 'to' => $to, 'cc' => array_values($cc), 'subject' => $subject, 'note' => ''];
        }
        if (!filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
            return ['send' => false, 'to' => '', 'cc' => [], 'subject' => $subject, 'note' => 'test mode is on but the test address is not a valid email'];
        }
        return ['send' => true, 'to' => $testRecipient, 'cc' => [], 'subject' => (strpos($subject, '[TEST]') === 0 ? '' : '[TEST] ') . $subject, 'note' => 'test mode'];
    }

    /** Statement PDF + email to the company. */
    function rh_billing_send_statement(PDO $pdo, int $accountId, array $extraCc = []): array
    {
        $acc = rh_billing_account($pdo, $accountId);
        if (!$acc) {
            return ['success' => false, 'message' => 'Company not found.', 'to' => ''];
        }
        $rows = rh_billing_open_bookings($pdo, $accountId);
        $total = round(array_sum(array_map(static fn($r) => $r['split']['company_due'], $rows)), 2);
        if (!function_exists('bookingRenderPdfFromHtml')) {
            require_once __DIR__ . '/../config/email.php';
        }
        $pdf = bookingRenderPdfFromHtml(rh_billing_statement_html($pdo, $acc, $rows), 'Statement ' . $acc['name']);
        $site = function_exists('getSetting') ? (string)getSetting('site_name', 'the hotel') : 'the hotel';
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $html = '<p>Dear ' . $e(trim((string)$acc['contact_name']) !== '' ? $acc['contact_name'] : $acc['name']) . ',</p>'
            . '<p>Please find attached your account statement from ' . $e($site) . '. Total owed: <strong>' . $e(rh_billing_money($total)) . '</strong> on '
            . count($rows) . ' booking(s).</p><p>Thank you.</p>';
        return rh_billing_send_pdf($pdo, $acc, 'company_statement', 'Account statement - ' . $site . ' - ' . $acc['name'], $html, $pdf,
            'Statement-' . $acc['name'] . '-' . date('Ymd') . '.pdf', $extraCc);
    }

    /** "Payment received" advice to the company for a recorded remittance (lists every booking line and receipt). */
    function rh_billing_send_advice(PDO $pdo, int $remittanceId): array
    {
        $st = $pdo->prepare('SELECT * FROM billing_remittances WHERE id = ?');
        $st->execute([$remittanceId]);
        $rem = $st->fetch(PDO::FETCH_ASSOC);
        $acc = $rem ? rh_billing_account($pdo, (int)$rem['billing_account_id']) : null;
        if (!$rem || !$acc) {
            return ['success' => false, 'message' => 'Company payment not found.', 'to' => ''];
        }
        $ls = $pdo->prepare('SELECT l.amount, b.booking_reference, b.guest_name, b.billing_reference, p.receipt_number FROM billing_remittance_lines l
            JOIN bookings b ON b.id = l.booking_id JOIN payments p ON p.id = l.payment_id WHERE l.remittance_id = ? ORDER BY l.id');
        $ls->execute([$remittanceId]);
        $lines = $ls->fetchAll(PDO::FETCH_ASSOC);
        if (!function_exists('rh_pdf_document_shell')) {
            require_once __DIR__ . '/../config/document-theme.php';
        }
        $items = [];
        foreach ($lines as $l) {
            $items[] = [rh_pdf_e($l['booking_reference']), rh_pdf_e($l['guest_name']), rh_pdf_e((string)($l['billing_reference'] ?? '') !== '' ? $l['billing_reference'] : '-'),
                rh_pdf_e((string)$l['receipt_number']), rh_pdf_e(number_format((float)$l['amount'], 2))];
        }
        $cur = rh_billing_currency();
        $body = rh_pdf_items_table([['label' => 'Booking', 'width' => 20], ['label' => 'Guest', 'width' => 28], ['label' => 'LPO', 'width' => 16],
            ['label' => 'Receipt', 'width' => 18], ['label' => 'Amount (' . $cur . ')', 'width' => 18, 'align' => 'right']], $items)
            . rh_pdf_totals_table([['label' => 'Total received', 'value' => $cur . ' ' . number_format((float)$rem['amount'], 2), 'type' => 'total']]);
        $meta = [['Received from', (string)$acc['name']], ['Reference', (string)$rem['remittance_reference']], ['Date received', date('d M Y', strtotime((string)$rem['received_date']))],
            ['Method', rh_billing_methods()[(string)$rem['payment_method']] ?? (string)$rem['payment_method']], ['Bank reference', (string)($rem['bank_reference'] ?? '')]];
        if (!function_exists('bookingRenderPdfFromHtml')) {
            require_once __DIR__ . '/../config/email.php';
        }
        $pdf = bookingRenderPdfFromHtml(rh_pdf_document_shell('Payment received', $meta, $body, ['banner' => ['text' => 'Thank you', 'tone' => 'neutral']]), 'Payment ' . $rem['remittance_reference']);
        $site = function_exists('getSetting') ? (string)getSetting('site_name', 'the hotel') : 'the hotel';
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $html = '<p>Dear ' . $e(trim((string)$acc['contact_name']) !== '' ? $acc['contact_name'] : $acc['name']) . ',</p><p>Thank you: we received '
            . $e(rh_billing_money((float)$rem['amount'])) . ' (' . $e($rem['remittance_reference']) . '). The attached advice shows how it was applied to your bookings.</p>';
        return rh_billing_send_pdf($pdo, $acc, 'company_payment_advice', 'Payment received - ' . $site . ' - ' . $rem['remittance_reference'], $html, $pdf,
            'Payment-' . $rem['remittance_reference'] . '.pdf');
    }
}
