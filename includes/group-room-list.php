<?php
/**
 * The rooms of a GROUP booking as guest-facing lines (Bundle B, owner 2026-11-19): one shared list for every group email
 * and document, so each room shows its own room type / number, its own dates and nights, the "Who's staying" name ONLY
 * when entered (bookings.room_guest_name, never the booker's name in its place) and, where the email already shows
 * money, its booked rate x nights (rh_booked_rate_line(): the rate it was booked at, never today's price).
 * A cancelled / no-show / expired room is listed with its state and never as payable: no booked rate, only the fee kept
 * when one is (cancellation_retained_amount; the posted group no-show / expiry charge). A booking outside a group
 * (fewer than two rooms) gives no list at all, so single bookings render exactly as before.
 *
 * PURE (no DB):
 *   rh_grl_dates(string $ci, string $co, int $fallbackNights = 0): string   "18 Dec 2026 to 21 Dec 2026 (3 nights)"
 *   rh_grl_room_title(array $r): string                                    "Deluxe Room - 12" (type, then number)
 *   rh_grl_state(array $r): string                                          'Cancelled' / 'No-show' / 'Expired' / ''
 *   rh_grl_line(array $r, array $opts = []): string                         one room, plain text
 *   rh_grl_lines(array $rows, array $opts = []): string[]                   every room; [] for fewer than 2 rooms
 *   rh_grl_html(array $lines, string $heading, string $intro = ''): string escaped HTML block ('' without lines)
 *   rh_grl_plain(array $lines, string $heading, string $intro = ''): string plain-text block ('' without lines)
 *   rh_grl_quote_sum(array $rows, array $lines): ?array                     a group quotation: live rooms + sums
 * Reads (read-only, never throw):
 *   rh_grl_rows(PDO $pdo, int $bookingId, string $currency = ''): array    the group's rows, prepared for rh_grl_line
 *   rh_grl_block(PDO $pdo, int $bookingId, array $opts = []): array{html:string,text:string,lines:string[]}
 *   rh_grl_quote(PDO $pdo, int $bookingId): ?array                          the group quotation (PDF, email, register,
 *                                                                           convert all take their total from it)
 *
 * Prepared row keys read by rh_grl_line: booking_reference, room_name (room type), room_label (room number(s)),
 * room_guest_name, check_in_date, check_out_date, number_of_nights, status, rh_rate_text (rh_booked_rate_line() 'text',
 * '' when none), rh_kept (fee kept on a closed room, 0 = none), rh_currency.
 * $opts: money (bool, add the booked rate x nights / the fee kept), ref (bool, default true: lead with the booking
 * reference), pdf (bool: plain "x" for the PDF core font).
 */

require_once __DIR__ . '/booked-rate.php'; // rh_booked_rate_line(), rh_booked_rate_money()

if (!function_exists('rh_grl_dates')) {
    /** PURE. "18 Dec 2026 to 21 Dec 2026 (3 nights)"; nights from the dates, else $fallbackNights. '' without both dates. */
    function rh_grl_dates(string $ci, string $co, int $fallbackNights = 0): string
    {
        $a = $ci !== '' ? strtotime($ci) : false;
        $b = $co !== '' ? strtotime($co) : false;
        if ($a === false || $b === false) {
            return '';
        }
        $nights = (int)round(($b - $a) / 86400);
        if ($nights <= 0) {
            $nights = $fallbackNights;
        }
        return date('d M Y', $a) . ' to ' . date('d M Y', $b) . ($nights > 0 ? ' (' . $nights . ' night' . ($nights === 1 ? '' : 's') . ')' : '');
    }
}

if (!function_exists('rh_grl_room_title')) {
    /** PURE. Room type, then the room number when assigned and not already in the type name. Plain text. */
    function rh_grl_room_title(array $r): string
    {
        $type = trim((string)($r['room_name'] ?? ''));
        $no = trim((string)($r['room_label'] ?? ''));
        return $no !== '' && stripos($type, $no) === false ? trim($type . ' - ' . $no, ' -') : $type;
    }
}

if (!function_exists('rh_grl_state')) {
    /** PURE. The closed state a room is listed with ('' = a live or stayed room). */
    function rh_grl_state(array $r): string
    {
        return ['cancelled' => 'Cancelled', 'no-show' => 'No-show', 'expired' => 'Expired'][strtolower(trim((string)($r['status'] ?? '')))] ?? '';
    }
}

if (!function_exists('rh_grl_line')) {
    /**
     * PURE. One room: "REF - Deluxe Room - 12 - Guest: Mr Banda - 18 Dec 2026 to 21 Dec 2026 (3 nights)", then with
     * money "- MWK 100,000.00 × 3 nights = MWK 300,000.00". A closed room: "... - Cancelled" (+ ", fee kept: MWK X" with
     * money when a fee was kept); never its booked rate. Plain text (escape on output).
     */
    function rh_grl_line(array $r, array $opts = []): string
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $money = !empty($opts['money']);
        $title = rh_grl_room_title($r);
        $head = array_key_exists('ref', $opts) && !$opts['ref']
            ? $title
            : trim(trim((string)($r['booking_reference'] ?? '')) . ($title !== '' ? ' - ' . $title : ''), ' -');
        $guest = trim((string)($r['room_guest_name'] ?? ''));
        $parts = [
            $head,
            $guest !== '' ? 'Guest: ' . $guest : '',
            rh_grl_dates((string)($r['check_in_date'] ?? ''), (string)($r['check_out_date'] ?? ''), (int)($r['number_of_nights'] ?? 0)),
        ];
        $state = rh_grl_state($r);
        if ($state !== '') {
            $kept = round((float)($r['rh_kept'] ?? 0), 2);
            $parts[] = $state . ($money && $kept > $tol
                ? ', fee kept: ' . rh_booked_rate_money($kept, (string)($r['rh_currency'] ?? 'MWK'))
                : '');
        } elseif ($money) {
            $parts[] = trim((string)($r['rh_rate_text'] ?? ''));
        }
        $line = implode(' - ', array_filter($parts, static function (string $x): bool {
            return $x !== '';
        }));
        return !empty($opts['pdf']) ? str_replace(['×', '·', '–'], ['x', '-', '-'], $line) : $line;
    }
}

if (!function_exists('rh_grl_lines')) {
    /** PURE. One line per room in the given order; [] when fewer than two rooms (a single booking has no list). */
    function rh_grl_lines(array $rows, array $opts = []): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        if (count($rows) < 2) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $l = rh_grl_line($r, $opts);
            if ($l !== '') {
                $out[] = $l;
            }
        }
        return count($out) < 2 ? [] : $out;
    }
}

if (!function_exists('rh_grl_html')) {
    /** PURE. The email block (same look as "Your rooms"); every line and the heading escaped. '' without lines. */
    function rh_grl_html(array $lines, string $heading, string $intro = ''): string
    {
        if (!$lines) {
            return '';
        }
        $esc = static function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        };
        $p = static function (string $t) use ($esc): string {
            return '<p style="margin:0 0 6px;color:#5C4A32;text-align:left;">' . $esc($t) . "</p>\n";
        };
        $html = '<div style="background:#FDF6EC;padding:15px;border-left:4px solid #8B7355;border-radius:5px;margin:20px 0;">' . "\n"
            . '<h3 style="color:#5C4A32;margin-top:0;text-align:left;">' . $esc($heading) . "</h3>\n";
        if (trim($intro) !== '') {
            $html .= $p($intro);
        }
        foreach ($lines as $l) {
            $html .= $p((string)$l);
        }
        return $html . "</div>\n";
    }
}

if (!function_exists('rh_grl_plain')) {
    /** PURE. The plain-text block: heading, intro, one line per room. '' without lines. */
    function rh_grl_plain(array $lines, string $heading, string $intro = ''): string
    {
        if (!$lines) {
            return '';
        }
        return implode("\n", array_merge([$heading], trim($intro) !== '' ? [$intro] : [], array_map('strval', $lines)));
    }
}

if (!function_exists('rh_grl_rows')) {
    /**
     * Every room of $bookingId's group (bookings.primary_booking_id: the lead, then the other rooms in id order), not
     * deleted, in ANY state (a cancelled / no-show room is listed with its state), prepared for rh_grl_line(). A booking
     * outside a group gives just its own row. Read-only; a read error gives [].
     */
    function rh_grl_rows(PDO $pdo, int $bookingId, string $currency = ''): array
    {
        if ($bookingId <= 0) {
            return [];
        }
        try {
            if ($currency === '') {
                $currency = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
                $currency = $currency !== '' ? $currency : 'MWK';
            }
            $st = $pdo->prepare('SELECT COALESCE(NULLIF(primary_booking_id, 0), id) FROM bookings WHERE id = ?');
            $st->execute([$bookingId]);
            $root = (int)$st->fetchColumn();
            if ($root <= 0) {
                return [];
            }
            $q = $pdo->prepare('SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id
                WHERE (b.id = ? OR b.primary_booking_id = ?) AND b.deleted_at IS NULL
                ORDER BY (b.id = ?) DESC, b.id ASC');
            $q->execute([$root, $root, $root]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $fee = $pdo->prepare('SELECT COALESCE(SUM(line_total), 0) FROM booking_charges WHERE booking_id = ? AND voided = 0 AND description LIKE ?');
            foreach ($rows as $i => $r) {
                $id = (int)$r['id'];
                $rows[$i]['room_label'] = function_exists('getBookingRoomLabel') ? trim(getBookingRoomLabel($id)) : '';
                $rows[$i]['rh_currency'] = $currency;
                $br = rh_booked_rate_line($r, null, ['currency' => $currency, 'room_name' => '']);
                $rows[$i]['rh_rate_text'] = !empty($br['ok']) ? (string)$br['text'] : '';
                $kept = 0.0;
                $status = strtolower((string)($r['status'] ?? ''));
                if ($status === 'cancelled') {
                    $kept = (float)($r['cancellation_retained_amount'] ?? 0);
                } elseif (in_array($status, ['no-show', 'expired'], true) && function_exists('rh_noshow_fee_tag')) {
                    $fee->execute([$id, '%' . rh_noshow_fee_tag($id)]);
                    $kept = (float)$fee->fetchColumn();
                }
                $rows[$i]['rh_kept'] = round($kept, 2);
            }
            return $rows;
        } catch (Throwable $e) {
            error_log('rh_grl_rows: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('rh_grl_quote_sum')) {
    /**
     * PURE. A GROUP quotation from prepared rows (rh_grl_rows) and each row's own document lines
     * (rh_doc_stay_lines(row, row.package_total), passed in $lines by the same index): only the LIVE rooms (a cancelled /
     * no-show / expired room is never quoted), each with its label (room type / number, the entered guest only), its
     * dates + nights and its own room amount; the sums of room, child, package, VAT, levy and total; deposit = the sum
     * of the live rooms' own deposits (deposit_required rows). null with fewer than two live rooms (one-room layout).
     */
    function rh_grl_quote_sum(array $rows, array $lines): ?array
    {
        $out = ['rooms' => [], 'room' => 0.0, 'child' => 0.0, 'pkg' => 0.0, 'vat' => 0.0, 'levy' => 0.0, 'total' => 0.0,
            'deposit' => 0.0, 'adults' => 0, 'children' => 0, 'ci' => '', 'co' => '', 'count' => 0];
        foreach (array_values($rows) as $i => $r) {
            if (!is_array($r) || rh_grl_state($r) !== '' || !isset($lines[$i]) || !is_array($lines[$i])) {
                continue;
            }
            $L = $lines[$i];
            $nights = (int)($r['number_of_nights'] ?? 0);
            $room = round((float)($L['room'] ?? 0), 2);
            $guest = trim((string)($r['room_guest_name'] ?? ''));
            $title = rh_grl_room_title($r);
            $out['rooms'][] = [
                'ref' => trim((string)($r['booking_reference'] ?? '')),
                'label' => $guest !== '' ? trim($title . ' – ' . $guest, ' –') : $title,
                'dates' => rh_grl_dates((string)($r['check_in_date'] ?? ''), (string)($r['check_out_date'] ?? ''), $nights),
                'nights' => $nights,
                'rate' => $nights > 0 ? round($room / $nights, 2) : $room,
                'room' => $room,
            ];
            foreach (['room', 'child', 'pkg', 'vat', 'levy', 'total'] as $k) {
                $out[$k] = round($out[$k] + (float)($L[$k] ?? 0), 2);
            }
            if (!empty($r['deposit_required'])) {
                $out['deposit'] = round($out['deposit'] + max(0.0, (float)($r['deposit_amount'] ?? 0)), 2);
            }
            $out['adults'] += (int)($r['adult_guests'] ?? $r['number_of_guests'] ?? 0);
            $out['children'] += (int)($r['child_guests'] ?? 0);
            $ci = (string)($r['check_in_date'] ?? '');
            $co = (string)($r['check_out_date'] ?? '');
            if ($ci !== '' && ($out['ci'] === '' || strcmp($ci, $out['ci']) < 0)) {
                $out['ci'] = $ci;
            }
            if ($co !== '' && ($out['co'] === '' || strcmp($co, $out['co']) > 0)) {
                $out['co'] = $co;
            }
        }
        $out['count'] = count($out['rooms']);
        return $out['count'] < 2 ? null : $out;
    }
}

if (!function_exists('rh_grl_quote')) {
    /**
     * The GROUP quotation of $bookingId's group (rh_grl_quote_sum over rh_grl_rows; each room priced on its own stored
     * gross basis, the same per-room basis the group invoice uses). The quotation PDF, the quotation email and the
     * quotations register all take their total from here, so the three always agree. null for a booking outside a
     * group, a group with fewer than two live rooms, or a read error (the one-room quotation, exactly as before).
     */
    function rh_grl_quote(PDO $pdo, int $bookingId): ?array
    {
        if ($bookingId <= 0) {
            return null;
        }
        if (!function_exists('rh_doc_stay_lines')) {
            require_once __DIR__ . '/pricing.php';
        }
        $rows = rh_grl_rows($pdo, $bookingId);
        if (count($rows) < 2) {
            return null;
        }
        $lines = [];
        foreach ($rows as $i => $r) {
            $lines[$i] = rh_doc_stay_lines($r, (float)($r['package_total'] ?? 0));
        }
        return rh_grl_quote_sum($rows, $lines);
    }
}

if (!function_exists('rh_grl_block')) {
    /**
     * The group room list of a booking's email: html (escaped) + text (plain) + lines. Everything '' / [] for a booking
     * outside a group or on a read error (information only: never stops an email). $opts: money (bool), heading, intro.
     */
    function rh_grl_block(PDO $pdo, int $bookingId, array $opts = []): array
    {
        $money = !empty($opts['money']);
        $lines = rh_grl_lines(rh_grl_rows($pdo, $bookingId), ['money' => $money]);
        $heading = (string)($opts['heading'] ?? 'Rooms in your group');
        $intro = (string)($opts['intro'] ?? ($money
            ? "Each room has its own booking reference, dates and the rate it was booked at:"
            : 'Each room has its own booking reference and dates:'));
        return [
            'html' => rh_grl_html($lines, $heading, $intro),
            'text' => rh_grl_plain($lines, $heading, $intro),
            'lines' => $lines,
        ];
    }
}
