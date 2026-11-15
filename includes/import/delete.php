<?php
/**
 * Import - remove one entry from Import history (owner 2026-11-15: "a high level operation").
 *
 *   import_job_delete_block_reason($job)                                     plain reason a job cannot be removed ('' = it can)
 *   import_job_delete($pdo, $root, $jobId, $actor, $opts)                    remove one import_jobs row, backup first (stock count wipe calls this)
 *   import_job_delete_checked($pdo, $root, $jobId, $actor, $typed, $pw, $o)  the screen's path: permission + typed DELETE + own password, then delete
 *
 * What it removes: the import_jobs row (the history entry) and, if one is still kept, the stored copy of the uploaded file.
 * What it never touches: anything the import brought in (stock items, opening counts, batches, quantities, costs), learned
 * import_aliases rows, the activity log. Linked data (DEPARTMENTS.md): no table points at import_jobs (no foreign key, no
 * import_job_id column; the opening count only names "Import #N" in its note) - everything linked is KEEP.
 *
 * Safety design (same pattern as admin/includes/stock-clear-lib.php):
 *  - Only a finished job (committed / failed / cancelled) can be removed. Unfinished jobs (uploaded / analysed / reviewing /
 *    confirmed) are "in progress": they may be read or saved right now, and they already have Cancel. The analysis and the
 *    commit both refuse a finished job, and the status is re-read under the row lock, so nothing can be half-way.
 *  - ONE transaction: the row is read FOR UPDATE, written to backups/import-delete/import-<id>-<YmdHis>.json.gz (gz JSON of the
 *    whole row), the stored file is COPIED next to it, both are re-read and verified, then the row is deleted and committed.
 *    The original stored file is unlinked only after that commit, so a rollback never leaves a row pointing at a missing file.
 *  - 'external_tx' => true: the caller owns the transaction (tests, the stock count wipe). The stored file is then copied but the
 *    original is NOT unlinked (the caller may still roll back); once the row is gone it is a stray that import_purge_staged()
 *    deletes after the retention window.
 *  - Anything that goes wrong rolls back and removes the half-written backup files: "Nothing was changed".
 *  - No DDL here (DDL would silently commit an open transaction).
 */

require_once __DIR__ . '/store.php';

if (!defined('RH_IMPORT_DELETE_DIR')) {
    define('RH_IMPORT_DELETE_DIR', 'backups/import-delete');
}

if (!function_exists('import_job_delete')) {

    /** '' when this job may be removed from the history, else the plain reason it may not. */
    function import_job_delete_block_reason(array $job): string
    {
        $status = (string)($job['status'] ?? '');
        if (in_array($status, ['committed', 'failed', 'cancelled'], true)) {
            return '';
        }
        $marker = (string)($job['error_text'] ?? '');
        if (strpos($marker, 'Reading') === 0) {
            return 'This file is being read right now. Wait until it has finished, then cancel or finish the import before deleting it.';
        }
        return 'This import is still in progress. Finish it or cancel it first; only finished, failed or cancelled imports can be deleted from the history.';
    }

    /** The backup folder (created on demand, denied from the web). Throws when it cannot be used. */
    function import_delete_dir(string $root): string
    {
        $dir = rtrim(str_replace('\\', '/', $root), '/') . '/' . RH_IMPORT_DELETE_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('The backup folder for deleted imports could not be created.');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('The backup folder for deleted imports cannot be written to.');
        }
        return $dir;
    }

    /** One JSON value, lossless: text that is not valid UTF-8 is kept as base64 instead of being altered. */
    function import_delete_json_value($v): string
    {
        if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
            return json_encode(['base64' => base64_encode($v)]);
        }
        $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($j === false) {
            throw new RuntimeException('The backup copy could not be written (unreadable value).');
        }
        return $j;
    }

    /** Short display name of an account ('' when none). */
    function import_delete_user_name(PDO $pdo, $userId): string
    {
        if (!$userId) {
            return '';
        }
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(full_name, ''), username) FROM admin_users WHERE id = ?");
        $st->execute([(int)$userId]);
        $n = $st->fetchColumn();
        return is_string($n) && $n !== '' ? $n : ('user #' . (int)$userId);
    }

    /**
     * Remove one job from Import history, with a verified backup first. No permission check here: callers check first
     * (the screen uses import_job_delete_checked(); the stock count wipe runs behind Clear Stock Data's own checks).
     *
     * @param string $root   project root; the backup goes to <root>/backups/import-delete/
     * @param array  $actor  ['id'=>int,'username'=>string,'full_name'=>string] - the person deleting (logged)
     * @param array  $opts   external_tx (bool): the caller owns the transaction (no begin/commit/rollback here)
     *                       log_note (string): replaces the closing "Nothing it imported was changed." of the activity-log line
     *                       (the stock count wipe passes its own sentence, because it does change what the import brought in)
     * @return array{ok:bool,error:string,snapshot:string,job:array}
     */
    function import_job_delete(PDO $pdo, string $root, int $jobId, array $actor, array $opts = []): array
    {
        $fail = static function (string $why): array {
            return ['ok' => false, 'error' => rtrim($why, '.') . '. Nothing was changed.', 'snapshot' => '', 'job' => []];
        };
        $external = !empty($opts['external_tx']);
        if ($jobId <= 0) {
            return $fail('That import was not found');
        }
        if ($external !== $pdo->inTransaction()) {
            return $fail($external ? 'No transaction is open' : 'Another change is in progress on this connection');
        }
        try {
            $dir = import_delete_dir($root);
        } catch (Throwable $e) {
            return $fail($e->getMessage());
        }

        $stamp = date('YmdHis');
        $base = 'import-' . $jobId . '-' . $stamp;
        for ($i = 1; $i < 50 && (is_file($dir . '/' . $base . '.json.gz') || is_file($dir . '/' . $base . '-upload.bin')); $i++) {
            $base = 'import-' . $jobId . '-' . $stamp . '-' . $i;
        }
        $snap = $dir . '/' . $base . '.json.gz';
        $tmp = $dir . '/.' . $base . '.json.gz.tmp';
        $fileCopy = $dir . '/' . $base . '-upload.bin';
        $copied = false;
        $started = false;
        $row = null;
        $srcPath = '';
        try {
            if (!$external) {
                $pdo->beginTransaction();
                $started = true;
            }
            $st = $pdo->prepare('SELECT * FROM import_jobs WHERE id = ? FOR UPDATE');
            $st->execute([$jobId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new DomainException('That import was not found. It may have been deleted already');
            }
            $why = import_job_delete_block_reason($row);
            if ($why !== '') {
                throw new DomainException($why);
            }

            // 1) the stored upload, if any: copied (not moved) and checked byte for byte
            $srcPath = import_job_file_path($row);
            if ($srcPath !== '') {
                if (!@copy($srcPath, $fileCopy)) {
                    throw new RuntimeException('The stored copy of the file could not be backed up');
                }
                $copied = true;
                @chmod($fileCopy, 0640);
                if (hash_file('sha256', $fileCopy) !== hash_file('sha256', $srcPath)) {
                    throw new RuntimeException('The backup of the stored file did not match the original');
                }
            }

            // 2) the whole row as gz JSON, streamed (analysis_json can be large), hashed while writing
            $gz = @gzopen($tmp, 'wb6');
            if (!$gz) {
                throw new RuntimeException('The backup copy could not be created');
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            $put = static function (string $s) use ($gz, $hash, &$bytes): void {
                if ($s === '') {
                    return;
                }
                if (gzwrite($gz, $s) !== strlen($s)) {
                    throw new RuntimeException('The backup copy could not be written');
                }
                hash_update($hash, $s);
                $bytes += strlen($s);
            };
            $put('{"format":"rh-import-delete-1","table":"import_jobs"'
                . ',"deleted_at":' . import_delete_json_value(date('Y-m-d H:i:s'))
                . ',"deleted_by":' . import_delete_json_value(['id' => (int)($actor['id'] ?? 0), 'username' => (string)($actor['username'] ?? ''), 'full_name' => (string)($actor['full_name'] ?? '')])
                . ',"file_backup":' . import_delete_json_value($copied ? basename($fileCopy) : null)
                . ',"row":{');
            $first = true;
            foreach ($row as $col => $val) {
                $put(($first ? '' : ',') . import_delete_json_value((string)$col) . ':');
                $put(import_delete_json_value($val));
                $first = false;
            }
            $put('}}');
            if (!gzclose($gz)) {
                throw new RuntimeException('The backup copy could not be saved');
            }
            $wanted = hash_final($hash);

            // 3) re-read the file and check it holds exactly what was written, and that it is the right job
            $rd = @gzopen($tmp, 'rb');
            if (!$rd) {
                throw new RuntimeException('The backup copy could not be read back');
            }
            $h2 = hash_init('sha256');
            $n2 = 0;
            $head = '';
            while (!gzeof($rd)) {
                $chunk = gzread($rd, 1048576);
                if ($chunk === false) {
                    break;
                }
                if (strlen($head) < 4096) {
                    $head .= substr($chunk, 0, 4096 - strlen($head));
                }
                hash_update($h2, $chunk);
                $n2 += strlen($chunk);
            }
            gzclose($rd);
            if ($n2 !== $bytes || !hash_equals($wanted, hash_final($h2))) {
                throw new RuntimeException('The backup copy did not match the import');
            }
            if (strpos($head, '"row":{"id":' . import_delete_json_value($row['id'])) === false) {
                throw new RuntimeException('The backup copy did not match the import');
            }
            if (!@rename($tmp, $snap)) {
                throw new RuntimeException('The backup copy could not be saved');
            }

            // 4) delete the history entry
            $del = $pdo->prepare('DELETE FROM import_jobs WHERE id = ?');
            $del->execute([$jobId]);
            if ($del->rowCount() !== 1) {
                throw new RuntimeException('The import could not be deleted');
            }
            if ($started) {
                $pdo->commit();
                $started = false;
            }
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $e2) {
                    // connection gone: nothing was committed
                }
            }
            if (isset($gz) && is_resource($gz)) {
                @gzclose($gz);
            }
            foreach ([$tmp, $snap, $copied ? $fileCopy : ''] as $f) {
                if ($f !== '' && is_file($f)) {
                    @unlink($f);
                }
            }
            if ($e instanceof DomainException) {
                return $fail($e->getMessage());
            }
            error_log('import_job_delete #' . $jobId . ': ' . $e->getMessage());
            if ($e instanceof PDOException) {
                $busy = (int)($e->errorInfo[1] ?? 0) === 1205;
                return $fail($busy ? 'This import is busy right now (it is being saved). Wait a minute and try again' : 'The database refused the delete');
            }
            return $fail($e->getMessage());
        }

        // 5) after the commit: the original stored file (the backup copy stays), then the activity log
        $fileNote = 'No stored copy of the file was left.';
        if ($copied) {
            if (!$external) {
                import_delete_stored((string)$row['stored_path']);
                $fileNote = 'Its stored copy of the file was moved to the backup.';
            } else {
                $fileNote = 'Its stored copy of the file was copied to the backup.';
            }
        }
        // The default closing sentence is true for the Import screen. A caller that changes what the import brought in (the stock
        // count wipe) passes its own sentence in log_note so the audit line stays true.
        $logNote = trim((string)($opts['log_note'] ?? ''));
        if ($logNote === '') {
            $logNote = 'Nothing it imported was changed.';
        }
        $created = $updated = null;
        $res = !empty($row['result_json']) ? json_decode((string)$row['result_json'], true) : null;
        if (is_array($res)) {
            $created = (int)($res['created'] ?? 0);
            $updated = (int)($res['updated'] ?? 0);
        }
        $ranBy = import_delete_user_name($pdo, $row['created_by'] ?? null);
        $savedBy = import_delete_user_name($pdo, $row['committed_by'] ?? null);
        $summary = [
            'id' => (int)$row['id'], 'file' => (string)$row['original_name'], 'status' => (string)$row['status'],
            'created' => $created, 'updated' => $updated,
            'ran_by' => $ranBy, 'ran_at' => (string)($row['created_at'] ?? ''),
            'committed_by' => $savedBy, 'committed_at' => (string)($row['committed_at'] ?? ''),
            'file_backup' => $copied ? basename($fileCopy) : '',
        ];
        if (function_exists('logActivity')) {
            $statusWord = ['committed' => 'imported', 'failed' => 'could not be read', 'cancelled' => 'cancelled'][$summary['status']] ?? $summary['status'];
            logActivity((int)($actor['id'] ?? 0), 'import_history_delete',
                'Import history entry deleted: import #' . $summary['id'] . ' (' . $summary['file'] . '), status ' . $statusWord
                . ($created !== null ? ', ' . $created . ' created, ' . $updated . ' updated' : '')
                . '. Started by ' . ($ranBy !== '' ? $ranBy : 'unknown') . ' on ' . ($summary['ran_at'] !== '' ? $summary['ran_at'] : 'unknown date')
                . ($summary['committed_at'] !== '' ? '; imported by ' . ($savedBy !== '' ? $savedBy : 'unknown') . ' on ' . $summary['committed_at'] : '')
                . '. ' . $fileNote . ' Backup ' . basename($snap) . '. ' . $logNote);
        }
        return ['ok' => true, 'error' => '', 'snapshot' => basename($snap), 'job' => $summary];
    }

    /**
     * The screen's path: the same high-level permission as Clear Stock Data (administrator account + data_reset, Tier S), then the
     * typed word DELETE and the person's own password, then import_job_delete(). The permission is checked before the job is looked at.
     * @return array{ok:bool,error:string,snapshot:string,job:array}
     */
    function import_job_delete_checked(PDO $pdo, string $root, int $jobId, array $actor, string $typedWord, string $password, array $opts = []): array
    {
        require_once dirname(__DIR__, 2) . '/admin/includes/stock-clear-lib.php';
        $uid = (int)($actor['id'] ?? 0);
        [$authOk] = rh_sc_authorize($pdo, $uid);
        if (!$authOk) {
            return ['ok' => false, 'error' => 'Your account cannot delete import history. It needs an administrator account with the Clear Stock Data permission. Nothing was changed.', 'snapshot' => '', 'job' => []];
        }
        [$okC, $whyC] = rh_sc_check_confirm($pdo, $uid, 'DELETE', $typedWord, $password);
        if (!$okC) {
            return ['ok' => false, 'error' => (string)$whyC, 'snapshot' => '', 'job' => []];
        }
        return import_job_delete($pdo, $root, $jobId, $actor, $opts);
    }
}
