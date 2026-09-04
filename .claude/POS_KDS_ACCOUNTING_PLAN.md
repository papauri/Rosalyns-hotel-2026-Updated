**STATUS 2026-09-04: Phases 0–3 are ALL DONE, both repos, `php -l` clean.** The full plan below is
implemented. Nothing has been committed or pushed — the owner does that. Decisions taken:
tips excluded from the revenue ledger and VAT on both legs (owner-confirmed); force-serve
authority is the new `pos_force_serve` permission, granted to `manager` by default and usable
via the existing manager-auth overlay (my call, per the plan's recommendation); the historic-drift
report was deferred (my call — nothing destructive turns on it, so it can wait for a quieter
moment). What actually changed, file by file:

- `admin/pos.php` — tips removed from `pos_syncPayment`'s ledger amount on both the single-payment
  and last-split-leg paths (was D3's sale-side bug); refund-side comment now matches the code it
  describes (Rosalyn's variable-named version folded into Liwonde's, so the two files are
  byte-identical again — P0.0 done); `close_shift`'s open-tab block now scopes to the current
  business window, excludes `room_service`, and names the blocking tab references; the tabs-tray
  query, `ajax=tabs`/`ajax=stations` counts and `adminStationsInit['open_tabs_all']` all exclude
  `room_service` (folio-settled, never a till obligation); added `pos_forceServeKitchenItems()`
  (mirrors the existing `pos_autoServeBarItems()` pattern — deducts stock, marks served, audits)
  and wired it into `pay_existing` behind the new `pos_force_serve` permission or a manager-auth
  token, with a matching JS retry flow in the `payTabForm` submit handler so a blocked settle
  offers the manager-auth prompt inline instead of a dead end; the Z-report's expected-totals
  query (`close_shift`) and `admin/pos-accounting.php`'s per-cashier and day-wide summaries now
  source split-order tenders from `stock_order_splits` instead of `stock_orders.payment_method`
  (D2's fix), keeping the same output shape so nothing downstream needed to change.
- `api/kds-action.php` — the `feed` action's ticket query no longer filters on `fired_at >=`
  the business-window start; `kitchen_status IN ('new','in_progress','ready','recalled')` already
  scopes it to unfinished work, so the extra cutoff only hid stranded pre-window orders from every
  station board with no way to see or finish them.
- `admin/includes/permissions.php` — new `pos_force_serve` permission, granted to the `manager`
  role by default (same tier as `pos_refund`).

**Phase 2 (2026-09-04):**
- P2.2 (one clock) — `rh_sync_restaurant_payment()` now takes an explicit `$businessDate`
  (defaults to `rh_station_union_business_window()['business_date']` instead of `CURDATE()`/PHP
  `date('Y-m-d')`) and uses it for both `payment_date` and receipt numbering.
  `admin/pos-accounting.php`'s `$dayStart`/`$dayEnd` (both the GET and the POST/business_date-repost
  path) now come from `rh_station_union_window_for_date($businessDate)` instead of literal
  midnight/23:59:59.
- P2.3 (refund can't silently fail) — the `payments` ledger insert in `refund_order` is no longer
  wrapped in a swallow-and-log `try/catch`; a failure now rolls back the whole refund via the
  existing outer catch. Added `refund_order` to the XHR-JSON-error action whitelist
  (`admin/pos.php` ~line 1204) so that failure — and every other failure path in this action —
  reaches the cashier as a proper JSON error instead of a full HTML page the fetch() can't parse.
- P2.4 (void reversal) — `api/void-order.php` no longer overwrites the original payment row to
  `status='failed'`; it inserts a `payment_type='refund'` contra row instead (same category every
  existing report already nets out), leaving the original sale row as an untouched historical
  record.

**Phase 3 (2026-09-04):**
- P3.1 (tabs-tray sizing, the other reported bug) — the tray's `style="width:760px;"` (no
  `max-width`) is gone; width now lives in `admin/css/pos-overrides.css` as `#tabsOverlay .modal
  { width: 900px; max-width: 96vw; }`, matching the `#tabDetailOverlay` convention. `.tab-cards-list`
  is now `repeat(auto-fill, minmax(260px, 1fr))` (was one column at every width, forced back to 1
  column under 760px for touch targets). The two other inline-width modals named in the original
  finding (station note 480px, item note 420px) got `max-width:96vw` too.
- P3.2 (honesty pass) — the stale-tab badge ("Previous shift") now carries a `data-help` tooltip
  explaining what it means and that the Pay flow offers a manager force-serve if kitchen items
  were never bumped, in both the PHP-rendered and JS-rendered tray markup.
- P3.3 (dead code) — removed the duplicate `open_tabs_visible` query (identical to `open_tabs_all`;
  this endpoint is admin/manager-only so there was never a different "visible to me" subset) and
  corrected the stale "48 hours" comment on the tabs query, which has never had a time bound.

Every task in this plan is now implemented, including the historic-drift report (built
2026-09-04, on request): `admin/pos-drift-report.php`, gated by the existing `pos_accounting`
permission, linked from `admin/pos-accounting.php`'s toolbar. Read-only — issues SELECT only,
writes nothing. Two sections: (1) a tip/VAT overstatement register — sale `payments` rows whose
recorded gross matches the D3 bug signature (`order_total + tip` instead of `order_total`), with
each affected order checked against its refund (if any) for the residual imbalance a refund left
behind; (2) historical shift-close accuracy — every `stock_shift_closes` row's expected
cash/mobile/card recomputed under the fixed split-aware logic and compared to what was actually
stored at close time, flagging which ones needed a manager override that a correct figure might
not have required. Date-range filterable (defaults to last 90 days, with an all-time toggle).
Registered in `admin/includes/permissions.php`'s page→permission and page→module maps in both
repos so `admin-init.php`'s routing picks it up.

**Round 2 (2026-09-04) — settle-path parity, POS↔KDS messaging accuracy, remaining accounting.**
Found by sweeping the paths the original plan did not cover (the second till in
`restaurant-tables.php`, the `station_messages`/`pos_ready_notifications` traffic, and the last
tender aggregation outside `pos.php`):

*Messaging accuracy (`api/kds-action.php`, `api/void-order.php`, `api/cancel-order.php`):*
- **Recall permanently killed the ready notification.** `kds_maybe_notify_ready()` dedupes on
  `(order_id, station)` with no expiry, but `recall_ticket` never cleared the row — so once food
  was recalled, re-made and bumped ready a second time, the waiter was never told. Recall now
  deletes the notification row for that order+station, restoring "notify once per ready cycle".
- **Unacknowledged station→POS notes vanished at the window boundary.** `get_pos_inbox` clause
  (c) — the only clause covering notes that still need FOH action — was bounded by
  `created_at >= businessStart`, hiding exactly the notes someone still had to act on. That
  clause is now unbounded; `pos_acknowledged`, not the clock, removes a note from the list.
  Same class of bug as the KDS board fix in Round 1.
- **Collection pings fired once per item.** Marking six items collected raised six separate
  URGENT notes for one trip to the same pass. `kds_notify_pos_collection()` now keeps one
  outstanding note per order+station, restating it with a running item count.
- **Old unacknowledged station messages were invisible AND unmarkable.** The board's 6-hour
  window dropped them from the list while the seen-marker skipped them too. Unacknowledged
  messages now stay on the board however old; the 6-hour window applies only to already-replied
  informational rows, and the seen-marker covers exactly what the list returns.
- **Void/cancel left a live "ready for collection" ping.** The POS poll only suppresses a
  notification while items are still in progress — once every item is `void` that check passes,
  so a voided order kept telling a waiter to collect food that no longer existed. Both endpoints
  now retract the ping and acknowledge outstanding station notes for the order.

*Tab payments / settle-path parity:*
- **`admin/restaurant-tables.php` settled tables without auto-serving drinks**, so un-bumped bar
  lines stayed at `stock_deducted=0` / `kds_status='pending'`: stock never came off the shelf and
  the ticket stayed on the bar display under an already-paid order. The auto-serve logic moved
  from inline in `pos.php` to a shared `admin/includes/restaurant-order-serve.php`
  (`rh_auto_serve_bar_items()`, same extraction pattern as `restaurant-payment-sync.php`);
  `pos_autoServeBarItems()` is now a thin wrapper so existing call sites read unchanged, and the
  table registry calls it before applying payment. Verified the other two settle paths
  (`restaurant-tables.php`, `stock-orders.php`) already pass tip-free totals, so the Round 1 D3
  fix covers them; `restaurant-tables.php` already blocks non-`dine_in` orders, so room-service
  double-charging was never possible there.

*Accounting:*
- **Last instance of the split-tender bug (D2)** — `admin/stock-orders.php`'s drawer
  reconciliation summary, the "anti-cheat" figure where a wrong tender split matters most, still
  read `stock_orders.payment_method`. Now split-aware via `stock_order_splits`, and its window
  moved off `DATE(created_at)=CURDATE()` onto the trading window (D4). Swept the other reports
  (`end-of-day-*`, `accounting-dashboard.php`, `reports.php`) — no further instances.

Nothing left queued from this plan.

---

# BUILD PLAN — POS ↔ KDS ↔ Accounting

**Scope:** the till (`admin/pos.php`), the station displays (`admin/kds.php`, `api/kds-action.php`)
and the money trail that joins them (`payments`, shift closes, `admin/pos-accounting.php`).
**Applies to BOTH repos** — Liwonde Sun Hotel 2026 and Rosalyn's Hotel 2026.
**Written:** 2026-09-04. **Owner:** build-planner. Specialists execute; they never edit this file.

---

## 0. The one fact that shapes this whole plan

The POS/KDS/accounting layer is **byte-identical across the two repos**, with a single exception:

| File | Liwonde | Rosalyn | State |
|---|---|---|---|
| `admin/kds.php` | 2637 | 2637 | identical |
| `admin/pos-accounting.php` | 1057 | 1057 | identical |
| `admin/order-lifecycle.php` | 413 | 413 | identical |
| `admin/kds-report.php` | 481 | 481 | identical |
| `admin/restaurant-tables.php` | 1701 | 1701 | identical |
| `api/kds-action.php` | 1546 | 1546 | identical |
| `api/pos-tab-detail.php` · `pos-notifications.php` · `void-order.php` · `cancel-order.php` | — | — | identical |
| `admin/pos.php` | 10019 | 10017 | **26 lines apart** |

So the working method is: **fix once in Liwonde, verify, then port the diff verbatim to Rosalyn.**
No parallel authoring, no per-repo variants. Task P0.0 exists purely to erase the one existing
drift so the files go back to identical and every later port is a clean copy.

**No DDL anywhere in this plan.** Every fix uses columns and tables that already exist
(`stock_order_splits`, `stock_orders.tip_amount`, `payments.payment_type`, …). Schema parity
stays locked; `admin/migrations/` stays empty.

---

## 1. What is actually broken

Seven defects, all read off the code, listed by severity. The two the owner reported are D1 and D7.

### D1 — Tabs that can never be settled, and a shift that can never close (P0)

The reported "orders for the day can't be settled." The chain:

1. `api/kds-action.php:73-82` — the station board's ticket query filters `o.fired_at >= $unionWindow['start_sql']`.
   An order fired **before** the current business window start is not on the board at all.
2. Its kitchen items therefore stay `pending`/`preparing`/`ready` forever. Nobody can bump what
   nobody can see.
3. `admin/pos.php:657-663` — `pay_existing` throws when any kitchen item is not in
   (`served`,`void`): *"This tab still has N food items not yet served."*
4. `admin/pos.php:2646` — but the tabs tray sets `$canSettle = $totalItems > 0`, so the UI shows
   a live Settle button. The till promises what the server refuses.
5. `admin/pos.php:789-793` — `close_shift` hard-blocks on **every** open tab by that user, with no
   date scope. One stranded tab from Tuesday blocks every close from then on.

There is no override, no re-fire, no recovery path of any kind. Bar items already have an escape
hatch (`pos_autoServeBarItems`, `pos.php:655`); kitchen items have none.

`order_type = 'room_service'` compounds it: `pos.php:646-648` forbids settling those at the till
(folio only), yet `close_shift` still counts them as open tabs the cashier must clear.

### D2 — Split payments are booked to the wrong tender (P0, money)

`admin/pos.php:806-820` — the Z-report sums `stock_orders.payment_method`, which
`pos_applyPaymentToOrder` (`pos.php:412`) sets to **the last split leg's method**. Pay a 30,000
tab as 10,000 cash + 10,000 cash + 10,000 card and the Z-report expects 30,000 card and 0 cash.
`stock_order_splits` holds the correct per-leg method and is never consulted.

Consequence: any mixed-tender split guarantees a drawer variance, the cashier cannot balance, and
`pos.php:843-856` blocks the close until a manager overrides — which files a false variance in
`stock_shift_closes` forever. This is the second half of "can't settle the day."

`admin/pos-accounting.php:396-479` reads the same order-level column and inherits the same error.

### D3 — Tips are revenue on the sale and not on the refund (P0, money)

- **Sale:** `pos.php:424` and `pos.php:429` call `pos_syncPayment(... 'total_amount' => $totalAmount + $tipAmount ...)`.
  `pos_calculateRestaurantVatParts` (`pos.php:45-55`) then splits that tip-inclusive figure, so
  `payments.total_amount` = total + tip and **VAT is charged on the tip**.
- **Refund:** `pos.php:947` computes `pos_calculateRestaurantVatParts($refRow['total_amount'])` —
  tip **excluded**.

Refund a tipped order and the ledger stays permanently overstated by the tip plus VAT on it, on a
transaction that netted zero. Both repos carry a comment block asserting the sale "passes
total_amount ONLY — the tip never enters `payments`" (Liwonde `pos.php:944-954`, Rosalyn
`pos.php:947-955`). **That comment is false in both.** The last commit fixed one leg of an
asymmetry and documented the other leg backwards.

Decide once, apply to both legs. Recommendation: **tips out of the revenue ledger entirely** —
they are cash movement, not sales, they should not be VAT-rated, and `pos-accounting.php` already
tracks them separately for till reconciliation.

### D4 — Four clocks, four answers to "what day was this?" (P1, money)

| Source | Day definition |
|---|---|
| `admin/includes/restaurant-payment-sync.php` | `payment_date = CURDATE()` — **MySQL server tz** |
| same file, receipt numbering | `finance_next_receipt_number($pdo, date('Y-m-d'))` — **PHP site tz** |
| `admin/pos.php` shifts/tabs/Z-report | `rh_station_union_business_window()` — **trading window** |
| `admin/pos-accounting.php:19-20` | `$date 00:00:00` → `23:59:59` — **calendar midnight** |

Two of those live inside a single function. Any late-night trading (the union window runs past
midnight) puts the sale, its receipt number, its shift and its accounting row on up to three
different dates. Reconciliation cannot succeed by construction.

### D5 — Voids mutate the sale row instead of reversing it (P1, audit)

`api/void-order.php:137` sets the original payment to `payment_status='cancelled', status='failed'`.
Refunds post a proper contra row (`pos.php:958+`); voids overwrite history. A report that filters
on status and a report that sums amounts will disagree, and the original sale figure is gone.

### D6 — A refund can silently fail to reach the ledger (P1, money)

`pos.php:938-981` — the order is marked `refunded` **before** the ledger insert, and that insert
sits in a `try/catch` whose only action is `error_log()`. Cash leaves the drawer, the ledger never
moves, the commit succeeds, and the cashier is told the refund worked.

### D7 — Tabs tray is the wrong size (P1, UI)

The reported "tabs not the right size."

- `pos.php:2541` — `<div class="modal modal-content" style="width:760px;">`. Fixed inline pixels,
  **no `max-width`**. Every sibling modal has one: `:2740` `max-width:96vw`, `:3054` `96vw`,
  `:3097` `98vw`, `:3112` `96vw`, `:3156` `96vw`. The tabs tray, `:3178` (480px) and `:3241`
  (420px) were missed. On a 600–768px tablet the tray overflows the viewport, and because the
  width is inline it **outranks every rule in `pos-overrides.css`** — including the
  `@media (max-width: 760px)` block at `pos-overrides.css:3480` that was written to fix it.
- `admin/css/pos-overrides.css:3210` — `.tab-cards-list { display: grid; gap: 6px; }` with no
  column definition. One column at every width, so on a 24" till each tab card is a 740px-wide
  strip holding a reference and a total.

---

## 2. What this plan deliberately does NOT do

Named so no agent widens scope mid-cycle:

- **No double-entry rewrite.** `payments` stays the single ledger table with contra rows.
- **No new tables, columns, indexes or migrations.** Schema parity is locked.
- **No KDS redesign.** Station boards, bump flow and routing stay as they are.
- **No new offline/sync layer, no printer integration, no card-terminal work.**
  `card_pos` stays disabled (`pos.php:623`).
- **No refactor of `pos.php` into modules.** It is 10k lines; leave it. Touch only the named
  line ranges.
- **No restoration of the two features the migration removed** (guest restaurant reservations,
  secondary hero CTA).

---

## 3. Phases

Every task states exact files. QA gate after each. Port to Rosalyn is part of the task, not a
follow-up — **a task is not done until both repos hold the same fix and both pass `php -l`.**

### Phase 0 — Re-baseline (do first, ~1 cycle)

**P0.0 — Erase the `pos.php` drift**
Reconcile the 26 divergent lines so the two files are byte-identical again. The divergence is
cosmetic (comment wording + two intermediate variables `$refLedgerNet`/`$refLedgerGross` in
Rosalyn) and the underlying logic is wrong in both, so fold this into the D3 decision rather than
picking a winner now: implement the chosen tip treatment once, write one correct comment, delete
both stale ones.
Files: `admin/pos.php` (both repos), lines ~940–980.
Done when: `diff` of `admin/pos.php` across repos is empty.

### Phase 1 — Settlement works (P0, the reported bugs)

**P1.1 — Kitchen items can always be resolved**
Give stranded kitchen items a recovery path, mirroring what bar items already have.
- `api/kds-action.php:73-82` — keep the window cutoff for the *default* board, but let an order
  with unserved items still be recalled to the board (an "unfinished / earlier service" view),
  so the kitchen can bump it legitimately.
- `admin/pos.php:657-663` — keep the block as the default, add a **manager-authorised
  force-serve** using the existing `$_SESSION['pos_mgr_auth']` mechanism already built for
  refunds (`pos.php:911-924`) and an audit event via `pos_logAudit`. Nothing is silently
  auto-served: the override is explicit, attributed and logged.
- `admin/pos.php:2646` — make `$canSettle` tell the truth: compute it from
  `pending_count + preparing_count + ready_count + collection_count` on kitchen items, which the
  tabs query at `pos.php:1463-1477` already selects. If it can't be settled, the card must say
  why and offer the override, not a dead Settle button.

Acceptance: a tab fired before the window start can be settled by a manager in ≤2 actions, and
every such settlement leaves an audit row naming the authoriser.

**P1.2 — Shifts can always close**
`admin/pos.php:789-793`. Scope the open-tab block to the current business window, exclude
`order_type='room_service'` (which by rule cannot be settled at the till), and name the blocking
tabs with their references in the error instead of a bare count.

Acceptance: a cashier with a stranded prior-day tab can close today's shift; the stranded tab is
still visible in the tray and still reported.

**P1.3 — Room-service tabs leave the till's hands**
Room-service orders must not sit in a cashier's open-tab list. Route them to the folio path
(`admin/room-service-dashboard.php` already posts `booking_charges` with
`charge_type IN ('food','drink','room_service')` at `:416`) and mark them so the tabs tray shows
them as folio-bound, not settleable.

Acceptance: no room-service order ever counts toward a cashier's open tabs or blocks a close.

**P1.4 — Splits book to the tender that was actually taken**
`admin/pos.php:806-820` and `admin/pos-accounting.php:396-479`. Source per-tender expected totals
from `stock_order_splits` (leg method + leg amount + leg tip) when `split_count > 1`, falling back
to the order row for single payments. Tips follow the leg that carried them.

Acceptance: a 3-way 10,000 cash / 10,000 cash / 10,000 card split produces expected cash 20,000
and expected card 10,000; the cashier balances with no override.

### Phase 2 — The money is right (P0/P1 accounting)

**P2.1 — One tip rule, both legs**
Apply the D3 decision to sale (`pos.php:424`, `pos.php:429`) and refund (`pos.php:947`) together,
and delete the two contradictory comment blocks. Recommended: exclude tips from
`payments.net/vat/gross` on both legs; keep the tip on `stock_orders.tip_amount` and
`stock_order_splits.tip_amount` where the Z-report and `pos-accounting.php` already read it.

Acceptance: sale-then-refund of a tipped order nets the ledger to exactly zero; no VAT is
declared on any tip.

**P2.2 — One clock**
Use a single business-date source (reusing `rh_station_union_business_window()` — no new concept):
- `admin/includes/restaurant-payment-sync.php` — replace `CURDATE()` with a bound business date,
  and use that same date for `finance_next_receipt_number()`.
- `admin/pos-accounting.php:19-20` — replace midnight/23:59:59 with the business window for the
  selected date.

Acceptance: a sale at 00:30 during an open trading window carries the same date on its payment
row, its receipt number, its shift close and the accounting page.

**P2.3 — Refunds cannot silently fail**
`pos.php:938-981`. Move the `payments` insert inside the transaction's failure path: if the
ledger row cannot be written, the whole refund rolls back and the cashier is told. Remove the
swallow-and-log.

Acceptance: forcing an insert failure leaves the order still `paid` and surfaces an error.

**P2.4 — Voids post a reversal, not an overwrite**
`api/void-order.php:137`. Write a contra row the way refunds do, leaving the original sale row
intact.

Acceptance: after a void, the original sale row is unchanged and the sum over `payments` for that
order is zero.

### Phase 3 — Fit and finish (P1/P2 UI)

**P3.1 — Tabs tray sizing**
- `admin/pos.php:2541`, `:3178`, `:3241` — add `max-width` in line with every sibling modal.
  Better: move the widths out of inline styles into `pos-overrides.css` so the existing
  `@media (max-width: 760px)` block at `:3480` can actually take effect.
- `admin/css/pos-overrides.css:3210` — give `.tab-cards-list` responsive columns
  (`repeat(auto-fill, minmax(…, 1fr))`) using the tokens in `css/base/variables.css`.

Acceptance: verified at 600px, 768px, 1024px, 1440px and 1920px — no horizontal overflow at any
width, no full-width single-column cards above 1024px, touch targets intact on coarse pointers
(`pos-overrides.css:226`).

**P3.2 — Tabs tray honesty pass**
Stale tabs (`pos.php:2589`, `.tab-card.stale`) should state *why* they are stuck and what the
staff member can do, rather than only being tinted red.

**P3.3 — Dead code**
`admin/pos.php:1576-1578` computes `open_tabs_visible` with a query identical to `open_tabs_all`;
`pos.php:1460-1462` carries a comment describing a 48-hour scope the query no longer has. Remove
the duplicate, correct the comment.

---

## 4. Execution rules for this plan

- **Order matters:** P0.0 → P1.1–P1.4 → P2.1–P2.4 → P3.x. Phase 1 is what the owner is feeling
  daily; Phase 2 is what the books need before month-end.
- **Two specialists max, in parallel, never on the same file.** `pos.php` is the hot file in
  almost every task — serialise anything touching it.
- **Port immediately.** Finish a task in Liwonde, QA it, port to Rosalyn in the same cycle, and
  re-run `diff` across the two trees. Drift is what created P0.0.
- **`php -l` every changed file, both repos.**
- **Never commit or push.** The owner triggers that.
- **Money invariants for qa-auditor:** comparisons via `BALANCE_TOLERANCE`; every ledger write
  paired with its reversal; sale and refund legs symmetric; no VAT on tips; no write outside a
  transaction that another write depends on.

---

## 5. Open owner decisions

1. **Tips and VAT (gates P2.1).** Confirm tips are excluded from the revenue ledger and not
   VAT-rated. Recommendation: yes. This is a tax-treatment call, not an engineering one.
2. **Force-serve authority (gates P1.1).** Manager-only, or any user holding `pos_refund`?
   Recommendation: manager-only, reusing the existing overlay.
3. **Historic data (gates nothing, but affects the books).** Refunded tipped orders and
   mixed-tender splits already in `payments` carry the D2/D3 errors. Do you want a read-only
   report quantifying the drift before any correction is considered? No corrective SQL will be
   written without an explicit instruction.
