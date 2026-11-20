# UI_DECISION: Room Timeline calendar (admin/calendar.php)

Owner FEATURE 2026-11-21. Author: ui-lead. Builder: dept-rooms-lead. Liwonde only (Rosalyn's is LIVE, port later).
Block prefix: `.tl-` (BEM: `.tl-block__element--modifier`). New sheet: `admin/css/room-timeline.css`, loaded after
`calendar.css`. Also link `css/admin-kpi.css` (its `:root` holds the `--ck-*` tokens) and `css/admin-cockpit.css`
(for `.ck-tabs`/`.ck-tab`/`.ck-kpi`/`.ck-rows`) exactly as `dashboard.php` lines 2081-2082 do. Checked: every
top-level selector in admin-cockpit.css is `.ck-*`, so it cannot restyle the page. `help-tooltips.css` is already
loaded by the header (admin-header.php line 500 -> includes/help-tooltips.php). Font Awesome 6 names (already used).

## 0. Ground rules for this screen
- No gradients, glows, shimmer, bouncing, emoji. `--admin-success-bg`, `--admin-danger-bg`, `--admin-warning-bg`,
  `--admin-info-bg` are `linear-gradient` tokens: BANNED here. Use the flat `.badge-*` values below.
- One allowed exception: hard-stop diagonal hatching for blocked / out-of-service cells (owner asked for it, it is a
  stripe pattern, not a colour gradient). Written once as `--tl-hatch`; QA must not flag it.
- Only motion: drawer slide (200ms transform) and hover colour (150ms). Both off under `prefers-reduced-motion`.
- `--tl-*` below are component layout variables and ALIASES of existing colours. No new palette, type or spacing
  scale is introduced. Spacing uses the 8px rhythm (4/8/12/16/24).

## 1. Component variables (declared on `.tl`)
| Variable | Value | Source |
|---|---|---|
| `--tl-room-w` | 208px desktop, 168px tablet, 64px phone | layout |
| `--tl-day-w` | (scroller width minus room col) / days, min 44px | layout, set by JS on resize |
| `--tl-row-h` | 44px comfortable, 36px compact (compact only on `pointer: fine`) | 44px touch rule |
| `--tl-head-h` | 52px | layout |
| `--tl-line` | `var(--ck-line)` | admin-kpi.css |
| `--tl-surface` / `--tl-soft` / `--tl-softer` | `var(--ck-surface)` / `var(--ck-soft)` / `var(--ck-softer)` | admin-kpi.css |
| `--tl-today` | `var(--gold-light)` column tint, `var(--admin-accent)` line | admin-styles.css |
| `--tl-hatch` | repeating-linear-gradient(135deg, var(--tbl-border) 0 6px, var(--admin-bg) 6px 12px) | admin-styles.css |
| `--tl-focus` | 2px solid `var(--admin-accent)` | as `.help:focus-visible` |
Status colours: aliases of `.badge-*` in admin-styles.css 3349-3409 so a state looks the same as its pill on Bookings.

## 2. Page shell
- Title row: `<h2 class="section-title"><i class="fas fa-calendar-days"></i> Calendar</h2>` (drop the emoji; "Calendar"
  matches the sidebar label in admin-nav-items.php line 23, so it is not confused with the Bookings list).
  Right side of the row: primary `.btn.btn-primary` "New booking" (only visible primary on the page).
- Tab strip under the title: `<nav class="ck-tabs tl-tabs" role="tablist">` with three `.ck-tab` links:
  Calendar | Housekeeping | Maintenance. `?tab=calendar` (default) / `?tab=housekeeping` / `?tab=maintenance`.
  Active tab `.is-active` + `aria-selected="true"`; counts in `.ck-tab__count` (open HK tasks, open maintenance).
  Tabs honour the existing permissions/module gates of housekeeping.php and room-maintenance.php (hidden, not disabled).
- View toggle (Calendar tab only), right end of the toolbar: segmented `.tl-view` with two buttons
  "Timeline" (default) | "Month". Month = existing view at `calendar.php?view=month`, unchanged. Same `.ck-tabs`
  look at small size (`.ck-tabs.tl-view`). Phone adds a third option "Agenda" (see 8).

## 3. Toolbar `.tl-toolbar`
One row on desktop, wraps to two on tablet. All controls use `--rh-toolbar-control-height` and
`--rh-toolbar-control-font-size` (admin-styles.css 4204), gap 8px, aligned on one baseline.
Order, left to right:
1. Date group `.tl-toolbar__nav`: `.btn.btn-secondary` "Today" · icon buttons prev/next (`fa-chevron-left/right`,
   `aria-label` "Previous 7 days" etc., step = current range) · `<input type="date" class="tl-toolbar__date">`
   labelled "From".
2. Range `.ck-tabs.tl-range`: 3 / 7 / 14 / 30 days (buttons, not a slider). Default: desktop 14, tablet 7, phone 3.
   Phone shows 3 and 7 only; tablet 7 and 14 (30 is hidden below 1025px).
3. Filters `.tl-toolbar__filters`: selects Room type, Floor (only if floors exist), Status (Free tonight / Occupied /
   Arriving / Departing / Dirty / Out of service). Reuse `.cal-filter-select` styling. Active filters show
   `.cal-filter-clear` "Clear filters".
4. Search `.tl-toolbar__search`: guest name, booking ref or room number; matching bars get `.tl-bar--match`,
   others dim to 35% opacity; Enter scrolls to the first match.
5. Density `.tl-density` icon toggle (`fa-bars` / `fa-grip-lines`, desktop only).
6. Legend: `.btn.btn-secondary` "Legend" opening a popover `.tl-legend` (reuse `.legend-item`/`.legend-dot` markup),
   listing every bar state, payment flag, room state and housekeeping icon in plain words.
Tablet/phone: filters + density move into one "Filters" button that opens the bottom sheet (8).

## 4. Grid `.tl-grid`
- ONE scroll container `.tl-scroller` owns both overflows (`overflow: auto`), height
  `calc(100dvh - var(--admin-header-height) - toolbar height - 24px)`, min 420px. Sticky works only inside it:
  no `overflow` on any ancestor between it and the sticky cells.
- Corner cell `.tl-corner` (sticky top+left, z 4): "Rooms" + collapse-all chevron.
- Date header `.tl-head` (sticky top, z 3), cell `.tl-head__day`: three stacked lines weekday "Fri" (11px, muted,
  uppercase), day "04" (16px, 600), month "Oct" (11px). Weekend `.tl-head__day--weekend` + column background
  `--tl-softer`. Today `.tl-head__day--today`: background `--tl-today` tint, day number in `var(--gold)`.
- Summary rows `.tl-sum` (sticky under the head, part of the head block): three rows, each with a label cell
  carrying `.help` + `data-help="Title|Body"` (existing pattern from help-tooltips.css):
  - "Occupancy" %: "Rooms with a stay that night / rooms you can sell (out of order excluded), this hotel, per night."
  - "Arrivals": "Bookings checking in that day (pending, tentative, confirmed), all room types."
  - "Departures": "Bookings checking out that day (in-house and due), all room types."
  Values 12px tabular-nums; occupancy 90%+ in `#9a4a08` (badge-warning text), 100% bold.
  Clicking an Arrivals/Departures value applies that filter (filter-card rule) and scrolls to the first row.
- Room column `.tl-room` (sticky left, z 2, width `--tl-room-w`, right border `--tl-line`):
  room number (600) + room name (muted, truncated) + housekeeping icon `.tl-hk` (5). Click opens the room in
  individual-rooms.php (link, `aria-label` "Room 104, dirty").
- Room-type group header `.tl-group` (full-width row, `--tl-soft` background, 36px): chevron button
  (`aria-expanded`), type name, `.badge.badge-neutral` "4 of 6 free tonight". Collapsed state remembered in
  localStorage per type.
- Unassigned lane `.tl-row--unassigned` first row of each group, label "Unassigned (n)" with `fa-inbox`;
  hidden when n = 0 in the visible range. Bars here get `.tl-bar--unassigned` (dashed 1px border). This replaces
  the old "Bookings without a room this month" box in the timeline view (kept in Month view).
- Day cells `.tl-cell`: borders `--tl-line`; weekend `--tl-softer`; hover on empty cell `--admin-hover-bg`.
- Today line `.tl-now`: 2px `var(--admin-accent)` vertical line at the middle of today's column through all rows
  (the turnover point, matching the half-day bars), z 1, `pointer-events: none`.

## 5. Booking bar `.tl-bar` (a `<button>` positioned in its row)
Half-day offset (DECIDED): bar starts at the middle of the check-in cell and ends at the middle of the check-out
cell: `left = (startIndex + 0.5) * --tl-day-w + 2px`, `width = nights * --tl-day-w - 4px`. Why: a departure and an
arrival on the same day sit side by side in one row, so same-day turnovers and real gaps are visible at a glance.
Bars clipped by the range edge get `.tl-bar--cut-start` / `--cut-end` (square corner + `fa-angle-left/right`).
Shape: radius 6px, height `--tl-row-h` minus 8px, 1px border, padding 0 8px, solid fill, no shadow at rest.

| DB status | Modifier | Fill | Text | Border | Contrast |
|---|---|---|---|---|---|
| pending | `--pending` | #9a4a08 | #fff | #9a4a08 | 6.6:1 |
| tentative | `--tentative` | rgba(245,158,11,.14) on white | #9a4a08 | 1px dashed #9a4a08 | 5.9:1 |
| confirmed | `--confirmed` | #047857 | #fff | #047857 | 5.5:1 |
| checked-in | `--checked-in` | #1d4ed8 | #fff | #1d4ed8 | 6.7:1 |
| checked-out | `--checked-out` | rgba(107,114,128,.14) | #374151 | rgba(107,114,128,.25) | 9:1 |
| no-show | `--no-show` | rgba(120,113,108,.14) | #57534e | 1px dotted #57534e | 6.9:1 |
| checked-in past check-out | `--overdue` (with `--checked-in`) | as checked-in | #fff | 3px right edge #b91c1c | |
Status strings are the stored ones (hyphenated: `checked-in`, see calendar.php line 203). Cancelled is not drawn.

Content (two lines, each `white-space: nowrap; overflow: hidden; text-overflow: ellipsis`):
- Line 1 `.tl-bar__name`: guest name, 13px 600.
- Line 2 `.tl-bar__meta`: rate plan or package name, else "N nights", then `fa-user` + guest count; 12px, 85% opacity.
- Width rules: under 96px hide line 2; under 56px show no text (surname only on hover title); the full sentence
  always sits in `aria-label` and `title`: "Jane Banda, confirmed, 4 to 7 Oct, 3 nights, Room 104, part paid".
Flags `.tl-bar__flags` (right end, gap 2px): 16px white circle chips `.tl-flag` with a coloured icon, so they read
on every fill. Paid shows nothing (no noise).
- `.tl-flag--part` `fa-circle-half-stroke` #9a4a08 · `.tl-flag--unpaid` `fa-circle-exclamation` #b91c1c
- `.tl-flag--note` `fa-note-sticky` #374151 (special request or notes present)
- `.tl-flag--joined` `fa-link` #374151 (room_combinations) · `.tl-flag--group` `fa-users` #374151 (multi-room booking)
States: hover border-color `var(--admin-text)` + z 2 (no lift, no shadow); focus-visible `--tl-focus` offset 2px;
selected `.is-selected` 2px outline `var(--admin-text)` offset 2px (stays while the drawer is open); search match
`.tl-bar--match` keeps full opacity; non-draggable `.tl-bar--locked` (cursor pointer only, no grips).

## 6. Room states on the grid
- Out of order / maintenance block `.tl-block--maintenance` and `.tl-block--ooo`: full cells (not half-day; a room
  is out for whole nights), background `--tl-hatch`, left border 3px #6b7280, label pill inside
  (`.tl-block__label`, white background, #374151 text, 11px): `fa-screwdriver-wrench` "Maintenance" /
  `fa-ban` "Out of order" + reason if short. Clicking opens room-maintenance.php for that entry.
- Blocked dates `.tl-block--blocked`: same hatch, `fa-lock` "Blocked"; room-type blocks hatch every room in the group.
- Housekeeping icon `.tl-hk` on the room cell: 18px circle, icon + colour (never colour alone):
  clean `fa-check` #047857 · dirty `fa-exclamation` #b91c1c · cleaning `fa-ellipsis` #9a4a08 ·
  inspection `fa-eye` #1d4ed8. Each with `.help`-style title "Dirty: checkout cleaning not started".
  Derivation (rooms lead owns it): dirty = open task `pending` or room status `cleaning` with no task in progress;
  cleaning = task `in_progress`; inspection = room status `inspection` or task completed and not verified;
  clean = otherwise. A task with status `blocked` (cannot be done, e.g. guest asked not to be disturbed) shows
  `fa-ban` #374151 "Cleaning blocked" and wins over dirty.

## 7. Interactions (visual spec; behaviour and saves are rooms lead's)
- Click/Enter on a bar: drawer `.tl-drawer` (right side, desktop + tablet). New component because no shared drawer
  exists; built from the modal parts: `.modal-header` / `.modal-body` / `.modal-footer` inside, fixed,
  `top: var(--admin-header-height)`, bottom 0, width min(420px, 100vw), `--tl-surface`, border-left 1px
  `--admin-surface-border`, `box-shadow: var(--shadow-lg)`. No backdrop on desktop (grid stays usable); Esc and the
  close button (`fa-xmark`, 44px) close it; focus moves into it and back to the bar on close.
  Body, top to bottom: guest name (h3) + status `.badge.badge-<status>` · ref · dates + nights · room / room type ·
  guests · rate plan/package · total, paid, balance + `.badge-paid/.badge-partial/.badge-unpaid` · notes ·
  joined/group line ("Joined with Room 105", "Group of 3 rooms, ref ...").
  Footer (right-aligned, one baseline, same height): `.btn.btn-secondary` "Open booking" then ONE state primary:
  "Check in" (confirmed, arrival today or overdue) / "Check out" (checked-in) / "Assign room" (unassigned) / none.
  Everything else in the shared `.actions-more-menu` (placed with `rhPlaceActionMenu`): Move room, Change dates,
  Record payment, Assign room (when not primary). Locked bars show the reason in the body in plain words.
- Drag (desktop only: `pointer: fine` and width over 1024px): whole bar = move (cursor `grab`/`grabbing`), 8px grip
  `.tl-bar__grip--end` on the right edge = change check-out (cursor `ew-resize`). While dragging: original stays at
  40% opacity, `.tl-bar--ghost` follows snapped to cells; target cells `.tl-cell--drop-ok` rgba(59,130,246,.12) or
  `.tl-cell--drop-bad` rgba(239,68,68,.12) + `not-allowed`. On drop nothing saves: AdminConfirm shows
  "Move Jane Banda (LSH-...) from Room 101 to Room 104, 4 to 7 Oct, 3 nights. Price: X to Y." Cancel snaps back.
- Not draggable (`.tl-bar--locked`): checked-out, no-show, stays already ended, joined and group bookings. Checked-in:
  end grip only (extend/shorten), no move.
- Click-drag on empty cells in one room row: selection `.tl-sel` (`var(--gold-light)` fill, 1px dashed
  `var(--admin-accent)`, label "3 nights"); on release a small popover with `.btn.btn-primary` "New booking" that
  opens create-booking prefilled (room, check-in, check-out). Single click on an empty cell = 1-night selection.

## 8. Tablet (600-1024px) and phone (under 600px)
Same parts, same colours, same bars: only sizes change.
- Tablet: `--tl-room-w` 168px, range 7 (14 option), toolbar on two rows, drawer as on desktop (width 380px).
- Phone: `--tl-room-w` 64px: room number + `.tl-hk` icon only (name in `aria-label`); range 3 (7 option); horizontal
  `scroll-snap-type: x mandatory` on `.tl-scroller`, `scroll-snap-align: start` on day cells; summary rows collapse to
  one "Occupancy" row (arrivals/departures shown in Agenda).
- Touch (`pointer: coarse`, any width): no drag, no grips. Tap bar = sheet; tap empty cell = sheet with
  "New booking from 4 Oct". Move room / Change dates sit in the sheet's More menu and open the existing forms.
- Sheet `.tl-drawer--sheet` (phone): bottom, max-height 85dvh, top corners `--admin-radius`, backdrop
  rgba(17,24,39,.45) (same as `.admin-modal-overlay`), 36x4px handle bar, sticky footer with the same buttons in the same order
  as desktop (Open booking, state action, More) on one row, each 44px high.
- Agenda view `.tl-agenda` (phone toggle "Agenda", optional on tablet): three sections with `.ck-rows`/`.ck-row`:
  Arriving today · In house · Departing today, each row = room number, guest, nights, status badge, payment badge,
  one action button (Check in / Check out). Section headers carry counts + `.help`.
- Every interactive target 44x44px (bars at 44px row height; icon buttons padded; `.help` already has the coarse
  pointer hit area).

## 9. Housekeeping and Maintenance tabs
Compact versions of the existing screens; reuse their markup and the shared pager, do not invent new lists.
- Filter cards row: `.ck-kpi` cards (admin-cockpit.css), each with `.help`. Clicking a card applies that filter and
  scrolls to the table (filter-card rule), active card `.is-active`.
  Housekeeping: Dirty · Cleaning · Inspection · Clean · Unassigned tasks. Maintenance: Open · In progress ·
  Out of order now · Due this week.
- Table `.tl-list` using the standard admin table classes from housekeeping.php / room-maintenance.php, every column
  header with `data-help`. Housekeeping columns: Room, Type, Status, Task, Assigned to, Priority, Due, Actions.
  Maintenance columns: Room, Issue, Status, Priority, From, To, Blocks room, Actions.
  Row actions: at most 2 visible (Start/Done or Edit) + `.actions-more-menu`.
- Paginate 10 per page with the shared auto pager + `.pg-size` 10/25/50/All (admin-components.css 2305).
- Phone/tablet: the shared card mode + `.rh-col-help` "What the columns mean" disclosure.
- Link "Open full Housekeeping page" / "Open full Maintenance page" top right (secondary).

## 10. Accessibility
- `.tl-grid` `role="grid"` + `aria-label="Room timeline, 4 to 17 Oct"`, `aria-rowcount`/`aria-colcount`;
  rows `role="row"`, room cells `role="rowheader"`, date cells `role="columnheader"` (full date in `aria-label`),
  day cells `role="gridcell"`; bars are buttons inside the check-in gridcell.
- Roving tabindex: one Tab stop into the grid (first bar of the first visible row). Arrows Left/Right = previous/next
  bar in the row, Up/Down = nearest bar in the row above/below, Home/End = first/last bar, Enter/Space = drawer,
  Esc = close drawer/cancel drag. Keyboard users move/change dates through the drawer actions (drag is never the only way).
- All bar and flag colours meet AA (table in 5); states also differ by border style or icon, never colour alone.
- Focus ring `--tl-focus` on every control; `prefers-reduced-motion`: no drawer slide, no smooth scroll;
  `prefers-contrast: more`: bars get a 2px #1f2a37 border, hatch darker.
- Live region `.tl-status` (`aria-live="polite"`) announces range changes and saved moves.

## UI_DECISION lines
UI_DECISION | Timeline default, Month secondary (`?view=month`), tabs Calendar/Housekeeping/Maintenance on `.ck-tabs` | admin/calendar.php | owner FEATURE 2026-11-21, reuse dashboard tab strip
UI_DECISION | bar colours = `.badge-*` values, no gradient tokens | timeline bars, drawer, agenda | one style per state across screens
UI_DECISION | half-day bars | timeline | same-day turnovers and gaps visible
UI_DECISION | Month view recoloured to the same values: `.legend-dot.reserved` and reserved cells -> #047857, `.legend-dot.occupied` and occupied cells -> #1d4ed8 (CSS only, admin/css/calendar.css) | Month view | blue must not mean "occupied" in one view and "reserved" one click away; existing colours, no new token
UI_DECISION | drawer/sheet from modal parts, 2 visible actions + `.actions-more-menu`, AdminConfirm before any save | timeline | held row-actions + modal patterns
UI_DECISION | `.help` + `data-help` on summary rows, cards and columns; `.pg-size` pager; `.ck-kpi` filter cards | timeline + tabs | owner list/helper rules
UI_DECISION | no drag on touch; 44px rows; phone Agenda | phone/tablet | touch-first staff screens

## UI_HANDBACK lines
UI_HANDBACK | dept-rooms-lead | admin/api (new range endpoint) | one JSON per range: bookings (status, room, dates, guests, rate/package, paid state, notes flag, joined/group flags), blocks, maintenance, housekeeping state per room, per-day occupancy/arrivals/departures
UI_HANDBACK | dept-rooms-lead | move/resize save | reuse existing room-assignment and date-change paths with per-room FOR UPDATE locks, availability + price recalculation shown in the confirm; accounting lead reviews price changes
UI_HANDBACK | dept-rooms-lead | create-booking.php | accept prefill params room, check_in, check_out
UI_HANDBACK | dept-rooms-lead | housekeeping state | derive clean/dirty/cleaning/inspection as in 6; action buttons follow existing permissions
