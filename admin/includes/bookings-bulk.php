<?php
/**
 * All Bookings bulk actions (owner 2026-11-18, G5a): tick rows, review what will happen to each room, confirm,
 * then a plain result list. Every room is planned and done on its own (one skipped room never stops the rest).
 *
 * Each op runs the SAME code as its single action, never a shortcut:
 *   remove_group -> rh_group_remove()             (booking-details.php group_op_apply, permission edit_booking; never moves money)
 *   reminder     -> bookings_send_booking_email()  (bookings.php resend_email, type booking_reminder)
 *   confirmation -> bookings_send_booking_email()  (bookings.php resend_email, the type the Resend menu picks for the status)
 *   invoice      -> bookings_send_invoice_email()  (bookings.php send_invoice_email)
 *   confirm      -> bookings_convert_tentative_booking() / bookings_confirm_hold()  (convert_tentative / update_status)
 *   assign       -> bookings_assign_individual_room() / bookings_assign_room_pair() (the review proposes a free room
 *                   per booking, never one room twice for overlapping nights; the person may change each)
 *   checkin      -> bookings_checkin_gates() + bookings_checkin_commit()            (update_status checked-in, all gates)
 * Confirm is one-time (G5b): the review issues a token kept in the session (rh_bk_bulk_once_*); a repeated confirm
 * returns the first result instead of doing it again.
 * Make a group / Add to a group stay on group_make_preview / group_make_apply (rh_group_make); this file only gives
 * them the per-room filter (rh_bk_bulk_group_filter) so a room that cannot join is skipped with its reason.
 *
 * Pairs with admin/includes/bulk-actions.php (ids whitelist, outcome text) and admin/js/admin-bulk.js (the bar).
 */

require_once __DIR__ . '/bulk-actions.php';
require_once __DIR__ . '/../../includes/booking-group-ops.php';
require_once __DIR__ . '/booking-row-actions.php';

if (!function_exists('rh_bk_bulk_ops')) {

    /** Bulk ops: label, the single action whose permission applies, the outcome verb, the most rooms in one go. */
    function rh_bk_bulk_ops(): array
    {
        return [
            'remove_group' => ['label' => 'Remove from group', 'single' => 'group_op_apply', 'verb' => 'taken out of their group', 'max' => 200],
            'reminder' => ['label' => 'Send a reminder', 'single' => 'resend_email', 'verb' => 'sent', 'max' => 50],
            'confirmation' => ['label' => 'Resend confirmation', 'single' => 'resend_email', 'verb' => 'sent', 'max' => 50],
            'invoice' => ['label' => 'Email invoice', 'single' => 'send_invoice_email', 'verb' => 'sent', 'max' => 50],
            // G5b: the front-desk actions, each through its single action's code (admin/includes/booking-row-actions.php).
            'confirm' => ['label' => 'Confirm', 'single' => 'update_status', 'status' => 'confirmed', 'verb' => 'confirmed', 'max' => 50],
            'assign' => ['label' => 'Assign rooms', 'single' => 'assign_individual_room', 'verb' => 'given a room', 'max' => 50],
            'checkin' => ['label' => 'Check in', 'single' => 'update_status', 'status' => 'checked-in', 'verb' => 'checked in', 'max' => 50],
            // G5c: Cancel and Mark no-show (permission cancel_booking, per room, through the single action's code). Both
            // can email the guest, so they keep the 50-room cap of the send ops.
            'cancel' => ['label' => 'Cancel', 'single' => 'update_status', 'status' => 'cancelled', 'verb' => 'cancelled', 'max' => 50],
            'noshow' => ['label' => 'Mark no-show', 'single' => 'noshow', 'verb' => 'marked no-show', 'max' => 50],
        ];
    }

    /** "MWK 1,250.00" in the hotel's currency (the booked-rate formatter when present). */
    function rh_bk_bulk_money(float $v): string
    {
        $cur = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
        return function_exists('rh_booked_rate_money') ? rh_booked_rate_money($v, $cur) : $cur . ' ' . number_format($v, 2);
    }

    /**
     * Bulk Cancel, one room (G5c), read only: the single Cancel's rules (validateBookingStatusTransition +
     * validateBookingCancellation) and its money preview (rh_cancel_policy_preview: the fee from the policy locked at
     * booking, refund, guest credit). Never a fee override: the automatic fee only.
     * @return array{will:bool,why:string,text:string,cols:?array,quoted:string,policy:bool}
     *   cols: {fee, refund, credit} plain text for the review columns; quoted: the fee figure the confirm must send back
     *   ('' = no policy on this room, nothing posted, as the single dialog).
     */
    function rh_bk_bulk_cancel_room(PDO $pdo, array $b): array
    {
        $status = strtolower(trim((string)($b['status'] ?? '')));
        $skip = static fn(string $why, string $text): array => ['will' => false, 'why' => $why, 'text' => $text, 'cols' => null, 'quoted' => '', 'policy' => false];
        if ($status === 'cancelled') {
            return $skip('already cancelled', 'Skipped: already cancelled.');
        }
        if ($status === 'checked-in') {
            return $skip('checked in', 'Skipped: the guest is in house, so it cannot be cancelled. Check them out instead.');
        }
        if ($status === 'checked-out') {
            return $skip('checked out', 'Skipped: the guest has already checked out, so it cannot be cancelled.');
        }
        if ($status === 'no-show') {
            return $skip('no-show', 'Skipped: it is marked no-show, so it cannot be cancelled.');
        }
        $t = validateBookingStatusTransition($status, 'cancelled');
        $v = validateBookingCancellation($b);
        if (empty($t['allowed']) || empty($v['allowed'])) {
            return $skip('cannot be cancelled', 'Skipped: a ' . str_replace('-', ' ', $status) . ' booking cannot be cancelled.');
        }
        $p = rh_cancel_policy_preview($pdo, $b);
        if ($p['source'] !== 'policy') {
            $mode = function_exists('getCancellationRefundMode') ? getCancellationRefundMode() : '';
            $label = function_exists('getCancellationRefundModeLabel') ? getCancellationRefundModeLabel($mode) : $mode;
            return ['will' => true, 'why' => '', 'policy' => false, 'quoted' => '',
                'cols' => ['fee' => 'Hotel rule', 'refund' => 'Worked out at cancel', 'credit' => 'Worked out at cancel'],
                'text' => 'Cancels this room. No cancellation policy on this booking, so the hotel rule applies: ' . $label
                    . '. Paid ' . rh_bk_bulk_money((float)$p['paid']) . '. The amounts kept and refunded are written to its History.'];
        }
        $fee = (float)$p['amount'];
        $text = 'Cancels this room. ' . $p['tier_label'] . '.';
        $text .= $fee > (float)BALANCE_TOLERANCE ? ' Fee kept: ' . $p['fee_text'] . '.' : ' No fee.';
        if ((float)$p['refund'] > (float)BALANCE_TOLERANCE) {
            $text .= ' Refund ' . rh_bk_bulk_money((float)$p['refund']) . '.';
        }
        if ((float)$p['credit'] > (float)BALANCE_TOLERANCE) {
            $text .= ' ' . rh_bk_bulk_money((float)$p['credit']) . ' stays as guest credit.';
        }
        if ((float)$p['owed'] > (float)BALANCE_TOLERANCE) {
            $text .= ' Still owed after the cancel: ' . rh_bk_bulk_money((float)$p['owed']) . '.';
        }
        return ['will' => true, 'why' => '', 'text' => $text, 'policy' => true, 'quoted' => number_format($fee, 2, '.', ''),
            'cols' => [
                'fee' => $fee > (float)BALANCE_TOLERANCE ? rh_bk_bulk_money($fee) . ' ' . (string)$p['basis'] : 'None',
                'refund' => (float)$p['refund'] > (float)BALANCE_TOLERANCE ? rh_bk_bulk_money((float)$p['refund']) : 'None',
                'credit' => (float)$p['credit'] > (float)BALANCE_TOLERANCE ? rh_bk_bulk_money((float)$p['credit']) : 'None',
            ]];
    }

    /**
     * Bulk Mark no-show, one room (G5c), read only: the single No-show's rules (confirmed, arrival date passed) and the
     * charge rh_mark_booking_no_show() will post: the policy's no-show % locked at booking (100% = the booked stay keeps
     * billing), else the group no-show charge when the setting is on and the room is in a group, else none.
     * @return array{will:bool,why:string,text:string,cols:?array}
     */
    function rh_bk_bulk_noshow_room(PDO $pdo, array $b, string $today): array
    {
        $status = strtolower(trim((string)($b['status'] ?? '')));
        $skip = static fn(string $why, string $text): array => ['will' => false, 'why' => $why, 'text' => $text, 'cols' => null];
        if ($status === 'no-show') {
            return $skip('already no-show', 'Skipped: already marked no-show.');
        }
        if ($status !== 'confirmed') {
            return $skip('not confirmed', 'Skipped: only a confirmed booking can be marked no-show (this one is ' . str_replace('-', ' ', $status) . ').');
        }
        $ci = substr((string)($b['check_in_date'] ?? ''), 0, 10);
        if ($ci === '' || $ci >= $today) {
            return $skip('arrival date not passed', 'Skipped: the arrival date (' . $ci . ') has not passed yet.');
        }
        $tv = validateBookingStatusTransition($status, 'no-show');
        if (empty($tv['allowed'])) {
            return $skip('refused', 'Skipped: ' . getBookingActionErrorMessage('noshow', (string)$tv['reason']));
        }
        $q = rh_cancellation_fee_quote($pdo, $b, 'noshow');
        if ($q['source'] === 'policy') {
            if ((float)$q['pct'] >= 100) {
                $fee = 'Booked stay ' . rh_bk_bulk_money((float)$q['base']);
                $text = 'No-show policy 100%: the booked stay keeps billing (' . rh_bk_bulk_money((float)$q['base']) . ' ' . $q['basis'] . ').';
            } elseif (!empty($q['applies'])) {
                $fee = rh_bk_bulk_money((float)$q['amount']) . ' ' . $q['basis'];
                $text = 'No-show charge ' . $q['fee_text'] . ' replaces the booked stay.';
            } else {
                $fee = 'None';
                $text = 'No-show policy 0%: nothing is charged and the booked stay is no longer billed.';
            }
        } else {
            $mode = rh_group_noshow_charge_mode();
            if ($mode !== '' && rh_group_noshow_fee_applies($pdo, $b)) {
                $a = rh_group_noshow_fee_amount($b, $mode);
                $names = ['none' => 'nothing', 'first_night' => 'the first night', 'full' => 'the full stay'];
                $fee = (float)$a['amount'] > (float)BALANCE_TOLERANCE ? rh_bk_bulk_money((float)$a['amount']) . ' ' . rh_cancel_policy_basis($b) : 'None';
                $text = 'Group no-show charge (' . ($names[$mode] ?? $mode) . '): ' . ((float)$a['amount'] > (float)BALANCE_TOLERANCE ? rh_bk_bulk_money((float)$a['amount']) : 'nothing')
                    . ' replaces the booked stay.';
            } else {
                $stay = (float)($b['total_with_vat'] ?? 0);
                $fee = 'Booked stay ' . rh_bk_bulk_money($stay);
                $text = 'No no-show charge is set, so the booked stay keeps billing (' . rh_bk_bulk_money($stay) . ' ' . rh_cancel_policy_basis($b) . ').';
            }
        }
        $pol = function_exists('getSetting') ? (string)getSetting('no_show_refund_policy', 'none') : 'none';
        $refundWords = ['first_night' => 'money paid above what is kept goes into a pending refund (hotel setting: first night kept)',
            'full' => 'money paid above what is kept goes into a pending refund (hotel setting: full refund)'];
        if ((float)($b['amount_paid'] ?? 0) > (float)BALANCE_TOLERANCE) {
            $text .= ' Paid ' . rh_bk_bulk_money((float)$b['amount_paid']) . ': ' . ($refundWords[$pol] ?? 'no automatic refund (hotel setting)') . '.';
        }
        $email = trim((string)($b['guest_email'] ?? ''));
        $text .= ' Room released. ' . ($email !== '' ? 'Emails ' . $email . ' the no-show notice.' : 'No email address, so no email is sent.');
        return ['will' => true, 'why' => '', 'text' => $text, 'cols' => ['fee' => $fee]];
    }

    /**
     * Bulk Cancel groups (G5c). A group whose every live room (rh_booking_group_ids) is ticked is cancelled together,
     * all or none, like the single Cancel's "Whole group" (rh_cancel_booking_group checks the sum of the rooms' fees).
     * If one of its rooms cannot be cancelled, every room of that group is skipped and the review names the room to
     * untick (the others are then cancelled one by one). A group only partly ticked: each ticked room on its own, like
     * the single Cancel's "Only this room". Updates $rooms (plan rows) in place: group (sorted ids), group_quoted, text.
     */
    function rh_bk_bulk_cancel_groups(PDO $pdo, array &$rooms): void
    {
        $at = [];
        foreach ($rooms as $k => $r) {
            $at[(int)$r['id']] = $k;
        }
        $seen = [];
        foreach ($rooms as $r) {
            $id = (int)$r['id'];
            if (isset($seen[$id]) || in_array((string)$r['why'], ['not found', 'deleted'], true)) {
                continue;
            }
            $g = array_values(array_unique(array_map('intval', rh_booking_group_ids($pdo, $id))));
            sort($g);
            if (count($g) < 2 || !in_array($id, $g, true) || array_diff($g, array_keys($at))) {
                continue; // not in a group, or only part of its group ticked: this room on its own
            }
            foreach ($g as $gid) {
                $seen[$gid] = true;
            }
            $labelOf = static fn(int $gid): string => (string)$rooms[$at[$gid]]['label'];
            $blocked = array_values(array_filter($g, static fn($gid) => empty($rooms[$at[$gid]]['will'])));
            if ($blocked) {
                $names = implode(', ', array_map($labelOf, $blocked));
                foreach ($g as $gid) {
                    if (in_array($gid, $blocked, true)) {
                        continue; // keeps its own reason
                    }
                    $rooms[$at[$gid]] = ['will' => false, 'why' => 'its group cannot all be cancelled', 'group' => $g, 'cols' => null, 'quoted' => '', 'policy' => false,
                        'text' => 'Skipped: every room of its group is ticked, so the group is cancelled together (all or none), but ' . $names
                            . ' cannot be cancelled. Untick ' . $names . ' to cancel the other rooms one by one.'] + $rooms[$at[$gid]];
                }
                continue;
            }
            $anyPolicy = false;
            $sum = 0.0;
            foreach ($g as $gid) {
                if (!empty($rooms[$at[$gid]]['policy'])) {
                    $anyPolicy = true;
                    $sum = round($sum + (float)$rooms[$at[$gid]]['quoted'], 2);
                }
            }
            $all = implode(', ', array_map($labelOf, $g));
            foreach ($g as $gid) {
                $rooms[$at[$gid]]['group'] = $g;
                $rooms[$at[$gid]]['group_quoted'] = $anyPolicy ? number_format($sum, 2, '.', '') : '';
                $rooms[$at[$gid]]['text'] = 'Every room of its group is ticked, so the ' . count($g) . ' rooms (' . $all . ') are cancelled together, all or none. ' . $rooms[$at[$gid]]['text'];
            }
        }
    }

    /** The ticked bookings (b.* + room type name), keyed by id, in the order given. Missing ids are left out. */
    function rh_bk_bulk_rows(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) {
            return [];
        }
        $st = $pdo->prepare('SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $byId = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byId[(int)$r['id']] = $r;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[$id] = $byId[$id];
            }
        }
        return $out;
    }

    /** The email the Resend menu picks for a status (openResendEmailModal defaults); '' = nothing to resend. */
    function rh_bk_bulk_confirmation_type(string $status): string
    {
        $map = ['pending' => 'booking_received', 'tentative' => 'tentative_confirmed', 'confirmed' => 'booking_confirmed', 'checked-in' => 'booking_confirmed'];
        return $map[strtolower(trim($status))] ?? '';
    }

    /** Whether the row's Resend menu offers the reminder: a missed check-in or a late / overdue checkout. */
    function rh_bk_bulk_is_late(array $b, ?string $today = null): bool
    {
        $today = $today ?? date('Y-m-d');
        $missed = (string)($b['status'] ?? '') === 'confirmed' && substr((string)($b['check_in_date'] ?? ''), 0, 10) < $today;
        return $missed || rh_checkout_overdue_state($b) !== '';
    }

    /**
     * What will happen to one room: ['will' => bool, 'why' => short skip reason (outcome summary), 'text' => the line shown].
     * Read only. Same rules as the single action (and its button).
     */
    function rh_bk_bulk_room_plan(PDO $pdo, string $op, array $b, ?string $today = null, int $userId = 0): array
    {
        $skip = static fn(string $why, string $text): array => ['will' => false, 'why' => $why, 'text' => $text];
        $status = strtolower(trim((string)($b['status'] ?? '')));
        if (!empty($b['deleted_at'])) {
            return $skip('deleted', 'Skipped: this booking is deleted. Restore it first.');
        }
        $email = trim((string)($b['guest_email'] ?? ''));
        $emailOk = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
        switch ($op) {
            case 'reminder':
                if (!in_array($status, ['confirmed', 'pending', 'checked-in'], true)) {
                    return $skip('not an active booking', 'Skipped: reminders are only for confirmed, pending or in-house bookings (this one is ' . str_replace('-', ' ', $status) . ').');
                }
                if (!rh_bk_bulk_is_late($b, $today)) {
                    return $skip('not late', 'Skipped: not late. A reminder is only for a missed check-in or a late or overdue checkout.');
                }
                if (!$emailOk) {
                    return $skip('no email address', 'Skipped: no email address on this booking.');
                }
                return ['will' => true, 'why' => '', 'text' => 'Sends the reminder ('
                    . ($status === 'checked-in' ? 'late or overdue checkout' : 'missed check-in') . ') to ' . $email . '.'];
            case 'confirmation':
                $type = rh_bk_bulk_confirmation_type($status);
                if ($type === '') {
                    return $skip('no confirmation for this status', 'Skipped: there is no confirmation to resend for a ' . str_replace('-', ' ', $status) . ' booking.');
                }
                if (!$emailOk) {
                    return $skip('no email address', 'Skipped: no email address on this booking.');
                }
                $names = ['booking_received' => 'booking received email', 'tentative_confirmed' => 'tentative booking email', 'booking_confirmed' => 'booking confirmed email'];
                return ['will' => true, 'why' => '', 'text' => 'Resends the ' . $names[$type] . ' to ' . $email . '.'];
            case 'invoice':
                if (!in_array($status, ['confirmed', 'checked-in', 'checked-out'], true)) {
                    return $skip('not confirmed', 'Skipped: an invoice is only emailed for a confirmed, in-house or checked-out booking.');
                }
                if (round((float)($b['amount_due'] ?? 0), 2) <= (float)BALANCE_TOLERANCE) {
                    return $skip('nothing owed', 'Skipped: nothing is owed on this booking. Send the receipt instead.');
                }
                if (function_exists('rh_invoice_paid_refusal')) {
                    $paid = rh_invoice_paid_refusal($pdo, 'room', (int)$b['id']);
                    if ($paid !== null) {
                        return $skip('fully paid', 'Skipped: ' . $paid);
                    }
                }
                if (!$emailOk) {
                    return $skip('no email address', 'Skipped: no email address on this booking.');
                }
                return ['will' => true, 'why' => '', 'text' => 'Emails the invoice to ' . $email . '.'];
            case 'remove_group':
                $res = rh_group_remove($pdo, (int)$b['id'], ['dry_run' => true]);
                if (empty($res['success'])) {
                    $err = (string)$res['error'];
                    return $skip($err === 'This booking is not in a group.' ? 'not in a group' : 'refused', 'Skipped: ' . $err);
                }
                $text = 'Becomes an individual booking with its own payments, balance and invoice. No money moves.';
                if (!empty($res['repointed'])) {
                    $text .= ' It is the primary room, so ' . $res['new_label'] . ' becomes the primary room of the rest.';
                }
                if (($res['billing'] ?? '') === 'copied') {
                    $text .= ' Still billed to ' . $res['company'] . ' (copied from the group).';
                }
                return ['will' => true, 'why' => '', 'text' => $text];
            case 'confirm':
                // Same rules as the row's Confirm: a tentative hold is converted (convert_tentative); a pending booking
                // (or a tentative one without the hold flag) goes through Status -> Confirmed, which needs a free room.
                $words = str_replace('-', ' ', $status);
                if ($status === 'tentative' && (int)($b['is_tentative'] ?? 0) === 1) {
                    $text = 'Confirms the tentative booking. ' . ($emailOk ? 'Emails ' . $email . ' that it is confirmed.' : 'No email address, so no email is sent.');
                } elseif (in_array($status, ['pending', 'tentative'], true)) {
                    $avail = checkRoomAvailability((int)$b['room_id'], (string)$b['check_in_date'], (string)$b['check_out_date'], (int)$b['id']);
                    if (empty($avail['available'])) {
                        return $skip('no room free', 'Skipped: ' . getBookingActionErrorMessage('confirm', (string)($avail['error'] ?? 'No rooms available')));
                    }
                    $text = 'Confirms the booking. ' . ($emailOk ? 'Emails ' . $email . ' the booking confirmed email.' : 'No email address, so no email is sent.');
                } else {
                    return $skip('not pending or tentative', 'Skipped: only a pending or tentative booking can be confirmed (this one is ' . $words . ').');
                }
                if (in_array((string)($b['payment_status'] ?? ''), ['paid', 'completed'], true) && empty($b['individual_room_id'])) {
                    $text .= ' It is paid, so a free room is given automatically.';
                }
                return ['will' => true, 'why' => '', 'text' => $text];
            case 'checkin':
                // Same gates as the row's Check in (validateCheckIn, late arrival, clean room). The bulk bar never asks the
                // questions the single dialog asks, so a late arrival or a room not marked clean is skipped, never assumed.
                if ($status !== 'confirmed') {
                    return $skip('not confirmed', 'Skipped: only a confirmed booking can be checked in (this one is ' . str_replace('-', ' ', $status) . ').');
                }
                $v = validateCheckIn($b);
                if (!$v['allowed']) {
                    $msg = getBookingActionErrorMessage('check_in', (string)$v['reason']);
                    if (strpos((string)$v['reason'], 'not been reached') !== false) {
                        return $skip('arrival date not reached', 'Skipped: the arrival date (' . substr((string)$b['check_in_date'], 0, 10) . ') has not come yet.');
                    }
                    return $skip(strpos((string)$v['reason'], 'payment') !== false ? 'no payment yet' : 'refused', 'Skipped: ' . $msg);
                }
                $win = rh_late_stay_window($b);
                if ((int)$win['late_days'] > 0) {
                    return $skip('late arrival', 'Skipped: the guest was due ' . (int)$win['late_days'] . ' day(s) ago. Use Check in on its row to say when they arrived.');
                }
                if (!getBookingRoomIds((int)$b['id'])) {
                    return $skip('no room assigned', 'Skipped: no room assigned yet. Assign a room first.');
                }
                $gate = evaluateCheckInRoomReady((int)$b['id'], $userId, false);
                if (!$gate['allowed']) {
                    return $skip('room not ready', !empty($gate['needs_confirm'])
                        ? 'Skipped: the room is not marked clean yet. Use Check in on its row if the guest must go in anyway.'
                        : 'Skipped: ' . (string)$gate['message']);
                }
                $text = 'Checks the guest in to room ' . getBookingRoomLabel((int)$b['id']) . '. No email is sent.';
                if (!empty($v['via_arrangement'])) {
                    $text .= ' Allowed by its pay-later arrangement.';
                }
                return ['will' => true, 'why' => '', 'text' => $text];
            case 'cancel':
                return rh_bk_bulk_cancel_room($pdo, $b);
            case 'noshow':
                return rh_bk_bulk_noshow_room($pdo, $b, $today ?? date('Y-m-d'));
        }
        return $skip('unknown action', 'Skipped: unknown action.');
    }

    /**
     * Assign rooms: the free rooms (or joined pairs) one booking could take, the same list as its row's Assign room
     * picker (rh_room_assignment_candidates; an in-house guest needs a clean room from today). Left out: rooms that need
     * the children override note and rooms out of service. Read only.
     * @return array{ok:bool,why:string,text:string,ci:string,co:string,options:array} options: [{value,label,rooms:int[]}]
     *   value 'r:<individual room id>' or 'c:<room_combinations id>'.
     */
    function rh_bk_bulk_assign_options(PDO $pdo, array $b): array
    {
        $out = ['ok' => false, 'why' => '', 'text' => '', 'ci' => '', 'co' => '', 'options' => []];
        $status = strtolower(trim((string)($b['status'] ?? '')));
        if (!empty($b['deleted_at'])) {
            return ['why' => 'deleted', 'text' => 'Skipped: this booking is deleted. Restore it first.'] + $out;
        }
        $v = validateRoomAssignment($b);
        if (!$v['allowed']) {
            return ['why' => 'not confirmed', 'text' => 'Skipped: a room is only assigned to a confirmed or in-house booking (this one is ' . str_replace('-', ' ', $status) . ').'] + $out;
        }
        if (getBookingRoomIds((int)$b['id'])) {
            return ['why' => 'has a room', 'text' => 'Skipped: already has room ' . getBookingRoomLabel((int)$b['id']) . '. Use Assign room on its row to change it.'] + $out;
        }
        $inHouse = $status === 'checked-in';
        $ci = substr((string)$b['check_in_date'], 0, 10);
        $co = substr((string)$b['check_out_date'], 0, 10);
        if ($inHouse && $ci < date('Y-m-d')) {
            $ci = date('Y-m-d');
        }
        $out['ci'] = $ci;
        $out['co'] = $co;
        $cand = rh_room_assignment_candidates((int)$b['room_id'], $ci, $co, (int)$b['id'], $inHouse);
        $children = (int)($b['child_guests'] ?? 0) > 0;
        $pairs = [];
        if ($cand['mode'] === 'combinations') {
            $cids = array_map(static fn($i) => (int)$i['id'], array_filter($cand['items'], static fn($i) => !empty($i['available'])));
            if ($cids) {
                $st = $pdo->prepare('SELECT id, room_a_id, room_b_id FROM room_combinations WHERE id IN (' . implode(',', array_fill(0, count($cids), '?')) . ')');
                $st->execute(array_values($cids));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $pairs[(int)$p['id']] = [(int)$p['room_a_id'], (int)$p['room_b_id']];
                }
            }
        }
        foreach ($cand['items'] as $it) {
            if (empty($it['available'])) {
                continue;
            }
            $note = trim((string)($it['turnover'] ?? ''));
            if ($cand['mode'] === 'combinations') {
                if (!isset($pairs[(int)$it['id']])) {
                    continue;
                }
                $out['options'][] = ['value' => 'c:' . (int)$it['id'], 'label' => 'Rooms ' . $it['label'] . ($note !== '' ? ' (' . $note . ')' : ''), 'rooms' => $pairs[(int)$it['id']]];
                continue;
            }
            if ($children && (int)$it['children_allowed'] === 0) {
                continue; // needs the children override note: only from its row
            }
            $hk = getRoomHousekeepingAssignmentBlock((int)$it['id'], $inHouse);
            if ($hk && !empty($hk['blocked'])) {
                continue;
            }
            $out['options'][] = ['value' => 'r:' . (int)$it['id'], 'label' => 'Room ' . $it['label'] . ($note !== '' ? ' (' . $note . ')' : ''), 'rooms' => [(int)$it['id']]];
        }
        if (!$out['options']) {
            return ['why' => 'no free room', 'text' => 'Skipped: no free ' . ($cand['mode'] === 'combinations' ? 'pair of joined rooms' : 'room of its type') . ' for these dates.'] + $out;
        }
        $out['ok'] = true;
        $out['text'] = 'Gets the room chosen here (' . count($out['options']) . ' free for these dates). The price does not change.';
        return $out;
    }

    /** Whether any room of $rooms is already used in $taken ([[roomId, ci, co], ...]) on an overlapping night. */
    function rh_bk_bulk_rooms_clash(array $taken, array $rooms, string $ci, string $co): ?int
    {
        foreach ($taken as [$rid, $tci, $tco]) {
            if (in_array((int)$rid, $rooms, true) && $ci < $tco && $tci < $co) {
                return (int)$rid;
            }
        }
        return null;
    }

    /**
     * Review list for $op over the ticked ids: every room with what will happen, or "Skipped: reason".
     * A person without the single action's permission gets every room skipped with that reason.
     * @return array{success:bool,error:string,op:string,rooms:array,state:string,will:int,skip:int}
     *   rooms: [{id,label,will,why,text}] in the ticked order; state: token the confirm must send back.
     */
    function rh_bk_bulk_plan(PDO $pdo, string $op, array $ids, int $userId, ?string $today = null): array
    {
        $out = ['success' => false, 'error' => '', 'op' => $op, 'rooms' => [], 'state' => '', 'will' => 0, 'skip' => 0];
        $ops = rh_bk_bulk_ops();
        if (!isset($ops[$op])) {
            $out['error'] = 'Unknown bulk action.';
            return $out;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) {
            $out['error'] = 'Tick at least one booking first.';
            return $out;
        }
        if (count($ids) > $ops[$op]['max']) {
            $out['error'] = 'Tick at most ' . $ops[$op]['max'] . ' bookings for ' . $ops[$op]['label'] . ' in one go.';
            return $out;
        }
        $need = function_exists('rhBookingActionPermission') ? rhBookingActionPermission($ops[$op]['single'], (string)($ops[$op]['status'] ?? '')) : null;
        $denied = $need !== null && !hasPermission($userId, $need);
        $deniedText = '';
        if ($denied) {
            $all = function_exists('getAllPermissions') ? getAllPermissions() : [];
            $deniedText = 'Skipped: you do not have permission to do that (' . ($all[$need]['label'] ?? $need) . ').';
        }
        $rows = rh_bk_bulk_rows($pdo, $ids);
        $assign = [];
        if ($op === 'assign' && !$denied) {
            // Joined-room bookings are proposed first (they need a whole pair), then the rest; a room proposed for one
            // booking is never proposed again for another booking with overlapping nights.
            $order = $ids;
            $joined = [];
            foreach ($ids as $id) {
                $joined[$id] = isset($rows[$id]) && roomTypeHasActiveCombinations((int)$rows[$id]['room_id']) ? 0 : 1;
            }
            usort($order, static fn($a, $b) => [$joined[$a], array_search($a, $ids, true)] <=> [$joined[$b], array_search($b, $ids, true)]);
            $taken = [];
            foreach ($order as $id) {
                if (!isset($rows[$id])) {
                    continue;
                }
                $o = rh_bk_bulk_assign_options($pdo, $rows[$id]);
                $o['pick'] = '';
                if ($o['ok']) {
                    foreach ($o['options'] as $opt) {
                        if (rh_bk_bulk_rooms_clash($taken, $opt['rooms'], $o['ci'], $o['co']) === null) {
                            $o['pick'] = $opt['value'];
                            foreach ($opt['rooms'] as $rid) {
                                $taken[] = [$rid, $o['ci'], $o['co']];
                            }
                            break;
                        }
                    }
                    if ($o['pick'] === '') {
                        $o['ok'] = false;
                        $o['why'] = 'no free room';
                        $o['text'] = 'Skipped: every free room for these dates is already proposed for another ticked booking.';
                    }
                }
                $assign[$id] = $o;
            }
            $out['order'] = array_values(array_filter($order, static fn($id) => isset($rows[$id])));
        }
        foreach ($ids as $id) {
            $b = $rows[$id] ?? null;
            $extra = [];
            if ($b === null) {
                $p = ['will' => false, 'why' => 'not found', 'text' => 'Skipped: this booking no longer exists. Reload the list.'];
                $label = '#' . $id;
            } else {
                $label = rh_group_ops_label($b);
                if ($denied) {
                    $p = ['will' => false, 'why' => 'no permission', 'text' => $deniedText];
                } elseif ($op === 'assign') {
                    $o = $assign[$id];
                    $p = ['will' => (bool)$o['ok'], 'why' => (string)$o['why'], 'text' => (string)$o['text']];
                    if ($o['ok']) {
                        $extra = ['options' => array_map(static fn($x) => ['value' => $x['value'], 'label' => $x['label']], $o['options']), 'pick' => $o['pick']];
                    }
                } else {
                    $p = rh_bk_bulk_room_plan($pdo, $op, $b, $today, $userId);
                    if ($op === 'cancel' || $op === 'noshow') {
                        $extra = ['cols' => $p['cols'] ?? null];
                        if ($op === 'cancel') {
                            $extra['quoted'] = (string)($p['quoted'] ?? '');
                            $extra['policy'] = !empty($p['policy']);
                            // The review's "Email the guest" tick: the row Cancel's default (a confirmed room is emailed).
                            $extra['email_default'] = function_exists('rh_cancel_email_default_ticked')
                                ? rh_cancel_email_default_ticked('list', (string)$b['status'])
                                : strtolower((string)$b['status']) === 'confirmed';
                        }
                    }
                }
            }
            $out['rooms'][] = ['id' => $id, 'label' => $label, 'will' => (bool)$p['will'], 'why' => (string)$p['why'], 'text' => (string)$p['text']] + $extra;
        }
        if ($op === 'cancel' && !$denied) {
            rh_bk_bulk_cancel_groups($pdo, $out['rooms']);
        }
        foreach ($out['rooms'] as $r) {
            $out[$r['will'] ? 'will' : 'skip']++;
        }
        // Assign rooms: the free-room count and the proposals may move between review and confirm; what counts is
        // which bookings will get a room (the room itself is re-checked under lock when it is assigned).
        // Cancel / Mark no-show: the text carries every amount; the fee figure and the group are in the token too, so a
        // fee or a group that changed after the review stops the confirm (nothing done).
        $out['state'] = sha1($op . '|' . json_encode(array_map(static function ($r) use ($op) {
            if ($op === 'assign') {
                return [$r['id'], $r['will'], $r['why']];
            }
            if ($op === 'cancel') {
                return [$r['id'], $r['will'], $r['text'], $r['quoted'] ?? '', $r['group'] ?? [], $r['group_quoted'] ?? ''];
            }
            return [$r['id'], $r['will'], $r['text']];
        }, $out['rooms'])));
        $out['success'] = true;
        return $out;
    }

    /**
     * Send one booking email: the body of the Resend email action (bookings.php resend_email), shared with the bulk
     * Send a reminder / Resend confirmation so both write the same email log and History.
     * $booking: b.* + room_name. $cc: valid CC addresses. Throws on a refused type, like the single action.
     * @return array{success:bool,message:string}
     */
    function bookings_send_booking_email(PDO $pdo, array $booking, string $email_type, array $cc_array, array $user): array
    {
        require_once __DIR__ . '/../../config/email.php';
        $booking_id = (int)($booking['id'] ?? 0);
        // System Logs > Emails sent: every email resent here is about this booking (the sender adds the kind).
        // Script scope: cleared in the finally below so it never reaches the scheduler that runs after the page.
        $emailCtx = rh_email_context(rh_email_ctx_account('room', $booking_id, (string)($booking['booking_reference'] ?? '')));
        $email_result = ['success' => false, 'message' => 'Invalid email type'];
        try {
            switch ($email_type) {
                case 'booking_received':
                    $email_result = sendBookingReceivedEmail($booking);
                    break;
                case 'booking_confirmed':
                    $email_result = sendBookingConfirmedEmail($booking);
                    break;
                case 'tentative_confirmed':
                    $booking['tentative_expires_at'] = $booking['tentative_expires_at'] ?? date('Y-m-d H:i:s', strtotime('+48 hours'));
                    $email_result = sendTentativeBookingConfirmedEmail($booking);
                    break;
                case 'tentative_converted':
                    $email_result = sendTentativeBookingConvertedEmail($booking);
                    break;
                case 'booking_cancelled':
                    // Never tell a guest with a live reservation that it was cancelled.
                    if (!in_array($booking['status'], ['cancelled', 'expired', 'no-show'], true)) {
                        throw new Exception('A cancellation email can only be resent for a cancelled, expired or no-show booking.');
                    }
                    $cancellation_reason = 'Resent by admin';
                    $email_result = sendBookingCancelledEmail($booking, $cancellation_reason);
                    break;
                case 'booking_reminder':
                    // Reminder emails are only valid for active bookings. A no-show
                    // (or any closed status) is explicitly excluded here so it can
                    // never receive a late/overdue alert — no-show takes precedence
                    // over any time-based checkout logic. sendBookingReminderEmail()
                    // enforces the same rule again as defence-in-depth.
                    if (!in_array($booking['status'], ['confirmed', 'pending', 'checked-in'], true)) {
                        throw new Exception('Reminder emails are only for active bookings (no-show, cancelled and closed bookings are excluded).');
                    }
                    $email_result = sendBookingReminderEmail($booking);
                    break;
                case 'invoice':
                    require_once __DIR__ . '/../../config/invoice.php';
                    $cc_recipients = array_values(array_filter($cc_array));
                    $inv_smtp = getEmailSetting('smtp_username', '');
                    if (!empty($inv_smtp) && !in_array($inv_smtp, $cc_recipients)) {
                        $cc_recipients[] = $inv_smtp;
                    }
                    $email_result = sendPaymentInvoiceEmailWithCC($booking_id, $cc_recipients);
                    if ($email_result['success']) {
                        logBookingAudit($booking_id, 'email_sent', null, null, 'Invoice email resent by admin (' . ($user['full_name'] ?? ($user['username'] ?? '')) . ')', $booking['booking_reference'] ?? null);
                    }
                    break;
                default:
                    throw new Exception('Invalid email type selected');
            }
        } finally {
            $emailCtx = null;
            rh_email_context_clear();
        }
        return $email_result;
    }

    /**
     * Email a booking's invoice: the body of the Send invoice action (bookings.php send_invoice_email), shared with the
     * bulk Email invoice. CC: the invoice_recipients setting + the SMTP mailbox.
     * @return array the sendPaymentInvoiceEmailWithCC() result (success, message, ...).
     */
    function bookings_send_invoice_email(int $bid, array $user): array
    {
        require_once __DIR__ . '/../../config/invoice.php';
        $cc_recipients = [];
        $invoice_recipients = getEmailSetting('invoice_recipients', '');
        $smtp_username     = getEmailSetting('smtp_username', '');
        if (!empty($invoice_recipients)) {
            $cc_recipients = array_filter(array_map('trim', explode(',', $invoice_recipients)));
        }
        if (!empty($smtp_username) && !in_array($smtp_username, $cc_recipients)) {
            $cc_recipients[] = $smtp_username;
        }
        $result = sendPaymentInvoiceEmailWithCC($bid, $cc_recipients);
        if ($result['success']) {
            logBookingAudit($bid, 'email_sent', null, null, 'Invoice email sent by admin (' . ($user['full_name'] ?? ($user['username'] ?? '')) . ')');
        }
        return $result;
    }

    /**
     * Do $op for the ticked ids after the review: the plan is made again and must match $state (else nothing is done).
     * Each room is done on its own; a failure skips that room with its reason. Remove from group takes the non-primary
     * rooms out first and primary rooms last (so the group is handed on once, not room by room).
     * @return array{success:bool,error:string,message:string,results:array,done:int}
     *   results: [{id,label,ok,text}] in the ticked order; message: "3 sent, 1 skipped (no email address x1)."
     */
    function rh_bk_bulk_apply(PDO $pdo, string $op, array $ids, array $user, string $state, ?string $today = null, array $choices = [], array $in = []): array
    {
        $out = ['success' => false, 'error' => '', 'message' => '', 'results' => [], 'done' => 0];
        // Cancel: one reason for every room (required); email_guest '1' / '0' (the review's tick). Mark no-show: an
        // optional note, as the single button's.
        $reason = trim((string)($in['cancellation_reason'] ?? ''));
        if ($op === 'cancel' && $reason === '') {
            $out['error'] = 'Give one reason for the cancellation. Nothing was done.';
            return $out;
        }
        $plan = rh_bk_bulk_plan($pdo, $op, $ids, (int)($user['id'] ?? 0), $today);
        if (empty($plan['success'])) {
            $out['error'] = $plan['error'];
            return $out;
        }
        if ($state === '' || !hash_equals($plan['state'], $state)) {
            $out['error'] = 'The bookings changed since you reviewed them. Nothing was done. Close this and review again.';
            return $out;
        }
        $opts = ['user_id' => (int)($user['id'] ?? 0), 'user_name' => (string)($user['full_name'] ?? ($user['username'] ?? ''))];
        $order = array_keys($plan['rooms']);
        if ($op === 'remove_group') {
            $isRoot = [];
            foreach ($plan['rooms'] as $k => $r) {
                $isRoot[$k] = rh_group_ops_root($pdo, (int)$r['id']) === (int)$r['id'] ? 1 : 0;
            }
            usort($order, static fn($a, $b) => [$isRoot[$a], $a] <=> [$isRoot[$b], $b]);
        } elseif ($op === 'assign' && isset($plan['order'])) {
            // Same order as the proposals: joined-room bookings first.
            $keyOf = [];
            foreach ($plan['rooms'] as $k => $r) {
                $keyOf[(int)$r['id']] = $k;
            }
            $order = [];
            foreach ($plan['order'] as $id) {
                $order[] = $keyOf[(int)$id];
            }
            foreach ($plan['rooms'] as $k => $r) {
                if (!in_array($k, $order, true)) {
                    $order[] = $k;
                }
            }
        }
        $results = [];
        $skipped = [];
        $done = 0;
        $taken = []; // Assign rooms: [roomId, ci, co] given in this batch
        $failVerb = ['remove_group' => 'refused', 'confirm' => 'refused', 'assign' => 'refused', 'checkin' => 'refused', 'cancel' => 'refused', 'noshow' => 'refused'][$op] ?? 'sending failed';
        $groupDone = []; // Cancel: bookingId => result of its whole-group cancel (done at the group's first ticked room)
        foreach ($order as $k) {
            $r = $plan['rooms'][$k];
            $res = ['id' => (int)$r['id'], 'label' => (string)$r['label'], 'ok' => false, 'text' => (string)$r['text']];
            if (!$r['will']) {
                adminBulkSkip($skipped, $r['why']);
                $results[$k] = $res;
                continue;
            }
            if ($op === 'cancel' && isset($groupDone[(int)$r['id']])) {
                $one = $groupDone[(int)$r['id']];
                $res['ok'] = $one['ok'];
                $res['text'] = $one['text'];
                if (!$one['ok']) {
                    adminBulkSkip($skipped, $one['why']);
                } else {
                    $done++;
                }
                $results[$k] = $res;
                continue;
            }
            try {
                if ($op === 'cancel') {
                    $one = rh_bk_bulk_cancel_one($pdo, $r, $user, $reason, (string)($in['email_guest'] ?? '0') === '1');
                    foreach ($one['others'] as $gid => $gres) {
                        $groupDone[(int)$gid] = $gres;
                    }
                    $res['ok'] = $one['ok'];
                    $res['text'] = $one['text'];
                    if (!$one['ok']) {
                        adminBulkSkip($skipped, $one['why']);
                    }
                } elseif ($op === 'noshow') {
                    $one = rh_bk_bulk_noshow_one($pdo, (int)$r['id'], $user, trim((string)($in['note'] ?? '')));
                    $res['ok'] = $one['ok'];
                    $res['text'] = $one['text'];
                    if (!$one['ok']) {
                        adminBulkSkip($skipped, $one['why']);
                    }
                } elseif ($op === 'confirm' || $op === 'assign' || $op === 'checkin') {
                    $one = rh_bk_bulk_apply_one($pdo, $op, $r, $user, $choices, $taken);
                    $res['ok'] = $one['ok'];
                    $res['text'] = $one['text'];
                    if (!$one['ok']) {
                        adminBulkSkip($skipped, $one['why']);
                    }
                } elseif ($op === 'remove_group') {
                    $g = rh_group_remove($pdo, (int)$r['id'], $opts);
                    if (!empty($g['success'])) {
                        $res['ok'] = true;
                        $res['text'] = 'Now an individual booking. No money moved.';
                    } elseif ((string)$g['error'] === 'This booking is not in a group.') {
                        // Every other room of its group was ticked and has left: it is on its own already.
                        $res['ok'] = true;
                        $res['text'] = 'Now on its own: every other room of its group was taken out. No money moved.';
                    } else {
                        $res['text'] = 'Skipped: ' . (string)$g['error'];
                        adminBulkSkip($skipped, 'refused');
                    }
                } else {
                    $rows = rh_bk_bulk_rows($pdo, [(int)$r['id']]);
                    $b = $rows[(int)$r['id']] ?? null;
                    if ($b === null) {
                        throw new Exception('This booking no longer exists.');
                    }
                    $send = $op === 'invoice'
                        ? bookings_send_invoice_email((int)$r['id'], $user)
                        : bookings_send_booking_email($pdo, $b, $op === 'reminder' ? 'booking_reminder' : rh_bk_bulk_confirmation_type((string)$b['status']), [], $user);
                    if (!empty($send['success'])) {
                        $res['ok'] = true;
                        $res['text'] = 'Sent to ' . (string)$b['guest_email'] . '.';
                    } else {
                        $res['text'] = 'Not sent: ' . (string)($send['message'] ?? 'the email could not be sent.');
                        adminBulkSkip($skipped, 'sending failed');
                    }
                }
            } catch (Throwable $e) {
                error_log('bookings bulk ' . $op . ' booking ' . (int)$r['id'] . ': ' . $e->getMessage());
                $after = '';
                if ($op === 'cancel' || $op === 'noshow') {
                    // The cancel / no-show commits before its email: an error after it must not read "nothing was changed".
                    $st = $pdo->prepare('SELECT status FROM bookings WHERE id = ?');
                    $st->execute([(int)$r['id']]);
                    $now = (string)$st->fetchColumn();
                    if ($now === ($op === 'cancel' ? 'cancelled' : 'no-show')) {
                        $after = $now;
                    }
                }
                if ($after !== '') {
                    $res['ok'] = true;
                    $res['text'] = ($op === 'cancel' ? 'Cancelled' : 'Marked no-show') . ', but something went wrong after it (' . $e->getMessage() . '). Open the booking and check its History.';
                } else {
                    $res['text'] = ($failVerb === 'refused' ? 'Skipped: nothing was changed (' : 'Not sent: ') . $e->getMessage() . ($failVerb === 'refused' ? ').' : '');
                    adminBulkSkip($skipped, $failVerb);
                }
                // A whole-group cancel is tried once (all or none): its other rooms take this outcome, never a retry.
                $grp = $op === 'cancel' ? array_map('intval', (array)($r['group'] ?? [])) : [];
                if (count($grp) > 1) {
                    $stG = $pdo->prepare('SELECT status FROM bookings WHERE id = ?');
                    foreach ($grp as $gid) {
                        if ($gid === (int)$r['id'] || isset($groupDone[$gid])) {
                            continue;
                        }
                        $stG->execute([$gid]);
                        $groupDone[$gid] = (string)$stG->fetchColumn() === 'cancelled'
                            ? ['ok' => true, 'why' => '', 'text' => 'Cancelled with its whole group, but something went wrong after it (' . $e->getMessage() . '). Open the booking and check its History.']
                            : ['ok' => false, 'why' => 'refused', 'text' => 'Skipped: its group was not cancelled, nothing was changed (' . $e->getMessage() . ').'];
                    }
                }
            }
            if ($res['ok']) {
                $done++;
            }
            $results[$k] = $res;
        }
        ksort($results);
        [$msg, $err] = adminBulkOutcome(rh_bk_bulk_ops()[$op]['verb'], $done, $skipped);
        $out['success'] = true;
        $out['done'] = $done;
        $out['message'] = $msg !== '' ? $msg : $err;
        $out['results'] = array_values($results);
        return $out;
    }

    /**
     * One room (or, for a whole ticked group, every room of it) of a bulk Cancel, through the single Cancel's own
     * bookings_cancel_commit(): same money (locked policy fee, re-checked against the figure the review showed), History
     * and guest email. No fee override in bulk. Throws what the single action throws (nothing was changed).
     * $r: the plan row (id, quoted, group, group_quoted). $email: the review's "Email the guest" tick.
     * @return array{ok:bool,why:string,text:string,others:array} others: [bookingId => {ok,why,text}] for the rest of a group.
     */
    function rh_bk_bulk_cancel_one(PDO $pdo, array $r, array $user, string $reason, bool $email): array
    {
        $id = (int)$r['id'];
        $st = $pdo->prepare('SELECT status, booking_reference FROM bookings WHERE id = ?');
        $st->execute([$id]);
        $now = $st->fetch(PDO::FETCH_ASSOC);
        if (!$now) {
            throw new Exception('This booking no longer exists.');
        }
        $group = array_map('intval', (array)($r['group'] ?? []));
        if ((string)$now['status'] === 'cancelled') {
            // Cancelled by someone else after the review was checked again: not ours, nothing done (its group neither).
            $others = [];
            foreach ($group as $gid) {
                if ($gid !== $id) {
                    $others[$gid] = ['ok' => false, 'why' => 'group changed', 'text' => 'Skipped: its group changed since the review (a room was cancelled by someone else). Nothing was changed. Review again.'];
                }
            }
            return ['ok' => false, 'why' => 'already cancelled', 'text' => 'Skipped: someone else cancelled it since the review. Nothing was changed.', 'others' => $others];
        }
        $t = validateBookingStatusTransition((string)$now['status'], 'cancelled');
        if (empty($t['allowed'])) {
            throw new Exception((string)$t['reason']);
        }
        $in = ['cancellation_reason' => $reason, 'email_guest' => $email ? '1' : '0', 'scope' => count($group) > 1 ? 'group' : 'single'];
        $quoted = count($group) > 1 ? (string)($r['group_quoted'] ?? '') : (string)($r['quoted'] ?? '');
        if ($quoted !== '') {
            $in['fee_quoted'] = $quoted;
        }
        $c = bookings_cancel_commit($pdo, $id, (string)$now['status'], $now['booking_reference'] ?? null, $user, $in);
        $per = [];
        foreach ($c['cancelled'] as $x) {
            $mail = $c['email'][(int)$x['id']] ?? '';
            $text = 'Cancelled' . (count($group) > 1 ? ' with its whole group' : '') . '. ' . trim((string)$x['summary']);
            if ($mail === 'sent') {
                $text .= ' Cancellation email sent.';
            } elseif ($mail === 'not sent') {
                $text .= ' No email sent (you chose not to).';
            } elseif ($mail !== '') {
                $text .= ' The cancellation email was not sent (' . substr($mail, 8) . ').';
            } else {
                $text .= ' No email (the booking was not confirmed).';
            }
            $per[(int)$x['id']] = ['ok' => true, 'why' => '', 'text' => $text];
        }
        if (!isset($per[$id])) {
            // Defensive: the group function did not list this room. Report what the booking says now.
            $st->execute([$id]);
            $s = (string)(($st->fetch(PDO::FETCH_ASSOC) ?: [])['status'] ?? '');
            $per[$id] = $s === 'cancelled'
                ? ['ok' => true, 'why' => '', 'text' => 'Cancelled. ' . (string)$c['message']]
                : ['ok' => false, 'why' => 'refused', 'text' => 'Skipped: nothing was changed.'];
        }
        $mine = $per[$id];
        unset($per[$id]);
        foreach ($group as $gid) {
            if ($gid !== $id && !isset($per[$gid])) {
                $per[$gid] = ['ok' => false, 'why' => 'refused', 'text' => 'Skipped: its group was cancelled without this room. Open the booking to check it.'];
            }
        }
        return $mine + ['others' => $per];
    }

    /**
     * One room of a bulk Mark no-show, through the single button's rh_mark_booking_no_show() (orders, status, rooms,
     * holds, refund in one transaction, History) and the same guest no-show email (sent here, not after the page).
     * @return array{ok:bool,why:string,text:string}
     */
    function rh_bk_bulk_noshow_one(PDO $pdo, int $id, array $user, string $note): array
    {
        require_once __DIR__ . '/../../includes/booking-functions.php';
        $ns = rh_mark_booking_no_show($pdo, $id, (int)($user['id'] ?? 0), (string)($user['full_name'] ?? ($user['username'] ?? '')), $note);
        if (empty($ns['success'])) {
            return ['ok' => false, 'why' => 'refused', 'text' => 'Skipped: nothing was changed (' . (string)$ns['error'] . ').'];
        }
        $bk = $ns['booking'];
        $rf = $ns['refund'];
        $text = 'Marked no-show. Room released.';
        if (!empty($ns['closed_orders'])) {
            $text .= ' Closed order(s) ' . implode(', ', $ns['closed_orders']) . '.';
        }
        if (!empty($rf['created'])) {
            $text .= ' Pending refund ' . $rf['refund_ref'] . ' (' . rh_bk_bulk_money((float)$rf['refund_amount']) . ') queued.';
        } elseif (!empty($rf['error'])) {
            $text .= ' WARNING: the automatic refund could not be created (' . $rf['error'] . ') - raise it manually in Payments.';
        }
        if (!empty($bk['guest_email'])) {
            require_once __DIR__ . '/../../config/email.php';
            $m = sendNoShowEmail($bk, (float)($rf['refund_amount'] ?? 0.0), (string)($rf['refund_ref'] ?? ''));
            $text .= !empty($m['success']) ? ' No-show email sent.' : ' The no-show email was not sent (' . (string)($m['message'] ?? 'unknown error') . ').';
        } else {
            $text .= ' No email address, so no email was sent.';
        }
        return ['ok' => true, 'why' => '', 'text' => $text];
    }

    /**
     * One room of a bulk Confirm / Assign rooms / Check in, through the single action's own function. Throws what the
     * single action throws (the caller turns it into "Skipped: nothing was changed (...)").
     * $r: the plan row (id, options, pick). $choices: [bookingId => 'r:ID' | 'c:ID'] the person picked in the review.
     * $taken: rooms given earlier in this batch ([roomId, ci, co]), updated here.
     * @return array{ok:bool,why:string,text:string}
     */
    function rh_bk_bulk_apply_one(PDO $pdo, string $op, array $r, array $user, array $choices, array &$taken): array
    {
        $id = (int)$r['id'];
        if ($op === 'confirm') {
            $st = $pdo->prepare('SELECT status, is_tentative FROM bookings WHERE id = ?');
            $st->execute([$id]);
            $now = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $res = ((string)($now['status'] ?? '') === 'tentative' && (int)($now['is_tentative'] ?? 0) === 1)
                ? bookings_convert_tentative_booking($pdo, $id, $user)
                : bookings_confirm_hold($pdo, $id, $user);
            $text = 'Confirmed. ' . ($res['email_ok'] ? 'Confirmation email sent.' : 'The confirmation email was not sent' . ($res['email_error'] !== '' ? ': ' . $res['email_error'] : '') . '.');
            if ($res['auto_note'] !== '') {
                $text .= ' ' . $res['auto_note'];
            }
            return ['ok' => true, 'why' => '', 'text' => $text];
        }
        if ($op === 'checkin') {
            // No answers posted: a late arrival or a room not marked clean is refused here, as in the review.
            $g = bookings_checkin_gates($pdo, $id, $user, []);
            if (empty($g['room_gate']['allowed'])) {
                return ['ok' => false, 'why' => 'room not ready', 'text' => 'Skipped: ' . (!empty($g['room_gate']['needs_confirm'])
                    ? 'the room is not marked clean yet. Use Check in on its row if the guest must go in anyway.'
                    : (string)$g['room_gate']['message'])];
            }
            bookings_checkin_commit($pdo, $g, $user, []);
            return ['ok' => true, 'why' => '', 'text' => 'Checked in to room ' . getBookingRoomLabel($id) . '. No email sent.'];
        }
        // assign
        $pick = isset($choices[$id]) && is_string($choices[$id]) ? trim($choices[$id]) : (string)($r['pick'] ?? '');
        if (!preg_match('/^[rc]:[0-9]{1,10}$/', $pick)) {
            return ['ok' => false, 'why' => 'no room chosen', 'text' => 'Skipped: no room was chosen.'];
        }
        $opt = null;
        foreach ((array)($r['options'] ?? []) as $o) {
            if ($o['value'] === $pick) {
                $opt = $o;
                break;
            }
        }
        if ($opt === null) {
            return ['ok' => false, 'why' => 'room no longer free', 'text' => 'Skipped: the room chosen is no longer free for these dates. Nothing was changed.'];
        }
        $rows = rh_bk_bulk_rows($pdo, [$id]);
        $b = $rows[$id] ?? null;
        if ($b === null) {
            throw new Exception('This booking no longer exists.');
        }
        $o = rh_bk_bulk_assign_options($pdo, $b);
        $rooms = [];
        foreach ($o['options'] as $fresh) {
            if ($fresh['value'] === $pick) {
                $rooms = $fresh['rooms'];
            }
        }
        if (!$rooms) {
            // Given to another ticked booking earlier in this batch: say so (clearer than "no longer free").
            $pickRooms = [(int)substr($pick, 2)];
            if ($pick[0] === 'c') {
                $pr = $pdo->prepare('SELECT room_a_id, room_b_id FROM room_combinations WHERE id = ?');
                $pr->execute([(int)substr($pick, 2)]);
                $pickRooms = array_map('intval', array_values($pr->fetch(PDO::FETCH_NUM) ?: []));
            }
            if ($o['ci'] !== '' && rh_bk_bulk_rooms_clash($taken, $pickRooms, $o['ci'], $o['co']) !== null) {
                return ['ok' => false, 'why' => 'room chosen twice', 'text' => 'Skipped: ' . $opt['label'] . ' was already given to another ticked booking for overlapping nights. Nothing was changed.'];
            }
            return ['ok' => false, 'why' => 'room no longer free', 'text' => 'Skipped: ' . ($o['ok'] ? 'the room chosen is no longer free for these dates.' : substr((string)$o['text'], 9)) . ' Nothing was changed.'];
        }
        $clash = rh_bk_bulk_rooms_clash($taken, $rooms, $o['ci'], $o['co']);
        if ($clash !== null) {
            return ['ok' => false, 'why' => 'room chosen twice', 'text' => 'Skipped: ' . $opt['label'] . ' was already given to another ticked booking for overlapping nights. Nothing was changed.'];
        }
        $num = (int)substr($pick, 2);
        $res = $pick[0] === 'c'
            ? bookings_assign_room_pair($pdo, $id, $num, $user)
            : bookings_assign_individual_room($pdo, $id, $num, $user, false, '');
        if (empty($res['success'])) {
            return ['ok' => false, 'why' => 'refused', 'text' => 'Skipped: ' . (string)$res['message']];
        }
        foreach ($rooms as $rid) {
            $taken[] = [(int)$rid, $o['ci'], $o['co']];
        }
        return ['ok' => true, 'why' => '', 'text' => 'Given room ' . getBookingRoomLabel($id, preg_replace('/^Rooms? /', '', (string)$opt['label'])) . '. The price did not change.'];
    }

    /**
     * One-time confirm (G5b): the review issues a token kept in the session; the confirm that carries it runs once.
     * A repeated confirm (double click, a retried request) gets the first result back and nothing is done again.
     * $store: the session array ($_SESSION['rh_bk_bulk_once']). Tokens live 3 hours, at most 30 are kept.
     */
    function rh_bk_bulk_once_issue(array &$store, string $scope): string
    {
        $now = time();
        foreach ($store as $t => $e) {
            if (!is_array($e) || ($now - (int)($e['at'] ?? 0)) > 10800) {
                unset($store[$t]);
            }
        }
        while (count($store) >= 30) {
            array_shift($store);
        }
        $token = bin2hex(random_bytes(16));
        $store[$token] = ['scope' => sha1($scope), 'at' => $now, 'state' => 'new', 'reply' => null];
        return $token;
    }

    /**
     * Run $run once for $token. Returns $run's reply; a replay returns the stored reply with replayed = true.
     * A reply with success false (nothing done) is stored too and given back as it was.
     * Unknown / expired / other-scope token: refused, nothing done.
     */
    function rh_bk_bulk_once_run(array &$store, string $token, string $scope, callable $run): array
    {
        $e = $token !== '' && isset($store[$token]) && is_array($store[$token]) ? $store[$token] : null;
        if ($e === null || !hash_equals((string)$e['scope'], sha1($scope))) {
            return ['success' => false, 'message' => 'This review has expired or no longer matches the ticked bookings. Nothing was done. Close it and review again.'];
        }
        if ($e['state'] === 'done') {
            $reply = (array)$e['reply'];
            if (!empty($reply['success'])) {
                $reply['replayed'] = true;
                $reply['message'] = 'Already done: this confirm was received before, so nothing was done again. ' . (string)($reply['message'] ?? '');
            }
            return $reply;
        }
        if ($e['state'] === 'running') {
            return ['success' => false, 'unknown' => true, 'message' => 'This confirm was already started and its result is unknown. Close this, refresh the list and check each booking\'s History before trying again.'];
        }
        $store[$token]['state'] = 'running';
        try {
            $reply = $run();
        } catch (Throwable $ex) {
            error_log('bookings bulk once-run: ' . $ex->getMessage());
            $reply = ['success' => false, 'unknown' => true, 'message' => 'Something went wrong part way, so the result is unknown. Close this, refresh the list and check each booking\'s History before trying again.'];
        }
        $store[$token]['state'] = 'done';
        $store[$token]['reply'] = $reply;
        return $reply;
    }

    /**
     * CSV export cell: a text cell that a spreadsheet would read as a formula (starts with = + - @, tab or CR) gets a
     * leading apostrophe. Numbers (-50.00) and phone numbers (+265 99 123 4567) are left as they are.
     */
    function rh_bk_bulk_csv_cell($v)
    {
        if (!is_string($v) || $v === '') {
            return $v;
        }
        if (!in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $v;
        }
        if (preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $v) || preg_match('/^\+?[0-9][0-9 ]*$/', $v)) {
            return $v;
        }
        return "'" . $v;
    }

    /**
     * Make a group / Add to a group: drop the ticked rooms that cannot join (rh_group_can_join, G4) or that sit in
     * another group, each with its reason; the lead is kept as it is (rh_group_make judges it as the primary room).
     * @return array{ids:int[],skipped:array} skipped: [{id,label,reason}]
     */
    function rh_bk_bulk_group_filter(PDO $pdo, int $leadId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        $out = ['ids' => [], 'skipped' => []];
        if (!$ids) {
            return $out;
        }
        $st = $pdo->prepare('SELECT b.id, b.booking_reference, b.guest_name, b.room_guest_name, b.status, b.deleted_at, b.primary_booking_id, b.amount_due,
                COALESCE((SELECT r.name FROM rooms r WHERE r.id = b.room_id), \'\') AS room_name,
                (SELECT COUNT(*) FROM bookings m WHERE m.primary_booking_id = b.id) AS member_count
            FROM bookings b WHERE b.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(int)$r['id']] = $r;
        }
        $leadGroup = $leadId > 0 ? rh_group_ops_rows($pdo, max(0, rh_group_ops_root($pdo, $leadId))) : [];
        foreach ($ids as $id) {
            if ($id === $leadId) {
                $out['ids'][] = $id;
                continue;
            }
            $r = $rows[$id] ?? null;
            if ($r === null) {
                $out['skipped'][] = ['id' => $id, 'label' => '#' . $id, 'reason' => 'This booking no longer exists. Reload the list.'];
                continue;
            }
            $label = rh_group_ops_label($r);
            $can = rh_group_can_join($r);
            if (!$can['ok']) {
                $out['skipped'][] = ['id' => $id, 'label' => $label, 'reason' => $can['reason']];
                continue;
            }
            if (!isset($leadGroup[$id]) && ((int)($r['primary_booking_id'] ?? 0) > 0 || (int)$r['member_count'] > 0)) {
                $out['skipped'][] = ['id' => $id, 'label' => $label, 'reason' => $label . ' is already in another group. Remove it from that group first.'];
                continue;
            }
            $out['ids'][] = $id;
        }
        return $out;
    }

    /** Ticked ids from a GET list "1,2,3" (Export selected / Print arrival list): digits only, unique, at most 500. */
    function rh_bk_bulk_get_ids($raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (preg_match('/^[0-9]{1,18}$/', $part) && (int)$part > 0) {
                $ids[(int)$part] = (int)$part;
            }
            if (count($ids) >= 500) {
                break;
            }
        }
        return array_values($ids);
    }

    /**
     * Rows for the arrival list: the ticked bookings (not deleted), by check-in date then guest. Balance = what the
     * booking still owes (amount_due; 0 for an expired booking, like the list's own rule).
     * @return array [{reference,guest,room_guest,room,check_in,check_out,nights,status,balance}]
     */
    function rh_bk_bulk_arrival_rows(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) {
            return [];
        }
        $st = $pdo->prepare('SELECT b.id, b.booking_reference, b.guest_name, b.room_guest_name, b.check_in_date, b.check_out_date,
                b.number_of_nights, b.status, b.amount_due, COALESCE(r.name, \'\') AS room_name
            FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.deleted_at IS NULL AND b.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
            ORDER BY b.check_in_date ASC, b.guest_name ASC, b.id ASC');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $unit = function_exists('getBookingRoomLabel') ? getBookingRoomLabel((int)$r['id']) : '';
            $due = strtolower((string)$r['status']) === 'expired' ? 0.0 : max(0.0, round((float)$r['amount_due'], 2));
            $out[] = [
                'reference' => (string)$r['booking_reference'], 'guest' => (string)$r['guest_name'],
                'room_guest' => trim((string)($r['room_guest_name'] ?? '')),
                'room' => trim((string)$r['room_name'] . ($unit !== '' ? ' ' . $unit : '')),
                'check_in' => substr((string)$r['check_in_date'], 0, 10), 'check_out' => substr((string)$r['check_out_date'], 0, 10),
                'nights' => (int)$r['number_of_nights'], 'status' => (string)$r['status'],
                'balance' => $due > (float)BALANCE_TOLERANCE ? $due : 0.0,
            ];
        }
        return $out;
    }

    /** Print arrival list: a plain printable page (guest, room, dates, reference, room guest, balance). Prints itself. */
    function rh_bk_bulk_print_arrivals(PDO $pdo, array $ids, string $currency, array $user): void
    {
        $rows = rh_bk_bulk_arrival_rows($pdo, $ids);
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $fmtDate = static function (string $d): string {
            $t = strtotime($d);
            return $t ? date('D j M Y', $t) : $d;
        };
        $site = function_exists('getSetting') ? (string)(getSetting('site_name') ?: '') : '';
        $owed = 0.0;
        foreach ($rows as $r) {
            $owed = round($owed + $r['balance'], 2);
        }
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Arrival list' . ($site !== '' ? ' - ' . $e($site) : '') . '</title>';
        echo '<style>
            body { font: 13px/1.4 Arial, Helvetica, sans-serif; color: black; margin: 24px; }
            h1 { font-size: 20px; margin: 0 0 4px; }
            .meta { margin: 0 0 14px; color: dimgray; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid rgba(0, 0, 0, .25); padding: 6px 8px; text-align: left; vertical-align: top; }
            th { background: rgba(0, 0, 0, .06); }
            td.num, th.num { text-align: right; white-space: nowrap; }
            tfoot td { font-weight: bold; }
            .tools { margin: 0 0 14px; }
            .tools button { font: inherit; padding: 8px 16px; min-height: 44px; cursor: pointer; }
            @media print { .tools { display: none; } body { margin: 0; } }
        </style></head><body>';
        echo '<div class="tools"><button type="button" onclick="window.print()">Print</button></div>';
        echo '<h1>Arrival list' . ($site !== '' ? ' - ' . $e($site) : '') . '</h1>';
        echo '<p class="meta">' . count($rows) . ' booking' . (count($rows) === 1 ? '' : 's') . ' &middot; printed ' . $e(date('D j M Y H:i'))
            . ' by ' . $e($user['full_name'] ?? ($user['username'] ?? '')) . ' &middot; Balance = what the booking still owes today.</p>';
        if (!$rows) {
            echo '<p>No bookings to list. Tick the bookings on All Bookings first (deleted bookings are left out).</p>';
        } else {
            echo '<table><thead><tr><th>Guest</th><th>Room guest</th><th>Room</th><th>Check-in</th><th>Check-out</th><th>Reference</th><th class="num">Balance</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr><td>' . $e($r['guest']) . '</td><td>' . $e($r['room_guest'] !== '' ? $r['room_guest'] : '-') . '</td><td>' . $e($r['room'] !== '' ? $r['room'] : '-')
                    . '</td><td>' . $e($fmtDate($r['check_in'])) . '</td><td>' . $e($fmtDate($r['check_out'])) . ' <small>(' . (int)$r['nights'] . ' night' . ((int)$r['nights'] === 1 ? '' : 's') . ')</small>'
                    . '</td><td>' . $e($r['reference']) . '</td><td class="num">' . ($r['balance'] > 0 ? $e($currency . ' ' . number_format($r['balance'], 2)) : 'Nothing owed') . '</td></tr>';
            }
            echo '</tbody><tfoot><tr><td colspan="6">Total still owed</td><td class="num">' . $e($currency . ' ' . number_format($owed, 2)) . '</td></tr></tfoot></table>';
        }
        echo '<script>window.addEventListener("load", function () { setTimeout(function () { try { window.print(); } catch (e) {} }, 300); });</script>';
        echo '</body></html>';
    }

    /**
     * Add to a group: the primary room of the group that holds the booking with reference $ref.
     * @return array{lead:int,error:string}
     */
    function rh_bk_bulk_group_lead_for_ref(PDO $pdo, string $ref): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return ['lead' => 0, 'error' => 'Enter the reference of any room in the group.'];
        }
        $st = $pdo->prepare('SELECT id FROM bookings WHERE booking_reference = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $st->execute([$ref]);
        $id = (int)$st->fetchColumn();
        if ($id <= 0) {
            return ['lead' => 0, 'error' => 'No booking has the reference ' . $ref . '. Check it and try again.'];
        }
        $root = rh_group_ops_root($pdo, $id);
        if (count(rh_group_ops_rows($pdo, $root)) < 2) {
            return ['lead' => 0, 'error' => $ref . ' is not in a group. Use Make a group instead.'];
        }
        return ['lead' => $root, 'error' => ''];
    }
}
