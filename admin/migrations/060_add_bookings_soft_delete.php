<?php

/**
 * 060 - recoverable booking deletion: `bookings.deleted_at`, `deleted_by`, `deleted_reason`.
 *
 * Additive only (owner decision 2026-10-08). A deleted booking stays in the table so it can be
 * restored and so its payments, invoices and audit trail keep pointing at a real row; these
 * columns record exactly when, by whom and why it was deleted. Restoring clears them again
 * (the restore itself is written to booking_audit_log / booking_timeline_logs).
 */

return [
    'name' => 'add_bookings_soft_delete',

    'check' => function (PDO $pdo): bool {
        $have = 0;
        foreach (['deleted_at', 'deleted_by', 'deleted_reason'] as $col) {
            $have += $pdo->query("SHOW COLUMNS FROM bookings LIKE " . $pdo->quote($col))->rowCount() > 0 ? 1 : 0;
        }
        $idx = $pdo->query("SHOW INDEX FROM bookings WHERE Key_name = 'idx_bookings_deleted_at'")->rowCount() > 0;
        return $have === 3 && $idx;
    },

    'up' => function (PDO $pdo): void {
        $add = [
            'deleted_at'     => "DATETIME NULL DEFAULT NULL COMMENT 'When the booking was deleted (NULL = not deleted)'",
            'deleted_by'     => "INT UNSIGNED NULL DEFAULT NULL COMMENT 'admin_users.id who deleted it'",
            'deleted_reason' => "VARCHAR(500) NULL DEFAULT NULL COMMENT 'Why it was deleted (required in the UI)'",
        ];
        foreach ($add as $col => $def) {
            if ($pdo->query("SHOW COLUMNS FROM bookings LIKE " . $pdo->quote($col))->rowCount() === 0) {
                $pdo->exec("ALTER TABLE bookings ADD COLUMN `{$col}` {$def}");
            }
        }
        if ($pdo->query("SHOW INDEX FROM bookings WHERE Key_name = 'idx_bookings_deleted_at'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE bookings ADD INDEX idx_bookings_deleted_at (deleted_at)");
        }
    },
];
