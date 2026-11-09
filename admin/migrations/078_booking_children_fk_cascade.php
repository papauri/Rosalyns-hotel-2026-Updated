<?php

/**
 * 078 - booking child rows go with their booking (owner 2026-11-09: "Fix the deleting of a booking issue";
 * standing rule "linked data goes with its parent").
 *
 * Six tables keyed by booking_id had no foreign key to bookings, so a hard DELETE of a booking (only test and
 * harness cleanups do that; the app soft-deletes via includes/booking-soft-delete.php) left their rows behind:
 * booking_rooms, booking_notes, booking_audit_log, tentative_booking_log, booking_status_log, guest_communication_log.
 * booking_charges / booking_payments / booking_timeline_logs / cancellation_log etc. already cascade.
 *
 * Per table, skipped when the cascade FK is already there (re-running is safe, a part-done run finishes):
 *   1. delete rows whose booking no longer exists (the FK cannot be added while they exist);
 *   2. booking_status_log / guest_communication_log only: booking_id INT -> INT UNSIGNED to match bookings.id;
 *   3. ADD FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE ON UPDATE CASCADE.
 *
 * Money tables are deliberately NOT cascaded (KEEP): payments, booking_financial_audit, credit_notes,
 * credit_note_applications, quotations.
 */

$rhBookingChildFks = [
    // table => [constraint name, column definition to MODIFY to first (null = already INT UNSIGNED)]
    'booking_rooms'           => ['fk_booking_rooms_booking', null],
    'booking_notes'           => ['fk_booking_notes_booking', null],
    'booking_audit_log'       => ['fk_booking_audit_log_booking', null],
    'tentative_booking_log'   => ['fk_tentative_booking_log_booking', null],
    'booking_status_log'      => ['fk_booking_status_log_booking', 'INT UNSIGNED NOT NULL'],
    'guest_communication_log' => ['fk_guest_communication_log_booking', 'INT UNSIGNED NOT NULL'],
];

$rhHasCascadeFk = static function (PDO $pdo, string $table): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k
           JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
             ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = 'booking_id'
            AND k.REFERENCED_TABLE_NAME = 'bookings' AND k.REFERENCED_COLUMN_NAME = 'id'
            AND rc.DELETE_RULE = 'CASCADE'"
    );
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
};

$rhTableExists = static function (PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
};

return [
    'name' => 'booking_children_fk_cascade',

    'check' => function (PDO $pdo) use ($rhBookingChildFks, $rhHasCascadeFk, $rhTableExists): bool {
        foreach (array_keys($rhBookingChildFks) as $table) {
            if ($rhTableExists($pdo, $table) && !$rhHasCascadeFk($pdo, $table)) {
                return false;
            }
        }
        return true;
    },

    'up' => function (PDO $pdo) use ($rhBookingChildFks, $rhHasCascadeFk, $rhTableExists): void {
        foreach ($rhBookingChildFks as $table => [$fkName, $modifyTo]) {
            if (!$rhTableExists($pdo, $table) || $rhHasCascadeFk($pdo, $table)) {
                continue;
            }
            $removed = $pdo->exec(
                "DELETE x FROM `$table` x LEFT JOIN bookings b ON b.id = x.booking_id WHERE b.id IS NULL"
            );
            echo "    $table: removed " . (int)$removed . " row(s) whose booking no longer exists\n";
            if ($modifyTo !== null) {
                $pdo->exec("ALTER TABLE `$table` MODIFY `booking_id` $modifyTo");
            }
            $pdo->exec(
                "ALTER TABLE `$table` ADD CONSTRAINT `$fkName` FOREIGN KEY (`booking_id`)
                 REFERENCES bookings(`id`) ON DELETE CASCADE ON UPDATE CASCADE"
            );
            echo "    $table: booking_id now cascades with its booking\n";
        }
    },
];
