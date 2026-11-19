# Drinks: selling price on import -> menu + projected profit (owner 2026-11-19)

Owner ask: "how would we project profits based on our stock? ... If we import a stock list which has a
selling price ... should that not go into the drinks menu right away with projections too ... food would be
hard but drinks are just drinks."

## Owner decisions (2026-11-19, final)
1. Import LISTS the drinks with a selling price for the manager to TICK FIRST. Nothing is added to the menu without a tick.
2. The selling price INCLUDES VAT. Never add anything on top of it: the menu price = the sheet price exactly.
   VAT is an OPTION on the projection: a switch to show profit with VAT taken out (uses the hotel's VAT
   settings: rh_vat_enabled(), vat_components(), config/database.php ~1134-1180 and ~9534).
3. Category: from the import sheet's category column when it has one, else "Bar"; the manager can change it
   per row or for all rows before ticking. Station = bar.
4. Build at LIWONDE first, test there, then port to Rosalyn's (scripts/port-commit.php).
- ASSUMPTION: drinks only. Food stays as it is (recipes + Food cost %).
- ASSUMPTION: no migration expected (sell price lives in the import job rows; the drink-to-stock link reuses
  the existing recipe tables). If one turns out to be needed: additive only, next number 091, and it needs the
  owner's OK before it runs at Rosalyn's.

## Rails (every brief says these)
ROSALYN'S IS LIVE: no DB tests, scripts or test emails against Rosalyn's DB; Liwonde is the test platform.
Emails only via SMTP settings (none expected here). No cron. No AI spend from shells. Never stage .env or
invoices/*.pdf. Never print secrets. Guides + Assistant sync NOT in this session (log an entry in
.claude/GUIDES_AI_SYNC.md Pending instead).

## Where things are (facts for the briefs, no exploring)
- Import: admin/data-import.php; includes/import/default-aliases.php (line ~116 `sell_price` aliases, line
  ~143 `sell_price` is in the 'derived' = ignored list; ~121 `category`); bar-sheet columns pack_name,
  pack_size, served_by, pack_price, sell_price; review screen includes/import/review.php + review-view.php;
  commit includes/import/commit-stock.php; price sanity check includes/import/price-check.php + case-signals.php;
  job rows in import_jobs; spec .claude/IMPORT_PLAN.md.
- Menu: admin/menu-management.php writes drink_menu AND menu_items in one transaction via
  includes/menu-pos-sync.php (drink_menu INSERT ~line 185). Menu cost warning (S1) already exists.
- Stock links: stock_recipes + stock_recipe_ingredients (admin/stock-recipes.php); drink measures
  rh_drink_measures_list(), RH_DRINK_MEASURE_MAX_ML (config/database.php ~14450-14500), migration 089.
- Stock screens: admin/stock-reports.php, admin/stock-dashboard.php, admin/stock-ingredients.php.
- Realised profit today: reports.php P&L (Gross Profit = Net Revenue - COGS); accounting-dashboard.php Margin.

## Steps (one session; bundled by lead; max 2 leads at once)

### P1 - Import keeps the selling price and offers the drinks for the menu (dept-import-lead, opus, Liwonde)
- Take `sell_price` out of the ignored list; keep it per row with the measure it sells in (served_by:
  bottle / shot / glass / a named drink measure; no measure = per main unit).
- Review screen: new section "Drinks for the menu": one row per drink with a selling price: name, measure,
  price (VAT incl.), category (sheet's or "Bar", editable, plus "set for all"), cost of one measure, profit of
  one measure, a warning when it sells below cost. Each row has a tick, UNTICKED by default. A drink already
  on the menu shows "Update price X -> Y" instead of "Add".
- On import: only ticked rows -> create/update drink_menu + menu_items through includes/menu-pos-sync.php
  (station bar), and link each to its stock item as a one-measure recipe so a sale takes the stock off.
- Permission: data_import AND the menu edit permission; full History/System Logs who/when/old -> new via
  the existing activity hook. Unticked rows change nothing.
- Own test: scripts/tests/import_drink_prices.php (Liwonde only): ignored when unticked; ticked adds menu +
  till item + recipe; price stored exactly as on the sheet (no VAT added); update path old -> new; category
  sheet vs Bar; below-cost warning; permission refused without menu rights.

### P2 - "Projected profit" view on Stock (dept-stock-lead, opus, Liwonde; after P1)
- New view (tab on admin/stock-reports.php): per drink on the menu linked to a stock item:
  on hand (main unit) | measures per unit | selling price | projected sales (on hand x measures x price) |
  stock cost | projected profit | margin %. Totals card on top.
- Switch "Take VAT out" (on when the hotel's VAT is on): projected sales shown net of VAT; nothing changes on
  the menu.
- Flags: "Sells below cost" (red); stock items with no menu price listed as "No selling price yet" (link to
  the menu); drinks sold in more than one measure use each menu price, best shown per line.
- Every column header and card gets a plain helper (what it uses); 10 per page + picker
  (admin-section-pagination.js); filter cards scroll to the rows; phone layout 390 px.
- Own test: scripts/tests/stock_projected_profit.php (Liwonde only): the Jameson example (12 x 750 ml, MK
  45,000, shot 25 ml at MK 3,500 -> sales 1,260,000, cost 540,000, profit 720,000, 57%); VAT switch; no
  price; below cost; money via BALANCE_TOLERANCE rules.

### P3 - SHIPPED 2026-11-20 (Liwonde d10a051) - Projected vs actual (dept-stock-lead + dept-accounting-lead review; after P2, same session if budget allows, else the next)
- For a date range: what the stock used SHOULD have earned (used measures x menu price) vs what the till
  actually took for those drinks. Gap = free pours, spillage or missing money. Read-only report, no writes.
- Own test: scripts/tests/stock_projected_vs_actual.php (Liwonde only).

#### P3 session prompt (written 2026-11-20; P1 + P2 SHIPPED, see TASKS.md)
Paste into a fresh session in Rosalyns-hotel-2026: "Read .claude/plans/drinks-profit-projection.md section P3 and build it."
Facts for the brief (no exploring):
- P1: Liwonde 994f0f8 / Rosalyn's 4ca9397. Drink -> stock link = menu recipe (admin/includes/menu-recipe-lib.php);
  sales deduct only via it (admin/pos.php:418, admin/pos-order-close.php:423). Cost of one measure:
  rh_drink_measure_cost() config/database.php ~14735.
- P2: Liwonde 5033c0f / Rosalyn's b4ca6e1. admin/includes/stock-projected-profit.php rh_spp_load + rh_spp_build
  (per-measure price, measures per unit, totals rule = each item once at lowest menu price, VAT out =
  gross/(1+rate) like the till). Tab 'profit' in admin/stock-reports.php ($tabs line 28).
- Usage today: admin/stock-reports.php usage tab ~line 220: stock_adjustments rows with source_type
  'pos_order'/'room_service' minus 'void_restore', cost_at_time, view-void filter {$vwSa}.
- Counts: admin/stock-count.php (3053 lines, CODE_INDEX + offset reads only).
DESIGN TRAP (decide first, state in the brief): POS deductions are CAUSED by till sales, so "used x menu price"
vs "till takings" only shows price changes, comps and voids, never free pours or spillage. The gap the owner
wants needs the COUNT side: actual used = opening count + received - closing count - recorded wastage, between
two approved counts; expected used = measures sold on the till (+ room service). Report per drink:
actual used, sold, gap in measures, gap value at menu price (VAT switch as P2) and at cost; plus till takings vs
sold x current menu price as a second, smaller line (price drift / comps / discounts). Date range snaps to two
approved counts; if fewer than two counts exist, say so plainly instead of a figure.
Dispatch: dept-stock-lead (opus) builds at Liwonde as a new tab in admin/stock-reports.php + calc in
admin/includes/stock-projected-vs-actual.php (read-only, no writes, no migration); own test
scripts/tests/stock_projected_vs_actual.php (Liwonde guard as in stock_projected_profit.php line 16): two counts
with a known gap (e.g. 2 bottles = 60 shots unaccounted), sold-only = gap 0, received between counts, wastage
recorded = not in the gap, void_restore, one count only = message, VAT switch. One qa-auditor (opus) with the
accounting lead's money check folded in (dept-accounting-lead review = the plan's ask). Browser 1400/768/390.
Commit Liwonde, copy/patch to Rosalyn's (compare base files first), push both, TASKS + GUIDES_AI_SYNC entry.

### Ship gate (each of P1, P2, P3)
php -l; own test + import / stock suites only (no full suite unless a money write path changed); ONE
qa-auditor (opus) per ship with the accounting lead's money check folded in; headless browser check
(php -S + scripts/tests/browser_check.mjs) at 1400 / 768 / 390; commit + push Liwonde; port to Rosalyn's with
scripts/port-commit.php; commit + push Rosalyn's; GUIDES_AI_SYNC Pending entry (guides 08 Stock + import guide,
Manager Guides stock.html, Assistant: projected profit question); stop php -S.

## After this (owner order 2026-11-19)
1. Screen tidy-ups (TASKS item 8): step 9b pop-ups on phones/tablets, step 10 Bookings list on tablets,
   Recipes ingredient row layout, step 16 filter polish - ui-lead at Liwonde, own fresh session.
2. Guides + Assistant + Manager Guides sync - ONLY when the owner prompts, own fresh session.
3. Optional: showcase refresh (artifacts-lead) when the owner asks.
