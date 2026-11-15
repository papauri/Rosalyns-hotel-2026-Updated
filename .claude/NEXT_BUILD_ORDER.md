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
   > Next up: step <next row's #> - <its title> (the next row in the table, not the next number).

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
| 8a | SHIPPED 2026-11-15 13cabec / 13df47e (owner confirmed the real question works) | AI Assistant revisit: stay facts for a named guest ("when did <guest> check out") must be answered, never "the chat was cleared" / "I don't know" (owner 2026-11-14) | this file, step 8a brief | dept-ai-lead (Opus) -> opus review |
| 8b | SHIPPED 2026-11-15 6303bf1 / a148654 | Payments: money accurate end to end + Payments page complete (row click opens the payment, History/logs, notes) + invoices, quotations, receipts, credit notes agree (owner 2026-11-14) | this file, step 8b brief | dept-accounting-lead (Opus) audit -> build -> qa-auditor opus + browser check 390 / 768 / 1400 |
| 8c | SHIPPED 2026-11-15 c1b5d4b / 64090a7 (P1-P7, S1-S3, S8, S9, S12, I1-I5; rest LATER in TASKS.md) | Stock review: works as intended, end to end, with the POS (deduct/restore, batches, counts, wastage, receiving, barcodes, suppliers/POs, Data Import). No new features (owner 2026-11-14) | this file, step 8c brief | dept-stock-lead (Opus) audit -> fix -> qa-auditor opus; dept-import-lead + dept-restaurant-lead for their parts |
| 8d | SHIPPED 2026-11-15 e90b5c7 / 5151a26 (owner: "do 8d now") | Refunds counted on the payout day everywhere: the 4 places 8b left on the day raised (dashboard today feed, api/payments.php list + summary, company "received this month", Accounting payment-method counts) + Payments list date column/sort | this file, step 8d brief | dept-accounting-lead (Opus) -> qa-auditor opus + browser check 390 / 768 / 1400 |
| 8e | SHIPPED 2026-11-15 5678666 / 68f08d8 (Rosalyn's: 3 split pairs merged on owner OK, joined pair 7A + 7B created Shared max 5 and given to the 3 kept bookings) | Joined rooms: booking a joined-room type when the individual rooms are not joined for those dates joins two free individual rooms for that stay (one booking, joined price, both rooms blocked); merge the two owner-named split pairs (27-29 Nov and 4-8 Dec 2026; refs in the gitignored storage/private-notes/8e.md) at Rosalyn's into one joined-room booking each | this file, step 8e brief | dept-rooms-lead (Opus) -> dept-accounting-lead (merge money) + qa-auditor opus; Fable spec check (booking/money data change) |
| 8f | SHIPPED 2026-11-15 20cdf13 / 6ee0531 (run before 9 by step number; phones line 2, tablets/laptops under Check In where the Guests column hides) | Bookings page: show the number of guests on every booking row/card at every width (phone, tablet, desktop) | this file, step 8f brief | ui-lead (Opus) or inline if under ~10 tool calls -> qa-auditor + browser check 390 / 768 / 1400 |
| 8g | SHIPPED 2026-11-15 2af6667 / 9032766 (owner: "add next step here and apply"; Booked by skipped: no column records it) | Better filtering: Bookings (Cancelled, No-show, Checked out, Group bookings, Joined rooms, room type, payment state, booked-by) and Payments (refunds, by booking status, group/company, amount, who took it, room type etc.), at every width | this file, step 8g brief | dept-rooms-lead (Bookings) + dept-accounting-lead (Payments) (Opus) -> one qa-auditor opus + browser check 390 / 768 / 1400 |
| 8h | SHIPPED 2026-11-15 d6b8081 / 3d50a8c (086 run at Liwonde, and at Rosalyn's 2026-11-15 on owner request "do rosalyns database update") | Rate plans: ONE plan carries a different % or fixed amount per room type; choose whether it goes on the all-in price (VAT + levy included) or on the base rate before VAT/levy; live breakdown per room type in the modal | this file, step 8h brief | Fable 5.2 spec check (money + migration) -> dept-rooms-lead (Opus) build -> dept-accounting-lead (money) + qa-auditor opus + browser check 390 / 768 / 1400 at Liwonde |
| 8i | SHIPPED 2026-11-16 93a56ff / 1b26f45 (display bug: pop-up showed one payment row's status; 0 stored mismatches, no data fix needed) | Payment status always tells the truth: a booking with money still owed never shows Completed/Paid; status follows paid vs total everywhere and is recalculated whenever the total changes | this file, step 8i brief | dept-accounting-lead (Opus) audit -> build -> qa-auditor opus + browser check 390 / 768 / 1400 at Liwonde |
| 9 | SHIPPED 2026-11-16 0c31edc / 7a7fd9b (A1-A9, B1-B5; full suite at Liwonde 64 OK + 2 pre-existing dashboard fails) | POS / KDS core review: comms between POS and stations (KDS/BDS/CDS, room service, tabs) and how the POS drives stock and accounting are accurate end to end. No new features | this file, step 9 brief | dept-restaurant-lead + dept-accounting-lead (Opus) READ-ONLY audits in parallel -> one fix builder per area -> qa-auditor opus + DB tests + browser check at LIWONDE |
| 11 | SHIPPED 2026-11-16 2d61313 / 26ae4a2 (tests only: Liwonde CRLF checkout; full suite at Liwonde all passed) | Two failing dashboard tests ("Takings today" card, rooms out of service) pass again | this file, step 11 brief | inline or backend-specialist (Opus) -> qa-auditor opus |
| 12 | SHIPPED 2026-11-16 1865a32 / aa107cc (0 paid room-service orders with live folio charges at either hotel; till now refuses them; LATER: EOD folio test counts voided charges) | Money leftovers from step 9: End of Day revenue refunds by payout day, room-service void lowers cost, paid room-service orders with live folio charges, charge-void History line, drift page wording | this file, step 12 brief | dept-accounting-lead (Opus) -> qa-auditor opus + DB tests at Liwonde |
| 13 | TODO · P3 (owner 2026-11-16 priority order): staff act on the wrong list: a booking can show "Unpaid" yet be missing from the Unpaid tab, so balances get missed | Payment status leftovers from 8i: Paid/Unpaid tabs + Payment filter follow the money, booking lookup + Individual Rooms use the new labels, calendar tooltip | this file, step 13 brief | dept-accounting-lead (Opus) -> qa-auditor opus + browser check 390 / 768 / 1400 |
| 15 | TODO · P4 (owner 2026-11-16 priority order): festive prices are live now; the Rosalyn's merge decision must come before the next mirror run, and the joined-room check stops a plan producing a wrong or free price | Rate plan leftovers from 8h: free-room check with joined prices, preview rounding + error message, History shows only booked room types, tap sizes; owner question on merging Rosalyn's festive plans | this file, step 15 brief | dept-rooms-lead (Opus) -> dept-accounting-lead + qa-auditor opus + browser check |
| 14 | TODO · P5 (owner 2026-11-16 priority order): real but rare POS faults (extra tab after a lost reply; rare lock clash that rolls back safely); Rosalyn's has no POS sales yet | POS / KDS leftovers from step 9: orphan open tab after a lost Fire reply, lock order, last-dish notice, the two POS smoke tests run on Liwonde | this file, step 14 brief | dept-restaurant-lead (Opus) -> accounting money review + qa-auditor opus + DB tests at Liwonde |
| 9b | TODO · P6 (owner 2026-11-16 priority order): pop-ups hard to use on phones/tablets; no wrong data | Other admin modals with the same problem on phones + tablets: check all, fix the affected ones (owner 2026-11-14) | this file, step 9 brief | ui-lead (Opus) -> qa-auditor + browser check 390 / 768 / 1400 |
| 10 | TODO · P7 (owner 2026-11-16 priority order): Bookings list easier on tablets (also fixes the 13 tabs wrapping); no wrong data | Bookings list on tablets: compact rows instead of the large cards (owner 2026-11-14) | this file, step 10 brief | ui-lead (Opus) -> qa-auditor + browser check 390 / 768 / 1400 |
| 16 | TODO · P8 (owner 2026-11-16 priority order): filter polish (wording, tap sizes, one shared design); cosmetic | Filtering leftovers from 8g: banner title, Payments Clear buttons 44px, Recorded by System/API, one More-filters design | this file, step 16 brief | ui-lead (Opus) or inline -> qa-auditor + browser check 390 / 768 / 1400 |

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

**Step 8d - Refunds on the payout day everywhere.** (Owner 2026-11-15: "do 8d now", ahead of 8c.) OWNER RULE (8b,
2026-11-14/15): a refund counts on the day it was paid out in every report; a pending refund counts in no period.
Helper: `rh_pay_count_day_sql()` in admin/includes/finance-refunds.php (already used by EOD, Reports, Accounting cards,
Payments filters). Apply it where 8b left the day raised: admin/dashboard.php ~1461 (today feed refunds),
api/payments.php ~182-238 (list + summary date filters), includes/billing-accounts.php ~615 (company received net),
admin/accounting-dashboard.php ~376 (payment-method counts), admin/payments.php ~175 + list Payment Date column. No
change to amounts, only which day a refund lands on. Fable: not needed (rule already spec-checked in 8b). Builder:
dept-accounting-lead (Opus); gate qa-auditor opus; tests at LIWONDE only (payments_accuracy_8b + finance_refunds +
billing suites + one new case); browser check 390 / 768 / 1400 at Liwonde. Ship to both hotels; entry in GUIDES_AI_SYNC.md.

**Step 9b - Other modals on phones and tablets.** (was step 9; owner 2026-11-16 put the POS/KDS review first) (Owner 2026-11-14: "Also check other modals affected the same for
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

**Step 8e - Joined rooms on demand + merge two live bookings.** (Owner 2026-11-15: "we need another way better logic switch for joined rooms. Individual rooms can be joined and sold as joined rooms for a future date however when the FOH choose joined rooms when individual rooms have not been joined. We need to be able to change because [two named bookings] at rosalyn live is actually supposed to be just 1 joined room and not 2 same with [a second guest]".) OWNER DECISIONS 2026-11-15: (1) booking a joined-room type for dates with no existing join JOINS two free individual rooms for just that stay: one booking, one joined room at the joined price, both individual rooms blocked for those nights (joined rooms are a real feature: keep the cross-type availability rules intact); (2) the live pairs are MERGED: the first reference stays as the joined-room booking at the joined price, the second is closed as merged (not a guest cancellation, no fee), any payment moves to the first, full History on both; (3) owner allowed reading and then changing ONLY these bookings at Rosalyn's, backup first, after the fix is built and tested at Liwonde. Builder: dept-rooms-lead (Opus); money gate dept-accounting-lead; Fable spec check before the build (booking + money data change); qa-auditor opus; browser check 390/768/1400 at Liwonde. Ship to both hotels; GUIDES_AI_SYNC entry.

**Step 8f - Guests on the Bookings page.** (Owner 2026-11-15: "Also on the booking page show number of guests".) admin/bookings.php shows guests only in the `col-optional-lg bk-col-guests` column (~:4338), hidden at smaller widths, and the phone/tablet compact rows (4cc94f4 pattern, admin-mobile.js) may not show it. Show "N guests" on every row/card at 390, 768 and 1400 next to stay/nights (same classes, no new visual language; joined bookings show the party total, e.g. 5). Layout only, no booking logic. Gate: one qa-auditor review + browser check at LIWONDE; ship to both hotels; GUIDES_AI_SYNC entry.

**Step 8g - Better filtering on Bookings and Payments.** (Owner 2026-11-15: "add better filtering too for like booking, i dont see cancelled for example or group booking filtering etc even better filtering for pages like payments".) Today Bookings has a Status dropdown (incl. Cancelled, buried), Arrival/date filters and tabs All / Today's check-ins / check-outs / In-house / Pending / Tentative / Expiring / Unpaid / Deleted (admin/bookings.php ~:4100-4215, rules in admin/includes/bookings-list-filters.php rh_bk_tab_map / rh_bk_tab_clauses). Payments has Booking type / Booking ID / Search / Status / Method / dates (admin/payments.php ~:690-770). Build:
- Bookings: tabs (same tab-button pattern, live counts from the same SQL pieces as the list) for Cancelled, No-show, Checked out, Group bookings; plus a "More filters" row (collapsed on phones) with Booking kind (Single / Group / Joined room), Room type, Payment (Paid / Part paid / Unpaid / Refunded / Fee owed after cancel or no-show), Booked by (online / staff, if a column records it). Every filter is a whitelisted GET param, prepared statements, kept across paging/sort/CSV export, shown in the "Showing N ..." notice with a Clear, and lands on the rows (scrolls to the list). Group = whatever the code already uses to tie a group together (find it; never invent a new column).
- Payments: add filters for Refunds (money out) vs money in, Linked booking status (cancelled / no-show / checked out), Group / company bookings, Recorded by (staff), Room type (room payments), Amount from/to; same rules (whitelist, prepared, kept across paging/sort/export, notice + Clear). Money totals on the page must follow the filters exactly as the list does (accounting lead owns this; refunds stay dated by payout day).
- Layout: no new visual language (same filter-group / tab-button classes), works at 390 / 768 / 1400 without sideways scroll, every new filter has a plain helper (memory: columns and cards explain themselves). No AI-looking labels.
- No money logic or saved data changes. Gate: one qa-auditor opus review of both diffs, browser check at LIWONDE, ship to both hotels, GUIDES_AI_SYNC entry.

**Step 8h - Rate plans: per-room-type amounts, price basis, breakdown.** (Owner 2026-11-15: "the room plan when created is only applying the 1 percentage to all room types. I want to be able to create 1 room plan that can apply either different percentages or fixed amount on top of the standard room prices so we dont have several room rate plans. Thats why we had to make 3 rate plans for the same period in liwonde ... when putting the rate plan whether its % or fixed amount it shows the breakdown of how much % or amount its gone up by and also pick whether this rate plan is on top of the full room price with tax and levy inclusive if both are ON or just on top of the room base rate before tax and levy depending on whether its on or off".)
Today: `rate_plans` has ONE adjustment_type ('percentage'|'fixed') + adjustment_value for the whole plan, applies_to 'all' | 'room_types' (room_type_ids JSON). Engine: includes/pricing.php rh_apply_rate_plans:99 (splits plans into surcharges/discounts by the SIGN of adjustment_value, _rhRatePlanGroupPick:193 picks best non-stacking per direction), applyRatePlanToPrice:283, _planAppliesToRoom:315. Room prices are ALWAYS the all-in amount (base + VAT + levy; rh_stay_totals:235, owner 2026-10-24). Admin: admin/rate-plans.php (save:29, modal planModalOverlay:702, JS onRuleTypeChange/onAppliesToChange). Other readers (all must agree): admin/create-booking.php, includes/booking-room-change.php, includes/price-history.php, includes/booking-history-format.php, admin/api/global-search.php, includes/ai/ai-tools.php (rh_ai_rate_plans_for_quote:1365, rh_ai_rate_plan_parts:1385, rh_ai_rate_plan_lines:1431; AI lead told via GUIDES_AI_SYNC, AI files changed only if a reader would now give a WRONG price), config/database.php (Grep only), js/booking.js (display only).
Build:
1. Per-room-type amounts: a plan keeps a default (type + value) and may set, per room type it applies to, its own type (% or fixed) and value. Storage: additive migration 086 (child table `rate_plan_room_adjustments` (rate_plan_id, room_id, adjustment_type, adjustment_value, unique plan+room) or an equivalent the spec check prefers; never destructive). Code must work before the migration runs (missing table/column = today's behaviour). Existing plans keep exactly today's prices.
2. Price basis per plan: `price_basis` 'all_in' (default = today) | 'base'. 'base' = the adjustment applies to the net room rate before VAT and levy, then VAT/levy are recomputed on the new net (all-in = new net x (1 + vat + levy), same rh_stay_totals rule). The choice shows only when VAT and/or the tourism levy is switched on (label names which are on); when both are off it is hidden and saved as 'all_in'. Note for the helper text: a PERCENTAGE gives the same guest total on either basis (only the split shown changes); a FIXED amount differs (base: +amount before tax, guest pays amount x (1 + VAT + levy)).
3. Engine: the direction (surcharge/discount) and best-pick are decided per room from that room's effective adjustment; stacking/priority rules unchanged. One helper returns a room's effective adjustment so every reader above uses the same rule.
4. Modal UI: rule section unchanged; adjustment section becomes "Same for all room types" or "Different per room type" (a row per chosen room type: % / fixed + value). Live breakdown per room type (JS mirrors the PHP helper, server value is the truth): standard price -> base before tax / VAT / levy -> adjustment (+X% or +MWK X) -> new base / VAT / levy -> new all-in price, and "up by MWK X (Y%)" (or "down by"). Same breakdown in the plan list (compact) and in the History/price-history entry (old -> new per room type). Same classes as the existing modal; works at 390 / 768 / 1400; every figure has a plain helper.
5. Booked bookings keep their booked rate (snapshots unchanged). No change to how bookings store rate_plan_id / label.
6. Tests: extend the rate-plan tests (Grep scripts/tests for rh_apply_rate_plans) with per-room %, per-room fixed, base vs all-in fixed, mixed signs across rooms, non-stacking best-pick per room, missing-migration fallback. DB tests at LIWONDE only. Migration 086 run at Liwonde; at Rosalyn's ONLY when the owner says so (code falls back until then).
Gate: Fable spec check of this brief + design before build (DEPARTMENTS rule 2 case 1); dept-accounting-lead reviews the money; qa-auditor opus; browser check at LIWONDE; ship to both hotels; GUIDES_AI_SYNC entry. Merging Liwonde's 3 same-period plans into one is NOT part of this step (owner to ask).

Step 8h spec check (Fable 5.2, 2026-11-15) - the builder follows this:
- Storage: two columns on `rate_plans` via migration 086 (085 style, each column checked alone): `room_adjustments` TEXT NULL = {"<room_id>":{"type":"percentage|fixed","value":n}}, `price_basis` VARCHAR(10) NOT NULL DEFAULT 'all_in'. Engine fallback `?? null` / `?? 'all_in'` = today. Save + modal gated on cached `rh_rate_plans_086_ready($pdo)`; false = today's SQL/UI.
- Helpers in includes/pricing.php: `rh_rate_plan_effective(array $plan, int $roomId): ?array` (null = not covered; else type/value/basis), `rh_rate_plan_tax_factor(): float` (1 + v + l, read as pricing.php:237-242), `rh_rate_plan_price(array $plan, int $roomId, float $price, ?float $F = null): float`; applyRatePlanToPrice stays as legacy wrapper. Switch pricing.php:132 (sign), :150, :211 (_rhRatePlanGroupPick gains $roomId); F read lazily only for fixed+base. Must switch: admin/create-booking.php:1481-1499 (+ room_adjustments, price_basis, F) and its JS :3273-3315; admin/rate-plans.php:619-622.
- Math: percentage (either basis) = round(P*(1+p/100),2) (identical all-in only computed this way). Fixed all_in = today. Fixed base = max(0, round(P + round(value*F,2),2)). VAT off v=0, levy off l=0; both off F=1 and force 'all_in' server-side. Breakdown split = rh_stay_totals(newP,'final'), residue on net.
- Rails: applies_to 'all' = overrides for listed rooms, every other room (incl. later types) uses the default; 'room_types' = strip overrides outside room_type_ids at save. Joined types price from the pair override (booking-room-change.php:114). Percent child supplements follow the post-plan rate (booking.php:454); fixed child charges + packages untouched. Override value 0 refused at save. History: one row per room, field room_adj_<id>, e.g. "+10% (100,000 -> 110,000)", <255 chars (price-history.php:68); labels at booking-history-format.php:870-903.
- Blockers: B1 add new columns to $rpHistFields (rate-plans.php:125) only when 086 ready. B2 ASSUMPTION: one plan is either all increases or all discounts - refuse mixed signs across default + overrides at save (overlap SQL rate-plans.php:219-221, ai-tools.php:1408/:1418, create-booking JS :3310 classify by the default's sign; AI files then need no edit; ai-tools.php:1856 lists the default only -> GUIDES_AI_SYNC). B3 create-booking JS preview must be updated.
- Nice-to-have (take it): one server preview action for the modal breakdown instead of a third JS copy of the math; refuse a fixed discount at or above a room's price. scripts/tests/rate_plan_rules_rp3.php:33-65 may hold an engine copy - update it.

**Step 8i - Payment status tells the truth.** (Owner 2026-11-15, Booking Details screenshot of a Rosalyn's checked-in booking: header pill "PAYMENT Completed" and field "Payment status: Completed", while "How the total is made up" shows Total MWK 1,066,500, Paid so far MWK 937,500, Balance due MWK 129,000: "its a bit misleading to say payment status just completed when a booking has a balance pending".) Likely cause: bookings.payment_status stayed 'completed' after the total went up (937,500 = 312,500 x 3, now 355,500 x 3; the modal says "or last repriced"). admin/bookings.php:4552 shows `payment_status ?: actual_payment_status`; rh_bk_payment_flags (admin/includes/bookings-list-filters.php) counts 'completed' as Paid, so the list badge, Paid/Unpaid tabs and 8g Payment filter may say Paid too.
Build: (1) find every place a booking's payment status is shown or counted (Booking Details pill + field, list badge/phone row, Paid/Unpaid tabs, 8g pay filter, dashboard/accounting counts, emails/PDFs only if they print it) and make them follow the money: Paid in full / Part paid (MWK X left) / Unpaid / Refunded, from the same paid-vs-total figures the "How the total is made up" box uses (BALANCE_TOLERANCE); (2) find every path that changes a booking's total (reprice, rate change, upgrade/room change, extend stay, add charges, child changes, cancellation fee, joined merge) and make sure payment_status is recalculated there with the same helper (and logged in booking History, who/when/old -> new); (3) list (read-only SELECT, no writes) every Rosalyn's booking whose stored payment_status disagrees with its money, and fix them ONLY at Liwonde; Rosalyn's data correction waits for the owner's OK (report the count + refs in the gitignored storage/private-notes/8i.md). No change to amounts, payments or documents. Gate: qa-auditor opus, browser check at LIWONDE 390/768/1400, ship to both hotels, GUIDES_AI_SYNC entry.

**Step 9 - POS / KDS core review.** (Owner 2026-11-16: "lets replace step 9 with a KDS/POS check instead, this is a proper review of comms between stations and how POS manages stock/accounting. This is not to add features but to make sure core functionality is accurate. Thats more of a priority than UI modals".)
NO NEW FEATURES: wrong figures, lost/duplicated/misrouted orders, stuck statuses, broken flows only; anything else -> LATER in TASKS.md. Rosalyn's has no POS sales yet (owner 2026-11-15): every test and rehearsal at LIWONDE, then port. Step 8c already reviewed the stock engine with the POS (P1-P7, S1-S3, S8, S9, S12, I1-I5 shipped; rest LATER in TASKS.md) - do not redo it; only check the POS call points into it still agree.
Files (CODE_INDEX): admin/pos.php (12k lines, offset reads only), api/kds-action.php, admin/kds.php, admin/order-lifecycle.php, admin/includes/pos-order-close.php, pos-order-delete.php, pos-shift-totals.php, restaurant-payment-sync.php, menu-pos-sync.php, offline-queue.js, admin/room-service-dashboard.php, admin/restaurant-tables.php, admin/pos-accounting.php, admin/shift-close-report.php, admin/pos-drift-report.php, admin/kds-report.php, includes/station-hours.php, admin/js/station-sounds.js; config/database.php Grep only.
A. Station comms (dept-restaurant-lead): every order line routes to the right station (kitchen / bar / coffee bar) by item/category; send, bump, ready, served, recall, void, delete, edit-after-send and split/merge tabs reach every station once (no duplicates, nothing lost), statuses move one way and agree on POS, KDS/BDS/CDS, Orders & Tabs, room service and tables; station hours / closed stations; polling/reconnect and offline queue replay (no double sends); station chat; timestamps (app $pdo time, not raw UTC).
B. POS money + stock (dept-accounting-lead; stock lead consulted only if a stock call point disagrees): totals, deals/promos, discounts, VAT/levy split, service charge if any, tips; pay methods incl. split payment, room charge to a booking folio, open tab settle; voids/refunds/deletes reverse money AND stock exactly once (stock via the 8c engine); every POS sale lands once in Payments / accounting / shift close / Z-report / End of Day with the same figures; drift report agrees.
Process: both leads AUDIT READ-ONLY in parallel (findings with file:line, severity, a Liwonde repro), then ONE fix builder per area for the confirmed faults (no overlapping files at the same time), DB tests at Liwonde, qa-auditor opus, browser check at LIWONDE (POS till + KDS at 390 / 768 / 1400 where they have a screen), ship to both hotels, GUIDES_AI_SYNC entry.

Step 9 audit results (2026-11-16, read-only, Liwonde tests green: stock_pos_restore_8c 138, refund_vat_pos 38, z_room_charge 84, refund_payout_day_8d 31, finance_refunds 60, payments_accuracy_8b 90, linked_rooms 127):
- A1 BLOCKER pos.php:7911 doParkSubmit makes a new client_uuid per send; a lost response + retry = two tickets + doubled tab. Fix: new uuid only after j.ok or a cart change; fix the stale comment :7909.
- A2 MAJOR no "ready" alert for a tab's 2nd round / after recall: add_to_tab (~pos.php:728) never clears pos_ready_notifications (kds-action.php:213 finds round 1 and stops); till seen key pos.php:4379 never changes (also blocks recall cleanup kds-action:1674); per-dish notice skips the last dish. Fix: delete rows for stations getting new lines in add_to_tab; key seen set on n.id.
- A3 MAJOR after stations close, ready alerts / My orders / sent notes vanish: kds-action.php:108 $unionWindow and api/pos-notifications.php:142 stop at closing; use rh_station_union_trading_window() start_sql + trading_end_sql (same as the till money window).
- A4 MINOR kds.php:941 help promises Recall (10 min, header says 5) but nothing calls recall_ticket: correct the text only (button = LATER).
- A5 MINOR closed-station warning uses item type not routed station, fixed at load, ignores coffee bar (pos.php:3984, 6938, 7855).
- A6 MINOR add_to_tab keeps the old fired_at (new round shows overdue, kds.php:872) and resets the order to 'new' while round 1 cooks.
- A7 MINOR note edits / rush flags go to all 3 stations (kds-action.php:978, 1024): send only to stations on the order.
- A8 MINOR 86 of the last unfinished line sends no ready alert (call kds_maybe_notify_ready after void_item).
- A9 MINOR deleting a live order sends no STOP note (pos-order-delete.php:749); void does.
- B1 MAJOR pos-drift-report.php:54-82 recomputes with its own query (created_by not paid_by, status='paid' only, gross, no refund/void payouts, no REF86 add-back) -> false drift; :36 reads getSetting('vat_enabled') not rh_vat_enabled(). Fix: compare against rh_pos_shift_totals($pdo,$uid,from,end) drawer_cash/mobile/card.
- B2 MAJOR End of Day dates a pending POS refund on the day raised: finance-account-sync.php:994 sets refunded_at=NOW(); rh_pos_eod_summary (pos-shift-totals.php:1202) counts refunded_value by refunded_at with no not-pending guard; Z (:386-388) waits for payout. Fix: use the Z's $refundAt / $refundNotPending rule.
- B3 MAJOR voiding a room-service folio charge on a closed order leaves stock_orders.total_amount unchanged (config/database.php:9721): lower subtotal/total in the same transaction with the KDS 86 ratio (kds-action.php:1245-1264).
- B4 MINOR discount > order: total clamped to 0.01 but discount_amount stores the full discount (pos.php:878/891, :1321/1340): store subtotal - new total.
- B5 MINOR latent rh_create_pending_refund_allocation (restaurant-payment-sync.php:523) not capped at the overpayment (unreachable today): cap it.
Fix split (no shared files): builder A = dept-restaurant-lead: A1-A9 + B4 (pos.php, api/kds-action.php, admin/kds.php, api/pos-notifications.php, admin/includes/pos-order-delete.php). Builder B = dept-accounting-lead: B1, B2, B3, B5 (admin/pos-drift-report.php, admin/includes/pos-shift-totals.php, config/database.php, admin/includes/restaurant-payment-sync.php, finance-account-sync.php if needed). Accounting lead reviews A1 + B4 money; qa-auditor opus on both.

**Steps 11-16 - Leftovers from 8g-9.** (Owner 2026-11-16: "Lets put these as steps in a new sessions", after the review of 8f-9.) Run order is the table order (owner 2026-11-16 priority): 11, 12, 13, 15, 14, 9b, 10, 16, one per session. Every step: ROSALYN'S IS LIVE (tests and data fixes at Liwonde only), DEPARTMENTS.md ship gate, ship to both hotels, GUIDES_AI_SYNC entry. Fix only what is listed; anything new -> LATER in TASKS.md. NOT steps (stay LATER until the owner asks): POS Recall button (feature), "Booked by" filter (needs a new column recording who made a booking), Assistant plan list showing per-room amounts (goes with the next Assistant sync, logged in GUIDES_AI_SYNC.md), Bookings tabs wrapping on tablets (step 10 covers it), one-time re-alert of old "ready" notices after the step 9 deploy (self-clears).

**Step 11 - Two failing dashboard tests.** The full suite at Liwonde had 64 OK + 2 FAIL at HEAD before step 9 too: the dashboard "Takings today" card and "rooms out of service" tests. Find the dashboard test file(s) with Grep in scripts/tests/ (run_tests.php lists them). Decide per test: the code is wrong (fix the dashboard; money rules: BALANCE_TOLERANCE, refunds by payout day, app $pdo time) or the test is stale after a shipped change (update the test and name the commit that changed the rule in the commit message). Never weaken a check just to pass. Run only those test files at Liwonde, then the full suite once (dashboard money). Gate: qa-auditor opus.

**Step 12 - Money leftovers from step 9.** dept-accounting-lead (Opus), Liwonde first. (1) admin/includes/eod-revenue.php:216 dates POS refunds on the day raised: use the same rule as step 9 B2 (rh_pos_eod_summary / the Z's $refundAt + $refundNotPending in admin/includes/pos-shift-totals.php), so End of Day revenue, the EOD report and Z agree. (2) Step 9 B3 (rh_lower_order_total_for_voided_charge in admin/includes/restaurant-payment-sync.php, called from voidBookingCharge in config/database.php ~:9721) lowers subtotal/total but not total_cost: lower total_cost by the same ratio so cost/margin match. (3) A room-service order already marked paid that still has live folio charges: find where it can happen (Grep room-service close + folio charge paths) and make sure the money is counted once (no double revenue, voids reverse once); report a read-only count of such orders at Rosalyn's (SELECT only) and fix data only at Liwonde. (4) Voiding a folio charge writes a Booking History line (who/when/what/amount, same format as other entries, includes/booking-history-format.php). (5) admin/pos-drift-report.php ~:284 help text still says "split-aware": say what the page now compares (rh_pos_shift_totals). Tests: extend scripts/tests/pos_kds_step9.php; run it + refund_payout_day_8d, refund_vat_pos, z_room_charge, finance_refunds at Liwonde. Gate: qa-auditor opus; browser check of End of Day + drift page at Liwonde.

**Step 13 - Payment status leftovers from 8i.** dept-accounting-lead (Opus). (1) Bookings Paid / Unpaid tabs and the 8g Payment filter (rh_bk_tab_clauses / rh_bk_payment_flags in admin/includes/bookings-list-filters.php) still read stored bookings.payment_status: make them use the same paid-vs-total rule as rh_booking_pay_state (includes/booking-pay-state.php), in SQL with BALANCE_TOLERANCE, so tab, badge and pop-up always agree (e.g. a cancelled booking with nothing paid). Keep cancelled/deleted handling as today's tabs. (2) admin/booking-lookup.php and admin/individual-rooms.php (and any other screen Grep finds printing payment_status) show the new labels via rh_booking_pay_state: Paid in full / Part paid (MWK X left) / Unpaid / Refunded. (3) Calendar tooltip (admin/calendar.php): label not Title-Cased per word, colour by state (same pill colours as the list). (4) Remove unused label entries in booking-pay-state.php. Tests: extend scripts/tests/booking_pay_state.php and bookings_filters.php; run at Liwonde. Gate: qa-auditor opus + browser check 390 / 768 / 1400 at Liwonde.

**Step 14 - POS / KDS leftovers from step 9.** dept-restaurant-lead (Opus), tests at Liwonde only. (1) Orphan open tab: if a Fire commits but its reply is lost and the cashier then Pays or edits before retrying, an extra open tab is left. Make the next Pay/edit reuse the committed park (same client_uuid / server lookup by uuid; admin/pos.php doParkSubmit ~:7911, posParkSendSignature) so no second tab is created; no new screens. (2) Lock order: voidBookingCharge (config/database.php ~:9721) and the KDS 86 / void_item path (api/kds-action.php ~:1245) lock booking and order rows in opposite order: make both use the same order (pick the one that changes fewer lines). (3) Verify the per-dish "ready" notice includes the last dish of a round (kds-action.php ~:213 kds_maybe_notify_ready); fix only if it really skips it. (4) Liwonde has no menu items: add a small made-up test menu at Liwonde only (never Rosalyn's), through the normal admin path or a Liwonde-guarded seed, and make scripts/tests/pos_kds_stock_accounting.php + smoke_test_pos_kds.php run on a non-empty Liwonde DB (cleaning up what they create) instead of refusing. Money parts reviewed by dept-accounting-lead. Tests: pos_kds_step9, stock_pos_restore_8c and the two smoke tests at Liwonde. Gate: qa-auditor opus; browser check POS till + KDS at Liwonde 390 / 768 / 1400 (now with a menu).

**Step 15 - Rate plan leftovers from 8h.** dept-rooms-lead (Opus); money gate dept-accounting-lead. FIRST ask the owner (the rest of the step does not wait for the answer): "Merge Rosalyn's 3 festive rate plans into one, like Liwonde? Prices stay identical; live data change, backup first. Without it the next mirror run undoes Liwonde's merge." If yes: backup, merge exactly as Liwonde (one plan with per-room-type amounts; the other two deactivated and renamed "- old, merged"), compare prices before/after per room type and date (must be identical). Build: (1) the free-room guard in rh_rate_plan_validate_rooms (includes/pricing.php) also checks joined-room pair prices (room_combinations), so no plan can bring a joined room to 0 or below; (2) the JS preview in admin/rate-plans.php rounds exactly like PHP (or shows the server preview figure), so preview = saved price; a failed preview fetch shows a plain message in the modal; (3) Booking History (includes/booking-history-format.php) lists only the room types actually on that booking for a per-room-type plan; (4) room-type chips / tick boxes in the rate plan modal at least 44px tap height. Tests: rate_plan_room_adjustments.php (pure + --db at Liwonde), rp3. Gate: qa-auditor opus + browser check of the modal at Liwonde 390 / 768 / 1400.

**Step 16 - Filtering leftovers from 8g.** ui-lead (Opus), or inline if under ~10 tool calls. (1) The Bookings filter notice says "Search filtered results" for any filter: say what is filtered (e.g. "Filtered: Cancelled · Group bookings") from the active filters (admin/bookings.php, admin/includes/bookings-list-filters.php). (2) Payments active-filter chip Clear links are 32px at tablet width: make them 44px tap targets (admin/payments.php / its CSS). (3) Payments "Recorded by" can also pick System / API (payments with no staff user; admin/includes/finance-filters.php). (4) Bookings and Payments "More filters" use one shared design (same toggle, layout, chips): ui-lead picks the pattern (UI_DECISION) and applies it to both, no new visual language. Tests: bookings_filters.php, finance_filters.php (pure). Gate: qa-auditor + browser check 390 / 768 / 1400 at Liwonde.
