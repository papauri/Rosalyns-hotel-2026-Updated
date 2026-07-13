<?php

/**
 * Room Booking Page with Enhanced Security
 * Features:
 * - CSRF protection
 * - Secure session management
 * - Input validation
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/booking-functions.php';
require_once 'includes/page-guard.php';
require_once 'config/email.php';
require_once 'includes/validation.php';
require_once 'includes/booking-timeline.php';
require_once 'includes/idempotency.php';
require_once 'includes/pricing.php';
require_once 'includes/public-csrf.php';

function bookingResolveOccupancyPolicy(array $room): array
{
    $policy = resolveOccupancyPolicy($room, null);

    // Only disable occupancy if price is explicitly set to 0 (not NULL)
    // NULL pricing means use base price as fallback
    if (array_key_exists('price_double_occupancy', $room)) {
        if ($room['price_double_occupancy'] === '0' || $room['price_double_occupancy'] === 0) {
            $policy['double_enabled'] = 0;
        }
        // NULL or positive value means enabled
    }
    if (array_key_exists('price_triple_occupancy', $room)) {
        if ($room['price_triple_occupancy'] === '0' || $room['price_triple_occupancy'] === 0) {
            $policy['triple_enabled'] = 0;
        }
        // NULL or positive value means enabled
    }

    return $policy;
}

function bookingPickOccupancyByGuestCount(int $guestCount, array $policy): ?string
{
    // For exact guest count matches, return the corresponding occupancy type
    if ($guestCount === 1 && !empty($policy['single_enabled'])) return 'single';
    if ($guestCount === 2 && !empty($policy['double_enabled'])) return 'double';
    if ($guestCount === 3 && !empty($policy['triple_enabled'])) return 'triple';

    // For guest counts > 3, return the highest enabled occupancy type
    // This allows rooms like Front Villa (max_guests=5) to be booked with 4+ guests
    // The split booking logic will handle distributing guests across multiple bookings
    if ($guestCount > 3) {
        if (!empty($policy['triple_enabled'])) return 'triple';
        if (!empty($policy['double_enabled'])) return 'double';
        if (!empty($policy['single_enabled'])) return 'single';
    }

    return null;
}

function bookingPriceForOccupancy(array $room, string $occupancyType): float
{
    if ($occupancyType === 'single') {
        return !empty($room['price_single_occupancy']) ? (float)$room['price_single_occupancy'] : (float)$room['price_per_night'];
    }

    if ($occupancyType === 'double') {
        return ($room['price_double_occupancy'] !== null && (float)$room['price_double_occupancy'] > 0)
            ? (float)$room['price_double_occupancy']
            : (float)$room['price_per_night'];
    }

    if ($occupancyType === 'triple') {
        return ($room['price_triple_occupancy'] !== null && (float)$room['price_triple_occupancy'] > 0)
            ? (float)$room['price_triple_occupancy']
            : (float)$room['price_per_night'];
    }

    return (float)$room['price_per_night'];
}

function bookingBuildGuestAllocation(int $totalGuests, int $childGuests, array $room, array $policy): array
{
    $maxGuestsPerRoom = max(1, (int)($room['max_guests'] ?? 1));
    $roomsNeeded = max(1, (int)ceil($totalGuests / $maxGuestsPerRoom));
    $adultGuests = $totalGuests - $childGuests;

    if ($adultGuests < $roomsNeeded) {
        return [
            'valid' => false,
            'rooms_needed' => $roomsNeeded,
            'allocation' => [],
            'message' => "At least one adult is required in each room. This group needs {$roomsNeeded} rooms, so please add more adult guests or reduce the number of children."
        ];
    }

    $allocation = [];
    $remainingGuests = $totalGuests;
    $remainingAdults = $adultGuests;
    $remainingChildren = $childGuests;

    for ($index = 0; $index < $roomsNeeded; $index++) {
        $roomsLeft = $roomsNeeded - $index;
        $minGuestsForLaterRooms = max(0, $roomsLeft - 1);
        $guestsThisRoom = min($maxGuestsPerRoom, max(1, $remainingGuests - $minGuestsForLaterRooms));
        $adultReserveForLaterRooms = max(0, $roomsLeft - 1);
        $adultsAvailableThisRoom = $remainingAdults - $adultReserveForLaterRooms;

        if ($adultsAvailableThisRoom < 1) {
            return [
                'valid' => false,
                'rooms_needed' => $roomsNeeded,
                'allocation' => [],
                'message' => 'At least one adult is required in each room.'
            ];
        }

        $childrenThisRoom = min($remainingChildren, max(0, $guestsThisRoom - 1));
        $adultsThisRoom = $guestsThisRoom - $childrenThisRoom;

        if ($adultsThisRoom > $adultsAvailableThisRoom) {
            $adultsThisRoom = $adultsAvailableThisRoom;
            $childrenThisRoom = $guestsThisRoom - $adultsThisRoom;
        }

        if ($childrenThisRoom > $remainingChildren) {
            $childrenThisRoom = $remainingChildren;
            $adultsThisRoom = $guestsThisRoom - $childrenThisRoom;
        }

        if ($adultsThisRoom < 1 || $childrenThisRoom < 0) {
            return [
                'valid' => false,
                'rooms_needed' => $roomsNeeded,
                'allocation' => [],
                'message' => 'Unable to allocate guests while keeping at least one adult in each room.'
            ];
        }

        $occupancyType = bookingPickOccupancyByGuestCount($guestsThisRoom, $policy);
        if ($occupancyType === null) {
            return [
                'valid' => false,
                'rooms_needed' => $roomsNeeded,
                'allocation' => [],
                'message' => "No enabled occupancy pricing can fit {$guestsThisRoom} guests in one {$room['name']} room."
            ];
        }

        $allocation[] = [
            'room_number' => $index + 1,
            'guests' => $guestsThisRoom,
            'adults' => $adultsThisRoom,
            'children' => $childrenThisRoom,
            'occupancy_type' => $occupancyType,
        ];

        $remainingGuests -= $guestsThisRoom;
        $remainingAdults -= $adultsThisRoom;
        $remainingChildren -= $childrenThisRoom;
    }

    if ($remainingGuests !== 0 || $remainingAdults !== 0 || $remainingChildren !== 0) {
        return [
            'valid' => false,
            'rooms_needed' => $roomsNeeded,
            'allocation' => [],
            'message' => 'Unable to allocate the full guest list across the required rooms.'
        ];
    }

    return [
        'valid' => true,
        'rooms_needed' => $roomsNeeded,
        'allocation' => $allocation,
    ];
}

// Check if booking system is enabled
requireBookingEnabled();

// Generate per-session CSRF token for the booking form
$booking_csrf_token = pub_csrf_generate('booking');

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF validation — must pass before any processing
        if (!pub_csrf_validate($_POST['csrf_token'] ?? '', 'booking')) {
            throw new Exception('Security token invalid. Please refresh the page and try again.');
        }

        // Idempotency short-circuit: if this client_uuid already produced a booking,
        // never create a duplicate — just redirect the guest to its confirmation page.
        // Guarantees that double-clicks, network retries, browser back-button resubmits,
        // and offline-queue replays all converge to the SAME single booking.
        $__incomingClientUuid = $_POST['client_uuid'] ?? null;
        if ($__existing = idem_find_existing_booking($pdo, $__incomingClientUuid)) {
            header('Location: booking-confirmation.php?ref=' . urlencode((string)$__existing['booking_reference']));
            exit;
        }
        // Rate limiting: max 5 booking submissions per 10 minutes
        if (!isset($_SESSION['booking_attempts'])) {
            $_SESSION['booking_attempts'] = [];
        }
        $_SESSION['booking_attempts'] = array_filter($_SESSION['booking_attempts'], function ($t) {
            return $t > time() - 600;
        });
        if (count($_SESSION['booking_attempts']) >= 5) {
            throw new Exception('Too many booking attempts. Please wait a few minutes before trying again.');
        }
        $_SESSION['booking_attempts'][] = time();

        // Initialize validation errors array
        $validation_errors = [];
        $sanitized_data = [];

        // Validate room_id
        $room_validation = validateRoomId($_POST['room_id'] ?? '');
        if (!$room_validation['valid']) {
            $validation_errors['room_id'] = $room_validation['error'];
        } else {
            $sanitized_data['room_id'] = $room_validation['room']['id'];
        }

        // Validate guest_name
        $name_validation = validateName($_POST['guest_name'] ?? '', 2, true);
        if (!$name_validation['valid']) {
            $validation_errors['guest_name'] = $name_validation['error'];
        } else {
            $sanitized_data['guest_name'] = sanitizeString($name_validation['value'], 100);
        }

        // Validate guest_email
        $guest_email_value = $_POST['guest_email'] ?? '';

        if (empty($guest_email_value)) {
            $validation_errors['guest_email'] = 'Guest email is required';
        } else {
            $guest_email_value = trim($guest_email_value);

            if (!filter_var($guest_email_value, FILTER_VALIDATE_EMAIL)) {
                $validation_errors['guest_email'] = 'Please enter a valid email address';
            } else {
                $sanitized_data['guest_email'] = sanitizeString($guest_email_value, 254);
            }
        }

        // Validate guest_phone
        $phone_validation = validatePhone($_POST['guest_phone'] ?? '');
        if (!$phone_validation['valid']) {
            $validation_errors['guest_phone'] = $phone_validation['error'];
        } else {
            $sanitized_data['guest_phone'] = $phone_validation['sanitized'];
        }

        // Validate guest_country (optional)
        $country_validation = validateText($_POST['guest_country'] ?? '', 0, 100, false);
        if (!$country_validation['valid']) {
            $validation_errors['guest_country'] = $country_validation['error'];
        } else {
            $sanitized_data['guest_country'] = sanitizeString($country_validation['value'], 100);
        }

        // Validate guest_address (optional)
        $address_validation = validateText($_POST['guest_address'] ?? '', 0, 500, false);
        if (!$address_validation['valid']) {
            $validation_errors['guest_address'] = $address_validation['error'];
        } else {
            $sanitized_data['guest_address'] = sanitizeString($address_validation['value'], 500);
        }

        // Validate number_of_guests (total guests: adults + children)
        $guests_validation = validateNumber($_POST['number_of_guests'] ?? '', 1, 20, true);
        if (!$guests_validation['valid']) {
            $validation_errors['number_of_guests'] = $guests_validation['error'];
        } else {
            $sanitized_data['number_of_guests'] = $guests_validation['value'];
        }

        // Validate child_guests (optional)
        $children_validation = validateNumber($_POST['child_guests'] ?? 0, 0, 20, false);
        if (!$children_validation['valid']) {
            $validation_errors['child_guests'] = $children_validation['error'];
        } else {
            $sanitized_data['child_guests'] = (int)($children_validation['value'] ?? 0);
        }

        // Validate check_in_date
        $check_in_validation = validateDate($_POST['check_in_date'] ?? '', false, true);
        if (!$check_in_validation['valid']) {
            $validation_errors['check_in_date'] = $check_in_validation['error'];
        } else {
            $sanitized_data['check_in_date'] = $check_in_validation['date']->format('Y-m-d');
        }

        // Validate check_out_date
        $check_out_validation = validateDate($_POST['check_out_date'] ?? '', false, true);
        if (!$check_out_validation['valid']) {
            $validation_errors['check_out_date'] = $check_out_validation['error'];
        } else {
            $sanitized_data['check_out_date'] = $check_out_validation['date']->format('Y-m-d');
        }

        // Validate date range
        if (empty($validation_errors['check_in_date']) && empty($validation_errors['check_out_date'])) {
            $date_range_validation = validateDateRange($sanitized_data['check_in_date'], $sanitized_data['check_out_date'], 30);
            if (!$date_range_validation['valid']) {
                $validation_errors['dates'] = $date_range_validation['error'];
            }
        }

        // Validate special_requests (optional)
        $requests_validation = validateText($_POST['special_requests'] ?? '', 0, 1000, false);
        if (!$requests_validation['valid']) {
            $validation_errors['special_requests'] = $requests_validation['error'];
        } else {
            $sanitized_data['special_requests'] = sanitizeString($requests_validation['value'], 1000);
        }

        // Adults/children consistency validation
        if (isset($sanitized_data['number_of_guests'], $sanitized_data['child_guests'])) {
            $totalGuests = (int)$sanitized_data['number_of_guests'];
            $childGuests = (int)$sanitized_data['child_guests'];
            $adultGuests = $totalGuests - $childGuests;

            if ($childGuests >= $totalGuests) {
                $validation_errors['child_guests'] = 'At least 1 adult is required for every booking';
            }

            if ($adultGuests < 1) {
                $validation_errors['number_of_guests'] = 'At least 1 adult is required';
            } else {
                $sanitized_data['adult_guests'] = $adultGuests;
            }
        }

        // Check for validation errors
        if (!empty($validation_errors)) {
            $error_messages = [];
            foreach ($validation_errors as $field => $message) {
                $error_messages[] = '• ' . $message;
            }
            throw new Exception('Please fix the following before submitting: ' . implode(' ', $error_messages));
        }

        // Load room now so we can apply occupancy policies before availability validation
        $room_pre_stmt = $pdo->prepare("
            SELECT r.*,
                   GREATEST(
                       r.max_guests,
                       COALESCE((SELECT MAX(ir.max_guests_override) FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1), 0),
                       COALESCE((SELECT MAX(rc.max_guests_combined) FROM room_combinations rc WHERE rc.combined_room_type_id = r.id AND rc.is_active = 1), 0)
                   ) AS max_guests
            FROM rooms r
            WHERE r.id = ? AND r.is_active = 1
        ");
        $room_pre_stmt->execute([$sanitized_data['room_id']]);
        $selected_room = $room_pre_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$selected_room) {
            throw new Exception('Selected room not found or inactive.');
        }

        $roomPolicy = bookingResolveOccupancyPolicy($selected_room);
        $maxOccupancyPerBooking = !empty($roomPolicy['triple_enabled']) ? 3 : (!empty($roomPolicy['double_enabled']) ? 2 : (!empty($roomPolicy['single_enabled']) ? 1 : 0));
        if ($maxOccupancyPerBooking < 1) {
            throw new Exception('Selected room type has no enabled occupancy pricing. Please contact support.');
        }

        if (empty($roomPolicy['children_allowed']) && ((int)($sanitized_data['child_guests'] ?? 0) > 0)) {
            throw new Exception('Children are not allowed for the selected room type.');
        }

        // Use enhanced validation with availability check
        // First, validate against the room's actual max_guests capacity
        $maxGuestsPerRoom = (int)($selected_room['max_guests'] ?? 1);
        if ($maxGuestsPerRoom < 1) $maxGuestsPerRoom = 1;

        $allocation_result = bookingBuildGuestAllocation(
            (int)$sanitized_data['number_of_guests'],
            (int)($sanitized_data['child_guests'] ?? 0),
            $selected_room,
            $roomPolicy
        );
        if (empty($allocation_result['valid'])) {
            throw new Exception($allocation_result['message'] ?? 'Unable to allocate guests across rooms.');
        }
        $bookingAllocation = $allocation_result['allocation'];
        $roomsNeeded = (int)$allocation_result['rooms_needed'];

        // For availability check, cap at occupancy pricing tier (this is for pricing, not capacity)
        $validation_payload = $sanitized_data;
        if ((int)$validation_payload['number_of_guests'] > $maxOccupancyPerBooking) {
            $validation_payload['number_of_guests'] = $maxOccupancyPerBooking;
            $validation_payload['child_guests'] = min((int)$validation_payload['child_guests'], max(0, $maxOccupancyPerBooking - 1));
            $validation_payload['adult_guests'] = max(1, (int)$validation_payload['number_of_guests'] - (int)$validation_payload['child_guests']);
        }
        $validation_result = validateBookingWithAvailability($validation_payload);

        if (!$validation_result['valid']) {
            // Handle validation errors
            if ($validation_result['type'] === 'availability') {
                // Room availability issue - provide detailed conflict info
                $conflict_message = $validation_result['errors']['availability'];
                if (!empty($validation_result['conflicts'])) {
                    $conflict_message .= ' ' . $validation_result['errors']['conflicts'];
                }
                throw new Exception($conflict_message);
            } elseif ($validation_result['type'] === 'capacity') {
                // Room capacity issue
                throw new Exception($validation_result['errors']['number_of_guests']);
            } else {
                // General validation errors
                $error_messages = [];
                foreach ($validation_result['errors'] as $field => $message) {
                    $error_messages[] = "$field: $message";
                }
                throw new Exception(implode('; ', $error_messages));
            }
        }

        // All validations passed - proceed with booking
        $room_id = $sanitized_data['room_id'];
        $guest_name = $sanitized_data['guest_name'];
        $guest_email = $sanitized_data['guest_email'];
        $guest_phone = $sanitized_data['guest_phone'];
        $guest_country = $sanitized_data['guest_country'];
        $guest_address = $sanitized_data['guest_address'];
        $number_of_guests = $sanitized_data['number_of_guests'];
        $child_guests = (int)($sanitized_data['child_guests'] ?? 0);
        $adult_guests = (int)($sanitized_data['adult_guests'] ?? max(1, $number_of_guests - $child_guests));
        $check_in_date = $sanitized_data['check_in_date'];
        $check_out_date = $sanitized_data['check_out_date'];
        $special_requests = $sanitized_data['special_requests'];

        // Get booking type (standard or tentative)
        $is_tentative_booking = isset($_POST['booking_type'])
            && $_POST['booking_type'] === 'tentative'
            && getSetting('tentative_bookings_enabled', '1') !== '0';

        // Get room details for pricing
        $room = $selected_room;
        $number_of_nights = $validation_result['availability']['nights'];

        if (roomTypeHasActiveCombinations((int)$room['id'])) {
            $availableCombinationsForPricing = getAvailableRoomCombinations((int)$room['id'], $check_in_date, $check_out_date);
            if (empty($availableCombinationsForPricing)) {
                throw new Exception('All joined rooms for this room type are already reserved for those dates.');
            }
            $pricingCombination = $availableCombinationsForPricing[0];
            $combinedRate = $pricingCombination['price_override'] !== null && $pricingCombination['price_override'] !== ''
                ? (float)$pricingCombination['price_override']
                : (float)$room['price_per_night'];
            $room['price_per_night'] = $combinedRate;
            $room['price_single_occupancy'] = $combinedRate;
            $room['price_double_occupancy'] = $combinedRate;
            $room['price_triple_occupancy'] = $combinedRate;
            $room['max_guests'] = max((int)$room['max_guests'], (int)($pricingCombination['max_guests_combined'] ?? 0));
        }

        // Determine primary occupancy type from the actual split allocation.
        $occupancyPolicy = bookingResolveOccupancyPolicy($room);
        $occupancy_type = $bookingAllocation[0]['occupancy_type'] ?? null;

        if ($occupancy_type === null) {
            $enabledOptions = [];
            if (!empty($occupancyPolicy['single_enabled'])) $enabledOptions[] = 'single';
            if (!empty($occupancyPolicy['double_enabled'])) $enabledOptions[] = 'double';
            if (!empty($occupancyPolicy['triple_enabled'])) $enabledOptions[] = 'triple';
            $optionsList = empty($enabledOptions) ? 'none' : implode(', ', $enabledOptions);
            throw new Exception("No valid occupancy option available for the allocated room. Room configuration allows: {$optionsList}. Please contact support.");
        }

        if (empty($occupancyPolicy['children_allowed']) && $child_guests > 0) {
            throw new Exception('Children are not allowed for the selected room type.');
        }

        $room_price = bookingPriceForOccupancy($room, $occupancy_type);

        // ── Dynamic pricing: apply any matching rate plan ──────────────
        $dynamicResult    = applyDynamicPricing($pdo, $room_id, $check_in_date, $check_out_date, $number_of_nights, (float)$room_price);
        $room_price       = $dynamicResult['final_price'];
        $applied_rate_plan_id    = $dynamicResult['rate_plan_id'];
        $applied_rate_plan_label = $dynamicResult['rate_plan_label'];
        $applied_rate_discount   = $dynamicResult['discount_amount'];
        $applied_rate_plan_row   = $dynamicResult['rate_plan_row'];

        $base_amount = $room_price * $number_of_nights;
        $child_price_multiplier = isset($room['child_price_multiplier'])
            ? (float)$room['child_price_multiplier']
            : (float)getSetting('booking_child_price_multiplier', getSetting('child_guest_price_multiplier', 50));
        if ($child_price_multiplier < 0) {
            $child_price_multiplier = 0;
        }

        $child_rate_per_night = $room_price * ($child_price_multiplier / 100);
        $child_supplement_total = $child_guests > 0 ? ($child_rate_per_night * $child_guests * $number_of_nights) : 0;
        $total_amount = $base_amount + $child_supplement_total;

        $tourism_levy_enabled = (bool)getSetting('tourism_levy_enabled', false);
        $tourism_levy_percent = (float)getSetting('tourism_levy_percent', 0);
        $tourism_levy_amount = 0.0;
        if ($tourism_levy_enabled && $tourism_levy_percent > 0) {
            $tourism_levy_amount = ($base_amount + $child_supplement_total) * ($tourism_levy_percent / 100);
            $total_amount += $tourism_levy_amount;
        }

        // ── Packages: validate and cost selected add-ons ───────────────
        $selectedPackageIds  = [];
        $packageTotal        = 0.0;
        $packageRowsToInsert = [];
        $rawPkgIds = $_POST['package_ids'] ?? [];
        if (!empty($rawPkgIds) && is_array($rawPkgIds)) {
            $selectedPackageIds = array_values(array_unique(array_map('intval', $rawPkgIds)));
        }
        if (!empty($selectedPackageIds)) {
            $availablePkgs = getActivePackages($pdo, $room_id);
            foreach ($availablePkgs as $pkg) {
                if (in_array((int)$pkg['id'], $selectedPackageIds, true)) {
                    $cost = calculatePackageCost($pkg, $number_of_nights, $adult_guests);
                    $packageTotal += $cost;
                    $packageRowsToInsert[] = [
                        'package_id'   => (int)$pkg['id'],
                        'package_name' => $pkg['name'],
                        'price_type'   => $pkg['price_type'],
                        'price_amount' => (float)$pkg['price_amount'],
                        'total_cost'   => $cost,
                    ];
                }
            }
        }
        $total_amount += $packageTotal;

        // Check for duplicate bookings (same email, room, overlapping dates)
        $dup_check = $pdo->prepare("
            SELECT COUNT(*) as count FROM bookings
            WHERE guest_email = ? AND room_id = ?
            AND status IN ('pending', 'tentative', 'confirmed', 'checked-in')
            AND check_in_date = ? AND check_out_date = ?
        ");
        $dup_check->execute([
            $sanitized_data['guest_email'],
            $sanitized_data['room_id'],
            $check_in_date,
            $check_out_date
        ]);
        if ($dup_check->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            throw new Exception('A booking already exists for these dates and room. Please check your existing bookings.');
        }

        // Generate unique booking reference (guaranteed unique)
        $ref_prefix = getSetting('booking_reference_prefix', 'LSH');
        do {
            $booking_reference = $ref_prefix . date('Y') . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            $ref_check = $pdo->prepare("SELECT COUNT(*) as count FROM bookings WHERE booking_reference = ?");
            $ref_check->execute([$booking_reference]);
            $ref_exists = $ref_check->fetch(PDO::FETCH_ASSOC)['count'] > 0;
        } while ($ref_exists);

        // Determine status and tentative expiration
        $booking_status = $is_tentative_booking ? 'tentative' : 'pending';
        $is_tentative = $is_tentative_booking ? 1 : 0;
        $tentative_expires_at = null;

        if ($is_tentative_booking) {
            // Get tentative duration from settings (default 48 hours)
            $tentative_duration_hours = (int)getSetting('tentative_duration_hours', 48);
            $tentative_expires_at = date('Y-m-d H:i:s', strtotime("+{$tentative_duration_hours} hours"));
        }

        // Auto-split guests across multiple bookings using the validated allocation.
        $maxGuestsPerRoom = (int)($room['max_guests'] ?? 1);
        if ($maxGuestsPerRoom < 1) $maxGuestsPerRoom = 1;

        // Insert booking(s) with transaction for data integrity.
        // Conflict check is now INSIDE the transaction with a per-room row lock to
        // prevent the classic check-then-insert race that allows overbooking when two
        // guests submit for the last room simultaneously.
        $pdo->beginTransaction();

        try {
            // Per-room serialisation lock — concurrent transactions wait here until
            // the current insert+commit finishes, eliminating the race window.
            $lockStmt = $pdo->prepare("SELECT id FROM rooms WHERE id = ? FOR UPDATE");
            $lockStmt->execute([$room_id]);

            $childRoomsNeeded = 0;
            foreach ($bookingAllocation as $allocatedRoom) {
                if ((int)($allocatedRoom['children'] ?? 0) > 0) {
                    $childRoomsNeeded++;
                }
            }

            $lockedAvailability = checkRoomAvailability($room_id, $check_in_date, $check_out_date, null, $child_guests, $childRoomsNeeded);
            $remaining = (int)($lockedAvailability['remaining_rooms'] ?? 0);
            if (empty($lockedAvailability['available'])) {
                throw new Exception($lockedAvailability['error'] ?? "Sorry, {$room['name']} is not available for {$check_in_date} to {$check_out_date}. Please choose different dates or another room type.");
            }

            if ($roomsNeeded > $remaining) {
                if ($remaining === 0) {
                    throw new Exception("Sorry, {$room['name']} is fully booked for {$check_in_date} to {$check_out_date}. Please choose different dates or another room type.");
                } else {
                    throw new Exception("Only {$remaining} room" . ($remaining === 1 ? '' : 's') . " available for {$room['name']} on those dates, but your group requires {$roomsNeeded}. Please adjust your guest count or dates.");
                }
            }

            $insert_stmt = $pdo->prepare("
                INSERT INTO bookings (
                    booking_reference, room_id, guest_name, guest_email, guest_phone,
                    guest_country, guest_address, number_of_guests, adult_guests, child_guests,
                    child_price_multiplier, check_in_date, check_out_date, number_of_nights,
                    total_amount, amount_due, total_with_vat, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
                    special_requests, status,
                    is_tentative, tentative_expires_at, occupancy_type, client_uuid,
                    rate_plan_id, rate_plan_label, rate_plan_discount, package_total
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            // Build per-row idempotency tag. The first row uses the client uuid verbatim;
            // split-bookings get a deterministic suffix so each row stays unique while
            // the SAME client uuid is still findable via the first row on resubmit.
            $__idemBase = idem_normalize_uuid($__incomingClientUuid);

            $createdBookingIds = [];
            $createdReferences = [];
            $createdBookingTotals = [];
            $createdGuestCounts = [];
            $bookingGroupTotal = 0.0;
            $bookingGroupChildSupplementTotal = 0.0;
            $bookingGroupTourismLevyTotal = 0.0;

            for ($i = 0; $i < $roomsNeeded; $i++) {
                $allocationPart = $bookingAllocation[$i];
                $guestsThisBooking = (int)$allocationPart['guests'];
                $adultsThisBooking = (int)$allocationPart['adults'];
                $childrenThisBooking = (int)$allocationPart['children'];
                $occThisBooking = $allocationPart['occupancy_type'];

                $baseRateThisBooking = bookingPriceForOccupancy($room, $occThisBooking);
                $dynamicThisBooking = applyDynamicPricing($pdo, $room_id, $check_in_date, $check_out_date, $number_of_nights, $baseRateThisBooking);
                $rateThisBooking = (float)$dynamicThisBooking['final_price'];
                // Packages added to first booking only; subsequent splits get 0
                $pkgTotalThisBooking = ($i === 0) ? $packageTotal : 0.0;

                $baseThisBooking = $rateThisBooking * $number_of_nights;
                $childSupplementThisBooking = $childrenThisBooking > 0 ? (($rateThisBooking * ($child_price_multiplier / 100)) * $childrenThisBooking * $number_of_nights) : 0;
                $tourismLevyThisBooking = 0.0;
                if ($tourism_levy_enabled && $tourism_levy_percent > 0) {
                    $tourismLevyThisBooking = ($baseThisBooking + $childSupplementThisBooking) * ($tourism_levy_percent / 100);
                }
                $totalThisBooking = $baseThisBooking + $childSupplementThisBooking + $tourismLevyThisBooking + $pkgTotalThisBooking;

                $refForBooking = ($i === 0) ? $booking_reference : ($booking_reference . '-' . ($i + 1));
                if ($i > 0) {
                    $uniqueCheck = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_reference = ?");
                    while (true) {
                        $uniqueCheck->execute([$refForBooking]);
                        if ((int)$uniqueCheck->fetchColumn() === 0) {
                            break;
                        }
                        $refForBooking .= 'X';
                    }
                }

                $requestsForBooking = $special_requests;
                if ($roomsNeeded > 1) {
                    $requestsForBooking = trim($special_requests . ' | Split booking part ' . ($i + 1) . '/' . $roomsNeeded);
                }

                $rowUuid = $__idemBase ? ($i === 0 ? $__idemBase : $__idemBase . '-' . ($i + 1)) : null;
                $insert_stmt->execute([
                    $refForBooking,
                    $room_id,
                    $guest_name,
                    $guest_email,
                    $guest_phone,
                    $guest_country,
                    $guest_address,
                    $guestsThisBooking,
                    $adultsThisBooking,
                    $childrenThisBooking,
                    $child_price_multiplier,
                    $check_in_date,
                    $check_out_date,
                    $number_of_nights,
                    $totalThisBooking,
                    $totalThisBooking,
                    $totalThisBooking,
                    $childSupplementThisBooking,
                    $tourismLevyThisBooking,
                    $tourism_levy_percent,
                    $requestsForBooking,
                    $booking_status,
                    $is_tentative,
                    $tentative_expires_at,
                    $occThisBooking,
                    $rowUuid,
                    $dynamicThisBooking['rate_plan_id'],
                    $dynamicThisBooking['rate_plan_label'] ?: null,
                    $dynamicThisBooking['discount_amount'] ?: null,
                    $pkgTotalThisBooking
                ]);

                $newBookingId = (int)$pdo->lastInsertId();
                if (roomTypeHasActiveCombinations($room_id)) {
                    $availableCombinations = getAvailableRoomCombinations($room_id, $check_in_date, $check_out_date, $newBookingId);
                    if (empty($availableCombinations)) {
                        throw new Exception('Joined rooms are no longer available for those dates. Please choose another date or room type.');
                    }
                    $assignment = assignRoomCombinationToBooking($newBookingId, (int)$availableCombinations[0]['id']);
                    if (empty($assignment['success'])) {
                        throw new Exception($assignment['message'] ?: 'Failed to reserve joined rooms for this booking.');
                    }
                }

                $createdBookingIds[] = $newBookingId;
                $createdReferences[] = $refForBooking;
                $createdBookingTotals[] = $totalThisBooking;
                $createdGuestCounts[] = $guestsThisBooking;
                $bookingGroupTotal += $totalThisBooking;
                $bookingGroupChildSupplementTotal += $childSupplementThisBooking;
                $bookingGroupTourismLevyTotal += $tourismLevyThisBooking;
            }

            // Insert booking_packages rows inside the transaction (atomic with the booking insert)
            if (!empty($packageRowsToInsert) && !empty($createdBookingIds)) {
                $pkgStmt = $pdo->prepare("INSERT INTO booking_packages
                    (booking_id, package_id, package_name, price_type, price_amount, quantity, total_cost)
                    VALUES (?, ?, ?, ?, ?, 1, ?)");
                foreach ($packageRowsToInsert as $pkgRow) {
                    $pkgStmt->execute([
                        $createdBookingIds[0],
                        $pkgRow['package_id'],
                        $pkgRow['package_name'],
                        $pkgRow['price_type'],
                        $pkgRow['price_amount'],
                        $pkgRow['total_cost'],
                    ]);
                }
            }

            // Commit transaction - booking + packages secured atomically
            $pdo->commit();

            // Log booking creation to timeline
            foreach ($createdBookingIds as $index => $bookingId) {
                $timelineBookingData = [
                    'id' => $bookingId,
                    'booking_reference' => $createdReferences[$index],
                    'room_id' => $room_id,
                    'guest_name' => $guest_name,
                    'guest_email' => $guest_email,
                    'check_in_date' => $check_in_date,
                    'check_out_date' => $check_out_date,
                    'number_of_nights' => $number_of_nights,
                    'number_of_guests' => $createdGuestCounts[$index] ?? $number_of_guests,
                    'total_amount' => $createdBookingTotals[$index] ?? $total_amount,
                    'status' => $booking_status,
                    'is_tentative' => $is_tentative
                ];
                logBookingCreated($timelineBookingData, 'guest', null, $guest_name);
                logBookingCreatedAudit($bookingId, $createdReferences[$index], 'guest', $guest_name);
            }

            // Send email notifications using working email system
            $booking_data = [
                'id' => $createdBookingIds[0] ?? $pdo->lastInsertId(),
                'booking_reference' => $createdReferences[0] ?? $booking_reference,
                'room_id' => $room_id,
                'guest_name' => $guest_name,
                'guest_email' => $guest_email,
                'guest_phone' => $guest_phone,
                'check_in_date' => $check_in_date,
                'check_out_date' => $check_out_date,
                'number_of_nights' => $number_of_nights,
                'number_of_guests' => $number_of_guests,
                'adult_guests' => $adult_guests,
                'child_guests' => $child_guests,
                'child_price_multiplier' => $child_price_multiplier,
                'child_supplement_total' => $bookingGroupChildSupplementTotal,
                'tourism_levy_amount' => $bookingGroupTourismLevyTotal,
                'tourism_levy_percent' => $tourism_levy_percent,
                'total_amount' => $bookingGroupTotal,
                'special_requests' => $special_requests,
                'status' => $booking_status,
                'is_tentative' => $is_tentative,
                'tentative_expires_at' => $tentative_expires_at,
                'occupancy_type' => $occupancy_type,
                'room_price' => $roomsNeeded > 1
                    ? round($bookingGroupTotal / max(1, $number_of_nights), 2)
                    : $room_price,
                'room_price_per_room' => $room_price,
                'rooms_needed' => $roomsNeeded,
                'split_count' => count($createdReferences),
                'all_references' => $createdReferences
            ];

            // Send appropriate email based on booking type
            if ($is_tentative_booking) {
                // Send tentative booking confirmation email
                $email_result = sendTentativeBookingConfirmedEmail($booking_data);
                $log_type = "Tentative booking confirmed";
            } else {
                // Send standard booking received email
                $email_result = sendBookingReceivedEmail($booking_data);
                $log_type = "Booking received";
            }

            // Log email result for debugging
            if (!$email_result['success']) {
                error_log("Failed to send {$log_type} email: " . $email_result['message']);
            } else {
                // Log success with preview URL if available
                $logMsg = "{$log_type} email processed (PHPMailer)";
                if (isset($email_result['preview_url'])) {
                    $logMsg .= " - Preview: " . $email_result['preview_url'];
                }
                error_log($logMsg);
            }

            // Send notification to admin (simplified PHPMailer)
            $admin_result = sendAdminNotificationEmail($booking_data);

            if (!$admin_result['success']) {
                error_log("Failed to send admin notification: " . $admin_result['message']);
            } else {
                // Log success with preview URL if available
                $logMsg = "Admin notification processed (PHPMailer)";
                if (isset($admin_result['preview_url'])) {
                    $logMsg .= " - Preview: " . $admin_result['preview_url'];
                }
                error_log($logMsg);
            }

            // Success - redirect to confirmation
            // Burn the per-render idempotency token so the next form load gets a fresh one,
            // letting the same browser create a NEW booking afterwards.
            unset($_SESSION['booking_form_uuid']);
            $_SESSION['booking_success'] = [
                'reference' => $createdReferences[0] ?? $booking_reference,
                'guest_name' => $guest_name,
                'room_name' => $room['name'],
                'check_in' => $check_in_date,
                'check_out' => $check_out_date,
                'nights' => $number_of_nights,
                'total' => $bookingGroupTotal,
                'email_sent' => $email_result['success'],
                'is_tentative' => $is_tentative,
                'tentative_expires_at' => $tentative_expires_at,
                'split_count' => count($createdReferences),
                'all_references' => $createdReferences
            ];

            header('Location: booking-confirmation.php?ref=' . urlencode($createdReferences[0] ?? $booking_reference));
            exit;
        } catch (Exception $e) {
            // Rollback on insert error
            $pdo->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        // Rollback transaction on any error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Get pre-selected room from URL
$preselected_room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
$preselected_room = null;

// Handle hero widget GET parameters
$hero_check_in = '';
$hero_check_out = '';
$hero_guests = '';
$hero_children = '';
$hero_room_type = '';

if (isset($_GET['check_in']) && !empty($_GET['check_in'])) {
    $hero_check_in = sanitizeString($_GET['check_in'], 10);
    // Validate date format
    if (DateTime::createFromFormat('Y-m-d', $hero_check_in) === false) {
        $hero_check_in = '';
    }
}

if (isset($_GET['check_out']) && !empty($_GET['check_out'])) {
    $hero_check_out = sanitizeString($_GET['check_out'], 10);
    // Validate date format
    if (DateTime::createFromFormat('Y-m-d', $hero_check_out) === false) {
        $hero_check_out = '';
    }
}

if (isset($_GET['guests']) && !empty($_GET['guests'])) {
    $hero_guests = (int)$_GET['guests'];
    if ($hero_guests < 1 || $hero_guests > 20) {
        $hero_guests = '';
    }
}

if (isset($_GET['children']) && !empty($_GET['children'])) {
    $hero_children = (int)$_GET['children'];
    if ($hero_children < 0 || $hero_children > 19) {
        $hero_children = '';
    }
}

if (isset($_GET['room_type']) && !empty($_GET['room_type'])) {
    $hero_room_type = sanitizeString($_GET['room_type'], 100);
    // Map room type to room_id if not already set
    if (!$preselected_room_id) {
        $room_type_mapping = [
            'standard' => 'Standard Room',
            'deluxe' => 'Deluxe Room',
            'suite' => 'Suite',
            'family' => 'Family Room'
        ];
        // We'll handle this in JavaScript after fetching rooms
    }
}

// Fetch available rooms for booking form with all details needed for validation
$rooms_stmt = $pdo->query("
    SELECT r.id, r.name, r.price_per_night, r.price_single_occupancy, r.price_double_occupancy,
           r.price_triple_occupancy, r.child_price_multiplier,
           GREATEST(
               r.max_guests,
               COALESCE((SELECT MAX(ir.max_guests_override) FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1), 0),
               COALESCE((SELECT MAX(rc.max_guests_combined) FROM room_combinations rc WHERE rc.combined_room_type_id = r.id AND rc.is_active = 1), 0)
           ) AS max_guests,
           r.rooms_available, r.total_rooms, r.short_description, r.image_url,
           r.single_occupancy_enabled, r.double_occupancy_enabled, r.triple_occupancy_enabled,
           r.children_allowed, r.badge
    FROM rooms r
    WHERE r.is_active = 1
    ORDER BY r.display_order ASC
");
$available_rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

// Extract unique badges for room category filters
$room_badges = ['All'];
$badge_counts = ['All' => count($available_rooms)];
foreach ($available_rooms as $room) {
    if (!empty($room['badge'])) {
        $badge_key = $room['badge'];
        if (!in_array($badge_key, $room_badges)) {
            $room_badges[] = $badge_key;
        }
        $badge_counts[$badge_key] = isset($badge_counts[$badge_key]) ? $badge_counts[$badge_key] + 1 : 1;
    }
}

// Build rooms data for JavaScript with occupancy pricing
$rooms_data = [];
foreach ($available_rooms as $room) {
    $policy = resolveOccupancyPolicy($room, null);
    $rooms_data[] = [
        'id' => (int)$room['id'],
        'name' => $room['name'],
        'max_guests' => (int)$room['max_guests'],
        'price_per_night' => (float)$room['price_per_night'],
        'price_single_occupancy' => (float)($room['price_single_occupancy'] ?? $room['price_per_night']),
        'price_double_occupancy' => (float)($room['price_double_occupancy'] ?? $room['price_per_night']),
        'price_triple_occupancy' => (float)($room['price_triple_occupancy'] ?? $room['price_per_night']),
        'child_price_multiplier' => isset($room['child_price_multiplier']) ? (float)$room['child_price_multiplier'] : (float)getSetting('booking_child_price_multiplier', getSetting('child_guest_price_multiplier', 50)),
        'rooms_available' => (int)$room['rooms_available'],
        'total_rooms' => (int)$room['total_rooms'],
        'single_enabled' => (int)$policy['single_enabled'],
        'double_enabled' => (int)$policy['double_enabled'],
        'triple_enabled' => (int)$policy['triple_enabled'],
        'children_allowed' => (int)$policy['children_allowed']
    ];
}

// Get pre-selected room details
if ($preselected_room_id) {
    foreach ($available_rooms as $room) {
        if ($room['id'] == $preselected_room_id) {
            $preselected_room = $room;
            break;
        }
    }
}

// Fetch site settings
$site_name = getSetting('site_name');
$site_logo = getSetting('site_logo');
$currency_symbol = getSetting('currency_symbol');
$phone_main = getSetting('phone_main');
$email_reservations = getSetting('email_reservations');
$email_reservations_esc = addslashes($email_reservations); // For JavaScript

// Get maximum advance booking days
$max_advance_days = (int)getSetting('max_advance_booking_days');
$max_advance_date = date('Y-m-d', strtotime("+{$max_advance_days} days"));

// Build blocked date sets for booking calendar parity with admin logic
// - Global blocked dates apply to all rooms (room_id IS NULL)
// - Room blocked dates apply only to that room
$blocked_dates_by_room = [];
$global_blocked_dates = [];
$calendar_start_date = date('Y-m-d');
$calendar_end_date = $max_advance_date;
$calendar_blocked_dates = getBlockedDates(null, $calendar_start_date, $calendar_end_date);

foreach ($calendar_blocked_dates as $bd) {
    $blockedDate = $bd['block_date'] ?? null;
    if (!$blockedDate) {
        continue;
    }

    if (($bd['block_scope'] ?? 'type') === 'individual') {
        continue;
    }

    if ($bd['room_id'] === null || $bd['room_id'] === '') {
        $global_blocked_dates[$blockedDate] = true;
        continue;
    }

    $roomIdKey = (int)$bd['room_id'];
    if (!isset($blocked_dates_by_room[$roomIdKey])) {
        $blocked_dates_by_room[$roomIdKey] = [];
    }
    $blocked_dates_by_room[$roomIdKey][$blockedDate] = true;
}

// Normalize to indexed arrays for JSON output
$global_blocked_dates = array_keys($global_blocked_dates);
foreach ($blocked_dates_by_room as $roomId => $datesMap) {
    $blocked_dates_by_room[$roomId] = array_keys($datesMap);
}

// Get booked dates for all rooms (including tentative bookings)
// This ensures the calendar shows unavailable dates on page load
$booked_dates_by_room = [];
try {
    // Get all active rooms
    $roomsStmt = $pdo->query("SELECT id FROM rooms WHERE is_active = 1");
    $activeRooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($activeRooms as $room) {
        $roomId = (int)$room['id'];
        $bookedDates = getBookedDatesForRoom($roomId, $calendar_start_date, $calendar_end_date);
        $booked_dates_by_room[$roomId] = $bookedDates;
    }
} catch (PDOException $e) {
    error_log("Error getting booked dates: " . $e->getMessage());
    $booked_dates_by_room = [];
}

// Get payment policy
$payment_policy = getSetting('payment_policy');

// Fetch policies for footer modals
$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching policies: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php
    $seo_data = [
        'title' => 'Book Your Stay | ' . $site_name,
        'description' => "Book your stay at {$site_name}. Choose from our luxurious rooms and suites, and enjoy a memorable experience.",
        'type' => 'website'
    ];
    require_once 'includes/seo-meta.php';
    ?>

    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=yes">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">

    <!-- Main CSS - Loads all stylesheets in correct order -->
    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">

    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>

<body class="booking-page">
    <?php include 'includes/loader.php'; ?>

    <?php include 'includes/header.php'; ?>
    <?php include 'includes/alert.php'; ?>

    <main id="main-content">
        <div class="main-content">
            <div class="booking-header">
                <p class="booking-header__eyebrow"><i class="fas fa-bed"></i> Room Reservation</p>
                <h1>Book Your Stay</h1>
                <p class="booking-header__sub">Select your dates and room below. We'll confirm your reservation within 24 hours.</p>
                <div class="booking-header__trust">
                    <span><i class="fas fa-shield-alt"></i> Secure Booking</span>
                    <span class="trust-divider">·</span>
                    <span><i class="fas fa-clock"></i> Fast Confirmation</span>
                    <span class="trust-divider">·</span>
                    <span><i class="fas fa-headset"></i> 24/7 Support</span>
                </div>
            </div>

            <?php if (isset($error_message)): ?>
                <?php showAlert($error_message, 'error'); ?>
            <?php endif; ?>

            <div class="booking-form-wrapper">
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="booking-form-card" id="bookingForm">
                <?php
                // Per-render idempotency token. Survives double-clicks, refresh-resubmit,
                // and offline-queue replays — the DB unique index is the ultimate guarantor.
                if (empty($_SESSION['booking_form_uuid'])) {
                    $_SESSION['booking_form_uuid'] = bin2hex(random_bytes(16));
                }
                ?>
                <input type="hidden" name="client_uuid" value="<?php echo htmlspecialchars($_SESSION['booking_form_uuid']); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($booking_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                <!-- Booking Details — date-first UX: pick dates before browsing rooms -->
                <div class="form-section form-section--step" id="bookingDetailsSection">
                    <h3 class="form-section-title"><i class="fas fa-calendar-alt"></i> When Are You Staying?</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="check_in_date" class="required">Check-in Date</label>
                            <div class="calendar-wrapper">
                                <input type="text" id="check_in_date" name="check_in_date" class="form-control" required
                                    placeholder="Select check-in date" readonly>
                            </div>
                            <small class="form-hint">
                                <i class="fas fa-info-circle"></i> Bookings can only be made up to <?php echo $max_advance_days; ?> days in advance
                            </small>
                        </div>
                        <div class="form-group">
                            <label for="check_out_date" class="required">Check-out Date</label>
                            <div class="calendar-wrapper">
                                <input type="text" id="check_out_date" name="check_out_date" class="form-control" required
                                    placeholder="Select check-out date" readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Calendar Legend -->
                    <div class="calendar-legend">
                        <div class="legend-item">
                            <div class="legend-color available"></div>
                            <span>Available</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color booked"></div>
                            <span>Fully Booked</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color blocked"></div>
                            <span>Blocked</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color selected"></div>
                            <span>Selected</span>
                        </div>
                    </div>
                </div>

                <!-- Room Selection — revealed after both dates are selected -->
                <?php if (!$preselected_room): ?>
                    <div class="form-section form-section--room form-section--step" id="roomSectionWrapper" style="<?php echo (!empty($_POST['check_in_date']) && !empty($_POST['check_out_date'])) ? '' : 'display:none'; ?>">
                        <h3 class="form-section-title"><i class="fas fa-bed"></i> Select Your Room</h3>
                        <!-- Room Category Filter Tabs -->
                        <div class="rooms-filter" id="roomsFilterTabs">
                            <?php foreach ($room_badges as $badge): ?>
                                <span class="chip <?php echo $badge === 'All' ? 'active' : ''; ?>"
                                    data-filter="<?php echo htmlspecialchars(strtolower(str_replace(' ', '-', $badge))); ?>"
                                    data-badge-filter="<?php echo htmlspecialchars($badge); ?>">
                                    <?php echo htmlspecialchars($badge); ?>
                                    <small class="chip-count">(<?php echo isset($badge_counts[$badge]) ? $badge_counts[$badge] : 0; ?>)</small>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <div class="room-selection">
                            <!-- Availability message container -->
                            <div id="roomAvailabilityMessage" class="availability-message" style="display: none;"></div>
                            <?php foreach ($available_rooms as $room): ?>
                                <?php
                                $room_badge_value = !empty($room['badge']) ? strtolower(str_replace(' ', '-', $room['badge'])) : 'all';
                                ?>
                                <label class="room-option" onclick="selectRoom(this)"
                                    data-room-id="<?php echo $room['id']; ?>"
                                    data-room-name="<?php echo htmlspecialchars($room['name']); ?>"
                                    data-room-price="<?php echo $room['price_per_night']; ?>"
                                    data-max-guests="<?php echo $room['max_guests']; ?>"
                                    data-rooms-available="<?php echo $room['rooms_available']; ?>"
                                    data-children-allowed="<?php echo (int)$room['children_allowed']; ?>"
                                    data-filter="all <?php echo htmlspecialchars($room_badge_value); ?>"
                                    data-badge="<?php echo htmlspecialchars($room['badge'] ?? ''); ?>">
                                    <input type="radio" name="room_id" value="<?php echo $room['id']; ?>" required>
                                    <div class="room-option__thumb<?php echo empty($room['image_url']) ? ' room-option__thumb--placeholder' : ''; ?>">
                                        <?php if (!empty($room['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($room['image_url']); ?>" alt="<?php echo htmlspecialchars($room['name']); ?>" loading="lazy">
                                        <?php else: ?>
                                        <div class="room-thumb-placeholder"><i class="fas fa-bed"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="room-info">
                                        <h4><?php echo htmlspecialchars($room['name']); ?></h4>
                                        <p><?php echo htmlspecialchars($room['short_description']); ?></p>
                                        <p><i class="fas fa-users"></i> Max <?php echo $room['max_guests']; ?> guests <span class="room-availability-count" data-default-text="(<?php echo $room['rooms_available']; ?> room<?php echo $room['rooms_available'] == 1 ? '' : 's'; ?> available)">(<?php echo $room['rooms_available']; ?> room<?php echo $room['rooms_available'] == 1 ? '' : 's'; ?> available)</span></p>
                                        <?php if ((int)$room['children_allowed']): ?>
                                            <span class="room-child-badge room-child-badge--yes"><i class="fas fa-child" aria-hidden="true"></i> Children welcome</span>
                                        <?php else: ?>
                                            <span class="room-child-badge room-child-badge--no"><i class="fas fa-ban" aria-hidden="true"></i> Adults only</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="room-price">
                                        <div class="room-price-amount"><?php echo $currency_symbol; ?><?php echo number_format($room['price_per_night'], 0); ?></div>
                                        <div class="room-price-period">per night</div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Pre-selected Room Info (shown if room is pre-selected) -->
                <?php if ($preselected_room): ?>
                    <div class="form-section">
                        <h3 class="form-section-title"><i class="fas fa-bed"></i> Selected Room</h3>
                        <div class="room-selection">
                            <div class="room-option selected"
                                data-room-id="<?php echo $preselected_room['id']; ?>"
                                data-room-name="<?php echo htmlspecialchars($preselected_room['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-room-price="<?php echo $preselected_room['price_per_night']; ?>"
                                data-max-guests="<?php echo $preselected_room['max_guests']; ?>"
                                data-children-allowed="<?php echo (int)$preselected_room['children_allowed']; ?>">
                                <input type="hidden" name="room_id" value="<?php echo $preselected_room['id']; ?>" id="preselectedRoomId">
                                <div class="room-option__thumb<?php echo empty($preselected_room['image_url']) ? ' room-option__thumb--placeholder' : ''; ?>">
                                    <?php if (!empty($preselected_room['image_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($preselected_room['image_url']); ?>" alt="<?php echo htmlspecialchars($preselected_room['name']); ?>" loading="lazy">
                                    <?php else: ?>
                                    <div class="room-thumb-placeholder"><i class="fas fa-bed"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="room-info">
                                    <h4><?php echo htmlspecialchars($preselected_room['name']); ?></h4>
                                    <p><?php echo htmlspecialchars($preselected_room['short_description']); ?></p>
                                    <p><i class="fas fa-users"></i> Max <?php echo $preselected_room['max_guests']; ?> guests <span class="room-availability-count" data-default-text="(<?php echo $preselected_room['rooms_available']; ?> room<?php echo $preselected_room['rooms_available'] == 1 ? '' : 's'; ?> available)">(<?php echo $preselected_room['rooms_available']; ?> room<?php echo $preselected_room['rooms_available'] == 1 ? '' : 's'; ?> available)</span></p>
                                    <?php if ((int)$preselected_room['children_allowed']): ?>
                                        <span class="room-child-badge room-child-badge--yes"><i class="fas fa-child" aria-hidden="true"></i> Children welcome</span>
                                    <?php else: ?>
                                        <span class="room-child-badge room-child-badge--no"><i class="fas fa-ban" aria-hidden="true"></i> Adults only</span>
                                    <?php endif; ?>
                                </div>
                                <div class="room-price">
                                    <div class="room-price-amount"><?php echo $currency_symbol; ?><?php echo number_format($preselected_room['price_per_night'], 0); ?></div>
                                    <div class="room-price-period">per night</div>
                                </div>
                            </div>
                        </div>
                        <p class="back-to-rooms-link">
                            <a href="rooms-gallery.php">
                                <i class="fas fa-arrow-left"></i> Choose a different room
                            </a>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Guest Information -->
                <div class="form-section form-section--step">
                    <h3 class="form-section-title"><i class="fas fa-user"></i> Guest Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="guest_name" class="required">Full Name</label>
                            <input type="text" id="guest_name" name="guest_name" class="form-control" required autocomplete="name" autocapitalize="words" placeholder="Your full name" value="<?php echo isset($_POST['guest_name']) ? htmlspecialchars($_POST['guest_name']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="guest_email" class="required">Email Address</label>
                            <input type="email" id="guest_email" name="guest_email" class="form-control" required autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false" placeholder="your@email.com" value="<?php echo isset($_POST['guest_email']) ? htmlspecialchars($_POST['guest_email']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="guest_phone" class="required">Phone Number</label>
                            <input type="tel" id="guest_phone" name="guest_phone" class="form-control" required autocomplete="tel" inputmode="tel" placeholder="+265 999 123 456" value="<?php echo isset($_POST['guest_phone']) ? htmlspecialchars($_POST['guest_phone']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="guest_country">Country</label>
                            <input type="text" id="guest_country" name="guest_country" class="form-control" autocomplete="country-name" autocapitalize="words" value="<?php echo isset($_POST['guest_country']) ? htmlspecialchars($_POST['guest_country']) : ''; ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="guest_address">Address</label>
                        <textarea id="guest_address" name="guest_address" class="form-control" rows="2"><?php echo isset($_POST['guest_address']) ? htmlspecialchars($_POST['guest_address']) : ''; ?></textarea>
                    </div>
                </div>

                <!-- Guest Details + Booking Type: side-by-side on desktop -->
                <div class="form-sections-row">

                <div class="form-section form-section--step" id="guestDetailsSection">
                    <h3 class="form-section-title"><i class="fas fa-users"></i> Guest Details</h3>
                    <div class="form-group">
                        <label for="number_of_guests" class="required">Number of Guests</label>
                        <select id="number_of_guests" name="number_of_guests" class="form-control" required>
                            <option value="">Select room first...</option>
                        </select>
                        <small id="guestCapacityHint" class="form-hint" style="display: none;"></small>
                    </div>

                    <div class="form-group">
                        <label for="child_guests">Children (under 12)</label>
                        <input
                            type="number"
                            id="child_guests"
                            name="child_guests"
                            class="form-control"
                            min="0"
                            max="19"
                            inputmode="numeric"
                            value="<?php echo isset($_POST['child_guests']) ? (int)$_POST['child_guests'] : 0; ?>">
                        <small id="childGuestHint" class="form-hint">Children must be accompanied by at least 1 adult. Children under 12.</small>
                    </div>

                    <!-- Occupancy Type Guide (Informational Only) -->
                    <div class="form-group">
                        <label>Price per Night (by Guest Count)</label>
                        <div class="occupancy-type-group occupancy-guide" id="occupancyTypeGroup">
                            <div class="occupancy-type-label" id="singleOccupancyLabel">
                                <strong>Single</strong>
                                <span>1 Guest</span>
                                <span id="singlePriceDisplay" class="price-display">-</span>
                            </div>
                            <div class="occupancy-type-label selected" id="doubleOccupancyLabel">
                                <strong>Double</strong>
                                <span>2 Guests</span>
                                <span id="doublePriceDisplay" class="price-display">-</span>
                            </div>
                            <div class="occupancy-type-label" id="tripleOccupancyLabel">
                                <strong>Triple</strong>
                                <span>3 Guests</span>
                                <span id="triplePriceDisplay" class="price-display">-</span>
                            </div>
                        </div>
                        <small class="form-hint" id="occupancyHint">
                            <i class="fas fa-info-circle"></i> The rate per night adjusts automatically based on how many guests are staying
                        </small>
                    </div>

                    <!-- Second Room Suggestion (hidden by default) -->
                    <div id="secondRoomSuggestion">
                        <div style="display: flex; align-items: start; gap: 12px;">
                            <i class="fas fa-info-circle" style="color: var(--gold); font-size: 20px; margin-top: 2px;"></i>
                            <div>
                                <h4 style="margin: 0 0 8px 0; color: var(--navy); font-size: 16px;">Group Too Large for One Room</h4>
                                <p style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Your group exceeds the maximum capacity for this room type. Please book this room for some guests, then make a separate booking for the remaining guests — or <a href="contact-us.php" style="color: var(--gold);">contact us</a> for group booking assistance.</p>
                                <div id="secondRoomOptions" style="margin-top: 10px;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:16px;">
                        <label for="special_requests">Special Requests (Optional)</label>
                        <textarea id="special_requests" name="special_requests" class="form-control" rows="3" placeholder="E.g., early check-in, airport pickup, dietary requirements..."><?php echo isset($_POST['special_requests']) ? htmlspecialchars($_POST['special_requests']) : ''; ?></textarea>
                    </div>
                </div>

                <!-- Booking Type Selection -->
                <?php $tentative_bookings_enabled = getSetting('tentative_bookings_enabled', '1') !== '0'; ?>
                <div class="form-section">
                    <h3 class="form-section-title"><i class="fas fa-clipboard-list"></i> Booking Type</h3>
                    <div class="booking-type-selection">
                        <label class="booking-type-option" onclick="selectBookingType('standard')">
                            <input type="radio" name="booking_type" value="standard" checked>
                            <div class="booking-type-content">
                                <div class="booking-type-header">
                                    <i class="fas fa-check-circle"></i>
                                    <h4>Standard Booking</h4>
                                </div>
                                <p class="booking-type-description">
                                    Confirm your booking immediately. Our team will review and confirm your reservation within 24 hours.
                                    Payment details will be provided upon confirmation.
                                </p>
                                <div class="booking-type-badge recommended">
                                    <i class="fas fa-star"></i> Recommended
                                </div>
                            </div>
                        </label>

                        <?php if ($tentative_bookings_enabled): ?>
                            <label class="booking-type-option" onclick="selectBookingType('tentative')">
                                <input type="radio" name="booking_type" value="tentative">
                                <div class="booking-type-content">
                                    <div class="booking-type-header">
                                        <i class="fas fa-clock"></i>
                                        <h4>Tentative Booking</h4>
                                    </div>
                                    <p class="booking-type-description">
                                        Place this room on temporary hold for <?php echo (int)getSetting('tentative_duration_hours', 48); ?> hours without immediate confirmation.
                                        Perfect when you need time to finalize travel plans. You'll receive a reminder before expiration.
                                    </p>
                                    <div class="booking-type-badge info">
                                        <i class="fas fa-info-circle"></i> No payment required yet
                                    </div>
                                </div>
                            </label>
                        <?php endif; ?>
                    </div>
                    <?php if ($tentative_bookings_enabled): ?>
                        <p style="margin-top: 15px; color: #666; font-size: 13px; text-align: center;">
                            <i class="fas fa-lightbulb" style="color: var(--gold);"></i>
                            <strong>Tentative bookings</strong> can be converted to standard bookings anytime before expiration.
                            After expiration, the room hold will be released automatically.
                        </p>
                    <?php endif; ?>
                </div>

                </div><!-- /.form-sections-row -->

                <!-- Rate Plan Badge (shown via JS when a discount/surcharge is active) -->
                <div class="form-section" id="ratePlanSection" style="display:none;">
                    <div id="ratePlanBadge" class="rate-plan-badge"></div>
                </div>

                <!-- Package Add-ons (populated via JS after availability check) -->
                <div class="form-section" id="packagesSection" style="display:none;">
                    <h3 class="form-section-title"><i class="fas fa-gift"></i> Add-On Packages</h3>
                    <p class="form-section-subtitle">
                        Enhance your stay with one of our curated packages.
                    </p>
                    <div id="packagesList"></div>
                </div>

                </form>

                <!-- ── Booking Bottom: summary + submit (below the form) ──── -->
                <div class="booking-bottom-section">

                    <!-- Empty state (hidden once summary is populated) -->
                    <div class="booking-bottom-empty" id="bookingSidebarEmpty">
                        <div class="bottom-empty-inner">
                            <div class="sidebar-empty-icon"><i class="fas fa-clipboard-list"></i></div>
                            <div>
                                <h4>Almost there!</h4>
                                <p>Select your dates, a room, and enter your guest details to see your full booking summary here.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Populated summary (shown by JS) -->
                    <div class="booking-summary" id="bookingSummary">

                        <!-- ── Top bar ── -->
                        <div class="bsum-topbar">
                            <div class="bsum-title">
                                <span class="bsum-title-icon"><i class="fas fa-receipt"></i></span>
                                <span>Booking Summary</span>
                            </div>
                            <div class="bsum-type-badge" id="summaryBookingTypeBadge">
                                <i class="fas fa-check-circle"></i>
                                <span id="summaryBookingType">Standard Booking</span>
                            </div>
                        </div>

                        <!-- ── Room hero ── -->
                        <div class="bsum-room-hero">
                            <div class="bsum-room-name" id="summaryRoom">—</div>
                            <div class="bsum-room-meta">
                                <span class="bsum-rate-type" id="summaryOccupancyType"></span>
                                <span class="bsum-meta-dot">·</span>
                                <span class="bsum-rate-night"><strong id="summaryRatePerNight"></strong><span class="bsum-per-night"> / night</span></span>
                            </div>
                        </div>

                        <!-- ── Dates + nights ── -->
                        <div class="bsum-dates-row">
                            <div class="bsum-date-block">
                                <div class="bsum-date-label"><i class="fas fa-sign-in-alt"></i> Check-in</div>
                                <div class="bsum-date-val" id="summaryCheckIn">—</div>
                            </div>
                            <div class="bsum-nights-badge">
                                <span class="bsum-nights-num" id="summaryNights">—</span>
                                <span class="bsum-nights-lbl">night</span>
                            </div>
                            <div class="bsum-date-block bsum-date-block--out">
                                <div class="bsum-date-label"><i class="fas fa-sign-out-alt"></i> Check-out</div>
                                <div class="bsum-date-val" id="summaryCheckOut">—</div>
                            </div>
                        </div>

                        <!-- ── Guests ── -->
                        <div class="bsum-guests-row">
                            <i class="fas fa-users"></i>
                            <span id="summaryGuests">—</span>
                            <span id="summaryChildChargeRow" style="display:none;" class="bsum-child-charge"><i class="fas fa-child" aria-hidden="true"></i> <span id="summaryChildCharge"></span> child supplement</span>
                        </div>

                        <!-- ── Optional rows (packages / rate plan) ── -->
                        <div id="summaryRatePlanRow" style="display:none;" class="bsum-detail-row">
                            <span id="summaryRatePlanLabel">Special Rate</span>
                            <span id="summaryRatePlanValue" class="summary-discount"></span>
                        </div>
                        <div id="summaryPackageTotalRow" style="display:none;" class="bsum-detail-row bsum-detail-row--packages">
                            <div class="bsum-pkg-info">
                                <span class="bsum-pkg-label"><i class="fas fa-gift" aria-hidden="true"></i> Add-on Packages</span>
                                <ul class="bsum-pkg-list" id="summaryPackageNames"></ul>
                            </div>
                            <span id="summaryPackageTotal" class="bsum-pkg-total"></span>
                        </div>

                        <!-- ── Total ── -->
                        <div class="bsum-total-block">
                            <div class="bsum-total-info">
                                <div class="bsum-total-label">Total Amount</div>
                                <div class="bsum-total-note" id="summaryNote">
                                    <i class="fas fa-info-circle"></i> Payment on confirmation
                                </div>
                                <div id="summaryTourismLevyNote" style="display:none;" class="bsum-total-note bsum-total-note--levy">
                                    <i class="fas fa-percent"></i> <span id="tourismLevyText"></span>
                                </div>
                            </div>
                            <div class="bsum-total-amount" id="summaryTotal">—</div>
                        </div>

                    </div><!-- /#bookingSummary -->

                    <!-- Submit + trust -->
                    <div class="booking-action-bar">
                        <button type="submit" class="btn-submit" form="bookingForm">
                            <i class="fas fa-check-circle"></i> Confirm Booking
                        </button>
                        <p class="booking-footer-info">
                            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($payment_policy); ?>
                        </p>
                        <div class="booking-trust-row">
                            <div class="trust-badge"><i class="fas fa-lock"></i> SSL Secured</div>
                            <div class="trust-badge"><i class="fas fa-check-circle"></i> No Hidden Fees</div>
                            <div class="trust-badge"><i class="fas fa-headset"></i> 24/7 Support</div>
                            <div class="trust-badge"><i class="fas fa-phone-alt"></i> Call to Book</div>
                        </div>
                    </div>

                </div><!-- /.booking-bottom-section -->
            </div><!-- /.booking-form-wrapper -->
        </div>
    </main>

    <script src="js/main.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <!-- Availability Modal -->
    <div id="availabilityModal" class="modal modal--sm">
        <div class="modal__wrapper">
            <button class="avail-modal__close" onclick="closeAvailabilityModal()" aria-label="Close">&times;</button>
            <div class="avail-modal__body">
                <div class="avail-modal__icon-wrap">
                    <i class="fas fa-calendar-times"></i>
                </div>
                <h3 class="avail-modal__title">Room Unavailable</h3>
                <p id="availabilityModalMessage" class="avail-modal__message">
                    The selected room is fully booked for your chosen dates.
                </p>
                <div class="avail-modal__suggestions">
                    <p class="avail-modal__suggestions-title"><i class="fas fa-lightbulb"></i> Suggested Options</p>
                    <ul>
                        <li>Try selecting different check-in or check-out dates</li>
                        <li>Choose another available room type from the list</li>
                        <li>Contact us directly if you need special assistance</li>
                    </ul>
                </div>
            </div>
            <div class="avail-modal__footer">
                <button type="button" class="btn btn--primary" onclick="closeAvailabilityModal()">
                    <i class="fas fa-calendar-alt"></i> Try Different Dates
                </button>
            </div>
        </div>
    </div>

    <script>
        // Site settings
        const emailReservations = '<?php echo $email_reservations_esc; ?>';
        const currencySymbol = '<?php echo htmlspecialchars($currency_symbol); ?>';
        const childPriceMultiplier = <?php echo json_encode((float)getSetting('booking_child_price_multiplier', getSetting('child_guest_price_multiplier', 50))); ?>;

        // Tourism levy settings
        const tourismLevyEnabled = <?php echo json_encode((bool)getSetting('tourism_levy_enabled', false)); ?>;
        const tourismLevyPercent = <?php echo json_encode((float)getSetting('tourism_levy_percent', 0)); ?>;

        // Blocked dates from server (global + per room)
        const globalBlockedDates = <?php echo json_encode(array_values($global_blocked_dates)); ?>;
        const blockedDatesByRoom = <?php echo json_encode($blocked_dates_by_room); ?>;
        const preselectedRoomId = <?php echo $preselected_room_id ? $preselected_room_id : 'null'; ?>;
        const preselectedRoomPrice = <?php echo $preselected_room ? $preselected_room['price_per_night'] : 'null'; ?>;
        const preselectedRoomName = <?php echo $preselected_room ? '"' . addslashes($preselected_room['name']) . '"' : 'null'; ?>;
        const preselectedRoomMaxGuests = <?php echo $preselected_room ? $preselected_room['max_guests'] : 'null'; ?>;

        // Hero widget parameters
        const heroCheckIn = <?php echo $hero_check_in ? '"' . $hero_check_in . '"' : 'null'; ?>;
        const heroCheckOut = <?php echo $hero_check_out ? '"' . $hero_check_out . '"' : 'null'; ?>;
        const heroGuests = <?php echo $hero_guests ? $hero_guests : 'null'; ?>;
        const heroChildren = <?php echo $hero_children ? $hero_children : 'null'; ?>;
        const heroRoomType = <?php echo $hero_room_type ? '"' . $hero_room_type . '"' : 'null'; ?>;

        // Rooms data for dynamic validation
        const roomsData = <?php echo json_encode($rooms_data); ?>;

        // Maximum advance booking window (days)
        const maxAdvanceDays = <?php echo (int)$max_advance_days; ?>;

        let checkInCalendar = null;
        let checkOutCalendar = null;
        let selectedRoomId = preselectedRoomId;
        let selectedRoomPrice = preselectedRoomPrice;
        let selectedRoomName = preselectedRoomName;
        let selectedRoomMaxGuests = preselectedRoomMaxGuests;

        // Dynamic pricing / packages state (populated after availability AJAX call)
        let currentDynamicPricing = null;
        let currentPackages = [];
        let selectedPackageIds = new Set();
        let currentAvailabilityResult = null;

        // Track booked dates per room (dates that are fully booked/unavailable)
        // Pre-loaded with booked dates from database (includes tentative bookings)
        const bookedDatesByRoom = <?php echo json_encode($booked_dates_by_room); ?>;
        // Track the date range that was checked for availability
        let lastCheckedDateRange = {
            checkIn: null,
            checkOut: null,
            roomId: null
        };
    </script>
    <script src="js/booking.js"></script>

    <?php include 'includes/footer.php'; ?>
</body>

</html>
