<?php
/**
 * The booked-rate line for a stay whose nights change (extend, shorten, change dates, early / late checkout):
 * "K356,000 × 4 nights = K1,424,000" — the rate the booking was MADE at, times the new nights, equal to the new total
 * rh_reprice_at_booked_rate() charges (owner 2026-11-09: show the booked rate and the nights, e.g. "x3").
 *
 *   rh_booked_rate_change_line(array $rp, float $packageGross, int $newNights, array $opts = []): array   PURE
 *   rh_booked_rate_change_for_booking(array $b, array $rp, int $newNights, array $opts = []): array      bookings row
 *   rh_booking_money_card(array $b, array $rate, array $opts = []): array   PURE: "How the total is made up" card
 *
 * $rp: rh_reprice_at_booked_rate() result (new_gross, new_child_supplement). $packageGross: rh_booked_stay_split()
 * package_gross (packages are kept as they are, never multiplied by nights). $opts: as rh_booked_rate_text()
 * (currency, status); 'child' = the repriced children charge on the all-in basis (rh_booked_rate_child_gross());
 * without it $rp['new_child_supplement'] is used as it is.
 *
 * rh_reprice_at_booked_rate()'s per_night includes the children charge; rh_booked_rate_text() shows the room rate
 * WITHOUT it and the children charge as its own "+ children" part. Feeding the repriced room figure and the repriced
 * children charge back through rh_booked_rate_text() keeps the printed rate equal to the original booked rate and the
 * "=" equal to what is actually charged.
 */

require_once __DIR__ . '/booked-rate.php';

if (!function_exists('rh_booked_rate_change_line')) {
    function rh_booked_rate_change_line(array $rp, float $packageGross, int $newNights, array $opts = []): array
    {
        $pkg = max(0.0, round($packageGross, 2));
        $newGross = round((float)($rp['new_gross'] ?? 0), 2);
        $child = array_key_exists('child', $opts) ? (float)$opts['child'] : (float)($rp['new_child_supplement'] ?? 0);
        return rh_booked_rate_text(
            ['room_gross' => max(0.0, round($newGross - $pkg, 2)), 'package_gross' => $pkg, 'nights' => max(1, $newNights)],
            array_merge($opts, ['child' => $child])
        );
    }
}

if (!function_exists('rh_booked_rate_change_for_booking')) {
    /**
     * The same line from a bookings row (the row BEFORE the change) and its rh_reprice_at_booked_rate() result: the
     * package from rh_booked_stay_split() and the children charge on the same all-in basis rh_booked_rate_line() uses.
     * Needs config/database.php (rh_booked_stay_split, rh_booking_has_levy); without it ok = false.
     */
    function rh_booked_rate_change_for_booking(array $b, array $rp, int $newNights, array $opts = []): array
    {
        if (!function_exists('rh_booked_stay_split') || !function_exists('rh_booking_has_levy')) {
            return rh_booked_rate_text(['room_gross' => 0, 'nights' => 1]);
        }
        $opts['child'] = rh_booked_rate_child_gross((float)($rp['new_child_supplement'] ?? 0), rh_booking_has_levy($b));
        return rh_booked_rate_change_line($rp, (float)rh_booked_stay_split($b)['package_gross'], $newNights, $opts);
    }
}

if (!function_exists('rh_booking_money_card')) {
    /**
     * PURE. "How the total is made up" for one bookings row, from its STORED columns only (never today's price):
     *   Room: K356,000 × 3 nights = K1,068,000 (all-in) [+ child charge, package, rate-plan discount note]
     *   Net price (excl. VAT and levy) = total_amount - tourism_levy_amount, VAT x%, Tourism levy x%, "= Total"
     *   [Folio extras], [Paid so far], Balance due (amount_due) or Credit owed to guest (credit_balance).
     * The "= Total" line is shown only when net + VAT + levy and the all-in parts both equal the total within
     * BALANCE_TOLERANCE; otherwise (older rows) the stored figures are listed with a note and no sum.
     * A cancelled / no-show / expired booking says so; it shows the kept amount (cancellation_retained_amount) and the
     * stored amount_due as "Still owed (... booking)" when above BALANCE_TOLERANCE, else says no balance is due.
     *
     * $rate: rh_booked_rate_line() for the same row. $opts: currency.
     * Line kinds: part (inside the all-in total), note (shown, not added), tax (how the total splits), total,
     * money (after the total: extras / paid / balance).
     *
     * @return array{ok:bool,title:string,help:string,lines:array<int,array{label:string,value:string,kind:string}>,adds_up:bool,closed:bool,note:string}
     */
    function rh_booking_money_card(array $b, array $rate, array $opts = []): array
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $cur = trim((string)($opts['currency'] ?? '')) !== '' ? trim((string)$opts['currency']) : 'MWK';
        $m = static function (float $v) use ($cur): string {
            return rh_hist_money($v, $cur);
        };
        $pct = static function ($p): string {
            return rtrim(rtrim(number_format((float)$p, 2), '0'), '.') . '%';
        };
        $out = ['ok' => false, 'title' => 'How the total is made up',
            'help' => 'From the figures saved on this booking when it was made (or last repriced), not today\'s room price.',
            'lines' => [], 'adds_up' => false, 'closed' => false, 'note' => ''];

        $total = round((float)($b['total_with_vat'] ?? 0), 2);
        if ($total <= $tol) {
            $total = round((float)($b['total_amount'] ?? 0) + (float)($b['vat_amount'] ?? 0), 2);
        }
        if ($total <= $tol) {
            return $out;
        }
        $levy = round(max(0.0, (float)($b['tourism_levy_amount'] ?? 0)), 2);
        $vat = round(max(0.0, (float)($b['vat_amount'] ?? 0)), 2);
        $net = round((float)($b['total_amount'] ?? 0) - $levy, 2);
        $status = strtolower(trim((string)($b['status'] ?? '')));
        $closed = in_array($status, ['cancelled', 'no-show', 'expired'], true);
        $lines = [];

        $rateOk = !empty($rate['ok']);
        $child = $rateOk ? (float)($rate['child'] ?? 0) : 0.0;
        $pkg = $rateOk ? (float)($rate['package'] ?? 0) : 0.0;
        if ($rateOk) {
            $n = max(1, (int)($rate['nights'] ?? 1));
            $lines[] = ['label' => 'Room', 'kind' => 'part',
                'value' => rh_booked_rate_money((float)$rate['per_night'], $cur) . ' × ' . ($n === 1 ? '1 night' : $n . ' nights') . ' = ' . rh_booked_rate_money((float)$rate['room_total'], $cur) . ' (all-in)'];
            if ($child > $tol) {
                $lines[] = ['label' => 'Child charge', 'kind' => 'part', 'value' => $m($child)];
            }
            if ($pkg > $tol) {
                $lines[] = ['label' => 'Package', 'kind' => 'part', 'value' => $m($pkg)];
            }
        }
        $disc = (float)($b['rate_plan_discount'] ?? 0);
        if ($disc > $tol) {
            $plan = trim((string)($b['rate_plan_label'] ?? ''));
            $dn = max(1, (int)($b['number_of_nights'] ?? 1));
            $lines[] = ['label' => ($plan !== '' ? $plan : 'Rate plan discount') . ' (already taken off the room rate)', 'kind' => 'note',
                'value' => '-' . $m(round($disc * $dn, 2))];
        } elseif ($disc < -$tol) {
            // Negative = a surcharge (e.g. festive season), already inside the room rate: a note, never added.
            $plan = trim((string)($b['rate_plan_label'] ?? ''));
            $dn = max(1, (int)($b['number_of_nights'] ?? 1));
            $lines[] = ['label' => ($plan !== '' ? $plan . ' surcharge' : 'Rate plan surcharge') . ' (already in the room rate)', 'kind' => 'note',
                'value' => '+' . $m(round(-$disc * $dn, 2))];
        }

        $lines[] = ['label' => ($child > $tol || $pkg > $tol) ? 'Net price (excl. VAT and levy)' : 'Net room price (excl. VAT and levy)',
            'kind' => 'tax', 'value' => $m($net)];
        if ($vat > $tol) {
            $vr = (float)($b['vat_rate'] ?? 0);
            $lines[] = ['label' => 'VAT' . ($vr > 0 ? ' ' . $pct($vr) : ''), 'kind' => 'tax', 'value' => $m($vat)];
        }
        if ($levy > $tol) {
            $lp = (float)($b['tourism_levy_percent'] ?? 0);
            $lines[] = ['label' => 'Tourism levy' . ($lp > 0 ? ' ' . $pct($lp) : ''), 'kind' => 'tax', 'value' => $m($levy)];
        }

        $taxSum = round($net + $vat + $levy, 2);
        $partsOk = !$rateOk || abs(round((float)$rate['room_total'] + $child + $pkg, 2) - $total) <= $tol;
        $addsUp = $net > $tol && abs($taxSum - $total) <= $tol && $partsOk;
        if ($addsUp) {
            $lines[] = ['label' => '= Total', 'kind' => 'total', 'value' => $m($total)];
        } else {
            $out['note'] = 'Figures as saved on this older booking. They do not add up exactly, so no sum is shown; the total is '
                . $m($total) . '.';
        }

        $extras = round((float)($b['folio_charges_total'] ?? 0), 2);
        if ($extras > $tol) {
            $lines[] = ['label' => 'Folio extras (meals, services and other charges)', 'kind' => 'money', 'value' => $m($extras)];
        }
        $word = $status === 'no-show' ? 'no-show' : $status;
        $kept = round((float)($b['cancellation_retained_amount'] ?? 0), 2);
        if ($closed && $kept > $tol) {
            $lines[] = ['label' => 'Kept on cancellation (still billed)', 'kind' => 'money', 'value' => $m($kept)];
        }
        $paid = round((float)($b['amount_paid'] ?? 0), 2);
        if ($paid > $tol) {
            $lines[] = ['label' => 'Paid so far', 'kind' => 'money', 'value' => $m($paid)];
        }
        // amount_due is the stored figure Payments outstanding and receivables chase (a kept first night, unvoided
        // folio extras), so a closed booking still shows it when it is owed.
        $due = round((float)($b['amount_due'] ?? 0), 2);
        $credit = round((float)($b['credit_balance'] ?? 0), 2);
        if ($credit > $tol) {
            $lines[] = ['label' => 'Credit owed to guest', 'kind' => 'money', 'value' => $m($credit)];
        } elseif (!$closed) {
            $lines[] = ['label' => 'Balance due', 'kind' => 'money', 'value' => $m(max(0.0, $due))];
        }
        if ($closed && $due > $tol) {
            $lines[] = ['label' => 'Still owed (' . $word . ' booking)', 'kind' => 'money', 'value' => $m($due)];
        }
        if ($closed) {
            $out['note'] = trim('This booking is ' . ($status === 'no-show' ? 'a no-show' : $status) . ': these are the figures it was booked at'
                . ($due > $tol ? ', and ' . $m($due) . ' is still owed.' : ', and no balance is due.') . ' ' . $out['note']);
        }

        $out['ok'] = true;
        $out['lines'] = $lines;
        $out['adds_up'] = $addsUp;
        $out['closed'] = $closed;
        return $out;
    }
}
