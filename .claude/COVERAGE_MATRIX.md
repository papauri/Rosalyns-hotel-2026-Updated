# COVERAGE MATRIX — Rosalyn's Hotel 2026

Owned by `build-planner` (status column) and `codebase-scout` (mapped column).
One row per core functional domain in `.claude/CORE_SYSTEM_BRIEF.md`. This is how the build
loop guarantees it touches **every aspect of the system**, not just the loud parts.

Status values: `unswept` · `mapped` (SYSTEM_MAP.md section exists) · `partial` (some tasks
done, gaps remain) · `swept` (all checklist items for this domain done + QA-passed).

| # | Domain | Mapped | Status | Last touched | Open gaps |
|---|--------|--------|--------|--------------|-----------|
| 1 | Booking engine | yes | partial | 2026-07-15 | — |
| 2 | Reservations & front desk | yes | partial | 2026-07-15 | — |
| 3 | Rooms | yes | partial | 2026-07-14 | — |
| 4 | POS & F&B / KDS | yes | partial | 2026-07-15 | — |
| 5 | Stock & procurement | yes | partial | 2026-07-14 | procurement DDL not yet applied |
| 6 | Finance & accounting | yes | partial | 2026-07-15 | — |
| 7 | Gym | yes | partial | 2026-07-14 | — |
| 8 | Conference & events | yes | partial | 2026-07-14 | — |
| 9 | Guest communication | yes | partial | 2026-07-15 | — |
| 10 | Content & marketing | yes | partial | 2026-07-14 | — |
| 11 | Admin platform (auth/perms/users/logs/backups) | yes | partial | 2026-07-15 | — |
| 12 | JSON API | yes | partial | 2026-07-14 | — |
| 13 | Platform / PWA / performance | yes | partial | 2026-07-15 | — |
| 14 | Safety net (tests, migrations) | yes | partial | 2026-07-15 | — |

Update rules: scout sets **Mapped**; planner sets **Status**, **Last touched** and
**Open gaps** when a task's QA gate passes. A domain only reaches `swept` when every
PROJECT COMPLETE WHEN checklist line naming it is `[x]`.
