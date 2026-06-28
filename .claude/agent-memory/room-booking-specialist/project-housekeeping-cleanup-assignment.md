---
name: project-housekeeping-cleanup-assignment
description: Checkout-cleanup must be one-open-per-room; how room cleaning state gates room assignment and how rooms get freed
metadata:
  type: project
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
