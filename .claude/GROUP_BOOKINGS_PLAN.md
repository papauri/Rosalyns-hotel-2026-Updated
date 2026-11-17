# Group bookings: per-room changes, group totals, bundling, add payment (owner 2026-11-17)

Owner, verbatim: "For group bookings like ICTAm at Rosalyn it turns out that some guests checked in or out before or after
the required day and therefore we need to make adjustments to individual rooms as this affects what we are owed. We
therefore need better logic on group bookings. One of those other things is to be able to group a group booking and show
totals of what was paid and what is owed or be able to switch any room into a primary room and also for the payment logic
in Payments accounting be able to add payments on bookings and not just take payments. This is critical. Be able to also
select bookings and bundle them into a group booking if they are not already or remove some rooms and accounting logic
and logs remain accurate. Make sure we get this right."

Priority: this comes BEFORE the parked item 1 in TASKS.md.

## Rails (every brief repeats these)
- ROSALYN'S IS LIVE. Build and test at LIWONDE only (mirror of the bookings, made-up guests, sending muted). Never write to Rosalyn's DB.
- Money is human-confirmed: preview → confirm. Booked rate × nights is shown everywhere ([[show-booked-rate-times-nights]]).
- Every write goes into the booking/payment History (who / when / old → new). Linked rows follow their parent; money rows are kept.
- Existing rules stay: per-room invoice and receipt per room for groups; cancellation tiers (individual vs group, locked at
  booking); joined rooms; refunds dated by payout day; no-show is earned per policy.
- No cron. Plain words, no AI wording. 10-per-page lists. Every column and card gets a "?" helper.

## Requirements → acceptance
R1 **Per-room dates in a group.** One room can arrive early or late, leave early or late, or be a no-show, without moving the others.
   That room's nights, booked rate × nights, balance and owed figures recalculate. The group totals follow. Invoice, receipt, EOD and
   Still owed stay right. Early departure follows the cancellation/no-show policy for the unused nights (the accounting lead decides
   and shows it before confirm).
R2 **Group view.** One place shows every room: nights, rate × nights, paid, owed, status. Group totals: billed, paid, owed, refunds.
   Filters land on rows.
R3 **Make any room the primary room.** It moves the group's primary-room role, contact and folio pointer. No money moves.
   The History records it.
R4 **Add a payment to a booking from Payments / Accounting.** Search a booking or group, pick a room or the whole group, then record
   the payment through the same path as take-payment: same receipt numbering, allocation, overpay guard, History and EOD.
   OWNER (2026-11-17): BOTH, i.e. record payments received AND add extra charges (see D-R4).

R5 **Bundle bookings into a group.** Select bookings in the list and press "Make a group". Choose the primary room. Payments already on
   each booking stay with that booking's room; group totals add them up.
R6 **Remove rooms from a group.** The room becomes an individual booking with its own payments, balance and invoice. The cancellation
   tier is recalculated or kept? The accounting lead decides (locked-at-booking rule). History is on both sides.
R7 **Accounting stays exact after any R1/R3/R5/R6.**
   - Sum of room balances = group balance.
   - No payment lost or double counted.
   - Invoices and credit notes are reissued per the existing per-room document rules.
   - Reports, EOD and Still owed reconcile.

## Who (department leads, max 2 building at once)
- dept-rooms-lead (Opus): group model, per-room dates, primary switch, bundle/unbundle, booking screens.
- dept-accounting-lead (Opus): balances, allocation, Add payment in Payments/Accounting, invoices, credit notes, refunds, early-departure money rule,
  EOD/Still owed reconcile. Reviews every money change.
- dept-logs-lead (Sonnet): History entries for every new action.
- ui-lead (Opus): one group view pattern + Add payment modal, phone widths.
- dept-eod-lead: told only if EOD figures change (grep overlap).
- qa-auditor (Opus): ONE review per ship.
- Fable 5.2: ONE spec check (case a) of this plan + the leads' decisions before any money build, ONLY if Fable is available
  (weekly limit resets Fri 20 Nov 2pm). If it is not available, skip it and say so. Never use Fable for building.
- Guides + Assistant: LAST, only when the owner prompts. Log entries in GUIDES_AI_SYNC.md.

## Steps
0. **Facts first (inline, about 10 tool calls, no agent).**
   - Read the ICTAm group at LIWONDE: its rooms, dates, payments, how the group is stored.
   - Grep CODE_INDEX for group, primary, booking_rooms, take-payment and payment-add.
   - Write the current model (tables, columns, files, lines) into §Current model below.
   - (Owner questions already answered: see Decisions.)
1. **Spec decisions (rooms + accounting leads together, ONE agent each, read-only).** Settle the R1 early-departure rule, R5/R6
   money ownership, the R6 cancellation tier, and any schema change (migration, additive). Write them into §Decisions.
2. **Fable spec check** (if available). Fold its required changes into §Decisions.
3. **Ship A (re-cut 2026-11-17 from the Rosalyn's data; unblocks ICTAm).** Covers A9, A8 and A4, plus the A5 display line. One builder (dept-rooms-lead, Opus), QA opus once.
   - Correct the dates of a CHECKED-OUT room through `adjust_dates`, under the new permission `correct_stay_after_checkout` with a reason. Preview, then confirm.
   - Any money back goes out as a refund or a credit note, never as credit_balance. The cached invoice PDF is cleared.
   - A past-date clash gives a warning and a tick.
   - GUARD: this path refuses to cut nights on a room that HAS a policy snapshot, until Ship A2 lands. Liwonde has 0 snapshots today.
   - Group table on booking details with group totals and the net position line. It replaces the old group total.
   - A9: fix the checkout time at bookings.php:1731.
4. **Ship A2 (D-R1a).** A1, A2 and A3 on all six paths, late arrival included (owner answer 2). The A8 guard is lifted. Tell dept-eod-lead about the "Kept for unused nights" line.
5. **Ship B (R4).** A7 first: a no-show or cancelled room that still owes can take a payment. Then open the group charge from Payments / Accounting, and the search fixes (deleted rooms, room x of N).
5b. **Ship C (R3 + R5 + R6 + the A5 move-credit action).** ONE A6 re-point helper with three callers. D-R6 lump-sum move = A5's issue + apply pair.
5c. **Owner answer 3 backfill at Rosalyn's**, after Ship A ships.
   - One UPDATE guarded by the 15 ids, status 'checked-out' and checkout_completed_at IS NULL.
   - The time comes from the log converted UTC→local (log 14:15 = local 16:15).
   - Ask the owner at the permission prompt.
6. **Reconcile test at Liwonde.** Rebuild an ICTAm-like group. Exercise early arrival, late checkout, early departure, a no-show room, a
   primary switch, add payment, bundle and unbundle. Group balance = Σ rooms, EOD and Still owed match.
7. Then the parked item 1 (stash@{0} at Liwonde).

Each ship: one builder per area, one QA, one fix round, `/compact` between ships, STATUS block, `/clear` after step 6.

## Current model
(step 0, 2026-11-17, read-only at Liwonde + code grep)

**Storage.** A group is N `bookings` rows. The lead row has `primary_booking_id` NULL; every other room has
`primary_booking_id` = lead id (column added in config/database.php:9500). There is no group table. Each room keeps its
own `total_amount`, `total_with_vat`, `amount_paid`, `amount_due`, `credit_balance`, `folio_charges_total`, `payment_status`,
`cancellation_policy_snapshot` and `billing_account_id`. `payments` rows point at ONE booking (`booking_id`, `booking_type='room'`).
- Helpers in config/database.php:
  - `rh_booking_group_members` (~11095): live rooms only, not cancelled / no-show / expired / checked-out;
  - `rh_booking_group_ids` (11135);
  - `rh_booking_group_doc_lead` / `rh_booking_group_effective_lead` (11145+): a cancelled lead hands its documents to the
    next room, read-only, no re-parenting;
  - `rh_cancel_booking_group` (11213+).

**ICTAm at Liwonde** (root 2100008837, ref RBH2026039972):
- 16 rooms, all 12→15 Nov, 3 nights, 909,000 + VAT = 1,066,500 each.
- 15 rooms are still `checked-in` on 17 Nov (overdue checkout); 1 is `no-show`.
- 15,000,000 was paid as a lump sum and split evenly: 937,500 on every room, including the no-show. Each room owes 129,000.
- No folio charges. No billing account.

**What already exists (do NOT rebuild):**
- Per-room date change: booking-details.php:1287 `adjust_dates` → `processBookingDateAdjustment` (database.php:11916).
  It changes check-in and/or check-out at the booked rate, re-prices, and logs the timeline.
  - Allowed for any status except checked-out / cancelled / no-show / expired (booking-details.php:1687).
  - It does NOT apply cancellation tiers to removed nights; a lower bill becomes credit_balance.
- In-house add / take off nights: includes/stay-nights.php, bookings.php:2904 `stay_nights_quote/apply`.
  - Nights are added or taken off at the END only. Money back goes as a refund or a credit note (owner D4 2026-11-13).
- Checkout settlement for early / late departure: bookings.php ~2040-2300.
  - Unused nights: refunded or kept. Extra nights: charged or waived.
  - `admin_update_checkout_date` (bookings.php:3135) corrects the departure after the guest has left.
- No-show per room in a group: tests group_noshow_charge.php; `rh_noshow_fee_tagged`.
- Group payment: admin/includes/group-payment.php (split / preview / record / history), and admin/payment-add.php with the
  "This room only / Whole group" scope (`groupScope`, `requestGroupPreview`). Payments has a quick action to it
  (payments.php:572), as do the Accounting dashboard (accounting-dashboard.php:1725) and the per-row "Record payment" (payments.php:1098).
  So **R4(a) mostly exists**.
- Group charge: admin/includes/group-charge.php (plan / preview / nonce / post), reachable ONLY from booking-details.php:154
  (Add charge, group mode). **R4(b)** exists on booking details but not from Payments / Accounting.
- Group display: booking-details.php:2121 is a notice with linked-room links and a group total (full total incl. VAT +
  levy). It has no per-room paid / owed table. bookings.php:280 `booking_group_info` is a read-only member list for the
  cancel / delete dialogs.

**ICTAm at Rosalyn's** (read-only SELECT, owner-approved 2026-11-17; the same root 2100008837):
- All 16 rooms are still dated 12→15 Nov. No date change was ever recorded.
- On 13 Nov the rate was corrected to 355,500 per night (owner-approved). 355,500 × 3 = 1,066,500 per room.
- Payments are 16 × 937,500 by bank transfer on 7 Nov, one payment row per room (the lump-sum split).
- The no-show was marked on 13 Nov; it owes 129,000 and has 937,500 paid.
- On 15 Nov, 15 rooms were checked out at 14:15–14:19, each "with MWK 129,000 outstanding (authorised)".
  - Group owed: 16 × 129,000 = 2,064,000.
- **Root gap:** once a room is checked out, its dates cannot be corrected:
  - `can_adjust_dates` excludes checked-out (booking-details.php:1687);
  - `admin_update_checkout_date` is checked-in only (bookings.php:3135).
  So the early and late moves were never put in, and the money owed is the booked figure.
  - R1 must therefore work on CHECKED-OUT group rooms too, as an after-the-fact correction.
- **Side fault:** 15 of the 16 checked-out rooms have `checkout_completed_at` NULL.
  - bookings.php:1731 sets status 'checked-out' without it.
  - Housekeeping and review emails read it. Fix it in Ship A (one line).

**Not there yet:**
- R2: a per-room group table with paid / owed / refunds and group totals.
- R3: switching the primary room.
- R5: bundling.
- R6: removing a room.
- D-R1a: unused nights charged by the locked cancellation tier, with an override and a reason.
- Moving a credit on one room to the rooms that still owe in the same group. Example: an early-departure room has credit
  while the other rooms owe 129,000.

## Decisions
**Owner answers, 2026-11-17, before the session. These are settled; do not re-ask.**
- **D-R4 Add payment = BOTH.** From Payments/Accounting staff can:
  - (a) record money received against an EXISTING booking or group, choosing a room or the whole group, through the same path as take-payment;
  - (b) add an extra CHARGE to a booking (late checkout, damages, extras), with a reason, as a preview → confirm. It shows in owed, folio and invoice.
- **D-R1a Early departure:** unused nights are charged by the existing cancellation tiers (group or individual, locked at booking). The amount is shown before confirm, and staff can override with a reason recorded.
- **D-R1b Early arrival / late checkout:** an extra night defaults to the room's booked nightly rate. Staff can change it, with a reason recorded. The screen shows rate × nights.
- **D-R6 Removing a room from a group:**
  - payments recorded on that room go with it;
  - for lump sums paid to the group, staff choose how much (if any) moves to the room, shown before confirm;
  - group and room totals must still add up exactly.

(Step 1: the leads add the remaining decisions here: the R5 cancellation tier when bundling, the R6 tier after removal, and any schema/migration.)

### Step 1 decisions (accounting lead, 2026-11-17)
Fable spec check (step 2): skipped, unavailable until Fri 20 Nov 2pm.

**A1 D-R1a: one shared helper.** Add `rh_unused_nights_quote(PDO, array $b, string $newIn, string $newOut, string $noticeDate)` and `rh_unused_nights_fee_post(...)` to includes/cancellation-policy.php.
- Do not reuse `rh_cancellation_fee_quote` (:450). It charges `confirmed` rooms only, and its base is the whole stay.
- Unused nights = max(0, old nights − new nights). Moving the dates without changing the count is not a cut.
- Base = unused nights × the booked per-night gross (`rh_booked_stay_split`). The screen shows it as rate × nights.
- The % comes from the row's OWN snapshot tier (`rh_cancel_policy_tier`, :396).
  - days = first unused night − notice date.
  - The notice date is the leave date for checkout and for after-checkout corrections, never the day staff key it in.
- The fee is capped at the bill reduction (old gross − new gross).
- Pending or tentative rooms pay 0. A room with no snapshot (NULL) pays no fee, as today.
- Staff can override to any amount from 0 up to the cap. A reason is required when the amount differs from the quote. The save re-quotes under the row lock.
- It applies to group AND individual bookings. The kind comes from the snapshot.

**A2 Paths that must call it, so staff cannot route round it.** Found by grep of `rh_reprice_at_booked_rate(` and `rh_write_booking_stay_totals(`:
- `processBookingDateAdjustment` (database.php:11916, writes at :11961/:11994);
- `rh_stay_nights_quote` / `rh_stay_nights_apply` (stay-nights.php:40/173);
- checkout settle `leave` (bookings.php:1992-2005) and checkout_assess;
- `admin_update_checkout_date` (bookings.php:3198);
- modify (bookings.php:1378) and edit-booking.php:481, but only when they cut nights.

These are exempt:
- Extend stay (bookings.php:3082) only adds nights.
- Room change (booking-room-change.php:447) keeps the count.

`adjust_dates` must stop leaving money in credit_balance. Copy the L1 guard (bookings.php:3205-3212): when money would come back, staff use a refund or a credit note.

**A3 How the kept amount is booked: a folio row.**
- The row goes in `booking_charges` with charge_type `other` and its own tag `[unused-nights:<id>]`.
- It must NOT use `[noshow-fee:]`. That tag flips billing (database.php:10333, receivables.php:37).
- `cancellation_retained_amount` is not usable here. It is read only when status = cancelled (database.php:10326/10499, invoice.php:977).
- VAT and levy: use `rh_room_night_share` (database.php:10029). Extend the levy readers to the new tag for every status:
  - getBookingFolioSummary extras levy (database.php:10339);
  - invoice.php:1130-1140.
- Post the row BEFORE the recalc. Then the refund, credit note and owed figures already include it.
- EOD counts the row once, on the day it is posted, as folio "other" (eod-revenue.php:164-172). Tell dept-eod-lead: it needs its own line, "Kept for unused nights".

**A4 R2 group table.**
- Rows: `(id = root OR primary_booking_id = root) AND deleted_at IS NULL`, all statuses. Root = `COALESCE(NULLIF(primary_booking_id,0), id)`.
- Do not use `rh_booking_group_members`. It drops rooms (database.php:11113).
- Per room, every figure comes from `getBookingFolioSummary` (database.php:10256):

  | Column | Source |
  |---|---|
  | Billed | grand_total |
  | Paid | amount_paid (canonical net) |
  | Owed | balance_due |
  | Credit | the rule at booking-details.php:1574-1577 |
  | Refunds | refund rows that are completed/processing with method <> 'credit_note' (money paid out). Credit-note legs go in the "?" hint. |

- Group = Σ rooms, for every column. The test asserts that each room's row equals that room's own booking page.
- Replace the group total at booking-details.php:2154-2175 with the table's Σ billed. That figure uses `rh_booking_full_total`, which misses the folio and the cancelled bases. Never show two different group totals.

**A5 Credit on one room while others owe.** Show both:
1. A display line: the group's net position = Σ owed − Σ credit.
2. An action: "Use this credit for the rooms that owe". Preview → confirm, with History on both sides.
   - First, issue a credit note on the room with the credit (`recordCreditNoteRefundLegs`, credit-notes.php:529). This zeroes its credit_balance.
   - Then use `applyCreditNote` (credit-notes.php:229) on each owing room. Split the amount with `rh_group_pay_split` (group-payment.php:141).

Rules:
- Never re-allocate existing payment rows. It would break the receipts, the refund lineage and the VAT split.
- EOD already leaves credit-note rows out (eod-revenue.php:61/410).
- Refuse a move between different payers (company vs guest), the same way group payment refuses on-account groups.
- D-R6 "move part of a lump sum to the room being removed" uses this same issue + apply pair.

**A6 Re-pointing `primary_booking_id` (R3, R5, R6).**
One transaction, with every row locked. It covers EVERY row with that root: cancelled, checked-out, no-show and deleted too.
- Point every room of the old root at the new one: `UPDATE bookings SET primary_booking_id=new WHERE primary_booking_id=old`.
- Set the new root's own `primary_booking_id` to NULL.
- Point the old root at the new one.

What happens to each item:

| Item | Decision |
|---|---|
| Invoice number `rh_invoice_group_number` (invoice.php:196) | STAYS: the group keeps the number it had before the switch, and each room's own number (payments.invoice_number, invoice.php:2068-2120) is untouched. The lookup prefers the root's `final_invoice_number`, so if the new root holds a different one the number would flip. That case needs OWNER? 4. Nothing is reissued. |
| Billing account (billing-accounts.php:137/394/802) | MOVES. Copy the 4 billing columns to the new root if it has none. Refuse if it holds a different account. |
| Group no-show fee root (database.php:10163-10170) | MOVES by itself (derived). Fee rows already posted STAY: their tag uses the room's own id. |
| Cancellation snapshot | STAYS on each row. |
| Group-payment split | STAYS. No row moves. Only future overpayments go to the new lead. |
| Doc lead `rh_booking_group_effective_lead` (database.php:11181) | MOVES by itself. |
| Guest history (guest-history.php:85-93) | MOVES by itself. The group still counts as one stay. |
| Overdue chase | MOVES. Details below. |

Billing account on R5 and R6:
- R5: refuse when the primary is on a company account and a joining room is not on that same account.
- R6: copy the inherited billing columns onto the leaving room, so its payer does not change.

Cancellation tier on R5 and R6 (locked at booking):
- R5: each booking keeps its own snapshot. An individual booking stays individual.
- R6: the removed room keeps its group snapshot.
- When a group's rooms have different snapshots, group documents print each distinct text. Today cancellation-policy.php:357 prints the lead's text only.

Overdue chase:
- `automated_email_log` is UNIQUE(job, account_type, account_id, stage). The key is the root for a group (auto-email-jobs.php:471) and the booking id for a single (:464).
- In the same transaction, copy the sent rows to the new key with INSERT IGNORE. Keep the old rows, so no stage is sent twice.
- The same applies on R5 (each single's key → the group key) and R6 (the group key → the leaving room's own key).

**Schema: none. No migration.**

**A7 R4 gaps.** api/search-bookings.php:52-119 has no status filter, so it already finds checked-in, checked-out-with-balance and no-show rooms. Gaps:
- No `deleted_at` filter: deleted rooms show up, and payment-add.php:570 then refuses them. Exclude them, and show "Room x of N, group <lead ref>".
- The recent list is limited to rooms created in the last 30 days (LIMIT 10). Search returns at most 20 rows.

What could block recording a payment on a group room:
- **BLOCKER:** `bookingAllowsAction(..., 'record_payment')` (payment-add.php:576) checks admin/includes/booking-lifecycle.php:11 and its record_payment branch.
  - It refuses every no-show room, and every cancelled room with a fee still owed (status is "terminal").
  - Example: ICTAm's no-show room owes 129,000 and cannot take a "This room only" payment.
  - Checked-out rooms with a balance are allowed.
  - Fix in Ship B: allow terminal statuses while amount_due > BALANCE_TOLERANCE, the same rule as checked-out and as rh_receivable_status_sql.
- A whole-group payment refuses a pending payment and a company-billed (on-account) group (group-payment.php:326 and its header).

R4(b), adding a charge from Payments: reuse group-charge.php. Today it is reached only from booking-details.php:154.

**A8 Correcting dates on CHECKED-OUT rooms (ICTAm at Rosalyn's).**
Which path to open:
- Open `adjust_dates` → `processBookingDateAdjustment` for `checked-out` rooms, behind a NEW permission `correct_stay_after_checkout` (default admin and manager).
- The gate at booking-details.php:1687 allows checked-out rooms only with that permission.
- A reason is required, and it is preview → confirm.
- Never for cancelled, expired or deleted rooms. A no-show uses the no-show/arrival path, not this one.

Money on this path:
- D-R1a: the fee comes from the A1 helper, with notice = the actual leave date.
- D-R1b: extra nights at the booked rate. Staff can change the rate with a reason.
- A lower bill first clears what is owed. Any money that must go back is a refund or a credit note, chosen in the dialog. Never credit_balance.

Documents and reports:
- Receipts never change: they record money received.
- An issued final invoice does not block the correction:
  - the room's invoice is regenerated under the same number, as today. invoice.php:2086-2104 reuses a cached PDF, so the correction must clear that room's `invoice_generated` / `invoice_path`, or the old figures keep printing;
  - if the new bill is below what was paid, a credit note is issued;
  - the preview names the invoice number.
- Past EOD days re-work their room nights live (eod-revenue.php:118-125). Tell dept-eod-lead: EOD emails already sent will no longer match.
- Extra nights in the past can clash with another booking on that room. The rooms lead owns this: show a warning and a tick, not a block.

**A9 Side fault at bookings.php:1731.** Fix it in Ship A: set `checkout_completed_at = NOW()` in that UPDATE, as processGuestCheckout does. It moves no money. Backfilling the 15 rooms at Rosalyn's changes live data, so it needs the owner (OWNER? 3).

**OWNER?**
1. A booking without a policy snapshot gets no automatic unused-nights fee. This happens when the policy was off at booking, likely ICTAm. Default: no fee; staff use the D-R4(b) charge with a reason.
2. Checkout's "charge only the nights actually stayed" for a late arrival (late-stay.php:146, owner 2026-11-12) also drops nights at the start. Default: the A1 tier applies there too, so it cannot be used to route round the policy.
3. Backfill `checkout_completed_at` for the 15 ICTAm rooms at Rosalyn's from the time in the status-change log. Default: yes, as an owner-approved one-off.
4. A primary switch where the new root holds a different `final_invoice_number`. Default: the switch keeps the old group number by copying it onto the new root. That room's own number stays on its payment row.

The new permission `correct_stay_after_checkout` is a code-list entry (admin/includes/permissions.php:448 pattern), not a table seed. Schema stays none.

**Owner answers to the OWNER? questions (2026-11-17, in session), settled:**
1. A booking with no saved policy snapshot gets NO automatic unused-nights fee. Staff use a D-R4(b) charge with a reason. This includes ICTAm: 0 of its 16 rooms have a snapshot.
2. Late arrival: the tier DOES apply to nights dropped at the start (late-stay.php "charge only nights stayed" included). Staff can override with a reason.
3. Backfill `checkout_completed_at` on the 15 ICTAm rooms at Rosalyn's from their "Guest checked out" / status log time.
   - This is a one-off, owner-approved live-data fix.
   - Only after the code fix ships.
   - 15 rows, no money.
4. Primary switch: the group keeps its old group invoice number (copied onto the new root). The room's own number stays on its payment.
