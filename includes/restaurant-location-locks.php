<?php
/**
 * Restaurant POS location helpers.
 *
 * Centralises table/room validation and active-order conflict checks so POS,
 * room service, and admin pages all agree on when a table/room is already busy.
 */

function rh_restaurant_normalize_location(string $value): string {
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return mb_substr($value, 0, 50);
}

function rh_restaurant_active_order_sql_for_alias(string $alias = ''): string {
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return $prefix . "status NOT IN ('cancelled','voided','completed','paid')
        AND (" . $prefix . "status = 'placed' OR " . $prefix . "kitchen_status IN ('none','new','in_progress','ready','recalled'))";
}

function rh_restaurant_active_order_sql(): string {
    return rh_restaurant_active_order_sql_for_alias('');
}

function rh_restaurant_closed_order_sql_list(): string {
    return "'paid','completed','cancelled','voided'";
}

function rh_restaurant_tables_exist(PDO $pdo): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'restaurant_tables'");
    $stmt->execute();
    return (int)$stmt->fetchColumn() > 0;
}

function rh_restaurant_active_tables(PDO $pdo): array {
    if (!rh_restaurant_tables_exist($pdo)) return [];
    $stmt = $pdo->query("SELECT id, table_number, capacity, notes, is_active, display_order FROM restaurant_tables WHERE is_active = 1 ORDER BY display_order ASC, CAST(table_number AS UNSIGNED) ASC, table_number ASC");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function rh_restaurant_checked_in_rooms(PDO $pdo): array {
    $stmt = $pdo->query("SELECT b.id AS booking_id, b.booking_reference, b.guest_name, b.individual_room_id, ir.room_number, r.name AS room_type_name
        FROM bookings b
        INNER JOIN individual_rooms ir ON ir.id = b.individual_room_id
        LEFT JOIN rooms r ON r.id = b.room_id
        WHERE b.status = 'checked-in'
          AND ir.is_active = 1
        ORDER BY ir.display_order ASC, CAST(ir.room_number AS UNSIGNED) ASC, ir.room_number ASC");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function rh_restaurant_active_location_locks(PDO $pdo): array {
    $locks = ['tables' => [], 'rooms' => []];
    $activeSql = rh_restaurant_active_order_sql_for_alias('o');
        $sql = "SELECT o.id, o.reference, o.order_type, o.table_number, o.room_number, o.status, o.kitchen_status, o.created_at
                FROM stock_orders o
                WHERE " . $activeSql . "
                    AND o.order_type IN ('dine_in','room_service')
                    AND NOT EXISTS (
                            SELECT 1
                            FROM stock_orders newer
                            WHERE newer.id > o.id
                                AND newer.order_type = o.order_type
                                AND newer.status IN (" . rh_restaurant_closed_order_sql_list() . ")
                                AND (
                                        (o.order_type = 'dine_in' AND newer.table_number = o.table_number)
                                        OR (
                                                o.order_type = 'room_service'
                                                AND COALESCE(newer.room_number, newer.table_number) = COALESCE(o.room_number, o.table_number)
                                        )
                                )
                    )
                ORDER BY o.created_at DESC, o.id DESC";
    $stmt = $pdo->query($sql);
    if (!$stmt) return $locks;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['order_type'] === 'dine_in' && !empty($row['table_number'])) {
            $key = (string)$row['table_number'];
            if (!isset($locks['tables'][$key])) $locks['tables'][$key] = $row;
        }
        if ($row['order_type'] === 'room_service') {
            $key = (string)($row['room_number'] ?: $row['table_number']);
            if ($key !== '' && !isset($locks['rooms'][$key])) $locks['rooms'][$key] = $row;
        }
    }
    return $locks;
}

function rh_restaurant_find_active_order_conflict(PDO $pdo, string $orderType, string $location, bool $forUpdate = true): ?array {
    $location = rh_restaurant_normalize_location($location);
    if ($location === '') return null;

    $activeSql = rh_restaurant_active_order_sql_for_alias('o');
    $lockSql = $forUpdate ? ' FOR UPDATE' : '';
    if ($orderType === 'dine_in') {
                $stmt = $pdo->prepare("SELECT o.id, o.reference, o.order_type, o.status, o.kitchen_status, o.total_amount, o.created_at, o.table_number, o.room_number
                        FROM stock_orders o
                        WHERE o.order_type = 'dine_in'
                            AND o.table_number = ?
                            AND " . $activeSql . "
                            AND NOT EXISTS (
                                    SELECT 1
                                    FROM stock_orders newer
                                    WHERE newer.id > o.id
                                        AND newer.order_type = 'dine_in'
                                        AND newer.table_number = o.table_number
                                        AND newer.status IN (" . rh_restaurant_closed_order_sql_list() . ")
                            )
                        ORDER BY o.created_at DESC, o.id DESC
            LIMIT 1" . $lockSql);
        $stmt->execute([$location]);
    } elseif ($orderType === 'room_service') {
                $stmt = $pdo->prepare("SELECT o.id, o.reference, o.order_type, o.status, o.kitchen_status, o.total_amount, o.created_at, o.table_number, o.room_number
                        FROM stock_orders o
                        WHERE o.order_type = 'room_service'
                            AND (o.room_number = ? OR o.table_number = ?)
                            AND " . $activeSql . "
                            AND NOT EXISTS (
                                    SELECT 1
                                    FROM stock_orders newer
                                    WHERE newer.id > o.id
                                        AND newer.order_type = 'room_service'
                                        AND COALESCE(newer.room_number, newer.table_number) = COALESCE(o.room_number, o.table_number)
                                        AND newer.status IN (" . rh_restaurant_closed_order_sql_list() . ")
                            )
                        ORDER BY o.created_at DESC, o.id DESC
            LIMIT 1" . $lockSql);
        $stmt->execute([$location, $location]);
    } else {
        return null;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function rh_restaurant_conflict_message(string $orderType, string $location, array $conflict): string {
    $label = $orderType === 'room_service' ? 'Room' : 'Table';
    $status = trim(str_replace('_', ' ', (string)($conflict['kitchen_status'] ?: $conflict['status'])));
    $reference = (string)($conflict['reference'] ?? 'existing order');
    return $label . ' ' . $location . ' already has active order ' . $reference . ($status !== '' ? ' (' . $status . ')' : '') . '. Settle, serve, cancel, or use the existing order before opening another.';
}

function rh_restaurant_resolve_pos_location(PDO $pdo, string $orderType, ?string $rawLocation): array {
    $location = rh_restaurant_normalize_location((string)$rawLocation);
    $result = [
        'table_number' => null,
        'room_number' => null,
        'booking_id' => null,
        'individual_room_id' => null,
        'label' => '',
        'capacity' => null,
    ];

    if ($orderType === 'dine_in') {
        if ($location === '') throw new RuntimeException('Select a table before sending a dine-in order.');
        if (!rh_restaurant_tables_exist($pdo)) throw new RuntimeException('Restaurant tables are not configured. Ask an admin to set the table range first.');

        $stmt = $pdo->prepare("SELECT id, table_number, capacity, is_active FROM restaurant_tables WHERE table_number = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$location]);
        $table = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$table || (int)$table['is_active'] !== 1) {
            throw new RuntimeException('Table ' . $location . ' is not active in Restaurant Tables settings.');
        }

        $conflict = rh_restaurant_find_active_order_conflict($pdo, 'dine_in', $location, true);
        if ($conflict) throw new RuntimeException(rh_restaurant_conflict_message('dine_in', $location, $conflict));

        $result['table_number'] = (string)$table['table_number'];
        $result['label'] = 'Table ' . $table['table_number'];
        $result['capacity'] = $table['capacity'] !== null ? (int)$table['capacity'] : null;
        return $result;
    }

    if ($orderType === 'room_service') {
        if ($location === '') throw new RuntimeException('Select a room before sending a room-service order.');

        $roomStmt = $pdo->prepare("SELECT id, room_number, status FROM individual_rooms WHERE room_number = ? AND is_active = 1 LIMIT 1 FOR UPDATE");
        $roomStmt->execute([$location]);
        $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) throw new RuntimeException('Room ' . $location . ' is not configured as an active room.');

        $bookingStmt = $pdo->prepare("SELECT b.id, b.booking_reference, b.guest_name, b.guest_email, b.guest_phone, b.individual_room_id, ir.room_number
            FROM bookings b
            INNER JOIN individual_rooms ir ON ir.id = b.individual_room_id
            WHERE b.status = 'checked-in'
              AND b.individual_room_id = ?
            ORDER BY b.check_in_date DESC, b.id DESC
            LIMIT 1 FOR UPDATE");
        $bookingStmt->execute([(int)$room['id']]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new RuntimeException('Room ' . $location . ' has no checked-in guest booking.');

        $conflict = rh_restaurant_find_active_order_conflict($pdo, 'room_service', $location, true);
        if ($conflict) throw new RuntimeException(rh_restaurant_conflict_message('room_service', $location, $conflict));

        $result['table_number'] = (string)$booking['room_number'];
        $result['room_number'] = (string)$booking['room_number'];
        $result['booking_id'] = (int)$booking['id'];
        $result['individual_room_id'] = (int)$booking['individual_room_id'];
        $result['label'] = 'Room ' . $booking['room_number'];
        $result['booking'] = $booking;
        return $result;
    }

    return $result;
}
