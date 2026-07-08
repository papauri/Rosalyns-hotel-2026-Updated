<?php

/**
 * Admin - Create Booking Manually
 * For walk-in guests, phone bookings, and agent bookings.
 *
 * Flow: Pick dates → available rooms → guest info → payment confirmation → create + email
 * Supports: multiple rooms of same type, manual or auto individual room assignment,
 *           full payment/partial payment recording with VAT & levy accounting.
 */

require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */
$user       = $user       ?? ['id' => 0, 'username' => '', 'role' => 'guest', 'full_name' => ''];
$csrf_token = $csrf_token ?? generateCsrfToken();
$site_name  = $site_name  ?? getSetting('site_name', 'Hotel');

require_once '../includes/validation.php';
require_once '../includes/booking-functions.php';
require_once '../includes/idempotency.php';
require_once '../includes/finance-sequences.php';
require_once '../includes/pricing.php';

$message = '';
$error   = '';

// VAT & levy settings (also passed to JS for live preview)
$vat_enabled     = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
$vat_rate_cfg    = $vat_enabled ? (float)getSetting('vat_rate', 0) : 0.0;
$levy_enabled    = getSetting('tourism_levy_enabled', '0') === '1';
$levy_pct_cfg    = $levy_enabled ? (float)getSetting('tourism_levy_percent', 0) : 0.0;
$currency_symbol = getSetting('currency_symbol', 'MWK');
finance_ensure_sequence_tables($pdo);

// ── AJAX: look up a guest's redeemable credit notes by email ──────────────────
// Served from this page so it reuses booking-staff permissions (the credit-notes
// API is gated to the 'invoices' permission, which booking staff may not hold).
if (($_GET['ajax'] ?? '') === 'guest_credit_lookup') {
    header('Content-Type: application/json');
    $lookupEmail = trim($_GET['email'] ?? '');
    if ($lookupEmail === '' || !filter_var($lookupEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => true, 'data' => [], 'total_balance' => 0, 'currency' => $currency_symbol]);
        exit;
    }
    try {
        $cnLookup = $pdo->prepare("
            SELECT id, credit_note_number, balance, expires_at, reason
            FROM credit_notes
            WHERE guest_email = ?
              AND status IN ('active', 'partially_applied')
              AND balance > 0.005
              AND (expires_at IS NULL OR expires_at >= CURDATE())
            ORDER BY (expires_at IS NULL), expires_at ASC, id ASC
        ");
        $cnLookup->execute([$lookupEmail]);
        $cnData = [];
        $cnTotal = 0.0;
        foreach ($cnLookup->fetchAll(PDO::FETCH_ASSOC) as $cnRow) {
            $cnBal = (float)$cnRow['balance'];
            $cnTotal += $cnBal;
            $cnData[] = [
                'id'              => (int)$cnRow['id'],
                'number'          => (string)$cnRow['credit_note_number'],
                'balance'         => round($cnBal, 2),
                'balance_display' => $currency_symbol . number_format($cnBal, 2),
                'expires_at'      => $cnRow['expires_at'] ? date('d M Y', strtotime((string)$cnRow['expires_at'])) : 'No expiry',
                'reason'          => ucfirst(str_replace('_', ' ', (string)($cnRow['reason'] ?? ''))),
            ];
        }
        echo json_encode(['success' => true, 'data' => $cnData, 'total_balance' => round($cnTotal, 2), 'currency' => $currency_symbol]);
    } catch (\Throwable $e) {
        error_log('create-booking guest_credit_lookup error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'data' => [], 'total_balance' => 0, 'currency' => $currency_symbol]);
    }
    exit;
}

// Fetch all active room types
try {
    $rooms_stmt = $pdo->query("
         SELECT r.id, r.name, r.price_per_night, r.price_single_occupancy, r.price_double_occupancy,
             r.price_triple_occupancy, r.child_price_multiplier,
             GREATEST(
                 r.max_guests,
                 COALESCE((SELECT MAX(ir.max_guests_override) FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1), 0),
                 COALESCE((SELECT MAX(rc.max_guests_combined) FROM room_combinations rc WHERE rc.combined_room_type_id = r.id AND rc.is_active = 1), 0)
             ) AS max_guests,
             r.rooms_available, r.total_rooms,
               short_description, single_occupancy_enabled, double_occupancy_enabled,
               triple_occupancy_enabled, children_allowed
         FROM rooms r WHERE r.is_active = 1 ORDER BY r.display_order ASC
    ");
    $rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rooms = [];
    $error = 'Failed to load rooms.';
}

// Fetch all individual rooms for grouped dropdown (used in JS-built UI)
try {
    $individual_rooms_stmt = $pdo->query("
        SELECT ir.id, ir.room_number, ir.room_name, ir.room_type_id, ir.status, ir.floor, ir.view_type,
               ir.child_price_multiplier AS individual_child_price_multiplier,
               ir.single_occupancy_enabled_override, ir.double_occupancy_enabled_override,
               ir.triple_occupancy_enabled_override, ir.children_allowed_override,
               ir.max_guests_override,
               r.name AS room_type_name, r.price_per_night,
               r.child_price_multiplier AS room_type_child_price_multiplier,
               r.max_guests, r.single_occupancy_enabled, r.double_occupancy_enabled,
               r.triple_occupancy_enabled, r.children_allowed,
               COALESCE(ir.child_price_multiplier, r.child_price_multiplier) AS effective_child_price_multiplier
        FROM individual_rooms ir
        JOIN rooms r ON ir.room_type_id = r.id
        WHERE ir.is_active = 1 ORDER BY r.name, ir.room_number
    ");
    $all_individual_rooms = $individual_rooms_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_individual_rooms = [];
}

// ─── AJAX: room type availability for date range ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_room_type_availability') {
    header('Content-Type: application/json');
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    $ci = trim($_POST['check_in'] ?? '');
    $co = trim($_POST['check_out'] ?? '');
    if (!$ci || !$co) {
        echo json_encode(['success' => false, 'error' => 'Dates required.']);
        exit;
    }
    try {
        $avail_stmt = $pdo->query("SELECT id FROM rooms WHERE is_active = 1 ORDER BY display_order ASC");
        $availability = [];
        foreach ($avail_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $check = checkRoomAvailability((int)$row['id'], $ci, $co);
            $availability[] = [
                'room_id' => (int)$row['id'],
                'available' => !empty($check['available']),
                'rooms_left' => max(0, (int)($check['remaining_rooms'] ?? 0)),
                'max_guests' => (int)($check['max_guests'] ?? 0),
            ];
        }
        echo json_encode(['success' => true, 'data' => $availability]);
    } catch (PDOException $e) {
        error_log('create-booking avail check: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Availability check failed.']);
    }
    exit;
}

// ─── AJAX: individual rooms available for a room type + date range ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_available_individual_rooms') {
    header('Content-Type: application/json');
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    $room_type_id = (int)($_POST['room_type_id'] ?? 0);
    $ci           = trim($_POST['check_in'] ?? '');
    $co           = trim($_POST['check_out'] ?? '');
    if (!$room_type_id || !$ci || !$co) {
        echo json_encode(['success' => false, 'error' => 'room_type_id, check_in, check_out required.']);
        exit;
    }
    try {
        $ir_stmt = $pdo->prepare("
            SELECT ir.id, ir.room_number, ir.room_name, ir.status, ir.floor, ir.view_type,
                   ir.child_price_multiplier, ir.children_allowed_override,
                   ir.single_occupancy_enabled_override, ir.double_occupancy_enabled_override,
                   ir.triple_occupancy_enabled_override,
                   COALESCE(ir.child_price_multiplier, r.child_price_multiplier) AS effective_child_multiplier
            FROM individual_rooms ir
            JOIN rooms r ON ir.room_type_id = r.id
            WHERE ir.room_type_id = ? AND ir.is_active = 1 ORDER BY ir.room_number ASC
        ");
        $ir_stmt->execute([$room_type_id]);
        $result = [];
        foreach ($ir_stmt->fetchAll(PDO::FETCH_ASSOC) as $ir) {
            $conflict_stmt = $pdo->prepare("
                                SELECT COUNT(*) FROM bookings b
                                WHERE (b.individual_room_id = ? OR EXISTS (
                                        SELECT 1 FROM booking_rooms br
                                        WHERE br.booking_id = b.id
                                            AND br.individual_room_id = ?
                                            AND br.released_at IS NULL
                                ))
                                    AND b.status NOT IN ('cancelled','no-show')
                  AND check_in_date < ? AND check_out_date > ?
            ");
            $conflict_stmt->execute([$ir['id'], $ir['id'], $co, $ci]);
            $has_conflict = (int)$conflict_stmt->fetchColumn() > 0;
            $label = $ir['room_number'];
            if (!empty($ir['room_name'])) $label .= ' – ' . $ir['room_name'];
            if (!empty($ir['floor']))     $label .= ' (Floor ' . $ir['floor'] . ')';
            if (!empty($ir['view_type'])) $label .= ', ' . $ir['view_type'];
            $result[] = [
                'id'                => (int)$ir['id'],
                'label'             => $label,
                'status'            => $ir['status'],
                'available'         => !$has_conflict && $ir['status'] === 'available',
                'conflict'          => $has_conflict,
                'child_multiplier'  => (float)($ir['effective_child_multiplier'] ?? 50),
                'single_override'   => $ir['single_occupancy_enabled_override'],
                'double_override'   => $ir['double_occupancy_enabled_override'],
                'triple_override'   => $ir['triple_occupancy_enabled_override'],
                'children_override' => $ir['children_allowed_override'],
            ];
        }
        echo json_encode(['success' => true, 'data' => $result]);
    } catch (PDOException $e) {
        error_log('get_available_individual_rooms: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to load rooms.']);
    }
    exit;
}

// ─── POST: create booking(s) ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_booking'])) {
    try {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security token invalid — refresh the page.');
        }

        // Idempotency guard
        $__incomingClientUuid = $_POST['client_uuid'] ?? null;
        if ($__existingBooking = idem_find_existing_booking($pdo, $__incomingClientUuid)) {
            $message = 'Booking already created (reference ' . htmlspecialchars((string)$__existingBooking['booking_reference']) . '). Duplicate submission ignored.';
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => $message];
            header('Location: booking-details.php?id=' . (int)$__existingBooking['id']);
            exit;
        }

        if (!isBookingEnabled()) {
            throw new Exception('Booking system is currently disabled.');
        }

        // ── Inputs ──────────────────────────────────────────────────────────
        // Room lines: arrays from multi-line room allocator UI
        $rl_room_ids    = array_values((array)($_POST['room_line_room_id']   ?? []));
        $rl_qtys        = array_values((array)($_POST['room_line_qty']       ?? []));
        $rl_occupancies = array_values((array)($_POST['room_line_occupancy'] ?? []));
        $rl_overrides   = array_values((array)($_POST['room_line_override']  ?? []));
        // Backwards-compat: old single-room fields → convert to first line
        if (empty($rl_room_ids) && !empty($_POST['room_id'])) {
            $rl_room_ids    = [(int)$_POST['room_id']];
            $rl_qtys        = [max(1, (int)($_POST['rooms_quantity'] ?? 1))];
            $rl_occupancies = [$_POST['occupancy_type'] ?? 'double'];
            $rl_overrides   = [trim($_POST['price_override'] ?? '')];
        }

        // Price override is a privileged action — only admin and manager roles may set it
        $hasNonEmptyOverride = !empty(array_filter(array_map('trim', $rl_overrides), static fn($v) => $v !== ''));
        if ($hasNonEmptyOverride && !in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
            throw new Exception('Your role does not have permission to override room pricing.');
        }
        $individual_room_id = !empty($_POST['individual_room_id']) ? (int)$_POST['individual_room_id'] : null;
        $auto_assign        = isset($_POST['auto_assign_room']) && $_POST['auto_assign_room'] === '1';

        $guest_name       = trim($_POST['guest_name'] ?? '');
        $guest_email      = trim($_POST['guest_email'] ?? '');
        $guest_phone      = trim($_POST['guest_phone'] ?? '');
        $guest_country    = trim($_POST['guest_country'] ?? '');
        $guest_address    = trim($_POST['guest_address'] ?? '');
        $number_of_guests = (int)($_POST['number_of_guests'] ?? 1);
        $child_guests     = max(0, (int)($_POST['child_guests'] ?? 0));
        $check_in_date    = $_POST['check_in_date'] ?? '';
        $check_out_date   = $_POST['check_out_date'] ?? '';
        $special_requests = trim($_POST['special_requests'] ?? '');
        $booking_status   = $_POST['booking_status'] ?? 'confirmed';
        $group_reference  = trim($_POST['group_reference'] ?? '');
        $admin_notes      = trim($_POST['admin_notes'] ?? '');
        $send_email       = isset($_POST['send_email']);

        // Payment fields
        $payment_received     = isset($_POST['payment_received']) && $_POST['payment_received'] === '1';
        $payment_method       = trim($_POST['payment_method'] ?? 'cash');
        $amount_collected_raw = trim($_POST['amount_collected'] ?? '');
        $amount_collected     = $amount_collected_raw !== '' ? max(0.0, (float)$amount_collected_raw) : 0.0;

        // ── Basic validation ─────────────────────────────────────────────────
        $errors = [];
        if (empty($rl_room_ids))                                                      $errors[] = 'Please select at least one room.';
        if (empty($guest_name))                                                       $errors[] = 'Guest name is required.';
        if (empty($guest_email) || !filter_var($guest_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (empty($guest_phone))                                                      $errors[] = 'Phone number is required.';
        if ($number_of_guests < 1)                                                    $errors[] = 'At least 1 guest required.';
        if ($child_guests >= $number_of_guests)                                       $errors[] = 'At least 1 adult is required.';
        if (empty($check_in_date))                                                    $errors[] = 'Check-in date is required.';
        if (empty($check_out_date))                                                   $errors[] = 'Check-out date is required.';
        if (!empty($errors)) throw new Exception(implode(' ', $errors));

        $checkIn  = new DateTime($check_in_date);
        $checkOut = new DateTime($check_out_date);
        if ($checkOut <= $checkIn) throw new Exception('Check-out must be after check-in.');

        $number_of_nights = $checkIn->diff($checkOut)->days;
        $adult_guests     = max(1, $number_of_guests - $child_guests);

        // ── Build & validate room lines ─────────────────────────────────────
        $room_lines = [];
        for ($li = 0; $li < count($rl_room_ids); $li++) {
            $rid = (int)($rl_room_ids[$li] ?? 0);
            $qty = max(1, min(20, (int)($rl_qtys[$li] ?? 1)));
            $occ = in_array($rl_occupancies[$li] ?? '', ['single', 'double', 'triple']) ? $rl_occupancies[$li] : 'double';
            $ovr = isset($rl_overrides[$li]) && trim((string)$rl_overrides[$li]) !== '' ? (float)$rl_overrides[$li] : null;
            if ($ovr !== null && $ovr < 0) {
                throw new Exception('Price override cannot be negative.');
            }
            if ($rid <= 0) continue;

            $r_stmt = $pdo->prepare("
                SELECT r.*,
                       GREATEST(
                           r.max_guests,
                           COALESCE((SELECT MAX(ir.max_guests_override) FROM individual_rooms ir WHERE ir.room_type_id = r.id AND ir.is_active = 1), 0),
                           COALESCE((SELECT MAX(rc.max_guests_combined) FROM room_combinations rc WHERE rc.combined_room_type_id = r.id AND rc.is_active = 1), 0)
                       ) AS max_guests
                FROM rooms r
                WHERE r.id = ? AND r.is_active = 1
            ");
            $r_stmt->execute([$rid]);
            $r_data = $r_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r_data) throw new Exception('Room type ID ' . $rid . ' not found.');

            $combination_candidates = [];
            if (roomTypeHasActiveCombinations($rid)) {
                $combination_candidates = getAvailableRoomCombinations($rid, $check_in_date, $check_out_date);
                if (count($combination_candidates) < $qty) {
                    throw new Exception('Only ' . count($combination_candidates) . ' joined room combination(s) are available for "' . $r_data['name'] . '" on those dates.');
                }
                $pricingCombination = $combination_candidates[0];
                $combinedRate = $pricingCombination['price_override'] !== null && $pricingCombination['price_override'] !== ''
                    ? (float)$pricingCombination['price_override']
                    : (float)$r_data['price_per_night'];
                $r_data['price_per_night'] = $combinedRate;
                $r_data['price_single_occupancy'] = $combinedRate;
                $r_data['price_double_occupancy'] = $combinedRate;
                $r_data['price_triple_occupancy'] = $combinedRate;
                $r_data['max_guests'] = max((int)$r_data['max_guests'], (int)($pricingCombination['max_guests_combined'] ?? 0));
            }

            $policy = resolveOccupancyPolicy($r_data, null);
            if (($occ === 'single' && empty($policy['single_enabled'])) ||
                ($occ === 'double' && empty($policy['double_enabled'])) ||
                ($occ === 'triple' && empty($policy['triple_enabled']))
            ) {
                throw new Exception('Occupancy "' . $occ . '" not enabled for room "' . $r_data['name'] . '".');
            }
            if (empty($policy['children_allowed']) && $child_guests > 0) {
                throw new Exception('Children not allowed for room "' . $r_data['name'] . '".');
            }
            $room_lines[] = [
                'room_id' => $rid,
                'qty' => $qty,
                'occupancy_type' => $occ,
                'price_override' => $ovr,
                'room_data' => $r_data,
                'policy' => $policy,
                'combination_candidates' => $combination_candidates,
            ];
        }
        if (empty($room_lines)) throw new Exception('Please select at least one room type.');

        // ── Capacity guard (authoritative) ───────────────────────────────────
        // The whole party must physically fit in the allocated rooms. max_guests
        // here is already the GREATEST of the room-type cap, any individual-room
        // override, and any joined-room combination — so a large party must be
        // split across enough rooms before the booking is accepted.
        $total_capacity = 0;
        foreach ($room_lines as $line) {
            $total_capacity += (int)$line['qty'] * max(1, (int)($line['room_data']['max_guests'] ?? 1));
        }
        if ($number_of_guests > $total_capacity) {
            throw new Exception(
                'The selected rooms hold up to ' . $total_capacity . ' guest(s), but ' . $number_of_guests .
                ' were entered. Add more rooms (or a larger room type) so everyone is accommodated.'
            );
        }

        $total_rooms_booked = array_sum(array_column($room_lines, 'qty'));
        // Individual room selection only valid for single-room bookings
        if ($total_rooms_booked > 1) {
            $individual_room_id = null;
            $auto_assign = true;
        }

        // ── VAT / levy settings ──────────────────────────────────────────────
        $levy_pct_db = getSetting('tourism_levy_enabled', '0') === '1' ? (float)getSetting('tourism_levy_percent', 0) : 0.0;
        $vat_rate_db = $vat_enabled ? $vat_rate_cfg : 0.0;

        // ── Ref prefix & tentative ───────────────────────────────────────────
        $ref_prefix = getSetting('booking_reference_prefix', 'LSH');
        $ref_check  = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_reference = ?");
        $is_tentative         = ($booking_status === 'tentative') ? 1 : 0;
        $tentative_expires_at = null;
        if ($is_tentative) {
            $tentative_hours      = (int)getSetting('tentative_duration_hours', 48);
            $tentative_expires_at = date('Y-m-d H:i:s', strtotime("+{$tentative_hours} hours"));
        }

        // ── Pre-calculate pricing for all lines (to derive grand total before inserts) ──
        $grand_total_with_vat    = 0.0;
        $grand_child_supplement  = 0.0;
        $grand_levy_amount       = 0.0;
        $grand_vat_amount        = 0.0;
        foreach ($room_lines as &$line) {
            $r   = $line['room_data'];
            $occ = $line['occupancy_type'];
            $ovr = $line['price_override'];
            $qty = $line['qty'];

            $r_price = match ($occ) {
                'single' => !empty($r['price_single_occupancy']) ? (float)$r['price_single_occupancy'] : (float)$r['price_per_night'],
                'double' => !empty($r['price_double_occupancy']) ? (float)$r['price_double_occupancy'] : (float)$r['price_per_night'],
                'triple' => !empty($r['price_triple_occupancy']) ? (float)$r['price_triple_occupancy'] : (float)$r['price_per_night'],
                default  => (float)$r['price_per_night'],
            };
            $cpm = max(0.0, (float)($r['child_price_multiplier'] ?? getSetting('booking_child_price_multiplier', 50)));

            $applied_rate_plan_id    = null;
            $applied_rate_plan_label = '';
            $applied_rate_discount   = 0.0;
            if ($ovr === null) {
                $dyn                  = applyDynamicPricing($pdo, $line['room_id'], $check_in_date, $check_out_date, $number_of_nights, $r_price);
                $r_price              = $dyn['final_price'];
                $applied_rate_plan_id    = $dyn['rate_plan_id'];
                $applied_rate_plan_label = $dyn['rate_plan_label'];
                $applied_rate_discount   = $dyn['discount_amount'];
            }

            // Per-room base, then child supplement is group-level (one charge regardless of qty)
            $base_amt_per  = $r_price * $number_of_nights;
            $base_amt_all  = $base_amt_per * $qty;
            $child_sup     = ($child_guests > 0) ? ($r_price * ($cpm / 100) * $child_guests * $number_of_nights) : 0.0;
            $levy_amt      = ($ovr === null && $levy_pct_db > 0) ? round(($base_amt_all + $child_sup) * ($levy_pct_db / 100), 2) : 0.0;
            $tot_amt       = $ovr !== null ? $ovr : ($base_amt_all + $child_sup + $levy_amt);
            // VAT per installation mode: exclusive on top, inclusive extracted
            // from the priced amount (total never inflates), off zero.
            $vp            = vat_components($tot_amt);
            $vat_amt       = $vp['vat'];
            $twv           = $vp['total'];  // covers ALL qty rooms

            // Per-row INSERT amounts: spread base across rows; child supplement on first row only
            $row_levy_per   = ($ovr === null && $levy_pct_db > 0) ? round($base_amt_per * ($levy_pct_db / 100), 2) : 0.0;
            $row_tot_per    = $base_amt_per + $row_levy_per;
            $row_vp         = vat_components($row_tot_per);
            $row_vat_per    = $row_vp['vat'];
            $row_twv_per    = $row_vp['total'];
            $first_levy_add = ($ovr === null && $levy_pct_db > 0) ? round($child_sup * ($levy_pct_db / 100), 2) : 0.0;
            $first_tot_add  = $child_sup + $first_levy_add;
            $first_vp       = vat_components($first_tot_add);
            $first_vat_add  = $first_vp['vat'];
            $first_twv_add  = $first_vp['total'];

            $line['calc'] = [
                'room_price'         => $r_price,
                'cpm'                => $cpm,
                'base_amount'        => $base_amt_all,
                'base_amount_per'    => $base_amt_per,
                'child_supplement'   => $child_sup,
                'levy_pct'           => $ovr !== null ? 0.0 : $levy_pct_db,
                'levy_amount'        => $levy_amt,
                'total_amount'       => $tot_amt,
                'vat_rate'           => $vat_rate_db,
                'vat_amount'         => $vat_amt,
                'total_with_vat'     => $twv,
                // per-row amounts
                'row_tot_per'        => $row_tot_per,
                'row_levy_per'       => $row_levy_per,
                'row_vat_per'        => $row_vat_per,
                'row_twv_per'        => $row_twv_per,
                'first_tot_add'      => $first_tot_add,
                'first_levy_add'     => $first_levy_add,
                'first_vat_add'      => $first_vat_add,
                'first_twv_add'      => $first_twv_add,
                'rate_plan_id'       => $applied_rate_plan_id,
                'rate_plan_label'    => $applied_rate_plan_label,
                'rate_plan_discount' => $applied_rate_discount,
            ];
            // $twv already covers all qty rooms — do NOT multiply by $qty
            $grand_total_with_vat   += $twv;
            $grand_child_supplement += $child_sup;
            $grand_levy_amount      += $levy_amt;
            $grand_vat_amount       += $vat_amt;
        }
        unset($line);

        // Derive payment_status from amount collected vs grand total
        if ($payment_received && $amount_collected > 0) {
            if ($amount_collected >= ($grand_total_with_vat - BALANCE_TOLERANCE)) {
                $payment_status_val = 'paid';
                $payment_type_val   = 'full_payment';
            } else {
                $payment_status_val = 'partial';
                $payment_type_val   = 'partial_payment';
            }
        } else {
            $payment_status_val = 'unpaid';
            $payment_type_val   = '';
            $amount_collected   = 0.0;
        }

        // ── Transaction: create bookings for all lines ───────────────────────
        $pdo->beginTransaction();
        foreach (array_unique(array_column($room_lines, 'room_id')) as $lock_rid) {
            $pdo->prepare("SELECT id FROM rooms WHERE id = ? FOR UPDATE")->execute([$lock_rid]);
        }

        // ── Server-side availability guard (prevents double-booking race) ─────
        // Re-verify capacity for every room type inside the locked transaction.
        // Sum requested qty per room_id (multiple lines may target the same type).
        $requested_qty_by_room = [];
        foreach ($room_lines as $line) {
            $rid = (int)$line['room_id'];
            $requested_qty_by_room[$rid] = ($requested_qty_by_room[$rid] ?? 0) + (int)$line['qty'];
        }
        foreach ($requested_qty_by_room as $rid => $needed) {
            $avail = checkRoomAvailability($rid, $check_in_date, $check_out_date, null, $child_guests, $needed);
            if (empty($avail['available'])) {
                throw new Exception($avail['error'] ?? 'Selected room is no longer available for those dates.');
            }
            $remaining = (int)($avail['remaining_rooms'] ?? 0);
            if ($remaining < $needed) {
                $room_label = $avail['room']['name'] ?? ('room type ' . $rid);
                throw new Exception('Only ' . max(0, $remaining) . ' room(s) of "' . $room_label . '" remain available for those dates, but ' . $needed . ' were requested.');
            }
        }

        $created_bookings = [];
        $booking_number   = 0;
        $primary_booking_db_id = null;   // tracks the first-inserted booking's DB id

        foreach ($room_lines as $line) {
            $r   = $line['room_data'];
            $c   = $line['calc'];
            $occ = $line['occupancy_type'];
            $qty = $line['qty'];

            for ($room_idx = 0; $room_idx < $qty; $room_idx++) {
                do {
                    $booking_reference = $ref_prefix . date('Y') . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
                    $ref_check->execute([$booking_reference]);
                    $ref_exists = (int)$ref_check->fetchColumn() > 0;
                } while ($ref_exists);

                $is_first_in_line = ($room_idx === 0);

                // Per-row financials: child supplement on first row only
                $row_tot  = $c['row_tot_per']  + ($is_first_in_line ? $c['first_tot_add']  : 0.0);
                $row_levy = $c['row_levy_per'] + ($is_first_in_line ? $c['first_levy_add'] : 0.0);
                $row_vat  = $c['row_vat_per']  + ($is_first_in_line ? $c['first_vat_add']  : 0.0);
                $row_twv  = $c['row_twv_per']  + ($is_first_in_line ? $c['first_twv_add']  : 0.0);
                $row_child_sup = $is_first_in_line ? $c['child_supplement'] : 0.0;

                // Distribute guests: adults spread evenly, children on first row only
                $adults_floor  = (int)floor($adult_guests / $qty);
                $row_adults    = ($room_idx < $qty - 1) ? $adults_floor : ($adult_guests - $adults_floor * ($qty - 1));
                $row_children  = $is_first_in_line ? $child_guests : 0;
                $row_guests    = $row_adults + $row_children;

                $is_primary = ($booking_number === 0);
                $__bookingClientUuid = idem_normalize_uuid(
                    $is_primary
                        ? ($__incomingClientUuid ?? null)
                        : (($__incomingClientUuid ?? '') . ':b' . $booking_number)
                );

                $this_individual_room_id = null;
                if ($is_primary && !$auto_assign && $individual_room_id) {
                    $ir_check2 = $pdo->prepare("SELECT id, room_type_id FROM individual_rooms WHERE id = ? AND is_active = 1");
                    $ir_check2->execute([$individual_room_id]);
                    $ir_row2 = $ir_check2->fetch(PDO::FETCH_ASSOC);
                    if ($ir_row2 && (int)$ir_row2['room_type_id'] === $line['room_id']) {
                        $this_individual_room_id = $individual_room_id;
                    }
                }

                // primary_booking_id: NULL for the primary, primary's DB id for all secondaries
                $this_primary_booking_id = $is_primary ? null : $primary_booking_db_id;

                $insert = $pdo->prepare("
                    INSERT INTO bookings (
                        booking_reference, room_id, individual_room_id,
                        guest_name, guest_email, guest_phone, guest_country, guest_address,
                        number_of_guests, adult_guests, child_guests, child_price_multiplier,
                        check_in_date, check_out_date, number_of_nights,
                        total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
                        vat_rate, vat_amount, total_with_vat,
                        special_requests, status, payment_status,
                        is_tentative, tentative_expires_at, occupancy_type,
                        client_uuid, rate_plan_id, rate_plan_label, rate_plan_discount,
                        primary_booking_id,
                        created_at
                    ) VALUES (
                        ?,?,?, ?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?, ?,?,?, ?,?,?, ?,?,?, ?,?,?,?, ?, NOW()
                    )
                ");
                $insert->execute([
                    $booking_reference,
                    $line['room_id'],
                    $this_individual_room_id,
                    $guest_name,
                    $guest_email,
                    $guest_phone,
                    $guest_country,
                    $guest_address,
                    $row_guests,
                    $row_adults,
                    $row_children,
                    $c['cpm'],
                    $check_in_date,
                    $check_out_date,
                    $number_of_nights,
                    $row_tot,
                    $row_child_sup,
                    $row_levy,
                    $c['levy_pct'],
                    $c['vat_rate'],
                    $row_vat,
                    $row_twv,
                    $special_requests,
                    $booking_status,
                    $payment_status_val,
                    $is_tentative,
                    $tentative_expires_at,
                    $occ,
                    $__bookingClientUuid,
                    $c['rate_plan_id'],
                    $c['rate_plan_label'] ?: null,
                    $c['rate_plan_discount'] ?: null,
                    $this_primary_booking_id,
                ]);

                $new_booking_id = (int)$pdo->lastInsertId();
                if ($is_primary) $primary_booking_db_id = $new_booking_id;
                $assigned_combination_id = null;
                if (roomTypeHasActiveCombinations((int)$line['room_id'])) {
                    $availableCombinations = getAvailableRoomCombinations((int)$line['room_id'], $check_in_date, $check_out_date, $new_booking_id);
                    if (empty($availableCombinations)) {
                        throw new Exception('Joined rooms are no longer available for ' . $r['name'] . '.');
                    }
                    $assignment = assignRoomCombinationToBooking($new_booking_id, (int)$availableCombinations[0]['id'], (int)$user['id']);
                    if (empty($assignment['success'])) {
                        throw new Exception($assignment['message'] ?: 'Failed to reserve joined rooms for ' . $r['name'] . '.');
                    }
                    $assigned_combination_id = (int)$availableCombinations[0]['id'];
                    $this_individual_room_id = (int)($assignment['room_ids'][0] ?? 0) ?: $this_individual_room_id;
                }
                $created_bookings[] = [
                    'id'             => $new_booking_id,
                    'ref'            => $booking_reference,
                    'room_name'      => $r['name'],
                    'total_with_vat' => $row_twv,
                    'manual_room_id' => $this_individual_room_id,
                    'room_combination_id' => $assigned_combination_id,
                ];

                if ($booking_status === 'confirmed') {
                    $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")
                        ->execute([$line['room_id']]);
                }
                // Individual room assignment is done AFTER commit (assignIndividualRoomToBooking
                // starts its own transaction and cannot nest inside the outer one).

                $group_label = $total_rooms_booked > 1
                    ? ' [Group: room ' . ($booking_number + 1) . ' of ' . $total_rooms_booked . ' — ' . $r['name'] . ']'
                    : '';
                if (!empty($admin_notes)) {
                    $pdo->prepare("INSERT INTO booking_notes (booking_id, note_text, created_by) VALUES (?, ?, ?)")
                        ->execute([$new_booking_id, 'Admin note: ' . $admin_notes, $user['id']]);
                }
                if (!empty($group_reference)) {
                    $pdo->prepare("INSERT INTO booking_notes (booking_id, note_text, created_by) VALUES (?, ?, ?)")
                        ->execute([$new_booking_id, 'Group reference: ' . $group_reference, $user['id']]);
                }
                $pdo->prepare("INSERT INTO booking_notes (booking_id, note_text, created_by) VALUES (?, ?, ?)")
                    ->execute([
                        $new_booking_id,
                        'Created manually by admin (' . ($user['full_name'] ?? $user['username']) . ')' . $group_label,
                        $user['id']
                    ]);
                $booking_number++;
            }
        }

        // ── Payment record — split proportionally across all created bookings ──
        $primary_id  = $created_bookings[0]['id'];
        $primary_ref = $created_bookings[0]['ref'];

        if ($payment_received && $amount_collected > 0) {
            $payment_reference_base = 'PAY-' . date('Ymd') . '-' . str_pad($primary_id, 6, '0', STR_PAD_LEFT);
            $total_distributed      = 0.0;

            foreach ($created_bookings as $bi => $cb) {
                $cb_total = (float)($cb['total_with_vat'] ?? 0);
                $is_last  = ($bi === count($created_bookings) - 1);
                // Proportional share; last booking absorbs rounding difference
                $this_amount = $is_last
                    ? round($amount_collected - $total_distributed, 2)
                    : ($grand_total_with_vat > 0
                        ? round($amount_collected * ($cb_total / $grand_total_with_vat), 2)
                        : round($amount_collected / count($created_bookings), 2));
                $total_distributed += $this_amount;

                $pay_ref  = $payment_reference_base . ($bi > 0 ? '-' . ($bi + 1) : '');
                $pay_uuid = ($__incomingClientUuid ?? '') ? ($__incomingClientUuid . ':pay' . ($bi > 0 ? $bi : '')) : null;
                $pay_vat  = ($vat_rate_db > 0) ? round($this_amount * ($vat_rate_db / (100 + $vat_rate_db)), 2) : 0.0;
                $receipt_number = finance_next_receipt_number($pdo, date('Y-m-d'));

                $pdo->prepare("
                    INSERT INTO payments (
                        payment_reference, booking_type, booking_id, booking_reference,
                        payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                        payment_method, payment_type, payment_status,
                        receipt_number, invoice_generated, status, recorded_by, client_uuid
                    ) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, 'completed', ?, 1, 'completed', ?, ?)
                ")->execute([
                    $pay_ref,
                    $cb['id'],
                    $cb['ref'],
                    $this_amount,
                    $vat_rate_db,
                    $pay_vat,
                    $this_amount,
                    $payment_method,
                    $payment_type_val,
                    $receipt_number,
                    $user['id'],
                    $pay_uuid,
                ]);
                $pdo->prepare("UPDATE bookings SET last_payment_date = CURDATE() WHERE id = ?")->execute([$cb['id']]);
                recalculateBookingFinancials($cb['id']);
            }
        }

        foreach ($created_bookings as $cb) {
            recalculateBookingFinancials((int)$cb['id']);
        }

        $pdo->commit();
        unset($_SESSION['admin_create_booking_uuid']);

        // ── Individual room assignments (must run after commit — each starts its own transaction) ──
        foreach ($created_bookings as $cb) {
            if (!empty($cb['room_combination_id'])) {
                continue;
            }
            if (!$auto_assign && !empty($cb['manual_room_id'])) {
                assignIndividualRoomToBooking($cb['id'], (int)$cb['manual_room_id'], false, null, $user['id']);
            } else {
                autoAssignIndividualRoom($cb['id']);
            }
        }

        // ── Apply guest credit notes (must run after commit — applyCreditNote starts
        //    its own transaction). Non-fatal: the booking is already durable, so any
        //    failure here is logged and surfaced, never rolled back. Credit is applied
        //    greedily across the created bookings until the selected notes are spent or
        //    the bookings' balances are cleared. ──────────────────────────────────────
        $credit_msg = '';
        $rawCreditIds = $_POST['apply_credit_note_ids'] ?? [];
        if (!$is_tentative && is_array($rawCreditIds) && !empty($rawCreditIds)) {
            try {
                require_once '../config/credit-notes.php';

                // Email override: by default only the booking guest's own credit may be
                // applied (matched on guest_email). Staff can deliberately apply credit
                // from a different account, but only with an explicit reason, which is
                // recorded on the application notes and the audit log.
                $credit_override        = !empty($_POST['apply_credit_override']);
                $credit_override_reason = trim((string)($_POST['apply_credit_override_reason'] ?? ''));
                $credit_override_active  = $credit_override && $credit_override_reason !== '';
                if ($credit_override && !$credit_override_active) {
                    $credit_msg = ' (Note: credit override was selected without a reason, so credit from a different account was not applied.)';
                }

                // Build a queue of usable, selected credit notes.
                $cnQueue = [];
                foreach (array_unique(array_map('intval', $rawCreditIds)) as $cnId) {
                    if ($cnId <= 0) {
                        continue;
                    }
                    $cnStmt = $pdo->prepare("SELECT id, credit_note_number, balance, guest_email, status, expires_at FROM credit_notes WHERE id = ?");
                    $cnStmt->execute([$cnId]);
                    $cn = $cnStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$cn) {
                        continue;
                    }
                    if (!in_array((string)$cn['status'], ['active', 'partially_applied'], true)) {
                        continue;
                    }
                    if ($cn['expires_at'] !== null && (string)$cn['expires_at'] < date('Y-m-d')) {
                        continue;
                    }
                    if ((float)$cn['balance'] <= 0.005) {
                        continue;
                    }
                    // Security: the guest's own credit applies freely; credit belonging to
                    // a different account is only allowed under an explicit, audited override.
                    $emailMatches = strcasecmp(trim((string)$cn['guest_email']), trim((string)$guest_email)) === 0;
                    if (!$emailMatches && !$credit_override_active) {
                        continue;
                    }
                    $cnQueue[] = [
                        'id'       => (int)$cn['id'],
                        'number'   => (string)$cn['credit_note_number'],
                        'balance'  => (float)$cn['balance'],
                        'override' => !$emailMatches,
                        'cn_email' => (string)$cn['guest_email'],
                    ];
                }

                $applied_credit_total = 0.0;
                $applied_credit_numbers = [];
                foreach ($created_bookings as $cb) {
                    if (empty($cnQueue)) {
                        break;
                    }
                    $dueStmt = $pdo->prepare("SELECT amount_due FROM bookings WHERE id = ?");
                    $dueStmt->execute([$cb['id']]);
                    $due = round((float)($dueStmt->fetchColumn() ?: 0), 2);

                    foreach ($cnQueue as &$cnq) {
                        if ($due <= 0.005) {
                            break;
                        }
                        if ($cnq['balance'] <= 0.005) {
                            continue;
                        }
                        $amt = round(min($due, $cnq['balance']), 2);
                        $applyNote = 'Applied at booking creation';
                        if (!empty($cnq['override'])) {
                            $applyNote .= ' — OVERRIDE: credit from a different account (' . $cnq['cn_email'] . '). Reason: ' . $credit_override_reason;
                        }
                        $res = applyCreditNote(
                            $pdo,
                            $cnq['id'],
                            ['booking_id' => $cb['id'], 'booking_type' => 'room', 'booking_reference' => $cb['ref']],
                            $amt,
                            (int)($user['id'] ?? 0),
                            $applyNote
                        );
                        if (!empty($res['success'])) {
                            $due = round($due - $amt, 2);
                            $cnq['balance'] = (float)$res['remaining_balance'];
                            $applied_credit_total += $amt;
                            $applied_credit_numbers[$cnq['number']] = true;
                            if (!empty($cnq['override'])) {
                                rh_log_event('create-booking', 'warning', 'Guest credit applied via email override', [
                                    'booking_id'         => $cb['id'],
                                    'booking_reference'  => $cb['ref'],
                                    'credit_note'        => $cnq['number'],
                                    'credit_note_email'  => $cnq['cn_email'],
                                    'booking_email'      => $guest_email,
                                    'amount'             => $amt,
                                    'reason'             => $credit_override_reason,
                                    'by'                 => $user['username'] ?? null,
                                ]);
                            }
                        } else {
                            error_log('create-booking applyCreditNote failed for CN ' . $cnq['id'] . ': ' . ($res['error'] ?? 'unknown'));
                        }
                    }
                    unset($cnq);
                }

                if ($applied_credit_total > 0) {
                    $credit_msg = ' Applied ' . htmlspecialchars($currency_symbol) . number_format($applied_credit_total, 2)
                        . ' from credit note' . (count($applied_credit_numbers) > 1 ? 's' : '') . ' '
                        . htmlspecialchars(implode(', ', array_keys($applied_credit_numbers))) . '.';
                }
            } catch (\Throwable $creditEx) {
                error_log('create-booking credit application error: ' . $creditEx->getMessage());
                $credit_msg = ' (Note: booking created, but guest credit could not be applied automatically — apply it from the booking page.)';
            }
        }

        // ── Audit logs ───────────────────────────────────────────────────────
        foreach ($created_bookings as $cb) {
            rh_log_event('create-booking', 'info', 'Booking created by admin', [
                'booking_id'  => $cb['id'],
                'ref' => $cb['ref'],
                'guest'       => $guest_name,
                'total_rooms' => $total_rooms_booked,
                'status'      => $booking_status,
                'by' => $user['username'] ?? null,
            ]);
            logBookingAudit(
                $cb['id'],
                'created',
                null,
                ['status' => $booking_status, 'payment_status' => $payment_status_val, 'total_rooms' => $total_rooms_booked],
                'Created manually by admin (' . ($user['full_name'] ?? ($user['username'] ?? '')) . ')',
                $cb['ref']
            );
        }

        // ── Email ────────────────────────────────────────────────────────────
        $email_msg = '';
        if ($send_email) {
            require_once '../config/email.php';
            $fl = $room_lines[0];
            $fc = $fl['calc'];
            // Room name label: list all room names for multi-room bookings
            $room_name_for_email = count($created_bookings) > 1
                ? implode(', ', array_unique(array_map(fn($cb) => $cb['room_name'], $created_bookings)))
                : $fl['room_data']['name'];
            $booking_data = [
                'id'                     => $primary_id,
                'booking_reference'      => $primary_ref,
                'room_id'                => $fl['room_id'],
                'guest_name'             => $guest_name,
                'guest_email'            => $guest_email,
                'guest_phone'            => $guest_phone,
                'check_in_date'          => $check_in_date,
                'check_out_date'         => $check_out_date,
                'number_of_nights'       => $number_of_nights,
                'number_of_guests'       => $number_of_guests,
                'adult_guests'           => $adult_guests,
                'child_guests'           => $child_guests,
                'child_price_multiplier' => $fc['cpm'],
                // Use grand totals across all rooms — correct for multi-room group bookings
                'child_supplement_total' => $grand_child_supplement,
                'total_amount'           => $grand_total_with_vat,
                'vat_rate'               => $fc['vat_rate'],
                'vat_amount'             => $grand_vat_amount,
                'total_with_vat'         => $grand_total_with_vat,
                'tourism_levy_amount'    => $grand_levy_amount,
                'tourism_levy_percent'   => $fc['levy_pct'],
                'special_requests'       => $special_requests,
                'status'                 => $booking_status,
                'is_tentative'           => $is_tentative,
                'tentative_expires_at'   => $tentative_expires_at,
                'occupancy_type'         => $fl['occupancy_type'],
                'room_name'              => $room_name_for_email,
            ];
            if ($payment_received && $amount_collected > 0) {
                // Payment taken — send invoice email then receipt email
                try {
                    require_once '../config/invoice.php';
                    $inv_cc = [];
                    $inv_recipients_cfg = getEmailSetting('invoice_recipients', '');
                    $inv_smtp_user = getEmailSetting('smtp_username', '');
                    if (!empty($inv_recipients_cfg)) {
                        $inv_cc = array_filter(array_map('trim', explode(',', $inv_recipients_cfg)));
                    }
                    if (!empty($inv_smtp_user) && !in_array($inv_smtp_user, $inv_cc)) {
                        $inv_cc[] = $inv_smtp_user;
                    }
                    $inv_result = sendPaymentInvoiceEmailWithCC($primary_id, $inv_cc);
                    $email_msg = $inv_result['success']
                        ? ' Invoice emailed to guest.'
                        : ' (Invoice email failed: ' . htmlspecialchars($inv_result['message'] ?? 'unknown error') . ')';
                } catch (\Throwable $invEx) {
                    error_log('Invoice email failed for ' . $primary_ref . ': ' . $invEx->getMessage());
                    $email_msg = ' (Invoice email failed)';
                }
                // Send receipt email with PDF for the primary booking's latest payment
                try {
                    require_once '../config/receipts.php';
                    $latestPayStmt = $pdo->prepare("SELECT id FROM payments WHERE booking_type = 'room' AND booking_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
                    $latestPayStmt->execute([$primary_id]);
                    $latestPayId = (int)($latestPayStmt->fetchColumn() ?: 0);
                    if ($latestPayId > 0) {
                        $rcpt_result = receipt_auto_send($pdo, $latestPayId, $user);
                        if ($rcpt_result['success']) {
                            $email_msg .= ' Receipt emailed.';
                        }
                    }
                } catch (\Throwable $rcptEx) {
                    error_log('Receipt email failed for ' . $primary_ref . ': ' . $rcptEx->getMessage());
                }
            } elseif ($is_tentative) {
                // Tentative booking confirmation
                $email_result = sendTentativeBookingConfirmedEmail($booking_data);
                $email_msg = $email_result['success']
                    ? ' Confirmation email sent.'
                    : ' (Email failed: ' . htmlspecialchars($email_result['message'] ?? 'unknown error') . ')';
            } else {
                // No payment yet — send booking confirmation + proforma invoice
                if ($booking_status === 'confirmed') {
                    $email_result = sendBookingConfirmedEmail($booking_data);
                } else {
                    $email_result = sendBookingReceivedEmail($booking_data);
                }
                $email_msg = $email_result['success']
                    ? ' Confirmation email sent.'
                    : ' (Email failed: ' . htmlspecialchars($email_result['message'] ?? 'unknown error') . ')';

                // Also send proforma invoice showing balance due
                if (in_array($booking_status, ['confirmed', 'pending'], true)) {
                    try {
                        if (!function_exists('sendTentativeQuotationEmail')) {
                            require_once '../config/email.php';
                        }
                        $qp_stmt = $pdo->prepare(
                            "SELECT b.*, r.name AS room_name, r.price_per_night, r.short_description,
                                    r.bed_type, r.size_sqm, r.max_guests
                             FROM bookings b JOIN rooms r ON b.room_id = r.id
                             WHERE b.id = ? LIMIT 1"
                        );
                        $qp_stmt->execute([$primary_id]);
                        $qp_booking = $qp_stmt->fetch(PDO::FETCH_ASSOC);
                        if ($qp_booking) {
                            $qp_result = sendTentativeQuotationEmail($qp_booking, [
                                'valid_days'      => 30,
                                'quotation_notes' => 'Payment is due upon arrival. Please bring this invoice with you.',
                                'attach_pdf'      => true,
                                'send_whatsapp'   => false,
                            ]);
                            if (!empty($qp_result['success'])) {
                                $email_msg .= ' Invoice sent to guest.';
                            }
                        }
                    } catch (\Throwable $qpEx) {
                        error_log('Proforma invoice email failed for ' . $primary_ref . ': ' . $qpEx->getMessage());
                    }
                }
            }

            // ── Admin CC: send a copy to the hotel admin for admin-created bookings ──
            try {
                $adminCcEmail = trim((string)getEmailSetting('email_admin_email', ''));
                if (empty($adminCcEmail)) {
                    $adminCcEmail = trim((string)getEmailSetting('smtp_username', ''));
                }
                if (!empty($adminCcEmail) && filter_var($adminCcEmail, FILTER_VALIDATE_EMAIL)) {
                    $adminCcName      = getSetting('site_name', 'Admin');
                    $adminBookingUrl  = rtrim((string)getSetting('site_url', ''), '/') . '/admin/booking-details.php?id=' . $primary_id;
                    $adminCreatedBy   = htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Admin');
                    $adminCcSubject   = '[Admin Copy] New Booking Created — ' . htmlspecialchars($primary_ref);
                    $adminCcBody      = '<h2 style="color:#8B7355;">Admin Copy — Booking Created</h2>'
                        . '<p>A new booking has been created by <strong>' . $adminCreatedBy . '</strong>.</p>'
                        . '<p><strong>Reference:</strong> ' . htmlspecialchars($primary_ref) . '<br>'
                        . '<strong>Guest:</strong> ' . htmlspecialchars($guest_name) . ' (' . htmlspecialchars($guest_email) . ')<br>'
                        . '<strong>Room(s):</strong> ' . htmlspecialchars($room_name_for_email) . '<br>'
                        . '<strong>Check-in:</strong> ' . htmlspecialchars($check_in_date) . '<br>'
                        . '<strong>Check-out:</strong> ' . htmlspecialchars($check_out_date) . '<br>'
                        . '<strong>Status:</strong> ' . htmlspecialchars($booking_status) . '</p>'
                        . '<p><a href="' . htmlspecialchars($adminBookingUrl) . '" style="background:#8B7355;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">View Booking</a></p>';
                    sendEmail($adminCcEmail, $adminCcName, $adminCcSubject, $adminCcBody);
                }
            } catch (Throwable $adminCcEx) {
                error_log('Admin CC email failed for booking ' . $primary_ref . ': ' . $adminCcEx->getMessage());
            }
        }

        // ── Quotation PDF (tentative bookings only) ───────────────────────────
        if ($is_tentative && !empty($_POST['send_quotation'])) {
            $valid_days = max(1, (int)($_POST['quotation_valid_days'] ?? 7));
            $q_notes    = trim($_POST['quotation_notes'] ?? '');
            // Re-fetch the saved booking with room details for the PDF generator
            $q_stmt = $pdo->prepare(
                "SELECT b.*, r.name AS room_name, r.price_per_night, r.short_description,
                        r.bed_type, r.size_sqm, r.max_guests
                 FROM bookings b
                 JOIN rooms r ON b.room_id = r.id
                 WHERE b.id = ? LIMIT 1"
            );
            $q_stmt->execute([$primary_id]);
            $q_booking = $q_stmt->fetch(PDO::FETCH_ASSOC);
            if ($q_booking) {
                if (!function_exists('sendTentativeQuotationEmail')) {
                    require_once '../config/email.php';
                }
                $qt_result = sendTentativeQuotationEmail($q_booking, [
                    'valid_days'      => $valid_days,
                    'quotation_notes' => $q_notes,
                    'attach_pdf'      => true,
                ]);
                if (!empty($qt_result['success'])) {
                    $pdo->prepare("UPDATE bookings SET last_quotation_sent_at = NOW() WHERE id = ?")
                        ->execute([$primary_id]);
                    $email_msg .= ' Quotation PDF sent.';
                } else {
                    $email_msg .= ' (Quotation failed: ' . htmlspecialchars($qt_result['message'] ?? 'unknown error') . ')';
                }
            }
        }

        $rooms_suffix = $total_rooms_booked > 1 ? ' (' . $total_rooms_booked . ' rooms)' : '';
        $_SESSION['flash_message'] = [
            'type' => 'success',
            'text' => 'Booking ' . $primary_ref . $rooms_suffix . ' created successfully!' . ($credit_msg ?? '') . $email_msg,
        ];
        header('Location: booking-details.php?id=' . $primary_id);
        exit;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
        rh_log_event('create-booking', 'error', 'Booking creation failed: ' . $e->getMessage(), [
            'by'        => $user['username'] ?? null,
            'exception' => get_class($e),
        ]);
    }
}

// ── Build rooms JSON for JavaScript ──────────────────────────────────────────
$rooms_json = json_encode(array_map(function ($r) {
    $policy = resolveOccupancyPolicy($r, null);
    return [
        'id'                     => (int)$r['id'],
        'name'                   => $r['name'],
        'max_guests'             => (int)$r['max_guests'],
        'total_rooms'            => (int)($r['total_rooms'] ?? 1),
        'price_per_night'        => (float)$r['price_per_night'],
        'price_single'           => (float)($r['price_single_occupancy'] ?? $r['price_per_night']),
        'price_double'           => (float)($r['price_double_occupancy'] ?? $r['price_per_night'] * 1.2),
        'price_triple'           => (float)($r['price_triple_occupancy'] ?? $r['price_per_night'] * 1.4),
        'child_price_multiplier' => isset($r['child_price_multiplier'])
            ? (float)$r['child_price_multiplier']
            : (float)getSetting('booking_child_price_multiplier', getSetting('child_guest_price_multiplier', 50)),
        'rooms_available'        => (int)$r['rooms_available'],
        'single_enabled'         => (int)$policy['single_enabled'],
        'double_enabled'         => (int)$policy['double_enabled'],
        'triple_enabled'         => (int)$policy['triple_enabled'],
        'children_allowed'       => (int)$policy['children_allowed'],
    ];
}, $rooms));

$rate_plans_json = '[]';
try {
    $rate_plans_json = json_encode(array_map(static function (array $plan): array {
        return [
            'id' => (int)$plan['id'],
            'name' => (string)$plan['name'],
            'applies_to' => (string)$plan['applies_to'],
            'room_type_ids' => $plan['room_type_ids'] ?? null,
            'rule_type' => (string)$plan['rule_type'],
            'start_date' => $plan['start_date'] ?? null,
            'end_date' => $plan['end_date'] ?? null,
            'days_of_week' => $plan['days_of_week'] ?? null,
            'min_nights' => $plan['min_nights'] !== null ? (int)$plan['min_nights'] : null,
            'max_nights' => $plan['max_nights'] !== null ? (int)$plan['max_nights'] : null,
            'days_before_min' => $plan['days_before_min'] !== null ? (int)$plan['days_before_min'] : null,
            'days_before_max' => $plan['days_before_max'] !== null ? (int)$plan['days_before_max'] : null,
            'adjustment_type' => (string)$plan['adjustment_type'],
            'adjustment_value' => (float)$plan['adjustment_value'],
            'is_stacking' => (int)$plan['is_stacking'],
        ];
    }, _fetchActiveRatePlans($pdo))) ?: '[]';
} catch (PDOException $e) {
    error_log('create-booking rate plan JSON error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Booking — Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        .create-booking-container {
            max-width: 860px;
            margin: 0 auto;
            padding-bottom: 60px;
        }

        .form-card {
            background: var(--admin-card-bg, #fff);
            border: 1px solid var(--admin-border, #e8e0d8);
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 20px;
        }

        .form-card h3 {
            font-family: 'Jost', sans-serif;
            font-size: 15px;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--admin-text, #2a2723);
            margin: 0 0 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-card h3 i {
            color: var(--gold, #b18247);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-row.three-col {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .form-row.one-col {
            grid-template-columns: 1fr;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--admin-text, #2a2723);
            margin-bottom: 5px;
        }

        .form-group input[type=text],
        .form-group input[type=email],
        .form-group input[type=tel],
        .form-group input[type=date],
        .form-group input[type=number],
        .form-group select,
        .form-group textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 8px 10px;
            border: 1px solid var(--admin-border, #d9d0c8);
            border-radius: 5px;
            font-family: 'Jost', sans-serif;
            font-size: 14px;
            color: var(--admin-text, #2a2723);
            background: #fff;
        }

        .form-group select:disabled,
        .form-group input:disabled {
            background: #f5f3f0;
            cursor: not-allowed;
            color: #999;
        }

        .form-group small {
            display: block;
            font-size: 11px;
            color: #888;
            margin-top: 3px;
        }

        .required {
            color: #c0392b;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }

        .checkbox-group label {
            margin-bottom: 0;
            font-size: 14px;
        }

        .ir-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 260px;
            overflow-y: auto;
            margin-top: 10px;
        }

        .ir-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border: 1px solid #e0d8d0;
            border-radius: 6px;
            cursor: pointer;
            transition: border-color .15s, background .15s;
        }

        .ir-card.available:hover {
            border-color: var(--gold, #b18247);
            background: #fdf8f2;
        }

        .ir-card.unavailable {
            opacity: .55;
            cursor: not-allowed;
        }

        .ir-card input[type=radio] {
            accent-color: var(--gold, #b18247);
        }

        .ir-card-label {
            font-size: 13px;
            font-weight: 500;
        }

        .ir-card-status {
            font-size: 11px;
            color: #888;
            margin-left: auto;
        }

        .ir-card.unavailable .ir-card-status {
            color: #c0392b;
        }

        .accounting-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .accounting-table td {
            padding: 6px 0;
        }

        .accounting-table td:last-child {
            text-align: right;
            font-weight: 500;
        }

        .accounting-table tr.subtotal td {
            border-top: 1px solid #e0d8d0;
            padding-top: 8px;
            font-weight: 600;
        }

        .accounting-table tr.total-row td {
            border-top: 2px solid var(--gold, #b18247);
            padding-top: 10px;
            font-size: 16px;
            font-weight: 700;
            color: var(--gold, #b18247);
        }

        .accounting-table tr.tax-row td {
            font-size: 13px;
            color: #666;
        }

        .payment-toggle-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        .payment-toggle-label input[type=checkbox] {
            width: 18px;
            height: 18px;
            accent-color: var(--gold, #b18247);
        }

        .payment-details-section {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #e0d8d0;
            display: none;
        }

        .payment-details-section.open {
            display: block;
        }

        .qty-stepper {
            display: flex;
            align-items: center;
            gap: 0;
            border: 1px solid #d9d0c8;
            border-radius: 5px;
            overflow: hidden;
            width: fit-content;
        }

        .qty-stepper button {
            width: 32px;
            height: 36px;
            border: none;
            background: #f5f3f0;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            color: #555;
        }

        .qty-stepper button:hover {
            background: #ece7e1;
        }

        .qty-stepper input {
            width: 52px;
            height: 36px;
            border: none;
            border-left: 1px solid #d9d0c8;
            border-right: 1px solid #d9d0c8;
            text-align: center;
            font-size: 15px;
            font-weight: 600;
            -moz-appearance: textfield;
            appearance: textfield;
        }

        .qty-stepper input::-webkit-inner-spin-button,
        .qty-stepper input::-webkit-outer-spin-button {
            -webkit-appearance: none;
        }

        .btn-create {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--gold, #b18247);
            color: #fff;
            border: none;
            padding: 14px 32px;
            border-radius: 6px;
            font-family: 'Jost', sans-serif;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }

        .btn-create:hover {
            background: #9a6f3b;
        }

        /* ── Section step bar ─────────────────────────────────────────── */
        .cb-step-bar {
            display: flex;
            justify-content: center;
            margin-bottom: 28px;
            position: sticky;
            top: 0;
            z-index: 90;
            background: var(--cream, #F3ECE4);
            padding: 10px 0 8px;
            box-shadow: 0 2px 8px rgba(35,31,28,0.08);
        }
        .cb-step-track {
            display: flex;
            align-items: center;
            gap: 0;
            flex-wrap: wrap;
            justify-content: center;
            gap: 4px;
        }
        .cb-step {
            display: flex;
            align-items: center;
            gap: 7px;
            background: none;
            border: 2px solid #D2C8BC;
            border-radius: 24px;
            padding: 6px 14px 6px 10px;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            color: #999;
            transition: all 0.2s;
        }
        .cb-step:hover { border-color: #8A775F; color: #8A775F; }
        .cb-step.active { border-color: #B18247; background: #B18247; color: #fff; }
        .cb-step.done { border-color: #6b9e73; background: #edf7ee; color: #4a7a53; }
        .cb-step-num {
            width: 22px; height: 22px;
            border-radius: 50%;
            background: rgba(0,0,0,0.12);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; flex-shrink: 0;
        }
        .cb-step.active .cb-step-num { background: rgba(255,255,255,0.3); }
        .cb-step.done .cb-step-num { background: #6b9e73; color: #fff; }
        .cb-step-label { font-size: 12px; white-space: nowrap; }
        .cb-step-sep { color: #ccc; font-size: 16px; margin: 0 2px; user-select: none; }

        /* ── Section nav buttons ──────────────────────────────────────── */
        .cb-section-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #EDE8E0;
        }
        .cb-nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 20px;
            border-radius: 6px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid #B18247;
            transition: all 0.2s;
        }
        .cb-nav-next { background: #B18247; color: #fff; }
        .cb-nav-next:hover { background: #9a6f3b; border-color: #9a6f3b; }
        .cb-nav-prev { background: #fff; color: #8A775F; }
        .cb-nav-prev:hover { background: #FAF6F0; }
        .cb-nav-prev:first-child:last-child { margin-left: auto; }
        .cb-section-error {
            font-size: 12px;
            color: #c0392b;
            background: #fdf3f2;
            border: 1px solid #f5b7b1;
            border-radius: 4px;
            padding: 7px 12px;
            margin-top: 10px;
            display: none;
        }
        .cb-field-error { border-color: #c0392b !important; }

        .room-line {
            border: 1px solid #e8e0d8;
            border-radius: 7px;
            padding: 14px;
            margin-bottom: 10px;
            background: #fafaf8;
        }

        .room-line-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .room-line-title {
            font-size: 13px;
            font-weight: 600;
            color: #555;
        }

        .room-line-remove {
            background: none;
            border: 1px solid #e8d5d0;
            color: #c0392b;
            font-size: 12px;
            cursor: pointer;
            padding: 3px 8px;
            border-radius: 4px;
            transition: background .15s;
        }

        .room-line-remove:hover {
            background: #fff0ee;
        }

        .line-subtotal {
            font-size: 13px;
            font-weight: 700;
            color: var(--gold, #b18247);
            line-height: 36px;
        }

        .group-summary {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #e8f4fd;
            border-left: 3px solid #3498db;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 14px;
            font-size: 13px;
            color: #1a5276;
        }

        .add-room-line-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: 1px dashed var(--gold, #b18247);
            color: var(--gold, #b18247);
            padding: 8px 16px;
            border-radius: 6px;
            font-family: 'Jost', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            transition: background .15s;
        }

        .add-room-line-btn:hover {
            background: #fdf8f2;
        }

        /* ── Availability summary panel ─────────────────────────────────── */
        .avail-panel {
            display: none;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .avail-panel.visible { display: flex; }
        .avail-card {
            flex: 1; min-width: 140px;
            border: 2px solid transparent;
            border-radius: 8px; padding: 13px 15px;
            cursor: pointer;
            transition: border-color .15s, transform .12s, box-shadow .12s;
            position: relative;
        }
        .avail-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.08); }
        .avail-card.ac-available { background: #f0faf4; border-color: #a3d5b3; }
        .avail-card.ac-unavailable { background: #fdf0f0; border-color: #f5b8b8; cursor: not-allowed; opacity: .75; }
        .avail-card.ac-selected { border-color: var(--gold, #b18247); box-shadow: 0 0 0 3px rgba(177,130,71,.15); }
        .avail-card-count { font-size: 24px; font-weight: 700; line-height: 1; }
        .ac-available .avail-card-count { color: #1a7a3c; }
        .ac-unavailable .avail-card-count { color: #c0392b; }
        .avail-card-name { font-size: 13px; font-weight: 600; color: #2a2723; margin-top: 5px; }
        .avail-card-price { font-size: 11px; color: #7a7068; margin-top: 3px; }
        .avail-card-tag {
            display: inline-block; font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .04em;
            border-radius: 8px; padding: 2px 7px; margin-top: 6px;
        }
        .ac-available .avail-card-tag { background: #d4edda; color: #1a6632; }
        .ac-unavailable .avail-card-tag { background: #f8d7da; color: #721c24; }

        @media (max-width: 640px) {

            .form-row,
            .form-row.three-col {
                grid-template-columns: 1fr;
            }
        }

        /* ── Returning Guest Lookup ─────────────────────────────────────── */
        .rg-lookup-bar {
            position: relative;
            margin-bottom: 18px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--admin-border, #e8e0d8);
        }
        .rg-lookup-bar > label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--admin-muted, #7a7068);
            margin-bottom: 6px;
        }
        .rg-search-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .rg-search-icon {
            position: absolute;
            left: 11px;
            color: var(--admin-muted, #9a9088);
            pointer-events: none;
            font-size: 13px;
        }
        .rg-search-input {
            width: 100%;
            padding: 9px 12px 9px 34px;
            font-size: 14px;
            border: 1px solid var(--admin-border, #e0d8d0);
            border-radius: 6px;
            background: var(--admin-card-bg, #fff);
            color: var(--admin-text, #2a2723);
            outline: none;
            transition: border-color .15s;
            font-family: inherit;
        }
        .rg-search-input:focus {
            border-color: var(--gold, #b18247);
            box-shadow: 0 0 0 2px rgba(177,130,71,.12);
        }
        .rg-clear-btn {
            position: absolute;
            right: 10px;
            background: none;
            border: none;
            color: #9a9088;
            cursor: pointer;
            padding: 4px;
            font-size: 14px;
            line-height: 1;
            display: none;
        }
        .rg-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0; right: 0;
            z-index: 200;
            background: #fff;
            border: 1px solid var(--admin-border, #e0d8d0);
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,.12);
            overflow: hidden;
            display: none;
        }
        .rg-dropdown.open { display: block; }
        .rg-dropdown-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 14px;
            cursor: pointer;
            transition: background .1s;
            border-bottom: 1px solid #f0ebe4;
        }
        .rg-dropdown-item:last-child { border-bottom: none; }
        .rg-dropdown-item:hover,
        .rg-dropdown-item.focused { background: #fdf9f5; }
        .rg-dropdown-avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #b18247, #8a6535);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 700; color: #fff;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .rg-dropdown-info { flex: 1; min-width: 0; }
        .rg-dropdown-name {
            font-size: 14px; font-weight: 600;
            color: var(--admin-text, #2a2723);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .rg-dropdown-meta {
            font-size: 12px;
            color: var(--admin-muted, #7a7068);
            margin-top: 2px;
        }
        .rg-dropdown-stays {
            font-size: 11px;
            background: #f4ede3;
            color: #8a5c1a;
            border-radius: 10px;
            padding: 2px 8px;
            white-space: nowrap;
            font-weight: 600;
            flex-shrink: 0;
            align-self: center;
        }
        .rg-dropdown-msg {
            padding: 12px 16px;
            font-size: 13px;
            color: var(--admin-muted, #7a7068);
            text-align: center;
        }
        .rg-selected-card {
            display: none;
            background: linear-gradient(135deg, #fdf9f4, #faf4ec);
            border: 1px solid #dbc89a;
            border-radius: 8px;
            padding: 14px 16px;
            margin-top: 10px;
            position: relative;
        }
        .rg-selected-card.visible { display: flex; gap: 14px; align-items: flex-start; }
        .rg-selected-avatar {
            width: 44px; height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #b18247, #7a5c28);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 700; color: #fff;
            flex-shrink: 0;
        }
        .rg-selected-body { flex: 1; min-width: 0; }
        .rg-selected-name { font-size: 15px; font-weight: 700; color: #2a2723; }
        .rg-selected-badges {
            display: flex; flex-wrap: wrap; gap: 6px;
            margin: 6px 0;
        }
        .rg-badge {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 11px; font-weight: 600;
            border-radius: 10px; padding: 3px 9px;
        }
        .rg-badge--returning { background: #d4edda; color: #1a6632; border: 1px solid #a3d5b3; }
        .rg-badge--stays    { background: #fde8c8; color: #834a00; border: 1px solid #f0c478; }
        .rg-badge--spend    { background: #e8f0fb; color: #1a3e7a; border: 1px solid #b3c8ef; }
        .rg-badge--new      { background: #e8f4e8; color: #2d6a2d; border: 1px solid #a8d4a8; }
        .rg-selected-meta   { font-size: 12px; color: #6a6058; margin-top: 4px; }
        .rg-selected-clear {
            position: absolute; top: 10px; right: 12px;
            background: none; border: none;
            color: #9a9088; cursor: pointer;
            font-size: 15px; padding: 2px 4px;
        }
        .rg-selected-clear:hover { color: #c0392b; }
        .rg-spinner {
            display: none;
            position: absolute; right: 36px;
            color: var(--gold, #b18247);
            font-size: 13px;
        }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="create-booking-container">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-plus-circle" style="color: var(--gold);"></i> Create Booking</h1>
                <a href="bookings.php" style="color: var(--gold); text-decoration: none; font-weight: 600;"><i class="fas fa-arrow-left"></i> Back to Bookings</a>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" id="createBookingForm" data-offline-queue="1">
                <?php
                if (empty($_SESSION['admin_create_booking_uuid'])) {
                    $_SESSION['admin_create_booking_uuid'] = bin2hex(random_bytes(16));
                }
                ?>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="client_uuid" value="<?php echo htmlspecialchars($_SESSION['admin_create_booking_uuid']); ?>">
                <input type="hidden" name="create_booking" value="1">
                <input type="hidden" name="auto_assign_room" id="autoAssignRoom" value="1">
                <input type="hidden" name="payment_received" id="paymentReceivedHidden" value="0">

                <!-- ── Section progress bar ─────────────────────────────────────── -->
                <div class="cb-step-bar" id="cbStepBar">
                    <div class="cb-step-track">
                        <button type="button" class="cb-step active" id="cbStepBtn1" onclick="cbJumpTo(1)">
                            <span class="cb-step-num">1</span>
                            <span class="cb-step-label">Stay Details</span>
                        </button>
                        <span class="cb-step-sep">›</span>
                        <button type="button" class="cb-step" id="cbStepBtn2" onclick="cbJumpTo(2)">
                            <span class="cb-step-num">2</span>
                            <span class="cb-step-label">Rooms</span>
                        </button>
                        <span class="cb-step-sep">›</span>
                        <button type="button" class="cb-step" id="cbStepBtn3" onclick="cbJumpTo(3)">
                            <span class="cb-step-num">3</span>
                            <span class="cb-step-label">Guest Info</span>
                        </button>
                        <span class="cb-step-sep">›</span>
                        <button type="button" class="cb-step" id="cbStepBtn4" onclick="cbJumpTo(4)">
                            <span class="cb-step-num">4</span>
                            <span class="cb-step-label">Status</span>
                        </button>
                        <span class="cb-step-sep">›</span>
                        <button type="button" class="cb-step" id="cbStepBtn5" onclick="cbJumpTo(5)">
                            <span class="cb-step-num">5</span>
                            <span class="cb-step-label">Payment</span>
                        </button>
                        <span class="cb-step-sep">›</span>
                        <button type="button" class="cb-step" id="cbStepBtn6" onclick="cbJumpTo(6)">
                            <span class="cb-step-num">6</span>
                            <span class="cb-step-label">Review</span>
                        </button>
                    </div>
                </div>

                <!-- ══ 1. STAY DETAILS ═══════════════════════════════════════════════════ -->
                <div class="form-card" id="cbSection1">
                    <h3><i class="fas fa-calendar-alt"></i> Stay Details</h3>
                    <div class="form-row three-col">
                        <div class="form-group">
                            <label>Check-in Date <span class="required">*</span></label>
                            <input type="date" name="check_in_date" id="checkInDate" required onchange="datesChanged()"
                                min="<?php echo date('Y-m-d'); ?>"
                                value="<?php echo htmlspecialchars($_POST['check_in_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Check-out Date <span class="required">*</span></label>
                            <input type="date" name="check_out_date" id="checkOutDate" required onchange="datesChanged()"
                                min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                                value="<?php echo htmlspecialchars($_POST['check_out_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Total Guests <span class="required">*</span></label>
                            <input type="number" name="number_of_guests" id="numGuests" min="1" max="20"
                                value="<?php echo htmlspecialchars($_POST['number_of_guests'] ?? '2'); ?>"
                                required onchange="guestsChanged()">
                        </div>
                        <div class="form-group">
                            <label>Children (under 12)</label>
                            <input type="number" name="child_guests" id="childGuests" min="0" max="19"
                                value="<?php echo htmlspecialchars($_POST['child_guests'] ?? '0'); ?>"
                                onchange="calculateTotal()">
                            <small>At least 1 adult required per booking.</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Special Requests</label>
                        <textarea name="special_requests" rows="2" placeholder="Early check-in, extra pillows, allergies…"><?php echo htmlspecialchars($_POST['special_requests'] ?? ''); ?></textarea>
                    </div>
                    <div class="cb-section-error" id="cbErr1"></div>
                    <div class="cb-section-nav">
                        <span></span>
                        <button type="button" class="cb-nav-btn cb-nav-next" onclick="cbNext(1)">Rooms &amp; Availability <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>

                <!-- ══ 2. ROOM ALLOCATION ════════════════════════════════════════════════ -->
                <div class="form-card" id="cbSection2">
                    <h3><i class="fas fa-bed"></i> Room Allocation</h3>

                    <div id="roomAvailabilityNotice" style="margin-bottom:14px;padding:10px 14px;border-radius:6px;background:#fff8e1;border-left:3px solid #f0c36d;color:#7a5c00;font-size:13px;display:<?php echo (!empty($_POST['check_in_date']) && !empty($_POST['check_out_date'])) ? 'none' : 'flex'; ?>;align-items:center;gap:8px;">
                        <i class="fas fa-calendar-alt"></i> Select check-in and check-out dates above to see available rooms.
                    </div>

                    <!-- Availability summary cards — populated by JS after dates are chosen -->
                    <div id="availSummaryPanel" class="avail-panel" role="list" aria-label="Room availability"></div>

                    <!-- Rooms counter: each increment adds a new room line -->
                    <div id="roomsCounterRow" style="display:flex;align-items:center;gap:14px;margin-bottom:16px;flex-wrap:wrap;">
                        <span style="font-size:13px;font-weight:600;color:#555;">Rooms needed:</span>
                        <div class="qty-stepper">
                            <button type="button" onclick="adjustTotalRooms(-1)" aria-label="Remove room">−</button>
                            <span id="roomsCounterDisplay" style="min-width:28px;text-align:center;font-weight:600;font-size:15px;line-height:36px;">1</span>
                            <button type="button" onclick="adjustTotalRooms(1)" aria-label="Add room">+</button>
                        </div>
                        <small id="roomsCounterHint" style="color:#888;font-size:12px;"></small>
                    </div>

                    <div id="groupSummaryPill" class="group-summary" style="display:none;">
                        <i class="fas fa-users"></i> <span id="groupSummaryText"></span>
                    </div>

                    <!-- Room lines injected by JS — one line = one room -->
                    <div id="roomLinesContainer"></div>

                    <!-- Individual room section — only shown for single-room bookings -->
                    <div id="individualRoomSection" style="display:none;margin-top:20px;padding-top:16px;border-top:1px solid #e0d8d0;">
                        <div style="display:flex;align-items:center;gap:20px;margin-bottom:10px;">
                            <span style="font-size:14px;font-weight:600;">Room Assignment</span>
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                                <input type="radio" name="ir_assign_mode" value="auto" id="irModeAuto" checked onchange="setAutoAssign(true)">
                                Auto-assign (recommended)
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                                <input type="radio" name="ir_assign_mode" value="pick" id="irModePick" onchange="setAutoAssign(false)">
                                Pick a specific room
                            </label>
                        </div>
                        <div id="irPickerSection" style="display:none;">
                            <p id="irLoadingMsg" style="font-size:13px;color:#888;display:none;"></p>
                            <div id="irList" class="ir-list"></div>
                        </div>
                    </div>
                    <div class="cb-section-error" id="cbErr2"></div>
                    <div class="cb-section-nav">
                        <button type="button" class="cb-nav-btn cb-nav-prev" onclick="cbPrev(2)"><i class="fas fa-arrow-left"></i> Stay Details</button>
                        <button type="button" class="cb-nav-btn cb-nav-next" onclick="cbNext(2)">Guest Information <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>

                <!-- ══ 3. GUEST INFORMATION ═══════════════════════════════════════════════ -->
                <div class="form-card" id="cbSection3">
                    <h3><i class="fas fa-user"></i> Guest Information</h3>

                    <!-- Returning Guest Lookup -->
                    <div class="rg-lookup-bar">
                        <label><i class="fas fa-search" style="margin-right:5px;color:var(--gold,#b18247);"></i> Returning Guest Lookup</label>
                        <div class="rg-search-wrap">
                            <i class="fas fa-user-check rg-search-icon"></i>
                            <input type="text" id="rgSearchInput" class="rg-search-input" placeholder="Search by name, email or phone…" autocomplete="off">
                            <i class="fas fa-spinner fa-spin rg-spinner" id="rgSpinner"></i>
                            <button type="button" class="rg-clear-btn" id="rgClearSearch" title="Clear search" onclick="rgClearSearch()"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="rg-dropdown" id="rgDropdown" role="listbox"></div>

                        <!-- Selected guest card — shown after selection -->
                        <div class="rg-selected-card" id="rgSelectedCard">
                            <div class="rg-selected-avatar" id="rgSelectedAvatar">?</div>
                            <div class="rg-selected-body">
                                <div class="rg-selected-name" id="rgSelectedName"></div>
                                <div class="rg-selected-badges" id="rgSelectedBadges"></div>
                                <div class="rg-selected-meta" id="rgSelectedMeta"></div>
                            </div>
                            <button type="button" class="rg-selected-clear" onclick="rgClearSelection()" title="Clear — enter new guest info"><i class="fas fa-times-circle"></i></button>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name <span class="required">*</span></label>
                            <input type="text" name="guest_name" id="guestName" required
                                value="<?php echo htmlspecialchars($_POST['guest_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Email Address <span class="required">*</span></label>
                            <input type="email" name="guest_email" id="guestEmail" required
                                value="<?php echo htmlspecialchars($_POST['guest_email'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone Number <span class="required">*</span></label>
                            <input type="tel" name="guest_phone" id="guestPhone" required
                                value="<?php echo htmlspecialchars($_POST['guest_phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="guest_country" id="guestCountry"
                                value="<?php echo htmlspecialchars($_POST['guest_country'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="guest_address" id="guestAddress" rows="2"><?php echo htmlspecialchars($_POST['guest_address'] ?? ''); ?></textarea>
                    </div>
                    <div class="cb-section-error" id="cbErr3"></div>
                    <div class="cb-section-nav">
                        <button type="button" class="cb-nav-btn cb-nav-prev" onclick="cbPrev(3)"><i class="fas fa-arrow-left"></i> Rooms</button>
                        <button type="button" class="cb-nav-btn cb-nav-next" onclick="cbNext(3)">Booking Status <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>

                <!-- ══ 4. BOOKING STATUS ══════════════════════════════════════════════════ -->
                <div class="form-card" id="cbSection4">
                    <h3><i class="fas fa-tag"></i> Booking Status</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="booking_status" id="bookingStatus" onchange="onBookingStatusChange()">
                                <option value="pending">Pending</option>
                                <option value="confirmed" selected>Confirmed</option>
                                <?php if (getSetting('tentative_bookings_enabled', '1') !== '0'): ?>
                                    <option value="tentative">Tentative (hold)</option>
                                <?php endif; ?>
                                <option value="checked-in">Checked In (walk-in)</option>
                            </select>
                        </div>
                    </div>
                    <div class="cb-section-nav" style="margin-top:16px;">
                        <button type="button" class="cb-nav-btn cb-nav-prev" onclick="cbPrev(4)"><i class="fas fa-arrow-left"></i> Guest Info</button>
                        <button type="button" class="cb-nav-btn cb-nav-next" onclick="cbNext(4)">Payment <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>

                <!-- ══ 5. PAYMENT TO COLLECT ══════════════════════════════════════════════ -->
                <div class="form-card" id="cbSection5">
                    <h3><i class="fas fa-receipt"></i> Payment to Collect</h3>

                    <table class="accounting-table">
                        <tbody>
                            <tr id="accRowRoomRate">
                                <td>Room rate</td>
                                <td id="accRoomRate">—</td>
                            </tr>
                            <tr id="accRowRatePlan" class="tax-row" style="display:none;">
                                <td id="accRatePlanLabel">Rate plan</td>
                                <td id="accRatePlanTotal">—</td>
                            </tr>
                            <tr id="accRowNights">
                                <td>× <span id="accNightsLabel">— nights</span></td>
                                <td id="accBaseTotal">—</td>
                            </tr>
                            <tr id="accRowQty" style="display:none;">
                                <td>× <span id="accQtyLabel">— rooms</span></td>
                                <td id="accQtyTotal">—</td>
                            </tr>
                            <tr id="accRowChild" style="display:none;" class="tax-row">
                                <td>Child supplement (<span id="accChildCount">0</span> child × <span id="accChildPct">50</span>% rate)</td>
                                <td id="accChildTotal">—</td>
                            </tr>
                            <?php if ($levy_enabled): ?>
                                <tr id="accRowLevy" class="tax-row">
                                    <td>Tourism levy (<?php echo $levy_pct_cfg; ?>%)</td>
                                    <td id="accLevyTotal">—</td>
                                </tr>
                            <?php endif; ?>
                            <tr class="subtotal">
                                <td>Subtotal</td>
                                <td id="accSubtotal">—</td>
                            </tr>
                            <?php if ($vat_enabled): ?>
                                <tr id="accRowVat" class="tax-row">
                                    <td>VAT (<?php echo $vat_rate_cfg; ?>%)<?php echo vat_mode() === 'inclusive' ? ' — included in price' : ''; ?></td>
                                    <td id="accVatTotal">—</td>
                                </tr>
                            <?php endif; ?>
                            <tr class="total-row">
                                <td>Total to collect</td>
                                <td id="accGrandTotal">—</td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="margin-top:20px;">
                        <label class="payment-toggle-label" id="paymentReceivedLabel">
                            <input type="checkbox" id="paymentReceivedCheck" onchange="togglePaymentSection()">
                            Payment Received Now
                        </label>
                        <div id="tentativePaymentNotice" style="display:none;background:#FFF8DC;border-left:3px solid #F0A500;padding:10px 14px;margin-top:10px;border-radius:4px;font-size:13px;color:#856404;">
                            <i class="fas fa-info-circle"></i> Payment collection is disabled for tentative bookings. You can send the guest a quotation below.
                        </div>
                        <div class="payment-details-section" id="paymentDetailsSection">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Payment Method</label>
                                    <select name="payment_method" id="paymentMethod">
                                        <option value="cash">Cash</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="mobile_money">Mobile Money</option>
                                        <option value="card">Card</option>
                                        <option value="cheque">Cheque</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Amount Collected (<?php echo htmlspecialchars($currency_symbol); ?>)</label>
                                    <input type="number" name="amount_collected" id="amountCollected"
                                        step="0.01" min="0" placeholder="0.00"
                                        oninput="checkPartialPayment()">
                                    <small id="amountCollectedHint">Enter full amount for complete payment, or partial amount for a deposit.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ══ Apply guest credit (credit notes) ══ -->
                    <div id="creditPanel" style="margin-top:18px;padding:16px;background:#FAF6F0;border:1px solid #D2C8BC;border-radius:8px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                            <div>
                                <strong style="font-size:13px;color:#5c4a2f;"><i class="fas fa-wallet"></i> Guest Credit</strong>
                                <div style="font-size:12px;color:#8A775F;margin-top:2px;">Apply store credit (credit notes) this guest holds toward the booking.</div>
                            </div>
                            <button type="button" class="cb-nav-btn" id="creditLoadBtn" onclick="loadGuestCredit()" style="flex:0 0 auto;">
                                <i class="fas fa-magnifying-glass"></i> Check available credit
                            </button>
                        </div>

                        <!-- Override: apply credit belonging to a different account -->
                        <div style="margin-top:12px;">
                            <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:#5c4a2f;cursor:pointer;">
                                <input type="checkbox" name="apply_credit_override" id="creditOverrideCheck" value="1" onchange="onCreditOverrideToggle()">
                                Use credit from a different account (override)
                            </label>
                            <div id="creditOverrideBox" style="display:none;margin-top:8px;background:#fff;border:1px dashed #C9A36A;border-radius:6px;padding:10px 12px;">
                                <div style="font-size:11px;color:#b0552b;margin-bottom:8px;"><i class="fas fa-triangle-exclamation"></i> Applying another guest's credit is recorded in the audit log.</div>
                                <div class="form-group" style="margin:0 0 8px;">
                                    <label style="font-size:12px;">Credit account email</label>
                                    <input type="email" id="creditOverrideEmail" placeholder="email the credit was issued to">
                                </div>
                                <div class="form-group" style="margin:0;">
                                    <label style="font-size:12px;">Reason for override <span class="required">*</span></label>
                                    <input type="text" name="apply_credit_override_reason" id="creditOverrideReason" maxlength="200" placeholder="e.g. Company paying for employee's stay">
                                </div>
                            </div>
                        </div>

                        <div id="creditList" style="margin-top:12px;font-size:13px;"></div>
                    </div>

                    <div class="cb-section-error" id="cbErr5"></div>
                    <div class="cb-section-nav">
                        <button type="button" class="cb-nav-btn cb-nav-prev" onclick="cbPrev(5)"><i class="fas fa-arrow-left"></i> Status</button>
                        <button type="button" class="cb-nav-btn cb-nav-next" onclick="cbNext(5)">Review &amp; Submit <i class="fas fa-arrow-right"></i></button>
                    </div>
                    <script>
                        function onCreditOverrideToggle() {
                            var on = document.getElementById('creditOverrideCheck').checked;
                            document.getElementById('creditOverrideBox').style.display = on ? 'block' : 'none';
                            // Clear any previously loaded list so the source can't be ambiguous.
                            document.getElementById('creditList').innerHTML = '';
                        }

                        function loadGuestCredit() {
                            var listEl = document.getElementById('creditList');
                            var btn = document.getElementById('creditLoadBtn');
                            var override = document.getElementById('creditOverrideCheck').checked;
                            var email;
                            if (override) {
                                var ovEmail = document.getElementById('creditOverrideEmail');
                                email = ovEmail ? ovEmail.value.trim() : '';
                                if (!email) {
                                    listEl.innerHTML = '<span style="color:#b0552b;">Enter the credit account email to look up.</span>';
                                    return;
                                }
                            } else {
                                var emailEl = document.getElementById('guestEmail');
                                email = emailEl ? emailEl.value.trim() : '';
                                if (!email) {
                                    listEl.innerHTML = '<span style="color:#b0552b;">Enter the guest email (step 3) first.</span>';
                                    return;
                                }
                            }
                            btn.disabled = true;
                            listEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking…';
                            fetch('create-booking.php?ajax=guest_credit_lookup&email=' + encodeURIComponent(email), {
                                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                                })
                                .then(function (r) { return r.json(); })
                                .then(function (res) {
                                    btn.disabled = false;
                                    if (!res.success || !res.data || res.data.length === 0) {
                                        listEl.innerHTML = '<span style="color:#8A775F;">No redeemable credit on file for this guest.</span>';
                                        return;
                                    }
                                    var html = '<div style="display:flex;flex-direction:column;gap:8px;">';
                                    res.data.forEach(function (cn) {
                                        html += '<label style="display:flex;align-items:flex-start;gap:10px;background:#fff;border:1px solid #E2D8CC;border-radius:6px;padding:9px 11px;cursor:pointer;">'
                                            + '<input type="checkbox" name="apply_credit_note_ids[]" value="' + cn.id + '" class="credit-cn-check" style="margin-top:3px;">'
                                            + '<span><span style="font-weight:600;">' + cn.number + '</span> — ' + cn.balance_display
                                            + '<span style="display:block;font-size:11px;color:#8A775F;">' + (cn.reason ? cn.reason + ' · ' : '') + 'Expires: ' + cn.expires_at + '</span>'
                                            + '</span></label>';
                                    });
                                    html += '</div>';
                                    html += '<div style="margin-top:10px;font-size:12px;color:#5c4a2f;">'
                                        + 'Total available: <strong>' + res.currency + Number(res.total_balance).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</strong>'
                                        + '<br>Selected credit is applied to the balance after the booking is created (capped at the amount due).</div>';
                                    listEl.innerHTML = html;
                                })
                                .catch(function () {
                                    btn.disabled = false;
                                    listEl.innerHTML = '<span style="color:#b0552b;">Could not load credit. Try again.</span>';
                                });
                        }
                    </script>
                </div>

                <!-- ══ 6. ADMIN & NOTIFICATION ════════════════════════════════════════════ -->
                <div class="form-card" id="cbSection6">
                    <h3><i class="fas fa-cog"></i> Admin &amp; Notification</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Group / Company / Agent Reference</label>
                            <input type="text" name="group_reference"
                                value="<?php echo htmlspecialchars($_POST['group_reference'] ?? ''); ?>"
                                placeholder="e.g. ABC Corp, Agent: Jane Smith, Wedding party">
                            <small>Added to all booking notes for group bookings. Leave blank for individual guests.</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Internal Notes</label>
                        <textarea name="admin_notes" rows="2"
                            placeholder="Walk-in guest, phone booking, agent reference, etc."><?php echo htmlspecialchars($_POST['admin_notes'] ?? ''); ?></textarea>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="send_email" id="sendEmail" checked>
                        <label for="sendEmail">Send confirmation email to guest</label>
                    </div>

                    <!-- Quotation options — visible only when Tentative status is selected -->
                    <div id="quotationOptionGroup" style="display:none;margin-top:16px;padding:16px;background:#FAF6F0;border-radius:6px;border:1px solid #D2C8BC;">
                        <h4 style="margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:0.06em;color:#8A775F;font-family:inherit;">
                            <i class="fas fa-file-invoice"></i> Quotation
                        </h4>
                        <div class="checkbox-group" style="margin-bottom:12px;">
                            <input type="checkbox" name="send_quotation" id="sendQuotationCheck" value="1" checked>
                            <label for="sendQuotationCheck">Send quotation PDF to guest</label>
                        </div>
                        <div id="quotationValidDaysGroup" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-top:4px;">
                            <div class="form-group" style="flex:0 0 auto;margin:0;">
                                <label style="font-size:12px;">Valid for</label>
                                <select name="quotation_valid_days" id="quotationValidDays" style="width:auto;min-width:110px;">
                                    <option value="1">1 day</option>
                                    <option value="3">3 days</option>
                                    <option value="7" selected>7 days</option>
                                    <option value="14">14 days</option>
                                    <option value="30">30 days</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex:1 1 200px;margin:0;">
                                <label style="font-size:12px;">Note to include in quotation (optional)</label>
                                <textarea name="quotation_notes" id="quotationNotes" rows="2"
                                    placeholder="e.g. Price includes breakfast, airport transfer available on request…"
                                    style="font-size:13px;"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="cb-section-nav" style="border-top:none; padding-top:8px; margin-top:8px;">
                        <button type="button" class="cb-nav-btn cb-nav-prev" onclick="cbPrev(6)"><i class="fas fa-arrow-left"></i> Payment</button>
                        <span></span>
                    </div>
                </div>

                <button type="submit" class="btn-create" id="cbSubmitBtn">
                    <i class="fas fa-plus-circle"></i> Create Booking
                </button>
            </form>
        </div>
    </div>

    <script>
        const roomsData = <?php echo $rooms_json; ?>;
        const ratePlans = <?php echo $rate_plans_json; ?>;
        const currency = '<?php echo htmlspecialchars($currency_symbol, ENT_QUOTES); ?>';
        const vatRate = <?php echo (float)$vat_rate_cfg; ?>;
        const vatMode = <?php echo json_encode(vat_mode()); ?>; // 'off' | 'inclusive' | 'exclusive'
        const levyPct = <?php echo (float)$levy_pct_cfg; ?>;
        const vatEnabled = <?php echo $vat_enabled  ? 'true' : 'false'; ?>;
        const levyEnabled = <?php echo $levy_enabled ? 'true' : 'false'; ?>;

        let _lineCount = 0; // ever-increasing line index counter
        let _availMap = {}; // roomId → {available, rooms_left}

        function fmt(n) {
            return currency + (n || 0).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function el(id) {
            return document.getElementById(id);
        }

        function getVisibleLines() {
            return Array.from(document.querySelectorAll('.room-line:not([data-removed])'));
        }

        function getTotalRooms() {
            return getVisibleLines().length;
        }

        // Physical guest capacity of ONE room of this type. max_guests is already the
        // GREATEST of the room-type cap, any individual-room override, and any joined-
        // room combination (see the roomsData query).
        function roomPhysicalCap(room) {
            return Math.max(1, parseInt(room?.max_guests || 1, 10));
        }

        // Re-entrancy guard so the auto-split routine can add/retype lines without the
        // change handlers it triggers recursing back into it.
        let _autoSplitting = false;

        // ── Room line management ──────────────────────────────────────────────────
        function addRoomLine() {
            const idx = _lineCount++;
            const container = el('roomLinesContainer');
            const isFirst = getVisibleLines().length === 0;
            const datesReady = !!(el('checkInDate')?.value && el('checkOutDate')?.value);

            let optHtml = `<option value="">— ${datesReady ? 'Select Room Type' : 'Select dates first'} —</option>`;
            roomsData.forEach(r => {
                const info = _availMap[r.id];
                const dis = info && !info.available ? ' disabled style="color:#999"' : '';
                const avl = info ? (info.available ? (info.rooms_left > 1 ? ' — ' + info.rooms_left + ' avail.' : ' — Available') : ' — Not available') : '';
                const price = currency + r.price_per_night.toLocaleString() + '/night';
                optHtml += `<option value="${r.id}"${dis} data-base-name="${r.name.replace(/"/g,'&quot;')}">${r.name} (${price})${avl}</option>`;
            });

            const div = document.createElement('div');
            div.className = 'room-line';
            div.id = 'room-line-' + idx;
            div.innerHTML = `
            <div class="room-line-header">
                <span class="room-line-title"><i class="fas fa-door-open" style="color:var(--gold,#b18247);margin-right:5px;"></i> Room ${getVisibleLines().length + 1}</span>
                ${!isFirst ? `<button type="button" class="room-line-remove" onclick="removeRoomLine(${idx})"><i class="fas fa-times"></i> Remove</button>` : ''}
            </div>
            <div class="form-row three-col">
                <div class="form-group" style="grid-column:1/3">
                    <label>Room Type <span class="required">*</span></label>
                    <select name="room_line_room_id[]" class="line-room-select" ${datesReady ? '' : 'disabled'} onchange="updateLineRoom(${idx})">${optHtml}</select>
                </div>
                <div class="form-group">
                    <label>Occupancy</label>
                    <select name="room_line_occupancy[]" class="line-occ-select" onchange="calculateTotal()">
                        <option value="single">Single</option>
                        <option value="double" selected>Double</option>
                        <option value="triple">Triple</option>
                    </select>
                </div>
            </div>
            <div class="form-row" style="align-items:flex-end;">
                <div class="form-group">
                    <label>Price override <small style="font-weight:400;">(optional)</small></label>
                    <input type="number" name="room_line_override[]" class="line-override-input" step="0.01" min="0" placeholder="Auto" oninput="calculateTotal()">
                </div>
                <div class="form-group" style="display:flex;flex-direction:column;">
                    <label>&nbsp;</label>
                    <span class="line-subtotal" id="line-subtotal-${idx}">—</span>
                </div>
            </div>
            <input type="hidden" name="room_line_qty[]" value="1">`;
            container.appendChild(div);
            updateLineRoom(idx);
            updateGroupSummary();
        }

        function removeRoomLine(idx) {
            const lineEl = el('room-line-' + idx);
            if (!lineEl || getVisibleLines().length <= 1) return;
            lineEl.setAttribute('data-removed', '1');
            lineEl.style.display = 'none';
            lineEl.querySelectorAll('input,select').forEach(i => i.disabled = true);
            const disp = el('roomsCounterDisplay');
            if (disp) disp.textContent = getVisibleLines().length;
            calculateTotal();
            updateGroupSummary();
            refreshIndividualRoomSection();
        }

        // ── Line interactions ─────────────────────────────────────────────────────
        function updateLineRoom(idx) {
            const lineEl = el('room-line-' + idx);
            if (!lineEl) return;
            const roomSel = lineEl.querySelector('.line-room-select');
            const occSel = lineEl.querySelector('.line-occ-select');
            const roomId = parseInt(roomSel?.value || '0', 10);
            const room = roomsData.find(r => r.id === roomId);

            // Sync selected state on availability cards
            if (roomId) {
                document.querySelectorAll('.avail-card').forEach(c => {
                    c.classList.toggle('ac-selected', parseInt(c.dataset.roomId) === roomId);
                });
            }
            if (!room) {
                calculateTotal();
                return;
            }

            // Occupancy options are gated by BOTH the room's occupancy policy AND its
            // physical capacity — a 2-guest room never offers "Triple".
            const cap = roomPhysicalCap(room);
            Array.from(occSel.options).forEach(opt => {
                if (opt.value === 'single') opt.disabled = !room.single_enabled || cap < 1;
                if (opt.value === 'double') opt.disabled = !room.double_enabled || cap < 2;
                if (opt.value === 'triple') opt.disabled = !room.triple_enabled || cap < 3;
            });
            if (occSel.selectedOptions[0]?.disabled) {
                // Fall back to the highest still-valid occupancy (seats the most guests).
                const valid = Array.from(occSel.options).filter(o => !o.disabled);
                if (valid.length) occSel.value = valid[valid.length - 1].value;
            }
            // If one room of this type can't seat the whole party, split into more.
            autoSplitForCapacity(idx);
            refreshIndividualRoomSection();
            calculateTotal();
            updateGroupSummary();
        }

        // ── Rooms counter (top of allocation card) ────────────────────────────────
        function adjustTotalRooms(delta) {
            const guests = parseInt(el('numGuests')?.value || '1', 10);
            const current = getVisibleLines().length;
            const maxAvail = getMaxRoomsFromAvailability();
            const max = Math.min(guests, maxAvail, 20);
            const newCount = Math.min(max, Math.max(1, current + delta));
            if (newCount > current) {
                for (let i = current; i < newCount; i++) addRoomLine();
            } else if (newCount < current) {
                const lines = getVisibleLines();
                for (let i = newCount; i < lines.length; i++) {
                    const linIdx = parseInt(lines[i].id.replace('room-line-', ''), 10);
                    removeRoomLine(linIdx);
                }
            }
            const disp = el('roomsCounterDisplay');
            if (disp) disp.textContent = getVisibleLines().length;
            updateGroupSummary();
            calculateTotal();
        }

        function getMaxRoomsFromAvailability() {
            const keys = Object.keys(_availMap);
            if (!keys.length) return 20;
            const total = keys.reduce((s, k) => s + ((_availMap[k].available && _availMap[k].rooms_left > 0) ? _availMap[k].rooms_left : 0), 0);
            return total > 0 ? total : 1;
        }

        function parseDateOnly(value) {
            return value ? new Date(value + 'T00:00:00') : null;
        }

        function daysBetween(start, end) {
            return Math.round((end - start) / 86400000);
        }

        function planAppliesToRoom(plan, roomId) {
            if (!plan || plan.applies_to === 'all') return true;
            if (!plan.room_type_ids) return false;
            try {
                const ids = Array.isArray(plan.room_type_ids) ? plan.room_type_ids : JSON.parse(plan.room_type_ids);
                return ids.map(id => Number(id)).includes(Number(roomId));
            } catch (_error) {
                return false;
            }
        }

        function planMatchesStay(plan, checkInDate, checkOutDate, nights, daysUntilArrival) {
            if (!plan || !checkInDate || !checkOutDate || nights < 1) return false;

            if (plan.rule_type === 'seasonal') {
                const start = parseDateOnly(plan.start_date);
                const end = parseDateOnly(plan.end_date);
                return !!(start && end && checkInDate >= start && checkInDate <= end);
            }

            if (plan.rule_type === 'weekend') {
                if (!plan.days_of_week) return false;
                const targetDays = String(plan.days_of_week).split(',').map(day => Number(day.trim()));
                const cursor = new Date(checkInDate);
                while (cursor < checkOutDate) {
                    if (targetDays.includes(cursor.getDay())) return true;
                    cursor.setDate(cursor.getDate() + 1);
                }
                return false;
            }

            if (plan.rule_type === 'los_discount') {
                const min = Number(plan.min_nights || 1);
                const max = Number(plan.max_nights || 0) > 0 ? Number(plan.max_nights) : Number.MAX_SAFE_INTEGER;
                return nights >= min && nights <= max;
            }

            if (plan.rule_type === 'last_minute' || plan.rule_type === 'early_bird') {
                const min = Number(plan.days_before_min || 0);
                const max = plan.days_before_max !== null && plan.days_before_max !== undefined ? Number(plan.days_before_max) : Number.MAX_SAFE_INTEGER;
                return daysUntilArrival >= min && daysUntilArrival <= max;
            }

            return plan.rule_type === 'promotion';
        }

        function applyRatePlanToPrice(plan, price) {
            const value = Number(plan.adjustment_value || 0);
            if (plan.adjustment_type === 'fixed') {
                return Math.max(0, price + value);
            }
            return Math.max(0, Math.round(price * (1 + value / 100) * 100) / 100);
        }

        function getDynamicRate(roomId, checkIn, checkOut, nights, baseRate) {
            const empty = {
                finalRate: baseRate,
                originalRate: baseRate,
                discountAmount: 0,
                label: ''
            };
            if (!Array.isArray(ratePlans) || !ratePlans.length || nights < 1) return empty;

            const checkInDate = parseDateOnly(checkIn);
            const checkOutDate = parseDateOnly(checkOut);
            const today = parseDateOnly(new Date().toISOString().split('T')[0]);
            const daysUntilArrival = checkInDate && today && checkInDate >= today ? daysBetween(today, checkInDate) : 0;
            let currentRate = baseRate;
            let appliedLabel = '';
            let totalDiscount = 0;

            for (const plan of ratePlans) {
                if (!planAppliesToRoom(plan, roomId)) continue;
                if (!planMatchesStay(plan, checkInDate, checkOutDate, nights, daysUntilArrival)) continue;

                const adjustedRate = applyRatePlanToPrice(plan, currentRate);
                totalDiscount += currentRate - adjustedRate;
                currentRate = adjustedRate;
                if (!appliedLabel) appliedLabel = plan.name || 'Rate plan';
                if (!Number(plan.is_stacking || 0)) break;
            }

            if (!appliedLabel) return empty;
            return {
                finalRate: Math.round(currentRate * 100) / 100,
                originalRate: baseRate,
                discountAmount: Math.round(totalDiscount * 100) / 100,
                label: appliedLabel
            };
        }

        // Show individual room section only when exactly 1 line × qty=1
        function refreshIndividualRoomSection() {
            const irSec = el('individualRoomSection');
            if (!irSec) return;
            const visible = getVisibleLines();
            const totalRms = getTotalRooms();
            if (visible.length === 1 && totalRms === 1) {
                irSec.style.display = 'block';
                const roomId = parseInt(visible[0].querySelector('.line-room-select')?.value || '0', 10);
                const irMode = document.querySelector('input[name=ir_assign_mode]:checked')?.value;
                if (irMode === 'pick' && roomId) loadIndividualRooms(roomId);
            } else {
                irSec.style.display = 'none';
                const autoR = el('irModeAuto');
                if (autoR) {
                    autoR.checked = true;
                    el('autoAssignRoom').value = '1';
                }
                const picker = el('irPickerSection');
                if (picker) picker.style.display = 'none';
            }
        }

        // ── Occupancy auto-derive per line ────────────────────────────────────────
        function autoOccupancy() {
            const guests = parseInt(el('numGuests')?.value || '1', 10);
            const totalRooms = getTotalRooms() || 1;
            const per = Math.ceil(guests / totalRooms);
            getVisibleLines().forEach(ln => {
                const occSel = ln.querySelector('.line-occ-select');
                const opts = Array.from(occSel.options).filter(o => !o.disabled);
                const has = v => opts.some(o => o.value === v);
                occSel.value = per <= 1 && has('single') ? 'single' :
                    per <= 2 && has('double') ? 'double' :
                    has('triple') ? 'triple' :
                    (opts[0]?.value ?? occSel.value);
            });
        }

        // ── Auto-split a large party across rooms ─────────────────────────────────
        // When the guest count exceeds what one room of the chosen type physically
        // holds, add more rooms of the SAME type until everyone is seated — bounded by
        // how many of that type are actually available for the dates. This is what a
        // front-desk manager does with a big group.
        function autoSplitForCapacity(triggerIdx) {
            if (_autoSplitting) return;
            const guests = parseInt(el('numGuests')?.value || '1', 10);
            const lines = getVisibleLines();
            if (!lines.length) return;

            const trigEl = (typeof triggerIdx === 'number') ? el('room-line-' + triggerIdx) : null;
            const baseLine = (trigEl && !trigEl.hasAttribute('data-removed')) ? trigEl : lines[0];
            const roomId = parseInt(baseLine.querySelector('.line-room-select')?.value || '0', 10);
            const room = roomsData.find(r => r.id === roomId);
            if (!room) return;

            const cap = roomPhysicalCap(room);
            if (guests <= cap) return; // one room seats everyone — nothing to split

            // Rooms of this type bookable for the chosen dates (fall back to the static
            // inventory count before an availability check has run).
            const info = _availMap[roomId];
            const availOfType = info
                ? (info.available ? Math.max(1, parseInt(info.rooms_left || 1, 10)) : 0)
                : Math.max(1, parseInt(room.rooms_available || 1, 10));
            if (availOfType < 1) return; // sold out — server capacity guard will flag it

            const needed = Math.min(Math.ceil(guests / cap), availOfType, 20);
            const current = lines.length;
            if (needed <= current) return;

            _autoSplitting = true;
            try {
                for (let i = current; i < needed; i++) addRoomLine();
                // Homogenise: every line becomes the same room type as the trigger.
                getVisibleLines().forEach(ln => {
                    const sel = ln.querySelector('.line-room-select');
                    if (sel && !sel.disabled && sel.value !== String(roomId)) {
                        sel.value = String(roomId);
                        updateLineRoom(parseInt(ln.id.replace('room-line-', ''), 10));
                    }
                });
                const disp = el('roomsCounterDisplay');
                if (disp) disp.textContent = getVisibleLines().length;
            } finally {
                _autoSplitting = false;
            }
            autoOccupancy();
            updateGroupSummary();
            refreshIndividualRoomSection();
        }

        function guestsChanged() {
            const guests = parseInt(el('numGuests')?.value || '1', 10);
            const current = getVisibleLines().length;
            if (current > guests) adjustTotalRooms(guests - current);
            // Expand the allocation if a single room can no longer hold the party.
            autoSplitForCapacity();
            autoOccupancy();
            calculateTotal();
            updateGroupSummary();
            const disp = el('roomsCounterDisplay');
            if (disp) disp.textContent = getVisibleLines().length;
        }

        // ── Group summary pill ────────────────────────────────────────────────────
        function updateGroupSummary() {
            const pill = el('groupSummaryPill');
            const txt = el('groupSummaryText');
            const guests = parseInt(el('numGuests')?.value || '1', 10);
            const total = getTotalRooms();

            // Capacity hint next to the rooms counter — tells the manager at a glance
            // whether the current allocation seats the whole party.
            const hint = el('roomsCounterHint');
            if (hint) {
                let seats = 0;
                getVisibleLines().forEach(ln => {
                    const rid = parseInt(ln.querySelector('.line-room-select')?.value || '0', 10);
                    const rm = roomsData.find(r => r.id === rid);
                    if (rm) seats += roomPhysicalCap(rm);
                });
                if (seats === 0) {
                    hint.textContent = '';
                } else if (guests > seats) {
                    hint.textContent = `Seats only ${seats} of ${guests} guests — add another room or pick a larger type.`;
                    hint.style.color = '#c0392b';
                } else {
                    hint.textContent = `Seats ${seats} guest${seats !== 1 ? 's' : ''} · party of ${guests} fits.`;
                    hint.style.color = '#2a7d4f';
                }
            }

            if (!pill || !txt) return;
            if (total > 1) {
                txt.textContent = `Group booking: ${total} rooms · ${guests} guest${guests !== 1 ? 's' : ''} · ~${(guests / total).toFixed(1)} guests/room`;
                pill.style.display = 'flex';
            } else {
                pill.style.display = 'none';
            }
        }

        // ── Date controls ─────────────────────────────────────────────────────────
        function datesChanged() {
            const today = new Date().toISOString().split('T')[0];
            const ciEl = el('checkInDate');
            const coEl = el('checkOutDate');
            let ci = ciEl.value,
                co = coEl.value;
            if (ci && ci < today) {
                ciEl.value = today;
                ci = today;
            }
            if (ci) {
                const next = new Date(ci + 'T00:00:00');
                next.setDate(next.getDate() + 1);
                const minCo = next.toISOString().split('T')[0];
                coEl.min = minCo;
                if (co && co <= ci) {
                    coEl.value = minCo;
                    co = minCo;
                }
            } else {
                coEl.min = today;
            }
            calculateTotal();
            ci = ciEl.value;
            co = coEl.value;
            if (ci && co && co > ci) checkAvailabilityForDates(ci, co);
        }

        // ── Availability AJAX ─────────────────────────────────────────────────────
        function checkAvailabilityForDates(checkIn, checkOut) {
            const notice = el('roomAvailabilityNotice');
            const panel  = el('availSummaryPanel');
            document.querySelectorAll('.line-room-select').forEach(s => s.disabled = true);
            if (panel) { panel.innerHTML = ''; panel.classList.remove('visible'); }
            if (notice) {
                notice.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking availability…';
                notice.style.cssText = 'display:flex;align-items:center;gap:8px;background:#fff8e1;border-left:3px solid #f0c36d;color:#7a5c00;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:13px;';
            }
            const csrf = document.querySelector('[name=csrf_token]').value;
            const fd = new URLSearchParams({
                action: 'check_room_type_availability',
                check_in: checkIn,
                check_out: checkOut,
                csrf_token: csrf
            });
            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: fd.toString()
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.error || 'Failed');
                    _availMap = {};
                    (data.data || []).forEach(r => {
                        _availMap[r.room_id] = r;
                    });
                    document.querySelectorAll('.line-room-select').forEach(sel => {
                        const curVal = sel.value;
                        let curStillOk = false;
                        Array.from(sel.options).forEach(opt => {
                            if (!opt.value) return;
                            const rid = parseInt(opt.value);
                            const info = _availMap[rid];
                            const rm = roomsData.find(r => r.id === rid);
                            let base = opt.getAttribute('data-base-name') || opt.text.split(' (')[0];
                            opt.setAttribute('data-base-name', base);
                            if (info && info.available) {
                                opt.disabled = false;
                                opt.style.color = '';
                                opt.text = base + ' (' + currency + (rm?.price_per_night || 0).toLocaleString() + '/night)' + (info.rooms_left > 1 ? ' — ' + info.rooms_left + ' avail.' : ' — Available');
                                if (String(rid) === String(curVal)) curStillOk = true;
                            } else {
                                opt.disabled = true;
                                opt.style.color = '#999';
                                opt.text = base + ' — Not available';
                            }
                        });
                        if (curVal && !curStillOk) sel.value = '';
                        sel.disabled = false;
                        sel.options[0].text = '— Select Room Type —';
                    });
                    if (notice) notice.style.display = 'none';

                    // Build availability summary panel
                    const panel = el('availSummaryPanel');
                    if (panel && roomsData.length > 0) {
                        panel.innerHTML = '';
                        roomsData.forEach(function (rm) {
                            const info = _availMap[rm.id];
                            if (!info) return;
                            const avail = !!info.available;
                            const left  = avail ? (info.rooms_left > 0 ? info.rooms_left : 1) : 0;
                            const card  = document.createElement('div');
                            card.className = 'avail-card ' + (avail ? 'ac-available' : 'ac-unavailable');
                            card.setAttribute('role', 'listitem');
                            card.dataset.roomId = rm.id;
                            card.innerHTML =
                                '<div class="avail-card-count">' + (avail ? left : '0') + '</div>' +
                                '<div class="avail-card-name">' + rm.name + '</div>' +
                                '<div class="avail-card-price">' + currency + rm.price_per_night.toLocaleString() + ' / night</div>' +
                                '<span class="avail-card-tag">' + (avail ? (left === 1 ? 'Available' : left + ' Available') : 'Sold Out') + '</span>';
                            if (avail) {
                                card.addEventListener('click', function () {
                                    // Auto-select this room type in the first empty line dropdown
                                    const selects = Array.from(document.querySelectorAll('.line-room-select'));
                                    const target  = selects.find(s => !s.value) || selects[0];
                                    if (target) {
                                        target.value = rm.id;
                                        target.dispatchEvent(new Event('change'));
                                    }
                                    // Mark selected card
                                    document.querySelectorAll('.avail-card').forEach(c => c.classList.remove('ac-selected'));
                                    card.classList.add('ac-selected');
                                });
                            }
                            panel.appendChild(card);
                        });
                        panel.classList.add('visible');
                    }

                    getVisibleLines().forEach(ln => {
                        const i = parseInt(ln.id.replace('room-line-', ''), 10);
                        updateLineRoom(i);
                    });
                })
                .catch(() => {
                    document.querySelectorAll('.line-room-select').forEach(sel => {
                        Array.from(sel.options).forEach(o => {
                            if (o.value) o.disabled = false;
                        });
                        sel.disabled = false;
                    });
                    if (notice) {
                        notice.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Could not check availability — all rooms shown.';
                        notice.style.color = '#856404';
                    }
                });
        }

        // ── Individual room AJAX loader ───────────────────────────────────────────
        function loadIndividualRooms(roomId) {
            const ci = el('checkInDate').value,
                co = el('checkOutDate').value;
            if (!roomId || !ci || !co) return;
            const listEl = el('irList');
            const loadMsg = el('irLoadingMsg');
            listEl.innerHTML = '';
            if (loadMsg) {
                loadMsg.style.display = 'block';
                loadMsg.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading available rooms…';
            }
            const csrf = document.querySelector('[name=csrf_token]').value;
            const fd = new URLSearchParams({
                action: 'get_available_individual_rooms',
                room_type_id: roomId,
                check_in: ci,
                check_out: co,
                csrf_token: csrf
            });
            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: fd.toString()
                })
                .then(r => r.json())
                .then(data => {
                    if (loadMsg) loadMsg.style.display = 'none';
                    if (!data.success || !data.data?.length) {
                        listEl.innerHTML = '<p style="font-size:13px;color:#c0392b;"><i class="fas fa-exclamation-circle"></i> No individual rooms configured for this type.</p>';
                        return;
                    }
                    data.data.forEach(ir => {
                        const card = document.createElement('label');
                        card.className = 'ir-card ' + (ir.available ? 'available' : 'unavailable');
                        const radio = document.createElement('input');
                        radio.type = 'radio';
                        radio.name = 'individual_room_id';
                        radio.value = ir.id;
                        radio.disabled = !ir.available;
                        radio.setAttribute('data-child-multiplier', ir.child_multiplier);
                        radio.setAttribute('data-single-override', ir.single_override ?? '');
                        radio.setAttribute('data-double-override', ir.double_override ?? '');
                        radio.setAttribute('data-triple-override', ir.triple_override ?? '');
                        radio.setAttribute('data-children-override', ir.children_override ?? '');
                        radio.onchange = applyIndividualRoomPolicyOverrides;
                        const lbl = document.createElement('span');
                        lbl.className = 'ir-card-label';
                        lbl.textContent = ir.label;
                        const st = document.createElement('span');
                        st.className = 'ir-card-status';
                        st.textContent = ir.conflict ? 'Booked' : (ir.status !== 'available' ? ir.status.charAt(0).toUpperCase() + ir.status.slice(1) : 'Available');
                        card.appendChild(radio);
                        card.appendChild(lbl);
                        card.appendChild(st);
                        listEl.appendChild(card);
                    });
                })
                .catch(() => {
                    if (loadMsg) {
                        loadMsg.style.display = 'block';
                        loadMsg.textContent = 'Failed to load rooms.';
                    }
                });
        }

        function setAutoAssign(isAuto) {
            el('autoAssignRoom').value = isAuto ? '1' : '0';
            el('irPickerSection').style.display = isAuto ? 'none' : 'block';
            if (!isAuto) {
                const lines = getVisibleLines();
                const roomId = lines.length ? parseInt(lines[0].querySelector('.line-room-select')?.value || '0', 10) : 0;
                if (roomId) loadIndividualRooms(roomId);
            }
        }

        function applyIndividualRoomPolicyOverrides() {
            const lines = getVisibleLines();
            if (!lines.length) return;
            const occSel = lines[0].querySelector('.line-occ-select');
            const roomId = parseInt(lines[0].querySelector('.line-room-select')?.value || '0', 10);
            const room = roomsData.find(r => r.id === roomId);
            if (!room || !occSel) return;
            let se = !!room.single_enabled,
                de = !!room.double_enabled,
                te = !!room.triple_enabled,
                ca = !!room.children_allowed;
            const checked = document.querySelector('input[name=individual_room_id]:checked');
            if (checked) {
                const so = checked.getAttribute('data-single-override');
                const dox = checked.getAttribute('data-double-override');
                const tox = checked.getAttribute('data-triple-override');
                const cox = checked.getAttribute('data-children-override');
                if (so !== '') se = so === '1';
                if (dox !== '') de = dox === '1';
                if (tox !== '') te = tox === '1';
                if (cox !== '') ca = cox === '1';
            }
            Array.from(occSel.options).forEach(opt => {
                if (opt.value === 'single') opt.disabled = !se;
                if (opt.value === 'double') opt.disabled = !de;
                if (opt.value === 'triple') opt.disabled = !te;
            });
            if (occSel.selectedOptions[0]?.disabled) {
                const f = Array.from(occSel.options).find(o => !o.disabled);
                if (f) occSel.value = f.value;
            }
            const cEl = el('childGuests');
            if (cEl) {
                cEl.disabled = !ca;
                if (!ca) cEl.value = '0';
            }
            calculateTotal();
        }

        // ── Core accounting calculator ─────────────────────────────────────────────
        function calculateTotal() {
            const ci = el('checkInDate')?.value;
            const co = el('checkOutDate')?.value;
            const children = parseInt(el('childGuests')?.value || '0', 10);

            if (!ci || !co) {
                ['accRoomRate', 'accBaseTotal', 'accQtyTotal', 'accChildTotal', 'accLevyTotal', 'accSubtotal', 'accVatTotal', 'accGrandTotal']
                .forEach(id => {
                    const e = el(id);
                    if (e) e.textContent = '—';
                });
                document.querySelectorAll('.line-subtotal').forEach(s => s.textContent = '—');
                updateCollectAmount(0);
                return;
            }

            const d1 = new Date(ci + 'T00:00:00'),
                d2 = new Date(co + 'T00:00:00');
            const nights = Math.round((d2 - d1) / 86400000);
            if (nights < 1) {
                updateCollectAmount(0);
                return;
            }

            const lines = getVisibleLines();
            if (!lines.length) {
                updateCollectAmount(0);
                return;
            }

            let grandBase = 0,
                grandChild = 0,
                grandLevy = 0,
                grandSub = 0,
                grandVat = 0,
                grandTotal = 0;
            let totalRooms = 0,
                firstRate = null,
                multiRates = false,
                ratePlanAdjustmentTotal = 0,
                ratePlanLabel = '';

            lines.forEach(ln => {
                const lineIdx = parseInt(ln.id.replace('room-line-', ''), 10);
                const roomId = parseInt(ln.querySelector('.line-room-select')?.value || '0', 10);
                const occ = ln.querySelector('.line-occ-select')?.value || 'double';
                const qty = 1;
                const ovRaw = ln.querySelector('.line-override-input')?.value?.trim() || '';
                const override = ovRaw !== '' ? parseFloat(ovRaw) : null;
                const room = roomsData.find(r => r.id === roomId);
                const subEl = el('line-subtotal-' + lineIdx);
                totalRooms += qty;
                if (!room) {
                    if (subEl) subEl.textContent = '—';
                    return;
                }

                let rate = room.price_per_night;
                if (occ === 'single' && room.price_single) rate = room.price_single;
                if (occ === 'double' && room.price_double) rate = room.price_double;
                if (occ === 'triple' && room.price_triple) rate = room.price_triple;
                const originalRate = rate;
                const dynamicRate = override === null ? getDynamicRate(roomId, ci, co, nights, rate) : null;
                if (dynamicRate && dynamicRate.label) {
                    rate = dynamicRate.finalRate;
                    ratePlanAdjustmentTotal += dynamicRate.discountAmount * qty;
                    if (!ratePlanLabel) ratePlanLabel = dynamicRate.label;
                }
                if (firstRate === null) firstRate = rate;
                else if (firstRate !== rate) multiRates = true;

                const cMult = document.querySelector('input[name=individual_room_id]:checked') ?
                    parseFloat(document.querySelector('input[name=individual_room_id]:checked').getAttribute('data-child-multiplier') || '50') :
                    room.child_price_multiplier;

                if (override !== null) {
                    const lt = override * qty;
                    if (subEl) subEl.textContent = fmt(lt);
                    grandSub += lt;
                    grandTotal += lt;
                    return;
                }

                const lineBase = rate * nights * qty;
                const lineChild = children > 0 ? (rate * (cMult / 100) * children * nights * qty) : 0;
                const lineLevy = levyEnabled ? (lineBase + lineChild) * (levyPct / 100) : 0;
                const lineSub = lineBase + lineChild + lineLevy;
                // Mode-aware VAT: inclusive extracts from the priced total
                // (grand total never inflates); exclusive adds on top.
                const lineVat = vatMode === 'inclusive' ? lineSub * (vatRate / (100 + vatRate))
                    : (vatMode === 'exclusive' ? lineSub * (vatRate / 100) : 0);
                const lineGrand = vatMode === 'exclusive' ? lineSub + lineVat : lineSub;
                if (subEl) subEl.textContent = fmt(lineGrand);
                grandBase += lineBase;
                grandChild += lineChild;
                grandLevy += lineLevy;
                grandSub += lineSub;
                grandVat += lineVat;
                grandTotal += lineGrand;
            });

            const rateLabel = multiRates ? 'Multiple room types' : (firstRate !== null ? fmt(firstRate) + '/night' : '—');
            if (el('accRoomRate')) el('accRoomRate').textContent = rateLabel;
            if (el('accRowRatePlan')) {
                const hasAdjustment = Math.abs(ratePlanAdjustmentTotal) > 0.01;
                el('accRowRatePlan').style.display = hasAdjustment ? '' : 'none';
                if (hasAdjustment) {
                    const sign = ratePlanAdjustmentTotal > 0 ? '-' : '+';
                    el('accRatePlanLabel').textContent = (ratePlanLabel || 'Rate plan') + ':';
                    el('accRatePlanTotal').textContent = sign + fmt(Math.abs(ratePlanAdjustmentTotal)) + '/night';
                }
            }
            if (el('accNightsLabel')) el('accNightsLabel').textContent = nights + ' night' + (nights !== 1 ? 's' : '');
            if (el('accBaseTotal')) el('accBaseTotal').textContent = fmt(grandBase);
            if (el('accRowQty')) el('accRowQty').style.display = totalRooms > 1 ? '' : 'none';
            if (el('accQtyLabel')) el('accQtyLabel').textContent = totalRooms + ' room' + (totalRooms !== 1 ? 's' : '');
            if (el('accQtyTotal')) el('accQtyTotal').textContent = fmt(grandBase);
            if (el('accRowChild')) el('accRowChild').style.display = children > 0 ? '' : 'none';
            if (el('accChildCount')) el('accChildCount').textContent = children;
            if (el('accChildPct')) el('accChildPct').textContent = '50';
            if (el('accChildTotal')) el('accChildTotal').textContent = fmt(grandChild);
            if (el('accRowLevy')) el('accRowLevy').style.display = levyEnabled && grandLevy > 0 ? '' : 'none';
            if (el('accLevyTotal')) el('accLevyTotal').textContent = fmt(grandLevy);
            if (el('accSubtotal')) el('accSubtotal').textContent = fmt(grandSub);
            if (el('accRowVat')) el('accRowVat').style.display = vatEnabled && grandVat > 0 ? '' : 'none';
            if (el('accVatTotal')) el('accVatTotal').textContent = fmt(grandVat);
            if (el('accGrandTotal')) el('accGrandTotal').textContent = fmt(grandTotal);
            updateCollectAmount(grandTotal);
        }

        function updateCollectAmount(grandTotal) {
            const amtEl = el('amountCollected');
            if (!amtEl) return;
            if (!amtEl.value || amtEl.getAttribute('data-auto') === '1') {
                amtEl.value = grandTotal > 0 ? grandTotal.toFixed(2) : '';
                amtEl.setAttribute('data-auto', '1');
            }
            checkPartialPayment();
        }

        function checkPartialPayment() {
            const amtEl = el('amountCollected');
            const hint = el('amountCollectedHint');
            if (!amtEl || !hint) return;
            const grand = parseFloat((el('accGrandTotal')?.textContent || '').replace(/[^\d.]/g, '')) || 0;
            const entered = parseFloat(amtEl.value) || 0;
            amtEl.removeAttribute('data-auto');
            if (entered > 0 && entered < grand - 0.01) {
                hint.textContent = 'Partial payment — booking will be marked Partially Paid.';
                hint.style.color = '#e67e22';
            } else if (entered >= grand - 0.01 && grand > 0) {
                hint.textContent = 'Full payment — booking will be marked Paid.';
                hint.style.color = '#27ae60';
            } else {
                hint.textContent = 'Enter full amount for complete payment, or partial amount for a deposit.';
                hint.style.color = '#888';
            }
        }

        function togglePaymentSection() {
            const chk = el('paymentReceivedCheck');
            const sec = el('paymentDetailsSection');
            const hid = el('paymentReceivedHidden');
            hid.value = chk.checked ? '1' : '0';
            sec.classList.toggle('open', chk.checked);
            if (chk.checked) calculateTotal();
        }

        function onBookingStatusChange() {
            const status = el('bookingStatus').value;
            const isTentative = status === 'tentative';
            const chk = el('paymentReceivedCheck');
            const lbl = el('paymentReceivedLabel');
            const notice = el('tentativePaymentNotice');
            const qtGroup = el('quotationOptionGroup');

            if (isTentative) {
                // Grey out & disable payment collection
                chk.checked = false;
                chk.disabled = true;
                lbl.style.opacity = '0.4';
                lbl.style.cursor = 'not-allowed';
                el('paymentReceivedHidden').value = '0';
                el('paymentDetailsSection').classList.remove('open');
                notice.style.display = 'block';
                qtGroup.style.display = 'block';
            } else {
                chk.disabled = false;
                lbl.style.opacity = '';
                lbl.style.cursor = '';
                notice.style.display = 'none';
                qtGroup.style.display = 'none';
            }
        }

        // ── Init ──────────────────────────────────────────────────────────────────
        function initBookingForm() {
            addRoomLine();
            const ci = el('checkInDate').value;
            const co = el('checkOutDate').value;
            if (ci && co) checkAvailabilityForDates(ci, co);
            calculateTotal();
            updateGroupSummary();
            onBookingStatusChange();
        }
        // Run immediately if the DOM is already parsed (bfcache / SW-served page
        // where DOMContentLoaded has already fired), otherwise wait for it.
        // A DOMContentLoaded-only listener left the allocator empty until a reload.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initBookingForm);
        } else {
            initBookingForm();
        }

        // ═══════════════════════════════════════════════════════════════════
        // Returning Guest Lookup
        // ═══════════════════════════════════════════════════════════════════
        (function () {
            const csrfToken  = document.querySelector('input[name="csrf_token"]')?.value || '';
            const searchInput = document.getElementById('rgSearchInput');
            const dropdown    = document.getElementById('rgDropdown');
            const spinner     = document.getElementById('rgSpinner');
            const clearBtn    = document.getElementById('rgClearSearch');
            const selCard     = document.getElementById('rgSelectedCard');
            const selAvatar   = document.getElementById('rgSelectedAvatar');
            const selName     = document.getElementById('rgSelectedName');
            const selBadges   = document.getElementById('rgSelectedBadges');
            const selMeta     = document.getElementById('rgSelectedMeta');

            if (!searchInput) return;

            let debounceTimer = null;
            let lastQuery     = '';
            let focusedIdx    = -1;
            let currentGuests = [];

            function getField(id) { return document.getElementById(id); }

            function initials(name) {
                const parts = String(name || '').trim().split(/\s+/);
                return (parts.length >= 2
                    ? parts[0][0] + parts[parts.length - 1][0]
                    : (parts[0][0] || '?')
                ).toUpperCase();
            }

            function fmt(currency, amount) {
                return currency + ' ' + Number(amount).toLocaleString('en', {minimumFractionDigits: 0, maximumFractionDigits: 0});
            }

            function escHtml(str) {
                return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            document.addEventListener('click', function (e) {
                if (!e.target.closest('#rgSearchInput') && !e.target.closest('#rgDropdown')) {
                    closeDropdown();
                }
            });

            function closeDropdown() {
                dropdown.classList.remove('open');
                dropdown.innerHTML = '';
                focusedIdx = -1;
            }

            searchInput.addEventListener('input', function () {
                const q = this.value.trim();
                clearBtn.style.display = q.length > 0 ? 'block' : 'none';
                clearTimeout(debounceTimer);
                if (q.length < 2) { closeDropdown(); return; }
                if (q === lastQuery) return;
                debounceTimer = setTimeout(() => doSearch(q), 280);
            });

            searchInput.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.rg-dropdown-item');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    focusedIdx = Math.min(focusedIdx + 1, items.length - 1);
                    highlightItem(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    focusedIdx = Math.max(focusedIdx - 1, 0);
                    highlightItem(items);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (focusedIdx >= 0 && focusedIdx < currentGuests.length) {
                        selectGuest(currentGuests[focusedIdx]);
                    }
                } else if (e.key === 'Escape') {
                    closeDropdown();
                }
            });

            function highlightItem(items) {
                items.forEach((item, i) => item.classList.toggle('focused', i === focusedIdx));
                if (items[focusedIdx]) items[focusedIdx].scrollIntoView({ block: 'nearest' });
            }

            async function doSearch(q) {
                lastQuery = q;
                spinner.style.display = 'inline-block';
                try {
                    const url = 'api/guest-lookup.php?q=' + encodeURIComponent(q) + '&csrf=' + encodeURIComponent(csrfToken);
                    const res = await fetch(url);
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    if (data.error) throw new Error(data.error);
                    renderDropdown(data.guests || [], q);
                } catch (err) {
                    dropdown.innerHTML = '<div class="rg-dropdown-msg" style="color:#c0392b;">Error: ' + escHtml(err.message) + '</div>';
                    dropdown.classList.add('open');
                } finally {
                    spinner.style.display = 'none';
                }
            }

            function renderDropdown(guests, q) {
                currentGuests = guests;
                focusedIdx    = -1;
                dropdown.innerHTML = '';

                if (guests.length === 0) {
                    dropdown.innerHTML = '<div class="rg-dropdown-msg">No returning guests found for "<strong>' + escHtml(q) + '</strong>" — fill in guest details below.</div>';
                    dropdown.classList.add('open');
                    return;
                }

                guests.forEach(function (g, idx) {
                    const item = document.createElement('div');
                    item.className = 'rg-dropdown-item';
                    item.setAttribute('role', 'option');
                    item.innerHTML =
                        '<div class="rg-dropdown-avatar">' + escHtml(initials(g.name)) + '</div>' +
                        '<div class="rg-dropdown-info">' +
                            '<div class="rg-dropdown-name">' + escHtml(g.name) + '</div>' +
                            '<div class="rg-dropdown-meta">' + escHtml(g.email) + (g.phone ? '  ·  ' + escHtml(g.phone) : '') + '</div>' +
                        '</div>' +
                        '<div class="rg-dropdown-stays">' + escHtml(g.stay_label) + '</div>';
                    item.addEventListener('click', function () { selectGuest(g); });
                    item.addEventListener('mouseenter', function () {
                        focusedIdx = idx;
                        highlightItem(dropdown.querySelectorAll('.rg-dropdown-item'));
                    });
                    dropdown.appendChild(item);
                });

                dropdown.classList.add('open');
            }

            function selectGuest(g) {
                closeDropdown();

                const fName    = getField('guestName');
                const fEmail   = getField('guestEmail');
                const fPhone   = getField('guestPhone');
                const fCountry = getField('guestCountry');
                const fAddress = getField('guestAddress');

                if (fName)    fName.value    = g.name    || '';
                if (fEmail)   fEmail.value   = g.email   || '';
                if (fPhone)   fPhone.value   = g.phone   || '';
                if (fCountry) fCountry.value = g.country || '';
                if (fAddress) fAddress.value = g.address || '';

                [fName, fEmail, fPhone, fCountry, fAddress].forEach(function (el) {
                    if (el) el.dispatchEvent(new Event('change', { bubbles: true }));
                });

                showSelectedCard(g);
                searchInput.value      = '';
                clearBtn.style.display = 'none';
            }

            function showSelectedCard(g) {
                selAvatar.textContent = initials(g.name);
                selName.textContent   = g.name;

                const badges = [];
                if (g.completed_stays >= 1) {
                    badges.push('<span class="rg-badge rg-badge--returning"><i class="fas fa-redo-alt"></i> Returning Guest</span>');
                } else {
                    badges.push('<span class="rg-badge rg-badge--new"><i class="fas fa-star"></i> First Stay</span>');
                }
                if (g.completed_stays >= 1) {
                    badges.push('<span class="rg-badge rg-badge--stays"><i class="fas fa-bed"></i> ' + escHtml(g.stay_label) + '</span>');
                }
                if (g.lifetime_spend > 0) {
                    badges.push('<span class="rg-badge rg-badge--spend"><i class="fas fa-coins"></i> ' + escHtml(fmt(g.currency, g.lifetime_spend)) + ' lifetime</span>');
                }
                selBadges.innerHTML = badges.join('');

                const metaParts = [];
                if (g.last_stay_label)  metaParts.push('Last stay: ' + g.last_stay_label);
                if (g.last_booking_ref) metaParts.push('Ref: ' + g.last_booking_ref);
                if (g.email)            metaParts.push(g.email);
                selMeta.textContent = metaParts.join('  ·  ');

                selCard.classList.add('visible');
            }

            window.rgClearSearch = function () {
                searchInput.value      = '';
                clearBtn.style.display = 'none';
                closeDropdown();
            };

            window.rgClearSelection = function () {
                selCard.classList.remove('visible');
                ['guestName','guestEmail','guestPhone','guestCountry','guestAddress'].forEach(function (id) {
                    const el = getField(id);
                    if (el) el.value = '';
                });
                searchInput.focus();
            };
        })();

        // ═══════════════════════════════════════════════════════════════════
        // Section step navigation
        // ═══════════════════════════════════════════════════════════════════
        (function () {
            const SECTIONS = 6;
            let currentSection = 1;

            function getSection(n) { return document.getElementById('cbSection' + n); }
            function getStepBtn(n) { return document.getElementById('cbStepBtn' + n); }
            function getErrBox(n)  { return document.getElementById('cbErr' + n); }

            function setActiveStep(n) {
                currentSection = n;
                for (let i = 1; i <= SECTIONS; i++) {
                    const btn = getStepBtn(i);
                    if (!btn) continue;
                    btn.classList.remove('active', 'done');
                    if (i < n) btn.classList.add('done');
                    if (i === n) btn.classList.add('active');
                }
            }

            function scrollToSection(n) {
                const el = getSection(n);
                if (!el) return;
                const top = el.getBoundingClientRect().top + window.scrollY - 80;
                window.scrollTo({ top, behavior: 'smooth' });
            }

            function showErr(n, msg) {
                const box = getErrBox(n);
                if (!box) return;
                box.textContent = msg;
                box.style.display = msg ? 'block' : 'none';
            }

            function clearFieldErrors(fields) {
                fields.forEach(function (f) { f.classList.remove('cb-field-error'); });
            }

            // Section-specific validation
            function validateSection(n) {
                const errs = [];
                const badFields = [];

                if (n === 1) {
                    const ci = document.getElementById('checkInDate');
                    const co = document.getElementById('checkOutDate');
                    const guests = document.querySelector('[name="number_of_guests"]');
                    if (!ci || !ci.value) { errs.push('Check-in date is required.'); if (ci) badFields.push(ci); }
                    if (!co || !co.value) { errs.push('Check-out date is required.'); if (co) badFields.push(co); }
                    if (ci && co && ci.value && co.value && co.value <= ci.value) {
                        errs.push('Check-out must be after check-in.');
                        badFields.push(co);
                    }
                    if (!guests || !guests.value || parseInt(guests.value) < 1) {
                        errs.push('At least 1 guest is required.');
                        if (guests) badFields.push(guests);
                    }
                }

                if (n === 2) {
                    // Require at least one room line with a room selected
                    const roomSelects = document.querySelectorAll('select[name="room_line_room_id[]"]');
                    const hasRoom = Array.from(roomSelects).some(function (s) { return s.value; });
                    if (!hasRoom) {
                        errs.push('Please select at least one room type before continuing.');
                    }
                }

                if (n === 3) {
                    const nm  = document.getElementById('guestName');
                    const em  = document.getElementById('guestEmail');
                    const ph  = document.getElementById('guestPhone');
                    if (!nm || !nm.value.trim()) { errs.push('Guest name is required.'); if (nm) badFields.push(nm); }
                    if (!em || !em.value.trim()) { errs.push('Guest email is required.'); if (em) badFields.push(em); }
                    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em.value.trim())) {
                        errs.push('Please enter a valid email address.');
                        badFields.push(em);
                    }
                    if (!ph || !ph.value.trim()) { errs.push('Guest phone is required.'); if (ph) badFields.push(ph); }
                }

                if (n === 5) {
                    const check = document.getElementById('paymentReceivedCheck');
                    if (check && check.checked) {
                        const amt = document.getElementById('amountCollected');
                        if (!amt || !amt.value || parseFloat(amt.value) <= 0) {
                            errs.push('Please enter the amount collected, or uncheck "Payment Received Now".');
                            if (amt) badFields.push(amt);
                        }
                    }
                }

                // Clear previous field errors then mark bad ones
                document.querySelectorAll('.cb-field-error').forEach(function (el) {
                    el.classList.remove('cb-field-error');
                });
                badFields.forEach(function (f) { f.classList.add('cb-field-error'); });

                return errs;
            }

            window.cbNext = function (n) {
                showErr(n, '');
                const errs = validateSection(n);
                if (errs.length) {
                    showErr(n, errs.join(' '));
                    // Scroll to the error within the section
                    const el = getSection(n);
                    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    return;
                }
                const next = n + 1;
                if (next > SECTIONS) return;
                setActiveStep(next);
                scrollToSection(next);
            };

            window.cbPrev = function (n) {
                const prev = n - 1;
                if (prev < 1) return;
                showErr(n, '');
                setActiveStep(prev);
                scrollToSection(prev);
            };

            window.cbJumpTo = function (n) {
                showErr(currentSection, '');
                setActiveStep(n);
                scrollToSection(n);
            };

            // Update active step on scroll via IntersectionObserver
            if ('IntersectionObserver' in window) {
                const obs = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            const id = entry.target.id;
                            const num = parseInt(id.replace('cbSection', ''));
                            if (!isNaN(num)) setActiveStep(num);
                        }
                    });
                }, { rootMargin: '-40% 0px -40% 0px', threshold: 0 });

                for (let i = 1; i <= SECTIONS; i++) {
                    const el = getSection(i);
                    if (el) obs.observe(el);
                }
            }

            // On form submit: run validation across all sections before submitting
            const form = document.getElementById('createBookingForm');
            if (form) {
                form.addEventListener('submit', function (e) {
                    for (let i = 1; i <= SECTIONS; i++) {
                        const errs = validateSection(i);
                        if (errs.length) {
                            e.preventDefault();
                            showErr(i, errs.join(' '));
                            setActiveStep(i);
                            scrollToSection(i);
                            return;
                        }
                    }
                });
            }
        })();
    </script>
    <?php /* admin-components.js is loaded once, cache-busted, by admin-footer.php below.
             A second unversioned copy here can be served stale by the service worker and,
             via the __rhComponentsLoaded guard, blocks the fresh copy from initialising —
             the same "works only after two reloads" race fixed on calendar.php. */ ?>
<?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

