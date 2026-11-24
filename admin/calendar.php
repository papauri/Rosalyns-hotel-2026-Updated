<?php

/**
 * Calendar: Room Timeline (default) and Month view (?view=month), plus compact Housekeeping and
 * Maintenance tabs (?tab=housekeeping|maintenance). Hotel Website - Admin Panel.
 * Both calendar views read admin/includes/calendar-data.php, so they always agree.
 */

// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
require_once __DIR__ . '/../includes/booked-rate.php'; // booked rate × nights in the booking popup
require_once __DIR__ . '/includes/front-desk-counts.php'; // missed-arrival rule shared with the Room Dashboard
require_once __DIR__ . '/includes/calendar-data.php'; // the one data source of the Timeline and the Month view

$calUserId = (int)($user['id'] ?? 0);

// Tabs: Calendar | Housekeeping | Maintenance. A tab the user cannot open (permission or module) is hidden.
$calCanHkTab = rhCanLinkTo($calUserId, 'housekeeping.php');
$calCanMtTab = rhCanLinkTo($calUserId, 'room-maintenance.php');
$calTab = is_scalar($_GET['tab'] ?? null) ? (string)$_GET['tab'] : 'calendar';
if (($calTab === 'housekeeping' && !$calCanHkTab) || ($calTab === 'maintenance' && !$calCanMtTab)
    || !in_array($calTab, ['calendar', 'housekeeping', 'maintenance'], true)) {
    $calTab = 'calendar';
}

// View: Timeline by default. Old links that carry ?year= / ?month= (and no view) still open the Month view.
$calViewRaw = is_scalar($_GET['view'] ?? null) ? (string)$_GET['view'] : '';
if ($calViewRaw === '') {
    $calViewRaw = (isset($_GET['year']) || isset($_GET['month'])) ? 'month' : 'timeline';
}
$calView = $calViewRaw === 'month' ? 'month' : 'timeline';

// Embedded (?embed=1): the Room Timeline alone inside bookings.php's List | Calendar switch (BOOKINGS_LIST_PLAN
// Decision 4, T11). Same login + permission checks (admin-init.php above); no sidebar, header or calendar tabs
// (admin-header.php / admin-footer.php read $rh_admin_embed); links that leave the timeline open in the top window.
$calEmbed = is_scalar($_GET['embed'] ?? null) && (string)$_GET['embed'] === '1';
$rh_admin_embed = $calEmbed;
if ($calEmbed) {
    $calTab = 'calendar';
    $calView = 'timeline';
}

// Get date parameters
$currentYear  = isset($_GET['year'])  ? intval($_GET['year'])  : date('Y');
$currentMonth = isset($_GET['month']) ? intval($_GET['month']) : date('m');

// Filter parameters (used for JS data attributes + PHP pre-filtering)
$filterRoomType   = isset($_GET['filter_room_type'])   ? intval($_GET['filter_room_type'])             : 0;
$filterRoomId     = isset($_GET['filter_room_id'])     ? intval($_GET['filter_room_id'])               : 0;
$filterStatus     = isset($_GET['filter_status'])      ? trim($_GET['filter_status'])                  : '';
$filterFloor      = isset($_GET['filter_floor'])       ? trim($_GET['filter_floor'])                   : '';
$filterSearch     = isset($_GET['filter_search'])      ? trim($_GET['filter_search'])                  : '';

// Whitelist filter_status to prevent XSS
$allowedStatuses = ['available', 'occupied', 'reserved', 'cleaning', 'maintenance', 'out_of_order', 'inspection'];
if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = '';
}
// Sanitise floor to alphanumeric
$filterFloor = preg_replace('/[^A-Za-z0-9\s\-]/', '', $filterFloor);
$filterSearchRaw = $filterSearch; // the Timeline escapes on output (JSON + textContent)
$filterSearch = htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8');

// Build query-string for preserving filters when navigating months
$filterQs = '';
if ($filterRoomType) $filterQs .= '&filter_room_type=' . $filterRoomType;
if ($filterRoomId)   $filterQs .= '&filter_room_id='   . $filterRoomId;
if ($filterStatus)   $filterQs .= '&filter_status='    . urlencode($filterStatus);
if ($filterFloor)    $filterQs .= '&filter_floor='     . urlencode($filterFloor);
if ($filterSearch)   $filterQs .= '&filter_search='    . urlencode($filterSearch);

// Keep the year sane (a hand-typed ?year=0 or 99999 must not build impossible dates).
$currentYear = max(2000, min(2100, (int)$currentYear));

// Validate month
if ($currentMonth < 1) {
    $currentMonth = 12;
    $currentYear--;
} elseif ($currentMonth > 12) {
    $currentMonth = 1;
    $currentYear++;
}

// Get previous and next month
$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Today's date for highlighting
$today = date('Y-m-d');

// Counts on the Housekeeping / Maintenance tabs (open tasks, open maintenance).
$calHkOpenCount = 0;
$calMtOpenCount = 0;
try {
    if ($calCanHkTab) {
        $calHkOpenCount = (int)$pdo->query("SELECT COUNT(*) FROM housekeeping_assignments WHERE status IN ('pending', 'in_progress', 'blocked')")->fetchColumn();
    }
    if ($calCanMtTab) {
        $calMtOpenCount = (int)$pdo->query("SELECT COUNT(*) FROM room_maintenance_schedules WHERE COALESCE(status, '') NOT IN ('completed', 'cancelled', 'verified')")->fetchColumn();
    }
} catch (PDOException $e) {
    error_log('calendar tab counts: ' . $e->getMessage());
}

$calIsMonth = $calTab === 'calendar' && $calView === 'month';

if ($calIsMonth) {
    // Month view: the month's nights from the shared data source (same rooms, lanes, blocks and bookings as before).
    $startDate = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
    $endDate = date('Y-m-t', strtotime($startDate)); // real last day: '-31' is an invalid DATE in short months and throws under strict SQL
    $calData = rh_calendar_load($pdo, $startDate, $endDate);
    $individualRooms = $calData['rooms'];
    $laneOfRoom = $calData['laneOfRoom'];   // room id => the lane (room) id its nights are drawn on
    $laneRoomIds = $calData['laneRoomIds']; // lane id => all room ids on that lane
    $allFloors = $calData['floors'];
    $roomTypes = $calData['roomTypes'];
    $blockedDates = $calData['blockedDates'];
    $blockedDatesByDate = $calData['blockedDatesByDate'];
    /** @var array<string, array<string, list<array<string, mixed>>>> $bookingsByDate */
    $bookingsByDate = $calData['bookingsByDate'];
    $bookings = $calData['bookings'];
    if ($calData['error'] !== null) {
        $error = $calData['error'];
    }
    if ($filterRoomId && isset($laneOfRoom[$filterRoomId])) {
        $filterRoomId = $laneOfRoom[$filterRoomId];
    }

    // Get days in month
    $daysInMonth = date('t', mktime(0, 0, 0, $currentMonth, 1, $currentYear));
    $firstDayOfWeek = date('w', mktime(0, 0, 0, $currentMonth, 1, $currentYear));

    // Month names
    $monthNames = [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December'
    ];

    // Calendar summary metrics for the header
    $activeRoomsCount = count($individualRooms);
    $activeBookingsCount = count($bookings);
    $blockedDayCount = count($blockedDatesByDate);
    $blockedEntryCount = 0;
    foreach ($blockedDatesByDate as $dayBlocks) {
        $blockedEntryCount += count($dayBlocks);
    }

    $calendarMonthLabel = $monthNames[(int)$currentMonth] . ' ' . $currentYear;
} elseif ($calTab === 'calendar') {
    // Timeline: first paint from the same payload the range endpoint (api/calendar-timeline.php) returns.
    $tlDays = (int)($_GET['days'] ?? 14);
    if (!in_array($tlDays, [3, 7, 14, 30], true)) {
        $tlDays = 14;
    }
    $tlStart = rh_cal_valid_date($_GET['start'] ?? '') ?? $today;
    $tlPayload = rh_cal_timeline_payload($pdo, $tlStart, $tlDays, [
        'user_id' => $calUserId,
        'role' => (string)($user['role'] ?? ''),
    ]);
    if (!empty($tlPayload['error'])) {
        $error = $tlPayload['error'];
    }
    // Filters carried in from other pages (e.g. individual-rooms.php links ?filter_room_id=).
    $tlRoomLane = 0;
    if ($filterRoomId > 0) {
        foreach ($tlPayload['lanes'] as $tlLane) {
            if (in_array($filterRoomId, $tlLane['room_ids'], true)) {
                $tlRoomLane = (int)$tlLane['id'];
            }
        }
    }
    $tlInitial = [
        'type' => $filterRoomType,
        'room' => $tlRoomLane,
        'floor' => $filterFloor,
        'search' => $filterSearchRaw,
        'days_set' => isset($_GET['days']),
        // Room state filter carried in from bookings.php (Arriving today -> arr, Leaving today -> dep, In house -> occ).
        'status' => (is_scalar($_GET['tl_status'] ?? null) && in_array((string)$_GET['tl_status'], ['free', 'occ', 'arr', 'dep', 'dirty', 'oos'], true)) ? (string)$_GET['tl_status'] : '',
        'from' => (is_scalar($_GET['from'] ?? null) && (string)$_GET['from'] === 'bookings') ? 'bookings' : '',
    ];
} elseif ($calTab === 'housekeeping') {
    $calHkFilter = (is_scalar($_GET['hk'] ?? null) && in_array((string)$_GET['hk'], ['dirty', 'cleaning', 'inspection', 'clean', 'unassigned'], true)) ? (string)$_GET['hk'] : '';
    $calHk = rh_cal_hk_tab($pdo, $calHkFilter, $today);
} else {
    $calMtFilter = (is_scalar($_GET['mt'] ?? null) && in_array((string)$_GET['mt'], ['progress', 'due'], true)) ? (string)$_GET['mt'] : '';
    $calMt = rh_cal_maint_tab($pdo, $calMtFilter, $today);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-kpi.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-kpi.css'); ?>">
    <link rel="stylesheet" href="css/admin-cockpit.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-cockpit.css'); ?>">
    <link rel="stylesheet" href="css/calendar.css?v=<?php echo @filemtime(__DIR__ . '/css/calendar.css'); ?>">
    <link rel="stylesheet" href="css/room-timeline.css?v=<?php echo @filemtime(__DIR__ . '/css/room-timeline.css'); ?>">
<?php if ($calEmbed): ?>
    <?php /* No href: only the default target changes, so fetch() and relative links resolve as before. */ ?>
    <base target="_top">
    <script>
        window.__rhPresencePing = true; /* the outer bookings.php already reports where this person is */
        // While the booking panel (side panel or phone sheet) is open, tell the outer page, so its floating Assistant,
        // notice and help buttons step aside and never cover the panel's buttons (as on the Calendar page itself).
        document.addEventListener('DOMContentLoaded', function () {
            var outer = null;
            try { outer = window.parent !== window ? window.parent.document.documentElement : null; } catch (e) { outer = null; }
            var dr = document.querySelector('[data-tl-drawer]');
            if (!outer) return;
            var sync = function () {
                var open = document.documentElement.classList.contains('rh-tl-open') || (!!dr && !dr.hidden && dr.classList.contains('is-open'));
                outer.classList.toggle('rh-bk-cal-panel', open);
            };
            var mo = new MutationObserver(sync);
            mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
            if (dr) mo.observe(dr, { attributes: true, attributeFilter: ['class', 'hidden'] });
            window.addEventListener('pagehide', function () { outer.classList.remove('rh-bk-cal-panel'); });
            sync();
        });
    </script>
    <style>
        /* No admin header in the frame: the side panel starts at the top and the grid gets the header's height. */
        html:root { --admin-header-height: 0px; }
        html, body.rh-embed { background: var(--admin-bg, #f5f6f8); }
        body.rh-embed > #rh-admin-page { padding-top: 0 !important; margin-left: 0 !important; width: 100% !important; }
        body.rh-embed > #rh-admin-page > .content { margin: 0 !important; padding: 8px !important; max-width: none !important; }
        body.rh-embed .tl-view a.ck-tab[href*="view=month"] { display: none; }
    </style>
<?php endif; ?>
</head>

<body<?php echo $calEmbed ? ' class="rh-embed"' : ''; ?>>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <?php if (!$calEmbed) { require __DIR__ . '/includes/calendar-shell.php'; } ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!$calIsMonth): ?>
            <?php require __DIR__ . '/includes/calendar-timeline-view.php'; ?>
    </div>
        <?php else: ?>
        <div class="calendar-container">
            <div class="calendar-header calendar-page-header">
                <div class="calendar-header-main">
                    <h2><?php echo htmlspecialchars($calendarMonthLabel); ?></h2>
                    <p class="calendar-header-meta">
                        <?php echo (int)$activeRoomsCount; ?> active rooms,
                        <?php echo (int)$activeBookingsCount; ?> active bookings,
                        <?php echo (int)$blockedDayCount; ?> blocked days
                        (<?php echo (int)$blockedEntryCount; ?> entries)
                    </p>
                </div>
                <div class="calendar-nav">
                    <a href="?year=<?php echo $prevYear; ?>&month=<?php echo $prevMonth;
                                                                    echo $filterQs; ?>" aria-label="View previous month">
                        <i class="fas fa-chevron-left" aria-hidden="true"></i> Previous
                    </a>
                    <a class="current" href="?year=<?php echo (int)date('Y'); ?>&month=<?php echo (int)date('n');
                                                                    echo $filterQs; ?>" aria-label="Back to the current month">Today</a>
                    <a href="?year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth;
                                                                    echo $filterQs; ?>" aria-label="View next month">
                        Next <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

            <div class="legend calendar-legend" role="group" aria-label="Calendar status legend">
                <div class="legend-item">
                    <span class="legend-dot available" aria-hidden="true"></span>
                    <span>Available (no booking)</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot reserved" aria-hidden="true"></span>
                    <span>Reserved (future stay)</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot occupied" aria-hidden="true"></span>
                    <span>Occupied (checked-in / active stay)</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot cleaning" aria-hidden="true"></span>
                    <span>Cleaning</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot maintenance" aria-hidden="true"></span>
                    <span>Maintenance</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot out_of_order" aria-hidden="true"></span>
                    <span>Out of order</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot blocked" aria-hidden="true"></span>
                    <span>Blocked date (room-type or individual)</span>
                </div>
            </div>

            <!-- ══ FILTER BAR ══ -->
            <div class="cal-filter-bar" id="calFilterBar">
                <div class="cal-filter-bar__inner">
                    <!-- Search by room number / name -->
                    <div class="cal-filter-group">
                        <label for="cf-search" class="cal-filter-label">
                            <i class="fas fa-search"></i> Search room
                        </label>
                        <input type="text" id="cf-search" class="cal-filter-input"
                            placeholder="Room number or name…"
                            value="<?php echo htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <!-- Room type -->
                    <div class="cal-filter-group">
                        <label for="cf-type" class="cal-filter-label">
                            <i class="fas fa-layer-group"></i> Room type
                        </label>
                        <select id="cf-type" class="cal-filter-select">
                            <option value="">All types</option>
                            <?php foreach ($roomTypes as $rt): ?>
                                <option value="<?php echo (int)$rt['id']; ?>"
                                    <?php echo ($filterRoomType === (int)$rt['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($rt['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Individual room -->
                    <div class="cal-filter-group">
                        <label for="cf-room" class="cal-filter-label">
                            <i class="fas fa-door-open"></i> Individual room
                        </label>
                        <select id="cf-room" class="cal-filter-select">
                            <option value="">All rooms</option>
                            <?php foreach ($individualRooms as $ir): ?>
                                <option value="<?php echo (int)$ir['id']; ?>"
                                    data-type="<?php echo (int)$ir['room_type_id']; ?>"
                                    <?php echo ($filterRoomId === (int)$ir['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ir['room_number'] . ($ir['room_name'] ? ' – ' . $ir['room_name'] : '') . ' (' . $ir['room_type_name'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status -->
                    <div class="cal-filter-group">
                        <label for="cf-status" class="cal-filter-label">
                            <i class="fas fa-circle-half-stroke"></i> Current status
                        </label>
                        <select id="cf-status" class="cal-filter-select">
                            <option value="">All statuses</option>
                            <option value="available" <?php echo $filterStatus === 'available'   ? 'selected' : ''; ?>>Available</option>
                            <option value="occupied" <?php echo $filterStatus === 'occupied'    ? 'selected' : ''; ?>>Occupied</option>
                            <option value="reserved" <?php echo $filterStatus === 'reserved'    ? 'selected' : ''; ?>>Reserved</option>
                            <option value="cleaning" <?php echo $filterStatus === 'cleaning'    ? 'selected' : ''; ?>>Cleaning</option>
                            <option value="maintenance" <?php echo $filterStatus === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="out_of_order" <?php echo $filterStatus === 'out_of_order' ? 'selected' : ''; ?>>Out of order</option>
                            <option value="inspection" <?php echo $filterStatus === 'inspection'  ? 'selected' : ''; ?>>Inspection</option>
                        </select>
                    </div>

                    <?php if (!empty($allFloors)): ?>
                        <!-- Floor -->
                        <div class="cal-filter-group">
                            <label for="cf-floor" class="cal-filter-label">
                                <i class="fas fa-building"></i> Floor
                            </label>
                            <select id="cf-floor" class="cal-filter-select">
                                <option value="">All floors</option>
                                <?php foreach ($allFloors as $fl): ?>
                                    <option value="<?php echo htmlspecialchars($fl, ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo ($filterFloor === $fl) ? 'selected' : ''; ?>>
                                        Floor <?php echo htmlspecialchars($fl, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="cal-filter-bar__actions">
                    <span id="calFilterCount" class="cal-filter-count"></span>
                    <button type="button" id="calFilterClear" class="cal-filter-clear" style="display:none">
                        <i class="fas fa-times"></i> Clear filters
                    </button>
                </div>
            </div>
            <!-- ══ / FILTER BAR ══ -->

            <?php
            // Bookings that still need a room, per room type (rt_<type> keys).
            $unassignedByType = [];
            foreach ($bookingsByDate as $dKey => $lanes) {
                foreach ($lanes as $laneKey => $laneBookings) {
                    if (strpos($laneKey, 'rt_') !== 0) {
                        continue;
                    }
                    foreach ($laneBookings as $ub) {
                        $unassignedByType[(int)substr($laneKey, 3)][(int)$ub['id']] = $ub;
                    }
                }
            }
            ?>
            <?php if ($unassignedByType): ?>
                <div class="cal-unassigned">
                    <h3><i class="fas fa-triangle-exclamation"></i> Bookings without a room this month</h3>
                    <p>These hold the room type but no specific room yet. Assign a room before arrival.</p>
                    <?php foreach ($unassignedByType as $typeId => $typeBookings): ?>
                        <?php
                        $typeName = '';
                        foreach ($roomTypes as $rt) {
                            if ((int)$rt['id'] === $typeId) {
                                $typeName = (string)$rt['name'];
                            }
                        }
                        ?>
                        <div class="cal-unassigned__type">
                            <strong><?php echo htmlspecialchars($typeName !== '' ? $typeName : ('Room type #' . $typeId)); ?></strong>
                            <ul>
                                <?php foreach ($typeBookings as $ub): ?>
                                    <li>
                                        <a href="booking-details.php?id=<?php echo (int)$ub['id']; ?>"><?php echo htmlspecialchars($ub['booking_reference']); ?></a>
                                        &middot; <?php echo htmlspecialchars($ub['guest_name']); ?><?php echo trim((string)($ub['room_guest_name'] ?? '')) !== '' ? ' (' . htmlspecialchars((string)$ub['room_guest_name']) . ')' : ''; ?>
                                        &middot; <?php echo date('j M', strtotime($ub['check_in_date'])); ?> &ndash; <?php echo date('j M', strtotime($ub['check_out_date'])); ?>
                                        &middot; <?php echo htmlspecialchars(ucfirst($ub['status'])); ?>
                                        <?php if ($ub['status'] === 'confirmed'): /* late arrivals can be given a room too */ ?>
                                            &middot; <a href="bookings.php?action=assign-room&amp;booking_id=<?php echo (int)$ub['id']; ?>">Assign room</a>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($individualRooms)): ?>
                <div class="room-calendars">
                    <?php foreach ($individualRooms as $indRoom): ?>
                        <?php
                        $roomKey = 'ir_' . $indRoom['id'];
                        $roomNumber = htmlspecialchars($indRoom['room_number']);
                        $roomName = htmlspecialchars($indRoom['room_name'] ?? '');
                        $roomTypeName = htmlspecialchars($indRoom['room_type_name']);
                        $floor = htmlspecialchars($indRoom['floor'] ?? '');
                        $displayTitle = $roomNumber . ($roomName ? ' - ' . $roomName : '') . ' (' . $roomTypeName . ')';
                        if ($floor) {
                            $displayTitle .= ' [Floor ' . $floor . ']';
                        }
                        ?>
                        <div class="room-calendar individual-room-calendar"
                            data-room-id="<?php echo (int)$indRoom['id']; ?>"
                            data-room-type-id="<?php echo (int)$indRoom['room_type_id']; ?>"
                            data-room-status="<?php echo htmlspecialchars($indRoom['status'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-room-floor="<?php echo htmlspecialchars($indRoom['floor'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-room-search="<?php echo htmlspecialchars(strtolower($indRoom['room_number'] . ' ' . ($indRoom['room_name'] ?? '') . ' ' . $indRoom['room_type_name']), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="room-header">
                                <h3><?php echo $displayTitle; ?></h3>
                                <span class="room-price">
                                    <?php echo getSetting('currency_symbol') . ' ' . number_format($indRoom['price_per_night'], 2); ?>/night
                                </span>
                                <span class="badge badge-<?php echo $indRoom['status']; ?> current-status-badge">
                                    <?php echo ucfirst($indRoom['status']); ?>
                                </span>
                            </div>

                            <div class="calendar-grid">
                                <!-- Day headers -->
                                <div class="calendar-day-header">Sun</div>
                                <div class="calendar-day-header">Mon</div>
                                <div class="calendar-day-header">Tue</div>
                                <div class="calendar-day-header">Wed</div>
                                <div class="calendar-day-header">Thu</div>
                                <div class="calendar-day-header">Fri</div>
                                <div class="calendar-day-header">Sat</div>

                                <!-- Empty days before first day of month -->
                                <?php for ($i = 0; $i < $firstDayOfWeek; $i++): ?>
                                    <div class="calendar-day empty"></div>
                                <?php endfor; ?>

                                <!-- Days of the month -->
                                <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                                    <?php
                                    $dateKey = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
                                    $isToday = ($dateKey === $today);
                                    // Get timeline-aware status for this date
                                    $timelineStatus = getTimelineAwareRoomStatus($indRoom, $dateKey, $bookingsByDate);
                                    $statusClass = 'status-' . $timelineStatus;
                                    ?>
                                    <div class="calendar-day <?php echo $isToday ? 'today' : ''; ?> <?php echo $statusClass; ?>">
                                        <div class="day-number"><?php echo $day; ?></div>

                                        <?php
                                        // Check if this date is blocked for this room type, all rooms, or individual room
                                        $isBlocked = false;
                                        if (isset($blockedDatesByDate[$dateKey])) {
                                            foreach ($blockedDatesByDate[$dateKey] as $blocked) {
                                                $showBlock = false;
                                                $blockScope = $blocked['block_scope'] ?? 'type';

                                                if ($blockScope === 'type') {
                                                    // Room-type level block - check if it applies to this room type or all rooms
                                                    if ($blocked['room_id'] == $indRoom['room_type_id'] || $blocked['room_id'] === null) {
                                                        $showBlock = true;
                                                    }
                                                } else {
                                                    // Individual room level block - check if it applies to this specific individual room
                                                    if ($blocked['individual_room_id'] == $indRoom['id']) {
                                                        $showBlock = true;
                                                    }
                                                }

                                                if ($showBlock) {
                                                    $isBlocked = true;
                                                    $blockType = htmlspecialchars($blocked['block_type']);
                                                    $blockReason = htmlspecialchars($blocked['reason'] ?? 'No reason provided');
                                                    $scopeLabel = $blockScope === 'individual' ? 'Individual Room' : 'Room Type';
                                        ?>
                                                    <div class="blocked-indicator <?php echo $blockScope === 'individual' ? 'blocked-individual' : ''; ?>"
                                                        title="Blocked (<?php echo $scopeLabel; ?>): <?php echo $blockType; ?> - <?php echo $blockReason; ?>"
                                                        onclick="window.location.href='blocked-dates.php'">
                                                        <?php echo ucfirst($blockType); ?>
                                                        <?php if ($blockScope === 'individual'): ?>
                                                            <span class="block-scope-badge">Individual</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php
                                                }
                                            }
                                        }

                                        // Bookings always show; a booking on a blocked night is a conflict and is flagged.
                                        if (isset($bookingsByDate[$dateKey][$roomKey])) {
                                            $dayBookings = $bookingsByDate[$dateKey][$roomKey];
                                            foreach ($dayBookings as $booking) {
                                                $statusClass = strtolower(str_replace('-', '_', $booking['status']));
                                                $isMissedArrival = rh_fd_is_missed_arrival((string)$booking['status'], (string)$booking['check_in_date'], $today); // confirmed or pending, as the Room Dashboard counts
                                                $isOverdueStay = $booking['status'] === 'checked-in' && (string)$booking['check_out_date'] <= $dateKey && $dateKey <= $today;
                                                if ($isMissedArrival) {
                                                    $statusClass .= ' is-missed-arrival';
                                                }
                                                if ($isOverdueStay) {
                                                    $statusClass .= ' is-overdue-stay';
                                                }
                                                if ($isBlocked) {
                                                    $statusClass .= ' is-block-conflict';
                                                }
                                                $guestName = htmlspecialchars($booking['guest_name'], ENT_QUOTES, 'UTF-8');
                                                $ref = htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8');
                                                $checkInDate = $booking['check_in_date'];
                                                $checkOutDate = $booking['check_out_date'];
                                                $checkIn = date('M j, Y', strtotime($checkInDate));
                                                $checkOut = date('M j, Y', strtotime($checkOutDate));

                                                // Calculate nights
                                                $checkInObj = new DateTime($checkInDate);
                                                $checkOutObj = new DateTime($checkOutDate);
                                                $nights = $checkInObj->diff($checkOutObj)->days;

                                                // Room info
                                                $roomName = htmlspecialchars($booking['room_name'] . (!empty($booking['pair_label']) ? ' (rooms ' . $booking['pair_label'] . ')' : ''), ENT_QUOTES, 'UTF-8');
                                                $individualRoomNumber = !empty($booking['individual_room_number'])
                                                    ? htmlspecialchars($booking['individual_room_number'], ENT_QUOTES, 'UTF-8')
                                                    : 'Not assigned';
                                                $individualRoomName = !empty($booking['individual_room_name'])
                                                    ? htmlspecialchars($booking['individual_room_name'], ENT_QUOTES, 'UTF-8')
                                                    : '';

                                                // Status
                                                $status = ucfirst(str_replace('-', ' ', $booking['status']))
                                                    . ($isMissedArrival ? ' (missed arrival)' : '')
                                                    . ($isOverdueStay ? ' (overdue departure)' : '')
                                                    . ($isBlocked ? ' - CONFLICT: night is blocked' : '');

                                                // Payment info
                                                // Step 8i: from the booking's money (paid so far vs balance due), never a payment record's status.
                                                // Step 13: the label keeps its own words (no Title Case) and its class colours the tooltip like the list's pill.
                                                $calPayState = rh_booking_pay_state($booking, ['currency' => (string)getSetting('currency_symbol')]);
                                                $paymentStatus = htmlspecialchars($calPayState['label'], ENT_QUOTES, 'UTF-8');
                                                $paymentClass = in_array($calPayState['class'], ['ok', 'warn', 'alert'], true) ? $calPayState['class'] : 'muted';
                                                $totalAmount = number_format(rh_booking_full_total($booking), 2); // full amount incl. VAT & levy
                                                $currencySymbol = getSetting('currency_symbol');
                                                // The room rate a night it was BOOKED at × nights (the row's own columns, no query).
                                                $calRate = rh_booked_rate_line($booking, null, ['currency' => (string)$currencySymbol]);
                                                $calRateCompact = $calRate['ok'] ? $calRate['compact'] : '';

                                                // Build tooltip data attributes (all properly escaped)
                                                // Use actual newlines (%0A encoded) for white-space: pre-line to work
                                                $roomGuestRaw = trim((string)($booking['room_guest_name'] ?? ''));
                                                $roomGuestEsc = htmlspecialchars($roomGuestRaw, ENT_QUOTES, 'UTF-8');
                                                $tooltipText = $ref . ' - ' . $guestName . ($roomGuestEsc !== '' ? ' (room guest: ' . $roomGuestEsc . ')' : '') . "\n" .
                                                    $individualRoomNumber . ' | ' . $checkIn . ' to ' . $checkOut . ' (' . $nights . ' nights)' . "\n" .
                                                    'Status: ' . $status . ' | Payment: ' . $paymentStatus
                                                    . ($calRateCompact !== '' ? "\n" . 'Booked at: ' . $calRateCompact . ' (rate a night × nights)' : '');

                                                $dataAttrs = [
                                                    'data-booking-ref' => $ref,
                                                    'data-guest-name' => $guestName,
                                                    'data-room-guest' => $roomGuestEsc,
                                                    'data-room-name' => $roomName,
                                                    'data-room-number' => $individualRoomNumber,
                                                    'data-room-display' => $individualRoomNumber . ($individualRoomName ? ' - ' . $individualRoomName : ''),
                                                    'data-status' => $status,
                                                    'data-check-in' => $checkIn,
                                                    'data-check-out' => $checkOut,
                                                    'data-nights' => $nights,
                                                    'data-payment-status' => $paymentStatus,
                                                    'data-payment-class' => $paymentClass,
                                                    'data-amount' => $currencySymbol . ' ' . $totalAmount,
                                                    'data-booked-rate' => htmlspecialchars($calRateCompact, ENT_QUOTES, 'UTF-8'),
                                                    'data-booking-id' => intval($booking['id']),
                                                    // CSS-only fallback tooltip (simple text for when JS fails)
                                                    'data-tooltip' => htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8')
                                                ];
                                                ?>
                                                <div class="booking-indicator <?php echo $statusClass; ?> calendar-booking-tooltip-trigger"
                                                    <?php foreach ($dataAttrs as $attr => $value): echo $attr . '="' . $value . '" ';
                                                    endforeach; ?>
                                                    tabindex="0"
                                                    role="button"
                                                    aria-label="Booking details for <?php echo $guestName; ?>"
                                                    onclick="window.location.href='booking-details.php?id=<?php echo intval($booking['id']); ?>'">
                                                    <?php echo htmlspecialchars(mb_substr(html_entity_decode($guestName, ENT_QUOTES, 'UTF-8'), 0, 12), ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                        <?php
                                            }
                                        }
                                        ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No individual rooms found.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php /* admin-components.js (CalendarTooltip) is loaded once, cache-busted, by admin-footer.php.
             A second unversioned copy here caused double-init and a stale-cache race that made the
             booking hover tooltips need two reloads before working. */ ?>
    <script>
        (function() {
            'use strict';

            const roomCards = Array.from(document.querySelectorAll('.room-calendar[data-room-id]'));
            const inSearch = document.getElementById('cf-search');
            const selType = document.getElementById('cf-type');
            const selRoom = document.getElementById('cf-room');
            const selStatus = document.getElementById('cf-status');
            const selFloor = document.getElementById('cf-floor');
            const btnClear = document.getElementById('calFilterClear');
            const spanCount = document.getElementById('calFilterCount');

            if (!roomCards.length) return;

            // Cascade: when a room type is selected, limit the individual-room dropdown
            function cascadeRoomDropdown() {
                if (!selRoom) return;
                const typeId = selType ? selType.value : '';
                Array.from(selRoom.options).forEach(opt => {
                    if (!opt.value) return; // "All rooms" — always show
                    opt.style.display = (!typeId || opt.dataset.type === typeId) ? '' : 'none';
                });
                // If currently selected room doesn't match new type, reset
                if (selRoom.value && typeId) {
                    const selectedOpt = selRoom.options[selRoom.selectedIndex];
                    if (selectedOpt && selectedOpt.dataset.type !== typeId) {
                        selRoom.value = '';
                    }
                }
            }

            function applyFilters() {
                const search = inSearch ? inSearch.value.toLowerCase().trim() : '';
                const typeId = selType ? selType.value : '';
                const roomId = selRoom ? selRoom.value : '';
                const status = selStatus ? selStatus.value : '';
                const floor = selFloor ? selFloor.value : '';

                const hasFilter = search || typeId || roomId || status || floor;
                if (btnClear) btnClear.style.display = hasFilter ? 'inline-flex' : 'none';

                let visible = 0;
                roomCards.forEach(card => {
                    let show = true;
                    if (roomId && card.dataset.roomId !== roomId) show = false;
                    if (typeId && card.dataset.roomTypeId !== typeId) show = false;
                    if (status && card.dataset.roomStatus !== status) show = false;
                    if (floor && card.dataset.roomFloor !== floor) show = false;
                    if (search && !card.dataset.roomSearch.includes(search)) show = false;

                    card.style.display = show ? '' : 'none';
                    if (show) visible++;
                });

                if (spanCount) {
                    spanCount.textContent = hasFilter ?
                        'Showing ' + visible + ' of ' + roomCards.length + ' rooms' :
                        '';
                }
            }

            function clearFilters() {
                if (inSearch) inSearch.value = '';
                if (selType) selType.value = '';
                if (selRoom) selRoom.value = '';
                if (selStatus) selStatus.value = '';
                if (selFloor) selFloor.value = '';
                cascadeRoomDropdown();
                applyFilters();
            }

            // Cascade room dropdown when type changes
            if (selType) selType.addEventListener('change', function() {
                cascadeRoomDropdown();
                applyFilters();
            });

            // Wire up all filter controls
            [inSearch, selRoom, selStatus, selFloor].forEach(el => {
                if (!el) return;
                el.addEventListener('change', applyFilters);
                if (el.tagName === 'INPUT') el.addEventListener('input', applyFilters);
            });

            if (btnClear) btnClear.addEventListener('click', clearFilters);

            // Apply on load (restores state from URL params passed as initial values)
            cascadeRoomDropdown();
            applyFilters();
        })();
    </script>

    <?php endif; ?>

    <?php require_once 'includes/admin-footer.php'; ?>
