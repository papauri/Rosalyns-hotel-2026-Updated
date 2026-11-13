# Cancellation & no-show policies - build spec (FEATURE, owner-approved 2026-11-13/14)

Owner: dept-accounting-lead (money gate). Written 2026-11-14 for ONE builder (Opus). ROSALYN'S IS LIVE:
no DB tests, scripts, test emails or settings writes against Rosalyn's; every rehearsal runs at LIWONDE.
Owner decisions are fixed (TASKS.md "CANCELLATION & NO-SHOW POLICIES"); do not reopen them.

## 1. The model (decision)

**One switch, one snapshot, two existing money mechanisms.**

- New setting `cancellation_policy_enabled` (code default `'0'`, never seeded). It ONLY decides whether a NEW
  booking gets a policy snapshot. Off = no snapshot is written = every path runs today's code byte-for-byte.
- A booking with a snapshot (`bookings.cancellation_policy_snapshot`, NOT NULL) is governed by it forever, even
  if the policy is later switched Off or edited. A booking with NULL follows today's rules forever.
- Cancellation fee (snapshot bookings) = tier % x whole stay. It is written to the existing
  `cancellation_retained_amount`; it REPLACES the `keep_first_night` retention, never adds to it.
- No-show fee (snapshot bookings) = no-show % x whole stay: 100% = booked stay keeps billing (as today); below 100%
  = the existing tagged folio row (`rh_noshow_fee_tag()`). It REPLACES `group_noshow_charge` for that booking.

How each existing setting behaves for a snapshot booking (NULL-snapshot bookings: unchanged):

| Setting | Today (no snapshot) | Snapshot booking |
|---|---|---|
| `cancellation_refund_mode` void_refund_all | bill voided, refund all | fee kept, auto-refund paid above the fee and folio charges |
| `cancellation_refund_mode` keep_first_night | first night kept, rest refunded | same as void_refund_all: the tier fee is the only retention |
| `cancellation_refund_mode` void_keep_credit | bill voided, paid becomes credit | fee kept, anything paid above it stays as `credit_balance` (no auto refund) |
| `no_show_refund_policy` none (default) | full stay billed, no refund | 100%: as today; <100%: tagged fee row, paid above it stays as credit owed back |
| `no_show_refund_policy` first_night / full | refund per rule | both mean "refund what was paid above the no-show charge" (this is the existing tagged branch in `createNoShowRefund`, booking-functions.php ~:621) |
| `group_noshow_charge` | tagged row for group rooms | NOT run (the policy fee owns the tag) |
| expiry (`expired`) | `group_noshow_charge` only | unchanged: the policy never charges expiry (never-confirmed holds) |
| `cancellation_notice_days` | blocks online cancel inside N days | unchanged (see OWNER-Q1) |

No double charging is structural: one retention column for cancellations, one idempotency tag for no-shows.

## 2. Data model

**Settings (code defaults only; nothing seeded; written only by the Hotel Settings form when changed):**

| Key | Default | Meaning |
|---|---|---|
| `cancellation_policy_enabled` | `'0'` | snapshot new bookings |
| `cancellation_policy_individual` | `''` | JSON `{"tiers":[{"days":7,"pct":50},{"days":2,"pct":100}],"noshow_pct":100}` |
| `cancellation_policy_group` | `''` | same shape; empty = groups use the individual policy |
| `cancellation_policy_group_min_rooms` | `'2'` | int 2..50 |
| `cancellation_policy_note` | `''` | optional extra sentence (<= 300 chars), printed after the generated text |
| `cancellation_policy_version` | `'0'` | int, +1 on every save that changes anything |
| `no_show_refund_policy` | `'none'` (code default, as today) | now visible and editable; own save field, written only when changed |

Validation: <= 5 tiers; `days` int 1..365 unique (strict "fewer than X days", so 1 = arrival day only); `pct` 0..100 (2 dp); `noshow_pct` 0..100 default 100; blank rows dropped; sorted by days desc.

**Migration `admin/migrations/085_booking_cancellation_policy.php`** (next free; 084 is the last in both repos).
Additive, idempotent, information_schema check like 083:
`bookings.cancellation_policy_snapshot TEXT NULL DEFAULT NULL COMMENT 'Cancellation/no-show policy locked at booking (JSON); NULL = legacy rules'`.
No rows touched. The Liwonde mirror copies only shared columns, so it tolerates the column either way.

**Snapshot JSON (written once, never updated):**
`{"v":1,"kind":"individual|group","group_min_rooms":2,"rooms_at_booking":3,"tiers":[...],"noshow_pct":100,
"policy_version":7,"taken_at":"2026-11-20 14:03:11","text":"<generated fine print>"}`

**Pre-migration safety (the code ships before the owner approves 085 at Rosalyn's):** every read uses
`$b['cancellation_policy_snapshot'] ?? null` (JSON decode failure = NULL = legacy); snapshot writes are guarded by
one cached `rh_cancel_policy_column_ready(PDO)` (information_schema); the editor stays hidden (a note says it needs
the database update) until 085 has run. Shipped code is Off-equivalent at Rosalyn's until then.

**Existing bookings:** NULL snapshot. Today's rules for cancel, no-show and expiry. Their documents print no policy block.

## 3. Fee computation (all in a new `includes/cancellation-policy.php`; pure functions + one reader)

- `rh_cancel_policy_settings(): array`: reads and validates the settings above.
- `rh_cancel_policy_for_kind(string $kind): ?array`: the group policy, else the individual policy; null when not set.
- `rh_cancel_policy_snapshot_build(string $kind, int $rooms): ?array`: builds the JSON array, including `text`.
- `rh_cancel_policy_text(array $policy, string $kind): string`: the ONE generator of the fine print (pure).
- `rh_days_before_arrival(string $checkInDate, ?DateTimeImmutable $now = null): int`: calendar days in `RH_TIMEZONE`
  (`check_in_date - today`). The `timezone` site setting is NOT used (it reads `UTC` at Liwonde). Negative = arrival
  date passed. Also replace the hard-coded `Africa/Blantyre` in booking-lookup.php :106 with this helper.
- `rh_cancel_policy_tier(array $tiers, int $daysBefore): ?array`: the tier with the SMALLEST `days` where
  `$daysBefore < days` (STRICT); none = no charge. A negative value matches the smallest tier. OWNER DECIDED
  2026-11-14: "7 days" means cancelling exactly 7 days before arrival is still FREE (arrival 20th, cancel on the
  13th = no fee; on the 14th = fee). So a tier charges when cancelled FEWER THAN X days before arrival; arrival
  day = 0. A 0-day tier therefore never matches (validation: `days` 1..365). The settings preview text shows the
  exact boundary with a dated example. Every fee figure (preview, History, emails) shows its basis per the booked-rate rule:
  "50% of the stay MWK X (booked rate Y x 3 nights)".
- `rh_cancellation_fee_quote(PDO $pdo, array $b, string $event = 'cancel'): array` returns
  `{source:'policy'|'legacy', applies:bool, pct:float, amount:float, days_before:int, tier_label:string, kind:string}`.
  It is read-only. The modals and the settle code both call it.

**Base ("whole stay") = the booking's stored `total_with_vat`** (else `total_amount + vat_amount`) at the moment
of the event. That base includes the room nights, child supplement, packages, VAT and tourism levy. It excludes folio
charges (extras, room service, minibar); those stay billable as today and are never part of the %.
- Rate corrections, date changes, extend and shorten all change the stored stay, so the fee follows the stay
  as it stands then. The % stays locked.
- `amount = round(pct/100 * base, 2)`; anything below BALANCE_TOLERANCE counts as 0.
- VAT and levy inside the fee follow the shipped rule: `rh_room_night_share()` (e291682).

**Who is exempt (fee 0, legacy behaviour):** status `tentative` (never confirmed, as today). Also exempt: the
auto-release of an unpaid confirmed booking (`rh_release_unpaid_confirmed_booking`, auto-email-jobs.php :1144,
writes retained 0) - OWNER DECIDED 2026-11-14 (Q2: no fee). `pending` is ALSO exempt: fee 0, everything paid is
refunded per the refund mode - OWNER DECIDED 2026-11-14 (Q3: no fee until the hotel has confirmed). This overrides
today's `keep_first_night` retention for pending SNAPSHOT bookings only; NULL-snapshot pending bookings keep today's rules.

**Groups:** kind = `group` when the bookings rows created together (lead + secondaries via `primary_booking_id`)
number >= `group_min_rooms` (same row-count rule as `rh_group_noshow_fee_applies`); a joined-rooms family booking
is ONE row, so `individual`. Decided once at creation, written onto every row. Partial-group cancel: each cancelled
room pays its own snapshot % of its own stay; the rest are untouched. Cancelled lead: no money effect
(`rh_booking_group_effective_lead` unchanged). Soft-deleted rooms: not a cancellation, no fee
(booking-soft-delete.php :116 keeps writing NULL).

**Pay-later / company billing:** the fee becomes what the booking owes. When `billing_account_id` is set, the
company owes it; billing-accounts.php :229 already reads `cancellation_retained_amount`, so nothing changes there.

## 4. Application

**Snapshot write (only when `cancellation_policy_enabled = '1'` and the column is ready)**: one helper
`rh_cancel_policy_stamp(PDO, array $ids)` runs once, AFTER group linking, inside the creating transaction, over
every id. The UPDATE has `WHERE cancellation_policy_snapshot IS NULL`, so a snapshot is never overwritten. The
four insert paths:

| Path | Where the stamp goes |
|---|---|
| booking.php | ONE call `rh_cancel_policy_stamp($pdo, $createdBookingIds)` between the group link (:714-:719) and the commit (:739). Single and split inserts share one loop (:587-:709); stamping inside the loop would run before linking and get the group kind wrong (corrected 2026-11-14 per GUEST_BOOKING_ALIGNMENT_PLAN §5) |
| includes/mixed-booking.php | after :578 (link loop), before the commit at :587, all ids |
| admin/create-booking.php | after the insert ~:838 (and its group link if any) |
| api/bookings.php | after the combination assignment (:365-:376), before the commit at :383 (not after the insert :317). The API never links rooms, so kind is always individual |

**Also part of this build (from GUEST_BOOKING_ALIGNMENT_PLAN F2):** the guest "Cancel My Booking" on booking-lookup.php
must cancel the whole party, not only the looked-up row: only rows carrying the email the guest proved; list every room
+ reference + total fee + refund before the button. `rh_cancel_booking_group` (database.php ~:10663, lines drifted)
gets `$feeChoice` AND a refund-status parameter in ONE edit (guest path needs 'pending' when
`guest_self_cancel_refund` = pending). Company-billed parties (`billing_account_id` on the lead): online cancel hidden,
"contact the hotel" instead - OWNER DECIDED 2026-11-14 (yes to whole-party cancel, yes to hiding it for company-billed). F5 (soft-deleted rows hidden from lookup/cancel) must ship
BEFORE the policy is switched On.

A tentative booking is stamped at creation and keeps that snapshot on conversion (the guest saw it on the quote).

**Cancellation:** `cancelRoomBookingSettled()` (config/database.php :10301) gets a new optional parameter
`?array $feeChoice = null` = `{quoted:float, override:?float, reason:string}`. Under the row lock:
1. `$q = rh_cancellation_fee_quote($pdo, $b)`.
2. `source = legacy`: today's code, unchanged.
3. `source = policy`: `$retained = $q['amount']` (not the first night). If `$feeChoice['quoted']` is given and
   differs from `$q['amount']` by more than BALANCE_TOLERANCE, refuse: "The cancellation fee changed (now X). Review
   and cancel again." Nothing changes.
4. Override: allowed only with permission `waive_cancellation_fee`, `0 <= override <= $q['amount']`, and a reason
   (required, <= 300 chars). The server never accepts a raise.
5. The refund formula is unchanged: `netPaid - retained - folio`, auto-refunded unless the mode is `void_keep_credit`.
   Credit-note-paid money still goes back to its note first (`rh_refund_credit_note_payments`).
6. Summary and History (`logBookingEvent` 'Cancellation settled') state: policy kind and version, days before
   arrival, tier, % and amount. If overridden, a second event 'Cancellation fee lowered' shows
   old -> new, who and why. `$result` gains `fee_source`, `fee_pct`, `fee_override`.

Idempotency: the status transition (`validateBookingCancellation` under `FOR UPDATE`) makes a second cancel
impossible, and `retained` is written in the same transaction.

**Every cancel caller:**

| Caller | Fee shown + override UI | Fee applied |
|---|---|---|
| admin/bookings.php :1010 (single) and :999 (group) | yes, cancel modal (`cancelBooking` JS ~:5803) | policy |
| admin/booking-details.php :931 (single) and :891 (group) | yes, cancel form | policy |
| `rh_cancel_booking_group` (database.php :10649) | gets one `$feeChoice` with `override_pct` (0..each room's %), applied per room | policy |
| admin/process-checkin.php :403 | shows the fee in its confirm text, no override | policy |
| admin/tentative-bookings.php :126, `cancelTentativeBooking` database.php :7326 | n/a (tentative) | exempt |
| booking-lookup.php :122 (guest) | fee + refund shown before the button; see OWNER-Q1 | policy, no override |
| auto-email-jobs.php :1162 (auto-release unpaid) | n/a | exempt (OWNER-Q2) |

The fee preview comes from `rh_cancellation_fee_quote`, rendered server-side on booking-details. The bookings.php
list modal gets it through a read-only GET `action=cancel_quote` (bookings permission, JSON: fee, pct, tier label,
paid, refund). The modal posts back `fee_quoted`.

**Lower or waive after cancellation:** booking-details, cancelled booking with retained > 0, permission
`waive_cancellation_fee`. New retained = any amount from 0 up to the current retained, plus a required reason.
Under the row lock: update retained, `recalculateBookingFinancials`, History 'Cancellation fee lowered'. The
difference becomes `credit_balance` (owed back). Staff refund it or issue a credit note through the normal screens
(`refund_payment`); there is no new refund code. This works for legacy-kept first nights too.

**No-show:** in `rh_mark_booking_no_show` (booking-functions.php ~:816) the group-charge slot becomes:
snapshot present -> `rh_policy_noshow_fee_apply($pdo, $id, $userId, $userName)`; else today's
`rh_group_noshow_fee_apply`. Snapshot bookings NEVER call `rh_group_noshow_fee_apply`. If they did, its
'none' mode would plant a voided zero row with the same tag first, and the policy fee would then never post.
`rh_policy_noshow_fee_apply` reuses the body of `rh_group_noshow_fee_apply` (same tag, savepoint, never throws,
History 'No-show charge posted'). At 100% it posts NOTHING: the booked stay keeps billing as today, so revenue stays
in Rooms (a tagged row is charge_type 'other' = "guest-bill extras" in eod-revenue.php :148-156). Below 100% it posts
the tagged row (a VOIDED zero row at 0%). `createNoShowRefund` gets a snapshot branch before its `first_night` /
`full` branches: snapshot + no tagged row (100%) -> refund `paid - base - folio` (none when the rule is `none`), so a
100% policy never falls into the first-night refund. Amount = `noshow_pct` x base; VAT = `rh_room_night_share($b, $amount)['vat']` (levy share already derived by
`rh_noshow_fee_levy`); description "No-show charge - N% of the stay (policy v7)".

Recommended: extract the shared insert/recalc/log into one private helper used by both functions; keep the
public signatures.

**Consistency (no code change expected; verify):** `getBookingFolioSummary` / `recalculateBookingFinancials`
already bill cancelled = retained and tagged no-show = folio only; Still owed `rh_receivable_status_sql`
(receivables.php :35) includes both; badge `rh_cancelled_fee_owed_label`; POS shift pos-shift-totals.php :1319;
EOD eod-revenue.php :133 (retained on the check-in date); reminders as shipped for kept fees (e291682).

## 5. Fine print

**One generator:** `rh_cancel_policy_text()`. A booking's documents print the STORED `snapshot.text`, never a
regenerated version, so a later wording change in code never rewrites old bookings. Public pages and the confirm
steps (no booking yet) call the generator on the current settings. Off, or no snapshot: '' everywhere, so the
documents are unchanged.

The wording is the rule itself, e.g. "Cancellation policy (individual bookings). Cancelled less than 7 days before
arrival: 50% of the stay is charged. Cancelled less than 2 days before arrival: 100%. Cancelled 7 or more days
before arrival: no charge.
No-show: 100% of the stay. Percentages are of the whole stay including VAT and tourism levy; extras already
used stay payable." Then `cancellation_policy_note`. Plain text; escape it at output.

Accessors: `rh_booking_policy_text(array $b)` (snapshot text or '') and `rh_current_policy_text(string $kind)`.

| Surface | File / line | Source |
|---|---|---|
| Invoice PDF terms (room) | config/invoice.php `rh_inv_bank_terms_html` :524 (section "Cancellation policy" after Payment terms :545) | booking |
| Invoice HTML template | config/invoice.php `rh_inv_legacy_room_template` :1680, new token `{{cancellation_terms}}` next to `{{invoice_terms}}` :2007 | booking |
| Quotation PDF | config/invoice.php `rh_inv_quotation_html` :661 (:700) and includes/quotation-pdf.php `quotationPdfClosingNotes` :49 | booking |
| Payment receipt PDF (room) | config/receipts.php `receipt_render_payment_pdf_html` :1023 (:1178), only booking_type room | booking |
| Booking emails | config/email.php `buildBookingEmailVariables` :2249, new tag `{{cancellation_policy_text}}`. The DB templates are NOT edited (live data): when a rendered template lacks the tag and the text is non-empty, append the block. Covers booking_received :2555, booking_confirmed :2656 (fallback HTML :2712), tentative_booking_created :4582, tentative_booking_converted :4878 (:4940), tentative_quotation :6486, booking_cancelled :4044 (adds "Cancellation fee charged: X") | booking |
| Check-in reminder | templates/emails/checkin-reminder.php :261 | booking |
| Booking lookup | booking-lookup.php details block (~:291-340) + cancel section :361 (fee quote) | booking |
| Booking API | api/booking-details.php :152 `cancellation_policy` = booking text, else the setting | booking |
| Public policy modal | includes/footer.php: new tag `{{cancellation_terms}}` in `footer_parse_tags` :37; for slug `cancellation-policy` (:240-258 and fallback :270) the current individual + group text goes FIRST, then the hotel's own page text | current |
| Public booking summary | booking.php summary :1528 (policies loaded :1102): JS switches between the individual and group text by rooms chosen | current |
| Admin create-booking review step | admin/create-booking.php step bar :2198, review step | current |
| Booking details (admin) | admin/booking-details.php: "Cancellation policy (locked at booking, v7)" panel | booking |
| FAQ | faq.php :111: link to the policy modal | current |

The receipt and the invoice for a group print the lead's snapshot text once (all rooms share a kind).

## 6. Hotel Settings UI

- Page: admin/booking-settings.php, section `#cancellation` ("Refunds & cancellations", :1849).
- Placement: a new card ABOVE the current mode form, with its own form and its own POST flag
  `save_cancellation_policy`, plus a separate flag `save_no_show_refund_policy`. Each is saved only when its
  values changed, following the `group_noshow_charge` pattern :251-277.
- Permission: `finance_settings` (administrators). Everyone else sees it read-only with the lock note already used.

Contents:
- On/Off switch "Use cancellation policies for new bookings"; blocks "Individual" and "Group (N rooms or more)"
  with the N input; tier rows "Cancelled [ 7 ] days or fewer before arrival -> [ 50 ] % of the stay" with
  add/remove (max 5); "No-show -> [ 100 ] %"; optional note.
- Live preview of the generated text. Same wording as the server: render it server-side on load, and update it with
  a small JS mirror of the generator; the saved text is always the server's.
- No-show refund rule (3 radios), labelled for both cases: "None - keep the money (for bookings under a policy:
  money paid above the no-show charge stays as credit owed back)" and "First night / Full - for bookings under a
  policy: refund what was paid above the no-show charge".
- Help line "Applies to bookings made after you save. Each booking keeps the policy it was made under."; every
  field gets a plain helper (owner rule); `rh_log_event` 'Cancellation policy changed' (from -> to, new version).

New permission `waive_cancellation_fee` in admin/includes/permissions.php: label "Lower or waive cancellation fees",
category Finance, not a page. OWNER DECIDED 2026-11-14: on for administrators and managers by default, AND grantable
to any other role or individual user through the normal permissions screen ("anyone else with permission").

## 7. Files, tests, ship, rollout

**Files:** new includes/cancellation-policy.php, admin/migrations/085_booking_cancellation_policy.php, scripts/tests/cancellation_policy.php. Edit config/database.php (`cancelRoomBookingSettled`, `rh_cancel_booking_group`, no-show helper extract), includes/booking-functions.php, admin/bookings.php, admin/booking-details.php, admin/booking-settings.php, admin/includes/permissions.php, admin/process-checkin.php, booking.php, includes/mixed-booking.php, admin/create-booking.php, api/bookings.php, booking-lookup.php, config/email.php, config/invoice.php, config/receipts.php, includes/quotation-pdf.php, includes/footer.php, api/booking-details.php, templates/emails/checkin-reminder.php, faq.php.

**Tests (Liwonde only, rolled back):**
1. Pure unit tests: tier pick at days 10/7/5/2/0/-1; pct 0 and 100; rounding; generator text; validation
   rejects; days-before across midnight in `RH_TIMEZONE`.
2. **Off = zero diff (gate).** A golden script at the pre-change commit and again after: for every live Liwonde
   booking, inside a rolled-back transaction, cancel, then (separately) no-show. Dump id, retained, refund_total,
   credit_balance, amount_due, payment_status, tagged row amount. The diff must be empty for each of the 3 cancel
   modes x 3 no-show rules (setting changed in-transaction only).
3. Per-tier rehearsals: paid in full / part-paid / unpaid; each tier and outside all tiers; each refund mode;
   expected retained, refund, credit, Still owed and VAT/levy split via `rh_room_night_share`.
4. Groups: all rooms stamped at creation; cancel 1 of 3; group cancel with override_pct; group no-show with
   `group_noshow_charge` On: the switch never posts; at most one tagged row (the policy's, below 100%).
5. No-show: 100% with `first_night` rule (no row, no first-night refund), 50% with `none` (credit owed back), 0% (voided row).
6. Override refused without permission, above the fee, without a reason, or on a stale quote; lowering after
   cancel raises credit_balance and writes History rows.
7. Pre-migration: the code runs with the column absent (simulate by name guard), no warnings.
8. Existing suites: group_noshow_charge, kept_fees, group_lead_cancel, receivables_noshow, finance_refunds,
   group_booking, group_charge. Then the FULL suite at Liwonde (money/migration tier).

**Browser checks** (desktop 1400 + phone 390, `scripts/tests/browser_check.mjs`, Liwonde): settings card (empty,
filled, read-only); booking-details cancel form with fee and override; bookings list cancel modal; booking-lookup
cancel section; public policy modal; booking.php summary; create-booking review step; invoice, receipt and
quotation PDFs open.

**Ship gate:** money + migration = full suite at Liwonde, accounting-lead review of the diff, port to Liwonde,
one commit.

**Rosalyn's rollout:** 1. Ship the code (inert at Rosalyn's: no column, editor hidden). 2. Owner approves and
runs migration 085. 3. Owner checks that the hand-written Cancellation Policy page text (policies table) does not contradict the
   tiers (Liwonde's says "48 hours ... one night"). It is the owner's edit; we never change it.
4. Owner enters the tiers and switches the policy On. Only bookings made after that carry it.

## 8. Leads to inform and OWNER-Q

- dept-rooms-lead: cancel and no-show flows, group stamping, process-checkin.
- dept-guest-site-lead: footer modal, booking.php summary, booking-lookup, guest emails.
- dept-eod-lead: the kept fee and the no-show % flow through the existing retained and tagged-row lines. Lowering
  a fee after cancellation changes a PAST day's EOD figure (retained is counted on the check-in date, eod-revenue.php
  :133). Decide whether the EOD shows it as an adjustment. A below-100% no-show fee is a tagged 'other' folio row
  (EOD "guest-bill extras", as the shipped group switch); confirm or map it to Rooms.
- ui-lead: settings tier editor, override field, fee preview (UI_REQUEST).
- LAST, separate session:
  - dept-ai-lead: includes/ai/ai-money.php :74-80 must read the snapshot and `rh_cancellation_fee_quote`, not only
    `no_show_refund_policy`.
  - guides-kb-lead: settings, cancel and waive guide pages, plus the new email tag.

**OWNER-Q (build proceeds on the stated assumption):**
1. DECIDED 2026-11-14 (owner: "yes"): a guest may cancel online inside a fee window, with the fee and refund shown
   and an "I accept the X fee" tick. `cancellation_notice_days` keeps blocking inside its own window
   either way.
2. DECIDED 2026-11-14 (owner: "no"): the automatic release of an unpaid confirmed booking charges no fee.
3. DECIDED 2026-11-14 (owner: "no"): pending (not yet confirmed) snapshot bookings are exempt like tentative holds;
   fee 0, paid money refunded per the refund mode.
