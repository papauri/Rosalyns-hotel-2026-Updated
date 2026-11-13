<?php
/**
 * Group charge (owner 2026-11-14): Add Charge on a room that belongs to a group can post the charge to the whole group.
 * Used by admin/booking-details.php (preview JSON + the POST) and scripts/tests/group_charge.php.
 *
 * Rules (settled for this feature):
 *  - Group = bookings.primary_booking_id (the lead and every room pointing at it), not deleted. 2+ rooms = a group.
 *  - Which rooms can take a charge = the Add Charge rule itself (bookingAllowsAction 'add_charge': not cancelled /
 *    no-show / expired, not checked out). Every other room is listed as skipped, with its reason.
 *  - "line" = ONE folio line on the group's effective lead room (rh_booking_group_effective_lead), description
 *    "Group: <desc>", quantity and unit price as entered: it prints under the lead on the group invoice and counts in
 *    the group total / Still owed / whole-group payment. Refused when that lead room cannot take charges.
 *  - "split" = one line per room that can take charges, the amount split in whole cents, the cents left over on the
 *    lead (or, when the lead cannot take charges, on the first room in the list), each line quantity 1 at its share,
 *    "Group: <desc> (share i of N)". The lines always add up to the amount entered exactly.
 *  - Price entered VAT-inclusive, as Add Charge (addBookingCharge extracts the VAT per line).
 *  - All lines in ONE transaction (rows locked, plan re-checked against the previewed one), History on every room
 *    (booking timeline, action_type 'update': the column is an ENUM without 'charge').
 *  - Each line voids on its own as today (no group void).
 */

require_once __DIR__ . '/booking-lifecycle.php';
require_once __DIR__ . '/../../includes/booking-timeline.php';

if (!function_exists('rh_group_charge_rooms')) {
    /**
     * Every room of $bookingId's group (not deleted), the group's lead first, then id order. Each row says whether it
     * can take a charge now. $lock = true: rows are locked FOR UPDATE (call inside the transaction).
     *
     * @return array{root:int,lead:int,rooms:array<int,array{id:int,booking_reference:string,guest:string,status:string,room:string,can:bool,reason:string}>}
     */
    function rh_group_charge_rooms(PDO $pdo, int $bookingId, bool $lock = false): array
    {
        $out = ['root' => 0, 'lead' => 0, 'rooms' => []];
        if ($bookingId <= 0) {
            return $out;
        }
        $st = $pdo->prepare('SELECT COALESCE(NULLIF(primary_booking_id, 0), id) FROM bookings WHERE id = ?');
        $st->execute([$bookingId]);
        $root = (int)$st->fetchColumn();
        if ($root <= 0) {
            return $out;
        }
        $q = $pdo->prepare("
            SELECT b.id, b.booking_reference, b.status, b.amount_paid, b.amount_due, b.total_amount,
                   COALESCE(NULLIF(TRIM(b.room_guest_name), ''), b.guest_name) AS guest, COALESCE(r.name, '') AS room_type
            FROM bookings b
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE (b.id = ? OR b.primary_booking_id = ?) AND b.deleted_at IS NULL
            ORDER BY b.id ASC" . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$root, $root]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $lead = function_exists('rh_booking_group_effective_lead') ? rh_booking_group_effective_lead($pdo, $root) : $root;
        $rooms = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $allow = bookingAllowsAction($r, 'add_charge');
            $rooms[] = [
                'id'                => $id,
                'booking_reference' => (string)$r['booking_reference'],
                'guest'             => (string)$r['guest'],
                'status'            => (string)$r['status'],
                'room'              => function_exists('getBookingRoomLabel') ? getBookingRoomLabel($id, (string)$r['room_type']) : (string)$r['room_type'],
                'can'               => !empty($allow['allowed']),
                'reason'            => (string)($allow['reason'] ?? ''),
            ];
        }
        // The lead first, the rest in id order (share 1 = the lead).
        usort($rooms, static function (array $a, array $b) use ($lead): int {
            if (($a['id'] === $lead) !== ($b['id'] === $lead)) {
                return $a['id'] === $lead ? -1 : 1;
            }
            return $a['id'] <=> $b['id'];
        });
        $out['root'] = $root;
        $out['lead'] = $lead;
        $out['rooms'] = $rooms;
        return $out;
    }
}

if (!function_exists('rh_group_charge_plan')) {
    /**
     * The lines a group charge would post (read-only). $mode 'line' | 'split'. $quantity / $unitPrice as entered
     * (VAT-inclusive). $group: rh_group_charge_rooms() output (pass the locked one when posting).
     *
     * @return array{ok:bool,message:string,mode:string,total:float,lines:array,skipped:array,sig:string,lead_id:int}
     */
    function rh_group_charge_plan(array $group, string $mode, string $description, float $quantity, float $unitPrice): array
    {
        $out = ['ok' => false, 'message' => '', 'mode' => $mode, 'total' => 0.0, 'lines' => [], 'skipped' => [], 'sig' => '', 'lead_id' => (int)$group['lead']];
        $rooms = $group['rooms'];
        if (count($rooms) < 2) {
            $out['message'] = 'This booking is not part of a group. Use "This room".';
            return $out;
        }
        if (!in_array($mode, ['line', 'split'], true)) {
            $out['message'] = 'Choose how to charge the group.';
            return $out;
        }
        $total = round($quantity * $unitPrice, 2);
        $cents = (int)round($total * 100);
        $out['total'] = $cents / 100;
        $live = [];
        foreach ($rooms as $r) {
            if ($r['can']) {
                $live[] = $r;
            } else {
                $out['skipped'][] = ['id' => $r['id'], 'booking_reference' => $r['booking_reference'], 'room' => $r['room'],
                    'guest' => $r['guest'], 'status' => $r['status'], 'reason' => $r['reason']];
            }
        }
        $qtyText = abs($quantity - 1.0) > 0.0001 ? ' × ' . rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.') : '';

        if ($mode === 'line') {
            $lead = null;
            foreach ($rooms as $r) {
                if ($r['id'] === (int)$group['lead']) {
                    $lead = $r;
                }
            }
            if ($lead === null || !$lead['can']) {
                $out['message'] = 'The group\'s lead room' . ($lead ? ' (' . $lead['booking_reference'] . ', ' . $lead['status'] . ')' : '')
                    . ' cannot take charges, so a group line has nowhere to go. Split it across the rooms, or add it to one room.';
                return $out;
            }
            $desc = 'Group: ' . $description;
            if (mb_strlen($desc) > 255) {
                $out['message'] = 'The description is too long for a group line (248 characters maximum).';
                return $out;
            }
            $out['skipped'] = []; // one line on the lead: no room is left out
            $out['lines'][] = ['booking_id' => $lead['id'], 'booking_reference' => $lead['booking_reference'], 'room' => $lead['room'],
                'guest' => $lead['guest'], 'description' => $desc, 'quantity' => $quantity, 'unit_price' => round($unitPrice, 2),
                'amount' => $out['total'], 'share' => 0];
        } else {
            $n = count($live);
            if ($n === 0) {
                $out['message'] = 'No room in this group can take charges now (cancelled, no-show, expired or checked out).';
                return $out;
            }
            if ($cents < $n) {
                $out['message'] = 'The amount is too small to split across ' . $n . ' room' . ($n === 1 ? '' : 's') . ' (at least 0.01 each).';
                return $out;
            }
            $base = intdiv($cents, $n);
            $rem = $cents - $base * $n;
            // The leftover cents go on the lead; when the lead cannot take charges, on the first room in the list.
            $remIdx = 0;
            foreach ($live as $i => $r) {
                if ($r['id'] === (int)$group['lead']) {
                    $remIdx = $i;
                }
            }
            foreach ($live as $i => $r) {
                $share = ($base + ($i === $remIdx ? $rem : 0)) / 100;
                $desc = 'Group: ' . $description . $qtyText . ' (share ' . ($i + 1) . ' of ' . $n . ')';
                if (mb_strlen($desc) > 255) {
                    $out['message'] = 'The description is too long to split (shorten it by ' . (mb_strlen($desc) - 255) . ' characters).';
                    $out['lines'] = [];
                    return $out;
                }
                $out['lines'][] = ['booking_id' => $r['id'], 'booking_reference' => $r['booking_reference'], 'room' => $r['room'],
                    'guest' => $r['guest'], 'description' => $desc, 'quantity' => 1.0, 'unit_price' => $share, 'amount' => $share,
                    'share' => $i + 1];
            }
        }
        $sigParts = [];
        foreach ($out['lines'] as $l) {
            $sigParts[] = $l['booking_id'] . ':' . number_format($l['amount'], 2, '.', '');
        }
        foreach ($out['skipped'] as $s) {
            $sigParts[] = 'x' . $s['id'];
        }
        $out['sig'] = hash('sha256', $mode . '|' . implode(',', $sigParts));
        $out['ok'] = true;
        return $out;
    }
}

if (!function_exists('rh_group_charge_post')) {
    /**
     * Post a group charge in ONE transaction (a caller transaction is joined: the caller then commits / rolls back).
     * $expectSig: the preview's sig; when given and the plan has changed since (a room checked out, a room added),
     * nothing is posted. History (booking timeline) is written on every room touched.
     *
     * @return array{success:bool,message:string,plan:array,charges:array<int,array{booking_id:int,charge_id:int,amount:float}>}
     */
    function rh_group_charge_post(PDO $pdo, int $bookingId, string $mode, string $chargeType, string $description, float $quantity,
        float $unitPrice, array $user, string $expectSig = ''): array
    {
        $res = ['success' => false, 'message' => '', 'plan' => [], 'charges' => []];
        $ownTx = !$pdo->inTransaction();
        try {
            if ($ownTx) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT rh_grp_charge');
            }
            $group = rh_group_charge_rooms($pdo, $bookingId, true);
            $plan = rh_group_charge_plan($group, $mode, $description, $quantity, $unitPrice);
            $res['plan'] = $plan;
            if (!$plan['ok']) {
                throw new RuntimeException($plan['message']);
            }
            if ($expectSig !== '' && !hash_equals($plan['sig'], $expectSig)) {
                throw new RuntimeException('The group changed since the preview (a room was checked out, cancelled or added). Nothing was added: check the preview again.');
            }
            $userId = (int)($user['id'] ?? 0);
            $who = (string)($user['full_name'] ?? ($user['username'] ?? ''));
            $cur = (string)getSetting('currency_symbol', 'MWK');
            $n = count($plan['lines']);
            $refs = implode(', ', array_column($plan['lines'], 'booking_reference'));
            $skippedText = '';
            if ($plan['skipped']) {
                $skippedText = ' Not charged: ' . implode(', ', array_map(static fn ($s) => $s['booking_reference'] . ' (' . $s['status'] . ')', $plan['skipped'])) . '.';
            }
            foreach ($plan['lines'] as $l) {
                $r = addBookingCharge((int)$l['booking_id'], $chargeType, (string)$l['description'], (float)$l['quantity'], (float)$l['unit_price'], null, $userId ?: null);
                if (empty($r['success'])) {
                    throw new RuntimeException('Room ' . $l['booking_reference'] . ': ' . ($r['message'] ?? 'the charge could not be added') . ' Nothing was added.');
                }
                $amount = (float)$r['line_total'];
                $res['charges'][] = ['booking_id' => (int)$l['booking_id'], 'charge_id' => (int)$r['charge_id'], 'amount' => $amount];
                $note = $mode === 'line'
                    ? 'Group line "' . $l['description'] . '" ' . $cur . ' ' . number_format($amount, 2) . ' added on this room (the group\'s lead) for the whole group.'
                    : 'Group charge "' . $description . '" ' . $cur . ' ' . number_format($plan['total'], 2) . ' split evenly across ' . $n . ' rooms (' . $refs . '): '
                        . $cur . ' ' . number_format($amount, 2) . ' on this room (share ' . $l['share'] . ' of ' . $n . ').' . $skippedText;
                logBookingEvent((int)$l['booking_id'], (string)$l['booking_reference'], 'Group charge added', 'update', $note . ' Charge #' . (int)$r['charge_id'] . '.',
                    null, null, 'admin', $userId ?: null, $who,
                    ['charge_id' => (int)$r['charge_id'], 'amount' => $amount, 'group_total' => $plan['total'], 'group_mode' => $mode,
                        'group_rooms' => $n, 'charge_type' => $chargeType, 'opened_from_booking_id' => $bookingId]);
            }
            $sumCents = 0;
            foreach ($res['charges'] as $c) {
                $sumCents += (int)round($c['amount'] * 100);
            }
            if ($sumCents !== (int)round($plan['total'] * 100)) { // never post lines that do not add up to the amount entered (whole cents)
                throw new RuntimeException('The group lines did not add up to the amount entered. Nothing was added.');
            }
            if ($ownTx) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT rh_grp_charge');
            }
            $res['success'] = true;
            if ($mode === 'line') {
                $l = $plan['lines'][0];
                $res['message'] = 'Group line added on room ' . ($l['room'] !== '' ? $l['room'] . ' ' : '') . '(' . $l['booking_reference'] . ', the group\'s lead): '
                    . $cur . number_format($plan['total'], 2) . '.' . ((int)$l['booking_id'] !== $bookingId ? ' It shows on that room\'s folio and on the group invoice.' : '');
            } else {
                $res['message'] = 'Group charge of ' . $cur . number_format($plan['total'], 2) . ' split across ' . $n . ' room' . ($n === 1 ? '' : 's') . ' (' . $refs . ').' . $skippedText;
            }
            return $res;
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownTx && $pdo->inTransaction()) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT rh_grp_charge');
                } catch (Throwable $e2) {
                    error_log('group charge savepoint rollback: ' . $e2->getMessage());
                }
            }
            error_log('rh_group_charge_post booking ' . $bookingId . ': ' . $e->getMessage());
            $res['message'] = $e->getMessage();
            return $res;
        }
    }
}

if (!function_exists('rh_group_charge_nonce_issue')) {
    /**
     * One-time confirm token for a shown preview (QA 2026-11-14: the sig alone is the same on every resend, so a network
     * retry or a second tab could post the group charge twice). $store: the session's slot ($_SESSION['rh_gc_nonce']);
     * one token per booking, a new preview replaces the old one.
     */
    function rh_group_charge_nonce_issue(array &$store, int $bookingId, string $sig): string
    {
        $nonce = bin2hex(random_bytes(16));
        $store[(string)$bookingId] = ['n' => $nonce, 'sig' => $sig, 't' => time()];
        return $nonce;
    }
}

if (!function_exists('rh_group_charge_nonce_take')) {
    /**
     * Use up the confirm token BEFORE anything is posted (success or failure): a resend finds nothing and is refused.
     * Returns '' when the token is good for this booking + sig (and under 30 minutes old), else the refusal.
     */
    function rh_group_charge_nonce_take(array &$store, int $bookingId, string $nonce, string $sig): string
    {
        $key = (string)$bookingId;
        $held = $store[$key] ?? null;
        unset($store[$key]);
        if (!is_array($held) || $nonce === '' || !hash_equals((string)$held['n'], $nonce)) {
            return 'This group charge was already posted, or its preview is no longer current. Nothing more was added: check the folio, and preview again if a charge is still needed.';
        }
        if (!hash_equals((string)$held['sig'], $sig) || time() - (int)$held['t'] > 1800) {
            return 'The preview is no longer current. Nothing was added: preview the group charge again.';
        }
        return '';
    }
}

if (!function_exists('rh_group_charge_preview_json')) {
    /** The preview the Add Charge dialog shows (plain data; the page renders it as text). */
    function rh_group_charge_preview_json(array $plan, string $currency): array
    {
        $lines = [];
        foreach ($plan['lines'] as $l) {
            $lines[] = ['booking_id' => (int)$l['booking_id'], 'ref' => (string)$l['booking_reference'], 'room' => (string)$l['room'],
                'guest' => (string)$l['guest'], 'description' => (string)$l['description'],
                'amount' => number_format((float)$l['amount'], 2, '.', ''), 'amount_text' => $currency . number_format((float)$l['amount'], 2)];
        }
        $skipped = [];
        foreach ($plan['skipped'] as $s) {
            $skipped[] = ['ref' => (string)$s['booking_reference'], 'room' => (string)$s['room'], 'guest' => (string)$s['guest'], 'status' => (string)$s['status']];
        }
        return ['success' => (bool)$plan['ok'], 'preview' => true, 'message' => (string)$plan['message'], 'mode' => (string)$plan['mode'],
            'total_text' => $currency . number_format((float)$plan['total'], 2), 'lines' => $lines, 'skipped' => $skipped, 'sig' => (string)$plan['sig']];
    }
}
