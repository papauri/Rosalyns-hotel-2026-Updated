<?php

/**
 * 062 - stock count lines remember WHEN they were counted: `stock_count_lines.counted_at`,
 * `stock_count_lines.counted_by`.
 *
 * Additive only (owner decision 2026-10-08). Until now a line that nobody counted looked exactly
 * like a line counted as zero (actual_quantity 0), and the count could not tell which sales and
 * receipts happened before vs after a line was physically counted. counted_at NULL = not counted
 * yet; counted_at set = the moment the reading was entered (explicit zero included).
 *
 * Backfill: lines of counts already submitted/approved are stamped with the count's last update
 * time so they are not suddenly "uncounted". Draft counts are left uncounted on purpose - their
 * readings must be re-entered so each carries a real timestamp.
 */

return [
    'name' => 'stock_count_lines_counted_at',

    'check' => function (PDO $pdo): bool {
        return $pdo->query("SHOW COLUMNS FROM stock_count_lines LIKE 'counted_at'")->rowCount() > 0
            && $pdo->query("SHOW COLUMNS FROM stock_count_lines LIKE 'counted_by'")->rowCount() > 0
            && $pdo->query("SHOW COLUMNS FROM stock_counts LIKE 'snapshot_at'")->rowCount() > 0;
    },

    'up' => function (PDO $pdo): void {
        // When the line snapshots were taken (start, or last reopen). created_at stays the true start.
        if ($pdo->query("SHOW COLUMNS FROM stock_counts LIKE 'snapshot_at'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE stock_counts ADD COLUMN `snapshot_at` DATETIME NULL DEFAULT NULL COMMENT 'When the line system quantities were snapshotted (start or last reopen)'");
            $pdo->exec("UPDATE stock_counts SET snapshot_at = created_at WHERE snapshot_at IS NULL");
        }
        $addedCountedAt = false;
        if ($pdo->query("SHOW COLUMNS FROM stock_count_lines LIKE 'counted_at'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE stock_count_lines ADD COLUMN `counted_at` DATETIME NULL DEFAULT NULL COMMENT 'When this line was physically counted; NULL = not counted yet'");
            $addedCountedAt = true;
        }
        if ($pdo->query("SHOW COLUMNS FROM stock_count_lines LIKE 'counted_by'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE stock_count_lines ADD COLUMN `counted_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Admin user who entered this line reading'");
        }

        if ($addedCountedAt) {
            $pdo->exec("UPDATE stock_count_lines scl
                INNER JOIN stock_counts sc ON sc.id = scl.count_id
                SET scl.counted_at = COALESCE(sc.updated_at, sc.created_at), scl.counted_by = sc.counted_by
                WHERE sc.status IN ('submitted', 'approved') AND scl.counted_at IS NULL");
        }
    },
];
