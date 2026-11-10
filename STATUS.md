# STATUS: Rosalyn's Beach Hotel 2026 (hotel PMS / HMS)

**Read this first.** Any agent (Claude, Cline, another provider) should be able to continue from this file alone. Keep it
current during work. Last updated: **2026-11-10**.

This is the original platform; `../Liwonde_Sun_Hotel_2026` is its fork. Most fixes go into both repos with the same
commit message.

Deeper trackers:
- `.claude/TASKS.md`: the live task tracker. Read it when a session resumes.
- `CLAUDE.md`
- `.claude/CLINE_HANDOFF.md`
- `.claude/BUILD_PLAN.md`

## Hard rules
- **ROSALYN'S IS LIVE**, with real guests and money.
  - The local `.env` points at the live database.
  - Run only pure (no database) tests and `php -l`.
  - Never run `scripts/tests/licence_client.php`: it rewrites the `licence_*` rows in `site_settings`.
  - Never edit `.env`.
  - The schema is locked: any DDL is the owner's decision.
- `.claude/TASKS.md` has uncommitted edits from another session. Do not stage them with unrelated work.

## Live now
The site runs `origin/main` at `9998b7c`.

## In progress: licence modules + staff managed from the licence panel (owner 2026-11-10)
This is the same change as in Liwonde (see Liwonde's `STATUS.md` for the full file list). It was shipped on 2026-11-10
after an independent review; its fixes are included.

New files:
- `includes/licence-staff.php`
- `scripts/tests/licence_staff.php`: 50 pure tests pass.

Edits:
- `includes/licence.php`
- `includes/licence-guard.php`
- `config/database.php`
- `admin/admin-init.php`
- `admin/includes/permissions.php`
- `includes/ai/ai-core.php`
- `scripts/licence-check.php`
- `admin/licence.php`
- `admin/includes/staff-invite-lib.php`

**Effect at Rosalyn's once pushed:** its licence lists every module except **Gym**. Gym therefore switches off for all
staff, administrators included. The owner approved this on 2026-11-10. To keep Gym, tick it on the panel's business page
for Rosalyn's (tenant 1) before the push.

Next steps:
1. After the push, the panel's `staff.php?id=1` shows the staff after the next check-in.
