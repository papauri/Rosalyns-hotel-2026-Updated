<?php

/**
 * 051 - bookings.payment_status accepts 'refunded' (additive).
 *
 * recalculateBookingFinancials() sets 'refunded' on a cancelled booking whose money has all gone back
 * out, but the column was ENUM('unpaid','partial','paid'), so under strict SQL the write failed and
 * cancelRoomBookingSettled() rolled back. Values are only ADDED - nothing removed, renamed or rewritten;
 * the column's existing nullability and default are kept as they are.
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
        $st = $pdo->prepare("SELECT COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'payment_status'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || stripos((string)$row['COLUMN_TYPE'], 'enum(') !== 0) {
            return;
        }
        preg_match_all("/'((?:[^']|'')*)'/", $row['COLUMN_TYPE'], $m);
        $values = $m[1] ?? [];
        if (!in_array('refunded', $values, true)) {
            $values[] = 'refunded';
        }
        $list = implode(',', array_map(static function (string $v): string {
            return "'" . $v . "'";
        }, $values));
        $nullable = $row['IS_NULLABLE'] === 'YES';
        $default = $row['COLUMN_DEFAULT'];
        // MariaDB reports string defaults already quoted ('unpaid') and a NULL default as the word NULL;
        // MySQL reports the bare value. Normalise so the default is never double-quoted.
        if ($default !== null && strlen($default) >= 2 && $default[0] === "'" && substr($default, -1) === "'") {
            $default = str_replace("''", "'", substr($default, 1, -1));
        } elseif ($default !== null && $nullable && strtoupper($default) === 'NULL') {
            $default = null;
        }
        $null = $nullable ? 'NULL' : 'NOT NULL';
        $def = $default !== null ? ' DEFAULT ' . $pdo->quote($default) : ($nullable ? ' DEFAULT NULL' : '');
        $pdo->exec("ALTER TABLE bookings MODIFY COLUMN payment_status ENUM($list) $null$def");
    },
];
