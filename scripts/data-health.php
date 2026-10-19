<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

/**
 * Linked-data health check (read-only). Usage: php scripts/data-health.php [--list]
 *
 * Finds rows left behind when their parent was deleted, cancelled, voided or expired
 * (rule: .claude/plans/linked-data-cleanup.md).
 *  FAIL = something linked is still "running" (an open tab on a cancelled booking, a room still
 *         held, a live kitchen ticket) — a code path forgot to close it.
 *  WARN = a leftover child row with no parent (clutter, nothing running). Clean-up of live
 *         rows is an owner decision; this script never changes data.
 * --list prints the ids found for each failing/warning check.
 */
$root = dirname(__DIR__);
chdir($root);
require_once $root . '/config/database.php';

$list = in_array('--list', $argv, true);
// Booking that no longer holds anything (cancelled / expired / no-show, or soft-deleted).
$gone = "(b.status IN ('cancelled','expired','no-show') OR b.deleted_at IS NOT NULL)";
$openOrder = "o.status IN ('placed','pending','confirmed')";

$checks = [
    // ---- FAIL: something still running ----
    ['FAIL', 'Open tabs/orders on a cancelled, expired, no-show or deleted booking',
        "SELECT o.id FROM stock_orders o JOIN bookings b ON b.id = o.booking_id WHERE $openOrder AND $gone"],
    ['FAIL', 'Open tabs/orders whose booking no longer exists',
        "SELECT o.id FROM stock_orders o LEFT JOIN bookings b ON b.id = o.booking_id WHERE o.booking_id IS NOT NULL AND b.id IS NULL AND $openOrder"],
    ['FAIL', 'Live kitchen tickets on cancelled/voided orders',
        "SELECT o.id FROM stock_orders o WHERE o.status IN ('cancelled','voided') AND o.kitchen_status IN ('new','in_progress','ready')"],
    ['FAIL', 'Rooms still held (booking_rooms) by a cancelled/deleted booking',
        "SELECT br.id FROM booking_rooms br JOIN bookings b ON b.id = br.booking_id WHERE br.released_at IS NULL AND $gone"],
    ['FAIL', 'Cancelled/deleted bookings still pointing at a room or joined pair',
        "SELECT b.id FROM bookings b WHERE (b.individual_room_id IS NOT NULL OR b.room_combination_id IS NOT NULL) AND $gone"],
    ['FAIL', 'Open housekeeping tasks for a cancelled/deleted booking',
        "SELECT h.id FROM housekeeping_assignments h JOIN bookings b ON b.id = h.linked_booking_id WHERE h.status IN ('pending','in_progress','blocked') AND $gone"],
    ['FAIL', 'Planned maintenance tied to a cancelled/deleted booking',
        "SELECT m.id FROM room_maintenance_schedules m JOIN bookings b ON b.id = m.linked_booking_id WHERE m.status IN ('planned','in_progress') AND $gone"],
    ['FAIL', 'Folio charges still live for a cancelled/voided order',
        "SELECT c.id FROM booking_charges c JOIN stock_orders o ON o.id = c.stock_order_id WHERE c.voided = 0 AND o.status IN ('cancelled','voided')"],
    ['FAIL', 'Folio charges still live on a deleted booking',
        // Consumed room service (its order closed as 'completed') stays charged on a deleted booking on purpose (owner, 2026-10-20).
        "SELECT c.id FROM booking_charges c JOIN bookings b ON b.id = c.booking_id
          WHERE c.voided = 0 AND b.deleted_at IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM stock_orders so WHERE so.id = c.stock_order_id AND so.status = 'completed')"],
    ['FAIL', 'Ready notifications for cancelled/voided/missing orders',
        "SELECT n.id FROM pos_ready_notifications n LEFT JOIN stock_orders o ON o.id = n.order_id WHERE o.id IS NULL OR o.status IN ('cancelled','voided')"],
    ['FAIL', 'Quotations still "sent" for a cancelled/deleted booking',
        "SELECT q.id FROM quotations q JOIN bookings b ON b.id = q.booking_id WHERE q.booking_type = 'room' AND q.status = 'sent' AND $gone"],
    ['FAIL', 'Gym class bookings / slot reservations for cancelled members',
        "SELECT x.id FROM gym_slot_reservations x JOIN gym_members m ON m.id = x.member_id WHERE m.status = 'cancelled' AND x.status = 'booked'"],

    // ---- WARN: leftover rows with no parent ----
    ['WARN', 'Room payments whose booking is missing',
        "SELECT p.id FROM payments p LEFT JOIN bookings b ON b.id = p.booking_id WHERE p.booking_type = 'room' AND p.booking_id IS NOT NULL AND b.id IS NULL"],
    ['WARN', 'Restaurant payments whose order is missing',
        "SELECT p.id FROM payments p LEFT JOIN stock_orders o ON o.id = p.booking_id WHERE p.booking_type = 'restaurant' AND p.booking_id IS NOT NULL AND o.id IS NULL"],
    ['WARN', 'Folio charges pointing at a missing order',
        "SELECT c.id FROM booking_charges c LEFT JOIN stock_orders o ON o.id = c.stock_order_id WHERE c.stock_order_id IS NOT NULL AND o.id IS NULL"],
    ['WARN', 'Station messages for missing orders',
        "SELECT m.id FROM station_messages m LEFT JOIN stock_orders o ON o.id = m.order_id WHERE m.order_id IS NOT NULL AND o.id IS NULL"],
    ['WARN', 'booking_rooms rows whose booking is missing',
        "SELECT br.id FROM booking_rooms br LEFT JOIN bookings b ON b.id = br.booking_id WHERE b.id IS NULL"],
];
foreach (['receipt_events', 'mra_submission_queue', 'payment_audit_log', 'credit_note_applications'] as $t) {
    $checks[] = ['WARN', "$t rows whose payment is missing",
        "SELECT r.id FROM $t r LEFT JOIN payments p ON p.id = r.payment_id WHERE r.payment_id IS NOT NULL AND p.id IS NULL"
        // receipt_events of a deleted POS order are kept on purpose with payment_id = 0 (pos-order-delete.php).
        . ($t === 'receipt_events' ? ' AND r.payment_id <> 0' : '')];
}
foreach (['gym_attendance', 'gym_class_enrollments', 'gym_slot_reservations', 'gym_reminder_log'] as $t) {
    $checks[] = ['WARN', "$t rows whose member is missing",
        "SELECT x.id FROM $t x LEFT JOIN gym_members m ON m.id = x.member_id WHERE m.id IS NULL"];
}
foreach (['individual_room_amenities', 'individual_room_photos', 'housekeeping_assignments', 'room_maintenance_schedules', 'room_maintenance_blocks', 'booking_rooms'] as $t) {
    $checks[] = ['WARN', "$t rows whose individual room is missing",
        "SELECT x.id FROM $t x LEFT JOIN individual_rooms r ON r.id = x.individual_room_id WHERE x.individual_room_id IS NOT NULL AND r.id IS NULL"];
}
foreach (['gallery', 'blocked_dates', 'room_blocked_dates', 'individual_rooms'] as $t) {
    $col = $t === 'individual_rooms' ? 'room_type_id' : 'room_id';
    $checks[] = ['WARN', "$t rows whose room type is missing",
        "SELECT x.id FROM $t x LEFT JOIN rooms r ON r.id = x.$col WHERE x.$col IS NOT NULL AND x.$col <> 0 AND r.id IS NULL"];
}
$checks[] = ['WARN', 'event_inquiries whose event is missing',
    "SELECT x.id FROM event_inquiries x LEFT JOIN events e ON e.id = x.event_id WHERE x.event_id IS NOT NULL AND e.id IS NULL"];
$checks[] = ['WARN', 'Review responses whose review is missing',
    "SELECT x.id FROM review_responses x LEFT JOIN reviews r ON r.id = x.review_id WHERE r.id IS NULL"];
$checks[] = ['WARN', 'Purchase-order lines whose order is missing',
    "SELECT x.id FROM stock_purchase_order_items x LEFT JOIN stock_purchase_orders p ON p.id = x.purchase_order_id WHERE p.id IS NULL"];
$checks[] = ['WARN', 'User permissions whose user is missing',
    "SELECT x.id FROM user_permissions x LEFT JOIN admin_users u ON u.id = x.user_id WHERE u.id IS NULL"];

$fail = 0;
$warn = 0;
$pass = 0;
foreach ($checks as [$level, $label, $sql]) {
    try {
        $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        // A table that does not exist on this install is not a defect.
        if (strpos($e->getMessage(), '42S02') !== false || strpos($e->getMessage(), '42S22') !== false) {
            continue;
        }
        echo "FAIL $label — query error: " . $e->getMessage() . "\n";
        $fail++;
        continue;
    }
    $n = count($ids);
    if ($n === 0) {
        $pass++;
        echo "PASS $label\n";
        continue;
    }
    $level === 'FAIL' ? $fail++ : $warn++;
    echo "$level $label: $n" . ($list ? ' (ids ' . implode(', ', array_slice($ids, 0, 50)) . ')' : '') . "\n";
}
echo "\n$pass passed, $fail failed" . ($warn ? ", $warn warning(s) — leftover rows, see --list" : '') . "\n";
exit($fail ? 1 : 0);
