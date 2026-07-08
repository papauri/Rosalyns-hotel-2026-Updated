<?php

/**
 * Individual Rooms Management - Admin Panel
 * Manage individual rooms (specific rooms like "Executive 101", "VVIP Suite")
 */
require_once 'admin-init.php';
/** @var string $csrf_token */

$message = '';
$error = '';
// Allow other pages (e.g. room-management.php) to deep-link straight into the
// joined-room manager via ?combinations=1.
$autoOpenCombinations = isset($_GET['combinations']) && $_GET['combinations'] === '1';

function saveRoomAmenities(PDO $pdo, int $roomId, string $amenitiesRaw): void
{
    $pdo->prepare("DELETE FROM individual_room_amenities WHERE individual_room_id = ?")->execute([$roomId]);
    $amenities = preg_split('/\s*,\s*/', trim($amenitiesRaw));
    $amenities = array_filter($amenities, fn($a) => $a !== '');
    if (empty($amenities)) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO individual_room_amenities (individual_room_id, amenity_key, amenity_label, display_order) VALUES (?, ?, ?, ?)");
    $order = 0;
    foreach ($amenities as $amenity) {
        $key = strtolower(preg_replace('/[^a-z0-9]+/', '_', trim($amenity)));
        $stmt->execute([$roomId, $key, trim($amenity), $order++]);
    }
}

function saveRoomPhotos(PDO $pdo, int $roomId, string $photosRaw): void
{
    $pdo->prepare("DELETE FROM individual_room_photos WHERE individual_room_id = ?")->execute([$roomId]);
    $photos = preg_split('/[\r\n,]+/', trim($photosRaw));
    $photos = array_filter(array_map('trim', $photos), fn($p) => $p !== '');
    if (empty($photos)) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO individual_room_photos (individual_room_id, image_path, caption, display_order, is_primary, is_active) VALUES (?, ?, ?, ?, ?, 1)");
    $order = 0;
    foreach ($photos as $idx => $photo) {
        $isPrimary = $idx === 0 ? 1 : 0;
        $stmt->execute([$roomId, $photo, null, $order++, $isPrimary]);
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token invalid.']);
            exit;
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_individual_room') {
            $room_type_id = (int)$_POST['room_type_id'];
            $room_number = trim($_POST['room_number']);
            $room_name = trim($_POST['room_name'] ?? '');
            $floor = trim($_POST['floor'] ?? '');
            $status = $_POST['status'] ?? 'available';
            $max_guests_override = ($_POST['max_guests_override'] ?? '') !== '' ? max(1, (int)$_POST['max_guests_override']) : null;
            $child_price_multiplier = ($_POST['child_price_multiplier'] ?? '') !== '' ? (float)$_POST['child_price_multiplier'] : null;
            if ($child_price_multiplier !== null && $child_price_multiplier < 0) {
                $child_price_multiplier = 0.0;
            }
            $single_override = (isset($_POST['single_occupancy_enabled_override']) && $_POST['single_occupancy_enabled_override'] !== '') ? (int)$_POST['single_occupancy_enabled_override'] : null;
            $double_override = (isset($_POST['double_occupancy_enabled_override']) && $_POST['double_occupancy_enabled_override'] !== '') ? (int)$_POST['double_occupancy_enabled_override'] : null;
            $triple_override = (isset($_POST['triple_occupancy_enabled_override']) && $_POST['triple_occupancy_enabled_override'] !== '') ? (int)$_POST['triple_occupancy_enabled_override'] : null;
            $children_override = (isset($_POST['children_allowed_override']) && $_POST['children_allowed_override'] !== '') ? (int)$_POST['children_allowed_override'] : null;
            $notes = trim($_POST['notes'] ?? '');
            $display_order = (int)($_POST['display_order'] ?? 0);
            $amenities_list = trim($_POST['amenities_list'] ?? '');
            $photos_list = trim($_POST['photos_list'] ?? '');

            // Validate
            if (empty($room_type_id) || empty($room_number)) {
                $error = 'Room type and room number are required.';
            } else {
                // Check if room number already exists
                $check = $pdo->prepare("SELECT COUNT(*) FROM individual_rooms WHERE room_number = ?");
                $check->execute([$room_number]);
                if ($check->fetchColumn() > 0) {
                    $error = 'Room number already exists. Please use a unique room number.';
                } else {
                    // Insert new individual room
                    $stmt = $pdo->prepare("
                        INSERT INTO individual_rooms (
                            room_type_id, room_number, room_name, floor, status, max_guests_override, child_price_multiplier,
                            single_occupancy_enabled_override, double_occupancy_enabled_override, triple_occupancy_enabled_override, children_allowed_override,
                            notes, display_order
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $room_type_id,
                        $room_number,
                        $room_name,
                        $floor,
                        $status,
                        $max_guests_override,
                        $child_price_multiplier,
                        $single_override,
                        $double_override,
                        $triple_override,
                        $children_override,
                        $notes,
                        $display_order
                    ]);

                    // Log the creation
                    $room_id = $pdo->lastInsertId();
                    $logStmt = $pdo->prepare("
                        INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, performed_by)
                        VALUES (?, NULL, ?, ?)
                    ");
                    $logStmt->execute([$room_id, $status, $user['id'] ?? null]);

                    saveRoomAmenities($pdo, (int)$room_id, $amenities_list);
                    saveRoomPhotos($pdo, (int)$room_id, $photos_list);

                    $message = 'Individual room added successfully!';
                }
            }
        } elseif ($action === 'update_individual_room') {
            $id = (int)$_POST['id'];
            $room_type_id = (int)$_POST['room_type_id'];
            $room_number = trim($_POST['room_number']);
            $room_name = trim($_POST['room_name'] ?? '');
            $floor = trim($_POST['floor'] ?? '');
            $max_guests_override = ($_POST['max_guests_override'] ?? '') !== '' ? max(1, (int)$_POST['max_guests_override']) : null;
            $child_price_multiplier = ($_POST['child_price_multiplier'] ?? '') !== '' ? (float)$_POST['child_price_multiplier'] : null;
            if ($child_price_multiplier !== null && $child_price_multiplier < 0) {
                $child_price_multiplier = 0.0;
            }
            $single_override = (isset($_POST['single_occupancy_enabled_override']) && $_POST['single_occupancy_enabled_override'] !== '') ? (int)$_POST['single_occupancy_enabled_override'] : null;
            $double_override = (isset($_POST['double_occupancy_enabled_override']) && $_POST['double_occupancy_enabled_override'] !== '') ? (int)$_POST['double_occupancy_enabled_override'] : null;
            $triple_override = (isset($_POST['triple_occupancy_enabled_override']) && $_POST['triple_occupancy_enabled_override'] !== '') ? (int)$_POST['triple_occupancy_enabled_override'] : null;
            $children_override = (isset($_POST['children_allowed_override']) && $_POST['children_allowed_override'] !== '') ? (int)$_POST['children_allowed_override'] : null;
            $notes = trim($_POST['notes'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $display_order = (int)($_POST['display_order'] ?? 0);
            $amenities_list = trim($_POST['amenities_list'] ?? '');
            $photos_list = trim($_POST['photos_list'] ?? '');

            // Validate
            if (empty($room_type_id) || empty($room_number)) {
                $error = 'Room type and room number are required.';
            } else {
                // Check if room number already exists (excluding current room)
                $check = $pdo->prepare("SELECT COUNT(*) FROM individual_rooms WHERE room_number = ? AND id != ?");
                $check->execute([$room_number, $id]);
                if ($check->fetchColumn() > 0) {
                    $error = 'Room number already exists. Please use a unique room number.';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE individual_rooms
                        SET room_type_id = ?, room_number = ?, room_name = ?, floor = ?, max_guests_override = ?, child_price_multiplier = ?,
                            single_occupancy_enabled_override = ?, double_occupancy_enabled_override = ?, triple_occupancy_enabled_override = ?, children_allowed_override = ?,
                            notes = ?, is_active = ?, display_order = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $room_type_id,
                        $room_number,
                        $room_name,
                        $floor,
                        $max_guests_override,
                        $child_price_multiplier,
                        $single_override,
                        $double_override,
                        $triple_override,
                        $children_override,
                        $notes,
                        $is_active,
                        $display_order,
                        $id
                    ]);
                    saveRoomAmenities($pdo, $id, $amenities_list);
                    saveRoomPhotos($pdo, $id, $photos_list);
                    $message = 'Individual room updated successfully!';
                }
            }
        } elseif ($action === 'save_room_combination') {
            $id = (int)($_POST['combination_id'] ?? 0);
            $combined_name = trim($_POST['combined_name'] ?? '');
            $combined_room_type_id = (int)($_POST['combined_room_type_id'] ?? 0);
            $room_a_id = (int)($_POST['room_a_id'] ?? 0);
            $room_b_id = (int)($_POST['room_b_id'] ?? 0);
            $price_override = ($_POST['price_override'] ?? '') !== '' ? max(0, (float)$_POST['price_override']) : null;
            $max_guests_combined = max(1, (int)($_POST['max_guests_combined'] ?? 1));
            $notes = trim($_POST['combination_notes'] ?? '');
            $is_active = isset($_POST['combination_is_active']) ? 1 : 0;

            if ($combined_name === '' || $combined_room_type_id <= 0 || $room_a_id <= 0 || $room_b_id <= 0) {
                $error = 'Combination name, room type, and both rooms are required.';
            } elseif ($room_a_id === $room_b_id) {
                $error = 'A joined-room combination must use two different rooms.';
            } else {
                $roomCheck = $pdo->prepare("SELECT COUNT(*) FROM individual_rooms WHERE id IN (?, ?) AND is_active = 1");
                $roomCheck->execute([$room_a_id, $room_b_id]);
                if ((int)$roomCheck->fetchColumn() !== 2) {
                    $error = 'Both physical rooms must exist and be active.';
                } elseif ($id > 0) {
                    $stmt = $pdo->prepare(" 
                        UPDATE room_combinations
                        SET combined_name = ?, combined_room_type_id = ?, room_a_id = ?, room_b_id = ?, price_override = ?,
                            max_guests_combined = ?, is_active = ?, notes = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$combined_name, $combined_room_type_id, $room_a_id, $room_b_id, $price_override, $max_guests_combined, $is_active, $notes, $id]);
                    $message = 'Joined-room combination updated successfully!';
                    $autoOpenCombinations = true;
                } else {
                    $stmt = $pdo->prepare(" 
                        INSERT INTO room_combinations (combined_name, combined_room_type_id, room_a_id, room_b_id, price_override, max_guests_combined, is_active, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$combined_name, $combined_room_type_id, $room_a_id, $room_b_id, $price_override, $max_guests_combined, $is_active, $notes, $user['id'] ?? null]);
                    $message = 'Joined-room combination added successfully!';
                    $autoOpenCombinations = true;
                }
            }
        } elseif ($action === 'deactivate_room_combination') {
            $id = (int)($_POST['combination_id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE room_combinations SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                $message = 'Joined-room combination deactivated successfully!';
                $autoOpenCombinations = true;
            }
        } elseif ($action === 'update_status') {
            $id = (int)$_POST['id'];
            $new_status = $_POST['new_status'];
            $reason = trim($_POST['reason'] ?? '');

            $validStatuses = ['available', 'occupied', 'maintenance', 'cleaning', 'out_of_order'];
            if (!in_array($new_status, $validStatuses)) {
                $error = 'Invalid status.';
            } else {
                // Get current status
                $currentStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
                $currentStmt->execute([$id]);
                $current = $currentStmt->fetch(PDO::FETCH_ASSOC);

                if ($current) {
                    $old_status = $current['status'];

                    // Update status
                    $stmt = $pdo->prepare("UPDATE individual_rooms SET status = ? WHERE id = ?");
                    $stmt->execute([$new_status, $id]);

                    // Log the change
                    $logStmt = $pdo->prepare("
                        INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $logStmt->execute([$id, $old_status, $new_status, $reason, $user['id'] ?? null]);

                    $message = 'Room status updated successfully!';
                } else {
                    $error = 'Room not found.';
                }
            }
        } elseif ($action === 'delete_individual_room') {
            $id = (int)$_POST['id'];

            // Check for active bookings
            $bookingsCheck = $pdo->prepare("
                SELECT COUNT(*) FROM bookings
                WHERE individual_room_id = ? AND status IN ('pending', 'confirmed', 'checked-in') AND check_out_date >= CURDATE()
            ");
            $bookingsCheck->execute([$id]);
            if ($bookingsCheck->fetchColumn() > 0) {
                $error = 'Cannot delete room with active bookings.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM individual_rooms WHERE id = ?");
                $stmt->execute([$id]);
                $message = 'Individual room deleted successfully!';
            }
        } elseif ($action === 'bulk_status_change') {
            $room_ids = $_POST['room_ids'] ?? [];
            $new_status = $_POST['bulk_status'] ?? '';

            if (empty($room_ids) || empty($new_status)) {
                $error = 'Please select rooms and a status.';
            } else {
                $validStatuses = ['available', 'occupied', 'maintenance', 'cleaning', 'out_of_order'];
                if (!in_array($new_status, $validStatuses)) {
                    $error = 'Invalid status.';
                } else {
                    foreach ($room_ids as $room_id) {
                        $room_id = (int)$room_id;

                        // Get current status
                        $currentStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
                        $currentStmt->execute([$room_id]);
                        $current = $currentStmt->fetch(PDO::FETCH_ASSOC);

                        if ($current) {
                            // Update status
                            $stmt = $pdo->prepare("UPDATE individual_rooms SET status = ? WHERE id = ?");
                            $stmt->execute([$new_status, $room_id]);

                            // Log the change
                            $logStmt = $pdo->prepare("
                                INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by)
                                VALUES (?, ?, ?, 'Bulk status change', ?)
                            ");
                            $logStmt->execute([$room_id, $current['status'], $new_status, $user['id'] ?? null]);
                        }
                    }
                    $message = count($room_ids) . ' rooms updated successfully!';
                }
            }
        } elseif ($action === 'get_assignable_bookings') {
            if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
                throw new Exception('Invalid request');
            }

            $room_type_id = (int)($_POST['room_type_id'] ?? 0);
            $individual_room_id = (int)($_POST['individual_room_id'] ?? 0);

            if ($room_type_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid room type']);
                exit;
            }

            // Fetch bookings that are eligible for assignment
            $stmt = $pdo->prepare("
                SELECT
                    b.id,
                    b.booking_reference,
                    b.guest_name,
                    b.check_in_date,
                    b.check_out_date,
                    b.status,
                    b.individual_room_id,
                    r.name as room_name
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                WHERE b.room_id = ?
                AND b.status IN ('pending', 'confirmed', 'checked-in')
                AND (b.individual_room_id IS NULL OR b.individual_room_id = ?)
                AND b.check_out_date >= CURDATE()
                ORDER BY b.check_in_date ASC
            ");
            $stmt->execute([$room_type_id, $individual_room_id]);
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Normalize for JSON
            $normalized = array_map(function ($booking) {
                return [
                    'id' => (int)$booking['id'],
                    'reference' => $booking['booking_reference'],
                    'guest_name' => $booking['guest_name'],
                    'check_in' => $booking['check_in_date'],
                    'check_out' => $booking['check_out_date'],
                    'status' => $booking['status'],
                    'room_name' => $booking['room_name'],
                    'already_assigned' => !empty($booking['individual_room_id'])
                ];
            }, $bookings);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Bookings loaded',
                'data' => $normalized
            ]);
            exit;
        } elseif ($action === 'get_room_detail') {
            if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
                throw new Exception('Invalid request');
            }

            $room_id = (int)($_POST['room_id'] ?? 0);
            if ($room_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid room ID']);
                exit;
            }

            // Full room info
            $rStmt = $pdo->prepare("
                  SELECT ir.*, r.name AS room_type_name, r.price_per_night,
                      COALESCE(ir.max_guests_override, r.max_guests) AS max_guests,
                       r.single_occupancy_enabled, r.double_occupancy_enabled, r.triple_occupancy_enabled
                FROM individual_rooms ir
                JOIN rooms r ON r.id = ir.room_type_id
                WHERE ir.id = ?
            ");
            $rStmt->execute([$room_id]);
            $roomRow = $rStmt->fetch(PDO::FETCH_ASSOC);
            if (!$roomRow) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Room not found']);
                exit;
            }

            // Active booking with guest contact
            $aStmt = $pdo->prepare("
                SELECT b.*, r.name AS room_type_name,
                       DATEDIFF(b.check_out_date, CURDATE()) AS nights_remaining
                FROM bookings b
                JOIN rooms r ON r.id = b.room_id
                                WHERE (b.individual_room_id = ? OR EXISTS (
                                        SELECT 1 FROM booking_rooms br
                                        WHERE br.booking_id = b.id
                                            AND br.individual_room_id = ?
                                            AND br.released_at IS NULL
                                ))
                  AND b.status IN ('confirmed','checked-in')
                  AND b.check_in_date <= CURDATE()
                  AND b.check_out_date >= CURDATE()
                ORDER BY b.check_in_date ASC
                LIMIT 1
            ");
            $aStmt->execute([$room_id, $room_id]);
            $active = $aStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            // All upcoming bookings
            $uStmt = $pdo->prepare("
                SELECT b.id, b.booking_reference, b.guest_name, b.guest_email, b.guest_phone,
                       b.check_in_date, b.check_out_date, b.status, b.payment_status,
                       b.total_amount, r.name AS room_type_name
                FROM bookings b
                JOIN rooms r ON r.id = b.room_id
                                WHERE (b.individual_room_id = ? OR EXISTS (
                                        SELECT 1 FROM booking_rooms br
                                        WHERE br.booking_id = b.id
                                            AND br.individual_room_id = ?
                                            AND br.released_at IS NULL
                                ))
                  AND b.status IN ('confirmed','checked-in','pending')
                  AND b.check_in_date > CURDATE()
                ORDER BY b.check_in_date ASC
                LIMIT 10
            ");
            $uStmt->execute([$room_id, $room_id]);
            $upcoming = $uStmt->fetchAll(PDO::FETCH_ASSOC);

            // Status / maintenance log — last 8 entries
            $lStmt = $pdo->prepare("
                SELECT ml.*, au.username AS performed_by_name
                FROM room_maintenance_log ml
                LEFT JOIN admin_users au ON au.id = ml.performed_by
                WHERE ml.individual_room_id = ?
                ORDER BY ml.created_at DESC
                LIMIT 8
            ");
            $lStmt->execute([$room_id]);
            $log = $lStmt->fetchAll(PDO::FETCH_ASSOC);

            // Amenities
            $amStmt = $pdo->prepare("
                SELECT amenity_label FROM individual_room_amenities
                WHERE individual_room_id = ?
                ORDER BY display_order ASC
            ");
            $amStmt->execute([$room_id]);
            $amenities = array_column($amStmt->fetchAll(PDO::FETCH_ASSOC), 'amenity_label');

            // Safe currency
            $sym = getSetting('currency_symbol') ?: 'MWK';

            // Normalise active booking for JSON
            $activeNorm = null;
            if ($active) {
                $activeNorm = [
                    'id'              => (int)$active['id'],
                    'reference'       => $active['booking_reference'],
                    'guest_name'      => $active['guest_name'],
                    'guest_email'     => $active['guest_email'] ?? '',
                    'guest_phone'     => $active['guest_phone'] ?? '',
                    'check_in'        => $active['check_in_date'],
                    'check_out'       => $active['check_out_date'],
                    'status'          => $active['status'],
                    'payment_status'  => $active['payment_status'] ?? 'pending',
                    'total_amount'    => $sym . ' ' . number_format((float)($active['total_amount'] ?? 0), 0),
                    'nights_remaining' => max(0, (int)($active['nights_remaining'] ?? 0)),
                    'adults'          => (int)($active['adult_guests'] ?? 1),
                    'children'        => (int)($active['child_guests'] ?? 0),
                    'special_requests' => $active['special_requests'] ?? '',
                ];
            }

            $upcomingNorm = array_map(fn($b) => [
                'id'             => (int)$b['id'],
                'reference'      => $b['booking_reference'],
                'guest_name'     => $b['guest_name'],
                'guest_email'    => $b['guest_email'] ?? '',
                'guest_phone'    => $b['guest_phone'] ?? '',
                'check_in'       => $b['check_in_date'],
                'check_out'      => $b['check_out_date'],
                'status'         => $b['status'],
                'payment_status' => $b['payment_status'] ?? 'pending',
                'total_amount'   => $sym . ' ' . number_format((float)($b['total_amount'] ?? 0), 0),
            ], $upcoming);

            $logNorm = array_map(fn($l) => [
                'from'         => $l['status_from'] ?? '-',
                'to'           => $l['status_to'] ?? '-',
                'reason'       => $l['reason'] ?? '',
                'performed_by' => $l['performed_by_name'] ?? 'System',
                'date'         => $l['created_at'] ?? '',
            ], $log);

            header('Content-Type: application/json');
            echo json_encode([
                'success'   => true,
                'room'      => [
                    'id'          => (int)$roomRow['id'],
                    'room_type_id' => (int)$roomRow['room_type_id'],
                    'number'      => $roomRow['room_number'],
                    'name'        => $roomRow['room_name'] ?? '',
                    'type'        => $roomRow['room_type_name'],
                    'floor'       => $roomRow['floor'] ?? '',
                    'status'      => $roomRow['status'],
                    'notes'       => $roomRow['notes'] ?? '',
                    'price'       => $sym . ' ' . number_format((float)$roomRow['price_per_night'], 0) . '/night',
                    'max_guests'  => (int)$roomRow['max_guests'],
                    'amenities'   => $amenities,
                    'is_active'   => (bool)$roomRow['is_active'],
                ],
                'active'    => $activeNorm,
                'upcoming'  => $upcomingNorm,
                'log'       => $logNorm,
            ]);
            exit;
        } elseif ($action === 'set_children_override') {
            if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
                throw new Exception('Invalid request');
            }

            $id = (int)($_POST['id'] ?? 0);
            $raw = $_POST['children_allowed_override'] ?? 'null';
            $override = ($raw === 'null' || $raw === '') ? null : ((int)$raw === 1 ? 1 : 0);

            if ($id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid room ID']);
                exit;
            }

            $upd = $pdo->prepare("UPDATE individual_rooms SET children_allowed_override = ? WHERE id = ?");
            $upd->execute([$override, $id]);

            // Return the resolved effective value for UI update
            $roomRow = $pdo->prepare("SELECT ir.children_allowed_override, r.children_allowed FROM individual_rooms ir JOIN rooms r ON r.id = ir.room_type_id WHERE ir.id = ?");
            $roomRow->execute([$id]);
            $roomRow = $roomRow->fetch(PDO::FETCH_ASSOC);
            $effective = $roomRow['children_allowed_override'] !== null ? (int)$roomRow['children_allowed_override'] : (int)$roomRow['children_allowed'];
            $isInherited = $roomRow['children_allowed_override'] === null;

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Children policy updated',
                'effective' => $effective,
                'is_inherited' => $isInherited,
                'override_value' => $override,
            ]);
            exit;
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Get filter parameters
$filter_room_type = isset($_GET['room_type']) ? (int)$_GET['room_type'] : null;
$filter_status = $_GET['status'] ?? null;
$filter_floor = $_GET['floor'] ?? null;

// Build query for individual rooms
$whereClauses = ['1=1'];
$params = [];

if ($filter_room_type) {
    $whereClauses[] = 'ir.room_type_id = ?';
    $params[] = $filter_room_type;
}
if ($filter_status) {
    $whereClauses[] = 'ir.status = ?';
    $params[] = $filter_status;
}
if ($filter_floor) {
    $whereClauses[] = 'ir.floor = ?';
    $params[] = $filter_floor;
}

$stmt = $pdo->prepare("
    SELECT
        ir.*,
        r.name as room_type_name,
        r.price_per_night,
        r.max_guests,
        r.single_occupancy_enabled,
        r.double_occupancy_enabled,
        r.triple_occupancy_enabled,
        r.children_allowed,
        r.child_price_multiplier as room_type_child_price_multiplier,
        COALESCE(ir.child_price_multiplier, r.child_price_multiplier) AS effective_child_price_multiplier,
        -- Active booking: guest has checked in (check_in <= today AND check_out >= today)
        active_b.booking_reference as active_booking_ref,
        active_b.id as active_booking_id,
        active_b.guest_name as active_guest,
        active_b.check_in_date as active_checkin,
        active_b.check_out_date as active_checkout,
        active_b.status as active_booking_status,
        -- Reserved booking: future confirmed booking (check_in > today)
        reserved_b.booking_reference as reserved_booking_ref,
        reserved_b.id as reserved_booking_id,
        reserved_b.guest_name as reserved_guest,
        reserved_b.check_in_date as reserved_checkin,
        reserved_b.check_out_date as reserved_checkout,
        reserved_b.status as reserved_booking_status,
        -- Next booking: after active/reserved booking ends
        next_b.booking_reference as next_booking_ref,
        next_b.id as next_booking_id,
        next_b.guest_name as next_guest,
        next_b.check_in_date as next_checkin,
        next_b.check_out_date as next_checkout,
        next_b.status as next_booking_status
    FROM individual_rooms ir
    LEFT JOIN rooms r ON ir.room_type_id = r.id
    -- Active booking: currently occupied (checked in and stay has started)
    LEFT JOIN bookings active_b ON (ir.id = active_b.individual_room_id OR EXISTS (
            SELECT 1 FROM booking_rooms abr
            WHERE abr.booking_id = active_b.id
              AND abr.individual_room_id = ir.id
              AND abr.released_at IS NULL
        ))
        AND active_b.status IN ('confirmed', 'checked-in')
        AND active_b.check_in_date <= CURDATE()
        AND active_b.check_out_date >= CURDATE()
    -- Reserved booking: future confirmed booking (stay hasn't started yet)
    LEFT JOIN bookings reserved_b ON (ir.id = reserved_b.individual_room_id OR EXISTS (
            SELECT 1 FROM booking_rooms rbr
            WHERE rbr.booking_id = reserved_b.id
              AND rbr.individual_room_id = ir.id
              AND rbr.released_at IS NULL
        ))
        AND reserved_b.status IN ('confirmed', 'checked-in')
        AND reserved_b.check_in_date > CURDATE()
        AND reserved_b.check_in_date = (
            SELECT MIN(check_in_date)
            FROM bookings b2
            WHERE (b2.individual_room_id = ir.id OR EXISTS (
                SELECT 1 FROM booking_rooms br2
                WHERE br2.booking_id = b2.id
                  AND br2.individual_room_id = ir.id
                  AND br2.released_at IS NULL
            ))
            AND b2.status IN ('confirmed', 'checked-in')
            AND b2.check_in_date > CURDATE()
        )
    -- Next booking: earliest future booking from today (includes reserved bookings)
    LEFT JOIN bookings next_b ON (ir.id = next_b.individual_room_id OR EXISTS (
            SELECT 1 FROM booking_rooms nbr
            WHERE nbr.booking_id = next_b.id
              AND nbr.individual_room_id = ir.id
              AND nbr.released_at IS NULL
        ))
        AND next_b.status IN ('confirmed', 'checked-in')
        AND next_b.check_in_date > CURDATE()
        AND next_b.check_in_date = (
            SELECT MIN(check_in_date)
            FROM bookings b3
            WHERE (b3.individual_room_id = ir.id OR EXISTS (
                SELECT 1 FROM booking_rooms br3
                WHERE br3.booking_id = b3.id
                  AND br3.individual_room_id = ir.id
                  AND br3.released_at IS NULL
            ))
            AND b3.status IN ('confirmed', 'checked-in')
            AND b3.check_in_date > CURDATE()
        )
    WHERE " . implode(' AND ', $whereClauses) . "
    ORDER BY r.name ASC, ir.floor ASC, ir.room_number ASC
");
$stmt->execute($params);
$individualRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build amenities & photos maps for edit modal
$roomAmenitiesMap = [];
$amenitiesStmt = $pdo->query("SELECT individual_room_id, amenity_label, amenity_key FROM individual_room_amenities ORDER BY display_order ASC, id ASC");
foreach ($amenitiesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $label = $row['amenity_label'] ?: $row['amenity_key'];
    $roomAmenitiesMap[$row['individual_room_id']][] = $label;
}

$roomPhotosMap = [];
$photosStmt = $pdo->query("SELECT individual_room_id, image_path, is_primary FROM individual_room_photos WHERE is_active = 1 ORDER BY is_primary DESC, display_order ASC, id ASC");
foreach ($photosStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $roomPhotosMap[$row['individual_room_id']][] = $row['image_path'];
}

// Get room types for dropdown
$roomTypesStmt = $pdo->query("SELECT id, name FROM rooms WHERE is_active = 1 ORDER BY name");
$roomTypes = $roomTypesStmt->fetchAll(PDO::FETCH_ASSOC);

$combinationRoomOptionsStmt = $pdo->query(" 
    SELECT ir.id, ir.room_number, ir.room_name, ir.room_type_id, r.name AS room_type_name
    FROM individual_rooms ir
    JOIN rooms r ON r.id = ir.room_type_id
    WHERE ir.is_active = 1
    ORDER BY r.name, ir.room_number
");
$combinationRoomOptions = $combinationRoomOptionsStmt->fetchAll(PDO::FETCH_ASSOC);

$roomCombinationsStmt = $pdo->query(" 
    SELECT rc.*, r.name AS combined_room_type_name,
           ra.room_number AS room_a_number, ra.room_name AS room_a_name,
           rb.room_number AS room_b_number, rb.room_name AS room_b_name
    FROM room_combinations rc
    JOIN rooms r ON r.id = rc.combined_room_type_id
    JOIN individual_rooms ra ON ra.id = rc.room_a_id
    JOIN individual_rooms rb ON rb.id = rc.room_b_id
    ORDER BY rc.is_active DESC, r.name, rc.combined_name
");
$roomCombinations = $roomCombinationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique floors for filter
$floorsStmt = $pdo->query("SELECT DISTINCT floor FROM individual_rooms WHERE floor IS NOT NULL AND floor != '' ORDER BY floor");
$floors = $floorsStmt->fetchAll(PDO::FETCH_COLUMN);

// Get status summary
$summaryStmt = $pdo->query("
    SELECT
        status,
        COUNT(*) as count
    FROM individual_rooms
    WHERE is_active = 1
    GROUP BY status
");
$statusSummary = [];
foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $statusSummary[$row['status']] = $row['count'];
}

$currency = htmlspecialchars(getSetting('currency_symbol'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
    <script>
        (function() {
            var _t = '<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>';
            var _f = window.fetch;
            window.fetch = function(u, o) {
                if (o && o.body instanceof FormData && !o.body.has('csrf_token')) o.body.append('csrf_token', _t);
                return _f.apply(this, arguments);
            };
        })();
    </script>
    <title>Individual Rooms Management - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/individual-rooms.css?v=<?php echo @filemtime(__DIR__ . '/css/individual-rooms.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2><i class="fas fa-door-open"></i> Individual Rooms Management</h2>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <button class="btn btn-secondary" type="button" onclick="openCombinationsModal()"
                    data-help="Manage Room Combinations|Pair adjoining rooms into one bookable joined-room unit. Accounting, refunds, invoices, and folios remain under a single booking.">
                    <i class="fas fa-link"></i> Room Combinations
                    <?php if (!empty($roomCombinations)): ?>
                        <span style="background:#8A775F;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;"><?php echo count($roomCombinations); ?></span>
                    <?php endif; ?>
                </button>
                <button class="btn btn-primary" type="button" onclick="openAddModal()"
                    data-help="Add Individual Room|Create a new physical room record — e.g. 'Executive 101' or 'VVIP Suite 3'. Link it to a room type and set per-room overrides for occupancy, children policy, and pricing.">
                    <i class="fas fa-plus"></i> Add Individual Room
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="background: #f8d7da; color: #721c24; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Status Summary — click any card to filter the table -->
        <div class="status-summary">
            <div class="status-card status-available" data-status-filter="available" onclick="filterByStatus('available')"
                data-help="Available — click to filter|Rooms that are clean, empty, and ready for a new guest to check in. Click this card to filter the table below to available rooms only. Click again to clear.">
                <div class="icon"><i class="fas fa-check"></i></div>
                <div>
                    <div class="count"><?php echo $statusSummary['available'] ?? 0; ?></div>
                    <div class="label">Available</div>
                </div>
            </div>
            <div class="status-card status-occupied" data-status-filter="occupied" onclick="filterByStatus('occupied')"
                data-help="Occupied — click to filter|A guest is currently checked in and the stay is active. Click to filter the table to occupied rooms only.">
                <div class="icon"><i class="fas fa-user"></i></div>
                <div>
                    <div class="count"><?php echo $statusSummary['occupied'] ?? 0; ?></div>
                    <div class="label">Occupied</div>
                </div>
            </div>
            <div class="status-card status-cleaning" data-status-filter="cleaning" onclick="filterByStatus('cleaning')"
                data-help="Cleaning — click to filter|Housekeeping is preparing this room. It cannot accept a new guest until the status changes to Available. Click to filter the table.">
                <div class="icon"><i class="fas fa-broom"></i></div>
                <div>
                    <div class="count"><?php echo $statusSummary['cleaning'] ?? 0; ?></div>
                    <div class="label">Cleaning</div>
                </div>
            </div>
            <div class="status-card status-maintenance" data-status-filter="maintenance" onclick="filterByStatus('maintenance')"
                data-help="Maintenance — click to filter|Room is under repair and excluded from booking availability. Click to filter the table to rooms in maintenance.">
                <div class="icon"><i class="fas fa-tools"></i></div>
                <div>
                    <div class="count"><?php echo $statusSummary['maintenance'] ?? 0; ?></div>
                    <div class="label">Maintenance</div>
                </div>
            </div>
            <div class="status-card status-out_of_order" data-status-filter="out_of_order" onclick="filterByStatus('out_of_order')"
                data-help="Out of Order — click to filter|Room is completely unusable and excluded from all availability searches. Use this for rooms with serious issues that can't be fixed quickly. Click to filter the table.">
                <div class="icon"><i class="fas fa-ban"></i></div>
                <div>
                    <div class="count"><?php echo $statusSummary['out_of_order'] ?? 0; ?></div>
                    <div class="label">Out of Order</div>
                </div>
            </div>
        </div>

        <!-- Active filter banner (shown when a stat card filter is active) -->
        <div class="filter-banner" id="filterBanner">
            <i class="fas fa-filter"></i>
            <span id="filterBannerText">Showing filtered rooms</span>
            <button type="button" class="clear-btn" onclick="clearStatusFilter()"><i class="fas fa-times"></i> Show All</button>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <form method="GET" style="display: flex; gap: 16px; flex-wrap: wrap; align-items: center;">
                <select name="room_type" onchange="this.form.submit()">
                    <option value="">All Room Types</option>
                    <?php foreach ($roomTypes as $type): ?>
                        <option value="<?php echo $type['id']; ?>" <?php echo $filter_room_type == $type['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="status" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="available" <?php echo $filter_status === 'available' ? 'selected' : ''; ?>>Available</option>
                    <option value="occupied" <?php echo $filter_status === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                    <option value="cleaning" <?php echo $filter_status === 'cleaning' ? 'selected' : ''; ?>>Cleaning</option>
                    <option value="maintenance" <?php echo $filter_status === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    <option value="out_of_order" <?php echo $filter_status === 'out_of_order' ? 'selected' : ''; ?>>Out of Order</option>
                </select>
                <?php if (!empty($floors)): ?>
                    <select name="floor" onchange="this.form.submit()">
                        <option value="">All Floors</option>
                        <?php foreach ($floors as $floor): ?>
                            <option value="<?php echo htmlspecialchars($floor); ?>" <?php echo $filter_floor === $floor ? 'selected' : ''; ?>>
                                Floor <?php echo htmlspecialchars($floor); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <a href="?" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Clear</a>
            </form>
        </div>

        <!-- Bulk Actions -->
        <div class="bulk-actions hidden" id="bulkActions">
            <span id="selectedCount">0 selected</span>
            <select id="bulkStatus" data-help="Select Status|Choose a new status to apply to all selected rooms at once.">
                <option value="">Change status to...</option>
                <option value="available">Available</option>
                <option value="occupied">Occupied</option>
                <option value="cleaning">Cleaning</option>
                <option value="maintenance">Maintenance</option>
                <option value="out_of_order">Out of Order</option>
            </select>
            <button class="btn btn-primary btn-sm" onclick="applyBulkStatus()"
                data-help="Apply Bulk Status Change|Change the status of all selected rooms at once to the value chosen above. Select rooms using the checkboxes in the table.">
                <i class="fas fa-check"></i> Apply
            </button>
        </div>

        <!-- Rooms Table -->
        <div class="rooms-table">
            <form method="POST" id="bulkForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="bulk_status_change">
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                            <th class="sortable" data-col="1" onclick="sortTable(this)"
                                data-help="Sort by Room|Click to sort rooms by room number or name. Click again to reverse the order.">
                                Room <span class="sort-icon">⇅</span>
                            </th>
                            <th class="sortable" data-col="2" onclick="sortTable(this)"
                                data-help="Sort by Room Type|Click to group rooms by their type (e.g. Executive, VVIP Suite).">
                                Type <span class="sort-icon">⇅</span>
                            </th>
                            <th class="sortable" data-col="3" onclick="sortTable(this)"
                                data-help="Sort by Floor|Click to sort rooms by floor level.">
                                Floor <span class="sort-icon">⇅</span>
                            </th>
                            <th data-help="Capacity|The guest capacity used by booking availability. Override means this room differs from the room type default.">
                                Capacity
                            </th>
                            <th class="sortable" data-col="5" onclick="sortTable(this)"
                                data-help="Sort by Status|Click to group rooms by their current status.">
                                Status <span class="sort-icon">⇅</span>
                            </th>
                            <th style="white-space:nowrap;">
                                Children <i class="fas fa-question-circle field-help-icon" data-help="Children Policy|Whether child guests are permitted in this room.\n\nInherit = follows the room type's default setting (grey pill).\nAllowed = explicitly permitted for this room (green pill).\nBlocked = explicitly not permitted for this room (red pill).\n\nClick the pill to cycle through options."></i>
                            </th>
                            <th data-help="Current Booking|The active booking currently occupying this room — the check-in date has passed and check-out is still in the future.">
                                Current Booking
                            </th>
                            <th class="sortable" data-col="8" onclick="sortTable(this)"
                                data-help="Sort by Next Booking|Click to sort by the date of each room's next upcoming booking.">
                                Next Booking <span class="sort-icon">⇅</span>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="roomsTableBody">
                        <?php if (empty($individualRooms)): ?>
                            <tr>
                                <td colspan="10" style="text-align: center; padding: 40px; color: #666;">
                                    <i class="fas fa-door-open" style="font-size: 48px; margin-bottom: 16px; display: block; color: #ddd;"></i>
                                    No individual rooms found. Click "Add Individual Room" to create one.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($individualRooms as $room): ?>
                                <?php
                                // Compute timeline-aware display status
                                $displayStatus = $room['status'];
                                $statusIcon = 'check';
                                $statusLabel = ucfirst(str_replace('_', ' ', $room['status']));
                                $hasActiveBooking = !empty($room['active_booking_ref']);
                                $hasReservedBooking = !empty($room['reserved_booking_ref']);
                                $hasNextBooking = !empty($room['next_booking_ref']);

                                // ── Assign Booking button logic ───────────────────────────────
                                // Disable if room already has both an active AND a next booking,
                                // or if the room is physically unavailable for assignment.
                                $assignBlockedByStatus = in_array($room['status'], ['maintenance', 'out_of_order', 'cleaning']);
                                $assignFullyScheduled  = $hasActiveBooking && $hasNextBooking;
                                $assignDisabled        = $assignBlockedByStatus || $assignFullyScheduled;

                                if ($assignBlockedByStatus) {
                                    $assignHelp = 'Cannot Assign — Room Unavailable|This room is currently '
                                        . ucfirst(str_replace('_', ' ', $room['status']))
                                        . '. Change the status to Available before assigning a booking.';
                                } elseif ($assignFullyScheduled) {
                                    $assignHelp = 'Room Fully Scheduled|Current booking: '
                                        . htmlspecialchars($room['active_booking_ref'], ENT_QUOTES)
                                        . ' (' . htmlspecialchars($room['active_guest'] ?? '', ENT_QUOTES) . ')'
                                        . ' and next booking: '
                                        . htmlspecialchars($room['next_booking_ref'], ENT_QUOTES)
                                        . ' (' . htmlspecialchars($room['next_guest'] ?? '', ENT_QUOTES) . ')'
                                        . ' are already assigned. No further assignment is needed.';
                                } else {
                                    $assignHelp = 'Assign to Booking|Link this physical room to an unassigned booking of the same room type. The guest will be checked into this specific room number.';
                                }

                                // ── Status button: pass active booking context ────────────────
                                $statusActiveGuest = $hasActiveBooking
                                    ? htmlspecialchars($room['active_guest'] ?? '', ENT_QUOTES, 'UTF-8')
                                    : '';
                                $statusActiveRef   = $hasActiveBooking
                                    ? htmlspecialchars($room['active_booking_ref'] ?? '', ENT_QUOTES, 'UTF-8')
                                    : '';
                                $statusActiveOut   = $hasActiveBooking
                                    ? htmlspecialchars($room['active_checkout'] ?? '', ENT_QUOTES, 'UTF-8')
                                    : '';

                                // Timeline-aware status override
                                if ($hasActiveBooking) {
                                    // Room has an active checked-in booking (stay in progress)
                                    $displayStatus = 'occupied';
                                    $statusIcon = 'user';
                                    $statusLabel = 'Occupied';
                                } elseif ($hasReservedBooking) {
                                    // Room has a future confirmed booking (reserved/upcoming)
                                    // Show as available now with future booking details in booking columns
                                    $displayStatus = 'available';
                                    $statusIcon = 'check';
                                    $statusLabel = 'Available';
                                } else {
                                    // No active or future bookings - use physical status
                                    switch ($room['status']) {
                                        case 'available':
                                            $statusIcon = 'check';
                                            $statusLabel = 'Available';
                                            break;
                                        case 'occupied':
                                            $statusIcon = 'user';
                                            $statusLabel = 'Occupied';
                                            break;
                                        case 'cleaning':
                                            $statusIcon = 'broom';
                                            $statusLabel = 'Cleaning';
                                            break;
                                        case 'maintenance':
                                            $statusIcon = 'tools';
                                            $statusLabel = 'Maintenance';
                                            break;
                                        case 'out_of_order':
                                            $statusIcon = 'ban';
                                            $statusLabel = 'Out of Order';
                                            break;
                                    }
                                }
                                ?>
                                <tr data-room-status="<?php echo $displayStatus; ?>" id="room-<?php echo (int)$room['id']; ?>" data-focus="room-<?php echo (int)$room['id']; ?>">
                                    <td>
                                        <input type="checkbox" name="room_ids[]" value="<?php echo $room['id']; ?>" onchange="updateBulkActions()">
                                    </td>
                                    <td>
                                        <button type="button" class="room-number-btn"
                                            onclick="openRoomDetailModal(<?php echo (int)$room['id']; ?>)">
                                            <?php echo htmlspecialchars($room['room_number']); ?>
                                        </button>
                                        <?php if ($room['room_name']): ?>
                                            <div class="room-name"><?php echo htmlspecialchars($room['room_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($room['room_type_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo $room['floor'] ? htmlspecialchars($room['floor']) : '-'; ?></td>
                                    <td>
                                        <?php echo (int)($room['max_guests_override'] ?? $room['max_guests']); ?> guests
                                        <?php if ($room['max_guests_override'] !== null): ?>
                                            <small style="display:block;color:#8A775F;">Override</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $_statusHelp = [
                                            'available'    => 'Available|This room is clean and empty — ready for a new guest to check in.',
                                            'occupied'     => 'Occupied|A guest is currently checked in and the stay is active.',
                                            'cleaning'     => 'Cleaning|Housekeeping is preparing this room. It cannot accept a new guest until the status changes to Available.',
                                            'maintenance'  => 'Maintenance|Room is under repair and excluded from booking availability until you change the status.',
                                            'out_of_order' => 'Out of Order|Completely unusable — excluded from all availability searches. Use for rooms with serious or long-term issues.',
                                        ];
                                        ?>
                                        <span class="badge badge-<?php echo $displayStatus; ?>"
                                            data-help="<?php echo htmlspecialchars($_statusHelp[$displayStatus] ?? $statusLabel, ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-<?php echo $statusIcon; ?>"></i>
                                            <?php echo $statusLabel; ?>
                                        </span>
                                    </td>
                                    <?php
                                    $chOverride = $room['children_allowed_override'];
                                    $chType = $room['children_allowed'];
                                    $chEffective = $chOverride !== null ? (int)$chOverride : (int)$chType;
                                    $chInherited = $chOverride === null;
                                    ?>
                                    <td style="text-align:center; white-space:nowrap;">
                                        <button
                                            type="button"
                                            class="children-toggle-btn"
                                            data-room-id="<?php echo $room['id']; ?>"
                                            data-override="<?php echo $chOverride !== null ? $chOverride : 'null'; ?>"
                                            data-effective="<?php echo $chEffective; ?>"
                                            onclick="cycleChildrenPolicy(this)"
                                            data-help="<?php
                                                        if ($chInherited) {
                                                            echo 'Inherit from Room Type|No manual override is set — this room follows the children policy of its room type. Click to set an explicit override just for this room.';
                                                        } elseif ($chEffective) {
                                                            echo 'Children Allowed (Override)|Children are explicitly permitted in this room, overriding the room type default. Click to change to Blocked, or click again to remove the override and inherit.';
                                                        } else {
                                                            echo 'Children Blocked (Override)|Children are explicitly not permitted in this room, overriding the room type default. Click to remove the override and inherit from the room type.';
                                                        }
                                                        ?>"
                                            style="
                                                border: none; cursor: pointer; border-radius: 20px; padding: 4px 10px;
                                                font-size: 12px; font-family: inherit; display: inline-flex; align-items: center; gap: 5px;
                                                <?php if ($chInherited): ?>
                                                    background: #e9ecef; color: #495057;
                                                <?php elseif ($chEffective): ?>
                                                    background: #d4edda; color: #155724;
                                                <?php else: ?>
                                                    background: #f8d7da; color: #721c24;
                                                <?php endif; ?>
                                            ">
                                            <?php if ($chInherited): ?>
                                                <i class="fas fa-arrows-rotate"></i> Inherit
                                            <?php elseif ($chEffective): ?>
                                                <i class="fas fa-child"></i> Allowed
                                            <?php else: ?>
                                                <i class="fas fa-ban"></i> Blocked
                                            <?php endif; ?>
                                        </button>
                                    </td>
                                    <td>
                                        <?php if ($hasActiveBooking): ?>
                                            <div class="current-booking">
                                                <a href="booking-details.php?id=<?php echo $room['active_booking_id']; ?>">
                                                    <?php echo htmlspecialchars($room['active_booking_ref']); ?>
                                                </a>
                                                <br>
                                                <small><?php echo htmlspecialchars($room['active_guest']); ?></small>
                                                <br>
                                                <small><?php echo $room['active_checkin']; ?> &rarr; <?php echo $room['active_checkout']; ?></small>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($room['next_booking_ref']): ?>
                                            <div class="next-booking">
                                                <a href="booking-details.php?id=<?php echo $room['next_booking_id']; ?>">
                                                    <?php echo htmlspecialchars($room['next_booking_ref']); ?>
                                                </a>
                                                <br>
                                                <small><?php echo htmlspecialchars($room['next_guest']); ?></small>
                                                <br>
                                                <small><?php echo $room['next_checkin']; ?> &rarr; <?php echo $room['next_checkout']; ?></small>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="actions-cell">
                                            <button class="btn btn-secondary btn-sm" type="button"
                                                onclick="openRoomDetailModal(<?php echo (int)$room['id']; ?>)"
                                                data-help="Room Details|View full occupancy details — who is staying, for how long, upcoming bookings, and status history for this room.">
                                                <i class="fas fa-eye"></i> Details
                                            </button>
                                            <button class="btn btn-info btn-sm" type="button"
                                                onclick='openEditModal(<?php echo htmlspecialchars(json_encode($room), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($roomAmenitiesMap[$room['id']] ?? []), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($roomPhotosMap[$room['id']] ?? []), ENT_QUOTES, "UTF-8"); ?>)'
                                                data-help="Edit Room|Update this room's details: name, floor, occupancy overrides, child pricing, amenities and photos.">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-primary btn-sm" type="button"
                                                <?php if ($assignDisabled): ?>
                                                disabled
                                                style="opacity:0.45;cursor:not-allowed;"
                                                <?php else: ?>
                                                onclick="openAssignBookingModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['room_number']); ?>', <?php echo $room['room_type_id']; ?>)"
                                                <?php endif; ?>
                                                data-help="<?php echo $assignHelp; ?>">
                                                <i class="fas fa-door-open"></i> Assign
                                            </button>
                                            <button class="btn btn-success btn-sm" type="button"
                                                onclick="openStatusModal(<?php echo $room['id']; ?>, '<?php echo $room['status']; ?>', '<?php echo htmlspecialchars($room['room_number']); ?>', '<?php echo $statusActiveGuest; ?>', '<?php echo $statusActiveRef; ?>', '<?php echo $statusActiveOut; ?>')"
                                                data-help="Change Status|Manually update this room's physical status — e.g. set to Cleaning after a checkout, or Maintenance when repairs are needed.">
                                                <i class="fas fa-exchange-alt"></i> Status
                                            </button>
                                            <button class="btn btn-danger btn-sm" type="button"
                                                <?php if ($hasActiveBooking): ?>
                                                disabled
                                                style="opacity:0.45;cursor:not-allowed;"
                                                data-help="Cannot Delete|This room has an active booking in progress. Check out the guest before deleting this room."
                                                <?php else: ?>
                                                onclick="confirmDelete(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['room_number']); ?>')"
                                                data-help="Delete Room|Permanently remove this room record. Rooms with active or upcoming bookings cannot be deleted."
                                                <?php endif; ?>>
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>

    <!-- ══ JOINED ROOM COMBINATIONS MODAL ══ -->
    <div class="modal-overlay" id="combinationsModal" style="display:none;align-items:flex-start;padding-top:40px;" onclick="if(event.target===this)closeCombinationsModal()">
        <div class="modal-content" style="max-width:880px;width:100%;max-height:90vh;overflow-y:auto;padding:0;">
            <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;padding:20px 24px 16px;border-bottom:1px solid #e8e0d8;">
                <div>
                    <h3 style="margin:0 0 4px;font-size:20px;font-family:'Cormorant Garamond',serif;font-weight:600;"><i class="fas fa-link" style="color:#8A775F;margin-right:8px;"></i> Joined Room Combinations</h3>
                    <p style="margin:0;color:#6b625a;font-size:13px;">Pair adjoining rooms into one bookable unit. Accounting, refunds, invoices, and folios stay under one booking.</p>
                </div>
                <button class="modal-close" onclick="closeCombinationsModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#6b625a;line-height:1;">&times;</button>
            </div>
            <div style="padding:20px 24px;">
                <div style="background:#f8f6f3;border:1px solid #e8e0d8;border-radius:8px;padding:18px 20px 14px;margin-bottom:24px;">
                    <h4 style="margin:0 0 16px;font-size:14px;font-weight:600;color:#2A2723;display:flex;align-items:center;gap:8px;" id="combinationFormTitle">
                        <i class="fas fa-plus-circle" style="color:#8A775F;"></i> Add / Edit Combination
                    </h4>
                    <form method="POST" id="combinationForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                        <input type="hidden" name="action" value="save_room_combination">
                        <input type="hidden" name="combination_id" id="combination_id" value="">

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 16px;margin-bottom:12px;">
                            <div>
                                <label for="combined_name" style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#6b625a;margin-bottom:5px;">Combination Name *</label>
                                <input type="text" name="combined_name" id="combined_name" placeholder="Family Suite 101A + 101B" required style="width:100%;box-sizing:border-box;">
                            </div>
                            <div>
                                <label for="combined_room_type_id" style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#6b625a;margin-bottom:5px;">Book As Room Type *</label>
                                <select name="combined_room_type_id" id="combined_room_type_id" required style="width:100%;box-sizing:border-box;">
                                    <option value="">Select Room Type</option>
                                    <?php foreach ($roomTypes as $type): ?>
                                        <option value="<?php echo (int)$type['id']; ?>"><?php echo htmlspecialchars($type['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="room_a_id" style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#6b625a;margin-bottom:5px;">First Room *</label>
                                <select name="room_a_id" id="room_a_id" required style="width:100%;box-sizing:border-box;">
                                    <option value="">Select Room</option>
                                    <?php foreach ($combinationRoomOptions as $roomOption): ?>
                                        <option value="<?php echo (int)$roomOption['id']; ?>"><?php echo htmlspecialchars($roomOption['room_type_name'] . ' — ' . $roomOption['room_number'] . (!empty($roomOption['room_name']) ? ' (' . $roomOption['room_name'] . ')' : '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="room_b_id" style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#6b625a;margin-bottom:5px;">Second Room *</label>
                                <select name="room_b_id" id="room_b_id" required style="width:100%;box-sizing:border-box;">
                                    <option value="">Select Room</option>
                                    <?php foreach ($combinationRoomOptions as $roomOption): ?>
                                        <option value="<?php echo (int)$roomOption['id']; ?>"><?php echo htmlspecialchars($roomOption['room_type_name'] . ' — ' . $roomOption['room_number'] . (!empty($roomOption['room_name']) ? ' (' . $roomOption['room_name'] . ')' : '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px 16px;align-items:end;margin-bottom:16px;">
                            <div>
                                <label for="price_override" style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#6b625a;margin-bottom:5px;">Rate Override <span style="text-transform:none;font-weight:400;">(optional)</span></label>
                                <input type="number" name="price_override" id="price_override" step="0.01" min="0" placeholder="Room type rate" style="width:100%;box-sizing:border-box;">
                            </div>
                            <div>
                                <label for="max_guests_combined" style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#6b625a;margin-bottom:5px;">Max Guests *</label>
                                <input type="number" name="max_guests_combined" id="max_guests_combined" min="1" value="4" required style="width:100%;box-sizing:border-box;">
                            </div>
                            <div>
                                <label for="combination_notes" style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#6b625a;margin-bottom:5px;">Notes</label>
                                <input type="text" name="combination_notes" id="combination_notes" placeholder="e.g. Adjoining door" style="width:100%;box-sizing:border-box;">
                            </div>
                            <div style="padding-bottom:1px;">
                                <label style="display:flex;align-items:center;gap:7px;cursor:pointer;white-space:nowrap;font-size:13px;font-weight:600;color:#2A2723;">
                                    <input type="checkbox" name="combination_is_active" id="combination_is_active" checked>
                                    Active
                                </label>
                            </div>
                        </div>

                        <div style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-link"></i> Save Combination</button>
                            <button type="button" class="btn btn-secondary" onclick="resetCombinationForm()"><i class="fas fa-rotate-left"></i> Clear</button>
                        </div>
                    </form>
                </div>

                <h4 style="margin:0 0 12px;font-size:14px;font-weight:600;color:#2A2723;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-list" style="color:#8A775F;"></i> Existing Combinations
                </h4>
                <div style="overflow-x:auto;border:1px solid #e8e0d8;border-radius:8px;overflow:hidden;">
                    <table class="table" style="margin:0;">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Book As</th>
                                <th>Rooms</th>
                                <th style="text-align:center;">Capacity</th>
                                <th>Rate Override</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($roomCombinations)): ?>
                                <tr><td colspan="7" style="text-align:center;padding:32px;color:#9b8f85;font-style:italic;">No joined-room combinations configured yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($roomCombinations as $combination): ?>
                                    <tr>
                                        <td style="font-weight:600;color:#2A2723;"><?php echo htmlspecialchars($combination['combined_name']); ?></td>
                                        <td style="color:#5E554D;"><?php echo htmlspecialchars($combination['combined_room_type_name']); ?></td>
                                        <td>
                                            <span style="background:#e8f4fd;color:#0369a1;border-radius:4px;padding:2px 8px;font-size:12px;font-weight:600;font-family:monospace;">
                                                <?php echo htmlspecialchars($combination['room_a_number']); ?>
                                            </span>
                                            <span style="color:#9b8f85;margin:0 4px;">+</span>
                                            <span style="background:#e8f4fd;color:#0369a1;border-radius:4px;padding:2px 8px;font-size:12px;font-weight:600;font-family:monospace;">
                                                <?php echo htmlspecialchars($combination['room_b_number']); ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <span style="background:#f3ece4;color:#6b4c2a;border-radius:4px;padding:2px 8px;font-size:12px;font-weight:600;">
                                                <i class="fas fa-user" style="font-size:10px;"></i> <?php echo (int)$combination['max_guests_combined']; ?>
                                            </span>
                                        </td>
                                        <td style="font-weight:500;">
                                            <?php if ($combination['price_override'] !== null): ?>
                                                <span style="color:#1a6b35;font-weight:600;"><?php echo htmlspecialchars(getSetting('currency_symbol', 'MWK') . ' ' . number_format((float)$combination['price_override'], 0)); ?></span>
                                            <?php else: ?>
                                                <span style="color:#9b8f85;font-style:italic;">Room type rate</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if (!empty($combination['is_active'])): ?>
                                                <span style="background:#d1fae5;color:#065f46;border-radius:12px;padding:3px 10px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Active</span>
                                            <?php else: ?>
                                                <span style="background:#f3f4f6;color:#9b8f85;border-radius:12px;padding:3px 10px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;white-space:nowrap;">
                                            <button type="button" class="btn btn-info btn-sm" onclick='openCombinationForm(<?php echo htmlspecialchars(json_encode($combination), ENT_QUOTES, "UTF-8"); ?>)'>
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <?php if (!empty($combination['is_active'])): ?>
                                                <form method="POST" style="display:inline;margin-left:4px;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                                    <input type="hidden" name="action" value="deactivate_room_combination">
                                                    <input type="hidden" name="combination_id" value="<?php echo (int)$combination['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-ban"></i> Deactivate</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ ROOM DETAIL MODAL ══ -->
    <div class="modal-overlay" id="roomDetailModal">
        <div class="modal-content room-detail-modal-content">
            <div class="modal-header room-detail-header" id="rdHeader">
                <div>
                    <p class="room-detail-label">Room Details</p>
                    <h3 id="rdTitle">—</h3>
                    <p id="rdSubtitle" class="room-detail-subtitle">—</p>
                </div>
                <div class="room-detail-header-meta">
                    <span id="rdStatusBadge" class="badge"></span>
                    <span id="rdPrice" class="room-detail-price"></span>
                </div>
                <button class="modal-close" onclick="closeRoomDetailModal()" style="position:absolute;top:14px;right:16px;">&times;</button>
            </div>

            <div class="modal-body room-detail-body" id="rdBody">
                <div id="rdSpinner" style="text-align:center;padding:60px 0;">
                    <i class="fas fa-spinner fa-spin" style="font-size:2rem;color:#8A775F;"></i>
                </div>
                <div id="rdContent" style="display:none;">

                    <!-- Occupancy Section -->
                    <div class="rd-section rd-occupancy" id="rdOccupancySection">
                        <div class="rd-section-title">
                            <i class="fas fa-user-check"></i> Current Occupancy
                        </div>
                        <div id="rdOccupancyBody"></div>
                    </div>

                    <!-- Upcoming Bookings -->
                    <div class="rd-section" id="rdUpcomingSection" style="display:none;">
                        <div class="rd-section-title">
                            <i class="fas fa-calendar-alt"></i> Upcoming Bookings
                        </div>
                        <div id="rdUpcomingBody"></div>
                    </div>

                    <!-- Room Info -->
                    <div class="rd-section">
                        <div class="rd-section-title">
                            <i class="fas fa-info-circle"></i> Room Information
                        </div>
                        <div id="rdInfoBody"></div>
                    </div>

                    <!-- Status Log -->
                    <div class="rd-section" id="rdLogSection" style="display:none;">
                        <div class="rd-section-title">
                            <i class="fas fa-history"></i> Status History
                        </div>
                        <div id="rdLogBody"></div>
                    </div>

                </div>
            </div>

            <div class="modal-footer" id="rdFooter" style="display:flex;gap:8px;flex-wrap:wrap;padding:14px 20px;border-top:1px solid #e5e7eb;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeRoomDetailModal()">Close</button>
                <button type="button" class="btn btn-success btn-sm" id="rdStatusBtn" onclick="_rdChangeStatus()">
                    <i class="fas fa-exchange-alt"></i> Change Status
                </button>
                <button type="button" class="btn btn-info btn-sm" id="rdEditBtn" onclick="_rdEdit()">
                    <i class="fas fa-edit"></i> Edit Room
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="rdAssignBtn" onclick="_rdAssign()" style="display:none;">
                    <i class="fas fa-door-open"></i> Assign Booking
                </button>
                <a type="button" class="btn btn-primary btn-sm" id="rdBookingLink" href="#" target="_blank" style="display:none;">
                    <i class="fas fa-receipt"></i> View Booking
                </a>
                <a type="button" class="btn btn-secondary btn-sm" id="rdCalLink" href="calendar.php" target="_blank">
                    <i class="fas fa-calendar"></i> View in Calendar
                </a>
            </div>
        </div>
    </div>
    <!-- ══ / ROOM DETAIL MODAL ══ -->

    <!-- Add/Edit Modal -->
    <div class="modal-overlay" id="roomModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Add Individual Room</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="roomForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" name="action" id="formAction" value="add_individual_room">
                <input type="hidden" name="id" id="roomId">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="room_type_id">Room Type *</label>
                            <select name="room_type_id" id="room_type_id" required>
                                <option value="">Select Room Type</option>
                                <?php foreach ($roomTypes as $type): ?>
                                    <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="room_number">Room Number *
                                <i class="fas fa-question-circle field-help-icon" data-help="Room Number|A unique identifier for this physical room — e.g. 'EXEC-101' or 'VIP-201'. This appears in bookings, receipts, and housekeeping logs."></i>
                            </label>
                            <input type="text" name="room_number" id="room_number" placeholder="e.g., EXEC-101" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="room_name">Room Name
                                <i class="fas fa-question-circle field-help-icon" data-help="Room Name|An optional friendly name for this room — e.g. 'Garden Suite' or 'Lakeside Deluxe'. Shown to guests alongside the room number."></i>
                            </label>
                            <input type="text" name="room_name" id="room_name" placeholder="e.g., Executive Room 1">
                        </div>
                        <div class="form-group">
                            <label for="floor">Floor
                                <i class="fas fa-question-circle field-help-icon" data-help="Floor|The floor this room is on — e.g. '1', '2', 'Ground', 'Penthouse'. Used for housekeeping routing and room filtering."></i>
                            </label>
                            <input type="text" name="floor" id="floor" placeholder="e.g., 1">
                        </div>
                        <div class="form-group">
                            <label for="max_guests_override">Guest Capacity Override
                                <i class="fas fa-question-circle field-help-icon" data-help="Guest Capacity Override|Leave blank to inherit the room type capacity. Enter a number when this physical room can safely host a different guest count."></i>
                            </label>
                            <input type="number" name="max_guests_override" id="max_guests_override" min="1" placeholder="Inherit room type">
                        </div>
                    </div>
                    <div class="form-row" id="statusRow" style="display: none;">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status">
                                <option value="available">Available</option>
                                <option value="occupied">Occupied</option>
                                <option value="cleaning">Cleaning</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="out_of_order">Out of Order</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="display_order">Display Order
                                <i class="fas fa-question-circle field-help-icon" data-help="Display Order|Controls the sort position of this room in listings. Lower numbers appear first. Rooms with the same number are sorted alphabetically."></i>
                            </label>
                            <input type="number" name="display_order" id="display_order" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label for="child_price_multiplier">Child Supplement Override (%)
                                <i class="fas fa-question-circle field-help-icon" data-help="Child Supplement Override|A flat price added per child per night — e.g. enter 1500 for MWK 1,500/night per child. Leave blank to use the room type's default supplement from Room Management."></i>
                            </label>
                            <input type="number" name="child_price_multiplier" id="child_price_multiplier" step="0.01" min="0" placeholder="Leave blank to use room type default">
                            <div class="field-hint">If blank, booking uses room type child supplement from room-management.</div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="single_occupancy_enabled_override">Single Occupancy Override
                                <i class="fas fa-question-circle field-help-icon" data-help="Single Occupancy Override|Leave blank (Inherit) to use the room type's setting. Select Enabled or Disabled to force a different value for this specific room only."></i>
                            </label>
                            <select name="single_occupancy_enabled_override" id="single_occupancy_enabled_override">
                                <option value="">Inherit room type</option>
                                <option value="1">Enabled</option>
                                <option value="0">Disabled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="double_occupancy_enabled_override">Double Occupancy Override
                                <i class="fas fa-question-circle field-help-icon" data-help="Double Occupancy Override|Leave blank (Inherit) to use the room type's setting. Select Enabled or Disabled to force a different value for this specific room only."></i>
                            </label>
                            <select name="double_occupancy_enabled_override" id="double_occupancy_enabled_override">
                                <option value="">Inherit room type</option>
                                <option value="1">Enabled</option>
                                <option value="0">Disabled</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="triple_occupancy_enabled_override">Triple Occupancy Override
                                <i class="fas fa-question-circle field-help-icon" data-help="Triple Occupancy Override|Leave blank (Inherit) to use the room type's setting. Select Enabled or Disabled to force a different value for this specific room only."></i>
                            </label>
                            <select name="triple_occupancy_enabled_override" id="triple_occupancy_enabled_override">
                                <option value="">Inherit room type</option>
                                <option value="1">Enabled</option>
                                <option value="0">Disabled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="children_allowed_override">Children Allowed Override
                                <i class="fas fa-question-circle field-help-icon" data-help="Children Allowed Override|Leave blank (Inherit) to follow the room type's policy. Select Allowed or Not Allowed to set an explicit rule just for this room — this will override whatever the room type says."></i>
                            </label>
                            <select name="children_allowed_override" id="children_allowed_override">
                                <option value="">Inherit room type</option>
                                <option value="1">Allowed</option>
                                <option value="0">Not allowed</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea name="notes" id="notes" rows="3" placeholder="Any special notes about this room..."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="amenities_list">Amenities (comma separated)</label>
                        <textarea name="amenities_list" id="amenities_list" rows="2" placeholder="Wi-Fi, Air Conditioning, Sea View"></textarea>
                        <div class="field-hint">These will be stored per room and override any defaults.</div>
                    </div>
                    <div class="form-group">
                        <label for="photos_list">Photo URLs (one per line or comma separated)</label>
                        <textarea name="photos_list" id="photos_list" rows="3" placeholder="/images/rooms/room101-1.jpg"></textarea>
                        <div class="field-hint">First photo becomes the primary image for the room.</div>
                    </div>
                    <div class="form-group" id="activeRow" style="display: none;">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="is_active" id="is_active" checked>
                            <label for="is_active">Active (room is available for booking)</label>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Room</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Status Change Modal -->
    <div class="modal-overlay" id="statusModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exchange-alt"></i> Change Room Status</h3>
                <button class="modal-close" onclick="closeStatusModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" name="id" id="statusRoomId">
                <div class="modal-body">
                    <div id="statusActiveWarning" style="display:none;margin-bottom:14px;padding:10px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-size:13px;color:#7f1d1d;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span id="statusActiveWarningText"></span>
                    </div>
                    <p style="margin-bottom:10px;">Changing status for room: <strong id="statusRoomNumber"></strong></p>
                    <div class="form-group">
                        <label for="new_status">New Status</label>
                        <select name="new_status" id="new_status" required>
                            <option value="available">Available</option>
                            <option value="occupied">Occupied</option>
                            <option value="cleaning">Cleaning</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="out_of_order">Out of Order</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="reason">Reason (optional)</label>
                        <textarea name="reason" id="reason" rows="2" placeholder="Reason for status change..."></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeStatusModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assign Booking Modal -->
    <div class="modal-overlay" id="assignBookingModal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3><i class="fas fa-door-open"></i> Assign Room to Booking</h3>
                <button class="modal-close" onclick="closeAssignBookingModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label><i class="fas fa-hashtag"></i> Room:</label>
                    <input type="text" id="assign_room_number" class="form-control" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Available Bookings:</label>
                    <div id="assign_booking_list" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px; padding: 10px;">
                        <div style="text-align: center; padding: 20px; color: #666;">
                            <i class="fas fa-spinner fa-spin"></i> Loading bookings...
                        </div>
                    </div>
                </div>
                <input type="hidden" id="assign_room_id">
                <input type="hidden" id="assign_booking_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAssignBookingModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAssignBooking()"><i class="fas fa-check"></i> Assign to Selected Booking</button>
            </div>
        </div>
    </div>

    <!-- Delete Form -->
    <form method="POST" id="deleteForm" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="delete_individual_room">
        <input type="hidden" name="id" id="deleteRoomId">
    </form>

    <script>
        // Add Modal
        function openAddModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Individual Room';
            document.getElementById('formAction').value = 'add_individual_room';
            document.getElementById('roomForm').reset();
            document.getElementById('roomId').value = '';
            document.getElementById('max_guests_override').value = '';
            document.getElementById('child_price_multiplier').value = '';
            document.getElementById('single_occupancy_enabled_override').value = '';
            document.getElementById('double_occupancy_enabled_override').value = '';
            document.getElementById('triple_occupancy_enabled_override').value = '';
            document.getElementById('children_allowed_override').value = '';
            document.getElementById('statusRow').style.display = 'grid';
            document.getElementById('activeRow').style.display = 'none';
            document.getElementById('roomModal').classList.add('active');
        }

        // Edit Modal
        function openEditModal(room, amenities, photos) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Individual Room';
            document.getElementById('formAction').value = 'update_individual_room';
            document.getElementById('roomId').value = room.id;
            document.getElementById('room_type_id').value = room.room_type_id;
            document.getElementById('room_number').value = room.room_number;
            document.getElementById('room_name').value = room.room_name || '';
            document.getElementById('floor').value = room.floor || '';
            document.getElementById('max_guests_override').value = room.max_guests_override ?? '';
            document.getElementById('status').value = room.status;
            document.getElementById('display_order').value = room.display_order || 0;
            document.getElementById('child_price_multiplier').value = room.child_price_multiplier ?? '';
            document.getElementById('single_occupancy_enabled_override').value = room.single_occupancy_enabled_override ?? '';
            document.getElementById('double_occupancy_enabled_override').value = room.double_occupancy_enabled_override ?? '';
            document.getElementById('triple_occupancy_enabled_override').value = room.triple_occupancy_enabled_override ?? '';
            document.getElementById('children_allowed_override').value = room.children_allowed_override ?? '';
            document.getElementById('notes').value = room.notes || '';
            document.getElementById('amenities_list').value = (amenities || []).join(', ');
            document.getElementById('photos_list').value = (photos || []).join('\n');
            document.getElementById('is_active').checked = room.is_active == 1;
            document.getElementById('statusRow').style.display = 'grid';
            document.getElementById('activeRow').style.display = 'block';
            document.getElementById('roomModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('roomModal').classList.remove('active');
        }

        function resetCombinationForm() {
            const form = document.getElementById('combinationForm');
            if (!form) return;
            form.reset();
            document.getElementById('combination_id').value = '';
            document.getElementById('combination_is_active').checked = true;
            const title = document.getElementById('combinationFormTitle');
            if (title) title.innerHTML = '<i class="fas fa-plus-circle" style="color:#8A775F;margin-right:6px;"></i> Add / Edit Combination';
        }

        function openCombinationsModal() {
            const modal = document.getElementById('combinationsModal');
            if (modal) { modal.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
        }

        function closeCombinationsModal() {
            const modal = document.getElementById('combinationsModal');
            if (modal) { modal.style.display = 'none'; document.body.style.overflow = ''; }
        }

        function openCombinationForm(combination) {
            document.getElementById('combination_id').value = combination.id || '';
            document.getElementById('combined_name').value = combination.combined_name || '';
            document.getElementById('combined_room_type_id').value = combination.combined_room_type_id || '';
            document.getElementById('room_a_id').value = combination.room_a_id || '';
            document.getElementById('room_b_id').value = combination.room_b_id || '';
            document.getElementById('price_override').value = combination.price_override ?? '';
            document.getElementById('max_guests_combined').value = combination.max_guests_combined || 1;
            document.getElementById('combination_notes').value = combination.notes || '';
            document.getElementById('combination_is_active').checked = String(combination.is_active) === '1';
            const title = document.getElementById('combinationFormTitle');
            if (title) title.innerHTML = '<i class="fas fa-edit" style="color:#8A775F;margin-right:6px;"></i> Editing: ' + (combination.combined_name || '');
            openCombinationsModal();
            const form = document.getElementById('combinationForm');
            if (form) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        <?php if ($autoOpenCombinations): ?>
        document.addEventListener('DOMContentLoaded', function() { openCombinationsModal(); });
        <?php endif; ?>

        // Status Modal
        function openStatusModal(roomId, currentStatus, roomNumber, activeGuest, activeRef, activeCheckout) {
            document.getElementById('statusRoomId').value = roomId;
            document.getElementById('statusRoomNumber').textContent = roomNumber;

            const sel = document.getElementById('new_status');
            sel.value = currentStatus;

            // Re-enable all options first (in case re-opening after a different room)
            Array.from(sel.options).forEach(o => {
                o.disabled = false;
                o.textContent = o.textContent.replace(' ⚠', '');
            });

            // If there's an active booking, warn and block "available" as a direct jump
            const warning = document.getElementById('statusActiveWarning');
            const warningText = document.getElementById('statusActiveWarningText');
            if (activeGuest) {
                const outStr = activeCheckout ? ' (checks out ' + activeCheckout + ')' : '';
                warningText.innerHTML = ' <strong>Active booking in progress:</strong> ' + activeGuest + ' — ' + activeRef + outStr +
                    '. Setting status to <em>Available</em> while a guest is checked in is not recommended.';
                warning.style.display = '';

                // Mark "Available" as risky but don't block it — just visually flag it
                const availOpt = sel.querySelector('option[value="available"]');
                if (availOpt) availOpt.textContent = 'Available ⚠';
            } else {
                warning.style.display = 'none';
                warningText.textContent = '';
            }

            // Also update the Room Detail modal button if open
            if (typeof _rdRoomData !== 'undefined' && _rdRoomData) {
                _rdRoomData.room.status = currentStatus;
            }

            document.getElementById('statusModal').classList.add('active');
        }

        function closeStatusModal() {
            document.getElementById('statusModal').classList.remove('active');
        }

        // Delete
        function confirmDelete(roomId, roomNumber) {
            window.AdminConfirm.request({
                title: 'Delete Room',
                message: 'Delete room "' + roomNumber + '"?',
                details: 'This action cannot be undone.',
                confirmText: 'Delete',
                tone: 'danger',
                icon: 'fa-trash'
            }).then(function(confirmed) {
                if (!confirmed) return;
                document.getElementById('deleteRoomId').value = roomId;
                document.getElementById('deleteForm').submit();
            });
        }

        // Bulk Actions
        function toggleSelectAll() {
            const checkboxes = document.querySelectorAll('input[name="room_ids[]"]');
            const selectAll = document.getElementById('selectAll').checked;
            checkboxes.forEach(cb => cb.checked = selectAll);
            updateBulkActions();
        }

        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('input[name="room_ids[]"]:checked');
            const count = checkboxes.length;
            document.getElementById('selectedCount').textContent = count + ' selected';
            document.getElementById('bulkActions').classList.toggle('hidden', count === 0);
        }

        function applyBulkStatus() {
            const status = document.getElementById('bulkStatus').value;
            if (!status) {
                showRoomNotify('Please select a status.', 'error');
                return;
            }
            const checkboxes = document.querySelectorAll('input[name="room_ids[]"]:checked');
            if (checkboxes.length === 0) {
                showRoomNotify('Please select at least one room.', 'error');
                return;
            }
            const statusLabels = {
                available: 'Available',
                occupied: 'Occupied',
                cleaning: 'Cleaning',
                maintenance: 'Maintenance',
                out_of_order: 'Out of Order'
            };
            window.AdminConfirm.request({
                title: 'Bulk Status Change',
                message: 'Change status of ' + checkboxes.length + ' room(s) to "' + (statusLabels[status] || status) + '"?',
                confirmText: 'Apply',
                tone: 'warning',
                icon: 'fa-exchange-alt'
            }).then(function(confirmed) {
                if (!confirmed) return;
                document.getElementById('bulkStatus').value = status;
                document.querySelector('#bulkForm input[name="bulk_status"]')?.remove();
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'bulk_status';
                input.value = status;
                document.getElementById('bulkForm').appendChild(input);
                document.getElementById('bulkForm').submit();
            });
        }

        // Assign Booking Modal Functions
        let selectedBookingId = null;

        function openAssignBookingModal(roomId, roomNumber, roomTypeId) {
            document.getElementById('assign_room_id').value = roomId;
            document.getElementById('assign_room_number').value = roomNumber;
            document.getElementById('assign_booking_id').value = '';
            selectedBookingId = null;
            document.getElementById('assignBookingModal').classList.add('active');
            loadAssignableBookings(roomId, roomTypeId);
        }

        function closeAssignBookingModal() {
            document.getElementById('assignBookingModal').classList.remove('active');
            document.getElementById('assign_booking_list').innerHTML = '';
            selectedBookingId = null;
        }

        function loadAssignableBookings(roomId, roomTypeId) {
            const bookingList = document.getElementById('assign_booking_list');
            bookingList.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;"><i class="fas fa-spinner fa-spin"></i> Loading bookings...</div>';

            const formData = new FormData();
            formData.append('action', 'get_assignable_bookings');
            formData.append('room_type_id', roomTypeId);
            formData.append('individual_room_id', roomId);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data && data.data.length > 0) {
                        bookingList.innerHTML = '';
                        data.data.forEach(booking => {
                            const bookingCard = document.createElement('div');
                            bookingCard.className = 'booking-assign-card';
                            bookingCard.dataset.bookingId = booking.id;
                            bookingCard.style.cssText = `
                                display: flex;
                                justify-content: space-between;
                                align-items: center;
                                padding: 12px;
                                margin-bottom: 8px;
                                border: 2px solid ${booking.already_assigned ? '#ffc107' : '#28a745'};
                                border-radius: 8px;
                                cursor: pointer;
                                background: #fff;
                                transition: all 0.2s;
                            `;

                            const bookingInfo = `
                                <div>
                                    <div style="font-weight: 600; color: var(--navy);">
                                        <i class="fas fa-calendar-check" style="color: var(--gold);"></i>
                                        ${booking.reference}
                                    </div>
                                    <small style="color: #666;">${booking.guest_name} • ${booking.room_name}</small><br>
                                    <small style="color: #666;">${booking.check_in} → ${booking.check_out} (${booking.status})</small>
                                </div>
                                <div>
                                    ${booking.already_assigned
                                        ? `<span class="badge" style="background: #fff3cd; color: #856404; padding: 4px 12px; border-radius: 12px; font-size: 11px;">Already Assigned</span>`
                                        : `<span class="badge" style="background: #d4edda; color: #155724; padding: 4px 12px; border-radius: 12px; font-size: 11px;">Available</span>`
                                    }
                                </div>
                            `;
                            bookingCard.innerHTML = bookingInfo;
                            bookingCard.onclick = () => selectBookingForAssignment(booking.id, bookingCard);
                            bookingList.appendChild(bookingCard);
                        });
                    } else {
                        bookingList.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> No assignable bookings found.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading bookings:', error);
                    bookingList.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fas fa-exclamation-circle"></i> Error loading bookings.</div>';
                });
        }

        function selectBookingForAssignment(bookingId, cardElement) {
            selectedBookingId = bookingId;
            document.getElementById('assign_booking_id').value = bookingId;

            // Remove previous selection
            document.querySelectorAll('.booking-assign-card').forEach(card => {
                card.style.background = '#fff';
                card.style.borderColor = card.dataset.alreadyAssigned === 'true' ? '#ffc107' : '#28a745';
            });

            // Highlight selected card
            cardElement.style.background = '#fff8e1';
            cardElement.style.borderColor = 'var(--gold)';
        }

        function submitAssignBooking() {
            if (!selectedBookingId) {
                showRoomNotify('Please select a booking to assign.', 'error');
                return;
            }

            const roomId = document.getElementById('assign_room_id').value;
            const bookingId = selectedBookingId;

            const formData = new FormData();
            formData.append('action', 'assign_individual_room');
            formData.append('booking_id', bookingId);
            formData.append('individual_room_id', roomId);

            fetch('bookings.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showRoomNotify('Room assigned successfully!', 'success');
                        closeAssignBookingModal();
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        showRoomNotify(data.message || 'Failed to assign room.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showRoomNotify('Error assigning room.', 'error');
                });
        }

        // Close modals on outside click
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // ── Stat card click-to-filter ─────────────────────────────────────────
        let _activeStatusFilter = null;

        function filterByStatus(status) {
            const tbody = document.getElementById('roomsTableBody');
            if (!tbody) return;

            // Toggle off if already active
            if (_activeStatusFilter === status) {
                clearStatusFilter();
                return;
            }

            _activeStatusFilter = status;

            // Highlight active card, un-highlight others
            document.querySelectorAll('.status-card[data-status-filter]').forEach(card => {
                card.classList.toggle('active-filter', card.dataset.statusFilter === status);
            });

            // Filter rows
            const rows = tbody.querySelectorAll('tr[data-room-status]');
            let visibleCount = 0;
            rows.forEach(row => {
                const match = row.dataset.roomStatus === status;
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });

            // Show banner
            const statusLabels = {
                available: 'Available',
                occupied: 'Occupied',
                cleaning: 'Cleaning',
                maintenance: 'Maintenance',
                out_of_order: 'Out of Order'
            };
            const banner = document.getElementById('filterBanner');
            document.getElementById('filterBannerText').textContent =
                'Showing ' + visibleCount + ' ' + (statusLabels[status] || status) + ' room' + (visibleCount !== 1 ? 's' : '');
            banner.classList.add('visible');
        }

        function clearStatusFilter() {
            _activeStatusFilter = null;
            document.querySelectorAll('.status-card[data-status-filter]').forEach(c => c.classList.remove('active-filter'));
            const tbody = document.getElementById('roomsTableBody');
            if (tbody) tbody.querySelectorAll('tr[data-room-status]').forEach(r => r.style.display = '');
            document.getElementById('filterBanner').classList.remove('visible');
        }

        // ── Sortable table columns ────────────────────────────────────────────
        const _sortState = {
            col: -1,
            dir: 1
        };

        function sortTable(th) {
            const colIdx = parseInt(th.dataset.col, 10);
            const tbody = document.getElementById('roomsTableBody');
            if (!tbody) return;

            if (_sortState.col === colIdx) {
                _sortState.dir *= -1;
            } else {
                _sortState.col = colIdx;
                _sortState.dir = 1;
            }

            // Update header indicators
            document.querySelectorAll('.rooms-table th.sortable').forEach(t => {
                t.classList.remove('sort-asc', 'sort-desc');
                const icon = t.querySelector('.sort-icon');
                if (icon) icon.textContent = '⇅';
            });
            th.classList.add(_sortState.dir === 1 ? 'sort-asc' : 'sort-desc');
            const activeIcon = th.querySelector('.sort-icon');
            if (activeIcon) activeIcon.textContent = _sortState.dir === 1 ? '↑' : '↓';

            // Sort rows (preserve empty-state row)
            const rows = Array.from(tbody.querySelectorAll('tr[data-room-status]'));
            rows.sort((a, b) => {
                const aCell = a.querySelectorAll('td')[colIdx];
                const bCell = b.querySelectorAll('td')[colIdx];
                if (!aCell || !bCell) return 0;
                const aVal = aCell.textContent.trim().toLowerCase();
                const bVal = bCell.textContent.trim().toLowerCase();
                if (aVal < bVal) return -1 * _sortState.dir;
                if (aVal > bVal) return 1 * _sortState.dir;
                return 0;
            });
            rows.forEach(r => tbody.appendChild(r));
        }

        // ── Children policy quick-toggle ─────────────────────────────────────
        // Cycle: Inherit → Allowed (1) → Blocked (0) → Inherit → ...
        function cycleChildrenPolicy(btn) {
            const current = btn.dataset.override; // 'null' | '1' | '0'
            let nextOverride;
            if (current === 'null') {
                nextOverride = '1'; // Inherit → Allowed
            } else if (current === '1') {
                nextOverride = '0'; // Allowed → Blocked
            } else {
                nextOverride = 'null'; // Blocked → Inherit
            }

            btn.disabled = true;
            btn.style.opacity = '0.6';

            const formData = new FormData();
            formData.append('action', 'set_children_override');
            formData.append('id', btn.dataset.roomId);
            formData.append('children_allowed_override', nextOverride);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    if (!data.success) {
                        showRoomNotify(data.message || 'Failed to update children policy.', 'error');
                        return;
                    }
                    // Update button state
                    btn.dataset.override = nextOverride;
                    btn.dataset.effective = data.effective;

                    if (data.is_inherited) {
                        btn.style.background = '#e9ecef';
                        btn.style.color = '#495057';
                        btn.innerHTML = '<i class="fas fa-arrows-rotate"></i> Inherit';
                        btn.setAttribute('data-help', 'Inherit from Room Type|No manual override is set — this room follows the children policy of its room type. Click to set an explicit override just for this room.');
                    } else if (data.effective) {
                        btn.style.background = '#d4edda';
                        btn.style.color = '#155724';
                        btn.innerHTML = '<i class="fas fa-child"></i> Allowed';
                        btn.setAttribute('data-help', 'Children Allowed (Override)|Children are explicitly permitted in this room, overriding the room type default. Click to change to Blocked, or click again to remove the override and inherit.');
                    } else {
                        btn.style.background = '#f8d7da';
                        btn.style.color = '#721c24';
                        btn.innerHTML = '<i class="fas fa-ban"></i> Blocked';
                        btn.setAttribute('data-help', 'Children Blocked (Override)|Children are explicitly not permitted in this room, overriding the room type default. Click to remove the override and inherit from the room type.');
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    showRoomNotify('Network error updating children policy.', 'error');
                });
        }

        // ── Room Detail Modal ─────────────────────────────────────────────────
        let _rdRoomId = null;
        let _rdRoomData = null; // cache last loaded room JSON

        function openRoomDetailModal(roomId) {
            _rdRoomId = roomId;
            _rdRoomData = null;

            // Reset UI
            document.getElementById('rdTitle').textContent = '—';
            document.getElementById('rdSubtitle').textContent = '—';
            document.getElementById('rdStatusBadge').textContent = '';
            document.getElementById('rdStatusBadge').className = 'badge';
            document.getElementById('rdPrice').textContent = '';
            document.getElementById('rdSpinner').style.display = '';
            document.getElementById('rdContent').style.display = 'none';
            document.getElementById('rdBookingLink').style.display = 'none';

            document.getElementById('roomDetailModal').classList.add('active');

            const fd = new FormData();
            fd.append('action', 'get_room_detail');
            fd.append('room_id', roomId);

            fetch(window.location.href, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    document.getElementById('rdSpinner').style.display = 'none';
                    if (!data.success) {
                        document.getElementById('rdContent').innerHTML = '<p style="color:#dc2626;padding:20px;">' + (data.message || 'Failed to load.') + '</p>';
                        document.getElementById('rdContent').style.display = '';
                        return;
                    }
                    _rdRoomData = data;
                    renderRoomDetail(data);
                    document.getElementById('rdContent').style.display = '';
                })
                .catch(() => {
                    document.getElementById('rdSpinner').style.display = 'none';
                    document.getElementById('rdContent').innerHTML = '<p style="color:#dc2626;padding:20px;">Network error — please try again.</p>';
                    document.getElementById('rdContent').style.display = '';
                });
        }

        function closeRoomDetailModal() {
            document.getElementById('roomDetailModal').classList.remove('active');
        }

        function _rdChangeStatus() {
            if (!_rdRoomData) return;
            const room = _rdRoomData.room;
            const active = _rdRoomData.active;
            closeRoomDetailModal();
            openStatusModal(
                room.id,
                room.status,
                room.number,
                active ? active.guest_name : '',
                active ? active.reference : '',
                active ? active.check_out : ''
            );
        }

        function _rdAssign() {
            if (!_rdRoomData) return;
            closeRoomDetailModal();
            openAssignBookingModal(_rdRoomData.room.id, _rdRoomData.room.number, _rdRoomData.room.room_type_id);
        }

        function _rdEdit() {
            if (!_rdRoomData) return;
            // Find the matching room row and trigger existing openEditModal
            const rows = document.querySelectorAll('tr[data-room-status]');
            for (const row of rows) {
                const editBtn = row.querySelector('button[onclick*="openEditModal"]');
                if (editBtn) {
                    const match = editBtn.getAttribute('onclick').match(/openEditModal\((\{.+?\})/s);
                    if (match) {
                        try {
                            const roomObj = JSON.parse(match[1]);
                            if (roomObj.id === _rdRoomData.room.id) {
                                closeRoomDetailModal();
                                editBtn.click();
                                return;
                            }
                        } catch (e) {
                            /* skip */ }
                    }
                }
            }
            // Fallback: reload with hash
            closeRoomDetailModal();
        }

        // Room statuses (individual_rooms.status enum)
        const _statusLabels = {
            available: 'Available',
            occupied: 'Occupied',
            cleaning: 'Cleaning',
            maintenance: 'Maintenance',
            out_of_order: 'Out of Order',
            // Booking statuses
            pending: 'Pending',
            tentative: 'Tentative',
            confirmed: 'Confirmed',
            'checked-in': 'Checked In',
            'checked-out': 'Checked Out',
            cancelled: 'Cancelled',
            expired: 'Expired',
            'no-show': 'No Show'
        };
        const _payLabels = {
            // bookings.payment_status enum: unpaid / partial / paid
            unpaid: 'Unpaid',
            partial: 'Partial',
            paid: 'Paid',
            // legacy / other tables
            pending: 'Pending',
            refunded: 'Refunded',
            failed: 'Failed'
        };
        const _statusColors = {
            // Room statuses
            available: '#15803d',
            occupied: '#b91c1c',
            cleaning: '#92400e',
            maintenance: '#c2410c',
            out_of_order: '#4b5563',
            // Booking statuses
            pending: '#92400e',
            tentative: '#6b21a8',
            confirmed: '#1d4ed8',
            'checked-in': '#15803d',
            'checked-out': '#374151',
            cancelled: '#6b7280',
            expired: '#6b7280',
            'no-show': '#dc2626'
        };
        const _payColors = {
            unpaid: '#b91c1c',
            partial: '#1d4ed8',
            paid: '#15803d',
            pending: '#92400e',
            refunded: '#6b7280',
            failed: '#b91c1c'
        };

        function _badge(text, color) {
            return `<span style="display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:${color}18;color:${color};border:1px solid ${color}40;">${text}</span>`;
        }

        function renderRoomDetail(data) {
            const room = data.room;
            const active = data.active;
            const upcoming = data.upcoming || [];
            const log = data.log || [];

            // Header
            const fullName = room.number + (room.name ? ' — ' + room.name : '');
            document.getElementById('rdTitle').textContent = fullName;
            document.getElementById('rdSubtitle').textContent = room.type + (room.floor ? ' · Floor ' + room.floor : '');
            document.getElementById('rdPrice').textContent = room.price;

            const statusBadge = document.getElementById('rdStatusBadge');
            statusBadge.textContent = _statusLabels[room.status] || room.status;
            statusBadge.className = 'badge badge-' + room.status;

            // Calendar link
            document.getElementById('rdCalLink').href = 'calendar.php?filter_room_id=' + room.id;

            // Assign Booking button — show only when room can accept an assignment
            const assignBtn = document.getElementById('rdAssignBtn');
            const blockByStatus = room.status === 'maintenance' || room.status === 'out_of_order' || room.status === 'cleaning';
            const fullyScheduled = active && upcoming.length > 0;
            if (!blockByStatus && !fullyScheduled) {
                assignBtn.style.display = 'inline-flex';
                assignBtn.disabled = false;
                assignBtn.title = '';
            } else {
                assignBtn.style.display = 'none';
            }

            // ── Occupancy section ─────────────────────────────────────
            const occSection = document.getElementById('rdOccupancySection');
            const occBody = document.getElementById('rdOccupancyBody');
            const bookingLink = document.getElementById('rdBookingLink');

            if (active) {
                bookingLink.href = 'booking-details.php?id=' + active.id;
                bookingLink.style.display = 'inline-flex';

                const checkIn = new Date(active.check_in + 'T00:00:00');
                const checkOut = new Date(active.check_out + 'T00:00:00');
                const totalNights = Math.round((checkOut - checkIn) / 86400000);

                let specialReqHtml = active.special_requests ?
                    `<div class="rd-info-note"><i class="fas fa-comment-dots"></i> ${_esc(active.special_requests)}</div>` :
                    '';

                let guestContact = '';
                if (active.guest_email) {
                    guestContact += `<a href="mailto:${_esc(active.guest_email)}" class="rd-contact-link"><i class="fas fa-envelope"></i> ${_esc(active.guest_email)}</a> `;
                }
                if (active.guest_phone) {
                    guestContact += `<a href="tel:${_esc(active.guest_phone.replace(/\s/g,''))}" class="rd-contact-link"><i class="fas fa-phone"></i> ${_esc(active.guest_phone)}</a>`;
                }

                occBody.innerHTML = `
                    <div class="rd-occupancy-card">
                        <div class="rd-occ-row">
                            <div class="rd-occ-col">
                                <div class="rd-occ-label">Guest</div>
                                <div class="rd-occ-value rd-occ-guest">${_esc(active.guest_name)}</div>
                                ${guestContact ? `<div class="rd-occ-contacts">${guestContact}</div>` : ''}
                            </div>
                            <div class="rd-occ-col">
                                <div class="rd-occ-label">Booking Ref</div>
                                <div class="rd-occ-value">
                                    <a href="booking-details.php?id=${active.id}" class="rd-booking-ref" target="_blank">
                                        ${_esc(active.reference)} <i class="fas fa-external-link-alt" style="font-size:10px;"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="rd-occ-row">
                            <div class="rd-occ-col">
                                <div class="rd-occ-label">Check-in</div>
                                <div class="rd-occ-value">${_fmtDate(active.check_in)}</div>
                            </div>
                            <div class="rd-occ-col">
                                <div class="rd-occ-label">Check-out</div>
                                <div class="rd-occ-value">${_fmtDate(active.check_out)}</div>
                            </div>
                            <div class="rd-occ-col">
                                <div class="rd-occ-label">Stay</div>
                                <div class="rd-occ-value">${totalNights} night${totalNights !== 1 ? 's' : ''}</div>
                            </div>
                            <div class="rd-occ-col">
                                <div class="rd-occ-label">Nights left</div>
                                <div class="rd-occ-value rd-nights-remaining">${active.nights_remaining} night${active.nights_remaining !== 1 ? 's' : ''}</div>
                            </div>
                        </div>
                        <div class="rd-occ-row">
                            <div class="rd-occ-col">
                                <div class="rd-occ-label">Guests</div>
                                <div class="rd-occ-value">${active.adults} adult${active.adults !== 1 ? 's' : ''}${active.children > 0 ? ' + ' + active.children + ' child' + (active.children !== 1 ? 'ren' : '') : ''}</div>
                            </div>
                            <div class="rd-occ-col">
                                <div class="rd-occ-label">Booking Status</div>
                                <div class="rd-occ-value">${_badge(_statusLabels[active.status] || active.status, _statusColors[active.status] || '#6b7280')}</div>
                            </div>
                            <div class="rd-occ-col">
                                <div class="rd-occ-label">Payment</div>
                                <div class="rd-occ-value">
                                    ${_badge(_payLabels[active.payment_status] || active.payment_status, _payColors[active.payment_status] || '#6b7280')}
                                    <span style="font-size:12px;color:#5E554D;margin-left:4px;">${active.total_amount}</span>
                                </div>
                            </div>
                        </div>
                        ${specialReqHtml}
                    </div>`;

                occSection.querySelector('.rd-section-title').innerHTML = '<i class="fas fa-user-check" style="color:#dc2626;"></i> Currently Occupied';
            } else {
                // No active booking found
                const nextMsg = upcoming.length ?
                    `Next check-in: <strong>${_fmtDate(upcoming[0].check_in)}</strong> — ${_esc(upcoming[0].guest_name)}` :
                    'No upcoming bookings scheduled.';
                const statusLabel = _statusLabels[room.status] || room.status;
                const statusColor = _statusColors[room.status] || '#6b7280';

                let extraWarning = '';
                if (room.status === 'occupied') {
                    extraWarning = `
                        <div style="margin-top:10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:12px;color:#7f1d1d;display:flex;align-items:flex-start;gap:8px;">
                            <i class="fas fa-exclamation-triangle" style="margin-top:2px;flex-shrink:0;"></i>
                            <div><strong>No booking record found.</strong> This room is marked as Occupied but has no linked booking in the system. It was likely set manually. Please either assign a booking or update the room status.</div>
                        </div>`;
                }

                occBody.innerHTML = `
                    <div class="rd-empty-occupancy">
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                            <span style="font-size:2rem;color:${statusColor};">
                                <i class="fas fa-door-${room.status === 'available' ? 'open' : 'closed'}"></i>
                            </span>
                            <div>
                                <div style="font-weight:600;font-size:15px;color:${statusColor};">${statusLabel}</div>
                                <div style="font-size:13px;color:#5E554D;">${nextMsg}</div>
                            </div>
                        </div>
                        ${extraWarning}
                    </div>`;
                occSection.querySelector('.rd-section-title').innerHTML = '<i class="fas fa-door-open"></i> Current Occupancy';
            }

            // ── Upcoming Bookings ─────────────────────────────────────
            const upSection = document.getElementById('rdUpcomingSection');
            const upBody = document.getElementById('rdUpcomingBody');
            if (upcoming.length) {
                upSection.style.display = '';
                upBody.innerHTML = upcoming.map(b => {
                    const ci = new Date(b.check_in + 'T00:00:00');
                    const co = new Date(b.check_out + 'T00:00:00');
                    const nights = Math.round((co - ci) / 86400000);
                    return `
                        <div class="rd-upcoming-row">
                            <div class="rd-up-ref">
                                <a href="booking-details.php?id=${b.id}" target="_blank" class="rd-booking-ref">
                                    ${_esc(b.reference)} <i class="fas fa-external-link-alt" style="font-size:9px;"></i>
                                </a>
                            </div>
                            <div class="rd-up-guest">${_esc(b.guest_name)}</div>
                            <div class="rd-up-dates">${_fmtDate(b.check_in)} → ${_fmtDate(b.check_out)} <span style="color:#8A775F;font-weight:500;">(${nights}n)</span></div>
                            <div class="rd-up-badges">
                                ${_badge(_statusLabels[b.status] || b.status, _statusColors[b.status] || '#6b7280')}
                                ${_badge(_payLabels[b.payment_status] || b.payment_status, _payColors[b.payment_status] || '#6b7280')}
                                <span style="font-size:11px;color:#5E554D;">${b.total_amount}</span>
                            </div>
                        </div>`;
                }).join('');
            } else {
                upSection.style.display = 'none';
            }

            // ── Room Info ─────────────────────────────────────────────
            const infoBody = document.getElementById('rdInfoBody');
            const amenitiesHtml = room.amenities && room.amenities.length ?
                room.amenities.map(a => `<span class="rd-amenity-tag">${_esc(a)}</span>`).join('') :
                '<span style="color:#9ca3af;font-size:12px;">No amenities listed</span>';

            infoBody.innerHTML = `
                <div class="rd-info-grid">
                    <div class="rd-info-item"><span class="rd-info-label">Type</span><span>${_esc(room.type)}</span></div>
                    <div class="rd-info-item"><span class="rd-info-label">Floor</span><span>${room.floor || '—'}</span></div>
                    <div class="rd-info-item"><span class="rd-info-label">Max Guests</span><span>${room.max_guests}</span></div>
                    <div class="rd-info-item"><span class="rd-info-label">Rate</span><span>${_esc(room.price)}</span></div>
                    <div class="rd-info-item"><span class="rd-info-label">Active</span><span>${room.is_active ? '✓ Yes' : '✗ No'}</span></div>
                </div>
                ${room.notes ? `<div class="rd-info-note"><i class="fas fa-sticky-note"></i> ${_esc(room.notes)}</div>` : ''}
                <div class="rd-amenities">${amenitiesHtml}</div>`;

            // ── Status Log ────────────────────────────────────────────
            const logSection = document.getElementById('rdLogSection');
            const logBody = document.getElementById('rdLogBody');
            if (log.length) {
                logSection.style.display = '';
                logBody.innerHTML = `<div class="rd-log-list">` + log.map(l => `
                    <div class="rd-log-row">
                        <span class="rd-log-arrow">
                            ${l.from !== '-' ? _badge(l.from, _statusColors[l.from] || '#6b7280') : ''}
                            ${l.from !== '-' ? ' <i class="fas fa-arrow-right" style="font-size:9px;color:#9ca3af;"></i> ' : ''}
                            ${_badge(l.to, _statusColors[l.to] || '#6b7280')}
                        </span>
                        <span class="rd-log-meta">
                            ${l.reason ? `<span class="rd-log-reason">${_esc(l.reason)}</span>` : ''}
                            <span class="rd-log-by">${_esc(l.performed_by)}</span>
                            <span class="rd-log-date">${_fmtDateTime(l.date)}</span>
                        </span>
                    </div>`).join('') + `</div>`;
            } else {
                logSection.style.display = 'none';
            }
        }

        function _esc(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function _fmtDate(iso) {
            if (!iso) return '—';
            const d = new Date(iso + 'T00:00:00');
            return d.toLocaleDateString('en-GB', {
                day: 'numeric',
                month: 'short',
                year: 'numeric'
            });
        }

        function _fmtDateTime(iso) {
            if (!iso) return '—';
            try {
                const d = new Date(iso);
                return d.toLocaleDateString('en-GB', {
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric'
                    }) +
                    ' ' + d.toLocaleTimeString('en-GB', {
                        hour: '2-digit',
                        minute: '2-digit'
                    });
            } catch (e) {
                return iso;
            }
        }

        // ── Inline notification (replaces alert/confirm) ─────────────────────
        function showRoomNotify(msg, type) {
            let n = document.getElementById('roomNotifyBar');
            if (!n) {
                n = document.createElement('div');
                n.id = 'roomNotifyBar';
                n.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9999;max-width:380px;padding:12px 20px;border-radius:8px;font-size:14px;font-family:inherit;box-shadow:0 4px 16px rgba(0,0,0,0.2);transition:opacity 0.4s;pointer-events:none;';
                document.body.appendChild(n);
            }
            const isErr = type === 'error';
            n.style.background = isErr ? '#f8d7da' : '#d4edda';
            n.style.color = isErr ? '#721c24' : '#155724';
            n.style.borderLeft = isErr ? '4px solid #dc3545' : '4px solid #28a745';
            n.textContent = msg;
            n.style.opacity = '1';
            clearTimeout(n._t);
            n._t = setTimeout(() => {
                n.style.opacity = '0';
            }, 3500);
        }
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
    <?php require_once 'includes/help-tooltips.php'; ?>
</body>

</html>

