---
name: build-loop
description: Autonomous build cycle for Rosalyn's Hotel — planner batches the next tasks from BUILD_PLAN.md, up to two specialists build in parallel, ui-designer polishes UI work, qa-auditor gates, then the loop advances by itself. Terminal output is a one-line ticker per task plus one final summary — never code. Use when the user says /build-loop or asks to continue the build.
---

# /build-loop — autonomous build cycle

Run continuously. Do NOT stop between tasks, do NOT ask the owner anything that an
`ASSUMPTION:` can cover, and do NOT print code.

## Step 0 — once per run

Read `.claude/CORE_SYSTEM_BRIEF.md` (the whole system in one page: 14 core domains, users,
conventions, rails). Every agent you dispatch reads it too — never re-explain the system
inside a brief; cite the domain number and the SYSTEM_MAP.md section instead.

## The cycle

1. **Plan a batch** — dispatch `build-planner` (opus). It returns **3–5 fully-specified
   tasks** with exact paths, acceptance criteria, specialist, polish-needed flag, QA model,
   and which two are parallel-safe. If a needed area is unmapped, the batch's first item is
   a `codebase-scout` (haiku) mapping run. A brief that says "explore" goes back to the
   planner once, then the task is parked as `failed-twice`.
2. **Build** — dispatch the named specialist (`backend-specialist` / `frontend-specialist`,
   sonnet) with the brief **verbatim**. Run the two parallel-safe tasks concurrently when
   their file sets are disjoint; never more than 2 specialists at once.
3. **Polish** — only if the task changed UI files: `ui-designer` (sonnet), scoped to exactly
   the files the specialist reported touching.
4. **Gate** — `qa-auditor` with the acceptance criteria + changed-file list. haiku for
   lint/format-only gates, sonnet for logic/security/money gates.
   - PASS → planner ticks the checklist item, updates `.claude/COVERAGE_MATRIX.md`, moves
     the task to Completed with date + verdict.
   - FAIL → send the FIX LIST to the same specialist for ONE retry, re-gate.
     Second FAIL → mark `failed-twice`, record why, continue with the next task.
5. **Ticker** — print exactly ONE line per task to the terminal, nothing else:
   `<task id> · <domain> · <PASS|FAIL|PARKED> · <≤8-word outcome>`
6. **Advance immediately** — pull the next task in the batch; when the batch empties, run
   step 1 again. Never end a run just because a task finished.

## Terminal output discipline (the owner's standing instruction)

- **No code, ever** — no code blocks, no diffs, no snippets, no config dumps, in your output
  or relayed from a subagent. Subagent reports are already format-constrained; if one
  returns code, summarise it in one line and drop the rest.
- **No narration** — no "I'm now going to…", no per-file explanations, no repeating briefs.
- Between tasks: the one-line ticker only.
- At the end of the run: exactly one SESSION SUMMARY (or PROJECT COMPLETE report).

## Cost tracking (silent — never printed mid-run)

`.claude/COST_LOG.md` is the ledger; `.claude/scripts/gen-dashboard.js` renders it. You own
it; agents don't log their own cost.

1. Before each dispatch, estimate `chars(prompt) ÷ 4` input + model overhead (~3000 opus /
   ~2000 sonnet / ~1000 haiku) + output budget (planning ≈3000, build ≈4000, polish ≈2000,
   QA ≈1500). Append the row: timestamp, task ID, agent, model, est in/out/total, tier;
   Actual/Accuracy/Flag as `—`.
2. **High-cost dispatches proceed automatically.** If an estimate exceeds 35,000 tokens, do
   not stop the loop — flag the row `HIGH-COST` and dispatch anyway. Instead of asking, split
   the task in half via the planner when it is splittable. Cost is reported once, in the
   SESSION SUMMARY.
3. After each agent's task notification, fill Actual Tokens from `subagent_tokens` and
   Accuracy = `round(actual/estimate × 100)%`.
4. Run `node .claude/scripts/gen-dashboard.js` every 3 completed tasks and at end of run.

## Cost & safety rails (non-negotiable)

- `/compact` after every 3 completed tasks; `/clear` when switching phases.
- Every brief carries exact file/line scope — no open-ended "explore".
- Model tiers: haiku read-only/lint/mapping · sonnet build/polish/logic-QA · opus planner
  only · never opus/Fable for routine execution.
- Max 2 specialists concurrent.
- NEVER commit or push. NEVER destructive SQL. NEVER edit `.env`. NEVER delete files.
- Non-blocking ambiguity → best assumption, logged as `ASSUMPTION:` in BUILD_PLAN.md.

## Escalation — the one thing you DO ask about

Autonomy never extends to redesigning the system. The **Escalation rule** in
CORE_SYSTEM_BRIEF.md is authoritative: money semantics · booking/availability rules ·
auth/permissions/security posture · non-additive schema changes · guest-visible booking-flow
changes · switching on live guest messaging · brand/design-system changes · deleting or
removing anything · new cost or dependency. Agents never ask the owner directly; they return
`BLOCKED:` lines and keep building everything else.

How you handle them:

1. Collect `BLOCKED:` lines into BUILD_PLAN.md as they arrive. **Keep the loop running** —
   never idle waiting on one.
2. Surface them **batched**, via a single AskUserQuestion, at the first of: the batch
   emptying, 3 blockers accumulating, or the end of the run. Do not drip-feed one question
   per task.
3. Each question must be precise and decidable in isolation: the exact decision, 2–3 concrete
   options with a one-line consequence each, and your recommendation first. Never
   "how should I handle X?".
4. On an answer: record it in BUILD_PLAN.md under the task as `OWNER DECISION <date>:`,
   unblock, and dispatch. If the owner defers, the item stays `blocked:` and the loop
   continues without it.
5. If a QA gate returns `UNSANCTIONED`, treat it as a rail violation: the specialist reverts
   that decision on its retry, and the decision becomes a `BLOCKED:` line.

## Fixed end goal — not open-ended

`.claude/BUILD_PLAN.md`'s **PROJECT COMPLETE WHEN** checklist is the entire approved scope.
Anything discovered that isn't on it goes under "Future Ideas (not in scope)" — never queued.
When the checklist is complete but domains in COVERAGE_MATRIX.md are still `partial`, the
planner may **propose** a new owner-approval round; it does not build it unapproved.

## Stop conditions

Stop entirely and print the PROJECT COMPLETE report when every checklist item is `[x]`.
Do not then go looking for more work.

Also pause and report on: (1) every remaining unchecked item is `blocked:`, (2) a task failed
twice with nothing else queued, (3) a safety-rail violation occurred.

## PROJECT COMPLETE report — only when every checklist item is `[x]`, then halt

```
PROJECT COMPLETE
Checklist: all <n> items [x]
Domains swept: <n>/14
Tasks completed this run: <count>
Future Ideas logged (not built): <count or none>
Est. cost this run: <tokens>
```

## SESSION SUMMARY — the only end-of-run output otherwise

```
SESSION SUMMARY
Goal:             <the checklist round being closed, one line>
Completed:        <task id — one-line outcome> (one line each)
Parked/failed:    <id — reason> (or none)
Checklist:        <n>/<total> items checked · Domains swept: <n>/14
Remaining:        P0: n · P1: n · P2: n · P3: n
Blockers:         <id: exact question for the owner> (or none)
Est. cost:        <tokens>
Recommendation:   <one line>
```
