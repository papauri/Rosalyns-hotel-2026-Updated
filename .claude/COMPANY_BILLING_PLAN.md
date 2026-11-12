# Company pays later (on account / LPO) + nights instead of amounts — design

Status (2026-11-13): Phase 1 SHIPPED (Rosalyn's ebf7e9f, Liwonde 20755de). Phase 2 BUILT, not committed: migration 084 applied at Liwonde only; Rosalyn's after QA (orchestrator). Remaining LATER items are in BUILD_PLAN.md.
Owner ask (verbatim in TASKS): payment type for people paying later through their company; "+1 night" / "take off
nights" instead of typing amounts; amounts more guarded than one click.
Real case: RBH2026461210 VICTORIA NYAHODA (id 2100008835), checked-in 10→12 Nov, 900,600.00, paid 0,
`checkin_without_payment=1`, note "MASM (LPO)", staying past 12 Nov. ROSALYN'S IS LIVE: tests on Liwonde only.

---

## 0. What exists today (verified)

| Piece | Where | What it does / does not do |
|---|---|---|
| Pay-later switch (mig 083) | `admin/migrations/083_booking_checkin_arrangement.php`; `config/database.php` 2790 `rh_checkin_arrangement_columns_present`, 2836 `rh_checkin_arrangement_exclude_sql`, ~2883 `rh_checkin_payment_gate`, ~2907 `validateCheckIn`, ~2965 `logCheckInUnderArrangement`; 303-313 `rh_unpaid_confirmed_deadline` | Per booking switch + free-text note. Relaxes the check-in payment gate only; excluded from unpaid-confirmed release/reminder and overdue reminders (`includes/auto-email-jobs.php` 134, 197, 643, 727). Header: does NOT relax checkout-with-balance. No company, no LPO number, no due date. |
| Switch UI | `admin/booking-details.php` 432-494 (save, `edit_booking`, History old→new), 1795-1812 (panel); tags `admin/bookings.php` 3994, `admin/dashboard.php` 2517-2534 | |
| Company on a booking | none | `bookings` has no company / bill-to / LPO column. Only conference_inquiries has `company_name`. No company/debtor table. **A migration is needed.** |
| Extend stay | `admin/bookings.php` 2568-2697 `extend_stay`; modal 6599, JS 8465 | Takes a DATE. Gate `rh_validate_booking_stay_change` (database.php 6113, joined rooms + blocks + locks). Reprice `rh_rate_plan_dropped` + `rh_reprice_at_booked_rate` (10764) + `rh_write_booking_stay_totals` (10732) + `recalculateBookingFinancials` (9620). Preview `bookings_stay_rate_preview` (bookings.php 145, same functions as the save). **No permission gate** (`rhBookingActionPermission`, permissions.php 2814, has no case); one click after the preview; History only gets a `booking_status_log` line with the new total — no `logBookingAudit` old→new. Sends no guest email. |
| Checkout-date correction | `admin/bookings.php` 2698-2808 `admin_update_checkout_date` (admin only) | Can shorten. See finding L1. |
| Booking Details "Adjust dates" | `admin/booking-details.php` ~1110-1170 → `processBookingDateAdjustment` (database.php 10851) | Emails the guest `sendExtendStayEmail` (config/email.php 5867) "Additional Amount Due ... arrange payment" whenever guest_email is set. |
| Late / early checkout settlement | `includes/late-stay.php` (`rh_late_stay_quote` 146); `admin/bookings.php` 1711 `checkout_assess`, 1822 `checkout_settle` | Booked rate × nights; extra nights billed, payment only for "amount received"; unused nights refunded in full (`allocateBookingRefunds`, refund rows 'completed'); then `evaluateCheckoutBalance` (database.php 10292) → `checkout_with_balance` needed if anything owed. |
| Final invoice at checkout | `admin/bookings.php` 98 `bookings_final_invoice_note` → `config/invoice.php` 1881 `generateAndSendFinalInvoice` → 1941-1960 `sendFinalInvoiceEmail` (2060) | Idempotent; "Billed to" = guest_name (invoice.php 1015); **emailed to guest_email with "FINAL INVOICE — Balance due"**, nothing skips pay-later bookings. |
| Manual consolidation | `admin/bookings.php` 2130 `consolidation_fetch`, 2219 `consolidation_record` (perm `payment_add`), modal 6586 | ONE booking per payment: lock, recalc, `bookingAllowsAction`, `rh_payment_limit_error`, `rh_account_vat_split`, `finance_next_receipt_number`, `rh_auto_credit_overpayment`. No multi-booking allocation anywhere. |
| Receipt numbers | `includes/finance-sequences.php` 230 | Unique per payment row (app-checked). One remittance across N bookings = N payment rows = N receipts. |
| Receivables | `admin/includes/receivables.php` 34-104 `rh_receivable_rows` | One rule for Dashboard "Owed to us", Accounting, Reports aging, Invoices outstanding, EOD "Still owed". Room `client` = guest_name; age = days since checkout. |
| Overdue reminders | `includes/auto-email-jobs.php` 129-143, 194-199 | Room: chases guest_email after checkout; pay-later excluded. |
| Payment edit guard | `admin/includes/payment-edit.php` 137 | Required reason, permission, one transaction, History old→new. Pattern to copy. |

---

## 1. Data (migration 084 — needs owner OK at Rosalyn's; additive, re-runnable, like 083)

Nothing existing can hold this (no company table; `checkin_arrangement_note` is free text — parsing "MASM (LPO)" is not
acceptable for money). Exactly:

**New table `billing_accounts`** (the companies that pay later)
`id`, `name` VARCHAR(150) NOT NULL, `contact_name` VARCHAR(150) NULL, `billing_email` VARCHAR(190) NULL,
`billing_cc` VARCHAR(255) NULL, `phone` VARCHAR(50) NULL, `address` VARCHAR(255) NULL, `tax_number` VARCHAR(60) NULL,
`terms_days` SMALLINT NOT NULL DEFAULT 30, `covers` ENUM('stay','everything') NOT NULL DEFAULT 'stay',
`active` TINYINT(1) NOT NULL DEFAULT 1, `notes` VARCHAR(255) NULL, `created_by`, `created_at`, `updated_at`.
UNIQUE(name). Never deleted (deactivate only — money history).

**`bookings` + 4 columns** (only when missing)
`billing_account_id` INT NULL (FK-less index; NULL = guest pays), `billing_reference` VARCHAR(80) NULL (LPO / order no.),
`billing_due_date` DATE NULL (NULL = checkout date + account terms), `billing_covers` ENUM('stay','everything') NULL
(NULL = the account default).

**New table `billing_remittances`** (one company payment received)
`id`, `billing_account_id`, `remittance_reference` (e.g. `REM-2026-0001`, own sequence in finance-sequences),
`received_date`, `payment_method` (payments enum), `bank_reference` VARCHAR(80), `amount` DECIMAL(12,2),
`notes`, `recorded_by`, `created_at`, `voided_at` NULL, `voided_by` NULL.

**New table `billing_remittance_lines`** (its split across bookings)
`id`, `remittance_id`, `booking_id`, `payment_id` (the normal room payment row created for this line), `amount`.
UNIQUE(payment_id). This is how we know which payments are the company's — the `payments` table is NOT altered.

Linked data (owner 2026-10-19): add `billing_remittance_lines.booking_id/payment_id` and `bookings.billing_account_id` to
`.claude/plans/linked-data-cleanup.md` (KEEP — money) and `scripts/data-health.php`.
Until 084 runs every new screen is hidden (probe like `rh_checkin_arrangement_columns_present`).

## 2. Relation to the 083 switch — MERGE, one rule

- `checkin_without_payment = 1` stays THE pay-later rule (check-in gate, no pay-by deadline, never chased).
- "On account" = that switch ON **plus** `billing_account_id`. Setting a company turns the switch on and writes the
  note automatically ("On account — MASM, LPO 12345"); the note stays editable for other arrangements.
- "Owner's stay" / ICTAM-style = switch ON, no company (works exactly as today).
- Removing the company leaves the switch as staff set it (History old→new for each field).
- One helper decides everything else: `rh_booking_billing(array $b): array{on_account, account_id, account_name,
  reference, due_date, covers}` in a new `includes/billing-accounts.php`. No screen reads the raw columns.
- Booking Details panel becomes "Pay later": [switch] · Who pays: Guest | Company [picker + "new company"] · LPO no. ·
  Due date (prefilled checkout + terms) · Company covers: Stay only | Everything. Setting/removing a company needs the
  new permission `billing_on_account` (default admin, manager, accountant) because it changes who is owed.

## 3. Money effects, place by place

The canonical columns do not change: `amount_paid`, `amount_due`, `credit_balance`, `payment_status`
(unpaid/partial/paid) stay as `recalculateBookingFinancials` computes them. On account is NOT a payment row and NOT a
payment status — no money exists until the company pays.

**Split helper** (`includes/billing-accounts.php`, `rh_booking_bill_split(PDO, bookingId)`):
stay = total_with_vat (cancelled: cancellation_retained_amount); extras = folio_charges_total;
company_paid = the LINKED PAYMENT ROWS themselves (join `billing_remittance_lines.payment_id` → `payments`: total_amount
of the leg under the canonical paid statuses, minus refunds whose `original_payment_id` is a leg, refund_status
completed/processing) — never `billing_remittance_lines.amount`, which is only the amount at the time of recording and
goes stale after a refund. Edit payment (`admin/includes/payment-edit.php`) is blocked on a remittance leg; corrections
go through void (refund lifecycle) and re-record, so the remittance total and its legs never drift;
guest_paid = amount_paid − company_paid. Guest money covers extras first, any excess reduces the company's share:
guest_extra_cover = min(guest_paid, extras); guest_stay_cover = guest_paid − guest_extra_cover.
covers='stay': company_due = max(0, stay − company_paid − guest_stay_cover); guest_due = max(0, extras − guest_extra_cover).
covers='everything': company_due = amount_due; guest_due = 0.
Invariant (tested): company_due + guest_due = amount_due within BALANCE_TOLERANCE. Not on account: guest_due = amount_due.

| Place | Change |
|---|---|
| Labels | One label helper `rh_booking_pay_label($b, $split)`: "On account — MASM (LPO 12345)" when company_due > tol and guest_due ≤ tol; "On account — MASM · guest owes K x" when both; normal Paid/Partly paid/Unpaid otherwise. Used by bookings.php list badge (`badgePay` 8659 + PHP 3994), booking-details, dashboard arrivals/in-house, front-desk-counts (`admin/includes/front-desk-counts.php` 232-239), individual-rooms, payment-details. DB `payment_status` unchanged so filters/reports keep working. |
| Check-in gate | Unchanged (on account sets the 083 switch → gate 'arrangement'). Log line names the company + LPO. |
| Checkout | `evaluateCheckoutBalance` (10292) gains the split: guest_due must be ≤ tol (or `checkout_with_balance`). company_due — **owner decision D2**. Until decided, on-account checkout still needs `checkout_with_balance` exactly as today. |
| Final invoice at checkout | `generateAndSendFinalInvoice` (invoice.php 1881): on account → "Billed to: MASM", "LPO: 12345", "Guest: Victoria Nyahoda", due date, banner "Due <date> — on account"; emailed to `billing_email` (+ billing_cc, + invoice_recipients CC), **never to guest_email**. Guest instead gets the paid-statement style "checkout complete" email with no balance (only if guest_due ≤ tol). No billing email → invoice generated, not sent, staff told. "covers=stay" with extras owed → the guest's own invoice lists only the extras (D1). Issued invoices are fixed: later nights changes issue a credit note (fewer nights) or a supplementary invoice (more nights) — never a re-render under the same number. |
| Receivables (Owed to us / Still owed / aging) | `rh_receivable_rows` (receivables.php 34) keeps **one row per booking** (callers checked: `rh_receivable_summary` receivables.php 192 counts rows for "N accounts" + buckets; `admin/dashboard.php` 970, `admin/includes/eod-revenue.php` 255, `admin/payments.php` 220 sum/list rows — two rows per booking would double the count). The room row gains `payer` ('guest' / 'company' / 'both'), `company_due`, `guest_due`, `account_name`; `client` = "MASM (guest: Victoria Nyahoda)" when on account; `days_outstanding` = days past the company due date when company_due > tol (0 = not due yet), else today's rule. amount_due unchanged, so every total stays identical. Summary adds `by_payer` (company / guest amounts); Dashboard / Accounting / EOD cards show "of which on company account K x" under the unchanged headline. |
| Company statement | New page `admin/billing-accounts.php` (list + per company): open bookings, LPO, stay dates, invoice no., amount, paid, due, due date, age; "Statement" PDF `STMT-ACC-<id>-<yyyymmdd>` via the existing document shell; send to billing email (manual button). |
| Consolidation (one company payment → many bookings) | On the company page: "Record company payment": amount, method, date, bank ref → allocation grid of that company's open bookings (company_due each, prefilled oldest-first, editable). Server, one transaction: create remittance; per line → exactly the `consolidation_record` sequence (lock booking, recalc, `bookingAllowsAction`, `rh_payment_limit_error` against company_due, `rh_account_vat_split`, receipt number, insert payment, recalc, History) + remittance line. Allocated total must equal the amount within tolerance (D5). After commit: per-payment receipts (existing `receipt_after_payment`) + one "Company payment advice" PDF listing them, to billing email. Void remittance = refund/void each leg through the existing refund lifecycle, never DELETE. Per-booking `consolidation_record` keeps working for guest money; on an on-account booking it asks "Paid by: guest / company" and the company choice creates a one-line remittance. |
| VAT / levy | Unchanged. Pricing never looks at billing. Each remittance leg is split pro-rata per booking (`rh_account_vat_split`). Levy stays on net room value. |
| EOD / Z / shift | No change: no payment row until money arrives; the remittance legs are normal payments on their received_date with their method. POS shifts untouched (front-office money). |
| Reminders / emails | Guest never chased (already true via 083 exclusion). Booking Details "Adjust dates" email (`booking-details.php` ~1160 → `sendExtendStayEmail`): on account → no "Additional Amount Due" block to the guest. Company chasing: manual "Send statement" first; optional in-app scheduler job (switch, permission, recipients + CC, off by default — no cron) (D6). |
| Reports | Aging by payer (company / guest); new "Company accounts" section: owed per company, buckets current/30/60/90+, remittances in period. Revenue/occupancy/ADR untouched (accrual by stay). |
| Assistant | Hand-off to dept-ai-lead only (`includes/ai/ai-lookups.php`, `ai-insights.php`, `ai-decisions.php` read amount_due): say "on account with MASM, LPO …, due …" via `rh_booking_bill_split`, never "unpaid / guest owes". SYNC QUEUE line. |
| Extras (room service, POS charge-to-room, folio) | Follow `covers`: 'stay' (default recommendation) = guest pays extras at checkout; 'everything' = company. POS charge-to-room unchanged (posts `booking_charges`). |
| Cancellation / no-show | Existing 3 modes unchanged. A retained first night on an on-account booking is owed by the company (stays in its statement). |
| Credit notes | A company overpayment on a booking cannot happen (limit check). Fewer nights after the company invoice is issued → a credit note on the booking (existing `issueCreditNote`, config/credit-notes.php 49, addresses the guest) printed with the company bill-to block + LPO: `rh_credit_note_pdf_html` (963) and `sendCreditNoteEmail` (1196) read `rh_booking_billing` and route to the billing email. config/credit-notes.php is in Phase 2. |
| Group bookings (several staff on one LPO) | Billing fields live on the PRIMARY booking only; members (`primary_booking_id` set) inherit them through `rh_booking_billing` (reads the primary when the member's own fields are empty). The split is computed per member booking (each has its own amount_due), the label shows on every member, the final invoice already combines the group (invoice.php ~112-157) and gets the company bill-to once, and reminders already skip members (`primary_booking_id IS NULL`). Setting/removing a company on the primary applies to the whole group (History on each). The remittance allocation grid lists members as separate lines. |

## 4. Nights instead of amounts

New `includes/stay-nights.php` (pure, mirrors late-stay.php): `rh_stay_nights_quote(array $b, int $deltaNights,
string $today)` → ok/error, old/new check-out, old/new nights, per_night (booked), line "K450,300 × 3 nights =
K1,350,900" (`rh_booked_rate_change_for_booking`), plan_note (`rh_rate_plan_dropped`), new_total, delta, balance
before/after, company/guest split after, direction (charge/reduce/refund/credit), money_back (paid − new bill, if > tol).
It calls `bookings_stay_rate_preview`'s functions so the quote IS the save's figure.

Actions in `admin/bookings.php` (next to extend_stay): `stay_nights_quote` (read-only) and `stay_nights_apply`.
- Applies to `checked-in` and `confirmed`; nights added/removed at the END of the stay only. Checked-in: new check-out
  ≥ tomorrow for +N; −N cannot drop nights already slept (new check-out ≥ today; = today → send them to Checkout,
  which already refunds via `checkout_settle`). Minimum 1 night.
- **+N**: `rh_validate_booking_stay_change` (joined rooms, blocks, maintenance, locks) inside the transaction; the
  quote shows availability read-only first. Same rate-plan drop as extend_stay. `extend_stay` and
  `admin_update_checkout_date` are rewired to call the same apply function (one code path, gains History).
- **−N, nothing paid / on account**: bill reduced, balance reduced, no money moves. If the company invoice was
  already issued → credit note to the company for the difference.
- **−N, paid booking, money goes back**: staff choose Refund (default, same as early checkout: extract the
  refund block of `checkout_settle` ~1951-2010 into `rh_refund_unused_nights()` and call it from both) or
  Credit note (`rh_auto_credit_overpayment`) (D4). Needs `refund_payment` for Refund.
- Guard rails (no one-click amounts anywhere):
  1. Step 1: a −/+ nights stepper (no amount field). Step 2: review card from the server quote: "N night(s) ×
     K per night = K amount", current → new total, current → new balance, who owes it (company/guest), availability,
     plan note, refund/credit amount and method.
  2. Confirm posts `expected_new_total` and `expected_delta`; server re-quotes under the row lock and rejects any
     difference > BALANCE_TOLERANCE ("figures changed — review again").
  3. Reason required for −N (≥3 chars, like payment-edit). When money goes back, staff retype the refund amount
     exactly (typed confirm on the money leg).
  4. Permissions: +N = as Extend stay today plus `edit_booking` for confirmed bookings; −N = `edit_booking`; −N with
     refund = also `refund_payment`.
  5. History: `logBookingEvent` + `logBookingAudit` old→new (check_out, nights, total_with_vat, amount_due,
     company_due, guest_due), quote line, reason, refund/credit refs.
- The same review-step pattern goes on the company payment allocation and on the per-booking consolidation
  "Record" (amount retyped when it differs from the balance).

## 5. Owner decisions — DECIDED 2026-11-13 (these override the recommendations below)

- DECISION D1: "Choose per booking": front desk picks per on-account booking whether extras go to the company or the
  guest; the room stay always goes to the company (so `billing_covers` per booking, no account default needed).
- DECISION D2: yes — on-account checkout skips `checkout_with_balance` for the COMPANY share only; guest share still
  paid or overridden.
- DECISION D3: migration 084 approved; Liwonde first, Rosalyn's after QA passes (orchestrator applies it).
- DECISION D4: front desk chooses refund or credit note each time; amount shown and retyped.
- DECISION D5: split exactly; excess refused.
- DECISION D6: manual statement + optional in-app scheduler job (OFF by default, own switch, recipients + CC,
  permission like `timed_report_emails`; no cron). Guest never chased for the company share.

### Original recommendations (kept for the record)

- **D1 What the company covers by default** — **"Stay only" (room + levy + packages); the guest pays extras at
  checkout; switchable per booking to "Everything".** Cost: the split helper + remittance link (designed above).
  "Everything only" would be simpler (no split) but a guest's bar bill would land on MASM's LPO.
- **D2 Checkout of an on-account booking** — **allowed without `checkout_with_balance` when only the company share
  is owed and the booking has company + LPO set; guest share still gated; History says "Checked out on account —
  MASM owes K x".** This is a new rule on top of the settled checkout gate, so it needs your yes. Abuse guard:
  setting "on account" needs `billing_on_account`.
- **D3 Migration 084** (tables `billing_accounts`, `billing_remittances`, `billing_remittance_lines`; 4 booking
  columns) at both hotels — **yes; additive only, Liwonde first.**
- **D4 Taking nights off a paid stay** — **staff choose Refund (default, full amount, like early checkout) or Credit
  note each time; on-account just lowers what is owed (credit note to the company if its invoice is already issued).**
- **D5 Company pays more or less than the allocated bookings** — **must allocate exactly; a round-sum excess is
  refused in v1 (record what matches, note the rest); short payments are fine (partial lines).**
- **D6 Chasing the company** — **manual "Send statement" now; optional scheduler job (off by default) emails overdue
  statements to the billing email + CC. The guest is never chased.**

ASSUMPTION (not a decision): guard rail = two-step review + server figure check + reason + retyped refund amount.

## 6. Build split (one builder at a time, Opus; accounting lead gates each; Liwonde-only DB tests)

1. **Phase 1 — nights (+N / −N), no migration, can ship first.** Files: new `includes/stay-nights.php`;
   `admin/bookings.php` (2 actions, rewire extend_stay 2568 / admin_update_checkout_date 2698, extract
   `rh_refund_unused_nights` from checkout_settle, nights stepper + review in the Extend modal 6599 / JS 8465);
   `admin/booking-details.php` (same control); `admin/includes/permissions.php` (action map). Reuse
   `rh_reprice_at_booked_rate`, `rh_rate_plan_dropped`, `rh_validate_booking_stay_change`, `bookings_stay_rate_preview`,
   `allocateBookingRefunds`, `rh_auto_credit_overpayment`. Tests: new `scripts/tests/stay_nights.php` (pure quotes:
   +1/+3/−1, packages, levy, rate-plan drop, child supplement, tolerance; harness rolled back on Liwonde: apply,
   stale expected_total rejected, joined-room conflict, refund legs, credit-note path, History rows) +
   `late_stay_options.php`, `booked_rate_line_test.php`. Money tier → full suite at ship.
2. **Phase 2 — on account (after D1-D3 + migration).** Files: `admin/migrations/084_billing_accounts.php`; new
   `includes/billing-accounts.php` (`rh_booking_billing`, `rh_booking_bill_split`, `rh_booking_pay_label`);
   `admin/booking-details.php` panel (merge with 083); `config/database.php` `evaluateCheckoutBalance` (if D2);
   `config/invoice.php` (bill-to, recipient routing); `config/credit-notes.php` (company bill-to + routing);
   booking-details adjust-dates email; label call sites listed in §3; `admin/includes/receivables.php` (payer
   fields on the one row + `by_payer`); permissions (`billing_on_account`). Tests: new
   `scripts/tests/billing_accounts.php` (split invariant across covers × guest deposit × extras × refund × cancel
   retained; label; invoice recipient = billing email never guest; reminder exclusion) + `checkin_arrangement.php`.
3. **Phase 3 — company page, statement, remittance allocation, reports, EOD line.** Files: new
   `admin/billing-accounts.php`, `admin/includes/billing-remittance.php`; `includes/finance-sequences.php` (REM
   sequence); `admin/includes/payment-edit.php` (block edit on a remittance leg); `admin/payment-refund.php` /
   `admin/includes/finance-refunds.php` (a refund of a leg is shown against the remittance); `config/credit-notes.php`; `admin/reports.php` aging/company section; accounting dashboard, dashboard, end-of-day card sub-lines;
   optional scheduler job in `includes/auto-email-jobs.php`. Tests: new `scripts/tests/billing_remittance.php`
   (N legs, VAT per booking, receipts, limits, exact allocation, void via refund lifecycle, History on each booking).
4. Hand-offs: dept-ai-lead (Assistant balance wording), ui-lead (UI_REQUESTs: nights stepper/review card, Pay-later
   panel, company page), guides once a day (staff-visible), artifacts-lead if bookings screens are mirrored.

## 7. Right now at Rosalyn's (no code, no data change by us)

She is checked in, booked 10→12 Nov, today 13 Nov → overdue. Options for front desk:
- **A. Extend stay** (Bookings list row → Extend stay, or Overdue check-outs → Extend Stay). Pick the date she will
  actually leave: the earliest allowed is 13 Nov and the default is 14 Nov. The preview shows the booked rate × nights
  (about K450,300 a night if there is no package or discount change: 13 Nov = 3 nights ≈ 1,350,900; 14 Nov = 4 nights ≈
  1,801,200. The preview figure is the one that counts). Room availability is checked. The pay-later switch and note
  stay as they are, and she is still never chased automatically. This route sends no email. Do NOT use Booking Details
  "Adjust dates", which emails her "Additional Amount Due".
- **B. Leave it and settle at checkout**: Check out → "Guest left on <date>" bills the extra nights at the booked
  rate with amount received 0.
- Either way, checking out with nothing paid needs someone holding **Check-out With Balance** (confirm step).
- Staff with Edit Booking can add the LPO number to her note on Booking Details ("MASM — LPO 12345").
- **Caveat:** at checkout the system creates the final invoice billed to "VICTORIA NYAHODA" with "Balance due" and
  **emails it to her guest email** (config/invoice.php 1941-1960, no pay-later skip). The front desk should tell her
  that MASM will settle it, then download the invoice PDF and send it to MASM themselves. Fixing this is Phase 2. If it must not
  happen, a small PATCH could skip the guest email for pay-later bookings (PATCH queue, needs your OK).

## 8. Findings logged while designing (LATER, not in this scope)

- L1 | medium | `admin/bookings.php` 2698-2808 `admin_update_checkout_date` | shortening a paid booking reprices and
  recalcs but never calls `rh_auto_credit_overpayment` or a refund | `credit_balance` is left on the booking with no
  credit note, against the rule "overpayment lives in the credit note only" | route it through the Phase 1 apply
  function (−N path) | self.
- L2 | medium | `config/invoice.php` 1941-1960 | the final invoice is emailed to the guest on pay-later bookings |
  guest chased for a company's LPO bill | Phase 2 recipient routing (or a small PATCH now) | self.
- L3 | low | `admin/bookings.php` 2568 `extend_stay` | no `logBookingAudit` old→new, only a status-log line with the
  new total; no permission key | History is thin for a money change | Phase 1 rewire | self.
