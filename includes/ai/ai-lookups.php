<?php

/**
 * One-record lookups (owner 2026-10-28: "follow up on the status of an order or gym stuff ... I want a complete all
 * rounder"). READ ONLY: prepared statements, no writes; voids, payments and changes stay on their screens.
 *
 *   order_status        one till / room-service order by reference, table, room or name (or the orders open now)
 *   gym_watch + args    one member (package, status, dates, days left, balance, check-ins, classes) or a class /
 *                       slot (time, capacity, who is booked, spaces left)
 *   events_pipeline     + query: one conference booking or event enquiry
 *   work_queues         + room: one room's status, housekeeping jobs and maintenance tasks
 *   inventory_watch     + item: one stock item's level; + purchase_order: one purchase order and what has arrived
 *
 * Every lookup: one record -> its detail; several -> a short list to pick from (ai_lookup_matches); none -> says so and
 * offers what IS there. A fixed number of queries whatever the size of the data (tested). Sizes are settings
 * (rh_ai_num), never numbers written here. Each tool is module-gated and permission-checked in the registry, and each
 * part re-checks its own module and permission here.
 */

require_once __DIR__ . '/ai-core.php';

/** A LIKE pattern part with the user's % and _ taken literally. */
function rh_ai_like(string $v): string
{
    return addcslashes($v, '%_\\');
}

/**
 * Match a table / room / document number: exact, or for a bare number also any value ending in it ("5" finds "T5",
 * "DELU-05"). @return array{0:string,1:array} [sql, params]
 */
function rh_ai_unit_where(string $col, string $v): array
{
    $v = trim($v);
    if (preg_match('/^\d{1,6}$/', $v)) {
        return ["($col = ? OR $col REGEXP ?)", [$v, '(^|[^0-9])0*' . (int)$v . '$']];
    }
    return ["$col = ?", [$v]];
}

/** Placeholders for an IN list. */
function rh_ai_in(array $ids): string
{
    return implode(',', array_fill(0, max(1, count($ids)), '?'));
}

// ── Orders (POS, room service) ───────────────────────────────────────────────

function rh_ai_lookup_tools(): array
{
    return [
        'order_status' => [
            'description' => 'Where one till or room-service order is (by reference, table, room or name; nothing given = the orders open now). Waiting, being made, ready, served, paid or voided per station, who opened it, minutes since ordered and since ready, and the balance left. Read only - voids, payments and changes are on the POS screen.',
            'schema' => ['properties' => [
                'reference' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Order or receipt reference'],
                'table' => ['type' => 'string', 'maxLength' => 20], 'room' => ['type' => 'string', 'maxLength' => 30],
                'name' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Tab or customer name (tokens are fine)']]],
            'perm' => ['pos_till', 'pos_all_tabs', 'stock_orders', 'kds_view', 'bds_view', 'cds_view', 'room_service_view'], 'module' => ['pos'],
            'cache' => 15, 'handler' => 'rh_ai_t_order_status', 'step' => 'Checking the order',
        ],
    ];
}

/** Order statuses that are still open (a tab or an order not yet settled), as the POS screen treats them. */
function rh_ai_order_open_statuses(): array
{
    return ['placed', 'pending', 'confirmed'];
}

/** Plain word for one item's kitchen state. */
function rh_ai_item_state(string $s): string
{
    return ['pending' => 'waiting', 'preparing' => 'being made', 'in_progress' => 'being made', 'ready' => 'ready', 'collection' => 'ready for collection', 'served' => 'served', 'void' => 'voided'][$s] ?? $s;
}

/**
 * Where an order is: [state, kitchen_state|null, minutes since it was ready|null]. Voided / refunded first; then the
 * items (all served -> served; all ready or served -> ready; any started or ready -> being made; else waiting); an order
 * with no kitchen items is paid or waiting by its payment.
 */
function rh_ai_order_state(array $o, array $items): array
{
    $st = (string)$o['status'];
    if (in_array($st, ['voided', 'cancelled'], true)) {
        return ['voided', null, null];
    }
    if ($st === 'refunded') {
        return ['refunded', null, null];
    }
    $paid = in_array($st, ['paid', 'completed'], true);
    $live = array_values(array_filter($items, static fn($i) => (string)$i['kds_status'] !== 'void'));
    $kitchen = null;
    $readyMin = null;
    if ($live) {
        $c = ['pending' => 0, 'preparing' => 0, 'ready' => 0, 'served' => 0];
        foreach ($live as $i) {
            $k = (string)$i['kds_status'];
            $k = $k === 'collection' ? 'ready' : ($k === 'in_progress' ? 'preparing' : $k);
            $c[$k] = ($c[$k] ?? 0) + 1;
            if ($i['ready_min'] !== null && in_array($k, ['ready', 'served'], true)) {
                $readyMin = $readyMin === null ? (int)$i['ready_min'] : min($readyMin, (int)$i['ready_min']); // the last item to be ready
            }
        }
        $n = count($live);
        if ($c['served'] === $n) {
            $kitchen = 'served';
        } elseif ($c['ready'] + $c['served'] === $n) {
            $kitchen = 'ready';
        } elseif ($c['preparing'] > 0 || $c['ready'] + $c['served'] > 0) {
            $kitchen = 'being made';
        } else {
            $kitchen = 'waiting';
        }
    }
    if ($paid && ($kitchen === null || $kitchen === 'served')) {
        return ['paid', $kitchen, null];
    }
    return [$kitchen ?? ($paid ? 'paid' : 'waiting'), $kitchen, $kitchen === 'ready' ? $readyMin : null];
}

function rh_ai_t_order_status(PDO $db, array $user, array $a): array
{
    $n = rh_ai_num('ai_lookup_matches');
    $hours = rh_ai_num('ai_order_lookback_hours');
    $uid = (int)$user['id'];
    // Who sees which orders: the POS screen's own rule (all tabs with pos_all_tabs; the stations and Orders screen see every
    // order) - otherwise only the orders this person opened. Room-service orders only where room service is switched on.
    $all = false;
    foreach (['pos_all_tabs', 'stock_orders', 'kds_view', 'bds_view', 'cds_view', 'room_service_view'] as $p) {
        $all = $all || rh_ai_has_perm($uid, $p);
    }
    $scope = '';
    $scopeArgs = [];
    if (!$all) {
        $scope .= ' AND o.created_by = ?';
        $scopeArgs[] = $uid;
    }
    if (!rh_ai_module_on('station_room_service')) {
        $scope .= " AND o.order_type <> 'room_service'";
    }
    $openSql = "o.status IN ('" . implode("','", rh_ai_order_open_statuses()) . "')";
    $cur = rh_ai_currency();
    $ref = trim((string)($a['reference'] ?? ''));
    $table = trim((string)preg_replace('/^(table|tbl)\s*(no\.?|number|#)?\s*/i', '', (string)($a['table'] ?? '')));
    $room = trim((string)preg_replace('/^room\s*(no\.?|number|#)?\s*/i', '', (string)($a['room'] ?? '')));
    $name = trim((string)($a['name'] ?? ''));
    $base = ['currency' => $cur];
    if (!$all) {
        $base['note'] = 'Only orders this person opened are shown (their till access).';
    }
    $link = rh_ai_deep_link($user, 'pos.php', 'Open the POS') ?? rh_ai_deep_link($user, 'stock-orders.php', 'Open Orders');
    $withLink = static function (array $out, ?array $l) {
        if ($l) {
            $out['link'] = $l['url'];
            $out['link_label'] = $l['label'];
        }
        return $out;
    };
    $cols = "o.id, o.reference, o.invoice_number, o.order_type, o.table_number, o.room_number, o.customer_name, o.status, o.total_amount, o.created_by, o.folio_posted_at,
             TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS age_min";
    $openNow = static function () use ($db, $cols, $openSql, $scope, $scopeArgs, $n): array {
        $st = $db->prepare("SELECT $cols FROM stock_orders o WHERE $openSql$scope ORDER BY o.created_at DESC LIMIT " . ($n + 1));
        $st->execute($scopeArgs);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $recent = "($openSql OR o.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR))";
    if ($ref !== '') {
        $where = '(o.reference = ? OR o.invoice_number = ? OR o.reference LIKE ?)';
        $args = [$ref, $ref, '%' . rh_ai_like($ref)];
        $asked = 'order ' . $ref;
    } elseif ($table !== '') {
        [$w, $p] = rh_ai_unit_where('o.table_number', $table);
        $where = "$w AND $recent";
        $args = array_merge($p, [$hours]);
        $asked = 'table ' . $table;
    } elseif ($room !== '') {
        [$w, $p] = rh_ai_unit_where('o.room_number', $room);
        $where = "$w AND $recent";
        $args = array_merge($p, [$hours]);
        $asked = 'room ' . $room;
    } elseif ($name !== '') {
        $where = "o.customer_name LIKE ? AND $recent";
        $args = ['%' . rh_ai_like($name) . '%', $hours];
        $asked = 'that name';
    } else {
        $rows = $openNow();
    }
    if (isset($where)) {
        $st = $db->prepare("SELECT $cols FROM stock_orders o WHERE $where$scope ORDER BY ($openSql) DESC, o.created_at DESC LIMIT " . ($n + 1));
        $st->execute(array_merge($args, $scopeArgs));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    // Items and money paid so far for the orders found: one query each (at most ai_lookup_matches + 1 orders)
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $items = [];
    $paidSoFar = [];
    if ($ids) {
        $st = $db->prepare("SELECT soi.order_id, soi.item_name, soi.quantity, COALESCE(NULLIF(soi.station, ''), 'kitchen') AS station, soi.kds_status,
                                   CASE WHEN soi.ready_at IS NULL THEN NULL ELSE TIMESTAMPDIFF(MINUTE, soi.ready_at, NOW()) END AS ready_min
                            FROM stock_order_items soi WHERE soi.order_id IN (" . rh_ai_in($ids) . ") ORDER BY soi.order_id, soi.id LIMIT 500");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $items[(int)$i['order_id']][] = $i;
        }
        try {
            $st = $db->prepare("SELECT order_id, COALESCE(SUM(split_amount), 0) FROM stock_order_splits WHERE order_id IN (" . rh_ai_in($ids) . ") GROUP BY order_id");
            $st->execute($ids);
            $paidSoFar = array_map('floatval', $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: []);
        } catch (Throwable $e) {
            // no split payments table: nothing part-paid
        }
    }
    $summary = static function (array $o) use ($items, $paidSoFar): array {
        $its = $items[(int)$o['id']] ?? [];
        [$state, $kitchen, $readyMin] = rh_ai_order_state($o, $its);
        $open = in_array((string)$o['status'], rh_ai_order_open_statuses(), true);
        $total = rh_ai_money((float)$o['total_amount']);
        $paid = rh_ai_money((float)($paidSoFar[(int)$o['id']] ?? 0));
        $onFolio = (string)$o['order_type'] === 'room_service' && !empty($o['folio_posted_at']);
        $payment = in_array((string)$o['status'], ['paid', 'completed'], true) ? 'paid' : ($onFolio ? 'charged to the room bill'
            : ($open ? ($paid > BALANCE_TOLERANCE ? 'part paid' : 'not paid yet') : (string)$o['status']));
        $out = ['order_ref' => (string)$o['reference'], 'type' => str_replace('_', ' ', (string)$o['order_type']),
            'table' => (string)($o['table_number'] ?? ''), 'room' => (string)($o['room_number'] ?? ''), 'guest_name' => (string)($o['customer_name'] ?? ''),
            'state' => $state, 'payment' => $payment, 'total' => $total, 'minutes_since_order' => (int)$o['age_min']];
        if ($kitchen !== null && $kitchen !== $state) {
            $out['kitchen'] = $kitchen;
        }
        if ($readyMin !== null) {
            $out['minutes_since_ready'] = $readyMin;
        }
        if ($open && !$onFolio) {
            if ($paid > BALANCE_TOLERANCE) {
                $out['paid_so_far'] = $paid;
            }
            $out['balance_left'] = rh_ai_money(max(0.0, $total - $paid));
        }
        return $out;
    };

    if (!isset($where)) { // nothing asked for: the orders open now
        $list = array_map($summary, array_slice($rows, 0, $n));
        return $withLink($base + ['match' => 'open orders', 'open_now' => $list ?: 'No orders are open right now.', 'more' => count($rows) > $n ? 'more than ' . $n . ' are open; ask for a table, room or name' : null], $link);
    }
    if (count($rows) === 1) {
        $o = $rows[0];
        $one = $summary($o);
        $its = $items[(int)$o['id']] ?? [];
        $byStation = [];
        foreach ($its as $i) {
            $s = (string)$i['station'];
            $w = rh_ai_item_state((string)$i['kds_status']);
            $byStation[$s][$w] = ($byStation[$s][$w] ?? 0) + 1;
        }
        foreach ($byStation as $s => $c) {
            $parts = [];
            foreach ($c as $w => $k) {
                $parts[] = $k . ' ' . $w;
            }
            $one['stations'][str_replace('_', ' ', $s)] = implode(', ', $parts);
        }
        $one['items'] = array_map(static fn($i) => ['item' => rh_ai_plain((string)$i['item_name'], 60), 'qty' => round((float)$i['quantity'], 2), 'station' => str_replace('_', ' ', (string)$i['station']), 'state' => rh_ai_item_state((string)$i['kds_status'])], $its);
        if ((string)$o['invoice_number'] !== '') {
            $one['receipt_no'] = (string)$o['invoice_number'];
        }
        $names = rh_ai_staff_names($db, [(int)$o['created_by']]);
        $one['opened_by'] = ['staff_name' => (string)($names[(int)$o['created_by']] ?? 'not recorded')];
        $ol = rh_ai_deep_link($user, 'order-lifecycle.php?id=' . (int)$o['id'], 'Open the order') ?? $link;
        return $withLink($base + ['match' => 'one', 'order' => $one, 'changes' => 'Voids, payments and changes are made on the POS screen.'], $ol);
    }
    if ($rows) {
        return $withLink($base + ['match' => 'several', 'asked' => $asked, 'orders' => array_map($summary, array_slice($rows, 0, $n)),
            'more' => count($rows) > $n ? 'more than ' . $n . ' match; ask with the order reference' : null, 'ask' => 'Several orders match: ask which one.'], $link);
    }
    // None: say so, then what IS there (the orders open now; for a room, its latest orders)
    $out = $base + ['match' => 'none', 'asked' => $asked,
        'not_found' => 'No ' . ($ref !== '' ? 'order with that reference' : 'open order, or order in the last ' . $hours . ' hours, for ' . $asked) . ($all ? '' : ' among the orders this person opened') . '.'];
    $openRows = $openNow();
    $out['open_now'] = $openRows ? array_map(static fn($o) => ['order_ref' => (string)$o['reference'], 'table' => (string)($o['table_number'] ?? ''), 'room' => (string)($o['room_number'] ?? ''),
        'guest_name' => (string)($o['customer_name'] ?? ''), 'minutes_since_order' => (int)$o['age_min']], array_slice($openRows, 0, $n)) : 'No orders are open right now.';
    if ($room !== '') {
        [$w, $p] = rh_ai_unit_where('o.room_number', $room);
        $st = $db->prepare("SELECT o.reference, o.status, o.total_amount, DATE(o.created_at) AS d FROM stock_orders o WHERE $w$scope ORDER BY o.created_at DESC LIMIT " . $n);
        $st->execute(array_merge($p, $scopeArgs));
        $prev = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($prev) {
            $out['earlier_orders_for_room'] = array_map(static fn($o) => ['order_ref' => (string)$o['reference'], 'status' => (string)$o['status'], 'total' => rh_ai_money((float)$o['total_amount']), 'date' => $o['d']], $prev);
        }
    }
    return $withLink($out, $link);
}

// ── Gym: one member, one class or slot ───────────────────────────────────────

/** A clock time from the question ("6am", "06:00", "6:30 pm") as HH:MM, or ''. */
function rh_ai_hhmm(string $t): string
{
    $t = trim(mb_strtolower($t));
    if ($t === '' || !preg_match('/^(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?$/u', $t, $m)) {
        return '';
    }
    $h = (int)$m[1];
    $min = (int)($m[2] ?? 0);
    $ap = str_replace('.', '', (string)($m[3] ?? ''));
    if ($ap === 'pm' && $h < 12) {
        $h += 12;
    } elseif ($ap === 'am' && $h === 12) {
        $h = 0;
    }
    return $h <= 23 && $min <= 59 ? sprintf('%02d:%02d', $h, $min) : '';
}

/** Does a class's day label fall on this date? The gym schedule's rule ("Monday", "Mon & Wed", "Daily", blank) plus a "Monday - Friday" range. */
function rh_ai_gym_class_on(string $dayLabel, string $date): bool
{
    $weekday = date('l', strtotime($date));
    return $dayLabel === '' || stripos($dayLabel, $weekday) !== false || stripos($dayLabel, substr($weekday, 0, 3)) !== false
        || stripos($dayLabel, 'daily') !== false || stripos($dayLabel, 'everyday') !== false
        || (preg_match('/\b(mon|monday)\s*(-|–|to)\s*(fri|friday)\b/i', $dayLabel) && (int)date('N', strtotime($date)) <= 5);
}

/** The hour slot a class time label belongs to ("6:30 AM" -> "06:00"), as the gym schedule files it; '' when unreadable. */
function rh_ai_gym_class_hour(string $timeLabel): string
{
    if (!preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $timeLabel, $m)) {
        return '';
    }
    $hm = rh_ai_hhmm($m[1] . (isset($m[2]) && $m[2] !== '' ? ':' . $m[2] : '') . (isset($m[3]) ? ' ' . $m[3] : ''));
    return $hm !== '' ? substr($hm, 0, 2) . ':00' : '';
}

/** One gym member by member number, name or phone: package, status, dates, days left, balance, check-ins, classes. */
function rh_ai_gym_member_lookup(PDO $db, array $user, string $q): array
{
    $n = rh_ai_num('ai_lookup_matches');
    $q = trim($q);
    $digits = (string)preg_replace('/\D/', '', $q);
    $phone = strlen($digits) >= 6 ? '%' . substr($digits, -9) . '%' : null;
    $sql = "SELECT id, member_number, full_name, membership_type, start_date, expiry_date, status, is_complimentary, gym_inquiry_id,
                   CASE WHEN expiry_date IS NULL THEN NULL ELSE DATEDIFF(expiry_date, CURDATE()) END AS days_left
            FROM gym_members WHERE member_number = ? OR full_name LIKE ?" . ($phone !== null ? " OR REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', '') LIKE ?" : '') . "
            ORDER BY (member_number = ?) DESC, (status = 'active') DESC, full_name LIMIT " . ($n + 1);
    $args = [$q, '%' . rh_ai_like($q) . '%'];
    if ($phone !== null) {
        $args[] = $phone;
    }
    $args[] = $q;
    $st = $db->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $link = rh_ai_deep_link($user, 'gym-members.php', 'Open Members');
    $out = ['currency' => rh_ai_currency()];
    if ($link) {
        $out['link'] = $link['url'];
        $out['link_label'] = $link['label'];
    }
    $line = static function (array $m): array {
        $ended = $m['expiry_date'] !== null && (int)$m['days_left'] < 0;
        return ['member_number' => (string)$m['member_number'], 'guest_name' => (string)$m['full_name'], 'membership' => (string)($m['membership_type'] ?? ''),
            'status' => $m['status'] === 'active' && $ended ? 'expired (end date passed)' : (string)$m['status'],
            'starts' => $m['start_date'], 'ends' => $m['expiry_date'] ?? 'no end date',
            'days_left' => $m['expiry_date'] === null ? null : max(0, (int)$m['days_left']), 'complimentary' => (int)$m['is_complimentary'] === 1 ? true : null];
    };
    if (!$rows) {
        // Nearest names: any word of what was asked (3+ letters), or a name that sounds the same
        $words = array_values(array_filter(preg_split('/\s+/u', $q), static fn($w) => mb_strlen($w) >= 3));
        $near = [];
        if ($words) {
            $conds = implode(' OR ', array_fill(0, count($words), 'full_name LIKE ?'));
            $st = $db->prepare("SELECT member_number, full_name, membership_type, status, expiry_date FROM gym_members
                                WHERE $conds OR SOUNDEX(SUBSTRING_INDEX(full_name, ' ', 1)) = SOUNDEX(?) ORDER BY (status = 'active') DESC, full_name LIMIT " . $n);
            $st->execute(array_merge(array_map(static fn($w) => '%' . rh_ai_like($w) . '%', $words), [$words[0]]));
            $near = array_map(static fn($m) => ['member_number' => (string)$m['member_number'], 'guest_name' => (string)$m['full_name'], 'membership' => (string)($m['membership_type'] ?? ''), 'status' => (string)$m['status'], 'ends' => $m['expiry_date']], $st->fetchAll(PDO::FETCH_ASSOC));
        }
        return $out + ['match' => 'none', 'not_found' => 'No gym member matches that name, phone or member number.', 'nearest_matches' => $near ?: 'none close'];
    }
    if (count($rows) > 1) {
        return $out + ['match' => 'several', 'members' => array_map($line, array_slice($rows, 0, $n)), 'more' => count($rows) > $n ? 'more than ' . $n . ' match; ask with the member number' : null, 'ask' => 'Several members match: ask which one.'];
    }
    $m = $rows[0];
    $one = $line($m);
    try {
        $st = $db->prepare("SELECT name, duration_label, price, is_complimentary FROM gym_packages WHERE name = ? LIMIT 1");
        $st->execute([(string)$m['membership_type']]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            $one['package'] = rh_ai_plain((string)$p['name'], 80) . ((string)$p['duration_label'] !== '' ? ' (' . rh_ai_plain((string)$p['duration_label'], 40) . ')' : '')
                . ((int)$p['is_complimentary'] === 1 ? ', complimentary' : ((float)$p['price'] > BALANCE_TOLERANCE ? ', ' . number_format((float)$p['price'], 2) : ''));
        }
    } catch (Throwable $e) {
        // no packages table
    }
    // Balance owed: the member's live bills (enrolment account + desk renewals tied by the [member:NUMBER] marker), the
    // same rule as Gym Reports' outstanding balances. Shown to people who see gym money; others are pointed to the screen.
    if (rh_ai_perm_any($user, ['gym_financials', 'gym_reports', 'accounting', 'payments'])) {
        try {
            $st = $db->prepare("SELECT COALESCE(SUM(amount_due), 0) FROM gym_inquiries WHERE status NOT IN ('cancelled') AND amount_due > 0 AND (id = ? OR notes LIKE ?)");
            $st->execute([(int)$m['gym_inquiry_id'], '%' . rh_ai_like('[member:' . $m['member_number'] . ']') . '%']);
            $one['balance_owed'] = rh_ai_money((float)$st->fetchColumn());
        } catch (Throwable $e) {
            $one['balance_owed'] = 'could not be read';
        }
    } else {
        $one['balance_owed'] = 'not shown to this account (gym invoicing or reports access needed)';
    }
    try {
        $st = $db->prepare("SELECT checked_in_at, checked_out_at FROM gym_attendance WHERE member_id = ? ORDER BY checked_in_at DESC LIMIT " . $n);
        $st->execute([(int)$m['id']]);
        $one['last_check_ins'] = array_map(static fn($r) => (string)$r['checked_in_at'] . ($r['checked_out_at'] ? ' to ' . substr((string)$r['checked_out_at'], 11, 5) : ' (still in)'), $st->fetchAll(PDO::FETCH_ASSOC)) ?: 'none';
    } catch (Throwable $e) {
        // no check-in table yet
    }
    try {
        $st = $db->prepare("SELECT c.title, c.day_label, c.time_label FROM gym_class_enrollments e INNER JOIN gym_classes c ON c.id = e.class_id
                            WHERE e.member_id = ? AND e.status = 'enrolled' AND c.is_active = 1 ORDER BY c.display_order, c.title LIMIT " . $n);
        $st->execute([(int)$m['id']]);
        $one['classes'] = array_map(static fn($c) => rh_ai_plain((string)$c['title'], 60) . ' — ' . rh_ai_plain(trim($c['day_label'] . ' ' . $c['time_label']), 60), $st->fetchAll(PDO::FETCH_ASSOC)) ?: 'none';
    } catch (Throwable $e) {
        // no classes table yet
    }
    try {
        $st = $db->prepare("SELECT slot_date, slot_time, party_size FROM gym_slot_reservations WHERE (member_id = ? OR member_number = ?) AND slot_date >= CURDATE() AND status = 'booked'
                            ORDER BY slot_date, slot_time LIMIT " . $n);
        $st->execute([(int)$m['id'], (string)$m['member_number']]);
        $slots = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($slots) {
            $one['booked_slots'] = array_map(static fn($r) => $r['slot_date'] . ' ' . rh_ai_clock((string)$r['slot_time']) . ((int)$r['party_size'] > 1 ? ' (' . (int)$r['party_size'] . ' people)' : ''), $slots);
        }
    } catch (Throwable $e) {
        // no slot calendar
    }
    return $out + ['match' => 'one', 'member' => $one];
}

/**
 * A gym class or time slot on a date: the class (day and time, enrolled members) and, when slot booking is on, the
 * slot at that time (capacity, booked, spaces left, who is booked). Nothing at that time / class not found / slot full:
 * says so and offers the next slots with space (ai_alt_days days ahead, at most ai_alt_max) and the other classes.
 */
function rh_ai_gym_class_lookup(PDO $db, array $user, array $a): array
{
    $n = rh_ai_num('ai_lookup_matches');
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($a['date'] ?? '')) ? (string)$a['date'] : rh_ai_today();
    $time = rh_ai_hhmm((string)($a['time'] ?? ''));
    $class = trim((string)($a['class'] ?? ''));
    $hasSched = rh_ai_admin_lib('gym-schedule-lib.php') && function_exists('gymScheduleSettings');
    $cfg = $hasSched ? gymScheduleSettings() : ['enabled' => false];
    $out = ['date' => $date, 'day' => date('l', strtotime($date))];
    $link = rh_ai_deep_link($user, 'gym-schedule.php?date=' . $date, 'Open the gym schedule') ?? rh_ai_deep_link($user, 'gym-classes.php', 'Open Classes');
    if ($link) {
        $out['link'] = $link['url'];
        $out['link_label'] = $link['label'];
    }
    // Classes: by name, or the ones on that day (at that hour when a time is given). One query for the active classes.
    $all = [];
    try {
        $all = $db->query("SELECT id, title, day_label, time_label, level_label FROM gym_classes WHERE is_active = 1 ORDER BY display_order, title LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // no classes table
    }
    $hourKey = $time !== '' ? substr($time, 0, 2) . ':00' : '';
    $match = [];
    foreach ($all as $c) {
        if ($class !== '') {
            if (mb_stripos((string)$c['title'], $class) !== false || mb_stripos($class, (string)$c['title']) !== false) {
                $match[] = $c;
            }
        } elseif (rh_ai_gym_class_on((string)$c['day_label'], $date) && ($hourKey === '' || rh_ai_gym_class_hour((string)$c['time_label']) === $hourKey)) {
            $match[] = $c;
        }
    }
    $match = array_slice($match, 0, $n);
    if ($match) {
        $ids = array_map(static fn($c) => (int)$c['id'], $match);
        $counts = [];
        $names = [];
        try {
            $st = $db->prepare("SELECT e.class_id, COUNT(*) FROM gym_class_enrollments e WHERE e.status = 'enrolled' AND e.class_id IN (" . rh_ai_in($ids) . ") GROUP BY e.class_id");
            $st->execute($ids);
            $counts = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $st = $db->prepare("SELECT e.class_id, m.full_name FROM gym_class_enrollments e INNER JOIN gym_members m ON m.id = e.member_id
                                WHERE e.status = 'enrolled' AND e.class_id IN (" . rh_ai_in($ids) . ") ORDER BY m.full_name LIMIT 500");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $names[(int)$r['class_id']][] = ['guest_name' => (string)$r['full_name']];
            }
        } catch (Throwable $e) {
            // no enrolments table
        }
        $out['classes'] = array_map(static fn($c) => ['class' => rh_ai_plain((string)$c['title'], 60), 'days' => rh_ai_plain((string)$c['day_label'], 60), 'time' => rh_ai_plain((string)$c['time_label'], 30),
            'level' => rh_ai_plain((string)$c['level_label'], 30), 'on_this_day' => rh_ai_gym_class_on((string)$c['day_label'], $date), 'enrolled' => (int)($counts[(int)$c['id']] ?? 0),
            'enrolled_members' => $names[(int)$c['id']] ?? []], $match);
        $out['class_capacity'] = 'Classes have no capacity set; the slot below (when slot booking is on) is the gym floor\'s limit.';
        if ($time === '' && count($match) === 1) {
            $hourKey = rh_ai_gym_class_hour((string)$match[0]['time_label']);
        }
    } elseif ($class !== '') {
        $out['class_not_found'] = 'No active class is called that.';
        $out['other_classes'] = array_map(static fn($c) => rh_ai_plain((string)$c['title'], 60) . ' — ' . rh_ai_plain(trim($c['day_label'] . ' ' . $c['time_label']), 60), array_slice($all, 0, $n)) ?: 'no classes set up';
    } elseif ($hourKey !== '') {
        $out['no_class'] = 'No class at that time on ' . date('l', strtotime($date)) . '.';
    }
    if (!$cfg['enabled']) {
        $out['slot_booking'] = 'Slot booking is switched off in gym settings, so there are no booked slots or slot spaces.';
        if (!$match && $class === '') {
            $out['classes_that_day'] = array_map(static fn($c) => rh_ai_plain((string)$c['title'], 60) . ' — ' . rh_ai_plain((string)$c['time_label'], 30),
                array_slice(array_values(array_filter($all, static fn($c) => rh_ai_gym_class_on((string)$c['day_label'], $date))), 0, $n)) ?: 'none';
        }
        return $out;
    }
    $slots = gymScheduleGenerateSlots($db, $date);
    $want = $time !== '' ? $time : $hourKey;
    $slot = null;
    foreach ($slots as $s) {
        if ($want !== '' && $s['time'] <= $want && strtotime($date . ' ' . $want) < strtotime($date . ' ' . $s['time']) + (int)$cfg['duration'] * 60) {
            $slot = $s;
            break;
        }
    }
    if ($want === '') { // the whole day: the slots with bookings, and the day's totals
        $booked = array_values(array_filter($slots, static fn($s) => (int)$s['booked'] > 0));
        $out['slots_with_bookings'] = array_map(static fn($s) => ['slot' => $s['label'], 'booked' => (int)$s['booked'], 'capacity' => (int)$s['capacity'], 'spaces_left' => (int)$s['remaining']], $booked) ?: 'no slots booked';
        $out['booked_today_total'] = array_sum(array_map(static fn($s) => (int)$s['booked'], $slots));
        $out['open'] = $slots ? 'open, ' . count($slots) . ' slots of ' . (int)$cfg['duration'] . ' minutes' : 'closed that day';
        return $out;
    }
    if ($slot !== null) {
        $who = [];
        try {
            $st = $db->prepare("SELECT full_name, party_size, status FROM gym_slot_reservations WHERE slot_date = ? AND slot_time = ? AND status <> 'cancelled' ORDER BY created_at LIMIT 200");
            $st->execute([$date, $slot['time'] . ':00']);
            $who = array_map(static fn($r) => ['guest_name' => (string)$r['full_name'], 'people' => (int)$r['party_size'], 'status' => str_replace('_', ' ', (string)$r['status'])], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            // no slot calendar
        }
        $out['slot'] = ['slot' => $slot['label'], 'capacity' => (int)$slot['capacity'], 'booked' => (int)$slot['booked'], 'spaces_left' => (int)$slot['remaining'], 'booked_people' => $who ?: 'nobody yet', 'past' => $slot['is_past'] ? true : null];
        if ((int)$slot['remaining'] > 0 && !$slot['is_past']) {
            return $out;
        }
        $out['slot_full'] = $slot['is_past'] ? 'That slot has passed.' : 'That slot is full.';
    } else {
        $out['no_slot'] = $slots ? 'The gym has no slot at that time on ' . date('l', strtotime($date)) . '.' : 'The gym is closed on ' . date('l', strtotime($date)) . '.';
    }
    // The next slots with space: from that time on, then the next ai_alt_days days (bounded work)
    $max = rh_ai_num('ai_alt_max');
    $next = [];
    for ($d = 0; $d <= rh_ai_num('ai_alt_days') && count($next) < $max; $d++) {
        $day = date('Y-m-d', strtotime($date . ' +' . $d . ' day'));
        foreach ($d === 0 ? $slots : gymScheduleGenerateSlots($db, $day) as $s) {
            if ((int)$s['remaining'] > 0 && !$s['is_past'] && ($d > 0 || $s['time'] > $want)) {
                $next[] = $day . ' ' . $s['label'] . ' (' . (int)$s['remaining'] . ' spaces)';
                if (count($next) >= $max) {
                    break;
                }
            }
        }
    }
    $out['next_slots_with_space'] = $next ?: 'none in the next ' . rh_ai_num('ai_alt_days') . ' days';
    return $out;
}

// ── Conference and events: one booking ───────────────────────────────────────

function rh_ai_events_lookup(PDO $db, array $user, string $q): array
{
    $n = rh_ai_num('ai_lookup_matches');
    $q = trim($q);
    $like = '%' . rh_ai_like($q) . '%';
    $found = [];
    $link = null;
    if (rh_ai_module_on('conference') && rh_ai_perm_any($user, ['conference'])) {
        require_once dirname(__DIR__) . '/conference-pricing.php';
        $gross = function_exists('rh_conf_gross_sql') ? rh_conf_gross_sql('ci') : 'ci.total_with_vat';
        try {
            $st = $db->prepare("SELECT ci.inquiry_reference, ci.company_name, ci.contact_person, ci.event_date, ci.end_date, ci.start_time, ci.end_time, ci.number_of_attendees, ci.event_type,
                                       ci.status, ci.payment_status, $gross AS gross, ci.amount_due, cr.name AS room_name
                                FROM conference_inquiries ci LEFT JOIN conference_rooms cr ON cr.id = ci.conference_room_id
                                WHERE ci.inquiry_reference = ? OR ci.company_name LIKE ? OR ci.contact_person LIKE ?
                                ORDER BY (ci.inquiry_reference = ?) DESC, (ci.event_date >= CURDATE()) DESC, ABS(DATEDIFF(ci.event_date, CURDATE())) LIMIT " . ($n + 1));
            $st->execute([$q, $like, $like, $q]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $found[] = ['kind' => 'conference booking', 'reference' => $r['inquiry_reference'], 'guest_name' => (string)$r['company_name'] !== '' ? $r['company_name'] : $r['contact_person'],
                    'contact_person' => $r['contact_person'], 'date' => $r['event_date'] . ($r['end_date'] && $r['end_date'] !== $r['event_date'] ? ' to ' . $r['end_date'] : ''),
                    'time' => $r['start_time'] ? rh_ai_clock((string)$r['start_time']) . ($r['end_time'] ? '–' . rh_ai_clock((string)$r['end_time']) : '') : null,
                    'room' => $r['room_name'], 'attendees' => (int)$r['number_of_attendees'], 'event_type' => $r['event_type'], 'status' => $r['status'],
                    'payment' => str_replace('_', ' ', (string)$r['payment_status']), 'total' => rh_ai_money((float)$r['gross']), 'still_owed' => rh_ai_money((float)$r['amount_due'])];
            }
            $link = ['conference-management.php', 'Open Conference'];
        } catch (Throwable $e) {
            // no conference table
        }
    }
    if (rh_ai_module_on('events') && rh_ai_perm_any($user, ['events_bookings', 'events'])) {
        try {
            $st = $db->prepare("SELECT ei.reference_number, ei.name, ei.guests, ei.status, ei.payment_status, ei.amount_due, e.title, e.event_date
                                FROM event_inquiries ei LEFT JOIN events e ON e.id = ei.event_id
                                WHERE ei.reference_number = ? OR ei.name LIKE ? ORDER BY (ei.reference_number = ?) DESC, ei.created_at DESC LIMIT " . ($n + 1));
            $st->execute([$q, $like, $q]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $found[] = ['kind' => 'event booking', 'reference' => $r['reference_number'], 'guest_name' => $r['name'], 'event' => rh_ai_plain((string)$r['title'], 80), 'date' => $r['event_date'],
                    'guests' => (int)$r['guests'], 'status' => (string)$r['status'] !== '' ? $r['status'] : 'new', 'payment' => str_replace('_', ' ', (string)$r['payment_status']), 'still_owed' => rh_ai_money((float)$r['amount_due'])];
            }
            $link = $link ?? ['events-inquiries.php', 'Open Event enquiries'];
        } catch (Throwable $e) {
            // no events table
        }
    }
    $out = ['currency' => rh_ai_currency()];
    if ($link) {
        $out['link'] = $link[0];
        $out['link_label'] = $link[1];
    }
    if (count($found) === 1) {
        return $out + ['match' => 'one', 'booking' => $found[0]];
    }
    if ($found) {
        return $out + ['match' => 'several', 'bookings' => array_slice($found, 0, $n), 'more' => count($found) > $n ? 'more than ' . $n . ' match; ask with the reference' : null, 'ask' => 'Several bookings match: ask which one.'];
    }
    return $out + ['match' => 'none', 'not_found' => 'No conference or event booking matches that reference or name.'];
}

// ── Housekeeping and maintenance: one room ───────────────────────────────────

function rh_ai_room_jobs_lookup(PDO $db, array $user, string $room): array
{
    $n = rh_ai_num('ai_lookup_matches');
    $room = trim((string)preg_replace('/^room\s*(no\.?|number|#)?\s*/i', '', $room));
    [$w, $p] = rh_ai_unit_where('room_number', $room);
    $st = $db->prepare("SELECT id, room_number, status, housekeeping_status, last_cleaned_at FROM individual_rooms WHERE is_active = 1 AND ($w OR room_name = ?) ORDER BY (room_number = ?) DESC LIMIT " . ($n + 1));
    $st->execute(array_merge($p, [$room, $room]));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return ['match' => 'none', 'not_found' => 'No room numbered ' . $room . '.'];
    }
    if (count($rows) > 1) {
        return ['match' => 'several', 'rooms' => array_map(static fn($r) => (string)$r['room_number'], array_slice($rows, 0, $n)), 'ask' => 'Several rooms match: ask which one.'];
    }
    $r = $rows[0];
    $out = ['match' => 'one', 'room' => (string)$r['room_number'], 'room_status' => str_replace('_', ' ', (string)$r['status']),
        'housekeeping_status' => str_replace('_', ' ', (string)($r['housekeeping_status'] ?? '')), 'last_cleaned' => $r['last_cleaned_at']];
    $ids = [];
    if (rh_ai_module_on('housekeeping') && rh_ai_perm_any($user, ['housekeeping'])) {
        $st = $db->prepare("SELECT status, priority, assignment_type, due_date, assigned_to FROM housekeeping_assignments
                            WHERE individual_room_id = ? AND status IN ('pending', 'in_progress', 'blocked') ORDER BY due_date IS NULL, due_date, FIELD(priority, 'high', 'medium', 'low') LIMIT " . $n);
        $st->execute([(int)$r['id']]);
        $hk = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($hk as $j) {
            $ids[] = (int)$j['assigned_to'];
        }
    }
    if (rh_ai_module_on('bookings') && rh_ai_perm_any($user, ['room_maintenance'])) {
        $st = $db->prepare("SELECT title, status, priority, due_date, block_room, assigned_to FROM room_maintenance_schedules
                            WHERE individual_room_id = ? AND status IN ('planned', 'in_progress') ORDER BY due_date IS NULL, due_date, FIELD(priority, 'urgent', 'high', 'medium', 'low') LIMIT " . $n);
        $st->execute([(int)$r['id']]);
        $mt = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mt as $j) {
            $ids[] = (int)$j['assigned_to'];
        }
    }
    $names = rh_ai_staff_names($db, $ids);
    if (isset($hk)) {
        $out['housekeeping_jobs'] = array_map(static fn($j) => ['job' => str_replace('_', ' ', (string)$j['assignment_type']), 'status' => str_replace('_', ' ', (string)$j['status']), 'priority' => $j['priority'], 'due' => $j['due_date'], 'staff_name' => (string)($names[(int)$j['assigned_to']] ?? '')], $hk) ?: 'none open';
    }
    if (isset($mt)) {
        $out['maintenance_tasks'] = array_map(static fn($j) => ['task' => rh_ai_plain((string)$j['title'], 80), 'status' => str_replace('_', ' ', (string)$j['status']), 'priority' => $j['priority'], 'due' => $j['due_date'], 'blocks_room' => (int)$j['block_room'] === 1, 'staff_name' => (string)($names[(int)$j['assigned_to']] ?? '')], $mt) ?: 'none open';
    }
    return $out;
}

// ── Stock: one item, one purchase order ──────────────────────────────────────

/** Level word by the stock dashboard rule (out / negative / low at or under the reorder level / ok). */
function rh_ai_stock_level_word(float $qty, float $thr): string
{
    if ($qty < -0.0001) {
        return 'negative (a count is needed)';
    }
    if ($qty <= 0.0001) {
        return 'out';
    }
    return $thr > 0 && $qty <= $thr ? 'low' : 'ok';
}

function rh_ai_stock_item_lookup(PDO $db, array $user, string $q): array
{
    $n = rh_ai_num('ai_lookup_matches');
    $q = trim($q);
    $thr = function_exists('rh_stock_threshold_sql') ? rh_stock_threshold_sql('i') : 'i.min_quantity';
    $st = $db->prepare("SELECT i.id, i.name, i.unit, i.current_quantity, $thr AS threshold, i.par_level FROM stock_ingredients i
                        WHERE i.is_archived = 0 AND i.name LIKE ? ORDER BY (i.name = ?) DESC, i.name LIMIT " . ($n + 1));
    $st->execute(['%' . rh_ai_like($q) . '%', $q]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $line = static fn($i) => ['item' => rh_ai_plain((string)$i['name'], 80), 'unit' => (string)$i['unit'], 'quantity' => round((float)$i['current_quantity'], 2),
        'level' => rh_ai_stock_level_word((float)$i['current_quantity'], (float)$i['threshold']), 'reorder_at' => (float)$i['threshold'] > 0 ? round((float)$i['threshold'], 2) : null];
    if (!$rows) {
        $words = array_values(array_filter(preg_split('/\s+/u', $q), static fn($w) => mb_strlen($w) >= 3));
        $near = [];
        if ($words) {
            $conds = implode(' OR ', array_fill(0, count($words), 'i.name LIKE ?'));
            $st = $db->prepare("SELECT i.id, i.name, i.unit, i.current_quantity, $thr AS threshold FROM stock_ingredients i WHERE i.is_archived = 0 AND ($conds) ORDER BY i.name LIMIT " . $n);
            $st->execute(array_map(static fn($w) => '%' . rh_ai_like($w) . '%', $words));
            $near = array_map($line, $st->fetchAll(PDO::FETCH_ASSOC));
        }
        return ['match' => 'none', 'not_found' => 'No stock item is called that.', 'nearest_items' => $near ?: 'none close'];
    }
    if (count($rows) > 1) {
        return ['match' => 'several', 'items' => array_map($line, array_slice($rows, 0, $n)), 'more' => count($rows) > $n ? 'more than ' . $n . ' match; ask with the full name' : null, 'ask' => 'Several items match: ask which one.'];
    }
    $i = $rows[0];
    $one = $line($i);
    if ((float)$i['par_level'] > 0) {
        $one['par_level'] = round((float)$i['par_level'], 2);
    }
    try {
        $st = $db->prepare("SELECT batch_number, quantity_remaining, expiry_date FROM stock_batches WHERE ingredient_id = ? AND status = 'active' AND quantity_remaining > 0
                            ORDER BY expiry_date IS NULL, expiry_date LIMIT " . $n);
        $st->execute([(int)$i['id']]);
        $b = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($b) {
            $one['batches'] = array_map(static fn($r) => ['batch' => (string)$r['batch_number'], 'quantity_left' => round((float)$r['quantity_remaining'], 2), 'expires' => $r['expiry_date'] ?? 'no expiry'], $b);
        }
    } catch (Throwable $e) {
        // no batches table
    }
    if (rh_ai_perm_any($user, ['stock_management'])) {
        try {
            $st = $db->prepare("SELECT po.reference, po.status, po.expected_date, pi.ordered_qty, pi.received_qty, pi.unit FROM stock_purchase_order_items pi
                                INNER JOIN stock_purchase_orders po ON po.id = pi.purchase_order_id WHERE pi.ingredient_id = ? AND po.status IN ('sent', 'partial') ORDER BY po.expected_date LIMIT " . $n);
            $st->execute([(int)$i['id']]);
            $po = $st->fetchAll(PDO::FETCH_ASSOC);
            $one['on_order'] = array_map(static fn($r) => ['po_ref' => (string)$r['reference'], 'status' => (string)$r['status'], 'expected' => $r['expected_date'], 'ordered' => round((float)$r['ordered_qty'], 2), 'received' => round((float)$r['received_qty'], 2), 'unit' => (string)$r['unit']], $po) ?: 'nothing on order';
        } catch (Throwable $e) {
            // no purchase orders
        }
    }
    return ['match' => 'one', 'item' => $one];
}

function rh_ai_purchase_order_lookup(PDO $db, array $user, string $q): array
{
    if (!rh_ai_perm_any($user, ['stock_management'])) {
        return ['error' => "Purchase orders are outside what this account can see."];
    }
    $n = rh_ai_num('ai_lookup_matches');
    $q = trim($q);
    $num = (string)preg_replace('/^po(?=[\s\-#]*\d)[\s\-#]*/i', '', $q);
    [$w, $p] = rh_ai_unit_where('po.reference', $num);
    $cols = "po.id, po.reference, po.status, po.order_date, po.expected_date, po.total_cost, s.name AS supplier";
    $st = $db->prepare("SELECT $cols FROM stock_purchase_orders po LEFT JOIN stock_suppliers s ON s.id = po.supplier_id
                        WHERE po.reference = ? OR $w OR s.name LIKE ?
                        ORDER BY (po.reference = ?) DESC, FIELD(po.status, 'sent', 'partial', 'draft', 'received', 'closed', 'cancelled'), po.id DESC LIMIT " . ($n + 1));
    $st->execute(array_merge([$q], $p, ['%' . rh_ai_like($q) . '%', $q]));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $today = rh_ai_today();
    $line = static fn($r) => ['po_ref' => (string)$r['reference'], 'supplier' => rh_ai_plain((string)($r['supplier'] ?? ''), 80), 'status' => (string)$r['status'], 'ordered_on' => $r['order_date'],
        'expected' => $r['expected_date'], 'overdue' => in_array($r['status'], ['sent', 'partial'], true) && $r['expected_date'] !== null && $r['expected_date'] < $today ? true : null, 'total_cost' => rh_ai_money((float)$r['total_cost'])];
    $out = ['currency' => rh_ai_currency()];
    if (!$rows) {
        $open = $db->query("SELECT $cols FROM stock_purchase_orders po LEFT JOIN stock_suppliers s ON s.id = po.supplier_id WHERE po.status IN ('sent', 'partial') ORDER BY po.expected_date IS NULL, po.expected_date LIMIT " . $n)->fetchAll(PDO::FETCH_ASSOC);
        return $out + ['match' => 'none', 'not_found' => 'No purchase order matches that reference or supplier.', 'open_purchase_orders' => array_map($line, $open) ?: 'none waiting for delivery',
            'link' => 'purchase-orders.php', 'link_label' => 'Open Purchase orders'];
    }
    if (count($rows) > 1) {
        return $out + ['match' => 'several', 'purchase_orders' => array_map($line, array_slice($rows, 0, $n)), 'more' => count($rows) > $n ? 'more than ' . $n . ' match; ask with the reference' : null,
            'ask' => 'Several purchase orders match: ask which one.', 'link' => 'purchase-orders.php', 'link_label' => 'Open Purchase orders'];
    }
    $r = $rows[0];
    $one = $line($r);
    $st = $db->prepare("SELECT COALESCE(i.name, pi.description) AS item, pi.unit, pi.ordered_qty, pi.received_qty FROM stock_purchase_order_items pi
                        LEFT JOIN stock_ingredients i ON i.id = pi.ingredient_id WHERE pi.purchase_order_id = ? ORDER BY pi.id LIMIT 200");
    $st->execute([(int)$r['id']]);
    $one['lines'] = array_map(static fn($l) => ['item' => rh_ai_plain((string)$l['item'], 80), 'unit' => (string)$l['unit'], 'ordered' => round((float)$l['ordered_qty'], 2), 'received' => round((float)$l['received_qty'], 2)], $st->fetchAll(PDO::FETCH_ASSOC));
    return $out + ['match' => 'one', 'purchase_order' => $one, 'link' => 'purchase-orders.php?id=' . (int)$r['id'], 'link_label' => 'Open the purchase order'];
}
