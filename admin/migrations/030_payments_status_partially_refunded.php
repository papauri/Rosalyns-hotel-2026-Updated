<?php

/**
 * 030 — payments.payment_status accepts 'partially_refunded' and 'failed' (additive).
 *
 * The canonical net-paid rule counts originals in completed/paid/refunded/partially_refunded,
 * and the refund flow now sets 'partially_refunded' on part-refunded originals (and the
 * payments API can mark a pending payment 'failed'). The column was an ENUM without those
 * two values, so writing them silently stored '' and dropped the payment from every total.
 * Values are only ADDED — no existing value is removed, renamed or rewritten.
 */

return [
    'name' => 'payments_status_partially_refunded',

    'check' => function (PDO $pdo): bool {
        $st = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_status'");
        $st->execute();
        $type = (string)$st->fetchColumn();
        return $type === '' || (strpos($type, "'partially_refunded'") !== false && strpos($type, "'failed'") !== false);
    },

    'up' => function (PDO $pdo): void {
        $st = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_status'");
        $st->execute();
        $type = (string)$st->fetchColumn();
        if ($type === '' || stripos($type, 'enum(') !== 0) {
            return; // column missing or already a free-form type — nothing to widen
        }
        preg_match_all("/'((?:[^']|'')*)'/", $type, $m);
        $values = $m[1] ?? [];
        foreach (['partially_refunded', 'failed'] as $add) {
            if (!in_array($add, $values, true)) {
                $values[] = $add;
            }
        }
        $list = implode(',', array_map(static function (string $v): string {
            return "'" . $v . "'";
        }, $values));
        $pdo->exec("ALTER TABLE payments MODIFY COLUMN payment_status ENUM($list) NULL DEFAULT 'pending'");
    },
];
