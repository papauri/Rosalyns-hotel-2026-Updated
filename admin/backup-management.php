<?php

/**
 * Admin — Database Backup Management
 * Run backups, list available backups, download, and restore from browser.
 * Restore is restricted to admin role only.
 */

require_once 'admin-init.php';
// Bootstrap fallback guards (admin-init.php sets these; guards satisfy static analysis)
$user       = $user       ?? ['id' => 0, 'username' => '', 'role' => 'guest', 'full_name' => ''];
$csrf_token = $csrf_token ?? generateCsrfToken();
$site_name  = $site_name  ?? getSetting('site_name', 'Hotel');

// Only admin + manager may access this page
if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    $_SESSION['alert'] = ['type' => 'error', 'message' => 'Access denied.'];
    header('Location: dashboard.php');
    exit;
}

$ROOT         = dirname(__DIR__);
$backupsDir   = $ROOT . '/backups';
$logFile      = $ROOT . '/logs/backup.log';
$backupScript = $ROOT . '/scripts/backup_database.php';
$restoreScript = $ROOT . '/scripts/restore_database.php';

/**
 * Return true when a function exists and is not disabled in php.ini.
 */
function rh_function_enabled(string $name): bool
{
    if (!function_exists($name)) {
        return false;
    }
    $disabledRaw = (string)ini_get('disable_functions');
    if ($disabledRaw === '') {
        return true;
    }
    $disabled = array_map('trim', explode(',', $disabledRaw));
    return !in_array($name, $disabled, true);
}

/**
 * Build a CLI PHP command for a script plus optional args.
 */
function rh_build_php_command(string $scriptPath, array $args = []): string
{
    $parts = [escapeshellarg(PHP_BINARY), escapeshellarg($scriptPath)];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg((string)$arg);
    }
    return implode(' ', $parts);
}

/**
 * Execute a command using the best available launcher.
 * Returns: ok(bool), exit(int), stdout(string), stderr(string), launcher(string)
 */
function rh_run_command(string $command, string $cwd): array
{
    if (rh_function_enabled('proc_open')) {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($command, $desc, $pipes, $cwd);
        if (is_resource($proc)) {
            $stdout = (string)stream_get_contents($pipes[1]);
            $stderr = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = (int)proc_close($proc);
            return [
                'ok' => $exit === 0,
                'exit' => $exit,
                'stdout' => trim($stdout),
                'stderr' => trim($stderr),
                'launcher' => 'proc_open',
            ];
        }
    }

    if (rh_function_enabled('exec')) {
        $output = [];
        $exit = 1;
        @exec($command . ' 2>&1', $output, $exit);
        $text = trim(implode("\n", $output));
        return [
            'ok' => $exit === 0,
            'exit' => $exit,
            'stdout' => $text,
            'stderr' => $exit === 0 ? '' : $text,
            'launcher' => 'exec',
        ];
    }

    if (rh_function_enabled('shell_exec')) {
        $marker = '__RH_CMD_STATUS_' . str_replace('.', '', uniqid('', true)) . '__';
        $wrapped = '(' . $command . ') && echo ' . $marker . 'OK || echo ' . $marker . 'FAIL';
        $output = (string)@shell_exec($wrapped . ' 2>&1');
        $ok = strpos($output, $marker . 'OK') !== false;
        $clean = str_replace([$marker . 'OK', $marker . 'FAIL'], '', $output);
        return [
            'ok' => $ok,
            'exit' => $ok ? 0 : 1,
            'stdout' => trim($clean),
            'stderr' => $ok ? '' : trim($clean),
            'launcher' => 'shell_exec',
        ];
    }

    return [
        'ok' => false,
        'exit' => 127,
        'stdout' => '',
        'stderr' => 'No command launcher available (proc_open/exec/shell_exec are disabled).',
        'launcher' => 'none',
    ];
}

/**
 * Keep command output short and readable in alerts.
 */
function rh_output_summary(string $text, int $maxLen = 320): string
{
    $single = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($single === '') {
        return '';
    }
    if (strlen($single) <= $maxLen) {
        return $single;
    }
    return substr($single, 0, $maxLen - 3) . '...';
}

$message = '';
$error   = '';

// ── POST actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF: all POST actions require the standard admin CSRF token
    if (!validateCsrfToken($_POST['_tok'] ?? '')) {
        $error = 'Invalid form token. Please refresh and try again.';
    } else {

        if ($action === 'run_backup') {
            if (!is_file($backupScript)) {
                $error = 'Backup script is missing on server.';
            } else {
                $cmd = rh_build_php_command($backupScript);
                $result = rh_run_command($cmd, $ROOT);
                $out = rh_output_summary($result['stdout']);
                $err = rh_output_summary($result['stderr']);

                if ($result['ok']) {
                    $suffix = '';
                    if (preg_match('/Backup OK:[^\r\n]*/i', (string)$result['stdout'], $m)) {
                        $suffix = ' ' . htmlspecialchars(trim((string)$m[0]));
                    }
                    $message = 'Backup completed via ' . htmlspecialchars($result['launcher']) . '.' . $suffix;
                } else {
                    $detail = $err !== '' ? $err : $out;
                    $error = 'Backup failed (exit ' . (int)$result['exit'] . ', via ' . htmlspecialchars($result['launcher']) . '): '
                        . htmlspecialchars($detail !== '' ? $detail : 'No output returned.');
                }
            }
        } elseif ($action === 'restore' && $user['role'] === 'admin') {
            // Restore is admin-only
            $relPath = $_POST['backup_file'] ?? '';
            // Sanitise: must be within backups/ and match expected name
            if (!preg_match('#^backups[/\\\\]\d{4}[/\\\\]\d{2}[/\\\\]db-\d{8}-\d{6}\.sql\.gz$#', $relPath)) {
                $error = 'Invalid backup file path.';
            } else {
                $absPath = $ROOT . '/' . $relPath;
                if (!is_file($absPath)) {
                    $error = 'Backup file not found on disk.';
                } else {
                    if (!is_file($restoreScript)) {
                        $error = 'Restore script is missing on server.';
                    } else {
                        // Restore script requires explicit --confirm flag.
                        $cmd = rh_build_php_command($restoreScript, ['--file=' . $relPath, '--confirm']);
                        $result = rh_run_command($cmd, $ROOT);
                        $out = rh_output_summary($result['stdout']);
                        $err = rh_output_summary($result['stderr']);

                        if ($result['ok']) {
                            $suffix = '';
                            if (preg_match('/Restore OK:[^\r\n]*/i', (string)$result['stdout'], $m)) {
                                $suffix = ' ' . htmlspecialchars(trim((string)$m[0]));
                            }
                            $message = 'Restore completed successfully via ' . htmlspecialchars($result['launcher']) . '.' . $suffix;
                        } else {
                            $detail = $err !== '' ? $err : $out;
                            $error = 'Restore failed (exit ' . (int)$result['exit'] . ', via ' . htmlspecialchars($result['launcher']) . '): '
                                . htmlspecialchars($detail !== '' ? $detail : 'No output returned.');
                        }
                    }
                }
            }
        } elseif ($action === 'restore' && $user['role'] !== 'admin') {
            $error = 'Only administrators can perform a restore.';
        }
    }
}

// ── Download action (GET) ────────────────────────────────────────────────────
if (isset($_GET['download']) && $user['role'] === 'admin') {
    $relPath = $_GET['download'];
    if (preg_match('#^backups[/\\\\]\d{4}[/\\\\]\d{2}[/\\\\]db-\d{8}-\d{6}\.sql\.gz$#', $relPath)) {
        $abs = $ROOT . '/' . $relPath;
        if (is_file($abs)) {
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
$backups = [];
if (is_dir($backupsDir)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupsDir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->isFile() && preg_match('/^db-(\d{8})-(\d{6})\.sql\.gz$/', $f->getFilename(), $m)) {
            $rel = ltrim(str_replace($ROOT, '', $f->getPathname()), '/\\');
            $rel = str_replace('\\', '/', $rel);
            $backups[] = [
                'rel'   => $rel,
                'name'  => $f->getFilename(),
                'size'  => $f->getSize(),
                'mtime' => $f->getMTime(),
                'date'  => substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2)
                    . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2),
            ];
        }
    }
    usort($backups, fn($a, $b) => $b['mtime'] - $a['mtime']);
}

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
$launchers = [];
if (rh_function_enabled('proc_open')) $launchers[] = 'proc_open';
if (rh_function_enabled('exec')) $launchers[] = 'exec';
if (rh_function_enabled('shell_exec')) $launchers[] = 'shell_exec';
$launcherText = $launchers ? implode(', ', $launchers) : 'none';
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
        'label' => 'Process launcher availability',
        'ok' => !empty($launchers),
        'detail' => $launcherText,
    ],
    [
        'label' => 'PHP CLI binary',
        'ok' => is_file(PHP_BINARY) || stripos(PHP_BINARY, 'php') !== false,
        'detail' => PHP_BINARY,
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
            <div class="stats-row">
                <div class="stat-box">
                    <div class="big-stat"><?php echo count($backups); ?></div>
                    <div class="stat-label">Backup files on disk</div>
                </div>
                <div class="stat-box">
                    <?php if ($lastBackupAt): ?>
                        <div class="big-stat" style="font-size:1.2rem"><?php echo htmlspecialchars($lastBackupAt); ?></div>
                        <div class="stat-label">Last successful backup</div>
                    <?php else: ?>
                        <div class="big-stat" style="color:#dc2626">None</div>
                        <div class="stat-label">No backup recorded yet</div>
                    <?php endif; ?>
                </div>
                <div class="stat-box">
                    <div class="big-stat"><?php echo $lastBackupSize ? number_format(round($lastBackupSize / 1024)) . ' KB' : '—'; ?></div>
                    <div class="stat-label">Last backup size</div>
                </div>
                <div class="stat-box" style="display:flex;flex-direction:column;align-items:center;justify-content:center">
                    <?php if ($backupAgeHours === null): ?>
                        <span class="status-pill stale"><i class="fas fa-times-circle"></i> No backups</span>
                    <?php elseif ($backupAgeHours <= 25): ?>
                        <span class="status-pill ok"><i class="fas fa-check-circle"></i> Fresh (<?php echo $backupAgeHours; ?>h ago)</span>
                    <?php elseif ($backupAgeHours <= 36): ?>
                        <span class="status-pill warn"><i class="fas fa-exclamation-circle"></i> Getting old (<?php echo $backupAgeHours; ?>h ago)</span>
                    <?php else: ?>
                        <span class="status-pill stale"><i class="fas fa-exclamation-triangle"></i> Stale — <?php echo $backupAgeHours; ?>h ago!</span>
                    <?php endif; ?>
                    <div class="stat-label" style="margin-top:6px">Backup health</div>
                </div>
            </div>

            <!-- Run backup button -->
            <form method="POST">
                <input type="hidden" name="_tok" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="run_backup">
                <button type="submit" class="btn-run" id="runBtn">
                    <i class="fas fa-play-circle"></i> Run Backup Now
                </button>
                <span style="font-size:.8rem;color:#6b7280;margin-left:12px">Takes 5–30 seconds depending on DB size. The page will reload when done.</span>
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
                        <table class="backup-table">
                            <thead>
                                <tr>
                                    <th>Filename</th>
                                    <th>Date</th>
                                    <th>Size</th>
                                    <th style="text-align:right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $i => $b): ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-file-archive" style="color:#6b7280;margin-right:6px"></i>
                                            <?php echo htmlspecialchars($b['name']); ?>
                                            <?php if ($i === 0): ?><span class="badge-latest">Latest</span><?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($b['date']); ?></td>
                                        <td><?php echo number_format(round($b['size'] / 1024)); ?> KB</td>
                                        <td style="text-align:right;white-space:nowrap">
                                            <?php if ($user['role'] === 'admin'): ?>
                                                <a href="?download=<?php echo urlencode($b['rel']); ?>" class="btn-dl" title="Download backup">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                                &nbsp;
                                                <button type="button" class="btn-restore"
                                                    onclick="showRestoreConfirm(<?php echo htmlspecialchars(json_encode($b['rel'])); ?>, <?php echo htmlspecialchars(json_encode($b['date'])); ?>)">
                                                    <i class="fas fa-undo"></i> Restore
                                                </button>
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
                        <!-- Restore confirmation panel (hidden, shown by JS) -->
                        <div class="confirm-restore" id="restoreConfirm">
                            <p><i class="fas fa-exclamation-triangle"></i> This will OVERWRITE the live database with the selected backup. This cannot be undone.</p>
                            <span class="file-path" id="restoreFilePath"></span>
                            <form method="POST" id="restoreForm">
                                <input type="hidden" name="_tok" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="backup_file" id="restoreFileInput">
                                <button type="button" class="btn-cancel" onclick="hideRestoreConfirm()">Cancel</button>
                                <button type="submit" class="btn-restore">
                                    <i class="fas fa-undo"></i> Yes, restore this backup
                                </button>
                            </form>
                        </div>
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
                    <li>Download any backup with the <strong>Download</strong> button — save off-site weekly.</li>
                    <li>Only <strong>Administrator</strong> accounts can restore or download backups.</li>
                </ul>
            </div>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>

    <script>
        document.getElementById('runBtn')?.addEventListener('click', function() {
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running backup…';
        });

        function showRestoreConfirm(filePath, dateStr) {
            document.getElementById('restoreFilePath').textContent = filePath + '  (' + dateStr + ')';
            document.getElementById('restoreFileInput').value = filePath;
            const el = document.getElementById('restoreConfirm');
            el.style.display = 'block';
            el.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        function hideRestoreConfirm() {
            document.getElementById('restoreConfirm').style.display = 'none';
        }
    </script>
</body>

</html>

