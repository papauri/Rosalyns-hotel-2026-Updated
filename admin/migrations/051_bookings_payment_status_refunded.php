<?php

/**
 * 051 - bookings.payment_status accepts 'refunded' (additive).
 *
 * recalculateBookingFinancials() sets 'refunded' on a cancelled booking whose money has all gone back
 * out, but the column was ENUM('unpaid','partial','paid'), so under strict SQL the write failed and
 * cancelRoomBookingSettled() rolled back. Values are only ADDED - nothing removed, renamed or rewritten.
 */

return [
    'name' => 'bookings_payment_status_refunded',

    'check' => function (PDO $pdo): bool {
        $st = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'payment_status'");
        $st->execute();
        $type = (string)$st->fetchColumn();
        return $type === '' || stripos($type, 'enum(') !== 0 || strpos($type, "'refunded'") !== false;
    },

    'up' => function (PDO $pdo): void {
        $st = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'payment_status'");
        $st->execute();
        $type = (string)$st->fetchColumn();
        if ($type === '' || stripos($type, 'enum(') !== 0) {
            return;
        }
        preg_match_all("/'((?:[^']|'')*)'/", $type, $m);
        $values = $m[1] ?? [];
        if (!in_array('refunded', $values, true)) {
            $values[] = 'refunded';
        }
        $list = implode(',', array_map(static function (string $v): string {
            return "'" . $v . "'";
        }, $values));
        $pdo->exec("ALTER TABLE bookings MODIFY COLUMN payment_status ENUM($list) NOT NULL DEFAULT 'unpaid'");
    },
];
