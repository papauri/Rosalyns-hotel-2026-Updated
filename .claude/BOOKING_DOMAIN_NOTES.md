# Booking / Finance Domain Notes

Hard-won invariants for the booking, housekeeping, credit-note and email-template areas.
Consolidated 2026-09-04 from `.claude/agent-memory/room-booking-specialist/`, whose owning
agent no longer exists — the scaffolding was removed, the knowledge kept. Applies to both the
Rosalyn and Liwonde forks (same platform). Verify a named file/function still exists before
relying on it.

---


The canonical availability engine is `checkRoomAvailability(int $room_id, string $check_in, string $check_out, ?int $exclude_booking_id, int $child_guests, int $child_rooms_needed)` defined in `config/database.php` (~line 3133).

- Returns an array with keys: `available` (bool), `remaining_rooms` (int), `room` (row), `error` (string), plus combination/individual breakdowns.
- It already excludes cancelled/no-show via `getBookingStatusesThatBlockAvailability()` and excludes expired tentatives.
- It rejects past check-in dates (`check_in < today`), so admin bookings can start today but not in the past.
- For joined-room types it returns `remaining_rooms` = remaining combinations; for individual-room types it returns physical-room remaining counts. The same call works for all three room-type shapes.

**Why:** `admin/create-booking.php` historically inserted bookings inside a transaction with a `FOR UPDATE` lock on the `rooms` row but performed NO server-side conflict check — availability was only verified client-side via AJAX. This allowed double-booking under a race.

**How to apply:** Any booking-creation path must call `checkRoomAvailability` for each room type INSIDE the locked transaction (after the `SELECT ... FOR UPDATE` rows-lock) and abort if `remaining_rooms < requested qty`. Aggregate requested qty per room_id first (multiple room lines can target the same type). This guard is now in place in create-booking.php.

---


Status values in this codebase use HYPHENS, not underscores: `pending, confirmed, tentative, checked-in, checked-out, completed, cancelled, no-show, expired`. (The generic spec sometimes writes `checked_in` — the actual DB/code uses `checked-in`.) booking-details.php and create-booking.php both rely on the hyphen form throughout (status badge map, transitions, dropdown option values).

`rooms.rooms_available` accounting rules observed in booking-details.php:
- `confirm` (pending→confirmed): decrement by 1. MUST guard on `rowCount() > 0` of the conditional UPDATE (`WHERE status='pending'`) — otherwise a double-submit on an already-confirmed booking double-decrements. (Fixed.)
- tentative `convert`→confirmed: decrement by 1 (guarded by a `status!=='tentative'` pre-check).
- `checkout`: increment by 1 (capped at total_rooms).
- `cancel` / `noshow`: increment by 1 ONLY if previous_status was `confirmed`.
- Tentative bookings do NOT consume `rooms_available`.

**Why:** these increments/decrements are easy to get wrong and lead to drift between `rooms_available` and real occupancy.

**How to apply:** when adding a status transition that changes occupancy, mirror these rules and always guard conditional UPDATEs with rowCount before adjusting `rooms_available`. See [[project-booking-availability-engine]] for the conflict-check side.

---


Store credit = the existing credit-notes module (`config/credit-notes.php`). Two core functions:
- `issueCreditNote(PDO, data[]): array` — creates a credit note (active), generates PDF, optionally emails. `data`: amount, guest_name (required, non-empty), guest_email, booking_id, booking_reference, booking_type (room|conference|restaurant|goodwill), reason (cancellation|service_issue|early_checkout|overpayment|goodwill|pricing_error|other), reason_notes, vat_rate, original_payment_id, issued_by (admin id, required >0), generate_pdf, send_email. Returns success + credit_note_number/id.
- `applyCreditNote(PDO, creditNoteId, bookingData[booking_id,booking_type,booking_reference], amountToApply, adminUserId, notes): array` — redeems against an EXISTING booking. Creates a payments row (method `credit_note`), deducts CN balance, recalculates booking financials. Returns success + remaining_balance.

Critical transaction rule: BOTH functions manage their own transaction, and `issueCreditNote`/`finance_next_credit_note_number` route through `finance_ensure_sequence_tables()` which runs `CREATE TABLE IF NOT EXISTS` (DDL → MySQL implicit commit). So NEVER call them inside another open transaction. Pattern used everywhere: do the core booking/refund work in its own committed transaction, then call issue/apply as a POST-COMMIT side effect (like the confirmation email), surfacing failures with a manual-fallback message rather than rolling back settled money.

Where it's wired:
- Refund to store credit: `admin/payment-refund.php` — "Refund to" toggle (original method | store credit). Store credit forces status=completed, records the refund row with method `credit_note`, then post-commit issues a credit note (PDF + credit-note email) and stamps the CN number on the refund row notes.
- Apply credit at booking time: `admin/create-booking.php` — payment step has a "Guest Credit" panel that calls `create-booking.php?ajax=guest_credit_lookup&email=` (served from the page itself so it uses booking-staff auth, NOT the `invoices`-gated credit-notes API). Selected `apply_credit_note_ids[]` are applied post-commit, greedily across the created bookings, capped at each booking's amount_due. Security: by default only credit notes whose `guest_email` matches the booking email are applied. There is an explicit, audited override (`apply_credit_override` + required `apply_credit_override_reason`) to apply credit from a different account (e.g. company paying for an employee); overridden applications record the reason in the application notes and an rh_log_event warning. The override email field is client-only (used for lookup); the server re-validates each CN's email independently.

Credit-note statuses: active, partially_applied, fully_applied, expired, void. Redeemable = active|partially_applied with balance>0 and not past expires_at. See [[project-editable-email-templates]] (credit_note template) and [[project-booking-status-conventions]].

---


Adding a new admin-editable booking/finance email template requires keeping FOUR places in sync; miss one and the email either isn't editable, isn't seeded, or previews with raw `{{placeholders}}`:

1. `config/email.php` → `ensureBookingEmailTemplateDefaults()` `$defaults` array — the canonical default, built with `hotel_premium_email_html()` + `hotel_premium_email_body()` + `hotel_premium_email_summary_rows()` for the shared Japandi-premium UI. Auto-upserts to DB on every include (only if not already present). Also exposed via `hotel_booking_template_defaults_map()` for the admin "Load Default" / reset buttons.
2. The send function — render via `renderBookingEmailTemplate($key, $vars)` with vars from `buildBookingEmailVariables($booking, $room, $extra)` (gives logo/site/address/contact/currency shell vars). Always keep a premium-wrapped code fallback for when the DB template is inactive/missing.
3. `admin/booking-settings.php` → add the key to `$booking_template_defs_master` AND `$booking_template_short_names` so it appears in the editor.
4. `admin/booking-settings.php` → add sample values for any custom placeholders to the `$ajaxVars` preview map (~line 247+), else preview shows raw `{{tags}}`.

No DB migration needed — the `booking_email_templates` table already exists and `ensureBookingEmailTemplateDefaults()` seeds new keys live. To force immediate seeding, just include `config/email.php` once.

Example: `refund_notification` (refund confirmation email) follows exactly this pattern; sent by `sendRefundNotificationEmail()` from `admin/payment-refund.php` after a refund commits. Status values used elsewhere: see [[project-booking-status-conventions]].

---


Checkout cleanup invariant: a room needs at most ONE open checkout cleanup, regardless of how many qualifying past bookings it has. Root cause of a duplicate "checkout cleanup pending twice": `getCheckoutCleanupRooms()` (admin/housekeeping.php) joined every past booking per room and `autoCreateCheckoutCleanup()` deduped per (room + linked_booking_id), so a room with two past bookings got two assignments. Fixed by: one-row-per-room (latest qualifying booking) + NOT EXISTS / dedup keyed on room only (linked_booking_id dropped from the existence checks; still stored on the row).

Why duplicates hurt: completing one cleanup leaves the other open, so `reconcileIndividualRoomHousekeeping()` keeps the room in `cleaning` — the room appears stuck and cannot be freed until BOTH are cleared.

Room freeing / assignment gating:
- Checkout sets the individual room `status='cleaning'` (admin/booking-details.php checkout case).
- `checkIndividualRoomAvailability()` (config/database.php) blocks any room whose `status != 'available'`, plus open maintenance schedules and date-bounded housekeeping assignments.
- `reconcileIndividualRoomHousekeeping()` returns the room to `available` only once NO open assignment (pending/in_progress/blocked) remains.
- `getRoomHousekeepingAssignmentBlock(int $roomId)` (config/database.php) gives the admin-facing reason+fix message; wired into the `assign_individual_room` chokepoint in admin/bookings.php (used by both the quick-assign modal and admin/individual-rooms.php). Returns null when free, else `{blocked, reason, message}`.

To clear an existing duplicate safely: delete the redundant open checkout_cleanup row in the Housekeeping UI (delete_assignment hard-deletes + reconciles), keeping one. Deleting production rows from a script is blocked by the auto-mode classifier without explicit user authorization.

See [[project-booking-status-conventions]] for booking status values and [[project-booking-availability-engine]] for the room-type availability engine.

---


The `booking_reminder` email type (template key `booking_reminder` in `config/email.php`, sent via `sendBookingReminderEmail()`) is ONE email reused for TWO distinct conditions: missed/upcoming check-in (potential no-show) and overdue checkout. The admin resend modal in `admin/bookings.php` auto-selects it whenever a row is `$is_missed_checkin || $is_overdue_checkout`.

Precedence rules now enforced inside `sendBookingReminderEmail()` (highest first):
1. Blocked statuses (`no-show, cancelled, checked-out, completed, expired`) throw — never get a reminder. No-show supersedes all time-based logic.
2. Not-checked-in bookings are always treated as potential no-show (check-in language, driven by `check_in_date`) — even if checkout date also elapsed. Never framed as late checkout.
3. Only `status='checked-in'` + checkout date passed → overdue-checkout language driven by `check_out_date`.

**Why:** the original function derived everything from `check_in_date` and always emitted arrival/no-show language, conflating the two notifications. Detection in the UI views (bookings.php list flags + SQL, booking-details.php alert, housekeeping queries) was already status-disjoint — the defect was purely in the email layer.

**How to apply:** any new caller/scheduler of reminders must respect that a not-checked-in booking is a no-show candidate, not a late checkout. The status gate `= 'checked-in'` is what keeps no-show out of overdue-checkout detection. See [[project-booking-status-conventions]] for the hyphenated status values.

---

