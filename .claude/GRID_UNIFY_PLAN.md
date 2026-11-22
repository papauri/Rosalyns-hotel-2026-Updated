# GRID_UNIFY_PLAN: one grid for Calendar, Blocked Dates, Maintenance, Housekeeping (owner FEATURE 2026-11-23)

Owner words 2026-11-23: "We need the calendar, maintenance, blocked dates, housekeeping views and logic to be more uniform
and more robust regardless of whether in list view or calendar view, be able to select multiple selections, do bulk
actions, extend for more days or months, years, time and most importantly work well with current booking logic, no slip
ups. Also be able to do things like right click actions and select by dragging."

Owner answers 2026-11-23:
- Clash: a block or a room-closing maintenance job on a booked night = SKIP the booked nights, save the free ones, list
  the bookings (guest, ref, room, dates) so staff can move them. Never save over a booked night.
- Extend: blocks, maintenance jobs, housekeeping tasks only. Bookings keep their own Change dates (reprice) path.
- Housekeeping extend = repeat the task every day (or week) until a chosen date, same time of day.
- Rollout: shared engine first, then pages; each phase tested, shipped, ported on its own.
- Phase order (orchestrator ASSUMPTION 2026-11-23, safety): the clash check (was 3) ships BEFORE Extend (was 2), so no
  new save path can ever write over a booked night.

## State before (mapped 2026-11-23)
- Calendar `admin/calendar.php`: `admin/js/room-timeline.js` booking mode. Bar click = drawer, bar drag = move, end grip =
  dates, empty-cell drag = ONE room row only, popover "New booking" (`finishSel` ~1472). Blocks/maintenance drawn as
  `.tl-block` (~502-519), click = link only, not selectable (drag ignores `.tl-block` ~1782).
- Blocked Dates `admin/blocked-dates.php`: own grid `admin/js/blocked-dates-grid.js` (multi-select, rectangle drag,
  shift range, room-name = month, arrows+Space) -> `grid_apply` / `preview_block` (~449-746) under
  `rh_block_locked` (~408), logged by `rh_block_log_change`. List view `?view=list` with checkboxes + bulk Unblock.
- Maintenance `admin/room-maintenance.php` + Housekeeping `admin/housekeeping.php`: room-timeline.js modes via
  `admin/js/room-timeline-ops.js`; bar click = drawer (`stdActions` ~999, `postAction` ~661), count chip = `openMulti`.
  No timeline multi/drag select. Lists have checkboxes + bulk bars (`bulk_schedules`, `bulk_assignments`).
- No `contextmenu` handler anywhere in admin/. No Extend anywhere.
- Data: blocks are one row per night (`individual_room_blocked_dates`, `blocked_dates`; `rh_block_dates_in_range` caps
  366). Maintenance `room_maintenance_schedules.start_date/end_date` DATETIME, `block_room`. Housekeeping
  `housekeeping_assignments.due_date` DATE + optional `scheduled_time`, recurring columns exist.
- Bookings' own availability: `checkIndividualRoomAvailability` (config/database.php ~8498) sees bookings, individual
  blocks and block_room=1 maintenance. Blocks only WARN (`getBookingsOverlappingBlockDates` ~7216); maintenance and
  housekeeping saves never check bookings.

## Phase 1: one selection engine, same on every grid and list (UI + reuse of existing saves)
Goal: the same gestures and the same bulk bar / right-click menu on all four timelines and the three lists.
1. room-timeline.js, all modes: a cell selection set (room x night). Click = one cell; drag = rectangle across rows;
   Shift+click = rectangle from anchor; Ctrl/Cmd+click = add/remove; click a room name = its visible range; click a day
   head = that night for all visible rooms; Esc clears; arrows + Space/Shift for keyboard. Touch: tap toggles, long-press
   = menu, no drag. Booking-mode bar drag/grips keep working unchanged (bar gestures win over cell gestures).
2. Bars (ops modes): Ctrl/Cmd+click or Shift+click selects several jobs/tasks; selected bars get a clear outline.
3. Right-click (and the ContextMenu key / Shift+F10, long-press on touch) opens ONE shared menu `.tl-ctx` (reuse
   `.actions-more-menu` look + `rhPlaceActionMenu`) listing the mode's actions for the current selection (right-click
   outside the selection selects that cell/bar first). Same menu on List-view rows (acts on ticked rows, or the row).
4. One sticky bulk bar component used by timelines AND lists: "N nights in M rooms" / "N jobs selected", the actions,
   Clear. Lists: Shift+click on a checkbox ticks the range.
5. Actions per mode (Phase 1 only reuses EXISTING server actions; permission-gated exactly as today):
   - Calendar: New booking (only when the selection is one room, consecutive nights, all free); Block nights / Unblock
     nights (calls blocked-dates.php `preview_block` then `grid_apply`, same confirm text as Blocked Dates); New
     maintenance job (opens the existing add form prefilled with room(s) + dates). Block cells become selectable.
   - Blocked Dates: its Calendar view moves onto room-timeline.js (new mode `blocks`; rows All rooms / types / rooms as
     today). Block / Unblock exactly as `grid_apply` does now. `blocked-dates-grid.js` retired only when every
     behaviour in its header comment works in the new mode.
   - Maintenance timeline: selected bars = the list's bulk actions (`bulk_schedules`); selected cells = New job
     prefilled (rooms + From/To).
   - Housekeeping timeline: selected bars = the list's bulk actions (`bulk_assignments`); selected cells = New task
     prefilled (rooms + due date).
6. No change to any save handler, table, or booking rule in this phase.
Tests: calendar_timeline_test, calendar_timeline_actions_test, ops_timeline_test, blocked_dates_grid, block_rooms (all
must stay green); browser check 1920 / 1280 / 390 on the four pages (right-click, drag rectangle, bulk bar).

## Phase 1b: right-click everywhere in admin (owner 2026-11-23)
Owner: "Extend the right click gestures wherever we can globally to bring up several other options easier."
- Phase 1 builds the menu as an admin-wide component (`RhContextMenu`); 1b wires it across admin.
- ASSUMPTION (orchestrator 2026-11-23): right-click on any admin list row / record card shows THAT row's own actions,
  read from what the page already renders (row buttons, links, `.actions-more-menu` items), so permissions stay as the
  server drew them and no new server action exists. Plus safe extras where they apply: Open, Open in new tab, Copy
  reference/number. Native browser menu kept on inputs, text selection, and Shift+right-click. POS/KDS stations excluded
  (touch tills). One shared hook in the admin layout, page opt-out attribute `data-no-ctx`.
- UI-only tier: no DB tests; lint + review + browser check on a sample of list pages.

## Phase 2: one booking-clash check (logic, Opus, full suite)
- One helper in includes/ (e.g. `rh_room_nights_clash($pdo, $roomNights)` -> booked nights + the bookings) using the
  same rules as `checkIndividualRoomAvailability` (statuses, joined rooms, type-level blocks vs typed bookings).
- Used by: blocked-dates.php `grid_apply` / `block_date` / `block_multiple`, api/blocked-dates.php POST, maintenance
  add/update when `block_room = 1` (job saves only if no booked night; otherwise save is refused with the bookings
  listed, since a job is one From..To range; or split, decide in spec check), all inside the existing locks.
- Result text names rooms, nights and bookings ("Skipped Room 4 on 3-5 Jan: booked by J. Banda LSH-123").
- Fable spec check before build (RULES: before a bookings build). Opus build, qa-auditor, full run_tests.php.

## Phase 3: Extend
- Blocks: extend selected blocks by N days / weeks / months / years or to a date. Per-night rows; cap per action
  (ASSUMPTION to log: 3 years = 1096 nights per room; the 366 cap in `rh_block_dates_in_range` raised for this path
  only). Goes through the Phase 2 clash check.
- Maintenance: extend end date + time by N units or to a date+time; block_room jobs go through the clash check.
- Housekeeping: repeat until a date, daily or weekly, same `scheduled_time`; no duplicate task for the same room +
  day + type.
- Bulk: each action works on a multi-selection; one transaction, all or nothing per action, one log row.

## Status
- 2026-11-23: plan written, owner answers recorded. Phase 1 dispatched (dept-rooms-lead, Opus).
