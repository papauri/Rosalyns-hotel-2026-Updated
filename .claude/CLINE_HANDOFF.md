# AI Assistant — handoff for Cline (backup builder)

Use this only if Claude Code runs out of usage. Read this file first, then only the plan sections named
below (offset reads — never read big files whole). Keep `.claude/TASKS.md` "Open" up to date as you go.

## Where things stand (update this block after every ship)
- Phases 0, 1, 2, 3, 4, 4b shipped to both hotels (4b = learning loop + self-healing, QA passed,
  full suites green). Check `git log --oneline -3` in both repos: if the top commit is "AI Assistant Phase 4b",
  4b is done and you start at **Phase 4c + 5 (one combined phase)**.
- Rosalyn's: migrations 070, 071 applied; 072 applied at the 4b ship. Assistant is **ON** at Rosalyn's
  (owner order); Liwonde is the test hotel, Assistant OFF there except during browser checks.
- Two repos, same code (only the guide's hotel name differs):
  - Rosalyn's `C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026` (orchestration docs live here)
  - Liwonde `C:\Users\john-paul.chirwa\OneDrive\MSP\Liwonde_Sun_Hotel_2026` (build and test here first)

## What to build next
`.claude/AI_ASSISTANT_PLAN.md`:
- "Owner direction" (line ~103–200) — governs everything: assistant-first, cheapest per answer, swarm of
  department leads, knows the user's permissions + polite one-line decline, guides expert, NEVER guesses
  figures, natural conversation within the hotel, minimalist strong look (no sparkle/wand/robot icons,
  gradients, glows, emoji, "AI/smart/magic" words).
- "Hard limits" (~253) — non-negotiable.
- "Phase 4c" (~477) and "Phase 5" (~487) — the remaining scope. Model refresh cron is already replaced by the
  daily trigger (Phase 4b). Migration for 4c = next free number (check `admin/migrations/`).
- "Verification" (~542) — the test list.
- Decisions already taken: `.claude/BUILD_PLAN.md` lines starting `ASSUMPTION (2026-10-26, AI Assistant`.
  The owner delegated all decisions: pick the safest sensible option, add an `ASSUMPTION (date, AI Assistant
  Phase 4c/5): …` line, never stop to ask.

Existing code to build on: `includes/ai/*.php`, `admin/api/ai-chat.php`, `admin/api/ai-action.php`,
`admin/api/ai-daily.php`, `admin/ai-settings.php`, `admin/includes/ai-panel.php`, `admin/js/ai-panel.js`,
`admin/css/ai-panel.css`, `scripts/ai-*.php`, tests in `scripts/tests/ai_assistant_test.php`.

## Rules (from CLAUDE.md — enforced)
- Vanilla PHP, procedural, prepared statements always, `htmlspecialchars` on output, CSRF on every POST,
  money comparisons via `BALANCE_TOLERANCE`, reuse `includes/` helpers, no Composer packages, no frameworks.
- Never edit `.env` or `config/database.php`. No destructive SQL. Migrations additive + idempotent
  (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`), style of `admin/migrations/064_admin_presence.php`.
- No writes to stock, POS, payments, refunds, invoices, credit notes, EOD/shift, rates/prices, deals. Every
  Assistant write = a card the user confirms, replayed to the existing screen endpoint.
- Never read `vendor/`, `PHPMailer/`, `logs/`, `cache/`, `backups/`, `images/`, `Database/`.
- Tests hit the live databases and roll back — never run two test runs at the same time.
- Guest test email: johnpaulchirwa@gmail.com. Never call real AI providers in tests (fake provider only).

## Ship gate (per phase)
1. Build on Liwonde; run `php admin/migrations/migrate.php --run` there if you added a migration.
2. `php -l` every changed PHP file; `node --check admin/js/ai-panel.js`;
   `php scripts/tests/ai_assistant_test.php` (extend it for every new feature — fake provider, no network).
3. Copy identical files to Rosalyn's; run the same test file there.
4. Update `docs/guides/22-assistant.html` in both hotels; check the help search finds it:
   `php -r 'require "includes/guide-knowledge-base.php"; print_r(rh_kb_search("<question>",3));'`
5. Browser check on Liwonde: `php -S 0.0.0.0:8000` + `node scripts/tests/browser_check.mjs <steps.json>`;
   switch the Assistant on only for the check, then off; delete test rows; stop the server.
6. Independent review of the diff (security, permissions, no business-table writes, no AI-looking UI).
7. Full suite, one hotel at a time: `php scripts/run_tests.php` (Liwonde, then Rosalyn's). A failure with
   "Lock wait timeout" / "server has gone away" = re-run that one test file alone.
8. Rosalyn's: `php scripts/backup_database.php` then `php admin/migrations/migrate.php --run`.
9. Commit in each repo (one commit per phase, message says what and why), `git push origin main`.
   Check the diff for secrets/stray files first. Never force-push.
10. Update the "Where things stand" block above and `.claude/TASKS.md`.

## Finish line
After 4c + 5 ship: report "AI Assistant feature complete — nothing else to patch". The owner's remaining
step is the live-key dress rehearsal on Liwonde (plan "Verification", last bullet).
