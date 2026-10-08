<?php

/**
 * Admin Edit Booking Page
 *
 * What can be edited follows the booking's stage, front-desk style:
 *  - Reservation (pending / tentative / confirmed): guest details, dates, occupancy, guests, the
 *    specific room. Price follows automatically and keeps the guest's BOOKED rate (dates: booked
 *    nightly rate; occupancy / children: booked rate + the rack difference), so discounts and rate
 *    plans are never lost by an edit.
 *  - In-house (checked-in): guest details and a move to another room of the same type. Stay length
 *    goes through Extend stay / Adjust dates; room type through Upgrade / change room type.
 *  - Closed (checked-out / cancelled / no-show / expired): guest-detail corrections only; the stay
 *    and the money are history.
 * The room TYPE is never changed here (that is an upgrade/downgrade with its own accounting), and
 * there is no free "final price" field: price corrections go through a credit note so the books
 * and the audit trail stay consistent.
 */

require_once __DIR__ . '/admin-init.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/alert.php';
require_once __DIR__ . '/../includes/booking-room-change.php';

/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

$booking_id = intval($_GET['id'] ?? 0);
if ($booking_id <= 0) {
    header('Location: bookings.php');
    exit;
}
if (!hasPermission((int)($user['id'] ?? 0), 'edit_booking')) {
    $_SESSION['error_message'] = 'You do not have permission to edit bookings.';
    header('Location: booking-details.php?id=' . $booking_id);
    exit;
}

$message = '';
$error = '';
$latest_booking_note = '';

$booking_select_sql = "
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
    WHERE b.id = ?";

try {
    $stmt = $pdo->prepare($booking_select_sql);
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

if ($booking && !empty($booking['deleted_at'])) {
    $_SESSION['error_message'] = 'This booking has been deleted. Restore it before editing.';
    header('Location: booking-details.php?id=' . $booking_id);
    exit;
}

// Edit mode by stage (see header comment).
$status = (string)($booking['status'] ?? '');
if (in_array($status, ['pending', 'tentative', 'confirmed'], true)) {
    $edit_mode = 'reservation';
} elseif ($status === 'checked-in') {
    $edit_mode = 'inhouse';
} else {
    $edit_mode = 'closed';
}
$can_edit_stay = $edit_mode === 'reservation';
$can_move_room = in_array($edit_mode, ['reservation', 'inhouse'], true);
$can_change_room_type = ($edit_mode === 'reservation' && (string)$booking['check_in_date'] >= date('Y-m-d'))
    || ($edit_mode === 'inhouse' && (string)$booking['check_out_date'] > date('Y-m-d'));

if ($booking) {
    try {
        $note_stmt = $pdo->prepare("SELECT note_text FROM booking_notes WHERE booking_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
        $note_stmt->execute([$booking_id]);
        $latest_booking_note = (string)($note_stmt->fetchColumn() ?: '');
    } catch (PDOException $e) {
        $latest_booking_note = '';
    }
}

// Rooms of this booking's type, with availability for its stay (in-house: from today).
$availableIndividualRooms = [];
if ($booking && $booking['room_id'] && $can_move_room) {
    $checkIn = $edit_mode === 'inhouse' ? max(date('Y-m-d'), (string)$booking['check_in_date']) : $booking['check_in_date'];
    $checkOut = $booking['check_out_date'];

    try {
        $stmt = $pdo->prepare("
            SELECT
                ir.id, ir.room_number, ir.room_name, ir.floor, ir.status, ir.child_price_multiplier,
                ir.single_occupancy_enabled_override, ir.double_occupancy_enabled_override,
                ir.triple_occupancy_enabled_override, ir.children_allowed_override,
                rt.child_price_multiplier AS room_type_child_price_multiplier,
                rt.single_occupancy_enabled, rt.double_occupancy_enabled, rt.triple_occupancy_enabled,
                rt.children_allowed, rt.name as room_type_name
            FROM individual_rooms ir
            JOIN rooms rt ON ir.room_type_id = rt.id
            WHERE ir.is_active = 1
            AND ir.room_type_id = ?
            ORDER BY ir.floor ASC, ir.room_number ASC
        ");
        $stmt->execute([(int)$booking['room_id']]);
        $allIndividualRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allIndividualRooms as $room) {
            $isAvailable = true;
            $reason = '';
            // One rule for the whole system: status, maintenance, blocks, same-day cleaning and
            // overlapping bookings. The booking's own stay is excluded; an in-house guest's own
            // room is not held to the cleaning rule.
            $roomCheck = checkIndividualRoomAvailability((int)$room['id'], $checkIn, $checkOut, (int)$booking_id, $edit_mode === 'inhouse');
            if (empty($roomCheck['available'])) {
                $isAvailable = false;
                if (!empty($roomCheck['conflicts'])) {
                    $reason = 'Booked (' . $roomCheck['conflicts'][0]['booking_reference'] . ')';
                } elseif (!empty($roomCheck['blocked_dates'])) {
                    $reason = 'Blocked';
                } elseif (!empty($roomCheck['maintenance'])) {
                    $reason = 'Maintenance';
                } else {
                    $reason = (string)($roomCheck['error'] ?? ucfirst(str_replace('_', ' ', (string)$room['status'])));
                }
            }
            if ((int)$room['id'] === (int)($booking['individual_room_id'] ?? 0)) {
                $isAvailable = true; // the guest's own room
                $reason = '';
            }
            $room['available'] = $isAvailable;
            $room['unavailable_reason'] = $reason;
            $room['effective_child_price_multiplier'] = isset($room['child_price_multiplier']) && $room['child_price_multiplier'] !== null
                ? (float)$room['child_price_multiplier']
                : (float)($room['room_type_child_price_multiplier'] ?? 50);
            $availableIndividualRooms[] = $room;
        }
    } catch (PDOException $e) {
        $availableIndividualRooms = [];
    }
}

// The booking's room type (catalogue row) for rack-difference pricing and occupancy rules.
$roomTypeRow = [];
if ($booking) {
    $rtStmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $rtStmt->execute([(int)$booking['room_id']]);
    $roomTypeRow = $rtStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Settings
$currency_symbol = getSetting('currency_symbol');
$vatEnabled = rh_vat_enabled();
$vatRate = (float)getSetting('vat_rate', 0);

/**
 * Price the stay for edited dates / occupancy / children, keeping the guest's BOOKED rate:
 * new nightly = booked nightly + (rack for the new occupancy & children - rack for the old ones).
 * Packages are carried unchanged.
 */
function edit_booking_reprice(array $booking, array $roomType, int $nights, string $occupancy, int $children): array
{
    $split = rh_booked_stay_split($booking);
    $oldRack = rh_room_type_rack_night($roomType, $booking);
    $newRack = rh_room_type_rack_night($roomType, array_merge($booking, ['occupancy_type' => $occupancy, 'child_guests' => $children]));
    $newNight = max(0.0, $split['per_night'] + ($newRack - $oldRack));
    $gross = round($newNight * max(1, $nights) + $split['package_gross'], 2);
    $base = rh_room_type_rack_night(array_merge($roomType, ['child_price_multiplier' => 0]), array_merge($booking, ['occupancy_type' => $occupancy, 'child_guests' => 0]));
    $childNightGross = max(0.0, $newRack - $base);
    return [
        'totals' => rh_stay_totals($gross, 'final', rh_booking_has_levy($booking)),
        'gross' => $gross,
        'per_night' => round($newNight, 2),
        'rack_delta_night' => round($newRack - $oldRack, 2),
        'child_supplement' => round($childNightGross * max(1, $nights), 2),
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $booking) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // Guest details: editable in every stage.
        $guest_name = trim($_POST['guest_name'] ?? '');
        $guest_email = trim($_POST['guest_email'] ?? '');
        $guest_phone = trim($_POST['guest_phone'] ?? '');
        $guest_country = trim($_POST['guest_country'] ?? '');
        $special_requests = trim($_POST['special_requests'] ?? '');
        $admin_notes = trim($_POST['booking_notes'] ?? '');

        // Stay fields: only from the form when this stage allows them, otherwise the stored values.
        $check_in = $can_edit_stay ? (string)($_POST['check_in_date'] ?? $booking['check_in_date']) : (string)$booking['check_in_date'];
        $check_out = $can_edit_stay ? (string)($_POST['check_out_date'] ?? $booking['check_out_date']) : (string)$booking['check_out_date'];
        $number_of_guests = $can_edit_stay ? intval($_POST['number_of_guests'] ?? $booking['number_of_guests']) : (int)$booking['number_of_guests'];
        $child_guests = $can_edit_stay ? intval($_POST['child_guests'] ?? ($booking['child_guests'] ?? 0)) : (int)($booking['child_guests'] ?? 0);
        $occupancy_type = $can_edit_stay ? (string)($_POST['occupancy_type'] ?? ($booking['occupancy_type'] ?? 'single')) : (string)($booking['occupancy_type'] ?? 'single');
        $adult_guests = max(1, $number_of_guests - $child_guests);
        $individual_room_id = $can_move_room
            ? (!empty($_POST['individual_room_id']) ? intval($_POST['individual_room_id']) : null)
            : (!empty($booking['individual_room_id']) ? (int)$booking['individual_room_id'] : null);
        if ($edit_mode === 'inhouse' && !$individual_room_id) {
            $individual_room_id = !empty($booking['individual_room_id']) ? (int)$booking['individual_room_id'] : null; // in-house guests always keep a room
        }
        $room_id = (int)$booking['room_id']; // room TYPE changes go through the upgrade / downgrade flow

        if (empty($guest_name) || empty($guest_email)) {
            $error = 'Guest name and email are required.';
        } elseif (!filter_var($guest_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid guest email address.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_in) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_out)) {
            $error = 'Check-in and check-out dates are required.';
        } elseif (strtotime($check_out) <= strtotime($check_in)) {
            $error = 'Check-out date must be after check-in date.';
        } elseif ($child_guests < 0) {
            $error = 'Children count cannot be negative.';
        } elseif ($child_guests >= $number_of_guests) {
            $error = 'At least 1 adult is required for every booking.';
        } elseif ($can_edit_stay && $check_in !== (string)$booking['check_in_date'] && $check_in < date('Y-m-d')) {
            $error = 'The check-in date cannot be moved into the past.';
        } elseif ($can_edit_stay && !in_array($occupancy_type, ['single', 'double', 'triple'], true)) {
            $error = 'Choose a valid occupancy type.';
        } else {
            if ($roomTypeRow && $number_of_guests > (int)$roomTypeRow['max_guests']) {
                $error = 'Number of guests (' . $number_of_guests . ') exceeds the room capacity of ' . $roomTypeRow['max_guests'] . '. Use Upgrade / change room type to move them to a larger room.';
            } elseif ($roomTypeRow) {
                $policy = resolveOccupancyPolicy($roomTypeRow, null);
                if (($occupancy_type === 'single' && empty($policy['single_enabled']))
                    || ($occupancy_type === 'double' && empty($policy['double_enabled']))
                    || ($occupancy_type === 'triple' && empty($policy['triple_enabled']))) {
                    $error = 'Selected occupancy type is disabled for this room type.';
                } elseif (empty($policy['children_allowed']) && $child_guests > 0) {
                    $error = 'Children are not allowed for this room type.';
                }
            }
            if (empty($error) && $individual_room_id && (int)$individual_room_id !== (int)($booking['individual_room_id'] ?? 0)) {
                $irPolicyStmt = $pdo->prepare("SELECT room_type_id, single_occupancy_enabled_override, double_occupancy_enabled_override, triple_occupancy_enabled_override, children_allowed_override FROM individual_rooms WHERE id = ?");
                $irPolicyStmt->execute([$individual_room_id]);
                $irPolicy = $irPolicyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$irPolicy || (int)$irPolicy['room_type_id'] !== $room_id) {
                    $error = 'That room is not a ' . ($booking['room_name'] ?? 'room of this type') . '. Use Upgrade / change room type to move to another type.';
                } else {
                    $effectivePolicy = resolveOccupancyPolicy($roomTypeRow, $irPolicy);
                    if (($occupancy_type === 'single' && empty($effectivePolicy['single_enabled']))
                        || ($occupancy_type === 'double' && empty($effectivePolicy['double_enabled']))
                        || ($occupancy_type === 'triple' && empty($effectivePolicy['triple_enabled']))) {
                        $error = 'Selected occupancy type is disabled for this individual room.';
                    } elseif (empty($effectivePolicy['children_allowed']) && $child_guests > 0) {
                        $error = 'Children are not allowed in this individual room.';
                    }
                }
            }
        }

        if (empty($error)) {
            $oldRoomIds = [];
            try {
                $pdo->beginTransaction();
                $lockSt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
                $lockSt->execute([$booking_id]);
                $locked = $lockSt->fetch(PDO::FETCH_ASSOC);
                if (!$locked || (string)$locked['status'] !== $status || !empty($locked['deleted_at'])) {
                    throw new Exception('The booking changed while you were editing. Reload the page and try again.');
                }

                $number_of_nights = (int)round((strtotime($check_out) - strtotime($check_in)) / 86400);
                $currency_sym = getSetting('currency_symbol');
                $storedGross = rh_booked_stay_split($locked)['total_with_vat'];

                $datesChanged = $check_in !== (string)$locked['check_in_date'] || $check_out !== (string)$locked['check_out_date'];
                $occChanged = $occupancy_type !== (string)($locked['occupancy_type'] ?? 'single') || $child_guests !== (int)($locked['child_guests'] ?? 0);
                $roomPointerChanged = (int)($individual_room_id ?? 0) !== (int)($locked['individual_room_id'] ?? 0);

                if ($datesChanged || $roomPointerChanged) {
                    // Shared stay-change gate: booking / room-type / room locks; only NEW nights are re-checked.
                    $stayGate = rh_validate_booking_stay_change((int)$booking_id, $check_in, $check_out, null, $individual_room_id ? (int)$individual_room_id : null);
                    if (!$stayGate['ok']) {
                        throw new Exception($stayGate['error']);
                    }
                }

                // Changes for the guest email + audit
                $changes = [];
                if ($datesChanged) {
                    if ($check_in !== (string)$locked['check_in_date']) {
                        $changes['check_in_date'] = ['old' => date('M j, Y', strtotime($locked['check_in_date'])), 'new' => date('M j, Y', strtotime($check_in))];
                    }
                    if ($check_out !== (string)$locked['check_out_date']) {
                        $changes['check_out_date'] = ['old' => date('M j, Y', strtotime($locked['check_out_date'])), 'new' => date('M j, Y', strtotime($check_out))];
                    }
                }
                if ($number_of_guests != $locked['number_of_guests']) {
                    $changes['number_of_guests'] = ['old' => $locked['number_of_guests'], 'new' => $number_of_guests];
                }
                if ((int)($locked['child_guests'] ?? 0) !== $child_guests) {
                    $changes['child_guests'] = ['old' => (int)($locked['child_guests'] ?? 0), 'new' => $child_guests];
                }
                if ($occupancy_type !== ($locked['occupancy_type'] ?? 'single')) {
                    $changes['occupancy_type'] = ['old' => ucfirst($locked['occupancy_type'] ?? 'single'), 'new' => ucfirst($occupancy_type)];
                }
                if ($guest_name !== $locked['guest_name']) {
                    $changes['guest_name'] = ['old' => $locked['guest_name'], 'new' => $guest_name];
                }
                if ($guest_email !== $locked['guest_email']) {
                    $changes['guest_email'] = ['old' => $locked['guest_email'], 'new' => $guest_email];
                }
                if ($guest_phone !== ($locked['guest_phone'] ?? '')) {
                    $changes['guest_phone'] = ['old' => $locked['guest_phone'] ?? '', 'new' => $guest_phone];
                }

                $pdo->prepare("UPDATE bookings SET guest_name = ?, guest_email = ?, guest_phone = ?, guest_country = ?, special_requests = ?,
                        check_in_date = ?, check_out_date = ?, number_of_nights = ?, number_of_guests = ?, adult_guests = ?, child_guests = ?,
                        occupancy_type = ?, individual_room_id = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$guest_name, $guest_email, $guest_phone, $guest_country, $special_requests,
                        $check_in, $check_out, $number_of_nights, $number_of_guests, $adult_guests, $child_guests,
                        $occupancy_type, $individual_room_id, $booking_id]);

                // Price follows the stay (never typed in): booked rate kept; occupancy / children add or remove the rack difference.
                $newGross = $storedGross;
                if ($can_edit_stay && ($datesChanged || $occChanged)) {
                    $rp = edit_booking_reprice($locked, $roomTypeRow, $number_of_nights, $occupancy_type, $child_guests);
                    rh_write_booking_stay_totals($pdo, (int)$booking_id, $rp['totals'], null, null, null, $rp['child_supplement']);
                    $newGross = $rp['gross'];
                    if (abs($newGross - $storedGross) > BALANCE_TOLERANCE) {
                        $changes['total_amount'] = ['old' => $currency_sym . ' ' . number_format($storedGross, 2), 'new' => $currency_sym . ' ' . number_format($newGross, 2)];
                    }
                }

                // Room hold table follows the chosen room.
                if ($roomPointerChanged) {
                    $oldRoomIds = getBookingRoomIds((int)$booking_id);
                    if ($individual_room_id) {
                        syncBookingRooms((int)$booking_id, [(int)$individual_room_id], null);
                        $pdo->prepare("UPDATE bookings SET room_combination_id = NULL WHERE id = ?")->execute([$booking_id]);
                    } else {
                        rh_release_booking_room_assignment((int)$booking_id);
                    }
                }

                $adminNoteChanged = $admin_notes !== '' && $admin_notes !== trim($latest_booking_note);
                if ($adminNoteChanged) {
                    $pdo->prepare("INSERT INTO booking_notes (booking_id, note_text, created_by) VALUES (?, ?, ?)")
                        ->execute([$booking_id, $admin_notes, $user['id'] ?? null]);
                }

                if (!recalculateBookingFinancials((int)$booking_id)) {
                    throw new Exception('Could not recalculate booking financials.');
                }
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Cannot save: ' . $e->getMessage();
            }

            if (empty($error)) {
                $actor = $user['full_name'] ?? ($user['username'] ?? 'Admin');
                // In-house room move: old room to housekeeping, new room occupied.
                if ($edit_mode === 'inhouse' && $roomPointerChanged) {
                    foreach ($oldRoomIds as $rid) {
                        if ((int)$rid !== (int)$individual_room_id) {
                            updateIndividualRoomStatus((int)$rid, 'cleaning', 'Guest moved rooms (' . $booking['booking_reference'] . ')', (int)($user['id'] ?? 0) ?: null);
                        }
                    }
                    updateBookingRoomsStatus((int)$booking_id, 'occupied', 'Guest moved in: ' . $booking['booking_reference'], (int)($user['id'] ?? 0) ?: null);
                }

                $auditFields = ['guest_name', 'guest_email', 'guest_phone', 'guest_country', 'special_requests', 'check_in_date', 'check_out_date',
                    'number_of_nights', 'number_of_guests', 'adult_guests', 'child_guests', 'occupancy_type', 'individual_room_id', 'total_with_vat'];
                $after = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
                $after->execute([$booking_id]);
                $afterRow = $after->fetch(PDO::FETCH_ASSOC) ?: [];
                $auditOld = [];
                $auditNew = [];
                foreach ($auditFields as $f) {
                    if ((string)($booking[$f] ?? '') !== (string)($afterRow[$f] ?? '')) {
                        $auditOld[$f] = $booking[$f] ?? null;
                        $auditNew[$f] = $afterRow[$f] ?? null;
                    }
                }
                if ($adminNoteChanged) {
                    $auditOld['admin_note'] = $latest_booking_note;
                    $auditNew['admin_note'] = $admin_notes;
                }
                if (!empty($auditNew)) {
                    logBookingAudit($booking_id, 'modified', $auditOld, $auditNew,
                        'Edited on full booking page by ' . $actor . (isset($changes['total_amount']) ? ' (stay repriced at the booked rate)' : ''),
                        $booking['booking_reference'] ?? null);
                    rh_log_event('edit-booking', 'info', 'Booking edited by admin', [
                        'booking_id' => $booking_id, 'ref' => $booking['booking_reference'] ?? null,
                        'fields' => array_keys($auditNew), 'by' => $user['username'] ?? null,
                    ]);
                }

                $flash = empty($auditNew) ? 'No changes to save.' : 'Booking updated.';
                if (!empty($changes) && ($_POST['notify_guest'] ?? '1') === '1') {
                    $refresh = $pdo->prepare($booking_select_sql);
                    $refresh->execute([$booking_id]);
                    $updated_booking = $refresh->fetch(PDO::FETCH_ASSOC);
                    if ($updated_booking) {
                        require_once __DIR__ . '/../config/email.php';
                        $email_result = sendBookingModifiedEmail($updated_booking, $changes);
                        $flash .= !empty($email_result['success']) ? ' Guest notified by email.' : ' (Guest email could not be sent.)';
                    }
                }
                // Post/Redirect/Get: back on the booking, so Back / refresh never re-submit the edit.
                $_SESSION['success_message'] = $flash;
                header('Location: booking-details.php?id=' . $booking_id);
                exit;
            }
        }
    }
}


if (!$booking) {
    echo '<p>Booking not found.</p>';
    exit;
}

// Live price preview data (same rule as the server: booked rate + rack difference).
$preview_split = rh_booked_stay_split($booking);
$preview_rack = [];
foreach (['single', 'double', 'triple'] as $occ) {
    $base = $roomTypeRow ? rh_room_type_rack_night(array_merge($roomTypeRow, ['child_price_multiplier' => 0]), array_merge($booking, ['occupancy_type' => $occ, 'child_guests' => 0])) : 0.0;
    $withChild = $roomTypeRow ? rh_room_type_rack_night($roomTypeRow, array_merge($booking, ['occupancy_type' => $occ, 'child_guests' => 1])) : 0.0;
    $preview_rack[$occ] = ['base' => $base, 'child' => max(0.0, $withChild - $base)];
}
$preview_data = [
    'bookedNight' => round($preview_split['per_night'], 2),
    'packageGross' => round($preview_split['package_gross'], 2),
    'currentTotal' => round($preview_split['total_with_vat'], 2),
    'occupancy' => (string)($booking['occupancy_type'] ?? 'single'),
    'children' => (int)($booking['child_guests'] ?? 0),
    'rack' => $preview_rack,
];
$back_url = 'booking-details.php?id=' . $booking_id;
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
    <style>
        .eb-mode { display: flex; gap: 10px; align-items: flex-start; border-radius: 10px; padding: 12px 14px; margin-bottom: 18px; font-size: 13px; line-height: 1.5; }
        .eb-mode i { margin-top: 3px; }
        .eb-mode--reservation { background: #eef3ff; color: #1f2d6b; }
        .eb-mode--inhouse { background: #e8f5e9; color: #1b5e20; }
        .eb-mode--closed { background: #f3f3f3; color: #444; }
        .eb-locked { background: #f7f5f1; border-radius: 8px; padding: 10px 12px; font-size: 13px; }
        .eb-locked a { font-weight: 600; }
        .eb-readonly { background: #f5f5f5 !important; color: #555; }
        .eb-price-row { display: flex; flex-wrap: wrap; gap: 18px; margin-top: 6px; }
        .eb-price-row div { display: flex; flex-direction: column; font-size: 12px; color: #666; }
        .eb-price-row strong { font-size: 16px; color: #222; }
    </style>
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-pen-to-square"></i>
                    <?php echo $edit_mode === 'closed' ? 'Correct Guest Details' : 'Edit Booking'; ?> <?php echo htmlspecialchars($booking['booking_reference']); ?>
                </h1>
                <p class="page-subtitle">
                    <?php echo htmlspecialchars($booking['guest_name']); ?> &middot; <?php echo htmlspecialchars($booking['room_name'] ?? ''); ?>
                    &middot; <?php echo date('j M', strtotime($booking['check_in_date'])); ?> &ndash; <?php echo date('j M Y', strtotime($booking['check_out_date'])); ?>
                </p>
                <div class="page-meta">
                    <span class="badge badge-<?php echo htmlspecialchars($booking['status']); ?>">
                        <?php echo htmlspecialchars(ucfirst($booking['status'])); ?>
                    </span>
                </div>
            </div>
            <div class="page-header-actions">
                <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to booking</span>
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <?php if ($edit_mode === 'reservation'): ?>
            <div class="eb-mode eb-mode--reservation"><i class="fas fa-circle-info"></i><span>
                Reservation: guest details, dates, guests and the specific room can be changed. The price follows automatically at the guest's <strong>booked rate</strong> (their discount is kept).
                To move to another room <em>type</em> use <strong>Upgrade / change room type</strong>; to correct a price, issue a <strong>credit note</strong>.
            </span></div>
        <?php elseif ($edit_mode === 'inhouse'): ?>
            <div class="eb-mode eb-mode--inhouse"><i class="fas fa-bed"></i><span>
                Guest is in-house: correct guest details or move them to another room of the same type (the old room goes to housekeeping).
                Use <strong>Extend stay</strong> / <strong>Adjust stay dates</strong> for the length of stay and <strong>Upgrade / change room type</strong> for a different type.
            </span></div>
        <?php else: ?>
            <div class="eb-mode eb-mode--closed"><i class="fas fa-lock"></i><span>
                This booking is <strong><?php echo htmlspecialchars($booking['status']); ?></strong>: the stay and the bill are closed. Only guest contact details can be corrected (for invoices and records).
            </span></div>
        <?php endif; ?>

        <div class="edit-form">
            <form method="POST"
                data-admin-confirm="Save these booking changes?"
                data-admin-confirm-title="Confirm booking update"
                data-admin-confirm-details="Date, occupancy and guest changes reprice the stay at the booked rate.|Every change is written to the booking audit log."
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
                        <input type="text" id="guest_name" name="guest_name" required maxlength="255"
                            value="<?php echo htmlspecialchars($booking['guest_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="guest_email">Email *</label>
                        <input type="email" id="guest_email" name="guest_email" required maxlength="255"
                            value="<?php echo htmlspecialchars($booking['guest_email']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="guest_phone">Phone</label>
                        <input type="text" id="guest_phone" name="guest_phone" maxlength="50"
                            value="<?php echo htmlspecialchars($booking['guest_phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="guest_country">Country</label>
                        <input type="text" id="guest_country" name="guest_country" maxlength="100"
                            value="<?php echo htmlspecialchars($booking['guest_country'] ?? ''); ?>">
                    </div>
                </div>

                <h3 class="form-section-title">
                    <i class="fas fa-bed"></i> Room &amp; Stay
                </h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Room type</label>
                        <input type="text" class="eb-readonly" readonly value="<?php echo htmlspecialchars($booking['room_name'] ?? ''); ?>">
                        <?php if ($can_change_room_type): ?>
                            <small><a href="bookings.php?action=room-change&amp;booking_id=<?php echo $booking_id; ?>"><i class="fas fa-arrow-up-right-dots"></i> Upgrade / change room type</a> (handles the price difference and audit)</small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="occupancy_type">Occupancy</label>
                        <select id="occupancy_type" name="occupancy_type" <?php echo $can_edit_stay ? '' : 'disabled'; ?>>
                            <?php
                            $occPolicy = $roomTypeRow ? resolveOccupancyPolicy($roomTypeRow, null) : ['single_enabled' => 1, 'double_enabled' => 1, 'triple_enabled' => 1];
                            foreach (['single' => 'Single', 'double' => 'Double', 'triple' => 'Triple'] as $occVal => $occLabel):
                                $occOn = !empty($occPolicy[$occVal . '_enabled']) || ($booking['occupancy_type'] ?? '') === $occVal;
                            ?>
                                <option value="<?php echo $occVal; ?>" <?php echo ($booking['occupancy_type'] ?? 'single') === $occVal ? 'selected' : ''; ?> <?php echo $occOn ? '' : 'disabled'; ?>><?php echo $occLabel; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="check_in_date">Check-in Date *</label>
                        <input type="date" id="check_in_date" name="check_in_date" required
                            value="<?php echo htmlspecialchars($booking['check_in_date']); ?>"
                            <?php echo $can_edit_stay ? 'min="' . htmlspecialchars(min(date('Y-m-d'), (string)$booking['check_in_date'])) . '"' : 'disabled'; ?>>
                    </div>
                    <div class="form-group">
                        <label for="check_out_date">Check-out Date *</label>
                        <input type="date" id="check_out_date" name="check_out_date" required
                            value="<?php echo htmlspecialchars($booking['check_out_date']); ?>" <?php echo $can_edit_stay ? '' : 'disabled'; ?>>
                    </div>
                    <div class="form-group">
                        <label for="number_of_guests">Number of Guests</label>
                        <input type="number" id="number_of_guests" name="number_of_guests" min="1"
                            max="<?php echo (int)($booking['max_guests'] ?? 10); ?>"
                            value="<?php echo (int)$booking['number_of_guests']; ?>" <?php echo $can_edit_stay ? '' : 'disabled'; ?>>
                        <small style="color: #888;">Max <?php echo (int)($booking['max_guests'] ?? 0); ?> for this room type</small>
                    </div>
                    <div class="form-group">
                        <label for="child_guests">Children (under 12)</label>
                        <input type="number" id="child_guests" name="child_guests" min="0"
                            max="<?php echo max(0, ((int)$booking['number_of_guests']) - 1); ?>"
                            value="<?php echo (int)($booking['child_guests'] ?? 0); ?>"
                            <?php echo ($can_edit_stay && !empty($occPolicy['children_allowed'])) ? '' : 'disabled'; ?>>
                        <small style="color:#888;">At least 1 adult is required per booking.</small>
                    </div>
                </div>

                <?php if ($edit_mode === 'inhouse'): ?>
                    <div class="eb-locked"><i class="fas fa-calendar-days"></i> Stay length: use <a href="booking-details.php?id=<?php echo $booking_id; ?>">Adjust Stay Dates</a> on the booking, or <a href="bookings.php?search=<?php echo urlencode($booking['booking_reference']); ?>">Extend stay</a> on the list. Both reprice at the booked rate and are audited.</div>
                <?php endif; ?>

                <?php if (!empty($availableIndividualRooms)): ?>
                    <div class="individual-room-section">
                        <h4><i class="fas fa-door-open"></i> <?php echo $edit_mode === 'inhouse' ? 'Move to another room (same type)' : 'Specific room (optional)'; ?></h4>
                        <p style="font-size: 13px; color: #666; margin-bottom: 12px;">
                            <?php echo $edit_mode === 'inhouse'
                                ? 'Choose the room the guest is moving to. Their current room is sent to housekeeping.'
                                : 'Pick a specific room for this booking, or leave it for automatic assignment.'; ?>
                        </p>
                        <input type="hidden" name="individual_room_id" id="individual_room_id" value="<?php echo htmlspecialchars((string)($booking['individual_room_id'] ?? '')); ?>">
                        <div id="roomOptionsContainer">
                            <?php foreach ($availableIndividualRooms as $room): ?>
                                <div class="room-option <?php echo $room['available'] ? '' : 'disabled'; ?> <?php echo ($booking['individual_room_id'] == $room['id']) ? 'selected' : ''; ?>"
                                    data-room-id="<?php echo (int)$room['id']; ?>"
                                    onclick="<?php echo $room['available'] ? 'selectRoom(' . (int)$room['id'] . ')' : ''; ?>">
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
                                                    &bull; <?php echo htmlspecialchars($room['unavailable_reason']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="room-option-status <?php echo $room['available'] ? 'status-available' : 'status-unavailable'; ?>">
                                            <?php echo ($booking['individual_room_id'] == $room['id']) ? 'Current' : ($room['available'] ? 'Available' : 'Unavailable'); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($edit_mode === 'reservation' && !empty($booking['individual_room_id'])): ?>
                            <p style="font-size: 12px; margin-top: 8px;"><a href="#" onclick="selectRoom(''); return false;"><i class="fas fa-xmark"></i> Clear the specific room (assign later)</a></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="price-info" id="priceCalculation">
                    <h4><i class="fas fa-calculator"></i> Price</h4>
                    <div class="eb-price-row">
                        <div><span>Current bill (room)</span><strong><?php echo htmlspecialchars($currency_symbol . ' ' . number_format($preview_data['currentTotal'], 2)); ?></strong></div>
                        <div><span>After this change</span><strong id="ebNewTotal"><?php echo htmlspecialchars($currency_symbol . ' ' . number_format($preview_data['currentTotal'], 2)); ?></strong></div>
                        <div><span>Difference</span><strong id="ebDelta">&ndash;</strong></div>
                    </div>
                    <small id="ebPriceNote" style="color:#777; display:block; margin-top:8px;">
                        Booked rate <?php echo htmlspecialchars($currency_symbol . ' ' . number_format($preview_data['bookedNight'], 2)); ?>/night incl. taxes.
                        The price is not typed in: to give a discount or correct a price, issue a credit note from the booking.
                    </small>
                </div>

                <h3 class="form-section-title">
                    <i class="fas fa-sticky-note"></i> Additional Details
                </h3>
                <div class="form-group form-full">
                    <label for="special_requests">Special Requests</label>
                    <textarea id="special_requests" name="special_requests" maxlength="2000"><?php echo htmlspecialchars($booking['special_requests'] ?? ''); ?></textarea>
                </div>
                <div class="form-group form-full">
                    <label for="booking_notes">Admin Notes (internal)</label>
                    <textarea id="booking_notes" name="booking_notes" maxlength="2000"><?php echo htmlspecialchars($latest_booking_note); ?></textarea>
                </div>
                <div class="form-group form-full">
                    <label style="display:flex; gap:8px; align-items:center;">
                        <input type="checkbox" name="notify_guest" value="1" checked>
                        <span>Email the guest if their dates, guests, room or price change</span>
                    </label>
                    <input type="hidden" name="notify_guest" value="0" disabled id="notifyGuestOff">
                </div>

                <div class="btn-bar">
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn-back">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function() {
            const P = <?php echo json_encode($preview_data); ?>;
            const currency = <?php echo json_encode((string)$currency_symbol); ?>;
            const money = v => currency + ' ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const rack = (occ, children) => {
                const r = P.rack[occ] || P.rack[P.occupancy] || { base: 0, child: 0 };
                return r.base + r.child * Math.max(0, children);
            };
            const $ = id => document.getElementById(id);

            // Same rule as the server: booked nightly rate + (rack for new occupancy/children - rack for old).
            function preview() {
                const ci = $('check_in_date') ? $('check_in_date').value : '';
                const co = $('check_out_date') ? $('check_out_date').value : '';
                const nights = ci && co ? Math.round((new Date(co) - new Date(ci)) / 86400000) : 0;
                if (nights <= 0) {
                    $('ebNewTotal').textContent = '-';
                    $('ebDelta').textContent = 'Check-out must be after check-in';
                    return;
                }
                const occ = $('occupancy_type') ? $('occupancy_type').value : P.occupancy;
                const children = $('child_guests') && !$('child_guests').disabled ? parseInt($('child_guests').value || '0', 10) : P.children;
                const night = Math.max(0, P.bookedNight + rack(occ, children) - rack(P.occupancy, P.children));
                const total = Math.round((night * nights + P.packageGross) * 100) / 100;
                const delta = total - P.currentTotal;
                $('ebNewTotal').textContent = money(total);
                const d = $('ebDelta');
                d.textContent = Math.abs(delta) < 0.01 ? 'No change' : (delta > 0 ? '+' : '') + money(delta);
                d.style.color = delta > 0.01 ? '#b42318' : (delta < -0.01 ? '#1b5e20' : '#222');
            }

            ['check_in_date', 'check_out_date', 'occupancy_type', 'child_guests', 'number_of_guests'].forEach(id => {
                const el = $(id);
                if (el) {
                    el.addEventListener('input', preview);
                    el.addEventListener('change', preview);
                }
            });

            const guests = $('number_of_guests');
            if (guests) {
                guests.addEventListener('input', function() {
                    const childInput = $('child_guests');
                    const maxChildren = Math.max(0, Math.max(1, parseInt(this.value || '1', 10)) - 1);
                    childInput.max = maxChildren;
                    if (parseInt(childInput.value || '0', 10) > maxChildren) childInput.value = maxChildren;
                });
            }

            // Unticking the notify box must still post notify_guest=0.
            const notify = document.querySelector('input[type="checkbox"][name="notify_guest"]');
            if (notify) {
                notify.addEventListener('change', function() {
                    $('notifyGuestOff').disabled = this.checked;
                });
            }

            window.selectRoom = function(roomId) {
                $('individual_room_id').value = roomId;
                document.querySelectorAll('.room-option').forEach(o => o.classList.remove('selected'));
                if (roomId !== '') {
                    const card = document.querySelector('.room-option[data-room-id="' + roomId + '"]');
                    if (card) card.classList.add('selected');
                }
            };

            preview();
        })();
    </script>

    <script src="js/admin-components.js"></script>
    <?php require_once 'includes/admin-footer.php'; ?>
