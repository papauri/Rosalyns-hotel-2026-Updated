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
 */

require_once __DIR__ . '/front-desk-counts.php'; // rh_fd_balance_tolerance: the one "owing" rule
require_once __DIR__ . '/../../includes/smart-search.php'; // the shared word-by-word / phone-number search matcher

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
            'payment'   => 'actual_payment_status',
            'created'   => 'b.created_at',
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
                return ['clauses' => ["b.payment_status IN ('paid', 'completed')"], 'order' => null];
            case 'unpaid':
                return ['clauses' => ["b.payment_status NOT IN ('paid', 'completed', 'refunded')", "b.status NOT IN ('cancelled', 'no-show', 'expired')"], 'order' => null];
            case 'today-bookings':
                return ['clauses' => ['DATE(b.created_at) = CURDATE()'], 'order' => null];
            case 'week-bookings':
                return ['clauses' => ['b.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'], 'order' => null];
            case 'month-bookings':
                return ['clauses' => ["DATE_FORMAT(b.created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')"], 'order' => null];
        }
        return ['clauses' => [], 'order' => null];
    }

    /**
     * Paid / Unpaid for one row, exactly as the SQL above decides it (the booking's own payment_status; a NULL
     * payment status is neither, like SQL's NULL IN / NOT IN). Feeds the on-screen tab filter.
     */
    function rh_bk_payment_flags($payment_status, $status): array
    {
        if ($payment_status === null) {
            return ['paid' => false, 'unpaid' => false];
        }
        $p = strtolower(trim((string)$payment_status));
        $s = strtolower(trim((string)$status));
        return [
            'paid' => in_array($p, ['paid', 'completed'], true),
            'unpaid' => !in_array($p, ['paid', 'completed', 'refunded'], true) && !in_array($s, ['cancelled', 'no-show', 'expired'], true),
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
        // Still owed. Cancelled / expired stay out here.
        if ($p === 'refunded' || in_array($s, ['cancelled', 'expired'], true)) {
            return 0.0;
        }
        return ($p === 'partial' || (float)$amount_paid > $tol) ? $due : 0.0;
    }
}
