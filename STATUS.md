# STATUS: Rosalyn's Beach Hotel 2026 (hotel PMS / HMS)

**Read this first.** Any agent (Claude, Cline, another provider) should be able to continue from this file alone. Keep it
current during work. Last updated: **2026-11-11**.

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

## Shipped 2026-11-10: "View only" permission and view-only free trials
Another session also works in this folder: always stage files by name. Files:
- `includes/view-only.php` (new)
- `scripts/tests/view_only.php` (new, 32 pure tests)
- `includes/licence-guard.php`: `rh_view_only_guard()` runs after the staff-session guard; `rh_licence_write_allowed()`
  takes an optional allowlist.
- `admin/includes/admin-header.php`: banner and greying.
- `admin/includes/permissions.php`: the new permission `view_only`, listed last, in no role's defaults.

**What it does:**
- A view-only person can open and read everything, but every change is refused by the server. Change buttons are greyed
  out by a small script.
- Everyone is view only while the licence panel has set the business's free trial to view only (`trial_view_only` in the
  signed answer). Otherwise only people with the `view_only` permission explicitly ticked are view only.
- **Inert until switched on:** no role has it, and Rosalyn's is not on a trial, so the panel switch does nothing here.
- Still to do: the Assistant-lead check, and a staff-guide page in `docs/guides/` (no guide yet).

## Shipped 2026-11-10: view-only trial fix, after the owner's report
This is the same change as in Liwonde (see Liwonde's `STATUS.md`). The owner found that Export and New booking could
still be clicked on Liwonde's view-only trial. Files: `includes/view-only.php` and `scripts/tests/view_only.php`
(80 pure tests).
- During a view-only trial, exports and downloads are now refused by the server.
- The greying now reaches page headers, every link, and icon-only buttons.
- POS and KDS still have no banner or greying, but the server refuses every change there.
- It changes nothing at Rosalyn's until a view-only trial is switched on. Pushed with the owner's OK.

## Shipped: the Assistant set up from the licence panel (owner 2026-11-11)
The owner sells the Assistant as a package with the HMS. The licence panel now holds each hotel's AI provider key and
models, tests them there, and sends a tested set-up (panel `public/ai.php`, see `../msp-control-panel/STATUS.md`).
The same change is in both hotels (identical files):
- `includes/licence-ai.php` (new): runs a staff command of kind `ai_setup` from the signed licence answer. The AI key in
  the answer is encrypted for this site only (key derived from its licence key). The hotel tests the key and every chosen
  model ITSELF first and changes nothing if that fails; then saves the key the Assistant's usual way (`rh_ai_store_key`,
  needs `APP_ENCRYPTION_SALT`), refreshes the model list, gives the chosen models their roles (`rh_ai_save_selection`),
  sets the monthly aim and, if asked, switches the Assistant on. Activity log: "by the system provider (licence panel)".
- `includes/licence-staff.php`: accepts `ai_setup` (it has no username); the staff report carries an `ai` block (on/off,
  provider, key's last 4, models, monthly aim, this month's usage). Never the key.
- `scripts/tests/licence_ai.php` (new, 30 pure tests; `licence_staff.php` still 53/53).
- Admins here may still change the Assistant in Assistant Settings; the panel never re-applies anything until the owner
  sends a new set-up. No schema change.
- Pushed with the owner's OK (both hotels first, then the panel). Not yet run against a real provider: the first
  real send from the panel is that test.

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

## Resolved 2026-11-10: the staff list now reaches the panel
- At first the staff report never reached the panel.
- It arrived (13 staff) at 08:57 DB time, once the follow-up commit that sends only real permission changes was live.
- The cause was very likely the report's size: the Users page stores one row per permission per user.
- If it stops again, the panel's HMS staff page shows the hotel's own `staff_error` in red.
