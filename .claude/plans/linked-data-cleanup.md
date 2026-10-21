# Linked-data cleanup (owner request 2026-10-19)

Owner: "When a booking, an order or anything is deleted or cancelled, everything linked to it
must go with it. We don't want to delete or cancel a booking and still have tabs open. The lead
decides what else goes with it, so the database stays healthy even when cleaning up."

## The rule every lead applies (one parent action → every linked row handled)

When a record is **deleted, cancelled, voided, expired or marked no-show**, the lead that owns
it lists every linked row in every table (use the link map below + grep for the parent id) and
gives each one exactly one of these outcomes:

| Outcome | Use for | Examples |
|---|---|---|
| **CLOSE** — cancel/void/release with a reason, same transaction | live, operational rows that would keep "running" | open POS tabs / room-charge orders, kitchen tickets, ready notifications, pending housekeeping/maintenance tasks, room holds (`booking_rooms`, `bookings.individual_room_id`, `rooms_available`), gym class enrollments / slot reservations, reminder queues, quotations still "sent" |
| **DELETE** with the parent | pure child rows that mean nothing alone (+ their files on disk) | photos, amenities, recipe lines, barcodes, user permissions, review responses, gallery rows of a deleted room type |
| **KEEP** (never deleted) | money and history | payments, receipts, invoices, credit notes, MRA queue, folio charges (voided, not deleted), stock movements, audit/timeline logs. Reversal is a void/refund/credit note, never a DELETE |
| **BLOCK** the action with a plain message | when the parent can't go without breaking money or history | hard delete of something with payments; deleting an ingredient used in a recipe |

Hard-deleting a parent that has KEEP rows is not allowed — soft delete (`deleted_at`) or cancel
instead. Everything in one transaction: either the parent and all its linked rows change, or
nothing does. Money effects (voiding folio charges, refunds, stock restore) reuse the existing
helpers and are reviewed by `dept-accounting-lead` / `dept-stock-lead`.

## Link map (live DB `p601229_Rosalyns_Hotel_V2`, 2026-10-19)

Real FKs (MySQL does these itself): bookings → booking_charges, cancellation_log,
booking_timeline_logs, booking_payments, booking_packages, booking_date_adjustments (CASCADE);
housekeeping_assignments.linked_booking_id, room_maintenance_schedules.linked_booking_id (SET NULL);
stock_orders → stock_order_items/audit/deliveries/splits (CASCADE); individual_rooms →
individual_room_blocked_dates, room_inspections (CASCADE); stock_ingredients → batches,
adjustments, counts, in_log, barcodes, wastage (CASCADE), recipe_ingredients (RESTRICT);
stock_recipes → stock_recipe_ingredients (CASCADE); menu_categories → menu_items (RESTRICT);
housekeeping_assignments → housekeeping_audit_log, room_maintenance_schedules →
maintenance_audit_log (CASCADE). Everything else is an id column with NO FK — the code must do it:

- `booking_id`: booking_audit_log, booking_financial_audit, booking_notes, booking_rooms,
  booking_status_log, credit_notes, guest_communication_log, payments (booking_type='room';
  for 'restaurant' it is the stock_orders.id), quotations, reviews, **stock_orders** (room tabs /
  room service), tentative_booking_log
- `booking_reference`: conference_bookings, credit_notes, payments, quotations, logs
- `order_id`: pos_ready_notifications, station_messages, stock_kds_events (+ booking_charges.stock_order_id);
  stock_order_items unserved lines of a REFUNDED order are CLOSED by `rh_close_refunded_order_lines()` (till refund + payments refund sync); data-health FAIL check covers it
- `payment_id`: credit_note_applications, mra_submission_queue, payment_audit_log, receipt_events
- `member_id`: gym_attendance, gym_class_enrollments, gym_member_audit_log, gym_reminder_log, gym_slot_reservations
- `event_id`: event_inquiries · `conference_id`: payments
- `individual_room_id`: booking_rooms, bookings, housekeeping_assignments, individual_room_amenities,
  individual_room_photos, individual_room_pictures_archive, room_maintenance_blocks,
  room_maintenance_log, room_maintenance_schedules, stock_orders
- `room_id` (room type): blocked_dates, bookings, gallery, reviews, room_blocked_dates

Live snapshot 2026-10-19: both hotels clean (1 booking each). One orphan `gallery` row in
Rosalyn's (room type deleted, gallery row left) — proof the room-type delete path leaks.
The gap is in the code paths, not (yet) in the data.

## Known gap already confirmed

`includes/booking-soft-delete.php` and `cancelRoomBookingSettled()` release the room but do not
touch open `stock_orders` with that `booking_id` (unpaid room tab / room service) — a deleted or
cancelled booking can leave a tab open and a kitchen ticket live.

## Findings and decisions

(filled in by the orchestrator from the lead audits)

### Events / conference / gym (audit 2026-10-19)
- F1 high gym-members.php:645 member delete is a bare DELETE → cancel booked future reservations, delete enrollments + reminder_log, close open visit, keep attendance/audit; block when the linked gym_inquiry has payments.
- F2 high gym-inquiries.php:232 inquiry cancel leaves linked member active → set member cancelled + F3 helper.
- F3 high gym-members.php:614-629 status → cancelled/expired/suspended leaves future bookings/enrollments → new helper gym_member_release_future(pdo, memberId), called by F1/F2/F3.
- F4 med gym-inquiries.php:123 delete allowed with a linked member → BLOCK; transaction + FOR UPDATE.
- F5 med events-inquiries.php:74, gym-inquiries.php:118 delete ignores credit notes → BLOCK like conference; transaction + lock.
- F6 med conference-management.php:324 room delete ignores completed/cancelled enquiries → BLOCK on any enquiry.
- F7 low payment-block checks ignore soft-deleted payments → include them (accounting to confirm).
- F8 low gym-classes-lib.php:185 class delete not in a transaction.
- OWNER: should cancelling a member void the unpaid bill on its linked gym inquiry?

### Stock (audit 2026-10-19)
- F1 high cancelRoomBookingSettled / rh_soft_delete_booking never close the booking's open stock_orders → shared helper (proposed rh_close_booking_stock_orders) reusing rh_restore_pos_order_stock + rh_restore_room_service_charge_stock, loops from api/cancel-order.php:60-97 / api/void-order.php:60-90 moved into restaurant-payment-sync.php. Combine with restaurant lead's hook spec.
- F2 med voidBookingCharge (config/database.php:8083-8103) always restores, ignores kitchen status; false return ignored → use rh_restore_room_service_charge_stock when stock_order_id set; log false.
- F3 med product-management-mode.php:214-225 product delete leaves stock_recipes; history check misses booking_charges.source_item_id.
- F4 med stock-ingredients.php:135-139 archive with stock on hand drops it from valuation silently. OWNER decision (rec: block until counted/written off).
- F5 low purchase-orders.php:270-280 cancel/close don't check rowCount (false "cancelled" message).
- F6 low PO close short leaves no note.
- Correct today (stock): ingredient hard delete blocked with history; supplier delete; PO line locks; order delete/cancel/void restore exactly once.

### Restaurant (audit 2026-10-19)
Order statuses that exist: placed (only open one), paid, completed, cancelled, voided, refunded.
- HOOK (spec): new admin/includes/pos-order-close.php with rh_close_pos_order($pdo,$orderId,$userId,$reason,$mode) extracted from api/cancel-order.php + api/void-order.php, and rh_close_orders_for_booking($pdo,$bookingId,$userId,$reason): array. No own transaction; caller calls it BEFORE recalculateBookingFinancials / refund target (config/database.php ~8572-8585). Selects placed orders (FOR UPDATE) + completed room-service orders with live charges. Cancel when unpaid + nothing started, else void. Restores/wastes stock (rh_restore_pos_order_stock), voids folio charges (rh_restore_room_service_charge_stock), sets void fields, lines kds 'void', deletes ready notifications, acks station messages, audit + kds event rows. Throws when blocked → booking action rolls back.
- F1 high booking cancel / soft delete / no-show never close orders → rooms lead wires the hook.
- F2 med admin/stock-orders.php:654-750 cancel/void leave ready pings + station notes, no void fields/reason → use rh_close_pos_order; backstop in api/pos-notifications.php:157 (order status not cancelled/voided).
- F3 med api/kds-action.php:1240 86-last-line cancel leaves ready pings.
- F4 med admin/menu-management.php:237-263 delete ignores open orders/history → BLOCK on open line, soft-delete (is_available=0) with history.
- F5 low cancel/void doesn't tell stations cooking the food → "STOP — voided" station message.
- F6 low deleted item id stays in pos_deals.item_ids.
- NOTE: rh_delete_pos_order hard-deletes payments/receipt_events by owner decision 2026-10-18 (pos_deleted_orders snapshot) — the one allowed exception to KEEP.
- ASSUMPTION (owner may overrule): a booking action with a PAID order leaves it as a settled sale; a PART-PAID order blocks the booking action with a plain message (no automatic refunds).

### Accounting (audit 2026-10-19)
KEEP confirmed for every money table (only exception: rh_delete_pos_order, owner-approved).
- F1 high api/payments.php:886-929 API payment delete bypasses every guard (no reason/actor/audit, receipted/completed/checked-out/restaurant payments deletable) → refuse completed or receipted payments ("use refund / credit note"); allow only pending drafts without receipt_number; audit row + timeline.
- F2 high config/database.php:8422/8591 allocateBookingRefunds refunds credit_note-method payments → exclude; re-open the CN (amount_used/balance + reversal application row). Same in admin/payment-refund.php "same method".
- F3 high = booking-close hook (rooms wires it); soft-delete blocker must also refuse while a live order exists if the hook can't close it.
- F4 med voidBookingCharge ignores linked stock_order → if order is live, block "void order <REF> from Orders & Tabs" (stock fix covers the wastage rule).
- F5 med pos-order-delete.php:315/630 MRA guard reads only payments.mra_status → also check mra_submission_queue; list deleted receipt numbers in notes.
- F6 low admin/pos-accounting.php:317 accountant notes DELETE+INSERT erases history → insert only on change.
- F7 low nothing writes payment_audit_log.
- F8 low cancel leaves pending/processing gateway payments open → mark cancelled with a note in the same transaction.
- OWNER: (1) cancel with posted folio charges: keep all billable (today) vs void unconsumed + keep consumed (proposal). (2) keep receipt_events on POS hard delete? (3) cancelled booking paid by credit note → re-open CN (proposal) vs cash refund.

### Rooms (audit 2026-10-19)
- F1 high no cancel/no-show/expiry/delete path closes the booking's orders → call rh_close_orders_for_booking (restaurant hook) inside each transaction; block undo check-in while an order is open.
- F2 high room holds (booking_rooms.released_at, bookings.individual_room_id/room_combination_id) never released on cancel/delete/no-show/expiry (8 sites: database.php:8556/229/336/6102, booking-soft-delete.php:116, booking-details:663, bookings:~2157, auto-email-jobs.php:714) → one helper rh_release_booking_holds (uses rh_release_booking_room_assignment, timeline line), batch-friendly for page-load sweeps. Restore relies on autoAssignIndividualRoom.
- F3 med quotations stay 'sent' → same helper sets 'expired'.
- F4 med admin/api/tentative-actions.php:160 expire_now on a paid pending booking → BLOCK, send to cancel.
- F5 med no-show duplicated + not atomic (booking-details.php:638-691, bookings.php:~2146-2200) → one rh_mark_booking_no_show in one transaction.
- F6 med individual room delete (individual-rooms.php:485-506, api/individual-rooms.php:479-508) → one rh_delete_individual_room: BLOCK on live/tentative bookings or used combinations; is_active=0 when history; else delete amenities, photos (+files), open tasks/blocks, unused combinations.
- F7 med room type delete (room-management.php:584-643, api/room-types.php:365-409) → one shared function: BLOCK on individual rooms/combinations/bookings/reviews; delete gallery (+files), blocked_dates, room_blocked_dates. API path made the live orphan gallery row.
- F8 med rh_checkout_with_gate (database.php:8810) skips closeFolioChargedRoomServiceOrders + booking_rooms release that processGuestCheckout does.
- F9 low housekeeping/maintenance task delete cascades away its own audit rows → rh_log_event snapshot first (full fix = schema, owner).
- OWNER: group bookings (primary_booking_id) on cancel/delete: cascade / only this one / ask each time (lead recommends ask, default whole group). Reviews on room type delete (rec: block). Housekeeping delete → soft-cancel? Delete the one orphan gallery row?

### Owner decisions
- 2026-10-20 (owner, answered in session): (1) group bookings on cancel/delete: ASK EACH TIME, default = whole group. (2) consumed room service on cancel: KEEP BILLABLE - food the kitchen started/served stays charged to the booking, only unconsumed is voided; the booking action is no longer refused for it. (3) cancelled booking paid by credit note: back to the credit note (as built). (4) POS order hard delete: KEEP receipt history (receipt_events stay). (5) gym member cancelled: VOID the unpaid part of the linked gym enquiry bill; paid money handled by refund/credit note. (6) orphan gallery row 43 (Rosalyn's): delete it.
- 2026-10-19 Stock F4: archiving an ingredient with stock on hand is BLOCKED until counted or written off (done in admin/stock-ingredients.php archive action).
- ASSUMPTION (accounting FIX, 2026-10-19; owner "CN re-open vs cash" pending): money a guest paid BY credit note is never refunded in cash. On a booking cancel it is returned to the credit note first (CN-first, then cash for the rest): amount_used down, balance up, status back to active/partially_applied, a NEGATIVE row in credit_note_applications, and a completed refund row (method credit_note, no credit_note_id so voiding the note never undoes it). A voided/expired note cannot be re-opened: that share stays on the booking as credit owed back (never paid in cash). Same rule in admin/payment-refund.php for a credit-note payment (store credit still issues a new note). Early-checkout / no-show refunds now exclude credit-note payments from cash; the CN share stays as credit owed back (not auto re-opened there).
- Orchestrator default pending owner #3 (consumed food): rh_close_orders_for_booking refuses the booking action when a placed order has food the kitchen already started (staff charge or void it in Orders & Tabs) — it never writes food off silently.

### Progress
- FIX done: stock F2/F3/F5/F6 + F4; restaurant hook + F2-F6; events-gym F1-F8; rooms F1-F8; accounting F1, F2, F4, F5, F6, F8. Tests all green (Rooms linked data 41, data health 38); browser check 16 screens desktop + 3 at 390px clean; QA → ship 2026-10-19.
