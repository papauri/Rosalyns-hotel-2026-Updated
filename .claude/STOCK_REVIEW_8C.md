# Step 8c stock review - fix plan (2026-11-15)

Source: the three part 1 audits in `.claude/TASKS.md` (lines "8c PART 1 POS SIDE", "STOCK CORE", "STOCK IMPORT").
Rule: no new features; quantity/cost faults first; everything not listed under a build is LATER for the owner.
ROSALYN'S IS LIVE: every test runs from `C:\Users\john-paul.chirwa\OneDrive\MSP\Liwonde_Sun_Hotel_2026` only.


## Build A - POS callers (dept-restaurant-lead, Opus) — REVISED after Fable spec check (GO WITH CHANGES)

- **ONE GATE (replaces every "fallback" rule):** a `pos_order` restore runs only when the line has a deduction record:
  EXISTS `stock_adjustments` source_type='pos_order' AND source_id = line id AND quantity_change < 0 (the EXISTS already
  used at `admin/includes/restaurant-payment-sync.php:641-642/:666/:683/:767/:781`). Apply at `api/kds-action.php:1194-1196`
  and `:1589-1600`, `admin/includes/pos-order-close.php:447-448` and `:453-454`. `room_service` restores keep the
  `stock_tracked=1` gate and keep the recipe fallback (flag set only after a successful deduction, `config/database.php:9383-9386`).
- **P1** `admin/includes/pos-order-delete.php:438-439` - order status voided / cancelled / refunded -> delete NEVER
  deducts (a void-time wastage deduction is keyed 'wastage' + wastage id, not the line: restaurant-payment-sync.php:439/:448,
  and the void clears the flag :695). For live orders, decide per line from the trail (`rh_pos_del_put_back_trail`
  counting); ZERO deductions does not count as "all restored": a started, never-deducted line of a live order still
  takes its wastage.
- **P2** `api/kds-action.php:1586-1610` recall_ticket - recall of a charge-tracked (room-service) line moves NO stock
  (recall does not void the charge; later paths assume its deduction stands, kds-action.php:1203-1204,
  restaurant-payment-sync.php:888-890). pos_order lines restore only under the ONE GATE. At `:1608/:1610` clear
  `stock_deducted` only on lines whose restore was actually written (a cleared flag makes the re-bump deduct again,
  :1511-1527).
- **P3** `config/database.php:9507-9522` voidBookingCharge - UNSERVED linked line: after the stock call (it reads the
  line status, restaurant-payment-sync.php:888) set kds_status='void' + stock_deducted=0 (as pos-order-close.php:367),
  for tracked and untracked charges, same line pick as restaurant-payment-sync.php:866-871, then recompute
  kitchen_status as pos-order-close.php:490-501. SERVED line: change NOTHING (do not clear the flag: flag 0 on a served
  line means "not taken yet", and a later void/delete would take a fresh wastage deduction). Leave stock_orders totals
  alone (folio total comes from charges, database.php:9540).
- **P4** NO flag change at pos.php:240/:719 / room-service-dashboard.php:269 (setting it only when tracked would make
  the KDS deduct those lines at ready/bump, kds-action.php:1511-1536). The ONE GATE already stops the phantom credit.
  Test: "untracked charge -> at most one pos_order deduction and one restore".
- **P5** `admin/includes/finance-account-sync.php:997-1001` - SAVEPOINT before `rh_close_refunded_order_lines`; in the
  catch: if `rh_is_lock_conflict($e)` RETHROW (the DB already rolled back the whole transaction; same rule as
  database.php:12403), else ROLLBACK TO SAVEPOINT and log with `rh_log_event`. Check the parallel 8d session's edits to
  this file first (git diff) and build on them.
- **P6** do NOT edit the shared status list at `api/kds-action.php:170` (it also guards :1369, :1489, :1507; refunded
  room-service orders keep live lines). In recall only, refuse when the status returned at `:1563` is 'refunded'.
- **P7** (pulled in: quantity-wrong) `admin/includes/restaurant-payment-sync.php:866-873` (called in the loop at
  pos-order-close.php:141-143) and `api/kds-action.php:1176` - each charge follows its OWN kitchen line: the used-set
  pattern of pos-order-close.php:333-349, so a served twin is wasted and an unserved twin restored.

## Build B - stock core (dept-stock-lead, Opus)

- **S1** `config/database.php:12797-12808` + `:12918` restoreStockForMenuItem - when the deduction was partly
  unallocated, put back an R-batch (batch row ONLY: no adjustment, no stock_in_log, no average-cost change) of
  quantity = min(remainder, current after restore - active batch total) at the UNTRACED part's own cost =
  (qty x cost_at_time - SUM traced qty x batch cost) / remainder (NOT the blended cost_at_time, :12594-12599; e.g.
  6@100 + 4 untraced@150 -> cost_at_time 120, R-batch at 150); if that cost is 0 use the ingredient's cost_per_unit.
  Same treatment for the fallback leftover at :12856-12872.
- **S2** archived items cannot move stock: `admin/stock-ingredients.php:168-184` archive refused while the item is in
  a recipe (plain reason naming the menu item); `admin/stock-recipes.php:107-133` save refuses archived ingredients;
  `config/database.php:13614` rhLookupBarcode does NOT filter (it serves every scope); it RETURNS is_archived and the
  receive screen `admin/stock-barcode-receive.php:223` refuses an archived item with a plain reason. Till unchanged. Existing archived-in-recipe rows: list only (no data change), message on the recipe page.
- **S3** `admin/stock-recipes.php:329-333` - wording only: the tooltip must say what the engine does (quantities are
  per ONE portion sold; the yield field is for reference). No behaviour change (dividing = owner call, LATER).
- **S8** `admin/stock-ingredients.php:513-516` discard_batch - same status/remaining guard as `admin/stock-batches.php:95`.
- **S9** `admin/stock-wastage.php:126-135` - refuse backdated wastage when an APPROVED count of the same item has a
  count date strictly AFTER the wastage date (wastage date < count date) (plain reason: "this loss is already in the count of <date>").
- **S12** `admin/purchase-orders.php:530-652` receive and `admin/stock-ingredients.php:227` stock_in - the same
  session fingerprint double-submit guard as `admin/stock-wastage.php:70` / `admin/stock-barcode-receive.php:175`.

## Build C - stock import (dept-import-lead, Opus)

- **I1** `includes/import/commit-stock.php:112` - `GET_LOCK('rh_import_commit', 10)` BEFORE beginTransaction
  (pattern `admin/stock-count.php:792-798`), RELEASE_LOCK in finally; re-select the job inside the lock (also fixes I7 part 2).
- **I2** `admin/includes/stock-sheet-import-lib.php:657-659`, `:729` - a "create" row whose name now exists:
  throw "matches existing <name>, review again", whole commit rolled back.
- **I3** `stock-sheet-import-lib.php:673-677` - re-read matched rows FOR UPDATE in apply; SET only the ticked columns;
  `system_quantity` taken from the locked row.
- **I4** `includes/import/resolve.php:761`, `:843-848` - a ticked quantity of 0 is counted (shortage line, reason
  'correction'), never silently dropped.
- **I5** `includes/import/review.php:747` - Cancel: `AND status NOT IN ('committed','cancelled')` + rowCount check,
  plain message when nothing was cancelled.

## LATER (owner / accounting lead; not built in 8c)

P8 86 cost + serve-path logging · S4 stock valuation method
(accounting lead decision: weighted average vs FIFO batch value) · S5 one cost-of-sales expression on all report tabs ·
S6 count approval keeps FIFO cost · S7 count shortage > live stock: report vs ledger · S10 restore pairing by qty
(latent) · S11 late-costing restore_of_type · S13 product-mode recipe re-key on category move · S14 CSV export value
rule · S15 report date filters + count page N+1 · S16 drop duplicate barcode indexes (migration, owner OK at
Rosalyn's) · I6 same-file re-check at commit · I7 part 1 answer/fields row lock · owner: FEFO vs strict FIFO for
no-expiry batches · owner: "Recipe yields N" divides quantities? · owner: shop-mode delete -> shelf vs wastage ·
owner: unique index on stock_ingredients.name · Payments-screen refunds can't be marked damaged in shop mode ·
line-level partial refund/return moves no stock · stock_clear_test "administrator is allowed" predates super-admin
tiers (test-only) · pos_kds_stock_accounting needs an empty Liwonde (now a mirror).

## Tests (each build adds its cases to its own suite, run from the Liwonde root)

A: shop_returns.php, refund_vat_pos.php + a new `scripts/tests/stock_pos_restore_8c.php` (sha1 Liwonde guard as in
pos_kds_stock_accounting.php:29, rolled back) for P1-P6. B: stock_expiry_fifo.php (S1), stock_loss.php (S9),
stock_receiving.php (S12), stock_list_filters.php / stock_packs.php as touched + S2/S8 cases. C: import_stock_test.php,
stock_sheet_import.php. Baseline before any fix is on the 8c DOING line in TASKS.md.

## How the builds run (orchestrator)

- **Red before green**: every finding is from reading code (Liwonde has no POS history). Each builder first writes its
  rolled-back case at Liwonde, runs it against the CURRENT code and sees it FAIL; then fixes and sees it pass. A case
  that cannot be made to fail = the fault is not real -> LATER/dropped, not "fixed". The new POS test builds its own
  ingredients, batches, recipe and order (Liwonde has no till data).
- **Order**: A and C in parallel (no shared files); B after A (both edit config/database.php and the restore path).
- **Old rows**: superseded by the ONE GATE in Build A (Fable spec check).
- **S2 lookup**: superseded by S2 in Build B (rhLookupBarcode returns is_archived; receive screen refuses).
- **Gates**: dept-accounting-lead reviews the money/cost-of-sales parts (S1, P2/P4 restores, I3) inside the one
  qa-auditor (opus) round; full `run_tests.php` from the Liwonde root (config/database.php changes); Fable 5.2 final
  review of the quantity/cost diff after the opus review passes; browser check 390 / 768 / 1400 at Liwonde of every
  screen touched; ship with `php scripts/ship.php --tests=none` (ship.php would run suites at Rosalyn's).
- **Ship order if short**: first commit P1-P4, S1, I1, I2 (quantity-wrong); second commit S2, S3, S8, S9, S12, P5,
  P6, I3-I5; whatever does not fit -> LATER.

**OWNER 2026-11-15:** "rosalyns is live but has not adopted any POS sale yet so you can proceed to test on liwonde and
port to rosalyn". So no legacy POS orders exist at Rosalyn's: the old-row concern for P1/P2/P4 applies only to
room-service folio charges, if any. Tests at Liwonde, port to Rosalyn's as usual.

**OWNER 2026-11-15 (import test file):** "use this stock count just the import test count. Remember it can come in any
sort of format". File: a 328-row stock-management CSV export (Name, Category, Unit, Current Qty, Min Qty, Reorder Point,
Par Level, Lead Time, Cost/Unit, Stock Value, Next Expiry, Status). Real hotel data - NEVER committed (public repo):
it sits in Liwonde's gitignored `scripts/tests/fixtures/import/real/stock-management-20261110-101650.csv`. Build C uses
it as the import test count at Liwonde (rolled back / cleaned up) plus variants generated in the same ignored folder
(xlsx, renamed/reordered headers, extra title rows, blank lines) so the import is proven on more than one shape.
Parallel session: step 8d (refunds on payout day) is building in the same tree - stage explicit paths only; P5
(finance-account-sync.php) must check for 8d edits first.

**Fable: case a (2026-11-15) GO WITH CHANGES** - applied above (ONE GATE, P1 status rule, P2 no stock for
charge lines, P3 unserved-only close, P4 no flag change, P5 lock-conflict rethrow, P6 recall-only, P7 pulled in, S1 untraced
cost, S2 no filter in rhLookupBarcode, S9 strict date). I4 confirmed a fault fix (explicit ticked 0; blank = null).

**OWNER 2026-11-15 (usage):** "When you reach 99% of this session 5hr usage automatically switch to fable ... not at high but equivalent of opus". Rule for the rest of 8c: if an Opus agent or the session hits a usage-limit error, re-dispatch that same work with `model: fable`, effort medium (not high). Logged as an owner exception to the Fable 3-case rule, this step only.
Cheapest way to switch (orchestrator advice to owner 2026-11-15): do NOT switch this long session to Fable (the whole history would be re-read by Fable, uncached). Instead /clear, /model Fable, then "Run the next step of .claude/NEXT_BUILD_ORDER.md": the state is in this file + the 8c lines in TASKS.md + the uncommitted Liwonde tree (builds A, B, C).
