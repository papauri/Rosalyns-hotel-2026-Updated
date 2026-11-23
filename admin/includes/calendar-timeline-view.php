<?php
/**
 * Room Timeline (Calendar tab) and the compact Housekeeping / Maintenance tabs of admin/calendar.php.
 * Changes go only through the existing admin/bookings.php actions (move room, add / take off nights; see
 * admin/js/room-timeline.js); everything else is a link to the page that does it (each re-checks its own permission).
 * The timeline grid is drawn by admin/js/room-timeline.js from the JSON block below (same payload as
 * admin/api/calendar-timeline.php); names reach the page only through textContent.
 */
if (!defined('ADMIN_ACCESS')) {
    http_response_code(403);
    exit;
}

$tlH = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$tlDate = static function ($v, string $fmt = 'j M Y'): string {
    $s = trim((string)$v);
    if ($s === '' || strpos($s, '0000-00-00') === 0) {
        return '';
    }
    $t = strtotime($s);
    return $t ? date($fmt, $t) : '';
};
?>
<?php if ($calTab === 'calendar'): ?>
    <?php
    $tlJson = json_encode([
        'data' => $tlPayload,
        'initial' => $tlInitial,
        'api' => 'api/calendar-timeline.php',
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $tlFloors = [];
    foreach ($tlPayload['lanes'] as $tlLane) {
        if ($tlLane['floor'] !== '' && !in_array($tlLane['floor'], $tlFloors, true)) {
            $tlFloors[] = $tlLane['floor'];
        }
    }
    natsort($tlFloors);
    ?>
    <section class="tl" id="roomTimeline" data-tl-root>
        <div class="tl-toolbar">
            <div class="tl-toolbar__nav">
                <button type="button" class="btn btn-secondary tl-btn" data-tl-today>Today</button>
                <button type="button" class="tl-icon-btn" data-tl-prev aria-label="Previous 14 days"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
                <button type="button" class="tl-icon-btn" data-tl-next aria-label="Next 14 days"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                <label class="tl-toolbar__from">
                    <span>From</span>
                    <input type="date" class="tl-toolbar__date" data-tl-date value="<?php echo $tlH($tlPayload['start']); ?>">
                </label>
            </div>

            <div class="ck-tabs tl-range" role="group" aria-label="Days shown">
                <?php foreach ([3, 7, 14, 30] as $tlN): ?>
                    <button type="button" class="ck-tab tl-range__opt tl-range__opt--<?php echo $tlN; ?>" data-tl-days="<?php echo $tlN; ?>" aria-pressed="false"><?php echo $tlN; ?> days</button>
                <?php endforeach; ?>
            </div>

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
                        <?php foreach ($tlPayload['groups'] as $tlG): ?>
                            <option value="<?php echo (int)$tlG['id']; ?>"><?php echo $tlH($tlG['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($tlFloors): ?>
                    <label class="tl-field">
                        <span>Floor</span>
                        <select class="cal-filter-select" data-tl-filter="floor">
                            <option value="">All floors</option>
                            <?php foreach ($tlFloors as $tlF): ?>
                                <option value="<?php echo $tlH($tlF); ?>">Floor <?php echo $tlH($tlF); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <label class="tl-field">
                    <span>Status</span>
                    <select class="cal-filter-select" data-tl-filter="status">
                        <option value="">All rooms</option>
                        <option value="free">Free tonight</option>
                        <option value="occ">Occupied</option>
                        <option value="arr">Arriving today</option>
                        <option value="dep">Departing today</option>
                        <option value="dirty">Dirty</option>
                        <option value="oos">Out of service</option>
                    </select>
                </label>
                <button type="button" class="tl-icon-btn tl-density" data-tl-density aria-pressed="false" aria-label="Compact rows" title="Compact rows">
                    <i class="fas fa-grip-lines" aria-hidden="true"></i>
                </button>
                <button type="button" class="cal-filter-clear" data-tl-clear hidden><i class="fas fa-times" aria-hidden="true"></i> Clear filters</button>
            </div>

            <label class="tl-toolbar__search">
                <span class="tl-sr">Search guest, booking reference or room</span>
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" data-tl-search placeholder="Guest, ref or room" autocomplete="off">
            </label>

            <div class="tl-legend-wrap">
                <button type="button" class="btn btn-secondary tl-btn" data-tl-legend aria-expanded="false" aria-controls="tlLegend">Legend</button>
                <div class="tl-legend" id="tlLegend" hidden data-disable-auto-pagination>
                    <p class="tl-legend__title">Bookings</p>
                    <ul>
                        <li><span class="tl-key tl-key--pending"></span> Pending: waiting for a deposit or approval</li>
                        <li><span class="tl-key tl-key--tentative"></span> Tentative: held, not yet confirmed</li>
                        <li><span class="tl-key tl-key--confirmed"></span> Confirmed: guest expected</li>
                        <li><span class="tl-key tl-key--checked-in"></span> Checked in: guest in the room</li>
                        <li><span class="tl-key tl-key--checked-in tl-key--overdue"></span> Red right edge: past check-out, still in</li>
                        <li><span class="tl-key tl-key--unassigned"></span> Dashed: no room assigned yet</li>
                    </ul>
                    <p class="tl-legend__title">Flags on a booking</p>
                    <ul>
                        <li><span class="tl-flag tl-flag--part"><i class="fas fa-circle-half-stroke"></i></span> Part paid</li>
                        <li><span class="tl-flag tl-flag--unpaid"><i class="fas fa-circle-exclamation"></i></span> Nothing paid yet</li>
                        <li><span class="tl-flag tl-flag--note"><i class="fas fa-note-sticky"></i></span> Has a special request or note</li>
                        <li><span class="tl-flag tl-flag--joined"><i class="fas fa-link"></i></span> Joined rooms</li>
                        <li><span class="tl-flag tl-flag--group"><i class="fas fa-users"></i></span> Part of a group of rooms</li>
                    </ul>
                    <p class="tl-legend__title">Rooms</p>
                    <ul>
                        <li><span class="tl-key tl-key--hatch"></span> Hatched: maintenance, out of order or blocked</li>
                        <li><span class="tl-hk tl-hk--clean"><i class="fas fa-check"></i></span> Clean</li>
                        <li><span class="tl-hk tl-hk--dirty"><i class="fas fa-exclamation"></i></span> Dirty: cleaning not started</li>
                        <li><span class="tl-hk tl-hk--cleaning"><i class="fas fa-ellipsis"></i></span> Cleaning in progress</li>
                        <li><span class="tl-hk tl-hk--inspection"><i class="fas fa-eye"></i></span> Cleaned, waiting for inspection</li>
                        <li><span class="tl-hk tl-hk--blocked"><i class="fas fa-ban"></i></span> Cleaning blocked</li>
                    </ul>
                    <p class="tl-legend__note">A booking starts at the middle of its check-in day and ends at the middle of its check-out day, so a same-day departure and arrival sit side by side.</p>
                </div>
            </div>

            <div class="ck-tabs tl-view" role="group" aria-label="Calendar view">
                <button type="button" class="ck-tab is-active" data-tl-mode="grid" aria-pressed="true"><i class="fas fa-bars-staggered" aria-hidden="true"></i> Timeline</button>
                <button type="button" class="ck-tab tl-view__agenda" data-tl-mode="agenda" aria-pressed="false"><i class="fas fa-list" aria-hidden="true"></i> Agenda</button>
                <a class="ck-tab" href="calendar.php?view=month<?php echo $filterQs !== '' ? $tlH($filterQs) : ''; ?>"><i class="fas fa-calendar" aria-hidden="true"></i> Month</a>
            </div>
        </div>

        <p class="tl-sr tl-status" data-tl-status aria-live="polite"></p>

        <div class="tl-scroller" data-tl-scroller data-disable-auto-pagination>
            <div class="tl-grid" data-tl-grid role="grid" aria-label="Room timeline"></div>
        </div>
        <p class="tl-empty" data-tl-empty hidden>No rooms match these filters.</p>

        <div class="tl-agenda" data-tl-agenda hidden></div>

        <div class="tl-backdrop" data-tl-backdrop hidden></div>
        <aside class="tl-drawer" data-tl-drawer hidden role="dialog" aria-modal="false" aria-labelledby="tlDrawerTitle" tabindex="-1">
            <span class="tl-drawer__handle" aria-hidden="true"></span>
            <div class="modal-header">
                <h3 id="tlDrawerTitle" data-tl-drawer-title>Booking</h3>
                <button type="button" class="tl-icon-btn tl-drawer__close" data-tl-drawer-close aria-label="Close"><i class="fas fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body" data-tl-drawer-body></div>
            <div class="modal-footer" data-tl-drawer-foot></div>
        </aside>

        <script type="application/json" data-tl-json><?php echo $tlJson; ?></script>
    </section>
    <script src="js/admin-context-menu.js?v=<?php echo @filemtime(__DIR__ . '/../js/admin-context-menu.js'); ?>"></script>
    <script src="js/room-timeline-select.js?v=<?php echo @filemtime(__DIR__ . '/../js/room-timeline-select.js'); ?>"></script>
    <script src="js/admin-extend.js?v=<?php echo @filemtime(__DIR__ . '/../js/admin-extend.js'); ?>"></script>
    <script src="js/room-timeline.js?v=<?php echo @filemtime(__DIR__ . '/../js/room-timeline.js'); ?>"></script>
    <script>
        (function () {
            var root = document.getElementById('roomTimeline');
            if (root && window.RoomTimeline) { window.RoomTimeline.init(root); }
        })();
    </script>

<?php elseif ($calTab === 'housekeeping'): ?>
    <?php
    $hkC = $calHk['counts'];
    $hkCards = [
        'dirty' => ['Dirty', 'fa-exclamation', 'Rooms with a cleaning task due and not started, or marked as cleaning. Active rooms, now.'],
        'cleaning' => ['Cleaning', 'fa-ellipsis', 'Rooms with a cleaning task in progress right now.'],
        'inspection' => ['Inspection', 'fa-eye', 'Rooms whose last cleaning task is done but not yet checked (verified).'],
        'clean' => ['Clean', 'fa-check', 'Rooms with no cleaning due, in progress or waiting for inspection.'],
        'unassigned' => ['Unassigned tasks', 'fa-user-slash', 'Rooms whose current open cleaning task has nobody assigned.'],
    ];
    ?>
    <section class="tl-tabpane" aria-labelledby="tlHkTitle">
        <div class="tl-tabpane__head">
            <h3 id="tlHkTitle" class="tl-tabpane__title">Housekeeping now</h3>
            <a class="btn btn-secondary" href="housekeeping.php"><i class="fas fa-up-right-from-square" aria-hidden="true"></i> Open full Housekeeping page</a>
        </div>
        <div class="ck-kpis tl-cards">
            <?php foreach ($hkCards as $key => [$label, $icon, $help]): $active = $calHkFilter === $key; ?>
                <a class="ck-kpi tl-card<?php echo $active ? ' is-active' : ''; ?>" href="calendar.php?tab=housekeeping<?php echo $active ? '' : '&amp;hk=' . $key; ?>#tlHkTable"<?php echo $active ? ' aria-current="true"' : ''; ?>>
                    <span class="ck-kpi__icon"><i class="fas <?php echo $icon; ?>" aria-hidden="true"></i></span>
                    <span class="ck-kpi__label"><?php echo $tlH($label); ?> <i class="help" data-tip="<?php echo $tlH($help); ?>">?</i></span>
                    <span class="ck-kpi__value"><?php echo (int)($hkC[$key] ?? 0); ?></span>
                    <span class="ck-kpi__sub"><?php echo $active ? 'Showing these rooms. Click again to show all.' : 'Show these rooms'; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($calHkFilter !== ''): ?>
            <p class="tl-filter-note">Showing <?php echo $tlH(strtolower($hkCards[$calHkFilter][0])); ?> rooms only. <a href="calendar.php?tab=housekeeping#tlHkTable">Show all rooms</a></p>
        <?php endif; ?>
        <div class="table-responsive tl-list-wrap" id="tlHkTable">
            <table class="table tl-list">
                <thead>
                    <tr>
                        <th>Room <i class="help" data-tip="Room number and name. Active rooms only.">?</i></th>
                        <th>Type <i class="help" data-tip="The room type the room belongs to.">?</i></th>
                        <th>Status <i class="help" data-tip="Housekeeping state now: from the room's open cleaning tasks and its last task.">?</i></th>
                        <th>Task <i class="help" data-tip="The room's current open task: the one in progress, else the next due, else a blocked one.">?</i></th>
                        <th>Assigned to <i class="help" data-tip="Staff member on the current task. A dash means nobody yet.">?</i></th>
                        <th>Priority <i class="help" data-tip="Priority set on the current task.">?</i></th>
                        <th>Due <i class="help" data-tip="Due date of the current task.">?</i></th>
                        <th>Actions <i class="help" data-tip="Opens the room on the Housekeeping page, where tasks are started, finished and checked.">?</i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$calHk['rows']): ?>
                        <tr><td colspan="8" class="tl-list__empty">No rooms in this view.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($calHk['rows'] as $row): $r = $row['room']; $h = $row['hk']; $t = $h['task']; ?>
                        <tr>
                            <td data-label="Room"><strong><?php echo $tlH($r['room_number']); ?></strong><?php if (trim((string)($r['room_name'] ?? '')) !== ''): ?> <small class="tl-muted"><?php echo $tlH($r['room_name']); ?></small><?php endif; ?></td>
                            <td data-label="Type"><?php echo $tlH($r['room_type_name']); ?></td>
                            <td data-label="Status"><span class="tl-hk tl-hk--<?php echo $tlH($h['state']); ?>" aria-hidden="true"><i class="fas <?php echo ['clean' => 'fa-check', 'dirty' => 'fa-exclamation', 'cleaning' => 'fa-ellipsis', 'inspection' => 'fa-eye', 'blocked' => 'fa-ban'][$h['state']] ?? 'fa-check'; ?>"></i></span> <?php echo $tlH($h['label']); ?></td>
                            <td data-label="Task"><?php echo $t ? $tlH(ucwords(str_replace('_', ' ', (string)$t['assignment_type']))) . ' <small class="tl-muted">(' . $tlH(str_replace('_', ' ', (string)$t['status'])) . ')</small>' : '&mdash;'; ?></td>
                            <td data-label="Assigned to"><?php echo ($t && trim((string)($t['assigned_name'] ?? '')) !== '') ? $tlH($t['assigned_name']) : '&mdash;'; ?></td>
                            <td data-label="Priority"><?php echo $t && trim((string)($t['priority'] ?? '')) !== '' ? $tlH(ucfirst((string)$t['priority'])) : '&mdash;'; ?></td>
                            <td data-label="Due"><?php $d = $t ? $tlDate($t['due_date']) : ''; echo $d !== '' ? $tlH($d) : '&mdash;'; ?></td>
                            <td data-label="Actions"><a class="btn btn-secondary btn-sm" href="housekeeping.php?room=<?php echo urlencode((string)$r['room_number']); ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php else: ?>
    <?php
    $mtC = $calMt['counts'];
    $mtCards = [
        '' => ['Open', 'fa-screwdriver-wrench', 'Maintenance jobs not yet completed or cancelled (planned and in progress), all rooms.', 'calendar.php?tab=maintenance#tlMtTable', $mtC['open']],
        'progress' => ['In progress', 'fa-person-digging', 'Maintenance jobs being worked on now.', 'calendar.php?tab=maintenance&mt=progress#tlMtTable', $mtC['progress']],
        'out' => ['Out of order now', 'fa-ban', 'Rooms in maintenance or out of order right now (as the Dashboard counts them). Opens the Maintenance page filtered to them.', 'room-maintenance.php?status=out_of_service', $mtC['out_now']],
        'due' => ['Due this week', 'fa-calendar-week', 'Open jobs due (or starting, when no due date) by 6 days from today, including overdue ones.', 'calendar.php?tab=maintenance&mt=due#tlMtTable', $mtC['due']],
    ];
    ?>
    <section class="tl-tabpane" aria-labelledby="tlMtTitle">
        <div class="tl-tabpane__head">
            <h3 id="tlMtTitle" class="tl-tabpane__title">Open maintenance</h3>
            <a class="btn btn-secondary" href="room-maintenance.php"><i class="fas fa-up-right-from-square" aria-hidden="true"></i> Open full Maintenance page</a>
        </div>
        <div class="ck-kpis tl-cards">
            <?php foreach ($mtCards as $key => [$label, $icon, $help, $href, $n]): $active = $key !== 'out' && $calMtFilter === $key; ?>
                <a class="ck-kpi tl-card<?php echo $active ? ' is-active' : ''; ?>" href="<?php echo $tlH($href); ?>"<?php echo $active ? ' aria-current="true"' : ''; ?>>
                    <span class="ck-kpi__icon"><i class="fas <?php echo $icon; ?>" aria-hidden="true"></i></span>
                    <span class="ck-kpi__label"><?php echo $tlH($label); ?> <i class="help" data-tip="<?php echo $tlH($help); ?>">?</i></span>
                    <span class="ck-kpi__value"><?php echo (int)$n; ?></span>
                    <span class="ck-kpi__sub"><?php echo $key === 'out' ? 'Open on the Maintenance page' : ($active ? 'Showing these jobs' : 'Show these jobs'); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($calMtFilter !== ''): ?>
            <p class="tl-filter-note">Showing <?php echo $calMtFilter === 'progress' ? 'jobs in progress' : 'jobs due this week'; ?> only. <a href="calendar.php?tab=maintenance#tlMtTable">Show all open jobs</a></p>
        <?php endif; ?>
        <div class="table-responsive tl-list-wrap" id="tlMtTable">
            <table class="table tl-list">
                <thead>
                    <tr>
                        <th>Room <i class="help" data-tip="The room the job is for.">?</i></th>
                        <th>Issue <i class="help" data-tip="The job's title as entered on the Maintenance page.">?</i></th>
                        <th>Status <i class="help" data-tip="Planned or in progress. Completed and cancelled jobs are not listed.">?</i></th>
                        <th>Priority <i class="help" data-tip="Urgent, high, medium or low, as set on the job.">?</i></th>
                        <th>From <i class="help" data-tip="When the job starts.">?</i></th>
                        <th>To <i class="help" data-tip="When the job is planned to end.">?</i></th>
                        <th>Blocks room <i class="help" data-tip="Yes: the room cannot be sold or assigned between From and To.">?</i></th>
                        <th>Actions <i class="help" data-tip="Opens the Maintenance page, where jobs are started, finished and edited.">?</i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$calMt['rows']): ?>
                        <tr><td colspan="8" class="tl-list__empty">No open maintenance jobs in this view.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($calMt['rows'] as $m): $st = (string)$m['status']; ?>
                        <tr>
                            <td data-label="Room"><strong><?php echo $tlH($m['room_number'] ?? ''); ?></strong><?php if (trim((string)($m['room_name'] ?? '')) !== ''): ?> <small class="tl-muted"><?php echo $tlH($m['room_name']); ?></small><?php endif; ?></td>
                            <td data-label="Issue"><?php echo trim((string)$m['title']) !== '' ? $tlH($m['title']) : '&mdash;'; ?></td>
                            <td data-label="Status"><span class="badge <?php echo $st === 'in_progress' ? 'badge-info' : 'badge-pending'; ?>"><?php echo $tlH($st === 'in_progress' ? 'In progress' : ($st === '' ? 'Planned' : ucfirst(str_replace('_', ' ', $st)))); ?></span></td>
                            <td data-label="Priority"><?php echo trim((string)($m['priority'] ?? '')) !== '' ? $tlH(ucfirst((string)$m['priority'])) : '&mdash;'; ?></td>
                            <td data-label="From"><?php $d = $tlDate($m['start_date'], 'j M Y, H:i'); echo $d !== '' ? $tlH($d) : '&mdash;'; ?></td>
                            <td data-label="To"><?php $d = $tlDate($m['end_date'], 'j M Y, H:i'); echo $d !== '' ? $tlH($d) : '&mdash;'; ?></td>
                            <td data-label="Blocks room"><?php echo (int)$m['block_room'] === 1 ? 'Yes' : 'No'; ?></td>
                            <td data-label="Actions"><a class="btn btn-secondary btn-sm" href="room-maintenance.php">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
