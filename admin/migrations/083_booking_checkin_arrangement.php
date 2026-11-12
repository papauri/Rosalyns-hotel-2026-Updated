<?php

/**
 * 083 - pay-later check-in arrangement (owner 2026-11-12: "some groups/individuals like ICTAM have special arrangements
 * with the hotel that they can also be checked in even without the full payment" + "the hotel owner is staying the night
 * and won't pay for any reason").
 *
 *   bookings.checkin_without_payment   1 = this booking may be checked in with nothing paid (agreed arrangement)
 *   bookings.checkin_arrangement_note  the short reason staff gave ("ICTAM - invoiced monthly", "Owner's stay")
 *
 * Switched on/off on Booking Details by anyone with Edit Booking (each change goes to the booking history). It only
 * relaxes the check-in payment gate (validateCheckIn): price, VAT, levy, the balance owed and the checkout-with-balance
 * rule are unchanged.
 *
 * Additive only: each column is added only when missing, no rows are touched (default 0 = off), re-running is safe.
 * Until this runs the switch is hidden and check-in works exactly as before.
 */

$rhCheckinArrColumns = [
    'checkin_without_payment'  => "ADD COLUMN `checkin_without_payment` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Pay-later arrangement: may check in with nothing paid'",
    'checkin_arrangement_note' => "ADD COLUMN `checkin_arrangement_note` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Why the pay-later arrangement applies'",
];

$rhCheckinArrHas = static function (PDO $pdo, string $column): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = ?"
    );
    $st->execute([$column]);
    return (int)$st->fetchColumn() > 0;
};

return [
    'name' => 'booking_checkin_arrangement',

    'check' => function (PDO $pdo) use ($rhCheckinArrColumns, $rhCheckinArrHas): bool {
        foreach (array_keys($rhCheckinArrColumns) as $col) {
            if (!$rhCheckinArrHas($pdo, $col)) {
                return false;
            }
        }
        return true;
    },

    'up' => function (PDO $pdo) use ($rhCheckinArrColumns, $rhCheckinArrHas): void {
        foreach ($rhCheckinArrColumns as $col => $ddl) {
            if ($rhCheckinArrHas($pdo, $col)) {
                continue;
            }
            $pdo->exec('ALTER TABLE `bookings` ' . $ddl);
            echo "    bookings.{$col}: added\n";
        }
    },
];
