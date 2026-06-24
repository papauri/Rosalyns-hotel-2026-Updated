---
name: project-booking-availability-engine
description: Canonical room-availability engine, where it lives, and how the admin create-booking flow must use it
metadata:
  type: project
---

The canonical availability engine is `checkRoomAvailability(int $room_id, string $check_in, string $check_out, ?int $exclude_booking_id, int $child_guests, int $child_rooms_needed)` defined in `config/database.php` (~line 3133).

- Returns an array with keys: `available` (bool), `remaining_rooms` (int), `room` (row), `error` (string), plus combination/individual breakdowns.
- It already excludes cancelled/no-show via `getBookingStatusesThatBlockAvailability()` and excludes expired tentatives.
- It rejects past check-in dates (`check_in < today`), so admin bookings can start today but not in the past.
- For joined-room types it returns `remaining_rooms` = remaining combinations; for individual-room types it returns physical-room remaining counts. The same call works for all three room-type shapes.

**Why:** `admin/create-booking.php` historically inserted bookings inside a transaction with a `FOR UPDATE` lock on the `rooms` row but performed NO server-side conflict check — availability was only verified client-side via AJAX. This allowed double-booking under a race.

**How to apply:** Any booking-creation path must call `checkRoomAvailability` for each room type INSIDE the locked transaction (after the `SELECT ... FOR UPDATE` rows-lock) and abort if `remaining_rooms < requested qty`. Aggregate requested qty per room_id first (multiple room lines can target the same type). This guard is now in place in create-booking.php.
