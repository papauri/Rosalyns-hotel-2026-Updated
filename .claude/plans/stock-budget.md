# Stock budget (owner request 2026-10-20)

Owner: "In stock management add a functionality to budget for stock. A very simple looking page but
with all the powerful logic and interaction it requires … based on real-world systems … don't forget
permissions and guides."

## What real systems do (and what we copy)

MarketMan, Apicbase, Restaurant365, Toast xtraCHEF and Oracle Simphony Inventory all budget purchasing
the same way — an **open-to-buy** per period and per category:

- **Budget** per month per category (fixed amount) — or a **target cost %** of sales (food cost %),
  so the budget grows with the business. Both modes, chosen per line.
- **Spent** = what was actually received into stock in the period (goods-in value).
- **Committed** = purchase orders sent but not yet received (outstanding value). Real systems count
  it against the budget the moment the PO is sent, so two people can't both spend the last 50,000.
- **Left to spend (open to buy)** = Budget − Spent − Committed.
- **Pace** = share of the budget used vs share of the month gone, and a **month-end forecast** at
  the current rate. Alerts at 80% and 100%.
- **Control at the point of spend**: the purchase order shows the budget effect before it is sent;
  going over either warns or needs a manager approval with a reason (setting per budget line).
- **Plan fast**: copy last month, or suggest from the average of the last 3 months.
- History: past months budget vs actual with variance; every budget change is audited.

## Scope for this build (keep the page simple)

One page `admin/stock-budget.php` (nav: Stock group, label "Budget"):
1. Month picker (‹ Oct 2026 ›). Summary card: Budget · Spent · Committed · Left · pace bar with the
   month-gone marker and a one-line verdict ("On track", "Ahead of pace — at this rate you'll spend
   X by month end", "Over budget by X").
2. One row per budget line (ingredient category, plus an optional "Whole stock" cap): progress bar
   split Spent / Committed, Left, status pill. Click a row → drawer listing the receipts and open POs
   behind the numbers (with links to the PO).
3. "Edit budgets" (manage permission): per line amount OR target % of sales, and control "Warn" or
   "Needs approval". Buttons: Copy last month · Suggest from last 3 months. Save is audited.
4. Past months: small table Budget / Actual / Variance for the last 6 months.

Interactions elsewhere:
- Purchase orders (`admin/purchase-orders.php`): on create/edit/send show "Budget effect" per
  category (left before → left after). Sending a PO that takes a line over budget: Warn lines show a
  confirm; Needs-approval lines are refused unless the user has `stock_budget_manage`, who must give
  a reason (stored on the PO + audit).
- Stock dashboard (`admin/stock-dashboard.php`): one tile "Budget this month" (% used, worst line)
  and a notice at 80% / 100%.
- Buying / reorder suggestions (`admin/stock-reorder.php`): show left-to-spend next to the
  suggested order total.

## Rules

- Amounts are stock cost values as already stored (stock_in_log.cost_total, PO unit_cost), not
  sales prices; VAT handled the same way those tables already hold it — say which in the page help.
- Category = `stock_ingredients.category`. A received item with no budget line counts under
  "Uncategorised"/the whole-stock cap only.
- Spent uses goods-in in the month (stock_in_log.created_at in the hotel timezone). Committed =
  sum over POs in status sent/partial of (ordered_qty − received_qty) × unit_cost, by the ingredient's
  category, regardless of PO date (it's money already promised).
- Target-% mode: budget = target% × F&B sales of the same month so far (reuse the existing sales
  figure used by reports — do not write a new one), and the forecast uses the month's sales pace.
- Money comparisons with BALANCE_TOLERANCE. Prepared statements, CSRF on every POST, escape output.
- Schema additive only (follow `admin/includes/procurement-schema.php` self-healing pattern or a
  numbered migration in `admin/migrations/`): `stock_budgets` (month, category, mode, amount,
  target_pct, control, updated_by/at), `stock_budget_audit`, PO override columns (reason, by, at).
- Permissions (`admin/includes/permissions.php` + page map + nav): `stock_budget` (view; stock_read
  group) and `stock_budget_manage` (set budgets, approve over-budget POs; stock_write group). Default
  roles: managers/admins get both; storekeeper view only.

## Done when

Page works at 1400px and 390px; tests in `scripts/tests/stock_budget.php` (rolled-back) cover
spent/committed/left, pace & forecast, target-% mode, warn vs needs-approval on PO send, copy/suggest,
audit rows; accounting + stock leads reviewed; QA PASS; guides 08-stock-orders + 18-staff-access +
FAQ updated; shipped to both hotels.
