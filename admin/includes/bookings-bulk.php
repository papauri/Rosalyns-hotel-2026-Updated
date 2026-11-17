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
 * Make a group / Add to a group stay on group_make_preview / group_make_apply (rh_group_make); this file only gives
 * them the per-room filter (rh_bk_bulk_group_filter) so a room that cannot join is skipped with its reason.
 *
 * Pairs with admin/includes/bulk-actions.php (ids whitelist, outcome text) and admin/js/admin-bulk.js (the bar).
 */

require_once __DIR__ . '/bulk-actions.php';
require_once __DIR__ . '/../../includes/booking-group-ops.php';

if (!function_exists('rh_bk_bulk_ops')) {

    /** Bulk ops: label, the single action whose permission applies, the outcome verb, the most rooms in one go. */
    function rh_bk_bulk_ops(): array
    {
        return [
            'remove_group' => ['label' => 'Remove from group', 'single' => 'group_op_apply', 'verb' => 'taken out of their group', 'max' => 200],
            'reminder' => ['label' => 'Send a reminder', 'single' => 'resend_email', 'verb' => 'sent', 'max' => 50],
            'confirmation' => ['label' => 'Resend confirmation', 'single' => 'resend_email', 'verb' => 'sent', 'max' => 50],
            'invoice' => ['label' => 'Email invoice', 'single' => 'send_invoice_email', 'verb' => 'sent', 'max' => 50],
        ];
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
    function rh_bk_bulk_room_plan(PDO $pdo, string $op, array $b, ?string $today = null): array
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
        }
        return $skip('unknown action', 'Skipped: unknown action.');
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
        $need = function_exists('rhBookingActionPermission') ? rhBookingActionPermission($ops[$op]['single']) : null;
        $denied = $need !== null && !hasPermission($userId, $need);
        $deniedText = '';
        if ($denied) {
            $all = function_exists('getAllPermissions') ? getAllPermissions() : [];
            $deniedText = 'Skipped: you do not have permission to do that (' . ($all[$need]['label'] ?? $need) . ').';
        }
        $rows = rh_bk_bulk_rows($pdo, $ids);
        foreach ($ids as $id) {
            $b = $rows[$id] ?? null;
            if ($b === null) {
                $p = ['will' => false, 'why' => 'not found', 'text' => 'Skipped: this booking no longer exists. Reload the list.'];
                $label = '#' . $id;
            } else {
                $label = rh_group_ops_label($b);
                $p = $denied ? ['will' => false, 'why' => 'no permission', 'text' => $deniedText] : rh_bk_bulk_room_plan($pdo, $op, $b, $today);
            }
            $out['rooms'][] = ['id' => $id, 'label' => $label, 'will' => (bool)$p['will'], 'why' => (string)$p['why'], 'text' => (string)$p['text']];
            $out[$p['will'] ? 'will' : 'skip']++;
        }
        $out['state'] = sha1($op . '|' . json_encode(array_map(static fn($r) => [$r['id'], $r['will'], $r['text']], $out['rooms'])));
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
    function rh_bk_bulk_apply(PDO $pdo, string $op, array $ids, array $user, string $state, ?string $today = null): array
    {
        $out = ['success' => false, 'error' => '', 'message' => '', 'results' => [], 'done' => 0];
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
        }
        $results = [];
        $skipped = [];
        $done = 0;
        foreach ($order as $k) {
            $r = $plan['rooms'][$k];
            $res = ['id' => (int)$r['id'], 'label' => (string)$r['label'], 'ok' => false, 'text' => (string)$r['text']];
            if (!$r['will']) {
                adminBulkSkip($skipped, $r['why']);
                $results[$k] = $res;
                continue;
            }
            try {
                if ($op === 'remove_group') {
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
                $res['text'] = ($op === 'remove_group' ? 'Skipped: nothing was changed (' : 'Not sent: ') . $e->getMessage() . ($op === 'remove_group' ? ').' : '');
                adminBulkSkip($skipped, $op === 'remove_group' ? 'refused' : 'sending failed');
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
