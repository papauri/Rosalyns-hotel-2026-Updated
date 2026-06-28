---
name: project-credit-notes-integration
description: How store-credit (credit notes) integrates with refunds and bookings — issueCreditNote/applyCreditNote and the post-commit pattern
metadata:
  type: project
---

Store credit = the existing credit-notes module (`config/credit-notes.php`). Two core functions:
- `issueCreditNote(PDO, data[]): array` — creates a credit note (active), generates PDF, optionally emails. `data`: amount, guest_name (required, non-empty), guest_email, booking_id, booking_reference, booking_type (room|conference|restaurant|goodwill), reason (cancellation|service_issue|early_checkout|overpayment|goodwill|pricing_error|other), reason_notes, vat_rate, original_payment_id, issued_by (admin id, required >0), generate_pdf, send_email. Returns success + credit_note_number/id.
- `applyCreditNote(PDO, creditNoteId, bookingData[booking_id,booking_type,booking_reference], amountToApply, adminUserId, notes): array` — redeems against an EXISTING booking. Creates a payments row (method `credit_note`), deducts CN balance, recalculates booking financials. Returns success + remaining_balance.

Critical transaction rule: BOTH functions manage their own transaction, and `issueCreditNote`/`finance_next_credit_note_number` route through `finance_ensure_sequence_tables()` which runs `CREATE TABLE IF NOT EXISTS` (DDL → MySQL implicit commit). So NEVER call them inside another open transaction. Pattern used everywhere: do the core booking/refund work in its own committed transaction, then call issue/apply as a POST-COMMIT side effect (like the confirmation email), surfacing failures with a manual-fallback message rather than rolling back settled money.

Where it's wired:
- Refund to store credit: `admin/payment-refund.php` — "Refund to" toggle (original method | store credit). Store credit forces status=completed, records the refund row with method `credit_note`, then post-commit issues a credit note (PDF + credit-note email) and stamps the CN number on the refund row notes.
- Apply credit at booking time: `admin/create-booking.php` — payment step has a "Guest Credit" panel that calls `create-booking.php?ajax=guest_credit_lookup&email=` (served from the page itself so it uses booking-staff auth, NOT the `invoices`-gated credit-notes API). Selected `apply_credit_note_ids[]` are applied post-commit, greedily across the created bookings, capped at each booking's amount_due. Security: by default only credit notes whose `guest_email` matches the booking email are applied. There is an explicit, audited override (`apply_credit_override` + required `apply_credit_override_reason`) to apply credit from a different account (e.g. company paying for an employee); overridden applications record the reason in the application notes and an rh_log_event warning. The override email field is client-only (used for lookup); the server re-validates each CN's email independently.

Credit-note statuses: active, partially_applied, fully_applied, expired, void. Redeemable = active|partially_applied with balance>0 and not past expires_at. See [[project-editable-email-templates]] (credit_note template) and [[project-booking-status-conventions]].
