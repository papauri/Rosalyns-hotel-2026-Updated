# SHIP M: credit notes are guest credit, not refunds; VAT counted once (build spec)
Owner approved 2026-11-18. Build at LIWONDE, then port. ROSALYN'S IS LIVE: no DB tests or writes there.
Line numbers are from Liwonde on 2026-11-18. Re-grep before you edit.

## 1. Owner rules (settled)
R1 Moving money onto a credit note is NOT a refund. Take it out of refunds in EOD, Reports and the exports. Only real payouts count as refunds.
R2 Credit-note redemptions stay out of cash and out of gross revenue (no change).
R3 Add a "Guest credit" line to Reports and EOD from shared helpers: issued, used, still owed (running balance).
R4 VAT is counted once, when the money is first received. VAT inside credit-note rows is information only and is not in VAT Collected.
R5 A kept cancellation fee stays earned (no change). R6 Never mention MRA in any new text.

## 2. How the rows are identified (confirmed by read-only selects at Liwonde)
Every credit-note movement on `payments` has `payment_method='credit_note'`.
- Moved into a note (conversion): `payment_type='refund'`, `refund_status='completed'`, `credit_note_id` set, refund_notes "Moved to credit note CN-..." / "Refunded as store credit".
  Written by recordCreditNoteRefundLegs (config/credit-notes.php:539) and by the store-credit path in admin/payment-refund.php:220/313.
- Returned to a note (a redemption reversed): `payment_type='refund'`, method credit_note, `credit_note_id` NULL. Written by rh_refund_credit_note_payments (credit-notes.php:698) and by payment-refund.php `$isCnPayment`. A negative `credit_note_applications` row goes with it.
- Redemption: a non-refund row with method credit_note (applyCreditNote :229). A `credit_note_applications` row goes with it.
REAL PAYOUT = `payment_type='refund' AND refund_status IN ('completed','processing') AND COALESCE(payment_method,'')<>'credit_note'`.
Writers checked (Fable 2026-11-18, all keep method credit_note): recordCreditNoteRefundLegs callers bookings.php:1643, group-credit-move.php:237, stay-correction.php:626, stay-nights.php:376, credit-notes.php:879 (overpay) and voidCreditNote:1001 (re-records the used part as a conversion on the void day); applyCreditNote callers create-booking:1156, admin/credit-notes:105, group-credit-move:252; the cancellation path database.php:11034.
Both refund kinds drop out of refunds. Do not key on credit_note_id. This also fixes an existing mismatch: a returned redemption was taken off net revenue, but the redemption itself was never in gross.
Liwonde data: 3 conversion rows, all overpayment/room. 2099973087 on 10-24 (6,059.57, VAT 894.87, CN-2026-000001); 2099976226 on 11-07 (188,600.00, VAT 27,852.32); 2099976257 on 11-09 (282,900.00, VAT 41,778.48). The 3 notes are active and unused (balance 477,559.57). There are no redemptions or returns. Real payouts: cash 257,182.02 on 11-13, bank 500,000.00 on 11-18.

## 3. Deliberately unchanged
Leave these as they are, so a booking's net paid still deducts the overpayment held as guest credit:
- rh_refund_settled_sql / _sum_sql (finance-refunds.php:33/53) and every balance reader: recalculateBookingFinancials, guest-history, payment-refund.php:179 refundable, Reports deferred revenue (reports.php:965-983).
- Gym earned (eod-revenue.php:234-247): money parked on a note was never earned.
- rh_refund_to_credit* and rh_refund_split_text: Payments, Accounting and AI still use them.
- Reports status mix 191-201, payment methods 257-266, daily 269-280, top clients 319-344, prior period 918-932.

## 4. New helpers
admin/includes/finance-refunds.php (pure SQL strings; append):
- `rh_refund_payout_sql(string $a='p'): string`: rh_refund_settled_sql($a) AND COALESCE($a.payment_method,'')<>'credit_note'.
- `rh_refund_payout_sum_sql(string $a='p'): string`: SUM(CASE WHEN payout THEN rh_refund_amount_sql ELSE 0 END).
- `rh_refund_payout_count_sql(string $a='p'): string`
- `rh_vat_once_sql(string $a='p'): string`: CASE WHEN non-refund AND payment_status IN (completed,paid,refunded,partially_refunded) AND COALESCE(method,'')<>'credit_note' THEN vat_amount WHEN (payout) THEN -vat_amount ELSE 0 END. A NULL method keeps its VAT; the payout condition is parenthesised (Fable). The info helper uses the same COALESCE form.
- Built with the sibling default alias '' (the callers are mostly unaliased `FROM payments`); pass 'p' where aliased. Every helper returns a parenthesised condition.
- `rh_vat_credit_note_info_sql(string $a='p'): string`: VAT on redemptions only (non-refund, method credit_note, received statuses). This is the rule the current Reports vat_credit_note uses. It does not key on credit_note_id. Conversion and return VAT is not shown anywhere: it was already counted when the guest first paid.
NEW admin/includes/guest-credit.php (needs DB; the finance-refunds header says that file stays pure):
- `rh_guest_credit_end_day_sql(string $a='cn'): string` (Fable): CASE WHEN voided_at IS NOT NULL AND expires_at IS NOT NULL THEN LEAST(DATE(voided_at), DATE_ADD(expires_at, INTERVAL 1 DAY)) WHEN voided_at IS NOT NULL THEN DATE(voided_at) WHEN expires_at IS NOT NULL THEN DATE_ADD(expires_at, INTERVAL 1 DAY) END. No DATE(updated_at) fallback (Liwonde 2026-11-18: 0 notes voided without voided_at). The Voided vs Expired label follows whichever end is earlier (a tie reads Voided). A note stays owed through its expiry date (expires_at is a DATE).
- `rh_guest_credit_owed_at(PDO $pdo, string $day): float`, where Ymd is the hotel day (the session time zone is +02:00). Each use of the day is its own named param (no repeated :d; Fable), so it runs with native prepares:
  SELECT COALESCE(SUM(GREATEST(0, cn.original_amount - COALESCE(a.used,0))),0) FROM credit_notes cn
  LEFT JOIN (SELECT credit_note_id, SUM(amount_applied) used FROM credit_note_applications WHERE DATE(applied_at) <= :d1 GROUP BY credit_note_id) a ON a.credit_note_id=cn.id
  WHERE DATE(cn.issued_at) <= :d2 AND (end_day IS NULL OR end_day > :d3)
- `rh_guest_credit_summary(PDO $pdo, string $from, string $to): array` with keys opening, issued, issued_count, used, voided, expired, closing.
  - opening = owed_at(from-1); closing = owed_at(to).
  - issued = SUM(original_amount) where DATE(issued_at) is in range. This includes goodwill notes and notes voided later, so history never restates.
  - used = SUM(amount_applied) where DATE(applied_at) is in range, net of the negative reversals.
  - voided / expired = the unused part, GREATEST(0, original - apps with date <= end_day), for notes whose end_day is in range (split by voided vs expires_at).
  - Identity (test it): opening + issued - used - voided - expired = closing, within BALANCE_TOLERANCE.
- `rh_guest_credit_from_rows(array $notes, array $apps, string $from, string $to): array`: a pure twin of the same rules, for unit tests.
- `rh_guest_credit_lines(array $s, bool $period): array`: shared [label, amount, helper] rows for the page, PDF, email, CSV and export. Voided and Expired appear only when above BALANCE_TOLERANCE. Opening appears only when $period is true.

## 5. Readers to change (old rule -> new rule)
EOD
- eod-revenue.php:58 rh_eod_cash_row_sql. Old: NOT(non-refund AND credit_note). New: COALESCE(a.payment_method,'')<>'credit_note' (every credit-note row leaves the cash view). Fix the docblock. Callers pick this up: page 60-61 and 692-702 trend, eod-report-data 74-91, rh_eod_payment_mix 384.
- end-of-day-report.php: 191-192 refunds and refund_vat change to payout. 221-235 net/VAT/cash change to VAT once. 463-475 previous day, same.
  - 341-363 and 1669-1672 (credit notes issued/redeemed) are replaced by the Guest credit block. 546-553 refunds_to_credit and cn_redeemed_pay are removed. 1398-1401: drop "refunds to credit" and "credit notes redeemed" from the cash panel (one source only: Guest credit used).
  - 641-643 alert: drop "of which moved to credit notes". 1635-1642 VAT table: drop the 1640 sub-text and add the VAT info row.
  - CSV 930-945/1000-1015: 'Refunds Paid Out' becomes payouts only. APPEND at the end: Guest Credit Issued, Guest Credit Used, Guest Credit Still Owed.
- eod-revenue.php:445 rh_eod_refunds_to_credit and :67 rh_eod_credit_notes_redeemed: remove them once no caller is left (grep first).
- eod-report-data.php: 73 $rf changes to payout. 95 total_vat changes to VAT once. 107 net and 135-139 previous day, same. 94/124 removed. 250: replace keys refunds_to_credit and credit_notes_redeemed with 'guest_credit' => summary(day,day) and 'vat_credit_note_info'. Headline 302-306 and 341-346 get the new refund helper text.
- includes/eod-pdf-builder.php: 483-490 and 530-537 refunds change to payout. 503-504 and 507-508 are replaced by the Guest credit rows. 510 and 552 VAT become VAT once, plus the info row.
- admin/api/end-of-day-send.php 102, 203-204, 440-441: replace with guest credit lines.
- includes/report-builders.php buildAccountingSummary 244-249 and 270-274: add the cash-row rule. Refund branch: payout only. VAT: VAT once. Append the guest credit rows.
Reports (admin/reports.php)
- 209-235 revenueByType: total_vat and total_vat_counted change to rh_vat_once_sql. $totalVatCreditNote changes to the info helper. Replace the ASSUMPTION comment at 233-234.
- 283-297 monthly: gate the CASE on the cash row first; refund branch payout only; VAT once.
- 300-316 VAT Collection daily: vat_collected and vat_counted change to VAT once; vat_credit_note changes to the info helper.
- 352-362 refund reasons, 382-393 trends: payout. 363-366 $refundsToCredit removed. 369-379 refund status: exclude credit_note rows.
- 832: keep `$netRevenue = $grossRevenue - $totalRefunds - $totalVatCounted;`. $totalRefunds is now payouts only, and $totalVatCounted equals VAT Collected.
- VAT Register 840-862 list, 865-880 totals, 882-894 by type, 990-1007 by quarter: add `AND COALESCE(p.payment_method,'')<>'credit_note'`. Register total = VAT Collected (test it).
- 945-960 cnData is replaced by rh_guest_credit_summary($from,$to).
Export (api/reports-export.php)
- 159-182 overview: refund branch payout only; VAT once; append Guest credit rows. 193-255 status breakdown: skip credit_note refund rows and add a Guest credit section.
- 262-285 by type (267 VAT) and 339-364 daily (343): add the cash-row rule, payout, VAT once.
- VAT report 510-517 daily, 554-561 by type: VAT once. Add the info VAT row to TOTALS. Their total_revenue columns get the cash-row gate and payout too (R1 covers the exports).
- Top clients (~379-385): the refund branch is payout only (Fable). Voids export POS refunds (~953-955): payouts only (Fable).
- Kept: the methods query's credit-note exclusion (test ~149).
More callers (Fable, §5/§9):
- reports.php $rhCashRow uses at 211-218, 261, 275, 286-287, 301-309 (incl. the NOT at 308) and 926: the helper is parenthesised, so `NOT {$rhCashRow}` stays correct.
- reports-export.php $cashRow at 165-166, 265-266 and 303.
- end-of-day-send.php also 103, 206-207 and 445-446.
- Tests reports_status_mix_test:86 and daily_reports_job:288-304.
- eod-revenue.php rh_eod_payment_mix comment at 401-403 updated.

## 6. Screen / PDF lines (label: helper text)
- Refunds (Reports card 1846-1870, P&L "- Refunds Issued" 1340-1360, EOD, PDF): "Money paid back to guests in cash, bank or mobile money. Moving money onto a credit note is guest credit, not a refund." Delete 1849's "plus overpayments or store credit moved to a credit note. Same rule as Payments and Accounting" and the 1850 split text.
- VAT Collected (1263-1264, 2052, P&L 1350-1356, EOD VAT table): "VAT on money received, less VAT on real refunds. Counted once, when the guest first paid."
- New memo row "VAT inside credit-note payments (information only)": "Already counted when the guest first paid; not in VAT Collected."
- 1804-1831 VAT columns paragraph: remove "The two VAT columns together make VAT Collected". New text: "VAT Collected counts each payment once. The credit-note column is information only."
- Guest credit block (Reports overview memo, replacing "Credit Notes Issued" 1374-1381, help 1232 and KPI 1437-1442; EOD 1669-1672; PDF; email; CSV):
  - "Guest credit at start" (Reports only): "What the hotel owed guests on credit notes when the period began."
  - "Guest credit issued": "New credit notes: overpayments, store credit and goodwill. Money the hotel holds for guests; not a refund, not revenue."
  - "Guest credit used": "Credit notes spent on bills (net of any put back). No new money."
  - "Voided" / "Expired unused" (only when non-zero): "Credit cancelled / passed its expiry date with nothing left owed."
  - "Guest credit still owed": "What the hotel still owes guests on credit notes at the end of the day / period."
- Gross Revenue titles 1253/1629 and Net Revenue 1634-1636: no "credit note" wording change beyond the refunds rule. No MRA wording anywhere.

## 7. Docs to update at build (not now)
- .claude/EOD_SPEC.md (dept-eod-lead to confirm):
  - 68-72: the cash rule excludes every credit-note row. 120 content and 138 accounting CSV: add Guest credit, VAT once.
  - Table 177 VAT changes to VAT once. 178 refunds change to rh_refund_payout_sql. 179 "of which moved to credit notes" is deleted. 180 changes to the Guest credit row (rh_guest_credit_summary).
  - 212: F5 superseded by Ship M.
- BUILD_PLAN.md: mark the ASSUMPTION at Liwonde 997 and Rosalyn's 221 "SUPERSEDED 2026-11-18 by Ship M (owner): credit-note VAT leaves VAT Collected".
- GUIDES_AI_SYNC.md: one entry for EOD and Reports refunds, Guest credit and VAT once. AI readers (ai-insights.php 296-303 and 1354, ai-decisions.php 1469-1626) are for the AI lead only.

## 8. Tests
T1 finance_refunds.php (pure). Keep the rh_refund_settled_sql string assertion unchanged. Add string asserts for payout (contains credit_note exclusion), vat_once and info.
T2 NEW scripts/tests/guest_credit_ship_m.php (pure twin), range 11-01..11-05:
  - Note A: 100,000 issued 11-01; applications +30,000 on 11-03, -5,000 on 11-05.
  - Note B: 50,000 issued 11-02; +10,000 on 11-03; voided 11-04.
  - Note C: 20,000 issued 10-20; expires 11-02.
  - Expect opening 20,000, issued 150,000, used 35,000, voided 40,000, expired 20,000, closing 75,000.
  - owed_at: 11-02 = 170,000; 11-03 = 110,000; 11-04 = 70,000. Identity holds.
T3 Same file, DB part at LIWONDE only, in one rolled-back transaction, on days D=2001-05-10 and D2=2001-05-12, VAT 10% on every row.
  - Rows: P1 cash 1,000 (D); P2 bank 500 (D); CNLEG credit_note refund 300 with credit_note_id (D); R1 cash refund 200 completed (D); R2 refund 80 pending (D); RED credit_note payment 120 (D2); RET credit_note refund 20 with credit_note_id NULL (D2).
  - Notes: CN-T1 300 issued D, with applications +120 and -20 on D2. CN-T2 goodwill 50 issued D, voided D2.
  - Expected on D: gross 1,500; refunds 200 (was 500); cash collected 1,300; VAT Collected 130 (was 100); cash to reconcile 800; non-cash 500; guest credit issued 350, still owed 350.
  - Expected on D2: gross 0; refunds 0 (was 20); VAT Collected 0; VAT info 12; guest credit opening 350, used 100, voided 50, closing 200.
  - Reports D..D2: refunds 200 (was 520); Net Revenue = 1,500 - 200 - 130 = 1,170; VAT Collected 130 = VAT Register total; info 12.
  - Export overview: revenue 1,300, VAT 130, no "Moved to credit note" line. Accounting CSV on D: gross 1,300, VAT 130.
  - Run the real SQL the same way reports_status_mix_test greps it out of the source.
T4 reports_status_mix_test.php 135-144. The TRSM-CN 70/VAT 7 redemption and the TRSM-TOCN 20/VAT 2 conversion stay in the fixture.
  - New expectation: VAT Collected 35 = counted 35 (was 40 / 33). Info 7. Net before refunds 350 - 35 = 315 (was 317). Refunds 0 (TOCN is not a refund).
  - Line ~158 monthly: 330 becomes 350 (the 20 moved into a credit note is no longer taken off; Fable).
  - The VAT Collection rows show counted 35 and credit-note 7.
T5 daily_reports_job.php 288-304: replace the old cash-row string, credit_notes_redeemed key, "Credit notes redeemed (...)" strings and rh_eod_refunds_to_credit asserts with the new rule, the guest_credit key and the guest credit labels.
T6 Read-only acceptance at Liwonde (derive figures from the data, because the mirror refresh changes it):
  - EOD 11-07: refunds 0 (was 188,600); guest credit issued 188,600; still owed 194,659.57.
  - EOD 11-09: issued 282,900; still owed 477,559.57.
  - EOD 11-13: refunds 257,182.02, unchanged.
  - Reports 11-01..11-18: refunds 757,182.02 (was 1,228,682.02); VAT Collected +69,630.80; guest credit opening 6,059.57, issued 471,500.00, used 0, closing 477,559.57.
  - Closing for today = SUM(balance) of the live notes.
Suites: tier = money, so the full suite runs at ship (orchestrator). Browser check at Liwonde: reports.php tabs overview, revenue, refunds and vat; end-of-day-report.php?date=2026-11-07 and its PDF.

## 9. Risks
- Past days restate: Liwonde 10-24, 11-07 and 11-09. EOD PDFs already sent will no longer match. Rosalyn's days are unknown until port (no DB access): list them read-only at port.
- Payments KPI (payments.php 391/652), Accounting dashboard (198-203, 577, 745, 974, 1807-1808), guest history and AI still use the old refund total, so they will disagree with Reports and EOD until aligned (open question).
- Changing rh_eod_cash_row_sql reaches every caller. Grep all callers before shipping. Gym earned (eod-revenue 234-247) must not call it, or the helper change will move gym figures.
- Net Revenue now keeps overpayments held as guest credit (owner decision). Guest credit still owed shows the liability next to it.
- Conversion-leg VAT is slightly off the note's vat_amount (894.87 vs 902.49). It no longer matters, since neither is in VAT Collected.
- GREATEST(0, ...) can break the identity if a note is over-applied. The test flags it. Liwonde has no gaps.
- Benefit: voiding a note no longer restates past refunds.

## 10. Open owner questions (defaults used until answered; log as ASSUMPTION)
Q1 Should Payments, the Accounting dashboard, guest history and the AI also count real payouts only? Default: no, this ship covers EOD, Reports and the exports; the rest follows as Ship M2.
Q2 Unused credit that expires: is it income, or only "no longer owed"? Default: no longer owed, on its own "Expired unused" line, not revenue anywhere.
