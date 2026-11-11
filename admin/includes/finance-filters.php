<?php
/**
 * Finance list filters (admin/payments.php, admin/invoices.php, admin/receipts.php): the pure rules shared by
 * each page's summary cards, list, row count and CSV export, so they can never disagree. No database, no settings.
 * Unit tested by scripts/tests/finance_filters.php.
 *
 * Dates: payment_date "To" is inclusive of the whole day and written half-open
 * (payment_date < To + 1 day), which is the same as <= To for a DATE column and also right if a time is ever stored.
 * Only real Y-m-d calendar dates are used; anything else is ignored.
 */

if (!function_exists('rh_fin_valid_date')) {
    /** 'Y-m-d' when it is a real calendar date, else ''. */
    function rh_fin_valid_date($d): string
    {
        if (!is_string($d)) {
            return '';
        }
        $d = trim($d);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return '';
        }
        return $d;
    }

    /** A trusted column name (letters, digits, underscore) or the fallback; never user text. */
    function rh_fin_ident(string $name, string $fallback): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) ? $name : $fallback;
    }

    /**
     * Date range conditions on $col (e.g. 'p.payment_date'); appends to $params.
     * @return string[] conditions to AND together
     */
    function rh_fin_date_conditions(string $col, $from, $to, array &$params): array
    {
        $out = [];
        $from = rh_fin_valid_date($from);
        $to = rh_fin_valid_date($to);
        if ($from !== '') {
            $out[] = $col . ' >= ?';
            $params[] = $from;
        }
        if ($to !== '') {
            $out[] = $col . ' < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $to;
        }
        return $out;
    }

    /** booking_type = ? when one type is picked, else IN (enabled types) when module scoping is on. */
    function rh_fin_type_conditions(string $col, string $type, array $scopeTypes, array &$params): array
    {
        if ($type !== '') {
            $params[] = $type;
            return [$col . ' = ?'];
        }
        $scopeTypes = array_values(array_filter($scopeTypes, static function ($t) { return is_string($t) && $t !== ''; }));
        if ($scopeTypes !== []) {
            foreach ($scopeTypes as $t) {
                $params[] = $t;
            }
            return [$col . ' IN (' . implode(',', array_fill(0, count($scopeTypes), '?')) . ')'];
        }
        return [];
    }

    /** Conference column names from finance_conference_fields(), checked as identifiers. */
    function rh_fin_conf_cols(array $conf): array
    {
        return [
            'company'   => rh_fin_ident((string)($conf['company'] ?? ''), 'company_name'),
            'reference' => rh_fin_ident((string)($conf['reference'] ?? ''), 'inquiry_reference'),
            'email'     => rh_fin_ident((string)($conf['email'] ?? ''), 'email'),
            'phone'     => rh_fin_ident((string)($conf['phone'] ?? ''), 'phone'),
        ];
    }

    /**
     * The one FROM + joins for the Payments page (list, count, export, cards). Every join is on the other table's
     * primary key id and restricted to its booking_type, so each payment row matches at most one row: sums over
     * this FROM equal sums over the payment rows themselves.
     */
    function rh_fin_payments_from(): string
    {
        return "
    FROM payments p
    LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
    LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
    LEFT JOIN stock_orders so ON p.booking_type = 'restaurant' AND p.booking_id = so.id
    LEFT JOIN gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id
    LEFT JOIN event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id
";
    }

    /** Payments page free-text search: payment fields + every linked account's name/reference/email/phone + amount. */
    function rh_fin_payments_search_sql(string $txnCol, array $conf): string
    {
        $txnCol = rh_fin_ident($txnCol, 'transaction_id');
        $c = rh_fin_conf_cols($conf);
        return "(
        p.payment_reference LIKE ?
        OR p.receipt_number LIKE ?
        OR p.invoice_number LIKE ?
        OR p.booking_reference LIKE ?
        OR p.{$txnCol} LIKE ?
        OR p.payment_method LIKE ?
        OR p.payment_status LIKE ?
        OR CAST(p.booking_id AS CHAR) LIKE ?
        OR CAST(p.total_amount AS CHAR) LIKE ?
        OR COALESCE(b.guest_name, '') LIKE ?
        OR COALESCE(b.guest_email, '') LIKE ?
        OR COALESCE(b.guest_phone, '') LIKE ?
        OR COALESCE(b.booking_reference, '') LIKE ?
        OR COALESCE(ci.{$c['company']}, '') LIKE ?
        OR COALESCE(ci.{$c['reference']}, '') LIKE ?
        OR COALESCE(ci.{$c['email']}, '') LIKE ?
        OR COALESCE(ci.{$c['phone']}, '') LIKE ?
        OR COALESCE(so.reference, '') LIKE ?
        OR COALESCE(so.customer_name, '') LIKE ?
        OR COALESCE(gi.name, '') LIKE ?
        OR COALESCE(gi.reference_number, '') LIKE ?
        OR COALESCE(gi.email, '') LIKE ?
        OR COALESCE(gi.phone, '') LIKE ?
        OR COALESCE(ei.name, '') LIKE ?
        OR COALESCE(ei.reference_number, '') LIKE ?
        OR COALESCE(ei.email, '') LIKE ?
        OR COALESCE(ei.phone, '') LIKE ?
    )";
    }

    /**
     * Payments page WHERE, used by the cards, the list, the count and the CSV export (pair it with
     * rh_fin_payments_from()). $f keys: booking_type, scope_types[], booking_id, search, status, refund_status,
     * payment_method, start_date, end_date.
     * @return array{sql:string, params:array}
     */
    function rh_fin_payments_where(array $f, string $txnCol, array $conf): array
    {
        $params = [];
        $where = ['p.deleted_at IS NULL'];
        $type = (string)($f['booking_type'] ?? '');
        $where = array_merge($where, rh_fin_type_conditions('p.booking_type', $type, $type === '' ? (array)($f['scope_types'] ?? []) : [], $params));
        $bookingId = (int)($f['booking_id'] ?? 0);
        if ($bookingId > 0) {
            $where[] = 'p.booking_id = ?';
            $params[] = $bookingId;
        }
        $search = trim((string)($f['search'] ?? ''));
        if ($search !== '') {
            $searchSql = rh_fin_payments_search_sql($txnCol, $conf);
            $where[] = $searchSql;
            $like = '%' . $search . '%';
            foreach (array_fill(0, substr_count($searchSql, '?'), $like) as $v) {
                $params[] = $v;
            }
        }
        $status = (string)($f['status'] ?? '');
        if ($status !== '') {
            $where[] = 'p.payment_status = ?';
            $params[] = $status;
        }
        $refundStatus = (string)($f['refund_status'] ?? '');
        if ($refundStatus !== '') {
            $where[] = "p.payment_type = 'refund' AND p.refund_status = ?";
            $params[] = $refundStatus;
        }
        $method = (string)($f['payment_method'] ?? '');
        if ($method !== '') {
            $where[] = 'p.payment_method = ?';
            $params[] = $method;
        }
        $where = array_merge($where, rh_fin_date_conditions('p.payment_date', $f['start_date'] ?? '', $f['end_date'] ?? '', $params));
        return ['sql' => 'WHERE ' . implode(' AND ', $where), 'params' => $params];
    }

    /**
     * Invoices page WHERE (payment columns only, alias p), used by the cards and the list.
     * $f keys: type ('all' or a booking type), scope_types[], status ('all' or a status), search.
     * @return array{sql:string, params:array}
     */
    function rh_fin_invoices_where(array $f): array
    {
        $params = [];
        $where = ['p.deleted_at IS NULL'];
        $type = (string)($f['type'] ?? 'all');
        $type = $type === 'all' ? '' : $type;
        $where = array_merge($where, rh_fin_type_conditions('p.booking_type', $type, $type === '' ? (array)($f['scope_types'] ?? []) : [], $params));
        $status = (string)($f['status'] ?? 'all');
        if ($status !== 'all' && $status !== '') {
            $where[] = 'p.payment_status = ?';
            $params[] = $status;
        }
        $search = (string)($f['search'] ?? '');
        if ($search !== '') {
            $where[] = '(p.invoice_number LIKE ? OR p.payment_reference LIKE ? OR p.booking_reference LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        return ['sql' => 'WHERE ' . implode(' AND ', $where), 'params' => $params];
    }
}
