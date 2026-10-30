# Department agents — shared operating rules

Every `dept-*-lead` and `ui-lead` reads this file after
`.claude/CORE_SYSTEM_BRIEF.md`.

## The structure

| Agent | Department (CORE_SYSTEM_BRIEF domains) | Owns |
|---|---|---|
| `dept-rooms-lead` | Booking engine, reservations & front desk, rooms (1, 2, 3) | bookings, room assignment, joined rooms, calendar, blocked dates, housekeeping, maintenance, individual rooms |
| `dept-restaurant-lead` | POS & F&B (4) | POS till, tabs, KDS/BDS/CDS, Orders & Tabs, deals, room service, tables, station chat |
| `dept-accounting-lead` | Finance & accounting (6) | payments, folios, invoices, receipts, credit notes, refunds, VAT/levy, shift close, end of day, POS accounting, reports |
| `dept-stock-lead` | Stock & procurement (5) | ingredients, recipes, stock deduction/restore, FIFO batches, counts, wastage, receiving, suppliers, POs, buying |
| `dept-guest-site-lead` | Guest website (13 + the public side of 1, 7, 8) | public pages, booking flow, in-site navigation, loader, SEO (PWA mechanics: `dept-pwa-lead`) — may differ per hotel |
| `dept-events-gym-lead` | Gym, conference & events (7, 8) | conference enquiries, event bookings/RSVPs/waitlist, gym packages, memberships, check-in, classes, slots |
| `dept-pwa-lead` | Installed app / PWA (part of 4, 11, 13) | service workers, manifests, install, offline queue + replay, station reconnect/wake, sign-out cache safety — admin, POS, KDS/BDS/CDS and public site mechanics. **Patch only** |
| `dept-patch-lead` | Feature + security patches (all) | `.claude/PATCH_QUEUE.md` (owner-approved backlog), standing security sweep, briefs the owning lead, gates and hands to ship; small cross-cutting/security fixes itself |
| `dept-backup-lead` | Backups & data safety (part of 11) | nightly backup, Run Backup Now, rotation, download/delete/restore, backup log, health checks, backup failure/stale/off-site alerts |
| `dept-ai-lead` | In-portal AI Assistant (owner 2026-10-26: handled **only** by this lead) | everything in `.claude/AI_ASSISTANT_PLAN.md`: panel, providers/keys/models, vault, tools, swarm, confirm actions, learning, self-healing, kill switches, evals, completion guardrail, its guide pages. Opus 5.5. Other leads never edit AI files |
| `ui-lead` | Cross-system UI | one visual language across every department; reviews UI requests from the leads |

Each lead is responsible for its department end to end: it audits, fixes, tests, and is the
one the orchestrator asks when something in that department breaks. Leads are peers: when a
fix crosses a boundary (e.g. a POS change that moves money), the owning lead must review it.

## Two modes (the brief says which)

- **AUDIT** — read-only. Produce findings; edit nothing.
- **FIX** — apply the findings you are given (or your own, if the brief says so), test, report.

## Finding format (AUDIT output, one line each, most severe first)

`F<n> | <critical|high|medium|low> | <file>:<line> | <what is wrong> | <concrete wrong outcome> | <proposed fix> | <owner: self|other-dept-lead|BLOCKED>`

Only real defects with a concrete wrong outcome. No style nits, no "consider". Money and
stock semantics that need an owner decision are `BLOCKED:` per CORE_SYSTEM_BRIEF's
escalation rule — settled owner decisions are in the memory file
`accounting-decisions-2026-10` (the orchestrator pastes the relevant ones into your brief);
never re-decide those.

## Talking to the UI lead

Leads do not restyle. Anything visual (layout, spacing, button alignment, modal sizing,
consistency with other screens) goes in your report as:

`UI_REQUEST | <file> | <screen/element> | <what is wrong for the user> | <desired outcome>`

The orchestrator hands every UI_REQUEST to `ui-lead`, which answers with `UI_DECISION` lines
(the shared pattern to use) and applies them. If you need a UI decision before you can
finish, say `NEEDS_UI_DECISION: <question>` and carry on with the rest.

## Rails that apply to every department agent

- Both hotels, always: Rosalyn's (`C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026`)
  and Liwonde (`C:\Users\john-paul.chirwa\OneDrive\MSP\Liwonde_Sun_Hotel_2026`) share all
  admin/api/includes/config/scripts code. Work in Rosalyn's; the orchestrator ports to
  Liwonde unless your brief says you port.
- The database is LIVE and shared, and tests run against it (owner's choice). Test only inside
  transactions you roll back, or with `scripts/tests/page_harness.php` (admin page POST as
  admin, rolled back). The harness skips statements that would commit implicitly (DDL, LOCK,
  autocommit) and reports them as `ddl_skipped`; if it prints `HARNESS LEAK` or `"leak":true`,
  stop and report it. Never point it at backup/restore actions or anything that spawns a
  subprocess or opens its own DB connection (those bypass the rollback). Never leave rows behind.
- Leads that touch the same files run one after another, never in parallel (shared hot spots:
  `config/database.php`, `admin/pos.php`, `admin/bookings.php`, `admin/reports.php`,
  `admin/includes/*`). Read-only AUDITs may run in parallel.
- Never run git. Never edit `.env`. No DDL (schema changes are owner decisions).
- `php -l` every changed PHP file; `node --check` every changed inline script (extract it).
- Run only the test file(s) for your change (`php scripts/tests/<x>.php`), once. Never the full
  `php scripts/run_tests.php` (10+ min) — the orchestrator runs it once at ship (token rule 3).
- Admin SPA: `admin/js/admin-spa.js` swaps only `#rh-admin-page`; modals printed after
  `includes/admin-footer.php` vanish after in-place navigation and inline `<head>` scripts are
  not re-run. Guest-site navigation keeps only `<main>`.
- Output: files changed (paths only) · ≤6-line outcome · findings/UI_REQUEST/BLOCKED lines ·
  lint and test results. No code blocks.

## Linked data (owner, 2026-10-19)

Any change that adds or touches a delete, cancel, void, expire or no-show path: the owning lead
lists every linked row (link map and rule in `.claude/plans/linked-data-cleanup.md`) and gives
each one CLOSE / DELETE / KEEP / BLOCK in the same transaction — nothing linked may keep running
(open tab, live ticket, held room, pending task, active reminder). Money and history are KEEP
(void/refund, never DELETE). A new table with a parent id column is added to the link map and to
`scripts/data-health.php` (runs in `php scripts/run_tests.php`).

## Right first time (owner, 2026-10-24: "If they did their job right once we would have completed this ages ago")

Rework came from three places: builders weren't checking what reviewers check, reviewers were
hunting for anything they could find, and side findings became new fix rounds. So:

1. **One checklist, used by both sides.** The builder works through the DONE CHECKLIST below
   before handing back and reports each line as done / not applicable. The reviewer checks the
   same list and the brief's criteria — nothing new is invented at review time.
2. **Builders test their own screens.** Any changed screen: the builder runs the headless browser
   check (desktop 1400 + phone 390) itself, including the empty / no-data state.
3. **Reviews are a gate, not a hunt.** A reviewer FAILs only for a BLOCKER: wrong number or money,
   security hole, error/crash, data loss, a broken or misleading screen, or a brief criterion not
   met. Everything else is one `LATER:` line — the orchestrator files it in `.claude/TASKS.md`
   and it never triggers a fix round on this task.
4. **One review per change.** Money changes: `dept-accounting-lead` is the gate (it applies the
   qa-auditor checklist too) — not accounting review *and* qa-auditor. Everything else: qa-auditor.
5. **One fix round, then stop.** The fix round fixes only the listed BLOCKERs; the re-check looks
   only at those (orchestrator verifies if small). Still failing → nothing ships, it is reported
   to the owner as "failed twice".
6. **Audits sort before anything is built.** Each finding is `FIX-NOW` (wrong today with a real
   effect on staff, guests or money) or `LATER`. Only FIX-NOW goes into one FIX brief, all at once.
7. **No scope growth mid-task.** Faults found while doing something else (e.g. while writing
   helpers) are written down as `LATER:` and batched per department after the current task ships —
   unless they show staff a wrong money figure today, which counts as FIX-NOW for the next task.

### PATCH or FEATURE — label every task before anyone starts (owner, 2026-10-24)
The feature freeze in BUILD_PLAN.md ("PRODUCTION READINESS GATE") still stands. Every line in
`.claude/TASKS.md` and every brief starts with `PATCH` or `FEATURE`.
- **PATCH** fixes a fault of one of the four kinds: (1) money is wrong, (2) a guest can't book or pay,
  or a guest document is wrong, (3) staff are blocked, (4) data loss or a security hole. It is the
  smallest change that removes the fault: no new screens, no new wording beyond what the fix
  needs, no migrations unless the fault can't be fixed without one. It needs no approval.
- **FEATURE** is everything else: new behaviour, new or changed screens, columns, helpers,
  clearer wording, polish, "while we're here" improvements, and migrations that add capability.
  It is built ONLY when the owner asked for it in so many words or it is on `.claude/PATCH_QUEUE.md`.
  The brief lists the exact deliverables up front; the feature is done when that list is done.
- **When unsure, it is a FEATURE**: log it as `LATER:` / Future Ideas and don't build it.
- Findings from an audit or review are sorted the same way: PATCH-kind faults may be fixed next;
  FEATURE-kind ideas go to the monthly list for the owner.
- A PATCH brief never grows into a FEATURE. If a fix needs feature work, stop and ask the owner.

### DONE CHECKLIST (builder before hand-back = reviewer's checklist)
- Every place that uses a changed function/value/label is found (Grep) and still right — totals,
  dashboards, PDFs, emails, exports, JS that reads the markup, the "?" helper text.
- Empty, zero, negative and first-day-of-period cases show something sensible (a dash, not 0% or NaN).
- Money: BALANCE_TOLERANCE, both VAT modes, refunds/voids/negatives, rounding; owner rules in
  `accounting-decisions-2026-10` respected.
- Screens: desktop 1400 + phone 390 checked headless, 44px tap targets, no console errors,
  labels and helpers say exactly what the number is.
- Security basics: prepared statements, escaped output, CSRF on POST, permission checks.
- A test for each fix (or the reason none is possible); `php -l` / `node --check`; the area's suites pass.
- Both hotels: nothing hotel-specific added to shared code.

## Ship gate (every change, no exceptions)

1. The lead's own checks: the DONE CHECKLIST above, `php -l`, `node --check`, the area's tests.
   **Tests by change type (owner 2026-10-30)** — the highest tier the diff touches wins:
   - UI-only (CSS, client JS, markup) or wording: NO database tests; lint + review + browser 1920/1280/390.
     Guides/help text: `help_map.php` only. Test/doc-only: that test or none.
   - One area: that area's own suites only (stock_*, POS/KDS smoke + shop_returns + stock_loss, linked_gym,
     conference_full_total, cms_content, ai_assistant_test, booking smoke + linked_rooms + concurrent_booking).
   - Full `run_tests.php` at both hotels before push ONLY for: config/database.php or shared includes/ helpers,
     money (payments, documents, refunds, VAT/levy, pricing, shift/EOD), migrations, login/permissions/security.
   - Safety net: the full set once a day in the background (first ship of the day or when the owner deploys);
     it never blocks a push.
2. **One independent review before every commit** (see "Right first time" 3–5): `qa-auditor`
   (sonnet for logic/stock/security, haiku for small markup/docs), or `dept-accounting-lead` as the
   gate for money changes. BLOCKERs fail; everything else is `LATER:`.
3. **Browser check before anything ships** that touches a screen — no MCP browser tools
   (too many tokens). Serve the repo with `php -S 0.0.0.0:8000 -t . scripts/tests/dev-router.php`
   and drive it with `node scripts/tests/browser_check.mjs steps.json` (headless Chrome;
   `{base}/__devlogin?to=page.php` signs in as admin). Check desktop and 390px phone width.
   If port 8000 is already taken by a plain `php -S` (no router), use another port and set
   `BASE=http://localhost:<port>`.
4. Then port to Liwonde, re-run its tests, commit and push both.
5. **Guides and KB**: once a change that staff will notice has shipped, `guides-kb-lead` updates the
   matching pages in `docs/guides/` in plain, easy-to-follow English and checks the KB search finds
   them; that update goes through the same review and is committed to both hotels.
6. **AI lead sync** (owner 2026-10-30): after ANY other lead's change ships, `dept-ai-lead` is told
   in the same run (short brief: commit hash, files, what changed for staff). It checks whether the
   Assistant's lookups, actions/confirm cards, guided flows, local answers, evals or its own guide
   depend on what changed (renamed columns/tables/settings, new statuses, moved screens, new rules,
   permission changes) and patches + tests the Assistant if so; "no impact" is a valid answer. Its
   fix goes through the same review and ships to both hotels. Skip only for changes made by the AI
   lead itself.

## Cost rules (owner, 2026-10-19: build fast and cheap)

1. **No agent for small work.** A change of one file / ~30 lines, a lint fix, a one-line QA
   finding, a data check: the orchestrator does it directly.
2. **Model by task, not by habit.** haiku: guides/KB, index/mapping, lint-only or small QA
   (diff < ~150 lines, no money/stock/auth), read-only audits that only list facts.
   sonnet: logic changes, and QA/review of money, stock, auth or security. Opus: planning
   only when the owner asks. Never Fable unless told.
3. **Never read whole big files.** Grep `.claude/CODE_INDEX.md` for the file or function you need
   (it is ~100 KB — never read it whole; regenerate with
   `php scripts/code-index.php`) + Grep, then Read with offset/limit around the lines you need.
   Briefs name exact files and functions/line ranges; no open-ended "explore".
4. **Fresh small agent over a big resume.** For a fix round, start a new agent with just the
   finding list unless the original agent's context is small; resuming re-sends its whole history.
5. **One review per ship**, covering the whole change (see "Right first time"); one fix round at
   most, re-checking only the failed BLOCKERs — small fixes are verified by the orchestrator.
6. **Short output.** Reports ≤ 25 lines; list problems, not everything that was fine.
7. **Tests by area while building** (`php scripts/ship.php --tests=pos` etc.); the full suite
   once at ship time. Browser checks only for the screens that changed.
8. **Ship with one command:** `php scripts/ship.php` (lint, tests, port to Liwonde, commit and
   push both) instead of hand steps. See `scripts/README-SHIP.md`.
9. Max 2 builders at once; read-only audits may run beside them.
