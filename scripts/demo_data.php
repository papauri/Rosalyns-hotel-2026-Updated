<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

/**
 * Demo data for training/testing on a live installation - every row is tagged so it can be
 * removed exactly.
 *
 *   php scripts/demo_data.php seed-settings [--dry-run]  fill EMPTY "Hotel details & policies"
 *                                                       fields with clearly marked SAMPLE values
 *   php scripts/demo_data.php seed [--dry-run]          bookings (every status), payments, an event
 *                                                       with RSVPs/waitlist, conference and gym
 *                                                       records, restaurant orders, a contact
 *                                                       message, housekeeping and maintenance tasks
 *   php scripts/demo_data.php purge [--dry-run]         remove all of it (and the SAMPLE settings)
 *
 * Tags: references start with DEMO, people are "DEMO ...", emails are @example.com (a domain
 * that can never receive mail). The demo event is inactive (not on the public site). Automated
 * emails are pre-marked as handled for the demo accounts, so nobody is ever mailed. Demo orders
 * do not touch stock. --dry-run does everything inside a transaction and rolls it back.
 */

chdir(dirname(__DIR__));
require 'config/database.php';
require_once 'includes/booking-timeline.php';

$cmd = $argv[1] ?? '';
$dry = in_array('--dry-run', $argv, true);
if (!in_array($cmd, ['seed', 'seed-settings', 'purge'], true)) {
    exit("Usage: php scripts/demo_data.php seed|seed-settings|purge [--dry-run]\n");
}

$say = static fn(string $m) => print("  $m\n");
$q = static function (string $sql, array $p = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st;
};
$today = new DateTimeImmutable('today');
$d = static fn(int $days): string => $today->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
$money = static function (float $gross): array {
    $c = vat_components($gross);
    return ['net' => (float)$c['net'], 'vat' => (float)$c['vat'], 'gross' => (float)$c['total'], 'rate' => (float)$c['rate']];
};

$pdo->beginTransaction();
try {
    if ($cmd === 'seed-settings') {
        require_once 'admin/includes/hotel-details-settings.php';
        $samples = [
            'site_short_name' => 'SAMPLE', 'hotel_address' => 'SAMPLE address - replace in Hotel Settings',
            'address_region' => 'SAMPLE region', 'phone_secondary' => '+265 000 000 000',
            'admin_notification_email' => 'sample-notify@example.com',
            'bank_name' => 'SAMPLE Bank', 'bank_account_name' => 'SAMPLE Account Name',
            'bank_account_number' => 'SAMPLE-000000', 'bank_branch' => 'SAMPLE Branch',
            'invoice_terms' => 'SAMPLE terms - replace in Hotel Settings',
            'invoice_footer' => 'SAMPLE invoice footer - replace in Hotel Settings',
            'receipt_footer' => 'SAMPLE receipt footer - replace in Hotel Settings',
            'quotation_footer_text' => 'SAMPLE quotation footer - replace in Hotel Settings',
            'eod_report_cc_emails' => 'sample-eod@example.com',
        ];
        $filled = json_decode((string)getSetting('demo_sample_settings', '[]'), true) ?: [];
        foreach (rh_hotel_details_fields() as $fields) {
            foreach ($fields as $key => $spec) {
                if (!isset($samples[$key])) {
                    continue; // public links (map, menu PDF) are left empty: a fake link would show on the site
                }
                $stored = getSetting($key, null);
                if ($stored !== null && trim((string)$stored) !== '') {
                    continue; // only fill what is empty
                }
                updateSetting($key, $samples[$key]);
                $filled[$key] = $samples[$key];
                $say("$key = {$samples[$key]}");
            }
        }
        updateSetting('demo_sample_settings', json_encode($filled));
        $say(count($filled) . ' sample setting(s) recorded for purge.');
    }

    if ($cmd === 'seed') {
        if ((int)$q("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'DEMO%'")->fetchColumn() > 0) {
            throw new RuntimeException('Demo data already present - run purge first.');
        }
        // Room type with physical rooms, for the stays.
        $type = $q("SELECT r.id, r.price_per_night FROM rooms r WHERE r.is_active = 1 AND EXISTS (SELECT 1 FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1 AND ir.status NOT IN ('maintenance','out_of_order')) ORDER BY r.display_order, r.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$type) {
            throw new RuntimeException('No active room type with rooms.');
        }
        $rate = (float)$type['price_per_night'];
        $bookings = [
            // ref, status, in, out, payment share, extra
            ['DEMO-B1', 'pending', 14, 17, 0.0, []],
            ['DEMO-B2', 'confirmed', 7, 9, 0.3, []],
            ['DEMO-B3', 'checked-in', -1, 2, 0.5, ['assign' => true]],
            ['DEMO-B4', 'checked-out', -5, -2, 0.5, ['checkout' => true]],
            ['DEMO-B5', 'cancelled', 20, 22, 0.0, []],
            ['DEMO-B6', 'tentative', 10, 12, 0.0, ['tentative' => true]],
        ];
        $n = 0;
        foreach ($bookings as [$ref, $status, $in, $out, $share, $x]) {
            $n++;
            $nights = $out - $in;
            $m = $money($rate * $nights);
            $q("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
                    check_in_date, check_out_date, number_of_nights, total_amount, vat_rate, vat_amount, total_with_vat, amount_paid, amount_due,
                    special_requests, status, payment_status, occupancy_type, is_tentative, tentative_expires_at, reminder_sent, checkout_completed_at, created_at)
                VALUES (?, ?, ?, ?, '+265 000 000 000', 'Malawi', 2, 2, 0, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'DEMO DATA - safe to delete', ?, 'unpaid', 'double', ?, ?, ?, ?, NOW())", [
                $ref, (int)$type['id'], 'DEMO Guest ' . $n, 'demo.guest' . $n . '@example.com', $d($in), $d($out), $nights,
                $m['net'], $m['rate'], $m['vat'], $m['gross'], $m['gross'], $status,
                !empty($x['tentative']) ? 1 : 0, !empty($x['tentative']) ? $today->modify('+2 days')->format('Y-m-d 18:00:00') : null,
                !empty($x['tentative']) ? 1 : 0, !empty($x['checkout']) ? $today->modify('-2 days')->format('Y-m-d 11:00:00') : null,
            ]);
            $id = (int)$pdo->lastInsertId();
            if ($share > 0) {
                $p = $money(round($m['gross'] * $share, 2));
                $q("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                        payment_method, payment_type, payment_status, status, receipt_number, notes)
                    VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, 'mobile_money', 'deposit', 'completed', 'completed', ?, 'DEMO DATA - safe to delete')",
                    ['DEMO-PAY-' . $n, $id, $ref, $p['net'], $p['rate'], $p['vat'], $p['gross'], 'DEMO-RCP-' . $n]);
            }
            recalculateBookingFinancials($id);
            if (!empty($x['assign'])) {
                $room = $q("SELECT id FROM individual_rooms WHERE room_type_id = ? AND is_active = 1 AND status = 'available' ORDER BY display_order, id LIMIT 1", [(int)$type['id']])->fetchColumn();
                if ($room) {
                    $q('UPDATE bookings SET individual_room_id = ? WHERE id = ?', [(int)$room, $id]);
                    updateBookingRoomsStatus($id, 'occupied', 'DEMO check-in', null);
                }
            }
            // Never e-mail anyone about demo accounts: mark every automated stage as handled.
            foreach (['overdue_payment_reminders' => ['r1', 'r2', 'r3'], 'tentative_expired_notice' => ['expired'], 'tentative_hold_reminder' => ['hold:' . $today->modify('+2 days')->format('Y-m-d 18:00')]] as $job => $stages) {
                foreach ($stages as $stage) {
                    $q("INSERT IGNORE INTO automated_email_log (job, account_type, account_id, stage, status, error) VALUES (?, 'room', ?, ?, 'skipped', 'DEMO data - suppressed')", [$job, $id, $stage]);
                }
            }
            logBookingEvent($id, $ref, 'Demo booking created', 'create', 'DEMO DATA - safe to delete', null, $status, 'system');
            $say("booking $ref ($status) id $id");
        }

        // Event with RSVPs incl. waitlist (event inactive: not shown on the public site).
        $q("INSERT INTO events (title, description, event_date, start_time, end_time, location, ticket_price, capacity, is_active)
            VALUES ('DEMO Event - safe to delete', 'DEMO DATA - safe to delete', ?, '18:00:00', '22:00:00', 'Main lawn', 25000, 10, 0)", [$d(30)]);
        $eventId = (int)$pdo->lastInsertId();
        foreach ([['DEMO-E1', 'pending', 2], ['DEMO-E2', 'confirmed', 8], ['DEMO-E3', 'waitlisted', 4]] as $i => [$ref, $status, $guests]) {
            $q("INSERT INTO event_inquiries (reference_number, event_id, name, email, phone, guests, message, consent, status, notes)
                VALUES (?, ?, ?, ?, '+265 000 000 000', ?, 'DEMO DATA - safe to delete', 1, ?, 'DEMO')", [$ref, $eventId, 'DEMO Attendee ' . ($i + 1), 'demo.event' . ($i + 1) . '@example.com', $guests, $status]);
        }
        $say("event id $eventId with 3 RSVPs (pending, confirmed, waitlisted)");

        // Conference enquiries.
        $croom = (int)$q('SELECT id FROM conference_rooms ORDER BY id LIMIT 1')->fetchColumn();
        if ($croom) {
            foreach ([['DEMO-C1', 'pending', 20], ['DEMO-C2', 'confirmed', 35]] as $i => [$ref, $status, $people]) {
                $q("INSERT INTO conference_inquiries (inquiry_reference, conference_room_id, company_name, contact_person, email, phone, event_date, start_time, end_time,
                        number_of_attendees, event_type, special_requirements, status, notes)
                    VALUES (?, ?, 'DEMO Company Ltd', ?, ?, '+265 000 000 000', ?, '09:00:00', '17:00:00', ?, 'meeting', 'DEMO DATA - safe to delete', ?, 'DEMO')",
                    [$ref, $croom, 'DEMO Organiser ' . ($i + 1), 'demo.conf' . ($i + 1) . '@example.com', $d(21 + $i), $people, $status]);
            }
            $say('2 conference enquiries');
        }

        // Gym: members (one expiring soon - its reminder pre-marked as sent) and an enquiry.
        foreach ([['DEMO-M1', 3], ['DEMO-M2', 30]] as $i => [$num, $exp]) {
            $q("INSERT INTO gym_members (member_number, full_name, email, phone, membership_type, start_date, expiry_date, status, monthly_fee, notes)
                VALUES (?, ?, ?, '+265 000 000 000', 'Monthly', ?, ?, 'active', 60000, 'DEMO DATA - safe to delete')",
                [$num, 'DEMO Member ' . ($i + 1), 'demo.member' . ($i + 1) . '@example.com', $d($exp - 30), $d($exp)]);
            $mid = (int)$pdo->lastInsertId();
            $q("INSERT IGNORE INTO gym_reminder_log (member_id, member_number, expiry_date, reminder_days, sent_to) VALUES (?, ?, ?, 0, 'DEMO-suppressed')", [$mid, $num, $d($exp)]);
        }
        $q("INSERT INTO gym_inquiries (reference_number, name, email, phone, membership_type, message, consent, status, notes)
            VALUES ('DEMO-G1', 'DEMO Gym Enquirer', 'demo.gym@example.com', '+265 000 000 000', 'Monthly', 'DEMO DATA - safe to delete', 1, 'pending', 'DEMO')");
        $say('2 gym members + 1 gym enquiry');

        // Restaurant: one paid order and one open tab (no stock movement).
        $food = $q("SELECT id, item_name, price FROM food_menu WHERE is_available = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
        if ($food) {
            foreach ([['DEMO-ORD-1', 'paid'], ['DEMO-ORD-2', 'placed']] as [$ref, $status]) {
                $lines = $status === 'paid' ? $food : array_slice($food, 0, 1);
                $sum = array_sum(array_map(static fn($f) => (float)$f['price'], $lines));
                $q("INSERT INTO stock_orders (reference, order_type, table_number, covers, customer_name, notes, status, payment_method, total_amount, subtotal, paid_at, created_at, fired_at, opened_as_tab)
                    VALUES (?, 'dine_in', 'DEMO', 2, 'DEMO Diner', 'DEMO DATA - safe to delete', ?, ?, ?, ?, ?, NOW(), NOW(), ?)",
                    [$ref, $status, $status === 'paid' ? 'cash' : null, $sum, $sum, $status === 'paid' ? date('Y-m-d H:i:s') : null, $status === 'paid' ? 0 : 1]);
                $oid = (int)$pdo->lastInsertId();
                foreach ($lines as $f) {
                    $q("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, unit_price, quantity, line_total, kds_status, station, stock_deducted)
                        VALUES (?, ?, 'food', ?, ?, 1, ?, ?, 'kitchen', 0)", [$oid, (int)$f['id'], $f['item_name'], $f['price'], $f['price'], $status === 'paid' ? 'served' : 'pending']);
                }
                if ($status === 'paid') {
                    $p = $money($sum);
                    $q("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                            payment_method, payment_type, payment_status, status, receipt_number, notes)
                        VALUES ('DEMO-PAY-R1', 'restaurant', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'full_payment', 'completed', 'completed', 'DEMO-RCP-R1', 'DEMO DATA - safe to delete')",
                        [$oid, $ref, $p['net'], $p['rate'], $p['vat'], $p['gross']]);
                }
            }
            $say('2 restaurant orders (1 paid, 1 open tab)');
        }

        // Contact message, housekeeping task, maintenance schedule (does not block the room).
        $q("INSERT INTO contact_inquiries (reference_number, name, email, phone, subject, message, consent, status)
            VALUES ('DEMO-Q1', 'DEMO Visitor', 'demo.contact@example.com', '+265 000 000 000', 'DEMO question', 'DEMO DATA - safe to delete', 1, 'new')");
        $hkRoom = (int)$q("SELECT id FROM individual_rooms WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($hkRoom) {
            $q("INSERT INTO housekeeping_assignments (individual_room_id, status, due_date, notes, priority, assignment_type) VALUES (?, 'pending', ?, 'DEMO DATA - safe to delete', 'low', 'regular_cleaning')", [$hkRoom, $d(5)]);
            $q("INSERT INTO room_maintenance_schedules (individual_room_id, title, description, status, priority, maintenance_type, block_room, start_date, end_date, due_date)
                VALUES (?, 'DEMO maintenance - safe to delete', 'DEMO DATA', 'planned', 'low', 'inspection', 0, ?, ?, ?)", [$hkRoom, $d(40) . ' 09:00:00', $d(40) . ' 12:00:00', $d(40)]);
            $say('contact message, housekeeping task, maintenance schedule');
        }
    }

    if ($cmd === 'purge') {
        $ids = $q("SELECT id FROM bookings WHERE booking_reference LIKE 'DEMO-%' AND special_requests = 'DEMO DATA - safe to delete'")->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            // Free rooms the demo stays occupied.
            $pdo->exec("UPDATE individual_rooms ir JOIN bookings b ON b.individual_room_id = ir.id SET ir.status = 'available' WHERE b.id IN ($in) AND b.status = 'checked-in' AND ir.status = 'occupied'");
            foreach (['booking_timeline_logs', 'booking_audit_log', 'booking_rooms', 'booking_charges', 'booking_notes', 'cancellation_log', 'guest_communication_log'] as $t) {
                try { $n = $pdo->exec("DELETE FROM `$t` WHERE booking_id IN ($in)"); if ($n) $say("$t: $n"); } catch (Throwable $e) { /* table absent */ }
            }
            $say('automated_email_log: ' . $pdo->exec("DELETE FROM automated_email_log WHERE account_type = 'room' AND account_id IN ($in)"));
            $say('room payments: ' . $pdo->exec("DELETE FROM payments WHERE booking_type = 'room' AND booking_id IN ($in) AND payment_reference LIKE 'DEMO-PAY-%'"));
            $say('bookings: ' . $pdo->exec("DELETE FROM bookings WHERE id IN ($in)"));
        }
        $orders = $q("SELECT id FROM stock_orders WHERE reference LIKE 'DEMO-ORD-%' AND notes = 'DEMO DATA - safe to delete'")->fetchAll(PDO::FETCH_COLUMN);
        if ($orders) {
            $in = implode(',', array_map('intval', $orders));
            $say('restaurant payments: ' . $pdo->exec("DELETE FROM payments WHERE booking_type = 'restaurant' AND booking_id IN ($in) AND payment_reference LIKE 'DEMO-PAY-%'"));
            foreach (['stock_kds_events', 'pos_ready_notifications', 'station_messages', 'stock_order_audit', 'stock_order_deliveries', 'stock_order_splits', 'stock_order_items'] as $t) {
                try { $pdo->exec("DELETE FROM `$t` WHERE order_id IN ($in)"); } catch (Throwable $e) { /* table absent */ }
            }
            $say('orders: ' . $pdo->exec("DELETE FROM stock_orders WHERE id IN ($in)"));
        }
        $say('event RSVPs: ' . $pdo->exec("DELETE FROM event_inquiries WHERE reference_number LIKE 'DEMO-E%' AND notes = 'DEMO'"));
        $say('events: ' . $pdo->exec("DELETE FROM events WHERE title = 'DEMO Event - safe to delete'"));
        $say('conference: ' . $pdo->exec("DELETE FROM conference_inquiries WHERE inquiry_reference LIKE 'DEMO-C%' AND notes = 'DEMO'"));
        $members = $q("SELECT id FROM gym_members WHERE member_number LIKE 'DEMO-M%' AND notes = 'DEMO DATA - safe to delete'")->fetchAll(PDO::FETCH_COLUMN);
        if ($members) {
            $in = implode(',', array_map('intval', $members));
            try { $pdo->exec("DELETE FROM gym_reminder_log WHERE member_id IN ($in)"); } catch (Throwable $e) {}
            $say('gym members: ' . $pdo->exec("DELETE FROM gym_members WHERE id IN ($in)"));
        }
        $say('gym enquiries: ' . $pdo->exec("DELETE FROM gym_inquiries WHERE reference_number = 'DEMO-G1' AND notes = 'DEMO'"));
        $say('contact: ' . $pdo->exec("DELETE FROM contact_inquiries WHERE reference_number = 'DEMO-Q1'"));
        $say('housekeeping: ' . $pdo->exec("DELETE FROM housekeeping_assignments WHERE notes = 'DEMO DATA - safe to delete'"));
        $say('maintenance: ' . $pdo->exec("DELETE FROM room_maintenance_schedules WHERE title = 'DEMO maintenance - safe to delete'"));
        // Sample settings: only reset values still equal to what was filled in.
        $filled = json_decode((string)getSetting('demo_sample_settings', '[]'), true) ?: [];
        foreach ($filled as $key => $val) {
            if ((string)getSetting($key, '') === (string)$val) {
                updateSetting($key, '');
                $say("setting $key cleared");
            }
        }
        updateSetting('demo_sample_settings', '[]');
    }

    if ($dry) {
        $pdo->rollBack();
        echo "DRY RUN - rolled back, nothing kept.\n";
    } else {
        $pdo->commit();
        echo strtoupper($cmd) . " done.\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'FAILED (nothing changed): ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    exit(1);
}
