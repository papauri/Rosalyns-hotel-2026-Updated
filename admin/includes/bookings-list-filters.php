<?php
/**
 * Bookings list filters (admin/bookings.php): the pure rules shared by the tab badges, the list, the page count,
 * the insight cards and the CSV export, so they can never disagree. No database, no settings.
 * Unit tested by scripts/tests/bookings_filters.php.
 *
 * Dates (owner 2026-11-11 "do what is standard", like Opera / Cloudbeds / Mews): the Dates select (?date_type=)
 * picks which date From / To apply to; both end days are included; absent or unknown = Arrival.
 *   arrival   check_in_date between From and To
 *   departure check_out_date between From and To
 *   staying   in-house on at least one night in the range: check_in_date <= To AND check_out_date > From
 *             (the departure day is not a night stayed, the hotel's night model, so a guest leaving on From is
 *             not "staying"; use Departure for them)
 *   booked    created_at on a hotel date between From and To: created_at >= From 00:00 AND < (To + 1 day) 00:00
 *             (the app's DB session time_zone is the hotel's, so these are hotel dates)
 * ?since= + ?since_kind= (Dashboard "Since you were last here"): rh_bk_since_clause.
 */

require_once __DIR__ . '/front-desk-counts.php'; // rh_fd_balance_tolerance: the one "owing" rule
require_once __DIR__ . '/../../includes/smart-search.php'; // the shared word-by-word / phone-number search matcher
require_once __DIR__ . '/../../includes/booking-pay-state.php'; // pure: rh_pay_state_key / rh_booking_pay_state (the badge rule)

if (!function_exists('rh_bk_search_where')) {
    /**
     * The search box (?search=) for the list and the CSV export (aliases b, r = rooms, ir = individual_rooms).
     * Every word must match one of: reference, guest / room guest name, email, phone, country, special requests,
     * room type, room number or name, or a live payment's reference / method / status. A typed phone number
     * matches whatever its spacing or +265 / 0 prefix.
     * @return array{sql:string, params:array} sql '' when the search is empty
     */
    function rh_bk_search_where(string $q): array
    {
        return rh_search_where($q, [
            'b.booking_reference', 'b.guest_name', 'b.room_guest_name', 'b.guest_email', 'b.guest_phone',
            'b.guest_country', 'b.special_requests', 'r.name',
            "COALESCE(ir.room_number, '')", "COALESCE(ir.room_name, '')",
        ], ['b.guest_phone'], [
            "EXISTS (
                SELECT 1
                FROM payments px
                WHERE px.booking_type = 'room'
                  AND px.booking_id = b.id
                  AND px.deleted_at IS NULL
                  AND (
                    px.payment_reference LIKE ? ESCAPE '!'
                    OR px.payment_method LIKE ? ESCAPE '!'
                    OR px.payment_status LIKE ? ESCAPE '!'
                  )
            )",
        ]);
    }
}

if (!function_exists('rh_bk_valid_date')) {
    /** 'Y-m-d' when it is a real calendar date, else ''. */
    function rh_bk_valid_date($d): string
    {
        $d = trim((string)$d);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return '';
        }
        return $d;
    }

    /** [from, to] cleaned; swapped when From is after To. */
    function rh_bk_date_range($from, $to): array
    {
        $from = rh_bk_valid_date($from);
        $to = rh_bk_valid_date($to);
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    /** ?date_type= => which date From / To apply to. Label per type (the Dates select). */
    function rh_bk_date_types(): array
    {
        return ['arrival' => 'Arrival', 'departure' => 'Departure', 'staying' => 'Staying', 'booked' => 'Booked on'];
    }

    /** Whitelisted ?date_type=; absent or unknown = 'arrival'. */
    function rh_bk_date_type($type): string
    {
        $t = strtolower(trim((string)$type));
        return isset(rh_bk_date_types()[$t]) ? $t : 'arrival';
    }

    /** 'Y-m-d' + 1 day (calendar arithmetic, no time of day involved). */
    function rh_bk_next_day(string $d): string
    {
        return (new DateTimeImmutable($d . ' 00:00:00'))->modify('+1 day')->format('Y-m-d');
    }

    /**
     * WHERE pieces for From / To on the chosen date type (one placeholder per clause, params in clause order).
     * $from / $to come from rh_bk_date_range (valid or '', From <= To); $type from rh_bk_date_type.
     */
    function rh_bk_date_scope(string $from, string $to, string $type): array
    {
        $type = rh_bk_date_type($type);
        $clauses = [];
        $params = [];
        if ($type === 'staying') {
            if ($to !== '') {
                $clauses[] = 'b.check_in_date <= ?';
                $params[] = $to;
            }
            if ($from !== '') {
                $clauses[] = 'b.check_out_date > ?';
                $params[] = $from;
            }
        } elseif ($type === 'booked') {
            if ($from !== '') {
                $clauses[] = 'b.created_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $clauses[] = 'b.created_at < ?';
                $params[] = rh_bk_next_day($to) . ' 00:00:00';
            }
        } else {
            $col = $type === 'departure' ? 'b.check_out_date' : 'b.check_in_date';
            if ($from !== '') {
                $clauses[] = $col . ' >= ?';
                $params[] = $from;
            }
            if ($to !== '') {
                $clauses[] = $col . ' <= ?';
                $params[] = $to;
            }
        }
        return ['clauses' => $clauses, 'params' => $params];
    }

    /** ?arrival=overdue: arrival date passed, still confirmed/pending (never checked in). '' when not set. */
    function rh_bk_arrival_filter($arrival): string
    {
        return trim((string)$arrival) === 'overdue' ? 'overdue' : '';
    }

    function rh_bk_arrival_clause(string $arrival): ?string
    {
        return $arrival === 'overdue' ? "b.check_in_date < CURDATE() AND b.status IN ('confirmed','pending')" : null;
    }

    /** ?departure=overdue: still checked in after the check-out date (the Dashboard "Overdue departures" chip). */
    function rh_bk_departure_filter($departure): string
    {
        return trim((string)$departure) === 'overdue' ? 'overdue' : '';
    }

    function rh_bk_departure_clause(string $departure): ?string
    {
        return $departure === 'overdue' ? "b.status = 'checked-in' AND b.check_out_date < CURDATE()" : null;
    }

    /**
     * ?balance=owing: amount still to pay above BALANCE_TOLERANCE, the same rule the Dashboard "Departures with a
     * balance" chip counts (rh_fd_departure_owes). The chip opens it with ?filter=checkout_today.
     */
    function rh_bk_balance_filter($balance): string
    {
        return trim((string)$balance) === 'owing' ? 'owing' : '';
    }

    /** ['clause' => ..., 'params' => [...]] or null; the tolerance is bound, never written into the SQL. */
    function rh_bk_balance_clause(string $balance): ?array
    {
        return $balance === 'owing' ? ['clause' => 'b.amount_due > ?', 'params' => [rh_fd_balance_tolerance()]] : null;
    }

    /**
     * ?since= (Dashboard "Since you were last here", plan DASHBOARD_FIRSTLOGIN B1): a real 'Y-m-d H:i:s' only (the
     * previous sign-in time), else ''. Also used by rh_fin_payments_where for Payments ?since=.
     */
    function rh_bk_since_filter($raw): string
    {
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
            return '';
        }
        $dt = DateTime::createFromFormat('!Y-m-d H:i:s', $raw);
        return ($dt !== false && $dt->format('Y-m-d H:i:s') === $raw) ? $raw : '';
    }

    /** ?since_kind= whitelist => the notice words. */
    function rh_bk_since_kinds(): array
    {
        return ['booked' => 'Booked', 'cancelled' => 'Cancelled', 'moved' => 'Moved to another room'];
    }

    /** ?since_kind= cleaned: 'booked' | 'cancelled' | 'moved', anything else ''. */
    function rh_bk_since_kind($raw): string
    {
        $k = is_string($raw) ? strtolower(trim($raw)) : '';
        return isset(rh_bk_since_kinds()[$k]) ? $k : '';
    }

    /**
     * ['clause' => sql, 'params' => [$since]] for ?since= + ?since_kind=, or null when either is not set. The one
     * rule the Dashboard counts (rh_dash_since_counts) and the list both use:
     *   booked    created on or after $since
     *   cancelled cancelled now, with a "-> cancelled" history entry on or after $since (the End of Day rule,
     *             rh_cancellations_between, with a date-time)
     *   moved     a room-type upgrade / downgrade, or a room (re)assignment / edit that changed an assigned room
     *             (old room set and not 0, different from the new one) on or after $since. A first assignment (old
     *             room JSON null) is not a move; "12" and 12 compare equal (both sides cast).
     */
    function rh_bk_since_clause(string $since, string $kind): ?array
    {
        $since = rh_bk_since_filter($since);
        $kind = rh_bk_since_kind($kind);
        if ($since === '' || $kind === '') {
            return null;
        }
        if ($kind === 'booked') {
            return ['clause' => 'b.created_at >= ?', 'params' => [$since]];
        }
        if ($kind === 'cancelled') {
            return ['clause' => "b.status = 'cancelled' AND b.id IN (SELECT t.booking_id FROM booking_timeline_logs t"
                . " WHERE t.new_value = 'cancelled' AND t.action_type IN ('status_change', 'financial', 'cancellation') AND t.created_at >= ?)",
                'params' => [$since]];
        }
        $old = "CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.old_values, '$.individual_room_id')), 'null') AS UNSIGNED)";
        $new = "CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.individual_room_id')), 'null') AS UNSIGNED)";
        return ['clause' => "b.id IN (SELECT a.booking_id FROM booking_audit_log a WHERE a.performed_at >= ?"
            . " AND (a.action IN ('upgraded', 'downgraded') OR (a.action IN ('room_assigned', 'modified')"
            . " AND {$old} > 0 AND {$old} <> {$new})))",
            'params' => [$since]];
    }

    /** Notice title for ?since=: "Booked since Mon 23 Nov, 18:40" (year added when not this year). '' when not set. */
    function rh_bk_since_words(string $since, string $kind): string
    {
        $since = rh_bk_since_filter($since);
        $kind = rh_bk_since_kind($kind);
        if ($since === '' || $kind === '') {
            return '';
        }
        $ts = strtotime($since);
        return rh_bk_since_kinds()[$kind] . ' since ' . date(date('Y', $ts) === date('Y') ? 'D j M, H:i' : 'D j M Y, H:i', $ts);
    }

    /** Title of the filter notice: the overdue / balance views name themselves, everything else is a search. */
    function rh_bk_notice_title(string $arrival, string $departure, string $balance = '', string $filterKey = ''): string
    {
        if ($balance === 'owing' && $arrival === '' && $departure === '') {
            return $filterKey === 'checkout_today' ? 'Departures with a balance' : 'Balance still to pay';
        }
        if ($arrival === 'overdue' && $departure === 'overdue') return 'Overdue arrivals and departures';
        if ($arrival === 'overdue') return 'Overdue arrivals only';
        if ($departure === 'overdue') return 'Overdue departures only';
        return 'Search filtered results';
    }

    /**
     * SQL: the "Total" the list shows (rh_booking_full_total): total_with_vat when set, else total_amount + vat_amount.
     * Constant text (tolerance is a number), never user input.
     */
    function rh_bk_full_total_sql(string $b = 'b'): string
    {
        $tol = sprintf('%.4F', rh_fd_balance_tolerance());
        return "(CASE WHEN COALESCE({$b}.total_with_vat, 0) > {$tol} THEN {$b}.total_with_vat"
            . " ELSE COALESCE({$b}.total_amount, 0) + COALESCE({$b}.vat_amount, 0) END)";
    }

    /** SQL: the value the Room column leads with for sorting: the room number when assigned, else the room type name. */
    function rh_bk_room_sort_sql(): string
    {
        return "COALESCE(NULLIF(TRIM(ir.room_number), ''), r.name, '')";
    }

    /**
     * ORDER BY for the Room header: assigned rooms always before unassigned ones (both directions), then in $dir
     * (asc|desc) natural order (numbers first, 2 before 10, then the text), then the room type name.
     */
    function rh_bk_room_sort_order(string $dir): string
    {
        $d = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $rd = $d === 'ASC' ? 'DESC' : 'ASC';
        $v = rh_bk_room_sort_sql();
        return "(ir.id IS NULL) ASC, (LEFT({$v}, 1) BETWEEN '0' AND '9') {$rd}, CAST({$v} AS UNSIGNED) {$d}, {$v} {$d}";
    }

    /** Header sort key => ORDER BY expression (server-sort.php appends the direction; 'room' is replaced by rh_bk_room_sort_order). */
    function rh_bk_sort_map(): array
    {
        return [
            'ref'       => 'b.booking_reference',
            'guest'     => 'b.guest_name',
            'room'      => rh_bk_room_sort_sql(),
            'check_in'  => 'b.check_in_date',
            'check_out' => 'b.check_out_date',
            'nights'    => 'DATEDIFF(b.check_out_date, b.check_in_date)',
            'guests'    => 'b.number_of_guests',
            'total'     => rh_bk_full_total_sql('b'),
            'status'    => 'b.status',
            'payment'   => rh_bk_pay_key_sql('b'), // the badge's key from the booking's money (step 13), not one payment record's
            'created'   => 'b.created_at',
            'balance'   => rh_bk_balance_due_sql('b'), // Balance due (plan Decision 9; deleted rows NULL = first ascending)
        ];
    }

    /** ?filter= shortcut => tab name. */
    function rh_bk_tab_map(): array
    {
        return [
            'checkin_today'  => 'today-checkins',
            'checkout_today' => 'today-checkouts',
            'checked_in'     => 'checked-in',
            'expiring_soon'  => 'expiring-soon',
            'paid'           => 'paid',
            'unpaid'         => 'unpaid',
            'today_bookings' => 'today-bookings',
            'week_bookings'  => 'week-bookings',
            'month_bookings' => 'month-bookings',
            'group_bookings' => 'group-bookings',
        ];
    }

    /**
     * The ?filter= key in force: ?filter=, else ?payment_status=paid|unpaid (Accounting Dashboard links). '' when none.
     */
    function rh_bk_filter_key($filter, $payment_status = ''): string
    {
        $map = rh_bk_tab_map();
        $f = trim((string)$filter);
        if (isset($map[$f])) {
            return $f;
        }
        $p = strtolower(trim((string)$payment_status));
        return ($f === '' && ($p === 'unpaid' || $p === 'paid')) ? $p : '';
    }

    /**
     * SQL twin of rh_booking_pay_state()'s key (includes/booking-pay-state.php, step 13 owner 2026-11-19): 'paid',
     * 'partial', 'unpaid' or 'refunded' from the row's own money, exactly as the badge and the pop-up decide it, so the
     * Paid / Unpaid tabs, their counts, the Payment filter and the export can never disagree with the badge.
     *   paid = ROUND(GREATEST(0, amount_paid), 2), due = ROUND(GREATEST(0, amount_due), 2),
     *   credit = ROUND(GREATEST(0, credit_balance), 2), billed = GREATEST(0, ROUND(paid + due - credit, 2)),
     *   closed = status cancelled / no-show / expired, refundedOut = stored payment_status 'refunded' (the row carries no
     *   refund total; the PHP rule reads the same flag).
     *   closed AND paid <= tol AND refundedOut AND due <= tol         -> 'refunded'
     *   closed AND billed <= tol AND paid <= tol AND NOT refundedOut  -> 'unpaid'
     *   due <= tol -> 'paid'; paid > tol -> 'partial'; else 'unpaid'.
     * Every room of a group and every company-billed room is one bookings row with its own figures, like its badge.
     * Constant text (the tolerance is a number, BALANCE_TOLERANCE), never user input.
     */
    function rh_bk_pay_key_sql(string $b = 'b'): string
    {
        $tol = sprintf('%.4F', rh_pay_state_tol());
        $paid = "ROUND(GREATEST(0, COALESCE({$b}.amount_paid, 0)), 2)";
        $due = "ROUND(GREATEST(0, COALESCE({$b}.amount_due, 0)), 2)";
        $credit = "ROUND(GREATEST(0, COALESCE({$b}.credit_balance, 0)), 2)";
        $billed = "GREATEST(0, ROUND({$paid} + {$due} - {$credit}, 2))";
        $closed = "LOWER(TRIM(COALESCE({$b}.status, ''))) IN ('cancelled', 'no-show', 'expired')";
        $refundedOut = "LOWER(TRIM(COALESCE({$b}.payment_status, ''))) = 'refunded'";
        return "(CASE"
            . " WHEN {$closed} AND {$paid} <= {$tol} AND {$refundedOut} AND {$due} <= {$tol} THEN 'refunded'"
            . " WHEN {$closed} AND {$billed} <= {$tol} AND {$paid} <= {$tol} AND NOT ({$refundedOut}) THEN 'unpaid'"
            . " WHEN {$due} <= {$tol} THEN 'paid'"
            . " WHEN {$paid} > {$tol} THEN 'partial'"
            . " ELSE 'unpaid' END)";
    }

    /** Tab => extra list WHERE pieces (no params) and its default order; the badge query uses the same rules. */
    function rh_bk_tab_clauses(string $tab): array
    {
        switch ($tab) {
            case 'today-checkins':
                return ['clauses' => ['b.check_in_date = CURDATE()', "b.status IN ('confirmed','pending')"], 'order' => 'b.check_in_date ASC, b.created_at DESC'];
            case 'today-checkouts':
                return ['clauses' => ['b.check_out_date = CURDATE()', "b.status = 'checked-in'"], 'order' => 'b.check_out_date ASC, b.created_at DESC'];
            case 'checked-in':
                // ?filter=checked_in (In-House shortcut): without this the tab lit up over the whole list.
                return ['clauses' => ["b.status = 'checked-in'"], 'order' => null];
            case 'expiring-soon':
                return ['clauses' => ["b.status = 'tentative'", 'b.tentative_expires_at IS NOT NULL', 'b.tentative_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)'], 'order' => 'b.tentative_expires_at ASC'];
            case 'paid':
                // Step 13: the badge's rule (rh_bk_pay_key_sql), any booking status, as before.
                return ['clauses' => [rh_bk_pay_key_sql('b') . " = 'paid'"], 'order' => null];
            case 'unpaid':
                // Unpaid or part paid by the badge's rule; cancelled / no-show / expired stay out, as before.
                return ['clauses' => [rh_bk_pay_key_sql('b') . " IN ('partial', 'unpaid')", "b.status NOT IN ('cancelled', 'no-show', 'expired')"], 'order' => null];
            case 'today-bookings':
                return ['clauses' => ['DATE(b.created_at) = CURDATE()'], 'order' => null];
            case 'week-bookings':
                return ['clauses' => ['b.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'], 'order' => null];
            case 'month-bookings':
                return ['clauses' => ["DATE_FORMAT(b.created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')"], 'order' => null];
            case 'group-bookings':
                // Group bookings tab (step 8g): every room of a group, the same rule as Booking kind = Group.
                return ['clauses' => [rh_bk_group_sql()], 'order' => null];
        }
        return ['clauses' => [], 'order' => null];
    }

    /**
     * Paid / Unpaid for one row, exactly as the SQL above decides it. $payKey = rh_booking_pay_state($row)['key'] (the
     * badge), $status = the booking status. Feeds the on-screen tab filter.
     */
    function rh_bk_payment_flags(string $payKey, $status): array
    {
        $k = strtolower(trim($payKey));
        $s = strtolower(trim((string)$status));
        return [
            'paid' => $k === 'paid',
            'unpaid' => in_array($k, ['partial', 'unpaid'], true) && !in_array($s, ['cancelled', 'no-show', 'expired'], true),
        ];
    }

    /** A date for the notice: "12 Nov 2026". */
    function rh_bk_day_words(string $d): string
    {
        return (new DateTimeImmutable($d . ' 00:00:00'))->format('j M Y');
    }

    /**
     * Plain words for the notice (mid-sentence, after "Showing N matching room bookings, "), naming the date type:
     * "arriving on 12 Nov 2026" / "arriving 12–14 Nov 2026" / "booked 28 Nov – 2 Dec 2026" /
     * "departing on or after 12 Nov 2026" / "staying on or before 14 Nov 2026" / '' when no dates.
     */
    function rh_bk_date_scope_words(string $from, string $to, string $type): string
    {
        $verb = ['arrival' => 'arriving', 'departure' => 'departing', 'staying' => 'staying', 'booked' => 'booked'][rh_bk_date_type($type)];
        $dash = "\u{2013}";
        if ($from !== '' && $to !== '') {
            if ($from === $to) {
                return $verb . ($verb === 'staying' ? ' the night of ' : ' on ') . rh_bk_day_words($from);
            }
            $f = new DateTimeImmutable($from . ' 00:00:00');
            $t = new DateTimeImmutable($to . ' 00:00:00');
            if ($f->format('Y-m') === $t->format('Y-m')) {
                $span = $f->format('j') . $dash . $t->format('j M Y');
            } elseif ($f->format('Y') === $t->format('Y')) {
                $span = $f->format('j M') . ' ' . $dash . ' ' . $t->format('j M Y');
            } else {
                $span = $f->format('j M Y') . ' ' . $dash . ' ' . $t->format('j M Y');
            }
            return $verb . ' ' . $span;
        }
        if ($from !== '') return $verb . ' on or after ' . rh_bk_day_words($from);
        if ($to !== '') return $verb . ' on or before ' . rh_bk_day_words($to);
        return '';
    }

    /** Most rows the list's "All" choice shows at once; above it "All" falls back to 100 a page. */
    if (!defined('RH_BK_ALL_ROWS_MAX')) {
        define('RH_BK_ALL_ROWS_MAX', 500);
    }

    /** Rows-per-page choices on the Bookings list (?size=), besides "All" (rh_bk_all_size_allowed). */
    function rh_bk_page_sizes(): array
    {
        return ['10', '25', '50', '100'];
    }

    /** ?size= cleaned: '10' | '25' | '50' | '100' | 'all'. Anything else (missing, junk, an array) = '10'. */
    function rh_bk_page_size($raw): string
    {
        if (!is_string($raw) && !is_int($raw)) {
            return '10';
        }
        $s = strtolower(trim((string)$raw));
        return ($s === 'all' || in_array($s, rh_bk_page_sizes(), true)) ? $s : '10';
    }

    /** Whether the picker offers "All" for a list of $count rows. */
    function rh_bk_all_size_allowed(int $count): bool
    {
        return $count <= RH_BK_ALL_ROWS_MAX;
    }

    /**
     * The size in force once the list is counted, and its rows per page: 'all' = every row (at least 1) while the
     * list has at most RH_BK_ALL_ROWS_MAX rows, else '100'. Returns ['size' => string, 'per_page' => int].
     */
    function rh_bk_resolve_page_size($size, int $count): array
    {
        $size = rh_bk_page_size($size);
        if ($size === 'all') {
            if (rh_bk_all_size_allowed($count)) {
                return ['size' => 'all', 'per_page' => max(1, $count)];
            }
            $size = '100';
        }
        return ['size' => $size, 'per_page' => (int)$size];
    }

    /**
     * What a part-paid booking still has to pay, shown under its payment badge; 0.0 = show nothing. Only when
     * amount_due is above the tolerance AND the booking is part-paid (payment status 'partial', or something already
     * paid). Never for cancelled / no-show / expired bookings (the Unpaid tab's rule) or a refunded one.
     */
    function rh_bk_left_to_pay($payment_status, $status, $amount_due, $amount_paid): float
    {
        $tol = rh_fd_balance_tolerance();
        $due = round((float)$amount_due, 2);
        if ($due <= $tol) {
            return 0.0;
        }
        $p = strtolower(trim((string)$payment_status));
        $s = strtolower(trim((string)$status));
        // A no-show keeps billing its stay (owner 2026-11-13: a no-show pays 100%), so what it still owes is shown, as in
        // Still owed. A cancelled booking that still owes (owner 2026-11-14: its kept fee / extras; amount_due is that
        // folio balance) shows it whether or not anything was paid, as in Still owed. Expired stays out here.
        if ($p === 'refunded' || $s === 'expired') {
            return 0.0;
        }
        if ($s === 'cancelled') {
            return $due;
        }
        return ($p === 'partial' || (float)$amount_paid > $tol) ? $due : 0.0;
    }
}

if (!function_exists('rh_bk_kind_filter')) {
    /*
     * "More filters" on the Bookings list (step 8g, owner 2026-11-15): Booking kind (?kind=), Room type (?room_type=) and
     * Payment (?pay=). Each value is whitelisted here; the SQL is fixed text (alias b = bookings only, plus
     * self-contained subqueries with their own aliases) and numbers are bound, so the list, the count, the tab badges,
     * the insight cards and the CSV export all use the same pieces. No database, no settings.
     *   Group       bookings tied together the way create-booking.php / booking.php / mixed-booking.php tie them:
     *               bookings.primary_booking_id (NULL on the lead, the lead's id on every other room; the rule of
     *               rh_booking_group_members). A lead counts while at least one not-deleted room points at it.
     *   Joined room a booking holding a joined pair (room_combination_id), or booked as a joined ("Book As") room type:
     *               a type named by any room_combinations row, active or not (rh_joined_type_without_pairs' rule).
     *   Single      neither of the above. A joined room inside a group is both Group and Joined room.
     */

    /** ?kind= choices => label. */
    function rh_bk_kinds(): array
    {
        return ['single' => 'Single booking', 'group' => 'Group booking', 'joined' => 'Joined room'];
    }

    /** Whitelisted ?kind=; '' when absent or unknown. */
    function rh_bk_kind_filter($kind): string
    {
        $k = is_string($kind) ? strtolower(trim($kind)) : '';
        return isset(rh_bk_kinds()[$k]) ? $k : '';
    }

    /** SQL: the booking is part of a group (fixed text). */
    function rh_bk_group_sql(): string
    {
        return '(COALESCE(b.primary_booking_id, 0) > 0 OR EXISTS (SELECT 1 FROM bookings bkgrp WHERE bkgrp.primary_booking_id = b.id AND bkgrp.deleted_at IS NULL))';
    }

    /** SQL: the booking is a joined room (fixed text). $hasCombos = the room_combinations table exists. */
    function rh_bk_joined_sql(bool $hasCombos = true): string
    {
        return $hasCombos
            ? '(b.room_combination_id IS NOT NULL OR b.room_id IN (SELECT bkrc.combined_room_type_id FROM room_combinations bkrc WHERE bkrc.combined_room_type_id IS NOT NULL))'
            : '(b.room_combination_id IS NOT NULL)';
    }

    /** The WHERE piece for a ?kind= value, or null when none. */
    function rh_bk_kind_clause(string $kind, bool $hasCombos = true): ?string
    {
        switch ($kind) {
            case 'group':
                return rh_bk_group_sql();
            case 'joined':
                return rh_bk_joined_sql($hasCombos);
            case 'single':
                return '(NOT ' . rh_bk_group_sql() . ' AND NOT ' . rh_bk_joined_sql($hasCombos) . ')';
        }
        return null;
    }

    /** Whitelisted ?room_type= (a rooms.id); 0 when absent, not a positive whole number, or not in $knownIds (when given). */
    function rh_bk_room_type_filter($raw, ?array $knownIds = null): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return 0;
        }
        $s = trim((string)$raw);
        if (!preg_match('/^[1-9]\d{0,9}$/', $s)) {
            return 0;
        }
        $id = (int)$s;
        if ($knownIds !== null && !in_array($id, array_map('intval', $knownIds), true)) {
            return 0;
        }
        return $id;
    }

    /** ['clause' => 'b.room_id = ?', 'params' => [id]] or null. The booked room type (bookings.room_id). */
    function rh_bk_room_type_clause(int $roomTypeId): ?array
    {
        return $roomTypeId > 0 ? ['clause' => 'b.room_id = ?', 'params' => [$roomTypeId]] : null;
    }

    /** ?pay= choices => label. */
    function rh_bk_pay_options(): array
    {
        return [
            'paid'     => 'Paid',
            'part'     => 'Part paid',
            'unpaid'   => 'Unpaid',
            'refunded' => 'Refunded',
            'fee_owed' => 'Fee owed after cancel or no-show',
        ];
    }

    /** Whitelisted ?pay=; '' when absent or unknown. */
    function rh_bk_pay_filter($pay): string
    {
        $p = is_string($pay) ? strtolower(trim($pay)) : '';
        return isset(rh_bk_pay_options()[$p]) ? $p : '';
    }

    /**
     * ['clauses' => [...], 'params' => [...]] for a ?pay= value, or null. Only existing rules, never a new money rule:
     *   paid      the Paid tab (the badge reads Paid in full: rh_bk_pay_key_sql 'paid')
     *   unpaid    the Unpaid tab (badge Unpaid or Part paid, not cancelled / no-show / expired)
     *   part      the Unpaid tab's rows whose badge reads Part paid (something paid, something still to pay)
     *   refunded  the badge reads Refunded (a closed booking whose money all went back)
     *   fee_owed  the Still owed (receivables) rule for cancelled and no-show rooms: amount due above BALANCE_TOLERANCE
     *             (bound) and a no-show, or a cancelled room that kept a fee or still has folio extras posted
     *             (rh_receivable_status_sql). Expired holds carrying a group no-show charge are not included here.
     */
    function rh_bk_pay_clause(string $pay): ?array
    {
        switch ($pay) {
            case 'paid':
                return ['clauses' => rh_bk_tab_clauses('paid')['clauses'], 'params' => []];
            case 'unpaid':
                return ['clauses' => rh_bk_tab_clauses('unpaid')['clauses'], 'params' => []];
            case 'part':
                return ['clauses' => array_merge(rh_bk_tab_clauses('unpaid')['clauses'], [rh_bk_pay_key_sql('b') . " = 'partial'"]), 'params' => []];
            case 'refunded':
                return ['clauses' => [rh_bk_pay_key_sql('b') . " = 'refunded'"], 'params' => []];
            case 'fee_owed':
                return ['clauses' => [
                    'b.amount_due > ?',
                    "(b.status = 'no-show' OR (b.status = 'cancelled' AND (COALESCE(b.cancellation_retained_amount, 0) > 0 OR COALESCE(b.folio_charges_total, 0) > 0)))",
                ], 'params' => [rh_fd_balance_tolerance()]];
        }
        return null;
    }

    /**
     * Every "More filters" piece at once, in a fixed order (kind, room type, payment), for the list and the export.
     * @return array{clauses: string[], params: array}
     */
    function rh_bk_more_scope(string $kind, int $roomTypeId, string $pay, bool $hasCombos = true): array
    {
        $clauses = [];
        $params = [];
        if (($k = rh_bk_kind_clause($kind, $hasCombos)) !== null) {
            $clauses[] = $k;
        }
        if (($rt = rh_bk_room_type_clause($roomTypeId)) !== null) {
            $clauses[] = $rt['clause'];
            $params = array_merge($params, $rt['params']);
        }
        if (($p = rh_bk_pay_clause($pay)) !== null) {
            $clauses = array_merge($clauses, $p['clauses']);
            $params = array_merge($params, $p['params']);
        }
        return ['clauses' => $clauses, 'params' => $params];
    }

    /**
     * Plain words for the notice (each part starts with ", "), e.g. ", group bookings only, room type Deluxe, part paid".
     * $roomTypeName is plain text: the caller escapes the whole sentence.
     */
    function rh_bk_more_scope_words(string $kind, string $roomTypeName, string $pay): string
    {
        $w = '';
        $kw = ['single' => 'single bookings only (not in a group, not a joined room)', 'group' => 'group bookings only', 'joined' => 'joined-room bookings only'];
        if (isset($kw[$kind])) {
            $w .= ', ' . $kw[$kind];
        }
        if ($roomTypeName !== '') {
            $w .= ', room type ' . $roomTypeName;
        }
        $pw = [
            'paid' => 'paid in full', 'part' => 'part paid', 'unpaid' => 'not fully paid',
            'refunded' => 'refunded', 'fee_owed' => 'with a fee still owed after a cancellation or no-show',
        ];
        if (isset($pw[$pay])) {
            $w .= ', ' . $pw[$pay];
        }
        return $w;
    }
}

if (!function_exists('rh_bk_balance_due')) {
    /*
     * Balance due (plan BOOKINGS_LIST_PLAN Decision 9 + spec-check S1 item 5): ONE rule, the PHP cell and its SQL twin
     * (sort key 'balance', CSV column). Never a new money rule: it is rh_booking_pay_state()['left'] with two list rules
     * on top, in this order:
     *   deleted row                                   -> null (blank)
     *   ROUND(GREATEST(0, amount_due), 2) <= tol      -> 0 (as rh_bk_left_to_pay; covers paid and refunded)
     *   expired                                       -> the rounded due only when the row carries the group no-show
     *                                                    charge tag (a booking_charges description ending
     *                                                    "[noshow-fee:<booking id>]", bookings.php's tagged-expired test), else 0
     *   otherwise                                     -> rh_booking_pay_state()['left'] (0 for paid / refunded, else the
     *                                                    rounded due: cancelled and no-show rows show what they still owe)
     * tol = BALANCE_TOLERANCE (rh_pay_state_tol).
     */

    /** PHP rule. $row: status, payment_status, amount_paid, amount_due, credit_balance, deleted_at. */
    function rh_bk_balance_due(array $row, bool $taggedExpired): ?float
    {
        if (!empty($row['deleted_at'])) {
            return null;
        }
        $due = round(max(0.0, (float)($row['amount_due'] ?? 0)), 2);
        if ($due <= rh_pay_state_tol()) {
            return 0.0;
        }
        if (strtolower(trim((string)($row['status'] ?? ''))) === 'expired') {
            return $taggedExpired ? $due : 0.0;
        }
        return (float)rh_booking_pay_state($row)['left'];
    }

    /**
     * SQL twin (alias $b = bookings; needs $b.id, so a derived table works too). The due expression is rh_bk_pay_key_sql's
     * own; the tag test is bookings.php's LIKE. Constant text (the tolerance is a number), never user input.
     */
    function rh_bk_balance_due_sql(string $b = 'b'): string
    {
        $tol = sprintf('%.4F', rh_pay_state_tol());
        $due = "ROUND(GREATEST(0, COALESCE({$b}.amount_due, 0)), 2)";
        $tag = "EXISTS (SELECT 1 FROM booking_charges bkbdc WHERE bkbdc.booking_id = {$b}.id"
            . " AND bkbdc.description LIKE CONCAT('%[noshow-fee:', {$b}.id, ']'))";
        return "(CASE"
            . " WHEN {$b}.deleted_at IS NOT NULL THEN NULL"
            . " WHEN {$due} <= {$tol} THEN 0"
            . " WHEN LOWER(TRIM(COALESCE({$b}.status, ''))) = 'expired' THEN (CASE WHEN {$tag} THEN {$due} ELSE 0 END)"
            . " WHEN " . rh_bk_pay_key_sql($b) . " IN ('paid', 'refunded') THEN 0"
            . " ELSE {$due} END)";
    }
}

if (!function_exists('rh_bk_source_sql')) {
    /*
     * Source / made by (plan Decision 1, T1b): there is no stored channel, so both are DERIVED with the exact order of
     * rh_created_by_pick (includes/booking-created-by.php) from the FIRST booking_audit_log 'created' row and the FIRST
     * booking_timeline_logs 'create' row (lowest id, as rh_created_by_map):
     *   1. audit row with performed_by > 0                          -> staff (that admin id)
     *   2. else a timeline row: performed_by_type guest / api       -> online
     *                           admin / staff with an id or a name  -> staff (its performed_by_id; a name only = no id)
     *                           anything else                       -> system
     *   3. else an audit row whose note starts "Booking made online" -> online
     *   4. else an audit row with a stored name only               -> staff (no id)
     *   5. nothing recorded                                         -> none
     * Text is trimmed of spaces (SQL TRIM); PHP's trim also strips tabs / new lines, which the log writers never put there
     * (scripts/tests/booking_created_by.php proves SQL = pick for every Liwonde booking). Alias b = bookings.
     */

    /** ?source= choices => label. */
    function rh_bk_source_options(): array
    {
        return [
            'staff'  => 'Staff (front desk)',
            'online' => 'Online (website or booking link)',
            'system' => 'System',
            'none'   => 'Not recorded',
        ];
    }

    /** Whitelisted ?source=; '' when absent or unknown. */
    function rh_bk_source_filter($raw): string
    {
        $s = is_string($raw) ? strtolower(trim($raw)) : '';
        return isset(rh_bk_source_options()[$s]) ? $s : '';
    }

    /** A positive whole-number id (rooms, groups, staff); 0 when absent, junk, or not in $knownIds (when given). */
    function rh_bk_id_filter($raw, ?array $knownIds = null): int
    {
        return rh_bk_room_type_filter($raw, $knownIds);
    }

    /** Whitelisted ?created_by= (an admin_users.id); 0 when none. */
    function rh_bk_creator_filter($raw, ?array $knownIds = null): int
    {
        return rh_bk_id_filter($raw, $knownIds);
    }

    /** One column of the first audit 'created' row (NULL when there is none). */
    function rh_bk_first_audit_sql(string $expr): string
    {
        return "(SELECT {$expr} FROM booking_audit_log bkau WHERE bkau.booking_id = b.id AND bkau.action = 'created' ORDER BY bkau.id ASC LIMIT 1)";
    }

    /** One column of the first timeline 'create' row (NULL when there is none). */
    function rh_bk_first_timeline_sql(string $expr): string
    {
        return "(SELECT {$expr} FROM booking_timeline_logs bktl WHERE bktl.booking_id = b.id AND bktl.action_type = 'create' ORDER BY bktl.id ASC LIMIT 1)";
    }

    /** SQL: 'staff' | 'online' | 'system' | 'none' (rh_created_by_pick's kind). */
    function rh_bk_source_sql(): string
    {
        $tlType = "LOWER(TRIM(COALESCE(bktl.performed_by_type, '')))";
        return "(CASE"
            . " WHEN COALESCE(" . rh_bk_first_audit_sql('bkau.performed_by') . ", 0) > 0 THEN 'staff'"
            . " WHEN " . rh_bk_first_timeline_sql('bktl.id') . " IS NOT NULL THEN (CASE"
            . " WHEN " . rh_bk_first_timeline_sql($tlType) . " IN ('guest', 'api') THEN 'online'"
            . " WHEN " . rh_bk_first_timeline_sql("({$tlType} IN ('admin', 'staff') AND (COALESCE(bktl.performed_by_id, 0) > 0 OR TRIM(COALESCE(bktl.performed_by_name, '')) <> ''))") . " = 1 THEN 'staff'"
            . " ELSE 'system' END)"
            . " WHEN " . rh_bk_first_audit_sql("(LOWER(TRIM(COALESCE(bkau.note, ''))) LIKE 'booking made online%')") . " = 1 THEN 'online'"
            . " WHEN " . rh_bk_first_audit_sql("(TRIM(COALESCE(bkau.performed_by_name, '')) <> '')") . " = 1 THEN 'staff'"
            . " ELSE 'none' END)";
    }

    /** SQL: the admin id that made the booking (rh_created_by_pick's staff id), 0 when none / a name only. */
    function rh_bk_creator_sql(): string
    {
        $tlType = "LOWER(TRIM(COALESCE(bktl.performed_by_type, '')))";
        return "(CASE"
            . " WHEN COALESCE(" . rh_bk_first_audit_sql('bkau.performed_by') . ", 0) > 0 THEN " . rh_bk_first_audit_sql('bkau.performed_by')
            . " WHEN " . rh_bk_first_timeline_sql('bktl.id') . " IS NOT NULL THEN COALESCE("
            . rh_bk_first_timeline_sql("(CASE WHEN {$tlType} IN ('admin', 'staff') THEN COALESCE(bktl.performed_by_id, 0) ELSE 0 END)") . ", 0)"
            . " ELSE 0 END)";
    }
}

if (!function_exists('rh_bk_filters_from_get')) {
    /*
     * Shared scope + URL helpers (plan T1): ONE place cleans every list param and builds every shared WHERE piece, so the
     * list, its count, the tab badges, the insight cards and the CSV export cannot disagree. Pure (no database).
     */

    /**
     * Every list param cleaned. $known: whitelists of ids ('room_types', 'rooms', 'groups', 'staff'); a missing list
     * accepts any positive id. ?status= is the alias of ?filter_status= and stays the raw text (an unknown status
     * matches no booking, as before).
     */
    function rh_bk_filters_from_get(array $get, array $known = []): array
    {
        $str = static function ($v): string {
            return is_string($v) ? $v : '';
        };
        [$from, $to] = rh_bk_date_range($str($get['date_from'] ?? ''), $str($get['date_to'] ?? ''));
        $sinceKind = rh_bk_since_kind($get['since_kind'] ?? '');
        $since = $sinceKind !== '' ? rh_bk_since_filter($get['since'] ?? '') : '';
        if ($since === '') {
            $sinceKind = '';
        }
        $status = $str(isset($get['filter_status']) ? $get['filter_status'] : ($get['status'] ?? ''));
        $filter = rh_bk_filter_key($str($get['filter'] ?? ''), $str($get['payment_status'] ?? ''));
        $page = $get['page'] ?? 1;
        return [
            'search'     => trim($str($get['search'] ?? '')),
            'status'     => $status,
            'date_from'  => $from,
            'date_to'    => $to,
            'date_type'  => rh_bk_date_type($str($get['date_type'] ?? '')),
            'arrival'    => rh_bk_arrival_filter($str($get['arrival'] ?? '')),
            'departure'  => rh_bk_departure_filter($str($get['departure'] ?? '')),
            'balance'    => rh_bk_balance_filter($str($get['balance'] ?? '')),
            'since'      => $since,
            'since_kind' => $sinceKind,
            'kind'       => rh_bk_kind_filter($get['kind'] ?? ''),
            'room_type'  => rh_bk_room_type_filter($get['room_type'] ?? '', $known['room_types'] ?? null),
            'pay'        => rh_bk_pay_filter($get['pay'] ?? ''),
            'room'       => rh_bk_id_filter($get['room'] ?? '', $known['rooms'] ?? null),
            'group'      => rh_bk_id_filter($get['group'] ?? '', $known['groups'] ?? null),
            'source'     => rh_bk_source_filter($get['source'] ?? ''),
            'created_by' => rh_bk_creator_filter($get['created_by'] ?? '', $known['staff'] ?? null),
            'filter'     => $filter,
            // The open tab: the ?filter= shortcut, else the status (status tabs are named like the status values).
            'tab'        => $filter !== '' ? rh_bk_tab_map()[$filter] : $status,
            'page'       => max(1, is_scalar($page) ? (int)$page : 1),
            'size'       => rh_bk_page_size($get['size'] ?? '10'),
        ];
    }

    /** Any filter set (the tab and page size are not filters). */
    function rh_bk_filters_active(array $f): bool
    {
        foreach (['search', 'status', 'date_from', 'date_to', 'arrival', 'departure', 'balance', 'since', 'kind', 'pay', 'source'] as $k) {
            if (($f[$k] ?? '') !== '') {
                return true;
            }
        }
        foreach (['room_type', 'room', 'group', 'created_by'] as $k) {
            if ((int)($f[$k] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The shared WHERE pieces (no status, deleted or tab piece: the badges count every tab inside this scope), in a fixed
     * order: search, dates, overdue arrivals, overdue departures, balance, since, More filters (kind, room type, payment),
     * room, group, source, made by. Params in clause order.
     * @return array{clauses: string[], params: array}
     */
    function rh_bk_scope(array $f, bool $hasCombos = true): array
    {
        $clauses = [];
        $params = [];
        $s = rh_bk_search_where((string)($f['search'] ?? ''));
        if ($s['sql'] !== '') {
            $clauses[] = $s['sql'];
            $params = array_merge($params, $s['params']);
        }
        $d = rh_bk_date_scope((string)($f['date_from'] ?? ''), (string)($f['date_to'] ?? ''), (string)($f['date_type'] ?? ''));
        $clauses = array_merge($clauses, $d['clauses']);
        $params = array_merge($params, $d['params']);
        if (($a = rh_bk_arrival_clause((string)($f['arrival'] ?? ''))) !== null) {
            $clauses[] = $a;
        }
        if (($dp = rh_bk_departure_clause((string)($f['departure'] ?? ''))) !== null) {
            $clauses[] = $dp;
        }
        if (($bl = rh_bk_balance_clause((string)($f['balance'] ?? ''))) !== null) {
            $clauses[] = $bl['clause'];
            $params = array_merge($params, $bl['params']);
        }
        if (($sn = rh_bk_since_clause((string)($f['since'] ?? ''), (string)($f['since_kind'] ?? ''))) !== null) {
            $clauses[] = $sn['clause'];
            $params = array_merge($params, $sn['params']);
        }
        $m = rh_bk_more_scope((string)($f['kind'] ?? ''), (int)($f['room_type'] ?? 0), (string)($f['pay'] ?? ''), $hasCombos);
        $clauses = array_merge($clauses, $m['clauses']);
        $params = array_merge($params, $m['params']);
        if (($room = (int)($f['room'] ?? 0)) > 0) {
            $clauses[] = 'b.individual_room_id = ?';
            $params[] = $room;
        }
        if (($group = (int)($f['group'] ?? 0)) > 0) {
            // A group = its lead booking + every room pointing at it (rh_bk_group_sql's link).
            $clauses[] = '(b.id = ? OR b.primary_booking_id = ?)';
            array_push($params, $group, $group);
        }
        if (($src = (string)($f['source'] ?? '')) !== '') {
            $clauses[] = rh_bk_source_sql() . ' = ?';
            $params[] = $src;
        }
        if (($by = (int)($f['created_by'] ?? 0)) > 0) {
            $clauses[] = rh_bk_creator_sql() . ' = ?';
            $params[] = $by;
        }
        return ['clauses' => $clauses, 'params' => $params];
    }

    /**
     * The list's full WHERE (and the CSV export's, the same call): the shared scope, deleted rows only under the Deleted
     * status, the status, then the open tab's pieces.
     * @return array{clauses: string[], params: array}
     */
    function rh_bk_list_scope(array $f, bool $hasCombos = true): array
    {
        $s = rh_bk_scope($f, $hasCombos);
        $status = (string)($f['status'] ?? '');
        $s['clauses'][] = $status === 'deleted' ? 'b.deleted_at IS NOT NULL' : 'b.deleted_at IS NULL';
        if (!empty($status) && $status !== 'deleted') { // empty(): '0' was never a status piece
            $s['clauses'][] = 'b.status = ?';
            $s['params'][] = $status;
        }
        $s['clauses'] = array_merge($s['clauses'], rh_bk_tab_clauses((string)($f['tab'] ?? ''))['clauses']);
        return $s;
    }

    /**
     * Query params that reproduce the list (pager links, the search form's kept inputs, insight-card links, chip remove
     * links), in a fixed order, empty values left out; $drop = keys to leave out. Sort params are added by the caller
     * (rh_server_sort_params). ?status= is written as filter_status.
     */
    function rh_bk_keep_params(array $f, array $drop = []): array
    {
        $hasDates = ($f['date_from'] ?? '') !== '' || ($f['date_to'] ?? '') !== '';
        $id = static function ($v): string {
            return (int)$v > 0 ? (string)(int)$v : '';
        };
        $all = [
            'search'        => (string)($f['search'] ?? ''),
            'filter_status' => (string)($f['status'] ?? ''),
            'date_from'     => (string)($f['date_from'] ?? ''),
            'date_to'       => (string)($f['date_to'] ?? ''),
            'date_type'     => $hasDates ? (string)($f['date_type'] ?? '') : '',
            'filter'        => (string)($f['filter'] ?? ''),
            'arrival'       => (string)($f['arrival'] ?? ''),
            'departure'     => (string)($f['departure'] ?? ''),
            'balance'       => (string)($f['balance'] ?? ''),
            'since'         => (string)($f['since'] ?? ''),
            'since_kind'    => (string)($f['since_kind'] ?? ''),
            'kind'          => (string)($f['kind'] ?? ''),
            'room_type'     => $id($f['room_type'] ?? 0),
            'pay'           => (string)($f['pay'] ?? ''),
            'room'          => $id($f['room'] ?? 0),
            'group'         => $id($f['group'] ?? 0),
            'source'        => (string)($f['source'] ?? ''),
            'created_by'    => $id($f['created_by'] ?? 0),
            'size'          => ($f['size'] ?? '10') !== '10' ? (string)$f['size'] : '',
            'page'          => (int)($f['page'] ?? 1) > 1 ? (string)(int)$f['page'] : '',
        ];
        $out = [];
        foreach ($all as $k => $v) {
            if ($v !== '' && !in_array($k, $drop, true)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * One chip per active filter: ['key', 'label' (plain text, the caller escapes), 'params' (the list without that filter,
     * page 1)]. $names: id => label lists 'room_types', 'rooms', 'groups', 'staff'; an id missing from them shows as "#id".
     */
    function rh_bk_chips(array $f, array $names = []): array
    {
        $name = static function (string $list, int $id) use ($names): string {
            $n = trim((string)($names[$list][$id] ?? ''));
            return $n !== '' ? $n : '#' . $id;
        };
        $defs = [];
        if (($f['search'] ?? '') !== '') {
            $defs[] = ['search', "Search: \u{201C}" . $f['search'] . "\u{201D}", ['search']];
        }
        if (($f['status'] ?? '') !== '') {
            $defs[] = ['status', 'Status: ' . ucfirst(str_replace('-', ' ', (string)$f['status'])), ['filter_status']];
        }
        if (($f['date_from'] ?? '') !== '' || ($f['date_to'] ?? '') !== '') {
            $defs[] = ['dates', ucfirst(rh_bk_date_scope_words((string)$f['date_from'], (string)$f['date_to'], (string)($f['date_type'] ?? ''))), ['date_from', 'date_to', 'date_type']];
        }
        if (($f['arrival'] ?? '') !== '') {
            $defs[] = ['arrival', 'Overdue arrivals', ['arrival']];
        }
        if (($f['departure'] ?? '') !== '') {
            $defs[] = ['departure', 'Overdue departures', ['departure']];
        }
        if (($f['balance'] ?? '') !== '') {
            $defs[] = ['balance', 'Balance still to pay', ['balance']];
        }
        if (($f['since'] ?? '') !== '') {
            $defs[] = ['since', rh_bk_since_words((string)$f['since'], (string)$f['since_kind']), ['since', 'since_kind']];
        }
        if (($f['kind'] ?? '') !== '') {
            $defs[] = ['kind', rh_bk_kinds()[$f['kind']] ?? (string)$f['kind'], ['kind']];
        }
        if ((int)($f['room_type'] ?? 0) > 0) {
            $defs[] = ['room_type', 'Room type: ' . $name('room_types', (int)$f['room_type']), ['room_type']];
        }
        if (($f['pay'] ?? '') !== '') {
            $defs[] = ['pay', 'Payment: ' . (rh_bk_pay_options()[$f['pay']] ?? (string)$f['pay']), ['pay']];
        }
        if ((int)($f['room'] ?? 0) > 0) {
            $defs[] = ['room', 'Room: ' . $name('rooms', (int)$f['room']), ['room']];
        }
        if ((int)($f['group'] ?? 0) > 0) {
            $defs[] = ['group', 'Group: ' . $name('groups', (int)$f['group']), ['group']];
        }
        if (($f['source'] ?? '') !== '') {
            $defs[] = ['source', 'Source: ' . (rh_bk_source_options()[$f['source']] ?? (string)$f['source']), ['source']];
        }
        if ((int)($f['created_by'] ?? 0) > 0) {
            $defs[] = ['created_by', 'Made by: ' . $name('staff', (int)$f['created_by']), ['created_by']];
        }
        $out = [];
        foreach ($defs as [$key, $label, $drop]) {
            $out[] = ['key' => $key, 'label' => $label, 'params' => rh_bk_keep_params($f, array_merge($drop, ['page']))];
        }
        return $out;
    }

    /**
     * Date presets on the hotel date $today ('Y-m-d', the server's date, never the device clock; Decision 8): Today,
     * Tomorrow, This week (Monday to Sunday), Next 7 days (today to today + 6), This month (1st to last day).
     * [] when $today is not a real date.
     */
    function rh_bk_date_presets(string $today): array
    {
        if (rh_bk_valid_date($today) === '') {
            return [];
        }
        $t = new DateTimeImmutable($today . ' 00:00:00');
        $mon = $t->modify('-' . ((int)$t->format('N') - 1) . ' days');
        $day = static function (DateTimeImmutable $d): string {
            return $d->format('Y-m-d');
        };
        return [
            ['key' => 'today', 'label' => 'Today', 'from' => $today, 'to' => $today],
            ['key' => 'tomorrow', 'label' => 'Tomorrow', 'from' => $day($t->modify('+1 day')), 'to' => $day($t->modify('+1 day'))],
            ['key' => 'this_week', 'label' => 'This week', 'from' => $day($mon), 'to' => $day($mon->modify('+6 days'))],
            ['key' => 'next_7', 'label' => 'Next 7 days', 'from' => $today, 'to' => $day($t->modify('+6 days'))],
            ['key' => 'this_month', 'label' => 'This month', 'from' => $t->format('Y-m-01'), 'to' => $t->format('Y-m-t')],
        ];
    }

    /** 'history' for Checked out, Cancelled, No-show and Deleted (as tab or status), else 'upcoming' (Decision 5). */
    function rh_bk_view_kind(string $tab, string $status): string
    {
        $history = ['checked-out', 'cancelled', 'no-show', 'deleted'];
        return in_array($tab, $history, true) || in_array($status, $history, true) ? 'history' : 'upcoming';
    }

    /**
     * The list's default ORDER BY when no header sort is chosen (Decision 5; the caller adds the tiebreak b.id DESC):
     *   tabs with their own order (today's check-ins / check-outs, expiring)  -> that order
     *   history views                                                         -> newest first (created_at DESC)
     *   upcoming views (All, In house, Pending, Unpaid, Tentative, Groups)    -> arrivals from today on, soonest
     *                                                                            first, then past arrivals, latest first
     *   any other view (Paid, booked today / week / month, other statuses)    -> newest first, as before
     */
    function rh_bk_default_order(string $tab, string $status): string
    {
        $own = rh_bk_tab_clauses($tab)['order'];
        if ($own !== null) {
            return $own;
        }
        if (rh_bk_view_kind($tab, $status) === 'history') {
            return 'b.created_at DESC';
        }
        if (in_array($tab, ['', 'checked-in', 'pending', 'unpaid', 'tentative', 'group-bookings'], true)) {
            return '(b.check_in_date < CURDATE()) ASC, (CASE WHEN b.check_in_date >= CURDATE() THEN b.check_in_date END) ASC, b.check_in_date DESC';
        }
        return 'b.created_at DESC';
    }
}
