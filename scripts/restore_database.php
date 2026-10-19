<?php
/**
 * Database Restore (CLI ONLY). Thin wrapper around rh_db_restore_run() in includes/db-backup.php.
 *
 *   php scripts/restore_database.php --list
 *   php scripts/restore_database.php --file=backups/2026/04/db-20260415-020000.sql.gz --confirm
 *
 *   Refuses to run unless `--confirm` is passed AND the script is invoked from CLI.
 *   The engine checks the file is complete, takes a safety backup of the current data first,
 *   then replays the file. Nothing is touched when the file is damaged or the safety backup fails.
 *
 * Exit codes: 0 ok · 1 no database / not CLI · 2 usage · 3 file not found / invalid name
 *             4 --confirm missing · 5 backup file damaged · 6 safety backup failed or busy
 *             7 restore stopped part-way (the safety file is named in the message)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script may only be executed from the command line.\n";
    exit(1);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/database.php';
require_once $ROOT . '/includes/db-backup.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection not available.\n");
    exit(1);
}

$opts = getopt('', ['list', 'file:', 'confirm']);

if (isset($opts['list'])) {
    echo "Available backups:\n";
    $rows = rh_db_backup_list($ROOT);
    foreach ($rows as $r) {
        printf("  %-50s  %12s bytes  %s\n", $r['rel'], number_format($r['size']), date('Y-m-d H:i:s', $r['ts']));
    }
    if (!$rows) {
        echo "  (none)\n";
    }
    exit(0);
}

if (empty($opts['file']) || !is_string($opts['file'])) {
    fwrite(STDERR, "Usage: php scripts/restore_database.php --list\n");
    fwrite(STDERR, "       php scripts/restore_database.php --file=backups/.../db-*.sql.gz --confirm\n");
    exit(2);
}

// Accept an absolute path inside this project's backups folder, or a project-relative one.
$arg = str_replace('\\', '/', $opts['file']);
$rootN = str_replace('\\', '/', $ROOT) . '/';
if (strncasecmp($arg, $rootN, strlen($rootN)) === 0) {
    $arg = substr($arg, strlen($rootN));
}
$rel = rh_db_backup_norm_rel($arg);
if ($rel === '' || !is_file($ROOT . '/' . $rel)) {
    fwrite(STDERR, "File not found or not a backup file name: " . $opts['file'] . "\n");
    exit(3);
}

if (!isset($opts['confirm'])) {
    fwrite(STDERR, "Refusing to restore without --confirm. This will OVERWRITE the live database.\n");
    fwrite(STDERR, "Re-run with: --file=$rel --confirm\n");
    exit(4);
}

echo "Restoring $rel into the live database (a safety backup is taken first) ...\n";
$r = rh_db_restore_run($pdo, $ROOT, $rel);
if (!$r['ok']) {
    fwrite(STDERR, $r['error'] . "\n");
    if ($r['detail'] !== '') {
        fwrite(STDERR, 'Detail: ' . $r['detail'] . "\n");
    }
    $codes = ['bad_path' => 3, 'not_found' => 3, 'damaged' => 5, 'safety_failed' => 6, 'busy' => 6, 'sql_error' => 7];
    exit($codes[$r['code']] ?? 7);
}

echo 'Restore OK: ' . $r['statements'] . " statements executed.\n";
echo 'Safety copy of the data from before the restore: ' . $r['safety_path'] . "\n";
exit(0);
