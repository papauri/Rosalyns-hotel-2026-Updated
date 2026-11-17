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
 *  - Any room involved billed to a company account is refused (company money goes through Company accounts), the same
 *    way a whole-group payment is refused.
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

    /** '' when every room pays as a guest, else the refusal (a company account is involved). */
    function rh_gcm_payer_error(PDO $pdo, array $ids): string
    {
        $company = rh_group_pay_on_account($pdo, array_values(array_unique(array_map('intval', $ids))));
        return $company === '' ? '' : 'Not moved: a room here is billed to ' . $company . '. Money paid by a company is moved through '
            . 'Company accounts, never between rooms here, so a guest\'s money and a company\'s money are never mixed.';
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
     */
    function rh_gcm_issue_and_apply(PDO $pdo, array $from, array $to, array $labels, string $why, array $opts): array
    {
        $tol = (float)BALANCE_TOLERANCE;
        $amount = round(array_sum($to), 2);
        $fromId = (int)$from['id'];
        $fromRef = (string)$from['booking_reference'];
        $vatOn = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
        $split = rh_account_vat_split($pdo, 'room', $fromId, $amount, $vatOn ? (float)getSetting('vat_rate') : 0.0);
        $guest = trim((string)($from['guest_name'] ?? ''));
        $parts = [];
        foreach ($to as $id => $amt) {
            $parts[] = ($labels[$id] ?? ('#' . $id)) . ' ' . number_format((float)$amt, 2);
        }
        $cn = issueCreditNote($pdo, [
            'booking_type' => 'room', 'booking_id' => $fromId, 'booking_reference' => $fromRef,
            'guest_name' => $guest !== '' ? $guest : 'Guest', 'guest_email' => (string)($from['guest_email'] ?? ''),
            'amount' => $amount, 'vat_rate' => (float)$split['rate'], 'vat_amount' => (float)$split['vat'],
            'reason' => 'overpayment',
            'reason_notes' => mb_substr($why . ' Applied to: ' . implode('; ', $parts) . '.', 0, 1000),
            'issued_by' => rh_gcm_issuer($pdo, $opts), 'send_email' => false, 'generate_pdf' => false,
        ]);
        if (empty($cn['success'])) {
            throw new RhGroupMoneyRefused('The credit note could not be made (' . ($cn['error'] ?? 'unknown error') . '). Nothing was changed.');
        }
        $cnId = (int)$cn['credit_note_id'];
        $cnNo = (string)$cn['credit_note_number'];
        $uid = (int)($opts['user_id'] ?? 0);
        $legs = recordCreditNoteRefundLegs($pdo, $fromId, $fromRef, $amount, $cnId, $cnNo, $uid, 'overpayment', 'room');
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
            'credit_note' => ''];
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
            $payErr = rh_gcm_payer_error($pdo, array_merge([$sourceId], array_keys($owing)));
            if ($payErr !== '') {
                $out['error'] = $payErr;
                return $out;
            }
            $amount = round(min($src['credit'], array_sum($owing)), 2);
            $split = rh_group_pay_split($owing, $amount, (int)array_key_first($owing));
            $out['moved'] = $amount;
            $out['left_over'] = round($src['credit'] - $amount, 2);
            $out['source'] = ['id' => $sourceId, 'label' => $labels[$sourceId], 'ref' => (string)$rows[$sourceId]['booking_reference'],
                'credit' => $src['credit'], 'paid' => $src['paid'], 'credit_after' => $out['left_over'], 'paid_after' => round($src['paid'] - $amount, 2)];
            $stateBits = [$root, $sourceId, number_format($src['credit'], 2, '.', ''), number_format($src['paid'], 2, '.', '')];
            foreach ($owing as $id => $owed) {
                $sh = (float)($split['shares'][$id] ?? 0);
                $out['rooms'][] = ['id' => $id, 'label' => $labels[$id], 'ref' => (string)$rows[$id]['booking_reference'],
                    'owed' => $owed, 'apply' => $sh, 'owed_after' => round($owed - $sh, 2)];
                $stateBits[] = $id . ':' . number_format($owed, 2, '.', '');
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
            $cn = rh_gcm_issue_and_apply($pdo, $from, $to, $labels, 'Credit on ' . $out['source']['ref'] . ' used for rooms of the same group that owe.', $opts);
            $out['credit_note'] = $cn['number'];

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
            $meta = ['group_root' => $root, 'credit_note' => $cn['number'], 'credit_note_id' => $cn['id'], 'moved' => $amount, 'to' => $to];
            $toText = [];
            foreach ($out['rooms'] as $r) {
                if ($r['apply'] > $tol) {
                    $toText[] = $r['ref'] . ' ' . $cur . number_format($r['apply'], 2);
                    rh_gcm_log($rows[$r['id']], 'Credit received from another room',
                        $cur . number_format($r['apply'], 2) . ' of the credit on ' . $out['source']['label'] . ' (same group) paid this room, by credit note '
                        . $cn['number'] . '. Owed ' . $cur . number_format($r['owed'], 2) . ' -> ' . $cur . number_format($r['owed_after'], 2) . '.',
                        number_format($r['owed'], 2, '.', ''), number_format($r['owed_after'], 2, '.', ''), $opts, $meta + ['from' => $sourceId]);
                }
            }
            rh_gcm_log($from, 'Credit moved to rooms that owe',
                $cur . number_format($amount, 2) . ' of this room\'s credit moved to credit note ' . $cn['number'] . ' and used for: ' . implode(', ', $toText)
                . '. Credit ' . $cur . number_format($out['source']['credit'], 2) . ' -> ' . $cur . number_format($out['left_over'], 2)
                . '. Group net position unchanged (' . $cur . number_format($out['net_before'], 2) . ').',
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
            'group_paid_before' => 0.0, 'group_paid_after' => 0.0, 'note' => ''];
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
                    'paid' => $f['paid'], 'owed' => $f['owed'], 'credit' => $f['credit'], 'can_give' => $avail, 'take' => 0.0];
            }
        }
        $out['group_paid_before'] = round($out['group_paid_before'], 2);
        $closed = rh_gcm_closed((string)$lr['status']);
        $out['max'] = $closed ? 0.0 : round(max(0.0, min($lf['owed'], array_sum($paid))), 2);
        if ($closed) {
            $out['note'] = 'This room is ' . $lr['status'] . ': a credit note cannot be used on it, so no group money can move to it.';
        } elseif ($lf['owed'] <= $tol) {
            $out['note'] = 'This room owes nothing, so no group money needs to move to it.';
        } elseif (!$paid) {
            $out['note'] = 'No other room of the group has paid anything that could move.';
        }
        $bits[] = number_format($out['amount'], 2, '.', '');
        $out['state'] = sha1('dr6|' . implode('|', $bits));
        $a = $out['amount'];
        if ($a > $tol) {
            if ($a > $out['max'] + $tol) {
                $out['error'] = 'At most ' . number_format($out['max'], 2) . ' can move to this room (what it owes, and no more than the other rooms have paid; a cancelled or no-show room gives only its credit).';
                return $out;
            }
            $payErr = rh_gcm_payer_error($pdo, array_merge([$leavingId], array_keys($paid)));
            if ($payErr !== '') {
                $out['error'] = $payErr;
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
        foreach ($q['sources'] as $s) {
            if ($s['take'] <= $tol) {
                continue;
            }
            $rowSt->execute([(int)$s['id']]);
            $from = $rowSt->fetch(PDO::FETCH_ASSOC);
            $cn = rh_gcm_issue_and_apply($pdo, $from, [$leavingId => $s['take']], $labels,
                'Part of the group payment moved to ' . $q['leaving']['ref'] . ', which left the group.', $opts);
            $q['credit_notes'][] = $cn['number'];
            $meta = ['credit_note' => $cn['number'], 'credit_note_id' => $cn['id'], 'moved' => $s['take'], 'to' => $leavingId, 'group_root' => $groupRoot];
            rh_gcm_log($from, 'Group payment moved to a room that left',
                $cur . number_format($s['take'], 2) . ' of this room\'s payments moved by credit note ' . $cn['number'] . ' to ' . $q['leaving']['label']
                . ', which was taken out of the group. Paid ' . $cur . number_format($s['paid'], 2) . ' -> ' . $cur . number_format($s['paid_after'], 2)
                . ', owed ' . $cur . number_format($s['owed'], 2) . ' -> ' . $cur . number_format($s['owed_after'], 2) . '.',
                number_format($s['paid'], 2, '.', ''), number_format($s['paid_after'], 2, '.', ''), $opts, $meta);
            rh_gcm_log($leaving, 'Group payment received on leaving the group',
                $cur . number_format($s['take'], 2) . ' of the group payment on ' . $s['label'] . ' moved to this room by credit note ' . $cn['number'] . '.',
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
