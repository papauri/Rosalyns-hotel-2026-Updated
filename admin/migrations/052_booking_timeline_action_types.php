<?php

/**
 * 052 - booking_timeline_logs.action_type accepts 'financial' and 'date_adjustment' (additive).
 *
 * cancelRoomBookingSettled(), the credit-note flows and the stay-date adjustment log timeline events
 * with those types, but the column ENUM lacked them, so under strict SQL the timeline write failed
 * ("Data truncated") and the audit entry was lost. Values are only ADDED.
 */

return [
    'name' => 'booking_timeline_action_types',

    'check' => function (PDO $pdo): bool {
        $st = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_timeline_logs' AND COLUMN_NAME = 'action_type'");
        $st->execute();
        $type = (string)$st->fetchColumn();
        return $type === '' || stripos($type, 'enum(') !== 0
            || (strpos($type, "'financial'") !== false && strpos($type, "'date_adjustment'") !== false);
    },

    'up' => function (PDO $pdo): void {
        $st = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_timeline_logs' AND COLUMN_NAME = 'action_type'");
        $st->execute();
        $type = (string)$st->fetchColumn();
        if ($type === '' || stripos($type, 'enum(') !== 0) {
            return;
        }
        preg_match_all("/'((?:[^']|'')*)'/", $type, $m);
        $values = $m[1] ?? [];
        foreach (['financial', 'date_adjustment'] as $add) {
            if (!in_array($add, $values, true)) {
                $values[] = $add;
            }
        }
        $list = implode(',', array_map(static function (string $v): string {
            return "'" . $v . "'";
        }, $values));
        $pdo->exec("ALTER TABLE booking_timeline_logs MODIFY COLUMN action_type ENUM($list) NOT NULL");
    },
];
