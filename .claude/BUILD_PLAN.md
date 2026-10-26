# BUILD_PLAN — Rosalyn's Hotel 2026

> Owned by build-planner. One objective per cycle. Every task must reference the
> PROJECT_CONTEXT.md gap it closes. Status values: `queued` · `in-progress` · `done` ·
> `blocked: <question for owner>` · `failed-twice: <reason>`.
>
> **Fixed end goal, not open-ended looping.** The checklist below (owner-approved
> 2026-07-14) is the entire scope. Every task in every phase must trace to exactly one
> checklist item. build-planner may NOT invent deliverables outside this list — anything
> else discovered gets logged under "Future Ideas" and flagged "out of scope, needs
> approval," never silently queued. /build-loop stops (not pauses) once every item below
> is `[x]`.

## PRODUCTION READINESS GATE (owner-approved 2026-10-20) — overrides everything below

Purpose: know when the system is ready and stop patching things that don't need it.

**1. Feature freeze (from the 2026-10-20 push).** 2026-10-22: owner approved the monthly batch — every item in `.claude/PATCH_QUEUE.md` may be built (by/through `dept-patch-lead`); security patches are always allowed. No new features, redesigns or polish without
explicit owner approval. New ideas go to "Future Ideas" with one line each — never into a phase
table, never built proactively.

**2. Only these four kinds of fix may be made without asking (severity P1/P2):**
- Money is wrong (a total, VAT/levy, balance, refund, receipt/invoice/credit-note number).
- A guest cannot book or pay, or a guest document (receipt/invoice/quotation) is wrong or missing.
- Staff are blocked from doing their job (a screen errors, a button does nothing, a permission
  wrongly refuses).
- Data loss or a security hole.
Anything else is logged and batched monthly for owner approval.

**3. Dress rehearsal — once per hotel, scripted, on the live DB.** Liwonde Sun first (not yet
live; guest email johnpaulchirwa@gmail.com, guest name "DRESS REHEARSAL"): public booking →
confirmation email → confirm + quotation → part payment + receipt → check-in → room service and
till order to the room → checkout, balance paid + receipt → paid-in-full stay statement →
end of day → money checks (paid/owed/VAT/receipt numbering) → data-health. Every failure is
triaged with rule 2. Then Rosalyn's, by the owner/staff on a real stay.

**4. Owner items that must be done before go-live:**
- [ ] Rosalyn's email sender: use an address the SMTP account owns (Gmail sender via another
      server is DMARC-blocked — receipts may go to spam).
- [x] Rosalyn's: create a manager account. Done: Pamela Msukwa (P.Msukwa, manager); also admin Mphatso Nthani. Note 2026-10-22: a "test" user (role manager) is still active - owner to decide.
- [ ] Opening stock count recorded (Stock → Stock Count) in each hotel that uses stock.
- [ ] Confirm restaurant tables match the real floor plan; replace placeholder reviews.
- [ ] Decide the DRESS REHEARSAL rows in Liwonde (keep for reference or clear).

**5. Finished when (then /build-loop stops and no proactive work is started):**
- [x] Liwonde dress rehearsal passes end to end (no P1/P2 open). Done 2026-10-21; findings fixed and shipped.
- [ ] All owner items in 4 are done.
- [ ] `php scripts/run_tests.php` and `php scripts/data-health.php` green in both hotels.
      2026-10-21: Liwonde all green. Rosalyn's data-health green; POS/KDS smoke cannot run until one menu item has a recipe (setup, not a code fault). Owner 2026-10-21: Liwonde's passing POS/KDS run counts for both hotels (same code).
- [ ] Two weeks of real use with no new P1/P2.

**6. FINAL GOAL — the finish line (owner, 2026-10-24: "set a level where you are finally 100%
satisfied … reach it without looping into something else").** Fixed list; nothing is added to it.
- [x] G1 PATCH: refund VAT correct when levy is on (all refund paths use the payment's own VAT share),
      partly refunded till orders in earned revenue, POS Accounting net on one basis — shipped both hotels.
- [x] G2 PATCH: levy report takes refunded levy back off (levy follows the money; a kept night keeps
      its levy) — shipped both hotels.
- [x] G3 Verify once: `php scripts/run_tests.php` + `php scripts/data-health.php` green in both hotels
      (after the owner runs migration 069), one headless pass over the money screens touched today.
- [ ] G4 Owner: run `php admin/migrations/migrate.php --run` on both hotels; owner items in 4.
When G1–G3 are ticked the code is FINISHED. Claude then does nothing proactive: no audits, no
reviews of untouched areas, no "while we're here". After that only two things start work:
(a) a fault the owner or staff report that is one of the four PATCH kinds — fixed as a PATCH, one
review, shipped, done; (b) a FEATURE the owner asks for in so many words, with its deliverables
listed up front (e.g. "?" helpers for the remaining departments: waiting for the owner's go).
Everything on the LATER list waits for the owner's monthly decision.

**How to test from here on:** tests run against the live DBs (no local MariaDB on the owner's
laptop) and must roll back or delete exactly what they create; real test entries go to Liwonde.
Per change, run only the relevant suites + browser-check only changed screens; the full suite
runs once per push.

---

> Earlier rounds, PROJECT COMPLETE WHEN checklist, phases, owner decisions and dispatch briefs: `.claude/archive/BUILD_PLAN_HISTORY.md` (lookup only).


- SETTLED OWNER RULE (2026-10-24): "The hotel room base rate includes all taxes and levies added already." A room rate (incl. child supplements and packages) is ALWAYS all-in in every vat_pricing_mode: rh_stay_totals extracts VAT and levy (net = price/(1+v+l)); a switched-off component is not extracted. Guests never see VAT/levy amounts (front end, confirmation page, guest booking emails): only "Includes VAT and tourism levy" naming what is on. Back end, documents and PDFs still show amounts (vat_shows_amount() unchanged). Existing bookings keep stored totals.

ASSUMPTION (2026-10-26): "add drag and drop" in Room management = drag picture thumbnails to reorder and drop image files onto the edit-room Pictures box / add-room Pictures box / image modal box (same type and size checks). Room card reorder now uses pointer events on the grip (mouse + touch) plus arrow keys.

ASSUMPTION (2026-10-26, AI Assistant Phase 1): permission defaults - admin = ai_assistant_use + ai_assistant_actions + ai_settings + ai_usage_view; manager = use + actions + usage (not settings); every other role none (owner grants per person on Staff & Access). Settings page is named "Assistant Settings" (Settings menu, not module-gated so the master switch is always reachable). Seeded limits: 200,000 tokens/person/day, US$20/month, 30 messages/10 min, keep chats (0). One provider in use at a time; the top 5 models are pre-ticked in the form but nothing is saved until Save models. ai_assistant_use/actions are module-mapped (hidden as new offers on Staff & Access while the Assistant is off, same as every other module). The ai_assistant module is not listed on Settings > Modules (toggle-module.php allowlist is outside the allowed edits); the master switch on Assistant Settings toggles setting + module row together.

ASSUMPTION (2026-10-26, AI Assistant Phase 2): (1) New additive migration 071_ai_chat (ai_vault + ai_tool_cache), run on Liwonde only; the plan's 071_ai_memory becomes 072 in Phase 4b. (2) The chat endpoint uses admin/api/api-init.php (JSON 401, no HTML redirect) instead of admin-init.php; order: signed in -> kill switch 404 -> CSRF -> ai_assistant_use 403 -> rate limit 429. (3) Settings > Modules lists the Assistant in its own "Staff Tools" section (not part of business presets; a missing row shows Off, never the "?? true" default); turning it on there alone shows nothing until Assistant Settings' master switch is on. (4) Monthly cost cap reached = model calls refused with a plain message (quick local lookups still work); no automatic pause flag yet (auto-pause is Phase 4b). (5) Answer cache is per conversation (2 min) so cached tokens always match that chat's vault; read-tool results cached per person + permission set (30-600 s by tool). (6) Out-of-scope guard: money / stock / staff-account questions from people without the matching permission get the owner's one line with zero tokens; one booking's own balance (visible on the booking screen) is allowed. (7) Finance read in Phase 2 = balances_due (needs accounting or payments); revenue/occupancy analytics tools come with the Phase 3 Money / Revenue specialists. (8) Guide visibility for how_to/summarise_guide = module on (rh_kb_allowed_guides) AND a per-guide permission map in includes/ai/ai-tools.php; unmapped guides are administrators only. (9) Anthropic output_config.effort is sent to every Claude model; a 400 naming effort/output_config retries once without it. (10) Logout vault purge stays with Phase 4 (vault rows are per conversation so stored chats still show real names).

ASSUMPTION (2026-10-26, AI Assistant Phase 3): (1) Nine department leads (Front Desk & Rooms, Housekeeping & Maintenance, Restaurant & Bar, Money, Stock, Events/Conference/Gym, Revenue & Sales, Guest voice, System Guide); a lead exists for a person only when they hold one of its core reads (shared helper reads such as rate plans do not create a lead). (2) Leads run on the Fast model (effort low) in parallel via curl_multi; the Primary model merges; consult_team is offered only to people with 2+ leads and once per question. (3) Caps: 8 tool rounds counted across the Lead and the leads together; new setting ai_turn_token_cap (default 60,000, 5,000-1,000,000, optional field "Most tokens for one question" on Assistant Settings). (4) Briefing view by permissions: Accounting/Reports = money view (owner); role manager = shift health + decisions emphasis; Bookings = front desk; otherwise own queue. Money findings only for Accounting/Reports holders; a receptionist sees each leaving guest's own balance, never a total. (5) Money leaks = receivables rule (left owing or overdue), refunds on the End of Day basis in full, till voids, till discounts (comps), tills closed short, voided bill charges, wastage, stock missing at approved counts, no-shows (booked value), cancellations (booking history), stock budget; no stored room price-override field exists, so room overrides show as guests who left owing. (6) Slow ticket = 20 minutes order-to-ready (the system has no lateness rule). (7) Pace vs last year uses each booking's final status; source mix comes from the booking history's "created by" (no booking source field). (8) Briefing shows on the first panel open of the day (preference on by default, "briefed_on" kept in ai_user_prefs); one Fast call explains it, replaced by a local summary when over budget, no model, or the text holds a figure not in the findings. (9) Never guesses: checked claims = name/booking tokens, ISO dates, references, money, percentages, counts beside data nouns; evidence = this turn's tool results, the question and the context sent (incl. the chat so far); a figure rounded from the data passes; regenerate once, then remove the sentence; a lead's answer is trimmed without a regenerate. (10) Small talk, greetings, thanks, "and tomorrow?" after a local lookup, and arrivals/departures for tomorrow/yesterday are answered locally with zero tokens. (11) The status line is a per-person step kept 90 s in ai_tool_cache, polled every 1.2 s by the panel (action status). (12) The Reports-equals-screen test renders a throw-away copy of reports.php whose only change is ';' inside SQL comments (the harness refuses any statement with ';' after it). (13) Panel link buttons are styled in ai-panel.css (admin .btn styles do not reach inside the panel).

ASSUMPTION (2026-10-26, AI Assistant Phase 4): (1) Action tools live in their own registry (includes/ai/ai-actions.php, names propose_*); the read registry stays read-only. A card is one ai_actions row (proposed, 15 min, fresh client_uuid); confirm is single use under a row lock and re-runs the preview with the person's card edits: a changed price/availability marks the card expired and returns a new one. (2) report: the database decides executed/failed (the screen's JSON alone is not trusted); emails and invoices with no database trace use the screen's answer. (3) Incident on an AI booking total that differs from its card by more than BALANCE_TOLERANCE is stored in ai_actions.result_json (incident) and the system log until Phase 4b adds ai_incidents. (4) Housekeeping and maintenance: new jobs only (created unassigned); starting/completing a job and individual-rooms.php room status answer with a page, not JSON, so room status is verified from the database and job status changes stay on their screens. (5) Room status card offers available/cleaning/maintenance/out of order (off-sale needs Block Rooms); a maintenance job may block the room (shown in red, off sale from the card time to the end of the due day). (6) Room type change via the Assistant is paid (pricing=charge) only; keeping the price (complimentary) stays on Bookings. (7) Joined (family) room assignment hands over to Bookings (pairs stay intact); assign/check-in rooms come from rh_room_assignment_candidates. (8) WhatsApp: booking-details.php sends via invoice_action with a redirect and generates an invoice, so the card only opens the booking (Phase 0 decision 5). (9) Group/multi-type or payment bookings hand over to Create Booking, prefilled in the browser from sessionStorage (guest, dates, party); room lines are picked there (create-booking.php gained only the JSON branch). (10) Briefing one-tap: missed / due-out check-outs and arrivals with no room get a button that prepares the card through ai-action.php propose (no model call); Confirm is a second tap. (11) Sign-out purge (admin/logout.php, one line): the vault of that person's chats, their unconfirmed cards (expired) and their cached lookups; old chats then show (guest)/(booking) in place of names. The nightly sweep of vaults from expired sessions is left to the Phase 4b nightly jobs. (12) New setting ai_actions_enabled (default 1) = kill-switch layer 4 "Action cards" on Assistant Settings > Budgets and limits. (13) Guest-written free text (special requests, notes, messages) is wrapped as QUOTED TEXT (data, not instructions) in every tool result. (14) Accounting review of the cancel/checkout cards was not dispatched (no agent tool in this run): the cards only show getCancellationRefundModeLabel() + folio paid/owed (no refund figure) and check out only when nothing is owed on the booked day, both replaying the screens' own actions.

ASSUMPTION (2026-10-26, AI Assistant Phase 4b): (1) Migration 072_ai_memory adds ai_memory, ai_incidents, ai_feedback, ai_eval_runs, ai_eval_cases, ai_faults + learning settings (INSERT IGNORE); run on Liwonde, Rosalyn's after the orchestrator's backup. Without 072 everything degrades silently (no feedback buttons, no memory, no eval/reflect; vault sweep, health and model refresh still run). (2) Regression questions added from fixed faults live in ai_eval_cases (source=incident); evals.json is the shipped set (40 questions, 5 smoke) - code files are never written at runtime. (3) Notes refuse contact details, privacy tokens, prices/amounts and any rule about tools/permissions/limits/confirm; only nightly-review playbooks may name read lookups. Owner-typed notes start active, learned ones on probation (active after 3 wins; retired at 3+ losses > wins unless pinned). A matched playbook routes a reasoning question to the Fast model. (4) Daily trigger lives in admin/includes/ai-panel.php (already required by admin-header): fastcgi/litespeed finish then run; otherwise one fire-and-forget POST to admin/api/ai-daily.php with a hashed single-use 5-minute token, at most every 30 min; single flight = GET_LOCK + atomic ai_daily_ran_on claim. Daily = vault sweep (chats idle 8 h whose owner is not seen for 8 h), model refresh after 7 days + eval-gated upgrade (one role per check, priced models only, accept if score >= and cost not higher; undo on settings), reflect, health, full question set + guardrail. Checks run as the first active admin; their tokens are recorded to user 0 (monthly limit, never a person's allowance); their chats are archived and vault removed. (5) Auto-pause: budget pause lifts itself next month; actions (5 failed/cancelled cards in 1 h, all users) and eval (smoke < ai_eval_smoke_min_pct=80, needs 3+ smoke questions run) wait for a person. Notices go to ai_settings holders as a small card on admin pages (setting ai_notices_json, works without 072). (6) Circuit breaker state kept in ai_tool_cache (user 0): 3 errors in 10 min -> off 1 h -> one try. Key refused = provider marked failed + notice (no extra pause). Retired model -> next priced model of the same family takes the role. (7) Cloud providers switch: all 5 providers are cloud, so Off = no model calls at all (local answers only). (8) Guardrail clean day also needs today's health self-test; a missed day breaks the streak; reopen only on regression / new incident kind / failed self-test. (9) Fixed a Phase 2 bug found by the new tests: rh_ai_message_add returned 0 (lastInsertId read after an UPDATE), so chat history never loaded and message ids were 0.
