# Rosalyn's Hotel 2026 — Project Instructions

Vanilla PHP (≥7.4, no framework) hotel website + PMS. PDO/MySQL (creds via `.env` →
`config/database.php`), PHPMailer, TCPDF. Page-per-file; shared functions in
`includes/`; admin panel in `admin/` (session + CSRF + per-page permissions via
`admin/admin-init.php`); key-authenticated JSON API in `api/`. Plain HTML/CSS/JS, no build step.

General rules for every project (safety, git, models and cost, UI) live in `../AI-Setup/RULES.md`
(the shared `AI-Setup` folder next to this repo). This file adds the hotel facts and may be stricter, never looser.

## Autonomous build system (default workflow)

This project runs on an agent build system. **Default to `/build-loop`** for build work —
do not re-derive plans or re-scan the repo ad hoc.

- `.claude/CORE_SYSTEM_BRIEF.md` — **every agent's Step 0.** The whole system in one page:
  14 core functional domains → primary files, who uses each surface, non-negotiable
  conventions, safety rails, output discipline (no code in the terminal).
- `.claude/COVERAGE_MATRIX.md` — per-domain sweep status; how the loop guarantees it
  touches every aspect of the system. Planner owns Status, scout owns Mapped.
- `.claude/PROJECT_CONTEXT.md` — goals, users, best-in-class bar (Cloudbeds/Mews/
  Little Hotelier), ranked gaps. Required reading for build-planner before any planning.
- `.claude/BUILD_PLAN.md` — phased backlog (P0 Learn → P1 Stabilise → P2 Complete →
  P3 Polish) + the project-specific "built to completion" definition. Owned by build-planner.
- `.claude/SYSTEM_MAP.md` — feature→file→endpoint→table map, built by codebase-scout.
  Briefs reference this instead of re-scanning.
- `.claude/agents/` — codebase-scout (haiku, read-only mapper) · build-planner (opus,
  plans only, never edits code) · backend-specialist (opus) · frontend-specialist
  (opus) · ui-designer (opus, polish pass on just-touched files) · qa-auditor
  (read-only gate, opus, anything with logic) · qa-quick (sonnet; only CSS/markup/docs diffs, escalates the rest). Model rule:
  RULES.md "Models and cost"; here "logic" also covers the Assistant rails.
- Department leads (`.claude/DEPARTMENTS.md`): `dept-rooms-lead`, `dept-restaurant-lead`,
  `dept-accounting-lead`, `dept-stock-lead`, `dept-guest-site-lead`, `dept-events-gym-lead`, `dept-pwa-lead` (patch-only: installed app, offline, POS/KDS stations), `dept-import-lead` (opus; Import button: Excel/CSV/Word/PDF → review → import, `data_import` permission default admin+manager, no staff-list import, spec `.claude/IMPORT_PLAN.md`), `dept-patch-lead` (owner-approved feature patches in `.claude/PATCH_QUEUE.md` + all security patches), `dept-logs-lead` (owner 2026-11-14; System Logs + activity hook + Hotel Settings change log, who/when/old → new, secrets never stored), `dept-eod-lead` (owner 2026-11-13; End of Day report + timed report emails: who gets what, when, test mode, never twice; contract `.claude/EOD_SPEC.md`; liaises with accounting/restaurant/rooms/AI/guides leads) (opus; each audits/fixes its department end to end and
  is the one to ask when it breaks) and `artifacts-lead` (owner 2026-11-12: the anonymised sales showcase in `OneDrive\MSP\Showcase`, business-type picker + MSP panel track; same lead in msp-control-panel; told of shipped screen changes via the SYNC QUEUE, worked through once a week) and `manager-guides-lead` (owner 2026-11-19; haiku, sonnet for a brand-new guide: the illustrated 2-page manager PDFs in `OneDrive\MSP\Manager Guides`, kept current in every guides sync) and `ui-lead` (one visual language; turns the leads'
  `UI_REQUEST`s into shared `UI_DECISION`s). Money changes from any department are reviewed by the
  accounting lead.
- `.claude/skills/build-loop/SKILL.md` — the cycle: plan → build → polish (UI only) →
  QA gate → mark done or one retry → STATUS block → advance automatically.

## Standing rails (apply to ALL sessions and agents)

- **ROSALYN'S IS LIVE (owner 2026-11-09)**: real guests and money. Extreme caution. No DB tests,
  writing scripts or test emails against Rosalyn's DB. Change Rosalyn's
  settings/data only when the owner asked for that exact change. Every dispatch brief must say this.
  Flow (owner 2026-11-21): build and test at Liwonde, push, then port the change here. Enforced: `.claude/LIVE_REPO`
  makes the shared guard refuse test runs in this folder (scripts/tests, run_tests.php, smoke_test*.php, and
  `scripts/ship.php` unless `--tests=none`).
- **LIWONDE IS THE DEDICATED TEST PLATFORM (owner 2026-11-12, replaces "Liwonde is live too")**: a mirror
  of Rosalyn's bookings, accounting and rooms with made-up client details (names, emails @example.invalid,
  phones, addresses, companies, notes) and outbound email/WhatsApp muted. DB tests, test bookings and
  rehearsals run there. Rosalyn's stays LIVE: it is only ever a read-only source for the mirror script.

- **Cost (hotel part; the general cost rules are in RULES.md)**: guides + AI Assistant always LAST and only when
  the owner prompts (end of day or on request; owner 2026-11-14). Every ship logs what changed for them in
  `.claude/GUIDES_AI_SYNC.md`; never run the sync unprompted. Small tasks inline ONLY IF they cannot break logic.
  Follow the Cost rules in `.claude/DEPARTMENTS.md` (haiku for small QA, one QA per ship, `scripts/ship.php` to
  ship; the Fable cases are DEPARTMENTS.md cost rule 2). /compact every 3 completed tasks; /clear on phase switch;
  max 2 concurrent specialists; /cost checkpoint after every loop cycle.
- **Safety (hotel part)**: park owner decisions as `blocked:` in BUILD_PLAN.md and continue with the next task.
- **Git (stricter than RULES.md)**: commit only work that has passed the ship gate in `.claude/DEPARTMENTS.md`:
  `php -l`, tests, a mandatory independent `qa-auditor` review of the diff, and a headless
  browser check (`php -S 0.0.0.0:8000` + `scripts/tests/browser_check.mjs`, never MCP) for
  anything with a screen. One commit per completed task, message stating what changed and why.
  `.env` is untracked and stays that way. Never run `scripts/deploy_cpanel.php` (the owner deploys).
- **Task tracker**: `.claude/TASKS.md` is the live list of open/shipped work. Read it first when a
  session resumes; the orchestrator updates it whenever a task starts, an agent reports, or work ships.
- **Reporting**: STATUS block after every task (finished / in-progress / next 3 / blocked);
  SESSION SUMMARY at end of every run (completed, remaining by phase, blockers,
  one-line recommendation).
- **Autonomy (owner 2026-10-24, overrides /build-loop's "advance automatically")**: the code is
  FINISHED (BUILD_PLAN.md §6). Do only what the owner asks or a reported PATCH fault; when it ships,
  stop. Never pull queued/LATER work on your own. Ambiguous-but-not-blocking → best assumption,
  logged as `ASSUMPTION:` in BUILD_PLAN.md.

## Token rules (hotel part; the general ones are in RULES.md "Models and cost")
1. When a session resumes, state lives in `.claude/TASKS.md` "Open": read only that.
2. Inline work here means a one-file fix, lookup, port or test; never parallel audits "to be safe".
3. Builders run only their own test file (`php scripts/tests/<x>.php`), never the full
   `run_tests.php` (10+ min, agents loop on it). At ship the orchestrator runs only what the change needs (tiers in DEPARTMENTS.md ship gate step 1): no DB tests for UI/docs, the area's suites for one area, the full suite only for core/money/migrations/auth.
4. Never read big files whole: `.claude/CODE_INDEX.md` + Grep + offset reads. `config/database.php`
   (~15.1k lines), BUILD_PLAN/SYSTEM_MAP/CODE_INDEX are lookup-only. Shipped history is in
   `.claude/archive/`, never read unless asked.
5. Briefs carry test cases as well as paths and lines.
6. Hard limits (owner 2026-11-17): sessions auto-compact at 250k (`.claude/settings.json`), and
   `scripts/claude-hooks/read-guard.php` blocks whole reads of files over 400 lines / 40 KB. Work already
   committed at Rosalyn's is ported with `php scripts/port-commit.php <commit>`, not an agent.
   The weekly usage report (RULES.md) replaces COST_LOG.md; see build-loop SKILL.md "Cost tracking".

## Code conventions (enforced by qa-auditor)

Prepared statements always · `htmlspecialchars()`/`sanitizeString()` on all user-data
output · CSRF on every POST (admin: `admin-init.php` token; public:
`includes/public-csrf.php`) · money comparisons via `BALANCE_TOLERANCE`, never raw float
equality · reuse `includes/` helpers before writing new ones · procedural style, no
frameworks/build steps/new Composer packages without owner approval · `php -l` every
changed file · never read `vendor/`, `PHPMailer/`, `logs/`, `cache/`, `backups/`,
`images/`, `Database/`.
