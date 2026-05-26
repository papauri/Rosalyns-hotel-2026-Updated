<?php
/**
 * Input Validation Library
 * Hotel Website - Comprehensive Input Validation
 * 
 * This library provides reusable validation functions for all forms
 * to ensure data integrity and security.
 */

/**
 * Sanitize a string input to prevent XSS attacks
 * 
 * @param string $input The input to sanitize
 * @param int $max_length Maximum allowed length (0 for no limit)
 * @return string The sanitized string
 */
function sanitizeString($input, $max_length = 0) {
    if ($input === null) {
        return '';
    }
    
    $sanitized = trim($input);
    $sanitized = strip_tags($sanitized);
    $sanitized = htmlspecialchars($sanitized, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    if ($max_length > 0) {
        $sanitized = substr($sanitized, 0, $max_length);
    }
    
    return $sanitized;
}

/**
 * Validate an email address
 * 
 * @param string $email The email to validate
 * @return array ['valid' => bool, 'error' => string|null]
 */
function validateEmail($email) {
    if (empty($email)) {
        return ['valid' => false, 'error' => 'Email address is required'];
    }
    
    $email = trim($email);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'error' => 'Please enter a valid email address'];
    }
    
    // Additional validation for email length
    if (strlen($email) > 254) {
        return ['valid' => false, 'error' => 'Email address is too long'];
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Validate a phone number
 * Supports international formats with optional country code
 * 
 * @param string $phone The phone number to validate
 * @return array ['valid' => bool, 'error' => string|null, 'sanitized' => string]
 */
function validatePhone($phone) {
    if (empty($phone)) {
        return ['valid' => false, 'error' => 'Phone number is required', 'sanitized' => ''];
    }
    
    $phone = trim($phone);
    
    // Remove all non-digit characters except + for country code
    $sanitized = preg_replace('/[^0-9+]/', '', $phone);
    
    // Check minimum length (8 digits minimum for most countries)
    $digits_only = preg_replace('/[^0-9]/', '', $sanitized);
    if (strlen($digits_only) < 8) {
        return ['valid' => false, 'error' => 'Phone number is too short (minimum 8 digits)', 'sanitized' => $sanitized];
    }
    
    // Check maximum length (15 digits maximum for international numbers)
    if (strlen($digits_only) > 15) {
        return ['valid' => false, 'error' => 'Phone number is too long (maximum 15 digits)', 'sanitized' => $sanitized];
    }
    
    return ['valid' => true, 'error' => null, 'sanitized' => $sanitized];
}

/**
 * Validate a date string
 * 
 * @param string $date The date string to validate
 * @param bool $allow_past Whether to allow past dates
 * @param bool $allow_future Whether to allow future dates
 * @param string $format Expected date format (default: Y-m-d)
 * @return array ['valid' => bool, 'error' => string|null, 'date' => DateTime|null]
 */
function validateDate($date, $allow_past = false, $allow_future = true, $format = 'Y-m-d') {
    if (empty($date)) {
        return ['valid' => false, 'error' => 'Date is required', 'date' => null];
    }
    
    $date = trim($date);
    
    // Try to parse the date
    $dateObj = DateTime::createFromFormat($format, $date);
    
    if ($dateObj === false) {
        return ['valid' => false, 'error' => 'Invalid date format', 'date' => null];
    }
    
    // Check for parsing errors
    $errors = DateTime::getLastErrors();
    if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
        return ['valid' => false, 'error' => 'Invalid date', 'date' => null];
    }
    
    // Normalize the date object
    $dateObj->setTime(0, 0, 0);
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    
    // Check if date is in the past
    if (!$allow_past && $dateObj < $today) {
        return ['valid' => false, 'error' => 'Date cannot be in the past', 'date' => $dateObj];
    }
    
    // Check if date is in the future (when not allowed)
    if (!$allow_future && $dateObj > $today) {
        return ['valid' => false, 'error' => 'Date cannot be in the future', 'date' => $dateObj];
    }
    
    return ['valid' => true, 'error' => null, 'date' => $dateObj];
}

/**
 * Validate a time string
 * 
 * @param string $time The time string to validate
 * @param string $format Expected time format (default: H:i)
 * @return array ['valid' => bool, 'error' => string|null, 'time' => string|null]
 */
function validateTime($time, $format = 'H:i') {
    if (empty($time)) {
        return ['valid' => false, 'error' => 'Time is required', 'time' => null];
    }
    
    $time = trim($time);
    
    // Try to parse the time
    $timeObj = DateTime::createFromFormat($format, $time);
    
    if ($timeObj === false) {
        return ['valid' => false, 'error' => 'Invalid time format', 'time' => null];
    }
    
    // Check for parsing errors
    $errors = DateTime::getLastErrors();
    if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
        return ['valid' => false, 'error' => 'Invalid time', 'time' => null];
    }
    
    return ['valid' => true, 'error' => null, 'time' => $time];
}

/**
 * Validate a combined date and time (for bookings, appointments, etc.)
 *
 * @param string $date The date string (Y-m-d format)
 * @param string $time The time string (H:i format)
 * @param bool $allow_past Whether to allow past date/times (default: false)
 * @param int $buffer_minutes Minimum minutes in advance required (default: 0)
 * @return array ['valid' => bool, 'error' => string|null, 'datetime' => DateTime|null]
 */
function validateDateTime($date, $time, $allow_past = false, $buffer_minutes = 0) {
    // First validate the date
    $date_result = validateDate($date, $allow_past, true, 'Y-m-d');
    if (!$date_result['valid']) {
        return ['valid' => false, 'error' => $date_result['error'], 'datetime' => null];
    }
    
    // Then validate the time
    $time_result = validateTime($time, 'H:i');
    if (!$time_result['valid']) {
        return ['valid' => false, 'error' => $time_result['error'], 'datetime' => null];
    }
    
    // Combine date and time into a single DateTime object
    $datetime = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    if ($datetime === false) {
        return ['valid' => false, 'error' => 'Invalid date or time format', 'datetime' => null];
    }
    
    // Get current time
    $now = new DateTime();
    
    // Calculate the minimum allowed datetime (now + buffer)
    $min_allowed = clone $now;
    if ($buffer_minutes > 0) {
        $min_allowed->modify("+{$buffer_minutes} minutes");
    }
    
    // Check if the datetime is in the past (before minimum allowed)
    if (!$allow_past && $datetime < $min_allowed) {
        // Check if it's just a date issue (past date)
        $date_only = DateTime::createFromFormat('Y-m-d', $date);
        $date_only->setTime(0, 0, 0);
        $today = clone $now;
        $today->setTime(0, 0, 0);
        
        if ($date_only < $today) {
            return ['valid' => false, 'error' => 'Date cannot be in the past', 'datetime' => $datetime];
        }
        
        // It's today but time is too soon
        if ($buffer_minutes > 0) {
            $buffer_hours = floor($buffer_minutes / 60);
            $buffer_mins = $buffer_minutes % 60;
            
            if ($buffer_hours > 0 && $buffer_mins > 0) {
                return ['valid' => false, 'error' => "For today, please select a time at least {$buffer_hours} hours and {$buffer_mins} minutes from now", 'datetime' => $datetime];
            } elseif ($buffer_hours > 0) {
                return ['valid' => false, 'error' => "For today, please select a time at least {$buffer_hours} hour(s) from now", 'datetime' => $datetime];
            } else {
                return ['valid' => false, 'error' => "For today, please select a time at least {$buffer_minutes} minutes from now", 'datetime' => $datetime];
            }
        } else {
            return ['valid' => false, 'error' => 'Selected time has already passed. Please choose a future time', 'datetime' => $datetime];
        }
    }
    
    return ['valid' => true, 'error' => null, 'datetime' => $datetime];
}

/**
 * Validate that end time is after start time
 * 
 * @param string $start_time Start time
 * @param string $end_time End time
 * @param string $format Time format (default: H:i)
 * @return array ['valid' => bool, 'error' => string|null]
 */
function validateTimeRange($start_time, $end_time, $format = 'H:i') {
    $start = DateTime::createFromFormat($format, $start_time);
    $end = DateTime::createFromFormat($format, $end_time);
    
    if ($start === false || $end === false) {
        return ['valid' => false, 'error' => 'Invalid time format'];
    }
    
    if ($end <= $start) {
        return ['valid' => false, 'error' => 'End time must be after start time'];
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Validate a numeric input
 * 
 * @param mixed $value The value to validate
 * @param int $min Minimum value (null for no minimum)
 * @param int $max Maximum value (null for no maximum)
 * @param bool $required Whether the field is required
 * @return array ['valid' => bool, 'error' => string|null, 'value' => int|null]
 */
function validateNumber($value, $min = null, $max = null, $required = true) {
    if ($value === '' || $value === null) {
        if ($required) {
            return ['valid' => false, 'error' => 'This field is required', 'value' => null];
        }
        return ['valid' => true, 'error' => null, 'value' => null];
    }
    
    if (!is_numeric($value)) {
        return ['valid' => false, 'error' => 'Please enter a valid number', 'value' => null];
    }
    
    $value = (int)$value;
    
    if ($min !== null && $value < $min) {
        return ['valid' => false, 'error' => "Value must be at least {$min}", 'value' => $value];
    }
    
    if ($max !== null && $value > $max) {
        return ['valid' => false, 'error' => "Value must be at most {$max}", 'value' => $value];
    }
    
    return ['valid' => true, 'error' => null, 'value' => $value];
}

/**
 * Validate a rating (1-5 stars)
 * 
 * @param mixed $rating The rating to validate
 * @param bool $required Whether the field is required
 * @return array ['valid' => bool, 'error' => string|null, 'value' => int|null]
 */
function validateRating($rating, $required = true) {
    return validateNumber($rating, 1, 5, $required);
}

/**
 * Validate a text field
 * 
 * @param string $value The value to validate
 * @param int $min_length Minimum length (0 for no minimum)
 * @param int $max_length Maximum length (0 for no maximum)
 * @param bool $required Whether the field is required
 * @return array ['valid' => bool, 'error' => string|null, 'value' => string|null]
 */
function validateText($value, $min_length = 0, $max_length = 0, $required = true) {
    if ($value === '' || $value === null) {
        if ($required) {
            return ['valid' => false, 'error' => 'This field is required', 'value' => null];
        }
        return ['valid' => true, 'error' => null, 'value' => ''];
    }
    
    $value = trim($value);
    
    if ($min_length > 0 && strlen($value) < $min_length) {
        return ['valid' => false, 'error' => "Must be at least {$min_length} characters", 'value' => $value];
    }
    
    if ($max_length > 0 && strlen($value) > $max_length) {
        return ['valid' => false, 'error' => "Must be at most {$max_length} characters", 'value' => $value];
    }
    
    return ['valid' => true, 'error' => null, 'value' => $value];
}

/**
 * Validate a name field (letters, spaces, hyphens, apostrophes only)
 * 
 * @param string $name The name to validate
 * @param int $min_length Minimum length
 * @param bool $required Whether the field is required
 * @return array ['valid' => bool, 'error' => string|null, 'value' => string|null]
 */
function validateName($name, $min_length = 2, $required = true) {
    if ($name === '' || $name === null) {
        if ($required) {
            return ['valid' => false, 'error' => 'Name is required', 'value' => null];
        }
        return ['valid' => true, 'error' => null, 'value' => ''];
    }
    
    $name = trim($name);
    
    if (strlen($name) < $min_length) {
        return ['valid' => false, 'error' => "Name must be at least {$min_length} characters", 'value' => $name];
    }
    
    // Allow letters, spaces, hyphens, apostrophes, and common name characters
    if (!preg_match("/^[a-zA-ZÀ-ÿ\s\-'\.]+$/u", $name)) {
        return ['valid' => false, 'error' => 'Name contains invalid characters', 'value' => $name];
    }
    
    return ['valid' => true, 'error' => null, 'value' => $name];
}

/**
 * Validate a booking ID exists in the database
 * 
 * @param int $booking_id The booking ID to validate
 * @param int|null $user_id Optional user ID to check ownership
 * @return array ['valid' => bool, 'error' => string|null, 'booking' => array|null]
 */
function validateBookingId($booking_id, $user_id = null) {
    global $pdo;
    
    if (empty($booking_id) || !is_numeric($booking_id)) {
        return ['valid' => false, 'error' => 'Invalid booking ID', 'booking' => null];
    }
    
    $booking_id = (int)$booking_id;
    
    try {
        $sql = "SELECT * FROM bookings WHERE id = ?";
        $params = [$booking_id];
        
        // If user_id is provided, check ownership
        if ($user_id !== null) {
            $sql .= " AND user_id = ?";
            $params[] = $user_id;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            return ['valid' => false, 'error' => 'Booking not found', 'booking' => null];
        }
        
        return ['valid' => true, 'error' => null, 'booking' => $booking];
    } catch (PDOException $e) {
        error_log("Error validating booking ID: " . $e->getMessage());
        return ['valid' => false, 'error' => 'Database error', 'booking' => null];
    }
}

/**
 * Validate a room ID exists and is active
 * 
 * @param int $room_id The room ID to validate
 * @return array ['valid' => bool, 'error' => string|null, 'room' => array|null]
 */
function validateRoomId($room_id) {
    global $pdo;
    
    if (empty($room_id) || !is_numeric($room_id)) {
        return ['valid' => false, 'error' => 'Invalid room ID', 'room' => null];
    }
    
    $room_id = (int)$room_id;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ? AND is_active = 1");
        $stmt->execute([$room_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            return ['valid' => false, 'error' => 'Room not found or inactive', 'room' => null];
        }
        
        return ['valid' => true, 'error' => null, 'room' => $room];
    } catch (PDOException $e) {
        error_log("Error validating room ID: " . $e->getMessage());
        return ['valid' => false, 'error' => 'Database error', 'room' => null];
    }
}

/**
 * Validate a conference room ID exists and is active
 * 
 * @param int $room_id The conference room ID to validate
 * @return array ['valid' => bool, 'error' => string|null, 'room' => array|null]
 */
function validateConferenceRoomId($room_id) {
    global $pdo;
    
    if (empty($room_id) || !is_numeric($room_id)) {
        return ['valid' => false, 'error' => 'Invalid conference room ID', 'room' => null];
    }
    
    $room_id = (int)$room_id;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ? AND is_active = 1");
        $stmt->execute([$room_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            return ['valid' => false, 'error' => 'Conference room not found or inactive', 'room' => null];
        }
        
        return ['valid' => true, 'error' => null, 'room' => $room];
    } catch (PDOException $e) {
        error_log("Error validating conference room ID: " . $e->getMessage());
        return ['valid' => false, 'error' => 'Database error', 'room' => null];
    }
}

/**
 * Validate date range (check-out after check-in)
 * 
 * @param string $check_in Check-in date
 * @param string $check_out Check-out date
 * @param int $max_nights Maximum number of nights allowed (0 for no limit)
 * @return array ['valid' => bool, 'error' => string|null, 'nights' => int|null]
 */
function validateDateRange($check_in, $check_out, $max_nights = 0) {
    $check_in_result = validateDate($check_in, false, true);
    if (!$check_in_result['valid']) {
        return ['valid' => false, 'error' => $check_in_result['error'], 'nights' => null];
    }
    
    $check_out_result = validateDate($check_out, false, true);
    if (!$check_out_result['valid']) {
        return ['valid' => false, 'error' => $check_out_result['error'], 'nights' => null];
    }
    
    $check_in_date = $check_in_result['date'];
    $check_out_date = $check_out_result['date'];
    
    if ($check_out_date <= $check_in_date) {
        return ['valid' => false, 'error' => 'Check-out date must be after check-in date', 'nights' => null];
    }
    
    $interval = $check_in_date->diff($check_out_date);
    $nights = $interval->days;
    
    if ($max_nights > 0 && $nights > $max_nights) {
        return ['valid' => false, 'error' => "Maximum stay duration is {$max_nights} nights", 'nights' => $nights];
    }
    
    return ['valid' => true, 'error' => null, 'nights' => $nights];
}

/**
 * Validate a select option value
 * 
 * @param string $value The value to validate
 * @param array $allowed_values Array of allowed values
 * @param bool $required Whether the field is required
 * @return array ['valid' => bool, 'error' => string|null, 'value' => string|null]
 */
function validateSelectOption($value, $allowed_values, $required = true) {
    if ($value === '' || $value === null) {
        if ($required) {
            return ['valid' => false, 'error' => 'Please select an option', 'value' => null];
        }
        return ['valid' => true, 'error' => null, 'value' => ''];
    }
    
    $value = trim($value);
    
    if (!in_array($value, $allowed_values, true)) {
        return ['valid' => false, 'error' => 'Invalid option selected', 'value' => $value];
    }
    
    return ['valid' => true, 'error' => null, 'value' => $value];
}

/**
 * Sanitize and validate a URL
 * 
 * @param string $url The URL to validate
 * @param bool $required Whether the field is required
 * @return array ['valid' => bool, 'error' => string|null, 'url' => string|null]
 */
function validateUrl($url, $required = false) {
    if ($url === '' || $url === null) {
        if ($required) {
            return ['valid' => false, 'error' => 'URL is required', 'url' => null];
        }
        return ['valid' => true, 'error' => null, 'url' => ''];
    }
    
    $url = trim($url);
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['valid' => false, 'error' => 'Please enter a valid URL', 'url' => $url];
    }
    
    return ['valid' => true, 'error' => null, 'url' => $url];
}

/**
 * Generate a validation error response
 * 
 * @param array $errors Array of field errors ['field' => 'error message']
 * @return array ['valid' => bool, 'errors' => array]
 */
function validationErrorResponse($errors) {
    return [
        'valid' => false,
        'errors' => $errors
    ];
}

/**
 * Generate a validation success response
 * 
 * @param array $data Optional data to include in response
 * @return array ['valid' => bool, 'data' => array]
 */
function validationSuccessResponse($data = []) {
    return [
        'valid' => true,
        'data' => $data
    ];
}
