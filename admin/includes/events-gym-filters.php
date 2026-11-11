<?php
/**
 * List filter rules for the conference and gym screens (pure: no database, no settings).
 * One source for each badge/count and the list it opens, so they can never disagree.
 * Tested by scripts/tests/events_gym_filters.php.
 */

if (!function_exists('rh_conf_list_statuses')) {
    /** Every status a conference enquiry can hold (value => label used in the filter notice). */
    function rh_conf_list_statuses(): array
    {
        return ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    }
}

if (!function_exists('rh_conf_list_valid_date')) {
    /** A real Y-m-d calendar date, or '' (impossible dates and junk are dropped). */
    function rh_conf_list_valid_date($v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return '';
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : '';
    }
}

if (!function_exists('rh_conf_list_filter')) {
    /**
     * Conference enquiry list filter from the query string (?status=, ?event_date=).
     * status: whitelisted key or ''. event_date: enquiries running on that day; a multi-day
     * booking (end_date) matches every day of its range, the same rule as the dashboard.
     *
     * event_date with no status: pending + confirmed only (open_only = true).
     *
     * @return array{status:string,event_date:string,open_only:bool,clauses:array<int,string>,params:array<int,string>}
     */
    function rh_conf_list_filter(array $get, bool $hasEndDate): array
    {
        $status = is_string($get['status'] ?? null) ? strtolower(trim($get['status'])) : '';
        if (!array_key_exists($status, rh_conf_list_statuses())) {
            $status = '';
        }
        $date = rh_conf_list_valid_date($get['event_date'] ?? '');
        $clauses = [];
        $params = [];
        if ($status !== '') {
            $clauses[] = 'ci.status = ?';
            $params[] = $status;
        }
        $openOnly = false;
        if ($date !== '' && $status === '') {
            // A day view without a status shows what is booked for that day (pending + confirmed),
            // the same rule as the dashboard's Today's conferences count.
            $openOnly = true;
            $clauses[] = 'ci.status IN (?,?)';
            $params[] = 'pending';
            $params[] = 'confirmed';
        }
        if ($date !== '') {
            if ($hasEndDate) {
                $clauses[] = '(ci.event_date <= ? AND COALESCE(ci.end_date, ci.event_date) >= ?)';
                $params[] = $date;
                $params[] = $date;
            } else {
                $clauses[] = 'ci.event_date = ?';
                $params[] = $date;
            }
        }
        return ['status' => $status, 'event_date' => $date, 'open_only' => $openOnly, 'clauses' => $clauses, 'params' => $params];
    }
}

if (!function_exists('rh_gym_member_filter_where')) {
    /**
     * Gym Members tabs: key => WHERE clause. The tab badges and the list both use this map.
     * A lapsed member (end date passed) is Expired, never also Active.
     */
    function rh_gym_member_filter_where(): array
    {
        return [
            'all'      => '1=1',
            'active'   => "status = 'active' AND (expiry_date IS NULL OR expiry_date >= CURDATE())",
            'expiring' => "status = 'active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
            'expired'  => "(status = 'expired' OR (expiry_date IS NOT NULL AND expiry_date < CURDATE()))",
        ];
    }
}

if (!function_exists('rh_gym_in_now_sql')) {
    /**
     * "In the gym now": open visits started today (hotel date). An open visit from an earlier
     * day is a forgotten scan-out (the end-of-day auto check-out closes it), not someone training.
     * $alias: table alias prefix ('' or 'ga.').
     */
    function rh_gym_in_now_sql(string $alias = ''): string
    {
        $a = preg_match('/^[a-z_]+\.$/', $alias) ? $alias : '';
        return "{$a}checked_out_at IS NULL AND {$a}checked_in_at >= CURDATE()";
    }
}

if (!function_exists('rh_conf_focus_id')) {
    /**
     * ?id= on the conference screen (the link in the new-enquiry email): a positive whole number,
     * or 0 when missing or junk ('5abc', '0', '-1', arrays, '5 OR 1=1'). Always bound, never interpolated.
     */
    function rh_conf_focus_id(array $get): int
    {
        $v = $get['id'] ?? null;
        if (is_int($v)) {
            return $v > 0 ? $v : 0;
        }
        if (!is_string($v) || !preg_match('/^\d{1,10}$/', $v)) {
            return 0;
        }
        $n = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $n === false ? 0 : (int)$n;
    }
}

if (!function_exists('rh_gym_member_list_cap')) {
    /** Most members the Gym Members list prints at once (the LIMIT and the "Showing X of N" note share it). */
    function rh_gym_member_list_cap(): int
    {
        return 500;
    }
}

if (!function_exists('rh_gym_checkin_list_cap')) {
    /** Most rows each Gym Check-In list ("in the gym now", "today's activity") prints; its note and LIMIT share it. */
    function rh_gym_checkin_list_cap(): int
    {
        return 100;
    }
}

if (!function_exists('rh_gym_member_filter_key')) {
    /** The Gym Members tab actually shown: a known key, else 'all' (the list falls back to every member). */
    function rh_gym_member_filter_key($raw): string
    {
        $k = is_string($raw) ? $raw : '';
        return array_key_exists($k, rh_gym_member_filter_where()) ? $k : 'all';
    }
}

if (!function_exists('rh_list_cap_note')) {
    /**
     * "Showing 500 of 1,234 members." when a capped list holds fewer rows than its count, else ''.
     * $total must come from a COUNT with the same WHERE as the list.
     */
    function rh_list_cap_note(int $shown, int $total, int $cap, string $nounPlural): string
    {
        if ($cap < 1 || $shown < $cap || $total <= $shown) {
            return '';
        }
        return 'Showing ' . number_format($shown) . ' of ' . number_format($total) . ' ' . $nounPlural . '.';
    }
}
