# Step 8e — Joined rooms on demand, auto-combine, rate plans, merge of split joined bookings, room-price tax lines (SPEC)

FEATURE (owner requests 2026-11-15, incl. the owner update of the same day) + one owner-approved data correction.
Author: dept-rooms-lead, audit only (no code, no DB writes). Rosalyn's is LIVE: no tests, writes or emails there; Liwonde is
the test mirror. Guest names never appear here (ids only; private detail in gitignored `storage/private-notes/8e.md`).
Line numbers: Rosalyn's `main` at cd6620c.

## 0. Facts found (they shape the plan)

| # | Fact | Source |
|---|---|---|
| F1 | Rooms 17 and 18 **do not exist** at Rosalyn's; combination 1 "Family Room 101A + 101B" (type 7, override 180,000, max 4, is_active 0) is a dangling row. Owner update: the ONLY rooms that join are **7A + 7B** = individual_rooms **id 14 (7A)** and **id 15 (7B)**, both room type **2 (Superior Suite)**, active, no capacity override (14 status occupied today, 15 available). | Rosalyn's read (owner-approved) |
| F2 | 7A and 7B are **free** for 27-29 Nov and 4-8 Dec (no live booking holds them as primary or via booking_rooms, no room blocks). Type 2 has 16 sellable rooms and at most 1 type-2 booking on any of those nights, so taking 2 for the pair leaves stock. | Rosalyn's read, 2026-11-15 |
| F3 | Type 7 "Joined Room": no own individual rooms, no active pair, total_rooms 2, 735,000/night, max_guests 4, no occupancy prices. | Rosalyn's read |
| F4 | All four rows were made on **admin/create-booking.php** by admin user 23 (timeline "Created on the New Booking screen", `price.source = room_type`, rate 735,000). No combination queue ran. | booking_timeline_logs |
| F5 | **Pair 1 belongs to a 3-room group**: 2100009092 and 2100009093 both have `primary_booking_id = 2100009091` (client_uuid `...:b1`/`...:b2`). 2100009091 was not read (outside scope) and is not in the Liwonde mirror (mirror's newest booking 13 Nov 15:44; pair 1 made 20:10). Pair 2: 2100009089 is the group lead, 2100009090 points at it. | Rosalyn's read |
| F6 | All four: confirmed, unpaid, no deposit, no rate plan, no cancellation-policy snapshot; prices all-in (VAT 17.5% + levy 1% extracted): pair 1 each total_amount 1,252,911.39 / VAT 217,088.61 / levy 12,405.06 / total_with_vat 1,470,000; pair 2 each 2,505,822.78 / 434,177.22 / 24,810.13 / 2,940,000. | Rosalyn's read |
| F7 | **Probable third split pair** in the mirror: 2100008876 (4 guests) + 2100008877 (1 guest, primary 2100008876), type 7, confirmed, unassigned, 24-27 Dec 2026, 3,413,340 each (1,137,780/night). Ids below 2100009089, so mirrored from Rosalyn's. Not named by the owner. | Liwonde DB |
| F8 | "Shared" sale mode already exists (admin/individual-rooms.php:324-326, :397-403): members keep their own type and sell on their own; a joined booking takes **both** only for its nights via `booking_rooms` + `bookings.room_combination_id`; `rh_booking_rooms_factor_sql` :4474 counts it as 2 rooms. This IS the stay-only join. | code |
| F9 | `assignRoomCombinationToBooking` (:4737) writes no money: only `individual_room_id`, `room_combination_id` (:4824-4825) and `booking_rooms` (:4826). | code |
| F10 | A room price is always the ALL-IN amount; `rh_stay_totals` (includes/pricing.php:235) extracts VAT and levy from it (net = price / (1 + vat% + levy%)), and returns `net, vat, levy, total_with_vat, vat_rate, levy_rate` (:268-275). VAT is off when `vat_mode()` = off (config/database.php:1132, `rh_vat_enabled` :9206); levy is on when `tourism_levy_enabled` and `tourism_levy_percent` > 0. | code |

## A. Which rooms can join, how a stay-only join is stored, Rosalyn's data

**Rule (pinned):** only a **defined, active `room_combinations` pair** can be joined, never any two free rooms.
- `is_active = 1`, sale mode **shared** = joinable on demand: sold as the joined type only for the nights a joined booking holds it; every other night both rooms sell as Superior Suites.
- `is_active = 1`, sale mode **joined_only** = permanently joined (unchanged).
- `is_active = 0` = **not sold** (unchanged). Do NOT reinterpret inactive as joinable: the deactivate message (individual-rooms.php:414) promises "It is no longer sold".

**Storage (no migration):** `bookings.room_combination_id = pair`, `bookings.individual_room_id = room_a` (primary pointer), two live `booking_rooms` rows written by `syncBookingRooms` (:3625). Nights blocked = check_in..check_out-1, because every per-room check reads booking_rooms with the booking's own dates (`checkIndividualRoomAvailability`, `getRoomTypeNightlyAvailability` :5526-5537, `rh_individual_room_locked_conflict`). Cancel / no-show / checkout / delete release booking_rooms, so both rooms free together. An unassigned joined booking reserves the first free pair through `rh_combination_unassigned_holds` (:4584), `rh_rooms_held_for_combinations` (:4619), `rh_room_needed_for_joined` (:4662).

**Rosalyn's data (owner-approved, done by staff in Individual Rooms > Room Combinations, which validates and logs; Liwonde first):**
- NEW row: name "Joined Room 7A + 7B", Book As = Joined Room (type 7), room A = 7A (id 14), room B = 7B (id 15), sale mode **Shared** (they stay Superior Suites), **Active**, Max Guests **5**, Rate Override **empty (NULL)**.
  Why NULL, not 735,000: with NULL the price comes from the joined type itself (type 7 price per night, its occupancy prices and its rate plans), so a later change to the Joined Room price or a rate plan flows through; a hard 735,000 override would freeze the price and silently ignore future price edits. Today both give 735,000.
- Combination 1 (101A + 101B, rooms gone, inactive): **leave it inactive and untouched** (proposal). It is never sold (inactive, and `getAvailableRoomCombinations` joins live rooms only), it keeps type 7 recognised as a joined type (§B predicate), and deleting it gains nothing. Never activate it: it carries the wrong 180,000 override. Optional later: owner may let staff delete it once the 7A + 7B row exists (the delete guard in §B keeps that safe).
- Room type 7 `max_guests` 4 -> 5 (§F).

## B. Root cause of the oversell + fix

`checkRoomAvailability` (:5130): :5175 capacity = `rooms.total_rooms` (2); :5225 `getRoomCombinationAvailabilitySummary` returns null (no ACTIVE pair, :4716); :5244 `getRoomTypeIndividualAvailabilitySummary` returns null (no room has type 7, :4950); falls to the legacy count :5295-5342 = 2 units per date with no rooms behind them.
Same fallthrough: `getRoomTypeNightlyAvailability` :5418-5420 returns null whenever the type has no OWN rooms (even with active shared pairs: **pre-existing bug, would stay live after 7A + 7B is created**: the calendar/forecast `getBookedDatesForRoom` :5635 and includes/room-management.php:1299 would keep using total_rooms 2); `rh_room_type_sellable_count` :3820-3841 falls back to rooms_available (booking.php:1007, includes/booking-widget.php:33, room.php:99, admin/room-management.php:686); `rh_room_type_unit_total` :3848 (reports, admin/reports.php:512).

Every create/mutate path reaches the central check, so the fix lives in config/database.php only:
booking.php :375-412 / :547; includes/mixed-booking.php :70, :225, :475 (queue :269, :358, :480); api/bookings.php :219, :304 (queue :225, :366); admin/create-booking.php :141, :759 (queue :445, picker :191); admin/bookings.php confirm :1064-1074, modify_booking :1341, extend :3046 / :3178, upgrade_room_type :3242 -> includes/booking-room-change.php :313 (all via `rh_validate_booking_stay_change` :6197 -> :6263), upgrade options `rh_room_change_options` (~:190); check-availability.php :189 / :204; api/availability.php :128. `getRoomCombinationAvailabilitySummary` has exactly one caller (:5225), so changing its null return is safe.

**Predicate (pinned):** new `rh_joined_type_without_pairs(int $typeId): bool` after :3879 = `!roomTypeHasActiveCombinations($typeId)` AND some `room_combinations` row has `combined_room_type_id = $typeId` (any is_active) AND no `individual_rooms` row with `room_type_id = $typeId AND is_active = 1`. True -> **0 sellable units** on every date. Legacy types never named by a pair are untouched; a deactivated joined_only pair (rooms still of the joined type) keeps selling its rooms as today. Today it flips only type 7 at both hotels (builder lists flipped types at Liwonde before/after). Never edit total_rooms as the fix.

**Delete guard (closes the hole the predicate leaves):** admin/individual-rooms.php `delete_room_combination` (:440-460): refuse deleting the LAST `room_combinations` row naming a type that has no own active rooms: "This is the only pair for <type>. Deactivate it instead (deleting it would let <type> be sold with no rooms behind it)." Test §H-11.

## C. Price and rate plans for a joined type (all create paths + the quote)

**Base nightly rate** = the pair's `price_override` if set, else the joined type's own price, set on EVERY occupancy price (a joined room has one price whatever the occupancy): booking.php :403-411 (headline) and :598-607 (per room), admin/create-booking.php :477-485 and :634-643, includes/mixed-booking.php `rh_mixed_apply_combination` :96-105, check-availability.php :172-178 via includes/joined-quote.php `rh_joined_quote_rate` :42 / `rh_joined_quote_room` :52, api/bookings.php :230-238. With the 7A + 7B override NULL -> 735,000.
Note: occupancy prices of type 7 itself are ignored for a pair (overwritten by the single rate). Type 7 has none today; keep it that way (document in the guides entry).

**Rate plans:** `applyDynamicPricing($pdo, <joined type id>, in, out, nights, base)` (includes/pricing.php:42 -> `rh_apply_rate_plans` :99) runs AFTER the base is set, so a plan applies only when it targets the joined type (or all types) (`_planAppliesToRoom` :126) and matches the stay (`_planMatchesStay` :129: dates, nights, lead time). Plans are chosen once per stay and give ONE final nightly rate (surcharges then discounts; non-stacking = best one per group); there is no per-night seasonal calendar in the engine, so a stay crossing a season edge takes the plan that matches the stay as a whole. Paths that apply plans: booking.php :610, admin/create-booking.php :663 (skipped when staff type a price override :656), includes/mixed-booking.php :244, check-availability.php :75 (quote), api/availability.php :140 (quote), Assistant quote includes/ai/ai-tools.php:1607. **api/bookings.php applies no rate plan for any type (by design, :404-407)**: unchanged, but listed so nobody "fixes" it inside 8e.
A plan scoped to Superior Suite does NOT apply to the joined room (the booking's type is 7). Packages: added to the first room only, VAT but no levy (`rh_stay_totals_with_package`).

**Taxes:** the final nightly rate x nights (+ child supplement) is all-in; `rh_stay_totals(..., 'price')` extracts VAT (only when `vat_mode()` != off) and levy (only when `tourism_levy_enabled` and percent > 0) (F10). Same helper on every path above. Rosalyn's: VAT 17.5% + levy 1% (F6 matches).

**Locked at booking:** the saved row carries total_amount, vat_amount, tourism_levy_amount/percent, total_with_vat, rate_plan_id/label/discount and the History price snapshot (`price.source = joined_room`, `joined_rooms_id`). Later rate-plan or price edits never touch it; assignment never reprices (F9); only the upgrade flow reprices (includes/booking-room-change.php). Booked rate x nights is what every screen shows.

**Merge:** survivor keeps its booked figures: pair 1 = 735,000 x 2 = **1,470,000**; pair 2 = 735,000 x 4 = **2,940,000** (VAT/levy as F6). The second row's bill is voided by the close (§D): party totals drop 2,940,000 -> 1,470,000 and 5,880,000 -> 2,940,000, as the owner decided.

## D. "Closed as merged": representation and readers

**Representation (pinned): the existing soft delete, no new status, no migration.** `rh_soft_delete_booking` (includes/booking-soft-delete.php:71) already does "release like a cancellation, no fee, no guest email": confirmed -> cancelled, `cancellation_retained_amount` NULL, rooms + holds released, `deleted_at/by/reason` set, financials recalculated, audit + timeline written; it refuses a row with money on it (:39-47), so payments move first (§G). Reason: `Merged into <survivor ref>: one joined room for the party (owner-approved 2026-11-15)`. Listed only under the Bookings "Deleted" filter; booking-details shows it read-only with the reason (:1672, :1965). The status enum has no 'merged'; adding one is a schema change and not needed.
**Restore must not be used** on a merged row (it would re-open a second bill); restore re-checks availability and is refused while the survivor holds the pair. The victim's booking note says so.

Readers it must stay out of (builder re-checks by Grep): cancellation counts `rh_cancellations_between` :4437 (`deleted_at IS NULL`) and EOD via it (admin/includes/eod-report-data.php:61); Assistant counts includes/ai/ai-decisions.php:968 (`deleted_at IS NULL`); Bookings stats admin/bookings.php:3601 (confirm `$stats_where_sql` excludes deleted); status-excluding readers (reports.php :415/:435/:557/:592/:917, api/reports-export.php :553/:637/:652, EOD :186-192) exclude it by status; fee / credit note / refund paths are never called; Still owed: amount_due must be 0 after the close (verify at Liwonde, also the group lead's balance); guest history (includes/guest-history.php, 6 deleted_at filters); includes/ai (11 files filter deleted_at); guest emails: soft delete sends nothing, scheduler jobs skip cancelled.
Timeline: soft delete writes "Booking deleted" (status_change, new_value cancelled), excluded from counts by `deleted_at`. The extra merge rows must NOT use new_value `cancelled` with action_type status_change/financial/cancellation (:4438).

## E. Locking

- Create paths are right: `rh_related_room_type_ids` before `beginTransaction` (booking.php:534, mixed-booking.php:460, api/bookings.php:296, create-booking.php:746, booking-room-change.php:249), `rh_lock_room_types` first inside, then the re-check and `rh_joined_members_stock_error` (booking.php:554, create-booking.php:773). With 7A + 7B active, related(7) = {2, 7}: a Superior Suite sale and a joined sale serialise on the same locks.
- Two desk users joining the same pair for overlapping nights: `assignRoomCombinationToBooking` locks both rooms FOR UPDATE ascending (:4781-4785), re-checks each (:4813) and `rh_individual_room_locked_conflict` (:4817): the second gets "One of the joined rooms was just taken by booking ...". Keep.
- **Gap to fix:** manual and automatic pair assigns (admin/bookings.php:632 `assign_room_combination`, :701 assign-by-member-room, `autoAssignIndividualRoom` :8889-8905, and the new §J hooks) take no room-TYPE lock and run no `rh_joined_members_stock_error`, so they can take two Superior Suites already promised to unassigned Superior Suite bookings (`checkIndividualRoomAvailability` does not count those). Fix inside `assignRoomCombinationToBooking` when it owns the transaction: read the booking's room_id + `rh_related_room_type_ids` BEFORE `beginTransaction` (:4753); `rh_lock_room_types` right after the booking FOR UPDATE (:4758); add `a.room_type_id AS room_a_type_id, b.room_type_id AS room_b_type_id` to the select (:4768); `rh_joined_members_stock_error([$combination], $comboFrom, check_out, $bookingId)` before writing. When a caller owns the transaction (create paths) it already locked and checked: skip.

## F. Capacity: the joined room takes 5

- Data (owner-approved): room type 7 `rooms.max_guests` 4 -> 5 (Room Types screen, or the merge script, once, logged); 7A + 7B pair `max_guests_combined` = 5. Combination 1 unchanged.
- Where checked: type lists take MAX(type max, active pair max) (booking.php:204/:412/:996, create-booking.php:89/:433/:485, api/bookings.php:180/:238, check-availability.php:179/:445/:503, mixed-booking.php:38/:105, `checkRoomAvailability` :5233). **Type-only checks** (why rooms.max_guests must be 5 too): admin/edit-booking.php:343 (would reject the 5-guest survivor on any edit), `rh_room_change_options`, guest auto-split includes/guest-allocation.php:213 (caller passes the effective max). Rehearsal check: open Edit Booking on the 5-guest survivor (occupancy stays 'triple') and save unchanged; :343 and the tier message :360 must not reject it. No code change for capacity.

## G. One-off merge script (outside the repo)

File `C:\Users\john-paul.chirwa\OneDrive\MSP\one-off-scripts\rosalyns_joined_pairs_merge.php` + README.txt entry. Never committed.
- **Scope:** merge (money, guest count, History) + optional pair assignment. Hard-coded PAIRS: [survivor 2100009092, victim 2100009093, 2026-11-27..29, 1,470,000, guests 3+1, lead 2100009091], [survivor 2100009089, victim 2100009090, 2026-12-04..08, 2,940,000, guests 4+1, lead 2100009089]; refs checked too. `--pair=1|2` (pair 1 waits for OPEN 2). Combination id of 7A + 7B passed as `--combination=<id>` (it does not exist yet) and verified: type 7, rooms 14 + 15, active, override NULL, max 5.
- Loads the app from the hotel folder (needs `rh_soft_delete_booking`, `recalculateBookingFinancials`, `logBookingEvent`, `logBookingAudit` from admin/includes/audit-functions.php, `assignRoomCombinationToBooking`). Aborts if `RH_ALLOW_AUTO_MIGRATE` is on. Dry run by default; `--run --confirm-db=<DB_NAME>` to write. (Loading the app runs the tentative/pending expiry sweeps, exactly as any page view does.)
- **Backup JSON first**: full rows of both bookings and the group lead, their booking_rooms, payments, folio charges, invoices/receipts, booking_notes, rooms id 7, all room_combinations rows, individual_rooms 14/15.
- **One transaction; guards re-checked under lock** (FOR UPDATE ascending; any mismatch -> exit 2, nothing written): ids + refs; room_id 7; status confirmed; deleted_at NULL; no room / combination / live booking_rooms; dates + nights exact; total_with_vat = expected within BALANCE_TOLERANCE; guest counts; primary_booking_id; victim `final_invoice_generated` 0 and no issued invoice; folio_charges_total 0, no open order/tab; payments: victim has none, else abort (the move below is enabled only after dept-accounting-lead signs it off); rooms 14 + 15 free for the stay (`checkIndividualRoomAvailability` with the survivor excluded).
- **Payment move (gated):** re-point the victim's live payment rows to the survivor, recalculate both, History "Payment <receipt> moved from <victim ref> (bookings merged)" on both.
- **Writes, in order:** (1) survivor number_of_guests / adult_guests / child_guests = party totals (pair 1: 4/4/0; pair 2: 5/5/0), no money column; (2) `UPDATE rooms SET max_guests = 5 WHERE id = 7 AND max_guests = 4` once + `rh_log_event`; (3) `rh_soft_delete_booking($pdo, victim, 0, 'Owner-approved merge', reason)` inside the script's transaction; (4) `recalculateBookingFinancials` on survivor and group lead; (5) History: survivor "Joined-room bookings merged" (action_type `modification`, old "3 guests" -> new "4 guests"; pair 2: 4 -> 5), naming the victim ref and "one joined room for the whole party at 735,000/night"; victim "Merged into <survivor ref>" (`modification`, new_value `merged`); `logBookingAudit` on both; (6) booking_notes on both (survivor: "Merged with <ref>: one joined room 7A + 7B for N guests, owner-approved 2026-11-15"; victim: "Closed: merged into <ref>; not a guest cancellation, no fee. Do not restore."); (7) if `--combination` given: `assignRoomCombinationToBooking(survivor, combination)` in the same transaction (History "Joined rooms 7A + 7B assigned").
- No email / WhatsApp, no cancellation path, no credit note. After commit: re-read, print, verify (exit 3 on mismatch); clear the room cache.
- **Rehearsal at Liwonde first:** pair 2 ids exist in the mirror; pair 1 and its lead do not: re-run `scripts/mirror_from_rosalyns.php` (dry run, then `--run`) or rehearse pair 1 on a made-up 3-room group. Check after: survivor bill unchanged, victim under Deleted with reason, cancellations today 0, EOD, Still owed, the lead's group invoice/folio, History on both, nothing in the email send log, 7A + 7B held only on the stay nights and sold as Superior Suites the night after.

## H. Tests

New `scripts/tests/joined_on_demand.php`: static part always; `--db` part refuses unless `realpath` contains "Liwonde" (guard as scripts/tests/guest_history.php:64); one transaction, rolled back. Fixture: a joined test type + a shared pair of two rooms of another type + a rate plan scoped to the joined type.
1. Pair row inactive, no own rooms -> `checkRoomAvailability` unavailable / remaining 0; sellable count 0; nightly all 0; booked dates = every night.
2. Pair active (shared) -> 1 unit; nightly from pairs (not null); booking takes both rooms for its nights only; member type loses 2 rooms those nights, sells them the next night.
3. Pair partly booked individually -> joined type sold out those nights; member type unaffected.
4. Two parallel joins of the same pair, overlapping nights (two connections, as concurrent_booking.php) -> exactly one wins.
5. Manual/auto pair assign when the member type's remaining is already promised to unassigned member bookings -> refused (§E).
6. Permanent joined_only pair -> counts and pricing unchanged.
7. Mixed booking with a joined type -> pair queued + assigned; no active pair -> "not available".
8. Cancel of the joined booking -> both booking_rooms released; both rooms sell again.
9. Price: override NULL -> type rate; plan for the joined type applies on every path (booking.php, create-booking, mixed, quote = save); a Superior Suite plan does not; VAT/levy on/off each; assignment changes no money column.
10. Legacy type never named by a pair keeps total_rooms counting.
11. Delete of the last pair of a room-less joined type is refused; the type stays sold out.
12. Auto-combine (§J): an unassigned joined booking gets the pair when the pair is created/activated, and at check-in; with no free pair check-in is refused with the plain message.
13. Room-price tax lines (§K): both on / VAT only / levy only / both off -> the right lines (static render check).
Rerun: linked_rooms, mixed_room_booking, mixed_booking_post_page, joined_quote, concurrent_booking, concurrent_booking_child, booking_history_test, guest_history, booking smoke. Full `run_tests.php` at ship (config/database.php changed). Browser check: room-management cards + Add/Edit forms (1400 + 390).

## J. Automatic combining ("combine on booking day too")

Already automatic: every create path assigns the pair at save when a free active pair exists (booking.php:695, create-booking.php:905, mixed-booking.php:553, api/bookings.php:372); paid confirmed bookings get `autoAssignConfirmedPaidBooking` (:3285 -> `autoAssignIndividualRoom` :8839, which tries each free pair :8889-8905). Gaps, and the fix:
1. **When a pair is created or activated** (admin/individual-rooms.php save :365-403, activate :419-438): after a successful save of an ACTIVE pair, auto-assign every live (pending/tentative/confirmed) unassigned booking of that joined type with check_out > today (no live booking_rooms), ordered by check_in then id, one `assignRoomCombinationToBooking` per booking (own transaction, §E locks). Report "Assigned 7A + 7B to N booking(s)"; any that could not get the pair are listed. This is what assigns the two merge survivors once 7A + 7B is created (if the script did not).
2. **At check-in** (admin/bookings.php check-in action ~:808, admin/process-checkin.php ~:60, admin/booking-details.php ~:776): before `evaluateCheckInRoomReady` (:3130), if the booking is of a joined type with an active pair and holds no room, call `autoAssignIndividualRoom`; if no pair is free, refuse the check-in: "No free joined pair for this stay. Assign rooms first." (a joined booking holds a whole pair, never none). The clean-room gate then runs on the two assigned rooms as usual.
No scheduler job and no cron (owner rule). Ordering at Rosalyn's: code ships -> merge script -> staff create 7A + 7B (hook 1 assigns survivors) — creating the pair BEFORE the merge would hand it to whichever of the two split rows comes first.

## K. Room management: tax lines on the room price (display only)

File admin/room-management.php (already loads includes/pricing.php :16). No price logic change, no hard-coded rates.
- **Card** :787 `.room-card-price`: under the price, one small line from `rh_stay_totals((float)$room['price_per_night'], 'price')`: "Room <net> · VAT <vat_rate>% <vat> · Tourism levy <levy_rate>% <levy>"; the VAT part only when `vat_rate` > 0, the levy part only when `levy_rate` > 0, no line at all when both are 0. Joined-pair cards (:686 block) show the same for the pair price.
- **Add / Edit forms**: under Price Per Night (:938 edit, :1088 add) and each occupancy price (:943 and the double/triple fields), a live helper line updated on input. Rates come from the server once: `$rmTax = rh_stay_totals(100, 'price')` -> JSON `{vat: vat_rate, levy: levy_rate}`; JS uses the same formula as `rh_stay_totals` (net = price / (1 + v + l), VAT = round(net x v), levy = round(net x l), room = price - VAT - levy). Same on/off rules; when both off: "No VAT or levy is added." Helper text says the price is the all-in amount the guest pays.
- Joined pair Rate Override field (admin/individual-rooms.php:1795) gets the same helper (blank = "uses the Joined Room price").
- UI_REQUEST | admin/room-management.php | room card price + price fields | guests' all-in price hides VAT/levy | small muted breakdown line using existing helper-text tokens, 44px targets unaffected.

## I. Files to change (smallest set)

| File | Lines | Change |
|---|---|---|
| config/database.php | after :3879 | new `rh_joined_type_without_pairs()` |
| config/database.php | :3820 | `rh_room_type_sellable_count`: 0 when the helper is true |
| config/database.php | :4716 | `getRoomCombinationAvailabilitySummary`: zero summary instead of null when the helper is true (single caller :5225) |
| config/database.php | :5418-5420 | `getRoomTypeNightlyAvailability`: no own rooms + active pairs -> continue with pair rooms; helper true -> all 0; else null |
| config/database.php | :4750-4826 | `assignRoomCombinationToBooking`: type locks + `rh_joined_members_stock_error` when it owns the transaction |
| admin/individual-rooms.php | :440-460; :365-438 | last-pair delete guard; auto-assign after an active pair is saved/activated; Rate Override helper :1795 |
| admin/bookings.php, admin/process-checkin.php, admin/booking-details.php | check-in actions ~:808, ~:60, ~:776 | auto-assign a joined booking's pair before the clean-room gate; refuse if none |
| admin/room-management.php | :787, :938, :943+, :1088+, script block | tax lines (§K) |
| scripts/tests/joined_on_demand.php | new | §H |
| .claude/GUIDES_AI_SYNC.md | entry | joined type sold out until a shared pair exists; auto-combine; tax lines; Assistant reads availability via the same functions |
LATER (accounting/reports): `rh_room_type_unit_total` :3848 still counts type 7 as total_rooms 2 in occupancy denominators (reports.php:512) when no pair is active.
No change in booking.php, mixed-booking.php, api/bookings.php, create-booking.php, check-availability.php, joined-quote.php: they reach the central check and already price joined types as §C.

## OPEN for Fable / owner

1. **Pair 1 group lead 2100009091**: owner approval to read it (type, totals, group invoice); dept-accounting-lead reviews removing one 1,470,000 member from the group bill. Pair 2 can run without it.
2. **Third split pair** 2100008876 + 2100008877 (24-27 Dec, 4+1 guests, 1,137,780/night) seen in the mirror: real at Rosalyn's, and merge it too? Until merged, the 7A + 7B auto-assign gives the pair to only one of them.
3. Payment move if a payment lands before the run (dept-accounting-lead): default abort.
4. Check-in refusal for a joined booking with no free pair (§J-2) is a new gate: owner OK (recommended; the alternative is a guest checked in with no room).
5. Fable spec check (cost rule 2a): §B predicate + delete guard, §D soft-delete-as-merge, §E lock change, §J hooks.

## 9. Fable spec check 2026-11-15: GO WITH CORRECTIONS (all binding on the build)

1. §E: `rh_joined_members_stock_error` -> `checkRoomAvailability` (database.php:4002) refuses check-in before today (:5162-5166). Pass `max($comboFrom, today)`; skip when that is >= check_out. Add the case to §H-5.
2. §G: delete step 7 and `--combination`. The script never assigns the pair itself (it would skip §E locks + stock check). Guard: survivor unassigned OR holding exactly rooms 14 + 15. Order: merge first, then create the 7A + 7B pair (Shared) so J-1 assigns the survivor.
3. §J-2 gate: condition = `roomTypeHasActiveCombinations() || rh_joined_type_without_pairs()`; run the hook only AFTER `validateCheckIn` passes (admin/process-checkin.php room gate :60 runs before :72); skip for the `ended` choice (admin/bookings.php:810); never inside `evaluateCheckInRoomReady` (render/preview callers admin/booking-details.php:1776, includes/ai/ai-actions.php:1102).
4. §B: the same refusal as delete when saving a pair changes the Book As type of the last pair of a type (admin/individual-rooms.php:366-373).
5. §A: the pair form defaults to joined_only (admin/individual-rooms.php:326, :1817), which re-types both rooms to type 7 (:397-399). The owner must pick **Shared**; after saving, rooms 14/15 must still be type 2. Guide + browser check say so.
6. §D: api/reports-export.php:537-541 and :565-568 lack `deleted_at IS NULL`; add both, add the file to §I.
7. §C: room upgrade prices a joined target from the type's rack/occupancy price (includes/booking-room-change.php:66-77), ignoring pair override and rate plans. Owner 2026-11-15 "perfect logic for joining and rate plans": make upgrade use the same price source as create (pair override else type rate plan, booked rate x nights) if it fits the existing helper; otherwise log LATER in TASKS.md, not mid-step growth.

Owner answers 2026-11-15: group lead of pair 1 = id 2100009091, include it (group bill drops by exactly one member, accounting lead checks); copy both pairs + group lead to Liwonde with made-up details and rehearse there; third pair 2100008876/877 shown in the Rosalyn's dry-run, owner confirms before --run; check-in refused when no joined pair is free.
