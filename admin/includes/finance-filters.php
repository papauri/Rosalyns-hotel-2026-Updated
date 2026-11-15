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

require_once __DIR__ . '/../../includes/smart-search.php'; // the shared word-by-word / phone-number search matcher

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

    /** Payments page free-text search columns: payment fields + every linked account's name/reference/email/phone + amount. */
    function rh_fin_payments_search_cols(string $txnCol, array $conf): array
    {
        $txnCol = rh_fin_ident($txnCol, 'transaction_id');
        $c = rh_fin_conf_cols($conf);
        return [
            'p.payment_reference', 'p.receipt_number', 'p.invoice_number', 'p.booking_reference', "p.{$txnCol}",
            'p.payment_method', 'p.payment_status', 'CAST(p.booking_id AS CHAR)', 'CAST(p.total_amount AS CHAR)',
            "COALESCE(b.guest_name, '')", "COALESCE(b.guest_email, '')", "COALESCE(b.guest_phone, '')", "COALESCE(b.booking_reference, '')",
            "COALESCE(ci.{$c['company']}, '')", "COALESCE(ci.{$c['reference']}, '')", "COALESCE(ci.{$c['email']}, '')", "COALESCE(ci.{$c['phone']}, '')",
            "COALESCE(so.reference, '')", "COALESCE(so.customer_name, '')",
            "COALESCE(gi.name, '')", "COALESCE(gi.reference_number, '')", "COALESCE(gi.email, '')", "COALESCE(gi.phone, '')",
            "COALESCE(ei.name, '')", "COALESCE(ei.reference_number, '')", "COALESCE(ei.email, '')", "COALESCE(ei.phone, '')",
        ];
    }

    /** Phone columns of the accounts a payment can belong to (also compared digits-only for a typed phone number). */
    function rh_fin_payments_phone_cols(array $conf): array
    {
        $c = rh_fin_conf_cols($conf);
        return ['b.guest_phone', "ci.{$c['phone']}", 'gi.phone', 'ei.phone'];
    }

    /** One-word form of the payments search (every column LIKE ?), kept for callers that bind one pattern. */
    function rh_fin_payments_search_sql(string $txnCol, array $conf): string
    {
        return rh_fin_like_any(rh_fin_payments_search_cols($txnCol, $conf));
    }

    /**
     * Payments page WHERE, used by the cards, the list, the count and the CSV export (pair it with
     * rh_fin_payments_from()). $f keys: booking_type, scope_types[], booking_id, search, status, refund_status,
     * payment_method, start_date, end_date; optional (step 8g) direction, booking_status, group (+ has_group /
     * has_company column flags), recorded_by (+ recorded_by_names[], has_recorded_by), room_type, amount_from,
     * amount_to, tolerance (default BALANCE_TOLERANCE).
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
            // Every word must match somewhere ("phiri 50000"), and a typed phone number matches whatever its spacing
            // or +265 / 0 prefix (includes/smart-search.php). One word is the same clause as before.
            $s = rh_search_where($search, rh_fin_payments_search_cols($txnCol, $conf), rh_fin_payments_phone_cols($conf));
            if ($s['sql'] !== '') {
                $where[] = $s['sql'];
                foreach ($s['params'] as $v) {
                    $params[] = $v;
                }
            }
        }
        $status = rh_fin_get_str($f, 'status');
        if ($status === 'pending_partial') {
            // The Payments "Pending / Partial" card: pending or partial, refund rows excluded.
            $where[] = "p.payment_status IN ('pending','partial') AND COALESCE(p.payment_type, '') <> 'refund'";
        } elseif ($status === 'received') {
            // Money received (the Collected / Today figures): received statuses, refund rows excluded.
            $where[] = "p.payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(p.payment_type, '') <> 'refund'";
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
        // Step 8g "More filters" (all optional; every value is checked again here, so junk adds nothing).
        $direction = rh_fin_pick(rh_fin_get_str($f, 'direction'), rh_fin_pay_directions());
        if ($direction === 'in') {
            $where[] = "COALESCE(p.payment_type, '') <> 'refund'";
        } elseif ($direction === 'out') {
            $where[] = "p.payment_type = 'refund'";
        }
        $bkStatusSql = rh_fin_pay_booking_status_sql(rh_fin_pick(rh_fin_get_str($f, 'booking_status'), rh_fin_pay_booking_statuses()));
        if ($bkStatusSql !== '') {
            $where[] = $bkStatusSql;
        }
        $groupSql = rh_fin_pay_group_sql(rh_fin_pick(rh_fin_get_str($f, 'group'), rh_fin_pay_group_kinds()), !empty($f['has_group']), !empty($f['has_company']));
        if ($groupSql !== '') {
            $where[] = $groupSql;
        }
        // Recorded by: the staff id in recorded_by, else (rows saved with only a name, e.g. Record payment) processed_by
        // equal to that person's name, username or id - the same order Payment details uses for "Received by".
        $recBy = rh_fin_get_int($f, 'recorded_by');
        if ($recBy > 0) {
            $names = [];
            foreach (array_merge([(string)$recBy], (array)($f['recorded_by_names'] ?? [])) as $n) {
                if (is_string($n) && trim($n) !== '' && !in_array(trim($n), $names, true)) {
                    $names[] = trim($n);
                }
            }
            $in = implode(',', array_fill(0, count($names), '?'));
            if (!empty($f['has_recorded_by'])) {
                $where[] = "(p.recorded_by = ? OR (COALESCE(p.recorded_by, 0) = 0 AND p.processed_by IN ({$in})))";
                $params[] = $recBy;
            } else {
                $where[] = "p.processed_by IN ({$in})";
            }
            foreach ($names as $n) {
                $params[] = $n;
            }
        }
        $roomType = rh_fin_get_int($f, 'room_type');
        if ($roomType > 0) {
            $where[] = "p.booking_type = 'room' AND b.room_id = ?";
            $params[] = $roomType;
        }
        // Amount from / to: the amount the list shows (total_amount; refunds by the amount paid back), both ends included
        // within BALANCE_TOLERANCE.
        $tol = isset($f['tolerance']) ? abs((float)$f['tolerance']) : (defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01);
        $amtFrom = rh_fin_amount($f['amount_from'] ?? '');
        $amtTo = rh_fin_amount($f['amount_to'] ?? '');
        if ($amtFrom !== '' && $amtTo !== '' && (float)$amtFrom > (float)$amtTo) {
            [$amtFrom, $amtTo] = [$amtTo, $amtFrom];
        }
        if ($amtFrom !== '') {
            $where[] = 'ABS(p.total_amount) >= ?';
            $params[] = sprintf('%.4f', max(0.0, (float)$amtFrom - $tol));
        }
        if ($amtTo !== '') {
            $where[] = 'ABS(p.total_amount) <= ?';
            $params[] = sprintf('%.4f', (float)$amtTo + $tol);
        }
        $where = array_merge($where, rh_fin_date_conditions(function_exists('rh_pay_count_day_sql') ? rh_pay_count_day_sql('p') : 'p.payment_date', $f['start_date'] ?? '', $f['end_date'] ?? '', $params));
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
        if ($status === 'refunds') {
            // The Invoices "Refunded / Credit" card: refund rows paid out or being paid (rh_refund_settled_sql rule).
            $where[] = "p.payment_type = 'refund' AND p.refund_status IN ('completed','processing')";
        } elseif ($status !== 'all' && $status !== '') {
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

if (!function_exists('rh_fin_search_term')) {
    /** Payments free-text search: ?search_text=, or ?q= as an alias when search_text is empty (older deep links). */
    function rh_fin_search_term(array $get): string
    {
        $t = rh_fin_get_str($get, 'search_text');
        return $t !== '' ? $t : rh_fin_get_str($get, 'q');
    }

    /**
     * Reports page deep-link preset (Dashboard links such as reports.php?type=accounting&range=today).
     * - type: an alias of ?tab, used only when ?tab is absent. A known tab name is taken as is; 'accounting' (the
     *   Dashboard's restaurant / till revenue link) means the POS tab when POS is on, else Revenue. Anything else is
     *   ignored. The page still runs the result through its own tab whitelist and module gate.
     * - range=today: start = end = $today (the calendar day the Reports page already uses), only when neither
     *   start_date nor end_date was given. Any other range value is ignored.
     * @return array{tab:string, range_today:bool}
     */
    function rh_fin_reports_preset(array $get, array $validTabs, bool $posOn, string $today): array
    {
        $tab = '';
        if (rh_fin_get_str($get, 'tab') === '') {
            $type = strtolower(rh_fin_get_str($get, 'type'));
            if ($type === 'accounting') {
                $tab = $posOn ? 'fnb' : 'revenue';
            } elseif ($type !== '' && in_array($type, $validTabs, true)) {
                $tab = $type;
            }
        }
        $rangeToday = rh_fin_get_str($get, 'range') === 'today'
            && rh_fin_get_str($get, 'start_date') === '' && rh_fin_get_str($get, 'end_date') === ''
            && rh_fin_valid_date($today) !== '';
        return ['tab' => $tab, 'range_today' => $rangeToday];
    }

    /**
     * Payments "Accounts owing" Type badge + link for one row source. Known sources use $known; any other source
     * (pending payment rows of a non-restaurant source) shows its OWN type name, e.g. 'gym_shop' -> "Gym shop", with a neutral badge,
     * never the restaurant "Open tab" badge. Link: its pending rows in this ledger (booking_type + booking_id).
     * @return array{label:string, badge:string, link:string}
     */
    function rh_fin_outstanding_src_meta(string $src, array $known): array
    {
        if (isset($known[$src]) && is_array($known[$src])) {
            return $known[$src];
        }
        $name = trim(str_replace(['_', '-'], ' ', $src));
        $label = $name === '' ? 'Other' : ucfirst(strtolower($name));
        return [
            'label' => $label,
            'badge' => 'neutral',
            'link' => 'payments.php?' . http_build_query(['booking_type' => $src]) . '&booking_id=',
        ];
    }
}

if (!function_exists('rh_fin_pay_directions')) {
    /**
     * Payments page "More filters" (step 8g, owner 2026-11-15): the whitelists the page reads its GET values against
     * and the pure SQL pieces rh_fin_payments_where() adds, so the cards, list, count and CSV export follow them alike.
     * No new money rule: refunds keep their payout day through the existing date filter (rh_pay_count_day_sql).
     */

    /** ?direction=: money in (every row that is not a refund) or refunds (money paid back). */
    function rh_fin_pay_directions(): array
    {
        return ['in' => 'Money in', 'out' => 'Refunds (money out)'];
    }

    /** ?booking_status=: the status of the booking the payment belongs to. Only Cancelled reads non-room accounts. */
    function rh_fin_pay_booking_statuses(): array
    {
        return [
            'upcoming'    => 'Upcoming (not arrived yet)',
            'in_house'    => 'In-house (checked in)',
            'checked_out' => 'Checked out',
            'no_show'     => 'No-show',
            'cancelled'   => 'Cancelled',
        ];
    }

    /** ?group=: room bookings in a group of 2+ rooms, billed to a company account, or either. */
    function rh_fin_pay_group_kinds(): array
    {
        return ['group' => 'Group bookings (2+ rooms)', 'company' => 'Company on account', 'group_company' => 'Group or company'];
    }

    /** $value when it is a key of $list, else ''. */
    function rh_fin_pick(string $value, array $list): string
    {
        return ($value !== '' && isset($list[$value])) ? $value : '';
    }

    /**
     * An amount box: digits with an optional ,-grouping and up to 2 decimals ("1,250.50"), as '1250.50'; anything else ''.
     * Never negative (refunds are searched by the amount paid back, as the list shows it).
     */
    function rh_fin_amount($raw): string
    {
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            return '';
        }
        $v = str_replace([',', ' '], '', trim((string)$raw));
        if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v)) {
            return '';
        }
        return number_format((float)$v, 2, '.', '');
    }

    /** SQL condition (fixed text, no parameters) for a ?booking_status= key; '' for an unknown key. */
    function rh_fin_pay_booking_status_sql(string $key): string
    {
        $room = static function (string $cond): string {
            return "(p.booking_type = 'room' AND b.id IS NOT NULL AND b.deleted_at IS NULL AND {$cond})";
        };
        switch ($key) {
            case 'upcoming':
                return $room("b.status IN ('pending','confirmed','tentative') AND b.check_in_date >= CURDATE()");
            case 'in_house':
                return $room("b.status = 'checked-in'");
            case 'checked_out':
                return $room("b.status = 'checked-out'");
            case 'no_show':
                return $room("b.status = 'no-show'");
            case 'cancelled':
                return '(' . $room("b.status = 'cancelled'")
                    . " OR (p.booking_type = 'conference' AND ci.status = 'cancelled')"
                    . " OR (p.booking_type = 'gym' AND gi.status = 'cancelled')"
                    . " OR (p.booking_type = 'event' AND ei.status = 'cancelled'))";
        }
        return '';
    }

    /**
     * SQL condition (fixed text) for a ?group= key. Group = the booking's group root (primary_booking_id, else itself)
     * has 2+ rooms not deleted, the same rule as the Group line on Payment details. Company = billing_account_id set.
     * $hasGroup / $hasCompany: whether bookings has those columns; a kind whose column is missing gives ''.
     */
    function rh_fin_pay_group_sql(string $key, bool $hasGroup, bool $hasCompany): string
    {
        $root = 'COALESCE(NULLIF(b.primary_booking_id, 0), b.id)';
        $group = "(SELECT COUNT(*) FROM bookings g WHERE (g.id = {$root} OR g.primary_booking_id = {$root}) AND g.deleted_at IS NULL) > 1";
        $company = 'COALESCE(b.billing_account_id, 0) > 0';
        $parts = [];
        if (($key === 'group' || $key === 'group_company') && $hasGroup) {
            $parts[] = $group;
        }
        if (($key === 'company' || $key === 'group_company') && $hasCompany) {
            $parts[] = $company;
        }
        if ($parts === []) {
            return '';
        }
        return "(p.booking_type = 'room' AND b.id IS NOT NULL AND (" . implode(' OR ', $parts) . '))';
    }
}
