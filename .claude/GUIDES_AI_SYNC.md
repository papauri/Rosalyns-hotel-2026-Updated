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
