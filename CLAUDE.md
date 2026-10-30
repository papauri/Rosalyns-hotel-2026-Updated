# Rosalyn's Hotel 2026 — Project Instructions

Vanilla PHP (≥7.4, no framework) hotel website + PMS. PDO/MySQL (creds via `.env` →
`config/database.php`), PHPMailer, TCPDF. Page-per-file; shared functions in
`includes/`; admin panel in `admin/` (session + CSRF + per-page permissions via
`admin/admin-init.php`); key-authenticated JSON API in `api/`. Plain HTML/CSS/JS, no build step.

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
  plans only, never edits code) · backend-specialist (sonnet) · frontend-specialist
  (sonnet) · ui-designer (sonnet, polish pass on just-touched files) · qa-auditor
  (read-only gate; haiku for lint, sonnet for logic/security).
- Department leads (`.claude/DEPARTMENTS.md`): `dept-rooms-lead`, `dept-restaurant-lead`,
  `dept-accounting-lead`, `dept-stock-lead`, `dept-guest-site-lead`, `dept-events-gym-lead`, `dept-pwa-lead` (patch-only: installed app, offline, POS/KDS stations), `dept-patch-lead` (owner-approved feature patches in `.claude/PATCH_QUEUE.md` + all security patches) (sonnet; each audits/fixes its department end to end and
  is the one to ask when it breaks) and `ui-lead` (one visual language; turns the leads'
  `UI_REQUEST`s into shared `UI_DECISION`s). Money changes from any department are reviewed by the
  accounting lead.
- `.claude/skills/build-loop/SKILL.md` — the cycle: plan → build → polish (UI only) →
  QA gate → mark done or one retry → STATUS block → advance automatically.

## Standing rails (apply to ALL sessions and agents)

- **Cost**: follow the Cost rules in `.claude/DEPARTMENTS.md` (no agent for small work, haiku
  for docs/lint/small QA, CODE_INDEX + offset reads instead of whole files, one QA per ship,
  `scripts/ship.php` to ship). Also: /compact every 3 completed tasks; /clear on phase switch; haiku for read-only/
  lint, sonnet for build execution; Fable 5/Opus only for setup and planning, never routine
  execution; max 2 concurrent specialists; /cost checkpoint after every loop cycle; every
  subagent brief carries exact file paths — no open-ended "explore".
- **Safety**: commit and push completed work to `main` (see Git below); never destructive
  SQL; never edit `.env`; park owner decisions as `blocked:` in BUILD_PLAN.md and continue
  with the next task.
- **Git**: commit and push to `origin/main` directly — no feature branches, no waiting for
  the owner to trigger it. Commit only work that has passed the ship gate in `.claude/DEPARTMENTS.md`:
  `php -l`, tests, a mandatory independent `qa-auditor` review of the diff, and a headless
  browser check (`php -S 0.0.0.0:8000` + `scripts/tests/browser_check.mjs`, never MCP) for
  anything with a screen. One commit per completed task, message
  stating what changed and why. Check the diff for secrets and stray files before staging;
  `.env` is untracked and stays that way. Never force-push, never rewrite pushed history.
- **Task tracker**: `.claude/TASKS.md` is the live list of open/shipped work. Read it first when a
  session resumes; the orchestrator updates it whenever a task starts, an agent reports, or work ships.
- **Reporting**: STATUS block after every task (finished / in-progress / next 3 / blocked);
  SESSION SUMMARY at end of every run (completed, remaining by phase, blockers,
  one-line recommendation).
- **Autonomy (owner 2026-10-24, overrides /build-loop's "advance automatically")**: the code is
  FINISHED (BUILD_PLAN.md §6). Do only what the owner asks or a reported PATCH fault; when it ships,
  stop. Never pull queued/LATER work on your own. Ambiguous-but-not-blocking → best assumption,
  logged as `ASSUMPTION:` in BUILD_PLAN.md.

## Token rules (owner 2026-10-24: "usage is way over normal") — apply before every step
1. Fresh session per task: `/clear` after each shipped task; never `/resume` a long session (it
   re-reads the whole history uncached). State lives in `.claude/TASKS.md` "Open" — read only that.
2. Do it inline when it is under ~10 tool calls (one-file fix, lookup, port, test). An agent only for
   real multi-file build work: ONE builder + ONE review, never parallel audits "to be safe".
3. Builders run only their own test file (`php scripts/tests/<x>.php`), never the full
   `run_tests.php` (10+ min, agents loop on it). At ship the orchestrator runs only what the change needs (tiers in DEPARTMENTS.md ship gate step 1): no DB tests for UI/docs, the area's suites for one area, the full suite only for core/money/migrations/auth.
4. Never read big files whole: `.claude/CODE_INDEX.md` + Grep + offset reads. `config/database.php`
   (~9.5k lines), BUILD_PLAN/SYSTEM_MAP/CODE_INDEX are lookup-only. Shipped history is in
   `.claude/archive/`, never read unless asked.
5. Briefs carry the facts (paths, lines, rule, test cases) so agents don't re-discover them; reports
   under 150 words. Haiku for lint/docs/guides, Sonnet for build, Opus only for the orchestrator.

## Code conventions (enforced by qa-auditor)

Prepared statements always · `htmlspecialchars()`/`sanitizeString()` on all user-data
output · CSRF on every POST (admin: `admin-init.php` token; public:
`includes/public-csrf.php`) · money comparisons via `BALANCE_TOLERANCE`, never raw float
equality · reuse `includes/` helpers before writing new ones · procedural style, no
frameworks/build steps/new Composer packages without owner approval · `php -l` every
changed file · never read `vendor/`, `PHPMailer/`, `logs/`, `cache/`, `backups/`,
`images/`, `Database/`.
