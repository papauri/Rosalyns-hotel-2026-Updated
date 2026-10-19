<?php

/**
 * Admin — Database Backup Management
 * Run backups, list available backups, download, and restore from browser.
 * Restore is restricted to admin role only.
 */

require_once 'admin-init.php';
require_once __DIR__ . '/includes/bulk-actions.php';
require_once dirname(__DIR__) . '/includes/db-backup.php';
// Bootstrap fallback guards (admin-init.php sets these; guards satisfy static analysis)
$user       = $user       ?? ['id' => 0, 'username' => '', 'role' => 'guest', 'full_name' => ''];
$csrf_token = $csrf_token ?? generateCsrfToken();
$site_name  = $site_name  ?? getSetting('site_name', 'Hotel');

// Only admin + manager may access this page
if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    rhDenyAndRedirectHome((int)$user['id'], (string)($user['role'] ?? ''), basename($_SERVER['PHP_SELF']));
    exit;
}

$ROOT         = dirname(__DIR__);
$backupsDir   = $ROOT . '/backups';
$logFile      = $ROOT . '/logs/backup.log';
$backupScript = $ROOT . '/scripts/backup_database.php';
$restoreScript = $ROOT . '/scripts/restore_database.php';

/**
 * A problem told in plain words: what happened, what it means, the one thing to do next.
 * The technical line goes into a collapsed "Details for the administrator" (escaped).
 * Returns HTML that is already safe to print.
 */
function rh_bm_problem(string $code, string $detail = ''): string
{
    [$what, $means, $todo] = rh_db_backup_explain($code);
    $html = '<strong>' . htmlspecialchars($what) . '</strong> ' . htmlspecialchars($means) . ' ' . htmlspecialchars($todo);
    if ($detail !== '') {
        $html .= '<details class="bm-details"><summary>Details for the administrator</summary><div>'
            . htmlspecialchars($detail) . '</div></details>';
    }
    return $html;
}

/**
 * Every backup file on disk, newest first. 'id' is the file's timestamp (YYYYMMDDHHMMSS), so
 * the shared bulk bar's numeric ids map back to a file without ever trusting a browser path.
 */
function rh_backup_files(string $root, string $dir): array
{
    $list = [];
    if (!is_dir($dir)) {
        return $list;
    }
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->isFile() && preg_match('/^db-(\d{8})-(\d{6})\.sql\.gz$/', $f->getFilename(), $m)) {
            $rel = str_replace('\\', '/', ltrim(str_replace($root, '', $f->getPathname()), '/\\'));
            $list[] = [
                'id'    => $m[1] . $m[2],
                'rel'   => $rel,
                'abs'   => $f->getPathname(),
                'name'  => $f->getFilename(),
                'size'  => $f->getSize(),
                'mtime' => $f->getMTime(),
                'date'  => substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2)
                    . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2),
            ];
        }
    }
    // Newest by the time in the name (the order rotation uses), not by file mtime.
    usort($list, fn($a, $b) => strcmp($b['id'], $a['id']));
    return $list;
}

$message = '';
$error   = '';

// ── POST actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF: all POST actions require the standard admin CSRF token
    // The shared bulk bar posts the token as csrf_token; this page's own forms use _tok.
    if (!validateCsrfToken($_POST['_tok'] ?? ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid form token. Please refresh and try again.';
    } else {

        if ($action === 'run_backup') {
            // In this request, not a child process: works where shell functions are switched off.
            @set_time_limit(900);
            @ignore_user_abort(true);
            // A copy on a Windows laptop must never stamp "last backup" on the shared live database.
            $r = rh_db_backup_run($pdo, $ROOT, ['stamp' => PHP_OS_FAMILY !== 'Windows']);
            if ($r['ok']) {
                $message = 'Backup saved (' . number_format(round($r['size'] / 1024)) . ' KB). Your hotel data is protected up to this moment.';
                if (PHP_OS_FAMILY === 'Windows') {
                    $message .= ' This is a local test copy, so the "last backup" time was not changed.';
                }
                $message = htmlspecialchars($message);
                logActivity((int)$user['id'], 'backup_run', 'Ran a database backup (' . basename((string)$r['path']) . ', ' . (int)$r['size'] . ' bytes)');
            } elseif (!empty($r['skipped'])) {
                $error = rh_bm_problem('locked');
            } else {
                $error = rh_bm_problem((string)$r['code'], (string)$r['error']);
                logActivity((int)$user['id'], 'backup_run_failed', 'Backup run from the Backups screen failed: ' . substr((string)$r['error'], 0, 200));
            }
        } elseif ($action === 'restore' && $user['role'] === 'admin') {
            // Restore is admin-only, needs the word RESTORE, and always takes a safety backup first.
            $relPath = rh_db_backup_norm_rel((string)($_POST['backup_file'] ?? ''));
            if (($_POST['confirm_text'] ?? '') !== 'RESTORE') {
                $error = 'Nothing was changed. To restore, you must type the word RESTORE exactly.';
            } elseif ($relPath === '') {
                $error = 'Nothing was changed. That is not a valid backup file name.';
            } else {
                @set_time_limit(900);
                @ignore_user_abort(true);
                rh_db_backup_log($ROOT, 'Restore requested by ' . ($user['username'] ?? 'admin') . ' from ' . $relPath);
                $r = rh_db_restore_run($pdo, $ROOT, $relPath);
                if ($r['ok']) {
                    $message = htmlspecialchars('The database was restored from ' . basename($relPath) . ' (' . (int)$r['statements'] . ' steps). '
                        . 'A copy of your data from just before the restore was saved as ' . (string)$r['safety_path'] . ', in case you need to undo it. '
                        . 'If anything looks wrong, sign out and in again.');
                    logActivity((int)$user['id'], 'backup_restored', 'Restored the database from ' . $relPath . '; safety copy ' . (string)$r['safety_path']);
                } else {
                    $error = '<strong>' . htmlspecialchars((string)$r['error']) . '</strong>';
                    if ($r['detail'] !== '') {
                        $error .= '<details class="bm-details"><summary>Details for the administrator</summary><div>' . htmlspecialchars((string)$r['detail']) . '</div></details>';
                    }
                    logActivity((int)$user['id'], 'backup_restore_failed', 'Restore from ' . $relPath . ' did not complete (' . (string)$r['code'] . ')');
                }
            }
        } elseif ($action === 'restore' && $user['role'] !== 'admin') {
            $error = 'Only administrators can perform a restore.';
        } elseif ($action === 'delete_backups') {
            if ($user['role'] !== 'admin') {
                $error = 'Only administrators can delete backups.';
            } else {
                // One row's Delete button sends backup_id; the bulk bar sends bulk_ids[].
                $ids = array_map('strval', adminBulkIds());
                if (preg_match('/^\d{14}$/', (string)($_POST['backup_id'] ?? ''))) {
                    $ids[] = (string)$_POST['backup_id'];
                }
                $files = rh_backup_files($ROOT, $backupsDir);
                $byId = array_column($files, null, 'id');
                $newestId = $files ? $files[0]['id'] : null;
                $base = realpath($backupsDir);
                $done = 0;
                $freed = 0;
                $skipped = [];
                $remaining = count($files);
                foreach (array_unique($ids) as $id) {
                    if (!isset($byId[$id])) {
                        adminBulkSkip($skipped, 'already removed');
                        continue;
                    }
                    if ($id === $newestId) {
                        adminBulkSkip($skipped, 'the newest backup is always kept');
                        continue;
                    }
                    if ($remaining <= 3) {
                        adminBulkSkip($skipped, 'at least 3 backups are always kept');
                        continue;
                    }
                    $f = $byId[$id];
                    $real = realpath($f['abs']);
                    if ($real === false || $base === false
                        || strpos(str_replace('\\', '/', $real), str_replace('\\', '/', $base) . '/') !== 0) {
                        adminBulkSkip($skipped, 'that file is not in the backups folder');
                        continue;
                    }
                    if (@unlink($real)) {
                        $done++;
                        $remaining--;
                        $freed += (int)$f['size'];
                        rh_db_backup_log($ROOT, 'Deleted by ' . ($user['username'] ?? 'admin') . ': ' . $f['rel']);
                    } else {
                        adminBulkSkip($skipped, 'the server would not let the system remove the file');
                    }
                }
                if ($done > 0) {
                    logActivity($user['id'], 'backup_deleted', 'Deleted ' . $done . ' backup file(s), ' . number_format($freed / 1048576, 1) . ' MB');
                }
                [$okText, $errText] = adminBulkOutcome($done === 1 ? 'backup deleted' : 'backups deleted', $done, $skipped);
                if ($okText !== '') {
                    $message = htmlspecialchars($okText . ' Space freed: ' . number_format($freed / 1048576, 1) . ' MB.');
                } else {
                    $error = htmlspecialchars($errText);
                }
            }
        }
    }
}

// ── Download action (GET) ────────────────────────────────────────────────────
if (isset($_GET['download']) && $user['role'] === 'admin') {
    $relPath = rh_db_backup_norm_rel((string)$_GET['download']);
    if ($relPath !== '') {
        $abs = $ROOT . '/' . $relPath;
        $realAbs = is_file($abs) ? realpath($abs) : false;
        $realBase = realpath($backupsDir);
        if ($realAbs !== false && $realBase !== false
            && strpos(str_replace('\\', '/', $realAbs), str_replace('\\', '/', $realBase) . '/') === 0) {
            // Remember when a copy last left the server (drives the off-site reminder).
            rh_db_backup_stamp($pdo, ['last_backup_downloaded_at' => date('Y-m-d H:i:s')], $ROOT);
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
            header('Content-Length: ' . filesize($abs));
            header('Cache-Control: no-store');
            readfile($abs);
            exit;
        }
    }
    http_response_code(404);
    exit('File not found.');
}

// ── Collect backup list ──────────────────────────────────────────────────────
$backups = rh_backup_files($ROOT, $backupsDir);
$backupsTotalSize = array_sum(array_column($backups, 'size'));
$canDelete = count($backups) > 3; // at least 3 backup files always stay on disk

// ── Recent backup log lines ──────────────────────────────────────────────────
$logLines = [];
if (is_file($logFile)) {
    $all = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $logLines = array_slice(array_reverse($all), 0, 30);
}

// ── Health info ─────────────────────────────────────────────────────────────
$lastBackupAt   = getSetting('last_backup_at');
$lastBackupSize = (int)getSetting('last_backup_size');
$backupAgeHours = $lastBackupAt ? round((time() - strtotime($lastBackupAt)) / 3600, 1) : null;
$isStale        = ($backupAgeHours === null || $backupAgeHours > 36);

$backupsDirReady = is_dir($backupsDir) || @mkdir($backupsDir, 0775, true);
$logsDir = dirname($logFile);
$logsDirReady = is_dir($logsDir) || @mkdir($logsDir, 0775, true);
$freeBytes = @disk_free_space($backupsDirReady ? $backupsDir : $ROOT);
$needBytes = max(2 * $lastBackupSize, 5 * 1048576);
$lastOffsite = trim((string)getSetting('last_backup_downloaded_at', ''));
$lastError   = trim((string)getSetting('last_backup_error', ''));
$lastErrorAt = trim((string)getSetting('last_backup_error_at', ''));
// A failure that a later good backup has replaced is not shown.
$showLastError = $lastError !== '' && $lastErrorAt !== '' && (!$lastBackupAt || strtotime($lastErrorAt) > strtotime((string)$lastBackupAt));
$runtimeChecks = [
    [
        'label' => 'Backup script',
        'ok' => is_file($backupScript),
        'detail' => str_replace('\\', '/', ltrim(str_replace($ROOT, '', $backupScript), '/\\')),
    ],
    [
        'label' => 'Restore script',
        'ok' => is_file($restoreScript),
        'detail' => str_replace('\\', '/', ltrim(str_replace($ROOT, '', $restoreScript), '/\\')),
    ],
    [
        'label' => 'Backups directory writable',
        'ok' => $backupsDirReady && is_writable($backupsDir),
        'detail' => str_replace('\\', '/', ltrim(str_replace($ROOT, '', $backupsDir), '/\\')),
    ],
    [
        'label' => 'Logs directory writable',
        'ok' => $logsDirReady && is_writable($logsDir),
        'detail' => str_replace('\\', '/', ltrim(str_replace($ROOT, '', $logsDir), '/\\')),
    ],
    [
        'label' => 'Free disk space',
        'ok' => $freeBytes === false || $freeBytes >= $needBytes,
        'detail' => $freeBytes === false ? 'Could not be measured' : number_format($freeBytes / 1073741824, 1) . ' GB free',
    ],
];
$localRunCmd = PHP_BINARY . ' ' . $backupScript;
$localRunQuietCmd = $localRunCmd . ' --quiet';
$localListCmd = PHP_BINARY . ' ' . $restoreScript . ' --list';

$current_page = 'backup-management.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Management — <?php echo htmlspecialchars(getSetting('site_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/backup-management.css?v=<?php echo @filemtime(__DIR__ . '/css/backup-management.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content">
        <div class="content-header">
            <h2><i class="fas fa-database" style="color:var(--gold)"></i> Database Backup Management</h2>
            <p style="color:#6b7280;margin:4px 0 0;">Run backups, download files, restore from a previous snapshot, monitor backup freshness, and execute local server backups manually.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success" style="background:#d1fae5;border-left:4px solid #059669;padding:12px 16px;border-radius:6px;margin-bottom:20px;color:#065f46;">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error" style="background:#fee2e2;border-left:4px solid #dc2626;padding:12px 16px;border-radius:6px;margin-bottom:20px;color:#991b1b;">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Status bar -->
        <div class="bm-card" style="margin-top:0">
            <div class="ck-kpis ck-kpis--4">
                <div class="ck-kpi">
                    <span class="ck-kpi__label">Backup files on disk</span>
                    <span class="ck-kpi__value"><?php echo count($backups); ?></span>
                </div>
                <?php if ($lastBackupAt): ?>
                    <div class="ck-kpi ck-kpi--good">
                        <span class="ck-kpi__label">Last successful backup</span>
                        <span class="ck-kpi__value"><?php echo htmlspecialchars($lastBackupAt); ?></span>
                    </div>
                <?php else: ?>
                    <div class="ck-kpi ck-kpi--alert">
                        <span class="ck-kpi__label">No backup recorded yet</span>
                        <span class="ck-kpi__value">None</span>
                    </div>
                <?php endif; ?>
                <div class="ck-kpi">
                    <span class="ck-kpi__label">Last backup size</span>
                    <span class="ck-kpi__value"><?php echo $lastBackupSize ? number_format(round($lastBackupSize / 1024)) . ' <small>KB</small>' : '—'; ?></span>
                </div>
                <div class="ck-kpi <?php echo ($backupAgeHours === null || $backupAgeHours > 36) ? 'ck-kpi--alert' : ($backupAgeHours <= 25 ? 'ck-kpi--good' : 'ck-kpi--warn'); ?>">
                    <span class="ck-kpi__label">Backup health</span>
                    <span class="ck-kpi__value"><?php echo $backupAgeHours === null ? 'None' : ($backupAgeHours <= 25 ? 'Fresh' : ($backupAgeHours <= 36 ? 'Ageing' : 'Stale')); ?></span>
                    <span class="ck-kpi__sub"><?php echo $backupAgeHours === null ? 'No backups' : (int)$backupAgeHours . 'h ago'; ?></span>
                </div>
            </div>

            <p class="bm-offsite">
                <i class="fas fa-cloud-download-alt"></i>
                Last copy saved off-site:
                <strong><?php echo $lastOffsite !== '' ? htmlspecialchars($lastOffsite) : 'Never'; ?></strong>
                <span class="bm-offsite__hint">(a backup is only safe from a server failure once you have downloaded a copy and kept it somewhere else)</span>
            </p>

            <?php if ($isStale): ?>
                <div class="bm-notice bm-notice--warn" role="status">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <?php if ($backupAgeHours === null): ?>
                            <strong>Your hotel data has no backup recorded yet.</strong>
                        <?php else: ?>
                            <strong>Your hotel data has not been backed up for <?php echo (int)$backupAgeHours; ?> hours.</strong>
                        <?php endif; ?>
                        If the system failed now, recent bookings could be lost. Press Run Backup Now.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($showLastError): ?>
                <div class="bm-notice bm-notice--error" role="alert">
                    <i class="fas fa-times-circle"></i>
                    <div>
                        The last backup attempt failed on <?php echo htmlspecialchars($lastErrorAt); ?>.
                        <?php echo rh_bm_problem(rh_db_backup_code_from_error($lastError), $lastError); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Run backup button -->
            <form method="POST">
                <input type="hidden" name="_tok" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="run_backup">
                <button type="submit" class="btn-run" id="runBtn">
                    <i class="fas fa-play-circle"></i> Run Backup Now
                </button>
                <span style="font-size:.8rem;color:#6b7280;margin-left:12px">Takes 5–30 seconds depending on database size. Keep this page open until it finishes.</span>
            </form>
        </div>

        <div class="bm-grid">
            <!-- Backup list -->
            <div class="bm-card" style="grid-column: 1 / -1">
                <h3><i class="fas fa-history"></i> Available Backups (<?php echo count($backups); ?>)</h3>
                <?php if (!$backups): ?>
                    <p style="color:#9ca3af">No backup files found. Run your first backup using the button above.</p>
                <?php else: ?>
                    <div style="overflow-x:auto">
                        <table class="backup-table" id="backupTable">
                            <thead>
                                <tr>
                                    <?php if ($user['role'] === 'admin'): ?>
                                        <th class="admin-bulk-col"><?php echo adminBulkAllBox('Select all backups'); ?></th>
                                    <?php endif; ?>
                                    <th>Filename</th>
                                    <th>Date</th>
                                    <th>Size</th>
                                    <th class="bm-actions-head">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $i => $b): ?>
                                    <tr>
                                        <?php if ($user['role'] === 'admin'): ?>
                                            <td class="admin-bulk-col">
                                                <?php if ($i === 0 || !$canDelete): ?>
                                                    <input type="checkbox" class="admin-bulk-check" disabled aria-label="<?php echo $i === 0 ? 'The newest backup is always kept' : 'At least 3 backups are always kept'; ?>" title="<?php echo $i === 0 ? 'The newest backup is always kept' : 'At least 3 backups are always kept'; ?>">
                                                <?php else: ?>
                                                    <?php echo adminBulkRowBox($b['id'], 'Select backup ' . $b['date']); ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <i class="fas fa-file-archive" style="color:#6b7280;margin-right:6px"></i>
                                            <?php echo htmlspecialchars($b['name']); ?>
                                            <?php if ($i === 0): ?><span class="badge-latest">Latest</span><?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($b['date']); ?></td>
                                        <td><?php echo number_format(round($b['size'] / 1024)); ?> KB</td>
                                        <td class="bm-actions-cell">
                                            <?php if ($user['role'] === 'admin'): ?>
                                              <div class="bm-actions">
                                                <a href="?download=<?php echo urlencode($b['rel']); ?>" class="btn-dl" title="Download backup">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                                <button type="button" class="btn-restore"
                                                    onclick="showRestoreConfirm(<?php echo htmlspecialchars(json_encode($b['rel'])); ?>, <?php echo htmlspecialchars(json_encode($b['date'])); ?>)">
                                                    <i class="fas fa-undo"></i> Restore
                                                </button>
                                                <?php if ($i === 0): ?>
                                                    <span class="bm-kept" title="The newest backup is always kept so you are never left without one">Always kept</span>
                                                <?php elseif (!$canDelete): ?>
                                                    <span class="bm-kept" title="At least 3 backups are always kept">Kept (minimum 3)</span>
                                                <?php else: ?>
                                                    <form method="POST" class="bm-inline-form">
                                                        <input type="hidden" name="_tok" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                        <input type="hidden" name="action" value="delete_backups">
                                                        <input type="hidden" name="backup_id" value="<?php echo htmlspecialchars($b['id']); ?>">
                                                        <button type="submit" class="btn-delete-backup" title="Delete this backup file"
                                                            data-admin-confirm="Delete the backup from <?php echo htmlspecialchars($b['date']); ?>? The file is removed from the server and cannot be brought back. Your other backups are not affected."
                                                            data-admin-confirm-title="Delete backup"
                                                            data-admin-confirm-ok="Delete backup"
                                                            data-admin-confirm-tone="danger">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                              </div>
                                            <?php else: ?>
                                                <span style="color:#9ca3af;font-size:.8rem">Admin only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($user['role'] === 'admin'): ?>
                        <p class="bm-disk-note">
                            <i class="fas fa-hdd"></i> Backups use <?php echo number_format($backupsTotalSize / 1048576, 1); ?> MB on the server.
                            Older backups are removed automatically (14 daily, 8 weekly, 12 monthly); delete extra ones here if space runs low.
                            The newest backup is always kept, and at least 3 backups always stay on the server.
                        </p>
                        <?php
                        adminBulkBarStart('delete_backups', '#backupTable', $csrf_token);
                        adminBulkButton('delete', 'Delete selected', 'fa-trash',
                            'Delete {n} backup file(s)? They are removed from the server and cannot be brought back. The newest backup is always kept, and at least 3 backups always stay.',
                            'btn-danger', '', 'Delete backups', 'danger');
                        adminBulkBarEnd();
                        ?>
                    <?php endif; ?>

                    <?php if ($user['role'] === 'admin'): ?>
                        <!-- Restore: the shared confirm asks for the word RESTORE, then this form is sent -->
                        <form method="POST" id="restoreForm" hidden>
                            <input type="hidden" name="_tok" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="backup_file" id="restoreFileInput">
                            <input type="hidden" name="confirm_text" id="restoreConfirmText">
                        </form>
                        <p class="bm-notice bm-notice--info" id="restoreNote" role="status" hidden></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Backup log -->
            <div class="bm-card">
                <h3><i class="fas fa-terminal"></i> Backup Log (last 30 entries)</h3>
                <?php if ($logLines): ?>
                    <div class="log-box">
                        <?php foreach ($logLines as $line): ?>
                            <p><?php echo htmlspecialchars($line); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:#9ca3af">No log entries yet.</p>
                <?php endif; ?>
            </div>

            <div class="bm-card">
                <h3><i class="fas fa-server"></i> Server Runtime Checks</h3>
                <ul class="runtime-checks">
                    <?php foreach ($runtimeChecks as $check): ?>
                        <li>
                            <span class="check-badge <?php echo $check['ok'] ? 'check-ok' : 'check-bad'; ?>">
                                <i class="fas <?php echo $check['ok'] ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                                <?php echo $check['ok'] ? 'OK' : 'Issue'; ?>
                            </span>
                            <div class="runtime-check-text">
                                <strong><?php echo htmlspecialchars($check['label']); ?></strong>
                                <small><?php echo htmlspecialchars((string)$check['detail']); ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="bm-card" style="grid-column: 1 / -1;">
                <h3><i class="fas fa-terminal"></i> Local Server Backup Commands (Manual)</h3>
                <p style="font-size:.875rem;color:#374151;margin-top:0;">Use these commands from server terminal/SSH when you want an immediate local backup outside cron schedules:</p>
                <div class="command-blocks">
                    <div>
                        <p class="cmd-label">Run backup now</p>
                        <div class="cmd-box"><?php echo htmlspecialchars($localRunCmd); ?></div>
                    </div>
                    <div>
                        <p class="cmd-label">Run quiet (cron-style)</p>
                        <div class="cmd-box"><?php echo htmlspecialchars($localRunQuietCmd); ?></div>
                    </div>
                    <div>
                        <p class="cmd-label">List available backups</p>
                        <div class="cmd-box"><?php echo htmlspecialchars($localListCmd); ?></div>
                    </div>
                </div>
            </div>

            <!-- How-to / cron info -->
            <div class="bm-card">
                <h3><i class="fas fa-info-circle"></i> Cron Setup &amp; Notes</h3>
                <p style="font-size:.875rem;color:#374151;margin-top:0">Add this line to <strong>cPanel → Cron Jobs</strong> for automatic nightly backups:</p>
                <div style="background:#f3f4f6;border-radius:6px;padding:10px 14px;font-family:monospace;font-size:.8rem;color:#1f2937;margin-bottom:16px;word-break:break-all">
                    0 2 * * * <?php echo PHP_BINARY; ?> <?php echo htmlspecialchars($backupScript); ?> --quiet
                </div>
                <ul style="font-size:.85rem;color:#374151;padding-left:20px;line-height:2">
                    <li>Keeps <strong>14 daily</strong>, <strong>8 weekly</strong>, <strong>12 monthly</strong> backups — older files are pruned automatically.</li>
                    <li>Uses <code>mysqldump</code> when available; falls back to a pure-PHP dumper.</li>
                    <li>Verifies the gzip file's integrity before finalising.</li>
                    <li>Backup files are stored in <code>backups/YYYY/MM/</code> and blocked from HTTP access.</li>
                    <li>Download any backup with the <strong>Download</strong> button — save off-site weekly. The administrator is reminded by email if a week passes without one.</li>
                    <li>If a backup fails, or none is saved for 36 hours, the administrator is emailed (at most once a day).</li>
                    <li>A restore always saves a fresh safety backup first, and needs the word RESTORE typed to confirm.</li>
                    <li>Only <strong>Administrator</strong> accounts can restore or download backups.</li>
                </ul>
            </div>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>

    <script>
        // Busy state after the submit has started (disabling the button inside its own click
        // handler can cancel the submit).
        document.getElementById('runBtn')?.form?.addEventListener('submit', function () {
            const btn = document.getElementById('runBtn');
            window.setTimeout(function () {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running backup…';
            }, 0);
        });

        // The shared confirm asks for the word RESTORE; the server checks it again.
        function showRestoreConfirm(filePath, dateStr) {
            const form = document.getElementById('restoreForm');
            const note = document.getElementById('restoreNote');
            if (!form) return;
            const warn = 'This replaces ALL current hotel data (bookings, payments, guests, stock and settings) with the backup from ' +
                dateStr + '. Anything entered since then will be lost. A safety backup of your current data is saved first, so this can be undone.';
            const details = [
                'Backup chosen: ' + dateStr,
                'It can take a minute. Keep this page open until it finishes.'
            ];
            const ask = (window.AdminConfirm && window.AdminConfirm.prompt) ?
                window.AdminConfirm.prompt({
                    title: 'Restore the database',
                    message: warn,
                    details: details,
                    confirmText: 'Restore now',
                    tone: 'danger',
                    icon: 'fa-undo',
                    inputLabel: 'Type RESTORE to confirm',
                    inputPlaceholder: 'RESTORE'
                }) :
                Promise.resolve(window.prompt(warn + '\n\nType RESTORE to confirm:'));
            ask.then(function (value) {
                if (value === null || value === undefined) return; // cancelled
                if (String(value).trim() !== 'RESTORE') {
                    if (note) {
                        note.textContent = 'Nothing was restored: the word RESTORE was not typed exactly.';
                        note.hidden = false;
                    }
                    return;
                }
                if (note) note.hidden = true;
                document.getElementById('restoreFileInput').value = filePath;
                document.getElementById('restoreConfirmText').value = 'RESTORE';
                if (form.requestSubmit) form.requestSubmit();
                else form.submit();
            });
        }
    </script>
</body>

</html>

