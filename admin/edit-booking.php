<?php

/**
 * Admin Edit Booking Page
 * Allows admin to modify booking details: dates, room, guest info, occupancy, amounts
 */

require_once __DIR__ . '/admin-init.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/alert.php';

/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

$booking_id = intval($_GET['id'] ?? 0);
if ($booking_id <= 0) {
    header('Location: bookings.php');
    exit;
}

$message = '';
$error = '';
$latest_booking_note = '';

// Fetch booking with individual room info
try {
    $stmt = $pdo->prepare("
        SELECT b.*,
               r.name as room_name, r.price_per_night, r.total_rooms, r.rooms_available, r.max_guests,
               r.child_price_multiplier as room_type_child_price_multiplier,
               ir.id as individual_room_id, ir.room_number as individual_room_number, ir.room_name as individual_room_name,
               ir.child_price_multiplier as individual_child_price_multiplier,
               rt.name as room_type_name, rt.id as room_type_id
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
        LEFT JOIN rooms rt ON ir.room_type_id = rt.id
        WHERE b.id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        header('Location: bookings.php');
        exit;
    }
} catch (PDOException $e) {
    $error = 'Error loading booking: ' . $e->getMessage();
    $booking = null;
}

if ($booking) {
    try {
        $note_stmt = $pdo->prepare("SELECT note_text FROM booking_notes WHERE booking_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
        $note_stmt->execute([$booking_id]);
        $latest_booking_note = (string)($note_stmt->fetchColumn() ?: '');
    } catch (PDOException $e) {
        $latest_booking_note = '';
    }
}

// Fetch available individual rooms for this booking's dates
$availableIndividualRooms = [];
if ($booking && $booking['room_id']) {
    $checkIn = $booking['check_in_date'];
    $checkOut = $booking['check_out_date'];

    try {
        // Get individual rooms for the booking's room type
        $stmt = $pdo->prepare("
            SELECT
                ir.id,
                ir.room_number,
                ir.room_name,
                ir.floor,
                ir.status,
                ir.child_price_multiplier,
                ir.single_occupancy_enabled_override,
                ir.double_occupancy_enabled_override,
                ir.triple_occupancy_enabled_override,
                ir.children_allowed_override,
                rt.child_price_multiplier AS room_type_child_price_multiplier,
                rt.single_occupancy_enabled,
                rt.double_occupancy_enabled,
                rt.triple_occupancy_enabled,
                rt.children_allowed,
                rt.name as room_type_name
            FROM individual_rooms ir
            JOIN rooms rt ON ir.room_type_id = rt.id
            WHERE ir.is_active = 1
            AND ir.room_type_id = ?
            ORDER BY ir.floor ASC, ir.room_number ASC
        ");
        $stmt->execute([(int)$booking['room_id']]);
        $allIndividualRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check availability for each room
        foreach ($allIndividualRooms as $room) {
            $isAvailable = true;
            $reason = '';

            // Check status
            if (!in_array($room['status'], ['available', 'cleaning'])) {
                $isAvailable = false;
                $reason = ucfirst(str_replace('_', ' ', $room['status']));
            } else {
                // Check for booking conflicts
                $conflictStmt = $pdo->prepare("
                    SELECT COUNT(*) as count, booking_reference
                    FROM bookings
                    WHERE individual_room_id = ?
                    AND status IN ('pending', 'confirmed', 'checked-in')
                    AND NOT (check_out_date <= ? OR check_in_date >= ?)
                    AND id != ?
                    LIMIT 1
                ");
                $conflictStmt->execute([$room['id'], $checkIn, $checkOut, $booking_id]);
                $conflict = $conflictStmt->fetch(PDO::FETCH_ASSOC);

                if ($conflict['count'] > 0) {
                    $isAvailable = false;
                    $reason = 'Booked (' . $conflict['booking_reference'] . ')';
                }
            }

            $room['available'] = $isAvailable;
            $room['unavailable_reason'] = $reason;
            $room['effective_child_price_multiplier'] = isset($room['child_price_multiplier']) && $room['child_price_multiplier'] !== null
                ? (float)$room['child_price_multiplier']
                : (float)($room['room_type_child_price_multiplier'] ?? 50);
            $availableIndividualRooms[] = $room;
        }
    } catch (PDOException $e) {
        // Silently fail if individual rooms don't exist yet
        $availableIndividualRooms = [];
    }
}

// Fetch all active rooms
try {
    $rooms_stmt = $pdo->query("SELECT id, name, price_per_night, total_rooms, rooms_available, max_guests, size_sqm,
                                      COALESCE(price_single_occupancy, price_per_night) AS single_price,
                                      COALESCE(price_double_occupancy, price_per_night) AS double_price,
                                      COALESCE(price_triple_occupancy, price_per_night) AS triple_price,
                                      child_price_multiplier
                               FROM rooms WHERE is_active = 1 ORDER BY display_order, name");
    $rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rooms = [];
}

// Get settings
$currency_symbol = getSetting('currency_symbol');
$vatEnabled = in_array(getSetting('vat_enabled'), ['1', 'true', 'on']);
$vatRate = (float)getSetting('vat_rate', 0);
$levyEnabled = getSetting('tourism_levy_enabled', '0') === '1';
$levyPercent = $levyEnabled ? (float)getSetting('tourism_levy_percent', 0) : 0.0;
$can_edit_booking_financials = hasPermission((int)($user['id'] ?? 0), 'edit_booking_financials');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $booking) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $room_id = intval($_POST['room_id'] ?? $booking['room_id']);
        $individual_room_id = !empty($_POST['individual_room_id']) ? intval($_POST['individual_room_id']) : null;
        $check_in = $_POST['check_in_date'] ?? $booking['check_in_date'];
        $check_out = $_POST['check_out_date'] ?? $booking['check_out_date'];
        $guest_name = trim($_POST['guest_name'] ?? '');
        $guest_email = trim($_POST['guest_email'] ?? '');
        $guest_phone = trim($_POST['guest_phone'] ?? '');
        $guest_country = trim($_POST['guest_country'] ?? '');
        $number_of_guests = intval($_POST['number_of_guests'] ?? 1);
        $child_guests = intval($_POST['child_guests'] ?? 0);
        $adult_guests = max(1, $number_of_guests - $child_guests);
        $occupancy_type = $_POST['occupancy_type'] ?? 'single';
        $special_requests = trim($_POST['special_requests'] ?? '');
        $posted_total_amount = isset($_POST['total_amount']) ? (float)$_POST['total_amount'] : (float)($booking['total_amount'] ?? 0);
        $total_amount = $can_edit_booking_financials ? $posted_total_amount : (float)($booking['total_amount'] ?? 0);
        $admin_notes = trim($_POST['booking_notes'] ?? '');

        // Validate
        if (!$can_edit_booking_financials && isset($_POST['total_amount']) && abs($posted_total_amount - (float)($booking['total_amount'] ?? 0)) > 0.01) {
            $error = 'You do not have permission to change booking amounts.';
        } elseif (empty($guest_name) || empty($guest_email) || empty($check_in) || empty($check_out)) {
            $error = 'Guest name, email, check-in and check-out dates are required.';
        } elseif (strtotime($check_out) <= strtotime($check_in)) {
            $error = 'Check-out date must be after check-in date.';
        } elseif ($child_guests < 0) {
            $error = 'Children count cannot be negative.';
        } elseif ($child_guests >= $number_of_guests) {
            $error = 'At least 1 adult is required for every booking.';
        } else {
            // Enforce maximum guest capacity for selected room
            $cap_check = $pdo->prepare("SELECT max_guests, single_occupancy_enabled, double_occupancy_enabled, triple_occupancy_enabled, children_allowed FROM rooms WHERE id = ?");
            $cap_check->execute([$room_id]);
            $cap_room = $cap_check->fetch(PDO::FETCH_ASSOC);
            if ($cap_room && $number_of_guests > (int)$cap_room['max_guests']) {
                $error = 'Number of guests (' . $number_of_guests . ') exceeds room capacity of ' . $cap_room['max_guests'] . '. Please reduce guests or assign a different room.';
            } elseif ($cap_room) {
                $policy = resolveOccupancyPolicy($cap_room, null);
                if (
                    ($occupancy_type === 'single' && empty($policy['single_enabled'])) ||
                    ($occupancy_type === 'double' && empty($policy['double_enabled'])) ||
                    ($occupancy_type === 'triple' && empty($policy['triple_enabled']))
                ) {
                    $error = 'Selected occupancy type is disabled for this room type.';
                } elseif (empty($policy['children_allowed']) && $child_guests > 0) {
                    $error = 'Children are not allowed for this room type.';
                }
            }

            if (empty($error) && $individual_room_id) {
                $irPolicyStmt = $pdo->prepare("SELECT single_occupancy_enabled_override, double_occupancy_enabled_override, triple_occupancy_enabled_override, children_allowed_override FROM individual_rooms WHERE id = ?");
                $irPolicyStmt->execute([$individual_room_id]);
                $irPolicy = $irPolicyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($irPolicy) {
                    $effectivePolicy = resolveOccupancyPolicy($cap_room ?? [], $irPolicy);
                    if (
                        ($occupancy_type === 'single' && empty($effectivePolicy['single_enabled'])) ||
                        ($occupancy_type === 'double' && empty($effectivePolicy['double_enabled'])) ||
                        ($occupancy_type === 'triple' && empty($effectivePolicy['triple_enabled']))
                    ) {
                        $error = 'Selected occupancy type is disabled for this individual room.';
                    } elseif (empty($effectivePolicy['children_allowed']) && $child_guests > 0) {
                        $error = 'Children are not allowed for this individual room.';
                    }
                }
            }
        }

        if (empty($error)) {
            try {
                $pdo->beginTransaction();

                $number_of_nights = (strtotime($check_out) - strtotime($check_in)) / 86400;
                $multiplierStmt = $pdo->prepare("
                    SELECT
                        r.child_price_multiplier AS room_type_child_price_multiplier,
                        ir.child_price_multiplier AS individual_child_price_multiplier,
                        COALESCE(ir.child_price_multiplier, r.child_price_multiplier) AS effective_child_price_multiplier
                    FROM rooms r
                    LEFT JOIN individual_rooms ir ON ir.id = ?
                    WHERE r.id = ?
                    LIMIT 1
                ");
                $multiplierStmt->execute([$individual_room_id, $room_id]);
                $multiplierRow = $multiplierStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $child_price_multiplier = isset($multiplierRow['effective_child_price_multiplier']) && $multiplierRow['effective_child_price_multiplier'] !== null
                    ? (float)$multiplierRow['effective_child_price_multiplier']
                    : (float)getSetting('booking_child_price_multiplier', getSetting('child_guest_price_multiplier', 50));
                if ($child_price_multiplier < 0) {
                    $child_price_multiplier = 0;
                }

                // Track changes for notification email
                $changes = [];
                $currency_sym = getSetting('currency_symbol');

                $old_room_id = $booking['room_id'];
                $room_changed = ($room_id != $old_room_id);

                if ($room_changed) {
                    $old_room_name = $booking['room_name'] ?? 'Unknown';
                    $new_room_stmt = $pdo->prepare("SELECT name FROM rooms WHERE id = ?");
                    $new_room_stmt->execute([$room_id]);
                    $new_room_row = $new_room_stmt->fetch(PDO::FETCH_ASSOC);
                    $new_room_name = $new_room_row ? $new_room_row['name'] : 'Unknown';
                    $changes['room'] = ['old' => $old_room_name, 'new' => $new_room_name];
                }
                if ($check_in !== $booking['check_in_date']) {
                    $changes['check_in_date'] = ['old' => date('M j, Y', strtotime($booking['check_in_date'])), 'new' => date('M j, Y', strtotime($check_in))];
                }
                if ($check_out !== $booking['check_out_date']) {
                    $changes['check_out_date'] = ['old' => date('M j, Y', strtotime($booking['check_out_date'])), 'new' => date('M j, Y', strtotime($check_out))];
                }
                if ($number_of_guests != $booking['number_of_guests']) {
                    $changes['number_of_guests'] = ['old' => $booking['number_of_guests'], 'new' => $number_of_guests];
                }
                if ((int)($booking['adult_guests'] ?? ($booking['number_of_guests'] ?? 1)) !== $adult_guests) {
                    $changes['adult_guests'] = ['old' => (int)($booking['adult_guests'] ?? ($booking['number_of_guests'] ?? 1)), 'new' => $adult_guests];
                }
                if ((int)($booking['child_guests'] ?? 0) !== $child_guests) {
                    $changes['child_guests'] = ['old' => (int)($booking['child_guests'] ?? 0), 'new' => $child_guests];
                }
                if ($occupancy_type !== ($booking['occupancy_type'] ?? 'single')) {
                    $changes['occupancy_type'] = ['old' => ucfirst($booking['occupancy_type'] ?? 'single'), 'new' => ucfirst($occupancy_type)];
                }
                if (abs($total_amount - (float)$booking['total_amount']) > 0.01) {
                    $changes['total_amount'] = ['old' => $currency_sym . ' ' . number_format($booking['total_amount'], 2), 'new' => $currency_sym . ' ' . number_format($total_amount, 2)];
                }
                if ($guest_name !== $booking['guest_name']) {
                    $changes['guest_name'] = ['old' => $booking['guest_name'], 'new' => $guest_name];
                }
                if ($guest_email !== $booking['guest_email']) {
                    $changes['guest_email'] = ['old' => $booking['guest_email'], 'new' => $guest_email];
                }
                if ($guest_phone !== ($booking['guest_phone'] ?? '')) {
                    $changes['guest_phone'] = ['old' => $booking['guest_phone'] ?? '', 'new' => $guest_phone];
                }

                // Always recheck availability when room OR dates change.
                // Pass exclude_booking_id so the current booking's own slot does not
                // conflict with itself. This prevents saving dates that are already
                // fully booked by other guests.
                $datesChanged = $check_in !== ($booking['check_in_date'] ?? '')
                    || $check_out !== ($booking['check_out_date'] ?? '');

                if ($room_changed || $datesChanged) {
                    // Serialise against concurrent creates/edits on this room type using
                    // the SAME per-room row lock the booking-creation flows take, BEFORE
                    // re-checking availability. Without it the check-then-update window
                    // lets an edit and a new booking both grab the last room → overbooking.
                    $pdo->prepare("SELECT id FROM rooms WHERE id = ? FOR UPDATE")->execute([$room_id]);
                    $availCheck = checkRoomAvailability($room_id, $check_in, $check_out, $booking_id);
                    if (empty($availCheck['available'])) {
                        $pdo->rollBack();
                        $error = 'Selected room is not available for those dates: ' . ($availCheck['error'] ?? 'no rooms remaining.');
                    }
                }

                if (empty($error)) {
                    $currentIndividualRoomId = !empty($booking['individual_room_id']) ? (int)$booking['individual_room_id'] : null;
                    $pricingFieldsChanged = $room_id !== (int)$booking['room_id']
                        || $individual_room_id !== $currentIndividualRoomId
                        || $check_in !== ($booking['check_in_date'] ?? '')
                        || $check_out !== ($booking['check_out_date'] ?? '')
                        || $number_of_guests !== (int)($booking['number_of_guests'] ?? 1)
                        || $adult_guests !== (int)($booking['adult_guests'] ?? ($booking['number_of_guests'] ?? 1))
                        || $child_guests !== (int)($booking['child_guests'] ?? 0)
                        || $occupancy_type !== ($booking['occupancy_type'] ?? 'single')
                        || abs($total_amount - (float)($booking['total_amount'] ?? 0)) > 0.01;

                    // Rate-affecting fields only (excludes total_amount — that may be an explicit admin override)
                    $rateFieldsChanged = $room_id !== (int)$booking['room_id']
                        || $individual_room_id !== $currentIndividualRoomId
                        || $check_in !== ($booking['check_in_date'] ?? '')
                        || $check_out !== ($booking['check_out_date'] ?? '')
                        || $number_of_guests !== (int)($booking['number_of_guests'] ?? 1)
                        || $adult_guests !== (int)($booking['adult_guests'] ?? ($booking['number_of_guests'] ?? 1))
                        || $child_guests !== (int)($booking['child_guests'] ?? 0)
                        || $occupancy_type !== ($booking['occupancy_type'] ?? 'single');

                    $vat_amount = (float)($booking['vat_amount'] ?? 0);
                    $child_supplement_total = (float)($booking['child_supplement_total'] ?? 0);
                    // FIX: Carry existing levy values as defaults; they are recalculated below
                    // when rateFieldsChanged is true.
                    $tourism_levy_amount  = (float)($booking['tourism_levy_amount'] ?? 0);
                    $tourism_levy_percent = (float)($booking['tourism_levy_percent'] ?? 0);

                    if ($pricingFieldsChanged) {
                        // Mode-aware: exclusive adds on top, inclusive extracts, off zeroes.
                        $vat_amount = vat_components($total_amount)['vat'];
                    }

                    $update = $pdo->prepare("
                        UPDATE bookings SET
                            room_id = ?,
                            individual_room_id = ?,
                            guest_name = ?,
                            guest_email = ?,
                            guest_phone = ?,
                            guest_country = ?,
                            check_in_date = ?,
                            check_out_date = ?,
                            number_of_nights = ?,
                            number_of_guests = ?,
                            adult_guests = ?,
                            child_guests = ?,
                            child_price_multiplier = ?,
                            occupancy_type = ?,
                            total_amount = ?,
                            child_supplement_total = ?,
                            tourism_levy_amount = ?,
                            tourism_levy_percent = ?,
                            vat_amount = ?,
                            total_with_vat = ?,
                            special_requests = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");

                    if ($pricingFieldsChanged) {
                        $roomRateStmt = $pdo->prepare("
                            SELECT
                                r.price_per_night,
                                COALESCE(r.price_single_occupancy, r.price_per_night) AS single_price,
                                COALESCE(r.price_double_occupancy, r.price_per_night) AS double_price,
                                COALESCE(r.price_triple_occupancy, r.price_per_night) AS triple_price,
                                COALESCE(ir.child_price_multiplier, r.child_price_multiplier) AS effective_child_price_multiplier
                            FROM rooms r
                            LEFT JOIN individual_rooms ir ON ir.id = ?
                            WHERE r.id = ?
                            LIMIT 1
                        ");
                        $roomRateStmt->execute([$individual_room_id, $room_id]);
                        $rateRow = $roomRateStmt->fetch(PDO::FETCH_ASSOC);
                        $ratePerNight = (float)($rateRow['price_per_night'] ?? 0);
                        if ($occupancy_type === 'single') {
                            $ratePerNight = (float)($rateRow['single_price'] ?? $ratePerNight);
                        } elseif ($occupancy_type === 'double') {
                            $ratePerNight = (float)($rateRow['double_price'] ?? $ratePerNight);
                        } elseif ($occupancy_type === 'triple') {
                            $ratePerNight = (float)($rateRow['triple_price'] ?? $ratePerNight);
                        }

                        if (isset($rateRow['effective_child_price_multiplier']) && $rateRow['effective_child_price_multiplier'] !== null) {
                            $child_price_multiplier = max(0, (float)$rateRow['effective_child_price_multiplier']);
                        }
                        $child_supplement_total = $child_guests > 0
                            ? ($ratePerNight * ($child_price_multiplier / 100) * $child_guests * $number_of_nights)
                            : 0;

                        // When rate-affecting fields changed, recalculate total server-side via dynamic pricing
                        if ($rateFieldsChanged) {
                            $dynResult = applyDynamicPricing($pdo, $room_id, $check_in, $check_out, (int)$number_of_nights, $ratePerNight);
                            $ratePerNight = $dynResult['final_price'];
                            $child_supplement_total = $child_guests > 0
                                ? ($ratePerNight * ($child_price_multiplier / 100) * $child_guests * (int)$number_of_nights)
                                : 0;
                            $total_amount = round(($ratePerNight * (int)$number_of_nights) + $child_supplement_total, 2);

                            // FIX: Recalculate tourism levy when rate-affecting fields change.
                            // Previously the levy was never updated on edit, causing the stored
                            // tourism_levy_amount to be stale after any date/room/occupancy change.
                            if ($levyEnabled && $levyPercent > 0) {
                                $tourism_levy_amount  = round(($total_amount) * ($levyPercent / 100), 2);
                                $tourism_levy_percent = $levyPercent;
                                $total_amount         = round($total_amount + $tourism_levy_amount, 2);
                            } else {
                                $tourism_levy_amount  = 0.0;
                                $tourism_levy_percent = 0.0;
                            }

                            // Mode-aware: exclusive adds on top, inclusive extracts, off zeroes.
                            $vat_amount = vat_components($total_amount)['vat'];
                        }
                    } else {
                        $number_of_nights = (int)($booking['number_of_nights'] ?? $number_of_nights);
                        $child_price_multiplier = (float)($booking['child_price_multiplier'] ?? $child_price_multiplier);
                    }
                    $adminNoteChanged = $admin_notes !== '' && $admin_notes !== trim($latest_booking_note);

                    $auditOldSource = [
                        'room_id' => isset($booking['room_id']) ? (int)$booking['room_id'] : null,
                        'individual_room_id' => isset($booking['individual_room_id']) ? (int)$booking['individual_room_id'] : null,
                        'guest_name' => $booking['guest_name'] ?? '',
                        'guest_email' => $booking['guest_email'] ?? '',
                        'guest_phone' => $booking['guest_phone'] ?? '',
                        'guest_country' => $booking['guest_country'] ?? '',
                        'check_in_date' => $booking['check_in_date'] ?? '',
                        'check_out_date' => $booking['check_out_date'] ?? '',
                        'number_of_nights' => isset($booking['number_of_nights']) ? (int)$booking['number_of_nights'] : null,
                        'number_of_guests' => isset($booking['number_of_guests']) ? (int)$booking['number_of_guests'] : null,
                        'adult_guests' => isset($booking['adult_guests']) ? (int)$booking['adult_guests'] : null,
                        'child_guests' => isset($booking['child_guests']) ? (int)$booking['child_guests'] : null,
                        'child_price_multiplier' => isset($booking['child_price_multiplier']) ? (float)$booking['child_price_multiplier'] : null,
                        'occupancy_type' => $booking['occupancy_type'] ?? '',
                        'total_amount' => isset($booking['total_amount']) ? (float)$booking['total_amount'] : null,
                        'child_supplement_total' => isset($booking['child_supplement_total']) ? (float)$booking['child_supplement_total'] : null,
                        'tourism_levy_amount' => isset($booking['tourism_levy_amount']) ? (float)$booking['tourism_levy_amount'] : null,
                        'vat_amount' => isset($booking['vat_amount']) ? (float)$booking['vat_amount'] : null,
                        'special_requests' => $booking['special_requests'] ?? '',
                        'admin_note' => $latest_booking_note,
                    ];
                    $auditNewSource = [
                        'room_id' => $room_id,
                        'individual_room_id' => $individual_room_id,
                        'guest_name' => $guest_name,
                        'guest_email' => $guest_email,
                        'guest_phone' => $guest_phone,
                        'guest_country' => $guest_country,
                        'check_in_date' => $check_in,
                        'check_out_date' => $check_out,
                        'number_of_nights' => (int)$number_of_nights,
                        'number_of_guests' => $number_of_guests,
                        'adult_guests' => $adult_guests,
                        'child_guests' => $child_guests,
                        'child_price_multiplier' => $child_price_multiplier,
                        'occupancy_type' => $occupancy_type,
                        'total_amount' => $total_amount,
                        'child_supplement_total' => $child_supplement_total,
                        'tourism_levy_amount' => $tourism_levy_amount,
                        'vat_amount' => $vat_amount,
                        'special_requests' => $special_requests,
                        'admin_note' => $adminNoteChanged ? $admin_notes : $latest_booking_note,
                    ];
                    $auditNumericFields = array_fill_keys([
                        'room_id',
                        'individual_room_id',
                        'number_of_nights',
                        'number_of_guests',
                        'adult_guests',
                        'child_guests',
                        'child_price_multiplier',
                        'total_amount',
                        'child_supplement_total',
                        'tourism_levy_amount',
                        'vat_amount'
                    ], true);
                    $auditOldValues = [];
                    $auditNewValues = [];
                    foreach ($auditNewSource as $field => $newValue) {
                        $oldValue = $auditOldSource[$field] ?? null;
                        $hasChanged = isset($auditNumericFields[$field])
                            ? abs((float)($oldValue ?? 0) - (float)($newValue ?? 0)) > 0.0001
                            : (string)($oldValue ?? '') !== (string)($newValue ?? '');

                        if ($hasChanged) {
                            $auditOldValues[$field] = $oldValue;
                            $auditNewValues[$field] = $newValue;
                        }
                    }

                    // Inclusive mode: the priced total already contains VAT — never add on top.
                    $total_with_vat = vat_mode() === 'inclusive'
                        ? round($total_amount, 2)
                        : round($total_amount + $vat_amount, 2);

                    $update->execute([
                        $room_id,
                        $individual_room_id,
                        $guest_name,
                        $guest_email,
                        $guest_phone,
                        $guest_country,
                        $check_in,
                        $check_out,
                        $number_of_nights,
                        $number_of_guests,
                        $adult_guests,
                        $child_guests,
                        $child_price_multiplier,
                        $occupancy_type,
                        $total_amount,
                        $child_supplement_total,
                        $tourism_levy_amount,
                        $tourism_levy_percent,
                        $vat_amount,
                        $total_with_vat,
                        $special_requests,
                        $booking_id
                    ]);

                    if ($adminNoteChanged) {
                        $noteInsert = $pdo->prepare("INSERT INTO booking_notes (booking_id, note_text, created_by) VALUES (?, ?, ?)");
                        $noteInsert->execute([$booking_id, $admin_notes, $user['id'] ?? null]);
                    }

                    $pdo->commit();

                    if ($adminNoteChanged) {
                        $latest_booking_note = $admin_notes;
                    }

                    if (!empty($auditNewValues)) {
                        $auditActor = $user['full_name'] ?? ($user['username'] ?? 'Admin');
                        logBookingAudit(
                            $booking_id,
                            'modified',
                            $auditOldValues,
                            $auditNewValues,
                            'Edited on full booking page by ' . $auditActor,
                            $booking['booking_reference'] ?? null
                        );
                        rh_log_event('edit-booking', 'info', 'Booking edited by admin', [
                            'booking_id' => $booking_id,
                            'ref' => $booking['booking_reference'] ?? null,
                            'fields' => array_keys($auditNewValues),
                            'by' => $user['username'] ?? null,
                        ]);
                    }

                    $message = 'Booking updated successfully.';

                    $bookingRefreshStmt = $pdo->prepare("
                        SELECT b.*,
                               r.name as room_name, r.price_per_night, r.total_rooms, r.rooms_available, r.max_guests,
                               r.child_price_multiplier as room_type_child_price_multiplier,
                               ir.id as individual_room_id, ir.room_number as individual_room_number, ir.room_name as individual_room_name,
                               ir.child_price_multiplier as individual_child_price_multiplier,
                               rt.name as room_type_name, rt.id as room_type_id
                        FROM bookings b
                        LEFT JOIN rooms r ON b.room_id = r.id
                        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                        LEFT JOIN rooms rt ON ir.room_type_id = rt.id
                        WHERE b.id = ?
                    ");

                    // Send modification email to guest if there were meaningful changes
                    if (!empty($changes)) {
                        $bookingRefreshStmt->execute([$booking_id]);
                        $updated_booking = $bookingRefreshStmt->fetch(PDO::FETCH_ASSOC);

                        if ($updated_booking) {
                            require_once __DIR__ . '/../config/email.php';
                            $email_result = sendBookingModifiedEmail($updated_booking, $changes);
                            if ($email_result['success']) {
                                $message .= ' Notification email sent to guest.';
                            } else {
                                $message .= ' Guest notification email could not be sent.';
                                error_log("Failed to send booking modification email: {$email_result['message']}");
                            }
                        }
                    }

                    // Refresh booking data
                    $bookingRefreshStmt->execute([$booking_id]);
                    $booking = $bookingRefreshStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$adminNoteChanged) {
                        try {
                            $note_stmt = $pdo->prepare("SELECT note_text FROM booking_notes WHERE booking_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
                            $note_stmt->execute([$booking_id]);
                            $latest_booking_note = (string)($note_stmt->fetchColumn() ?: '');
                        } catch (PDOException $e) {
                            $latest_booking_note = '';
                        }
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Error updating booking: ' . $e->getMessage();
            }
        }
    }
}


if (!$booking) {
    echo '<p>Booking not found.</p>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Booking <?php echo htmlspecialchars($booking['booking_reference']); ?> - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-pen-to-square"></i>
                    Edit Booking <?php echo htmlspecialchars($booking['booking_reference']); ?>
                </h1>
                <p class="page-subtitle">
                    Modify booking details, room assignment, dates, and guest information.
                </p>
                <div class="page-meta">
                    <span class="badge badge-<?php echo htmlspecialchars($booking['status']); ?>">
                        <?php echo ucfirst(htmlspecialchars($booking['status'])); ?>
                    </span>
                </div>
            </div>
            <div class="page-header-actions">
                <a href="booking-details.php?id=<?php echo $booking_id; ?>" class="btn btn-secondary btn-sm" onclick="if(history.length>1){history.back();return false;}">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back</span>
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <div class="edit-form">
            <form method="POST"
                data-admin-confirm="Save these booking changes?"
                data-admin-confirm-title="Confirm booking update"
                data-admin-confirm-details="Please verify room, dates, guests, charges, payment values, and room assignment before saving.|This change can affect availability, pricing, and the booking audit trail."
                data-admin-confirm-ok="Save Changes"
                data-admin-confirm-icon="fa-pen-to-square"
                data-admin-loader-text="Saving booking..."
                data-admin-submit-text="Saving...">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                <h3 class="form-section-title">
                    <i class="fas fa-user"></i> Guest Information
                </h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="guest_name">Full Name *</label>
                        <input type="text" id="guest_name" name="guest_name" required
                            value="<?php echo htmlspecialchars($booking['guest_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="guest_email">Email *</label>
                        <input type="email" id="guest_email" name="guest_email" required
                            value="<?php echo htmlspecialchars($booking['guest_email']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="guest_phone">Phone</label>
                        <input type="text" id="guest_phone" name="guest_phone"
                            value="<?php echo htmlspecialchars($booking['guest_phone']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="guest_country">Country</label>
                        <input type="text" id="guest_country" name="guest_country"
                            value="<?php echo htmlspecialchars($booking['guest_country'] ?? ''); ?>">
                    </div>
                </div>

                <h3 class="form-section-title">
                    <i class="fas fa-bed"></i> Room & Dates
                </h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="room_id">Room</label>
                        <select id="room_id" name="room_id" onchange="updatePricing()">
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?php echo $room['id']; ?>"
                                    data-price="<?php echo $room['price_per_night']; ?>"
                                    data-single="<?php echo $room['single_price'] ?? $room['price_per_night']; ?>"
                                    data-double="<?php echo $room['double_price'] ?? $room['price_per_night']; ?>"
                                    data-triple="<?php echo $room['triple_price'] ?? $room['price_per_night']; ?>"
                                    data-child-multiplier="<?php echo htmlspecialchars((string)($room['child_price_multiplier'] ?? 50)); ?>"
                                    data-single-enabled="<?php echo (int)($room['single_occupancy_enabled'] ?? ((int)($room['max_guests'] ?? 0) >= 1 ? 1 : 0)); ?>"
                                    data-double-enabled="<?php echo (int)($room['double_occupancy_enabled'] ?? ((int)($room['max_guests'] ?? 0) >= 2 ? 1 : 0)); ?>"
                                    data-triple-enabled="<?php echo (int)($room['triple_occupancy_enabled'] ?? ((int)($room['max_guests'] ?? 0) >= 3 ? 1 : 0)); ?>"
                                    data-children-allowed="<?php echo (int)($room['children_allowed'] ?? 1); ?>"
                                    data-max-guests="<?php echo $room['max_guests']; ?>"
                                    data-available="<?php echo $room['rooms_available']; ?>"
                                    <?php echo $room['id'] == $booking['room_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($room['name']); ?>
                                    (<?php echo $currency_symbol . ' ' . number_format($room['price_per_night']); ?>/night)
                                    [<?php echo $room['rooms_available']; ?> avail]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="occupancy_type">Occupancy Type</label>
                        <select id="occupancy_type" name="occupancy_type" onchange="updatePricing()">
                            <option value="single" <?php echo $booking['occupancy_type'] === 'single' ? 'selected' : ''; ?>>Single</option>
                            <option value="double" <?php echo $booking['occupancy_type'] === 'double' ? 'selected' : ''; ?>>Double</option>
                            <option value="triple" <?php echo $booking['occupancy_type'] === 'triple' ? 'selected' : ''; ?>>Triple</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="check_in_date">Check-in Date *</label>
                        <input type="date" id="check_in_date" name="check_in_date" required
                            value="<?php echo htmlspecialchars($booking['check_in_date']); ?>"
                            onchange="updatePricing()">
                    </div>
                    <div class="form-group">
                        <label for="check_out_date">Check-out Date *</label>
                        <input type="date" id="check_out_date" name="check_out_date" required
                            value="<?php echo htmlspecialchars($booking['check_out_date']); ?>"
                            onchange="updatePricing()">
                    </div>
                    <div class="form-group">
                        <label for="number_of_guests">Number of Guests</label>
                        <input type="number" id="number_of_guests" name="number_of_guests" min="1"
                            max="<?php echo $booking['max_guests'] ?? 10; ?>"
                            value="<?php echo $booking['number_of_guests']; ?>">
                        <small style="color: #888;">Max: <span id="maxGuestsHint"><?php echo $booking['max_guests'] ?? '?'; ?></span> for this room</small>
                    </div>
                    <div class="form-group">
                        <label for="child_guests">Children (under 12)</label>
                        <input type="number" id="child_guests" name="child_guests" min="0"
                            max="<?php echo max(0, ((int)$booking['number_of_guests']) - 1); ?>"
                            value="<?php echo (int)($booking['child_guests'] ?? 0); ?>">
                        <small style="color:#888;">At least 1 adult is required per booking.</small>
                    </div>
                    <div class="form-group">
                        <label for="total_amount">Total Amount (<?php echo $currency_symbol; ?>)</label>
                        <input type="number" id="total_amount" name="total_amount" step="0.01" min="0"
                            value="<?php echo $booking['total_amount']; ?>" <?php echo $can_edit_booking_financials ? '' : 'readonly disabled aria-disabled="true"'; ?>>
                        <?php if (!$can_edit_booking_financials): ?>
                            <small style="color: #888;">Only admin users or users with the Edit Booking Financials permission can change this amount.</small>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($availableIndividualRooms)): ?>
                    <div class="individual-room-section">
                        <h4><i class="fas fa-door-open"></i> Assign Specific Room (Optional)</h4>
                        <p style="font-size: 13px; color: #666; margin-bottom: 12px;">
                            Select a specific individual room for this booking. Only available rooms are shown.
                        </p>
                        <input type="hidden" name="individual_room_id" id="individual_room_id" value="<?php echo $booking['individual_room_id'] ?? ''; ?>">
                        <div id="roomOptionsContainer">
                            <?php foreach ($availableIndividualRooms as $room): ?>
                                <div class="room-option <?php echo $room['available'] ? '' : 'disabled'; ?> <?php echo ($booking['individual_room_id'] == $room['id']) ? 'selected' : ''; ?>"
                                    data-room-id="<?php echo $room['id']; ?>"
                                    data-child-multiplier="<?php echo htmlspecialchars((string)($room['effective_child_price_multiplier'] ?? 50)); ?>"
                                    data-single-override="<?php echo $room['single_occupancy_enabled_override'] === null ? '' : (int)$room['single_occupancy_enabled_override']; ?>"
                                    data-double-override="<?php echo $room['double_occupancy_enabled_override'] === null ? '' : (int)$room['double_occupancy_enabled_override']; ?>"
                                    data-triple-override="<?php echo $room['triple_occupancy_enabled_override'] === null ? '' : (int)$room['triple_occupancy_enabled_override']; ?>"
                                    data-children-override="<?php echo $room['children_allowed_override'] === null ? '' : (int)$room['children_allowed_override']; ?>"
                                    onclick="<?php echo $room['available'] ? 'selectRoom(' . $room['id'] . ')' : ''; ?>">
                                    <div class="room-option-header">
                                        <div>
                                            <div class="room-option-title">
                                                <?php echo htmlspecialchars($room['room_number']); ?>
                                                <?php if ($room['room_name']): ?>
                                                    - <?php echo htmlspecialchars($room['room_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="room-option-details">
                                                Floor <?php echo htmlspecialchars($room['floor'] ?? 'N/A'); ?>
                                                <?php if (!$room['available']): ?>
                                                    • <?php echo htmlspecialchars($room['unavailable_reason']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="room-option-status <?php echo $room['available'] ? 'status-available' : 'status-unavailable'; ?>">
                                            <?php echo $room['available'] ? 'Available' : 'Unavailable'; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($booking['individual_room_id']): ?>
                            <p style="font-size: 12px; color: #666; margin-top: 8px;">
                                <i class="fas fa-info-circle"></i> Currently assigned:
                                <strong><?php echo htmlspecialchars($booking['individual_room_number'] ?? ''); ?></strong>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="price-info" id="priceCalculation">
                    <h4><i class="fas fa-calculator"></i> Price Calculation</h4>
                    <div id="priceBreakdown">
                        <span id="calcNights"><?php echo $booking['number_of_nights']; ?></span> night(s) ×
                        <span id="calcRate"><?php echo $currency_symbol . ' ' . number_format($booking['price_per_night'] ?? 0); ?></span>/night =
                        <strong><span id="calcTotal"><?php echo $currency_symbol . ' ' . number_format($booking['total_amount']); ?></span></strong>
                    </div>
                    <small id="calcGuestSplit" style="color:#888; display:block; margin-top:6px;">
                        <?php
                        $adultDefault = (int)($booking['adult_guests'] ?? max(1, ((int)$booking['number_of_guests']) - (int)($booking['child_guests'] ?? 0)));
                        $childDefault = (int)($booking['child_guests'] ?? 0);
                        echo $adultDefault . ' adult' . ($adultDefault === 1 ? '' : 's') . ($childDefault > 0 ? ' + ' . $childDefault . ' child' . ($childDefault === 1 ? '' : 'ren') : '');
                        ?>
                    </small>
                    <small id="calcChildInfo" style="color:#888; display:block;"></small>
                    <small id="calcVatInfo" style="color: #888;">
                        <?php if ($vatEnabled): ?>
                            VAT (<?php echo $vatRate; ?>%): <?php echo $currency_symbol . ' ' . number_format($booking['vat_amount'] ?? 0, 2); ?>
                        <?php endif; ?>
                    </small>
                </div>

                <h3 class="form-section-title">
                    <i class="fas fa-sticky-note"></i> Additional Details
                </h3>
                <div class="form-group form-full">
                    <label for="special_requests">Special Requests</label>
                    <textarea id="special_requests" name="special_requests"><?php echo htmlspecialchars($booking['special_requests'] ?? ''); ?></textarea>
                </div>
                <div class="form-group form-full">
                    <label for="booking_notes">Admin Notes</label>
                    <textarea id="booking_notes" name="booking_notes"><?php echo htmlspecialchars($latest_booking_note); ?></textarea>
                </div>

                <div class="btn-bar">
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="booking-details.php?id=<?php echo $booking_id; ?>" class="btn-back">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        const currencySymbol = '<?php echo $currency_symbol; ?>';
        const vatEnabled = <?php echo $vatEnabled ? 'true' : 'false'; ?>;
        const vatRate = <?php echo $vatRate; ?>;
        const vatMode = <?php echo json_encode(vat_mode()); ?>; // 'off' | 'inclusive' | 'exclusive'
        const childPriceMultiplier = <?php echo json_encode((float)($booking['child_price_multiplier'] ?? getSetting('booking_child_price_multiplier', getSetting('child_guest_price_multiplier', 50)))); ?>;

        function updatePricing() {
            const roomSelect = document.getElementById('room_id');
            const occupancy = document.getElementById('occupancy_type').value;
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const totalGuests = parseInt(document.getElementById('number_of_guests').value || '1', 10);
            const childGuests = parseInt(document.getElementById('child_guests').value || '0', 10);

            if (!checkIn || !checkOut) return;

            const nights = Math.ceil((new Date(checkOut) - new Date(checkIn)) / 86400000);
            if (nights <= 0) return;

            const selected = roomSelect.options[roomSelect.selectedIndex];
            applyOccupancyAndChildrenPolicy();
            let rate = parseFloat(selected.dataset.price);

            if (occupancy === 'single' && selected.dataset.single) rate = parseFloat(selected.dataset.single);
            else if (occupancy === 'double' && selected.dataset.double) rate = parseFloat(selected.dataset.double);
            else if (occupancy === 'triple' && selected.dataset.triple) rate = parseFloat(selected.dataset.triple);

            const safeChildren = Math.min(Math.max(childGuests, 0), Math.max(0, totalGuests - 1));
            const adults = Math.max(1, totalGuests - safeChildren);
            let activeChildMultiplier = Math.max(0, Number(selected.dataset.childMultiplier || childPriceMultiplier) || 0);
            const selectedIndividualRoomId = document.getElementById('individual_room_id').value;
            if (selectedIndividualRoomId) {
                const selectedRoomCard = document.querySelector('.room-option[data-room-id="' + selectedIndividualRoomId + '"]');
                if (selectedRoomCard) {
                    const roomSpecificMultiplier = Number(selectedRoomCard.dataset.childMultiplier || activeChildMultiplier);
                    if (!Number.isNaN(roomSpecificMultiplier)) {
                        activeChildMultiplier = Math.max(0, roomSpecificMultiplier);
                    }
                }
            }

            const childSupplement = safeChildren > 0 ? (nights * rate * (activeChildMultiplier / 100) * safeChildren) : 0;
            const total = (nights * rate) + childSupplement;

            document.getElementById('calcNights').textContent = nights;
            document.getElementById('calcRate').textContent = currencySymbol + ' ' + rate.toLocaleString();
            document.getElementById('calcTotal').textContent = currencySymbol + ' ' + total.toLocaleString();
            document.getElementById('total_amount').value = total.toFixed(2);
            document.getElementById('calcGuestSplit').textContent = `${adults} adult${adults === 1 ? '' : 's'}${safeChildren > 0 ? ` + ${safeChildren} child${safeChildren === 1 ? '' : 'ren'}` : ''}`;
            document.getElementById('calcChildInfo').textContent = safeChildren > 0 ?
                `Child supplement (${activeChildMultiplier}%): ${currencySymbol} ${childSupplement.toLocaleString()}` :
                'Child supplement: None';

            if (vatMode !== 'off' && vatRate > 0) {
                const vat = vatMode === 'inclusive'
                    ? total * (vatRate / (100 + vatRate))   // extracted from the priced total
                    : total * (vatRate / 100);              // added on top of the priced total
                const suffix = vatMode === 'inclusive' ? ' (included in price)' : ' (added on top)';
                document.getElementById('calcVatInfo').textContent = 'VAT (' + vatRate + '%): ' + currencySymbol + ' ' + Number(vat).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }) + suffix;
            }
        }

        // Update max guests when room changes
        document.getElementById('room_id').addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const maxGuests = parseInt(selected.dataset.maxGuests) || 10;
            const guestsInput = document.getElementById('number_of_guests');
            guestsInput.max = maxGuests;
            document.getElementById('maxGuestsHint').textContent = maxGuests;
            if (parseInt(guestsInput.value) > maxGuests) {
                guestsInput.value = maxGuests;
            }

            const childInput = document.getElementById('child_guests');
            const maxChildren = Math.max(0, (parseInt(guestsInput.value || '1', 10) - 1));
            childInput.max = maxChildren;
            if (parseInt(childInput.value || '0', 10) > maxChildren) {
                childInput.value = maxChildren;
            }

            applyOccupancyAndChildrenPolicy();
        });

        document.getElementById('number_of_guests').addEventListener('input', function() {
            const total = Math.max(1, parseInt(this.value || '1', 10));
            const childInput = document.getElementById('child_guests');
            const maxChildren = Math.max(0, total - 1);
            childInput.max = maxChildren;
            if (parseInt(childInput.value || '0', 10) > maxChildren) {
                childInput.value = maxChildren;
            }
            updatePricing();
        });

        document.getElementById('child_guests').addEventListener('input', updatePricing);

        // Individual room selection
        function selectRoom(roomId) {
            document.getElementById('individual_room_id').value = roomId;

            // Update visual selection
            document.querySelectorAll('.room-option').forEach(option => {
                option.classList.remove('selected');
            });
            document.querySelector('.room-option[data-room-id="' + roomId + '"]')?.classList.add('selected');
            applyOccupancyAndChildrenPolicy();
            updatePricing();
        }

        function applyOccupancyAndChildrenPolicy() {
            const roomSelect = document.getElementById('room_id');
            const occupancySelect = document.getElementById('occupancy_type');
            const childInput = document.getElementById('child_guests');
            const selected = roomSelect.options[roomSelect.selectedIndex];
            if (!selected) return;

            let singleEnabled = Number(selected.dataset.singleEnabled || 0) === 1;
            let doubleEnabled = Number(selected.dataset.doubleEnabled || 0) === 1;
            let tripleEnabled = Number(selected.dataset.tripleEnabled || 0) === 1;
            let childrenAllowed = Number(selected.dataset.childrenAllowed || 0) === 1;

            const individualRoomId = document.getElementById('individual_room_id').value;
            if (individualRoomId) {
                const card = document.querySelector('.room-option[data-room-id="' + individualRoomId + '"]');
                if (card) {
                    const so = card.dataset.singleOverride;
                    const dox = card.dataset.doubleOverride;
                    const to = card.dataset.tripleOverride;
                    const co = card.dataset.childrenOverride;
                    if (so !== '') singleEnabled = Number(so) === 1;
                    if (dox !== '') doubleEnabled = Number(dox) === 1;
                    if (to !== '') tripleEnabled = Number(to) === 1;
                    if (co !== '') childrenAllowed = Number(co) === 1;
                }
            }

            Array.from(occupancySelect.options).forEach(opt => {
                if (opt.value === 'single') opt.disabled = !singleEnabled;
                if (opt.value === 'double') opt.disabled = !doubleEnabled;
                if (opt.value === 'triple') opt.disabled = !tripleEnabled;
            });
            if (occupancySelect.selectedOptions[0]?.disabled) {
                const firstEnabled = Array.from(occupancySelect.options).find(opt => !opt.disabled);
                if (firstEnabled) occupancySelect.value = firstEnabled.value;
            }

            childInput.disabled = !childrenAllowed;
            if (!childrenAllowed) {
                childInput.value = '0';
            }
        }
    </script>

    <script src="js/admin-components.js"></script>
    <?php require_once 'includes/admin-footer.php'; ?>

