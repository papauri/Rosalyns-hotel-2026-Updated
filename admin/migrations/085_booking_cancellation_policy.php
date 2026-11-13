<?php

/**
 * 085 - cancellation & no-show policies (owner-approved 2026-11-13/14, .claude/CANCELLATION_POLICY_SPEC.md).
 *
 *   bookings.cancellation_policy_snapshot   the cancellation / no-show policy locked onto a booking when it was made
 *                                           (JSON with the generated fine print); NULL = today's rules for ever
 *
 * Written once per new booking, and only while Hotel Settings > Refunds & cancellations > "Use cancellation policies for
 * new bookings" is switched on (code default Off). Existing bookings keep NULL.
 *
 * Additive only: the column is added only when missing, no rows are touched, re-running is safe. Until this runs the
 * policy editor stays hidden and every cancel / no-show runs exactly as before.
 */

$rhCancelPolicyHas = static function (PDO $pdo): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'cancellation_policy_snapshot'"
    );
    $st->execute();
    return (int)$st->fetchColumn() > 0;
};

return [
    'name' => 'booking_cancellation_policy',

    'check' => function (PDO $pdo) use ($rhCancelPolicyHas): bool {
        return $rhCancelPolicyHas($pdo);
    },

    'up' => function (PDO $pdo) use ($rhCancelPolicyHas): void {
        if ($rhCancelPolicyHas($pdo)) {
            return;
        }
        $pdo->exec("ALTER TABLE `bookings` ADD COLUMN `cancellation_policy_snapshot` TEXT NULL DEFAULT NULL COMMENT 'Cancellation/no-show policy locked at booking (JSON); NULL = legacy rules'");
        echo "    bookings.cancellation_policy_snapshot: added\n";
    },
];
