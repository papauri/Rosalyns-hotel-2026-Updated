<?php
/**
 * Whole-group payment (owner 2026-11-13): Record payment on a room that belongs to a group can split one amount
 * across the group's rooms, one payments row + one receipt per room. Used by admin/payment-add.php (preview JSON
 * and the POST) and its test scripts/tests/group_record_payment.php.
 *
 * Rules (settled for this feature):
 *  - Group = bookings.primary_booking_id (the lead and every room pointing at it), the same link the shipped group
 *    helpers use. Rooms that count: not deleted, not cancelled, not expired. A no-show room counts (a no-show pays
 *    100%), and so does a checked-out room; both only take money while they still owe. rh_booking_group_members()
 *    is NOT used here because it leaves no-show and checked-out rooms out (cancel/delete rely on that).
 *    A cancelled room counts while it still owes (owner 2026-11-14: its kept fee / extras, the Still owed rule:
 *    rh_group_pay_owing_cancelled_sql), so paying the group invoice's balance due settles it too.
 *  - Split basis = each room's own balance due. Rooms paid in full get nothing; no room is ever taken above its
 *    balance. Shares are proportional to the balance, rounded down to the cent; the cents left over go to the room
 *    with the most balance left (spilling to the next only if that room cannot take them), so the total is exact.
 *  - More than the whole group owes = today's overpayment rule applied ONCE: the explicit tick is needed, every room is
 *    paid to its balance and the extra goes to one room (the group's lead when it counts, else the room the payment
 *    was opened from; never a cancelled room while the group has another), which issues the one credit note.
 *  - Money received only (status completed / paid). Company-billed (on-account) groups are refused: company money
 *    goes through Company accounts.
 */

require_once __DIR__ . '/finance-account-sync.php';
require_once __DIR__ . '/../../includes/finance-sequences.php';
require_once __DIR__ . '/receivables.php';

if (!function_exists('rh_group_pay_owing_cancelled_sql')) {
    /**
     * SQL condition (no leading AND) for a CANCELLED room that still owes (owner 2026-11-14): counted as owed by the one
     * receivables rule (rh_receivable_status_sql: its kept fee or extras left posted) with amount_due above
     * BALANCE_TOLERANCE. $alias: the bookings table alias.
     */
    function rh_group_pay_owing_cancelled_sql(string $alias): string
    {
        $tol = (float)BALANCE_TOLERANCE;
        return "({$alias}.status = 'cancelled' AND {$alias}.amount_due > {$tol} AND " . rh_receivable_status_sql('room', $alias) . ')';
    }
}

if (!function_exists('rh_group_pay_rooms')) {
    /**
     * The rooms of $bookingId's group that can take a group payment (see the rules above), id order, each with its
     * balance due. $live = true: the balance is re-derived and written (rh_account_balance_due) - call it inside the
     * transaction after the rows are locked. $live = false: read-only (folio summary), for the preview.
     *
     * @return array<int,array{id:int,booking_reference:string,guest_name:string,status:string,room:string,owed:float}>
     */
    function rh_group_pay_rooms(PDO $pdo, int $bookingId, bool $live = false): array
    {
        if ($bookingId <= 0) {
            return [];
        }
        $st = $pdo->prepare('SELECT COALESCE(NULLIF(primary_booking_id, 0), id) FROM bookings WHERE id = ?');
        $st->execute([$bookingId]);
        $root = (int)$st->fetchColumn();
        if ($root <= 0) {
            return [];
        }
        $q = $pdo->prepare("
            SELECT b.id, b.booking_reference, b.guest_name, b.status, COALESCE(r.name, '') AS room_type
            FROM bookings b
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE (b.id = ? OR b.primary_booking_id = ?)
              AND b.deleted_at IS NULL
              AND (b.status NOT IN ('cancelled', 'expired') OR " . rh_group_pay_owing_cancelled_sql('b') . ")
            ORDER BY b.id ASC
        ");
        $q->execute([$root, $root]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            if ($live) {
                $owed = rh_account_balance_due($pdo, 'room', $id);
            } else {
                $fs = function_exists('getBookingFolioSummary') ? getBookingFolioSummary($id) : [];
                $owed = max(0.0, round((float)($fs['balance_due'] ?? 0), 2));
            }
            $out[] = [
                'id'                => $id,
                'booking_reference' => (string)$r['booking_reference'],
                'guest_name'        => (string)$r['guest_name'],
                'status'            => (string)$r['status'],
                'room'              => function_exists('getBookingRoomLabel') ? getBookingRoomLabel($id, (string)$r['room_type']) : (string)$r['room_type'],
                'owed'              => $owed,
            ];
        }
        return $out;
    }
}

if (!function_exists('rh_group_pay_counts')) {
    /**
     * For each booking id: how many rooms of its group count for a group payment (same rule as rh_group_pay_rooms).
     * One cheap query, so screens can render "is this a group?" without the per-room balance work; 1 = single booking.
     * @return array<int,int>
     */
    function rh_group_pay_counts(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if (!$ids) {
            return [];
        }
        $out = array_fill_keys($ids, 1);
        try {
            $st = $pdo->prepare("
                SELECT b.id,
                       (SELECT COUNT(*) FROM bookings g
                         WHERE (g.id = COALESCE(NULLIF(b.primary_booking_id, 0), b.id) OR g.primary_booking_id = COALESCE(NULLIF(b.primary_booking_id, 0), b.id))
                           AND g.deleted_at IS NULL AND (g.status NOT IN ('cancelled', 'expired') OR " . rh_group_pay_owing_cancelled_sql('g') . ")) AS n
                FROM bookings b
                WHERE b.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
            ");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['id']] = max(1, (int)$r['n']);
            }
        } catch (Throwable $e) {
            error_log('rh_group_pay_counts: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('rh_group_pay_company_reason')) {
    /** The plain-words reason a company-billed group cannot take a whole-group payment here. */
    function rh_group_pay_company_reason(string $company): string
    {
        return 'Company-billed group (' . $company . '): record the company\'s payment on the company account (Company accounts). '
            . 'Here you can record this room only.';
    }
}

if (!function_exists('rh_group_pay_split')) {
    /**
     * Split $amount across rooms by their balance (see the rules above). $owedById: booking id => balance due.
     * $excessRoomId: the room that takes anything above the whole group's balance (must be a key of $owedById).
     *
     * @return array{shares:array<int,float>,group_owed:float,excess:float}
     */
    function rh_group_pay_split(array $owedById, float $amount, int $excessRoomId): array
    {
        $owedC = [];
        foreach ($owedById as $id => $owed) {
            $c = (int)round(((float)$owed) * 100);
            $owedC[(int)$id] = $c > (int)round(BALANCE_TOLERANCE * 100) ? $c : 0;
        }
        $totalC = array_sum($owedC);
        $amtC = (int)round($amount * 100);
        $sharesC = array_fill_keys(array_keys($owedC), 0);
        $excessC = 0;
        if ($amtC <= 0 || $totalC <= 0) {
            $excessC = max(0, $amtC);
        } elseif ($amtC >= $totalC) {
            $sharesC = $owedC;
            $excessC = $amtC - $totalC;
        } else {
            $ratio = $amtC / $totalC;
            foreach ($owedC as $id => $c) {
                $sharesC[$id] = min($c, (int)floor($ratio * $c));
            }
            $left = $amtC - array_sum($sharesC);
            // Floating point can overshoot by a cent on huge figures: take it back from the largest shares.
            while ($left < 0) {
                arsort($sharesC);
                foreach ($sharesC as $id => $s) {
                    if ($left >= 0) {
                        break;
                    }
                    if ($s > 0) {
                        $sharesC[$id]--;
                        $left++;
                    }
                }
            }
            if ($left > 0) {
                // The cents left over go to the room with the most balance left (then the next, only if needed).
                $order = array_keys($owedC);
                usort($order, static function ($a, $b) use ($owedC, $sharesC) {
                    $ha = $owedC[$a] - $sharesC[$a];
                    $hb = $owedC[$b] - $sharesC[$b];
                    return $hb <=> $ha ?: ($owedC[$b] <=> $owedC[$a] ?: $a <=> $b);
                });
                foreach ($order as $id) {
                    $take = min($left, $owedC[$id] - $sharesC[$id]);
                    if ($take > 0) {
                        $sharesC[$id] += $take;
                        $left -= $take;
                    }
                    if ($left <= 0) {
                        break;
                    }
                }
            }
        }
        if ($excessC > 0) {
            $sharesC[$excessRoomId] = ($sharesC[$excessRoomId] ?? 0) + $excessC;
        }
        $shares = [];
        foreach ($sharesC as $id => $c) {
            $shares[(int)$id] = round($c / 100, 2);
        }
        return ['shares' => $shares, 'group_owed' => round($totalC / 100, 2), 'excess' => round($excessC / 100, 2)];
    }
}

if (!function_exists('rh_group_pay_excess_room')) {
    /**
     * The room that takes an overpayment: the group's lead when it counts, else the room the payment was opened on.
     * $roomIds: the counted rooms; $cancelledIds: those that are cancelled (owing their kept fee). A cancelled room never
     * takes the extra while another room can (its credit note is not issued on a cancelled booking): the first
     * non-cancelled room then takes it.
     */
    function rh_group_pay_excess_room(PDO $pdo, int $bookingId, array $roomIds, array $cancelledIds = []): int
    {
        $open = array_values(array_diff(array_map('intval', $roomIds), array_map('intval', $cancelledIds)));
        $lead = function_exists('rh_booking_group_effective_lead') ? rh_booking_group_effective_lead($pdo, $bookingId) : 0;
        if (in_array($lead, $open, true)) {
            return $lead;
        }
        if (in_array($bookingId, $open, true) || !$open) {
            return $bookingId;
        }
        return (int)$open[0];
    }
}

if (!function_exists('rh_group_pay_cancelled_ids')) {
    /** The cancelled rooms among rh_group_pay_rooms() rows (they count only for what they still owe). */
    function rh_group_pay_cancelled_ids(array $rooms): array
    {
        $out = [];
        foreach ($rooms as $r) {
            if ((string)($r['status'] ?? '') === 'cancelled') {
                $out[] = (int)$r['id'];
            }
        }
        return $out;
    }
}

if (!function_exists('rh_group_pay_on_account')) {
    /** Name of the company when any room is billed to a company account (company pays later), else ''. */
    function rh_group_pay_on_account(PDO $pdo, array $roomIds): string
    {
        if (!function_exists('rh_booking_billing')) {
            require_once __DIR__ . '/../../includes/billing-accounts.php';
        }
        foreach ($roomIds as $id) {
            $bill = rh_booking_billing($pdo, ['id' => (int)$id]);
            if (!empty($bill['on_account'])) {
                return (string)($bill['name'] ?: 'a company account');
            }
        }
        return '';
    }
}

if (!function_exists('rh_group_pay_preview')) {
    /**
     * Read-only preview for the Record payment dialogs: is this room in a group, and how $amount would be split.
     * 'expect' (booking id => balance) is posted back with the payment; the server refuses if any balance has moved.
     */
    function rh_group_pay_preview(PDO $pdo, int $bookingId, float $amount): array
    {
        $out = ['ok' => true, 'is_group' => false, 'can_group' => false, 'reason' => '', 'rooms' => [], 'group_owed' => 0.0,
            'amount' => round(max(0.0, $amount), 2), 'excess' => 0.0, 'excess_room_id' => 0, 'expect' => new stdClass()];
        $rooms = rh_group_pay_rooms($pdo, $bookingId, false);
        $ids = array_column($rooms, 'id');
        if (count($rooms) < 2 || !in_array($bookingId, $ids, true)) {
            return $out;
        }
        $out['is_group'] = true;
        $company = rh_group_pay_on_account($pdo, $ids);
        if ($company !== '') {
            $out['reason'] = rh_group_pay_company_reason($company);
            return $out;
        }
        $out['can_group'] = true;
        $owed = array_column($rooms, 'owed', 'id');
        $excessRoom = rh_group_pay_excess_room($pdo, $bookingId, $ids, rh_group_pay_cancelled_ids($rooms));
        $split = rh_group_pay_split($owed, $out['amount'], $excessRoom);
        $expect = [];
        foreach ($rooms as $r) {
            $r['apply'] = $split['shares'][$r['id']] ?? 0.0;
            $out['rooms'][] = $r;
            $expect[(string)$r['id']] = $r['owed'];
        }
        $out['group_owed'] = $split['group_owed'];
        $out['excess'] = $split['excess'];
        $out['excess_room_id'] = $excessRoom;
        $out['expect'] = $expect;
        return $out;
    }
}

if (!function_exists('rh_group_pay_record')) {
    /**
     * Record one whole-group payment: one payments row (own reference, receipt number, VAT split) per room that takes a
     * share, every room re-synced, and the one overpayment credit note when the amount is above what the group owes.
     * Runs INSIDE the caller's transaction (never begins or commits): any refusal throws and the caller rolls back,
     * so it is all rooms or none. History, receipts and emails are the caller's job after the commit
     * (rh_group_pay_log_history, receipt_after_payment per leg).
     *
     * $in: amount, method, date, status, reference, notes, cc_emails, client_uuid, confirm_overpay (bool),
     *      expect (booking id => balance from the preview), vat_rate (fallback), processed_by, txn_column.
     * @return array{legs:array<int,array>,group_owed:float,amount:float,excess:float,excess_room_id:int,credit:?array}
     */
    function rh_group_pay_record(PDO $pdo, int $bookingId, array $in, array $user): array
    {
        $tol = (float)BALANCE_TOLERANCE;
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Group payment must run inside a transaction.');
        }
        $amount = round((float)($in['amount'] ?? 0), 2);
        $status = (string)($in['status'] ?? '');
        $method = (string)($in['method'] ?? '');
        $date = (string)($in['date'] ?? date('Y-m-d'));
        $txnCol = (string)($in['txn_column'] ?? 'transaction_reference');
        if (!preg_match('/^[a-z_]+$/', $txnCol)) {
            throw new RuntimeException('Invalid payment column.');
        }
        if ($amount <= 0) {
            throw new Exception('Payment amount must be greater than zero. Nothing was recorded.');
        }
        if (!in_array($status, ['completed', 'paid'], true)) {
            throw new Exception('Pending whole-group payments are not allowed: split a payment across the group only once the money is received (status Completed or Paid). A pending payment can be recorded for this room only. Nothing was recorded.');
        }
        if ($method === '') {
            throw new Exception('Choose how the guest paid. Nothing was recorded.');
        }

        // Lock every counted room in id order (two staff paying the same group can never deadlock), then re-read under the lock.
        $pre = rh_group_pay_rooms($pdo, $bookingId, false);
        $ids = array_map('intval', array_column($pre, 'id'));
        sort($ids);
        if (count($ids) < 2 || !in_array($bookingId, $ids, true)) {
            throw new Exception('This room is no longer part of a group with other open rooms. Use "This room only". Nothing was recorded.');
        }
        $pdo->prepare('SELECT id FROM bookings WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE')->execute($ids);
        $rooms = rh_group_pay_rooms($pdo, $bookingId, true);
        $ids = array_map('intval', array_column($rooms, 'id'));
        if (count($ids) < 2 || !in_array($bookingId, $ids, true)) {
            throw new Exception('This room is no longer part of a group with other open rooms. Use "This room only". Nothing was recorded.');
        }
        $company = rh_group_pay_on_account($pdo, $ids);
        if ($company !== '') {
            throw new Exception(rh_group_pay_company_reason($company) . ' Nothing was recorded.');
        }

        // The split staff confirmed must still be the split we record: same rooms, same balances.
        $expect = is_array($in['expect'] ?? null) ? $in['expect'] : [];
        $changed = count($expect) !== count($rooms);
        foreach ($rooms as $r) {
            if (!array_key_exists((string)$r['id'], $expect) || abs((float)$expect[(string)$r['id']] - (float)$r['owed']) > $tol) {
                $changed = true;
            }
        }
        if ($changed) {
            throw new Exception('The group\'s balances changed since the split was shown (another payment or charge was recorded). Open Record payment again to see the new split. Nothing was recorded.');
        }

        $owed = array_column($rooms, 'owed', 'id');
        $groupOwed = round(array_sum($owed), 2);
        $limitErr = rh_payment_limit_error($amount, $groupOwed, !empty($in['confirm_overpay']));
        if ($limitErr !== null) {
            throw new Exception(str_replace('this booking', 'this group', $limitErr) . ' Nothing was recorded.');
        }
        $excessRoom = rh_group_pay_excess_room($pdo, $bookingId, $ids, rh_group_pay_cancelled_ids($rooms));
        $split = rh_group_pay_split($owed, $amount, $excessRoom);
        if (abs(array_sum($split['shares']) - $amount) > 0.001) {
            throw new RuntimeException('Group split does not add up. Nothing was recorded.');
        }
        $byId = [];
        foreach ($rooms as $r) {
            $byId[$r['id']] = $r;
        }

        $baseUuid = function_exists('idem_normalize_uuid') ? idem_normalize_uuid($in['client_uuid'] ?? null) : null;
        $ins = $pdo->prepare("
            INSERT INTO payments (
                payment_reference, booking_type, booking_id, booking_reference, payment_date,
                payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_status, status, {$txnCol},
                receipt_number, cc_emails, processed_by, notes, client_uuid, payment_type
            ) VALUES (?, 'room', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $refCheck = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE payment_reference = ?');
        $legCount = count(array_filter($split['shares'], static fn ($s) => $s > 0.001));
        $cur = (string)getSetting('currency_symbol', 'MWK');
        $groupNote = 'Part of a whole-group payment of ' . $cur . ' ' . number_format($amount, 2) . ' split across ' . $legCount . ' rooms.';
        $userNotes = trim((string)($in['notes'] ?? ''));
        $legs = [];
        $first = true;
        foreach ($ids as $id) {
            $share = (float)($split['shares'][$id] ?? 0);
            if ($share <= 0.001) {
                continue;
            }
            $vat = rh_account_vat_split($pdo, 'room', $id, $share, (float)($in['vat_rate'] ?? 0));
            do {
                $ref = 'PAY' . date('Ym') . strtoupper(substr(uniqid(), -6));
                $refCheck->execute([$ref]);
                $exists = (int)$refCheck->fetchColumn() > 0;
            } while ($exists || isset($legs[$ref]));
            $receipt = finance_next_receipt_number($pdo, $date);
            $uuid = null;
            if ($baseUuid !== null) {
                if ($first) {
                    $uuid = $baseUuid; // the double-submit check finds the group by this one
                } else {
                    $uuid = $baseUuid . ':g' . $id;
                    if (strlen($uuid) > 64) {
                        $uuid = substr(md5($baseUuid), 0, 40) . ':g' . $id;
                    }
                }
            }
            $first = false;
            $ins->execute([
                $ref, $id, $byId[$id]['booking_reference'], $date,
                $vat['net'], $vat['rate'], $vat['vat'], $share,
                $method, $status, rh_legacy_payment_status($status), ($in['reference'] ?? '') !== '' ? (string)$in['reference'] : null,
                $receipt, ($in['cc_emails'] ?? '') !== '' ? (string)$in['cc_emails'] : null, (string)($in['processed_by'] ?? ''),
                $userNotes !== '' ? $userNotes . "\n" . $groupNote : $groupNote, $uuid,
                ($share + $tol >= (float)$owed[$id]) ? 'full_payment' : 'partial_payment',
            ]);
            $payId = (int)$pdo->lastInsertId();
            if (!rh_sync_account_payments($pdo, 'room', $id)) {
                throw new RuntimeException('Could not recalculate the balance of ' . $byId[$id]['booking_reference'] . '. Nothing was recorded.');
            }
            $legs[$ref] = [
                'booking_id' => $id, 'booking_reference' => $byId[$id]['booking_reference'], 'room' => $byId[$id]['room'],
                'guest_name' => $byId[$id]['guest_name'], 'payment_id' => $payId, 'payment_reference' => $ref,
                'receipt_number' => $receipt, 'amount' => $share, 'owed_before' => (float)$owed[$id],
            ];
        }

        // Above what the whole group owes: one credit note, on the room that took the extra (same rule as one room).
        $credit = null;
        if ($split['excess'] > $tol) {
            $ovSel = $pdo->prepare('SELECT status, booking_reference, guest_name, guest_email FROM bookings WHERE id = ? FOR UPDATE');
            $ovSel->execute([$excessRoom]);
            $ov = $ovSel->fetch(PDO::FETCH_ASSOC);
            $ovLeg = null;
            foreach ($legs as $l) {
                if ($l['booking_id'] === $excessRoom) {
                    $ovLeg = $l;
                }
            }
            if ($ov && $ovLeg && (string)$ov['status'] !== 'cancelled') {
                $excess = round(min(rh_account_credit_owed($pdo, 'room', $excessRoom), (float)$ovLeg['amount']), 2);
                if ($excess > $tol) {
                    if (!function_exists('rh_issue_overpay_credit_notes')) {
                        require_once __DIR__ . '/../../config/credit-notes.php';
                    }
                    try {
                        $notes = rh_issue_overpay_credit_notes($pdo, 'room', $excessRoom, $excess,
                            ['ref' => (string)$ov['booking_reference'], 'name' => (string)$ov['guest_name'], 'email' => (string)$ov['guest_email']],
                            (string)$ovLeg['payment_reference'], (int)($user['id'] ?? 0), (float)($in['vat_rate'] ?? 0));
                    } catch (RuntimeException $ovEx) {
                        throw new Exception($ovEx->getMessage() . ' Nothing was recorded.');
                    }
                    if (!rh_sync_account_payments($pdo, 'room', $excessRoom)) {
                        throw new RuntimeException('Could not recalculate the balance. Nothing was recorded.');
                    }
                    $credit = ['credit_note_id' => (int)$notes[0]['credit_note_id'], 'credit_note_number' => implode(', ', array_column($notes, 'credit_note_number')),
                        'notes' => $notes, 'excess' => $excess, 'booking_id' => $excessRoom, 'payment_id' => (int)$ovLeg['payment_id']];
                }
            }
        }

        return ['legs' => array_values($legs), 'group_owed' => $groupOwed, 'amount' => $amount, 'excess' => $split['excess'],
            'excess_room_id' => $excessRoom, 'credit' => $credit];
    }
}

if (!function_exists('rh_group_pay_log_history')) {
    /**
     * History for every room of a recorded group payment (payment History + the booking timeline): who, how much,
     * owed before and after, the overpay tick and the credit note. Call after the commit; a failed log write never undoes
     * the payment. Returns the group's balance still owed after the payment.
     */
    function rh_group_pay_log_history(PDO $pdo, array $res, array $user, string $method, string $status, bool $overpayTicked): float
    {
        if (!function_exists('rh_hist_payment_saved_note')) {
            require_once __DIR__ . '/../../includes/booking-history-format.php';
        }
        if (!function_exists('logBookingEvent')) {
            require_once __DIR__ . '/../../includes/booking-timeline.php';
        }
        $cur = (string)getSetting('currency_symbol', 'MWK');
        $who = (string)($user['full_name'] ?? ($user['username'] ?? ''));
        $n = count($res['legs']);
        $refs = implode(', ', array_column($res['legs'], 'booking_reference'));
        $after = 0.0;
        foreach ($res['legs'] as $leg) {
            try {
                $owedAfter = rh_account_balance_due($pdo, 'room', (int)$leg['booking_id']);
                $after += $owedAfter;
                $isCn = $res['credit'] && (int)$res['credit']['payment_id'] === (int)$leg['payment_id'];
                $note = rh_hist_payment_saved_note((float)$leg['amount'], $method, (float)$leg['owed_before'], $owedAfter,
                    $overpayTicked && $isCn, $isCn ? (string)$res['credit']['credit_note_number'] : null, $isCn ? (float)$res['credit']['excess'] : null, $cur)
                    . ' Whole-group payment: ' . rh_hist_money((float)$res['amount'], $cur) . ' split across ' . $n . ' rooms (' . $refs . ').';
                rh_log_payment_audit($pdo, (int)$leg['payment_id'], (string)$leg['payment_reference'], 'created', [], (int)($user['id'] ?? 0), $who, $note);
                logBookingEvent((int)$leg['booking_id'], (string)$leg['booking_reference'], 'Payment ' . $leg['payment_reference'] . ' recorded (whole group)', 'payment', $note,
                    null, $status, 'admin', (int)($user['id'] ?? 0) ?: null, $who,
                    ['payment_id' => (int)$leg['payment_id'], 'payment_ref' => (string)$leg['payment_reference'], 'amount' => (float)$leg['amount'],
                        'method' => $method, 'balance_before' => (float)$leg['owed_before'], 'balance_after' => $owedAfter,
                        'group_payment_total' => (float)$res['amount'], 'group_rooms' => $n,
                        'overpay_confirmed' => $overpayTicked && $isCn, 'credit_note_id' => $isCn ? (int)$res['credit']['credit_note_id'] : null]);
            } catch (Throwable $e) {
                error_log('group payment history for payment ' . (int)$leg['payment_id'] . ': ' . $e->getMessage());
            }
        }
        // Rooms that took nothing still count in what the group owes.
        try {
            foreach (rh_group_pay_rooms($pdo, (int)($res['legs'][0]['booking_id'] ?? 0), false) as $r) {
                if (!in_array((int)$r['id'], array_map('intval', array_column($res['legs'], 'booking_id')), true)) {
                    $after += (float)$r['owed'];
                }
            }
        } catch (Throwable $e) {
            error_log('group payment owed after: ' . $e->getMessage());
        }
        return round($after, 2);
    }
}

if (!function_exists('rh_group_pay_after_commit')) {
    /**
     * Everything after a whole-group payment is committed, shared by every screen that records one (Add Payment /
     * Record payment and Payments & balance): History on every room, the credit note email, one receipt per room,
     * the system log, and the staff message. Never undoes the payment.
     * @return array{owed_after:float,emailed:int,message:string}
     */
    function rh_group_pay_after_commit(PDO $pdo, array $res, array $user, string $method, string $status, bool $overpayTicked, string $source): array
    {
        $owedAfter = rh_group_pay_log_history($pdo, $res, $user, $method, $status, $overpayTicked);
        if (!empty($res['credit'])) {
            if (!function_exists('rh_email_overpay_credit_notes')) {
                require_once __DIR__ . '/../../config/credit-notes.php';
            }
            rh_email_overpay_credit_notes($pdo, $res['credit']);
        }
        require_once __DIR__ . '/../../config/receipts.php';
        $emailed = 0;
        foreach ($res['legs'] as $leg) {
            try {
                if (receipt_after_payment($pdo, (int)$leg['payment_id'], $user)['sent']) {
                    $emailed++;
                }
            } catch (Throwable $e) {
                error_log('group payment receipt ' . (int)$leg['payment_id'] . ': ' . $e->getMessage());
            }
        }
        if (function_exists('rh_log_event')) {
            rh_log_event($source, 'info', 'Whole-group payment recorded', [
                'booking_id' => (int)($res['legs'][0]['booking_id'] ?? 0), 'amount' => (float)$res['amount'], 'rooms' => count($res['legs']),
                'payment_ids' => array_column($res['legs'], 'payment_id'), 'method' => $method, 'by' => $user['username'] ?? null,
            ]);
        }
        $cur = (string)getSetting('currency_symbol', 'MWK');
        $n = count($res['legs']);
        $msg = 'Payment of ' . $cur . ' ' . number_format((float)$res['amount'], 2) . ' recorded for the whole group, split across ' . $n
            . ' room' . ($n === 1 ? '' : 's') . ' (receipts ' . implode(', ', array_column($res['legs'], 'receipt_number')) . ').'
            . ($owedAfter > BALANCE_TOLERANCE ? ' The group still owes ' . $cur . ' ' . number_format($owedAfter, 2) . '.' : ' Nothing more is owed by the group.')
            . (!empty($res['credit']) ? ' Overpayment: credit note ' . $res['credit']['credit_note_number'] . ' for ' . number_format((float)$res['credit']['excess'], 2) . ' issued.' : '')
            . ($emailed > 0 ? ' ' . $emailed . ' receipt' . ($emailed === 1 ? '' : 's') . ' emailed.' : '');
        return ['owed_after' => $owedAfter, 'emailed' => $emailed, 'message' => $msg];
    }
}
