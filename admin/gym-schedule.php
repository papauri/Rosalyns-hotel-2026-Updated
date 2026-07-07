<?php

/**
 * Gym Schedule (admin) — day calendar of slot activity + opening-hours and
 * slot-booking settings editor.
 *
 * Shows, for any chosen date, the gym's hourly slots with who is booked in and
 * how full each hour is — so staff can see the day's floor load at a glance.
 * Managers configure opening hours per weekday and the slot capacity/window
 * that drive the public schedule page (gym-schedule.php).
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once __DIR__ . '/includes/gym-schedule-lib.php';

/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

if (!hasPermission((int)$user['id'], 'gym')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}
$gs_can_edit = hasPermission((int)$user['id'], 'gym_packages');

gymScheduleEnsureTables($pdo);

$gs_json = static function (bool $ok, string $msg, array $extra = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
};

$gs_days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// ── POST actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gs_action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $gs_json(false, 'Security token invalid — refresh the page.');
    }
    $action = (string)$_POST['gs_action'];
    try {
        if ($action === 'save_settings') {
            if (!$gs_can_edit) { $gs_json(false, 'You do not have permission to change gym settings.'); }
            $enabled  = !empty($_POST['enabled']) ? '1' : '0';
            $capacity = max(1, min(1000, (int)($_POST['capacity'] ?? 20)));
            $duration = max(15, min(240, (int)($_POST['duration'] ?? 60)));
            $advance  = max(0, min(60, (int)($_POST['advance_days'] ?? 7)));
            updateSetting('gym_slot_booking_enabled', $enabled);
            updateSetting('gym_slot_capacity', (string)$capacity);
            updateSetting('gym_slot_duration_minutes', (string)$duration);
            updateSetting('gym_slot_advance_days', (string)$advance);
            $gs_json(true, 'Slot booking settings saved.');
        }

        if ($action === 'save_hours') {
            if (!$gs_can_edit) { $gs_json(false, 'You do not have permission to change opening hours.'); }
            $upd = $pdo->prepare("UPDATE gym_hours SET is_open=?, open_time=?, close_time=? WHERE day_of_week=?");
            for ($d = 0; $d < 7; $d++) {
                $isOpen = !empty($_POST['open_' . $d]) ? 1 : 0;
                $open = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['from_' . $d] ?? '')) ? $_POST['from_' . $d] . ':00' : '06:00:00';
                $close = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['to_' . $d] ?? '')) ? $_POST['to_' . $d] . ':00' : '21:00:00';
                if (strtotime($close) <= strtotime($open)) { $gs_json(false, 'Closing time must be after opening time for ' . $gs_days[$d] . '.'); }
                $upd->execute([$isOpen, $open, $close, $d]);
            }
            $gs_json(true, 'Opening hours saved.');
        }

        if ($action === 'cancel_reservation') {
            $id = (int)($_POST['id'] ?? 0);
            $res = gymScheduleCancelReservation($pdo, (string)$id, false);
            $gs_json($res['ok'], $res['message']);
        }

        if ($action === 'mark_status') {
            $id = (int)($_POST['id'] ?? 0);
            $newStatus = in_array($_POST['status'] ?? '', ['attended', 'no_show', 'booked'], true) ? (string)$_POST['status'] : '';
            if ($newStatus === '') { $gs_json(false, 'Invalid status.'); }
            $pdo->prepare("UPDATE gym_slot_reservations SET status=? WHERE id=?")->execute([$newStatus, $id]);
            $gs_json(true, 'Marked ' . str_replace('_', ' ', $newStatus) . '.');
        }

        $gs_json(false, 'Unknown action.');
    } catch (Throwable $e) {
        error_log('gym-schedule: ' . $e->getMessage());
        $gs_json(false, 'Something went wrong. Please try again.');
    }
}

// ── Data for the view ───────────────────────────────────────────────────────
$cfg = gymScheduleSettings();
$hours = gymScheduleHours($pdo);
$gs_date = $_GET['date'] ?? date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $gs_date)) { $gs_date = date('Y-m-d'); }
$gs_dateObj = new DateTime($gs_date);
$gs_slots = gymScheduleGenerateSlots($pdo, $gs_date);
$gs_reservations = gymScheduleDayReservations($pdo, $gs_date);
$gs_currency = (string)getSetting('currency_symbol', 'K');

// Day totals.
$gs_total_booked = 0;
foreach ($gs_slots as $s) { $gs_total_booked += (int)$s['booked']; }
$gs_prevDate = (clone $gs_dateObj)->modify('-1 day')->format('Y-m-d');
$gs_nextDate = (clone $gs_dateObj)->modify('+1 day')->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            var _t = '<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>';
            var _f = window.fetch;
            window.fetch = function (u, o) {
                if (o && o.body instanceof FormData && !o.body.has('csrf_token')) o.body.append('csrf_token', _t);
                return _f.apply(this, arguments);
            };
        })();
    </script>
    <title>Gym Schedule - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        .gss-grid { display: grid; grid-template-columns: 96px 1fr; gap: 0; border: 1px solid #e8e0d4; border-radius: 10px; overflow: hidden; background: #fff; }
        .gss-row { display: contents; }
        .gss-time { padding: 12px 10px; border-top: 1px solid #efe8dc; border-right: 1px solid #efe8dc; font-weight: 600; color: #5a5147; font-size: .86rem; background: #faf6f0; }
        .gss-cell { padding: 10px 12px; border-top: 1px solid #efe8dc; display: flex; flex-direction: column; gap: 6px; }
        .gss-row:first-child .gss-time, .gss-row:first-child .gss-cell { border-top: 0; }
        .gss-cellhead { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .gss-meter { flex: 1; min-width: 120px; height: 8px; background: #ece4d8; border-radius: 6px; overflow: hidden; }
        .gss-meter i { display: block; height: 100%; background: linear-gradient(90deg, #8B7355, #C8A45A); }
        .gss-count { font-size: .82rem; color: #6d6455; white-space: nowrap; }
        .gss-full { color: #a03030; font-weight: 600; }
        .gss-people { display: flex; flex-wrap: wrap; gap: 6px; }
        .gss-chip { display: inline-flex; align-items: center; gap: 6px; background: #f3efe8; border: 1px solid #e2dacc; border-radius: 999px; padding: 3px 8px 3px 10px; font-size: .78rem; color: #5a5147; }
        .gss-chip.attended { background: #e7f2ea; border-color: #bfe0c8; }
        .gss-chip.no_show { background: #f7e6e6; border-color: #e6c3c3; opacity: .8; }
        .gss-chip button { border: 0; background: none; cursor: pointer; color: #9a8f82; font-size: .8rem; padding: 0 2px; }
        .gss-chip button:hover { color: #8B7355; }
        .gss-empty { color: #b8ad9e; font-size: .82rem; font-style: italic; }
        .gss-classes { font-size: .74rem; color: #8B7355; }
        .gss-daynav { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
        .gss-daynav input[type=date] { padding: 8px 10px; border: 1px solid #d3cbc0; border-radius: 6px; }
        .gss-card { background: #fff; border: 1px solid #e8e0d4; border-radius: 10px; padding: 18px 20px; margin-bottom: 20px; }
        .gss-card h3 { margin: 0 0 12px; font-size: 1.05rem; color: #3e3930; }
        .gss-hours-row { display: grid; grid-template-columns: 110px 70px 1fr 1fr; gap: 10px; align-items: center; margin-bottom: 8px; }
        .gss-hours-row input[type=time] { padding: 7px 8px; border: 1px solid #d3cbc0; border-radius: 6px; width: 100%; }
        .gss-settings { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; }
        .gss-settings label { display: block; font-weight: 600; font-size: .82rem; margin-bottom: 4px; color: #5a5147; }
        .gss-settings input { width: 100%; padding: 8px 10px; border: 1px solid #d3cbc0; border-radius: 6px; }
        @media (max-width: 640px) { .gss-grid { grid-template-columns: 72px 1fr; } .gss-hours-row { grid-template-columns: 1fr 1fr; } }
    </style>
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title">Gym Schedule</h2>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <span class="cat-count" title="Reservations booked for this day"><i class="fas fa-calendar-check"></i> Booked <?php echo $gs_date === date('Y-m-d') ? 'today' : 'this day'; ?>: <?php echo (int)$gs_total_booked; ?></span>
                <span class="cat-count" title="Slot booking status">
                    <i class="fas fa-<?php echo $cfg['enabled'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
                    Booking <?php echo $cfg['enabled'] ? 'ON' : 'OFF'; ?>
                </span>
                <a class="btn btn-secondary btn-sm" href="../gym-schedule.php" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> Public page</a>
            </div>
        </div>

        <!-- Day navigator + calendar -->
        <div class="gss-card">
            <div class="gss-daynav">
                <a class="btn btn-secondary btn-sm" href="gym-schedule.php?date=<?php echo $gs_prevDate; ?>">&laquo; Prev</a>
                <form method="get" style="display:flex;gap:8px;align-items:center;">
                    <input type="date" name="date" value="<?php echo htmlspecialchars($gs_date); ?>" onchange="this.form.submit()">
                </form>
                <a class="btn btn-secondary btn-sm" href="gym-schedule.php?date=<?php echo $gs_nextDate; ?>">Next &raquo;</a>
                <strong style="margin-left:6px;font-family:'Cormorant Garamond',serif;font-size:1.3rem;color:#3e3930;"><?php echo $gs_dateObj->format('l, F j, Y'); ?></strong>
                <?php if ($gs_date !== date('Y-m-d')): ?><a class="btn btn-link btn-sm" href="gym-schedule.php">Today</a><?php endif; ?>
            </div>

            <?php if (empty($gs_slots)): ?>
                <p class="gss-empty" style="padding:20px;text-align:center;">The gym is closed on <?php echo $gs_dateObj->format('l'); ?> (set opening hours below to open it).</p>
            <?php else: ?>
                <div class="gss-grid">
                    <?php foreach ($gs_slots as $s):
                        $people = $gs_reservations[$s['time']] ?? [];
                        $pct = $s['capacity'] > 0 ? min(100, round(($s['booked'] / $s['capacity']) * 100)) : 0;
                    ?>
                        <div class="gss-row">
                            <div class="gss-time"><?php echo date('g:i A', strtotime($s['time'])); ?></div>
                            <div class="gss-cell">
                                <div class="gss-cellhead">
                                    <div class="gss-meter"><i style="width:<?php echo $pct; ?>%;"></i></div>
                                    <span class="gss-count <?php echo $s['is_full'] ? 'gss-full' : ''; ?>"><?php echo (int)$s['booked']; ?> / <?php echo (int)$s['capacity']; ?><?php echo $s['is_full'] ? ' · FULL' : ''; ?></span>
                                </div>
                                <?php if (!empty($s['classes'])): ?>
                                    <div class="gss-classes"><i class="fas fa-users"></i> <?php echo htmlspecialchars(implode(', ', array_map(fn($c) => $c['title'], $s['classes']))); ?></div>
                                <?php endif; ?>
                                <?php if (empty($people)): ?>
                                    <span class="gss-empty">No reservations</span>
                                <?php else: ?>
                                    <div class="gss-people">
                                        <?php foreach ($people as $r): ?>
                                            <span class="gss-chip <?php echo htmlspecialchars($r['status']); ?>" title="<?php echo htmlspecialchars(trim(($r['phone'] ?? '') . ' ' . ($r['email'] ?? '') . ' · ' . $r['reference'])); ?>">
                                                <?php echo htmlspecialchars($r['full_name']); ?><?php echo (int)$r['party_size'] > 1 ? ' &times;' . (int)$r['party_size'] : ''; ?>
                                                <?php if ($r['status'] === 'attended'): ?><i class="fas fa-check" style="color:#2e7d32;"></i><?php endif; ?>
                                                <?php if ($gs_can_edit && $r['status'] === 'booked'): ?>
                                                    <button type="button" title="Mark attended" onclick="gsMark(<?php echo (int)$r['id']; ?>,'attended')"><i class="fas fa-check"></i></button>
                                                    <button type="button" title="Cancel" onclick="gsCancel(<?php echo (int)$r['id']; ?>)"><i class="fas fa-times"></i></button>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($gs_can_edit): ?>
        <!-- Slot booking settings -->
        <div class="gss-card">
            <h3><i class="fas fa-sliders-h"></i> Slot Booking Settings</h3>
            <form id="gsSettingsForm" onsubmit="return gsSaveSettings(event)">
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="enabled" value="1" <?php echo $cfg['enabled'] ? 'checked' : ''; ?>>
                    Enable public slot booking (shows the schedule page + reserve buttons on the gym site)
                </label>
                <div class="gss-settings">
                    <div>
                        <label>Capacity per slot</label>
                        <input type="number" name="capacity" min="1" max="1000" value="<?php echo (int)$cfg['capacity']; ?>">
                        <small style="color:#9a8f82;">Max people on the floor per slot.</small>
                    </div>
                    <div>
                        <label>Slot length (minutes)</label>
                        <input type="number" name="duration" min="15" max="240" step="15" value="<?php echo (int)$cfg['duration']; ?>">
                    </div>
                    <div>
                        <label>Bookable days ahead</label>
                        <input type="number" name="advance_days" min="0" max="60" value="<?php echo (int)$cfg['advance_days']; ?>">
                    </div>
                </div>
                <div style="margin-top:14px;"><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-save"></i> Save settings</button></div>
            </form>
        </div>

        <!-- Opening hours -->
        <div class="gss-card">
            <h3><i class="fas fa-clock"></i> Opening Hours</h3>
            <form id="gsHoursForm" onsubmit="return gsSaveHours(event)">
                <?php for ($d = 0; $d < 7; $d++): $h = $hours[$d]; ?>
                    <div class="gss-hours-row">
                        <label style="display:flex;align-items:center;gap:6px;font-weight:600;cursor:pointer;">
                            <input type="checkbox" name="open_<?php echo $d; ?>" value="1" <?php echo $h['is_open'] ? 'checked' : ''; ?>>
                            <?php echo $gs_days[$d]; ?>
                        </label>
                        <span style="font-size:.8rem;color:#9a8f82;">Open</span>
                        <input type="time" name="from_<?php echo $d; ?>" value="<?php echo htmlspecialchars($h['open_time']); ?>">
                        <input type="time" name="to_<?php echo $d; ?>" value="<?php echo htmlspecialchars($h['close_time']); ?>">
                    </div>
                <?php endfor; ?>
                <div style="margin-top:14px;"><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-save"></i> Save hours</button></div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
    <script>
        var GS_CSRF = <?php echo json_encode($csrf_token); ?>;
        function gsToast(m, ok) { if (typeof Alert !== 'undefined' && Alert.show) { Alert.show(m, ok ? 'success' : 'error'); } }
        function gsPost(fd) {
            fd.append('csrf_token', GS_CSRF);
            return fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { gsToast(d.message || (d.success ? 'Saved.' : 'Failed.'), !!d.success); if (d.success) setTimeout(function () { location.reload(); }, 650); return d; })
                .catch(function () { gsToast('Network error — please try again.', false); });
        }
        function gsSaveSettings(e) { e.preventDefault(); var fd = new FormData(document.getElementById('gsSettingsForm')); fd.append('gs_action', 'save_settings'); gsPost(fd); return false; }
        function gsSaveHours(e) { e.preventDefault(); var fd = new FormData(document.getElementById('gsHoursForm')); fd.append('gs_action', 'save_hours'); gsPost(fd); return false; }
        function gsCancel(id) { var fd = new FormData(); fd.append('gs_action', 'cancel_reservation'); fd.append('id', id); gsPost(fd); }
        function gsMark(id, status) { var fd = new FormData(); fd.append('gs_action', 'mark_status'); fd.append('id', id); fd.append('status', status); gsPost(fd); }
        window.gsSaveSettings = gsSaveSettings; window.gsSaveHours = gsSaveHours; window.gsCancel = gsCancel; window.gsMark = gsMark;
    </script>
</body>

</html>
