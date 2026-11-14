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

(nothing pending - add the next ship's entry here)

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
