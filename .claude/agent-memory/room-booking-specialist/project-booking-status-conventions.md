---
name: project-booking-status-conventions
description: Booking status values use hyphens (checked-in/checked-out/no-show); rooms_available decrement rules per status
metadata:
  type: project
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
