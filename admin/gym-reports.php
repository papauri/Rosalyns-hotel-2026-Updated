<?php

/**
 * Gym Reports — membership, attendance and outstanding-balance analytics.
 *
 * The gym operator's daily/weekly management view: how many members, who
 * hasn't paid, how busy the floor is and when. Reads gym_members,
 * gym_attendance, gym_inquiries and the payments ledger (booking_type='gym',
 * display-only — accounting math lives in the finance pages).
 *
 * Permission: gym_reports · Module: gym. Degrades gracefully while the
 * gym_members / gym_attendance migrations are pending.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once __DIR__ . '/includes/gym-checkin-lib.php';

/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

// ── Date range (presets like reports.php; default last 30 days) ─────────────
$gr_range = (string)($_GET['range'] ?? '30d');
$gr_end   = date('Y-m-d');
$gr_start = match ($gr_range) {
    'today' => date('Y-m-d'),
    '7d'    => date('Y-m-d', strtotime('-6 days')),
    '90d'   => date('Y-m-d', strtotime('-89 days')),
    default => date('Y-m-d', strtotime('-29 days')),
};
if ($gr_range === 'custom') {
    $cs = (string)($_GET['start_date'] ?? '');
    $ce = (string)($_GET['end_date'] ?? '');
    if (DateTime::createFromFormat('Y-m-d', $cs) && DateTime::createFromFormat('Y-m-d', $ce) && $cs <= $ce) {
        $gr_start = $cs;
        $gr_end = $ce;
    } else {
        $gr_range = '30d';
    }
}
$gr_start_dt = $gr_start . ' 00:00:00';
$gr_end_dt   = $gr_end . ' 23:59:59';
$gr_currency = (string)getSetting('currency_symbol', 'K');

// ── Membership KPIs ──────────────────────────────────────────────────────────
$gr_members_ready = true;
$gr_m = ['total' => 0, 'active' => 0, 'expired' => 0, 'suspended' => 0, 'cancelled' => 0, 'new_in_range' => 0, 'expiring_30d' => 0, 'churn_in_range' => 0];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) c FROM gym_members GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR) as $st => $c) {
        if (isset($gr_m[$st])) { $gr_m[$st] = (int)$c; }
        $gr_m['total'] += (int)$c;
    }
    $s = $pdo->prepare("SELECT COUNT(*) FROM gym_members WHERE start_date BETWEEN ? AND ?");
    $s->execute([$gr_start, $gr_end]);
    $gr_m['new_in_range'] = (int)$s->fetchColumn();
    $gr_m['expiring_30d'] = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    // Churn proxy: memberships that lapsed inside the range (expiry passed or explicitly marked)
    $s = $pdo->prepare("SELECT COUNT(*) FROM gym_members WHERE (status IN ('expired','cancelled') AND updated_at BETWEEN ? AND ?) OR (expiry_date BETWEEN ? AND ? AND expiry_date < CURDATE())");
    $s->execute([$gr_start_dt, $gr_end_dt, $gr_start, $gr_end]);
    $gr_m['churn_in_range'] = (int)$s->fetchColumn();
} catch (PDOException $e) {
    $gr_members_ready = false;
}

// ── Unpaid / outstanding balances ────────────────────────────────────────────
$gr_outstanding_rows = [];
$gr_outstanding_total = 0.0;
try {
    $gr_outstanding_rows = $pdo->query("
        SELECT gi.id, gi.reference_number, gi.name, gi.membership_type,
               gi.total_with_vat, gi.amount_paid, gi.amount_due, gi.payment_status,
               gm.member_number
        FROM gym_inquiries gi
        LEFT JOIN gym_members gm ON gm.gym_inquiry_id = gi.id
        WHERE gi.amount_due > 0 AND gi.status NOT IN ('cancelled')
        ORDER BY gi.amount_due DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($gr_outstanding_rows as $r) { $gr_outstanding_total += (float)$r['amount_due']; }
} catch (PDOException $e) { /* inquiries financial columns pending */ }

// ── Attendance analytics (range-filtered) ────────────────────────────────────
$gr_att_ready = gym_attendance_table_exists($pdo);
$gr_a = ['checkins' => 0, 'unique' => 0, 'avg_minutes' => 0, 'in_now' => 0, 'busiest_day' => null];
$gr_hours_in = array_fill(0, 24, 0);
$gr_hours_out = array_fill(0, 24, 0);
$gr_daily = [];
if ($gr_att_ready) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) c, COUNT(DISTINCT member_id) u,
                                   AVG(CASE WHEN checked_out_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, checked_in_at, checked_out_at) END) avg_min
                            FROM gym_attendance WHERE checked_in_at BETWEEN ? AND ?");
        $s->execute([$gr_start_dt, $gr_end_dt]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $gr_a['checkins']    = (int)($row['c'] ?? 0);
        $gr_a['unique']      = (int)($row['u'] ?? 0);
        $gr_a['avg_minutes'] = (int)round((float)($row['avg_min'] ?? 0));
        $gr_a['in_now']      = (int)$pdo->query("SELECT COUNT(*) FROM gym_attendance WHERE checked_out_at IS NULL")->fetchColumn();

        $s = $pdo->prepare("SELECT HOUR(checked_in_at) h, COUNT(*) c FROM gym_attendance WHERE checked_in_at BETWEEN ? AND ? GROUP BY h");
        $s->execute([$gr_start_dt, $gr_end_dt]);
        foreach ($s->fetchAll(PDO::FETCH_KEY_PAIR) as $h => $c) { $gr_hours_in[(int)$h] = (int)$c; }

        $s = $pdo->prepare("SELECT HOUR(checked_out_at) h, COUNT(*) c FROM gym_attendance WHERE checked_out_at IS NOT NULL AND checked_out_at BETWEEN ? AND ? GROUP BY h");
        $s->execute([$gr_start_dt, $gr_end_dt]);
        foreach ($s->fetchAll(PDO::FETCH_KEY_PAIR) as $h => $c) { $gr_hours_out[(int)$h] = (int)$c; }

        $s = $pdo->prepare("SELECT DAYNAME(checked_in_at) d, COUNT(*) c FROM gym_attendance WHERE checked_in_at BETWEEN ? AND ? GROUP BY d ORDER BY c DESC LIMIT 1");
        $s->execute([$gr_start_dt, $gr_end_dt]);
        if ($bd = $s->fetch(PDO::FETCH_ASSOC)) { $gr_a['busiest_day'] = $bd['d'] . ' (' . (int)$bd['c'] . ' visits)'; }

        $s = $pdo->query("SELECT DATE(checked_in_at) d, COUNT(*) c, COUNT(DISTINCT member_id) u,
                                 AVG(CASE WHEN checked_out_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, checked_in_at, checked_out_at) END) avg_min
                          FROM gym_attendance
                          WHERE checked_in_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                          GROUP BY d ORDER BY d DESC");
        $gr_daily = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $gr_att_ready = false;
    }
}
$gr_hours_in_max  = max(1, max($gr_hours_in));
$gr_hours_out_max = max(1, max($gr_hours_out));

// ── Revenue strip (display only) ─────────────────────────────────────────────
$gr_rev = ['count' => 0, 'total' => 0.0];
try {
    $s = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) t FROM payments
                        WHERE booking_type='gym' AND payment_status IN ('completed','paid')
                          AND COALESCE(payment_type,'') <> 'refund' AND deleted_at IS NULL
                          AND payment_date BETWEEN ? AND ?");
    $s->execute([$gr_start, $gr_end]);
    $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    $gr_rev = ['count' => (int)($row['c'] ?? 0), 'total' => (float)($row['t'] ?? 0)];
} catch (PDOException $e) { /* fine */ }

$gr_fmt_dur = static function (int $mins): string {
    if ($mins <= 0) { return '—'; }
    return $mins >= 60 ? floor($mins / 60) . 'h ' . ($mins % 60) . 'm' : $mins . 'm';
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gym Reports - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
    <link rel="stylesheet" href="css/menu-management.css?v=<?php echo @filemtime(__DIR__ . '/css/menu-management.css'); ?>">
    <style>
        .gr-hour-row { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
        .gr-hour-label { width: 46px; font-size: .78rem; color: #7a6f63; text-align: right; flex-shrink: 0; }
        .gr-hour-track { flex: 1; }
        .gr-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        @media (max-width: 900px) { .gr-grid-2 { grid-template-columns: 1fr; } }
        .gr-section { background: #fff; border: 1px solid #d5cfc4; border-radius: 4px; padding: 18px 20px; margin-bottom: 20px; }
        .gr-section h3 { margin: 0 0 14px; font-size: 1rem; color: #3e3930; }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title"><i class="fas fa-chart-line" style="color:#8B7355;"></i> Gym Reports</h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <?php foreach (['today' => 'Today', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days'] as $rk => $rl): ?>
                    <a class="menu-type-tab <?php echo $gr_range === $rk ? 'active' : ''; ?>" style="text-decoration:none;padding:7px 14px;" href="?range=<?php echo $rk; ?>"><?php echo $rl; ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <p style="margin:-6px 0 18px;color:#7a6f63;font-size:.85rem;">
            <?php echo htmlspecialchars(date('M j, Y', strtotime($gr_start))); ?> — <?php echo htmlspecialchars(date('M j, Y', strtotime($gr_end))); ?>
        </p>

        <?php if (!$gr_members_ready): ?>
            <?php showAlert('The membership register table (gym_members) has not been created yet — run its migration, then reload this page.', 'error'); ?>
        <?php endif; ?>

        <!-- KPI row: membership -->
        <div class="acct-kpis" style="margin-bottom:20px;">
            <div class="acct-kpi acct-kpi--revenue">
                <div class="acct-kpi__label">Members</div>
                <div class="acct-kpi__value"><?php echo $gr_m['total']; ?></div>
                <div class="acct-kpi__sub"><?php echo $gr_m['active']; ?> active · <?php echo $gr_m['suspended']; ?> suspended · <?php echo $gr_m['expired']; ?> expired</div>
            </div>
            <div class="acct-kpi acct-kpi--cash">
                <div class="acct-kpi__label">New Members</div>
                <div class="acct-kpi__value"><?php echo $gr_m['new_in_range']; ?></div>
                <div class="acct-kpi__sub">Joined in this period</div>
            </div>
            <div class="acct-kpi <?php echo $gr_m['expiring_30d'] > 0 ? 'acct-kpi--vat' : 'acct-kpi--cash'; ?>">
                <div class="acct-kpi__label">Expiring ≤30 Days</div>
                <div class="acct-kpi__value"><?php echo $gr_m['expiring_30d']; ?></div>
                <div class="acct-kpi__sub"><a href="gym-members.php?filter=expiring" class="acct-link">Renewal follow-ups →</a></div>
            </div>
            <div class="acct-kpi <?php echo $gr_outstanding_total > 0 ? 'acct-kpi--receivables' : 'acct-kpi--cash'; ?>">
                <div class="acct-kpi__label">Outstanding</div>
                <div class="acct-kpi__value"><?php echo $gr_currency . number_format($gr_outstanding_total, 0); ?></div>
                <div class="acct-kpi__sub"><?php echo count($gr_outstanding_rows); ?> unpaid account<?php echo count($gr_outstanding_rows) === 1 ? '' : 's'; ?> · churn: <?php echo $gr_m['churn_in_range']; ?></div>
            </div>
        </div>

        <!-- KPI row: attendance + revenue -->
        <div class="acct-kpis" style="margin-bottom:20px;">
            <div class="acct-kpi acct-kpi--cash">
                <div class="acct-kpi__label">Check-Ins</div>
                <div class="acct-kpi__value"><?php echo $gr_a['checkins']; ?></div>
                <div class="acct-kpi__sub"><?php echo $gr_a['unique']; ?> unique member<?php echo $gr_a['unique'] === 1 ? '' : 's'; ?> in period</div>
            </div>
            <div class="acct-kpi acct-kpi--cash">
                <div class="acct-kpi__label">Avg Visit</div>
                <div class="acct-kpi__value"><?php echo $gr_fmt_dur($gr_a['avg_minutes']); ?></div>
                <div class="acct-kpi__sub">Busiest day: <?php echo htmlspecialchars($gr_a['busiest_day'] ?? '—'); ?></div>
            </div>
            <div class="acct-kpi acct-kpi--revenue">
                <div class="acct-kpi__label">In Gym Now</div>
                <div class="acct-kpi__value"><?php echo $gr_a['in_now']; ?></div>
                <div class="acct-kpi__sub"><a href="gym-checkin.php" class="acct-link">Open check-in scanner →</a></div>
            </div>
            <div class="acct-kpi acct-kpi--revenue">
                <div class="acct-kpi__label">Gym Revenue</div>
                <div class="acct-kpi__value"><?php echo $gr_currency . number_format($gr_rev['total'], 0); ?></div>
                <div class="acct-kpi__sub"><?php echo $gr_rev['count']; ?> payment<?php echo $gr_rev['count'] === 1 ? '' : 's'; ?> · <a href="payments.php?booking_type=gym" class="acct-link">ledger →</a></div>
            </div>
        </div>

        <?php if (!$gr_att_ready): ?>
            <?php showAlert('Attendance analytics need the gym_attendance table — run its migration to unlock check-in reporting.', 'info'); ?>
        <?php else: ?>
        <div class="gr-grid-2">
            <div class="gr-section">
                <h3><i class="fas fa-arrow-right-to-bracket" style="color:#2e7d32;"></i> Busiest Check-In Hours</h3>
                <?php if ($gr_a['checkins'] === 0): ?>
                    <p style="color:#9a8f82;margin:0;">No check-ins in this period.</p>
                <?php else: foreach ($gr_hours_in as $h => $c): if ($c === 0) { continue; } ?>
                    <div class="gr-hour-row">
                        <span class="gr-hour-label"><?php echo str_pad((string)$h, 2, '0', STR_PAD_LEFT); ?>:00</span>
                        <div class="gr-hour-track">
                            <div class="acct-bar">
                                <div class="acct-bar__fill" style="width: <?php echo round($c / $gr_hours_in_max * 100); ?>%"></div>
                                <span class="acct-bar__label"><?php echo $c; ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="gr-section">
                <h3><i class="fas fa-arrow-right-from-bracket" style="color:#B18247;"></i> Busiest Check-Out Hours</h3>
                <?php if (array_sum($gr_hours_out) === 0): ?>
                    <p style="color:#9a8f82;margin:0;">No check-outs in this period.</p>
                <?php else: foreach ($gr_hours_out as $h => $c): if ($c === 0) { continue; } ?>
                    <div class="gr-hour-row">
                        <span class="gr-hour-label"><?php echo str_pad((string)$h, 2, '0', STR_PAD_LEFT); ?>:00</span>
                        <div class="gr-hour-track">
                            <div class="acct-bar">
                                <div class="acct-bar__fill" style="width: <?php echo round($c / $gr_hours_out_max * 100); ?>%"></div>
                                <span class="acct-bar__label"><?php echo $c; ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="gr-section">
            <h3><i class="fas fa-calendar-day" style="color:#8B7355;"></i> Daily Visits — Last 14 Days</h3>
            <?php if (empty($gr_daily)): ?>
                <p style="color:#9a8f82;margin:0;">No visits recorded in the last 14 days.</p>
            <?php else: ?>
                <table class="menu-table" style="margin:0;">
                    <thead><tr><th>Date</th><th style="width:120px;">Check-Ins</th><th style="width:140px;">Unique Members</th><th style="width:130px;">Avg Duration</th></tr></thead>
                    <tbody>
                        <?php foreach ($gr_daily as $d): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('D, M j', strtotime((string)$d['d']))); ?><?php echo $d['d'] === date('Y-m-d') ? ' <span class="cat-count" style="margin-left:6px;">Today</span>' : ''; ?></td>
                                <td><?php echo (int)$d['c']; ?></td>
                                <td><?php echo (int)$d['u']; ?></td>
                                <td><?php echo $gr_fmt_dur((int)round((float)($d['avg_min'] ?? 0))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="gr-section">
            <h3><i class="fas fa-file-invoice-dollar" style="color:#c0392b;"></i> Unpaid / Outstanding Balances</h3>
            <?php if (empty($gr_outstanding_rows)): ?>
                <p style="color:#2e7d32;margin:0;"><i class="fas fa-circle-check"></i> No outstanding membership balances — everyone is paid up.</p>
            <?php else: ?>
                <table class="menu-table" style="margin:0;">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th style="width:130px;">Member / Ref</th>
                            <th>Package</th>
                            <th style="width:110px;">Total</th>
                            <th style="width:110px;">Paid</th>
                            <th style="width:110px;">Due</th>
                            <th style="width:110px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gr_outstanding_rows as $o): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($o['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($o['member_number'] ?: $o['reference_number']); ?></td>
                                <td><?php echo htmlspecialchars($o['membership_type'] ?? '—'); ?></td>
                                <td><?php echo $gr_currency . number_format((float)$o['total_with_vat'], 2); ?></td>
                                <td style="color:#2e7d32;"><?php echo $gr_currency . number_format((float)$o['amount_paid'], 2); ?></td>
                                <td style="color:#c0392b;font-weight:700;"><?php echo $gr_currency . number_format((float)$o['amount_due'], 2); ?></td>
                                <td><a href="gym-inquiries.php" class="acct-link">Follow up →</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
