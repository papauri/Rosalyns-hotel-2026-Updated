<?php

/**
 * POS notices - the station -> till messages about an order that changed under the cashier:
 * an order cancelled or voided from another screen, or a line 86'd by a station.
 *
 * They are rows in station_messages (source 'station', to_user_id = the cashier who opened the
 * order). Two additive columns make them actionable instead of plain chat text:
 *   notice_type     'order_cancelled' | 'order_voided' | 'item_86'
 *   notice_item_id  stock_order_items.id of the 86'd line (item_86 only)
 * The till reads them back through api/kds-action.php get_pos_inbox together with the order's
 * CURRENT state (status, paid, pending refund, line status), so each notice offers only the
 * follow-ups that still make sense. Read state lives on the server (seen_at, pos_acknowledged):
 * once handled, a notice is gone on every till of that user and shows as handled on the stations.
 *
 * Never call these inside an open transaction: adding a column is DDL and would commit it.
 */

if (!function_exists('rh_station_messages_ensure')) {
    /** Create / upgrade station_messages (idempotent, cached per request). */
    function rh_station_messages_ensure(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS station_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            station VARCHAR(30) NOT NULL,
            message VARCHAR(255) NOT NULL,
            sent_by INT UNSIGNED NULL,
            sent_by_name VARCHAR(120) NOT NULL DEFAULT '',
            source VARCHAR(20) NOT NULL DEFAULT 'pos',
            is_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
            acknowledged_by INT UNSIGNED NULL,
            acknowledged_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_station_messages_station_created (station, created_at),
            INDEX idx_station_messages_ack (is_acknowledged)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = [
            'priority'            => "ENUM('normal','urgent') NOT NULL DEFAULT 'normal'",
            'seen_at'             => 'DATETIME NULL',
            'reply_message'       => 'VARCHAR(255) NULL',
            'replied_at'          => 'DATETIME NULL',
            'replied_by_name'     => 'VARCHAR(120) NULL',
            'order_id'            => 'INT UNSIGNED NULL',
            'order_ref'           => 'VARCHAR(40) NULL',
            'to_user_id'          => 'INT UNSIGNED NULL',
            'pos_acknowledged'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'pos_acknowledged_at' => 'DATETIME NULL',
            'pos_acknowledged_by' => 'INT UNSIGNED NULL',
            'notice_type'         => 'VARCHAR(30) NULL',
            'notice_item_id'      => 'INT UNSIGNED NULL',
        ];
        $chk = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'station_messages'");
        $chk->execute();
        $have = array_map('strtolower', $chk->fetchAll(PDO::FETCH_COLUMN));
        foreach ($cols as $name => $definition) {
            if (!in_array($name, $have, true)) {
                $pdo->exec("ALTER TABLE `station_messages` ADD COLUMN `{$name}` {$definition}");
            }
        }
        $done = true;
    }
}

if (!function_exists('rh_pos_notice_send')) {
    /**
     * Send an actionable notice to one cashier. Returns the message id (0 when nothing was sent).
     *
     * @param array{station?:string,message:string,sent_by?:int,sent_by_name?:string,order_id:int,order_ref:string,
     *              to_user_id:int,priority?:string,notice_type:string,notice_item_id?:int} $n
     */
    function rh_pos_notice_send(PDO $pdo, array $n): int
    {
        $toUser = (int)($n['to_user_id'] ?? 0);
        $orderId = (int)($n['order_id'] ?? 0);
        $type = (string)($n['notice_type'] ?? '');
        if ($toUser <= 0 || $orderId <= 0 || $type === '' || trim((string)($n['message'] ?? '')) === '') {
            return 0;
        }
        rh_station_messages_ensure($pdo);
        $station = in_array((string)($n['station'] ?? 'kitchen'), ['kitchen', 'bar', 'coffee_bar'], true) ? (string)$n['station'] : 'kitchen';
        $priority = ((string)($n['priority'] ?? 'urgent')) === 'normal' ? 'normal' : 'urgent';
        $pdo->prepare("INSERT INTO station_messages
                (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref, to_user_id, is_acknowledged, acknowledged_at, notice_type, notice_item_id)
             VALUES (?, ?, ?, ?, 'station', ?, ?, ?, ?, 0, NULL, ?, ?)")
            ->execute([
                $station,
                mb_substr((string)$n['message'], 0, 255),
                !empty($n['sent_by']) ? (int)$n['sent_by'] : null,
                mb_substr((string)($n['sent_by_name'] ?? ''), 0, 120),
                $priority,
                $orderId,
                mb_substr((string)($n['order_ref'] ?? ''), 0, 40),
                $toUser,
                mb_substr($type, 0, 30),
                !empty($n['notice_item_id']) ? (int)$n['notice_item_id'] : null,
            ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('rh_pos_notice_actor_label')) {
    /** "Kitchen" / "Bar" / "Coffee Bar" / "Manager" for the sentence in a notice. */
    function rh_pos_notice_actor_label(string $role): string
    {
        switch ($role) {
            case 'chef':
                return 'Kitchen';
            case 'bar_staff':
                return 'Bar';
            case 'coffee_staff':
                return 'Coffee Bar';
            default:
                return $role !== '' ? ucfirst(str_replace('_', ' ', $role)) : 'Staff';
        }
    }
}
