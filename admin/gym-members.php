<?php

/**
 * Gym Members — enrolled membership register.
 *
 * The operational heart of the Gym/Fitness preset: who is enrolled, on what
 * package, and when their membership lapses. Distinct from gym-inquiries.php
 * (sales leads); an inquiry that converts becomes a row here.
 *
 * Backed by the gym_members table (migration
 * admin/migrations/2026_07_03_create_gym_members.sql) — until that runs,
 * the page shows a "pending migration" notice instead of failing.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once __DIR__ . '/includes/gym-checkin-lib.php';
require_once __DIR__ . '/includes/gym-analytics-lib.php';
require_once __DIR__ . '/includes/gym-reminders-lib.php';

/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$gm_json = static function (bool $ok, string $msg): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
};

$gm_statuses = ['active', 'expired', 'suspended', 'cancelled'];

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gm_action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $gm_json(false, 'Security token invalid — refresh the page.');
    }
    $action = (string)$_POST['gm_action'];

    try {
        if ($action === 'member_save') {
            $memberId = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $type = trim((string)($_POST['membership_type'] ?? ''));
            $start = trim((string)($_POST['start_date'] ?? ''));
            $expiry = trim((string)($_POST['expiry_date'] ?? ''));
            $fee = $_POST['monthly_fee'] !== '' ? (float)($_POST['monthly_fee'] ?? 0) : null;
            $status = in_array($_POST['status'] ?? '', $gm_statuses, true) ? (string)$_POST['status'] : 'active';
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($name === '' || mb_strlen($name) > 255) {
                $gm_json(false, 'Member name is required (max 255 characters).');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $gm_json(false, 'Enter a valid email address or leave it empty.');
            }
            $startDt = DateTime::createFromFormat('Y-m-d', $start);
            if (!$startDt) {
                $gm_json(false, 'A valid start date is required.');
            }
            if ($expiry !== '' && !DateTime::createFromFormat('Y-m-d', $expiry)) {
                $gm_json(false, 'Expiry date must be a valid date or left empty.');
            }
            if ($fee !== null && ($fee < 0 || $fee > 99999999)) {
                $gm_json(false, 'Monthly fee must be zero or a positive amount.');
            }

            if ($memberId > 0) {
                $stmt = $pdo->prepare("UPDATE gym_members SET full_name=?, email=?, phone=?, membership_type=?, start_date=?, expiry_date=?, monthly_fee=?, status=?, notes=? WHERE id=?");
                $stmt->execute([$name, $email ?: null, $phone ?: null, $type ?: null, $start, $expiry ?: null, $fee, $status, $notes ?: null, $memberId]);
                $gm_json(true, 'Member updated.');
            }
            do {
                $memberNumber = 'GM-' . strtoupper(substr(uniqid(), -6));
                $chk = $pdo->prepare("SELECT COUNT(*) FROM gym_members WHERE member_number = ?");
                $chk->execute([$memberNumber]);
            } while ((int)$chk->fetchColumn() > 0);
            $inquiryId = (int)($_POST['gym_inquiry_id'] ?? 0);
            $stmt = $pdo->prepare("INSERT INTO gym_members (member_number, full_name, email, phone, membership_type, start_date, expiry_date, monthly_fee, status, notes, gym_inquiry_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$memberNumber, $name, $email ?: null, $phone ?: null, $type ?: null, $start, $expiry ?: null, $fee, $status, $notes ?: null, $inquiryId ?: null, (int)($user['id'] ?? 0)]);

            // Converting an inquiry: mark the sales lead converted so the
            // pipeline reflects reality. Best-effort — never blocks enrolment.
            if ($inquiryId > 0) {
                try {
                    $pdo->prepare("UPDATE gym_inquiries SET status='converted' WHERE id=? AND status NOT IN ('cancelled')")->execute([$inquiryId]);
                } catch (Throwable $e) { /* fine */ }
            }

            // Digital membership card (barcode) email — never blocks the enrolment.
            $cardNote = '';
            if ($email !== '') {
                try {
                    require_once '../config/email.php';
                    $cardResult = sendGymMemberCardEmail([
                        'member_number'   => $memberNumber,
                        'full_name'       => $name,
                        'email'           => $email,
                        'membership_type' => $type,
                        'expiry_date'     => $expiry ?: null,
                    ]);
                    $cardNote = !empty($cardResult['success'])
                        ? ' Membership card emailed.'
                        : ' (Card email failed: ' . (string)($cardResult['message'] ?? 'unknown error') . ')';
                } catch (Throwable $mailEx) {
                    error_log('gym-members card email: ' . $mailEx->getMessage());
                    $cardNote = ' (Card email failed — member saved.)';
                }
            }
            $gm_json(true, 'Member enrolled — ' . $memberNumber . '.' . $cardNote);
        }

        if ($action === 'member_card') {
            $memberId = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT member_number, full_name, email, membership_type, expiry_date FROM gym_members WHERE id = ?");
            $stmt->execute([$memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                $gm_json(false, 'Member not found.');
            }
            if (empty($member['email'])) {
                $gm_json(false, 'This member has no email address on file.');
            }
            require_once '../config/email.php';
            $cardResult = sendGymMemberCardEmail($member);
            $gm_json(!empty($cardResult['success']), (string)($cardResult['message'] ?? 'Card email failed.'));
        }

        if ($action === 'member_attendance') {
            $memberId = (int)($_POST['id'] ?? 0);
            if (!gym_attendance_table_exists($pdo)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'visits' => [], 'pending_migration' => true]);
                exit;
            }
            $stmt = $pdo->prepare("
                SELECT checked_in_at, checked_out_at, method,
                       CASE WHEN checked_out_at IS NULL THEN NULL
                            ELSE TIMESTAMPDIFF(MINUTE, checked_in_at, checked_out_at) END AS minutes
                FROM gym_attendance WHERE member_id = ?
                ORDER BY checked_in_at DESC LIMIT 10
            ");
            $stmt->execute([$memberId]);
            $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Personal peak profile from ALL of this member's visits: per-hour
            // histogram + weekday preference, for targeted marketing.
            $hourRows = $pdo->prepare("SELECT HOUR(checked_in_at) AS h, COUNT(*) AS c FROM gym_attendance WHERE member_id = ? GROUP BY h");
            $hourRows->execute([$memberId]);
            $hours = [];
            foreach ($hourRows->fetchAll(PDO::FETCH_ASSOC) as $hr) { $hours[(int)$hr['h']] = (int)$hr['c']; }
            $dayRows = $pdo->prepare("SELECT WEEKDAY(checked_in_at) AS d, COUNT(*) AS c FROM gym_attendance WHERE member_id = ? GROUP BY d");
            $dayRows->execute([$memberId]);
            $wdays = [];
            foreach ($dayRows->fetchAll(PDO::FETCH_ASSOC) as $dr) { $wdays[(int)$dr['d']] = (int)$dr['c']; }

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'visits'  => $visits,
                'profile' => gym_peak_profile($hours, $wdays),
                'hours'   => $hours,
            ]);
            exit;
        }

        if ($action === 'reminder_settings') {
            $enabled = !empty($_POST['enabled']) ? '1' : '0';
            $days = (int)($_POST['days'] ?? 3);
            if ($days < 1 || $days > 30) {
                $gm_json(false, 'Reminder days must be between 1 and 30.');
            }
            updateSetting('gym_reminder_enabled', $enabled);
            updateSetting('gym_reminder_days', (string)$days);
            $gm_json(true, $enabled === '1'
                ? 'Renewal reminders on — members are emailed ' . $days . ' day(s) before expiry.'
                : 'Renewal reminders switched off.');
        }

        if ($action === 'run_reminders') {
            require_once '../config/email.php';
            $run = gym_run_expiry_reminders($pdo);
            if ($run['pending_migration']) {
                $gm_json(false, 'Reminder log table missing — run admin/migrations/2026_07_04_gym_reminder_log.sql first.');
            }
            if ($run['disabled']) {
                $gm_json(false, 'Reminders are switched off — enable them first.');
            }
            $msg = 'Checked ' . $run['checked'] . ' due membership(s): ' . $run['sent'] . ' reminder(s) sent, ' . $run['skipped'] . ' already reminded.';
            if (!empty($run['errors'])) {
                $msg .= ' Errors: ' . implode(' | ', array_slice($run['errors'], 0, 3));
            }
            $gm_json(empty($run['errors']), $msg);
        }

        if ($action === 'member_status') {
            $memberId = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', $gm_statuses, true) ? (string)$_POST['status'] : '';
            if ($status === '') {
                $gm_json(false, 'Invalid status.');
            }
            $pdo->prepare("UPDATE gym_members SET status=? WHERE id=?")->execute([$status, $memberId]);
            $gm_json(true, 'Member marked ' . $status . '.');
        }

        if ($action === 'member_delete') {
            $memberId = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM gym_members WHERE id=?")->execute([$memberId]);
            $gm_json(true, 'Member deleted.');
        }

        $gm_json(false, 'Unknown action.');
    } catch (PDOException $e) {
        error_log('gym-members: ' . $e->getMessage());
        $gm_json(false, 'Database error — has the gym_members migration been run?');
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
$gm_table_missing = false;
$gm_members = [];
$gm_counts = ['active' => 0, 'expiring' => 0, 'expired' => 0, 'all' => 0];
$gm_filter = (string)($_GET['filter'] ?? 'all');
try {
    $gm_counts['all']      = (int)$pdo->query("SELECT COUNT(*) FROM gym_members")->fetchColumn();
    $gm_counts['active']   = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='active'")->fetchColumn();
    $gm_counts['expiring'] = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $gm_counts['expired']  = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='expired' OR (expiry_date IS NOT NULL AND expiry_date < CURDATE())")->fetchColumn();

    $where = '1=1';
    if ($gm_filter === 'active')   { $where = "status='active'"; }
    if ($gm_filter === 'expiring') { $where = "status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; }
    if ($gm_filter === 'expired')  { $where = "(status='expired' OR (expiry_date IS NOT NULL AND expiry_date < CURDATE()))"; }
    $gm_members = $pdo->query("SELECT * FROM gym_members WHERE $where ORDER BY status='active' DESC, expiry_date IS NULL ASC, expiry_date ASC, full_name ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $gm_table_missing = true;
}

// Attendance aggregates — degrade to empty until gym_attendance migration runs
$gm_attendance_ready = !$gm_table_missing && gym_attendance_table_exists($pdo);
$gm_visit_stats = [];      // member_id => ['visits' => n, 'last_in' => datetime, 'in_now' => 0|1]
$gm_in_gym_now = 0;
$gm_visits_today = 0;
$gm_peak_stats = [];       // member_id => ['hours' => [h=>c], 'wdays' => [d=>c]]
if ($gm_attendance_ready) {
    try {
        foreach ($pdo->query("
            SELECT member_id, COUNT(*) AS visits, MAX(checked_in_at) AS last_in,
                   SUM(CASE WHEN checked_out_at IS NULL THEN 1 ELSE 0 END) AS in_now,
                   SUM(CASE WHEN checked_in_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS visits_30d
            FROM gym_attendance GROUP BY member_id
        ")->fetchAll(PDO::FETCH_ASSOC) as $vs) {
            $gm_visit_stats[(int)$vs['member_id']] = $vs;
        }
        // Per-member check-in hour + weekday histograms (small table; one pass each)
        foreach ($pdo->query("SELECT member_id, HOUR(checked_in_at) AS h, COUNT(*) AS c FROM gym_attendance GROUP BY member_id, h")->fetchAll(PDO::FETCH_ASSOC) as $hr) {
            $gm_peak_stats[(int)$hr['member_id']]['hours'][(int)$hr['h']] = (int)$hr['c'];
        }
        foreach ($pdo->query("SELECT member_id, WEEKDAY(checked_in_at) AS d, COUNT(*) AS c FROM gym_attendance GROUP BY member_id, d")->fetchAll(PDO::FETCH_ASSOC) as $dr) {
            $gm_peak_stats[(int)$dr['member_id']]['wdays'][(int)$dr['d']] = (int)$dr['c'];
        }
        $gm_in_gym_now   = (int)$pdo->query("SELECT COUNT(*) FROM gym_attendance WHERE checked_out_at IS NULL")->fetchColumn();
        $gm_visits_today = (int)$pdo->query("SELECT COUNT(*) FROM gym_attendance WHERE checked_in_at >= CURDATE()")->fetchColumn();
    } catch (PDOException $e) {
        $gm_attendance_ready = false;
    }
}

// Renewal-reminder configuration + last-run summary
$gm_reminder_cfg   = gym_reminder_settings();
$gm_reminder_ready = !$gm_table_missing && gym_reminder_log_table_exists($pdo);
$gm_reminder_run   = $gm_reminder_ready ? gym_reminder_last_run($pdo) : ['last_sent_at' => null, 'sent_today' => 0, 'total' => 0];

// Package names for the membership-type datalist (best effort)
$gm_packages = [];
try {
    $gm_packages = $pdo->query("SELECT name FROM gym_packages WHERE is_active=1 ORDER BY display_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { /* optional */ }

$gm_currency = (string)getSetting('currency_symbol', 'K');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function() {
            var _t = '<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>';
            var _f = window.fetch;
            window.fetch = function(u, o) {
                if (o && o.body instanceof FormData && !o.body.has('csrf_token')) o.body.append('csrf_token', _t);
                return _f.apply(this, arguments);
            };
        })();
    </script>
    <title>Gym Members - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/menu-management.css">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title">Gym Members</h2>
            <?php if (!$gm_table_missing): ?>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <?php if ($gm_attendance_ready): ?>
                <span class="cat-count" title="Members checked in right now"><i class="fas fa-person-running"></i> In gym now: <?php echo $gm_in_gym_now; ?></span>
                <span class="cat-count" title="Check-ins recorded today"><i class="fas fa-clock"></i> Visits today: <?php echo $gm_visits_today; ?></span>
                <?php endif; ?>
                <?php if (hasPermission((int)$user['id'], 'gym_checkin')): ?>
                <a class="btn-add" href="gym-checkin.php" style="text-decoration:none;background:#111827;color:#ffffff;">
                    <i class="fas fa-barcode"></i> Check-In Scanner
                </a>
                <?php endif; ?>
                <?php if (hasPermission((int)$user['id'], 'gym_reports')): ?>
                <a class="btn-add" href="gym-reports.php" style="text-decoration:none;background:#8B7355;color:#ffffff;">
                    <i class="fas fa-chart-line"></i> Gym Reports
                </a>
                <?php endif; ?>
                <button class="btn-add" onclick="gmOpenModal()">
                    <i class="fas fa-user-plus"></i> Enrol Member
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($gm_table_missing): ?>
            <?php showAlert('The membership register table (gym_members) has not been created yet — run the migration in admin/migrations/2026_07_03_create_gym_members.sql, then reload this page.', 'error'); ?>
        <?php else: ?>

        <!-- Renewal reminder engine — configurable days-before-expiry email -->
        <div style="background:#fff;border:1px solid #d5cfc4;border-radius:4px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <span style="font-weight:700;color:#8B7355;font-size:.82rem;letter-spacing:.05em;text-transform:uppercase;">
                <i class="fas fa-bell"></i> Renewal Reminders
            </span>
            <label style="display:inline-flex;align-items:center;gap:7px;font-size:.86rem;color:#3e3930;cursor:pointer;">
                <input type="checkbox" id="gmRemEnabled" <?php echo $gm_reminder_cfg['enabled'] ? 'checked' : ''; ?>>
                Email members
            </label>
            <label style="display:inline-flex;align-items:center;gap:7px;font-size:.86rem;color:#3e3930;">
                <input type="number" id="gmRemDays" min="1" max="30" value="<?php echo (int)$gm_reminder_cfg['days']; ?>" style="width:64px;padding:6px 8px;border:1px solid #d3cbc0;border-radius:4px;">
                day(s) before expiry
            </label>
            <button class="mm-btn mm-btn-sm" onclick="gmSaveReminderSettings()"><i class="fas fa-save"></i> Save</button>
            <button class="mm-btn mm-btn-sm" style="background:#8B7355;color:#fff;" onclick="gmRunReminders(this)" title="Checks for memberships expiring within the window and emails any not yet reminded — safe to click repeatedly">
                <i class="fas fa-paper-plane"></i> Send due reminders now
            </button>
            <span style="font-size:.78rem;color:#9a8f82;margin-left:auto;">
                <?php if (!$gm_reminder_ready): ?>
                    <i class="fas fa-triangle-exclamation" style="color:#B18247;"></i> Log table pending — run admin/migrations/2026_07_04_gym_reminder_log.sql
                <?php elseif ($gm_reminder_run['last_sent_at']): ?>
                    Last reminder sent <?php echo htmlspecialchars(date('M j, H:i', strtotime((string)$gm_reminder_run['last_sent_at']))); ?> · <?php echo (int)$gm_reminder_run['sent_today']; ?> today · <?php echo (int)$gm_reminder_run['total']; ?> all-time
                <?php else: ?>
                    No reminders sent yet — cron: scripts/gym_membership_reminders.php (daily)
                <?php endif; ?>
            </span>
        </div>

        <div class="menu-type-tabs" style="margin-bottom:18px;">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'expiring' => 'Expiring ≤30d', 'expired' => 'Expired'] as $fk => $fl): ?>
                <a class="menu-type-tab <?php echo $gm_filter === $fk ? 'active' : ''; ?>" href="?filter=<?php echo $fk; ?>" style="text-decoration:none;">
                    <?php echo $fl; ?> <span class="cat-count" style="margin-left:6px;"><?php echo (int)$gm_counts[$fk]; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($gm_members)): ?>
            <div class="empty-state">
                <i class="fas fa-id-card"></i>
                <p><?php echo $gm_filter === 'all' ? 'No members enrolled yet. Enrol your first member to start the register.' : 'No members match this filter.'; ?></p>
            </div>
        <?php else: ?>
            <table class="menu-table">
                <thead>
                    <tr>
                        <th style="width:110px;">Member #</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Package</th>
                        <th style="width:100px;">Started</th>
                        <th style="width:100px;">Expires</th>
                        <th style="width:110px;">Fee (<?php echo htmlspecialchars($gm_currency); ?>/mo)</th>
                        <?php if ($gm_attendance_ready): ?>
                        <th style="width:120px;" title="Most recent check-in">Last Visit</th>
                        <th style="width:90px;" title="Total visits, and visits in the last 30 days">Visits</th>
                        <th style="width:160px;" title="Personal peak training time and marketing segment (click visits for the full breakdown)">Profile</th>
                        <?php endif; ?>
                        <th style="width:100px;">Status</th>
                        <th style="width:170px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gm_members as $m):
                        $statusColor = ['active' => '#2e7d32', 'expired' => '#9e4040', 'suspended' => '#B18247', 'cancelled' => '#6c757d'][$m['status']] ?? '#6c757d';
                        $expPill = gym_days_to_expiry($m['expiry_date'] ?? null, (string)$m['status'], (int)$gm_reminder_cfg['days']);
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($m['member_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($m['full_name']); ?></td>
                            <td style="font-size:.85rem;color:#7a6f63;">
                                <?php echo htmlspecialchars($m['email'] ?? ''); ?><?php echo ($m['email'] && $m['phone']) ? '<br>' : ''; ?><?php echo htmlspecialchars($m['phone'] ?? ''); ?>
                            </td>
                            <td><?php echo htmlspecialchars($m['membership_type'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($m['start_date']))); ?></td>
                            <td style="font-size:.85rem;">
                                <?php echo $m['expiry_date'] ? htmlspecialchars(date('M j, Y', strtotime($m['expiry_date']))) : '—'; ?>
                                <br><span style="display:inline-block;margin-top:3px;padding:1px 8px;border-radius:10px;font-size:.72rem;font-weight:700;color:<?php echo $expPill['color']; ?>;background:<?php echo $expPill['bg']; ?>;"><?php echo htmlspecialchars($expPill['label']); ?></span>
                            </td>
                            <td><?php echo $m['monthly_fee'] !== null ? number_format((float)$m['monthly_fee'], 2) : '—'; ?></td>
                            <?php if ($gm_attendance_ready):
                                $vs = $gm_visit_stats[(int)$m['id']] ?? null;
                                $inNow = $vs && (int)$vs['in_now'] > 0;
                            ?>
                            <td style="font-size:.85rem;">
                                <?php if ($inNow): ?>
                                    <span style="font-weight:700;color:#2e7d32;"><i class="fas fa-person-running"></i> In gym</span>
                                <?php elseif ($vs && $vs['last_in']): ?>
                                    <?php echo htmlspecialchars(date('M j, H:i', strtotime((string)$vs['last_in']))); ?>
                                <?php else: ?>
                                    <span style="color:#9a8f82;">Never</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;font-size:.85rem;">
                                <?php
                                    $v30 = $vs ? (int)($vs['visits_30d'] ?? 0) : 0;
                                    $freq = gym_frequency_label($v30, $m['start_date'] ?? null);
                                ?>
                                <?php if ($vs && (int)$vs['visits'] > 0): ?>
                                    <a href="#" onclick="gmShowLog(<?php echo (int)$m['id']; ?>, '<?php echo htmlspecialchars($m['full_name'], ENT_QUOTES); ?>'); return false;" style="font-weight:700;"><?php echo (int)$vs['visits']; ?></a>
                                <?php else: ?>
                                    <span style="color:#9a8f82;">0</span>
                                <?php endif; ?>
                                <br><span style="font-size:.72rem;font-weight:700;color:<?php echo $freq['color']; ?>;" title="<?php echo $v30; ?> visit(s) in the last 30 days"><?php echo htmlspecialchars($freq['label']); ?> · <?php echo $v30; ?>/30d</span>
                            </td>
                            <td style="font-size:.78rem;">
                                <?php
                                    $peak = gym_peak_profile($gm_peak_stats[(int)$m['id']]['hours'] ?? [], $gm_peak_stats[(int)$m['id']]['wdays'] ?? []);
                                    $seg  = gym_member_segment($m, $v30, $vs ? (int)$vs['visits'] : 0, $vs['last_in'] ?? null);
                                ?>
                                <?php if ($peak['top_slot']): ?>
                                    <span style="color:#5a5147;" title="<?php echo htmlspecialchars((string)$peak['summary']); ?>"><i class="fas fa-clock" style="color:#B18247;"></i> <?php echo htmlspecialchars((string)$peak['top_slot']); ?></span><br>
                                <?php endif; ?>
                                <span style="display:inline-block;margin-top:2px;padding:1px 8px;border-radius:10px;font-size:.7rem;font-weight:700;color:#fff;background:<?php echo $seg['color']; ?>;" title="<?php echo htmlspecialchars($seg['hint']); ?>"><?php echo htmlspecialchars($seg['segment']); ?></span>
                            </td>
                            <?php endif; ?>
                            <td><span style="font-weight:600;color:<?php echo $statusColor; ?>;"><?php echo ucfirst($m['status']); ?></span></td>
                            <td class="actions-cell">
                                <div class="action-buttons">
                                    <button class="btn-action" title="Edit" onclick="gmOpenModal(<?php echo htmlspecialchars(json_encode($m), ENT_QUOTES); ?>)"><i class="fas fa-pen"></i></button>
                                    <?php if (!empty($m['email'])): ?>
                                        <button class="btn-action" title="Resend membership card email" onclick="gmResendCard(<?php echo (int)$m['id']; ?>)"><i class="fas fa-envelope"></i></button>
                                    <?php endif; ?>
                                    <?php if ($m['status'] === 'active'): ?>
                                        <button class="btn-action" title="Suspend" onclick="gmStatus(<?php echo (int)$m['id']; ?>, 'suspended')"><i class="fas fa-pause"></i></button>
                                    <?php else: ?>
                                        <button class="btn-action btn-toggle active" title="Reactivate" onclick="gmStatus(<?php echo (int)$m['id']; ?>, 'active')"><i class="fas fa-play"></i></button>
                                    <?php endif; ?>
                                    <button class="btn-action btn-delete" title="Delete"
                                        onclick="gmConfirm('Delete member &quot;<?php echo htmlspecialchars($m['full_name'], ENT_QUOTES); ?>&quot;? This cannot be undone.', function(){ gmDelete(<?php echo (int)$m['id']; ?>); })"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Member modal -->
    <div class="mm-modal" id="gmModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3 id="gmModalTitle">Enrol Member</h3>
                <button type="button" class="mm-modal-close" onclick="gmClose('gmModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body">
                <input type="hidden" id="gmId" value="0">
                <input type="hidden" id="gmInquiryId" value="0">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Full name</label>
                <input type="text" id="gmName" maxlength="255" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;margin-bottom:12px;">
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Email <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                        <input type="email" id="gmEmail" maxlength="255" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Phone <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                        <input type="text" id="gmPhone" maxlength="50" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                </div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Membership package</label>
                <input type="text" id="gmType" maxlength="100" list="gmPackages" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;margin-bottom:12px;" placeholder="e.g. Monthly Unlimited">
                <datalist id="gmPackages">
                    <?php foreach ($gm_packages as $p): ?><option value="<?php echo htmlspecialchars($p); ?>"></option><?php endforeach; ?>
                </datalist>
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Start date</label>
                        <input type="date" id="gmStart" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Expiry <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                        <input type="date" id="gmExpiry" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                </div>
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Monthly fee (<?php echo htmlspecialchars($gm_currency); ?>)</label>
                        <input type="number" id="gmFee" min="0" step="0.01" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Status</label>
                        <select id="gmStatus" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                            <?php foreach ($gm_statuses as $s): ?><option value="<?php echo $s; ?>"><?php echo ucfirst($s); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Notes <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                <textarea id="gmNotes" rows="2" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;"></textarea>
            </div>
            <div class="mm-modal-foot" style="display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;">
                <button class="mm-btn mm-btn-ghost" onclick="gmClose('gmModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" onclick="gmSave()">Save Member</button>
            </div>
        </div>
    </div>

    <!-- Attendance log modal -->
    <div class="mm-modal" id="gmLogModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3 id="gmLogTitle">Recent Visits</h3>
                <button type="button" class="mm-modal-close" onclick="gmClose('gmLogModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body" id="gmLogBody" style="max-height:60vh;overflow-y:auto;">
                <p style="color:#9a8f82;">Loading…</p>
            </div>
        </div>
    </div>

    <!-- Confirm modal -->
    <div class="mm-modal" id="gmConfirmModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3><i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i> Are you sure?</h3>
                <button type="button" class="mm-modal-close" onclick="gmClose('gmConfirmModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body"><p id="gmConfirmText" style="margin:0;"></p></div>
            <div class="mm-modal-foot" style="display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;">
                <button class="mm-btn mm-btn-ghost" onclick="gmClose('gmConfirmModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" id="gmConfirmYes" style="background:#c0392b;border-color:#c0392b;">Yes, continue</button>
            </div>
        </div>
    </div>

    <script>
        function gmOpen(id) { document.getElementById(id).classList.add('open'); }
        function gmClose(id) { document.getElementById(id).classList.remove('open'); }
        function gmToast(msg, ok) { if (typeof Alert !== 'undefined' && Alert.show) { Alert.show(msg, ok ? 'success' : 'error'); } }

        function gmPost(fields) {
            var fd = new FormData();
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k] == null ? '' : fields[k]); });
            return fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    gmToast(d.message || (d.success ? 'Saved.' : 'Failed.'), !!d.success);
                    if (d.success) { setTimeout(function () { window.location.reload(); }, 700); }
                    return d;
                })
                .catch(function () { gmToast('Network error — please try again.', false); });
        }

        function gmOpenModal(m) {
            document.getElementById('gmModalTitle').textContent = m ? 'Edit Member' : 'Enrol Member';
            document.getElementById('gmId').value = m ? m.id : 0;
            document.getElementById('gmInquiryId').value = 0;
            document.getElementById('gmName').value = m ? (m.full_name || '') : '';
            document.getElementById('gmEmail').value = m ? (m.email || '') : '';
            document.getElementById('gmPhone').value = m ? (m.phone || '') : '';
            document.getElementById('gmType').value = m ? (m.membership_type || '') : '';
            document.getElementById('gmStart').value = m ? (m.start_date || '') : new Date().toISOString().slice(0, 10);
            document.getElementById('gmExpiry').value = m ? (m.expiry_date || '') : '';
            document.getElementById('gmFee').value = m && m.monthly_fee != null ? m.monthly_fee : '';
            document.getElementById('gmStatus').value = m ? (m.status || 'active') : 'active';
            document.getElementById('gmNotes').value = m ? (m.notes || '') : '';
            gmOpen('gmModal');
            setTimeout(function () { document.getElementById('gmName').focus(); }, 60);
        }

        function gmSave() {
            var name = document.getElementById('gmName').value.trim();
            var start = document.getElementById('gmStart').value;
            if (!name) { gmToast('Member name is required.', false); return; }
            if (!start) { gmToast('Start date is required.', false); return; }
            gmPost({
                gm_action: 'member_save',
                id: document.getElementById('gmId').value,
                gym_inquiry_id: document.getElementById('gmInquiryId').value,
                full_name: name,
                email: document.getElementById('gmEmail').value.trim(),
                phone: document.getElementById('gmPhone').value.trim(),
                membership_type: document.getElementById('gmType').value.trim(),
                start_date: start,
                expiry_date: document.getElementById('gmExpiry').value,
                monthly_fee: document.getElementById('gmFee').value,
                status: document.getElementById('gmStatus').value,
                notes: document.getElementById('gmNotes').value.trim()
            });
        }

        function gmStatus(id, status) { gmPost({ gm_action: 'member_status', id: id, status: status }); }
        function gmDelete(id) { gmPost({ gm_action: 'member_delete', id: id }); }

        function gmResendCard(id) {
            var fd = new FormData();
            fd.append('gm_action', 'member_card');
            fd.append('id', id);
            fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { gmToast(d.message || (d.success ? 'Card sent.' : 'Failed.'), !!d.success); })
                .catch(function () { gmToast('Network error — please try again.', false); });
        }

        function gmEscape(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
        function gmFmtDT(dt) { if (!dt) return ''; var d = new Date(String(dt).replace(' ', 'T')); return isNaN(d) ? String(dt) : d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }); }
        function gmFmtDur(mins) { if (mins == null) return '<span style="color:#2e7d32;font-weight:700;">in gym</span>'; mins = parseInt(mins, 10); return mins >= 60 ? Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm' : mins + 'm'; }

        function gmShowLog(id, name) {
            document.getElementById('gmLogTitle').textContent = 'Recent Visits — ' + name;
            document.getElementById('gmLogBody').innerHTML = '<p style="color:#9a8f82;">Loading…</p>';
            gmOpen('gmLogModal');
            var fd = new FormData();
            fd.append('gm_action', 'member_attendance');
            fd.append('id', id);
            fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var body = document.getElementById('gmLogBody');
                    var visits = (d && d.visits) || [];
                    if (!visits.length) {
                        body.innerHTML = '<p style="color:#9a8f82;margin:0;">No visits recorded yet.</p>';
                        return;
                    }
                    var html = '';
                    // Peak-time profile: summary line + per-hour histogram bars
                    if (d.profile && d.profile.summary) {
                        html += '<div style="background:#FAF6F0;border:1px solid #e8e0d4;border-radius:6px;padding:12px 14px;margin-bottom:14px;">' +
                            '<div style="font-weight:700;color:#8B7355;font-size:.8rem;margin-bottom:8px;"><i class="fas fa-chart-simple"></i> Peak training time: ' + gmEscape(d.profile.summary) + '</div>';
                        var hours = d.hours || {};
                        var max = 0;
                        Object.keys(hours).forEach(function (h) { if (hours[h] > max) max = hours[h]; });
                        if (max > 0) {
                            html += Object.keys(hours).sort(function (a, b) { return a - b; }).map(function (h) {
                                var c = hours[h];
                                var pct = Math.max(4, Math.round((c / max) * 100));
                                return '<div style="display:flex;align-items:center;gap:8px;margin:2px 0;font-size:.72rem;color:#5a5147;">' +
                                    '<span style="width:42px;text-align:right;">' + String(h).padStart(2, '0') + ':00</span>' +
                                    '<span style="flex:1;background:#ece4d8;border-radius:3px;overflow:hidden;"><span style="display:block;height:9px;width:' + pct + '%;background:#B18247;border-radius:3px;"></span></span>' +
                                    '<span style="width:20px;">' + c + '</span></div>';
                            }).join('');
                        }
                        html += '</div>';
                    }
                    html += '<table class="menu-table" style="margin:0;"><thead><tr><th>In</th><th>Out</th><th>Duration</th><th>Method</th></tr></thead><tbody>' +
                        visits.map(function (v) {
                            return '<tr><td>' + gmFmtDT(v.checked_in_at) + '</td><td>' + (v.checked_out_at ? gmFmtDT(v.checked_out_at) : '—') + '</td><td>' + gmFmtDur(v.minutes) + '</td><td>' + gmEscape(v.method) + '</td></tr>';
                        }).join('') + '</tbody></table>';
                    body.innerHTML = html;
                })
                .catch(function () { document.getElementById('gmLogBody').innerHTML = '<p style="color:#c0392b;margin:0;">Could not load visits.</p>'; });
        }

        function gmSaveReminderSettings() {
            var days = parseInt(document.getElementById('gmRemDays').value, 10);
            if (isNaN(days) || days < 1 || days > 30) { gmToast('Reminder days must be between 1 and 30.', false); return; }
            gmPost({
                gm_action: 'reminder_settings',
                enabled: document.getElementById('gmRemEnabled').checked ? 1 : 0,
                days: days
            });
        }

        function gmRunReminders(btn) {
            btn.disabled = true;
            var fd = new FormData();
            fd.append('gm_action', 'run_reminders');
            fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    gmToast(d.message || (d.success ? 'Done.' : 'Failed.'), !!d.success);
                    if (d.success) { setTimeout(function () { window.location.reload(); }, 1400); }
                })
                .catch(function () { gmToast('Network error — please try again.', false); })
                .finally(function () { btn.disabled = false; });
        }

        var gmConfirmCb = null;
        function gmConfirm(text, cb) {
            document.getElementById('gmConfirmText').textContent = text;
            gmConfirmCb = cb;
            gmOpen('gmConfirmModal');
        }
        document.getElementById('gmConfirmYes').addEventListener('click', function () {
            gmClose('gmConfirmModal');
            if (gmConfirmCb) { gmConfirmCb(); gmConfirmCb = null; }
        });
        ['gmModal', 'gmConfirmModal', 'gmLogModal'].forEach(function (id) {
            var el = document.getElementById(id);
            el.addEventListener('click', function (e) { if (e.target === el) { gmClose(id); } });
        });

        // "Enrol" handoff from gym-inquiries.php: ?enrol_name=…&enrol_email=…
        // opens the enrol modal pre-filled and links the member back to the
        // inquiry via gym_inquiry_id (marked converted on save).
        (function () {
            var q = new URLSearchParams(window.location.search);
            if (!q.has('enrol_name')) { return; }
            gmOpenModal();
            document.getElementById('gmName').value = q.get('enrol_name') || '';
            document.getElementById('gmEmail').value = q.get('enrol_email') || '';
            document.getElementById('gmPhone').value = q.get('enrol_phone') || '';
            document.getElementById('gmType').value = q.get('enrol_type') || '';
            document.getElementById('gmInquiryId').value = parseInt(q.get('enrol_inquiry_id') || '0', 10) || 0;
        })();
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
