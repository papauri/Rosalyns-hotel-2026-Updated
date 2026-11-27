<?php
/**
 * Bookings list: hover / tap on the Status and Payment pills shows the high-level why and when (owner 2026-11-25:
 * "an expired booking says nothing until you open full details").
 *
 *   rh_bk_status_events(PDO, array $rows): booking id => what changed its status last (when, who, reason, cancellation
 *       settlement). Only for the page's cancelled / no-show / expired / checked-in / checked-out rows, ONE read-only
 *       query per log table (booking_timeline_logs, booking_audit_log, tentative_booking_log), never one per row.
 *       [] on any error (then the popup shows what the row itself says). Checked-in and checked-out rows also get
 *       ci_when / ci_who: their latest check-in, even when the last status event is the check-out.
 *
 * Check-in who and when (owner 2026-11-27: "shows whether someone has checked in and a log of time and who did it"):
 *   rh_checkin_log_merge(array $timeline, array $audit, ...): one booking's check-ins, cancelled check-ins and
 *       check-outs, oldest first, from its rows in the two log tables (pure). rh_checkin_last_in: the latest check-in.
 *   rh_bk_checkin_line(?array $ev, string $status, string $today): the list's line under the Status pill (pure).
 *   rh_checkin_state / rh_checkin_step_words: the Booking details "Check-in" block in words (pure).
 *   rh_checkin_log_load(PDO, array $booking): the Booking details log for one booking (read only).
 *   rh_bk_status_info(array $b, ?array $ev, array $o): popup data for the Status pill, from the row + its event.
 *   rh_bk_pay_info(array $b, array $o): popup data for the Payment pill, from the SAME figures the row prints (total,
 *       Balance due incl. the group / expiry-charge rules), so the popup never disagrees with the row. No query.
 *   rh_bk_pill_info_attrs(array $info, string $aria): the attributes that make a pill open the shared popup
 *       (admin/js/rh-total-info.js, textContent only).
 *
 * Read only. Never shows IP addresses or browser details from the logs.
 */

if (!function_exists('rh_bk_status_events')) {
    function rh_bk_status_events(PDO $pdo, array $rows): array
    {
        $want = ['cancelled', 'no-show', 'expired', 'checked-in', 'checked-out'];
        $status = [];
        foreach ($rows as $r) {
            $st = strtolower(trim((string)($r['status'] ?? '')));
            if (in_array($st, $want, true) && empty($r['deleted_at'])) {
                $status[(int)$r['id']] = $st;
            }
        }
        if (!$status) {
            return [];
        }
        $ids = array_keys($status);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        $put = static function (int $id, string $k, $v) use (&$out): void {
            if ($v === null || $v === '') {
                return;
            }
            $out[$id] = $out[$id] ?? [];
            $out[$id][$k] = $v;
        };
        $meta = static function ($json): array {
            $m = is_string($json) && $json !== '' ? json_decode($json, true) : null;
            return is_array($m) ? $m : [];
        };

        // The list's check-in line (rh_bk_checkin_line): every check-in row of the page's checked-in / checked-out rows,
        // from the same two queries; merged per booking below (rh_checkin_log_merge).
        $ciRows = [];
        $inHouseOrLeft = static function (string $s): bool {
            return $s === 'checked-in' || $s === 'checked-out';
        };

        try {
            // Timeline: the status change itself (reason in metadata) and, for a cancellation, how it was settled.
            $st = $pdo->prepare("SELECT booking_id, action, action_type, new_value, performed_by_type, performed_by_id, performed_by_name, metadata, created_at
                FROM booking_timeline_logs
                WHERE booking_id IN ($in)
                  AND (action_type IN ('status_change', 'check_in', 'check_out')
                       OR (action_type = 'financial' AND new_value = 'cancelled'))
                ORDER BY id ASC");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $id = (int)$t['booking_id'];
                $now = $status[$id] ?? '';
                $type = (string)$t['action_type'];
                if ($type === 'check_in' && $inHouseOrLeft($now)) {
                    $ciRows[$id]['t'][] = $t;
                }
                $m = $meta($t['metadata']);
                if ($type === 'financial') {
                    if ($now === 'cancelled') { // "Cancellation settled": the last one wins
                        $out[$id]['settle'] = [
                            'mode' => (string)($m['mode'] ?? ''),
                            'refunded' => (float)($m['refunded'] ?? 0),
                            'retained' => (float)($m['retained'] ?? 0),
                        ];
                        $put($id, 'reason_settle', trim((string)($m['reason'] ?? '')));
                        $put($id, 'when_settle', (string)$t['created_at']);
                    }
                    continue;
                }
                $hit = ($type === 'status_change' && (string)$t['new_value'] === $now)
                    || ($type === 'check_in' && $now === 'checked-in')
                    || ($type === 'check_out' && $now === 'checked-out');
                if (!$hit) {
                    continue;
                }
                $out[$id]['when'] = (string)$t['created_at'];
                $out[$id]['who'] = (string)$t['performed_by_type'] === 'system' ? 'System (automatic)' : trim((string)$t['performed_by_name']);
                $out[$id]['reason'] = trim((string)($m['reason'] ?? ''));
            }
        } catch (Throwable $e) {
            error_log('rh_bk_status_events timeline: ' . $e->getMessage());
        }

        try {
            // Audit log: the staff name and note where the timeline has none (some paths log only here).
            $st = $pdo->prepare("SELECT booking_id, action, note, performed_by, performed_by_name, performed_at
                FROM booking_audit_log
                WHERE booking_id IN ($in) AND action IN ('cancelled', 'no-show', 'expired', 'checked-in', 'checked-out')
                ORDER BY id ASC");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $id = (int)$a['booking_id'];
                if ((string)$a['action'] === 'checked-in' && $inHouseOrLeft($status[$id] ?? '')) {
                    $ciRows[$id]['a'][] = $a;
                }
                if ((string)$a['action'] !== ($status[$id] ?? '')) {
                    continue;
                }
                $out[$id]['au_when'] = (string)$a['performed_at'];
                $out[$id]['au_who'] = trim((string)$a['performed_by_name']);
                $out[$id]['au_note'] = trim((string)$a['note']);
            }
        } catch (Throwable $e) {
            error_log('rh_bk_status_events audit: ' . $e->getMessage());
        }

        if ($ciRows) {
            // A step logged without a name: the staff member's admin_users name (one query for the page, only if needed).
            $need = [];
            foreach ($ciRows as $r) {
                foreach ($r['t'] ?? [] as $t) {
                    if (trim((string)$t['performed_by_name']) === '' && (int)$t['performed_by_id'] > 0) {
                        $need[(int)$t['performed_by_id']] = true;
                    }
                }
                foreach ($r['a'] ?? [] as $a) {
                    if (trim((string)$a['performed_by_name']) === '' && (int)$a['performed_by'] > 0) {
                        $need[(int)$a['performed_by']] = true;
                    }
                }
            }
            $names = $need ? rh_checkin_staff_names($pdo, array_keys($need)) : [];
            foreach ($ciRows as $id => $r) {
                $tl = array_map(static function ($t) use ($names) {
                    return $t + ['staff_name' => $names[(int)$t['performed_by_id']] ?? ''];
                }, $r['t'] ?? []);
                $au = array_map(static function ($a) use ($names) {
                    return $a + ['staff_name' => $names[(int)$a['performed_by']] ?? ''];
                }, $r['a'] ?? []);
                $last = rh_checkin_last_in(rh_checkin_log_merge($tl, $au));
                if ($last !== null) {
                    $out[$id]['ci_when'] = $last['when'];
                    $out[$id]['ci_who'] = $last['who'];
                }
            }
        }

        $expired = array_keys(array_filter($status, static function ($s) { return $s === 'expired'; }));
        if ($expired) {
            try {
                // A tentative hold expired by hand (admin/api/tentative-actions.php) or by tentative-bookings.php logs here.
                $in2 = implode(',', array_fill(0, count($expired), '?'));
                $st = $pdo->prepare("SELECT l.booking_id, l.action_reason, l.created_at, COALESCE(NULLIF(u.full_name, ''), u.username) AS staff_name
                    FROM tentative_booking_log l LEFT JOIN admin_users u ON u.id = l.performed_by
                    WHERE l.booking_id IN ($in2) AND l.action = 'expired' ORDER BY l.id ASC");
                $st->execute($expired);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
                    $id = (int)$l['booking_id'];
                    $out[$id]['tl_when'] = (string)$l['created_at'];
                    $out[$id]['tl_who'] = trim((string)($l['staff_name'] ?? ''));
                    // markTentativeBookingExpired() stores a JSON blob here (previous/new status), not words: never shown.
                    $why = trim((string)$l['action_reason']);
                    $out[$id]['tl_reason'] = ($why !== '' && is_array(json_decode($why, true))) ? '' : $why;
                }
            } catch (Throwable $e) {
                error_log('rh_bk_status_events tentative: ' . $e->getMessage());
            }
        }
        return $out;
    }
}

if (!function_exists('rh_bk_info_when')) {
    /** "Mon 9 Nov 2026, 13:53" ('' for an empty / bad date). */
    function rh_bk_info_when(?string $d, bool $withTime = true): string
    {
        $t = ($d !== null && trim($d) !== '' && strpos($d, '0000-00-00') !== 0) ? strtotime($d) : false;
        return $t ? date($withTime ? 'D j M Y, H:i' : 'D j M Y', $t) : '';
    }
}

if (!function_exists('rh_bk_status_info')) {
    /**
     * @param array $b  the bookings row (b.* of the list query)
     * @param array|null $ev rh_bk_status_events()[id]
     * @param array $o  currency, today (Y-m-d), pending_hours (int, 0 = pending bookings never lapse), left (Balance due shown)
     * @return array{title:string,lines:array,note:string}
     */
    function rh_bk_status_info(array $b, ?array $ev, array $o): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $cur = trim((string)($o['currency'] ?? ''));
        $money = static function (float $v) use ($cur): string {
            return ($cur !== '' ? $cur . ' ' : '') . number_format($v, 2);
        };
        $today = (string)($o['today'] ?? date('Y-m-d'));
        $ev = $ev ?? [];
        $st = strtolower(trim((string)($b['status'] ?? '')));
        $lines = [];
        $note = '';
        // kind 'text': words that wrap in the popup (a reason can be long); amounts read the same either way.
        $line = static function (string $label, string $value, string $hint = '') use (&$lines): void {
            if ($value !== '') {
                $lines[] = ['label' => $label, 'value' => $value, 'hint' => $hint, 'kind' => 'text'];
            }
        };
        // A reason that only repeats the status ("cancelled") says nothing.
        $reasonOf = static function (string ...$c) use ($st): string {
            foreach ($c as $r) {
                $r = trim($r);
                if ($r !== '' && strtolower($r) !== $st && strtolower($r) !== 'no reason given') {
                    return $r;
                }
            }
            return '';
        };
        $paid = round(max(0.0, (float)($b['amount_paid'] ?? 0)), 2);
        $left = round(max(0.0, (float)($o['left'] ?? 0)), 2);
        $credit = round(max(0.0, (float)($b['credit_balance'] ?? 0)), 2);
        $who = static function (string ...$c): string {
            foreach ($c as $w) {
                if (trim($w) !== '') {
                    return trim($w);
                }
            }
            return '';
        };

        switch ($st) {
            case 'expired':
                $title = 'Expired';
                $when = $ev['when'] ?? ($ev['tl_when'] ?? (string)($b['expired_at'] ?? ''));
                $line('Expired on', rh_bk_info_when($when));
                // No staff name in any log = the automatic lapse (the only expiry paths without a staff member).
                $line('By', $who($ev['who'] ?? '', $ev['tl_who'] ?? '', $ev['au_who'] ?? '', 'System (automatic)'));
                $reason = $reasonOf($ev['reason'] ?? '', $ev['tl_reason'] ?? '', $ev['au_note'] ?? '');
                if ($reason === '') {
                    $held = (string)($b['tentative_expires_at'] ?? '');
                    $reason = $held !== ''
                        ? 'The tentative hold ran out (held until ' . rh_bk_info_when($held) . ') without being confirmed'
                        : 'Not paid or confirmed in time';
                }
                $line('Why', $reason);
                $line('Room', 'Released, free to book again');
                if ($left > $tol) {
                    $line('Expiry charge owed', $money($left));
                }
                if ($paid > $tol) {
                    $line('Paid', $money($paid));
                }
                $note = $left > $tol ? 'The group expiry charge is still owed.' : 'Nothing is owed on an expired booking.';
                break;

            case 'cancelled':
                $title = (float)($b['amount_due'] ?? 0) > $tol ? 'Cancelled, fee owed' : 'Cancelled';
                $line('Cancelled on', rh_bk_info_when($ev['when'] ?? ($ev['au_when'] ?? ($ev['when_settle'] ?? ''))));
                $line('By', $who($ev['who'] ?? '', $ev['au_who'] ?? ''));
                $line('Reason', $reasonOf($ev['reason'] ?? '', $ev['reason_settle'] ?? '', $ev['au_note'] ?? ''));
                $settle = $ev['settle'] ?? null;
                if (is_array($settle) && $settle['mode'] !== '') {
                    $modes = [
                        'void_refund_all' => 'Bill voided, money paid refunded',
                        'void_keep_credit' => 'Bill voided, money paid kept as guest credit',
                        'keep_first_night' => 'First night charged, the rest refunded',
                    ];
                    $line('Settled as', $modes[$settle['mode']] ?? ucfirst(str_replace('_', ' ', $settle['mode'])));
                    if ($settle['refunded'] > $tol) {
                        $line('Refunded', $money((float)$settle['refunded']));
                    }
                }
                $kept = round(max(0.0, (float)($b['cancellation_retained_amount'] ?? 0)), 2);
                if ($kept > $tol) {
                    $line('Kept as cancellation fee', $money($kept));
                }
                if ($left > $tol) {
                    $line('Fee still owed', $money($left));
                }
                if ($credit > $tol) {
                    $line('Credit owed to guest', $money($credit));
                }
                $note = 'The room was released when the booking was cancelled.';
                break;

            case 'no-show':
                $title = 'No-show';
                $line('Marked on', rh_bk_info_when($ev['when'] ?? ($ev['au_when'] ?? '')));
                $line('By', $who($ev['who'] ?? '', $ev['au_who'] ?? ''));
                $line('Reason', $reasonOf($ev['reason'] ?? '', $ev['au_note'] ?? ''));
                $line('Was due', rh_bk_info_when((string)($b['check_in_date'] ?? ''), false));
                if ($left > $tol) {
                    $line('Still owed', $money($left));
                }
                if ($paid > $tol) {
                    $line('Paid', $money($paid));
                }
                $note = 'The guest did not arrive; the room was released.';
                break;

            case 'tentative':
                $title = 'Tentative (on hold)';
                $exp = (string)($b['tentative_expires_at'] ?? '');
                $line('Held until', rh_bk_info_when($exp));
                if ($exp !== '' && strtotime($exp) > time()) {
                    $hrs = (int)floor((strtotime($exp) - time()) / 3600);
                    $line('Time left', $hrs >= 48 ? (int)floor($hrs / 24) . ' days' : ($hrs >= 1 ? $hrs . ' hours' : 'Under an hour'));
                }
                if ((float)($b['deposit_amount'] ?? 0) > $tol && !empty($b['deposit_required'])) {
                    $line('Deposit asked', $money((float)$b['deposit_amount']), !empty($b['deposit_paid']) ? 'paid' : 'not paid yet');
                }
                $line('Reminder sent', rh_bk_info_when((string)($b['reminder_sent_at'] ?? '')));
                $line('Quotation sent', rh_bk_info_when((string)($b['last_quotation_sent_at'] ?? '')));
                $note = 'Not confirmed yet. If it is not confirmed by the hold time it expires and the room is released.';
                break;

            case 'pending':
                $title = 'Pending';
                $line('Booked on', rh_bk_info_when((string)($b['created_at'] ?? '')));
                $hours = (int)($o['pending_hours'] ?? 0);
                $hasMoney = $paid > $tol || in_array((string)($b['payment_status'] ?? ''), ['partial', 'paid'], true) || !empty($b['deposit_paid']);
                if ($hours > 0 && !$hasMoney && (string)($b['check_in_date'] ?? '') >= $today) {
                    $lapse = strtotime((string)($b['created_at'] ?? '')) + $hours * 3600;
                    $line('Lapses on', $lapse > 0 ? date('D j M Y, H:i', $lapse) : '', 'if nothing is paid or confirmed');
                }
                $note = 'Waiting for payment or confirmation.';
                break;

            case 'confirmed':
                $title = 'Confirmed';
                $line('Confirmed on', rh_bk_info_when((string)($b['converted_to_confirmed_at'] ?? '')));
                $ci = (string)($b['check_in_date'] ?? '');
                if ($ci !== '' && $ci < $today) {
                    $days = (int)floor((strtotime($today) - strtotime($ci)) / 86400);
                    $line('Arrival missed', rh_bk_info_when($ci, false), $days . ' day' . ($days === 1 ? '' : 's') . ' ago, not checked in');
                } elseif ($ci === $today) {
                    $line('Arrives', 'Today');
                } elseif ($ci !== '') {
                    $days = (int)floor((strtotime($ci) - strtotime($today)) / 86400);
                    $line('Arrives', rh_bk_info_when($ci, false), 'in ' . $days . ' day' . ($days === 1 ? '' : 's'));
                }
                if (function_exists('rh_unpaid_confirmed_deadline')) {
                    $payBy = rh_unpaid_confirmed_deadline($b);
                    if ($payBy !== null) {
                        $line('Pay by', rh_bk_info_when($payBy), 'or the room is released');
                    }
                }
                if ($left > $tol) {
                    $line('Still owed', $money($left));
                }
                break;

            case 'checked-in':
                $title = 'Checked in';
                $line('Checked in', rh_bk_info_when($ev['when'] ?? ($ev['au_when'] ?? '')));
                $line('By', $who($ev['who'] ?? '', $ev['au_who'] ?? ''));
                $co = (string)($b['check_out_date'] ?? '');
                $line('Leaves', rh_bk_info_when($co, false), $co !== '' && $co < $today ? 'departure date passed' : '');
                if ($left > $tol) {
                    $line('Still owed', $money($left), 'settle before checkout');
                }
                break;

            case 'checked-out':
                $title = 'Checked out';
                $line('Checked out', rh_bk_info_when((string)($b['checkout_completed_at'] ?? '') ?: ($ev['when'] ?? ($ev['au_when'] ?? ''))));
                $line('By', $who($ev['who'] ?? '', $ev['au_who'] ?? ''));
                $line('Final invoice', trim((string)($b['final_invoice_number'] ?? '')));
                if ($left > $tol) {
                    $line('Still owed', $money($left));
                }
                if ($credit > $tol) {
                    $line('Credit owed to guest', $money($credit));
                }
                break;

            default:
                $title = ucfirst(str_replace('-', ' ', $st));
        }
        if (!$lines && $note === '') {
            $note = 'Open the booking for its full history.';
        }
        return ['title' => $title, 'lines' => $lines, 'note' => $note];
    }
}

if (!function_exists('rh_bk_pay_info')) {
    /**
     * @param array $b the bookings row (with payment_reference / last_payment_date from the list query)
     * @param array $o currency, total (rh_booking_full_total), left (Balance due shown), group_note, pay (rh_booking_pay_state),
     *                 arrangement (company / pay-later words, '' = none)
     */
    function rh_bk_pay_info(array $b, array $o): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $cur = trim((string)($o['currency'] ?? ''));
        $money = static function (float $v) use ($cur): string {
            return ($cur !== '' ? $cur . ' ' : '') . number_format($v, 2);
        };
        $pay = is_array($o['pay'] ?? null) ? $o['pay'] : [];
        $key = (string)($pay['key'] ?? '');
        $st = strtolower(trim((string)($b['status'] ?? '')));
        $paid = round(max(0.0, (float)($b['amount_paid'] ?? 0)), 2);
        $left = round(max(0.0, (float)($o['left'] ?? 0)), 2);
        $credit = round(max(0.0, (float)($b['credit_balance'] ?? 0)), 2);
        $closed = in_array($st, ['cancelled', 'no-show', 'expired'], true);
        $lines = [];
        $line = static function (string $label, string $value, string $hint = '', string $kind = 'add') use (&$lines): void {
            if ($value !== '') {
                $lines[] = ['label' => $label, 'value' => $value, 'hint' => $hint, 'kind' => $kind];
            }
        };

        $line('Booking total', $money((float)($o['total'] ?? 0)), $closed && $left <= $tol ? 'not charged now' : '');
        $line('Paid so far', $money($paid));
        $line('Balance due', $money($left), (string)($o['group_note'] ?? ''), 'total');
        if ($credit > $tol) {
            $line('Credit owed to guest', $money($credit), 'paid more than the bill');
        }
        $ref = trim((string)($b['payment_reference'] ?? ''));
        if ($ref !== '') {
            $line('Latest payment', $ref, rh_bk_info_when((string)($b['last_payment_date'] ?? ''), false), 'text');
        }
        if (!empty($b['deposit_required']) && (float)($b['deposit_amount'] ?? 0) > $tol) {
            $line('Deposit', $money((float)$b['deposit_amount']), !empty($b['deposit_paid']) ? 'paid' . (($dp = rh_bk_info_when((string)($b['deposit_paid_at'] ?? ''), false)) !== '' ? ' ' . $dp : '') : 'not paid yet');
        }
        if ($st === 'confirmed' && function_exists('rh_unpaid_confirmed_deadline')) {
            $payBy = rh_unpaid_confirmed_deadline($b);
            if ($payBy !== null) {
                $line('Pay by', rh_bk_info_when($payBy), 'or the room is released', 'text');
            }
        }
        $arr = trim((string)($o['arrangement'] ?? ''));
        if ($arr !== '') {
            $line('Arrangement', $arr, '', 'text');
        }

        switch ($key) {
            case 'paid':
                $note = $credit > $tol ? 'Nothing left to pay; the guest paid more than the bill.' : 'Nothing left to pay.';
                $title = 'Paid in full';
                break;
            case 'partial':
                $note = 'Part of the bill is paid; ' . $money($left) . ' is still owed.';
                $title = 'Part paid';
                break;
            case 'refunded':
                $note = 'The money paid on this booking has all been given back.';
                $title = 'Refunded';
                break;
            default:
                $title = 'Unpaid';
                if ($left > $tol) {
                    $note = 'Nothing paid yet; ' . $money($left) . ' is owed.';
                } elseif ($st === 'expired') {
                    $note = 'Nothing paid and nothing owed: the booking expired, so the bill was dropped.';
                } elseif ($st === 'cancelled') {
                    $note = 'Nothing paid and nothing owed: the bill was voided when it was cancelled.';
                } else {
                    $note = 'Nothing paid and nothing owed.';
                }
        }
        return ['title' => 'Payment: ' . $title, 'lines' => $lines, 'note' => $note];
    }
}

if (!function_exists('rh_bk_pill_info_attrs')) {
    /** Attributes for a <button class="badge ... rh-pill-info"> that opens the shared "i" popup ('' if it cannot be encoded). */
    function rh_bk_pill_info_attrs(array $info, string $aria): string
    {
        $json = json_encode($info, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return '';
        }
        return ' aria-haspopup="dialog" aria-expanded="false" aria-label="' . htmlspecialchars($aria, ENT_QUOTES, 'UTF-8')
            . '" data-rh-breakdown="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"';
    }
}

if (!function_exists('rh_checkin_who')) {
    /**
     * Who did a check-in / check-out step, in plain words: "guest", "system" or "API" from the timeline's
     * performed_by_type, else the staff name logged with the step, else their admin_users name ($staffName).
     * '' when no name was recorded.
     */
    function rh_checkin_who(string $type, string $name, string $staffName = ''): string
    {
        $type = strtolower(trim($type));
        if ($type === 'guest' || $type === 'system') {
            return $type;
        }
        if ($type === 'api') {
            return 'API';
        }
        $name = trim($name);
        return $name !== '' ? $name : trim($staffName);
    }
}

if (!function_exists('rh_checkin_log_merge')) {
    /**
     * One booking's check-in log, oldest first, from its rows in the two log tables. Pure (no DB).
     *   $timeline  booking_timeline_logs rows: action_type, new_value, performed_by_type, performed_by_name, created_at
     *              (+ staff_name: the admin_users name of performed_by_id). A check-in is action_type 'check_in' with
     *              new_value 'checked-in' (logBookingCheckIn); the other check_in rows are notes on it ("Late check-in",
     *              "Checked in to a room not marked clean"). A check-out is any 'check_out' row ("Guest checked out",
     *              "Checkout settlement").
     *   $audit     booking_audit_log rows: action 'checked-in' / 'check-in cancelled' / 'checked-out', performed_by_name,
     *              performed_at (+ staff_name). A cancelled check-in is logged only here.
     *   $checkoutAt, $checkoutBy  bookings.checkout_completed_at and who did it: a check-out step only when no log row
     *              has one.
     * Most steps are logged in both tables a second or two apart: an audit row up to 120 seconds from a timeline row of
     * the same kind is that same step (it only fills a missing name). Rows of one table never merge with each other, so a
     * check-in, a cancel and a check-in again within two minutes stay three steps. Same second: check-in, then cancelled,
     * then check-out (a completed stay recorded late logs its check-in and check-out together).
     * @return list<array{kind:string, when:string, who:string}> kind 'in' | 'undo' | 'out'; when as logged (hotel time);
     *         who '' = not recorded
     */
    function rh_checkin_log_merge(array $timeline, array $audit, ?string $checkoutAt = null, string $checkoutBy = ''): array
    {
        $ts = static function ($d): ?int {
            $d = trim((string)$d);
            if ($d === '' || strpos($d, '0000-00-00') === 0) {
                return null;
            }
            $t = strtotime($d);
            return $t === false ? null : $t;
        };
        $steps = [];
        foreach ($timeline as $t) {
            $type = (string)($t['action_type'] ?? '');
            if ($type === 'check_in' && trim((string)($t['new_value'] ?? '')) === 'checked-in') {
                $kind = 'in';
            } elseif ($type === 'check_out') {
                $kind = 'out';
            } else {
                continue;
            }
            $at = $ts($t['created_at'] ?? null);
            if ($at === null) {
                continue;
            }
            $steps[] = [
                'kind' => $kind, 'at' => $at, 'when' => trim((string)$t['created_at']), 'tl' => true, 'paired' => false,
                'who' => rh_checkin_who((string)($t['performed_by_type'] ?? ''), (string)($t['performed_by_name'] ?? ''), (string)($t['staff_name'] ?? '')),
            ];
        }
        $kinds = ['checked-in' => 'in', 'check-in cancelled' => 'undo', 'checked-out' => 'out'];
        foreach ($audit as $a) {
            $kind = $kinds[(string)($a['action'] ?? '')] ?? null;
            $at = $ts($a['performed_at'] ?? null);
            if ($kind === null || $at === null) {
                continue;
            }
            $who = rh_checkin_who('admin', (string)($a['performed_by_name'] ?? ''), (string)($a['staff_name'] ?? ''));
            $pair = null;
            foreach ($steps as $i => $s) {
                if ($s['tl'] && !$s['paired'] && $s['kind'] === $kind && abs($s['at'] - $at) <= 120
                    && ($pair === null || abs($s['at'] - $at) < abs($steps[$pair]['at'] - $at))) {
                    $pair = $i;
                }
            }
            if ($pair !== null) {
                $steps[$pair]['paired'] = true;
                if ($steps[$pair]['who'] === '') {
                    $steps[$pair]['who'] = $who;
                }
                continue;
            }
            $steps[] = ['kind' => $kind, 'at' => $at, 'when' => trim((string)$a['performed_at']), 'who' => $who, 'tl' => false, 'paired' => true];
        }
        $hasOut = false;
        foreach ($steps as $s) {
            $hasOut = $hasOut || $s['kind'] === 'out';
        }
        if (!$hasOut && ($at = $ts($checkoutAt)) !== null) {
            $steps[] = ['kind' => 'out', 'at' => $at, 'when' => trim((string)$checkoutAt), 'who' => trim($checkoutBy), 'tl' => false, 'paired' => true];
        }
        $rank = ['in' => 0, 'undo' => 1, 'out' => 2];
        $order = array_keys($steps);
        usort($order, static function ($x, $y) use ($steps, $rank) {
            return [$steps[$x]['at'], $rank[$steps[$x]['kind']], $x] <=> [$steps[$y]['at'], $rank[$steps[$y]['kind']], $y];
        });
        $log = [];
        foreach ($order as $i) {
            $log[] = ['kind' => $steps[$i]['kind'], 'when' => $steps[$i]['when'], 'who' => $steps[$i]['who']];
        }
        return $log;
    }

    /** The latest check-in step of rh_checkin_log_merge() (null when there is none). */
    function rh_checkin_last_in(array $log): ?array
    {
        for ($i = count($log) - 1; $i >= 0; $i--) {
            if (($log[$i]['kind'] ?? '') === 'in') {
                return $log[$i];
            }
        }
        return null;
    }
}

if (!function_exists('rh_bk_checkin_line')) {
    /**
     * Bookings list: the small line under the Status pill of a checked-in or checked-out row. "Checked in 27 Nov 14:05
     * by Ann Banda" (hotel time; the year too when it is not this year's), "... (name not recorded)", or "Checked in:
     * time not recorded" when no log row has the check-in (e.g. a booking copied in from another system). '' for every
     * other status. $ev = rh_bk_status_events()[id] (ci_when, ci_who); $today 'Y-m-d' (the hotel date).
     */
    function rh_bk_checkin_line(?array $ev, string $status, string $today): string
    {
        if ($status !== 'checked-in' && $status !== 'checked-out') {
            return '';
        }
        $when = trim((string)($ev['ci_when'] ?? ''));
        $t = ($when !== '' && strpos($when, '0000-00-00') !== 0) ? strtotime($when) : false;
        if (!$t) {
            return 'Checked in: time not recorded';
        }
        $line = 'Checked in ' . date(date('Y', $t) === substr($today, 0, 4) ? 'j M H:i' : 'j M Y H:i', $t);
        $who = trim((string)($ev['ci_who'] ?? ''));
        return $line . ($who !== '' ? ' by ' . $who : ' (name not recorded)');
    }
}

if (!function_exists('rh_checkin_state')) {
    /** Booking details "Check-in" block: where the guest is now, from the booking status (label + rh-pill modifier). */
    function rh_checkin_state(string $status): array
    {
        if ($status === 'checked-in') {
            return ['label' => 'Checked in', 'class' => 'ok'];
        }
        if ($status === 'checked-out') {
            return ['label' => 'Checked out', 'class' => 'muted'];
        }
        return ['label' => 'Not checked in', 'class' => 'muted'];
    }

    /** One step of rh_checkin_log_merge() in words: what ("Checked in"), when ("Thu 12 Nov 2026, 14:06"), who ("by Ann Banda"). */
    function rh_checkin_step_words(array $step): array
    {
        $who = trim((string)($step['who'] ?? ''));
        return [
            'what' => ['in' => 'Checked in', 'undo' => 'Check-in cancelled', 'out' => 'Checked out'][(string)($step['kind'] ?? '')] ?? '',
            'when' => rh_bk_info_when((string)($step['when'] ?? '')),
            'who' => $who !== '' ? 'by ' . $who : 'name not recorded',
        ];
    }
}

if (!function_exists('rh_checkin_staff_names')) {
    /** admin_users id => full name (else username), read only; [] on error. */
    function rh_checkin_staff_names(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($v) {
            return $v > 0;
        })));
        if (!$ids) {
            return [];
        }
        $names = [];
        try {
            $st = $pdo->prepare('SELECT id, COALESCE(NULLIF(full_name, \'\'), username) AS staff_name FROM admin_users WHERE id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $names[(int)$u['id']] = trim((string)$u['staff_name']);
            }
        } catch (Throwable $e) {
            error_log('rh_checkin_staff_names: ' . $e->getMessage());
        }
        return $names;
    }
}

if (!function_exists('rh_checkin_log_load')) {
    /**
     * Booking details "Check-in" block: rh_checkin_log_merge() for one booking ($booking = its b.* row). Read only: one
     * query per log table (+ the checkout user's name only when no log row has the check-out). [] when nothing is
     * logged; a failed query leaves its table out.
     */
    function rh_checkin_log_load(PDO $pdo, array $booking): array
    {
        $id = (int)($booking['id'] ?? 0);
        if ($id <= 0) {
            return [];
        }
        $tl = [];
        $au = [];
        try {
            $st = $pdo->prepare("SELECT t.action_type, t.new_value, t.performed_by_type, t.performed_by_name, t.created_at,
                    COALESCE(NULLIF(u.full_name, ''), u.username) AS staff_name
                FROM booking_timeline_logs t
                LEFT JOIN admin_users u ON u.id = t.performed_by_id
                WHERE t.booking_id = ? AND t.action_type IN ('check_in', 'check_out')
                ORDER BY t.id ASC");
            $st->execute([$id]);
            $tl = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('rh_checkin_log_load timeline: ' . $e->getMessage());
        }
        try {
            $st = $pdo->prepare("SELECT a.action, a.performed_by_name, a.performed_at,
                    COALESCE(NULLIF(u.full_name, ''), u.username) AS staff_name
                FROM booking_audit_log a
                LEFT JOIN admin_users u ON u.id = a.performed_by
                WHERE a.booking_id = ? AND a.action IN ('checked-in', 'check-in cancelled', 'checked-out')
                ORDER BY a.id ASC");
            $st->execute([$id]);
            $au = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('rh_checkin_log_load audit: ' . $e->getMessage());
        }
        $outAt = trim((string)($booking['checkout_completed_at'] ?? ''));
        $outBy = '';
        $byId = (int)($booking['checkout_processed_by'] ?? 0);
        if ($outAt !== '' && $byId > 0
            && !in_array('check_out', array_column($tl, 'action_type'), true) && !in_array('checked-out', array_column($au, 'action'), true)) {
            $outBy = rh_checkin_staff_names($pdo, [$byId])[$byId] ?? '';
        }
        return rh_checkin_log_merge($tl, $au, $outAt !== '' ? $outAt : null, $outBy);
    }
}
