# Guides + Assistant sync — what's been done since the last sync

Owner 2026-11-14: "Keep the guides and AI Assistant always for last. Have a document that just updates
the guide and Assistant on what's been done. We will do these when prompted at the end of the day or
when prompted."

## Rules
- **Never run this on your own.** Guides (`guides-kb-lead`) and the Assistant (`dept-ai-lead`) are
  updated ONLY when the owner says so ("do the sync", "end of day", "update guides/Assistant").
  Real work always ships first and never waits for them.
- **Every ship adds one entry below** (orchestrator, in the same commit as the ship): commit hashes
  (Rosalyn's / Liwonde), what staff now SEE or DO differently, and what the Assistant reads that
  changed (tables, columns, settings keys, statuses, helpers, page URLs, permission keys). Pure fixes
  with no visible change and no Assistant overlap: one line "no sync needed".
- **When prompted:** one `guides-kb-lead` brief (haiku) for all Guides items, one `dept-ai-lead`
  brief for all Assistant items (only if a grep of `includes/ai/` finds an overlap), ONE combined
  review, ship both hotels, then move the done entries to "Synced" with the sync commit hash.
- Both hotels share this list; the file lives in both repos (keep them identical).

## Pending (oldest first)

### Stock count wipe + Delete on Import history (2026-11-15, owner ask) — hashes in TASKS.md
- Guides (Settings > Clear Stock Data, Stock > Import): Clear Stock Data has a new section "Stock counts and the stock
  they added": lists every count, wipes all counts plus the batches/adjustments their approval added and puts item
  quantities back; full backup + snapshot first, logged as stock_count_wipe; refused (with the item named) when stock
  has moved since or an item that keeps stock had its cost averaged with the count; tick "I understand" + type WIPE +
  own password; no Undo on the page (restore from the full backup). Optional tick also removes the Import history
  entries that created the counts. Import page: Delete on finished import entries (committed/failed/cancelled) for
  holders of Clear Stock Data (administrator, super admin only unless switched on); type DELETE + own password; removes
  the entry and its stored file (backup copy kept in backups/import-delete/), does NOT undo the stock it brought in,
  the "already imported" check forgets that file; logged as import_history_delete.
- Assistant: check at sync time whether any read tool reads stock_counts, import_jobs or the data_reset permission
  wording (grep includes/ai/ai-import.php, ai-tools.php, ai-insights.php); no action tools added.

### Joined rooms follow-up (2026-11-15, step 8e follow-up) — hashes in TASKS.md
- Guides (Bookings > Change room type, Individual Rooms, Reports > Occupancy): an upgrade into a joined room is now
  priced like a new booking of it (the pair's rate, else the joined room price, plus the joined room's rate plans) and
  the guest gets the exact pair it was priced on; an in-house guest moves to a clean pair. With two or more pairs the
  first free pair by name is given; for a stay starting today a clean pair goes before one still being cleaned.
  Individual Rooms: the status cards count the rows below (a room with a guest checked in = Occupied; a future
  booking no longer hides a room being cleaned or under maintenance; a switched-off room shows Inactive), a card click works with the 10-per-page list, filtering by the Joined
  Room type lists the pair's two rooms, a room being cleaned can still be assigned. Reports "Occupancy by Room Type"
  Rooms = rooms that can be sold now (a joined room with no active pair = dash), same figure in ADR/RevPAR and CSV.
- Assistant: includes/ai/ai-extras.php:680 falls back to rooms.total_rooms when rh_sellable_room_units_by_type() has no
  entry (a Shared joined type) - rh_room_type_unit_total() now gives the per-type figure the reports use.

### Joined rooms on demand (2026-11-15, NEXT_BUILD_ORDER step 8e) — hashes in TASKS.md
- Guides (Individual Rooms > Room Combinations, New Booking, Bookings check-in, Room Types): a joined room type
  with no ACTIVE pair now shows sold out everywhere (it was sold on its stored room count with no rooms behind it).
  A pair in "On their own and as the joined room" (Shared) mode joins the two rooms only for the nights a joined
  booking takes it. Saving or switching on a pair gives it to joined bookings still waiting for rooms (the message
  lists who got it and who still waits). Check-in of a joined booking with no rooms joins a free pair, or is refused
  ("No free joined pair for this stay"). The last pair of a joined type cannot be deleted or re-typed (deactivate it).
  Rate Override blank = the joined type's own price and rate plans. Room Types cards and price fields show the VAT
  and tourism levy inside the price (each line only when it is switched on). Upgrade into a joined type is priced at
  the pair's rate (one rate whatever the occupancy). Pick Shared, not "Only as the joined room", for 7A + 7B.
- Assistant: availability/quotes go through checkRoomAvailability, so a joined type without a pair now reads 0 left;
  check includes/ai/ for any room-count read that uses rooms.total_rooms / rooms_available directly.

### Refunds on the payout day everywhere (2026-11-15, NEXT_BUILD_ORDER step 8d) — e90b5c7 / 5151a26
- Guides (Dashboard, Payments, Accounting, Company accounts): a refund now shows and counts on the day it was paid
  out on the Dashboard "today" list (with the payout time), the Payments list Payment Date column and its date sort,
  the Accounting payment-method counts and Company accounts "received this month" - the same day as End of Day and
  Reports. A refund still pending shows the day it was raised and counts in no period. The time under Payment Date is
  still when the record was saved. No amounts changed.
- Assistant: check at sync time whether any read tool filters refunds by payment_date (grep includes/ai/ for
  payment_type = 'refund' with a date filter) and apply the payout-day rule.

### Payments accuracy + Payments page (2026-11-15, NEXT_BUILD_ORDER step 8b) — SHIPPED (hashes: see TASKS.md line)
- Guides (Payments, Payment details, Bookings "Payments & balance", End of Day, Reports, Credit notes, email templates):
  click anywhere on a Payments row (or an "accounts that owe" row) to open it; Payment details now shows paid by,
  received by, group and credit note; "Refunded" counts only refunds actually made (pending on its own line, 2 decimals).
  "Payments & balance" lists refunded payments and every refund; Total charged = the bill (kept fee / no-show charge).
  Room invoice email shows the invoice's own totals; its last row is the balance (new tags {{invoice_total_amount}},
  {{amount_paid_amount}}, {{balance_due_amount}}; stored templates unchanged, so they still say "Total Due" above the
  balance). A partly refunded payment can be refunded again up to what is left. OWNER RULES 2026-11-14/15: a no-show is
  money earned per its policy (new End of Day line "Kept on no-shows", on the check-in date, not in ADR/RevPAR; past
  days' totals change); a refund counts on the day it was paid out in every report, pending refunds in no period; the
  levy is a once-off on the room booking. Every payment write now shows in History.
- Assistant: check at sync time. No grep hit in includes/ai/ for rh_eod_earned_revenue, rh_pay_count_day_sql,
  payment_audit_log or find_payment_for_refund; check any refund-by-date figure it reads against the payout-day rule.
- LATER (not pulled): Payments list date column/sort still use the day a refund was raised; dashboard today feed
  (dashboard.php:1461), api/payments.php:182-238, billing-accounts.php:615 and accounting-dashboard.php:376 count
  refunds by the day raised; new date filters cannot use the payment_date index; Rosalyn's stored payment_invoice
  template label "Total Due" (owner); row click on Receipts/Invoices/Quotations/Credit notes; dead code
  api/payments.php:1070-1150, bookings.php:1634, booking-details.php:1423; till refund status rows not in History;
  receipts/CSV still show a per-payment levy share (owner: levy is booking-level).

### Assistant: stay questions by first name (2026-11-15, step 8a) — hashes in TASKS.md
- Guides: staff can ask the Assistant "when did <first name> check in / out", "has X left / arrived", "who checked them
  in / out", "who cancelled X's booking", "was X a no-show", "did X extend", "was X moved". The answer comes from the
  booking's History with the time and the staff member. Lower-case names and the hotel's name in the question are fine;
  if several guests share the name it asks one question listing names, references and dates. Guide 14 / Assistant page.
- Assistant: done in this entry (ai-desk.php, ai-vault.php, ai-tools.php, ai-focus.php, ai-agents.php, evals). LATER:
  in the model path "when did X check out" can still offer a check-out card; room-number questions still go to the
  model; an undone check-in still shows the earlier check-in step; 'expired' is not a search_bookings status; fix round
  verified by tests (1217 pass / 7 known data-dependent), not re-reviewed; no live model check.

### Booking Details dialog compact on phones + tablets, row click opens it (2026-11-14, step 8) — 0a860b9 / 142aee6
- Guides: on the Bookings page, clicking or tapping anywhere on a booking row (not a button) opens Booking Details, on
  phone, tablet and desktop. On phones and tablets the dialog starts with one summary block (guest, room, dates and
  nights, total, balance, booked rate x nights); tapping it or "Full Details" opens the full booking page. Guide 07.
- Assistant: no sync needed (layout only; no table, setting, status, URL or permission changed).

### Assistant sync (2026-11-14, NEXT_BUILD_ORDER step 7) — SHIPPED (hashes: see TASKS.md line)
- Guides: staff can now ask the Assistant "what is the cancellation fee on <booking>" (fee from the booking's locked
  policy, booked rate x nights, tier and days before arrival; pending/tentative/expired = no fee; an already cancelled
  booking shows the fee kept and what is still owed), "what does each room in this group owe", and "who changed the
  <name> setting" (needs staff activity + System Logs). The Assistant quotes a fee but never lowers or waives one:
  that stays on Booking Details. Mention in guide 14 / the Assistant page at the next guides sync.
- Assistant: done in this entry (includes/ai/ai-money.php, ai-actions.php, ai-tools.php, ai-desk.php, ai-vault.php,
  ai-insights.php, ai-decisions.php). Live check at Liwonde on gemini-3.5-flash-lite: 10/10 after fixes, US$0.021 total.
- LATER (not pulled): Assistant Settings saves write no settings-change rows; still-owed list can stop at 300 rows
  without saying so; vault stop words (staff, admin, manager, test, user ...) also apply to guest name parts (full
  names still hidden); staff-count test repeats its rule in SQL; History Summary check misses figures written without
  commas; settings-lookup refusal names the wrong missing permission; 10 money cases in ai_assistant_test.php have no
  refuse-at-Rosalyn's guard; 7 data-dependent fails at Liwonde (briefing left-owing + refunds, 4 alert checks, digest
  recipients) - they also fail on the previous code; idea: cancellations decision could offer the policy as an option.

## Synced 2026-11-14 (guides: step 6, 844f21f / 4a02c11 · Assistant: step 7) — entries kept for reference

**Both parts of every entry below are DONE. Stashes "PARKED 2026-11-14 guides + Assistant sync" (Rosalyn's) and
"PARKED 2026-11-14 Assistant sync port" (Liwonde) are superseded by the step 7 ship; drop them only when the owner says.**

### Group documents / no-show / kept fees / group charge (2026-11-11 → 2026-11-14)
- Commits (Rosalyn's): 0d2ef59, e4867fc, 28a5f0d, a3e5a95, 1e9c2a7, e291682, ec2e069, 58ea5ea, 2472594, f06fac1.
- Guides: 07, 13, 14, 19, faq — group invoices/receipts per room, no-show charges, kept cancellation
  fees owed (Still owed, EOD outstanding), kept fees + no-show charge taxed like a room night, Add
  Charge "This room / Whole group".
- Assistant: `rh_ai_noshow_money` group charge + credit (built, QA PASS 2026-11-13); align the
  `balances_due` tool (`includes/ai/ai-tools.php` ~1850) to the shared Still owed rule (owing no-shows
  + cancelled kept fees).
- PARTIAL WORK in git stash "PARKED 2026-11-14 guides + Assistant sync" (Rosalyn's) and
  "PARKED 2026-11-14 Assistant sync port" (Liwonde) — `git stash list`; re-check against HEAD before use.
- AI suite baseline: 1119 pass / 3 known fails / 10 skip.

### Hotel Settings change log (2026-11-14) — Rosalyn's 7a4cadc / Liwonde 0144ee6
- Guides: System Logs page — new "Hotel Settings changes" section (When / Who / Setting / Before /
  After, secrets shown as hidden, only real changes) and the new Staff activity type "Hotel Settings
  changes"; Hotel Settings guide: "every change you save is recorded in System Logs".
- Assistant: `includes/ai/ai-desk.php` (lines ~80, ~145) reads `admin_activity_log` — new action
  `setting_change` now raises per-person action counts and shows as "Setting change" in history (no
  values). Decide: count it as a save, or exclude; optionally answer "who changed setting X / when".

### Hotel Settings order + search/filter (2026-11-14) — SHIPPED (hashes: see TASKS.md line)
- Guides: Hotel Settings guide — 5 groups (Website & booking; Rooms & rates rules; Money & documents;
  Email & notifications; Modules & app), search box, group filter, "No settings match" + Clear, Esc clears.
- Assistant: any Assistant links to `booking-settings.php#<anchor>` keep working (anchors unchanged);
  check only.

### Settings change log on the other settings pages (2026-11-14) — SHIPPED (hashes: see TASKS.md line)
- Guides: System Logs section renamed "Settings changes" with a Page column; covers Automated
  Emails, VAT (Accounting), Modules, Station hours, WhatsApp, Facebook, Gym slots/reminders, Footer.
- Assistant: `ai-settings.php` (Assistant Settings) is AI-lead only — decide whether its saves
  (provider, models, keys masked, switches) should also write `setting_change` rows
  (`includes/settings-change-log.php`: `rh_settings_log_begin/_end/_record`).

### Guest booking lookup + "Booking Received" heading (2026-11-14, NEXT_BUILD_ORDER step 1) — SHIPPED (hashes: see TASKS.md line)
- Guides: guest-facing only. Check Booking Status now lists every room of a multi-room party (same or different
  room types) with each room's guests and reference; cancelled rooms show a "Cancelled" badge and are left out of
  the guests count and the Total Amount. Bookings staff deleted can no longer be looked up or cancelled online;
  expired bookings can't be cancelled online. The confirmation page heading says "Booking Received" for pending
  bookings (was "Booking Confirmed"). Front-desk guide/FAQ lines that quote the old heading: update if any.
- Assistant: no sync needed (grep includes/ai/ for "Booking Confirmed" and booking-lookup: no hits).

### History price note on online bookings (2026-11-14, NEXT_BUILD_ORDER step 2) — SHIPPED (hashes: see TASKS.md line)
- Guides: booking History > "How the price was worked out" now shows the booked room rate × nights for bookings
  made online (website, mixed-room parties, API) from the day this shipped, the same as bookings staff create; the
  rate no longer includes packages. Online bookings made before this still say "worked out from the saved total".
- Assistant: check only. Booking History answers that read the price snapshot (audit `new_values.price` /
  timeline `metadata.price`) now find one on new online bookings; API bookings have no rate plan fields.
- Logs lead: online 'created' audit rows now carry `new_values.price`; timeline 'create' rows carry
  `metadata.price`. Audit screens list changed fields only, so nothing new shows there.
- LATER (not pulled): no "Packages: +" line in the price explanation; the history test builds rows by hand and
  never calls `rh_booking_price_snapshot_clean()`.

### Joined-room live quote (2026-11-14, NEXT_BUILD_ORDER step 3) — SHIPPED (hashes: see TASKS.md line)
- Guides: no sync needed. Guest website only: a party that needs two or more joined rooms now sees a quote that
  adds up each joined pair at its own price, the same figure the booking is saved with.
- Assistant: no overlap (check-availability.php and the new includes/joined-quote.php are not read by includes/ai/).
- LATER (not pulled): the quote can still say a joined type is free when fewer separate pairs are free than the
  party needs (pairs sharing a room are counted twice); booking.php refuses at submit.

### Cancellation & no-show policies + guest whole-party cancel (2026-11-14, NEXT_BUILD_ORDER step 4) — SHIPPED, switch OFF (hashes: see TASKS.md line)
- Guides (settings): Hotel Settings > Refunds & cancellations has a new card "Cancellation & no-show policy":
  On/Off switch, up to 5 rows "Cancelled fewer than [X] days before arrival -> [N] % of the stay" (cancelling
  exactly X days before is still free; arrival day = 0), No-show %, optional separate policy for groups of N rooms
  or more, optional extra note, and "What guests will read" preview. The No-show refund rule is now visible and
  editable there. Only people with Finance settings can change it. At Rosalyn's the card says it needs the
  database update until migration 085 has been run.
- Guides (bookings): each booking made while the switch is On keeps the policy it was made under (Booking Details
  panel "Cancellation policy (locked at booking, vN)"). Cancelling a CONFIRMED booking inside a fee window shows
  the fee before you cancel ("50% of the stay MWK X (booked rate Y x 3 nights)"), what was paid and the refund;
  pending, tentative and expired bookings pay no fee. People with "Lower or waive cancellation fees"
  (administrators + managers by default, grantable to anyone) can lower the fee with a required reason, at cancel
  time or afterwards on Booking Details; the difference becomes credit owed back. History shows it. Worked example
  for the guide: MWK 100,000 x 3 nights.
- Guides (no-show): under a policy, 100% = the booked stay keeps billing as today; below 100% one "No-show
  charge - N% of the stay" line is posted and money paid above it is owed back.
- Guides (guest site, LIVE NOW at both hotels, not behind the switch): "Cancel My Booking" on booking lookup
  cancels every room in the party under the guest's email (rooms listed first; checked-in rooms left alone); one
  cancellation email per room. Parties billed to a company see "contact the hotel" instead of the button. Under a
  policy the guest sees the fee and refund and must tick "I accept the cancellation fee".
- Guides (documents): invoice, quotation, receipt, booking emails, booking lookup, the public Cancellation policy
  window, the booking summary and the create-booking review step print the policy wording (booking's own locked
  wording on its documents). Nothing prints for bookings without a policy.
- Assistant: includes/ai/ai-money.php (~:74-80) still reads only `no_show_refund_policy`; it must read
  `bookings.cancellation_policy_snapshot` and `rh_cancellation_fee_quote()` (includes/cancellation-policy.php);
  new permission key `waive_cancellation_fee`; new settings `cancellation_policy_enabled/_individual/_group/
  _group_min_rooms/_note/_version`. Step 7 covers this.
- Logs lead: new History events 'Cancellation settled' (policy kind/version/tier/%), 'Cancellation fee lowered',
  'No-show charge posted'; `rh_log_event` 'Cancellation policy changed'.
- EOD lead: lowering a fee after cancellation changes a past day's kept-fee figure (counted on the check-in
  date); a below-100% no-show charge is a tagged 'other' folio row ("guest-bill extras").
- LATER (not pulled): fee of exactly 0.01 shows "No fee" but is kept (cancellation-policy.php :489, `<` vs `<=`);
  group dialog totals `rh_booking_group_ids` while the cancel runs on `$cancelGroupIds`; lower/waive for
  legacy-kept first nights not built (ASSUMPTION, keeps the ship inert); sent reminder emails carry no policy
  text (templates/emails/checkin-reminder.php has no caller); cancellation_policy + golden tests not in
  run_tests.php; unused `cancelBooking()` JS in admin/bookings.php posts no fee (no caller; server would refuse).

### Guest booking emails: rate, rate plan, room references (2026-11-14, NEXT_BUILD_ORDER step 5) — SHIPPED (hashes: see TASKS.md line)
- Guides: guest-facing emails only (Booking received, Booking confirmed, Tentative booking created / converted,
  Quotation). Each now ends with a short block: the booked room rate x nights (never today's price), the rate plan
  the booking was made on, and for a multi-room party every room with its own booking reference (cancelled rooms
  left out). The "Rate Plan" row in the email body is no longer blank for website bookings. Nothing is added to
  cancellation, reminder, refund or invoice emails. Stored email templates were not changed; a template can place
  the block itself with `{{booked_rate_block}}` (Email templates guide: add the tag to the list if tags are listed).
- Assistant: check only. New email tag `{{booked_rate_block}}` (config/email.php); no table, setting, status or
  permission changed.
- LATER (not pulled): when the rate lines are left out (figures do not match the total), the plan's per-night
  figure still prints; a template that places the tag in the HTML only gets no block in its text part; the
  quotation's own "Rate Per Night" is on the line basis while the block shows the all-in rate (labelled).

### Failing test suites fixed (2026-11-14) — SHIPPED (hashes: see TASKS.md line)
- Guides: one small wording change. Early checkout on the day the guest arrived: the dialog, History and the
  refund reason now say the right number of unused nights (one night is still charged, so a 2-night stay shows
  1 unused night, not 2). The refund amount did not change. Everything else was test files.
- Assistant: no sync needed (includes/ai/ does not read `unused_nights`; check with a grep at sync time).
- MSP panel: a failed staff step now reports only the error type ("details in this site's error log"), never the
  error text. Liwonde has a permanent test staff account `test.staff` (receptionist, id 5351) - never delete it.
- LATER (not pulled): pin `staff_error` in licence_client.php to the known message starts; an older saved
  licence_staff_error goes out until the next staff run; late_stay_options leaves STMT-ZLS*.pdf files in invoices/;
  error_log_clear, stock_count_approval*, documents_flow, rehearsal_fixes have no refuse-at-Rosalyn's guard; past
  same-day early checkouts at Rosalyn's keep the old night count in their History text (no money effect).

### Create Booking: levy shown once (2026-11-15, owner urgent fix) — SHIPPED (hashes: see TASKS.md line)
- Guides (Create Booking, page 07 or wherever "Payment to Collect" is explained): the table now reads Room rate -> rate plan -> x nights (the all-in price) -> Subtotal (before levy and VAT) -> Tourism levy 1% (included in price) -> VAT 17.5% (included in price) -> Total to collect. Subtotal + levy + VAT = total; the levy is charged once. Display only: saved amounts were already right.
- Assistant: no change (it reads saved booking figures, which did not change).

### Stock review fixes (2026-11-15, NEXT_BUILD_ORDER step 8c) — SHIPPED (hashes: see TASKS.md line)
- Guides (Stock: ingredients, recipes, wastage, receiving, purchase orders, barcode receive, Data Import; POS/KDS void, recall, delete): new refusals staff can now see: archiving an item a recipe uses (names the dishes); saving a recipe with an archived item (and a warning listing recipes that already use one); receiving an archived item by barcode; throwing out an empty/closed batch; backdated wastage before an approved count ("this loss is already in the count of <date>"); a second click on Receive / Stock in is ignored; KDS recall refused on a refunded order; Import: "another import is being saved", "nothing was cancelled", a new-item row whose name now exists is refused ("review the import again"), a ticked quantity of 0 is counted. Recipe "yields N portions" help now says quantities are per ONE portion sold (the yield is for reference).
- Assistant: stock figures it reads stay the same tables; check any answer that explains why stock was or was not put back after a void / recall / delete (only lines with a recorded deduction are restored; recall of a room-service line moves no stock).

### Guests on the Bookings list (2026-11-15, NEXT_BUILD_ORDER step 8f) — SHIPPED (hashes: see NEXT_BUILD_ORDER.md row 8f)
- Guides (Bookings list, phone/tablet/laptop screenshots or wording): every booking row now shows the number of guests. Phones: line 2 reads "27 Nov -> 29 Nov · 2 nights · 4 guests". Tablets and laptops where the Guests column is hidden: "4 guests" under the Check In / Out dates. Wide screens keep the Guests column.
- Assistant: no change (layout only; number_of_guests unchanged).

### Better filtering on Bookings and Payments (2026-11-15, NEXT_BUILD_ORDER step 8g) — SHIPPED (hashes: see NEXT_BUILD_ORDER.md row 8g)
- Guides (Bookings list): new tabs Checked Out, Cancelled, No-Show, Group Bookings (each with its count). New "More filters" button: Booking kind (Single / Group / Joined room), Room type, Payment (Paid / Part paid / Unpaid / Refunded / Fee owed after cancel or no-show). Group = rooms booked together under one lead booking; joined room = two rooms sold as one. Filters stay on through paging, sorting and CSV export; the "Showing N ..." line names them with Clear.
- Guides (Payments): new filters Money in / Refunds (money out), Booking status (upcoming / in-house / checked out / no-show / cancelled), Group or company, Recorded by (staff), Room type, Amount from / to; on phones behind "More filters". The money cards follow the filters except Still owed and Today (their helpers say so). Each filter shows as a chip with its own Clear.
- Assistant: no change to data it reads; if it links staff to filtered Bookings/Payments lists, the new GET params are kind / room_type / pay / filter=group_bookings (Bookings) and direction / booking_status / group / recorded_by / room_type / amount_from / amount_to (Payments).

### Rate plans: per-room-type amounts, price basis, breakdown (2026-11-15, NEXT_BUILD_ORDER step 8h) — SHIPPED (hashes: see NEXT_BUILD_ORDER.md row 8h)
- Guides (Rate Plans): one plan can now give each room type its own % or fixed amount ("Same for all room types" / "Different per room type"; a blank row uses the plan amount). New "Apply on" choice (only when VAT and/or levy is on): the full price incl. VAT + levy, or the base rate before VAT/levy (a fixed amount on the base rate adds VAT + levy on top; a percentage gives the same guest total either way). The modal shows a breakdown per room type: standard price, base + VAT + levy, new price, "Up by MWK X (Y%) per night". A plan must be all increases or all discounts (mixed is refused); a fixed discount that would make a room free is refused. Plan History shows one line per room type. Needs migration 086 (run at Liwonde; Rosalyn's on owner request - until then the old one-amount form shows there).
- Assistant: rate_plans gains room_adjustments (JSON per room id) + price_basis; quotes go through rh_apply_rate_plans so prices are right; ai-tools.php:1856 (plan list) shows only the plan's main amount - add the per-room amounts at sync time.

### Rate plan pop-up layout (2026-11-15, owner: "its not clearly arranged and this is hidden") — SHIPPED (hashes: see TASKS.md)
- Guides (Rate Plans, Add/Edit pop-up): now in 4 numbered parts: 1. Name and when it applies, 2. Which room types (Applies To moved up, before the prices), 3. Price change (amounts, per room type, apply on, what each room type will cost), 4. Settings (Priority, Stack with other plans, Active - the two switches were cut off before and now show properly). Update any screenshot/step order.
- Assistant: no change.

### Payment status tells the truth (2026-11-16, NEXT_BUILD_ORDER step 8i) — SHIPPED (hashes: see NEXT_BUILD_ORDER.md row 8i)
- Guides (Bookings list, Booking Details pop-up + page, Dashboard check-in card, Calendar tooltip): payment status now comes from the money: "Paid in full", "Part paid (MWK X left)", "Unpaid", "Refunded". "Paid" is now "Paid in full", "Partial" is now "Part paid (MWK X left)". The pop-up no longer says "Completed" for a booking that still owes (it was showing one payment's status). A cancelled/no-show booking with nothing paid or owed shows "Unpaid". Booking History gets a "Payment status now ..." line whenever the status changes.
- Assistant: no data change (bookings.payment_status was already right); if it words a booking's payment state, use the same four labels (includes/booking-pay-state.php rh_booking_pay_state).

### Rate plan pop-up: choose "Same / Different per room type" first (2026-11-16, owner) — SHIPPED (hashes: see TASKS.md)
- Guides (Rate Plans, part 3 Price change): the first choice is now "Amount by room type": Same for all room types (then one Adjustment Type + Value) or Different per room type (the room type rows first, then "Amount for any room type left empty above"). Update step order/screenshots.
- Assistant: no change.

### POS / KDS core review fixes (2026-11-16, NEXT_BUILD_ORDER step 9) — SHIPPED (hashes: see NEXT_BUILD_ORDER.md row 9)
- Guides (POS till, KDS/BDS/CDS, Orders & Tabs, Drift report, End of Day): a retried Fire / Add to tab after a lost connection no longer sends the order twice; the till now gets a "ready" alert for every later round and after a recall; ready alerts, My orders and sent notes stay on the till after the stations close until the next opening; the closed-station warning follows the station each dish goes to (coffee bar included) and updates live; a new round on an old tab no longer shows as overdue; notes and rush flags go only to the stations on the order; voiding the last unfinished dish sends the ready alert; deleting a live order sends a STOP note to the station; KDS Bump help no longer mentions a Recall button. Drift report compares against the till's own shift totals (no false drift for a tab settled by another cashier or a refund). End of Day counts a POS refund on the day it is paid out (same as the Z). Voiding a room-service charge after the order closed lowers the order total too (Z / End of Day "Charged to room" match the folio). A discount bigger than the order now stores the real discount.
- Assistant: POS figures it reads (stock_orders totals, Z/EOD helpers) now agree with the folio and payout day; check any answer about drift, EOD refunds or room-service voids.

### Money leftovers from step 9 (2026-11-16, NEXT_BUILD_ORDER step 12) — SHIPPED (hashes: see NEXT_BUILD_ORDER.md row 12)
- Guides (End of Day, Booking History, POS till / Orders & Tabs / tables, Drift report): End of Day revenue now counts a till refund on the day the money was paid out (pending refunds left out), same as the Z and EOD report; voiding a room-bill charge adds a "Charge voided: <item> MWK x" line (who, when, type, reason) to the booking's History; the till, Orders & Tabs settle and tables refuse to take payment for an order that is already on a guest's room bill; Drift report's shift section now says it re-works each closed shift the way the Z report does and compares it with what was recorded at close.
- Assistant: new History action `charge_voided`; voiding a room-service charge also lowers the order's cost (total_cost), so margin answers change; EOD revenue refunds by payout day (rh_pos_refund_paid_out_sql).

## Known leftovers from earlier syncs (fix during a sync, never on their own)
- `ai_assistant_test` fails in unchanged code: claim-check self-test (Room Board "Tomorrow"; staff
  activity "4 of 3 staff accounts"), History Summary "K1,068,000," flagged; digest-recipients fail.
- `ai-decisions` ~1620/1777 dept-takings/VAT still on payment_date (matches Reports); "Open refunds"
  link filters Payments by payment_date.
- Guide 14 says "AI Assistant" vs "the Assistant"; `rh_ai_t_guest_history` drops no-email bookings
  when one email is found; `balances_due` per-row fallback (speed).

## Synced
- Through 943ca16: Rosalyn's 55827b0 / Liwonde 717b36e (and earlier 859473d / 8f7301d, 5085f61 / 2c82b0e).
  Full history: `.claude/TASKS.md` Shipped + `.claude/archive/`.
