<?php

/**
 * Automated Emails — web-triggered scheduler control panel (replaces cron).
 * Gated by the existing 'booking_settings' permission (admin/includes/permissions.php).
 *
 * Settings, per-job switches, "Run now", "Send test to me" and the activity log.
 * Engine: includes/auto-scheduler.php · jobs: includes/auto-email-jobs.php.
 */
require_once __DIR__ . '/admin-init.php';
require_once __DIR__ . '/../includes/auto-email-jobs.php';
/** @var array $user */
/** @var string $csrf_token */

$message = '';
$messageType = 'success';

$tableReady = rh_auto_log_table_ready($pdo);
$jobs = rh_scheduler_jobs();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Security token invalid. Refresh the page and try again.';
        $messageType = 'error';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save') {
            $stages = [];
            foreach (preg_split('/[\s,;]+/', (string)($_POST['automated_email_reminder_stages'] ?? '')) as $p) {
                $n = (int)$p;
                if ($n >= 1 && $n <= 365) {
                    $stages[$n] = $n;
                }
            }
            sort($stages);
            $stages = array_slice(array_values($stages), 0, 3);
            if (!$stages) {
                $stages = [1, 3, 7];
            }
            $testRecipient = trim((string)($_POST['automated_email_test_recipient'] ?? ''));
            if ($testRecipient !== '' && !filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
                $testRecipient = '';
                $message = 'The test recipient was not a valid email address and was cleared. ';
            }
            $flag = static function (string $k): string {
                return isset($_POST[$k]) ? '1' : '0';
            };
            updateSetting('automated_email_master', $flag('automated_email_master'));
            updateSetting('automated_email_reminder_stages', implode(',', $stages));
            updateSetting('automated_email_payment_terms_days', (string)max(0, min(120, (int)($_POST['automated_email_payment_terms_days'] ?? 7))));
            updateSetting('automated_email_lookback_days', (string)max(1, min(365, (int)($_POST['automated_email_lookback_days'] ?? 30))));
            updateSetting('automated_email_quotation_days', (string)max(1, min(30, (int)($_POST['automated_email_quotation_days'] ?? 2))));
            updateSetting('automated_email_interval_minutes', (string)max(5, min(1440, (int)($_POST['automated_email_interval_minutes'] ?? 15))));
            updateSetting('automated_email_bcc_hotel', $flag('automated_email_bcc_hotel'));
            updateSetting('automated_email_test_recipient', $testRecipient);
            foreach ($jobs as $jobName => $job) {
                updateSetting($job['toggle'], $flag('job_' . $jobName));
            }
            $message .= 'Automated email settings saved.';
        } elseif ($action === 'run_now') {
            $r = rh_scheduler_execute(true, ['time_budget' => 20, 'email_budget' => 25], false);
            if (($r['status'] ?? '') === 'ran') {
                $parts = [];
                foreach ($r['jobs'] as $jn => $jr) {
                    $parts[] = ($jobs[$jn]['label'] ?? $jn) . ': ' . ($jr['status'] === 'ran' ? 'sent ' . (int)($jr['sent'] ?? 0) . ', checked ' . (int)($jr['checked'] ?? 0) : $jr['status']);
                }
                $message = 'Run complete. ' . implode(' · ', $parts);
            } else {
                $message = 'Nothing ran (' . ($r['reason'] ?? $r['status'] ?? 'unknown') . ').';
                $messageType = 'error';
            }
        } elseif ($action === 'send_test') {
            $key = (string)($_POST['template_key'] ?? '');
            $st = $pdo->prepare('SELECT email FROM admin_users WHERE id = ?');
            $st->execute([(int)$user['id']]);
            $to = trim((string)$st->fetchColumn());
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $message = 'Your admin account has no valid email address to send the test to.';
                $messageType = 'error';
            } else {
                $r = rh_auto_send_sample($key, $to);
                if (!empty($r['success'])) {
                    $message = 'Test email (sample data) sent to ' . $to . '.';
                } else {
                    $message = 'Test email failed: ' . ($r['message'] ?? 'unknown error');
                    $messageType = 'error';
                }
            }
        }
    }
}

$cfg = [];
foreach (array_keys(rh_auto_defaults()) as $k) {
    $cfg[$k] = rh_auto_setting($k);
}
$lastRun = (int)$cfg['scheduler_last_run'];
$lastResult = json_decode($cfg['scheduler_last_result'], true);

$logRows = [];
if ($tableReady) {
    $logRows = $pdo->query('SELECT job, account_type, account_id, stage, recipient, subject, status, attempts, error, sent_at FROM automated_email_log ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
}

$esc = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$editableTemplates = ['payment_reminder_1', 'payment_reminder_2', 'payment_reminder_3', 'quotation_expiry_reminder', 'tentative_booking_reminder', 'tentative_booking_expired'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automated Emails - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-booking-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-booking-settings.css'); ?>">
    <style>
        .ae-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px 18px; }
        .ae-check { display: flex; align-items: center; gap: 10px; min-height: 44px; }
        .ae-check input { width: 20px; height: 20px; }
        .ae-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .ae-table th, .ae-table td { text-align: left; padding: 7px 8px; border-bottom: 1px solid #EAE1D8; vertical-align: top; }
        .ae-badge { display: inline-block; padding: 2px 9px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
        .ae-sent { background: #d4edda; color: #155724; }
        .ae-failed { background: #f8d7da; color: #721c24; }
        .ae-sending, .ae-skipped { background: #e9ecef; color: #495057; }
        .ae-warn { background: #fff3cd; color: #856404; border: 1px solid #ffe08a; border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; }
        .ae-scroll { overflow-x: auto; }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-paper-plane"></i> Automated Emails</h1>
            <p>Reminders go out by themselves while people use the website or admin panel &mdash; no cron needed.</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?>" style="margin-bottom:12px;">
                <?php echo $esc($message); ?>
            </div>
        <?php endif; ?>

        <?php if (!$tableReady): ?>
            <div class="ae-warn"><strong>Setup pending:</strong> the <code>automated_email_log</code> table does not exist yet. Run <code>php admin/migrations/migrate.php --run</code>. Nothing is sent until then.</div>
        <?php endif; ?>
        <?php if (trim($cfg['automated_email_test_recipient']) !== ''): ?>
            <div class="ae-warn"><strong>TEST MODE:</strong> every automated email is being redirected to <?php echo $esc($cfg['automated_email_test_recipient']); ?> and real guests are NOT being contacted. Clear the test recipient below to go live.</div>
        <?php endif; ?>

        <div class="settings-card">
            <h2>Status</h2>
            <p style="margin:0 0 6px;">
                Master switch: <strong><?php echo $cfg['automated_email_master'] === '1' ? 'ON' : 'OFF'; ?></strong>
                &nbsp;·&nbsp; Last run: <strong><?php echo $lastRun > 0 ? $esc(date('Y-m-d H:i:s', $lastRun)) : 'never'; ?></strong>
                &nbsp;·&nbsp; Runs at most every <strong><?php echo (int)$cfg['automated_email_interval_minutes']; ?></strong> min, when someone loads a page.
            </p>
            <?php if (is_array($lastResult) && !empty($lastResult['jobs'])): ?>
                <p style="margin:0;font-size:0.85rem;color:#6d6455;">
                    <?php foreach ($lastResult['jobs'] as $jn => $jr): ?>
                        <?php echo $esc($jobs[$jn]['label'] ?? $jn); ?>:
                        <?php echo $esc(($jr['status'] ?? '') === 'ran' ? 'sent ' . (int)($jr['sent'] ?? 0) . ' of ' . (int)($jr['checked'] ?? 0) . ' due' : ($jr['status'] ?? '')); ?>
                        &nbsp;·&nbsp;
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <form method="POST" style="margin-top:10px;">
                <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf_token); ?>">
                <input type="hidden" name="action" value="run_now">
                <button type="submit" class="btn-submit"><i class="fas fa-play"></i> Run now</button>
                <span class="help-text" style="margin-left:8px;">Runs every enabled job immediately (still safe: each email is sent once only).</span>
            </form>
        </div>

        <form method="POST" class="settings-card">
            <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf_token); ?>">
            <input type="hidden" name="action" value="save">
            <h2>Settings</h2>

            <div class="form-group">
                <label class="ae-check"><input type="checkbox" name="automated_email_master" value="1" <?php echo $cfg['automated_email_master'] === '1' ? 'checked' : ''; ?>>
                    <span><strong>Master switch</strong> &mdash; turn ALL automated jobs (emails and the nightly backup) on/off</span></label>
            </div>

            <h3>Jobs</h3>
            <div class="ae-grid">
                <?php foreach ($jobs as $jobName => $job): ?>
                    <label class="ae-check"><input type="checkbox" name="job_<?php echo $esc($jobName); ?>" value="1" <?php echo rh_auto_job_enabled($jobName) ? 'checked' : ''; ?>>
                        <span><?php echo $esc($job['label']); ?></span></label>
                <?php endforeach; ?>
            </div>
            <p class="help-text">Tentative holds use the reminder hours from Hotel Settings. Pre-arrival, post-stay and gym renewal share the switches (and day settings) they always had in Hotel Settings / Gym Members.</p>

            <h3>Overdue payment reminders</h3>
            <div class="ae-grid">
                <div class="form-group"><label for="stages">Reminder stages (days overdue, up to 3)</label>
                    <input type="text" id="stages" name="automated_email_reminder_stages" class="form-control" value="<?php echo $esc($cfg['automated_email_reminder_stages']); ?>" placeholder="1,3,7"></div>
                <div class="form-group"><label for="terms">Payment terms for events / gym / conference (days)</label>
                    <input type="number" id="terms" name="automated_email_payment_terms_days" class="form-control" min="0" max="120" value="<?php echo (int)$cfg['automated_email_payment_terms_days']; ?>"></div>
                <div class="form-group"><label for="lookback">Stop chasing after (days overdue)</label>
                    <input type="number" id="lookback" name="automated_email_lookback_days" class="form-control" min="1" max="365" value="<?php echo (int)$cfg['automated_email_lookback_days']; ?>"></div>
                <div class="form-group"><label for="qdays">Quotation reminder (days before expiry)</label>
                    <input type="number" id="qdays" name="automated_email_quotation_days" class="form-control" min="1" max="30" value="<?php echo (int)$cfg['automated_email_quotation_days']; ?>"></div>
            </div>

            <h3>Delivery</h3>
            <div class="ae-grid">
                <div class="form-group"><label for="interval">Run interval (minutes)</label>
                    <input type="number" id="interval" name="automated_email_interval_minutes" class="form-control" min="5" max="1440" value="<?php echo (int)$cfg['automated_email_interval_minutes']; ?>"></div>
                <div class="form-group"><label for="testrcpt">Test recipient (leave EMPTY in production)</label>
                    <input type="email" id="testrcpt" name="automated_email_test_recipient" class="form-control" value="<?php echo $esc($cfg['automated_email_test_recipient']); ?>" placeholder="empty = send to real customers"></div>
            </div>
            <label class="ae-check"><input type="checkbox" name="automated_email_bcc_hotel" value="1" <?php echo $cfg['automated_email_bcc_hotel'] === '1' ? 'checked' : ''; ?>>
                <span>BCC the hotel (admin notification address) on payment and quotation reminders</span></label>

            <button type="submit" class="btn-submit" style="margin-top:12px;"><i class="fas fa-save"></i> Save settings</button>
        </form>

        <div class="settings-card">
            <h2>Email templates</h2>
            <div class="ae-scroll">
                <table class="ae-table">
                    <thead><tr><th>Email</th><th>Template</th><th>Test</th></tr></thead>
                    <tbody>
                        <?php foreach (rh_auto_sample_keys() as $key => $label):
                            $editable = in_array($key, $editableTemplates, true);
                            $tplCfg = $editable ? getBookingEmailTemplateConfig($key, []) : null;
                            ?>
                            <tr>
                                <td><?php echo $esc($label); ?></td>
                                <td>
                                    <?php if ($editable): ?>
                                        <?php echo (int)($tplCfg['is_active'] ?? 1) === 1 ? 'Active' : '<strong>Switched off</strong>'; ?> &middot;
                                        <a href="email-templates.php">Edit in Email Templates</a>
                                    <?php else: ?>
                                        Built-in wording
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf_token); ?>">
                                        <input type="hidden" name="action" value="send_test">
                                        <input type="hidden" name="template_key" value="<?php echo $esc($key); ?>">
                                        <button type="submit" class="btn-submit"><i class="fas fa-envelope"></i> Send test to me</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="help-text">Tests use sample data, are sent only to your own admin email address, and record nothing.</p>
        </div>

        <div class="settings-card">
            <h2>Recent activity (last 100)</h2>
            <div class="ae-scroll">
                <table class="ae-table">
                    <thead><tr><th>When</th><th>Job</th><th>Account</th><th>Stage</th><th>Recipient</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (!$logRows): ?>
                            <tr><td colspan="6">Nothing sent yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($logRows as $row): ?>
                            <tr>
                                <td><?php echo $esc($row['sent_at']); ?></td>
                                <td><?php echo $esc($jobs[$row['job']]['label'] ?? $row['job']); ?></td>
                                <td><?php echo $esc($row['account_type'] === 'run' ? '—' : $row['account_type'] . ' #' . $row['account_id']); ?></td>
                                <td><?php echo $esc($row['account_type'] === 'run' ? $row['subject'] : $row['stage']); ?></td>
                                <td><?php echo $esc($row['recipient']); ?></td>
                                <td><span class="ae-badge ae-<?php echo $esc($row['status']); ?>"><?php echo $esc($row['status']); ?></span>
                                    <?php if (!empty($row['error'])): ?><br><small><?php echo $esc($row['error']); ?></small><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
