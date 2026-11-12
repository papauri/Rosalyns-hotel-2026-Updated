<?php
/**
 * Guest history: ONE rule for every place that says how often a guest has stayed and what they have paid
 * (owner 2026-11-12 "Make sure Guest history is also accurate"). Used by the booking page's "Guest history" panel
 * (admin/booking-details.php) and the Create Booking returning-guest lookup (admin/api/guest-lookup.php), so the
 * same guest gets the same answer on both.
 *
 * Rules:
 *   - Guest = the booking email, trimmed and case-insensitive (LOWER(TRIM())). No phone/name merging.
 *     A blank email has no history. Deleted bookings (deleted_at set) are never counted, listed or linked.
 *   - Completed stay = a checked-out booking. A group of rooms booked together (primary_booking_id) is ONE stay.
 *     In-house (checked-in), upcoming, cancelled, no-show and expired bookings are not stays.
 *   - Returning guest = at least one completed stay other than the booking being looked at (and its group).
 *   - Received = money actually taken from the guest, net of refunds, across all their non-deleted room bookings
 *     (cancelled ones included: money kept on a cancellation is money received). It is the canonical net-paid rule
 *     of the folio / Still owed (getBookingFolioSummary, recalculateBookingFinancials, rh_sum_account_paid): gross
 *     total_amount of completed/paid/refunded/partially_refunded non-refund rows, minus refunds paid out
 *     (admin/includes/finance-refunds.php: refund_status completed/processing, full total_amount), deleted payment
 *     rows ignored, each booking floored at 0, then added up. Voided/failed/pending payments never count.
 *   - Money is only read when the caller says the viewer may see it; otherwise it is null (never 0).
 *   - A failed query gives ok=false: callers show a dash, never "0" or "First stay".
 */

require_once __DIR__ . '/../admin/includes/finance-refunds.php';

if (!function_exists('rh_guest_history_email_sql')) {
    /** SQL condition: booking alias $a belongs to the guest whose email is bound at ? (trimmed, case-insensitive). */
    function rh_guest_history_email_sql(string $a = 'b'): string
    {
        $p = rh_refund_alias($a);
        return "LOWER(TRIM({$p}guest_email)) = LOWER(TRIM(?))";
    }

    /** SQL expression: one payments row's effect on what the guest has paid (the canonical net-paid CASE, gross). */
    function rh_guest_history_paid_case_sql(string $a = 'p'): string
    {
        $p = rh_refund_alias($a);
        return "CASE WHEN {$p}payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE({$p}payment_type,'') <> 'refund'"
            . " THEN {$p}total_amount"
            . ' WHEN ' . rh_refund_settled_sql($a) . ' THEN -' . rh_refund_amount_sql($a)
            . ' ELSE 0 END';
    }

    /** Plain stay count wording, e.g. "No completed stays", "1 completed stay", "3 completed stays". */
    function rh_guest_history_stay_label(int $stays): string
    {
        if ($stays <= 0) {
            return 'No completed stays';
        }
        return $stays === 1 ? '1 completed stay' : $stays . ' completed stays';
    }

    /** The empty result (also the shape every caller can rely on). */
    function rh_guest_history_blank(bool $ok = true): array
    {
        return [
            'ok' => $ok,
            'completed_stays' => 0,       // checked-out stays, all bookings (groups count once)
            'other_completed_stays' => 0, // the same, leaving out the booking being looked at and its group
            'in_house' => 0,              // checked-in bookings right now
            'total_bookings' => 0,        // every non-deleted booking under this email, any status
            'other_bookings' => 0,        // the same, leaving out the booking being looked at
            'is_returning' => false,
            'last_stay' => null,          // latest checked-out booking: id, booking_reference, check_in_date, check_out_date
            'recent_other' => [],         // latest other bookings (not deleted), newest check-in first
            'money' => null,              // ['received' => net, 'refunded' => refunds paid out, 'to_credit' => part moved to credit notes] or null
        ];
    }

    /**
     * History of the guest with $email. $currentBookingId: the booking being looked at (0 = none) — it is left out of
     * "other" figures and the list, and its group is left out of the returning test. $canSeeMoney: read the money.
     */
    function rh_guest_history(PDO $pdo, string $email, int $currentBookingId = 0, bool $canSeeMoney = false, int $listLimit = 6): array
    {
        $out = rh_guest_history_blank(true);
        $email = trim($email);
        if ($email === '') {
            return $out;
        }
        $emailSql = rh_guest_history_email_sql('b');
        try {
            $curGroup = 0;
            if ($currentBookingId > 0) {
                $g = $pdo->prepare('SELECT COALESCE(primary_booking_id, id) FROM bookings WHERE id = ?');
                $g->execute([$currentBookingId]);
                $curGroup = (int)($g->fetchColumn() ?: $currentBookingId);
            }
            $c = $pdo->prepare("
                SELECT COUNT(*) AS total_bookings,
                       COALESCE(SUM(CASE WHEN b.id <> ? THEN 1 ELSE 0 END), 0) AS other_bookings,
                       COUNT(DISTINCT CASE WHEN b.status = 'checked-out' THEN COALESCE(b.primary_booking_id, b.id) END) AS completed_stays,
                       COUNT(DISTINCT CASE WHEN b.status = 'checked-out' AND COALESCE(b.primary_booking_id, b.id) <> ? THEN COALESCE(b.primary_booking_id, b.id) END) AS other_completed_stays,
                       COALESCE(SUM(CASE WHEN b.status = 'checked-in' THEN 1 ELSE 0 END), 0) AS in_house
                FROM bookings b
                WHERE b.deleted_at IS NULL AND {$emailSql}
            ");
            $c->execute([$currentBookingId, $curGroup, $email]);
            $row = $c->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (['total_bookings', 'other_bookings', 'completed_stays', 'other_completed_stays', 'in_house'] as $k) {
                $out[$k] = (int)($row[$k] ?? 0);
            }
            $out['is_returning'] = $out['other_completed_stays'] >= 1;

            $l = $pdo->prepare("
                SELECT b.id, b.booking_reference, b.check_in_date, b.check_out_date
                FROM bookings b
                WHERE b.deleted_at IS NULL AND b.status = 'checked-out' AND {$emailSql}
                ORDER BY b.check_out_date DESC, b.id DESC
                LIMIT 1
            ");
            $l->execute([$email]);
            $out['last_stay'] = $l->fetch(PDO::FETCH_ASSOC) ?: null;

            $listLimit = max(0, min(50, $listLimit));
            if ($listLimit > 0) {
                $r = $pdo->prepare("
                    SELECT b.id, b.booking_reference, b.check_in_date, b.check_out_date, b.status
                    FROM bookings b
                    WHERE b.deleted_at IS NULL AND b.id <> ? AND {$emailSql}
                    ORDER BY b.check_in_date DESC, b.id DESC
                    LIMIT {$listLimit}
                ");
                $r->execute([$currentBookingId, $email]);
                $out['recent_other'] = $r->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($canSeeMoney) {
                $m = $pdo->prepare("
                    SELECT COALESCE(SUM(GREATEST(0, t.net)), 0) AS received, COALESCE(SUM(t.refunded), 0) AS refunded, COALESCE(SUM(t.to_credit), 0) AS to_credit
                    FROM (
                        SELECT p.booking_id,
                               COALESCE(SUM(" . rh_guest_history_paid_case_sql('p') . "), 0) AS net,
                               " . rh_refund_settled_sum_sql('p') . " AS refunded,
                               " . rh_refund_to_credit_sum_sql('p') . " AS to_credit
                        FROM payments p
                        INNER JOIN bookings b ON b.id = p.booking_id
                        WHERE p.booking_type = 'room' AND p.deleted_at IS NULL
                          AND b.deleted_at IS NULL AND {$emailSql}
                        GROUP BY p.booking_id
                    ) t
                ");
                $m->execute([$email]);
                $mr = $m->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['money'] = [
                    'received' => round((float)($mr['received'] ?? 0), 2),
                    'refunded' => round((float)($mr['refunded'] ?? 0), 2),
                    'to_credit' => round((float)($mr['to_credit'] ?? 0), 2), // part of refunded moved into credit notes (no cash out)
                ];
            }
        } catch (Throwable $e) {
            error_log('Guest history: ' . $e->getMessage());
            return rh_guest_history_blank(false);
        }
        return $out;
    }

    /**
     * One guest row for the Create Booking returning-guest lookup (JSON). Money keys are left out entirely when
     * the history has no money (hidden or failed), so the screen never shows a made-up 0.
     */
    function rh_guest_history_lookup_row(array $contact, array $h, string $currency): array
    {
        $stays = (int)($h['completed_stays'] ?? 0);
        $last = is_array($h['last_stay'] ?? null) ? $h['last_stay'] : null;
        $lastIn = $last ? (string)($last['check_in_date'] ?? '') : '';
        $ok = !empty($h['ok']);
        $row = [
            'email' => (string)($contact['guest_email'] ?? ''),
            'name' => (string)($contact['guest_name'] ?? ''),
            'phone' => (string)($contact['guest_phone'] ?? ''),
            'country' => (string)($contact['guest_country'] ?? ''),
            'address' => (string)($contact['guest_address'] ?? ''),
            'history_ok' => $ok,
            'total_bookings' => (int)($h['total_bookings'] ?? 0),
            'completed_stays' => $stays,
            'in_house' => (int)($h['in_house'] ?? 0),
            'last_check_in' => $lastIn,
            'last_check_out' => $last ? (string)($last['check_out_date'] ?? '') : '',
            'last_booking_ref' => $last ? (string)($last['booking_reference'] ?? '') : '',
            'currency' => $currency,
            'is_returning' => $ok && $stays >= 1,
            'stay_label' => $ok ? rh_guest_history_stay_label($stays) : 'History could not be loaded',
            'last_stay_label' => ($lastIn !== '' && strtotime($lastIn)) ? date('M j, Y', (int)strtotime($lastIn)) : '',
        ];
        if (is_array($h['money'] ?? null)) {
            $row['lifetime_spend'] = (float)$h['money']['received'];
            $row['refunded'] = (float)$h['money']['refunded'];
        }
        return $row;
    }
}
