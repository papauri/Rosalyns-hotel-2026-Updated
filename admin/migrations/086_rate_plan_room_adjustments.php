<?php

/**
 * 086 - rate plans: a different amount per room type + price basis (owner 2026-11-15, step 8h).
 *
 *   rate_plans.room_adjustments  TEXT NULL     {"<room_id>":{"type":"percentage|fixed","value":n}}; NULL = every covered
 *                                              room type uses the plan's own adjustment_type / adjustment_value
 *   rate_plans.price_basis       VARCHAR(10)   'all_in' (default = today: the adjustment works on the all-in price)
 *                                              | 'base' (works on the rate before VAT and levy, then VAT/levy on top)
 *
 * Additive only: each column is added only when missing, no rows are touched, re-running is safe. Until this runs the
 * per-room-type rows and the basis choice stay hidden and every plan prices exactly as before.
 */

$rhRp086Has = static function (PDO $pdo, string $col): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_plans' AND COLUMN_NAME = ?"
    );
    $st->execute([$col]);
    return (int)$st->fetchColumn() > 0;
};

return [
    'name' => 'rate_plan_room_adjustments',

    'check' => function (PDO $pdo) use ($rhRp086Has): bool {
        return $rhRp086Has($pdo, 'room_adjustments') && $rhRp086Has($pdo, 'price_basis');
    },

    'up' => function (PDO $pdo) use ($rhRp086Has): void {
        if (!$rhRp086Has($pdo, 'room_adjustments')) {
            $pdo->exec("ALTER TABLE `rate_plans` ADD COLUMN `room_adjustments` TEXT NULL DEFAULT NULL COMMENT 'Per room type amount (JSON room_id => type/value); NULL = plan amount for all'");
            echo "    rate_plans.room_adjustments: added\n";
        }
        if (!$rhRp086Has($pdo, 'price_basis')) {
            $pdo->exec("ALTER TABLE `rate_plans` ADD COLUMN `price_basis` VARCHAR(10) NOT NULL DEFAULT 'all_in' COMMENT 'all_in = on the all-in price; base = on the rate before VAT and levy'");
            echo "    rate_plans.price_basis: added\n";
        }
    },
];
