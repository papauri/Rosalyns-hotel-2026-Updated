<?php
/**
 * Booking / payment history: the pure part (no database, no settings, no requires).
 *
 * includes/booking-history.php reads the rows; everything here turns rows into one step-by-step list:
 * each event is at (local 'Y-m-d H:i:s'), who, what (one plain sentence), detail (optional, may hold several
 * lines), kind. Unit tested by scripts/tests/booking_history_test.php with fixture rows.
 *
 * Kinds: created / price / payment / refund / credit_note / status / room / email / note / check_in /
 * check_out / cancel / edit.
 */

if (!function_exists('rh_hist_tol')) {
    function rh_hist_tol(): float
    {
        return defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
    }

    /** "K356,000" / "MWK 356,000.50": decimals only when there are tambala. */
    function rh_hist_money($amount, string $cur): string
    {
        $a = round((float)$amount, 2);
        $neg = $a < 0;
        $a = abs($a);
        $num = abs($a - round($a)) > 0.004 ? number_format($a, 2) : number_format($a, 0);
        $sep = (strlen($cur) > 1 && ctype_alpha($cur)) ? ' ' : '';
        return ($neg ? '-' : '') . $cur . $sep . $num;
    }

    /** '2026-11-07 09:36:12' -> '7 Nov 2026, 09:36'. Times are already hotel-local. */
    function rh_hist_time_label(string $at): string
    {
        $ts = strtotime($at);
        return $ts ? date('j M Y, H:i', $ts) : $at;
    }

    function rh_hist_words(string $code): string
    {
        $code = trim(str_replace(['_', '-'], ' ', $code));
        return $code === '' ? '' : ucfirst(strtolower($code));
    }

    function rh_hist_clip($v, int $len = 120): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string)$v));
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
    }

    /** Status word for sentences: 'checked-in' -> 'checked in'. */
    function rh_hist_status_word(string $s): string
    {
        $map = ['checked-in' => 'checked in', 'checked_in' => 'checked in', 'checked-out' => 'checked out', 'checked_out' => 'checked out',
            'no-show' => 'no-show', 'no_show' => 'no-show'];
        $k = strtolower(trim($s));
        return $map[$k] ?? str_replace('_', ' ', $k);
    }

    /** 'checked_in' / 'Checked-In' -> 'checked-in' (one spelling for de-duplication). */
    function rh_hist_status_norm(string $s): string
    {
        return str_replace('_', '-', strtolower(trim($s)));
    }

    /** The kind a status change belongs to. */
    function rh_hist_status_kind(string $status): string
    {
        $n = rh_hist_status_norm($status);
        if ($n === 'cancelled' || $n === 'expired') return 'cancel';
        if ($n === 'checked-in') return 'check_in';
        if ($n === 'checked-out') return 'check_out';
        return 'status';
    }

    function rh_hist_kind_order(string $kind): int
    {
        $o = ['created' => 0, 'price' => 1, 'room' => 2, 'status' => 3, 'check_in' => 4, 'edit' => 5, 'payment' => 6,
            'credit_note' => 7, 'refund' => 8, 'email' => 9, 'note' => 10, 'check_out' => 11, 'cancel' => 12];
        return $o[$kind] ?? 13;
    }

    function rh_hist_kind_label(string $kind): string
    {
        $l = ['created' => 'Booked', 'price' => 'Price', 'payment' => 'Payment', 'refund' => 'Refund', 'credit_note' => 'Credit note',
            'status' => 'Status', 'room' => 'Room', 'email' => 'Email', 'note' => 'Note', 'check_in' => 'Check-in',
            'check_out' => 'Check-out', 'cancel' => 'Cancelled', 'edit' => 'Change'];
        return $l[$kind] ?? 'Other';
    }

    /**
     * Who did it: "Full Name (role)", or Guest / System / Not recorded.
     * $staff: id => ['name' => ..., 'role' => ...]. $type: performed_by_type when the source has one.
     */
    function rh_hist_who(array $staff, $id, $name = null, ?string $type = null): string
    {
        $id = (int)$id;
        $type = strtolower(trim((string)$type));
        if ($id > 0 && isset($staff[$id])) {
            $role = trim((string)($staff[$id]['role'] ?? ''));
            return $staff[$id]['name'] . ($role !== '' ? ' (' . str_replace('_', ' ', $role) . ')' : '');
        }
        $name = trim((string)$name);
        if ($type === 'guest') {
            return $name !== '' ? 'Guest (' . $name . ')' : 'Guest';
        }
        if ($type === 'system' || $type === 'cron' || $type === 'scheduler') {
            return 'System';
        }
        if ($type === 'api') {
            return $name !== '' ? $name . ' (online booking)' : 'Online booking';
        }
        if ($name !== '') {
            // A stored name that matches a staff member gets the role too.
            foreach ($staff as $s) {
                if (strcasecmp((string)$s['name'], $name) === 0 || strcasecmp((string)($s['username'] ?? ''), $name) === 0) {
                    $role = trim((string)($s['role'] ?? ''));
                    return $s['name'] . ($role !== '' ? ' (' . str_replace('_', ' ', $role) . ')' : '');
                }
            }
            return $name;
        }
        return $id > 0 ? 'Staff member #' . $id : 'Not recorded';
    }

    /** One event. $keys: de-duplication keys; $rank: the richer source wins (3 = the money/booking record itself). */
    function rh_hist_ev(?string $at, string $who, string $what, string $detail, string $kind, array $keys, int $rank, array $pids = []): ?array
    {
        $at = trim((string)$at);
        if ($at === '' || strpos($at, '0000-00-00') === 0) {
            return null;
        }
        $ts = strtotime($at);
        if (!$ts) {
            return null;
        }
        return ['at' => date('Y-m-d H:i:s', $ts), 'ts' => $ts, 'who' => $who, 'what' => $what, 'detail' => trim($detail),
            'kind' => $kind, 'keys' => array_values(array_unique(array_filter($keys, 'strlen'))), 'rank' => $rank,
            'pids' => array_values(array_unique(array_map('intval', array_filter($pids))))];
    }

    function rh_hist_json($raw): array
    {
        if (is_array($raw)) return $raw;
        $d = json_decode((string)$raw, true);
        return is_array($d) ? $d : [];
    }

    /** First present column of a row (schemas differ between installs). */
    function rh_hist_col(array $row, array $names, $default = null)
    {
        foreach ($names as $n) {
            if (array_key_exists($n, $row) && $row[$n] !== null && $row[$n] !== '') {
                return $row[$n];
            }
        }
        return $default;
    }

    /** First money figure in a sentence ("Full payment of 1,068,000.00 via cash") or null. */
    function rh_hist_parse_amount(string $text): ?float
    {
        if (preg_match('/(\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+\.\d{2}|\d{4,})/', $text, $m)) {
            return (float)str_replace(',', '', $m[1]);
        }
        return null;
    }

    function rh_hist_amt_key($amount): string
    {
        return 'amt:' . (string)(int)round((float)$amount);
    }

    // ------------------------------------------------------------------ field changes

    function rh_hist_field_label(string $f): string
    {
        $l = ['check_in_date' => 'Check-in date', 'check_out_date' => 'Check-out date', 'number_of_nights' => 'Nights',
            'number_of_guests' => 'Guests', 'adult_guests' => 'Adults', 'child_guests' => 'Children', 'occupancy_type' => 'Occupancy',
            'individual_room_id' => 'Room', 'room_id' => 'Room type', 'total_with_vat' => 'Total', 'total_amount' => 'Total before VAT',
            'guest_name' => 'Guest name', 'room_guest_name' => 'Name on the room', 'guest_email' => 'Email', 'guest_phone' => 'Phone',
            'guest_country' => 'Country', 'special_requests' => 'Special requests', 'status' => 'Status', 'payment_status' => 'Payment status',
            'admin_note' => 'Staff note', 'price_override' => 'Price set by hand', 'amount_paid' => 'Paid', 'amount_due' => 'Owed',
            'tentative_expires_at' => 'Hold expires', 'is_tentative' => 'On hold', 'payment_date' => 'Payment date',
            'payment_method' => 'Method', 'payment_status ' => 'Status', 'transaction_reference' => 'Transaction reference',
            'notes' => 'Notes', 'vat_amount' => 'VAT', 'tourism_levy_amount' => 'Tourism levy', 'child_supplement_total' => 'Children charge',
            'room_combination_id' => 'Joined rooms', 'refund_status' => 'Refund status', 'refund_amount' => 'Refund amount'];
        return $l[$f] ?? rh_hist_words($f);
    }

    function rh_hist_is_money_field(string $f): bool
    {
        return in_array($f, ['total_with_vat', 'total_amount', 'amount_paid', 'amount_due', 'price_override', 'vat_amount',
            'tourism_levy_amount', 'child_supplement_total', 'refund_amount', 'payment_amount', 'amount'], true);
    }

    function rh_hist_field_value(string $f, $v, array $ctx): string
    {
        if ($v === null || $v === '') return '(blank)';
        if (is_array($v)) return rh_hist_clip(json_encode($v), 80);
        if (rh_hist_is_money_field($f) && is_numeric($v)) return rh_hist_money((float)$v, $ctx['cur']);
        if ($f === 'individual_room_id') {
            $rn = $ctx['room_numbers'][(int)$v] ?? null;
            return $rn !== null ? 'Room ' . $rn : 'Room #' . (int)$v;
        }
        if ($f === 'room_id') {
            return (string)($ctx['room_types'][(int)$v] ?? ('Room type #' . (int)$v));
        }
        if (in_array($f, ['status', 'payment_status', 'occupancy_type', 'payment_method', 'refund_status'], true)) {
            return rh_hist_words((string)$v);
        }
        if (in_array($f, ['check_in_date', 'check_out_date', 'payment_date'], true) && strtotime((string)$v)) {
            return date('j M Y', strtotime((string)$v));
        }
        return rh_hist_clip($v, 80);
    }

    /** "Check-in date: 7 Nov 2026 → 8 Nov 2026" lines for every field that changed. */
    function rh_hist_changes(array $old, array $new, array $ctx): array
    {
        $lines = [];
        foreach ($new as $f => $nv) {
            $f = (string)$f;
            if ($f === 'price' || preg_match('/pass|token|secret|card|cvv|pin\b/i', $f)) continue;
            $ov = $old[$f] ?? null;
            if (is_scalar($ov) && is_scalar($nv) && is_numeric($ov) && is_numeric($nv) && abs((float)$ov - (float)$nv) <= rh_hist_tol()) continue;
            if (!is_array($ov) && !is_array($nv) && (string)$ov === (string)$nv) continue;
            $lines[] = rh_hist_field_label($f) . ': ' . rh_hist_field_value($f, $ov, $ctx) . ' → ' . rh_hist_field_value($f, $nv, $ctx);
        }
        return $lines;
    }

    // ------------------------------------------------------------------ booking summary

    /** The full amount the guest pays for the stay (room + VAT + levy), as rh_booking_full_total(). */
    function rh_hist_full_total(array $b): float
    {
        $full = (float)($b['total_with_vat'] ?? 0);
        if ($full > rh_hist_tol()) return round($full, 2);
        return round((float)($b['total_amount'] ?? 0) + (float)($b['vat_amount'] ?? 0), 2);
    }

    function rh_hist_booking_line(array $b, array $ctx): string
    {
        $n = (int)($b['number_of_nights'] ?? 0);
        $parts = [];
        $room = trim((string)($ctx['room_type_name'] ?? ''));
        if ($room !== '') $parts[] = $room;
        if ($n > 0) $parts[] = $n . ' night' . ($n === 1 ? '' : 's');
        $ci = (string)($b['check_in_date'] ?? '');
        $co = (string)($b['check_out_date'] ?? '');
        if (strtotime($ci) && strtotime($co)) $parts[] = date('j M', strtotime($ci)) . ' to ' . date('j M Y', strtotime($co));
        $g = (int)($b['number_of_guests'] ?? 0);
        if ($g > 0) $parts[] = $g . ' guest' . ($g === 1 ? '' : 's');
        $line = implode(', ', $parts);
        return ($line !== '' ? $line . '. ' : '') . 'Total ' . rh_hist_money(rh_hist_full_total($b), $ctx['cur']) . '.';
    }

    // ------------------------------------------------------------------ sources -> events

    /** booking_audit_log rows. */
    function rh_hist_from_audit(array $rows, array $ctx): array
    {
        $out = [];
        $ref = (string)($ctx['booking']['booking_reference'] ?? '');
        foreach ($rows as $r) {
            $action = strtolower(trim((string)($r['action'] ?? '')));
            $old = rh_hist_json($r['old_values'] ?? null);
            $new = rh_hist_json($r['new_values'] ?? null);
            $note = trim((string)($r['note'] ?? ''));
            $who = rh_hist_who($ctx['staff'], $r['performed_by'] ?? 0, $r['performed_by_name'] ?? null);
            $at = (string)($r['performed_at'] ?? '');
            $ev = null;
            if ($action === 'created') {
                $detail = $ctx['booking'] ? rh_hist_booking_line($ctx['booking'], $ctx) : '';
                if ($note !== '' && !preg_match('/^(Created manually by admin|Booking made online)/i', $note)) {
                    $detail .= "\n" . $note;
                }
                $ev = rh_hist_ev($at, $who, 'Booking ' . $ref . ' created' . (isset($new['status']) ? ' as ' . rh_hist_status_word((string)$new['status']) : ''), $detail, 'created', ['created'], 2);
            } elseif ($action === 'email_sent') {
                $ev = rh_hist_ev($at, $who, 'Email sent for this booking', $note, 'email', ['email:' . md5($note)], 2);
            } elseif ($action === 'room_assigned' || $action === 'upgraded' || $action === 'downgraded') {
                $rid = (int)($new['individual_room_id'] ?? 0);
                $rn = $rid > 0 ? ($ctx['room_numbers'][$rid] ?? ('#' . $rid)) : '';
                $what = $action === 'room_assigned' ? ($rn !== '' ? 'Room ' . $rn . ' assigned' : 'Room assigned') : 'Booking ' . $action;
                $lines = rh_hist_changes($old, $new, $ctx);
                if ($note !== '') $lines[] = $note;
                $ev = rh_hist_ev($at, $who, $what, implode("\n", $lines), 'room', [$rn !== '' ? 'room:' . $rn : 'room:' . md5($at)], 2);
            } elseif ($action === 'payment_updated') {
                $ns = (string)($new['payment_status'] ?? '');
                $ev = rh_hist_ev($at, $who, 'Payment status set to ' . rh_hist_status_word($ns), $note, 'payment', ['pstatus:' . $ns], 2);
            } elseif (isset($new['status']) && count(array_diff(array_keys($new), ['status', 'is_tentative', 'tentative_expires_at'])) === 0) {
                $ns = (string)$new['status'];
                $kind = rh_hist_status_kind($ns);
                $what = $kind === 'check_in' ? 'Guest checked in' : ($kind === 'check_out' ? 'Guest checked out'
                    : ($kind === 'cancel' ? 'Booking cancelled' : 'Booking ' . rh_hist_status_word($ns)));
                if ($action === 'check-in cancelled') $what = 'Check-in undone';
                $detail = isset($old['status']) ? 'Status: ' . rh_hist_words((string)$old['status']) . ' → ' . rh_hist_words($ns) : '';
                if ($note !== '') $detail .= ($detail !== '' ? "\n" : '') . $note;
                $ev = rh_hist_ev($at, $who, $what, $detail, $kind, ['status:' . rh_hist_status_norm($ns)], 2);
            } elseif (in_array($action, ['cancelled', 'no-show', 'confirmed', 'checked-in', 'checked-out', 'tentative', 'pending'], true)) {
                $kind = rh_hist_status_kind($action);
                $what = $kind === 'check_in' ? 'Guest checked in' : ($kind === 'check_out' ? 'Guest checked out'
                    : ($kind === 'cancel' ? 'Booking cancelled' : 'Booking ' . rh_hist_status_word($action)));
                $ev = rh_hist_ev($at, $who, $what, $note, $kind, ['status:' . rh_hist_status_norm($action)], 2);
            } else {
                $lines = rh_hist_changes($old, $new, $ctx);
                if ($note !== '') $lines[] = $note;
                $what = $action === 'modified' ? 'Booking details changed' : 'Booking ' . rh_hist_words($action);
                $ev = rh_hist_ev($at, $who, $what, implode("\n", $lines), 'edit', ['edit:' . md5(implode('|', array_keys($new)))], 2);
            }
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    /** booking_timeline_logs rows. */
    function rh_hist_from_timeline(array $rows, array $ctx): array
    {
        $out = [];
        $ref = (string)($ctx['booking']['booking_reference'] ?? '');
        foreach ($rows as $r) {
            $type = strtolower((string)($r['action_type'] ?? ''));
            $action = trim((string)($r['action'] ?? ''));
            $desc = trim((string)($r['description'] ?? ''));
            $meta = rh_hist_json($r['metadata'] ?? null);
            $nv = (string)($r['new_value'] ?? '');
            $ov = (string)($r['old_value'] ?? '');
            $who = rh_hist_who($ctx['staff'], $r['performed_by_id'] ?? 0, $r['performed_by_name'] ?? null, $r['performed_by_type'] ?? null);
            $at = (string)($r['created_at'] ?? '');
            $ev = null;
            if ($type === 'create') {
                $detail = $ctx['booking'] ? rh_hist_booking_line($ctx['booking'], $ctx) : $desc;
                $ev = rh_hist_ev($at, $who, 'Booking ' . $ref . ' created' . ($nv !== '' ? ' as ' . rh_hist_status_word($nv) : ''), $detail, 'created', ['created'], 1);
            } elseif (in_array($type, ['status_change', 'check_in', 'check_out', 'cancellation', 'conversion', 'expiry'], true)) {
                $ns = $nv !== '' ? $nv : ($type === 'check_in' ? 'checked-in' : ($type === 'check_out' ? 'checked-out' : ($type === 'cancellation' ? 'cancelled' : '')));
                if ($type === 'conversion') $ns = 'confirmed';
                if ($type === 'expiry') $ns = 'expired';
                $kind = rh_hist_status_kind($ns);
                $what = $kind === 'check_in' ? 'Guest checked in' : ($kind === 'check_out' ? 'Guest checked out'
                    : ($ns === 'expired' ? 'Hold expired and the booking was cancelled' : ($kind === 'cancel' ? 'Booking cancelled' : 'Booking ' . rh_hist_status_word($ns))));
                if ($ns === 'no-show' || $action === 'Guest marked as no-show') $what = 'Guest marked as no-show';
                $detail = ($ov !== '' && $ns !== '' && $ov !== $ns) ? 'Status: ' . rh_hist_words($ov) . ' → ' . rh_hist_words($ns) : '';
                if (!empty($meta['reason'])) $detail .= ($detail !== '' ? "\n" : '') . 'Reason: ' . rh_hist_clip($meta['reason'], 200);
                $ev = rh_hist_ev($at, $who, $what, $detail, $kind, ['status:' . rh_hist_status_norm($ns)], 1);
            } elseif ($action === 'credit_note_issued') {
                $ev = rh_hist_ev($at, $who, 'Credit note ' . $nv . ' issued', $desc, 'credit_note', ['cn:' . $nv], 1);
            } elseif ($action === 'credit_note_applied') {
                $ev = rh_hist_ev($at, $who, 'Credit note ' . $nv . ' used on this booking', $desc, 'credit_note', ['cnapply:' . $nv], 1);
            } elseif ($action === 'credit_note_voided') {
                $num = preg_match('/Credit note (\S+)/', $desc, $m) ? $m[1] : '';
                $ev = rh_hist_ev($at, $who, 'Credit note ' . $num . ' voided', $desc, 'credit_note', ['cnvoid:' . $num], 1);
            } elseif ($type === 'payment' || $type === 'financial') {
                $pref = (string)($meta['payment_ref'] ?? '');
                $amt = isset($meta['amount']) ? (float)$meta['amount'] : rh_hist_parse_amount($desc);
                $keys = [];
                if ($pref !== '') $keys[] = 'payment:' . $pref;
                if ($amt !== null) $keys[] = 'payment:' . rh_hist_amt_key($amt);
                if (!$keys) $keys[] = 'payment:' . md5($desc);
                $isDel = stripos($action, 'deleted') !== false;
                if ($isDel) $keys = ['paydel:' . ($pref !== '' ? $pref : md5($desc))];
                $lines = [$desc];
                if (isset($meta['balance_before'], $meta['balance_after'])) {
                    $lines[] = 'Owed before: ' . rh_hist_money($meta['balance_before'], $ctx['cur']) . ' · owed after: ' . rh_hist_money(max(0, (float)$meta['balance_after']), $ctx['cur']);
                }
                $ev = rh_hist_ev($at, $who, $action !== '' ? $action : 'Payment', implode("\n", array_filter($lines)), 'payment', $keys, 1,
                    [(int)($meta['payment_id'] ?? 0)]);
            } elseif ($type === 'email' || $type === 'reminder') {
                $et = (string)($meta['email_type'] ?? $action);
                $keys = ['email:' . md5($et)];
                if ($type === 'reminder') $keys[] = 'reminder';
                $ev = rh_hist_ev($at, $who, $action !== '' ? $action : 'Email sent', $desc, 'email', $keys, 1);
            } elseif ($type === 'note') {
                $ev = rh_hist_ev($at, $who, 'Note added', $desc, 'note', ['note:' . md5(trim($desc))], 1);
            } elseif ($type === 'date_adjustment') {
                $lines = [];
                $o = rh_hist_json($r['old_value'] ?? null);
                $n = rh_hist_json($r['new_value'] ?? null);
                $on = $o['old']['nights'] ?? ($meta['old']['nights'] ?? null);
                $nn = $n['new']['nights'] ?? ($meta['new']['nights'] ?? null);
                if ($on !== null || $nn !== null) $lines[] = 'Nights: ' . ($on ?? '?') . ' → ' . ($nn ?? '?');
                if (isset($meta['amount_delta'])) {
                    $d = (float)$meta['amount_delta'];
                    $lines[] = 'Room charge change: ' . ($d >= 0 ? '+' : '-') . rh_hist_money(abs($d), $ctx['cur']);
                }
                if (!empty($meta['reason'])) $lines[] = 'Reason: ' . rh_hist_clip($meta['reason'], 200);
                if (!$lines && $desc !== '') $lines[] = $desc;
                $ev = rh_hist_ev($at, $who, $action !== '' ? $action : 'Stay dates changed', implode("\n", $lines), 'edit', ['dateadj:' . md5($at)], 1);
            } else {
                $kind = (stripos($action, 'room') !== false || stripos($action, 'grade') !== false) ? 'room' : 'edit';
                $ev = rh_hist_ev($at, $who, $action !== '' ? $action : 'Booking updated', $desc, $kind, ['tl:' . md5($action . $desc)], 1);
            }
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    /** booking_status_log rows. */
    function rh_hist_from_status_log(array $rows, array $ctx): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ns = (string)($r['new_status'] ?? '');
            if ($ns === '') continue;
            $kind = rh_hist_status_kind($ns);
            $what = $kind === 'check_in' ? 'Guest checked in' : ($kind === 'check_out' ? 'Guest checked out'
                : ($kind === 'cancel' ? 'Booking cancelled' : 'Booking ' . rh_hist_status_word($ns)));
            $detail = (($r['old_status'] ?? '') !== '' ? 'Status: ' . rh_hist_words((string)$r['old_status']) . ' → ' . rh_hist_words($ns) : '');
            if (trim((string)($r['change_reason'] ?? '')) !== '') $detail .= ($detail !== '' ? "\n" : '') . 'Reason: ' . rh_hist_clip($r['change_reason'], 200);
            $ev = rh_hist_ev((string)($r['created_at'] ?? ''), rh_hist_who($ctx['staff'], $r['changed_by'] ?? 0), $what, $detail, $kind, ['status:' . rh_hist_status_norm($ns)], 0);
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    /** booking_notes rows. */
    function rh_hist_from_notes(array $rows, array $ctx): array
    {
        $out = [];
        foreach ($rows as $r) {
            $text = trim((string)rh_hist_col($r, ['note_text', 'note'], ''));
            if ($text === '') continue;
            $ev = rh_hist_ev((string)rh_hist_col($r, ['created_at', 'added_at'], ''), rh_hist_who($ctx['staff'], $r['created_by'] ?? 0), 'Note added', rh_hist_clip($text, 500), 'note', ['note:' . md5($text)], 1);
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    /**
     * payments rows (originals + refunds, deleted ones too). $ctx['cn_by_payment'][payment_reference] = overpayment credit
     * note row for that payment (found from its reason notes), so an old payment still says the overpay box was ticked.
     */
    function rh_hist_from_payments(array $rows, array $ctx): array
    {
        $out = [];
        $cur = $ctx['cur'];
        $refs = [];
        foreach ($rows as $r) $refs[(int)$r['id']] = (string)($r['payment_reference'] ?? '');
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            $pref = (string)($r['payment_reference'] ?? '');
            $who = rh_hist_payment_actor($r, $ctx['staff']);
            $method = rh_hist_words((string)($r['payment_method'] ?? ''));
            $at = (string)rh_hist_col($r, ['created_at', 'payment_date'], '');
            if ($who === 'Not recorded' && (string)($r['payment_type'] ?? '') !== 'refund' && empty($ctx['pay_created_audit'][$id])) {
                $who = rh_hist_payment_who_fallback($r, $rows, $ctx);
            }
            if ((string)($r['payment_type'] ?? '') === 'refund') {
                $amt = (float)rh_hist_col($r, ['refund_amount', 'total_amount', 'payment_amount'], 0);
                $orig = (int)($r['original_payment_id'] ?? 0);
                $origRef = $refs[$orig] ?? '';
                $cnNum = (string)($ctx['cn_numbers'][(int)($r['credit_note_id'] ?? 0)] ?? '');
                if ((string)($r['payment_method'] ?? '') === 'credit_note') {
                    $what = 'Refund leg ' . $pref . ' moved ' . rh_hist_money($amt, $cur) . ' into credit note' . ($cnNum !== '' ? ' ' . $cnNum : '');
                } else {
                    $what = 'Refund ' . $pref . ' of ' . rh_hist_money($amt, $cur) . ' recorded' . ($method !== '' ? ' (' . strtolower($method) . ')' : '');
                }
                $lines = [];
                if ($origRef !== '') $lines[] = 'Against payment ' . $origRef . '.';
                $lines[] = 'Method: ' . ($method !== '' ? $method : 'not recorded') . ' · refund status: ' . rh_hist_words((string)($r['refund_status'] ?? $r['payment_status'] ?? ''));
                if (trim((string)($r['refund_reason'] ?? '')) !== '') $lines[] = 'Reason: ' . rh_hist_words((string)$r['refund_reason']);
                if (trim((string)($r['refund_notes'] ?? '')) !== '') $lines[] = rh_hist_clip($r['refund_notes'], 200);
                $ev = rh_hist_ev($at, $who, $what, implode("\n", $lines), 'refund', ['refund:' . $pref], 3, [$id, $orig]);
                if ($ev) $out[] = $ev;
            } else {
                $amt = (float)rh_hist_col($r, ['total_amount', 'payment_amount'], 0);
                $status = (string)($r['payment_status'] ?? '');
                $pending = !in_array($status, ['completed', 'paid', 'refunded', 'partially_refunded'], true);
                $what = ($pending ? 'Payment ' . $pref . ' of ' . rh_hist_money($amt, $cur) . ' entered as ' . rh_hist_status_word($status)
                    : 'Payment ' . $pref . ' of ' . rh_hist_money($amt, $cur) . ' recorded') . ($method !== '' ? ' (' . strtolower($method) . ')' : '');
                $lines = [];
                if (trim((string)($r['receipt_number'] ?? '')) !== '') $lines[] = 'Receipt ' . $r['receipt_number'] . '.';
                if (trim((string)($r['payment_date'] ?? '')) !== '' && strtotime((string)$r['payment_date']) && substr((string)$r['payment_date'], 0, 10) !== substr($at, 0, 10)) {
                    $lines[] = 'Payment date on the record: ' . date('j M Y', strtotime((string)$r['payment_date'])) . '.';
                }
                $cn = $ctx['cn_by_payment'][$pref] ?? null;
                if ($cn) {
                    $lines[] = 'More than was owed: the overpay box was ticked, so ' . rh_hist_money((float)$cn['original_amount'], $cur)
                        . ' went to credit note ' . $cn['credit_note_number'] . '.';
                }
                if (in_array($status, ['refunded', 'partially_refunded', 'cancelled', 'failed'], true)) {
                    $lines[] = 'Status now: ' . rh_hist_words($status) . '.';
                }
                $ev = rh_hist_ev($at, $who, $what, implode("\n", $lines), 'payment', ['payment:' . $pref, 'payment:' . rh_hist_amt_key($amt)], 3, [$id]);
                if ($ev) $out[] = $ev;
                if (!empty($r['receipt_emailed_at'])) {
                    $ev = rh_hist_ev((string)$r['receipt_emailed_at'], 'System', 'Receipt for ' . $pref . ' emailed to the guest', '', 'email', ['receipt:' . $pref], 3, [$id]);
                    if ($ev) $out[] = $ev;
                }
            }
            if (!empty($r['deleted_at'])) {
                $ev = rh_hist_ev((string)$r['deleted_at'], 'Not recorded', 'Payment ' . $pref . ' removed', '', 'payment', ['paydel:' . $pref], 2, [$id]);
                if ($ev) $out[] = $ev;
            }
        }
        return $out;
    }

    /**
     * Who a payment row names: recorded_by (a staff id), then processed_by, which holds a staff id on some rows and a
     * NAME (VARCHAR) on others: a name is matched to a staff member by full name or username for the role, otherwise
     * shown as written. 'Not recorded' when both are empty.
     */
    function rh_hist_payment_actor(array $r, array $staff): string
    {
        $rec = trim((string)($r['recorded_by'] ?? ''));
        if ($rec !== '' && ctype_digit($rec) && (int)$rec > 0) {
            return rh_hist_who($staff, (int)$rec);
        }
        $proc = trim((string)($r['processed_by'] ?? ''));
        if ($proc === '' || $proc === '0') {
            return 'Not recorded';
        }
        if (ctype_digit($proc)) {
            return rh_hist_who($staff, (int)$proc);
        }
        return rh_hist_who($staff, 0, $proc);
    }

    /**
     * Who saved an older payment that recorded nobody: the person who issued the overpayment credit note naming this
     * exact payment reference, else the person on a refund leg of this payment written within 5 seconds of it.
     * Labelled with where the name comes from. 'Not recorded' when neither exists.
     */
    function rh_hist_payment_who_fallback(array $pay, array $rows, array $ctx): string
    {
        $ref = (string)($pay['payment_reference'] ?? '');
        $cn = $ref !== '' ? ($ctx['cn_by_payment'][$ref] ?? null) : null;
        if ($cn && (int)($cn['issued_by'] ?? 0) > 0) {
            $w = rh_hist_who($ctx['staff'], (int)$cn['issued_by']);
            if ($w !== 'Not recorded') return $w . ' — from the credit note issued with it';
        }
        $pts = strtotime((string)rh_hist_col($pay, ['created_at', 'payment_date'], ''));
        foreach ($rows as $leg) {
            if ((string)($leg['payment_type'] ?? '') !== 'refund' || (int)($leg['original_payment_id'] ?? 0) !== (int)($pay['id'] ?? 0)) continue;
            $lts = strtotime((string)($leg['created_at'] ?? ''));
            if ($pts && $lts && abs($lts - $pts) <= 5) {
                $w = rh_hist_payment_actor($leg, $ctx['staff']);
                if ($w !== 'Not recorded') return $w . ' — from the refund leg recorded with it';
            }
        }
        return 'Not recorded';
    }

    /** payment_audit_log rows (one per changed field); rows of one save are joined. $ctx['pay_refs'][id] = reference. */
    function rh_hist_from_payment_audit(array $rows, array $ctx): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $at = (string)rh_hist_col($r, ['created_at', 'performed_at', 'changed_at'], '');
            $k = (int)($r['payment_id'] ?? 0) . '|' . (string)($r['action'] ?? '') . '|' . $at;
            $groups[$k][] = $r;
        }
        $out = [];
        foreach ($groups as $g) {
            $r = $g[0];
            $pid = (int)($r['payment_id'] ?? 0);
            $pref = (string)($r['payment_reference'] ?? ($ctx['pay_refs'][$pid] ?? ''));
            $action = strtolower((string)($r['action'] ?? ''));
            $who = rh_hist_who($ctx['staff'], $r['performed_by'] ?? 0, $r['performed_by_name'] ?? null);
            $at = (string)rh_hist_col($r, ['created_at', 'performed_at', 'changed_at'], '');
            $lines = [];
            foreach ($g as $row) {
                $f = (string)($row['field_name'] ?? '');
                if ($f !== '') {
                    $lines[] = rh_hist_field_label($f) . ': ' . rh_hist_field_value($f, $row['old_value'] ?? null, $ctx) . ' → ' . rh_hist_field_value($f, $row['new_value'] ?? null, $ctx);
                } elseif (trim((string)($row['new_value'] ?? '')) !== '') {
                    $lines[] = rh_hist_clip($row['new_value'], 400);
                }
            }
            $detail = implode("\n", $lines);
            if ($action === 'created') {
                $ev = rh_hist_ev($at, $who, 'Payment ' . $pref . ' recorded', $detail, 'payment', ['payment:' . $pref], 1, [$pid]);
            } elseif ($action === 'edited') {
                $ev = rh_hist_ev($at, $who, 'Payment ' . $pref . ' changed', $detail, 'payment', ['payedit:' . $pref . ':' . md5($detail)], 1, [$pid]);
            } elseif ($action === 'deleted') {
                $ev = rh_hist_ev($at, $who, 'Payment ' . $pref . ' removed', $detail, 'payment', ['paydel:' . $pref], 3, [$pid]);
            } elseif ($action === 'overpay_credit_note') {
                $ev = rh_hist_ev($at, $who, 'Overpayment on ' . $pref . ' moved to a credit note', $detail, 'credit_note', ['payovr:' . $pref], 1, [$pid]);
            } elseif (strpos($action, 'refund') === 0) {
                $what = $action === 'refund_recorded' ? 'Refund recorded against payment ' . $pref : 'Refund ' . $pref . ' marked ' . rh_hist_words(substr($action, 7));
                $ev = rh_hist_ev($at, $who, $what, $detail, 'refund', ['payaudit:' . $action . ':' . $pref], 1, [$pid]);
            } else {
                $ev = rh_hist_ev($at, $who, 'Payment ' . $pref . ': ' . strtolower(rh_hist_words($action)), $detail, 'payment', ['payaudit:' . $action . ':' . $pref], 1, [$pid]);
            }
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    /** credit_notes rows: issued, emailed, voided. */
    function rh_hist_from_credit_notes(array $rows, array $ctx): array
    {
        $out = [];
        $cur = $ctx['cur'];
        foreach ($rows as $r) {
            $num = (string)($r['credit_note_number'] ?? '');
            $amt = (float)rh_hist_col($r, ['original_amount', 'amount'], 0);
            $reason = (string)($r['reason'] ?? '');
            $notes = trim(preg_replace('/\s*\[account:[^\]]*\]\s*/', ' ', (string)($r['reason_notes'] ?? '')));
            $issuer = rh_hist_who($ctx['staff'], $r['issued_by'] ?? 0);
            $pids = [(int)($r['original_payment_id'] ?? 0), (int)($ctx['cn_payment_ids'][(int)($r['id'] ?? 0)] ?? 0)];
            if ($reason === 'overpayment') {
                $who = 'System';
                $what = 'Credit note ' . $num . ' for ' . rh_hist_money($amt, $cur) . ' issued automatically';
                $detail = 'The payment was more than was owed' . ($issuer !== 'Not recorded' ? ' (saved by ' . $issuer . ')' : '') . '.';
            } else {
                $who = $issuer;
                $what = 'Credit note ' . $num . ' for ' . rh_hist_money($amt, $cur) . ' issued';
                $detail = ($reason !== '' ? 'Reason: ' . rh_hist_words($reason) . '.' : '') . ($notes !== '' ? "\n" . $notes : '');
            }
            $ev = rh_hist_ev((string)($r['issued_at'] ?? rh_hist_col($r, ['created_at'], '')), $who, $what, $detail, 'credit_note', ['cn:' . $num], 3, $pids);
            if ($ev) $out[] = $ev;
            if (!empty($r['email_sent_at'])) {
                $to = trim((string)($r['guest_email'] ?? ''));
                $ev = rh_hist_ev((string)$r['email_sent_at'], 'System', 'Credit note ' . $num . ' emailed to the guest', $to !== '' ? 'Sent to ' . $to . '.' : '', 'email', ['cnemail:' . $num], 3, $pids);
                if ($ev) $out[] = $ev;
            }
            if (!empty($r['voided_at'])) {
                $vr = trim((string)($r['void_reason'] ?? ''));
                $ev = rh_hist_ev((string)$r['voided_at'], rh_hist_who($ctx['staff'], $r['voided_by'] ?? 0), 'Credit note ' . $num . ' voided', $vr !== '' ? 'Reason: ' . rh_hist_clip($vr, 200) : '', 'credit_note', ['cnvoid:' . $num], 3, $pids);
                if ($ev) $out[] = $ev;
            }
        }
        return $out;
    }

    /** credit_note_applications rows ($ctx['cn_numbers'][id] = number). */
    function rh_hist_from_cn_apps(array $rows, array $ctx): array
    {
        $out = [];
        foreach ($rows as $r) {
            $num = (string)($ctx['cn_numbers'][(int)($r['credit_note_id'] ?? 0)] ?? ('#' . (int)($r['credit_note_id'] ?? 0)));
            $amt = (float)($r['amount_applied'] ?? 0);
            $bref = trim((string)($r['applied_to_booking_reference'] ?? ''));
            $what = 'Credit note ' . $num . ' used: ' . rh_hist_money($amt, $ctx['cur']) . ($bref !== '' ? ' towards ' . $bref : '');
            $ev = rh_hist_ev((string)($r['applied_at'] ?? ''), rh_hist_who($ctx['staff'], $r['applied_by'] ?? 0), $what,
                rh_hist_clip($r['notes'] ?? '', 200), 'credit_note', ['cnapply:' . $num], 3, [(int)($r['payment_id'] ?? 0)]);
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    /** cancellation_log rows. */
    function rh_hist_from_cancellations(array $rows, array $ctx): array
    {
        $out = [];
        foreach ($rows as $r) {
            $lines = [];
            if (trim((string)($r['cancellation_reason'] ?? '')) !== '') $lines[] = 'Reason: ' . rh_hist_clip($r['cancellation_reason'], 200);
            if (isset($r['amount_paid']) && (float)$r['amount_paid'] > rh_hist_tol()) $lines[] = 'Paid at the time: ' . rh_hist_money($r['amount_paid'], $ctx['cur']);
            if (isset($r['refund_amount']) && (float)$r['refund_amount'] > rh_hist_tol()) {
                $lines[] = 'Refund due: ' . rh_hist_money($r['refund_amount'], $ctx['cur']) . (!empty($r['refund_status']) ? ' (' . rh_hist_words((string)$r['refund_status']) . ')' : '');
            }
            $who = rh_hist_who($ctx['staff'], $r['cancelled_by_id'] ?? 0, $r['cancelled_by_name'] ?? null, $r['cancelled_by_type'] ?? null);
            $ev = rh_hist_ev((string)rh_hist_col($r, ['cancelled_at', 'created_at'], ''), $who, 'Booking cancelled', implode("\n", $lines), 'cancel', ['status:cancelled'], 2);
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    /** guest_communication_log + automated_email_log rows. */
    function rh_hist_from_emails(array $comms, array $auto, array $ctx): array
    {
        $out = [];
        $stages = ['prearrival' => 'Before-arrival email sent to the guest', 'poststay' => 'After-stay email (review request) sent to the guest'];
        foreach ($comms as $r) {
            $st = (string)($r['stage'] ?? '');
            $ev = rh_hist_ev((string)($r['sent_at'] ?? ''), 'System', $stages[$st] ?? ('Email sent to the guest (' . rh_hist_words($st) . ')'),
                trim((string)($r['sent_to'] ?? '')) !== '' ? 'Sent to ' . $r['sent_to'] . '.' : '', 'email', ['comm:' . $st], 2);
            if ($ev) $out[] = $ev;
        }
        foreach ($auto as $r) {
            $job = (string)($r['job'] ?? '');
            $status = strtolower((string)($r['status'] ?? ''));
            if ($status === 'skipped') continue;
            $subject = trim((string)($r['subject'] ?? ''));
            $what = ($status === 'failed' ? 'Automatic email could not be sent' : 'Automatic email sent') . ($subject !== '' ? ': ' . rh_hist_clip($subject, 90) : ' (' . strtolower(rh_hist_words($job)) . ')');
            $lines = [];
            if (trim((string)($r['recipient'] ?? '')) !== '') $lines[] = 'To ' . $r['recipient'] . '.';
            if ($status === 'failed' && trim((string)($r['error'] ?? '')) !== '') $lines[] = 'Problem: ' . rh_hist_clip($r['error'], 160);
            $keys = ['auto:' . $job . ':' . ($r['stage'] ?? '')];
            if (stripos($job, 'reminder') !== false) $keys[] = 'reminder';
            $ev = rh_hist_ev((string)($r['sent_at'] ?? ''), 'System', $what, implode("\n", $lines), 'email', $keys, 2);
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    /** tentative_booking_log rows. */
    function rh_hist_from_tentative(array $rows, array $ctx): array
    {
        $out = [];
        foreach ($rows as $r) {
            $a = strtolower((string)($r['action'] ?? ''));
            $kind = (strpos($a, 'sent') !== false || strpos($a, 'reminder') !== false) ? 'email' : 'status';
            $what = ['quotation_sent' => 'Quotation emailed to the guest', 'reminder_sent' => 'Hold reminder emailed to the guest',
                'converted' => 'Hold confirmed as a booking', 'expired' => 'Hold expired', 'created' => 'Booking put on hold'][$a] ?? ('Hold: ' . strtolower(rh_hist_words($a)));
            $ev = rh_hist_ev((string)rh_hist_col($r, ['created_at', 'performed_at'], ''), rh_hist_who($ctx['staff'], $r['performed_by'] ?? 0), $what,
                rh_hist_clip($r['action_reason'] ?? '', 200), $kind, $a === 'quotation_sent' ? ['tent:' . $a, 'quote'] : ['tent:' . $a], 1);
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    /** booking_rooms rows: room assigned / released. */
    /**
     * $others: the booking's other events (already built). A room assigned within 10 s of the booking's creation was
     * assigned by whoever created it; a room released within 10 s of another step (cancel, check-out, move ...) was
     * released by that step's person. Otherwise the person is not recorded.
     */
    function rh_hist_from_booking_rooms(array $rows, array $ctx, array $others = []): array
    {
        $out = [];
        $creator = 'Not recorded';
        $createdTs = !empty($ctx['booking']['created_at']) ? strtotime((string)$ctx['booking']['created_at']) : 0;
        $bestRank = -1;
        foreach ($others as $o) {
            if (in_array('created', $o['keys'], true) && !rh_hist_unknown_who($o['who']) && $o['rank'] > $bestRank) {
                $creator = $o['who'];
                $bestRank = $o['rank'];
            }
        }
        $nearWho = function (int $ts) use ($others): string {
            $best = null;
            foreach ($others as $o) {
                if (rh_hist_unknown_who($o['who']) || $o['who'] === 'System' || !in_array($o['kind'], ['cancel', 'check_out', 'room', 'status', 'edit'], true)) continue;
                $d = abs($o['ts'] - $ts);
                if ($d <= 10 && ($best === null || $d < $best[0])) $best = [$d, $o['who']];
            }
            return $best ? $best[1] : 'Not recorded';
        };
        foreach ($rows as $r) {
            $rid = (int)($r['individual_room_id'] ?? 0);
            if ($rid <= 0) continue;
            $rn = (string)($ctx['room_numbers'][$rid] ?? ('#' . $rid));
            $combo = (int)($r['room_combination_id'] ?? 0);
            $detail = $combo > 0 ? 'Part of joined rooms' . (isset($ctx['combo_names'][$combo]) ? ' ' . $ctx['combo_names'][$combo] : '') . '.' : '';
            $ats = strtotime((string)($r['assigned_at'] ?? ''));
            $who = ($ats && $createdTs && abs($ats - $createdTs) <= 10) ? $creator : 'Not recorded';
            $ev = rh_hist_ev((string)($r['assigned_at'] ?? ''), $who, 'Room ' . $rn . ' assigned', $detail, 'room', ['room:' . $rn], 0);
            if ($ev) $out[] = $ev;
            if (!empty($r['released_at'])) {
                $rts = strtotime((string)$r['released_at']);
                $ev = rh_hist_ev((string)$r['released_at'], $rts ? $nearWho($rts) : 'Not recorded', 'Room ' . $rn . ' released', '', 'room', ['roomrel:' . $rn], 0);
                if ($ev) $out[] = $ev;
            }
        }
        return $out;
    }

    /** booking_financial_audit rows (columns differ by install: read what is there). */
    function rh_hist_from_financial_audit(array $rows, array $ctx): array
    {
        $out = [];
        foreach ($rows as $r) {
            $action = (string)rh_hist_col($r, ['action', 'event', 'change_type'], 'change');
            $f = (string)rh_hist_col($r, ['field_name', 'field'], '');
            $detail = $f !== '' ? rh_hist_field_label($f) . ': ' . rh_hist_field_value($f, $r['old_value'] ?? null, $ctx) . ' → ' . rh_hist_field_value($f, $r['new_value'] ?? null, $ctx)
                : rh_hist_clip(rh_hist_col($r, ['note', 'notes', 'description'], ''), 300);
            $who = rh_hist_who($ctx['staff'], rh_hist_col($r, ['performed_by', 'changed_by', 'user_id'], 0), rh_hist_col($r, ['performed_by_name', 'changed_by_name', 'username'], null));
            $ev = rh_hist_ev((string)rh_hist_col($r, ['created_at', 'performed_at', 'changed_at'], ''), $who, 'Money on the booking changed: ' . strtolower(rh_hist_words($action)),
                $detail, 'edit', ['fin:' . md5($action . $detail)], 1);
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    /** system_event_log rows already filtered to this booking (problems only). */
    function rh_hist_from_system_events(array $rows, array $ctx): array
    {
        $out = [];
        foreach ($rows as $r) {
            $msg = rh_hist_clip($r['message'] ?? '', 200);
            if ($msg === '') continue;
            $ev = rh_hist_ev((string)($r['created_at'] ?? ''), rh_hist_who($ctx['staff'], $r['user_id'] ?? 0, $r['username'] ?? null),
                'Problem recorded: ' . $msg, '', 'edit', ['sys:' . (int)($r['id'] ?? 0)], 0);
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    // ------------------------------------------------------------------ prices

    function rh_hist_price_field_label(string $field): string
    {
        $l = ['price_per_night' => 'standard nightly price', 'price_single_occupancy' => 'single-occupancy price',
            'price_double_occupancy' => 'double-occupancy price', 'price_triple_occupancy' => 'triple-occupancy price',
            'price_override' => 'joined-rooms price', 'adjustment_value' => 'adjustment', 'adjustment_type' => 'adjustment type',
            'is_active' => 'switched on', 'deleted' => 'plan', 'created' => 'plan', 'name' => 'name'];
        return $l[$field] ?? str_replace('_', ' ', $field);
    }

    /** One room_price_history row in a sentence. $names: entity_type|id => display name. */
    function rh_hist_price_change_text(array $r, array $ctx): string
    {
        $type = (string)($r['entity_type'] ?? '');
        $eid = (int)($r['entity_id'] ?? 0);
        $name = (string)($ctx['entity_names'][$type . '|' . $eid] ?? '');
        $field = (string)($r['field'] ?? '');
        $o = $r['old_value'] ?? null;
        $n = $r['new_value'] ?? null;
        $fmt = function ($v) use ($ctx, $field) {
            if ($v === null || $v === '') return '(blank)';
            if (is_numeric($v) && in_array($field, ['price_per_night', 'price_single_occupancy', 'price_double_occupancy', 'price_triple_occupancy', 'price_override'], true)) {
                return rh_hist_money((float)$v, $ctx['cur']);
            }
            return rh_hist_clip($v, 60);
        };
        $what = ['room_type' => 'room type', 'individual_room' => 'room', 'room_combination' => 'joined rooms', 'rate_plan' => 'rate plan'][$type] ?? 'price';
        $label = ($name !== '' ? $name : ucfirst($what) . ' #' . $eid);
        if ($type === 'rate_plan' && in_array($field, ['created', 'deleted'], true)) {
            return 'Rate plan ' . $label . ' ' . $field;
        }
        if ($type === 'rate_plan' && $field === 'is_active') {
            return 'Rate plan ' . $label . ' switched ' . ((string)$n === '1' ? 'on' : 'off');
        }
        return $label . ' ' . rh_hist_price_field_label($field) . ' changed from ' . $fmt($o) . ' to ' . $fmt($n);
    }

    function rh_hist_from_price_history(array $rows, array $ctx): array
    {
        $out = [];
        foreach ($rows as $r) {
            $who = rh_hist_who($ctx['staff'], $r['changed_by'] ?? 0, $r['changed_by_name'] ?? null);
            $ev = rh_hist_ev((string)($r['changed_at'] ?? ''), $who, rh_hist_price_change_text($r, $ctx),
                'This does not change this booking: it keeps the price it was made at.', 'price', ['ph:' . (int)($r['id'] ?? 0)], 3);
            if ($ev) $out[] = $ev;
        }
        return $out;
    }

    /**
     * "How the price was worked out", placed at creation. $snap: the price snapshot saved at creation (new bookings)
     * or null (older bookings: the nightly rate is worked out from the saved total). $today: today's price for this
     * room and occupancy (null = unknown). $changes: room_price_history rows for this room since the booking was made.
     */
    function rh_hist_price_explanation(array $b, ?array $snap, ?float $today, array $changes, array $ctx): ?array
    {
        $cur = $ctx['cur'];
        $n = (int)($b['number_of_nights'] ?? 0);
        $full = rh_hist_full_total($b);
        $child = (float)($b['child_supplement_total'] ?? 0);
        if ($n <= 0 || $full <= rh_hist_tol()) {
            return null;
        }
        $occ = strtolower((string)($b['occupancy_type'] ?? ''));
        $occWord = in_array($occ, ['single', 'double', 'triple'], true) ? $occ . '-occupancy ' : '';
        $room = trim((string)($ctx['room_type_name'] ?? '')) ?: 'this room';
        $lines = [];
        $override = false;
        $ratePlan = trim((string)($b['rate_plan_label'] ?? ''));
        $compareRate = null;

        if ($snap) {
            $override = (string)($snap['source'] ?? '') === 'override';
            $rate = (float)($snap['rate'] ?? 0);
            $sn = (int)($snap['nights'] ?? $n) ?: $n;
            if ($override) {
                $lines[] = 'Price set by hand: ' . rh_hist_money((float)($snap['total_with_vat'] ?? $full), $cur) . ' for the whole stay (' . $sn . ' night' . ($sn === 1 ? '' : 's') . ').';
            } else {
                $src = ['room_type' => 'the ' . $occWord . 'price of ' . $room . ' at the time',
                    'joined_room' => 'the joined-rooms price at the time', 'rate_plan' => 'the ' . $occWord . 'price of ' . $room . ' at the time, after the rate plan'][(string)($snap['source'] ?? '')]
                    ?? 'the room price at the time';
                $lines[] = rh_hist_money($rate, $cur) . ' a night × ' . $sn . ' night' . ($sn === 1 ? '' : 's') . ' = ' . rh_hist_money(round($rate * $sn, 2), $cur) . ', using ' . $src . '.';
                if (isset($snap['list_rate']) && abs((float)$snap['list_rate'] - $rate) > rh_hist_tol()) {
                    $lines[] = 'List price ' . rh_hist_money((float)$snap['list_rate'], $cur) . ' a night; rate plan ' . ($snap['rate_plan_label'] ?? $ratePlan) . ' made it ' . rh_hist_money($rate, $cur) . '.';
                }
                $compareRate = isset($snap['list_rate']) ? (float)$snap['list_rate'] : $rate;
            }
            $child = (float)($snap['child_supplement'] ?? $child);
        } else {
            $nightly = round(($full - $child) / $n, 2);
            $lines[] = rh_hist_money($nightly, $cur) . ' a night × ' . $n . ' night' . ($n === 1 ? '' : 's') . ' = ' . rh_hist_money(round($nightly * $n, 2), $cur)
                . ' (worked out from the saved total: this booking was made before price details were recorded).';
            $compareRate = $nightly;
        }
        if ($child > rh_hist_tol()) $lines[] = 'Children: + ' . rh_hist_money($child, $cur) . '.';
        if ($ratePlan !== '' && !($snap && isset($snap['list_rate']))) {
            $disc = (float)($b['rate_plan_discount'] ?? 0);
            $lines[] = 'Rate plan applied: ' . $ratePlan . ($disc > rh_hist_tol() ? ' (discount ' . rh_hist_money($disc, $cur) . ')' : '') . '.';
        }
        if (!empty($ctx['combo_name'])) $lines[] = 'Joined rooms: ' . $ctx['combo_name'] . '.';
        $vat = (float)($b['vat_amount'] ?? 0);
        $levy = (float)($b['tourism_levy_amount'] ?? 0);
        $tax = [];
        if ($vat > rh_hist_tol()) $tax[] = 'VAT ' . rh_hist_money($vat, $cur) . ((float)($b['vat_rate'] ?? 0) > 0 ? ' (' . rtrim(rtrim(number_format((float)$b['vat_rate'], 2), '0'), '.') . '%)' : '');
        if ($levy > rh_hist_tol()) $tax[] = 'tourism levy ' . rh_hist_money($levy, $cur) . ((float)($b['tourism_levy_percent'] ?? 0) > 0 ? ' (' . rtrim(rtrim(number_format((float)$b['tourism_levy_percent'], 2), '0'), '.') . '%)' : '');
        $lines[] = 'Total ' . rh_hist_money($full, $cur) . ($tax ? ', which includes ' . implode(' and ', $tax) : '') . '.';

        if (!$override && $today !== null && $today > 0 && $compareRate !== null && abs($today - $compareRate) > 1.0) {
            $lines[] = "Today's price for this room is " . rh_hist_money($today, $cur) . ' a night — the booking keeps the price it was made at.';
            if ($ratePlan !== '' && !$snap) {
                $lines[] = 'A rate plan was applied to this booking, so part of the difference may be the rate plan.';
            }
            if ($changes) {
                foreach (array_slice($changes, -5) as $c) {
                    $lines[] = 'Price changed on ' . rh_hist_time_label((string)$c['changed_at']) . ' by '
                        . rh_hist_who($ctx['staff'], $c['changed_by'] ?? 0, $c['changed_by_name'] ?? null) . ': ' . rh_hist_price_change_text($c, $ctx) . '.';
                }
            } else {
                $lines[] = 'The price change was made before price changes were recorded, so who changed it and when is not known.';
            }
        }
        return rh_hist_ev((string)($b['created_at'] ?? ''), 'System', 'How the price was worked out', implode("\n", $lines), 'price', ['price-explain'], 1);
    }

    // ------------------------------------------------------------------ merge

    function rh_hist_unknown_who(string $who): bool
    {
        return $who === '' || $who === 'Not recorded';
    }

    /**
     * A key that names one record (the booking's creation, a payment / refund / credit note by its reference, a price
     * row): rows carrying it are the same step however far apart they were written. Other keys (amounts, statuses,
     * emails) only match within the time window.
     */
    function rh_hist_identity_key(string $k): bool
    {
        if ($k === 'created' || $k === 'price-explain') return true;
        if (strpos($k, 'payment:amt:') === 0) return false;
        foreach (['payment:', 'refund:', 'cn:', 'paydel:', 'cnemail:', 'cnvoid:', 'receipt:', 'ph:'] as $p) {
            if (strpos($k, $p) === 0 && strlen($k) > strlen($p)) return true;
        }
        return false;
    }

    /** Should these two events be shown as one step? Two records of their own (rank 3) never merge with each other. */
    function rh_hist_same_step(array $x, array $e, int $window): bool
    {
        if ($x['rank'] >= 3 && $e['rank'] >= 3) return false;
        $shared = array_intersect($x['keys'], $e['keys']);
        if (!$shared) return false;
        foreach ($shared as $k) {
            if (rh_hist_identity_key((string)$k)) return true;
        }
        return abs($e['ts'] - $x['ts']) <= $window;
    }

    /**
     * De-duplicate (same record, or same key within $window seconds = one event; the higher rank is kept, a known
     * person, the earliest time and every distinct detail line are kept) and sort oldest first (ties: kind order, then
     * rank). Returns public events.
     */
    function rh_hist_merge(array $events, int $window = 5): array
    {
        $events = array_values(array_filter($events));
        foreach ($events as $i => &$e) {
            $e['seq'] = $i;
        }
        unset($e);
        usort($events, function ($a, $b) {
            return [$a['ts'], rh_hist_kind_order($a['kind']), -$a['rank'], $a['seq']] <=> [$b['ts'], rh_hist_kind_order($b['kind']), -$b['rank'], $b['seq']];
        });
        $kept = [];
        foreach ($events as $e) {
            $merged = false;
            for ($k = count($kept) - 1; $k >= 0; $k--) {
                $x = $kept[$k];
                if (!rh_hist_same_step($x, $e, $window)) continue;
                $base = $e['rank'] > $x['rank'] ? $e : $x;
                $other = $e['rank'] > $x['rank'] ? $x : $e;
                if (rh_hist_unknown_who($base['who']) && !rh_hist_unknown_who($other['who'])) $base['who'] = $other['who'];
                $lines = [];
                foreach ([$base['detail'], $other['detail']] as $d) {
                    foreach (preg_split('/\n/', (string)$d) as $ln) {
                        $ln = trim($ln);
                        if ($ln !== '' && !in_array($ln, $lines, true)) $lines[] = $ln;
                    }
                }
                $base['detail'] = implode("\n", $lines);
                $base['keys'] = array_values(array_unique(array_merge($base['keys'], $other['keys'])));
                $base['pids'] = array_values(array_unique(array_merge($base['pids'], $other['pids'])));
                $base['ts'] = min($x['ts'], $e['ts']);
                $base['at'] = date('Y-m-d H:i:s', $base['ts']);
                $base['seq'] = $x['seq'];
                $kept[$k] = $base;
                $merged = true;
                break;
            }
            if (!$merged) $kept[] = $e;
        }
        usort($kept, function ($a, $b) {
            return [$a['ts'], rh_hist_kind_order($a['kind']), -$a['rank'], $a['seq']] <=> [$b['ts'], rh_hist_kind_order($b['kind']), -$b['rank'], $b['seq']];
        });
        $out = [];
        foreach ($kept as $e) {
            $out[] = ['at' => $e['at'], 'at_label' => rh_hist_time_label($e['at']), 'who' => $e['who'], 'what' => $e['what'],
                'detail' => $e['detail'], 'kind' => $e['kind'], 'kind_label' => rh_hist_kind_label($e['kind']), 'pids' => $e['pids']];
        }
        return $out;
    }

    /**
     * Every source for one booking -> the step-by-step list. $src keys (all optional except booking): booking, staff,
     * cur, room_type_name, room_numbers, room_types, combo_names, combo_name, entity_names, snapshot, today_price,
     * price_changes (for the explanation), price_history (timeline rows), cn_numbers (id => number for notes issued elsewhere), audit, timeline, status_log, notes, payments,
     * payment_audit, credit_notes, cn_apps, cancellations, comms, auto_emails, tentative, booking_rooms,
     * financial_audit, system_events.
     */
    function rh_hist_build(array $src): array
    {
        $ctx = [
            'booking' => $src['booking'] ?? null,
            'staff' => $src['staff'] ?? [],
            'cur' => (string)($src['cur'] ?? 'MWK'),
            'room_type_name' => $src['room_type_name'] ?? '',
            'room_numbers' => $src['room_numbers'] ?? [],
            'room_types' => $src['room_types'] ?? [],
            'combo_names' => $src['combo_names'] ?? [],
            'combo_name' => $src['combo_name'] ?? '',
            'entity_names' => $src['entity_names'] ?? [],
            'cn_numbers' => $src['cn_numbers'] ?? [],
            'cn_by_payment' => [],
            'cn_payment_ids' => [],
            'pay_refs' => [],
        ];
        // Payments that have their own "created" audit line: that line names who saved them (no fallback needed).
        $ctx['pay_created_audit'] = [];
        foreach ($src['payment_audit'] ?? [] as $pa) {
            if (strtolower((string)($pa['action'] ?? '')) === 'created') $ctx['pay_created_audit'][(int)($pa['payment_id'] ?? 0)] = true;
        }
        foreach ($src['payments'] ?? [] as $p) {
            $ctx['pay_refs'][(int)$p['id']] = (string)($p['payment_reference'] ?? '');
            if (!empty($p['credit_note_id'])) $ctx['cn_payment_ids'][(int)$p['credit_note_id']] = (int)($p['original_payment_id'] ?? 0);
        }
        foreach ($src['credit_notes'] ?? [] as $cn) {
            $ctx['cn_numbers'][(int)$cn['id']] = (string)$cn['credit_note_number'];
            if ((string)($cn['reason'] ?? '') === 'overpayment' && preg_match('/on payment ([A-Za-z0-9_\/-]+)(?=\s|\[|$)/', (string)($cn['reason_notes'] ?? ''), $m)) {
                $ctx['cn_by_payment'][$m[1]] = $cn;
            }
        }

        $events = [];
        $b = $ctx['booking'];
        if ($b) {
            // The booking row itself: creation is always shown, even when no log recorded it.
            $ev = rh_hist_ev((string)($b['created_at'] ?? ''), 'Not recorded', 'Booking ' . ($b['booking_reference'] ?? '') . ' created',
                rh_hist_booking_line($b, $ctx), 'created', ['created'], 0);
            if ($ev) $events[] = $ev;
            if (!empty($b['last_quotation_sent_at'])) {
                $ev = rh_hist_ev((string)$b['last_quotation_sent_at'], 'Not recorded', 'Quotation emailed to the guest', 'The most recent quotation sent for this booking.', 'email', ['quote'], 0);
                if ($ev) $events[] = $ev;
            }
            $px = rh_hist_price_explanation($b, $src['snapshot'] ?? null, isset($src['today_price']) ? (float)$src['today_price'] : null, $src['price_changes'] ?? [], $ctx);
            if ($px) $events[] = $px;
        }
        $events = array_merge(
            $events,
            rh_hist_from_audit($src['audit'] ?? [], $ctx),
            rh_hist_from_timeline($src['timeline'] ?? [], $ctx),
            rh_hist_from_status_log($src['status_log'] ?? [], $ctx),
            rh_hist_from_notes($src['notes'] ?? [], $ctx),
            rh_hist_from_payments($src['payments'] ?? [], $ctx),
            rh_hist_from_payment_audit($src['payment_audit'] ?? [], $ctx),
            rh_hist_from_credit_notes($src['credit_notes'] ?? [], $ctx),
            rh_hist_from_cn_apps($src['cn_apps'] ?? [], $ctx),
            rh_hist_from_cancellations($src['cancellations'] ?? [], $ctx),
            rh_hist_from_emails($src['comms'] ?? [], $src['auto_emails'] ?? [], $ctx),
            rh_hist_from_tentative($src['tentative'] ?? [], $ctx),
            rh_hist_from_financial_audit($src['financial_audit'] ?? [], $ctx),
            rh_hist_from_system_events($src['system_events'] ?? [], $ctx),
            rh_hist_from_price_history($src['price_history'] ?? [], $ctx)
        );
        // Rooms last: their person comes from the creation / releasing step built above.
        $events = array_merge($events, rh_hist_from_booking_rooms($src['booking_rooms'] ?? [], $ctx, array_values(array_filter($events))));
        return rh_hist_merge($events);
    }

    /** A booking's merged history cut down to one payment: the booking's money steps plus anything tied to the payment. */
    function rh_hist_payment_filter(array $events, array $paymentIds): array
    {
        $money = ['created', 'price', 'payment', 'refund', 'credit_note', 'cancel'];
        $paymentIds = array_map('intval', $paymentIds);
        $out = [];
        foreach ($events as $e) {
            if (in_array($e['kind'], $money, true) || array_intersect($paymentIds, $e['pids'] ?? [])) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** The sentence logged when a payment is saved (payment-add): who, amount, method, owed before and after, overpay. */
    function rh_hist_payment_saved_note(float $amount, string $method, ?float $before, ?float $after, bool $overpayTicked, ?string $cnNumber, ?float $cnAmount, string $cur): string
    {
        $s = 'Received ' . rh_hist_money($amount, $cur) . ($method !== '' ? ' by ' . strtolower(rh_hist_words($method)) : '') . '.';
        if ($before !== null) $s .= ' Owed before: ' . rh_hist_money(max(0, $before), $cur) . '.';
        if ($after !== null) $s .= ' Owed after: ' . rh_hist_money(max(0, $after), $cur) . '.';
        if ($overpayTicked) $s .= ' The overpay box was ticked.';
        if ($cnNumber) $s .= ' Credit note ' . $cnNumber . ($cnAmount !== null ? ' for ' . rh_hist_money($cnAmount, $cur) : '') . ' issued for the extra.';
        return $s;
    }
}
