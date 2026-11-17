<?php
/**
 * Group money moves (group bookings plan A5 and D-R6, accounting lead 2026-11-18).
 *
 *   rh_group_credit_move()  A5 "Use this credit for the rooms that owe": one room's credit pays the other rooms of its
 *                           group that owe.
 *   rh_group_lump_quote()   D-R6 review: how much of the group's money can move to a room leaving the group, and from
 *                           which rooms.
 *   rh_group_lump_apply()   D-R6 confirm, run inside rh_group_remove()'s transaction through its after_detach hook.
 *
 * Both moves use the same pair and never re-allocate an existing payment row (receipts, refund lineage and VAT split
 * stay as they are): ONE credit note is issued on the room giving the money (recordCreditNoteRefundLegs: credit-note
 * refund legs against its own payments, so its paid drops) and that note is applied to the receiving room(s)
 * (applyCreditNote: a credit-note payment row, so their paid rises). Group paid, billed and net position do not change.
 *
 * Rules (ASSUMPTIONs logged in BUILD_PLAN.md):
 *  - Credit that can move = paid above billed (getBookingFolioSummary), the money the refund legs can actually take.
 *  - Rooms that can receive: not deleted, not cancelled / no-show / expired (applyCreditNote refuses those), owing.
 *  - A5 moves min(credit, what the others owe); any rest stays as credit on the giving room.
 *  - Same-payer rule (Ship C2b, owner 2026-11-18): every room involved must have the same payer. All paid by the guest:
 *    as above. All billed to the SAME company: only that company's money moves (its payment rows on the giving room,
 *    credit note made out to the company) and a receiving room gets at most what the company owes on it (company_due),
 *    never the guest's extras. Guest money above the bill on a company room stays where it is. Mixed guest / company or
 *    two companies: refused. Read side: includes/billing-accounts.php rh_billing_company_cn_payments().
 *  - Preview -> confirm: the confirm re-reads every figure under the row locks and refuses when the reviewed state
 *    token no longer matches.
 */

require_once __DIR__ . '/booking-group-ops.php';
require_once __DIR__ . '/booking-timeline.php';
require_once __DIR__ . '/../config/credit-notes.php';
require_once __DIR__ . '/../admin/includes/group-payment.php';

if (!class_exists('RhGroupMoneyRefused')) {
    /** A refusal the screen shows as it is (nothing was changed). */
    class RhGroupMoneyRefused extends RuntimeException
    {
    }
}

if (!function_exists('rh_group_credit_move')) {

    /** A room's own figures (the same as its page): billed, paid, owed and the credit that can move (paid above billed). */
    function rh_gcm_figures(int $bookingId): array
    {
        $m = rh_group_ops_money($bookingId);
        $m['credit'] = round(max(0.0, $m['paid'] - $m['billed']), 2);
        if ($m['credit'] <= (float)BALANCE_TOLERANCE) {
            $m['credit'] = 0.0;
        }
        return $m;
    }

    /** Statuses a credit note can never be applied to (applyCreditNote refuses them). */
    function rh_gcm_closed(string $status): bool
    {
        return in_array(strtolower($status), ['cancelled', 'no-show', 'expired'], true);
    }

    /**
     * Same-payer rule (Ship C2b): who pays every room in $ids. mode 'guest' (no company on any room), 'company' (every room
     * billed to the same company: account_id, name, billing_email) or '' with error (mixed guest / company, or two companies).
     * @return array{mode:string,account_id:int,name:string,billing_email:string,error:string}
     */
    function rh_gcm_payer(PDO $pdo, array $ids): array
    {
        if (!function_exists('rh_booking_billing')) {
            require_once __DIR__ . '/billing-accounts.php';
        }
        $out = ['mode' => 'guest', 'account_id' => 0, 'name' => '', 'billing_email' => '', 'error' => ''];
        $guest = 0;
        $cos = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $bill = rh_booking_billing($pdo, ['id' => $id]);
            if (!empty($bill['on_account'])) {
                $cos[(int)$bill['account_id']] = $bill;
            } else {
                $guest++;
            }
        }
        if (!$cos) {
            return $out;
        }
        if ($guest === 0 && count($cos) === 1) {
            $b = reset($cos);
            return ['mode' => 'company', 'account_id' => (int)$b['account_id'], 'name' => (string)($b['name'] ?: 'the company'),
                'billing_email' => (string)$b['billing_email'], 'error' => ''];
        }
        $names = array_map(static fn($b) => (string)($b['name'] ?: 'a company account'), $cos);
        $out['mode'] = '';
        $out['error'] = count($cos) > 1
            ? 'Not moved: these rooms are billed to different companies (' . implode(' and ', $names) . '). Money only moves between rooms '
                . 'billed to the same company, so one company\'s money never pays another company\'s bill.'
            : 'Not moved: a room here is billed to ' . reset($names) . ' and another is paid by the guest. Money only moves between rooms '
                . 'with the same payer (all paid by the guests, or all billed to the same company), so a guest\'s money and a company\'s money are never mixed.';
        return $out;
    }

    /**
     * The company's payment rows on $bookingId (billing_remittance_lines of $accountId) with what is still refundable on
     * each, newest first, in allocateBookingRefunds' row shape; locked when $lock (inside the caller's transaction).
     */
    function rh_gcm_company_rows(PDO $pdo, int $bookingId, int $accountId, bool $lock): array
    {
        $st = $pdo->prepare("SELECT p.id, p.payment_method, p.booking_type, p.booking_id, p.booking_reference, p.payment_amount, p.vat_rate, p.vat_amount,
                   p.total_amount, p.credit_note_id,
                   COALESCE((SELECT SUM(COALESCE(r.refund_amount, r.total_amount)) FROM payments r WHERE r.original_payment_id = p.id AND r.payment_type = 'refund'
                       AND r.refund_status IN ('completed','processing','pending') AND r.deleted_at IS NULL), 0) AS already_refunded
            FROM payments p
            WHERE p.booking_type = 'room' AND p.booking_id = ? AND COALESCE(p.payment_type, '') <> 'refund'
              AND p.payment_status IN ('completed','paid','refunded','partially_refunded') AND p.deleted_at IS NULL
              AND p.id IN (SELECT l.payment_id FROM billing_remittance_lines l JOIN billing_remittances m ON m.id = l.remittance_id
                           WHERE l.booking_id = ? AND m.billing_account_id = ?)
            ORDER BY p.payment_date DESC, p.id DESC" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$bookingId, $bookingId, $accountId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $p['left'] = max(0.0, round((float)$p['total_amount'] - (float)$p['already_refunded'], 2));
            if ($p['left'] > (float)BALANCE_TOLERANCE) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * What the company's money on a room can give (same-company move). surplus = the company's part of the room's credit
     * (rh_billing_refund_overpaid with the bill as it stands: guest money above the bill stays the guest's), all = every
     * company payment still on it. Both capped at what its company payment rows can still give (a company credit-note
     * payment from an earlier move is not taken again here: LATER). $m: rh_gcm_figures().
     * @return array{surplus:float,all:float,rows:array}
     */
    function rh_gcm_company_give(PDO $pdo, int $bookingId, array $m, int $accountId, bool $lock): array
    {
        $rows = rh_gcm_company_rows($pdo, $bookingId, $accountId, $lock);
        $cLeft = round(array_sum(array_column($rows, 'left')), 2);
        $sp = rh_booking_bill_split($pdo, $bookingId);
        $cTotal = (float)$sp['company_paid'];
        $over = rh_billing_refund_overpaid($cTotal, max(0.0, round($m['paid'] - $cTotal, 2)), $m['billed'], (float)$sp['extras'], (string)$sp['covers']);
        $surplus = round(max(0.0, min($m['credit'], $over['company'], $cLeft)), 2);
        $all = round(max(0.0, min($cLeft, $cTotal)), 2);
        $tol = (float)BALANCE_TOLERANCE;
        return ['surplus' => $surplus <= $tol ? 0.0 : $surplus, 'all' => $all <= $tol ? 0.0 : $all, 'rows' => $rows,
            'guest_surplus' => $over['guest'] <= $tol ? 0.0 : round($over['guest'], 2)];
    }

    /** Preset refund legs for recordCreditNoteRefundLegs: $amount taken from $rows (rh_gcm_company_rows) in order. */
    function rh_gcm_allocs(array $rows, float $amount): array
    {
        $out = [];
        $left = round($amount, 2);
        foreach ($rows as $p) {
            if ($left <= (float)BALANCE_TOLERANCE) {
                break;
            }
            $n = round(min($left, (float)$p['left']), 2);
            if ($n > 0) {
                $out[] = ['payment' => $p, 'amount' => $n, 'payer' => 'company'];
                $left = round($left - $n, 2);
            }
        }
        return $out;
    }

    /** What the company owes on a room (rh_billing_split_figures company_due), never above what the room owes. */
    function rh_gcm_company_due(PDO $pdo, int $bookingId, float $owed): float
    {
        $due = round(min($owed, (float)rh_booking_bill_split($pdo, $bookingId)['company_due']), 2);
        return $due <= (float)BALANCE_TOLERANCE ? 0.0 : $due;
    }

    /** User id that may issue a credit note (issueCreditNote needs one): the staff member, else the first admin. */
    function rh_gcm_issuer(PDO $pdo, array $opts): int
    {
        $uid = (int)($opts['user_id'] ?? 0);
        return $uid > 0 ? $uid : (int)$pdo->query('SELECT MIN(id) FROM admin_users')->fetchColumn();
    }

    /** History line on one room (booking_timeline_logs, action_type 'payment'). */
    function rh_gcm_log(array $row, string $action, string $description, ?string $old, ?string $new, array $opts, array $meta): void
    {
        $uid = (int)($opts['user_id'] ?? 0);
        logBookingEvent((int)$row['id'], (string)($row['booking_reference'] ?? ''), $action, 'payment', $description, $old, $new,
            $uid > 0 ? 'admin' : 'system', $uid > 0 ? $uid : null, ($opts['user_name'] ?? '') !== '' ? (string)$opts['user_name'] : null, $meta ?: null);
    }

    /**
     * Issue ONE credit note on $from for $amount (its own payments give the money: credit-note refund legs) and apply it
     * to each receiving room. Inside the caller's transaction. Throws RhGroupMoneyRefused on any shortfall.
     * $to: booking id => amount. Returns ['id' => cn id, 'number' => cn number].
     * $payer (rh_gcm_payer) mode 'company' + $allocs (rh_gcm_allocs): only the company's payment rows give, the note is
     * made out to the company, and the company figures are checked before the caller commits (giver company paid -X,
     * each receiver +its share, company money received unchanged).
     */
    function rh_gcm_issue_and_apply(PDO $pdo, array $from, array $to, array $labels, string $why, array $opts, array $payer = [], ?array $allocs = null): array
    {
        $tol = (float)BALANCE_TOLERANCE;
        $amount = round(array_sum($to), 2);
        $fromId = (int)$from['id'];
        $fromRef = (string)$from['booking_reference'];
        $isCo = ($payer['mode'] ?? '') === 'company';
        if ($isCo && ($allocs === null || abs(round(array_sum(array_column($allocs, 'amount')), 2) - $amount) > $tol)) {
            throw new RhGroupMoneyRefused('Only part of ' . number_format($amount, 2) . ' is ' . ($payer['name'] ?? 'the company') . '\'s money on ' . $fromRef . '. Nothing was changed.');
        }
        $coBefore = [];
        $recvBefore = 0.0;
        if ($isCo) {
            foreach (array_merge([$fromId], array_map('intval', array_keys($to))) as $id) {
                $coBefore[$id] = rh_billing_company_paid($pdo, $id);
            }
            $recvBefore = rh_billing_received_net($pdo, date('Y-m-d'), (int)$payer['account_id']);
        }
        $vatOn = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
        $split = rh_account_vat_split($pdo, 'room', $fromId, $amount, $vatOn ? (float)getSetting('vat_rate') : 0.0);
        $guest = trim((string)($from['guest_name'] ?? ''));
        $parts = [];
        foreach ($to as $id => $amt) {
            $parts[] = ($labels[$id] ?? ('#' . $id)) . ' ' . number_format((float)$amt, 2);
        }
        $cn = issueCreditNote($pdo, [
            'booking_type' => 'room', 'booking_id' => $fromId, 'booking_reference' => $fromRef,
            // Company money: the note is made out to the company (its billing email), like the early-checkout split.
            'guest_name' => $isCo ? (string)$payer['name'] : ($guest !== '' ? $guest : 'Guest'),
            'guest_email' => $isCo ? (string)($payer['billing_email'] ?? '') : (string)($from['guest_email'] ?? ''),
            'amount' => $amount, 'vat_rate' => (float)$split['rate'], 'vat_amount' => (float)$split['vat'],
            'reason' => 'overpayment',
            'reason_notes' => mb_substr(($isCo ? 'Company money of ' . $payer['name'] . ' (guest: ' . ($guest !== '' ? $guest : 'Guest')
                . ') moved between rooms billed to ' . $payer['name'] . '. ' : '') . $why . ' Applied to: ' . implode('; ', $parts) . '.', 0, 1000),
            'issued_by' => rh_gcm_issuer($pdo, $opts), 'send_email' => false, 'generate_pdf' => false,
        ]);
        if (empty($cn['success'])) {
            throw new RhGroupMoneyRefused('The credit note could not be made (' . ($cn['error'] ?? 'unknown error') . '). Nothing was changed.');
        }
        $cnId = (int)$cn['credit_note_id'];
        $cnNo = (string)$cn['credit_note_number'];
        $uid = (int)($opts['user_id'] ?? 0);
        $legs = recordCreditNoteRefundLegs($pdo, $fromId, $fromRef, $amount, $cnId, $cnNo, $uid, 'overpayment', 'room', $isCo ? $allocs : null);
        if (abs((float)$legs['total'] - $amount) > $tol) {
            throw new RhGroupMoneyRefused('Only ' . number_format((float)$legs['total'], 2) . ' of ' . number_format($amount, 2)
                . ' could be taken from the payments on ' . $fromRef . '. Nothing was changed.');
        }
        if (!recalculateBookingFinancials($fromId)) {
            throw new RhGroupMoneyRefused('Could not update the balance of ' . $fromRef . '. Nothing was changed.');
        }
        foreach ($to as $id => $amt) {
            $id = (int)$id;
            $amt = round((float)$amt, 2);
            if ($amt <= $tol) {
                continue;
            }
            recalculateBookingFinancials($id); // applyCreditNote reads bookings.amount_due under its lock
            $ap = applyCreditNote($pdo, $cnId, ['booking_id' => $id, 'booking_type' => 'room'], $amt, $uid,
                'Credit note ' . $cnNo . ' from ' . $fromRef . ': ' . $why);
            if (empty($ap['success']) || abs((float)($ap['applied_amount'] ?? 0) - $amt) > $tol) {
                throw new RhGroupMoneyRefused('Could not use the credit on ' . ($labels[$id] ?? ('#' . $id)) . ' ('
                    . ($ap['error'] ?? ('only ' . number_format((float)($ap['applied_amount'] ?? 0), 2) . ' fits')) . '). Nothing was changed.');
            }
        }
        $left = $pdo->prepare('SELECT balance FROM credit_notes WHERE id = ?');
        $left->execute([$cnId]);
        if ((float)$left->fetchColumn() > $tol) {
            throw new RhGroupMoneyRefused('The credit note was not fully used. Nothing was changed.');
        }
        if ($isCo) {
            // The company's figures must move exactly with the money, or nothing is saved.
            $bad = abs(rh_billing_company_paid($pdo, $fromId) - round($coBefore[$fromId] - $amount, 2)) > $tol
                || abs(rh_billing_received_net($pdo, date('Y-m-d'), (int)$payer['account_id']) - $recvBefore) > $tol;
            foreach ($to as $id => $amt) {
                $bad = $bad || abs(rh_billing_company_paid($pdo, (int)$id) - round($coBefore[(int)$id] + round((float)$amt, 2), 2)) > $tol;
            }
            if ($bad) {
                throw new RhGroupMoneyRefused('The money of ' . $payer['name'] . ' did not add up after the move. Nothing was changed.');
            }
        }
        return ['id' => $cnId, 'number' => $cnNo];
    }

    /**
     * A5: use the credit on $sourceId for the other rooms of its group that owe.
     * $opts: user_id, user_name, dry_run (preview, nothing written or locked), expect_state (the preview's state).
     * @return array{success:bool,error:string,state:string,source:array,rooms:array,moved:float,left_over:float,
     *   owed_before:float,owed_after:float,credit_before:float,credit_after:float,net_before:float,net_after:float,credit_note:string}
     *   source: id,label,ref,credit,paid,credit_after,paid_after; rooms: id,label,ref,owed,apply,owed_after (owing rooms only).
     *   owed_* / credit_* / net_*: whole group (every room not deleted), net = owed - credit.
     */
    function rh_group_credit_move(PDO $pdo, int $sourceId, array $opts = []): array
    {
        $out = ['success' => false, 'error' => '', 'state' => '', 'source' => [], 'rooms' => [], 'moved' => 0.0, 'left_over' => 0.0,
            'owed_before' => 0.0, 'owed_after' => 0.0, 'credit_before' => 0.0, 'credit_after' => 0.0, 'net_before' => 0.0, 'net_after' => 0.0,
            'credit_note' => '', 'payer' => ['mode' => '', 'name' => ''], 'guest_credit' => 0.0];
        $dry = !empty($opts['dry_run']);
        $tol = (float)BALANCE_TOLERANCE;
        $work = static function () use ($pdo, $sourceId, $opts, $dry, $out, $tol): array {
            $root = $sourceId > 0 ? rh_group_ops_root($pdo, $sourceId) : 0;
            $rows = $root > 0 ? rh_group_ops_rows($pdo, $root, !$dry) : [];
            if (!isset($rows[$sourceId]) || count($rows) < 2) {
                $out['error'] = 'This booking is not in a group.';
                return $out;
            }
            if (!empty($rows[$sourceId]['deleted_at'])) {
                $out['error'] = 'This booking is deleted.';
                return $out;
            }
            $guests = $pdo->prepare('SELECT guest_email FROM bookings WHERE id = ?');
            $fig = [];
            $labels = [];
            foreach ($rows as $id => $r) {
                if (!empty($r['deleted_at'])) {
                    continue;
                }
                $fig[$id] = rh_gcm_figures($id);
                $labels[$id] = rh_group_ops_label($r);
                $out['owed_before'] += $fig[$id]['owed'];
                $out['credit_before'] += $fig[$id]['credit'];
            }
            $src = $fig[$sourceId];
            if ($src['credit'] <= $tol) {
                $out['error'] = 'This room has no credit to move (it has not paid more than its bill).';
                return $out;
            }
            $owing = [];
            foreach ($fig as $id => $f) {
                if ($id !== $sourceId && $f['owed'] > $tol && !rh_gcm_closed((string)$rows[$id]['status'])) {
                    $owing[$id] = $f['owed'];
                }
            }
            if (!$owing) {
                $out['error'] = 'No other open room of this group owes money, so there is nothing to use the credit for.';
                return $out;
            }
            $payer = rh_gcm_payer($pdo, array_merge([$sourceId], array_keys($owing)));
            if ($payer['error'] !== '') {
                $out['error'] = $payer['error'];
                return $out;
            }
            $out['payer'] = ['mode' => $payer['mode'], 'name' => $payer['name']];
            $isCo = $payer['mode'] === 'company';
            $give = null;
            $avail = $src['credit'];
            $takes = $owing; // what each room can take: what it owes, or (company) what the company owes on it
            if ($isCo) {
                $give = rh_gcm_company_give($pdo, $sourceId, $src, (int)$payer['account_id'], !$dry);
                if ($give['surplus'] <= $tol) {
                    $out['error'] = 'The credit on this room is the guest\'s own money (paid above what ' . $payer['name'] . ' owes here), so it cannot pay '
                        . $payer['name'] . '\'s bill on the other rooms. Refund it to the guest or issue the guest a credit note as usual.';
                    return $out;
                }
                $avail = $give['surplus'];
                foreach ($owing as $id => $owed) {
                    $takes[$id] = rh_gcm_company_due($pdo, $id, $owed);
                    if ($takes[$id] <= $tol) {
                        unset($takes[$id]);
                    }
                }
                if (!$takes) {
                    $out['error'] = $payer['name'] . ' owes nothing on the other open rooms of this group (what they owe is the guests\' own extras), so there is nothing to use its credit for.';
                    return $out;
                }
            }
            $amount = round(min($avail, array_sum($takes)), 2);
            $split = rh_group_pay_split($takes, $amount, (int)array_key_first($takes));
            $out['moved'] = $amount;
            $out['left_over'] = round($src['credit'] - $amount, 2);
            $out['guest_credit'] = $isCo ? round($src['credit'] - $give['surplus'], 2) : 0.0; // guest money that stays (company move)
            $out['source'] = ['id' => $sourceId, 'label' => $labels[$sourceId], 'ref' => (string)$rows[$sourceId]['booking_reference'],
                'credit' => $src['credit'], 'paid' => $src['paid'], 'credit_after' => $out['left_over'], 'paid_after' => round($src['paid'] - $amount, 2)];
            $stateBits = [$root, $sourceId, number_format($src['credit'], 2, '.', ''), number_format($src['paid'], 2, '.', ''),
                'payer:' . $payer['mode'] . ':' . (int)$payer['account_id'] . ':' . number_format($avail, 2, '.', '')];
            foreach ($takes as $id => $take) {
                $owed = $owing[$id];
                $sh = (float)($split['shares'][$id] ?? 0);
                $out['rooms'][] = ['id' => $id, 'label' => $labels[$id], 'ref' => (string)$rows[$id]['booking_reference'],
                    'owed' => $owed, 'company_due' => $isCo ? $take : 0.0, 'apply' => $sh, 'owed_after' => round($owed - $sh, 2)];
                $stateBits[] = $id . ':' . number_format($owed, 2, '.', '') . ':' . number_format($take, 2, '.', '');
            }
            $out['owed_before'] = round($out['owed_before'], 2);
            $out['credit_before'] = round($out['credit_before'], 2);
            $out['net_before'] = round($out['owed_before'] - $out['credit_before'], 2);
            $out['owed_after'] = round($out['owed_before'] - $amount, 2);
            $out['credit_after'] = round($out['credit_before'] - $amount, 2);
            $out['net_after'] = round($out['owed_after'] - $out['credit_after'], 2);
            $out['state'] = sha1('a5|' . implode('|', $stateBits));
            if (($opts['expect_state'] ?? '') !== '' && !hash_equals((string)$opts['expect_state'], $out['state'])) {
                $out['error'] = 'The rooms\' figures changed since you reviewed them (a payment or charge was recorded). Close this and review again. Nothing was changed.';
                return $out;
            }
            if ($dry) {
                $out['success'] = true;
                return $out;
            }
            if (($opts['expect_state'] ?? '') === '') {
                $out['error'] = 'Review the move first.';
                return $out;
            }

            $from = $rows[$sourceId];
            $guests->execute([$sourceId]);
            $from['guest_email'] = (string)$guests->fetchColumn();
            $to = [];
            foreach ($out['rooms'] as $r) {
                if ($r['apply'] > $tol) {
                    $to[$r['id']] = $r['apply'];
                }
            }
            $cn = rh_gcm_issue_and_apply($pdo, $from, $to, $labels, 'Credit on ' . $out['source']['ref'] . ' used for rooms of the same group that owe.', $opts,
                $payer, $isCo ? rh_gcm_allocs($give['rows'], $amount) : null);
            $out['credit_note'] = $cn['number'];
            $coText = $isCo ? ' This is company money of ' . $payer['name'] . '.' : '';

            // The rooms and the group must add up exactly as reviewed.
            $after = rh_gcm_figures($sourceId);
            if (abs($after['credit'] - $out['left_over']) > $tol || abs($after['paid'] - $out['source']['paid_after']) > $tol) {
                throw new RhGroupMoneyRefused('The credit left on ' . $out['source']['ref'] . ' did not come out as reviewed. Nothing was changed.');
            }
            foreach ($out['rooms'] as $r) {
                if (abs(rh_gcm_figures((int)$r['id'])['owed'] - $r['owed_after']) > $tol) {
                    throw new RhGroupMoneyRefused('What ' . $r['ref'] . ' owes did not come out as reviewed. Nothing was changed.');
                }
            }
            $cur = (string)getSetting('currency_symbol', 'MWK') . ' ';
            $meta = ['group_root' => $root, 'credit_note' => $cn['number'], 'credit_note_id' => $cn['id'], 'moved' => $amount, 'to' => $to,
                'payer' => $isCo ? 'company' : 'guest'] + ($isCo ? ['billing_account_id' => (int)$payer['account_id'], 'company' => $payer['name']] : []);
            $toText = [];
            foreach ($out['rooms'] as $r) {
                if ($r['apply'] > $tol) {
                    $toText[] = $r['ref'] . ' ' . $cur . number_format($r['apply'], 2);
                    rh_gcm_log($rows[$r['id']], 'Credit received from another room',
                        $cur . number_format($r['apply'], 2) . ' of the credit on ' . $out['source']['label'] . ' (same group) paid this room, by credit note '
                        . $cn['number'] . '. Owed ' . $cur . number_format($r['owed'], 2) . ' -> ' . $cur . number_format($r['owed_after'], 2) . '.'
                        . ($isCo ? $coText . ' ' . $payer['name'] . ' owed ' . $cur . number_format($r['company_due'], 2) . ' here before.' : ''),
                        number_format($r['owed'], 2, '.', ''), number_format($r['owed_after'], 2, '.', ''), $opts, $meta + ['from' => $sourceId]);
                }
            }
            rh_gcm_log($from, 'Credit moved to rooms that owe',
                $cur . number_format($amount, 2) . ' of this room\'s credit moved to credit note ' . $cn['number'] . ' and used for: ' . implode(', ', $toText)
                . '. Credit ' . $cur . number_format($out['source']['credit'], 2) . ' -> ' . $cur . number_format($out['left_over'], 2)
                . '. Group net position unchanged (' . $cur . number_format($out['net_before'], 2) . ').' . $coText
                . ($out['guest_credit'] > $tol ? ' ' . $cur . number_format($out['guest_credit'], 2) . ' of the credit is the guest\'s own money and stays here.' : ''),
                number_format($out['source']['credit'], 2, '.', ''), number_format($out['left_over'], 2, '.', ''), $opts, $meta);
            $out['success'] = true;
            return $out;
        };
        if ($dry) {
            return $work();
        }
        $res = rh_group_ops_tx($pdo, $work);
        if (function_exists('rh_payment_audit_flush')) {
            rh_payment_audit_flush($pdo); // payment History rows (no-op while a caller's transaction is still open)
        }
        return $res;
    }

    /**
     * D-R6 review: move $amount of the group's money to $leavingId. Source rooms = the other rooms of the group rooted at
     * $groupRoot (not deleted, paid above 0). Before the detach $groupRoot is the room's own root; inside after_detach it
     * is rh_group_remove's new_root: the same rooms either way, so the state token matches.
     * Most that can move = min(what the leaving room owes, what the sources have paid); a closed leaving room takes 0.
     * Taken from the sources' credit first (pro rata to credit), then pro rata to what each has paid.
     * @return array{ok:bool,error:string,amount:float,max:float,state:string,leaving:array,sources:array,
     *   group_paid_before:float,group_paid_after:float}
     */
    function rh_group_lump_quote(PDO $pdo, int $leavingId, int $groupRoot, float $amount): array
    {
        $tol = (float)BALANCE_TOLERANCE;
        $out = ['ok' => false, 'error' => '', 'amount' => round(max(0.0, $amount), 2), 'max' => 0.0, 'state' => '', 'leaving' => [], 'sources' => [],
            'group_paid_before' => 0.0, 'group_paid_after' => 0.0, 'note' => '', 'payer' => ['mode' => '', 'name' => '']];
        $st = $pdo->prepare('SELECT id, booking_reference, guest_name, room_guest_name, status, deleted_at, COALESCE((SELECT r.name FROM rooms r WHERE r.id = b.room_id), \'\') AS room_name FROM bookings b WHERE id = ?');
        $st->execute([$leavingId]);
        $lr = $st->fetch(PDO::FETCH_ASSOC);
        if (!$lr) {
            $out['error'] = 'Booking not found.';
            return $out;
        }
        if ($amount < -$tol) {
            $out['error'] = 'Enter an amount of 0 or more.';
            return $out;
        }
        $lf = rh_gcm_figures($leavingId);
        $out['leaving'] = ['id' => $leavingId, 'label' => rh_group_ops_label($lr), 'ref' => (string)$lr['booking_reference'],
            'paid' => $lf['paid'], 'owed' => $lf['owed']];
        $rows = $groupRoot > 0 ? rh_group_ops_rows($pdo, $groupRoot) : [];
        $paid = [];
        $credit = [];
        $bits = [$leavingId, number_format($lf['owed'], 2, '.', ''), number_format($lf['paid'], 2, '.', '')];
        $labels = [];
        foreach ($rows as $id => $r) {
            if ($id === $leavingId || !empty($r['deleted_at'])) {
                continue;
            }
            $f = rh_gcm_figures($id);
            $out['group_paid_before'] += $f['paid'];
            // A cancelled / no-show / expired room gives only its credit: what it kept is earned by its policy and must not
            // turn into a debt nobody is asked for.
            $avail = rh_gcm_closed((string)$r['status']) ? $f['credit'] : $f['paid'];
            if ($avail > $tol) {
                $paid[$id] = $avail;
                $credit[$id] = $f['credit'];
                $labels[$id] = rh_group_ops_label($r);
                $bits[] = $id . ':' . (string)$r['status'] . ':' . number_format($f['paid'], 2, '.', '') . ':' . number_format($f['billed'], 2, '.', '');
                $out['sources'][$id] = ['id' => $id, 'label' => $labels[$id], 'ref' => (string)$r['booking_reference'],
                    'paid' => $f['paid'], 'owed' => $f['owed'], 'credit' => $f['credit'], 'can_give' => $avail, 'take' => 0.0, 'status' => (string)$r['status']];
            }
        }
        $out['group_paid_before'] = round($out['group_paid_before'], 2);
        $closed = rh_gcm_closed((string)$lr['status']);
        // Same-payer rule (Ship C2b). The leaving room keeps its payer through the removal (rh_group_remove copies an
        // inherited company onto it before after_detach), so this answers the same before and after the detach.
        $payer = $paid ? rh_gcm_payer($pdo, array_merge([$leavingId], array_keys($paid))) : ['mode' => 'guest', 'account_id' => 0, 'name' => '', 'error' => ''];
        $out['payer'] = ['mode' => $payer['mode'], 'name' => $payer['name'], 'account_id' => (int)$payer['account_id'], 'billing_email' => (string)($payer['billing_email'] ?? '')];
        $owedTake = $lf['owed'];
        if ($payer['mode'] === 'company') {
            // Only the company's money moves, and only up to what the company owes on the leaving room.
            $owedTake = rh_gcm_company_due($pdo, $leavingId, $lf['owed']);
            $out['leaving']['company_due'] = $owedTake;
            $lock = $pdo->inTransaction();
            foreach (array_keys($paid) as $id) {
                $f = rh_gcm_figures($id);
                $give = rh_gcm_company_give($pdo, $id, $f, (int)$payer['account_id'], $lock);
                // A room that also holds the guest's own money above its bill gives only the company's surplus: taking
                // more would leave the guest's money covering the company's bill there.
                $cAvail = (rh_gcm_closed((string)$out['sources'][$id]['status']) || $give['guest_surplus'] > $tol) ? $give['surplus'] : $give['all'];
                if ($cAvail <= $tol) {
                    unset($paid[$id], $credit[$id], $out['sources'][$id]);
                    continue;
                }
                $paid[$id] = $cAvail;
                $credit[$id] = min($give['surplus'], $cAvail);
                $out['sources'][$id]['can_give'] = $cAvail;
                $bits[] = $id . ':co:' . number_format($cAvail, 2, '.', '') . ':' . number_format($credit[$id], 2, '.', '');
            }
        }
        $bits[] = 'payer:' . $payer['mode'] . ':' . (int)$payer['account_id'] . ':' . number_format($owedTake, 2, '.', '');
        $out['max'] = ($closed || $payer['error'] !== '') ? 0.0 : round(max(0.0, min($owedTake, array_sum($paid))), 2);
        if ($closed) {
            $out['note'] = 'This room is ' . $lr['status'] . ': a credit note cannot be used on it, so no group money can move to it.';
        } elseif ($payer['error'] !== '') {
            $out['note'] = $payer['error'];
        } elseif ($lf['owed'] <= $tol) {
            $out['note'] = 'This room owes nothing, so no group money needs to move to it.';
        } elseif ($payer['mode'] === 'company' && $owedTake <= $tol) {
            $out['note'] = $payer['name'] . ' owes nothing on this room (what it owes is the guest\'s own extras), so no company money moves to it.';
        } elseif (!$paid) {
            $out['note'] = $payer['mode'] === 'company' ? 'No other room of the group holds ' . $payer['name'] . '\'s money that could move.'
                : 'No other room of the group has paid anything that could move.';
        }
        $bits[] = number_format($out['amount'], 2, '.', '');
        $out['state'] = sha1('dr6|' . implode('|', $bits));
        $a = $out['amount'];
        if ($a > $tol) {
            if ($payer['error'] !== '') {
                $out['error'] = $payer['error'];
                return $out;
            }
            if ($a > $out['max'] + $tol) {
                $out['error'] = 'At most ' . number_format($out['max'], 2) . ' can move to this room (what '
                    . ($payer['mode'] === 'company' ? $payer['name'] . ' owes on it, and no more than ' . $payer['name'] . ' has paid on the other rooms'
                        : 'it owes, and no more than the other rooms have paid') . '; a cancelled or no-show room gives only its credit).';
                return $out;
            }
            $first = (int)array_key_first($paid);
            $fromCredit = round(min($a, array_sum($credit)), 2);
            $s1 = $fromCredit > $tol ? rh_group_pay_split($credit, $fromCredit, $first)['shares'] : [];
            $rest = round($a - $fromCredit, 2);
            $left = [];
            foreach ($paid as $id => $p) {
                $left[$id] = round($p - (float)($s1[$id] ?? 0), 2);
            }
            $s2 = $rest > $tol ? rh_group_pay_split($left, $rest, $first)['shares'] : [];
            foreach ($out['sources'] as $id => &$s) {
                $s['take'] = round((float)($s1[$id] ?? 0) + (float)($s2[$id] ?? 0), 2);
            }
            unset($s);
            if (abs(array_sum(array_column($out['sources'], 'take')) - $a) > 0.001) {
                $out['error'] = 'The amount could not be split across the rooms exactly. Nothing was changed.';
                return $out;
            }
        }
        foreach ($out['sources'] as &$s) {
            $s['paid_after'] = round($s['paid'] - $s['take'], 2);
            $s['owed_after'] = round($s['owed'] + max(0.0, $s['take'] - $s['credit']), 2);
        }
        unset($s);
        $out['sources'] = array_values($out['sources']);
        $out['leaving']['paid_after'] = round($lf['paid'] + $a, 2);
        $out['leaving']['owed_after'] = round($lf['owed'] - $a, 2);
        $out['group_paid_after'] = round($out['group_paid_before'] - $a, 2);
        $out['ok'] = true;
        return $out;
    }

    /**
     * D-R6 confirm, for rh_group_remove()'s after_detach hook (inside its transaction, after the room is detached).
     * Re-quotes, refuses when the reviewed state changed, then one credit note per giving room applied to the leaving room.
     * Throws RhGroupMoneyRefused (the whole removal rolls back). Returns the quote plus 'credit_notes' => [numbers].
     */
    function rh_group_lump_apply(PDO $pdo, int $leavingId, int $groupRoot, float $amount, string $expectState, array $opts = []): array
    {
        $tol = (float)BALANCE_TOLERANCE;
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('The group money move must run inside the removal\'s transaction.');
        }
        $q = rh_group_lump_quote($pdo, $leavingId, $groupRoot, $amount);
        if (!$q['ok']) {
            throw new RhGroupMoneyRefused($q['error'] . ' Nothing was changed.');
        }
        if ($expectState === '' || !hash_equals($expectState, $q['state'])) {
            throw new RhGroupMoneyRefused('The rooms\' figures changed since you reviewed them. Close this and review again. Nothing was changed.');
        }
        $q['credit_notes'] = [];
        if ($q['amount'] <= $tol) {
            return $q;
        }
        $rowSt = $pdo->prepare('SELECT b.id, b.booking_reference, b.guest_name, b.guest_email, b.room_guest_name, COALESCE((SELECT r.name FROM rooms r WHERE r.id = b.room_id), \'\') AS room_name FROM bookings b WHERE b.id = ?');
        $rowSt->execute([$leavingId]);
        $leaving = $rowSt->fetch(PDO::FETCH_ASSOC);
        $cur = (string)getSetting('currency_symbol', 'MWK') . ' ';
        $labels = [$leavingId => $q['leaving']['label']];
        $payer = $q['payer'];
        $isCo = $payer['mode'] === 'company';
        $coText = $isCo ? ' This is company money of ' . $payer['name'] . '.' : '';
        foreach ($q['sources'] as $s) {
            if ($s['take'] <= $tol) {
                continue;
            }
            $rowSt->execute([(int)$s['id']]);
            $from = $rowSt->fetch(PDO::FETCH_ASSOC);
            $allocs = null;
            if ($isCo) {
                // Earlier sources already moved inside this transaction, but each room only gives its own payment rows.
                $give = rh_gcm_company_give($pdo, (int)$s['id'], rh_gcm_figures((int)$s['id']), (int)$payer['account_id'], true);
                $allocs = rh_gcm_allocs($give['rows'], $s['take']);
            }
            $cn = rh_gcm_issue_and_apply($pdo, $from, [$leavingId => $s['take']], $labels,
                'Part of the group payment moved to ' . $q['leaving']['ref'] . ', which left the group.', $opts, $payer, $allocs);
            $q['credit_notes'][] = $cn['number'];
            $meta = ['credit_note' => $cn['number'], 'credit_note_id' => $cn['id'], 'moved' => $s['take'], 'to' => $leavingId, 'group_root' => $groupRoot,
                'payer' => $isCo ? 'company' : 'guest'] + ($isCo ? ['billing_account_id' => (int)$payer['account_id'], 'company' => $payer['name']] : []);
            rh_gcm_log($from, 'Group payment moved to a room that left',
                $cur . number_format($s['take'], 2) . ' of this room\'s payments moved by credit note ' . $cn['number'] . ' to ' . $q['leaving']['label']
                . ', which was taken out of the group. Paid ' . $cur . number_format($s['paid'], 2) . ' -> ' . $cur . number_format($s['paid_after'], 2)
                . ', owed ' . $cur . number_format($s['owed'], 2) . ' -> ' . $cur . number_format($s['owed_after'], 2) . '.' . $coText,
                number_format($s['paid'], 2, '.', ''), number_format($s['paid_after'], 2, '.', ''), $opts, $meta);
            rh_gcm_log($leaving, 'Group payment received on leaving the group',
                $cur . number_format($s['take'], 2) . ' of the group payment on ' . $s['label'] . ' moved to this room by credit note ' . $cn['number'] . '.' . $coText,
                null, number_format($s['take'], 2, '.', ''), $opts, $meta + ['from' => (int)$s['id']]);
        }
        // Rooms and group add up exactly as reviewed.
        $lf = rh_gcm_figures($leavingId);
        if (abs($lf['paid'] - $q['leaving']['paid_after']) > $tol || abs($lf['owed'] - $q['leaving']['owed_after']) > $tol) {
            throw new RhGroupMoneyRefused('The leaving room\'s figures did not come out as reviewed. Nothing was changed.');
        }
        foreach ($q['sources'] as $s) {
            $f = rh_gcm_figures((int)$s['id']);
            if (abs($f['paid'] - $s['paid_after']) > $tol || abs($f['owed'] - $s['owed_after']) > $tol) {
                throw new RhGroupMoneyRefused('The figures of ' . $s['ref'] . ' did not come out as reviewed. Nothing was changed.');
            }
        }
        return $q;
    }
}
