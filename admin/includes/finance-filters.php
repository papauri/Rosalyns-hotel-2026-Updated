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

    /**
     * A query-string value as text: trimmed when it is a string or number, $default for anything else (an array
     * such as ?search[]=x is ignored rather than turned into "Array" or a PHP 8 TypeError).
     */
    function rh_fin_get_str(array $src, string $key, string $default = ''): string
    {
        $v = $src[$key] ?? null;
        if (is_string($v) || is_int($v) || is_float($v)) {
            return trim((string)$v);
        }
        return $default;
    }

    /** A query-string value as a whole number; arrays and non-numeric text give $default (never 1 from (int)array). */
    function rh_fin_get_int(array $src, string $key, int $default = 0): int
    {
        $v = rh_fin_get_str($src, $key);
        return preg_match('/^-?\d{1,18}$/', $v) ? (int)$v : $default;
    }

    /**
     * LIKE pattern for "contains $term": % and _ typed by staff are matched literally. The escape character is '!'
     * (paired with rh_fin_like_any()'s ESCAPE '!'), which works whatever MySQL's backslash mode is.
     */
    function rh_fin_like_param(string $term): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    }

    /** "(c1 LIKE ? ESCAPE '!' OR c2 LIKE ? ESCAPE '!' ...)" over trusted SQL column expressions (never user text). */
    function rh_fin_like_any(array $cols): string
    {
        $parts = [];
        foreach ($cols as $c) {
            $parts[] = $c . " LIKE ? ESCAPE '!'";
        }
        return "(\n        " . implode("\n        OR ", $parts) . "\n    )";
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

    /**
     * Refund workflow statuses from ?refund_status= text: one status or a comma list ("pending,processing", the
     * Dashboard "Refunds to approve" queue). Only known statuses are kept, each once, in lifecycle order; anything
     * else is dropped, so junk gives [] (no filter) and the result is the same whatever order was typed.
     * @return string[]
     */
    function rh_fin_refund_statuses($raw): array
    {
        $known = ['pending', 'processing', 'completed', 'failed', 'rejected'];
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $picked = array_map('trim', explode(',', strtolower($raw)));
        return array_values(array_intersect($known, $picked));
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
        return rh_fin_like_any([
            'p.payment_reference', 'p.receipt_number', 'p.invoice_number', 'p.booking_reference', "p.{$txnCol}",
            'p.payment_method', 'p.payment_status', 'CAST(p.booking_id AS CHAR)', 'CAST(p.total_amount AS CHAR)',
            "COALESCE(b.guest_name, '')", "COALESCE(b.guest_email, '')", "COALESCE(b.guest_phone, '')", "COALESCE(b.booking_reference, '')",
            "COALESCE(ci.{$c['company']}, '')", "COALESCE(ci.{$c['reference']}, '')", "COALESCE(ci.{$c['email']}, '')", "COALESCE(ci.{$c['phone']}, '')",
            "COALESCE(so.reference, '')", "COALESCE(so.customer_name, '')",
            "COALESCE(gi.name, '')", "COALESCE(gi.reference_number, '')", "COALESCE(gi.email, '')", "COALESCE(gi.phone, '')",
            "COALESCE(ei.name, '')", "COALESCE(ei.reference_number, '')", "COALESCE(ei.email, '')", "COALESCE(ei.phone, '')",
        ]);
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
        $type = rh_fin_get_str($f, 'booking_type');
        $where = array_merge($where, rh_fin_type_conditions('p.booking_type', $type, $type === '' ? (array)($f['scope_types'] ?? []) : [], $params));
        $bookingId = rh_fin_get_int($f, 'booking_id');
        if ($bookingId > 0) {
            $where[] = 'p.booking_id = ?';
            $params[] = $bookingId;
        }
        $search = rh_fin_get_str($f, 'search');
        if ($search !== '') {
            $searchSql = rh_fin_payments_search_sql($txnCol, $conf);
            $where[] = $searchSql;
            $like = rh_fin_like_param($search);
            foreach (array_fill(0, substr_count($searchSql, '?'), $like) as $v) {
                $params[] = $v;
            }
        }
        $status = rh_fin_get_str($f, 'status');
        if ($status === 'pending_partial') {
            // The Payments "Pending / Partial" card: pending or partial, refund rows excluded.
            $where[] = "p.payment_status IN ('pending','partial') AND COALESCE(p.payment_type, '') <> 'refund'";
        } elseif ($status !== '') {
            $where[] = 'p.payment_status = ?';
            $params[] = $status;
        }
        // refund_status: one status or a comma list (rh_fin_refund_statuses), each bound as a parameter.
        $refundStatuses = rh_fin_refund_statuses(rh_fin_get_str($f, 'refund_status'));
        if (count($refundStatuses) === 1) {
            $where[] = "p.payment_type = 'refund' AND p.refund_status = ?";
            $params[] = $refundStatuses[0];
        } elseif ($refundStatuses !== []) {
            $where[] = "p.payment_type = 'refund' AND p.refund_status IN (" . implode(',', array_fill(0, count($refundStatuses), '?')) . ')';
            foreach ($refundStatuses as $rs) {
                $params[] = $rs;
            }
        }
        $method = rh_fin_get_str($f, 'payment_method');
        if ($method !== '') {
            $where[] = 'p.payment_method = ?';
            $params[] = $method;
        }
        $where = array_merge($where, rh_fin_date_conditions('p.payment_date', $f['start_date'] ?? '', $f['end_date'] ?? '', $params));
        return ['sql' => 'WHERE ' . implode(' AND ', $where), 'params' => $params];
    }

    /**
     * The one FROM + joins for the Invoices page cards (list adds its 1:1 room / conference-room name joins).
     * Every join is on the other table's primary key and restricted to its booking_type, so each payment row
     * matches at most one row. Cards wrap it as "WHERE id IN (SELECT p.id <from> <where>)" so their own
     * unqualified payment columns stay unambiguous.
     */
    function rh_fin_invoices_from(): string
    {
        return "
    FROM payments p
    LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
    LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
    LEFT JOIN gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id
    LEFT JOIN event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id
";
    }

    /** Invoices page free-text search: document numbers plus the customer name and email the list shows. */
    function rh_fin_invoices_search_sql(array $conf): string
    {
        $c = rh_fin_conf_cols($conf);
        return rh_fin_like_any([
            'p.invoice_number', 'p.payment_reference', 'p.booking_reference',
            "COALESCE(b.guest_name, '')", "COALESCE(b.guest_email, '')",
            "COALESCE(ci.{$c['company']}, '')", "COALESCE(ci.{$c['email']}, '')",
            "COALESCE(gi.name, '')", "COALESCE(gi.email, '')",
            "COALESCE(ei.name, '')", "COALESCE(ei.email, '')",
        ]);
    }

    /**
     * Invoices page WHERE (alias p; the search also reads b / ci / gi / ei, so pair it with rh_fin_invoices_from()
     * or the list's own joins), used by the cards and the list.
     * $f keys: type ('all' or a booking type), scope_types[], status ('all' or a status), search.
     * $conf: finance_conference_fields() column names (checked as identifiers).
     * @return array{sql:string, params:array}
     */
    function rh_fin_invoices_where(array $f, array $conf = []): array
    {
        $params = [];
        $where = ['p.deleted_at IS NULL'];
        $type = rh_fin_get_str($f, 'type', 'all');
        $type = $type === 'all' ? '' : $type;
        $where = array_merge($where, rh_fin_type_conditions('p.booking_type', $type, $type === '' ? (array)($f['scope_types'] ?? []) : [], $params));
        $status = rh_fin_get_str($f, 'status', 'all');
        if ($status !== 'all' && $status !== '') {
            $where[] = 'p.payment_status = ?';
            $params[] = $status;
        }
        $search = rh_fin_get_str($f, 'search');
        if ($search !== '') {
            $searchSql = rh_fin_invoices_search_sql($conf);
            $where[] = $searchSql;
            $like = rh_fin_like_param($search);
            foreach (array_fill(0, substr_count($searchSql, '?'), $like) as $v) {
                $params[] = $v;
            }
        }
        return ['sql' => 'WHERE ' . implode(' AND ', $where), 'params' => $params];
    }
}
