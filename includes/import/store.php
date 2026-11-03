<?php
/**
 * Import - safe storage of an uploaded file (Phase 1b).
 *
 *   import_store_upload($pdo, $tmpPath, $origName, $userId, $opts)   check + move the file into storage/imports/, create the job
 *   import_storage_dir()                                             the private folder (created on demand, denied from the web)
 *   import_job_file_path($job)                                       absolute path of a job's staged file ('' when gone)
 *   import_delete_stored($storedName)                                delete one staged file (only inside the folder)
 *   import_purge_staged($pdo, $force)                                delete staged files after commit/cancel or when too old
 *
 * Rules: size cap (setting data_import_max_mb, default 10), extension allow-list, magic-byte check (zip 'PK' for .xlsx/.xlsm,
 * plain text for .csv/.tsv), random file name with NO original extension, mode 0640, never executed or included, sha256 stored,
 * a file that was already imported is refused unless an admin ticks "import again" (checked here on the server).
 * The folder is inside the project but not served: .htaccess denies everything and the files carry no extension.
 */

require_once __DIR__ . '/analyze.php';

if (!function_exists('import_store_upload')) {

    function import_storage_dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/imports';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (is_dir($dir)) {
            if (!is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "# Uploaded stock lists: never served.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
            }
            if (!is_file($dir . '/index.html')) {
                @file_put_contents($dir . '/index.html', '');
            }
        }
        return $dir;
    }

    /** 'abc123...ef.bin' style names only. */
    function import_stored_name_ok(string $name): bool
    {
        return (bool)preg_match('/^[a-f0-9]{32}\.bin$/', $name);
    }

    function import_job_file_path(array $job): string
    {
        $name = (string)($job['stored_path'] ?? '');
        if ($name === '' || !import_stored_name_ok($name)) {
            return '';
        }
        $dir = realpath(import_storage_dir());
        $p = $dir !== false ? realpath($dir . '/' . $name) : false;
        if ($dir === false || $p === false || strpos($p, $dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($p)) {
            return '';
        }
        return $p;
    }

    function import_delete_stored(string $storedName): bool
    {
        if (!import_stored_name_ok($storedName)) {
            return false;
        }
        $dir = realpath(import_storage_dir());
        $p = $dir !== false ? realpath($dir . '/' . $storedName) : false;
        if ($dir === false || $p === false || strpos($p, $dir . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }
        return @unlink($p);
    }

    /** csv/tsv must be plain text: no NUL bytes (that also catches UTF-16 and binary files) and not a zip / old Office file. */
    function import_text_magic_error(string $path, string $ext): string
    {
        $fh = fopen($path, 'rb');
        $head = $fh ? (string)fread($fh, 8192) : '';
        if ($fh) {
            fclose($fh);
        }
        if (substr($head, 0, 2) === 'PK' || substr($head, 0, 4) === "\xD0\xCF\x11\xE0") {
            return 'This file is named .' . $ext . ' but is a spreadsheet/Office file, not plain text. Save it as .xlsx and upload that.';
        }
        if (strpos($head, "\0") !== false) {
            return 'This file is named .' . $ext . ' but is not plain text (it may be UTF-16 or a binary file). Save it again as "CSV UTF-8".';
        }
        if (substr($head, 0, 4) === '%PDF') {
            return 'This is a PDF, not a CSV. PDFs are not supported yet; upload the Excel or CSV version.';
        }
        return '';
    }

    function import_clean_name(string $orig): string
    {
        $n = basename(str_replace('\\', '/', $orig));
        $n = preg_replace('/[\x00-\x1F\x7F]+/', '', $n);
        $n = trim((string)$n);
        return mb_substr($n === '' ? 'upload' : $n, 0, 255);
    }

    /**
     * Check and stage an upload, create the job (status 'uploaded').
     * $opts: from_upload (default true: the file came in via PHP's upload, so move_uploaded_file; false = copy, used by tests),
     *        allow_reimport (honoured ONLY for an admin), max_bytes (default: setting data_import_max_mb).
     * Returns ['ok'=>bool, 'error'=>string, 'job_id'=>int, 'duplicate'=>?array{job_id,committed_at,by}].
     */
    function import_store_upload(PDO $pdo, string $tmpPath, string $origName, int $userId, array $opts = []): array
    {
        $fail = static function (string $msg, array $extra = []) {
            return $extra + ['ok' => false, 'error' => $msg, 'job_id' => 0, 'duplicate' => null];
        };
        $name = import_clean_name($origName);
        $maxMb = max(1, (int)rh_import_setting('data_import_max_mb', $pdo));
        $maxBytes = (int)($opts['max_bytes'] ?? ($maxMb * 1048576));
        $fromUpload = ($opts['from_upload'] ?? true) !== false;
        if ($fromUpload && !is_uploaded_file($tmpPath)) {
            return $fail('The file did not arrive correctly. Please try again.');
        }
        $ext = '';
        $err = import_precheck_file($tmpPath, $name, $maxBytes, $ext);   // extension allow-list, size, zip magic
        if ($err !== '') {
            return $fail($err);
        }
        if (in_array($ext, ['csv', 'tsv'], true)) {
            $err = import_text_magic_error($tmpPath, $ext);
            if ($err !== '') {
                return $fail($err);
            }
        }
        $sha = hash_file('sha256', $tmpPath);
        $dup = null;
        $st = $pdo->prepare("SELECT j.id, j.committed_at, COALESCE(NULLIF(u.full_name, ''), u.username, 'someone') AS by_name
                             FROM import_jobs j LEFT JOIN admin_users u ON u.id = j.committed_by
                             WHERE j.sha256 = ? AND j.status = 'committed' ORDER BY j.id DESC LIMIT 1");
        $st->execute([$sha]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $dup = ['job_id' => (int)$row['id'], 'committed_at' => (string)$row['committed_at'], 'by' => (string)$row['by_name']];
            $allow = !empty($opts['allow_reimport']) && rh_import_can_reimport($userId, $pdo);
            if (!$allow) {
                return $fail('This file was already imported on ' . substr((string)$row['committed_at'], 0, 10) . ' by ' . $row['by_name'] . '. Importing it again needs an administrator to tick "import again".', ['duplicate' => $dup]);
            }
        }
        $dir = import_storage_dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            return $fail('The import folder is not writable on the server. Please tell the administrator.');
        }
        $stored = bin2hex(random_bytes(16)) . '.bin';
        $dest = $dir . '/' . $stored;
        $moved = $fromUpload ? @move_uploaded_file($tmpPath, $dest) : @copy($tmpPath, $dest);
        if (!$moved || !is_file($dest)) {
            return $fail('The file could not be saved. Please try again.');
        }
        @chmod($dest, 0640);
        if (hash_file('sha256', $dest) !== $sha) {
            @unlink($dest);
            return $fail('The file changed while it was being saved. Please try again.');
        }
        $days = max(1, (int)rh_import_setting('data_import_retention_days', $pdo));
        try {
            $pdo->prepare("INSERT INTO import_jobs (target, original_name, stored_path, sha256, size_bytes, status, created_by, expires_at)
                           VALUES ('stock', ?, ?, ?, ?, 'uploaded', ?, DATE_ADD(NOW(), INTERVAL $days DAY))")
                ->execute([$name, $stored, $sha, (int)filesize($dest), $userId > 0 ? $userId : null]);
        } catch (Throwable $e) {
            @unlink($dest);
            error_log('import_store_upload: ' . $e->getMessage());
            return $fail('The upload could not be recorded. Please try again.');
        }
        return ['ok' => true, 'error' => '', 'job_id' => (int)$pdo->lastInsertId(), 'duplicate' => $dup];
    }

    /**
     * Delete staged files that are no longer needed: jobs that are committed / cancelled / failed, jobs past expires_at
     * (they are marked cancelled), and stray files older than the retention window that no job points at.
     * Rate-limited to once every 10 minutes unless $force. Safe to call on page load. Returns counts.
     */
    function import_purge_staged(PDO $pdo, bool $force = false): array
    {
        $res = ['files_deleted' => 0, 'jobs_expired' => 0, 'skipped' => false];
        $dir = import_storage_dir();
        $mark = $dir . '/.last_purge';
        if (!$force && is_file($mark) && (time() - (int)@filemtime($mark)) < 600) {
            $res['skipped'] = true;
            return $res;
        }
        @touch($mark);
        try {
            $days = max(1, (int)rh_import_setting('data_import_retention_days', $pdo));
            // expire old jobs that nobody finished (a committed job never expires; its file is removed below)
            $res['jobs_expired'] = (int)$pdo->exec("UPDATE import_jobs SET status = 'cancelled', error_text = 'Expired: not finished within the retention period.'
                                                    WHERE status IN ('uploaded','analysed','reviewing','confirmed') AND expires_at IS NOT NULL AND expires_at < NOW()");
            $rows = $pdo->query("SELECT id, stored_path FROM import_jobs WHERE stored_path IS NOT NULL AND status IN ('committed','cancelled','failed')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (import_delete_stored((string)$r['stored_path']) || !is_file($dir . '/' . $r['stored_path'])) {
                    $res['files_deleted']++;
                }
                $pdo->prepare('UPDATE import_jobs SET stored_path = NULL WHERE id = ?')->execute([(int)$r['id']]);
            }
            // strays: well-formed names, older than the window, not referenced by any job
            $ref = array_flip($pdo->query('SELECT stored_path FROM import_jobs WHERE stored_path IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
            foreach ((array)@scandir($dir) as $f) {
                if (import_stored_name_ok((string)$f) && !isset($ref[$f]) && (time() - (int)@filemtime($dir . '/' . $f)) > $days * 86400) {
                    if (import_delete_stored((string)$f)) {
                        $res['files_deleted']++;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('import_purge_staged: ' . $e->getMessage());
        }
        return $res;
    }
}
