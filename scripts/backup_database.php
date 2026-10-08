<?php

/**
 * Automated Database Backup (CLI wrapper around includes/db-backup.php)
 *
 * mysqldump when the host allows it, otherwise a streaming pure-PHP dumper.
 * Output: <repo>/backups/YYYY/MM/db-YYYYMMDD-HHMMSS.sql.gz
 * Rotation: 14 daily / 8 weekly / 12 monthly. Log: logs/backup.log.
 * Updates last_backup_at / last_backup_path / last_backup_size, EXCEPT on Windows: a laptop
 * copy of the live database must never stop the server's own nightly backup.
 *
 * Usage:
 *   php scripts/backup_database.php           # normal run
 *   php scripts/backup_database.php --quiet   # cron-friendly (only prints on error)
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/database.php';
require_once $ROOT . '/includes/db-backup.php';

$quiet = in_array('--quiet', $argv ?? [], true);

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection not available.\n");
    exit(1);
}

$stamp = PHP_OS_FAMILY !== 'Windows';
if (!$stamp && !$quiet) {
    echo "Note: running on Windows - last_backup_at will NOT be updated (local copy only)." . PHP_EOL;
}

$r = rh_db_backup_run($pdo, $ROOT, ['stamp' => $stamp]);
if (!$r['ok'] && ($r['error'] ?? '') === 'another backup is already running') {
    if (!$quiet) {
        echo 'Backup skipped: another backup process is already running.' . PHP_EOL;
    }
    exit(0);
}
if (!$r['ok']) {
    fwrite(STDERR, 'Backup failed: ' . ($r['error'] ?? 'unknown error') . "\n");
    exit(4);
}
if (!$quiet) {
    echo 'Backup OK: ' . $r['path'] . ' (' . number_format($r['size']) . ' bytes, ' . $r['method'] . ')' . PHP_EOL;
}
exit(0);
