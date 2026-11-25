<?php
/**
 * Automated Emails > Recent activity: the filters (owner 2026-11-25 "add filters then").
 *
 * GET only, every name starts with ae_ (ae_job, ae_status, ae_from, ae_to, ae_q; paging ae_page / ae_size through
 * admin/includes/log-pager.php), so a filtered view can be linked and the page's POST settings form is untouched.
 * Pure: loading this file connects to nothing. Tested by scripts/tests/auto_email_log_filter_test.php.
 *
 * The list reads automated_email_log l LEFT JOIN bookings b (account_type 'room' only, for the booking reference).
 * Every value staff type reaches the query only as a bound parameter; column names are written here in code.
 */

require_once __DIR__ . '/../../includes/smart-search.php'; // word-by-word search, % and _ matched literally

if (!function_exists('rh_ael_parse')) {

    if (!defined('RH_AEL_ALL_MAX')) {
        define('RH_AEL_ALL_MAX', 500); // "All" shows at most this many rows on one page (the log only grows)
    }
    if (!defined('RH_AEL_Q_MAX')) {
        define('RH_AEL_Q_MAX', 100); // longest search text kept
    }

    /** Every result the scheduler writes to automated_email_log.status, with the word staff see. */
    function rh_ael_statuses(): array
    {
        return [
            'sent' => 'Sent',
            'failed' => 'Failed',
            'skipped' => 'Skipped',
            'sending' => 'Sending (in progress)',
        ];
    }

    /** A query-string value as trimmed text; arrays and anything else = ''. */
    function rh_ael_str(array $get, string $key): string
    {
        $v = $get[$key] ?? null;
        return (is_string($v) || is_int($v)) ? trim((string)$v) : '';
    }

    /** 'Y-m-d' when it is a real calendar date, else ''. */
    function rh_ael_date(string $d): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return '';
        }
        $dt = DateTime::createFromFormat('!Y-m-d', $d);
        return ($dt !== false && $dt->format('Y-m-d') === $d) ? $d : '';
    }

    /**
     * The filters from the query string. $jobKeys: the job codes the log holds (anything else is dropped).
     * Unknown result, bad dates and arrays are dropped; From after To are swapped; the search is cut to RH_AEL_Q_MAX.
     * Returns [job, status, from, to, q, active].
     */
    function rh_ael_parse(array $get, array $jobKeys): array
    {
        $job = rh_ael_str($get, 'ae_job');
        if ($job === '' || !in_array($job, array_map('strval', $jobKeys), true)) {
            $job = '';
        }
        $status = rh_ael_str($get, 'ae_status');
        if (!isset(rh_ael_statuses()[$status])) {
            $status = '';
        }
        $from = rh_ael_date(rh_ael_str($get, 'ae_from'));
        $to = rh_ael_date(rh_ael_str($get, 'ae_to'));
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $q = rh_ael_str($get, 'ae_q');
        $q = trim(function_exists('mb_substr') ? mb_substr($q, 0, RH_AEL_Q_MAX) : substr($q, 0, RH_AEL_Q_MAX));
        return ['job' => $job, 'status' => $status, 'from' => $from, 'to' => $to, 'q' => $q,
            'active' => ($job !== '' || $status !== '' || $from !== '' || $to !== '' || $q !== '')];
    }

    /**
     * WHERE for the filters (aliases l = automated_email_log, b = bookings): ['sql' => ' WHERE ...' or '', 'args'].
     * Dates are hotel days on sent_at, half-open (To is the whole day). The search matches every word in the
     * recipient, subject, stage or booking reference; a number (optionally "#123") also matches the account ID.
     */
    function rh_ael_where(array $f): array
    {
        $w = [];
        $args = [];
        if (($f['job'] ?? '') !== '') {
            $w[] = 'l.job = ?';
            $args[] = (string)$f['job'];
        }
        if (($f['status'] ?? '') !== '') {
            $w[] = 'l.status = ?';
            $args[] = (string)$f['status'];
        }
        if (($f['from'] ?? '') !== '') {
            $w[] = 'l.sent_at >= ?';
            $args[] = $f['from'] . ' 00:00:00';
        }
        if (($f['to'] ?? '') !== '') {
            $w[] = 'l.sent_at < ?';
            $args[] = (new DateTime($f['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        $q = (string)($f['q'] ?? '');
        if ($q !== '') {
            $s = rh_search_where($q, ['l.recipient', 'l.subject', 'l.stage', 'b.booking_reference']);
            $id = ltrim($q, '#');
            $byId = preg_match('/^\d{1,10}$/', $id) === 1;
            if ($s['sql'] !== '' && $byId) {
                $w[] = '(' . $s['sql'] . ' OR l.account_id = ?)';
                $args = array_merge($args, $s['params'], [(int)$id]);
            } elseif ($s['sql'] !== '') {
                $w[] = $s['sql'];
                $args = array_merge($args, $s['params']);
            } elseif ($byId) {
                $w[] = 'l.account_id = ?';
                $args[] = (int)$id;
            }
        }
        return ['sql' => $w ? ' WHERE ' . implode(' AND ', $w) : '', 'args' => $args];
    }

    /** FROM + JOIN shared by the count and the rows (the booking reference only for plain room rows). */
    function rh_ael_from_sql(): string
    {
        return " FROM automated_email_log l LEFT JOIN bookings b ON l.account_type = 'room' AND b.id = l.account_id";
    }

    /** [count SQL, rows SQL, args] for rh_lp_fetch(), both with the same WHERE; the rows end in their ORDER BY. */
    function rh_ael_sql(array $f): array
    {
        $where = rh_ael_where($f);
        $count = 'SELECT 1' . rh_ael_from_sql() . $where['sql'];
        $rows = 'SELECT l.id, l.job, l.account_type, l.account_id, l.stage, l.recipient, l.subject, l.status, l.attempts, l.error, l.sent_at,'
            . ' b.booking_reference' . rh_ael_from_sql() . $where['sql'] . ' ORDER BY l.id DESC';
        return [$count, $rows, $where['args']];
    }

    /** "12 results" / "1 result" / "No results"; capped counts end in "+". */
    function rh_ael_count_line(array $p): string
    {
        if (!empty($p['unknown'])) {
            return 'Results could not be counted just now';
        }
        $n = (int)($p['total'] ?? 0);
        if ($n <= 0) {
            return 'No results';
        }
        return number_format($n) . (!empty($p['capped']) ? '+' : '') . ' result' . ($n === 1 && empty($p['capped']) ? '' : 's');
    }
}
