<?php

/**
 * Money in the chat: read, never moved (owner 2026-10-29: "do not touch money. You can show finances based on
 * permissions, but do not do money actions").
 *
 *  - Money actions ("take 50,000 cash from him", "record a payment for Mr Banda", "mark them no-show", "refund the
 *    guest", "charge it to the room", "pay the balance") are never prepared or done here. One line says where it is
 *    done, with the screen's link; where this person may see them, the live figures come along (total, paid, balance,
 *    last payment; for a no-show what the hotel's no-show rule would keep and queue for refund - worked out read-only
 *    with the same rule as the no-show itself). No card, no write path, zero tokens.
 *  - Money reads ("is it fully paid", "what's left to pay", "how much did they pay", "deposit paid?", "when was the last
 *    payment") for one room booking (the booking in focus, "them", a name or a reference), a gym member (their bills), a
 *    conference or event booking, and a till / room-service order. Each behind the lookup that already shows those
 *    figures on its screen, with that lookup's own permission: no finance permission where the screen needs none.
 * Figures come from the existing helpers (getBookingFolioSummary through rh_ai_folio, the lookups' own sums); "fully
 * paid" is decided with BALANCE_TOLERANCE. Nothing here writes.
 */

require_once __DIR__ . '/ai-core.php';

/** The message as typed, lower case, polite wrappers off ("please", "can you"). */
function rh_ai_money_clean(string $m): string
{
    return mb_strtolower(function_exists('rh_ai_desk_clean') ? rh_ai_desk_clean($m) : trim($m));
}

/** A question (not an instruction): starts with a question word, or ends with "?". */
function rh_ai_money_is_question(string $l): bool
{
    return (bool)preg_match('/^(how|what|whats|what\'s|why|when|where|which|who|whose|can|could|should|did|does|do|is|are|was|were|has|have|had|show|list|tell|any)\b/u', $l)
        || substr(rtrim($l), -1) === '?';
}

/**
 * A request to MOVE money, from the person's own words (never from words read as typos): ['kind' => payment|refund|
 * noshow|charge, 'who' => name as written or '' (the booking in focus)] or null. Questions ("has he paid?", "how do I
 * mark a no-show") are not requests.
 */
function rh_ai_money_action_intent(string $raw): ?array
{
    $l = rh_ai_money_clean($raw);
    if ($l === '' || mb_strlen($l) > 200 || rh_ai_money_is_question($l)) {
        return null;
    }
    $amt = '(?:\d[\d,\.]*\s*(?:k|mwk|kwacha|usd|dollars?)?)';
    $kind = null;
    $feeNoun = '(?:cancell?ation|cancell?ing|no[\s\-]?show)\s+(?:fees?|charges?|penalty|penalties)\b';
    if (preg_match('/^(?:waive|lower|reduce|drop|remove|forgive|write off|discount)\b.{0,50}\b' . $feeNoun . '/u', $l)
        || preg_match('/^(?:cancel|scrap|clear|cut)\s+(?:the |their |his |her |this |that |a |any )?' . $feeNoun . '/u', $l)) {
        $kind = 'waive'; // lowering / waiving a cancellation or no-show fee (2026-11-14: done on Booking Details, needs waive_cancellation_fee)
    } elseif (preg_match('/\bno[\s\-]?shows?\b/u', $l) && preg_match('/^(?:mark|set|make|record|log|put|flag|change|move|turn|register)\b|^no[\s\-]?show\b/u', $l)) {
        $kind = 'noshow';
    } elseif (preg_match('/^(?:refund|reverse (?:the |a |their |his |her |that )?payment|cancel the payment|give (?:back|the money back))\b|^(?:give|send|pay|issue|process|do|make|record|raise)\b.{0,30}\brefunds?\b/u', $l)) {
        $kind = 'refund';
    } elseif (preg_match('/^(?:charge|bill|post|add)\b.{0,60}\b(?:to|on) (?:the |their |his |her |this |that )?(?:room|bill|folio|account|tab)\b/u', $l)) {
        $kind = 'charge';
    } elseif (preg_match('/^(?:take|record|collect|receive|log|enter|add|post|accept|process|capture|put through|register)\b.{0,60}\b(?:payments?|cash|money|deposit|card payment|mpamba|airtel money|mobile money|bank transfer|eft|swipe)\b/u', $l)
        || preg_match('/^(?:take|collect|receive|accept)\s+(?:a |an |the |their |his |her )?(?:\S+\s+)?' . $amt . '/u', $l)
        || preg_match('/^(?:pay|settle|clear)\b.{0,12}\b(?:the |their |his |her |this |that )?(?:balance|bill|deposit|account|invoice|folio)\b/u', $l)
        || preg_match('/^(?:he|she|they|the guest|guest|mr \S+|mrs \S+|ms \S+|\p{L}+) (?:just |has |have |already )?(?:paid|deposited|sent)\b.{0,40}' . $amt . '/u', $l)) {
        $kind = 'payment';
    }
    if ($kind === null) {
        return null;
    }
    $who = '';
    if (preg_match('/\b(?:from|for|of|on|to)\s+((?:mr|mrs|ms|miss|dr)\.?\s+)?(\p{L}[\p{L}\'\-]+(?:\s+\p{L}[\p{L}\'\-]+){0,2})\b/u', (string)preg_replace('/\s+/u', ' ', trim($raw)), $m)
        && function_exists('rh_ai_desk_name')) {
        $n = rh_ai_desk_name(trim($m[1] . $m[2]));
        $who = $n ?? '';
    }
    return ['kind' => $kind, 'who' => $who];
}

/**
 * What the hotel's no-show rule would do with the money on this booking, worked out read-only with the same rule the
 * no-show uses (createNoShowRefund: the no_show_refund_policy setting, the first night including its VAT/levy share,
 * folio charges stay billed, capped at what the room payments can still refund). Nothing is written.
 * A room of a group under the group_noshow_charge setting (On, 2+ rooms, not yet charged) is worked out as the no-show
 * will do it: its booked stay is replaced by the group charge (rh_group_noshow_fee_amount), so under 'first_night' and
 * 'full' the refund is paid - (folio charges + that charge); 'group_charge' / 'group_charge_mode' say so (0 / '' when
 * not, and then every other figure is exactly as before). 'credit' = paid above that charge + folio charges that is not
 * queued for refund (policy 'none'): it stays on the booking as the guest's credit, so 'kept' never includes it.
 * A booking with a cancellation policy locked at booking (bookings.cancellation_policy_snapshot, 2026-11-14) follows
 * that policy instead, as rh_mark_booking_no_show does: never the group charge; its no-show % of the stay
 * (rh_cancellation_fee_quote 'noshow'): 100% = the booked stay stays billed, below 100% = one no-show charge replaces it;
 * under 'first_night' / 'full' the refund is what was paid above that charge and any extras (createNoShowRefund's
 * snapshot branch), under 'none' that money stays on the booking as the guest's credit. 'locked' carries the policy
 * (null = no policy locked: today's rules, every figure exactly as before).
 * @return array{policy:string,label:string,paid:float,refund:float,kept:float,can_mark:bool,why:string,group_charge:float,group_charge_mode:string,credit:float,locked:?array}
 */
function rh_ai_noshow_money(PDO $db, array $b): array
{
    $policy = function_exists('getSetting') ? (string)getSetting('no_show_refund_policy', 'none') : 'none';
    $labels = ['none' => 'no automatic refund (the hotel keeps what was paid)', 'first_night' => 'the first night is kept; the rest is queued for refund', 'full' => 'everything paid is queued for refund (folio charges stay billed)'];
    $f = function_exists('getBookingFolioSummary') ? getBookingFolioSummary((int)$b['id']) : [];
    $paid = round(max(0.0, (float)($f['amount_paid'] ?? 0)), 2);
    $folio = round((float)($f['extras_total'] ?? 0), 2);
    // Cancellation policy locked at booking: the same quote the no-show charge is posted from (read only).
    $lock = null;
    if (function_exists('rh_cancellation_fee_quote')) {
        $q = rh_cancellation_fee_quote($db, $b, 'noshow');
        if (($q['source'] ?? '') === 'policy') {
            $full100 = (float)$q['pct'] >= 100;
            $lock = ['pct' => (float)$q['pct'], 'version' => (int)$q['version'], 'kind' => (string)$q['kind'], 'base' => (float)$q['base'], 'basis' => (string)$q['basis'],
                'charge' => $full100 ? (float)$q['base'] : (float)$q['amount'], 'stay_billed' => $full100, 'fee_text' => (string)$q['fee_text']];
            $labels['first_night'] = $labels['full'] = 'what was paid above the no-show charge and any extras is queued for refund';
        }
    }
    // Group no-show charge: the same mode, group test and amount the no-show itself uses (read only). Never with a
    // locked policy (the policy owns the no-show charge).
    $gMode = $lock === null && function_exists('rh_group_noshow_charge_mode') ? rh_group_noshow_charge_mode() : '';
    $gCharge = 0.0;
    $inGroup = false;
    if ($gMode !== '' && function_exists('rh_group_noshow_fee_applies') && rh_group_noshow_fee_applies($db, $b)) {
        $inGroup = true;
        $gCharge = (float)rh_group_noshow_fee_amount($b, $gMode)['amount'];
    }
    $refund = 0.0;
    if ($paid > BALANCE_TOLERANCE && in_array($policy, ['full', 'first_night'], true)) {
        if ($lock !== null) {
            // createNoShowRefund, policy branch: paid - (the stay at 100%, else the no-show charge) - extras
            $refund = max(0.0, round($paid - ($folio + $lock['charge']), 2));
        } elseif ($inGroup) {
            // The charge replaces the policy's own first-night retention (createNoShowRefund: paid - folio charges).
            $refund = max(0.0, round($paid - ($folio + $gCharge), 2));
        } elseif ($policy === 'full') {
            $refund = max(0.0, round($paid - $folio, 2));
        } else {
            $nights = max(1, (int)($b['number_of_nights'] ?? 1));
            $gross = (float)($b['total_with_vat'] ?? 0);
            if ($gross <= 0) {
                $gross = (float)($b['total_amount'] ?? 0) + (float)($b['vat_amount'] ?? 0);
            }
            $retained = max(0.0, round(min($gross, $gross / $nights), 2));
            $retained = min($retained, max(0.0, round($paid - $folio, 2)));
            $refund = max(0.0, round($paid - $retained - $folio, 2));
        }
        if ($refund > BALANCE_TOLERANCE && function_exists('allocateBookingRefunds')) {
            $refund = round(array_sum(array_column(allocateBookingRefunds($db, (int)$b['id'], $refund, false), 'amount')), 2);
        }
        if ($refund <= BALANCE_TOLERANCE) {
            $refund = 0.0;
        }
    }
    $can = true;
    $why = '';
    if ((string)$b['check_in_date'] >= date('Y-m-d')) {
        $can = false;
        $why = 'A no-show can only be marked after the check-in date has passed.';
    } elseif (function_exists('validateBookingStatusTransition') && empty(validateBookingStatusTransition((string)$b['status'], 'no-show')['allowed'])) {
        $can = false;
        $why = 'A ' . str_replace('-', ' ', (string)$b['status']) . ' booking cannot be marked as a no-show.';
    }
    // A charged group room bills only its charge + folio charges: whatever was paid above that and is not queued for
    // refund stays on the booking as credit owed to the guest (never "kept" by the hotel). Off / single: always 0.
    $credit = 0.0;
    if ($inGroup || $lock !== null) {
        $credit = max(0.0, round($paid - ($folio + ($lock !== null ? $lock['charge'] : $gCharge)) - $refund, 2));
        if ($credit <= BALANCE_TOLERANCE) {
            $credit = 0.0;
        } elseif ($policy === 'none') {
            $labels['none'] = 'no automatic refund'; // the hotel does not keep the credit, so not "keeps what was paid"
        }
    }
    return ['policy' => $policy, 'label' => $labels[$policy] ?? $policy, 'paid' => $paid, 'refund' => $refund, 'kept' => round(max(0.0, $paid - $refund - $credit), 2), 'can_mark' => $can, 'why' => $why,
        'group_charge' => $inGroup ? $gCharge : 0.0, 'group_charge_mode' => $inGroup ? $gMode : '', 'credit' => $credit, 'locked' => $lock];
}

/** "this booking's cancellation policy (locked at booking, v3, group bookings)" - the words for a locked policy. */
function rh_ai_locked_policy_words(int $version, string $kind): string
{
    return "this booking's cancellation policy (locked at booking" . ($version > 0 ? ', v' . $version : '') . ($kind !== '' ? ', ' . $kind . ' bookings' : '') . ')';
}

/**
 * The cancellation fee answer for one room booking, from the booking's LOCKED policy (rh_cancel_policy_preview /
 * rh_cancel_policy_dialog_quote - the figures the cancel dialogs show; nothing is worked out here). No policy locked:
 * today's cancellation rule by name, never a figure and never "no fee". A booking made together with other rooms also
 * gets every room's own fee and the total for the whole group. Read only.
 */
function rh_ai_money_cancel_fee_text(PDO $db, array $res, int $id): string
{
    $line = rh_ai_booking_line($res);
    $st = $db->prepare('SELECT * FROM bookings WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !function_exists('rh_cancel_policy_dialog_quote')) {
        return 'The cancellation fee for this booking could not be read just now. Open the booking to see it.';
    }
    $cur = (string)($res['currency'] ?? rh_ai_currency());
    $fm = static fn($v) => rh_ai_fmt_cur($cur, (float)$v);
    $status = strtolower((string)$row['status']);
    // 2026-11-14 review: a booking that is already cancelled, a no-show, checked in or checked out is never answered
    // "No cancellation fee". Its figures are the stored ones the screens show: the kept fee
    // (bookings.cancellation_retained_amount, as Booking Details shows it) and the balance (getBookingFolioSummary via
    // rh_ai_folio, the Bookings Balance Due figure); every comparison with BALANCE_TOLERANCE.
    if (in_array($status, ['cancelled', 'no-show', 'checked-in', 'checked-out'], true)) {
        $f = rh_ai_folio($id);
        $owedTxt = static function (array $f) use ($fm): string {
            if (!isset($f['balance_due'])) {
                return 'What is still owed could not be read just now - open Booking Details to see it.';
            }
            return (float)$f['balance_due'] > BALANCE_TOLERANCE ? '**Still owed: ' . $fm($f['balance_due']) . '** (paid ' . $fm($f['paid'] ?? 0) . ').'
                : 'Nothing is owed on it now (paid ' . $fm($f['paid'] ?? 0) . ').';
        };
        $out = [];
        if ($status === 'cancelled') {
            $kept = round((float)($row['cancellation_retained_amount'] ?? 0), 2);
            if ($kept > BALANCE_TOLERANCE) {
                $owedLabel = isset($f['balance_due']) && function_exists('rh_cancelled_fee_owed_label') ? rh_cancelled_fee_owed_label('cancelled', $f['balance_due']) : '';
                $out[] = '**Already cancelled - cancellation fee kept: ' . $fm($kept) . '**' . ($owedLabel !== '' ? ' (the booking reads "' . $owedLabel . '")' : '') . '.';
            } else {
                $out[] = '**Already cancelled - no kept cancellation fee is recorded on this booking** (the kept fee it stores is ' . $fm(0) . ').';
            }
            $out[] = $owedTxt($f);
            $out[] = 'The booking\'s History shows who cancelled it, when, and the fee that was set.';
        } elseif ($status === 'no-show') {
            $q = function_exists('rh_cancellation_fee_quote') ? rh_cancellation_fee_quote($db, $row, 'noshow') : [];
            $out[] = '**Marked as a no-show** - a no-show is charged under its no-show rule, not a cancellation fee'
                . (($q['source'] ?? '') === 'policy' ? ' (' . $q['tier_label'] . ', under ' . rh_ai_locked_policy_words((int)$q['version'], (string)$q['kind']) . ')' : '') . '.';
            $out[] = isset($f['total']) ? 'Charged on the booking: ' . $fm($f['total']) . '. ' . $owedTxt($f) : $owedTxt($f);
            $out[] = 'Booking Details shows the no-show charge line.';
        } else {
            $out[] = '**' . ($status === 'checked-in' ? 'Checked in' : 'Checked out') . ' - this booking can no longer be cancelled**, so a cancellation fee does not come into it.';
            $out[] = $owedTxt($f);
        }
        return implode("\n", $out) . "\n\n" . $line . "\n\nLowering or waiving a fee is done by staff on the booking.";
    }
    if (!in_array($status, ['confirmed', 'pending', 'tentative', 'expired'], true)) {
        return 'The cancellation fee for this booking could not be worked out from its status (' . $status . '). Open Booking Details to see it.' . "\n\n" . $line;
    }
    $dq = rh_cancel_policy_dialog_quote($db, $id);
    $p = (array)($dq['single'] ?? []);
    $mode = function_exists('getCancellationRefundModeLabel') ? getCancellationRefundModeLabel() : '';
    $out = [];
    if (($p['source'] ?? 'legacy') !== 'policy') {
        // Owner rule: never "no fee" / a figure without a checked total. A booking with no locked policy follows the
        // cancellation rule set in Hotel Settings, which the cancel dialog applies.
        $out[] = '**No cancellation policy is locked on this booking** (it was booked while the policy was off, or before it existed), so today\'s cancellation rule applies'
            . ($mode !== '' ? ': ' . $mode : '') . '. The cancel dialog on Booking Details shows what it does to the money before you confirm.';
    } else {
        $snap = function_exists('rh_cancel_policy_snapshot') ? rh_cancel_policy_snapshot($row) : null;
        $who = rh_ai_locked_policy_words((int)$p['version'], (string)$p['kind']);
        if ($status !== 'confirmed') {
            $out[] = '**No cancellation fee** - the booking is ' . str_replace('-', ' ', $status) . ' (not confirmed), and ' . $who . ' charges a fee only on a confirmed booking.';
        } elseif (!empty($p['applies'])) {
            $out[] = '**Cancellation fee if cancelled today: ' . $fm($p['amount']) . '** - ' . trim((string)$p['fee_text']) . '.';
            $out[] = 'Why: ' . $p['tier_label'] . ', under ' . $who . '.';
        } else {
            $out[] = '**No cancellation fee if cancelled today** - ' . $p['tier_label'] . ', under ' . $who . '.';
            $maxDays = 0;
            foreach ((array)($snap['tiers'] ?? []) as $t) {
                $maxDays = max($maxDays, (int)($t['days'] ?? 0));
            }
            if ($maxDays > 0 && (int)$p['days_before'] >= $maxDays && function_exists('rh_days_before_arrival')) {
                $tz = new DateTimeZone(defined('RH_TIMEZONE') ? RH_TIMEZONE : 'Africa/Blantyre');
                $from = (new DateTimeImmutable((string)$row['check_in_date'], $tz))->modify('-' . ($maxDays - 1) . ' days');
                $out[] = 'A fee starts when it is fewer than ' . $maxDays . ' days before arrival (from ' . $from->format('D j M Y') . '); cancelling exactly ' . $maxDays . ' days before is still free.';
            }
        }
        if ($status === 'confirmed') {
            $money = 'Paid so far ' . $fm($p['paid'] ?? 0);
            if ((float)($p['refund'] ?? 0) > BALANCE_TOLERANCE) {
                $money .= '; refunded on cancelling ' . $fm($p['refund']);
            }
            if ((float)($p['credit'] ?? 0) > BALANCE_TOLERANCE) {
                $money .= '; kept on the booking as the guest\'s credit ' . $fm($p['credit']);
            }
            if ((float)($p['owed'] ?? 0) > BALANCE_TOLERANCE) {
                $money .= '; still owed after cancelling ' . $fm($p['owed']);
            }
            $out[] = $money . ' (the same figures the cancel dialog shows).';
        }
    }
    $g = is_array($dq['group'] ?? null) ? $dq['group'] : null;
    if ($g !== null && !empty($dq['policy'])) {
        $lines = [];
        // A room of the group that is already cancelled / a no-show / checked in or out is not part of cancelling now:
        // said so, never "no fee" (the same rule as the single answer above).
        $gIds = array_map(static fn($r) => (int)$r['id'], (array)$g['rooms']);
        $gSt = [];
        if ($gIds) {
            $q = $db->prepare('SELECT id, status FROM bookings WHERE id IN (' . implode(',', array_fill(0, count($gIds), '?')) . ')');
            $q->execute($gIds);
            $gSt = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        foreach (array_slice((array)$g['rooms'], 0, 10) as $r) {
            $rs = strtolower((string)($gSt[(int)$r['id']] ?? ''));
            if (in_array($rs, ['cancelled', 'no-show', 'checked-in', 'checked-out'], true)) {
                $lines[] = $r['reference'] . ': already ' . str_replace('-', ' ', $rs) . ' - not part of cancelling now (ask about that booking for its own figures)';
                continue;
            }
            $lines[] = $r['reference'] . ': ' . ($r['source'] !== 'policy' ? 'no policy locked (today\'s cancellation rule)' : (!empty($r['fee']) && (float)$r['fee'] > BALANCE_TOLERANCE ? $r['fee_text'] : 'no fee (' . $r['tier_label'] . ')'));
        }
        $out[] = '**Booked together: ' . (int)$g['count'] . ' rooms** - each room pays its own fee on its own stay; cancelling all of them: ' . $fm($g['fee']) . ' in total.'
            . rh_ai_local_lines($lines) . (count((array)$g['rooms']) > 10 ? "\n(first 10 rooms shown)" : '');
    }
    return implode("\n", $out) . "\n\n" . $line . "\n\nI only quote the fee: cancelling, and lowering or waiving a fee, are done by staff on the booking.";
}

/**
 * What each room of a group booking owes and the group total (2026-11-14 live check): the rooms are the group's links
 * (lead + primary_booking_id, as rh_booking_group_members), every status, each room's figure is its own bill (rh_ai_folio ->
 * getBookingFolioSummary, the Bookings Balance Due figure, company share kept apart by rh_ai_owe_words), a cancelled
 * room still owing its kept fee reads "Cancelled - fee owed" (rh_cancelled_fee_owed_label). null = not in a group (the
 * caller answers for the one booking). Read only.
 */
function rh_ai_money_group_text(PDO $db, array $res, int $id): ?string
{
    // The group as rh_booking_group_members links it (the lead + every room whose primary_booking_id is the lead), but
    // every status: a cancelled room still owing its kept fee, a no-show owing its charge or a checked-out room with a
    // balance is still money owed by the group (rh_booking_group_ids leaves closed rooms out - it decides what a cancel
    // touches, not what is owed).
    $lead = $db->prepare('SELECT COALESCE(primary_booking_id, id) FROM bookings WHERE id = ?');
    $lead->execute([$id]);
    $leadId = (int)$lead->fetchColumn();
    if ($leadId <= 0) {
        return null;
    }
    $st = $db->prepare('SELECT b.id, b.booking_reference, b.status, b.cancellation_retained_amount, ir.room_number, r.name AS room_type
                        FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                        WHERE b.deleted_at IS NULL AND (b.id = ? OR b.primary_booking_id = ?) ORDER BY b.id');
    $st->execute([$leadId, $leadId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < 2) {
        return null;
    }
    $cur = (string)($res['currency'] ?? rh_ai_currency());
    $fm = static fn($v) => rh_ai_fmt_cur($cur, (float)$v);
    $lines = [];
    $total = 0.0;
    $paid = 0.0;
    $unread = 0;
    foreach ($rows as $b) {
        $f = rh_ai_folio((int)$b['id']);
        $where = trim((string)($b['room_type'] ?? '') . ($b['room_number'] !== null && (string)$b['room_number'] !== '' ? ' room ' . $b['room_number'] : ' (room not assigned)'));
        if (!isset($f['balance_due'])) {
            $unread++;
            $lines[] = $b['booking_reference'] . ' · ' . $where . ': could not be read - open the booking';
            continue;
        }
        $bal = (float)$f['balance_due'];
        $total += $bal;
        $paid += (float)($f['paid'] ?? 0);
        $st2 = strtolower((string)$b['status']);
        $label = function_exists('rh_cancelled_fee_owed_label') ? rh_cancelled_fee_owed_label($st2, $bal) : '';
        $kept = round((float)($b['cancellation_retained_amount'] ?? 0), 2);
        $owe = rh_ai_owe_words($f, $fm);
        $lines[] = $b['booking_reference'] . ' · ' . $where . ' · ' . ($label !== '' ? $label : str_replace('-', ' ', $st2))
            . ($st2 === 'cancelled' && $kept > BALANCE_TOLERANCE ? ' (fee kept ' . $fm($kept) . ')' : '')
            . ': ' . ($owe !== '' ? $owe : 'nothing owed') . ' (paid ' . $fm($f['paid'] ?? 0) . ')';
    }
    $head = '**Booked together: ' . count($rows) . ' rooms - the whole group still owes ' . $fm($total) . '**' . ($unread > 0 ? ' (' . $unread . ' room(s) could not be read, so this total leaves them out)' : '')
        . '; paid so far ' . $fm($paid) . '.';
    return $head . rh_ai_local_lines($lines) . "\n\nEach room's own bill, the same figures as Bookings. Payments are taken on each booking's screen.";
}

/**
 * The cancellation fee facts for one bookings row, for the booking lookup the model reads (2026-11-14 live check: the
 * model must never answer a fee from nothing). The same sources as the local answer: the stored kept fee for a
 * cancelled booking, rh_cancellation_fee_quote for a locked policy (pending / tentative / expired = no fee until
 * confirmed), today's rule by name when no policy is locked (no figure). Read only.
 */
function rh_ai_cancel_fee_facts(PDO $db, array $b): array
{
    $status = strtolower((string)($b['status'] ?? ''));
    if ($status === 'cancelled') {
        $kept = rh_ai_money(round((float)($b['cancellation_retained_amount'] ?? 0), 2));
        return ['already_cancelled' => true, 'fee_kept' => $kept, 'note' => $kept > BALANCE_TOLERANCE ? 'Already cancelled; this fee was kept. What is still owed is money.balance_due.'
            : 'Already cancelled; no kept fee is recorded (stored figure checked). What is still owed is money.balance_due; History shows the cancel.'];
    }
    if ($status === 'no-show') {
        return ['note' => 'A no-show: charged under its no-show rule, not a cancellation fee. What is owed is money.balance_due; Booking Details shows the no-show charge line.'];
    }
    if (in_array($status, ['checked-in', 'checked-out'], true)) {
        return ['note' => 'Can no longer be cancelled (' . $status . '), so no cancellation fee applies to it.'];
    }
    $q = function_exists('rh_cancellation_fee_quote') ? rh_cancellation_fee_quote($db, $b) : ['source' => 'legacy'];
    if (($q['source'] ?? '') === 'policy') {
        return ['policy' => rh_ai_locked_policy_words((int)$q['version'], (string)$q['kind']), 'if_cancelled_today' => rh_ai_money((float)$q['amount']),
            'fee_text' => (string)$q['fee_text'], 'why' => (string)$q['tier_label']];
    }
    $mode = function_exists('getCancellationRefundModeLabel') ? getCancellationRefundModeLabel() : '';
    return ['policy' => 'none locked at booking', 'note' => 'Today\'s cancellation rule applies' . ($mode !== '' ? ' (' . $mode . ')' : '')
        . '; the cancel dialog on Booking Details shows the money. Quote no fee figure and never say "no fee" for this booking.'];
}

/** The latest money received on a room booking (not refunds): ['amount','method','date','receipt'] or null. */
function rh_ai_last_payment(PDO $db, int $bookingId): ?array
{
    try {
        $st = $db->prepare("SELECT total_amount, payment_method, payment_date, receipt_number FROM payments WHERE booking_type = 'room' AND booking_id = ? AND deleted_at IS NULL
                            AND COALESCE(payment_type, '') <> 'refund' AND payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') ORDER BY payment_date DESC, id DESC LIMIT 1");
        $st->execute([$bookingId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    return $p ? ['amount' => rh_ai_money((float)$p['total_amount']), 'method' => str_replace('_', ' ', (string)$p['payment_method']), 'date' => (string)$p['payment_date'], 'receipt' => (string)($p['receipt_number'] ?? '')] : null;
}

/** The room booking a money line is about: focus / on screen ('' asked), a reference, or one clear name. 0 = none. */
function rh_ai_money_booking(PDO $db, array $user, int $conv, string $who, array $focus, int $pageBooking): int
{
    if ($who === '') {
        return (int)($focus['focus'] ?? 0) > 0 ? (int)$focus['focus'] : $pageBooking;
    }
    if (!rh_ai_tool_allowed($user, 'search_bookings')) {
        return 0;
    }
    $r = rh_ai_run_tool($db, $user, $conv, 'search_bookings', ['query' => $who]);
    $rows = $r['ok'] && is_array($r['result']['bookings'] ?? null) ? $r['result']['bookings'] : [];
    return count($rows) === 1 ? (int)$rows[0]['booking_id'] : 0;
}

/**
 * "I don't take payments ..." + the screen + (where this person may see them) the booking's live figures.
 * @return array ['text','links','chips','route','focus'?]
 */
function rh_ai_money_action_reply(PDO $db, array $user, int $conv, array $mi, array $focus, int $pageBooking): array
{
    $uid = (int)$user['id'];
    $canOpen = static fn(string $p): bool => function_exists('rhCanOpenPage') ? rhCanOpenPage($uid, $p) : false;
    if ($mi['kind'] === 'waive') {
        // Lowering / waiving a cancellation or no-show fee (2026-11-14): never done here. Without the permission
        // (waive_cancellation_fee) the person is told so and offered nothing - no figures, no link.
        if (!rh_ai_has_perm($uid, 'waive_cancellation_fee')) {
            return ['text' => "You don't have permission to lower or waive cancellation fees, so I can't help with that. A manager or an administrator can do it.", 'links' => [], 'chips' => [], 'route' => 'declined'];
        }
        $wid = rh_ai_tool_allowed($user, 'get_booking') && rh_ai_module_on('bookings') ? rh_ai_money_booking($db, $user, $conv, (string)$mi['who'], $focus, $pageBooking) : 0;
        $links = [];
        if ($wid > 0 && $canOpen('booking-details.php')) {
            $links[] = ['label' => 'Open booking', 'url' => 'booking-details.php?id=' . $wid];
        } elseif ($canOpen('bookings.php')) {
            $links[] = ['label' => 'Open Bookings', 'url' => 'bookings.php'];
        }
        return ['text' => "I don't lower or waive fees - you do it on Booking Details, with a reason: when you cancel the booking, or afterwards on the booking. The booking's History keeps who changed it and why.",
            'links' => $links, 'chips' => [], 'route' => 'declined', 'focus' => $wid > 0 ? $wid : null];
    }
    $lines = [
        'payment' => "I don't take or record payments - record it on the booking (Add payment), so the receipt, the balance and the reports stay right.",
        'refund' => "I don't do refunds - they are recorded by staff on the Payments screen (Refund).",
        'noshow' => "I don't mark no-shows - a no-show moves money (what is kept or refunded), so it is marked on the booking screen (No-show).",
        'charge' => "I don't post charges - add it on the booking's folio (Add charge) or on the till.",
    ];
    $text = $lines[$mi['kind']];
    $links = [];
    $id =rh_ai_tool_allowed($user, 'get_booking') && rh_ai_module_on('bookings') ? rh_ai_money_booking($db, $user, $conv, (string)$mi['who'], $focus, $pageBooking) : 0;
    $b = null;
    if ($id > 0) {
        $r = rh_ai_run_tool($db, $user, $conv, 'get_booking', ['booking_id' => $id]);
        if ($r['ok']) {
            $b = $r;
        }
    }
    if ($b !== null) {
        $res = (array)$b['result'];
        $raw = (array)$b['raw'];
        rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => 'get_booking', 'args' => ['booking_id' => $id], 'result' => $b['json'], 'agent' => 'local']);
        $text .= "\n\n" . rh_ai_booking_line($res) . "\n\n" . rh_ai_booking_money_text($res);
        $last = rh_ai_last_payment($db, $id);
        if ($last) {
            $text .= "\nLast payment: " . rh_ai_fmt_cur((string)($res['currency'] ?? ''), $last['amount']) . ($last['method'] !== '' ? ' (' . $last['method'] . ')' : '') . ' on ' . date('D j M Y', strtotime($last['date'])) . '.';
        }
        if ($mi['kind'] === 'noshow') {
            $st = $db->prepare("SELECT * FROM bookings WHERE id = ?");
            $st->execute([$id]);
            $full = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $ns = rh_ai_noshow_money($db, $full);
            $cur = (string)($res['currency'] ?? '');
            $grp = '';
            if ($ns['group_charge_mode'] !== '') {
                // A room of a group under the hotel's group no-show setting: billed the charge instead of its booked stay.
                $what = $ns['group_charge'] <= BALANCE_TOLERANCE ? 'nothing' : ($ns['group_charge_mode'] === 'full' ? 'its full booked stay' : 'the first night') . ' (' . rh_ai_fmt_cur($cur, $ns['group_charge']) . ')';
                $grp = ' This room is part of a group, so it will be charged ' . $what . ' instead of its booked stay'
                    . (in_array($ns['policy'], ['first_night', 'full'], true) ? '; the refund is what was paid above that charge and any extras.'
                        : ($ns['credit'] > BALANCE_TOLERANCE ? '; what was paid above that charge and any extras stays on the booking as credit for the guest (no automatic refund).' : '.'));
            }
            if (is_array($ns['locked'] ?? null)) {
                // A cancellation policy locked at booking decides the no-show charge (never the group charge)
                $lk = $ns['locked'];
                $grp = ' Under ' . rh_ai_locked_policy_words((int)$lk['version'], (string)$lk['kind']) . ' a no-show is charged '
                    . ($lk['stay_billed'] ? 'the whole booked stay (100%), which stays billed.' : ($lk['charge'] <= BALANCE_TOLERANCE ? 'nothing (0% of the stay).' : $lk['fee_text'] . ', one "No-show charge" line that replaces the booked stay.'));
            }
            $text .= "\n\n**If marked as a no-show** (" . (is_array($ns['locked'] ?? null) ? 'refund rule' : "the hotel's rule") . ': ' . $ns['label'] . '): paid ' . rh_ai_fmt_cur($cur, $ns['paid']) . ', kept ' . rh_ai_fmt_cur($cur, $ns['kept'])
                . ', queued for refund ' . rh_ai_fmt_cur($cur, $ns['refund']) . ($ns['credit'] > BALANCE_TOLERANCE ? ', left as credit for the guest ' . rh_ai_fmt_cur($cur, $ns['credit']) : '')
                . '.' . $grp . ($ns['can_mark'] ? '' : ' ' . $ns['why']);
        }
        if ($mi['kind'] === 'payment' && $canOpen('payment-add.php')) {
            $links[] = ['label' => 'Add payment', 'url' => 'payment-add.php?booking_type=room&booking_id=' . $id];
        }
        $links[] = ['label' => 'Open booking', 'url' => 'booking-details.php?id=' . $id];
        unset($raw);
    } else {
        $page = ['payment' => ['payment-add.php', 'Open Add payment'], 'refund' => ['payments.php', 'Open Payments'], 'noshow' => ['bookings.php', 'Open Bookings'], 'charge' => ['bookings.php', 'Open Bookings']][$mi['kind']];
        if ($canOpen($page[0])) {
            $links[] = ['label' => $page[1], 'url' => $page[0]];
        }
    }
    if ($mi['kind'] === 'refund' && $canOpen('payments.php')) {
        $links[] = ['label' => 'Open Payments', 'url' => 'payments.php'];
    }
    return ['text' => $text, 'links' => $links, 'chips' => [], 'route' => 'declined', 'focus' => $id > 0 ? $id : null];
}

/**
 * A money QUESTION about one account: ['ask' => full|left|paid|deposit|last, 'subject' => booking|gym|event|tab,
 * 'who' => name / reference as written ('' = the booking in focus), 'table' / 'room' (tab)] or null.
 */
function rh_ai_money_read_intent(string $raw): ?array
{
    $l = rh_ai_money_clean($raw);
    if ($l === '' || mb_strlen($l) > 160 || (function_exists('rh_ai_focus_is_change') && preg_match('/^(take|record|refund|charge|mark|add|post|collect)\b/u', $l))) {
        return null;
    }
    if (preg_match('/\bimport(s|ed|ing)?\b/u', $l)) {
        return null; // "what's left on my import" is about an Import review, not money owed
    }
    $ask = null;
    if (rh_ai_money_is_question($l) && (preg_match('/\b(?:cancell?ation|cancell?ing|cancel)\s+(?:fees?|charges?|penalty|penalties)\b/u', $l)
        || preg_match('/\b(?:how much|what)\b.{0,40}\b(?:cost|charge|pay|owe|fee|keep|lose)\b.{0,30}\bcancel/u', $l)
        || preg_match('/\b(?:cost|charge|fee)\b.{0,12}\bto cancel\b|\bif (?:they|he|she|the guest|we|i|the booking|it) (?:cancels?|cancelled|were cancelled|is cancelled)\b/u', $l)
        || preg_match('/\bhow much\b.{0,30}\bto cancel\b|\b(?:fees?|charges?|penalty)\b.{0,40}\bif (?:it(?:\'s| is| were| was| gets)? )?cancell?ed\b/u', $l))) {
        // 2026-11-14: the cancellation fee from the booking's locked policy (a question only: "cancel it" stays a card)
        $ask = 'cancelfee';
    } elseif (preg_match('/\b(fully|completely|all) paid\b|\bpaid (in full|up|everything|it all|off)\b|\b(settled|paid off)\b|\bpaid for\b.*\?$/u', $l)) {
        $ask = 'full';
    } elseif (preg_match('/\b(left|remaining|still) to pay\b|\bhow much (is |do |does |did )?(\S+ ){0,3}(still )?(owe|owes|owing|outstanding|left|remaining|due)\b|\bwhat(\'s| is| does) (\S+ ){0,5}(left|outstanding|owing|owed|due|owe)\b|\b(balance|amount) (due|left|remaining|outstanding|owing)\b|\bany(thing)? (still )?(owing|owed|outstanding|due)\b|^(the |their |his |her )?balance\??$/u', $l)) {
        $ask = 'left';
    } elseif (preg_match('/\b(last|latest|most recent) payment\b|\bwhen did (\S+ ){0,4}pay\b|\bwhen was (the |their |his |her )?(last )?payment\b/u', $l)) {
        $ask = 'last';
    } elseif (preg_match('/\bdeposit\b/u', $l) && rh_ai_money_is_question($l)) {
        $ask = 'deposit';
    } elseif (preg_match('/\bhow much (have|has|did) (\S+ ){0,4}paid?\b|\bamount paid\b|\bpaid so far\b|^(has|have|did) (\S+ ){0,4}paid?\b/u', $l)) {
        $ask = 'paid';
    }
    if ($ask === null) {
        return null;
    }
    $subject = 'booking';
    if (preg_match('/\b(gym|membership|member)\b/u', $l)) {
        $subject = 'gym';
    } elseif (preg_match('/\b(conference|event|function|wedding|meeting|seminar|workshop)\b/u', $l)) {
        $subject = 'event';
    } elseif (preg_match('/\btable\s+(\S+)|\b(tab|room service|order)\b/u', $l)) {
        $subject = 'tab';
    }
    if ($ask === 'cancelfee' && $subject !== 'booking') {
        return null; // cancellation policies are for room bookings only
    }
    $out = ['ask' => $ask, 'subject' => $subject, 'who' => ''];
    // 2026-11-14 live check: "what does each room owe / the total for the whole group" = every room of the group
    if ($subject === 'booking' && $ask !== 'cancelfee' && preg_match('/\b(?:each|every|per|all (?:the |of the )?)\s*rooms?\b|\b(?:whole|entire|the) group\b|\bgroup (?:booking|total)\b/u', $l)) {
        $out['group'] = true;
    }
    // A booking reference, or a document number with its hotel letters when the hotel sets them
    // ("LSH-INV-2026-000001", "LSH-CONF-INV-...", "LSH-RFD-REF-...", "RCP2026000001"), captured whole. 2026-11-14 live
    // check: any other reference shape the booking lookup can find ("AILC962961": letters then digits, or the word
    // after "booking" / "reference" when it holds a digit), never a currency amount ("MWK50000").
    if (preg_match('/\b([A-Z]{2,6}20\d{6,10}|(?:[a-z0-9]{1,6}-)?(?:[a-z]{2,6}-)?[a-z]{2,6}-\d[\d\-]{3,30})\b/iu', $raw, $m)
        || preg_match('/\b(?!(?:MWK|USD|ZAR|EUR|GBP|K)\d)([A-Z]{2,8}\d{5,14})\b/u', $raw, $m)
        || preg_match('/\b(?:booking|reservation|ref(?:erence)?)\s+(?:ref(?:erence)?\s+|no\.?\s+|number\s+|#\s*)?((?=[a-z0-9\-]*\d)[a-z][a-z0-9\-]{3,24})\b/iu', $raw, $m)) {
        $out['who'] = strtoupper($m[1]);
    } elseif (preg_match('/\btable\s+([a-z0-9\-]{1,10})\b/u', $l, $m)) {
        $out['table'] = $m[1];
    } elseif ($subject === 'tab' && preg_match('/\broom\s+([a-z0-9\-]{1,10})\b/u', $l, $m)) {
        $out['room'] = $m[1];
    } else {
        $txt = trim((string)preg_replace('/\s+/u', ' ', $raw));
        if (preg_match('/\b(?:((?i:mr|mrs|ms|miss|dr))\.?\s+)(\p{L}[\p{L}\'\-]+(?:\s+\p{Lu}[\p{L}\'\-]+)?)/u', $txt, $m)) {
            $out['who'] = trim($m[2]);
        } elseif (preg_match('/\b(?:for|of|has|did|is|does|from)\s+(\p{Lu}[\p{L}\'\-]+(?:\s+\p{Lu}[\p{L}\'\-]+){0,2})/u', $txt, $m) && function_exists('rh_ai_desk_name') && rh_ai_desk_name($m[1]) !== null) {
            $out['who'] = (string)rh_ai_desk_name($m[1]);
        } elseif (preg_match('/^(?:is|has|did|does)\s+(\p{L}[\p{L}\'\-]+(?:\s+\p{L}[\p{L}\'\-]+)?)\s+(?:fully |completely |already )?(?:paid|owe|owing|settled)/iu', $txt, $m)
            && !preg_match('/^(it|he|she|they|this|that|the|their|his|her|everything|anything|someone|anyone)\b/iu', $m[1])) {
            $out['who'] = $m[1];
        } elseif ($subject !== 'booking' && preg_match('/\b(?:member|booking|conference|event)\s+(?:for\s+|of\s+)?([\p{L}\p{N}][\p{L}\p{N}\'\-]+(?:\s+\p{Lu}[\p{L}\'\-]+){0,2})/u', $txt, $m)
            && !preg_match('/^(paid|fully|still|left|owe|is|has|the|their)\b/iu', $m[1])) {
            $out['who'] = $m[1];
        } elseif ($subject === 'event' && preg_match('/\bthe\s+(\p{L}[\p{L}\'\- ]{1,40}?)\s+(?:conference|event|wedding|function|meeting|seminar|workshop)\b/iu', $txt, $m)) {
            $out['who'] = trim($m[1]); // "is the Widgets conference fully paid?"
        }
    }
    return $out;
}

/** The plain answer for one room booking (de-identified result from get_booking). */
function rh_ai_money_booking_text(PDO $db, array $res, string $ask, int $id): string
{
    $m = is_array($res['money'] ?? null) ? $res['money'] : [];
    if (!isset($m['total'])) {
        return 'The bill for this booking could not be read just now. Open the booking to see it.';
    }
    $cur = (string)($res['currency'] ?? '');
    $owed = (float)$m['balance_due'];
    $paid = (float)$m['paid'];
    $total = (float)$m['total'];
    $f = static fn($v) => rh_ai_fmt_cur($cur, $v);
    $last = rh_ai_last_payment($db, $id);
    $lastLine = $last ? 'Last payment: ' . $f($last['amount']) . ($last['method'] !== '' ? ' (' . $last['method'] . ')' : '') . ' on ' . date('D j M Y', strtotime($last['date'])) . ($last['receipt'] !== '' ? ', receipt ' . $last['receipt'] : '') . '.' : 'No payment is recorded yet.';
    switch ($ask) {
        case 'full':
            $head = $owed <= BALANCE_TOLERANCE ? '**Yes - fully paid.** ' . $f($paid) . ' paid of ' . $f($total) . '; nothing is owed.' : '**No - ' . $f($owed) . ' is still to pay** (paid ' . $f($paid) . ' of ' . $f($total) . ').';
            break;
        case 'left':
            $head = $owed <= BALANCE_TOLERANCE ? '**Nothing is left to pay** - ' . $f($paid) . ' paid of ' . $f($total) . '.' : '**Still to pay: ' . $f($owed) . '** (paid ' . $f($paid) . ' of ' . $f($total) . ').';
            break;
        case 'deposit':
            $head = $paid > BALANCE_TOLERANCE ? '**Yes - ' . $f($paid) . ' has been paid** so far' . ($owed > BALANCE_TOLERANCE ? '; ' . $f($owed) . ' is still to pay.' : '; nothing is owed.') : '**No payment yet** - the full ' . $f($total) . ' is still to pay.';
            break;
        case 'last':
            $head = $last ? '**' . $lastLine . '**' : '**No payment is recorded yet** on this booking (' . $f($owed) . ' to pay).';
            $lastLine = '';
            break;
        default:
            $head = '**Paid so far: ' . $f($paid) . '** of ' . $f($total) . '. ' . ($owed <= BALANCE_TOLERANCE ? 'Nothing is owed.' : 'Still to pay: ' . $f($owed) . '.');
    }
    if (!empty($m['on_account'])) {
        // Company pays later (owner 2026-11-13): the company share is billed to the company, never the guest's debt
        $name = trim((string)($m['billed_to']['guest_name'] ?? ''));
        $gOwes = (float)($m['guest_owes'] ?? 0);
        $cOwes = (float)($m['company_owes'] ?? 0);
        $split = '**On a company account' . ($name !== '' ? ' (' . $name . ')' : '') . '** - ' . ($gOwes > BALANCE_TOLERANCE ? 'the guest owes ' . $f($gOwes) : 'the guest owes nothing')
            . ($cOwes > BALANCE_TOLERANCE ? '; ' . $f($cOwes) . ' is billed to the company (company pays later, not the guest\'s debt)' : '; nothing is left on the company account')
            . '. Paid so far ' . $f($paid) . ' of ' . $f($total) . '.';
        $head = $ask === 'last' ? $head . "\n" . $split : $split; // "last payment" keeps its answer first
    }
    $rate = rh_ai_booked_rate_text($res); // the rate it was booked at × nights, never today's price (owner 2026-11-09)
    return $head . "\n\n" . rh_ai_booking_line($res) . ($rate !== '' ? "\n" . $rate : '') . ($lastLine !== '' ? "\n" . $lastLine : '') . "\n\nThe booking's own bill, the same figures as the booking screen.";
}

/**
 * A gym balance that is not a figure, in plain words. Two different cases, never worded as "nothing owed": the account
 * may not see gym money (the lookup says "not shown ..."), or the bills could not be read (anything else).
 */
function rh_ai_money_gym_unread_text(string $who, $bal): string
{
    if (strpos((string)$bal, 'not shown') === 0) {
        return $who . ': the gym bills are not shown to your account - the Gym Inquiries screen (gym invoicing) or Gym Reports shows them.';
    }
    return $who . ': I could not read their gym bills just now, so I cannot say what is owed. Check the balance on the Gym Inquiries screen (gym invoicing) or Gym Reports.';
}

/**
 * Answer a money question locally. null = not one (or nothing to tie it to: the model or the focus answers go on).
 * @return array|null ['text','links','chips','route'?,'focus'?,'focus_offer'?]
 */
function rh_ai_money_read_reply(PDO $db, array $user, int $conv, array $mi, array $focus, int $pageBooking): ?array
{
    $declined = static function (string $tool) use ($user): array {
        return function_exists('rh_ai_desk_declined') ? rh_ai_desk_declined($user, $tool) : ['text' => rh_ai_out_of_scope_line(), 'links' => [], 'chips' => [], 'route' => 'declined'];
    };
    $cur = rh_ai_currency();
    if ($mi['subject'] === 'booking') {
        if (!rh_ai_tool_allowed($user, 'get_booking')) {
            return $declined('get_booking');
        }
        if ($mi['who'] === '' && (int)($focus['focus'] ?? 0) <= 0 && $pageBooking <= 0) {
            return null; // nothing ties it to a booking: the model asks which one
        }
        if ($mi['who'] !== '') {
            $pick = rh_ai_desk_pick($db, $user, $conv, (string)$mi['who'], $focus, $pageBooking);
            if (isset($pick['reply'])) {
                return $pick['reply'];
            }
            $id = (int)$pick['id'];
        } else {
            $id = (int)($focus['focus'] ?? 0) > 0 ? (int)$focus['focus'] : $pageBooking;
        }
        $r = rh_ai_run_tool($db, $user, $conv, 'get_booking', ['booking_id' => $id]);
        if (!$r['ok']) {
            return null;
        }
        rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => 'get_booking', 'args' => ['booking_id' => $id], 'result' => $r['json'], 'agent' => 'local']);
        if (!empty($mi['group']) && ($gt = rh_ai_money_group_text($db, (array)$r['result'], $id)) !== null) {
            return ['text' => $gt, 'links' => $r['links'], 'chips' => [], 'focus' => $id, 'focus_offer' => 0, 'local' => ['tool' => 'get_booking', 'view' => []]];
        }
        $txt = $mi['ask'] === 'cancelfee' ? rh_ai_money_cancel_fee_text($db, (array)$r['result'], $id) : rh_ai_money_booking_text($db, (array)$r['result'], (string)$mi['ask'], $id);
        return ['text' => $txt, 'links' => $r['links'], 'chips' => ['What is included?'], 'focus' => $id, 'focus_offer' => 0, 'local' => ['tool' => 'get_booking', 'view' => []]];
    }
    if ($mi['subject'] === 'gym') {
        if ($mi['who'] === '') {
            return null;
        }
        if (!rh_ai_tool_allowed($user, 'gym_watch')) {
            return $declined('gym_watch');
        }
        $r = rh_ai_run_tool($db, $user, $conv, 'gym_watch', ['member' => (string)$mi['who']]);
        if (!$r['ok']) {
            return null;
        }
        rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => 'gym_watch', 'args' => rh_ai_vault_protect($db, $conv, ['member' => (string)$mi['who']]), 'result' => $r['json'], 'agent' => 'local']);
        $res = (array)$r['result'];
        if (($res['match'] ?? '') !== 'one') {
            return ['text' => ($res['match'] ?? '') === 'several' ? 'Several members match - give me the member number.' : (string)($res['not_found'] ?? 'No gym member matches that.'), 'links' => $r['links'], 'chips' => []];
        }
        $mem = (array)$res['member'];
        $bal = $mem['balance_owed'] ?? null;
        $who = '**' . ($mem['guest_name'] ?? '') . '** (' . ($mem['member_number'] ?? '') . ')';
        if (!is_numeric($bal)) {
            return ['text' => rh_ai_money_gym_unread_text($who, $bal), 'links' => $r['links'], 'chips' => []];
        }
        $owed = (float)$bal;
        $text = $owed <= BALANCE_TOLERANCE ? $who . ': **fully paid** - nothing is owed on their gym bills.' : $who . ': **' . rh_ai_fmt_cur($cur, $owed) . ' still to pay** on their gym bills.';
        return ['text' => $text . "\n\nMembership: " . ($mem['membership'] ?? '') . ', ' . ($mem['status'] ?? '') . ', ends ' . ($mem['ends'] ?? '-') . '.', 'links' => $r['links'], 'chips' => []];
    }
    if ($mi['subject'] === 'event') {
        if ($mi['who'] === '') {
            return null;
        }
        if (!rh_ai_tool_allowed($user, 'events_pipeline')) {
            return $declined('events_pipeline');
        }
        $r = rh_ai_run_tool($db, $user, $conv, 'events_pipeline', ['query' => (string)$mi['who']]);
        if (!$r['ok']) {
            return null;
        }
        rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => 'events_pipeline', 'args' => rh_ai_vault_protect($db, $conv, ['query' => (string)$mi['who']]), 'result' => $r['json'], 'agent' => 'local']);
        $res = (array)$r['result'];
        if (($res['match'] ?? '') !== 'one') {
            return ['text' => ($res['match'] ?? '') === 'several' ? 'Several conference or event bookings match - give me the reference.' : (string)($res['not_found'] ?? 'No conference or event booking matches that.'), 'links' => $r['links'], 'chips' => []];
        }
        $bk = (array)$res['booking'];
        $owed = (float)($bk['still_owed'] ?? 0);
        $text = '**' . ($bk['guest_name'] ?? '') . '** (' . ($bk['reference'] ?? '') . ', ' . ($bk['kind'] ?? '') . '): '
            . ($owed <= BALANCE_TOLERANCE ? '**fully paid** - nothing is owed.' : '**' . rh_ai_fmt_cur($cur, $owed) . ' still to pay**')
            . (isset($bk['total']) ? ' (total ' . rh_ai_fmt_cur($cur, $bk['total']) . ')' : '') . '. Payment status: ' . ($bk['payment'] ?? '-') . '.';
        return ['text' => $text, 'links' => $r['links'], 'chips' => []];
    }
    // A till or room-service order
    $args = isset($mi['table']) ? ['table' => (string)$mi['table']] : (isset($mi['room']) ? ['room' => (string)$mi['room']] : ($mi['who'] !== '' ? ['reference' => (string)$mi['who']] : []));
    if (!$args) {
        return null;
    }
    if (!rh_ai_tool_allowed($user, 'order_status')) {
        return $declined('order_status');
    }
    $r = rh_ai_run_tool($db, $user, $conv, 'order_status', $args);
    if (!$r['ok']) {
        return null;
    }
    rh_ai_message_add($db, $conv, 'tool', '', ['tool_name' => 'order_status', 'args' => $args, 'result' => $r['json'], 'agent' => 'local']);
    $res = (array)$r['result'];
    $orders = isset($res['order']) ? [(array)$res['order']] : (is_array($res['orders'] ?? null) ? $res['orders'] : []);
    if (!$orders) {
        return ['text' => (string)($res['not_found'] ?? 'No open order matches that.'), 'links' => $r['links'], 'chips' => []];
    }
    $lines = [];
    foreach (array_slice($orders, 0, 5) as $o) {
        if (!isset($o['balance_left']) || !is_numeric($o['balance_left'])) {
            $lines[] = ($o['reference'] ?? 'Order') . ': ask for this order by its reference to see its balance';
            continue;
        }
        $left = (float)$o['balance_left'];
        $lines[] = ($o['reference'] ?? 'Order') . ': ' . ($left <= BALANCE_TOLERANCE ? 'fully paid' : rh_ai_fmt_cur($cur, $left) . ' left to pay') . (isset($o['paid_so_far']) && is_numeric($o['paid_so_far']) ? ' (paid ' . rh_ai_fmt_cur($cur, $o['paid_so_far']) . ')' : '');
    }
    return ['text' => '**What is left to pay**' . rh_ai_local_lines($lines) . "\n\nPayments are taken on the POS screen.", 'links' => $r['links'], 'chips' => []];
}
