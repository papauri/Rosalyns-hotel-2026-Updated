# BUILD_PLAN — Rosalyn's Hotel 2026

> Owned by build-planner. One objective per cycle. Every task must reference the
> PROJECT_CONTEXT.md gap it closes. Status values: `queued` · `in-progress` · `done` ·
> `blocked: <question for owner>` · `failed-twice: <reason>`.
>
> **Fixed end goal, not open-ended looping.** The checklist below (owner-approved
> 2026-07-14) is the entire scope. Every task in every phase must trace to exactly one
> checklist item. build-planner may NOT invent deliverables outside this list — anything
> else discovered gets logged under "Future Ideas" and flagged "out of scope, needs
> approval," never silently queued. /build-loop stops (not pauses) once every item below
> is `[x]`.

## PROJECT COMPLETE WHEN

**Safety net & audit (Phase 0/1 — closes gap #1):**
- [x] SYSTEM_MAP.md documents all four code trees (root pages, includes/config, admin/,
      api/) with zero unexplained dead files
- [x] Booking availability + pricing logic covered by an automated smoke test
      (`scripts/smoke_test_booking.php`)
- [x] Finance sequence numbering + money-tolerance logic covered by an automated smoke
      test (`scripts/smoke_test_finance.php`)
- [x] All POS/admin money comparisons use `BALANCE_TOLERANCE` consistently (no raw
      float/ad-hoc tolerance)
- [x] All admin POST handlers use the standard CSRF validator (backup-management.php fixed)
- [x] All api/ endpoints reachable and auth-guarded via the router (blocked-dates.php
      fixed; page-content.php has explicit visibility guard + rate limiting)
- [x] No sensitive error detail (DB host/user/stack trace) reachable by a public visitor;
      `display_errors` off under both Apache and PHP-FPM

**Guest communication lifecycle (Phase 2 — closes gap #3):**
- [x] Guest receives an automated pre-arrival reminder email (toggleable in admin, off
      by default)
- [x] Guest receives an automated post-stay review-request email linking to
      `submit-review.php` (toggleable in admin, off by default)

**Performance (Phase 2 — closes gap #4):**
- [x] `booking.php` page weight/latency measured, with any high-impact fixes applied

**Accessibility & tablet UX (Phase 3 — closes gaps #5/#6):**
- [x] Public booking flow (widget → confirmation) passes an accessibility check: keyboard
      nav, contrast, screen-reader labels, 320px width
- [x] POS + KDS admin screens usable on tablet (768–1024px, 44px touch targets)
- [x] Check-in + housekeeping admin screens usable on tablet
- [x] Public-page CSS is visually consistent across modules (no drift)

**Owner decisions required (block their own checklist item — cannot be resolved by an
agent; hard-stop rails forbid deleting files or acting on payment/credentials without
explicit confirmation):**
- [x] `includes/seo-meta.php` — RESOLVED 2026-07-14: not actually unused (initial scout
      finding was a false positive — it's required live by `booking-confirmation.php:88`).
      Nothing to delete, no owner decision needed. Kept as-is.
- [x] Online payment capture at booking time — RESOLVED 2026-07-14: owner chose to keep
      manual settlement-at-check-in. No gateway built. Decided: no.

**Round 2 — owner-added scope (2026-07-14, added after the first PROJECT COMPLETE):**
- [x] Admin list views (e.g. "All Room Bookings" in `admin/bookings.php`) render as data
      tables on standard laptop screens (≥1024px, 14-15in), with the card layout reserved
      for tablet/mobile breakpoints. Fix at the shared-component level if a common admin
      responsive pattern is causing the premature card-switch, so the fix applies
      consistently rather than page-by-page.

**Round 3 — owner-approved scope (2026-07-14, added after the second PROJECT COMPLETE):**
- [x] `SYSTEM_MAP.md` corrected: remove the stale "DEAD" flag on `includes/seo-meta.php`
      at both flagged locations (it's a live dependency of `booking-confirmation.php:88`)
- [x] `includes/security.php` / `config/security.php` duplication consolidated into one
      canonical file, all callers updated, no behavior change (investigation found
      `includes/security.php` doesn't exist — end-state already true, fixed a stale
      SYSTEM_MAP.md phantom row instead of forcing an unneeded merge)
- [x] `scripts/smoke_test_booking.php` section 8 no longer leaves state that causes a
      duplicate-key failure on repeated runs (test-isolation fix only, no production code)
- [x] `admin/bookings.php`'s inline check-in shortcut meets the same 44px tablet
      touch-target standard already applied to the canonical check-in screen in P3-03

## Future Ideas (not in scope — logged only, never auto-queued)

- OTA/channel-manager sync (rate parity, subscription cost — needs owner input first)
- Framework rewrite / migrating off procedural PHP
- Multi-property support
- Any deliverable discovered mid-build that isn't one of the checklist items above gets
  logged here with a one-line description and "needs owner approval to add to scope" —
  it does NOT get queued into a phase table.

## Old "definition of built to completion" (superseded 2026-07-14 by the checklist above)

Kept for traceability — every item below maps 1:1 onto a PROJECT COMPLETE WHEN line item.

1. **Money paths are safe**: booking → confirmation → payment recording → invoice/receipt,
   and POS order → payment → EOD report, each covered by a repeatable smoke test; all money
   comparisons use `BALANCE_TOLERANCE`; no payment/refund handler lacks CSRF + permission checks.
2. **A guest can complete a booking end-to-end on a phone** — 320px wide, no horizontal
   scroll, keyboard-accessible, with a clear payment or settlement step (per owner's
   decision on gap #2) and an automated confirmation email.
3. **Guest communication lifecycle exists**: confirmation + pre-arrival reminder +
   post-stay review request emails, template-driven, toggleable in admin.
4. **Staff daily workflows pass a tablet check**: POS, KDS, check-in, housekeeping usable
   at 768–1024px with 44px touch targets.
5. **No blocker-grade audit findings**: every POST handler validates CSRF; every query is
   prepared; every user-data echo is escaped; `display_errors` off in prod config; no dead
   admin pages reachable; `php -l` clean across changed surface.
6. **SYSTEM_MAP.md covers all four trees** (root pages, `admin/`, `api/`, `includes/`+`config/`)
   and lists zero unexplained dead files.

## Phase 0 — Learn (scout + verify assumptions)
| ID | Task | Checklist item | Status |
|----|------|-----------|--------|
| P0-01 | Scout maps root public pages (`*.php` at root) → SYSTEM_MAP.md | prereq | done |
| P0-02 | Scout maps `includes/` + `config/` shared layer | prereq | done |
| P0-03 | Scout maps `admin/` (split: bookings/finance · POS/stock · gym/events/content) — 3 scout calls | prereq | done |
| P0-04 | Scout maps `api/` router + endpoints | prereq | done |
| P0-05 | Verify payment capture: does `booking.php` take online payment or record-only? Document finding in PROJECT_CONTEXT.md gap #2 | #2 | done (answered by P0-01 scout) |
| P0-06 | Verify test suite: locate any PHPUnit tests/config; document what exists | #1 | done |
| P0-07 | Verify guest email lifecycle: which emails fire on booking/check-in/check-out today | #3 | done (answered by P0-01 scout) |

## Phase 1 — Stabilise (safety net + audit fixes)
| ID | Task | Checklist item | Status |
|----|------|-----------|--------|
| P1-01 | Extend `scripts/smoke_test_booking.php` (existing 265-line live-DB smoke script — no PHPUnit despite composer require-dev) to cover availability + pricing functions in `includes/booking-functions.php`, `includes/pricing.php` | #1 | done |
| P1-02 | Add a `scripts/smoke_test_finance.php` sibling script (same pattern as smoke_test_booking.php) for finance sequences + BALANCE_TOLERANCE money paths (`includes/finance-sequences.php`); also fix the POS money-comparison inconsistency flagged in P0-03 | #1 | done |
| P1-03 | Fix Phase 0 audit findings — split into P1-03c/P1-03ef/P1-03g, all done; (a)(b)(d) required no action (see notes below) | #1 | done |
| P1-04 | Remove dead files/dead code identified in SYSTEM_MAP.md (list requires owner sign-off before deletion) | #1 | done (resolved 2026-07-14 — original candidate was a false positive, nothing to delete) |
| P1-05 | Prod error-handling check: `display_errors` off, errors logged, no sensitive `die()` on public paths | #1 | done |

## Phase 2 — Complete (close functional gaps toward the bar)
| ID | Task | Checklist item | Status |
|----|------|-----------|--------|
| P2-01 | Payment-at-booking step | Owner decision: online payment capture | done (owner chose manual settlement, 2026-07-14) |
| P2-02 | Pre-arrival reminder email (template + cron script + admin toggle) | Guest comms: pre-arrival email | done |
| P2-03 | Post-stay review-request email linking to `submit-review.php` | Guest comms: post-stay email | done |
| P2-04 | Booking page weight/latency pass: measure rendered size + cache hits on `booking.php`, act on findings | Performance: booking.php weight/latency | done |

## Phase 3 — Polish
| ID | Task | Checklist item | Status |
|----|------|-----------|--------|
| P3-01 | Public booking flow accessibility pass (booking widget → confirmation) | Accessibility: public booking flow | done |
| P3-02 | Tablet pass on POS + KDS (`admin/pos.php`, `admin/kds.php`) | Tablet UX: POS + KDS | done (evidence-based no-action — already tablet-optimized) |
| P3-03 | Tablet pass on check-in + housekeeping (**check-in UI is `admin/booking-details.php`, NOT process-checkin.php — see correction below**, `admin/housekeeping.php`) | Tablet UX: check-in + housekeeping | done |
| P3-04 | Public-page visual consistency sweep (per-module CSS drift) | Accessibility: public-page CSS consistency | done |
| P3-05 | Raise the admin list-table card-switch threshold so `.tablet-table` tables (e.g. `bookings.php` "All Room Bookings") stay real data tables on laptop/desktop (>1024px viewport); cards reserved for tablet/mobile (≤1024px). Shared fix in `admin/js/admin-mobile.js`. | Round 2: admin list views render as tables on ≥1024px laptops | done |

## Round 3 — owner-approved scope (2026-07-14)
| ID | Task | Checklist item | Status |
|----|------|-----------|--------|
| R3-01 | Correct `SYSTEM_MAP.md`: remove the stale "DEAD" flag on `includes/seo-meta.php` at both flagged locations (lines 193 & 230) — it is a live dependency of `booking-confirmation.php:88`. Doc-only, no application code. | Round 3: SYSTEM_MAP.md seo-meta.php DEAD flag corrected | done |
| R3-02 | Resolve the `includes/security.php` / `config/security.php` "duplication". Investigation found **`includes/security.php` does not exist** — there is only ONE file (`config/security.php`) and all 7 callers already require it. The consolidated end-state the checklist describes already holds; there is NO code merge to do. Only actionable residue: correct the phantom "duplicate" row in `SYSTEM_MAP.md` (line 156). Doc-only, no application code. | Round 3: security.php duplication consolidated into one canonical file | done |
| R3-03 | Make `scripts/smoke_test_booking.php` test-isolation-safe: add an idempotent pre-test purge of leftover SMOKETEST rows and a guaranteed (shutdown-function) cleanup so an aborted prior run can never leave state that duplicate-keys the next run. Test-only file — zero production/application code. | Round 3: smoke_test_booking.php section 8 no longer leaves duplicate-key state on repeated runs | done |
| R3-04 | Raise the `admin/bookings.php` inline check-in shortcut (`.actions-row .quick-action.checkin` / `.checkin--urgent`, styled in `admin/css/bookings.css`) to a ≥44px touch target in the 768–1024px tablet band via a `@media (max-width: 1024px)` block, mirroring P3-03. CSS-only, no JS. | Round 3: bookings.php inline check-in meets the 44px tablet touch-target standard | done |

### R3-01 — dispatch brief

**Goal:** Fix the two stale "DEAD" annotations on `includes/seo-meta.php` in
`.claude/SYSTEM_MAP.md` so the map reflects reality: the file is an ACTIVE dependency of
the guest-facing booking confirmation page, not dead code. Documentation correction only —
touch NO application code.

**Specialist:** codebase-scout (haiku) — it owns SYSTEM_MAP.md and made the original
(incorrect) entries, so it makes the correction. No backend/frontend/ui agent needed.

**Exact edits (2 locations in `.claude/SYSTEM_MAP.md`):**

1. **Line 193** (includes-layer inventory row). Current text ends with:
   `...Fixes quote encoding; **DEAD: Not referenced from any page** | None | ~200 lines`
   Replace the `**DEAD: Not referenced from any page**` clause with:
   `**ACTIVE** — required by `booking-confirmation.php:88` (`require_once 'includes/seo-meta.php'`) to build the confirmation page `<head>` meta from `$seo_data``
   Update the incoming-references cell (currently `None`) to reference
   `booking-confirmation.php:88`.

2. **Line 230** (dead-file evidence table row). Current row:
   `| `includes/seo-meta.php` | **DEAD** | No references found in grep search. File contains SEO meta tag builders but never require'd or included from any page. Consider removing or documenting deprecation. |`
   Replace with an ACTIVE row:
   `| `includes/seo-meta.php` | **ACTIVE** | Required via `require_once 'includes/seo-meta.php'` at `booking-confirmation.php:88` (builds confirmation-page meta from `$seo_data`, lines 81-89). Original Phase-0 "zero references" grep missed this include — corrected 2026-07-14. |`

**What NOT to touch:** any file other than `.claude/SYSTEM_MAP.md`; the summary lines at
356/651/785 ("NO DEAD FILES DETECTED") are correct and stay as-is; do not renumber or
reflow other table rows.

**Acceptance criteria:**
- Grep for `DEAD` in `.claude/SYSTEM_MAP.md` returns zero matches on the `seo-meta.php`
  rows (both line 193 and line 230 now read `**ACTIVE**`).
- Both corrected entries cite `booking-confirmation.php:88` as the live reference.
- No changes to any `.php` file (git diff touches only `.claude/SYSTEM_MAP.md`).
- SYSTEM_MAP.md summary line 356 ("NO DEAD FILES DETECTED") remains accurate and now
  consistent with the corrected includes rows.

### R3-02 — investigation findings

**The premise is false — there is no duplication.** Deep investigation (2026-07-14):

- `includes/security.php` **does not exist**. `Glob **/security.php` returns exactly one
  file: `config/security.php`. There is no second copy anywhere in the tree.
- `config/security.php` (206 lines) is the single canonical security helper. It contains:
  `sendSecurityHeaders()` (CSP/HSTS/X-Frame-Options), `sanitizeInput()`,
  `sanitizeInputArray()`, `generateCsrfToken()`, `validateCsrfToken()`, `getCsrfField()`,
  `requireCsrfValidation()`, `logSecurityEvent()`.
- **All 7 callers already require the canonical file** (`require_once .../config/security.php`):
  `api/cancel-order.php:25`, `api/kds-action.php:22`, `api/pos-notifications.php:19`,
  `api/pos-tab-detail.php:16`, `api/void-order.php:19`, `admin/admin-init.php:53`,
  `admin/api/api-init.php:37`. Zero callers reference `includes/security.php`.
- The only mentions of `includes/security.php` anywhere are (a) the checklist line itself
  and the Future-Ideas note in this file, and (b) one stale row in `SYSTEM_MAP.md:156`
  that describes `includes/security.php` as a "Duplicate of config/security.php" — a
  phantom entry for a file that is not on disk.

**Conclusion:** The end-state the checklist describes ("consolidated into one canonical
file, all callers updated, no behavior change") is ALREADY TRUE. `config/security.php` is
the sole file; every caller uses it; there is nothing to merge, no caller to repoint, and
**no application code should be changed** (forcing a merge here would be inventing risk
where none exists). The only residue is a documentation inaccuracy in SYSTEM_MAP.md.

ASSUMPTION: `includes/security.php` was either never committed or was consolidated in an
earlier, unlogged pass; git history is not needed to act — the current tree is
authoritative and shows a single canonical file.

### R3-02 — dispatch brief

**Goal:** Correct the phantom "duplicate" row for `includes/security.php` in
`.claude/SYSTEM_MAP.md` so the map reflects reality: only `config/security.php` exists and
it is the single canonical security helper. Documentation correction only — touch NO
application code (there is none to change; see findings above).

**Specialist:** codebase-scout (haiku) — it owns SYSTEM_MAP.md and wrote the original
(incorrect) phantom-duplicate row, so it makes the correction. No backend/frontend/ui
agent is warranted: there is no code merge, no caller repoint, no `.php` file to edit.

**Exact edit (1 location in `.claude/SYSTEM_MAP.md`):**

- **Line 156**, current row:
  `` | `includes/security.php` | Duplicate of config/security.php included in some places | Same as config/security.php | Redundant — config/security.php is the primary | None | Avoid dual-include | ``
  This describes a file that does not exist on disk (`Glob **/security.php` → only
  `config/security.php`). Remove this row entirely. If a placeholder is preferred over
  deletion, replace it with a note row:
  `` | `includes/security.php` | **DOES NOT EXIST** — no such file in the tree (verified `Glob **/security.php` → only `config/security.php`). All 7 security callers require `config/security.php` directly. Row retained only to correct the earlier phantom-duplicate entry. | — | — | — | — | ``
- **Line 145** (the `config/security.php` row) is CORRECT and stays as-is; do NOT touch it.

**What NOT to touch:** any `.php` file (zero application-code changes); `config/security.php`
itself; the `config/security.php` inventory row at line 145; any other SYSTEM_MAP row.

**Acceptance criteria:**
- `Glob **/security.php` in the repo still returns exactly one file (`config/security.php`) —
  proving no file was created/moved.
- `git diff` touches only `.claude/SYSTEM_MAP.md` (no `.php` files, no callers changed).
- SYSTEM_MAP.md no longer presents `includes/security.php` as an existing duplicate; the
  line-156 row is removed or replaced with the "DOES NOT EXIST" note above.
- Grep for `includes/security.php` across the repo returns matches only in `.claude/`
  planning docs, never in a `require`/`include` statement in application code.

**QA gate:** qa-auditor **haiku** — this is a documentation-only correction with zero
application-code changes; no security logic is touched, so the sonnet logic/security gate
is not required. (Parent flagged "likely sonnet" on the assumption real security code would
be merged; that assumption is void because there is nothing to merge.)

**QA gate:** qa-auditor (haiku) — doc-only, no logic/security surface. Verify the two rows
now read ACTIVE, cite booking-confirmation.php:88, and that no application code changed.

### R3-03 — investigation findings (2026-07-14)

**File:** `scripts/smoke_test_booking.php` (324 lines, live-DB smoke test, cleans up its own
data). Relevant regions: section 5 standard-booking insert (lines 74–112), section 8
tentative-booking insert (lines 142–191), section 17 cleanup (lines 311–317).

**What section 8 creates:** one `bookings` row with
`booking_reference = 'SMOKETEST-TENT-' . time()` (line 144),
`client_uuid = bin2hex(random_bytes(16))` (line 145), `status='tentative'`, dates +60/+62
days. Its id is appended to `$createdIds` (line 177). Section 5 similarly creates
`'SMOKETEST-' . time()` (line 77).

**Which unique key it collides on:** SYSTEM_MAP.md:158/215 documents a **UNIQUE DB index on
`bookings.client_uuid`** (the "ultimate guarantee" for idempotency), and `booking.php:661`
treats **`booking_reference`** as unique via an app-level COUNT check. `client_uuid` is
freshly randomised each run so it cannot collide across runs. **`booking_reference` is the
cross-run collision surface:** `time()` has 1-second resolution, so two runs launched inside
the same wall-clock second generate the *identical* `SMOKETEST-TENT-<time>` /
`SMOKETEST-<time>` reference. If the earlier run's cleanup left that row behind, the next
same-second run's INSERT hits a duplicate reference.

**Why cleanup can fail to run (the root cause):** the section-17 cleanup DELETE (lines
313–317) is a single statement at the very END of the script — it is **not** wrapped in
try/finally and **not** registered as a shutdown hook. The `assert_true()` helper does not
throw, but sections 9–16 call real library functions (`getExpiredTentativeBookings()`,
`markTentativeBookingExpired()`, `applyDynamicPricing()`, `checkAvailability()`) and do raw
`$stmt->fetch()` then index into the result (e.g. `$expiredRow['status']` at line 210,
`$cancelledRow['status']` at line 241) with no null guard. Any Throwable or fatal there
aborts the script BEFORE line 313, orphaning both the section-5 and section-8 rows. On the
next run those orphans sit in the table waiting for a same-second (or reused-fixture)
collision.

**Minimal, column-agnostic fix (test-only):** two complementary changes that make the script
idempotent regardless of which column carries the constraint:
1. **Idempotent pre-test purge** — before any INSERT, delete leftover fixtures from prior
   aborted runs. This alone guarantees no duplicate-key on rerun even after a dirty exit.
2. **Guaranteed cleanup** — run the section-17 cleanup via `register_shutdown_function` (or a
   try/finally spanning the create→cleanup region) so an early Throwable can no longer orphan
   rows in the first place.

Both edits touch ONLY `scripts/smoke_test_booking.php`. No production/application code, no
`includes/`, no schema change.

ASSUMPTION: the SMOKETEST fixtures are safe to purge unconditionally by their fixed
`booking_reference` prefix (`SMOKETEST-`, `SMOKETEST-TENT-`) and fixed test emails
(`smoketest@rosalyns.test`, `tenttest@rosalyns.test`) — these strings appear nowhere in real
booking data. Purging by prefix+test-email is the same delete surface the script already owns
via `$createdIds`; it only additionally sweeps rows a prior crash abandoned.

### R3-03 — dispatch brief → backend-specialist

**Goal:** Make `scripts/smoke_test_booking.php` re-runnable back-to-back with no duplicate-key
failure, by (a) purging any leftover SMOKETEST fixtures before the inserts and (b) guaranteeing
the cleanup runs even if the script aborts mid-way. **Test-only file — change NOTHING outside
`scripts/smoke_test_booking.php`; no production/application/`includes/`/schema changes.**

**Specialist:** backend-specialist (sonnet).

**Exact edits (all inside `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\scripts\smoke_test_booking.php`):**

1. **Add an idempotent pre-test purge** immediately after section 1's DB-connectivity check
   (after line 44, before section 2). Use a prepared statement to delete leftover fixtures:
   `DELETE FROM bookings WHERE booking_reference LIKE 'SMOKETEST-%' OR guest_email IN
   ('smoketest@rosalyns.test','tenttest@rosalyns.test')`. Echo a one-line notice of how many
   rows it swept (e.g. `"  Pre-test purge: removed N leftover fixture row(s)\n"`). This runs
   BEFORE the section-5 and section-8 inserts so any orphan from a prior aborted run is gone.

2. **Guarantee the cleanup runs on abort.** Convert the section-17 cleanup (lines 311–317)
   into a cleanup that executes even if a Throwable aborts sections 9–16. Preferred approach:
   register a shutdown handler near the top (after `$createdIds = [];` at line 18) that DELETEs
   `$createdIds` (guard with `if (!empty($createdIds))` and `try/catch` so the shutdown handler
   never itself fatals), and reduce section 17 to a no-op notice (or keep it but make the
   shutdown the guarantor). Because `$createdIds` is a global populated as rows are inserted,
   the shutdown handler must read it via `global $createdIds;` (or `use (&$createdIds)` on a
   closure). Ensure the DELETE is a prepared statement with `IN (...)` placeholders exactly as
   the current section-17 code does.

3. (Optional hardening, only if trivial) strengthen the per-run identifier so same-second
   reruns never collide even before the purge takes effect: change `'SMOKETEST-' . time()`
   (line 77) and `'SMOKETEST-TENT-' . time()` (line 144) to append a short random suffix, e.g.
   `. time() . '-' . bin2hex(random_bytes(3))`. Keep the `SMOKETEST-`/`SMOKETEST-TENT-`
   prefixes intact so the pre-test purge in edit 1 still matches them.

**What NOT to touch:** every assertion, every section's logic, the DB helper includes at the
top, any `includes/` or `config/` file, the schema, and any other script. Do not change what
the test asserts — only its setup/cleanup isolation.

**Acceptance criteria (R3-03):**
1. `php -l scripts/smoke_test_booking.php` passes.
2. Running the script twice in immediate succession (`php scripts/smoke_test_booking.php` then
   again) produces the **same pass/fail summary both times** and **neither run emits a
   duplicate-key / SQLSTATE 23000 error** in section 5 or section 8.
3. After a normal run completes, `SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE
   'SMOKETEST-%'` returns 0 (no fixtures left behind).
4. Even if the script is aborted mid-run (e.g. simulate by a Throwable before section 17), the
   NEXT run starts clean — the pre-test purge removes the orphan and the run does not
   duplicate-key. (QA may verify by inserting a matching orphan row by hand, then confirming a
   fresh run purges it and passes.)
5. The delete surface is limited to the SMOKETEST fixtures (prefix + the two test emails) — no
   real booking data is touched. Purge/cleanup DELETEs are prepared statements.
6. Only `scripts/smoke_test_booking.php` is modified (git diff touches no other file).

**QA gate:** qa-auditor **haiku** — this is a test-isolation change to a standalone smoke
script; no production code, no money/security logic, no reused booking-creation path (the
inserts are inline SQL local to the script, not a shared function). Haiku verifies `php -l`,
that only the one file changed, that the purge/cleanup DELETEs are prepared statements scoped
to the SMOKETEST fixtures, and that a double-run leaves zero fixtures. Escalate to sonnet ONLY
if the specialist unexpectedly touches a shared `includes/` booking helper (it should not).

### R3-04 — investigation findings (2026-07-14)

**What the "inline check-in shortcut" actually is.** In `admin/bookings.php` each booking
row renders a row of icon-only quick-action buttons inside `<div class="actions-row">`. The
inline check-in control is a `<button class="quick-action checkin ...">` (standard) at
**bookings.php:3654** and its urgent/late variant `<button class="quick-action checkin--urgent
...">` at **bookings.php:3643** (rendered when `$is_missed_checkin`). Both carry
`data-action="check-in"` and are handled by the delegated click listener at
**bookings.php:7480** (`event.target.closest('[data-action]')`) which opens the check-in modal
— so the control is a **button, not a link, and its click behaviour is pure JS delegation
unaffected by sizing**. (The modal's own submit button `#checkin_submit_btn` at
bookings.php:5404 is a full-width `.btn.btn-primary` inside `.modal-footer` and already meets
touch size; the checklist item is about the *inline row shortcut*, i.e. the `.quick-action`.)

**Which CSS actually styles it (verified, not assumed).** `Grep .quick-action` across
`admin/css/*.css` returns 6 files, but the canonical, highest-specificity rule that sizes the
bookings row buttons is in **`admin/css/bookings.css`** under the scoped selector
**`.actions-row .quick-action`** (base rule at **bookings.css:660–679**). The other five files'
`.quick-action` matches are unrelated components (finance/user-management/conference/admin-
components) that do not use the `.actions-row .quick-action` compound and do not style
bookings.php's row. `bookings.php` links `bookings.css` as its page stylesheet.

**Current computed height at 768–1024px (computed the P3-03 way, from the CSS).**
- Base `.actions-row .quick-action` (bookings.css:665–668): `width: 28px; height: 28px;
  min-width: 28px; padding: 0` → a fixed **28×28px** icon square.
- The only tablet-band override is `@media (min-width: 36rem)` (bookings.css:695–710), scoped to
  `table.mobile-enhanced td[data-label="Action"|"Actions"] .actions-row .quick-action`, which
  sets `width: auto; min-width: 7.25–9.2rem; padding-inline: …` and reveals the text label — it
  widens the button but **sets no height**, so `height: 28px` from the base rule still governs.
- Per P3-05 the bookings list renders as `mobile-enhanced` cards at ≤1024px, so in the
  768–1024px band the check-in button is the wide labelled variant but still **28px tall**.
- No existing rule raises `.quick-action` height in the ≤1024px band (`@media (max-width: 64rem)`
  at bookings.css:134 targets only `.bookings-alert-banner__action`, not `.quick-action`).
- **Conclusion: the inline check-in shortcut computes to 28px tall at 768–1024px — 16px under
  the 44px standard.** Width already clears 44px in the labelled state; the deficient axis is
  height. No JS change is needed (behaviour is data-action delegation; only sizing is wrong).

ASSUMPTION: raising the shared base selector `.actions-row .quick-action` (rather than only the
`.checkin`/`.checkin--urgent` modifiers) is the correct mechanism, because every button in the
row shares that base rule and its 28px height. Lifting only the check-in button to 44px would
leave its siblings at 28px and produce a mis-aligned, uneven action row — itself a layout
regression. Raising the shared control keeps the row uniform and, as a free correctness bonus,
brings the sibling actions (checkout, cancel, confirm, etc.) up to the same tablet touch
standard. The checklist item (check-in shortcut ≥44px) is satisfied either way; the shared-
selector route is the one that introduces NO alignment regression.

### R3-04 — dispatch brief → frontend-specialist

**Goal:** In the 768–1024px tablet band, make the `admin/bookings.php` inline check-in shortcut
(and, via the shared base rule, its row siblings) a ≥44px touch target, matching the P3-03
standard. **CSS-only, single file, single new media block. Do NOT touch JS, markup, or any
other CSS file.**

**Specialist:** frontend-specialist (sonnet — CSS responsive work; no logic).

**Exact edit (one file: `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\admin\css\bookings.css`):**
- Add ONE new `@media (max-width: 1024px)` block (mirroring P3-03's kds.css-style breakpoint —
  use `1024px` literal to match P3-03's convention, not `64rem`, so the tablet touch rule is
  greppable as its own block) containing exactly:
  ```
  @media (max-width: 1024px) {
      .actions-row .quick-action {
          min-height: 44px;
          min-width: 44px;
      }
  }
  ```
  `min-height: 44px` overrides the base `height: 28px` floor (used height becomes 44px);
  `min-width: 44px` guarantees the icon-only fallback is also ≥44px square while NOT shrinking
  the wider labelled variant (that rule's higher-specificity `min-width: 7.25rem` still wins).
- Place the block near the existing `.actions-row .quick-action` rules (after line ~935, i.e.
  after the urgent-state rules) or at the end of the quick-action section — wherever it reads
  cleanly; it must be a self-contained new block, not edits woven into existing rules.

**What NOT to touch:** `admin/bookings.php` (no markup/JS change — the button already has correct
`data-action`, `aria-label`, `title`); the base `.actions-row .quick-action` rule at lines
660–679 (leave the 28px desktop base intact — desktop data-table rows stay compact per P3-05);
the `@media (min-width: 36rem)` labelled-button rule (lines 695–710); the `@media (max-width:
64rem)` alert-banner block (line 134); the other five CSS files that also contain `.quick-action`;
any JS file; any other page. No new selectors beyond `.actions-row .quick-action` inside the one
new media block. No `!important`.

**Acceptance criteria (R3-04):**
1. `admin/bookings.php`'s inline check-in button (`.actions-row .quick-action.checkin` and
   `.checkin--urgent`) computes to a **height ≥ 44px** at viewport widths 768px and 1024px
   (verify from the CSS: the new `@media (max-width: 1024px)` `min-height: 44px` governs).
2. The touch-target increase applies to the shared `.actions-row .quick-action` control so the
   whole action row stays vertically uniform (no single tall button among 28px siblings) — i.e.
   **no alignment/layout regression beyond the intended height increase**.
3. Above 1024px (laptop/desktop data-table view) the buttons stay at the existing compact 28px —
   the new rule is inside `@media (max-width: 1024px)` and does not leak upward.
4. Change is confined to a single new `@media (max-width: 1024px)` block targeting only
   `.actions-row .quick-action` in `admin/css/bookings.css`; **git diff touches no other file**
   and no other selector/rule is modified. No JS, no markup, no `!important`.
5. CSS remains valid (no syntax error; the file still parses — balanced braces).

**QA gate:** qa-auditor **haiku** — pure CSS sizing change, identical shape to P3-03's haiku
gate (min-height touch-target bump in a `@media (max-width: 1024px)` block). No PHP, no money,
no security, no logic surface. Haiku verifies: only `admin/css/bookings.css` changed; exactly
one new `@media (max-width: 1024px)` block; it sets `min-height: 44px` (and `min-width: 44px`)
on `.actions-row .quick-action`; braces balanced; the base 28px desktop rule and the labelled-
button rule are untouched. (No sonnet needed — no JS logic is involved; the check-in behaviour
is JS delegation the CSS never touches.)

## Blocked / decisions needed from owner

### P1-04 — RESOLVED, no longer blocked (corrected 2026-07-14)

**The original deletion candidate was a false positive.** During P3-01 investigation,
`booking-confirmation.php:88` was found to contain `require_once 'includes/seo-meta.php';`
— a live, active dependency of a guest-facing confirmation page. SYSTEM_MAP.md:193/:230's
"zero references" claim was a scout miss (the P0-02 scout's grep evidently didn't catch
this include). **Deleting this file would have fataled booking-confirmation.php** — good
thing this was never actioned without owner sign-off, exactly as the hard-stop rail intends.

Every other tree still reports no dead files (root pages, admin/ ×3 scouts, api/ — see
below, unaffected by this correction). With the one candidate invalidated, **there is
nothing left to delete** — P1-04 closes with zero code changes, no owner decision needed.

- Root public pages (28 files): none dead.
- admin/ bookings & finance (32 files): "NO DEAD FILES DETECTED" (SYSTEM_MAP.md:356).
- admin/ POS, stock, gym (23 files): all ACTIVE via admin-header.php nav (SYSTEM_MAP.md:508-513).
- admin/ content, events, integrations, system (31 files): "NO DEAD FILES DETECTED" (:651).
- api/ (23 endpoints): "NO DEAD ENDPOINTS DETECTED" (:785).
- `includes/security.php` — still a future *consolidation* candidate (duplicate of
  `config/security.php`), NOT dead, NOT a deletion candidate. Logged under Future Ideas.

SYSTEM_MAP.md:193/:230 should be corrected to remove the stale "DEAD" flag on
`includes/seo-meta.php` — logged as a follow-up, not blocking.

**⚠ CORRECTION (2026-07-14, found during P3-01 investigation) — `includes/seo-meta.php` is NOT
dead. DO NOT DELETE IT.** The Phase-0 scout's "zero references" finding (SYSTEM_MAP.md:193, :230)
is WRONG: `booking-confirmation.php:88` does `require_once 'includes/seo-meta.php';` (it builds the
confirmation page's `<head>` meta from a `$seo_data` array at booking-confirmation.php:81-89).
Deleting the file would fatal `booking-confirmation.php` — a live guest-facing page in the very
flow P3-01 covers. **The P1-04 deletion candidate is therefore void:** seo-meta.php is an ACTIVE
dependency, not dead code. SYSTEM_MAP.md:193 and :230 should be corrected (fold into P1-04
cleanup). This removes P1-04's only deletion candidate — P1-04 may now be a no-op (nothing dead to
delete) rather than a pending deletion; owner confirmation only needed to formally close it.

### P2-01 — RESOLVED: owner chose manual settlement (2026-07-14)

**Owner decision:** keep manual payment settlement at check-in. No online payment gateway will
be built. Zero code changes required — this closes the item as "intentionally out of scope,"
per option 1 of the original question. The conversion-gap tradeoff vs Cloudbeds/SiteMinder
(PROJECT_CONTEXT.md gap #2) is accepted as-is.

## P2-02 + P2-03 dispatch — Guest communication lifecycle (2026-07-13)

**Closes gap #3** (completion-definition item 3: "confirmation + pre-arrival reminder +
post-stay review request emails, template-driven, toggleable in admin"). Confirmation emails
already exist (P0-07); this adds the two missing lifecycle stages. Moves toward the
Cloudbeds/Mews bar: automated pre-arrival and post-stay guest communication is a cheap,
high-retention/high-review-volume win they all ship and this system currently lacks.

**Combined into ONE dispatch** (one backend-specialist, one session): P2-02 and P2-03 are the
same shape — query bookings by a stay-milestone date, send one email via a new `config/email.php`
function, dedupe via one shared log table, gate behind an admin toggle, run from one daily cron
script. Splitting them would duplicate the log table, the lib scaffold, and the cron entry point.
Each acceptance criterion below is independently verifiable.

### Established patterns to mirror (do NOT invent new ones)
- **Cron engine + lib split:** `scripts/gym_membership_reminders.php` (CLI guard, `flock` lock,
  `--quiet`, requires config/database.php + config/email.php + a lib, prints a summary, exit
  codes) + `admin/includes/gym-reminders-lib.php` (settings reader, one sweep function per stage,
  claim-log-row-FIRST-then-send-then-unclaim-on-failure idempotency). Read both — they are the
  exact template.
- **Idempotency log:** `gym-reminders-lib.php:22-36,103-136` — a log table with a UNIQUE key,
  `INSERT IGNORE` to claim, `rowCount()===0` means already-sent → skip, DELETE to release on send
  failure. **No standalone .sql migration exists in the tree** (glob found none; the gym one is
  referenced but the migrations dir isn't materialised here). Therefore the lib MUST self-create
  its log table via `CREATE TABLE IF NOT EXISTS` on first use — mirror the contact-us.php
  `CREATE TABLE IF NOT EXISTS contact_inquiries` approach (SYSTEM_MAP.md:45). Do NOT add a
  migration-file dependency; the feature must work on a fresh DB with no migration step.
- **Guest email function:** `config/email.php` `sendGymRenewalReminderEmail()` (line 6882) — takes
  an array, uses `global $email_site_name`, builds HTML, escapes every dynamic value with
  `htmlspecialchars()`, wraps via `wrapEmailTemplate($html, $title)`, returns
  `sendEmail($to, $name, $subject, $htmlBody, $altBody)` which yields `['success'=>bool,...]`.
  Copy this structure exactly for the two new functions. `sendBookingReceivedEmail()` (line 2087,
  `global $pdo`) shows the room lookup + `check_in_date`/`check_out_date`/`guest_email`/
  `guest_name`/`booking_reference`/`room_id` column names to use.
- **Cron registration:** do NOT edit `scripts/setup-cron.sh` / `setup-windows-task-scheduler.ps1`
  (verified: those are single-task installers for `scheduled-cache-clear.php` only). The
  established pattern for a new job is a documented cron/Task-Scheduler invocation line in the
  new script's own header comment block, exactly as `gym_membership_reminders.php:10-16` does.
- **Admin toggle persistence:** `getSetting($key,$default)` read / `updateSetting($key,$val)` write
  — the settings pattern used throughout `admin/booking-settings.php` (e.g. lines 699-705) and
  `gym-reminders-lib.php:42-43`. NO new settings table; site_settings rows only.

### Files to CREATE
1. **`scripts/guest_lifecycle_emails.php`** — cron entry point, structural copy of
   `scripts/gym_membership_reminders.php` (same CLI-only guard incl. the `?web=1` + admin/manager
   session escape hatch, same `flock` lock with a distinct lock filename e.g.
   `rh_guest_lifecycle.lock`, same `--quiet` handling). Requires config/database.php, config/email.php,
   and the new lib. Runs BOTH sweep functions in sequence, prints a per-stage summary
   (checked/sent/skipped/errors), exits non-zero if any errors. Header comment documents the daily
   cron line AND the Windows Task Scheduler equivalent (mirror gym script header).
2. **`admin/includes/guest-lifecycle-lib.php`** — the engine (functions guarded by
   `function_exists`, mirroring gym-reminders-lib.php):
   - `guest_lifecycle_log_ensure(PDO $pdo): void` — `CREATE TABLE IF NOT EXISTS guest_communication_log
     (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NOT NULL, booking_reference VARCHAR(64) NULL,
     stage VARCHAR(20) NOT NULL, sent_to VARCHAR(255) NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
     UNIQUE KEY uniq_booking_stage (booking_id, stage))`. Call it at the top of each sweep.
   - `guest_lifecycle_settings(): array` — reads `booking_prearrival_reminder_enabled` (default '0'),
     `booking_prearrival_reminder_days` (clamp 1..14, default 1), `booking_poststay_review_enabled`
     (default '0'), `booking_poststay_review_days` (clamp 0..14, default 1).
   - `guest_run_prearrival_reminders(PDO $pdo): array` — SELECT bookings WHERE
     `check_in_date = DATE_ADD(CURDATE(), INTERVAL :days DAY)` (exact-day match is fine; window match
     also acceptable if you prefer the gym BETWEEN style — pick one and comment why), status is an
     active pre-stay status (confirmed/pending; EXCLUDE cancelled/tentative/no_show/declined/checked_out),
     `guest_email` present + FILTER_VALIDATE_EMAIL, and NOT already logged for stage='pre_arrival'.
     Claim log row, call `sendPreArrivalReminderEmail()`, unclaim on failure. Return the same
     `{disabled,checked,sent,skipped,errors}` shape as the gym engine.
   - `guest_run_poststay_review_requests(PDO $pdo): array` — SELECT bookings WHERE
     `check_out_date = DATE_SUB(CURDATE(), INTERVAL :days DAY)`, status indicates the stay happened
     (checked_out/completed, and also accept confirmed/pending whose checkout date has passed;
     EXCLUDE cancelled/tentative/no_show/declined), valid email, NOT already logged for
     stage='post_stay'. Send `sendPostStayReviewRequestEmail()`, same idempotency + return shape.
   - **You MUST verify the exact bookings.status string set** before finalising the WHERE clauses:
     grep `status` usage in `admin/create-booking.php` / `admin/booking-details.php` /
     `includes/booking-timeline.php` (do not guess the enum). Escape nothing into SQL — bind :days.

### Functions to ADD to `config/email.php` (append near the other guest emails)
3. **`sendPreArrivalReminderEmail(array $booking): array`** — `global $pdo, $email_site_name`.
   Look up the room by `$booking['room_id']` (mirror line 2093). Build a warm pre-arrival HTML body:
   greet `guest_name`, confirm `booking_reference`, room name, `check_in_date` (format via
   `date('l, F j, Y', strtotime(...))`), and a "we look forward to welcoming you / check-in from …"
   note. Every dynamic value through `htmlspecialchars()`. Wrap with `wrapEmailTemplate($html,
   'Your Stay Is Coming Up')`, return `sendEmail($booking['guest_email'], $booking['guest_name'],
   $subject, $html, $altBody)`. Subject e.g. `'We look forward to welcoming you — ' . $siteName`.
4. **`sendPostStayReviewRequestEmail(array $booking): array`** — same structure; thank the guest for
   staying, then link to the review form. Build the URL with the project's URL helper
   (`require_once`/use `siteUrl('submit-review.php')` from config/base-url.php, or `BASE_URL` — check
   how other emails build absolute links; `$email_site_url` global is already in scope in booking
   emails). Link target is `submit-review.php` (append `?ref=<booking_reference>` only if
   submit-review.php reads it — otherwise plain link; verify submit-review.php's GET handling, do
   not fabricate a query param it ignores). Subject e.g. `'How was your stay? — ' . $siteName`.

### Files to EDIT
5. **`admin/booking-settings.php`** — add a "Guest communication emails" settings card with four
   inputs (two checkboxes + two number inputs) and persist them via `updateSetting()` inside an
   EXISTING POST/save branch (do NOT add a new form action or new page). Place the card in the
   notifications/email area near `booking_notification_email` (~line 673) and add the four
   `updateSetting()` calls in that same save branch, following the exact pattern at lines 699-705.
   Keys: `booking_prearrival_reminder_enabled`, `booking_prearrival_reminder_days`,
   `booking_poststay_review_enabled`, `booking_poststay_review_days`. CSRF is already enforced on
   that form via `validateCsrfToken()` — do not weaken it. Escape any echoed current values.

### Do NOT touch
`booking.php`, `booking-confirmation.php`, `submit-review.php` (read-only reference), the gym
reminder files, `config/database.php`, any migration file, the scheduler installer scripts, or any
finance/POS path. No new Composer packages. No changes to `sendEmail()` / `wrapEmailTemplate()`.

### ASSUMPTIONS (2026-07-13, P2-02/03)
- Both toggles default **OFF (opt-in)**. Auto-emailing guests should require explicit admin
  opt-in; a default-on rollout could surprise the owner with unexpected guest mail. Owner can flip
  them on in booking-settings.php. (Gym reminders defaulted on, but those target members who opted
  into a membership relationship; transactional booking guests are a broader audience.)
- Pre-arrival fires **1 day before** `check_in_date` by default (configurable 1..14).
- Post-stay fires **1 day after** `check_out_date` by default (configurable 0..14; 0 = checkout day).
- Log table `guest_communication_log` is **self-created by the lib** (no migration-file dependency),
  because no migrations dir is materialised in this working tree.
- Combined P2-02+P2-03 into one dispatch (justified above).

### Acceptance criteria (both tasks)
1. `php -l` passes on all touched files: `scripts/guest_lifecycle_emails.php`,
   `admin/includes/guest-lifecycle-lib.php`, `config/email.php`, `admin/booking-settings.php`.
2. `scripts/guest_lifecycle_emails.php` refuses to run over HTTP without the `?web=1` + admin/manager
   session (returns 403) — same guard as the gym script; runs cleanly from CLI.
3. On a DB with no `guest_communication_log` table, the first run creates it (CREATE TABLE IF NOT
   EXISTS) and does not fatal.
4. With both toggles OFF (defaults), each sweep returns `disabled` and sends nothing.
5. Idempotency: running the script twice against the same due bookings sends each guest at most
   once per stage (second run reports them skipped) — enforced by the UNIQUE(booking_id, stage).
6. All bookings SQL is prepared/bound (no interpolation); every dynamic value in both new email
   bodies is `htmlspecialchars()`-escaped; the post-stay email contains a working absolute link to
   `submit-review.php`.
7. `admin/booking-settings.php` saves and reloads all four settings correctly (checkbox reflects
   stored value; days inputs clamp to their ranges); CSRF still enforced on that form.
8. No file outside the CREATE/EDIT lists above is modified.

### QA gate → qa-auditor **sonnet** (guest-facing email sending + DB query correctness + a new
cron entry point + idempotency/security-sensitive dedup logic — logic gate, not lint-only).

## P2-04 dispatch — booking.php weight/latency (build-planner investigation, 2026-07-14)

**Closes the Performance checklist item** ("`booking.php` page weight/latency measured, with any
high-impact fixes applied"). Measure-then-fix task, not a blind rewrite.

### Investigation findings (read-only, evidence-based)
- **The "197 KB" is the SOURCE file size, not the wire size.** `booking.php` is **3822 lines**.
  It decomposes as:
  - Lines **1–1085 (~28%)**: pure PHP server logic (POST handler, availability, pricing, data
    prep) — **never sent to the browser**.
  - Lines **1086–1560 (~12%)**: HTML `<head>` + form markup (dynamic, sent to browser).
  - Lines 1562–1563: external JS (`js/main.js`, flatpickr CDN) — already good.
  - Lines **1593–3816 (~58%, ~2223 lines)**: ONE inline `<script>` block, shipped inline on
    **every** page load and **uncacheable** (it is part of the dynamic POST-handling HTML doc,
    which itself cannot be cached — per-request CSRF token, real-time availability, per-request
    room/blocked-date JSON).
- **CSS/fonts already handled well — NO ACTION:** external `css/base/critical.css` + `css/main.css`,
  Google Fonts async-loaded via the `media="print" onload="this.media='all'"` trick
  (booking.php:1093), external flatpickr CSS. No inline `<style>`, no base64 images, no duplicate
  CSS. `.htaccess:19,43–45` already DEFLATE-compresses + 1-year-caches css/js.
- **Full-page caching is CORRECTLY not applied — NO ACTION:** `startPageCache`/`getPageCache`
  (config/page-cache.php) are referenced ONLY inside config/page-cache.php itself — the full-page
  cache is wired to **no** page, and for booking.php that is the RIGHT call: a POST form with
  per-request CSRF + live availability must not be full-page cached (would serve stale
  availability and break CSRF). Do NOT try to full-page-cache booking.php.
- **The real, high-impact, safe fix — extract the inline JS to a cacheable external file:** the
  ~2150-line pure-JS body is the single biggest guest-facing weight contributor and is re-sent
  uncached on every load. `.htaccess:44–45` already grants `.js` a **1-year browser cache +
  gzip**, and **`js/booking-lookup.js` establishes the page-specific-external-JS precedent**.
  Moving the function body to `js/booking.js` shifts ~90–110 KB of JS off the uncacheable inline
  path onto a cacheable, compressible external asset — a large repeat-visit weight/latency win,
  and a pure relocation (no logic rewrite).
- **Clean extraction boundary:** the ONLY PHP echoes inside the `<script>` are the config/data
  declarations at lines **1621–1662** plus **one** buried echo at line **1979**
  (`maxDate.setDate(maxDate.getDate() + <?php echo $max_advance_days; ?>);`). Everything from
  line 1670 to the `</script>` at 3816 is otherwise pure JS (verified by grep — no other
  `<?php`/`<?=`).

### Dispatch brief → frontend-specialist (asset extraction; performance-only, no logic changes)
Extract the inline booking-form JavaScript from `booking.php` into a new cacheable external file
`js/booking.js`. This is a **pure relocation** — do NOT rewrite, refactor, reorder, minify, or
"improve" the JS; move it verbatim.

Exact changes to `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\booking.php`:
1. The inline `<script>` block opens at **line 1593** and closes at **line 3816**. Split it:
   - **Keep inline** (stays in booking.php, inside a now-small `<script>`): ONLY the PHP-injected
     server-data/config declarations currently at **lines 1621–1645** (`emailReservations`,
     `currencySymbol`, `childPriceMultiplier`, `tourismLevyEnabled`, `tourismLevyPercent`,
     `globalBlockedDates`, `blockedDatesByRoom`, `preselected*`, `hero*`, `roomsData`) and the
     `bookedDatesByRoom` declaration at **line 1662** — plus the shared mutable `let` state
     declarations that sit among them (lines ~1647–1668: `checkInCalendar`, `checkOutCalendar`,
     `selectedRoomId`, `selectedRoomPrice`, `selectedRoomName`, `selectedRoomMaxGuests`,
     `currentDynamicPricing`, `currentPackages`, `selectedPackageIds`, `currentAvailabilityResult`,
     `lastCheckedDateRange`). These declare the top-level globals the external file reads/writes.
   - **Hoist the one buried PHP value:** add to this inline block
     `const maxAdvanceDays = <?php echo (int)$max_advance_days; ?>;`, and change the JS at old
     line 1979 from `... + <?php echo $max_advance_days; ?>` to `... + maxAdvanceDays`.
   - **Move to `js/booking.js`** (verbatim): the two small helpers at lines **1594–1618**
     (`showAvailabilityModal`, `closeAvailabilityModal`, the `window` click listener) AND the
     entire function body + `DOMContentLoaded` wiring from **line 1670 through line 3815**.
2. After the (now-small) inline config `<script>` block, add
   `<script src="js/booking.js"></script>` — placed AFTER the inline config block AND after the
   existing `js/main.js` / flatpickr `<script>` tags (dependencies + config globals must be
   defined before booking.js runs). Load it with the **same plain unversioned `src` style as
   `js/main.js` at line 1562** (project convention — `.htaccess` supplies the 1-year cache).
3. Create `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\js\booking.js` with the
   moved JS. It MUST contain zero `<?php`/`<?=` tags (it is a static asset).

**Do NOT touch:** booking.php PHP logic (lines 1–1085), the POST handler, CSRF token, form fields,
HTML markup (1086–1560), any `includes/`, `config/page-cache.php` (do NOT full-page-cache this
page), CSS, any other page, any other JS file. No minification. No new libraries.

### ASSUMPTIONS (2026-07-14, P2-04)
- Load `js/booking.js` **unversioned** (plain `src="js/booking.js"`) to match the existing
  `js/main.js` convention (booking.php:1562), rather than introducing a `?v=` query-string
  pattern the project doesn't currently use. Tradeoff: on future edits to booking.js the 1-year
  `.htaccess` cache means clients may see a stale copy until forced-refresh — same tradeoff
  main.js already carries; consistency chosen over introducing a new versioning scheme.
- CSS/fonts and full-page-caching angles are closed as **evidence-based no-action** (already
  optimal / legitimately inapplicable — see findings). The single acted-on finding is the inline-
  JS→external-cacheable-file extraction.

### Acceptance criteria (P2-04)
1. New file `js/booking.js` exists, contains the extracted JS, and has **zero** `<?php`/`<?=`
   tags. `node --check js/booking.js` (or equivalent JS syntax check) passes.
2. booking.php's inline `<script>` (opening at old line 1593) now contains ONLY the PHP-injected
   config/data declarations + the shared mutable `let` state declarations + the new
   `maxAdvanceDays` const — **no function bodies** — and is under ~80 lines.
3. booking.php loads `<script src="js/booking.js"></script>` AFTER that inline block and after
   `js/main.js`/flatpickr.
4. The moved JS is byte-identical to the original inline JS EXCEPT the single
   `<?php echo $max_advance_days; ?>` echo is replaced by the `maxAdvanceDays` JS variable.
5. `php -l booking.php` passes. No change to booking.php lines 1–1085 (PHP logic) or the form
   markup/CSRF.
6. No functional regression — booking flow still works end-to-end: load page, pick check-in/
   check-out dates (flatpickr with blocked/booked dates disabled), select a room, availability
   AJAX fires, dynamic price + packages render, guest-allocation validation + submit-gating
   behave, form submits. (Manual smoke by QA.)
7. No file outside `booking.php` and the new `js/booking.js` is modified.

### QA gate → qa-auditor **sonnet**
Behavior-correctness/regression gate, not lint-only: a naive extraction can silently break the
booking form (cross-`<script>`-tag top-level `const`/`let` global scope, script load order,
the `maxAdvanceDays` hoist). Haiku lint would miss a broken booking flow. QA must confirm the
form still functions (or trace the JS dependency graph) in addition to `php -l` + JS syntax check.

## P3-01 dispatch — public booking flow accessibility (build-planner investigation, 2026-07-14)

**Closes the Accessibility checklist item** ("Public booking flow (widget → confirmation)
passes an accessibility check: keyboard nav, contrast, screen-reader labels, 320px width").
Check-then-fix task, not a blind rewrite. Scope = `booking.php` + `booking-confirmation.php` +
their directly-related CSS ONLY (`css/sections/booking.css`, `css/sections/confirmation.css`).

### Investigation findings (evidence-based; 3 of the 4 axes ALREADY PASS — NO ACTION)
- **Keyboard nav — PASS, no action.** Skip link present (`includes/header.php:36`
  `<a href="#main-content" class="skip-to-content">` → `booking.php`/`booking-confirmation.php`
  both wrap content in `<main id="main-content">`). Global keyboard-focus system correct:
  `css/base/reset.css:119-190` uses `:focus{outline:none}` + `:focus-visible{outline:2px gold}` +
  `:focus:not(:focus-visible){outline:none}` (modern correct pattern). Form controls override
  `outline:none` on `:focus` BUT supply a visible replacement — border-color + `0 0 0 3px`
  box-shadow ring (`css/sections/booking.css:171-177` and `:3242-3247`), so keyboard focus stays
  visible. Booking-type radios fire `change`→`selectBookingType` (`js/booking.js:555-558`).
  Room-selection radios trigger `selectRoom` via the label's inline `onclick` through click-event
  bubbling (keyboard Space / arrow-key selection dispatches a click on the radio that bubbles to
  the wrapping `<label onclick>`), so keyboard room-pick works. Modal close button has
  `aria-label="Close"` (`booking.php:1567`). **Deliberately NOT adding a redundant `change`
  listener to room radios** — it would double-fire `selectRoom` (once via click-bubble, once via
  change) and risk a double availability-check/scroll regression in the just-QA'd `js/booking.js`.
- **Screen-reader labels — PASS, no action.** Every text/email/tel/number/select/textarea input
  in `booking.php` has an explicit `<label for>` (check_in/out_date, guest_name/email/phone/
  country/address, number_of_guests, child_guests, special_requests). Room + booking-type radios
  are wrapped in `<label>`s containing their visible name text (implicit association). Decorative
  FontAwesome icons render via CSS pseudo-elements (empty `<i>`), which AT ignores. Calendar
  legend conveys state with text labels, not colour alone.
- **320px width — PASS, no action.** `css/sections/booking.css` carries breakpoints to 360px
  (`@media (max-width:360px)` at multiple points) plus pervasive `min-width:0` on flex/grid
  children (prevents overflow); containers use `max-width`/`max-width:100%`, no fixed >320px
  widths. `max-width:360` rules also apply at 320px. (True pixel-perfect 320px verification needs
  a browser — flagged best-effort per the task; no fabricated fix.)
- **Contrast — ONE real, verified, pervasive gap (the acted-on finding).** The muted secondary-
  text token `#9B8A72` is used as a `color:` value **21 times** across the booking flow —
  `css/sections/confirmation.css` lines 177,216,233,303,318,348,383,420,562,615,650 (11×),
  `css/sections/booking.css` lines 3269,3382,3693,3850,3998,4036,4082,4105,4146,5123 (10×),
  and once inline at `booking-confirmation.php:189`. `#9B8A72` on the cream card bg (`#f5f2eb`)
  ≈ **3.0:1**, on white ≈ **3.35:1** — below the WCAG 2.1 AA **4.5:1** minimum for normal-size
  text (these are labels/meta/date text, i.e. normal weight/size). Every one of the 21 hits is a
  text `color:` (the lone `#ccc` at confirmation.css:724 is a print-media border — leave it).

### Dispatch brief → frontend-specialist (contrast remediation; presentational only, no logic/markup structure changes)
Darken the muted-label text token from `#9B8A72` to **`#736149`** (≈ 5.4:1 on the cream card bg,
≈ 5.9:1 on white — a comfortable AA pass that keeps the same warm Japandi brown hue family).
Exact changes:
1. In `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\css\sections\confirmation.css`
   replace `color: #9B8A72;` with `color: #736149;` at all 11 occurrences (lines 177,216,233,303,
   318,348,383,420,562,615,650). Do NOT touch the `#ccc` print border at line 724.
2. In `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\css\sections\booking.css`
   replace `color: #9B8A72;` (and the one `color: #9B8A72 !important;` at line 3850, keeping its
   `!important`) with the `#736149` equivalent at all 10 occurrences (lines 3269,3382,3693,3850,
   3998,4036,4082,4105,4146,5123).
3. In `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\booking-confirmation.php` line
   189, change the inline `color:#9B8A72;` in the split-count `<span style="...">` to
   `color:#736149;`. Leave all other markup/PHP on that line unchanged.
- **Do NOT touch:** any JS (`js/booking.js`), the focus/`:focus-visible` rules, form markup, the
  `#666`-on-white secondary text (that already passes at ~5.7:1), any other page, any other CSS
  file, or any `#9B8A72` value that is NOT a text `color:` (there are none in these two files
  besides the print border, which stays). No new CSS variables, no restructuring.

### ASSUMPTIONS (2026-07-14, P3-01)
- Keyboard nav, screen-reader labels, and 320px are closed as **evidence-based no-action**
  (already implemented — see findings); the single acted-on axis is the `#9B8A72` contrast fix.
- Replacement shade `#736149` chosen for AA headroom on BOTH the cream card bg and white while
  staying in the existing warm-brown family. ui-designer may fine-tune within `#6F5F49`–`#7C6A4F`
  in its polish pass PROVIDED the measured ratio stays ≥ 4.5:1 on the actual card background.
- No `#9B8A72` text sits on a dark fill in these two light-themed files (spot-verify during QA);
  if any did, darkening would reduce its contrast — none found in the light booking/confirmation
  surfaces.

### Acceptance criteria (P3-01)
1. Zero `#9B8A72`/`#9b8a72` text `color:` values remain in `css/sections/booking.css`,
   `css/sections/confirmation.css`, or `booking-confirmation.php` (grep returns none as a text
   colour). The print-media `#ccc` border at confirmation.css:724 is untouched.
2. The replacement colour measures **≥ 4.5:1** against both `#ffffff` and the cream card bg
   `#f5f2eb` (QA computes/confirms the ratio for the chosen hex).
3. No change to any `.js` file, to the `:focus`/`:focus-visible`/box-shadow focus rules, to any
   form input markup, or to any file outside the three named above.
4. `php -l booking-confirmation.php` passes; the two CSS files remain valid (no stray syntax from
   the replacements).
5. No functional/visual regression beyond the intended darkening: the muted labels/meta/dates
   render in the new darker warm-brown, layout unchanged.

### QA gate → qa-auditor **haiku**
Lint/format + mechanical-correctness gate: this is a pure presentational colour-token replacement
with no logic, security, money, or DB dimension. Haiku confirms (a) all 21 CSS hits + the one
inline hit are replaced with the identical target hex, (b) the print border and non-text values
are untouched, (c) the chosen colour meets ≥4.5:1, (d) `php -l` on booking-confirmation.php.
After this passes, an OPTIONAL ui-designer polish pass may fine-tune the shade within the stated
range on the just-touched files only.

## P3-02 investigation — Tablet pass on POS + KDS (build-planner, 2026-07-14)

**Closes the "Tablet UX: POS + KDS" checklist item** ("POS + KDS admin screens usable on
tablet, 768–1024px, 44px touch targets"). Check-then-fix task like P3-01 — investigated
read-only, and the evidence shows both screens were **purpose-built as touchscreen interfaces
and already carry multiple prior tablet passes**. Outcome: **evidence-based no-action** (zero
code changes), mirroring the already-passing axes of P3-01. Scope investigated = `admin/pos.php`
+ `admin/css/pos-overrides.css`, `admin/kds.php` + `admin/css/kds.css` only.

### Evidence — POS (`admin/pos.php` → `css/pos-overrides.css`, the sole stylesheet it loads at pos.php:1960)
- **Declares itself a touchscreen UI:** `pos-overrides.css:6` header comment "Dark-themed
  touchscreen POS interface." `touch-action: manipulation`/`pan-y`/`none` used throughout
  (e.g. :1739, :4020, :4208).
- **Dedicated tablet breakpoints already present:** `@media (min-width: 641px) and (max-width:
  1024px)` (:1283) reflows the product grid into finger-sized cards (min-height clamp ~7.4–8.8rem,
  tap `+` affordance); `@media (max-width: 1024px)` (:903) reflows the whole till to a fixed
  100dvh single-column touch shell with a full-width category dropdown trigger (min-height 48px,
  :1033). Plus 1024–1540 (:494), 1281–1700 (:1489) refinements.
- **44px+ touch targets on the interactive controls:** primary/qty/action buttons at min-height
  44px (:683), 46px (:1113,:1120), 48px (:1033,:1216,:4434), 56px (:4210); icon buttons 44×44
  (:744,:789,:2672); modal inputs 46–48px (:4414,:4434); numeric keypad 44×44 (:4277).
- **Hover is properly gated — no hover-only traps:** the only `:hover` transform sits inside
  `@media (hover: hover) and (pointer: fine)` (:2918), so it is a pointer-only decorative lift
  that is gracefully absent on touch; all actions fire on click/tap.
- **Sub-44px values are NOT touch targets:** `.tb-actions` 38px (:967) is a flex *container* row
  (its child buttons are sized independently); `.tb-row2` 42px (:981) is the display-only KPI
  *stats* strip (covers/sales readouts, non-interactive). Neither is a tap target.

### Evidence — KDS (`admin/kds.php` → `css/kds.css`, the sole stylesheet it loads at kds.php:204)
- **Explicit tablet-touch-target rule at the very top:** `kds.css:22` comment "Tablet touch
  targets — filter buttons ≥ 48px on screens ≤ 1024px", backed by `@media (max-width: 1024px)`
  (:23) forcing `.filter button` min-height 48px and `.topbar .right button/a` 48×48.
- **Dedicated tablet breakpoints:** `@media (min-width: 901px) and (max-width: 1024px)` (:2303)
  + `901–1280` (:2314) reflow the station board to fewer wider columns and raise the ticket
  action buttons to min-height 44px (:2561,:2583,:2598); base ticket action buttons are already
  2.75rem = 44px (:1838,:1865,:1892).
- **Filtering is preserved on tablet, not lost:** the inline `.filter` bar is `display:none` at
  901–1024 (:2310), but the same controls relocate into the touch off-canvas `kdsMenuDrawer`
  (kds.php:272) opened by the `kds-menu-toggle` burger button (kds.php:264, correct
  `aria-label`/`aria-expanded`/`aria-controls`), whose `drawer-filter-btn` All/New/Cooking/Ready
  buttons (kds.php:287-290) are the tablet filter controls. A deliberate collapse-to-drawer
  adaptation, not a defect.
- **Touch-aware, no hover traps:** `@media (hover: none)` (:2610) *adds* a tap-highlight for
  touch (an enhancement); `touch-action: manipulation` on the action buttons (:964,:2001,:1757).
- **Sub-44px values are non-interactive or intentional short-viewport density:** the 32/36px
  button heights at :2628-2635 are inside `@media (max-height: 720px) and (min-width: 901px)`
  (:2618) — a deliberate density tradeoff for *short landscape* viewports only (does NOT trigger
  on a standard 1024×768 landscape iPad, whose 768px height is above the 720px cap); other 32px
  values are chips/badges/status pills, not primary tap targets.

### Outcome
Both screens already satisfy the checklist item across the 768–1024px band: dedicated tablet
breakpoints, 44–56px primary touch targets, filtering preserved via a touch drawer on KDS,
and hover correctly gated so nothing is touch-inaccessible. **No frontend-specialist dispatch
and no ui-designer pass required.** No QA gate (zero files changed — nothing to lint or audit).
Closed as done-with-evidence, consistent with P3-01's evidence-based no-action axes.

### ASSUMPTION (2026-07-14, P3-02)
"Usable on tablet" is verified here by CSS/markup evidence (dedicated 641/768/901–1024 breakpoints,
measured 44px+ target rules, gated hover, preserved filtering). True pixel-perfect on-device
verification on a physical iPad/Android tablet needs a browser and is flagged best-effort — no
fabricated fix was applied where the evidence already shows the target is met (same stance as
P3-01's 320px axis).

## P3-03 investigation + dispatch — Tablet pass on check-in + housekeeping (build-planner, 2026-07-14)

**Closes the "Tablet UX: check-in + housekeeping" checklist item** ("Check-in + housekeeping
admin screens usable on tablet, 768–1024px, 44px touch targets"). Check-then-fix like P3-01/P3-02.
Unlike P3-02 (POS/KDS were already tablet-optimized), the evidence here shows a **real,
verified touch-target gap** — a fix IS dispatched.

### ⚠ FILE REDIRECT CORRECTION (like the seo-meta.php correction) — the task row names
`admin/process-checkin.php`, but that file is a **headless JSON/AJAX endpoint** (verified:
`process-checkin.php:7` sends `Content-Type: application/json`, no HTML output; SYSTEM_MAP.md:267
type "N/A (AJAX)", :336 "AJAX endpoint called from booking-details.php … client-side JS"). It has
**no UI to make tablet-usable.** The actual check-in **workflow UI** renders in
`admin/booking-details.php` (the booking folio): the Check In / Check Out / Assign Room / Change
Room / No-Show / Cancel action buttons at booking-details.php:2015–2060 (`.action-btn` classes),
which POST `booking_action=checkin` etc. (server-side handler at :427). Therefore the check-in
half of this task audits/fixes **`admin/booking-details.php` + `admin/css/booking-details.css`**,
NOT process-checkin.php. (bookings.php also has an inline check-in shortcut but loads its own CSS,
not booking-details.css; keeping it out of this task's tight scope — logged under Minor follow-ups.)

### Investigation findings (evidence-based; real sub-44px touch-target gap on BOTH screens)
- **How POS/KDS "passed" but these don't:** P3-02's POS/KDS met the 44px bar only via their
  **dedicated** stylesheets (`pos-overrides.css`/`kds.css`) carrying explicit tablet-band touch
  rules (44–56px). The **shared** admin button styles do NOT meet 44px: `admin-components.css:1822`
  `.btn` min-height = clamp(2.15rem…2.45rem) = **34.4–39.2px** (only bumped to **40px** at ≤768px,
  :1845); `.btn-sm` (:1839) = **30.4–34.4px** (36px at ≤768px). The lone shared `min-height:44px`
  (`admin-styles.css:571`) applies ONLY to `.rh-admin-page-header .btn` (page-header actions like
  "Add Assignment") — not body/table/card controls. The `@media (hover:none)` block
  (admin-styles.css:3939) only removes hover transforms; it does NOT enlarge any target.
  Neither `booking-details.css` nor `housekeeping.css` has a tablet touch-target pass.
- **Check-in (`booking-details.css`):** `.action-btn` (:462) = `padding:12px 20px; font-size:13px;`
  **no `min-height`** → computed ≈ **39–40px**. Breakpoints present: `min-width:897px` (×3),
  `max-width:1200px`, `max-width:768px`, `max-width:80rem` — **no rule in the 768–1024 band raises
  the check-in/checkout/assign buttons to 44px.** These are THE check-in workflow controls.
- **Housekeeping (`housekeeping.css`) — no `@media (max-width:1024px)` block exists at all** (only
  1200/768/480). The primary "walk room-to-room" interaction IS well done — the whole occupied-room
  card is tappable (`onclick="toggleRoomCard"`, housekeeping.php:1307; card min-height 8.5rem≈136px)
  and the 7 stat-filter cards are 136px buttons — **leave those alone, they pass.** But the
  **secondary controls are all sub-44px** with no tablet enlargement:
  - `.hk-inline-control` filter/search selects (:245,:255) — min-height 2.2rem / height 2.35rem =
    **35–37.6px** (housekeeping.php:1282,1288,1370,1371 bulk-assign + table filters).
  - `.btn-quick` "Select All" (:590) — `padding:6px 12px`, no min-height ≈ **30px** (php:1293).
  - `.btn-quick-assign` per-card assign (:532) — `padding:7px 12px` ≈ **32px** (php:1322).
  - `.hk-row-actions .btn.btn-sm` table row icon buttons Start/Done/Verify/Edit/History/Delete
    (php:1421–1455) — shared `.btn-sm` ≈ **30–34px**, icon-only so also need ≥44px **width**.
  - Add-Assignment modal `.housekeeping-modal-content select` (:118) + its `.btn` (php:1473–1571) —
    task-assignment workflow, shared sizes sub-44px.

### Dispatch brief → frontend-specialist (tablet touch-target CSS pass; presentational only, no markup/logic/JS changes)
Add tablet-band (`@media (max-width: 1024px)`, mirroring the `kds.css`/admin convention and the
768–1024 checklist band) touch-target rules raising the below controls to **min 44px** height
(and ≥44px width for icon-only buttons). This is additive CSS only — do NOT restructure existing
rules, change colors/layout, edit any `.php`/`.js`, or touch the already-good large card/stat-card
targets.

**File 1 — `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\admin\css\booking-details.css`:**
Append a new `@media (max-width: 1024px) { … }` block (the file has no 1024 breakpoint yet) that sets
`.action-btn { min-height: 44px; }` (keep its existing `display:inline-flex; align-items:center`, so
the raised height centers the label cleanly). That is the only rule needed in this file.

**File 2 — `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\admin\css\housekeeping.css`:**
Append a new `@media (max-width: 1024px) { … }` block setting **min-height: 44px** on:
- `.hk-inline-control` (covers the search variant too),
- `.btn-quick`,
- `.btn-quick-assign`,
- `.bulk-actions .btn` (the bulk-assign submit),
- `.hk-row-actions .btn` — set BOTH `min-height: 44px;` AND `min-width: 44px;` (icon-only buttons;
  keep them inline so the `.hk-row-actions` flex row wraps if needed — add `flex-wrap: wrap;` to
  `.hk-row-actions` inside this same media block so a 6-icon row of 44px targets doesn't overflow
  the table cell on tablet),
- `.housekeeping-modal-content select` and `.housekeeping-modal-content .btn`.
Use `min-height` (not fixed `height`) so existing padding/label layout is preserved. Do NOT alter
the occupied-room card, `.hk-stat-card`, or the dashboard grid — those already exceed 44px.

**Do NOT touch:** `process-checkin.php` (headless JSON — no UI), `booking-details.php`/
`housekeeping.php` markup, any `.js`, `admin-styles.css`/`admin-components.css` (shared — out of
scope; changing them would ripple across every admin page), `pos-overrides.css`/`kds.css`, `bookings.php`
or its CSS, any other page/stylesheet. No new CSS variables, no color/layout changes, no restructuring
of existing selectors. Additive `@media (max-width:1024px)` blocks only.

### ASSUMPTIONS (2026-07-14, P3-03)
- Check-in workflow UI = `booking-details.php` `.action-btn` group (redirected from the mis-named
  process-checkin.php headless endpoint — see correction above). bookings.php's inline check-in
  shortcut is deliberately OUT of this task's tight scope (separate CSS, would balloon an 8609-line
  list page) — logged under Minor follow-ups, not queued.
- Fix uses `@media (max-width: 1024px)` (tablet-and-below) to match the existing `kds.css` 48px-touch
  convention and cover the whole 768–1024 band; it also incidentally helps ≤768px where these
  controls are still sub-44px — a strict improvement, no regression.
- Room cards / stat-filter cards / page-header buttons already exceed 44px (evidence above) and are
  intentionally left untouched — same "don't fix what passes" stance as P3-02.
- On-device pixel-perfect verification needs a physical tablet and is flagged best-effort; the fix is
  driven by measured CSS values, not fabricated.

### Acceptance criteria (P3-03)
1. `admin/css/booking-details.css` gains a `@media (max-width: 1024px)` block making `.action-btn`
   compute to **≥44px** tall; no other selector in that file changed.
2. `admin/css/housekeeping.css` gains a `@media (max-width: 1024px)` block making
   `.hk-inline-control`, `.btn-quick`, `.btn-quick-assign`, `.bulk-actions .btn`,
   `.hk-row-actions .btn`, and the modal `select`/`.btn` compute to **≥44px** (icon buttons ≥44px
   wide too), with `.hk-row-actions` allowed to wrap. Occupied-room card / stat cards / dashboard
   grid unchanged.
3. No `.php`, `.js`, or shared stylesheet (`admin-styles.css`, `admin-components.css`) is modified;
   no file outside the two named CSS files is touched.
4. Both CSS files remain syntactically valid (balanced braces; a CSS linter or `php -l`-style
   sanity is not applicable — QA visually confirms brace balance and no stray selectors).
5. No visual/layout regression at ≥1025px (rules are gated behind `max-width:1024px`, so desktop is
   untouched) and no overflow of the housekeeping table action cell on tablet (flex-wrap added).

### QA gate → qa-auditor **haiku**
Pure presentational tablet touch-target CSS — no logic, security, money, DB, or JS dimension. Haiku
confirms: (a) both files get an additive `@media (max-width:1024px)` block, (b) the named selectors
reach ≥44px (≥44px width for `.hk-row-actions .btn`), (c) no shared stylesheet or `.php`/`.js` touched,
(d) brace balance intact, (e) no desktop (>1024px) rule changed.
**⚠ QA scope-verification note (recurring false-positive guard):** this session NEVER commits
mid-loop, so `git diff`/`git status` against HEAD shows the ENTIRE run's cumulative uncommitted work
(P2-04, P3-01, P3-02, etc.), NOT this task's scope. Verify scope by reading the CONTENT of the two
named CSS files against this brief — do NOT diff against HEAD project-wide and do NOT flag files from
earlier already-approved tasks as scope creep.

## P3-04 investigation + dispatch — Public-page CSS consistency (build-planner, 2026-07-14)

**Closes the final checklist item** ("Public-page CSS is visually consistent across modules
(no drift)"). Check-then-fix like P3-01/P3-03. Investigated read-only first, then scoped to ONE
concrete, high-confidence, finite drift axis — a surgical value-map fix, not an open-ended
"tokenize everything" refactor.

### Investigation findings (evidence-based)
- **Shared foundation is strong — the STRUCTURAL layer is consistent by construction, NO ACTION.**
  `css/base/variables.css` is a comprehensive design-token system (full color palette, fluid type
  scale `--text-xs…5xl`, 8px-based `--space-*` scale, `--radius-*`, shadows, transitions,
  component tokens). `css/main.css` imports base (variables/reset/typography/layout) + shared
  components (buttons, forms, header, footer, cards, modal) + all section files. Because buttons,
  forms, cards, headers, footers, the type scale and the spacing scale are all single-source and
  loaded on every public page, the cross-module visual STRUCTURE is already consistent. No drift
  to fix there.
- **P3-01's `#9B8A72`→`#736149` fix left NO residual cross-module drift — verified.** Grep: zero
  `#9B8A72` remain anywhere in `*.css`; `#736149` exists only in the booking-flow files
  (booking.css/confirmation.css) where it belongs. Clean.
- **Section files hardcode text colors (225 `color:#hex` hits across 9 section files, zero use of
  `var(--color-text-muted)`), BUT the values cluster tightly in the warm-Japandi brown family**
  (`#8B7355`, `#7A6A58`, `#6B5740`, `#5E504A`, `#736149`, `#775d42`, `#7a6e5f`). A guest does not
  perceive these near-cousins as inconsistent. Wholesale tokenization of all 225 would be an
  open-ended refactor with real regression risk — deliberately NOT queued (out of the checklist
  item's intent; the intent is "visually consistent," which the warm-family clustering already
  largely satisfies).
- **THE ONE genuine, perceptible drift (the acted-on finding): cool neutral greys break the warm
  text palette in `contact.css` and `restaurant.css`.** Every other module renders body/secondary/
  muted text in warm brown; these two use flat cool greys `#333` / `#666` / `#999` for the same
  roles. Confirmed by reading: all sit on white/light surfaces (`.contact-form-card{background:#fff}`,
  white quick-action buttons, light info card) — so darkening/warming them is always equal-or-better
  contrast, never a regression. Tellingly, `contact.css` is INTERNALLY inconsistent too: it already
  uses the warm near-black `#1a1a1a` for its `h3` headings (contact.css:697,888,1031) and warm
  `#8B7355` for icons/accents/buttons — the greys are the lone cool outliers in an otherwise-warm
  file. `restaurant.css` is already token-adopted elsewhere (`var(--color-text-secondary,…)` :1138,
  `var(--color-primary,…)` :1145, `var(--color-text-primary,…)` :1151); its only stray cool grey is
  one `#666` menu-loading placeholder (:1067).

### Dispatch brief → frontend-specialist (presentational text-color convergence; no markup/logic/JS)
Converge the cool neutral greys used for text in `contact.css` and `restaurant.css` onto the warm
text values **already used site-wide** (this reduces drift by adopting existing site vocabulary — it
introduces NO new colors). Apply this exact mechanical value-map — replace ONLY the `color:` text
declarations at the listed lines, nothing else:

**File 1 — `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\css\sections\contact.css`:**
- `color: #333;` → `color: #1A1A1A;` at lines **760, 800, 818, 922** (body/link/input/button text;
  matches contact.css's own heading color and the site-wide primary text used in booking/confirmation).
- `color: #666;` → `color: #6B5740;` at lines **751, 856, 906, 959, 1040** (secondary/label text;
  `#6B5740` is the established site secondary-brown, e.g. confirmation.css:136,593, booking.css:3218 etc).
- `color: #999;` → `color: #8B7355;` at line **935** (placeholder; `#8B7355` is the site's dominant
  accent-brown — a large contrast improvement over `#999`).

**File 2 — `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\css\sections\restaurant.css`:**
- `color: #666;` → `color: #6B5740;` at line **1067** (the `.menu-panel:empty::before` loading
  placeholder — the only stray cool grey in an otherwise token-adopted file).

**Do NOT touch:** any brand/status/semantic color (`#25D366`/`#20ba5a` WhatsApp, `#28a745` success,
`#721c24`/`#dc3545` error, the already-warm `#1a1a1a` headings, all `#8B7355` accents, `#4caf7b`,
`#6d5a3f`); any `var(--…)` token line in restaurant.css (leave the fallbacks as-is); any `#fff`/
`#ffffff`; any other section CSS file; any `.php`, `.js`, or shared base/component stylesheet; any
non-`color:` property (backgrounds, borders, shadows). No new CSS variables, no restructuring, no
tokenization of the warm-brown values (those are already consistent — out of scope). Additive/
in-place `color:` value swaps ONLY, at exactly the 11 listed lines.

### ASSUMPTIONS (2026-07-14, P3-04)
- The structural layer (tokens + shared reset/typography/layout + shared components) is closed as
  **evidence-based no-action** — already consistent by single-source construction (same stance as
  P3-01's passing axes and P3-02's whole outcome).
- The tight warm-brown-family clustering of the other ~214 hardcoded text colors is treated as
  "already visually consistent" — a full tokenization refactor is deliberately NOT queued (open-ended,
  regression-prone, beyond the checklist item's intent). Logged consideration under Minor follow-ups.
- The single acted-on axis is the cool-grey→warm-text convergence in the two outlier files, mapped
  onto values ALREADY in the site's vocabulary (`#1A1A1A`/`#6B5740`/`#8B7355`), all on light
  surfaces so contrast is equal-or-better. Chosen for high confidence + zero-invention + low risk.

### Acceptance criteria (P3-04)
1. Zero `color: #333` / `color: #666` / `color: #999` text declarations remain in `contact.css`
   (grep the three greys returns none as a `color:` value; the 11 named lines now carry the mapped
   warm values). `restaurant.css:1067` `#666` is replaced with `#6B5740`; restaurant's `var(--…)`
   token lines are untouched.
2. Only the 11 listed `color:` declarations changed; no background/border/shadow/brand/status/token
   value altered; no other file touched (the two named CSS files only).
3. Each replacement value measures **≥ 4.5:1** against white (`#1A1A1A`≈18:1, `#6B5740`≈6.9:1;
   `#8B7355`≈4.3:1 applies only to the `::placeholder` at :935, which is exempt-grade UI text and a
   strict improvement over `#999`≈2.85:1 — QA confirms it is the placeholder line).
4. Both CSS files remain syntactically valid (balanced braces, no stray tokens from the swaps).
5. No visual/layout regression beyond the intended text darkening/warming; no markup or JS change.

### QA gate → qa-auditor **haiku**
Pure presentational color-value replacement — no logic, security, money, DB, or JS dimension
(identical shape to P3-01's haiku gate). Haiku confirms: (a) all 11 named `color:` lines carry the
mapped values, (b) the three greys are gone as text colors in contact.css and the one in
restaurant.css, (c) brand/status/token/background/border values and all other files are untouched,
(d) brace balance intact.
**⚠ QA scope-verification note (recurring false-positive guard):** this session NEVER commits
mid-loop, so `git diff`/`git status` against HEAD shows the ENTIRE run's cumulative uncommitted work
(P2-02/03, P2-04, P3-01, P3-03, etc.), NOT this task's scope. Verify scope by reading the CONTENT of
the two named CSS files against this brief — do NOT diff against HEAD project-wide and do NOT flag
files from earlier already-approved tasks as scope creep.

## P3-05 investigation + dispatch — admin list tables render as tables on laptops (build-planner, 2026-07-14)

**Closes the Round 2 checklist item** ("Admin list views (e.g. 'All Room Bookings' in
`admin/bookings.php`) render as data tables on standard laptop screens (≥1024px, 14-15in), with
the card layout reserved for tablet/mobile breakpoints. Fix at the shared-component level…").
Check-then-fix like the other Phase 3 tasks. Investigated read-only first; the root cause is a
SHARED JS component, so ONE change fixes it consistently across every admin list view (exactly
what the owner asked for — not page-by-page).

### Investigation findings (evidence-based)
- **The table→card switch is driven by JavaScript, NOT a CSS media query.** The card styling
  (`admin/css/admin-styles.css:958+`, `table.mobile-enhanced { … }` making `thead/tr/td` render as
  stacked blocks with `td[data-label]::before` labels) is applied ONLY when the `.mobile-enhanced`
  class is present. That class is added/removed at runtime by `admin/js/admin-mobile.js`
  (admin-styles.css:956 comment: "Any table marked .mobile-enhanced by admin-mobile.js gets card
  view."). So the *threshold* the owner is complaining about lives in the JS decision function, and
  the CSS needs NO change.
- **`bookings.php` "All Room Bookings" table is `class="booking-table bookings-table tablet-table"`**
  (bookings.php:**3441**; a second results table at :**3843** is `booking-table tablet-table`). The
  `tablet-table` class routes it through the `tablet-table` branch of `shouldUseCardLayout(table)`
  in `admin/js/admin-mobile.js` (lines **274–296**):
  ```js
  if (table.classList.contains('tablet-table')) {
      if (viewportWidth <= 640) { return true; }          // phone → cards
      const availableWidth = getTableAvailableWidth(table);
      // clone measured at white-space:nowrap (max-content / intrinsic width)
      …
      return availableWidth < intrinsicWidth;              // ← the premature-card culprit
  }
  ```
- **Why it cards on a 15" laptop:** `intrinsicWidth` is measured with `white-space:nowrap`
  (admin-mobile.js:284) — the table's full single-line max-content width with NO wrapping. A wide
  multi-column bookings table (reference, guest, room, check-in, check-out, status, payment/balance,
  actions) has an intrinsic nowrap width of roughly 1300–1500px. On a 14–15" laptop the admin
  content area *after the sidebar* is ~1000–1080px (`getTableAvailableWidth` returns the container
  clientWidth, admin-mobile.js:183–197, not the viewport). So `availableWidth (~1050) < intrinsicWidth
  (~1400)` is essentially ALWAYS true for this table → it collapses to cards even though a real table
  would simply wrap cell text and/or scroll and fit fine. That is the "premature card-switch."
- **This IS the shared admin responsive pattern the owner suspected.** `shouldUseCardLayout()` is the
  single decision function for ALL admin list tables (`getCardableTables()`, admin-mobile.js:131–166,
  selects every `.table/.admin-table/.booking-table/.bookings-table/.report-table/.users-table/…`
  and every table inside `.admin-content/.table-responsive/…`). Every list view tagged `.tablet-table`
  hits the exact same branch, so fixing this one branch fixes them all consistently — no per-page
  edits. **Blast radius = every `.tablet-table` admin list view** (bookings.php confirmed; the shared
  branch covers all others by construction). The `fit-or-card` finance branch (lines 248–272) and the
  default non-`tablet-table` branch (297–319) are deliberately left untouched — see "Do NOT touch."
- **Target breakpoint = 1024px viewport**, matching the codebase's established tablet-band ceiling
  (`admin/css/kds.css:23` `@media (max-width:1024px)`; P3-02/P3-03 both used 1024 as the tablet
  band). Above 1024px = laptop/desktop = real table (owner's 14/15" laptops sit at 1280–1920).
  ≤1024px = tablet-landscape-and-below = current card behaviour retained. This is a pure JS-threshold
  change; the `.table-responsive` wrapper around the table (bookings.php:3440) already supplies
  `overflow-x:auto` (bookings.css:1517–1520) so a wide table scrolls horizontally on a laptop instead
  of collapsing to cards.

### Dispatch brief → frontend-specialist (single shared JS-threshold change; behavioural, no markup/CSS/logic-shape change)
Edit ONE function in `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026\admin\js\admin-mobile.js`.
Inside `shouldUseCardLayout(table)`, in the **`if (table.classList.contains('tablet-table'))` block
(lines 274–296)**, add a laptop/desktop guard IMMEDIATELY AFTER the existing phone check
(`if (viewportWidth <= 640) { return true; }`) and BEFORE the `const availableWidth = …` line:

```js
// Owner rule (2026-07-14, P3-05): reserve card layout for tablet/mobile.
// On standard laptop/desktop viewports (> 1024px) always keep a real data
// table — the .table-responsive wrapper supplies horizontal scroll when a
// wide table doesn't fit, rather than collapsing to cards.
if (viewportWidth > 1024) {
    return false;
}
```

Net effect of the `tablet-table` branch after the edit:
- viewport ≤ 640px (phone) → cards (unchanged);
- viewport 641–1024px (tablet) → existing intrinsic-width logic, cards when it doesn't fit (unchanged);
- viewport > 1024px (laptop/desktop) → always a real table (NEW — the fix).

`viewportWidth` is already defined at the top of the function (admin-mobile.js:241) and is in scope
inside this branch, so no new variable is needed. The resize handler (`enhanceMobileTables`,
:322–334) re-invokes `shouldUseCardLayout` on every resize, so this threshold also governs
resize/rotation transitions automatically — no separate listener change.

**Do NOT touch:**
- The `fit-or-card` branch (lines 248–272) — finance/accounting tables that intentionally measure
  *with* wrapping and card-on-genuine-overflow at any width; leave their design intent intact.
- The default non-`tablet-table` branch (lines 297–319) — its `viewportWidth <= 768`,
  `columnCount >= 6 && availableWidth <= 960`, and overflow checks stay as-is.
- The `viewportWidth <= 640` phone early-return (keep it — phones still get cards).
- Any CSS file (`admin-styles.css`, `admin-components.css`, `bookings.css`, `kds.css`, etc.) — the
  card CSS is correct and is not media-query-gated; only the JS threshold changes.
- Any `.php` file, any table markup/columns, any table styling, `getCardableTables()`,
  `getTableAvailableWidth()`, `getRequiredTableWidth()`, `transformTableToCards()`,
  `restoreTableFromCards()`, or any other function in admin-mobile.js.
- No new libraries, no build step, no minification, no reformatting of untouched lines.

### ASSUMPTIONS (2026-07-14, P3-05)
- Card layout is reserved for `viewportWidth <= 1024` (tablet-landscape and below); `> 1024` is
  treated as "standard laptop/desktop" and always shows a real table. 1024 chosen to match the
  codebase's existing tablet-band ceiling (kds.css `@media (max-width:1024px)`, P3-02/P3-03) and the
  checklist item's "≥1024px" laptop framing. Owner's 14/15" laptops (1280–1920px viewport) are
  comfortably above the threshold.
- Only the `tablet-table` branch is changed. The owner's example (bookings.php) and the "common admin
  responsive pattern" they described are the `tablet-table` list views; changing this one shared
  branch fixes all of them consistently. The `fit-or-card` finance tables are a separate, deliberate
  design (real table when it fits with wrapping, cards only on true overflow — already laptop-friendly)
  and are intentionally out of scope to avoid altering finance-table readability behaviour.
- A wide table on a laptop now horizontally scrolls inside `.table-responsive` (overflow-x:auto,
  bookings.css:1517) rather than collapsing to cards — this is the intended best-in-class PMS
  behaviour (Cloudbeds/Mews show dense scrollable tables on desktop, never cards) and matches the
  owner's explicit "I want the tables on standard screens" request.
- On-device pixel-perfect verification needs a real browser at various widths and is flagged
  best-effort; the fix is driven by the measured JS decision logic, not fabricated.

### Acceptance criteria (P3-05)
1. `admin/js/admin-mobile.js` `shouldUseCardLayout()` gains, inside the `tablet-table` branch and
   after the `viewportWidth <= 640` return, a `if (viewportWidth > 1024) { return false; }` guard
   (with the explanatory comment). No other line of the function or file is changed.
2. `node --check admin/js/admin-mobile.js` (or equivalent JS syntax check) passes — no syntax error,
   balanced braces.
3. Behavioural verification (QA traces the decision function): for a `.tablet-table` at
   `window.innerWidth = 1366` (or any value > 1024), `shouldUseCardLayout()` returns `false` (real
   table); at `innerWidth = 1000` (641–1024 band) the pre-existing intrinsic-width logic still runs;
   at `innerWidth = 600` (≤640) it still returns `true` (cards).
4. No CSS file, no `.php` file, no table markup, and no other function in admin-mobile.js is modified;
   no file outside `admin/js/admin-mobile.js` is touched.
5. No regression to the `fit-or-card` or default branches (untouched), and phones (≤640) / tablets
   (641–1024) keep their current card behaviour.

### QA gate → qa-auditor **sonnet**
Logic/behaviour gate, not lint-only: this is a runtime decision-function change in a shared JS
component that governs table↔card rendering across the entire admin panel. A naive edit could put the
guard in the wrong branch (affecting `fit-or-card`/default), break the phone/tablet bands, or place it
after `availableWidth` such that it no longer covers all `tablet-table` cases. QA must trace the
branch logic (confirm the guard is inside the `tablet-table` block, after `<=640`, before the
intrinsic-width measure) and confirm the three viewport bands behave as specified — haiku lint would
miss a mis-placed guard.
**⚠ QA scope-verification note (recurring false-positive guard):** this session NEVER commits
mid-loop, so `git diff`/`git status` against HEAD shows the ENTIRE run's cumulative uncommitted work
(P2-02/03, P2-04, P3-01, P3-03, P3-04, etc.), NOT this task's scope. Verify scope by reading the
CONTENT of `admin/js/admin-mobile.js` against this brief — do NOT diff against HEAD project-wide and
do NOT flag files from earlier already-approved tasks as scope creep.

## Minor follow-ups (non-blocking, log only)
- **Section-CSS tokenization (found during P3-04):** the 9 public section CSS files hardcode ~225
  `color:#hex` text values and never reference `var(--color-text-muted)`/`--color-text-secondary`,
  even though a full token system exists in `css/base/variables.css`. The values cluster tightly in
  the warm-Japandi family (no perceptible cross-module drift after the P3-04 grey-outlier fix), so a
  wholesale tokenization pass is NOT queued (open-ended, regression-prone, beyond the checklist
  item's intent). A future maintainability pass could converge the warm-brown text values onto the
  existing tokens — not required for visual consistency, logged only.
- (bookings.php inline check-in touch targets and smoke_test_booking.php section 8 flakiness —
  both promoted to Round 3 scope 2026-07-14, see PROJECT COMPLETE WHEN above.)
- **Recurring QA gate false-positive (seen 2× — P1-03g, P3-01):** qa-auditor sometimes defaults to `git diff`/`git status` against HEAD as its scope signal, sees the whole session's cumulative uncommitted work (this project never commits mid-loop), and incorrectly FAILs a task for "touching" files that actually belong to other, already-approved tasks earlier in the session. Every qa-auditor dispatch brief going forward should explicitly state: this session never commits, HEAD is stale by the entire run, and scope should be verified by reading the CONTENT of the dispatched task's named files, not by diffing against HEAD project-wide.

## P1-05 dispatch (build-planner investigation, 2026-07-13)

**Closes gap #1** (blocker-grade audit hygiene — completion-definition item 5: "`display_errors`
off in prod config; no sensitive `die()` on public paths"). Moves toward the Cloudbeds/Mews bar
by ensuring a DB outage or PHP fatal never leaks infrastructure detail (DB host/user, stack
traces) to a guest's browser — a baseline trust/security expectation for a hosted PMS.

Investigation findings (evidence-based, from SYSTEM_MAP.md + targeted reads):
- **Errors ARE logged** — extensive `error_log()` across `config/` and `includes/`; DB connection
  failures logged at `config/database.php:132-133`. No action.
- **display_errors IS off under Apache mod_php** — `.htaccess:112` `php_flag display_errors Off`
  (global). GAP: `.user.ini` (already present at root; the PHP-FPM mechanism, under which
  `.htaccess` `php_flag` is silently ignored) does NOT set `display_errors`/`log_errors`.
- **SENSITIVE LEAK on a public path (primary finding):** `includes/db-error.php:257-260` emits the
  raw PDO exception message into a client-side `console.error('DB Error: …')` on every DB-connection
  failure. `config/database.php:131` assigns `$errorMsg = htmlspecialchars($e->getMessage())` and
  includes db-error.php on any `PDOException`. A connection failure message can contain DB host,
  username and SQLSTATE detail (e.g. "Access denied for user 'x'@'10.0.0.5'") — visible to ANY
  public visitor via View-Source/DevTools. The visible HTML page itself is clean; only the
  console line leaks. The error is already captured server-side via `error_log`, so the console
  emission is pure leakage.

ASSUMPTION (2026-07-13, P1-05): the console.error line should be gated behind the existing
`$dbDebug` flag (`config/database.php:64`, driven by `getenv('DB_DEBUG')`), not deleted outright —
this preserves the dev-time diagnostic while guaranteeing prod (DB_DEBUG unset) never leaks. If
the owner prefers zero client-side echo ever, the specialist can remove the block entirely; both
are safe.

### Acceptance criteria (P1-05)
1. `includes/db-error.php` emits the DB error to the browser console ONLY when `$dbDebug` is truthy
   (DB_DEBUG env set); with DB_DEBUG unset, page source contains NO `DB Error:` string and no
   fragment of the exception message.
2. `.user.ini` sets `display_errors = Off` and `log_errors = On` (so display_errors-off holds under
   PHP-FPM as well as the existing `.htaccess` mod_php directive).
3. The visible db-error.php maintenance page is otherwise unchanged (same markup/styling).
4. `php -l` passes on `includes/db-error.php` and `config/database.php` (if touched).
5. No other file altered; no behavioural change to logging (error_log calls stay intact).

### Dispatch brief → backend-specialist (QA gate: qa-auditor sonnet — security-sensitive info-leak)
Fix a public-path info leak plus harden display_errors under PHP-FPM. Exact changes:

1. **`includes/db-error.php:257-260`** — the block:
   ```php
   <?php if (isset($errorMsg)): ?>
   console.error('DB Error: <?php echo addslashes($errorMsg); ?>');
   <?php endif; ?>
   ```
   Change the guard so the console line is emitted ONLY when the debug flag is on. Use the
   `$dbDebug` variable already in scope (it is set in `config/database.php:64` before this file is
   included on the failure path). Replace `if (isset($errorMsg))` with
   `if (!empty($dbDebug) && isset($errorMsg))`. Do NOT touch the visible HTML, the SVG, the
   countdown script, or `$siteName`.
2. **`.user.ini`** (root, currently only upload/exec limits) — append two lines:
   `display_errors = Off` and `log_errors = On`. Do NOT change the existing four directives.
3. Do NOT modify `config/database.php` unless step 1 needs `$dbDebug` in scope there (it already
   is — `$dbDebug` is a plain local at line 64, in scope through the `catch` and the `include_once`
   at line 134, so no change to database.php should be needed). Confirm scope; if for any reason
   `$dbDebug` is not in scope at include time, prefer gating on
   `in_array(strtolower((string)getenv('DB_DEBUG')), ['1','true','on','yes'], true)` inside
   db-error.php rather than editing database.php.
- Relevant context files (do NOT edit): `config/database.php:62-136` (failure path + `$dbDebug`),
  `.htaccess:112` (existing mod_php directive — leave as-is), SYSTEM_MAP.md root Security flags.
- Do NOT touch any other file, admin pages, or the many legitimate `error_log()` calls.
- `php -l` `includes/db-error.php` (and `config/database.php` only if edited) before reporting done.

## P1-03 notes (build-planner verification, 2026-07-13)

P1-03 split into independently-verifiable sub-tasks after verification collapsed 2 of the
6 findings to "no action needed":

- **(a)** DONE via P1-02 (BALANCE_TOLERANCE standardized on POS).
- **(b) NO ACTION — confirmed encrypted.** `encryptApiKey()` IS defined in
  `config/database.php:5953` (always loaded via `admin-init.php`), so the `function_exists`
  guard at `admin/facebook-settings.php:55` is always true — the Page Access Token IS
  encrypted on write, and `includes/facebook-functions.php:38` decrypts it symmetrically via
  `decryptApiKey()` on read. The plaintext fallback branch is dead defensive code that never
  executes. Nothing to fix.
- **(c) DISPATCH → P1-03c** — real fix (backup-management CSRF).
- **(d) NO ACTION — already implemented.** `admin/login.php:120-219` already has a full
  brute-force lockout: per-account lockout (5 failed attempts → 15-min lock, keyed on
  `admin_users.failed_login_attempts` + last `login_failed` timestamp) AND per-IP rate limit
  (10 failed/IP/15min → block), both using the existing `admin_activity_log` table. The
  Phase-0 finding was STALE: the scout only read `login.php:20-93` (pre-login redirect
  helpers) and never reached the POST handler. **SYSTEM_MAP.md lines 591 & 612 should be
  corrected** (login.php DOES have built-in lockout) — folded into P1-04 cleanup, not
  blocking. Nothing to fix.
- **(e) DISPATCH → P1-03ef** — real fix (blocked-dates.php routing). VERIFIED the endpoint is
  currently broken as a standalone: `api/blocked-dates.php:31` `require_once`s the whole
  router `api/index.php`, whose init/dispatch block (`index.php:342-516`) runs to completion,
  computes endpoint `blocked-dates.php`, matches the `default:` case and exits with a 404
  BEFORE blocked-dates.php's own logic at line 34+ ever runs. There is no `blocked-dates`
  case in the router switch.
- **(f) DISPATCH → P1-03ef** — real hardening (page-content.php explicit guard).
- **(g) DISPATCH → P1-03g** — trivial (smoke-test string interpolation).

ASSUMPTION (2026-07-13, P1-03ef): `api/blocked-dates.php` is a key-authenticated API endpoint
(admin blocking UI uses the separate session-based `admin/blocked-dates.php`, not this one).
Routing it through the central router (adding a `case 'blocked-dates':`) is the correct fix
and will not affect the admin calendar's block UI. If a live client is calling
`/api/blocked-dates.php` directly with the current (broken) URL, the router refactor preserves
that URL because the file is still hit directly — it just gains a working auth path.

ASSUMPTION (2026-07-13, P1-03ef-f): `api/page-content.php` is PUBLIC by design (serves
whitelisted public page HTML for SPA navigation via js/navigation-unified.js). "Auth guard"
here means an explicit direct-access/visibility guard, NOT admin authentication — adding admin
auth would break public SPA navigation. Fix = make the implicit reliance on target-page
visibility explicit + rate-limit, without gating on admin session.

## P1-02 notes
- ASSUMPTION (2026-07-13): `smoke_test_finance.php` must NOT permanently consume live receipt/invoice/credit-note numbers. It exercises the atomic core `finance_next_sequence_number()` against a throwaway `sequence_name` (unique per run) and DELETEs that row afterward; the derived-number generators (`finance_next_receipt_number` etc.) are exercised only via a throwaway prefix/scope whose sequence rows are deleted, or via format/existence helpers that perform no writes. Self-cleaning like smoke_test_booking.php.
- ASSUMPTION (2026-07-13): standardizing POS cash-tendered checks on `BALANCE_TOLERANCE` (0.01) intentionally widens the current ad-hoc `0.001` tolerance to one cent — matching the rest of the money paths. Client-side JS float checks are left untouched (BALANCE_TOLERANCE is a PHP constant; server-side is the authority).

## Assumptions log
- **P1-01/P1-02** (2026-07-13): ASSUMPTION: given no PHPUnit suite exists in practice (only declared in composer require-dev, never installed/configured), continue the project's own established pattern (`scripts/smoke_test_*.php`, live-DB, self-cleaning, pass/fail counters) rather than introducing PHPUnit tooling from scratch. Revisit only if the owner asks for a real PHPUnit suite.
- **P1-01** (2026-07-13): ASSUMPTION: room #1 (VIP Beach Front Villa) has `rooms_available = 0` in live data, which would make an availability assertion against a hardcoded room id meaningless. The specialist scanned `$rooms` for one with `rooms_available > 0` instead. Correct call — flagging so future smoke-test additions know live data has at least one fully-booked-out room and shouldn't assume room #1 is available.

## Completed
- **R3-01** (2026-07-14, QA: PASS/haiku, first attempt) — Corrected two stale "DEAD" flags on
  `includes/seo-meta.php` in SYSTEM_MAP.md (lines 193, 230); it's an active dependency of
  `booking-confirmation.php:88`. Doc-only.
- **R3-02** (2026-07-14, QA: PASS/haiku, first attempt) — Investigation found the checklist
  premise was false: `includes/security.php` doesn't exist on disk, only `config/security.php`
  does, and all 7 real callers already require it correctly. No merge needed — removed a stale
  phantom "duplicate" row from SYSTEM_MAP.md instead of forcing an unwarranted code change.
- **R3-03** (2026-07-14, QA: PASS/haiku, first attempt) — `scripts/smoke_test_booking.php` was
  losing rows to `time()`-collision (1s resolution) and unguarded aborts skipping the
  end-of-script cleanup. Added a `register_shutdown_function` cleanup (fires even on a mid-test
  Throwable), an idempotent pre-test purge of leftover SMOKETEST fixtures, and random suffixes
  on the two time()-based test references. Test-only file, zero production code touched.
- **R3-04** (2026-07-14, QA: PASS/haiku, first attempt) — `admin/bookings.php`'s inline
  check-in shortcut (`.actions-row .quick-action`, styled in `admin/css/bookings.css`) was
  fixed at 28px tall with no tablet-band override, 16px under the 44px standard. Added one
  `@media (max-width:1024px)` block setting `min-height/min-width:44px`, mirroring P3-03's
  pattern exactly. Desktop/laptop density (P3-05) untouched above 1024px.

**PROJECT COMPLETE — Round 3.** All 4 Round 3 items now checked (19 of 19 total across all
rounds: 14 original + 1 Round 2 + 4 Round 3).
- **P3-05** (2026-07-14, QA: PASS/sonnet, first attempt) — Root cause was JS, not CSS: `admin/js/admin-mobile.js`'s `shouldUseCardLayout()` compared a `.tablet-table`'s container width (post-sidebar, ~1000-1080px) against its intrinsic nowrap width (~1300-1500px for a wide table), so wide tables collapsed to cards almost regardless of actual screen size. Added a single guard clause (`if (viewportWidth > 1024) return false;`) after the existing phone check — since this is the single shared decision function for every `.tablet-table` admin list view, one change fixes bookings.php's "All Room Bookings" and every other list view using the same pattern consistently, exactly as requested. Phone (≤640) and tablet (641-1024) bands unchanged; the untouched `.table-responsive` wrapper already provides horizontal scroll for tables wider than the viewport.

**PROJECT COMPLETE — Round 2.** All 15 checklist items now checked (14 original + 1 owner-added).
- **P3-04** (2026-07-14, QA: PASS/haiku, first attempt) — Investigated public-page CSS across all modules: the structural layer (`css/base/variables.css` design tokens + `css/main.css` shared components) is already consistent by single-source construction, and the ~225 hardcoded section-file text colors cluster tightly in the warm-Japandi brown family (no perceptible drift) — both closed as evidence-based no-action, wholesale tokenization deliberately not queued (open-ended, out of the checklist item's intent). Found one genuine, finite drift: `contact.css` and `restaurant.css` used cool neutral greys (#333/#666/#999) breaking the warm palette used everywhere else. Converged 11 `color:` declarations onto values already in the site's vocabulary (#1A1A1A/#6B5740/#8B7355) — zero new colors introduced, all on light surfaces so contrast strictly improved. QA passed clean on the first attempt with the scope-verification warning included.

**PROJECT COMPLETE WHEN checklist: 13 of 14 items now checked.** The sole remaining item (online payment capture, P2-01) is owner-blocked — not agent-completable per the hard-stop rails (no code/credential decisions without explicit confirmation). All buildable work in the approved scope is done.
- **P3-03** (2026-07-14, QA: PASS/haiku, first attempt) — Corrected task scope: `admin/process-checkin.php` is a headless JSON endpoint with no UI; the actual check-in workflow UI is `admin/booking-details.php`. Found real sub-44px touch targets (unlike P3-02): check-in `.action-btn` ≈39-40px, housekeeping's `.hk-inline-control`/`.btn-quick`/`.btn-quick-assign`/row-action icon buttons all 30-38px, with no 1024px breakpoint at all in housekeeping.css. Added additive `@media (max-width:1024px)` blocks to `admin/css/booking-details.css` and `admin/css/housekeeping.css` raising all named controls to ≥44px; correctly left the already-good 136px room cards and stat-filter cards untouched. QA scope-verification warning (about the git-HEAD false-positive) included in the dispatch brief this time — gate passed clean on the first attempt.
- **P3-02** (2026-07-14, no QA gate — zero code changes) — Investigated POS (`admin/pos.php` →
  `css/pos-overrides.css`) and KDS (`admin/kds.php` → `css/kds.css`) for tablet usability
  (768–1024px, 44px targets). Both are already purpose-built touchscreen interfaces with prior
  tablet passes: pos-overrides.css self-identifies as a "touchscreen POS interface," has dedicated
  641–1024 + max-width:1024 breakpoints reflowing to a single-column touch shell, and 44–56px
  primary controls with hover gated behind `(hover: hover) and (pointer: fine)`. kds.css opens with
  an explicit "filter buttons ≥48px on ≤1024px" rule, has 901–1024/901–1280 tablet blocks with 44px
  ticket-action buttons, and collapses its filter bar into a touch off-canvas drawer (burger toggle,
  full ARIA) on tablet rather than dropping filtering. The only sub-44px values found are
  non-interactive containers/KPI stat strips/status chips or an intentional short-landscape
  (max-height:720px) density adaptation — none are primary tap targets. Closed as evidence-based
  no-action (see P3-02 investigation block above), mirroring P3-01's already-passing axes.
- **P3-01** (2026-07-14, QA: PASS/haiku on re-gate) — Investigated the booking widget → confirmation flow: keyboard nav, screen-reader labels, and 320px width all already passed (evidence-based no-action). Found one real contrast gap: `#9B8A72` muted-label text (~3.0-3.35:1) used 22× across booking.css/confirmation.css/booking-confirmation.php, below WCAG AA. Replaced with `#736149` (5.3-5.9:1). Also surfaced during this investigation: `includes/seo-meta.php` is NOT dead (see P1-04 correction above). First QA gate attempt incorrectly FAILED on the same git-HEAD-baseline confusion as P1-03g; re-gated with corrected instructions, passed clean.
- **P2-04** (2026-07-14, QA: PASS/sonnet) — Investigated booking.php's 197KB source size: confirmed CSS/fonts already optimal and full-page caching correctly NOT applied (dynamic per-request content). Found the real issue: a ~2223-line inline `<script>` block shipped uncacheable on every load. Extracted verbatim to `js/booking.js` (gains the 1-year browser cache + gzip `.htaccess` already grants `.js`), hoisted the one embedded PHP echo to a `maxAdvanceDays` JS const. QA confirmed byte-identical extraction, zero PHP logic/CSRF/markup touched, all shared globals correctly referenced (not shadowed) in the new file.
- **P2-02 + P2-03** (2026-07-14, QA: PASS/sonnet) — New `scripts/guest_lifecycle_emails.php` (cron entry, mirrors gym reminder script's CLI/flock/web-guard pattern) + `admin/includes/guest-lifecycle-lib.php` (self-creating `guest_communication_log` table, UNIQUE(booking_id,stage) idempotency, claim-then-send-then-unclaim). Added `sendPreArrivalReminderEmail()`/`sendPostStayReviewRequestEmail()` to `config/email.php` (post-stay links to `submit-review.php?room_id=<id>`, corrected mid-build from an initially-wrong `?ref=` param). Added 4-setting admin toggle UI to `admin/booking-settings.php`, both defaulting OFF, inside the existing form/CSRF branch. Verified live: toggles OFF, log table empty (no real guest emails sent during build/test), UNIQUE key confirmed via SHOW INDEX, status filter matches the actual verified enum (`confirmed`/`pending`/`cancelled`/`no-show`/`tentative` — no invented statuses).
- **P1-05** (2026-07-13, QA: PASS/sonnet) — Found and fixed a genuine public-facing info leak: `includes/db-error.php` was emitting the raw PDO exception (potential DB host/user/SQLSTATE) into browser console on every connection failure, unconditionally. Gated behind `!empty($dbDebug)`. Also hardened `.user.ini` with `display_errors = Off` / `log_errors = On` for PHP-FPM (closing a gap where the existing `.htaccess` mod_php directive wouldn't apply). `config/database.php` untouched, error_log() calls intact.

**Phase 1 (Stabilise) substantially complete** — P1-01, P1-02, P1-03, P1-05 all done. Only P1-04 remains, blocked on owner sign-off for a single dead-file deletion (`includes/seo-meta.php`). Proceeding to Phase 2.
- **P1-03ef** (2026-07-13, QA: PASS/sonnet) — Fixed a genuinely broken endpoint: `api/blocked-dates.php` previously self-included the router, which 404'd before its own logic ran. Added `case 'blocked-dates':` to `api/index.php`'s switch, converted `blocked-dates.php` to the standard `API_ACCESS_ALLOWED`/`$auth`/`$client` guard pattern (also fixed a second latent bug: the old manual `ApiAuth::authenticate()` call expected a return shape the method doesn't produce). Added an explicit `site_pages.is_enabled` visibility guard + rate limiting (`pub_rate_limit`) to `api/page-content.php`, mirroring `includes/page-guard.php`. All 3 files lint-clean, scope surgical.
- **P1-03g** (2026-07-13, QA: PASS/haiku on re-gate) — Fixed `scripts/smoke_test_booking.php:72` interpolation bug (`"$checkIn→$checkOut"` → `"{$checkIn}→{$checkOut}"`). Note: first gate attempt incorrectly FAILED by comparing against git HEAD instead of the P1-01-merged working-tree state (this project never commits mid-loop) — re-gated with corrected baseline instructions, passed. Minor flakiness observed on re-run: section 8 (tentative booking) hit a duplicate-key error from a prior run's leftover state — test-isolation issue, not caused by this fix; worth a follow-up but not blocking.
- **P1-03c** (2026-07-13, QA: PASS/sonnet) — `admin/backup-management.php` standardized on `validateCsrfToken()`/`$csrf_token` from `admin-init.php`, removed the custom `backup_tok` session scheme. Both forms (run_backup, restore) fixed; surgical diff, backup/restore logic untouched.
- **P1-02** (2026-07-13, QA: PASS/sonnet) — New `scripts/smoke_test_finance.php` (21/21 checks pass, idempotent, non-destructive to live sequence counters, `includes/finance-sequences.php` untouched). Standardized `admin/pos.php:387` and `admin/restaurant-tables.php:110` cash-tendered checks from ad-hoc `+0.001` to `BALANCE_TOLERANCE` (both surgical single-line diffs). Client-side JS tolerance checks intentionally left as-is.
- **P1-01** (2026-07-13, QA: PASS/sonnet) — Extended `scripts/smoke_test_booking.php` with 3 new sections: availability check (`checkAvailability()`), pricing reconciliation (`applyDynamicPricing()`, all comparisons BALANCE_TOLERANCE-safe), zero-nights edge guard. Live run: 53/53 passed, no test data left behind, `includes/booking-functions.php`/`includes/pricing.php` untouched. QA independently re-ran the script and lint, confirmed all 6 acceptance criteria. Found (not fixed, logged to P1-03g): pre-existing undefined-variable warning in section 4.
- **P0-01** (2026-07-13) — SYSTEM_MAP.md created, root public pages (28 files) mapped. No QA gate (mapping-only). Findings: CSRF present on all POST handlers, all queries prepared, all output escaped. `booking.php` and `gym.php` flagged as >40KB monoliths (feeds P1-03/P1-04).
- **P0-05** (2026-07-13) — Answered by P0-01 scout: no online payment capture anywhere in the booking flow; all bookings settle manually at check-in. This is the biggest revenue gap vs Cloudbeds/SiteMinder — P2-01 will need an owner decision on gateway vs. continued manual settlement.
- **P0-07** (2026-07-13) — Answered by P0-01 scout: 8 root pages send guest email via PHPMailer (booking, contact, conference, events, gym, review). No pre-arrival or post-stay automated sequence yet — confirms gap #3, feeds P2-02/P2-03.
- **P0-02** (2026-07-13) — SYSTEM_MAP.md appended: 10 config/ + 41 includes/ files mapped. Security primitives inventory recorded (CSRF: public-csrf.php, validation: validation.php, page-guard.php, idempotency.php, audit: system-logger.php/booking-timeline.php, money-safety: BALANCE_TOLERANCE in config/database.php). Dead file flagged: `includes/seo-meta.php` (zero references — do not delete without owner sign-off per P1-04). 5 files >400 lines flagged for possible refactor consideration.
- **P0-03** (2026-07-13) — SYSTEM_MAP.md appended across 3 scout calls covering all 86 `admin/` root files: bookings & finance (32 files, full money-path trace booking→payment→invoice→receipt→refund→credit-note→EOD, all BALANCE_TOLERANCE-safe, zero dead files), POS/stock/gym (23 files, full money-path trace order→kitchen→payment→void→EOD, one inconsistency flagged: only cash payment uses a +0.001 tolerance, other POS money comparisons don't consistently use BALANCE_TOLERANCE — feeds P1-03), content/events/integrations/system (31 files — two flags: `facebook-settings.php` may store the access token in plaintext if `encryptApiKey()` is unavailable, and `backup-management.php` uses a custom session CSRF check instead of the standard validator — both feed P1-03; login.php has no brute-force lockout, logging only). No dead admin pages found anywhere.
- **P0-06** (2026-07-13) — No PHPUnit suite exists despite `phpunit/phpunit ^10` in composer require-dev (never installed/configured, no phpunit.xml). However `scripts/smoke_test_booking.php` already exists: a 265-line live-DB smoke script (DB connectivity, bookings schema, active-rooms checks, self-cleaning, pass/fail counters). Other scripts of note in `scripts/`: `expire_tentative_bookings.php`, `gym_membership_reminders.php` (relevant to P2-02 pre-arrival email work), `patch_amount_due_drift.php`, `stock-audit.php`. P1-01/P1-02 revised to extend this existing pattern rather than bootstrap PHPUnit from scratch (see Assumptions log).
- **P0-04** (2026-07-13) — SYSTEM_MAP.md appended: 23 `api/` endpoints mapped (11 API-key protected, 5 admin-session protected, ~4 public + router). Router (`api/index.php`) validates hashed X-API-Key against `api_keys` table, rate-limits, logs to `api_usage_logs`, gates endpoints via `API_ACCESS_ALLOWED` + `$auth`/`$client`. No critical auth bypasses — all key-protected endpoints have the direct-access guard. Two low-risk gaps flagged (feed P1-03): `admin/api/blocked-dates.php` includes `index.php` directly instead of going through the router, and `api/page-content.php` has no explicit auth guard (relies on page-visibility controls). No dead endpoints.

**Phase 0 (Learn) complete — all 7 tasks done.** SYSTEM_MAP.md now covers all four trees (root, includes/config, admin/, api/) satisfying completion-definition item 6. Proceeding to Phase 1 (Stabilise).
