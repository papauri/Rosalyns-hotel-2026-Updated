# Rosalyn's Hotel 2026 — Project Instructions

Vanilla PHP (≥7.4, no framework) hotel website + PMS. PDO/MySQL (creds via `.env` →
`config/database.local.php`), PHPMailer, TCPDF. Page-per-file; shared functions in
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
- `.claude/skills/build-loop/SKILL.md` — the cycle: plan → build → polish (UI only) →
  QA gate → mark done or one retry → STATUS block → advance automatically.

## Standing rails (apply to ALL sessions and agents)

- **Cost**: /compact every 3 completed tasks; /clear on phase switch; haiku for read-only/
  lint, sonnet for build execution; Fable 5/Opus only for setup and planning, never routine
  execution; max 2 concurrent specialists; /cost checkpoint after every loop cycle; every
  subagent brief carries exact file paths — no open-ended "explore".
- **Safety**: never commit or push (only the owner triggers that explicitly); never
  destructive SQL; never edit `.env`; park owner decisions as `blocked:` in BUILD_PLAN.md
  and continue with the next task.
- **Reporting**: STATUS block after every task (finished / in-progress / next 3 / blocked);
  SESSION SUMMARY at end of every run (completed, remaining by phase, blockers,
  one-line recommendation).
- **Autonomy**: never pause because a task finished — pull the next queued task. Stop only
  for: blocker needing owner, task failed twice, safety violation, or Phase 3 complete.
  Ambiguous-but-not-blocking → best assumption, logged as `ASSUMPTION:` in BUILD_PLAN.md.

## Code conventions (enforced by qa-auditor)

Prepared statements always · `htmlspecialchars()`/`sanitizeString()` on all user-data
output · CSRF on every POST (admin: `admin-init.php` token; public:
`includes/public-csrf.php`) · money comparisons via `BALANCE_TOLERANCE`, never raw float
equality · reuse `includes/` helpers before writing new ones · procedural style, no
frameworks/build steps/new Composer packages without owner approval · `php -l` every
changed file · never read `vendor/`, `PHPMailer/`, `logs/`, `cache/`, `backups/`,
`images/`, `Database/`.
