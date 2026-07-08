<?php

/**
 * Gym Calendar & Slot Booking — public schedule page.
 *
 * A guest-facing, no-login calendar of the gym's day. Members (and walk-ins)
 * can see how busy each time slot is and reserve a workout window so the floor
 * never gets overcrowded. Reached from gym.php when slot booking is enabled.
 */
require_once 'config/database.php';
require_once 'includes/page-guard.php';
require_once 'includes/booking-functions.php';
require_once 'includes/validation.php';
require_once 'includes/public-csrf.php';
require_once 'admin/includes/gym-schedule-lib.php';

/** @var PDO $pdo */

$site_name  = getSetting('site_name');
$gymEnabled = isGymEnabled();

// Module gate: when Gym & Fitness is switched off, this page must not exist on
// the public site at all (same intent as gym.php's disabled state). The in-page
// "slot booking unavailable" notice below only covers the gym-on/slots-off case.
if (!$gymEnabled) {
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/'));
    exit;
}

$cfg = gymScheduleSettings();
$currency = (string)getSetting('currency_symbol', 'K');

$slotCsrf = pub_csrf_generate('gym_slot');

// ── Reservation submission (self-POST) ──────────────────────────────────────
$resSuccess = false;
$resError = '';
$resReference = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gym_slot_form'])) {
    if (!$gymEnabled || !$cfg['enabled']) {
        $resError = 'Slot booking is not available right now.';
    } elseif (!pub_csrf_validate($_POST['csrf_token'] ?? '', 'gym_slot')) {
        $resError = 'Your session expired. Please refresh and try again.';
    } elseif (!pub_rate_limit('gym_slot_form', 6, 600)) {
        $resError = 'Too many reservations from this device. Please wait a few minutes.';
    } else {
        $result = gymScheduleCreateReservation($pdo, [
            'slot_date'     => $_POST['slot_date'] ?? '',
            'slot_time'     => $_POST['slot_time'] ?? '',
            'full_name'     => $_POST['full_name'] ?? '',
            'phone'         => $_POST['phone'] ?? '',
            'email'         => $_POST['email'] ?? '',
            'party_size'    => $_POST['party_size'] ?? 1,
            'member_number' => $_POST['member_number'] ?? '',
            'source'        => 'public',
        ]);
        if ($result['ok']) {
            $resSuccess = true;
            $resReference = (string)($result['reference'] ?? '');
        } else {
            $resError = $result['message'];
        }
    }
    // Refresh token after a POST so the next submit has a valid one.
    $slotCsrf = pub_csrf_generate('gym_slot');
}

// ── Which day are we viewing? ───────────────────────────────────────────────
$today = new DateTime('today');
$maxDate = (clone $today)->modify('+' . $cfg['advance_days'] . ' days');
$viewDate = $_GET['date'] ?? $today->format('Y-m-d');
$viewObj = DateTime::createFromFormat('Y-m-d', $viewDate);
if (!$viewObj) { $viewObj = clone $today; }
$viewObj->setTime(0, 0);
if ($viewObj < $today) { $viewObj = clone $today; }
if ($viewObj > $maxDate) { $viewObj = clone $maxDate; }
$viewDate = $viewObj->format('Y-m-d');

$slots = ($gymEnabled && $cfg['enabled']) ? gymScheduleGenerateSlots($pdo, $viewDate) : [];

// Build the day strip (today .. today+advance_days).
$dayStrip = [];
for ($i = 0; $i <= $cfg['advance_days']; $i++) {
    $d = (clone $today)->modify('+' . $i . ' days');
    $dayStrip[] = $d;
}

$prevDate = ($viewObj > $today) ? (clone $viewObj)->modify('-1 day')->format('Y-m-d') : null;
$nextDate = ($viewObj < $maxDate) ? (clone $viewObj)->modify('+1 day')->format('Y-m-d') : null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    $seo_data = [
        'title' => 'Gym Schedule & Slot Booking - ' . $site_name,
        'description' => "See how busy the gym is by the hour and reserve your workout slot at {$site_name}. Plan your visit and skip the crowds.",
        'image' => '/images/gym/hero.jpg',
        'type' => 'website',
    ];
    require_once 'includes/seo-meta.php';
    ?>
    <meta name="format-detection" content="telephone=yes">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript>
        <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    </noscript>
    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/feature-disabled.css">
    <style>
        .gs-wrap { max-width: 980px; margin: 0 auto; padding: 32px 20px 64px; }
        .gs-head { text-align: center; margin-bottom: 24px; }
        .gs-head h1 { font-family: 'Cormorant Garamond', serif; font-size: clamp(2rem, 4vw, 2.8rem); color: #3e3930; margin: 0 0 8px; font-weight: 500; }
        .gs-head p { color: #6d6455; font-size: 1rem; margin: 0 auto; max-width: 620px; }
        .gs-daystrip { display: flex; gap: 8px; overflow-x: auto; padding: 4px 2px 12px; margin-bottom: 20px; scrollbar-width: thin; }
        .gs-day { flex: 0 0 auto; min-width: 78px; text-align: center; text-decoration: none; padding: 10px 12px; border: 1px solid #d8cfc0; border-radius: 10px; background: #fff; color: #5a5147; transition: all .15s; }
        .gs-day:hover { border-color: #8B7355; }
        .gs-day.active { background: #8B7355; border-color: #8B7355; color: #fff; }
        .gs-day .dow { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; opacity: .8; }
        .gs-day .dnum { font-size: 1.35rem; font-weight: 600; line-height: 1.1; }
        .gs-day .dmon { font-size: .7rem; opacity: .8; }
        .gs-navrow { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .gs-navrow h2 { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; color: #3e3930; margin: 0; font-weight: 500; }
        .gs-navbtn { border: 1px solid #d8cfc0; background: #fff; color: #5a5147; border-radius: 8px; padding: 8px 14px; cursor: pointer; text-decoration: none; font-size: .9rem; }
        .gs-navbtn[aria-disabled="true"] { opacity: .4; pointer-events: none; }
        .gs-slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
        .gs-slot { border: 1px solid #e2dacc; border-radius: 12px; padding: 14px 16px; background: #fff; display: flex; flex-direction: column; gap: 8px; }
        .gs-slot.full { background: #f6f2ec; opacity: .75; }
        .gs-slot.past { opacity: .45; }
        .gs-slot__time { font-weight: 600; color: #3e3930; font-size: 1rem; }
        .gs-slot__meter { height: 8px; border-radius: 6px; background: #ece4d8; overflow: hidden; }
        .gs-slot__meter i { display: block; height: 100%; background: linear-gradient(90deg, #8B7355, #C8A45A); }
        .gs-slot__meta { display: flex; justify-content: space-between; align-items: center; font-size: .8rem; color: #6d6455; }
        .gs-slot__cls { font-size: .74rem; color: #8B7355; }
        .gs-badge { font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; padding: 2px 8px; border-radius: 999px; font-weight: 600; }
        .gs-badge.ok { background: #e7f2ea; color: #2e7d32; }
        .gs-badge.low { background: #fdf2e2; color: #b45309; }
        .gs-badge.full { background: #f7e6e6; color: #a03030; }
        .gs-reserve { border: 0; background: #8B7355; color: #fff; border-radius: 8px; padding: 9px 12px; font-weight: 600; cursor: pointer; font-size: .86rem; }
        .gs-reserve:disabled { background: #cfc6b8; cursor: not-allowed; }
        /* Pin the reserve button to the bottom of every card so it lines up in one row
           across all cards, regardless of whether a card has the optional class-name
           line (.gs-slot__cls) above. Full width keeps the buttons uniform too.
           Scoped to .gs-slot so the modal's "Confirm reservation" button is unaffected. */
        .gs-slot .gs-reserve { margin-top: auto; width: 100%; }
        .gs-closed { text-align: center; padding: 48px 20px; color: #9a8f82; }
        .gs-modal-backdrop { position: fixed; inset: 0; background: rgba(30,26,22,.55); display: none; align-items: center; justify-content: center; z-index: 3000; padding: 16px; }
        .gs-modal-backdrop.open { display: flex; }
        .gs-modal { background: #fff; border-radius: 14px; max-width: 440px; width: 100%; padding: 24px; }
        .gs-modal h3 { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; margin: 0 0 4px; color: #3e3930; }
        .gs-modal .sub { color: #6d6455; font-size: .9rem; margin: 0 0 16px; }
        .gs-field { margin-bottom: 12px; }
        .gs-field label { display: block; font-weight: 600; font-size: .84rem; margin-bottom: 4px; color: #5a5147; }
        .gs-field input { width: 100%; padding: 10px; border: 1px solid #d3cbc0; border-radius: 6px; font-size: .95rem; }
        .gs-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 8px; }
        .gs-btn-ghost { background: #f3efe8; border: 0; border-radius: 8px; padding: 10px 16px; cursor: pointer; color: #5a5147; }
        .gs-notice { max-width: 640px; margin: 0 auto 24px; padding: 14px 18px; border-radius: 10px; text-align: center; }
        .gs-notice.ok { background: #e7f2ea; color: #256b30; }
        .gs-notice.err { background: #f7e6e6; color: #9c2a2a; }
        .gs-backlink { display: inline-flex; align-items: center; gap: 6px; color: #8B7355; text-decoration: none; font-size: .9rem; margin-bottom: 16px; }
    </style>
</head>

<body>
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>

    <main id="main-content">
        <?php if (!$gymEnabled || !$cfg['enabled']): ?>
            <div class="gs-wrap">
                <div class="gs-closed">
                    <i class="fas fa-calendar-xmark" style="font-size:2.4rem;color:#c8bdac;"></i>
                    <h1 style="font-family:'Cormorant Garamond',serif;color:#3e3930;">Slot booking isn't available</h1>
                    <p>Online gym slot reservations are currently turned off. Please contact our front desk to plan your visit.</p>
                    <a class="gs-backlink" href="gym.php"><i class="fas fa-arrow-left"></i> Back to the gym</a>
                </div>
            </div>
        <?php else: ?>
            <div class="gs-wrap">
                <a class="gs-backlink" href="gym.php"><i class="fas fa-arrow-left"></i> Back to the gym</a>
                <div class="gs-head">
                    <h1>Plan Your Workout</h1>
                    <p>See how busy the gym is by the hour and reserve your slot so you can train without the crowds.</p>
                </div>

                <?php if ($resSuccess): ?>
                    <div class="gs-notice ok">
                        <strong>You're booked in!</strong> Your reference is <strong><?php echo htmlspecialchars($resReference); ?></strong>. See you at the gym.
                    </div>
                <?php elseif ($resError !== ''): ?>
                    <div class="gs-notice err"><?php echo htmlspecialchars($resError); ?></div>
                <?php endif; ?>

                <!-- Day selector -->
                <div class="gs-daystrip">
                    <?php foreach ($dayStrip as $d): $ds = $d->format('Y-m-d'); ?>
                        <a class="gs-day <?php echo $ds === $viewDate ? 'active' : ''; ?>" href="gym-schedule.php?date=<?php echo $ds; ?>">
                            <span class="dow"><?php echo $d->format('D'); ?></span>
                            <span class="dnum"><?php echo $d->format('j'); ?></span>
                            <span class="dmon"><?php echo $d->format('M'); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="gs-navrow">
                    <?php if ($prevDate): ?><a class="gs-navbtn" href="gym-schedule.php?date=<?php echo $prevDate; ?>">&laquo; Prev</a><?php else: ?><span class="gs-navbtn" aria-disabled="true">&laquo; Prev</span><?php endif; ?>
                    <h2><?php echo $viewObj->format('l, F j'); ?></h2>
                    <?php if ($nextDate): ?><a class="gs-navbtn" href="gym-schedule.php?date=<?php echo $nextDate; ?>">Next &raquo;</a><?php else: ?><span class="gs-navbtn" aria-disabled="true">Next &raquo;</span><?php endif; ?>
                </div>

                <?php if (empty($slots)): ?>
                    <div class="gs-closed">
                        <i class="fas fa-moon" style="font-size:2rem;color:#c8bdac;"></i>
                        <p>The gym is closed on <?php echo $viewObj->format('l'); ?>. Try another day.</p>
                    </div>
                <?php else: ?>
                    <div class="gs-slots">
                        <?php foreach ($slots as $s):
                            $pct = $s['capacity'] > 0 ? round(($s['booked'] / $s['capacity']) * 100) : 0;
                            if ($s['is_full']) { $badgeClass = 'full'; $badgeText = 'Full'; }
                            elseif ($s['remaining'] <= max(1, (int)round($s['capacity'] * 0.25))) { $badgeClass = 'low'; $badgeText = $s['remaining'] . ' left'; }
                            else { $badgeClass = 'ok'; $badgeText = $s['remaining'] . ' free'; }
                        ?>
                            <div class="gs-slot <?php echo $s['is_full'] ? 'full' : ''; ?> <?php echo $s['is_past'] ? 'past' : ''; ?>">
                                <div class="gs-slot__time"><?php echo htmlspecialchars($s['label']); ?></div>
                                <?php if (!empty($s['classes'])): ?>
                                    <div class="gs-slot__cls"><i class="fas fa-users"></i> <?php echo htmlspecialchars(implode(', ', array_map(fn($c) => $c['title'], $s['classes']))); ?></div>
                                <?php endif; ?>
                                <div class="gs-slot__meter"><i style="width:<?php echo min(100, $pct); ?>%;"></i></div>
                                <div class="gs-slot__meta">
                                    <span><?php echo (int)$s['booked']; ?> / <?php echo (int)$s['capacity']; ?> in gym</span>
                                    <span class="gs-badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($badgeText); ?></span>
                                </div>
                                <?php if ($s['is_past']): ?>
                                    <button class="gs-reserve" disabled>Passed</button>
                                <?php elseif ($s['is_full']): ?>
                                    <button class="gs-reserve" disabled>Fully booked</button>
                                <?php else: ?>
                                    <button class="gs-reserve" type="button"
                                        onclick="gsOpen('<?php echo $viewDate; ?>', '<?php echo $s['time']; ?>', '<?php echo htmlspecialchars(addslashes($s['label']), ENT_QUOTES); ?>', <?php echo (int)$s['remaining']; ?>)">
                                        Reserve
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Reservation modal -->
            <div class="gs-modal-backdrop" id="gsModal">
                <form class="gs-modal" method="POST" action="gym-schedule.php?date=<?php echo $viewDate; ?>">
                    <h3>Reserve your slot</h3>
                    <p class="sub" id="gsModalSlot">—</p>
                    <input type="hidden" name="gym_slot_form" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($slotCsrf); ?>">
                    <input type="hidden" name="slot_date" id="gsDate" value="">
                    <input type="hidden" name="slot_time" id="gsTime" value="">
                    <div class="gs-field">
                        <label for="gsName">Your name</label>
                        <input type="text" id="gsName" name="full_name" maxlength="255" required>
                    </div>
                    <div class="gs-field">
                        <label for="gsPhone">Phone <span style="font-weight:400;color:#9a8f82;">(or email below)</span></label>
                        <input type="text" id="gsPhone" name="phone" maxlength="50">
                    </div>
                    <div class="gs-field">
                        <label for="gsEmail">Email <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                        <input type="email" id="gsEmail" name="email" maxlength="255">
                    </div>
                    <div class="gs-field" style="display:flex;gap:12px;">
                        <div style="flex:1;">
                            <label for="gsMember">Member no. <span style="font-weight:400;color:#9a8f82;">(if any)</span></label>
                            <input type="text" id="gsMember" name="member_number" maxlength="32" placeholder="GM-XXXXXX">
                        </div>
                        <div style="width:110px;">
                            <label for="gsParty">People</label>
                            <input type="number" id="gsParty" name="party_size" min="1" max="10" value="1">
                        </div>
                    </div>
                    <div class="gs-modal-actions">
                        <button type="button" class="gs-btn-ghost" onclick="gsClose()">Cancel</button>
                        <button type="submit" class="gs-reserve">Confirm reservation</button>
                    </div>
                </form>
            </div>

            <script>
                function gsOpen(date, time, label, remaining) {
                    document.getElementById('gsDate').value = date;
                    document.getElementById('gsTime').value = time;
                    document.getElementById('gsModalSlot').textContent = label + ' · ' + remaining + ' space' + (remaining === 1 ? '' : 's') + ' left';
                    var p = document.getElementById('gsParty');
                    p.max = remaining;
                    document.getElementById('gsModal').classList.add('open');
                    setTimeout(function () { document.getElementById('gsName').focus(); }, 60);
                }
                function gsClose() { document.getElementById('gsModal').classList.remove('open'); }
                document.getElementById('gsModal').addEventListener('click', function (e) { if (e.target === this) gsClose(); });
            </script>
        <?php endif; ?>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>

</html>
