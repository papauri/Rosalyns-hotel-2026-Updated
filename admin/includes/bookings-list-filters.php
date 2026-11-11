<?php
/**
 * Bookings list filters (admin/bookings.php): the pure rules shared by the tab badges, the list, the page count,
 * the insight cards and the CSV export, so they can never disagree. No database, no settings.
 * Unit tested by scripts/tests/bookings_filters.php.
 *
 * Dates (owner 2026-11-11, ASSUMPTION logged in BUILD_PLAN): From / To mean "stays that overlap the range":
 * check_in_date <= To AND check_out_date >= From, so From = To = a day lists everyone staying that day
 * (arrivals, in-house and departures on that day).
 */

require_once __DIR__ . '/front-desk-counts.php'; // rh_fd_balance_tolerance: the one "owing" rule

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

    /** WHERE pieces for the date range (stays overlapping it). */
    function rh_bk_date_scope(string $from, string $to): array
    {
        $clauses = [];
        $params = [];
        if ($to !== '') {
            $clauses[] = 'b.check_in_date <= ?';
            $params[] = $to;
        }
        if ($from !== '') {
            $clauses[] = 'b.check_out_date >= ?';
            $params[] = $from;
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

    /** Plain words for the notice: "staying 12 Nov 2026 to 14 Nov 2026" / "staying on 12 Nov 2026" / ''. */
    function rh_bk_date_scope_words(string $from, string $to): string
    {
        $f = $from !== '' ? date('j M Y', strtotime($from)) : '';
        $t = $to !== '' ? date('j M Y', strtotime($to)) : '';
        if ($f !== '' && $f === $t) return 'staying on ' . $f;
        if ($f !== '' && $t !== '') return 'staying between ' . $f . ' and ' . $t;
        if ($f !== '') return 'staying on or after ' . $f;
        if ($t !== '') return 'staying on or before ' . $t;
        return '';
    }
}
