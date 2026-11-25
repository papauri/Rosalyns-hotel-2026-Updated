# Conference packages + conference faults: build plan (2026-11-26)

FEATURE, owner's words 2026-11-26: "conference package is only for conference rooms and should have the full logic
like the room logic but only for conferences and then fix any other faults and small faults both have including
reporting". Owner of the plan: dept-events-gym-lead. Money diffs gated by dept-accounting-lead.

**Every dispatch brief for this plan must carry this line:** ROSALYN'S IS LIVE (real guests and money). Build and
test at Liwonde only. No DB tests, scripts, writes or test emails against Rosalyn's. No git from department agents.

Sections (offset-read only yours): 1 Owner rules · 2 Schema + migration 092 · 3 Price rules + examples ·
4 Override + repricing · 5 B1 backend · 6 S1 screens · 7 B2 reporting · 8 B3 documents + emails · 9 Tests ·
10 Browser check · 11 Order + port · 12 ASSUMPTIONs · 13 LATER (not built) · 14 Spec-check rules (binding)

## 1. Owner rules (2026-11-26)
- Price types: per event, per day, per delegate per day. Delegates = `conference_inquiries.number_of_attendees`,
  used as `max(1, n)`.
- The guest picks packages online on `conference.php`. Staff add or remove them on the enquiry in
  `admin/conference-management.php`. The total follows.
- When the event changes (days, delegates, room), per-day and per-delegate lines reprice automatically from the unit
  price saved on the line. Per-event lines stay as they are.
- A package the new room does not allow is flagged to staff. It is never silently kept or dropped.
- Conferences carry VAT (mode from settings), and **never a tourism levy**. Keep it that way.
- Cancel: no auto-refund. The bill is voided and money paid stays as credit owed (existing cancel path, unchanged).
  Package lines are kept on a cancelled enquiry for the record.

## 2. Schema + migration `admin/migrations/092_conference_packages.php`
Format as in 091 (`name` / `check` / `up`, CREATE TABLE IF NOT EXISTS, SHOW COLUMNS guards, ROLLBACK comment). It is
additive and idempotent: no FKs, no UPDATE backfill of existing rows. Before writing it, B1 reads the real types of
`conference_inquiries.id`, `conference_inquiries.total_amount` and `conference_rooms.id` from `information_schema` at
Liwonde (read-only).
- `inquiry_id` must match `conference_inquiries.id`'s signedness.
- Checked at Liwonde 2026-11-26: `conference_inquiries.id` and `conference_rooms.id` are INT (signed);
  `total_amount`, `vat_amount`, `total_with_vat` are DECIMAL(10,2) (max 99,999,999.99). Not altered. Worst case
  15,000 × 500 × 14 = 105,000,000 does not fit, so the server REFUSES any save whose total or VAT-included total
  would pass 99,999,999.99 (section 14). Line columns are DECIMAL(12,2) so a line itself never truncates.
- Agents run 092 at Liwonde only. Rosalyn's gets it through the owner's normal deploy/migrate path, never from an agent.

**Table `conference_packages` (catalog)**
| column | type |
|---|---|
| id | INT UNSIGNED AUTO_INCREMENT PK |
| name | VARCHAR(100) NOT NULL, UNIQUE KEY uq_conf_pkg_name |
| short_description | VARCHAR(255) NULL |
| description | TEXT NULL |
| icon | VARCHAR(60) NULL |
| price_type | ENUM('per_event','per_day','per_delegate_per_day') NOT NULL DEFAULT 'per_event' |
| price_amount | DECIMAL(12,2) NOT NULL DEFAULT 0 |
| inclusions | TEXT NULL (JSON list of strings) |
| applies_to | ENUM('all','rooms') NOT NULL DEFAULT 'all' |
| conference_room_ids | TEXT NULL (JSON int array, used when applies_to='rooms') |
| is_featured | TINYINT(1) NOT NULL DEFAULT 0 |
| is_active | TINYINT(1) NOT NULL DEFAULT 1 |
| sort_order | SMALLINT UNSIGNED NOT NULL DEFAULT 0 |
| created_at / updated_at | DATETIME DEFAULT CURRENT_TIMESTAMP / ON UPDATE CURRENT_TIMESTAMP |

**Table `conference_inquiry_packages` (snapshot lines, like `booking_packages`)**
| column | type |
|---|---|
| id | INT UNSIGNED AUTO_INCREMENT PK |
| inquiry_id | INT NOT NULL (matches the signed INT id), KEY idx_cip_inquiry |
| package_id | INT UNSIGNED NULL (catalog id; kept as-is if the catalog row is later deleted) |
| package_name | VARCHAR(100) NOT NULL (snapshot) |
| price_type | ENUM(same 3) NOT NULL (snapshot) |
| unit_price | DECIMAL(12,2) NOT NULL (snapshot, never changes after the line is added) |
| days | SMALLINT UNSIGNED NOT NULL DEFAULT 1 |
| delegates | SMALLINT UNSIGNED NOT NULL DEFAULT 1 |
| total_cost | DECIMAL(12,2) NOT NULL |
| needs_review | TINYINT(1) NOT NULL DEFAULT 0 |
| review_reason | VARCHAR(255) NULL |
| added_by | INT UNSIGNED NULL (NULL = guest online) |
| added_at | DATETIME DEFAULT CURRENT_TIMESTAMP |
| | UNIQUE KEY uq_cip_line (inquiry_id, package_id) |

**New columns on `conference_inquiries`** (each guarded by SHOW COLUMNS):
- `package_total DECIMAL(12,2) NOT NULL DEFAULT 0`: sum of the line totals, same price basis as `total_amount`.
- `booked_daily_rate DECIMAL(10,2) NULL` and `booked_half_day_rate DECIMAL(10,2) NULL`: room rates at booking (fault 5).
  NULL = legacy row.

**Invariant:** venue (room hire) = `total_amount − package_total`. Nothing stores it. Any other writer of
`total_amount` therefore simply moves the venue part. Writers today:
- `admin/conference-management.php` update_schedule :802 and update_amount :833
- `conference.php` INSERT
- `finance-account-sync.php` :252/:263 (VAT re-lock only when `total_with_vat` = 0, `total_amount` untouched)

`includes/ai/*` and `api/*` do not write `total_amount`.

Deleting a catalog package never cascades. Enquiry lines keep their snapshot (name, type, unit price). `package_id`
stays as it is.

## 3. Price rules + worked examples
- Line cost (round 2):
  - per_event = unit
  - per_day = unit × days
  - per_delegate_per_day = unit × max(1, delegates) × days
- days = `rh_conf_days(event_date, end_date)`. A half-day event counts as 1 day.
- `total_amount` = venue + `package_total`, then lock `vat_rate`, `vat_amount` and `total_with_vat` from
  `vat_components(total_amount)`.
- Package prices are on the same basis as room rates: incl. VAT in inclusive mode, before VAT in exclusive mode.
  No levy.
- Line text everywhere follows the booked-rate rule: "Lunch: 15,000 × 40 delegates × 3 days = 1,800,000",
  "Projector & PA: 30,000 × 3 days", "Banner: 50,000 per event".

Examples use VAT 16.5%; room daily 250,000, half day 150,000; Tea break per_delegate_per_day 5,000; Lunch
per_delegate_per_day 15,000; Projector & PA per_day 30,000; Banner per_event 50,000.
| case | venue | packages | total_amount | inclusive: net / VAT / gross | exclusive: VAT / gross |
|---|---|---|---|---|---|
| A half day, 40 delegates, Tea + Projector | 150,000 | Tea 200,000 + Proj 30,000 = 230,000 | 380,000 | 326,180.26 / 53,819.74 / 380,000 | 62,700 / 442,700 |
| B 3 days, 40 delegates, all four | 750,000 | Lunch 1,800,000 + Tea 600,000 + Proj 90,000 + Banner 50,000 = 2,540,000 | 3,290,000 | 2,824,034.33 / 465,965.67 / 3,290,000 | 542,850 / 3,832,850 |
| C = B moved to 2 days, 35 delegates | 500,000 | Lunch 1,050,000 + Tea 350,000 + Proj 60,000 + Banner 50,000 = 1,510,000 | 2,010,000 | per vat_components | per vat_components |
| D catalog Lunch raised to 18,000 after B booked | unchanged | Lunch line keeps 15,000 unit | unchanged | | |
| E B with manual venue 600,000, moved to 2 days | 600,000 kept (500,000 only if "replace" ticked) | repriced as C = 1,510,000 | 2,110,000 | | |
| F B moved to a room where Banner is not allowed | per room rule | Banner line flagged "Not offered in <room>", still charged until staff Keep or Remove | | | |

## 4. Staff override + repricing
- **Manual venue.** Staff's "Update Amount" becomes **"Venue price (room hire)"** (label adds "incl. VAT" or "before
  VAT" by `vat_mode()`). Saving sets `total_amount = venue + package_total` and re-locks VAT. Packages are never
  inside the manual figure; they are separate lines and always follow their own rules.
- **Is the venue manual?** (fault 5) Compare `total_amount − package_total` with `rh_conf_price()` run on the **booked
  rates** (`booked_daily_rate` / `booked_half_day_rate`) for the OLD dates. Legacy rows with NULL booked rates fall
  back to the current room rates (today's behaviour; ASSUMPTION A5).
- **Event change** (dates/times, delegates, room; one action, see B1):
  1. First decide `$wasManual` from the OLD row (old dates, old room, old booked rates). Then build the new venue
     suggestion: same room uses the booked rates; a changed room uses the new room's current rates, written to
     `booked_*` only after the manual test.
  2. Venue becomes the suggestion only if (not manual) or "replace" ticked, and the user has `conference_financials`.
     Otherwise the venue is kept.
  3. Every line is recomputed: per_day / per_delegate_per_day from the line's `unit_price` × new days/delegates;
     per_event unchanged.
  4. On a room change, a line whose catalog package (if it still exists) does not allow the new room gets
     `needs_review=1` and `review_reason='Not offered in <room name>'`. It stays charged until staff Keep (clears the
     flag) or Remove.
  5. `package_total` and `total_amount` are written, VAT is re-locked at the current rate (as update_amount does
     today), and the payment snapshot is synced, all in one transaction.
- **History.** Every reprice, add, remove and keep writes a log row naming the line, old → new line total and
  old → new enquiry total (`logActivity` / `rh_log_event`, the same as the existing update_amount logging). The
  enquiry History then shows each step.
- **Paid more than the new total** (line removed, fewer delegates). The existing sync shows it as credit owed /
  overpayment. Grep confirmed nothing on the update_amount path issues a credit note automatically; staff issue one
  from the existing credit-note screen, as today. Nothing new.
- **Who may change what.** Dates/times stay open to `conference` users, as today. The Delegates and Room fields need
  `conference_financials` outright. Lines always reprice on any allowed event change (owner rule). Only the venue
  suggestion is gated by `conference_financials`.

## 5. B1 backend (Opus, dept-events-gym-lead)
**Status at plan time:** Liwonde HEAD 65acb65. `config/invoice.php` and `includes/booked-rate.php` are now committed.
Another session has guides + `includes/ai/*` staged. Do not touch those; run `git status` before starting.

**`includes/conference-pricing.php`** (new helpers, all feature-detected with `rh_conf_has_column`):
- `rh_conf_has_packages(PDO)`: both tables + `package_total` exist.
- `rh_conf_delegates(row)`: `max(1, (int)number_of_attendees)`.
- `rh_conf_package_cost(type, unit, days, delegates)`: the section 3 rule, round 2.
- `rh_conf_packages_for_room(PDO, roomId)`: active catalog rows, `applies_to='all'` or roomId in the JSON, ordered
  by sort_order, name.
- `rh_conf_package_allowed(pkgRow, roomId)`.
- `rh_conf_package_lines(PDO, inquiryId)`.
- `rh_conf_line_text(line)`: the "unit × N delegates × N days" wording.
- `rh_conf_venue_amount(row)` = `total_amount − package_total`, floored at 0.
- `rh_conf_apply_totals(PDO, inquiryId, ?float newVenue, ?int roomId)`: reprices all lines from the row's current
  dates/delegates, flags disallowed lines when roomId changed, writes `package_total`, `total_amount`, `vat_rate`,
  `vat_amount` and `total_with_vat`, then calls `syncConferenceInquiryPaymentSnapshot`. It runs inside the caller's
  transaction and returns old/new figures for logging.
- `rh_conf_package_gross_sql(alias)`: the VAT-included package figure for reports, scaled by the row's LOCKED VAT
  (never the current `vat_mode()`): `package_total * total_with_vat / NULLIF(total_amount,0)`, falling back to
  `package_total` when `total_with_vat` is 0. Line totals in reports/EOD scale by the same row factor.
- `rh_conf_price()` gains optional booked-rate overrides (`daily_rate` / `half_day_rate` taken from the row when
  set). The signature stays backward compatible.

**`conference.php` (server part)**
- Read `package_ids[]` as ints (cap 20, unique).
- Each must be in `rh_conf_packages_for_room(room)`. Otherwise refuse with "One of the packages you picked is not
  offered for this room. Please pick again." Never silently drop.
- One transaction:
  1. INSERT the enquiry with `total_amount` = venue + packages, plus **the VAT split** (`vat_rate`, `vat_amount`,
     `total_with_vat` via `vat_components`). This is a Liwonde fault: Rosalyn's already has it at :289-296, and
     Liwonde's `$conference_vat_amount` / `$conference_total_wv` are undefined.
  2. Write `booked_daily_rate`, `booked_half_day_rate` and `package_total`.
  3. Insert the lines.
  4. Run the end_date UPDATE.
- **Fault 4:** `'id' => $new_inquiry_id` instead of the second `lastInsertId()` (:336).
- Pass the lines to the emails (B3).

**`admin/conference-management.php` (server part)**
- `update_schedule` becomes the "update event" action. It also accepts optional `number_of_attendees` (1-500) and
  `conference_room_id` (an active room). Rules:
  - Clash check when confirmed, including the new room.
  - Capacity above the room's limit is a warning only, as confirm does today.
  - Delegates and room fields need `conference_financials` outright (section 4).
  - Then `rh_conf_apply_totals`.
  - **Fault 5:** `$wasManual` uses the booked rates (section 4).
- New actions `add_package` (package_id), `remove_package` (line_id) and `keep_package` (line_id; clears the flag).
  Rules:
  - Need `conference_financials`. Add them to the :466 list.
  - Only for `pending` / `confirmed` enquiries.
  - Add refuses a package not allowed for the enquiry's room, and a duplicate (unique key).
  - Remove is a DELETE of the line plus the log row.
  - Each runs in a transaction ending in `rh_conf_apply_totals`, CSRF as the existing actions, AJAX JSON like the
    page's other actions.
- `update_amount`: the posted figure is the venue. `total_amount = venue + package_total`.
- `delete_enquiry`: also deletes its `conference_inquiry_packages` lines in the same transaction. Cancel keeps them.
- List query (:907): add `package_total` and the line count. Keep the price-suggest code (:929) working with the
  booked rates.

**Catalog server: `admin/conference-packages.php`** (actions only; screen in S1)
- Clone of the `admin/packages.php` save/toggle/delete pattern: validation, unique slug-free name check,
  `rh_find_duplicate` + `rh_with_create_lock`, `rh_log_event`, AJAX JSON.
- price 0..99,999,999.99. price_type limited to the 3 values. `conference_room_ids` must be existing room ids.
- Permissions:
  - Page view `'conference'`.
  - Writes need `'conference_rooms'`, the same people who set room rates (ASSUMPTION A3).
- Add the page to both maps in `admin/includes/permissions.php` (~2141 page → permission `'conference'`, ~2314
  page → module `'conference'`).
- Add `'conference_package' => conference_packages.name` to `admin/api/check-unique.php`.

**Other**
- `scripts/data-health.php`: orphan WARN for `conference_inquiry_packages` whose `inquiry_id` has no enquiry.
- Rosalyn's `.claude/plans/linked-data-cleanup.md`: one line "conference_inquiry_packages → conference_inquiries:
  deleted with the enquiry, kept on cancel". Write it at port time; Liwonde has no plans/ folder.
- `.claude/GUIDES_AI_SYNC.md` Pending (orchestrator at ship, NOT B1: the file is staged by the guides/AI sync
  session; add it after that session commits): one entry. Staff can add or remove conference packages; the price types; the
  repricing and flag rules; the Venue price label. The Assistant must know the enquiry total now includes package
  lines. Never edit `includes/ai/*`.
- Write `scripts/tests/conference_packages_test.php` (section 9).

## 6. S1 screens (Sonnet, `model: opus` where a screen posts money; ui-lead for look)
**`admin/conference-packages.php` (screen)**
- Card list as in `packages.php`, paginated 10 with the 10/25/50/All picker (`admin-section-pagination.js`).
- Modal inside `#rh-admin-page` before `admin-footer.php`. Fields: name, short description, description, icon,
  price type, price (label "incl. VAT" / "before VAT"), inclusions, "All rooms" or chosen conference rooms,
  featured, active, sort.
- Live duplicate-name check. Empty state text.
- `?` helpers on price type and price ("charged per event / per day / per delegate per day; delegates = attendees on
  the enquiry"). No Facebook share.
- Nav item in `admin/includes/admin-nav-items.php` "Conference & Events" group after Conference:
  `['conference-packages.php','fas fa-box','Conference Packages','conference','','conference']`.

**`admin/conference-management.php` enquiry modal** (`showEnquiryDetails` ~2011)
- "Packages" table: package, how charged (line text), line total, flag badge "Not offered in <room>" with Keep /
  Remove. `?` helpers on Line total ("unit price saved when added × delegates × days, from this enquiry") and on
  Packages total.
- "Add package" select (packages allowed for this room, not already on it) + Add button.
- "Venue price (room hire)" replaces the "Update Total Amount" label. Show Venue, Packages and Total incl. VAT
  separately.
- The schedule form gains Delegates and Conference room fields; the "replace" checkbox text becomes "Replace the venue
  price with the suggested price even if it was set manually".
- Buttons show only with `conference_financials`, for pending/confirmed.
- List column (or line in the existing total cell) "Packages" with a `?` helper.

**`conference.php` (public modal; look owned by dept-guest-site-lead, so UI_REQUEST to it / ui-lead)**
- Fieldset "Add packages (optional)", checkboxes filtered by the chosen room in JS (room → allowed ids rendered
  as data attributes).
- Each item shows price + how charged. A live estimate line, "Estimated total: venue + packages", recalculates on
  date/time/attendees change using the same rules (display only; the server recomputes).
- Escape all names.

**`conference-confirmation.php`**
- Price label from the booked rates (:100-107) instead of current room rates.
- List the package lines (line text + total). Total stays `rh_conf_gross()`.

## 7. B2 reporting (Opus; liaise with dept-eod-lead for the EOD pane)
- **Fault 3**, `admin/includes/eod-revenue.php:243`: replace the hand-written fallback with `rh_conf_gross_sql()`.
  Events at :255 are LATER.
- **EOD pane** (`admin/end-of-day-report.php` "Pricing, packages & quotations", near :1901): add
  `$kv('Conference package revenue', …'VAT included')`, packages sold (line count) and top conference package.
  Basis: confirmed/completed enquiries with `event_date` = the report day (the same basis eod-revenue uses for
  conference revenue). Use `rh_conf_package_gross_sql()`; for the top package, scale line `total_cost` by the same row factor.
  `?` helper on each. Tell dept-eod-lead (`.claude/EOD_SPEC.md` line).
- **Fault 1**, `api/reports-export.php` exportConferenceReport (~756-805) and `admin/reports.php` conference tab
  (:625-654):
  - Filter on `event_date >= :start AND event_date < DATE_ADD(:end, INTERVAL 1 DAY)`, not `created_at` and not `<=`.
  - Revenue sums only `status IN ('confirmed','completed')`, via `CASE WHEN`, using `rh_conf_gross_sql()`.
  - The status table still counts every status.
- **New "Conference packages" section** in both: package name, times sold, revenue incl. VAT. Same filter and status basis. `?` helpers on every column; 10 per page with the picker on the
  screen; the CSV gets the same columns.

## 8. B3 documents + emails (Opus; accounting lead reviews)
- **Fault 2, quotation** `includes/quotation-pdf.php` generateConferenceQuotationPDF:
  - :343 deposit = `deposit_required ? (float)deposit_amount : 0`. The flag is never printed as money.
  - Items: "Room hire - <room> (<label>)" at the venue amount, then one item per package line (line text, total).
  - Totals unchanged (`rh_pdf_vat_rows` on `total_amount`).
  - The custom-template path gets new var `{{package_lines}}` (escaped HTML rows) (ASSUMPTION A6).
- **Fault 2, invoice** `config/invoice.php`:
  - `rh_inv_flat_invoice` (:582) accepts an optional `'lines'` array (desc_html, qty, amount). With no `lines`, it
    behaves exactly as today, so room/gym/event callers are untouched.
  - `buildConferenceInvoiceHTML` (:3423): venue line + package lines; deposit block `paid` =
    `min(amount_paid, deposit_amount)` instead of the 0/1 flag (:3532-3536).
  - Check `git status` first. If another session holds `config/invoice.php` uncommitted, wait and do this part last.
- **Emails** `config/email.php`:
  - sendConferenceEnquiryEmail (:3637), sendConferenceAdminNotificationEmail (:3768), sendConferenceConfirmedEmail
    (:4017) and sendConferenceQuotationEmail (:7482) add a "Packages" block. Build `packages_html` in PHP with
    `htmlspecialchars` on every value (loadEmailTemplate does not escape).
  - Add `{{#if packages_html}}{{packages_html}}{{/if}}` to `templates/emails/conference-enquiry-customer.html` and
    `conference-enquiry-admin.html`.
  - Payment and cancelled emails: no change.
  - No test emails. Liwonde sending is muted; check the HTML through the test harness only.

## 9. Tests: `scripts/tests/conference_packages_test.php` (written by B1, extended by B2/B3)
Liwonde DB only, everything in a transaction that is rolled back (pattern: `scripts/tests/conference_full_total.php`,
`page_harness.php`). No DDL. If 092 is not applied, print "Run migration 092 at Liwonde first" and exit 1. The
area run at ship: this file + `conference_full_total.php`; money + migration means the full suite at ship (Liwonde).
1. `rh_conf_package_cost` for the 3 types, delegates 0 → 1, half day = 1 day.
2. Examples A, B, C (section 3) in inclusive AND exclusive mode (forced inside the rolled-back run): `package_total`,
   `total_amount`, `vat_amount`, `total_with_vat` equal `vat_components` of the expected base.
3. D: a catalog price change leaves the line unit price alone.
4. E: a manual venue is kept on reschedule; replace ticked → suggestion; packages repriced either way.
5. Fault 5: room rate raised after booking, rescheduled with no manual change → not treated as manual (booked rates).
6. Legacy row (NULL booked rates, `package_total` 0) still reprices as today.
7. F: room change flags a disallowed line; Keep clears it; Remove deletes it and lowers the total; log rows written.
8. Guest POST with a disallowed package id → refused, nothing inserted. With allowed ids → lines + VAT split saved and
   `id` in the result = the new enquiry (fault 4).
9. Permission: add/remove/keep without `conference_financials` refused; catalog save without `conference_rooms`
   refused.
10. delete_enquiry removes its lines; cancel keeps them, and the bill is voided as before.
11. Fault 2: the quotation deposit shows `deposit_amount` (e.g. 500,000), never 1. The invoice "Deposit paid" =
    min(paid, deposit_amount).
12. Fault 3: EOD conference revenue in inclusive mode with `total_with_vat` 0 does not add VAT twice.
13. Package gross on an inclusive-locked row read while the mode is forced to exclusive = the locked figure (no VAT
    added on top).
14. Fault 1: an event dated in range but created outside is counted. An event on end+1 day 00:00 is not. A pending
    enquiry's revenue is excluded. The package section sums match.

## 10. Browser check (headless, `php -S 0.0.0.0:8000 -t . scripts/tests/dev-router.php` + `node scripts/tests/browser_check.mjs`, 1400 and 390; stop the server after)
- `admin/conference-packages.php` (list, empty state, modal open)
- `admin/conference-management.php` (enquiry modal with packages, flag badge)
- `conference.php` (modal with packages, estimate)
- `conference-confirmation.php`
- `admin/reports.php` conference tab
- `admin/end-of-day-report.php` pricing pane

## 11. Order + port
Coordinator 2026-11-26: build B1, S1, B2, then STOP before B3 and report (another builder holds `config/email.php`
and `config/invoice.php`). No commits by the lead.

1. **B1** (migration 092 at Liwonde, helpers, public server, admin actions, faults 4 + 5, catalog server, data-health,
   tests 1-10).
2. **S1** (screens).
3. **B2** (fault 1 + 3, report + EOD lines, tests 12-14).
4. **B3** (fault 2, documents + emails, test 11; invoice part last if invoice.php is held).

Gates:
- Each step: `php -l`, its tests, qa-auditor, plus the dept-accounting-lead review for B1/B2/B3 money paths.
- ui-lead for S1.
- Browser check for S1 + B2 screens.
- One commit per step at Liwonde.

**Port to Rosalyn's:** by hunk, checking each diff. Rosalyn's `conference.php:292-295` runs
`vat_components($total_amount)` on the venue only: the port must add the package sum to `$total_amount` BEFORE that
call (the account sync at `finance-account-sync.php:250` never re-locks a row whose `total_with_vat` is set, so a
late add would leave VAT on the venue only). Do not run `port-commit.php` blindly on `conference.php` or the
email templates; they differ per hotel. Liwonde's VAT-split insert fix only brings Liwonde up to Rosalyn's, so it is
not ported. Rosalyn's gets the package hunks + fault 4. No tests or migration runs at Rosalyn's from agents.

## 12. ASSUMPTIONs (log in BUILD_PLAN.md)
- A1 (confirm with owner; top one). Staff can change delegates and conference room on an enquiry. No path exists
  today, and the owner's repricing rule ("days, delegates, room") needs one. It is built into the existing
  event-change form.
- A2. A half-day event counts as 1 day for per-day and per-delegate-per-day packages.
- A3. Catalog writes need `conference_rooms` (who sets room rates). Enquiry lines need `conference_financials`.
  Page view needs `conference`.
- A4. A guest picking a package not offered for the room gets an error (room logic silently drops it).
- A5. Legacy enquiries (no booked rates) use current room rates for the "manual?" test, as today.
- A6. Custom quotation templates get `{{package_lines}}`. Without it they show the total only.
- A7. Removing a line deletes it (logged with old → new totals). Cancel keeps lines.
- A8. Reports and EOD date conference package revenue by `event_date` (the event's first day), like conference
  revenue today.
- A9 (build 2026-11-26). Test 10 "cancel keeps lines" is not run: the cancel path writes log files and sends email,
  and it never touches package lines (code read). delete_enquiry removing lines is tested.
- A10 (build 2026-11-26). The public package picker on conference.php uses the form's existing checkbox style plus
  small `.conf-pkg-pick*` rules in `css/components/forms.css`. Its look is dept-guest-site-lead's. Filed (B3):
  `UI_REQUEST | conference.php + css/components/forms.css | enquiry modal, "Packages" picker (#confPkgPick, .conf-pkg-pick*) and its estimate line | works and fits at 390, but was styled minimally by the events lead, not designed to match the guest site | dept-guest-site-lead / ui-lead give it the guest-site form look; logic, ids, data-* and the hide-when-not-allowed rule stay as they are`
- A12 (B3). Help on `conference-packages.php` opens `10-conference-events.html#conf-amount` (no packages section in the
  guides yet; the guides sync adds one and can repoint `rh_kb_sys_page_guides()`).
- A13 (B3). Documents: room hire = `total_amount - package_total`, each package its stored `total_cost`, so the rows
  add up to `total_amount` and the existing VAT rows/totals are unchanged. Emails label the block "(before VAT)" when the
  stored total had VAT added on top (`total_with_vat > total_amount`), judged from the row, not the current setting.
- A11 (build 2026-11-26). The Reports "Room utilisation" Events column still lists pending enquiries (cancelled
  excluded, as before); only its revenue is limited to confirmed / completed.

## 13. LATER (found, not in scope, not built)
- Gym and event invoices print the deposit flag as money (`config/invoice.php` ~3902, ~4231).
- The gym report export uses `<= DATE_ADD` (double midnight).
- The events EOD fallback adds VAT twice in inclusive mode (`eod-revenue.php:255`).
- `loadEmailTemplate` does not escape `{{values}}` (security, dept-patch-lead: guest fields reach the conference
  enquiry emails raw; the B3 packages block escapes itself).
- `api/payments.php:415` returns the conference `deposit_required` flag as a money float.

## 14. Spec-check rules (binding, coordinator 2026-11-26)
1. **Locking.** `admin/conference-management.php:458` reads the enquiry without a lock, before any transaction. Every
   money action (update_schedule/event change, update_amount, add_package, remove_package, keep_package) opens the
   transaction, then `SELECT * FROM conference_inquiries WHERE id = ? FOR UPDATE` FIRST, then locks the old and new
   conference rooms in ascending id order (`SELECT id FROM conference_rooms WHERE id IN (..) ORDER BY id FOR UPDATE`).
   `$wasManual`, the status check and the reprice are decided from that locked row, never from the pre-read.
   `rh_conf_apply_totals` refuses to run outside a transaction and re-reads the row FOR UPDATE itself (a re-lock by
   the same transaction is free).
2. **History.** Writer: `logActivity()` (admin/includes/permissions.php:2051, table `admin_activity_log`), action
   `conference_price_change`, called after commit. Screen: System Logs (`admin/system-logs.php`, "Recent Admin
   Activity"). Conferences have no per-enquiry History panel (`rh_history_payload` covers room bookings and payments
   only); none is built here. Logged with the enquiry reference and old → new: package add, remove, keep, every
   reprice from an event change (each changed line + total), and update_amount (venue and total, old → new; today it
   logs nothing).
3. **Port note** in section 11 (Rosalyn's VAT split must see venue + packages).
4. **Overflow.** `total_amount` / `total_with_vat` are DECIMAL(10,2). Any save (guest form, event change, add,
   update_amount) is refused with a plain message when the new total or its VAT-included total would pass
   99,999,999.99 (`RH_CONF_MAX_TOTAL`). The existing update_amount cap of 99,999,999 (:821) becomes this check.
5. **Note.** `rh_conf_gross_sql()`'s fallback (conference-pricing.php:111) reads the CURRENT `vat_mode()`; it only
   applies to rows whose `total_with_vat` is 0. New and repriced rows always lock `total_with_vat`, so the fallback
   only touches legacy rows. `rh_conf_package_gross_sql()` scales by the locked row instead.
6. **Permission map** checked: `admin/includes/permissions.php` page → permission map ~2141 and page → module map
   ~2314 both get `'conference-packages.php' => 'conference'`; permissions `conference` (:550),
   `conference_rooms` (:558), `conference_financials` (:566) already exist, so no new permission key.
