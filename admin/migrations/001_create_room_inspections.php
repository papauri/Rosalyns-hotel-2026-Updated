<?php

/**
 * 001 — create `room_inspections`.
 *
 * The table was never created on this installation, and the code that creates it
 * lazily could never have succeeded: it declared
 *
 *     individual_room_id INT NOT NULL,
 *     FOREIGN KEY (individual_room_id) REFERENCES individual_rooms(id)
 *
 * while `individual_rooms.id` is `INT UNSIGNED`. MySQL 8 rejects that foreign key
 * (errno 3780, incompatible column types), and the lazy CREATE sat inside a
 * `catch (PDOException)`, so the failure was swallowed on every attempt.
 *
 * Two consequences, both silent:
 *   - moving a room to `inspection` status recorded nothing;
 *   - `getRoomsRequiringInspection()` (includes/room-management.php) caught the
 *     missing-table error and returned [], so the inspection queue on
 *     admin/room-dashboard.php was permanently empty.
 *
 * Column types below match the app's own DDL except for the two FK columns, which
 * are widened to INT UNSIGNED so the constraints are actually accepted.
 * `inspector_id` references admin_users(id) (also INT UNSIGNED) and is nullable —
 * ON DELETE SET NULL keeps an inspection record when a staff account is removed.
 */

return [
    'name' => 'create_room_inspections',

    'check' => function (PDO $pdo): bool {
        $stmt = $pdo->query("SHOW TABLES LIKE 'room_inspections'");
        return $stmt->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE room_inspections (
                id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                individual_room_id INT UNSIGNED NOT NULL,
                status             ENUM('pending', 'passed', 'failed') NOT NULL DEFAULT 'pending',
                inspector_id       INT UNSIGNED NULL,
                checklist          JSON NULL,
                notes              TEXT NULL,
                inspected_at       DATETIME NULL,
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_room_status (individual_room_id, status),
                KEY idx_status (status),
                CONSTRAINT fk_room_inspections_room
                    FOREIGN KEY (individual_room_id) REFERENCES individual_rooms(id) ON DELETE CASCADE,
                CONSTRAINT fk_room_inspections_inspector
                    FOREIGN KEY (inspector_id) REFERENCES admin_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
];
