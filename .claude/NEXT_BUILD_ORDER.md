# Next build order (owner-approved 2026-11-14)

## How to run this queue (every session follows this exactly)

The owner starts EVERY session with the same prompt: **"Run the next step of .claude/NEXT_BUILD_ORDER.md"**.

1. Read ONLY this file and the "Open" section of `.claude/TASKS.md`. Do not re-plan; the plans are final.
2. Pick the FIRST row in the queue whose Status is not `SHIPPED`. If it is `DOING`, resume it (check git log + TASKS.md
   for what is already done). Never skip ahead, never run two steps in one session.
3. Set that row to `DOING <date>` here and add a DOING line in TASKS.md.
4. Execute that step's brief (below) exactly. Standing rails apply: ROSALYN'S IS LIVE (no DB tests, scripts or test
   emails there; Liwonde only), DEPARTMENTS.md ship gate, one builder + one review, stop every `php -S`.
5. If an owner decision is genuinely needed, ask it, park the row as `BLOCKED: <question>` and stop. Do not start the
   next step.
   Steps 1-5 also add their entry to `.claude/GUIDES_AI_SYNC.md` in the ship commit (DEPARTMENTS.md rule 5,
   owner 2026-11-14: guides + Assistant always last). Model policy: Opus builds; Fable 5.2 only in the three cases in
   DEPARTMENTS.md (step 4 qualifies for ONE Fable call).
6. When the step has shipped to BOTH hotels: set the row to `SHIPPED <date> <Rosalyn's hash> / <Liwonde hash>`, update
   TASKS.md, commit + push, and END the session with exactly this message to the owner:

   > Step N shipped (<one-line summary>). Please run **/clear**, then paste:
   > **Run the next step of .claude/NEXT_BUILD_ORDER.md**
   > Next up: step N+1 - <its title>.

   If it was the last row, say "Queue complete" instead and give the SESSION SUMMARY.

## Queue

| # | Status | Step | Read | Builder -> gate |
|---|---|---|---|---|
| 0 | SHIPPED 2026-11-14 7a4cadc + dee8489 + 976db55 (other session; its LATER items stay in TASKS.md) | Wait: Hotel Settings change log + settings page order/search shipped (other session) | TASKS.md "DOING 2026-11-14 ... logs lead" | check only |
| 1 | SHIPPED 2026-11-14 e83be44 / 5d72c79 | B1 Guest booking lookup correctness (+ "Booking Received" heading) | GUEST_BOOKING_ALIGNMENT_PLAN.md F1, F5, F8, F11, §3 B1 | dept-guest-site-lead -> dept-accounting-lead + qa-auditor |
| 2 | SHIPPED 2026-11-14 5599ae0 / d1aa02d | B3 History price snapshot on online bookings | GUEST_BOOKING_ALIGNMENT_PLAN.md F4, §3 B3 | dept-rooms-lead -> qa-auditor opus |
| 3 | SHIPPED 2026-11-14 754aba7 / 19fdb11 | F7 Live quote for 2+ joined room pairs | GUEST_BOOKING_ALIGNMENT_PLAN.md F7 | dept-rooms-lead -> qa-auditor opus |
| 4 | SHIPPED 2026-11-14 eaef2d2 / d408fc2 (switch OFF; 085 run at both hotels, Rosalyn's on owner request 2026-11-14) | Cancellation & no-show policies (+ F2 whole-booking guest cancel) | CANCELLATION_POLICY_SPEC.md | one Opus builder -> dept-accounting-lead + qa-auditor opus |
| 5 | SHIPPED 2026-11-14 905aecb / f3bd2e7 | F10 Guest booking emails (rate x nights, rate plan, all room refs) | GUEST_BOOKING_ALIGNMENT_PLAN.md F10 | dept-guest-site-lead -> dept-accounting-lead |
| 6 | SHIPPED 2026-11-14 844f21f / 4a02c11 | Staff guides + help search sync | this file, step 6 brief | guides-kb-lead (haiku) -> haiku review |
| 7 | SHIPPED 2026-11-14 5acf82b / d31e5ab | AI Assistant sync | this file, step 7 brief | dept-ai-lead (Opus) -> opus review |
| 8 | SHIPPED 2026-11-14 0a860b9 / 142aee6 | Booking Details modal on phones + tablets: compact cards, tap a booking to open full details (owner 2026-11-14) | this file, step 8 brief | dept-rooms-lead + ui-lead (Opus) -> qa-auditor + browser check 390 / 768 / 1400 |
| 8a | DOING 2026-11-14 | AI Assistant revisit: stay facts for a named guest ("when did <guest> check out") must be answered, never "the chat was cleared" / "I don't know" (owner 2026-11-14) | this file, step 8a brief | dept-ai-lead (Opus) -> opus review |
| 8b | TODO | Payments: money accurate end to end + Payments page complete (row click opens the payment, History/logs, notes) + invoices, quotations, receipts, credit notes agree (owner 2026-11-14) | this file, step 8b brief | dept-accounting-lead (Opus) audit -> build -> qa-auditor opus + browser check 390 / 768 / 1400 |
| 8c | TODO | Stock review: works as intended, end to end, with the POS (deduct/restore, batches, counts, wastage, receiving, barcodes, suppliers/POs, Data Import). No new features (owner 2026-11-14) | this file, step 8c brief | dept-stock-lead (Opus) audit -> fix -> qa-auditor opus; dept-import-lead + dept-restaurant-lead for their parts |
| 9 | TODO | Other admin modals with the same problem on phones + tablets: check all, fix the affected ones (owner 2026-11-14) | this file, step 9 brief | ui-lead (Opus) -> qa-auditor + browser check 390 / 768 / 1400 |
| 10 | TODO | Bookings list on tablets: compact rows instead of the large cards (owner 2026-11-14) | this file, step 10 brief | ui-lead (Opus) -> qa-auditor + browser check 390 / 768 / 1400 |

**LATER (never pulled by this queue; only when the owner asks):** F3 + F6 + F5 API part (no outside client uses the API,
owner 2026-11-14) · F9 split-booking note into booking_notes · F11 payment line from `payment_policy` · admin
create-booking packages.

Owner decisions behind this order: GUEST_BOOKING_ALIGNMENT_PLAN §4 and CANCELLATION_POLICY_SPEC OWNER-Q 1-3, tier
boundary ("fewer than X days": cancelling exactly 7 days before is free), waive permission (admin + manager default,
grantable to anyone).

## Step briefs

**Step 0 - check only.** Read the "Open" section of TASKS.md and `git log -10`. Has the Hotel Settings work (settings
change log + settings page order/search, logs lead + ui-lead, "DOING 2026-11-14") shipped to BOTH hotels? If yes: mark
step 0 SHIPPED with the hashes and end with the clear-session message. If no: tell the owner what is left, leave step 0
TODO and stop. Build nothing.

**Step 1 - B1 guest lookup.** Read GUEST_BOOKING_ALIGNMENT_PLAN.md §2 F1, F5 (lookup part only), F8, F11 (heading
"Booking Received" for pending ONLY; the payment line is unchanged) and §3 row B1. Owner decisions are in §4; do not
reopen them. Builder: dept-guest-site-lead (Opus); money gate: dept-accounting-lead; then one qa-auditor review. Write
the new test scripts/tests/booking_lookup_group.php as the plan says (refuses Rosalyn's). Tests and browser check
(1400 + 390) at LIWONDE only. Ship to both hotels.

**Step 2 - B3 History price snapshot.** Read GUEST_BOOKING_ALIGNMENT_PLAN.md §2 F4 and §3 row B3. Builder:
dept-rooms-lead (Opus); tell dept-logs-lead what changed; gate: qa-auditor (opus). Touches the booking.php,
includes/mixed-booking.php and api/bookings.php create paths plus includes/booking-timeline.php. Logging only, never
fatal, old bookings untouched. Run suites from the Liwonde root; shared include = full suite once at Liwonde before
ship. Ship to both hotels.

**Step 3 - F7 joined quote.** Read GUEST_BOOKING_ALIGNMENT_PLAN.md §2 F7 only. check-availability.php: build the same
non-overlapping combination queue booking.php uses (:385-:396) and price each room off its own combination. Joined
rooms are a real feature: keep the cross-type availability rules intact. Builder: dept-rooms-lead (Opus), or inline if
under ~10 tool calls; gate: qa-auditor (opus). Browser check of the booking.php summary with a joined type at
Liwonde. Ship to both hotels.

**Step 4 - Cancellation & no-show policies.** Confirm steps 1-2 are SHIPPED. Read CANCELLATION_POLICY_SPEC.md in full.
Every owner question is answered (Q1 online cancel with an "I accept" tick: yes; Q2 auto-release of unpaid: no fee; Q3
pending: no fee; tiers charge at FEWER THAN X days; waive permission admin + manager by default, grantable). It
includes F2 (guest cancels the whole party: only rows under the proven email; company-billed parties see "contact the
hotel") and §4's corrected stamp placements. ONE Opus builder; money gate: dept-accounting-lead; then one qa-auditor
(opus). Model policy case (a): ONE Fable 5.2 spec check of CANCELLATION_POLICY_SPEC.md (effort high, exact files,
one verdict) BEFORE the builder starts. Ship with the switch OFF. Migration 085 runs at LIWONDE only; ask the owner before running it at Rosalyn's,
with the exact command. Ship to both hotels.

**Step 5 - F10 guest emails.** Read GUEST_BOOKING_ALIGNMENT_PLAN.md §2 F10. Add "booked rate x N nights", the rate plan
line and every room reference for groups as an appended block from buildBookingEmailVariables (config/email.php), the
same mechanism step 4 added for `{{cancellation_policy_text}}`. Stored DB templates are live data: never edit them.
Builder: dept-guest-site-lead (Opus); money gate: dept-accounting-lead. No test emails at Rosalyn's; Liwonde test mode
only. Ship to both hotels.

**Step 6 - Staff guides + help search.** (Owner pre-approved this run as part of the queue, 2026-11-14.) Read
`.claude/GUIDES_AI_SYNC.md` (every pending entry, incl. those carried over from the old SYNC QUEUE) to list what changed
for staff. Expected: group documents (per-room invoice,
receipt per room, group charge), kept cancellation fees, Hotel Settings change log + settings page order/search, guest
lookup fixes, History price note on online bookings, cancellation & no-show policies (settings, tiers "fewer than X
days", locked at booking, fee shown before cancel, lower/waive permission, guest whole-booking cancel online), guest
email additions. Agent: guides-kb-lead (haiku). Update ONLY the matching pages in docs/guides/ (expect 07, 13, 14, 19,
faq) in plain staff language, with a worked cancellation-fee example (MWK 100,000 x 3 nights). Verify the KB search
(includes/guide-knowledge-base.php) answers: "how do I cancel with a fee", "can I waive a cancellation fee", "where do
I set the cancellation policy", "who changed this setting". Rosalyn's has no gym: keep gym pages hidden there. No
AI-looking wording. Docs only, no DB. One haiku review. Ship to both hotels and mark the guides part of each entry
done in GUIDES_AI_SYNC.md.

**Step 7 - AI Assistant sync.** (Owner pre-approved as part of the queue.) Only dept-ai-lead (Opus) builds or tests
the Assistant. Read `.claude/GUIDES_AI_SYNC.md` (Assistant part of every pending entry) and CANCELLATION_POLICY_SPEC.md
§1, §3 and §5. Grep includes/ai/
(ai-tools.php, ai-money.php and the read tools) for every place that shows money owed, refunds, cancellations, no-shows,
group totals, booking History or settings. Update only where a shipped change makes an answer wrong or incomplete.
It must answer correctly:
- the cancellation fee for a booking, from its LOCKED policy, with booked rate x nights, the tier and the days before
  arrival (old bookings follow today's rules)
- kept fees and no-show charges in "still owed"
- group totals per room
- who changed a hotel setting and when, old -> new, secrets never shown

Rails stay:
- preview-then-confirm for every action
- permissions respected (`waive_cancellation_fee`)
- never "none/0" without a checked total
- no hard-coded hotel facts
- the Assistant may quote a fee but never cancels, lowers or waives one without the staff member's confirm

Decisions go with it (owner 2026-11-14: "add decisions to the AI Assistant sync ... if any decisions AI have to be
updated"): check the Assistant's decisions (`includes/ai/ai-decisions.php` and anything else that raises a decision or
"needs attention" item) against the same shipped changes - cancellation fees from the locked policy, kept fees and
no-show charges owed, fee lowered/waived, group per-room figures, setting changes - and update only the decisions a
shipped change made wrong or incomplete. Same rails; a decision never acts without the staff member's confirm.

Also in this step (owner 2026-11-14): fix the three known claim-check fails in ai_assistant_test.php at the cause
(never by loosening the check), and ONE live model test at LIWONDE on the cheapest model, at most 10 questions,
hard cap US$0.10 in total (owner: "Don't run tasks that will cost even more than 10 cents overall").

Add eval cases for each new answer. Run only the Assistant suites, at LIWONDE (cheapest top-3 models, minimal live
tests). One opus review. Ship to both hotels and mark the Assistant part of each entry done in GUIDES_AI_SYNC.md.

**Step 8 - Booking Details modal on phones and tablets.** (Owner 2026-11-14, in the owner's words: "fix the booking
details UI modal on mobiles and tabs. The cards don't look great and have to be compact like what we did to the booking
page on mobile a few days ago. We don't need every detail on an individual card, it's taking up unnecessary space. Also
we should be able to click a booking to open the full booking details.")
- Scope: the Booking Details modal (admin) at phone (390) and tablet (768) widths ONLY. Desktop stays as it is.
- Cards become compact, the same pattern as the recent mobile Bookings page work (find that ship in `git log` /
  TASKS.md Shipped and reuse its classes and tokens; no new visual language). Each card shows only the key facts
  (ASSUMPTION unless the owner says otherwise: guest, room, dates + nights, status, balance); the rest moves behind
  the full details view, nothing is removed from the system.
- Tapping a booking (the whole card/row, not only a small button) opens the full Booking Details. Keep keyboard
  access and a visible focus state; action buttons inside a card must not also trigger the open.
- Layout and markup only: no money, booking or permission logic changes. Figures keep "booked rate x N nights".
  Remember the SPA rule: modal markup and scripts must sit inside `#rh-admin-page` / `<main>`.
- Find the files via Grep on `.claude/CODE_INDEX.md` ("booking details", the modal's id) - never read big files whole.
- Builder: dept-rooms-lead with a UI_REQUEST to ui-lead (one shared pattern), Opus. Gate: one qa-auditor review +
  headless browser check at 390, 768 and 1400 at LIWONDE (stop `php -S` after). UI only: no DB tests. Ship to both
  hotels; add the entry to GUIDES_AI_SYNC.md.
- While building, write the compact-modal pattern down as ONE shared `UI_DECISION` (classes, spacing, what a compact
  card shows, tap-to-open) so step 9 can apply it to the other modals without redesigning.
- If "click a booking" turns out to mean a different list than the one the builder finds (e.g. the group/linked
  bookings list inside the modal vs the Bookings page cards), cover both; log it as `ASSUMPTION:`.

**Step 8a - AI Assistant revisit.** (Owner 2026-11-14, after step 8: the Assistant sync "is not going well" - asked
when a guest at Rosalyn's checked out, by first name, the Assistant said the chat was cleared or it did not know.)
Only dept-ai-lead (Opus) builds or tests the Assistant.
- Reproduce at LIWONDE only (the mirror holds the same bookings with made-up names; pick a checked-out guest there and
  ask "when did <first name> check out"). Rosalyn's is LIVE: no tests, no writes, no model calls there.
- Find the cause, not the symptom: why a first-name-only question about a stay fact (checked in / checked out / when,
  by whom) ends in "chat was cleared" or "I don't know" instead of a guest lookup + booking History read. Check the
  router/intent, the guest-name match (infer the obvious match and show the real name; several plausible = one short
  question), the read tool for actual check-in/checkout times (History, app $pdo times, not raw UTC), and what the
  "chat cleared" message is and when it fires.
- Then sweep the same class: for each stay fact staff ask daily (arrived, left, extended, moved room, cancelled, who
  did it and when) add an eval case and fix what fails. Rails unchanged (confirm, permissions, never "none/0"
  unverified, no hard-coded facts, privacy).
- Live model test at LIWONDE only, cheapest model, at most 10 questions, hard cap US$0.10. One opus review. Ship to
  both hotels; entry in GUIDES_AI_SYNC.md.

**Step 8b - Payments accuracy and completeness.** (Owner 2026-11-14: "add payments as another step, we have to
absolutely make sure money is accurate and that pages like payments have everything including clicking rows to display
info or showing any other important payment details like logs and notes and also making sure invoices, quotes,
receipts etc".) ASSUMPTION: the last part means those documents must show the same money as the payment records.
Owner: dept-accounting-lead (Opus). Money change = full suite at LIWONDE before ship; Rosalyn's is LIVE (read-only).
- Part 1, audit (read-only, at Liwonde): for a sample covering single, group, company-billed, part-paid, overpaid,
  refunded, cancelled-with-kept-fee, no-show and edited payments, check that ONE figure agrees everywhere: the
  payment row, payment details, booking folio / left to pay, Still owed, Payments + Accounting cards, EOD, receipt,
  invoice, quotation (and its converted invoice), credit note, the emailed copies and the CSV exports. VAT 17.5% +
  levy 1% split, BALANCE_TOLERANCE, booked rate x N nights, refunds = full amount paid shown in full. List every
  mismatch with file:line and the two figures in TASKS.md; no fix before the list exists.
- Part 2, Payments page (admin/payments.php + payment-details.php): a click anywhere on a row that is not a control
  opens that payment (step 8 UI_DECISION: same handler rule, pointer, View button stays the keyboard path) showing
  who paid, for which booking / room / group, method, reference, amount with VAT + levy, received by, date and time,
  linked receipt / invoice / credit note / refund, notes, and the step-by-step History (who, what, old -> new; edits
  with their reason). Missing notes or History on any payment write path = add the logging (full history rule,
  owner 2026-11-09). Lists paginate at 10 with the picker; every column and card has its helper. Same row click on
  Receipts, Invoices, Quotations and Credit notes where a details view exists.
- Part 3, fix the mismatches from part 1, highest money risk first, each with a test case in the area suite. If the
  list is longer than one session, ship the money-wrong items first and leave display-only items as LATER in
  TASKS.md for the owner; do not grow the step mid-way.
- Fable 5.2 (owner 2026-11-14, model policy): ONE spec check of the part 1 mismatch list + planned fixes BEFORE the
  build (money build), and ONE final high-risk review of the money diff AFTER the Opus review passed. A third call
  only for a fault Opus failed twice.
- Gate: dept-accounting-lead builds, ONE qa-auditor (opus) review, then the Fable final review. Browser check 390 / 768 / 1400 at LIWONDE. No migration
  without the owner's OK for Rosalyn's. Ship to both hotels; entry in GUIDES_AI_SYNC.md.

**Step 8c - Stock review.** (Owner 2026-11-14: "add stock on its own as a step to review which should include things
like the import scraper too and small things like barcode. We don't want to add features to these but make absolutely
sure it works as it's intended at optimum. This will work with the POS too.") ASSUMPTION: "import scraper" = the Data
Import button (Excel/CSV/Word/PDF -> review -> import, IMPORT_PLAN.md) for stock lists.
NO NEW FEATURES: faults, wrong figures, broken flows and slow paths only. Anything that would be a feature goes to
TASKS.md as LATER for the owner. Rosalyn's is LIVE (read-only); every test, count and import rehearsal runs at LIWONDE.
- Part 1, audit (read-only, at Liwonde), owner dept-stock-lead (Opus): walk each flow end to end and compare the
  result with what it is meant to do: ingredients + recipes; POS sale and room-service order -> stock deduction; void /
  refund / order delete / return -> restore (exact, once, never twice); FIFO batches and cost; stock counts (partial,
  opening, approval); wastage and loss; receiving against purchase orders; suppliers and buying; low-stock figures and
  the stock cards / reports (card figure = the rows behind it); barcodes (scan to find, scan to receive, duplicates,
  unknown code, the till and stock pages agree). dept-restaurant-lead checks the POS side of every deduct/restore;
  dept-import-lead checks the stock import (mapping shown, unclear rows asked, nothing invented, re-import does not
  double stock, permission `data_import`). List every fault with file:line, expected vs actual, in TASKS.md before
  fixing anything.
- Part 2, fix: stock-quantity and cost faults first, then broken flows, then speed (per-row queries on the stock
  pages, slow scans). Each fix gets a case in its own suite (stock_*, POS/KDS smoke, shop_returns, stock_loss, import
  tests). Money touched (cost of sales, margins, POS accounting) = dept-accounting-lead reviews.
- If the list is longer than one session: ship the quantity/cost faults first, list the rest as LATER; do not grow
  the step mid-way.
- Fable 5.2 (owner 2026-11-14, model policy): if any fix changes stock deduction/restore quantities, batch cost,
  cost of sales or margins - ONE spec check of the fault list + planned fixes before the build and ONE final
  high-risk review after the Opus review passed. Display-only or speed-only fixes: no Fable. Otherwise only for a
  fault Opus failed twice.
- Gate: ONE qa-auditor (opus) review; the stock + POS suites at LIWONDE; browser check 390 / 768 / 1400 of any screen
  touched (POS till and stock pages). No migration without the owner's OK for Rosalyn's. Ship to both hotels; entry
  in GUIDES_AI_SYNC.md.

**Step 9 - Other modals on phones and tablets.** (Owner 2026-11-14: "Also check other modals affected the same for
mobile and tabs especially and add it in the build order.") Confirm step 8 is SHIPPED; reuse its `UI_DECISION`.
- Part 1, check (read-only, cheap): list every admin modal (Grep `admin/`, `js/`, `css/` for the shared modal classes
  and ids; CODE_INDEX, never whole files) and look at each one at 390 and 768 with the headless browser check at
  LIWONDE. Mark each: fine / affected (oversized cards, every detail on its own card, wasted space, sideways scroll,
  buttons off screen, tap targets too small). Put the table in TASKS.md under this step.
- Part 2, fix: apply the step 8 compact pattern to the affected modals, highest-use first (payments, folio/charges,
  check-in/out, room assignment, POS/order and stock modals before rarely used settings dialogs). Prefer fixing the
  SHARED modal styles once over per-modal overrides.
- Layout and markup only: no money, stock, booking or permission logic; nothing removed from the system; desktop
  unchanged. Modal markup stays inside `#rh-admin-page` / `<main>` (SPA rule). No AI-looking icons or labels.
- If more than about 8 modals need more than the shared fix, ship the shared fix + the highest-use ones in this
  step and list the rest in TASKS.md as LATER for the owner to approve; do not grow the step mid-way.
- Builder: ui-lead (Opus), department leads consulted only for their own screens. Gate: one qa-auditor review +
  browser check at 390, 768 and 1400 of every modal touched (stop `php -S` after). UI only: no DB tests. Ship to
  both hotels; add the entry to GUIDES_AI_SYNC.md.

**Step 10 - Bookings list on tablets.** (Owner 2026-11-14.) At 641-1024px the Bookings list switches to large cards
(admin-mobile.js `.mobile-enhanced`), about one booking per screen. Make it compact like the phone rows of 4cc94f4
(name + status; stay, nights, room; total + payment badge; booked rate x nights; one next-step button + More), reusing
those classes and the step 8 UI_DECISION. Row click already opens Booking Details (step 8). Layout only, desktop and
phone unchanged. Builder: ui-lead (Opus). Gate: one qa-auditor review + browser check 390 / 768 / 1400 at LIWONDE.
Ship to both hotels; entry in GUIDES_AI_SYNC.md.
