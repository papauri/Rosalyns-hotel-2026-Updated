<?php
/**
 * Database Restore (CLI ONLY).
 *
 *   php scripts/restore_database.php --list
 *   php scripts/restore_database.php --file=backups/2026/04/db-20260415-020000.sql.gz --confirm
 *
 *   Refuses to run unless `--confirm` is passed AND the script is invoked from CLI
 *   (php_sapi_name() === 'cli'). This prevents accidental restores via stray HTTP hits.
 *
 *   The restore stops on the first SQL error and prints the failing statement preview.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script may only be executed from the command line.\n";
    exit(1);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/database.php';

// config/database.php sets $db_* vars via database.local.php; use constants as fallback
// so static analysis does not report them as undefined.
$db_host = $db_host ?? DB_HOST;
$db_port = $db_port ?? DB_PORT;
$db_name = $db_name ?? DB_NAME;
$db_user = $db_user ?? DB_USER;
$db_pass = $db_pass ?? DB_PASS;

$opts = getopt('', ['list', 'file:', 'confirm']);

if (isset($opts['list'])) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/backups', FilesystemIterator::SKIP_DOTS));
    $rows = [];
    foreach ($rii as $f) {
        if ($f->isFile() && preg_match('/^db-\d{8}-\d{6}\.sql\.gz$/', $f->getFilename())) {
            $rows[] = [
                ltrim(str_replace($ROOT, '', $f->getPathname()), '/\\'),
                $f->getSize(),
                date('Y-m-d H:i:s', $f->getMTime()),
            ];
        }
    }
    usort($rows, fn($a, $b) => strcmp($b[2], $a[2]));
    echo "Available backups:\n";
    foreach ($rows as $r) {
        printf("  %-50s  %12s bytes  %s\n", $r[0], number_format($r[1]), $r[2]);
    }
    if (!$rows) echo "  (none)\n";
    exit(0);
}

if (empty($opts['file'])) {
    fwrite(STDERR, "Usage: php scripts/restore_database.php --list\n");
    fwrite(STDERR, "       php scripts/restore_database.php --file=backups/.../db-*.sql.gz --confirm\n");
    exit(2);
}

$path = $opts['file'];
if ($path[0] !== '/' && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
    $path = $ROOT . '/' . ltrim($path, '/\\');
}
if (!is_file($path)) {
    fwrite(STDERR, "File not found: $path\n");
    exit(3);
}

if (!isset($opts['confirm'])) {
    fwrite(STDERR, "Refusing to restore without --confirm. This will OVERWRITE the live database.\n");
    fwrite(STDERR, "Re-run with: --file=$path --confirm\n");
    exit(4);
}

$port = (int)($db_port ?? 3306);
$pdo  = new PDO("mysql:host=$db_host;port=$port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "Restoring $path into $db_name@$db_host ...\n";
$gz = gzopen($path, 'rb');
if (!$gz) { fwrite(STDERR, "Cannot open gz file.\n"); exit(5); }

$buf  = '';
$stmtCount = 0;
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
try {
    while (!gzeof($gz)) {
        $buf .= gzread($gz, 65536);
        // Naive split on ";\n" — sufficient for our dump format. Avoids loading whole file.
        while (($pos = strpos($buf, ";\n")) !== false) {
            $sql = trim(substr($buf, 0, $pos));
            $buf = substr($buf, $pos + 2);
            if ($sql === '' || str_starts_with($sql, '--')) continue;
            try {
                $pdo->exec($sql);
                $stmtCount++;
            } catch (PDOException $e) {
                fwrite(STDERR, "ERROR at statement " . ($stmtCount + 1) . ": " . $e->getMessage() . "\n");
                fwrite(STDERR, "SQL preview: " . substr($sql, 0, 200) . "...\n");
                throw $e;
            }
        }
    }
    if (trim($buf) !== '') {
        $pdo->exec($buf);
        $stmtCount++;
    }
} finally {
    gzclose($gz);
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

echo "Restore OK: $stmtCount statements executed.\n";
exit(0);
