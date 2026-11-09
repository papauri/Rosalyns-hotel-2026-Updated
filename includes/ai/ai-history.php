<?php

/**
 * The History panel's "Summary" (owner 2026-11-09: "We can even enhance it with extra info if we have AI assistant
 * available"): three to five plain sentences at the top of a booking's or a payment's History, written from the steps
 * the panel already shows (includes/booking-history.php). Also the summary line of the booking_history lookup.
 *
 *   rh_ai_history_summary(array $events, bool $names = true): array   sentences, [] when there is nothing to say
 *
 * Local only, no model call and no tokens: every amount, reference, date and name in a sentence is copied from a step
 * (its "what" line, the price explanation's rate / total / today's-price lines, or the payment's owed-before/after and
 * overpay lines). Nothing is added up or worked out, nothing is said to be missing, and notes, reasons and other text
 * people typed are never copied. $names = false (the chat lookup) names roles only ("a staff member (receptionist)",
 * "the guest", "the system"), so no name reaches a cloud model.
 *
 * Pure functions: no database, no session. The caller has already checked the Assistant is on and the person may see
 * this booking or payment.
 */

require_once dirname(__DIR__) . '/booking-history-format.php';

/** "by Jane Doe (receptionist)" / "by the system" / "by the guest" / "online"; '' when nobody is recorded. */
function rh_ai_hist_by(string $who, bool $names): string
{
    $who = trim($who);
    if ($who === '' || $who === 'Not recorded') {
        return '';
    }
    if ($who === 'System') {
        return 'by the system';
    }
    if ($who === 'Online booking' || preg_match('/\(online booking\)$/', $who)) {
        return $names && $who !== 'Online booking' ? 'online by ' . trim(preg_replace('/\s*\(online booking\)$/', '', $who)) : 'online';
    }
    if (strpos($who, 'Guest') === 0) {
        return $names ? 'by the g' . substr($who, 1) : 'by the guest';
    }
    if ($names) {
        return 'by ' . $who;
    }
    $who = trim((string)preg_replace('/\s+—\s+from the .+$/u', '', $who)); // "Name (role) — from the credit note issued with it"
    if (preg_match('/^Staff member #\d+$/', $who)) {
        return 'by a staff member';
    }
    if (preg_match('/\(([^()]+)\)$/', $who, $m)) {
        return 'by a staff member (' . trim($m[1]) . ')';
    }
    return ''; // a stored name with no role: not said to be staff (it may not be)
}

/** '2026-11-07 09:36:12' -> '7 Nov 2026'. */
function rh_ai_hist_day(array $e): string
{
    $ts = strtotime((string)($e['at'] ?? ''));
    return $ts ? date('j M Y', $ts) : '';
}

/** A step's own line with no closing full stop. */
function rh_ai_hist_what(array $e): string
{
    return rtrim(trim(preg_replace('/\s+/u', ' ', (string)($e['what'] ?? ''))), '.');
}

/** One sentence, trimmed to a sane length, ending with a full stop. */
function rh_ai_hist_sentence(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if ($s === '') {
        return '';
    }
    if (mb_strlen($s) > 320) {
        $s = rtrim(mb_substr($s, 0, 319)) . '…';
    }
    return preg_match('/[.!?…]$/u', $s) ? $s : $s . '.';
}

/** The detail lines of a step. */
function rh_ai_hist_lines(array $e): array
{
    return array_values(array_filter(array_map('trim', explode("\n", (string)($e['detail'] ?? ''))), 'strlen'));
}

/** Is this step a payment received (not a status change or a removal)? */
function rh_ai_hist_is_payment(array $e): bool
{
    if (($e['kind'] ?? '') !== 'payment') {
        return false;
    }
    $w = rh_ai_hist_what($e);
    return $w !== '' && stripos($w, 'Payment status set to') !== 0 && !preg_match('/\bremoved$/i', $w);
}

/** The latest step of these kinds (steps are oldest first), or null. */
function rh_ai_hist_latest(array $events, array $kinds, ?callable $also = null): ?array
{
    for ($i = count($events) - 1; $i >= 0; $i--) {
        $e = $events[$i];
        if (is_array($e) && in_array((string)($e['kind'] ?? ''), $kinds, true) && ($also === null || $also($e))) {
            return $e;
        }
    }
    return null;
}

/**
 * The summary sentences for a History list (oldest first, as rh_booking_history / rh_payment_history return it):
 *  1. when and by whom it was booked, with the price line and total as the price explanation states them;
 *  2. the room's price today when it differs (the booking keeps its price), or that the price was changed since;
 *  3. the latest payment, with owed before / after and the overpay credit note when the step records them;
 *  4. the latest refund, else the latest credit note step not already named;
 *  5. the latest status step (confirmed, checked in or out, cancelled, no-show), with the refund due a cancellation records.
 */
function rh_ai_history_summary(array $events, bool $names = true): array
{
    $events = array_values(array_filter($events, 'is_array'));
    if (!$events) {
        return [];
    }
    $out = [];

    // 1. Booked
    $created = rh_ai_hist_latest(array_reverse($events), ['created']); // the first one
    $px = null;
    foreach ($events as $e) {
        if (($e['kind'] ?? '') === 'price' && rh_ai_hist_what($e) === 'How the price was worked out') {
            $px = $e;
            break;
        }
    }
    $pxLines = $px ? rh_ai_hist_lines($px) : [];
    $rate = '';
    $total = '';
    $today = '';
    foreach ($pxLines as $l) {
        if ($rate === '' && preg_match('/^(.+? a night × \d+ nights? = .+?)(?:, using | \(worked out|\.$)/u', $l, $m)) {
            $rate = $m[1] . (strpos($l, '(worked out from the saved total') !== false ? ' (worked out from the saved total)' : '');
        } elseif ($rate === '' && preg_match('/^(Price set by hand: .+?)\.$/u', $l, $m)) {
            $rate = $m[1];
        } elseif ($total === '' && preg_match('/^Total (.+?)(?:, which includes |\.$)/u', $l, $m)) {
            $total = $m[1];
        } elseif ($today === '' && preg_match('/^Today\'s price for this room is (.+?) a night/u', $l, $m)) {
            $today = $m[1];
        }
    }
    if ($created) {
        $day = rh_ai_hist_day($created);
        $by = rh_ai_hist_by((string)($created['who'] ?? ''), $names);
        $s = 'Booked' . ($day !== '' ? ' on ' . $day : '') . ($by !== '' ? ' ' . $by : '');
        if ($rate !== '') {
            $s .= ' at ' . $rate . ($total !== '' && strpos($rate, '= ' . $total) === false ? ', total ' . $total : '');
        } else {
            $first = rh_ai_hist_lines($created)[0] ?? ''; // the booking line the panel shows (room, nights, dates, total)
            if ($first !== '') {
                $s .= ': ' . rtrim($first, '.');
            }
        }
        if (in_array(trim((string)($created['who'] ?? '')), ['', 'Not recorded'], true) && $day !== '') {
            $s .= '. Who made it is not recorded'; // only when the step itself says so (a name left out for privacy is not "not recorded")
        }
        $out[] = rh_ai_hist_sentence($s);
    }

    // 2. The room's price since
    if ($today !== '') {
        $out[] = rh_ai_hist_sentence('The room\'s price has changed since: today it is ' . $today . ' a night, and this booking keeps the price it was made at');
    } else {
        $later = rh_ai_hist_latest($events, ['price'], static function ($e) {
            return strpos((string)($e['detail'] ?? ''), 'This does not change this booking') === 0;
        });
        if ($later) {
            $out[] = rh_ai_hist_sentence('The room\'s price was changed on ' . rh_ai_hist_day($later) . ' (' . rh_ai_hist_what($later) . '); this booking keeps the price it was made at');
        }
    }

    // 3. The latest payment
    $pays = array_values(array_filter($events, 'rh_ai_hist_is_payment'));
    $said = '';
    if ($pays) {
        $p = $pays[count($pays) - 1];
        $by = rh_ai_hist_by((string)($p['who'] ?? ''), $names);
        $s = (count($pays) > 1 ? 'The latest payment: on ' : 'On ') . rh_ai_hist_day($p) . ', ' . lcfirst(rh_ai_hist_what($p)) . ($by !== '' ? ' ' . $by : '') . '.';
        $detail = (string)($p['detail'] ?? '');
        $owed = [];
        if (preg_match('/Owed before: (.+?)(?=\s·|\.\s|\.$|$)/mu', $detail, $m)) {
            $owed[] = 'owed before ' . $m[1];
        }
        if (preg_match('/[Oo]wed after: (.+?)(?=\s·|\.\s|\.$|$)/mu', $detail, $m)) {
            $owed[] = 'owed after ' . $m[1];
        }
        if ($owed) {
            $s .= ' ' . ucfirst(implode(', ', $owed)) . '.';
        }
        if (preg_match('/^More than was owed: .+$/mu', $detail, $m)) {
            $s .= ' ' . rtrim($m[0], '.') . '.';
        } elseif (preg_match('/The overpay box was ticked\.(?: Credit note (\S+) for (.+?) issued for the extra\.)?/u', $detail, $m)) {
            $s .= !empty($m[1]) ? ' More than was owed: the overpay box was ticked, so ' . $m[2] . ' went to credit note ' . $m[1] . '.' : ' The overpay box was ticked.';
        }
        $said = $s;
        $out[] = rh_ai_hist_sentence($s);
    }

    // 4. The latest refund, else the latest credit note step not already named
    $rf = rh_ai_hist_latest($events, ['refund']);
    if ($rf === null) {
        $rf = rh_ai_hist_latest($events, ['credit_note'], static function ($e) use ($said) {
            // skip only the issue step of a credit note the payment sentence already named; a later "used" or "voided" still shows
            $w = (string)($e['what'] ?? '');
            return !(preg_match('/^Credit note (\S+)(?: for .+)? issued/', $w, $m) && $said !== '' && strpos($said, $m[1]) !== false);
        });
    }
    if ($rf) {
        $by = rh_ai_hist_by((string)($rf['who'] ?? ''), $names);
        $out[] = rh_ai_hist_sentence('On ' . rh_ai_hist_day($rf) . ', ' . lcfirst(rh_ai_hist_what($rf)) . ($by !== '' ? ' ' . $by : ''));
    }

    // 5. Where it stands: the latest status step
    $st = rh_ai_hist_latest($events, ['status', 'check_in', 'check_out', 'cancel']);
    if ($st) {
        $by = rh_ai_hist_by((string)($st['who'] ?? ''), $names);
        $s = rh_ai_hist_what($st) . ' on ' . rh_ai_hist_day($st) . ($by !== '' ? ' ' . $by : '') . '.';
        if (($st['kind'] ?? '') === 'cancel') {
            foreach (rh_ai_hist_lines($st) as $l) {
                if (preg_match('/^Refund due: [^\n]+$/u', $l)) {
                    $s .= ' ' . rtrim($l, '.') . '.';
                    break;
                }
            }
        }
        $out[] = rh_ai_hist_sentence($s);
    }

    return array_values(array_filter($out, 'strlen'));
}
