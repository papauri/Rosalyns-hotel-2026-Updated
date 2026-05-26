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
                $error_messages[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $message;
            }
            throw new Exception(implode('; ', $error_messages));
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
                'room_price' => $room_price,
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
                <h1>Book Your Stay</h1>
                <p>Complete the form below to reserve your room. Our team will confirm your booking shortly.</p>
            </div>

            <?php if (isset($error_message)): ?>
                <?php showAlert($error_message, 'error'); ?>
            <?php endif; ?>

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
                <div class="form-section" id="bookingDetailsSection">
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
                    <div class="form-section form-section--room" id="roomSectionWrapper" style="<?php echo (!empty($_POST['check_in_date']) && !empty($_POST['check_out_date'])) ? '' : 'display:none'; ?>">
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
                            <a href="booking.php">
                                <i class="fas fa-arrow-left"></i> Choose a different room
                            </a>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Guest Information -->
                <div class="form-section">
                    <h3 class="form-section-title"><i class="fas fa-user"></i> Guest Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="guest_name" class="required">Full Name</label>
                            <input type="text" id="guest_name" name="guest_name" class="form-control" required value="<?php echo isset($_POST['guest_name']) ? htmlspecialchars($_POST['guest_name']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="guest_email" class="required">Email Address</label>
                            <input type="email" id="guest_email" name="guest_email" class="form-control" required value="<?php echo isset($_POST['guest_email']) ? htmlspecialchars($_POST['guest_email']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="guest_phone" class="required">Phone Number</label>
                            <input type="tel" id="guest_phone" name="guest_phone" class="form-control" required value="<?php echo isset($_POST['guest_phone']) ? htmlspecialchars($_POST['guest_phone']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="guest_country">Country</label>
                            <input type="text" id="guest_country" name="guest_country" class="form-control" value="<?php echo isset($_POST['guest_country']) ? htmlspecialchars($_POST['guest_country']) : ''; ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="guest_address">Address</label>
                        <textarea id="guest_address" name="guest_address" class="form-control" rows="2"><?php echo isset($_POST['guest_address']) ? htmlspecialchars($_POST['guest_address']) : ''; ?></textarea>
                    </div>
                </div>

                <!-- Guest Details -->
                <div class="form-section" id="guestDetailsSection">
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
                            value="<?php echo isset($_POST['child_guests']) ? (int)$_POST['child_guests'] : 0; ?>">
                        <small id="childGuestHint" class="form-hint">At least 1 adult is required.</small>
                    </div>

                    <!-- Occupancy Type Guide (Informational Only) -->
                    <div class="form-group">
                        <label>Occupancy Pricing Guide</label>
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
                            <i class="fas fa-info-circle"></i> Occupancy type is automatically determined based on your guest count
                        </small>
                    </div>

                    <!-- Second Room Suggestion (hidden by default) -->
                    <div id="secondRoomSuggestion">
                        <div style="display: flex; align-items: start; gap: 12px;">
                            <i class="fas fa-info-circle" style="color: var(--gold); font-size: 20px; margin-top: 2px;"></i>
                            <div>
                                <h4 style="margin: 0 0 8px 0; color: var(--navy); font-size: 16px;">Consider Booking Multiple Rooms</h4>
                                <p style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Your group size exceeds the maximum capacity for one room. You can book multiple rooms to accommodate all guests.</p>
                                <div id="secondRoomOptions" style="margin-top: 10px;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:16px;">
                        <label for="special_requests">Special Requests (Optional)</label>
                        <textarea id="special_requests" name="special_requests" class="form-control" rows="3" placeholder="E.g., early check-in, airport pickup, dietary requirements..."><?php echo isset($_POST['special_requests']) ? htmlspecialchars($_POST['special_requests']) : ''; ?></textarea>
                    </div>
                </div>

                <!-- Rate Plan Badge (shown via JS when a discount/surcharge is active) -->
                <div class="form-section" id="ratePlanSection" style="display:none;">
                    <div id="ratePlanBadge" class="rate-plan-badge"></div>
                </div>

                <!-- Package Add-ons (populated via JS after availability check) -->
                <div class="form-section" id="packagesSection" style="display:none;">
                    <h3 class="form-section-title"><i class="fas fa-gift"></i> Add-On Packages</h3>
                    <p style="font-size:14px; color:var(--color-text-secondary); margin-bottom:16px;">
                        Enhance your stay with one of our curated packages.
                    </p>
                    <div id="packagesList"></div>
                </div>

                <!-- Booking Summary -->
                <div class="booking-summary" id="bookingSummary">
                    <h3><i class="fas fa-receipt"></i> Booking Summary</h3>

                    <!-- Booking Type Badge -->
                    <div class="summary-badge" id="summaryBookingTypeBadge">
                        <i class="fas fa-check-circle"></i> <span id="summaryBookingType">Standard Booking</span>
                    </div>

                    <div class="summary-section">
                        <h4><i class="fas fa-bed"></i> Room Details</h4>
                        <div class="summary-row">
                            <span>Room:</span>
                            <span id="summaryRoom">-</span>
                        </div>
                        <div class="summary-row">
                            <span>Occupancy Type:</span>
                            <span id="summaryOccupancyType">-</span>
                        </div>
                        <div class="summary-row">
                            <span>Rate per Night:</span>
                            <span id="summaryRatePerNight">-</span>
                        </div>
                    </div>

                    <div class="summary-section">
                        <h4><i class="fas fa-calendar-alt"></i> Stay Details</h4>
                        <div class="summary-row">
                            <span>Check-in:</span>
                            <span id="summaryCheckIn">-</span>
                        </div>
                        <div class="summary-row">
                            <span>Check-out:</span>
                            <span id="summaryCheckOut">-</span>
                        </div>
                        <div class="summary-row">
                            <span>Number of Nights:</span>
                            <span id="summaryNights">-</span>
                        </div>
                    </div>

                    <div class="summary-section">
                        <h4><i class="fas fa-users"></i> Guest Details</h4>
                        <div class="summary-row">
                            <span>Guests:</span>
                            <span id="summaryGuests">-</span>
                        </div>
                        <div class="summary-row" id="summaryChildChargeRow" style="display:none;">
                            <span>Child Supplement:</span>
                            <span id="summaryChildCharge">-</span>
                        </div>
                    </div>

                    <div class="summary-section summary-total">
                        <div class="summary-row" id="summaryRatePlanRow" style="display:none;">
                            <span id="summaryRatePlanLabel">Special Rate:</span>
                            <span id="summaryRatePlanValue" class="summary-discount"></span>
                        </div>
                        <div class="summary-row" id="summaryPackageTotalRow" style="display:none;">
                            <span>Packages:</span>
                            <span id="summaryPackageTotal">-</span>
                        </div>
                        <div class="summary-row summary-row--total">
                            <span>Total Amount:</span>
                            <span id="summaryTotal">-</span>
                        </div>
                        <div class="summary-note" id="summaryTourismLevyNote" style="display:none;">
                            <i class="fas fa-percent"></i> <span id="tourismLevyText"></span>
                        </div>
                        <div class="summary-note" id="summaryNote">
                            <i class="fas fa-info-circle"></i> Payment details will be provided upon confirmation
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit" form="bookingForm">
                    <i class="fas fa-check-circle"></i> Confirm Booking
                </button>

                <p class="booking-footer-info">
                    <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($payment_policy); ?>
                </p>
            </form>
        </div>
    </main>

    <script src="js/modal.js"></script>
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
        function showAvailabilityModal(message) {
            const modal = document.getElementById('availabilityModal');
            const msgEl = document.getElementById('availabilityModalMessage');
            if (msgEl) msgEl.innerHTML = message;
            if (modal) {
                modal.classList.add('modal--active');
                document.body.classList.add('modal-open');
            }
        }

        function closeAvailabilityModal() {
            const modal = document.getElementById('availabilityModal');
            if (modal) {
                modal.classList.remove('modal--active');
                document.body.classList.remove('modal-open');
            }
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('availabilityModal');
            if (event.target === modal) {
                closeAvailabilityModal();
            }
        });

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

        function getBlockedDatesForRoom(roomId) {
            const roomKey = roomId !== null && roomId !== undefined ? String(roomId) : null;
            const roomDates = roomKey && blockedDatesByRoom[roomKey] ? blockedDatesByRoom[roomKey] : [];
            return Array.from(new Set([...(globalBlockedDates || []), ...(roomDates || [])]));
        }

        function getBookedDatesForRoom(roomId) {
            const roomKey = roomId !== null && roomId !== undefined ? String(roomId) : null;
            return roomKey && bookedDatesByRoom[roomKey] ? bookedDatesByRoom[roomKey] : [];
        }

        function getAllUnavailableDatesForRoom(roomId) {
            const blockedDates = getBlockedDatesForRoom(roomId);
            const bookedDates = getBookedDatesForRoom(roomId);
            return Array.from(new Set([...blockedDates, ...bookedDates]));
        }

        function getSelectedRoomData() {
            return selectedRoomId ? roomsData.find(room => room.id === selectedRoomId) : null;
        }

        function pickOccupancyForGuestCount(guestCount, room) {
            if (!room || guestCount < 1) return null;
            if (guestCount === 1 && Number(room.single_enabled || 0) === 1) return 'single';
            if (guestCount === 2 && Number(room.double_enabled || 0) === 1) return 'double';
            if (guestCount === 3 && Number(room.triple_enabled || 0) === 1) return 'triple';
            if (guestCount > 3) {
                if (Number(room.triple_enabled || 0) === 1) return 'triple';
                if (Number(room.double_enabled || 0) === 1) return 'double';
                if (Number(room.single_enabled || 0) === 1) return 'single';
            }
            return null;
        }

        function getOccupancyLabel(occupancyType) {
            if (occupancyType === 'single') return 'Single';
            if (occupancyType === 'double') return 'Double';
            if (occupancyType === 'triple') return 'Triple';
            return 'Standard';
        }

        function getPriceForOccupancy(room, occupancyType) {
            if (!room) return 0;
            if (occupancyType === 'single') return Number(room.price_single_occupancy || room.price_per_night || 0);
            if (occupancyType === 'double') return Number(room.price_double_occupancy || room.price_per_night || 0);
            if (occupancyType === 'triple') return Number(room.price_triple_occupancy || room.price_per_night || 0);
            return Number(room.price_per_night || 0);
        }

        function getCurrentChildGuestCount() {
            const childInput = document.getElementById('child_guests');
            return Math.max(0, parseInt(childInput?.value || '0', 10) || 0);
        }

        function getGuestAllocation(totalGuests, room, childGuests = null) {
            const normalizedGuests = Math.max(0, Number(totalGuests || 0));
            if (!room || normalizedGuests < 1) return [];
            const maxGuestsPerRoom = Math.max(1, Number(room.max_guests || 1));
            const roomsNeeded = Math.ceil(normalizedGuests / maxGuestsPerRoom);
            const childCount = Math.min(
                Math.max(0, Number(childGuests === null ? getCurrentChildGuestCount() : childGuests) || 0),
                normalizedGuests
            );
            const adultGuests = normalizedGuests - childCount;

            if (adultGuests < roomsNeeded) return [];

            const allocation = [];
            let remainingGuests = normalizedGuests;
            let remainingAdults = adultGuests;
            let remainingChildren = childCount;

            for (let index = 0; index < roomsNeeded; index++) {
                const roomsLeft = roomsNeeded - index;
                const minForOthers = Math.max(0, roomsLeft - 1);
                const guestsThisRoom = Math.min(maxGuestsPerRoom, Math.max(1, remainingGuests - minForOthers));
                const adultReserveForLaterRooms = Math.max(0, roomsLeft - 1);
                const adultsAvailableThisRoom = remainingAdults - adultReserveForLaterRooms;

                if (adultsAvailableThisRoom < 1) return [];

                let childrenThisRoom = Math.min(remainingChildren, Math.max(0, guestsThisRoom - 1));
                let adultsThisRoom = guestsThisRoom - childrenThisRoom;

                if (adultsThisRoom > adultsAvailableThisRoom) {
                    adultsThisRoom = adultsAvailableThisRoom;
                    childrenThisRoom = guestsThisRoom - adultsThisRoom;
                }

                if (childrenThisRoom > remainingChildren) {
                    childrenThisRoom = remainingChildren;
                    adultsThisRoom = guestsThisRoom - childrenThisRoom;
                }

                if (adultsThisRoom < 1 || childrenThisRoom < 0) return [];

                const occupancyType = pickOccupancyForGuestCount(guestsThisRoom, room);
                allocation.push({
                    guests: guestsThisRoom,
                    adults: adultsThisRoom,
                    children: childrenThisRoom,
                    occupancyType
                });
                remainingGuests -= guestsThisRoom;
                remainingAdults -= adultsThisRoom;
                remainingChildren -= childrenThisRoom;
            }

            return remainingGuests === 0 && remainingAdults === 0 && remainingChildren === 0 ? allocation : [];
        }

        function getRoomsNeededForGuests(totalGuests, room) {
            if (!room || totalGuests < 1) return 0;
            return Math.ceil(totalGuests / Math.max(1, Number(room.max_guests || 1)));
        }

        function getAllocationValidationMessage(totalGuests, room, childGuests = null) {
            const normalizedGuests = Math.max(0, Number(totalGuests || 0));
            if (!room || normalizedGuests < 1) return 'Select a room and guest count first.';
            const childCount = Math.min(
                Math.max(0, Number(childGuests === null ? getCurrentChildGuestCount() : childGuests) || 0),
                normalizedGuests
            );
            const adultGuests = normalizedGuests - childCount;
            const roomsNeeded = getRoomsNeededForGuests(normalizedGuests, room);

            if (adultGuests < 1) {
                return 'At least 1 adult is required for every booking.';
            }

            if (adultGuests < roomsNeeded) {
                return `This group needs ${roomsNeeded} room${roomsNeeded === 1 ? '' : 's'}, so it needs at least ${roomsNeeded} adult${roomsNeeded === 1 ? '' : 's'}.`;
            }

            const allocation = getGuestAllocation(normalizedGuests, room, childCount);
            if (!allocation.length || allocation.some(part => part.occupancyType === null)) {
                return 'The selected room type does not have pricing enabled for this guest combination.';
            }

            return '';
        }

        function hasValidAllocation(totalGuests, room, childGuests = null) {
            const allocation = getGuestAllocation(totalGuests, room, childGuests);
            return allocation.length > 0 && allocation.every(part => part.occupancyType !== null);
        }

        function buildAvailabilityStatusKey(roomId, checkIn, checkOut, childGuests, totalGuests) {
            const room = roomsData.find(item => item.id === Number(roomId));
            const roomsNeeded = getRoomsNeededForGuests(Number(totalGuests || 0), room);
            return `${roomId}_${checkIn}_${checkOut}_${childGuests}_${totalGuests}_${roomsNeeded}`;
        }

        function applyBlockedDatesToCalendars(roomId) {
            const allUnavailableDates = getAllUnavailableDatesForRoom(roomId);

            if (checkInCalendar) {
                checkInCalendar.set('disable', allUnavailableDates);
            }

            if (checkOutCalendar) {
                checkOutCalendar.set('disable', allUnavailableDates);
            }
        }

        // Generate date range between two dates
        function getDateRange(startDate, endDate) {
            const dates = [];
            let currentDate = new Date(startDate);
            const end = new Date(endDate);

            while (currentDate < end) {
                dates.push(currentDate.toISOString().split('T')[0]);
                currentDate.setDate(currentDate.getDate() + 1);
            }
            return dates;
        }

        // Reveal room selection section once both dates are set
        function revealRoomSection() {
            const ci = document.getElementById('check_in_date');
            const co = document.getElementById('check_out_date');
            const wrapper = document.getElementById('roomSectionWrapper');
            if (!wrapper) return;
            if (ci && co && ci.value && co.value && co.value > ci.value) {
                wrapper.style.display = '';
            } else {
                wrapper.style.display = 'none';
            }
        }

        // Initialize calendars
        function initCalendars() {
            const today = new Date();
            const tomorrow = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1);
            const maxDate = new Date();
            maxDate.setDate(maxDate.getDate() + <?php echo $max_advance_days; ?>);

            // Check-in calendar
            checkInCalendar = flatpickr('#check_in_date', {
                minDate: 'today',
                maxDate: maxDate,
                dateFormat: 'Y-m-d',
                disable: getAllUnavailableDatesForRoom(selectedRoomId),
                onDayCreate: function(dObj, dStr, fp, dayElem) {
                    // Add custom class for blocked and booked dates
                    const dateStr = fp.formatDate(dayElem.dateObj, 'Y-m-d');
                    const roomBlockedDates = getBlockedDatesForRoom(selectedRoomId);
                    const roomBookedDates = getBookedDatesForRoom(selectedRoomId);

                    // Check if date is blocked (manually blocked from admin)
                    if (roomBlockedDates.includes(dateStr)) {
                        dayElem.classList.add('blocked-date');
                        dayElem.innerHTML += '<span class="blocked-indicator"></span>';
                    }
                    // Check if date is booked (fully booked from availability check)
                    else if (roomBookedDates.includes(dateStr)) {
                        dayElem.classList.add('booked-date');
                        dayElem.innerHTML += '<span class="booked-indicator"></span>';
                    }
                },
                onChange: function(selectedDates, dateStr, instance) {
                    if (selectedDates.length > 0) {
                        // Update check-out calendar min date
                        const nextDay = new Date(selectedDates[0]);
                        nextDay.setDate(nextDay.getDate() + 1);

                        if (checkOutCalendar) {
                            checkOutCalendar.set('minDate', nextDay);

                            // If check-out is before new min date, clear it
                            const currentCheckOut = checkOutCalendar.selectedDates[0];
                            if (currentCheckOut && currentCheckOut < nextDay) {
                                checkOutCalendar.clear();
                            }
                        }
                    }
                    revealRoomSection();
                    updateSummary();
                }
            });

            // Check-out calendar
            checkOutCalendar = flatpickr('#check_out_date', {
                minDate: tomorrow,
                maxDate: maxDate,
                dateFormat: 'Y-m-d',
                disable: getAllUnavailableDatesForRoom(selectedRoomId),
                onDayCreate: function(dObj, dStr, fp, dayElem) {
                    // Add custom class for blocked and booked dates
                    const dateStr = fp.formatDate(dayElem.dateObj, 'Y-m-d');
                    const roomBlockedDates = getBlockedDatesForRoom(selectedRoomId);
                    const roomBookedDates = getBookedDatesForRoom(selectedRoomId);

                    // Check if date is blocked (manually blocked from admin)
                    if (roomBlockedDates.includes(dateStr)) {
                        dayElem.classList.add('blocked-date');
                        dayElem.innerHTML += '<span class="blocked-indicator"></span>';
                    }
                    // Check if date is booked (fully booked from availability check)
                    else if (roomBookedDates.includes(dateStr)) {
                        dayElem.classList.add('booked-date');
                        dayElem.innerHTML += '<span class="booked-indicator"></span>';
                    }
                },
                onChange: function() {
                    revealRoomSection();
                    updateSummary();
                }
            });
        }

        // Initialize calendars on page load
        document.addEventListener('DOMContentLoaded', function() {
            initCalendars();

            // Handle hero widget parameters - pre-fill form
            if (heroCheckIn && checkInCalendar) {
                checkInCalendar.setDate(heroCheckIn);
            }

            if (heroCheckOut && checkOutCalendar) {
                checkOutCalendar.setDate(heroCheckOut);
            }

            // Handle room type from hero widget
            if (heroRoomType && !preselectedRoomId) {
                // Map room type to room name and find matching room
                const roomTypeMapping = {
                    'standard': 'Standard Room',
                    'deluxe': 'Deluxe Room',
                    'suite': 'Suite',
                    'family': 'Family Room'
                };

                const targetRoomName = roomTypeMapping[heroRoomType];
                if (targetRoomName) {
                    const matchingRoom = roomsData.find(room => room.name === targetRoomName);
                    if (matchingRoom) {
                        // Select the matching room
                        const roomOption = document.querySelector(`.room-option[data-room-id="${matchingRoom.id}"]`);
                        if (roomOption) {
                            selectRoom(roomOption);
                        }
                    }
                }
            }

            // Handle guests from hero widget
            if (heroGuests) {
                const guestSelect = document.getElementById('number_of_guests');
                if (guestSelect) {
                    // Set guests value after room is selected
                    setTimeout(() => {
                        const maxSelectableGuests = selectedRoomMaxGuests ? Math.min(20, Math.max(selectedRoomMaxGuests * 4, selectedRoomMaxGuests)) : 20;
                        guestSelect.value = Math.min(heroGuests, maxSelectableGuests);

                        // Handle children from hero widget
                        if (heroChildren) {
                            const childInput = document.getElementById('child_guests');
                            if (childInput) {
                                const maxChildren = Math.max(0, heroGuests - 1);
                                const childCount = Math.min(heroChildren, maxChildren);
                                childInput.value = childCount;
                            }
                        }

                        enforceChildGuestRules();
                        checkGuestCapacity();
                        updateSummary();
                    }, 100);
                }
            }

            // Handle room type from hero widget - match by room name
            if (heroRoomType && !preselectedRoomId) {
                // Find room by exact name match from roomsData
                const matchingRoom = roomsData.find(room => room.name === heroRoomType);
                if (matchingRoom) {
                    // Select the matching room
                    const roomOption = document.querySelector(`.room-option[data-room-id="${matchingRoom.id}"]`);
                    if (roomOption) {
                        selectRoom(roomOption);
                    }
                }
            }

            // If room is pre-selected, initialize with that room
            if (preselectedRoomId) {
                // Find the pre-selected room data from roomsData
                const preselectedRoom = roomsData.find(room => room.id === preselectedRoomId);

                if (preselectedRoom) {
                    // Create a synthetic room option to call selectRoom
                    // This ensures all room-specific settings are properly initialized
                    const syntheticRoomOption = {
                        querySelector: function(selector) {
                            if (selector === 'input[type="radio"]' || selector === 'input[name="room_id"]') {
                                return {
                                    value: preselectedRoom.id,
                                    checked: true
                                };
                            }
                            if (selector === 'h4') {
                                return {
                                    textContent: preselectedRoom.name
                                };
                            }
                            if (selector === '.room-price-amount') {
                                return {
                                    textContent: currencySymbol + preselectedRoom.price_per_night.toLocaleString()
                                };
                            }
                            if (selector === '.room-price-period') {
                                return {
                                    textContent: 'per night'
                                };
                            }
                            return null;
                        },
                        getAttribute: function(attr) {
                            if (attr === 'data-room-id') return preselectedRoom.id;
                            if (attr === 'data-room-name') return preselectedRoom.name;
                            if (attr === 'data-room-price') return preselectedRoom.price_per_night;
                            if (attr === 'data-max-guests') return preselectedRoom.max_guests;
                            return null;
                        },
                        classList: {
                            add: function() {},
                            contains: function() {
                                return false;
                            }
                        },
                        closest: function() {
                            return this;
                        }
                    };

                    // Call selectRoom to ensure all room-specific settings are applied
                    selectRoom(syntheticRoomOption);

                    // Trigger availability check for pre-selected room if dates are provided
                    if (heroCheckIn && heroCheckOut) {
                        setTimeout(() => {
                            performAvailabilityCheck();
                        }, 200);
                    }
                }
            }

            // Add booking type change listeners
            const bookingTypeRadios = document.querySelectorAll('input[name="booking_type"]');
            bookingTypeRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    selectBookingType(this.value);
                });
            });

            // Reveal room section if dates are already set (e.g. hero widget pre-fill)
            revealRoomSection();
        });

        // Helper function to update occupancy visual selection based on guest count
        function updateOccupancyVisualSelection(guestCount) {
            const room = getSelectedRoomData();
            const perRoomGuests = room ? Math.min(guestCount, Math.max(1, Number(room.max_guests || 1))) : guestCount;
            const selectedType = pickOccupancyForGuestCount(perRoomGuests, room);

            ['single', 'double', 'triple'].forEach(type => {
                const label = document.getElementById(type + 'OccupancyLabel');
                if (label) {
                    label.classList.toggle('selected', selectedType === type);
                }
            });
        }

        // Update price displays based on guest count (occupancy is auto-determined)
        function updatePriceBasedOnGuestCount() {
            if (!selectedRoomId) return;

            const guestSelect = document.getElementById('number_of_guests');
            const guestCount = parseInt(guestSelect?.value || '0', 10);

            // Find the selected room from roomsData
            const selectedRoom = roomsData.find(room => room.id === selectedRoomId);
            if (!selectedRoom) return;

            const perRoomGuests = Math.min(guestCount || 1, Math.max(1, Number(selectedRoom.max_guests || 1)));
            const occupancyType = pickOccupancyForGuestCount(perRoomGuests, selectedRoom);
            const newPrice = getPriceForOccupancy(selectedRoom, occupancyType);

            selectedRoomPrice = newPrice;

            const selectedCardPrice = document.querySelector('.room-option.selected .room-price-amount');
            if (selectedCardPrice) {
                selectedCardPrice.textContent = currencySymbol + Number(newPrice || 0).toLocaleString();
            }

            // Update visual selection based on guest count
            updateOccupancyVisualSelection(guestCount);

            // Update summary after price change to reflect new pricing
            updateSummary();
        }

        function applyOccupancyAvailability(room) {
            const occupancyHint = document.getElementById('occupancyHint');
            const mapping = [{
                    key: 'single_enabled',
                    labelId: 'singleOccupancyLabel',
                    value: 'single'
                },
                {
                    key: 'double_enabled',
                    labelId: 'doubleOccupancyLabel',
                    value: 'double'
                },
                {
                    key: 'triple_enabled',
                    labelId: 'tripleOccupancyLabel',
                    value: 'triple'
                }
            ];

            let enabledCount = 0;
            mapping.forEach(item => {
                const enabled = Number(room[item.key] || 0) === 1;
                if (enabled) {
                    enabledCount++;
                }
                const label = document.getElementById(item.labelId);
                if (label) {
                    label.classList.toggle('occupancy-tier--disabled', !enabled);
                    label.setAttribute('aria-disabled', enabled ? 'false' : 'true');
                    label.classList.remove('selected');
                }
            });

            // Update hint based on available options
            if (occupancyHint) {
                if (enabledCount === 1) {
                    const enabledType = mapping.find(item => Number(room[item.key] || 0) === 1);
                    if (enabledType) {
                        const typeName = enabledType.key.replace('_enabled', '');
                        occupancyHint.innerHTML = `<i class="fas fa-info-circle"></i> Only ${typeName} occupancy available for this room`;
                    }
                } else {
                    occupancyHint.innerHTML = '<i class="fas fa-info-circle"></i> Occupancy type is automatically determined based on your guest count';
                }
            }

            // Update price and visual selection after applying availability
            updatePriceBasedOnGuestCount();
        }

        function applyChildrenPolicy(room) {
            const childInput = document.getElementById('child_guests');
            const childHint = document.getElementById('childGuestHint');
            const childGroup = childInput ? childInput.closest('.form-group') : null;
            const allowed = Number(room.children_allowed || 0) === 1;
            if (!childInput) return;

            childInput.disabled = !allowed;

            // Visual indication for disabled state
            if (childGroup) {
                childGroup.style.opacity = allowed ? '1' : '0.5';
            }

            if (!allowed) {
                childInput.value = '0';
                if (childHint) {
                    childHint.innerHTML = '<i class="fas fa-ban" style="color: #dc3545;"></i> Children are not allowed for this room type.';
                    childHint.style.color = '#dc3545';
                }
            } else {
                // Update hint with pricing info
                const childMultiplier = Number(room.child_price_multiplier || childPriceMultiplier || 50);
                if (childHint) {
                    childHint.innerHTML = `<i class="fas fa-child"></i> Children under 12 stay at ${childMultiplier}% of adult rate. At least 1 adult required.`;
                    childHint.style.color = '#666';
                }
            }

            // Update summary after applying policy
            updateSummary();
        }

        // Update occupancy price displays when room is selected
        function updateOccupancyPrices(roomId) {
            const room = roomsData.find(r => r.id === roomId);
            if (!room) return;

            const singlePrice = document.getElementById('singlePriceDisplay');
            const doublePrice = document.getElementById('doublePriceDisplay');
            const triplePrice = document.getElementById('triplePriceDisplay');

            if (singlePrice) {
                singlePrice.textContent = currencySymbol + room.price_single_occupancy.toLocaleString();
            }
            if (doublePrice) {
                doublePrice.textContent = currencySymbol + room.price_double_occupancy.toLocaleString();
            }
            if (triplePrice) {
                triplePrice.textContent = currencySymbol + room.price_triple_occupancy.toLocaleString();
            }
        }

        function updateSummaryWithDates(selection) {
            const roomRadio = document.querySelector('input[name="room_id"]:checked');
            if (!roomRadio) return;

            const roomOption = roomRadio.closest('.room-option');
            const roomName = roomOption.querySelector('h4').textContent;
            const roomPrice = parseFloat(roomOption.querySelector('.room-price-amount').textContent.replace(/[^0-9.]/g, ''));

            const checkInDate = new Date(selection.checkIn);
            const checkOutDate = new Date(selection.checkOut);

            document.getElementById('summaryRoom').textContent = roomName;
            document.getElementById('summaryCheckIn').textContent = checkInDate.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
            document.getElementById('summaryCheckOut').textContent = checkOutDate.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
            document.getElementById('summaryNights').textContent = selection.nights + (selection.nights === 1 ? ' night' : ' nights');
            document.getElementById('summaryTotal').textContent = currencySymbol + (roomPrice * selection.nights).toLocaleString();
            document.getElementById('bookingSummary').style.display = 'block';

            // Enable submit button
            const submitBtn = document.querySelector('.btn-submit');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Booking';
            submitBtn.style.opacity = '1';
        }

        function selectRoom(label) {
            document.querySelectorAll('.room-option').forEach(opt => opt.classList.remove('selected'));
            label.classList.add('selected');

            const previousGuests = parseInt(document.getElementById('number_of_guests')?.value || heroGuests || '0', 10);
            const roomRadio = label.querySelector('input[name="room_id"]');
            const roomId = parseInt(roomRadio.value);
            const roomName = label.getAttribute('data-room-name');
            const roomPrice = parseFloat(label.getAttribute('data-room-price'));

            roomRadio.checked = true;

            selectedRoomId = roomId;
            selectedRoomName = roomName;
            selectedRoomMaxGuests = parseInt(label.getAttribute('data-max-guests'));

            // Update guest options based on room capacity
            updateGuestOptions(selectedRoomMaxGuests);

            const guestSelect = document.getElementById('number_of_guests');
            const selectedRoom = roomsData.find(room => room.id === roomId);
            const maxSelectableGuests = Math.min(20, Math.max(selectedRoomMaxGuests * 4, selectedRoomMaxGuests));
            const fallbackGuests = selectedRoomMaxGuests > 1 ? 2 : 1;
            const desiredGuests = previousGuests > 0 ? previousGuests : fallbackGuests;
            const normalizedGuests = Math.min(maxSelectableGuests, Math.max(1, desiredGuests));
            guestSelect.value = hasValidAllocation(normalizedGuests, selectedRoom) ? String(normalizedGuests) : '1';

            // Update occupancy prices for this room
            updateOccupancyPrices(roomId);

            // Find the room data from roomsData array
            const room = roomsData.find(r => r.id === roomId);
            if (room) {
                applyOccupancyAvailability(room);
                applyChildrenPolicy(room);
            }

            // Update child-friendly indicators across all room cards
            const currentChildGuests = getCurrentChildGuestCount();
            refreshChildFriendlyRoomIndicators(currentChildGuests);

            // Update price based on guest count (occupancy is auto-determined)
            updatePriceBasedOnGuestCount();
            checkGuestCapacity();

            // Update calendars with selected room blocked dates (global + room-specific)
            applyBlockedDatesToCalendars(roomId);

            if (document.getElementById('check_in_date')?.value && document.getElementById('check_out_date')?.value) {
                performAvailabilityCheck();
            }
        }

        // Update guest dropdown options based on room capacity
        function updateGuestOptions(maxGuests) {
            const guestSelect = document.getElementById('number_of_guests');
            const capacityHint = document.getElementById('guestCapacityHint');
            const room = getSelectedRoomData();
            const currentValue = parseInt(guestSelect.value || '0', 10);
            const maxSelectableGuests = Math.min(20, Math.max(maxGuests * 4, maxGuests));

            // Clear existing options
            guestSelect.innerHTML = '<option value="">Select number of guests...</option>';

            for (let i = 1; i <= maxSelectableGuests; i++) {
                const option = document.createElement('option');
                option.value = i;
                const roomsNeeded = Math.ceil(i / Math.max(1, maxGuests));
                option.textContent = i + (i === 1 ? ' Guest' : ' Guests') + (roomsNeeded > 1 ? ` (${roomsNeeded} rooms)` : '');
                if (room && !hasValidAllocation(i, room)) {
                    option.disabled = true;
                    option.textContent += ' - pricing unavailable';
                }
                guestSelect.appendChild(option);
            }

            // Update capacity hint
            capacityHint.textContent = `This room accommodates up to ${maxGuests} guest${maxGuests > 1 ? 's' : ''} per room. Larger groups are split across multiple rooms automatically.`;
            capacityHint.style.display = 'block';

            if (currentValue && currentValue <= maxSelectableGuests) {
                guestSelect.value = String(currentValue);
            }

            // Hide second room suggestion
            document.getElementById('secondRoomSuggestion').style.display = 'none';
        }

        // Check if guests exceed capacity and show second room suggestion
        function checkGuestCapacity() {
            const guestSelect = document.getElementById('number_of_guests');
            const numGuests = parseInt(guestSelect.value);
            const suggestionBox = document.getElementById('secondRoomSuggestion');
            const optionsContainer = document.getElementById('secondRoomOptions');
            const room = getSelectedRoomData();
            const childGuests = getCurrentChildGuestCount();

            if (!numGuests || !selectedRoomMaxGuests || !room) {
                suggestionBox.style.display = 'none';
                return;
            }

            const allocationMessage = getAllocationValidationMessage(numGuests, room, childGuests);
            if (allocationMessage) {
                suggestionBox.style.display = 'block';
                optionsContainer.innerHTML = `
                    <div class="booking-split-notice booking-split-notice--warning">
                        <strong>Guest allocation needs attention</strong>
                        <p>${allocationMessage}</p>
                    </div>
                `;
                validateFormForSubmit();
                return;
            }

            // Check if guests exceed room capacity
            if (numGuests > selectedRoomMaxGuests) {
                suggestionBox.style.display = 'block';

                // Calculate how many rooms needed
                const roomsNeeded = Math.ceil(numGuests / selectedRoomMaxGuests);
                const allocation = getGuestAllocation(numGuests, room, childGuests);
                const allocationText = allocation.map((part, index) => `Room ${index + 1}: ${part.adults} adult${part.adults === 1 ? '' : 's'}${part.children > 0 ? ` + ${part.children} child${part.children === 1 ? '' : 'ren'}` : ''} (${getOccupancyLabel(part.occupancyType)})`).join(' • ');

                // Build suggestion message
                let html = `
                    <div class="booking-split-notice">
                        <strong>${roomsNeeded} ${selectedRoomName} room${roomsNeeded > 1 ? 's' : ''} will be reserved</strong>
                        <p>Each room accommodates up to ${selectedRoomMaxGuests} guest${selectedRoomMaxGuests > 1 ? 's' : ''}. Your booking will be split automatically under one group request.</p>
                        <p>${allocationText}</p>
                    </div>
                `;

                optionsContainer.innerHTML = html;
                validateFormForSubmit();
            } else {
                suggestionBox.style.display = 'none';

                // Enable submit button if all validations pass
                validateFormForSubmit();
            }
        }

        // Validate form for submit
        function validateFormForSubmit() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const numGuests = document.getElementById('number_of_guests').value;
            const childGuests = parseInt(document.getElementById('child_guests').value || '0', 10);
            const submitBtn = document.querySelector('.btn-submit');

            const totalGuestsInt = parseInt(numGuests || '0', 10);
            const adultsInt = totalGuestsInt - childGuests;
            const childValid = childGuests >= 0 && childGuests < totalGuestsInt;
            const selectedRoom = getSelectedRoomData();
            const allocationValid = selectedRoom ? hasValidAllocation(totalGuestsInt, selectedRoom, childGuests) : false;

            if (selectedRoomId && checkIn && checkOut && numGuests && childValid && adultsInt >= 1 && allocationValid) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Booking';
                submitBtn.style.opacity = '1';
            } else {
                submitBtn.disabled = true;
                submitBtn.innerHTML = allocationValid || !selectedRoomId ?
                    '<i class="fas fa-calendar-check"></i> Complete All Fields (1+ adult required)' :
                    '<i class="fas fa-exclamation-triangle"></i> Choose a Supported Guest Count';
                submitBtn.style.opacity = '0.6';
            }
        }

        function updateBlockedDatesForRoom(roomId) {
            // Local parity with admin blocking logic; no auth-protected API call needed.
            applyBlockedDatesToCalendars(roomId);
        }

        function checkRoomAvailability(roomId, checkIn, checkOut, childGuests, callback) {
            const totalGuests = parseInt(document.getElementById('number_of_guests')?.value || '1', 10);
            const childCount = parseInt(childGuests || '0', 10);
            const adultGuests = Math.max(1, totalGuests - childCount);
            const room = roomsData.find(item => item.id === Number(roomId));
            const roomsNeeded = getRoomsNeededForGuests(totalGuests, room);
            const url = `check-availability.php?room_id=${roomId}&check_in=${checkIn}&check_out=${checkOut}&child_guests=${childCount}&adult_guests=${adultGuests}&number_of_guests=${totalGuests}&rooms_needed=${roomsNeeded}`;

            fetch(url)
                .then(response => response.json())
                .then(callback)
                .catch(() => {
                    callback({
                        available: false,
                        message: 'Unable to check availability'
                    });
                });
        }


        // ── Dynamic pricing + packages helpers ────────────────────────────
        function applyDynamicPricingState(pricing) {
            currentDynamicPricing = pricing || null;
            const section = document.getElementById('ratePlanSection');
            const badge = document.getElementById('ratePlanBadge');
            if (!section || !badge) return;

            if (pricing && pricing.rate_plan_id) {
                // discount_amount > 0 means a genuine discount (price reduced); < 0 means a surcharge
                const adjustment = Number(pricing.discount_amount_per_night_total ?? pricing.discount_amount ?? 0);
                const sign = adjustment > 0 ? '-' : '+';
                const abs = Math.abs(adjustment);
                badge.innerHTML = `<i class="fas fa-tag"></i> <strong>${pricing.rate_plan_label}</strong>
                    <span class="rate-plan-badge__amount">${sign}${currencySymbol}${abs.toLocaleString()}/night</span>`;
                badge.className = adjustment > 0 ?
                    'rate-plan-badge rate-plan-badge--discount' :
                    'rate-plan-badge rate-plan-badge--surcharge';
                section.style.display = '';
            } else {
                section.style.display = 'none';
            }
        }

        function renderPackages(packages, nights, adultGuests) {
            currentPackages = packages || [];
            const section = document.getElementById('packagesSection');
            const list = document.getElementById('packagesList');
            if (!section || !list) return;

            if (!currentPackages.length) {
                section.style.display = 'none';
                return;
            }

            section.style.display = '';
            list.innerHTML = currentPackages.map(pkg => {
                const isComplimentary = parseFloat(pkg.price_amount) === 0;
                let cost = 0;
                if (!isComplimentary) {
                    if (pkg.price_type === 'per_night') {
                        cost = pkg.price_amount * nights;
                    } else if (pkg.price_type === 'per_stay') {
                        cost = pkg.price_amount;
                    } else {
                        cost = pkg.price_amount * adultGuests * nights;
                    }
                }

                const priceHtml = isComplimentary ?
                    `<span class="package-option__price package-option__price--free"><i class="fas fa-gift"></i> Complimentary</span>` :
                    `<span class="package-option__price">${currencySymbol}${cost.toLocaleString()}</span>`;

                const inclusions = Array.isArray(pkg.inclusions_list) && pkg.inclusions_list.length ?
                    `<ul class="package-option__inclusions">${pkg.inclusions_list.map(i => `<li>${i}</li>`).join('')}</ul>` :
                    '';

                const checked = selectedPackageIds.has(Number(pkg.id));
                return `<label class="package-option${checked ? ' selected' : ''}" data-pkg-id="${pkg.id}" data-pkg-cost="${cost}">
                    <input type="checkbox" name="package_ids[]" value="${pkg.id}" form="bookingForm"${checked ? ' checked' : ''}>
                    <div class="package-option__body">
                        <div class="package-option__header">
                            <span class="package-option__icon"><i class="${pkg.icon || 'fas fa-gift'}"></i></span>
                            <span class="package-option__name">${pkg.name}</span>
                            ${priceHtml}
                        </div>
                        ${pkg.short_description ? `<p class="package-option__desc">${pkg.short_description}</p>` : ''}
                        ${inclusions}
                    </div>
                </label>`;
            }).join('');

            // Attach change handlers
            list.querySelectorAll('.package-option').forEach(label => {
                const cb = label.querySelector('input[type="checkbox"]');
                cb.addEventListener('change', () => {
                    const id = Number(label.dataset.pkgId);
                    if (cb.checked) {
                        selectedPackageIds.add(id);
                        label.classList.add('selected');
                    } else {
                        selectedPackageIds.delete(id);
                        label.classList.remove('selected');
                    }
                    updateSummary();
                });
            });
        }

        function getPackageTotal(nights, adultGuests) {
            if (!currentPackages.length || !selectedPackageIds.size) return 0;
            let total = 0;
            currentPackages.forEach(pkg => {
                if (!selectedPackageIds.has(Number(pkg.id))) return;
                if (pkg.price_type === 'per_night') {
                    total += pkg.price_amount * nights;
                } else if (pkg.price_type === 'per_stay') {
                    total += pkg.price_amount;
                } else {
                    total += pkg.price_amount * adultGuests * nights;
                }
            });
            return total;
        }
        // ─────────────────────────────────────────────────────────────────

        function updateSummary() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const totalGuests = parseInt(document.getElementById('number_of_guests').value || '0', 10);
            const childGuests = parseInt(document.getElementById('child_guests').value || '0', 10);
            const adults = Math.max(0, totalGuests - childGuests);
            const bookingSummary = document.getElementById('bookingSummary');
            const childChargeRow = document.getElementById('summaryChildChargeRow');
            const childChargeEl = document.getElementById('summaryChildCharge');
            const summaryGuests = document.getElementById('summaryGuests');
            const bookingTypeBadge = document.getElementById('summaryBookingTypeBadge');
            const bookingTypeText = document.getElementById('summaryBookingType');

            if (!bookingSummary) return;

            if (!selectedRoomId || !checkIn || !checkOut || totalGuests < 1) {
                bookingSummary.style.display = 'none';
                validateFormForSubmit();
                return;
            }

            if (selectedRoomId && checkIn && checkOut) {
                const checkInDate = new Date(checkIn);
                const checkOutDate = new Date(checkOut);
                const nights = Math.ceil((checkOutDate - checkInDate) / (1000 * 60 * 60 * 24));

                if (nights > 0) {
                    // Find the selected room from roomsData
                    const selectedRoom = roomsData.find(room => room.id === selectedRoomId);
                    if (!selectedRoom) return;

                    const roomsNeeded = getRoomsNeededForGuests(totalGuests, selectedRoom);
                    const statusKey = buildAvailabilityStatusKey(selectedRoomId, checkIn, checkOut, childGuests, totalGuests);
                    const serverPricing = currentAvailabilityResult && currentAvailabilityResult.status_key === statusKey ?
                        currentAvailabilityResult.split_pricing :
                        null;
                    const allocation = serverPricing && Array.isArray(serverPricing.allocation) ?
                        serverPricing.allocation.map(part => ({
                            guests: Number(part.guests || 0),
                            adults: Number(part.adults || 0),
                            children: Number(part.children || 0),
                            occupancyType: part.occupancy_type,
                            ratePerNight: Number(part.rate_per_night || 0)
                        })) :
                        getGuestAllocation(totalGuests, selectedRoom, childGuests);

                    if (!allocation.length || allocation.some(part => !part.occupancyType)) {
                        bookingSummary.style.display = 'none';
                        validateFormForSubmit();
                        return;
                    }

                    let roomRateTotalPerNight = 0;
                    let baseTotal = 0;
                    let childSupplement = 0;
                    let remainingChildren = childGuests;
                    const roomChildMultiplier = selectedRoom.child_price_multiplier !== undefined ?
                        Number(selectedRoom.child_price_multiplier) :
                        Number(childPriceMultiplier);

                    if (serverPricing) {
                        roomRateTotalPerNight = Number(serverPricing.room_rate_total_per_night || 0);
                        baseTotal = Number(serverPricing.base_total || 0);
                        childSupplement = Number(serverPricing.child_supplement_total || 0);
                    } else {
                        allocation.forEach(part => {
                            let ratePerNight = getPriceForOccupancy(selectedRoom, part.occupancyType);
                            if (currentDynamicPricing && currentDynamicPricing.rate_plan_id && allocation.length === 1) {
                                ratePerNight = Number(currentDynamicPricing.final_price || ratePerNight);
                            }
                            const childrenThisRoom = Math.min(remainingChildren, Math.max(0, part.guests - 1));
                            remainingChildren -= childrenThisRoom;
                            roomRateTotalPerNight += ratePerNight;
                            baseTotal += ratePerNight * nights;
                            childSupplement += childrenThisRoom > 0 ?
                                ratePerNight * (Math.max(0, roomChildMultiplier || 0) / 100) * childrenThisRoom * nights :
                                0;
                            part.ratePerNight = ratePerNight;
                            part.children = childrenThisRoom;
                            part.adults = Math.max(1, part.guests - childrenThisRoom);
                        });
                    }

                    // Calculate package total from selected packages
                    const pkgTotal = getPackageTotal(nights, adults);

                    // Calculate tourism levy if enabled
                    let tourismLevyAmount = serverPricing ? Number(serverPricing.tourism_levy_amount || 0) : 0;
                    if (!serverPricing && tourismLevyEnabled && tourismLevyPercent > 0) {
                        tourismLevyAmount = (baseTotal + childSupplement) * (tourismLevyPercent / 100);
                    }

                    const total = baseTotal + childSupplement + tourismLevyAmount + pkgTotal;

                    // Update booking type badge
                    const selectedBookingType = document.querySelector('input[name="booking_type"]:checked');
                    if (selectedBookingType && bookingTypeBadge && bookingTypeText) {
                        const isTentative = selectedBookingType.value === 'tentative';
                        bookingTypeBadge.className = 'summary-badge ' + (isTentative ? 'badge-tentative' : 'badge-standard');
                        bookingTypeText.textContent = isTentative ? 'Tentative Booking' : 'Standard Booking';
                        bookingTypeBadge.innerHTML = isTentative ?
                            '<i class="fas fa-clock"></i> <span id="summaryBookingType">Tentative Booking</span>' :
                            '<i class="fas fa-check-circle"></i> <span id="summaryBookingType">Standard Booking</span>';
                    }

                    // Update room details section
                    document.getElementById('summaryRoom').textContent = selectedRoomName;
                    const occupancySummary = roomsNeeded > 1 ?
                        `${totalGuests} Guests (${roomsNeeded} rooms: ${allocation.map((part, index) => `R${index + 1} ${getOccupancyLabel(part.occupancyType)}`).join(', ')})` :
                        `${getOccupancyLabel(allocation[0].occupancyType)} (${totalGuests} Guest${totalGuests === 1 ? '' : 's'})`;
                    document.getElementById('summaryOccupancyType').textContent = occupancySummary;
                    document.getElementById('summaryRatePerNight').textContent = roomsNeeded > 1 ?
                        `${currencySymbol}${roomRateTotalPerNight.toLocaleString()}/night across ${roomsNeeded} rooms` :
                        `${currencySymbol}${roomRateTotalPerNight.toLocaleString()}/night`;

                    // Update stay details section
                    document.getElementById('summaryCheckIn').textContent = checkInDate.toLocaleDateString('en-US', {
                        weekday: 'short',
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric'
                    });
                    document.getElementById('summaryCheckOut').textContent = checkOutDate.toLocaleDateString('en-US', {
                        weekday: 'short',
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric'
                    });
                    document.getElementById('summaryNights').textContent = nights + (nights === 1 ? ' night' : ' nights');

                    // Update guest details section
                    if (summaryGuests) {
                        summaryGuests.textContent = `${adults} adult${adults === 1 ? '' : 's'}${childGuests > 0 ? ` + ${childGuests} child${childGuests === 1 ? '' : 'ren'}` : ''}`;
                    }

                    // Update child supplement
                    if (childChargeRow && childChargeEl) {
                        if (childGuests > 0) {
                            childChargeRow.style.display = '';
                            childChargeEl.textContent = currencySymbol + childSupplement.toLocaleString() + ` (${childGuests} child${childGuests === 1 ? '' : 'ren'} across ${nights} night${nights === 1 ? '' : 's'})`;
                        } else {
                            childChargeRow.style.display = 'none';
                            childChargeEl.textContent = '-';
                        }
                    }

                    // Update rate plan summary row
                    const ratePlanRow = document.getElementById('summaryRatePlanRow');
                    const ratePlanLabel = document.getElementById('summaryRatePlanLabel');
                    const ratePlanValue = document.getElementById('summaryRatePlanValue');
                    const serverRatePlan = serverPricing && serverPricing.rate_plan ? serverPricing.rate_plan : null;
                    const activeRatePlan = serverRatePlan || currentDynamicPricing;
                    if (ratePlanRow && activeRatePlan && activeRatePlan.rate_plan_id) {
                        const adj = Number(activeRatePlan.discount_amount_per_night_total ?? activeRatePlan.discount_amount ?? 0);
                        // adj > 0 = genuine discount (price reduced); adj < 0 = surcharge
                        const sign = adj > 0 ? '-' : '+';
                        ratePlanLabel.textContent = (activeRatePlan.rate_plan_label || 'Special Rate') + ':';
                        ratePlanValue.textContent = sign + currencySymbol + Math.abs(adj).toLocaleString() + '/night';
                        ratePlanRow.style.display = '';
                    } else if (ratePlanRow) {
                        ratePlanRow.style.display = 'none';
                    }

                    // Update package total row
                    const pkgTotalRow = document.getElementById('summaryPackageTotalRow');
                    const pkgTotalEl = document.getElementById('summaryPackageTotal');
                    const compCount = currentPackages.filter(p => selectedPackageIds.has(Number(p.id)) && parseFloat(p.price_amount) === 0).length;
                    if (pkgTotalRow) {
                        if (pkgTotal > 0 && compCount > 0) {
                            pkgTotalEl.innerHTML = currencySymbol + pkgTotal.toLocaleString() + ` <small style="color:#1f7a42;">+ ${compCount} complimentary</small>`;
                            pkgTotalRow.style.display = '';
                        } else if (pkgTotal > 0) {
                            pkgTotalEl.textContent = currencySymbol + pkgTotal.toLocaleString();
                            pkgTotalRow.style.display = '';
                        } else if (compCount > 0) {
                            pkgTotalEl.innerHTML = `<span style="color:#1f7a42;"><i class="fas fa-gift"></i> Complimentary</span>`;
                            pkgTotalRow.style.display = '';
                        } else {
                            pkgTotalRow.style.display = 'none';
                        }
                    }

                    // Update total
                    document.getElementById('summaryTotal').textContent = currencySymbol + total.toLocaleString();

                    // Update tourism levy hint
                    const tourismLevyNote = document.getElementById('summaryTourismLevyNote');
                    const tourismLevyText = document.getElementById('tourismLevyText');
                    if (tourismLevyEnabled && tourismLevyPercent > 0 && tourismLevyAmount > 0) {
                        tourismLevyNote.style.display = '';
                        tourismLevyText.textContent = `Includes ${tourismLevyPercent}% Tourism Levy`;
                    } else {
                        tourismLevyNote.style.display = 'none';
                    }

                    bookingSummary.style.display = 'block';
                    validateFormForSubmit();
                } else {
                    bookingSummary.style.display = 'none';
                    validateFormForSubmit();
                }
            }
        }

        // Event listeners will be added inside DOMContentLoaded
        // These are defined here but attached after DOM is ready

        function enforceChildGuestRules() {
            const totalGuests = parseInt(document.getElementById('number_of_guests').value || '0', 10);
            const childInput = document.getElementById('child_guests');
            const childHint = document.getElementById('childGuestHint');
            if (!childInput) return;

            if (childInput.disabled) {
                childInput.value = '0';
                if (childHint) {
                    childHint.innerHTML = '<i class="fas fa-ban" style="color: #dc3545;"></i> Children are not allowed for this room type.';
                }
                return;
            }

            const selectedRoom = getSelectedRoomData();
            const roomsNeeded = selectedRoom ? getRoomsNeededForGuests(totalGuests, selectedRoom) : 1;
            const maxChildren = Math.max(0, totalGuests - roomsNeeded);
            childInput.max = String(maxChildren);

            let childGuests = parseInt(childInput.value || '0', 10);
            if (Number.isNaN(childGuests) || childGuests < 0) childGuests = 0;
            if (childGuests > maxChildren) {
                childGuests = maxChildren;
                childInput.value = String(childGuests);
            }

            const adults = Math.max(0, totalGuests - childGuests);

            // Get room-specific child price multiplier
            let effectiveMultiplier = childPriceMultiplier;
            if (selectedRoomId) {
                const selectedRoom = roomsData.find(room => room.id === selectedRoomId);
                if (selectedRoom && selectedRoom.child_price_multiplier !== undefined) {
                    effectiveMultiplier = Number(selectedRoom.child_price_multiplier);
                }
            }

            if (childHint) {
                let hintHtml = '';
                if (childGuests > 0 && adults >= 1) {
                    // Show breakdown with pricing
                    hintHtml = `<i class="fas fa-users"></i> ${adults} adult${adults === 1 ? '' : 's'} + ${childGuests} child${childGuests === 1 ? '' : 'ren'}`;
                    hintHtml += ` <span style="color: var(--gold);">• Child rate: ${effectiveMultiplier}% of adult price</span>`;
                } else if (adults < 1) {
                    hintHtml = `<i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> At least 1 adult is required. Current: ${adults} adult${adults === 1 ? '' : 's'}`;
                } else {
                    hintHtml = `<i class="fas fa-child"></i> Children under 12 stay at ${effectiveMultiplier}% of adult rate. At least 1 adult is required in each room. Max ${maxChildren} children for this selection.`;
                }
                childHint.innerHTML = hintHtml;
            }

            refreshChildFriendlyRoomIndicators(childGuests);
        }

        // Event listener for child_guests will be added inside DOMContentLoaded

        // Booking type selection function
        function selectBookingType(type) {
            document.querySelectorAll('.booking-type-option').forEach(opt => {
                opt.classList.remove('selected');
            });

            const selectedOption = document.querySelector(`input[name="booking_type"][value="${type}"]`);
            if (selectedOption) {
                selectedOption.closest('.booking-type-option').classList.add('selected');
            }

            // Update summary to reflect booking type change
            updateSummary();
        }

        // Room Category Filter Tabs for booking page
        (function() {
            const filterTabs = document.querySelectorAll('#roomsFilterTabs .chip');
            const roomOptions = document.querySelectorAll('.room-option[data-filter]');

            if (filterTabs.length === 0 || roomOptions.length === 0) return;

            filterTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const filterValue = this.getAttribute('data-filter');
                    const badgeFilter = this.getAttribute('data-badge-filter');

                    // Update active tab
                    filterTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');

                    // Filter room options - respect availability hiding
                    roomOptions.forEach(option => {
                        const optionFilter = option.getAttribute('data-filter');

                        // Check if this room is currently disabled due to availability
                        const isAvailabilityDisabled = option.classList.contains('room-option-disabled');
                        const radio = option.querySelector('input[type="radio"]');
                        const isRadioDisabled = radio && radio.disabled;

                        // Apply filter
                        if (filterValue === 'all' || (optionFilter && optionFilter.includes(filterValue))) {
                            // Show this room option
                            option.style.display = '';

                            // If it was availability-disabled, keep it disabled but visible
                            if (isAvailabilityDisabled || isRadioDisabled) {
                                option.style.opacity = '0.5';
                                option.style.pointerEvents = 'none';
                                if (radio) radio.disabled = true;
                            } else {
                                option.style.opacity = '';
                                option.style.pointerEvents = '';
                                if (radio) radio.disabled = false;
                            }
                        } else {
                            // Hide this room option
                            option.style.display = 'none';
                        }
                    });

                    // If the currently selected room is hidden, clear selection
                    if (selectedRoomId) {
                        const selectedOption = document.querySelector(`.room-option[data-room-id="${selectedRoomId}"]`);
                        if (selectedOption && selectedOption.style.display === 'none') {
                            clearRoomSelection();
                        }
                    }
                });
            });
        })();

        // Initialize booking type selection on page load
        document.addEventListener('DOMContentLoaded', function() {
            selectBookingType('standard');
            enforceChildGuestRules();
            validateFormForSubmit();
            initInstantValidation();
            initAvailabilityValidation();

            // Add event listeners for date inputs (must be after DOM is ready)
            const checkInInput = document.getElementById('check_in_date');
            const checkOutInput = document.getElementById('check_out_date');
            const guestsInput = document.getElementById('number_of_guests');
            const childGuestsInput = document.getElementById('child_guests');

            if (checkInInput) {
                checkInInput.addEventListener('change', function() {
                    const checkIn = new Date(this.value);
                    const nextDay = new Date(checkIn);
                    nextDay.setDate(checkIn.getDate() + 1);
                    if (checkOutInput) {
                        checkOutInput.min = nextDay.toISOString().split('T')[0];
                    }
                    updateSummary();
                    validateFormForSubmit();
                });

                // Also trigger availability check on check-in date change
                checkInInput.addEventListener('input', function() {
                    updateSummary();
                    validateFormForSubmit();
                });
            }

            if (checkOutInput) {
                checkOutInput.addEventListener('change', function() {
                    updateSummary();
                    validateFormForSubmit();
                });

                // Also trigger availability check on check-out date change
                checkOutInput.addEventListener('input', function() {
                    updateSummary();
                    validateFormForSubmit();
                });
            }

            // Add guest count change listener - occupancy is auto-determined
            if (guestsInput) {
                guestsInput.addEventListener('change', function() {
                    checkGuestCapacity();
                    enforceChildGuestRules();
                    updatePriceBasedOnGuestCount();
                    updateSummary();
                    validateFormForSubmit();
                });
            }

            // Add child guests input listener
            if (childGuestsInput) {
                childGuestsInput.addEventListener('input', function() {
                    enforceChildGuestRules();
                    checkGuestCapacity();
                    updateSummary();
                    validateFormForSubmit();
                });
            }
        });

        // Availability state tracking
        let availabilityCheckPending = false;
        let availabilityCheckTimer = null;
        let roomAvailabilityStatus = {}; // Track availability status per room

        // Initialize immediate availability validation
        function initAvailabilityValidation() {
            const checkInInput = document.getElementById('check_in_date');
            const checkOutInput = document.getElementById('check_out_date');
            const guestsInput = document.getElementById('number_of_guests');
            const childInput = document.getElementById('child_guests');
            const bookingForm = document.getElementById('bookingForm');

            // Trigger availability check immediately when both dates are entered
            const triggerImmediateAvailabilityCheck = () => {
                const checkIn = checkInInput ? checkInInput.value : '';
                const checkOut = checkOutInput ? checkOutInput.value : '';

                // If both dates are entered, trigger availability check immediately
                if (checkIn && checkOut) {
                    clearTimeout(availabilityCheckTimer);
                    availabilityCheckTimer = setTimeout(() => {
                        performAvailabilityCheck();
                    }, 100); // Minimal delay to ensure UI updates first
                }
            };

            // Debounced availability check on date/guest changes
            const scheduleAvailabilityCheck = () => {
                clearTimeout(availabilityCheckTimer);
                availabilityCheckTimer = setTimeout(() => {
                    performAvailabilityCheck();
                }, 300); // Reduced from 500ms to 300ms for faster response
            };

            // Add event listeners for immediate validation
            // Use 'input' event for immediate response when user selects dates
            if (checkInInput) {
                checkInInput.addEventListener('input', triggerImmediateAvailabilityCheck);
                checkInInput.addEventListener('change', scheduleAvailabilityCheck);
            }
            if (checkOutInput) {
                checkOutInput.addEventListener('input', triggerImmediateAvailabilityCheck);
                checkOutInput.addEventListener('change', scheduleAvailabilityCheck);
            }
            if (guestsInput) {
                guestsInput.addEventListener('change', scheduleAvailabilityCheck);
            }
            if (childInput) {
                childInput.addEventListener('change', scheduleAvailabilityCheck);
            }

            // Prevent form submission if availability check is pending or failed
            if (bookingForm) {
                bookingForm.addEventListener('submit', function(e) {
                    if (availabilityCheckPending) {
                        e.preventDefault();
                        showAvailabilityMessage('<i class="fas fa-spinner fa-spin"></i> Checking availability... Please wait.', 'warning');
                        return false;
                    }

                    if (selectedRoomId) {
                        const checkIn = checkInInput ? checkInInput.value : '';
                        const checkOut = checkOutInput ? checkOutInput.value : '';
                        const childGuests = childInput ? parseInt(childInput.value || '0', 10) : 0;
                        const totalGuests = parseInt(guestsInput?.value || '1', 10);

                        if (checkIn && checkOut) {
                            const statusKey = buildAvailabilityStatusKey(selectedRoomId, checkIn, checkOut, childGuests, totalGuests);
                            const roomStatus = roomAvailabilityStatus[statusKey];

                            if (roomStatus && !roomStatus.available) {
                                e.preventDefault();

                                // Clean up any raw HTML that might be in the error message
                                let reasonText = roomStatus.error || "This room is fully booked for your selected dates.";
                                // Strip HTML tags
                                reasonText = reasonText.replace(/<\/?[^>]+(>|$)/g, "");

                                showAvailabilityModal(`<strong>Room Unavailable:</strong><br>${reasonText}`);
                                return false;
                            }
                        }
                    }
                });
            }
        }

        // Perform availability check for all rooms or selected room
        function performAvailabilityCheck() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const guestsInput = document.getElementById('number_of_guests');
            const numGuests = guestsInput ? parseInt(guestsInput.value || '0', 10) : 0;
            const childInput = document.getElementById('child_guests');
            const childGuests = childInput ? parseInt(childInput.value || '0', 10) : 0;

            // Clear previous availability status if dates are incomplete
            if (!checkIn || !checkOut) {
                clearAvailabilityMessage();
                enableAllRoomOptions();
                return;
            }

            // Validate date range
            const checkInDate = new Date(checkIn);
            const checkOutDate = new Date(checkOut);
            if (checkOutDate <= checkInDate) {
                showAvailabilityMessage('<i class="fas fa-exclamation-triangle"></i> Check-out date must be after check-in date.', 'error');
                return;
            }

            availabilityCheckPending = true;

            const roomOptions = document.querySelectorAll('.room-option[data-room-id]');
            const roomIds = Array.from(roomOptions)
                .map(roomOption => parseInt(roomOption.getAttribute('data-room-id'), 10))
                .filter(roomId => Number.isInteger(roomId));

            if (!roomIds.length) {
                availabilityCheckPending = false;
                validateFormForSubmit();
                return;
            }

            const totalGuests = Math.max(1, numGuests || 1);
            const adultGuests = Math.max(1, totalGuests - childGuests);
            const params = new URLSearchParams({
                room_ids: roomIds.join(','),
                check_in: checkIn,
                check_out: checkOut,
                child_guests: String(childGuests),
                adult_guests: String(adultGuests),
                number_of_guests: String(totalGuests)
            });

            fetch(`check-availability.php?${params.toString()}`)
                .then(response => response.json())
                .then(payload => {
                    const results = payload.rooms || {};
                    let availableCount = 0;

                    roomOptions.forEach(roomOption => {
                        const roomId = parseInt(roomOption.getAttribute('data-room-id'), 10);
                        const result = results[String(roomId)] || results[roomId] || {
                            available: false,
                            message: 'Unable to check availability'
                        };
                        const statusKey = buildAvailabilityStatusKey(roomId, checkIn, checkOut, childGuests, totalGuests);
                        result.status_key = statusKey;
                        roomAvailabilityStatus[statusKey] = result;

                        updateRoomAvailabilityCount(roomOption, result, childGuests);

                        if (result.available) {
                            availableCount++;
                            enableRoomOption(roomOption);

                            if (roomId === selectedRoomId) {
                                currentAvailabilityResult = result;
                                const nights = Number(result.nights || 0);
                                applyDynamicPricingState(result.split_pricing?.rate_plan || result.dynamic_pricing || null);
                                renderPackages(result.packages || [], nights, adultGuests);
                                updateSummary();
                            }
                        } else {
                            disableRoomOption(roomOption, result.message || result.error || 'Unavailable');

                            const shouldCacheAsBooked = Number(result.remaining_rooms || 0) <= 0 &&
                                result.children_required !== true &&
                                !(result.split_pricing && result.split_pricing.valid === false);

                            if (shouldCacheAsBooked) {
                                const roomKey = String(roomId);
                                if (!bookedDatesByRoom[roomKey]) {
                                    bookedDatesByRoom[roomKey] = [];
                                }

                                const dateRange = getDateRange(checkIn, checkOut);
                                dateRange.forEach(date => {
                                    if (!bookedDatesByRoom[roomKey].includes(date)) {
                                        bookedDatesByRoom[roomKey].push(date);
                                    }
                                });
                            }

                            if (shouldCacheAsBooked && (selectedRoomId === roomId || selectedRoomId === null)) {
                                applyBlockedDatesToCalendars(selectedRoomId);
                            }

                            if (selectedRoomId === roomId) {
                                currentAvailabilityResult = result;
                                applyDynamicPricingState(null);
                                showAvailabilityModal(`<strong>Room Unavailable:</strong><br>${result.message || 'This room cannot accommodate the selected stay.'}`);
                            }
                        }
                    });

                    availabilityCheckPending = false;

                    if (availableCount === 0) {
                        showAvailabilityMessage(
                            '<div style="line-height: 1.8;">' +
                            '<i class="fas fa-calendar-times"></i> ' +
                            '<strong>Sorry, all rooms are fully booked or cannot fit your group for the selected dates.</strong><br>' +
                            '<div style="margin-top: 10px; padding: 10px; background: rgba(255, 193, 7, 0.15); border-radius: 4px; border-left: 3px solid #ffc107;">' +
                            '<i class="fas fa-lightbulb" style="color: #ffc107; margin-right: 6px;"></i>' +
                            '<strong>Tip:</strong> Try adjusting your dates, guest count, or room type.' +
                            '</div></div>',
                            'error'
                        );
                    } else if (availableCount < roomOptions.length) {
                        const unavailableCount = roomOptions.length - availableCount;
                        showAvailabilityMessage(
                            '<div style="line-height: 1.8;">' +
                            `<i class="fas fa-info-circle"></i> ` +
                            `<strong>${unavailableCount} room type${unavailableCount > 1 ? 's are' : ' is'} unavailable</strong> for your selected dates or guest count.<br>` +
                            '<div style="margin-top: 8px; padding: 8px; background: rgba(255, 193, 7, 0.1); border-radius: 4px; border-left: 3px solid #ffc107;">' +
                            '<i class="fas fa-lightbulb" style="color: #ffc107; margin-right: 6px;"></i>' +
                            '<strong>Tip:</strong> Select from the available rooms highlighted above or try different dates.' +
                            '</div></div>',
                            'warning'
                        );
                    } else {
                        clearAvailabilityMessage();
                    }

                    validateFormForSubmit();
                })
                .catch(() => {
                    availabilityCheckPending = false;
                    showAvailabilityMessage('<i class="fas fa-exclamation-triangle"></i> Unable to check availability. Please try again.', 'error');
                    validateFormForSubmit();
                });
        }

        function updateRoomAvailabilityCount(roomOption, result, childGuests) {
            const countEl = roomOption.querySelector('.room-availability-count');
            if (!countEl) return;

            if (!result) {
                countEl.textContent = countEl.dataset.defaultText || '';
                return;
            }

            const remaining = childGuests > 0 ?
                Math.max(0, Number(result.child_eligible_remaining_rooms ?? result.remaining_rooms ?? 0)) :
                Math.max(0, Number(result.remaining_rooms || 0));
            const label = childGuests > 0 ? 'child-ready room' : 'room';
            countEl.textContent = `(${remaining} ${label}${remaining === 1 ? '' : 's'} left)`;
        }

        /**
         * Visually mark rooms that do not allow children when the guest has entered
         * children. Adds/removes the `room-option--children-warn` modifier class so
         * CSS can apply a distinct visual treatment without disabling the card.
         */
        function refreshChildFriendlyRoomIndicators(childCount) {
            const roomOptions = document.querySelectorAll('.room-option[data-children-allowed]');
            roomOptions.forEach(option => {
                const allows = option.getAttribute('data-children-allowed') === '1';
                if (childCount > 0 && !allows) {
                    option.classList.add('room-option--children-warn');
                } else {
                    option.classList.remove('room-option--children-warn');
                }
            });
        }

        // Disable a room option with visual feedback (composes with filter tabs)
        function disableRoomOption(roomOption, reason) {
            roomOption.classList.add('room-option-disabled');
            const radio = roomOption.querySelector('input[type="radio"]');
            if (radio) {
                radio.disabled = true;
            }

            // Add unavailable badge
            let badge = roomOption.querySelector('.unavailable-badge');
            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'unavailable-badge';
                roomOption.appendChild(badge);
            }
            badge.innerHTML = '<i class="fas fa-ban"></i> Unavailable';
            badge.title = reason;
        }

        // Enable a room option (composes with filter tabs)
        function enableRoomOption(roomOption) {
            roomOption.classList.remove('room-option-disabled');
            const radio = roomOption.querySelector('input[type="radio"]');
            if (radio) {
                radio.disabled = false;
            }

            // Remove unavailable badge
            const badge = roomOption.querySelector('.unavailable-badge');
            if (badge) {
                badge.remove();
            }

            // Restore opacity - filter controls visibility, this controls availability state
            roomOption.style.opacity = '';
            roomOption.style.pointerEvents = '';
        }

        // Enable all room options (respects current filter state)
        function enableAllRoomOptions() {
            const roomOptions = document.querySelectorAll('.room-option');
            roomOptions.forEach(option => {
                enableRoomOption(option);
                const countEl = option.querySelector('.room-availability-count');
                if (countEl) {
                    countEl.textContent = countEl.dataset.defaultText || '';
                }
            });
        }

        // Clear room selection
        function clearRoomSelection() {
            selectedRoomId = null;
            selectedRoomName = null;
            selectedRoomPrice = null;
            selectedRoomMaxGuests = null;
            currentDynamicPricing = null;
            currentPackages = [];
            selectedPackageIds.clear();
            applyDynamicPricingState(null);
            const pkgSection = document.getElementById('packagesSection');
            if (pkgSection) pkgSection.style.display = 'none';

            const roomRadios = document.querySelectorAll('input[name="room_id"]');
            roomRadios.forEach(radio => {
                radio.checked = false;
            });

            const roomOptions = document.querySelectorAll('.room-option');
            roomOptions.forEach(option => {
                option.classList.remove('selected');
            });

            // Clear summary
            const summary = document.getElementById('bookingSummary');
            if (summary) {
                summary.style.display = 'none';
            }

            // Disable submit button
            const submitBtn = document.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-bed"></i> Select a Room';
                submitBtn.style.opacity = '0.6';
            }
        }

        // Show availability message
        function showAvailabilityMessage(message, type) {
            const el = document.getElementById('roomAvailabilityMessage');
            if (!el) return;

            const icons = {
                error: 'fa-circle-exclamation',
                warning: 'fa-triangle-exclamation',
                success: 'fa-circle-check'
            };
            const t = ['error', 'warning', 'success'].includes(type) ? type : 'success';
            el.innerHTML =
                '<span class="availability-message__icon"><i class="fas ' + icons[t] + '"></i></span>' +
                '<span>' + message + '</span>';
            el.className = 'availability-message availability-message--' + t;
            el.style.display = 'flex';
        }

        // Clear availability message
        function clearAvailabilityMessage() {
            const el = document.getElementById('roomAvailabilityMessage');
            if (el) {
                el.style.display = 'none';
                el.innerHTML = '';
                el.className = 'availability-message';
            }
        }

        // Instant field validation for better UX
        function initInstantValidation() {
            const nameInput = document.getElementById('guest_name');
            const emailInput = document.getElementById('guest_email');
            const phoneInput = document.getElementById('guest_phone');

            // Name validation - at least 2 characters, letters/spaces/hyphens only
            if (nameInput) {
                nameInput.addEventListener('input', function() {
                    validateNameField(this);
                });
                nameInput.addEventListener('blur', function() {
                    validateNameField(this);
                });
            }

            // Email validation
            if (emailInput) {
                emailInput.addEventListener('input', function() {
                    validateEmailField(this);
                });
                emailInput.addEventListener('blur', function() {
                    validateEmailField(this);
                });
            }

            // Phone validation
            if (phoneInput) {
                phoneInput.addEventListener('input', function() {
                    validatePhoneField(this);
                });
                phoneInput.addEventListener('blur', function() {
                    validatePhoneField(this);
                });
            }
        }

        function validateNameField(input) {
            const value = input.value.trim();
            const feedback = getOrCreateFeedback(input, 'name-feedback');

            // Remove previous state
            input.classList.remove('is-valid', 'is-invalid');

            if (value === '') {
                feedback.textContent = '';
                feedback.className = 'field-feedback';
                return false;
            }

            // Check minimum length
            if (value.length < 2) {
                input.classList.add('is-invalid');
                feedback.textContent = 'Name must be at least 2 characters';
                feedback.className = 'field-feedback text-danger';
                return false;
            }

            // Check for valid characters (letters, spaces, hyphens, apostrophes)
            const namePattern = /^[a-zA-Z\s\-'\u00C0-\u017F\u0400-\u04FF]+$/;
            if (!namePattern.test(value)) {
                input.classList.add('is-invalid');
                feedback.textContent = 'Name can only contain letters, spaces, hyphens';
                feedback.className = 'field-feedback text-danger';
                return false;
            }

            // Valid
            input.classList.add('is-valid');
            feedback.textContent = '✓ Looks good';
            feedback.className = 'field-feedback text-success';
            return true;
        }

        function validateEmailField(input) {
            const value = input.value.trim();
            const feedback = getOrCreateFeedback(input, 'email-feedback');

            // Remove previous state
            input.classList.remove('is-valid', 'is-invalid');

            if (value === '') {
                feedback.textContent = '';
                feedback.className = 'field-feedback';
                return false;
            }

            // Comprehensive email regex
            const emailPattern = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;

            if (!emailPattern.test(value)) {
                input.classList.add('is-invalid');
                feedback.textContent = 'Please enter a valid email address';
                feedback.className = 'field-feedback text-danger';
                return false;
            }

            // Check for common typos
            const commonDomains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'aol.com'];
            const domain = value.split('@')[1]?.toLowerCase();
            const suggestions = {
                'gmial.com': 'gmail.com',
                'gmal.com': 'gmail.com',
                'gmal.com': 'gmail.com',
                'gmali.com': 'gmail.com',
                'yaho.com': 'yahoo.com',
                'yahooo.com': 'yahoo.com',
                'hotmal.com': 'hotmail.com',
                'hotmil.com': 'hotmail.com',
                'outlok.com': 'outlook.com',
                'iclod.com': 'icloud.com',
                'icluod.com': 'icloud.com'
            };

            if (suggestions[domain]) {
                input.classList.add('is-invalid');
                feedback.innerHTML = `Did you mean <strong>${value.split('@')[0]}@${suggestions[domain]}</strong>?`;
                feedback.className = 'field-feedback text-warning';
                return false;
            }

            // Valid
            input.classList.add('is-valid');
            feedback.textContent = '✓ Valid email';
            feedback.className = 'field-feedback text-success';
            return true;
        }

        function validatePhoneField(input) {
            const value = input.value.trim();
            const feedback = getOrCreateFeedback(input, 'phone-feedback');

            // Remove previous state
            input.classList.remove('is-valid', 'is-invalid');

            if (value === '') {
                feedback.textContent = '';
                feedback.className = 'field-feedback';
                return false;
            }

            // Remove all non-digit and non-plus characters for validation
            const cleanNumber = value.replace(/[\s\-\(\)\.]/g, '');

            // Check for valid phone format
            // Allows: +265999123456, 265999123456, 0999123456, +1-234-567-8900
            const phonePattern = /^\+?[0-9]{8,15}$/;

            if (!phonePattern.test(cleanNumber)) {
                input.classList.add('is-invalid');
                if (cleanNumber.length < 8) {
                    feedback.textContent = 'Phone number is too short (min 8 digits)';
                } else if (cleanNumber.length > 15) {
                    feedback.textContent = 'Phone number is too long (max 15 digits)';
                } else {
                    feedback.textContent = 'Please enter a valid phone number';
                }
                feedback.className = 'field-feedback text-danger';
                return false;
            }

            // Check for obviously invalid patterns
            if (/^0+$/.test(cleanNumber) || /^1+$/.test(cleanNumber) || /^(\d)\1+$/.test(cleanNumber.replace('+', ''))) {
                input.classList.add('is-invalid');
                feedback.textContent = 'Please enter a real phone number';
                feedback.className = 'field-feedback text-danger';
                return false;
            }

            // Valid - format display
            input.classList.add('is-valid');
            feedback.textContent = '✓ Valid phone number';
            feedback.className = 'field-feedback text-success';
            return true;
        }

        function getOrCreateFeedback(input, id) {
            let feedback = document.getElementById(id);
            if (!feedback) {
                feedback = document.createElement('small');
                feedback.id = id;
                feedback.className = 'field-feedback';
                input.parentNode.appendChild(feedback);
            }
            return feedback;
        }

        // Override validateFormForSubmit to include instant validation and availability check
        const originalValidateFormForSubmit = validateFormForSubmit;
        validateFormForSubmit = function() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const numGuests = document.getElementById('number_of_guests').value;
            const childGuests = parseInt(document.getElementById('child_guests').value || '0', 10);
            const submitBtn = document.querySelector('.btn-submit');

            const totalGuestsInt = parseInt(numGuests || '0', 10);
            const adultsInt = totalGuestsInt - childGuests;
            const childValid = childGuests >= 0 && childGuests < totalGuestsInt;

            // Check availability status for selected room
            let availabilityValid = true;
            let availabilityKnown = true;
            if (selectedRoomId && checkIn && checkOut) {
                const statusKey = buildAvailabilityStatusKey(selectedRoomId, checkIn, checkOut, childGuests, totalGuestsInt);
                const roomStatus = roomAvailabilityStatus[statusKey];
                availabilityKnown = !!roomStatus;
                if (roomStatus && !roomStatus.available) {
                    availabilityValid = false;
                }
            }

            const selectedRoom = getSelectedRoomData();
            const allocationValid = selectedRoom ? hasValidAllocation(totalGuestsInt, selectedRoom, childGuests) : false;

            // Determine button state based on all validations
            let btnText = '<i class="fas fa-calendar-check"></i> Complete All Fields';
            let btnDisabled = true;

            if (availabilityCheckPending || !availabilityKnown) {
                btnText = '<i class="fas fa-spinner fa-spin"></i> Checking Availability';
                btnDisabled = true;
            } else if (!availabilityValid) {
                btnText = '<i class="fas fa-calendar-times"></i> Room Fully Booked - Try Different Dates';
                btnDisabled = true;
            } else if (!allocationValid && selectedRoomId && numGuests) {
                btnText = '<i class="fas fa-exclamation-triangle"></i> Choose a Supported Guest Count';
                btnDisabled = true;
            } else if (selectedRoomId && checkIn && checkOut && numGuests && childValid && adultsInt >= 1 && allocationValid) {
                // All required fields are filled - allow submission
                // Browser validation will handle required contact fields
                btnText = '<i class="fas fa-check-circle"></i> Confirm Booking';
                btnDisabled = false;
            }

            submitBtn.disabled = btnDisabled;
            submitBtn.innerHTML = btnText;
            submitBtn.style.opacity = btnDisabled ? '0.6' : '1';
        };
    </script>

    <?php include 'includes/footer.php'; ?>
</body>

</html>
