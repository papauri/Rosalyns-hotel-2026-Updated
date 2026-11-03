# Data Import ("Import") — build plan

Owner ask 2026-11-03 (FEATURE, asked in so many words): an **Import** button where staff upload a
spreadsheet, document or PDF (stock lists, booking lists), see exactly how it will map
onto our tables, review and fix what is unclear, and only then import. It never invents data. It works
without the AI Assistant and gets better when the Assistant is on. Owned only by `dept-import-lead`.

## What "100% accuracy" means here (honest version)
No reader is perfect on messy files. The guarantee is **nothing is imported unless every row is either
(a) read cleanly and checked by fixed rules, or (b) fixed/approved by a person on the review screen.**
Unclear things are asked about, never guessed. Every value on the review screen shows its source
(`sheet!cell`). A value that is not in the source cells cannot reach the database.

## Hard rules (enforced in code, covered by tests)
1. **Stage → review → confirm → commit.** Upload writes only to `import_jobs` / `import_rows`
   (created by `ensureImportSchema()` in `admin/includes/import-schema.php`, same pattern as
   `procurement-schema.php`; nothing added to `config/database.php`). Commit happens only on an
   explicit confirm POST, in one DB transaction, all-or-nothing.
2. **Writes go through the existing paths** for the target (stock: the `stock-ingredients.php`
   add/update/stock_in/adjust logic and `rh_receive_stock_line()`; never a raw `UPDATE` of a quantity).
   Audit trail, batches, weighted-average cost, FIFO stay intact.
3. **Provenance on everything**: each proposed value = value + `sheet!cell` + how it was read
   (`as-written` / `converted-date` / `derived-ignored`). Derived columns (formulas, totals, days to
   expiry, status, "purchase qty") are shown greyed and **never imported**. A formula cell with no
   cached value is "unknown", never 0.
4. **Model output is advice only.** If the Assistant/model proposes a mapping or value, the checker
   re-verifies it against the source cells; anything not found verbatim in the source is dropped.
5. **Permissions** (owner 2026-11-03): new key `data_import` (who may use Import at all) AND the target's own key
   (`stock_management` for stock). Import is hidden when either is missing. **Default grant: `admin` (admin bypasses
   checks as for every key) and `manager` (add `data_import` to the manager list in `admin/includes/permissions.php`
   ~line 36-123, plus a one-time idempotent backfill so EXISTING manager accounts/role rows get it; nobody else gets it
   by default). Shown in Staff Access with a plain helper line; assignable per person like any other key. Kill switch setting
   `data_import_enabled`, **ships OFF**, fail-closed.
6. **Personal data** (bookings): any call to a cloud model goes through the Assistant vault
   (names/phones/emails tokenised out, rehydrated locally). Local parsing never leaves the server.
7. **Re-import safe**: SHA-256 of the file is stored; same file again → "already imported on <date>
   by <user>", import blocked unless the owner-level user ticks "import again".
8. **No hard-coded hotel facts.** Header aliases, unit words, section-row words, the "looks like a
   total" words live in a seeded table/settings (`import_aliases`), editable later; code holds only
   generic rules. Learned corrections (user fixed "Heinken"→"Heineken") are saved as hotel-specific
   aliases and applied next time (the "training").
9. Plain wording: button says **Import**. No sparkle/wand/robot icons, no "smart/AI magic" labels.
   Every column and card has a plain helper line. Existing design tokens only. No MRA claims.
10. The repo is **public**: real stock files (prices, balances) are never committed. Local copies live
    in `scripts/tests/fixtures/import/real/` (gitignored); the committed test fixture is a
    synthetic workbook built by `scripts/tests/fixtures/import/build_fixture.php` with the same quirks.

## Engine (the "swarm" = ordered passes, each can only add warnings/questions, never data)
`includes/import/` (new dir, owned by the import lead):
1. **Reader** — `.xlsx`, `.xlsm`, `.csv/.tsv`, `.docx` (tables), `.pdf` (text layer only), `.xls`
   old binary = "please save as .xlsx". **`ZipArchive` is NOT installed locally** (checked
   2026-11-03) so xlsx/docx use a small pure-PHP zip reader (central directory + `gzinflate`):
   caps on entry count and total unpacked size (zip-bomb guard), `LIBXML_NONET`, no entity loading,
   upload size cap, extension + magic-byte check, file stored outside web root, never executed.
   Output = a grid of cells `{value, raw, is_formula, cached?, style:date?, merged?}` per sheet
   (hidden sheets flagged). Shared strings, inline strings, serial dates (1900 system + 1904 flag),
   merged cells, rich text all handled. **PDF**: text-layer tables only; a scanned/image PDF is
   reported "this PDF has no readable text — upload the Excel/CSV, or type it in"; with the Assistant
   on, a model may *suggest* structure from the extracted text, still re-verified (rule 4).
2. **Sheet triage** — per sheet: import / skip (dashboards, summaries, navigators, "PURCHASE
   SUMMARY"-style) / ask. Skipped sheets are listed with the reason so the user can overrule.
3. **Structure finder** — header row (first row whose cells match known labels), data region, section
   rows (label-only or label+zeros, e.g. `IMPORTED BEERS`, `PROTEIN`) = **category for the rows below,
   not an item**, total/summary rows (`TOTAL …`, `GRAND TOTAL`) = never imported, blank gaps, repeated
   headers, notes rows.
4. **Column mapper** — header text → our field via `import_aliases` (case/space/emoji/punctuation
   insensitive), then value-shape checks (a "price" column must look like money). Low confidence =
   a question, not a guess. Columns that appear in one sheet but not another (e.g. `Units`, `Batch`)
   handled per sheet.
5. **Row validator** — numbers (decimals, thousands separators, `—` and `-` as empty), non-negative
   quantities, dates → ISO, units from a unit column or remark, duplicates.
6. **Matcher** — to existing records: exact → normalised → fuzzy (typo: Heinken/Heineken,
   Windowhoek/Windhoek, Budwiser/Budweiser). **Fuzzy never auto-applies**: shown as "did you mean X?"
   with the real existing name; the user picks (use existing / create new / skip). Same name twice
   with a **Batch** column (`B1`,`B2`) = one ingredient, two batches. Same name twice without a batch
   column = a question.
7. **Cross-checker** — between sheets and between files in the same upload batch: conflicting prices
   or units for the same item (e.g. the same item at two different prices in two sheets) are shown
   side by side and the user must pick; never silently last-wins. Prices far from the item's usual
   size (a case price in a per-bottle column) → "is this per case or per bottle?" question.
8. **Reviewer gate** — computes status per row: `ready` · `needs-answer` · `skipped` · `blocked`.
   **Confirm is disabled while any `needs-answer` or `blocked` row remains** (the user can mark a row
   "skip"). Summary counts and a downloadable "what will be imported" CSV before confirming.
9. **Assistant hook** (built by `dept-ai-lead`, read-only, separate brief): explain a column, suggest a
   mapping, phrase the "please fix cell D12 in the sheet" request. Import works fully without it.

## Targets
| Phase | Target | Notes |
|---|---|---|
| 1 | **Stock list** → ingredients (+ category, unit, par/max, opening batch with qty and unit cost) | the two sample workbooks. Needs `dept-stock-lead` review; prices = money → `dept-accounting-lead` gate |
| 2 | **Bookings list** (messy, unstructured, large) | must call `checkRoomAvailability()` per row and respect joined rooms; conflicts shown, never forced; guest data via vault; `dept-rooms-lead` review |
| 3 | **Suppliers**, menu items/prices (prices = accounting review) | |
| — | **Staff list: NOT built** (owner 2026-11-03: "dont do the staff lists"). Not a target, no alias, no UI option, no mention in guides | |

## Phase 1 — what the two sample workbooks contain (provisional ground truth, 2026-11-03)
Computed by an independent script **before** the parser exists, so the tests are not circular. Counts
are heuristic; the builder must re-verify them by eye on the real files and then freeze them in
`scripts/tests/fixtures/import/expected.json`.

`Rosalyns_Stock_and_Purchasing_Adjustments.xlsx` (2 sheets: 🍺 BAR STOCK A1:E189, 🍳 KITCHEN STOCK):
cols `Item Name | Bar|Kitchen Balance | Stores Balance | Total Balance (formula, no cached value) | Unit Price (MWK)`.

`Rosalyns_Integrated_Tracker_V3-3_102806.xlsx` (7 sheets): 📊 DASHBOARD (skip), 🍺 BAR STOCK
(A..N: Item, **Batch**, Bar, Stores, Total, Expiry (serial date), Days, Status, Action, Comments, Max Level,
Purchase Qty, Unit Price, Total Cost), 🍳 KITCHEN STOCK (col order differs: Stores before Kitchen,
has **Units**), 🧴 CHEMICAL STOCK (single `Balance`, `Remarks` holds the unit), ☕ 77 MUG, STATIONARY
STOCK, 💰 PURCHASE SUMMARY (skip).

| Sheet | Item rows (≈) | Section rows | Notes |
|---|---|---|---|
| Adjustments · BAR | 162 | 20 categories + 4 `TOTAL…` rows | 5 repeated names (batch pairs and true duplicates) |
| Adjustments · KITCHEN | 173 | 8 categories + 3 `TOTAL…` rows | |
| Tracker · BAR | 164 | 20 categories + 1 total | same 5 duplicate names, distinguished by Batch B1/B2 |
| Tracker · KITCHEN | 175 | 8 | has Units |
| Tracker · CHEMICAL | 63 | 0 | unit in Remarks, many blank prices |
| Tracker · 77 MUG | 48 | 5 + total | |
| Tracker · STATIONARY | 29 | 0 | |

Traps the tests must cover (each from a real cell): zero-valued category rows (`IMPORTED BEERS`);
`TOTAL…` rows; formulas without cached values (Total Balance — never read as 0); derived columns ignored;
expiry as Excel serial (46473 → 2027-02-…); two files disagreeing on price (74 items differ across the two files);
per-case-looking prices; typos (one-letter slips in brand names,
Budwiser, `claner`, `Mangína`, `FRUITS & VEGITABLES`); `—` placeholders; emoji sheet names; merged
title cells; same item in two sheets; bar+stores split balances.

### Phase 1 decisions taken (ASSUMPTION — owner can override)
- A1: Bar/Kitchen balance + Stores balance: **check whether the stock schema has locations**
  (builder greps `ensureProcurementSchema` / `ingredients`). If not, import the **total** as the
  opening batch and keep the split in the batch note (`Bar 33 + Stores 80`); no schema change.
- A2 (REVISED 2026-11-03): an earlier owner-approved CLI tool already exists — `admin/includes/stock-sheet-import-lib.php`
  (`rh_ssi_plan()` read+decide, `rh_ssi_apply()` idempotent write, `rh_ssi_report()`), `scripts/import_stock_sheet.php`,
  tests `stock_sheet_import*.php`, staged opening batches (migration 068). The Import commit step **reuses its apply path**:
  create/update ingredients by name, never delete or touch recipes, a **submitted opening count** with staged expiry batches
  that a *different* manager approves (the existing two-person rule) — it does NOT push stock in directly. Import's engine
  (reader/structure/review) replaces the CLI's hard-wired sheet layout; the CLI stays untouched. Read the lib's header + plan
  shape before designing `commit-stock.php`; adapt the engine's reviewed rows into the lib's plan input.
- A3: Existing ingredient matched → **never overwritten silently**: the review shows old vs new for
  each field (unit, par, price); default is "keep existing", user ticks what to change.
- A4: Category = nearest section row above (created if missing, shown as a question when new).
- A5: Unit price in the files is treated as **per stocking unit** only after the user confirms the
  unit; "looks like a case price" rows get the per-case/per-bottle question (pack_name/pack_size
  fields exist on ingredients).

### Phase 1a decisions taken (ASSUMPTION — pure engine, owner can override)
- A6: A formula cell WITH a stored result in a non-derived column (e.g. a balance linked from another sheet) is used and tagged `how=formula-result`; a formula with no stored result is `unknown` (left blank, row warning, never 0). Columns recognised as derived by header (totals, days to expiry, status, action, purchase qty, total cost) are shown greyed and never imported either way.
- A7: A label row with only zeros is a heading when ALL CAPS and the sheet's items are not, or merged, or has a section word. If the sheet's confirmed headings are all capitals, a non-capital zero row is an out-of-stock item (kept, with a warning); a sheet with no headings at all treats it as an item. Anything else is ASKED. (This departs from the brief's literal "label + zeros = section" to avoid dropping real items.)
- A8: A Remarks cell becomes the unit only when it is exactly a unit word from the alias set and the sheet has no Unit column; otherwise it stays a remark.
- A9: A plain number in an Expiry column between 20000 and 80000 is read as an Excel serial date (`converted-date`). Years outside 1990-2100 are rejected as bad values. Text dates: day-first only when unambiguous; both parts <= 12 is ASKED.
- A10: Currency words/symbols are stripped by `import_parse_number` (generic code, not hotel data); Phase 1b may move them to `import_aliases`.
- A11: A unit price of 0 is kept as written with a `zero-price` warning; the commit step should treat it as "no price" (accounting lead to confirm in 1b).
- A12: Blocking questions: mapping unsure, no item column, sheet unknown/named like a summary, duplicate name without distinct batches, conflicts, case-price, ambiguous date, formula-looking name, section-or-item, bad value (bad value = status `blocked`). NON-blocking: "possible typo" suggestions (inside the file or against `known_names`), default "keep as written".
- A13 (REPLACED by A38): Case-price heuristic: a price far above its peers (same category with >= 4 peers, else the sheet; sheet needs >= 5 priced rows). The fixed 5x median / `case_price_ratio` is gone.
- A14: A sheet whose NAME has a skip word (dashboard, summary...) is skipped unless it has item + quantity/price columns, then ASKED. Hidden sheets are skipped (override with `force_sheets`). No recognisable headings: ASKED if >= 8 cells, else skipped.
- A15: Summary lines ("items below...", "re-order items") count as total lines when no price/unit/batch/expiry value sits on the row. Rows without a name but with values are listed as skipped (`orphan`), never imported.
- A16: Word/PDF give a plain "not supported yet" message in Phase 1a; `.xls` asks for a re-save as `.xlsx`.
- Corrections to the provisional ground truth above (re-verified 2026-11-03 from raw cells, counts only): the heuristic item counts included total/summary lines; true item counts are Tracker bar 162, kitchen 172, chemical 61, mug 47, stationary 27; Adjustments bar 162, kitchen 172. In the Tracker only two of the five duplicated names carry B1/B2; the other three have a blank batch and are ASKED. 74 items are priced differently between the two files (not only the two examples).

## Files (new unless noted)
`admin/data-import.php` (page: upload → analysis → review → confirm → result) ·
`admin/api/import-*.php` or POST actions inside the page (builder decides, follow existing pattern) ·
`includes/import/{zip-reader,xlsx-reader,docx-reader,pdf-reader,csv-reader,structure,mapper,matcher,
validator,crosscheck,commit-stock}.php` · `admin/includes/import-schema.php` ·
`admin/includes/permissions.php` (`data_import` key + nav entry; additive) ·
`scripts/tests/import_engine_test.php` (pure, no DB) · `scripts/tests/import_stock_test.php` (DB,
rolls back) · `scripts/tests/fixtures/import/` · `docs/guides/23-import.html` (guides lead) ·
`.gitignore` (real fixtures + root `Rosalyns_*.xlsx`).

## Who must be told when it ships (owner 2026-11-03: "update KBs and any other lead affected")
`guides-kb-lead` (new guide `23-import.html`, Staff Access guide gets the `data_import` permission row, stock guide
`08`/`20` get a pointer, KB search tested for "import excel / upload stock list / bulk add"); `dept-ai-lead` (ship gate
step 6 + the read-only hook; it also owns the Assistant's permission list/evals/guide `22`); `dept-stock-lead`
(reviews stock writes, owns the stock guide wording); `dept-accounting-lead` (price/cost gate); `dept-rooms-lead`
(Phase 2 only); `ui-lead` (review screen); `dept-patch-lead` (security sweep adds the upload surface: file type/size,
zip bomb, XXE, path storage). `dept-backup-lead`: told that `storage/imports/` holds uploaded files (retention:
delete staged files after commit/cancel/7 days; not part of backups). `dept-pwa-lead`: no impact expected (page is
normal admin SPA; confirm it loads inside the SPA container).

## Team & gates
`dept-import-lead` (sonnet) builds and tests. `dept-stock-lead` reviews stock semantics;
`dept-accounting-lead` is the single gate for the price/cost handling (one review, not two);
`ui-lead` gets UI_REQUESTs for the review screen; `dept-ai-lead` adds the read-only Assistant hook
under its own rules (it alone edits `includes/ai/*`); `guides-kb-lead` after ship. Ship gate per
DEPARTMENTS.md; Liwonde is the test hotel for any live-DB check; tests at Rosalyn's only then port.

## Verification (done when all true)
1. Both sample workbooks (real, local) analyse with: every item row accounted for (imported + skipped
   + asked = total), zero section/total rows imported, zero invented values (test asserts every
   proposed value exists in a source cell), all conflicts and typos surfaced as questions.
2. Confirm blocked while questions remain; commit atomic; re-upload of same file blocked.
3. Import with the Assistant OFF gives the same result as ON, minus the explanations.
4. Zip-bomb, oversize, wrong extension, macro (`.xlsm` macros never run), CSV formula injection
   (`=cmd|…` cells shown as plain text, never executed) all handled and tested.
5. Permission matrix: no `data_import` → no button; `data_import` but no `stock_management` → no stock target.

### Phase 1b-backend decisions taken (ASSUMPTION - owner can override)
- A17: Permission backfill is a no-op by design. Role defaults live in code (`getAllRoles()`); `user_permissions` holds only explicit per-key rows, and a missing row falls back to the role default. Adding `data_import` to the manager list therefore reaches every existing manager (even ones with saved lists, which never contained the key). Admin bypasses. No grant rows are written (a test proves a manager with explicit rows has it).
- A18: No `import_rows` table: a row is re-derived from the stored file on every review; answers live in `import_jobs.answers_json` under a stable content key of the question (engine ids are positional and change on re-analysis). Learned corrections are `import_aliases` rows (`kind=name_correction`, hotel-learned flag) saved only after a successful commit and offered next time as a visible pre-filled answer, never silently applied. Alias rows are binary-collated and unique by a hash.
- A19: Status is never taken from the browser: every review/confirm/commit recomputes from the stored file + answers; commit re-reads the file (sha256 checked), re-analyses, re-runs the "nothing invented" invariant, then needs `can_confirm`.
- A20: Commit calls only `rh_ssi_apply()` (never `rh_ssi_plan()`, which holds one hotel's layout, budgets and unit guesses) with a plan built from reviewed rows and `budget_lines = []`. New ingredient: category (section row, else the sheet name without leading symbols), unit (sheet; else a per-sheet "which unit?" question, never a default), cost = price (0 = no price, A11: stock with no cost is left OUT of the opening count and reported), par = max level, reorder = min level or 0, pack only from a per-case answer (apply names it "crate"). Existing ingredient: nothing changes unless a field (category, cost, par, pack, quantity) is ticked; ticked quantity adds a line to the opening count (two-person approval still applies); its unit is never changed. Opening count note keeps the "Stock sheet import" prefix. accounting-lead gate: price/cost handling above.
- A21: Extra review questions the engine cannot know (blocking): no unit for new items (per sheet), same item on two sheets (add / first only / skip, never silently summed), different prices/units across merged batch rows, archived match, unit clash with an existing item, ambiguous existing match. `separate-items` for same-name rows is blocked (stock names must be unique).
- A22: Structural answers re-analyse the file: "import this sheet" (force_sheets), "this column is X" / "this column holds the names" (header confirmed as a per-job alias). A column flagged because its values do not fit, or because two columns compete, cannot be switched on from the screen (fix the sheet) - only ignored.
- A23: Kill switch `data_import_enabled` is read by a direct SELECT (rh_import_enabled, fail-closed); settings `data_import_max_mb` (10) and `data_import_retention_days` (7) live in site_settings (group import), seeded by ensureImportSchema. Re-import of an already committed file is admin-only, checked in store.php. Staged files: storage/imports/<32 hex>.bin, deleted on commit/cancel/failure/expiry by import_purge_staged() (call it from the page load; rate-limited).
- IDEA: free pick of any existing ingredient for a typed name (beyond the engine's suggestion); row-level "update fields" screen could show all matched rows at once.

### Phase 1b-UI decisions taken (ASSUMPTION - owner can override)
- A24: A job can be opened, answered, downloaded or cancelled only by the person who uploaded it, or by an admin (checked in `admin/data-import.php`, not in the backend functions). "My imports" lists the last 30 days (admin sees everyone's). Cancel of someone else's job is admin-only.
- A25: One page, AJAX POST actions to itself (`upload | summary | answer | fields | cancel | confirm | list`, CSRF in the form field; `GET ?action=csv` for the preview CSV). No separate api file, so no new permission-map entries beyond `data-import.php`. All file-derived text is set with `textContent` (never HTML); every answer is followed by a full `summary` fetch (question ids can change after a structural answer).
- A26: The kill switch off, or a missing `stock_management`, uses the standard "no access / switched off" card (`rhDenyAndRedirectHome`); AJAX gets a JSON 403. The nav entry "Import" (Stock group, icon fa-file-import) is hidden by `_navItemVisible()` unless `rh_import_can_use()`. `ensureImportSchema()` runs on every visit of the page (before the switch check) so the OFF switch row exists for the owner to flip.
- A27: `import_job_review_summary` was extended additively (no engine change): per-sheet `columns / categories / non_item_rows / header_row / hidden`, per-item `balance_sources / max_source / min_source / update_key`, per-question `conflict_values` (value + source cell) and `pack_size`. Questions without a row (structural: sheet-unknown, mapping-unsure, no-item-column) show inside their sheet card in step 2; the rest in step 3.
- A28: A question with more than 8 options (the "which unit?" list) is a drop-down. Review table: one table per sheet, 100 items per page, blocked / needs-answer first; filter chips count items (plus rows skipped by an answer), not source rows.
- IDEA: "use this sheet anyway" for a sheet that was skipped without being asked is not offered (the backend only forces a sheet through a `sheet-unknown` question). IDEA: an on/off control for `data_import_enabled` (today the owner changes the site_settings row).

### Fix round 2026-11-04 (stock lead + accounting money gate reviews) - ASSUMPTION, owner can override
- A29 (F1): a per-case pack size is stored WITH the source cell of the row whose per-case answer set it (`pack_source`) and is divided only into a price whose `price_source` is that same cell; any other chosen price ignores the pack (warning on the item says so). Conflict/merge-conflict choices show the converted value ("60,000 per case of 12 = 5,000 each"); option KEYS never change (labels only), the merge-conflict key does carry the label so a changed pack answer re-asks.
- A30 (F2): unit prices are decimal(12,4): `import_money_tolerance()` = 0.00005 (not BALANCE_TOLERANCE, which is for whole-currency totals), zero = exactly 0 (`import_is_zero`), `import_fmt_money` shows up to 4 decimals. accounting-lead gate still the single gate.
- A31 (F3/F4): two typed names resolving to one existing ingredient = blocked question `same-existing-match` (skip all but one); names/categories/units go through `rh_clean_text` and the manual Add limits (name 100, category 50, unit 20): over-long = blocked `value-too-long` question, re-checked at commit (`import_limit_problems`).
- A32 (F5/F6): commit clears the stock dashboard cache after `$pdo->commit()`; one `stock_import_cost` activity-log line per changed existing cost, old -> new (4 decimals), written in the commit transaction.
- A33 (F8): "Data Import: on/off" card in Module Settings > Staff Tools (admin role only, shown and enforced in `admin/api/toggle-module.php` key `data_import` -> site setting `data_import_enabled`, CSRF as every module switch, ships OFF, fail-closed reader unchanged).
- For ui-lead (F9): next to the 'Cost per unit' column/field show the helper text "as written in your file, VAT not added or removed".
- LATER / owner decision (not built): warn when ticking cost on an item with stock on hand (owner decision, accounting L3); race branch "drop-from-count"; pack_name is always 'crate' (apply names it so); mapping a section row to an existing category.

### Price plausibility + storage limit (owner 2026-11-04: "intelligence and common sense, not hard-coded settings") - ASSUMPTION, owner can override
No fixed ratio, no `import_price_ratio` setting, no policy price cap. Code: `includes/import/price-check.php` (pure) + evidence in `import_job_ctx()` (review.php). It only ever ASKS.
- A34: question type `price-check` (blocking until answered; options `file` = the file's price is right / `current` = keep the current price (cost field dropped, ticked cost removed, the quantity still imports at the current cost) / `skip`; a NEW item has `file`/`skip` only). `evidence` array on the question and in the review summary: reason, basis, old, new, factor, own history (last 12 + min/max/n), centre, peer median/n, tolerance (log and factor). Stable key includes the new and old cost: an answer belongs to the exact prices compared, a changed cost or per-case answer asks again. Compared price = the converted per-unit cost (F1/F2 untouched); not judged while the row still has an open question (per-case, price disagreement). "The file's price is right" confirms only: the cost is still changed only when ticked (no hidden writes).
- A35: evidence order: (1) the item's own unit costs (stock_batches.cost_per_unit = one row per delivery, plus costs typed on APPROVED opening counts; counts lines of ordinary counts are NOT used, they only copy the average) with 3+ points: tolerance = 3 x robust spread (1.4826 x MAD of the logs); (2) otherwise 3 x the median size of successive price moves in the same category, then in the whole hotel (needs 5+ moves) - a high-inflation hotel tolerates more by itself; (3) a NEW item: median/spread of in-stock items in the same category (and unit), 5+ peers. Spread under 2% in log terms counts as 2% (rounding noise); identical history (zero spread) falls to step 2. No evidence = no question. These are generic statistics (k = 3, minimum sample sizes), not hotel facts; overridable via ctx `price_check_params` (tests).
- A36: structural reasons (computed, need the current cost; ratio within 5% of the pattern): extra-zero / missing-zero / power-of-ten, pack-size-up / -down (the item's own pack_size first, then pack sizes this hotel uses), thousands-separator (price cell text "2.875" with the stock cost ~1000x higher), swapped-columns (price = quantity on the row, only when the price is already off). These ask even with no history (a x10 slip is asked). Unit kg/g and L/ml mix-ups are explained inside the existing blocking `unit-clash` question (evidence reason `unit-differs`), no second question. A price equal to the quantity with nothing else off asks nothing.
- A37 (storage limit): the ONLY hard stop is physical. `import_cost_limits()` reads NUMERIC_PRECISION/SCALE of stock_ingredients.cost_per_unit and stock_count_lines.cost_per_unit (max 10^(p-s) - 10^-s = 99,999,999.9999 today) and the opening-count line value (stock_count_lines.variance_cost); unreadable columns fall back to the stock-count rule (99,999,999) for the cost and skip the value check. Over it = blocking `cost-too-large` question, only "skip" ("Row N "Item" price X is too large for a stock cost. Fix the cell in your file and upload again, or skip this row."); `import_cost_storage_problems()` is also run by `import_job_revalidate()` at commit. Sum of line values vs stock_counts.surplus_cost (14,2) is not checked per item.
- IDEA: show `evidence` as a small table on the question card; the Assistant import helper can read it from the summary.
- A38 (accounting gate round, 2026-11-04): (F1) a price equal to the current cost (within 0.00005) never asks. (F3) an existing item with a blank/zero cost is judged like a new one (similar items in stock; options file/skip, wording "has no cost in stock yet"). (F2/F6) the price question and the storage check on the COST run only when that cost will really be written: a new item, or an existing item with `cost` ticked and not answered "keep current"; unticked = nothing asked, ticking later makes it appear (recomputed); the opening-count line value is still checked with the cost that will be used (`line_cost`); item field `cost_applied`. (F4) the file-peer "case price?" question uses the same rule as the price check: ask when ln(price / median) > 3 x max(1.4826 x MAD of the peers' logs, 2% floor), upward only, category (>= 4 peers) else sheet; `case_price_ratio` removed, `case_price_min_peers` stays (a sample size); wording and options unchanged. Side effect: a sheet of near-identical prices asks about any price ~6% above the median. (F5) a pack size is named in the reason only for the item's own pack_size (`pack_is_own`); another item's pack count gives "may be a case price or a typing slip".
- LATER (not built): a bulk "apply this answer to all similar questions" shortcut for legitimate bulk price-list updates.
