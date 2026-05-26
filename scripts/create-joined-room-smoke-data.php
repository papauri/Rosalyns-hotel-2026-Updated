<?php
/**
 * Creates or reactivates a controlled joined-room smoke test product.
 * Run from project root: php scripts/create-joined-room-smoke-data.php [deactivate]
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
/** @var PDO $pdo */

$deactivate = ($argv[1] ?? '') === 'deactivate';
$slug = 'qa-joined-room-test';
$roomNumbers = ['QA-JOIN-101A', 'QA-JOIN-101B'];

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id FROM rooms WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $roomTypeId = (int)($stmt->fetchColumn() ?: 0);

    if ($roomTypeId === 0) {
        $insertRoom = $pdo->prepare('
            INSERT INTO rooms (
                name, slug, description, short_description, price_per_night, max_guests,
                rooms_available, total_rooms, bed_type, image_url, badge, amenities,
                is_featured, is_active, display_order, price_single_occupancy,
                price_double_occupancy, price_triple_occupancy, child_price_multiplier
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $insertRoom->execute([
            'QA Joined Room Test',
            $slug,
            'Temporary joined-room smoke test product.',
            'Temporary joined-room smoke test product.',
            180000,
            4,
            1,
            1,
            'Two adjoining rooms',
            'images/rooms/room-placeholder.jpg',
            'Test',
            'Wi-Fi, Adjoining rooms',
            0,
            $deactivate ? 0 : 1,
            999,
            180000,
            180000,
            180000,
            50,
        ]);
        $roomTypeId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare('
            UPDATE rooms
            SET is_active = ?, rooms_available = 1, total_rooms = 1, max_guests = 4,
                price_per_night = 180000, price_single_occupancy = 180000,
                price_double_occupancy = 180000, price_triple_occupancy = 180000
            WHERE id = ?
        ')->execute([$deactivate ? 0 : 1, $roomTypeId]);
    }

    $individualIds = [];
    foreach ($roomNumbers as $index => $roomNumber) {
        $selectRoom = $pdo->prepare('SELECT id FROM individual_rooms WHERE room_number = ? LIMIT 1');
        $selectRoom->execute([$roomNumber]);
        $individualId = (int)($selectRoom->fetchColumn() ?: 0);

        if ($individualId === 0) {
            $insertIndividual = $pdo->prepare('
                INSERT INTO individual_rooms (
                    room_type_id, room_number, room_name, floor, status,
                    max_guests_override, is_active, display_order
                ) VALUES (?, ?, ?, ?, ?, ?, 1, ?)
            ');
            $insertIndividual->execute([
                $roomTypeId,
                $roomNumber,
                'QA Joined Test ' . ($index + 1),
                'QA',
                'available',
                2,
                999 + $index,
            ]);
            $individualId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare('
                UPDATE individual_rooms
                SET room_type_id = ?, status = ?, max_guests_override = 2, is_active = 1
                WHERE id = ?
            ')->execute([$roomTypeId, 'available', $individualId]);
        }

        $individualIds[] = $individualId;
    }

    sort($individualIds);
    [$roomAId, $roomBId] = $individualIds;

    $comboStmt = $pdo->prepare('SELECT id FROM room_combinations WHERE room_a_id = ? AND room_b_id = ? LIMIT 1');
    $comboStmt->execute([$roomAId, $roomBId]);
    $comboId = (int)($comboStmt->fetchColumn() ?: 0);

    if ($comboId > 0) {
        $pdo->prepare('
            UPDATE room_combinations
            SET combined_name = ?, combined_room_type_id = ?, price_override = ?,
                max_guests_combined = 4, is_active = ?, notes = ?
            WHERE id = ?
        ')->execute([
            'QA Joined 101A + 101B',
            $roomTypeId,
            180000,
            $deactivate ? 0 : 1,
            'Temporary smoke test joined rooms',
            $comboId,
        ]);
    } else {
        $pdo->prepare('
            INSERT INTO room_combinations (
                combined_name, combined_room_type_id, room_a_id, room_b_id,
                price_override, max_guests_combined, is_active, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            'QA Joined 101A + 101B',
            $roomTypeId,
            $roomAId,
            $roomBId,
            180000,
            4,
            $deactivate ? 0 : 1,
            'Temporary smoke test joined rooms',
        ]);
        $comboId = (int)$pdo->lastInsertId();
    }

    $pdo->commit();
    echo ($deactivate ? 'deactivated' : 'ready') . " room_type_id={$roomTypeId} room_a={$roomAId} room_b={$roomBId} combo_id={$comboId}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
