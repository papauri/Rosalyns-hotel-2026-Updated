<?php

/**
 * Automated Database Backup
 *
 * Strategy:
 *   1. Prefer `mysqldump` (fast, transactional with --single-transaction).
 *   2. Fall back to a pure-PHP PDO dumper if mysqldump is unavailable
 *      (so the script still works on locked-down shared hosting).
 *
 *   Output: gzipped SQL written to <repo>/backups/YYYY/MM/db-YYYYMMDD-HHMMSS.sql.gz
 *
 * Rotation:
 *   - Daily: keep last 14
 *   - Weekly: keep first daily of each ISO week, last 8
 *   - Monthly: keep first daily of each calendar month, last 12
 *
 * Verifies the gzip file's integrity (gzopen + read) before recording success.
 *
 * Logs to logs/backup.log and updates `last_backup_at`, `last_backup_path`,
 * `last_backup_size` site_settings rows so api/health.php and the admin dashboard
 * can surface the freshness of the most recent backup.
 *
 * Usage:
 *   php scripts/backup_database.php           # normal run
 *   php scripts/backup_database.php --quiet   # cron-friendly (only prints on error)
 *
 * Suggested cron (cPanel):
 *   0 2 * * * /usr/local/bin/php /home/USER/public_html/scripts/backup_database.php --quiet
 */

declare(strict_types=1);

// --- Bootstrap -----------------------------------------------------------------------------
$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/database.php';

// config/database.php sets $db_* vars via database.local.php; use constants as fallback
// so static analysis does not report them as undefined.
$db_host = $db_host ?? DB_HOST;
$db_port = $db_port ?? DB_PORT;
$db_name = $db_name ?? DB_NAME;
$db_user = $db_user ?? DB_USER;
$db_pass = $db_pass ?? DB_PASS;

$quiet = in_array('--quiet', $argv ?? [], true);

function out(string $msg, bool $quiet = false): void
{
    if (!$quiet) {
        echo $msg . PHP_EOL;
    }
}

function backup_log(string $msg): void
{
    $logDir  = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($logDir . '/backup.log', $line, FILE_APPEND | LOCK_EX);
}

// --- Resolve credentials (already loaded by config/database.php into $db_* vars) -----------
if (empty($db_host) || empty($db_name) || empty($db_user)) {
    backup_log('FATAL: DB credentials missing.');
    fwrite(STDERR, "Database credentials not configured.\n");
    exit(1);
}

// Prevent overlapping backups (cron + manual) from running together.
$logDir = $ROOT . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$lockFile = $logDir . '/backup.lock';
$lockHandle = @fopen($lockFile, 'c+');
if (!$lockHandle) {
    backup_log('FATAL: cannot open backup lock file: ' . $lockFile);
    fwrite(STDERR, "Cannot open backup lock file.\n");
    exit(7);
}
if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    backup_log('SKIP: backup already running; lock is held.');
    out('Backup skipped: another backup process is already running.', $quiet);
    fclose($lockHandle);
    exit(0);
}
@ftruncate($lockHandle, 0);
@fwrite($lockHandle, (string)getmypid() . ' ' . date('c') . PHP_EOL);
register_shutdown_function(static function () use (&$lockHandle): void {
    if (is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
});

$timestamp  = date('Ymd-His');
$year       = date('Y');
$month      = date('m');
$backupDir  = $ROOT . '/backups/' . $year . '/' . $month;
if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        backup_log('FATAL: cannot create backup dir ' . $backupDir);
        fwrite(STDERR, "Cannot create backup directory: $backupDir\n");
        exit(2);
    }
}

// Make sure backups dir has a deny-all .htaccess so even if the webroot accidentally
// ends up serving these files, they cannot be downloaded over HTTP.
$rootBackups = $ROOT . '/backups';
$ht = $rootBackups . '/.htaccess';
if (!file_exists($ht)) {
    @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}
$idx = $rootBackups . '/index.html';
if (!file_exists($idx)) {
    @file_put_contents($idx, '');
}

$outFile  = $backupDir . '/db-' . $timestamp . '.sql.gz';
$tmpFile  = $backupDir . '/.db-' . $timestamp . '.sql.gz.tmp';

// --- Try mysqldump first --------------------------------------------------------------------
$usedMysqldump = false;
$dumpedOk      = false;

$mysqldump = '';
$isWindows = DIRECTORY_SEPARATOR === '\\';
if ($isWindows) {
    $which = trim((string)@shell_exec('where mysqldump 2>nul'));
    if ($which !== '') {
        $lines = preg_split('/\r?\n/', $which);
        $candidate = trim((string)($lines[0] ?? ''));
        if ($candidate !== '') {
            $mysqldump = $candidate;
        }
    }
} else {
    $mysqldump = trim((string)@shell_exec('command -v mysqldump 2>/dev/null'));
}

if ($mysqldump !== '' && function_exists('proc_open')) {
    // Build credentials file (avoids leaking password via process list / argv)
    $cnf = tempnam(sys_get_temp_dir(), 'rh_my_');
    chmod($cnf, 0600);
    $cnfBody = "[client]\n"
        . "host=" . $db_host . "\n"
        . "port=" . ($db_port ?? 3306) . "\n"
        . "user=" . $db_user . "\n"
        . "password=" . ($db_pass ?? '') . "\n";
    file_put_contents($cnf, $cnfBody);

    $cmd = escapeshellcmd($mysqldump)
        . ' --defaults-extra-file=' . escapeshellarg($cnf)
        . ' --single-transaction --quick --routines --triggers --events'
        . ' --default-character-set=utf8mb4 --no-tablespaces'
        . ' --skip-lock-tables'
        . ' ' . escapeshellarg($db_name);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $gz = gzopen($tmpFile, 'wb6');
        if (!$gz) {
            proc_terminate($proc);
            @unlink($cnf);
            backup_log('FATAL: cannot open gz output ' . $tmpFile);
            fwrite(STDERR, "Cannot open gz output\n");
            exit(3);
        }
        while (!feof($pipes[1])) {
            $buf = fread($pipes[1], 65536);
            if ($buf === '' || $buf === false) break;
            gzwrite($gz, $buf);
        }
        gzclose($gz);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($cnf);

        if ($exit === 0 && filesize($tmpFile) > 1024) {
            $usedMysqldump = true;
            $dumpedOk      = true;
        } else {
            @unlink($tmpFile);
            backup_log('mysqldump failed (exit ' . $exit . '): ' . trim((string)$stderr));
        }
    } else {
        @unlink($cnf);
    }
}

// --- PHP fallback dumper -------------------------------------------------------------------
if (!$dumpedOk) {
    out('mysqldump unavailable — using PHP fallback dumper.', $quiet);

    try {
        $port = (int)($db_port ?? 3306);
        $dsn  = "mysql:host=$db_host;port=$port;dbname=$db_name;charset=utf8mb4";
        $pdo  = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $gz = gzopen($tmpFile, 'wb6');
        if (!$gz) {
            throw new RuntimeException('cannot open gz output');
        }

        gzwrite($gz, "-- Rosalyns Hotel database backup\n-- Generated: " . date('c') . "\n");
        gzwrite($gz, "-- Database: $db_name\n\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$tbl]) {
            $tblQ = '`' . str_replace('`', '``', $tbl) . '`';
            gzwrite($gz, "-- ----------------------------\n-- Table: $tbl\n-- ----------------------------\n");
            gzwrite($gz, "DROP TABLE IF EXISTS $tblQ;\n");
            $createRow = $pdo->query("SHOW CREATE TABLE $tblQ")->fetch(PDO::FETCH_NUM);
            gzwrite($gz, ($createRow[1] ?? '') . ";\n\n");

            // Stream rows
            $stmt = $pdo->query("SELECT * FROM $tblQ", PDO::FETCH_ASSOC);
            $batch = [];
            $batchSize = 200;
            $cols = null;
            foreach ($stmt as $row) {
                if ($cols === null) {
                    $cols = array_map(fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', array_keys($row));
                }
                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif (is_int($v) || is_float($v)) {
                        $vals[] = (string)$v;
                    } else {
                        $vals[] = $pdo->quote((string)$v);
                    }
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= $batchSize) {
                    gzwrite($gz, "INSERT INTO $tblQ (" . implode(',', $cols) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO $tblQ (" . implode(',', $cols) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            gzwrite($gz, "\n");
        }

        // Views
        $views = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM);
        foreach ($views as [$v]) {
            $vQ = '`' . str_replace('`', '``', $v) . '`';
            $row = $pdo->query("SHOW CREATE VIEW $vQ")->fetch(PDO::FETCH_NUM);
            gzwrite($gz, "DROP VIEW IF EXISTS $vQ;\n" . ($row[1] ?? '') . ";\n\n");
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        $dumpedOk = true;
    } catch (Throwable $e) {
        @unlink($tmpFile);
        backup_log('FATAL: PHP fallback dump failed: ' . $e->getMessage());
        fwrite(STDERR, "Backup failed: " . $e->getMessage() . "\n");
        exit(4);
    }
}

// --- Verify gzip integrity -----------------------------------------------------------------
$verifyOk = false;
if (file_exists($tmpFile)) {
    $g = @gzopen($tmpFile, 'rb');
    if ($g) {
        $bytes = 0;
        while (!gzeof($g)) {
            $chunk = gzread($g, 65536);
            if ($chunk === false) {
                $bytes = -1;
                break;
            }
            $bytes += strlen($chunk);
            if ($bytes > 64 * 1024 * 1024) {
                break;
            } // sample first 64MB is enough
        }
        gzclose($g);
        $verifyOk = ($bytes > 0);
    }
}

if (!$verifyOk) {
    @unlink($tmpFile);
    backup_log('FATAL: gzip integrity check failed for ' . $tmpFile);
    fwrite(STDERR, "Backup integrity check failed.\n");
    exit(5);
}

// Atomic rename
if (!rename($tmpFile, $outFile)) {
    @unlink($tmpFile);
    backup_log('FATAL: rename to ' . $outFile . ' failed');
    fwrite(STDERR, "Cannot finalize backup file.\n");
    exit(6);
}
@chmod($outFile, 0640);

$size = filesize($outFile) ?: 0;
$method = $usedMysqldump ? 'mysqldump' : 'php-fallback';
backup_log(sprintf('OK: %s (%s, %d bytes)', $outFile, $method, $size));
out("Backup OK: $outFile (" . number_format($size) . " bytes, $method)", $quiet);

// --- Update site_settings ------------------------------------------------------------------
try {
    $pdo = $pdo ?? new PDO("mysql:host=$db_host;port=" . (int)($db_port ?? 3306) . ";dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $upd = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group, updated_at) VALUES (?, ?, 'system', NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    $rel = ltrim(str_replace($ROOT, '', $outFile), '/\\');
    $upd->execute(['last_backup_at', date('Y-m-d H:i:s')]);
    $upd->execute(['last_backup_path', $rel]);
    $upd->execute(['last_backup_size', (string)$size]);
} catch (Throwable $e) {
    backup_log('WARN: could not update site_settings: ' . $e->getMessage());
}

// --- Rotation: 14 daily / 8 weekly / 12 monthly --------------------------------------------
function rotate_backups(string $rootBackups): void
{
    if (!is_dir($rootBackups)) return;
    $all = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootBackups, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->isFile() && preg_match('/^db-(\d{8})-(\d{6})\.sql\.gz$/', $f->getFilename(), $m)) {
            $all[] = [
                'path'  => $f->getPathname(),
                'date'  => $m[1],   // YYYYMMDD
                'time'  => $m[2],   // HHMMSS
                'mtime' => $f->getMTime(),
            ];
        }
    }
    if (!$all) return;

    usort($all, fn($a, $b) => strcmp($b['date'] . $b['time'], $a['date'] . $a['time']));

    $keep = [];
    // Daily: most recent 14 distinct days
    $dailyDays = [];
    foreach ($all as $b) {
        if (count($dailyDays) >= 14) break;
        if (!isset($dailyDays[$b['date']])) {
            $dailyDays[$b['date']] = true;
            $keep[$b['path']] = true;
        }
    }
    // Weekly: 8 most recent ISO weeks (using earliest entry of each week's set)
    $weekly = [];
    foreach ($all as $b) {
        $ts   = strtotime($b['date']);
        $wkey = date('o-W', $ts);
        if (!isset($weekly[$wkey])) {
            $weekly[$wkey] = $b['path'];
            if (count($weekly) >= 8) break;
        }
    }
    foreach ($weekly as $p) {
        $keep[$p] = true;
    }

    // Monthly: 12 most recent calendar months
    $monthly = [];
    foreach ($all as $b) {
        $mkey = substr($b['date'], 0, 6); // YYYYMM
        if (!isset($monthly[$mkey])) {
            $monthly[$mkey] = $b['path'];
            if (count($monthly) >= 12) break;
        }
    }
    foreach ($monthly as $p) {
        $keep[$p] = true;
    }

    foreach ($all as $b) {
        if (!isset($keep[$b['path']])) {
            @unlink($b['path']);
            backup_log('Rotated out: ' . $b['path']);
        }
    }
}

rotate_backups($ROOT . '/backups');

exit(0);
