<?php

/**
 * One receivables rule for every screen (Reports Overview + Aging, Invoices Outstanding, Accounting
 * dashboard, End of Day "Still owed"): money owed is the ACCOUNT's amount_due, never a pending payment row.
 *
 *   room        every live booking with a balance (pending/confirmed/checked-in/checked-out, plus the first
 *               night kept on a cancellation)
 *   conference  confirmed or completed bookings only (an unconfirmed enquiry is not owed money)
 *   event       confirmed or completed bookings only
 *   gym         confirmed or converted enquiries only
 *
 * Other sources that have no account row (restaurant tabs) keep using their pending payment rows so
 * they do not drop out of the picture.
 */

if (!function_exists('rh_receivable_status_sql')) {
    /** SQL condition (no leading AND) for which accounts of $type count as receivables. */
    function rh_receivable_status_sql(string $type, string $alias = ''): string
    {
        $a = $alias !== '' ? $alias . '.' : '';
        switch ($type) {
            case 'room':
                return "{$a}deleted_at IS NULL AND ({$a}status IN ('pending','confirmed','checked-in','checked-out') OR ({$a}status = 'cancelled' AND COALESCE({$a}cancellation_retained_amount,0) > 0))";
            case 'conference':
            case 'event':
                return "{$a}status IN ('confirmed','completed')";
            case 'gym':
                return "{$a}status IN ('confirmed','converted')";
        }
        return '1=0';
    }
}

if (!function_exists('rh_receivable_rows')) {
    /**
     * Every open receivable, oldest first.
     * @param array $mods module flags: bookings, conference, gym, events, tabs (a missing key means enabled)
     * @param array|null $failed filled with the names of any source that could not be read; a screen must then
     *                           show "Unavailable" rather than a figure that silently leaves that source out
     * @return array<int,array{type:string,id:int,reference:string,client:string,status:string,amount_paid:float,amount_due:float,days_outstanding:int,source:string}>
     */
    function rh_receivable_rows(PDO $pdo, array $mods = [], ?array &$failed = null): array
    {
        $failed = [];
        $on = static function (string $k) use ($mods): bool {
            return !array_key_exists($k, $mods) || !empty($mods[$k]);
        };
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $cf = function_exists('finance_conference_fields') ? finance_conference_fields($pdo) : [
            'reference' => 'inquiry_reference', 'company' => 'company_name', 'contact_name' => 'contact_person', 'start_date' => 'event_date',
        ];
        $queries = [];
        if ($on('bookings')) {
            $queries['room'] = "SELECT 'room' AS type, b.id, b.booking_reference AS reference, b.guest_name AS client, b.status, b.amount_paid, b.amount_due,
                    GREATEST(0, DATEDIFF(CURDATE(), CASE WHEN b.status IN ('checked-out','cancelled') THEN b.check_out_date ELSE CURDATE() END)) AS days_outstanding
                FROM bookings b WHERE b.amount_due > {$tol} AND " . rh_receivable_status_sql('room', 'b');
        }
        if ($on('conference')) {
            $queries['conference'] = "SELECT 'conference' AS type, ci.id, ci.{$cf['reference']} AS reference,
                    COALESCE(NULLIF(ci.{$cf['company']}, ''), ci.{$cf['contact_name']}) AS client, ci.status, ci.amount_paid, ci.amount_due,
                    GREATEST(0, DATEDIFF(CURDATE(), COALESCE(ci.{$cf['start_date']}, DATE(ci.created_at)))) AS days_outstanding
                FROM conference_inquiries ci WHERE ci.amount_due > {$tol} AND " . rh_receivable_status_sql('conference', 'ci');
        }
        if ($on('events')) {
            $queries['event'] = "SELECT 'event' AS type, ei.id, ei.reference_number AS reference, ei.name AS client, ei.status, ei.amount_paid, ei.amount_due,
                    GREATEST(0, DATEDIFF(CURDATE(), COALESCE(ev.event_date, DATE(ei.created_at)))) AS days_outstanding
                FROM event_inquiries ei LEFT JOIN events ev ON ev.id = ei.event_id
                WHERE ei.amount_due > {$tol} AND " . rh_receivable_status_sql('event', 'ei');
        }
        if ($on('gym')) {
            $queries['gym'] = "SELECT 'gym' AS type, gi.id, gi.reference_number AS reference, gi.name AS client, gi.status, gi.amount_paid, gi.amount_due,
                    GREATEST(0, DATEDIFF(CURDATE(), DATE(gi.created_at))) AS days_outstanding
                FROM gym_inquiries gi WHERE gi.amount_due > {$tol} AND " . rh_receivable_status_sql('gym', 'gi');
        }
        // Sources with no account row: their pending payment rows stand in (restaurant tabs and the like).
        if ($on('tabs')) {
        $queries['tabs'] = "SELECT p.booking_type AS type, p.booking_id AS id, COALESCE(NULLIF(p.booking_reference, ''), p.payment_reference) AS reference,
                    COALESCE(so.customer_name, 'N/A') AS client, 'open' AS status, 0 AS amount_paid, p.total_amount AS amount_due,
                    GREATEST(0, DATEDIFF(CURDATE(), p.payment_date)) AS days_outstanding
                FROM payments p LEFT JOIN stock_orders so ON p.booking_type = 'restaurant' AND p.booking_id = so.id
                WHERE p.payment_status IN ('pending','partial') AND COALESCE(p.payment_type, '') <> 'refund'
                  AND p.deleted_at IS NULL AND p.booking_type NOT IN ('room','conference','event','gym')
                  AND p.total_amount > {$tol}";
        }

        $rows = [];
        foreach ($queries as $srcName => $sql) {
            try {
                foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rows[] = [
                        'type' => (string)$r['type'], 'id' => (int)$r['id'], 'reference' => (string)($r['reference'] ?? ''),
                        'client' => (string)($r['client'] ?? ''), 'status' => (string)($r['status'] ?? ''), 'amount_paid' => round((float)($r['amount_paid'] ?? 0), 2), 'amount_due' => round((float)$r['amount_due'], 2),
                        'days_outstanding' => (int)$r['days_outstanding'], 'source' => (string)$r['type'],
                    ];
                }
            } catch (Throwable $e) {
                error_log('rh_receivable_rows ' . $srcName . ': ' . $e->getMessage());
                $failed[] = $srcName;
            }
        }
        usort($rows, static function (array $a, array $b): int {
            return $b['days_outstanding'] <=> $a['days_outstanding'];
        });
        return $rows;
    }
}

if (!function_exists('rh_receivable_summary')) {
    /**
     * Totals from rh_receivable_rows(): overall count/total, per type, and the 0-30 / 31-60 / 61-90 / 90+ buckets.
     * 'failed' lists the sources that could not be read (empty = the figure is complete). Open unpaid tabs
     * are their own by_type entries (restaurant) - see rh_receivable_type_label().
     * @return array{count:int,total:float,by_type:array,buckets:array,rows:array,failed:array}
     */
    function rh_receivable_summary(PDO $pdo, array $mods = []): array
    {
        $failed = [];
        $rows = rh_receivable_rows($pdo, $mods, $failed);
        $out = [
            'count' => 0, 'total' => 0.0, 'by_type' => [], 'rows' => $rows, 'failed' => $failed,
            'buckets' => ['0-30' => ['count' => 0, 'amount' => 0.0], '31-60' => ['count' => 0, 'amount' => 0.0],
                          '61-90' => ['count' => 0, 'amount' => 0.0], '90+' => ['count' => 0, 'amount' => 0.0]],
        ];
        foreach ($rows as $r) {
            $out['count']++;
            $out['total'] += $r['amount_due'];
            $t = $r['type'];
            $out['by_type'][$t] = $out['by_type'][$t] ?? ['count' => 0, 'amount' => 0.0];
            $out['by_type'][$t]['count']++;
            $out['by_type'][$t]['amount'] += $r['amount_due'];
            $d = $r['days_outstanding'];
            $bk = $d <= 30 ? '0-30' : ($d <= 60 ? '31-60' : ($d <= 90 ? '61-90' : '90+'));
            $out['buckets'][$bk]['count']++;
            $out['buckets'][$bk]['amount'] += $r['amount_due'];
        }
        $out['total'] = round($out['total'], 2);
        return $out;
    }
}

if (!function_exists('rh_receivable_type_label')) {
    /** Plain label for a receivable type; unpaid tabs are named as such. */
    function rh_receivable_type_label(string $type): string
    {
        switch ($type) {
            case 'room': return 'Room bookings';
            case 'conference': return 'Conference';
            case 'event': return 'Events';
            case 'gym': return 'Gym';
            default: return 'Open tabs (unpaid)';
        }
    }
}

if (!function_exists('rh_receivable_mods')) {
    /** Module flags for the receivables helper from the installation's enabled modules. */
    function rh_receivable_mods(): array
    {
        return [
            'bookings'   => function_exists('moduleEnabled') && moduleEnabled('bookings'),
            'conference' => function_exists('moduleEnabled') && moduleEnabled('conference'),
            'gym'        => function_exists('moduleEnabled') && moduleEnabled('gym'),
            'events'     => function_exists('isEventsEnabled') && isEventsEnabled(),
            'tabs'       => function_exists('moduleEnabled') && moduleEnabled('pos'),
        ];
    }
}
