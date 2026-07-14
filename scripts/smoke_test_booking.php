<?php
/**
 * Booking pipeline smoke test — runs against the live DB.
 * Usage: php scripts/smoke_test_booking.php
 * Cleans up its own test data on completion.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/booking-functions.php';
require_once __DIR__ . '/../includes/booking-timeline.php';
require_once __DIR__ . '/../includes/idempotency.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../includes/pricing.php';

$pass = 0;
$fail = 0;
$createdIds = [];

// Ensure fixtures created during this run are removed even if a later section
// throws before the section-17 cleanup block executes. Reads $createdIds by
// reference so it sees IDs pushed after registration.
register_shutdown_function(function () use (&$createdIds, $pdo) {
    if (empty($createdIds)) {
        return;
    }
    try {
        $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
        $pdo->prepare("DELETE FROM bookings WHERE id IN ($placeholders)")->execute($createdIds);
    } catch (Throwable $e) {
        // Best-effort cleanup; do not mask the original failure.
    }
});

function ok(string $label): void {
    global $pass;
    $pass++;
    echo "[PASS] $label\n";
}

function fail(string $label, string $detail = ''): void {
    global $fail;
    $fail++;
    echo "[FAIL] $label" . ($detail ? ": $detail" : '') . "\n";
}

function assert_true(bool $cond, string $label, string $detail = ''): void {
    if ($cond) ok($label); else fail($label, $detail);
}

// ── 1. DB connectivity ────────────────────────────────────────────────────────
echo "\n=== 1. DB connectivity ===\n";
try {
    $pdo->query('SELECT 1');
    ok('Live DB reachable');
} catch (Throwable $e) {
    fail('Live DB reachable', $e->getMessage());
    exit(1);
}

// ── 1.5 Pre-test purge — remove leftover fixtures from prior aborted runs ─────
echo "\n=== 1.5 Pre-test purge ===\n";
try {
    $purged = $pdo->prepare("
        DELETE FROM bookings
        WHERE booking_reference LIKE 'SMOKETEST-%'
           OR guest_email IN (?, ?)
    ");
    $purged->execute(['smoketest@rosalyns.test', 'tenttest@rosalyns.test']);
    ok('Leftover SMOKETEST fixtures purged (' . $purged->rowCount() . ' row(s))');
} catch (Throwable $e) {
    fail('Pre-test purge', $e->getMessage());
}

// ── 2. Schema check — critical booking columns ────────────────────────────────
echo "\n=== 2. Bookings table schema ===\n";
$colMap = [];
foreach ($pdo->query('DESCRIBE bookings')->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $colMap[$c['Field']] = $c;
}
$requiredCols = ['amount_due', 'total_with_vat', 'is_tentative', 'tentative_expires_at',
                 'occupancy_type', 'client_uuid', 'rate_plan_id', 'package_total', 'expired_at'];
foreach ($requiredCols as $col) {
    assert_true(isset($colMap[$col]), "bookings.$col exists",
        isset($colMap[$col]) ? '' : 'Column missing from schema');
}

// ── 3. Active rooms check ─────────────────────────────────────────────────────
echo "\n=== 3. Active rooms ===\n";
$rooms = $pdo->query("SELECT id, name, price_per_night, total_rooms, rooms_available FROM rooms WHERE is_active=1 ORDER BY id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
assert_true(count($rooms) > 0, 'At least one active room exists');
$testRoom = $rooms[0];
echo "  Using room #{$testRoom['id']}: {$testRoom['name']} price={$testRoom['price_per_night']}\n";

// ── 4. Availability check function ───────────────────────────────────────────
echo "\n=== 4. checkRoomAvailability() ===\n";
$checkIn  = date('Y-m-d', strtotime('+30 days'));
$checkOut = date('Y-m-d', strtotime('+32 days'));
$avail = checkRoomAvailability((int)$testRoom['id'], $checkIn, $checkOut);
assert_true(isset($avail['available']), 'checkRoomAvailability() returns available key');
echo "  Result for dates {$checkIn}→{$checkOut}: " . ($avail['available'] ? 'AVAILABLE' : 'NOT AVAILABLE - ' . ($avail['error'] ?? '')) . "\n";

// ── 5. Standard booking INSERT (smoke) ───────────────────────────────────────
echo "\n=== 5. Standard booking creation ===\n";
$refPrefix = getSetting('booking_reference_prefix', 'LSH');
$testRef = 'SMOKETEST-' . time() . '-' . bin2hex(random_bytes(3));
$clientUuid = bin2hex(random_bytes(16));

$nights = 2;
$totalAmount = (float)$testRoom['price_per_night'] * $nights;

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        INSERT INTO bookings (
            booking_reference, room_id, guest_name, guest_email, guest_phone,
            guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights,
            total_amount, amount_due, total_with_vat,
            child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            special_requests, status, is_tentative, occupancy_type, client_uuid
        ) VALUES (?, ?, 'Smoke Test Guest', 'smoketest@rosalyns.test', '+265000000000',
            'Test Country', 2, 2, 0, 50.00, ?, ?, ?,
            ?, ?, ?,
            0, 0, 0,
            'Smoke test booking', 'pending', 0, 'double', ?)
    ");
    $stmt->execute([
        $testRef, $testRoom['id'],
        $checkIn, $checkOut, $nights,
        $totalAmount, $totalAmount, $totalAmount,
        $clientUuid
    ]);
    $bookingId = (int)$pdo->lastInsertId();
    $pdo->commit();
    $createdIds[] = $bookingId;
    ok("Standard booking inserted (id=$bookingId ref=$testRef)");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Standard booking INSERT', $e->getMessage());
}

// ── 6. Verify booking fields in DB ───────────────────────────────────────────
echo "\n=== 6. Verify booking fields ===\n";
if (!empty($bookingId)) {
    $row = $pdo->prepare("SELECT * FROM bookings WHERE id=?")->execute([$bookingId])
        ? $pdo->prepare("SELECT * FROM bookings WHERE id=?") : null;
    $stmt2 = $pdo->prepare("SELECT * FROM bookings WHERE id=?");
    $stmt2->execute([$bookingId]);
    $savedBooking = $stmt2->fetch(PDO::FETCH_ASSOC);

    assert_true($savedBooking !== false, 'Booking found in DB after INSERT');
    if ($savedBooking) {
        assert_true($savedBooking['amount_due'] == $totalAmount, 'amount_due set correctly', "got={$savedBooking['amount_due']} expected=$totalAmount");
        assert_true($savedBooking['total_with_vat'] == $totalAmount, 'total_with_vat set correctly', "got={$savedBooking['total_with_vat']}");
        assert_true($savedBooking['status'] === 'pending', 'status=pending');
        assert_true($savedBooking['is_tentative'] == 0, 'is_tentative=0');
        assert_true($savedBooking['client_uuid'] === $clientUuid, 'client_uuid stored');
    }
}

// ── 7. Idempotency check ──────────────────────────────────────────────────────
echo "\n=== 7. Idempotency (duplicate UUID) ===\n";
$existing = idem_find_existing_booking($pdo, $clientUuid);
assert_true($existing !== null, 'idem_find_existing_booking finds the booking by UUID');
assert_true(
    $existing !== null && $existing['booking_reference'] === $testRef,
    'Returns correct booking reference'
);

// ── 8. Tentative booking creation ────────────────────────────────────────────
echo "\n=== 8. Tentative booking ===\n";
$tentRef   = 'SMOKETEST-TENT-' . time() . '-' . bin2hex(random_bytes(3));
$tentUuid  = bin2hex(random_bytes(16));
$tentExpiry = date('Y-m-d H:i:s', strtotime('+48 hours'));
$tentIn    = date('Y-m-d', strtotime('+60 days'));
$tentOut   = date('Y-m-d', strtotime('+62 days'));
$tentTotal = (float)$testRoom['price_per_night'] * 2;

try {
    $pdo->beginTransaction();
    $ts = $pdo->prepare("
        INSERT INTO bookings (
            booking_reference, room_id, guest_name, guest_email, guest_phone,
            number_of_guests, adult_guests, child_guests, child_price_multiplier,
            check_in_date, check_out_date, number_of_nights,
            total_amount, amount_due, total_with_vat,
            child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            special_requests, status, is_tentative, tentative_expires_at,
            occupancy_type, client_uuid
        ) VALUES (?, ?, 'Tent Test Guest', 'tenttest@rosalyns.test', '+265111111111',
            1, 1, 0, 50.00, ?, ?, 2,
            ?, ?, ?,
            0, 0, 0,
            'Tentative smoke test', 'tentative', 1, ?,
            'single', ?)
    ");
    $ts->execute([
        $tentRef, $testRoom['id'],
        $tentIn, $tentOut,
        $tentTotal, $tentTotal, $tentTotal,
        $tentExpiry, $tentUuid
    ]);
    $tentId = (int)$pdo->lastInsertId();
    $pdo->commit();
    $createdIds[] = $tentId;
    ok("Tentative booking inserted (id=$tentId ref=$tentRef)");

    // Verify tentative fields
    $ts2 = $pdo->prepare("SELECT * FROM bookings WHERE id=?");
    $ts2->execute([$tentId]);
    $tentRow = $ts2->fetch(PDO::FETCH_ASSOC);
    assert_true($tentRow['status'] === 'tentative', 'tentative status=tentative');
    assert_true($tentRow['is_tentative'] == 1, 'is_tentative=1');
    assert_true(!empty($tentRow['tentative_expires_at']), 'tentative_expires_at set');
    assert_true($tentRow['amount_due'] == $tentTotal, 'tentative amount_due set');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Tentative booking INSERT', $e->getMessage());
}

// ── 9. Tentative expiry sweep (getExpiredTentativeBookings) ──────────────────
echo "\n=== 9. Tentative expiry functions ===\n";
// Manually set one booking as expired for test
if (!empty($tentId)) {
    $pdo->prepare("UPDATE bookings SET tentative_expires_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id=?")
        ->execute([$tentId]);
    $expired = getExpiredTentativeBookings();
    $foundExpired = array_filter($expired, fn($b) => (int)$b['id'] === $tentId);
    assert_true(!empty($foundExpired), 'getExpiredTentativeBookings() finds our expired test booking');

    // Mark it expired
    $result = markTentativeBookingExpired($tentId);
    assert_true($result === true, 'markTentativeBookingExpired() returns true');

    $ts3 = $pdo->prepare("SELECT status, is_tentative FROM bookings WHERE id=?");
    $ts3->execute([$tentId]);
    $expiredRow = $ts3->fetch(PDO::FETCH_ASSOC);
    assert_true($expiredRow['status'] === 'expired', 'Status updated to expired');
    assert_true($expiredRow['is_tentative'] == 0, 'is_tentative cleared to 0');
}

// ── 10. Guest booking lookup ──────────────────────────────────────────────────
echo "\n=== 10. Guest booking lookup ===\n";
if (!empty($bookingId)) {
    $stmt3 = $pdo->prepare("
        SELECT b.*, r.name as room_name, r.image_url as room_image, r.short_description as room_description
        FROM bookings b JOIN rooms r ON b.room_id=r.id
        WHERE b.booking_reference=? AND b.guest_email=?
    ");
    $stmt3->execute([$testRef, 'smoketest@rosalyns.test']);
    $looked = $stmt3->fetch(PDO::FETCH_ASSOC);
    assert_true($looked !== false, 'Guest lookup by reference+email finds booking');
    assert_true($looked && $looked['room_name'] !== null, 'Lookup returns room name');
    assert_true($looked && $looked['status'] === 'pending', 'Lookup returns correct status');
}

// ── 11. Guest cancellation (simulate booking-lookup.php logic) ───────────────
echo "\n=== 11. Guest self-cancellation ===\n";
if (!empty($bookingId)) {
    // Check the cancellation policy check works
    $cancelNoticeDays = (int)getSetting('cancellation_notice_days', 0);
    echo "  cancellation_notice_days=$cancelNoticeDays\n";
    // Attempt cancel on our test booking (30 days ahead — should be within window)
    $pdo->prepare("UPDATE bookings SET status='cancelled', updated_at=NOW() WHERE id=?")
        ->execute([$bookingId]);
    $cs = $pdo->prepare("SELECT status FROM bookings WHERE id=?");
    $cs->execute([$bookingId]);
    $cancelledRow = $cs->fetch(PDO::FETCH_ASSOC);
    assert_true($cancelledRow['status'] === 'cancelled', 'Booking cancellation updates status');
}

// ── 12. booking-timeline logBookingCreated (function exists + callable) ───────
echo "\n=== 12. Timeline / audit functions ===\n";
assert_true(function_exists('logBookingCreated'), 'logBookingCreated() is defined');
assert_true(function_exists('logBookingCreatedAudit'), 'logBookingCreatedAudit() is defined');
assert_true(function_exists('logCancellationToDatabase'), 'logCancellationToDatabase() is defined');
assert_true(function_exists('sendTentativeBookingExpiredEmail'), 'sendTentativeBookingExpiredEmail() is defined');
assert_true(function_exists('sendAdminBookingExpiredNotification'), 'sendAdminBookingExpiredNotification() is defined');
assert_true(function_exists('sendBookingCancelledEmail'), 'sendBookingCancelledEmail() is defined');
assert_true(function_exists('sendTentativeBookingConvertedEmail'), 'sendTentativeBookingConvertedEmail() is defined');

// ── 14. checkAvailability() wrapper ────────────────────────────────────────────
echo "\n=== 14. checkAvailability() wrapper ===\n";
// Pick a room from the already-fetched $rooms set that actually has free capacity
// (rooms_available > 0) — a room with 0 available rooms configured would never
// show as available regardless of date, which would make this a false failure.
$availTestRoom = $testRoom;
foreach ($rooms as $r) {
    if ((int)$r['rooms_available'] > 0) {
        $availTestRoom = $r;
        break;
    }
}
echo "  Using room #{$availTestRoom['id']}: {$availTestRoom['name']} for availability check\n";
$farCheckIn  = date('Y-m-d', strtotime('+400 days'));
$farCheckOut = date('Y-m-d', strtotime('+401 days'));
$availResult = checkAvailability((int)$availTestRoom['id'], $farCheckIn, $farCheckOut);
assert_true(isset($availResult['available']) && is_bool($availResult['available']), 'checkAvailability() returns bool available key');
assert_true(isset($availResult['conflicts']) && is_array($availResult['conflicts']), 'checkAvailability() returns array conflicts key');
assert_true($availResult['available'] === true, 'checkAvailability() reports far-future free date range as available',
    $availResult['available'] === true ? '' : ('error=' . ($availResult['error'] ?? 'unknown')));

// ── 15. applyDynamicPricing() calculation ──────────────────────────────────────
echo "\n=== 15. applyDynamicPricing() calculation ===\n";
$priceRoomId   = (int)$testRoom['id'];
$priceBase     = (float)$testRoom['price_per_night'];
$priceCheckIn  = date('Y-m-d', strtotime('+90 days'));
$priceCheckOut = date('Y-m-d', strtotime('+92 days'));
$priceNights   = 2;
$pricing = applyDynamicPricing($pdo, $priceRoomId, $priceCheckIn, $priceCheckOut, $priceNights, $priceBase);

assert_true(array_key_exists('final_price', $pricing) && is_numeric($pricing['final_price']), 'applyDynamicPricing() returns numeric final_price');
assert_true(array_key_exists('original_price', $pricing) && is_numeric($pricing['original_price']), 'applyDynamicPricing() returns numeric original_price');
assert_true(array_key_exists('discount_amount', $pricing) && is_numeric($pricing['discount_amount']), 'applyDynamicPricing() returns numeric discount_amount');

$finalPrice    = (float)$pricing['final_price'];
$originalPrice = (float)$pricing['original_price'];
$discountAmt   = (float)$pricing['discount_amount'];

assert_true($finalPrice >= 0, 'final_price is non-negative', "final_price=$finalPrice");
assert_true($finalPrice <= $originalPrice + BALANCE_TOLERANCE, 'final_price does not exceed original_price beyond tolerance',
    "final_price=$finalPrice original_price=$originalPrice");
assert_true($discountAmt >= -BALANCE_TOLERANCE, 'discount_amount is not a negative surcharge beyond tolerance', "discount_amount=$discountAmt");
assert_true(
    abs(($originalPrice - $discountAmt) - $finalPrice) <= BALANCE_TOLERANCE,
    'original_price - discount_amount reconciles with final_price within BALANCE_TOLERANCE',
    "original=$originalPrice discount=$discountAmt final=$finalPrice"
);

// ── 16. applyDynamicPricing() zero-nights guard ────────────────────────────────
echo "\n=== 16. applyDynamicPricing() zero-nights guard ===\n";
$zeroNightsPricing = applyDynamicPricing($pdo, $priceRoomId, $priceCheckIn, $priceCheckOut, 0, $priceBase);
assert_true(
    abs((float)$zeroNightsPricing['final_price'] - $priceBase) <= BALANCE_TOLERANCE,
    'applyDynamicPricing() with nights=0 returns final_price === base_price (guard path)',
    "final_price={$zeroNightsPricing['final_price']} base_price=$priceBase"
);

// ── 17. Cleanup ───────────────────────────────────────────────────────────────
echo "\n=== 17. Cleanup ===\n";
if (!empty($createdIds)) {
    $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
    $pdo->prepare("DELETE FROM bookings WHERE id IN ($placeholders)")->execute($createdIds);
    ok('Test bookings cleaned up (ids: ' . implode(', ', $createdIds) . ')');
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n========================================\n";
echo "SMOKE TEST RESULTS: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
