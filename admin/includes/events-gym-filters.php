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
