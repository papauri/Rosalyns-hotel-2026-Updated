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
3. **Ship A (R1 + R2 + R3).** Rooms lead builds, accounting lead builds the money parts, logs lead adds History. QA opus once.
   Browser check at Liwonde. Ship to both hotels.
4. **Ship B (R4).** Accounting lead plus ui-lead. QA opus once. Ship.
5. **Ship C (R5 + R6).** Rooms lead plus accounting lead. QA opus once. Ship.
6. **Reconcile test at Liwonde.** Rebuild an ICTAm-like group. Exercise early arrival, late checkout, early departure, a no-show room, a
   primary switch, add payment, bundle and unbundle. Group balance = Σ rooms, EOD and Still owed match.
7. Then the parked item 1 (stash@{0} at Liwonde).

Each ship: one builder per area, one QA, one fix round, `/compact` between ships, STATUS block, `/clear` after step 6.

## Current model
(step 0 fills this in)

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
