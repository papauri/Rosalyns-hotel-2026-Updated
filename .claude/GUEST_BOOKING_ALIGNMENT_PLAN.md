# Guest booking path vs current admin system: alignment plan

Owner request 2026-11-14: "go through the front end booking logic to make sure group and company and individual
bookings are all up to date with our current system. Put that in a plan."
Audit only (dept-guest-site-lead, read-only, 2026-11-14). No code was changed. No database was read, so anything
that depends on live data is marked "not verified". Line numbers are as of commit 19e74eb.

Reference "current" = admin/create-booking.php (insert :837-893, group link :834-835, History :1186-1221),
admin/booking-details.php group cancel (:886-891), group charge/payment helpers, includes/billing-accounts.php,
the booked-rate rule, and `.claude/CANCELLATION_POLICY_SPEC.md` §4/§5.

## 1. Summary

| Area | Individual | Group (2+ linked rows) | Company | Status |
|---|---|---|---|---|
| Pricing: occupancy rate, rate plans, × nights | booking.php :432-440, :608-610 = admin :644-667 | same loop :587-709; mixed via `rh_mixed_price_row` mixed-booking.php :241-244 | n/a | OK (website). **Gap in api/bookings.php** :243-272, no rate plans (F3) |
| VAT / levy (`rh_stay_totals`, levy outside the VAT base, all-in price) | booking.php :619-630; api :266-272 | booking.php same; mixed :516-522 | n/a | OK, same as admin :678-689 |
| Child supplement (per room, rule snapshot) | booking.php :444-454, :615 | per row :615; mixed per row | n/a | OK |
| Packages | lead row only, booking.php :612, :721-736 | lead row only, mixed :579-586 | n/a | OK. Admin create writes no packages at all (LATER, outside this scope) |
| Joined rooms (`room_combinations`, ONE row) | queue + lock + `rh_joined_members_stock_error` booking.php :375-413, :553-558, :689-698 | mixed :480-491, :552-557 | n/a | OK on the website. **Bug in API** (F3). Quote mismatch in check-availability (F7) |
| Group link (`primary_booking_id`, NULL on the lead) | n/a | booking.php :714-719; mixed :575-578 = admin :834-835 | n/a | OK. API cannot create groups (it refuses multi-room parties, :206-208) |
| Group charge / payment / per-room docs / no-show switch | n/a | all key on `COALESCE(primary_booking_id,id)` (group-charge.php :38-50, group-payment.php :55-66, database.php :9754-9764), so public groups qualify | follows the lead | OK |
| `group_min_rooms` | n/a | exists only in the cancellation spec (§2). Today a group = 2+ linked rows | n/a | OK (nothing to align yet) |
| Company billing (`billing_account_id`, 084) | not set at creation | not set; members follow the lead (billing-accounts.php :137-150) | admin create does not set it either; staff attach it later via `rh_billing_set_on_booking` (:748, pending allowed :783) | OK (parity). Owner Q2 |
| History: timeline + audit rows written | booking.php :757-758; api :400-401 | per row; mixed :617-624 | n/a | OK (both rows written) |
| History: price snapshot ("how the price was worked out") | **missing** on all public paths | **missing** | n/a | **Bug** (F4) |
| Guest lookup display | booking-lookup.php :246-341 | **wrong for same-type split parties** :255-321 | n/a | **Bug** (F1) |
| Guest self-cancel | booking-lookup.php :118-149, one row | **cancels only the looked-up row** :122 | n/a | **Bug, owner decision** (F2) |
| Soft-deleted bookings | lookup :46-53, :87-94; api/booking-details :45-73 have no `deleted_at` filter | lookup group query :258-263 | n/a | **Gap** (F5) |
| Guest emails | booking.php :762-810 | group totals OK; other references and rate plan not shown | n/a | Cosmetic (F10) |
| API booking details | today's room price shown as `price_per_night` :49, :105 | no group info | n/a | **Gap** (F6) |
| Confirmation page | booking-confirmation.php :26-38 groups by `REF-%` | OK for public refs | n/a | Cosmetic wording (F11) |
| Deposits on unconfirmed bookings | no payment taken on the public path (booking.php :1480, :1594) | same | n/a | OK (nothing to align; room deposits exist only on quotations) |
| Individual room assignment | not at creation (joined rooms excepted). Admin assigns at confirm/manually (bookings.php :723; auto-assign needs confirmed + paid, database.php :3316-3326) | same | n/a | OK (parity by design) |
| `payment_status` on insert | public does not write it (column default used); admin writes it :846 | same | n/a | Not verified (DB not read). No admin filter on `payment_status = 'unpaid'` was found, so no screen depends on it |

## 2. Findings (ranked: wrong money or data first, then mismatch, then cosmetic)

Format per DEPARTMENTS.md. Kind: PATCH / FEATURE, and FIX-NOW / LATER.

**F1 | high | booking-lookup.php :269-:270, :303, :307-:321 | PATCH, FIX-NOW**
- What admin does: shows a group as every room, with each room's total.
- What public does: the room list and the "Total Amount" cover the whole party only when the room TYPES differ (`$lookup_mixed`).
  - A same-type split party (REF, REF-2, the normal 4-guests-in-2-rooms case from booking.php :587-719) sees one room, that room's guests and that room's total. Example: half the real total.
  - When types do differ, the sum (:313-:319) includes cancelled and soft-deleted rooms. The group query :258-:263 has no status or `deleted_at` filter, so a partly cancelled group shows the original full total.
- Fix (PATCH scope: correct the figures, no new wording):
  - Group = any lead with 2+ linked rows (same guest email), not "2+ room names".
  - List every room with its own guests.
  - The total sums the rows that are neither cancelled nor soft-deleted.
- Moved to the cancellation build (spec §5 already puts the fee on the lookup page, and the owner approved it there): showing a cancelled room's kept fee (`cancellation_retained_amount`, as `recalculateBookingFinancials` bills it, database.php :10055-:10059) instead of the original total.
- Owner: guest-site. Accounting lead reviews the money figure.
- Risk: low (read-only display). Collides with the cancellation build's lookup details block (spec §5).

**F2 | high | booking-lookup.php :122 (vs admin/booking-details.php :886-:891) | BLOCKED: owner Q1 (guest-visible flow)**
- The guest's "Cancel My Booking" runs `cancelRoomBookingSettled` on the ONE row looked up.
- The other rooms of the party stay pending or confirmed, holding stock and billing, yet the guest is told "Your booking has been cancelled".
- With `group_noshow_charge` On, those rooms later become no-shows and are charged.
- booking.php :711-:713 says the link exists precisely "so the cancel dialog offers the whole group".
- Admin offers "whole group" through `rh_cancel_booking_group` (database.php :10663).
- Two things a fix needs:
  - `rh_cancel_booking_group` always settles refunds as 'completed' (:10692 calls `cancelRoomBookingSettled` with no refund status). The guest path needs 'pending' when `guest_self_cancel_refund` = pending, so the group helper needs a refund-status parameter.
  - That is the same function the cancellation build changes ($feeChoice). Build this inside the cancellation build (see §5).
  - Ownership limit: `rh_booking_group_members` / `rh_booking_group_ids` (database.php :10550) do not filter by email. The lookup's own group query does (:261). A guest-side whole-group cancel must touch only rows carrying the email the guest proved, because admin-created groups can hold rows booked for other people.
- Owner: guest-site + rooms. Accounting gate.
- Risk: medium (cancels several rows).

**F3 | high if any client uses the API (not verified) | api/bookings.php | PATCH, FIX-NOW**
- (a) No rate plans. There is no `applyDynamicPricing` (:243-:272), and the insert :316-:326 writes no `rate_plan_*` columns. The API charges list price for a stay the website and admin price with the plan (surcharge or discount): a wrong total.
- (b) Joined rooms:
  - The price is taken from `getAvailableRoomCombinations()[0]` BEFORE the lock (:225-:239).
  - After the insert a fresh query's `[0]` is assigned (:365-:371). So the room can be priced off one combination and assigned another. This is the bug booking.php :370-:374 fixed.
- (c) No `rh_joined_members_stock_error` under the lock. booking.php :553-:558, mixed :487 and admin :778 all run it, so the API can oversell a joined pair against a member-type booking committed a moment earlier.
- Fix:
  - Price with `ga_price_for_occupancy` + `applyDynamicPricing` and write the plan columns, as booking.php :608-:610, :682-:684.
  - Pick the combination once, inside the lock, then price and assign that same id.
  - Add the stock guard.
- Owner: rooms lead (availability/pricing). Accounting reviews the pricing.
- Risk: medium (create path). A small diff in one file.

**F4 | medium (wrong money figure on a staff screen) | booking.php :757-:758, includes/mixed-booking.php :617-:624, api/bookings.php :400-:401; helpers includes/booking-timeline.php :82, :390 | PATCH, FIX-NOW**
- Admin writes a price snapshot: audit `new_values.price` :1200 and timeline `metadata.price` :1217, built at :918-:925. Public paths write neither.
- So the History "How the price was worked out" for every online booking takes the no-snapshot branch (includes/booking-history-format.php :957-:960):
  - It says "this booking was made before price details were recorded", which is false.
  - It works the nightly rate out as (total_with_vat − child) ÷ nights. `total_with_vat` includes packages (booking.php :626; `rh_hist_full_total` :247-:252 = total_with_vat), so an online booking with a package shows an inflated booked rate.
  - That inflated rate also triggers the "Today's price is …" line (:976-:978).
- Fix:
  - Give `logBookingCreated` / `logBookingCreatedAudit` an optional `$price` argument (same keys as admin: source, rate, list_rate, nights, occupancy, room_type_id, rate_plan_*, child_supplement, vat/levy, total_with_vat, joined_rooms_id).
  - Pass it from all three public paths.
  - Old bookings are unchanged.
- Owner: rooms lead; tell dept-logs-lead.
- Risk: low (logging only; never fatal).

**F5 | medium | booking-lookup.php :46-:53, :87-:94, :258-:263; api/booking-details.php :45-:73 | PATCH, FIX-NOW**
- There is no `deleted_at IS NULL`, so a booking staff soft-deleted can still be looked up AND cancelled by the guest.
- `cancelRoomBookingSettled` (:10342-:10347) and `validateBookingCancellation` (:3343-:3367) do not check `deleted_at`, so cancellation and refund rows land on a deleted booking.
- The cancellation spec says soft-deleted rooms carry no fee. Today a guest could still trigger one once the policy is On.
- Fix: add the filter to all four queries.
- Owner: guest-site (lookup) and rooms (API).
- Risk: low.

**F6 | medium | api/booking-details.php :49, :105, :149, :163-:174 | PATCH (price) / LATER (rest)**
- `room.price_per_night` is TODAY's `rooms.price_per_night`. That breaks the booked-rate rule (never today's price).
- Fix: return the booked rate from `rh_booked_rate_line()` (includes/booked-rate.php :197).
- LATER:
  - `booking_notes` (including "Admin note: …" and staff names) is returned to any API key.
  - `can_cancel` ignores tentative.
  - The response has no amount paid or owed and no group rooms.
- Owner: rooms lead.
- Risk: low.

**F7 | low (rare) | check-availability.php :148-:169 | PATCH, FIX-NOW (rooms lead)**
- The live quote prices EVERY joined room off `combinations[0]`.
- booking.php :598-:605 and mixed price each room off its own non-overlapping combination.
- A party needing 2+ joined pairs with different `price_override` sees a quote that differs from the saved total.
- Fix: build the same non-overlapping queue (booking.php :385-:396) and price per room. Guest-site does not re-implement availability; this goes to dept-rooms-lead.
- Risk: low.

**F8 | low | booking-lookup.php :98 | PATCH**
- The server accepts a cancel POST on an `expired` booking. The button is hidden (:360) but the POST is not refused.
- In `keep_first_night` mode `cancelRoomBookingSettled` keeps a first night (:10376-:10384) on a booking that had already expired.
- Fix: add 'expired' to the refused statuses (guest path only).
- Owner: guest-site.
- Risk: very low.

**F9 | cosmetic | booking.php :644-:647, includes/mixed-booking.php :534-:537 | LATER**
- "| Split booking part 1/2" is appended to the guest's own `special_requests`.
- The guest sees it on lookup (:343-:347), and staff read it as the guest's words.
- Admin writes the group label to `booking_notes` instead (create-booking.php :937-:953).
- Fix: write a booking note for new bookings and leave the request text alone.

**F10 | cosmetic / mismatch | booking.php :762-:799, mixed-booking.php :630-:667, config/email.php :1700-:1748, :2269-:2310 | BLOCKED: owner Q3**
- The public email data has no `rate_plan_label` / `rate_plan_discount` / `package_total`, so the template's "Rate Plan" row is blank.
- A group's other references (`all_references`) are passed but never printed.
- No email shows "booked rate × N nights".
- DB templates are live data. The fix appends a block from `buildBookingEmailVariables`, the same mechanism as the spec's `{{cancellation_policy_text}}`.

**F11 | cosmetic | booking-confirmation.php :137, :309, :315; booking.php :1594 | BLOCKED: owner Q4 (guest wording)**
- The heading reads "Booking Confirmed" for a pending booking that is "being reviewed".
- "collected at check-in" is hard-coded, which contradicts the summary's "Payment on confirmation" and ignores the `payment_policy` setting.

Checked and OK: the website's joined-rooms rules (cross-type locks `rh_related_room_type_ids` + `rh_lock_room_types`, booking.php :534-:541, mixed :460-:463). Mixed pricing uses rate plans and a non-overlapping queue (mixed :241-:244, queue :120-:129). Every public create writes both the timeline and the audit row. Idempotency `client_uuid` is set per row.

## 3. Build batches (one builder each; Opus for all, since they touch bookings or money)

DB tests run at **LIWONDE only** (Rosalyn's is LIVE). Several suites commit real throwaway rows and do NOT refuse to run at Rosalyn's (`mixed_room_booking.php`, `concurrent_booking.php`), so run every suite from the Liwonde root. Browser checks run against Liwonde.

| Batch | Findings | Files | Builder / gate | Tests |
|---|---|---|---|---|
| B1 Lookup correctness | F1, F5 (lookup part), F8 | booking-lookup.php | dept-guest-site-lead; gate dept-accounting-lead (money shown) | New `scripts/tests/booking_lookup_group.php`: page_harness, rolled back, refuses Rosalyn's. Cases: same-type split; mixed; one room cancelled; deleted row; expired POST refused. Browser check desktop 1400 + phone 390 |
| B2 API create parity | F3 | api/bookings.php | dept-rooms-lead; gate dept-accounting-lead | New `scripts/tests/api_booking_create.php` on THROWAWAY room types (pattern of mixed_room_booking). Cases: rate plan on / off; joined price = assigned combination; member-stock guard. Plus `rate_plan_rules_rp3.php`, `linked_rooms.php` |
| B3 History snapshot on online bookings | F4 | includes/booking-timeline.php, booking.php, includes/mixed-booking.php, api/bookings.php | dept-rooms-lead (+ tell dept-logs-lead); gate qa-auditor (opus) | `booking_history_test.php` (pure, add an online-with-package case), `booking_created_by.php`, `mixed_room_booking.php`. Shared include = full suite once at ship, at Liwonde |
| B4 API details + quote | F5 (API part), F6 price, F7 | api/booking-details.php, check-availability.php | dept-rooms-lead; gate qa-auditor | `booked_rate_line_test.php`; new joined-quote case in `api_booking_create.php`. Browser check of booking.php summary (joined type) |
| (in cancellation build) | F2 + F1's kept-fee display | booking-lookup.php, config/database.php `rh_cancel_booking_group` | cancellation builder; gate dept-accounting-lead | `group_lead_cancel.php`, `group_noshow_charge.php`, the spec's `cancellation_policy.php` |
| LATER | F6 rest, F9, F10, F11, admin create packages | | | |

## 4. Owner questions (only real decisions)

**OWNER DECIDED 2026-11-14 (these override the recommendations below):**
1. YES: guest cancel of a multi-room booking cancels the whole party (only rows under the guest's proven email),
   listing every room + reference + total fee + refund before the button. Company-billed parties: online cancel
   hidden, "contact the hotel" shown. Built inside the cancellation build.
2. NO: no company field on the website. Staff attach the company after confirmation (already works).
3. YES: emails get "booked rate x N nights", the rate plan line and every room reference for groups (appended block,
   stored templates untouched). After the cancellation build.
4. WORDING ONLY: heading "Booking Received" for pending bookings. The payment line is NOT changed (owner: "if it's
   only a wording thing"); ASSUMPTION: the hard-coded "collected at check-in" stays until the owner asks.
- Fact confirmed: NO outside client uses api/bookings.php today. F3, F5 (API part) and F6 drop to LATER.

1. **Guest cancels a booking that has several rooms (F2).** Cancel all the party's rooms, or let the guest pick?
   Recommended: cancel the whole party (only rooms under the guest's own email). Before the button, list every room and reference, plus the total fee and refund. This matches admin, which offers "whole group" first.
   Part of the same decision: a group billed to a company (`billing_account_id` on the lead; the members follow it) would let a guest cancel the company's rooms online. Recommended: for company-billed bookings, hide online cancel and tell the guest to contact the hotel.
2. **Company on the website.** Should the public form offer "booking for a company / invoice to company"?
   Recommended: no. Staff attach the company after confirmation from the billing panel, and linked rooms follow the lead (already works). A company field would be a new FEATURE with its own vetting (pay-later risk).
3. **Guest emails (F10).** Add "booked rate × N nights", the rate plan line and every room reference for groups, as an appended block without editing the stored templates?
   Recommended: yes, after the cancellation build, which adds an appended block the same way.
4. **Confirmation wording (F11).** For pending bookings: heading "Booking Received", and the payment line taken from the `payment_policy` setting instead of a hard-coded "collected at check-in"?
   Recommended: yes.

Fact to confirm (not a decision): does any outside client call `api/bookings.php` with an API key today? Not verified (no DB read). Recommended: fix B2 either way; it is one file.

## 5. Overlap with the cancellation-policy build and order

Shared touch points:
- **booking-lookup.php is a hard collision.**
  - The spec rewrites the notice check :102-:122 (fee quote, "I accept" tick, `rh_days_before_arrival`) and the details block (~:291-:340).
  - B1 rewrites the details block :255-:321 and the status check :98.
- **`rh_cancel_booking_group`** (database.php :10663; the spec cites :10649, so lines have drifted). The spec adds `$feeChoice`; F2 needs a refund-status parameter. Do both in one edit.
- **Stamp placement in booking.php: amend the spec.**
  - The single and split inserts share ONE loop (:587-:709). Linking is at :714-:719 and the commit at :739.
  - So ONE `rh_cancel_policy_stamp($pdo, $createdBookingIds)` call between :719 and :739 covers both paths.
  - The spec's "after the single insert ~:561" would stamp inside the loop, before linking, and get the group kind wrong.
- **Stamp placement in mixed-booking.php:** after :578 and before the commit at :587, as the spec says.
- **Stamp placement in api/bookings.php:** after the combination assignment (:365-:376) and before the commit at :383, not "after the insert ~:317". The API never links rooms, so its kind is always individual.
- **B3 touches the same three create files** (the post-commit log calls).
- **api/booking-details.php :152** is changed by both the spec (`cancellation_policy`) and B4.
- **Do F5 before the policy goes On**, or a guest could trigger a fee on a soft-deleted booking.

**FINAL ORDER (2026-11-14, after owner answers): see `.claude/NEXT_BUILD_ORDER.md`. It supersedes the list below.**

Original suggested order:
1. **B1** (lookup display, deleted filter, expired): small, PATCH, ships first. It frees booking-lookup.php for the cancellation build.
2. **B2, then B3**: both touch the create paths. Ship them before the cancellation builder adds the stamp, so the stamp lands on the fixed code. Alternatively, B3 can be given to the cancellation builder in the same edit of those three files, but not in parallel.
3. **B4**: before the cancellation build, or hand its api/booking-details.php part to that builder.
4. **The cancellation build**, including F2 (guest whole-group cancel with a per-room fee and pending refunds), once owner Q1 is answered.
5. **LATER items** (F9, F10, F11 after owner Q3/Q4).
