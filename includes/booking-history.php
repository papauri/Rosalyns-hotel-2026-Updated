<?php
/**
 * Booking / payment history: reads every record that says what happened to a booking or a payment and hands the rows
 * to the pure builder in includes/booking-history-format.php. Read-only: plain SELECTs, no table is created here; a
 * table this install does not have is skipped and the rest still show.
 *
 *   rh_booking_history(PDO $pdo, int $bookingId, array $opts = []): array   step-by-step list, oldest first
 *   rh_payment_history(PDO $pdo, int $paymentId): array                     the payment, its refunds and credit notes,
 *                                                                           and its booking's money steps
 *   rh_history_payload(PDO $pdo, string $type, int $id): array              the History panel's data (header + steps)
 *
 * Each step: at (hotel time), at_label, who, what, detail, kind, kind_label. Times come through the app's $pdo, which
 * reads TIMESTAMP and DATETIME columns in hotel time.
 */

require_once __DIR__ . '/booking-history-format.php';
require_once __DIR__ . '/booked-rate.php';

if (!function_exists('rh_hist_tables')) {
    /** Tables this database has (one lookup per request). */
    function rh_hist_tables(PDO $pdo): array
    {
        static $cache = [];
        $key = spl_object_id($pdo);
        if (!isset($cache[$key])) {
            $cache[$key] = [];
            try {
                foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN) as $t) {
                    $cache[$key][strtolower((string)$t)] = true;
                }
            } catch (Throwable $e) {
            }
        }
        return $cache[$key];
    }

    function rh_hist_has(PDO $pdo, string $table): bool
    {
        return isset(rh_hist_tables($pdo)[strtolower($table)]);
    }

    /** Run a read; any problem (missing table or column) gives no rows. */
    function rh_hist_rows(PDO $pdo, string $table, string $sql, array $args = []): array
    {
        if ($table !== '' && !rh_hist_has($pdo, $table)) {
            return [];
        }
        try {
            $st = $pdo->prepare($sql);
            $st->execute($args);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('booking-history read (' . $table . '): ' . $e->getMessage());
            return [];
        }
    }

    function rh_hist_in(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return [$ids ? implode(',', array_fill(0, count($ids), '?')) : '', $ids];
    }

    function rh_hist_staff(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        foreach (rh_hist_rows($pdo, 'admin_users', 'SELECT id, username, full_name, role FROM admin_users LIMIT 1000') as $r) {
            $name = trim((string)($r['full_name'] ?? '')) !== '' ? trim((string)$r['full_name']) : (string)$r['username'];
            $cache[(int)$r['id']] = ['name' => $name, 'username' => (string)$r['username'], 'role' => (string)($r['role'] ?? '')];
        }
        return $cache;
    }

    function rh_hist_currency(): string
    {
        try {
            $c = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
        } catch (Throwable $e) {
            $c = 'MWK';
        }
        return $c !== '' ? $c : 'MWK';
    }

    /** id => name lookups shared by bookings: room numbers, room types, joined rooms. */
    function rh_hist_names(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = ['room_numbers' => [], 'room_types' => [], 'combo_names' => []];
        foreach (rh_hist_rows($pdo, 'individual_rooms', 'SELECT id, room_number FROM individual_rooms LIMIT 2000') as $r) {
            $cache['room_numbers'][(int)$r['id']] = (string)$r['room_number'];
        }
        foreach (rh_hist_rows($pdo, 'rooms', 'SELECT id, name FROM rooms LIMIT 500') as $r) {
            $cache['room_types'][(int)$r['id']] = (string)$r['name'];
        }
        foreach (rh_hist_rows($pdo, 'room_combinations', 'SELECT id, combined_name FROM room_combinations LIMIT 1000') as $r) {
            $cache['combo_names'][(int)$r['id']] = (string)$r['combined_name'];
        }
        return $cache;
    }

    /** The price snapshot saved at creation (create-booking.php, since 2026-11-09), or null for older bookings. */
    function rh_hist_snapshot(array $audit, array $timeline): ?array
    {
        foreach ($audit as $r) {
            if ((string)($r['action'] ?? '') === 'created') {
                $n = rh_hist_json($r['new_values'] ?? null);
                if (!empty($n['price']) && is_array($n['price'])) return $n['price'];
            }
        }
        foreach ($timeline as $r) {
            if ((string)($r['action_type'] ?? '') === 'create') {
                $m = rh_hist_json($r['metadata'] ?? null);
                if (!empty($m['price']) && is_array($m['price'])) return $m['price'];
            }
        }
        return null;
    }

    /** Every row about one room booking, ready for rh_hist_build(). $light: skip the slower system-event search. */
    function rh_hist_booking_sources(PDO $pdo, int $bookingId, bool $light = false): ?array
    {
        $b = rh_hist_rows($pdo, 'bookings', "SELECT b.*, r.name AS rh_room_type_name, r.price_per_night AS rh_pp_night,
                r.price_single_occupancy AS rh_pp_single, r.price_double_occupancy AS rh_pp_double, r.price_triple_occupancy AS rh_pp_triple
            FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?", [$bookingId]);
        if (!$b) return null;
        $b = $b[0];
        $names = rh_hist_names($pdo);
        $src = $names + ['booking' => $b, 'staff' => rh_hist_staff($pdo), 'cur' => rh_hist_currency(), 'room_type_name' => (string)($b['rh_room_type_name'] ?? '')];

        // Today's price for this room and occupancy (same choice as create-booking.php), or the joined-rooms price:
        // one shared lookup (includes/booked-rate.php) so the payment screens show the same figure.
        $combo = null;
        $comboId = (int)($b['room_combination_id'] ?? 0);
        if ($comboId > 0) {
            $c = rh_hist_rows($pdo, 'room_combinations', 'SELECT combined_name, price_override FROM room_combinations WHERE id = ?', [$comboId]);
            if ($c) {
                $combo = $c[0];
                $src['combo_name'] = (string)$c[0]['combined_name'];
            }
        }
        $src['today_price'] = rh_room_today_price($b, $combo);

        $src['audit'] = rh_hist_rows($pdo, 'booking_audit_log', 'SELECT * FROM booking_audit_log WHERE booking_id = ? ORDER BY id LIMIT 500', [$bookingId]);
        $src['timeline'] = rh_hist_rows($pdo, 'booking_timeline_logs', 'SELECT * FROM booking_timeline_logs WHERE booking_id = ? ORDER BY id LIMIT 500', [$bookingId]);
        $src['snapshot'] = rh_hist_snapshot($src['audit'], $src['timeline']);
        $src['status_log'] = rh_hist_rows($pdo, 'booking_status_log', 'SELECT * FROM booking_status_log WHERE booking_id = ? ORDER BY id LIMIT 300', [$bookingId]);
        $src['notes'] = rh_hist_rows($pdo, 'booking_notes', 'SELECT * FROM booking_notes WHERE booking_id = ? LIMIT 300', [$bookingId]);
        $src['payments'] = rh_hist_rows($pdo, 'payments', "SELECT * FROM payments WHERE booking_type = 'room' AND booking_id = ? ORDER BY id LIMIT 500", [$bookingId]);

        $payIds = [];
        $legCn = [];
        foreach ($src['payments'] as $p) {
            $payIds[] = (int)$p['id'];
            if (!empty($p['credit_note_id'])) $legCn[] = (int)$p['credit_note_id'];
        }
        [$ph, $pa] = rh_hist_in($payIds);
        $src['payment_audit'] = $ph === '' ? [] : rh_hist_rows($pdo, 'payment_audit_log', "SELECT * FROM payment_audit_log WHERE payment_id IN ($ph) ORDER BY id LIMIT 1000", $pa);
        [$ch, $ca] = rh_hist_in($legCn);
        $src['credit_notes'] = rh_hist_rows($pdo, 'credit_notes', "SELECT * FROM credit_notes WHERE (booking_type = 'room' AND booking_id = ?)" . ($ch !== '' ? " OR id IN ($ch)" : '') . ' ORDER BY id LIMIT 200',
            array_merge([$bookingId], $ca));
        $cnIds = array_map(function ($r) { return (int)$r['id']; }, $src['credit_notes']);
        [$nh, $na] = rh_hist_in($cnIds);
        $src['cn_apps'] = rh_hist_rows($pdo, 'credit_note_applications', "SELECT * FROM credit_note_applications WHERE (applied_to_booking_type = 'room' AND applied_to_booking_id = ?)"
            . ($nh !== '' ? " OR credit_note_id IN ($nh)" : '') . ' ORDER BY id LIMIT 300', array_merge([$bookingId], $na));
        // A note used on this booking but issued elsewhere still needs its number.
        $missing = array_diff(array_map(function ($r) { return (int)$r['credit_note_id']; }, $src['cn_apps']), $cnIds);
        if ($missing) {
            [$mh, $ma] = rh_hist_in($missing);
            foreach (rh_hist_rows($pdo, 'credit_notes', "SELECT id, credit_note_number FROM credit_notes WHERE id IN ($mh)", $ma) as $r) {
                $src['cn_numbers'][(int)$r['id']] = (string)$r['credit_note_number'];
            }
        }
        $src['cancellations'] = rh_hist_rows($pdo, 'cancellation_log', "SELECT * FROM cancellation_log WHERE booking_id = ? AND (booking_type IS NULL OR booking_type = 'room') LIMIT 50", [$bookingId]);
        $src['comms'] = rh_hist_rows($pdo, 'guest_communication_log', 'SELECT * FROM guest_communication_log WHERE booking_id = ? LIMIT 50', [$bookingId]);
        $src['auto_emails'] = rh_hist_rows($pdo, 'automated_email_log', "SELECT * FROM automated_email_log WHERE account_type = 'room' AND account_id = ? LIMIT 100", [$bookingId]);
        $src['tentative'] = rh_hist_rows($pdo, 'tentative_booking_log', 'SELECT * FROM tentative_booking_log WHERE booking_id = ? LIMIT 100', [$bookingId]);
        $src['booking_rooms'] = rh_hist_rows($pdo, 'booking_rooms', 'SELECT * FROM booking_rooms WHERE booking_id = ? LIMIT 50', [$bookingId]);
        $src['financial_audit'] = rh_hist_rows($pdo, 'booking_financial_audit', 'SELECT * FROM booking_financial_audit WHERE booking_id = ? LIMIT 300', [$bookingId]);

        if (!$light && !empty($b['created_at'])) {
            // Problems recorded against this booking id (the log has no booking column: bounded by time and level).
            $src['system_events'] = rh_hist_rows($pdo, 'system_event_log', "SELECT id, message, user_id, username, created_at FROM system_event_log
                WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 120 DAY) AND level IN ('warning','error','critical')
                  AND (context_json LIKE ? OR context_json LIKE ? OR context_json LIKE ?) ORDER BY id LIMIT 20",
                // Exact id only (rh_log_event writes json_encode(context)): "booking_id":ID followed by , or }, or the quoted
                // string form. A bare prefix match would also pick up ids 120, 1234 ... for booking 12.
                [$b['created_at'], $b['created_at'], '%"booking_id":' . (int)$bookingId . ',%', '%"booking_id":' . (int)$bookingId . '}%',
                    '%"booking_id":"' . (int)$bookingId . '"%']);
        }

        // Price changes for this room since it was booked (room_price_history, migration 077).
        $src['entity_names'] = ['room_type|' . (int)$b['room_id'] => (string)($b['rh_room_type_name'] ?? '')];
        if ($comboId > 0) $src['entity_names']['room_combination|' . $comboId] = (string)($src['combo_name'] ?? '');
        $rpId = (int)($b['rate_plan_id'] ?? 0);
        if ($rpId > 0) $src['entity_names']['rate_plan|' . $rpId] = (string)($b['rate_plan_label'] ?? ('#' . $rpId));
        $hist = !empty($b['created_at']) ? rh_hist_rows($pdo, 'room_price_history', "SELECT * FROM room_price_history WHERE changed_at >= ? AND (
                (entity_type = 'room_type' AND entity_id = ? AND field IN ('price_per_night','price_single_occupancy','price_double_occupancy','price_triple_occupancy'))
                OR (entity_type = 'room_combination' AND entity_id = ?) OR (entity_type = 'rate_plan' AND entity_id = ?))
            ORDER BY changed_at, id LIMIT 50", [$b['created_at'], (int)$b['room_id'], $comboId, $rpId]) : [];
        $src['price_history'] = $hist;
        $src['price_changes'] = array_values(array_filter($hist, function ($r) use ($comboId, $field) {
            if ($comboId > 0) return $r['entity_type'] === 'room_combination' && $r['field'] === 'price_override';
            return $r['entity_type'] === 'room_type' && $r['field'] === $field;
        }));
        return $src;
    }

    function rh_booking_history(PDO $pdo, int $bookingId, array $opts = []): array
    {
        try {
            $src = rh_hist_booking_sources($pdo, $bookingId, !empty($opts['light']));
            return $src ? rh_hist_build($src) : [];
        } catch (Throwable $e) {
            error_log('rh_booking_history: ' . $e->getMessage());
            return [];
        }
    }

    /** The payment row, the payment it refunds (when it is a refund) and the ids that belong to it. */
    function rh_hist_payment_anchor(PDO $pdo, int $paymentId): ?array
    {
        $p = rh_hist_rows($pdo, 'payments', 'SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$p) return null;
        $p = $p[0];
        $anchorId = ((string)($p['payment_type'] ?? '') === 'refund' && (int)($p['original_payment_id'] ?? 0) > 0) ? (int)$p['original_payment_id'] : $paymentId;
        $rows = rh_hist_rows($pdo, 'payments', 'SELECT * FROM payments WHERE id IN (?, ?) OR original_payment_id = ? ORDER BY id LIMIT 300', [$paymentId, $anchorId, $anchorId]);
        return ['payment' => $p, 'anchor_id' => $anchorId, 'rows' => $rows, 'ids' => array_map(function ($r) { return (int)$r['id']; }, $rows)];
    }

    function rh_payment_history(PDO $pdo, int $paymentId): array
    {
        try {
            $a = rh_hist_payment_anchor($pdo, $paymentId);
            if (!$a) return [];
            $p = $a['payment'];
            if ((string)($p['booking_type'] ?? '') === 'room' && (int)($p['booking_id'] ?? 0) > 0) {
                $src = rh_hist_booking_sources($pdo, (int)$p['booking_id'], true);
                if ($src) {
                    return rh_hist_payment_filter(rh_hist_build($src), $a['ids']);
                }
            }
            // Other accounts (conference, gym, event, restaurant): the payment, its refunds and their credit notes.
            [$ih, $ia] = rh_hist_in($a['ids']);
            $legCn = [];
            foreach ($a['rows'] as $r) if (!empty($r['credit_note_id'])) $legCn[] = (int)$r['credit_note_id'];
            [$ch, $ca] = rh_hist_in($legCn);
            $anchorRef = '';
            foreach ($a['rows'] as $r) if ((int)$r['id'] === $a['anchor_id']) $anchorRef = (string)$r['payment_reference'];
            $src = rh_hist_names($pdo) + ['staff' => rh_hist_staff($pdo), 'cur' => rh_hist_currency(), 'payments' => $a['rows']];
            $src['payment_audit'] = rh_hist_rows($pdo, 'payment_audit_log', "SELECT * FROM payment_audit_log WHERE payment_id IN ($ih) ORDER BY id LIMIT 500", $ia);
            $src['credit_notes'] = rh_hist_rows($pdo, 'credit_notes', 'SELECT * FROM credit_notes WHERE original_payment_id = ?'
                . ($ch !== '' ? " OR id IN ($ch)" : '') . ($anchorRef !== '' ? ' OR reason_notes LIKE ?' : '') . ' ORDER BY id LIMIT 50',
                array_merge([$a['anchor_id']], $ca, $anchorRef !== '' ? ['%on payment ' . $anchorRef . '%'] : []));
            return rh_hist_build($src);
        } catch (Throwable $e) {
            error_log('rh_payment_history: ' . $e->getMessage());
            return [];
        }
    }

    /** The History panel: a header (what this is) and the steps. Unknown id -> found false. */
    function rh_history_payload(PDO $pdo, string $type, int $id): array
    {
        $cur = rh_hist_currency();
        if ($type === 'booking') {
            $b = rh_hist_rows($pdo, 'bookings', 'SELECT b.id, b.booking_reference, b.guest_name, b.check_in_date, b.check_out_date, b.number_of_nights,
                    b.total_amount, b.total_with_vat, b.vat_amount, b.status, r.name AS room_type_name
                FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?', [$id]);
            if (!$b) return ['found' => false];
            $b = $b[0];
            return ['found' => true, 'title' => 'Booking ' . $b['booking_reference'], 'facts' => [
                ['label' => 'Guest', 'value' => (string)$b['guest_name'], 'help' => 'The name the booking is under.'],
                ['label' => 'Room', 'value' => (string)($b['room_type_name'] ?? ''), 'help' => 'The room type booked.'],
                ['label' => 'Stay', 'value' => (strtotime((string)$b['check_in_date']) ? date('j M', strtotime((string)$b['check_in_date'])) . ' to ' . date('j M Y', strtotime((string)$b['check_out_date'])) : '') . ' (' . (int)$b['number_of_nights'] . ' nights)', 'help' => 'Check-in to check-out date, and the number of nights.'],
                ['label' => 'Total', 'value' => rh_hist_money(rh_hist_full_total($b), $cur), 'help' => 'The full amount for the stay as saved on the booking: room, VAT and tourism levy. Extras on the bill are not included.'],
                ['label' => 'Status now', 'value' => rh_hist_words((string)$b['status']), 'help' => 'Where the booking stands today.'],
            ], 'events' => rh_booking_history($pdo, $id)];
        }
        $a = rh_hist_payment_anchor($pdo, $id);
        if (!$a) return ['found' => false];
        $p = $a['payment'];
        $isRefund = (string)($p['payment_type'] ?? '') === 'refund';
        $amt = $isRefund ? (float)rh_hist_col($p, ['refund_amount', 'total_amount'], 0) : (float)($p['total_amount'] ?? 0);
        return ['found' => true, 'title' => ($isRefund ? 'Refund ' : 'Payment ') . $p['payment_reference'], 'facts' => [
            ['label' => $isRefund ? 'Refund amount' : 'Amount', 'value' => rh_hist_money($amt, $cur), 'help' => $isRefund ? 'The money this refund gives back, in full.' : 'The full amount received on this payment, VAT included.'],
            ['label' => 'Method', 'value' => rh_hist_words((string)($p['payment_method'] ?? '')), 'help' => 'How the money was paid or given back.'],
            ['label' => 'Account', 'value' => rh_hist_words((string)($p['booking_type'] ?? '')) . ' ' . (string)($p['booking_reference'] ?? ''), 'help' => 'The booking or account this payment belongs to.'],
            ['label' => 'Status now', 'value' => rh_hist_words((string)($p['payment_status'] ?? '')), 'help' => 'Where this payment stands today.'],
        ], 'events' => rh_payment_history($pdo, $id)];
    }
}
