<?php
/**
 * Room timeline section for admin/housekeeping.php, admin/room-maintenance.php and admin/room-service-dashboard.php
 * (CALENDAR_UI_DECISION 10). Room Service ('service') shows one day by the hour: no range tabs, priority, assignee or
 * density controls, and a "Show all rooms" switch (only rooms with an order show by default).
 * The page sets, before including this file:
 *   $opsMode     'housekeeping' | 'maintenance' | 'service'
 *   $opsPayload  rh_ops_payload() result (admin/includes/ops-timeline-data.php)
 *   $opsInitial  rh_ops_deeplink() result (id, room, room_id, date, view, days)
 *   $opsPage     ['list' => CSS selector of the list card, 'table' => selector of its table,
 *                 'confirm' => the page's confirm function, 'edit' => the page's edit function,
 *                 optional 'bulk' => selector of the list's bulk bar form, 'new' => the page's prefilled add function]
 * The grid is drawn by admin/js/room-timeline-ops.js (via RoomTimeline.init) from the JSON block below. The section is
 * hidden until the script runs, so without JavaScript the page shows its list and modals exactly as before.
 * Every change goes through the page's own POST handlers (same fields, CSRF token and confirm).
 */
if (!defined('ADMIN_ACCESS')) {
    http_response_code(403);
    exit;
}

$opsH = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$opsIsHk = $opsMode === 'housekeeping';
$opsIsRs = $opsMode === 'service';
$opsLabel = $opsIsRs ? 'Room service timeline' : ($opsIsHk ? 'Housekeeping timeline' : 'Maintenance timeline');
$opsJson = json_encode([
    'mode' => $opsMode,
    'page' => $opsMode,
    'label' => $opsLabel,
    'data' => $opsPayload,
    'initial' => $opsInitial,
    'api' => $opsIsRs ? 'api/room-service-timeline.php' : ($opsIsHk ? 'api/housekeeping-timeline.php' : 'api/maintenance-timeline.php'),
    'list' => $opsPage['list'],
    'table' => $opsPage['table'],
    'confirm' => $opsPage['confirm'],
    'edit' => $opsPage['edit'],
    'filter' => 'filterTable',
    // Picks on the grid (admin/js/room-timeline-select.js): the list's bulk bar form, and the page's prefilled add form.
    'bulk' => (string)($opsPage['bulk'] ?? ''),
    'newFor' => (string)($opsPage['new'] ?? ''),
    'csrf' => (string)($csrf_token ?? ''),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$opsFloors = [];
foreach ($opsPayload['lanes'] as $opsLane) {
    if ($opsLane['floor'] !== '' && !in_array($opsLane['floor'], $opsFloors, true)) {
        $opsFloors[] = $opsLane['floor'];
    }
}
natsort($opsFloors);
if ($opsIsRs) {
    $opsStatuses = ['' => 'All orders (not cancelled)', 'new' => 'New', 'preparing' => 'Preparing', 'ready' => 'Ready', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
} else {
    $opsStatuses = $opsIsHk
        ? ['' => 'All tasks', 'due' => 'Due now (today or earlier)', 'pending' => 'Not started', 'in_progress' => 'In progress', 'completed' => 'Done, not checked', 'verified' => 'Verified', 'blocked' => 'Blocked']
        : ['' => 'All jobs', 'pending' => 'Planned', 'in_progress' => 'In progress', 'done' => 'Done', 'blocks' => 'Blocks the room'];
}
$opsLegendId = $opsIsRs ? 'opsLegendRs' : ($opsIsHk ? 'opsLegendHk' : 'opsLegendRm');
?>
<section class="tl tl-ops" id="opsTimeline" data-tl-root hidden aria-label="<?php echo $opsLabel; ?>">
    <div class="tl-toolbar">
        <div class="tl-toolbar__nav">
            <button type="button" class="btn btn-secondary tl-btn" data-tl-today>Today</button>
            <button type="button" class="tl-icon-btn" data-tl-prev aria-label="<?php echo $opsIsRs ? 'Previous day' : 'Previous 7 days'; ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
            <button type="button" class="tl-icon-btn" data-tl-next aria-label="<?php echo $opsIsRs ? 'Next day' : 'Next 7 days'; ?>"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
            <label class="tl-toolbar__from">
                <span><?php echo $opsIsRs ? 'Day' : 'From'; ?></span>
                <input type="date" class="tl-toolbar__date" data-tl-date value="<?php echo $opsH($opsPayload['start']); ?>">
            </label>
        </div>

        <?php if ($opsIsRs): ?>
            <button type="button" class="btn btn-secondary tl-btn" data-ops-allrooms aria-pressed="false" data-help="Show all rooms|Off: only rooms with an order on this day. On: every room, so you can see which rooms ordered nothing.">
                <i class="fas fa-bed" aria-hidden="true"></i> Show all rooms
            </button>
        <?php else: ?>
        <div class="ck-tabs tl-range" role="group" aria-label="Days shown">
            <?php foreach ([3, 7, 14, 30] as $opsN): ?>
                <button type="button" class="ck-tab tl-range__opt tl-range__opt--<?php echo $opsN; ?>" data-tl-days="<?php echo $opsN; ?>" aria-pressed="false"><?php echo $opsN; ?> days</button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <button type="button" class="btn btn-secondary tl-btn tl-filters-btn" data-tl-filters-open aria-expanded="false">
            <i class="fas fa-sliders" aria-hidden="true"></i> Filters <span class="tl-filters-btn__count" data-tl-filter-count hidden></span>
        </button>

        <div class="tl-toolbar__filters" data-tl-filters role="group" aria-label="Filters">
            <div class="tl-sheet-head">
                <strong>Filters</strong>
                <button type="button" class="tl-icon-btn" data-tl-filters-close aria-label="Close filters"><i class="fas fa-xmark" aria-hidden="true"></i></button>
            </div>
            <label class="tl-field">
                <span>Room type</span>
                <select class="cal-filter-select" data-tl-filter="type">
                    <option value="">All types</option>
                    <?php foreach ($opsPayload['groups'] as $opsG): ?>
                        <option value="<?php echo (int)$opsG['id']; ?>"><?php echo $opsH($opsG['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($opsFloors): ?>
                <label class="tl-field">
                    <span>Floor</span>
                    <select class="cal-filter-select" data-tl-filter="floor">
                        <option value="">All floors</option>
                        <?php foreach ($opsFloors as $opsF): ?>
                            <option value="<?php echo $opsH($opsF); ?>">Floor <?php echo $opsH($opsF); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label class="tl-field">
                <span>Status</span>
                <select class="cal-filter-select" data-tl-filter="status">
                    <?php foreach ($opsStatuses as $opsV => $opsL): ?>
                        <option value="<?php echo $opsH($opsV); ?>"><?php echo $opsH($opsL); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if (!$opsIsRs): ?>
            <label class="tl-field">
                <span>Priority</span>
                <select class="cal-filter-select" data-tl-filter="priority"><option value="">Any priority</option></select>
            </label>
            <label class="tl-field">
                <span>Assigned to</span>
                <select class="cal-filter-select" data-tl-filter="assignee"><option value="">Everyone</option></select>
            </label>
            <button type="button" class="tl-icon-btn tl-density" data-tl-density aria-pressed="false" aria-label="Compact rows" title="Compact rows">
                <i class="fas fa-grip-lines" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
            <button type="button" class="cal-filter-clear" data-tl-clear hidden><i class="fas fa-times" aria-hidden="true"></i> Clear filters</button>
        </div>

        <label class="tl-toolbar__search">
            <?php if ($opsIsRs): ?>
            <span class="tl-sr">Search room, guest, order number or item</span>
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search" data-tl-search placeholder="Room or guest" autocomplete="off">
            <?php else: ?>
            <span class="tl-sr"><?php echo $opsIsHk ? 'Search room, staff member, guest or note' : 'Search room, issue, staff member or note'; ?></span>
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search" data-tl-search placeholder="<?php echo $opsIsHk ? 'Room, staff or guest' : 'Room, issue or staff'; ?>" autocomplete="off">
            <?php endif; ?>
        </label>

        <div class="tl-legend-wrap">
            <button type="button" class="btn btn-secondary tl-btn" data-tl-legend aria-expanded="false" aria-controls="<?php echo $opsLegendId; ?>">Legend</button>
            <div class="tl-legend" id="<?php echo $opsLegendId; ?>" data-tl-legend-box hidden data-disable-auto-pagination>
                <?php if ($opsIsRs): ?>
                    <p class="tl-legend__title">Orders (from placed to delivered)</p>
                    <ul>
                        <li><span class="tl-key tl-key--task-pending"></span> New: sent to the kitchen, not started</li>
                        <li><span class="tl-key tl-key--task-progress"></span> Preparing</li>
                        <li><span class="tl-key tl-key--order-ready"></span> Ready to take to the room</li>
                        <li><span class="tl-key tl-key--task-done"></span> Delivered</li>
                        <li><span class="tl-key tl-key--order-void"></span> Cancelled (shown only when Status is Cancelled)</li>
                        <li><span class="tl-key tl-key--task-pending tl-key--overdue"></span> Red right edge: open more than <?php echo (int)($opsPayload['late_minutes'] ?? 10); ?> minutes after it reached the kitchen (the same rule as the kitchen screen)</li>
                    </ul>
                    <p class="tl-legend__note">An open order from an earlier day shows on today, from midnight. The vertical line is now.</p>
                <?php elseif ($opsIsHk): ?>
                    <p class="tl-legend__title">Tasks (on their due day)</p>
                    <ul>
                        <li><span class="tl-key tl-key--task-pending"></span> Not started</li>
                        <li><span class="tl-key tl-key--task-progress"></span> In progress</li>
                        <li><span class="tl-key tl-key--task-done"></span> Done, waiting to be checked</li>
                        <li><span class="tl-key tl-key--task-verified"></span> Verified</li>
                        <li><span class="tl-key tl-key--hatch"></span> Blocked</li>
                        <li><span class="tl-key tl-key--task-pending tl-key--overdue"></span> Red right edge: overdue (open tasks from past days show on today)</li>
                        <li><span class="tl-flag tl-flag--urgent"><i class="fas fa-exclamation"></i></span> High or urgent priority</li>
                        <li><span class="tl-key tl-key--stay"></span> Shaded day: a guest is in the room that night</li>
                    </ul>
                    <p class="tl-legend__title">Room now</p>
                    <ul>
                        <li><span class="tl-hk tl-hk--clean"><i class="fas fa-check"></i></span> Clean</li>
                        <li><span class="tl-hk tl-hk--dirty"><i class="fas fa-exclamation"></i></span> Dirty: cleaning not started</li>
                        <li><span class="tl-hk tl-hk--cleaning"><i class="fas fa-ellipsis"></i></span> Cleaning in progress</li>
                        <li><span class="tl-hk tl-hk--inspection"><i class="fas fa-eye"></i></span> Cleaned, waiting for inspection</li>
                        <li><span class="tl-hk tl-hk--blocked"><i class="fas fa-ban"></i></span> Cleaning blocked</li>
                    </ul>
                <?php else: ?>
                    <p class="tl-legend__title">Jobs (from start to end date)</p>
                    <ul>
                        <li><span class="tl-key tl-key--job-open"></span> Planned</li>
                        <li><span class="tl-key tl-key--task-progress"></span> In progress</li>
                        <li><span class="tl-key tl-key--task-done"></span> Done (shown for 7 days)</li>
                        <li><span class="tl-key tl-key--hatch"></span> Hatched: blocks the room (it cannot be sold)</li>
                        <li><span class="tl-key tl-key--job-open tl-key--overdue"></span> Red right edge: past its end date, still open</li>
                        <li><span class="tl-flag tl-flag--urgent"><i class="fas fa-exclamation"></i></span> High or urgent priority</li>
                        <li><span class="tl-hk tl-hk--blocked"><i class="fas fa-ban"></i></span> Room out of service now</li>
                    </ul>
                    <p class="tl-legend__note">Jobs that do not block the room show here too; the Calendar only shows the ones that block it.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="ck-tabs tl-view tl-ops-view" role="group" aria-label="View">
            <button type="button" class="ck-tab is-active" data-ops-view="timeline" aria-pressed="true"><i class="fas fa-bars-staggered" aria-hidden="true"></i> Timeline</button>
            <button type="button" class="ck-tab" data-ops-view="list" aria-pressed="false"><i class="fas fa-list" aria-hidden="true"></i> List</button>
        </div>
    </div>

    <p class="tl-sr tl-status" data-tl-status aria-live="polite"></p>

    <div class="tl-scroller" data-tl-scroller data-disable-auto-pagination>
        <div class="tl-grid<?php echo $opsIsRs ? ' tl-grid--hours' : ''; ?>" data-tl-grid role="grid" aria-label="<?php echo $opsLabel; ?>"></div>
    </div>
    <p class="tl-empty" data-tl-empty hidden><?php echo $opsIsRs ? 'No room service orders on this day match. Use "Show all rooms" to see every room.' : ($opsIsHk ? 'No rooms match these filters.' : 'No rooms or jobs match these filters.'); ?></p>

    <div class="tl-backdrop" data-tl-backdrop hidden></div>
    <aside class="tl-drawer" data-tl-drawer hidden role="dialog" aria-modal="false" aria-labelledby="opsDrawerTitle" tabindex="-1">
        <span class="tl-drawer__handle" aria-hidden="true"></span>
        <div class="modal-header">
            <h3 id="opsDrawerTitle" data-tl-drawer-title><?php echo $opsIsRs ? 'Order' : ($opsIsHk ? 'Task' : 'Job'); ?></h3>
            <button type="button" class="tl-icon-btn tl-drawer__close" data-tl-drawer-close aria-label="Close"><i class="fas fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="modal-body" data-tl-drawer-body></div>
        <div class="modal-footer" data-tl-drawer-foot></div>
    </aside>

    <script type="application/json" data-tl-json><?php echo $opsJson; ?></script>
</section>
