---
name: build-loop
description: Autonomous build cycle for Rosalyn's Hotel — planner picks next BUILD_PLAN.md task, specialist builds, ui-designer polishes UI work, qa-auditor gates, then automatically advances to the next task. Use when the user says /build-loop or asks to continue the build.
---

# /build-loop — autonomous build cycle

Run this loop. Do NOT stop between tasks — pull the next queued task automatically.

## The cycle (repeat until a stop condition)

1. **Plan** — spawn `build-planner` (opus): it reads `.claude/PROJECT_CONTEXT.md`,
   `.claude/BUILD_PLAN.md`, `.claude/SYSTEM_MAP.md`, marks ONE task `in-progress`, and
   returns a dispatch brief with exact paths + acceptance criteria. If the needed area
   isn't in SYSTEM_MAP.md, the task becomes a `codebase-scout` (haiku) mapping run instead.
2. **Build** — spawn the specialist named in the brief (`backend-specialist` or
   `frontend-specialist`, sonnet; `codebase-scout`, haiku, for mapping tasks). Pass the
   brief verbatim. The brief MUST contain exact file paths — if it says "explore",
   send it back to the planner once.
3. **Polish** — ONLY if the task changed UI files: spawn `ui-designer` (sonnet) scoped to
   exactly the files the specialist reported touching.
4. **Gate** — spawn `qa-auditor` with the acceptance criteria + changed-file list.
   Lint/format-only gates: dispatch with model haiku. Logic/security gates: sonnet.
   - PASS → mark task `done` in BUILD_PLAN.md (move to Completed with date + verdict).
   - FAIL → send the FIX LIST to the same specialist for ONE retry, re-gate.
     Second FAIL → mark `failed-twice`, record why, move on to the next task.
5. **STATUS block** — print after EVERY task completes or parks (no exceptions):
   ```
   STATUS
   Just finished: <task id — one line>
   In progress:   <task id or —>
   Next 3 queued: <ids + one-liners, priority order>
   Blocked:       <id: exact question for the owner, or —>
   ```
6. **Advance immediately** to step 1 for the next queued task in the same run.

## Cost & safety rails (every cycle, non-negotiable)

- `/compact` after every 3 completed tasks; `/clear` when switching phases.
- Every subagent brief states exact file/line scope — no open-ended "explore".
- Model tiers: haiku for read-only/lint/lookup; sonnet for build work; NEVER Fable 5 or
  Opus for routine execution — Opus only for build-planner; Fable 5 only for setup/replanning.
- Max 2 specialist subagents running concurrently.
- Print a `/cost` checkpoint after every full loop cycle.
- NEVER commit or push. NEVER run destructive SQL (DROP/TRUNCATE/DELETE without WHERE).
- Anything needing the owner's decision → mark `blocked:` with the specific question in
  BUILD_PLAN.md and move on to the next task.
- Ambiguous but not decision-blocking → make the most reasonable assumption, log it under
  the task as `ASSUMPTION:` in BUILD_PLAN.md, continue.

## Fixed end goal — this is not open-ended

`.claude/BUILD_PLAN.md`'s **"PROJECT COMPLETE WHEN"** checklist is the entire approved
scope (owner-approved 2026-07-14). Do not search for or invent additional work once it is
fully checked off. Anything discovered mid-build that isn't on the checklist gets logged
under "Future Ideas (not in scope)" in BUILD_PLAN.md, not queued.

## Stop conditions

**Stop the loop entirely — not just pause — when every item in the PROJECT COMPLETE WHEN
checklist is checked `[x]`.** When this happens: do not go looking for additional work,
do not queue anything from "Future Ideas," do not re-open Phase 0-3 tables looking for
more to do. Print the PROJECT COMPLETE report (format below) and halt.

Also stop (pause, report, and wait) for any of these — same as before:
1. Every remaining unchecked checklist item is now `blocked:` (nothing buildable is left).
2. A task failed twice.
3. A safety-rail violation occurred (commit/push/destructive SQL attempted).

If invoked as `/loop /build-loop`, run continuously through all remaining phases until one
of the above stop conditions — checklist-complete included.

## PROJECT COMPLETE report — print ONLY when every checklist item is `[x]`, then halt

```
PROJECT COMPLETE

All PROJECT COMPLETE WHEN items checked:
[x] <item 1>
[x] <item 2>
... (every line from the checklist, confirmed checked)

Total tasks completed across the run: <count>
Future Ideas logged (not built, out of scope): <count, or none>

No further work will be queued. This loop is now finished.
```

## SESSION SUMMARY — print at the end of every run that does NOT end in PROJECT COMPLETE

```
SESSION SUMMARY
Completed this run: <ids + one-liners>
Checklist status: <n> of <total> items checked
Remaining by phase: P0: n · P1: n · P2: n · P3: n
Blockers:           <id: question> (or none)
Recommendation:     <one line — what the owner should tackle or decide next>
```
