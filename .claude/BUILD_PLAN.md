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
