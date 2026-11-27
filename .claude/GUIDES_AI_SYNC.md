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
- **Manager Guides (owner 2026-11-19: "always up to date"):** illustrated 2-page PDFs in
  `OneDrive\MSP\Manager Guides` (outside both repos; area map + last-synced line in its README.md).
  Every sync also checks each Pending entry against that map; where a manager-level fact changed
  (a step, rule, permission, button or "coming soon" item), one `manager-guides-lead` brief (haiku)
  makes the matching `source\*.html` edit, then `php build.php` there and a page-count/visual check (2 pages
  each). Record "Manager Guides: <which> refreshed" or "Manager Guides: no change" in the Synced entry.

## Pending (oldest first)

- 2026-11-28 Liwonde 23bd6fd / Rosalyn's e702401 (PATCH): Housekeeping > Needs action: hovering the Overdue pill says
  'Due out <date> (N days ago) but the booking is still checked in. Check the guest out on the booking, or extend the
  stay, to clear this.' Guides: housekeeping page (Needs action, what Overdue means). Assistant: 'why are rooms overdue in
  housekeeping' -> checked-in bookings past their checkout date; check them out or extend.

- 2026-11-28 Liwonde 99e8a66 / Rosalyn's 04020a0 (owner FEATURE + PATCH): Housekeeping and Room Maintenance timelines: drag
  (or Shift-click) across cells, past days too, and the bottom bar offers the list's bulk actions for the tasks/jobs
  in those cells (Assign, Priority, Start, Mark done, Verify, Repeat/Extend, Delete); right-click on picked cells shows
  the same. A drag may start on a task bar; a plain click still opens it. New task/job only for today onwards. The
  list's bulk bar no longer sits under the floating "?" button. Guides: housekeeping + maintenance pages (bulk
  section). Assistant: "how do I verify/delete several tasks at once" -> pick the cells or tick the list rows.
  Manager Guide: Housekeeping and Maintenance (one line).

- 2026-11-26 Liwonde ddda9f4 / Rosalyn's 537e6ce (owner FEATURE): the booking total popup ("How this total is worked
  out") has a line "Room rate x nights (excl. VAT & levy)" with "N nights at X" right above the Tourism levy line (not
  added; the total is unchanged); its levy/VAT hints now say "included in the all-in room rate (first line)". Booking
  details > Payment breakdown shows "N nights × X" under "Room base (excl. VAT and levy)". Guides: 13-finance-payments
  + faq (they quote "included in the room rate above"; add the new line). Assistant: "what is the room rate without
  VAT / levy" -> that popup line. Manager Guide: Money and End of Day (one line, optional). (Arrived mid-sync 2026-11-26.)

- 2026-11-26 Liwonde (CONF_PACKAGES_PLAN.md, owner FEATURE): Conference packages. New admin page Conference Packages
  (conference-packages.php; view: conference, edit catalog: conference_rooms): per event / per day / per delegate per
  day, per conference room, inclusions. Clients tick packages on the conference page; staff add/remove/keep them on
  the enquiry (conference_financials) and can now change delegates and room in the event-change form. Per-day and
  per-delegate packages reprice when days/delegates/room change; a package the new room doesn't offer is flagged.
  VAT on venue + packages, no levy. Quotation, invoice, emails list the package lines; the deposit line shows the
  amount (was the yes/no flag). Reports: Conference export by event date, confirmed only, plus a packages section;
  EOD has a conference packages line. Guides: conference page + a packages section (Help now opens #conf-amount).
  Assistant: conference answers should include package lines. Manager Guide: none.

- 2026-11-26 Liwonde (0ff45bf + 26822e7 / Rosalyn's 085706d + 35a6679, owner PATCH): gym/event invoices show the
  deposit amount (was the yes/no flag); invoice emails show the same paid / total / due as the PDF; email subjects
  show the hotel name as typed. Conference event change: moving to a conference room with no rate is refused ("set
  its rate in Conference Rooms first"); events over 500 delegates can be rescheduled. Guides: conference page, one
  line on the no-rate refusal. Assistant: none. Manager Guide: none.

- 2026-11-26 Liwonde (d9a3bb8 / Rosalyn's 5559e09, ROOM_PACKAGES_FIX_PLAN.md, owner PATCH): room packages now follow stay changes.
  Shorter/longer stays and adult changes work per-night and per-person-per-night packages out again (per-stay kept);
  in a group the lead room's party package is recalculated from the party's adults x the lead's nights, History
  "Package recalculated" (lead) / "Party package recalculated" (the changed room). Edit Booking and the date-change
  preview show old -> new. Changing the room type to one a package is not offered for needs the tick "I have told
  the guest" (package kept at its booked price; History "Package check"). Emails / invoices word packages as
  "per night × N nights" with the real units. A package switched off or not offered is refused at booking by name.
  Switching a package on/off is in System Logs. Assistant: the room-type change action (includes/ai/ai-actions.php)
  must send `package_ack` after the guest-told confirm, else it is refused when a package is flagged; reprice
  previews already include the package. Guides: bookings / edit booking / room change pages. Manager Guide: Bookings (optional).

- LATER (Assistant lead 2026-11-25): admin/bookings.php `modify_booking` has no "changed by someone else" version check
  (Edit Booking has one). A PATCH for the owner to decide.
- Manager Guides left out 2026-11-25 (pages full): Stock: the import crates/bottles and "Which price is right?" questions;
  Staff and Permissions: the VAT/bank password line; Housekeeping and Maintenance: one "Rooms needing work" sentence.
  The staff guides cover all three. Left out 2026-11-26: Money and End of Day: "Taken this week = End of Day Cash
  collected" (its Net taken line covers it).
- LATER (sync review 2026-11-26): includes/ai/ai-desk.php ~1625 can still call an expired booking "cancelled" and does
  not quote a reason staff typed; a capitalised word ("is Milk expired") still reads as a booking name.
- LATER (sync review 2026-11-26): a named "when did X pay" is answered first by includes/ai/ai-money.php ~619 (last
  payment only); add the Paid "i" pointer there.
- LATER (sync review 2026-11-26): the Assistant's pre-arrival days are clamped differently from
  guest-lifecycle-lib.php:48.
- LATER (Assistant lead 2026-11-26, accounting lead to review): `rh_dec_base` (ai-decisions) counts payments made with a
  credit note as collected.
- LATER (guides lead 2026-11-26): the KB search does not return the Bookings list Status-popup text for any query tried.
- Owner decision (2026-11-26, open): offer "Suggest wording" for the Receipt Email template too? (It gets none now.)
- e54c663 / Rosalyn's 6571a9c (2026-11-26, owner FEATURE): "Edit wording" before a booking email goes out, on the
  Resend email window and the single Confirm / Cancel dialogs (booking page + bookings list). Default template opens in an
  editor; money/payment fields are locked chips filled from the booking; one send only, never saved; typed prices refused.
  Only received / confirmed / cancelled / reminder / provisional created / provisional converted; not invoices, receipts,
  refunds, credit notes, bulk sends. Guides: bookings (resend, confirm, cancel). Assistant: "can I change the email
  before it goes" answer. Manager Guide: Bookings (one line).
- fc8e45a / Rosalyn's 9f95094 (2026-11-27, owner): every icon field (Room Packages, Conference Packages, Gym, Page
  Management; Liwonde also Section Headers hero badges) has a "Choose icon" button: searchable grid of free icons, click
  to fill; typing just "coffee" works too. The "browse fontawesome.com" hints are gone. Room Packages New Package window
  no longer opens under the sidebar. Guides: wherever a guide says to look up an icon on fontawesome.com, say "press
  Choose icon" instead. Assistant: none.
- d570ccc / Rosalyn's 266ea3c (2026-11-27, owner): "Edit wording" now also on bulk sends (Confirm, Cancel, Send a
  reminder, Resend confirmation: one wording for the whole batch, each guest's own figures; invoice stays fixed), New
  booking (beside the email tick, follows Status), Edit booking (new "Booking updated" template in Email & PDF templates,
  change list locked), Convert on Booking details, and "Confirm and edit email" in the All Bookings row menu. Quick Modify
  now emails only what really changed. Guides: bookings (bulk, create, edit, convert). Assistant: update the "can I
  change the email before it goes" answer. Manager Guide: Bookings line.
- ae6ec67 / Rosalyn's 2491031 (2026-11-27, owner): "Booking updated" email shows the "Includes VAT and tourism levy"
  note again; new tick "Email the guest about this change" (off by default, with Edit wording) on room assign, Add / take
  off nights and Change checkout date. Guides: bookings (move room, change nights/checkout). Assistant: "does the guest
  get told when I move their room" = only if you tick it.
- e9ecb8d / Rosalyn's b9a44fd (2026-11-27, owner fault): New Booking "Payment Received Now" takes the full amount
  without the "more than the balance due" refusal (a room with no double/triple price now costs its nightly price on
  the page too). The amount follows the total until staff type their own; if the save is refused, the rooms and
  payment stay filled in. Guides: bookings (create, take payment now). Assistant: "why does it say more than the
  balance due" = only when the amount really is above the total; tick "The guest paid more than owed" for a credit note.
- 22cefb1 / Rosalyn's 100498e (2026-11-28, owner): Bookings list shows "Checked in <date time> by <name>" under the
  Status of checked-in/out rows; booking details has a Check-in block (each check-in/out step, time, who); new
  "Checked in" tab (guests in house) and "Checked in on" date filter. Guides: bookings (list, filters, details).
  Assistant: "who checked this guest in / when" = the details page Check-in block.
- c270eb9 / Rosalyn's 26c7805 (2026-11-28, fault): guest booking page shows "Checking price…" and keeps Confirm
  waiting after the party or dates change, until the price for exactly that stay is in. No guide change.
  Assistant: "the total said Checking price" = normal for a few seconds after a change.

## Synced 2026-11-26 (owner prompted "assistant and guide sync"; guides + Assistant: commit "Guides + Assistant sync 2026-11-26" in both repos · guides 06, 07, 12, 13, 19, 99 (+ Liwonde-only brand fix in 01, 02, 14, 23; guide 07 Blocked Dates note) · Assistant: when-did-they-pay -> Paid "i", dashboard vs End of Day rule, email Tone, calendar arrow keys, bare pre-arrival question gets its own answer (payment chase needs "payment"), expired / cancelled / no-show answers point to the Status pill, movements arrows for tomorrow / next week, receipt comment per b145620, expiry questions never catch stock or gym questions · Manager Guides: Money and End of Day, Bookings, Daily checklist refreshed; others no change) - entries kept for reference

- 7994a1f / Rosalyn's 5c44967 (2026-11-25): Email & PDF templates: the preview and "Send test" of a PDF template now
  show the layout guests actually get (a stored copy of the old default is replaced by the current layout; edited
  wording shows as typed). Guide 12 / Assistant only if they say the test PDF can look different from the real one.
  (Neither did: no change.)
- 2026-11-25 Liwonde a1fda1b / Rosalyn's 1687f9a (owner FEATURE + accuracy PATCH): (1) Booking details: an "i" next to
  Paid (top strip and payment panel) lists each payment and refund on the booking: date, deposit / part / full payment,
  method, receipt, time recorded, amount; refunds on the day paid out, money moved to guest credit labelled; pending rows
  shown but not counted; adds up to Paid. (2) Dashboard Money "Taken this week" / Takings today now equal End of Day
  "Cash collected": money moved onto a credit note is guest credit, not a refund, so it is no longer taken off.
  (3) New dashboard card "This week's movements": money in by source, refunds paid out, Net taken (= Taken this week),
  change on the 7 days before, money moved to guest credit apart (dashboard_takings permission).
  Guides: 07-reception-bookings (Paid "i"), 99-admin-dashboard + 13-finance-payments (Money rule, new card).
  Assistant: "when did the guest pay / part payments" -> the Paid "i"; "why does the dashboard differ from End of Day"
  -> it no longer should; credit-note moves are guest credit. Manager Guide: Money and End of Day.
- 2026-11-25 Liwonde 8b9a348 / Rosalyn's 74b80e3 (owner FEATURE): Bookings list: hover (or tap on a phone) the Status
  or Payment pill for a short popup. Status: when, by whom and why (expired: hold ran out / not paid in time, room
  released; cancelled: reason, how it was settled, fee kept, refund; no-show; checked in / out), or for open bookings
  the hold time, pending lapse time, arrival and pay-by deadline. Payment: total, paid so far, balance due, credit,
  latest payment, deposit, arrangement. Guides: 07-reception-bookings (Bookings list). Assistant: "why did this
  booking expire / who cancelled it" -> hover the Status pill. Manager Guide: Bookings (one line).
- 2026-11-25 Liwonde 6006f77 / Rosalyn's 8937bde (owner FEATURE): Dashboard "Today's movements" card: the ‹ › arrows beside the title switch between Today,
  Tomorrow, Next 7 days and Next 30 days (round and round); counts, title and lists follow. Later periods start tomorrow
  and are view-only (arrivals confirmed/pending, departures due out, events on any day). Room service only under Today.
  Guides: 99-admin-dashboard. Assistant: "who arrives tomorrow / next week" -> the arrows on the movements card.
  Manager Guide: Daily checklist (one line).
- 2026-11-26 Liwonde 46cc606 / Rosalyn's e8728f2 (owner FEATURE): Email Templates "Suggest wording" has a Tone choice
  beside it: Mixed (default, three styles, each card says which), Friendly, Concise, Firm, Formal, Apologetic (three
  versions in that tone). Firm stays polite: a version adding a threat, deadline or cancelling talk is never shown.
  Suggestions only use tags that email really fills; the pre-arrival payment reminder now gets suggestions too. PDF
  templates still get none (on purpose). Guides: 12-email-templates (a ready paragraph is in this session's
  scratchpad guide-tone-para.diff; re-write it if gone). Assistant: "how do I make the reminder sound firmer" -> Tone.
- 2026-11-26 Liwonde 4a2b04f / Rosalyn's f05cd8f (owner PATCH): the check-in reminder no longer shows guests a literal
  "{{site_name}}"; VAT rates print as set (17.5, never "18" or "17.0") in the quotation email, invoice, receipt and
  PDFs; template previews use the hotel's VAT rate and fill every tag. In the room invoice email {{total_amount}} is
  the balance (owner rule 2026-11-15), now said in the tag list. Guides: 12-email-templates (tag list note).
  Assistant: none.
- 2026-11-26 Liwonde 402291f / Rosalyn's f5232e5 (owner PATCH): emails read cleanly with no phone number set (no
  "or ."); the invoice email's check-out date matches its PDF; the receipt email's VAT row says "VAT"; template
  previews show this hotel's own reference numbers; the pre-arrival payment reminder only mentions the payment policy
  when one is set. Guides: none. Assistant: none.
- 2026-11-26 Liwonde 3d02358 / Rosalyn's 9bce3d6 (owner PATCH): every list's page buttons stay in one place on every
  page and screen size, also on a short last page, after a filter or with nothing found ("Showing 0 of 0"). The
  dashboard movements card keeps one height: its list scrolls inside the card with the page buttons at the bottom.
  Guides: 99-admin-dashboard (one line, if it describes the card's paging). Assistant: none. Manager Guides: none.
- 2026-11-26 Liwonde f33403c / Rosalyn's 177e4b0 (owner PATCH, amends the line above): on phones and tablets (up to
  1024px wide) the page buttons sit right under the last row again, no gap; the dashboard movements card follows its
  rows there and keeps one height only on larger screens. Guides: 99-admin-dashboard (same line, say "on a computer
  screen"). Assistant: none. Manager Guides: none.
  DONE 2026-11-26 (owner "fix the line"), this entry and the one above: 99-admin-dashboard "Today's movements" has the
  page-buttons line, commit "Guide: movements card page buttons" in both repos. Nothing else left for either.
- 2026-11-26 Liwonde bb3a41c / Rosalyn's 2908d62 (owner FEATURE): Calendar, Housekeeping and Maintenance timelines:
  with a night selected by keyboard, Left / Right past the last or first day shown moves the grid to the next /
  previous dates (same step as the Next / Previous buttons) and the cursor lands on the next night in the same room,
  like a spreadsheet. Shift+Right / Shift+Left keeps selecting across. Guides: 07-reception-bookings (calendar
  keyboard line), 06-housekeeping (one line). Assistant: "how do I see further dates with the keyboard" -> arrow past
  the edge. Manager Guides: none.
- 2026-11-26 Liwonde b145620 / Rosalyn's e2b0b3b (owner "Fix receipt template" + "reset"): the Receipt Email template
  (Booking Settings > email templates) is now what the guest receives: its wording, with the receipt itself (details,
  VAT, levy, tip, total, REFUND / CORRECTED banner) where the {{receipt_details_html}} tag sits; left out = added at the
  end. Switched off = the old automatic layout (and no automatic receipt emails). The template was reset to the new
  default at both hotels. Guides: 12-email-templates (receipt email note: the template now shapes the email, the new
  tag). Assistant: none. Manager Guides: none.
- 2026-11-26 Liwonde af2a4a7 / Rosalyn's b00586f (owner PATCH): Dashboard figure cards (Owed to us, check-ins, takings
  and the rest) open their details again after going to another page and back; before, none opened until a hard
  refresh. Following a link inside the details window now closes it first. Guides: none. Assistant: none. Manager
  Guides: none.
- 2026-11-26 Liwonde 492bc02 / Rosalyn's 2ec4e0c (owner PATCH): leaving any admin page with a window still open
  (browser Back, or a link) no longer leaves the next page unable to scroll. Guides: none. Assistant: none. Manager
  Guides: none.

## Synced 2026-11-25 (owner prompted "sync guides, assistants and decisions"; guides + Assistant: commit "Guides + Assistant sync 2026-11-25" in both repos · Assistant: why-cancelled answer (reason from History, staff-only), Assistant cancel card uses rh_cancel_reason_check, counts_not_approved + count-measure hint, new arrivals_to_chase read · owner decisions 2026-11-24/25 written into guides + Assistant (cancel reason internal, guest emails unchanged; SMTP + copy lists unlocked) · Manager Guides: Stock, Bookings, Housekeeping and Maintenance, Money and End of Day, Daily checklist, Staff and Permissions refreshed; POS and Kitchen Screens no change) - entries kept for reference

- 2026-11-24 Liwonde 2cdb039 / Rosalyn's d089216 (owner FEATURE): Stock Items has a "Fill from bottle sizes" button: it
  works out shots (or glasses) per bottle from the item's bottle size and the Drink measures list sizes its menu items
  are sold in, shows a preview (new = ticked; items with a different measure or sold in several measures = unticked with
  the reason; skips say what to set, e.g. "No bottle size: set Bottle size on the item, then run again"), and saves only
  ticked rows. Hand-set measures never change unless ticked. New "Shots per bottle" column ("30 shots / bottle", "–").
  Guides: stock items / count measures page. Assistant: answer "why does it show 2.28 bottle" -> set the count measure
  (by hand, Set count measure, or Fill from bottle sizes). Manager Guide: Stock.
- 2026-11-24 Liwonde c71a77d / Rosalyn's 96956f3 (PATCH): Stock Reports (Inventory, Projected profit, Projected vs
  actual) show a notice when a stock count is not approved yet, with a link to it. What a count finds goes into stock on
  hand only on approval, so an unapproved opening count (from an import) leaves items at 0 and projected profit at 0.
  Guide + Assistant answer for "why is projected profit 0": approve the count. Manager Guide: Stock.
- 2026-11-24 bookings list overhaul (owner FEATURE; Liwonde 5d1d320 + 5af0c15 + 926cb92 /
  Rosalyn's c6f99e6 + f0e6169 + 532fdc5): Bookings page has one filter bar (search; date field + quick dates Today /
  Tomorrow / This week / Next 7 days / This month; room type, room, status, payment, source, group, made by; phone:
  Filters button), removable filter chips + Clear all, main tabs + "More" menu (Tentative, Expiring, Checked out,
  Cancelled, No-show, Groups, Deleted), Balance due column (amount under the total where it does not fit), sort
  remembered per user per device, 10/25/50/All, "Saved filters" (save / open / delete your own sets), CSV follows the
  screen sort + Balance due, default order upcoming = soonest arrival first, history = newest first, and a List |
  Calendar switch showing the Room Timeline inside the page. Guides: 07-reception-bookings. Assistant: links into
  bookings.php unchanged; answers for "find unpaid arrivals this week", "save my filters", "see bookings on the
  calendar". Manager Guide: Bookings.
- 2026-11-24 Liwonde 4940b48 / Rosalyn's e5be00f (owner PATCH): Calendar, Blocked Dates, Housekeeping, Maintenance
  grids pick the night under the pointer at any display size (was wrong on tablets at 60%); a selection stays when you
  change days or month; Shift-click / long press "Select <start> to here" extends the room's run across months; the
  bar and Block confirm name every selected night. Guides: calendar / blocked dates selection. Manager Guide:
  Bookings, Housekeeping and Maintenance.
- 2026-11-24 (owner PATCH) Liwonde 4196fa3 / Rosalyn's 7f48f41: the calendar and
  Blocked Dates selection bar now checks every selected night, also ones in another month ("checking..." first);
  Unblock nights is offered for a room's own block only and unblocks just those nights; the booked count covers the
  whole selection. Guides: calendar / blocked dates selection. Manager Guide: Bookings.
- 2026-11-24 Liwonde 2323967 / Rosalyn's 75d564c (owner PATCH): Housekeeping and Room Maintenance pages re-laid out
  like the Calendar: KPI chips on one row, grid first, then a compact list ("Needs action" / "Rooms needing work") with
  an Assign dropdown per row (no open task = adds today's task/job; open task/job = hands it to the person picked) and
  "Guest has left" per row; Staff Workload is one chip row. Maintenance list now shows only rooms out of order / under
  maintenance or with an open job (no longer every occupied room). Guides: housekeeping, room maintenance. Manager
  Guide: Housekeeping and Maintenance (screenshots + wording). Showcase SYNC QUEUE: both screens changed.
- 2026-11-25 (owner FEATURE) Liwonde 510ff01 / Rosalyn's d7d7a8c: every staff cancel needs a reason (at
  least 5 characters; "Cancelled by admin" refused), picked from a ready-made list or typed ("Other" = type it): room
  bookings (booking page single/group, bookings list row + bulk, Cancel Hold, Assistant), Mark no-show (single + bulk
  note), conference / event / gym enquiries, gym members (status + Edit), gym slot bookings. Guest website cancel must
  pick a reason ("Prefer not to say" counts). The reason shows once in the booking History, audit and System Logs
  (conference/event/gym: dated line in the record's Notes + System Logs). Owner 2026-11-25: the staff reason stays
  internal; guest emails unchanged ("Cancelled by admin" / "Tentative hold cancelled by hotel"). Guides: reception
  bookings cancel + no-show, tentative holds, conference/events/gym. Assistant: cancel card already asks the reason;
  answer "why was this cancelled" -> booking History. Manager Guide: Bookings.

- 2026-11-25 (owner PATCH) Liwonde 2efa7b7 / Rosalyn's 31480c5: help text now says what adding an
  address does: Backup staff copy emails = hidden copy (BCC) of every email the system sends, guests never see it;
  End-of-day report CC = copy of End of Day + daily report emails; station report CC = copy of kitchen/bar/coffee bar
  reports. Guide: 19-hotel-settings (Email + Payments & documents) and automated emails. Assistant: "who gets a copy
  of our emails" -> these three fields.

- 2026-11-25 (owner FEATURE) Liwonde 5a8c3d6 / Rosalyn's 4d6a64c: Hotel Settings groups and cards fold (remembered);
  bank details, restaurant service charge, tourism levy and VAT (Accounting Dashboard) are greyed out: "Change (password
  needed)" asks your own password and opens that one section for 10 minutes; saving locks it again. Only users with
  the VAT & refund settings permission. Guides: 19-hotel-settings, accounting VAT page. Assistant: "why can't I edit
  the bank details / VAT" -> unlock with your password. Manager Guide: Money and End of Day.
- 2026-11-25 Liwonde bd504f2 / Rosalyn's ec74e01 (owner PATCH): Import, step 3 asks "Is your count in crates/cases or in
  bottles/cans?" once per sheet when the file has a Unit of Crate/Case/Pack, a Total Per Unit and a Served by bottle/can
  (each option shows the price it gives); unit cells like "Bottle(750ML)" and "Shot(30ML)" are read with their size; a
  decimal count (2.28) suggests "in bottles". Guide: import stock page (column layouts + this question). Assistant:
  "why does the import ask crates or bottles". Manager Guide: Stock.
- 2026-11-25 Liwonde a9a3c9c / Rosalyn's f937d03 (owner PATCH): Import, a Case/Pack Price and a Unit Price that disagree
  ask "Which price is right?" for that item (each option shows the cost it gives); "Bottle(750ML)" in Unit Bought As is
  read with its size. Guide: import stock page (questions list). Assistant: "the import asks which price is right".
- 2026-11-25 Liwonde 088124a / Rosalyn's c645b6f (owner FEATURE): Import asks "When was this counted?" (date + time,
  required) and compares the file with the stock at that time, so sales after the count are kept. Once an opening
  count is approved, later imports make a regular stock count: shortages need a reason picked on Stock Count before it
  is submitted, and a different manager approves it. Guide: import stock page + stock count page. Assistant: "how do I
  import a second stock count". Manager Guide: Stock.

- 2026-11-25 (owner PATCH) Liwonde f0b932f / Rosalyn's 597dad6: wording and layout only. Card titles in sentence
  case (e.g. "Email server and sender", "Booking alerts and guest reminders", "Online booking"), one on/off status
  block, solid "Save ..." buttons, SMTP fields two per row. Guide 19-hotel-settings: update card names and screenshots.

- 2026-11-25 Liwonde f3a2bd4 / Rosalyn's 388027a (owner PATCH): Import step 3 "Put on the menu" now reads "X ticked to
  add or change · Y already on the menu at this price" (was "0 of N on the menu"); each group says how many are
  already on the menu, and its button says "All already on the menu" when nothing is left to add. Guide 23-import:
  step 3 wording. Assistant: "why does it say 0 on the menu" = they are already on the menu at that price.
- 2026-11-25 Liwonde f845c7e / Rosalyn's 3df06db (owner FEATURE + PATCH): (1) Create Booking's automatic quotation no
  longer adds "Payment is due upon arrival. Please bring this quotation with you."; the quotation note is only what
  staff type. (2) Plain text: when a template's plain-text box is empty, the plain version is made from the email
  itself (never saved); the editor shows a plain-text preview; PDF/document templates have no plain-text box. (3) Every
  template in Email Templates lists its tags, grouped, with what each one means. (4) New "Payment policy" box in
  Booking Settings > Cancellation card (up to 1000 characters) and a {{payment_policy}} tag for any email template;
  when set it replaces the built-in "Payment will be made at the hotel upon arrival..." line in the emails.
  Guides: 05/booking settings (payment policy), email templates page (tag list, plain text). Assistant: "where do I
  set the payment policy" / "which tag do I use" / "where does the quotation note come from" (typed by staff).
- 2026-11-25 Liwonde 3378f74 / Rosalyn's bc626d5 (PATCH, staff complaint): the installed admin app now opens on each
  person's own main page (station staff: their station; others: the dashboard or the first page they can open), no
  longer always the POS Till. The "POS Till" app shortcut still opens the till. Guides: 15-pwa-install-guide line 50
  ("It opens on the POS Till page") is now wrong. Assistant: "why does the app open on the POS" -> fixed; close and
  reopen the app (or reinstall) if it still does. Manager Guide: POS and Kitchen Screens if it mentions the start page.
- 2026-11-25 Liwonde 659b381..2706650 (9 commits) / Rosalyn's d0a5896 (owner FEATURE "Do all required"): two people
  working on the same thing can no longer overwrite each other. (1) Edit Booking, room types, rooms, menu items and stock
  items: a save after someone else changed it is refused ("changed by <name> at <time> since you opened it: reload").
  (2) Stock count: only one open count per area + shift; a second person is sent to the open one. (3) Import: the same
  file cannot be opened twice ("Open that import" button); answers made on an old screen are refused. (4) Automated
  emails, WhatsApp, Facebook, Hotel details, Review sources save only the fields you changed; a field someone else changed
  meanwhile is refused naming who/when; a page opened before the update asks for a reload. (5) "Also open right now"
  banner names the other staff on the same booking / count / import / stock item (warning only, saving still allowed).
  Guides: the editing pages above + settings pages. Assistant: answer "why did my save get refused / what is the yellow
  banner". Manager Guide: Staff and Permissions (one line on working at the same time).
- 2026-11-25 Liwonde b1148a5 / Rosalyn's c2ca585 (owner FEATURE): Hotel Settings > Email & PDF templates redesigned: a
  grouped, searchable list instead of 28 tabs. "Suggest wording" (only when the Assistant is on and a model is ready, and
  for people who may edit templates) offers 3 wordings per email template using only the template's own tags; Load
  Default unchanged. Guides: templates page. Assistant: "how do I change the wording of an email". Manager Guide: no change.
- 2026-11-25 Liwonde 4db16f3 / Rosalyn's 9f58022 + fixes 7ee6eeb / e92a3f4 (owner FEATURE): new automated email
  "Payment reminder before arrival": confirmed bookings still owing get a reminder at each day in "Payment reminders
  before arrival" (default 30, 15, 4 days before arrival), once per stage, stops when paid; groups get one email (the
  whole group's balance, earliest arrival). New "CC on guest reminders" setting copies every guest reminder email.
  All automated emails need the master switch on. Guides: Automated Emails page. Assistant: "when do guests get payment
  reminders / who is copied". Manager Guide: Money and End of Day (one line).
- 2026-11-25 Liwonde b32252c / Rosalyn's 009ff7b + fixes 7ee6eeb / e92a3f4 (owner FEATURE): front office "Arrivals with a
  balance to chase": Dashboard Needs attention item (red when anyone arrives within the smallest reminder day), linking to
  Bookings filtered to the same rows (Balance due shows the whole group's balance, "Group balance, N rooms"), plus a
  daily staff email (Automated Emails, off by default; To empty = the hotel's main email, optional CC). Window = the
  largest reminder day. Guides: Dashboard + Bookings + Automated Emails. Assistant: "who still owes before arrival".
  Manager Guide: Daily checklist (check the item each morning).
- 2026-11-25 Liwonde d9475a0 / Rosalyn's 8926fd4 (owner FEATURE): Automated Emails > Recent activity has filters (job,
  sent/skipped/failed, dates, search) and 10/25/50/All pages instead of the last 100; every column has a "?". Guides:
  Automated Emails page. Assistant: "did the reminder go to X / why was it skipped". Manager Guide: no change.

## Synced 2026-11-24 (owner prompted; guides + Assistant: commit "Guides + Assistant sync 2026-11-24" in both repos · Manager Guides: Stock, POS and Kitchen Screens, Bookings, Housekeeping and Maintenance, Money and End of Day, Daily checklist, Staff and Permissions refreshed) - entries kept for reference

### Whole-group payment receipts show the group total and every room (2026-11-20, owner fault) - Liwonde 7d5c105 / Rosalyn's 3781b20
- **Guides (Payments / Receipts, Bookings group payment):** each room still gets its own receipt number, but every room's receipt PDF and email now show "Whole-group payment: <total> split across N rooms" and list each room (booking, room, receipt number, amount); emailing any of them (automatic or Email receipt) sends ONE email with every room's receipt attached, to the lead room's guest plus CC, and all are marked emailed.
- **Assistant:** when asked about a group payment's receipt, say it is one receipt per room, each showing the group total, all sent together in one email.
- **Manager Guides:** Money & End of Day - one line on group payment receipts.

### AI Settings: Check for newer models refreshes the pick lists (2026-11-20, owner fault) - Liwonde 69942d3 / Rosalyn's 214a0f1
- **Guides (AI Settings page, admin only):** after Check for newer models the lists show the current top models and drop older ones; the model in use stays selected as '(older, still in use)' until someone saves another; a retired model shows a warning only.
- **Assistant:** built by the AI lead; nothing more to sync.
- **Manager Guides:** none.

### AI Settings: top 10 models, Google price page, Gemini 4 allowed, untick + Save fixed, provider cards lined up (2026-11-20, owner ask + fault) - Liwonde a7e1e5d / Rosalyn's port
- **Guides (22-assistant.html, Models rows ~350/377/378, admin only):** each provider now shows up to 10 current models (cheapest, fastest, latest); badges Newest / Cheapest / Fastest; "Replies here in about X s"; Google prices are read from Google's price page in the daily check and on Check for newer models ("Price from Google's page, <date>"), typed prices are never changed; Gemini 4 models are now shown and can be suggested (line 378 still says they are not); big models (Pro/Ultra/Opus or US$15+ per million out) are shown but never suggested; ticking a model makes it Extra, unticking clears its role, picking a role ticks it; Save fixes a missing Primary / second Fast and says what it set.
- **Assistant:** built by the AI lead; nothing more to sync.
- **Manager Guides:** none.

### Room Timeline calendar, step A (2026-11-21, owner ask) - Liwonde f011c65 / Rosalyn's cadda61
- **Guides (Calendar / Front desk, Housekeeping, Maintenance):**
  - Calendar now opens on the Room Timeline: rooms down the side grouped by type, dates across, bookings as coloured bars.
  - Each type has an Unassigned line; each day has occupancy / arrivals / departures rows; maintenance and blocked dates show hatched.
  - Housekeeping state shows as a dot per room. Range is 14 days by default, 30 optional, 7 on tablet and 3 on phone, with an Agenda list on phone.
  - Clicking a bar opens a side panel (a sheet on phone) that links to the booking.
  - Dragging across empty days opens New booking with the room and dates filled in.
  - The old month grid is at Calendar > Month. Housekeeping and Maintenance tabs list rooms and link to the full pages.
- **Assistant:** the new page view is calendar.php (default timeline, ?view=month); read-only JSON admin/api/calendar-timeline.php; create-booking.php accepts room, room_type, check_in, check_out prefill. No table or permission changes.
- **Manager Guides:** Bookings - one line that the calendar is now the room timeline; Housekeeping & maintenance - the tabs on the calendar.

### Room Timeline calendar, step B: move room / change nights from the calendar (2026-11-21, owner ask) - Liwonde 1f3658e / Rosalyn's f8a9ec2
- **Guides (Calendar):**
  - On desktop, drag a bar to another room to move it, or drag an unassigned bar onto a room. On a confirmed booking, dropping on another room type changes the type at that type's price.
  - Drag a checked-in stay's right edge to change its check-out. Keys: M = Move room, D = Change dates.
  - On phone and tablet, use More > Move room / Change dates in the sheet.
  - Every change shows a confirm (before -> after, price) and saves only on Confirm. No guest email is sent.
  - Changes that would give money back, checked-out, past, joined-room and group bookings, and a confirmed stay's dates are done from the booking.
- **Assistant:** the calendar uses the existing bookings.php actions assign_individual_room, upgrade_room_type (pricing=charge, send_email=0), stay_nights_quote and stay_nights_apply. No new tables, settings or permissions.
- **Manager Guides:** Bookings - "move a guest by dragging on the calendar".

### Menu Management: Delete selected + Delete all (2026-11-21, owner ask) - Liwonde d2aff50 / Rosalyn's 5ac2f4a
- **Guides (Menu Management):** administrators with the Clear Stock Data permission see tick boxes, a tick per category,
  "Select all shown" (only rows the search/filters show on the open tab), Delete selected, and Delete all (Food, Drinks
  or both; type DELETE). Each item follows the one-item rule: never sold = deleted with its till copy and stock list;
  sold = hidden; on an open order = left. Everyone else keeps the one-item Delete only.
- **Assistant:** new POST actions menu_bulk_delete / menu_delete_all on admin/menu-management.php; System Logs rows
  menu_items_bulk_deleted / menu_items_all_deleted (Till & orders) list every item and outcome. No new tables/settings/permissions.
- **Manager Guides:** Stock - "clear the whole menu before a re-import" (admin with Clear Stock Data only).

### Housekeeping, Maintenance, Room Service look like the calendar (2026-11-21, owner ask) - hashes in TASKS.md
- **Guides (Housekeeping, Maintenance, Room Service):** each page has a Timeline | List switch (phones open on List).
  Housekeeping: tasks on their due day per room, overdue red edge, shaded nights with a guest in. Maintenance: every
  open job from its From to its To date, hatched when it blocks the room. Room Service: today's orders by room and hour,
  "Show all rooms", late edge after 10 minutes (the KDS rule). Clicking a bar opens a side panel with the page's own
  buttons (Start / Mark done / next order state, Open on calendar, More). Calendar maintenance blocks open that job.
- **Assistant:** new read-only JSON admin/api/housekeeping-timeline.php, maintenance-timeline.php, room-service-timeline.php;
  pages read ?view=timeline|list, ?id=, ?room=, ?date=, ?days=. No table, setting or permission changes.
- **Manager Guides:** Housekeeping & maintenance - one line on the Timeline view; POS & Kitchen Screens - Room Service timeline.

### POS drink tiles show the serving and the other servings' prices (2026-11-21, owner FEATURE) - commit "POS drink tiles" in both repos
- **Guides (POS till):** a bar / coffee-bar tile whose stock link is ONE stock item now says how one sale is served
  under the name ("Shot · 25 ml", "Glass of wine · 175 ml", "Bottle · 750 ml", "Can · 330 ml") and, in small under the
  price, the menu price of the same drink's other till items ("Bottle MWK 40,000" on the shot tile, "Shot MWK 4,000" on
  the bottle tile; at most 3; 86'd items are left out; in Room Service mode the Room Service items are listed, so a bottle sold on Room Service only shows there). The cart line starts with the serving too. Wording comes from the
  stock link (Menu Management) and Stock (bottle size, bottle/shot measure); nothing is shown when neither says.
- **Assistant:** no table, setting or permission change. New read-only helper admin/includes/pos-drink-servings.php
  (rh_pos_drink_info). If asked "why does a tile not show shot/bottle", answer: link the drink to its stock item in
  Menu Management (one line) and set the bottle size / bottle-shot measure in Stock.
- **Manager Guides:** POS & Kitchen Screens - one line on the drink tile wording.

- 2026-11-21 Menu Management: Room Service tab + drink categories (owner FEATURE; commit "Menu Management: Room Service tab...", Liwonde then Rosalyn's port).
- **Guides:** Menu Management has a third tab "Room service": every food and drink item by category with an "On room
  service" switch (saves at once; filter On / Not on / All). The Drinks tab has category buttons (All, Beer, Wine...)
  and "Move selected to category" (tick drinks, pick or type a category, Move); the till copy and stock link move too.
  Anyone with Menu Management can move drinks; food moves and Delete selected/all stay with Clear Stock Data holders.
- **Assistant:** no table/column/setting change. New POST actions in admin/menu-management.php: toggle_room_service
  (food_menu/drink_menu.show_room_service + menu_items room service flag), menu_move_category (category change via
  rh_menu_pos_sync_item). Page URL admin/menu-management.php?tab=room-service.
- **Manager Guides:** Menu/Restaurant guide - one line on the Room service tab and moving drinks into categories.

### Import step 4 "Drinks for the menu": Tick all / Untick all (2026-11-21, owner ask) - Liwonde e4bbc37 / Rosalyn's 3c3b30e
- **Guides (23-import):** step 4 "Put on the menu" has Tick all and Untick all: they act on every drink the list shows
  (after Search and Show, all pages). Drinks that cannot go on the menu stay unticked. Nothing is written until Import.
- **Assistant:** new drinks_set mode tick_all (on, q, show) on admin/data-import.php; no table/setting/permission change.
- **Manager Guides:** Stock - "Tick all in step 4 to put every priced drink on the menu at once".

### Import step 3: Shot size (ml) in "Optional: add sizes" (2026-11-21, owner ask) - Liwonde 9b776c5 / Rosalyn's 3c3b30e
- **Guides (23-import):** drinks sold by the shot (or tot) show a Shot size (ml), filled in from the Drink measures list
  (or the file / the Assistant's reading); change it per group or per row if the bar pours a different shot. Used for the
  cost of one shot and what each sale takes off stock only; the opening count and the Drink measures list do not change.
- **Assistant:** sizes_set field 'shot' (group|row); new import alias kind shot_words (seed v9); the single reader call
  may pre-fill a shot size. No table/setting/permission change.
- **Manager Guides:** Stock - one line: "Step 3: check the shot size if your bar pours other than the list's shot".

### Import step 3 sizes: tidy table, "Change several drinks at once", per-row "Sold as" (2026-11-21, owner ask) - Liwonde 68e62fe / Rosalyn's 6fb1abe
- **Guides (23-import):** tick drinks (or "Tick all shown": every drink the search, Show and Group pick, all pages), then
  fill only what should change: Sold as (shot / glass / whole bottle / a Drink measures list name), shot, glass, bottle
  size; Apply. Each value is checked per drink; skipped ones say why. Sold as changes the menu only (cost of one, what a
  sale takes off stock); the opening count still reads the file. Each row's Sold as can be set or cleared back to the file.
- **Assistant:** new POST action sizes_bulk on admin/data-import.php; row answer _sizes.r.<key>.sold. No table/setting/permission change.
- **Manager Guides:** Stock - "Step 3: tick many drinks and set their shot or bottle size in one go".

### Import page: step plan, folding sections, pinned buttons (2026-11-21, owner ask, UI only) - Liwonde 79d0a4a / Rosalyn's d14bca5
- **Guides (23-import):** new screenshots needed. Each step shows "On this step" (numbered actions and what is left),
  sections fold (Required / Optional / Done; the ones that need you start open; Open all / Fold all), Back / Continue /
  Confirm and import stay pinned at the bottom. Wording shortened; "More about this" holds the detail.
- **Assistant:** no action/table/setting change; section names: "Answer what is unclear", "All items", sizes, value check,
  "The numbers for the whole file", drinks, rows left out, fixed for you.
- **Manager Guides:** Stock import page picture refresh.

### Stock quantities shown as "bottles + shots" (2026-11-21, owner ask, display only) - Liwonde dced1a0 / Rosalyn's 8686663
- **Guides (stock pages):** on-hand, batch, wastage, reorder, report and count figures read "2 bottles + 7 shots" for
  items with a count measure (hover shows the exact figure); below zero reads "short by ..."; items without a measure
  show at most 2 decimals. Stored stock, exports and what you type are unchanged.
- **Assistant:** no table/setting change; when quoting stock to staff, prefer the same words (helper rh_stock_qty_words).
- **Manager Guides:** Stock - one line on reading "bottles + shots".

### Put stock items on the drinks menu (2026-11-22, owner ask) - Liwonde (this commit)
- **Guides (stock + menu):** Stock Items: tick drinks (or Tick all shown) -> "Put N on the menu" -> type price (VAT
  included), check Sold as and category -> Add. Menu Management Drinks tab has "Add from stock" (same page). Needs the
  Menu permission. A drink already on the menu only gets its new price; weight items (kg) cannot go on.
- **Assistant:** new page admin/stock-to-menu.php (permission menu); log action drink_menu_from_stock; shared writer
  import_drinks_menu_write(). No table/setting change.
- **Manager Guides:** Stock + Menu - one line each on "Put on the menu" / "Add from stock".

### Blocked Dates calendar: block single rooms on a month grid (2026-11-22, owner FEATURE) - commit "Blocked Dates calendar" in both repos
- **Guides (Blocked Dates):** the page opens on a Calendar: All rooms row, then each room type row and its rooms (a joined
  room is one row), one column per night of the month (prev / next / This month / month picker, Room type filter). Click a
  night, drag across nights and rooms, shift-click a range or click a room name for its whole month; pick the block type and
  reason and press Block, or press Unblock. Letters B/M/E/F = Blocked / Maintenance / Event / Fully booked on that row; a light
  tint = blocked by the room type or All rooms row (unblock it on that row); blue bar = booked; red ring = booked AND blocked.
  Past nights cannot be changed. The old table is the List tab. Needs the Block Rooms permission to change anything.
- **Assistant:** no table, column, setting or permission change. New POST action grid_apply on admin/blocked-dates.php
  (op block/unblock, items JSON). Page URLs: blocked-dates.php?month=YYYY-MM, blocked-dates.php?view=list.
- **Manager Guides:** the Rooms / Front desk guide line on blocking dates (now a calendar).
- **Follow-up (2026-11-22, owner PATCH "fully blocked types show greyed out"):** the public booking calendar now also greys
  out a night when EVERY room of a type is off (each blocked one by one, Maintenance / Out of Order, or a maintenance
  window; a joined type when every pair has a room off). Check-out ON a blocked date is allowed (the night before is what
  counts); once a check-in is picked, check-out dates that would cross a blocked night are greyed. Guides: one line in the
  guest booking / blocking section. Assistant: no table/setting change (helper includes/blocked-nights.php).
- **Follow-up (2026-11-22, owner PATCH "specify the room names and the date"):** the Blocked Dates calendar's summary,
  Block/Unblock confirm and result message now name each room and its nights ("Blocked 6 night(s): Villa 2 on 3 to 5 Jan
  2028; ..."), never "rows"; the List-view forms say e.g. "Room 101 blocked on 12 Dec 2026". Guides: update any screenshot
  text that shows the old "N nights on N rows" wording. Assistant: no change.
- **Follow-up (2026-11-22, owner PATCH "proper logic and logs ... it works directly with bookings"):** every block and
  unblock (calendar, List view, bulk unblock, integration API) is recorded in System Logs > Staff activity as "Blocked
  rooms" / "Unblocked rooms" (who, rooms, nights, type, reason, bookings already on those nights), and each booking that
  sleeps on such a night gets a History entry "Room blocked on a night of this stay" / "Block removed from a night of this
  stay". Before blocking, the confirm lists bookings that will NOT be moved. Guides: one line each in Blocked Dates and
  Booking History. Assistant: new admin_activity_log actions room_block / room_unblock; new booking_timeline_logs note
  actions (no table/setting change).

### Import step 3 "Put items on the menu": drinks AND food, grouped, editable price (2026-11-22, owner FEATURE) - Liwonde (this commit)
- **Guides (23-import):** step 3 now holds ONE "Put items on the menu" section: items grouped by the file's category; each group has Menu (Drinks / Food) + menu category; per row Sold as, sizes (drinks), cost of one, selling price (VAT incl., editable, filled from the file), profit, On menu tick; "Put all N on the menu" + "Put all in this group"; nothing ticked by default. Unclear groups say "Choose: Drinks or Food" and cannot be ticked until answered; an item already on a menu stays on it. Step 4 shows one summary line ("N go on the menu: D drinks, F food ...") + "Change in step 3". A typed price changes the menu price only, never stock cost or the opening count.
- **Assistant:** if set up, it may show "Suggested: Food · Snacks" on an unclear group; nothing changes until "Use this" is pressed; it never ticks.
- **Manager Guides:** Stock - one line: import can put drinks and food on the menu at a price you can change.

### Admin phone + tablet polish (2026-11-22, owner PATCH) - Liwonde (this commit)
- **Guides:** layout only. On phones and tablets list pages show compact rows (tick at the left, name + status, short detail lines, action buttons on the right, more in the ... menu). Screenshots of phone views in guides may look different; no steps change.
- **Assistant:** no change.
- **Manager Guides:** no change.

### Admin phone + tablet polish round 2 (2026-11-22, owner PATCH) - Liwonde (this commit)
- **Guides:** layout only. Phone row buttons now show a small name under the icon; Individual Rooms, Invoices, Credit Notes, Stock Clear, Cache, Menu Management and Receipts use compact rows; no big gap above the page buttons. On phones Credit Notes hides Issued (on the PDF), Individual Rooms hides booking dates (in Details), Menu hides barcode/tags (in Edit). No steps change.
- **Assistant:** no change.
- **Manager Guides:** no change.

### Grid Phase 1: select many, right-click, bulk bar on Calendar / Housekeeping / Maintenance / Blocked Dates (2026-11-23, owner FEATURE) - Liwonde 0bb811a / Rosalyn's 790990b
- **Guides (calendar, housekeeping, maintenance, blocked dates):** drag a box across rooms and days, Shift+click a range, Ctrl+click to add, click a room name (its days) or a day head (that night for all rooms); right-click (or long-press, or the Menu key) opens the actions; a bar at the bottom shows what is picked. Calendar right-click: New booking (one room, free nights), Block / Unblock nights (same check as Blocked Dates), New maintenance job. Housekeeping / Maintenance timelines: pick several tasks/jobs for the list's bulk actions; pick days to add tasks/jobs for those rooms in one go. Lists: Shift+click ticks a range; right-click a row for its actions. Clicking a room name or a calendar block now selects it (Enter opens it). Shift+right-click = the browser's own menu.
- **Assistant:** no change (no tables, settings or statuses changed).
- **Manager Guides:** Bookings / Rooms guide may mention right-click + drag-select (check at sync).

### Grid Phase 1b / Blocked Dates patch / Phase 2b / Phase 3 (2026-11-23, owner FEATURE + PATCH) - Liwonde 3addb8a, 53c7d34, 77536cd, Phase 3 (this commit)
- **Guides (all admin lists):** right-click any list row for that row's own actions + Open / Open in new tab / Copy reference (3addb8a).
- **Guides (blocked dates):** picks stay when changing month; Block Date Range Individual level = tick several rooms (53c7d34).
- **Guides (bookings):** changing a stay's dates or room is refused with a plain message when a booking, block or maintenance job is in the way (77536cd).
- **Guides (calendar, blocked dates, maintenance, housekeeping):** Extend... (right-click / bulk bar) by days, weeks, months, years or to a date; drag the end handle of a block or maintenance job to stretch or shrink it (booked nights skipped and listed; no handle on a month's last night in Blocked Dates, use Extend...); housekeeping repeat daily / weekly until a date; dragging a confirmed booking opens its Change dates prefilled, nothing saved on the calendar.
- **Assistant:** extend_stay now refuses with the same clear clash message.
- **Manager Guides:** Rooms guide: Extend + drag handles (check at sync).

### Grid Phase 2: blocks and room-closing maintenance never land on a booked night (2026-11-23, owner FEATURE) - Liwonde (this commit)
- **Guides (blocked dates, calendar, maintenance):** Block (calendar grid, Block Date Range, list forms, Calendar right-click) now SKIPS nights a booking sleeps on and saves the rest; the confirm says which nights will be skipped and lists the bookings; if every night is booked nothing is saved. A room-type or All-rooms block on a night with bookings blocks only that night's free rooms one by one (keeping rooms free for bookings with no room yet); unblocking the type later does not remove those single-room blocks. Maintenance with "Block room" over a booked night is refused with the bookings and the free dates (change the dates, untick Block room, or move the booking). Auto-created, bulk-assigned and repeating jobs on a booked night are created without closing the room. Unblock is never checked.
- **Assistant:** Block dates card (includes/ai/ai-actions.php rh_ai_pv_block_dates / rh_ai_vf_block_dates): the preview runs the same clash check, warns which nights are skipped, refuses when every night is booked, and the verify checks only the nights the save may block (plus single-room blocks), so a correct partial save is no longer an action_failed incident. Wording in its card help changed.
- **API:** POST /api/blocked-dates skips booked nights (response `skipped[]`, 409 when nothing free; `warnings` kept empty); PUT onto a booked night = 409, old block kept; an individual block of a joined room now blocks both rooms. POST/PUT /api/maintenance-schedules with block_room=1 over a booked night = 409.
- **Manager Guides:** Rooms guide: one line "blocks skip booked nights" (check at sync).

### Assistant Settings: model prices automatic, "Monthly cap" (2026-11-21, owner FEATURE)
- Owner: "no need for us to set AI prices, you do it automatically, we just set max cap" (cap stays information only, never stops chat).
- Guides (Assistant Settings > Models): no Price in / Price out boxes any more; prices are read automatically each day (Google's own price page; the public model price list for Anthropic, OpenAI, xAI, DeepSeek, and for Google models its page does not show) and on Test connection / Check for newer models. A model with "No price yet" cannot be ticked until a price is found. "New - set price" is gone.
- Guides (Budgets and limits, Usage): "Monthly aim" is now "Monthly cap (US$)"; still information only.
- Assistant: settings key unchanged (`ai_monthly_budget_usd`); if it mentions the figure, call it the monthly cap. `rh_ai_save_selection()` takes no prices.

### Assistant Settings: Fit limits to the cap + layout fixes (2026-11-21, owner FEATURE)
- Owner: "for Budgets and limits be able to auto set to the best capacity to fit budget and fix the UI in assistant settings".
- Guides (Assistant Settings > Budgets and limits): Monthly cap now comes first. New "Fit limits to the cap" button fills Daily limit per person and Most tokens for one question so that even if every person with access plus the nightly checks used all of it every day (and went one question past the daily limit, which can happen), a month stays within the cap with 10% kept back; it shows the figures and what the current limit could cost. Click Save limits to keep it. When a person's daily limit is used up, quick lookups still work and the rest is back the next day.
- Guides: on/off pickers now read short ("On - prepares changes", "Off - local answers only"...); the full meaning is in the help text under each. Usage table names the system row "Nightly checks". Prices show as $0.30, not $0.3.
- Assistant: new helper `rh_ai_fit_limits()` (includes/ai/ai-catalog.php); no tool change.

### Maintenance windows from the panel + hotel backups skip cp_ tables (2026-11-23, ClaudeWorkTwo) - Liwonde 46efbca / Rosalyn's 974003a
- Line added for ClaudeWorkTwo (board note). Files: includes/licence-maintenance.php, licence.php, licence-guard.php,
  config/database.php frontend maintenance block, db-backup.php. Details in the commit message. New panel pages
  Maintenance + Deployments = Showcase SYNC QUEUE item.

### Update from Remote, hotel side (2026-11-23, ClaudeWorkTwo) - Liwonde 3fccfba / Rosalyn's 1bad20c
- Line added for ClaudeWorkTwo (board note). Files: includes/licence.php, scripts/deploy-check.php. Details in the commit message.

### Calendar side panel opens like the Assistant chat (2026-11-23, owner ask) - commit "Calendar side panel docks" in both repos
- Owner: "on the calendar views ... should be opening like our chat panel and not hiding page content but minimising the
  page like how chat does and also not overlapping with our assistant icon and notice icon because it's hiding some buttons".
- Guides (Calendar, Housekeeping, Maintenance, Room Service grids): on a laptop the booking / task panel now sits beside
  the page (the page gets narrower, like the Assistant), and the Assistant, notice and "?" buttons move to the left of
  the panel so Open booking / More are never covered. On a small screen with the menu open the panel still covers the
  page, but the buttons still move clear. Phones keep the bottom sheet.
- Assistant: no change.

### Update from Remote pulls with git (2026-11-24, ClaudeWorkTwo) - Liwonde 9f01daa / Rosalyn's 5c9779e
- Line added for ClaudeWorkTwo (board note). Update from Remote now uses git pull, not cPanel uapi. Details in the commit message.

### Dashboard Phase A: last sign-in line + End of Day day chip (2026-11-24, owner FEATURE) - commit "Dashboard Phase A" in both repos
- Owner: dashboard "missing some very vital information that can help anyone that first logs in"; mock-up approved 2026-11-24.
  Plan `.claude/DASHBOARD_FIRSTLOGIN_PLAN.md` (Phases B and C to follow).
- Guides (Dashboard): under the greeting a small line "Last signed in Mon 23 Nov at 18:40, Chrome on Windows. Not you?
  Change your password" ("First sign-in on this account." the first time). Next to the date, for people with End of Day
  access: a green "Yesterday closed · End of Day sent HH:MM" tag, or "Yesterday's End of Day not run" linking to the report
  ("(test)" when the send was in test mode). System Logs > Recent Logins now name Android / iOS phones instead of "Linux".
- Assistant: no tool change. New helpers `rh_dash_prev_signin()`, `rh_dash_eod_status()` (admin/includes/dashboard-since-data.php).

### Dashboard Phase B: since you were last here, guest chips, free tonight, week takings, "?" tips (2026-11-24, owner FEATURE) - commit "Dashboard Phase B" in both repos
- Guides (Dashboard): the line under the greeting now reads "Since you were last here: N new bookings · N cancelled ·
  MWK … received · N room moves — N things need you" (only what the person may see; each part opens the matching list).
  Bookings and Payments accept `?since=` (the list shows a "since" notice with Clear). Arrival rows (Today's movements,
  Week ahead) show chips: "Returning guest · Nth stay", "Request: …", "Deposit unpaid" (not for company-billed or group
  rooms, only pending/confirmed). Rooms ring centre = rooms still free tonight (occupancy % stays in the top card). Money
  panel headline = "Taken this week" (today stays in the Takings card). Every figure card and panel title has a "?" tip.
- Assistant: no tool change. New helpers `rh_guest_history_stays_for()` (includes/guest-history.php),
  `rh_fd_deposit_unpaid()`, `rh_fd_request_short()` (admin/includes/front-desk-counts.php), since readers in
  admin/includes/dashboard-since-data.php.

### Dashboard Phase C: handover notes + first-login welcome card (2026-11-24, owner FEATURE) - commit "Dashboard Phase C" in both repos
- Guides (Dashboard): a "Handover note" button in the dashboard header lets anyone write a short note (up to 500
  characters) for the next shift. Everyone else sees the newest unread note at the top of Needs attention ("From <name>,
  <time>", "and N more") until they press Mark read; notes disappear after 48 hours; the writer never sees their own.
  It does not change the Needs-attention count. System Logs shows "Wrote a handover note" / "Read a handover note" (note
  number only, never the text). First sign-in: a welcome card shows the person's role, what it can do, up to 3 main pages
  they can open and the guide links, until they press Got it.
- Assistant: no tool change. New tables `shift_handover_notes`, `shift_handover_reads` (migration 091; Rosalyn's only
  after the owner confirms), preference key `dashboard_welcome_dismissed` in `admin_user_preferences`; reader
  `rh_dash_handover_unread()` (admin/includes/dashboard-since-data.php); endpoints admin/api/handover-notes.php,
  admin/api/dashboard-seen.php.

## Synced 2026-11-20 (2) (import entries below: guides 23-import + 08-stock-orders, Manager Guide Stock line, AI import helper ai-import-suggest.php; hashes in TASKS.md) - entries kept for reference

### Import: selling price recognised in Step 2, Step 4 pager, "See projected profit" link (2026-11-20, owner fault) - hashes in TASKS.md
- **Guides (23 Import):** Step 2 now shows the selling price column as "Recognised", used in Step 4 "Drinks for the menu" (no longer "Not imported"). Step 4 lists: Previous / Next, type a page number, Show 10/25/50/100. Finished screen: "See projected profit" link (people who can open Stock Reports) opens Stock Reports > Projected profit.
- **Manager Guides (stock):** check the import line still matches (no "Not imported" wording).
- **Assistant:** no overlap.

### Import: optional bottle size for shot/glass drinks + more measure wording (2026-11-20, owner ask) - hashes in TASKS.md
- **Guides (23 Import, 08 Stock):** Step 3 has an optional "bottle size" question for drinks sold by the shot or glass when the file gives no size: once per group, with a per-row change; never guessed; never blocks Next; a size under 50 ml or smaller than one measure saves with a warning. With a size, Step 4 can tick those drinks (cost per shot = bottle cost × shot ml / bottle ml). More wording is read: "Per shot", "1 Shot", "Tot 25ml", "25ml" ("In 25 ml measures"). If the file has a Unit column, it always sets the stock unit; a serving column only says how it is sold.
- **Manager Guides (stock):** the import line can say "add a bottle size so shot drinks can go on the menu".
- **Assistant (AI_REQUEST from dept-import-lead, owner "harness AI where possible"):** an Assistant hook that suggests served-by and bottle-size readings from unusual wording ("Single", "Measure", "Glass 175") for the import. Suggestions only, shown as "Read from your file", confirmed by the user; the import works fully with AI off. AI lead builds it in the next owner-prompted sync. Built, Liwonde (2026-11-20), file includes/ai/ai-import-suggest.php (rh_ai_import_measure_suggest; test scripts/tests/ai_import_suggest_test.php); import wiring by dept-import-lead.

## Synced 2026-11-20 (guides Rosalyn's 909510e / Liwonde 8704293 · Assistant: see TASKS.md SYNC 2026-11-20 · Manager Guides: Stock, Bookings, Money and End of Day, Staff and Permissions refreshed) - entries kept for reference

### Group bookings Ship A: Correct Stay Dates after checkout + group table (2026-11-17, owner ask) — hashes in TASKS.md
- **Guides (Bookings > booking details):**
  - A checked-out room has a new "Correct Stay Dates" button. It needs the "Correct a stay after checkout" permission (default admin and manager) and a reason, and goes through preview → confirm.
  - It is used when a guest really arrived late or early, or left early or late.
  - Extra nights are charged at the booked rate (a changed rate needs a reason). Money owed back goes out as a refund to the original payment or as a credit note.
  - The room's invoice is rebuilt with the same number. Receipts do not change.
  - Past End of Day figures for those dates change.
  - A room with a cancellation policy cannot have nights cut this way yet.
- **Guides (group table):** a booking in a group shows every room (no-show included) with:
  - dates and nights;
  - booked rate × nights;
  - billed, paid, owed, credit and refunds;
  - group totals over all rooms, and the net position (owed − credit).
- **Assistant:**
  - knows the new action and permission;
  - group figures = the sum of the rooms, all statuses;
  - stays read-only on this. It points staff to Correct Stay Dates and never does the correction itself.

### Assistant: full booking / group breakdown when asked about a booking (2026-11-17, owner ask, for the next sync)
Owner: "I also like how you broke down everything you know about the booking including days. Put this sort of details in the steps for the AI when we are ready to sync later."

When staff ask about a booking or a group, the Assistant answers in the same structured way as the ICTAm breakdown. It is read-only, and it only states figures it has checked (rail: never claim absence unverified). The answer covers:
- **Group summary:** reference, number of rooms, how the group is stored (lead room + linked rooms), and a payer or company account if any.
- **Dates and nights:** booked check-in → check-out and number of nights, per room. It calls out rooms whose dates differ from the group's.
- **Money per room:**
  - booked rate per night × nights = bill (including VAT and levy);
  - paid;
  - owed or credit;
  - how a lump sum was split (e.g. 15,000,000 split as 937,500 per room);
  - folio charges.
- **Group totals:** billed, paid, owed (with the sum written out, e.g. 16 × 129,000 = 2,064,000), refunds, net position.
- **Status timeline:** each room's history in plain words with dates and times, in local time. Raw log times are UTC, so read them via the app.
  - When each room checked in, and who marked a no-show (with the note).
  - Rate corrections, old → new, with the approval note.
  - Checked out, and "with outstanding balance, authorised by X".
- **What stands out:** e.g. dates never corrected after checkout, a no-show still carrying a share of the lump sum, a missing checkout time.
  It points to the screen to fix each one; it never fixes anything itself, and preview → confirm stays.

Guides: none for this entry. The guide entries for the group bookings ships are logged separately when they ship.
Depends on the group bookings ships (Ship A group table, A8 correction after checkout), so the Assistant reuses the same figures (getBookingFolioSummary per room, group = sum of rooms, all statuses).

### Group bookings Ship B1: payments on no-show / cancelled rooms that still owe + booking search (2026-11-17, owner ask) — hashes in TASKS.md
- Payments > Add Payment and Bookings > Payments & balance now accept a payment on a no-show room, a cancelled room whose kept fee or extras still owe, and an expired hold carrying the group no-show charge, only while it owes. The room keeps its status. Such a room can never be overpaid (the overpay tick does not apply): "This no-show booking owes MWK X. A payment on it cannot be more than that."
- A room that owes nothing still refuses: "This no-show booking owes nothing. No payment can be recorded." Deleted rooms refuse everywhere.
- Add Payment booking search: deleted rooms no longer listed; a room in a group shows "Room x of N, group <lead ref>"; the recent list also shows up to 10 older rooms that still owe.
- Guides: Payments (Add Payment, search), no-show/cancelled sections, FAQ "can I take money on a no-show". Assistant: payment answers must not say no-show/cancelled rooms cannot take payments.

### Group bookings Ship B2: Add charge from Payments / Accounting + small fixes (2026-11-18, owner ask) — hashes in TASKS.md
- New "Add charge" button on Payments Ledger and the Accounting Dashboard (page admin/charge-add.php, needs Edit Booking). Pick a booking, then "This room only" or the whole group (one line on the group's lead room, or split evenly across the rooms), charge type, description, quantity, price (VAT included, no levy), and a reason (required). Preview shows each room's owed now → after; Confirm posts it. History: "Charge added" / "Group charge added" with the reason and owed old → new.
- A checked-out room can take a charge here only (not from booking details), and only with the "Correct Stay After Check-out" permission (admin + manager). No-show, cancelled, expired and deleted rooms are refused. Issued invoices are rebuilt under the same number. Extra nights still go through Correct Stay Dates / Extend stay.
- Bookings: an expired room carrying its group no-show charge that still owes now has Payments & balance in the More menu. Booking details' group list leaves deleted rooms out.
- Guides: Payments (Add charge), Accounting dashboard shortcuts, group bookings section, FAQ "how do I charge a guest who has already checked out". Assistant: can say charges after check-out go through Payments > Add charge with the permission; must not say checked-out rooms can never be charged.

### Group bookings Ship A2: Kept for unused nights (2026-11-18, owner ask) — hashes in TASKS.md
- When a stay gets shorter (leaves early, arrives late and pays only nights stayed, dates changed, nights taken off, check-out date corrected, edit/modify booking), the room's saved cancellation tiers decide how much of the unused nights is kept: shown before confirm as "rate × nights × %". Staff can change the amount (0 up to the bill reduction) with a reason. A booking with no saved cancellation policy keeps nothing (ICTAm included).
- The amount kept is a line on the bill (VAT and levy inside), on the invoice; History "Kept for unused nights" / "No fee kept for unused nights". Notice date: the leave date at checkout/after-checkout, the arrival date for late-arrival nights, today for future changes (never later than today).
- Adjust Stay Dates no longer leaves paid money as credit: staff choose a refund or a credit note. Correct Stay Dates after check-out can now cut nights on rooms with a policy.
- Guides: check-out (early leave / late arrival), Adjust Stay Dates, Add / take off nights, cancellation policy page, FAQ "why was money kept when the guest left early". Assistant: its "Correct check-out" / "Change booking" cards do not show the kept amount yet and the save refuses when a fee applies — tell staff to use the screen (dept-ai-lead).

### Group bookings Ship C1: make primary, make a group, remove from group (2026-11-18, owner ask) — hashes in TASKS.md
- All Bookings has tick boxes (this page only; cancelled / expired / no-show / deleted cannot be ticked) and a "Make a group" bar: pick the primary room, review each room's paid / owed and the group totals, confirm. Refused when payers differ (company vs guest, two companies) or a booking is already in another group.
- Booking details "Rooms in this group" table: "Make primary" and "Remove" per room (review, then confirm; Edit Booking). The group invoice number never changes on a primary switch. A removed room becomes an individual booking with its own payments, balance, invoice and cancellation policy; if it held the group number, the number stays with the group (in a group of 2, with the room left) and the removed room gets its own number on its next invoice. Removing the primary room hands the group to another room first.
- Group documents print each distinct cancellation policy text ("For REF1, REF2: ..."). No money moves in any of these. History: "Made the primary room of the group", "Primary room of the group moved", "Group invoice number kept", "Added to a group", "Rooms added to the group", "Removed from the group", "Group invoice number left with the group", "Room removed from the group".
- Guides: Bookings (groups), booking details group table, FAQ "how do I join two bookings into one group / take a room out". Assistant: group answers must know these exist; it has no actions for them (screen only).

### Group bookings Ship C2: move credit between rooms, move group payment to a leaving room (2026-11-18, owner ask) — hashes in TASKS.md
- Booking details "Rooms in this group": when one room has credit and others owe, "Use this credit for the rooms that owe" (review each room's owed now → after, then confirm). It issues one credit note on the room with credit and applies it to the owing rooms; payments and receipts never move. Unused credit stays on its room. Refused when any room is company-billed. Needs Issue refunds (refund_payment).
- Remove from group: optional "Move part of the group payment to this room" (default none, at most what the room owes and what the other rooms can give); shown before confirm, done in the same step as the removal. Needs Issue refunds.
- A cancelled or no-show room gives only its credit, never what its policy kept. History: "Credit moved to rooms that owe", "Credit received from another room", "Group payment moved to a room that left", "Group payment received on leaving the group".
- Guides: booking details group table, Remove from group, credit notes page, FAQ "a room left early and has credit, others owe". Assistant: knows these screen actions exist; no Assistant action.

### Group bookings Ship C2b: same-company credit moves (2026-11-18, owner "same-payer rule") — hashes in TASKS.md
- Both C2 moves now also work when EVERY room is billed to the SAME company: only that company's money moves (credit note made out to the company); a guest's own extra money stays on its room (review says so); a receiving room takes at most what the company owes on it (never the guest's extras). Mixed guest/company or two companies: still refused, with the reason.
- Company accounts / company invoice: the moved money counts as the receiving room's company payment and comes off the giving room's; "company money received" does not drop. A company credit-note payment cannot be edited (change it on Credit Notes); refunds of it go to the company.
- History says "company money of <company>". Guides: same pages as C2 + Company accounts page. Assistant: knows the rule; no Assistant action.

### All Bookings row click opens the full booking (2026-11-18, owner "show the full booking and not the summary") — hashes in TASKS.md
- Clicking/tapping anywhere on a booking row (not on a button) now opens the full booking page (Booking Details), at every width. The (i) summary icon still opens the quick summary. Ctrl/Cmd-click opens the booking in a new tab.
- Guides: All Bookings page ("click a row to open the booking"). Assistant: no change.

### Group follow-up G4: a no-show / owing cancelled room can join a group (2026-11-18, owner "Yes it can still join") — hashes in TASKS.md
- All Bookings: the group tick now also shows on no-show rooms and on cancelled rooms that still owe a kept fee. Make a group lets them join; they keep their own owed amount and cancellation terms. A group payment pays them only what they still owe; credit from other rooms never moves into them.
- A no-show or cancelled room can never be the group's primary room (the lead choice is greyed out; Make primary is hidden on its booking page).
- History on the joining room: "Added to a group … Joined as a no-show: a group payment pays it only what it still owes." / "… Joined as a cancelled booking: only its kept fee counts in the group." Guides: All Bookings Make a group, booking details group table. Assistant: knows the joining rule; no Assistant action.

### Group follow-up G1: payment statuses never say "Completed" while the bill still owes (2026-11-18, owner) — hashes in TASKS.md
- Payment details (top badge, Status row, other payments), Accounting dashboard recent payments, booking History "Status now", Reports (Payment Status Mix) and the reports export now use the Payments list words: "Received" (the money came in) + whether the bill is settled or still owes MWK X. Refunds show their step ("Refund: Paid out", "Refund: Moved to credit note"); a payment made from a credit note reads "Paid from credit note" (guest credit used, not money received). Pending / failed / cancelled keep their own words.
- Guides: Payment details, Accounting dashboard, Reports pages. Assistant: payment rows' status words changed (it should say "Received" + bill position, never "Completed" for a part-paid bill); no Assistant action.

### Group follow-ups G2 + G3: change any room's dates and name who's staying, from the group table (2026-11-18, owner) — hashes in TASKS.md
- Booking details "Rooms in this group": each room has "Change dates". On the room you are on, its own date dialog opens; on another room it opens that room's page with the right dialog already open (Adjust Stay Dates before check-out, Correct Stay Dates after) and "Back to the group". Hidden on cancelled / no-show / expired rooms and for staff without that dialog's permission.
- Each room has "Who's staying" (optional name, up to 150 characters, any status incl. after check-out; Edit booking permission). Empty = the lead booker's name shows again. A checked-out room's invoice PDF is rebuilt with the new name. History on that room: "Name on the room changed" (old → new).
- Guides: booking details group table. Assistant: knows both buttons; no Assistant action.

### Group follow-up G5a: All Bookings tick column, select all and bulk actions (2026-11-18, owner "Yes I want them all") — hashes in TASKS.md
- All Bookings: the tick is its own first column (also on phones), with a header tick for select all / none. A bar shows "N selected · Clear" and the actions: Make a group, Add to an existing group, Remove from group, Send a reminder (late / overdue bookings, as the single button), Resend confirmation, Email invoice, Export selected (CSV), Print arrival list.
- Every action (except export / print) shows a review list of each ticked room — what will happen, or "skipped: reason" — then Confirm; each room is done or skipped on its own, with a result list. Same rules and permissions as each single button. At most 50 rooms per send, 200 per remove.
- History / email log as the single actions; one "bookings_bulk_apply" activity line per bulk confirm. Guides: All Bookings (ticks, bulk bar, each action). Assistant: knows the bulk bar exists; no Assistant action.

### Group follow-up G5b: bulk Confirm, Assign rooms, Check in (2026-11-18, owner) — hashes in TASKS.md
- All Bookings bulk bar adds Confirm (tentative / pending → confirmed), Assign rooms (the review proposes a free room per booking, never the same room twice for overlapping nights; each proposal can be changed before Confirm) and Check in (same gates as the single button: room assigned, payment rule, arrival day, room clean; late arrivals are skipped; no check-in email from the bulk bar).
- Confirming the same review twice never runs it twice; after a lost connection the message says the result is unknown (check History). The bookings CSV export neutralises cells that start like a spreadsheet formula.
- History as each single action. Guides: All Bookings bulk bar. Assistant: knows the bulk bar actions; no Assistant action.

### Group follow-up G5c: bulk Cancel and Mark no-show (2026-11-18, owner) — hashes in TASKS.md
- All Bookings bulk bar adds Cancel (one reason for all, "Email the guest" tick) and Mark no-show (optional note). The review shows each room's fee kept, refund, guest credit (or no-show fee) and what will happen; nothing changes until Confirm. Fees are the automatic ones (policy locked at booking, else the hotel rule / no-show setting); to change one room's fee, cancel it from its own booking. Rooms of one group that are all ticked are cancelled together. No-show needs a confirmed booking whose arrival date has passed.
- History, refunds and emails as the single row's Cancel / No-show. Guides: All Bookings bulk bar + cancelling a booking. Assistant: knows the bulk Cancel / no-show; no Assistant action.

### Check-in: group members use the lead's pay-later arrangement (2026-11-18, owner audit) — hashes in TASKS.md
- A room in a group whose lead booking has "check in now, pay later" (or a company account) can now be checked in unpaid from booking details, All Bookings and the check-in page (it was refused on save before).
- Make primary copies the pay-later switch and its note to the new lead (History: "Check in before payment copied", Off → On). Cancel check-in now writes History ("Check-in undone", Checked in → Confirmed).
- Guides: Groups (pay later for the whole group), Check-in (undo). Assistant: group members inherit the lead's pay-later arrangement at check-in.

### Reports: payment status mix adds up + credit-note payments as End of Day (2026-11-18, owner audit) — hashes in TASKS.md
- Payment Status Mix shows every status (incl. Received, part refunded / refunded, Paid from credit note, Failed) with a Total row equal to the payments in the period; the three Received lines = Gross Revenue. Helper text per line.
- Gross revenue, payment methods, daily / monthly trends and prior-period comparison no longer count payments made from a credit note (same as End of Day). Net Revenue takes off only the VAT on counted payments; VAT Collection shows "VAT on payments counted" and "VAT on credit-note payments" apart.
- CSV export: status breakdown follows the chosen dates, has a Total row, refund rows after it; no "Credit note" method line. Guides: Reports (Payment Status Mix, VAT Collection). Assistant: revenue answers use the same rule.


### Ship M: guest credit is not a refund, VAT counted once (2026-11-18, owner) - hashes in TASKS.md
- Guides (End of Day page/PDF/email/WhatsApp, Reports page + CSV export, timed Accounting Summary CSV): "Refunds"
  now counts only money really paid back to a guest; money moved onto a credit note is no longer a refund. New
  "Guest credit" lines: issued, used, voided, expired unused, still owed (Reports adds "Guest credit at start").
  "Moved to credit notes" and "Credit notes redeemed" lines are gone. "VAT on payments counted" is now "VAT
  Collected" (VAT on money received less VAT on real refunds); "VAT inside credit-note payments (information only)"
  shows beside it. Payments list, Accounting dashboard and guest history keep the old refund total until Ship M2.
- Assistant: new helpers rh_refund_payout_sql, rh_vat_once_sql, rh_vat_credit_note_info_sql (finance-refunds.php)
  and admin/includes/guest-credit.php (rh_guest_credit_summary / rh_guest_credit_lines); rh_eod_refunds_to_credit and
  rh_eod_credit_notes_redeemed removed. includes/ai/ai-insights.php ~315 still says "Moved to credit notes" - check at sync.

### Stock Step U1: count in shots / glasses (2026-11-18, owner) - hashes in TASKS.md
- Guides (Stock › Items, Stock › Stock count): an item can have a measure ("1 bottle = 25 shots", or glasses, cups...;
  which one is the stock unit), suggested from the size in the name and the shot/glass size settings. On a count line
  staff pick stock unit / bottles / shots / both ("2 bottles + 7 shots"); the line shows what it converts to and the
  cost per shot. "Set measure" from the count line (Approve stock counts or stock management). Changing N re-works
  draft and submitted counts, never approved ones. Approved count typed in bottles but counted in shots: "These were
  counted in shots" correction, Preview -> Confirm, once per line; it does not show as missing stock.
- Assistant: new columns stock_ingredients.measure_* and stock_count_lines.entry_*; helpers rh_stock_measure_* in
  config/database.php (rh_stock_measure_fix_sql excludes the correction from loss lists); activity actions
  stock_measure_changed, stock_count_line_measure_changed, stock_count_measure_correction. Counts it reads stay in the
  stock unit.

### Ship Q: group remove keeps / drops pay later, rooms' own names + dates in emails (2026-11-19, owner) - hashes in TASKS.md
- Guides (Bookings › Groups): removing a room that gets "check in now, pay later" from its group (single Remove or bulk
  Remove) asks "Keep pay later for this room" or "Drop it - this room pays on its own"; History "Kept pay-later from
  group" / "Dropped pay-later from group". A no-show room's unpaid fee never stops the rest of the group checking out;
  the fee stays owed on that room. Received / Confirmed / Tentative / quotation emails list each room with its own
  dates and nights and "Who's staying" name when entered.
- Assistant: rh_group_remove opts take the pay-later choice (keep/drop), refused when needed and missing; email rate
  block rh_email_booked_rate_rows rows carry per-room dates/nights + room_guest_name.

### Ship M2 + step 13: refunds = payouts on Payments / Accounting / guest history; one payment label everywhere (2026-11-19, owner, Bundle A) - Rosalyn's d10e20c, Liwonde 348a182
- Guides: Payments "Refunds" card and Accounting dashboard "Refunds" (KPI, pop-up, reasons, trend, latest list) count
  only money paid back in cash, bank or mobile money; money moved onto a credit note shows on its own "Moved to
  guest credit" line, never in the refunds total. Accounting Net reads "Gross − refunds − moved to guest credit"
  (same figure as before). Guest history and Booking details: "Refunded" = paid back only, "Moved to guest credit"
  on its own line; Received / balances unchanged.
- Guides: Bookings Paid / Unpaid tabs, their counts, the Payment filter (part paid / refunded), the sort and the CSV
  export follow the badge (from the money: Paid in full / Part paid (MWK X left) / Unpaid / Refunded). The same
  words on booking lookup (guest page), Individual Rooms booking cards, the Bookings stats pop-up and the calendar
  tooltip (coloured like the list's pill, no Title Case).
- Assistant: includes/ai/ai-insights.php ~303/315 ("Moved to credit notes" wording, still on the settled refund
  total), includes/ai/ai-decisions.php ~1626 (refund figures on the settled rule) and includes/ai/ai-tools.php
  ~1864 (money.refunded should mean payouts only, with moved-to-credit-notes separate) - align to
  rh_refund_payout_sql / rh_refund_to_credit_sql at sync. Bookings "paid / unpaid" answers should use
  rh_bk_pay_key_sql (admin/includes/bookings-list-filters.php), not stored payment_status.

### Step 14: POS till reuses a Fire whose reply was lost; void lock order (2026-11-19, owner, Bundle C) - Rosalyn's 704d493, Liwonde a4a8ec9
- Guides (POS till): if the connection drops just after Fire, the next Pay or edit picks up the tab that was already
  sent (no second tab); an edited cart whose Fire had already landed shows that sent order instead of adding to it -
  the cashier re-adds anything extra. No new screens.
- Assistant: nothing to change (no AI wording touched).

### Bundle B: group emails, invoice and quotation list every room (2026-11-19, owner) - Liwonde a2de61b, Rosalyn's see TASKS.md
- Guides: group invoice email + booked-rate lines show each room's guest (only when entered); invoice PDF room line
  shows "(N nights)"; a group quotation lists every live room and its total is the sum of the rooms (cancelled rooms
  left out); reminder, pre-arrival, check-in, cancellation, no-show, extend and modified emails list the group's rooms
  with their own dates; a cancelled / no-show room shows its state and "fee kept", never as payable. Convert to
  invoice: an old one-room quote is compared room to room, a new group quote with the group stay total.
- Assistant: quotation answers should use the group stay total for group quotes (includes/quotation-convert.php
  rh_qc_quote_amount); room lists from includes/group-room-list.php.

### Stock Step U3: Change main unit (bottle <-> shot / glass) (2026-11-19, owner, Bundle D) - Liwonde a1dba0e, Rosalyn's see TASKS.md
- Guides (08 Stock; 18 Staff Access): Stock Items > item > Change main unit switches the item between its two measures
  (needs a count measure first, e.g. bottle and shot, 30 in one). Preview lists every place that changes (on hand,
  cost of one, batches, recipes, open counts, open orders) with old -> new and the stock value before and after
  (always the same); Confirm applies it at once; History / System Logs show who, when, old -> new. New permission
  "Change main unit" (stock_change_unit, Restaurant & Stock, default admin + manager). A count made before a unit
  change can no longer be taken back on Clear Stock Data (correct it with a new count).
- Manager Guides: stock.html "Coming soon: Change main unit" becomes a real short section; staff-access.html risky
  permissions table: Change main unit no longer "Coming soon". Rebuild stock + staff-access, check 2 pages each.
- Assistant: stock answers should give quantities in the item's current unit; a unit change is in stock_unit_changes.
### Stock: "Show it in" takes a custom amount (2026-11-19, owner) - Liwonde bcb7d15, Rosalyn's 23da18b
- Guides (08 Stock): Add / Edit ingredient > Bottle size and drink measures > Show it in: last option "Other
  amount (type it)": type a name and ml (e.g. double, 35) to see how many are in one bottle and the cost of one;
  Use this for counting fills the count measure with it (saved with Save). For recipes and the menu too, add the
  measure under Drink measures. Manager Guides: stock.html measures paragraph, one line.
- Assistant: nothing to change.
### Import: Drinks for the menu (2026-11-19, owner, drinks profit projection P1) - Liwonde 994f0f8, Rosalyn's see TASKS.md
- Guides (08 Stock + Import guide): step 4 Confirm now lists "Drinks for the menu" when the file has a selling price:
  name, sold as, price (VAT included, used exactly, never added), category (file's column, else Bar; per row or
  "Set for all"), cost of one, profit of one, below-cost warning. Nothing goes on the menu unless ticked; a drink
  already on the menu shows "Update price X -> Y". Ticking needs Import + Menu permissions. Ticked drinks go on the
  drinks menu and the till (bar) with a one-measure stock link; History / System Logs show old -> new.
- Manager Guides: stock.html import paragraph, one or two lines. Rebuild stock, check 2 pages.
- Assistant: answer "which drinks did the import offer for the menu" (AI_REQUEST to the AI lead; data in the
  import job's answers `_drinks` + activity log `drink_menu_import`).
### Stock Reports: Projected profit tab (2026-11-20, owner, drinks profit projection P2) - Liwonde 5033c0f, Rosalyn's see TASKS.md
- Guides (08 Stock): Stock Reports > Projected profit: one row per menu price of a drink sold as itself (one stock
  item): on hand, measures per unit, selling price, projected sales, stock cost, projected profit, margin. Cards on
  top = each stock item counted once, at its lowest-selling menu price ("In totals"); cost not known is left out.
  "Take VAT out" switch (on by default when VAT is on) shows sales/profit without VAT; the menu is not changed.
  Filter cards: Sells below cost (red), No selling price yet (drink stock only; link to the Menu page), Cost not known. Read-only.
- Manager Guides: stock.html reports paragraph, one or two lines. Rebuild stock, check 2 pages.
- Assistant: answer "what profit will our drinks stock make" (AI_REQUEST to the AI lead; reuse
  admin/includes/stock-projected-profit.php rh_spp_load + rh_spp_build, same totals rule and VAT switch).

### Stock Reports: Projected vs actual tab (2026-11-20, owner, drinks profit projection P3) - Liwonde d10a051, Rosalyn's see TASKS.md
- Guides (08 Stock): Stock Reports > Projected vs actual: pick dates; per drink, between ITS OWN first and last
  approved counts in those dates: opening/closing count, actual used, sold (till + room service, voids put back),
  unaccounted measures (free pours, spillage, missing) at menu price (Take VAT out switch as Projected profit) and at
  today's cost; recorded wastage is never in the gap. Second line "Till vs menu price" = what the till took vs sold x
  today's menu price (happy hour/deals, discounts, comps, price changes; order discounts shared across the bill's
  lines). Fewer than two approved counts = "count it again" message, no figure. Cocktails count as sold but are not
  in the till line. Totals net extra stock found against losses (card help says so). Read-only. Also: Stock-In,
  Usage, Wastage, Yield, Adjustments now honour From/To (were stuck on last 30 days).
- Manager Guides: stock.html - illustrated, simple "count -> report -> gap" explanation (owner asked 2026-11-20;
  manager-guides-lead dispatched the same day).
- Assistant: answer "how much drink went missing between counts" (AI_REQUEST to the AI lead; reuse
  admin/includes/stock-projected-vs-actual.php, same per-item snapping, totals and VAT rules).
## Synced 2026-11-16 (guides + Assistant: every entry from 2026-11-14 to 2026-11-16 below, including the 2026-11-15/16 entries further down; hashes in TASKS.md) - entries kept for reference

### Import stock check: value column warning + stock check workbook (2026-11-16, owner ask) — hashes in TASKS.md
- Guides (Stock > Import, review / confirm / result / history): when the sheet's value column (e.g. "Stock Value")
  disagrees with Quantity x Unit Cost, a warning shows how many rows and how much more/less (MWK), with the rows (10 per
  page). "Download stock check" gives a workbook: Summary, "<column> wrong" (biggest gap first, red = sheet shows more,
  green = less, implied quantity, blank Manager's explanation column) and Missing prices (items with stock but no cost,
  same count as the "no cost" list before Import). Offered in review, after Import and from Import history; logged as
  stock_check_export. Never changes what is imported.
- Assistant: result_json gains value_check / stock_check keys (includes/ai/ai-import.php reads result_json) - check at sync.

### Import wizard reads more column headings (2026-11-16, owner ask) — hashes in TASKS.md
- Guides (Stock > Import, step 2): the wizard now recognises our own Stock > Ingredients export headings and common
  variants (Current Quantity level / Current Qty = Balance; Unit Cost / Cost/Unit / Average cost = Unit price; Max qty /
  Order up to = Par; Next Expiry = Expiry; Lead Time greyed). "Min Qty / Minimum Quantity level" is greyed when the sheet
  has a Reorder Point column, otherwise used as the reorder level. Headings with total / value / amount / days are only
  used on an exact match, otherwise asked.
- Assistant: no change in what it reads (vocabulary only); no sync needed beyond noting new field ids min_qty/lead_time.

### Wipe all stock (full reset) permission + section (2026-11-16, owner ask) — hashes in TASKS.md
- Guides (Settings > Clear Stock Data, User Management > permissions): new permission "Wipe all stock (full reset)"
  (super admins always; a plain administrator only when a super admin switches it on; never managers/staff). Clear
  Stock Data opens for it and shows a top section "Wipe all stock and every record": preview, then tick I understand +
  type WIPE ALL + own password; removes every stock table's rows plus Import history; full backup + snapshot; the
  stock part can be undone from Past clears; logged as stock_wipe_all. The older sections stay on Clear Stock Data.
- Assistant: AI files and admin/system-logs.php:561 still check only data_reset; check at sync time whether the
  Assistant should mention or respect stock_wipe_all.

### Import wizard: tick all + honest not-counted reasons (2026-11-16, owner ask) — hashes in TASKS.md
- Guides (Stock > Import, step 3/4 and result): two buttons for items already in stock, "Set quantity for every matched
  item" and "Use the file's unit cost for every matched item" (each shows how many first, each can be cleared again);
  before Import a warning lists every item with stock that will NOT be in the opening count, by reason (no cost in the
  file / file cost not ticked / quantity not ticked / row skipped / already in another unfinished opening count) with a
  fix button where one helps; the result screen lists "no price" and "not ticked" separately.
- Assistant: includes/ai/ai-import.php:322 "will_update" leaves out quantity-only ticks; result_json has a new key
  stock_not_counted_not_ticked (ai-import.php:200/323/594 read the result) - check at sync time.

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

### Individual rooms + housekeeping review (2026-11-16, NEXT_BUILD_ORDER step 12a) — SHIPPED (hashes: see NEXT_BUILD_ORDER.md row 12a)
- Staff-visible: checkout no longer sends an out-of-order room, a room still holding another checked-in guest, or a
  room under blocking maintenance to Cleaning (maintenance rooms show "Room is in maintenance; its clean waits");
  check-in "not clean" override only covers dirty/cleaning rooms (occupied/maintenance/out of order always refused);
  changing a booking's room refuses a pair room a waiting joined-room booking needs; in-house move puts the old room
  on the Housekeeping list; Individual Rooms refuses statuses that contradict bookings, Cleaning creates one task;
  Create Checkout Cleanup covers every room of a booking; Maintenance "Block room during maintenance" unticked =
  room stays on sale (owner 2026-11-16); Housekeeping + Maintenance filters page 10 + picker, Clear, search all
  rows; column helpers; overdue guests show "Overdue".
- Guides: housekeeping, maintenance, individual rooms, check-in/checkout pages. Assistant: room status / checkout
  answers must not say a maintenance or out-of-order room was sent to cleaning.

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

### Rate plan: bottom amount optional when every chosen room type has its own (2026-11-16, owner fault) — hashes in TASKS.md
- Guides (Rate Plans): with "Different per room type", a plan for chosen room types where each has its own amount can leave "Amount for any room type left empty above" empty; a plan for all room types still needs it (a room type added later uses it); the error names any room type left without an amount.
- Assistant: no change in what it reads (adjustment_value still set: the first chosen room type's amount).
- Follow-up (same day, owner): a room type at 0 keeps its normal price and is left out of the plan; bottom amount empty or 0 = room types without their own amount keep their normal price; the plan then covers exactly the room types that change (so a room type added later is untouched). Replaces the line above about all-room-type plans needing the bottom amount.

### Import: clearer flags (2026-11-16, owner ask "RBH REVISED STOCK NOV26") - pending ship
- Guides (Stock > Import, guide 23): "look very similar" no longer asks about two names that differ by one whole word (Black tea / Black tie, sweet red / sweet rose); the question that stays shows each row's quantity and unit cost and says "Use X for both" makes ONE item and ADDS the quantities (different sheets: you are then asked add or first sheet only), and that answer no longer leaves the item stuck. Case-price question shows the unit, the counted quantity, the line value and what each answer does (example pack, quantity stays in the sheet's unit). Value check: every disagreeing row has "Most likely: ..." on Step 3 and a "What it most likely means" column in the stock-check workbook; the Summary states the counted fact. "trays" reads as "tray".
- Assistant: question texts it may relay changed (possible-typo, case-price); value-check rows carry `why` / `why_code` (qty-changed, no-qty, no-value, no-fit) and groups `why_*` counts. includes/ai/ai-import.php needs no change (it keys on question type).

### Menu items: Save adds a picked stock item (2026-11-17, owner fault "keeps saying something is picked but not added") - hashes in TASKS.md
- Guides (Menu Management, add/edit item): you no longer have to press Add before Save. A stock item picked with an amount typed is added when you press Save; one already in the list is just cleared; picked with no amount, Save asks "Type how much X one sale uses, or clear the Stock item box." A "New category" typed with other capitals (drinks / Drinks) joins the existing category. New stock item from "Not in the list?" is picked for you.
- Assistant: no change in what it reads (same tables, same recipe lines).

### Import: case price vs unit price + "May be a case price" note (2026-11-17, owner ask "add logic in determining during import to decide and also flag any suspect") - hashes in TASKS.md
- Guides (Stock > Import, guide 23; Stock items): Step 3 can show a case question already "Answered for you" with a one-line reason when this hotel's own data is clear (selling price of a linked recipe, the item's pack size × known cost, or the sheet's value cell); it can be changed. Step 4 card "Read as case prices". Ticking Pack also ticks Cost (warning says so); answering "keep the current price" drops the Pack tick; a pack without its divided cost is refused. Stock items list: "May be a case price" note under Avg cost / unit, its ? says what it compared; it changes nothing.
- Assistant (AI_REQUEST): new import summary keys `auto_reason`, `pack_hint`, `case_evidence`, item `cost_suspect`, overview `case_auto`.

### Stock: Fix crate prices (2026-11-17, owner ask "Correction tool 'price was for a crate of N'") - hashes in TASKS.md
- Guides (Stock, new page after Buying): Stock › Fix crate prices: type how many units are in one crate/case (or "Fill from list" with the CSV: Item + Units in one crate/case; rows marked Y skipped; unmatched names listed), Preview (old → new cost and value per item, totals), Confirm. Cost and the stock still held are divided; unit and quantities unchanged; past counts and deliveries keep their numbers. Refused: an item on a stock count not yet approved (approve or delete that count first), or a cost changed since Preview. Stock items' "May be a case price" note links here. Staff activity shows "Corrected a crate price".
- Assistant: new page stock-cost-fix.php, permission stock_management, activity action stock_cost_correction; nothing else it reads changed.

### Fix crate prices: purchase orders, per-batch ticks, full log text (2026-11-17, owner "fix these") - hashes in TASKS.md
- Guides (Stock › Fix crate prices): Preview now has a "Divide this batch" box per batch still holding stock (batches already at a price for one start unticked) and shows the new cost as the average cost of the stock held; open purchase-order lines still at the crate price are divided too (line total old → new, order total recomputed; check the quantity is in single units, not crates, and send the supplier the corrected order if it was already sent). Staff activity shows the whole "Corrected a crate price" entry.
- Assistant: no change in what it reads (PO unit_cost/line_total/total_cost may now change through this page; activity text longer).

### Fix crate prices page redone (2026-11-17, owner "UI all over the place … explanation needs to be easier") - hashes in TASKS.md
- Guides (Stock › Fix crate prices): page is now 3 steps: 1 Type the crate size (box next to the item name; "Choose CSV file" then "Fill from list"; "May be a case price" is a filter tick), 2 Check (Preview → "Now: X each → After: Y each", value before/after), 3 Save (Confirm and correct). Short 3-line intro + "More detail". Screenshots for the guide should be retaken.
- Assistant: no change.

### Assistant: stock name check, phase 1 (2026-11-17, owner "use it … just as a helper … without it on it works still like this") - hashes in TASKS.md
- Guides (AI Settings; Stock › Fix crate prices): AI Settings has a "stock name check" switch (OFF by default), a list of what it learned from people (remove a wrong one), and pre-fill stays locked until the name test passes on the hotel's own answers. On Fix crate prices, with it on: "Check names" marks items whose price looks like a crate price for that kind of drink and offers "Use N" per row; "Use all" appears only after the name test passes. You still Preview and Confirm. The Assistant notice now folds into a small pill beside the Assistant button after a few seconds (Escape folds it).
- Assistant (dept-ai-lead's own feature): new tool surface ai-stock-helper endpoint; settings ai_stock_helper_enabled / ai_stock_prefill_enabled; table ai_stock_kinds.

### Import reads bar stock sheets (2026-11-17, owner "without needing me to answer so many questions") - hashes in TASKS.md
- Guides (Stock > Import, guide 23): Import now recognises quantity ("Total Balance"), selling price, "bought as" (crate/case), "how many in one" and "sold/served by" columns, also from misspelt headings and from the values; a crate/case price is divided by how many are in it automatically with a reason (same unit = price of one); a Stock Value at selling price is recognised and only rows that differ are listed; section rows and TOTAL rows are skipped; a name in two sections becomes two items with the section added. It still asks a row when the numbers don't add up (price already at or below selling price, odd pack size, cases vs single units). An existing item still on a crate price stays out of the opening count until Cost is ticked.
- Assistant: import summary carries the value-at-selling-price line (differ, both totals); new hook import_unsure_columns / import_attach_column_suggestions for the AI column reader (not built yet).

### Group booking page + group emails (2026-11-18) - Rosalyn's e7d69dd, a54a28c / Liwonde 5f4b5b0, c31882f
- Guides (Bookings > booking details, group bookings): "Rooms in this group" folds open/closed (remembered); the totals are now the table's last row "All N rooms" with the Net position under it. Who pays: on the lead room it says which other rooms that choice covers; on another room it says it follows the lead room (link) unless it has its own company. Check in before payment: shows "Already allowed" when the room's company, or the lead room's switch or company, already lets it check in before paying; on the lead room it says which other rooms its switch covers.
- Guides (emails): a group's Booking received / Confirmed / Tentative email from the lead room shows the whole party's total (all rooms), not the lead room only.
- Assistant: no change in tools; if it explains check-in before payment for a group room, the lead room's switch or company covers rooms without their own.

### Step S1: import crate check + menu cost warning (2026-11-19) - Rosalyn's 1af010e / Liwonde efea0f2
- Guides (Stock > Import, guide 23): a row saying "bought by the case of N" with NO selling price beside it is divided only when the hotel's own data agrees (cost in stock, deliveries, the sheet's value, menu price, or other checked case rows of the same sheet at a similar price); otherwise one question asks "price of one or of the whole case?" (N pre-filled), and like rows share one question. A pack-size column found only by its place is asked once.
- Guides (Menu Management): red "Costs more than it sells for" badge on a row, and a warning in the Edit form, when one sale's stock cost is above the price (free items never); "Fix crate prices" link (stock managers) opens that stock item on Fix crate prices.
- Assistant: no change in tools; new helper rh_menu_cost_check() in admin/includes/menu-recipe-lib.php could back a "which menu items lose money" answer.

### Stock Step U2: drink measures everywhere (2026-11-19) - Rosalyn's 321699c / Liwonde 20c492a
- Guides (Stock > Ingredients): hotel measures list (shot, tot, glass, pour... with ml; names refuse < > & "); an item can have its own volume in ml, shown as "1 L bottle = 33 shots"; crate/case/pack items never take a size from their name. Needs migration 089 (applied at both hotels 2026-11-19).
- Guides (Recipes + Menu Management): a recipe line can be typed as "2 shots", "50 ml", "1 glass"; it shows the stock-unit fraction and cost live and is saved in the stock unit, so the till and kitchen deduct as before.
- Guides (Stock > Import, guide 23): measure columns (tots per bottle, price per tot) are read; an unclear measure word or count is asked; a price-per-tot price is kept as written.
- Assistant: part (e) (suggest bottle size / measure for unsized names, spec AI_STOCK_HELPER_SPEC.md) only when the owner prompts; helpers rh_drink_* in config/database.php can back measure answers.

### Stock: custom drink measures + Edit ingredient layout (2026-11-19) - Rosalyn's e415694 / Liwonde 13b2c0e
- Guides (Stock > Ingredients): the Drink measures list takes the hotel's own measures (jigger, half tot, carafe...) with any ml; plurals typed on recipe lines are understood ("2 jiggers"); the same name twice (shot / shots) is refused. Edit ingredient is now a wide dialog in five sections (Basic details, Units and packs, Stock levels and supplier, Bottle size and drink measures, Count measure). Screenshots in guide 08 need refreshing.
- Guides (Stock > Import, guide 23): one-word measures from the hotel list are read from a sheet ("40 jiggers"); multi-word or unknown words are asked.
- Assistant: no change in tools.
- Manager quick guide PDF sent to the owner 2026-11-19 (OneDrive\MSP\Rosalyns-Stock-Quick-Guide.pdf).

### Stock: yield explained plainly (2026-11-19) - Rosalyn's 1f0953a / Liwonde 6e3dc69
- Guides: guide 08 already updated in this ship (chips example). Other guides that mention yield (recipes/menu, KB answers) should use the same example: 10 kg potatoes peeled to 8 kg = 80 %; 250 g chips plate takes 313 g raw; sack makes 32 plates, not 40; type the plate amount; rice/pasta dry amount at 100 %.
- Assistant: if it explains yield, use the same example and rule (raw taken off = plate amount / yield).

## Known leftovers from earlier syncs (fix during a sync, never on their own)
- `ai_assistant_test` at Liwonde: 7 data-dependent fails (left-owing + refunds briefing, 4 alert checks, digest
  recipients); ai_review_engine "nothing to add for this person" fails on HEAD too. (Tomorrow / 4 of 3 / K1,068,000 now pass.)
- `ai-decisions` ~1620/1777 dept-takings/VAT still on payment_date (matches Reports); "Open refunds"
  link filters Payments by payment_date.
- `rh_ai_t_guest_history` drops no-email bookings when one email is found; `balances_due` per-row fallback (speed).
- (2026-11-24) System Logs has no words for action `drink_menu_from_stock` (admin/includes/staff-activity-lib.php, logs lead).
- (2026-11-24) Manager Guide Bookings: Blocked Dates calendar + Extend / drag handles left out (page 2 full);
  the blocks-skip-booked-nights line is in Housekeeping and Maintenance instead.
- (2026-11-24) Import guide has no screenshots in docs/guides; none out of date there.

## Synced
- 2026-11-26 (owner prompted), DONE: Guides + Assistant, commit "Guides + Assistant sync 2026-11-26" in both repos (entries in the 'Synced 2026-11-26' section).
  Manager Guides: Money and End of Day, Bookings, Daily checklist refreshed; the rest no change.
- 2026-11-24 (owner prompted), DONE: Guides + Assistant, commit "Guides + Assistant sync 2026-11-24" in both repos (entries in the 'Synced 2026-11-24' section).
  Manager Guides: Stock, POS and Kitchen Screens, Bookings, Housekeeping and Maintenance, Money and End of Day, Daily checklist, Staff and Permissions refreshed.
- 2026-11-20 (owner prompted), DONE: Guides Rosalyn's 909510e / Liwonde 8704293; Assistant see TASKS.md SYNC 2026-11-20 (entries in the 'Synced 2026-11-20' section).
  Manager Guides: Stock, Bookings, Money and End of Day, Staff and Permissions refreshed; Daily checklist no change.
- Through 943ca16: Rosalyn's 55827b0 / Liwonde 717b36e (and earlier 859473d / 8f7301d, 5085f61 / 2c82b0e).
  Full history: `.claude/TASKS.md` Shipped + `.claude/archive/`.
