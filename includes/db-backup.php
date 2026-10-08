<?php

/**
 * Database backup engine (shared by scripts/backup_database.php and the web scheduler).
 *
 *   1. mysqldump, only when EVERY shell function it needs is really callable
 *      (shared hosts remove them via disable_functions; calling one is a fatal Error).
 *   2. Otherwise a pure-PHP PDO dumper that streams rows (unbuffered cursor) into gzip.
 *
 * Output: gzipped SQL at <root>/backups/YYYY/MM/db-YYYYMMDD-HHMMSS.sql.gz
 * Rotation: 14 daily / 8 weekly / 12 monthly. Log: <root>/logs/backup.log.
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

        $w = static function (string $s) use ($gz): void {
            if ($s !== '' && !gzwrite($gz, $s)) {
                throw new RuntimeException('write to gzip failed (disk full?)');
            }
        };
        $strip = static function (string $sql): string {
            return preg_replace('/\bDEFINER\s*=\s*(?:`[^`]*`|\'[^\']*\'|[^\s@`\']+)@(?:`[^`]*`|\'[^\']*\'|[^\s`\']+)\s*/i', '', $sql);
        };

        $w("-- " . (function_exists('getSetting') ? ((string)getSetting('site_name') ?: 'Hotel') : 'Hotel') . " database backup\n-- Generated: " . date('c') . "\n");
        $w("-- Database: $dbName\n\n");
        $w("SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

        // Tables/views are listed up front (fully read) so no cursor is open while dumping.
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $views  = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM);

        foreach ($tables as [$tbl]) {
            $tblQ = '`' . str_replace('`', '``', $tbl) . '`';
            $w("-- ----------------------------\n-- Table: $tbl\n-- ----------------------------\n");
            $w("DROP TABLE IF EXISTS $tblQ;\n");
            $cs = $pdo->query("SHOW CREATE TABLE $tblQ");
            $createRow = $cs->fetch(PDO::FETCH_NUM);
            $cs->closeCursor();
            $w(($createRow[1] ?? '') . ";\n\n");

            $stmt = $pdo->query("SELECT * FROM $tblQ");
            $batch = [];
            $cols = null;
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($cols === null) {
                    $cols = array_map(static function ($c) {
                        return '`' . str_replace('`', '``', (string)$c) . '`';
                    }, array_keys($row));
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
                if (count($batch) >= 200) {
                    $w("INSERT INTO $tblQ (" . implode(',', $cols) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            $stmt->closeCursor();
            if ($batch) {
                $w("INSERT INTO $tblQ (" . implode(',', $cols) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
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

        $w("SET FOREIGN_KEY_CHECKS=1;\n");
        $w("-- Dump completed\n");
    }

    /** 14 daily / 8 weekly / 12 monthly. */
    function rh_db_backup_rotate(string $root): void
    {
        $rootBackups = $root . '/backups';
        if (!is_dir($rootBackups)) {
            return;
        }
        $all = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootBackups, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if ($f->isFile() && preg_match('/^db-(\d{8})-(\d{6})\.sql\.gz$/', $f->getFilename(), $m)) {
                $all[] = ['path' => $f->getPathname(), 'date' => $m[1], 'time' => $m[2]];
            }
        }
        if (!$all) {
            return;
        }
        usort($all, static function ($a, $b) {
            return strcmp($b['date'] . $b['time'], $a['date'] . $a['time']);
        });

        $keep = [];
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
        foreach ($all as $b) {
            if (!isset($keep[$b['path']])) {
                @unlink($b['path']);
                rh_db_backup_log($root, 'Rotated out: ' . $b['path']);
            }
        }
    }

    /**
     * Run one backup.
     *
     * @param array $opts stamp (bool, default true): update last_backup_at/_path/_size settings.
     * @return array{ok:bool,path:?string,size:int,method:string,error:?string}
     */
    function rh_db_backup_run(PDO $pdo, string $root, array $opts = []): array
    {
        $stamp  = array_key_exists('stamp', $opts) ? (bool)$opts['stamp'] : true;
        $root   = rtrim($root, '/\\');
        $fail   = static function (string $err, string $method = 'php') use ($root): array {
            rh_db_backup_log($root, 'FATAL: ' . $err);
            return ['ok' => false, 'path' => null, 'size' => 0, 'method' => $method, 'error' => $err];
        };

        $logDir = $root . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $lock = @fopen($logDir . '/backup.lock', 'c+');
        if (!$lock) {
            return $fail('cannot open backup lock file');
        }
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            rh_db_backup_log($root, 'SKIP: backup already running; lock is held.');
            return ['ok' => false, 'path' => null, 'size' => 0, 'method' => 'php', 'error' => 'another backup is already running'];
        }
        @ftruncate($lock, 0);
        @fwrite($lock, getmypid() . ' ' . date('c') . PHP_EOL);

        $tmpFile = null;
        try {
            $timestamp = date('Ymd-His');
            $backupDir = $root . '/backups/' . date('Y') . '/' . date('m');
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
                return $fail('cannot create backup directory ' . $backupDir);
            }
            $ht = $root . '/backups/.htaccess';
            if (!file_exists($ht)) {
                @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            }
            if (!file_exists($root . '/backups/index.html')) {
                @file_put_contents($root . '/backups/index.html', '');
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
                    return $fail('cannot open gz output ' . $tmpFile);
                }
                try {
                    rh_db_backup_php_dump($gz, defined('DB_NAME') ? (string)DB_NAME : '');
                    if (!gzclose($gz)) {
                        throw new RuntimeException('closing gzip output failed (disk full?)');
                    }
                } catch (Throwable $e) {
                    @gzclose($gz);
                    @unlink($tmpFile);
                    return $fail('PHP fallback dump failed: ' . $e->getMessage());
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
                return $fail('gzip integrity check failed (truncated or incomplete dump)', $method);
            }

            if (!@rename($tmpFile, $outFile)) {
                @unlink($tmpFile);
                return $fail('rename to ' . $outFile . ' failed', $method);
            }
            $tmpFile = null;
            @chmod($outFile, 0640);
            $size = (int)(@filesize($outFile) ?: 0);
            rh_db_backup_log($root, sprintf('OK: %s (%s, %d bytes)', $outFile, $method, $size));

            if ($stamp) {
                try {
                    $upd = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group, updated_at) VALUES (?, ?, 'system', NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
                    $rel = ltrim(str_replace($root, '', $outFile), '/\\');
                    $upd->execute(['last_backup_at', date('Y-m-d H:i:s')]);
                    $upd->execute(['last_backup_path', $rel]);
                    $upd->execute(['last_backup_size', (string)$size]);
                } catch (Throwable $e) {
                    rh_db_backup_log($root, 'WARN: could not update site_settings: ' . $e->getMessage());
                }
            }

            try {
                rh_db_backup_rotate($root);
            } catch (Throwable $e) {
                rh_db_backup_log($root, 'WARN: rotation failed: ' . $e->getMessage());
            }

            return ['ok' => true, 'path' => $outFile, 'size' => $size, 'method' => $method, 'error' => null];
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
}
