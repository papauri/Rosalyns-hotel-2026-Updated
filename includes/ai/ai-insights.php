<?php

/**
 * Assistant Phase 3 (.claude/AI_ASSISTANT_PLAN.md "The swarm" + "Owner direction"): the department read
 * tools the specialists use, and the role-shaped daily briefing ("the full tea").
 *
 * READ ONLY. Every query is a prepared statement or a fixed string; nothing here writes anything. Figures
 * come from the same helpers / SQL the screens use:
 *   room_performance  admin/reports.php Occupancy + KPI block (rh_occupancy_pct, rh_adr, rh_sellable_room_units,
 *                     rh_booking_gross_sql, rh_booking_rooms_factor_sql, rh_cancellations_between)
 *   money_leaks       admin/includes/receivables.php (rh_receivable_summary), the End of Day refund basis,
 *                     admin/includes/pos-shift-totals.php void basis, stock wastage / count tables
 *   restaurant_sales  rh_pos_eod_summary() (End of Day POS block), summed day by day
 *   inventory_watch   rh_stock_alert_summary() (stock dashboard rule)
 *   gym_watch         admin/gym-members.php filter counts
 *   guest_voice       admin/reviews.php "Needs a reply" rule, contact-inquiries.php statuses
 * Each tool is gated in the registry (ai-tools.php) by permission + module; parts inside a tool that need a
 * further permission or module check it again here. Names, emails, phones and references are returned in
 * fields the vault tokenises (guest_name, staff_name, reference ...) so nothing personal leaves the hotel.
 *
 * The briefing finders (rh_ai_briefing_findings) are deterministic: they call these tools and rank what
 * they find with ZERO model tokens; only the ranked compact findings go to the model to be explained.
 */

if (!defined('RH_AI_SLOW_TICKET_MINUTES')) {
    define('RH_AI_SLOW_TICKET_MINUTES', 20); // ASSUMPTION: no lateness rule exists on the KDS; 20 minutes order-to-ready
}
if (!defined('RH_AI_BRIEF_MAX')) {
    define('RH_AI_BRIEF_MAX', 8);
}

// ── Shared helpers ───────────────────────────────────────────────────────────

/** A date range from tool arguments: [from, to] or [null, error]. Defaults given; at most $maxDays long. */
function rh_ai_range(array $a, string $defFrom, string $defTo, int $maxDays = 366): array
{
    $from = (string)($a['from'] ?? $defFrom);
    $to = (string)($a['to'] ?? $defTo);
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
    if ($days > $maxDays) {
        return [null, 'That range is too long; ask for ' . $maxDays . ' days or fewer.'];
    }
    return [$from, $to];
}

function rh_ai_admin_lib(string $file): bool
{
    $p = dirname(__DIR__, 2) . '/admin/includes/' . $file;
    if (!is_file($p)) {
        return false;
    }
    require_once $p;
    return true;
}

/** Staff full names by id (for "who"), one query. */
function rh_ai_staff_names(PDO $db, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    try {
        $st = $db->prepare('SELECT id, COALESCE(NULLIF(full_name, \'\'), username) FROM admin_users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** A deep link to an admin screen, only when this person may open it (else null). */
function rh_ai_deep_link(array $user, string $url, string $label): ?array
{
    $page = (string)strtok($url, '?');
    if (!preg_match('/^[a-z0-9\-_]+\.php$/i', $page) || !function_exists('rhCanOpenPage') || !rhCanOpenPage((int)$user['id'], $page)) {
        return null;
    }
    return ['label' => $label, 'url' => $url];
}

function rh_ai_perm_any(array $user, array $perms): bool
{
    foreach ($perms as $p) {
        if (hasPermission((int)$user['id'], $p)) {
            return true;
        }
    }
    return false;
}

function rh_ai_mod(string $m): bool
{
    return function_exists('rh_module_key_enabled') ? rh_module_key_enabled($m) : false;
}

// ── Revenue & Sales ──────────────────────────────────────────────────────────

/**
 * Occupancy, ADR, RevPAR, cancellations and no-shows for a range - the same SQL, in the same order, as the
 * Reports screen (admin/reports.php "OCCUPANCY TAB QUERIES" and "ADVANCED HOTEL KPI METRICS"), so the
 * figures equal the screen's for the same dates. Default range = this month (the screen's default).
 */
function rh_ai_t_room_performance(PDO $db, array $user, array $a): array
{
    if (!rh_ai_mod('bookings')) {
        return ['error' => 'Room bookings are switched off here.'];
    }
    [$start_date, $end_date] = rh_ai_range($a, date('Y-m-01'), date('Y-m-t'));
    if ($start_date === null) {
        return ['error' => $end_date];
    }
    $nightsSql = static fn(string $al, string $k): string => "GREATEST(0, DATEDIFF(LEAST({$al}.check_out_date, DATE_ADD(:{$k}e, INTERVAL 1 DAY)), GREATEST({$al}.check_in_date, :{$k}s)))";
    $par = static fn(string $k, string $s, string $e): array => [":{$k}s" => $s, ":{$k}e" => $e];
    $factor = rh_booking_rooms_factor_sql('b');
    $revSql = static fn(string $al, string $k): string => '(' . rh_booking_gross_sql($al) . ' * ' . $nightsSql($al, $k) . " / NULLIF({$al}.number_of_nights, 0))";

    $st = $db->prepare("SELECT COUNT(*) AS total FROM bookings WHERE deleted_at IS NULL AND created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)");
    $st->execute([$start_date, $end_date]);
    $made = (int)$st->fetchColumn();
    $cancelled = rh_cancellations_between($db, $start_date, $end_date);
    $cancelRate = $made > 0 ? min(100, round(($cancelled / $made) * 100, 1)) : 0;

    $daysInPeriod = max(1, (strtotime($end_date) - strtotime($start_date)) / 86400 + 1);
    $st = $db->prepare("SELECT COUNT(DISTINCT b.id) AS total_bookings, COALESCE(SUM(" . $nightsSql('b', 'o2') . " * " . $factor . "), 0) AS total_nights_booked
                        FROM bookings b WHERE b.deleted_at IS NULL AND b.check_in_date <= :oe AND b.check_out_date > :os
                        AND b.status IN ('confirmed', 'checked-in', 'checked-out')");
    $st->execute([':oe' => $end_date, ':os' => $start_date] + $par('o2', $start_date, $end_date));
    $occ = $st->fetch(PDO::FETCH_ASSOC) ?: ['total_bookings' => 0, 'total_nights_booked' => 0];
    $inventory = rh_sellable_room_units();
    $available = $inventory * $daysInPeriod;
    $occPct = rh_occupancy_pct((float)$occ['total_nights_booked'], (float)$available);

    $st = $db->prepare("SELECT COALESCE(SUM(" . $revSql('b', 'a1') . "), 0) AS total_room_revenue,
                               COALESCE(SUM(" . $nightsSql('b', 'a2') . " * " . $factor . "), 0) AS total_nights_sold
                        FROM bookings b WHERE b.deleted_at IS NULL AND b.status IN ('confirmed', 'checked-in', 'checked-out')
                        AND b.check_in_date <= :ae AND b.check_out_date > :as");
    $st->execute([':ae' => $end_date, ':as' => $start_date] + $par('a1', $start_date, $end_date) + $par('a2', $start_date, $end_date));
    $adrRow = $st->fetch(PDO::FETCH_ASSOC) ?: ['total_room_revenue' => 0, 'total_nights_sold' => 0];
    $adr = round(rh_adr((float)$adrRow['total_room_revenue'], (float)$adrRow['total_nights_sold']), 0);
    $revpar = $available > 0 ? round($adrRow['total_room_revenue'] / $available, 0) : 0;

    $st = $db->prepare("SELECT COUNT(*) AS total_confirmed, SUM(CASE WHEN status = 'no-show' THEN 1 ELSE 0 END) AS no_shows FROM bookings
                        WHERE deleted_at IS NULL AND status IN ('confirmed', 'checked-in', 'checked-out', 'no-show') AND created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)");
    $st->execute([$start_date, $end_date]);
    $ns = $st->fetch(PDO::FETCH_ASSOC) ?: ['total_confirmed' => 0, 'no_shows' => 0];
    $noShowRate = $ns['total_confirmed'] > 0 ? round(($ns['no_shows'] / $ns['total_confirmed']) * 100, 1) : 0;

    $types = [];
    $st = $db->prepare("SELECT r.name AS room_type, COUNT(DISTINCT b.id) AS bookings, COALESCE(SUM(" . $nightsSql('b', 'o1') . "), 0) AS nights_booked
                        FROM rooms r LEFT JOIN bookings b ON r.id = b.room_id AND b.deleted_at IS NULL AND b.check_in_date <= :oe AND b.check_out_date > :os
                            AND b.status IN ('confirmed', 'checked-in', 'checked-out')
                        WHERE r.is_active = 1 GROUP BY r.id, r.name ORDER BY nights_booked DESC LIMIT 10");
    $st->execute([':oe' => $end_date, ':os' => $start_date] + $par('o1', $start_date, $end_date));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $types[] = ['room_type' => $t['room_type'], 'bookings' => (int)$t['bookings'], 'nights_booked' => (int)$t['nights_booked']];
    }
    return [
        'from' => $start_date, 'to' => $end_date, 'days' => (int)$daysInPeriod, 'currency' => rh_ai_currency(),
        'sellable_rooms' => $inventory, 'room_nights_available' => (int)$available, 'room_nights_sold' => (int)round((float)$occ['total_nights_booked']),
        'occupancy_pct' => $occPct, 'room_revenue_incl_vat' => rh_ai_money((float)$adrRow['total_room_revenue']), 'adr_incl_vat' => $adr, 'revpar' => $revpar,
        'bookings_made' => $made, 'cancelled' => $cancelled, 'cancellation_rate_pct' => $cancelRate, 'no_shows' => (int)$ns['no_shows'], 'no_show_rate_pct' => $noShowRate,
        'by_room_type' => $types, 'basis' => 'Same figures as Reports for the same dates (stays clipped to the dates; ADR and RevPAR include VAT).',
        'link' => 'reports.php?tab=occupancy&start_date=' . $start_date . '&end_date=' . $end_date, 'link_label' => 'Open Reports',
    ];
}

/**
 * Pace and pickup for a stay period (default the next 30 nights): room-nights on the books now vs the same
 * nights last year as booked by this day last year, nights picked up in the last 7 days, unsold nights,
 * average lead time and where bookings come from (the booking history's "created by").
 * ASSUMPTION: last year's comparison uses each booking's final status (the past status is not stored).
 */
function rh_ai_t_booking_pace(PDO $db, array $user, array $a): array
{
    if (!rh_ai_mod('bookings')) {
        return ['error' => 'Room bookings are switched off here.'];
    }
    [$from, $to] = rh_ai_range($a, date('Y-m-d'), date('Y-m-d', strtotime('+29 days')), 366);
    if ($from === null) {
        return ['error' => $to];
    }
    $nights = static fn(string $k): string => "GREATEST(0, DATEDIFF(LEAST(b.check_out_date, DATE_ADD(:{$k}e, INTERVAL 1 DAY)), GREATEST(b.check_in_date, :{$k}s)))";
    $factor = rh_booking_rooms_factor_sql('b');
    $sold = static function (string $s, string $e, string $extra, array $xp) use ($db, $nights, $factor): float {
        $st = $db->prepare("SELECT COALESCE(SUM(" . $nights('p') . " * $factor), 0) FROM bookings b
                            WHERE b.deleted_at IS NULL AND b.status IN ('confirmed', 'checked-in', 'checked-out') AND b.check_in_date <= :qe AND b.check_out_date > :qs $extra");
        $st->execute([':qe' => $e, ':qs' => $s, ':ps' => $s, ':pe' => $e] + $xp);
        return (float)$st->fetchColumn();
    };
    $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
    $sellable = rh_sellable_room_units();
    $available = $sellable * $days;
    $onBooks = $sold($from, $to, '', []);
    $lyFrom = date('Y-m-d', strtotime($from . ' -1 year'));
    $lyTo = date('Y-m-d', strtotime($to . ' -1 year'));
    $lyAsOf = date('Y-m-d', strtotime('-1 year +1 day'));
    $lastYear = $sold($lyFrom, $lyTo, 'AND b.created_at < :asof', [':asof' => $lyAsOf]);
    $pickup = $sold($from, $to, 'AND b.created_at >= :since', [':since' => date('Y-m-d', strtotime('-6 days'))]);
    $st = $db->prepare("SELECT COUNT(*) FROM bookings b WHERE b.deleted_at IS NULL AND b.status = 'tentative' AND b.check_in_date <= ? AND b.check_out_date > ?");
    $st->execute([$to, $from]);
    $tentative = (int)$st->fetchColumn();
    $lead = $db->query("SELECT AVG(GREATEST(0, DATEDIFF(check_in_date, DATE(created_at)))) FROM bookings WHERE deleted_at IS NULL AND created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetchColumn();
    $mix = [];
    try {
        $labels = ['guest' => 'Website', 'admin' => 'Front desk', 'api' => 'Partners (API)', 'system' => 'System'];
        foreach ($db->query("SELECT t.performed_by_type AS src, COUNT(DISTINCT t.booking_id) AS n FROM booking_timeline_logs t
                             INNER JOIN bookings b ON b.id = t.booking_id AND b.deleted_at IS NULL
                             WHERE t.action_type = 'create' AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY t.performed_by_type ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $mix[] = ['source' => $labels[(string)$m['src']] ?? ucfirst((string)$m['src']), 'bookings' => (int)$m['n']];
        }
    } catch (Throwable $e) {
        $mix = [];
    }
    return [
        'from' => $from, 'to' => $to, 'nights_in_period' => $days, 'sellable_rooms' => $sellable,
        'room_nights_on_the_books' => (int)round($onBooks), 'occupancy_on_the_books_pct' => rh_occupancy_pct($onBooks, (float)$available),
        'unsold_room_nights' => max(0, (int)round($available - $onBooks)),
        'same_nights_last_year_booked_by_this_date' => (int)round($lastYear), 'difference_vs_last_year' => (int)round($onBooks - $lastYear),
        'picked_up_last_7_days' => (int)round($pickup), 'tentative_bookings_in_period' => $tentative,
        'average_lead_time_days_last_90' => $lead !== null && $lead !== false ? round((float)$lead, 1) : null,
        'booking_sources_last_90_days' => $mix,
        'basis' => 'Room-nights on the same basis as Reports (joined rooms counted as the screen counts them).',
        'link' => 'reports.php?tab=occupancy&start_date=' . $from . '&end_date=' . $to, 'link_label' => 'Open Reports',
    ];
}

// ── Money (accounting reads) ─────────────────────────────────────────────────

/**
 * Where money is being lost in a range (default the last 7 days): still owed (receivables rule), refunds
 * paid (full amount, End of Day basis), till voids, folio charge voids, discounts, till shortages, wastage,
 * stock count shortages, no-shows, cancellations and the stock budget. Each with amount, cause and who.
 */
function rh_ai_t_money_leaks(PDO $db, array $user, array $a): array
{
    [$from, $to] = rh_ai_range($a, date('Y-m-d', strtotime('-6 days')), date('Y-m-d'), 92);
    if ($from === null) {
        return ['error' => $to];
    }
    $cur = rh_ai_currency();
    $out = ['from' => $from, 'to' => $to, 'currency' => $cur];
    $staffIds = [];

    // Still owed - the one receivables rule every money screen uses
    if (rh_ai_admin_lib('receivables.php')) {
        rh_ai_admin_lib('finance-schema.php');
        try {
            $sum = rh_receivable_summary($db, rh_receivable_mods());
            $left = [];
            foreach ($sum['rows'] as $r) {
                if ($r['days_outstanding'] > 0 || in_array($r['status'], ['checked-out', 'cancelled'], true)) {
                    $left[] = $r;
                }
            }
            usort($left, static fn($x, $y) => $y['amount_due'] <=> $x['amount_due']);
            $leftTotal = 0.0;
            foreach ($left as $r) {
                $leftTotal += $r['amount_due'];
            }
            $out['still_owed'] = [
                'total' => rh_ai_money((float)$sum['total']), 'accounts' => (int)$sum['count'], 'by_age_days' => $sum['buckets'],
                'left_or_overdue_total' => rh_ai_money($leftTotal), 'left_or_overdue_accounts' => count($left),
                'largest_left_or_overdue' => array_map(static fn($r) => ['type' => rh_receivable_type_label($r['type']), 'reference' => $r['reference'], 'guest_name' => $r['client'], 'status' => $r['status'], 'amount_due' => $r['amount_due'], 'days_outstanding' => $r['days_outstanding']], array_slice($left, 0, 6)),
                'complete' => empty($sum['failed']),
            ];
        } catch (Throwable $e) {
            $out['still_owed'] = ['error' => 'Could not be read.'];
        }
    }

    // Refunds paid out - End of Day basis (payment_type refund, completed or processing), full amount
    try {
        $st = $db->prepare("SELECT booking_type, booking_reference, refund_amount, refund_reason, refund_status, recorded_by, processed_by, payment_date
                            FROM payments WHERE deleted_at IS NULL AND payment_type = 'refund' AND refund_status IN ('completed', 'processing') AND payment_date BETWEEN ? AND ?
                            ORDER BY refund_amount DESC");
        $st->execute([$from, $to]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $tot = 0.0;
        foreach ($rows as $r) {
            $tot += (float)$r['refund_amount'];
            $staffIds[] = (int)$r['recorded_by'];
        }
        $pend = (int)$db->query("SELECT COUNT(*) FROM payments WHERE deleted_at IS NULL AND payment_type = 'refund' AND refund_status = 'pending'")->fetchColumn();
        $out['refunds'] = ['total' => rh_ai_money($tot), 'count' => count($rows), 'waiting_to_be_processed' => $pend, 'rows' => array_slice($rows, 0, 6),
            'note' => 'Shown in full (the whole amount paid back).'];
    } catch (Throwable $e) {
        $out['refunds'] = ['error' => 'Could not be read.'];
    }

    if (rh_ai_mod('pos')) {
        // Till voids - by voided_at, same basis as End of Day
        try {
            $st = $db->prepare("SELECT reference, total_amount, COALESCE(NULLIF(TRIM(void_reason), ''), 'No reason given') AS reason, voided_by, voided_at
                                FROM stock_orders WHERE status = 'voided' AND voided_at >= ? AND voided_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY total_amount DESC");
            $st->execute([$from, $to]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $tot = 0.0;
            $byStaff = [];
            foreach ($rows as $r) {
                $tot += (float)$r['total_amount'];
                $staffIds[] = (int)$r['voided_by'];
                $byStaff[(int)$r['voided_by']] = ($byStaff[(int)$r['voided_by']] ?? 0) + 1;
            }
            $out['till_voids'] = ['total' => rh_ai_money($tot), 'count' => count($rows), 'rows' => array_slice($rows, 0, 6), '_by_staff' => $byStaff];
        } catch (Throwable $e) {
            $out['till_voids'] = ['error' => 'Could not be read.'];
        }
        // Discounts given on paid till orders
        try {
            $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(discount_amount), 0) AS v FROM stock_orders
                                WHERE discount_amount > 0 AND status IN ('paid', 'completed') AND COALESCE(paid_at, created_at) >= ? AND COALESCE(paid_at, created_at) < DATE_ADD(?, INTERVAL 1 DAY)");
            $st->execute([$from, $to]);
            $d = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'v' => 0];
            $st = $db->prepare("SELECT COALESCE(NULLIF(TRIM(discount_reason), ''), 'No reason given') AS reason, COUNT(*) AS n, COALESCE(SUM(discount_amount), 0) AS v FROM stock_orders
                                WHERE discount_amount > 0 AND status IN ('paid', 'completed') AND COALESCE(paid_at, created_at) >= ? AND COALESCE(paid_at, created_at) < DATE_ADD(?, INTERVAL 1 DAY)
                                GROUP BY reason ORDER BY v DESC LIMIT 5");
            $st->execute([$from, $to]);
            $out['till_discounts'] = ['total' => rh_ai_money((float)$d['v']), 'count' => (int)$d['n'], 'by_reason' => $st->fetchAll(PDO::FETCH_ASSOC)];
        } catch (Throwable $e) {
            $out['till_discounts'] = ['error' => 'Could not be read.'];
        }
        // Till shortages at shift close (declared less than expected)
        try {
            $st = $db->prepare("SELECT user_name, shift_date, (variance_cash + variance_mobile + variance_card) AS variance, override_applied FROM stock_shift_closes
                                WHERE shift_date BETWEEN ? AND ? AND (variance_cash + variance_mobile + variance_card) < ? ORDER BY variance ASC");
            $st->execute([$from, $to, -BALANCE_TOLERANCE]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $tot = 0.0;
            foreach ($rows as $r) {
                $tot += -(float)$r['variance'];
            }
            $out['till_shortages'] = ['total' => rh_ai_money($tot), 'count' => count($rows),
                'rows' => array_map(static fn($r) => ['staff_name' => $r['user_name'], 'shift_date' => $r['shift_date'], 'short_by' => rh_ai_money(-(float)$r['variance']), 'closed_with_override' => (bool)$r['override_applied']], array_slice($rows, 0, 6))];
        } catch (Throwable $e) {
            $out['till_shortages'] = ['error' => 'Could not be read.'];
        }
    }
    if (rh_ai_mod('bookings')) {
        // Folio charges voided
        try {
            $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(line_total), 0) AS v FROM booking_charges WHERE voided = 1 AND voided_at >= ? AND voided_at < DATE_ADD(?, INTERVAL 1 DAY)");
            $st->execute([$from, $to]);
            $d = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'v' => 0];
            $out['folio_charges_voided'] = ['total' => rh_ai_money((float)$d['v']), 'count' => (int)$d['n']];
        } catch (Throwable $e) {
        }
        // No-shows (value of the stays that did not happen) and cancellations (booking history count)
        try {
            $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(" . rh_booking_gross_sql('b') . "), 0) AS v FROM bookings b WHERE b.deleted_at IS NULL AND b.status = 'no-show' AND b.check_in_date BETWEEN ? AND ?");
            $st->execute([$from, $to]);
            $d = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'v' => 0];
            $out['no_shows'] = ['count' => (int)$d['n'], 'booked_value' => rh_ai_money((float)$d['v'])];
        } catch (Throwable $e) {
        }
        $out['cancellations'] = ['count' => rh_cancellations_between($db, $from, $to)];
    }
    if (rh_ai_mod('stock')) {
        try {
            $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(wastage_cost), 0) AS v FROM stock_wastage WHERE recorded_date BETWEEN ? AND ?");
            $st->execute([$from, $to]);
            $d = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'v' => 0];
            $st = $db->prepare("SELECT COALESCE(NULLIF(TRIM(reason), ''), 'No reason given') AS reason, COALESCE(SUM(wastage_cost), 0) AS v FROM stock_wastage WHERE recorded_date BETWEEN ? AND ? GROUP BY reason ORDER BY v DESC LIMIT 4");
            $st->execute([$from, $to]);
            $out['wastage'] = ['total_cost' => rh_ai_money((float)$d['v']), 'entries' => (int)$d['n'], 'by_reason' => $st->fetchAll(PDO::FETCH_ASSOC)];
        } catch (Throwable $e) {
        }
        try {
            $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(shortage_cost), 0) AS v FROM stock_counts WHERE status = 'approved' AND COALESCE(approved_at, count_date) >= ? AND COALESCE(approved_at, count_date) < DATE_ADD(?, INTERVAL 1 DAY)");
            $st->execute([$from, $to]);
            $d = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'v' => 0];
            $out['stock_count_shortages'] = ['total_cost' => rh_ai_money((float)$d['v']), 'counts' => (int)$d['n']];
        } catch (Throwable $e) {
        }
        $b = rh_ai_stock_budget($db);
        if ($b !== null) {
            $out['stock_budget_this_month'] = $b;
        }
    }
    $names = rh_ai_staff_names($db, $staffIds);
    if (isset($out['refunds']['rows'])) {
        $out['refunds']['rows'] = array_map(static fn($r) => ['type' => $r['booking_type'], 'reference' => $r['booking_reference'], 'amount' => rh_ai_money((float)$r['refund_amount']),
            'reason' => (string)$r['refund_reason'], 'status' => $r['refund_status'], 'date' => $r['payment_date'], 'staff_name' => (string)($names[(int)$r['recorded_by']] ?? $r['processed_by'] ?? '')], $out['refunds']['rows']);
    }
    if (isset($out['till_voids']['rows'])) {
        $out['till_voids']['rows'] = array_map(static fn($r) => ['reference' => $r['reference'], 'amount' => rh_ai_money((float)$r['total_amount']), 'reason' => $r['reason'], 'staff_name' => (string)($names[(int)$r['voided_by']] ?? '')], $out['till_voids']['rows']);
        $bs = [];
        foreach ($out['till_voids']['_by_staff'] as $sid => $n) {
            $bs[] = ['staff_name' => (string)($names[$sid] ?? 'Unknown'), 'voids' => $n];
        }
        usort($bs, static fn($x, $y) => $y['voids'] <=> $x['voids']);
        $out['till_voids']['by_staff'] = array_slice($bs, 0, 4);
        unset($out['till_voids']['_by_staff']);
    }
    $out['link'] = 'accounting-dashboard.php?start_date=' . $from . '&end_date=' . $to;
    $out['link_label'] = 'Open Accounting';
    return $out;
}

/** This month's stock budget (the Stock Budget screen's own calculation), or null when none is set. */
function rh_ai_stock_budget(PDO $db): ?array
{
    try {
        if (!rh_ai_admin_lib('stock-budget-lib.php') || !function_exists('rh_sbud_compute')) {
            return null;
        }
        $c = rh_sbud_compute($db, date('Y-m'));
        if (empty($c['has_budget'])) {
            return null;
        }
        $s = $c['summary'];
        return ['budget' => rh_ai_money((float)$s['budget']), 'used' => rh_ai_money((float)$s['used']), 'left' => rh_ai_money((float)$s['left']),
            'pct_used' => round((float)$s['pct_used'], 1), 'month_gone_pct' => round((float)$s['month_pct'], 1), 'status' => (string)$s['status']];
    } catch (Throwable $e) {
        return null;
    }
}

// ── Restaurant & Bar ─────────────────────────────────────────────────────────

/** Till sales, voids, refunds, top sellers and void reasons - End of Day's POS block, summed day by day. */
function rh_ai_t_restaurant_sales(PDO $db, array $user, array $a): array
{
    [$from, $to] = rh_ai_range($a, date('Y-m-d'), date('Y-m-d'), 31);
    if ($from === null) {
        return ['error' => $to];
    }
    if (!rh_ai_admin_lib('pos-shift-totals.php') || !function_exists('rh_pos_eod_summary')) {
        return ['error' => 'Till figures are not available here.'];
    }
    $t = ['orders' => 0, 'gross' => 0.0, 'voided_count' => 0, 'voided_value' => 0.0, 'refunded_count' => 0, 'refunded_value' => 0.0];
    $items = [];
    $reasons = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $s = rh_pos_eod_summary($db, $d);
        foreach ($t as $k => $v) {
            $t[$k] += $s['totals'][$k] ?? 0;
        }
        foreach ($s['top_items'] as $i) {
            $key = $i['item_name'];
            $items[$key] = ['item' => $i['item_name'], 'qty' => ($items[$key]['qty'] ?? 0) + (float)$i['qty'], 'revenue' => ($items[$key]['revenue'] ?? 0) + (float)$i['revenue']];
        }
        foreach ($s['void_reasons'] as $r) {
            $key = $r['reason'];
            $reasons[$key] = ['reason' => $key, 'count' => ($reasons[$key]['count'] ?? 0) + (int)$r['cnt'], 'value' => ($reasons[$key]['value'] ?? 0) + (float)$r['value']];
        }
    }
    usort($items, static fn($x, $y) => $y['revenue'] <=> $x['revenue']);
    usort($reasons, static fn($x, $y) => $y['count'] <=> $x['count']);
    foreach (['gross', 'voided_value', 'refunded_value'] as $k) {
        $t[$k] = rh_ai_money($t[$k]);
    }
    return ['from' => $from, 'to' => $to, 'currency' => rh_ai_currency(), 'totals' => $t,
        'top_sellers' => array_map(static fn($i) => ['item' => $i['item'], 'qty' => round($i['qty'], 2), 'revenue' => rh_ai_money($i['revenue'])], array_slice($items, 0, 8)),
        'void_reasons' => array_slice($reasons, 0, 5), 'basis' => 'Same as the End of Day till block (sales by the time they were paid, voids by the time they were voided).',
        'link' => 'end-of-day-report.php?date=' . $to, 'link_label' => 'Open End of Day'];
}

/** Tickets waiting at the kitchen / bar / coffee stations now, the slow ones, and today's order-to-ready time. */
function rh_ai_t_kitchen_tickets(PDO $db, array $user, array $a): array
{
    $slow = RH_AI_SLOW_TICKET_MINUTES;
    $rows = $db->query("SELECT soi.item_name, COALESCE(NULLIF(soi.station, ''), 'kitchen') AS station, soi.kds_status, o.reference, o.table_number, o.room_number,
                               TIMESTAMPDIFF(MINUTE, soi.created_at, NOW()) AS waiting_minutes
                        FROM stock_order_items soi INNER JOIN stock_orders o ON o.id = soi.order_id
                        WHERE soi.kds_status IN ('pending', 'preparing') AND o.status NOT IN ('voided', 'cancelled', 'refunded')
                          AND soi.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                        ORDER BY soi.created_at ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $byStation = [];
    $slowList = [];
    foreach ($rows as $r) {
        $s = (string)$r['station'];
        $byStation[$s] = $byStation[$s] ?? ['station' => $s, 'waiting' => 0, 'slow' => 0, 'oldest_minutes' => 0];
        $byStation[$s]['waiting']++;
        $byStation[$s]['oldest_minutes'] = max($byStation[$s]['oldest_minutes'], (int)$r['waiting_minutes']);
        if ((int)$r['waiting_minutes'] >= $slow) {
            $byStation[$s]['slow']++;
            if (count($slowList) < 8) {
                $slowList[] = ['item' => $r['item_name'], 'station' => $s, 'status' => $r['kds_status'], 'waiting_minutes' => (int)$r['waiting_minutes'],
                    'table' => $r['table_number'] !== null && $r['table_number'] !== '' ? (string)$r['table_number'] : null, 'room' => $r['room_number'] !== null && $r['room_number'] !== '' ? (string)$r['room_number'] : null];
            }
        }
    }
    $today = $db->query("SELECT COUNT(*) AS n, AVG(TIMESTAMPDIFF(MINUTE, soi.created_at, soi.ready_at)) AS avg_m,
                                SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, soi.created_at, soi.ready_at) >= " . (int)$slow . " THEN 1 ELSE 0 END) AS slow_n
                         FROM stock_order_items soi WHERE soi.ready_at IS NOT NULL AND soi.ready_at >= CURDATE()")->fetch(PDO::FETCH_ASSOC) ?: [];
    $link = rh_ai_perm_any($user, ['kds_view']) ? 'kds.php' : 'stock-orders.php';
    return ['slow_after_minutes' => $slow, 'waiting_now' => count($rows), 'by_station' => array_values($byStation), 'slow_tickets' => $slowList,
        'ready_today' => ['items' => (int)($today['n'] ?? 0), 'average_order_to_ready_minutes' => isset($today['avg_m']) && $today['avg_m'] !== null ? round((float)$today['avg_m'], 1) : null, 'slow' => (int)($today['slow_n'] ?? 0)],
        'link' => $link, 'link_label' => $link === 'kds.php' ? 'Open the kitchen screen' : 'Open Orders'];
}

// ── Stock (reads) ────────────────────────────────────────────────────────────

/** Out / low / negative stock (the stock dashboard rule), batches expiring, wastage, count variance, counts waiting. */
function rh_ai_t_inventory_watch(PDO $db, array $user, array $a): array
{
    if (trim((string)($a['purchase_order'] ?? '')) !== '') {
        return rh_ai_purchase_order_lookup($db, $user, (string)$a['purchase_order']); // one purchase order (ai-lookups.php)
    }
    if (trim((string)($a['item'] ?? '')) !== '') {
        $r = rh_ai_stock_item_lookup($db, $user, (string)$a['item']); // one stock item (ai-lookups.php)
        return $r + ['link' => 'stock-dashboard.php', 'link_label' => 'Open Stock'];
    }
    [$from, $to] = rh_ai_range($a, date('Y-m-d', strtotime('-6 days')), date('Y-m-d'), 92);
    if ($from === null) {
        return ['error' => $to];
    }
    $al = rh_stock_alert_summary($db, 8);
    $item = static fn($i) => ['item' => $i['name'], 'unit' => $i['unit'], 'quantity' => round((float)$i['current_quantity'], 2), 'reorder_at' => isset($i['threshold']) ? round((float)$i['threshold'], 2) : null];
    $out = ['currency' => rh_ai_currency(), 'from' => $from, 'to' => $to,
        'alerts' => ['out' => $al['out'], 'low' => $al['low'], 'negative' => $al['negative'], 'opening_stock_not_recorded' => $al['never_stocked'],
            'out_items' => array_map($item, $al['out_items']), 'low_items' => array_map($item, $al['low_items']), 'negative_items' => array_map($item, $al['negative_items'])]];
    try {
        $rows = $db->query("SELECT i.name, b.batch_number, b.quantity_remaining, b.expiry_date FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id
                            WHERE b.status = 'active' AND b.quantity_remaining > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                            ORDER BY b.expiry_date LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
        $out['batches_expiring_within_7_days'] = array_map(static fn($r) => ['item' => $r['name'], 'batch' => $r['batch_number'], 'quantity_left' => round((float)$r['quantity_remaining'], 2), 'expires' => $r['expiry_date']], $rows);
    } catch (Throwable $e) {
    }
    try {
        $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(wastage_cost), 0) AS v FROM stock_wastage WHERE recorded_date BETWEEN ? AND ?");
        $st->execute([$from, $to]);
        $d = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'v' => 0];
        $out['wastage'] = ['entries' => (int)$d['n'], 'cost' => rh_ai_money((float)$d['v'])];
    } catch (Throwable $e) {
    }
    try {
        $c = $db->query("SELECT reference, count_date, shortage_cost, surplus_cost, total_variance_cost FROM stock_counts WHERE status = 'approved' ORDER BY COALESCE(approved_at, count_date) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $out['last_approved_count'] = ['count_ref' => $c['reference'], 'date' => $c['count_date'], 'shortage_cost' => rh_ai_money((float)$c['shortage_cost']), 'surplus_cost' => rh_ai_money((float)$c['surplus_cost'])];
        }
        $out['counts_waiting_for_approval'] = (int)$db->query("SELECT COUNT(*) FROM stock_counts WHERE status = 'submitted'")->fetchColumn();
    } catch (Throwable $e) {
    }
    $b = rh_ai_stock_budget($db);
    if ($b !== null) {
        $out['stock_budget_this_month'] = $b;
    }
    $out['link'] = 'stock-dashboard.php';
    $out['link_label'] = 'Open Stock';
    return $out;
}

// ── Events, Conference & Gym ─────────────────────────────────────────────────

/** Conference bookings coming up (and not yet confirmed), event enquiries waiting. */
function rh_ai_t_events_pipeline(PDO $db, array $user, array $a): array
{
    $one = null;
    if (trim((string)($a['query'] ?? '')) !== '') {
        $one = rh_ai_events_lookup($db, $user, (string)$a['query']); // one booking (ai-lookups.php)
        if (($one['match'] ?? '') !== 'none') {
            return $one;
        }
    }
    $days = max(1, min(180, (int)($a['days'] ?? 30)));
    $out = ['days_ahead' => $days, 'currency' => rh_ai_currency()];
    if (rh_ai_mod('conference') && rh_ai_perm_any($user, ['conference'])) {
        require_once dirname(__DIR__) . '/conference-pricing.php';
        try {
            $gross = function_exists('rh_conf_gross_sql') ? rh_conf_gross_sql('ci') : 'ci.total_with_vat';
            $st = $db->prepare("SELECT ci.status, COUNT(*) AS n, COALESCE(SUM($gross), 0) AS value, COALESCE(SUM(ci.amount_due), 0) AS due FROM conference_inquiries ci
                                WHERE ci.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND ci.status <> 'cancelled' GROUP BY ci.status");
            $st->execute([$days]);
            $by = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $by[] = ['status' => $r['status'], 'bookings' => (int)$r['n'], 'value' => rh_ai_money((float)$r['value']), 'still_owed' => rh_ai_money((float)$r['due'])];
            }
            $st = $db->prepare("SELECT ci.inquiry_reference, ci.company_name, ci.contact_person, ci.event_date, ci.number_of_attendees, ci.status, ci.amount_due FROM conference_inquiries ci
                                WHERE ci.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND ci.status <> 'cancelled' ORDER BY ci.event_date LIMIT 10");
            $st->execute([$days]);
            $list = array_map(static fn($r) => ['reference' => $r['inquiry_reference'], 'guest_name' => $r['company_name'] !== '' && $r['company_name'] !== null ? $r['company_name'] : $r['contact_person'],
                'date' => $r['event_date'], 'attendees' => (int)$r['number_of_attendees'], 'status' => $r['status'], 'still_owed' => rh_ai_money((float)$r['amount_due'])], $st->fetchAll(PDO::FETCH_ASSOC));
            $pending = (int)$db->query("SELECT COUNT(*) FROM conference_inquiries WHERE status = 'pending'")->fetchColumn();
            $out['conference'] = ['upcoming_by_status' => $by, 'upcoming' => $list, 'enquiries_not_confirmed' => $pending];
        } catch (Throwable $e) {
            $out['conference'] = ['error' => 'Could not be read.'];
        }
    }
    if (rh_ai_mod('events') && rh_ai_perm_any($user, ['events_bookings', 'events'])) {
        try {
            $by = $db->query("SELECT COALESCE(NULLIF(status, ''), 'new') AS status, COUNT(*) AS n FROM event_inquiries WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
            $st = $db->prepare("SELECT e.title, e.event_date, COUNT(ei.id) AS enquiries FROM events e LEFT JOIN event_inquiries ei ON ei.event_id = e.id
                                WHERE e.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) GROUP BY e.id, e.title, e.event_date ORDER BY e.event_date LIMIT 8");
            $st->execute([$days]);
            $out['events'] = ['enquiries_last_60_days_by_status' => array_map('intval', $by ?: []), 'upcoming_events' => $st->fetchAll(PDO::FETCH_ASSOC)];
        } catch (Throwable $e) {
            $out['events'] = ['error' => 'Could not be read.'];
        }
    }
    if (count($out) === 2) {
        return ['error' => "That's outside what this account can see."];
    }
    $out['link'] = isset($out['conference']) ? 'conference-management.php' : 'events-inquiries.php';
    $out['link_label'] = isset($out['conference']) ? 'Open Conference' : 'Open Event enquiries';
    if ($one !== null) { // nothing matched the booking asked for: say so, then what is coming up
        $out = ['match' => 'none', 'not_found' => $one['not_found'], 'coming_up_instead' => true] + $out;
    }
    return $out;
}

/** Gym members: the Members screen's own counts, who expires in the next 30 days, new enquiries. */
function rh_ai_t_gym_watch(PDO $db, array $user, array $a): array
{
    if (trim((string)($a['member'] ?? '')) !== '') {
        return rh_ai_gym_member_lookup($db, $user, (string)$a['member']); // one member (ai-lookups.php)
    }
    if (trim((string)($a['class'] ?? '')) !== '' || !empty($a['date']) || trim((string)($a['time'] ?? '')) !== '') {
        return rh_ai_gym_class_lookup($db, $user, $a); // a class or time slot on a date (ai-lookups.php)
    }
    $c = [
        'all' => (int)$db->query("SELECT COUNT(*) FROM gym_members")->fetchColumn(),
        'active' => (int)$db->query("SELECT COUNT(*) FROM gym_members WHERE status='active' AND (expiry_date IS NULL OR expiry_date >= CURDATE())")->fetchColumn(),
        'expiring_30_days' => (int)$db->query("SELECT COUNT(*) FROM gym_members WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn(),
        'expired' => (int)$db->query("SELECT COUNT(*) FROM gym_members WHERE status='expired' OR (expiry_date IS NOT NULL AND expiry_date < CURDATE())")->fetchColumn(),
    ];
    $list = array_map(static fn($r) => ['member_number' => $r['member_number'], 'guest_name' => $r['full_name'], 'membership' => $r['membership_type'], 'expires' => $r['expiry_date']],
        $db->query("SELECT member_number, full_name, membership_type, expiry_date FROM gym_members WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY expiry_date LIMIT 10")->fetchAll(PDO::FETCH_ASSOC));
    $newEnq = 0;
    try {
        $newEnq = (int)$db->query("SELECT COUNT(*) FROM gym_inquiries WHERE COALESCE(NULLIF(status, ''), 'new') IN ('new', 'pending')")->fetchColumn();
    } catch (Throwable $e) {
    }
    return ['members' => $c, 'expiring_soon' => $list, 'enquiries_waiting' => $newEnq, 'link' => 'gym-members.php?filter=expiring', 'link_label' => 'Open expiring members'];
}

// ── Guest voice ──────────────────────────────────────────────────────────────

/** Reviews to moderate / reply to, low ratings, and website / event / gym / conference enquiries waiting. */
function rh_ai_t_guest_voice(PDO $db, array $user, array $a): array
{
    $out = [];
    $link = null;
    if (rh_ai_mod('website_cms') && rh_ai_perm_any($user, ['reviews'])) {
        try {
            $needs = (int)$db->query("SELECT COUNT(*) FROM reviews r WHERE r.status = 'approved' AND NOT EXISTS (SELECT 1 FROM review_responses rr0 WHERE rr0.review_id = r.id)")->fetchColumn();
            $pending = (int)$db->query("SELECT COUNT(*) FROM reviews WHERE status = 'pending'")->fetchColumn();
            $avg = $db->query("SELECT COUNT(*) AS n, AVG(rating) AS a, SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) AS low FROM reviews WHERE status = 'approved' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch(PDO::FETCH_ASSOC) ?: [];
            $latest = $db->query("SELECT r.guest_name, r.rating, r.title, r.comment, DATE(r.created_at) AS d FROM reviews r WHERE r.status = 'approved' AND NOT EXISTS (SELECT 1 FROM review_responses rr0 WHERE rr0.review_id = r.id) ORDER BY r.rating ASC, r.created_at DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
            $out['reviews'] = ['waiting_for_approval' => $pending, 'published_needing_a_reply' => $needs,
                'last_30_days' => ['published' => (int)($avg['n'] ?? 0), 'average_rating' => isset($avg['a']) && $avg['a'] !== null ? round((float)$avg['a'], 1) : null, 'rated_2_or_less' => (int)($avg['low'] ?? 0)],
                'needing_a_reply' => array_map(static fn($r) => ['guest_name' => $r['guest_name'], 'rating' => (int)$r['rating'], 'title' => (string)$r['title'], 'comment' => mb_substr((string)$r['comment'], 0, 200), 'date' => $r['d']], $latest)];
            $link = $link ?? ['reviews.php?status=approved&reply=needs', 'Open reviews needing a reply'];
        } catch (Throwable $e) {
            $out['reviews'] = ['error' => 'Could not be read.'];
        }
    }
    if (rh_ai_mod('website_cms') && rh_ai_perm_any($user, ['contact'])) {
        try {
            $by = $db->query("SELECT status, COUNT(*) FROM contact_inquiries GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $new = $db->query("SELECT reference_number, name, subject, created_at, TIMESTAMPDIFF(HOUR, created_at, NOW()) AS age_h FROM contact_inquiries WHERE status = 'new' ORDER BY created_at LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            $out['website_messages'] = ['unread' => (int)($by['new'] ?? 0), 'read_not_replied' => (int)($by['read'] ?? 0),
                'oldest_unread' => array_map(static fn($r) => ['reference' => $r['reference_number'], 'guest_name' => $r['name'], 'subject' => (string)$r['subject'], 'waiting_hours' => (int)$r['age_h']], $new)];
            $link = $link ?? ['contact-inquiries.php?status=new', 'Open new messages'];
        } catch (Throwable $e) {
        }
    }
    if (rh_ai_mod('events') && rh_ai_perm_any($user, ['events_bookings'])) {
        try {
            $out['event_enquiries_new'] = (int)$db->query("SELECT COUNT(*) FROM event_inquiries WHERE COALESCE(NULLIF(status, ''), 'new') IN ('new', 'pending')")->fetchColumn();
            $link = $link ?? ['events-inquiries.php?status=new', 'Open event enquiries'];
        } catch (Throwable $e) {
        }
    }
    if (rh_ai_mod('gym') && rh_ai_perm_any($user, ['gym'])) {
        try {
            $out['gym_enquiries_new'] = (int)$db->query("SELECT COUNT(*) FROM gym_inquiries WHERE COALESCE(NULLIF(status, ''), 'new') IN ('new', 'pending')")->fetchColumn();
            $link = $link ?? ['gym-inquiries.php?status=new', 'Open gym enquiries'];
        } catch (Throwable $e) {
        }
    }
    if (rh_ai_mod('conference') && rh_ai_perm_any($user, ['conference'])) {
        try {
            $out['conference_enquiries_not_confirmed'] = (int)$db->query("SELECT COUNT(*) FROM conference_inquiries WHERE status = 'pending'")->fetchColumn();
            $link = $link ?? ['conference-management.php', 'Open Conference'];
        } catch (Throwable $e) {
        }
    }
    if (!$out) {
        return ['error' => "That's outside what this account can see."];
    }
    if ($link) {
        $out['link'] = $link[0];
        $out['link_label'] = $link[1];
    }
    return $out;
}

// ── Housekeeping & Maintenance ───────────────────────────────────────────────

/** Open housekeeping jobs and maintenance tasks (overdue, today, urgent, blocking a room) and unsellable rooms. */
function rh_ai_t_work_queues(PDO $db, array $user, array $a): array
{
    $roomAsked = null;
    if (trim((string)($a['room'] ?? '')) !== '') {
        $roomAsked = rh_ai_room_jobs_lookup($db, $user, (string)$a['room']); // one room (ai-lookups.php)
        if (($roomAsked['match'] ?? '') === 'one' || ($roomAsked['match'] ?? '') === 'several') {
            return $roomAsked + ['link' => 'housekeeping.php', 'link_label' => 'Open Housekeeping'];
        }
    }
    $out = [];
    $ids = [];
    if (rh_ai_mod('housekeeping') && rh_ai_perm_any($user, ['housekeeping'])) {
        $rows = $db->query("SELECT ha.status, ha.priority, ha.assignment_type, ha.due_date, ha.assigned_to, ir.room_number FROM housekeeping_assignments ha
                            LEFT JOIN individual_rooms ir ON ir.id = ha.individual_room_id
                            WHERE ha.status IN ('pending', 'in_progress', 'blocked') ORDER BY ha.due_date IS NULL, ha.due_date, FIELD(ha.priority, 'high', 'medium', 'low') LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        $c = ['open' => count($rows), 'overdue' => 0, 'due_today' => 0, 'high_priority' => 0, 'blocked' => 0, 'not_started' => 0];
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $c['overdue'] += ($r['due_date'] !== null && $r['due_date'] < $today) ? 1 : 0;
            $c['due_today'] += $r['due_date'] === $today ? 1 : 0;
            $c['high_priority'] += $r['priority'] === 'high' ? 1 : 0;
            $c['blocked'] += $r['status'] === 'blocked' ? 1 : 0;
            $c['not_started'] += $r['status'] === 'pending' ? 1 : 0;
            $ids[] = (int)$r['assigned_to'];
        }
        $out['housekeeping'] = $c + ['_list' => array_slice($rows, 0, 10)];
        $out['rooms_being_cleaned'] = (int)$db->query("SELECT COUNT(*) FROM individual_rooms WHERE is_active = 1 AND status = 'cleaning'")->fetchColumn();
    }
    if (rh_ai_mod('bookings') && rh_ai_perm_any($user, ['room_maintenance'])) {
        $rows = $db->query("SELECT rms.title, rms.status, rms.priority, rms.due_date, rms.block_room, rms.assigned_to, ir.room_number FROM room_maintenance_schedules rms
                            LEFT JOIN individual_rooms ir ON ir.id = rms.individual_room_id
                            WHERE rms.status IN ('planned', 'in_progress') ORDER BY rms.due_date IS NULL, rms.due_date, FIELD(rms.priority, 'urgent', 'high', 'medium', 'low') LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        $c = ['open' => count($rows), 'overdue' => 0, 'urgent_or_high' => 0, 'blocking_a_room' => 0];
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $c['overdue'] += ($r['due_date'] !== null && $r['due_date'] < $today) ? 1 : 0;
            $c['urgent_or_high'] += in_array($r['priority'], ['urgent', 'high'], true) ? 1 : 0;
            $c['blocking_a_room'] += (int)$r['block_room'] === 1 ? 1 : 0;
            $ids[] = (int)$r['assigned_to'];
        }
        $out['maintenance'] = $c + ['_list' => array_slice($rows, 0, 10)];
        $out['rooms_off_sale_now'] = rh_unsellable_room_units();
    }
    if (!$out) {
        return ['error' => "That's outside what this account can see."];
    }
    $names = rh_ai_staff_names($db, $ids);
    if (isset($out['housekeeping'])) {
        $out['housekeeping']['jobs'] = array_map(static fn($r) => ['room' => (string)$r['room_number'], 'job' => str_replace('_', ' ', (string)$r['assignment_type']), 'priority' => $r['priority'], 'status' => $r['status'], 'due' => $r['due_date'], 'staff_name' => (string)($names[(int)$r['assigned_to']] ?? '')], $out['housekeeping']['_list']);
        unset($out['housekeeping']['_list']);
    }
    if (isset($out['maintenance'])) {
        $out['maintenance']['tasks'] = array_map(static fn($r) => ['room' => (string)$r['room_number'], 'task' => (string)$r['title'], 'priority' => $r['priority'], 'status' => $r['status'], 'due' => $r['due_date'], 'blocks_room' => (int)$r['block_room'] === 1, 'staff_name' => (string)($names[(int)$r['assigned_to']] ?? '')], $out['maintenance']['_list']);
        unset($out['maintenance']['_list']);
    }
    $out['link'] = isset($out['housekeeping']) ? 'housekeeping.php' : 'room-maintenance.php';
    $out['link_label'] = isset($out['housekeeping']) ? 'Open Housekeeping' : 'Open Maintenance';
    if ($roomAsked !== null) { // no such room: say so, then the open jobs there are
        $out = ['match' => 'none', 'not_found' => $roomAsked['not_found'] ?? 'No such room.'] + $out;
    }
    return $out;
}

/** The person's own briefing findings (zero tokens), for the Lead and the specialists. */
function rh_ai_t_daily_briefing(PDO $db, array $user, array $a): array
{
    $b = rh_ai_briefing_findings($db, $user);
    $f = array_map(static fn($x) => array_diff_key($x, ['link' => 1, 'score' => 1]), $b['findings']);
    return ['for' => $b['lens_label'], 'date' => $b['date'], 'currency' => $b['currency'], 'findings' => $f, 'nothing_found' => !$f];
}

// ══ The daily briefing ("full tea") ══════════════════════════════════════════

/** Which view of the hotel this person gets, from their permissions (role only picks the manager emphasis). */
function rh_ai_briefing_lens(array $user): string
{
    $money = rh_ai_perm_any($user, ['accounting', 'reports']);
    if ((string)($user['role'] ?? '') === 'manager') {
        return 'manager';
    }
    if ($money) {
        return 'owner';
    }
    if (rh_ai_perm_any($user, ['bookings']) && rh_ai_mod('bookings')) {
        return 'front_desk';
    }
    return 'operations';
}

function rh_ai_briefing_lens_label(string $lens): string
{
    return ['owner' => 'Where money is being lost', 'manager' => 'Shift health and decisions', 'front_desk' => 'What needs you at the desk', 'operations' => 'Your queue'][$lens] ?? 'Today';
}

/** Weight added to each kind of finding for each view (ranking only). */
function rh_ai_briefing_weights(string $lens): array
{
    $w = [
        'owner'      => ['money' => 50, 'decision' => 30, 'time' => 15, 'queue' => 10],
        'manager'    => ['decision' => 45, 'time' => 35, 'money' => 30, 'queue' => 25],
        'front_desk' => ['time' => 50, 'queue' => 25, 'decision' => 20, 'money' => 0],
        'operations' => ['queue' => 50, 'time' => 30, 'decision' => 20, 'money' => 0],
    ];
    return $w[$lens] ?? $w['operations'];
}

/**
 * Gather and rank today's findings for this person. Deterministic, ZERO model tokens.
 * Each finding: id, kind (money|time|queue|decision), title, detail, count, amount (money kinds only),
 * items (rows with guest_name / reference / staff_name fields the vault tokenises), link (deep link the
 * person may open), score. Money findings exist only for people who hold a money permission.
 * @return array{lens:string, lens_label:string, date:string, currency:string, findings:array}
 */
function rh_ai_briefing_findings(PDO $db, array $user): array
{
    $lens = rh_ai_briefing_lens($user);
    $cur = rh_ai_currency();
    $f = [];
    $add = static function (array $x) use (&$f, $user): void {
        if (!empty($x['link'])) {
            $x['link'] = rh_ai_deep_link($user, $x['link'][0], $x['link'][1]);
        }
        if (empty($x['link'])) {
            unset($x['link']);
        }
        $f[] = $x;
    };
    $run = static function (string $tool, array $args = []) use ($db, $user): ?array {
        if (!rh_ai_tool_allowed($user, $tool)) {
            return null;
        }
        try {
            $def = rh_ai_tool_registry()[$tool];
            $r = (array)call_user_func($def['handler'], $db, $user, $args);
            return empty($r['error']) ? $r : null;
        } catch (Throwable $e) {
            error_log('Assistant briefing ' . $tool . ': ' . $e->getMessage());
            return null;
        }
    };

    // Front desk: time leaks and misses
    if (rh_ai_tool_allowed($user, 'arrivals_departures')) {
        try {
            rh_ai_brief_front_desk($db, $user, $add);
        } catch (Throwable $e) {
            error_log('Assistant briefing front desk: ' . $e->getMessage());
        }
    }
    // Money: where it is being lost (people with a money permission only)
    if (rh_ai_perm_any($user, ['accounting', 'reports']) && ($m = $run('money_leaks')) !== null) {
        rh_ai_brief_money($m, $add);
    }
    if (rh_ai_perm_any($user, ['accounting', 'reports']) && ($p = $run('booking_pace', ['from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+6 days'))])) !== null && $p['sellable_rooms'] > 0) {
        $unsold = (int)$p['unsold_room_nights'];
        if ($unsold > 0) {
            $add(['id' => 'unsold_nights', 'kind' => 'money', 'severity' => min(40, (int)round(100 - (float)$p['occupancy_on_the_books_pct']) / 2.5),
                'title' => 'Unsold room-nights in the next 7 days', 'count' => $unsold,
                'detail' => $unsold . ' room-nights still unsold (' . $p['occupancy_on_the_books_pct'] . '% on the books, ' . ($p['difference_vs_last_year'] >= 0 ? '+' : '') . $p['difference_vs_last_year'] . ' room-nights vs the same nights last year).',
                'link' => ['reports.php?tab=occupancy&start_date=' . $p['from'] . '&end_date=' . $p['to'], 'Open Reports']]);
        }
    }
    // Queues
    if (($w = $run('work_queues')) !== null) {
        if (!empty($w['housekeeping']['open'])) {
            $h = $w['housekeeping'];
            $sev = min(60, $h['overdue'] * 12 + $h['high_priority'] * 6 + $h['blocked'] * 8 + $h['due_today'] * 3);
            if ($sev > 0) {
                $add(['id' => 'hk_queue', 'kind' => 'queue', 'severity' => $sev, 'title' => 'Housekeeping jobs waiting', 'count' => $h['open'],
                    'detail' => $h['overdue'] . ' overdue, ' . $h['due_today'] . ' due today, ' . $h['high_priority'] . ' high priority, ' . $h['blocked'] . ' blocked.',
                    'items' => array_slice(array_map(static fn($j) => ['room' => $j['room'], 'text' => $j['job'] . ', ' . $j['status'] . ($j['due'] ? ', due ' . $j['due'] : ''), 'staff_name' => $j['staff_name']], $h['jobs']), 0, 4),
                    'link' => ['housekeeping.php', 'Open Housekeeping']]);
            }
        }
        if (!empty($w['maintenance']['open'])) {
            $m = $w['maintenance'];
            $sev = min(60, $m['overdue'] * 10 + $m['urgent_or_high'] * 8 + $m['blocking_a_room'] * 10);
            if ($sev > 0) {
                $add(['id' => 'maint_queue', 'kind' => $m['blocking_a_room'] > 0 ? 'decision' : 'queue', 'severity' => $sev, 'title' => 'Maintenance tasks open', 'count' => $m['open'],
                    'detail' => $m['overdue'] . ' overdue, ' . $m['urgent_or_high'] . ' urgent or high, ' . $m['blocking_a_room'] . ' keeping a room off sale.',
                    'items' => array_slice(array_map(static fn($t) => ['room' => $t['room'], 'text' => $t['task'] . ', ' . $t['priority'] . ($t['due'] ? ', due ' . $t['due'] : '') . ($t['blocks_room'] ? ', room off sale' : ''), 'staff_name' => $t['staff_name']], $m['tasks']), 0, 4),
                    'link' => ['room-maintenance.php', 'Open Maintenance']]);
            }
        }
    }
    if (($k = $run('kitchen_tickets')) !== null && $k['waiting_now'] > 0) {
        $slow = 0;
        foreach ($k['by_station'] as $s) {
            $slow += $s['slow'];
        }
        if ($slow > 0) {
            $add(['id' => 'slow_tickets', 'kind' => 'queue', 'severity' => min(60, 20 + $slow * 8), 'title' => 'Slow tickets at the stations', 'count' => $slow,
                'detail' => $slow . ' item(s) waiting longer than ' . $k['slow_after_minutes'] . ' minutes; ' . $k['waiting_now'] . ' waiting in all.',
                'items' => array_slice(array_map(static fn($t) => ['text' => $t['item'] . ' (' . $t['station'] . ') ' . $t['waiting_minutes'] . ' min' . ($t['table'] ? ', table ' . $t['table'] : '') . ($t['room'] ? ', room ' . $t['room'] : '')], $k['slow_tickets']), 0, 4),
                'link' => [$k['link'], $k['link_label']]]);
        }
    }
    if (($s = $run('inventory_watch')) !== null) {
        $al = $s['alerts'];
        if ($al['out'] + $al['low'] > 0) {
            $add(['id' => 'stock_low', 'kind' => 'queue', 'severity' => min(50, $al['out'] * 8 + $al['low'] * 3), 'title' => 'Stock out or running low', 'count' => $al['out'] + $al['low'],
                'detail' => $al['out'] . ' item(s) out, ' . $al['low'] . ' low.',
                'items' => array_slice(array_map(static fn($i) => ['text' => $i['item'] . ': ' . $i['quantity'] . ' ' . $i['unit'] . ' left'], array_merge($al['out_items'], $al['low_items'])), 0, 4),
                'link' => ['stock-reorder.php', 'Open Reorder']]);
        }
        if (!empty($s['counts_waiting_for_approval'])) {
            $add(['id' => 'count_approval', 'kind' => 'decision', 'severity' => 35, 'title' => 'Stock count waiting for approval', 'count' => (int)$s['counts_waiting_for_approval'],
                'detail' => (int)$s['counts_waiting_for_approval'] . ' count(s) submitted and waiting for approval.', 'link' => ['stock-count.php', 'Open Stock Count']]);
        }
        if (!empty($s['batches_expiring_within_7_days'])) {
            $add(['id' => 'batches_expiring', 'kind' => 'queue', 'severity' => 25, 'title' => 'Stock batches expiring this week', 'count' => count($s['batches_expiring_within_7_days']),
                'detail' => 'Use or move these before they expire.', 'items' => array_slice(array_map(static fn($b) => ['text' => $b['item'] . ', ' . $b['quantity_left'] . ' left, expires ' . $b['expires']], $s['batches_expiring_within_7_days']), 0, 4),
                'link' => ['stock-batches.php?expiry=soon', 'Open Batches']]);
        }
    }
    if (($g = $run('guest_voice')) !== null) {
        if (!empty($g['reviews']['waiting_for_approval'])) {
            $add(['id' => 'reviews_pending', 'kind' => 'decision', 'severity' => 20 + min(20, $g['reviews']['waiting_for_approval'] * 4), 'title' => 'Reviews waiting for approval', 'count' => $g['reviews']['waiting_for_approval'],
                'detail' => $g['reviews']['waiting_for_approval'] . ' review(s) to publish or reject.', 'link' => ['reviews.php?status=pending', 'Open reviews to approve']]);
        }
        if (!empty($g['reviews']['published_needing_a_reply'])) {
            $add(['id' => 'reviews_reply', 'kind' => 'queue', 'severity' => 15 + min(25, $g['reviews']['published_needing_a_reply'] * 3), 'title' => 'Published reviews with no reply', 'count' => $g['reviews']['published_needing_a_reply'],
                'detail' => $g['reviews']['published_needing_a_reply'] . ' review(s) have no reply from the hotel.',
                'items' => array_slice(array_map(static fn($r) => ['guest_name' => $r['guest_name'], 'text' => $r['rating'] . ' stars: ' . $r['title']], $g['reviews']['needing_a_reply']), 0, 3),
                'link' => ['reviews.php?status=approved&reply=needs', 'Open reviews needing a reply']]);
        }
        $enq = (int)($g['website_messages']['unread'] ?? 0) + (int)($g['event_enquiries_new'] ?? 0) + (int)($g['gym_enquiries_new'] ?? 0) + (int)($g['conference_enquiries_not_confirmed'] ?? 0);
        if ($enq > 0) {
            $parts = [];
            foreach (['website_messages' => 'website message(s) unread', 'event_enquiries_new' => 'event enquiry(ies)', 'gym_enquiries_new' => 'gym enquiry(ies)', 'conference_enquiries_not_confirmed' => 'conference enquiry(ies) not confirmed'] as $key => $label) {
                $n = $key === 'website_messages' ? (int)($g[$key]['unread'] ?? 0) : (int)($g[$key] ?? 0);
                if ($n > 0) {
                    $parts[] = $n . ' ' . $label;
                }
            }
            $oldest = (int)($g['website_messages']['oldest_unread'][0]['waiting_hours'] ?? 0);
            $add(['id' => 'enquiries', 'kind' => 'time', 'severity' => min(55, 15 + $enq * 4 + ($oldest >= 24 ? 15 : 0)), 'title' => 'Enquiries waiting for an answer', 'count' => $enq,
                'detail' => implode(', ', $parts) . '.' . ($oldest > 0 ? ' Oldest unread website message: ' . $oldest . ' hour(s).' : ''),
                'items' => array_slice(array_map(static fn($m) => ['guest_name' => $m['guest_name'], 'text' => $m['subject'] . ' (' . $m['waiting_hours'] . ' h)'], (array)($g['website_messages']['oldest_unread'] ?? [])), 0, 3),
                'link' => [!empty($g['website_messages']['unread']) ? 'contact-inquiries.php?status=new' : ((string)($g['link'] ?? 'dashboard.php')), 'Open enquiries']]);
        }
    }
    if (($gy = $run('gym_watch')) !== null && $gy['members']['expiring_30_days'] > 0) {
        $soon = array_filter($gy['expiring_soon'], static fn($m) => $m['expires'] <= date('Y-m-d', strtotime('+7 days')));
        if ($soon) {
            $add(['id' => 'gym_expiring', 'kind' => 'queue', 'severity' => min(35, 10 + count($soon) * 4), 'title' => 'Gym memberships ending this week', 'count' => count($soon),
                'detail' => count($soon) . ' membership(s) end in the next 7 days; a renewal reminder keeps them.',
                'items' => array_slice(array_map(static fn($m) => ['guest_name' => $m['guest_name'], 'text' => $m['membership'] . ', ends ' . $m['expires']], array_values($soon)), 0, 4),
                'link' => ['gym-members.php?filter=expiring', 'Open expiring members']]);
        }
    }
    if (($ev = $run('events_pipeline', ['days' => 7])) !== null && !empty($ev['conference']['upcoming'])) {
        $unconf = array_filter($ev['conference']['upcoming'], static fn($c) => $c['status'] === 'pending');
        if ($unconf) {
            $add(['id' => 'conf_unconfirmed', 'kind' => 'decision', 'severity' => min(45, 20 + count($unconf) * 8), 'title' => 'Conference bookings this week not confirmed', 'count' => count($unconf),
                'detail' => count($unconf) . ' conference booking(s) in the next 7 days are still pending.',
                'items' => array_slice(array_map(static fn($c) => ['guest_name' => $c['guest_name'], 'text' => $c['date'] . ', ' . $c['attendees'] . ' people'], array_values($unconf)), 0, 3),
                'link' => ['conference-management.php', 'Open Conference']]);
        }
    }

    $w = rh_ai_briefing_weights($lens);
    foreach ($f as &$x) {
        $x['score'] = (int)round((float)($x['severity'] ?? 0) + ($w[$x['kind']] ?? 0));
        unset($x['severity']);
    }
    unset($x);
    usort($f, static fn($a, $b) => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);
    return ['lens' => $lens, 'lens_label' => rh_ai_briefing_lens_label($lens), 'date' => date('Y-m-d'), 'currency' => $cur, 'findings' => array_slice($f, 0, RH_AI_BRIEF_MAX)];
}

/**
 * Front-desk misses: due-outs not checked out (overdue first), arrivals not checked in or with no room,
 * balances to collect before departure (each booking's own balance - never a total), tentative holds
 * about to lapse, rooms not ready for today's arrivals. Same statuses and links as the Bookings screen tabs.
 */
function rh_ai_brief_front_desk(PDO $db, array $user, callable $add): void
{
    $coTime = trim((string)getSetting('check_out_time', '')); // e.g. "11:00 AM" (one default lives in the settings)
    $coTs = $coTime !== '' ? strtotime(date('Y-m-d') . ' ' . $coTime) : false;
    $pastCheckout = $coTs !== false && time() > $coTs;
    $rows = $db->query("SELECT b.id, b.booking_reference, b.guest_name, b.check_out_date, ir.room_number FROM bookings b LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                        WHERE b.deleted_at IS NULL AND b.status = 'checked-in' AND b.check_out_date <= CURDATE() ORDER BY b.check_out_date, ir.room_number LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $overdue = array_values(array_filter($rows, static fn($r) => $r['check_out_date'] < date('Y-m-d')));
    $today = array_values(array_filter($rows, static fn($r) => $r['check_out_date'] === date('Y-m-d')));
    $line = static fn($r, string $t) => ['guest_name' => $r['guest_name'], 'reference' => $r['booking_reference'], 'room' => $r['room_number'] !== null ? (string)$r['room_number'] : null, 'text' => $t, 'booking_id' => (int)$r['id']];
    if ($overdue) {
        $add(['id' => 'missed_checkout', 'kind' => 'time', 'severity' => min(70, 40 + count($overdue) * 10), 'title' => 'Guests past their check-out date, still checked in', 'count' => count($overdue),
            'detail' => count($overdue) . ' booking(s) should have checked out already. Check them out or extend the stay.',
            'items' => array_slice(array_map(static fn($r) => $line($r, 'was due out ' . $r['check_out_date']), $overdue), 0, 5),
            'link' => [count($overdue) === 1 ? 'booking-details.php?id=' . (int)$overdue[0]['id'] : 'bookings.php?status=checked-in', count($overdue) === 1 ? 'Open the booking' : 'Open in-house list']]);
    }
    if ($today) {
        $add(['id' => 'due_out_today', 'kind' => 'time', 'severity' => min(55, ($pastCheckout ? 30 : 10) + count($today) * 4), 'title' => $pastCheckout ? 'Due out today, past check-out time' : 'Due out today, not checked out yet', 'count' => count($today),
            'detail' => count($today) . ' guest(s) still to check out today' . ($pastCheckout ? ' (check-out time ' . $coTime . ' has passed).' : '.'),
            'items' => array_slice(array_map(static fn($r) => $line($r, 'due out today'), $today), 0, 5), 'link' => ['bookings.php?filter=checkout_today', 'Open today\'s check-outs']]);
    }
    // Balances to collect before departure (leaving today or tomorrow): each booking's own folio balance
    $leaving = $db->query("SELECT b.id, b.booking_reference, b.guest_name, b.check_out_date, ir.room_number FROM bookings b LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                           WHERE b.deleted_at IS NULL AND b.status = 'checked-in' AND b.check_out_date <= DATE_ADD(CURDATE(), INTERVAL 1 DAY) ORDER BY b.check_out_date LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
    $owe = [];
    foreach ($leaving as $r) {
        $fo = rh_ai_folio((int)$r['id']);
        if ((float)($fo['balance_due'] ?? 0) > BALANCE_TOLERANCE) {
            $owe[] = ['guest_name' => $r['guest_name'], 'reference' => $r['booking_reference'], 'room' => $r['room_number'] !== null ? (string)$r['room_number'] : null,
                'text' => 'leaves ' . $r['check_out_date'], 'balance_due' => $fo['balance_due'], 'booking_id' => (int)$r['id']];
        }
    }
    if ($owe) {
        $add(['id' => 'collect_before_departure', 'kind' => 'time', 'severity' => min(60, 25 + count($owe) * 8), 'title' => 'Balances to collect before they leave', 'count' => count($owe),
            'detail' => count($owe) . ' guest(s) leaving today or tomorrow still have a balance on their booking.',
            'items' => array_slice(array_map(static fn($o) => array_diff_key($o, ['booking_id' => 1]), $owe), 0, 5),
            'link' => [count($owe) === 1 ? 'booking-details.php?id=' . $owe[0]['booking_id'] : 'bookings.php?filter=checkout_today', count($owe) === 1 ? 'Open the booking' : 'Open today\'s check-outs']]);
    }
    // Arrivals today not checked in, and with no room
    $arr = $db->query("SELECT b.id, b.booking_reference, b.guest_name, b.individual_room_id, ir.room_number, ir.status AS room_status, ir.housekeeping_status
                       FROM bookings b LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
                       WHERE b.deleted_at IS NULL AND b.check_in_date = CURDATE() AND b.status IN ('confirmed', 'pending') ORDER BY b.created_at LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
    $noRoom = array_values(array_filter($arr, static fn($r) => empty($r['individual_room_id'])));
    if ($noRoom) {
        $add(['id' => 'arrival_no_room', 'kind' => 'time', 'severity' => min(60, 30 + count($noRoom) * 8), 'title' => 'Arrivals today with no room assigned', 'count' => count($noRoom),
            'detail' => count($noRoom) . ' arrival(s) today have no room yet.', 'items' => array_slice(array_map(static fn($r) => $line($r, 'arrives today, no room'), $noRoom), 0, 5),
            'link' => ['bookings.php?filter=checkin_today', 'Open today\'s arrivals']]);
    }
    $notReady = array_values(array_filter($arr, static fn($r) => !empty($r['individual_room_id']) && ($r['room_status'] !== 'available' || ($r['housekeeping_status'] !== null && $r['housekeeping_status'] !== 'completed'))));
    if ($notReady) {
        $add(['id' => 'room_not_ready', 'kind' => 'time', 'severity' => min(60, 25 + count($notReady) * 8), 'title' => 'Rooms not ready for today\'s arrivals', 'count' => count($notReady),
            'detail' => count($notReady) . ' arriving guest(s) have a room that is not clean and free yet.',
            'items' => array_slice(array_map(static fn($r) => $line($r, 'room ' . str_replace('_', ' ', (string)$r['room_status']) . ($r['housekeeping_status'] !== 'completed' ? ', cleaning not done' : '')), $notReady), 0, 5),
            'link' => ['housekeeping.php', 'Open Housekeeping']]);
    }
    if (count($arr) > count($noRoom)) {
        $waiting = count($arr);
        $add(['id' => 'arrivals_to_check_in', 'kind' => 'time', 'severity' => min(30, 5 + $waiting * 2), 'title' => 'Arrivals today still to check in', 'count' => $waiting,
            'detail' => $waiting . ' guest(s) due in today have not checked in yet.', 'link' => ['bookings.php?filter=checkin_today', 'Open today\'s arrivals']]);
    }
    $late = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL AND status IN ('confirmed', 'pending') AND check_in_date < CURDATE()")->fetchColumn();
    if ($late > 0) {
        $add(['id' => 'possible_no_shows', 'kind' => 'decision', 'severity' => min(50, 20 + $late * 6), 'title' => 'Earlier arrivals never checked in', 'count' => $late,
            'detail' => $late . ' booking(s) passed their arrival date without checking in: check in, mark no-show or move the dates.',
            'link' => ['bookings.php?arrival=overdue', 'Open overdue arrivals']]);
    }
    $tent = $db->query("SELECT b.booking_reference, b.guest_name, b.tentative_expires_at FROM bookings b WHERE b.deleted_at IS NULL AND b.status = 'tentative' AND b.is_tentative = 1
                        AND b.tentative_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR) ORDER BY b.tentative_expires_at LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if ($tent) {
        $add(['id' => 'tentative_expiring', 'kind' => 'time', 'severity' => min(50, 20 + count($tent) * 8), 'title' => 'Tentative holds lapsing in the next 24 hours', 'count' => count($tent),
            'detail' => count($tent) . ' tentative booking(s) will expire unless confirmed.',
            'items' => array_slice(array_map(static fn($r) => ['guest_name' => $r['guest_name'], 'reference' => $r['booking_reference'], 'text' => 'expires ' . substr((string)$r['tentative_expires_at'], 0, 16)], $tent), 0, 4),
            'link' => ['bookings.php?filter=expiring_soon', 'Open expiring holds']]);
    }
    $pending = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL AND status = 'pending' AND check_in_date >= CURDATE()")->fetchColumn();
    if ($pending > 0) {
        $add(['id' => 'pending_to_confirm', 'kind' => 'decision', 'severity' => min(35, 10 + $pending * 3), 'title' => 'Bookings waiting to be confirmed', 'count' => $pending,
            'detail' => $pending . ' upcoming booking(s) are still pending.', 'link' => ['bookings.php?status=pending', 'Open pending bookings']]);
    }
}

/** Money findings from money_leaks: each with the amount, the cause and who/what drove it. */
function rh_ai_brief_money(array $m, callable $add): void
{
    $cur = (string)$m['currency'];
    $fmt = static fn($v) => ($cur !== '' ? $cur . ' ' : '') . number_format((float)$v, 2);
    $sev = static fn(float $amt) => $amt <= 0 ? 0 : (int)min(60, 10 + 10 * log10($amt + 1));
    $range = $m['from'] === $m['to'] ? $m['from'] : $m['from'] . ' to ' . $m['to'];
    if (!empty($m['still_owed']['left_or_overdue_total']) && (float)$m['still_owed']['left_or_overdue_total'] > BALANCE_TOLERANCE) {
        $o = $m['still_owed'];
        $add(['id' => 'left_owing', 'kind' => 'money', 'severity' => $sev((float)$o['left_or_overdue_total']) + 10, 'title' => 'Money still owed after departure or overdue', 'count' => $o['left_or_overdue_accounts'], 'amount' => $o['left_or_overdue_total'],
            'detail' => $fmt($o['left_or_overdue_total']) . ' on ' . $o['left_or_overdue_accounts'] . ' account(s) that have left or are overdue (all money owed: ' . $fmt($o['total']) . ').',
            'items' => array_slice(array_map(static fn($r) => ['guest_name' => $r['guest_name'], 'reference' => $r['reference'], 'text' => $r['type'] . ', ' . $r['status'] . ', ' . $r['days_outstanding'] . ' day(s)', 'amount' => $r['amount_due']], $o['largest_left_or_overdue']), 0, 4),
            'link' => ['payments.php?balance=outstanding', 'Open money owed']]);
    }
    if (!empty($m['refunds']['total']) && (float)$m['refunds']['total'] > BALANCE_TOLERANCE) {
        $r = $m['refunds'];
        $add(['id' => 'refunds', 'kind' => 'money', 'severity' => $sev((float)$r['total']), 'title' => 'Refunds paid out', 'count' => $r['count'], 'amount' => $r['total'],
            'detail' => $fmt($r['total']) . ' refunded in full on ' . $r['count'] . ' payment(s), ' . $range . '.',
            'items' => array_slice(array_map(static fn($x) => ['reference' => $x['reference'], 'staff_name' => $x['staff_name'], 'text' => ($x['reason'] !== '' ? $x['reason'] : 'no reason given'), 'amount' => $x['amount']], $r['rows']), 0, 4),
            'link' => ['payments.php?refund_status=completed&start_date=' . $m['from'] . '&end_date=' . $m['to'], 'Open refunds']]);
    }
    if (!empty($m['refunds']['waiting_to_be_processed'])) {
        $add(['id' => 'refunds_pending', 'kind' => 'decision', 'severity' => 30, 'title' => 'Refunds waiting to be processed', 'count' => (int)$m['refunds']['waiting_to_be_processed'],
            'detail' => (int)$m['refunds']['waiting_to_be_processed'] . ' refund(s) are pending.', 'link' => ['payments.php?refund_status=pending', 'Open pending refunds']]);
    }
    if (!empty($m['till_voids']['count'])) {
        $v = $m['till_voids'];
        $who = $v['by_staff'][0] ?? null;
        $add(['id' => 'till_voids', 'kind' => 'money', 'severity' => $sev((float)$v['total']), 'title' => 'Till orders voided', 'count' => $v['count'], 'amount' => $v['total'],
            'detail' => $fmt($v['total']) . ' voided on ' . $v['count'] . ' order(s), ' . $range . '.',
            'items' => array_slice(array_map(static fn($x) => ['reference' => $x['reference'], 'staff_name' => $x['staff_name'], 'text' => $x['reason'], 'amount' => $x['amount']], $v['rows']), 0, 3)
                + ($who && $who['voids'] > 1 ? [3 => ['staff_name' => $who['staff_name'], 'text' => 'voided ' . $who['voids'] . ' orders, the most']] : []),
            'link' => ['stock-orders.php?status=voided', 'Open voided orders']]);
    }
    if (!empty($m['till_discounts']['count'])) {
        $d = $m['till_discounts'];
        $add(['id' => 'till_discounts', 'kind' => 'money', 'severity' => max(0, $sev((float)$d['total']) - 10), 'title' => 'Discounts given at the till', 'count' => $d['count'], 'amount' => $d['total'],
            'detail' => $fmt($d['total']) . ' in discounts on ' . $d['count'] . ' paid order(s), ' . $range . '.',
            'items' => array_slice(array_map(static fn($x) => ['text' => $x['reason'] . ' (' . (int)$x['n'] . ')', 'amount' => rh_ai_money((float)$x['v'])], $d['by_reason']), 0, 3),
            'link' => ['pos-accounting.php', 'Open POS Accounting']]);
    }
    if (!empty($m['till_shortages']['count'])) {
        $s = $m['till_shortages'];
        $add(['id' => 'till_shortages', 'kind' => 'money', 'severity' => $sev((float)$s['total']) + 5, 'title' => 'Tills closed short', 'count' => $s['count'], 'amount' => $s['total'],
            'detail' => $fmt($s['total']) . ' short across ' . $s['count'] . ' shift close(s), ' . $range . '.',
            'items' => array_slice(array_map(static fn($x) => ['staff_name' => $x['staff_name'], 'text' => $x['shift_date'] . ($x['closed_with_override'] ? ', closed with override' : ''), 'amount' => $x['short_by']], $s['rows']), 0, 4),
            'link' => ['shift-close-report.php', 'Open shift closes']]);
    }
    if (!empty($m['folio_charges_voided']['count'])) {
        $c = $m['folio_charges_voided'];
        $add(['id' => 'folio_voids', 'kind' => 'money', 'severity' => max(0, $sev((float)$c['total']) - 10), 'title' => 'Room bill charges removed', 'count' => $c['count'], 'amount' => $c['total'],
            'detail' => $fmt($c['total']) . ' in charges voided on guest bills, ' . $range . '.', 'link' => ['bookings.php', 'Open Bookings']]);
    }
    if (!empty($m['wastage']['entries'])) {
        $w = $m['wastage'];
        $add(['id' => 'wastage', 'kind' => 'money', 'severity' => $sev((float)$w['total_cost']), 'title' => 'Stock wasted', 'count' => $w['entries'], 'amount' => $w['total_cost'],
            'detail' => $fmt($w['total_cost']) . ' of stock written off as waste, ' . $range . '.',
            'items' => array_slice(array_map(static fn($x) => ['text' => $x['reason'], 'amount' => rh_ai_money((float)$x['v'])], $w['by_reason']), 0, 3), 'link' => ['stock-wastage.php', 'Open Wastage']]);
    }
    if (!empty($m['stock_count_shortages']['total_cost']) && (float)$m['stock_count_shortages']['total_cost'] > BALANCE_TOLERANCE) {
        $s = $m['stock_count_shortages'];
        $add(['id' => 'count_shortage', 'kind' => 'money', 'severity' => $sev((float)$s['total_cost']) + 5, 'title' => 'Stock missing at count', 'count' => $s['counts'], 'amount' => $s['total_cost'],
            'detail' => $fmt($s['total_cost']) . ' less stock than the system expected, on ' . $s['counts'] . ' approved count(s), ' . $range . '.', 'link' => ['stock-count.php', 'Open Stock Count']]);
    }
    if (!empty($m['no_shows']['count'])) {
        $n = $m['no_shows'];
        $add(['id' => 'no_shows', 'kind' => 'money', 'severity' => max(5, $sev((float)$n['booked_value']) - 5), 'title' => 'No-shows', 'count' => $n['count'], 'amount' => $n['booked_value'],
            'detail' => $n['count'] . ' no-show(s) worth ' . $fmt($n['booked_value']) . ' booked, ' . $range . '.', 'link' => ['bookings.php?status=no-show', 'Open no-shows']]);
    }
    if (!empty($m['cancellations']['count'])) {
        $add(['id' => 'cancellations', 'kind' => 'money', 'severity' => min(30, 5 + $m['cancellations']['count'] * 3), 'title' => 'Cancellations', 'count' => $m['cancellations']['count'],
            'detail' => $m['cancellations']['count'] . ' booking(s) cancelled, ' . $range . '.', 'link' => ['bookings.php?status=cancelled', 'Open cancellations']]);
    }
    if (!empty($m['stock_budget_this_month']) && in_array($m['stock_budget_this_month']['status'], ['over', 'at_risk', 'warn', 'overspent'], true)) {
        $b = $m['stock_budget_this_month'];
        $add(['id' => 'budget', 'kind' => 'money', 'severity' => 35, 'title' => 'Stock buying over budget', 'amount' => $b['used'],
            'detail' => $b['pct_used'] . '% of this month\'s stock budget used with ' . $b['month_gone_pct'] . '% of the month gone (' . $fmt($b['used']) . ' of ' . $fmt($b['budget']) . ').', 'link' => ['stock-budget.php', 'Open Stock Budget']]);
    }
}

/** One finding line as text (from the de-identified item, so stored text holds tokens only). */
function rh_ai_brief_line(array $it, string $cur): string
{
    $p = [];
    foreach (['guest_name', 'reference', 'staff_name'] as $k) {
        if (!empty($it[$k])) {
            $p[] = (string)$it[$k];
        }
    }
    if (!empty($it['room'])) {
        $p[] = 'room ' . $it['room'];
    }
    if (!empty($it['text'])) {
        $p[] = (string)$it['text'];
    }
    foreach (['amount', 'balance_due'] as $k) {
        if (isset($it[$k]) && $it[$k] !== null) {
            $p[] = ($k === 'balance_due' ? 'owes ' : '') . ($cur !== '' ? $cur . ' ' : '') . number_format((float)$it[$k], 2);
        }
    }
    return implode(' · ', $p);
}
