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
    if (preg_match('/\bno[\s\-]?shows?\b/u', $l) && preg_match('/^(?:mark|set|make|record|log|put|flag|change|move|turn|register)\b|^no[\s\-]?show\b/u', $l)) {
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
 * @return array{policy:string,label:string,paid:float,refund:float,kept:float,can_mark:bool,why:string}
 */
function rh_ai_noshow_money(PDO $db, array $b): array
{
    $policy = function_exists('getSetting') ? (string)getSetting('no_show_refund_policy', 'none') : 'none';
    $labels = ['none' => 'no automatic refund (the hotel keeps what was paid)', 'first_night' => 'the first night is kept; the rest is queued for refund', 'full' => 'everything paid is queued for refund (folio charges stay billed)'];
    $f = function_exists('getBookingFolioSummary') ? getBookingFolioSummary((int)$b['id']) : [];
    $paid = round(max(0.0, (float)($f['amount_paid'] ?? 0)), 2);
    $folio = round((float)($f['extras_total'] ?? 0), 2);
    $refund = 0.0;
    if ($paid > BALANCE_TOLERANCE && in_array($policy, ['full', 'first_night'], true)) {
        if ($policy === 'full') {
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
    return ['policy' => $policy, 'label' => $labels[$policy] ?? $policy, 'paid' => $paid, 'refund' => $refund, 'kept' => round(max(0.0, $paid - $refund), 2), 'can_mark' => $can, 'why' => $why];
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
    $lines = [
        'payment' => "I don't take or record payments - record it on the booking (Add payment), so the receipt, the balance and the reports stay right.",
        'refund' => "I don't do refunds - they are recorded by staff on the Payments screen (Refund).",
        'noshow' => "I don't mark no-shows - a no-show moves money (what is kept or refunded), so it is marked on the booking screen (No-show).",
        'charge' => "I don't post charges - add it on the booking's folio (Add charge) or on the till.",
    ];
    $text = $lines[$mi['kind']];
    $links = [];
    $canOpen = static fn(string $p): bool => function_exists('rhCanOpenPage') ? rhCanOpenPage($uid, $p) : false;
    $id = rh_ai_tool_allowed($user, 'get_booking') && rh_ai_module_on('bookings') ? rh_ai_money_booking($db, $user, $conv, (string)$mi['who'], $focus, $pageBooking) : 0;
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
            $text .= "\n\n**If marked as a no-show** (the hotel's rule: " . $ns['label'] . '): paid ' . rh_ai_fmt_cur($cur, $ns['paid']) . ', kept ' . rh_ai_fmt_cur($cur, $ns['kept'])
                . ', queued for refund ' . rh_ai_fmt_cur($cur, $ns['refund']) . '.' . ($ns['can_mark'] ? '' : ' ' . $ns['why']);
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
    $ask = null;
    if (preg_match('/\b(fully|completely|all) paid\b|\bpaid (in full|up|everything|it all|off)\b|\b(settled|paid off)\b|\bpaid for\b.*\?$/u', $l)) {
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
    $out = ['ask' => $ask, 'subject' => $subject, 'who' => ''];
    if (preg_match('/\b([A-Z]{2,6}20\d{6,10}|[a-z]{2,6}-\d[\d\-]{3,30})\b/iu', $raw, $m)) {
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
    return $head . "\n\n" . rh_ai_booking_line($res) . ($lastLine !== '' ? "\n" . $lastLine : '') . "\n\nThe booking's own bill, the same figures as the booking screen.";
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
        return ['text' => rh_ai_money_booking_text($db, (array)$r['result'], (string)$mi['ask'], $id), 'links' => $r['links'], 'chips' => ['What is included?'], 'focus' => $id, 'focus_offer' => 0, 'local' => ['tool' => 'get_booking', 'view' => []]];
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
