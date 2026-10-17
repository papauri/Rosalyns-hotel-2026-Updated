# Department agents — shared operating rules

Every `dept-*-lead` and `ui-lead` reads this file after
`.claude/CORE_SYSTEM_BRIEF.md`.

## The structure

| Agent | Department (CORE_SYSTEM_BRIEF domains) | Owns |
|---|---|---|
| `dept-rooms-lead` | Booking engine, reservations & front desk, rooms (1, 2, 3) | bookings, room assignment, joined rooms, calendar, blocked dates, housekeeping, maintenance, individual rooms |
| `dept-restaurant-lead` | POS & F&B (4) | POS till, tabs, KDS/BDS/CDS, Orders & Tabs, deals, room service, tables, station chat |
| `dept-accounting-lead` | Finance & accounting (6) | payments, folios, invoices, receipts, credit notes, refunds, VAT/levy, shift close, end of day, POS accounting, reports |
| `dept-stock-lead` | Stock & procurement (5) | ingredients, recipes, stock deduction/restore, FIFO batches, counts, wastage, receiving, suppliers, POs, buying |
| `ui-lead` | Cross-system UI | one visual language across every department; reviews UI requests from the leads |

Each lead is responsible for its department end to end: it audits, fixes, tests, and is the
one the orchestrator asks when something in that department breaks. Leads are peers: when a
fix crosses a boundary (e.g. a POS change that moves money), the owning lead must review it.

## Two modes (the brief says which)

- **AUDIT** — read-only. Produce findings; edit nothing.
- **FIX** — apply the findings you are given (or your own, if the brief says so), test, report.

## Finding format (AUDIT output, one line each, most severe first)

`F<n> | <critical|high|medium|low> | <file>:<line> | <what is wrong> | <concrete wrong outcome> | <proposed fix> | <owner: self|other-dept-lead|BLOCKED>`

Only real defects with a concrete wrong outcome. No style nits, no "consider". Money and
stock semantics that need an owner decision are `BLOCKED:` per CORE_SYSTEM_BRIEF's
escalation rule — settled owner decisions are in the memory file
`accounting-decisions-2026-10` (the orchestrator pastes the relevant ones into your brief);
never re-decide those.

## Talking to the UI lead

Leads do not restyle. Anything visual (layout, spacing, button alignment, modal sizing,
consistency with other screens) goes in your report as:

`UI_REQUEST | <file> | <screen/element> | <what is wrong for the user> | <desired outcome>`

The orchestrator hands every UI_REQUEST to `ui-lead`, which answers with `UI_DECISION` lines
(the shared pattern to use) and applies them. If you need a UI decision before you can
finish, say `NEEDS_UI_DECISION: <question>` and carry on with the rest.

## Rails that apply to every department agent

- Both hotels, always: Rosalyn's (`C:\Users\john-paul.chirwa\OneDrive\MSP\Rosalyns-hotel-2026`)
  and Liwonde (`C:\Users\john-paul.chirwa\OneDrive\MSP\Liwonde_Sun_Hotel_2026`) share all
  admin/api/includes/config/scripts code. Work in Rosalyn's; the orchestrator ports to
  Liwonde unless your brief says you port.
- The database is LIVE and shared. Test only inside transactions you roll back, or with
  `scripts/tests/page_harness.php` (runs an admin page POST as admin, rolled back).
  Never leave rows behind.
- Never run git. Never edit `.env`. No DDL (schema changes are owner decisions).
- `php -l` every changed PHP file; `node --check` every changed inline script (extract it).
- Run `php scripts/run_tests.php` at the end of a FIX pass (never in parallel with another
  agent's run — the brief tells you if you may).
- Admin SPA: `admin/js/admin-spa.js` swaps only `#rh-admin-page`; modals printed after
  `includes/admin-footer.php` vanish after in-place navigation and inline `<head>` scripts are
  not re-run. Guest-site navigation keeps only `<main>`.
- Output: files changed (paths only) · ≤6-line outcome · findings/UI_REQUEST/BLOCKED lines ·
  lint and test results. No code blocks.
