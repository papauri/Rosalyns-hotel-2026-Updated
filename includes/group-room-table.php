<?php
/**
 * Group booking room table (group bookings plan A4 / R2, owner 2026-11-17).
 *
 * Every room of a group, ALL statuses (checked-out, no-show, cancelled ... a deleted room is left out), with each
 * room's money exactly as that room's own Booking Details page shows it:
 *   Billed  = getBookingFolioSummary().grand_total
 *   Paid    = getBookingFolioSummary().amount_paid (net of refunds)
 *   Owed    = getBookingFolioSummary().balance_due
 *   Credit  = paid above the bill, else the booking's credit_balance (booking-details.php rule)
 *   Refunds = money paid back out: refund rows completed / processing, not credit-note legs
 * Group totals are the sum over ALL rooms (never only the rows on screen). Net position = Σ owed − Σ credit
 * (display only). Rows: (id = root OR primary_booking_id = root) AND deleted_at IS NULL,
 * root = COALESCE(NULLIF(primary_booking_id, 0), id). Read-only.
 */

if (!function_exists('rh_group_room_table')) {

    /** The group root (lead) id for a booking id; 0 when the booking is not found. */
    function rh_group_root_id(PDO $pdo, int $bookingId): int
    {
        $st = $pdo->prepare('SELECT COALESCE(NULLIF(primary_booking_id, 0), id) FROM bookings WHERE id = ?');
        $st->execute([$bookingId]);
        return (int)$st->fetchColumn();
    }

    /** Credit on one room, the same rule as booking-details.php (paid above the bill, else credit_balance). */
    function rh_group_room_credit(array $fs, array $b): float
    {
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $credit = max(0.0, (float)($fs['amount_paid'] ?? 0) - (float)($fs['grand_total'] ?? 0));
        if ($credit <= $tol) {
            $credit = (float)($b['credit_balance'] ?? 0);
        }
        return round(max(0.0, $credit), 2);
    }

    /**
     * @return array{root:int, rows:array<int,array>, totals:array{billed:float,paid:float,owed:float,credit:float,refunds:float,net:float,rooms:int}}
     *         rows: id, ref, room, room_number, guest, check_in, check_out, nights, rate_compact, rate_text, status,
     *               billed, paid, owed, credit, refunds, credit_note_refunds, is_root
     */
    function rh_group_room_table(PDO $pdo, int $bookingId, string $cur = ''): array
    {
        if ($cur === '') {
            $cur = function_exists('getSetting') ? trim((string)getSetting('currency_symbol', 'MWK')) : 'MWK';
        }
        if (!function_exists('rh_booked_rate_line')) {
            require_once __DIR__ . '/booked-rate.php';
        }
        $out = ['root' => 0, 'rows' => [], 'totals' => ['billed' => 0.0, 'paid' => 0.0, 'owed' => 0.0, 'credit' => 0.0, 'refunds' => 0.0, 'net' => 0.0, 'rooms' => 0]];
        $root = rh_group_root_id($pdo, $bookingId);
        if ($root <= 0) {
            return $out;
        }
        $out['root'] = $root;
        $st = $pdo->prepare("
            SELECT b.*, COALESCE(r.name, 'Unknown') AS room_name, ir.room_number AS ir_number
            FROM bookings b
            LEFT JOIN rooms r ON r.id = b.room_id
            LEFT JOIN individual_rooms ir ON ir.id = b.individual_room_id
            WHERE (b.id = ? OR b.primary_booking_id = ?) AND b.deleted_at IS NULL
            ORDER BY (b.id = ?) DESC, b.id ASC
        ");
        $st->execute([$root, $root, $root]);
        $rf = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN COALESCE(payment_method, '') <> 'credit_note' THEN total_amount ELSE 0 END), 0) AS paid_out,
                COALESCE(SUM(CASE WHEN COALESCE(payment_method, '') = 'credit_note' THEN total_amount ELSE 0 END), 0) AS to_credit_note
            FROM payments
            WHERE booking_type = 'room' AND booking_id = ? AND deleted_at IS NULL
              AND payment_type = 'refund' AND refund_status IN ('completed', 'processing')
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $id = (int)$b['id'];
            $fs = getBookingFolioSummary($id);
            if (isset($fs['error'])) {
                $fs = ['grand_total' => 0, 'amount_paid' => 0, 'balance_due' => 0];
            }
            $rf->execute([$id]);
            $ref = $rf->fetch(PDO::FETCH_ASSOC) ?: [];
            $line = rh_booked_rate_line($b, null, ['currency' => $cur]);
            // Rooms still held (joined pairs: "101 + 102"); a stay that is over has released its holds: the primary room.
            $roomNo = function_exists('getBookingRoomLabel') ? getBookingRoomLabel($id, (string)($b['ir_number'] ?? '')) : (string)($b['ir_number'] ?? '');
            $guest = trim((string)($b['room_guest_name'] ?? ''));
            if ($guest === '') {
                $guest = trim((string)($b['guest_name'] ?? ''));
            }
            $row = [
                'id' => $id, 'ref' => (string)$b['booking_reference'], 'room' => (string)$b['room_name'], 'room_number' => $roomNo,
                'guest' => $guest, 'check_in' => substr((string)$b['check_in_date'], 0, 10), 'check_out' => substr((string)$b['check_out_date'], 0, 10),
                'nights' => (int)($b['number_of_nights'] ?? 0),
                'rate_compact' => !empty($line['ok']) ? (string)$line['compact'] : '', 'rate_text' => !empty($line['ok']) ? (string)$line['text'] : '',
                'status' => (string)$b['status'],
                'billed' => round((float)($fs['grand_total'] ?? 0), 2),
                'paid' => round((float)($fs['amount_paid'] ?? 0), 2),
                'owed' => round((float)($fs['balance_due'] ?? 0), 2),
                'credit' => rh_group_room_credit($fs, $b),
                'refunds' => round((float)($ref['paid_out'] ?? 0), 2),
                'credit_note_refunds' => round((float)($ref['to_credit_note'] ?? 0), 2),
                'is_root' => $id === $root,
            ];
            $out['rows'][] = $row;
            foreach (['billed', 'paid', 'owed', 'credit', 'refunds'] as $k) {
                $out['totals'][$k] = round($out['totals'][$k] + $row[$k], 2);
            }
        }
        $out['totals']['rooms'] = count($out['rows']);
        $out['totals']['net'] = round($out['totals']['owed'] - $out['totals']['credit'], 2);
        return $out;
    }
}
