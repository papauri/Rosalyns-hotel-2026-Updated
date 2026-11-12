<?php

/**
 * Automated Emails — control panel of the in-app scheduler (no cron or server timer at either hotel).
 * Gated by the existing 'booking_settings' permission (admin/includes/permissions.php). The timed report emails
 * (daily reports + station report emails: their job switches, "go to" list and CC copies) also need
 * 'timed_report_emails' (owner 2026-11-12); without it they show disabled and the save never writes them.
 * The save writes a text / number setting only when its field was posted.
 *
 * Settings, per-job switches, "Run now", "Send test to me" and the activity log.
 * Engine: includes/auto-scheduler.php · jobs: includes/auto-email-jobs.php.
 */
require_once __DIR__ . '/admin-init.php';
require_once __DIR__ . '/../includes/auto-email-jobs.php';
require_once __DIR__ . '/../includes/daily-reports-send.php';
/** @var array $user */
/** @var string $csrf_token */

$message = '';
$messageType = 'success';

$tableReady = rh_auto_log_table_ready($pdo);
$jobs = rh_scheduler_jobs();
// Report-email jobs whose switch, recipients and copy need the Timed report emails permission (checked server-side).
$timedReportJobs = ['daily_reports', 'station_reports'];
$canTimedReports = hasPermission((int)$user['id'], 'timed_report_emails');
$timedNote = 'Needs the Timed report emails permission';
// Company statements (owner D6 2026-11-13): its switch and copy list need 'company_statement_emails'.
$companyJobs = ['company_statements'];
$canCompanyEmails = hasPermission((int)$user['id'], 'company_statement_emails');
$companyNote = 'Needs the Company statement emails permission';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Security token invalid. Refresh the page and try again.';
        $messageType = 'error';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save') {
            // A text / number setting is written only when its field was posted, so a form opened before a field
            // existed (or a disabled field) never blanks the stored value. Checkboxes: absent = off, as before.
            $posted = static function (string $k): bool {
                return isset($_POST[$k]);
            };
            // Address list fields: valid addresses only, with the same plain messages as the daily reports copy.
            $cleanList = static function (string $k, string $what) use (&$message): string {
                $raw = trim((string)($_POST[$k] ?? ''));
                $clean = implode(', ', array_column(rh_daily_reports_parse_recipients($raw), 'email'));
                if ($raw !== '' && $clean === '') {
                    $message .= 'The ' . $what . ' had no valid email address and was cleared. ';
                } elseif ($clean !== '' && count(preg_split('/[,;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY)) !== count(explode(', ', $clean))) {
                    $message .= 'Some ' . $what . ' addresses were not valid and were left out. ';
                }
                return $clean;
            };
            if ($posted('automated_email_reminder_stages')) {
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
                updateSetting('automated_email_reminder_stages', implode(',', $stages));
            }
            if ($posted('automated_email_test_recipient')) {
                $testRecipient = trim((string)($_POST['automated_email_test_recipient'] ?? ''));
                if ($testRecipient !== '' && !filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
                    $testRecipient = '';
                    $message = 'The test recipient was not a valid email address and was cleared. ';
                }
                updateSetting('automated_email_test_recipient', $testRecipient);
            }
            $flag = static function (string $k): string {
                return isset($_POST[$k]) ? '1' : '0';
            };
            updateSetting('automated_email_master', $flag('automated_email_master'));
            if ($posted('automated_email_payment_terms_days')) {
                updateSetting('automated_email_payment_terms_days', (string)max(0, min(120, (int)($_POST['automated_email_payment_terms_days'] ?? 7))));
            }
            if ($posted('automated_email_lookback_days')) {
                updateSetting('automated_email_lookback_days', (string)max(1, min(365, (int)($_POST['automated_email_lookback_days'] ?? 30))));
            }
            if ($posted('automated_email_quotation_days')) {
                updateSetting('automated_email_quotation_days', (string)max(1, min(30, (int)($_POST['automated_email_quotation_days'] ?? 2))));
            }
            if ($posted('automated_email_interval_minutes')) {
                updateSetting('automated_email_interval_minutes', (string)max(5, min(1440, (int)($_POST['automated_email_interval_minutes'] ?? 15))));
            }
            updateSetting('automated_email_bcc_hotel', $flag('automated_email_bcc_hotel'));
            // Timed report emails (daily reports + station reports): switches, To and CC need 'timed_report_emails'.
            // Without it these keys are NOT written at all; the stored values stay as they are.
            if ($canTimedReports) {
                if ($posted('automated_job_daily_reports_cc')) {
                    // Visible copy (CC) on the daily reports email: valid addresses only; empty = no copy.
                    $reportCc = $cleanList('automated_job_daily_reports_cc', 'daily reports copy (CC)');
                    updateSetting('automated_job_daily_reports_cc', $reportCc);
                }
                if ($posted('automated_job_station_reports_to')) {
                    // Empty = the End of Day report recipients.
                    updateSetting('automated_job_station_reports_to', $cleanList('automated_job_station_reports_to', 'station report emails "go to" list'));
                }
                if ($posted('automated_job_station_reports_cc')) {
                    updateSetting('automated_job_station_reports_cc', $cleanList('automated_job_station_reports_cc', 'station report emails copy (CC)'));
                }
            }
            if ($canCompanyEmails && $posted('automated_job_company_statements_cc')) {
                updateSetting('automated_job_company_statements_cc', $cleanList('automated_job_company_statements_cc', 'company statements copy (CC)'));
            }
            // Only the switches this form showed are written: a form opened before a job existed never switches it off.
            // A form without the list predates 'station_reports' (2026-11-12), the only job added since.
            $shownJobs = isset($_POST['jobs_shown']) ? (array)$_POST['jobs_shown'] : array_diff(array_keys($jobs), ['station_reports']);
            foreach ($jobs as $jobName => $job) {
                if (!in_array($jobName, $shownJobs, true)) {
                    continue;
                }
                if (!$canTimedReports && in_array($jobName, $timedReportJobs, true)) {
                    continue; // needs the Timed report emails permission: keep the stored switch
                }
                if (!$canCompanyEmails && in_array($jobName, $companyJobs, true)) {
                    continue; // needs the Company statement emails permission: keep the stored switch
                }
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
            <?php
            $bkAt = (string)getSetting('last_backup_at', '');
            $bkSize = (int)getSetting('last_backup_size', 0);
            $bkTs = $bkAt !== '' ? (int)strtotime($bkAt) : 0;
            ?>
            <p style="margin:0 0 6px;">
                Last database backup: <strong><?php echo $bkTs > 0 ? $esc($bkAt) : 'never'; ?></strong>
                <?php if ($bkTs > 0 && $bkSize > 0): ?>(<?php echo $esc(number_format($bkSize / 1048576, 2)); ?> MB)<?php endif; ?>
                <?php if ($bkTs === 0 || (time() - $bkTs) > 36 * 3600): ?><span class="ae-badge ae-failed">overdue</span><?php endif; ?>
                &nbsp;·&nbsp; The backup runs on its own switch and does not depend on the master switch.
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
                    <span><strong>Master switch</strong> &mdash; turn all automated EMAIL jobs on/off (the nightly backup is controlled by its own job switch below)</span></label>
            </div>

            <h3>Jobs</h3>
            <div class="ae-grid">
                <?php foreach ($jobs as $jobName => $job):
                    $jobLocked = !$canTimedReports && in_array($jobName, $timedReportJobs, true);
                    $jobLockNote = $timedNote;
                    if (!$canCompanyEmails && in_array($jobName, $companyJobs, true)) {
                        $jobLocked = true;
                        $jobLockNote = $companyNote;
                    } ?>
                    <input type="hidden" name="jobs_shown[]" value="<?php echo $esc($jobName); ?>">
                    <label class="ae-check"><input type="checkbox" name="job_<?php echo $esc($jobName); ?>" value="1" <?php echo rh_auto_job_enabled($jobName) ? 'checked' : ''; ?><?php echo $jobLocked ? ' disabled' : ''; ?>>
                        <span><?php echo $esc($job['label']); ?><?php if ($jobLocked): ?><br><small class="help-text"><?php echo $esc($jobLockNote); ?></small><?php endif; ?></span></label>
                <?php endforeach; ?>
            </div>
            <p class="help-text">Tentative holds use the reminder hours from Hotel Settings. Pre-arrival, post-stay and gym renewal share the switches (and day settings) they always had in Hotel Settings / Gym Members. Daily station reports email sends the bookings, restaurant/till, accounting and stock reports once a day, soon after the trading day ends (next opening), to the End of Day report recipients. Station report emails send each station's Station Report (the same email as the Email button on that page) once a day per station, soon after that station's trading day ends (its next opening), to the "Station report emails go to" list (empty = End of Day report recipients). A station with no tickets that day gets no email.</p>

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
                <div class="form-group"><label for="reportcc">Copy (CC) on the daily reports email</label>
                    <input type="text" id="reportcc" name="automated_job_daily_reports_cc" class="form-control" value="<?php echo $esc(rh_auto_setting('automated_job_daily_reports_cc')); ?>" placeholder="empty = no copy" autocomplete="off"<?php echo $canTimedReports ? '' : ' disabled'; ?>>
                    <p class="help-text">Shown openly as a copy on every daily reports email. Separate several addresses with commas. Not used on test sends.<?php echo $canTimedReports ? '' : ' ' . $esc($timedNote) . '.'; ?></p></div>
                <div class="form-group"><label for="stationto">Station report emails go to</label>
                    <input type="text" id="stationto" name="automated_job_station_reports_to" class="form-control" value="<?php echo $esc(rh_auto_setting('automated_job_station_reports_to')); ?>" placeholder="empty = End of Day report recipients" autocomplete="off"<?php echo $canTimedReports ? '' : ' disabled'; ?>>
                    <p class="help-text">Who gets the station report emails. Separate several addresses with commas. Empty = the End of Day report recipients.<?php echo $canTimedReports ? '' : ' ' . $esc($timedNote) . '.'; ?></p></div>
                <div class="form-group"><label for="stationcc">Copy (CC) on the station report emails</label>
                    <input type="text" id="stationcc" name="automated_job_station_reports_cc" class="form-control" value="<?php echo $esc(rh_auto_setting('automated_job_station_reports_cc')); ?>" placeholder="empty = no copy" autocomplete="off"<?php echo $canTimedReports ? '' : ' disabled'; ?>>
                    <p class="help-text">Shown openly as a copy on every station report email. Separate several addresses with commas. Not used on test sends.<?php echo $canTimedReports ? '' : ' ' . $esc($timedNote) . '.'; ?></p></div>
                <div class="form-group"><label for="companycc">Copy (CC) on the company statements</label>
                    <input type="text" id="companycc" name="automated_job_company_statements_cc" class="form-control" value="<?php echo $esc(rh_auto_setting('automated_job_company_statements_cc')); ?>" placeholder="empty = no copy" autocomplete="off"<?php echo $canCompanyEmails ? '' : ' disabled'; ?>>
                    <p class="help-text">Company statements go once a week to each company with an overdue booking, at its billing email (plus the company's own copy list on Company accounts) and openly copied to these addresses. A company with no billing email gets nothing. The guest is never emailed for the company share.<?php echo $canCompanyEmails ? '' : ' ' . $esc($companyNote) . '.'; ?></p></div>
            </div>
            <label class="ae-check"><input type="checkbox" name="automated_email_bcc_hotel" value="1" <?php echo $cfg['automated_email_bcc_hotel'] === '1' ? 'checked' : ''; ?>>
                <span>BCC the hotel (admin notification address) on payment and quotation reminders</span></label>
            <p class="help-text">The hidden copy goes to the Staff copy email in Email Settings. For reminders sent from this page, this switch replaces the main hidden-copy switch.</p>

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
                                <td><?php echo $esc(in_array($row['account_type'], ['run', 'trading_day'], true) ? '—' : $row['account_type'] . ' #' . $row['account_id']); ?></td>
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
