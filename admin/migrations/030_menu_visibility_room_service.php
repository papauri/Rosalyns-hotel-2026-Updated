<?php
/**
 * Migration 030: Menu visibility per surface + room_service role/permissions.
 *
 *  - Adds show_pos / show_room_service flags to food_menu and drink_menu so admins
 *    can choose which menu items appear in each ordering surface (POS, KDS/BDS/CDS
 *    are already routed by `station`, Room Service is the 4th surface).
 *  - Extends admin_users.role enum with 'room_service'.
 *  - Adds room_service_view / room_service_manage permission keys with sane role
 *    defaults via the existing role_permissions infrastructure.
 *  - Idempotent.
 */
require_once __DIR__ . '/../../config/database.php';

function out030(string $m, string $t = 'info'): void { echo "[$t] $m\n"; }
function colExists030(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function tableExists030(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

try {
    /* 1. food_menu / drink_menu visibility flags */
    foreach (['food_menu','drink_menu'] as $tbl) {
        foreach ([
            ['show_pos',           "TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Show in POS / restaurant ordering'"],
            ['show_room_service',  "TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Show in Room Service ordering'"],
        ] as [$col, $def]) {
            if (!colExists030($pdo, $tbl, $col)) {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN {$col} {$def}");
                out030("{$tbl}.{$col} added", 'ok');
            } else {
                out030("{$tbl}.{$col} exists", 'skip');
            }
        }
    }

    /* 2. Extend role enum to include room_service */
    $pdo->exec("ALTER TABLE admin_users MODIFY role ENUM('admin','manager','receptionist','housekeeping','accountant','viewer','restaurant_staff','chef','bar_staff','coffee_staff','room_service') NOT NULL DEFAULT 'receptionist'");
    out030('admin_users.role extended with room_service', 'ok');

    /* 3. Permissions — only if the permissions table exists */
    if (tableExists030($pdo, 'permissions')) {
        $upsert = $pdo->prepare("INSERT INTO permissions (permission_key, label, description, category)
                                 VALUES (?, ?, ?, 'Operations')
                                 ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description)");
        $upsert->execute(['room_service_view',   'View Room Service Dashboard', 'See active and historical in-room dining orders']);
        $upsert->execute(['room_service_manage', 'Manage Room Service',         'Place, deliver and post in-room dining charges to bookings']);
        $upsert->execute(['kds_reports',         'View KDS Reports',            'View daily KDS/BDS/CDS reports and email them']);
        out030('permissions upserted (room_service_view, room_service_manage, kds_reports)', 'ok');

        if (tableExists030($pdo, 'role_permissions')) {
            $rp = $pdo->prepare("INSERT IGNORE INTO role_permissions (role, permission_key) VALUES (?, ?)");
            // admin / manager get everything new
            foreach (['admin','manager'] as $r) {
                foreach (['room_service_view','room_service_manage','kds_reports'] as $p) $rp->execute([$r, $p]);
            }
            // receptionist & restaurant_staff can place room-service charges from desk/POS
            foreach (['receptionist','restaurant_staff'] as $r) {
                $rp->execute([$r, 'room_service_view']);
                $rp->execute([$r, 'room_service_manage']);
            }
            // dedicated room_service role
            foreach (['room_service_view','room_service_manage'] as $p) $rp->execute(['room_service', $p]);
            // station staff get the report for their own station
            foreach (['chef','bar_staff','coffee_staff'] as $r) $rp->execute([$r, 'kds_reports']);
            out030('role_permissions seeded for new keys', 'ok');
        }
    } else {
        out030('permissions table missing — skipping permission seed', 'warn');
    }

    /* 4. Optional index for fast room-service order queries */
    $idxStmt = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_orders' AND INDEX_NAME='idx_orders_type_created'");
    $idxStmt->execute();
    if (!$idxStmt->fetchColumn()) {
        try {
            $pdo->exec("CREATE INDEX idx_orders_type_created ON stock_orders (order_type, created_at)");
            out030('stock_orders.idx_orders_type_created created', 'ok');
        } catch (Throwable $e) {
            out030('idx_orders_type_created skipped: '.$e->getMessage(), 'warn');
        }
    } else {
        out030('idx_orders_type_created exists', 'skip');
    }

    out030('Migration 030 complete.', 'done');
} catch (Throwable $e) {
    out030('FAIL: '.$e->getMessage(), 'err');
    exit(1);
}
