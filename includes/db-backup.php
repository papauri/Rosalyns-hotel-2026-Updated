<?php

/**
 * Database backup + restore engine (shared by scripts/backup_database.php,
 * scripts/restore_database.php, the web scheduler and admin/backup-management.php).
 *
 * Backup:
 *   1. mysqldump, only when EVERY shell function it needs is really callable
 *      (shared hosts remove them via disable_functions; calling one is a fatal Error).
 *   2. Otherwise a pure-PHP PDO dumper that streams rows (unbuffered cursor) into gzip,
 *      inside one consistent snapshot.
 *
 * Output: gzipped SQL at <root>/backups/YYYY/MM/db-YYYYMMDD-HHMMSS.sql.gz
 * Rotation: 14 daily / 8 weekly / 12 monthly; never removes a file younger than 48 hours and
 * never the newest one. Log: <root>/logs/backup.log.
 *
 * Restore: rh_db_restore_run() checks the file, takes a safety backup, then replays it.
 * Never calls exit()/die(); every failure is returned.
 */

if (!function_exists('rh_db_backup_can')) {

    /** True when $fn exists and is not listed in disable_functions. */
    function rh_db_backup_can(string $fn): bool
    {
        if (!function_exists($fn)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
        return !in_array(strtolower($fn), $disabled, true);
    }

    function rh_db_backup_log(string $root, string $msg): void
    {
        $logDir = $root . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        @file_put_contents($logDir . '/backup.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Strict check for a backup path coming from outside (browser, CLI): returns the normalised
     * "backups/YYYY/MM/db-YYYYMMDD-HHMMSS.sql.gz" or '' when it is anything else.
     */
    function rh_db_backup_norm_rel(string $rel): string
    {
        $rel = str_replace('\\', '/', trim($rel));
        return preg_match('#^backups/\d{4}/\d{2}/db-\d{8}-\d{6}\.sql\.gz\z#', $rel) ? $rel : '';
    }

    /**
     * Every backup file on disk, newest first (by the time in the name).
     *
     * @return array<int,array{path:string,rel:string,id:string,date:string,time:string,size:int,ts:int}>
     */
    function rh_db_backup_list(string $root): array
    {
        $root = rtrim($root, '/\\');
        $dir = $root . '/backups';
        $all = [];
        if (!is_dir($dir)) {
            return $all;
        }
        try {
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($rii as $f) {
                if ($f->isFile() && preg_match('/^db-(\d{8})-(\d{6})\.sql\.gz$/', $f->getFilename(), $m)) {
                    $d = $m[1];
                    $t = $m[2];
                    $ts = mktime((int)substr($t, 0, 2), (int)substr($t, 2, 2), (int)substr($t, 4, 2),
                        (int)substr($d, 4, 2), (int)substr($d, 6, 2), (int)substr($d, 0, 4));
                    $all[] = [
                        'path' => $f->getPathname(),
                        'rel'  => str_replace('\\', '/', ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $root))), '/')),
                        'id'   => $d . $t,
                        'date' => $d,
                        'time' => $t,
                        'size' => (int)$f->getSize(),
                        'ts'   => (int)$ts,
                    ];
                }
            }
        } catch (Throwable $e) {
            return $all;
        }
        usort($all, static function ($a, $b) {
            return strcmp($b['id'], $a['id']);
        });
        return $all;
    }

    /**
     * Write site_settings rows with the connection we were given and clear the settings caches,
     * so the web side (which reads through a file cache) sees the new value at once.
     */
    function rh_db_backup_stamp(PDO $pdo, array $kv, string $root = ''): void
    {
        try {
            $upd = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group, updated_at) VALUES (?, ?, 'system', NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
            foreach ($kv as $k => $v) {
                $upd->execute([(string)$k, (string)$v]);
                if (isset($GLOBALS['_SITE_SETTINGS']) && is_array($GLOBALS['_SITE_SETTINGS'])) {
                    unset($GLOBALS['_SITE_SETTINGS'][$k]);
                }
                if (function_exists('deleteCache')) {
                    @deleteCache('setting_' . $k);
                }
            }
        } catch (Throwable $e) {
            rh_db_backup_log($root !== '' ? $root : dirname(__DIR__), 'WARN: could not update site_settings: ' . $e->getMessage());
        }
    }

    /**
     * Plain-words explanation for a backup problem code.
     *
     * @return array{0:string,1:string,2:string} what happened, what it means, the one thing to do next
     */
    function rh_db_backup_explain(string $code): array
    {
        $means = 'Your hotel data has not been copied, so recent bookings are not protected until a backup works.';
        switch ($code) {
            case 'disk_full':
                return ['The backup could not be saved because the server is running out of disk space.', $means,
                    'On the Backups screen, delete some old backups (or ask your hosting provider for more disk space), then press Run Backup Now.'];
            case 'permission':
                return ['The server would not let the system save its backup files.', $means,
                    'Ask your IT support or hosting provider to make the "backups" and "logs" folders writable, then press Run Backup Now.'];
            case 'verify_failed':
                return ['A backup was made but it did not pass its safety check, so it was thrown away.', $means,
                    'Press Run Backup Now to try again. If it fails again, contact your IT support.'];
            case 'locked':
                return ['A backup is already running right now.', 'Nothing is wrong; it finishes by itself.',
                    'Wait a minute, then refresh this page.'];
            default:
                return ['The system could not read all of your hotel data to make a backup.', $means,
                    'Press Run Backup Now to try again. If it fails again, contact your IT support and give them the details below.'];
        }
    }

    /** Map a stored technical error line (last_backup_error) back to an explain code. */
    function rh_db_backup_code_from_error(string $err): string
    {
        $e = strtolower($err);
        if (strpos($e, 'disk') !== false) {
            return 'disk_full';
        }
        if (strpos($e, 'cannot create backup directory') !== false || strpos($e, 'cannot open') !== false || strpos($e, 'rename to') !== false) {
            return 'permission';
        }
        if (strpos($e, 'integrity check') !== false) {
            return 'verify_failed';
        }
        if (strpos($e, 'already running') !== false) {
            return 'locked';
        }
        return 'failed';
    }

    /** Locate mysqldump, or '' when it cannot be used. */
    function rh_db_backup_find_mysqldump(): string
    {
        foreach (['shell_exec', 'escapeshellarg', 'escapeshellcmd', 'proc_open', 'proc_close'] as $fn) {
            if (!rh_db_backup_can($fn)) {
                return '';
            }
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $which = trim((string)@shell_exec('where mysqldump 2>nul'));
            $lines = preg_split('/\r?\n/', $which);
            return trim((string)($lines[0] ?? ''));
        }
        return trim((string)@shell_exec('command -v mysqldump 2>/dev/null'));
    }

    /** Pure-PHP dump, streamed into the open gzip handle. Throws on failure. */
    function rh_db_backup_php_dump($gz, string $dbName): void
    {
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
            throw new RuntimeException('DB constants not defined');
        }
        $port = defined('DB_PORT') ? (int)DB_PORT : 3306;
        // Dedicated connection: an unbuffered cursor blocks other queries on the same connection.
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            defined('DB_PASS') ? DB_PASS : '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
        );
        // One consistent snapshot for every table (like mysqldump --single-transaction), and
        // TIMESTAMP columns written in UTC so the restore is correct on any server time zone.
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $w = static function (string $s) use ($gz): void {
            if ($s !== '' && !gzwrite($gz, $s)) {
                throw new RuntimeException('write to gzip failed (disk full?)');
            }
        };
        $strip = static function (string $sql): string {
            return preg_replace('/\bDEFINER\s*=\s*(?:`[^`]*`|\'[^\']*\'|[^\s@`\']+)@(?:`[^`]*`|\'[^\']*\'|[^\s`\']+)\s*/i', '', $sql);
        };

        $siteName = function_exists('getSetting') ? ((string)getSetting('site_name') ?: 'Hotel') : 'Hotel';
        $siteName = trim(preg_replace('/[\r\n]+/', ' ', $siteName));
        $w("-- " . $siteName . " database backup\n-- Generated: " . date('c') . "\n");
        $w("-- Database: " . preg_replace('/[\r\n]+/', ' ', $dbName) . "\n\n");
        $w("SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        $w("SET @OLD_TIME_ZONE=@@TIME_ZONE, TIME_ZONE='+00:00';\n");
        $w("SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

        // Tables/views are listed up front (fully read) so no cursor is open while dumping.
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $views  = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM);

        foreach ($tables as [$tbl]) {
            $tblQ = '`' . str_replace('`', '``', $tbl) . '`';
            $w("-- ----------------------------\n-- Table: " . preg_replace('/[\r\n]+/', ' ', (string)$tbl) . "\n-- ----------------------------\n");
            $w("DROP TABLE IF EXISTS $tblQ;\n");
            $cs = $pdo->query("SHOW CREATE TABLE $tblQ");
            $createRow = $cs->fetch(PDO::FETCH_NUM);
            $cs->closeCursor();
            $w(($createRow[1] ?? '') . ";\n\n");

            // Real columns only: generated columns are rebuilt by the server and cannot be inserted.
            $colRows = $pdo->query("SHOW COLUMNS FROM $tblQ")->fetchAll(PDO::FETCH_ASSOC);
            $names = [];
            $isBin = [];
            foreach ($colRows as $c) {
                if (stripos((string)($c['Extra'] ?? ''), 'generated') !== false) {
                    continue;
                }
                $names[] = '`' . str_replace('`', '``', (string)$c['Field']) . '`';
                $isBin[] = (bool)preg_match('/^(?:(?:tiny|medium|long)?blob|(?:var)?binary|bit)\b/i', (string)($c['Type'] ?? ''));
            }
            if (!$names) {
                $w("\n");
                continue;
            }
            $colList = implode(',', $names);

            $stmt = $pdo->query("SELECT $colList FROM $tblQ");
            $batch = [];
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                $vals = [];
                foreach ($row as $idx => $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif (!empty($isBin[$idx])) {
                        $vals[] = ((string)$v === '') ? "''" : '0x' . bin2hex((string)$v);
                    } elseif (is_int($v) || is_float($v)) {
                        $vals[] = (string)$v;
                    } else {
                        $vals[] = $pdo->quote((string)$v);
                    }
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 200) {
                    $w("INSERT INTO $tblQ ($colList) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            $stmt->closeCursor();
            if ($batch) {
                $w("INSERT INTO $tblQ ($colList) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $w("\n");
        }

        // Views: fetch definitions, then emit dependencies first (a view that names another view comes after it).
        $viewDefs = [];
        foreach ($views as [$v]) {
            $vs = $pdo->query('SHOW CREATE VIEW `' . str_replace('`', '``', $v) . '`');
            $row = $vs->fetch(PDO::FETCH_NUM);
            $vs->closeCursor();
            $viewDefs[$v] = $strip((string)($row[1] ?? ''));
        }
        $emitted = [];
        $pending = $viewDefs;
        while ($pending) {
            $progress = false;
            foreach ($pending as $name => $def) {
                $blocked = false;
                foreach ($pending as $other => $_) {
                    if ($other !== $name && strpos($def, '`' . $other . '`') !== false) {
                        $blocked = true;
                        break;
                    }
                }
                if (!$blocked) {
                    $vQ = '`' . str_replace('`', '``', $name) . '`';
                    $w("DROP VIEW IF EXISTS $vQ;\n" . $def . ";\n\n");
                    unset($pending[$name]);
                    $progress = true;
                }
            }
            if (!$progress) { // circular/ambiguous: keep alphabetical for the rest
                foreach ($pending as $name => $def) {
                    $w('DROP VIEW IF EXISTS `' . str_replace('`', '``', $name) . "`;\n" . $def . ";\n\n");
                }
                break;
            }
        }

        // Stored routines, triggers, events (DEFINER removed so a restore on another user/host works).
        $objects = [];
        foreach ($pdo->query("SELECT ROUTINE_TYPE, ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_NUM) as [$type, $name]) {
            $objects[] = [strtoupper($type) === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE', $name, 2];
        }
        foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_NUM) as $t) {
            $objects[] = ['TRIGGER', $t[0], 2];
        }
        foreach ($pdo->query('SHOW EVENTS')->fetchAll(PDO::FETCH_NUM) as $e) {
            $objects[] = ['EVENT', $e[1], 3];
        }
        foreach ($objects as [$kind, $name, $col]) {
            $nQ = '`' . str_replace('`', '``', $name) . '`';
            $os = $pdo->query("SHOW CREATE $kind $nQ");
            $row = $os->fetch(PDO::FETCH_NUM);
            $os->closeCursor();
            $def = $strip((string)($row[$col] ?? ''));
            if ($def === '') {
                // MySQL hides the body unless the connection is the routine's exact DEFINER user@host.
                $w("-- $kind $nQ not dumped: its definition is not readable by this database user (DEFINER mismatch)\n\n");
                continue;
            }
            $w("DELIMITER ;;\nDROP $kind IF EXISTS $nQ;;\n" . $def . ";;\nDELIMITER ;\n\n");
        }

        try {
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            // read-only snapshot; nothing to lose
        }
        $w("SET FOREIGN_KEY_CHECKS=1;\n");
        $w("SET SQL_MODE=@OLD_SQL_MODE;\nSET TIME_ZONE=@OLD_TIME_ZONE;\n");
        $w("-- Dump completed\n");
    }

    /**
     * 14 daily / 8 weekly / 12 monthly. A file younger than 48 hours is never removed and the
     * newest file is never removed, whatever the policy says.
     */
    function rh_db_backup_rotate(string $root): void
    {
        $all = rh_db_backup_list($root);
        if (!$all) {
            return;
        }

        $keep = [$all[0]['path'] => true];
        $days = [];
        foreach ($all as $b) {
            if (count($days) >= 14) {
                break;
            }
            if (!isset($days[$b['date']])) {
                $days[$b['date']] = true;
                $keep[$b['path']] = true;
            }
        }
        $weekly = [];
        foreach ($all as $b) {
            $wkey = date('o-W', strtotime($b['date']));
            if (!isset($weekly[$wkey])) {
                $weekly[$wkey] = $b['path'];
                if (count($weekly) >= 8) {
                    break;
                }
            }
        }
        foreach ($weekly as $p) {
            $keep[$p] = true;
        }
        $monthly = [];
        foreach ($all as $b) {
            $mkey = substr($b['date'], 0, 6);
            if (!isset($monthly[$mkey])) {
                $monthly[$mkey] = $b['path'];
                if (count($monthly) >= 12) {
                    break;
                }
            }
        }
        foreach ($monthly as $p) {
            $keep[$p] = true;
        }
        $young = time() - 48 * 3600;
        foreach ($all as $b) {
            if (isset($keep[$b['path']]) || $b['ts'] > $young) {
                continue;
            }
            @unlink($b['path']);
            rh_db_backup_log($root, 'Rotated out: ' . $b['path']);
        }
    }

    /** Remove half-written .db-*.tmp files older than an hour (left by a crashed run). */
    function rh_db_backup_cleanup_tmp(string $root): void
    {
        $dir = $root . '/backups';
        if (!is_dir($dir)) {
            return;
        }
        try {
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($rii as $f) {
                if ($f->isFile() && preg_match('/^\.db-\d{8}-\d{6}\.sql\.gz\.tmp$/', $f->getFilename()) && $f->getMTime() < time() - 3600) {
                    if (@unlink($f->getPathname())) {
                        rh_db_backup_log($root, 'Removed leftover temp file: ' . $f->getFilename());
                    }
                }
            }
        } catch (Throwable $e) {
            // best effort
        }
    }

    /**
     * Run one backup.
     *
     * @param array $opts stamp (bool, default true): update last_backup_* settings and, on a
     *                    failure, last_backup_error / last_backup_error_at.
     *                    rotate (bool, default true): apply the rotation policy afterwards.
     * @return array{ok:bool,path:?string,size:int,method:string,error:?string,code:string,skipped:bool}
     *         code: '' on success, else disk_full|permission|verify_failed|locked|failed.
     *         skipped is true when another backup holds the lock (not a failure).
     */
    function rh_db_backup_run(PDO $pdo, string $root, array $opts = []): array
    {
        $stamp  = array_key_exists('stamp', $opts) ? (bool)$opts['stamp'] : true;
        $rotate = array_key_exists('rotate', $opts) ? (bool)$opts['rotate'] : true;
        $root   = rtrim($root, '/\\');
        $fail   = static function (string $err, string $method = 'php', string $code = 'failed') use ($root, $pdo, $stamp): array {
            rh_db_backup_log($root, 'FATAL: ' . $err);
            if ($stamp) {
                rh_db_backup_stamp($pdo, [
                    'last_backup_error'    => substr($err, 0, 300),
                    'last_backup_error_at' => date('Y-m-d H:i:s'),
                ], $root);
            }
            return ['ok' => false, 'path' => null, 'size' => 0, 'method' => $method, 'error' => $err, 'code' => $code, 'skipped' => false];
        };

        $logDir = $root . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        // A restore in progress on another connection: a dump now would capture a half-restored
        // database and stamp it as the newest good backup. The restore's own safety backup runs on
        // the restoring connection, so it still goes ahead.
        try {
            $restoreHolder = $pdo->query("SELECT IS_USED_LOCK('rh_db_restore'), CONNECTION_ID()")->fetch(PDO::FETCH_NUM);
            if ($restoreHolder && $restoreHolder[0] !== null && (string)$restoreHolder[0] !== (string)$restoreHolder[1]) {
                rh_db_backup_log($root, 'SKIP: a restore is in progress.');
                return ['ok' => false, 'path' => null, 'size' => 0, 'method' => 'php', 'error' => 'a restore is in progress', 'code' => 'locked', 'skipped' => true];
            }
        } catch (Throwable $e) {
            // lock functions unavailable: carry on as before
        }

        $lock = @fopen($logDir . '/backup.lock', 'c+');
        if (!$lock) {
            return $fail('cannot open backup lock file', 'php', 'permission');
        }
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            rh_db_backup_log($root, 'SKIP: backup already running; lock is held.');
            return ['ok' => false, 'path' => null, 'size' => 0, 'method' => 'php', 'error' => 'another backup is already running', 'code' => 'locked', 'skipped' => true];
        }
        @ftruncate($lock, 0);
        @fwrite($lock, getmypid() . ' ' . date('c') . PHP_EOL);

        $tmpFile = null;
        try {
            rh_db_backup_cleanup_tmp($root);

            $timestamp = date('Ymd-His');
            $backupDir = $root . '/backups/' . date('Y') . '/' . date('m');
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
                return $fail('cannot create backup directory ' . $backupDir, 'php', 'permission');
            }
            $ht = $root . '/backups/.htaccess';
            if (!file_exists($ht)) {
                @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            }
            if (!file_exists($root . '/backups/index.html')) {
                @file_put_contents($root . '/backups/index.html', '');
            }

            // Disk space: a backup needs roughly the size of the last one; ask for twice that.
            $existing = rh_db_backup_list($root);
            $lastSize = $existing ? (int)$existing[0]['size'] : 0;
            $need = max(2 * $lastSize, 5 * 1024 * 1024);
            $free = @disk_free_space($backupDir);
            if ($free !== false && $free < $need) {
                return $fail(sprintf('not enough free disk space (%.1f MB free, about %.1f MB needed)', $free / 1048576, $need / 1048576), 'php', 'disk_full');
            }

            $outFile = $backupDir . '/db-' . $timestamp . '.sql.gz';
            $tmpFile = $backupDir . '/.db-' . $timestamp . '.sql.gz.tmp';
            $method  = 'php';
            $done    = false;

            // 1. mysqldump, only if every shell function is usable
            $mysqldump = rh_db_backup_find_mysqldump();
            if ($mysqldump !== '' && defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
                $cnf = @tempnam(sys_get_temp_dir(), 'rh_my_');
                $errFile = @tempnam(sys_get_temp_dir(), 'rh_my_err_');
                try {
                    if ($cnf !== false && $errFile !== false) {
                        @chmod($cnf, 0600);
                        file_put_contents($cnf, "[client]\nhost=" . DB_HOST . "\nport=" . (defined('DB_PORT') ? (int)DB_PORT : 3306)
                            . "\nuser=" . DB_USER . "\npassword=" . (defined('DB_PASS') ? DB_PASS : '') . "\n");
                        // Default --comments stays on so the dump ends with "-- Dump completed".
                        $cmd = escapeshellcmd($mysqldump)
                            . ' --defaults-extra-file=' . escapeshellarg($cnf)
                            . ' --single-transaction --quick --routines --triggers --events'
                            . ' --default-character-set=utf8mb4 --no-tablespaces --skip-lock-tables'
                            . ' ' . escapeshellarg(DB_NAME);
                        // stderr goes to a file, so a chatty mysqldump can never fill a pipe and deadlock.
                        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
                        if (is_resource($proc)) {
                            fclose($pipes[0]);
                            $gz = @gzopen($tmpFile, 'wb6');
                            $writeOk = (bool)$gz;
                            while ($gz && !feof($pipes[1])) {
                                $buf = fread($pipes[1], 65536);
                                if ($buf === '' || $buf === false) {
                                    break;
                                }
                                if (!gzwrite($gz, $buf)) {
                                    $writeOk = false;
                                    break;
                                }
                            }
                            if ($gz && !gzclose($gz)) {
                                $writeOk = false;
                            }
                            fclose($pipes[1]);
                            $exit = proc_close($proc);
                            if ($exit === 0 && $writeOk && @filesize($tmpFile) > 1024) {
                                $method = 'mysqldump';
                                $done = true;
                            } else {
                                @unlink($tmpFile);
                                rh_db_backup_log($root, 'mysqldump failed (exit ' . $exit . '): ' . trim((string)@file_get_contents($errFile)));
                            }
                        }
                    }
                } finally {
                    if ($cnf !== false) {
                        @unlink($cnf);
                    }
                    if ($errFile !== false) {
                        @unlink($errFile);
                    }
                }
            }

            // 2. pure-PHP streaming dumper
            if (!$done) {
                $gz = @gzopen($tmpFile, 'wb6');
                if (!$gz) {
                    return $fail('cannot open gz output ' . $tmpFile, 'php', 'permission');
                }
                try {
                    rh_db_backup_php_dump($gz, defined('DB_NAME') ? (string)DB_NAME : '');
                    if (!gzclose($gz)) {
                        throw new RuntimeException('closing gzip output failed (disk full?)');
                    }
                } catch (Throwable $e) {
                    @gzclose($gz);
                    @unlink($tmpFile);
                    $m = $e->getMessage();
                    return $fail('PHP fallback dump failed: ' . $m, 'php', stripos($m, 'disk full') !== false ? 'disk_full' : 'failed');
                }
            }

            // gzip integrity check: the whole stream must decompress and end with the completion marker
            $verifyOk = false;
            if (file_exists($tmpFile)) {
                $g = @gzopen($tmpFile, 'rb');
                if ($g) {
                    $bytes = 0;
                    $tail = '';
                    while (!gzeof($g)) {
                        $chunk = gzread($g, 65536);
                        if ($chunk === false) {
                            $bytes = -1;
                            break;
                        }
                        $bytes += strlen($chunk);
                        $tail = substr($tail . $chunk, -2048);
                    }
                    gzclose($g);
                    $verifyOk = ($bytes > 0 && strpos($tail, '-- Dump completed') !== false);
                }
            }
            if (!$verifyOk) {
                @unlink($tmpFile);
                return $fail('gzip integrity check failed (truncated or incomplete dump)', $method, 'verify_failed');
            }

            if (!@rename($tmpFile, $outFile)) {
                @unlink($tmpFile);
                return $fail('rename to ' . $outFile . ' failed', $method, 'permission');
            }
            $tmpFile = null;
            @chmod($outFile, 0640);
            $size = (int)(@filesize($outFile) ?: 0);
            rh_db_backup_log($root, sprintf('OK: %s (%s, %d bytes)', $outFile, $method, $size));

            if ($stamp) {
                $rel = ltrim(str_replace($root, '', $outFile), '/\\');
                rh_db_backup_stamp($pdo, [
                    'last_backup_at'       => date('Y-m-d H:i:s'),
                    'last_backup_path'     => $rel,
                    'last_backup_size'     => (string)$size,
                    'last_backup_error'    => '',
                    'last_backup_error_at' => '',
                ], $root);
            }

            if ($rotate) {
                try {
                    rh_db_backup_rotate($root);
                } catch (Throwable $e) {
                    rh_db_backup_log($root, 'WARN: rotation failed: ' . $e->getMessage());
                }
            }

            return ['ok' => true, 'path' => $outFile, 'size' => $size, 'method' => $method, 'error' => null, 'code' => '', 'skipped' => false];
        } catch (Throwable $e) {
            if ($tmpFile) {
                @unlink($tmpFile);
            }
            return $fail($e->getMessage());
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /* ── restore ─────────────────────────────────────────────────────── */

    /** Fresh state for rh_db_sql_splitter_feed(). */
    function rh_db_sql_splitter_new(): array
    {
        return ['delim' => ';', 'q' => '', 'cmt' => '', 'cur' => '', 'pend' => ''];
    }

    /**
     * Quote-aware SQL statement splitter (streaming). Feed it chunks; it calls $emit(string $sql)
     * for each complete statement. Understands '..' ".." `..` quotes with backslash escapes and
     * doubled quotes, strips "-- ", "#" and plain block comments (executable comments that start
     * with a bang are kept for the server), and follows the DELIMITER command.
     * Call once more with $final = true to flush a last statement that has no terminator.
     */
    function rh_db_sql_splitter_feed(array &$st, string $chunk, bool $final, callable $emit): void
    {
        $buf = $st['pend'] . $chunk;
        $n = strlen($buf);
        $delim = $st['delim'];
        $q = $st['q'];
        $cmt = $st['cmt'];
        $cur = $st['cur'];
        // Keep a small tail back until more data arrives, so look-ahead never runs off the buffer.
        $limit = $final ? $n : max(0, $n - 24);
        $i = 0;
        while ($i < $limit) {
            if ($cmt === 'line') {
                $p = strpos($buf, "\n", $i);
                if ($p === false) {
                    $i = $n;
                    break;
                }
                $i = $p + 1;
                $cmt = '';
                continue;
            }
            if ($cmt === 'block' || $cmt === 'exec') {
                $p = strpos($buf, '*/', $i);
                if ($p === false) {
                    if ($cmt === 'exec') {
                        $cur .= substr($buf, $i, $limit - $i);
                    }
                    $i = $limit;
                    break;
                }
                if ($cmt === 'exec') {
                    $cur .= substr($buf, $i, $p + 2 - $i);
                }
                $i = $p + 2;
                $cmt = '';
                continue;
            }
            if ($q !== '') {
                $set = $q === '`' ? '`' : ($q . '\\');
                $run = strcspn($buf, $set, $i);
                if ($i + $run >= $limit) {
                    $cur .= substr($buf, $i, $limit - $i);
                    $i = $limit;
                    break;
                }
                $cur .= substr($buf, $i, $run);
                $i += $run;
                $c = $buf[$i];
                if ($c === '\\') {
                    $cur .= substr($buf, $i, 2);
                    $i += 2;
                    continue;
                }
                if (($buf[$i + 1] ?? '') === $q) {
                    $cur .= $q . $q;
                    $i += 2;
                    continue;
                }
                $cur .= $c;
                $q = '';
                $i++;
                continue;
            }

            $c = $buf[$i];
            if (($c === 'D' || $c === 'd') && ($cur === '' || ctype_space($cur))
                && strncasecmp(substr($buf, $i, 10), 'DELIMITER', 9) === 0
                && in_array(substr($buf, $i + 9, 1), [' ', "\t"], true)) {
                $p = strpos($buf, "\n", $i);
                if ($p === false) {
                    if (!$final) {
                        break; // wait for the end of the line
                    }
                    $p = $n;
                }
                $new = trim(substr($buf, $i + 9, $p - $i - 9));
                if ($new !== '') {
                    $delim = $new;
                }
                $cur = '';
                $i = $p + 1;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $q = $c;
                $cur .= $c;
                $i++;
                continue;
            }
            if ($c === '-' && ($buf[$i + 1] ?? '') === '-') {
                $nx = $buf[$i + 2] ?? "\n";
                if (ord($nx) <= 32) {
                    $cmt = 'line';
                    $cur .= ' ';
                    $i += 2;
                    continue;
                }
            }
            if ($c === '#') {
                $cmt = 'line';
                $cur .= ' ';
                $i++;
                continue;
            }
            if ($c === '/' && ($buf[$i + 1] ?? '') === '*') {
                if (($buf[$i + 2] ?? '') === '!') {
                    $cmt = 'exec';
                    $cur .= '/*';
                } else {
                    $cmt = 'block';
                    $cur .= ' ';
                }
                $i += 2;
                continue;
            }
            $dl = strlen($delim);
            if ($c === $delim[0] && substr($buf, $i, $dl) === $delim) {
                $s = trim($cur);
                $cur = '';
                $i += $dl;
                if ($s !== '') {
                    $st['delim'] = $delim;
                    $emit($s);
                }
                continue;
            }
            $cur .= $c;
            $i++;
        }

        if ($final) {
            $s = trim($cur);
            $cur = '';
            $st['pend'] = '';
            if ($s !== '') {
                $emit($s);
            }
        } else {
            $st['pend'] = (string)substr($buf, min($i, $n));
        }
        $st['delim'] = $delim;
        $st['q'] = $q;
        $st['cmt'] = $cmt;
        $st['cur'] = $cur;
    }

    /**
     * Restore the live database from one backup file.
     *
     * Order of events: validate the path, read the WHOLE file and require the completion
     * marker (nothing is touched if it is damaged), take a fresh safety backup (refuse if that
     * fails), then replay the file with foreign-key checks off.
     *
     * @param string $relPath "backups/YYYY/MM/db-YYYYMMDD-HHMMSS.sql.gz"
     * @return array{ok:bool,error:string,detail:string,code:string,safety_path:?string,statements:int}
     *         error is a plain-words message for staff; detail is the technical line for the administrator.
     */
    function rh_db_restore_run(PDO $pdo, string $root, string $relPath, array $opts = []): array
    {
        $root = rtrim($root, '/\\');
        $res = static function (bool $ok, string $err = '', string $code = '', ?string $safety = null, int $n = 0, string $detail = '') use ($root): array {
            if (!$ok) {
                rh_db_backup_log($root, 'RESTORE FAILED (' . $code . '): ' . $err . ($detail !== '' ? ' | ' . $detail : ''));
            }
            return ['ok' => $ok, 'error' => $err, 'detail' => $detail, 'code' => $code, 'safety_path' => $safety, 'statements' => $n];
        };

        $rel = rh_db_backup_norm_rel($relPath);
        if ($rel === '') {
            return $res(false, 'Nothing was changed. That is not a valid backup file name.', 'bad_path');
        }
        $abs = $root . '/' . $rel;
        $base = realpath($root . '/backups');
        $real = is_file($abs) ? realpath($abs) : false;
        if ($real === false || $base === false
            || strpos(str_replace('\\', '/', $real), str_replace('\\', '/', $base) . '/') !== 0) {
            return $res(false, 'Nothing was changed. That backup file is no longer on the server. Choose another one.', 'not_found');
        }

        // One restore at a time.
        $gotLock = false;
        try {
            $gotLock = (int)$pdo->query("SELECT GET_LOCK('rh_db_restore', 0)")->fetchColumn() === 1;
        } catch (Throwable $e) {
            $gotLock = true; // cannot ask the server; carry on
        }
        if (!$gotLock) {
            return $res(false, 'Nothing was changed. A restore is already running. Wait for it to finish.', 'busy');
        }

        $safetyRel = null;
        $count = 0;
        try {
            // 1. Read the whole file first: it must decompress completely and end with the marker.
            $g = @gzopen($real, 'rb');
            $bytes = 0;
            $tail = '';
            if ($g) {
                while (!gzeof($g)) {
                    $chunk = gzread($g, 65536);
                    if ($chunk === false) {
                        $bytes = -1;
                        break;
                    }
                    $bytes += strlen($chunk);
                    $tail = substr($tail . $chunk, -2048);
                }
                gzclose($g);
            }
            if (!$g || $bytes <= 0 || strpos($tail, '-- Dump completed') === false) {
                return $res(false, 'Nothing was changed. This backup file looks damaged or incomplete. Choose an earlier one.', 'damaged');
            }

            // 2. Safety backup of the current data. No rotation, so the file being restored cannot be rotated out.
            $sb = rh_db_backup_run($pdo, $root, ['stamp' => false, 'rotate' => false]);
            if (!$sb['ok']) {
                if (!empty($sb['skipped'])) {
                    return $res(false, 'Nothing was changed. A backup is running right now. Wait a minute, then try again.', 'busy');
                }
                return $res(false, 'Nothing was changed. The system could not save a safety copy of your current data first, so the restore was not started.', 'safety_failed', null, 0, (string)$sb['error']);
            }
            $safetyRel = ltrim(str_replace('\\', '/', substr(str_replace('\\', '/', (string)$sb['path']), strlen(str_replace('\\', '/', $root)))), '/');

            // The backup bookkeeping describes the files on THIS server, not the data being
            // restored: keep today's values so a restore does not trigger a false "no backup for
            // days" or off-site alert straight away.
            $keepKeys = ['last_backup_at', 'last_backup_path', 'last_backup_size', 'last_backup_error', 'last_backup_error_at',
                'last_backup_downloaded_at', 'last_backup_alert_failed_at', 'last_backup_alert_stale_at', 'last_backup_alert_offsite_reminder_at'];
            $keepVals = [];
            try {
                $kq = $pdo->prepare('SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($keepKeys), '?')) . ')');
                $kq->execute($keepKeys);
                $keepVals = $kq->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (Throwable $e) {
                $keepVals = [];
            }

            // 3. Replay.
            try {
                $prev = $pdo->query('SELECT @@SQL_MODE, @@TIME_ZONE, @@FOREIGN_KEY_CHECKS')->fetch(PDO::FETCH_NUM);
            } catch (Throwable $e) {
                $prev = false;
            }
            $g = @gzopen($real, 'rb');
            if (!$g) {
                return $res(false, 'Nothing was changed. The backup file could not be opened. Choose another one.', 'damaged', $safetyRel);
            }
            $st = rh_db_sql_splitter_new();
            $lastSql = '';
            $emit = static function (string $sql) use ($pdo, &$count, &$lastSql): void {
                $lastSql = $sql;
                $r = $pdo->exec($sql);
                if ($r === false) {
                    $ei = $pdo->errorInfo();
                    throw new RuntimeException((string)($ei[2] ?? 'statement failed'));
                }
                $count++;
            };
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                while (!gzeof($g)) {
                    $chunk = gzread($g, 65536);
                    if ($chunk === false) {
                        throw new RuntimeException('could not read the backup file');
                    }
                    rh_db_sql_splitter_feed($st, $chunk, false, $emit);
                }
                rh_db_sql_splitter_feed($st, '', true, $emit);
            } catch (Throwable $e) {
                $preview = substr(preg_replace('/\s+/', ' ', $lastSql), 0, 160);
                return $res(false,
                    'The restore stopped part-way, so your data may be a mix of old and new. Your data from just before the restore is saved as ' . $safetyRel
                    . '. Restore that file to get back to where you were, or ask your IT support for help.',
                    'sql_error', $safetyRel, $count,
                    'After ' . $count . ' statements: ' . $e->getMessage() . ($preview !== '' ? ' (near: ' . $preview . ')' : ''));
            } finally {
                @gzclose($g);
                try {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=' . ((is_array($prev) && (string)$prev[2] === '0') ? '0' : '1'));
                    if (is_array($prev)) {
                        $pdo->exec('SET SESSION sql_mode=' . $pdo->quote((string)$prev[0]));
                        $pdo->exec('SET SESSION time_zone=' . $pdo->quote((string)$prev[1]));
                    }
                } catch (Throwable $e) {
                    // session settings only
                }
            }

            if ($keepVals) {
                rh_db_backup_stamp($pdo, $keepVals, $root);
            }
            // Settings and other cached rows may have changed under us.
            $GLOBALS['_SITE_SETTINGS'] = [];
            if (function_exists('clearCache')) {
                @clearCache();
            }
            rh_db_backup_log($root, sprintf('RESTORE OK: %s (%d statements); safety copy %s', $rel, $count, (string)$safetyRel));
            return $res(true, '', '', $safetyRel, $count);
        } finally {
            if ($gotLock) {
                try {
                    $pdo->query("SELECT RELEASE_LOCK('rh_db_restore')");
                } catch (Throwable $e) {
                    // frees itself when the connection closes
                }
            }
        }
    }
}
